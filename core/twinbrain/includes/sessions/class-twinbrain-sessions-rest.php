<?php
/**
 * BizCity TwinBrain Sessions REST Controller.
 *
 * [2026-06-03 Johnny Chu] BRAIN-SESSIONS BS-2 — REST surface for the
 * conversation-thread feature. Namespace: bizcity-twinbrain/v1 (R-CH-NS
 * does NOT apply — this is the canonical client namespace, NOT the
 * channel-gateway one).
 *
 * Routes:
 *   GET    /sessions               — list current user's sessions
 *   POST   /sessions               — mint a new session (returns session_id)
 *   GET    /sessions/{id}          — single session detail
 *   PATCH  /sessions/{id}          — { title } rename (auto / user)
 *   POST   /sessions/{id}/archive  — soft-archive
 *
 * Permission: is_user_logged_in. All reads scoped to current user_id; writes
 * verify ownership through the VIEW's user_id column.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Modules\TwinBrain
 * @since      2026-06-03 (Phase BRAIN-SESSIONS BS-2)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( ! trait_exists( 'BizCity_REST_Error' ) ) {
	$__trait = dirname( __DIR__, 2 ) . '/diagnostics/includes/trait-rest-error.php';
	if ( file_exists( $__trait ) ) {
		require_once $__trait;
	}
}

class BizCity_TwinBrain_Sessions_REST {

	use BizCity_REST_Error;

	/** @var self|null */
	private static $instance = null;
	public static function instance(): self {
		if ( null === self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	protected function rest_error_module(): string {
		return 'twinbrain.sessions.rest';
	}

	public function register_routes(): void {
		$ns = defined( 'BIZCITY_TWINBRAIN_REST_NS' ) ? BIZCITY_TWINBRAIN_REST_NS : 'bizcity-twinbrain/v1';

		register_rest_route( $ns, '/sessions', [
			[
				'methods'             => 'GET',
				'permission_callback' => [ $this, 'perm_logged_in' ],
				'args'                => [
					'include_archived' => [ 'type' => 'boolean', 'required' => false, 'default' => false ],
					'limit'            => [ 'type' => 'integer', 'required' => false, 'default' => 50 ],
					'offset'           => [ 'type' => 'integer', 'required' => false, 'default' => 0 ],
				],
				'callback'            => [ $this, 'handle_list' ],
			],
			[
				'methods'             => 'POST',
				'permission_callback' => [ $this, 'perm_logged_in' ],
				'args'                => [
					'title'      => [ 'type' => 'string', 'required' => false ],
					'project_id' => [ 'type' => 'string', 'required' => false ],
					'source'     => [ 'type' => 'string', 'required' => false, 'enum' => [ 'user', 'twin_tool', 'system' ] ],
				],
				'callback'            => [ $this, 'handle_create' ],
			],
		] );

		register_rest_route( $ns, '/sessions/(?P<session_id>brain_sess_[\w]+)', [
			[
				'methods'             => 'GET',
				'permission_callback' => [ $this, 'perm_logged_in' ],
				'callback'            => [ $this, 'handle_get' ],
			],
			[
				'methods'             => 'PATCH',
				'permission_callback' => [ $this, 'perm_logged_in' ],
				'args'                => [
					'title'  => [ 'type' => 'string', 'required' => true ],
					'reason' => [ 'type' => 'string', 'required' => false, 'enum' => [ 'user', 'auto_llm' ] ],
				],
				'callback'            => [ $this, 'handle_rename' ],
			],
		] );

		register_rest_route( $ns, '/sessions/(?P<session_id>brain_sess_[\w]+)/archive', [
			'methods'             => 'POST',
			'permission_callback' => [ $this, 'perm_logged_in' ],
			'args'                => [
				'reason' => [ 'type' => 'string', 'required' => false ],
			],
			'callback'            => [ $this, 'handle_archive' ],
		] );

		// [2026-06-03 Johnny Chu] BRAIN-SESSIONS BS-11 — full-text search across
		// the current user's sessions (title + session_id + message bodies).
		register_rest_route( $ns, '/sessions/search', [
			'methods'             => 'GET',
			'permission_callback' => [ $this, 'perm_logged_in' ],
			'args'                => [
				'q'     => [ 'type' => 'string',  'required' => true ],
				'limit' => [ 'type' => 'integer', 'required' => false, 'default' => 30 ],
			],
			'callback'            => [ $this, 'handle_search' ],
		] );

		// [2026-06-03 Johnny Chu] BRAIN-SESSIONS BS-12 — hydrate the chat panel
		// with prior turns of a session (user_message + assistant_message
		// pairs from event_stream). Used when the user clicks a row in the
		// Sessions tab to resume an existing thread.
		register_rest_route( $ns, '/sessions/(?P<session_id>brain_sess_[\w]+)/turns', [
			'methods'             => 'GET',
			'permission_callback' => [ $this, 'perm_logged_in' ],
			'args'                => [
				'limit' => [ 'type' => 'integer', 'required' => false, 'default' => 200 ],
			],
			'callback'            => [ $this, 'handle_list_turns' ],
		] );
	}

	public function perm_logged_in() {
		return is_user_logged_in();
	}

	// ------------------------------------------------------------------

	public function handle_list( WP_REST_Request $req ) {
		$mgr = BizCity_TwinBrain_Sessions_Manager::instance();
		$res = $mgr->list_for_user( [
			'user_id'          => get_current_user_id(),
			'include_archived' => (bool) $req->get_param( 'include_archived' ),
			'limit'            => (int) ( $req->get_param( 'limit' )  ?: 50 ),
			'offset'           => (int) ( $req->get_param( 'offset' ) ?: 0 ),
		] );
		return rest_ensure_response( [
			'ok'    => true,
			'items' => (array) ( $res['items'] ?? [] ),
			'total' => (int)   ( $res['total'] ?? 0 ),
		] );
	}

	public function handle_create( WP_REST_Request $req ) {
		$mgr = BizCity_TwinBrain_Sessions_Manager::instance();
		$res = $mgr->create( [
			'user_id'    => get_current_user_id(),
			'title'      => (string) $req->get_param( 'title' ),
			'project_id' => (string) $req->get_param( 'project_id' ),
			'source'     => (string) ( $req->get_param( 'source' ) ?: 'user' ),
		] );
		if ( ! empty( $res['error'] ) ) {
			return $this->err(
				'twinbrain_sessions_create_failed',
				(string) ( $res['message'] ?? $res['error'] ),
				400,
				$res
			);
		}
		return rest_ensure_response( array_merge( [ 'ok' => true ], $res ) );
	}

	public function handle_get( WP_REST_Request $req ) {
		$session_id = (string) $req['session_id'];
		$mgr        = BizCity_TwinBrain_Sessions_Manager::instance();
		$row        = $mgr->get( $session_id, get_current_user_id() );
		if ( empty( $row ) ) {
			return $this->err( 'twinbrain_session_not_found', 'Session không tồn tại hoặc không thuộc user hiện tại.', 404, [ 'session_id' => $session_id ] );
		}
		return rest_ensure_response( [ 'ok' => true, 'session' => $row ] );
	}

	public function handle_rename( WP_REST_Request $req ) {
		$session_id = (string) $req['session_id'];
		$mgr        = BizCity_TwinBrain_Sessions_Manager::instance();
		$existing   = $mgr->get( $session_id, get_current_user_id() );
		if ( empty( $existing ) ) {
			return $this->err( 'twinbrain_session_not_found', 'Session không tồn tại hoặc không thuộc user hiện tại.', 404, [ 'session_id' => $session_id ] );
		}
		$res = $mgr->rename(
			$session_id,
			(string) $req->get_param( 'title' ),
			[
				'reason'  => (string) ( $req->get_param( 'reason' ) ?: 'user' ),
				'user_id' => get_current_user_id(),
			]
		);
		if ( empty( $res['ok'] ) ) {
			return $this->err(
				'twinbrain_session_rename_failed',
				(string) ( $res['message'] ?? $res['error'] ?? 'rename_failed' ),
				400,
				$res
			);
		}
		return rest_ensure_response( array_merge( [ 'ok' => true, 'session_id' => $session_id ], $res ) );
	}

	public function handle_archive( WP_REST_Request $req ) {
		$session_id = (string) $req['session_id'];
		$mgr        = BizCity_TwinBrain_Sessions_Manager::instance();
		$existing   = $mgr->get( $session_id, get_current_user_id() );
		if ( empty( $existing ) ) {
			return $this->err( 'twinbrain_session_not_found', 'Session không tồn tại hoặc không thuộc user hiện tại.', 404, [ 'session_id' => $session_id ] );
		}
		$res = $mgr->archive( $session_id, [
			'user_id' => get_current_user_id(),
			'reason'  => (string) $req->get_param( 'reason' ),
		] );
		if ( empty( $res['ok'] ) ) {
			return $this->err(
				'twinbrain_session_archive_failed',
				(string) ( $res['message'] ?? $res['error'] ?? 'archive_failed' ),
				400,
				$res
			);
		}
		return rest_ensure_response( array_merge( [ 'ok' => true, 'session_id' => $session_id ], $res ) );
	}

	/**
	 * [2026-06-03 Johnny Chu] BRAIN-SESSIONS BS-11 — GET /sessions/search?q=...
	 * Returns up to `limit` (≤50) sessions owned by the current user matching
	 * the query against title / session_id / user+assistant message bodies.
	 * Each item carries a centred ~240-char snippet for FE preview.
	 */
	public function handle_search( WP_REST_Request $req ) {
		$mgr = BizCity_TwinBrain_Sessions_Manager::instance();
		$res = $mgr->search( [
			'user_id' => get_current_user_id(),
			'q'       => (string) $req->get_param( 'q' ),
			'limit'   => (int)    ( $req->get_param( 'limit' ) ?: 30 ),
		] );
		return rest_ensure_response( [
			'ok'    => true,
			'q'     => (string) ( $res['q']     ?? '' ),
			'items' => (array)  ( $res['items'] ?? [] ),
			'total' => (int)    ( $res['total'] ?? 0 ),
		] );
	}

	/**
	 * [2026-06-03 Johnny Chu] BRAIN-SESSIONS BS-12 — GET /sessions/{id}/turns
	 * Returns ordered turn pairs (user prompt + assistant answer) reconstructed
	 * from `user_message` + `assistant_message` events in event_stream.
	 * Used by FE to hydrate the chat panel when a session row is clicked.
	 */
	public function handle_list_turns( WP_REST_Request $req ) {
		$session_id = (string) $req['session_id'];
		$mgr        = BizCity_TwinBrain_Sessions_Manager::instance();
		$existing   = $mgr->get( $session_id, get_current_user_id() );
		if ( empty( $existing ) ) {
			return $this->err( 'twinbrain_session_not_found', 'Session không tồn tại hoặc không thuộc user hiện tại.', 404, [ 'session_id' => $session_id ] );
		}
		$res = $mgr->list_turns( $session_id, get_current_user_id(), (int) ( $req->get_param( 'limit' ) ?: 200 ) );
		return rest_ensure_response( [
			'ok'         => true,
			'session_id' => $session_id,
			'items'      => (array) ( $res['items'] ?? [] ),
			'total'      => (int)   ( $res['total'] ?? 0 ),
		] );
	}
}