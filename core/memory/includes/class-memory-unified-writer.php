<?php
/**
 * BizCity Memory — Unified Mirror Writer (Wave 2.8d TBR.MEM-D5).
 *
 * Dual-write helper: mirror writes from legacy 5 tables into unified
 * `bizcity_memory` table while we transition (D5 → D6 → D7).
 *
 * Listens on action `bizcity_memory_mirror_write` emitted by legacy writers:
 *
 *   do_action( 'bizcity_memory_mirror_write', $class, $row, $result );
 *
 *   $class  = 'user' | 'episodic' | 'rolling' | 'session' | 'note'
 *   $row    = canonical fields cho row legacy vừa ghi (assoc array)
 *   $result = 'insert' | 'update' | int row id | bool
 *
 * Hook is a NO-OP unless filter `bizcity_memory_unified_enabled` returns TRUE.
 * Failures NEVER throw — caught + error_log only.
 *
 * Schema reference: core/memory/PHASE-MEMORY-CONSOLIDATION.md §2.1
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Memory
 * @since      Wave 2.8d (2026-05-24)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Memory_Unified_Writer {

	/** @var self|null */
	private static $instance = null;

	/** Dedupe seen unique-keys within one request to avoid pingpong. */
	private $seen = [];

	public static function instance(): self {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'bizcity_memory_mirror_write', [ $this, 'on_mirror_write' ], 20, 3 );
	}

	/**
	 * Main listener — dispatch per class.
	 *
	 * @param string             $class  'user'|'episodic'|'rolling'|'session'|'note'
	 * @param array              $row    Legacy row data (assoc).
	 * @param string|int|bool    $result Legacy writer result for context.
	 */
	public function on_mirror_write( $class, $row, $result = null ): void {
		if ( ! BizCity_Memory_Unified_Installer::is_enabled() ) {
			return;
		}
		if ( ! is_array( $row ) || ! is_string( $class ) || $class === '' ) {
			return;
		}
		// Allow filter to skip / override per call.
		$row = apply_filters( 'bizcity_memory_mirror_row', $row, $class, $result );
		if ( ! is_array( $row ) || empty( $row ) ) {
			return;
		}

		try {
			switch ( $class ) {
				case 'user':     $this->mirror_user( $row );     break;
				case 'episodic': $this->mirror_episodic( $row ); break;
				case 'rolling':  $this->mirror_rolling( $row );  break;
				case 'session':  $this->mirror_session( $row );  break;
				case 'note':     $this->mirror_note( $row );     break;
				default:
					// Unknown class — ignore silently.
					return;
			}
		} catch ( \Throwable $e ) {
			error_log( '[BizCity_Memory_Unified_Writer] mirror ' . $class . ' failed: ' . $e->getMessage() );
		}
	}

	/* ─── Class-specific mirrors ────────────────────────────────── */

	private function mirror_user( array $row ): void {
		$this->upsert_unified( [
			'memory_class' => 'user',
			'legacy_id'    => (int) ( $row['id'] ?? 0 ),
			'blog_id'      => (int) ( $row['blog_id']    ?? get_current_blog_id() ),
			'user_id'      => (int) ( $row['user_id']    ?? 0 ),
			'session_id'   => (string) ( $row['session_id'] ?? '' ),
			'memory_tier'  => (string) ( $row['memory_tier'] ?? 'extracted' ),
			'memory_type'  => (string) ( $row['memory_type'] ?? 'fact' ),
			'memory_key'   => (string) ( $row['memory_key']  ?? '' ),
			'memory_text'  => (string) ( $row['memory_text'] ?? '' ),
			'score'        => (int) ( $row['score']      ?? 50 ),
			'source_log_ids' => (string) ( $row['source_log_ids'] ?? '' ),
			'metadata'     => (string) ( $row['metadata'] ?? '' ),
		] );
	}

	private function mirror_episodic( array $row ): void {
		$key = (string) ( $row['event_key'] ?? '' );
		if ( $key === '' ) return;
		$this->upsert_unified( [
			'memory_class'      => 'episodic',
			'legacy_id'         => (int) ( $row['id'] ?? 0 ),
			'blog_id'           => (int) ( $row['blog_id']    ?? get_current_blog_id() ),
			'user_id'           => (int) ( $row['user_id']    ?? 0 ),
			'session_id'        => (string) ( $row['session_id'] ?? '' ),
			'conversation_id'   => (string) ( $row['source_conversation_id'] ?? '' ),
			'memory_type'       => (string) ( $row['event_type'] ?? 'fact' ),
			'event_type'        => (string) ( $row['event_type'] ?? 'fact' ),
			'memory_key'        => 'ep:' . $key,
			'memory_text'       => (string) ( $row['event_text'] ?? '' ),
			'score'             => (int) ( $row['importance'] ?? 50 ),
			'importance'        => (int) ( $row['importance'] ?? 50 ),
			'goal'              => (string) ( $row['source_goal'] ?? '' ),
			'metadata'          => (string) ( $row['metadata'] ?? '' ),
		] );
	}

	private function mirror_rolling( array $row ): void {
		$conv = (string) ( $row['conversation_id'] ?? '' );
		if ( $conv === '' ) return;
		$this->upsert_unified( [
			'memory_class'           => 'rolling',
			'legacy_id'              => (int) ( $row['id'] ?? 0 ),
			'blog_id'                => (int) ( $row['blog_id'] ?? get_current_blog_id() ),
			'user_id'                => (int) ( $row['user_id'] ?? 0 ),
			'session_id'             => (string) ( $row['session_id'] ?? '' ),
			'conversation_id'        => $conv,
			'memory_type'            => 'rolling',
			'memory_key'             => 'rl:' . $conv,
			'memory_text'            => (string) ( $row['window_summary'] ?? '' ),
			'goal'                   => (string) ( $row['goal'] ?? '' ),
			'goal_label'             => (string) ( $row['goal_label'] ?? '' ),
			'window_summary'         => (string) ( $row['window_summary'] ?? '' ),
			'window_turn_count'      => (int) ( $row['window_turn_count'] ?? 0 ),
			'user_goal_score'        => (int) ( $row['user_goal_score'] ?? 0 ),
			'bot_satisfaction_score' => (int) ( $row['bot_satisfaction_score'] ?? 0 ),
			'status'                 => (string) ( $row['status'] ?? 'active' ),
			'score'                  => 60,
		] );
	}

	private function mirror_session( array $row ): void {
		$key = (string) ( $row['memory_key'] ?? '' );
		if ( $key === '' ) return;
		$this->upsert_unified( [
			'memory_class' => 'session',
			'legacy_id'    => (int) ( $row['id'] ?? 0 ),
			'blog_id'      => (int) ( $row['blog_id']    ?? get_current_blog_id() ),
			'user_id'      => (int) ( $row['user_id']    ?? 0 ),
			'session_id'   => (string) ( $row['session_id'] ?? '' ),
			'memory_type'  => (string) ( $row['memory_type'] ?? 'fact' ),
			'memory_key'   => 'ws:' . $key,
			'memory_text'  => (string) ( $row['memory_text'] ?? '' ),
			'score'        => (int) ( $row['score'] ?? 50 ),
			'metadata'     => (string) ( $row['metadata'] ?? '' ),
		] );
	}

	private function mirror_note( array $row ): void {
		$id = (int) ( $row['id'] ?? 0 );
		$pid = (string) ( $row['project_id'] ?? '' );
		if ( $id <= 0 && $pid === '' ) return;
		$key = $id > 0 ? ( 'nt:' . $pid . ':' . $id ) : ( 'nt:' . $pid . ':' . md5( (string) ( $row['title'] ?? '' ) ) );
		$this->upsert_unified( [
			'memory_class' => 'note',
			'legacy_id'    => $id,
			'blog_id'      => (int) ( $row['blog_id'] ?? get_current_blog_id() ),
			'user_id'      => (int) ( $row['user_id'] ?? 0 ),
			'session_id'   => (string) ( $row['session_id'] ?? '' ),
			'memory_type'  => (string) ( $row['note_type'] ?? 'manual' ),
			'memory_tier'  => 'manual',
			'memory_key'   => $key,
			'memory_text'  => (string) ( $row['title'] ?? '' )
				. ( ! empty( $row['content'] ) ? "\n\n" . (string) $row['content'] : '' ),
			'score'        => ! empty( $row['is_starred'] ) ? 80 : 50,
			'metadata'     => (string) ( $row['metadata'] ?? '' ),
		] );
	}

	/* ─── Generic upsert into unified table ─────────────────────── */

	private function upsert_unified( array $data ): void {
		global $wpdb;
		$table = BizCity_Memory_Unified_Installer::table();

		// Required keys.
		$class = (string) ( $data['memory_class'] ?? '' );
		$key   = (string) ( $data['memory_key']   ?? '' );
		if ( $class === '' || $key === '' ) return;

		$blog_id    = (int) ( $data['blog_id']    ?? get_current_blog_id() );
		$user_id    = (int) ( $data['user_id']    ?? 0 );
		$session_id = (string) ( $data['session_id'] ?? '' );

		$dedupe_key = $blog_id . '|' . $user_id . '|' . $session_id . '|' . $class . '|' . $key;
		if ( isset( $this->seen[ $dedupe_key ] ) ) return;
		$this->seen[ $dedupe_key ] = true;

		$now = current_time( 'mysql' );

		// Build column data — only include keys that exist.
		$cols = [
			'blog_id'         => $blog_id,
			'user_id'         => $user_id,
			'session_id'      => $session_id,
			'conversation_id' => (string) ( $data['conversation_id'] ?? '' ),
			'notebook_id'     => (int) ( $data['notebook_id'] ?? 0 ),
			'memory_class'    => $class,
			'legacy_id'       => (int) ( $data['legacy_id'] ?? 0 ),
			'memory_tier'     => (string) ( $data['memory_tier'] ?? 'explicit' ),
			'memory_type'     => (string) ( $data['memory_type'] ?? 'fact' ),
			'memory_key'      => $key,
			'memory_text'     => (string) ( $data['memory_text'] ?? '' ),
			'event_type'      => isset( $data['event_type'] ) ? (string) $data['event_type'] : null,
			'importance'      => (int) ( $data['importance'] ?? 0 ),
			'goal'            => isset( $data['goal'] ) ? (string) $data['goal'] : null,
			'goal_label'      => isset( $data['goal_label'] ) ? (string) $data['goal_label'] : null,
			'window_summary'  => isset( $data['window_summary'] ) ? (string) $data['window_summary'] : null,
			'window_turn_count'      => (int) ( $data['window_turn_count'] ?? 0 ),
			'user_goal_score'        => (int) ( $data['user_goal_score'] ?? 0 ),
			'bot_satisfaction_score' => (int) ( $data['bot_satisfaction_score'] ?? 0 ),
			'status'                 => (string) ( $data['status'] ?? 'active' ),
			'score'                  => (int) ( $data['score'] ?? 50 ),
			'source_log_ids'         => (string) ( $data['source_log_ids'] ?? '' ),
			'metadata'               => (string) ( $data['metadata'] ?? '' ),
			'last_seen'              => $now,
			'updated_at'             => $now,
		];

		// Check existing via unique key.
		$exists_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE blog_id=%d AND user_id=%d AND session_id=%s AND memory_class=%s AND memory_key=%s LIMIT 1",
			$blog_id, $user_id, $session_id, $class, $key
		) );

		if ( $exists_id > 0 ) {
			$update = [
				'memory_text'      => $cols['memory_text'],
				'memory_tier'      => $cols['memory_tier'],
				'memory_type'      => $cols['memory_type'],
				'event_type'       => $cols['event_type'],
				'importance'       => $cols['importance'],
				'goal'             => $cols['goal'],
				'goal_label'       => $cols['goal_label'],
				'window_summary'   => $cols['window_summary'],
				'window_turn_count'      => $cols['window_turn_count'],
				'user_goal_score'        => $cols['user_goal_score'],
				'bot_satisfaction_score' => $cols['bot_satisfaction_score'],
				'status'                 => $cols['status'],
				'score'                  => $cols['score'],
				'source_log_ids'         => $cols['source_log_ids'],
				'metadata'               => $cols['metadata'],
				'last_seen'              => $now,
				'updated_at'             => $now,
			];
			// Preserve legacy_id only if we now know it (don't overwrite with 0).
			if ( (int) $cols['legacy_id'] > 0 ) {
				$update['legacy_id'] = (int) $cols['legacy_id'];
			}
			$wpdb->update( $table, $update, [ 'id' => $exists_id ] );
			// Bump times_seen.
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET times_seen = times_seen + 1 WHERE id = %d", $exists_id ) );
			return;
		}

		$cols['times_seen'] = 1;
		$cols['created_at'] = $now;
		$wpdb->insert( $table, $cols );
	}
}
