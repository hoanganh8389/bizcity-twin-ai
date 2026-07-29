<?php
/**
 * CF7 Response Config — per-form custom success message.
 *
 * Replaces the default `wpcf7-response-output` message with either:
 *   - A custom HTML string (supports download links, buttons, etc.)
 *   - An AI-generated response (calls BizCity_LLM_Client with submitted form data)
 *
 * Option key: `bizcity_cf7_response_config` (serialised array keyed by form_id).
 *
 * Hook: `wpcf7_ajax_json_echo` (WordPress filter, runs before CF7 echoes its JSON)
 *       `wpcf7_mail_sent` is used when reply_type = 'ai' to read submission data
 *       (CF7 AJAX sends a single request; we use a transient to bridge the two hooks).
 *
 * [2026-06-24 Johnny Chu] PHASE-CF7-RESP — new class.
 *
 * @package BizCity_Channel_Gateway
 * @since   PHASE-CF7-RESP (2026-06-24)
 */

defined( 'ABSPATH' ) || exit;

class BizCity_CF7_Response_Config {

	const OPTION_KEY = 'bizcity_cf7_response_config';

	/**
	 * Last wp_mail_failed details captured in the current request.
	 *
	 * @var array<string,mixed>
	 */
	private static $last_mail_failed = array();

	public static function init() {
		// [2026-06-24 Johnny Chu] PHASE-CF7-RESP — intercept CF7 AJAX response
		add_filter( 'wpcf7_ajax_json_echo', array( __CLASS__, 'on_ajax_json_echo' ), 20, 2 );
		// [2026-07-17 Johnny Chu] HOTFIX — harden CF7 mail payload against
		// unresolved mail-tags in subject/headers to reduce SMTP DATA rejection.
		add_filter( 'wpcf7_mail_components', array( __CLASS__, 'sanitize_mail_components' ), 20, 3 );
		// [2026-07-17 Johnny Chu] HOTFIX — capture wp_mail_failed details so
		// CF7 frontend can show an actionable error instead of generic text.
		add_action( 'wp_mail_failed', array( __CLASS__, 'capture_mail_failed' ), 1, 1 );
	}

	// ── Option CRUD ──────────────────────────────────────────────────────────

	/**
	 * Get config for a single form.
	 * Returns array: { reply_type: 'none'|'custom'|'ai', custom_html: '', prompt_prefix: '', enabled: bool }
	 *
	 * @param  int $form_id
	 * @return array
	 */
	public static function get( $form_id ) {
		$all  = (array) get_option( self::OPTION_KEY, array() );
		$key  = (int) $form_id;
		$cfg  = isset( $all[ $key ] ) ? (array) $all[ $key ] : array();
		return array_merge(
			array(
				'reply_type'    => 'none',
				'custom_html'   => '',
				'prompt_prefix' => '',
				'enabled'       => false,
			),
			$cfg
		);
	}

	/**
	 * Save config for a single form.
	 *
	 * @param  int   $form_id
	 * @param  array $data
	 */
	public static function save( $form_id, array $data ) {
		$all = (array) get_option( self::OPTION_KEY, array() );
		$key = (int) $form_id;

		// Sanitise
		$reply_type    = in_array( $data['reply_type'] ?? 'none', array( 'none', 'custom', 'ai' ), true )
			? (string) $data['reply_type']
			: 'none';
		$custom_html   = isset( $data['custom_html'] ) ? wp_kses_post( (string) $data['custom_html'] ) : '';
		$prompt_prefix = isset( $data['prompt_prefix'] ) ? sanitize_textarea_field( (string) $data['prompt_prefix'] ) : '';
		$enabled       = ! empty( $data['enabled'] );

		$all[ $key ] = array(
			'reply_type'    => $reply_type,
			'custom_html'   => $custom_html,
			'prompt_prefix' => $prompt_prefix,
			'enabled'       => $enabled,
		);

		update_option( self::OPTION_KEY, $all, false );
	}

	// ── Hook: intercept CF7 AJAX JSON ───────────────────────────────────────

	/**
	 * `wpcf7_ajax_json_echo` filter — called just before CF7 echoes its JSON
	 * on form submit AJAX response.
	 *
	 * @param  array $response  CF7 JSON response array
	 * @param  array $result    CF7 submission result
	 * @return array
	 */
	public static function on_ajax_json_echo( $response, $result ) {
		$form_id = self::resolve_form_id_from_response( $response );

		// [2026-07-17 Johnny Chu] HOTFIX — provide clearer message for mail_failed.
		$status = $response['status'] ?? '';
		if ( $status === 'mail_failed' ) {
			// [2026-07-17 Johnny Chu] HOTFIX — fail-open UX: do not block customer flow
			// when SMTP fails. Submission is accepted and mail failure is flagged separately.
			$payload             = self::build_mail_failed_payload( $form_id );
			$response['status']  = 'mail_sent';
			$response['message'] = (string) ( $payload['public_message'] ?? $payload['message'] );
			$response['bizcity_error'] = $payload;
			$response['bizcity_mail_flagged'] = true;
			return $response;
		}

		// Only process mail-sent (success) results
		if ( $status !== 'mail_sent' ) {
			return $response;
		}

		if ( $form_id <= 0 ) {
			return $response;
		}

		$cfg = self::get( $form_id );
		if ( empty( $cfg['enabled'] ) ) {
			return $response;
		}

		$reply_type = (string) $cfg['reply_type'];

		if ( $reply_type === 'custom' && $cfg['custom_html'] !== '' ) {
			// [2026-06-24 Johnny Chu] PHASE-CF7-RESP — replace with custom HTML
			$response['message'] = $cfg['custom_html'];
			return $response;
		}

		if ( $reply_type === 'ai' ) {
			// [2026-06-24 Johnny Chu] PHASE-CF7-RESP — AI-generated response
			$ai_msg = self::generate_ai_response( $form_id, $cfg );
			if ( $ai_msg !== '' ) {
				$response['message'] = $ai_msg;
			}
			return $response;
		}

		return $response;
	}

	/**
	 * Resolve CF7 form id from AJAX response / submission context.
	 *
	 * @param mixed $response
	 * @return int
	 */
	private static function resolve_form_id_from_response( $response ) {
		// [2026-07-17 Johnny Chu] HOTFIX — shared resolver for both mail_sent
		// and mail_failed branches to keep guidance tied to the exact form.
		$form_id = 0;

		if ( is_array( $response ) && isset( $response['contact_form_id'] ) ) {
			$form_id = (int) $response['contact_form_id'];
		}

		if ( $form_id <= 0 && class_exists( 'WPCF7_Submission' ) ) {
			$sub = method_exists( 'WPCF7_Submission', 'get_instance' )
				? WPCF7_Submission::get_instance()
				: null;
			if ( $sub ) {
				if ( method_exists( $sub, 'contact_form' ) ) {
					$cf7     = $sub->contact_form();
					$form_id = $cf7 && method_exists( $cf7, 'id' ) ? (int) $cf7->id() : 0;
				} elseif ( method_exists( $sub, 'form' ) ) {
					$cf7     = $sub->form();
					$form_id = $cf7 && method_exists( $cf7, 'id' ) ? (int) $cf7->id() : 0;
				}
			}
		}

		return (int) $form_id;
	}

	/**
	 * Sanitize CF7 mail components to avoid SMTP rejection when form tags are misconfigured.
	 *
	 * @param array $components
	 * @param mixed $contact_form
	 * @param mixed $mail
	 * @return array
	 */
	public static function sanitize_mail_components( $components, $contact_form, $mail ) {
		if ( ! is_array( $components ) ) {
			return $components;
		}

		$form_id = is_object( $contact_form ) && method_exists( $contact_form, 'id' )
			? (int) $contact_form->id()
			: 0;

		if ( isset( $components['sender'] ) ) {
			$old_sender = (string) $components['sender'];
			$new_sender = self::sanitize_sender( $old_sender );
			if ( $new_sender !== $old_sender ) {
				// [2026-07-17 Johnny Chu] HOTFIX — normalize invalid/unresolved sender.
				$components['sender'] = $new_sender;
				error_log( '[bizcity-cf7] sender_sanitized form_id=' . $form_id );
			}
		}

		if ( isset( $components['recipient'] ) ) {
			$old_recipient = (string) $components['recipient'];
			$new_recipient = self::sanitize_recipient( $old_recipient );
			if ( $new_recipient !== $old_recipient ) {
				// [2026-07-17 Johnny Chu] HOTFIX — normalize invalid/unresolved recipient list.
				$components['recipient'] = $new_recipient;
				error_log( '[bizcity-cf7] recipient_sanitized form_id=' . $form_id );
			}
		}

		if ( isset( $components['subject'] ) ) {
			$old_subject = (string) $components['subject'];
			$new_subject = self::sanitize_subject( $old_subject, $form_id );
			if ( $new_subject !== $old_subject ) {
				// [2026-07-17 Johnny Chu] HOTFIX — auto-fix unresolved subject tags.
				$components['subject'] = $new_subject;
				error_log( '[bizcity-cf7] subject_sanitized form_id=' . $form_id . ' old=' . self::truncate_text( $old_subject, 120 ) . ' new=' . self::truncate_text( $new_subject, 120 ) );
			}
		}

		if ( isset( $components['additional_headers'] ) ) {
			$old_headers = (string) $components['additional_headers'];
			$new_headers = self::sanitize_headers( $old_headers, $form_id );
			if ( $new_headers !== $old_headers ) {
				// [2026-07-17 Johnny Chu] HOTFIX — drop unresolved header lines
				// (e.g. Reply-To: [your-email] when field does not exist).
				$components['additional_headers'] = $new_headers;
				error_log( '[bizcity-cf7] headers_sanitized form_id=' . $form_id );
			}
		}

		return $components;
	}

	/**
	 * Capture wp_mail_failed details in-memory for current request.
	 *
	 * @param mixed $error
	 */
	public static function capture_mail_failed( $error ) {
		if ( ! ( $error instanceof WP_Error ) ) {
			return;
		}
		if ( ! class_exists( 'WPCF7_Submission' ) || ! method_exists( 'WPCF7_Submission', 'get_instance' ) || ! WPCF7_Submission::get_instance() ) {
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

		$provider_err  = self::extract_provider_error( $primary_msg, $data );
		$provider_code = isset( $data['phpmailer_exception_code'] ) ? (int) $data['phpmailer_exception_code'] : 0;
		$reason_code   = self::map_mail_failed_reason( $primary_code, $provider_err, $provider_code );

		self::$last_mail_failed = array(
			'code'          => $primary_code,
			'message'       => $primary_msg,
			'provider_error' => $provider_err,
			'provider_code' => $provider_code,
			'reason_code'   => $reason_code,
		);
	}

	/**
	 * Get latest wp_mail_failed details captured in current request.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_last_mail_failed() {
		// [2026-07-17 Johnny Chu] HOTFIX — expose mail_failed details so CF7
		// listener can write evidence log + dashboard stats.
		return is_array( self::$last_mail_failed ) ? self::$last_mail_failed : array();
	}

	/**
	 * Build a clearer CF7 mail_failed message for user/admin.
	 */
	private static function build_mail_failed_payload( $form_id = 0 ) {
		$reason_code = isset( self::$last_mail_failed['reason_code'] )
			? (string) self::$last_mail_failed['reason_code']
			: 'smtp_send_failed';
		$form_id     = (int) $form_id;
		$form_hint   = $form_id > 0
			? 'Form ID ' . $form_id . ' (Contact Form 7).'
			: 'Form Contact Form 7 đang dùng.';
		$edit_url    = $form_id > 0
			? admin_url( 'admin.php?page=wpcf7&post=' . $form_id . '&action=edit' )
			: admin_url( 'admin.php?page=wpcf7' );

		$message      = 'Không thể gửi email lúc này.';
		$hint         = 'Vui lòng thử lại sau vài phút hoặc liên hệ quản trị website.';
		$public_msg   = 'Thông tin của bạn đã được ghi nhận thành công. Đội ngũ sẽ phản hồi sớm nhất qua kênh phù hợp.';
		$help_code = 'cf7_mail_failed_generic';
		if ( $reason_code === 'smtp_data_not_accepted' ) {
			// [2026-07-17 Johnny Chu] HOTFIX — expand admin guidance to include To.
			$message = 'Email chưa thể gửi do cấu hình biểu mẫu chưa hợp lệ.';
			$hint = 'Quản trị viên: mở ' . $form_hint . ' Vào tab Mail, kiểm tra To/From/Reply-To/Subject (không dùng tag [field] không tồn tại). Link: ' . esc_url_raw( $edit_url );
			$help_code = 'cf7_mail_data_not_accepted';
		} elseif ( $reason_code === 'smtp_auth_failed' ) {
			$message = 'Email chưa thể gửi do xác thực SMTP thất bại.';
			$hint = 'Quản trị viên: kiểm tra tài khoản SMTP/App Password và xác thực Gmail tại Channel Gateway > Email SMTP > Cài đặt. Link: ' . esc_url_raw( admin_url( 'admin.php?page=bizchat-gateway-spa#/p/email_smtp/settings' ) );
			$help_code = 'cf7_mail_auth_failed';
		} elseif ( $reason_code === 'smtp_connect_failed' ) {
			$message = 'Email chưa thể gửi do không kết nối được SMTP.';
			$hint = 'Quản trị viên: kiểm tra host/port/tls của SMTP và firewall outbound tại Email SMTP > Cài đặt. Link: ' . esc_url_raw( admin_url( 'admin.php?page=bizchat-gateway-spa#/p/email_smtp/settings' ) );
			$help_code = 'cf7_mail_connect_failed';
		} elseif ( $form_id > 0 ) {
			// [2026-07-17 Johnny Chu] HOTFIX — generic branch still gives exact form path.
			$hint = 'Quản trị viên: kiểm tra cấu hình Mail của Form ID ' . $form_id . '. Link: ' . esc_url_raw( $edit_url );
		}

		return array(
			'code'           => $reason_code,
			'message'        => $message,
			'hint'           => $hint,
			'help_code'      => $help_code,
			'public_message' => $public_msg,
		);
	}

	/**
	 * Remove unresolved CF7 tags from subject and ensure fallback subject exists.
	 */
	private static function sanitize_subject( $subject, $form_id ) {
		$subject = (string) $subject;
		$subject = self::strip_unresolved_tags( $subject );
		$subject = preg_replace( '/\s+/', ' ', trim( $subject ) );
		$subject = trim( (string) $subject, " \t\n\r\0\x0B\"'[]" );

		if ( $subject === '' ) {
			$site_name = get_bloginfo( 'name' );
			$subject   = $site_name . ' - Form #' . (int) $form_id;
		}

		return $subject;
	}

	/**
	 * Sanitize sender header value.
	 */
	private static function sanitize_sender( $sender ) {
		// [2026-07-17 Johnny Chu] HOTFIX — auto-normalize sender to a valid mailbox.
		$sender = trim( (string) self::strip_unresolved_tags( (string) $sender ) );
		$sender = preg_replace( '/\s+/', ' ', $sender );

		$name  = '';
		$email = '';

		if ( preg_match( '/^(.*)<([^<>]+)>$/', $sender, $m ) ) {
			$name  = trim( (string) $m[1], " \t\n\r\0\x0B\"'" );
			$email = sanitize_email( trim( (string) $m[2] ) );
		} else {
			$email = sanitize_email( $sender );
		}

		if ( ! is_email( $email ) ) {
			$email = self::default_sender_email();
		}

		// [2026-07-17 Johnny Chu] HOTFIX — force CF7 sender mailbox to SMTP from
		// when available (Gmail rejects many non-alias From values at DATA stage).
		$smtp_from = self::smtp_sender_email();
		if ( $smtp_from !== '' ) {
			$email = $smtp_from;
		}

		$name = sanitize_text_field( $name );
		if ( $name === '' ) {
			$name = sanitize_text_field( (string) get_bloginfo( 'name' ) );
		}

		if ( $name !== '' ) {
			return $name . ' <' . $email . '>';
		}

		return $email;
	}

	/**
	 * Sanitize recipient list and keep only valid email addresses.
	 */
	private static function sanitize_recipient( $recipient ) {
		// [2026-07-17 Johnny Chu] HOTFIX — keep only valid recipient addresses.
		$recipient = trim( (string) self::strip_unresolved_tags( (string) $recipient ) );
		$recipient = str_replace( ';', ',', $recipient );

		$parts = explode( ',', $recipient );
		$valid = array();
		foreach ( $parts as $part ) {
			$email = sanitize_email( trim( (string) $part ) );
			if ( $email !== '' && is_email( $email ) ) {
				$valid[] = strtolower( $email );
			}
		}
		$valid = array_values( array_unique( $valid ) );

		if ( empty( $valid ) ) {
			$fallback = sanitize_email( (string) get_option( 'admin_email', '' ) );
			if ( $fallback === '' || ! is_email( $fallback ) ) {
				$fallback = self::default_sender_email();
			}
			$valid[] = strtolower( $fallback );
		}

		return implode( ', ', $valid );
	}

	/**
	 * Resolve SMTP sender mailbox from core SMTP config, when available.
	 */
	private static function smtp_sender_email() {
		if ( class_exists( 'BizCity_SMTP' ) && method_exists( 'BizCity_SMTP', 'resolve_config' ) ) {
			$cfg = BizCity_SMTP::resolve_config();
			if ( is_array( $cfg ) && ! empty( $cfg['from'] ) ) {
				$email = sanitize_email( (string) $cfg['from'] );
				if ( $email !== '' && is_email( $email ) ) {
					return strtolower( $email );
				}
			}
		}

		return '';
	}

	/**
	 * Build a safe default sender email from site domain.
	 */
	private static function default_sender_email() {
		// [2026-07-17 Johnny Chu] HOTFIX — stable fallback sender for malformed forms.
		$smtp_from = self::smtp_sender_email();
		if ( $smtp_from !== '' ) {
			return $smtp_from;
		}

		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$host = is_string( $host ) ? strtolower( trim( $host ) ) : '';
		$host = preg_replace( '/^www\./', '', $host );

		if ( $host === '' || strpos( $host, '.' ) === false ) {
			$host = 'localhost.localdomain';
		}

		$email = sanitize_email( 'wordpress@' . $host );
		if ( $email === '' || ! is_email( $email ) ) {
			$email = sanitize_email( (string) get_option( 'admin_email', '' ) );
		}
		if ( $email === '' || ! is_email( $email ) ) {
			$email = 'wordpress@localhost.localdomain';
		}

		return $email;
	}

	/**
	 * Remove unresolved CF7 mail-tags, e.g. [your-email].
	 */
	private static function strip_unresolved_tags( $text ) {
		// [2026-07-17 Johnny Chu] HOTFIX — remove unresolved CF7 tags from mail components.
		return (string) preg_replace( '/\[[a-z0-9_\-.:]+\]/', '', (string) $text );
	}

	/**
	 * Drop header lines that still contain unresolved CF7 tags.
	 */
	private static function sanitize_headers( $headers, $form_id ) {
		$headers = (string) $headers;
		if ( $headers === '' ) {
			return $headers;
		}

		$lines    = preg_split( '/\r\n|\r|\n/', $headers );
		$clean    = array();
		$removed  = 0;
		$updated  = 0;

		foreach ( (array) $lines as $line ) {
			$line = trim( (string) $line );
			if ( $line === '' ) {
				continue;
			}
			if ( preg_match( '/\[[a-z0-9_\-.:]+\]/', $line ) ) {
				$removed++;
				continue;
			}

			$sanitized = self::sanitize_header_line( $line );
			if ( $sanitized === '' ) {
				$removed++;
				continue;
			}
			if ( $sanitized !== $line ) {
				$updated++;
			}
			$clean[] = $sanitized;
		}

		if ( $removed > 0 ) {
			error_log( '[bizcity-cf7] unresolved_header_removed form_id=' . (int) $form_id . ' removed=' . $removed );
		}
		if ( $updated > 0 ) {
			error_log( '[bizcity-cf7] header_value_sanitized form_id=' . (int) $form_id . ' updated=' . $updated );
		}

		return implode( "\n", $clean );
	}

	/**
	 * Sanitize sensitive header lines and reject invalid values.
	 */
	private static function sanitize_header_line( $line ) {
		if ( ! preg_match( '/^([A-Za-z0-9\-]+)\s*:\s*(.+)$/', $line, $m ) ) {
			return $line;
		}

		$key   = strtolower( trim( (string) $m[1] ) );
		$value = trim( (string) $m[2] );

		if ( $key === 'reply-to' ) {
			$emails = self::extract_valid_emails_from_text( $value );
			if ( empty( $emails ) ) {
				return '';
			}
			return 'Reply-To: ' . $emails[0];
		}

		if ( $key === 'cc' || $key === 'bcc' ) {
			$emails = self::extract_valid_emails_from_text( $value );
			if ( empty( $emails ) ) {
				return '';
			}
			$label = $key === 'cc' ? 'Cc' : 'Bcc';
			return $label . ': ' . implode( ', ', $emails );
		}

		if ( $key === 'from' || $key === 'sender' ) {
			$sender = self::sanitize_sender( $value );
			if ( $sender === '' ) {
				return '';
			}
			$label = $key === 'from' ? 'From' : 'Sender';
			return $label . ': ' . $sender;
		}

		return $line;
	}

	/**
	 * Extract valid email addresses from a header value.
	 *
	 * @return array<int,string>
	 */
	private static function extract_valid_emails_from_text( $text ) {
		$text   = str_replace( ';', ',', (string) $text );
		$emails = array();

		if ( preg_match_all( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $text, $m ) && ! empty( $m[0] ) ) {
			foreach ( (array) $m[0] as $candidate ) {
				$email = sanitize_email( (string) $candidate );
				if ( $email !== '' && is_email( $email ) ) {
					$emails[] = strtolower( $email );
				}
			}
		}

		if ( empty( $emails ) ) {
			$parts = explode( ',', $text );
			foreach ( $parts as $part ) {
				$email = sanitize_email( trim( (string) $part ) );
				if ( $email !== '' && is_email( $email ) ) {
					$emails[] = strtolower( $email );
				}
			}
		}

		return array_values( array_unique( $emails ) );
	}

	/**
	 * Resolve provider-level SMTP error text.
	 */
	private static function extract_provider_error( $fallback, array $data ) {
		if ( isset( $data['phpmailer_exception'] ) && is_object( $data['phpmailer_exception'] ) && method_exists( $data['phpmailer_exception'], 'getMessage' ) ) {
			$msg = (string) $data['phpmailer_exception']->getMessage();
			if ( $msg !== '' ) {
				return $msg;
			}
		}

		if ( isset( $data['error'] ) && is_string( $data['error'] ) && $data['error'] !== '' ) {
			return $data['error'];
		}

		return (string) $fallback;
	}

	/**
	 * Map wp_mail_failed details into a stable reason code for frontend copy.
	 */
	private static function map_mail_failed_reason( $code, $provider_error, $provider_code ) {
		$code           = strtolower( (string) $code );
		$provider_error = strtolower( (string) $provider_error );

		if ( strpos( $provider_error, 'data not accepted' ) !== false ) {
			return 'smtp_data_not_accepted';
		}
		if ( strpos( $provider_error, 'authenticate' ) !== false || strpos( $provider_error, 'username and password not accepted' ) !== false ) {
			return 'smtp_auth_failed';
		}
		if ( strpos( $provider_error, 'connect' ) !== false || strpos( $provider_error, 'timed out' ) !== false ) {
			return 'smtp_connect_failed';
		}
		if ( $code === 'wp_mail_failed' && (int) $provider_code === 2 ) {
			return 'smtp_data_not_accepted';
		}
		return 'smtp_send_failed';
	}

	/**
	 * Compact text for log lines.
	 */
	private static function truncate_text( $text, $max_len ) {
		$text = preg_replace( '/\s+/', ' ', trim( (string) $text ) );
		if ( (int) $max_len > 0 && strlen( $text ) > (int) $max_len ) {
			return substr( $text, 0, (int) $max_len ) . '...';
		}
		return $text;
	}

	// ── AI reply generator ───────────────────────────────────────────────────

	/**
	 * Call BizCity_LLM_Client to generate a personalised reply.
	 *
	 * @param  int   $form_id
	 * @param  array $cfg      Response config
	 * @return string  HTML string or '' on failure
	 */
	private static function generate_ai_response( $form_id, array $cfg ) {
		if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
			return '';
		}
		$llm = BizCity_LLM_Client::instance();
		if ( ! $llm->is_ready() ) {
			return '';
		}

		// Read posted form data for context
		$posted = array();
		if ( class_exists( 'WPCF7_Submission' ) ) {
			$sub = WPCF7_Submission::get_instance();
			if ( $sub ) {
				$raw = (array) $sub->get_posted_data();
				foreach ( $raw as $k => $v ) {
					// Skip CF7 internal fields
					if ( strpos( $k, '_wpcf7' ) === 0 ) { continue; }
					$posted[ $k ] = is_array( $v ) ? implode( ', ', $v ) : (string) $v;
				}
			}
		}

		// Build context string from form fields
		$field_lines = array();
		foreach ( $posted as $k => $v ) {
			if ( trim( $v ) === '' ) { continue; }
			$field_lines[] = '- ' . $k . ': ' . mb_substr( $v, 0, 200 );
		}
		$form_context = implode( "\n", $field_lines );

		$site_name = get_bloginfo( 'name' );

		$prompt_prefix = ! empty( $cfg['prompt_prefix'] )
			? $cfg['prompt_prefix']
			: 'Bạn là nhân viên CSKH chuyên nghiệp của ' . $site_name . '. Trả lời bằng tiếng Việt, thân thiện, lịch sự. Nội dung ngắn gọn 2-4 câu.';

		$user_prompt = $prompt_prefix . "\n\nThông tin khách hàng vừa gửi form:\n" . ( $form_context ?: '(không có dữ liệu)' ) . "\n\nViết thông báo xác nhận và lời cảm ơn ngắn gọn bằng HTML đơn giản (chỉ dùng <p>, <strong>, <a>). KHÔNG dùng markdown.";

		try {
			$resp = $llm->chat(
				array(
					array( 'role' => 'user', 'content' => $user_prompt ),
				),
				array( 'purpose' => 'cf7_response', 'max_tokens' => 300 )
			);
			$text = is_array( $resp ) ? (string) ( $resp['content'] ?? $resp['choices'][0]['message']['content'] ?? '' ) : '';
			// Trim markdown fences if model wrapped output
			$text = preg_replace( '/^```[a-z]*\s*/i', '', $text );
			$text = preg_replace( '/\s*```$/i', '', $text );
			return trim( $text );
		} catch ( \Exception $e ) {
			return '';
		}
	}
}
