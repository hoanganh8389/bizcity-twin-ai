<?php
/**
 * Twin GPT member-safe CRM projections.
 *
 * This class is read-only and never grants tenant-wide access. The caller must
 * provide the identity resolved by BizCity_TwinWeb_Identity::current().
 *
 * @package BizCity_Twin_AI
 * @since 2026-08-25
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_TwinWeb_CRM_Projection', false ) ) {
	return;
}

final class BizCity_TwinWeb_CRM_Projection {

	public static function get_for_identity( array $identity, int $limit = 50 ): array {
		// [2026-08-25 Johnny Chu] PHASE-0.39F-F8 — compose only member-safe CRM projections after identity resolution.
		$user_id = (int) ( $identity['user_id'] ?? 0 );
		$current_user_id = (int) get_current_user_id();
		// [2026-08-25 Johnny Chu] PHASE-0.39F-S1 — reject stale or forged identity objects before any CRM scope resolution or query.
		if ( $user_id <= 0 || $current_user_id <= 0 || $user_id !== $current_user_id ) {
			return array( 'conversations' => array(), 'care_tasks' => array(), 'orders' => array(), '_degraded' => true, 'reason' => 'crm_identity_mismatch' );
		}
		if ( ! class_exists( 'BizCity_CRM_Repository' ) || ! class_exists( 'BizCity_CRM_Inbox_Access' ) ) {
			return array( 'conversations' => array(), 'care_tasks' => array(), 'orders' => array(), '_degraded' => true, 'reason' => 'crm_projection_unavailable' );
		}
		$limit = max( 1, min( 100, $limit ) );
		$scope = BizCity_CRM_Inbox_Access::resolve_scope( $user_id );
		$allowed = isset( $scope['inbox_ids'] ) && is_array( $scope['inbox_ids'] ) ? $scope['inbox_ids'] : null;
		$conversations = BizCity_CRM_Repository::list_conversations_for_member( $user_id, $allowed, $limit );
		$conversation_items = array();
		foreach ( $conversations as $conversation ) {
			$conversation_items[] = self::conversation_item( $conversation );
		}
		$tasks = BizCity_CRM_Repository::list_tasks_for_member( $user_id, $limit );
		$task_items = array();
		foreach ( $tasks as $task ) {
			$task_items[] = self::task_item( $task );
		}
		return array(
			'contract_version' => '1.0.0',
			'scope' => array(
				'scope_type' => (string) ( $scope['scope_type'] ?? 'empty' ),
				'owner_user_id' => $user_id,
				'allowed_inbox_count' => is_array( $allowed ) ? count( $allowed ) : null,
			),
			'conversations' => $conversation_items,
			'care_tasks' => $task_items,
			'orders' => self::orders_for_user( $user_id ),
			'member_safe' => true,
			'_degraded' => false,
		);
	}

	private static function conversation_item( array $row ): array {
		return array(
			'id' => (int) ( $row['id'] ?? 0 ),
			'inbox_id' => (int) ( $row['inbox_id'] ?? 0 ),
			'status' => sanitize_key( (string) ( $row['status'] ?? '' ) ),
			'priority' => (int) ( $row['priority'] ?? 0 ),
			'last_activity_at' => $row['last_activity_at'] ?? null,
			'last_message' => array(
				'content' => (string) ( $row['last_message_content'] ?? '' ),
				'content_type' => (string) ( $row['last_message_type'] ?? 'text' ),
				'created_at' => $row['last_message_at'] ?? null,
			),
			'channel' => sanitize_key( (string) ( $row['channel_type'] ?? '' ) ),
		);
	}

	private static function task_item( array $row ): array {
		return array(
			'id' => (int) ( $row['id'] ?? 0 ),
			'title' => sanitize_text_field( (string) ( $row['title'] ?? '' ) ),
			'status' => sanitize_key( (string) ( $row['status'] ?? '' ) ),
			'priority' => sanitize_key( (string) ( $row['priority'] ?? '' ) ),
			'due_date' => $row['due_date'] ?? null,
			'assignee_id' => ! empty( $row['assignee_id'] ) ? (int) $row['assignee_id'] : null,
			'related_entity_type' => sanitize_key( (string) ( $row['related_entity_type'] ?? '' ) ),
			'related_entity_id' => ! empty( $row['related_entity_id'] ) ? (int) $row['related_entity_id'] : null,
			'updated_at' => $row['updated_at'] ?? null,
		);
	}

	private static function orders_for_user( int $user_id ): array {
		if ( ! class_exists( 'BizCity_CRM_Order_Adapter_Registry' ) || ! class_exists( 'WooCommerce' ) && ! function_exists( 'WC' ) || ! class_exists( 'BizCity_CRM_Repository' ) ) {
			return array();
		}
		$adapter = BizCity_CRM_Order_Adapter_Registry::default_adapter();
		if ( ! $adapter ) { return array(); }
		$orders = array();
		foreach ( BizCity_CRM_Repository::list_contacts_for_wp_user( $user_id ) as $contact ) {
			$items = $adapter->list_orders_for_contact( $contact, 20 );
			foreach ( is_array( $items ) ? $items : array() as $order ) {
				$orders[] = self::order_item( (array) $order );
			}
		}
		return $orders;
	}

	private static function order_item( array $order ): array {
		$allowed = array( 'id', 'order_id', 'status', 'payment_status', 'shipment_status', 'total', 'currency', 'created_at', 'updated_at', 'recap_status' );
		$out = array();
		foreach ( $allowed as $key ) {
			if ( array_key_exists( $key, $order ) ) { $out[ $key ] = is_scalar( $order[ $key ] ) || null === $order[ $key ] ? $order[ $key ] : null; }
		}
		return $out;
	}
}
