<?php
/**
 * BizCity Memory Module — Persistent Pipeline Working Memory
 *
 * Independent module: core/memory/
 * SQL-based memory spec storage (Markdown content) with tree-view admin UI.
 *
 * Phase 1.15: Memory Spec = "working brief" dạng Markdown, lưu trong SQL,
 * gắn với project + session. Mọi pipeline (single/multi) PHẢI đọc Memory Spec
 * trước khi chạy bất kỳ bước nào.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Memory
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 * @since      Phase 1.15 — 2026-04-09
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

/* ── Constants ────────────────────────────────────────────────────── */
if ( ! defined( 'BIZCITY_MEMORY_DIR' ) ) {
	define( 'BIZCITY_MEMORY_DIR', __DIR__ . '/' );
}
if ( ! defined( 'BIZCITY_MEMORY_VERSION' ) ) {
	define( 'BIZCITY_MEMORY_VERSION', '1.0.0' );
}
if ( ! defined( 'BIZCITY_MEMORY_SCHEMA_VERSION' ) ) {
	define( 'BIZCITY_MEMORY_SCHEMA_VERSION', '1.0.0' );
}

/**
 * Feature flag — disable memory module entirely.
 * Set to false in wp-config.php to skip all memory hooks.
 */
if ( ! defined( 'BIZCITY_MEMORY_ENABLED' ) ) {
	define( 'BIZCITY_MEMORY_ENABLED', true );
}

if ( ! BIZCITY_MEMORY_ENABLED ) {
	return;
}

/* ── Includes ─────────────────────────────────────────────────────── */
require_once BIZCITY_MEMORY_DIR . 'includes/class-memory-database.php';
require_once BIZCITY_MEMORY_DIR . 'includes/class-memory-parser.php';
require_once BIZCITY_MEMORY_DIR . 'includes/class-memory-log.php';
// [2026-07-28 Johnny Chu] R-CH-IDMEM — load the shared identity-scoped owner contract before every memory service.
require_once BIZCITY_MEMORY_DIR . 'includes/class-memory-identity-scope.php';
// [2026-07-31 Johnny Chu] PHASE-1.22-MEMORY-UNIFY — canonical channel context normalizer for all memory writers.
require_once BIZCITY_MEMORY_DIR . 'includes/memory-writer-context.php';
require_once BIZCITY_MEMORY_DIR . 'includes/class-memory-log-projector.php';
require_once BIZCITY_MEMORY_DIR . 'includes/class-memory-manager.php';
require_once BIZCITY_MEMORY_DIR . 'includes/class-memory-rest-api.php';
require_once BIZCITY_MEMORY_DIR . 'includes/class-admin-page.php';
// [2026-09-01 Johnny Chu] PHASE-CB4.4 — retain the obsolete unified installer
// for migration diagnostics only; it is hard-blocked and cannot create SQL
// memory payload storage. Context Bank owns the replacement.
require_once BIZCITY_MEMORY_DIR . 'includes/class-memory-unified-installer.php';
// [2026-09-01 Johnny Chu] PHASE-CB4.4 — compatibility bridge forwards only
// filestore receipts to the Context Bank reference adapter hook.
require_once BIZCITY_MEMORY_DIR . 'includes/class-memory-unified-writer.php';
// Wave 2.8d (TBR.MEM-D6.7 2026-05-24) — admin toggle UI for the unified flag
// + staging timer + D7 readiness checklist (replaces hardcoded filter).
if ( is_admin() ) {
	require_once BIZCITY_MEMORY_DIR . 'includes/class-memory-unified-admin.php';
}

/* ── Initialize ───────────────────────────────────────────────────── */
BizCity_Memory_Database::instance();
BizCity_Memory_Log::instance();
if ( class_exists( 'BizCity_Memory_Log_Projector' ) ) {
	BizCity_Memory_Log_Projector::instance();
}
// [2026-08-01 Johnny Chu] PHASE-1.24-LOG-RETENTION — bounded cleanup for the memory_logs projection.
add_action( 'init', array( 'BizCity_Memory_Log', 'register_retention_cron' ), 20 );
add_action( BizCity_Memory_Log::RETENTION_HOOK, array( 'BizCity_Memory_Log', 'gc_logs' ) );
BizCity_Memory_Manager::instance();
BizCity_Memory_REST_API::instance();
BizCity_Memory_Unified_Installer::instance();
BizCity_Memory_Unified_Writer::instance();

// [2026-07-31 Johnny Chu] PHASE-1.22-MEMORY-DUAL-WRITE — load D7 only for cron so scheduled backup purge has a registered handler.
if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
	$memory_d7_migration = BIZCITY_MEMORY_DIR . 'migrations/d7-drop-legacy.php';
	if ( file_exists( $memory_d7_migration ) ) {
		require_once $memory_d7_migration;
	}
}

if ( is_admin() ) {
	BizCity_Memory_Admin_Page::instance();
}
