<?php
/**
 * BizCity CRM — "Chuyển phụ trách khách" (PHASE-0.50 W1/W2, §4.4 + R-ZP-OWNER 0.48E §E4.2).
 *
 * Moving a customer to another employee is NOT a column write: it reassigns that customer's
 * conversations (`BizCity_CRM_Repository::set_conversation_assignee`, which emits
 * `crm_conversation_assigned`) inside inboxes the receiver already owns, and writes an audit row.
 *
 * Two invariants come straight from the rules and are enforced here, not in the UI:
 *   - The actor may only move customers they can see (`Inbox_Access::allowed_inbox_ids`).
 *   - The receiver must already reach the conversation through THEIR OWN inbox scope
 *     (`resolve_scope( user, 'be', force_user_scope = true )`). A Zalo Personal inbox belonging to
 *     someone else never appears there, so this can never hand over another agent's personal thread;
 *     that needs `POST /crm-phones/{inbox_id}/transfer-owner` (R-ZP-OWNER) or inbox membership first.
 *
 * @package BizCity_Twin_CRM
 * @since   PHASE-0.50 2026-09-18
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Contact_Transfer' ) ) { return; }

final class BizCity_CRM_Contact_Transfer {

	const MAX_CONTACTS = 200;
	/** Conversations moved per customer; a customer with more than this is rare and the rest keep their assignee. */
	const MAX_CONVERSATIONS_PER_CONTACT = 50;
	const IDEMPOTENCY_TTL = 600;

	/**
	 * @param array $payload contact_ids[], to_user_id, out_of_scope_policy (reject|strip), client_request_id
	 * @return array|WP_Error
	 */
	public static function transfer( int $actor_id, array $payload ) {
		if ( $actor_id <= 0 ) { return self::error( 'auth_required', 'Cần đăng nhập.', 401 ); }
		if ( ! class_exists( 'BizCity_CRM_Staff_Policy' ) || ! class_exists( 'BizCity_CRM_Inbox_Access' ) || ! class_exists( 'BizCity_CRM_Repository' ) ) {
			return self::error( 'module_not_loaded', 'Chưa bật quản lý nhân viên.', 503 );
		}
		$to_user_id = (int) ( $payload['to_user_id'] ?? 0 );
		if ( $to_user_id <= 0 || ! BizCity_CRM_Staff_Policy::is_assignable_user( $to_user_id ) ) {
			return self::error( 'assignee_invalid', 'Người nhận không hợp lệ.', 422 );
		}
		// Taking a customer back for yourself only needs the action rank; handing to someone else
		// also needs them to be inside the actor's team (Staff_Policy subject rules).
		$decision = $to_user_id === $actor_id
			? BizCity_CRM_Staff_Policy::can( $actor_id, 'conv.transfer' )
			: BizCity_CRM_Staff_Policy::can( $actor_id, 'conv.transfer', $to_user_id );
		if ( ! $decision['ok'] ) {
			return self::error( 'member_not_manageable', $decision['why'] ?: 'Bạn không chuyển được khách cho nhân viên này.', 403 );
		}

		$contact_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $payload['contact_ids'] ?? array() ) ) ) ) );
		if ( empty( $contact_ids ) ) { return self::error( 'contacts_required', 'Chọn ít nhất một khách.', 422 ); }
		if ( count( $contact_ids ) > self::MAX_CONTACTS ) {
			return self::error( 'too_many_subjects', 'Tối đa ' . self::MAX_CONTACTS . ' khách mỗi lần chuyển.', 422 );
		}
		$policy = 'strip' === ( $payload['out_of_scope_policy'] ?? '' ) ? 'strip' : 'reject';

		$request_key = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) ( $payload['client_request_id'] ?? '' ) );
		$idem_key = '' !== $request_key ? 'bzc_ctransfer_' . md5( get_current_blog_id() . '|' . $actor_id . '|' . $request_key ) : '';
		if ( '' !== $idem_key ) {
			$previous = get_transient( $idem_key );
			if ( is_array( $previous ) ) { return array_merge( $previous, array( 'duplicate' => true ) ); }
		}

		$actor_inboxes = BizCity_CRM_Inbox_Access::allowed_inbox_ids( $actor_id ); // null = tenant admin.
		$to_inboxes = class_exists( 'BizCity_CRM_Task_Handoff' ) ? BizCity_CRM_Task_Handoff::user_inbox_ids( $to_user_id ) : array();
		if ( empty( $to_inboxes ) ) {
			return self::error( 'transfer_target_out_of_scope', 'Người nhận chưa phụ trách SĐT/inbox nào.', 422, array( 'hint' => 'Gán SĐT hoặc thêm họ vào inbox trước khi chuyển khách.' ) );
		}

		$planned = array();
		$stripped = array();
		foreach ( $contact_ids as $contact_id ) {
			$actor_sees = null === $actor_inboxes || ( class_exists( 'BizCity_CRM_Task_Handoff' ) && BizCity_CRM_Task_Handoff::contact_in_inboxes( $contact_id, (array) $actor_inboxes ) );
			if ( ! $actor_sees ) {
				$stripped[] = array( 'contact_id' => $contact_id, 'reason' => 'contact_not_in_scope' );
				continue;
			}
			$conversations = self::contact_conversations_in_inboxes( $contact_id, $to_inboxes );
			if ( empty( $conversations ) ) {
				$stripped[] = array( 'contact_id' => $contact_id, 'reason' => 'transfer_target_out_of_scope' );
				continue;
			}
			$planned[ $contact_id ] = $conversations;
		}
		if ( ! empty( $stripped ) && 'reject' === $policy ) {
			return self::error(
				'transfer_target_out_of_scope',
				count( $stripped ) . ' khách không nằm trong kênh của người nhận.',
				422,
				array( 'stripped' => $stripped, 'hint' => 'Chuyển SĐT cho họ (Chuyển SĐT), thêm họ vào inbox, hoặc bỏ số khách này ra.' )
			);
		}
		if ( empty( $planned ) ) {
			return self::error( 'transfer_target_out_of_scope', 'Không còn khách nào thuộc kênh của người nhận.', 422, array( 'stripped' => $stripped ) );
		}

		$moved = array();
		$conversations_moved = 0;
		foreach ( $planned as $contact_id => $conversations ) {
			$per_contact = 0;
			$from_ids = array();
			foreach ( $conversations as $conv ) {
				if ( (int) $conv['assignee_id'] === $to_user_id ) { continue; }
				if ( (int) $conv['assignee_id'] > 0 ) { $from_ids[ (int) $conv['assignee_id'] ] = true; }
				if ( BizCity_CRM_Repository::set_conversation_assignee( (int) $conv['id'], $to_user_id, $actor_id, array( 'reason' => 'contact_owner_transferred' ) ) ) {
					$per_contact++;
				}
			}
			$conversations_moved += $per_contact;
			$moved[] = array(
				'contact_id'          => (int) $contact_id,
				'conversations_moved' => $per_contact,
				'from_user_ids'       => array_map( 'intval', array_keys( $from_ids ) ),
			);
			if ( class_exists( 'BizCity_CRM_Audit_Log' ) ) {
				BizCity_CRM_Audit_Log::log(
					'crm_contact',
					(int) $contact_id,
					'owner_transferred',
					array( 'assignee_ids' => array_map( 'intval', array_keys( $from_ids ) ) ),
					array( 'assignee_id' => $to_user_id, 'conversations_moved' => $per_contact ),
					array( 'user_id' => $actor_id )
				);
			}
		}

		$result = array(
			'ok'                  => true,
			'to_user_id'          => $to_user_id,
			'contacts_moved'      => count( $moved ),
			'conversations_moved' => $conversations_moved,
			'moved'               => $moved,
			'stripped'            => $stripped,
		);
		if ( '' !== $idem_key ) { set_transient( $idem_key, $result, self::IDEMPOTENCY_TTL ); }
		/** Zone 2 notification hook — ids and counts only, never customer names or phones. */
		do_action( 'bizcity_crm_contact_owner_transferred', $to_user_id, $actor_id, array_column( $moved, 'contact_id' ) );
		return $result;
	}

	/**
	 * Conversations of one customer inside the given inboxes, newest first.
	 *
	 * @return array<int,array{id:int,inbox_id:int,assignee_id:int}>
	 */
	private static function contact_conversations_in_inboxes( int $contact_id, array $inbox_ids ): array {
		if ( $contact_id <= 0 || empty( $inbox_ids ) ) { return array(); }
		global $wpdb;
		$conv = BizCity_CRM_DB_Installer_V2::tbl_conversations();
		$ci   = BizCity_CRM_DB_Installer_V2::tbl_contact_inboxes();
		$ph   = implode( ',', array_fill( 0, count( $inbox_ids ), '%d' ) );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT c.id, c.inbox_id, c.assignee_id FROM `{$conv}` c
			 WHERE c.inbox_id IN ({$ph})
			   AND ( c.contact_id = %d OR c.contact_inbox_id IN ( SELECT id FROM `{$ci}` WHERE contact_id = %d ) )
			 ORDER BY c.last_activity_at DESC, c.id DESC
			 LIMIT " . self::MAX_CONVERSATIONS_PER_CONTACT,
			array_merge( array_map( 'intval', $inbox_ids ), array( $contact_id, $contact_id ) )
		), ARRAY_A );
		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array( 'id' => (int) $row['id'], 'inbox_id' => (int) $row['inbox_id'], 'assignee_id' => (int) ( $row['assignee_id'] ?? 0 ) );
		}
		return $out;
	}

	private static function error( string $code, string $message, int $status, array $extra = array() ) {
		return new WP_Error( $code, $message, array_merge( array( 'status' => $status ), $extra ) );
	}
}
