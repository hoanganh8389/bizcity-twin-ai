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

// Phase B (F7.B1..B4) — Guru ↔ Skill/Provider bridge (R-MPRT-5 anti-jailbreak
// + admin REST). Schema auto-migrates on plugins_loaded.
require_once BIZCITY_PERSONA_INCLUDES . 'class-guru-bridge-installer.php';
require_once BIZCITY_PERSONA_INCLUDES . 'class-guru-skill-bridge.php';
require_once BIZCITY_PERSONA_INCLUDES . 'class-guru-provider-bridge.php';
require_once BIZCITY_PERSONA_INCLUDES . 'class-guru-bridge-rest.php';

// Phase C.1 (F7.C1) — Pre-rules @guru / #tool token parser. Pure helper, no
// auto-hook; TwinBrain runtime + Intent Engine call ::parse() as needed.
require_once BIZCITY_PERSONA_INCLUDES . 'class-guru-token-parser.php';

// PHASE-0.35 GURU-ZALO-BOT §1 (2026-05-26) — Unified Guru Runtime + DTO +
// citation canonicaliser. R-GURU-UNIFY: every channel reply funnels through
// BizCity_Guru_Runtime::instance()->reply() so context / citations / events
// stay uniform. See PHASE-0.35-GURU-ZALO-BOT.md §1.1-§1.4.
require_once BIZCITY_PERSONA_INCLUDES . 'dto/class-guru-reply-dto.php';
require_once BIZCITY_PERSONA_INCLUDES . 'class-guru-citation-formatter.php';
require_once BIZCITY_PERSONA_INCLUDES . 'class-guru-runtime.php';

add_action( 'plugins_loaded', array( 'BizCity_Guru_Bridge_Installer', 'maybe_install' ), 7 );
BizCity_Guru_Bridge_REST::init();

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

// [2026-07-05 Johnny Chu] HOTFIX — bridge persona provider tools into Twin Tool Registry.
add_filter(
    'bizcity_twin_register_tool',
    static function ( $registry ) {
        if ( ! is_array( $registry ) ) {
            $registry = array();
        }
        if ( ! interface_exists( 'BizCity_Twin_Tool' ) ) {
            return $registry;
        }

        $adapter_file = BIZCITY_PERSONA_INCLUDES . 'class-persona-provider-tool-adapter.php';
        if ( file_exists( $adapter_file ) ) {
            require_once $adapter_file;
        }
        if ( ! class_exists( 'BizCity_Persona_Provider_Tool_Adapter' ) ) {
            return $registry;
        }

        foreach ( BizCity_Persona_Registry::instance()->all() as $provider ) {
            if ( ! is_object( $provider ) || ! method_exists( $provider, 'get_tool_definitions' ) ) {
                continue;
            }
            $defs = (array) $provider->get_tool_definitions();
            foreach ( $defs as $idx => $def ) {
                if ( ! is_array( $def ) ) {
                    continue;
                }
                if ( empty( $def['name'] ) && is_string( $idx ) && $idx !== '' ) {
                    $def['name'] = $idx;
                }
                $tool_name = isset( $def['name'] ) ? sanitize_key( (string) $def['name'] ) : '';
                if ( $tool_name === '' || isset( $registry[ $tool_name ] ) ) {
                    continue;
                }
                $registry[ $tool_name ] = new BizCity_Persona_Provider_Tool_Adapter( $provider, $def );
            }
        }

        return $registry;
    },
    40
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
