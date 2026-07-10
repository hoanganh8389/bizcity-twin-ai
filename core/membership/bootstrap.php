<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Membership
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 *
 * core/membership — Client-side Membership Plans (Free / Pro / Plus / …).
 *
 * Self-written, lean core. Lets a CLIENT site define plans, assign them to WP
 * users, and (later phases) collect money via its OWN PayPal. Membership
 * revenue is a DIFFERENT money type from the hub LLM credit (R-GW-8) — this
 * core never calls bizcity-llm-router for billing.
 *
 * M1 scope: Plan Registry (CRUD) + assignment (subscriptions table + user_meta)
 * + admin Users "Plan" column + manual assign on the profile screen.
 *
 * See: core/bizcity-llm/docs/MEMBERSHIP-CLIENT-PLANS.md
 *
 * @since 2026-06-04 (PHASE-MEMBERSHIP M1)
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/class-membership-plan-registry.php';
require_once __DIR__ . '/includes/class-membership-manager.php';
require_once __DIR__ . '/includes/class-membership-entitlement.php';
require_once __DIR__ . '/includes/class-membership-usage.php';
require_once __DIR__ . '/includes/class-membership-enforcer.php';
// [2026-07-17 Johnny Chu] PHASE-D G-1 — membership email notifications.
require_once __DIR__ . '/includes/class-membership-emails.php';
// [2026-06-04 Johnny Chu] PHASE-MEMBERSHIP M4 — one-time PayPal payments ledger + gateway.
require_once __DIR__ . '/includes/class-membership-payments.php';
require_once __DIR__ . '/includes/class-membership-paypal-gateway.php';
// [2026-06-04 Johnny Chu] PHASE-MEMBERSHIP M4/M6 — same-origin REST proxy (bizcity-membership/v1).
require_once __DIR__ . '/includes/class-membership-rest.php';
// [2026-06-04 Johnny Chu] PHASE-MEMBERSHIP M7 — daily expiry sweep + optional PMS bridge.
require_once __DIR__ . '/includes/class-membership-cron.php';

// [2026-06-09 Johnny Chu] PHASE-MASTER-PLANS — Plugin Feature Gate (Pro/Premium menu lock).
// Reads bizcity_hub_plugins_enabled (synced via BizCity_LLM_Client::get_entitlement())
// and badges / notices locked bundled plugins. Only active in wp-admin.
if ( is_admin() ) {
    require_once __DIR__ . '/includes/class-plugin-feature-gate.php';
    BizCity_Plugin_Feature_Gate::boot();
}
require_once __DIR__ . '/includes/class-membership-bridge-pms.php';

/**
 * Boot the manager early so other modules can resolve plans.
 */
add_action( 'plugins_loaded', static function () {
	BizCity_Membership_Manager::instance();
}, 4 );

// [2026-06-04 Johnny Chu] PHASE-MEMBERSHIP M2 — inject per-user plan limits into
// the existing KG Cost Guard filters (bizcity_kg_quota_per_user / _user_is_exempt).
add_action( 'plugins_loaded', static function () {
	BizCity_Membership_Enforcer::init();
}, 6 );

// [2026-06-04 Johnny Chu] PHASE-MEMBERSHIP M4/M6 — register same-origin membership REST.
BizCity_Membership_REST::init();

// [2026-06-11 Johnny Chu] R-KG-FILE-TYPES — clamp sources-service file size cap to plan's kg_max_file_size_mb.
add_filter( 'twinchat_sources_max_file_bytes', static function ( $out, $ext, $mime ) {
	if ( ! class_exists( 'BizCity_Membership_Entitlement' ) ) {
		return $out;
	}
	$uid = get_current_user_id();
	$ent = BizCity_Membership_Entitlement::instance()->for_user( $uid );
	$plan_max_mb = isset( $ent['kg_max_file_size_mb'] ) && (int) $ent['kg_max_file_size_mb'] > 0
		? (int) $ent['kg_max_file_size_mb']
		: 5;
	$plan_max_bytes = $plan_max_mb * 1048576;
	// Only clamp non-AV files; AV has its own large cap and quota system.
	if ( $out['modality'] !== 'av' ) {
		$out['bytes'] = min( (int) $out['bytes'], $plan_max_bytes );
	}
	return $out;
}, 10, 3 );

// [2026-07-17 Johnny Chu] PHASE-D G-7 — daily expiry sweep + optional PMS bridge.
add_action( 'plugins_loaded', static function () {
	BizCity_Membership_Cron::init();
	BizCity_Membership_Bridge_PMS::init();
	// [2026-07-17 Johnny Chu] PHASE-D G-1 — wire email notification hooks.
	BizCity_Membership_Emails::init();
}, 7 );

/**
 * Version-gated table migration. Runs cheaply on admin_init.
 */
add_action( 'admin_init', static function () {
	BizCity_Membership_Manager::instance()->maybe_upgrade();
} );

/**
 * Admin Users "Plan" column + manual assign on profile screen.
 */
if ( is_admin() ) {
	require_once __DIR__ . '/includes/admin/class-membership-user-column.php';
	add_action( 'admin_init', static function () {
		BizCity_Membership_User_Column::init();
	} );

	// [2026-06-04 Johnny Chu] PHASE-MEMBERSHIP M5 — admin dashboard (Overview/Plans/
	// Members/Payments/Settings PayPal).
	require_once __DIR__ . '/includes/admin/class-membership-admin-page.php';
	// [2026-06-07 Johnny Chu] PHASE-C C-3 — Revenue + Usage report classes for admin tabs.
	require_once __DIR__ . '/includes/admin/class-membership-revenue-report.php';
	require_once __DIR__ . '/includes/admin/class-membership-usage-report.php';
	BizCity_Membership_Admin_Page::register();
}

/**
 * Register the subscriptions table into the diagnostics Table Registry so it
 * appears in Tools → BizCity Diagnostics (with auto-create button).
 */
add_filter( 'bizcity_diagnostics_register_tables', static function ( $tables ) {
	$tables   = is_array( $tables ) ? $tables : array();
	$tables[] = array(
		'name'      => 'bizcity_member_subscriptions',
		'owner'     => 'core/membership',
		'group'     => 'membership',
		'critical'  => true,
		'class'     => 'BizCity_Membership_Manager',
		'installer' => 'membership',
	);
	// [2026-06-04 Johnny Chu] PHASE-MEMBERSHIP M3 — usage counter table.
	$tables[] = array(
		'name'      => 'bizcity_member_usage',
		'owner'     => 'core/membership',
		'group'     => 'membership',
		'class'     => 'BizCity_Membership_Usage',
		'installer' => 'membership',
	);
	// [2026-06-04 Johnny Chu] PHASE-MEMBERSHIP M4 — payments ledger table.
	$tables[] = array(
		'name'      => 'bizcity_member_payments',
		'owner'     => 'core/membership',
		'group'     => 'membership',
		'class'     => 'BizCity_Membership_Payments',
		'installer' => 'membership',
	);
	return $tables;
}, 10 );

// [2026-06-04 Johnny Chu] PHASE-MEMBERSHIP M8 — R-DDV probe core.membership.entitlement
// (3-layer evidence Disk/Loader/Runtime). Rule: validate qua probe, KHÔNG wp-cli ad-hoc.
add_filter( 'bizcity_diagnostics_register_probes', static function ( $probes ) {
	$probes = is_array( $probes ) ? $probes : array();
	$probe_path = __DIR__ . '/../diagnostics/includes/probes/class-probe-membership-entitlement.php';
	if ( file_exists( $probe_path ) ) {
		require_once $probe_path;
		if ( class_exists( 'BizCity_Probe_Membership_Entitlement' ) ) {
			$probes[] = new BizCity_Probe_Membership_Entitlement();
		}
	}
	return $probes;
}, 20 );

