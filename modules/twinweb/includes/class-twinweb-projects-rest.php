<?php
/**
 * TwinWeb — Projects REST Controller
 *
 * Namespace: bizcity-twinweb/v1/projects
 *
 * Routes:
 *   GET    /projects                         — list projects for current user
 *   POST   /projects                         — create project
 *   PATCH  /projects/{id}                    — rename / update project
 *   DELETE /projects/{id}                    — delete (threads → inbox)
 *   POST   /projects/{id}/threads/{tid}/move — assign thread to project
 *   POST   /projects/{id}/threads/{tid}/remove — move thread to inbox
 *
 * [2026-07-30 Johnny Chu] PHASE-TWINWEB-UNIFIED-SOURCES — canonical Project identity
 *   is now bizcity_kg_notebooks. Legacy webchat projects are migrated lazily and
 *   exposed as stable nb_<id> aliases while TwinWeb threads store notebook_id.
 *   Session memory lives in bizcity_twin_event_stream (canonical, via brain_sessions VIEW).
 *   All state flows through twin event stream path — no orphan tables.
 *
 * PHP 7.4 compatible.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Modules\TwinWeb
 */
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_TwinWeb_Projects_REST', false ) ) { return; }

class BizCity_TwinWeb_Projects_REST {

	const NS = 'bizcity-twinweb/v1';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	// ── Table names ───────────────────────────────────────────────────────────

	/**
	 * Reuse existing webchat projects table — no new DDL.
	 */
	private static function projects_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_webchat_projects';
	}

	/**
	 * TwinWeb threads table — already created by BizCity_TwinWeb_Installer.
	 */
	private static function threads_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_twinweb_threads';
	}

	private static function notebook_service() {
		return class_exists( 'BizCity_KG_Notebook_Service' )
			? BizCity_KG_Notebook_Service::instance()
			: null;
	}

	private static function notebook_key( $notebook_id ): string {
		return 'nb_' . (int) $notebook_id;
	}

	private static function notebook_id_from_key( $key, $user_id ) {
		$key = sanitize_key( (string) $key );
		if ( preg_match( '/^nb_(\d+)$/', $key, $match ) ) {
			return (int) $match[1];
		}
		if ( ctype_digit( $key ) ) {
			return (int) $key;
		}

		$service = self::notebook_service();
		if ( ! $service ) {
			return 0;
		}
		foreach ( $service->list_for_user( (int) $user_id, array( 'limit' => 500 ) ) as $notebook ) {
			$settings = isset( $notebook['settings'] ) && is_array( $notebook['settings'] ) ? $notebook['settings'] : array();
			if ( (string) ( $settings['legacy_project_id'] ?? '' ) === $key ) {
				return (int) $notebook['id'];
			}
		}
		return 0;
	}

	/**
	 * Copy legacy webchat projects into canonical notebooks once per owner.
	 */
	private static function migrate_legacy_projects( $user_id ) {
		global $wpdb;
		$service = self::notebook_service();
		$legacy_table = self::projects_table();
		if ( ! $service || ! self::table_exists( $legacy_table ) ) {
			return;
		}

		$notebooks = $service->list_for_user( (int) $user_id, array( 'limit' => 500 ) );
		$mapped = array();
		foreach ( $notebooks as $notebook ) {
			$settings = isset( $notebook['settings'] ) && is_array( $notebook['settings'] ) ? $notebook['settings'] : array();
			$legacy_id = sanitize_key( (string) ( $settings['legacy_project_id'] ?? '' ) );
			if ( $legacy_id !== '' ) {
				$mapped[ $legacy_id ] = (int) $notebook['id'];
			}
		}

		$legacy_rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT project_id, name, description, icon, color, sort_order FROM {$legacy_table} WHERE user_id = %d AND is_archived = 0 ORDER BY sort_order ASC, created_at DESC",
			(int) $user_id
		), ARRAY_A );
		$threads_table = self::threads_table();
		foreach ( (array) $legacy_rows as $legacy ) {
			$legacy_id = sanitize_key( (string) ( $legacy['project_id'] ?? '' ) );
			if ( $legacy_id === '' ) {
				continue;
			}
			$notebook_id = isset( $mapped[ $legacy_id ] ) ? (int) $mapped[ $legacy_id ] : 0;
			if ( $notebook_id <= 0 ) {
				$created = $service->create( array(
					'name'          => (string) ( $legacy['name'] ?? 'Untitled project' ),
					'description'   => (string) ( $legacy['description'] ?? '' ),
					'color'         => (string) ( $legacy['color'] ?? '' ),
					'settings'      => array(
						'icon'              => (string) ( $legacy['icon'] ?? '📁' ),
						'sort_order'        => (int) ( $legacy['sort_order'] ?? 0 ),
						'legacy_project_id' => $legacy_id,
					),
				), (int) $user_id );
				if ( is_wp_error( $created ) || empty( $created['id'] ) ) {
					continue;
				}
				$notebook_id = (int) $created['id'];
				$mapped[ $legacy_id ] = $notebook_id;
			}
			if ( self::table_exists( $threads_table ) ) {
				// [2026-07-30 Johnny Chu] PHASE-TWINWEB-UNIFIED-SOURCES — backfill legacy assignments into the canonical notebook column.
				$wpdb->query( $wpdb->prepare(
					"UPDATE {$threads_table} SET notebook_id = %d WHERE project_id = %s AND user_id = %d AND (notebook_id = 0 OR notebook_id IS NULL)",
					$notebook_id,
					$legacy_id,
					(int) $user_id
				) );
			}
		}
	}

	private static function format_notebook( array $notebook, $thread_count = 0 ): array {
		$settings = isset( $notebook['settings'] ) && is_array( $notebook['settings'] ) ? $notebook['settings'] : array();
		return array(
			'project_id'   => self::notebook_key( $notebook['id'] ),
			'notebook_id'  => (int) $notebook['id'],
			'name'         => (string) ( $notebook['name'] ?? '' ),
			'description'  => (string) ( $notebook['description'] ?? '' ),
			'icon'         => (string) ( $settings['icon'] ?? '📁' ),
			'color'        => (string) ( $notebook['color'] ?? '' ),
			'is_archived'  => 0,
			'sort_order'   => (int) ( $settings['sort_order'] ?? 0 ),
			'created_at'   => (string) ( $notebook['created_at'] ?? '' ),
			'updated_at'   => (string) ( $notebook['updated_at'] ?? '' ),
			'thread_count' => (int) $thread_count,
		);
	}

	// ── Table existence check (R-SHOW-TABLES dual cache) ──────────────────────

	/**
	 * @param string $table Fully-qualified table name (with prefix).
	 */
	private static function table_exists( string $table ): bool {
		static $cache = array();
		if ( isset( $cache[ $table ] ) ) { return $cache[ $table ]; }
		global $wpdb;
		$ck = 'bz_tbl_' . (int) get_current_blog_id() . '_' . crc32( $table );
		$v  = wp_cache_get( $ck, 'bizcity_tbl' );
		if ( false === $v ) {
			$v = (int) (bool) $wpdb->get_var( $wpdb->prepare(
				'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
				$table
			) );
			wp_cache_set( $ck, $v, 'bizcity_tbl', HOUR_IN_SECONDS );
		}
		$cache[ $table ] = (bool) $v;
		return $cache[ $table ];
	}

	// ── Route registration ─────────────────────────────────────────────────────

	public function register_routes() {
		$ns = self::NS;

		register_rest_route( $ns, '/projects', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_projects' ),
				'permission_callback' => array( $this, 'require_logged_in' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_project' ),
				'permission_callback' => array( $this, 'require_logged_in' ),
				'args'                => array(
					'name'        => array( 'type' => 'string', 'required' => true,  'sanitize_callback' => 'sanitize_text_field' ),
					'description' => array( 'type' => 'string', 'required' => false, 'default' => '', 'sanitize_callback' => 'sanitize_textarea_field' ),
					'icon'        => array( 'type' => 'string', 'required' => false, 'default' => '\ud83d\udcc1', 'sanitize_callback' => 'sanitize_text_field' ),
					'color'       => array( 'type' => 'string', 'required' => false, 'default' => '#6366f1', 'sanitize_callback' => 'sanitize_text_field' ),
				),
			),
		) );

		register_rest_route( $ns, '/projects/(?P<id>[a-zA-Z0-9_-]+)', array(
			array(
				'methods'             => 'PATCH',
				'callback'            => array( $this, 'update_project' ),
				'permission_callback' => array( $this, 'require_logged_in' ),
				'args'                => array(
					'name'  => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
					'icon'  => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
					'color' => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
				),
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'delete_project' ),
				'permission_callback' => array( $this, 'require_logged_in' ),
			),
		) );

		register_rest_route( $ns, '/projects/(?P<id>[a-zA-Z0-9_-]+)/threads', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'list_project_threads' ),
			'permission_callback' => array( $this, 'require_logged_in' ),
		) );

		// [2026-07-30 Johnny Chu] PHASE-TWINWEB-UNIFIED-SOURCES — expose notebook-backed conversations/sources endpoints for Project tabs.
		register_rest_route( $ns, '/projects/(?P<id>[a-zA-Z0-9_-]+)/sessions', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'list_project_sessions' ),
			'permission_callback' => array( $this, 'require_logged_in' ),
			'args'                => array(
				'limit' => array( 'type' => 'integer', 'required' => false, 'default' => 50 ),
			),
		) );

		register_rest_route( $ns, '/projects/(?P<id>[a-zA-Z0-9_-]+)/sources', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'list_project_sources' ),
			'permission_callback' => array( $this, 'require_logged_in' ),
			'args'                => array(
				'limit'  => array( 'type' => 'integer', 'required' => false, 'default' => 50 ),
				'search' => array( 'type' => 'string', 'required' => false, 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );

		register_rest_route( $ns, '/my-files', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'list_my_files' ),
			'permission_callback' => array( $this, 'require_logged_in' ),
			'args'                => array(
				'search' => array( 'type' => 'string', 'required' => false, 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
				'type'   => array( 'type' => 'string', 'required' => false, 'default' => '', 'sanitize_callback' => 'sanitize_key' ),
				'origin' => array( 'type' => 'string', 'required' => false, 'default' => '', 'sanitize_callback' => 'sanitize_key' ),
				'limit'  => array( 'type' => 'integer', 'required' => false, 'default' => 50 ),
				'page'   => array( 'type' => 'integer', 'required' => false, 'default' => 1 ),
			),
		) );

		register_rest_route( $ns, '/my-files/(?P<source_id>\d+)/attach', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'attach_my_file' ),
			'permission_callback' => array( $this, 'require_logged_in' ),
			'args'                => array(
				'source_id'              => array( 'type' => 'integer', 'required' => true ),
				'destination_notebook_id' => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ),
			),
		) );

		// Move a twinweb thread into a project
		register_rest_route( $ns, '/projects/(?P<id>[a-zA-Z0-9_-]+)/threads/(?P<tid>\\d+)/move', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'move_thread' ),
			'permission_callback' => array( $this, 'require_logged_in' ),
		) );

		// Remove twinweb thread from project → inbox
		register_rest_route( $ns, '/projects/(?P<id>[a-zA-Z0-9_-]+)/threads/(?P<tid>\\d+)/remove', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'remove_thread' ),
			'permission_callback' => array( $this, 'require_logged_in' ),
		) );
	}

	// ── Permission ────────────────────────────────────────────────────────────

	public function require_logged_in( $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'auth_required', 'B\u1ea1n c\u1ea7n \u0111\u0103ng nh\u1eadp.', array( 'status' => 401 ) );
		}
		return true;
	}

	// ── Handlers ──────────────────────────────────────────────────────────────

	/**
	 * GET /projects
	 * Lists projects from bizcity_webchat_projects (existing table, no new DDL).
	 */
	public function list_projects( $request ) {
		global $wpdb;
		$user_id = (int) get_current_user_id();
		$service = self::notebook_service();
		if ( ! $service ) {
			// [2026-07-30 Johnny Chu] PHASE-TWINWEB-UNIFIED-SOURCES — fail gracefully when KG Hub is unavailable.
			return rest_ensure_response( array( 'projects' => array(), '_degraded' => true ) );
		}
		self::migrate_legacy_projects( $user_id );
		$notebooks = $service->list_for_user( $user_id, array( 'limit' => 500 ) );
		$threads_tbl = self::threads_table();
		$projects = array();
		foreach ( $notebooks as $notebook ) {
			$thread_count = 0;
			if ( self::table_exists( $threads_tbl ) ) {
				$thread_count = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$threads_tbl} WHERE notebook_id = %d AND user_id = %d AND archived = 0",
					(int) $notebook['id'], $user_id
				) );
			}
			$projects[] = self::format_notebook( $notebook, $thread_count );
		}
		usort( $projects, static function ( $left, $right ) {
			if ( (int) $left['sort_order'] === (int) $right['sort_order'] ) {
				return strcmp( (string) $right['updated_at'], (string) $left['updated_at'] );
			}
			return (int) $left['sort_order'] <=> (int) $right['sort_order'];
		} );
		return rest_ensure_response( array( 'projects' => $projects ) );
	}

	/**
	 * GET /projects/{id}/threads — conversation tab for a canonical notebook.
	 */
	public function list_project_threads( $request ) {
		global $wpdb;
		$user_id = (int) get_current_user_id();
		$service = self::notebook_service();
		$notebook_id = self::notebook_id_from_key( $request->get_param( 'id' ), $user_id );
		$notebook = $service ? $service->get( $notebook_id ) : null;
		if ( ! $service || ! $notebook || (int) $notebook['owner_id'] !== $user_id ) {
			return new WP_Error( 'not_found', 'Project không tồn tại.', array( 'status' => 404 ) );
		}
		$table = self::threads_table();
		if ( ! self::table_exists( $table ) ) {
			return rest_ensure_response( array( 'threads' => array(), 'notebook_id' => $notebook_id ) );
		}
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, title, pinned, archived, last_at, created_at, notebook_id FROM {$table} WHERE notebook_id = %d AND user_id = %d ORDER BY last_at DESC LIMIT 100",
			$notebook_id,
			$user_id
		), ARRAY_A );
		$threads = array_map( static function ( $row ) use ( $notebook_id ) {
			return array(
				'id'          => (int) $row['id'],
				'title'       => (string) $row['title'],
				'pinned'      => (bool) $row['pinned'],
				'archived'    => (bool) $row['archived'],
				'last_at'     => (string) $row['last_at'],
				'created_at'  => (string) $row['created_at'],
				'notebook_id' => (int) $notebook_id,
				'project_id'  => self::notebook_key( $notebook_id ),
			);
		}, $rows ?: array() );
		return rest_ensure_response( array( 'threads' => $threads, 'notebook_id' => $notebook_id ) );
	}

	/**
	 * GET /projects/{id}/sessions — notebook-scoped conversation sessions.
	 */
	public function list_project_sessions( $request ) {
		$user_id = (int) get_current_user_id();
		$service = self::notebook_service();
		$notebook_id = self::notebook_id_from_key( $request->get_param( 'id' ), $user_id );
		$notebook = $service ? $service->get( $notebook_id ) : null;
		if ( ! $service || ! $notebook || (int) $notebook['owner_id'] !== $user_id ) {
			return new WP_Error( 'not_found', 'Project không tồn tại.', array( 'status' => 404 ) );
		}

		$limit = max( 1, min( 100, (int) $request->get_param( 'limit' ) ) );
		$sessions = array();
		if ( class_exists( 'BizCity_TwinChat_Database' ) ) {
			// [2026-07-30 Johnny Chu] PHASE-TWINWEB-UNIFIED-SOURCES — notebook tab conversations reuse canonical TwinChat session listing.
			$sessions = BizCity_TwinChat_Database::instance()->list_sessions( $notebook_id, $limit );
		}

		return rest_ensure_response( array(
			'notebook_id' => $notebook_id,
			'project_id'  => self::notebook_key( $notebook_id ),
			'sessions'    => is_array( $sessions ) ? $sessions : array(),
		) );
	}

	/**
	 * GET /projects/{id}/sources — notebook-scoped source list.
	 */
	public function list_project_sources( $request ) {
		$user_id = (int) get_current_user_id();
		$service = self::notebook_service();
		$notebook_id = self::notebook_id_from_key( $request->get_param( 'id' ), $user_id );
		$notebook = $service ? $service->get( $notebook_id ) : null;
		if ( ! $service || ! $notebook || (int) $notebook['owner_id'] !== $user_id ) {
			return new WP_Error( 'not_found', 'Project không tồn tại.', array( 'status' => 404 ) );
		}

		$limit = max( 1, min( 200, (int) $request->get_param( 'limit' ) ) );
		$args = array(
			'limit'  => $limit,
			'search' => (string) $request->get_param( 'search' ),
		);

		$sources = array();
		if ( class_exists( 'BizCity_TwinChat_Sources_Service' ) ) {
			// [2026-07-30 Johnny Chu] PHASE-TWINWEB-UNIFIED-SOURCES — source tab reuses the existing TwinChat source contract.
			$sources = BizCity_TwinChat_Sources_Service::instance()->list_sources( $notebook_id, $args );
		}

		return rest_ensure_response( array(
			'notebook_id' => $notebook_id,
			'project_id'  => self::notebook_key( $notebook_id ),
			'sources'     => is_array( $sources ) ? $sources : array(),
		) );
	}

	/**
	 * GET /my-files — all canonical sources in notebooks owned by the user.
	 */
	public function list_my_files( $request ) {
		global $wpdb;
		$user_id = (int) get_current_user_id();
		$service = self::notebook_service();
		if ( ! $service || ! class_exists( 'BizCity_KG_Database' ) ) {
			return rest_ensure_response( array( 'items' => array(), 'total' => 0, '_degraded' => true ) );
		}

		$notebooks = $service->list_for_user( $user_id, array( 'limit' => 500 ) );
		$notebook_names = array();
		$notebook_ids = array();
		foreach ( (array) $notebooks as $notebook ) {
			$id = (int) ( $notebook['id'] ?? 0 );
			if ( $id <= 0 ) { continue; }
			$notebook_ids[] = $id;
			$notebook_names[ $id ] = (string) ( $notebook['name'] ?? '' );
		}
		if ( empty( $notebook_ids ) ) {
			return rest_ensure_response( array( 'items' => array(), 'total' => 0 ) );
		}

		$limit = max( 1, min( 200, (int) $request->get_param( 'limit' ) ) );
		$page = max( 1, (int) $request->get_param( 'page' ) );
		$search = trim( (string) $request->get_param( 'search' ) );
		$type = sanitize_key( (string) $request->get_param( 'type' ) );
		$origin = sanitize_key( (string) $request->get_param( 'origin' ) );
		$table = BizCity_KG_Database::instance()->tbl_sources();
		$placeholders = implode( ',', array_fill( 0, count( $notebook_ids ), '%s' ) );
		$where = array( "scope_type = 'notebook'", "scope_id IN ({$placeholders})" );
		$params = array_map( 'strval', $notebook_ids );
		if ( '' !== $search ) {
			$where[] = '(title LIKE %s OR origin_url LIKE %s)';
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			$params[] = $like;
			$params[] = $like;
		}
		if ( '' !== $origin ) {
			$where[] = 'origin_kind = %s';
			$params[] = $origin;
		}
		if ( '' !== $type ) {
			if ( 'url' === $type ) {
				$where[] = "origin_url IS NOT NULL AND origin_url <> ''";
			} elseif ( 'file' === $type ) {
				$where[] = "(origin_url IS NULL OR origin_url = '') AND origin_kind NOT IN ('text', 'manual')";
			} elseif ( 'text' === $type ) {
				$where[] = "origin_kind IN ('text', 'manual')";
			}
		}
		$where_sql = implode( ' AND ', $where );
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) );
		$params[] = $limit;
		$params[] = ( $page - 1 ) * $limit;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, uuid, title, origin_url, origin_plugin, origin_id, origin_kind, status, scope_id, passage_count, created_at, updated_at FROM {$table} WHERE {$where_sql} ORDER BY updated_at DESC, id DESC LIMIT %d OFFSET %d",
			$params
		), ARRAY_A );

		$items = array_map( function ( $row ) use ( $notebook_names ) {
			$notebook_id = (int) ( $row['scope_id'] ?? 0 );
			$url = (string) ( $row['origin_url'] ?? '' );
			$origin_kind = sanitize_key( (string) ( $row['origin_kind'] ?? '' ) );
			$type = '' !== $url ? 'url' : ( in_array( $origin_kind, array( 'text', 'manual' ), true ) ? 'text' : 'file' );
			$origin_plugin = sanitize_key( (string) ( $row['origin_plugin'] ?? '' ) );
			$origin_id = (int) ( $row['origin_id'] ?? 0 );
			return array(
				'id'            => (int) $row['id'],
				'uuid'          => (string) ( $row['uuid'] ?? '' ),
				'type'          => $type,
				'title'         => (string) ( $row['title'] ?? '' ),
				'url'           => $url,
				'origin'        => $origin_kind,
				'origin_plugin' => $origin_plugin,
				'origin_id'     => $origin_id,
				'can_attach'    => $origin_id > 0 && '' !== $origin_plugin,
				'status'        => (string) ( $row['status'] ?? '' ),
				'notebook_id'   => $notebook_id,
				'source_notebook_id' => $notebook_id,
				'project_id'    => self::notebook_key( $notebook_id ),
				'project_name'  => (string) ( $notebook_names[ $notebook_id ] ?? '' ),
				'attached_notebook_ids' => self::list_attached_notebook_ids( $origin_plugin, $origin_id ),
				'passage_count' => (int) ( $row['passage_count'] ?? 0 ),
				'created_at'    => (string) ( $row['created_at'] ?? '' ),
				'updated_at'    => (string) ( $row['updated_at'] ?? '' ),
			);
		}, $rows ?: array() );

		return rest_ensure_response( array(
			'items' => $items,
			'total' => $total,
			'page'  => $page,
			'limit' => $limit,
		) );
	}

	/**
	 * POST /my-files/{source_id}/attach — virtual-link a source to another owned notebook.
	 */
	public function attach_my_file( $request ) {
		global $wpdb;
		$user_id = (int) get_current_user_id();
		$source_id = (int) $request->get_param( 'source_id' );
		$destination_id = (int) $request->get_param( 'destination_notebook_id' );
		$service = self::notebook_service();
		if ( $source_id <= 0 || $destination_id <= 0 || ! $service || ! class_exists( 'BizCity_KG' ) || ! class_exists( 'BizCity_KG_Database' ) ) {
			return new WP_Error( 'invalid_attach', 'Nguồn và Project đích không hợp lệ.', array( 'status' => 400 ) );
		}

		$destination = $service->get( $destination_id );
		if ( ! is_array( $destination ) || (int) ( $destination['owner_id'] ?? 0 ) !== $user_id ) {
			return new WP_Error( 'not_found', 'Project đích không tồn tại.', array( 'status' => 404 ) );
		}

		$source = $wpdb->get_row( $wpdb->prepare(
			'SELECT id, origin_plugin, origin_id, scope_type, scope_id, user_id FROM ' . BizCity_KG_Database::instance()->tbl_sources() . ' WHERE id = %d AND scope_type = %s LIMIT 1',
			$source_id,
			'notebook'
		), ARRAY_A );
		$source_notebook_id = is_array( $source ) ? (int) ( $source['scope_id'] ?? 0 ) : 0;
		$source_notebook = $source_notebook_id > 0 ? $service->get( $source_notebook_id ) : null;
		if ( ! is_array( $source ) || ! is_array( $source_notebook ) || (int) ( $source_notebook['owner_id'] ?? 0 ) !== $user_id ) {
			return new WP_Error( 'not_found', 'Nguồn không thuộc tài khoản hiện tại.', array( 'status' => 404 ) );
		}
		if ( (int) $source['origin_id'] <= 0 || '' === (string) $source['origin_plugin'] ) {
			return new WP_Error( 'source_not_attachable', 'Nguồn này chưa có mapping để gắn ảo sang Project khác.', array( 'status' => 409 ) );
		}
		if ( $source_notebook_id === $destination_id ) {
			return rest_ensure_response( array( 'attached' => false, 'already_in_project' => true, 'notebook_id' => $destination_id ) );
		}

		$result = BizCity_KG::attach_source(
			array( 'plugin' => sanitize_key( (string) $source['origin_plugin'] ), 'scope_id' => $source_notebook_id ),
			(int) $source['origin_id'],
			array( 'plugin' => sanitize_key( (string) $source['origin_plugin'] ), 'scope_id' => $destination_id )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( array(
			'attached'             => true,
			'source_id'           => $source_id,
			'source_notebook_id'  => $source_notebook_id,
			'destination_notebook_id' => $destination_id,
			'linked_passages'     => (int) ( $result['linked_passages'] ?? 0 ),
			'total_passages'      => (int) ( $result['total_passages'] ?? 0 ),
			'attached_notebook_ids' => self::list_attached_notebook_ids( (string) $source['origin_plugin'], (int) $source['origin_id'] ),
		) );
	}

	private static function list_attached_notebook_ids( $origin_plugin, $origin_id ) {
		$origin_plugin = sanitize_key( (string) $origin_plugin );
		$origin_id = (int) $origin_id;
		if ( '' === $origin_plugin || $origin_id <= 0 || ! class_exists( 'BizCity_KG_Source_Registry' ) || ! class_exists( 'BizCity_KG_Database' ) ) {
			return array();
		}
		$entry = BizCity_KG_Source_Registry::instance()->get( $origin_plugin );
		$source_table = is_array( $entry ) ? (string) ( $entry['sources_table'] ?? '' ) : '';
		if ( '' === $source_table ) { return array(); }
		global $wpdb;
		$passage_table = BizCity_KG_Database::instance()->tbl_passages();
		$link_table = BizCity_KG_Database::instance()->tbl_scope_links();
		$rows = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT sl.scope_id FROM {$link_table} sl INNER JOIN {$passage_table} p ON p.id = sl.ref_id WHERE sl.scope_type = %s AND sl.ref_type = %s AND p.source_table = %s AND p.source_id = %d",
			'notebook',
			'passage',
			$source_table,
			$origin_id
		) );
		return array_values( array_unique( array_map( 'intval', (array) $rows ) ) );
	}

	/**
	 * POST /projects
	 * Inserts into bizcity_webchat_projects (lean subset of columns).
	 */
	public function create_project( $request ) {
		$service = self::notebook_service();
		if ( ! $service ) {
			return new WP_Error( 'module_not_loaded', 'Knowledge Graph chưa sẵn sàng.', array( 'status' => 503 ) );
		}
		$user_id = (int) get_current_user_id();
		$created = $service->create( array(
			'name'        => (string) $request->get_param( 'name' ),
			'description' => (string) $request->get_param( 'description' ),
			'color'       => (string) $request->get_param( 'color' ),
			'settings'    => array( 'icon' => (string) $request->get_param( 'icon' ), 'sort_order' => 0 ),
		), $user_id );
		if ( is_wp_error( $created ) ) {
			return $created;
		}
		return rest_ensure_response( self::format_notebook( $created ) );
	}

	/**
	 * PATCH /projects/{id}
	 */
	public function update_project( $request ) {
		$service = self::notebook_service();
		$user_id = (int) get_current_user_id();
		$notebook_id = self::notebook_id_from_key( $request->get_param( 'id' ), $user_id );
		$notebook = $service ? $service->get( $notebook_id ) : null;
		if ( ! $service || ! $notebook || (int) $notebook['owner_id'] !== $user_id ) {
			return new WP_Error( 'not_found', 'Project không tồn tại.', array( 'status' => 404 ) );
		}
		$data = array();
		foreach ( array( 'name', 'color', 'description' ) as $field ) {
			$value = $request->get_param( $field );
			if ( null !== $value ) { $data[ $field ] = (string) $value; }
		}
		if ( null !== $request->get_param( 'icon' ) ) {
			$settings = is_array( $notebook['settings'] ?? null ) ? $notebook['settings'] : array();
			$settings['icon'] = (string) $request->get_param( 'icon' );
			$data['settings'] = $settings;
		}
		if ( empty( $data ) ) { return rest_ensure_response( array( 'updated' => false ) ); }
		$updated = $service->update( $notebook_id, $data );
		if ( is_wp_error( $updated ) ) { return $updated; }
		return rest_ensure_response( array( 'updated' => true ) );
	}

	/**
	 * DELETE /projects/{id}
	 * Threads in this project are moved to inbox (project_id = '').
	 */
	public function delete_project( $request ) {
		global $wpdb;
		$user_id    = (int) get_current_user_id();
		$service    = self::notebook_service();
		$notebook_id = self::notebook_id_from_key( $request->get_param( 'id' ), $user_id );
		$notebook   = $service ? $service->get( $notebook_id ) : null;
		if ( ! $service || ! $notebook || (int) $notebook['owner_id'] !== $user_id ) {
			return new WP_Error( 'not_found', 'Project không tồn tại.', array( 'status' => 404 ) );
		}

		// [2026-07-30 Johnny Chu] PHASE-TWINWEB-UNIFIED-SOURCES — delete keeps threads and returns them to the free-session inbox.
		$threads_tbl = self::threads_table();
		if ( self::table_exists( $threads_tbl ) ) {
			$wpdb->update(
				$threads_tbl,
				array( 'notebook_id' => 0, 'project_id' => '' ),
				array( 'notebook_id' => $notebook_id, 'user_id' => $user_id )
			);
		}
		$service->delete( $notebook_id );

		return rest_ensure_response( array( 'deleted' => true ) );
	}

	/**
	 * POST /projects/{id}/threads/{tid}/move
	 */
	public function move_thread( $request ) {
		global $wpdb;
		$user_id    = (int) get_current_user_id();
		$project_key = (string) $request->get_param( 'id' );
		$thread_id  = (int) $request->get_param( 'tid' );
		$service = self::notebook_service();
		$notebook_id = self::notebook_id_from_key( $project_key, $user_id );
		$notebook = $service ? $service->get( $notebook_id ) : null;
		if ( ! $service || ! $notebook || (int) $notebook['owner_id'] !== $user_id ) {
			return new WP_Error( 'not_found', 'Project không tồn tại.', array( 'status' => 404 ) );
		}

		$threads_tbl = self::threads_table();
		$rows = 0;
		if ( self::table_exists( $threads_tbl ) ) {
			// [2026-07-30 Johnny Chu] PHASE-TWINWEB-UNIFIED-SOURCES — drag/drop writes the canonical notebook id.
			$rows = $wpdb->update( $threads_tbl, array( 'notebook_id' => $notebook_id, 'project_id' => '' ), array( 'id' => $thread_id, 'user_id' => $user_id ) );
		}

		return rest_ensure_response( array( 'moved' => (bool) $rows ) );
	}

	/**
	 * POST /projects/{id}/threads/{tid}/remove
	 */
	public function remove_thread( $request ) {
		global $wpdb;
		$user_id   = (int) get_current_user_id();
		$thread_id = (int) $request->get_param( 'tid' );
		$service   = self::notebook_service();
		$notebook_id = self::notebook_id_from_key( $request->get_param( 'id' ), $user_id );
		$notebook = $service ? $service->get( $notebook_id ) : null;
		if ( ! $service || ! $notebook || (int) $notebook['owner_id'] !== $user_id ) {
			return new WP_Error( 'not_found', 'Project không tồn tại.', array( 'status' => 404 ) );
		}

		$threads_tbl = self::threads_table();
		if ( self::table_exists( $threads_tbl ) ) {
			$wpdb->update(
				$threads_tbl,
				array( 'notebook_id' => 0, 'project_id' => '' ),
				array( 'id' => $thread_id, 'user_id' => $user_id, 'notebook_id' => $notebook_id )
			);
		}

		return rest_ensure_response( array( 'removed' => true ) );
	}
}
