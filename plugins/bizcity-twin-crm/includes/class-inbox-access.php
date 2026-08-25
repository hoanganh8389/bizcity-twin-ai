<?php
/**
 * BizCity CRM — account-backed inbox access policy.
 *
 * MVP-0 scopes Zalo Personal CRM access to the WordPress owner of the
 * Personal account. Administrators retain tenant-wide CRM access.
 *
 * @package BizCity_Twin_CRM
 * @since   PHASE-0.39B 2026-08-21
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Inbox_Access' ) ) {
	return;
}

final class BizCity_CRM_Inbox_Access {

	public static function is_admin( int $user_id = 0 ): bool {
		// [2026-08-21 Johnny Chu] PHASE-0.39B — tenant-wide CRM admin gate.
		$user_id = $user_id > 0 ? $user_id : (int) get_current_user_id();
		return $user_id > 0 && user_can( $user_id, 'manage_options' );
	}

	/**
	 * @param int $user_id
	 * @return int[]|null Null means tenant-wide administrator access.
	 */
	public static function allowed_inbox_ids( int $user_id = 0 ) {
		// [2026-08-21 Johnny Chu] PHASE-0.39B — resolve Personal owner inbox scope without a new ACL table.
		$user_id = $user_id > 0 ? $user_id : (int) get_current_user_id();
		if ( self::is_admin( $user_id ) ) {
			return null;
		}
		if ( $user_id <= 0 || ! class_exists( 'BizCity_Zalo_Mapping_Repo' ) ) {
			return array();
		}
		$rows = BizCity_Zalo_Mapping_Repo::list_personal_accounts_for_owner( $user_id );
		$ids  = array();
		foreach ( $rows as $row ) {
			$inbox_id = (int) ( $row['crm_inbox_id'] ?? 0 );
			if ( $inbox_id > 0 ) {
				$ids[] = $inbox_id;
			}
		}
		// [2026-08-24 Johnny Chu] PHASE-0.39F-F7 — union explicit tenant-local Inbox Members for Facebook, Messenger, OA, WebChat and Email scopes.
		if ( class_exists( 'BizCity_CRM_DB_Installer_V2' ) && BizCity_CRM_DB_Installer_V2::table_exists( BizCity_CRM_DB_Installer_V2::tbl_inbox_members() ) ) {
			global $wpdb;
			$member_table = BizCity_CRM_DB_Installer_V2::tbl_inbox_members();
			$member_ids = $wpdb->get_col( $wpdb->prepare( "SELECT inbox_id FROM `{$member_table}` WHERE user_id = %d AND is_active = 1", $user_id ) );
			$ids = array_merge( $ids, array_map( 'intval', is_array( $member_ids ) ? $member_ids : array() ) );
		}
		return array_values( array_unique( $ids ) );
	}

	public static function can_view_inbox( int $inbox_id, int $user_id = 0 ): bool {
		// [2026-08-21 Johnny Chu] PHASE-0.39B — enforce inbox row scope.
		if ( $inbox_id <= 0 ) {
			return false;
		}
		$allowed = self::allowed_inbox_ids( $user_id );
		return null === $allowed || in_array( $inbox_id, $allowed, true );
	}

	public static function can_view_conversation( int $conversation_id, int $user_id = 0 ): bool {
		// [2026-08-21 Johnny Chu] PHASE-0.39B — enforce conversation scope through its inbox.
		if ( $conversation_id <= 0 || ! class_exists( 'BizCity_CRM_Repository' ) ) {
			return false;
		}
		$conversation = BizCity_CRM_Repository::get_conversation( $conversation_id );
		return is_array( $conversation ) && self::can_view_inbox( (int) ( $conversation['inbox_id'] ?? 0 ), $user_id );
	}
}