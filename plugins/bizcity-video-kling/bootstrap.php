<?php
/**
 * BizCity Video Kling - Bootstrap v2.0
 *
 * Load order:
 * 1. Libraries (kling_api, ffmpeg, music, tts, r2)
 * 2. Database class (needed everywhere)
 * 3. Cron+Chat handler (MUST be outside admin — cron runs in CLI/frontend context)
 * 4. Admin classes (only wp-admin)
 * 5. Workflow actions (only if WAIC available)
 */

defined('ABSPATH') or die('OOPS...');

// Asset version for cache busting
define('BIZCITY_VIDEO_KLING_ASSETS_VERSION', '2.0.0');

// ── 1. Libraries ──
require_once BIZCITY_VIDEO_KLING_DIR . 'lib/kling_api.php';
require_once BIZCITY_VIDEO_KLING_DIR . 'lib/class-ffmpeg-presets.php';
require_once BIZCITY_VIDEO_KLING_DIR . 'lib/class-music-library.php';
require_once BIZCITY_VIDEO_KLING_DIR . 'lib/class-openai-tts.php';
require_once BIZCITY_VIDEO_KLING_DIR . 'lib/class-r2-uploader.php';

// ── 2. Database class (needed everywhere) ──
require_once BIZCITY_VIDEO_KLING_DIR . 'includes/class-database.php';

// Ensure new columns exist for existing installations
add_action( 'admin_init', function() {
    $db_version = get_option( 'bizcity_video_kling_db_version', '1.0.0' );
    if ( version_compare( $db_version, '2.0.0', '<' ) ) {
        BizCity_Video_Kling_Database::maybe_add_chain_columns();
        BizCity_Video_Kling_Database::maybe_add_checkpoints_columns();
        update_option( 'bizcity_video_kling_db_version', '2.0.0' );
    }
} );

// ── 3. Cron + Chat notification (PILLAR 3) ──
// CRITICAL: Must load outside is_admin() — WP-Cron runs without admin context
require_once BIZCITY_VIDEO_KLING_DIR . 'includes/class-cron-chat.php';
BizCity_Video_Kling_Cron_Chat::init();

// ── 3b. AJAX handlers (frontend form in profile page) ──
// CRITICAL: wp_ajax_ hooks need to fire from admin-ajax.php context
require_once BIZCITY_VIDEO_KLING_DIR . 'includes/class-ajax-kling.php';
BizCity_Video_Kling_Ajax::init();

// ── 4. Admin classes ──
if ( is_admin() ) {
    require_once BIZCITY_VIDEO_KLING_DIR . 'includes/class-job-monitor.php';
    require_once BIZCITY_VIDEO_KLING_DIR . 'includes/class-scripts.php';
    require_once BIZCITY_VIDEO_KLING_DIR . 'includes/class-shots.php';
    require_once BIZCITY_VIDEO_KLING_DIR . 'includes/class-queue.php';
    require_once BIZCITY_VIDEO_KLING_DIR . 'includes/class-admin-menu.php';

    BizCity_Video_Kling_Job_Monitor::init();
    BizCity_Video_Kling_Scripts::init();
    BizCity_Video_Kling_Shots::init();
    BizCity_Video_Kling_Queue::init();
    BizCity_Video_Kling_Admin_Menu::instance();
}

// ── 5. Workflow actions (WAIC) ──
add_action('plugins_loaded', 'bizcity_video_kling_register_workflow_actions', 20);

function bizcity_video_kling_register_workflow_actions() {
    if (!class_exists('WaicAction')) {
        return;
    }

    require_once BIZCITY_VIDEO_KLING_DIR . 'workflow/blocks/actions/kl_create_job.php';
    require_once BIZCITY_VIDEO_KLING_DIR . 'workflow/blocks/actions/kl_poll_status.php';
    require_once BIZCITY_VIDEO_KLING_DIR . 'workflow/blocks/actions/kl_fetch_video.php';

    add_filter('waic_actions', function($actions) {
        $actions[] = WaicAction_kl_create_job::class;
        $actions[] = WaicAction_kl_poll_status::class;
        $actions[] = WaicAction_kl_fetch_video::class;
        return $actions;
    });
}

