<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Runtime
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 *
 * Bootstrap for core/runtime — Phase 0.13 runtime layer.
 *
 * Loads all runtime classes and registers the REST endpoint.
 * Agents bootstrap must be loaded first (agents/ bootstrap.php).
 *
 * @since 1.13.0
 */

defined( 'ABSPATH' ) || exit;
if ( defined( 'BIZCITY_TWIN_RUNTIME_LOADED' ) ) return;
define( 'BIZCITY_TWIN_RUNTIME_LOADED', true );

// Runtime contracts + classes
require_once __DIR__ . '/class-twin-db-installer.php';
require_once __DIR__ . '/interface-twin-session.php';
require_once __DIR__ . '/class-twin-run-state.php';
require_once __DIR__ . '/class-twin-rolling-session.php';
require_once __DIR__ . '/class-twinshell-event-bus.php';
require_once __DIR__ . '/class-twin-runner.php';
require_once __DIR__ . '/class-twin-rest-controller.php';

// Ensure DB tables exist for current blog (cheap: reads one option, bails if current).
add_action( 'plugins_loaded', function () {
	BizCity_Twin_DB_Installer::maybe_install();
}, 15 );

// Register REST route at rest_api_init
add_action( 'rest_api_init', function () {
	BizCity_Twin_REST_Controller::instance()->register_routes();
} );
