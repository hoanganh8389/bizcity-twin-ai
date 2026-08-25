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
		// [2026-08-25 Johnny Chu] PHASE-0.39F-F7 — keep the legacy ID API backed by the structured scope resolver.
		$user_id = $user_id > 0 ? $user_id : (int) get_current_user_id();
		$scope = self::resolve_scope( $user_id );
		return $scope['inbox_ids'];
	}

	/**
	 * Resolve the current-blog CRM scope without trusting posted resource IDs.
	 *
	 * @return array{scope_type:string,user_id:int,inbox_ids:int[]|null,channel_types:string[],sources:array,field_projection:array}
	 */
	public static function resolve_scope( int $user_id = 0 ): array {
		// [2026-08-25 Johnny Chu] PHASE-0.39F-F7 — centralize admin/owner/member scope for future /gpt/ projections.
		$user_id = $user_id > 0 ? $user_id : (int) get_current_user_id();
		if ( self::is_admin( $user_id ) ) {
			return array(
				'scope_type' => 'admin',
				'user_id' => $user_id,
				'inbox_ids' => null,
				'channel_types' => array( '*' ),
				'sources' => array( 'tenant_admin' => true ),
				'field_projection' => array( 'operator_safe' => true, 'message_content' => true, 'private_notes' => true ),
			);
		}
		if ( $user_id <= 0 ) {
			return self::empty_scope( $user_id );
		}
		$personal_ids = self::personal_owner_inbox_ids( $user_id );
		$member_ids = self::inbox_member_ids( $user_id );
		$ids = array_values( array_unique( array_merge( $personal_ids, $member_ids ) ) );
		if ( empty( $ids ) ) {
			return self::empty_scope( $user_id, array( 'zalo_personal_owner' => false, 'inbox_member' => false ) );
		}
		$channel_types = array();
		if ( class_exists( 'BizCity_CRM_DB_Installer_V2' ) ) {
			global $wpdb;
			$inboxes = BizCity_CRM_DB_Installer_V2::tbl_inboxes();
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$rows = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT channel_type FROM `{$inboxes}` WHERE id IN ({$placeholders})", $ids ) );
			$channel_types = array_values( array_unique( array_filter( array_map( 'sanitize_key', is_array( $rows ) ? $rows : array() ) ) ) );
		}
		return array(
			'scope_type' => 'owner_or_member',
			'user_id' => $user_id,
			'inbox_ids' => $ids,
			'channel_types' => $channel_types,
			'sources' => array( 'zalo_personal_owner' => ! empty( $personal_ids ), 'inbox_member' => ! empty( $member_ids ) ),
			'field_projection' => array( 'operator_safe' => false, 'message_content' => true, 'private_notes' => false, 'provider_identifiers' => false ),
		);
	}

	private static function personal_owner_inbox_ids( int $user_id ): array {
		if ( $user_id <= 0 || ! class_exists( 'BizCity_Zalo_Mapping_Repo' ) ) { return array(); }
		$ids = array();
		foreach ( (array) BizCity_Zalo_Mapping_Repo::list_personal_accounts_for_owner( $user_id ) as $row ) {
			$inbox_id = (int) ( $row['crm_inbox_id'] ?? 0 );
			if ( $inbox_id > 0 ) { $ids[] = $inbox_id; }
		}
		return array_values( array_unique( $ids ) );
	}

	private static function inbox_member_ids( int $user_id ): array {
		if ( $user_id <= 0 || ! class_exists( 'BizCity_CRM_DB_Installer_V2' ) || ! BizCity_CRM_DB_Installer_V2::table_exists( BizCity_CRM_DB_Installer_V2::tbl_inbox_members() ) ) { return array(); }
		global $wpdb;
		$member_table = BizCity_CRM_DB_Installer_V2::tbl_inbox_members();
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT inbox_id FROM `{$member_table}` WHERE user_id = %d AND is_active = 1", $user_id ) );
		return array_values( array_unique( array_map( 'intval', is_array( $ids ) ? $ids : array() ) ) );
	}

	private static function empty_scope( int $user_id, array $sources = array() ): array {
		return array(
			'scope_type' => 'empty',
			'user_id' => $user_id,
			'inbox_ids' => array(),
			'channel_types' => array(),
			'sources' => $sources,
			'field_projection' => array( 'operator_safe' => false, 'message_content' => false, 'private_notes' => false, 'provider_identifiers' => false ),
		);
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