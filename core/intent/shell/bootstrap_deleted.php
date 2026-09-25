<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Intent\Shell
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 *
 * Phase 0.16 / Vòng 4 — bootstrap loader for the Intent → TwinShell migration.
 *
 * Loads the new shell-side classes only. Does NOT yet wire into the legacy
 * `BizCity_Intent_Engine::process()` — Sprint 2 will add the hybrid
 * dispatcher that respects `BizCity_Intent_Shell_Config::should_use_shell()`.
 *
 * Safe to load unconditionally: every class is namespaced under
 * `BizCity_Intent_*`, has no side effects beyond a single cron action
 * registration, and the activation hook for the diff table is idempotent.
 *
 * @since 4.0.0 (Vòng 4)
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-intent-shell-config.php';
require_once __DIR__ . '/class-intent-shadow-diff-installer.php';
require_once __DIR__ . '/class-intent-shadow-diff.php';
require_once __DIR__ . '/class-intent-pre-rules.php';
require_once __DIR__ . '/class-intent-context-collector.php';
require_once __DIR__ . '/class-intent-shell.php';
require_once __DIR__ . '/class-intent-shell-admin.php';

// Vòng 4 / Sprint 2 — adapters that bridge legacy Intent registries to the
// TwinShell runtime (tools, conversation session, agent registration).
require_once __DIR__ . '/../adapters/class-intent-tool-migrator.php';
require_once __DIR__ . '/../adapters/class-intent-session-adapter.php';
require_once __DIR__ . '/../adapters/class-intent-agent-bootstrap.php';

// Register the four Intent agents (intent_root + 3 specialists) into the
// global Twin_Agent_Registry via the bizcity_register_agent filter.
BizCity_Intent_Agent_Bootstrap::init();

// Sprint 3 — admin settings + shadow diff viewer (Tools menu).
if ( is_admin() ) {
	BizCity_Intent_Shell_Admin::init();
}

// Ensure the shadow-diff table exists once on admin/init — covers fresh
// installs without requiring a separate plugin activation hook.
add_action( 'admin_init', [ 'BizCity_Intent_Shadow_Diff_Installer', 'maybe_install' ], 5 );
