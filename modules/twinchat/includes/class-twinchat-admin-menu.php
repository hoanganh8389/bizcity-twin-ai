<?php
/**
 * Bizcity Twin AI — TwinChat Admin Menu
 *
 * Registers the admin page that hosts the React workspace bundle.
 * Tries Vite-built `ui/dist/` assets, falls back to a placeholder div.
 *
 * PHP 7.4 compatible.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Modules\TwinChat
 * @since 2026-05-01
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_TwinChat_Admin_Menu {

	const PAGE_SLUG = 'bizcity-twinchat';

	private static $instance = null;

	/** Populated by enqueue_assets() so render_page() does not re-query. */
	private $resolved_nb_id   = 0;
	private $resolved_nb_name = '';
	private $resolved_nb_list = [];
	private $bundle_built     = false;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register() {
		// 2026-05-06 — TwinChat is now the default dashboard (replaces WebChat dashboard).
		// Position 2 = top of admin sidebar, capability 'read' = visible to end users.
		add_menu_page(
			'Twin AI',
			'Twin',
			'read',
			self::PAGE_SLUG,
			[ $this, 'render_page' ],
			'dashicons-format-chat',
			2
		);

		// 2026-05-06 — FIX: ensure parent's clickable URL = admin.php?page=bizcity-twinchat.
		// WordPress auto-promotes the FIRST submenu's URL to be the parent's <a href> when
		// the parent slug is not itself in the submenu list. Without this, the parent link
		// inherited the slug of whichever submodule registered first.
		add_submenu_page(
			self::PAGE_SLUG,
			'Twin chat',
			'Twin chat',
			'read',
			self::PAGE_SLUG,
			[ $this, 'render_page' ]
		);

		// Enqueue assets only on our admin page.
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		// Make the main entry script a JS module (Vite output requires type="module").
		add_filter( 'script_loader_tag', [ $this, 'add_module_type' ], 10, 3 );
	}

	/**
	 * Build the activity bar item list from BizCity_Twin_Shell_Registry — the
	 * same data source that powers /twin/ — so both surfaces are always in sync.
	 *
	 * Converts registry schema (public_slug / target_url) → ActivityBar schema
	 * (pluginId / target) expected by ActivityBar.tsx.
	 *
	 * @return array
	 */
	public static function build_activity_bar(): array {
		// Primary: use the Twin Shell registry (same source as /twin/).
		if ( class_exists( 'BizCity_Twin_Shell_Registry' ) ) {
			$plugins = BizCity_Twin_Shell_Registry::instance()->all();
			if ( ! empty( $plugins ) ) {
				$out = [];
				foreach ( $plugins as $p ) {
					// Skip items the current user cannot access.
					if ( ! empty( $p['capability'] ) && ! current_user_can( $p['capability'] ) ) {
						continue;
					}
					$mode = (string) $p['mode'];
					// For 'embed' items the shell navigates via ?plugin=<id>.
					// For 'link' items, target_url is the destination.
					$target    = ( $mode === 'link' ) ? (string) $p['target_url'] : '';
					$plugin_id = ( $mode === 'embed' || $mode === 'home' || $mode === 'workspace' ) ? (string) $p['id'] : '';
					$out[] = [
						'id'       => (string) $p['id'],
						'label'    => (string) $p['label'],
						'icon'     => (string) $p['icon'],
					'emoji'    => '', // Strip emoji — TwinChat uses lucide icons (monochrome), not colored emoji.
						'mode'     => $mode,
						'target'   => $target,
						'pluginId' => $plugin_id,
						'section'  => ( isset( $p['section'] ) && $p['section'] === 'bottom' ) ? 'bottom' : 'top',
					];
				}
				if ( ! empty( $out ) ) {
					return $out;
				}
			}
		}

		// Fallback (registry not loaded yet) — inline minimal list.
		$td   = 'bizcity-twin-ai';
		$items = [
			[ 'id' => 'home',         'label' => __( 'Home',             $td ), 'icon' => 'home',       'emoji' => '', 'mode' => 'home',  'target' => '',                                                                          'pluginId' => 'home',         'section' => 'top' ],
			[ 'id' => 'creator',      'label' => __( 'Plans & Scripts',  $td ), 'icon' => 'creator',    'emoji' => '', 'mode' => 'embed', 'target' => '',                                                                          'pluginId' => 'creator',      'section' => 'top' ],
			[ 'id' => 'doc',          'label' => __( 'Documents',        $td ), 'icon' => 'doc',        'emoji' => '', 'mode' => 'embed', 'target' => '',                                                                          'pluginId' => 'doc',          'section' => 'top' ],
			[ 'id' => 'crm',          'label' => __( 'CRM Inbox',        $td ), 'icon' => 'gateway',    'emoji' => '', 'mode' => 'embed', 'target' => '',                                                                          'pluginId' => 'crm',          'section' => 'top' ],
			[ 'id' => 'image',        'label' => __( 'Product Images',   $td ), 'icon' => 'image',      'emoji' => '', 'mode' => 'embed', 'target' => '',                                                                          'pluginId' => 'image',        'section' => 'top' ],
			[ 'id' => 'video',        'label' => __( 'Video',            $td ), 'icon' => 'video',      'emoji' => '', 'mode' => 'embed', 'target' => '',                                                                          'pluginId' => 'video',        'section' => 'top' ],
			[ 'id' => 'web',          'label' => __( 'Web Builder',      $td ), 'icon' => 'web',        'emoji' => '', 'mode' => 'embed', 'target' => '',                                                                          'pluginId' => 'web',          'section' => 'top' ],
			[ 'id' => 'twin-builder', 'label' => __( 'TwinBuilder',      $td ), 'icon' => 'brain',      'emoji' => '', 'mode' => 'link',  'target' => admin_url( 'admin.php?page=bizcity-twin-builder' ),                         'pluginId' => '',             'section' => 'top' ],
			[ 'id' => 'account',      'label' => __( 'Account',          $td ), 'icon' => 'wallet',     'emoji' => '', 'mode' => 'link',  'target' => 'https://bizcity.vn/my-account/',                                           'pluginId' => '',             'section' => 'bottom' ],
			[ 'id' => 'scheduler',    'label' => __( 'Reminders',        $td ), 'icon' => 'scheduler',  'emoji' => '', 'mode' => 'embed', 'target' => '',                                                                          'pluginId' => 'scheduler',    'section' => 'bottom' ],
			[ 'id' => 'workflow',     'label' => __( 'Automation',       $td ), 'icon' => 'automation', 'emoji' => '', 'mode' => 'link',  'target' => admin_url( 'admin.php?page=bizcity-workspace&tab=workflow' ),                 'pluginId' => '',             'section' => 'bottom' ],
			[ 'id' => 'tools',        'label' => __( 'Tools',            $td ), 'icon' => 'tools',      'emoji' => '', 'mode' => 'embed', 'target' => '',                                                                          'pluginId' => 'tools',        'section' => 'bottom' ],
			[ 'id' => 'skills',       'label' => __( 'Skills',           $td ), 'icon' => 'skills',     'emoji' => '', 'mode' => 'embed', 'target' => '',                                                                          'pluginId' => 'skills',       'section' => 'bottom' ],
			[ 'id' => 'explore',      'label' => __( 'Marketplace',      $td ), 'icon' => 'explore',    'emoji' => '', 'mode' => 'link',  'target' => admin_url( 'admin.php?page=bizcity-marketplace' ),                           'pluginId' => '',             'section' => 'bottom' ],
		];
		return $items;
	}

	/**
	 * Enqueue Vite-built CSS & JS for the TwinChat admin page.
	 * Runs on admin_enqueue_scripts — assets end up in <head> / footer properly.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( $hook !== 'toplevel_page_' . self::PAGE_SLUG ) {
			return;
		}

		// 2026-05-13 — Unify with /twin/: this admin page is now a thin iframe
		// wrapper around /twin/?plugin=twinchat, so the React/Vite bundle no
		// longer needs to be loaded inside the WP admin shell. The TwinShell
		// activity bar (dark, single source of truth) is used instead.
		$this->bundle_built = true;
		return;

		$dist_dir = BIZCITY_TWINCHAT_UI_DIR . 'dist/';
		$dist_url = trailingslashit( BIZCITY_TWINCHAT_URL ) . 'ui/dist/';
		$manifest = $dist_dir . '.vite/manifest.json';
		if ( ! file_exists( $manifest ) ) {
			$manifest = $dist_dir . 'manifest.json';
		}
		if ( ! file_exists( $manifest ) ) {
			return;
		}

		$json = json_decode( (string) file_get_contents( $manifest ), true );
		if ( ! is_array( $json ) ) {
			return;
		}

		$entry_js  = '';
		$chunk_js  = [];
		$entry_css = [];
		foreach ( $json as $entry ) {
			if ( isset( $entry['file'] ) && substr( (string) $entry['file'], -3 ) === '.js' ) {
				if ( ! empty( $entry['isEntry'] ) ) {
					$entry_js = $dist_url . $entry['file'];
				} else {
					$chunk_js[] = $dist_url . $entry['file'];
				}
			}
			if ( isset( $entry['css'] ) && is_array( $entry['css'] ) ) {
				foreach ( $entry['css'] as $css_file ) {
					$entry_css[] = $dist_url . $css_file;
				}
			}
		}

		if ( ! $entry_js ) {
			return;
		}

		// ── Cache-bust by manifest mtime — bump tự động khi `npm run build`. ───
		$ver = (string) BIZCITY_TWINCHAT_VERSION;
		if ( file_exists( $manifest ) ) {
			$ver .= '.' . filemtime( $manifest );
		}

		// ── CSS in <head> via wp_enqueue_style ──────────────────────────────────
		$last_css_handle = '';
		foreach ( $entry_css as $i => $css_url ) {
			$last_css_handle = 'bizcity-twinchat-' . $i;
			wp_enqueue_style(
				$last_css_handle,
				$css_url,
				[],
				$ver
			);
		}

		// Override Tailwind preflight leak: force img/svg/etc back to inline so
		// WP admin chrome (toolbar icons, plugin lists, notices) is not broken
		// on TwinChat admin pages. Scoped only to this hook to avoid conflicts.
		if ( $last_css_handle ) {
			$inline_reset = 'img,svg,video,canvas,audio,iframe,embed,object{display:inline-block !important;vertical-align:middle}';
			wp_add_inline_style( $last_css_handle, $inline_reset );
		}

		// ── modulepreload <link> tags in <head> — only on this page ────────────
		// Emitting preloads on every admin page causes "preloaded but not used"
		// warnings (×52 chunks × every page visit). Guard is already applied by
		// the early-return above, but add_action fires *after* enqueue_scripts so
		// we confirm we are still on the right hook before printing.
		foreach ( $chunk_js as $chunk_url ) {
			// Capture by value so the closure carries the correct URL.
			$url = $chunk_url;
			add_action(
				'admin_head',
				static function () use ( $url ) {
					echo '<link rel="modulepreload" crossorigin href="' . esc_url( $url ) . '" />' . "\n";
				}
			);
		}

		// ── Resolve notebook & build inline config ───────────────────────────────
		$user_id = get_current_user_id();
		$nb_id   = $this->resolve_notebook_id( $user_id );
		$nb_name = '';
		$nb_list = [];
		if ( class_exists( 'BizCity_KG_Notebook_Service' ) ) {
			$svc = BizCity_KG_Notebook_Service::instance();
			if ( $nb_id ) {
				$nb = $svc->get( $nb_id );
				if ( $nb && isset( $nb['name'] ) ) {
					$nb_name = (string) $nb['name'];
				}
			}
			foreach ( $svc->list_for_user( $user_id, [ 'limit' => 50 ] ) as $row ) {
				$nb_list[] = [ 'id' => (int) $row['id'], 'name' => (string) $row['name'] ];
			}
		}

		$config = (string) wp_json_encode( [
			'restRoot'     => esc_url_raw( rest_url( BIZCITY_TWINCHAT_REST_NS . '/' ) ),
			'kgRoot'       => esc_url_raw( rest_url( 'bizcity-knowledge/v2/' ) ),
			'nonce'        => wp_create_nonce( 'wp_rest' ),
			'userId'       => $user_id,
			// Fallback: if resolve_notebook_id returned 0 but we have notebooks in the list,
			// use the first one so the frontend always gets a valid notebookId.
			'notebookId'   => $nb_id ? $nb_id : ( ! empty( $nb_list ) ? $nb_list[0]['id'] : 0 ),
			'notebookName' => $nb_name,
			'notebookList' => $nb_list,
			'pluginUrl'    => BIZCITY_TWINCHAT_URL,
			'shellUrl'     => esc_url_raw( class_exists( 'BizCity_Twin_Shell_Page' ) ? BizCity_Twin_Shell_Page::shell_url() : home_url( '/twin/' ) ),
			// Activity bar — same items as /twin/ so both surfaces look identical.
			'activityBar'  => self::build_activity_bar(),
			// Twin Debug bridge — when ON, FE prints BE traces to console and
			// turns on its own per-stage tracing. Driven by the same gate as
			// `BizCity_Twin_Debug::is_enabled()` (constant / option / ?twin_debug=1).
			'debug'        => class_exists( 'BizCity_Twin_Debug' ) ? BizCity_Twin_Debug::is_enabled() : false,
			// ── Billing / PayPal (shared with webchat) ────────────────────────────
			'walletRestUrl'  => esc_url_raw( rest_url( 'bizcity/v1/' ) ),
			'paypalClientId' => (string) get_site_option( 'bizcity_paypal_client_id', '' ),
			'fxUsdVnd'       => (int) get_site_option( 'bizcity_wallet_fx_usd_vnd', 25000 ),
			// R-1API — Webchat AJAX (admin-ajax) bridge for ApiKey/Usage workspaces.
			// Shares the `bizcity_llm_*` actions registered by webchat module.
			'ajaxUrl'        => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
			'webchatNonce'   => wp_create_nonce( 'bizcity_webchat' ),
			// Admin URL used by FilesWorkspace and other deep-link helpers.
			'adminUrl'       => esc_url_raw( admin_url( 'admin.php' ) ),
			'siteUrl'        => esc_url_raw( home_url( '/' ) ),
			// Wave D — BizDesign embed integration (resolved by helper for consistency).
			'bzdesignEmbedUrl' => class_exists( 'BizCity_TwinChat_Public_Page' )
				? BizCity_TwinChat_Public_Page::resolve_bzdesign_embed_url()
				: ( defined( 'BZDESIGN_URL' ) ? esc_url_raw( BZDESIGN_URL . 'assets/dist/design-embed.js' ) : '' ),
			'bzdesignRestUrl'  => esc_url_raw( rest_url( 'bzdesign/v1' ) ),
		] );

		// ── Main entry script in footer (React needs the DOM node first) ─────────
		wp_enqueue_script(
			'bizcity-twinchat-app',
			$entry_js,
			[ 'wp-i18n' ], // wp.i18n must be on window before our bundle boots.
			$ver,
			true   // in_footer = true
		);
		// Inline config runs BEFORE the module so window.BIZCITY_TWINCHAT is ready.
		wp_add_inline_script(
			'bizcity-twinchat-app',
			'window.BIZCITY_TWINCHAT = ' . $config . ';',
			'before'
		);
		// Load translations from /languages/. JSON files generated via `wp i18n make-json`
		// are named bizcity-twin-ai-{locale}-{md5(handle)}.json.
		wp_set_script_translations(
			'bizcity-twinchat-app',
			'bizcity-twin-ai',
			BIZCITY_TWIN_AI_DIR . 'languages'
		);

		// Cache resolved data for render_page().
		$this->resolved_nb_id   = $nb_id;
		$this->resolved_nb_name = $nb_name;
		$this->resolved_nb_list = $nb_list;
		$this->bundle_built     = true;
	}

	/**
	 * Add type="module" to the TwinChat entry script tag.
	 * Vite ESM output requires this attribute.
	 *
	 * @param string $tag    Full <script ...> HTML tag.
	 * @param string $handle Script handle.
	 * @param string $src    Script URL.
	 * @return string
	 */
	public function add_module_type( $tag, $handle, $src ) {
		if ( $handle !== 'bizcity-twinchat-app' ) {
			return $tag;
		}
		// Replace ONLY the <script src=...></script> opening for our handle, keep
		// the surrounding "before" / "after" inline scripts (window.BIZCITY_TWINCHAT, i18n)
		// that WP injects in the same $tag string.
		// Pattern: match the bare <script ...src="...bizcity-twinchat-app..." ...></script>.
		$module_tag = '<script type="module" src="' . esc_url( $src ) . '" id="bizcity-twinchat-app-js"></script>' . "\n";
		// Replace the WP-generated tag for this handle.
		$pattern = '#<script[^>]+id=["\']bizcity-twinchat-app-js["\'][^>]*></script>#i';
		if ( preg_match( $pattern, $tag ) ) {
			return preg_replace( $pattern, $module_tag, $tag );
		}
		// Fallback: simpler pattern by src match.
		$src_quoted = preg_quote( $src, '#' );
		$pattern2 = '#<script[^>]+src=["\']' . $src_quoted . '["\'][^>]*></script>#i';
		if ( preg_match( $pattern2, $tag ) ) {
			return preg_replace( $pattern2, $module_tag, $tag );
		}
		return $tag;
	}

	/**
	 * Resolve which notebook the workspace should open with.
	 *
	 * Resolution order (no manual setup required):
	 *   1. ?notebook_id=N in the URL (must be owned by the user) — lets the UI switch context.
	 *   2. User meta `bizcity_twinchat_notebook_id` (sticky last-used).
	 *   3. Most recently updated notebook owned by the user.
	 *   4. Auto-create a default "TwinChat" notebook on first run.
	 *
	 * The resolved id is persisted back to user meta so subsequent visits open the same notebook.
	 *
	 * @param int $user_id
	 * @return int Notebook id (0 if KG-Hub not loaded — caller should render a notice).
	 */
	private function resolve_notebook_id( $user_id ) {
		if ( ! class_exists( 'BizCity_KG_Notebook_Service' ) ) {
			return 0;
		}
		$svc       = BizCity_KG_Notebook_Service::instance();
		$meta_key  = 'bizcity_twinchat_notebook_id';
		$resolved  = 0;

		// 1) URL override.
		if ( isset( $_GET['notebook_id'] ) ) {
			$candidate = (int) $_GET['notebook_id'];
			if ( $candidate > 0 ) {
				$nb = $svc->get( $candidate );
				if ( $nb && (int) $nb['owner_id'] === (int) $user_id ) {
					$resolved = $candidate;
				}
			}
		}

		// 2) Sticky user meta.
		if ( ! $resolved ) {
			$candidate = (int) get_user_meta( $user_id, $meta_key, true );
			if ( $candidate > 0 ) {
				$nb = $svc->get( $candidate );
				if ( $nb && (int) $nb['owner_id'] === (int) $user_id ) {
					$resolved = $candidate;
				}
			}
		}

		// 3) Most recently updated notebook owned by user.
		if ( ! $resolved ) {
			$list = $svc->list_for_user( $user_id, [ 'limit' => 1 ] );
			if ( ! empty( $list ) && isset( $list[0]['id'] ) ) {
				$resolved = (int) $list[0]['id'];
			}
		}

		// 4) Auto-create a default notebook on first run.
		if ( ! $resolved ) {
			$user = get_user_by( 'id', $user_id );
			$name = $user ? sprintf( '%s\'s TwinChat', $user->display_name ) : 'TwinChat Notebook';
			$nb   = $svc->create(
				[
					'name'        => $name,
					'description' => 'Auto-created on first TwinChat workspace visit.',
				],
				$user_id
			);
			if ( $nb && isset( $nb['id'] ) ) {
				$resolved = (int) $nb['id'];
			}
		}

		// Persist (sticky).
		if ( $resolved && (int) get_user_meta( $user_id, $meta_key, true ) !== $resolved ) {
			update_user_meta( $user_id, $meta_key, $resolved );
		}

		/**
		 * Filter the notebook id resolved for the TwinChat workspace.
		 *
		 * @param int $resolved
		 * @param int $user_id
		 */
		return (int) apply_filters( 'bizcity_twinchat_resolved_notebook_id', $resolved, $user_id );
	}

	public function render_page() {
		// 2026-05-13 — UNIFY: render the same TwinShell at /twin/?plugin=twinchat
		// inside an iframe so the WP admin page uses the EXACT same activity bar
		// (dark themed, React-driven, single source of truth) as /twin/. No more
		// duplicate ActivityBar.tsx instance on this surface.

		$initial_plugin = isset( $_GET['plugin'] ) ? sanitize_key( wp_unslash( $_GET['plugin'] ) ) : 'twinchat';
		$shell_url      = class_exists( 'BizCity_Twin_Shell_Page' )
			? BizCity_Twin_Shell_Page::shell_url()
			: home_url( '/twin/' );
		$shell_url      = add_query_arg(
			[
				'plugin'         => $initial_plugin,
				'bizcity_iframe' => '1',
			],
			$shell_url
		);

		// Forward useful query args (notebook_id, session, thread, tab, ...) so
		// deep-links into the admin URL still hit the right plugin context.
		// Union of params declared across registered shell plugins
		// (twinchat / crm / doc / brain / studio / ...). Keep this list in sync
		// with `modules/twinshell/includes/default-plugins.php`.
		$forward = [
			'notebook_id', 'notebook', 'session', 'session_id', 'thread', 'tab',
			'id', 'task_id', 'inbox', 'contact_id', 'doc', 'instance_id',
		];
		foreach ( $forward as $k ) {
			if ( isset( $_GET[ $k ] ) && $_GET[ $k ] !== '' ) {
				$shell_url = add_query_arg( $k, sanitize_text_field( wp_unslash( $_GET[ $k ] ) ), $shell_url );
			}
		}

		// Forward _iurl (deeplink path) with strict validation:
		// must be a same-origin relative path so we never create an open redirect.
		if ( isset( $_GET['_iurl'] ) && $_GET['_iurl'] !== '' ) {
			$iurl_raw = wp_unslash( $_GET['_iurl'] );
			// Accept only paths starting with '/' and containing no protocol (no '://').
			if (
				substr( $iurl_raw, 0, 1 ) === '/' &&
				strpos( $iurl_raw, '//' ) !== 0 &&
				strpos( $iurl_raw, '://' ) === false
			) {
				$shell_url = add_query_arg( '_iurl', sanitize_text_field( $iurl_raw ), $shell_url );
			}
		}

		// Full-bleed: hide WP admin chrome padding for this page only.
		echo '<style>
			#wpcontent, #wpbody-content { padding-left: 0 !important; }
			#wpbody-content { padding-bottom: 0 !important; margin: 0 !important; }
			.wrap { margin: 0 !important; }
			html.wp-toolbar { padding-top: 32px; }
			#bizcity-twinchat-shell-frame { display:block; width:100%; height:calc(100vh - 32px); border:0; background:#0f1115; }
			@media screen and (max-width: 782px) { html.wp-toolbar { padding-top: 46px; } #bizcity-twinchat-shell-frame { height:calc(100vh - 46px); } }
		</style>';

		echo '<div class="wrap" style="margin:0;padding:0;">';
		echo '<iframe id="bizcity-twinchat-shell-frame" src="' . esc_url( $shell_url ) . '" title="Twin AI" allow="clipboard-read; clipboard-write; microphone; camera; fullscreen"></iframe>';
		echo '</div>';

		// ── Deep-link sync ─────────────────────────────────────────────
		// /twin/ shell (loaded inside #bizcity-twinchat-shell-frame) posts
		// `{source:'bizcity-twin-shell',type:'url-change',pluginId,params}`
		// every time the user navigates. Mirror those params onto THIS
		// admin page URL so the address bar reflects the current deep-link
		// (e.g. ?page=bizcity-twinchat&plugin=crm&tab=inbox). Reloading the
		// admin page then forwards the same params back into the iframe via
		// the `$forward` whitelist above.
		$origin = wp_parse_url( $shell_url, PHP_URL_SCHEME ) . '://' . wp_parse_url( $shell_url, PHP_URL_HOST );
		?>
		<script>
		( function () {
			var EXPECTED_ORIGIN = <?php echo wp_json_encode( $origin ); ?>;
			var FORWARD_KEYS    = <?php echo wp_json_encode( $forward ); ?>;
			console.log( '[twinchat-admin][deeplink-sync] listener armed', { origin: EXPECTED_ORIGIN, forward: FORWARD_KEYS } );
			window.addEventListener( 'message', function ( ev ) {
				if ( ! ev || ev.origin !== EXPECTED_ORIGIN ) { return; }
				var d = ev.data;
				if ( ! d || d.source !== 'bizcity-twin-shell' || d.type !== 'url-change' ) { return; }
				try {
					var url = new URL( window.location.href );
					url.searchParams.set( 'page', 'bizcity-twinchat' );
					if ( d.pluginId ) { url.searchParams.set( 'plugin', String( d.pluginId ) ); }
					// Wipe stale forwarded keys, then re-apply from current params.
					FORWARD_KEYS.forEach( function ( k ) { url.searchParams.delete( k ); } );
					var p = d.params || {};
					FORWARD_KEYS.forEach( function ( k ) {
						if ( Object.prototype.hasOwnProperty.call( p, k ) && p[ k ] !== '' && p[ k ] !== null && p[ k ] !== undefined ) {
							url.searchParams.set( k, String( p[ k ] ) );
						}
					} );
					// _iurl: deeplink path stored by shell (not in d.params — arrives in d.iurl).
					// Always clear stale value, then re-apply with security validation.
					url.searchParams.delete( '_iurl' );
					if ( typeof d.iurl === 'string' && d.iurl.charAt( 0 ) === '/' &&
					     d.iurl.indexOf( '//' ) !== 0 && d.iurl.indexOf( '://' ) === -1 ) {
						url.searchParams.set( '_iurl', d.iurl );
					}
					var nextUrl = url.pathname + url.search;
					console.log( '[twinchat-admin][deeplink-sync] <- shell', { pluginId: d.pluginId, params: p, iurl: d.iurl || '', nextUrl: nextUrl } );
					window.history.replaceState( null, '', nextUrl );
				} catch ( e ) { console.warn( '[twinchat-admin][deeplink-sync] err', e ); }
			}, false );
		} )();
		</script>
		<?php
	}

}
