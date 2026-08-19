<?php
/**
 * TwinWeb thread registry foundation.
 *
 * Normalizes thread rows so Twin GPT and TwinChat can converge on one
 * conversation registry contract without moving storage ownership yet.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Modules\TwinWeb
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_TwinWeb_Thread_Registry', false ) ) {
	return;
}

final class BizCity_TwinWeb_Thread_Registry {

	const SPEC_SCHEMA = 'bizcity.twin.thread_spec.v1';

	/**
	 * Normalize a TwinWeb thread row into the shared registry DTO.
	 *
	 * @param object $row  DB row from bizcity_twinweb_threads.
	 * @param array  $meta Decoded meta_json.
	 * @return array
	 */
	public static function normalize_twinweb_row( $row, array $meta = array() ) {
		// [2026-07-19 Johnny Chu] PHASE-TWINWEB-THREADS — stable cross-surface thread registry DTO for TwinWeb first, TwinChat adapter next.
		$spec = isset( $meta['thread_spec'] ) && is_array( $meta['thread_spec'] ) ? $meta['thread_spec'] : array();
		$goal_link = isset( $meta['goal_link'] ) && is_array( $meta['goal_link'] ) ? $meta['goal_link'] : array();
		return array(
			'schema'      => self::SPEC_SCHEMA,
			'surface'     => 'twinweb',
			'thread_id'   => isset( $row->id ) ? (int) $row->id : 0,
			'session_id'  => isset( $spec['session_id'] ) ? sanitize_text_field( (string) $spec['session_id'] ) : ( isset( $row->id ) ? (string) (int) $row->id : '' ),
			'user_id'     => isset( $row->user_id ) ? (int) $row->user_id : 0,
			'app_type'    => isset( $row->app_type ) ? sanitize_key( (string) $row->app_type ) : 'chat',
			// [2026-07-19 Johnny Chu] PHASE-TWINWEB-THREADS — prefer persisted spec value when registry receives pre-refresh row data.
			'project_id'  => isset( $spec['project_id'] ) ? sanitize_key( (string) $spec['project_id'] ) : ( isset( $row->project_id ) ? sanitize_key( (string) $row->project_id ) : '' ),
			'mode'        => isset( $spec['mode'] ) ? sanitize_key( (string) $spec['mode'] ) : 'notebooks',
			'answer_mode' => isset( $spec['answer_mode'] ) ? sanitize_key( (string) $spec['answer_mode'] ) : 'instant',
			'model'       => isset( $spec['model'] ) ? sanitize_text_field( (string) $spec['model'] ) : 'auto',
			'guru_id'     => isset( $spec['guru_id'] ) ? (int) $spec['guru_id'] : 0,
			// [2026-07-20 Johnny Chu] PHASE-TWINWEB-THREADS — preserve safe subject/source hints patched into persistent thread_spec.
			'profile_template_slug' => isset( $spec['profile_template_slug'] ) ? sanitize_key( (string) $spec['profile_template_slug'] ) : '',
			'subject_user_id'       => isset( $spec['subject_user_id'] ) ? (int) $spec['subject_user_id'] : 0,
			'source_scope_hash'     => isset( $spec['source_scope_hash'] ) ? sanitize_text_field( (string) $spec['source_scope_hash'] ) : '',
			'title_source'=> isset( $meta['title_source'] ) ? sanitize_key( (string) $meta['title_source'] ) : 'manual',
			'summary_md'  => isset( $meta['summary_md'] ) ? sanitize_textarea_field( (string) $meta['summary_md'] ) : '',
			// [2026-08-02 Johnny Chu] PHASE-TWIN-GOAL-LOOP-P0 — expose only the versioned thread pointer/summary; canonical state remains event-sourced.
			'goal_link'   => self::normalize_goal_link( $goal_link ),
			'goal_summary'=> isset( $meta['goal_summary'] ) && is_array( $meta['goal_summary'] ) ? self::normalize_goal_summary( $meta['goal_summary'] ) : null,
			'updated_at'  => isset( $spec['updated_at'] ) ? sanitize_text_field( (string) $spec['updated_at'] ) : '',
		);
	}

	/**
	 * Synchronize the thread pointer after a canonical Goal Loop event succeeds.
	 *
	 * @param int|string $thread_id Numeric TwinWeb thread id.
	 * @param array      $goal       Normalized Goal Loop state.
	 * @param string     $event_uuid Canonical event UUID returned by the Event Bus.
	 * @return bool
	 */
	public static function sync_goal_link( $thread_id, array $goal, $event_uuid ) {
		// [2026-08-02 Johnny Chu] PHASE-TWIN-GOAL-LOOP-P0 — event-first, ownership-checked, idempotent meta_json pointer; never trust goal state from the browser.
		$thread_id = (int) $thread_id;
		$event_uuid = sanitize_text_field( (string) $event_uuid );
		$goal_id = sanitize_text_field( (string) ( $goal['goal_id'] ?? '' ) );
		if ( $thread_id <= 0 || $event_uuid === '' || $goal_id === '' ) {
			return false;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_twinweb_threads';
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $thread_id ) );
		if ( ! $row || ! self::owns_row( $row ) ) {
			return false;
		}

		$meta_raw = isset( $row->meta_json ) ? (string) $row->meta_json : '';
		$meta = $meta_raw !== '' ? json_decode( $meta_raw, true ) : array();
		$meta = is_array( $meta ) ? $meta : array();
		$now = gmdate( 'c' );
		$existing_link = isset( $meta['goal_link'] ) && is_array( $meta['goal_link'] ) ? $meta['goal_link'] : array();
		$status = sanitize_key( (string) ( $goal['status'] ?? 'clarifying' ) );
		$link_role = class_exists( 'BizCity_TwinBrain_Goal_Loop_State' ) && BizCity_TwinBrain_Goal_Loop_State::is_terminal( $status )
			? 'terminal'
			: ( in_array( $status, array( 'paused', 'blocked' ), true ) ? 'paused' : 'active' );
		$meta['goal_link'] = self::normalize_goal_link( array(
			'schema'             => 'bizcity.twin.goal_link.v1',
			'goal_id'            => $goal_id,
			'root_session_id'    => (string) ( $goal['root_session_id'] ?? $existing_link['root_session_id'] ?? $goal['session_id'] ?? '' ),
			'current_session_id' => (string) ( $goal['session_id'] ?? '' ),
			'link_role'          => $link_role,
			'status'             => $status,
			'last_event_id'      => (int) ( $goal['_event_id'] ?? 0 ),
			'last_event_uuid'    => $event_uuid,
			'updated_at'         => $now,
		) );
		$meta['goal_summary'] = self::normalize_goal_summary( array(
			'goal_id'          => $goal_id,
			'primary_goal'     => (string) ( $goal['primary_goal'] ?? '' ),
			'status'           => $status,
			'completion_score' => (float) ( $goal['completion_score'] ?? 0 ),
			'open_gap_count'   => count( (array) ( $goal['gaps'] ?? array() ) ),
			'next_best_action' => is_array( $goal['next_best_action'] ?? null ) ? $goal['next_best_action'] : null,
			'source_event_uuid'=> $event_uuid,
			'updated_at'       => $now,
		) );

		return false !== $wpdb->update( $table, array( 'meta_json' => wp_json_encode( $meta ) ), array( 'id' => $thread_id ) );
	}

	public static function repair_goal_link( $row, array &$meta ) {
		// [2026-08-02 Johnny Chu] PHASE-TWIN-GOAL-LOOP-P1 — repair legacy thread metadata from the canonical event snapshot only; never create a goal during thread hydration.
		if ( ! is_object( $row ) || ! empty( $meta['goal_link']['goal_id'] ?? '' ) || ! class_exists( 'BizCity_TwinBrain_Goal_Loop_Repository' ) ) {
			return false;
		}
		if ( ! self::owns_row( $row ) ) {
			return false;
		}
		$identity = self::resolve_identity();
		if ( $identity['uuid'] === '' ) {
			return false;
		}
		$spec = isset( $meta['thread_spec'] ) && is_array( $meta['thread_spec'] ) ? $meta['thread_spec'] : array();
		$session_id = isset( $spec['session_id'] ) && (string) $spec['session_id'] !== ''
			? (string) $spec['session_id']
			: ( isset( $row->id ) ? (string) (int) $row->id : '' );
		if ( $session_id === '' ) {
			return false;
		}
		$goal = BizCity_TwinBrain_Goal_Loop_Repository::latest( (int) get_current_blog_id(), $identity['uuid'], $session_id );
		$event_uuid = sanitize_text_field( (string) ( $goal['_event_uuid'] ?? '' ) );
		if ( empty( $goal['goal_id'] ) || $event_uuid === '' ) {
			return false;
		}
		if ( ! self::sync_goal_link( (int) $row->id, $goal, $event_uuid ) ) {
			return false;
		}
		if ( class_exists( 'BizCity_TwinBrain_Goal_Loop_Repository' ) && method_exists( 'BizCity_TwinBrain_Goal_Loop_Repository', 'trace_projection' ) ) {
			BizCity_TwinBrain_Goal_Loop_Repository::trace_projection(
				'goal_link_repaired',
				$goal,
				array( 'event_uuid' => $event_uuid, 'event_source' => 'twinweb_thread_hydration' ),
				'legacy_meta_missing'
			);
		}
		$meta['goal_link'] = self::normalize_goal_link( array(
			'goal_id'            => $goal['goal_id'],
			'root_session_id'    => $goal['session_id'] ?? $session_id,
			'current_session_id' => $goal['session_id'] ?? $session_id,
			'link_role'          => 'terminal' === ( $goal['status'] ?? '' ) ? 'terminal' : 'active',
			'status'             => $goal['status'] ?? '',
			'last_event_id'      => (int) ( $goal['_event_id'] ?? 0 ),
			'last_event_uuid'    => $event_uuid,
			'updated_at'         => gmdate( 'c' ),
		) );
		$meta['goal_summary'] = self::normalize_goal_summary( array(
			'goal_id'           => $goal['goal_id'],
			'primary_goal'      => $goal['primary_goal'] ?? '',
			'status'            => $goal['status'] ?? '',
			'completion_score'  => $goal['completion_score'] ?? 0,
			'open_gap_count'    => count( (array) ( $goal['gaps'] ?? array() ) ),
			'next_best_action'  => $goal['next_best_action'] ?? null,
			'source_event_uuid' => $event_uuid,
			'updated_at'        => gmdate( 'c' ),
		) );
		return true;
	}

	private static function normalize_goal_link( array $link ) {
		$role = sanitize_key( (string) ( $link['link_role'] ?? '' ) );
		if ( ! in_array( $role, array( 'active', 'paused', 'terminal' ), true ) ) {
			$role = '';
		}
		return array(
			'schema'             => 'bizcity.twin.goal_link.v1',
			'goal_id'            => sanitize_text_field( (string) ( $link['goal_id'] ?? '' ) ),
			'root_session_id'    => sanitize_text_field( (string) ( $link['root_session_id'] ?? '' ) ),
			'current_session_id' => sanitize_text_field( (string) ( $link['current_session_id'] ?? '' ) ),
			'link_role'          => $role,
			'status'             => sanitize_key( (string) ( $link['status'] ?? '' ) ),
			'last_event_id'      => max( 0, (int) ( $link['last_event_id'] ?? 0 ) ),
			'last_event_uuid'    => sanitize_text_field( (string) ( $link['last_event_uuid'] ?? '' ) ),
			'updated_at'         => sanitize_text_field( (string) ( $link['updated_at'] ?? '' ) ),
		);
	}

	private static function normalize_goal_summary( array $summary ) {
		$action = isset( $summary['next_best_action'] ) && is_array( $summary['next_best_action'] ) ? $summary['next_best_action'] : null;
		return array(
			'goal_id'          => sanitize_text_field( (string) ( $summary['goal_id'] ?? '' ) ),
			'primary_goal'     => sanitize_text_field( (string) ( $summary['primary_goal'] ?? '' ) ),
			'status'           => sanitize_key( (string) ( $summary['status'] ?? '' ) ),
			'completion_score' => max( 0, min( 1, (float) ( $summary['completion_score'] ?? 0 ) ) ),
			'open_gap_count'   => max( 0, (int) ( $summary['open_gap_count'] ?? 0 ) ),
			'next_best_action' => $action,
			'source_event_uuid'=> sanitize_text_field( (string) ( $summary['source_event_uuid'] ?? '' ) ),
			'updated_at'       => sanitize_text_field( (string) ( $summary['updated_at'] ?? '' ) ),
		);
	}

	private static function owns_row( $row ) {
		if ( ! class_exists( 'BizCity_TwinWeb_Identity' ) ) {
			return false;
		}
		$identity = BizCity_TwinWeb_Identity::current();
		if ( ! empty( $identity['is_guest'] ) ) {
			return isset( $row->guest_sid ) && (string) $row->guest_sid === (string) ( $identity['guest_sid'] ?? '' );
		}
		return (int) ( $row->user_id ?? 0 ) === (int) ( $identity['user_id'] ?? 0 );
	}

	private static function resolve_identity() {
		if ( ! class_exists( 'BizCity_Memory_Identity_Scope' ) ) {
			return array( 'uuid' => '' );
		}
		$identity = class_exists( 'BizCity_TwinWeb_Identity' ) ? BizCity_TwinWeb_Identity::current() : array( 'user_id' => get_current_user_id(), 'is_guest' => false, 'guest_sid' => '' );
		$user_id = (int) ( $identity['user_id'] ?? 0 );
		$context = $user_id > 0
			? array( 'user_id' => $user_id, 'wp_user_id' => $user_id, 'platform' => 'TWIN_GPT' )
			: array(
				'platform'            => 'WEBCHAT',
				'account_id'          => (string) get_current_blog_id(),
				'external_user_id'    => (string) ( $identity['guest_sid'] ?? '' ),
				'identity_guest_bind' => true,
				'identity_is_stable'  => true,
			);
		$scope = BizCity_Memory_Identity_Scope::resolve( $context );
		return array( 'uuid' => strtolower( trim( (string) ( $scope['identity_uuid'] ?? '' ) ) ) );
	}

	/**
	 * Surface adapters available to the unified registry.
	 *
	 * @return array
	 */
	public static function surfaces() {
		// [2026-07-19 Johnny Chu] PHASE-TWINWEB-THREADS — filter slot for TwinChat thread adapter without changing TwinChat storage today.
		return apply_filters( 'bizcity_twin_thread_registry_surfaces', array(
			'twinweb' => array(
				'label'   => 'Twin GPT',
				'schema'  => self::SPEC_SCHEMA,
				'storage' => 'bizcity_twinweb_threads',
			),
		) );
	}
}