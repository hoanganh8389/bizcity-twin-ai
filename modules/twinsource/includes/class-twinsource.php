<?php
/**
 * Twinsource facade — render + enqueue.
 *
 * @package Bizcity_Twin_AI\Twinsource
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Twinsource {

	private static $enqueued = false;

	/**
	 * Render the mount point + ensure assets enqueued.
	 *
	 * @param array $args {
	 *   @type array  scope            { plugin, scope_type, scope_id }  (required)
	 *   @type array  capabilities     optional capability flags
	 *   @type array  toggles          optional toggle row config
	 *   @type array  secondary_scope  optional read-only secondary scope (Path-B dual-tab)
	 *   @type string mount_id         DOM id (default: auto)
	 *   @type string variant          'sidebar' | 'modal' | 'compact'
	 * }
	 */
	public static function render( array $args ): void {
		$scope = $args['scope'] ?? null;
		if ( ! is_array( $scope ) || empty( $scope['plugin'] ) || empty( $scope['scope_id'] ) ) {
			echo '<!-- twinsource: missing scope -->';
			return;
		}

		$config = [
			'scope'        => self::normalize_scope( $scope ),
			'capabilities' => self::normalize_capabilities( $args['capabilities'] ?? [] ),
			'toggles'      => array_values( array_map( [ __CLASS__, 'normalize_toggle' ], $args['toggles'] ?? [] ) ),
			'variant'      => in_array( $args['variant'] ?? 'sidebar', [ 'sidebar', 'modal', 'compact' ], true ) ? $args['variant'] : 'sidebar',
		];

		// Path-B dual-tab: serialize as `secondaryScope` (camelCase) to match the TS `TwinsourceConfig`.
		$secondary = $args['secondary_scope'] ?? $args['secondaryScope'] ?? null;
		if ( is_array( $secondary ) && ! empty( $secondary['plugin'] ) && ! empty( $secondary['scope_id'] ) ) {
			$config['secondaryScope'] = self::normalize_scope( $secondary );
		}

		$mount_id = sanitize_html_class( $args['mount_id'] ?? 'twinsource-' . wp_generate_uuid4() );

		self::enqueue();

		printf(
			'<div id="%s" class="twinsource-mount" data-twinsource="%s"></div>',
			esc_attr( $mount_id ),
			esc_attr( wp_json_encode( $config, JSON_UNESCAPED_UNICODE ) )
		);
	}

	/**
	 * Enqueue Twinsource bundle once per request.
	 */
	public static function enqueue(): void {
		if ( self::$enqueued ) {
			return;
		}
		self::$enqueued = true;

		$dist_js  = BIZCITY_TWINSOURCE_DIR . '/ui/dist/twinsource.js';
		$dist_css = BIZCITY_TWINSOURCE_DIR . '/ui/dist/twinsource.css';

		// Wave 0: bundle chưa build — guard tránh 404.
		if ( file_exists( $dist_js ) ) {
			wp_enqueue_script(
				'bizcity-twinsource',
				BIZCITY_TWINSOURCE_URL . '/ui/dist/twinsource.js',
				[], // No deps — i18n strings are passed via BIZCITY_TWINSOURCE_BOOT, no @wordpress/i18n usage in bundle.
				(string) filemtime( $dist_js ),
				true
			);
		}
		if ( file_exists( $dist_css ) ) {
			wp_enqueue_style(
				'bizcity-twinsource',
				BIZCITY_TWINSOURCE_URL . '/ui/dist/twinsource.css',
				[],
				(string) filemtime( $dist_css )
			);
		}

		wp_localize_script( 'bizcity-twinsource', 'BIZCITY_TWINSOURCE_BOOT', [
			'restRoot' => esc_url_raw( rest_url( 'bizcity-knowledge/v2' ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'userId'   => get_current_user_id(),
			'iframe'   => self::is_iframe_context(),
			'i18n'     => [
				'add_source'        => __( 'Thêm nguồn', 'bizcity-twinsource' ),
				'paste_url'         => __( 'Dán URL hoặc tìm trên web…', 'bizcity-twinsource' ),
				'select_all'        => __( 'Chọn tất cả nguồn', 'bizcity-twinsource' ),
				'sources'           => __( 'Nguồn', 'bizcity-twinsource' ),
				'empty_state'       => __( 'Chưa có nguồn — bấm + Thêm nguồn hoặc dán URL.', 'bizcity-twinsource' ),
				'borrow_from'       => __( 'Mượn từ scope khác', 'bizcity-twinsource' ),
				'embedding_pending' => __( 'Đang xử lý…', 'bizcity-twinsource' ),
				'embedding_failed'  => __( 'Lỗi xử lý nguồn', 'bizcity-twinsource' ),
			],
		] );
	}

	private static function normalize_scope( array $scope ): array {
		return [
			'plugin'     => sanitize_key( $scope['plugin'] ),
			'scope_type' => sanitize_key( $scope['scope_type'] ?? 'project' ),
			'scope_id'   => (string) $scope['scope_id'],
		];
	}

	private static function normalize_capabilities( array $caps ): array {
		$defaults = [
			'add_file'      => true,
			'add_url'       => true,
			'add_text'      => true,
			'web_search'    => true,
			'borrow'        => true,
			'delete'        => true,
			'select_filter' => true,
		];
		$out = [];
		foreach ( $defaults as $k => $default ) {
			$out[ $k ] = isset( $caps[ $k ] ) ? (bool) $caps[ $k ] : $default;
		}
		return $out;
	}

	private static function normalize_toggle( $t ): array {
		return [
			'id'      => sanitize_key( $t['id'] ?? '' ),
			'label'   => (string) ( $t['label'] ?? '' ),
			'tooltip' => (string) ( $t['tooltip'] ?? '' ),
		];
		// value/onChange are runtime — host React tree wires them via Twinsource.attach().
	}

	private static function is_iframe_context(): bool {
		// Heuristic: shell adds ?bizcity_iframe=1 when embedding.
		return ! empty( $_GET['bizcity_iframe'] );
	}
}
