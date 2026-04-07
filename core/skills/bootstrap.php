<?php
/**
 * BizCity Skills Module — Plug & Play Skill Library
 *
 * Independent module: core/skills/
 * File-based skill storage (Markdown) with React file-manager admin UI.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Skills
 * @since      2026-03-31
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

/* ── Constants ────────────────────────────────────────────────────── */
if ( ! defined( 'BIZCITY_SKILLS_DIR' ) ) {
    define( 'BIZCITY_SKILLS_DIR', __DIR__ . '/' );
}
if ( ! defined( 'BIZCITY_SKILLS_VERSION' ) ) {
    define( 'BIZCITY_SKILLS_VERSION', '1.0.0' );
}

/**
 * Skill library root — per-blog isolation in uploads dir.
 * Path: wp-content/uploads/bizcity-skills/{blog_id}/
 *
 * Why uploads:
 * - Not inside plugin → not committed to git
 * - Per-blog isolation via blog_id
 * - Writable by web server
 * - Standard WP pattern for user-generated content
 */
if ( ! defined( 'BIZCITY_SKILLS_LIBRARY' ) ) {
    $upload_dir = wp_upload_dir();
    $blog_id    = get_current_blog_id();
    define( 'BIZCITY_SKILLS_LIBRARY', $upload_dir['basedir'] . '/bizcity-skills/' . $blog_id . '/' );
}

/* ── Includes ─────────────────────────────────────────────────────── */
require_once BIZCITY_SKILLS_DIR . 'includes/class-skill-manager.php';
require_once BIZCITY_SKILLS_DIR . 'includes/class-skill-database.php';
require_once BIZCITY_SKILLS_DIR . 'includes/class-skill-tool-map.php';
require_once BIZCITY_SKILLS_DIR . 'includes/class-skill-rest-api.php';
require_once BIZCITY_SKILLS_DIR . 'includes/class-skill-recipe-parser.php';
require_once BIZCITY_SKILLS_DIR . 'includes/class-skill-context.php';
require_once BIZCITY_SKILLS_DIR . 'includes/class-skill-pipeline-bridge.php';
require_once BIZCITY_SKILLS_DIR . 'includes/class-admin-page.php';

/* ── Initialize ───────────────────────────────────────────────────── */
BizCity_Skill_Manager::instance();
if ( class_exists( 'BizCity_Skill_Database' ) ) {
    BizCity_Skill_Database::instance();
}
BizCity_Skill_Tool_Map::instance();
BizCity_Skill_Tool_Map::register_hooks(); // Phase 1.9 S2.7: auto-extract @mentions on skill save
BizCity_Skill_REST_API::instance();
BizCity_Skill_Context::instance();
BizCity_Skill_Pipeline_Bridge::instance();

if ( is_admin() ) {
    BizCity_Skill_Admin_Page::instance();
}

/* ══════════════════════════════════════════════════════════════
 *  PUBLIC PAGE — /skills/
 * ══════════════════════════════════════════════════════════════ */
add_action( 'init', function () {
    add_rewrite_rule( '^skills/?$', 'index.php?bizcity_agent_page=skills', 'top' );
} );
add_filter( 'query_vars', function ( $vars ) {
    if ( ! in_array( 'bizcity_agent_page', $vars, true ) ) {
        $vars[] = 'bizcity_agent_page';
    }
    return $vars;
} );
add_action( 'template_redirect', function () {
    if ( get_query_var( 'bizcity_agent_page' ) === 'skills' ) {
        include BIZCITY_SKILLS_DIR . 'views/page-skills.php';
        exit;
    }
} );
