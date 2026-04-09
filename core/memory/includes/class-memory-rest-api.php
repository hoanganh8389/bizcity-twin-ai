<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Memory
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * BizCity Memory REST API
 *
 * Namespace: bizcity/memory/v1
 *
 * Endpoints:
 *   GET    /tree                 — Hierarchical tree data (project → session → memories)
 *   GET    /list                 — Flat list with filters & pagination
 *   GET    /(?P<id>\d+)         — Single memory spec
 *   POST   /create              — Create new memory spec
 *   PUT    /(?P<id>\d+)         — Full update (content + metadata)
 *   PATCH  /(?P<id>\d+)/section — Patch a single section
 *   DELETE /(?P<id>\d+)         — Archive (soft-delete) memory spec
 *   GET    /(?P<id>\d+)/log     — Audit log for a memory spec
 *
 * @package  BizCity_Memory
 * @since    Phase 1.15 — 2026-04-09
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( class_exists( 'BizCity_Memory_REST_API' ) ) {
	return;
}

class BizCity_Memory_REST_API {

	const API_NAMESPACE = 'bizcity/memory/v1';

	/** @var self|null */
	private static $instance = null;

	/** @var BizCity_Memory_Manager */
	private $mgr;

	/** @var BizCity_Memory_Database */
	private $db;

	/** @var BizCity_Memory_Log */
	private $log;

	/**
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->mgr = BizCity_Memory_Manager::instance();
		$this->db  = BizCity_Memory_Database::instance();
		$this->log = BizCity_Memory_Log::instance();

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/* ================================================================
	 *  Route Registration
	 * ================================================================ */

	public function register_routes() {

		// GET /tree
		register_rest_route( self::API_NAMESPACE, '/tree', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_tree' ),
			'permission_callback' => array( $this, 'check_logged_in' ),
		) );

		// GET /list
		register_rest_route( self::API_NAMESPACE, '/list', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_list' ),
			'permission_callback' => array( $this, 'check_logged_in' ),
			'args'                => array(
				'project_id'   => array( 'type' => 'string' ),
				'session_id'   => array( 'type' => 'string' ),
				'status'       => array( 'type' => 'string' ),
				'scope'        => array( 'type' => 'string' ),
				'character_id' => array( 'type' => 'integer' ),
				'page'         => array( 'type' => 'integer', 'default' => 1 ),
				'per_page'     => array( 'type' => 'integer', 'default' => 20 ),
			),
		) );

		// GET /{id}
		register_rest_route( self::API_NAMESPACE, '/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_single' ),
			'permission_callback' => array( $this, 'check_logged_in' ),
			'args'                => array(
				'id' => array( 'type' => 'integer', 'required' => true ),
			),
		) );

		// POST /create
		register_rest_route( self::API_NAMESPACE, '/create', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'create_memory' ),
			'permission_callback' => array( $this, 'check_logged_in' ),
		) );

		// PUT /{id}
		register_rest_route( self::API_NAMESPACE, '/(?P<id>\d+)', array(
			'methods'             => 'PUT',
			'callback'            => array( $this, 'update_memory' ),
			'permission_callback' => array( $this, 'check_logged_in' ),
		) );

		// PATCH /{id}/section
		register_rest_route( self::API_NAMESPACE, '/(?P<id>\d+)/section', array(
			'methods'             => 'PATCH',
			'callback'            => array( $this, 'patch_section' ),
			'permission_callback' => array( $this, 'check_logged_in' ),
		) );

		// DELETE /{id}
		register_rest_route( self::API_NAMESPACE, '/(?P<id>\d+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( $this, 'delete_memory' ),
			'permission_callback' => array( $this, 'check_admin' ),
		) );

		// GET /{id}/log
		register_rest_route( self::API_NAMESPACE, '/(?P<id>\d+)/log', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_memory_log' ),
			'permission_callback' => array( $this, 'check_logged_in' ),
			'args'                => array(
				'id'    => array( 'type' => 'integer', 'required' => true ),
				'limit' => array( 'type' => 'integer', 'default' => 50 ),
			),
		) );

		// POST /load-or-create (pipeline convenience)
		register_rest_route( self::API_NAMESPACE, '/load-or-create', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'load_or_create' ),
			'permission_callback' => array( $this, 'check_logged_in' ),
		) );
	}

	/* ================================================================
	 *  Permission Callbacks
	 * ================================================================ */

	/**
	 * @return bool
	 */
	public function check_logged_in() {
		return is_user_logged_in();
	}

	/**
	 * @return bool
	 */
	public function check_admin() {
		return current_user_can( 'manage_options' );
	}

	/* ================================================================
	 *  GET /tree
	 * ================================================================ */

	/**
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function get_tree( $request ) {
		$user_id      = get_current_user_id();
		$character_id = absint( $request->get_param( 'character_id' ) );

		// Admin sees all users' trees
		if ( current_user_can( 'manage_options' ) && $request->get_param( 'all_users' ) ) {
			$user_id = 0;
		}

		$tree = $this->mgr->get_tree( $user_id, $character_id );

		return new WP_REST_Response( array(
			'ok'   => true,
			'tree' => $tree,
		), 200 );
	}

	/* ================================================================
	 *  GET /list
	 * ================================================================ */

	/**
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function get_list( $request ) {
		$user_id = get_current_user_id();

		// Admin can view all
		if ( current_user_can( 'manage_options' ) && $request->get_param( 'all_users' ) ) {
			$user_id = 0;
		}

		$filters = array(
			'user_id'      => $user_id,
			'project_id'   => sanitize_text_field( $request->get_param( 'project_id' ) ?: '' ),
			'session_id'   => sanitize_text_field( $request->get_param( 'session_id' ) ?: '' ),
			'status'       => sanitize_text_field( $request->get_param( 'status' ) ?: '' ),
			'scope'        => sanitize_text_field( $request->get_param( 'scope' ) ?: '' ),
			'character_id' => absint( $request->get_param( 'character_id' ) ),
			'page'         => absint( $request->get_param( 'page' ) ) ?: 1,
			'per_page'     => min( absint( $request->get_param( 'per_page' ) ) ?: 20, 100 ),
		);

		$items = $this->db->list_specs( $filters );
		$total = $this->db->count_specs( $filters );

		return new WP_REST_Response( array(
			'ok'    => true,
			'items' => $items,
			'total' => $total,
			'page'  => $filters['page'],
		), 200 );
	}

	/* ================================================================
	 *  GET /{id}
	 * ================================================================ */

	/**
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function get_single( $request ) {
		$id  = absint( $request->get_param( 'id' ) );
		$row = $this->db->get( $id );

		if ( ! $row ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'Not found' ), 404 );
		}

		// Ownership check (non-admin)
		if ( ! current_user_can( 'manage_options' ) && absint( $row['user_id'] ) !== get_current_user_id() ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'Forbidden' ), 403 );
		}

		// Attach parsed data
		$row['parsed'] = BizCity_Memory_Parser::parse( $row['content'] );

		return new WP_REST_Response( array( 'ok' => true, 'memory' => $row ), 200 );
	}

	/* ================================================================
	 *  POST /create
	 * ================================================================ */

	/**
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function create_memory( $request ) {
		$body = $request->get_json_params();

		$data = array(
			'memory_key'      => sanitize_text_field( $body['memory_key'] ?? ( 'mem_' . substr( md5( uniqid( wp_rand(), true ) ), 0, 12 ) ) ),
			'project_id'      => sanitize_text_field( $body['project_id'] ?? '' ),
			'session_id'      => sanitize_text_field( $body['session_id'] ?? '' ),
			'conversation_id' => sanitize_text_field( $body['conversation_id'] ?? '' ) ?: null,
			'user_id'         => get_current_user_id(),
			'character_id'    => absint( $body['character_id'] ?? 0 ),
			'title'           => sanitize_text_field( $body['title'] ?? 'Untitled Memory' ),
			'content'         => wp_kses_post( $body['content'] ?? '' ),
			'scope'           => sanitize_text_field( $body['scope'] ?? 'project' ),
			'status'          => 'active',
		);

		// If no content provided, build from goal
		if ( empty( $data['content'] ) && ! empty( $body['goal'] ) ) {
			$data['content'] = BizCity_Memory_Parser::build( array(
				'goal'    => sanitize_text_field( $body['goal'] ),
				'context' => array( 'created' => current_time( 'Y-m-d H:i' ) ),
			) );
		}

		$memory_id = $this->db->create( $data );
		if ( ! $memory_id ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'Insert failed' ), 500 );
		}

		$this->log->record( $memory_id, 'created', 'rest_api', array( 'title' => $data['title'] ) );

		$row = $this->db->get( $memory_id );
		return new WP_REST_Response( array( 'ok' => true, 'memory' => $row ), 201 );
	}

	/* ================================================================
	 *  PUT /{id}
	 * ================================================================ */

	/**
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function update_memory( $request ) {
		$id  = absint( $request->get_param( 'id' ) );
		$row = $this->db->get( $id );

		if ( ! $row ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'Not found' ), 404 );
		}

		// Ownership check
		if ( ! current_user_can( 'manage_options' ) && absint( $row['user_id'] ) !== get_current_user_id() ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'Forbidden' ), 403 );
		}

		$body   = $request->get_json_params();
		$update = array();

		if ( isset( $body['title'] ) ) {
			$update['title'] = sanitize_text_field( $body['title'] );
		}
		if ( isset( $body['content'] ) ) {
			$update['content']      = wp_kses_post( $body['content'] );
			$update['content_hash'] = md5( $update['content'] );
		}
		if ( isset( $body['status'] ) ) {
			$allowed = array( 'active', 'stale', 'archived' );
			$status  = sanitize_text_field( $body['status'] );
			if ( in_array( $status, $allowed, true ) ) {
				$update['status'] = $status;
			}
		}
		if ( isset( $body['scope'] ) ) {
			$update['scope'] = sanitize_text_field( $body['scope'] );
		}
		if ( isset( $body['project_id'] ) ) {
			$update['project_id'] = sanitize_text_field( $body['project_id'] );
		}
		if ( isset( $body['session_id'] ) ) {
			$update['session_id'] = sanitize_text_field( $body['session_id'] );
		}

		if ( empty( $update ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'Nothing to update' ), 400 );
		}

		$result = $this->db->update( $id, $update );
		if ( ! $result ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'Update failed' ), 500 );
		}

		$this->log->record( $id, 'updated', 'rest_api', array_keys( $update ) );

		$row = $this->db->get( $id );
		return new WP_REST_Response( array( 'ok' => true, 'memory' => $row ), 200 );
	}

	/* ================================================================
	 *  PATCH /{id}/section
	 * ================================================================ */

	/**
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function patch_section( $request ) {
		$id  = absint( $request->get_param( 'id' ) );
		$row = $this->db->get( $id );

		if ( ! $row ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'Not found' ), 404 );
		}

		// Ownership check
		if ( ! current_user_can( 'manage_options' ) && absint( $row['user_id'] ) !== get_current_user_id() ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'Forbidden' ), 403 );
		}

		$body         = $request->get_json_params();
		$section_name = sanitize_text_field( $body['section'] ?? '' );
		$new_body     = wp_kses_post( $body['body'] ?? '' );

		if ( empty( $section_name ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'Missing section name' ), 400 );
		}

		$result = $this->mgr->update_section( $id, $section_name, $new_body, 'rest_api' );

		if ( ! $result ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'Patch failed' ), 500 );
		}

		$updated = $this->db->get( $id );
		return new WP_REST_Response( array( 'ok' => true, 'memory' => $updated ), 200 );
	}

	/* ================================================================
	 *  DELETE /{id}
	 * ================================================================ */

	/**
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function delete_memory( $request ) {
		$id = absint( $request->get_param( 'id' ) );
		$row = $this->db->get( $id );

		if ( ! $row ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'Not found' ), 404 );
		}

		$result = $this->mgr->archive( $id );

		return new WP_REST_Response( array(
			'ok'      => (bool) $result,
			'message' => $result ? 'Archived' : 'Failed',
		), $result ? 200 : 500 );
	}

	/* ================================================================
	 *  GET /{id}/log
	 * ================================================================ */

	/**
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function get_memory_log( $request ) {
		$id    = absint( $request->get_param( 'id' ) );
		$limit = absint( $request->get_param( 'limit' ) ) ?: 50;
		$limit = min( $limit, 200 );

		$logs = $this->log->get_logs( $id, $limit );

		return new WP_REST_Response( array(
			'ok'   => true,
			'logs' => $logs,
		), 200 );
	}

	/* ================================================================
	 *  POST /load-or-create
	 * ================================================================ */

	/**
	 * Pipeline convenience endpoint.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function load_or_create( $request ) {
		$body = $request->get_json_params();

		$args = array(
			'user_id'         => get_current_user_id(),
			'character_id'    => absint( $body['character_id'] ?? 0 ),
			'session_id'      => sanitize_text_field( $body['session_id'] ?? '' ),
			'project_id'      => sanitize_text_field( $body['project_id'] ?? '' ),
			'goal'            => sanitize_text_field( $body['goal'] ?? '' ),
			'conversation_id' => sanitize_text_field( $body['conversation_id'] ?? '' ),
		);

		$memory = $this->mgr->load_or_create( $args );

		if ( ! $memory ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'Failed to load or create' ), 500 );
		}

		$memory['parsed'] = BizCity_Memory_Parser::parse( $memory['content'] );

		return new WP_REST_Response( array( 'ok' => true, 'memory' => $memory ), 200 );
	}
}
