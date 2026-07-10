<?php
/**
 * BizCity Personal — Notebook REST API
 *
 * Namespace: bizcity-personal/v1
 *
 * Endpoints:
 *   GET    /notebooks                             — list user's notebooks
 *   POST   /notebooks                             — create notebook
 *   PATCH  /notebooks/(?P<id>\d+)                 — update notebook meta
 *   DELETE /notebooks/(?P<id>\d+)                 — delete notebook + all pages + files
 *   GET    /notebooks/(?P<id>\d+)/pages           — list pages (id, title, excerpt, tags, updated_at)
 *   POST   /notebooks/(?P<id>\d+)/pages           — create page (content stored in DB + .md file)
 *   GET    /notebooks/(?P<id>\d+)/pages/(?P<pid>\d+)   — get single page (full content)
 *   PATCH  /notebooks/(?P<id>\d+)/pages/(?P<pid>\d+)   — update page
 *   DELETE /notebooks/(?P<id>\d+)/pages/(?P<pid>\d+)   — delete page
 *   POST   /notebooks/(?P<id>\d+)/pages/(?P<pid>\d+)/ingest-kg — send to KG Hub
 *
 * Security: R-GW-8 standalone — all routes require is_user_logged_in().
 * Ownership: every query scoped to current_user_id() (OWASP A01).
 *
 * PHP 7.4 compatible — no union types, no nullsafe, no match, no str_contains.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Plugins\BizCityPersonal
 * @since      2026-06-24 (PHASE-HOME-NOTEBOOKS v1.0)
 */
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Personal_Notebook_REST' ) ) { return; }

class BizCity_Personal_Notebook_REST {

	// [2026-06-24 Johnny Chu] PHASE-HOME-NOTEBOOKS — REST controller for notebooks feature

	const NS = 'bizcity-personal/v1';

	private static $instance = null;

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register_routes() {
		// ── Notebooks CRUD ────────────────────────────────────────────────────
		register_rest_route( self::NS, '/notebooks', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_notebooks' ),
				'permission_callback' => array( $this, 'is_logged_in' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_notebook' ),
				'permission_callback' => array( $this, 'is_logged_in' ),
			),
		) );

		register_rest_route( self::NS, '/notebooks/(?P<id>\d+)', array(
			array(
				'methods'             => 'PATCH',
				'callback'            => array( $this, 'update_notebook' ),
				'permission_callback' => array( $this, 'is_logged_in' ),
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'delete_notebook' ),
				'permission_callback' => array( $this, 'is_logged_in' ),
			),
		) );

		// ── Pages CRUD ────────────────────────────────────────────────────────
		register_rest_route( self::NS, '/notebooks/(?P<id>\d+)/pages', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_pages' ),
				'permission_callback' => array( $this, 'is_logged_in' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_page' ),
				'permission_callback' => array( $this, 'is_logged_in' ),
			),
		) );

		register_rest_route( self::NS, '/notebooks/(?P<id>\d+)/pages/(?P<pid>\d+)', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_page' ),
				'permission_callback' => array( $this, 'is_logged_in' ),
			),
			array(
				'methods'             => 'PATCH',
				'callback'            => array( $this, 'update_page' ),
				'permission_callback' => array( $this, 'is_logged_in' ),
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'delete_page' ),
				'permission_callback' => array( $this, 'is_logged_in' ),
			),
		) );

		// ── KG Hub ingest ────────────────────────────────────────────────────
		register_rest_route( self::NS, '/notebooks/(?P<id>\d+)/pages/(?P<pid>\d+)/ingest-kg', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'ingest_page_kg' ),
			'permission_callback' => array( $this, 'is_logged_in' ),
		) );
	}

	public function is_logged_in() {
		return is_user_logged_in();
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	private function current_uid() {
		return (int) get_current_user_id();
	}

	/**
	 * @param int $id
	 * @return array|null
	 */
	private function resolve_notebook( $id ) {
		global $wpdb;
		$t = $wpdb->prefix . 'bizcity_personal_notebooks';
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$t} WHERE id = %d AND user_id = %d",
			(int) $id, $this->current_uid()
		), ARRAY_A );
	}

	/**
	 * @param int $notebook_id
	 * @param int $page_id
	 * @return array|null
	 */
	private function resolve_page( $notebook_id, $page_id ) {
		global $wpdb;
		$t = $wpdb->prefix . 'bizcity_personal_notebook_pages';
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$t} WHERE id = %d AND notebook_id = %d AND user_id = %d",
			(int) $page_id, (int) $notebook_id, $this->current_uid()
		), ARRAY_A );
	}

	/**
	 * Build a safe page row array for API response.
	 *
	 * @param array $row
	 * @param bool  $include_content
	 * @return array
	 */
	private function format_page( array $row, $include_content = false ) {
		$tags = array();
		if ( ! empty( $row['tags'] ) ) {
			$decoded = json_decode( $row['tags'], true );
			if ( is_array( $decoded ) ) { $tags = $decoded; }
		}
		$out = array(
			'id'           => (int) $row['id'],
			'notebook_id'  => (int) $row['notebook_id'],
			'title'        => (string) $row['title'],
			'excerpt'      => (string) ( $row['excerpt'] ?? '' ),
			'tags'         => $tags,
			'mood'         => (string) ( $row['mood'] ?? '' ),
			'word_count'   => (int) ( $row['word_count'] ?? 0 ),
			'kg_source_id' => $row['kg_source_id'] ? (int) $row['kg_source_id'] : null,
			'file_path'    => (string) ( $row['file_path'] ?? '' ),
			'created_at'   => (string) $row['created_at'],
			'updated_at'   => (string) $row['updated_at'],
		);
		if ( $include_content ) {
			$out['content'] = (string) ( $row['content'] ?? '' );
		}
		return $out;
	}

	/**
	 * Update notebook page_count cache.
	 *
	 * @param int $notebook_id
	 */
	private function refresh_page_count( $notebook_id ) {
		global $wpdb;
		$pt = $wpdb->prefix . 'bizcity_personal_notebook_pages';
		$nt = $wpdb->prefix . 'bizcity_personal_notebooks';
		$cnt = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$pt} WHERE notebook_id = %d AND user_id = %d",
			(int) $notebook_id, $this->current_uid()
		) );
		$wpdb->update( $nt, array( 'page_count' => $cnt ), array( 'id' => (int) $notebook_id ) );
	}

	// ── Notebook handlers ─────────────────────────────────────────────────────

	public function list_notebooks( WP_REST_Request $request ) {
		global $wpdb;
		$t    = $wpdb->prefix . 'bizcity_personal_notebooks';
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$t} WHERE user_id = %d ORDER BY sort_order ASC, created_at ASC",
			$this->current_uid()
		), ARRAY_A );
		$out = array_map( function ( $r ) {
			return array(
				'id'          => (int) $r['id'],
				'title'       => (string) $r['title'],
				'description' => (string) ( $r['description'] ?? '' ),
				'icon'        => (string) $r['icon'],
				'color'       => (string) $r['color'],
				'is_default'  => (bool) $r['is_default'],
				'page_count'  => (int) $r['page_count'],
				'sort_order'  => (int) $r['sort_order'],
				'created_at'  => (string) $r['created_at'],
				'updated_at'  => (string) $r['updated_at'],
			);
		}, (array) $rows );
		return rest_ensure_response( array( 'success' => true, 'notebooks' => $out ) );
	}

	public function create_notebook( WP_REST_Request $request ) {
		global $wpdb;
		$t     = $wpdb->prefix . 'bizcity_personal_notebooks';
		$title = sanitize_text_field( (string) $request->get_param( 'title' ) );
		if ( ! $title ) {
			return new WP_Error( 'invalid_param', 'Tên notebook không được rỗng.', array( 'status' => 400 ) );
		}
		$data = array(
			'user_id'     => $this->current_uid(),
			'title'       => $title,
			'description' => sanitize_text_field( (string) ( $request->get_param( 'description' ) ?? '' ) ),
			'icon'        => sanitize_text_field( (string) ( $request->get_param( 'icon' ) ?? '📓' ) ),
			'color'       => sanitize_hex_color( (string) ( $request->get_param( 'color' ) ?? '#6366f1' ) ) ?: '#6366f1',
			'is_default'  => 0,
			'page_count'  => 0,
			'sort_order'  => 0,
		);
		$wpdb->insert( $t, $data );
		$id = (int) $wpdb->insert_id;
		if ( ! $id ) {
			return new WP_Error( 'db_error', 'Không thể tạo notebook.', array( 'status' => 500 ) );
		}
		return rest_ensure_response( array( 'success' => true, 'id' => $id ) );
	}

	public function update_notebook( WP_REST_Request $request ) {
		$nb = $this->resolve_notebook( $request->get_param( 'id' ) );
		if ( ! $nb ) {
			return new WP_Error( 'not_found', 'Notebook không tồn tại.', array( 'status' => 404 ) );
		}
		global $wpdb;
		$t    = $wpdb->prefix . 'bizcity_personal_notebooks';
		$data = array();
		if ( null !== $request->get_param( 'title' ) )       { $data['title']       = sanitize_text_field( (string) $request->get_param( 'title' ) ); }
		if ( null !== $request->get_param( 'description' ) ) { $data['description'] = sanitize_text_field( (string) $request->get_param( 'description' ) ); }
		if ( null !== $request->get_param( 'icon' ) )        { $data['icon']        = sanitize_text_field( (string) $request->get_param( 'icon' ) ); }
		if ( null !== $request->get_param( 'color' ) )       { $data['color']       = sanitize_hex_color( (string) $request->get_param( 'color' ) ) ?: $nb['color']; }
		if ( empty( $data ) ) {
			return new WP_Error( 'no_changes', 'Không có thay đổi.', array( 'status' => 400 ) );
		}
		$data['updated_at'] = current_time( 'mysql' );
		$wpdb->update( $t, $data, array( 'id' => (int) $nb['id'] ) );
		return rest_ensure_response( array( 'success' => true ) );
	}

	public function delete_notebook( WP_REST_Request $request ) {
		$nb = $this->resolve_notebook( $request->get_param( 'id' ) );
		if ( ! $nb ) {
			return new WP_Error( 'not_found', 'Notebook không tồn tại.', array( 'status' => 404 ) );
		}
		global $wpdb;
		$pt  = $wpdb->prefix . 'bizcity_personal_notebook_pages';
		$nt  = $wpdb->prefix . 'bizcity_personal_notebooks';
		// Delete all page files first
		$pages = $wpdb->get_results( $wpdb->prepare(
			"SELECT file_path FROM {$pt} WHERE notebook_id = %d AND user_id = %d",
			(int) $nb['id'], $this->current_uid()
		), ARRAY_A );
		foreach ( (array) $pages as $page ) {
			if ( ! empty( $page['file_path'] ) ) {
				BizCity_Personal_Notebook_File_Store::delete_page( $page['file_path'] );
			}
		}
		$wpdb->delete( $pt, array( 'notebook_id' => (int) $nb['id'], 'user_id' => $this->current_uid() ) );
		$wpdb->delete( $nt, array( 'id' => (int) $nb['id'], 'user_id' => $this->current_uid() ) );
		return rest_ensure_response( array( 'success' => true ) );
	}

	// ── Page handlers ─────────────────────────────────────────────────────────

	public function list_pages( WP_REST_Request $request ) {
		$nb = $this->resolve_notebook( $request->get_param( 'id' ) );
		if ( ! $nb ) {
			return new WP_Error( 'not_found', 'Notebook không tồn tại.', array( 'status' => 404 ) );
		}
		global $wpdb;
		$t    = $wpdb->prefix . 'bizcity_personal_notebook_pages';
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, notebook_id, title, excerpt, tags, mood, word_count, kg_source_id, file_path, created_at, updated_at
			 FROM {$t} WHERE notebook_id = %d AND user_id = %d ORDER BY updated_at DESC",
			(int) $nb['id'], $this->current_uid()
		), ARRAY_A );
		$pages = array_map( function ( $r ) { return $this->format_page( $r, false ); }, (array) $rows );
		return rest_ensure_response( array(
			'success'  => true,
			'notebook' => array(
				'id'    => (int) $nb['id'],
				'title' => (string) $nb['title'],
				'icon'  => (string) $nb['icon'],
				'color' => (string) $nb['color'],
			),
			'pages'    => $pages,
		) );
	}

	public function create_page( WP_REST_Request $request ) {
		$nb = $this->resolve_notebook( $request->get_param( 'id' ) );
		if ( ! $nb ) {
			return new WP_Error( 'not_found', 'Notebook không tồn tại.', array( 'status' => 404 ) );
		}
		global $wpdb;
		$t       = $wpdb->prefix . 'bizcity_personal_notebook_pages';
		$title   = sanitize_text_field( (string) ( $request->get_param( 'title' ) ?? 'Trang mới' ) );
		$content = wp_kses_post( (string) ( $request->get_param( 'content' ) ?? '' ) );
		$tags    = $this->parse_tags( $request->get_param( 'tags' ) );
		$mood    = sanitize_text_field( (string) ( $request->get_param( 'mood' ) ?? '' ) );
		$ingest  = (bool) $request->get_param( 'ingest_kg' );
		$uid     = $this->current_uid();

		$excerpt    = BizCity_Personal_Notebook_File_Store::make_excerpt( $content );
		$word_count = BizCity_Personal_Notebook_File_Store::word_count( $content );

		// 1. Insert DB row first to get ID
		$row_data = array(
			'notebook_id' => (int) $nb['id'],
			'user_id'     => $uid,
			'title'       => $title,
			'content'     => $content,
			'excerpt'     => $excerpt,
			'tags'        => wp_json_encode( $tags ),
			'mood'        => $mood,
			'word_count'  => $word_count,
			'kg_source_id' => null,
			'file_path'   => '',
		);
		$wpdb->insert( $t, $row_data );
		$page_id = (int) $wpdb->insert_id;
		if ( ! $page_id ) {
			return new WP_Error( 'db_error', 'Không thể tạo trang.', array( 'status' => 500 ) );
		}

		// 2. Write .md file
		$file_path = BizCity_Personal_Notebook_File_Store::write_page(
			$page_id, $title, $content,
			array( 'notebook_id' => (int) $nb['id'], 'user_id' => $uid, 'tags' => $tags, 'mood' => $mood )
		);
		if ( $file_path ) {
			$wpdb->update( $t, array( 'file_path' => $file_path ), array( 'id' => $page_id ) );
		}

		// 3. Refresh page count
		$this->refresh_page_count( (int) $nb['id'] );

		// 4. Optional KG ingest
		$kg_id = null;
		if ( $ingest ) {
			$kg_id = $this->ingest_to_kg( $page_id, $title, $content, (int) $nb['id'], $uid );
			if ( $kg_id ) {
				$wpdb->update( $t, array( 'kg_source_id' => $kg_id ), array( 'id' => $page_id ) );
			}
		}

		return rest_ensure_response( array(
			'success' => true,
			'page'    => array( 'id' => $page_id, 'file_path' => $file_path ?: '', 'kg_source_id' => $kg_id ),
		) );
	}

	public function get_page( WP_REST_Request $request ) {
		$page = $this->resolve_page( $request->get_param( 'id' ), $request->get_param( 'pid' ) );
		if ( ! $page ) {
			return new WP_Error( 'not_found', 'Trang không tồn tại.', array( 'status' => 404 ) );
		}
		// Try reading from file first (source of truth for content)
		if ( ! empty( $page['file_path'] ) ) {
			$file = BizCity_Personal_Notebook_File_Store::read_page( $page['file_path'] );
			if ( $file ) {
				$page['content'] = $file['content'];
			}
		}
		return rest_ensure_response( array( 'success' => true, 'page' => $this->format_page( $page, true ) ) );
	}

	public function update_page( WP_REST_Request $request ) {
		$page = $this->resolve_page( $request->get_param( 'id' ), $request->get_param( 'pid' ) );
		if ( ! $page ) {
			return new WP_Error( 'not_found', 'Trang không tồn tại.', array( 'status' => 404 ) );
		}
		global $wpdb;
		$t    = $wpdb->prefix . 'bizcity_personal_notebook_pages';
		$data = array( 'updated_at' => current_time( 'mysql' ) );

		if ( null !== $request->get_param( 'title' ) ) {
			$data['title'] = sanitize_text_field( (string) $request->get_param( 'title' ) );
		}
		if ( null !== $request->get_param( 'content' ) ) {
			$content            = wp_kses_post( (string) $request->get_param( 'content' ) );
			$data['content']    = $content;
			$data['excerpt']    = BizCity_Personal_Notebook_File_Store::make_excerpt( $content );
			$data['word_count'] = BizCity_Personal_Notebook_File_Store::word_count( $content );
		}
		if ( null !== $request->get_param( 'tags' ) ) {
			$data['tags'] = wp_json_encode( $this->parse_tags( $request->get_param( 'tags' ) ) );
		}
		if ( null !== $request->get_param( 'mood' ) ) {
			$data['mood'] = sanitize_text_field( (string) $request->get_param( 'mood' ) );
		}

		$wpdb->update( $t, $data, array( 'id' => (int) $page['id'] ) );

		// Update .md file
		$final_title   = isset( $data['title'] )   ? $data['title']   : $page['title'];
		$final_content = isset( $data['content'] ) ? $data['content'] : $page['content'];
		$final_tags    = isset( $data['tags'] )    ? json_decode( $data['tags'], true ) : json_decode( $page['tags'], true );
		$final_mood    = isset( $data['mood'] )    ? $data['mood']    : $page['mood'];
		$file_path = BizCity_Personal_Notebook_File_Store::write_page(
			(int) $page['id'], $final_title, $final_content,
			array(
				'notebook_id' => (int) $page['notebook_id'],
				'user_id'     => $this->current_uid(),
				'tags'        => (array) $final_tags,
				'mood'        => (string) $final_mood,
				'created_at'  => $page['created_at'],
			)
		);
		if ( $file_path && $file_path !== $page['file_path'] ) {
			$wpdb->update( $t, array( 'file_path' => $file_path ), array( 'id' => (int) $page['id'] ) );
		}

		return rest_ensure_response( array( 'success' => true ) );
	}

	public function delete_page( WP_REST_Request $request ) {
		$page = $this->resolve_page( $request->get_param( 'id' ), $request->get_param( 'pid' ) );
		if ( ! $page ) {
			return new WP_Error( 'not_found', 'Trang không tồn tại.', array( 'status' => 404 ) );
		}
		if ( ! empty( $page['file_path'] ) ) {
			BizCity_Personal_Notebook_File_Store::delete_page( $page['file_path'] );
		}
		global $wpdb;
		$t = $wpdb->prefix . 'bizcity_personal_notebook_pages';
		$wpdb->delete( $t, array( 'id' => (int) $page['id'], 'user_id' => $this->current_uid() ) );
		$this->refresh_page_count( (int) $page['notebook_id'] );
		return rest_ensure_response( array( 'success' => true ) );
	}

	public function ingest_page_kg( WP_REST_Request $request ) {
		$page = $this->resolve_page( $request->get_param( 'id' ), $request->get_param( 'pid' ) );
		if ( ! $page ) {
			return new WP_Error( 'not_found', 'Trang không tồn tại.', array( 'status' => 404 ) );
		}
		// Read full content from file
		$content = (string) ( $page['content'] ?? '' );
		if ( ! empty( $page['file_path'] ) ) {
			$file = BizCity_Personal_Notebook_File_Store::read_page( $page['file_path'] );
			if ( $file ) { $content = $file['content']; }
		}
		$kg_id = $this->ingest_to_kg( (int) $page['id'], (string) $page['title'], $content, (int) $page['notebook_id'], $this->current_uid() );
		if ( ! $kg_id ) {
			return new WP_Error( 'kg_error', 'Không thể gửi vào KG Hub. Kiểm tra KG Hub đã kích hoạt chưa.', array( 'status' => 500 ) );
		}
		global $wpdb;
		$t = $wpdb->prefix . 'bizcity_personal_notebook_pages';
		$wpdb->update( $t, array( 'kg_source_id' => $kg_id ), array( 'id' => (int) $page['id'] ) );
		return rest_ensure_response( array( 'success' => true, 'kg_source_id' => $kg_id ) );
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	/**
	 * Ingest page content into KG Hub.
	 * Fail-OPEN: returns 0 if KG Hub not available.
	 *
	 * @param int    $page_id
	 * @param string $title
	 * @param string $content
	 * @param int    $notebook_id
	 * @param int    $user_id
	 * @return int   kg_source_id or 0 on failure
	 */
	private function ingest_to_kg( $page_id, $title, $content, $notebook_id, $user_id ) {
		// [2026-06-24 Johnny Chu] PHASE-HOME-NOTEBOOKS PATH-B — use plugin='personal' (own registry entry)
		if ( ! class_exists( 'BizCity_KG' ) ) { return 0; }
		// scope_id = bizcity_personal_notebooks.id — already known as $notebook_id
		// Verify the scope is visible in registry (available_scopes returns [] until filter fires)
		$scopes = BizCity_KG::available_scopes( $user_id, array( 'plugin' => 'personal', 'scope_type' => 'personal_notebook' ) );
		if ( empty( $scopes ) ) {
			// Registry not loaded yet (happens on first request) — call service directly
			if ( ! class_exists( 'BizCity_Personal_KG_Service' ) ) { return 0; }
			try {
				$result = BizCity_Personal_KG_Service::instance()->ingest(
					$notebook_id,
					$user_id,
					array(
						'type'        => 'text',
						'title'       => $title,
						'content'     => $content,
						'source_meta' => array( 'page_id' => $page_id, 'notebook_id' => $notebook_id ),
					)
				);
				if ( is_wp_error( $result ) ) { return 0; }
				return (int) ( isset( $result['source_id'] ) ? $result['source_id'] : 0 );
			} catch ( Exception $e ) {
				error_log( '[bizcity-personal] Notebook KG ingest error (direct): ' . $e->getMessage() );
				return 0;
			}
		}
		// Use facade (preferred path after registry is loaded)
		try {
			$result = BizCity_KG::ingest(
				array( 'plugin' => 'personal', 'scope_id' => (int) $notebook_id ),
				array(
					'type'        => 'text',
					'title'       => $title,
					'content'     => $content,
					'source_meta' => array( 'page_id' => $page_id, 'notebook_id' => $notebook_id ),
				)
			);
			if ( is_wp_error( $result ) ) { return 0; }
			return (int) ( isset( $result['source_id'] ) ? $result['source_id'] : 0 );
		} catch ( Exception $e ) {
			error_log( '[bizcity-personal] Notebook KG ingest error: ' . $e->getMessage() );
			return 0;
		}
	}

	/**
	 * Parse tags from request param — accepts string[] or comma-string.
	 *
	 * @param mixed $raw
	 * @return string[]
	 */
	private function parse_tags( $raw ) {
		if ( ! $raw ) { return array(); }
		if ( is_array( $raw ) ) {
			return array_values( array_filter( array_map( 'sanitize_text_field', $raw ) ) );
		}
		$parts = explode( ',', (string) $raw );
		return array_values( array_filter( array_map( function ( $t ) { return sanitize_text_field( trim( $t ) ); }, $parts ) ) );
	}
}
