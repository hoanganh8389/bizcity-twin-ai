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

/* ── Rolling + Episodic memory owners ─────────────────────────────── */
// [2026-09-25 Claude Opus 5.5] CORE-REDUCTION WP-11 C4b (R-IM-5) — moved here from core/intent. Loaded BEFORE the
// BIZCITY_MEMORY_ENABLED switch on purpose: they never depended on it while they lived in core/intent, so the
// switch keeps meaning "no Memory Spec / Memory Manager hooks" only. Same class names, tables, contracts
// (`core.intent.rolling_memory`, `core.intent.episodic_memory` keep their ids) and hook names.
// [2026-10-01 Claude Sonnet 5] CORE-REDUCTION WP-17 K-1 (R-LEAN-4) — User Memory ("ghi nhớ") moved here from
// core/knowledge/includes/ (same class name, same tables: bizcity_memory_users). Loaded in the same unconditional
// block as rolling/episodic for the same reason: it never depended on BIZCITY_MEMORY_ENABLED while it lived in
// core/knowledge, so the switch keeps meaning "no Memory Spec / Memory Manager hooks" only. Its callers
// (CG bot memory, Universal Channel Listener, twin-core context builder/focus gate/snapshot/suggest, LLM
// client, Zalo bot) all resolve it lazily inside request-time methods, so moving its instantiation into the
// same deferred `plugins_loaded` bucket as rolling/episodic (instead of the old synchronous call) is safe.
if ( class_exists( 'BizCity_Safe_Loader', false ) ) {
	BizCity_Safe_Loader::require_file( BIZCITY_MEMORY_DIR . 'includes/class-rolling-memory.php', 'memory.rolling_memory' );
	BizCity_Safe_Loader::require_file( BIZCITY_MEMORY_DIR . 'includes/class-episodic-memory.php', 'memory.episodic_memory' );
	BizCity_Safe_Loader::require_file( BIZCITY_MEMORY_DIR . 'includes/class-user-memory.php', 'memory.user_memory' );
} else {
	error_log( '[bizcity] memory_rolling_episodic_skipped: BizCity_Safe_Loader unavailable' );
}
add_action( 'plugins_loaded', function () {
	// [2026-07-28 Johnny Chu] HOTFIX P0 — a partial/invalid deploy of one memory class must degrade that service
	// instead of turning every request into a Class-not-found fatal.
	if ( class_exists( 'BizCity_Rolling_Memory' ) ) {
		BizCity_Rolling_Memory::instance();
	} else {
		error_log( '[bizcity] BizCity_Rolling_Memory unavailable — skipping rolling memory boot; verify deployment artifact.' );
	}
	if ( class_exists( 'BizCity_Episodic_Memory' ) ) {
		// The constructor hooks `bizcity_episodic_daily_aggregate`; only the schedule lives here.
		BizCity_Episodic_Memory::instance();
		if ( ! wp_next_scheduled( 'bizcity_episodic_daily_aggregate' ) ) {
			wp_schedule_event( time(), 'daily', 'bizcity_episodic_daily_aggregate' );
		}
	} else {
		error_log( '[bizcity] BizCity_Episodic_Memory unavailable — skipping episodic memory boot; verify deployment artifact.' );
	}
	if ( class_exists( 'BizCity_User_Memory' ) ) {
		// [2026-10-01 Claude Sonnet 5] CORE-REDUCTION WP-17 K-1 — constructor hooks `bizcity_chat_system_prompt`
		// (filter) and `bizcity_intent_mode_processed` (action); both are only applied at request time.
		BizCity_User_Memory::instance();
	} else {
		error_log( '[bizcity] BizCity_User_Memory unavailable — skipping user memory boot; verify deployment artifact.' );
	}
}, 5 );

if ( ! BIZCITY_MEMORY_ENABLED ) {
	return;
}

/* ── Includes ─────────────────────────────────────────────────────── */
// [2026-07-28 Johnny Chu] R-CH-IDMEM — load the shared identity-scoped owner contract before every memory service.
require_once BIZCITY_MEMORY_DIR . 'includes/class-memory-identity-scope.php';

// [2026-10-01 Claude Opus 5.5] CORE-REDUCTION WP-16 B-4 S3b (R-LEAN-4, Q-W16-1) — Memory Spec, memory logs, the unified writer bridge, session memory and the
// memory admin moved to the add-on (bizcity-twin-brain-addon/memory/); the axis keeps identity scope, rolling + episodic memory here.
$_bizcity_memory_addon = class_exists( 'BizCity_Addon_Locator', false ) ? BizCity_Addon_Locator::file( 'memory/bootstrap.php' ) : '';
if ( '' !== $_bizcity_memory_addon ) {
	require_once $_bizcity_memory_addon;
}
unset( $_bizcity_memory_addon );
