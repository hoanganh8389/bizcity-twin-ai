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
define('BIZCITY_VIDEO_KLING_ASSETS_VERSION', '2.20.0');

// ── 1. Libraries ──
require_once BIZCITY_VIDEO_KLING_DIR . 'lib/kling_api.php';
require_once BIZCITY_VIDEO_KLING_DIR . 'lib/class-ffmpeg-presets.php';
require_once BIZCITY_VIDEO_KLING_DIR . 'lib/class-music-library.php';
require_once BIZCITY_VIDEO_KLING_DIR . 'lib/class-openai-tts.php';
require_once BIZCITY_VIDEO_KLING_DIR . 'lib/class-r2-uploader.php';

// ── 2. Database class (needed everywhere) ──
require_once BIZCITY_VIDEO_KLING_DIR . 'includes/class-database.php';

// ── 2b. Database migration (runs on EVERY request — safe because version check is cheap) ──
// CRITICAL: Must use 'init' not 'admin_init' — frontend pages (Studio/Generate) need video_effects table
add_action( 'init', 'bizcity_video_kling_run_migrations', 5 );

function bizcity_video_kling_run_migrations() {
    $current_version = get_option( 'bizcity_video_kling_db_version', '0' );
    $target_version  = '2.2.0';

    // Already up to date — skip (cheap check on every request)
    if ( version_compare( $current_version, $target_version, '>=' ) ) {
        return;
    }

    // v2.0.0: chain + checkpoint columns on jobs table
    if ( version_compare( $current_version, '2.0.0', '<' ) ) {
        BizCity_Video_Kling_Database::maybe_add_chain_columns();
        BizCity_Video_Kling_Database::maybe_add_checkpoints_columns();
        $current_version = '2.0.0';
        update_option( 'bizcity_video_kling_db_version', $current_version );
    }

    // v2.1.0: video_effects table (uses dbDelta — safe to re-run)
    if ( version_compare( $current_version, '2.1.0', '<' ) ) {
        BizCity_Video_Kling_Database::create_tables();
        $current_version = '2.1.0';
        update_option( 'bizcity_video_kling_db_version', $current_version );
    }

    // v2.2.0: projects table (video editor persistence)
    if ( version_compare( $current_version, '2.2.0', '<' ) ) {
        BizCity_Video_Kling_Database::create_projects_table();
        $current_version = '2.2.0';
        update_option( 'bizcity_video_kling_db_version', $current_version );
    }
}

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

// ── 4b. TwitCanva Video Workflow integration (admin + REST) ──
require_once BIZCITY_VIDEO_KLING_DIR . 'includes/class-twitcanva-integration.php';
BizCity_TwitCanva_Integration::init();

// ── 4c. TwitCanva AJAX bridge (frontend SPA → PHP) ──
require_once BIZCITY_VIDEO_KLING_DIR . 'includes/class-twitcanva-ajax.php';
BizCity_TwitCanva_Ajax::init();

// ── 4d. Standalone Video Editor page at /video-editor/ ──
require_once BIZCITY_VIDEO_KLING_DIR . 'includes/class-video-editor-page.php';
BizCity_Video_Editor_Page::init();

// ── 4e. Standalone Avatar LipSync page at /avatar/ ──
require_once BIZCITY_VIDEO_KLING_DIR . 'includes/class-avatar-page.php';
BizCity_Avatar_Page::init();

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

