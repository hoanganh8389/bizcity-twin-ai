<?php
/**
 * Broadcast Dispatcher — cron-driven batch sender.
 *
 * Cron hook: bizcity_cg_broadcast_tick (every minute)
 * Mỗi tick: lấy tối đa batch_size=10 recipients có status='queued',
 * gửi từng cái (ZNS qua eSMS API hoặc Email qua wp_mail()),
 * cập nhật status, sync counters.
 *
 * ZNS: Dùng eSMS API (reuse config từ BizCity_CF7_ZNS_Config nếu available,
 *      fallback về option bizcity_cg_esms_zns_settings).
 * Email: Dùng wp_mail() với phpmailer_init hook để set SMTP credentials từ
 *        account được chọn trong meta_json.email_account_uid.
 *
 * R-CRON-META: note() + note_event() cho mọi kết quả.
 *
 * [2026-06-27 Johnny Chu] PHASE-CG-BROADCAST — new dispatcher.
 *
 * @package BizCity_Channel_Gateway
 * @since   PHASE-CG-BROADCAST (2026-06-27)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Broadcast_Dispatcher' ) ) {
	return;
}

class BizCity_Broadcast_Dispatcher {

	const CRON_HOOK     = 'bizcity_cg_broadcast_tick';
	const CRON_INTERVAL = 'bizcity_every_minute';
	const ESMS_ENDPOINT = 'https://rest.esms.vn/MainService.svc/json/SendZaloMessage_V6/';
	const LOG_CHANNEL   = 'broadcast';

	/**
	 * Register cron hook + schedule event.
	 * Called from bootstrap at file-load time (ngoài mọi hook) — R-PERF.
	 */
	public static function init_cron() {
		// [2026-06-27 Johnny Chu] PHASE-CG-BROADCAST — register cron interval + hook
		add_filter( 'cron_schedules', array( __CLASS__, 'add_cron_interval' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'tick' ) );

		// Schedule nếu chưa có
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), self::CRON_INTERVAL, self::CRON_HOOK );
		}
	}

	/**
	 * Đăng ký interval 'bizcity_every_minute' nếu chưa tồn tại.
	 *
	 * @param  array $schedules
	 * @return array
	 */
	public static function add_cron_interval( array $schedules ) {
		if ( ! isset( $schedules[ self::CRON_INTERVAL ] ) ) {
			$schedules[ self::CRON_INTERVAL ] = array(
				'interval' => 60,
				'display'  => 'Every Minute (BizCity Broadcast)',
			);
		}
		return $schedules;
	}

	/**
	 * Main cron tick — process all active broadcasts.
	 */
	public static function tick() {
		// [2026-06-27 Johnny Chu] PHASE-CG-BROADCAST — cron tick
		// [2026-07-02 Johnny Chu] HOTFIX R-SHOW-TABLES — guard: check table exists before querying.
		// Blog 1488 (and new multisite blogs) may not have the table yet. Without this guard
		// wpdb emits a DB error every cron tick (every minute) → spam in PHP error log.
		// Also add wp_cache layer: cache "no active broadcasts" for 60s to skip DB on quiet sites.
		global $wpdb;

		$table    = $wpdb->prefix . 'bizcity_cg_broadcasts';
		$blog_id  = (int) get_current_blog_id();
		$tbl_ck   = 'bz_tbl_' . $blog_id . '_' . crc32( $table );

		// Dual cache: static (free) + wp_cache (1h) — R-SHOW-TABLES pattern.
		static $s_tbl_checked = array();
		if ( ! isset( $s_tbl_checked[ $table ] ) ) {
			$present = wp_cache_get( $tbl_ck, 'bizcity_tbl' );
			if ( false === $present ) {
				$present = (int) (bool) $wpdb->get_var( $wpdb->prepare(
					'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
					$table
				) );
				wp_cache_set( $tbl_ck, $present, 'bizcity_tbl', HOUR_IN_SECONDS );
			}
			$s_tbl_checked[ $table ] = (bool) $present;
		}

		if ( ! $s_tbl_checked[ $table ] ) {
			// Self-heal: trigger installer then invalidate cache so next tick works.
			if ( class_exists( 'BizCity_Broadcast_Installer' ) ) {
				BizCity_Broadcast_Installer::install();
				wp_cache_delete( $tbl_ck, 'bizcity_tbl' );
				unset( $s_tbl_checked[ $table ] );
			}
			return; // nothing to send yet on a freshly-created table.
		}

		// Cache: skip DB if we already know there are no active broadcasts (60s TTL).
		$active_ck = 'bzcast_has_sending_' . $blog_id;
		$has_sending = wp_cache_get( $active_ck, 'bizcity_broadcast' );
		if ( false === $has_sending ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$has_sending = (int) (bool) $wpdb->get_var( "SELECT 1 FROM `{$table}` WHERE status = 'sending' LIMIT 1" );
			wp_cache_set( $active_ck, $has_sending, 'bizcity_broadcast', 60 ); // 60s TTL
		}
		if ( ! $has_sending ) {
			return;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$actives = $wpdb->get_results(
			"SELECT id, type, meta_json, batch_size FROM `{$table}` WHERE status = 'sending' ORDER BY id ASC",
			ARRAY_A
		);
		if ( empty( $actives ) ) {
			return;
		}

		foreach ( $actives as $bc ) {
			$bc_id      = (int) $bc['id'];
			$type       = (string) $bc['type'];
			$batch_size = max( 1, (int) $bc['batch_size'] );
			$meta       = $bc['meta_json'] ? (array) json_decode( $bc['meta_json'], true ) : array();

			self::process_broadcast( $bc_id, $type, $meta, $batch_size );
		}
	}

	/**
	 * Process one broadcast — send up to $batch_size recipients.
	 *
	 * @param int    $bc_id
	 * @param string $type    'zns' | 'email'
	 * @param array  $meta
	 * @param int    $batch_size
	 */
	private static function process_broadcast( $bc_id, $type, array $meta, $batch_size ) {
		// [2026-06-27 Johnny Chu] PHASE-CG-BROADCAST — process one broadcast batch
		$batch = BizCity_Broadcast_Manager::get_next_batch( $bc_id, $batch_size );

		if ( empty( $batch ) ) {
			// Không còn gì để gửi → mark done
			BizCity_Broadcast_Manager::update( $bc_id, array(
				'status'  => 'done',
				'done_at' => current_time( 'mysql' ),
			) );
			self::file_log( 'broadcast_done', 'INFO', 'Broadcast #' . $bc_id . ' done (no more queued)', array( 'broadcast_id' => $bc_id ) );
			return;
		}

		$sent    = 0;
		$failed  = 0;

		foreach ( $batch as $rcpt ) {
			$rcpt_id = (int) $rcpt['id'];
			$name    = (string) $rcpt['name'];
			$phone   = (string) ( $rcpt['phone'] ?? '' );
			$email   = (string) ( $rcpt['email'] ?? '' );
			$custom  = $rcpt['custom_data'] ? (array) json_decode( $rcpt['custom_data'], true ) : array();
			$custom['name']  = $name;
			$custom['phone'] = $phone;
			$custom['email'] = $email;

			if ( 'zns' === $type ) {
				$result = self::send_zns( $meta, $phone, $custom );
			} elseif ( 'email' === $type ) {
				$result = self::send_email( $meta, $email, $name, $custom );
			} else {
				$result = array( 'success' => false, 'error' => 'unknown_type' );
			}

			if ( ! empty( $result['success'] ) ) {
				BizCity_Broadcast_Manager::mark_recipient( $rcpt_id, true );
				$sent++;
			} else {
				$err = (string) ( $result['error'] ?? 'send_failed' );
				BizCity_Broadcast_Manager::mark_recipient( $rcpt_id, false, $err );
				$failed++;
				self::file_log( 'recipient_failed', 'ERROR', 'Recipient #' . $rcpt_id . ' failed: ' . $err, array(
					'broadcast_id' => $bc_id,
					'recipient_id' => $rcpt_id,
					'type'         => $type,
					'error'        => $err,
				) );
			}
		}

		// Sync counters
		BizCity_Broadcast_Manager::sync_counters( $bc_id );

		self::file_log( 'batch_done', 'INFO', 'Broadcast #' . $bc_id . ' batch: sent=' . $sent . ' failed=' . $failed, array(
			'broadcast_id' => $bc_id,
			'sent'         => $sent,
			'failed'       => $failed,
		) );

		// Check if all done
		$progress = BizCity_Broadcast_Manager::get_progress( $bc_id );
		if ( $progress['queued'] === 0 ) {
			BizCity_Broadcast_Manager::update( $bc_id, array(
				'status'  => 'done',
				'done_at' => current_time( 'mysql' ),
			) );
		}
	}

	// ── ZNS Send ─────────────────────────────────────────────────────────────

	/**
	 * Gửi ZNS qua eSMS API cho 1 recipient.
	 *
	 * @param  array  $meta   Broadcast meta (temp_id, oa_id, temp_vars, sandbox)
	 * @param  string $phone
	 * @param  array  $recipient_data  { name, phone, email, ...custom }
	 * @return array { success: bool, error: string }
	 */
	private static function send_zns( array $meta, $phone, array $recipient_data ) {
		// [2026-06-27 Johnny Chu] PHASE-CG-BROADCAST — send single ZNS via eSMS
		$phone = self::normalize_phone( $phone );
		if ( ! $phone ) {
			return array( 'success' => false, 'error' => 'invalid_phone' );
		}

		$temp_id = (string) ( $meta['temp_id'] ?? '' );
		if ( ! $temp_id ) {
			return array( 'success' => false, 'error' => 'missing_temp_id' );
		}

		// Load eSMS credentials (BizCity_CF7_ZNS_Config nếu available, else direct option)
		if ( class_exists( 'BizCity_CF7_ZNS_Config' ) ) {
			$global = BizCity_CF7_ZNS_Config::get_global_settings();
		} else {
			$global = self::get_esms_settings();
		}

		if ( empty( $global['api_key'] ) || empty( $global['secret_key'] ) ) {
			return array( 'success' => false, 'error' => 'missing_esms_credentials' );
		}

		$oa_id = (string) ( $meta['oa_id'] ?? '' );
		if ( ! $oa_id ) {
			$oa_id = (string) ( $global['oa_id'] ?? '' );
		}
		if ( ! $oa_id ) {
			return array( 'success' => false, 'error' => 'missing_oa_id' );
		}

		// Build TempData từ temp_vars + recipient_data
		$temp_vars = isset( $meta['temp_vars'] ) && is_array( $meta['temp_vars'] ) ? $meta['temp_vars'] : array();
		$temp_data = self::build_zns_temp_data( $temp_vars, $recipient_data );

		$body = array(
			'ApiKey'      => $global['api_key'],
			'SecretKey'   => $global['secret_key'],
			'OAID'        => $oa_id,
			'Phone'       => $phone,
			'TempData'    => (object) $temp_data,
			'TempID'      => $temp_id,
			'SendingMode' => '1',
		);
		if ( ! empty( $meta['sandbox'] ) ) {
			$body['Sandbox'] = '1';
		}

		self::file_log( 'zns_attempt', 'INFO', 'Sending ZNS to ' . self::mask_phone( $phone ), array(
			'temp_id' => $temp_id,
			'phone'   => self::mask_phone( $phone ),
		) );

		$response = wp_remote_post( self::ESMS_ENDPOINT, array(
			'timeout'     => 15,
			'headers'     => array( 'Content-Type' => 'application/json' ),
			'body'        => wp_json_encode( $body ),
			'data_format' => 'body',
		) );

		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'error' => $response->get_error_message() );
		}

		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		$code = (string) ( $json['CodeResult'] ?? '' );

		if ( '100' === $code ) {
			return array( 'success' => true, 'sms_id' => (string) ( $json['SMSID'] ?? '' ) );
		}

		$err_msg = (string) ( $json['ErrorMessage'] ?? ( 'CodeResult=' . $code ) );
		return array( 'success' => false, 'error' => $err_msg );
	}

	// ── Email Send ───────────────────────────────────────────────────────────

	/**
	 * Gửi email qua wp_mail() + phpmailer_init để inject SMTP account.
	 *
	 * @param  array  $meta   { email_subject, email_body, email_account_uid, email_from, email_from_name }
	 * @param  string $to_email
	 * @param  string $to_name
	 * @param  array  $recipient_data
	 * @return array { success: bool, error: string }
	 */
	private static function send_email( array $meta, $to_email, $to_name, array $recipient_data ) {
		// [2026-06-27 Johnny Chu] PHASE-CG-BROADCAST — send email via wp_mail
		if ( ! $to_email || ! is_email( $to_email ) ) {
			return array( 'success' => false, 'error' => 'invalid_email' );
		}

		$subject = (string) ( $meta['email_subject'] ?? '' );
		$body    = (string) ( $meta['email_body'] ?? '' );
		if ( ! $subject || ! $body ) {
			return array( 'success' => false, 'error' => 'missing_email_template' );
		}

		// Replace {name}, {phone}, {email} placeholders
		$subject = self::replace_placeholders( $subject, $recipient_data );
		$body    = self::replace_placeholders( $body, $recipient_data );

		// Load SMTP account nếu có account_uid
		$account_uid = (string) ( $meta['email_account_uid'] ?? '' );
		$smtp_cfg    = $account_uid ? self::load_smtp_account( $account_uid ) : array();

		// Inject phpmailer config nếu có credentials
		$handler = null;
		if ( ! empty( $smtp_cfg['smtp_host'] ) ) {
			$handler = function ( $phpmailer ) use ( $smtp_cfg ) {
				$phpmailer->isSMTP();
				$phpmailer->Host       = $smtp_cfg['smtp_host'];
				$phpmailer->Port       = (int) ( $smtp_cfg['smtp_port'] ?? 587 );
				$phpmailer->SMTPAuth   = ! empty( $smtp_cfg['auth'] );
				$phpmailer->Username   = $smtp_cfg['smtp_user'] ?? '';
				$phpmailer->Password   = $smtp_cfg['smtp_pass'] ?? '';
				$phpmailer->SMTPSecure = $smtp_cfg['security'] ?? 'tls';
				if ( ! empty( $smtp_cfg['from_email'] ) ) {
					$phpmailer->setFrom( $smtp_cfg['from_email'], $smtp_cfg['from_name'] ?? '' );
				}
			};
			add_action( 'phpmailer_init', $handler, 999 );
		}

		$from_name  = (string) ( $meta['email_from_name'] ?? get_bloginfo( 'name' ) );
		$from_email = (string) ( $meta['email_from'] ?? get_option( 'admin_email' ) );
		if ( ! empty( $smtp_cfg['from_email'] ) ) {
			$from_email = $smtp_cfg['from_email'];
			$from_name  = $smtp_cfg['from_name'] ?? $from_name;
		}

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . $from_name . ' <' . $from_email . '>',
		);

		self::file_log( 'email_attempt', 'INFO', 'Sending email to ' . self::mask_email( $to_email ), array(
			'subject' => substr( $subject, 0, 80 ),
			'to'      => self::mask_email( $to_email ),
		) );

		$ok = wp_mail( $to_email, $subject, $body, $headers );

		if ( $handler ) {
			remove_action( 'phpmailer_init', $handler, 999 );
		}

		if ( $ok ) {
			return array( 'success' => true );
		}
		return array( 'success' => false, 'error' => 'wp_mail_failed' );
	}

	// ── Helpers ──────────────────────────────────────────────────────────────

	/**
	 * Build ZNS TempData từ temp_vars config + recipient data.
	 *
	 * temp_vars item: { var_name, source: 'recipient'|'literal', field?, value? }
	 * source='recipient': lấy từ $recipient_data[$field]
	 * source='literal': dùng static value
	 *
	 * @param  array $temp_vars
	 * @param  array $recipient_data
	 * @return array
	 */
	private static function build_zns_temp_data( array $temp_vars, array $recipient_data ) {
		$temp_data = array();
		foreach ( $temp_vars as $tv ) {
			$var_name = (string) ( $tv['var_name'] ?? '' );
			if ( ! $var_name ) {
				continue;
			}
			$source = (string) ( $tv['source'] ?? 'literal' );
			if ( 'recipient' === $source ) {
				$field = (string) ( $tv['field'] ?? '' );
				$temp_data[ $var_name ] = (string) ( $recipient_data[ $field ] ?? '' );
			} else {
				$temp_data[ $var_name ] = (string) ( $tv['value'] ?? '' );
			}
		}
		return $temp_data;
	}

	/**
	 * Replace {placeholder} in text với recipient data.
	 *
	 * @param  string $text
	 * @param  array  $data
	 * @return string
	 */
	private static function replace_placeholders( $text, array $data ) {
		foreach ( $data as $key => $val ) {
			$text = str_replace( '{' . $key . '}', (string) $val, $text );
		}
		return $text;
	}

	/**
	 * Normalize Vietnamese phone number (keep digits + leading 0).
	 *
	 * @param  string $phone
	 * @return string
	 */
	private static function normalize_phone( $phone ) {
		$phone = preg_replace( '/[^0-9+]/', '', (string) $phone );
		$phone = substr( $phone, 0, 15 );
		// Convert +84xxx → 0xxx
		if ( strpos( $phone, '+84' ) === 0 ) {
			$phone = '0' . substr( $phone, 3 );
		}
		return ( strlen( $phone ) >= 9 ) ? $phone : '';
	}

	/**
	 * Get eSMS settings directly from option (fallback when CF7 class not loaded).
	 *
	 * @return array { api_key, secret_key, oa_id }
	 */
	private static function get_esms_settings() {
		$raw = get_option( 'bizcity_cg_esms_zns_settings', array() );
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}
		$secret_enc = (string) ( $raw['secret_key_enc'] ?? '' );
		$secret     = '';
		if ( $secret_enc ) {
			// Simple AES-256-CBC decrypt (same as BizCity_CF7_ZNS_Config)
			if ( function_exists( 'openssl_decrypt' ) && defined( 'AUTH_KEY' ) ) {
				$key = substr( hash( 'sha256', AUTH_KEY ), 0, 32 );
				$iv  = substr( hash( 'sha256', 'bizcity_zns_iv' ), 0, 16 );
				$dec = openssl_decrypt( base64_decode( $secret_enc ), 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
				if ( false !== $dec ) {
					$secret = $dec;
				}
			}
		}
		return array(
			'api_key'    => (string) ( $raw['api_key'] ?? '' ),
			'secret_key' => $secret,
			'oa_id'      => (string) ( $raw['oa_id'] ?? '' ),
		);
	}

	/**
	 * Load SMTP account config by uid from Integration Registry.
	 *
	 * @param  string $uid
	 * @return array
	 */
	private static function load_smtp_account( $uid ) {
		$accounts = get_option( 'bizcity_integration_email_smtp_accounts', array() );
		if ( ! is_array( $accounts ) ) {
			return array();
		}
		foreach ( $accounts as $acc ) {
			if ( isset( $acc['uid'] ) && (string) $acc['uid'] === (string) $uid ) {
				// Decrypt smtp_pass if encrypted
				if ( class_exists( 'BizCity_Email_SMTP_Integration' ) && method_exists( 'BizCity_Email_SMTP_Integration', 'decrypt_field' ) ) {
					$acc['smtp_pass'] = BizCity_Email_SMTP_Integration::decrypt_field( (string) ( $acc['smtp_pass_enc'] ?? '' ) );
				}
				return $acc;
			}
		}
		return array();
	}

	/**
	 * Mask phone for logging (first 3 chars + ***).
	 *
	 * @param  string $phone
	 * @return string
	 */
	private static function mask_phone( $phone ) {
		if ( strlen( $phone ) <= 3 ) {
			return '***';
		}
		return substr( $phone, 0, 3 ) . '***';
	}

	/**
	 * Mask email for logging.
	 *
	 * @param  string $email
	 * @return string
	 */
	private static function mask_email( $email ) {
		$parts = explode( '@', $email );
		if ( count( $parts ) !== 2 ) {
			return '***';
		}
		$local = substr( $parts[0], 0, 3 ) . '***';
		return $local . '@' . $parts[1];
	}

	/**
	 * Write to channel file log (R-CH-FILE-LOG).
	 * Channel: 'broadcast'.
	 *
	 * @param string $event
	 * @param string $level  'INFO' | 'WARN' | 'ERROR'
	 * @param string $msg
	 * @param array  $ctx
	 */
	private static function file_log( $event, $level, $msg, array $ctx = array() ) {
		if ( class_exists( 'BizCity_Channel_File_Logger' ) ) {
			BizCity_Channel_File_Logger::write(
				self::LOG_CHANNEL,
				$level,
				$event,
				$msg,
				$ctx
			);
		} else {
			// Fallback: error_log
			error_log( '[bizcity-broadcast] ' . $event . ': ' . $msg );
		}
	}
}
