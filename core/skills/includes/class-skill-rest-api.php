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
 * Namespace: bizcity/skill/v1
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

	const API_NAMESPACE = 'bizcity/skill/v1';

	private static $instance = null;

	public static function instance(): self {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );

		// Strip BOM bytes leaked by other included PHP files (UTF-8 BOM = EF BB BF)
		add_action( 'rest_api_init', function () {
			if ( ob_get_level() ) {
				$buf = ob_get_clean();
				// Remove BOM characters
				$buf = str_replace( "\xEF\xBB\xBF", '', $buf );
				if ( $buf !== '' ) {
					echo $buf;
				}
			}
		}, 999 );
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

		// POST /generate — AI-generate skill markdown from a natural language prompt
		register_rest_route( self::API_NAMESPACE, '/generate', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'generate_skill_ai' ],
			'permission_callback' => [ $this, 'check_admin' ],
		] );

		// GET /debug — trace get_tree internals (remove after debugging)
		register_rest_route( self::API_NAMESPACE, '/debug', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'debug_tree' ],
			'permission_callback' => '__return_true',
		] );

		// GET /skills — list all skills from DB (grouped, filterable)
		register_rest_route( self::API_NAMESPACE, '/skills', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'list_skills_db' ],
			'permission_callback' => function () { return is_user_logged_in(); },
		] );

		// GET /skill/{id}  — read single skill from DB
		// PUT /skill/{id}  — update skill in DB (also auto-maps @tool mentions)
		// DELETE /skill/{id} — delete skill from DB
		register_rest_route( self::API_NAMESPACE, '/skill/(?P<id>\d+)', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'read_skill_db' ],
				'permission_callback' => function () { return is_user_logged_in(); },
				'args'                => [ 'id' => [ 'type' => 'integer', 'required' => true ] ],
			],
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'update_skill_db' ],
				'permission_callback' => [ $this, 'check_admin' ],
				'args'                => [ 'id' => [ 'type' => 'integer', 'required' => true ] ],
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'delete_skill_db' ],
				'permission_callback' => [ $this, 'check_admin' ],
				'args'                => [ 'id' => [ 'type' => 'integer', 'required' => true ] ],
			],
		] );
	}

	/* ── Permission ────────────────────────────────────────────── */

	public function check_admin(): bool {
		// manage_options = super-admin only trên multisite → đổi sang edit_posts
		return current_user_can( 'edit_posts' );
	}

	/* ── Handlers ──────────────────────────────────────────────── */

	/** GET /debug — trace internals of get_tree augmentation */
	public function debug_tree(): \WP_REST_Response {
		$mgr  = BizCity_Skill_Manager::instance();
		$tree = $mgr->get_file_tree();

		$db_class_exists = class_exists( 'BizCity_Skill_Database' );
		$db_rows         = [];
		$existing_paths  = [];
		$error           = null;

		// Reconstruct folder path map exactly as get_tree() does
		$folder_path_by_id = [ '0' => '' ];
		foreach ( $tree as $entry ) {
			if ( ! empty( $entry['isDir'] ) && $entry['id'] !== '0' && ! empty( $entry['path'] ) ) {
				$folder_path_by_id[ $entry['id'] ] = rtrim( $entry['path'], '/' );
			}
		}
		foreach ( $tree as $entry ) {
			if ( $entry['isDir'] ) continue;
			if ( ! empty( $entry['path'] ) ) {
				$existing_paths[] = $entry['path'];
			} else {
				$parent_path = $folder_path_by_id[ $entry['parentId'] ?? '0' ] ?? '';
				$existing_paths[] = $parent_path . '/' . $entry['name'];
			}
		}

		if ( $db_class_exists ) {
			try {
				$db      = BizCity_Skill_Database::instance();
				$active  = $db->list_skills( [ 'limit' => 200, 'status' => 'active' ] );
				$draft   = $db->list_skills( [ 'limit' => 200, 'status' => 'draft' ] );
				$all_db  = array_merge( $active, $draft );
				foreach ( $all_db as $s ) {
					$category  = $s['category'] ?: 'root';
					$skill_key = $s['skill_key'];
					$vpath     = "/{$category}/{$skill_key}.md";
					$vpath_root = "/{$skill_key}.md";
					$in_existing = in_array( $vpath, $existing_paths, true )
						|| in_array( $vpath_root, $existing_paths, true )
						|| in_array( '/general/' . $skill_key . '.md', $existing_paths, true )
						|| in_array( '/root/' . $skill_key . '.md', $existing_paths, true );

					$db_rows[] = [
						'id'          => $s['id'],
						'skill_key'   => $skill_key,
						'category'    => $category,
						'vpath'       => $vpath,
						'vpath_root'  => $vpath_root,
						'skipped'     => $in_existing,
					];
				}
			} catch ( \Throwable $e ) {
				$error = $e->getMessage();
			}
		}

		return new \WP_REST_Response( [
			'db_class_exists'    => $db_class_exists,
			'fs_tree_count'      => count( $tree ),
			'existing_paths'     => $existing_paths,
			'folder_path_by_id'  => $folder_path_by_id,
			'db_skills'          => $db_rows,
			'error'              => $error,
		], 200 );
	}

	public function get_tree(): \WP_REST_Response {
		$mgr  = BizCity_Skill_Manager::instance();
		$tree = $mgr->get_file_tree();

		// Augment filesystem tree with DB-only skills (no .md file on disk).
		if ( class_exists( 'BizCity_Skill_Database' ) ) {
			$db     = BizCity_Skill_Database::instance();
			$all_db = array_merge(
				$db->list_skills( [ 'limit' => 200, 'status' => 'active' ] ),
				$db->list_skills( [ 'limit' => 200, 'status' => 'draft' ] )
			);

			// Build folder id → path map.
			// Root id='0' → '' (empty) so child files get '/{name}'.
			// Skip id='0' in the loop to avoid overwriting with '/'.
			$folder_path_by_id = [ '0' => '' ];
			foreach ( $tree as $entry ) {
				if ( ! empty( $entry['isDir'] ) && $entry['id'] !== '0' && ! empty( $entry['path'] ) ) {
					$folder_path_by_id[ $entry['id'] ] = rtrim( $entry['path'], '/' );
				}
			}

			// Build a full-path index for every file currently in the tree.
			$existing_paths = [];
			foreach ( $tree as $entry ) {
				if ( $entry['isDir'] ) continue;
				if ( ! empty( $entry['path'] ) ) {
					$existing_paths[ $entry['path'] ] = true;
				} else {
					$parent_path = $folder_path_by_id[ $entry['parentId'] ?? '0' ] ?? '';
					$existing_paths[ $parent_path . '/' . $entry['name'] ] = true;
				}
			}

			// Index folder IDs by folder name
			$folder_ids = [];
			foreach ( $tree as $entry ) {
				if ( ! empty( $entry['isDir'] ) && $entry['id'] !== '0' ) {
					$folder_ids[ $entry['name'] ] = $entry['id'];
				}
			}

			foreach ( $all_db as $skill ) {
				$category   = $skill['category'] ?: 'root';
				$skill_key  = $skill['skill_key'];
				$vpath      = "/{$category}/{$skill_key}.md";
				$vpath_root = "/{$skill_key}.md"; // fallback for root-level files

				// Skip if file already present on disk (match by category path OR root path)
				if (
					isset( $existing_paths[ $vpath ] ) ||
					isset( $existing_paths[ $vpath_root ] ) ||
					isset( $existing_paths[ '/general/' . $skill_key . '.md' ] ) ||
					isset( $existing_paths[ '/root/' . $skill_key . '.md' ] )
				) {
					continue;
				}

				// Ensure category folder exists in tree
				if ( ! isset( $folder_ids[ $category ] ) ) {
					$folder_id             = 'db_folder_' . $category;
					$tree[]                = [
						'id'       => $folder_id,
						'name'     => $category,
						'isDir'    => true,
						'path'     => "/{$category}",
						'parentId' => '0',
					];
					$folder_ids[ $category ] = $folder_id;
				}

				// Inject synthetic virtual file node
				$tree[] = [
					'id'           => 'db_' . $skill['id'],
					'name'         => $skill_key . '.md',
					'isDir'        => false,
					'parentId'     => $folder_ids[ $category ],
					'sk_id'        => (int) $skill['id'],
					'sk_title'     => $skill['title'],
					'sk_status'    => $skill['status'],
					'virtual'      => true,
					'lastModified' => strtotime( $skill['updated_at'] ?? '' ) ?: 0,
				];
			}
		}

		return new \WP_REST_Response( $tree, 200 );
	}

	public function read_file( \WP_REST_Request $req ): \WP_REST_Response {
		$path = sanitize_text_field( $req->get_param( 'path' ) );
		$mgr  = BizCity_Skill_Manager::instance();

		// Virtual DB node path: 'db_{skill_id}'
		if ( preg_match( '/^db_(\d+)$/', $path, $m ) ) {
			return $this->read_skill_db_by_id( (int) $m[1] );
		}

		$data = $mgr->read_file( $path );

		// Fallback: path might be a skill_key not on disk → read from DB
		if ( is_wp_error( $data ) && class_exists( 'BizCity_Skill_Database' ) ) {
			$skill_key = sanitize_title( basename( $path, '.md' ) );
			$db        = BizCity_Skill_Database::instance();
			$row       = $db->get_by_key( $skill_key );
			if ( $row ) {
				return $this->read_skill_db_by_id( (int) $row['id'] );
			}
		}

		if ( is_wp_error( $data ) ) {
			return new \WP_REST_Response( [ 'error' => $data->get_error_message() ], 404 );
		}

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

	/** POST /generate — AI-generates a full skill .md from a natural language prompt */
	public function generate_skill_ai( \WP_REST_Request $req ): \WP_REST_Response {
		$body   = $req->get_json_params();
		$prompt = sanitize_textarea_field( $body['prompt'] ?? '' );

		if ( ! $prompt ) {
			return new \WP_REST_Response( [ 'error' => 'Prompt is required' ], 400 );
		}

		// Build system instruction
		$tools_list = '';
		$mgr        = BizCity_Skill_Manager::instance();
		if ( method_exists( $mgr, 'get_tools_catalog' ) ) {
			$catalog = $mgr->get_tools_catalog();
			$all     = [];
			foreach ( $catalog['groups'] ?? [] as $g ) {
				foreach ( $g['tools'] ?? [] as $t ) {
					$all[] = '@' . $t['toolName'];
				}
			}
			$tools_list = implode( ', ', array_slice( $all, 0, 30 ) );
		}

		$system = 'Bạn là AI chuyên tạo skill file cho hệ thống BizCity Twin AI.' . "\n"
			. 'Hãy tạo một file skill hoàn chỉnh ở định dạng Markdown với YAML frontmatter.' . "\n\n"
			. "Cấu trúc bắt buộc:\n"
			. "---\n"
			. "name: [tên ngắn, snake_case]\n"
			. "title: [tiêu đề đầy đủ]\n"
			. 'description: "[mô tả 1-2 câu]"' . "\n"
			. "version: \"1.0\"\n"
			. "status: active\n"
			. "category: [nhóm phù hợp]\n"
			. "triggers:\n  - \"[từ khoá kích hoạt skill]\"\n"
			. "slash_commands: []\n"
			. "modes: [chat, assistant]\n"
			. "related_tools: []\n"
			. "required_inputs: []\n"
			. "priority: 50\n"
			. "---\n\n"
			. "# [Tiêu đề Skill]\n\n"
			. "## Mục tiêu\n[Mô tả rõ mục tiêu của skill]\n\n"
			. "## Ngữ cảnh\n[Khi nào dùng skill này]\n\n"
			. "## Hướng dẫn thực thi\n[Các bước hoặc hướng dẫn chi tiết]\n\n"
			. "## Ví dụ\n[Ví dụ cụ thể]\n\n"
			. "Nếu cần gọi tool, dùng cú pháp @tool_name trong nội dung.\n"
			. 'Danh sách tools hiện có: ' . $tools_list . "\n\n"
			. 'Chỉ trả về nội dung file Markdown, không giải thích thêm.';

		// Call BizCity LLM Client directly (same PHP process — no internal HTTP)
		if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
			return new \WP_REST_Response( [ 'error' => 'BizCity LLM Client chưa được tải.' ], 503 );
		}

		$llm     = BizCity_LLM_Client::instance();
		$result  = $llm->chat(
			[
				[ 'role' => 'system', 'content' => $system ],
				[ 'role' => 'user',   'content' => $prompt ],
			],
			[
				'purpose'     => 'chat',
				'max_tokens'  => 1500,
				'temperature' => 0.7,
			]
		);

		$markdown = '';
		if ( ! empty( $result['success'] ) && ! empty( $result['message'] ) ) {
			$markdown = $result['message'];
		}

		if ( ! $markdown ) {
			return new \WP_REST_Response( [
				'error' => 'AI không phản hồi: ' . ( $result['error'] ?? 'Unknown error' ),
			], 502 );
		}

		// Strip any ```markdown fences if present
		$markdown = preg_replace( '/^```(?:markdown|md)?\r?\n/', '', $markdown );
		$markdown = preg_replace( '/\r?\n```$/', '', $markdown );

		return new \WP_REST_Response( [
			'markdown' => trim( $markdown ),
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
	/* ══════════════════════════════════════════════════════════════
	 *  DB Skill CRUD Handlers (GET /skills, GET|PUT|DELETE /skill/{id})
	 * ══════════════════════════════════════════════════════════════ */

	public function list_skills_db( \WP_REST_Request $req ): \WP_REST_Response {
		if ( ! class_exists( 'BizCity_Skill_Database' ) ) {
			return new \WP_REST_Response( [ 'error' => 'DB class not found' ], 500 );
		}
		$db     = BizCity_Skill_Database::instance();
		$status = sanitize_text_field( $req->get_param( 'status' ) ?: '' );
		$search = sanitize_text_field( $req->get_param( 'search' ) ?: '' );

		$filters = [ 'limit' => 200 ];
		if ( $status ) $filters['status'] = $status;
		if ( $search ) $filters['search'] = $search;

		$rows    = $db->list_skills( $filters );
		$grouped = [];
		$skills  = [];

		foreach ( $rows as $row ) {
			$skill          = $this->db_row_to_skill_array( $row );
			$skills[]       = $skill;
			$cat            = $skill['category'] ?: 'general';
			$grouped[ $cat ][] = $skill;
		}

		return new \WP_REST_Response( [
			'skills'  => $skills,
			'grouped' => $grouped,
			'total'   => count( $skills ),
		], 200 );
	}

	public function read_skill_db( \WP_REST_Request $req ): \WP_REST_Response {
		$id = (int) $req->get_param( 'id' );
		return $this->read_skill_db_by_id( $id );
	}

	private function read_skill_db_by_id( int $id ): \WP_REST_Response {
		if ( ! class_exists( 'BizCity_Skill_Database' ) ) {
			return new \WP_REST_Response( [ 'error' => 'DB class not found' ], 500 );
		}
		$db  = BizCity_Skill_Database::instance();
		$row = $db->get( $id );
		if ( ! $row ) {
			return new \WP_REST_Response( [ 'error' => 'Skill not found' ], 404 );
		}
		$fm  = $this->db_row_to_frontmatter( $row );
		$raw = $this->reconstruct_md( $fm, $row['content'] ?? '' );

		$tool_bindings = [];
		if ( class_exists( 'BizCity_Skill_Tool_Map' ) ) {
			$tool_bindings = BizCity_Skill_Tool_Map::instance()->get_tools_for_skill( $id );
		}

		return new \WP_REST_Response( [
			'path'          => "/{$row['category']}/{$row['skill_key']}.md",
			'frontmatter'   => $fm,
			'content'       => $row['content'] ?? '',
			'raw'           => $raw,
			'skill_id'      => $id,
			'source'        => 'db',
			'tool_bindings' => $tool_bindings,
		], 200 );
	}

	public function update_skill_db( \WP_REST_Request $req ): \WP_REST_Response {
		$id   = (int) $req->get_param( 'id' );
		$body = $req->get_json_params();
		$raw  = $body['raw'] ?? '';

		if ( ! class_exists( 'BizCity_Skill_Database' ) ) {
			return new \WP_REST_Response( [ 'error' => 'DB class not found' ], 500 );
		}

		// Strip script tags from content
		$raw  = preg_replace( '/<script\b[^>]*>.*?<\/script>/is', '', $raw );
		$mgr  = BizCity_Skill_Manager::instance();
		$parsed = $mgr->parse_frontmatter( $raw );
		$fm   = $parsed['frontmatter'] ?? [];
		$body_content = $parsed['content'] ?? '';

		// Extract @tool_name mentions and auto-populate skill_tool_map
		$mentioned_tools = $this->extract_at_tool_mentions( $body_content );
		$frontmatter_tools = array_merge(
			(array) ( $fm['related_tools'] ?? [] ),
			(array) ( $fm['tools'] ?? [] )
		);
		$all_tools = array_unique( array_merge( $mentioned_tools, $frontmatter_tools ) );

		$db        = BizCity_Skill_Database::instance();
		$skill_key = $fm['name'] ?? '';

		$upsert_data = [
			'title'         => $fm['title'] ?? '',
			'description'   => $fm['description'] ?? '',
			'category'      => $fm['category'] ?? 'general',
			'triggers_json' => $fm['triggers'] ?? [],
			'slash_commands'=> $fm['slash_commands'] ?? [],
			'modes'         => $fm['modes'] ?? [],
			'tools_json'    => $all_tools,
			'content'       => $body_content,
			'priority'      => (int) ( $fm['priority'] ?? 50 ),
			'status'        => $fm['status'] ?? 'active',
		];

		// Update fields on the existing row
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_skills';
		foreach ( $upsert_data as &$v ) {
			if ( is_array( $v ) ) $v = wp_json_encode( $v, JSON_UNESCAPED_UNICODE );
		}
		unset( $v );
		$wpdb->update( $table, $upsert_data, [ 'id' => $id ] );

		// Auto-map @mentioned and frontmatter tools
		if ( class_exists( 'BizCity_Skill_Tool_Map' ) && ! empty( $all_tools ) ) {
			$map = BizCity_Skill_Tool_Map::instance();
			foreach ( $all_tools as $tool_key ) {
				$tool_key = sanitize_text_field( $tool_key );
				if ( $tool_key ) {
					$map->link( $id, $tool_key, 'primary' );
				}
			}
		}

		return new \WP_REST_Response( [
			'saved'    => true,
			'skill_id' => $id,
			'tools_mapped' => count( $all_tools ),
		], 200 );
	}

	public function delete_skill_db( \WP_REST_Request $req ): \WP_REST_Response {
		$id = (int) $req->get_param( 'id' );
		if ( ! class_exists( 'BizCity_Skill_Database' ) ) {
			return new \WP_REST_Response( [ 'error' => 'DB class not found' ], 500 );
		}
		global $wpdb;
		$table   = $wpdb->prefix . 'bizcity_skills';
		$deleted = $wpdb->delete( $table, [ 'id' => $id ] );
		if ( ! $deleted ) {
			return new \WP_REST_Response( [ 'error' => 'Skill not found or already deleted' ], 404 );
		}
		return new \WP_REST_Response( [ 'deleted' => true, 'id' => $id ], 200 );
	}

	/* ── Private helpers ──────────────────────────────────────────── */

	/** Convert DB row to frontmatter array */
	private function db_row_to_frontmatter( array $row ): array {
		$triggers = [];
		if ( ! empty( $row['triggers_json'] ) ) {
			$triggers = json_decode( $row['triggers_json'], true ) ?: [];
		}
		$tools = [];
		if ( ! empty( $row['tools_json'] ) ) {
			$tools = json_decode( $row['tools_json'], true ) ?: [];
		}
		$slash_commands = ! empty( $row['slash_commands'] ) ? explode( ',', $row['slash_commands'] ) : [];
		$modes          = ! empty( $row['modes'] ) ? explode( ',', $row['modes'] ) : [];

		return [
			'name'           => $row['skill_key'],
			'title'          => $row['title'] ?? '',
			'description'    => $row['description'] ?? '',
			'category'       => $row['category'] ?? 'general',
			'status'         => $row['status'] ?? 'active',
			'priority'       => (int) ( $row['priority'] ?? 50 ),
			'modes'          => $modes,
			'slash_commands' => $slash_commands,
			'triggers'       => $triggers,
			'tools'          => $tools,
			'version'        => $row['version'] ?? '1.0',
		];
	}

	/** Convert DB row to flat skill array for list responses */
	private function db_row_to_skill_array( array $row ): array {
		$fm = $this->db_row_to_frontmatter( $row );
		return array_merge( $fm, [
			'id'         => (int) $row['id'],
			'skill_key'  => $row['skill_key'],
			'updated_at' => $row['updated_at'] ?? '',
		] );
	}

	/** Reconstruct full .md content from frontmatter + body */
	private function reconstruct_md( array $fm, string $body ): string {
		$yaml = "---\n";
		foreach ( $fm as $key => $val ) {
			if ( is_array( $val ) ) {
				$yaml .= "{$key}:\n";
				foreach ( $val as $item ) {
					$yaml .= "  - " . $item . "\n";
				}
			} else {
				$yaml .= "{$key}: " . $val . "\n";
			}
		}
		$yaml .= "---\n";
		return $yaml . ( $body ? "\n" . ltrim( $body ) : '' );
	}

	/** Extract @tool_name mentions from skill body content */
	private function extract_at_tool_mentions( string $body ): array {
		$matches = [];
		preg_match_all( '/@([a-z][a-z0-9_-]{1,80})\b/i', $body, $matches );
		return array_values( array_unique( array_filter( $matches[1] ?? [] ) ) );
	}

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

		$mentioned_tools = $this->extract_at_tool_mentions( $store_content );
		$all_tools       = array_unique( array_merge(
			(array) ( $fm['related_tools'] ?? [] ),
			(array) ( $fm['tools'] ?? [] ),
			$mentioned_tools
		) );

		$skill_id = $db->upsert( [
			'skill_key'      => $skill_key,
			'character_id'   => 0,
			'user_id'        => 0,
			'title'          => $fm['title'] ?? basename( $path, '.md' ),
			'description'    => $fm['description'] ?? '',
			'category'       => dirname( $path ) !== '/' ? sanitize_title( basename( dirname( $path ) ) ) : 'root',
			'triggers_json'  => $fm['triggers'] ?? [],
			'slash_commands' => $fm['slash_commands'] ?? [],
			'modes'          => $fm['modes'] ?? [],
			'tools_json'     => $all_tools,
			'content'        => $store_content,
			'priority'       => (int) ( $fm['priority'] ?? 50 ),
			'status'         => $fm['status'] ?? 'active',
			'version'        => $fm['version'] ?? '1.0',
		] );

		if ( ! $skill_id ) {
			error_log( '[BizCity Skills] sync_skill_to_db FAILED for path=' . $path . ' skill_key=' . $skill_key . ' content_len=' . strlen( $store_content ) );
		}

		// Auto-link @mentioned tools in skill_tool_map
		if ( $skill_id && ! empty( $mentioned_tools ) && class_exists( 'BizCity_Skill_Tool_Map' ) ) {
			$map = BizCity_Skill_Tool_Map::instance();
			foreach ( $mentioned_tools as $tool_key ) {
				$map->link( (int) $skill_id, sanitize_text_field( $tool_key ), 'primary' );
			}
		}

		// Fire canonical hook — listeners can react to any skill save (Phase 1.9 S2.7)
		if ( $skill_id ) {
			do_action( 'bizcity_skill_saved', (int) $skill_id, $store_content, $fm['title'] ?? '' );
		}

		return $skill_id ? (int) $skill_id : 0;
	}
}
