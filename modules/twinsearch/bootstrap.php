<?php
/**
 * TwinSearch — Module bootstrap
 *
 * Family: `retrieval` Input Provider (R-IP-1).
 * Wraps BizCity_Search_Client (Tavily gateway) into a standardized Input Gate
 * the user must go through before adding sources to a notebook/character.
 *
 * Surfaces:
 *  - Admin character editor → tab "🔬 Nghiên cứu" (scope=character)
 *  - TwinChat SmartSourcesPanel → button "🔬 Nghiên cứu sâu" (scope=notebook)
 *
 * Both surfaces mount the SAME Vite bundle built at `modules/twinsearch/ui/dist/`
 * via `<div data-input-mount="twinsearch" data-scope="..." data-scope-id="...">`.
 *
 * Governing rules: PHASE-0-RULE-INPUT-PROVIDER.md (R-IP-1..R-IP-6),
 *                  PHASE-0-RULE-PERSONA-PROVIDER.md (R-PP-1..R-PP-8),
 *                  PHASE-0.18.1-GURU-RESEARCH-TAVILY.md (§7.C).
 *
 * @package Bizcity_Twin_AI
 * @subpackage Modules\TwinSearch
 * @since 0.18.1.7
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'BIZCITY_TWINSEARCH_DIR' ) ) {
	define( 'BIZCITY_TWINSEARCH_DIR', __DIR__ . '/' );
}
if ( ! defined( 'BIZCITY_TWINSEARCH_URL' ) ) {
	define( 'BIZCITY_TWINSEARCH_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'BIZCITY_TWINSEARCH_VERSION' ) ) {
	define( 'BIZCITY_TWINSEARCH_VERSION', '0.1.0' );
}
if ( ! defined( 'BIZCITY_TWINSEARCH_UI_DIST' ) ) {
	define( 'BIZCITY_TWINSEARCH_UI_DIST', BIZCITY_TWINSEARCH_DIR . 'ui/dist/' );
}

require_once BIZCITY_TWINSEARCH_DIR . 'includes/class-twinsearch-persona-provider.php';
require_once BIZCITY_TWINSEARCH_DIR . 'includes/class-twinsearch-asset-loader.php';
require_once BIZCITY_TWINSEARCH_DIR . 'includes/class-input-gate.php';
require_once BIZCITY_TWINSEARCH_DIR . 'includes/class-twinsearch-rest.php';

BizCity_TwinSearch_REST::init();

/**
 * Register persona provider via filter (R-PP-2).
 * Persona Registry is loaded by core; this filter fires lazily when registry builds.
 */
if ( class_exists( 'BizCity_Persona_Tool_Provider' ) ) {
	add_filter( 'bizcity_persona_tool_providers', function ( array $providers ) {
		$providers[] = new BizCity_TwinSearch_Persona_Provider();
		return $providers;
	}, 20 );
}

// Asset loader registers the bundle on admin character editor + TwinChat surfaces.
BizCity_TwinSearch_Asset_Loader::init();
