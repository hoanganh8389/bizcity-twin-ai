<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Agents
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 *
 * Bootstrap for core/agents — Phase 0.15 primitives.
 *
 * @since 1.13.0
 */

defined( 'ABSPATH' ) || exit;
if ( defined( 'BIZCITY_TWIN_AGENTS_LOADED' ) ) return;
define( 'BIZCITY_TWIN_AGENTS_LOADED', true );

// Agent primitives
require_once __DIR__ . '/class-twin-tool.php';
require_once __DIR__ . '/class-twin-agent.php';
require_once __DIR__ . '/class-twin-agent-registry.php';
require_once __DIR__ . '/class-twin-handoff.php';

// Vòng 4.5.0.a — Universal Artifact-Source Federation (Rule 8g).
require_once __DIR__ . '/contracts/class-artifact-source-federation.php';

// Built-in agent registrations
// Sprint 8 cleanup — `echo_agent` is a Vòng-1 smoke-test fixture (no tools,
// just echoes input). Gate behind `bizcity_twin_dev_mode` filter so it does
// not pollute production agent registry. Default ON in WP_DEBUG, OFF otherwise.
$bizcity_twin_dev_mode = (bool) apply_filters(
	'bizcity_twin_dev_mode',
	defined( 'WP_DEBUG' ) && WP_DEBUG
);
if ( $bizcity_twin_dev_mode ) {
	require_once __DIR__ . '/bootstrap/register-echo-agent.php';
}
unset( $bizcity_twin_dev_mode );
// Vòng 4.5.5e (Rule 8g v2 — 2026-05-02) — agent registration moved to owning
// plugins. Each plugin self-registers via the `bizcity_register_agent`
// filter (see BizCity_Twin_Agent_Registry::resolve()). Core only keeps
// the system-level agents: image (placeholder) + twin_root (orchestrator).
//
//   - register-mindmap-agent.php   →  plugins/bizcity-doc/includes/agents/
//   - register-doc-agent.php       →  plugins/bizcity-doc/includes/agents/
//   - register-content-agent.php   →  plugins/bizcity-content-creator/includes/agents/
require_once __DIR__ . '/bootstrap/register-image-agent.php';
require_once __DIR__ . '/bootstrap/register-twin-root.php';
