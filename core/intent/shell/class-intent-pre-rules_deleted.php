<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Intent\Shell
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 *
 * Phase 0.16 / Vòng 4 — Task 4.16.2
 * Pre-rules layer for the Intent Shell.
 *
 * Two responsibilities, both pure and synchronous (NO LLM):
 *
 *   1. `try_match($params, $conversation)` — if the message is a slash
 *      command / cancel / approval / rejection / retry, return a fully-formed
 *      legacy-shaped response and SKIP the runner entirely. Returns null
 *      otherwise. Target latency: <50ms.
 *
 *   2. `detect_intent_kind($params)` — fast regex hint for the triage agent.
 *      Returns one of {creative, task, chat} or null when ambiguous (the
 *      triage agent will then decide based on context). Target hit-rate ≥70%
 *      to reduce LLM load on the triage step.
 *
 * Sprint 1 NOTE: dispatch helpers (cancel, approval) currently return null
 * so the runner sees them — full parity with legacy `BizCity_Pre_Rules` is
 * scheduled for Sprint 2 once `Intent_Session_Adapter` lands.
 *
 * @since 4.0.0 (Vòng 4)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Intent_Pre_Rules {

	/** @var BizCity_Intent_Session_Adapter|null */
	private $session_adapter = null;

	public function set_session_adapter( $adapter ): void {
		$this->session_adapter = $adapter;
	}

	/**
	 * Try to short-circuit the request with a deterministic local response.
	 *
	 * @param array $params       Original Intent_Engine::process() params.
	 * @param array $conversation Active conversation row (may be empty array).
	 * @return array|null  Legacy-shaped response, or null to fall through.
	 */
	public function try_match( array $params, array $conversation = [] ): ?array {
		$msg = trim( (string) ( $params['message'] ?? '' ) );
		if ( $msg === '' ) {
			return null;
		}

		$lower = mb_strtolower( $msg, 'UTF-8' );

		// 1. Help command — local, deterministic.
		if ( preg_match( '#^/?(help|trợ giúp|hướng dẫn)$#u', $lower ) ) {
			return $this->help_response();
		}

		// 2. Cancel / abort — dispatch to Session_Adapter (Sprint 3).
		if ( preg_match( '#^/?(cancel|hủy|dừng|stop|bỏ)\b#u', $lower ) ) {
			if ( $this->session_adapter ) {
				$ok = $this->session_adapter->cancel_active( $params );
				return [
					'reply'  => $ok ? '✅ Đã hủy yêu cầu đang chạy.' : '⚠️ Không tìm thấy yêu cầu nào để hủy.',
					'action' => 'reply',
					'meta'   => [ 'rule' => 'cancel', 'cancelled' => $ok ],
				];
			}
			return null;
		}

		// 3. Approval — handed back to runner (HIL inbox handles in Twin_Runner).
		//    The runner detects the user's "ok" as a HIL response and resumes
		//    the paused run; no pre-rules action needed beyond pass-through.
		if ( preg_match( '#^(ok|yes|có|đồng ý|được|xác nhận|approve|chạy đi|làm đi|go|tiếp tục|chấp nhận)\b#u', $lower ) ) {
			return null;
		}

		// 4. Rejection — same pass-through; HIL handles via runner.
		if ( preg_match( '#^(no|không|reject|từ chối|thôi)\b#u', $lower ) ) {
			return null;
		}

		// 5. Retry — pass through so the agent re-asks. Runner handles re-plan.
		if ( preg_match( '#^(retry|thử lại|làm lại)\b#u', $lower ) ) {
			return null;
		}

		return null;
	}

	/**
	 * Hybrid intent_kind detection. Cheaper than triage LLM call.
	 *
	 * @param array $params
	 * @return string|null  One of {creative, task, chat} or null = ambiguous.
	 */
	public function detect_intent_kind( array $params ): ?string {
		$msg = (string) ( $params['message'] ?? '' );
		if ( $msg === '' ) {
			return null;
		}

		// Creative: viết bài, soạn, draft, generate content
		if ( preg_match( '#(viết|soạn|draft|generate|create\s+(post|article|content)|làm\s+content)#iu', $msg ) ) {
			return 'creative';
		}

		// Task: explicit action verb + object (channel / asset)
		if ( preg_match( '#(đăng|publish|gửi|send|upload|export|tạo\s+(zalo|facebook|email|fanpage))#iu', $msg ) ) {
			return 'task';
		}

		// Sprint 5 — Chat: question / explanation / emotion / chitchat / ack.
		// Expanded to catch the 70%+ of webchat traffic that's plain conversation,
		// so Intent_Shell can fast-path skip the triage LLM call.
		if ( preg_match( '#(là\s+gì|là\s+ai|tại\s+sao|vì\s+sao|như\s+thế\s+nào|why|what|how|where|when|cảm\s+thấy|buồn|vui|stress|lo|mệt)#iu', $msg ) ) {
			return 'chat';
		}

		// Ack / chitchat: short reactive messages ("ok bro", "cảm ơn", "thanks", "cố lên", greetings).
		if ( preg_match( '#^(ok|oki|okay|ừ|um|ờ|vâng|dạ|được|cảm\s*ơn|thanks?|thank\s+you|cố\s+lên|tuyệt|hay\s+quá|wow|nice|good\s+job|hi|hello|chào|xin\s+chào|alo|bro|bạn)\b#iu', trim( $msg ) ) ) {
			return 'chat';
		}

		// Heuristic fallback: short message ≤ 10 words and no action verb
		// → almost certainly chitchat / opinion / Q&A.
		$words = preg_split( '/\s+/u', trim( $msg ) ) ?: [];
		if ( count( $words ) <= 10 && ! preg_match( '#(\?|đăng|gửi|tạo|xoá|sửa|xuất|publish|delete|update|create|send|export)#iu', $msg ) ) {
			return 'chat';
		}

		return null; // ambiguous — let triage agent decide
	}

	/* ------------------------------------------------------------------ */

	private function help_response(): array {
		$reply = "Mình có thể giúp bạn:\n"
			. "• Hỏi đáp / brainstorm (chỉ cần gõ câu hỏi)\n"
			. "• Soạn bài Facebook / blog / headline (\"viết bài về …\")\n"
			. "• Vẽ mindmap (\"vẽ sơ đồ tư duy về …\")\n"
			. "• Tạo prompt ảnh (\"tạo ảnh …\")\n"
			. "Gõ /cancel để hủy lệnh đang chạy.";

		return [
			'reply'  => $reply,
			'action' => 'reply',
			'meta'   => [ 'source' => 'pre_rules', 'rule' => 'help' ],
		];
	}
}
