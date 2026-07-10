<?php
/**
 * PHASE 0.37.2 — Generic programmatic adapter.
 *
 * Cho code khác push lead bằng:
 *   do_action( 'bizcity_capture_lead', [
 *       'email'   => 'a@b.com',
 *       'phone'   => '0900xxxxxx',
 *       'full_name' => 'Nguyen Van A',
 *       'message' => 'Tôi muốn báo giá',
 *       'meta'    => [ 'source_url' => '...', 'utm' => [...] ],
 *   ], 'wpforms:42' );
 *
 * @package BizCity\CRM\LeadCapture
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class BizCity_CRM_Lead_Source_Generic {

	public static function register(): void {
		add_action( 'bizcity_capture_lead', array( __CLASS__, 'on_capture' ), 10, 2 );
	}

	public static function on_capture( $data, $source = 'generic' ): void {
		if ( ! is_array( $data ) ) { return; }
		$src = $source ? (string) $source : 'generic';
		$res = BizCity_CRM_Lead_Capture_Engine::capture( $data, $src );
		if ( is_wp_error( $res ) && function_exists( 'error_log' ) ) {
			error_log( '[BizCity CRM] Generic capture failed: ' . $res->get_error_message() );
		}
	}
}
