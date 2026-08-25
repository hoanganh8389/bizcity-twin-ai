<?php
/**
 * BizCity CRM assignment policy and fair-distribution service.
 *
 * Uses WordPress users plus tenant-local inbox/team membership. It owns
 * assignment decisions, while Repository owns conversation state writes.
 *
 * @package BizCity_Twin_CRM
 * @since   PHASE-0.39F 2026-08-24
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Assignment_Manager', false ) ) {
	return;
}

final class BizCity_CRM_Assignment_Manager {

	const DEFAULT_POLICY = array(
		'assignment_order' => 'round_robin',
		'conversation_priority' => 'earliest_created',
		'fair_distribution_limit' => 100,
		'fair_distribution_window_seconds' => 3600,
		'max_open_conversations_per_user' => 0,
		'overflow_action' => 'leave_unassigned',
	);

	public static function register(): void {
		// [2026-08-25 Johnny Chu] PHASE-0.39F-F5 — auto-assign only once from the canonical new-conversation event.
		static $registered = false;
		if ( $registered ) { return; }
		$registered = true;
		add_action( 'bizcity_crm_event_crm_conversation_opened', array( __CLASS__, 'on_conversation_opened' ), 30, 1 );
	}

	public static function on_conversation_opened( $payload ): void {
		// [2026-08-25 Johnny Chu] PHASE-0.39F-F5 — keep assignment fail-open for ingest while retaining an explicit outcome.
		if ( ! is_array( $payload ) || (int) ( $payload['conversation_id'] ?? 0 ) <= 0 ) { return; }
		try {
			self::assign_conversation( (int) $payload['conversation_id'] );
		} catch ( \Throwable $e ) {
			if ( class_exists( 'BizCity_CRM_Event_Emitter' ) ) {
				BizCity_CRM_Event_Emitter::emit( 'crm_assignment_failed', array(
					'conversation_id' => (int) $payload['conversation_id'],
					'inbox_id'        => (int) ( $payload['inbox_id'] ?? 0 ),
					'reason'          => 'assignment_exception',
					'error_class'     => get_class( $e ),
				) );
			}
		}
	}

	public static function list_policies(): array {
		// [2026-08-24 Johnny Chu] PHASE-0.39F-F5 — cache the tenant-local policy catalog with module invalidation.
		$cache_key = 'policies_' . (int) get_current_blog_id();
		if ( class_exists( 'BizCity_Cache' ) ) {
			$cached = BizCity_Cache::get( 'crm_assignment', $cache_key );
			if ( false !== $cached && is_array( $cached ) ) { return $cached; }
		}
		global $wpdb;
		$table = BizCity_CRM_DB_Installer_V2::tbl_assignment_policies();
		$rows = $wpdb->get_results( "SELECT * FROM `{$table}` ORDER BY name ASC, id ASC", ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();
		if ( class_exists( 'BizCity_Cache' ) ) { BizCity_Cache::set( 'crm_assignment', $cache_key, $rows, BizCity_Cache::TTL_MEDIUM ); }
		return $rows;
	}

	public static function create_policy( array $data, int $actor_user_id = 0 ): int {
		// [2026-08-24 Johnny Chu] PHASE-0.39F-F5 — validate and persist one tenant assignment policy.
		$name = sanitize_text_field( trim( (string) ( $data['name'] ?? '' ) ) );
		if ( $name === '' ) { return 0; }
		$order = sanitize_key( (string) ( $data['assignment_order'] ?? 'round_robin' ) );
		$priority = sanitize_key( (string) ( $data['conversation_priority'] ?? 'earliest_created' ) );
		$overflow = sanitize_key( (string) ( $data['overflow_action'] ?? 'leave_unassigned' ) );
		if ( ! in_array( $order, array( 'round_robin' ), true ) || ! in_array( $priority, array( 'earliest_created', 'longest_waiting' ), true ) || ! in_array( $overflow, array( 'leave_unassigned', 'assign_team_lead', 'pause_auto_assign' ), true ) ) { return 0; }
		global $wpdb;
		$table = BizCity_CRM_DB_Installer_V2::tbl_assignment_policies();
		$now = current_time( 'mysql' );
		$ok = $wpdb->insert( $table, array(
			'name' => $name,
			'description' => sanitize_textarea_field( (string) ( $data['description'] ?? '' ) ),
			'enabled' => array_key_exists( 'enabled', $data ) ? ( empty( $data['enabled'] ) ? 0 : 1 ) : 1,
			'assignment_order' => $order,
			'conversation_priority' => $priority,
			'fair_distribution_limit' => max( 1, min( 10000, (int) ( $data['fair_distribution_limit'] ?? 100 ) ) ),
			'fair_distribution_window_seconds' => max( 60, min( 86400, (int) ( $data['fair_distribution_window_seconds'] ?? 3600 ) ) ),
			'max_open_conversations_per_user' => ! empty( $data['max_open_conversations_per_user'] ) ? max( 1, min( 10000, (int) $data['max_open_conversations_per_user'] ) ) : null,
			'overflow_action' => $overflow,
			'created_by' => $actor_user_id > 0 ? $actor_user_id : get_current_user_id(),
			'updated_by' => $actor_user_id > 0 ? $actor_user_id : get_current_user_id(),
			'created_at' => $now,
			'updated_at' => $now,
		), array( '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%d', '%s', '%d', '%d', '%s', '%s' ) );
		if ( ! $ok ) { return 0; }
		self::flush_cache();
		return (int) $wpdb->insert_id;
	}

	public static function bind_policy( int $inbox_id, int $policy_id, int $team_id = 0 ): bool {
		// [2026-08-24 Johnny Chu] PHASE-0.39F-F5 — bind one validated policy to one tenant inbox.
		if ( $inbox_id <= 0 || $policy_id <= 0 ) { return false; }
		global $wpdb;
		$inboxes = BizCity_CRM_DB_Installer_V2::tbl_inboxes();
		$policies = BizCity_CRM_DB_Installer_V2::tbl_assignment_policies();
		if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$inboxes}` WHERE id = %d LIMIT 1", $inbox_id ) ) || ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$policies}` WHERE id = %d LIMIT 1", $policy_id ) ) ) { return false; }
		if ( $team_id > 0 && ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `" . BizCity_CRM_DB_Installer_V2::tbl_teams() . "` WHERE id = %d AND is_active = 1 LIMIT 1", $team_id ) ) ) { return false; }
		$table = BizCity_CRM_DB_Installer_V2::tbl_inbox_assignment_policies();
		$now = current_time( 'mysql' );
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$table}` WHERE inbox_id = %d LIMIT 1", $inbox_id ) );
		$data = array( 'assignment_policy_id' => $policy_id, 'team_id' => $team_id > 0 ? $team_id : null, 'enabled' => 1, 'updated_at' => $now );
		$ok = $existing ? false !== $wpdb->update( $table, $data, array( 'id' => (int) $existing ), array( '%d', '%d', '%d', '%s' ), array( '%d' ) ) : false !== $wpdb->insert( $table, array_merge( array( 'inbox_id' => $inbox_id ), $data, array( 'created_at' => $now ) ), array( '%d', '%d', '%d', '%d', '%s', '%s' ) );
		if ( $ok ) { self::flush_cache(); }
		return $ok;
	}

	public static function update_policy( int $policy_id, array $data, int $actor_user_id = 0 ): bool {
		// [2026-08-24 Johnny Chu] PHASE-0.39F-F5 — update policy fields without changing the inbox binding identity.
		if ( $policy_id <= 0 ) { return false; }
		global $wpdb;
		$table = BizCity_CRM_DB_Installer_V2::tbl_assignment_policies();
		$current = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d LIMIT 1", $policy_id ), ARRAY_A );
		if ( ! is_array( $current ) ) { return false; }
		$merged = array_merge( $current, $data );
		$order = sanitize_key( (string) ( $merged['assignment_order'] ?? 'round_robin' ) );
		$priority = sanitize_key( (string) ( $merged['conversation_priority'] ?? 'earliest_created' ) );
		$overflow = sanitize_key( (string) ( $merged['overflow_action'] ?? 'leave_unassigned' ) );
		if ( ! in_array( $order, array( 'round_robin' ), true ) || ! in_array( $priority, array( 'earliest_created', 'longest_waiting' ), true ) || ! in_array( $overflow, array( 'leave_unassigned', 'assign_team_lead', 'pause_auto_assign' ), true ) ) { return false; }
		$now = current_time( 'mysql' );
		$ok = false !== $wpdb->update( $table, array(
			'name' => sanitize_text_field( trim( (string) ( $merged['name'] ?? '' ) ) ),
			'description' => sanitize_textarea_field( (string) ( $merged['description'] ?? '' ) ),
			'enabled' => empty( $merged['enabled'] ) ? 0 : 1,
			'assignment_order' => $order,
			'conversation_priority' => $priority,
			'fair_distribution_limit' => max( 1, min( 10000, (int) ( $merged['fair_distribution_limit'] ?? 100 ) ) ),
			'fair_distribution_window_seconds' => max( 60, min( 86400, (int) ( $merged['fair_distribution_window_seconds'] ?? 3600 ) ) ),
			'max_open_conversations_per_user' => ! empty( $merged['max_open_conversations_per_user'] ) ? max( 1, min( 10000, (int) $merged['max_open_conversations_per_user'] ) ) : null,
			'overflow_action' => $overflow,
			'updated_by' => $actor_user_id > 0 ? $actor_user_id : get_current_user_id(),
			'updated_at' => $now,
		), array( 'id' => $policy_id ), array( '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%d', '%s', '%d', '%s' ), array( '%d' ) );
		if ( $ok ) { self::flush_cache(); }
		return $ok;
	}

	/** Assign one open conversation using the inbox policy, if eligible. */
	public static function assign_conversation( int $conversation_id ): array {
		// [2026-08-24 Johnny Chu] PHASE-0.39F-F5 — assign only through tenant-local membership, capability and capacity policy.
		if ( $conversation_id <= 0 || ! class_exists( 'BizCity_CRM_Repository' ) ) {
			return self::outcome( 'permanent_failed', 'invalid_conversation' );
		}
		$conversation = BizCity_CRM_Repository::get_conversation( $conversation_id );
		if ( ! is_array( $conversation ) ) {
			return self::outcome( 'permanent_failed', 'conversation_not_found' );
		}
		$inbox_id = (int) ( $conversation['inbox_id'] ?? 0 );
		$policy = self::policy_for_inbox( $inbox_id );
		if ( empty( $policy['enabled'] ) ) {
			return self::outcome( 'ignored', 'assignment_policy_disabled' );
		}
		$team_id = (int) ( $policy['team_id'] ?? 0 );
		$lock_name = 'bizcity_crm_assign_' . (int) get_current_blog_id() . '_' . $inbox_id;
		global $wpdb;
		$locked = (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock_name ) );
		if ( ! $locked ) {
			return self::outcome( 'retryable', 'assignment_lock_timeout' );
		}
		$transaction_started = false;
		$previous_conversation = $conversation;
		try {
			// [2026-08-24 Johnny Chu] PHASE-0.39F-F5 — keep team, assignee and fair-distribution cursor in one tenant transaction.
			$transaction_started = false !== $wpdb->query( 'START TRANSACTION' );
			if ( ! $transaction_started ) {
				return self::outcome( 'retryable', 'assignment_transaction_failed' );
			}
			$conversation = BizCity_CRM_Repository::get_conversation( $conversation_id );
			if ( ! is_array( $conversation ) || ! empty( $conversation['assignee_id'] ) ) {
				$wpdb->query( 'ROLLBACK' );
				$transaction_started = false;
				return self::outcome( 'ignored', 'conversation_already_assigned' );
			}
			$candidates = self::eligible_candidates( $inbox_id, $team_id, $policy );
			if ( empty( $candidates ) ) {
				$wpdb->query( 'ROLLBACK' );
				$transaction_started = false;
				if ( class_exists( 'BizCity_CRM_Event_Emitter' ) ) {
					BizCity_CRM_Event_Emitter::emit( 'crm_assignment_no_eligible_agent', array( 'conversation_id' => $conversation_id, 'inbox_id' => $inbox_id, 'team_id' => $team_id ?: null, 'reason' => 'no_eligible_agent', 'overflow_action' => (string) $policy['overflow_action'] ) );
				}
				return self::outcome( 'ignored', 'no_eligible_agent', array( 'overflow_action' => (string) $policy['overflow_action'] ) );
			}
			$selected = self::select_candidate( $candidates, $policy );
			if ( ! BizCity_CRM_Repository::set_conversation_team( $conversation_id, $team_id > 0 ? $team_id : null, 0, false ) ) {
				$wpdb->query( 'ROLLBACK' );
				$transaction_started = false;
				return self::outcome( 'retryable', 'team_assignment_failed' );
			}
			if ( ! BizCity_CRM_Repository::set_conversation_assignee( $conversation_id, (int) $selected['user_id'], 0, array( 'team_id' => $team_id, 'policy_id' => (int) ( $policy['id'] ?? 0 ), 'reason' => 'auto_assign' ), false ) ) {
				$wpdb->query( 'ROLLBACK' );
				$transaction_started = false;
				return self::outcome( 'retryable', 'assignee_assignment_failed' );
			}
			if ( ! self::mark_assigned( (int) $selected['user_id'], $inbox_id, $team_id ) ) {
				$wpdb->query( 'ROLLBACK' );
				$transaction_started = false;
				return self::outcome( 'retryable', 'assignment_cursor_failed' );
			}
			$wpdb->query( 'COMMIT' );
			$transaction_started = false;
			// [2026-08-25 Johnny Chu] PHASE-0.39F-S2 — emit assignment events only after the transaction commits.
			self::emit_committed_assignment_events( $previous_conversation, $conversation_id, $team_id, $selected, (int) ( $policy['id'] ?? 0 ) );
			return self::outcome( 'accepted', 'assigned', array( 'user_id' => (int) $selected['user_id'], 'team_id' => $team_id ?: null ) );
		} finally {
			if ( $transaction_started ) {
				$wpdb->query( 'ROLLBACK' );
			}
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
	}

	private static function policy_for_inbox( int $inbox_id ): array {
		// [2026-08-24 Johnny Chu] PHASE-0.39F-F5 — cache the effective inbox policy until a policy mutation flushes the module.
		if ( $inbox_id <= 0 ) { return array(); }
		$cache_key = 'inbox_policy_' . (int) get_current_blog_id() . '_' . $inbox_id;
		if ( class_exists( 'BizCity_Cache' ) ) {
			$cached = BizCity_Cache::get( 'crm_assignment', $cache_key );
			if ( false !== $cached && is_array( $cached ) ) { return $cached; }
		}
		global $wpdb;
		$binding = BizCity_CRM_DB_Installer_V2::tbl_inbox_assignment_policies();
		$policies = BizCity_CRM_DB_Installer_V2::tbl_assignment_policies();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT p.*, b.team_id, b.enabled AS binding_enabled FROM `{$binding}` b JOIN `{$policies}` p ON p.id = b.assignment_policy_id WHERE b.inbox_id = %d AND b.enabled = 1 LIMIT 1", $inbox_id ), ARRAY_A );
		if ( ! is_array( $row ) ) { return array(); }
		$row['enabled'] = ! empty( $row['enabled'] ) && ! empty( $row['binding_enabled'] );
		$row = array_merge( self::DEFAULT_POLICY, $row );
		if ( class_exists( 'BizCity_Cache' ) ) { BizCity_Cache::set( 'crm_assignment', $cache_key, $row, BizCity_Cache::TTL_SHORT ); }
		return $row;
	}

	private static function eligible_candidates( int $inbox_id, int $team_id, array $policy ): array {
		global $wpdb;
		$members = BizCity_CRM_DB_Installer_V2::tbl_inbox_members();
		$team_members = BizCity_CRM_DB_Installer_V2::tbl_team_members();
		$conversations = BizCity_CRM_DB_Installer_V2::tbl_conversations();
		$join = $team_id > 0 ? "JOIN `{$team_members}` tm ON tm.user_id = im.user_id AND tm.team_id = %d AND tm.is_active = 1" : '';
		$params = array();
		$window = max( 60, min( 86400, (int) ( $policy['fair_distribution_window_seconds'] ?? 3600 ) ) );
		$since = gmdate( 'Y-m-d H:i:s', time() - $window );
		$params[] = $since;
		if ( $team_id > 0 ) { $params[] = $team_id; }
		$params[] = $inbox_id;
		$max_open = (int) ( $policy['max_open_conversations_per_user'] ?? 0 );
		$capacity = $max_open > 0 ? "AND (SELECT COUNT(*) FROM `{$conversations}` c WHERE c.assignee_id = im.user_id AND c.status IN ('open','pending')) < %d" : '';
		if ( $max_open > 0 ) { $params[] = $max_open; }
		$last_assigned = $team_id > 0 ? 'tm.last_assigned_at' : 'NULL';
		$sql = "SELECT im.user_id, im.member_role, im.can_assign, {$last_assigned} AS last_assigned_at, (SELECT COUNT(*) FROM `{$conversations}` c2 WHERE c2.assignee_id = im.user_id AND c2.status IN ('open','pending')) AS open_count, (SELECT COUNT(*) FROM `{$conversations}` c3 WHERE c3.assignee_id = im.user_id AND c3.updated_at >= %s) AS fair_count FROM `{$members}` im {$join} WHERE im.inbox_id = %d AND im.is_active = 1 AND im.member_role <> 'observer' AND (im.can_assign = 1 OR im.member_role IN ('agent','lead','supervisor')) {$capacity} ORDER BY fair_count ASC, open_count ASC, COALESCE({$last_assigned}, '1970-01-01 00:00:00') ASC, im.user_id ASC";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		$out = array();
		$fair_limit = max( 1, min( 10000, (int) ( $policy['fair_distribution_limit'] ?? 100 ) ) );
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$user_id = (int) ( $row['user_id'] ?? 0 );
			if ( (int) ( $row['fair_count'] ?? 0 ) >= $fair_limit ) { continue; }
			if ( $user_id > 0 && get_userdata( $user_id ) && ( user_can( $user_id, 'bizcity_crm_handle_inbox' ) || user_can( $user_id, 'manage_options' ) ) ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	private static function select_candidate( array $candidates, array $policy ): array {
		// [2026-08-24 Johnny Chu] PHASE-0.39F-F5 — conversation priority belongs to queue ordering, not agent candidate ordering.
		return (array) reset( $candidates );
	}

	private static function emit_committed_assignment_events( array $previous_conversation, int $conversation_id, int $team_id, array $selected, int $policy_id ): void {
		// [2026-08-25 Johnny Chu] PHASE-0.39F-S2 — publish only the committed assignment transition with its original state.
		if ( ! class_exists( 'BizCity_CRM_Event_Emitter' ) ) {
			return;
		}
		$previous_team_id = ! empty( $previous_conversation['team_id'] ) ? (int) $previous_conversation['team_id'] : null;
		$next_team_id = $team_id > 0 ? $team_id : null;
		if ( $previous_team_id !== $next_team_id ) {
			BizCity_CRM_Event_Emitter::emit( 'crm_conversation_team_changed', array(
				'conversation_id' => $conversation_id,
				'previous_team_id' => $previous_team_id,
				'team_id' => $next_team_id,
				'by_user_id' => get_current_user_id(),
			) );
		}
		BizCity_CRM_Event_Emitter::emit( 'crm_conversation_assigned', array(
			'conversation_id' => $conversation_id,
			'previous_assignee_id' => ! empty( $previous_conversation['assignee_id'] ) ? (int) $previous_conversation['assignee_id'] : null,
			'assignee_id' => (int) ( $selected['user_id'] ?? 0 ),
			'by_user_id' => get_current_user_id(),
			'team_id' => $next_team_id,
			'policy_id' => $policy_id > 0 ? $policy_id : null,
			'reason' => 'auto_assign',
		) );
	}

	private static function mark_assigned( int $user_id, int $inbox_id, int $team_id ): bool {
		global $wpdb;
		$now = current_time( 'mysql' );
		if ( $team_id > 0 ) {
			return false !== $wpdb->update( BizCity_CRM_DB_Installer_V2::tbl_team_members(), array( 'last_assigned_at' => $now, 'updated_at' => $now ), array( 'team_id' => $team_id, 'user_id' => $user_id ), array( '%s', '%s' ), array( '%d', '%d' ) );
		}
		return true;
	}

	private static function outcome( string $outcome, string $code, array $extra = array() ): array {
		return array_merge( array( 'success' => $outcome === 'accepted', 'outcome' => $outcome, 'code' => sanitize_key( $code ), 'error' => $outcome === 'accepted' ? null : $code, 'retryable' => $outcome === 'retryable', 'contract_version' => '1.0.0' ), $extra );
	}

	private static function flush_cache(): void {
		if ( class_exists( 'BizCity_Cache_Registry' ) ) {
			BizCity_Cache_Registry::flush_module( 'modules.twin-crm' );
		}
	}
}

if ( class_exists( 'BizCity_Cache_Registry' ) ) {
	BizCity_Cache_Registry::register( 'crm_assignment', 'modules.twin-crm', array(
		'policies_{blog_id}'       => array( 'ttl' => 300, 'desc' => 'Tenant-local assignment policy catalog' ),
		'inbox_policy_{blog_id}_{id}' => array( 'ttl' => 60, 'desc' => 'Effective assignment policy for one inbox' ),
	) );
}
