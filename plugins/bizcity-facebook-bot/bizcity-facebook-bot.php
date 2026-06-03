<?php
/**
 * Plugin Name: BizCity Facebook Bot Integration
 * Plugin URI:  https://bizcity.vn
 * Description: Facebook Messenger + Page integration with webhook support for BizCity Automation. Bundled với bizcity-twin-ai (must-load).
 * Version:     1.0.0
 * Author:      BizCity
 * Author URI:  https://bizcity.vn
 * License:     GPL v2 or later
 * Text Domain: bizcity-facebook-bot
 *
 * BizCity PHASE 0.31 Sprint 6 — moved from mu-plugins/bizcity-facebook-bot/
 * to plugins/bizcity-twin-ai/plugins/bizcity-facebook-bot/. Loaded as a
 * bundled must-load by bizcity-twin-ai.php so it activates whenever the
 * main plugin runs (no separate WP activation needed).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Delegate to the original bootstrap (defines BIZCITY_FACEBOOK_BOT_* constants
// and wires up hooks). Guard prevents double-load when activated as a regular plugin.
if ( ! defined( 'BIZCITY_FACEBOOK_BOT_VERSION' ) ) {
	require_once __DIR__ . '/bootstrap.php';
}
