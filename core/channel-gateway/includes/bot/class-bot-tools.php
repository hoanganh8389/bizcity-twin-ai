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
	/** @var callable|null test seam: fn(string $event, array $ctx): void — EA-7.4 cross-thread-read logging */
	public static $log_writer = null;

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
		// [2026-09-24 Claude Opus 5.5] PHASE-0.60H D-H5 — a poll / group-creation JSON (question + options, uid list) does not fit
		// 120 tokens and a truncated JSON silently means "no tool" (SIDECAR-OUTBOUND-ACTIONS-PHASE-PLAN, first finding).
		$long_args  = array_intersect( array_column( $tools, 'id' ), array( 'create_poll', 'create_group', 'add_group_members', 'pin_group_note' ) );
		$opts = array( 'purpose' => 'bot_tool_plan', 'temperature' => 0, 'max_tokens' => empty( $long_args ) ? 120 : 260, 'timeout' => 20 );
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
			// [2026-09-24 Claude Opus 5.5] PHASE-0.60H D-H5 — Zalo-native actions (sticker, poll, group admin…).
			if ( class_exists( 'BizCity_Bot_Zalo_Actions' ) && BizCity_Bot_Zalo_Actions::handles( $tool_id ) ) {
				return BizCity_Bot_Zalo_Actions::run( $tool_id, $args, $claim );
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
				case 'list_threads':
					return self::list_threads( $claim );
				case 'read_thread':
					return self::read_thread( (int) ( $args['index'] ?? 0 ), $claim );
				case 'scrape_social_data':
					return self::scrape_social_data( $args, $claim );
				case 'generate_image':
					return self::generate_image( $args, $claim );
				// [2026-09-24 Claude Sonnet 5] PHASE-0.60H D-H3 — a short voice companion to the text reply.
				case 'tts':
					return self::text_to_speech( $args, $claim );
				// [2026-09-24 Claude Sonnet 5] PHASE-0.60H D-H1 — identity-scoped customer memory.
				case 'save_memory':
					return class_exists( 'BizCity_Bot_Memory' )
						? BizCity_Bot_Memory::run_tool( $args, $claim )
						: array( 'ok' => false, 'content' => '', 'error' => 'module_not_loaded' );
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

	/**
	 * EA-7 (doc §6, D-E2) — count the account owner's OTHER group threads. The bridge's
	 * experimental group-discovery route returns only opaque, hash-based tokens — no group
	 * name — so this can only ever offer "Nhóm 1, Nhóm 2, …", never a human label. That is a
	 * real limit of the underlying sidecar capability, not something to paper over here.
	 * BizCity_Bot_Tool_Registry::effective_for_turn() must already have gated the caller down
	 * to (owner_uid matches sender, private chat) before this ever runs — this method does not
	 * re-check that, it trusts the tool list it was offered from.
	 */
	private static function list_threads( array $claim ): array {
		if ( ! class_exists( 'BizCity_Zalo_Bridge_Client' ) ) {
			return array( 'ok' => false, 'content' => '', 'error' => 'bridge_unavailable' );
		}
		$account_id = (string) ( $claim['account_id'] ?? '' );
		if ( '' === $account_id ) {
			return array( 'ok' => false, 'content' => '', 'error' => 'account_missing' );
		}
		$result = BizCity_Zalo_Bridge_Client::instance()->get_group_candidates( $account_id );
		if ( empty( $result['success'] ) && empty( $result['ok'] ) ) {
			return array( 'ok' => false, 'content' => '', 'error' => (string) ( $result['error'] ?? $result['code'] ?? 'list_threads_failed' ) );
		}
		$refs = array();
		foreach ( (array) ( $result['groups'] ?? array() ) as $g ) {
			$ref = (string) ( $g['thread_ref'] ?? '' );
			if ( '' !== $ref ) {
				$refs[] = $ref;
			}
		}
		self::remember_threads( (int) ( $claim['contact_id'] ?? 0 ), $refs );
		self::log_cross_thread_read( $claim, 'list_threads', 0, count( $refs ) );
		if ( empty( $refs ) ) {
			return array( 'ok' => true, 'content' => self::fence( 'Danh sách nhóm khác', 'Không có nhóm nào khác.' ), 'error' => '' );
		}
		$lines = array();
		for ( $i = 1; $i <= count( $refs ); $i++ ) {
			$lines[] = 'Nhóm ' . $i . ' (không có tên do bridge chỉ trả token ẩn danh).';
		}
		$lines[] = 'Gọi read_thread với đúng số thứ tự ở trên để đọc nội dung.';
		return array( 'ok' => true, 'content' => self::fence( 'Danh sách nhóm khác (' . count( $refs ) . ')', implode( "\n", $lines ) ), 'error' => '' );
	}

	/** EA-7 read: `index` must be a number the model just saw from list_threads THIS session — never an arbitrary thread id (prompt injection cannot name a group directly). */
	private static function read_thread( int $index, array $claim ): array {
		if ( $index <= 0 ) {
			return array( 'ok' => false, 'content' => '', 'error' => 'index_required' );
		}
		if ( ! class_exists( 'BizCity_Zalo_Bridge_Client' ) ) {
			return array( 'ok' => false, 'content' => '', 'error' => 'bridge_unavailable' );
		}
		$contact_id = (int) ( $claim['contact_id'] ?? 0 );
		$refs       = self::recall_threads( $contact_id );
		$ref        = $refs[ $index - 1 ] ?? '';
		if ( '' === $ref ) {
			return array( 'ok' => false, 'content' => '', 'error' => 'thread_index_unknown' );
		}
		$account_id = (string) ( $claim['account_id'] ?? '' );
		$result     = BizCity_Zalo_Bridge_Client::instance()->get_group_history( $account_id, $ref, 20 );
		$messages   = (array) ( $result['messages'] ?? array() );
		self::log_cross_thread_read( $claim, 'read_thread', $index, count( $messages ) );
		if ( empty( $result['success'] ) && empty( $result['ok'] ) ) {
			return array( 'ok' => false, 'content' => '', 'error' => (string) ( $result['error'] ?? $result['code'] ?? 'read_thread_failed' ) );
		}
		if ( empty( $messages ) ) {
			return array( 'ok' => true, 'content' => self::fence( 'Nội dung nhóm ' . $index, 'Chưa có tin nhắn.' ), 'error' => '' );
		}
		$lines = array();
		foreach ( $messages as $m ) {
			$text = trim( (string) ( $m['content'] ?? '' ) );
			if ( '' === $text ) {
				continue;
			}
			$lines[] = ( ! empty( $m['is_self'] ) ? '[bạn] ' : '[thành viên] ' ) . $text;
		}
		return array( 'ok' => true, 'content' => self::fence( 'Nội dung nhóm ' . $index, implode( "\n", $lines ) ), 'error' => '' );
	}

	/**
	 * [2026-09-23 Claude Sonnet 5] PHASE-0.60F OW-4 (doc §6.1 G-10) — the first genuine executor for
	 * `scrape_social_data`; previously this tool had config (0.60E D-E1/D-E3) but no way to actually
	 * run. `character_id` comes from the turn's own claim, never from args (a customer message can
	 * never choose which character's Apify token gets spent).
	 */
	private static function scrape_social_data( array $args, array $claim ): array {
		$character_id = (int) ( $claim['character_id'] ?? 0 );
		$platform     = sanitize_key( (string) ( $args['platform'] ?? '' ) );
		$url          = trim( (string) ( $args['url'] ?? '' ) );
		if ( $character_id <= 0 || '' === $platform || '' === $url ) {
			return array( 'ok' => false, 'content' => '', 'error' => 'invalid_param' );
		}
		if ( ! class_exists( 'BizCity_Bot_Apify_Client' ) ) {
			return array( 'ok' => false, 'content' => '', 'error' => 'apify_unavailable' );
		}
		$result = BizCity_Bot_Apify_Client::scrape( $character_id, $platform, $url );
		if ( is_wp_error( $result ) ) {
			return array( 'ok' => false, 'content' => '', 'error' => (string) $result->get_error_code() );
		}
		$items = (array) ( $result['items'] ?? array() );
		if ( empty( $items ) ) {
			return array( 'ok' => true, 'content' => self::fence( 'Kết quả cào dữ liệu ' . $platform, 'Không có dữ liệu trả về.' ), 'error' => '' );
		}
		$lines = array();
		foreach ( $items as $item ) {
			$lines[] = '- ' . self::summarize_apify_item( $item );
		}
		return array( 'ok' => true, 'content' => self::fence( 'Kết quả cào dữ liệu ' . $platform . ' (' . count( $items ) . ')', implode( "\n", $lines ) ), 'error' => '' );
	}

	/**
	 * [2026-09-23 Claude Sonnet 5] PHASE-0.60F OW-4 (doc §6.1 G-04) — first genuine executor for
	 * `generate_image`. The image endpoint itself (`BizCity_LLM_Client::generate_image()`) has
	 * always been real; what was missing is turning its output into something the bot can actually
	 * SEND. That send path (`BizCity_CRM_Outbound_Dispatcher::dispatch()` with `content_type=image`)
	 * has a real ownership rule this file must respect, not route around:
	 * `dispatch()` REFUSES any attachment whose owner is only the inbox capability
	 * ("Gửi theo capability của inbox không kèm được tệp đính kèm; cần owner cụ thể"). A human
	 * assignee/default assignee is an owner; since PHASE-0.60H a fully-automated Bot Studio
	 * conversation with an active binding gets the powerless `system_owner` instead. This asks the
	 * dispatcher for that owner BEFORE spending on generation — none, no image, an honest
	 * `no_attachment_owner` error the model is told about (see the turn runner's tool-failure
	 * branch), not a silently-wasted generation or a spoofed owner id.
	 */
	private static function generate_image( array $args, array $claim ): array {
		$character_id    = (int) ( $claim['character_id'] ?? 0 );
		$conversation_id = (int) ( $claim['conversation_id'] ?? 0 );
		$prompt          = trim( (string) ( $args['prompt'] ?? $args['query'] ?? '' ) );
		if ( $character_id <= 0 || $conversation_id <= 0 || '' === $prompt ) {
			return array( 'ok' => false, 'content' => '', 'error' => 'invalid_param' );
		}
		$owner_user_id = self::resolve_attachment_owner( $conversation_id );
		if ( $owner_user_id <= 0 ) {
			return array( 'ok' => false, 'content' => '', 'error' => 'no_attachment_owner' );
		}
		if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
			return array( 'ok' => false, 'content' => '', 'error' => 'llm_missing' );
		}
		try {
			$result = BizCity_LLM_Client::instance()->generate_image( mb_substr( $prompt, 0, 800 ) );
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'content' => '', 'error' => 'image_generation_exception' );
		}
		if ( empty( $result['success'] ) ) {
			return array( 'ok' => false, 'content' => '', 'error' => 'image_generation_failed: ' . (string) ( $result['error'] ?? '' ) );
		}
		$attachment_id = self::save_generated_image_as_attachment( $result, $prompt, $owner_user_id );
		if ( $attachment_id <= 0 ) {
			return array( 'ok' => false, 'content' => '', 'error' => 'image_save_failed' );
		}
		return array(
			'ok'                   => true,
			'content'              => self::fence( 'Ảnh vừa tạo', 'Đã tạo một ảnh theo mô tả: "' . mb_substr( $prompt, 0, 200 ) . '". Ảnh sẽ được đính kèm trong tin trả lời — không cần mô tả lại bằng chữ.' ),
			'error'                => '',
			// [OW-4] the ONLY extra key the turn runner reads to decide whether to send an image —
			// see class-bot-turn-runner.php's tool loop and send().
			'image_attachment_id'  => $attachment_id,
		);
	}

	/**
	 * The user id that must be the `post_author` of any file this turn generates for the conversation, or 0
	 * when the dispatcher would refuse media here ("cannot send media", never a fake owner).
	 *
	 * [2026-09-24 Claude Sonnet 5] PHASE-0.60H D-H2 — this used to MIRROR the dispatcher's owner lookup by hand
	 * ("different module, not reuse"), and had already drifted: it took `assignee_id` / `default_assignee_id`
	 * at face value while the dispatcher additionally requires that owner to pass `can_view_conversation()`, so
	 * the tool could pay for an image the dispatcher then rejected. It now asks the dispatcher itself
	 * (`resolve_bot_media_owner()`), which is also where the `system_owner` fallback for fully-automated
	 * conversations (D-H2b: `ai_autoreply` + attachments + an active bot binding) lives. One decision, two callers.
	 * Dispatcher not loaded ⇒ 0: without it nothing could be sent anyway.
	 */
	private static function resolve_attachment_owner( int $conversation_id ): int {
		if ( ! class_exists( 'BizCity_CRM_Outbound_Dispatcher' ) || ! method_exists( 'BizCity_CRM_Outbound_Dispatcher', 'resolve_bot_media_owner' ) ) {
			return 0;
		}
		$anchor = BizCity_CRM_Outbound_Dispatcher::resolve_bot_media_owner( $conversation_id, true );
		return (int) ( $anchor['user_id'] ?? 0 );
	}

	/**
	 * `$result` is BizCity_LLM_Client::generate_image()'s return: either `image_url` (fetch it) or
	 * `b64_json` (decode it). `post_author` MUST equal the resolved owner exactly —
	 * `validate_attachments()` in the dispatcher does a strict `===` ownership check.
	 */
	private static function save_generated_image_as_attachment( array $result, string $prompt, int $owner_user_id ): int {
		$binary = '';
		if ( ! empty( $result['b64_json'] ) ) {
			$binary = base64_decode( (string) $result['b64_json'], true ) ?: '';
		} elseif ( ! empty( $result['image_url'] ) && function_exists( 'wp_remote_get' ) ) {
			$response = wp_remote_get( (string) $result['image_url'], array( 'timeout' => 30 ) );
			if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
				$binary = (string) wp_remote_retrieve_body( $response );
			}
		}
		if ( '' === $binary || ! function_exists( 'wp_upload_bits' ) ) {
			return 0;
		}
		if ( ! function_exists( 'wp_insert_attachment' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}
		$filename = 'bot-image-' . time() . '-' . wp_generate_password( 6, false ) . '.png';
		$upload   = wp_upload_bits( $filename, null, $binary );
		if ( ! empty( $upload['error'] ) ) {
			return 0;
		}
		$filetype   = wp_check_filetype( $upload['file'], null );
		$attachment = array(
			'post_mime_type' => $filetype['type'] ?: 'image/png',
			'post_title'     => sanitize_text_field( mb_substr( $prompt, 0, 120 ) ),
			'post_content'   => '',
			'post_status'    => 'inherit',
			'post_author'    => $owner_user_id,
		);
		$attachment_id = wp_insert_attachment( $attachment, $upload['file'] );
		if ( ! $attachment_id || is_wp_error( $attachment_id ) ) {
			return 0;
		}
		$metadata = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
		wp_update_attachment_metadata( $attachment_id, $metadata );
		return (int) $attachment_id;
	}

	/**
	 * [2026-09-24 Claude Sonnet 5] PHASE-0.60H D-H3 (option A, user-approved) — the model writes the short text to
	 * speak in `args.text`. It is NOT "read the final answer aloud": this tool runs in the planner loop, before the
	 * answer exists (class-bot-turn-runner.php), so it can only speak what the model put in the call.
	 *
	 * Same contract as generate_image(): ids come from the claim, never from args; the attachment owner is checked
	 * BEFORE paying the provider; the file's post_author equals that owner (the dispatcher compares with `===`).
	 * The text reply is always sent first and alone — a voice failure can never cost the customer the answer.
	 */
	private static function text_to_speech( array $args, array $claim ): array {
		$character_id    = (int) ( $claim['character_id'] ?? 0 );
		$conversation_id = (int) ( $claim['conversation_id'] ?? 0 );
		$text            = trim( (string) ( $args['text'] ?? $args['content'] ?? '' ) );
		if ( $character_id <= 0 || $conversation_id <= 0 || '' === $text ) {
			return array( 'ok' => false, 'content' => '', 'error' => 'invalid_param' );
		}
		if ( ! class_exists( 'BizCity_Bot_Media_Client' ) || ! method_exists( 'BizCity_Bot_Media_Client', 'synthesize' ) ) {
			return array( 'ok' => false, 'content' => '', 'error' => 'module_not_loaded' );
		}
		$owner_user_id = self::resolve_attachment_owner( $conversation_id );
		if ( $owner_user_id <= 0 ) {
			return array( 'ok' => false, 'content' => '', 'error' => 'no_attachment_owner' );
		}
		try {
			$audio = BizCity_Bot_Media_Client::synthesize( $character_id, $text );
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'content' => '', 'error' => 'tts_exception' );
		}
		if ( is_wp_error( $audio ) ) {
			return array( 'ok' => false, 'content' => '', 'error' => 'tts_failed: ' . $audio->get_error_code() );
		}
		$attachment_id = self::save_audio_as_attachment( (string) $audio['binary'], $text, $owner_user_id );
		if ( $attachment_id <= 0 ) {
			return array( 'ok' => false, 'content' => '', 'error' => 'tts_save_failed' );
		}
		$spoken = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 200 ) : substr( $text, 0, 200 );
		return array(
			'ok'                  => true,
			'content'             => self::fence( 'Tin thoại vừa tạo', 'Đã tạo một tin thoại đọc đoạn: "' . $spoken . '". Tin thoại sẽ được gửi ngay sau câu trả lời chữ — vẫn viết câu trả lời chữ bình thường.' ),
			'error'               => '',
			'audio_attachment_id' => $attachment_id,
		);
	}

	private static function save_audio_as_attachment( string $binary, string $text, int $owner_user_id ): int {
		if ( '' === $binary || ! function_exists( 'wp_upload_bits' ) ) {
			return 0;
		}
		if ( ! function_exists( 'wp_insert_attachment' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}
		$filename = 'bot-voice-' . time() . '-' . wp_generate_password( 6, false ) . '.mp3';
		$upload   = wp_upload_bits( $filename, null, $binary );
		if ( ! empty( $upload['error'] ) ) {
			return 0;
		}
		$attachment_id = wp_insert_attachment( array(
			'post_mime_type' => 'audio/mpeg',
			'post_title'     => sanitize_text_field( function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 120 ) : substr( $text, 0, 120 ) ),
			'post_content'   => '',
			'post_status'    => 'inherit',
			'post_author'    => $owner_user_id,
		), $upload['file'] );
		return ( ! $attachment_id || is_wp_error( $attachment_id ) ) ? 0 : (int) $attachment_id;
	}

	/** Best-effort common fields across scraper Actors — an Actor with an unrecognised shape still gets a bounded JSON dump instead of an empty line. */
	private static function summarize_apify_item( $item ): string {
		if ( ! is_array( $item ) ) {
			return mb_substr( (string) $item, 0, 300 );
		}
		$text = trim( (string) ( $item['text'] ?? $item['caption'] ?? $item['title'] ?? $item['description'] ?? '' ) );
		if ( '' === $text ) {
			$text = (string) wp_json_encode( array_slice( $item, 0, 4, true ) );
		}
		return mb_substr( $text, 0, 300 );
	}

	const THREAD_INDEX_TTL = 900; // 15 minutes — long enough for one back-and-forth, short enough not to linger.

	private static function remember_threads( int $contact_id, array $refs ): void {
		if ( $contact_id <= 0 ) {
			return;
		}
		set_transient( self::thread_index_key( $contact_id ), $refs, self::THREAD_INDEX_TTL );
	}

	private static function recall_threads( int $contact_id ): array {
		if ( $contact_id <= 0 ) {
			return array();
		}
		$val = get_transient( self::thread_index_key( $contact_id ) );
		return is_array( $val ) ? $val : array();
	}

	private static function thread_index_key( int $contact_id ): string {
		$blog = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		return 'bzbot_threads_' . $blog . '_' . $contact_id;
	}

	/** EA-7.4 — every cross-read logged: who (account), which slot, how many messages. Never the content. */
	private static function log_cross_thread_read( array $claim, string $action, int $index, int $count ): void {
		$event = 'bot_cross_thread_' . $action;
		$ctx   = array(
			'account_id'    => (string) ( $claim['account_id'] ?? '' ),
			'contact_id'    => (int) ( $claim['contact_id'] ?? 0 ),
			'thread_index'  => $index,
			'message_count' => $count,
		);
		if ( is_callable( self::$log_writer ) ) {
			call_user_func( self::$log_writer, $event, $ctx );
			return;
		}
		if ( ! class_exists( 'BizCity_Channel_File_Logger' ) ) {
			return;
		}
		BizCity_Channel_File_Logger::write(
			BizCity_Channel_File_Logger::CH_ZALO_PERSONAL,
			BizCity_Channel_File_Logger::LEVEL_INFO,
			$event,
			'Chủ tài khoản đọc chéo nội dung nhóm khác qua bot.',
			$ctx
		);
	}
}
