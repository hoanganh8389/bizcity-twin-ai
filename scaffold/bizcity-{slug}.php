<?php
/*
Plugin Name: BizCity {Name} – {Subtitle}
Description: {Mô tả ngắn gọn chức năng plugin}
Short Description: {1 dòng cho Touch Bar}
Quick View: {Emoji} Chat → {Input} → AI → {Output}
Version: 1.0.0
Icon Path: /assets/icon.png
Role: agent
Credit: 0
Price: 0
Cover URI: https://media.bizcity.vn/uploads/{year}/{month}/{slug}-cover.jpg
Template Page: {slug}
Category: tools
Tags: AI tool, {tag1}, {tag2}
Author: BizCity
Author URI: https://bizcity.vn
Text Domain: bizcity-{slug}
Requires at least: 6.3
Requires PHP: 7.4
*/

if ( ! defined( 'ABSPATH' ) ) exit;

/* ── Twin AI Core Dependency ── */
if ( ! defined( 'BIZCITY_TWIN_AI_VERSION' ) ) {
    add_action( 'admin_notices', function() {
        echo '<div class="notice notice-error"><p>';
        echo '<strong>BizCity {Name}</strong> yêu cầu plugin <strong>Bizcity Twin AI</strong> được cài đặt và kích hoạt. ';
        echo 'Tải về tại <a href="https://github.com/hoanganh8389/bizcity-twin-ai/" target="_blank">github.com/hoanganh8389/bizcity-twin-ai</a>.';
        echo '</p></div>';
    });
    return;
}

define( 'BZ{PREFIX}_DIR',     plugin_dir_path( __FILE__ ) );
define( 'BZ{PREFIX}_URL',     plugin_dir_url( __FILE__ ) );
define( 'BZ{PREFIX}_VERSION', '1.0.0' );
define( 'BZ{PREFIX}_SLUG',    'bizcity-{slug}' );

require_once BZ{PREFIX}_DIR . 'includes/class-tools-{slug}.php';

/* ══════════════════════════════════════════════════════════════
 *  PILLAR 2 — Intent Provider Registration (array-based)
 * ══════════════════════════════════════════════════════════════ */
add_action( 'bizcity_intent_register_providers', function ( $registry ) {

    bizcity_intent_register_plugin( $registry, [

        'id'   => '{slug}',
        'name' => 'BizCity {Name}',

        /* ── Goal Patterns (specific → generic, primary LAST) ── */
        'patterns' => [
            /* SECONDARY goals first */
            '/{secondary_keywords}/ui' => [
                'goal' => '{secondary_goal}', 'label' => '{Label}',
                'description' => '{Mô tả goal cho LLM Classifier}',
                'extract' => [ '{slot1}' ],
            ],
            /* PRIMARY goal last (catch-all) */
            '/{primary_keywords}/ui' => [
                'goal' => '{primary_goal}', 'label' => '{Label}',
                'description' => '{Mô tả goal cho LLM Classifier}',
                'extract' => [ '{slot1}' ],
            ],
        ],

        /* ── Plans ── */
        'plans' => [
            '{primary_goal}' => [
                'required_slots' => [
                    'topic' => [ 'type' => 'text', 'prompt' => '{Câu hỏi khi thiếu}' ],
                ],
                'optional_slots' => [
                    'image_url' => [ 'type' => 'image', 'prompt' => 'Gửi ảnh (tùy chọn).', 'default' => '' ],
                ],
                'tool' => '{primary_goal}', 'ai_compose' => false,
                'slot_order' => [ 'topic', 'image_url' ],
            ],
            '{secondary_goal}' => [
                'required_slots' => [
                    'topic' => [ 'type' => 'text', 'prompt' => '{Câu hỏi khi thiếu}' ],
                ],
                'optional_slots' => [],
                'tool' => '{secondary_goal}', 'ai_compose' => false,
                'slot_order' => [ 'topic' ],
            ],
        ],

        /* ── Tools (schema BẮT BUỘC) ── */
        'tools' => [
            '{primary_goal}' => [
                'schema' => [
                    'description'  => '{Mô tả tool}',
                    'input_fields' => [
                        'topic'     => [ 'required' => true,  'type' => 'text' ],
                        'image_url' => [ 'required' => false, 'type' => 'image' ],
                    ],
                ],
                'callback' => [ 'BizCity_Tool_{Name}', '{primary_goal}' ],
            ],
            '{secondary_goal}' => [
                'schema' => [
                    'description'  => '{Mô tả tool}',
                    'input_fields' => [
                        'topic' => [ 'required' => true, 'type' => 'text' ],
                    ],
                ],
                'callback' => [ 'BizCity_Tool_{Name}', '{secondary_goal}' ],
            ],
        ],

        /* ── Context ── */
        'context' => function ( $goal, $slots, $user_id, $conversation ) {
            return "Plugin: BizCity {Name}\nMục tiêu: {$goal}\n";
        },
    ] );
} );

/* ══════════════════════════════════════════════════════════════
 *  PILLAR 1 — Profile View Route: /{slug}/
 * ══════════════════════════════════════════════════════════════ */
add_action( 'init', function() {
    add_rewrite_rule( '^{slug}/?$', 'index.php?bizcity_agent_page={slug}', 'top' );
} );
add_filter( 'query_vars', function( $vars ) {
    if ( ! in_array( 'bizcity_agent_page', $vars, true ) ) $vars[] = 'bizcity_agent_page';
    return $vars;
} );
add_action( 'template_redirect', function() {
    if ( get_query_var( 'bizcity_agent_page' ) === '{slug}' ) {
        include BZ{PREFIX}_DIR . 'views/page-agent-profile.php';
        exit;
    }
} );

register_activation_hook( __FILE__, function() { flush_rewrite_rules(); } );
register_deactivation_hook( __FILE__, function() { flush_rewrite_rules(); } );