<?php
/**
 * BizCity Zalo Personal — the owner's own 1-1 chat with the number stays in the CRM as ONE conversation, but its contact
 * carries `role:owner` so it never counts as a customer (PHASE-0.87 CL-D2, R-WORK-PIPE: a contact's role is a tag).
 *
 * Owner = the sender whose Zalo UID equals the number's "UID chủ tài khoản" (Bot Studio `policy.owner_uid`), in a 1-1
 * thread — the same rule the cell uses for the Owner Agent (owner-agent-block@1 §2). Groups never tag anyone.
 *
 * // @axis twin-agent-axis@1 block owner_agent
 *
 * @package BizCity_Zalo_Personal
 * @since   PHASE-0.87 (2026-09-30)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Zalo_Owner_Contact', false ) ) {
	return;
}

final class BizCity_Zalo_Owner_Contact {

	const PLATFORM = 'ZALO_PERSONAL';

	/** Test seams: owner_uid(bridge_id): string · staff_uids(bridge_id): string[] · contact_of_message(crm_message_id): int · tag(contact_id[, role]): bool */
	public static $readers = array();

	/** Pure rule: 1-1 only, a non-empty owner UID, exact match. */
	public static function is_owner_message( string $from_uid, string $owner_uid, bool $is_group ): bool {
		$owner_uid = trim( $owner_uid );
		return ! $is_group && '' !== $owner_uid && '' !== $from_uid && hash_equals( $owner_uid, trim( $from_uid ) );
	}

	/**
	 * Called by the inbound emitter after the CRM row exists. Owner UID ⇒ `role:owner`; a UID on the number's
	 * "Người dùng Agent" list ⇒ `role:staff` (PHASE-0.87 W2-5, doc 50 §6.5; listed even when paused — still internal).
	 */
	public static function maybe_tag( string $bridge_id, string $from_uid, bool $is_group, int $crm_message_id ): bool {
		if ( $is_group || $crm_message_id <= 0 || '' === $from_uid ) {
			return false;
		}
		$role = '';
		if ( self::is_owner_message( $from_uid, self::owner_uid( $bridge_id ), false ) ) {
			$role = 'owner';
		} elseif ( in_array( trim( $from_uid ), self::staff_uids( $bridge_id ), true ) ) {
			$role = 'staff';
		}
		if ( '' === $role ) {
			return false;
		}
		$contact_id = self::contact_of_message( $crm_message_id );
		if ( $contact_id <= 0 ) {
			return false;
		}
		if ( isset( self::$readers['tag'] ) ) {
			return (bool) call_user_func( self::$readers['tag'], $contact_id, $role );
		}
		return class_exists( 'BizCity_CRM_Contact_Roles' ) && BizCity_CRM_Contact_Roles::add( $contact_id, $role );
	}

	/** @return string[] */
	private static function staff_uids( string $bridge_id ): array {
		if ( isset( self::$readers['staff_uids'] ) ) {
			return array_map( 'strval', (array) call_user_func( self::$readers['staff_uids'], $bridge_id ) );
		}
		if ( ! class_exists( 'BizCity_Zalo_Agent_Principals' ) ) {
			return array();
		}
		return array_map( static function ( $r ) { return (string) $r['zalo_uid']; }, BizCity_Zalo_Agent_Principals::from_policy( BizCity_Zalo_Agent_Principals::policy( $bridge_id ) ) );
	}

	private static function owner_uid( string $bridge_id ): string {
		if ( isset( self::$readers['owner_uid'] ) ) {
			return (string) call_user_func( self::$readers['owner_uid'], $bridge_id );
		}
		$b   = class_exists( 'BizCity_Channel_Binding' ) ? BizCity_Channel_Binding::resolve( self::PLATFORM, $bridge_id ) : null;
		$raw = is_array( $b ) ? ( $b['policy_json'] ?? '' ) : '';
		$p   = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
		return is_array( $p ) ? (string) ( $p['owner_uid'] ?? '' ) : '';
	}

	private static function contact_of_message( int $crm_message_id ): int {
		if ( isset( self::$readers['contact_of_message'] ) ) {
			return (int) call_user_func( self::$readers['contact_of_message'], $crm_message_id );
		}
		if ( ! class_exists( 'BizCity_CRM_Repository' ) ) {
			return 0;
		}
		$msg = BizCity_CRM_Repository::get_message( $crm_message_id );
		$cid = is_array( $msg ) ? (int) ( $msg['conversation_id'] ?? 0 ) : 0;
		return $cid > 0 ? BizCity_CRM_Repository::get_conversation_contact_id( $cid ) : 0;
	}
}
