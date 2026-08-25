<?php
/**
 * BizCity CRM team, inbox membership and assignment policy foundation.
 *
 * WP users remain the identity source. These tables only describe tenant-local
 * work membership and never replace WordPress roles/capabilities.
 *
 * @package BizCity_Twin_CRM
 * @since   PHASE-0.39F 2026-08-24
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Team_Manager', false ) ) {
	return;
}

final class BizCity_CRM_Team_Manager {

	const CACHE_GROUP = 'crm_team';

	public static function list_teams( bool $active_only = true ): array {
		$cache_key = 'teams_' . ( $active_only ? 'active' : 'all' );
		if ( class_exists( 'BizCity_Cache' ) ) {
			$cached = BizCity_Cache::get( self::CACHE_GROUP, $cache_key );
			if ( false !== $cached && is_array( $cached ) ) { return $cached; }
		}
		global $wpdb;
		$table = BizCity_CRM_DB_Installer_V2::tbl_teams();
		$where = $active_only ? ' WHERE is_active = 1' : '';
		$rows = $wpdb->get_results( "SELECT * FROM `{$table}`{$where} ORDER BY name ASC, id ASC", ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();
		if ( class_exists( 'BizCity_Cache' ) ) { BizCity_Cache::set( self::CACHE_GROUP, $cache_key, $rows ); }
		return $rows;
	}

	public static function create_team( string $name, string $description = '', int $created_by = 0 ): int {
		$name = sanitize_text_field( trim( $name ) );
		if ( $name === '' ) {
			return 0;
		}
		global $wpdb;
		$table = BizCity_CRM_DB_Installer_V2::tbl_teams();
		$now = current_time( 'mysql' );
		$ok = $wpdb->insert( $table, array(
			'name'        => $name,
			'description' => sanitize_textarea_field( $description ),
			'created_by'  => $created_by > 0 ? $created_by : get_current_user_id(),
			'created_at'  => $now,
			'updated_at'  => $now,
		), array( '%s', '%s', '%d', '%s', '%s' ) );
		if ( ! $ok ) {
			return 0;
		}
		self::flush_cache();
		if ( class_exists( 'BizCity_CRM_Event_Emitter' ) ) {
			BizCity_CRM_Event_Emitter::emit( 'crm_team_created', array( 'team_id' => (int) $wpdb->insert_id, 'actor_user_id' => get_current_user_id() ) );
		}
		return (int) $wpdb->insert_id;
	}

	public static function add_team_member( int $team_id, int $user_id, string $member_role = 'agent' ): bool {
		if ( $team_id <= 0 || ! self::wp_user_exists( $user_id ) ) {
			return false;
		}
		$member_role = in_array( $member_role, array( 'agent', 'lead', 'supervisor' ), true ) ? $member_role : 'agent';
		global $wpdb;
		$table = BizCity_CRM_DB_Installer_V2::tbl_team_members();
		$now = current_time( 'mysql' );
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$table}` WHERE team_id = %d AND user_id = %d LIMIT 1", $team_id, $user_id ) );
		if ( $existing ) {
			$ok = false !== $wpdb->update( $table, array( 'member_role' => $member_role, 'is_active' => 1, 'updated_at' => $now ), array( 'id' => (int) $existing ), array( '%s', '%d', '%s' ), array( '%d' ) );
		} else {
			$ok = false !== $wpdb->insert( $table, array( 'team_id' => $team_id, 'user_id' => $user_id, 'member_role' => $member_role, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now ), array( '%d', '%d', '%s', '%d', '%s', '%s' ) );
		}
		if ( $ok ) {
			self::flush_cache();
			if ( class_exists( 'BizCity_Cache' ) ) { BizCity_Cache::flush_group( self::CACHE_GROUP ); }
		}
		return $ok;
	}

	public static function list_team_members( int $team_id ): array {
		$cache_key = 'team_members_' . (int) $team_id;
		if ( class_exists( 'BizCity_Cache' ) ) {
			$cached = BizCity_Cache::get( self::CACHE_GROUP, $cache_key );
			if ( false !== $cached && is_array( $cached ) ) { return $cached; }
		}
		global $wpdb;
		$table = BizCity_CRM_DB_Installer_V2::tbl_team_members();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT tm.*, u.display_name FROM `{$table}` tm LEFT JOIN {$wpdb->users} u ON u.ID = tm.user_id WHERE tm.team_id = %d AND tm.is_active = 1 ORDER BY tm.member_role DESC, u.display_name ASC", $team_id ), ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();
		if ( class_exists( 'BizCity_Cache' ) ) { BizCity_Cache::set( self::CACHE_GROUP, $cache_key, $rows ); }
		return $rows;
	}

	public static function list_inbox_members( int $inbox_id ): array {
		$cache_key = 'inbox_members_' . (int) $inbox_id;
		if ( class_exists( 'BizCity_Cache' ) ) {
			$cached = BizCity_Cache::get( self::CACHE_GROUP, $cache_key );
			if ( false !== $cached && is_array( $cached ) ) { return $cached; }
		}
		global $wpdb;
		$table = BizCity_CRM_DB_Installer_V2::tbl_inbox_members();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT im.*, u.display_name FROM `{$table}` im LEFT JOIN {$wpdb->users} u ON u.ID = im.user_id WHERE im.inbox_id = %d AND im.is_active = 1 ORDER BY im.member_role DESC, u.display_name ASC", $inbox_id ), ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();
		if ( class_exists( 'BizCity_Cache' ) ) { BizCity_Cache::set( self::CACHE_GROUP, $cache_key, $rows ); }
		return $rows;
	}

	public static function add_inbox_member( int $inbox_id, int $user_id, string $member_role = 'agent', bool $can_assign = false ): bool {
		if ( $inbox_id <= 0 || ! self::wp_user_exists( $user_id ) ) {
			return false;
		}
		$member_role = in_array( $member_role, array( 'agent', 'lead', 'observer' ), true ) ? $member_role : 'agent';
		global $wpdb;
		$table = BizCity_CRM_DB_Installer_V2::tbl_inbox_members();
		$now = current_time( 'mysql' );
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$table}` WHERE inbox_id = %d AND user_id = %d LIMIT 1", $inbox_id, $user_id ) );
		$data = array( 'member_role' => $member_role, 'can_assign' => $can_assign ? 1 : 0, 'is_active' => 1, 'updated_at' => $now );
		if ( $existing ) {
			$ok = false !== $wpdb->update( $table, $data, array( 'id' => (int) $existing ), array( '%s', '%d', '%d', '%s' ), array( '%d' ) );
		} else {
			$ok = false !== $wpdb->insert( $table, array_merge( array( 'inbox_id' => $inbox_id, 'user_id' => $user_id ), $data, array( 'created_at' => $now ) ), array( '%d', '%d', '%s', '%d', '%d', '%s', '%s' ) );
		}
		if ( $ok ) {
			self::flush_cache();
			if ( class_exists( 'BizCity_Cache' ) ) { BizCity_Cache::flush_group( self::CACHE_GROUP ); }
		}
		return $ok;
	}

	public static function is_inbox_member( int $inbox_id, int $user_id ): bool {
		if ( $inbox_id <= 0 || $user_id <= 0 ) {
			return false;
		}
		global $wpdb;
		$table = BizCity_CRM_DB_Installer_V2::tbl_inbox_members();
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$table}` WHERE inbox_id = %d AND user_id = %d AND is_active = 1 LIMIT 1", $inbox_id, $user_id ) );
	}

	public static function can_assign( int $conversation_id, int $actor_user_id, int $target_user_id ): bool {
		if ( $conversation_id <= 0 || $target_user_id <= 0 || ! self::wp_user_exists( $target_user_id ) ) {
			return false;
		}
		if ( user_can( $actor_user_id, 'manage_options' ) ) {
			return true;
		}
		$conversation = BizCity_CRM_Repository::get_conversation( $conversation_id );
		if ( ! is_array( $conversation ) ) {
			return false;
		}
		$inbox_id = (int) ( $conversation['inbox_id'] ?? 0 );
		if ( ! self::is_inbox_member( $inbox_id, $actor_user_id ) || ! self::is_inbox_member( $inbox_id, $target_user_id ) ) {
			return false;
		}
		$actor_roles = self::inbox_member_roles( $inbox_id, $actor_user_id );
		return ! empty( $actor_roles['can_assign'] ) || in_array( $actor_roles['member_role'], array( 'lead', 'supervisor' ), true );
	}

	private static function inbox_member_roles( int $inbox_id, int $user_id ): array {
		global $wpdb;
		$table = BizCity_CRM_DB_Installer_V2::tbl_inbox_members();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT member_role, can_assign FROM `{$table}` WHERE inbox_id = %d AND user_id = %d AND is_active = 1 LIMIT 1", $inbox_id, $user_id ), ARRAY_A );
		return is_array( $row ) ? $row : array();
	}

	private static function wp_user_exists( int $user_id ): bool {
		return $user_id > 0 && (bool) get_userdata( $user_id );
	}

	public static function flush_cache(): void {
		if ( class_exists( 'BizCity_Cache_Registry' ) ) {
			BizCity_Cache_Registry::flush_module( 'modules.twin-crm' );
		}
	}
}

if ( class_exists( 'BizCity_Cache_Registry' ) ) {
	BizCity_Cache_Registry::register( 'crm_team', 'modules.twin-crm', array(
		'teams_active' => array( 'ttl' => 300, 'desc' => 'Active tenant-local CRM teams' ),
	) );
}
