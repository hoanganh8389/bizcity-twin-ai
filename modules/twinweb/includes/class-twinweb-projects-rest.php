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
 * [2026-06-22 Johnny Chu] PHASE-TWINWEB — reuses bizcity_webchat_projects (NO new table).
 *   Thread→project mapping stored in bizcity_twinweb_threads.project_id (ALTER added
 *   by BizCity_TwinWeb_Installer::ensure_project_id_column(), idempotent).
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
		$t       = self::projects_table();

		if ( ! self::table_exists( $t ) ) {
			// webchat module not installed on this site — return empty list gracefully
			return rest_ensure_response( array( 'projects' => array(), '_degraded' => true ) );
		}

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT project_id, name, description, icon, color, is_archived, sort_order, created_at
			 FROM {$t}
			 WHERE user_id = %d AND is_archived = 0
			 ORDER BY sort_order ASC, created_at DESC",
			$user_id
		) );

		// Attach thread counts from twinweb_threads.project_id
		$threads_tbl = self::threads_table();
		if ( self::table_exists( $threads_tbl ) ) {
			foreach ( $rows as $row ) {
				$row->thread_count = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$threads_tbl} WHERE project_id = %s AND user_id = %d AND archived = 0",
					$row->project_id, $user_id
				) );
			}
		} else {
			foreach ( $rows as $row ) { $row->thread_count = 0; }
		}

		return rest_ensure_response( array( 'projects' => $rows ) );
	}

	/**
	 * POST /projects
	 * Inserts into bizcity_webchat_projects (lean subset of columns).
	 */
	public function create_project( $request ) {
		global $wpdb;
		$user_id = (int) get_current_user_id();
		$t       = self::projects_table();

		if ( ! self::table_exists( $t ) ) {
			return new WP_Error( 'module_not_loaded', 'WebChat module chưa cài.', array( 'status' => 503 ) );
		}

		$project_id = 'twp_' . bin2hex( random_bytes( 8 ) );
		$now        = current_time( 'mysql' );

		$ok = $wpdb->insert( $t, array(
			'project_id'  => $project_id,
			'user_id'     => $user_id,
			'name'        => (string) $request->get_param( 'name' ),
			'description' => (string) $request->get_param( 'description' ),
			'icon'        => (string) $request->get_param( 'icon' ),
			'color'       => (string) $request->get_param( 'color' ),
			'created_at'  => $now,
			'updated_at'  => $now,
		) );

		if ( ! $ok ) {
			return new WP_Error( 'db_error', 'Không thể tạo project.', array( 'status' => 500 ) );
		}

		return rest_ensure_response( array(
			'project_id'   => $project_id,
			'name'         => (string) $request->get_param( 'name' ),
			'icon'         => (string) $request->get_param( 'icon' ),
			'color'        => (string) $request->get_param( 'color' ),
			'thread_count' => 0,
		) );
	}

	/**
	 * PATCH /projects/{id}
	 */
	public function update_project( $request ) {
		global $wpdb;
		$user_id    = (int) get_current_user_id();
		$project_id = (string) $request->get_param( 'id' );
		$t          = self::projects_table();

		if ( ! self::table_exists( $t ) ) {
			return new WP_Error( 'module_not_loaded', 'WebChat module chưa cài.', array( 'status' => 503 ) );
		}

		$exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$t} WHERE project_id = %s AND user_id = %d",
			$project_id, $user_id
		) );
		if ( ! $exists ) {
			return new WP_Error( 'not_found', 'Project không tồn tại.', array( 'status' => 404 ) );
		}

		$data = array();
		foreach ( array( 'name', 'icon', 'color', 'description' ) as $f ) {
			$v = $request->get_param( $f );
			if ( null !== $v ) { $data[ $f ] = (string) $v; }
		}
		if ( empty( $data ) ) { return rest_ensure_response( array( 'updated' => false ) ); }

		$data['updated_at'] = current_time( 'mysql' );
		$wpdb->update( $t, $data, array( 'project_id' => $project_id, 'user_id' => $user_id ) );

		return rest_ensure_response( array( 'updated' => true ) );
	}

	/**
	 * DELETE /projects/{id}
	 * Threads in this project are moved to inbox (project_id = '').
	 */
	public function delete_project( $request ) {
		global $wpdb;
		$user_id    = (int) get_current_user_id();
		$project_id = (string) $request->get_param( 'id' );
		$t          = self::projects_table();

		if ( ! self::table_exists( $t ) ) {
			return new WP_Error( 'module_not_loaded', 'WebChat module chưa cài.', array( 'status' => 503 ) );
		}

		$exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$t} WHERE project_id = %s AND user_id = %d",
			$project_id, $user_id
		) );
		if ( ! $exists ) {
			return new WP_Error( 'not_found', 'Project không tồn tại.', array( 'status' => 404 ) );
		}

		// Detach threads → inbox
		$threads_tbl = self::threads_table();
		if ( self::table_exists( $threads_tbl ) ) {
			$wpdb->update(
				$threads_tbl,
				array( 'project_id' => '' ),
				array( 'project_id' => $project_id, 'user_id' => $user_id )
			);
		}

		$wpdb->delete( $t, array( 'project_id' => $project_id, 'user_id' => $user_id ) );

		return rest_ensure_response( array( 'deleted' => true ) );
	}

	/**
	 * POST /projects/{id}/threads/{tid}/move
	 */
	public function move_thread( $request ) {
		global $wpdb;
		$user_id    = (int) get_current_user_id();
		$project_id = (string) $request->get_param( 'id' );
		$thread_id  = (int) $request->get_param( 'tid' );
		$t          = self::projects_table();

		if ( ! self::table_exists( $t ) ) {
			return new WP_Error( 'module_not_loaded', 'WebChat module chưa cài.', array( 'status' => 503 ) );
		}

		$proj = $wpdb->get_var( $wpdb->prepare(
			"SELECT project_id FROM {$t} WHERE project_id = %s AND user_id = %d",
			$project_id, $user_id
		) );
		if ( ! $proj ) {
			return new WP_Error( 'not_found', 'Project không tồn tại.', array( 'status' => 404 ) );
		}

		$threads_tbl = self::threads_table();
		$rows = self::table_exists( $threads_tbl )
			? $wpdb->update( $threads_tbl, array( 'project_id' => $project_id ), array( 'id' => $thread_id, 'user_id' => $user_id ) )
			: 0;

		return rest_ensure_response( array( 'moved' => (bool) $rows ) );
	}

	/**
	 * POST /projects/{id}/threads/{tid}/remove
	 */
	public function remove_thread( $request ) {
		global $wpdb;
		$user_id   = (int) get_current_user_id();
		$thread_id = (int) $request->get_param( 'tid' );

		$threads_tbl = self::threads_table();
		if ( self::table_exists( $threads_tbl ) ) {
			$wpdb->update(
				$threads_tbl,
				array( 'project_id' => '' ),
				array( 'id' => $thread_id, 'user_id' => $user_id )
			);
		}

		return rest_ensure_response( array( 'removed' => true ) );
	}
}
