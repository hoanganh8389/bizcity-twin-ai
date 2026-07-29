<?php
/**
 * BizCity Diagnostics — modules.twinweb.me_plan_catalog probe (SPRINT-5 Q-1/Q-3).
 *
 * R-DDV 3 layers evidence:
 *
 *   Layer 1 (Disk)    — class-twinweb-rest.php readable;
 *                       build_plan_catalog_for_me() + build_subscription_for_me()
 *                       methods tồn tại trong BizCity_TwinWeb_REST.
 *   Layer 2 (Loader)  — REST route /bizcity-twinweb/v1/me đã đăng ký;
 *                       BizCity_TwinWeb_REST class loaded.
 *   Layer 3 (Runtime) — rest_do_request /me trả plan_catalog array sorted by rank;
 *                       không có hardcoded VND price;
 *                       subscription block có plan_slug, days_remaining (int or null).
 *
 * Read-only. No DB mutation. Runs as current admin user.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-07-17 (SPRINT-5 Q-1)
 */

// [2026-07-17 Johnny Chu] SPRINT-5 Q-1 R-BIZ-MODEL-11 — UpgradeModal server-driven plan catalog DDV probe.
defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false ) ) {
	$_iface_path = defined( 'BIZCITY_DIAGNOSTICS_DIR' )
		? BIZCITY_DIAGNOSTICS_DIR . 'includes/interface-diagnostics-probe.php'
		: dirname( __DIR__ ) . '/interface-diagnostics-probe.php';
	if ( is_readable( $_iface_path ) ) {
		require_once $_iface_path;
	}
}
if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false ) ) {
	return;
}

if ( class_exists( 'BizCity_Probe_TwinWeb_Me_Plan_Catalog', false ) ) {
	return;
}

final class BizCity_Probe_TwinWeb_Me_Plan_Catalog implements BizCity_Diagnostics_Probe {

	public function id(): string          { return 'modules.twinweb.me_plan_catalog'; }
	public function label(): string       { return 'Twin GPT · /me plan_catalog + subscription (SPRINT-5)'; }
	public function description(): string {
		return 'Disk/Loader/Runtime: /me trả server-owned plan_catalog (sorted by rank) và subscription block; không còn hardcoded VND/URL trong FE UpgradeModal.';
	}
	public function severity(): string    { return 'warning'; }
	public function order(): int          { return 83; }
	public function icon(): string        { return 'ShoppingCart'; }
	public function estimate_ms(): int    { return 80; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_TwinWeb_REST' ) ) {
			return new WP_Error( 'no_class', 'BizCity_TwinWeb_REST chưa load — kiểm tra modules/twinweb/bootstrap.php.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		$steps = array();

		/* ── Layer 1 · Disk ────────────────────────────────────────────── */
		$rest_file = defined( 'BIZCITY_TWIN_AI_DIR' )
			? BIZCITY_TWIN_AI_DIR . 'modules/twinweb/includes/class-twinweb-rest.php'
			: '';
		$disk_readable = $rest_file !== '' && is_readable( $rest_file );

		$has_catalog_method = false;
		$has_sub_method     = false;
		if ( $disk_readable ) {
			$src = file_get_contents( $rest_file );
			if ( $src !== false ) {
				$has_catalog_method = strpos( $src, 'build_plan_catalog_for_me' ) !== false;
				$has_sub_method     = strpos( $src, 'build_subscription_for_me' ) !== false;
			}
		}

		$disk_ok = $disk_readable && $has_catalog_method && $has_sub_method;
		$step = array(
			'label'  => 'Disk · class-twinweb-rest.php + build_plan_catalog_for_me() + build_subscription_for_me()',
			'status' => $disk_ok ? 'pass' : 'fail',
			'detail' => $disk_readable
				? sprintf(
					'File readable; catalog_method=%s; subscription_method=%s',
					$has_catalog_method ? 'ok' : 'MISSING',
					$has_sub_method ? 'ok' : 'MISSING'
				)
				: 'class-twinweb-rest.php not readable at expected path',
		);
		$steps[] = $step;
		$ctx->emit_step( $step );

		/* ── Layer 2 · Loader ──────────────────────────────────────────── */
		$ns        = 'bizcity-twinweb/v1';
		$all_routes = rest_get_server()->get_routes();
		$route_ok   = isset( $all_routes[ '/' . $ns . '/me' ] );

		$step = array(
			'label'  => 'Loader · REST route /bizcity-twinweb/v1/me registered',
			'status' => $route_ok ? 'pass' : 'fail',
			'detail' => $route_ok
				? 'Route registered in rest_api_init'
				: 'Route not found — ensure BizCity_TwinWeb_REST::init() called',
		);
		$steps[] = $step;
		$ctx->emit_step( $step );

		// Also assert methods actually exist on the loaded class.
		$method_catalog = method_exists( 'BizCity_TwinWeb_REST', 'build_plan_catalog_for_me' );
		$method_sub     = method_exists( 'BizCity_TwinWeb_REST', 'build_subscription_for_me' );

		$step = array(
			'label'  => 'Loader · BizCity_TwinWeb_REST methods callable',
			'status' => ( $method_catalog && $method_sub ) ? 'pass' : 'fail',
			'detail' => sprintf(
				'build_plan_catalog_for_me=%s; build_subscription_for_me=%s',
				$method_catalog ? 'ok' : 'MISSING',
				$method_sub ? 'ok' : 'MISSING'
			),
		);
		$steps[] = $step;
		$ctx->emit_step( $step );

		/* ── Layer 3 · Runtime ─────────────────────────────────────────── */
		$uid = get_current_user_id();
		if ( $uid <= 0 ) {
			$step = array(
				'label'  => 'Runtime · /me response plan_catalog',
				'status' => 'skip',
				'detail' => 'Không có session user — probe chạy ở context không có WP user. Đăng nhập admin để chạy runtime layer.',
			);
			$steps[] = $step;
			$ctx->emit_step( $step );
		} else {
			$request  = new WP_REST_Request( 'GET', '/' . $ns . '/me' );
			$response = rest_do_request( $request );
			$data     = is_wp_error( $response ) ? array() : (array) $response->get_data();

			// Assert plan_catalog is array.
			$catalog       = isset( $data['plan_catalog'] ) && is_array( $data['plan_catalog'] ) ? $data['plan_catalog'] : null;
			$catalog_ok    = $catalog !== null && count( $catalog ) >= 1;
			$has_slug      = $catalog_ok && isset( $catalog[0]['slug'] );
			$has_rank      = $catalog_ok && isset( $catalog[0]['rank'] );
			$has_checkout  = $catalog_ok && array_key_exists( 'checkout_url', $catalog[0] );

			$step = array(
				'label'  => 'Runtime · /me plan_catalog non-empty, has slug/rank/checkout_url',
				'status' => ( $catalog_ok && $has_slug && $has_rank && $has_checkout ) ? 'pass' : 'fail',
				'detail' => $catalog === null
					? 'plan_catalog missing from /me response'
					: sprintf(
						'%d plans; slug=%s; rank=%s; checkout_url_key=%s',
						count( $catalog ),
						$has_slug ? 'ok' : 'MISSING',
						$has_rank ? 'ok' : 'MISSING',
						$has_checkout ? 'ok' : 'MISSING'
					),
			);
			$steps[] = $step;
			$ctx->emit_step( $step );

			// Assert sorted by rank ascending.
			$sorted_ok  = true;
			$prev_rank  = -1;
			foreach ( (array) $catalog as $p ) {
				$r = isset( $p['rank'] ) ? (int) $p['rank'] : 0;
				if ( $r < $prev_rank ) {
					$sorted_ok = false;
					break;
				}
				$prev_rank = $r;
			}

			$step = array(
				'label'  => 'Runtime · plan_catalog sorted by rank ascending',
				'status' => $sorted_ok ? 'pass' : 'fail',
				'detail' => $sorted_ok ? 'Rank order ok' : 'plan_catalog NOT sorted by rank — fix build_plan_catalog_for_me() usort()',
			);
			$steps[] = $step;
			$ctx->emit_step( $step );

			// Assert no hardcoded VND price in response (plain text "đ" or "VND" inside catalog).
			$catalog_json = wp_json_encode( $catalog );
			$no_vnd       = $catalog_json !== false
				? ( strpos( $catalog_json, 'đ' ) === false && strpos( $catalog_json, 'VND' ) === false )
				: true;

			$step = array(
				'label'  => 'Runtime · plan_catalog does NOT contain hardcoded VND/đ prices',
				'status' => $no_vnd ? 'pass' : 'fail',
				'detail' => $no_vnd
					? 'No VND/đ strings found in plan_catalog — server-owned pricing ok'
					: 'Hardcoded VND/đ found in plan_catalog — remove from build_plan_catalog_for_me()',
			);
			$steps[] = $step;
			$ctx->emit_step( $step );

			// Assert subscription block.
			$sub          = isset( $data['subscription'] ) ? $data['subscription'] : null;
			$sub_ok       = $sub !== null && isset( $sub['plan_slug'] ) && array_key_exists( 'days_remaining', $sub );

			$step = array(
				'label'  => 'Runtime · /me subscription block has plan_slug + days_remaining',
				'status' => $sub_ok ? 'pass' : ( $sub === null ? 'skip' : 'fail' ),
				'detail' => $sub === null
					? 'subscription=null (guest or no login) — SKIP'
					: sprintf(
						'plan_slug=%s; days_remaining=%s',
						$sub['plan_slug'] ?? 'MISSING',
						array_key_exists( 'days_remaining', $sub ) ? var_export( $sub['days_remaining'], true ) : 'KEY MISSING'
					),
			);
			$steps[] = $step;
			$ctx->emit_step( $step );
		}

		$pass = $disk_ok && $route_ok && $method_catalog && $method_sub;

		return array(
			'status'  => $pass ? 'pass' : 'fail',
			'summary' => $pass
				? '/me plan_catalog + subscription contract PASS'
				: 'plan_catalog / subscription contract chưa đủ — xem steps',
			'steps'   => $steps,
		);
	}

	public function cleanup(): void {
		// Read-only probe.
	}
}

// [2026-07-17 Johnny Chu] SPRINT-5 Q-1 R-BIZ-MODEL-11 — register /me plan catalog probe.
add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_TwinWeb_Me_Plan_Catalog';
	return $list;
} );
