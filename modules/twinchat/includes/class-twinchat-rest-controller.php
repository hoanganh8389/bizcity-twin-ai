<?php
/**
 * Bizcity Twin AI — TwinChat REST Controller
 *
 * Routes registered on namespace `bizcity-twinchat/v1`:
 *   POST /chat/(?P<notebook_id>\d+)/stream  → SSE pipeline
 *   GET  /sessions/(?P<notebook_id>\d+)     → list sessions
 *   GET  /messages/(?P<session_id>[A-Za-z0-9\-]+) → session history
 *   GET  /stats/(?P<notebook_id>\d+)        → KG-Hub stats summary
 *
 * PHP 7.4 compatible.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Modules\TwinChat
 * @since 2026-05-01
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_TwinChat_REST_Controller {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register_routes() {
		$ns = BIZCITY_TWINCHAT_REST_NS;

		register_rest_route( $ns, '/chat/(?P<notebook_id>\d+)/stream', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handle_stream' ],
			'permission_callback' => [ $this, 'check_logged_in' ],
			'args'                => [
				'notebook_id' => [ 'type' => 'integer', 'required' => true ],
			],
		] );

		register_rest_route( $ns, '/sessions/(?P<notebook_id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'list_sessions' ],
			'permission_callback' => [ $this, 'check_logged_in' ],
		] );

		register_rest_route( $ns, '/messages/(?P<session_id>[A-Za-z0-9\-_]+)', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_messages' ],
			'permission_callback' => [ $this, 'check_logged_in' ],
		] );

		register_rest_route( $ns, '/stats/(?P<notebook_id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_stats' ],
			'permission_callback' => [ $this, 'check_logged_in' ],
		] );

		// Sprint 0.6.9 — standalone passage search (no agent loop, no answer LLM).
		register_rest_route( $ns, '/search/(?P<notebook_id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'handle_search' ],
			'permission_callback' => [ $this, 'check_logged_in' ],
			'args'                => [
				'notebook_id' => [ 'type' => 'integer', 'required' => true ],
				'q'           => [ 'type' => 'string',  'required' => true ],
				'top_k'       => [ 'type' => 'integer', 'default' => 20 ],
			],
		] );

		// 2026-05-21 — API key health probe used by the React SetupApiKeyDialog
		// (R-LEARN §6 E10 — "api key missing/invalid" surface).
		//   GET  /api-key/status    → cached snapshot from get_api_key_status()
		//   POST /api-key/test      → live ping to bizcity.vn /account/info
		register_rest_route( $ns, '/api-key/status', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'handle_api_key_status' ],
			'permission_callback' => [ $this, 'check_logged_in' ],
		] );
		register_rest_route( $ns, '/api-key/test', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handle_api_key_test' ],
			'permission_callback' => [ $this, 'check_logged_in' ],
		] );

		// ── Direct Sources routes (no KG-Hub dependency) ──────────────────────
		register_rest_route( $ns, '/sources/(?P<notebook_id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'list_sources' ],
			'permission_callback' => [ $this, 'check_logged_in' ],
			'args'                => [
				'notebook_id' => [ 'type' => 'integer', 'required' => true ],
				'limit'       => [ 'type' => 'integer', 'default' => 200 ],
				'search'      => [ 'type' => 'string',  'default' => '' ],
			],
		] );

		register_rest_route( $ns, '/sources/(?P<notebook_id>\d+)/(?P<source_id>\d+)', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_source' ],
				'permission_callback' => [ $this, 'check_logged_in' ],
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'delete_source' ],
				'permission_callback' => [ $this, 'check_logged_in' ],
			],
		] );

		// Delete legacy passages by origin string (for notebooks predating bizcity_twinchat_sources)
		// Sprint 5.0d — FE→BE event dispatch (whitelisted user-action types only).
		// All other event types must be emitted server-side via Event_Bus::dispatch_v2().
		register_rest_route( $ns, '/events/dispatch', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handle_dispatch_event' ],
			'permission_callback' => [ $this, 'check_logged_in' ],
		] );

		// Wave 0.18.3 — Notebook persona context (character + provider chips).
		register_rest_route( $ns, '/notebooks/(?P<notebook_id>\d+)/persona-context', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_persona_context' ],
			'permission_callback' => [ $this, 'check_logged_in' ],
			'args'                => [
				'notebook_id' => [ 'type' => 'integer', 'required' => true ],
			],
		] );

		// Wave 0.18.5c — Twin Guru picker (composer @-mention) + sticky persistence.
		// (1) Catalog of available Gurus for the current user.
		register_rest_route( $ns, '/gurus/list', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'list_gurus' ],
			'permission_callback' => [ $this, 'check_logged_in' ],
		] );
		// PHASE 0.31 T-S3.2 — Per-passage actions (Tag note + Trigger workflow).
		// Backed by `BizCity_KG_Source_Service::tag_passage()` which fires
		// `bizcity_twin_notebook_event('note_tagged', ...)` so workflow trigger
		// `nb_note_tagged` reacts. "Trigger workflow" is implemented as a
		// dedicated reserved tag (default `#trigger`) to reuse the same pipeline.
		register_rest_route( $ns, '/passages/(?P<passage_id>\d+)/tag', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'tag_passage' ],
			'permission_callback' => [ $this, 'check_logged_in' ],
			'args'                => [
				'passage_id' => [ 'type' => 'integer', 'required' => true ],
			],
		] );
		register_rest_route( $ns, '/passages/(?P<passage_id>\d+)/trigger-workflow', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'trigger_workflow_for_passage' ],
			'permission_callback' => [ $this, 'check_logged_in' ],
			'args'                => [
				'passage_id' => [ 'type' => 'integer', 'required' => true ],
			],
		] );

		// (2)(3)(4) Per-(user, notebook) sticky Guru — saved in user_meta.
		register_rest_route( $ns, '/notebooks/(?P<notebook_id>\d+)/sticky-guru', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_sticky_guru' ],
				'permission_callback' => [ $this, 'check_logged_in' ],
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'set_sticky_guru' ],
				'permission_callback' => [ $this, 'check_logged_in' ],
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'clear_sticky_guru' ],
				'permission_callback' => [ $this, 'check_logged_in' ],
			],
		] );
	}

	/* ── Permission ────────────────────────────────────────────────────── */

	public function check_logged_in() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', 'Login required.', [ 'status' => 401 ] );
		}
		return true;
	}

	/* ── API key health (R-LEARN §6 E10) ───────────────────────────────── */

	/**
	 * GET /bizcity-twinchat/v1/api-key/status
	 * Returns the cached snapshot from the public-page helper.
	 */
	public function handle_api_key_status() {
		if ( ! class_exists( 'BizCity_TwinChat_Public_Page' ) ) {
			return new WP_Error( 'public_page_missing', 'Public page class not loaded.', [ 'status' => 500 ] );
		}
		return rest_ensure_response( BizCity_TwinChat_Public_Page::get_api_key_status() );
	}

	/**
	 * POST /bizcity-twinchat/v1/api-key/test
	 * Live-pings the gateway `/bizcity/v1/account/info` endpoint with the
	 * currently configured key. Persists the result in `bizcity_llm_last_test`
	 * so `handle_api_key_status` reflects it next call.
	 */
	public function handle_api_key_test() {
		$key = trim( (string) get_site_option( 'bizcity_llm_api_key', '' ) );
		if ( $key === '' ) {
			return new WP_Error(
				'bizcity_api_key_missing',
				'Chưa có API key trong cấu hình site này.',
				[
					'status'       => 412,
					'settings_url' => admin_url( 'admin.php?page=bizcity-twinchat-settings' ),
				]
			);
		}

		$gateway = (string) get_site_option( 'bizcity_llm_gateway_url', '' );
		if ( $gateway === '' ) {
			$gateway = 'https://bizcity.vn';
		}
		$url = trailingslashit( $gateway ) . 'wp-json/bizcity/v1/account/info';

		$started_at = microtime( true );
		$resp       = wp_remote_get( $url, [
			'timeout'     => 8,
			'redirection' => 3,
			'sslverify'   => true,
			'headers'     => [
				'Authorization' => 'Bearer ' . $key,
				'Accept'        => 'application/json',
			],
		] );
		$elapsed_ms = (int) round( ( microtime( true ) - $started_at ) * 1000 );

		if ( is_wp_error( $resp ) ) {
			update_site_option( 'bizcity_llm_last_test', [
				'ok'      => false,
				'ts'      => time(),
				'ms'      => $elapsed_ms,
				'code'    => 'network_error',
				'message' => $resp->get_error_message(),
			] );
			return new WP_Error(
				'bizcity_api_key_test_failed',
				sprintf( 'Không gọi được gateway %s: %s', $gateway, $resp->get_error_message() ),
				[
					'status'       => 502,
					'settings_url' => admin_url( 'admin.php?page=bizcity-twinchat-settings' ),
				]
			);
		}

		$code  = (int) wp_remote_retrieve_response_code( $resp );
		$body  = (string) wp_remote_retrieve_body( $resp );
		$json  = json_decode( $body, true );
		$ok    = ( $code === 200 );

		update_site_option( 'bizcity_llm_last_test', [
			'ok'      => $ok,
			'ts'      => time(),
			'ms'      => $elapsed_ms,
			'code'    => 'http_' . $code,
			'message' => is_array( $json ) ? '' : substr( $body, 0, 200 ),
		] );

		if ( ! $ok ) {
			return new WP_Error(
				'bizcity_api_key_invalid',
				sprintf( 'Gateway từ chối key (HTTP %d). Có thể key sai/đã thu hồi, hoặc gateway URL không đúng.', $code ),
				[
					'status'       => $code === 401 || $code === 403 ? 401 : 502,
					'gateway_url'  => $gateway,
					'http_status'  => $code,
					'settings_url' => admin_url( 'admin.php?page=bizcity-twinchat-settings' ),
				]
			);
		}

		return rest_ensure_response( [
			'ok'           => true,
			'http_status'  => $code,
			'elapsed_ms'   => $elapsed_ms,
			'gateway_url'  => $gateway,
			'account'      => is_array( $json ) ? $json : null,
			'status'       => BizCity_TwinChat_Public_Page::get_api_key_status(),
		] );
	}

	/**
	 * Verify current user owns (or can access) the given notebook.
	 * Returns true on success, WP_Error(403/404) on failure.
	 */
	private function check_notebook_access( int $notebook_id ) {
		if ( $notebook_id <= 0 ) {
			return new WP_Error( 'invalid_notebook', 'Invalid notebook_id.', [ 'status' => 400 ] );
		}
		if ( ! class_exists( 'BizCity_KG_Notebook_Service' ) ) return true; // graceful degrade
		$nb = BizCity_KG_Notebook_Service::instance()->get( $notebook_id );
		if ( ! $nb ) {
			return new WP_Error( 'not_found', 'Notebook not found.', [ 'status' => 404 ] );
		}
		$owner = (int) ( $nb['owner_id'] ?? $nb['user_id'] ?? 0 );
		if ( $owner !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'forbidden', 'Notebook not accessible.', [ 'status' => 403 ] );
		}
		return true;
	}

	/* ── Handlers ──────────────────────────────────────────────────────── */

	public function handle_stream( WP_REST_Request $request ) {
		$notebook_id = (int) $request->get_param( 'notebook_id' );
		$auth = $this->check_notebook_access( $notebook_id );
		if ( is_wp_error( $auth ) ) return $auth;

		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = [];
		}
		$args = [
			'notebook_id'     => $notebook_id,
			'session_id'      => isset( $body['session_id'] ) ? (string) $body['session_id'] : '',
			'user_message'    => isset( $body['message'] ) ? (string) $body['message'] : '',
			'history'         => isset( $body['history'] ) && is_array( $body['history'] ) ? $body['history'] : [],
			'source_ids'      => isset( $body['source_ids'] ) && is_array( $body['source_ids'] ) ? $body['source_ids'] : [],
			'use_kg'          => isset( $body['use_kg'] ) ? (bool) $body['use_kg'] : true,
			'enable_thinking' => isset( $body['enable_thinking'] ) ? (bool) $body['enable_thinking'] : false,
		];

		// Wave 0.18.5c — Twin Guru @-mention from composer.
		// Shape: { character_id, character_slug, character_name, avatar_url? }
		// Source: 'mention' = user picked via @, 'pinned' = sticky restored on mount.
		if ( isset( $body['target_guru'] ) && is_array( $body['target_guru'] ) ) {
			$tg_cid = (int) ( $body['target_guru']['character_id'] ?? 0 );
			if ( $tg_cid > 0 ) {
				$args['target_guru'] = [
					'character_id'   => $tg_cid,
					'character_slug' => isset( $body['target_guru']['character_slug'] ) ? (string) $body['target_guru']['character_slug'] : '',
					'character_name' => isset( $body['target_guru']['character_name'] ) ? (string) $body['target_guru']['character_name'] : '',
					'avatar_url'     => isset( $body['target_guru']['avatar_url'] ) ? esc_url_raw( (string) $body['target_guru']['avatar_url'] ) : '',
					'sticky_source'  => isset( $body['target_guru']['sticky_source'] ) && in_array( $body['target_guru']['sticky_source'], [ 'mention', 'pinned' ], true )
						? (string) $body['target_guru']['sticky_source']
						: 'mention',
				];
			}
		}

		// Trim user message guard.
		$args['user_message'] = trim( $args['user_message'] );
		if ( $args['user_message'] === '' ) {
			return new WP_Error( 'empty_message', 'message is required', [ 'status' => 400 ] );
		}

		// Hand off to the SSE handler — it will write directly + exit.
		BizCity_TwinChat_Stream_Handler::instance()->handle( $args );
		// Stop WP from appending JSON envelope.
		exit;
	}

	public function list_sessions( WP_REST_Request $request ) {
		$notebook_id = (int) $request->get_param( 'notebook_id' );
		$auth = $this->check_notebook_access( $notebook_id );
		if ( is_wp_error( $auth ) ) return $auth;
		$rows = BizCity_TwinChat_Database::instance()->list_sessions( $notebook_id, 50 );
		return rest_ensure_response( [
			'ok'   => true,
			'data' => $rows,
		] );
	}

	public function get_messages( WP_REST_Request $request ) {
		$session_id = (string) $request->get_param( 'session_id' );
		$rows = BizCity_TwinChat_Database::instance()->get_session_messages( $session_id, 200 );
		return rest_ensure_response( [
			'ok'   => true,
			'data' => $rows,
		] );
	}

	public function get_stats( WP_REST_Request $request ) {
		global $wpdb;
		$notebook_id = (int) $request->get_param( 'notebook_id' );
		$auth = $this->check_notebook_access( $notebook_id );
		if ( is_wp_error( $auth ) ) return $auth;
		$entities = 0;
		$relations = 0;
		if ( class_exists( 'BizCity_KG_Graph_Service' ) ) {
			$svc = BizCity_KG_Graph_Service::instance();
			if ( method_exists( $svc, 'get_full_graph' ) ) {
				$g = $svc->get_full_graph( $notebook_id, 1000 );
				if ( is_array( $g ) ) {
					$entities  = isset( $g['nodes'] ) ? count( $g['nodes'] ) : 0;
					$relations = isset( $g['links'] ) ? count( $g['links'] ) : 0;
				}
			}
		}

		// 4.10.4 — analytics breakdown (best-effort; tolerate missing tables).
		$type_distribution = [];
		$top_entities      = [];
		$passages_total    = 0;
		$embedding_coverage = 0.0;
		if ( class_exists( 'BizCity_KG_Database' ) ) {
			$db  = BizCity_KG_Database::instance();
			$te  = $db->tbl_entities();
			$tr  = method_exists( $db, 'tbl_relations' )       ? $db->tbl_relations()       : '';
			$tp  = method_exists( $db, 'tbl_passages' )        ? $db->tbl_passages()        : '';
			$tpe = method_exists( $db, 'tbl_passage_entities' )? $db->tbl_passage_entities(): '';

			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT type, COUNT(*) AS cnt FROM {$te} WHERE notebook_id = %d GROUP BY type ORDER BY cnt DESC",
				$notebook_id
			), ARRAY_A );
			foreach ( (array) $rows as $r ) {
				$type_distribution[] = [
					'type'  => (string) ( $r['type'] ?? 'Other' ),
					'count' => (int) $r['cnt'],
				];
			}

			if ( $tr ) {
				// 4.10.4 hotfix — single-pass aggregate (O(n) over relations) instead of
				// per-entity correlated subquery. Counts each relation twice (head + tail)
				// via UNION ALL, groups, joins to entity name/type, returns top 10.
				$top = $wpdb->get_results( $wpdb->prepare(
					"SELECT e.id AS entity_id, e.name, e.type, agg.rel_count
					   FROM (
					     SELECT entity_id, COUNT(*) AS rel_count FROM (
					       SELECT head_entity_id AS entity_id FROM {$tr} WHERE notebook_id = %d
					       UNION ALL
					       SELECT tail_entity_id AS entity_id FROM {$tr} WHERE notebook_id = %d
					     ) ee
					     GROUP BY entity_id
					   ) agg
					   INNER JOIN {$te} e ON e.id = agg.entity_id AND e.notebook_id = %d
					   ORDER BY agg.rel_count DESC, e.name ASC
					   LIMIT 10",
					$notebook_id, $notebook_id, $notebook_id
				), ARRAY_A );
				foreach ( (array) $top as $r ) {
					$top_entities[] = [
						'entity_id' => (int) $r['entity_id'],
						'name'      => (string) $r['name'],
						'type'      => (string) ( $r['type'] ?? '' ),
						'count'     => (int) $r['rel_count'],
					];
				}
			}

			if ( $tp ) {
				$passages_total = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$tp} WHERE notebook_id = %d", $notebook_id
				) );
				$with_embedding = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$tp} WHERE notebook_id = %d AND embedding IS NOT NULL AND CHAR_LENGTH(embedding) > 0", $notebook_id
				) );
				if ( $passages_total > 0 ) {
					$embedding_coverage = round( ( $with_embedding / $passages_total ) * 100, 1 );
				}
			}
		}

		return rest_ensure_response( [
			'ok'   => true,
			'data' => [
				'notebook_id'        => $notebook_id,
				'entities'           => $entities,
				'relations'          => $relations,
				'passages'           => $passages_total,
				'embedding_coverage' => $embedding_coverage,
				'type_distribution'  => $type_distribution,
				'top_entities'       => $top_entities,
			],
		] );
	}

	/* ── Direct Sources handlers ────────────────────────────────────────── */

	/**
	 * Sprint 0.6.9 — Standalone Search.
	 * Thin wrapper around BizCity_KG_Retriever::search() — vector search over
	 * passages of one notebook, no agent loop, no answer generation.
	 */
	public function handle_search( WP_REST_Request $request ) {
		$notebook_id = (int) $request->get_param( 'notebook_id' );
		$auth = $this->check_notebook_access( $notebook_id );
		if ( is_wp_error( $auth ) ) return $auth;

		$q     = trim( (string) $request->get_param( 'q' ) );
		$top_k = (int) $request->get_param( 'top_k' );
		if ( $q === '' ) {
			return new WP_Error( 'empty_query', 'q is required.', [ 'status' => 400 ] );
		}
		if ( ! class_exists( 'BizCity_KG_Retriever' ) ) {
			return new WP_Error( 'unavailable', 'Retriever not loaded.', [ 'status' => 503 ] );
		}
		$out = BizCity_KG_Retriever::instance()->search( $notebook_id, $q, $top_k );
		return rest_ensure_response( [
			'ok'    => true,
			'query' => $q,
			'data'  => $out,
		] );
	}


	public function list_sources( WP_REST_Request $request ) {
		global $wpdb;
		$notebook_id = (int) $request->get_param( 'notebook_id' );
		$auth = $this->check_notebook_access( $notebook_id );
		if ( is_wp_error( $auth ) ) return $auth;
		$args        = [
			'limit'  => (int) $request->get_param( 'limit' ),
			'search' => (string) $request->get_param( 'search' ),
		];
		try {
			// Phase 0.6 — Read switch: query bizcity_kg_sources when flag is on.
			// Default now honors the central `bizcity_kg_unified_read_enabled` option so a
			// single toggle flips reads for both the facade-level and TwinChat-direct paths.
			$read_switch_default = (bool) get_option( 'bizcity_kg_unified_read_enabled', true );
			if ( apply_filters( 'bizcity_kg_v06_read_switch', $read_switch_default ) && class_exists( 'BizCity_KG_Database' ) ) {
				$rows = self::_list_kg_sources( $notebook_id, $args );
				return rest_ensure_response( [ 'ok' => true, 'data' => $rows ] );
			}
			// Ensure the sources table exists before querying (may not be installed yet on server).
			// Cached via option `bizcity_known_tables` — chỉ hit DB 1 lần / blog.
			$db  = BizCity_TwinChat_Sources_Database::instance();
			$tbl = $db->table_sources();
			$exists = function_exists( 'bizcity_table_exists' ) ? bizcity_table_exists( $tbl ) : ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) ) === $tbl );
			if ( ! $exists ) {
				// Table not yet installed — trigger install and return empty for now.
				$db->maybe_install();
				return rest_ensure_response( [ 'ok' => true, 'data' => [] ] );
			}
			$rows = BizCity_TwinChat_Sources_Service::instance()->list_sources( $notebook_id, $args );
			$rows = is_array( $rows ) ? $rows : [];

			// Phase 0.13 — enrich each row with KG triplet-extraction stats so the
			// FE can light up the 🧠 "learned" brain badge per source.
			//
			// Wave 10d.5c BUGFIX (2026-05-02) — dual-id lookup, mirrors the fix
			// already in `_list_kg_sources()` for the read-switch path.
			// Symptom: every source shows 0% even though Twin_Context_Resolver
			// returns passages + citations for the same notebook (PHASE-0.13
			// "Per-Source Learning Progress" doc, root cause #1). Reason:
			// `kg_passages.source_id` for these notebooks holds the CANONICAL
			// `kg_sources.id` (e.g. 200) while this legacy path returns rows with
			// `bizcity_webchat_sources.id` (1..N local ids). Aggregating only on
			// the local id returned zero, so the brain badge never lit up and
			// the sweep cron kept re-enqueuing "learning" jobs (root cause #2,
			// loop). Fix: also probe by `origin_id` from the mirrored kg_sources
			// row (one extra small SELECT keyed by origin_table) and bucket the
			// totals back to the local row id.
			if ( ! empty( $rows ) && class_exists( 'BizCity_KG_Database' ) ) {
				$ids = array_values( array_unique( array_filter( array_map(
					static function ( $r ) { return (int) ( $r['id'] ?? 0 ); },
					$rows
				) ) ) );
				if ( ! empty( $ids ) ) {
					$db_kg        = BizCity_KG_Database::instance();
					$tbl_passages = $db_kg->tbl_passages();
					$tbl_sources  = $db_kg->tbl_sources();

					// Build lookup: passage_source_id (any of legacy id OR kg_sources.id)
					//               → canonical local row id (what FE sees).
					$lookup    = [];
					foreach ( $ids as $local_id ) { $lookup[ $local_id ] = $local_id; }

					// Probe kg_sources for mirror rows whose origin_id == local id.
					// Only run if the kg_sources table actually exists.
					$placeholders_local = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$mirror_rows = $wpdb->get_results( $wpdb->prepare(
						"SELECT id AS kg_id, origin_id FROM {$tbl_sources}
						  WHERE origin_id IN ({$placeholders_local})
						    AND scope_type = %s AND scope_id = %s",
						array_merge( $ids, [ 'notebook', (string) $notebook_id ] )
					), ARRAY_A );
					if ( is_array( $mirror_rows ) ) {
						foreach ( $mirror_rows as $mr ) {
							$kg_id = (int) $mr['kg_id'];
							$oid   = (int) $mr['origin_id'];
							if ( $kg_id > 0 && $oid > 0 && isset( $lookup[ $oid ] ) ) {
								$lookup[ $kg_id ] = $oid; // canonical bucket = local row id
							}
						}
					}

					$query_ids    = array_values( array_unique( array_keys( $lookup ) ) );
					$placeholders = implode( ',', array_fill( 0, count( $query_ids ), '%d' ) );
					$agg_sql      = "SELECT source_id,
						COUNT(*) AS total_chunks,
						SUM(CASE WHEN extraction_status = 'done'  THEN 1 ELSE 0 END) AS done_chunks,
						SUM(CASE WHEN extraction_status = 'error' THEN 1 ELSE 0 END) AS error_chunks
						FROM {$tbl_passages}
						WHERE source_id IN ({$placeholders})
						GROUP BY source_id";
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					$agg_rows = $wpdb->get_results( $wpdb->prepare( $agg_sql, $query_ids ), ARRAY_A );
					$agg_map  = [];
					if ( is_array( $agg_rows ) ) {
						foreach ( $agg_rows as $a ) {
							$psid = (int) $a['source_id'];
							$lid  = $lookup[ $psid ] ?? 0;
							if ( $lid <= 0 ) continue;
							if ( ! isset( $agg_map[ $lid ] ) ) {
								$agg_map[ $lid ] = [ 'total' => 0, 'done' => 0, 'error' => 0 ];
							}
							$agg_map[ $lid ]['total'] += (int) $a['total_chunks'];
							$agg_map[ $lid ]['done']  += (int) $a['done_chunks'];
							$agg_map[ $lid ]['error'] += (int) $a['error_chunks'];
						}
					}
					foreach ( $rows as &$r ) {
						$rid   = (int) ( $r['id'] ?? 0 );
						$stat  = $agg_map[ $rid ] ?? [ 'total' => 0, 'done' => 0, 'error' => 0 ];
						$total = $stat['total'];
						$done  = $stat['done'];
						$r['extraction_total']    = $total;
						$r['extraction_done']     = $done;
						$r['extraction_error']    = $stat['error'];
						$r['extraction_complete'] = ( $total > 0 && $done >= $total );
						$r['extraction_progress'] = $total > 0 ? round( $done / $total, 4 ) : 0.0;
					}
					unset( $r );
				}
			}

			return rest_ensure_response( [ 'ok' => true, 'data' => $rows ] );
		} catch ( \Throwable $e ) {
			error_log( '[TwinChat] list_sources error: ' . get_class( $e ) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );
			return new WP_Error( 'list_sources_error', $e->getMessage(), [ 'status' => 500 ] );
		}
	}

	public function get_source( WP_REST_Request $request ) {
		try {
			if ( ! class_exists( 'BizCity_TwinChat_Sources_Service' ) ) {
				error_log( '[TwinChat] get_source: BizCity_TwinChat_Sources_Service not loaded' );
				return new WP_Error( 'service_unavailable', 'Sources service not available', [ 'status' => 503 ] );
			}
			$source_id   = (int) $request->get_param( 'source_id' );
			$notebook_id = (int) $request->get_param( 'notebook_id' );

			// 2026-05-05 — Synthetic source IDs. The Twin context resolver
			// emits a synthetic id (1_000_000_000 + passage_id) for chat-promoted
			// passages whose underlying `source_id` is NULL (auto-promoter rows).
			// See BizCity_Twin_Context_Resolver::_resolve_citable_source_id().
			// Resolve those directly from kg_passages and return a virtual source row
			// so the FE source-detail panel can render the chip content instead of 404.
			if ( $source_id >= 1000000000 && class_exists( 'BizCity_KG_Database' ) ) {
				global $wpdb;
				$kg  = BizCity_KG_Database::instance();
				$pid = $source_id - 1000000000;
				// kg_passages: only `notebook_id`, `content`, `origin`, `metadata`, `created_at` exist
				// (no `heading_path`/`scope_id` columns). Older deployments may also have a `scope_id`
				// column added by the Phase-0.6 migration — try that defensively.
				$pas = $wpdb->get_row( $wpdb->prepare(
					"SELECT * FROM {$kg->tbl_passages()} WHERE id = %d LIMIT 1",
					$pid
				), ARRAY_A );
				if ( ! $pas ) {
					return new WP_Error( 'not_found', 'Source not found', [ 'status' => 404 ] );
				}
				$pas_nb = (int) ( $pas['notebook_id'] ?? $pas['scope_id'] ?? 0 );
				if ( $notebook_id > 0 && $pas_nb !== $notebook_id ) {
					return new WP_Error( 'forbidden', 'Access denied', [ 'status' => 403 ] );
				}
				// Try to extract a useful title from `metadata` JSON if present.
				$title = 'Chat memory #' . $pid;
				if ( ! empty( $pas['metadata'] ) ) {
					$meta = json_decode( (string) $pas['metadata'], true );
					if ( is_array( $meta ) ) {
						if ( ! empty( $meta['heading_path'] ) ) {
							$title = is_array( $meta['heading_path'] ) ? implode( ' › ', $meta['heading_path'] ) : (string) $meta['heading_path'];
						} elseif ( ! empty( $meta['title'] ) ) {
							$title = (string) $meta['title'];
						}
					}
				}
				$row = [
					'id'               => (int) $source_id,
					'notebook_id'      => $pas_nb,
					'user_id'          => 0,
					'title'            => $title,
					'source_type'      => (string) ( $pas['origin'] ?? 'chat' ),
					'source_url'       => '',
					'content_text'     => (string) ( $pas['content'] ?? '' ),
					'embedding_status' => 'ready',
					'status'           => 'active',
					'created_at'       => (string) ( $pas['created_at'] ?? '' ),
					'updated_at'       => (string) ( $pas['updated_at'] ?? $pas['created_at'] ?? '' ),
					'is_synthetic'     => true,
				];
				return rest_ensure_response( [ 'ok' => true, 'data' => $row ] );
			}

			// Wave 0.6.C — source_id may be either a kg_sources.id (new write path)
			// OR a legacy webchat_sources.id (old citations / older messages). We must
			// resolve both, scoped by notebook_id to avoid cross-notebook id collisions.
			$row        = null;
			$rs_default = (bool) get_option( 'bizcity_kg_unified_read_enabled', true );
			if ( apply_filters( 'bizcity_kg_v06_read_switch', $rs_default ) && class_exists( 'BizCity_KG_Database' ) ) {
				global $wpdb;
				$kg     = BizCity_KG_Database::instance();
				// 1) Try as kg_sources.id (scoped).
				$kg_row = $wpdb->get_row( $wpdb->prepare(
					"SELECT id, origin_id, origin_kind, title, origin_url, status, scope_id, user_id, created_at
					   FROM {$kg->tbl_sources()}
					  WHERE id = %d AND scope_type = %s AND scope_id = %s LIMIT 1",
					$source_id, 'notebook', (string) $notebook_id
				), ARRAY_A );
				// 2) Fallback: maybe source_id is a legacy webchat_sources.id → look up
				//    the mirror row via origin_id (also scoped).
				if ( ! $kg_row ) {
					$kg_row = $wpdb->get_row( $wpdb->prepare(
						"SELECT id, origin_id, origin_kind, title, origin_url, status, scope_id, user_id, created_at
						   FROM {$kg->tbl_sources()}
						  WHERE origin_id = %d AND scope_type = %s AND scope_id = %s LIMIT 1",
						$source_id, 'notebook', (string) $notebook_id
					), ARRAY_A );
				}
				if ( $kg_row ) {
					$legacy_id    = (int) $kg_row['origin_id'];
					$content_text = '';
					if ( $legacy_id > 0 && class_exists( 'BizCity_TwinChat_Sources_Database' ) ) {
						// Read full text directly from webchat_sources.content_text
						// (insert_source() stores the materialized content there).
						$db_tc        = BizCity_TwinChat_Sources_Database::instance();
						$tbl_src      = $db_tc->table_sources();
						$content_text = (string) $wpdb->get_var( $wpdb->prepare(
							"SELECT content_text FROM {$tbl_src} WHERE id = %d LIMIT 1",
							$legacy_id
						) );
						// Fallback: stitch from chunk rows if content_text was never set.
						if ( $content_text === '' ) {
							$chunks_table = $db_tc->table_source_chunks();
							$texts        = $wpdb->get_col( $wpdb->prepare(
								"SELECT content FROM {$chunks_table} WHERE source_id = %d ORDER BY chunk_index ASC",
								$legacy_id
							) );
							if ( $texts ) $content_text = implode( "\n\n", $texts );
						}
					}
					$row = [
						'id'               => (int) $kg_row['id'],
						'notebook_id'      => (int) $kg_row['scope_id'],
						'user_id'          => (int) $kg_row['user_id'],
						'title'            => (string) ( $kg_row['title'] ?? '' ),
						'source_type'      => (string) ( $kg_row['origin_kind'] ?? 'file' ),
						'source_url'       => (string) ( $kg_row['origin_url'] ?? '' ),
						'content_text'     => $content_text,
						'embedding_status' => 'ready',
						'status'           => (string) ( $kg_row['status'] ?? 'active' ),
						'created_at'       => (string) ( $kg_row['created_at'] ?? '' ),
						'updated_at'       => (string) ( $kg_row['created_at'] ?? '' ),
					];
				}
			}

			if ( ! $row ) {
				$row = BizCity_TwinChat_Sources_Service::instance()->get_source( $source_id );
			}
			if ( ! $row ) {
				return new WP_Error( 'not_found', 'Source not found', [ 'status' => 404 ] );
			}
			// Ownership check — only enforce when notebook_id is present in the row.
			// Fallback KG-Hub sources don't have notebook_id in their shape → skip.
			if ( isset( $row['notebook_id'] ) && (int) $row['notebook_id'] > 0 && (int) $row['notebook_id'] !== $notebook_id ) {
				return new WP_Error( 'forbidden', 'Access denied', [ 'status' => 403 ] );
			}
			// Append full content_text if stored in chunks (for detail view)
			if ( empty( $row['content_text'] ) ) {
				global $wpdb;
				$db           = BizCity_TwinChat_Sources_Database::instance();
				$chunks_table = $db->table_source_chunks();
				$texts        = $wpdb->get_col( $wpdb->prepare(
					"SELECT content FROM {$chunks_table} WHERE source_id = %d ORDER BY chunk_index ASC",
					$source_id
				) );
				if ( $texts ) {
					$row['content_text'] = implode( "\n\n", $texts );
				}
			}
			return rest_ensure_response( [ 'ok' => true, 'data' => $row ] );
		} catch ( \Throwable $e ) {
			error_log( '[TwinChat] get_source error: ' . $e->getMessage() );
			return new WP_Error( 'get_source_error', $e->getMessage(), [ 'status' => 500 ] );
		}
	}

	public function delete_source( WP_REST_Request $request ) {
		try {
			if ( ! class_exists( 'BizCity_TwinChat_Sources_Service' ) ) {
				return new WP_Error( 'service_unavailable', 'Sources service not available', [ 'status' => 503 ] );
			}
			$notebook_id = (int) $request->get_param( 'notebook_id' );
			$auth = $this->check_notebook_access( $notebook_id );
			if ( is_wp_error( $auth ) ) return $auth;
			$source_id   = (int) $request->get_param( 'source_id' );

			// Wave 0.6.C — source_id may be kg_sources.id OR legacy webchat_sources.id.
			$rs_default = (bool) get_option( 'bizcity_kg_unified_read_enabled', true );
			if ( apply_filters( 'bizcity_kg_v06_read_switch', $rs_default ) && class_exists( 'BizCity_KG_Database' ) ) {
				global $wpdb;
				$kg     = BizCity_KG_Database::instance();
				// Try kg_sources.id (scoped), then origin_id (legacy), both scoped by notebook.
				$kg_row = $wpdb->get_row( $wpdb->prepare(
					"SELECT id, origin_id, scope_id FROM {$kg->tbl_sources()}
					  WHERE id = %d AND scope_type = %s AND scope_id = %s LIMIT 1",
					$source_id, 'notebook', (string) $notebook_id
				), ARRAY_A );
				if ( ! $kg_row ) {
					$kg_row = $wpdb->get_row( $wpdb->prepare(
						"SELECT id, origin_id, scope_id FROM {$kg->tbl_sources()}
						  WHERE origin_id = %d AND scope_type = %s AND scope_id = %s LIMIT 1",
						$source_id, 'notebook', (string) $notebook_id
					), ARRAY_A );
				}
				if ( $kg_row ) {
					if ( (int) $kg_row['scope_id'] !== $notebook_id ) {
						return new WP_Error( 'forbidden', 'Access denied', [ 'status' => 403 ] );
					}
					$kg_source_id = (int) $kg_row['id'];
					$legacy_id    = (int) $kg_row['origin_id'];
					$scope_str    = (string) $kg_row['scope_id'];
					// Fire hook before deletion so cascade listeners can still resolve passage_ids.
					do_action( 'bizcity_twinchat_after_source_delete', $legacy_id > 0 ? $legacy_id : $source_id, $scope_str );
					// Delete kg_passages for both id-paths.
					$wpdb->delete( $kg->tbl_passages(), [ 'source_id' => $kg_source_id ] );
					if ( $legacy_id > 0 ) {
						$wpdb->delete( $kg->tbl_passages(), [ 'scope_id' => $scope_str, 'source_id' => $legacy_id ] );
					}
					// Delete the kg_sources row.
					$wpdb->delete( $kg->tbl_sources(), [ 'id' => $kg_source_id ] );
					// Delete legacy webchat_sources row (no hook — already fired above).
					if ( $legacy_id > 0 && class_exists( 'BizCity_TwinChat_Sources_Database' ) ) {
						BizCity_TwinChat_Sources_Database::instance()->delete_source( $legacy_id );
					}
					return rest_ensure_response( [ 'ok' => true ] );
				}
			}

			$row = BizCity_TwinChat_Sources_Service::instance()->get_source( $source_id );
			if ( ! $row ) {
				return new WP_Error( 'not_found', 'Source not found', [ 'status' => 404 ] );
			}
			// Ownership check — only enforce when notebook_id is present in the row.
			if ( isset( $row['notebook_id'] ) && (int) $row['notebook_id'] > 0 && (int) $row['notebook_id'] !== $notebook_id ) {
				return new WP_Error( 'forbidden', 'Access denied', [ 'status' => 403 ] );
			}
			$ok = BizCity_TwinChat_Sources_Service::instance()->delete_source( $source_id );
			return rest_ensure_response( [ 'ok' => (bool) $ok ] );
		} catch ( \Throwable $e ) {
			error_log( '[TwinChat] delete_source error: ' . $e->getMessage() );
			return new WP_Error( 'delete_source_error', $e->getMessage(), [ 'status' => 500 ] );
		}
	}

	public function delete_by_origin( WP_REST_Request $request ) {
		global $wpdb;
		$notebook_id = (int) $request->get_param( 'notebook_id' );
		$origin      = (string) $request->get_param( 'origin' );
		if ( $notebook_id <= 0 || $origin === '' ) {
			return new WP_Error( 'bad_request', 'notebook_id and origin are required', [ 'status' => 400 ] );
		}
		$auth = $this->check_notebook_access( $notebook_id );
		if ( is_wp_error( $auth ) ) return $auth;
		try {
			$deleted = 0;
			if ( class_exists( 'BizCity_KG_Database' ) ) {
				$kg     = BizCity_KG_Database::instance();
				$tbl    = $kg->tbl_passages();
				$deleted = (int) $wpdb->query( $wpdb->prepare(
					"DELETE FROM {$tbl} WHERE notebook_id = %d AND origin = %s AND source_id IS NULL",
					$notebook_id, $origin
				) );
			}
			return rest_ensure_response( [ 'ok' => true, 'deleted' => $deleted ] );
		} catch ( \Throwable $e ) {
			error_log( '[TwinChat] delete_by_origin error: ' . $e->getMessage() );
			return new WP_Error( 'delete_by_origin_error', $e->getMessage(), [ 'status' => 500 ] );
		}
	}

	public function get_by_origin( WP_REST_Request $request ) {
		global $wpdb;
		$notebook_id = (int) $request->get_param( 'notebook_id' );
		$origin      = (string) $request->get_param( 'origin' );
		if ( $notebook_id <= 0 || $origin === '' ) {
			return new WP_Error( 'bad_request', 'notebook_id and origin are required', [ 'status' => 400 ] );
		}
		$auth = $this->check_notebook_access( $notebook_id );
		if ( is_wp_error( $auth ) ) return $auth;
		try {
			if ( ! class_exists( 'BizCity_KG_Database' ) ) {
				return new WP_Error( 'no_kg', 'KG database not available', [ 'status' => 500 ] );
			}
			$kg  = BizCity_KG_Database::instance();
			$tbl = $kg->tbl_passages();
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, content, created_at FROM {$tbl}
				  WHERE notebook_id = %d AND origin = %s AND source_id IS NULL
				  ORDER BY id ASC",
				$notebook_id, $origin
			), ARRAY_A );
			$rows = is_array( $rows ) ? $rows : [];
			$texts = array_map( static function ( $r ) { return (string) $r['content']; }, $rows );
			$content_text = implode( "\n\n", $texts );
			$char_count   = mb_strlen( $content_text );
			// Derive title + type from origin string (mirror UI logic)
			$type = 'text'; $title = 'Văn bản thủ công';
			if ( strpos( $origin, 'file:' ) === 0 ) { $type = 'file'; $title = substr( $origin, 5 ); }
			elseif ( strpos( $origin, 'url:' ) === 0 ) { $type = 'url';  $title = substr( $origin, 4 ); }
			elseif ( $origin === 'url' ) { $type = 'url'; $title = 'Trang web'; }
			$created_at = $rows ? (string) $rows[0]['created_at'] : '';
			$data = [
				'id'               => 0,
				'notebook_id'      => $notebook_id,
				'user_id'          => 0,
				'title'            => $title,
				'source_type'      => $type,
				'source_url'       => ( $type === 'url' ) ? $title : '',
				'attachment_id'    => 0,
				'content_hash'     => '',
				'char_count'       => $char_count,
				'token_estimate'   => (int) round( $char_count / 4 ),
				'chunk_count'      => count( $rows ),
				'embedding_model'  => '',
				'embedding_status' => 'ready',
				'status'           => 'ready',
				'error_message'    => '',
				'created_at'       => $created_at,
				'updated_at'       => $created_at,
				'origin'           => $origin,
				'content_text'     => $content_text,
			];
			return rest_ensure_response( [ 'ok' => true, 'data' => $data ] );
		} catch ( \Throwable $e ) {
			error_log( '[TwinChat] get_by_origin error: ' . $e->getMessage() );
			return new WP_Error( 'get_by_origin_error', $e->getMessage(), [ 'status' => 500 ] );
		}
	}

	/* ── Phase 0.6 — KG read-switch helper ─────────────────────────────── */

	/**
	 * Read sources from bizcity_kg_sources (Phase 0.6 unified table).
	 * Normalises rows to the same shape the FE expects from the legacy table.
	 *
	 * @param  int   $notebook_id
	 * @param  array $args  { limit: int, search: string }
	 * @return array[]
	 */
	private static function _list_kg_sources( int $notebook_id, array $args ): array {
		global $wpdb;
		$db    = BizCity_KG_Database::instance();
		$limit = max( 1, min( 200, (int) ( $args['limit'] ?: 50 ) ) );

		// Build WHERE clause. Note: scope_id is stored as a string in the KG table.
		$where  = $wpdb->prepare( 'scope_type = %s AND scope_id = %s AND status = %s', 'notebook', (string) $notebook_id, 'active' );
		$params = [];

		if ( ! empty( $args['search'] ) ) {
			$like  = '%' . $wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%';
			$where .= $wpdb->prepare( ' AND title LIKE %s', $like );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT id, uuid, title, status, origin_kind, origin_url, origin_id,
			        passage_count, created_at, updated_at
			   FROM {$db->tbl_sources()}
			  WHERE {$where}
			  ORDER BY created_at DESC
			  LIMIT {$limit}",
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) return [];

		// PHASE-0.13 — aggregate graph-extraction completion per source from kg_passages.
		// (TwinChat sources are mirrored as kg_sources; passages belonging to each
		//  source carry `extraction_status` updated by BizCity_KG_Triplet_Extractor.
		//  Note: `kg_source_chunks` is bizdoc-specific and is empty for TwinChat,
		//  which is why the brain badge previously never lit up.)
		//
		// PHASE-0.13 Wave 10c BUGFIX (2026-05-01) — `kg_passages.source_id` historically
		// holds the LEGACY `bizcity_webchat_sources.id` (which equals `kg_sources.origin_id`
		// for mirror rows), NOT the new auto-increment `kg_sources.id`. Aggregating on
		// `kg_sources.id` alone made every source render at 0% even after extraction
		// completed. Fix: query passages on BOTH ids (current + legacy origin_id), then
		// bucket the result back into the canonical `kg_sources.id`.
		$ids = array_values( array_unique( array_filter( array_map(
			static function ( $r ) { return (int) ( $r['id'] ?? 0 ); },
			$rows
		) ) ) );
		$agg_map = [];
		if ( ! empty( $ids ) ) {
			// Build a passage_source_id → kg_sources.id lookup so we can aggregate
			// across both the new kg id and the legacy origin id without losing
			// attribution (canonical bucket = kg_sources.id).
			$lookup     = []; // passage_source_id => kg_sources.id
			$query_ids  = [];
			foreach ( $rows as $r ) {
				$sid = (int) ( $r['id'] ?? 0 );
				$oid = (int) ( $r['origin_id'] ?? 0 );
				if ( $sid <= 0 ) continue;
				if ( ! isset( $lookup[ $sid ] ) ) { $lookup[ $sid ] = $sid; $query_ids[] = $sid; }
				if ( $oid > 0 && ! isset( $lookup[ $oid ] ) ) {
					// Only safe if origin_id doesn't collide with a different source's kg id.
					$lookup[ $oid ] = $sid;
					$query_ids[]   = $oid;
				}
			}
			$query_ids    = array_values( array_unique( $query_ids ) );
			$placeholders = implode( ',', array_fill( 0, count( $query_ids ), '%d' ) );
			$tbl_passages = $db->tbl_passages();
			$agg_sql      = "SELECT source_id,
				COUNT(*) AS total_chunks,
				SUM(CASE WHEN extraction_status = 'done'  THEN 1 ELSE 0 END) AS done_chunks,
				SUM(CASE WHEN extraction_status = 'error' THEN 1 ELSE 0 END) AS error_chunks
				FROM {$tbl_passages}
				WHERE source_id IN ({$placeholders})
				GROUP BY source_id";
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$agg_rows = $wpdb->get_results( $wpdb->prepare( $agg_sql, $query_ids ), ARRAY_A );
			if ( is_array( $agg_rows ) ) {
				foreach ( $agg_rows as $a ) {
					$psid = (int) $a['source_id'];
					$kid  = $lookup[ $psid ] ?? 0;
					if ( $kid <= 0 ) continue;
					if ( ! isset( $agg_map[ $kid ] ) ) {
						$agg_map[ $kid ] = [ 'total' => 0, 'done' => 0, 'error' => 0 ];
					}
					$agg_map[ $kid ]['total'] += (int) $a['total_chunks'];
					$agg_map[ $kid ]['done']  += (int) $a['done_chunks'];
					$agg_map[ $kid ]['error'] += (int) $a['error_chunks'];
				}
			}
		}

		return array_map( static function ( $r ) use ( $agg_map ) {
			$kid   = (int) $r['id'];
			$stat  = $agg_map[ $kid ] ?? [ 'total' => 0, 'done' => 0, 'error' => 0 ];
			$total = $stat['total'];
			$done  = $stat['done'];
			return [
				'id'                  => (int) $r['id'],
				'source_id'           => (int) $r['id'],
				'uuid'                => (string) ( $r['uuid'] ?? '' ),
				'title'               => (string) ( $r['title'] ?? '' ),
				'source_type'         => (string) ( $r['origin_kind'] ?? 'file' ),
				'source_url'          => (string) ( $r['origin_url'] ?? '' ),
				'chunk_count'         => (int) ( $r['passage_count'] ?? 0 ),
				'embedding_status'    => 'ready',
				'status'              => (string) ( $r['status'] ?? 'active' ),
				'created_at'          => (string) ( $r['created_at'] ?? '' ),
				'updated_at'          => (string) ( $r['updated_at'] ?? $r['created_at'] ?? '' ),
				'extraction_total'    => $total,
				'extraction_done'     => $done,
				'extraction_error'    => $stat['error'],
				'extraction_complete' => ( $total > 0 && $done >= $total ),
				'extraction_progress' => $total > 0 ? round( $done / $total, 4 ) : 0.0,
			];
		}, $rows );
	}

	/* ── Sprint 5.0d — POST /events/dispatch ───────────────────────────── */

	/**
	 * Whitelist of event_types the FE is allowed to dispatch directly.
	 * Everything else MUST originate server-side (R-EVT-3).
	 *
	 * @var string[]
	 */
	private static $fe_dispatchable_types = [
		'suggestion_clicked',
		'note_pinned',
	];

	/**
	 * POST /events/dispatch — FE-initiated event dispatch (audit trail for user actions).
	 *
	 * Body JSON:
	 *   {
	 *     "event_type":      "suggestion_clicked",   // required, must be whitelisted
	 *     "payload":         { ... },                // required, validated by taxonomy required_fields()
	 *     "notebook_id":     123,                    // optional, sets opts.blog_id-like scope
	 *     "session_id":      "abc-123",              // optional
	 *     "conversation_id": "conv-xyz",             // optional
	 *     "trace_id":        "uuid",                 // optional
	 *     "parent_event_uuid": "uuid"                // optional
	 *   }
	 *
	 * Response: { ok: true, event_uuid: "..." }
	 */
	public function handle_dispatch_event( WP_REST_Request $request ) {
		if ( ! class_exists( 'BizCity_Twin_Event_Bus' ) ) {
			return new WP_Error( 'event_bus_missing', 'Twin Event Bus not loaded.', [ 'status' => 500 ] );
		}

		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) $body = [];

		$event_type = isset( $body['event_type'] ) ? (string) $body['event_type'] : '';
		$payload    = isset( $body['payload'] ) && is_array( $body['payload'] ) ? $body['payload'] : [];

		if ( $event_type === '' ) {
			return new WP_Error( 'invalid_event_type', 'event_type is required.', [ 'status' => 400 ] );
		}
		if ( ! in_array( $event_type, self::$fe_dispatchable_types, true ) ) {
			return new WP_Error(
				'event_type_not_allowed',
				sprintf( 'event_type "%s" is not FE-dispatchable.', $event_type ),
				[ 'status' => 403 ]
			);
		}

		// Optional notebook scope check (only if notebook_id given).
		$notebook_id = isset( $body['notebook_id'] ) ? (int) $body['notebook_id'] : 0;
		if ( $notebook_id > 0 ) {
			$auth = $this->check_notebook_access( $notebook_id );
			if ( is_wp_error( $auth ) ) return $auth;
		}

		// 2026-04-30 — event_source must be one of the taxonomy-allowed values
		// (see BizCity_Twin_Event_Taxonomy::allowed_sources()). 'user' was rejected
		// because the FE-dispatch surface is part of the twinchat module.
		$opts = [
			'event_source' => 'twinchat',
			'user_id'      => get_current_user_id(),
		];
		if ( ! empty( $body['trace_id'] ) )           $opts['trace_id']           = (string) $body['trace_id'];
		if ( ! empty( $body['conversation_id'] ) )    $opts['conversation_id']    = (string) $body['conversation_id'];
		if ( ! empty( $body['session_id'] ) )         $opts['session_id']         = (string) $body['session_id'];
		if ( ! empty( $body['parent_event_uuid'] ) )  $opts['parent_event_uuid']  = (string) $body['parent_event_uuid'];

		try {
			$uuid = BizCity_Twin_Event_Bus::dispatch_v2( $event_type, $payload, $opts );
		} catch ( \Throwable $e ) {
			return new WP_Error(
				'event_dispatch_failed',
				$e->getMessage(),
				[ 'status' => 400 ]
			);
		}

		return rest_ensure_response( [
			'ok'         => true,
			'event_uuid' => $uuid,
		] );
	}

	/**
	 * Wave 0.18.3 — Persona context for a notebook.
	 *
	 * Returns the bound character (id, name, avatar, description, system_prompt
	 * preview, starter prompts derived from greeting_messages) plus any
	 * persona-tool provider chips registered through `BizCity_Persona_Registry`.
	 * Used by the SmartSourcesPanel "Persona Tools" section.
	 *
	 * Response shape:
	 * {
	 *   ok: true,
	 *   data: {
	 *     character: { id, name, slug, avatar, description, system_prompt_excerpt,
	 *                  capabilities[], industries[], starter_prompts[] } | null,
	 *     provider:  { id, label, chips: [{ label, icon, action, payload_schema }] } | null,
	 *     tools:     [{ name, label, description, side_effect, cost_class }]
	 *   }
	 * }
	 */
	public function get_persona_context( WP_REST_Request $request ) {
		$notebook_id = (int) $request->get_param( 'notebook_id' );
		$auth = $this->check_notebook_access( $notebook_id );
		if ( is_wp_error( $auth ) ) return $auth;

		$character = null;
		$provider_payload = null;
		$tools = [];

		if ( class_exists( 'BizCity_KG_Notebook_Service' ) ) {
			$nb = BizCity_KG_Notebook_Service::instance()->get( $notebook_id );
			$character_id = (int) ( $nb['character_id'] ?? 0 );
			if ( $character_id > 0 && class_exists( 'BizCity_Character' ) ) {
				$char = BizCity_Character::get( $character_id );
				if ( $char ) {
					// Decode greeting_messages directly from the row (not on the model).
					$starter_prompts = [];
					if ( class_exists( 'BizCity_Knowledge_Database' ) ) {
						$row = BizCity_Knowledge_Database::instance()->get_character( $character_id );
						$raw = is_object( $row ) ? ( $row->greeting_messages ?? '' ) : ( is_array( $row ) ? ( $row['greeting_messages'] ?? '' ) : '' );
						if ( is_string( $raw ) && $raw !== '' ) {
							$decoded = json_decode( $raw, true );
							if ( is_array( $decoded ) ) {
								foreach ( $decoded as $g ) {
									if ( is_string( $g ) && $g !== '' ) {
										$starter_prompts[] = $g;
									} elseif ( is_array( $g ) && ! empty( $g['text'] ) ) {
										$starter_prompts[] = (string) $g['text'];
									}
								}
							}
						}
					}
					$starter_prompts = array_slice( $starter_prompts, 0, 6 );

					$prompt_excerpt = '';
					if ( ! empty( $char->system_prompt ) ) {
						$plain = trim( wp_strip_all_tags( (string) $char->system_prompt ) );
						$prompt_excerpt = mb_substr( $plain, 0, 280 );
						if ( mb_strlen( $plain ) > 280 ) {
							$prompt_excerpt .= '…';
						}
					}

					$character = [
						'id'                   => (int) $char->id,
						'name'                 => (string) $char->name,
						'slug'                 => (string) $char->slug,
						'avatar'               => (string) ( $char->avatar ?? '' ),
						'description'          => (string) ( $char->description ?? '' ),
						'system_prompt_excerpt'=> $prompt_excerpt,
						'capabilities'         => is_array( $char->capabilities ) ? array_values( $char->capabilities ) : [],
						'industries'           => is_array( $char->industries ) ? array_values( $char->industries ) : [],
						'starter_prompts'      => $starter_prompts,
					];

					// Settings JSON may carry a `provider_id` (Wave 0.18 contract).
					$settings = is_array( $char->settings ) ? $char->settings : [];
					$provider_id = isset( $settings['provider_id'] ) ? (string) $settings['provider_id'] : '';

					if ( $provider_id !== '' && class_exists( 'BizCity_Persona_Registry' ) ) {
						$provider = BizCity_Persona_Registry::instance()->get( $provider_id );
						if ( $provider ) {
							$chips = [];
							try {
								$chips = (array) $provider->get_smart_source_chips();
							} catch ( \Throwable $e ) {
								$chips = [];
							}
							$tool_defs = [];
							try {
								$tool_defs = (array) $provider->get_tool_definitions();
							} catch ( \Throwable $e ) {
								$tool_defs = [];
							}
							foreach ( $tool_defs as $name => $def ) {
								if ( ! is_array( $def ) ) continue;
								$tools[] = [
									'name'        => isset( $def['name'] ) ? (string) $def['name'] : (string) $name,
									'label'       => isset( $def['label'] ) ? (string) $def['label'] : (string) $name,
									'description' => isset( $def['description'] ) ? (string) $def['description'] : '',
									'side_effect' => isset( $def['side_effect'] ) ? (string) $def['side_effect'] : '',
									'cost_class'  => isset( $def['cost_class'] ) ? (string) $def['cost_class'] : 'free',
								];
							}
							$provider_payload = [
								'id'    => $provider_id,
								'label' => method_exists( $provider, 'label' ) ? (string) $provider->label() : $provider_id,
								'chips' => array_values( $chips ),
							];
						}
					}
				}
			}
		}

		return rest_ensure_response( [
			'ok'   => true,
			'data' => [
				'character' => $character,
				'provider'  => $provider_payload,
				'tools'     => $tools,
			],
		] );
	}

	/* ── Wave 0.18.5c — Twin Guru picker (composer @-mention) ─────────── */

	/**
	 * GET /gurus/list
	 *
	 * Catalog of active Twin Gurus available to the current user. Used by the
	 * composer `@` picker (TwinGuruDialog). PHASE-0.13-v1 §10.2 contract:
	 * dropdown card list with avatar + name + slug + counts.
	 *
	 * Response:
	 * {
	 *   ok: true,
	 *   data: {
	 *     gurus: [
	 *       { character_id, slug, name, avatar, description,
	 *         system_prompt_excerpt, capabilities[], industries[] }
	 *     ]
	 *   }
	 * }
	 */
	public function list_gurus( WP_REST_Request $request ) {
		if ( ! class_exists( 'BizCity_Knowledge_Database' ) ) {
			return rest_ensure_response( [ 'ok' => true, 'data' => [ 'gurus' => [] ] ] );
		}
		$db   = BizCity_Knowledge_Database::instance();
		$rows = $db->get_characters( [ 'status' => 'active', 'limit' => 200 ] );
		$out  = [];
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$cid = (int) ( is_object( $row ) ? ( $row->id ?? 0 ) : ( $row['id'] ?? 0 ) );
				if ( $cid <= 0 ) continue;
				$prompt = (string) ( is_object( $row ) ? ( $row->system_prompt ?? '' ) : ( $row['system_prompt'] ?? '' ) );
				$plain  = trim( wp_strip_all_tags( $prompt ) );
				$excerpt = mb_substr( $plain, 0, 160 );
				if ( mb_strlen( $plain ) > 160 ) $excerpt .= '…';
				$caps  = json_decode( (string) ( is_object( $row ) ? ( $row->capabilities ?? '' ) : ( $row['capabilities'] ?? '' ) ), true );
				$inds  = json_decode( (string) ( is_object( $row ) ? ( $row->industries   ?? '' ) : ( $row['industries']   ?? '' ) ), true );
				$out[] = [
					'character_id'         => $cid,
					'slug'                 => (string) ( is_object( $row ) ? ( $row->slug ?? '' ) : ( $row['slug'] ?? '' ) ),
					'name'                 => (string) ( is_object( $row ) ? ( $row->name ?? '' ) : ( $row['name'] ?? '' ) ),
					'avatar'               => (string) ( is_object( $row ) ? ( $row->avatar ?? '' ) : ( $row['avatar'] ?? '' ) ),
					'description'          => (string) ( is_object( $row ) ? ( $row->description ?? '' ) : ( $row['description'] ?? '' ) ),
					'system_prompt_excerpt' => $excerpt,
					'capabilities'         => is_array( $caps ) ? array_values( $caps ) : [],
					'industries'           => is_array( $inds ) ? array_values( $inds ) : [],
				];
			}
		}
		// Allow plugins to filter / extend the catalog.
		$out = apply_filters( 'bizcity_twin_guru_catalog', $out, get_current_user_id() );
		return rest_ensure_response( [ 'ok' => true, 'data' => [ 'gurus' => array_values( $out ) ] ] );
	}

	/**
	 * GET /notebooks/{id}/sticky-guru
	 *
	 * Returns the per-(user, notebook) sticky Guru pinned via the @-picker.
	 * Stored as user_meta `bizcity_twin_sticky_guru_<notebook_id>`.
	 */
	public function get_sticky_guru( WP_REST_Request $request ) {
		$notebook_id = (int) $request->get_param( 'notebook_id' );
		$auth = $this->check_notebook_access( $notebook_id );
		if ( is_wp_error( $auth ) ) return $auth;
		$key = 'bizcity_twin_sticky_guru_' . $notebook_id;
		$row = get_user_meta( get_current_user_id(), $key, true );
		if ( ! is_array( $row ) || empty( $row['character_id'] ) ) {
			return rest_ensure_response( [ 'ok' => true, 'data' => [ 'guru' => null ] ] );
		}
		return rest_ensure_response( [ 'ok' => true, 'data' => [ 'guru' => [
			'character_id'   => (int) $row['character_id'],
			'character_slug' => (string) ( $row['character_slug'] ?? '' ),
			'character_name' => (string) ( $row['character_name'] ?? '' ),
			'avatar_url'     => (string) ( $row['avatar_url'] ?? '' ),
			'set_at'         => (int) ( $row['set_at'] ?? 0 ),
			'source'         => (string) ( $row['source'] ?? 'mention' ),
		] ] ] );
	}

	/**
	 * POST /notebooks/{id}/sticky-guru
	 * Body: { character_id, character_slug, character_name, avatar_url? }
	 */
	public function set_sticky_guru( WP_REST_Request $request ) {
		$notebook_id = (int) $request->get_param( 'notebook_id' );
		$auth = $this->check_notebook_access( $notebook_id );
		if ( is_wp_error( $auth ) ) return $auth;
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) $body = [];
		$cid = (int) ( $body['character_id'] ?? 0 );
		if ( $cid <= 0 ) {
			return new WP_Error( 'invalid_character', 'character_id is required', [ 'status' => 400 ] );
		}
		// Validate character exists + active.
		if ( class_exists( 'BizCity_Character' ) ) {
			$char = BizCity_Character::get( $cid );
			if ( ! $char ) {
				return new WP_Error( 'character_not_found', 'Character not found', [ 'status' => 404 ] );
			}
		}
		$payload = [
			'character_id'   => $cid,
			'character_slug' => isset( $body['character_slug'] ) ? sanitize_key( (string) $body['character_slug'] ) : '',
			'character_name' => isset( $body['character_name'] ) ? sanitize_text_field( (string) $body['character_name'] ) : '',
			'avatar_url'     => isset( $body['avatar_url'] ) ? esc_url_raw( (string) $body['avatar_url'] ) : '',
			'set_at'         => time(),
			'source'         => 'mention',
		];
		$key = 'bizcity_twin_sticky_guru_' . $notebook_id;
		update_user_meta( get_current_user_id(), $key, $payload );
		return rest_ensure_response( [ 'ok' => true, 'data' => [ 'guru' => $payload ] ] );
	}

	/**
	 * DELETE /notebooks/{id}/sticky-guru
	 */
	public function clear_sticky_guru( WP_REST_Request $request ) {
		$notebook_id = (int) $request->get_param( 'notebook_id' );
		$auth = $this->check_notebook_access( $notebook_id );
		if ( is_wp_error( $auth ) ) return $auth;
		$key = 'bizcity_twin_sticky_guru_' . $notebook_id;
		delete_user_meta( get_current_user_id(), $key );
		return rest_ensure_response( [ 'ok' => true ] );
	}

	/* ── PHASE 0.31 T-S3.2 — Per-passage actions ─────────────────────── */

	/**
	 * Look up `notebook_id` for a passage and verify access.
	 * Returns array{passage_id, notebook_id} on success, WP_Error otherwise.
	 */
	private function load_passage_with_access( int $passage_id ) {
		global $wpdb;
		if ( $passage_id <= 0 ) {
			return new WP_Error( 'invalid_passage', 'Invalid passage_id', [ 'status' => 400 ] );
		}
		if ( ! class_exists( 'BizCity_KG_Database' ) ) {
			return new WP_Error( 'kg_unavailable', 'KG-Hub not loaded', [ 'status' => 503 ] );
		}
		$db  = BizCity_KG_Database::instance();
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, notebook_id FROM {$db->tbl_passages()} WHERE id = %d LIMIT 1",
			$passage_id
		), ARRAY_A );
		if ( ! $row ) {
			return new WP_Error( 'passage_not_found', 'Passage not found', [ 'status' => 404 ] );
		}
		$auth = $this->check_notebook_access( (int) $row['notebook_id'] );
		if ( is_wp_error( $auth ) ) return $auth;
		return [
			'passage_id'  => (int) $row['id'],
			'notebook_id' => (int) $row['notebook_id'],
		];
	}

	/**
	 * POST /passages/{id}/tag
	 * Body: { tag: string, action?: 'added'|'removed' }
	 * Adds/removes a tag on the passage's metadata.tags and fires note_tagged.
	 */
	public function tag_passage( WP_REST_Request $request ) {
		$ctx = $this->load_passage_with_access( (int) $request->get_param( 'passage_id' ) );
		if ( is_wp_error( $ctx ) ) return $ctx;

		$body   = $request->get_json_params();
		if ( ! is_array( $body ) ) { $body = []; }
		$tag    = isset( $body['tag'] )    ? sanitize_text_field( (string) $body['tag'] )    : '';
		$action = isset( $body['action'] ) ? (string) $body['action'] : 'added';
		$action = ( $action === 'removed' ) ? 'removed' : 'added';

		if ( $tag === '' ) {
			return new WP_Error( 'tag_required', 'Body must include non-empty `tag`.', [ 'status' => 400 ] );
		}
		if ( ! class_exists( 'BizCity_KG_Source_Service' ) ) {
			return new WP_Error( 'kg_unavailable', 'KG service unavailable', [ 'status' => 503 ] );
		}
		$result = BizCity_KG_Source_Service::instance()->tag_passage( $ctx['passage_id'], $tag, $action );
		if ( is_wp_error( $result ) ) return $result;

		return rest_ensure_response( [
			'ok'          => true,
			'passage_id'  => $ctx['passage_id'],
			'notebook_id' => $ctx['notebook_id'],
			'tag'         => strtolower( trim( $tag ) ),
			'action'      => $action,
		] );
	}

	/**
	 * POST /passages/{id}/trigger-workflow
	 * Body: { tag?: string }  (default: filterable, fallback `#trigger`)
	 *
	 * Implementation: tag the passage with the reserved "trigger" tag so any
	 * workflow whose `nb_note_tagged` trigger filters on that tag fires. This
	 * keeps a single mechanism (the existing trigger) instead of inventing a
	 * second event channel.
	 */
	public function trigger_workflow_for_passage( WP_REST_Request $request ) {
		$ctx = $this->load_passage_with_access( (int) $request->get_param( 'passage_id' ) );
		if ( is_wp_error( $ctx ) ) return $ctx;

		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) { $body = []; }
		$tag  = isset( $body['tag'] ) && trim( (string) $body['tag'] ) !== ''
			? sanitize_text_field( (string) $body['tag'] )
			: apply_filters( 'bizcity_twin_default_trigger_tag', 'trigger' );

		if ( ! class_exists( 'BizCity_KG_Source_Service' ) ) {
			return new WP_Error( 'kg_unavailable', 'KG service unavailable', [ 'status' => 503 ] );
		}

		// Force-add (re-add idempotent: the service no-ops if already present, which
		// would not fire the event again — so for retriggering we remove-then-add).
		BizCity_KG_Source_Service::instance()->tag_passage( $ctx['passage_id'], $tag, 'removed' );
		$result = BizCity_KG_Source_Service::instance()->tag_passage( $ctx['passage_id'], $tag, 'added' );
		if ( is_wp_error( $result ) ) return $result;

		return rest_ensure_response( [
			'ok'          => true,
			'passage_id'  => $ctx['passage_id'],
			'notebook_id' => $ctx['notebook_id'],
			'tag'         => strtolower( trim( $tag ) ),
			'note'        => 'Fired bizcity_twin_notebook_event(note_tagged); workflows with matching nb_note_tagged trigger will queue.',
		] );
	}
}
