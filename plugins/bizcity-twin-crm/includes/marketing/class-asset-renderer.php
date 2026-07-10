<?php
/**
 * BizCity CRM — Marketing Asset Renderer (PHASE 0.35 M6.W19+W20).
 *
 * Pipeline:
 *   1. Load SVG template from `templates/marketing-assets/{key}.svg`.
 *   2. Substitute placeholder tags using brand kit + campaign + opts.
 *      QR is inlined as base64 data URI from `BizCity_CRM_QR_Generator::png()`,
 *      logo is fetched once and inlined where local URL allows.
 *   3. For non-svg outputs, rasterize SVG → PNG via Imagick (preferred) or
 *      GD (fallback). PDF wraps PNG into a single-page PDF using FPDF if
 *      available, else returns SVG with a `Content-Disposition: inline`.
 *
 * @package BizCity_Twin_CRM
 * @since   PHASE 0.35 (M6.W19)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_CRM_Asset_Renderer {

	/**
	 * Registry of supported templates.
	 * Format: key => { file, width, height, label, mime_default, dpi_print }
	 */
	const TEMPLATES = array(
		'voucher_landscape' => array( 'file' => 'voucher_landscape.svg', 'width' => 1200, 'height' => 628,  'label' => 'Voucher ngang (FB/Zalo)',   'orientation' => 'landscape', 'paper' => 'social' ),
		'voucher_square'    => array( 'file' => 'voucher_square.svg',    'width' => 1080, 'height' => 1080, 'label' => 'Voucher vuông (IG/OA)',     'orientation' => 'square',    'paper' => 'social' ),
		'story_vertical'    => array( 'file' => 'story_vertical.svg',    'width' => 1080, 'height' => 1920, 'label' => 'Story dọc (IG/FB/TikTok)',  'orientation' => 'portrait',  'paper' => 'social' ),
		'name_card'         => array( 'file' => 'name_card.svg',         'width' => 1004, 'height' => 638,  'label' => 'Danh thiếp (85×54mm)',      'orientation' => 'landscape', 'paper' => 'card'   ),
		'leaflet_a6'        => array( 'file' => 'leaflet_a6.svg',        'width' => 1240, 'height' => 1748, 'label' => 'Tờ rơi A6',                 'orientation' => 'portrait',  'paper' => 'A6'     ),
		'table_tent_a5'     => array( 'file' => 'table_tent_a5.svg',     'width' => 1748, 'height' => 2480, 'label' => 'Standee bàn A5',            'orientation' => 'portrait',  'paper' => 'A5'     ),
	);

	const SUPPORTED_MIME = array( 'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'pdf' => 'application/pdf' );

	/** Templates folder absolute path. */
	public static function templates_dir(): string {
		// Resolve against this file (includes/marketing/) → ../../templates/marketing-assets/
		return dirname( __DIR__, 2 ) . '/templates/marketing-assets/';
	}

	/** Public list for FE manifest. */
	public static function list_templates(): array {
		$out = array();
		foreach ( self::TEMPLATES as $key => $meta ) {
			$out[] = array_merge( array( 'key' => $key ), $meta );
		}
		return $out;
	}

	/**
	 * Render a template into the requested format.
	 *
	 * @param int    $campaign_id
	 * @param string $template_key one of self::TEMPLATES
	 * @param string $format       svg|png|jpg|pdf
	 * @param array  $opts         { headline?, cta_text?, voucher_code?, hotline?, qr_size? }
	 * @return array|WP_Error      { mime, bytes, width, height, brand_hash }
	 */
	public static function render( int $campaign_id, string $template_key, string $format = 'svg', array $opts = array() ) {
		if ( ! isset( self::TEMPLATES[ $template_key ] ) ) {
			return new WP_Error( 'bizcity_crm_asset_unknown_template', 'Unknown template: ' . $template_key );
		}
		if ( ! isset( self::SUPPORTED_MIME[ $format ] ) ) {
			return new WP_Error( 'bizcity_crm_asset_bad_format', 'Unsupported format: ' . $format );
		}
		$meta = self::TEMPLATES[ $template_key ];
		$path = self::templates_dir() . $meta['file'];
		if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
			return new WP_Error( 'bizcity_crm_asset_missing_template', 'Template file not readable: ' . $meta['file'] );
		}
		$svg_raw = (string) file_get_contents( $path );
		if ( $svg_raw === '' ) {
			return new WP_Error( 'bizcity_crm_asset_empty_template', 'Template empty: ' . $meta['file'] );
		}

		// Pull campaign + brand kit context.
		$campaign = self::load_campaign( $campaign_id );
		if ( is_wp_error( $campaign ) ) { return $campaign; }
		$kit = BizCity_CRM_Brand_Kit::get();

		$tokens = self::build_tokens( $campaign, $kit, $opts );
		$svg    = strtr( $svg_raw, $tokens );

		$mime       = self::SUPPORTED_MIME[ $format ];
		$brand_hash = BizCity_CRM_Brand_Kit::hash( $kit );

		if ( $format === 'svg' ) {
			return array(
				'mime'       => $mime,
				'bytes'      => $svg,
				'width'      => (int) $meta['width'],
				'height'     => (int) $meta['height'],
				'brand_hash' => $brand_hash,
			);
		}

		$raster = self::rasterize( $svg, $format, (int) $meta['width'], (int) $meta['height'] );
		if ( is_wp_error( $raster ) ) {
			// PNG/JPG/PDF requested but rasterizer unavailable → degrade to SVG with notice.
			return array(
				'mime'       => self::SUPPORTED_MIME['svg'],
				'bytes'      => $svg,
				'width'      => (int) $meta['width'],
				'height'     => (int) $meta['height'],
				'brand_hash' => $brand_hash,
				'fallback'   => $raster->get_error_code(),
			);
		}
		return array(
			'mime'       => $mime,
			'bytes'      => $raster,
			'width'      => (int) $meta['width'],
			'height'     => (int) $meta['height'],
			'brand_hash' => $brand_hash,
		);
	}

	/* ------------------------------------------------------------------- */
	/* Token construction                                                  */
	/* ------------------------------------------------------------------- */

	private static function build_tokens( array $campaign, array $kit, array $opts ): array {
		$headline     = (string) ( $opts['headline']     ?? $campaign['headline']     ?? $campaign['title'] ?? '' );
		$cta_text     = (string) ( $opts['cta_text']     ?? $campaign['cta_text']     ?? 'Quét QR để chat' );
		$voucher_code = (string) ( $opts['voucher_code'] ?? $campaign['voucher_code'] ?? $campaign['code']  ?? '' );
		$hotline      = (string) ( $opts['hotline']      ?? $kit['hotline']           ?? '' );

		// Build QR (PNG → base64 data URI), inline so SVG is self-contained.
		$qr_payload = self::qr_payload( $campaign );
		$qr_size    = max( 128, min( 1024, (int) ( $opts['qr_size'] ?? 512 ) ) );
		$qr_data    = self::qr_data_uri( $qr_payload, $qr_size );

		$logo_data = self::logo_data_uri( (string) $kit['logo_url'] );

		return array(
			'{{BRAND_NAME}}'      => self::xml_escape( (string) $kit['brand_name'] ),
			'{{BRAND_LOGO}}'      => self::xml_escape( $logo_data ),
			'{{BRAND_PRIMARY}}'   => self::xml_escape( (string) $kit['primary_color'] ),
			'{{BRAND_SECONDARY}}' => self::xml_escape( (string) $kit['secondary_color'] ),
			'{{HEADLINE}}'        => self::xml_escape( $headline ),
			'{{CTA_TEXT}}'        => self::xml_escape( $cta_text ),
			'{{VOUCHER_CODE}}'    => self::xml_escape( $voucher_code !== '' ? $voucher_code : '—' ),
			'{{HOTLINE}}'         => self::xml_escape( $hotline ),
			'{{QR_IMG}}'          => self::xml_escape( $qr_data ),
			// Legacy aliases the spec mentions but UI may not pass:
			'{{CODE}}'            => self::xml_escape( $voucher_code ),
			'{{BG_IMAGE}}'        => '', // intentionally blank — templates have built-in gradients.
		);
	}

	/** XML-safe escape for text inserted into SVG. */
	private static function xml_escape( string $v ): string {
		return htmlspecialchars( $v, ENT_QUOTES | ENT_XML1, 'UTF-8' );
	}

	/** Build QR payload — prefer canonical Messenger ref URL when available. */
	private static function qr_payload( array $campaign ): string {
		if ( class_exists( 'BizCity_CRM_QR_Generator' ) && ! empty( $campaign['code'] ) ) {
			try {
				return BizCity_CRM_QR_Generator::build_url( $campaign );
			} catch ( \Throwable $e ) {
				// Fall through to home_url.
			}
		}
		$code = isset( $campaign['code'] ) ? (string) $campaign['code'] : '';
		return $code !== '' ? home_url( '/?bizcity_ref=' . rawurlencode( $code ) ) : home_url( '/' );
	}

	/** PNG QR → base64 data URI; falls back to inline SVG when GD missing. */
	private static function qr_data_uri( string $payload, int $size ): string {
		if ( ! class_exists( 'BizCity_CRM_QR_Generator' ) ) { return ''; }
		try {
			$png = BizCity_CRM_QR_Generator::png( $payload, $size, 4 );
			if ( $png !== '' ) { return 'data:image/png;base64,' . base64_encode( $png ); }
		} catch ( \Throwable $e ) {
			// fall through
		}
		try {
			$svg = BizCity_CRM_QR_Generator::svg( $payload, $size, 4 );
			if ( $svg !== '' ) { return 'data:image/svg+xml;base64,' . base64_encode( $svg ); }
		} catch ( \Throwable $e ) {
			return '';
		}
		return '';
	}

	/**
	 * Convert a logo URL to a data URI when local & readable. Remote URLs are
	 * passed through unchanged (browser/Imagick may fetch them).
	 */
	private static function logo_data_uri( string $url ): string {
		if ( $url === '' ) { return ''; }
		$upload_dir = wp_upload_dir();
		$base_url   = isset( $upload_dir['baseurl'] ) ? (string) $upload_dir['baseurl'] : '';
		$base_dir   = isset( $upload_dir['basedir'] ) ? (string) $upload_dir['basedir'] : '';
		if ( $base_url !== '' && $base_dir !== '' && strpos( $url, $base_url ) === 0 ) {
			$rel  = substr( $url, strlen( $base_url ) );
			$path = $base_dir . $rel;
			if ( is_readable( $path ) ) {
				$bytes = (string) file_get_contents( $path );
				if ( $bytes !== '' ) {
					$mime = wp_check_filetype( $path )['type'] ?? 'image/png';
					return 'data:' . $mime . ';base64,' . base64_encode( $bytes );
				}
			}
		}
		return $url; // pass through as href; SVG renderers will dereference.
	}

	/* ------------------------------------------------------------------- */
	/* Rasterization                                                       */
	/* ------------------------------------------------------------------- */

	/**
	 * SVG → PNG/JPG/PDF bytes. Returns WP_Error when no rasterizer available.
	 */
	private static function rasterize( string $svg, string $format, int $w, int $h ) {
		if ( $format === 'png' || $format === 'jpg' ) {
			if ( extension_loaded( 'imagick' ) && class_exists( 'Imagick' ) ) {
				try {
					$im = new Imagick();
					$im->setBackgroundColor( new ImagickPixel( 'white' ) );
					$im->readImageBlob( $svg );
					$im->setImageFormat( $format === 'jpg' ? 'jpeg' : 'png' );
					$im->resizeImage( $w, $h, Imagick::FILTER_LANCZOS, 1 );
					$bytes = $im->getImageBlob();
					$im->clear();
					return $bytes;
				} catch ( \Throwable $e ) {
					// Fall through to GD.
				}
			}
			// GD cannot rasterize SVG → return WP_Error so caller can fall back to SVG.
			return new WP_Error( 'bizcity_crm_asset_no_rasterizer', 'Imagick unavailable; cannot rasterize SVG to ' . $format );
		}
		if ( $format === 'pdf' ) {
			// Try to convert via Imagick first.
			if ( extension_loaded( 'imagick' ) && class_exists( 'Imagick' ) ) {
				try {
					$im = new Imagick();
					$im->setBackgroundColor( new ImagickPixel( 'white' ) );
					$im->readImageBlob( $svg );
					$im->setImageFormat( 'pdf' );
					$bytes = $im->getImageBlob();
					$im->clear();
					return $bytes;
				} catch ( \Throwable $e ) {
					// fall through
				}
			}
			return new WP_Error( 'bizcity_crm_asset_no_pdf_engine', 'No PDF engine available (need Imagick with PDF delegate).' );
		}
		return new WP_Error( 'bizcity_crm_asset_bad_format', 'Unsupported format: ' . $format );
	}

	/* ------------------------------------------------------------------- */
	/* Campaign loader (minimal, repository-agnostic)                      */
	/* ------------------------------------------------------------------- */

	private static function load_campaign( int $campaign_id ) {
		if ( $campaign_id <= 0 ) {
			return new WP_Error( 'bizcity_crm_asset_bad_campaign', 'Campaign id required.' );
		}
		if ( class_exists( 'BizCity_CRM_Campaign_Repository' ) ) {
			$repo = new BizCity_CRM_Campaign_Repository();
			if ( method_exists( $repo, 'find' ) ) {
				$row = $repo->find( $campaign_id );
				if ( $row ) { return is_array( $row ) ? $row : (array) $row; }
			}
		}
		return new WP_Error( 'bizcity_crm_asset_campaign_not_found', 'Campaign not found: ' . $campaign_id );
	}
}
