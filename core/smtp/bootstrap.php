<?php
/**
 * BizCity SMTP Module — wp_mail() bridge over Gmail / generic SMTP
 *
 * Default-on infrastructure module shipped with bizcity-twin-ai.
 * Replaces the legacy mu-plugin `wp-content/mu-plugins/bizcity-smtp-gmail.php`
 * (which can be removed after this module is loaded — see § Migration).
 *
 * ── Configuration precedence (highest → lowest) ─────────────────────
 *   1. PHP constants in `wp-config.php` (BIZCITY_SMTP_*)
 *   2. WP option `bizcity_smtp_settings` (admin-editable, future Channel UI)
 *   3. (none — module no-ops, default `wp_mail()` continues unchanged)
 *
 * ── Required keys ───────────────────────────────────────────────────
 *   host, port, user, pass, from, from_name, secure (tls|ssl|''), auth (1|0)
 *
 * ── Override vs fallback semantics (2026-05-12 update) ─────────────
 * Mu-plugin `bizcity-smtp-gmail.php` (nếu còn) vẫn load TRƯỚC plugin
 * thường — nó register `phpmailer_init` ở priority mặc định (10).
 * Module này register cùng hook ở priority **999** → CHẠY SAU và GHI ĐÈ
 * toàn bộ field trên `$phpmailer` (Host/Port/User/Pass/From/…).
 *
 *   - Nếu `resolve_config()` trả config hợp lệ → core/smtp **override**
 *     mu-plugin (admin có thể đổi credential ngay từ UI, không phải
 *     động vào file mu-plugin).
 *   - Nếu `resolve_config()` trả `null` (chưa cấu hình ở admin / wp-config) →
 *     module **không bind** → mu-plugin tiếp tục hoạt động như cũ
 *     (fallback an toàn — không mất khả năng gửi mail).
 *
 * Hằng `BIZCITY_SMTP_LOADED` được define ở đầu file để các module khác
 * (admin-menu, diagnostics) biết core/smtp đã được nạp; nó KHÔNG còn
 * dùng làm guard early-return nữa.
 *
 * ── Future Channel Settings tie-in (M-CRM.M12 / Channel Settings) ──
 * Admin UI in CRM SPA will write to option `bizcity_smtp_settings`.
 * The module then auto-applies on the next page load — no plugin
 * deactivation/reactivation needed.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\SMTP
 * @since      1.3.8 (2026-05-12)
 */

defined( 'ABSPATH' ) || exit;

// Signal: core/smtp module has been loaded. Other modules (admin-menu
// diagnostics, channel-gateway) check `defined('BIZCITY_SMTP_LOADED')`
// to know the bridge is active. Defined unconditionally so the signal
// reflects "module loaded", not "bind succeeded" — bind only runs if
// `resolve_config()` finds a usable config (else mu-plugin fallback).
if ( ! defined( 'BIZCITY_SMTP_LOADED' ) ) {
	define( 'BIZCITY_SMTP_LOADED', true );
}

if ( ! class_exists( 'BizCity_SMTP' ) ) {

	/**
	 * Thin SMTP bridge — option-driven with constant override fallback.
	 */
	final class BizCity_SMTP {

		const OPTION_KEY = 'bizcity_smtp_settings';

		/**
		 * Resolve final config (constants > option > none).
		 *
		 * @return array{host:string,port:int,user:string,pass:string,from:string,from_name:string,secure:string,auth:bool}|null
		 */
		public static function resolve_config(): ?array {
			$opt = get_option( self::OPTION_KEY, array() );
			$opt = is_array( $opt ) ? $opt : array();

			$cfg = array(
				'host'      => defined( 'BIZCITY_SMTP_HOST' )      ? (string) BIZCITY_SMTP_HOST      : (string) ( $opt['host']      ?? '' ),
				'port'      => defined( 'BIZCITY_SMTP_PORT' )      ? (int)    BIZCITY_SMTP_PORT      : (int)    ( $opt['port']      ?? 587 ),
				'user'      => defined( 'BIZCITY_SMTP_USER' )      ? (string) BIZCITY_SMTP_USER      : (string) ( $opt['user']      ?? '' ),
				'pass'      => defined( 'BIZCITY_SMTP_PASS' )      ? (string) BIZCITY_SMTP_PASS      : (string) ( $opt['pass']      ?? '' ),
				'from'      => defined( 'BIZCITY_SMTP_FROM' )      ? (string) BIZCITY_SMTP_FROM      : (string) ( $opt['from']      ?? '' ),
				'from_name' => defined( 'BIZCITY_SMTP_FROM_NAME' ) ? (string) BIZCITY_SMTP_FROM_NAME : (string) ( $opt['from_name'] ?? get_bloginfo( 'name' ) ),
				'secure'    => defined( 'BIZCITY_SMTP_SECURE' )    ? (string) BIZCITY_SMTP_SECURE    : (string) ( $opt['secure']    ?? 'tls' ),
				'auth'      => defined( 'BIZCITY_SMTP_AUTH' )      ? (bool)   BIZCITY_SMTP_AUTH      : (bool)   ( $opt['auth']      ?? true ),
			);

			// Filter for runtime overrides (e.g. per-tenant in multisite).
			$cfg = apply_filters( 'bizcity_smtp_config', $cfg );

			// Sanity: must have host + user + pass + from to be useful.
			if ( $cfg['host'] === '' || $cfg['user'] === '' || $cfg['pass'] === '' || $cfg['from'] === '' ) {
				return null;
			}

			// Skip placeholder credentials (legacy mu-plugin bail check).
			if ( $cfg['user'] === 'your-email@gmail.com' ) {
				return null;
			}

			return $cfg;
		}

		public static function bind(): void {
			// [2026-07-17 Johnny Chu] HOTFIX — always register centralized
			// wp_mail_failed diagnostics, even when SMTP config is incomplete.
			self::bind_failure_logger();

			$cfg = self::resolve_config();
			if ( $cfg === null ) {
				return;
			}

			add_action( 'phpmailer_init', static function ( $phpmailer ) use ( $cfg ) {
				// [2026-07-17 Johnny Chu] HOTFIX — enforce SMTP identity at the
				// last hook priority to avoid provider DATA-stage reject.
				$phpmailer->isSMTP();
				$phpmailer->Host       = $cfg['host'];
				$phpmailer->Port       = $cfg['port'];
				$phpmailer->SMTPSecure = $cfg['secure'];
				$phpmailer->SMTPAuth   = $cfg['auth'];
				$phpmailer->Username   = $cfg['user'];
				$phpmailer->Password   = $cfg['pass'];
				$phpmailer->From       = $cfg['from'];
				$phpmailer->FromName   = $cfg['from_name'];
				$phpmailer->Sender     = $cfg['from'];
				if ( method_exists( $phpmailer, 'setFrom' ) ) {
					$phpmailer->setFrom( $cfg['from'], $cfg['from_name'], false );
				}
			}, 999 );

			add_filter( 'wp_mail_from',      static function () use ( $cfg ) { return $cfg['from']; }, 999 );
			add_filter( 'wp_mail_from_name', static function () use ( $cfg ) { return $cfg['from_name']; }, 999 );
		}

		/**
		 * Register global wp_mail_failed logger.
		 */
		private static function bind_failure_logger(): void {
			add_action( 'wp_mail_failed', array( __CLASS__, 'on_wp_mail_failed' ), 1, 1 );
		}

		/**
		 * Emit detailed diagnostics for wp_mail_failed.
		 *
		 * @param mixed $error WP_Error from wp_mail_failed hook.
		 */
		public static function on_wp_mail_failed( $error ): void {
			if ( ! ( $error instanceof WP_Error ) ) {
				return;
			}

			$codes        = $error->get_error_codes();
			$primary_code = ! empty( $codes ) ? (string) $codes[0] : 'wp_mail_failed';
			$primary_msg  = (string) $error->get_error_message( $primary_code );
			if ( $primary_msg === '' ) {
				$primary_msg = (string) $error->get_error_message();
			}

			$data = $error->get_error_data( $primary_code );
			if ( ! is_array( $data ) ) {
				$data = array();
			}

			$provider_code = isset( $data['phpmailer_exception_code'] ) ? (int) $data['phpmailer_exception_code'] : 0;
			$provider_err  = self::extract_provider_error_message( $primary_msg, $data );
			$to_domains    = self::extract_recipient_domains( $data['to'] ?? array() );
			$subject       = isset( $data['subject'] ) ? sanitize_text_field( (string) $data['subject'] ) : '';
			// [2026-07-17 Johnny Chu] HOTFIX — capture effective mail headers
			// so DATA-stage SMTP rejects can be traced to malformed From/Reply-To.
			$header_from   = self::extract_mail_header_value( $data['headers'] ?? array(), 'from' );
			$header_sender = self::extract_mail_header_value( $data['headers'] ?? array(), 'sender' );
			$header_reply  = self::extract_mail_header_value( $data['headers'] ?? array(), 'reply-to' );

			$cfg       = self::resolve_config();
			$smtp_host = is_array( $cfg ) ? (string) ( $cfg['host'] ?? '' ) : '';
			$smtp_port = is_array( $cfg ) ? (int) ( $cfg['port'] ?? 0 ) : 0;
			$cfg_from  = is_array( $cfg ) ? (string) ( $cfg['from'] ?? '' ) : '';
			$cfg_user  = is_array( $cfg ) ? (string) ( $cfg['user'] ?? '' ) : '';

			// [2026-07-17 Johnny Chu] HOTFIX — detailed mail failure line for
			// rapid diagnosis (provider code + provider message + smtp target).
			error_log( sprintf(
				'[bizcity-smtp] wp_mail_failed code=%s provider_code=%d smtp=%s:%d cfg_from=%s cfg_user=%s to_domains=%s subject=%s from=%s sender=%s reply_to=%s msg=%s provider_error=%s',
				$primary_code,
				$provider_code,
				$smtp_host !== '' ? $smtp_host : 'n/a',
				$smtp_port,
				self::mask_email_for_log( $cfg_from ),
				self::mask_email_for_log( $cfg_user ),
				wp_json_encode( $to_domains ),
				self::truncate_log_text( $subject, 140 ),
				self::truncate_log_text( $header_from, 140 ),
				self::truncate_log_text( $header_sender, 140 ),
				self::truncate_log_text( $header_reply, 140 ),
				self::truncate_log_text( $primary_msg, 220 ),
				self::truncate_log_text( $provider_err, 220 )
			) );

			if ( class_exists( 'BizCity_Channel_File_Logger', false ) ) {
				BizCity_Channel_File_Logger::write(
					BizCity_Channel_File_Logger::CH_EMAIL,
					BizCity_Channel_File_Logger::LEVEL_ERROR,
					'wp_mail_failed',
					'wp_mail_failed captured by BizCity_SMTP',
					array(
						'code'          => $primary_code,
						'provider_code' => $provider_code,
						'message'       => self::truncate_log_text( $primary_msg, 220 ),
						'provider_error'=> self::truncate_log_text( $provider_err, 220 ),
						'smtp_host'     => $smtp_host,
						'smtp_port'     => $smtp_port,
						'to_domains'    => $to_domains,
						'subject'       => self::truncate_log_text( $subject, 140 ),
						'cfg_from'      => self::mask_email_for_log( $cfg_from ),
						'cfg_user'      => self::mask_email_for_log( $cfg_user ),
						'from'          => self::truncate_log_text( $header_from, 140 ),
						'sender'        => self::truncate_log_text( $header_sender, 140 ),
						'reply_to'      => self::truncate_log_text( $header_reply, 140 ),
					)
				);
			}
		}

		/**
		 * Mask email local-part for safer diagnostics.
		 */
		private static function mask_email_for_log( string $email ): string {
			$email = sanitize_email( trim( $email ) );
			if ( $email === '' || strpos( $email, '@' ) === false ) {
				return $email !== '' ? '***' : '';
			}
			$parts  = explode( '@', $email, 2 );
			$local  = (string) ( $parts[0] ?? '' );
			$domain = (string) ( $parts[1] ?? '' );
			if ( $domain === '' ) {
				return '***';
			}
			$prefix = $local !== '' ? substr( $local, 0, 1 ) : '*';
			return $prefix . '***@' . $domain;
		}

		/**
		 * Extract a specific mail header value from WP mail payload.
		 *
		 * @param mixed  $raw_headers
		 * @param string $target
		 * @return string
		 */
		private static function extract_mail_header_value( $raw_headers, string $target ): string {
			// [2026-07-17 Johnny Chu] HOTFIX — helper for structured wp_mail_failed diagnostics.
			$target = strtolower( trim( $target ) );
			if ( $target === '' ) {
				return '';
			}

			$lines = array();
			if ( is_array( $raw_headers ) ) {
				$lines = $raw_headers;
			} elseif ( is_string( $raw_headers ) && $raw_headers !== '' ) {
				$lines = preg_split( '/\r\n|\r|\n/', $raw_headers );
			}

			foreach ( (array) $lines as $line ) {
				$line = trim( (string) $line );
				if ( $line === '' ) {
					continue;
				}
				if ( ! preg_match( '/^([A-Za-z0-9\-]+)\s*:\s*(.+)$/', $line, $m ) ) {
					continue;
				}
				$name  = strtolower( trim( (string) $m[1] ) );
				$value = trim( (string) $m[2] );
				if ( $name === $target ) {
					return $value;
				}
			}

			return '';
		}

		/**
		 * Extract provider-facing error message from WP mail failure payload.
		 */
		private static function extract_provider_error_message( string $fallback, array $data ): string {
			if ( isset( $data['phpmailer_exception'] ) && is_object( $data['phpmailer_exception'] ) && method_exists( $data['phpmailer_exception'], 'getMessage' ) ) {
				$msg = (string) $data['phpmailer_exception']->getMessage();
				if ( $msg !== '' ) {
					return $msg;
				}
			}

			if ( isset( $data['error'] ) && is_string( $data['error'] ) && $data['error'] !== '' ) {
				return $data['error'];
			}

			return $fallback;
		}

		/**
		 * Extract recipient domains only (avoid logging full emails).
		 *
		 * @param mixed $raw_to
		 * @return array<int,string>
		 */
		private static function extract_recipient_domains( $raw_to ): array {
			$emails = array();
			if ( is_array( $raw_to ) ) {
				$emails = $raw_to;
			} elseif ( is_string( $raw_to ) && $raw_to !== '' ) {
				$emails = preg_split( '/[,;]+/', $raw_to );
			}

			$domains = array();
			foreach ( $emails as $candidate ) {
				$email = sanitize_email( trim( (string) $candidate ) );
				if ( $email === '' || strpos( $email, '@' ) === false ) {
					continue;
				}
				$parts = explode( '@', $email );
				$domain = strtolower( trim( (string) end( $parts ) ) );
				if ( $domain !== '' ) {
					$domains[] = $domain;
				}
			}

			$domains = array_values( array_unique( $domains ) );
			if ( count( $domains ) > 5 ) {
				$domains = array_slice( $domains, 0, 5 );
				$domains[] = '...';
			}

			return $domains;
		}

		/**
		 * Normalize and clamp text for log lines.
		 */
		private static function truncate_log_text( string $text, int $max_len ): string {
			$text = preg_replace( '/\s+/', ' ', trim( $text ) );
			$text = (string) $text;
			if ( $max_len > 0 && strlen( $text ) > $max_len ) {
				return substr( $text, 0, $max_len ) . '...';
			}
			return $text;
		}
	}
}

BizCity_SMTP::bind();
