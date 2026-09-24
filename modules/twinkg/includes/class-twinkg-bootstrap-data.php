<?php
/**
 * Bizcity Twin AI — TwinKG Bootstrap Data
 *
 * Single source for the JS bootstrap object consumed by the KG React app on
 * BOTH surfaces (public `/twinkg/` page and the Twin Shell iframe), so the two
 * can never drift apart.
 *
 * The object name (`window.BIZCITY_KG_HUB`) and the root element id
 * (`bizcity-kg-hub-root`) are DELIBERATELY unchanged from the original mount in
 * `core/knowledge/kg-hub/includes/class-kg-admin-menu.php`: WP-09 step T1 is a
 * pure relocation, so `ui/src/**` needs no edit. Renaming both is WP-09 step T6
 * and must ship with a one-release alias.
 *
 * Ownership (WP-09 §3 / proposed rule R-KG-UI): this module is a VIEW. It owns
 * no table, option, cron or REST route. Every value below is either a WordPress
 * primitive or a REST address — never a KG service call.
 *
 * PHP 7.4 compatible — no match, no enums, no nullsafe, no readonly.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Modules\TwinKG
 * @since      2026-09-24
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_TwinKG_Bootstrap_Data {

	/** Views the React store accepts (ui/src/main.tsx + store.ts). */
	const ALLOWED_VIEWS = array( 'gurus', 'graph', 'queue', 'sources', 'settings', 'gurus-admin', 'guru' );

	/**
	 * Build the bootstrap payload.
	 *
	 * @param array $overrides Values that win over the defaults (e.g. `surface`).
	 * @return array
	 */
	public static function build( array $overrides = array() ): array {
		$data = array(
			// The canonical KG REST namespace. Read from the owner constant when
			// core/knowledge happens to be loaded; on a plain `/twinkg/` front-end
			// request it is NOT loaded (core/knowledge is gated on admin context),
			// so the literal is the normal path, not a fallback for breakage.
			'restNamespace' => class_exists( 'BizCity_KG_Rest_Controller' )
				? BizCity_KG_Rest_Controller::NAMESPACE_V2
				: 'bizcity-knowledge/v2',
			'restRoot'      => esc_url_raw( rest_url() ),
			'nonce'         => wp_create_nonce( 'wp_rest' ),
			'currentUserId' => get_current_user_id(),
			// Only decides whether the admin-only "Settings & Cost" entry is shown. The REST
			// route enforces the same gate on its own; this flag grants nothing.
			'canManage'     => class_exists( 'BizCity_Network_Admin_Capability' )
				? BizCity_Network_Admin_Capability::can_manage()
				: current_user_can( 'manage_options' ),
			'blogId'        => get_current_blog_id(),
			'pluginUrl'     => defined( 'BIZCITY_TWINKG_URL' ) ? BIZCITY_TWINKG_URL : '',
			'buildVersion'  => self::build_version(),
			'defaultView'   => self::requested_view(),
			// `?guru=N` deep link for the Guru editor (WP-09 T5a); null when absent.
			'defaultGuru'   => ! empty( $_GET['guru'] ) ? absint( $_GET['guru'] ) : null,
			// Base for links that still point into wp-admin (the legacy Guru editor until T5f).
			'adminUrl'      => esc_url_raw( admin_url() ),
			// Surface metadata — lets the app adapt chrome without guessing from
			// the URL. 'public' = /twinkg/, 'shell' = inside the Twin Shell iframe.
			'surface'       => 'public',
			'shellEmbed'    => ! empty( $_GET['bizcity_iframe'] ),
			'homeUrl'       => esc_url_raw( home_url( '/' ) ),
			'twinUrl'       => esc_url_raw( home_url( '/twin/' ) ),
		);

		foreach ( $overrides as $key => $value ) {
			$data[ $key ] = $value;
		}

		return $data;
	}

	/**
	 * Cache-buster: the Vite manifest mtime changes on every `npm run build`,
	 * which the module version alone would not.
	 *
	 * @return string
	 */
	public static function build_version(): string {
		$manifest = self::manifest_path();
		if ( '' !== $manifest && file_exists( $manifest ) ) {
			return (string) filemtime( $manifest );
		}
		return defined( 'BIZCITY_TWINKG_VERSION' ) ? BIZCITY_TWINKG_VERSION : '0.0.0';
	}

	/**
	 * Absolute path of the Vite manifest, or '' when the UI is not built.
	 *
	 * Vite 5 writes `dist/.vite/manifest.json`; older builds wrote
	 * `dist/manifest.json`. Both are accepted so an older `dist/` on a deployed
	 * site keeps rendering instead of falling back to the "not built" notice.
	 *
	 * @return string
	 */
	public static function manifest_path(): string {
		if ( ! defined( 'BIZCITY_TWINKG_UI_DIR' ) ) {
			return '';
		}
		$dist = BIZCITY_TWINKG_UI_DIR . 'dist/';
		if ( file_exists( $dist . '.vite/manifest.json' ) ) {
			return $dist . '.vite/manifest.json';
		}
		if ( file_exists( $dist . 'manifest.json' ) ) {
			return $dist . 'manifest.json';
		}
		return '';
	}

	/**
	 * Parse the Vite manifest into the three asset groups the page needs.
	 *
	 * @return array{entry_js:string,chunk_js:array,css:array} URLs, already absolute.
	 */
	public static function assets(): array {
		$out = array( 'entry_js' => '', 'chunk_js' => array(), 'css' => array() );

		$manifest = self::manifest_path();
		if ( '' === $manifest ) {
			return $out;
		}
		$json = json_decode( (string) file_get_contents( $manifest ), true );
		if ( ! is_array( $json ) ) {
			return $out;
		}

		$dist_url = trailingslashit( BIZCITY_TWINKG_URL ) . 'ui/dist/';
		foreach ( $json as $asset ) {
			if ( ! is_array( $asset ) || ! isset( $asset['file'] ) ) {
				continue;
			}
			$file_url = $dist_url . $asset['file'];
			$is_entry = ! empty( $asset['isEntry'] );

			if ( preg_match( '/\.js$/', (string) $asset['file'] ) ) {
				if ( $is_entry ) {
					$out['entry_js'] = $file_url;
				} else {
					$out['chunk_js'][] = $file_url;
				}
			}
			if ( ! empty( $asset['css'] ) ) {
				foreach ( (array) $asset['css'] as $css_file ) {
					$out['css'][] = $dist_url . $css_file;
				}
			}
		}

		// A CSS-only manifest row (Vite emits one when `cssCodeSplit` is false and
		// the stylesheet is not attached to the entry) would otherwise be dropped.
		if ( empty( $out['css'] ) ) {
			foreach ( $json as $asset ) {
				if ( is_array( $asset ) && isset( $asset['file'] ) && preg_match( '/\.css$/', (string) $asset['file'] ) ) {
					$out['css'][] = $dist_url . $asset['file'];
				}
			}
		}

		$out['css']      = array_values( array_unique( $out['css'] ) );
		$out['chunk_js'] = array_values( array_unique( $out['chunk_js'] ) );

		return $out;
	}

	/**
	 * `?view=` deep-link, validated against the views the React store knows.
	 *
	 * Returns null (not a default) so the app keeps its own persisted choice
	 * when the URL says nothing — the same contract the original mount had.
	 *
	 * @return string|null
	 */
	private static function requested_view() {
		if ( empty( $_GET['view'] ) ) {
			return null;
		}
		$view = sanitize_key( wp_unslash( $_GET['view'] ) );
		return in_array( $view, self::ALLOWED_VIEWS, true ) ? $view : null;
	}
}
