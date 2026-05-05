<?php
/**
 * Bizcity TwinChat — Public Frontend Page
 *
 * Registers the pretty URL /twinchat/ as a WordPress virtual page
 * that serves the same React workspace bundle as the WP-Admin page.
 *
 * URL:   https://example.com/twinchat/
 * Query: index.php?bizcity_twinchat_page=1
 *
 * Access: requires is_user_logged_in(); redirects to wp-login otherwise.
 *
 * PHP 7.4 compatible.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Modules\TwinChat
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_TwinChat_Public_Page {

	const QUERY_VAR   = 'bizcity_twinchat_page';
	const REWRITE_KEY = '^twinchat(?:/.*)?$';
	const OPTION_KEY  = 'bizcity_twinchat_rewrite_flushed_v1';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register() {
		add_action( 'init',              [ $this, 'add_rewrite_rule' ] );
		add_filter( 'query_vars',        [ $this, 'add_query_var' ] );
		add_action( 'template_redirect', [ $this, 'maybe_render' ] );
		add_action( 'wp_enqueue_scripts',[ $this, 'enqueue_assets' ] );
		add_filter( 'script_loader_tag', [ $this, 'add_module_type' ], 10, 3 );

		// Strip ALL theme/plugin styles & scripts except our own on /twinchat/ page.
		add_action( 'wp_enqueue_scripts', [ $this, 'strip_foreign_assets' ], 9999 );
		// Disable Query Monitor on this page.
		add_filter( 'qm/dispatch/html',  [ $this, 'disable_qm' ] );
		add_filter( 'qm/process',        [ $this, 'disable_qm' ] );
	}

	/** Register rewrite rule + flush once if not yet registered. */
	public function add_rewrite_rule() {
		add_rewrite_rule(
			self::REWRITE_KEY,
			'index.php?' . self::QUERY_VAR . '=1',
			'top'
		);

		// Flush once after the rule is added.
		if ( ! get_option( self::OPTION_KEY ) ) {
			flush_rewrite_rules( false );
			update_option( self::OPTION_KEY, 1 );
		}
	}

	public function add_query_var( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/** Intercept the request and output the full-page app. */
	public function maybe_render() {
		if ( ! get_query_var( self::QUERY_VAR ) ) {
			return;
		}

		// Redirect to login if not logged in.
		if ( ! is_user_logged_in() ) {
			$redirect = home_url( '/twinchat/' );
			wp_safe_redirect( wp_login_url( $redirect ) );
			exit;
		}

		// Phase 0.11 — redirect standalone visits to the unified Twin Shell
		// unless the page is being loaded inside the shell iframe
		// (?bizcity_iframe=1 — legacy convention shared with webchat / intent /
		// twin-core templates) or the caller explicitly opts out (?shell=0).
		$is_embed = ! empty( $_GET['bizcity_iframe'] );
		$opt_out  = isset( $_GET['shell'] )  && '0' === (string) $_GET['shell'];
		if ( ! $is_embed && ! $opt_out ) {
			$shell = home_url( '/twin/?plugin=twinchat' );
			wp_safe_redirect( $shell, 302 );
			exit;
		}

		// Output the full HTML page.
		$this->render_full_page();
		exit;
	}

	/** Enqueue Vite assets — only fires on our virtual page. */
	public function enqueue_assets() {
		if ( ! get_query_var( self::QUERY_VAR ) ) {
			return;
		}

		// Wave 0.18.1.7 — Signal flag so sibling modules (e.g. TwinSearch) can
		// piggy-back the same surface without duplicating page detection.
		if ( ! defined( 'BIZCITY_TWINCHAT_ACTIVE' ) ) {
			define( 'BIZCITY_TWINCHAT_ACTIVE', true );
		}

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

		foreach ( $json as $key => $asset ) {
			if ( ! isset( $asset['file'] ) ) {
				continue;
			}
			$is_entry = ! empty( $asset['isEntry'] );
			$file_url = $dist_url . $asset['file'];

			if ( $is_entry && preg_match( '/\.js$/', $asset['file'] ) ) {
				$entry_js = $file_url;
			}
			if ( ! $is_entry && preg_match( '/\.js$/', $asset['file'] ) ) {
				$chunk_js[] = $file_url;
			}
			if ( ! empty( $asset['css'] ) ) {
				foreach ( $asset['css'] as $css_file ) {
					$entry_css[] = $dist_url . $css_file;
				}
			}
		}

		if ( ! $entry_js ) {
			return;
		}

		// Cache-bust by manifest mtime — bump tự động khi `npm run build`.
		$ver = (string) BIZCITY_TWINCHAT_VERSION;
		if ( file_exists( $manifest ) ) {
			$ver .= '.' . filemtime( $manifest );
		}

		// CSS.
		foreach ( $entry_css as $i => $css_url ) {
			wp_enqueue_style(
				'bizcity-twinchat-pub-' . $i,
				$css_url,
				[],
				$ver
			);
		}

		// Modulepreload chunks.
		foreach ( $chunk_js as $chunk_url ) {
			$url = $chunk_url;
			add_action( 'wp_head', static function () use ( $url ) {
				echo '<link rel="modulepreload" crossorigin href="' . esc_url( $url ) . '" />' . "\n";
			} );
		}

		// Build inline config.
		$user_id = get_current_user_id();
		$nb_id   = 0;
		$nb_name = '';
		$nb_list = [];
		if ( class_exists( 'BizCity_KG_Notebook_Service' ) ) {
			$svc = BizCity_KG_Notebook_Service::instance();
			foreach ( $svc->list_for_user( $user_id, [ 'limit' => 50 ] ) as $row ) {
				$nb_list[] = [ 'id' => (int) $row['id'], 'name' => (string) $row['name'] ];
			}
			if ( ! empty( $nb_list ) ) {
				// 1) Honor ?notebook_id= deeplink first (shell forwards the param).
				$url_nb = isset( $_GET['notebook_id'] ) ? (int) $_GET['notebook_id'] : 0;
				if ( $url_nb > 0 ) {
					foreach ( $nb_list as $row ) {
						if ( $row['id'] === $url_nb ) {
							$nb_id   = $url_nb;
							$nb_name = $row['name'];
							// Persist as sticky so subsequent loads without param keep the choice.
							update_user_meta( $user_id, 'bizcity_twinchat_notebook_id', $nb_id );
							break;
						}
					}
				}
				// 2) Fall back to sticky user meta.
				if ( ! $nb_id ) {
					$meta_nb = (int) get_user_meta( $user_id, 'bizcity_twinchat_notebook_id', true );
					if ( $meta_nb > 0 ) {
						foreach ( $nb_list as $row ) {
							if ( $row['id'] === $meta_nb ) {
								$nb_id   = $meta_nb;
								$nb_name = $row['name'];
								break;
							}
						}
					}
				}
				// 3) Final fallback: first notebook.
				if ( ! $nb_id ) {
					$nb_id   = $nb_list[0]['id'];
					$nb_name = $nb_list[0]['name'];
				}
			}
		}

		$config = (string) wp_json_encode( [
			'restRoot'     => esc_url_raw( rest_url( BIZCITY_TWINCHAT_REST_NS . '/' ) ),
			'kgRoot'       => esc_url_raw( rest_url( 'bizcity-knowledge/v2/' ) ),
			'nonce'        => wp_create_nonce( 'wp_rest' ),
			'userId'       => $user_id,
			'notebookId'   => $nb_id,
			'notebookName' => $nb_name,
			'notebookList' => $nb_list,
			'pluginUrl'    => BIZCITY_TWINCHAT_URL,
			'shellUrl'     => esc_url_raw( home_url( '/twin/' ) ),
			'activityBar'  => self::get_activity_bar(),
			'debug'        => class_exists( 'BizCity_Twin_Debug' ) ? BizCity_Twin_Debug::is_enabled() : false,
		] );

		wp_enqueue_script(
			'bizcity-twinchat-app',
			$entry_js,
			[ 'wp-i18n' ],
			$ver,
			true
		);
		wp_add_inline_script(
			'bizcity-twinchat-app',
			'window.BIZCITY_TWINCHAT = ' . $config . ';',
			'before'
		);
		wp_set_script_translations(
			'bizcity-twinchat-app',
			'bizcity-twin-ai',
			BIZCITY_TWIN_AI_PATH . 'languages'
		);
	}

	public function add_module_type( $tag, $handle, $src ) {
		if ( $handle === 'bizcity-twinchat-app' ) {
			return '<script type="module" src="' . esc_url( $src ) . '"></script>' . "\n";
		}
		return $tag;
	}

	/**
	 * Dequeue everything except our own TwinChat assets.
	 * Runs at priority 9999 on wp_enqueue_scripts.
	 */
	public function strip_foreign_assets() {
		if ( ! get_query_var( self::QUERY_VAR ) ) {
			return;
		}
		global $wp_styles, $wp_scripts;

		$keep_styles  = [];
		$keep_scripts = [ 'bizcity-twinchat-app', 'wp-i18n', 'wp-hooks', 'wp-polyfill' ];

		// Dequeue every style not in our keep list.
		if ( ! empty( $wp_styles->queue ) ) {
			foreach ( $wp_styles->queue as $handle ) {
				if ( ! in_array( $handle, $keep_styles, true ) ) {
					wp_dequeue_style( $handle );
					wp_deregister_style( $handle );
				}
			}
		}

		// Dequeue every script not in our keep list.
		if ( ! empty( $wp_scripts->queue ) ) {
			foreach ( $wp_scripts->queue as $handle ) {
				if ( ! in_array( $handle, $keep_scripts, true ) ) {
					wp_dequeue_script( $handle );
					wp_deregister_script( $handle );
				}
			}
		}

		// Remove WP global styles / inline CSS output.
		remove_action( 'wp_head',   'wp_global_styles_render_svg_filters' );
		wp_dequeue_style( 'global-styles' );
		wp_dequeue_style( 'wp-block-library' );
		wp_dequeue_style( 'classic-theme-styles' );
	}

	/** Return false to disable Query Monitor output on /twinchat/. */
	public function disable_qm( $val ) {
		if ( get_query_var( self::QUERY_VAR ) ) {
			return false;
		}
		return $val;
	}

	/**
	 * Output a minimal HTML5 page — NO theme, NO Query Monitor, NO WP head cruft.
	 * Reads Vite manifest directly so we are NOT affected by WP enqueue queue order
	 * (avoids: strip_foreign_assets wiping CSS, footer-only scripts missed by wp_print_scripts).
	 */
	private function render_full_page() {
		$dist_dir = BIZCITY_TWINCHAT_UI_DIR . 'dist/';
		$dist_url = trailingslashit( BIZCITY_TWINCHAT_URL ) . 'ui/dist/';

		// Load Vite manifest.
		$manifest = $dist_dir . '.vite/manifest.json';
		if ( ! file_exists( $manifest ) ) {
			$manifest = $dist_dir . 'manifest.json';
		}

		$entry_js   = '';
		$entry_css  = [];
		$chunk_urls = [];

		if ( file_exists( $manifest ) ) {
			$json = json_decode( (string) file_get_contents( $manifest ), true );
			if ( is_array( $json ) ) {
				foreach ( $json as $asset ) {
					if ( ! isset( $asset['file'] ) ) {
						continue;
					}
					$is_entry = ! empty( $asset['isEntry'] );
					$file_url = $dist_url . $asset['file'];

					if ( $is_entry && preg_match( '/\.js$/', $asset['file'] ) ) {
						$entry_js = $file_url;
					}
					if ( ! $is_entry && preg_match( '/\.js$/', $asset['file'] ) ) {
						$chunk_urls[] = $file_url;
					}
					if ( ! empty( $asset['css'] ) ) {
						foreach ( $asset['css'] as $css_file ) {
							$entry_css[] = $dist_url . $css_file;
						}
					}
				}
			}
		}

		// Build inline config (same logic as enqueue_assets).
		$user_id = get_current_user_id();
		$nb_id   = 0;
		$nb_name = '';
		$nb_list = [];
		if ( class_exists( 'BizCity_KG_Notebook_Service' ) ) {
			$svc = BizCity_KG_Notebook_Service::instance();
			foreach ( $svc->list_for_user( $user_id, [ 'limit' => 50 ] ) as $row ) {
				$nb_list[] = [ 'id' => (int) $row['id'], 'name' => (string) $row['name'] ];
			}
			if ( ! empty( $nb_list ) ) {
				// 1) Honor ?notebook_id= deeplink first (shell forwards the param).
				$url_nb = isset( $_GET['notebook_id'] ) ? (int) $_GET['notebook_id'] : 0;
				if ( $url_nb > 0 ) {
					foreach ( $nb_list as $row ) {
						if ( $row['id'] === $url_nb ) {
							$nb_id   = $url_nb;
							$nb_name = $row['name'];
							update_user_meta( $user_id, 'bizcity_twinchat_notebook_id', $nb_id );
							break;
						}
					}
				}
				// 2) Fall back to sticky user meta.
				if ( ! $nb_id ) {
					$meta_nb = (int) get_user_meta( $user_id, 'bizcity_twinchat_notebook_id', true );
					if ( $meta_nb > 0 ) {
						foreach ( $nb_list as $row ) {
							if ( $row['id'] === $meta_nb ) {
								$nb_id   = $meta_nb;
								$nb_name = $row['name'];
								break;
							}
						}
					}
				}
				// 3) Final fallback: first notebook.
				if ( ! $nb_id ) {
					$nb_id   = $nb_list[0]['id'];
					$nb_name = $nb_list[0]['name'];
				}
			}
		}

		$config = (string) wp_json_encode( [
			'restRoot'     => esc_url_raw( rest_url( BIZCITY_TWINCHAT_REST_NS . '/' ) ),
			'kgRoot'       => esc_url_raw( rest_url( 'bizcity-knowledge/v2/' ) ),
			'nonce'        => wp_create_nonce( 'wp_rest' ),
			'userId'       => $user_id,
			'notebookId'   => $nb_id,
			'notebookName' => $nb_name,
			'notebookList' => $nb_list,
			'pluginUrl'    => BIZCITY_TWINCHAT_URL,
			'shellUrl'     => esc_url_raw( home_url( '/twin/' ) ),
			'activityBar'  => self::get_activity_bar(),
			'debug'        => class_exists( 'BizCity_Twin_Debug' ) ? BizCity_Twin_Debug::is_enabled() : false,
		] );

		$ver       = BIZCITY_TWINCHAT_VERSION;
		if ( file_exists( $manifest ) ) {
			$ver .= '.' . filemtime( $manifest );
		}
		$site_name = esc_html( get_bloginfo( 'name' ) );
		$lang      = esc_attr( get_bloginfo( 'language' ) );
		$nonce     = esc_attr( wp_create_nonce( 'wp_rest' ) );

		header( 'Content-Type: text/html; charset=utf-8' );
		echo '<!DOCTYPE html>' . "\n";
		echo '<html lang="' . $lang . '">' . "\n";
		echo '<head>' . "\n";
		echo '<meta charset="utf-8">' . "\n";
		echo '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n";
		echo '<title>TwinChat — ' . $site_name . '</title>' . "\n";

		// Modulepreload hints for JS chunks.
		foreach ( $chunk_urls as $chunk ) {
			echo '<link rel="modulepreload" crossorigin href="' . esc_url( $chunk ) . '?ver=' . esc_attr( $ver ) . '">' . "\n";
		}

		// CSS bundle(s) — direct <link> so strip_foreign_assets can never touch them.
		foreach ( $entry_css as $css_url ) {
			echo '<link rel="stylesheet" href="' . esc_url( $css_url ) . '?ver=' . esc_attr( $ver ) . '">' . "\n";
		}

		echo '<style>html,body{margin:0;padding:0;height:100%;overflow:hidden;background:#fff;}#bizcity-twinchat-root{height:100vh;width:100%;}</style>' . "\n";
		echo '</head>' . "\n";
		echo '<body>' . "\n";
		echo '<div id="bizcity-twinchat-root" data-nonce="' . $nonce . '"></div>' . "\n";

		// Inline config — regular (non-module) script runs synchronously BEFORE the ES module.
		// data-cfasync="false" prevents Cloudflare Rocket Loader from deferring it.
		echo '<script data-cfasync="false">window.BIZCITY_TWINCHAT = ' . $config . ';</script>' . "\n";

		// ── i18n bootstrap ─────────────────────────────────────────────
		// /twinchat/ bypasses WP enqueue, so wp-i18n must be inlined here.
		// Load order: wp-hooks → wp-i18n → setLocaleData (if locale JSON exists).
		$hooks_url = includes_url( 'js/dist/hooks.min.js' );
		$i18n_url  = includes_url( 'js/dist/i18n.min.js' );
		echo '<script src="' . esc_url( $hooks_url ) . '?ver=' . esc_attr( $ver ) . '"></script>' . "\n";
		echo '<script src="' . esc_url( $i18n_url ) . '?ver=' . esc_attr( $ver ) . '"></script>' . "\n";

		$locale    = determine_locale();
		$json_file = BIZCITY_TWIN_AI_DIR . 'languages/bizcity-twin-ai-' . $locale . '-' . md5( 'bizcity-twinchat-app' ) . '.json';
		if ( file_exists( $json_file ) ) {
			$json = (string) file_get_contents( $json_file );
			echo '<script data-cfasync="false">(function(){var d=' . $json . ';if(window.wp&&wp.i18n&&d&&d.locale_data&&d.locale_data["bizcity-twin-ai"]){wp.i18n.setLocaleData(d.locale_data["bizcity-twin-ai"],"bizcity-twin-ai");}})();</script>' . "\n";
		}

		if ( $entry_js ) {
			echo '<script type="module" src="' . esc_url( $entry_js ) . '?ver=' . esc_attr( $ver ) . '"></script>' . "\n";
		}

		// ── Twin Shell bridge (URL deep-link sync) ──────────────────────────────
		// render_full_page() exits before wp_enqueue_scripts fires, so the
		// bridge registered by class-twin-shell-bridge.php never gets injected.
		// Inject it manually here when running inside the Twin Shell iframe.
		if ( ! empty( $_GET['bizcity_iframe'] ) && defined( 'BIZCITY_TWIN_SHELL_URL' ) ) {
			$bridge_cfg = (string) wp_json_encode( [
				'pluginId' => 'twinchat',
				'shellUrl' => esc_url_raw( home_url( '/twin/' ) ),
			] );
			$bridge_ver = defined( 'BIZCITY_TWIN_SHELL_VERSION' ) ? BIZCITY_TWIN_SHELL_VERSION : '0.11.0';
			$bridge_js  = BIZCITY_TWIN_SHELL_URL . 'assets/twin-shell-bridge.js?ver=' . rawurlencode( $bridge_ver );
			echo '<script data-cfasync="false">window.BIZCITY_TWIN_SHELL_BRIDGE=' . $bridge_cfg . ';</script>' . "\n";
			echo '<script data-cfasync="false" src="' . esc_url( $bridge_js ) . '"></script>' . "\n";
		}

		// ── TwinSearch headless bundle ──────────────────────────────────────────
		// render_full_page() exits before wp_enqueue_scripts fires, so the
		// TwinSearch asset loader hook never gets called.  Inject directly so
		// the headless dialog controller (window.bizcityTwinSearch.openDialog)
		// is available when the Deep Research button fires.
		if ( class_exists( 'BizCity_TwinSearch_Asset_Loader' ) ) {
			BizCity_TwinSearch_Asset_Loader::inline_for_full_page( $ver );
		}

		echo '</body></html>' . "\n";
	}

	/**
	 * Call once on plugin activation / deactivation to ensure rules are written.
	 * Also resets the flush option so the next `init` will re-flush.
	 */
	public static function on_activate() {
		delete_option( self::OPTION_KEY );
	}

	/**
	 * Build the Activity Bar items (left fixed sidebar, VS Code style).
	 *
	 * Plugins extend by hooking `bizcity_twinchat_activity_bar`:
	 *
	 *   add_filter( 'bizcity_twinchat_activity_bar', function ( $items ) {
	 *       $items[] = [
	 *           'id'      => 'my-tool',
	 *           'label'   => __( 'My Tool', 'my-td' ),
	 *           'icon'    => 'tools',                 // lucide id (see ActivityBar.tsx ICON_MAP)
	 *           // 'emoji'  => '🧠',                    // optional, takes precedence over icon
	 *           'mode'    => 'link',                  // 'home'|'workspace'|'route'|'link'|'embed'
	 *           'target'  => home_url( '/my-tool/' ), // for route/link/embed
	 *           'section' => 'top',                   // 'top'|'bottom'
	 *       ];
	 *       return $items;
	 *   } );
	 */
	public static function get_activity_bar() {
		$items = [
			[ 'id' => 'home',      'label' => __( 'Home',             'bizcity-twin-ai' ), 'icon' => 'home',      'mode' => 'home',      'section' => 'top' ],
			[ 'id' => 'workspace', 'label' => __( 'Workspace',        'bizcity-twin-ai' ), 'icon' => 'workspace', 'mode' => 'workspace', 'section' => 'top' ],
			[ 'id' => 'creator',   'label' => __( 'Plans & Scripts',  'bizcity-twin-ai' ), 'icon' => 'creator',   'mode' => 'embed', 'pluginId' => 'creator',   'section' => 'top' ],
			[ 'id' => 'doc',       'label' => __( 'Documents',        'bizcity-twin-ai' ), 'icon' => 'doc',       'mode' => 'embed', 'pluginId' => 'doc',       'section' => 'top' ],
			[ 'id' => 'image',     'label' => __( 'Product Images',   'bizcity-twin-ai' ), 'icon' => 'image',     'mode' => 'embed', 'pluginId' => 'image',     'section' => 'top' ],
			[ 'id' => 'profile',   'label' => __( 'Portrait Studio',  'bizcity-twin-ai' ), 'icon' => 'image',     'mode' => 'embed', 'pluginId' => 'profile',   'section' => 'top' ],
			[ 'id' => 'canva',     'label' => __( 'Banners & Flyers', 'bizcity-twin-ai' ), 'icon' => 'image',     'mode' => 'embed', 'pluginId' => 'canva',     'section' => 'top' ],
			[ 'id' => 'video',     'label' => __( 'Video',            'bizcity-twin-ai' ), 'icon' => 'video',     'mode' => 'embed', 'pluginId' => 'video',     'section' => 'top' ],
			[ 'id' => 'web',       'label' => __( 'Web Builder',      'bizcity-twin-ai' ), 'icon' => 'web',       'mode' => 'embed', 'pluginId' => 'web',       'section' => 'top' ],
			[ 'id' => 'mindmap',   'label' => __( 'Mindmap',          'bizcity-twin-ai' ), 'icon' => 'notebook',  'mode' => 'embed', 'pluginId' => 'mindmap',   'section' => 'top' ],
			[ 'id' => 'note',      'label' => __( 'Notebook',         'bizcity-twin-ai' ), 'icon' => 'notebook',  'mode' => 'embed', 'pluginId' => 'note',      'section' => 'top' ],
			[ 'id' => 'tasks',     'label' => __( 'Tasks',            'bizcity-twin-ai' ), 'icon' => 'tools',     'mode' => 'embed', 'pluginId' => 'tasks',     'section' => 'bottom' ],
			[ 'id' => 'sessions',  'label' => __( 'Chat Sessions',    'bizcity-twin-ai' ), 'icon' => 'tools',     'mode' => 'embed', 'pluginId' => 'sessions',  'section' => 'bottom' ],
			[ 'id' => 'scheduler', 'label' => __( 'Reminders',        'bizcity-twin-ai' ), 'icon' => 'scheduler', 'mode' => 'embed', 'pluginId' => 'scheduler', 'section' => 'bottom' ],
			[ 'id' => 'tools',     'label' => __( 'Tools',            'bizcity-twin-ai' ), 'icon' => 'tools',     'mode' => 'embed', 'pluginId' => 'tools',     'section' => 'bottom' ],
			[ 'id' => 'skills',    'label' => __( 'Skills',           'bizcity-twin-ai' ), 'icon' => 'skills',    'mode' => 'embed', 'pluginId' => 'skills',    'section' => 'bottom' ],
			[ 'id' => 'explore',   'label' => __( 'Marketplace',      'bizcity-twin-ai' ), 'icon' => 'explore',   'mode' => 'link',  'target'   => admin_url( 'admin.php?page=bizcity-marketplace' ), 'section' => 'bottom' ],
		];
		$items = apply_filters( 'bizcity_twinchat_activity_bar', $items );
		// Sanitize.
		$out = [];
		foreach ( (array) $items as $it ) {
			if ( empty( $it['id'] ) || empty( $it['mode'] ) ) {
				continue;
			}
			$out[] = [
				'id'      => sanitize_key( $it['id'] ),
				'label'   => isset( $it['label'] )   ? (string) $it['label'] : '',
				'icon'    => isset( $it['icon'] )    ? (string) $it['icon']  : '',
				'emoji'   => isset( $it['emoji'] )   ? (string) $it['emoji'] : '',
				'mode'    => (string) $it['mode'],
				'target'  => isset( $it['target'] )  ? (string) $it['target']  : '',
				'section' => ( isset( $it['section'] ) && $it['section'] === 'bottom' ) ? 'bottom' : 'top',
			];
		}
		return $out;
	}
}
