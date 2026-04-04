<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Skills
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

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
			'permission_callback' => function () { return is_user_logged_in(); },
		] );

		// GET /file — read .md file
		register_rest_route( self::API_NAMESPACE, '/file', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'read_file' ],
			'permission_callback' => function () { return is_user_logged_in(); },
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

		// GET /catalog — public skills catalog (frontmatter only, no raw content)
		register_rest_route( self::API_NAMESPACE, '/catalog', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_catalog' ],
			'permission_callback' => function () { return is_user_logged_in(); },
		] );

		// ── Skill-Tool Map endpoints ──

		// GET /tool-map?skill_id=N — list linked tools for a skill
		register_rest_route( self::API_NAMESPACE, '/tool-map', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_tool_map' ],
			'permission_callback' => function () { return is_user_logged_in(); },
			'args'                => [
				'skill_id' => [ 'type' => 'integer', 'required' => true ],
			],
		] );

		// POST /tool-map — link a tool to a skill { skill_id, tool_key, binding }
		register_rest_route( self::API_NAMESPACE, '/tool-map', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'link_tool' ],
			'permission_callback' => [ $this, 'check_admin' ],
		] );

		// DELETE /tool-map — unlink a tool from a skill { skill_id, tool_key }
		register_rest_route( self::API_NAMESPACE, '/tool-map', [
			'methods'             => 'DELETE',
			'callback'            => [ $this, 'unlink_tool' ],
			'permission_callback' => [ $this, 'check_admin' ],
			'args'                => [
				'skill_id' => [ 'type' => 'integer', 'required' => true ],
				'tool_key' => [ 'type' => 'string',  'required' => true ],
			],
		] );

		// PUT /tool-map/sync — sync tools_json ↔ tool_map for a skill { skill_id }
		register_rest_route( self::API_NAMESPACE, '/tool-map/sync', [
			'methods'             => 'PUT',
			'callback'            => [ $this, 'sync_tool_map' ],
			'permission_callback' => [ $this, 'check_admin' ],
		] );

		// POST /bulk-sync — re-sync all .md skill files to DB
		register_rest_route( self::API_NAMESPACE, '/bulk-sync', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'bulk_sync_to_db' ],
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

		// Resolve DB skill_id for tool-map operations
		$data['skill_id'] = $this->resolve_skill_db_id( $path, $data['frontmatter'] ?? [] );

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

		// Sync to DB and return skill_id for tool-map operations
		$parsed   = $mgr->parse_frontmatter( $raw );
		$fm       = $parsed['frontmatter'] ?? [];
		$skill_id = $this->sync_skill_to_db( $path, $fm, $parsed['content'] ?? '', $raw );

		return new \WP_REST_Response( [
			'saved'     => true,
			'path'      => $path,
			'skill_id'  => $skill_id,
			'db_synced' => $skill_id > 0,
		], 200 );
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

	/**
	 * GET /catalog — Public skills catalog (frontmatter only).
	 */
	public function get_catalog(): \WP_REST_Response {
		$mgr    = BizCity_Skill_Manager::instance();
		$skills = $mgr->get_all_skills();

		$catalog = [];
		foreach ( $skills as $s ) {
			$fm = $s['frontmatter'] ?? [];
			$catalog[] = [
				'path'        => $s['path'],
				'title'       => $fm['title'] ?? basename( $s['path'], '.md' ),
				'description' => $fm['description'] ?? '',
				'category'    => dirname( $s['path'] ),
				'modes'       => $fm['modes'] ?? [],
				'tools'       => $fm['tools'] ?? $fm['related_tools'] ?? [],
				'triggers'    => $fm['triggers'] ?? [],
				'priority'    => $fm['priority'] ?? 5,
			];
		}

		// Sort by category then title
		usort( $catalog, function ( $a, $b ) {
			$c = strcmp( $a['category'], $b['category'] );
			return $c !== 0 ? $c : strcmp( $a['title'], $b['title'] );
		} );

		return new \WP_REST_Response( [ 'skills' => $catalog, 'total' => count( $catalog ) ], 200 );
	}

	/* ══════════════════════════════════════════════════════════════
	 *  Bulk Sync — re-sync all .md files to bizcity_skills DB
	 * ══════════════════════════════════════════════════════════════ */

	/**
	 * POST /bulk-sync — iterate all skill .md files, sync each to DB.
	 */
	public function bulk_sync_to_db(): \WP_REST_Response {
		$mgr    = BizCity_Skill_Manager::instance();
		$skills = $mgr->get_all_skills();

		$synced  = 0;
		$failed  = 0;
		$errors  = [];

		foreach ( $skills as $s ) {
			$path = $s['path'] ?? '';
			$fm   = $s['frontmatter'] ?? [];
			$raw  = $s['raw'] ?? '';

			$parsed  = $mgr->parse_frontmatter( $raw );
			$content = $parsed['content'] ?? '';

			$skill_id = $this->sync_skill_to_db( $path, $fm, $content, $raw );
			if ( $skill_id > 0 ) {
				$synced++;
			} else {
				$failed++;
				$errors[] = $path;
			}
		}

		return new \WP_REST_Response( [
			'total'  => count( $skills ),
			'synced' => $synced,
			'failed' => $failed,
			'errors' => $errors,
		], 200 );
	}

	/* ══════════════════════════════════════════════════════════════
	 *  Tool-Map Handlers (bizcity_skill_tool_map CRUD)
	 * ══════════════════════════════════════════════════════════════ */

	/**
	 * GET /tool-map?skill_id=N — list linked tools for a skill.
	 */
	public function get_tool_map( \WP_REST_Request $req ): \WP_REST_Response {
		$skill_id = (int) $req->get_param( 'skill_id' );
		if ( $skill_id <= 0 ) {
			return new \WP_REST_Response( [ 'error' => 'Invalid skill_id' ], 400 );
		}

		$map   = BizCity_Skill_Tool_Map::instance();
		$tools = $map->get_tools_for_skill( $skill_id );

		return new \WP_REST_Response( [
			'skill_id' => $skill_id,
			'tools'    => $tools,
		], 200 );
	}

	/**
	 * POST /tool-map — link a tool to a skill.
	 * Body: { skill_id: int, tool_key: string, binding?: 'primary'|'secondary'|'suggested' }
	 */
	public function link_tool( \WP_REST_Request $req ): \WP_REST_Response {
		$body     = $req->get_json_params();
		$skill_id = (int) ( $body['skill_id'] ?? 0 );
		$tool_key = sanitize_text_field( $body['tool_key'] ?? '' );
		$binding  = sanitize_text_field( $body['binding'] ?? 'primary' );

		if ( $skill_id <= 0 || empty( $tool_key ) ) {
			return new \WP_REST_Response( [ 'error' => 'skill_id and tool_key are required' ], 400 );
		}

		if ( ! in_array( $binding, [ 'primary', 'secondary', 'suggested' ], true ) ) {
			$binding = 'primary';
		}

		$map    = BizCity_Skill_Tool_Map::instance();
		$map_id = $map->link( $skill_id, $tool_key, $binding );

		if ( ! $map_id ) {
			return new \WP_REST_Response( [ 'error' => 'Failed to create link' ], 500 );
		}

		return new \WP_REST_Response( [
			'linked'   => true,
			'map_id'   => $map_id,
			'skill_id' => $skill_id,
			'tool_key' => $tool_key,
			'binding'  => $binding,
		], 200 );
	}

	/**
	 * DELETE /tool-map?skill_id=N&tool_key=xxx — remove a tool link.
	 */
	public function unlink_tool( \WP_REST_Request $req ): \WP_REST_Response {
		$skill_id = (int) $req->get_param( 'skill_id' );
		$tool_key = sanitize_text_field( $req->get_param( 'tool_key' ) );

		if ( $skill_id <= 0 || empty( $tool_key ) ) {
			return new \WP_REST_Response( [ 'error' => 'skill_id and tool_key are required' ], 400 );
		}

		$map     = BizCity_Skill_Tool_Map::instance();
		$removed = $map->unlink( $skill_id, $tool_key );

		return new \WP_REST_Response( [
			'unlinked' => $removed,
			'skill_id' => $skill_id,
			'tool_key' => $tool_key,
		], 200 );
	}

	/**
	 * PUT /tool-map/sync — sync from skill's tools_json to bizcity_skill_tool_map.
	 * Body: { skill_id: int }
	 *
	 * Reads tools_json from bizcity_skills row, then:
	 * - Links tools not yet in map (as 'primary')
	 * - Does NOT remove manual bindings (only adds missing ones)
	 */
	public function sync_tool_map( \WP_REST_Request $req ): \WP_REST_Response {
		$body     = $req->get_json_params();
		$skill_id = (int) ( $body['skill_id'] ?? 0 );

		if ( $skill_id <= 0 ) {
			return new \WP_REST_Response( [ 'error' => 'skill_id is required' ], 400 );
		}

		$db    = BizCity_Skill_Database::instance();
		$skill = $db->get( $skill_id );
		if ( ! $skill ) {
			return new \WP_REST_Response( [ 'error' => 'Skill not found' ], 404 );
		}

		$tools_json = ! empty( $skill['tools_json'] ) ? json_decode( $skill['tools_json'], true ) : [];
		if ( ! is_array( $tools_json ) ) {
			$tools_json = [];
		}

		$map   = BizCity_Skill_Tool_Map::instance();
		$added = 0;
		foreach ( $tools_json as $tool_key ) {
			$tool_key = sanitize_text_field( $tool_key );
			if ( $tool_key ) {
				$map->link( $skill_id, $tool_key, 'primary' );
				$added++;
			}
		}

		$tools = $map->get_tools_for_skill( $skill_id );
		return new \WP_REST_Response( [
			'synced'   => true,
			'skill_id' => $skill_id,
			'added'    => $added,
			'tools'    => $tools,
		], 200 );
	}

	/* ══════════════════════════════════════════════════════════════
	 *  Private helpers — DB sync
	 * ══════════════════════════════════════════════════════════════ */

	/**
	 * Resolve a file path to a bizcity_skills.id.
	 * If not found in DB, returns 0.
	 */
	private function resolve_skill_db_id( string $path, array $fm ): int {
		if ( ! class_exists( 'BizCity_Skill_Database' ) ) {
			return 0;
		}
		$db        = BizCity_Skill_Database::instance();
		$skill_key = $fm['name'] ?? sanitize_title( $fm['title'] ?? basename( $path, '.md' ) );
		$row       = $db->get_by_key( $skill_key );
		return $row ? (int) $row['id'] : 0;
	}

	/**
	 * Sync file-based skill to bizcity_skills DB.
	 * Returns the skill_id.
	 */
	private function sync_skill_to_db( string $path, array $fm, string $content, string $raw = '' ): int {
		if ( ! class_exists( 'BizCity_Skill_Database' ) ) {
			error_log( '[BizCity Skills] sync_skill_to_db: BizCity_Skill_Database class not found' );
			return 0;
		}
		$db        = BizCity_Skill_Database::instance();
		$skill_key = $fm['name'] ?? sanitize_title( $fm['title'] ?? basename( $path, '.md' ) );

		// Use full raw markdown as content if body-only content is empty
		$store_content = ! empty( $content ) ? $content : $raw;

		$skill_id = $db->upsert( [
			'skill_key'      => $skill_key,
			'character_id'   => 0,
			'user_id'        => 0,
			'title'          => $fm['title'] ?? basename( $path, '.md' ),
			'description'    => $fm['description'] ?? '',
			'category'       => dirname( $path ) !== '/' ? sanitize_title( basename( dirname( $path ) ) ) : 'general',
			'triggers_json'  => $fm['triggers'] ?? [],
			'slash_commands' => $fm['slash_commands'] ?? [],
			'modes'          => $fm['modes'] ?? [],
			'tools_json'     => array_merge(
				(array) ( $fm['related_tools'] ?? [] ),
				(array) ( $fm['tools'] ?? [] )
			),
			'content'        => $store_content,
			'priority'       => (int) ( $fm['priority'] ?? 50 ),
			'status'         => $fm['status'] ?? 'active',
			'version'        => $fm['version'] ?? '1.0',
		] );

		if ( ! $skill_id ) {
			error_log( '[BizCity Skills] sync_skill_to_db FAILED for path=' . $path . ' skill_key=' . $skill_key . ' content_len=' . strlen( $store_content ) );
		}

		return $skill_id ? (int) $skill_id : 0;
	}
}
