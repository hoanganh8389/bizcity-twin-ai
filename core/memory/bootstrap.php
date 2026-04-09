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
require_once BIZCITY_MEMORY_DIR . 'includes/class-memory-manager.php';
require_once BIZCITY_MEMORY_DIR . 'includes/class-memory-rest-api.php';
require_once BIZCITY_MEMORY_DIR . 'includes/class-admin-page.php';

/* ── Initialize ───────────────────────────────────────────────────── */
BizCity_Memory_Database::instance();
BizCity_Memory_Log::instance();
BizCity_Memory_Manager::instance();
BizCity_Memory_REST_API::instance();

if ( is_admin() ) {
	BizCity_Memory_Admin_Page::instance();
}
