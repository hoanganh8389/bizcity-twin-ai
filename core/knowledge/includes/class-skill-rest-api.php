<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * BizCity Skill Library — REST API
 *
 * Namespace: bizcity-skill/v1
 *
 * Endpoints:
 *   GET    /skills          — List all skills (filter by mode, status, category, search)
 *   GET    /skills/{id}     — Get single skill
 *   POST   /skills          — Create skill
 *   PUT    /skills/{id}     — Update skill
 *   DELETE /skills/{id}     — Delete skill
 *   POST   /skills/test     — Test message against skill matching
 *   GET    /skills/categories — List distinct categories
 *
 * @package  BizCity_Knowledge
 * @since    2026-03-31
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Skill_REST_API {

	const NAMESPACE = 'bizcity-skill/v1';

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
		// GET /skills — list
		register_rest_route( self::NAMESPACE, '/skills', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'list_skills' ],
			'permission_callback' => [ $this, 'check_admin' ],
			'args'                => [
				'status'   => [ 'type' => 'string', 'default' => '' ],
				'mode'     => [ 'type' => 'string', 'default' => '' ],
				'category' => [ 'type' => 'string', 'default' => '' ],
				'search'   => [ 'type' => 'string', 'default' => '' ],
			],
		] );

		// GET /skills/categories — distinct categories
		register_rest_route( self::NAMESPACE, '/skills/categories', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'list_categories' ],
			'permission_callback' => [ $this, 'check_admin' ],
		] );

		// POST /skills/test — test matching
		register_rest_route( self::NAMESPACE, '/skills/test', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'test_matching' ],
			'permission_callback' => [ $this, 'check_admin' ],
			'args'                => [
				'message' => [ 'type' => 'string', 'required' => true ],
				'mode'    => [ 'type' => 'string', 'default' => '' ],
				'goal'    => [ 'type' => 'string', 'default' => '' ],
				'tool'    => [ 'type' => 'string', 'default' => '' ],
			],
		] );

		// GET /skills/{id} — single
		register_rest_route( self::NAMESPACE, '/skills/(?P<id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_skill' ],
			'permission_callback' => [ $this, 'check_admin' ],
		] );

		// POST /skills — create
		register_rest_route( self::NAMESPACE, '/skills', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'create_skill' ],
			'permission_callback' => [ $this, 'check_admin' ],
		] );

		// PUT /skills/{id} — update
		register_rest_route( self::NAMESPACE, '/skills/(?P<id>\d+)', [
			'methods'             => 'PUT',
			'callback'            => [ $this, 'update_skill' ],
			'permission_callback' => [ $this, 'check_admin' ],
		] );

		// DELETE /skills/{id} — delete
		register_rest_route( self::NAMESPACE, '/skills/(?P<id>\d+)', [
			'methods'             => 'DELETE',
			'callback'            => [ $this, 'delete_skill' ],
			'permission_callback' => [ $this, 'check_admin' ],
		] );
	}

	/* ================================================================
	 *  Permission
	 * ================================================================ */

	public function check_admin(): bool {
		return current_user_can( 'manage_options' );
	}

	/* ================================================================
	 *  Handlers
	 * ================================================================ */

	public function list_skills( \WP_REST_Request $req ): \WP_REST_Response {
		$db    = BizCity_Skill_Database::instance();
		$items = $db->get_all( [
			'status'   => $req->get_param( 'status' ),
			'mode'     => $req->get_param( 'mode' ),
			'category' => $req->get_param( 'category' ),
			'search'   => $req->get_param( 'search' ),
		] );

		$result = array_map( [ $this, 'format_skill' ], $items );
		return new \WP_REST_Response( $result, 200 );
	}

	public function list_categories(): \WP_REST_Response {
		$db   = BizCity_Skill_Database::instance();
		$cats = $db->get_categories();
		return new \WP_REST_Response( $cats, 200 );
	}

	public function get_skill( \WP_REST_Request $req ): \WP_REST_Response {
		$db    = BizCity_Skill_Database::instance();
		$skill = $db->get_by_id( (int) $req['id'] );

		if ( ! $skill ) {
			return new \WP_REST_Response( [ 'error' => 'Skill not found' ], 404 );
		}

		$data = $this->format_skill( $skill );
		$data['usage_stats'] = $db->get_usage_stats( $skill->id );
		return new \WP_REST_Response( $data, 200 );
	}

	public function create_skill( \WP_REST_Request $req ): \WP_REST_Response {
		$data = $this->extract_skill_data( $req );
		$data['author_id'] = get_current_user_id();

		$db = BizCity_Skill_Database::instance();

		// Check unique key
		if ( ! empty( $data['skill_key'] ) && $db->key_exists( $data['skill_key'] ) ) {
			return new \WP_REST_Response( [ 'error' => 'Skill key "' . $data['skill_key'] . '" đã tồn tại' ], 409 );
		}

		$id = $db->save( $data );
		if ( ! $id ) {
			return new \WP_REST_Response( [ 'error' => 'Không thể tạo skill' ], 500 );
		}

		$skill = $db->get_by_id( $id );
		return new \WP_REST_Response( $this->format_skill( $skill ), 201 );
	}

	public function update_skill( \WP_REST_Request $req ): \WP_REST_Response {
		$id = (int) $req['id'];
		$db = BizCity_Skill_Database::instance();

		$existing = $db->get_by_id( $id );
		if ( ! $existing ) {
			return new \WP_REST_Response( [ 'error' => 'Skill not found' ], 404 );
		}

		$data       = $this->extract_skill_data( $req );
		$data['id'] = $id;

		// Check unique key (exclude self)
		if ( ! empty( $data['skill_key'] ) && $db->key_exists( $data['skill_key'], $id ) ) {
			return new \WP_REST_Response( [ 'error' => 'Skill key "' . $data['skill_key'] . '" đã tồn tại' ], 409 );
		}

		$result = $db->save( $data );
		if ( ! $result ) {
			return new \WP_REST_Response( [ 'error' => 'Không thể cập nhật skill' ], 500 );
		}

		$skill = $db->get_by_id( $id );
		return new \WP_REST_Response( $this->format_skill( $skill ), 200 );
	}

	public function delete_skill( \WP_REST_Request $req ): \WP_REST_Response {
		$id = (int) $req['id'];
		$db = BizCity_Skill_Database::instance();

		if ( ! $db->get_by_id( $id ) ) {
			return new \WP_REST_Response( [ 'error' => 'Skill not found' ], 404 );
		}

		$db->delete( $id );
		return new \WP_REST_Response( [ 'deleted' => true ], 200 );
	}

	public function test_matching( \WP_REST_Request $req ): \WP_REST_Response {
		$db      = BizCity_Skill_Database::instance();
		$matches = $db->find_matching( [
			'message' => sanitize_text_field( $req->get_param( 'message' ) ),
			'mode'    => sanitize_text_field( $req->get_param( 'mode' ) ),
			'goal'    => sanitize_text_field( $req->get_param( 'goal' ) ),
			'tool'    => sanitize_text_field( $req->get_param( 'tool' ) ),
			'limit'   => 5,
		] );

		$result = [];
		foreach ( $matches as $m ) {
			$result[] = [
				'skill_id'  => $m['skill']->id,
				'skill_key' => $m['skill']->skill_key,
				'title'     => $m['skill']->title,
				'score'     => $m['score'],
				'reasons'   => $m['reasons'],
			];
		}

		return new \WP_REST_Response( $result, 200 );
	}

	/* ================================================================
	 *  Helpers
	 * ================================================================ */

	private function extract_skill_data( \WP_REST_Request $req ): array {
		$body = $req->get_json_params();
		$data = [];

		$string_fields = [ 'skill_key', 'title', 'slug', 'description', 'content_md', 'status', 'version', 'source_type', 'category' ];
		foreach ( $string_fields as $f ) {
			if ( isset( $body[ $f ] ) ) {
				$data[ $f ] = $f === 'content_md' ? wp_kses_post( $body[ $f ] ) : sanitize_text_field( $body[ $f ] );
			}
		}

		$json_fields = [ 'triggers_json', 'modes_json', 'related_tools_json', 'related_plugins_json' ];
		foreach ( $json_fields as $f ) {
			if ( isset( $body[ $f ] ) ) {
				$data[ $f ] = is_array( $body[ $f ] ) ? $body[ $f ] : [];
			}
		}

		if ( isset( $body['priority'] ) ) {
			$data['priority'] = max( 0, min( 100, (int) $body['priority'] ) );
		}

		return $data;
	}

	private function format_skill( object $skill ): array {
		return [
			'id'               => (int) $skill->id,
			'skill_key'        => $skill->skill_key,
			'title'            => $skill->title,
			'slug'             => $skill->slug,
			'description'      => $skill->description ?? '',
			'content_md'       => $skill->content_md ?? '',
			'triggers'         => json_decode( $skill->triggers_json ?: '[]', true ) ?: [],
			'modes'            => json_decode( $skill->modes_json ?: '[]', true ) ?: [],
			'related_tools'    => json_decode( $skill->related_tools_json ?: '[]', true ) ?: [],
			'related_plugins'  => json_decode( $skill->related_plugins_json ?: '[]', true ) ?: [],
			'priority'         => (int) $skill->priority,
			'status'           => $skill->status,
			'version'          => $skill->version ?? '1.0',
			'source_type'      => $skill->source_type ?? 'db',
			'category'         => $skill->category ?? '',
			'author_id'        => (int) $skill->author_id,
			'use_count'        => (int) $skill->use_count,
			'last_used_at'     => $skill->last_used_at,
			'created_at'       => $skill->created_at,
			'updated_at'       => $skill->updated_at,
		];
	}
}
