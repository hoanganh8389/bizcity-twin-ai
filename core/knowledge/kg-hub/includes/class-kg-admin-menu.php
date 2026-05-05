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

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register() {
		add_menu_page(
			__( 'Knowledge Graph', 'bizcity-knowledge' ),
			__( 'Knowledge Graph', 'bizcity-knowledge' ),
			'manage_options',
			self::PAGE_SLUG,
			[ $this, 'render_page' ],
			'dashicons-share-alt2',
			31
		);
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	public function enqueue_assets( $hook ) {
		// Only load on our page.
		if ( strpos( (string) $hook, self::PAGE_SLUG ) === false ) {
			return;
		}

		$dist_dir = BIZCITY_KG_HUB_UI_DIR . 'dist/';
		$dist_url = trailingslashit( BIZCITY_KG_HUB_URL ) . 'ui/dist/';
		$manifest_path = $dist_dir . '.vite/manifest.json';

		// Inject bootstrap data BEFORE the bundle so React can read it.
		$bootstrap = [
			'restNamespace' => BizCity_KG_Rest_Controller::NAMESPACE_V2,
			'restRoot'      => esc_url_raw( rest_url() ),
			'nonce'         => wp_create_nonce( 'wp_rest' ),
			'currentUserId' => get_current_user_id(),
			'blogId'        => get_current_blog_id(),
			'pluginUrl'     => BIZCITY_KG_HUB_URL,
		];

		if ( ! file_exists( $manifest_path ) ) {
			// UI not built yet — print fallback instructions.
			wp_register_script( 'bizcity-kg-hub-bootstrap', '', [], BIZCITY_KG_HUB_VERSION, true );
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
		wp_register_script( 'bizcity-kg-hub-bootstrap', '', [], BIZCITY_KG_HUB_VERSION, true );
		wp_enqueue_script( 'bizcity-kg-hub-bootstrap' );
		wp_add_inline_script( 'bizcity-kg-hub-bootstrap',
			'window.BIZCITY_KG_HUB = ' . wp_json_encode( $bootstrap ) . ';'
		);

		// CSS
		foreach ( (array) ( $entry['css'] ?? [] ) as $css ) {
			$css_file = $dist_dir . $css;
			$css_ver  = file_exists( $css_file ) ? filemtime( $css_file ) : BIZCITY_KG_HUB_VERSION;
			wp_enqueue_style(
				'bizcity-kg-hub-css-' . md5( $css ),
				$dist_url . $css,
				[],
				(string) $css_ver
			);
		}
		// Main JS
		$js_file = $dist_dir . $entry['file'];
		$js_ver  = file_exists( $js_file ) ? filemtime( $js_file ) : BIZCITY_KG_HUB_VERSION;
		wp_enqueue_script(
			'bizcity-kg-hub-app',
			$dist_url . $entry['file'],
			[ 'bizcity-kg-hub-bootstrap' ],
			(string) $js_ver,
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
