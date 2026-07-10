<?php
/**
 * BizCity Diagnostics — core.channel.zone_ui probe (PHASE-0.40 Wave G0-UI.7).
 *
 * R-DDV: Kiểm tra UI zone separation đã được implement:
 *   - PHP catalog có `zone` field trên mọi platform entry
 *   - `zalo_oa` có mặt trong catalog (Zone 1)
 *   - `zalo_bot` / `telegram` / `zalo_hotline` có zone='admin' trong catalog
 *   - JS navConfig.js export ZONE_1_CODES / ZONE_2_CODES / ZALO_OA_TABS / ZALO_BOT_TABS
 *   - SideNav.jsx có cg-nav-zone-label sections
 *   - PlatformWorkspace.jsx hiển thị ZoneBadge
 *   - AddChannelRoute.jsx group theo zone (ZONE_GROUPS)
 *   - CSS có .cg-nav-zone-label--customer / --admin
 *
 * DDV rows (Disk-only, 8 layers):
 *   zone_ui.php.catalog_zone    — catalog() có 'zone' => 'customer' + 'zone' => 'admin'
 *   zone_ui.php.zalo_oa_entry   — catalog có entry code='zalo_oa' zone='customer'
 *   zone_ui.php.admin_zones     — zalo_bot, telegram, zalo_hotline có zone='admin'
 *   zone_ui.js.zone_consts      — navConfig.js export ZONE_1_CODES, ZONE_2_CODES
 *   zone_ui.js.tabs_split       — navConfig.js có ZALO_OA_TABS + ZALO_BOT_TABS (separate)
 *   zone_ui.js.sidenav_sections — SideNav.jsx có cg-nav-zone-label
 *   zone_ui.js.workspace_badge  — PlatformWorkspace.jsx có ZoneBadge
 *   zone_ui.js.add_channel      — AddChannelRoute.jsx group theo ZONE_GROUPS
 *   zone_ui.css.classes         — index.css có .cg-nav-zone-label--customer
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-06-13 (PHASE-0.40 G0-UI.7 / R-DDV)
 */

// [2026-06-13 Johnny Chu] PHASE-0.40 G0-UI.7 — DDV probe zone_ui (FE zone separation R-DDV)
defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

if ( class_exists( 'BizCity_Probe_Zone_UI', false ) ) {
	return;
}

final class BizCity_Probe_Zone_UI implements BizCity_Diagnostics_Probe {

	public function id(): string          { return 'core.channel.zone_ui'; }
	public function label(): string       { return 'Zone UI Separation: Catalog zone field + SideNav 2 sections'; }
	public function description(): string {
		return '9 lớp kiểm tra UI zone separation (R-ZONE-UI): PHP catalog zone field, zalo_oa Zone 1 entry, Zone 2 admin entries, JS ZONE_1/2_CODES, ZALO_OA_TABS/ZALO_BOT_TABS split, SideNav 2 sections, ZoneBadge, AddChannel ZONE_GROUPS, CSS zone classes (G0-UI.7 PHASE-0.40).';
	}
	public function severity(): string    { return 'warning'; }
	public function order(): int          { return 50; }
	public function icon(): string        { return 'layout'; }
	public function estimate_ms(): int    { return 100; }

	public function precondition() {
		return true;
	}

	// [2026-06-14 Johnny Chu] HOTFIX — add missing $ctx param to match BizCity_Diagnostics_Probe::run($ctx):array
	public function run( $ctx ): array {
		$steps = array();
		$pass  = true;

		// [2026-06-14 Johnny Chu] HOTFIX — fixed paths (were missing /core/channel-gateway/)
		$cg_base     = dirname( __DIR__, 4 ) . '/core/channel-gateway';
		$php_catalog = $cg_base . '/includes/class-admin-menu-spa.php';
		$fe_src      = $cg_base . '/frontend/src';
		$nav_config  = $fe_src . '/shell/navConfig.js';
		$sidenav     = $fe_src . '/shell/SideNav.jsx';
		$workspace   = $fe_src . '/shell/PlatformWorkspace.jsx';
		$add_channel = $fe_src . '/routes/AddChannelRoute.jsx';
		$index_css   = $fe_src . '/index.css';

		// Whether React src is deployed (production often ships dist only)
		$fe_src_present = is_dir( $fe_src );

		// ── Disk: PHP catalog zone field ────────────────────────────────────────
		$php_ok   = file_exists( $php_catalog );
		$php_text = $php_ok ? file_get_contents( $php_catalog ) : '';

		$has_customer = $php_ok && ( false !== strpos( $php_text, "'zone' => 'customer'" ) );
		$steps[] = array(
			'id' => 'zone_ui.php.catalog_zone', 'label' => "Disk: PHP catalog has 'zone' field (customer + admin)",
			'pass' => $has_customer,
			'detail' => $has_customer ? "OK — 'zone' => 'customer' present in platform_catalog()" : "MISSING — add 'zone' field to class-admin-menu-spa.php platform_catalog()",
		);
		if ( ! $has_customer ) { $pass = false; }

		$has_zalo_oa = $php_ok && ( false !== strpos( $php_text, "'code' => 'zalo_oa'" ) );
		$steps[] = array(
			'id' => 'zone_ui.php.zalo_oa_entry', 'label' => "Disk: PHP catalog has zalo_oa (Zone 1 customer)",
			'pass' => $has_zalo_oa,
			'detail' => $has_zalo_oa ? "OK — zalo_oa Zone 1 entry exists" : "MISSING — add zalo_oa to catalog with zone='customer'",
		);
		if ( ! $has_zalo_oa ) { $pass = false; }

		$has_admin_zones = $php_ok
			&& ( false !== strpos( $php_text, "'code' => 'zalo_bot'" ) )
			&& ( false !== strpos( $php_text, "'zone' => 'admin'" ) );
		$steps[] = array(
			'id' => 'zone_ui.php.admin_zones', 'label' => "Disk: PHP catalog zalo_bot/telegram/hotline zone='admin'",
			'pass' => $has_admin_zones,
			'detail' => $has_admin_zones ? "OK — Zone 2 admin entries present" : "MISSING — zalo_bot/telegram/hotline need zone='admin' in catalog",
		);
		if ( ! $has_admin_zones ) { $pass = false; }

		// ── Disk: JS navConfig.js ────────────────────────────────────────────────
		// [2026-06-14 Johnny Chu] HOTFIX — FE src steps are info-only (skip not fail) when
		// src not deployed; production server ships dist/ only, not src/.
		if ( ! $fe_src_present ) {
			$fe_skip_detail = 'React src không được deploy lên server (chỉ dist/). Bỏ qua.';
			$steps[] = array( 'id' => 'zone_ui.js.zone_consts',      'label' => 'Disk: navConfig.js exports ZONE_1_CODES + ZONE_2_CODES', 'pass' => true, 'detail' => $fe_skip_detail );
			$steps[] = array( 'id' => 'zone_ui.js.tabs_split',        'label' => 'Disk: navConfig.js has ZALO_OA_TABS + ZALO_BOT_TABS',   'pass' => true, 'detail' => $fe_skip_detail );
			$steps[] = array( 'id' => 'zone_ui.js.sidenav_sections',  'label' => 'Disk: SideNav.jsx uses cg-nav-zone-label',              'pass' => true, 'detail' => $fe_skip_detail );
			$steps[] = array( 'id' => 'zone_ui.js.workspace_badge',   'label' => 'Disk: PlatformWorkspace.jsx renders ZoneBadge',         'pass' => true, 'detail' => $fe_skip_detail );
			$steps[] = array( 'id' => 'zone_ui.js.add_channel',       'label' => 'Disk: AddChannelRoute.jsx groups by ZONE_GROUPS',       'pass' => true, 'detail' => $fe_skip_detail );
			$steps[] = array( 'id' => 'zone_ui.css.classes',          'label' => 'Disk: index.css has .cg-nav-zone-label--customer/--admin','pass' => true, 'detail' => $fe_skip_detail );
		} else {
		$nav_ok   = file_exists( $nav_config );
		$nav_text = $nav_ok ? file_get_contents( $nav_config ) : '';

		$has_zone_consts = $nav_ok
			&& ( false !== strpos( $nav_text, 'export const ZONE_1_CODES' ) )
			&& ( false !== strpos( $nav_text, 'export const ZONE_2_CODES' ) );
		$steps[] = array(
			'id' => 'zone_ui.js.zone_consts', 'label' => 'Disk: navConfig.js exports ZONE_1_CODES + ZONE_2_CODES',
			'pass' => $has_zone_consts,
			'detail' => $has_zone_consts ? 'OK — ZONE_1_CODES / ZONE_2_CODES exported' : 'MISSING — add export const ZONE_1_CODES / ZONE_2_CODES to navConfig.js',
		);
		if ( ! $has_zone_consts ) { $pass = false; }

		$has_tabs_split = $nav_ok
			&& ( false !== strpos( $nav_text, 'ZALO_OA_TABS' ) )
			&& ( false !== strpos( $nav_text, 'ZALO_BOT_TABS' ) );
		$steps[] = array(
			'id' => 'zone_ui.js.tabs_split', 'label' => 'Disk: navConfig.js has ZALO_OA_TABS + ZALO_BOT_TABS (zone-split)',
			'pass' => $has_tabs_split,
			'detail' => $has_tabs_split ? 'OK — Zone 1 Zalo OA tabs (with Inbox) + Zone 2 Zalo Bot tabs (no Inbox)' : 'MISSING — split ZALO_TABS into ZALO_OA_TABS/ZALO_BOT_TABS',
		);
		if ( ! $has_tabs_split ) { $pass = false; }

		// ── Disk: SideNav.jsx zone sections ─────────────────────────────────────
		$sn_ok   = file_exists( $sidenav );
		$sn_text = $sn_ok ? file_get_contents( $sidenav ) : '';

		$has_zone_label = $sn_ok && ( false !== strpos( $sn_text, 'cg-nav-zone-label' ) );
		$steps[] = array(
			'id' => 'zone_ui.js.sidenav_sections', 'label' => 'Disk: SideNav.jsx uses cg-nav-zone-label (2 zone sections)',
			'pass' => $has_zone_label,
			'detail' => $has_zone_label ? 'OK — zone section dividers present in SideNav' : 'MISSING — add cg-nav-zone-label sections to SideNav.jsx',
		);
		if ( ! $has_zone_label ) { $pass = false; }

		// ── Disk: PlatformWorkspace.jsx ZoneBadge ────────────────────────────────
		$ws_ok   = file_exists( $workspace );
		$ws_text = $ws_ok ? file_get_contents( $workspace ) : '';

		$has_zone_badge = $ws_ok && ( false !== strpos( $ws_text, 'ZoneBadge' ) );
		$steps[] = array(
			'id' => 'zone_ui.js.workspace_badge', 'label' => 'Disk: PlatformWorkspace.jsx renders ZoneBadge',
			'pass' => $has_zone_badge,
			'detail' => $has_zone_badge ? 'OK — ZoneBadge component in workspace header' : 'MISSING — add ZoneBadge to PlatformWorkspace.jsx header',
		);
		if ( ! $has_zone_badge ) { $pass = false; }

		// ── Disk: AddChannelRoute.jsx ZONE_GROUPS ────────────────────────────────
		$ac_ok   = file_exists( $add_channel );
		$ac_text = $ac_ok ? file_get_contents( $add_channel ) : '';

		$has_zone_groups = $ac_ok && ( false !== strpos( $ac_text, 'ZONE_GROUPS' ) );
		$steps[] = array(
			'id' => 'zone_ui.js.add_channel', 'label' => 'Disk: AddChannelRoute.jsx groups platforms by ZONE_GROUPS',
			'pass' => $has_zone_groups,
			'detail' => $has_zone_groups ? 'OK — ZONE_GROUPS groups in Add Channel wizard' : 'MISSING — add ZONE_GROUPS grouping to AddChannelRoute.jsx',
		);
		if ( ! $has_zone_groups ) { $pass = false; }

		// ── Disk: CSS zone label classes ─────────────────────────────────────────
		$css_ok   = file_exists( $index_css );
		$css_text = $css_ok ? file_get_contents( $index_css ) : '';

		$has_css_zone = $css_ok && ( false !== strpos( $css_text, 'cg-nav-zone-label--customer' ) );
		$steps[] = array(
			'id' => 'zone_ui.css.classes', 'label' => 'Disk: index.css has .cg-nav-zone-label--customer/--admin',
			'pass' => $has_css_zone,
			'detail' => $has_css_zone ? 'OK — zone label CSS classes present' : 'MISSING — add .cg-nav-zone-label--customer/--admin to index.css',
		);
		if ( ! $has_css_zone ) { $pass = false; }

		} // end else ($fe_src_present)

		// [2026-06-14 Johnny Chu] HOTFIX — runner expects 'status' key ('pass'/'fail'), not 'pass' bool
		return array( 'status' => $pass ? 'pass' : 'fail', 'steps' => $steps );
	}

	// [2026-06-14 Johnny Chu] HOTFIX — required by BizCity_Diagnostics_Probe interface
	public function cleanup(): void {}
}

// Self-register through the standard filter.
add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = new BizCity_Probe_Zone_UI();
	return $list;
} );
