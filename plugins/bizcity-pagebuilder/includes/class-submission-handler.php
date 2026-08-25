<?php
/**
 * Contact Form Submission Handler
 *
 * Hooks into Contact Form 7 to capture and store submissions
 * 
 * @package    BizCity_Page_Builder
 * @subpackage Submission_Handler
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BZPB_Submission_Handler {

	/**
	 * Initialize hooks
	 */
	public static function init() {
		// Hook CF7 submission
		add_action( 'wpcf7_before_send_mail', [ __CLASS__, 'capture_cf7_submission' ], 10, 3 );
		
		// Handle native BZPB contact block (if not using CF7)
		add_action( 'wp_ajax_bzpb_submit_contact', [ __CLASS__, 'handle_native_submission' ] );
		add_action( 'wp_ajax_nopriv_bzpb_submit_contact', [ __CLASS__, 'handle_native_submission' ] );
	}

	/**
	 * Send a canonical user-facing submission error.
	 *
	 * @param string $code
	 * @param string $message
	 * @param string $hint
	 * @param string $help_code
	 * @param int    $status
	 */
	private static function send_submission_error( $code, $message, $hint, $help_code, $status = 400 ) {
		// [2026-08-10 Johnny Chu] PHASE-1.24-PAGEBUILDER — standardize native submission AJAX errors.
		$payload = class_exists( 'BizCity_Error_Payload' )
			? BizCity_Error_Payload::make( $code, $message, $hint, $help_code )
			: array(
				'success'    => false,
				'_degraded'  => true,
				'code'       => (string) $code,
				'message'    => (string) $message,
				'hint'       => (string) $hint,
				'help_code'  => (string) $help_code,
			);
		wp_send_json_error( $payload, (int) $status );
	}

	/**
	 * Capture Contact Form 7 submission
	 *
	 * @param WPCF7_ContactForm $contact_form The contact form instance
	 * @param bool             &$abort        Whether to abort email sending
	 * @param WPCF7_Submission $submission    Submission instance
	 */
	public static function capture_cf7_submission( $contact_form, &$abort, $submission ) {
		// [2026-07-02 Johnny Chu] PHASE-PB-LEADFORM R-CH-FILE-LOG — log hook fired immediately
		// (gives evidence even if DB write fails)
		// Log path: uploads/sites/{blog_id}/bizcity-channel-logs/cf7/YYYY-MM-DD.jsonl
		$cf7_form_id    = (int) $contact_form->id();
		$cf7_form_title = (string) $contact_form->title();
		if ( class_exists( 'BizCity_Channel_File_Logger' ) ) {
			BizCity_Channel_File_Logger::write(
				'cf7', BizCity_Channel_File_Logger::LEVEL_INFO,
				'hook_triggered',
				'wpcf7_before_send_mail fired — form_id=' . $cf7_form_id,
				array( 'form_id' => $cf7_form_id, 'form_title' => $cf7_form_title, 'handler' => 'BZPB_Submission_Handler' )
			);
		} else {
			error_log( '[BZPB] capture_cf7_submission hook fired: form_id=' . $cf7_form_id . ' title=' . $cf7_form_title );
		}

		// Get submission data
		$posted_data = $submission->get_posted_data();

		// Extract common fields (try various field name patterns)
		// [2026-07-02 Johnny Chu] PHASE-PB-LEADFORM — added tel-phone (BZPB default) + your-msg
		$name    = self::extract_field( $posted_data, array( 'your-name', 'name', 'full-name', 'fullname' ) );
		$email   = self::extract_field( $posted_data, array( 'your-email', 'email', 'mail' ) );
		$phone   = self::extract_field( $posted_data, array( 'tel-phone', 'your-phone', 'phone', 'tel', 'telephone' ) );
		$subject = self::extract_field( $posted_data, array( 'your-subject', 'subject' ) );
		$message = self::extract_field( $posted_data, array( 'your-message', 'your-msg', 'message', 'content', 'your-content' ) );

		// [2026-07-02 Johnny Chu] PHASE-PB-LEADFORM — backend dedup: reject duplicate
		// submissions within 30 seconds (same form_id + phone/email fingerprint).
		// Guards against double-click or rapid form resubmit on slow connections.
		$_dedup_fp  = substr( md5( (string) $cf7_form_id . '|' . $phone . '|' . $email ), 0, 16 );
		$_dedup_key = 'bzpb_cf7_dd_' . $_dedup_fp;
		if ( get_transient( $_dedup_key ) ) {
			// Duplicate — log and bail (do NOT $abort — CF7 email still goes out).
			if ( class_exists( 'BizCity_Channel_File_Logger' ) ) {
				BizCity_Channel_File_Logger::write( 'cf7', BizCity_Channel_File_Logger::LEVEL_INFO,
					'dedup_skipped', 'Duplicate submission within 30s — skipped',
					array( 'form_id' => $cf7_form_id, 'fp' => $_dedup_fp ) );
			}
			return;
		}
		set_transient( $_dedup_key, 1, 30 );
		unset( $_dedup_fp, $_dedup_key );

		$id = self::save_submission( array(
			'name'       => $name,
			'email'      => $email,
			'phone'      => $phone,
			'subject'    => $subject,
			'message'    => $message,
			'full_data'  => wp_json_encode( $posted_data ),
			'form_id'    => $cf7_form_id,
			'form_title' => $cf7_form_title,
			'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ) : '',
			'ip_address' => self::get_client_ip(),
			'source'     => 'cf7',
		) );

		// [2026-07-02 Johnny Chu] PHASE-PB-LEADFORM R-CH-FILE-LOG — log bzpb_submissions result
		$log_ctx = array(
			'form_id'     => $cf7_form_id,
			'bzpb_sub_id' => (int) $id,
			'has_name'    => ! empty( $name ),
			'has_phone'   => ! empty( $phone ),
			'has_email'   => ! empty( $email ),
			'cg_log_class' => class_exists( 'BizCity_CF7_Submissions_Log' ) ? 'yes' : 'no',
			'crm_sync_class' => class_exists( 'BizCity_CF7_CRM_Sync' ) ? 'yes' : 'no',
		);
		if ( class_exists( 'BizCity_Channel_File_Logger' ) ) {
			BizCity_Channel_File_Logger::write(
				'cf7',
				$id ? BizCity_Channel_File_Logger::LEVEL_INFO : BizCity_Channel_File_Logger::LEVEL_ERROR,
				$id ? 'bzpb_sub_saved' : 'bzpb_sub_failed',
				$id ? 'bzpb_submissions saved id=' . $id : 'FAILED to save to bzpb_submissions',
				$log_ctx
			);
		} else {
			error_log( '[BZPB] capture_cf7_submission result: ' . wp_json_encode( $log_ctx ) );
		}

		// [2026-07-02 Johnny Chu] PHASE-PB-LEADFORM — explicitly forward to CG channel
		// (bizcity_cf7_submissions) so data appears in Twin CRM Submissions panel.
		// Previously this was missing: CF7-backed forms NEVER called sync_to_cg_channel.
		self::sync_to_cg_channel( $name, $email, $phone, $message, $subject, $posted_data, $cf7_form_id, $cf7_form_title );
	}

	/**
	 * Handle native BZPB contact form submission
	 */
	public static function handle_native_submission() {
		check_ajax_referer( 'bzpb-contact-submit', 'nonce' );

		// [2026-06-27 Johnny Chu] PHASE-PB-LEADFORM — accept flexible field names
		// (CF7-style: your-name, your-email, tel-phone, your-msg, etc.)
		$post_data = array_map( 'wp_unslash', $_POST );

		$name    = self::extract_field( $post_data, [ 'name', 'your-name', 'full-name', 'fullname', 'ho-ten', 'hoten' ] );
		$email   = self::extract_field( $post_data, [ 'email', 'your-email', 'mail', 'e-mail' ] );
		$phone   = self::extract_field( $post_data, [ 'phone', 'your-phone', 'tel', 'tel-phone', 'telephone', 'dien-thoai' ] );
		$subject = self::extract_field( $post_data, [ 'subject', 'your-subject', 'tieu-de' ] );
		$message = self::extract_field( $post_data, [ 'message', 'your-message', 'your-msg', 'msg', 'content', 'your-content', 'nhu-cau', 'noi-dung' ] );

		$name    = sanitize_text_field( $name );
		$email   = sanitize_email( $email );
		$phone   = sanitize_text_field( $phone );
		$subject = sanitize_text_field( $subject );
		$message = sanitize_textarea_field( $message );

		// Last-resort fallback for name: pick first unknown POST field with a value
		if ( empty( $name ) ) {
			$known_keys = [ 'name', 'your-name', 'email', 'your-email', 'phone', 'tel', 'tel-phone',
				'subject', 'message', 'your-msg', 'msg', 'action', 'nonce' ];
			foreach ( $post_data as $k => $v ) {
				if ( ! in_array( $k, $known_keys, true ) && is_string( $v ) && trim( $v ) !== '' ) {
					$name = sanitize_text_field( $v );
					break;
				}
			}
		}

		// Basic validation
		if ( empty( $name ) || empty( $email ) || empty( $message ) ) {
			self::send_submission_error(
				'invalid_param',
				'Form liên hệ còn thiếu thông tin bắt buộc.',
				'Điền họ tên, email và nội dung rồi thử lại.',
				'pagebuilder_submission_required'
			);
		}

		if ( ! is_email( $email ) ) {
			self::send_submission_error(
				'invalid_param',
				'Email liên hệ không hợp lệ.',
				'Kiểm tra lại địa chỉ email rồi thử lại.',
				'pagebuilder_submission_email'
			);
		}

		// Save submission
		$id = self::save_submission( [
			'name'       => $name,
			'email'      => $email,
			'phone'      => $phone,
			'subject'    => $subject,
			'message'    => $message,
			'full_data'  => wp_json_encode( $_POST ),
			'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ) : '',
			'ip_address' => self::get_client_ip(),
			'source'     => 'bzpb',
		] );

		if ( $id ) {
			// [2026-06-27 Johnny Chu] PHASE-PB-LEADFORM — sync native submission into
			// CF7 channel path (Submissions Log + CRM) so it appears in bizcity-twin-crm
			// and the Channel Gateway Submissions panel — same as a real CF7 submission.
			self::sync_to_cg_channel( $name, $email, $phone, $message, $subject, $post_data );

			// Send notification email to admin (optional)
			$admin_email = get_option( 'admin_email' );
			$site_name   = get_bloginfo( 'name' );
			
			wp_mail(
				$admin_email,
				sprintf( '[%s] Liên hệ mới từ %s', $site_name, $name ),
				sprintf(
					"Tên: %s\nEmail: %s\nĐiện thoại: %s\nChủ đề: %s\n\nNội dung:\n%s\n\n---\nIP: %s",
					$name, $email, $phone, $subject, $message, self::get_client_ip()
				),
				[ "Reply-To: {$name} <{$email}>" ]
			);

			wp_send_json_success( [ 'message' => 'Cảm ơn bạn đã liên hệ! Chúng tôi sẽ phản hồi sớm nhất.' ] );
		} else {
			self::send_submission_error(
				'db_error',
				'Không thể lưu form liên hệ lúc này.',
				'Thử lại sau ít phút hoặc liên hệ quản trị viên.',
				'pagebuilder_submission_save'
			);
		}
	}

	/**
	 * Mirror a BZPB submission into the CF7 Channel Gateway path.
	 *
	 * Writes to bizcity_cf7_submissions (Submissions panel) and upserts the
	 * contact into bizcity_crm_contacts (Twin CRM) if both classes are loaded.
	 *
	 * [2026-06-27 Johnny Chu] PHASE-PB-LEADFORM — unify native lead-form → CG channel path
	 * [2026-07-02 Johnny Chu] PHASE-PB-LEADFORM — accept real CF7 form_id/title from capture_cf7_submission
	 *
	 * @param string $name
	 * @param string $email     Already sanitised.
	 * @param string $phone     Already sanitised.
	 * @param string $message
	 * @param string $subject
	 * @param array  $raw_post  Original $_POST / get_posted_data() array.
	 * @param int    $form_id   Real CF7 form ID, or 0 for native BZPB forms.
	 * @param string $form_title
	 */
	private static function sync_to_cg_channel( $name, $email, $phone, $message, $subject, array $raw_post, $form_id = 0, $form_title = '' ) {
		// [2026-07-02 Johnny Chu] PHASE-PB-LEADFORM — default title by source
		if ( ! $form_title ) {
			$form_title = $form_id ? ( 'CF7 Form #' . $form_id ) : 'BZPB Lead Form';
		}

		// [2026-07-02 Johnny Chu] PHASE-PB-LEADFORM R-CH-FILE-LOG — log sync entry
		if ( class_exists( 'BizCity_Channel_File_Logger' ) ) {
			BizCity_Channel_File_Logger::write(
				'cf7', BizCity_Channel_File_Logger::LEVEL_INFO,
				'sync_to_cg_start',
				'sync_to_cg_channel: form_id=' . $form_id . ' title=' . $form_title,
				array(
					'form_id'        => $form_id,
					'form_title'     => $form_title,
					'has_email'      => ! empty( $email ),
					'has_phone'      => ! empty( $phone ),
					'cg_log_class'   => class_exists( 'BizCity_CF7_Submissions_Log' ) ? 'yes' : 'no',
					'crm_sync_class' => class_exists( 'BizCity_CF7_CRM_Sync' ) ? 'yes' : 'no',
				)
			);
		} else {
			error_log( '[BZPB] sync_to_cg_channel: form_id=' . $form_id
				. ' BizCity_CF7_Submissions_Log=' . ( class_exists( 'BizCity_CF7_Submissions_Log' ) ? 'yes' : 'no' )
				. ' BizCity_CF7_CRM_Sync=' . ( class_exists( 'BizCity_CF7_CRM_Sync' ) ? 'yes' : 'no' ) );
		}

		$mapped = array(
			'name'    => $name,
			'email'   => $email,
			'phone'   => $phone,
			'message' => $message,
			'subject' => $subject,
		);

		// [2026-07-02 Johnny Chu] PHASE-0.46 M1 — capture UTM/referrer/device source meta
		$source_meta = array(
			'form_id'    => $form_id,
			'form_title' => $form_title,
		);
		if ( class_exists( 'BizCity_Lead_Source_Tracker' ) ) {
			$source_meta = BizCity_Lead_Source_Tracker::capture_from_request( $source_meta );
		}

		$source_url = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 ) : '';
		$ip_address = self::get_client_ip();

		// ── 1. Write to CF7 Submissions Log ──────────────────────────────
		// [2026-07-02 Johnny Chu] PHASE-PB-LEADFORM — force-load CF7 CG classes
		// if not yet available (happens when CF7 submits to page URL instead of
		// /wp-json/ and bizcity_admin_ctx was false). Also self-heal the table.
		if ( ! class_exists( 'BizCity_CF7_Submissions_Log' ) && defined( 'BIZCITY_TWIN_AI_DIR' ) ) {
			$_bzpb_cf7_dir = BIZCITY_TWIN_AI_DIR . 'core/channel-gateway/includes/cf7/';
			if ( is_dir( $_bzpb_cf7_dir ) ) {
				require_once $_bzpb_cf7_dir . 'class-cf7-installer.php';
				require_once $_bzpb_cf7_dir . 'class-cf7-submissions-log.php';
				require_once $_bzpb_cf7_dir . 'class-cf7-crm-sync.php';
			}
			unset( $_bzpb_cf7_dir );
		}
		// Self-heal: bypass maybe_install() option cache — check actual table existence.
		// [2026-07-02 Johnny Chu] PHASE-PB-LEADFORM — maybe_install() returns early if
		// option 'bizcity_cf7_channel_db_version' is already set (even if dbDelta failed
		// silently via BizCity_WPDB_Router on a previous request). Use table_exists()
		// which queries information_schema directly (R-SHOW-TABLES compliant) to decide.
		if ( class_exists( 'BizCity_CF7_Installer' ) && ! BizCity_CF7_Installer::table_exists() ) {
			BizCity_CF7_Installer::install();
		}
		$sub_id = 0;
		// [2026-07-02 Johnny Chu] PHASE-PB-LEADFORM — skip inserting into bizcity_cf7_submissions
		// from BZPB side when BizCity_CF7_Channel_Listener is active: it fires on wpcf7_mail_sent
		// and inserts the same row → duplicate entries in CRM Submissions list.
		// BZPB only inserts if the CG listener is NOT present (e.g. channel-gateway not loaded).
		$_cg_listener_active = class_exists( 'BizCity_CF7_Channel_Listener' )
			&& has_filter( 'wpcf7_mail_sent' );
		if ( class_exists( 'BizCity_CF7_Submissions_Log' ) && ! $_cg_listener_active ) {
			$sub_id = BizCity_CF7_Submissions_Log::insert( array(
				'form_id'      => $form_id,
				'form_title'   => $form_title,
				'raw_data'     => $raw_post,
				'mapped_data'  => $mapped,
				'email'        => $email ?: null,
				'phone'        => $phone ?: null,
				'source_url'   => $source_url,
				'user_agent'   => $user_agent,
				'ip_address'   => $ip_address,
				// [2026-07-02 Johnny Chu] PHASE-0.46 M1 — pass source_meta for channel classification
				'source_meta'  => $source_meta,
			) );
			// [2026-07-02 Johnny Chu] PHASE-PB-LEADFORM — log CG insert result
			$log_cg = array( 'sub_id' => (int) $sub_id, 'form_id' => $form_id );
			if ( class_exists( 'BizCity_Channel_File_Logger' ) ) {
				BizCity_Channel_File_Logger::write( 'cf7',
					$sub_id ? BizCity_Channel_File_Logger::LEVEL_INFO : BizCity_Channel_File_Logger::LEVEL_ERROR,
					$sub_id ? 'cg_sub_saved' : 'cg_sub_failed',
					$sub_id ? 'bizcity_cf7_submissions saved id=' . $sub_id : 'BizCity_CF7_Submissions_Log::insert returned 0',
					$log_cg
				);
			} else {
				error_log( '[BZPB] CG insert result: ' . wp_json_encode( $log_cg ) );
			}
		} elseif ( $_cg_listener_active ) {
			// [2026-07-02 Johnny Chu] PHASE-PB-LEADFORM — CG listener is active;
			// bizcity_cf7_submissions insert deferred to BizCity_CF7_Channel_Listener::on_submit
			// (fires on wpcf7_mail_sent). BZPB skips to avoid duplicate rows.
			if ( class_exists( 'BizCity_Channel_File_Logger' ) ) {
				BizCity_Channel_File_Logger::write( 'cf7', BizCity_Channel_File_Logger::LEVEL_INFO,
					'cg_sub_deferred', 'BizCity_CF7_Channel_Listener active — insert deferred to wpcf7_mail_sent',
					array( 'form_id' => $form_id ) );
			}
		} else {
			// [2026-07-02 Johnny Chu] PHASE-PB-LEADFORM — class not loaded: dump loaded files hint
			$hint = array(
				'bizcity_twin_ai_loaded' => defined( 'BIZCITY_TWIN_AI_VERSION' ),
				'channel_gw_bootstrap'   => class_exists( 'BizCity_CG_Debug_Logger' ),
				'cf7_listener'           => class_exists( 'BizCity_CF7_Channel_Listener' ),
				'request_uri'            => substr( $_SERVER['REQUEST_URI'] ?? '', 0, 120 ),
			);
			if ( class_exists( 'BizCity_Channel_File_Logger' ) ) {
				BizCity_Channel_File_Logger::write( 'cf7', BizCity_Channel_File_Logger::LEVEL_ERROR,
					'cg_class_missing', 'BizCity_CF7_Submissions_Log not loaded — channel-gateway may not have bootstrapped', $hint );
			} else {
				error_log( '[BZPB] BizCity_CF7_Submissions_Log missing. hint=' . wp_json_encode( $hint ) );
			}
		}

		// ── 2. Upsert CRM Contact ─────────────────────────────────────────
		$crm_result = array( 'action' => 'skipped', 'contact_id' => 0, 'error' => null );
		if ( ( $email || $phone ) && class_exists( 'BizCity_CF7_CRM_Sync' ) && BizCity_CF7_CRM_Sync::is_available() ) {
			$crm_result = BizCity_CF7_CRM_Sync::upsert(
				$email,
				$phone,
				$mapped,
				array(
					'form_id'    => $form_id,
					'form_title' => $form_title,
					'sub_id'     => $sub_id,
					'auto_tag'   => array(),
					'owner_id'   => 0,
				)
			);
		}

		// ── 3. Update submission row with CRM result ──────────────────────
		if ( $sub_id && class_exists( 'BizCity_CF7_Submissions_Log' ) ) {
			BizCity_CF7_Submissions_Log::update_crm_result( $sub_id, $crm_result );
		}

		// ── 4. Listener Bus trace ─────────────────────────────────────────
		if ( class_exists( 'BizCity_Listener_Bus' ) ) {
			BizCity_Listener_Bus::emit( array(
				'kind'       => 'system',
				'platform'   => 'CF7',
				'account_id' => 'bzpb_lead_form',
				'user_id'    => '',
				'chat_id'    => 'bzpb_lead_0',
				'event_type' => 'cf7_submit',
				'direction'  => 'inbound',
				'message'    => $form_title,
				'meta'       => array(
					'form_id'    => $form_id,
					'form_title' => $form_title,
					'has_email'  => ! empty( $email ),
					'has_phone'  => ! empty( $phone ),
					'crm_action' => $crm_result['action'],
					'contact_id' => (int) $crm_result['contact_id'],
					'sub_id'     => $sub_id,
					'source'     => 'bzpb_native',
				),
			) );
		}
	}

	/**
	 * Save submission to database
	 *
	 * @param array $data Submission data
	 * @return int|false Submission ID or false on failure
	 */
	public static function save_submission( $data ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bzpb_submissions';

		$defaults = [
			'name'       => '',
			'email'      => '',
			'phone'      => '',
			'subject'    => '',
			'message'    => '',
			'full_data'  => '{}',
			'form_id'    => 0,
			'form_title' => '',
			'user_agent' => '',
			'ip_address' => '',
			'source'     => 'unknown',
			'status'     => 'unread',
		];

		$data = wp_parse_args( $data, $defaults );

		$result = $wpdb->insert(
			$table,
			[
				'name'        => $data['name'],
				'email'       => $data['email'],
				'phone'       => $data['phone'],
				'subject'     => $data['subject'],
				'message'     => $data['message'],
				'full_data'   => $data['full_data'],
				'form_id'     => $data['form_id'],
				'form_title'  => $data['form_title'],
				'user_agent'  => $data['user_agent'],
				'ip_address'  => $data['ip_address'],
				'source'      => $data['source'],
				'status'      => $data['status'],
				'submitted_at' => current_time( 'mysql' ),
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);

		return $result ? $wpdb->insert_id : false;
	}

	/**
	 * Extract field from posted data by trying multiple field names
	 *
	 * @param array $data       Posted data
	 * @param array $field_names Possible field names to try
	 * @return string
	 */
	private static function extract_field( $data, $field_names ) {
		foreach ( $field_names as $name ) {
			if ( isset( $data[ $name ] ) && ! empty( $data[ $name ] ) ) {
				return is_array( $data[ $name ] ) ? implode( ', ', $data[ $name ] ) : $data[ $name ];
			}
		}
		return '';
	}

	/**
	 * Get client IP address
	 *
	 * @return string
	 */
	private static function get_client_ip() {
		$ip_keys = [ 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR' ];
		foreach ( $ip_keys as $key ) {
			if ( isset( $_SERVER[ $key ] ) && ! empty( $_SERVER[ $key ] ) ) {
				$ips = explode( ',', sanitize_text_field( $_SERVER[ $key ] ) );
				return trim( $ips[0] );
			}
		}
		return 'unknown';
	}

	/**
	 * Mark submission as read
	 *
	 * @param int $id Submission ID
	 * @return bool
	 */
	public static function mark_as_read( $id ) {
		global $wpdb;
		return (bool) $wpdb->update(
			$wpdb->prefix . 'bzpb_submissions',
			[ 'status' => 'read', 'read_at' => current_time( 'mysql' ) ],
			[ 'id' => $id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);
	}

	/**
	 * Delete submission
	 *
	 * @param int $id Submission ID
	 * @return bool
	 */
	public static function delete_submission( $id ) {
		global $wpdb;
		return (bool) $wpdb->delete(
			$wpdb->prefix . 'bzpb_submissions',
			[ 'id' => $id ],
			[ '%d' ]
		);
	}

	/**
	 * Get submission by ID
	 *
	 * @param int $id Submission ID
	 * @return object|null
	 */
	public static function get_submission( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}bzpb_submissions WHERE id = %d",
			$id
		) );
	}

	/**
	 * Get submissions with filters
	 *
	 * @param array $args Query arguments
	 * @return array
	 */
	public static function get_submissions( $args = [] ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bzpb_submissions';

		$defaults = [
			'status'   => '',
			'search'   => '',
			'orderby'  => 'submitted_at',
			'order'    => 'DESC',
			'limit'    => 20,
			'offset'   => 0,
		];

		$args = wp_parse_args( $args, $defaults );

		$where = [ '1=1' ];
		
		if ( ! empty( $args['status'] ) ) {
			$where[] = $wpdb->prepare( 'status = %s', $args['status'] );
		}

		if ( ! empty( $args['search'] ) ) {
			$search = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[] = $wpdb->prepare(
				'(name LIKE %s OR email LIKE %s OR message LIKE %s)',
				$search, $search, $search
			);
		}

		$where_clause = implode( ' AND ', $where );
		$orderby = sanitize_sql_orderby( $args['orderby'] . ' ' . $args['order'] );

		$query = "SELECT * FROM {$table} WHERE {$where_clause} ORDER BY {$orderby} LIMIT {$args['limit']} OFFSET {$args['offset']}";
		
		return $wpdb->get_results( $query );
	}

	/**
	 * Get submissions count
	 *
	 * @param array $args Query arguments
	 * @return int
	 */
	public static function get_count( $args = [] ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bzpb_submissions';

		$where = [ '1=1' ];
		
		if ( ! empty( $args['status'] ) ) {
			$where[] = $wpdb->prepare( 'status = %s', $args['status'] );
		}

		if ( ! empty( $args['search'] ) ) {
			$search = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[] = $wpdb->prepare(
				'(name LIKE %s OR email LIKE %s OR message LIKE %s)',
				$search, $search, $search
			);
		}

		$where_clause = implode( ' AND ', $where );

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where_clause}" );
	}
}
