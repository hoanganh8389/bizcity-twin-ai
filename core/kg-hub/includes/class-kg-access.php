<?php
/**
 * BizCity KG access resolver — PHASE-0.57A.
 *
 * One server-side read policy for notebook, workspace and Brain surfaces.
 * Workspaces always have a real owner; visibility only adds read access.
 *
 * @package Bizcity_Twin_AI
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_KG_Access', false ) ) {
	return;
}

final class BizCity_KG_Access {

	const VISIBILITY_PRIVATE = 'private';
	const VISIBILITY_SHARED  = 'shared';
	const VISIBILITY_PUBLIC  = 'public';
	const OPTION_DEFAULT_NOTEBOOK = 'bizcity_twin_default_notebook_id';
	const OPTION_MIGRATION_PREFIX = 'bizcity_kg_workspace_migrated_';
	const OPTION_ACL_GENERATION = 'bizcity_kg_acl_gen';

	/**
	 * Return a SQL predicate for a notebook alias. User ids are safely cast to
	 * integers before interpolation so callers do not need to duplicate ACL
	 * placeholder ordering across selector tiers.
	 */
	public static function readable_where( $user_id, $alias = '' ) {
		self::migrate_legacy_workspaces( (int) $user_id );
		$id    = (int) $user_id;
		$viewer_id = $id > 0 ? $id : (int) get_current_user_id();
		$nb    = $alias;
		$grant = self::grants_table();
		$work  = self::workspaces_table();
		$role  = self::user_role( $viewer_id );
		$role_sql = '';
		if ( $role !== '' ) {
			$role_sql = " OR (g.grantee_type = 'role' AND g.grantee_ref = '" . esc_sql( $role ) . "')";
		}

		$workspace_id = "CAST(JSON_UNQUOTE(JSON_EXTRACT({$nb}settings, '$.workspace_id')) AS UNSIGNED)";
		$workspace_override = "COALESCE(JSON_UNQUOTE(JSON_EXTRACT({$nb}settings, '$.visibility_override')), '')";
		$deleted = "COALESCE(JSON_UNQUOTE(JSON_EXTRACT({$nb}settings, '$.deleted_at')), '') = ''";
		return "({$deleted} AND (({$nb}owner_id = %d AND {$nb}owner_id <> 0)
			OR ({$nb}owner_id = 0 AND {$nb}notebook_scope IN ('business_kb','guru_kb'))
			OR ({$workspace_override} <> 'private' AND EXISTS (
				SELECT 1 FROM {$work} ws
				WHERE ws.id = {$workspace_id} AND ws.deleted_at IS NULL
				AND (ws.visibility = 'public' OR EXISTS (
					SELECT 1 FROM {$grant} g
					WHERE g.object_type = 'workspace' AND g.object_id = ws.id
					AND g.revoked_at IS NULL AND g.permission = 'view'
					AND (g.grantee_type = 'user' AND g.grantee_ref = '{$id}'{$role_sql})
				))
			))
			OR EXISTS (
				SELECT 1 FROM {$grant} ng
				WHERE ng.object_type = 'notebook' AND ng.object_id = {$nb}id
				AND ng.revoked_at IS NULL AND ng.permission = 'view'
				AND ((ng.grantee_type = 'user' AND ng.grantee_ref = '{$id}')" . ( $role !== '' ? " OR (ng.grantee_type = 'role' AND ng.grantee_ref = '" . esc_sql( $role ) . "')" : '' ) . ")
			)))";
	}

		/**
		 * Canonical access predicate used by all retrieval surfaces.
		 *
		 * @param int    $user_id Viewer id.
		 * @param string $surface Surface identifier retained for diagnostics.
		 * @param string $alias SQL alias including trailing dot.
		 * @return string
		 */
		public static function readable_notebooks_where( $user_id, $surface = 'twinbrain', $alias = '' ) {
			unset( $surface );
			return self::readable_where( $user_id, $alias );
		}

	public static function can_read_notebook( $notebook_id, $user_id, $surface = 'twinbrain' ) {
		if ( (int) $notebook_id <= 0 || (int) $user_id <= 0 || ! class_exists( 'BizCity_KG_Database' ) ) {
			return false;
		}
		global $wpdb;
		$table = BizCity_KG_Database::instance()->tbl_notebooks();
		$sql = $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d AND " . self::readable_where( $user_id ) . ' LIMIT 1', (int) $notebook_id, (int) $user_id );
		return (bool) $wpdb->get_var( $sql );
	}

	public static function can_manage_notebook( $notebook_id, $user_id ) {
		$nb = class_exists( 'BizCity_KG_Notebook_Service' ) ? BizCity_KG_Notebook_Service::instance()->get( (int) $notebook_id ) : null;
		return is_array( $nb ) && ( (int) ( $nb['owner_id'] ?? 0 ) === (int) $user_id || user_can( (int) $user_id, 'manage_options' ) );
	}

	/** View grants never confer write access. */
	public static function can_manage( $object_type, $object_id, $user_id ) {
		if ( 'workspace' === sanitize_key( $object_type ) ) {
			return self::can_manage_workspace( (int) $object_id, (int) $user_id );
		}
		return self::can_manage_notebook( (int) $object_id, (int) $user_id );
	}

	public static function grants_table() {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_kg_grants';
	}

	public static function workspaces_table() {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_kg_workspaces';
	}

	public static function acl_log_table() {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_kg_acl_log';
	}

	/**
	 * Migrate old per-user workspace JSON once per blog/user. The legacy meta is
	 * retained as a rollback source until an operator removes it explicitly.
	 */
	public static function migrate_legacy_workspaces( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 || ! class_exists( 'BizCity_KG_Database' ) ) {
			return false;
		}
		$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		$option  = self::OPTION_MIGRATION_PREFIX . $blog_id . '_' . $user_id;
		if ( get_option( $option, '' ) === 'complete' ) {
			return true;
		}
		$meta_key = 'bizcity_kg_workspaces' . ( $blog_id > 1 ? '_' . $blog_id : '' );
		$raw      = get_user_meta( $user_id, $meta_key, true );
		$list     = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : $raw;
		if ( ! is_array( $list ) || empty( $list ) ) {
			update_option( $option, 'complete', false );
			return true;
		}

		global $wpdb;
		$db         = BizCity_KG_Database::instance();
		$workspaces = self::workspaces_table();
		$notebooks  = $db->tbl_notebooks();
		$legacy_map = array();
		$now        = current_time( 'mysql', true );
		$rows       = $wpdb->get_results( $wpdb->prepare( "SELECT id, settings FROM {$notebooks} WHERE owner_id = %d", $user_id ), ARRAY_A );

		foreach ( $list as $workspace ) {
			if ( ! is_array( $workspace ) || empty( $workspace['id'] ) || empty( $workspace['name'] ) ) {
				continue;
			}
			$legacy_id = sanitize_key( $workspace['id'] );
			$found = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$workspaces} WHERE owner_id = %d AND JSON_UNQUOTE(JSON_EXTRACT(settings, '$.legacy_id')) = %s LIMIT 1", $user_id, $legacy_id ), ARRAY_A );
			if ( $found ) {
				$legacy_map[ $legacy_id ] = (int) $found['id'];
				continue;
			}
			$wpdb->insert( $workspaces, array(
				'uuid'       => wp_generate_uuid4(),
				'owner_id'   => $user_id,
				'parent_id'  => null,
				'title'      => sanitize_text_field( $workspace['name'] ),
				'icon'       => 'folder',
				'sort_order' => isset( $workspace['sort'] ) ? (int) $workspace['sort'] : 0,
				'visibility' => self::normalize_visibility( $workspace['visibility'] ?? 'private' ),
				'settings'   => wp_json_encode( array( 'legacy_id' => $legacy_id ), JSON_UNESCAPED_UNICODE ),
				'created_at' => isset( $workspace['createdAt'] ) ? sanitize_text_field( $workspace['createdAt'] ) : $now,
				'updated_at' => $now,
			) );
			$legacy_map[ $legacy_id ] = (int) $wpdb->insert_id;
		}

		foreach ( $rows ?: array() as $row ) {
			$settings = json_decode( (string) ( $row['settings'] ?? '' ), true );
			if ( ! is_array( $settings ) || empty( $settings['workspace_id'] ) ) {
				continue;
			}
			$legacy_id = sanitize_key( (string) $settings['workspace_id'] );
			if ( empty( $legacy_map[ $legacy_id ] ) ) {
				continue;
			}
			$settings['workspace_id'] = (string) $legacy_map[ $legacy_id ];
			$wpdb->update( $notebooks, array( 'settings' => wp_json_encode( $settings, JSON_UNESCAPED_UNICODE ) ), array( 'id' => (int) $row['id'] ) );
		}
		update_option( $option, 'complete', false );
		return true;
	}

	public static function generation() {
		$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		return max( 1, (int) get_option( self::OPTION_ACL_GENERATION . '_' . $blog_id, 1 ) );
	}

	public static function bump_generation() {
		$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		$next = self::generation() + 1;
		update_option( self::OPTION_ACL_GENERATION . '_' . $blog_id, $next, false );
		return $next;
	}

	public static function normalize_visibility( $visibility ) {
		$visibility = sanitize_key( (string) $visibility );
		return in_array( $visibility, array( self::VISIBILITY_PRIVATE, self::VISIBILITY_SHARED, self::VISIBILITY_PUBLIC ), true ) ? $visibility : self::VISIBILITY_PRIVATE;
	}

	public static function user_role( $user_id ) {
		if ( class_exists( 'BizCity_CRM_Staff_Policy' ) ) {
			$role = BizCity_CRM_Staff_Policy::role( (int) $user_id );
			return in_array( $role, array( 'admin', 'supervisor', 'lead', 'agent' ), true ) ? $role : '';
		}
		return user_can( (int) $user_id, 'manage_options' ) ? 'admin' : '';
	}

	public static function can_manage_workspace( $workspace_id, $user_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT owner_id FROM ' . self::workspaces_table() . ' WHERE id = %d AND deleted_at IS NULL', (int) $workspace_id ), ARRAY_A );
		if ( ! $row ) {
			return false;
		}
		if ( (int) $row['owner_id'] === (int) $user_id || user_can( (int) $user_id, 'manage_options' ) ) {
			return true;
		}
		return self::user_role( $user_id ) === 'supervisor';
	}

	public static function list_workspaces( $user_id ) {
		global $wpdb;
		$id = (int) $user_id;
		$work = self::workspaces_table();
		$grant = self::grants_table();
		$role = self::user_role( $id );
		$role_clause = $role !== '' ? " OR (g.grantee_type = 'role' AND g.grantee_ref = '" . esc_sql( $role ) . "')" : '';
		$sql = "SELECT DISTINCT ws.* FROM {$work} ws
			LEFT JOIN {$grant} g ON g.object_type = 'workspace' AND g.object_id = ws.id AND g.revoked_at IS NULL AND g.permission = 'view'
			WHERE ws.deleted_at IS NULL AND (ws.owner_id = {$id} OR ws.visibility = 'public' OR (g.grantee_type = 'user' AND g.grantee_ref = '{$id}'{$role_clause}))
			ORDER BY ws.parent_id IS NOT NULL ASC, ws.sort_order ASC, ws.id ASC";
		return $wpdb->get_results( $sql, ARRAY_A ) ?: array();
	}

	public static function ensure_workspace_for_notebook( $notebook_id, $owner_id, $title = 'Đào tạo Twin CRM', $visibility = self::VISIBILITY_PUBLIC ) {
		global $wpdb;
		$notebook_id = (int) $notebook_id;
		$owner_id    = (int) $owner_id;
		if ( $notebook_id <= 0 || $owner_id <= 0 || ! self::can_publish( $owner_id ) ) {
			return new WP_Error( 'kg_workspace_publish_forbidden', 'Không đủ quyền tạo workspace công khai.' );
		}
		$nb_table = BizCity_KG_Database::instance()->tbl_notebooks();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT settings FROM {$nb_table} WHERE id = %d", $notebook_id ), ARRAY_A );
		$settings = $row && ! empty( $row['settings'] ) ? json_decode( (string) $row['settings'], true ) : array();
		$settings = is_array( $settings ) ? $settings : array();
		$workspace_id = isset( $settings['workspace_id'] ) && ctype_digit( (string) $settings['workspace_id'] ) ? (int) $settings['workspace_id'] : 0;
		if ( $workspace_id > 0 ) {
			$workspace = $wpdb->get_row( $wpdb->prepare( 'SELECT id FROM ' . self::workspaces_table() . ' WHERE id = %d AND owner_id = %d AND deleted_at IS NULL', $workspace_id, $owner_id ), ARRAY_A );
			if ( $workspace ) {
				self::set_workspace_visibility( $workspace_id, $owner_id, $visibility );
				return $workspace_id;
			}
		}
		$wpdb->insert( self::workspaces_table(), array(
			'uuid'       => wp_generate_uuid4(),
			'owner_id'   => $owner_id,
			'parent_id'  => null,
			'title'      => sanitize_text_field( $title ),
			'icon'       => 'book-open',
			'sort_order' => 0,
			'visibility' => self::normalize_visibility( $visibility ),
			'settings'   => wp_json_encode( array( 'origin' => 'crm_training' ), JSON_UNESCAPED_UNICODE ),
			'created_at' => current_time( 'mysql', true ),
			'updated_at' => current_time( 'mysql', true ),
		) );
		$workspace_id = (int) $wpdb->insert_id;
		if ( $workspace_id <= 0 ) {
			return new WP_Error( 'kg_workspace_create_failed', 'Không tạo được workspace đào tạo.' );
		}
		$settings['workspace_id'] = (string) $workspace_id;
		$settings['visibility_override'] = null;
		$wpdb->update( $nb_table, array( 'settings' => wp_json_encode( $settings, JSON_UNESCAPED_UNICODE ) ), array( 'id' => $notebook_id ) );
		self::log( 'workspace', $workspace_id, 'created_for_notebook', $owner_id, array( 'notebook_id' => $notebook_id ) );
		return $workspace_id;
	}

	public static function can_publish( $user_id ) {
		return user_can( (int) $user_id, 'manage_options' ) || self::user_role( $user_id ) === 'supervisor';
	}

	public static function notebook_has_channel_source( $notebook_id ) {
		global $wpdb;
		if ( ! class_exists( 'BizCity_KG_Database' ) ) {
			return false;
		}
		$db = BizCity_KG_Database::instance();
		$source_table = $db->tbl_sources();
		$legacy_table = $wpdb->prefix . 'bizcity_twinchat_sources';
		$found = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$source_table} WHERE scope_type = 'notebook' AND scope_id = %s AND (origin_plugin IN ('zalo','facebook','messenger','telegram','channel-gateway','twinchat-channel') OR origin_kind IN ('channel','message','inbox')) LIMIT 1", (string) $notebook_id ) );
		if ( $found ) {
			return true;
		}
		if ( function_exists( 'bizcity_tbl_exists' ) && bizcity_tbl_exists( $legacy_table ) ) {
			return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$legacy_table} WHERE notebook_id = %d AND (source_type IN ('zalo','facebook','messenger','telegram','inbox','message') OR metadata LIKE %s) LIMIT 1", (int) $notebook_id, '%"channel"%' ) );
		}
		return false;
	}

	public static function set_workspace_visibility( $workspace_id, $actor_id, $visibility ) {
		global $wpdb;
		$visibility = self::normalize_visibility( $visibility );
		if ( ! self::can_manage_workspace( $workspace_id, $actor_id ) || ( $visibility === self::VISIBILITY_PUBLIC && ! self::can_publish( $actor_id ) ) ) {
			return new WP_Error( 'kg_workspace_visibility_forbidden', 'Bạn không có quyền đổi phạm vi workspace.' );
		}
		if ( $visibility === self::VISIBILITY_PUBLIC ) {
			global $wpdb;
			$notebook_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM " . BizCity_KG_Database::instance()->tbl_notebooks() . " WHERE JSON_UNQUOTE(JSON_EXTRACT(settings, '$.workspace_id')) = %s", (string) (int) $workspace_id ) );
			foreach ( $notebook_ids as $notebook_id ) {
				if ( self::notebook_has_channel_source( $notebook_id ) ) {
					return new WP_Error( 'kg_public_channel_source_blocked', 'Workspace chứa dữ liệu kênh khách nên không thể công khai.', array( 'status' => 403 ) );
				}
			}
		}
		$ok = $wpdb->update( self::workspaces_table(), array( 'visibility' => $visibility, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => (int) $workspace_id ) );
		if ( false === $ok ) {
			return new WP_Error( 'kg_workspace_visibility_failed', 'Không lưu được phạm vi workspace.' );
		}
		self::bump_generation();
		self::log( 'workspace', $workspace_id, 'visibility_changed', $actor_id, array( 'visibility' => $visibility ) );
		return true;
	}

	public static function log_guru_change( $notebook_id, $actor_id, $action, array $payload = array() ) {
		if ( ! self::can_manage_notebook( $notebook_id, $actor_id ) ) return false;
		self::bump_generation();
		self::log( 'notebook', (int) $notebook_id, sanitize_key( $action ), (int) $actor_id, $payload );
		return true;
	}

	public static function list_grants( $object_type, $object_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			'SELECT id, object_type, object_id, grantee_type, grantee_ref, permission, created_by, created_at FROM ' . self::grants_table() . ' WHERE object_type = %s AND object_id = %d AND revoked_at IS NULL ORDER BY id ASC',
			sanitize_key( $object_type ),
			(int) $object_id
		), ARRAY_A ) ?: array();
	}

	public static function grant_view( $object_type, $object_id, $grantee_type, $grantee_ref, $actor_id ) {
		global $wpdb;
		$object_type  = sanitize_key( $object_type );
		$grantee_type = sanitize_key( $grantee_type );
		$grantee_ref  = sanitize_text_field( (string) $grantee_ref );
		if ( ! in_array( $object_type, array( 'workspace', 'notebook' ), true ) || ! in_array( $grantee_type, array( 'user', 'role', 'team' ), true ) || '' === $grantee_ref ) {
			return new WP_Error( 'kg_grant_invalid', 'Cấp quyền xem không hợp lệ.' );
		}
		if ( ! self::can_manage( $object_type, $object_id, $actor_id ) || ! self::valid_grantee( $grantee_type, $grantee_ref, $actor_id ) ) {
			return new WP_Error( 'kg_grant_forbidden', 'Bạn không có quyền quản lý quyền xem.' );
		}
		if ( 'workspace' === $object_type && self::workspace_contains_channel_source( $object_id ) ) {
			return new WP_Error( 'kg_grant_channel_source_blocked', 'Workspace chứa dữ liệu kênh khách không thể chia sẻ.', array( 'status' => 403 ) );
		}
		$existing = $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM ' . self::grants_table() . ' WHERE object_type = %s AND object_id = %d AND grantee_type = %s AND grantee_ref = %s AND revoked_at IS NULL LIMIT 1',
			$object_type, (int) $object_id, $grantee_type, $grantee_ref
		) );
		if ( $existing ) {
			return (int) $existing;
		}
		$wpdb->insert( self::grants_table(), array(
			'object_type'  => $object_type,
			'object_id'    => (int) $object_id,
			'grantee_type' => $grantee_type,
			'grantee_ref'  => $grantee_ref,
			'permission'   => 'view',
			'created_by'   => (int) $actor_id,
			'created_at'   => current_time( 'mysql', true ),
		) );
		if ( ! $wpdb->insert_id ) {
			return new WP_Error( 'kg_grant_create_failed', 'Không lưu được quyền xem.' );
		}
		self::bump_generation();
		self::log( $object_type, $object_id, 'grant_view', $actor_id, array( 'grantee_type' => $grantee_type, 'grantee_ref' => $grantee_ref ) );
		return (int) $wpdb->insert_id;
	}

	private static function workspace_contains_channel_source( $workspace_id ) {
		global $wpdb;
		$notebook_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM " . BizCity_KG_Database::instance()->tbl_notebooks() . " WHERE JSON_UNQUOTE(JSON_EXTRACT(settings, '$.workspace_id')) = %s", (string) (int) $workspace_id ) );
		foreach ( $notebook_ids as $notebook_id ) {
			if ( self::notebook_has_channel_source( $notebook_id ) ) {
				return true;
			}
		}
		return false;
	}

	private static function valid_grantee( $grantee_type, $grantee_ref, $actor_id ) {
		if ( 'user' === $grantee_type ) {
			return (int) $grantee_ref > 0 && get_user_by( 'id', (int) $grantee_ref );
		}
		if ( 'role' === $grantee_type ) {
			return self::can_share_role( $actor_id ) && in_array( sanitize_key( $grantee_ref ), array( 'supervisor', 'lead', 'agent' ), true );
		}
		return self::can_share_role( $actor_id ) && preg_match( '/^[A-Za-z0-9_-]{1,80}$/', (string) $grantee_ref );
	}

	private static function can_share_role( $actor_id ) {
		return user_can( (int) $actor_id, 'manage_options' ) || self::user_role( $actor_id ) === 'supervisor';
	}

	public static function revoke_grant( $grant_id, $actor_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::grants_table() . ' WHERE id = %d AND revoked_at IS NULL', (int) $grant_id ), ARRAY_A );
		if ( ! $row || ! self::can_manage( $row['object_type'], (int) $row['object_id'], $actor_id ) ) {
			return new WP_Error( 'kg_grant_revoke_forbidden', 'Không thể thu hồi quyền xem.' );
		}
		$wpdb->update( self::grants_table(), array( 'revoked_at' => current_time( 'mysql', true ) ), array( 'id' => (int) $grant_id ) );
		self::bump_generation();
		self::log( $row['object_type'], (int) $row['object_id'], 'revoke_view', $actor_id, array( 'grant_id' => (int) $grant_id ) );
		return true;
	}

	public static function soft_delete_notebook( $notebook_id, $actor_id ) {
		if ( ! self::can_manage_notebook( $notebook_id, $actor_id ) ) {
			return new WP_Error( 'kg_notebook_delete_forbidden', 'Bạn không có quyền xoá notebook này.', array( 'status' => 403 ) );
		}
		global $wpdb;
		$db = BizCity_KG_Database::instance();
		$notebook_id = (int) $notebook_id;
		$owner_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT owner_id FROM ' . $db->tbl_notebooks() . ' WHERE id = %d', $notebook_id ) );
		$current = $wpdb->get_var( $wpdb->prepare( 'SELECT settings FROM ' . $db->tbl_notebooks() . ' WHERE id = %d', $notebook_id ) );
		$settings = json_decode( (string) $current, true );
		$settings = is_array( $settings ) ? $settings : array();
		$settings['deleted_at'] = current_time( 'mysql', true );
		do_action( 'bizcity_kg_notebook_before_soft_delete', (int) $notebook_id, (int) $actor_id );
		$now = current_time( 'mysql', true );
		$wpdb->update( $db->tbl_notebooks(), array( 'settings' => wp_json_encode( $settings, JSON_UNESCAPED_UNICODE ) ), array( 'id' => $notebook_id ) );
		$grants = $wpdb->get_results( $wpdb->prepare( 'SELECT grantee_type, grantee_ref FROM ' . self::grants_table() . ' WHERE object_type = %s AND object_id = %d AND revoked_at IS NULL', 'notebook', $notebook_id ), ARRAY_A );
		$wpdb->update( self::grants_table(), array( 'revoked_at' => $now ), array( 'object_type' => 'notebook', 'object_id' => (int) $notebook_id, 'revoked_at' => null ) );
		$wpdb->delete( $db->tbl_notebook_character_attachments(), array( 'notebook_id' => (int) $notebook_id ) );
		if ( $owner_id > 0 ) {
			delete_user_meta( $owner_id, 'bizcity_twin_sticky_guru_' . $notebook_id );
		}
		foreach ( $grants ?: array() as $grant ) {
			if ( ( $grant['grantee_type'] ?? '' ) === 'user' && (int) $grant['grantee_ref'] > 0 ) {
				delete_user_meta( (int) $grant['grantee_ref'], 'bizcity_twin_sticky_guru_' . $notebook_id );
			}
		}
		do_action( 'bizcity_kg_notebook_soft_deleted', $notebook_id, $owner_id, $grants );
		self::bump_generation();
		self::log( 'notebook', $notebook_id, 'soft_deleted', $actor_id );
		return true;
	}

	/**
	 * PHASE-0.57A §3.6 "Xoá workspace" — mirrors `soft_delete_notebook()`. Direct
	 * child workspaces and notebooks pointing at this workspace are un-parented
	 * (not cascade-deleted) rather than left dangling on a soft-deleted row —
	 * same "gỡ, không mất dữ liệu" intent as the legacy FE fallback to `ws_default`,
	 * but the new ACL-backed table has no canonical "default" row to fall back to.
	 */
	public static function soft_delete_workspace( $workspace_id, $actor_id ) {
		if ( ! self::can_manage_workspace( $workspace_id, $actor_id ) ) {
			return new WP_Error( 'kg_workspace_delete_forbidden', 'Bạn không có quyền xoá workspace này.', array( 'status' => 403 ) );
		}
		global $wpdb;
		$workspace_id = (int) $workspace_id;
		$actor_id     = (int) $actor_id;
		$table        = self::workspaces_table();
		$now          = current_time( 'mysql', true );
		do_action( 'bizcity_kg_workspace_before_soft_delete', $workspace_id, $actor_id );
		$wpdb->update( $table, array( 'deleted_at' => $now, 'updated_at' => $now ), array( 'id' => $workspace_id ) );
		$wpdb->update( $table, array( 'parent_id' => null, 'updated_at' => $now ), array( 'parent_id' => $workspace_id ) );
		if ( class_exists( 'BizCity_KG_Database' ) ) {
			$nb_table = BizCity_KG_Database::instance()->tbl_notebooks();
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$nb_table} SET settings = JSON_REMOVE(settings, '$.workspace_id') WHERE JSON_UNQUOTE(JSON_EXTRACT(settings, '$.workspace_id')) = %s",
				(string) $workspace_id
			) );
		}
		$wpdb->update( self::grants_table(), array( 'revoked_at' => $now ), array( 'object_type' => 'workspace', 'object_id' => $workspace_id, 'revoked_at' => null ) );
		self::bump_generation();
		self::log( 'workspace', $workspace_id, 'soft_deleted', $actor_id );
		return true;
	}

	/**
	 * PHASE-0.57A §3.6/UI-12 "Chuyển chủ" — admin-only (§3.7 table: chỉ Admin, không
	 * supervisor/lead/chủ). Grants are left untouched (they attach to the object, not
	 * the owner); the new owner does not need a pre-existing grant of their own.
	 */
	public static function transfer_owner( $object_type, $object_id, $new_owner_id, $actor_id ) {
		global $wpdb;
		$object_type  = sanitize_key( $object_type );
		$object_id    = (int) $object_id;
		$new_owner_id = (int) $new_owner_id;
		$actor_id     = (int) $actor_id;
		if ( ! in_array( $object_type, array( 'workspace', 'notebook' ), true ) || $object_id <= 0 ) {
			return new WP_Error( 'kg_transfer_invalid', 'Đối tượng không hợp lệ.' );
		}
		if ( $new_owner_id <= 0 || ! get_user_by( 'id', $new_owner_id ) ) {
			return new WP_Error( 'kg_transfer_owner_invalid', 'Người nhận không hợp lệ.' );
		}
		if ( ! user_can( $actor_id, 'manage_options' ) ) {
			return new WP_Error( 'kg_transfer_forbidden', 'Chỉ quản trị viên mới được chuyển chủ.', array( 'status' => 403 ) );
		}
		if ( 'workspace' === $object_type ) {
			$table = self::workspaces_table();
			$old_owner_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT owner_id FROM {$table} WHERE id = %d AND deleted_at IS NULL", $object_id ) );
			if ( $old_owner_id <= 0 ) {
				return new WP_Error( 'kg_transfer_not_found', 'Không tìm thấy workspace.', array( 'status' => 404 ) );
			}
			$wpdb->update( $table, array( 'owner_id' => $new_owner_id, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $object_id ) );
		} else {
			$table = BizCity_KG_Database::instance()->tbl_notebooks();
			$old_owner_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT owner_id FROM {$table} WHERE id = %d", $object_id ) );
			if ( $old_owner_id <= 0 ) {
				return new WP_Error( 'kg_transfer_not_found', 'Không tìm thấy notebook.', array( 'status' => 404 ) );
			}
			$wpdb->update( $table, array( 'owner_id' => $new_owner_id ), array( 'id' => $object_id ) );
		}
		self::bump_generation();
		self::log( $object_type, $object_id, 'owner_transferred', $actor_id, array( 'from' => $old_owner_id, 'to' => $new_owner_id ) );
		return true;
	}

	private static function log( $object_type, $object_id, $action, $actor_id, array $payload = array() ) {
		global $wpdb;
		$wpdb->insert( self::acl_log_table(), array(
			'object_type' => sanitize_key( $object_type ),
			'object_id'   => (int) $object_id,
			'action'      => sanitize_key( $action ),
			'actor_id'    => (int) $actor_id,
			'payload'     => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ),
			'created_at'  => current_time( 'mysql', true ),
		) );
	}
}
