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

	public static function init() {
		// [2026-06-24 Johnny Chu] PHASE-CF7-RESP — intercept CF7 AJAX response
		add_filter( 'wpcf7_ajax_json_echo', array( __CLASS__, 'on_ajax_json_echo' ), 20, 2 );
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
		// Only process mail-sent (success) results
		$status = $response['status'] ?? '';
		if ( $status !== 'mail_sent' ) {
			return $response;
		}

		// [2026-06-24 Johnny Chu] HOTFIX — get form_id from $response first (CF7 REST API mode);
		// fallback to WPCF7_Submission only if needed, with full method_exists guard to prevent
		// "Call to undefined method WPCF7_Submission::contact_form()" on older CF7 versions.
		$form_id = 0;

		// CF7 v5+ REST mode: form ID is in the response array
		if ( isset( $response['contact_form_id'] ) ) {
			$form_id = (int) $response['contact_form_id'];
		}

		// Fallback: legacy AJAX mode — read from active submission instance
		if ( $form_id <= 0 && class_exists( 'WPCF7_Submission' ) ) {
			$sub = class_exists( 'WPCF7_Submission' ) && method_exists( 'WPCF7_Submission', 'get_instance' )
				? WPCF7_Submission::get_instance()
				: null;
			if ( $sub ) {
				if ( method_exists( $sub, 'contact_form' ) ) {
					$cf7     = $sub->contact_form();
					$form_id = $cf7 && method_exists( $cf7, 'id' ) ? (int) $cf7->id() : 0;
				} elseif ( method_exists( $sub, 'form' ) ) {
					// CF7 < 4.9 used ->form() instead of ->contact_form()
					$cf7     = $sub->form();
					$form_id = $cf7 && method_exists( $cf7, 'id' ) ? (int) $cf7->id() : 0;
				}
			}
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
