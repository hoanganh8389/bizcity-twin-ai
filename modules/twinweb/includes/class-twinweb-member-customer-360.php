<?php
/**
 * Twin GPT — member-safe Customer 360 serializer (PHASE-0.50 C-02 C side / M4-02, R-LEADER-MEMBER R-LM-6).
 *
 * Owns the `/gpt/crm/` Customer 360 projection that used to be built inline in
 * BizCity_TwinWeb_REST::get_crm_member_customer360(). Two outputs from the same inputs:
 *   - legacy(): the flat DTO the current Twin GPT UI reads (unchanged keys);
 *   - envelope(): `member-customer-360@1.0.0` (core/twin-core/contracts/schema/public/v1/member-customer-360.schema.json),
 *     served with `?format=contract`.
 *
 * Inputs are already scoped by the caller (exact conversation in the member's own inboxes). This class
 * never widens scope: journey reads only the member's allowed inboxes, tasks are the member's own, and
 * `previously_cared` is a boolean without any colleague identity. Phone/email are kept only because the
 * member can edit this customer's facts in their own conversation (contract §7 item 2); they must never
 * be copied into URLs, logs or cache keys.
 *
 * PHP 7.4 compatible.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Modules\TwinWeb
 * @since PHASE-0.50 2026-09-18
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_TwinWeb_Member_Customer_360', false ) ) { return; }

final class BizCity_TwinWeb_Member_Customer_360 {

	const CONTRACT = 'member-customer-360';
	const VERSION  = '1.0.0';
	const SURFACE  = 'C_PUBLIC_TWINGPT';

	const TASK_KEYS = array( 'task_id', 'title', 'instructions', 'priority', 'status', 'overdue', 'due_at', 'assigned_by', 'seen', 'can' );
	const TASK_STATUSES = array( 'sent', 'accepted', 'in_progress', 'done', 'returned', 'cancelled' );
	const TASK_PRIORITIES = array( 'low', 'medium', 'high', 'urgent' );
	const MEMBER_ACTIONS = array( 'accept', 'start', 'complete', 'return' );
	const ROLES = array( 'admin', 'supervisor', 'lead', 'agent', 'none' );

	/**
	 * Collect every source once. `$context` is the output of the caller's exact-conversation resolver:
	 * { identity: {user_id}, conversation_id, contact_id, inbox_id, allowed_inboxes[] }.
	 */
	public static function collect( array $context, array $contact ): array {
		$member_id  = (int) ( $context['identity']['user_id'] ?? 0 );
		$contact_id = (int) ( $context['contact_id'] ?? 0 );
		$degraded   = array();

		$orders = array();
		$orders_available = false;
		if ( class_exists( 'BizCity_CRM_Order_Adapter_Registry' ) ) {
			$adapter = BizCity_CRM_Order_Adapter_Registry::default_adapter();
			if ( $adapter && $adapter->is_available() ) {
				$orders_available = true;
				$orders = (array) $adapter->list_orders_for_contact( array_merge( $contact, array( 'id' => $contact_id, 'conversation_id' => (int) $context['conversation_id'] ) ), 20 );
			}
		}
		if ( ! $orders_available ) { $degraded[] = 'orders'; }

		$care = class_exists( 'BizCity_CRM_Repository' ) && method_exists( 'BizCity_CRM_Repository', 'get_contact_care_projection' )
			? (array) BizCity_CRM_Repository::get_contact_care_projection( $contact_id, (array) ( $context['allowed_inboxes'] ?? array() ), 50 )
			: array();
		if ( empty( $care ) ) { $degraded[] = 'care'; }

		$handoff_tasks = array();
		if ( class_exists( 'BizCity_CRM_Task_Handoff' ) ) {
			$handoff_tasks = array_values( array_filter( (array) BizCity_CRM_Task_Handoff::list_for_member( $member_id, 'all', 20, $contact_id ) ) );
		} else {
			$degraded[] = 'tasks';
		}

		return array(
			'context'       => $context,
			'contact'       => $contact,
			'orders'        => $orders,
			'care'          => array(
				'notes'  => $care['notes'] ?? array(),
				'tasks'  => self::own_tasks( (array) ( $care['tasks'] ?? array() ), $member_id ),
				'labels' => $care['labels'] ?? array(),
			),
			'handoff_tasks' => $handoff_tasks,
			'journey'       => self::journey( $context, $contact, $orders ),
			'degraded'      => $degraded,
		);
	}

	/** Flat DTO consumed by the current Twin GPT UI (keys unchanged from the inline handler). */
	public static function legacy( array $bundle ): array {
		$contact = $bundle['contact'];
		$context = $bundle['context'];
		$journey = $bundle['journey'];
		$journey['contract'] = self::CONTRACT . '@' . self::VERSION;
		return array(
			'success'         => true,
			'conversation_id' => (int) $context['conversation_id'],
			'contact_id'      => (int) $context['contact_id'],
			'profile'         => array(
				'id'    => (int) $context['contact_id'],
				'name'  => sanitize_text_field( (string) ( $contact['name'] ?? '' ) ),
				'email' => sanitize_email( (string) ( $contact['email'] ?? '' ) ),
				'phone' => sanitize_text_field( (string) ( $contact['phone'] ?? '' ) ),
			),
			'orders'          => $bundle['orders'],
			'care'            => $bundle['care'],
			'handoff_tasks'   => $bundle['handoff_tasks'],
			'journey'         => $journey,
			'evidence_state'  => ! empty( $bundle['orders'] ) ? 'confirmed' : 'unavailable',
			'_degraded'       => false, // unchanged legacy value; real coverage is in envelope().
		);
	}

	/** `member-customer-360@1.0.0` envelope; only schema fields, subject === actor. */
	public static function envelope( array $bundle ): array {
		$context   = $bundle['context'];
		$contact   = $bundle['contact'];
		$member_id = (int) ( $context['identity']['user_id'] ?? 0 );
		$user      = function_exists( 'get_userdata' ) ? get_userdata( $member_id ) : null;
		$role      = class_exists( 'BizCity_CRM_Staff_Policy' ) ? (string) BizCity_CRM_Staff_Policy::role( $member_id ) : 'none';
		$display   = $user && '' !== trim( (string) $user->display_name ) ? (string) $user->display_name : '#' . $member_id;

		$data_contact = array(
			'contact_id'      => (int) $context['contact_id'],
			'conversation_id' => (int) $context['conversation_id'],
			'display_name'    => self::cut( sanitize_text_field( (string) ( $contact['name'] ?? '' ) ), 190 ),
		);
		$phone = sanitize_text_field( (string) ( $contact['phone'] ?? '' ) );
		$email = sanitize_email( (string) ( $contact['email'] ?? '' ) );
		if ( '' !== $phone ) { $data_contact['phone'] = self::cut( $phone, 32 ); }
		if ( '' !== $email ) { $data_contact['email'] = self::cut( $email, 190 ); }

		$tasks = array();
		$truncated = false;
		foreach ( (array) $bundle['handoff_tasks'] as $task ) {
			if ( count( $tasks ) >= 20 ) { $truncated = true; break; }
			$shaped = self::shape_task( is_array( $task ) ? $task : array() );
			if ( $shaped ) { $tasks[] = $shaped; }
		}

		$orders = array();
		foreach ( (array) $bundle['orders'] as $order ) {
			if ( count( $orders ) >= 20 ) { $truncated = true; break; }
			$shaped = self::shape_order( is_array( $order ) ? $order : array() );
			if ( $shaped ) { $orders[] = $shaped; }
		}

		return array(
			'contract'  => self::CONTRACT,
			'version'   => self::VERSION,
			'surface'   => self::SURFACE,
			'principal' => array( 'blog_id' => max( 1, (int) get_current_blog_id() ), 'actor_user_id' => $member_id ),
			'subject'   => array(
				'user_id'      => $member_id,
				'display_name' => self::cut( $display, 120 ),
				'team_role'    => in_array( $role, self::ROLES, true ) ? $role : 'none',
			),
			'as_of'     => current_time( 'c' ),
			'coverage'  => array(
				'complete'         => empty( $bundle['degraded'] ),
				'degraded_sources' => array_values( array_unique( (array) $bundle['degraded'] ) ),
				'truncated'        => $truncated,
			),
			'data'      => array(
				'contact' => $data_contact,
				'journey' => $bundle['journey'],
				'tasks'   => $tasks,
				'orders'  => $orders,
				'care'    => $bundle['care'],
			),
			'denied'    => array(),
		);
	}

	/**
	 * Member-safe short journey (moved from BizCity_TwinWeb_REST::crm_member_journey, 2026-09-18).
	 * Sources: conversations in the member's own inboxes + orders already returned to C.
	 * No colleague identity, no assignment history, no leader notes, no KPI. Stage from order evidence only.
	 */
	public static function journey( array $context, array $contact, array $orders ): array {
		global $wpdb;
		$member_id  = (int) ( $context['identity']['user_id'] ?? 0 );
		$contact_id = (int) ( $context['contact_id'] ?? 0 );
		$allowed    = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $context['allowed_inboxes'] ?? array() ) ) ) ) );
		$milestones = array();
		if ( ! empty( $contact['created_at'] ) ) {
			$milestones[] = array( 'kind' => 'profile', 'at' => (string) $contact['created_at'], 'ref' => null, 'status' => null, 'evidence_state' => 'confirmed' );
		}

		$last_touch_at    = null;
		$previously_cared = false;
		if ( ! empty( $allowed ) && $contact_id > 0 && class_exists( 'BizCity_CRM_DB_Installer_V2' ) ) {
			$conversations   = BizCity_CRM_DB_Installer_V2::tbl_conversations();
			$contact_inboxes = BizCity_CRM_DB_Installer_V2::tbl_contact_inboxes();
			$messages        = BizCity_CRM_DB_Installer_V2::tbl_messages();
			$placeholders    = implode( ',', array_fill( 0, count( $allowed ), '%d' ) );
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT c.id, c.created_at, c.last_activity_at FROM `{$conversations}` c
				 JOIN `{$contact_inboxes}` ci ON ci.id = c.contact_inbox_id
				 WHERE ci.contact_id = %d AND ci.inbox_id IN ({$placeholders})
				 ORDER BY c.id DESC LIMIT 20",
				array_merge( array( $contact_id ), $allowed )
			), ARRAY_A );
			$rows = is_array( $rows ) ? $rows : array();
			if ( ! empty( $rows ) ) {
				$first = end( $rows );
				if ( ! empty( $first['created_at'] ) ) {
					$milestones[] = array( 'kind' => 'conversation_started', 'at' => (string) $first['created_at'], 'ref' => null, 'status' => null, 'evidence_state' => 'confirmed' );
				}
				foreach ( $rows as $row ) {
					$at = (string) ( $row['last_activity_at'] ?? '' );
					if ( '' !== $at && ( null === $last_touch_at || strtotime( $at ) > strtotime( $last_touch_at ) ) ) { $last_touch_at = $at; }
				}
				if ( null !== $last_touch_at ) {
					$milestones[] = array( 'kind' => 'last_activity', 'at' => $last_touch_at, 'ref' => null, 'status' => null, 'evidence_state' => 'confirmed' );
				}
				$ids = array_map( 'intval', array_column( $rows, 'id' ) );
				$id_placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
				$previously_cared = (bool) $wpdb->get_var( $wpdb->prepare(
					"SELECT 1 FROM `{$messages}` WHERE conversation_id IN ({$id_placeholders})
					 AND message_type = 'outgoing' AND responder_kind = 'manual'
					 AND responder_user_id IS NOT NULL AND responder_user_id <> %d LIMIT 1",
					array_merge( $ids, array( $member_id ) )
				) );
			}
		}

		$paid_orders = 0;
		foreach ( $orders as $order ) {
			if ( ! is_array( $order ) ) { continue; }
			$status = sanitize_key( str_replace( 'wc-', '', (string) ( $order['status'] ?? '' ) ) );
			if ( in_array( $status, array( 'processing', 'completed' ), true ) ) { $paid_orders++; }
			$at = (string) ( $order['created_at'] ?? ( $order['date_created'] ?? '' ) );
			$ref = (int) ( $order['id'] ?? ( $order['order_id'] ?? 0 ) );
			if ( strlen( $at ) < 10 ) { continue; }
			$milestones[] = array( 'kind' => 'order', 'at' => $at, 'ref' => $ref > 0 ? $ref : null, 'status' => '' !== $status ? $status : null, 'evidence_state' => 'confirmed' );
		}

		usort( $milestones, static function ( $a, $b ) {
			return (int) strtotime( (string) $b['at'] ) <=> (int) strtotime( (string) $a['at'] );
		} );

		return array(
			'stage'            => $paid_orders >= 2 ? 'repeat' : ( $paid_orders >= 1 ? 'buyer' : null ),
			'paid_order_count' => $paid_orders,
			'last_touch_at'    => $last_touch_at,
			'previously_cared' => $previously_cared,
			'milestones'       => array_slice( $milestones, 0, 5 ),
		);
	}

	/** Only the current member's own care tasks; a colleague's tasks on the same customer never reach C. */
	public static function own_tasks( array $tasks, int $member_id ): array {
		return array_values( array_filter( $tasks, static function ( $task ) use ( $member_id ) {
			return is_array( $task ) && (int) ( $task['assignee_id'] ?? 0 ) === $member_id;
		} ) );
	}

	// ── schema shapers ───────────────────────────────────────────────────

	private static function shape_task( array $task ): ?array {
		$task_id = (int) ( $task['task_id'] ?? 0 );
		$title   = self::cut( (string) ( $task['title'] ?? '' ), 190 );
		$status  = (string) ( $task['status'] ?? '' );
		if ( $task_id <= 0 || '' === $title || ! in_array( $status, self::TASK_STATUSES, true ) ) { return null; }
		$out = array(
			'task_id'     => $task_id,
			'title'       => $title,
			'status'      => $status,
			'overdue'     => ! empty( $task['overdue'] ),
			'due_at'      => isset( $task['due_at'] ) && '' !== (string) $task['due_at'] ? (string) $task['due_at'] : null,
			'assigned_by' => array( 'display_name' => self::cut( (string) ( $task['assigned_by']['display_name'] ?? '' ), 120 ) ),
			'seen'        => ! empty( $task['seen'] ),
			'can'         => array_values( array_unique( array_intersect( (array) ( $task['can'] ?? array() ), self::MEMBER_ACTIONS ) ) ),
		);
		if ( isset( $task['instructions'] ) && '' !== (string) $task['instructions'] ) { $out['instructions'] = self::cut( (string) $task['instructions'], 2000 ); }
		if ( in_array( (string) ( $task['priority'] ?? '' ), self::TASK_PRIORITIES, true ) ) { $out['priority'] = (string) $task['priority']; }
		return $out;
	}

	private static function shape_order( array $order ): ?array {
		$order_id = (int) ( $order['id'] ?? ( $order['order_id'] ?? 0 ) );
		$status   = sanitize_key( str_replace( 'wc-', '', (string) ( $order['status'] ?? '' ) ) );
		if ( $order_id <= 0 || ! preg_match( '/^[a-z0-9_-]{2,32}$/', $status ) ) { return null; }
		$total = $order['total'] ?? 0;
		$out = array(
			'order_id' => $order_id,
			'status'   => $status,
			'total'    => is_numeric( $total ) ? (float) $total : (string) $total,
		);
		$currency = strtoupper( (string) ( $order['currency'] ?? '' ) );
		if ( preg_match( '/^[A-Z]{3}$/', $currency ) ) { $out['currency'] = $currency; }
		$created = (string) ( $order['created_at'] ?? ( $order['date_created'] ?? '' ) );
		$out['created_at'] = '' !== $created ? $created : null;
		return $out;
	}

	private static function cut( string $value, int $max ): string {
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max ) : substr( $value, 0, $max );
	}
}
