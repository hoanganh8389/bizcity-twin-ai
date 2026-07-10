<?php
/**
 * BizCity Diagnostics — core.membership.rest probe
 *
 * [2026-06-05 Johnny Chu] PHASE-MEMBERSHIP BE-3A/3B — R-DDV evidence for:
 *   • REST /membership/me (extended profile: first_name/last_name/phone/bio)
 *   • REST /membership/me/payments
 *   • REST /membership/me/cancel
 *   • AJAX bizcity_ajax_update_profile + bizcity_ajax_change_password handlers
 *   • Chat quota gate filter bizcity_twinchat_can_send_message (hooked by enforcer)
 *   • Chat usage increment action bizcity_twinchat_message_sent (hooked by enforcer)
 *   • Usage snapshot: chat_msgs_per_day remaining from BizCity_Membership_Usage
 *
 * 3-layer R-DDV:
 *   Layer 1 (Disk)    — REST controller + AJAX handler + Enforcer classes exist on disk.
 *   Layer 2 (Loader)  — REST routes registered, AJAX actions hooked, Enforcer filters hooked.
 *   Layer 3 (Runtime) — rest_do_request /me returns profile.{first_name, last_name, phone, bio};
 *                       usage snapshot has chat_msgs_per_day; filters have > 0 callbacks.
 *
 * Read-only — never modifies user data.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-06-05 (PHASE-MEMBERSHIP BE-3A/3B)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( ! interface_exists( 'BizCity_Diagnostics_Probe' ) ) {
	require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';
}

class BizCity_Probe_Membership_REST implements BizCity_Diagnostics_Probe {

	public function id(): string          { return 'core.membership.rest'; }
	public function label(): string       { return 'Membership · REST /me + quota gates (3A/3B)'; }
	public function description(): string {
		return 'R-DDV cho Membership REST 3.1: self-scope /me/*, parity contract (/me,/me/profile,/me/payments,/me/invoice/{id},/me/cancel), checkout/capture validation + degraded parse, và quota hooks.';
	}
	public function severity(): string    { return 'warning'; }
	public function order(): int          { return 61; }
	public function icon(): string        { return 'Shield'; }
	public function estimate_ms(): int    { return 200; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_Membership_REST' ) ) {
			return new WP_Error( 'no_rest_class', 'BizCity_Membership_REST chưa load — kiểm tra core/membership/bootstrap.php.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-07-09 Johnny Chu] PHASE-TWINSHELL-IMPL — expand probe scope to
		// full Membership REST 3.1 checklist (route parity + runtime parse contract).
		$failed = false;

		/* ── Layer 1 · Disk ──────────────────────────────────────────── */
		$classes = array(
			'BizCity_Membership_REST',
			'BizCity_Membership_Enforcer',
			'BizCity_Membership_Usage',
			'BizCity_Auth_Ajax',
		);
		$missing = array();
		foreach ( $classes as $cls ) {
			if ( ! class_exists( $cls ) ) {
				$missing[] = $cls;
			}
		}
		$disk_ok = empty( $missing );
		if ( ! $disk_ok ) {
			$failed = true;
		}
		$ctx->emit_step( array(
			'label'  => 'Layer 1 · Disk — classes',
			'status' => $disk_ok ? 'pass' : 'fail',
			'detail' => $disk_ok
				? implode( ' · ', $classes ) . ' — all loaded'
				: 'MISSING: ' . implode( ', ', $missing ),
		) );

		$rest_file = WP_PLUGIN_DIR . '/bizcity-twin-ai/core/membership/includes/class-membership-rest.php';
		$rest_src  = file_exists( $rest_file ) ? (string) file_get_contents( $rest_file ) : '';
		$owner_guard_ok = $rest_src !== ''
			&& strpos( $rest_src, 'if ( (int) $payment[\'user_id\'] !== $uid )' ) !== false;
		if ( ! $owner_guard_ok ) {
			$failed = true;
		}
		$ctx->emit_step( array(
			'label'  => 'Layer 1 · Disk — invoice ownership guard',
			'status' => $owner_guard_ok ? 'pass' : 'fail',
			'detail' => $owner_guard_ok
				? 'me_invoice() has explicit owner check payment.user_id === current uid'
				: 'owner guard marker missing in class-membership-rest.php',
		) );

		/* ── Layer 2 · Loader — REST routes ────────────────────────── */
		// [2026-07-09 Johnny Chu] PHASE-TWINSHELL-IMPL — route/method parity for
		// account hub + pricing APIs consumed by FE.
		$rest_server  = rest_get_server();
		$routes       = $rest_server->get_routes();
		$ns           = 'bizcity-membership/v1';
		$route_expect = array(
			'/' . $ns . '/me' => 'GET',
			'/' . $ns . '/me/profile' => 'POST',
			'/' . $ns . '/me/payments' => 'GET',
			'/' . $ns . '/me/invoice/(?P<id>[A-Za-z0-9_\-]+)' => 'GET',
			'/' . $ns . '/me/cancel' => 'POST',
			'/' . $ns . '/checkout' => 'POST',
			'/' . $ns . '/capture' => 'POST',
		);

		$route_missing = array();
		foreach ( $route_expect as $route_key => $must_method ) {
			if ( ! isset( $routes[ $route_key ] ) ) {
				$route_missing[] = $route_key . ' (missing route)';
				continue;
			}
			$methods = array();
			foreach ( (array) $routes[ $route_key ] as $ep ) {
				if ( ! is_array( $ep ) || empty( $ep['methods'] ) || ! is_array( $ep['methods'] ) ) {
					continue;
				}
				foreach ( $ep['methods'] as $m => $enabled ) {
					if ( $enabled ) {
						$methods[] = strtoupper( (string) $m );
					}
				}
			}
			$methods = array_values( array_unique( $methods ) );
			if ( ! in_array( strtoupper( $must_method ), $methods, true ) ) {
				$route_missing[] = $route_key . ' missing ' . strtoupper( $must_method );
			}
		}
		$route_ok = empty( $route_missing );
		if ( ! $route_ok ) {
			$failed = true;
		}

		$ctx->emit_step( array(
			'label'  => 'Layer 2 · REST route-method parity (/me*, /checkout, /capture)',
			'status' => $route_ok ? 'pass' : 'fail',
			'detail' => $route_ok
				? 'all expected routes + methods are registered'
				: 'issues=' . implode( '; ', $route_missing ),
		) );

		/* ── Layer 2 · Loader — AJAX handlers ──────────────────────── */
		$ajax_update   = has_action( 'wp_ajax_bizcity_ajax_update_profile' );
		$ajax_password = has_action( 'wp_ajax_bizcity_ajax_change_password' );

		$ctx->emit_step( array(
			'label'  => 'Layer 2 · AJAX handlers',
			'status' => ( $ajax_update && $ajax_password ) ? 'pass' : 'fail',
			'detail' => sprintf(
				'update_profile=%s · change_password=%s',
				$ajax_update   ? 'hooked' : 'MISSING',
				$ajax_password ? 'hooked' : 'MISSING'
			),
		) );
		if ( ! ( $ajax_update && $ajax_password ) ) {
			$failed = true;
		}

		/* ── Layer 2 · Loader — Enforcer hooks ─────────────────────── */
		$filter_chat    = has_filter( 'bizcity_twinchat_can_send_message' );
		$action_chat    = has_action( 'bizcity_twinchat_message_sent' );
		$filter_kg      = has_filter( 'bizcity_kg_quota_per_user' );

		$ctx->emit_step( array(
			'label'  => 'Layer 2 · Enforcer hooks',
			'status' => ( $filter_chat && $action_chat && $filter_kg ) ? 'pass' : 'fail',
			'detail' => sprintf(
				'can_send_msg=%s · msg_sent=%s · kg_quota=%s',
				$filter_chat ? 'hooked' : 'MISSING',
				$action_chat ? 'hooked' : 'MISSING',
				$filter_kg   ? 'hooked' : 'MISSING'
			),
		) );
		if ( ! ( $filter_chat && $action_chat && $filter_kg ) ) {
			$failed = true;
		}

		/* ── Layer 3 · Runtime — /me profile fields ─────────────────── */
		$prof_key_count = 0;
		$uid = get_current_user_id();
		if ( $uid <= 0 ) {
			$ctx->emit_step( array(
				'label'  => 'Layer 3 · /me profile fields',
				'status' => 'skip',
				'detail' => 'Không có session user — probe chạy ở context không có WP user. Đăng nhập để chạy runtime layer.',
			) );
		} else {
			$route_me = '/' . $ns . '/me';
			$request  = new WP_REST_Request( 'GET', $route_me );
			$response = rest_do_request( $request );
			$data     = $response->get_data();

			$me_ok_rt  = is_array( $data ) && ! empty( $data['success'] );
			$profile   = is_array( $data ) ? ( $data['profile'] ?? array() ) : array();
			$prof_keys = array( 'display_name', 'first_name', 'last_name', 'email', 'phone', 'bio', 'avatar_url', 'registered' );
			$prof_key_count = count( $prof_keys );
			$prof_miss = array();
			foreach ( $prof_keys as $k ) {
				if ( ! array_key_exists( $k, $profile ) ) {
					$prof_miss[] = $k;
				}
			}
			$prof_ok = empty( $prof_miss );

			$ctx->emit_step( array(
				'label'  => sprintf( 'Layer 3 · /me profile fields (uid=%d)', $uid ),
				'status' => ( $me_ok_rt && $prof_ok ) ? 'pass' : ( $me_ok_rt ? 'fail' : 'fail' ),
				'detail' => ( $me_ok_rt && $prof_ok )
					? sprintf(
						'display="%s" · first="%s" · last="%s" · phone="%s" · bio=%s',
						$profile['display_name'] ?? '',
						$profile['first_name']   ?? '',
						$profile['last_name']    ?? '',
						$profile['phone']        ?? '',
						isset( $profile['bio'] ) ? ( strlen( $profile['bio'] ) . ' chars' ) : 'empty'
					)
					: ( ! $me_ok_rt
						? 'REST /me failed: ' . ( is_array( $data ) ? wp_json_encode( $data ) : 'null' )
						: 'profile{} missing keys: ' . implode( ', ', $prof_miss )
					),
			) );

			if ( ! ( $me_ok_rt && $prof_ok ) ) {
				$failed = true;
			}

			/* ── Layer 3 · Runtime — usage snapshot ─────────────────── */
			$usage   = is_array( $data ) ? ( $data['usage'] ?? array() ) : array();
			$has_chat = array_key_exists( 'chat_msgs_per_day', $usage );

			if ( $has_chat ) {
				$row = $usage['chat_msgs_per_day'];
				$ctx->emit_step( array(
					'label'  => 'Layer 3 · usage.chat_msgs_per_day',
					'status' => 'pass',
					'detail' => sprintf(
						'used=%d · limit=%d · remaining=%d',
						$row['used']      ?? 0,
						$row['limit']     ?? -1,
						$row['remaining'] ?? -1
					),
				) );
			} else {
				$ctx->emit_step( array(
					'label'  => 'Layer 3 · usage.chat_msgs_per_day',
					'status' => 'warn',
					'detail' => 'Key không có trong /me usage — kiểm tra BizCity_Membership_Usage::snapshot()',
				) );
			}

			/* ── Layer 3 · Runtime — chat quota gate synthetic check ─── */
			// Apply the filter ourselves to verify enforcer fires correctly for current user.
			$can = apply_filters( 'bizcity_twinchat_can_send_message', true, $uid );
			$gate_ok = ( $can === true ) || is_wp_error( $can );
			$ctx->emit_step( array(
				'label'  => sprintf( 'Layer 3 · quota gate (uid=%d)', $uid ),
				'status' => $gate_ok ? 'pass' : 'fail',
				'detail' => is_wp_error( $can )
					? sprintf( 'quota_exceeded — code=%s · plan=%s', $can->get_error_code(), $can->get_error_data()['plan'] ?? '?' )
					: ( $can === true
						? ( $has_chat ? sprintf( 'remaining=%d → allowed', $usage['chat_msgs_per_day']['remaining'] ?? -1 ) : 'allowed (no usage row)' )
						: 'unexpected return: ' . wp_json_encode( $can )
					),
			) );
			if ( ! $gate_ok ) {
				$failed = true;
			}

			/* ── Layer 3 · Runtime — payments/invoice self-scope contract ─── */
			$route_payments = '/' . $ns . '/me/payments';
			$pay_req  = new WP_REST_Request( 'GET', $route_payments );
			$pay_res  = rest_do_request( $pay_req );
			$pay_data = $pay_res->get_data();
			$payments_ok = is_array( $pay_data ) && array_key_exists( 'success', $pay_data ) && array_key_exists( 'payments', $pay_data );
			$ctx->emit_step( array(
				'label'  => sprintf( 'Layer 3 · /me/payments contract (uid=%d)', $uid ),
				'status' => $payments_ok ? 'pass' : 'fail',
				'detail' => $payments_ok
					? 'success + payments keys present'
					: 'invalid /me/payments response shape',
			) );
			if ( ! $payments_ok ) {
				$failed = true;
			}

			$payment_rows = ( $payments_ok && is_array( $pay_data['payments'] ) ) ? $pay_data['payments'] : array();
			if ( ! empty( $payment_rows ) && ! empty( $payment_rows[0]['id'] ) ) {
				$txn_id = sanitize_text_field( (string) $payment_rows[0]['id'] );
				$route_invoice = '/' . $ns . '/me/invoice/' . rawurlencode( $txn_id );
				$inv_req  = new WP_REST_Request( 'GET', $route_invoice );
				$inv_res  = rest_do_request( $inv_req );
				$inv_data = $inv_res->get_data();
				$invoice_ok = is_array( $inv_data ) && ! empty( $inv_data['success'] ) && isset( $inv_data['html'] );
				$ctx->emit_step( array(
					'label'  => 'Layer 3 · /me/invoice/{id} own-payment access',
					'status' => $invoice_ok ? 'pass' : 'fail',
					'detail' => $invoice_ok ? 'invoice html returned for own transaction' : 'invoice request failed for own transaction id',
				) );
				if ( ! $invoice_ok ) {
					$failed = true;
				}
			} else {
				$ctx->emit_step( array(
					'label'  => 'Layer 3 · /me/invoice/{id} own-payment access',
					'status' => 'skip',
					'detail' => 'Không có payment row để thử invoice runtime; disk ownership guard vẫn được kiểm tra.',
				) );
			}

			/* ── Layer 3 · Runtime — checkout/capture degrade parse contract ─── */
			$checkout_req = new WP_REST_Request( 'POST', '/' . $ns . '/checkout' );
			$checkout_res = rest_do_request( $checkout_req );
			$checkout_data = $checkout_res->get_data();
			$checkout_contract_ok = is_array( $checkout_data )
				&& array_key_exists( 'success', $checkout_data )
				&& isset( $checkout_data['message'] );
			$ctx->emit_step( array(
				'label'  => 'Layer 3 · /checkout invalid-input parse contract',
				'status' => $checkout_contract_ok ? 'pass' : 'fail',
				'detail' => $checkout_contract_ok
					? 'invalid input still returns parseable success/message contract'
					: 'invalid /checkout response shape',
			) );
			if ( ! $checkout_contract_ok ) {
				$failed = true;
			}

			$capture_req = new WP_REST_Request( 'POST', '/' . $ns . '/capture' );
			$capture_res = rest_do_request( $capture_req );
			$capture_data = $capture_res->get_data();
			$capture_contract_ok = is_array( $capture_data )
				&& array_key_exists( 'success', $capture_data )
				&& isset( $capture_data['message'] );
			$ctx->emit_step( array(
				'label'  => 'Layer 3 · /capture invalid-input parse contract',
				'status' => $capture_contract_ok ? 'pass' : 'fail',
				'detail' => $capture_contract_ok
					? 'invalid input still returns parseable success/message contract'
					: 'invalid /capture response shape',
			) );
			if ( ! $capture_contract_ok ) {
				$failed = true;
			}
		}

		/* ── Verdict ──────────────────────────────────────────────────── */
		if ( $failed ) {
			return array(
				'status'   => 'fail',
				'summary'  => 'Membership REST 3.1 có mismatch ở route/contract/self-scope — xem các Layer FAIL ở trên.',
				'fix_hint' => 'Kiểm tra core/membership/includes/class-membership-rest.php (route /me/* + owner guard invoice + checkout/capture contract) và bootstrap init order.',
			);
		}

		return array(
			'status'  => 'pass',
			'summary' => sprintf(
				'Membership REST parity PASS · /me* + checkout/capture contract + self-scope guard ok (uid=%d, profile_keys=%d)',
				$uid,
				$prof_key_count
			),
		);
	}

	public function cleanup(): void {
		// Read-only probe — nothing to clean.
	}
}

// [2026-06-05 Johnny Chu] PHASE-MEMBERSHIP BE-3A/3B — register probe
add_filter( 'bizcity_diagnostics_register_probes', function ( array $list ): array {
	$list[] = new BizCity_Probe_Membership_REST();
	return $list;
} );
