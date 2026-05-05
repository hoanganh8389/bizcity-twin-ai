<?php
/**
 * TwinSearch — Asset Loader
 *
 * Enqueues the built React bundle on the 2 surfaces defined by R-IP-6:
 *   1) Admin Knowledge → Character editor screen (tab "🔬 Nghiên cứu")
 *   2) Front-end TwinChat shell (button in SmartSourcesPanel)
 *
 * Bundle lives at `modules/twinsearch/ui/dist/`. We read `vite manifest.json`
 * to resolve the hashed entry filename (Vite default for `manifest:true` config).
 *
 * Localizes a single global `bizcityTwinSearch` with REST URL + nonce + i18n.
 *
 * @package Bizcity_Twin_AI\Modules\TwinSearch
 * @since   0.18.1.7
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_TwinSearch_Asset_Loader' ) ) {
	return;
}

class BizCity_TwinSearch_Asset_Loader {

	const HANDLE_JS  = 'bizcity-twinsearch';
	const HANDLE_CSS = 'bizcity-twinsearch-css';

	public static function init(): void {
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'maybe_enqueue_admin' ] );
		// Priority 20: ensure twinchat public page (priority 10) defines
		// BIZCITY_TWINCHAT_ACTIVE before this loader checks for it.
		add_action( 'wp_enqueue_scripts',    [ __CLASS__, 'maybe_enqueue_front' ], 20 );
	}

	/** Enqueue on Knowledge → Character editor pages only. */
	public static function maybe_enqueue_admin( $hook ): void {
		// Knowledge module character edit screens use these slugs (see core/knowledge/views/).
		$is_character_screen = (
			isset( $_GET['page'] ) && in_array( $_GET['page'], [ 'bizcity-knowledge-characters', 'bizcity-characters' ], true )
		);
		if ( ! $is_character_screen ) {
			return;
		}
		self::enqueue_bundle();
	}

	/** Enqueue on front-end pages that host TwinChat. */
	public static function maybe_enqueue_front(): void {
		// TwinChat-aware pages set this body class via filter; fall back to global flag.
		if ( ! ( defined( 'BIZCITY_TWINCHAT_ACTIVE' ) && BIZCITY_TWINCHAT_ACTIVE ) ) {
			return;
		}
		self::enqueue_bundle();
	}

	private static function enqueue_bundle(): void {
		$dist     = BIZCITY_TWINSEARCH_UI_DIST;
		$dist_url = trailingslashit( BIZCITY_TWINSEARCH_URL ) . 'ui/dist/';
		$manifest_path = $dist . '.vite/manifest.json';

		$entry_js  = '';
		$entry_css = '';

		if ( file_exists( $manifest_path ) ) {
			$manifest = json_decode( file_get_contents( $manifest_path ), true );
			if ( is_array( $manifest ) && isset( $manifest['index.html'] ) ) {
				$entry = $manifest['index.html'];
				if ( ! empty( $entry['file'] ) ) {
					$entry_js = $entry['file'];
				}
				if ( ! empty( $entry['css'] ) && is_array( $entry['css'] ) ) {
					$entry_css = $entry['css'][0];
				}
			}
		}

		if ( $entry_js === '' ) {
			// Bundle not built yet — fail silent (dev environment may run vite dev server).
			return;
		}

		wp_enqueue_script(
			self::HANDLE_JS,
			$dist_url . $entry_js,
			[],
			BIZCITY_TWINSEARCH_VERSION,
			true
		);
		// Vite emits as ESM.
		add_filter( 'script_loader_tag', function ( $tag, $handle ) {
			if ( $handle === self::HANDLE_JS ) {
				return str_replace( '<script ', '<script type="module" ', $tag );
			}
			return $tag;
		}, 10, 2 );

		if ( $entry_css ) {
			wp_enqueue_style(
				self::HANDLE_CSS,
				$dist_url . $entry_css,
				[],
				BIZCITY_TWINSEARCH_VERSION
			);
		}

		wp_localize_script( self::HANDLE_JS, 'bizcityTwinSearch', self::build_config() );
	}

	/**
	 * Build the JS config object — shared between wp_localize_script and the
	 * inline injection path used by render_full_page().
	 *
	 * @return array<string,mixed>
	 */
	private static function build_config(): array {
		return [
			'restUrl'       => esc_url_raw( rest_url( 'bizcity/research/v1/' ) ),
			'nonce'         => wp_create_nonce( 'wp_rest' ),
			'i18n'          => [
				'dialogTitle'   => __( '🔬 Deep Research', 'bizcity-twinsearch' ),
				'placeholder'   => __( 'Bạn muốn nghiên cứu chủ đề gì?', 'bizcity-twinsearch' ),
				'modeFast'      => __( 'Nhanh', 'bizcity-twinsearch' ),
				'modeDeep'      => __( 'Sâu', 'bizcity-twinsearch' ),
				'addSource'     => __( 'Thêm vào nguồn', 'bizcity-twinsearch' ),
				'removeSource'  => __( 'Bỏ khỏi nguồn', 'bizcity-twinsearch' ),
				'inSources'     => __( 'Đã trong nguồn', 'bizcity-twinsearch' ),
				'startSearch'   => __( 'Bắt đầu nghiên cứu', 'bizcity-twinsearch' ),
				'thinking'      => __( 'Đang phân tích...', 'bizcity-twinsearch' ),
				'searching'     => __( 'Đang tìm kiếm...', 'bizcity-twinsearch' ),
				'extracting'    => __( 'Đang trích xuất...', 'bizcity-twinsearch' ),
				'crawling'      => __( 'Đang crawl...', 'bizcity-twinsearch' ),
				'generating'    => __( 'Đang tổng hợp báo cáo...', 'bizcity-twinsearch' ),
				'sources'       => __( 'Nguồn', 'bizcity-twinsearch' ),
				'report'        => __( 'Báo cáo', 'bizcity-twinsearch' ),
				'pipeline'      => __( 'Pipeline', 'bizcity-twinsearch' ),
				'cancel'        => __( 'Hủy', 'bizcity-twinsearch' ),
				'cancelled'     => __( 'Đã hủy', 'bizcity-twinsearch' ),
				'gateRequired'  => __( 'Bắt buộc bổ sung nguồn trước khi trò chuyện', 'bizcity-twinsearch' ),
				'gateMin'       => __( 'Số nguồn tối thiểu', 'bizcity-twinsearch' ),
				'openResearch'  => __( '🔬 Deep Research (TwinSearch)', 'bizcity-twinsearch' ),
				'citationOpen'  => __( 'Mở nguồn', 'bizcity-twinsearch' ),
			],
			'inputGateRest' => esc_url_raw( rest_url( 'twinsearch/v1/' ) ),
		];
	}

	/**
	 * Called directly from BizCity_TwinChat_Public_Page::render_full_page().
	 *
	 * render_full_page() calls exit() before wp_enqueue_scripts fires, so
	 * the normal maybe_enqueue_front() hook never runs. This method echoes
	 * the inline config + <script type="module"> directly into the custom
	 * HTML document so the headless controller mounts properly.
	 *
	 * @param string $ver  Version string for cache-busting (from caller).
	 */
	public static function inline_for_full_page( string $ver = '' ): void {
		// Prevent double-injection if called more than once per page render.
		static $already_injected = false;
		if ( $already_injected ) {
			return;
		}
		$already_injected = true;

		$dist          = BIZCITY_TWINSEARCH_UI_DIST;
		$dist_url      = trailingslashit( BIZCITY_TWINSEARCH_URL ) . 'ui/dist/';
		$manifest_path = $dist . '.vite/manifest.json';

		if ( ! file_exists( $manifest_path ) ) {
			return;
		}

		$manifest = json_decode( (string) file_get_contents( $manifest_path ), true );
		if ( ! is_array( $manifest ) || empty( $manifest['index.html']['file'] ) ) {
			return;
		}

		$entry_js = (string) $manifest['index.html']['file'];

		// Inline config must land BEFORE the ES module script so the app can
		// read window.bizcityTwinSearch synchronously on load.
		$config = (string) wp_json_encode( self::build_config() );
		echo '<script data-cfasync="false">window.bizcityTwinSearch = ' . $config . ';</script>' . "\n";

		$src = esc_url( $dist_url . $entry_js ) . ( $ver !== '' ? '?ver=' . esc_attr( $ver ) : '' );
		echo '<script type="module" src="' . $src . '"></script>' . "\n";
	}
}
