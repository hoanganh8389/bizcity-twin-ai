<?php
/**
 * BizCity CRM — Print-Ads Composer service (M-PA.W2).
 *
 * Builds final LLM prompt by merging template + campaign context + caller
 * overrides, calls BizCity_Tool_Image::generate_image(), saves an audit
 * row to {prefix}bzcrm_print_generations, and tags the resulting Media
 * Library attachment with three meta fields.
 *
 * Placeholders resolved automatically:
 *   {campaign_title}  → campaign.name
 *   {brand_name}      → site option blogname (filterable)
 *   {qr_url}          → rest_url(bizcity-crm/v1/campaigns/{id}/qr.png?size=480)
 *   {campaign_url}    → rest /campaigns/{id}/url short URL when available
 *   {discount}        → overrides['discount']   (default '')
 *   {cta_text}        → overrides['cta_text']   (default 'Liên hệ ngay')
 *   {custom_detail}   → overrides['custom_detail'] (default '')
 *
 * @package BizCity_Twin_CRM
 * @since   0.32.3 (M-PA.W2)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Print_Ads_Composer', false ) ) { return; }

final class BizCity_CRM_Print_Ads_Composer {

	/** Rate-limit window per campaign per user (seconds). */
	const RATE_LIMIT_SEC = 10;

	const ASPECT_TO_SIZE = array(
		'1:1'  => '1024x1024',
		'4:5'  => '1024x1536',  // closest supported portrait
		'9:16' => '768x1344',
		'16:9' => '1344x768',
	);

	/* ─────────────────────────────────────────────────────────────
	 * Template lookups
	 * ───────────────────────────────────────────────────────────── */

	public static function list_templates( array $args = array() ): array {
		global $wpdb;
		$tbl = BizCity_CRM_Print_Templates_Installer::tbl_templates();

		$where  = array( "status = 'active'" );
		$params = array();
		if ( ! empty( $args['template_type'] ) ) {
			$where[]  = 'template_type = %s';
			$params[] = substr( (string) $args['template_type'], 0, 40 );
		}
		if ( ! empty( $args['source'] ) ) {
			$where[]  = 'source = %s';
			$params[] = substr( (string) $args['source'], 0, 20 );
		}

		$sql = "SELECT * FROM {$tbl} WHERE " . implode( ' AND ', $where )
			. ' ORDER BY template_type ASC, sort_order ASC, id ASC';
		if ( $params ) {
			$sql = $wpdb->prepare( $sql, $params );
		}
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		return array_map( array( __CLASS__, 'hydrate' ), $rows ?: array() );
	}

	public static function get_template( int $id ): ?array {
		global $wpdb;
		$tbl = BizCity_CRM_Print_Templates_Installer::tbl_templates();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tbl} WHERE id = %d", $id ), ARRAY_A );
		return $row ? self::hydrate( $row ) : null;
	}

	private static function hydrate( array $row ): array {
		$row['id']              = (int) $row['id'];
		$row['sort_order']      = (int) $row['sort_order'];
		$row['qr_slot']         = ! empty( $row['qr_slot_json'] )    ? ( json_decode( (string) $row['qr_slot_json'], true )    ?: null ) : null;
		$row['brand_slot']      = ! empty( $row['brand_slot_json'] ) ? ( json_decode( (string) $row['brand_slot_json'], true ) ?: null ) : null;
		return $row;
	}

	/* ─────────────────────────────────────────────────────────────
	 * Prompt merge
	 * ───────────────────────────────────────────────────────────── */

	/**
	 * Build the full variable bag used to expand {placeholders} in base_prompt.
	 */
	public static function resolve_vars( array $campaign, array $overrides ): array {
		$campaign_id = (int) ( $campaign['id'] ?? 0 );

		// {qr_url} = the target/landing URL the QR code encodes (what customers land on
		// after scanning). Use QR generator so ref/utm params are consistent with
		// the printable QR in campaign assets.
		if ( class_exists( 'BizCity_CRM_QR_Generator' ) ) {
			$qr_target_url = BizCity_CRM_QR_Generator::build_url( $campaign );
		} elseif ( ! empty( $campaign['landing_url'] ) ) {
			$qr_target_url = (string) $campaign['landing_url'];
		} else {
			$qr_target_url = home_url( '/' );
		}

		// {qr_image_url} = ready-to-embed PNG via qrserver.com.
		// Template authors may use this as a real image reference in base_prompt.
		$qr_image_url = 'https://api.qrserver.com/v1/create-qr-code/?size=400x400&data='
			. rawurlencode( $qr_target_url );

		$campaign_url = '';

		$brand_name = (string) apply_filters(
			'bzcrm_print_ads_brand_name',
			(string) get_bloginfo( 'name' ),
			$campaign
		);

		return array(
			'campaign_title' => (string) ( $campaign['name'] ?? '' ),
			'campaign_code'  => (string) ( $campaign['code'] ?? '' ),
			'brand_name'     => $brand_name,
			'qr_url'         => $qr_target_url,
			'qr_image_url'   => $qr_image_url,
			'campaign_url'   => $campaign_url,
			'discount'       => (string) ( $overrides['discount']       ?? '' ),
			'cta_text'       => (string) ( $overrides['cta_text']       ?? 'Liên hệ ngay' ),
			'custom_detail'  => (string) ( $overrides['custom_detail']  ?? '' ),
		);
	}

	public static function merge_prompt( string $template, array $vars ): string {
		$out = $template;
		foreach ( $vars as $key => $value ) {
			$out = str_replace( '{' . $key . '}', (string) $value, $out );
		}
		// Strip any unresolved placeholders so they don't leak into prompt.
		$out = preg_replace( '/\{[a-z_][a-z0-9_]*\}/i', '', $out );
		// Collapse extra whitespace.
		$out = trim( preg_replace( '/\s+/', ' ', (string) $out ) );
		return $out;
	}

	/* ─────────────────────────────────────────────────────────────
	 * Generations table helpers
	 * ───────────────────────────────────────────────────────────── */

	private static function insert_generation_row( int $campaign_id, int $template_id, string $model, string $merged_prompt, array $overrides ): int {
		global $wpdb;
		$tbl = BizCity_CRM_Print_Templates_Installer::tbl_generations();
		$now = current_time( 'mysql' );
		$ok  = $wpdb->insert( $tbl, array(
			'campaign_id'    => $campaign_id,
			'template_id'    => $template_id,
			'attachment_id'  => null,
			'model'          => substr( $model, 0, 40 ),
			'merged_prompt'  => $merged_prompt,
			'overrides_json' => wp_json_encode( $overrides ),
			'status'         => 'pending',
			'error'          => null,
			'created_by'     => get_current_user_id() ?: null,
			'created_at'     => $now,
			'updated_at'     => $now,
		) );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	private static function finish_generation_row( int $gen_id, string $status, ?int $attachment_id, ?string $error ): void {
		global $wpdb;
		$tbl = BizCity_CRM_Print_Templates_Installer::tbl_generations();
		$wpdb->update( $tbl, array(
			'status'        => $status,
			'attachment_id' => $attachment_id ?: null,
			'error'         => $error,
			'updated_at'    => current_time( 'mysql' ),
		), array( 'id' => $gen_id ) );
	}

	public static function list_generations( int $campaign_id, int $limit = 50 ): array {
		global $wpdb;
		$tbl = BizCity_CRM_Print_Templates_Installer::tbl_generations();
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT g.id, g.campaign_id, g.template_id, g.attachment_id, g.model, g.status, g.error, g.created_at,
			        g.merged_prompt
			   FROM {$tbl} g
			  WHERE g.campaign_id = %d
			  ORDER BY g.id DESC
			  LIMIT %d",
			$campaign_id, max( 1, min( 200, $limit ) )
		), ARRAY_A );

		$rows = is_array( $rows ) ? $rows : array();
		foreach ( $rows as &$r ) {
			$r['id']            = (int) $r['id'];
			$r['campaign_id']   = (int) $r['campaign_id'];
			$r['template_id']   = (int) $r['template_id'];
			$r['attachment_id'] = $r['attachment_id'] ? (int) $r['attachment_id'] : null;
			$r['image_url']     = $r['attachment_id'] ? (string) wp_get_attachment_url( (int) $r['attachment_id'] ) : '';
			$r['thumb_url']     = $r['attachment_id']
				? ( wp_get_attachment_image_url( (int) $r['attachment_id'], 'medium' ) ?: $r['image_url'] )
				: '';
		}
		unset( $r );
		return $rows;
	}

	/* ─────────────────────────────────────────────────────────────
	 * Main entry — generate
	 * ───────────────────────────────────────────────────────────── */

	/**
	 * Generate one print-ad image for a campaign using a template.
	 *
	 * @param int   $campaign_id
	 * @param int   $template_id
	 * @param array $overrides   { cta_text?, discount?, custom_detail?, model?, size?, ref_image_url? }
	 * @return array|WP_Error    { generation_id, attachment_id, image_url, model, merged_prompt }
	 */
	public static function generate( int $campaign_id, int $template_id, array $overrides = array() ) {
		if ( ! class_exists( 'BizCity_CRM_Campaign_Repository' ) ) {
			return new WP_Error( 'bzcrm_print_ads_missing_dep', 'Campaign repository unavailable.' );
		}
		if ( ! class_exists( 'BizCity_Tool_Image' ) ) {
			return new WP_Error( 'bzcrm_print_ads_missing_image_plugin', 'bizcity-tool-image plugin is not active.' );
		}

		$campaign = BizCity_CRM_Campaign_Repository::get( $campaign_id );
		if ( ! $campaign ) {
			return new WP_Error( 'bzcrm_print_ads_no_campaign', 'Campaign not found.' );
		}

		$template = self::get_template( $template_id );
		if ( ! $template ) {
			return new WP_Error( 'bzcrm_print_ads_no_template', 'Print template not found.' );
		}

		// Per-campaign-per-user rate limit (transient, short window).
		$rl_key = 'bzcrm_print_ads_rl_' . $campaign_id . '_' . ( get_current_user_id() ?: 0 );
		if ( get_transient( $rl_key ) ) {
			return new WP_Error(
				'bzcrm_print_ads_rate_limited',
				sprintf( 'Vui lòng chờ %ds giữa các lần tạo ảnh cho cùng campaign.', self::RATE_LIMIT_SEC )
			);
		}
		set_transient( $rl_key, 1, self::RATE_LIMIT_SEC );

		// Build merged prompt.
		$vars          = self::resolve_vars( $campaign, $overrides );
		$merged_prompt = self::merge_prompt( (string) $template['base_prompt'], $vars );

		// Model + size resolution.
		$model = ! empty( $overrides['model'] )
			? sanitize_text_field( (string) $overrides['model'] )
			: (string) $template['recommended_model'];
		if ( ! array_key_exists( $model, BizCity_Tool_Image::MODELS ) ) {
			$model = 'flux-pro';
		}

		$size = ! empty( $overrides['size'] )
			? sanitize_text_field( (string) $overrides['size'] )
			: ( self::ASPECT_TO_SIZE[ (string) $template['target_aspect'] ] ?? '1024x1024' );

		// Reference image: template default, override allowed.
		$ref_url = ! empty( $overrides['ref_image_url'] )
			? esc_url_raw( (string) $overrides['ref_image_url'] )
			: (string) ( $template['ref_image_url'] ?? '' );

		// Open audit row BEFORE calling LLM so we can correlate failures.
		$gen_id = self::insert_generation_row( $campaign_id, $template_id, $model, $merged_prompt, $overrides );
		if ( $gen_id <= 0 ) {
			return new WP_Error( 'bzcrm_print_ads_db', 'Failed to insert generation row.' );
		}

		// Call tool-image. Use 'reference' mode when we have a ref image, else 'text'.
		$slots = array(
			'creation_mode' => $ref_url !== '' ? 'reference' : 'text',
			'prompt'        => $merged_prompt,
			'model'         => $model,
			'size'          => $size,
			'style'         => 'auto',
			'user_id'       => get_current_user_id(),
			'_meta'         => array(
				'session_id' => 'crm-print-ads:gen-' . $gen_id,
				'caller'     => 'bzcrm_print_ads',
			),
		);
		if ( $ref_url !== '' ) {
			$slots['image_url']  = $ref_url;
			$slots['ref_images'] = array( $ref_url );
		}

		$result = BizCity_Tool_Image::generate_image( $slots );

		if ( empty( $result['success'] ) ) {
			$err = isset( $result['message'] ) ? (string) $result['message'] : 'image generation failed';
			self::finish_generation_row( $gen_id, 'failed', null, $err );
			return new WP_Error( 'bzcrm_print_ads_gen_failed', $err, array( 'generation_id' => $gen_id ) );
		}

		$attachment_id = isset( $result['data']['attachment_id'] ) ? (int) $result['data']['attachment_id'] : 0;
		$image_url     = (string) ( $result['data']['image_url'] ?? $result['data']['url'] ?? '' );

		self::finish_generation_row( $gen_id, 'ok', $attachment_id ?: null, null );

		// Tag the attachment so we can list / inverse-lookup later.
		if ( $attachment_id > 0 ) {
			update_post_meta( $attachment_id, '_bzcrm_campaign_id',   $campaign_id );
			update_post_meta( $attachment_id, '_bzcrm_template_id',   $template_id );
			update_post_meta( $attachment_id, '_bzcrm_generation_id', $gen_id );
		}

		return array(
			'generation_id' => $gen_id,
			'attachment_id' => $attachment_id ?: null,
			'image_url'     => $image_url,
			'thumb_url'     => $attachment_id
				? ( wp_get_attachment_image_url( $attachment_id, 'medium' ) ?: $image_url )
				: $image_url,
			'model'         => $model,
			'size'          => $size,
			'merged_prompt' => $merged_prompt,
		);
	}
}
