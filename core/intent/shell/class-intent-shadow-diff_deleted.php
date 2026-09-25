<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Intent\Shell
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 *
 * Phase 0.16 / Vòng 4 — Task 4.16.9 (logger half)
 * Async logger that records {legacy_response, shell_response} pairs into
 * `bizcity_intent_shadow_diff` so a daily cron can compute parity.
 *
 * Sprint 1 — write path only. The diff scoring (`compute_match_score`) is a
 * conservative placeholder that compares action types + content equality.
 * Sprint 2 will swap it for the cosine-similarity scorer described in
 * PHASE-0.16 §10 Q3.
 *
 * @since 4.0.0 (Vòng 4)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Intent_Shadow_Diff {

	/** Persist a comparison row. Safe to call from a wp_cron callback. */
	public function log( array $params, array $legacy_resp, array $shell_resp, array $meta = [] ): void {
		global $wpdb;

		$table = BizCity_Intent_Shadow_Diff_Installer::table_name();
		$msg   = (string) ( $params['message'] ?? '' );
		$score = $this->compute_match_score( $legacy_resp, $shell_resp );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$table,
			[
				'user_id'       => (int) ( $params['user_id'] ?? 0 ),
				'channel'       => (string) ( $params['channel'] ?? '' ),
				'message_hash'  => sha1( $msg ),
				'message'       => mb_substr( $msg, 0, 1000 ),
				'legacy_action' => (string) ( $legacy_resp['action'] ?? '' ),
				'shell_action'  => (string) ( $shell_resp['action']  ?? '' ),
				'legacy_resp'   => wp_json_encode( $legacy_resp ),
				'shell_resp'    => wp_json_encode( $shell_resp ),
				'match_score'   => $score,
				'diff_summary'  => $this->summarise_diff( $legacy_resp, $shell_resp ),
				'shell_run_id'  => (string) ( $shell_resp['run_id'] ?? '' ),
				'legacy_ms'     => (int) ( $meta['legacy_ms'] ?? 0 ),
				'shell_ms'      => (int) ( $meta['shell_ms']  ?? 0 ),
				'created_at'    => current_time( 'mysql' ),
			],
			[ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%s' ]
		);
	}

	/** Schedule a diff write on the next cron tick (non-blocking caller). */
	public function log_async( array $params, array $legacy_resp, array $shell_resp, array $meta = [] ): void {
		wp_schedule_single_event(
			time(),
			'bizcity_intent_shadow_diff_write',
			[ $params, $legacy_resp, $shell_resp, $meta ]
		);
	}

	/**
	 * Parity score 0..100. Sprint 4 heuristic with action-equivalence groups
	 * to handle Phase 1.11 Engine_Shell semantics:
	 *
	 *   - Engine_Shell sets `action=knowledge|multi|passthrough` then defers
	 *     reply composition to chat-gateway downstream → $legacy.reply is ''.
	 *   - Intent_Shell sets `action=reply` and embeds the full reply text.
	 *
	 * So we compare actions by EQUIVALENCE GROUP, not exact string, and
	 * fall back to a partial score when one side has empty reply.
	 *
	 *   - same equivalence group → +30
	 *   - both replies present + Jaccard token sim   → +70 * jaccard
	 *   - exact reply match                          → +70 (replaces Jaccard)
	 *   - one reply empty + same group               → +20 (best we can do)
	 */
	public function compute_match_score( array $a, array $b ): int {
		$score = 0;

		$grp_a = $this->action_group( (string) ( $a['action'] ?? '' ) );
		$grp_b = $this->action_group( (string) ( $b['action'] ?? '' ) );
		if ( $grp_a !== '' && $grp_a === $grp_b ) {
			$score += 30;
		}

		$txt_a = trim( (string) ( $a['reply'] ?? $a['content'] ?? '' ) );
		$txt_b = trim( (string) ( $b['reply'] ?? $b['content'] ?? '' ) );

		if ( $txt_a === '' && $txt_b === '' ) {
			return min( 100, $score );
		}

		if ( $txt_a !== '' && $txt_a === $txt_b ) {
			return min( 100, $score + 70 );
		}

		if ( $txt_a !== '' && $txt_b !== '' ) {
			$jaccard = $this->jaccard_tokens( $txt_a, $txt_b );
			$score += (int) round( 70 * $jaccard );
		} elseif ( $grp_a !== '' && $grp_a === $grp_b ) {
			// One side has reply, the other doesn't, but actions agree
			// semantically (e.g. legacy=knowledge, shell=reply). Award a
			// partial credit so dashboard isn't all-zero.
			$score += 20;
		}

		return min( 100, $score );
	}

	/**
	 * Map action vocabulary onto equivalence groups so Phase 1.11 actions
	 * (`knowledge`, `multi`, `passthrough`, `compose_answer`) align with
	 * Intent_Shell's `reply`. Both mean "AI answers in natural language".
	 */
	private function action_group( string $action ): string {
		$action = strtolower( trim( $action ) );
		if ( $action === '' ) { return ''; }

		$chat_like = [ 'reply', 'knowledge', 'multi', 'passthrough', 'compose_answer', 'chat', 'answer' ];
		if ( in_array( $action, $chat_like, true ) ) {
			return 'chat';
		}
		$tool_like = [ 'single', 'call_tool', 'execute_pipeline', 'execution', 'tool' ];
		if ( in_array( $action, $tool_like, true ) ) {
			return 'tool';
		}
		$ask_like = [ 'ask_user', 'ask', 'clarify', 'confirm' ];
		if ( in_array( $action, $ask_like, true ) ) {
			return 'ask';
		}
		return $action; // fallback: keep raw for any new action keyword
	}

	/**
	 * Jaccard similarity over normalized word tokens. Vietnamese-friendly:
	 * lowercase + strip punctuation; we don't strip diacritics so "đặt" and
	 * "đặt" stay equal but "dat" stays different (intentional — semantically
	 * different phrasings shouldn't score 100).
	 */
	private function jaccard_tokens( string $a, string $b ): float {
		$tok_a = $this->tokens( $a );
		$tok_b = $this->tokens( $b );
		if ( ! $tok_a || ! $tok_b ) {
			return 0.0;
		}
		$set_a = array_flip( $tok_a );
		$set_b = array_flip( $tok_b );
		$inter = count( array_intersect_key( $set_a, $set_b ) );
		$union = count( $set_a + $set_b );
		return $union > 0 ? $inter / $union : 0.0;
	}

	private function tokens( string $s ): array {
		$s = mb_strtolower( $s, 'UTF-8' );
		// Replace punctuation with spaces, keep Unicode letters/digits.
		$s = preg_replace( '/[^\p{L}\p{N}\s]+/u', ' ', $s );
		$parts = preg_split( '/\s+/u', trim( (string) $s ) );
		return array_values( array_filter( (array) $parts, static function ( $t ) {
			return $t !== '' && mb_strlen( $t ) > 1;
		} ) );
	}

	private function summarise_diff( array $a, array $b ): string {
		$diffs = [];
		$act_a = (string) ( $a['action'] ?? '' );
		$act_b = (string) ( $b['action'] ?? '' );
		$grp_a = $this->action_group( $act_a );
		$grp_b = $this->action_group( $act_b );
		if ( $act_a !== $act_b ) {
			$same_group = ( $grp_a !== '' && $grp_a === $grp_b );
			$diffs[] = sprintf(
				'action: %s → %s%s',
				$act_a !== '' ? $act_a : '∅',
				$act_b !== '' ? $act_b : '∅',
				$same_group ? ' (same group: ' . $grp_a . ')' : ''
			);
		}
		if ( ! empty( $a['interruptions'] ) xor ! empty( $b['interruptions'] ) ) {
			$diffs[] = 'interruptions presence diverges';
		}
		// Note when reply length is wildly different (potential downstream-composed reply).
		$len_a = mb_strlen( trim( (string) ( $a['reply'] ?? '' ) ) );
		$len_b = mb_strlen( trim( (string) ( $b['reply'] ?? '' ) ) );
		if ( $len_a === 0 && $len_b > 0 ) {
			$diffs[] = 'legacy reply empty (downstream-composed?)';
		} elseif ( $len_b === 0 && $len_a > 0 ) {
			$diffs[] = 'shell reply empty';
		}
		return implode( '; ', $diffs );
	}
}

// Cron worker for log_async().
add_action( 'bizcity_intent_shadow_diff_write', static function ( $params, $legacy, $shell, $meta ) {
	if ( ! class_exists( 'BizCity_Intent_Shadow_Diff' ) ) {
		return;
	}
	( new BizCity_Intent_Shadow_Diff() )->log(
		(array) $params,
		(array) $legacy,
		(array) $shell,
		(array) $meta
	);
}, 10, 4 );
