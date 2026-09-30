<?php
/**
 * Twin Shell — module access REST (module-access@1.0.0, PHASE-0.84 W-17).
 *
 *   GET  bizcity-twinchat/v1/shell/module-access              roles × modules matrix
 *   GET  bizcity-twinchat/v1/shell/module-access/users        blog members + effective icons
 *   POST bizcity-twinchat/v1/shell/module-access/preview      how many people a role change affects
 *   POST bizcity-twinchat/v1/shell/module-access/roles        apply role changes
 *   POST bizcity-twinchat/v1/shell/module-access/users/{id}   apply one user's overrides
 *
 * Site admins only. Writes go to the one owner of each value: WordPress role/user capabilities for
 * `grantable` modules, the Twin GPT access policy for `gpt`. Other delegated and admin-only modules
 * are read-only here.
 *
 * Scope: site (per blog).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Modules\TwinShell
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Twin_Module_Access_REST {

	const NS        = 'bizcity-twinchat/v1';
	const PER_PAGE  = 50;
	const PREVIEW_CAP = 2000;

	private static $instance = null;
	private $registered = false;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register() {
		if ( $this->registered ) {
			return;
		}
		$this->registered = true;
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes() {
		$admin = [ $this, 'permission_admin' ];
		register_rest_route( self::NS, '/shell/module-access', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_matrix' ],
			'permission_callback' => $admin,
		] );
		register_rest_route( self::NS, '/shell/module-access/users', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_users' ],
			'permission_callback' => $admin,
			'args'                => [
				'search'         => [ 'type' => 'string', 'default' => '' ],
				'role'           => [ 'type' => 'string', 'default' => '' ],
				'overrides_only' => [ 'type' => 'boolean', 'default' => false ],
				'page'           => [ 'type' => 'integer', 'default' => 1, 'minimum' => 1 ],
			],
		] );
		register_rest_route( self::NS, '/shell/module-access/preview', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'preview' ],
			'permission_callback' => $admin,
		] );
		register_rest_route( self::NS, '/shell/module-access/roles', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'save_roles' ],
			'permission_callback' => $admin,
		] );
		register_rest_route( self::NS, '/shell/module-access/users/(?P<id>\d+)', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'save_user' ],
			'permission_callback' => $admin,
		] );
	}

	/** @return true|WP_Error */
	public function permission_admin() {
		if ( ! is_user_logged_in() ) {
			return self::error( 'auth_required', __( 'Please sign in again.', 'bizcity-twin-ai' ), __( 'Reload the page and sign in.', 'bizcity-twin-ai' ), 401 );
		}
		if ( ! BizCity_Twin_Module_Access::is_site_admin( get_current_user_id() ) ) {
			return self::error( 'capability_denied', __( 'Only the site administrator can manage module access.', 'bizcity-twin-ai' ), __( 'Ask the site administrator.', 'bizcity-twin-ai' ), 403 );
		}
		return true;
	}

	/* ── Reads ─────────────────────────────────────────────────────────── */

	public function get_matrix( WP_REST_Request $request ) {
		$roles   = self::roles();
		$modules = [];
		foreach ( self::governed_entries() as $entry ) {
			$cells = [];
			foreach ( $roles as $role ) {
				$cells[ $role['slug'] ] = self::role_cell( $entry, $role['slug'] );
			}
			$modules[] = self::module_summary( $entry ) + [ 'cells' => $cells ];
		}
		return rest_ensure_response( [
			'success'  => true,
			'contract' => 'module-access',
			'version'  => '1.0.0',
			'blog_id'  => (int) get_current_blog_id(),
			'roles'    => $roles,
			'modules'  => $modules,
		] );
	}

	public function get_users( WP_REST_Request $request ) {
		$page   = max( 1, (int) $request->get_param( 'page' ) );
		$search = trim( sanitize_text_field( (string) $request->get_param( 'search' ) ) );
		$role   = sanitize_key( (string) $request->get_param( 'role' ) );
		$args   = [
			'blog_id' => get_current_blog_id(),
			'number'  => self::PER_PAGE,
			'paged'   => $page,
			'orderby' => 'display_name',
			'order'   => 'ASC',
			'count_total' => true,
		];
		if ( '' !== $search ) {
			$args['search']         = '*' . $search . '*';
			$args['search_columns'] = [ 'user_login', 'user_email', 'display_name', 'user_nicename' ];
		}
		if ( '' !== $role && null !== get_role( $role ) ) {
			$args['role'] = $role;
		}
		if ( rest_sanitize_boolean( $request->get_param( 'overrides_only' ) ) ) {
			$ids = self::user_ids_with_overrides();
			if ( empty( $ids ) ) {
				return rest_ensure_response( [ 'success' => true, 'page' => $page, 'per_page' => self::PER_PAGE, 'total' => 0, 'users' => [] ] );
			}
			$args['include'] = $ids;
		}
		$query = new WP_User_Query( $args );
		$users = [];
		foreach ( (array) $query->get_results() as $user ) {
			$users[] = self::user_row( $user );
		}
		return rest_ensure_response( [
			'success'  => true,
			'page'     => $page,
			'per_page' => self::PER_PAGE,
			'total'    => (int) $query->get_total(),
			'users'    => $users,
		] );
	}

	/* ── Writes ────────────────────────────────────────────────────────── */

	public function preview( WP_REST_Request $request ) {
		$changes = self::read_role_changes( $request );
		if ( is_wp_error( $changes ) ) {
			return $changes;
		}
		$items       = [];
		$approximate = false;
		foreach ( $changes as $c ) {
			$count = self::affected_count( $c['entry'], $c['role'], $c['allowed'], $approximate );
			$items[] = [
				'module_id'      => $c['entry']['id'],
				'role'           => $c['role'],
				'allowed'        => $c['allowed'],
				'affected_users' => $count,
			];
		}
		return rest_ensure_response( [ 'success' => true, 'items' => $items, 'approximate' => $approximate ] );
	}

	public function save_roles( WP_REST_Request $request ) {
		$changes = self::read_role_changes( $request );
		if ( is_wp_error( $changes ) ) {
			return $changes;
		}
		$applied = [];
		$errors  = [];
		foreach ( $changes as $c ) {
			$before = self::role_cell( $c['entry'], $c['role'] )['state'];
			$result = self::apply_role_change( $c['entry'], $c['role'], $c['allowed'] );
			if ( is_wp_error( $result ) ) {
				$errors[] = [ 'module_id' => $c['entry']['id'], 'role' => $c['role'], 'code' => $result->get_error_code(), 'message' => $result->get_error_message() ];
				continue;
			}
			$after     = $c['allowed'] ? 'on' : 'off';
			$applied[] = [ 'module_id' => $c['entry']['id'], 'role' => $c['role'], 'allowed' => $c['allowed'] ];
			self::emit_changed( $c['entry']['id'], [ 'role' => $c['role'] ], $before, $after );
		}
		return rest_ensure_response( [ 'success' => empty( $errors ), 'applied' => $applied, 'errors' => $errors ] );
	}

	public function save_user( WP_REST_Request $request ) {
		$user_id = (int) $request['id'];
		$user    = get_userdata( $user_id );
		if ( ! $user || ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return self::error( 'user_not_in_site', __( 'This person is not a member of this website.', 'bizcity-twin-ai' ), __( 'Reload the list and pick again.', 'bizcity-twin-ai' ), 404 );
		}
		if ( BizCity_Twin_Module_Access::is_site_admin( $user_id ) || ( is_multisite() && is_super_admin( $user_id ) ) ) {
			return self::error( 'module_access_write_failed', __( 'Administrators always have every module.', 'bizcity-twin-ai' ), __( 'Change their WordPress role first if they should have less.', 'bizcity-twin-ai' ), 409 );
		}
		$overrides = $request->get_param( 'overrides' );
		if ( ! is_array( $overrides ) || empty( $overrides ) ) {
			return self::error( 'invalid_param', __( 'Nothing to save.', 'bizcity-twin-ai' ), __( 'Change at least one module, then save.', 'bizcity-twin-ai' ), 400 );
		}
		$errors = [];
		foreach ( $overrides as $module_id => $state ) {
			$module_id = sanitize_key( (string) $module_id );
			$state     = sanitize_key( (string) $state );
			$entry     = self::governed_entry( $module_id );
			if ( ! $entry || ! in_array( $state, [ 'inherit', 'allow', 'deny' ], true ) ) {
				$errors[] = [ 'module_id' => $module_id, 'code' => 'invalid_param', 'message' => __( 'Unknown module or value.', 'bizcity-twin-ai' ) ];
				continue;
			}
			$before = self::user_override( $entry, $user );
			$result = self::apply_user_override( $entry, $user, $state );
			if ( is_wp_error( $result ) ) {
				$errors[] = [ 'module_id' => $module_id, 'code' => $result->get_error_code(), 'message' => $result->get_error_message() ];
				continue;
			}
			if ( $before !== $state ) {
				self::emit_changed( $module_id, [ 'user_id' => $user_id ], (string) $before, $state );
			}
		}
		clean_user_cache( $user_id );
		return rest_ensure_response( [
			'success' => empty( $errors ),
			'errors'  => $errors,
			'user'    => self::user_row( new WP_User( $user_id ) ),
		] );
	}

	/* ── Helpers ───────────────────────────────────────────────────────── */

	/** @return array<int, array<string, mixed>> Governed entries in ActivityBar order. */
	private static function governed_entries() {
		$out = [];
		foreach ( BizCity_Twin_Shell_Registry::sort_for_activity_bar( BizCity_Twin_Shell_Registry::instance()->all() ) as $entry ) {
			if ( ! empty( $entry['access'] ) ) {
				$out[] = $entry;
			}
		}
		return $out;
	}

	private static function governed_entry( $module_id ) {
		$entry = BizCity_Twin_Shell_Registry::instance()->get( $module_id );
		return ( $entry && ! empty( $entry['access'] ) ) ? $entry : null;
	}

	/** @return array<int, array{slug:string,name:string,user_count:int}> */
	private static function roles() {
		$counts = count_users();
		$counts = isset( $counts['avail_roles'] ) ? (array) $counts['avail_roles'] : [];
		$out    = [];
		foreach ( wp_roles()->role_names as $slug => $name ) {
			$out[] = [
				'slug'       => (string) $slug,
				'name'       => translate_user_role( $name ),
				'user_count' => isset( $counts[ $slug ] ) ? (int) $counts[ $slug ] : 0,
			];
		}
		usort( $out, static function ( $a, $b ) {
			if ( 'administrator' === $a['slug'] ) {
				return -1;
			}
			if ( 'administrator' === $b['slug'] ) {
				return 1;
			}
			return $b['user_count'] - $a['user_count'];
		} );
		return $out;
	}

	private static function module_summary( array $entry ) {
		$manage = null;
		if ( ! empty( $entry['access']['manage']['plugin'] ) && class_exists( 'BizCity_Twin_Shell_Page' ) ) {
			$args = [ 'plugin' => $entry['access']['manage']['plugin'] ];
			if ( '' !== $entry['access']['manage']['r'] ) {
				$args['r'] = $entry['access']['manage']['r'];
			}
			$manage = [ 'url' => esc_url_raw( BizCity_Twin_Shell_Page::shell_url( $args ) ) ];
		}
		$order = isset( BizCity_Twin_Shell_Registry::ACTIVITY_ORDER[ $entry['id'] ] ) ? BizCity_Twin_Shell_Registry::ACTIVITY_ORDER[ $entry['id'] ] : 1000;
		return [
			'id'         => $entry['id'],
			'label'      => $entry['label'],
			'desc'       => $entry['desc'],
			'icon'       => $entry['icon'],
			'emoji'      => $entry['emoji'],
			'mode'       => $entry['access']['mode'],
			'owner'      => $entry['access']['owner'],
			'section'    => $entry['section'],
			'primary'    => $order < 100,
			'available'  => empty( $entry['locked'] ),
			'plan_badge' => $entry['plan_badge'],
			'manage'     => $manage,
		];
	}

	/** @return array{state:string,editable:bool,reason:string} */
	private static function role_cell( array $entry, $role_slug ) {
		if ( ! empty( $entry['locked'] ) ) {
			return [ 'state' => 'na', 'editable' => false, 'reason' => 'unavailable' ];
		}
		if ( 'administrator' === $role_slug ) {
			return [ 'state' => 'on', 'editable' => false, 'reason' => 'admin_always' ];
		}
		$mode = $entry['access']['mode'];
		if ( 'admin_only' === $mode ) {
			return [ 'state' => 'off', 'editable' => false, 'reason' => 'admin_only' ];
		}
		if ( 'grantable' === $mode ) {
			$role = get_role( $role_slug );
			$on   = $role && $role->has_cap( BizCity_Twin_Module_Access::PRIM_PREFIX . $entry['id'] );
			return [ 'state' => $on ? 'on' : 'off', 'editable' => true, 'reason' => '' ];
		}
		if ( 'gpt' === $entry['id'] && class_exists( 'BizCity_TwinWeb_REST' ) && method_exists( 'BizCity_TwinWeb_REST', 'module_access_role_state' ) ) {
			$state = BizCity_TwinWeb_REST::instance()->module_access_role_state();
			if ( in_array( $role_slug, $state['editable_roles'], true ) ) {
				return [ 'state' => in_array( $role_slug, $state['allowed_roles'], true ) ? 'on' : 'off', 'editable' => true, 'reason' => '' ];
			}
			return [ 'state' => 'na', 'editable' => false, 'reason' => 'delegated_other' ];
		}
		if ( 'crm' === $entry['id'] ) {
			$role = get_role( $role_slug );
			$on   = $role && $role->has_cap( 'bizcity_crm_handle_inbox' );
			return [ 'state' => $on ? 'on' : 'off', 'editable' => false, 'reason' => 'managed_by_owner' ];
		}
		return [ 'state' => 'na', 'editable' => false, 'reason' => 'managed_by_owner' ];
	}

	/** @return array<int, array{entry:array,role:string,allowed:bool}>|WP_Error */
	private static function read_role_changes( WP_REST_Request $request ) {
		$raw = $request->get_param( 'changes' );
		if ( ! is_array( $raw ) || empty( $raw ) || count( $raw ) > 200 ) {
			return self::error( 'invalid_param', __( 'Nothing to save.', 'bizcity-twin-ai' ), __( 'Change at least one switch, then save.', 'bizcity-twin-ai' ), 400 );
		}
		$out = [];
		foreach ( $raw as $item ) {
			$entry = is_array( $item ) && isset( $item['module_id'] ) ? self::governed_entry( sanitize_key( (string) $item['module_id'] ) ) : null;
			$role  = is_array( $item ) && isset( $item['role'] ) ? sanitize_key( (string) $item['role'] ) : '';
			if ( ! $entry || '' === $role || null === get_role( $role ) || ! isset( $item['allowed'] ) ) {
				return self::error( 'invalid_param', __( 'One change points to an unknown module or role.', 'bizcity-twin-ai' ), __( 'Reload the page and try again.', 'bizcity-twin-ai' ), 400 );
			}
			$cell = self::role_cell( $entry, $role );
			if ( ! $cell['editable'] ) {
				return self::error( 'module_access_write_failed', __( 'This switch cannot be changed here.', 'bizcity-twin-ai' ), __( 'Use the link on that row to change it where it is managed.', 'bizcity-twin-ai' ), 409, [ 'reason' => 'not_grantable', 'module_id' => $entry['id'], 'role' => $role ] );
			}
			$out[] = [ 'entry' => $entry, 'role' => $role, 'allowed' => rest_sanitize_boolean( $item['allowed'] ) ];
		}
		return $out;
	}

	/** @return true|WP_Error */
	private static function apply_role_change( array $entry, $role_slug, $allowed ) {
		if ( 'grantable' === $entry['access']['mode'] ) {
			$role = get_role( $role_slug );
			$prim = BizCity_Twin_Module_Access::PRIM_PREFIX . $entry['id'];
			if ( $allowed ) {
				$role->add_cap( $prim );
			} else {
				$role->remove_cap( $prim );
			}
			return true;
		}
		if ( 'gpt' === $entry['id'] && class_exists( 'BizCity_TwinWeb_REST' ) ) {
			return BizCity_TwinWeb_REST::instance()->module_access_set_role( $role_slug, $allowed );
		}
		return self::error( 'module_access_write_failed', __( 'This switch cannot be changed here.', 'bizcity-twin-ai' ), '', 409, [ 'reason' => 'not_grantable' ] );
	}

	/** @return string|null 'inherit'|'allow'|'deny', or null when this module has no per-user override here. */
	private static function user_override( array $entry, WP_User $user ) {
		if ( 'grantable' === $entry['access']['mode'] ) {
			$prim = BizCity_Twin_Module_Access::PRIM_PREFIX . $entry['id'];
			if ( ! array_key_exists( $prim, (array) $user->caps ) ) {
				return 'inherit';
			}
			return $user->caps[ $prim ] ? 'allow' : 'deny';
		}
		if ( 'gpt' === $entry['id'] && class_exists( 'BizCity_TwinWeb_REST' ) && method_exists( 'BizCity_TwinWeb_REST', 'module_access_user_state' ) ) {
			return BizCity_TwinWeb_REST::instance()->module_access_user_state( $user->ID );
		}
		return null;
	}

	/** @return true|WP_Error */
	private static function apply_user_override( array $entry, WP_User $user, $state ) {
		if ( 'grantable' === $entry['access']['mode'] ) {
			$prim = BizCity_Twin_Module_Access::PRIM_PREFIX . $entry['id'];
			if ( 'inherit' === $state ) {
				$user->remove_cap( $prim );
			} else {
				$user->add_cap( $prim, 'allow' === $state );
			}
			return true;
		}
		if ( 'gpt' === $entry['id'] && class_exists( 'BizCity_TwinWeb_REST' ) ) {
			return BizCity_TwinWeb_REST::instance()->module_access_set_user( $user->ID, $state );
		}
		return self::error( 'module_access_write_failed', __( 'This module is managed elsewhere.', 'bizcity-twin-ai' ), __( 'Use the link on that row to change it where it is managed.', 'bizcity-twin-ai' ), 409, [ 'reason' => 'not_grantable' ] );
	}

	private static function user_row( WP_User $user ) {
		$is_admin  = BizCity_Twin_Module_Access::is_site_admin( $user->ID );
		$modules   = [];
		$overrides = 0;
		foreach ( self::governed_entries() as $entry ) {
			$override = $is_admin ? null : self::user_override( $entry, $user );
			if ( null !== $override && 'inherit' !== $override ) {
				$overrides++;
			}
			$modules[] = [
				'id'       => $entry['id'],
				'allowed'  => empty( $entry['locked'] ) && BizCity_Twin_Module_Access::can( $entry['id'], $user->ID ),
				'override' => $override,
			];
		}
		$roles = [];
		foreach ( (array) $user->roles as $slug ) {
			$names   = wp_roles()->role_names;
			$roles[] = [ 'slug' => (string) $slug, 'name' => isset( $names[ $slug ] ) ? translate_user_role( $names[ $slug ] ) : (string) $slug ];
		}
		return [
			'id'              => (int) $user->ID,
			'display_name'    => (string) $user->display_name,
			'email'           => (string) $user->user_email,
			'roles'           => $roles,
			'is_admin'        => $is_admin,
			'is_self'         => (int) $user->ID === (int) get_current_user_id(),
			'overrides_count' => $overrides,
			'modules'         => $modules,
		];
	}

	/** @return int[] Members of this blog with at least one per-user override (caps or Twin GPT lists). */
	private static function user_ids_with_overrides() {
		global $wpdb;
		$meta_key = $wpdb->get_blog_prefix( get_current_blog_id() ) . 'capabilities';
		$ids      = get_users( [
			'blog_id'    => get_current_blog_id(),
			'fields'     => 'ID',
			'number'     => 5000,
			'meta_query' => [ [ 'key' => $meta_key, 'value' => BizCity_Twin_Module_Access::PRIM_PREFIX, 'compare' => 'LIKE' ] ],
		] );
		$ids = array_map( 'intval', (array) $ids );
		$opt = get_option( 'bizcity_twinweb_access_policy_' . (int) get_current_blog_id(), [] );
		if ( is_array( $opt ) && isset( $opt['users'] ) && is_array( $opt['users'] ) ) {
			foreach ( [ 'allow_user_ids', 'deny_user_ids' ] as $k ) {
				foreach ( (array) ( $opt['users'][ $k ] ?? [] ) as $id ) {
					$ids[] = (int) $id;
				}
			}
		}
		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/** People in the role whose effective access to the module would flip. */
	private static function affected_count( array $entry, $role_slug, $allowed, &$approximate ) {
		$ids = get_users( [
			'blog_id' => get_current_blog_id(),
			'role'    => $role_slug,
			'fields'  => 'ID',
			'number'  => self::PREVIEW_CAP + 1,
		] );
		if ( count( $ids ) > self::PREVIEW_CAP ) {
			$approximate = true;
			$ids         = array_slice( $ids, 0, self::PREVIEW_CAP );
		}
		$count = 0;
		foreach ( $ids as $id ) {
			$user = get_userdata( (int) $id );
			if ( ! $user || BizCity_Twin_Module_Access::is_site_admin( $user->ID ) ) {
				continue;
			}
			$override = self::user_override( $entry, $user );
			if ( null !== $override && 'inherit' !== $override ) {
				continue;
			}
			$now = BizCity_Twin_Module_Access::can( $entry['id'], $user->ID );
			if ( 'grantable' === $entry['access']['mode'] ) {
				$other = false;
				foreach ( (array) $user->roles as $r ) {
					$ro = get_role( $r );
					if ( $r !== $role_slug && $ro && $ro->has_cap( BizCity_Twin_Module_Access::PRIM_PREFIX . $entry['id'] ) ) {
						$other = true;
						break;
					}
				}
				$next = $allowed || $other;
			} else {
				$approximate = true;
				$next        = $allowed;
			}
			if ( $now !== $next ) {
				$count++;
			}
		}
		return $count;
	}

	private static function emit_changed( $module_id, array $target, $before, $after ) {
		if ( ! class_exists( 'BizCity_Twin_Shell_Page' ) ) {
			return;
		}
		BizCity_Twin_Shell_Page::instance()->emit_activity_event( 'shell.access.changed', [
			'outcome'   => 'success',
			'plugin_id' => (string) $module_id,
			'target'    => $target,
			'before'    => (string) $before,
			'after'     => (string) $after,
		] );
	}

	private static function error( $code, $message, $hint, $status, array $extra = [] ) {
		return new WP_Error( $code, $message, array_merge( [ 'status' => (int) $status, 'hint' => $hint, 'help_code' => $code ], $extra ) );
	}
}
