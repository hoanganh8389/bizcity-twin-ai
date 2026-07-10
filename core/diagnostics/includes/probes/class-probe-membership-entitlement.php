<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 *
 * Probe: core.membership.entitlement — Health of the client-side membership
 * core (PHASE-MEMBERSHIP). Validates the 3-layer R-DDV evidence:
 *
 *   Layer 1 — DISK:    Manager + Plan Registry + Entitlement + Usage classes load.
 *   Layer 2 — LOADER:  bizcity_membership_plans parses → ≥ 1 valid plan; a 'free'
 *                      plan exists; subscriptions + usage tables exist.
 *   Layer 3 — RUNTIME: for_user(current) returns site_tier + user_plan + clamped
 *                      limits; assert kg_passages_per_day ≤ site-tier ceiling.
 *
 * Read-only — never assigns a plan or mutates user data.
 *
 * @since 2026-06-04 (PHASE-MEMBERSHIP M8)
 *
 * M7 (2026-06-04): + expiry cron step.
 * M7-recurring (2026-06-04): + PayPal Subscriptions v2 wiring (loader) and
 *   provisioned-count (runtime, SKIP until admin provisions plans).
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( ! interface_exists( 'BizCity_Diagnostics_Probe' ) ) {
	require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';
}

class BizCity_Probe_Membership_Entitlement implements BizCity_Diagnostics_Probe {

	public function id(): string          { return 'core.membership.entitlement'; }
	public function label(): string       { return 'Membership · entitlement & plans'; }
	public function description(): string {
		return 'Kiểm tra core membership: class load, plan registry, 3 bảng (subscriptions/usage/payments), expiry cron, PayPal Subscriptions v2 recurring wiring, và merge trần site × plan user qua for_user().';
	}
	public function severity(): string    { return 'warning'; }
	public function order(): int          { return 60; }
	public function icon(): string        { return 'CreditCard'; }
	public function estimate_ms(): int    { return 150; }

	public function precondition() {
		// Layer 1 — DISK.
		$need = array(
			'BizCity_Membership_Manager',
			'BizCity_Membership_Plan_Registry',
			'BizCity_Membership_Entitlement',
			'BizCity_Membership_Usage',
		);
		foreach ( $need as $cls ) {
			if ( ! class_exists( $cls ) ) {
				return new WP_Error( 'no_class', "{$cls} chưa load — kiểm tra core/membership/bootstrap.php." );
			}
		}
		return true;
	}

	public function run( $ctx ): array {
		global $wpdb;
		$steps = array();

		/* ── Layer 1 — DISK ─────────────────────────────────────────────── */
		$steps[] = array(
			'label'  => 'disk · classes',
			'status' => 'pass',
			'detail' => 'Manager + Registry + Entitlement + Usage loaded',
		);

		/* ── Layer 2 — LOADER ───────────────────────────────────────────── */
		$registry = BizCity_Membership_Plan_Registry::instance();
		$plans    = $registry->all();
		$plan_ok  = is_array( $plans ) && count( $plans ) >= 1;
		$has_free = isset( $plans['free'] );

		$steps[] = array(
			'label'  => 'loader · plan registry',
			'status' => ( $plan_ok && $has_free ) ? 'pass' : 'fail',
			'detail' => sprintf( '%d plan · free=%s', is_array( $plans ) ? count( $plans ) : 0, $has_free ? 'yes' : 'no' ),
		);

		$sub_table   = $wpdb->prefix . 'bizcity_member_subscriptions';
		$usage_table = $wpdb->prefix . 'bizcity_member_usage';
		$pay_table   = $wpdb->prefix . 'bizcity_member_payments';
		$wpdb->suppress_errors( true );
		$sub_exists   = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $sub_table ) );
		$usage_exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $usage_table ) );
		$pay_exists   = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $pay_table ) );
		$wpdb->suppress_errors( false );

		$steps[] = array(
			'label'  => 'loader · tables',
			'status' => ( $sub_exists && $usage_exists && $pay_exists ) ? 'pass' : 'fail',
			'detail' => sprintf(
				'%s=%s · %s=%s · %s=%s',
				'subscriptions', $sub_exists ? 'ok' : 'MISSING',
				'usage', $usage_exists ? 'ok' : 'MISSING',
				'payments', $pay_exists ? 'ok' : 'MISSING'
			),
		);

		// [2026-06-04 Johnny Chu] PHASE-MEMBERSHIP M7 — expiry sweep cron present.
		$cron_class = class_exists( 'BizCity_Membership_Cron' );
		$cron_sched = false;
		if ( $cron_class ) {
			$cron_sched = (bool) wp_next_scheduled( BizCity_Membership_Cron::HOOK );
		}
		$steps[] = array(
			'label'  => 'loader · expiry cron',
			'status' => ( $cron_class && $cron_sched ) ? 'pass' : 'fail',
			'detail' => $cron_class
				? ( $cron_sched ? 'membership.expiry scheduled (daily)' : 'class ok nhưng chưa schedule (chờ init hook)' )
				: 'BizCity_Membership_Cron chưa load',
		);

		// [2026-06-04 Johnny Chu] PHASE-MEMBERSHIP M7-recurring — PayPal Subscriptions v2 wiring.
		// Disk: gateway recurring methods + Manager/Registry persistence helpers exist.
		$gw_methods = array( 'provision_plan', 'provision_all', 'create_subscription', 'activate_subscription', 'handle_recurring_payment' );
		$gw_ok = class_exists( 'BizCity_Membership_PayPal_Gateway' );
		$gw_missing = array();
		if ( $gw_ok ) {
			foreach ( $gw_methods as $m ) {
				if ( ! method_exists( 'BizCity_Membership_PayPal_Gateway', $m ) ) {
					$gw_missing[] = $m;
				}
			}
		}
		$mgr_ok = method_exists( 'BizCity_Membership_Manager', 'extend_by_paypal_subscription' );
		$reg_ok = method_exists( 'BizCity_Membership_Plan_Registry', 'set_paypal_plan_id' );
		$recurring_ok = $gw_ok && empty( $gw_missing ) && $mgr_ok && $reg_ok;
		$steps[] = array(
			'label'  => 'loader · recurring (subscriptions v2)',
			'status' => $recurring_ok ? 'pass' : 'fail',
			'detail' => $recurring_ok
				? 'gateway provision/create/activate/renew + Manager.extend + Registry.set_paypal_plan_id'
				: sprintf(
					'gateway=%s%s · Manager.extend=%s · Registry.set_paypal_plan_id=%s',
					$gw_ok ? 'ok' : 'MISSING',
					$gw_missing ? ' (missing: ' . implode( ',', $gw_missing ) . ')' : '',
					$mgr_ok ? 'ok' : 'MISSING',
					$reg_ok ? 'ok' : 'MISSING'
				),
		);

		// Runtime: count recurring plans + how many already provisioned on PayPal.
		$recurring_plans = 0;
		$provisioned     = 0;
		if ( is_array( $plans ) ) {
			foreach ( $plans as $p ) {
				$price = isset( $p['price'] ) ? (float) $p['price'] : 0.0;
				$cycle = isset( $p['billing_cycle'] ) ? (string) $p['billing_cycle'] : '';
				if ( $price > 0 && $cycle !== '' && $cycle !== 'once' ) {
					$recurring_plans++;
					if ( ! empty( $p['paypal_plan_id'] ) ) {
						$provisioned++;
					}
				}
			}
		}
		// SKIP (not fail) when no plan provisioned yet — provisioning is an admin action.
		$prov_status = ( $recurring_plans === 0 )
			? 'skip'
			: ( $provisioned === $recurring_plans ? 'pass' : 'skip' );
		$steps[] = array(
			'label'  => 'runtime · recurring provisioned',
			'status' => $prov_status,
			'detail' => sprintf(
				'%d/%d recurring plan có paypal_plan_id%s',
				$provisioned,
				$recurring_plans,
				( $recurring_plans > 0 && $provisioned < $recurring_plans )
					? ' — bấm "Provision PayPal plans" ở Settings'
					: ''
			),
		);

		/* ── Layer 3 — RUNTIME ──────────────────────────────────────────── */
		$uid = get_current_user_id();
		$ent = BizCity_Membership_Entitlement::instance()->for_user( $uid );

		$has_shape = is_array( $ent )
			&& isset( $ent['site_tier'] )
			&& isset( $ent['user_plan'] )
			&& isset( $ent['limits'] )
			&& is_array( $ent['limits'] );

		$steps[] = array(
			'label'  => 'runtime · for_user()',
			'status' => $has_shape ? 'pass' : 'fail',
			'detail' => $has_shape
				? sprintf( 'uid=%d · site_tier=%s · user_plan=%s', $uid, $ent['site_tier'], $ent['user_plan'] )
				: 'for_user() trả về shape không hợp lệ',
		);

		// Assert clamp: kg_passages_per_day ≤ site-tier ceiling (if ceiling defined).
		$clamp_ok = true;
		$clamp_detail = 'no ceiling for tier (unlimited)';
		if ( $has_shape ) {
			$ceiling = $registry->site_tier_ceiling( $ent['site_tier'] );
			if ( isset( $ceiling['kg_passages_per_day'] ) ) {
				$eff = isset( $ent['limits']['kg_passages_per_day'] ) ? (int) $ent['limits']['kg_passages_per_day'] : 0;
				$cap = (int) $ceiling['kg_passages_per_day'];
				$clamp_ok = $eff <= $cap;
				$clamp_detail = sprintf( 'kg/day eff=%d ≤ ceiling=%d → %s', $eff, $cap, $clamp_ok ? 'ok' : 'VIOLATION' );
			}
		}
		$steps[] = array(
			'label'  => 'runtime · min(plan, ceiling)',
			'status' => $clamp_ok ? 'pass' : 'fail',
			'detail' => $clamp_detail,
		);

		/* ── Verdict ────────────────────────────────────────────────────── */
		$fail = false;
		foreach ( $steps as $s ) {
			if ( $s['status'] === 'fail' ) {
				$fail = true;
				break;
			}
		}

		if ( $fail ) {
			$hint = ( ! $sub_exists || ! $usage_exists || ! $pay_exists )
				? 'Mở Tools → BizCity Diagnostics → Site Provisioner để auto-create bảng membership.'
				: 'Kiểm tra core/membership: registry option bizcity_membership_plans + for_user() merge.';
			return array(
				'status'   => 'fail',
				'summary'  => 'Membership core có vấn đề ở 1 layer.',
				'fix_hint' => $hint,
				'steps'    => $steps,
			);
		}

		return array(
			'status'  => 'pass',
			'summary' => sprintf(
				'%d plan · tier=%s · plan=%s · clamp ok',
				count( $plans ),
				$has_shape ? $ent['site_tier'] : '?',
				$has_shape ? $ent['user_plan'] : '?'
			),
			'steps'   => $steps,
		);
	}

	public function cleanup(): void {
		// Read-only probe — nothing to clean.
	}
}

// [2026-06-05 Johnny Chu] PHASE-MEMBERSHIP M8 — register probe
add_filter( 'bizcity_diagnostics_register_probes', function ( array $list ): array {
	$list[] = new BizCity_Probe_Membership_Entitlement();
	return $list;
} );
