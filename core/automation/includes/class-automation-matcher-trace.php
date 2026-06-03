<?php
/**
 * Matcher trace ring buffer (option-based).
 *
 * Diagnostic helper — ghi log mọi quyết định của trigger-matcher để debug
 * scenario "nhắn tin thật vào kênh nhưng workflow không phản hồi".
 *
 * Storage: option `bizcity_automation_matcher_trace` (autoload=no), keep last
 * MAX entries. Mỗi entry là 1 mảng phẳng:
 *   { ts, platform, chat_id, text, trigger_type, decision, detail }
 *
 * decision ∈
 *   - 'enter'              — matcher entered for this inbound
 *   - 'resume_pending'     — pinned workflow_id from pending_state, run that
 *   - 'media_stash'        — image-first (Logic 1) → stash + ask purpose
 *   - 'matched_keyword'    — N workflow(s) matched filter, fired
 *   - 'fallback_fired'     — no keyword match, ran is_fallback workflows
 *   - 'default_reply'      — no match + no fallback → TwinBrain MPR safety net
 *   - 'silent'             — default_reply disabled by filter
 *   - 'rejected_role'      — channel_role=ASSISTANT loop guard
 *   - 'no_trigger_type'    — couldn't map platform → trigger_type
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Automation
 * @since      AUTOMATION PG-S9-fix (2026-05-31)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Automation_Matcher_Trace {

	const OPTION = 'bizcity_automation_matcher_trace';
	const MAX    = 80;

	/**
	 * Append 1 trace entry to the ring buffer.
	 *
	 * @param string $decision Bucket from header doc above.
	 * @param array  $context  Free-form payload — sẽ được trim string >300 chars.
	 */
	public static function note( string $decision, array $context = array() ): void {
		$entry = array(
			'ts'           => time(),
			'ts_iso'       => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'decision'     => $decision,
			'platform'     => (string) ( $context['platform']     ?? '' ),
			'chat_id'      => (string) ( $context['chat_id']      ?? '' ),
			'text'         => self::trim_str( (string) ( $context['text'] ?? '' ), 200 ),
			'media_url'    => self::trim_str( (string) ( $context['media_url'] ?? '' ), 200 ),
			'trigger_type' => (string) ( $context['trigger_type'] ?? '' ),
			'wf_id'        => (int)    ( $context['wf_id']        ?? 0 ),
			'detail'       => self::trim_str( (string) ( $context['detail']    ?? '' ), 300 ),
		);

		$buf = (array) get_option( self::OPTION, array() );
		if ( ! isset( $buf[0] ) || ! is_array( $buf[0] ) ) {
			// Defensive: option got corrupted (e.g. legacy format).
			$buf = array();
		}
		$buf[] = $entry;
		if ( count( $buf ) > self::MAX ) {
			$buf = array_slice( $buf, -self::MAX );
		}
		update_option( self::OPTION, $buf, false );

		// PG-S9-fix v6 — mirror into per-workflow JSONL when a workflow_id is
		// in scope (resume / matched / fallback). Decisions without wf_id
		// (no_trigger_type, rejected_role, dedup_skip, enter, intake,
		// media_stash) stay in the global ring buffer only.
		$wfid = (int) ( $context['wf_id'] ?? 0 );
		if ( $wfid > 0 && class_exists( 'BizCity_Automation_File_Logger' ) ) {
			BizCity_Automation_File_Logger::note_decision( $wfid, 'matcher.' . $decision, array(
				'platform'     => $entry['platform'],
				'chat_id'      => $entry['chat_id'],
				'text'         => $entry['text'],
				'trigger_type' => $entry['trigger_type'],
				'detail'       => $entry['detail'],
			) );
		}
	}

	/**
	 * Return entries newest-first.
	 *
	 * @param int $limit Max rows (default 50, hard cap 80).
	 */
	public static function recent( int $limit = 50 ): array {
		$buf = (array) get_option( self::OPTION, array() );
		$buf = array_filter( $buf, 'is_array' );
		$buf = array_reverse( $buf );
		return array_slice( $buf, 0, max( 1, min( self::MAX, $limit ) ) );
	}

	public static function clear(): void {
		delete_option( self::OPTION );
	}

	private static function trim_str( string $s, int $max ): string {
		if ( strlen( $s ) <= $max ) { return $s; }
		return substr( $s, 0, $max ) . '…';
	}
}
