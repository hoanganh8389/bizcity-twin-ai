<?php
/**
 * Channel Gateway — Admin Command Classifier
 *
 * Fast-path intent classification using Vietnamese regex patterns.
 * Used by BizCity_CG_Admin_Router to determine what a Zalo admin message
 * intends before creating a scheduler draft event.
 *
 * Intent struct returned:
 * {
 *   type  : 'web_post'|'fb_post'|'reminder_zalo'|'cancel_task'|'list_tasks'|'CHAT',
 *   topic : string (the extracted topic/content, empty for cancel/list),
 *   target: string (who to remind — for reminder_zalo),
 *   when  : string (raw schedule spec — for reminder_zalo),
 *   task_id: int   (for cancel_task),
 * }
 *
 * CHAT type means the message is not a recognized admin command — the caller
 * should fall back to the normal chat pipeline.
 *
 * Patterns (§5.3 ROADMAP-TASK-UNIFY-PHASE.md):
 *   ^(đăng|viết)\s+(bài|post)\s+<topic>    → web_post
 *   ^(post|đăng)\s+(facebook|fb)\s+<topic>  → fb_post
 *   ^nhắc\s+<who>\s+(lúc|vào)\s+<when>     → reminder_zalo
 *   ^(hủy|huỷ)\s+(việc|task)\s+#?<id>      → cancel_task
 *   ^danh\s+sách\s+(việc|task)              → list_tasks
 *   fallback                                 → CHAT
 *
 * No LLM call in this class — pure regex fast-path. LLM fallback is left
 * to future extension (add_filter 'bizcity_cmd_classify_fallback').
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway
 * @since 1.5.0
 */

defined( 'ABSPATH' ) || exit;

class BizCity_CMD_Classifier {

	/**
	 * Classify a raw text message from an admin Zalo user.
	 *
	 * @param  string $text Incoming message (trimmed, original case).
	 * @return array{type:string, topic:string, target:string, when:string, task_id:int}
	 */
	public static function classify( string $text ): array {
		$text = trim( $text );

		$result = self::try_patterns( $text );

		if ( null !== $result ) {
			return $result;
		}

		/**
		 * Filter: allow external LLM fallback classifier.
		 *
		 * Return a non-null array to short-circuit and supply the intent.
		 * Return null to keep default CHAT fallback.
		 *
		 * @param string $text Original message text.
		 */
		$external = apply_filters( 'bizcity_cmd_classify_fallback', null, $text );
		if ( is_array( $external ) && ! empty( $external['type'] ) ) {
			return self::normalize_intent( $external );
		}

		return self::make( 'CHAT' );
	}

	/* ═══════════════════════════════════════════════════════════
	 *  Pattern matching
	 * ═══════════════════════════════════════════════════════════ */

	/**
	 * Run all regex patterns. Returns intent array on first match, null if none.
	 */
	private static function try_patterns( string $text ): ?array {
		// web_post: "đăng bài X", "viết bài X", "đăng post X", "viết post X"
		if ( preg_match( '/^(đăng|viết)\s+(bài|post)\s+(?P<topic>.+)/iu', $text, $m ) ) {
			return self::make( 'web_post', trim( $m['topic'] ) );
		}

		// fb_post: "post facebook X", "đăng facebook X", "post fb X", "đăng fb X"
		if ( preg_match( '/^(post|đăng)\s+(facebook|fb)\s+(?P<topic>.+)/iu', $text, $m ) ) {
			return self::make( 'fb_post', trim( $m['topic'] ) );
		}

		// reminder_zalo: "nhắc <who> lúc/vào <when>"
		// E.g.: "nhắc chị Lan lúc 8 giờ sáng mai", "nhắc tất cả vào 15:00"
		if ( preg_match( '/^nhắc\s+(?P<who>.+?)\s+(lúc|vào)\s+(?P<when>.+)/iu', $text, $m ) ) {
			return self::make( 'reminder_zalo', '', trim( $m['who'] ), trim( $m['when'] ) );
		}

		// cancel_task: "hủy việc #123", "huỷ task 456"
		if ( preg_match( '/^(hủy|huỷ)\s+(việc|task)\s+#?(?P<id>\d+)/iu', $text, $m ) ) {
			return self::make( 'cancel_task', '', '', '', (int) $m['id'] );
		}

		// list_tasks: "danh sách việc", "danh sách task"
		if ( preg_match( '/^danh\s+sách\s+(việc|task)/iu', $text ) ) {
			return self::make( 'list_tasks' );
		}

		return null;
	}

	/* ═══════════════════════════════════════════════════════════
	 *  Helpers
	 * ═══════════════════════════════════════════════════════════ */

	/**
	 * Build a canonical intent array.
	 */
	private static function make(
		string $type,
		string $topic   = '',
		string $target  = '',
		string $when    = '',
		int    $task_id = 0
	): array {
		return compact( 'type', 'topic', 'target', 'when', 'task_id' );
	}

	/**
	 * Normalize an external intent array to the canonical shape.
	 */
	private static function normalize_intent( array $raw ): array {
		return [
			'type'    => (string) ( $raw['type']    ?? 'CHAT' ),
			'topic'   => (string) ( $raw['topic']   ?? '' ),
			'target'  => (string) ( $raw['target']  ?? '' ),
			'when'    => (string) ( $raw['when']    ?? '' ),
			'task_id' => (int)    ( $raw['task_id'] ?? 0 ),
		];
	}
}
