<?php
/**
 * BizCity Skills — REST API
 *
 * Namespace: bizcity-skill/v1
 *
 * Endpoints:
 *   GET    /tree           — File system tree (for ReactFileManager)
 *   GET    /file           — Read a .md file (?path=/content/x.md)
 *   POST   /file           — Create/update a .md file
 *   DELETE /file           — Delete a .md file
 *   POST   /folder         — Create a category folder
 *   DELETE /folder         — Delete an empty folder
 *   POST   /test           — Test skill matching
 *
 * @package  BizCity_Skills
 * @since    2026-03-31
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Skill_REST_API {

	const API_NAMESPACE = 'bizcity-skill/v1';

	private static $instance = null;

	public static function instance(): self {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {

		// GET /tree — file system for ReactFileManager
		register_rest_route( self::API_NAMESPACE, '/tree', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_tree' ],
			'permission_callback' => [ $this, 'check_admin' ],
		] );

		// GET /file — read .md file
		register_rest_route( self::API_NAMESPACE, '/file', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'read_file' ],
			'permission_callback' => [ $this, 'check_admin' ],
			'args'                => [
				'path' => [ 'type' => 'string', 'required' => true ],
			],
		] );

		// POST /file — create or update .md file
		register_rest_route( self::API_NAMESPACE, '/file', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'write_file' ],
			'permission_callback' => [ $this, 'check_admin' ],
		] );

		// DELETE /file — delete .md file
		register_rest_route( self::API_NAMESPACE, '/file', [
			'methods'             => 'DELETE',
			'callback'            => [ $this, 'delete_file' ],
			'permission_callback' => [ $this, 'check_admin' ],
			'args'                => [
				'path' => [ 'type' => 'string', 'required' => true ],
			],
		] );

		// POST /folder — create folder
		register_rest_route( self::API_NAMESPACE, '/folder', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'create_folder' ],
			'permission_callback' => [ $this, 'check_admin' ],
		] );

		// DELETE /folder — delete empty folder
		register_rest_route( self::API_NAMESPACE, '/folder', [
			'methods'             => 'DELETE',
			'callback'            => [ $this, 'delete_folder' ],
			'permission_callback' => [ $this, 'check_admin' ],
			'args'                => [
				'path' => [ 'type' => 'string', 'required' => true ],
			],
		] );

		// POST /test — test matching
		register_rest_route( self::API_NAMESPACE, '/test', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'test_matching' ],
			'permission_callback' => [ $this, 'check_admin' ],
		] );
	}

	/* ── Permission ────────────────────────────────────────────── */

	public function check_admin(): bool {
		return current_user_can( 'manage_options' );
	}

	/* ── Handlers ──────────────────────────────────────────────── */

	public function get_tree(): \WP_REST_Response {
		$mgr  = BizCity_Skill_Manager::instance();
		$tree = $mgr->get_file_tree();
		return new \WP_REST_Response( $tree, 200 );
	}

	public function read_file( \WP_REST_Request $req ): \WP_REST_Response {
		$path = sanitize_text_field( $req->get_param( 'path' ) );
		$mgr  = BizCity_Skill_Manager::instance();
		$data = $mgr->read_file( $path );

		if ( is_wp_error( $data ) ) {
			return new \WP_REST_Response( [ 'error' => $data->get_error_message() ], 404 );
		}

		return new \WP_REST_Response( $data, 200 );
	}

	public function write_file( \WP_REST_Request $req ): \WP_REST_Response {
		$body = $req->get_json_params();
		$path = sanitize_text_field( $body['path'] ?? '' );
		$raw  = $body['raw'] ?? '';

		if ( ! $path ) {
			return new \WP_REST_Response( [ 'error' => 'Path is required' ], 400 );
		}

		// Sanitize: allow markdown content but strip script tags
		$raw = preg_replace( '/<script\b[^>]*>.*?<\/script>/is', '', $raw );

		$mgr    = BizCity_Skill_Manager::instance();
		$result = $mgr->write_file( $path, $raw );

		if ( is_wp_error( $result ) ) {
			return new \WP_REST_Response( [ 'error' => $result->get_error_message() ], 400 );
		}

		return new \WP_REST_Response( [ 'saved' => true, 'path' => $path ], 200 );
	}

	public function delete_file( \WP_REST_Request $req ): \WP_REST_Response {
		$path   = sanitize_text_field( $req->get_param( 'path' ) );
		$mgr    = BizCity_Skill_Manager::instance();
		$result = $mgr->delete_file( $path );

		if ( is_wp_error( $result ) ) {
			return new \WP_REST_Response( [ 'error' => $result->get_error_message() ], 400 );
		}

		return new \WP_REST_Response( [ 'deleted' => true ], 200 );
	}

	public function create_folder( \WP_REST_Request $req ): \WP_REST_Response {
		$body = $req->get_json_params();
		$name = sanitize_file_name( $body['name'] ?? '' );

		if ( ! $name ) {
			return new \WP_REST_Response( [ 'error' => 'Folder name is required' ], 400 );
		}

		// If parentPath provided, create inside it
		$parent = sanitize_text_field( $body['parentPath'] ?? '' );
		$full   = $parent ? rtrim( $parent, '/' ) . '/' . $name : '/' . $name;

		$mgr    = BizCity_Skill_Manager::instance();
		$result = $mgr->create_folder( $full );

		if ( is_wp_error( $result ) ) {
			return new \WP_REST_Response( [ 'error' => $result->get_error_message() ], 400 );
		}

		return new \WP_REST_Response( [ 'created' => true, 'path' => $full ], 201 );
	}

	public function delete_folder( \WP_REST_Request $req ): \WP_REST_Response {
		$path   = sanitize_text_field( $req->get_param( 'path' ) );
		$mgr    = BizCity_Skill_Manager::instance();
		$result = $mgr->delete_file( $path ); // delete_file handles dirs too

		if ( is_wp_error( $result ) ) {
			return new \WP_REST_Response( [ 'error' => $result->get_error_message() ], 400 );
		}

		return new \WP_REST_Response( [ 'deleted' => true ], 200 );
	}

	public function test_matching( \WP_REST_Request $req ): \WP_REST_Response {
		$body    = $req->get_json_params();
		$mgr     = BizCity_Skill_Manager::instance();
		$matches = $mgr->find_matching( [
			'message' => sanitize_text_field( $body['message'] ?? '' ),
			'mode'    => sanitize_text_field( $body['mode'] ?? '' ),
			'goal'    => sanitize_text_field( $body['goal'] ?? '' ),
			'tool'    => sanitize_text_field( $body['tool'] ?? '' ),
			'limit'   => 5,
		] );

		$result = [];
		foreach ( $matches as $m ) {
			$result[] = [
				'path'        => $m['path'],
				'title'       => $m['frontmatter']['title'] ?? basename( $m['path'], '.md' ),
				'score'       => $m['score'],
				'reasons'     => $m['reasons'],
				'frontmatter' => $m['frontmatter'],
			];
		}

		return new \WP_REST_Response( $result, 200 );
	}
}
