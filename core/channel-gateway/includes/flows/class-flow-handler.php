<?php
/**
 * BizCity Channel Gateway — Flows · Handler
 *
 * Ported from `bizgpt-custom-flows/includes/custom-flow-handler.php` (2026-05-25).
 * Matches inbound user text against the `bizcity_crm_flows` table, then executes
 * one of two actions:
 *
 *   - `send_message`  — render template + optional LLM rephrase (reply_mode=direct|llm)
 *   - `run_shortcode` — collect required attrs (ask back if missing) → render shortcode
 *
 * Public entry points (function wrappers kept for backward-compat see flows/bootstrap.php):
 *
 *   - BizCity_CG_Flow_Handler::match( $question ): array
 *   - BizCity_CG_Flow_Handler::handle_guest_flow( $question ): array
 *   - BizCity_CG_Flow_Handler::run_steps( $flow_id, $ctx ): array
 *
 * @package    BizCity_Twin_AI
 * @subpackage Channel_Gateway\Flows
 * @since      PHASE-N (2026-05-25)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_CG_Flow_Handler {

	/* ============================================================
	 * Vietnamese accent stripping (port of bizgpt_flow_remove_vietnamese_accents)
	 * ============================================================ */
	public static function strip_accents( string $s ): string {
		$map = array(
			'a' => 'àáạảãâầấậẩẫăằắặẳẵ', 'A' => 'ÀÁẠẢÃÂẦẤẬẨẪĂẰẮẶẲẴ',
			'e' => 'èéẹẻẽêềếệểễ',        'E' => 'ÈÉẸẺẼÊỀẾỆỂỄ',
			'i' => 'ìíịỉĩ',                'I' => 'ÌÍỊỈĨ',
			'o' => 'òóọỏõôồốộổỗơờớợởỡ',  'O' => 'ÒÓỌỎÕÔỒỐỘỔỖƠỜỚỢỞỠ',
			'u' => 'ùúụủũưừứựửữ',        'U' => 'ÙÚỤỦŨƯỪỨỰỬỮ',
			'y' => 'ỳýỵỷỹ',                'Y' => 'ỲÝỴỶỸ',
			'd' => 'đ',                    'D' => 'Đ',
		);
		foreach ( $map as $ascii => $tones ) {
			$s = preg_replace( '/[' . $tones . ']/u', $ascii, $s );
		}
		return $s;
	}

	/* ============================================================
	 * match() — find best matching flow row for the input question
	 * @return array{flow_id:int,shortcode:string,output:array}|array{}
	 * ============================================================ */
	public static function match( string $question ): array {
		global $wpdb;
		$tbl   = BizCity_CG_Flow_Installer::table();
		$flows = $wpdb->get_results( "SELECT * FROM {$tbl}" );
		if ( empty( $flows ) ) {
			return array();
		}

		$q_lower = mb_strtolower( $question, 'UTF-8' );
		$q_nodac = mb_strtolower( self::strip_accents( $question ), 'UTF-8' );

		foreach ( $flows as $f ) {
			$msg     = mb_strtolower( (string) $f->message, 'UTF-8' );
			$msg_nd  = mb_strtolower( (string) ( $f->message_khong_dau ?? '' ), 'UTF-8' );
			$matched = false;
			if ( $msg !== '' && stripos( $q_lower, $msg ) !== false ) { $matched = true; }
			if ( ! $matched && $msg_nd !== '' && stripos( $q_nodac, $msg_nd ) !== false ) { $matched = true; }
			if ( ! $matched && $msg !== '' && levenshtein( $q_lower, $msg ) <= 2 )       { $matched = true; }
			if ( ! $matched && $msg_nd !== '' && levenshtein( $q_nodac, $msg_nd ) <= 2 ) { $matched = true; }

			if ( $matched ) {
				$out = self::parse_intent_via_llm( $question, (string) $f->prompt );
				return array(
					'flow_id'   => (int) $f->id,
					'shortcode' => (string) $f->shortcode,
					'output'    => $out,
				);
			}
		}
		return array();
	}

	/* ============================================================
	 * parse_intent_via_llm() — call BizCity_LLM_Client → expect JSON
	 * ============================================================ */
	private static function parse_intent_via_llm( string $question, string $prompt ): array {
		if ( '' === $prompt || ! class_exists( 'BizCity_LLM_Client' ) ) {
			return array();
		}
		$client = BizCity_LLM_Client::instance();
		if ( ! $client->is_ready() ) {
			return array();
		}
		$resp = $client->chat(
			array(
				array( 'role' => 'system', 'content' => 'Bạn là AI hỗ trợ chatbot eCommerce.' ),
				array( 'role' => 'user',   'content' => $prompt . "\n\nCâu hỏi liên quan đến " . $question ),
			),
			array( 'purpose' => 'fast', 'temperature' => 0.3, 'max_tokens' => 500, 'timeout' => 20 )
		);
		if ( empty( $resp['success'] ) ) {
			error_log( '[bizcity-cg-flows] LLM intent parser failed: ' . ( $resp['error'] ?? 'unknown' ) );
			return array();
		}
		return json_decode( (string) $resp['message'], true ) ?: array();
	}

	/* ============================================================
	 * handle_guest_flow() — match + cache context + execute
	 * ============================================================ */
	public static function handle_guest_flow( string $question ): array {
		$iden = function_exists( 'bizgpt_get_webchat_identity' )
			? bizgpt_get_webchat_identity()
			: array( 'session_id' => '', 'user_id' => 0 );
		$session_id = (string) ( $iden['session_id'] ?? '' );
		$user_id    = (int) ( $iden['user_id'] ?? 0 );
		$key        = 'bizcity_cg_flow_ctx_' . $session_id;
		$ctx        = get_transient( $key ) ?: array( 'flow_id' => 0, 'params' => array() );

		$cf = self::match( $question );
		if ( ! empty( $cf['flow_id'] ) ) {
			$ctx['flow_id'] = (int) $cf['flow_id'];
			$ctx['params']  = array_merge( $ctx['params'] ?? array(), $cf['output']['params'] ?? array() );
			set_transient( $key, $ctx, 10 * MINUTE_IN_SECONDS );
		}

		if ( ! empty( $ctx['flow_id'] ) ) {
			return self::run_steps( (int) $ctx['flow_id'], array(
				'user_id'    => $user_id,
				'session_id' => $session_id,
				'params'     => $ctx['params'] ?? array(),
				'question'   => $question,
			) );
		}
		return array();
	}

	/* ============================================================
	 * render_placeholders() — replace {{client_id}}/{{client_name}}/{{page_id}}
	 * from get_transient('hook_data') as set by FB hook / float chatbot.
	 * ============================================================ */
	public static function render_placeholders( string $msg ): string {
		$hd = get_transient( 'hook_data' );
		if ( ! is_array( $hd ) ) { return $msg; }
		return str_replace(
			array( '{{client_id}}', '{{client_name}}', '{{page_id}}' ),
			array(
				(string) ( $hd['client_id'] ?? '' ),
				(string) ( $hd['client_name'] ?? '' ),
				(string) ( $hd['page_id'] ?? '' ),
			),
			$msg
		);
	}

	/* ============================================================
	 * generate_via_llm() — rephrase a directive prompt into a customer reply
	 * (used only when reply_mode === 'llm')
	 * ============================================================ */
	private static function generate_via_llm( string $prompt ): string {
		if ( ! class_exists( 'BizCity_LLM_Client' ) ) { return $prompt; }
		$client = BizCity_LLM_Client::instance();
		if ( ! $client->is_ready() ) { return $prompt; }
		$resp = $client->chat(
			array(
				array( 'role' => 'system', 'content' => 'Bạn là trợ lý chăm sóc khách hàng thân thiện. Tạo tin nhắn trả lời khách theo đúng hướng dẫn, không thêm thắt ngoài hướng dẫn.' ),
				array( 'role' => 'user',   'content' => $prompt ),
			),
			array( 'purpose' => 'fast', 'temperature' => 0.7, 'max_tokens' => 300, 'timeout' => 20 )
		);
		if ( ! empty( $resp['success'] ) && ! empty( $resp['message'] ) ) {
			return (string) $resp['message'];
		}
		error_log( '[bizcity-cg-flows] reply_mode=llm generation failed: ' . ( $resp['error'] ?? 'unknown' ) );
		return $prompt;
	}

	/* ============================================================
	 * run_steps() — execute a flow row given runtime context
	 * @return array list of recorded message rows
	 * ============================================================ */
	public static function run_steps( int $flow_id, array $ctx ): array {
		global $wpdb;
		$tbl        = BizCity_CG_Flow_Installer::table();
		$user_id    = (int) ( $ctx['user_id'] ?? 0 );
		$session_id = (string) ( $ctx['session_id'] ?? '' );
		$params     = (array) ( $ctx['params'] ?? array() );

		$cache_key = "flow_row_{$flow_id}";
		$row = wp_cache_get( $cache_key, 'bizcity_crm_flows' );
		if ( ! $row ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tbl} WHERE id=%d", $flow_id ) );
			if ( $row ) {
				wp_cache_set( $cache_key, $row, 'bizcity_crm_flows', DAY_IN_SECONDS );
			}
		}
		if ( ! $row ) { return array(); }

		/* ---- Branch: send_message ---- */
		if ( 'send_message' === $row->action_type ) {
			$cfg      = json_decode( (string) $row->action_config, true ) ?: array();
			$msg_text = (string) ( $row->prompt ?? '' );
			$msg_text = self::render_placeholders( $msg_text );

			$reply_mode = (string) ( $row->reply_mode ?? 'direct' );
			if ( 'llm' === $reply_mode && '' !== $msg_text ) {
				$msg_text = self::generate_via_llm( $msg_text );
			}

			$rec = function_exists( 'bizgpt_log_chat_message' )
				? bizgpt_log_chat_message( $user_id, $msg_text, 'bot', $session_id )
				: array( 'text' => $msg_text );

			if ( ! empty( $cfg['clear_context'] ) ) {
				delete_transient( 'bizcity_cg_flow_ctx_' . $session_id );
			}
			return array( $rec );
		}

		/* ---- Branch: run_shortcode ---- */
		$cfg   = json_decode( (string) $row->action_config, true );
		$attrs = $cfg['attributes'] ?? array();

		// Drop attrs that hold fallback-question text instead of a real value.
		foreach ( $attrs as $attr ) {
			$k = $attr['key'] ?? '';
			if ( $k && isset( $params[ $k ] ) ) {
				if ( trim( $params[ $k ] ) === trim( $attr['prompt'] ?? '' ) || '...' === $params[ $k ] ) {
					unset( $params[ $k ] );
				}
			}
		}

		// Ask back if any required attr is missing.
		foreach ( $attrs as $attr ) {
			$k = $attr['key'] ?? '';
			if ( $k && empty( $params[ $k ] ) ) {
				$rec = function_exists( 'bizgpt_log_chat_message' )
					? bizgpt_log_chat_message( $user_id, (string) $attr['prompt'], 'bot', $session_id )
					: array( 'text' => $attr['prompt'] );
				return array( $rec );
			}
		}

		$sc = rtrim( (string) $row->shortcode, ']' );
		foreach ( $attrs as $attr ) {
			$k = $attr['key'] ?? '';
			if ( '' === $k ) { continue; }
			$sc .= sprintf( ' %s="%s"', $k, esc_attr( (string) $params[ $k ] ) );
		}
		$sc .= ']';

		// Run shortcode + replace placeholders in its output (FB hook context).
		$out = do_shortcode( $sc );
		$out = self::render_placeholders( $out );

		$rec = function_exists( 'bizgpt_log_chat_message' )
			? bizgpt_log_chat_message( $user_id, $out, 'bot', $session_id )
			: array( 'text' => $out );
		return array( $rec );
	}
}
