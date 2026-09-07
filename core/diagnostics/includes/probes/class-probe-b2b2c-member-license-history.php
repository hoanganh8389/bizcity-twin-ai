<?php
/**
 * Disposable H6 fixture for member-owned license purchase history.
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false ) ) {
	return;
}

if ( class_exists( 'BizCity_Probe_B2B2C_Member_License_History', false ) ) {
	return;
}

final class BizCity_Probe_B2B2C_Member_License_History implements BizCity_Diagnostics_Probe {

	const FIXTURE_SOURCE = 'diagnostics_h6_member_history';

	public function id(): string {
		// [2026-09-04 11:50 AM Johnny Chu - Chu Hoàng Anh] B2C-H6 - identify member-owned purchase history contract.
		return 'b2b2c.account.member_license_history';
	}

	public function label(): string {
		return 'B2B2C member license history';
	}

	public function description(): string {
		return 'Verifies owner-scoped ledger history, exact-key denial, bounded pagination/date filters, safe fields and expiry enrichment.';
	}

	public function severity(): string {
		return 'critical';
	}

	public function order(): int {
		return 27;
	}

	public function icon(): string {
		return 'receipt-text';
	}

	public function estimate_ms(): int {
		return 260;
	}

	public function precondition() {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( preg_replace( '/:\d+$/', '', (string) $_SERVER['HTTP_HOST'] ) ) : '';
		if ( ! in_array( $host, array( 'bizcity.vn', 'www.bizcity.vn' ), true ) ) {
			return 'not_applicable_b2_client: member license history is owned by bizcity.vn.';
		}
		if ( ! class_exists( 'BizCity_Router_Account' ) || ! class_exists( 'BizCity_Router_License_Ledger' ) ) {
			return new WP_Error( 'member_history_loader_missing', 'Account REST or license ledger is not loaded.' );
		}
		if ( ! function_exists( 'bizcity_tbl_exists' ) || ! bizcity_tbl_exists( BizCity_Router_License_Ledger::table_name() ) ) {
			return 'ledger_runtime_missing: member history requires the Global license ledger table.';
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-09-04 11:50 AM Johnny Chu - Chu Hoàng Anh] B2C-H6 - exercise owner filtering and bounded member history with disposable ledger state.
		$failures = array();
		$uid      = (int) get_current_user_id();
		if ( $uid <= 0 ) {
			return array( 'status' => 'fail', 'summary' => 'No authenticated Hub user is available for member history ownership checks.', 'error' => 'hub_user_missing', 'fix_hint' => 'Run the probe in the Hub diagnostics admin context.' );
		}
		$detail_loader_ok = method_exists( 'BizCity_Router_Account', 'handle_license_order_detail' )
			&& method_exists( 'BizCity_Router_Account', 'handle_license_summary' )
			&& method_exists( 'BizCity_Router_License_Ledger', 'get_member_purchase_order' )
			&& method_exists( 'BizCity_Router_License_Ledger', 'get_member_license_summary' );
		$ctx->emit_step( array( 'label' => 'Loader - detail and summary owner', 'status' => $detail_loader_ok ? 'pass' : 'fail', 'detail' => $detail_loader_ok ? 'Account REST detail/summary handlers and Global ledger readers are loaded.' : 'H6 detail or summary owner methods are missing.' ) );
		if ( ! $detail_loader_ok ) {
			return array( 'status' => 'fail', 'summary' => 'H6 detail/summary loader contract is incomplete.', 'error' => 'detail_summary_loader_missing', 'fix_hint' => 'Load the Account REST detail/summary handlers and ledger readers before rerunning the focused H6 probe.' );
		}

		global $wpdb;
		$key = $wpdb->get_row( $wpdb->prepare( "SELECT id, user_id, allowed_domain FROM {$wpdb->base_prefix}bizcity_llm_api_keys WHERE user_id = %d AND is_active = 1 ORDER BY id ASC LIMIT 1", $uid ), ARRAY_A );
		$foreign_key = $wpdb->get_row( $wpdb->prepare( "SELECT id, user_id FROM {$wpdb->base_prefix}bizcity_llm_api_keys WHERE user_id <> %d AND is_active = 1 ORDER BY id ASC LIMIT 1", $uid ), ARRAY_A );
		if ( ! is_array( $key ) || absint( $key['id'] ?? 0 ) <= 0 || ! is_array( $foreign_key ) || absint( $foreign_key['id'] ?? 0 ) <= 0 ) {
			return array( 'status' => 'warn', 'summary' => 'Owned and foreign active keys are required for the disposable H6 ownership fixture.', 'error' => 'fixture_keys_missing', 'fix_hint' => 'Provide one active key for the diagnostics user and one active key for another user, then rerun the focused H6 probe.' );
		}

		$key_id       = absint( $key['id'] );
		$order_id     = 980000000 + mt_rand( 1000, 999999 );
		$item_id      = 880000000 + mt_rand( 1000, 999999 );
		$idempotency  = 'diag_h6:' . strtolower( str_replace( '-', '', wp_generate_uuid4() ) );
		$fixture_date = gmdate( 'Y-m-d' );
		$start        = gmdate( 'Y-m-d H:i:s' );
		$end          = gmdate( 'Y-m-d H:i:s', time() + ( 30 * DAY_IN_SECONDS ) );
		$append       = BizCity_Router_License_Ledger::append_grant( array(
			'event_uuid'              => wp_generate_uuid4(),
			'issuer_hub_id'           => 'bizcity',
			'commerce_hub_id'         => 'bizcity',
			'woo_site_id'             => function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 1,
			'woo_order_id'            => $order_id,
			'woo_order_item_id'       => $item_id,
			'key_id'                  => $key_id,
			'owner_user_id'           => $uid,
			'allowed_domain_snapshot' => 'diagnostics-h6.example.com',
			'plan_code'               => 'master_pro',
			'offer_product_id'        => 999991,
			'offer_variation_id'      => 0,
			'duration_days'           => 30,
			'quantity'                => 1,
			'period_start_at'         => $start,
			'period_end_at'           => $end,
			'currency'                => 'USD',
			'gross_amount'            => 19,
			'event_type'              => 'grant',
			'event_status'            => 'applied',
			'idempotency_key'         => $idempotency,
			'source'                  => self::FIXTURE_SOURCE,
			'applied_at'              => gmdate( 'Y-m-d H:i:s' ),
		) );
		$append_ok = is_array( $append ) && ! empty( $append['success'] );
		$ctx->emit_step( array( 'label' => 'Runtime - disposable member purchase', 'status' => $append_ok ? 'pass' : 'fail', 'detail' => $append_ok ? 'Created one disposable exact-key grant for the authenticated member.' : 'Could not create the disposable H6 ledger grant.' ) );
		if ( ! $append_ok ) {
			return array( 'status' => 'fail', 'summary' => 'H6 fixture grant creation failed.', 'error' => 'fixture_grant_append_failed', 'fix_hint' => 'Verify the Global ledger insert path and rerun the focused H6 probe.' );
		}

		try {
			$from_request = new WP_REST_Request( 'GET', '/bizcity/v1/account/license-orders' );
			$from_request->set_param( 'key_id', $key_id );
			$from_request->set_param( 'page', 1 );
			$from_request->set_param( 'per_page', 500 );
			$from_request->set_param( 'from', $fixture_date );
			$from_request->set_param( 'to', $fixture_date );
			$response = BizCity_Router_Account::handle_license_orders( $from_request );
			$data     = $response instanceof WP_REST_Response ? $response->get_data() : array();
			$bounded  = $response instanceof WP_REST_Response && 200 === (int) $response->get_status() && 50 === (int) ( $data['per_page'] ?? 0 ) && (int) ( $data['total'] ?? 0 ) >= 1 && ! empty( $data['orders'] );
			$ctx->emit_step( array( 'label' => 'Runtime - owner list and bounds', 'status' => $bounded ? 'pass' : 'fail', 'detail' => $bounded ? 'Authenticated owner received the fixture with per_page capped at 50 and date bounds applied.' : 'Owner list or pagination/date bounds did not meet the contract.' ) );
			if ( ! $bounded ) {
				$failures[] = 'owner_list_or_bounds_failed';
			}

			$safe = true;
			foreach ( (array) ( $data['orders'] ?? array() ) as $order ) {
				foreach ( array( 'key_hash', 'api_key', 'bearer', 'secret' ) as $forbidden ) {
					if ( array_key_exists( $forbidden, (array) $order ) ) {
						$safe = false;
					}
				}
				if ( ! array_key_exists( 'current_expiry', (array) $order ) ) {
					$safe = false;
				}
			}
			$ctx->emit_step( array( 'label' => 'Runtime - safe purchase projection', 'status' => $safe ? 'pass' : 'fail', 'detail' => $safe ? 'History response contains bounded commerce fields, expiry enrichment and no credential/hash fields.' : 'History response exposed an unsafe field or omitted expiry enrichment.' ) );
			if ( ! $safe ) {
				$failures[] = 'unsafe_history_projection';
			}

			$detail_request = new WP_REST_Request( 'GET', '/bizcity/v1/account/license-orders/' . $order_id );
			// [2026-09-06 04:20 PM Johnny Chu - Chu Hoàng Anh] B2C-H6 — direct handler fixtures must provide the route parameter explicitly because WP_REST_Request does not resolve regex route params outside REST dispatch.
			$detail_request->set_param( 'order_id', $order_id );
			$detail_response = BizCity_Router_Account::handle_license_order_detail( $detail_request );
			$detail_data     = $detail_response instanceof WP_REST_Response ? $detail_response->get_data() : array();
			$detail_ok       = $detail_response instanceof WP_REST_Response && 200 === (int) $detail_response->get_status() && (int) ( $detail_data['order_id'] ?? 0 ) === $order_id && ! empty( $detail_data['items'] );
			$ctx->emit_step( array( 'label' => 'Runtime - owned order detail', 'status' => $detail_ok ? 'pass' : 'fail', 'detail' => $detail_ok ? 'Authenticated owner received bounded detail for the fixture order.' : 'Owned order detail did not return the expected safe fixture projection.' ) );
			if ( ! $detail_ok ) {
				$failures[] = 'order_detail_failed';
			}

			$summary_request = new WP_REST_Request( 'GET', '/bizcity/v1/account/license-summary' );
			$summary_request->set_param( 'key_id', $key_id );
			$summary_response = BizCity_Router_Account::handle_license_summary( $summary_request );
			$summary_data     = $summary_response instanceof WP_REST_Response ? $summary_response->get_data() : array();
			$summary_ok       = $summary_response instanceof WP_REST_Response && 200 === (int) $summary_response->get_status() && (int) ( $summary_data['key_id'] ?? 0 ) === $key_id && isset( $summary_data['summary']['order_count'], $summary_data['summary']['current_expiry'] );
			$ctx->emit_step( array( 'label' => 'Runtime - exact-key license summary', 'status' => $summary_ok ? 'pass' : 'fail', 'detail' => $summary_ok ? 'Authenticated owner received bounded exact-key totals and current expiry.' : 'Exact-key summary did not return the expected safe totals/expiry projection.' ) );
			if ( ! $summary_ok ) {
				$failures[] = 'license_summary_failed';
			}

			$foreign_request = new WP_REST_Request( 'GET', '/bizcity/v1/account/license-orders' );
			$foreign_request->set_param( 'key_id', absint( $foreign_key['id'] ) );
			$foreign_response = BizCity_Router_Account::handle_license_orders( $foreign_request );
			$foreign_data     = $foreign_response instanceof WP_REST_Response ? $foreign_response->get_data() : array();
			$foreign_denied   = $foreign_response instanceof WP_REST_Response && 403 === (int) $foreign_response->get_status() && 'permission_denied' === (string) ( $foreign_data['code'] ?? '' );
			$ctx->emit_step( array( 'label' => 'Runtime - foreign exact-key denial', 'status' => $foreign_denied ? 'pass' : 'fail', 'detail' => $foreign_denied ? 'A key owned by another member was rejected before ledger reads.' : 'Foreign exact-key request was not denied with the expected ownership envelope.' ) );
			if ( ! $foreign_denied ) {
				$failures[] = 'foreign_key_not_denied';
			}
		} finally {
			$deleted = $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . BizCity_Router_License_Ledger::table_name() . ' WHERE source = %s AND key_id = %d AND owner_user_id = %d AND woo_order_id = %d AND woo_order_item_id = %d', self::FIXTURE_SOURCE, $key_id, $uid, $order_id, $item_id ) );
			if ( false === $deleted ) {
				$failures[] = 'fixture_cleanup_failed';
			}
			if ( class_exists( 'BizCity_Cache' ) ) {
				BizCity_Cache::flush_group( BizCity_Router_License_Ledger::CACHE_GROUP );
			}
			$ctx->emit_step( array( 'label' => 'Fixture - full cleanup', 'status' => false === $deleted ? 'fail' : 'pass', 'detail' => false === $deleted ? 'Disposable H6 ledger row could not be removed.' : 'Disposable H6 ledger row was removed and ledger cache was flushed.' ) );
		}

		if ( ! empty( $failures ) ) {
			return array( 'status' => 'fail', 'summary' => 'Member license history failed: ' . implode( ', ', array_unique( $failures ) ), 'error' => implode( '; ', array_unique( $failures ) ), 'fix_hint' => 'Keep Account REST ownership before ledger reads, cap pagination/date ranges and return only safe purchase fields; rerun the focused H6 probe.' );
		}
		return array( 'status' => 'pass', 'summary' => 'Authenticated member history passed owner filtering, exact-key denial, pagination/date bounds, safe projection and cleanup.' );
	}

	public function cleanup(): void {
		// Fixture cleanup is completed in finally; no persisted H6 state is retained.
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_B2B2C_Member_License_History';
	return $list;
} );
