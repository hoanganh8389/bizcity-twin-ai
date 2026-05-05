<?php
/**
 * Twin Shell — Primitives (Phase 0.13 W1)
 *
 * Cross-plugin UI primitives that live ONLY in twinshell:
 *   - Notebook Picker (W1)
 *   - Source Panel (W2)
 *   - Learning Monitor (W4)
 *
 * Every plugin in the registry can call:
 *   window.BizcityTwin.pickNotebook({ allowCreate, currentId, title })
 *     → Promise<{ notebook_id, title, is_new }>
 *
 * The same JS bundle works in two modes (auto-detected by `window === window.top`):
 *   - In-shell: rendered inside the iframe, modal mounts to local document.
 *   - Standalone: same — modal mounts to local document.
 * (Parent-layer primitives like the Learning Monitor badge will be added in W4.)
 *
 * REST namespace: bizcity-twin-shell/v1
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Modules\TwinShell
 * @since      0.13.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Twin_Shell_Primitives {

	const NS                = 'bizcity-twin-shell/v1';
	const HANDLE_JS         = 'bizcity-twin-shell-primitives';
	const HANDLE_CSS        = 'bizcity-twin-shell-primitives-css';
	const HANDLE_JS_UPLOAD  = 'bizcity-twin-shell-source-upload';
	const FEATURE_FLAG      = 'bizcity_twinshell_primitives_v1';

	private static $instance = null;
	private $enqueued = false;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Feature flag. Default ON for logged-in admins, OFF otherwise (until W4 stable).
	 */
	public static function is_enabled() {
		$opt = get_option( self::FEATURE_FLAG, null );
		if ( null === $opt ) {
			$opt = current_user_can( 'manage_options' );
		}
		return (bool) apply_filters( 'bizcity_twinshell_primitives_enabled', (bool) $opt );
	}

	/**
	 * Enqueue the primitives bundle. Idempotent — safe to call from multiple
	 * hooks (parent shell, child iframe bridge, standalone page).
	 *
	 * @param array $matched Optional plugin entry from registry (for filter context).
	 */
	public function enqueue( $matched = null ) {
		if ( $this->enqueued ) {
			return;
		}
		if ( ! self::is_enabled() ) {
			return;
		}
		if ( ! apply_filters( 'bizcity_twinshell_primitives_enqueue', true, $matched ) ) {
			return;
		}

		$js_file  = BIZCITY_TWIN_SHELL_DIR . 'assets/primitives/twin-primitives.js';
		$css_file = BIZCITY_TWIN_SHELL_DIR . 'assets/primitives/twin-primitives.css';
		$js_url   = BIZCITY_TWIN_SHELL_URL . 'assets/primitives/twin-primitives.js';
		$css_url  = BIZCITY_TWIN_SHELL_URL . 'assets/primitives/twin-primitives.css';
		$js_ver   = file_exists( $js_file )  ? filemtime( $js_file )  : BIZCITY_TWIN_SHELL_VERSION;
		$css_ver  = file_exists( $css_file ) ? filemtime( $css_file ) : BIZCITY_TWIN_SHELL_VERSION;

		$upload_file = BIZCITY_TWIN_SHELL_DIR . 'assets/primitives/twin-source-upload.js';
		$upload_url  = BIZCITY_TWIN_SHELL_URL . 'assets/primitives/twin-source-upload.js';
		$upload_ver  = file_exists( $upload_file ) ? filemtime( $upload_file ) : BIZCITY_TWIN_SHELL_VERSION;

		wp_register_style( self::HANDLE_CSS, $css_url, [], $css_ver );
		wp_register_script( self::HANDLE_JS, $js_url, [], $js_ver, true );
		wp_register_script( self::HANDLE_JS_UPLOAD, $upload_url, [ self::HANDLE_JS ], $upload_ver, true );

		$cfg = [
			'restRoot' => esc_url_raw( rest_url( self::NS . '/' ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'userId'   => get_current_user_id(),
			'plugin'   => $matched['id'] ?? '',
		];
		wp_add_inline_script(
			self::HANDLE_JS,
			'window.BIZCITY_TWIN_PRIMITIVES_CFG=' . wp_json_encode( $cfg ) . ';',
			'before'
		);

		wp_enqueue_style( self::HANDLE_CSS );
		wp_enqueue_script( self::HANDLE_JS );
		wp_enqueue_script( self::HANDLE_JS_UPLOAD );

		$this->enqueued = true;
	}

	/** Public accessors used by twin-shell-page when rendering inline tags. */
	public static function asset_files() {
		return [
			'css'    => BIZCITY_TWIN_SHELL_DIR . 'assets/primitives/twin-primitives.css',
			'js'     => BIZCITY_TWIN_SHELL_DIR . 'assets/primitives/twin-primitives.js',
			'upload' => BIZCITY_TWIN_SHELL_DIR . 'assets/primitives/twin-source-upload.js',
		];
	}
	public static function asset_urls() {
		return [
			'css'    => BIZCITY_TWIN_SHELL_URL . 'assets/primitives/twin-primitives.css',
			'js'     => BIZCITY_TWIN_SHELL_URL . 'assets/primitives/twin-primitives.js',
			'upload' => BIZCITY_TWIN_SHELL_URL . 'assets/primitives/twin-source-upload.js',
		];
	}

	// ─── REST routes ─────────────────────────────────────────────────────────

	public function register_routes() {
		$perm = static function () { return is_user_logged_in(); };

		register_rest_route( self::NS, '/notebooks', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'rest_list_notebooks' ],
				'permission_callback' => $perm,
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'rest_create_notebook' ],
				'permission_callback' => $perm,
				'args'                => [
					'title' => [ 'type' => 'string', 'required' => false ],
					'host'  => [ 'type' => 'object', 'required' => false ],
				],
			],
		] );

		register_rest_route( self::NS, '/notebooks/(?P<id>\d+)', [
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'rest_delete_notebook' ],
				'permission_callback' => $perm,
			],
		] );

		register_rest_route( self::NS, '/notebooks/(?P<id>\d+)/summary', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'rest_notebook_summary' ],
			'permission_callback' => $perm,
		] );

		register_rest_route( self::NS, '/host/bind-notebook', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'rest_bind_notebook' ],
			'permission_callback' => $perm,
			'args'                => [
				'plugin'      => [ 'type' => 'string',  'required' => true ],
				'entity_type' => [ 'type' => 'string',  'required' => true ],
				'entity_id'   => [ 'type' => 'string',  'required' => true ],
				'notebook_id' => [ 'type' => 'integer', 'required' => true ],
			],
		] );
	}

	// ─── Handlers ────────────────────────────────────────────────────────────

	public function rest_list_notebooks( WP_REST_Request $req ) {
		if ( ! class_exists( 'BizCity_KG_Notebook_Service' ) ) {
			return new WP_Error( 'kg_unavailable', 'KG Hub not loaded', [ 'status' => 500 ] );
		}
		$rows = BizCity_KG_Notebook_Service::instance()->list_for_user( get_current_user_id() );
		// Reduce payload — picker only needs id/name/stats.
		$out = [];
		foreach ( $rows as $r ) {
			$out[] = [
				'id'          => (int) $r['id'],
				'name'        => (string) $r['name'],
				'description' => (string) ( $r['description'] ?? '' ),
				'color'       => (string) ( $r['color'] ?? '' ),
				'stats'       => $r['stats'] ?? new stdClass(),
				'updated_at'  => (string) ( $r['updated_at'] ?? '' ),
			];
		}
		return rest_ensure_response( [ 'notebooks' => $out ] );
	}

	public function rest_create_notebook( WP_REST_Request $req ) {
		if ( ! class_exists( 'BizCity_KG_Notebook_Service' ) ) {
			return new WP_Error( 'kg_unavailable', 'KG Hub not loaded', [ 'status' => 500 ] );
		}
		$data  = $req->get_json_params() ?: $req->get_params();
		$title = trim( (string) ( $data['title'] ?? '' ) );
		if ( '' === $title ) {
			$title = sprintf( 'Untitled · %s', wp_date( 'M j, H:i' ) );
		}

		$nb = BizCity_KG_Notebook_Service::instance()->create(
			[ 'name' => $title ],
			get_current_user_id()
		);
		if ( ! $nb || empty( $nb['id'] ) ) {
			return new WP_Error( 'create_failed', 'Could not create notebook', [ 'status' => 500 ] );
		}

		$bound = null;
		$host  = is_array( $data['host'] ?? null ) ? $data['host'] : null;
		if ( $host && ! empty( $host['plugin'] ) && isset( $host['entity_type'], $host['entity_id'] ) ) {
			$bind_args = [
				'plugin'      => sanitize_key( $host['plugin'] ),
				'entity_type' => sanitize_key( $host['entity_type'] ),
				'entity_id'   => (string) $host['entity_id'],
				'notebook_id' => (int) $nb['id'],
				'user_id'     => get_current_user_id(),
			];
			$ok = self::dispatch_bind( $bind_args );
			if ( ! $ok ) {
				// Bind failed — rollback the notebook immediately to prevent an orphan.
				BizCity_KG_Notebook_Service::instance()->delete( (int) $nb['id'] );
				return new WP_Error(
					'bind_failed',
					sprintf(
						'No bind handler for plugin "%s". Register filter bizcity_twinshell_bind_notebook_%s.',
						$bind_args['plugin'],
						$bind_args['plugin']
					),
					[ 'status' => 422 ]
				);
			}
			$bound = $bind_args;
		}

		return rest_ensure_response( [
			'notebook_id' => (int) $nb['id'],
			'title'       => (string) $nb['name'],
			'is_new'      => true,
			'bound_to'    => $bound,
		] );
	}

	public function rest_delete_notebook( WP_REST_Request $req ) {
		if ( ! class_exists( 'BizCity_KG_Notebook_Service' ) ) {
			return new WP_Error( 'kg_unavailable', 'KG Hub not loaded', [ 'status' => 500 ] );
		}
		$id  = (int) $req['id'];
		$svc = BizCity_KG_Notebook_Service::instance();
		$nb  = $svc->get( $id );
		if ( ! $nb ) {
			return new WP_Error( 'not_found', 'Notebook not found', [ 'status' => 404 ] );
		}
		$owner = (int) ( $nb['owner_id'] ?? $nb['user_id'] ?? 0 );
		if ( $owner !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'forbidden', 'Cannot delete this notebook', [ 'status' => 403 ] );
		}
		$svc->delete( $id );
		return rest_ensure_response( [ 'deleted' => true, 'id' => $id ] );
	}

	public function rest_notebook_summary( WP_REST_Request $req ) {
		if ( ! class_exists( 'BizCity_KG_Notebook_Service' ) ) {
			return new WP_Error( 'kg_unavailable', 'KG Hub not loaded', [ 'status' => 500 ] );
		}
		$id = (int) $req['id'];
		$nb = BizCity_KG_Notebook_Service::instance()->get( $id );
		if ( ! $nb ) {
			return new WP_Error( 'not_found', 'Notebook not found', [ 'status' => 404 ] );
		}
		$stats = is_array( $nb['stats'] ) ? $nb['stats'] : (array) $nb['stats'];
		return rest_ensure_response( [
			'notebook_id'  => $id,
			'title'        => (string) $nb['name'],
			'source_count' => (int) ( $stats['sources'] ?? 0 ),
			'passages'     => (int) ( $stats['passages'] ?? 0 ),
			'entities'     => (int) ( $stats['entities'] ?? 0 ),
			'relations'    => (int) ( $stats['relations'] ?? 0 ),
			'pending_triplets' => (int) ( $stats['pending_triplets'] ?? 0 ),
			'updated_at'   => (string) ( $nb['updated_at'] ?? '' ),
		] );
	}

	public function rest_bind_notebook( WP_REST_Request $req ) {
		$data = $req->get_json_params() ?: $req->get_params();
		$args = [
			'plugin'      => sanitize_key( (string) ( $data['plugin'] ?? '' ) ),
			'entity_type' => sanitize_key( (string) ( $data['entity_type'] ?? '' ) ),
			'entity_id'   => (string) ( $data['entity_id'] ?? '' ),
			'notebook_id' => (int) ( $data['notebook_id'] ?? 0 ),
			'user_id'     => get_current_user_id(),
		];
		if ( '' === $args['plugin'] || '' === $args['entity_type'] || '' === $args['entity_id'] || $args['notebook_id'] <= 0 ) {
			return new WP_Error( 'bad_request', 'plugin, entity_type, entity_id, notebook_id required', [ 'status' => 400 ] );
		}
		$ok = self::dispatch_bind( $args );
		if ( ! $ok ) {
			return new WP_Error(
				'no_handler',
				sprintf( 'No bind handler registered for plugin "%s". Add filter bizcity_twinshell_bind_notebook_%s.', $args['plugin'], $args['plugin'] ),
				[ 'status' => 501 ]
			);
		}
		return rest_ensure_response( [ 'bound' => true, 'binding' => $args ] );
	}

	/**
	 * Dispatch a bind request to the host plugin. Plugin must register a
	 * filter `bizcity_twinshell_bind_notebook_{plugin}` returning true on success.
	 *
	 * Filter signature: ($handled_bool, array $args) → bool
	 */
	public static function dispatch_bind( array $args ) {
		$plugin = (string) ( $args['plugin'] ?? '' );
		if ( '' === $plugin ) return false;
		$hook = 'bizcity_twinshell_bind_notebook_' . $plugin;
		$handled = apply_filters( $hook, false, $args );
		return (bool) $handled;
	}
}
