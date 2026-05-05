<?php
/**
 * Bizcity Twin AI — Persona module bootstrap.
 *
 * PHASE-0.18 Wave 0.18.0. Loads the abstract provider contract + registry
 * singleton, then bridges them to the `bizcity_kg_source_kinds` filter so
 * downstream services (KG ingest, admin UI) see the union of reserved +
 * provider-declared source kinds.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Persona
 * @since      1.3.3
 * @license    GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'BIZCITY_PERSONA_BOOTSTRAPPED' ) ) {
    return;
}
define( 'BIZCITY_PERSONA_BOOTSTRAPPED', true );

if ( ! defined( 'BIZCITY_PERSONA_DIR' ) ) {
    define( 'BIZCITY_PERSONA_DIR', __DIR__ . '/' );
}
if ( ! defined( 'BIZCITY_PERSONA_INCLUDES' ) ) {
    define( 'BIZCITY_PERSONA_INCLUDES', BIZCITY_PERSONA_DIR . 'includes/' );
}

require_once BIZCITY_PERSONA_INCLUDES . 'class-persona-tool-provider.php';
require_once BIZCITY_PERSONA_INCLUDES . 'class-persona-registry.php';
require_once BIZCITY_PERSONA_INCLUDES . 'class-twin-guru-context.php';

// Wave 0.18.5 — wire 3-layer Twin Guru context (L1 instruction / L2 guru
// knowledge / L3 personal artifacts) into the chat system-prompt chain.
BizCity_Twin_Guru_Context::init();

/**
 * Aggregate reserved + provider-declared source kinds.
 *
 * Downstream ingest service should filter `bizcity_kg_source_kinds` and reject
 * any `source_type` not in the returned union (R-PP-3).
 */
add_filter(
    'bizcity_kg_source_kinds',
    static function ( $kinds ) {
        $kinds = is_array( $kinds ) ? $kinds : [];
        return array_values(
            array_unique(
                array_merge( $kinds, BizCity_Persona_Registry::instance()->all_source_kinds() )
            )
        );
    },
    5
);

/**
 * Surface validation errors to admins as a notice (debug only — silent in prod).
 */
add_action(
    'admin_notices',
    static function () {
        if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $errors = BizCity_Persona_Registry::instance()->get_errors();
        if ( empty( $errors ) ) {
            return;
        }
        echo '<div class="notice notice-warning"><p><strong>Persona Registry:</strong> '
            . esc_html( count( $errors ) ) . ' provider validation issue(s) skipped — '
            . esc_html( implode( ', ', array_slice( $errors, 0, 5 ) ) )
            . ( count( $errors ) > 5 ? '…' : '' ) . '</p></div>';
    }
);
