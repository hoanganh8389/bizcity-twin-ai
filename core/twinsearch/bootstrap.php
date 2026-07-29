<?php
/**
 * Core TwinSearch — shared internal document-search runtime.
 *
 * This core is intentionally separate from modules/twinsearch, which is the
 * external web/research input provider. Core TwinSearch searches local KG
 * documents and is shared by TwinChat, TwinWeb and future surfaces.
 *
 * PHP 7.4 compatible.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\TwinSearch
 * @since      2026-07-14
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'BIZCITY_TWINSEARCH_CORE_DIR' ) ) {
	define( 'BIZCITY_TWINSEARCH_CORE_DIR', __DIR__ . '/' );
}

// [2026-07-14 Johnny Chu] PHASE-0.43 — load shared local-document search engine for TwinChat/TwinWeb.
require_once BIZCITY_TWINSEARCH_CORE_DIR . 'includes/class-twinsearch-core.php';
