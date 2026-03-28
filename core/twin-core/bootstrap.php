<?php
/**
 * Twin Core bootstrap (local compatibility layer).
 *
 * Provides concrete BizCity_Twin_Context_Resolver implementation for
 * local gateways while server-side intelligence remains the source of truth
 * for deep orchestration.
 *
 * @package BizCity_Twin_AI
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/class-twin-context-resolver.php';
