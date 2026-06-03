<?php
/**
 * BizCity KG Hub — Public Workflow REST API (PHASE 0.31 T-S1.6)
 *
 * Thin, *workflow-friendly* REST surface around `BizCity_KG_Retriever::ask()`.
 * Designed to be consumed by:
 *   - workflow blocks (e.g. WaicAction_nb_query_kg) inside this site
 *   - external automations (n8n, Zapier, sister sites) holding a token
 *
 * The internal admin REST already lives at
 *   POST /wp-json/bizcity-knowledge/v2/notebooks/{id}/query
 * but is gated by capability checks suited to the studio UI. For
 * automation we expose a parallel namespace with shared-token auth so
 * server-side workflows (which run as guest in REST context) can call
 * it predictably.
 *
 * Endpoint:
 *   POST /wp-json/bizcity/v1/kg/query
 *   Body (JSON):
 *     {
 *       "notebook_id": 22,
 *       "query":       "Twin AI là gì?",
 *       "limit":       5,           // optional, maps → rerank_top_k
 *       "answer":      false,       // optional, default false (passages only)
 *       "expand_hops": 1            // optional
 *     }
 *   Auth (any of):
 *     - logged-in user with `manage_options`
 *     - HTTP header `X-BizCity-KG-Token: <secret>` matching option
 *       `bizcity_kg_public_api_token`
 *
 *   200 OK shape:
 *     {
 *       "ok": true,
 *       "notebook_id": 22,
 *       "query": "...",
 *       "passages": [ {uuid, content, score, source_id, ...} ],
 *       "answer":   "..." | null,
 *       "took_ms":  123
 *     }
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge\KG_Hub
 * @since      PHASE 0.31
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_KG_Public_API {

	const REST_NS    = 'bizcity/v1';
	const ROUTE      = '/kg/query';
	const TOKEN_OPT  = 'bizcity_kg_public_api_token';
	const HEADER_KEY = 'X-BizCity-KG-Token';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route(
			self::REST_NS,
			self::ROUTE,
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'handle_query' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'notebook_id' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'query' => array(
							'type'     => 'string',
							'required' => true,
						),
						'limit' => array(
							'type'              => 'integer',
							'required'          => false,
							'default'           => 5,
							'sanitize_callback' => 'absint',
						),
						'answer' => array(
							'type'     => 'boolean',
							'required' => false,
							'default'  => false,
						),
						'expand_hops' => array(
							'type'              => 'integer',
							'required'          => false,
							'default'           => 1,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		// GET /kg/query/diag — lightweight self-test (no LLM cost).
		register_rest_route(
			self::REST_NS,
			'/kg/query/diag',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'handle_diag' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);
	}

	/**
	 * Permission: logged-in admin OR matching token header.
	 */
	public function check_permission( WP_REST_Request $request ) {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		$token = (string) get_option( self::TOKEN_OPT, '' );
		if ( '' === $token ) {
			return new WP_Error(
				'kg_no_token_configured',
				'Public KG API token is not set. Either log in as admin or configure option ' . self::TOKEN_OPT . '.',
				array( 'status' => 401 )
			);
		}
		$sent = (string) $request->get_header( self::HEADER_KEY );
		// WP normalises HTTP headers to lowercase + underscores when retrieving
		// individual header objects, but get_header() handles aliasing.
		if ( '' === $sent ) {
			$sent = (string) $request->get_header( strtolower( self::HEADER_KEY ) );
		}
		if ( '' !== $sent && hash_equals( $token, $sent ) ) {
			return true;
		}
		return new WP_Error(
			'kg_forbidden',
			'Missing or invalid ' . self::HEADER_KEY . ' header.',
			array( 'status' => 401 )
		);
	}

	/**
	 * Main query handler.
	 */
	public function handle_query( WP_REST_Request $request ) {
		$started     = microtime( true );
		$notebook_id = (int) $request->get_param( 'notebook_id' );
		$query       = trim( (string) $request->get_param( 'query' ) );
		$limit       = max( 1, min( 50, (int) $request->get_param( 'limit' ) ) );
		$answer      = (bool) $request->get_param( 'answer' );
		$expand_hops = max( 0, min( 3, (int) $request->get_param( 'expand_hops' ) ) );

		if ( $notebook_id <= 0 ) {
			return new WP_Error( 'kg_bad_notebook', 'notebook_id must be a positive integer.', array( 'status' => 400 ) );
		}
		if ( '' === $query ) {
			return new WP_Error( 'kg_empty_query', 'query is required.', array( 'status' => 400 ) );
		}
		if ( ! class_exists( 'BizCity_KG_Retriever' ) ) {
			return new WP_Error( 'kg_no_retriever', 'BizCity_KG_Retriever not loaded.', array( 'status' => 500 ) );
		}

		// Verify notebook actually exists in this blog's shard so we surface
		// a clean 404 instead of returning empty passages silently.
		if ( ! $this->notebook_exists( $notebook_id ) ) {
			return new WP_Error( 'kg_notebook_not_found', 'notebook_id ' . $notebook_id . ' not found in current blog.', array( 'status' => 404 ) );
		}

		$opts = array(
			'rerank_top_k' => $limit,
			'expand_hops'  => $expand_hops,
			'answer'       => $answer,
		);

		try {
			$raw = BizCity_KG_Retriever::instance()->ask( $notebook_id, $query, $opts );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'kg_retriever_threw', $e->getMessage(), array( 'status' => 500 ) );
		}

		$passages = array();
		if ( is_array( $raw ) && isset( $raw['passages'] ) && is_array( $raw['passages'] ) ) {
			foreach ( $raw['passages'] as $p ) {
				$passages[] = array(
					'uuid'       => isset( $p['uuid'] )       ? (string) $p['uuid']       : '',
					'content'    => isset( $p['content'] )    ? (string) $p['content']    : '',
					'score'      => isset( $p['score'] )      ? (float)  $p['score']      : null,
					'source_id'  => isset( $p['source_id'] )  ? (int)    $p['source_id']  : null,
					'chunk_index'=> isset( $p['chunk_index'] )? (int)    $p['chunk_index']: null,
				);
			}
		}

		$answer_text = null;
		if ( $answer && is_array( $raw ) && isset( $raw['answer'] ) ) {
			$answer_text = is_string( $raw['answer'] ) ? $raw['answer'] : ( $raw['answer']['text'] ?? null );
		}

		return rest_ensure_response( array(
			'ok'           => true,
			'notebook_id'  => $notebook_id,
			'query'        => $query,
			'limit'        => $limit,
			'passages'     => $passages,
			'passage_count'=> count( $passages ),
			'answer'       => $answer_text,
			'took_ms'      => (int) round( ( microtime( true ) - $started ) * 1000 ),
		) );
	}

	/**
	 * Lightweight diagnostic endpoint. Reports schema/health WITHOUT
	 * triggering any LLM/embedding cost.
	 */
	public function handle_diag( WP_REST_Request $request ) {
		global $wpdb;
		$db_ok      = class_exists( 'BizCity_KG_Database' );
		$retr_ok    = class_exists( 'BizCity_KG_Retriever' );
		$embed_ok   = class_exists( 'BizCity_KG_Vector_Index' );
		$tbl_chunks = $db_ok ? BizCity_KG_Database::instance()->tbl_source_chunks() : ( $wpdb->prefix . 'bizcity_kg_passages' );
		$tbl_nb     = $wpdb->prefix . 'bizcity_kg_notebooks';

		$nb_count    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl_nb}" );
		$chunk_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl_chunks}" );
		$embed_done  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl_chunks} WHERE embed_status = 'done'" );

		return rest_ensure_response( array(
			'ok'                    => $db_ok && $retr_ok && $embed_ok,
			'classes'               => array(
				'BizCity_KG_Database'     => $db_ok,
				'BizCity_KG_Retriever'    => $retr_ok,
				'BizCity_KG_Vector_Index' => $embed_ok,
			),
			'tables'                => array(
				'notebooks'      => $tbl_nb,
				'source_chunks'  => $tbl_chunks,
			),
			'counts'                => array(
				'notebooks'         => $nb_count,
				'source_chunks'     => $chunk_count,
				'embeddings_done'   => $embed_done,
			),
			'token_configured'      => '' !== (string) get_option( self::TOKEN_OPT, '' ),
		) );
	}

	/**
	 * Verify the notebook row exists in the current blog shard.
	 */
	private function notebook_exists( $notebook_id ) {
		global $wpdb;
		$tbl = $wpdb->prefix . 'bizcity_kg_notebooks';
		$row = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$tbl} WHERE id = %d LIMIT 1", $notebook_id ) );
		return ! empty( $row );
	}
}

BizCity_KG_Public_API::instance();
