<?php
/**
 * Plugin Name: BizCity Content Creator
 * Plugin URI:  https://bizcity.vn
 * Description: Template-driven AI content creation — categories, form builder, SSE chunk generation, multi-platform output.
 * Version:     0.1.16
 * Author:      BizCity
 * License:     Proprietary
 * Role:        agent
 * Featured:    1
 * Category:    Content
 * Tags:        content,marketing,copywriting,campaign,template
 * Template Page: tool-content-creator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ── Guard: require bizcity-twin-ai host plugin ── */
if ( ! defined( 'BIZCITY_TWIN_AI_VERSION' ) ) {
	return;
}

/* ── Constants ── */
define( 'BZCC_VERSION', '0.1.26' );
define( 'BZCC_DIR',     __DIR__ . '/' );
define( 'BZCC_FILE',    __FILE__ );
define( 'BZCC_URL',     plugin_dir_url( __FILE__ ) );
define( 'BZCC_SLUG',    'bizcity-content-creator' );

/* ── Autoload includes ── */
require_once BZCC_DIR . 'includes/class-installer.php';
require_once BZCC_DIR . 'includes/class-admin-menu.php';
require_once BZCC_DIR . 'includes/class-category-manager.php';
require_once BZCC_DIR . 'includes/class-template-manager.php';
require_once BZCC_DIR . 'includes/class-file-manager.php';
require_once BZCC_DIR . 'includes/class-chunk-meta-manager.php';
require_once BZCC_DIR . 'includes/class-frontend.php';
require_once BZCC_DIR . 'includes/class-rest-api.php';

/* ── Activation hook ── */
register_activation_hook( __FILE__, [ 'BZCC_Installer', 'activate' ] );

/* ── Self-healing: table creation for marketplace/AJAX activation ── */
BZCC_Installer::maybe_create_tables();

/* ── Flush rewrite rules once after install (must-load won't trigger activation hook) ── */
if ( ! get_option( 'bzcc_rewrite_version' ) || get_option( 'bzcc_rewrite_version' ) !== BZCC_VERSION ) {
	add_action( 'init', function () {
		BZCC_Frontend::register_rewrite_rules();
		flush_rewrite_rules( false );
		update_option( 'bzcc_rewrite_version', BZCC_VERSION );
	}, 99 );
}

/* ── Admin ── */
BZCC_Admin_Menu::init();

/* ── Frontend (shortcode + template page) ── */
BZCC_Frontend::init();

/* ── REST API (SSE streaming + file operations) ── */
BZCC_Rest_API::init();

/* ── Intent Provider (HIL + dual-path) ── */
add_action( 'bizcity_intent_register_providers', function ( $registry ) {

	bizcity_intent_register_plugin( $registry, [

		'id'   => 'content-creator',
		'name' => 'BizCity Content Creator — Tạo nội dung sáng tạo',

		/* ── PATTERNS ── */
		'patterns' => bzcc_get_intent_patterns(),

		/* ── PLANS ── */
		'plans' => bzcc_get_intent_plans(),

		/* ── TOOLS ── */
		'tools' => [
			'content_creator_execute' => [
				'schema' => [
					'description'   => 'Tạo file + outline + chunks từ template + slots',
					'accepts_skill' => true,
					'input_fields'  => [
						'template_id' => [ 'required' => true,  'type' => 'number' ],
						'form_data'   => [ 'required' => false, 'type' => 'text' ],
					],
				],
				'callback'  => [ 'BZCC_Execution_Engine', 'handle_intent_execute' ],
				'save_mode' => 'always',
			],
			'content_creator_select_template' => [
				'schema' => [
					'description'  => 'Giúp user chọn template phù hợp',
					'input_fields' => [
						'template_choice' => [ 'required' => true, 'type' => 'text' ],
					],
				],
				'callback'  => [ 'BZCC_Execution_Engine', 'handle_select_template' ],
				'save_mode' => 'never',
			],
		],
	] );
} );

/* ── Tool Registry ── */
add_action( 'bizcity_register_tools', function () {
	if ( ! class_exists( 'BizCity_Tool_Registry' ) ) {
		return;
	}
	BizCity_Tool_Registry::register( 'content_creator', [
		'slug'           => 'content_creator',
		'icon'           => '✨',
		'label'          => 'Content Creator',
		'description'    => 'Tạo nội dung sáng tạo từ template',
		'category'       => 'Content',
		'tool_type'      => 'atomic',
		'studio_enabled' => true,
		'at_enabled'     => true,
		'accepts_skill'  => true,
		'content_tier'   => 1,
	] );
} );

/* ── TouchBar Agent ── */
add_filter( 'bizcity_agent_plugins', function ( $agents ) {
	$agents[] = [
		'slug' => 'content-creator',
		'name' => 'Content Creator',
		'icon' => '✨',
		'type' => 'agent',
		'src'  => admin_url( 'admin.php?page=bizcity-creator' ),
	];
	return $agents;
} );

/* ══════════════════════════════════════════════
 *  HELPER: Build intent patterns from active templates
 * ══════════════════════════════════════════════ */

/**
 * Build goal patterns from active templates.
 */
function bzcc_get_intent_patterns(): array {
	$patterns = [
		'/tạo nội dung|content creator|viết bài sáng tạo/iu' => [
			'goal'        => 'cc_generic',
			'label'       => 'Tạo nội dung sáng tạo',
			'description' => 'Mở Content Creator để chọn template',
			'extract'     => [ 'message' ],
		],
	];

	$templates = BZCC_Template_Manager::get_all_active();
	foreach ( $templates as $tpl ) {
		$keywords = array_filter( array_map( 'trim', explode( ',', $tpl->tags ?? '' ) ) );
		if ( empty( $keywords ) ) {
			continue;
		}
		$regex = '/' . implode( '|', array_map( 'preg_quote', $keywords ) ) . '/iu';
		$patterns[ $regex ] = [
			'goal'        => 'cc_' . $tpl->slug,
			'label'       => $tpl->title,
			'description' => $tpl->description ?? '',
			'extract'     => [ 'message' ],
		];
	}

	return $patterns;
}

/**
 * Build plans from active templates — form_fields → HIL slots.
 */
function bzcc_get_intent_plans(): array {
	$plans = [];

	/* Generic: user chọn template */
	$plans['cc_generic'] = [
		'required_slots' => [
			'template_choice' => [
				'type'   => 'text',
				'prompt' => 'Bạn muốn tạo loại nội dung gì? (bán hàng, marketing, kế hoạch, ...)',
			],
		],
		'tool'       => 'content_creator_select_template',
		'ai_compose' => true,
	];

	$type_map = [
		'number'       => 'number',
		'image_upload' => 'image',
		'date'         => 'text',
	];

	$templates = BZCC_Template_Manager::get_all_active();
	foreach ( $templates as $tpl ) {
		$goal_key    = 'cc_' . $tpl->slug;
		$form_fields = json_decode( $tpl->form_fields, true ) ?: [];
		$required    = [];
		$optional    = [];

		foreach ( $form_fields as $field ) {
			$slot_def = [
				'type'    => $type_map[ $field['type'] ] ?? 'text',
				'prompt'  => $field['label'] ?? '',
				'options' => $field['options'] ?? null,
			];

			if ( ! empty( $field['required'] ) ) {
				$required[ $field['slug'] ] = $slot_def;
			} else {
				$optional[ $field['slug'] ] = $slot_def;
			}
		}

		$plans[ $goal_key ] = [
			'required_slots' => $required,
			'optional_slots' => $optional,
			'tool'           => 'content_creator_execute',
			'tool_params'    => [ 'template_id' => $tpl->id ],
			'ai_compose'     => true,
		];
	}

	return $plans;
}
