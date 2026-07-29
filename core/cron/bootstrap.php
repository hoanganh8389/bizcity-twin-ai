<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Cron
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 *
 * core/cron — Unified Cron Registry & Dispatcher.
 *
 * Boots `BizCity_Cron_Manager` (singleton) + diagnostics probe.
 * Phase 1 only — registry + observability, no behavioural change.
 *
 * See: core/cron/PHASE-CRON.md
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/class-cron-manager.php';
require_once __DIR__ . '/includes/class-cron-admin-page.php';
require_once __DIR__ . '/includes/class-cron-rest.php';
require_once __DIR__ . '/includes/class-cron-mcp.php';
// [2026-06-14 Johnny Chu] CRON-FILE-LOGGER — JSONL per-day file logger
require_once __DIR__ . '/includes/class-cron-file-logger.php';
// [2026-07-15 Johnny Chu] R-CRON-TIER — tier-based interval resolver + network UI.
require_once __DIR__ . '/includes/class-cron-tier-settings.php';
require_once __DIR__ . '/includes/class-cron-tier-admin-page.php';

// R-PERF / PERF-CRON-FIX — đăng ký schedule NAMES động (bizcity_tier_{N}min)
// VÔ ĐIỀU KIỆN mỗi request, priority 1, để wp_reschedule_event() không gặp
// invalid_schedule trên frontend (tránh sự cố loop 2026-06-10).
add_filter( 'cron_schedules', array( 'BizCity_Cron_Tier_Settings', 'register_schedules' ), 1 );

/**
 * Initialise the manager (table registration + filter hooks).
 *
 * Loaded inside the plugin bootstrap — must run BEFORE any module
 * tries to register a job so the manager is in place to accept it.
 */
add_action( 'plugins_loaded', static function () {
	BizCity_Cron_Manager::instance();
	// [2026-06-14 Johnny Chu] CRON-FILE-LOGGER — init file logger (hooks on bizcity_cron_run_started/ended/GC)
	BizCity_Cron_File_Logger::init();
}, 4 );

// Admin UI · REST · MCP — Phase 2.
BizCity_Cron_Admin_Page::register();
BizCity_Cron_REST::register();
BizCity_Cron_MCP::register();
// [2026-07-15 Johnny Chu] R-CRON-TIER — Network Admin settings page.
BizCity_Cron_Tier_Admin_Page::register();

/**
 * Register cron tables into the diagnostics Table Registry so they appear in
 * the Tools → BizCity Diagnostics inventory (with auto-create button).
 */
add_filter( 'bizcity_diagnostics_register_tables', static function ( $tables ) {
	$tables   = is_array( $tables ) ? $tables : [];
	$tables[] = [ 'name' => 'bizcity_cron_registry', 'owner' => 'core/cron', 'group' => 'cron', 'critical' => true,  'class' => 'BizCity_Cron_Manager', 'installer' => 'cron' ];
	$tables[] = [ 'name' => 'bizcity_cron_runs',     'owner' => 'core/cron', 'group' => 'cron', 'class' => 'BizCity_Cron_Manager', 'installer' => 'cron' ];
	$tables[] = [ 'name' => 'bizcity_cron_retries',  'owner' => 'core/cron', 'group' => 'cron', 'class' => 'BizCity_Cron_Manager', 'installer' => 'cron' ];
	// [2026-07-26 Johnny Chu] CRON-LOCK-PHASE-A — runtime mutex table.
	$tables[] = [ 'name' => 'bizcity_cron_locks',    'owner' => 'core/cron', 'group' => 'cron', 'class' => 'BizCity_Cron_Manager', 'installer' => 'cron' ];
	return $tables;
}, 10 );

/**
 * Register the diagnostics probe for cron registry health.
 */
add_filter( 'bizcity_diagnostics_register_probes', static function ( array $probes ) {
	$probe_path = __DIR__ . '/../diagnostics/includes/probes/class-probe-cron-registry.php';
	if ( file_exists( $probe_path ) ) {
		require_once $probe_path;
		if ( class_exists( 'BizCity_Probe_Cron_Registry' ) ) {
			$probes[] = new BizCity_Probe_Cron_Registry();
		}
	}
	return $probes;
}, 20 );

/**
 * One-time cleanup of legacy/stale recurring hooks across multisite.
 *
 * Runs in small batches to avoid long admin requests on large networks.
 */
add_action( 'admin_init', static function () {
	// [2026-07-26 Johnny Chu] CRON-OVERLOAD-OPTIMIZE — batched network-wide unschedule for stale hooks.
	$done_option  = 'bizcity_cron_cleanup_legacy_hooks_done';
	$state_option = 'bizcity_cron_cleanup_legacy_hooks_state';
	if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_network_options' ) ) {
		return;
	}
	if ( (int) get_site_option( $done_option, 0 ) > 0 ) {
		return;
	}

	$hooks = array(
		'twf_execute_cron_post_task_batch',
		'twf_check_biztask_reminder',
		'twf_daily_advice',
		'bizcity_shard_metrics_check',
	);

	if ( ! is_multisite() ) {
		foreach ( $hooks as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
		update_site_option( $done_option, time() );
		delete_site_option( $state_option );
		return;
	}

	if ( ! is_main_site() ) {
		return;
	}

	$state  = get_site_option( $state_option, array( 'offset' => 0 ) );
	$offset = max( 0, (int) ( $state['offset'] ?? 0 ) );
	$batch  = 50;
	$sites  = get_sites( array(
		'fields'  => 'ids',
		'number'  => $batch,
		'offset'  => $offset,
		'orderby' => 'id',
		'order'   => 'ASC',
	) );

	if ( empty( $sites ) ) {
		update_site_option( $done_option, time() );
		delete_site_option( $state_option );
		return;
	}

	foreach ( $sites as $blog_id ) {
		switch_to_blog( (int) $blog_id );
		try {
			foreach ( $hooks as $hook ) {
				wp_clear_scheduled_hook( $hook );
			}
		} finally {
			restore_current_blog();
		}
	}

	if ( count( $sites ) < $batch ) {
		update_site_option( $done_option, time() );
		delete_site_option( $state_option );
		return;
	}

	update_site_option( $state_option, array( 'offset' => $offset + count( $sites ) ) );
}, 5 );
