<?php
/**
 * Research Event Emitter — pushes research_* events into the unified
 * Twin Event Stream (R-EVT-9). Falls back to a no-op if the event bus
 * isn't loaded so the module remains independently usable.
 *
 * Event taxonomy (PHASE-0.18.1):
 *   research_session_created     — new session (admin or notebook)
 *   research_phase               — turn phase change (planning|searching|generating)
 *   research_tool_call           — tool_start / tool_end snapshot
 *   research_turn_done           — turn finalised (success or error)
 *   research_ingest_completed    — bulk ingest summary
 *   guru_knowledge_ingested      — single source attached to KG
 *   research_source_attached     — Wave 0.18.1.6 — single URL attached via sync API
 *   research_source_detached     — Wave 0.18.1.6 — single URL detached via sync API
 */
defined( 'ABSPATH' ) || exit;

final class BizCity_Research_Event_Emitter {

    public static function emit( string $type, array $payload = [] ): void {
        $payload = array_merge( [ 'ts' => time() ], $payload );

        if ( class_exists( 'BizCity_Event_Bus' ) && method_exists( 'BizCity_Event_Bus', 'dispatch' ) ) {
            BizCity_Event_Bus::dispatch( $type, $payload );
            return;
        }
        if ( function_exists( 'bizcity_event_emit' ) ) {
            bizcity_event_emit( $type, $payload );
            return;
        }
        // Generic WP hook so other listeners can subscribe even without the bus.
        do_action( 'bizcity_research_event', $type, $payload );
    }
}
