<?php
/**
 * Bizcity Twin AI — KG_Admin_Menu
 *
 * Registers the WP admin page that hosts the React SPA for KG-Hub.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge\KG_Hub
 * @since      2026-04-25
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_KG_Admin_Menu {

	const PAGE_SLUG = 'bizcity-kg-hub';

	// 2026-05-06 — Phase 0.21 Wave 3.3: "Phong cấp Guru" subpage under TwinChat menu.
	// Same React bundle, defaultView='gurus' wired via bootstrap.
	const PAGE_SLUG_GURUS = 'bizcity-twinchat-gurus';

	/** Hook → defaultView mapping for bootstrap injection. */
	private $hook_default_view = [];

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register() {
		// Phase G (2026-05-19) — moved from top-level menu to submenu of Twin Chat.
		// Slug `bizcity-kg-hub` is preserved → all deep-links continue to work.
		$root_hook = add_submenu_page(
			'bizcity-twinchat',
			__( 'Knowledge Graph', 'bizcity-knowledge' ),
			__( 'Knowledge Graph', 'bizcity-knowledge' ),
			'manage_options',
			self::PAGE_SLUG,
			[ $this, 'render_page' ]
		);
		// Default view = none (React reads its own state).
		if ( $root_hook ) { $this->hook_default_view[ $root_hook ] = null; }
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	/**
	 * Mount the KG-Hub React bundle as a SUBPAGE of another parent menu,
	 * with a preselected default view. Used by TwinChat "Phong cấp Guru".
	 *
	 * @param string      $parent_slug   e.g. 'bizcity-twinchat'
	 * @param string      $page_slug     e.g. 'bizcity-twinchat-gurus'
	 * @param string      $page_title
	 * @param string      $menu_title
	 * @param string      $capability
	 * @param string|null $default_view  'gurus' | 'graph' | 'queue' | 'sources' | null
	 */
	public function register_subpage( $parent_slug, $page_slug, $page_title, $menu_title, $capability = 'read', $default_view = null ) {
		$hook = add_submenu_page(
			$parent_slug,
			$page_title,
			$menu_title,
			$capability,
			$page_slug,
			[ $this, 'render_page' ]
		);
		if ( $hook ) {
			$this->hook_default_view[ $hook ] = $default_view;
		}
		// enqueue_assets is shared — it inspects $hook to decide.
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	public function enqueue_assets( $hook ) {
		// Load on either the main KG-Hub page OR any registered subpage.
		$is_main_page = strpos( (string) $hook, self::PAGE_SLUG ) !== false;
		$is_subpage   = array_key_exists( $hook, $this->hook_default_view );
		if ( ! $is_main_page && ! $is_subpage ) {
			return;
		}
		$default_view = $is_subpage ? $this->hook_default_view[ $hook ] : null;

		$dist_dir = BIZCITY_KG_HUB_UI_DIR . 'dist/';
		$dist_url = trailingslashit( BIZCITY_KG_HUB_URL ) . 'ui/dist/';
		$manifest_path = $dist_dir . '.vite/manifest.json';

		// Cache-buster: prefer manifest mtime (changes on every `npm run build`),
		// falls back to constant. Used for handles that are NOT hashed by Vite
		// (bootstrap inline + dynamic CSS handles), so editors don't need to
		// hard-refresh after each rebuild.
		$build_ver = file_exists( $manifest_path )
			? (string) filemtime( $manifest_path )
			: BIZCITY_KG_HUB_VERSION;

		// Inject bootstrap data BEFORE the bundle so React can read it.
		$bootstrap = [
			'restNamespace' => BizCity_KG_Rest_Controller::NAMESPACE_V2,
			'restRoot'      => esc_url_raw( rest_url() ),
			'nonce'         => wp_create_nonce( 'wp_rest' ),
			'currentUserId' => get_current_user_id(),
			'blogId'        => get_current_blog_id(),
			'pluginUrl'     => BIZCITY_KG_HUB_URL,
			'buildVersion'  => $build_ver,
			'defaultView'   => $default_view, // 'gurus' | 'graph' | 'queue' | 'sources' | null
		];

		if ( ! file_exists( $manifest_path ) ) {
			// UI not built yet — print fallback instructions.
			wp_register_script( 'bizcity-kg-hub-bootstrap', '', [], $build_ver, true );
			wp_enqueue_script( 'bizcity-kg-hub-bootstrap' );
			wp_add_inline_script( 'bizcity-kg-hub-bootstrap',
				'window.BIZCITY_KG_HUB = ' . wp_json_encode( $bootstrap ) . ';'
			);
			return;
		}

		$manifest = json_decode( file_get_contents( $manifest_path ), true );
		$entry    = $manifest['index.html'] ?? null;
		if ( ! $entry ) {
			return;
		}

		// Inline bootstrap before main script.
		wp_register_script( 'bizcity-kg-hub-bootstrap', '', [], $build_ver, true );
		wp_enqueue_script( 'bizcity-kg-hub-bootstrap' );
		wp_add_inline_script( 'bizcity-kg-hub-bootstrap',
			'window.BIZCITY_KG_HUB = ' . wp_json_encode( $bootstrap ) . ';'
		);

		// CSS — Vite hashes filenames already, but mtime gives us extra safety
		// (and forces a refresh if the same hash is somehow re-served stale).
		$last_css_handle = null;
		$entry_css = (array) ( $entry['css'] ?? [] );
		// [2026-07-11 Johnny Chu] HOTFIX — support standalone CSS entries in manifest (e.g. style.css) when entry['css'] is absent.
		if ( empty( $entry_css ) ) {
			foreach ( (array) $manifest as $manifest_row ) {
				if ( isset( $manifest_row['file'] ) && substr( (string) $manifest_row['file'], -4 ) === '.css' ) {
					$entry_css[] = (string) $manifest_row['file'];
				}
			}
		}
		$entry_css = array_values( array_unique( $entry_css ) );
		foreach ( $entry_css as $css ) {
			$css_file = $dist_dir . $css;
			$css_ver  = file_exists( $css_file ) ? (string) filemtime( $css_file ) : $build_ver;
			$handle   = 'bizcity-kg-hub-css-' . md5( $css );
			wp_enqueue_style( $handle, $dist_url . $css, [], $css_ver );
			$last_css_handle = $handle;
		}
		// Counter-reset: undo Tailwind preflight leakage on the WordPress admin
		// chrome (admin bar, side menu, notices, default lists / images).
		// Tailwind v3 preflight resets `img,svg,...{display:block}` and
		// `ol,ul,menu{list-style:none;margin:0;padding:0}` globally — which
		// breaks WP admin UI when the bundle is loaded on an admin page.
		$reset = '
			#wpadminbar img,#wpadminbar svg,#adminmenuwrap img,#adminmenuwrap svg,'
			. '#wpfooter img,#wpfooter svg,.notice img,.notice svg{display:inline-block;vertical-align:middle}'
			. '#wpadminbar ul,#wpadminbar ol,#wpadminbar menu,#adminmenu ul,#adminmenu ol,#adminmenu menu{list-style:disc;margin:revert;padding:revert}'
			. '#adminmenu ul.wp-submenu,#adminmenu ul#adminmenuwrap,#wpadminbar ul.ab-submenu,#wpadminbar ul#wp-admin-bar-root-default{list-style:none;margin:0;padding:0}';
		if ( $last_css_handle ) {
			wp_add_inline_style( $last_css_handle, $reset );
		}
		// Main JS
		$js_file = $dist_dir . $entry['file'];
		$js_ver  = file_exists( $js_file ) ? (string) filemtime( $js_file ) : $build_ver;
		wp_enqueue_script(
			'bizcity-kg-hub-app',
			$dist_url . $entry['file'],
			[ 'bizcity-kg-hub-bootstrap' ],
			$js_ver,
			true
		);
		// Mark as ES module.
		add_filter( 'script_loader_tag', static function ( $tag, $handle ) {
			if ( $handle === 'bizcity-kg-hub-app' ) {
				return str_replace( ' src=', ' type="module" src=', $tag );
			}
			return $tag;
		}, 10, 2 );
	}

	public function render_page() {
		$ui_built = file_exists( BIZCITY_KG_HUB_UI_DIR . 'dist/.vite/manifest.json' );
		?>
		<div class="wrap" style="margin: 0; padding: 0;">
			<div id="bizcity-kg-hub-root" style="min-height: calc(100vh - 32px);">
				<?php if ( ! $ui_built ) : ?>
				<div style="padding:40px;font-family:system-ui;max-width:760px;">
					<h1 style="font-size:22px;margin-bottom:8px;">🧠 Knowledge Graph Hub</h1>
					<p style="color:#666;">UI chưa được build. Chạy lệnh sau trong terminal:</p>
					<pre style="background:#f4f4f5;padding:14px;border-radius:8px;font-size:13px;overflow:auto;">
cd <?php echo esc_html( BIZCITY_KG_HUB_UI_DIR ); ?>
npm install
npm run build</pre>
					<p style="color:#666;margin-top:16px;">REST endpoints đã sẵn sàng tại:
						<code><?php echo esc_html( rest_url( BizCity_KG_Rest_Controller::NAMESPACE_V2 ) ); ?>/</code>
					</p>
				</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
