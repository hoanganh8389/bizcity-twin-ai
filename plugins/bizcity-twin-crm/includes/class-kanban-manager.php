<?php
/**
 * BizCity CRM Kanban projections.
 *
 * Boards are read-only projections over existing conversation, lead,
 * opportunity, task and Woo-owned order state. No Kanban shadow table exists.
 *
 * Cache Contract: group `crm_kanban`; keys are blog-scoped board projections;
 * TTL is short; conversation/status/assignment and source mutations flush the
 * CRM module registry before the next read.
 *
 * @package BizCity_Twin_CRM
 * @since   PHASE-0.39F 2026-08-24
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Kanban_Manager', false ) ) {
	return;
}

final class BizCity_CRM_Kanban_Manager {

	const CACHE_GROUP = 'crm_kanban';

	public static function conversation_board( array $args = array() ): array {
		// [2026-08-24 Johnny Chu] PHASE-0.39F-F6 — project only the caller's allowed inboxes into the operations board.
		$cache_key = 'conversation_' . (int) get_current_blog_id() . '_' . md5( serialize( $args ) );
		if ( class_exists( 'BizCity_Cache' ) ) {
			$cached = BizCity_Cache::get( self::CACHE_GROUP, $cache_key );
			if ( false !== $cached && is_array( $cached ) ) { return $cached; }
		}
		$rows = BizCity_CRM_Repository::list_conversations( $args );
		$columns = array( 'unassigned' => array(), 'open' => array(), 'pending' => array(), 'snoozed' => array(), 'resolved' => array() );
		foreach ( $rows as $row ) {
			$status = (string) ( $row['status'] ?? 'open' );
			$column = ! empty( $row['assignee_id'] ) ? $status : 'unassigned';
			if ( ! isset( $columns[ $column ] ) ) { $column = 'open'; }
			$columns[ $column ][] = array(
				'id' => (int) $row['id'],
				'inbox_id' => (int) $row['inbox_id'],
				'contact_id' => (int) ( $row['contact_id'] ?? 0 ),
				'status' => $status,
				'priority' => (int) ( $row['priority'] ?? 0 ),
				'assignee_id' => ! empty( $row['assignee_id'] ) ? (int) $row['assignee_id'] : null,
				'team_id' => ! empty( $row['team_id'] ) ? (int) $row['team_id'] : null,
				'contact_name' => (string) ( $row['contact_name'] ?? '' ),
				'last_message_type' => (string) ( $row['last_message_type'] ?? '' ),
				'last_message_at' => (string) ( $row['last_message_at'] ?? '' ),
			);
		}
		$result = array( 'board' => 'conversations', 'columns' => $columns, 'source' => 'bizcity_crm_conversations', 'content_free' => false );
		if ( class_exists( 'BizCity_Cache' ) ) { BizCity_Cache::set( self::CACHE_GROUP, $cache_key, $result, BizCity_Cache::TTL_SHORT ); }
		return $result;
	}

	public static function order_care_board( int $limit = 200, int $scope_user_id = 0 ): array {
		// [2026-08-24 Johnny Chu] PHASE-0.39F-F6 — expose lead/opportunity/task cards without duplicating Woo order truth.
		$limit = max( 1, min( 500, $limit ) );
		$cache_key = 'order_care_' . (int) get_current_blog_id() . '_' . $scope_user_id . '_' . $limit;
		if ( class_exists( 'BizCity_Cache' ) ) {
			$cached = BizCity_Cache::get( self::CACHE_GROUP, $cache_key );
			if ( false !== $cached && is_array( $cached ) ) { return $cached; }
		}
		global $wpdb;
		$leads = BizCity_CRM_DB_Installer_V2::tbl_crm_leads();
		$opportunities = BizCity_CRM_DB_Installer_V2::tbl_crm_opportunities();
		$tasks = BizCity_CRM_DB_Installer_V2::tbl_crm_tasks();
		$owner_where = $scope_user_id > 0 ? ' AND owner_id = %d' : '';
		$task_where = $scope_user_id > 0 ? ' AND assignee_id = %d' : '';
		$lead_params = $scope_user_id > 0 ? array( $scope_user_id, $limit ) : array( $limit );
		$opp_params = $scope_user_id > 0 ? array( $scope_user_id, $limit ) : array( $limit );
		$task_params = $scope_user_id > 0 ? array( $scope_user_id, $limit ) : array( $limit );
		$lead_rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, contact_id, status, owner_id, created_at, updated_at FROM `{$leads}` WHERE deleted_at IS NULL{$owner_where} ORDER BY updated_at DESC, id DESC LIMIT %d", $lead_params ), ARRAY_A );
		$opportunity_rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, contact_id, stage, status, owner_id, amount, currency, close_date, updated_at FROM `{$opportunities}` WHERE deleted_at IS NULL{$owner_where} ORDER BY updated_at DESC, id DESC LIMIT %d", $opp_params ), ARRAY_A );
		$task_rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, title, status, priority, due_date, assignee_id, related_entity_type, related_entity_id, updated_at FROM `{$tasks}` WHERE deleted_at IS NULL{$task_where} ORDER BY updated_at DESC, id DESC LIMIT %d", $task_params ), ARRAY_A );
		$result = array(
			'board' => 'order-care',
			'columns' => array(
				'leads' => array_values( is_array( $lead_rows ) ? $lead_rows : array() ),
				'opportunities' => array_values( is_array( $opportunity_rows ) ? $opportunity_rows : array() ),
				'care_tasks' => array_values( is_array( $task_rows ) ? $task_rows : array() ),
			),
			'order_source' => 'Woo HPOS',
			'crm_order_truth' => false,
		);
		if ( class_exists( 'BizCity_Cache' ) ) { BizCity_Cache::set( self::CACHE_GROUP, $cache_key, $result, BizCity_Cache::TTL_SHORT ); }
		return $result;
	}
}

if ( class_exists( 'BizCity_Cache_Registry' ) ) {
	BizCity_Cache_Registry::register( 'crm_kanban', 'modules.twin-crm', array(
		'conversation_{args_hash}' => array( 'ttl' => 60, 'desc' => 'Scoped conversation operations board' ),
		'order_care_{limit}'      => array( 'ttl' => 60, 'desc' => 'CRM order-care projection without order truth' ),
	) );
}

add_action( 'bizcity_crm_event', static function ( $event_type ) {
	// [2026-08-24 Johnny Chu] PHASE-0.39F-F6 — invalidate board projections after CRM state events.
	if ( class_exists( 'BizCity_Cache' ) && is_string( $event_type ) ) {
		BizCity_Cache::flush_group( BizCity_CRM_Kanban_Manager::CACHE_GROUP );
	}
}, 100, 1 );
