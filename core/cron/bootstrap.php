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

/**
 * Initialise the manager (table registration + filter hooks).
 *
 * Loaded inside the plugin bootstrap — must run BEFORE any module
 * tries to register a job so the manager is in place to accept it.
 */
add_action( 'plugins_loaded', static function () {
	BizCity_Cron_Manager::instance();
}, 4 );

// Admin UI · REST · MCP — Phase 2.
BizCity_Cron_Admin_Page::register();
BizCity_Cron_REST::register();
BizCity_Cron_MCP::register();

/**
 * Register cron tables into the diagnostics Table Registry so they appear in
 * the Tools → BizCity Diagnostics inventory (with auto-create button).
 */
add_filter( 'bizcity_diagnostics_register_tables', static function ( $tables ) {
	$tables   = is_array( $tables ) ? $tables : [];
	$tables[] = [ 'name' => 'bizcity_cron_registry', 'owner' => 'core/cron', 'group' => 'cron', 'critical' => true,  'class' => 'BizCity_Cron_Manager', 'installer' => 'cron' ];
	$tables[] = [ 'name' => 'bizcity_cron_runs',     'owner' => 'core/cron', 'group' => 'cron', 'class' => 'BizCity_Cron_Manager', 'installer' => 'cron' ];
	$tables[] = [ 'name' => 'bizcity_cron_retries',  'owner' => 'core/cron', 'group' => 'cron', 'class' => 'BizCity_Cron_Manager', 'installer' => 'cron' ];
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
