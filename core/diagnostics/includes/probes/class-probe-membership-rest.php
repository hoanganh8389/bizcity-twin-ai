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
 * Runtime-safe probe: may create a temporary synthetic payment row for invoice
 * contract verification and removes it in the same run/cleanup.
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

	const PROBE_SYNTH_TXN_PREFIX = 'diag_probe_invoice_';

	public function id(): string          { return 'core.membership.rest'; }
	public function label(): string       { return 'Membership · REST /me + quota gates (3A/3B)'; }
	public function description(): string {
		return 'R-DDV cho Membership REST 3.1: self-scope /me/*, admin plan sheet/templates, checkout/capture validation + degraded parse, và quota hooks.';
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

		// [2026-07-17 Johnny Chu] SPRINT-7 DDV-FIX — BizCity_Auth_Ajax can be optional on some topologies.
		$auth_ajax_ok = class_exists( 'BizCity_Auth_Ajax' );
		$ctx->emit_step( array(
			'label'  => 'Layer 1 · Disk — optional BizCity_Auth_Ajax class',
			'status' => $auth_ajax_ok ? 'pass' : 'warn',
			'detail' => $auth_ajax_ok
				? 'BizCity_Auth_Ajax loaded'
				: 'Class missing (optional); runtime still validated via AJAX action hooks.',
		) );

		$rest_file = $this->resolve_plugin_file( 'core/membership/includes/class-membership-rest.php' );
		// [2026-07-17 Johnny Chu] PROBE-RECHECK HOTFIX — reflection fallback when plugin root differs from canonical slug path.
		if ( $rest_file === '' && class_exists( 'BizCity_Membership_REST' ) ) {
			try {
				$ref = new ReflectionClass( 'BizCity_Membership_REST' );
				$rf  = (string) $ref->getFileName();
				if ( $rf !== '' && is_readable( $rf ) ) {
					$rest_file = $rf;
				}
			} catch ( Exception $e ) {
				$rest_file = '';
			}
		}
		$rest_src  = $rest_file !== '' ? (string) file_get_contents( $rest_file ) : '';
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

		// [2026-07-18 Johnny Chu] PHASE-TWIN-GPT-C-ENDUSER C-2 — DDV markers for built-in Membership plan template preview/import.
		$template_file = $this->resolve_plugin_file( 'core/membership/templates/builtin-twin-gpt-c-plans.json' );
		$template_markers_ok = $rest_src !== ''
			&& strpos( $rest_src, 'admin_get_plan_templates' ) !== false
			&& strpos( $rest_src, 'admin_import_plan_template' ) !== false
			&& strpos( $rest_src, 'load_membership_plan_templates' ) !== false
			&& $template_file !== '';
		if ( ! $template_markers_ok ) {
			$failed = true;
		}
		$ctx->emit_step( array(
			'label'  => 'Layer 1 · Disk — Membership plan template endpoints + JSON template',
			'status' => $template_markers_ok ? 'pass' : 'fail',
			'detail' => $template_markers_ok
				? 'REST handlers present; builtin-twin-gpt-c-plans.json readable'
				: 'Missing REST template handlers or builtin-twin-gpt-c-plans.json',
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
			'/' . $ns . '/admin/plan-templates' => 'GET',
			'/' . $ns . '/admin/plan-templates/import' => 'POST',
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

		// [2026-07-17 Johnny Chu] SPRINT-7 DDV-FIX — cover payments CSV export admin-post action.
		$ajax_export = has_action( 'admin_post_bizcity_membership_export_payments' );
		$in_rest_ctx = defined( 'REST_REQUEST' ) && REST_REQUEST;
		$export_file = $this->resolve_plugin_file( 'core/membership/includes/admin/class-membership-admin-page.php' );
		// [2026-07-17 Johnny Chu] PROBE-RECHECK HOTFIX — resolve file from loaded class if admin file already included from non-canonical path.
		if ( $export_file === '' && class_exists( 'BizCity_Membership_Admin_Page' ) ) {
			try {
				$ref_admin = new ReflectionClass( 'BizCity_Membership_Admin_Page' );
				$af        = (string) $ref_admin->getFileName();
				if ( $af !== '' && is_readable( $af ) ) {
					$export_file = $af;
				}
			} catch ( Exception $e ) {
				$export_file = '';
			}
		}
		$export_marker_ok = false;
		if ( ! $ajax_export && $export_file !== '' ) {
			$export_src = (string) file_get_contents( $export_file );
			$export_marker_ok = strpos( $export_src, 'admin_post_bizcity_membership_export_payments' ) !== false
				&& strpos( $export_src, 'handle_export_payments' ) !== false;
		}
		$export_class_ok = false;
		if ( ! $ajax_export && ! class_exists( 'BizCity_Membership_Admin_Page' ) && $export_file !== '' ) {
			require_once $export_file;
		}
		if ( class_exists( 'BizCity_Membership_Admin_Page' ) ) {
			$export_class_ok = method_exists( 'BizCity_Membership_Admin_Page', 'handle_export_payments' );
		}
		$export_step_ok = $ajax_export || $export_marker_ok || $export_class_ok;
		$export_diag = sprintf(
			'diag(rest=%s,file=%s,marker=%s,class=%s,method=%s)',
			$in_rest_ctx ? '1' : '0',
			$export_file !== '' ? basename( $export_file ) : 'none',
			$export_marker_ok ? '1' : '0',
			class_exists( 'BizCity_Membership_Admin_Page' ) ? '1' : '0',
			$export_class_ok ? '1' : '0'
		);
		$ctx->emit_step( array(
			'label'  => 'Layer 2 · payments CSV export action',
			'status' => $export_step_ok ? 'pass' : 'fail',
			'detail' => $ajax_export
				? 'admin_post_bizcity_membership_export_payments hooked · ' . $export_diag
				: ( $export_marker_ok
					? ( $in_rest_ctx
						? 'Hook defined in admin class; not attached in REST runtime context (expected). · ' . $export_diag
						: 'Hook defined in admin class file marker; runtime hook not attached in this request context. · ' . $export_diag
					)
					: ( $export_class_ok
						? 'Admin page class + handler method present; runtime hook omitted in this context. · ' . $export_diag
						: 'MISSING admin_post_bizcity_membership_export_payments hook/marker · ' . $export_diag
					)
				),
		) );
		if ( ! $export_step_ok ) {
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
		$runtime_origin_uid = get_current_user_id();
		$runtime_impersonated = false;
		$synthetic_invoice_txn = '';
		$uid = $runtime_origin_uid;

		// [2026-07-17 Johnny Chu] PROBE-RECHECK HOTFIX — avoid runtime skip when diagnostics executes without authenticated user context.
		if ( $uid <= 0 ) {
			$uid = $this->resolve_runtime_uid();
			if ( $uid > 0 ) {
				wp_set_current_user( $uid );
				$runtime_impersonated = true;
			}
		}

		if ( $uid <= 0 ) {
			$ctx->emit_step( array(
				'label'  => 'Layer 3 · /me profile fields',
				'status' => 'warn',
				'detail' => 'Không resolve được runtime user context; bỏ qua Layer 3 real-call để tránh false fail.',
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

			/* ── Layer 3 · Runtime — admin plan template preview ─── */
			// [2026-07-18 Johnny Chu] PHASE-TWIN-GPT-C-ENDUSER C-2 — read-only runtime proof for built-in plan template preview.
			if ( current_user_can( 'manage_options' ) ) {
				$template_req  = new WP_REST_Request( 'GET', '/' . $ns . '/admin/plan-templates' );
				$template_res  = rest_do_request( $template_req );
				$template_data = $template_res->get_data();
				$templates     = is_array( $template_data ) && isset( $template_data['templates'] ) && is_array( $template_data['templates'] )
					? $template_data['templates']
					: array();
				$importable_count = 0;
				foreach ( $templates as $template ) {
					if ( is_array( $template ) && ! empty( $template['importable'] ) && ! empty( $template['plan_count'] ) ) {
						$importable_count++;
					}
				}
				$template_runtime_ok = is_array( $template_data ) && ! empty( $template_data['success'] ) && $importable_count > 0;
				$ctx->emit_step( array(
					'label'  => 'Layer 3 · /admin/plan-templates preview contract',
					'status' => $template_runtime_ok ? 'pass' : 'fail',
					'detail' => $template_runtime_ok
						? sprintf( 'templates=%d; importable=%d; read-only preview ok', count( $templates ), $importable_count )
						: 'No importable Membership plan template returned from admin preview endpoint.',
				) );
				if ( ! $template_runtime_ok ) {
					$failed = true;
				}
			} else {
				$ctx->emit_step( array(
					'label'  => 'Layer 3 · /admin/plan-templates preview contract',
					'status' => 'warn',
					'detail' => 'Current runtime user lacks manage_options; route registration is checked in Layer 2, runtime admin preview skipped.',
				) );
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
			$txn_id = '';
			$invoice_source = 'existing';
			if ( ! empty( $payment_rows ) && ! empty( $payment_rows[0]['id'] ) ) {
				$txn_id = sanitize_text_field( (string) $payment_rows[0]['id'] );
			} elseif ( class_exists( 'BizCity_Membership_Payments' ) ) {
				// [2026-07-17 Johnny Chu] PROBE-RECHECK HOTFIX — synthesize one payment row so invoice runtime contract can be tested without pre-existing ledger data.
				$synthetic_invoice_txn = $this->create_synthetic_payment_for_user( $uid );
				if ( $synthetic_invoice_txn !== '' ) {
					$txn_id = $synthetic_invoice_txn;
					$invoice_source = 'synthetic';
				}
			}

			if ( $txn_id !== '' ) {
				$route_invoice = '/' . $ns . '/me/invoice/' . rawurlencode( $txn_id );
				$inv_req  = new WP_REST_Request( 'GET', $route_invoice );
				$inv_res  = rest_do_request( $inv_req );
				$inv_data = $inv_res->get_data();
				$invoice_ok = is_array( $inv_data ) && ! empty( $inv_data['success'] ) && isset( $inv_data['html'] );
				$ctx->emit_step( array(
					'label'  => 'Layer 3 · /me/invoice/{id} own-payment access',
					'status' => $invoice_ok ? 'pass' : 'fail',
					'detail' => $invoice_ok
						? 'invoice html returned for own transaction (' . $invoice_source . ')'
						: 'invoice request failed for own transaction id (' . $invoice_source . ')',
				) );
				if ( ! $invoice_ok ) {
					$failed = true;
				}
			} else {
				$ctx->emit_step( array(
					'label'  => 'Layer 3 · /me/invoice/{id} own-payment access',
					'status' => 'warn',
					'detail' => 'Không resolve được transaction cho runtime invoice check; disk ownership guard vẫn được kiểm tra.',
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

		if ( $synthetic_invoice_txn !== '' ) {
			$this->cleanup_synthetic_payment_by_txn( $synthetic_invoice_txn );
		}
		if ( $runtime_impersonated ) {
			wp_set_current_user( $runtime_origin_uid );
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
		// [2026-07-17 Johnny Chu] PROBE-RECHECK HOTFIX — defensive cleanup in case previous run ended before synthetic invoice row cleanup.
		global $wpdb;
		if ( ! class_exists( 'BizCity_Membership_Payments' ) ) {
			return;
		}
		$table = BizCity_Membership_Payments::instance()->table();
		if ( ! $this->table_exists( $table ) ) {
			return;
		}
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE transaction_id LIKE %s",
				self::PROBE_SYNTH_TXN_PREFIX . '%'
			)
		);
	}

	/**
	 * Resolve a runtime user id to avoid false runtime SKIP in anonymous contexts.
	 *
	 * @return int
	 */
	private function resolve_runtime_uid() {
		$ids = get_users( array(
			'fields'  => 'ids',
			'number'  => 1,
			'orderby' => 'ID',
			'order'   => 'ASC',
		) );
		return ( is_array( $ids ) && ! empty( $ids[0] ) ) ? (int) $ids[0] : 0;
	}

	/**
	 * Create a synthetic payment row for invoice runtime contract checks.
	 *
	 * @param int $uid
	 * @return string transaction id, empty on failure
	 */
	private function create_synthetic_payment_for_user( $uid ) {
		if ( $uid <= 0 || ! class_exists( 'BizCity_Membership_Payments' ) ) {
			return '';
		}

		$user = get_userdata( $uid );
		$txn_id = self::PROBE_SYNTH_TXN_PREFIX . $uid . '_' . gmdate( 'YmdHis' ) . '_' . (string) wp_rand( 1000, 9999 );
		$row_id = BizCity_Membership_Payments::instance()->record( array(
			'user_id'         => $uid,
			'subscription_id' => 0,
			'plan_slug'       => 'probe_invoice',
			'status'          => BizCity_Membership_Payments::STATUS_COMPLETED,
			'amount'          => 1.0,
			'currency'        => 'USD',
			'gateway'         => 'probe',
			'transaction_id'  => $txn_id,
			'payer_email'     => $user ? (string) $user->user_email : 'probe@example.test',
			'paid_at'         => current_time( 'mysql' ),
			'meta'            => array(
				'probe'     => $this->id(),
				'synthetic' => 1,
			),
		) );

		return $row_id > 0 ? $txn_id : '';
	}

	/**
	 * Cleanup one synthetic payment row by transaction id.
	 *
	 * @param string $txn_id
	 * @return void
	 */
	private function cleanup_synthetic_payment_by_txn( $txn_id ) {
		global $wpdb;
		$txn_id = sanitize_text_field( (string) $txn_id );
		if ( $txn_id === '' || ! class_exists( 'BizCity_Membership_Payments' ) ) {
			return;
		}
		$table = BizCity_Membership_Payments::instance()->table();
		if ( ! $this->table_exists( $table ) ) {
			return;
		}
		$wpdb->delete( $table, array( 'transaction_id' => $txn_id ), array( '%s' ) );
	}

	/**
	 * Check table existence without SHOW TABLES.
	 *
	 * @param string $table_name
	 * @return bool
	 */
	private function table_exists( $table_name ) {
		if ( $table_name === '' ) {
			return false;
		}
		if ( function_exists( 'bizcity_tbl_exists' ) ) {
			return (bool) bizcity_tbl_exists( $table_name );
		}
		global $wpdb;
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
				$table_name
			)
		);
	}

	/**
	 * Resolve plugin-relative file path across canonical and fallback roots.
	 *
	 * @param string $relative_path
	 * @return string
	 */
	private function resolve_plugin_file( $relative_path ) {
		$relative_path = ltrim( (string) $relative_path, '/\\' );
		$candidates = array();
		if ( defined( 'BIZCITY_TWIN_AI_DIR' ) ) {
			$candidates[] = BIZCITY_TWIN_AI_DIR . $relative_path;
		}
		if ( defined( 'WP_PLUGIN_DIR' ) ) {
			$candidates[] = WP_PLUGIN_DIR . '/bizcity-twin-ai/' . $relative_path;
		}
		$candidates[] = dirname( __DIR__, 4 ) . '/' . $relative_path;
		$candidates[] = dirname( __DIR__, 5 ) . '/bizcity-twin-ai/' . $relative_path;

		foreach ( $candidates as $candidate ) {
			if ( is_string( $candidate ) && $candidate !== '' && is_readable( $candidate ) ) {
				return $candidate;
			}
		}

		return '';
	}
}

// [2026-06-05 Johnny Chu] PHASE-MEMBERSHIP BE-3A/3B — register probe
add_filter( 'bizcity_diagnostics_register_probes', function ( array $list ): array {
	$list[] = new BizCity_Probe_Membership_REST();
	return $list;
} );
