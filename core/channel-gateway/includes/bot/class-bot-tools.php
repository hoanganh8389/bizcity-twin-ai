<?php
/**
 * Bot Studio — tool planner + executor (PHASE-0.60A W5, B7.*).
 *
 * `BizCity_LLM_Client::chat()` has no native function-calling parameter, so
 * intent is still recognised BY THE MODEL from a tool list (doc §3.6 — no
 * keyword table): a bounded JSON planning call asks "do you need one of these
 * tools for this turn?"; the answer is either {"tool":null} or
 * {"tool":"id","args":{...}}. The tool result is then appended as a wrapped
 * data block and the normal reply call runs.
 *
 * Safety:
 *   - B7.4 every failure is an object {ok:false,error}, never a bare string.
 *   - B7.5 same tool + same args + same error twice in a turn → stop.
 *   - B7.6 external content is fenced with open/close markers; fake markers
 *     inside the content are neutralised before fencing.
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway\Bot
 * @since PHASE-0.60A W5 (2026-09-23)
 */

// [2026-09-23 03:40 PM Claude Fable 5.1] PHASE-0.60A W5 — planner (JSON) + executor with repeat guard and fencing.
defined( 'ABSPATH' ) || exit;

final class BizCity_Bot_Tools {

	const FENCE_OPEN  = '[DỮ LIỆU NGOÀI]';
	const FENCE_CLOSE = '[/DỮ LIỆU NGOÀI]';
	const MAX_CONTENT = 3500;

	/** @var callable|null test seam: fn(array $messages, array $opts): array LLM result */
	public static $planner_llm = null;

	/**
	 * Ask the model whether a tool is needed. Returns null when none.
	 *
	 * @param object $character
	 * @param array  $messages   Full context messages (system + history).
	 * @param array  $tools      Effective tool rows.
	 * @return array{tool:string,args:array}|null
	 */
	public static function plan( $character, array $messages, array $tools ) {
		if ( empty( $tools ) ) {
			return null;
		}
		$last_user = '';
		for ( $i = count( $messages ) - 1; $i >= 0; $i-- ) {
			if ( 'user' === ( $messages[ $i ]['role'] ?? '' ) ) {
				$last_user = (string) $messages[ $i ]['content'];
				break;
			}
		}
		if ( $last_user === '' ) {
			return null;
		}
		$prompt = "Bạn là bộ định tuyến công cụ. Chỉ chọn công cụ khi tin nhắn cuối của khách THẬT SỰ cần nó; chuyện thường thì không.\n"
			. "Công cụ có thể dùng:\n" . BizCity_Bot_Tool_Registry::describe_for_model( $tools ) . "\n"
			. "Trả lời DUY NHẤT một JSON, không giải thích: {\"tool\": null} hoặc {\"tool\": \"<id>\", \"args\": {\"query\": \"...\"}}.\n"
			. "Tin nhắn cuối của khách: " . mb_substr( $last_user, 0, 800 );
		$plan_messages = array(
			array( 'role' => 'system', 'content' => 'Trả lời chỉ bằng JSON hợp lệ.' ),
			array( 'role' => 'user', 'content' => $prompt ),
		);
		$opts = array( 'purpose' => 'bot_tool_plan', 'temperature' => 0, 'max_tokens' => 120, 'timeout' => 20 );
		if ( is_object( $character ) && ! empty( $character->model_id ) ) {
			$opts['model'] = (string) $character->model_id;
		}
		try {
			$res = is_callable( self::$planner_llm )
				? call_user_func( self::$planner_llm, $plan_messages, $opts )
				: ( class_exists( 'BizCity_LLM_Client' ) ? BizCity_LLM_Client::instance()->chat( $plan_messages, $opts ) : array() );
		} catch ( \Throwable $e ) {
			return null;
		}
		$text = is_array( $res ) ? (string) ( $res['message'] ?? '' ) : (string) $res;
		return self::parse_plan( $text, $tools );
	}

	/** Extract {"tool":..} from a model reply; unknown/disabled ids are rejected (prompt injection cannot add tools). */
	public static function parse_plan( string $text, array $tools ) {
		if ( ! preg_match( '/\{.*\}/s', $text, $m ) ) {
			return null;
		}
		$json = json_decode( $m[0], true );
		if ( ! is_array( $json ) || empty( $json['tool'] ) || ! is_string( $json['tool'] ) ) {
			return null;
		}
		$id = sanitize_key( $json['tool'] );
		foreach ( $tools as $t ) {
			if ( $t['id'] === $id ) {
				$args = isset( $json['args'] ) && is_array( $json['args'] ) ? $json['args'] : array();
				return array( 'tool' => $id, 'args' => $args );
			}
		}
		return null;
	}

	/**
	 * Execute one tool. Always returns an object (B7.4).
	 *
	 * @return array{ok:bool,content:string,error:string,ask?:bool,disclaimer?:string}
	 */
	public static function run( string $tool_id, array $args, array $claim ): array {
		$tool_id = sanitize_key( $tool_id );
		try {
			if ( strpos( $tool_id, BizCity_Bot_Vertical_Tools::TOOL_PREFIX ) === 0 && class_exists( 'BizCity_Bot_Vertical_Tools' ) ) {
				return BizCity_Bot_Vertical_Tools::run( substr( $tool_id, strlen( BizCity_Bot_Vertical_Tools::TOOL_PREFIX ) ), $args, $claim );
			}
			switch ( $tool_id ) {
				case 'current_datetime':
					return array( 'ok' => true, 'content' => 'Bây giờ là ' . ( function_exists( 'wp_date' ) ? wp_date( 'H:i, l d/m/Y' ) : date( 'H:i, l d/m/Y' ) ) . ' (múi giờ site).', 'error' => '' );
				case 'web_search':
					return self::web_search( (string) ( $args['query'] ?? '' ) );
				case 'read_url':
					return self::read_url( (string) ( $args['url'] ?? $args['query'] ?? '' ) );
				case 'astro_profile':
					return class_exists( 'BizCity_Bot_Astro_Tool' ) ? BizCity_Bot_Astro_Tool::run( $args, $claim ) : array( 'ok' => false, 'content' => '', 'error' => 'tool_unavailable' );
				default:
					return array( 'ok' => false, 'content' => '', 'error' => 'tool_unknown' );
			}
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'content' => '', 'error' => 'tool_exception' );
		}
	}

	/**
	 * Repeat guard (B7.5): same tool + args + error N times → stop.
	 */
	public static function repeat_key( string $tool_id, array $args, string $error ): string {
		return md5( $tool_id . '|' . wp_json_encode( $args ) . '|' . $error );
	}

	/** Fence external text so the model treats it as data, not instructions (B7.6/B7.7). */
	public static function fence( string $label, string $content, bool $unverified = true ): string {
		$content = str_replace( array( self::FENCE_OPEN, self::FENCE_CLOSE ), array( '[DU LIEU NGOAI]', '[/DU LIEU NGOAI]' ), $content );
		$content = mb_substr( trim( $content ), 0, self::MAX_CONTENT );
		$tag     = $unverified ? ' (chưa xác minh)' : '';
		return self::FENCE_OPEN . ' ' . $label . $tag . "\n" . $content . "\n" . self::FENCE_CLOSE;
	}

	/* ── tools ─────────────────────────────────────────────────────────── */

	private static function web_search( string $query ): array {
		$query = trim( $query );
		if ( $query === '' ) {
			return array( 'ok' => false, 'content' => '', 'error' => 'query_required' );
		}
		if ( ! class_exists( 'BizCity_Search_Client' ) ) {
			return array( 'ok' => false, 'content' => '', 'error' => 'search_unavailable' );
		}
		$results = BizCity_Search_Client::instance()->search( $query, 5, array( 'include_raw_content' => false, 'timeout' => 15 ) );
		if ( is_wp_error( $results ) ) {
			return array( 'ok' => false, 'content' => '', 'error' => (string) $results->get_error_code() );
		}
		$lines = array();
		foreach ( (array) $results as $r ) {
			$lines[] = '- ' . (string) ( $r['title'] ?? '' ) . ' (' . (string) ( $r['domain'] ?? '' ) . '): ' . (string) ( $r['excerpt'] ?? '' );
		}
		if ( empty( $lines ) ) {
			return array( 'ok' => true, 'content' => self::fence( 'Kết quả tra cứu', 'Không tìm thấy kết quả phù hợp.' ), 'error' => '' );
		}
		return array( 'ok' => true, 'content' => self::fence( 'Kết quả tra cứu web cho "' . mb_substr( $query, 0, 80 ) . '"', implode( "\n", $lines ) ), 'error' => '' );
	}

	private static function read_url( string $url ): array {
		$url = trim( $url );
		if ( $url === '' || ! preg_match( '#^https?://#i', $url ) ) {
			return array( 'ok' => false, 'content' => '', 'error' => 'url_required' );
		}
		if ( ! class_exists( 'BizCity_Search_Client' ) || ! method_exists( 'BizCity_Search_Client', 'extract' ) ) {
			return array( 'ok' => false, 'content' => '', 'error' => 'extract_unavailable' );
		}
		$res = BizCity_Search_Client::instance()->extract( array( $url ) );
		if ( is_wp_error( $res ) ) {
			return array( 'ok' => false, 'content' => '', 'error' => (string) $res->get_error_code() );
		}
		$first = is_array( $res ) && ! empty( $res ) ? reset( $res ) : array();
		$text  = (string) ( $first['raw_content'] ?? $first['content'] ?? '' );
		if ( trim( $text ) === '' ) {
			return array( 'ok' => false, 'content' => '', 'error' => 'empty_page' );
		}
		return array( 'ok' => true, 'content' => self::fence( 'Nội dung trang ' . (string) parse_url( $url, PHP_URL_HOST ), $text ), 'error' => '' );
	}
}
