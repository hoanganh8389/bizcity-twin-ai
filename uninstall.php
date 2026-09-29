<?php
/**
 * BizCity Twin AI uninstall entrypoint.
 *
 * Legacy tables are never dropped merely because the main plugin is removed.
 * Only tables explicitly marked ready_to_drop with an approval reference and
 * zero rows are eligible; all other core/plugin data remains untouched.
 *
 * @package Bizcity_Twin_AI
 * @since 2026-08-26
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) || ! WP_UNINSTALL_PLUGIN ) {
	exit;
}

$root = __DIR__ . '/';
$safe_loader = $root . 'core/helper/class-bizcity-safe-loader.php';
if ( ! is_file( $safe_loader ) || ! is_readable( $safe_loader ) ) {
	exit;
}
require_once $safe_loader;
if ( ! class_exists( 'BizCity_Safe_Loader', false ) ) {
	exit;
}

// [2026-08-26 Johnny Chu] PHASE-LEGACY-TABLES — uninstall only approved empty legacy tables.
BizCity_Safe_Loader::require_file( $root . 'core/helper/class-bizcity-legacy-table-policy.php', 'uninstall.legacy_table_policy' );
// [2026-09-28 Claude Opus 5.5] R-DIAG-LOCAL (WP-13 DL-4) — core/diagnostics exists only in dev; on a server the
// registry is absent, so check is_file() first (Safe_Loader would log missing_file). The policy keeps its own lists (N12).
if ( is_file( $root . 'core/diagnostics/includes/class-diagnostics-table-registry.php' ) ) {
	BizCity_Safe_Loader::require_file( $root . 'core/diagnostics/includes/class-diagnostics-table-registry.php', 'uninstall.table_registry' );
}

if ( class_exists( 'BizCity_Legacy_Table_Policy' ) ) {
	BizCity_Legacy_Table_Policy::uninstall_ready_tables();
}
