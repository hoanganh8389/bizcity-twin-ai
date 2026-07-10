<?php
/**
 * BizCity CRM — Order Public Tracking Controller (Phase 0.38 W3.2).
 *
 * Registers rewrite rule `/o/<token>` and renders the public tracking page.
 * No login required — accessible by anyone with the link (token acts as bearer).
 *
 * Flow:
 *   1. `init@11`  — add_rewrite_rule + add_rewrite_tag
 *   2. `template_redirect` — catch query var, resolve token → order_id, render template
 *
 * Template path (filterable):
 *   plugins/bizcity-twin-crm/includes/woo/templates/order-public-tracking.php
 *
 * @package    BizCity_Twin_CRM\Woo
 * @since      PHASE-0.38.W3.2 (2026-06-07)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Order_Public_Controller' ) ) { return; }

// [2026-06-07 Johnny Chu] PHASE-0.38.W3.2 — public tracking page controller /o/<token>
final class BizCity_CRM_Order_Public_Controller {

	const QUERY_VAR = 'bizcity_order_token';

	public static function boot(): void {
		add_action( 'init',              array( __CLASS__, 'register_rewrite' ), 11 );
		add_action( 'template_redirect', array( __CLASS__, 'handle_request' ),  1 );
		add_filter( 'query_vars',        array( __CLASS__, 'add_query_var' ) );
	}

	public static function register_rewrite(): void {
		add_rewrite_rule(
			'^o/([A-Za-z0-9]{8,32})/?$',
			'index.php?' . self::QUERY_VAR . '=$matches[1]',
			'top'
		);
		add_rewrite_tag( '%' . self::QUERY_VAR . '%', '([A-Za-z0-9]{8,32})' );
	}

	public static function add_query_var( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	public static function handle_request(): void {
		$token = get_query_var( self::QUERY_VAR, '' );
		if ( $token === '' ) {
			return;
		}

		// Resolve token to order.
		if ( ! class_exists( 'BizCity_CRM_Order_Public_Token' ) ) {
			wp_die( 'Trang theo dõi đơn hàng chưa sẵn sàng.', 404 );
		}
		$order_id = BizCity_CRM_Order_Public_Token::resolve( $token );
		if ( $order_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
			// Token invalid or expired — show 404.
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
			include get_query_template( '404' );
			exit;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
			include get_query_template( '404' );
			exit;
		}

		// Set HTTP headers.
		nocache_headers();
		status_header( 200 );

		// Load template.
		$template = apply_filters(
			'bizcity_crm_order_tracking_template',
			__DIR__ . '/templates/order-public-tracking.php'
		);

		if ( ! file_exists( $template ) ) {
			wp_die( 'Template theo dõi đơn hàng không tìm thấy.', 500 );
		}

		// Pass $order and $token to template scope.
		include $template;
		exit;
	}
}
