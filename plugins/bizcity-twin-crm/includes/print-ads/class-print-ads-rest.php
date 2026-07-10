<?php
/**
 * BizCity CRM — Print-Ads REST routes (M-PA.W2).
 *
 * All routes mounted under `bizcity-crm/v1` (existing CRM namespace).
 *
 *   GET  /campaigns/{id}/print-ads/templates   — list active templates (?type=voucher|print_ad|qr_card|business_card|event_invite)
 *   POST /campaigns/{id}/print-ads/generate    — body { template_id, overrides:{ cta_text, discount, custom_detail, model, size, ref_image_url } }
 *   GET  /campaigns/{id}/print-ads             — list past generations for the campaign (?limit=50)
 *
 * Permission: reuses BizCity_CRM_REST_Controller::can_write() (edit_posts cap)
 * when available; falls back to manage_options.
 *
 * @package BizCity_Twin_CRM
 * @since   0.32.3 (M-PA.W2)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Print_Ads_REST', false ) ) { return; }

final class BizCity_CRM_Print_Ads_REST {

	const NS = 'bizcity-crm/v1';

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function permission(): bool {
		if ( class_exists( 'BizCity_CRM_REST_Controller' )
			&& method_exists( 'BizCity_CRM_REST_Controller', 'can_write' ) ) {
			return BizCity_CRM_REST_Controller::can_write();
		}
		return current_user_can( 'manage_options' );
	}

	public static function register_routes(): void {
		register_rest_route( self::NS, '/campaigns/(?P<id>\d+)/print-ads/templates', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'list_templates' ),
			'permission_callback' => array( __CLASS__, 'permission' ),
			'args'                => array(
				'type'   => array( 'type' => 'string', 'required' => false ),
				'source' => array( 'type' => 'string', 'required' => false ),
			),
		) );

		register_rest_route( self::NS, '/campaigns/(?P<id>\d+)/print-ads/generate', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'generate' ),
			'permission_callback' => array( __CLASS__, 'permission' ),
			'args'                => array(
				'template_id' => array( 'type' => 'integer', 'required' => true ),
				'overrides'   => array( 'type' => 'object',  'required' => false ),
			),
		) );

		register_rest_route( self::NS, '/campaigns/(?P<id>\d+)/print-ads', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'list_generations' ),
			'permission_callback' => array( __CLASS__, 'permission' ),
			'args'                => array(
				'limit' => array( 'type' => 'integer', 'required' => false, 'default' => 50 ),
			),
		) );
	}

	/* ─────────────────────────────────────────────────────────────
	 * Handlers
	 * ───────────────────────────────────────────────────────────── */

	public static function list_templates( WP_REST_Request $req ) {
		$args = array();
		$type = (string) $req->get_param( 'type' );
		if ( $type !== '' ) { $args['template_type'] = $type; }
		$source = (string) $req->get_param( 'source' );
		if ( $source !== '' ) { $args['source'] = $source; }

		$rows = BizCity_CRM_Print_Ads_Composer::list_templates( $args );

		// Slim payload for the modal grid.
		$out = array_map( static function ( array $r ): array {
			return array(
				'id'                => (int) $r['id'],
				'slug'              => (string) $r['slug'],
				'template_type'     => (string) $r['template_type'],
				'title'             => (string) $r['title'],
				'description'       => (string) ( $r['description'] ?? '' ),
				'target_aspect'     => (string) $r['target_aspect'],
				'recommended_model' => (string) $r['recommended_model'],
				'ref_image_url'     => (string) ( $r['ref_image_url'] ?? '' ),
				'qr_slot'           => $r['qr_slot']    ?? null,
				'brand_slot'        => $r['brand_slot'] ?? null,
				'source'            => (string) $r['source'],
			);
		}, $rows );

		return rest_ensure_response( array(
			'ok'        => true,
			'templates' => $out,
			'count'     => count( $out ),
		) );
	}

	public static function generate( WP_REST_Request $req ) {
		$campaign_id = (int) $req->get_param( 'id' );
		$template_id = (int) $req->get_param( 'template_id' );
		$overrides   = (array) ( $req->get_param( 'overrides' ) ?: array() );

		if ( $campaign_id <= 0 || $template_id <= 0 ) {
			return new WP_Error( 'bzcrm_print_ads_bad_request', 'campaign_id and template_id required', array( 'status' => 400 ) );
		}

		$res = BizCity_CRM_Print_Ads_Composer::generate( $campaign_id, $template_id, $overrides );
		if ( is_wp_error( $res ) ) {
			$status = $res->get_error_code() === 'bzcrm_print_ads_rate_limited' ? 429 : 500;
			$res->add_data( array( 'status' => $status ) );
			return $res;
		}

		return rest_ensure_response( array_merge( array( 'ok' => true ), $res ) );
	}

	public static function list_generations( WP_REST_Request $req ) {
		$campaign_id = (int) $req->get_param( 'id' );
		$limit       = (int) $req->get_param( 'limit' );
		if ( $limit <= 0 ) { $limit = 50; }

		$rows = BizCity_CRM_Print_Ads_Composer::list_generations( $campaign_id, $limit );

		return rest_ensure_response( array(
			'ok'          => true,
			'generations' => $rows,
			'count'       => count( $rows ),
		) );
	}
}
