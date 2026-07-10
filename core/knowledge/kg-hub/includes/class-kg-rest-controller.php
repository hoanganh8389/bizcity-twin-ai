<?php
/**
 * Bizcity Twin AI — KG_Rest_Controller
 *
 * REST namespace: bizcity-knowledge/v2
 * All routes require WP nonce + manage_options (Phase A).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge\KG_Hub
 * @since      2026-04-25
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_KG_Rest_Controller {

	const NAMESPACE_V2 = 'bizcity-knowledge/v2';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function permission() {
		return current_user_can( 'manage_options' );
	}

	public function register_routes() {
		$ns = self::NAMESPACE_V2;
		$perm = [ $this, 'permission' ];

		register_rest_route( $ns, '/notebooks', [
			[ 'methods' => 'GET',  'callback' => [ $this, 'list_notebooks' ],   'permission_callback' => $perm ],
			[ 'methods' => 'POST', 'callback' => [ $this, 'create_notebook' ],  'permission_callback' => $perm ],
		] );

		// ── Wave 0.18.1c — Workspaces (per-user folder grouping over notebooks) ──
		// Workspaces are stored in user_meta `bizcity_kg_workspaces` (JSON array of
		// { id:string, name:string, color?:string, createdAt:string }). Notebook
		// membership is stored in `notebooks.settings.workspace_id`.
		register_rest_route( $ns, '/workspaces', [
			[ 'methods' => 'GET', 'callback' => [ $this, 'list_workspaces' ], 'permission_callback' => $perm ],
			[ 'methods' => 'PUT', 'callback' => [ $this, 'save_workspaces' ], 'permission_callback' => $perm ],
		] );
		register_rest_route( $ns, '/notebooks/(?P<id>\d+)', [
			[ 'methods' => 'GET',    'callback' => [ $this, 'get_notebook' ],    'permission_callback' => $perm ],
			[ 'methods' => 'PATCH',  'callback' => [ $this, 'update_notebook' ], 'permission_callback' => $perm ],
			[ 'methods' => 'DELETE', 'callback' => [ $this, 'delete_notebook' ], 'permission_callback' => $perm ],
		] );
		register_rest_route( $ns, '/notebooks/(?P<id>\d+)/sources', [
			'methods'  => 'POST',
			'callback' => [ $this, 'attach_source' ],
			'permission_callback' => $perm,
		] );
		register_rest_route( $ns, '/notebooks/(?P<id>\d+)/sources/available', [
			'methods'  => 'GET',
			'callback' => [ $this, 'list_available_sources' ],
			'permission_callback' => $perm,
		] );
		register_rest_route( $ns, '/notebooks/(?P<id>\d+)/passages', [
			[ 'methods' => 'GET',  'callback' => [ $this, 'list_passages' ], 'permission_callback' => $perm ],
			[ 'methods' => 'POST', 'callback' => [ $this, 'add_passage' ],   'permission_callback' => $perm ],
		] );
		register_rest_route( $ns, '/notebooks/(?P<id>\d+)/extract', [
			'methods'  => 'POST',
			'callback' => [ $this, 'extract_pending' ],
			'permission_callback' => $perm,
		] );
		register_rest_route( $ns, '/notebooks/(?P<id>\d+)/triplet-queue', [
			'methods'  => 'GET',
			'callback' => [ $this, 'list_queue' ],
			'permission_callback' => $perm,
		] );
		register_rest_route( $ns, '/notebooks/(?P<id>\d+)/graph', [
			'methods'  => 'GET',
			'callback' => [ $this, 'get_graph' ],
			'permission_callback' => $perm,
		] );
		// Phase 4.5b — Global graph (all notebooks merged) for BrainHome Nexus view.
		register_rest_route( $ns, '/graph', [
			'methods'  => 'GET',
			'callback' => [ $this, 'get_graph_global' ],
			'permission_callback' => $perm,
		] );
		register_rest_route( $ns, '/notebooks/(?P<id>\d+)/query', [
			'methods'  => 'POST',
			'callback' => [ $this, 'query' ],
			'permission_callback' => $perm,
		] );

		register_rest_route( $ns, '/notebooks/(?P<id>\d+)/triplet-queue/approve-all', [
			'methods'  => 'POST',
			'callback' => [ $this, 'approve_all_triplets' ],
			'permission_callback' => $perm,
		] );
		register_rest_route( $ns, '/triplet-queue/(?P<id>\d+)/approve', [
			'methods'  => 'POST',
			'callback' => [ $this, 'approve_triplet' ],
			'permission_callback' => $perm,
		] );
		register_rest_route( $ns, '/triplet-queue/(?P<id>\d+)/reject', [
			'methods'  => 'POST',
			'callback' => [ $this, 'reject_triplet' ],
			'permission_callback' => $perm,
		] );
		register_rest_route( $ns, '/entities/merge', [
			'methods'  => 'POST',
			'callback' => [ $this, 'merge_entities' ],
			'permission_callback' => $perm,
		] );

		// ── Phase 0.5 Sprint 3 — Editable graph ───────────────────────────
		register_rest_route( $ns, '/entities/(?P<id>\d+)', [
			[ 'methods' => 'PATCH',  'callback' => [ $this, 'update_entity' ], 'permission_callback' => $perm ],
			[ 'methods' => 'DELETE', 'callback' => [ $this, 'delete_entity' ], 'permission_callback' => $perm ],
		] );
		// 4.9.5 — supporting passages for an entity (read-only).
		register_rest_route( $ns, '/entities/(?P<id>\d+)/passages', [
			'methods'  => 'GET',
			'callback' => [ $this, 'get_entity_passages' ],
			'permission_callback' => $perm,
		] );
		// 4.10.3 — entities mentioned in a source (read-only).
		register_rest_route( $ns, '/sources/(?P<id>\d+)/entities', [
			'methods'  => 'GET',
			'callback' => [ $this, 'get_source_entities' ],
			'permission_callback' => $perm,
		] );
		register_rest_route( $ns, '/relations/(?P<id>\d+)', [
			[ 'methods' => 'PATCH',  'callback' => [ $this, 'update_relation' ], 'permission_callback' => $perm ],
			[ 'methods' => 'DELETE', 'callback' => [ $this, 'delete_relation' ], 'permission_callback' => $perm ],
		] );
		register_rest_route( $ns, '/relations', [
			'methods'  => 'POST',
			'callback' => [ $this, 'create_manual_relation' ],
			'permission_callback' => $perm,
		] );

		// ── Phase 0.5 Sprint 2 — Studio adapter ───────────────────────────
		register_rest_route( $ns, '/studio/backfill', [
			'methods'  => 'POST',
			'callback' => [ $this, 'studio_backfill' ],
			'permission_callback' => $perm,
		] );

		// ── Phase 0.5 Sprint 1 — Cost telemetry ───────────────────────────
		register_rest_route( $ns, '/cost/today', [
			'methods'  => 'GET',
			'callback' => [ $this, 'cost_today' ],
			'permission_callback' => $perm,
		] );

		// ── PHASE-0.13 Wave 10c — Per-source learning evidence trail ──────
		register_rest_route( $ns, '/sources/(?P<id>\d+)/progress-log', [
			'methods'  => 'GET',
			'callback' => [ $this, 'source_progress_log' ],
			'permission_callback' => $perm,
		] );
		register_rest_route( $ns, '/notebooks/(?P<id>\d+)/progress-log', [
			'methods'  => 'GET',
			'callback' => [ $this, 'notebook_progress_log' ],
			'permission_callback' => $perm,
		] );

		// ── Phase 0.21 Wave 3.0 — Guru Builder (promote notebook → guru) ──
		register_rest_route( $ns, '/notebooks/(?P<id>\d+)/promote-to-guru/preview', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'guru_promote_preview' ],
			'permission_callback' => $perm,
		] );
		register_rest_route( $ns, '/notebooks/(?P<id>\d+)/promote-to-guru', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'guru_promote_notebook' ],
			'permission_callback' => $perm,
			'args'                => [
				'name'          => [ 'required' => true,  'type' => 'string' ],
				'slug'          => [ 'required' => false, 'type' => 'string' ],
				'description'   => [ 'required' => false, 'type' => 'string' ],
				'system_prompt' => [ 'required' => false, 'type' => 'string' ],
				'mode'          => [ 'required' => false, 'type' => 'string', 'default' => 'clone' ],
			],
		] );

		// ── Phase 0.21 Wave 3.1 — Notebook ↔ Guru attach API ──────────────
		register_rest_route( $ns, '/notebooks/(?P<id>\d+)/attached-gurus', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'list_attached_gurus' ],
			'permission_callback' => $perm,
		] );
		register_rest_route( $ns, '/notebooks/(?P<id>\d+)/attached-gurus', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'attach_guru' ],
			'permission_callback' => $perm,
			'args'                => [
				'guru_uuid'        => [ 'required' => false, 'type' => 'string' ],
				'character_id'     => [ 'required' => false, 'type' => 'integer' ],
				'source'           => [ 'required' => false, 'type' => 'string', 'default' => 'self' ],
				'read_only'        => [ 'required' => false, 'type' => 'boolean', 'default' => true ],
				'attached_version' => [ 'required' => false, 'type' => 'string' ],
			],
		] );
		register_rest_route( $ns, '/notebooks/(?P<id>\d+)/attached-gurus/(?P<uuid>[0-9a-fA-F-]{36})', [
			'methods'             => 'DELETE',
			'callback'            => [ $this, 'detach_guru' ],
			'permission_callback' => $perm,
		] );

		// List candidate gurus (characters with guru_uuid stamped) — for attach picker.
		register_rest_route( $ns, '/gurus', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'list_gurus' ],
			'permission_callback' => $perm,
		] );
	}

	// ─── Notebook handlers ─────────────────────────────────────────────────

	public function list_notebooks( WP_REST_Request $req ) {
		return rest_ensure_response(
			BizCity_KG_Notebook_Service::instance()->list_for_user( get_current_user_id() )
		);
	}

	public function create_notebook( WP_REST_Request $req ) {
		$data = $req->get_json_params() ?: $req->get_params();
		return rest_ensure_response(
			BizCity_KG_Notebook_Service::instance()->create( (array) $data, get_current_user_id() )
		);
	}

	/**
	 * Fetch a notebook and assert the current user is the owner (or site admin).
	 * Returns the notebook row on success, WP_Error on failure.
	 *
	 * @param int $nb_id
	 * @return array|WP_Error
	 */
	private function assert_notebook_owner( $nb_id ) {
		$nb = BizCity_KG_Notebook_Service::instance()->get( (int) $nb_id );
		if ( ! $nb ) {
			return new WP_Error( 'not_found', 'Notebook not found', [ 'status' => 404 ] );
		}
		$owner = (int) ( $nb['owner_id'] ?? 0 );
		if ( $owner !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'forbidden', 'Access denied: you do not own this notebook', [ 'status' => 403 ] );
		}
		return $nb;
	}

	public function get_notebook( WP_REST_Request $req ) {
		$nb = $this->assert_notebook_owner( (int) $req['id'] );
		return is_wp_error( $nb ) ? $nb : rest_ensure_response( $nb );
	}

	public function update_notebook( WP_REST_Request $req ) {
		$nb = $this->assert_notebook_owner( (int) $req['id'] );
		if ( is_wp_error( $nb ) ) return $nb;
		$data = $req->get_json_params() ?: $req->get_params();
		return rest_ensure_response(
			BizCity_KG_Notebook_Service::instance()->update( (int) $req['id'], (array) $data )
		);
	}

	public function delete_notebook( WP_REST_Request $req ) {
		$nb = $this->assert_notebook_owner( (int) $req['id'] );
		if ( is_wp_error( $nb ) ) return $nb;
		BizCity_KG_Notebook_Service::instance()->delete( (int) $req['id'] );
		return rest_ensure_response( [ 'deleted' => true ] );
	}

	// ─── Workspace handlers (Wave 0.18.1c) ─────────────────────────────────
	// Workspaces are per-user folder labels stored in user_meta as a JSON array.
	// They are NOT a separate DB table — keeps schema light while syncing across
	// devices/sessions. Default workspace `ws_default` is auto-seeded.

	const USER_META_WORKSPACES = 'bizcity_kg_workspaces';

	/**
	 * Returns the site-specific user meta key for workspaces.
	 * Appends the current blog ID so Multisite sites each maintain their own
	 * workspace list (wp_usermeta is a global table shared across all sites).
	 */
	private function workspaces_meta_key() {
		$blog_id = is_multisite() ? (int) get_current_blog_id() : 0;
		return $blog_id > 1 ? self::USER_META_WORKSPACES . '_' . $blog_id : self::USER_META_WORKSPACES;
	}

	private function default_workspaces() {
		return [
			[ 'id' => 'ws_default', 'name' => 'Notebook', 'color' => '#1976e7', 'createdAt' => current_time( 'mysql' ) ],
		];
	}

	public function list_workspaces( WP_REST_Request $req ) {
		$user_id  = get_current_user_id();
		$meta_key = $this->workspaces_meta_key();
		// [2026-06-22 Johnny Chu] R-PERF — route via BizCity_User_Meta_Cache to avoid WP meta prime
		$raw = class_exists( 'BizCity_User_Meta_Cache' )
			? BizCity_User_Meta_Cache::get( $user_id, $meta_key, '' )
			: get_user_meta( $user_id, $meta_key, true );
		$list = is_string( $raw ) && $raw !== '' ? json_decode( $raw, true ) : $raw;
		if ( ! is_array( $list ) || empty( $list ) ) {
			$list = $this->default_workspaces();
			update_user_meta( $user_id, $meta_key, wp_json_encode( $list ) );
		}
		// Normalize entries.
		$out = [];
		foreach ( $list as $w ) {
			if ( ! is_array( $w ) || empty( $w['id'] ) || empty( $w['name'] ) ) continue;
			$out[] = [
				'id'        => sanitize_key( $w['id'] ),
				'name'      => sanitize_text_field( $w['name'] ),
				'color'     => isset( $w['color'] ) ? sanitize_hex_color( $w['color'] ) ?: '' : '',
				'createdAt' => isset( $w['createdAt'] ) ? sanitize_text_field( $w['createdAt'] ) : current_time( 'mysql' ),
			];
		}
		if ( empty( $out ) ) $out = $this->default_workspaces();
		return rest_ensure_response( $out );
	}

	public function save_workspaces( WP_REST_Request $req ) {
		$data = $req->get_json_params() ?: $req->get_params();
		$list = isset( $data['workspaces'] ) && is_array( $data['workspaces'] ) ? $data['workspaces'] : ( is_array( $data ) ? $data : [] );
		$clean = [];
		$has_default = false;
		foreach ( $list as $w ) {
			if ( ! is_array( $w ) ) continue;
			$id   = isset( $w['id'] ) ? sanitize_key( $w['id'] ) : '';
			$name = isset( $w['name'] ) ? sanitize_text_field( $w['name'] ) : '';
			if ( ! $id || ! $name ) continue;
			if ( $id === 'ws_default' ) $has_default = true;
			$clean[] = [
				'id'        => $id,
				'name'      => $name,
				'color'     => isset( $w['color'] ) ? sanitize_hex_color( $w['color'] ) ?: '' : '',
				'createdAt' => isset( $w['createdAt'] ) ? sanitize_text_field( $w['createdAt'] ) : current_time( 'mysql' ),
			];
		}
		// Always keep a default workspace at index 0.
		if ( ! $has_default ) {
			array_unshift( $clean, $this->default_workspaces()[0] );
		}
		update_user_meta( get_current_user_id(), $this->workspaces_meta_key(), wp_json_encode( $clean ) );
		return rest_ensure_response( $clean );
	}

	// ─── Source / Passage handlers ─────────────────────────────────────────

	public function attach_source( WP_REST_Request $req ) {
		$data = $req->get_json_params() ?: $req->get_params();
		$source_id = (int) ( $data['source_id'] ?? 0 );
		if ( ! $source_id ) {
			return new WP_Error( 'bad_request', 'source_id required', [ 'status' => 400 ] );
		}
		return rest_ensure_response(
			BizCity_KG_Source_Service::instance()->attach_source( (int) $req['id'], $source_id )
		);
	}

	public function list_available_sources( WP_REST_Request $req ) {
		return rest_ensure_response(
			BizCity_KG_Source_Service::instance()->list_available_sources( [
				'limit'      => (int) $req->get_param( 'limit' ) ?: 50,
				'search'     => (string) $req->get_param( 'search' ),
				'project_id' => $req->get_param( 'project_id' ),
				'user_id'    => $req->get_param( 'user_id' ) ? (int) $req->get_param( 'user_id' ) : null,
			] )
		);
	}

	public function add_passage( WP_REST_Request $req ) {
		$data    = $req->get_json_params() ?: $req->get_params();
		$content = (string) ( $data['content'] ?? '' );
		$origin  = (string) ( $data['origin']  ?? 'manual' );
		$res     = BizCity_KG_Source_Service::instance()->add_passage( (int) $req['id'], $content, $origin );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return rest_ensure_response( [ 'passage_id' => $res ] );
	}

	public function list_passages( WP_REST_Request $req ) {
		return rest_ensure_response(
			BizCity_KG_Source_Service::instance()->list_passages( (int) $req['id'], [
				'limit'  => (int) $req->get_param( 'limit' ) ?: 50,
				'offset' => (int) $req->get_param( 'offset' ) ?: 0,
			] )
		);
	}

	public function extract_pending( WP_REST_Request $req ) {
		$data          = $req->get_json_params() ?: [];
		// [2026-06-08 Johnny Chu] HOTFIX — use hub-synced batch_size (via BizCity_KG_Cost_Guard) as default
		// instead of hardcoded 5 so the client respects hub admin setting (e.g. 20).
		$default_limit = class_exists( 'BizCity_KG_Cost_Guard' ) ? BizCity_KG_Cost_Guard::instance()->batch_size() : 5;
		$limit         = (int) ( $data['limit'] ?? $default_limit );
		$force         = ! empty( $data['force'] );
		return rest_ensure_response(
			BizCity_KG_Triplet_Extractor::instance()->extract_notebook_pending( (int) $req['id'], $limit, $force )
		);
	}

	// ─── Triplet queue handlers ────────────────────────────────────────────

	public function list_queue( WP_REST_Request $req ) {
		$status = sanitize_key( $req->get_param( 'status' ) ?: 'pending' );
		return rest_ensure_response(
			BizCity_KG_Graph_Service::instance()->list_queue( (int) $req['id'], $status, 200 )
		);
	}

	public function approve_triplet( WP_REST_Request $req ) {
		$res = BizCity_KG_Graph_Service::instance()->approve_triplet( (int) $req['id'], get_current_user_id() );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return rest_ensure_response( [ 'approved' => true, 'relation_id' => $res ] );
	}

	public function approve_all_triplets( WP_REST_Request $req ) {
		$result = BizCity_KG_Graph_Service::instance()->approve_all_pending( (int) $req['id'], get_current_user_id() );
		return rest_ensure_response( $result );
	}

	public function reject_triplet( WP_REST_Request $req ) {
		BizCity_KG_Graph_Service::instance()->reject_triplet( (int) $req['id'], get_current_user_id() );
		return rest_ensure_response( [ 'rejected' => true ] );
	}

	// ─── Graph + Query ─────────────────────────────────────────────────────

	public function get_graph( WP_REST_Request $req ) {
		$limit = (int) ( $req->get_param( 'limit' ) ?: 200 );
		return rest_ensure_response(
			BizCity_KG_Graph_Service::instance()->get_full_graph( (int) $req['id'], $limit )
		);
	}

	/**
	 * Phase 4.5b — Global graph scoped to the current user's notebooks.
	 * (Admins with manage_options see ALL notebooks to allow cross-user debugging.)
	 */
	public function get_graph_global( WP_REST_Request $req ) {
		$limit   = (int) ( $req->get_param( 'limit' ) ?: 600 );
		$user_id = get_current_user_id();

		// TBR.F2-cite (2026-05-13) — caller can request must-include entity ids
		// (e.g. cited nodes from Ask Brain) so the orange highlight always lands
		// on a node that actually exists in the graph payload.
		$include_raw = (string) $req->get_param( 'include' );
		$include_ids = [];
		if ( $include_raw !== '' ) {
			foreach ( explode( ',', $include_raw ) as $tok ) {
				$id = (int) trim( $tok );
				if ( $id > 0 ) $include_ids[ $id ] = true;
			}
			$include_ids = array_keys( $include_ids );
			if ( count( $include_ids ) > 500 ) $include_ids = array_slice( $include_ids, 0, 500 );
		}

		if ( current_user_can( 'manage_options' ) && $req->get_param( 'all' ) ) {
			// Admin explicit opt-in: pass empty array → no notebook filter.
			$nb_ids = [];
		} else {
			$notebooks = BizCity_KG_Notebook_Service::instance()->list_for_user( $user_id, [ 'limit' => 500 ] );
			$nb_ids    = array_map( 'intval', array_column( $notebooks, 'id' ) );
		}

		return rest_ensure_response(
			BizCity_KG_Graph_Service::instance()->get_global_graph( $limit, $nb_ids, $include_ids )
		);
	}

	public function query( WP_REST_Request $req ) {
		$data     = $req->get_json_params() ?: $req->get_params();
		$question = trim( (string) ( $data['question'] ?? '' ) );
		if ( $question === '' ) {
			return new WP_Error( 'bad_request', 'question required', [ 'status' => 400 ] );
		}
		$opts = [];
		foreach ( [ 'seed_entities', 'seed_relations', 'rerank_top_k', 'expand_hops' ] as $k ) {
			if ( isset( $data[ $k ] ) ) $opts[ $k ] = (int) $data[ $k ];
		}
		if ( isset( $data['answer'] ) ) $opts['answer'] = (bool) $data['answer'];

		return rest_ensure_response(
			BizCity_KG_Retriever::instance()->ask( (int) $req['id'], $question, $opts )
		);
	}

	public function merge_entities( WP_REST_Request $req ) {
		$data         = $req->get_json_params() ?: $req->get_params();
		$canonical_id = (int) ( $data['canonical_id'] ?? 0 );
		$other_ids    = (array) ( $data['other_ids']  ?? [] );
		if ( ! $canonical_id || empty( $other_ids ) ) {
			return new WP_Error( 'bad_request', 'canonical_id + other_ids required', [ 'status' => 400 ] );
		}
		$count = BizCity_KG_Graph_Service::instance()->merge_entities( $canonical_id, $other_ids );
		return rest_ensure_response( [ 'merged' => $count ] );
	}

	// ─── Phase 0.5 Sprint 3: editable graph ────────────────────────────────

	public function update_entity( WP_REST_Request $req ) {
		$data = $req->get_json_params() ?: $req->get_params();
		$res  = BizCity_KG_Graph_Service::instance()->update_entity( (int) $req['id'], (array) $data );
		return is_wp_error( $res ) ? $res : rest_ensure_response( $res );
	}

	public function delete_entity( WP_REST_Request $req ) {
		BizCity_KG_Graph_Service::instance()->soft_delete_entity( (int) $req['id'] );
		return rest_ensure_response( [ 'deleted' => true, 'soft' => true ] );
	}

	/**
	 * 4.9.5 — List passages mentioning the given entity within a notebook scope.
	 * Joins kg_passages × kg_passage_entities and (when available) kg_sources.
	 */
	public function get_entity_passages( WP_REST_Request $req ) {
		global $wpdb;
		$entity_id   = (int) $req['id'];
		$notebook_id = (int) $req->get_param( 'notebook_id' );
		$limit       = max( 1, min( 50, (int) ( $req->get_param( 'limit' ) ?: 10 ) ) );
		if ( $entity_id <= 0 ) {
			return new WP_Error( 'bad_request', 'Invalid entity id', [ 'status' => 400 ] );
		}

		$db = BizCity_KG_Database::instance();
		$tp = $db->tbl_passages();
		$tpe = $db->tbl_passage_entities();
		$ts  = method_exists( $db, 'tbl_sources' ) ? $db->tbl_sources() : '';

		$where_nb = $notebook_id > 0 ? $wpdb->prepare( ' AND p.notebook_id = %d', $notebook_id ) : '';

		// 2026-05-05 — schema (kg_source_chunks / legacy kg_passages) does NOT carry
		// `heading_path` or `page_no` columns; they live inside `metadata` JSON when
		// present. Selecting them caused "Unknown column" errors that nuked entity
		// popups for any blog whose passages table never had those legacy cols.
		if ( $ts ) {
			$sql = $wpdb->prepare(
				"SELECT DISTINCT p.id AS passage_id, p.id, p.content, p.metadata,
				        p.source_id, p.notebook_id,
				        p.storage_ver, p.file_shard, p.file_offset, p.file_length,
				        s.title AS source_title
				   FROM {$tp} p
				   INNER JOIN {$tpe} pe ON pe.passage_id = p.id
				   LEFT JOIN {$ts} s ON s.id = p.source_id
				   WHERE pe.entity_id = %d {$where_nb}
				   ORDER BY p.id DESC
				   LIMIT %d",
				$entity_id,
				$limit
			);
		} else {
			$sql = $wpdb->prepare(
				"SELECT DISTINCT p.id AS passage_id, p.id, p.content, p.metadata, p.source_id, p.notebook_id,
				        p.storage_ver, p.file_shard, p.file_offset, p.file_length
				   FROM {$tp} p
				   INNER JOIN {$tpe} pe ON pe.passage_id = p.id
				   WHERE pe.entity_id = %d {$where_nb}
				   ORDER BY p.id DESC
				   LIMIT %d",
				$entity_id,
				$limit
			);
		}

		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( ! is_array( $rows ) ) $rows = [];
		if ( $rows && class_exists( 'BizCity_KG_Content_Router' ) ) {
			BizCity_KG_Content_Router::instance()->hydrate_passages( $rows );
		}

		$out = array_map( static function ( $r ) {
			$content = (string) ( $r['content'] ?? '' );
			$snippet = mb_substr( $content, 0, 240 );
			if ( mb_strlen( $content ) > 240 ) $snippet .= '…';
			$meta = [];
			if ( ! empty( $r['metadata'] ) ) {
				$decoded = json_decode( (string) $r['metadata'], true );
				if ( is_array( $decoded ) ) {
					$meta = $decoded;
				}
			}
			return [
				'passage_id'   => (int) $r['passage_id'],
				'source_id'    => isset( $r['source_id'] ) ? (int) $r['source_id'] : null,
				'source_title' => (string) ( $r['source_title'] ?? '' ),
				'heading_path' => (string) ( $meta['heading_path'] ?? '' ),
				'page_no'      => isset( $meta['page_no'] ) ? (int) $meta['page_no'] : null,
				'snippet'      => $snippet,
			];
		}, $rows );

		return rest_ensure_response( [ 'ok' => true, 'data' => $out ] );
	}

	/**
	 * 4.10.3 — List distinct entities mentioned in passages belonging to the given source,
	 * ordered by mention frequency.
	 */
	public function get_source_entities( WP_REST_Request $req ) {
		global $wpdb;
		$source_id   = (int) $req['id'];
		$notebook_id = (int) $req->get_param( 'notebook_id' );
		$limit       = max( 1, min( 50, (int) ( $req->get_param( 'limit' ) ?: 15 ) ) );
		if ( $source_id <= 0 ) {
			return new WP_Error( 'bad_request', 'Invalid source id', [ 'status' => 400 ] );
		}

		$db = BizCity_KG_Database::instance();
		$tp  = $db->tbl_passages();
		$tpe = $db->tbl_passage_entities();
		$te  = $db->tbl_entities();
		$ts  = $db->tbl_sources();

		// 2026-05-05 — Synthetic source id (1B + passage_id) emitted by
		// BizCity_Twin_Context_Resolver for chat-memory passages whose underlying
		// `kg_passages.source_id` is NULL. Resolve directly to that single passage.
		// Falls back to triplet_queue (pending review) if passage_entities is empty
		// because chat-memory passages typically have triplets awaiting Approve All.
		if ( $source_id >= 1000000000 ) {
			$pid = $source_id - 1000000000;
			$sql = $wpdb->prepare(
				"SELECT e.id AS entity_id, e.name, e.type, COUNT(DISTINCT pe.passage_id) AS mention_count
				   FROM {$tpe} pe
				   INNER JOIN {$te} e ON e.id = pe.entity_id
				   WHERE pe.passage_id = %d AND e.deleted_at IS NULL
				   GROUP BY e.id, e.name, e.type
				   ORDER BY mention_count DESC, e.name ASC
				   LIMIT %d",
				$pid, $limit
			);
			$rows = $wpdb->get_results( $sql, ARRAY_A );
			if ( ! is_array( $rows ) ) $rows = [];

			$out = array_map( static function ( $r ) {
				return [
					'entity_id'     => (int) $r['entity_id'],
					'name'          => (string) $r['name'],
					'type'          => (string) ( $r['type'] ?? '' ),
					'mention_count' => (int) $r['mention_count'],
					'status'        => 'approved',
				];
			}, $rows );

			// Fallback: surface unapproved triplet mentions so the sidebar isn't blank.
			if ( empty( $out ) ) {
				$tq = $db->tbl_triplet_queue();
				$qrows = $wpdb->get_results( $wpdb->prepare(
					"SELECT subject AS name, subject_type AS type, COUNT(*) AS mention_count
					   FROM {$tq}
					   WHERE passage_id = %d AND status IN ('pending','approved')
					   GROUP BY subject, subject_type
					 UNION ALL
					 SELECT object AS name, object_type AS type, COUNT(*) AS mention_count
					   FROM {$tq}
					   WHERE passage_id = %d AND status IN ('pending','approved')
					   GROUP BY object, object_type",
					$pid, $pid
				), ARRAY_A );
				if ( ! is_array( $qrows ) ) $qrows = [];
				// Merge dup names client-side.
				$bag = [];
				foreach ( $qrows as $r ) {
					$key = mb_strtolower( trim( (string) $r['name'] ) );
					if ( $key === '' ) continue;
					if ( ! isset( $bag[ $key ] ) ) {
						$bag[ $key ] = [
							'entity_id'     => 0,
							'name'          => (string) $r['name'],
							'type'          => (string) ( $r['type'] ?? '' ),
							'mention_count' => 0,
							'status'        => 'pending',
						];
					}
					$bag[ $key ]['mention_count'] += (int) $r['mention_count'];
				}
				usort( $bag, static function ( $a, $b ) {
					return $b['mention_count'] <=> $a['mention_count'] ?: strcmp( $a['name'], $b['name'] );
				} );
				$out = array_slice( array_values( $bag ), 0, $limit );
			}

			return rest_ensure_response( [ 'ok' => true, 'data' => $out ] );
		}

		// Wave 0.6.C — resolve dual-id space. kg_passages.source_id may hold either
		// the kg_sources.id (new write path) or the legacy origin_id (webchat id).
		// Probe kg_sources for a mirror row so we can match on BOTH ids.
		$ids = [ $source_id ];
		if ( $notebook_id > 0 ) {
			$mirror_id = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$ts} WHERE origin_id = %d AND scope_type = %s AND scope_id = %s LIMIT 1",
				$source_id, 'notebook', (string) $notebook_id
			) );
			if ( $mirror_id > 0 ) $ids[] = $mirror_id;
			$origin_id = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT origin_id FROM {$ts} WHERE id = %d AND scope_type = %s AND scope_id = %s LIMIT 1",
				$source_id, 'notebook', (string) $notebook_id
			) );
			if ( $origin_id > 0 ) $ids[] = $origin_id;
		}
		$ids = array_values( array_unique( array_filter( $ids ) ) );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$where_nb = $notebook_id > 0 ? $wpdb->prepare( ' AND p.notebook_id = %d', $notebook_id ) : '';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare(
			"SELECT e.id AS entity_id, e.name, e.type, COUNT(DISTINCT p.id) AS mention_count
			   FROM {$tp} p
			   INNER JOIN {$tpe} pe ON pe.passage_id = p.id
			   INNER JOIN {$te} e  ON e.id = pe.entity_id
			   WHERE p.source_id IN ({$placeholders}) AND e.deleted_at IS NULL {$where_nb}
			   GROUP BY e.id, e.name, e.type
			   ORDER BY mention_count DESC, e.name ASC
			   LIMIT %d",
			array_merge( $ids, [ $limit ] )
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( ! is_array( $rows ) ) $rows = [];

		$out = array_map( static function ( $r ) {
			return [
				'entity_id'     => (int) $r['entity_id'],
				'name'          => (string) $r['name'],
				'type'          => (string) ( $r['type'] ?? '' ),
				'mention_count' => (int) $r['mention_count'],
			];
		}, $rows );

		return rest_ensure_response( [ 'ok' => true, 'data' => $out ] );
	}

	public function update_relation( WP_REST_Request $req ) {
		$data = $req->get_json_params() ?: $req->get_params();
		$res  = BizCity_KG_Graph_Service::instance()->update_relation( (int) $req['id'], (array) $data );
		return is_wp_error( $res ) ? $res : rest_ensure_response( $res );
	}

	public function delete_relation( WP_REST_Request $req ) {
		BizCity_KG_Graph_Service::instance()->soft_delete_relation( (int) $req['id'] );
		return rest_ensure_response( [ 'deleted' => true, 'soft' => true ] );
	}

	public function create_manual_relation( WP_REST_Request $req ) {
		$data = $req->get_json_params() ?: $req->get_params();
		$res  = BizCity_KG_Graph_Service::instance()->create_manual_relation( (array) $data );
		return is_wp_error( $res ) ? $res : rest_ensure_response( $res );
	}

	// ─── Phase 0.5 Sprint 2: studio backfill ───────────────────────────────

	public function studio_backfill( WP_REST_Request $req ) {
		$data  = $req->get_json_params() ?: $req->get_params();
		$limit = max( 1, min( 200, (int) ( $data['limit'] ?? 50 ) ) );
		return rest_ensure_response(
			BizCity_KG_Source_Adapter_Studio::instance()->run_backfill_batch( $limit )
		);
	}

	// ─── Phase 0.5 Sprint 1: cost telemetry ────────────────────────────────

	public function cost_today( WP_REST_Request $req ) {
		return rest_ensure_response( BizCity_KG_Cost_Guard::instance()->summary_today() );
	}

	// ─── PHASE-0.13 Wave 10c: per-source learning evidence trail ───────────

	public function source_progress_log( WP_REST_Request $req ) {
		if ( ! class_exists( 'BizCity_KG_Source_Progress_Log' ) ) {
			return new WP_Error( 'not_available', 'Progress log unavailable.', [ 'status' => 503 ] );
		}
		$source_id = (int) $req['id'];
		$limit     = (int) ( $req->get_param( 'limit' ) ?: 100 );
		return rest_ensure_response( [
			'ok'        => true,
			'source_id' => $source_id,
			'summary'   => BizCity_KG_Source_Progress_Log::summarise_for_source( $source_id ),
			'events'    => BizCity_KG_Source_Progress_Log::get_for_source( $source_id, $limit ),
		] );
	}

	public function notebook_progress_log( WP_REST_Request $req ) {
		if ( ! class_exists( 'BizCity_KG_Source_Progress_Log' ) ) {
			return new WP_Error( 'not_available', 'Progress log unavailable.', [ 'status' => 503 ] );
		}
		$nb    = (int) $req['id'];
		$limit = (int) ( $req->get_param( 'limit' ) ?: 200 );
		return rest_ensure_response( [
			'ok'          => true,
			'notebook_id' => $nb,
			'events'      => BizCity_KG_Source_Progress_Log::get_for_notebook( $nb, $limit ),
		] );
	}

	// ── Phase 0.21 Wave 3.0 — Guru Builder handlers ───────────────────────

	public function guru_promote_preview( WP_REST_Request $req ) {
		if ( ! class_exists( 'BizCity_KG_Guru_Builder' ) ) {
			return new WP_Error( 'not_available', 'Guru Builder unavailable.', [ 'status' => 503 ] );
		}
		$res = BizCity_KG_Guru_Builder::instance()->preview_notebook( (int) $req['id'] );
		if ( is_wp_error( $res ) ) {
			$res->add_data( [ 'status' => 400 ] );
			return $res;
		}
		return rest_ensure_response( [ 'ok' => true ] + $res );
	}

	public function guru_promote_notebook( WP_REST_Request $req ) {
		if ( ! class_exists( 'BizCity_KG_Guru_Builder' ) ) {
			return new WP_Error( 'not_available', 'Guru Builder unavailable.', [ 'status' => 503 ] );
		}
		$args = [
			'name'          => (string) $req->get_param( 'name' ),
			'slug'          => (string) ( $req->get_param( 'slug' ) ?: '' ),
			'description'   => (string) ( $req->get_param( 'description' ) ?: '' ),
			'system_prompt' => (string) ( $req->get_param( 'system_prompt' ) ?: '' ),
			'mode'          => (string) ( $req->get_param( 'mode' ) ?: 'clone' ),
			'user_id'       => (int) get_current_user_id(),
		];
		$res = BizCity_KG_Guru_Builder::instance()->promote_notebook( (int) $req['id'], $args );
		if ( is_wp_error( $res ) ) {
			$res->add_data( [ 'status' => 400 ] );
			return $res;
		}
		return rest_ensure_response( [ 'ok' => true ] + $res );
	}

	// ── Phase 0.21 Wave 3.1 — attach-guru handlers ────────────────────────

	public function list_attached_gurus( WP_REST_Request $req ) {
		$nb   = (int) $req['id'];
		$rows = BizCity_KG_Database::instance()->list_attached_gurus( $nb );
		return rest_ensure_response( [
			'ok'          => true,
			'notebook_id' => $nb,
			'count'       => count( $rows ),
			'attached'    => $rows,
		] );
	}

	public function attach_guru( WP_REST_Request $req ) {
		global $wpdb;
		$nb        = (int) $req['id'];
		$guru_uuid = strtolower( trim( (string) $req->get_param( 'guru_uuid' ) ) );
		$char_id   = (int) $req->get_param( 'character_id' );
		// Resolve guru_uuid from character_id if needed.
		if ( $guru_uuid === '' && $char_id > 0 ) {
			$char_tbl  = $wpdb->prefix . 'bizcity_characters';
			$guru_uuid = strtolower( (string) $wpdb->get_var( $wpdb->prepare(
				"SELECT guru_uuid FROM {$char_tbl} WHERE id = %d", $char_id
			) ) );
		}
		if ( $guru_uuid === '' ) {
			return new WP_Error( 'kg_attach_missing_uuid', 'Provide guru_uuid or character_id with a stamped guru_uuid.', [ 'status' => 400 ] );
		}
		$args = [
			'source'           => (string) ( $req->get_param( 'source' ) ?: 'self' ),
			'read_only'        => null !== $req->get_param( 'read_only' ) ? (bool) $req->get_param( 'read_only' ) : true,
			'attached_version' => (string) ( $req->get_param( 'attached_version' ) ?: '' ),
			'attached_by'      => (int) get_current_user_id(),
		];
		$res = BizCity_KG_Database::instance()->attach_guru( $nb, $guru_uuid, $args );
		if ( is_wp_error( $res ) ) {
			$res->add_data( [ 'status' => 400 ] );
			return $res;
		}
		return rest_ensure_response( [ 'ok' => true, 'attachment' => $res ] );
	}

	public function detach_guru( WP_REST_Request $req ) {
		$nb        = (int) $req['id'];
		$guru_uuid = strtolower( (string) $req['uuid'] );
		$res = BizCity_KG_Database::instance()->detach_guru( $nb, $guru_uuid );
		if ( is_wp_error( $res ) ) {
			$res->add_data( [ 'status' => 400 ] );
			return $res;
		}
		return rest_ensure_response( [ 'ok' => true ] + $res );
	}

	/**
	 * List candidate gurus (characters with guru_uuid stamped). Used by the
	 * attach-guru picker in the React UI.
	 *
	 * Query params:
	 *   search  string  Optional substring match on name/slug
	 *   limit   int     Max rows (default 100, capped 200)
	 */
	public function list_gurus( WP_REST_Request $req ) {
		global $wpdb;
		$char_tbl = $wpdb->prefix . 'bizcity_characters';
		$search   = trim( (string) $req->get_param( 'search' ) );
		$limit    = (int) ( $req->get_param( 'limit' ) ?: 100 );
		if ( $limit < 1 )   $limit = 100;
		if ( $limit > 200 ) $limit = 200;

		if ( $search !== '' ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			$sql  = $wpdb->prepare(
				"SELECT id, name, slug, guru_uuid, version, visibility,
				        bin_path, bin_dim, bin_count, embed_model, updated_at
				   FROM {$char_tbl}
				  WHERE guru_uuid IS NOT NULL AND guru_uuid <> ''
				    AND ( name LIKE %s OR slug LIKE %s )
				  ORDER BY id DESC
				  LIMIT %d",
				$like, $like, $limit
			);
		} else {
			$sql = $wpdb->prepare(
				"SELECT id, name, slug, guru_uuid, version, visibility,
				        bin_path, bin_dim, bin_count, embed_model, updated_at
				   FROM {$char_tbl}
				  WHERE guru_uuid IS NOT NULL AND guru_uuid <> ''
				  ORDER BY id DESC
				  LIMIT %d",
				$limit
			);
		}
		$rows = $wpdb->get_results( $sql, ARRAY_A ) ?: [];
		return rest_ensure_response( [ 'ok' => true, 'count' => count( $rows ), 'gurus' => $rows ] );
	}
}
