<?php
/**
 * BizCity Tool HeyGen — Bootstrap
 *
 * Load order:
 * 1. Libraries (heygen_api)
 * 2. Database class (needed everywhere)
 * 3. Cron+Chat handler (MUST be outside admin — cron runs in CLI/frontend context)
 * 4. AJAX handlers (wp_ajax_ hooks fire from admin-ajax.php context)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

// Asset version for cache busting
define( 'BIZCITY_TOOL_HEYGEN_ASSETS_VERSION', '1.0.0' );

// ── 1. Libraries ──
require_once BIZCITY_TOOL_HEYGEN_DIR . 'lib/heygen_api.php';

// ── 2. Database class (needed everywhere) ──
require_once BIZCITY_TOOL_HEYGEN_DIR . 'includes/class-database.php';

// ── 3. Cron + Chat notification (PILLAR 3) ──
// CRITICAL: Must load outside is_admin() — WP-Cron runs without admin context
require_once BIZCITY_TOOL_HEYGEN_DIR . 'includes/class-cron-chat.php';
BizCity_Tool_HeyGen_Cron_Chat::init();

// ── 4. AJAX handlers (frontend form in profile page) ──
// CRITICAL: wp_ajax_ hooks need to fire from admin-ajax.php context
require_once BIZCITY_TOOL_HEYGEN_DIR . 'includes/class-ajax-heygen.php';
BizCity_Tool_HeyGen_Ajax::init();
