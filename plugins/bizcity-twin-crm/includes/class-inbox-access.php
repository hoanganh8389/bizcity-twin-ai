<?php
/**
 * BizCity CRM — account-backed inbox access policy.
 *
 * MVP-0 scopes Zalo Personal CRM access to the WordPress owner of the
 * Personal account. Administrators retain tenant-wide CRM access only on an
 * explicitly classified BE/admin surface.
 *
 * @package BizCity_Twin_CRM
 * @since   PHASE-0.39B 2026-08-21
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Inbox_Access' ) ) {
	return;
}

final class BizCity_CRM_Inbox_Access {

	public static function resolve_user_inbox_scope( int $user_id = 0, string $surface = 'c' ): array {
		// [2026-09-08 01:27 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.41-CX0 — produce the public user-centric Inbox scope without raw phone/account identifiers.
		$user_id = $user_id > 0 ? $user_id : (int) get_current_user_id();
		$scope_key = 'b2' === strtolower( $surface ) ? 'b2' : 'c';
		$surface_code = 'b2' === $scope_key ? 'B2_ADMIN_CRM' : 'C_PUBLIC_TWINGPT';
		// [2026-09-08 02:10 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.41-CX1 — preserve the caller's B2/C policy boundary instead of labeling a C scope as B2.
		// [2026-09-08 03:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.41-CX2 — B2 selected principals stay owner/membership-scoped even when the selected user is an administrator.
		$scope = self::resolve_scope( $user_id, $scope_key, 'b2' === $scope_key );
		$inbox_ids = array_key_exists( 'inbox_ids', $scope ) && null === $scope['inbox_ids']
			? null
			: ( isset( $scope['inbox_ids'] ) && is_array( $scope['inbox_ids'] ) ? array_values( array_unique( array_map( 'intval', $scope['inbox_ids'] ) ) ) : array() );
		$owned_personal_ids = self::personal_owner_inbox_ids( $user_id );
		$customer = array();
		$admin = array();
		if ( ( null === $inbox_ids || ! empty( $inbox_ids ) ) && class_exists( 'BizCity_CRM_DB_Installer_V2' ) ) {
			global $wpdb;
			$table = BizCity_CRM_DB_Installer_V2::tbl_inboxes();
			if ( null === $inbox_ids ) {
				// [2026-09-08 02:09 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.41-CX1 — materialize B2 tenant scope while retaining exact-owner filtering for Personal accounts.
				$rows = $wpdb->get_results( "SELECT id, name, channel_type, channel_ref_id FROM `{$table}` WHERE is_active = 1 ORDER BY id ASC", ARRAY_A );
			} else {
				$placeholders = implode( ',', array_fill( 0, count( $inbox_ids ), '%d' ) );
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, name, channel_type, channel_ref_id FROM `{$table}` WHERE id IN ({$placeholders}) AND is_active = 1 ORDER BY id ASC", $inbox_ids ), ARRAY_A );
			}
			foreach ( is_array( $rows ) ? $rows : array() as $row ) {
				$inbox_id = (int) ( $row['id'] ?? 0 );
				$channel = sanitize_key( (string) ( $row['channel_type'] ?? '' ) );
				$account_ref = (string) ( $row['channel_ref_id'] ?? '' );
				$allowed_customer_channels = array( 'facebook', 'messenger', 'zalo_oa', 'zalo_personal', 'webchat', 'email', 'cf7', 'instagram', 'whatsapp' );
				if ( $inbox_id <= 0 || ! in_array( $channel, $allowed_customer_channels, true ) || $account_ref === '' ) { continue; }
				$is_personal = 'zalo_personal' === $channel;
				if ( $is_personal && ! in_array( $inbox_id, $owned_personal_ids, true ) ) { continue; }
				$account_label = sanitize_text_field( (string) ( $row['name'] ?? '' ) );
				if ( $account_label === '' ) { $account_label = ucwords( str_replace( '_', ' ', $channel ) ); }
				$item = array(
					'scope_id' => 'inbox_' . $inbox_id,
					'branch' => 'customer',
					'zone' => 'customer',
					'channel' => $channel,
					'access_mode' => $is_personal ? 'owner_only' : 'membership',
					'account_key' => substr( hash_hmac( 'sha256', $channel . '|' . $account_ref, wp_salt( 'auth' ) ), 0, 32 ),
					'account_label' => $account_label,
					'crm_mode' => 'customer_inbox',
					'capabilities' => $is_personal
						? array( 'conversation.read', 'conversation.reply', 'contact.read', 'group.roster.read', 'context.read' )
						: array( 'conversation.read', 'contact.read', 'context.read' ),
					'context_policy' => 'conversation_summary',
				);
				$item = apply_filters( 'bizcity_user_inbox_scope_customer_item', $item, $row, $user_id, $surface_code );
				$item = self::normalize_public_scope_item( $item, 'customer' );
				if ( ! empty( $item ) ) { $customer[] = $item; }
			}
		}
		$admin_candidates = apply_filters( 'bizcity_user_inbox_scope_admin_items', array(), $user_id, $surface_code );
		foreach ( is_array( $admin_candidates ) ? $admin_candidates : array() as $item ) {
			$item = self::normalize_public_scope_item( $item, 'admin' );
			if ( ! empty( $item ) ) { $admin[] = $item; }
		}
		return array(
			'contract' => 'user-inbox-scope',
			'version' => '1.0.0',
			'surface' => $surface_code,
			'principal' => array( 'blog_id' => (int) get_current_blog_id(), 'user_id' => $user_id ),
			'phone_spine' => self::resolve_phone_spine( $user_id ),
			'branches' => array( 'customer' => array_values( $customer ), 'admin' => array_values( $admin ) ),
			'denied' => array(),
		);
	}

	public static function resolve_user_contact_projection( int $user_id = 0, string $surface = 'c', int $limit = 100 ): array {
		// [2026-09-08 02:09 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.41-CX1 — expose one redacted Contacts projection from the same B2/C Inbox scope owner.
		$user_id = $user_id > 0 ? $user_id : (int) get_current_user_id();
		$scope_key = 'b2' === strtolower( $surface ) ? 'b2' : 'c';
		$surface_code = 'b2' === $scope_key ? 'B2_ADMIN_CRM' : 'C_PUBLIC_TWINGPT';
		if ( 'c' === $scope_key && $user_id !== (int) get_current_user_id() ) {
			return array( 'projection_version' => '1.0.0', 'surface' => $surface_code, 'principal' => array( 'blog_id' => (int) get_current_blog_id(), 'user_id' => $user_id ), 'user_scope' => null, 'contacts' => array(), 'count' => 0, '_denied' => true );
		}
		$public_scope = self::resolve_user_inbox_scope( $user_id, $scope_key );
		$scope = self::resolve_scope( $user_id, $scope_key, 'b2' === $scope_key );
		$allowed_inbox_ids = array_key_exists( 'inbox_ids', $scope ) ? $scope['inbox_ids'] : array();
		if ( ! class_exists( 'BizCity_CRM_Repository' ) || ! method_exists( 'BizCity_CRM_Repository', 'list_contacts_for_inbox_scope' ) ) {
			return array( 'projection_version' => '1.0.0', 'surface' => $surface_code, 'principal' => $public_scope['principal'], 'user_scope' => $public_scope, 'contacts' => array(), 'count' => 0, '_degraded' => true );
		}

		$scope_items = array();
		foreach ( (array) ( $public_scope['branches']['customer'] ?? array() ) as $item ) {
			$scope_id = (string) ( $item['scope_id'] ?? '' );
			if ( preg_match( '/^inbox_(\d+)$/', $scope_id, $matches ) ) {
				$scope_items[ (int) $matches[1] ] = $item;
			}
		}
		$contacts = array();
		foreach ( BizCity_CRM_Repository::list_contacts_for_inbox_scope( $allowed_inbox_ids, $limit ) as $contact ) {
			$source_memberships = array();
			$last_activity_at = null;
			foreach ( (array) ( $contact['memberships'] ?? array() ) as $membership ) {
				$inbox_id = (int) ( $membership['inbox_id'] ?? 0 );
				if ( ! isset( $scope_items[ $inbox_id ] ) ) { continue; }
				$scope_item = $scope_items[ $inbox_id ];
				$source_id = (string) ( $membership['source_id'] ?? '' );
				$channel = sanitize_key( (string) ( $scope_item['channel'] ?? $membership['channel_type'] ?? '' ) );
				$activity_at = $membership['last_activity_at'] ?? null;
				if ( $activity_at && ( null === $last_activity_at || strcmp( (string) $activity_at, (string) $last_activity_at ) > 0 ) ) { $last_activity_at = $activity_at; }
				$source_memberships[] = array(
					'membership_key' => substr( hash_hmac( 'sha256', 'contact-inbox|' . (int) ( $membership['contact_inbox_id'] ?? 0 ), wp_salt( 'auth' ) ), 0, 32 ),
					'inbox_id' => $inbox_id,
					'channel_code' => $channel,
					'source_kind' => 0 === strpos( $source_id, 'group:' ) ? 'group' : 'direct',
					'account_key' => (string) ( $scope_item['account_key'] ?? '' ),
					'account_label' => (string) ( $scope_item['account_label'] ?? ucwords( str_replace( '_', ' ', $channel ) ) ),
					'provider_contact_key' => substr( hash_hmac( 'sha256', $channel . '|' . $source_id, wp_salt( 'auth' ) ), 0, 32 ),
					'conversation_id' => ! empty( $membership['conversation_id'] ) ? (int) $membership['conversation_id'] : null,
					'conversation_status' => sanitize_key( (string) ( $membership['conversation_status'] ?? '' ) ),
					'last_activity_at' => $activity_at,
				);
			}
			if ( empty( $source_memberships ) ) { continue; }
			$contacts[] = array(
				'contact_id' => (int) ( $contact['contact_id'] ?? 0 ),
				'display_name' => sanitize_text_field( (string) ( $contact['display_name'] ?? '' ) ),
				'source_memberships' => $source_memberships,
				'source_count' => count( $source_memberships ),
				'last_activity_at' => $last_activity_at,
			);
		}
		return array(
			'projection_version' => '1.0.0',
			'surface' => $surface_code,
			'principal' => $public_scope['principal'],
			'user_scope' => $public_scope,
			'contacts' => $contacts,
			'count' => count( $contacts ),
			'member_safe' => true,
		);
	}

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
	* The C surface must pass 'c' so a manage_options user does not inherit
	* tenant-wide scope merely by opening /gpt/.
	 *
	 * @return array{scope_type:string,user_id:int,inbox_ids:int[]|null,channel_types:string[],sources:array,field_projection:array}
	 */
	public static function resolve_scope( int $user_id = 0, string $surface = 'be', bool $force_user_scope = false ): array {
		// [2026-08-25 Johnny Chu] PHASE-0.39F-F7 — centralize admin/owner/member scope for future /gpt/ projections.
		$user_id = $user_id > 0 ? $user_id : (int) get_current_user_id();
		// [2026-08-29 Johnny Chu] PHASE-0-RULE-TWIN-GPT-FIRST-USER-ID-PII-SURFACE — keep C /gpt/ requests out of tenant-wide admin scope.
		// [2026-09-08 03:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.41-CX2 — only explicit selected-user adapters may suppress tenant-admin widening.
		if ( ! $force_user_scope && 'c' !== strtolower( $surface ) && self::is_admin( $user_id ) ) {
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
		// [2026-09-08 01:02 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.41-CX0 — never let C Inbox membership widen access to another user's Zalo Personal inbox.
		$member_ids = self::filter_c_personal_inbox_membership( $member_ids, $personal_ids );
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

	private static function filter_c_personal_inbox_membership( array $member_ids, array $owned_personal_ids ): array {
		// [2026-09-08 01:02 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.41-CX0 — business Inbox membership remains shareable; Personal membership is owner-only.
		$member_ids = array_values( array_unique( array_map( 'intval', $member_ids ) ) );
		if ( empty( $member_ids ) || ! class_exists( 'BizCity_CRM_DB_Installer_V2' ) ) {
			return $member_ids;
		}
		global $wpdb;
		$inboxes = BizCity_CRM_DB_Installer_V2::tbl_inboxes();
		$placeholders = implode( ',', array_fill( 0, count( $member_ids ), '%d' ) );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, channel_type FROM `{$inboxes}` WHERE id IN ({$placeholders})", $member_ids ), ARRAY_A );
		$owned_personal_ids = array_map( 'intval', $owned_personal_ids );
		$filtered = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$inbox_id = (int) ( $row['id'] ?? 0 );
			$channel = sanitize_key( (string) ( $row['channel_type'] ?? '' ) );
			if ( $inbox_id <= 0 ) { continue; }
			if ( 'zalo_personal' === $channel && ! in_array( $inbox_id, $owned_personal_ids, true ) ) { continue; }
			$filtered[] = $inbox_id;
		}
		return array_values( array_unique( $filtered ) );
	}

	private static function resolve_phone_spine( int $user_id ): array {
		// [2026-09-08 01:27 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.41-CX0 — expose correlation readiness without treating billing_phone as verified authority.
		$raw = $user_id > 0 ? (string) get_user_meta( $user_id, 'billing_phone', true ) : '';
		$normalized = class_exists( 'BizCity_Phone_Normalizer' )
			? (string) BizCity_Phone_Normalizer::normalize_vn( $raw )
			: (string) preg_replace( '/\D+/', '', $raw );
		if ( $normalized === '' ) { return array( 'status' => 'absent' ); }
		$length = strlen( $normalized );
		$masked = $length > 4 ? substr( $normalized, 0, 2 ) . str_repeat( '*', max( 2, $length - 4 ) ) . substr( $normalized, -2 ) : str_repeat( '*', $length );
		return array(
			'status' => 'unverified',
			'phone_key' => substr( hash_hmac( 'sha256', $normalized, wp_salt( 'auth' ) ), 0, 32 ),
			'phone_masked' => $masked,
		);
	}

	private static function normalize_public_scope_item( $item, string $expected_branch ): array {
		// [2026-09-08 01:27 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.41-CX0 — prevent extension filters from leaking raw identifiers or invalid branch semantics.
		if ( ! is_array( $item ) || ! in_array( $expected_branch, array( 'customer', 'admin' ), true ) ) { return array(); }
		$customer_channels = array( 'facebook', 'messenger', 'zalo_oa', 'zalo_personal', 'webchat', 'email', 'cf7', 'instagram', 'whatsapp' );
		$admin_channels = array( 'zalo_bot', 'telegram', 'twinchat_be' );
		$channel = sanitize_key( (string) ( $item['channel'] ?? '' ) );
		$account_key = strtolower( sanitize_text_field( (string) ( $item['account_key'] ?? '' ) ) );
		$scope_id = sanitize_key( (string) ( $item['scope_id'] ?? '' ) );
		$allowed_channels = 'customer' === $expected_branch ? $customer_channels : $admin_channels;
		if ( ! in_array( $channel, $allowed_channels, true ) || ! preg_match( '/^[a-f0-9]{16,64}$/', $account_key ) || strlen( $scope_id ) < 3 ) { return array(); }
		$access_mode = sanitize_key( (string) ( $item['access_mode'] ?? '' ) );
		$crm_mode = sanitize_key( (string) ( $item['crm_mode'] ?? '' ) );
		$context_policy = sanitize_key( (string) ( $item['context_policy'] ?? '' ) );
		if ( 'customer' === $expected_branch ) {
			if ( 'zalo_personal' === $channel && 'owner_only' !== $access_mode ) { return array(); }
			if ( ! in_array( $access_mode, array( 'owner_only', 'owner_or_membership', 'membership' ), true ) || 'customer_inbox' !== $crm_mode || ! in_array( $context_policy, array( 'conversation_summary', 'none' ), true ) ) { return array(); }
		} elseif ( 'linked_user' !== $access_mode || 'disabled' !== $crm_mode || ! in_array( $context_policy, array( 'admin_command', 'none' ), true ) ) {
			return array();
		}
		$allowed_capabilities = array( 'conversation.read', 'conversation.reply', 'conversation.assign', 'contact.read', 'contact.write', 'group.roster.read', 'group.mention', 'attachment.send', 'broadcast.send', 'context.read', 'brain.command' );
		$capabilities = array();
		foreach ( (array) ( $item['capabilities'] ?? array() ) as $capability ) {
			$capability = strtolower( trim( (string) $capability ) );
			if ( in_array( $capability, $allowed_capabilities, true ) ) { $capabilities[] = $capability; }
		}
		$capabilities = array_values( array_unique( $capabilities ) );
		$account_label = self::mask_phone_in_label( sanitize_text_field( (string) ( $item['account_label'] ?? '' ) ) );
		if ( $account_label === '' ) { $account_label = ucwords( str_replace( '_', ' ', $channel ) ); }
		return array(
			'scope_id' => $scope_id,
			'branch' => $expected_branch,
			'zone' => $expected_branch,
			'channel' => $channel,
			'access_mode' => $access_mode,
			'account_key' => $account_key,
			'account_label' => $account_label,
			'crm_mode' => $crm_mode,
			'capabilities' => $capabilities,
			'context_policy' => $context_policy,
		);
	}

	private static function mask_phone_in_label( string $label ): string {
		// [2026-09-08 01:27 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.41-CX0 — public scope labels never expose raw phone-like values.
		$masked = preg_replace_callback( '/(?<!\d)(?:\+?84|0)[\d .-]{7,14}\d(?!\d)/', static function ( array $matches ): string {
			$digits = (string) preg_replace( '/\D+/', '', (string) $matches[0] );
			$length = strlen( $digits );
			return $length > 4 ? substr( $digits, 0, 2 ) . str_repeat( '*', max( 2, $length - 4 ) ) . substr( $digits, -2 ) : str_repeat( '*', $length );
		}, $label );
		return is_string( $masked ) ? $masked : '';
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