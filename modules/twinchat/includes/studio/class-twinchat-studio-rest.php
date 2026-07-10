<?php
/**
 * Bizcity Twin AI — TwinChat Studio REST Controller
 *
 * Phase 0.7 Wave C — REST surface for the Studio panel button → docgen flow.
 *
 * Routes (namespace bizcity-twinchat/v1):
 *   GET    /studio/tools                          → list registered tools
 *   POST   /studio/(?P<notebook_id>\d+)/generate  → generate artifact
 *   GET    /studio/(?P<notebook_id>\d+)/outputs   → list artifacts
 *   GET    /studio/output/(?P<id>\d+)             → fetch one artifact
 *   DELETE /studio/output/(?P<id>\d+)             → delete artifact
 *   POST   /studio/output/(?P<id>\d+)/regenerate  → re-run with fresh skeleton
 *
 * PHP 7.4 compatible.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Modules\TwinChat\Studio
 * @since 0.7.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_TwinChat_Studio_REST {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	public function register_routes() {
		$ns = defined( 'BIZCITY_TWINCHAT_REST_NS' ) ? BIZCITY_TWINCHAT_REST_NS : 'bizcity-twinchat/v1';

		register_rest_route( $ns, '/studio/tools', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'list_tools' ],
			'permission_callback' => [ $this, 'check_logged_in' ],
		] );

		register_rest_route( $ns, '/studio/(?P<notebook_id>\d+)/generate', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'generate' ],
			'permission_callback' => [ $this, 'check_logged_in' ],
			'args' => [
				'notebook_id' => [ 'type' => 'integer', 'required' => true ],
			],
		] );

		register_rest_route( $ns, '/studio/(?P<notebook_id>\d+)/outputs', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'list_outputs' ],
			'permission_callback' => [ $this, 'check_logged_in' ],
			'args' => [
				'notebook_id' => [ 'type' => 'integer', 'required' => true ],
				'tool_type'   => [ 'type' => 'string',  'default' => '' ],
			],
		] );

		register_rest_route( $ns, '/studio/output/(?P<id>\d+)', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_output' ],
				'permission_callback' => [ $this, 'check_logged_in' ],
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'delete_output' ],
				'permission_callback' => [ $this, 'check_logged_in' ],
			],
		] );

		register_rest_route( $ns, '/studio/output/(?P<id>\d+)/regenerate', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'regenerate_output' ],
			'permission_callback' => [ $this, 'check_logged_in' ],
		] );

		register_rest_route( $ns, '/studio/output/(?P<id>\d+)/status', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'output_status' ],
			'permission_callback' => [ $this, 'check_logged_in' ],
		] );

		// Alias route — /studio/job/{id}/status (new Job-Manager based polling).
		register_rest_route( $ns, '/studio/job/(?P<id>\d+)/status', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'output_status' ],
			'permission_callback' => [ $this, 'check_logged_in' ],
		] );
	}

	public function check_logged_in() {
		return is_user_logged_in()
			? true
			: new WP_Error( 'rest_forbidden', 'Login required.', [ 'status' => 401 ] );
	}

	/* ─────────────────────────── Handlers ───────────────────────────── */

	public function list_tools( WP_REST_Request $request ) {
		if ( ! class_exists( 'BCN_Notebook_Tool_Registry' ) ) {
			return new WP_Error(
				'registry_missing',
				'BCN_Notebook_Tool_Registry không tồn tại — Companion Notebook plugin cần được kích hoạt.',
				[ 'status' => 503 ]
			);
		}
		return rest_ensure_response( [
			'tools' => BizCity_TwinChat_Studio::get_available_tools(),
		] );
	}

	public function generate( WP_REST_Request $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) $body = [];

		$notebook_id = (int) $request->get_param( 'notebook_id' );
		$tool_type   = isset( $body['tool_type'] ) ? sanitize_key( (string) $body['tool_type'] ) : '';
		$source_ids  = isset( $body['source_ids'] ) && is_array( $body['source_ids'] )
			? array_values( array_filter( array_map( 'intval', $body['source_ids'] ) ) )
			: [];
		$force       = ! empty( $body['force'] );
		// Wave 2 (PHASE-6.1 §8.4) — kickstart: chain doc generation right after the
		// Studio job, so the user lands on bzdoc with the document already drafted.
		$kickstart   = ! empty( $body['kickstart'] );

		if ( $tool_type === '' ) {
			return new WP_Error( 'missing_tool', 'tool_type required.', [ 'status' => 400 ] );
		}

		$user_id = get_current_user_id();
		$id = BizCity_TwinChat_Studio::instance()->enqueue_generate( $notebook_id, $tool_type, $user_id, [
			'source_ids' => $source_ids,
			'force'      => $force,
			'kickstart'  => $kickstart,
		] );

		if ( is_wp_error( $id ) ) return $id;

		$row = BizCity_TwinChat_Studio::instance()->get_output( (int) $id );
		return rest_ensure_response( [
			'ok'      => true,
			'output'  => self::shape_row( $row ),
		] );
	}

	public function list_outputs( WP_REST_Request $request ) {
		$notebook_id = (int) $request->get_param( 'notebook_id' );
		$tool_type   = (string) $request->get_param( 'tool_type' );
		$rows = BizCity_TwinChat_Studio::instance()->get_outputs( $notebook_id, $tool_type );
		return rest_ensure_response( [
			'outputs' => array_map( [ __CLASS__, 'shape_row' ], $rows ),
		] );
	}

	public function get_output( WP_REST_Request $request ) {
		$row = BizCity_TwinChat_Studio::instance()->get_output( (int) $request->get_param( 'id' ), true );
		if ( ! $row ) return new WP_Error( 'not_found', 'Output not found.', [ 'status' => 404 ] );
		return rest_ensure_response( self::shape_row( $row, true ) );
	}

	public function delete_output( WP_REST_Request $request ) {
		$ok = BizCity_TwinChat_Studio::instance()->delete_output( (int) $request->get_param( 'id' ), get_current_user_id() );
		return rest_ensure_response( [ 'ok' => $ok ] );
	}

	public function regenerate_output( WP_REST_Request $request ) {
		$id  = (int) $request->get_param( 'id' );
		$res = BizCity_TwinChat_Studio::instance()->regenerate( $id, get_current_user_id() );
		if ( is_wp_error( $res ) ) return $res;
		$row = BizCity_TwinChat_Studio::instance()->get_output( (int) $res );
		return rest_ensure_response( [ 'ok' => true, 'output' => self::shape_row( $row ) ] );
	}

	/** Lightweight poll: id, status, title, external_url — works for both job and output ids. */
	public function output_status( WP_REST_Request $request ) {
		$row = BizCity_TwinChat_Studio::instance()->get_output( (int) $request->get_param( 'id' ) );
		if ( ! $row ) return new WP_Error( 'not_found', 'Output not found.', [ 'status' => 404 ] );
		return rest_ensure_response( [
			'id'               => (int) $row->id,
			'status'           => (string) $row->status,
			'title'            => (string) $row->title,
			'external_url'     => (string) $row->external_url,
			'external_post_id' => $row->external_post_id !== null ? (int) $row->external_post_id : null,
			'error_message'    => (string) ( $row->error_message ?? '' ),
		] );
	}

	/* ───────────────────────── Shape helper ─────────────────────────── */

	/**
	 * Shape a pre-shaped job+output object (from Studio::get_output / get_outputs)
	 * into the REST response format the FE expects.
	 */
	private static function shape_row( $row, $include_content = false ) {
		if ( ! $row ) return null;
		$out = [
			'id'               => (int) $row->id,
			'project_id'       => (string) $row->project_id,
			'tool_type'        => (string) $row->tool_type,
			'title'            => (string) $row->title,
			'content_format'   => (string) ( $row->content_format ?? 'json' ),
			'source_count'     => (int) ( $row->source_count ?? 0 ),
			'note_count'       => (int) ( $row->note_count ?? 0 ),
			'external_url'     => (string) ( $row->external_url ?? '' ),
			'external_post_id' => isset( $row->external_post_id ) && $row->external_post_id !== null
				? (int) $row->external_post_id
				: null,
			'status'           => (string) $row->status,
			'error_message'    => (string) ( $row->error_message ?? '' ),
			'created_at'       => (string) $row->created_at,
		];
		if ( $include_content ) {
			$out['content']        = (string) ( $row->content ?? '' );
			$out['input_snapshot'] = (string) ( $row->input_snapshot ?? '' );
		}
		return $out;
	}
}
