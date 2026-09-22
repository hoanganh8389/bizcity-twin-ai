<?php
/**
 * ============================================================================
 * ORPHAN FILE — DELETED FROM RUNTIME. DO NOT USE. DO NOT COPY FROM THIS FILE.
 * ============================================================================
 *
 * [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-1.33C — renamed to
 * `class-twin-event-taxonomy_deleted.php` and retired.
 *
 * WHY THIS FILE EXISTS AT ALL
 * ---------------------------
 * It is kept only as history. It is loaded by NO bootstrap and defines NO
 * runtime contract. The `_deleted` suffix is the signal that it must never be
 * required, autoloaded, copied, or treated as a source of truth.
 *
 * WHY IT WAS DANGEROUS
 * --------------------
 * It declares `class BizCity_Twin_Event_Taxonomy` — the SAME class name as the
 * canonical owner — with a stale `TAXONOMY_VERSION = 3`. Reading this file
 * makes it look like an event type is already registered when the canonical
 * file does not have it. That exact trap caused a real production defect:
 *
 *   `BizCity_TwinBrain_Runtime` emitted `BizCity_Twin_Event_Taxonomy::MEMORY_RECALL`
 *   every TwinBrain turn. The constant was declared ONLY here, in a file no
 *   bootstrap loads, so the canonical taxonomy did not have it and PHP threw
 *   `Error: Undefined class constant 'MEMORY_RECALL'` on every turn. The Layer
 *   0.5 audit event never reached the Event Stream, so the MPR thinking timeline
 *   could not attribute the memory-recall step at all.
 *
 *   Evidence: `uploads/sites/1258/bizcity-twinbrain-logs/runtime/2026-09-13.jsonl`
 *   contained three `memory_recall_exception` rows.
 *
 * BANNED EVEN THOUGH THE CLASS IS NOT LOADED
 * ------------------------------------------
 * A duplicate class declaration behind a `class_exists()` guard is still a
 * latent fatal: if any loader, test, or future refactor includes this path
 * before the canonical file, the canonical taxonomy is never defined and the
 * whole Event Stream contract silently degrades. That is why this file is
 * renamed rather than merely commented.
 *
 * CANONICAL OWNER — READ AND EDIT THIS INSTEAD
 * --------------------------------------------
 *   core/twin-core/event-stream/class-twin-event-taxonomy.php
 *
 * It is loaded by `core/twin-core/bootstrap.php` (the Event Stream section) and
 * is the only allowed source of truth for `BizCity_Twin_Event_Taxonomy`.
 * Adding an event type requires the checklist at
 * `core/twin-core/event-stream/README.md` §"Adding a new event_type".
 *
 * RETIREMENT CHECK BEFORE REVIVING ANYTHING FROM HERE
 * ---------------------------------------------------
 * 1. Does the canonical taxonomy already declare the constant? Check there first.
 * 2. Is the matching JSON schema already at
 *    `core/twin-core/event-stream/schemas/events/<type>.json`?
 * 3. Bump `TAXONOMY_VERSION` in the CANONICAL file, never here.
 * 4. Add the `required_fields()` entry in the CANONICAL file.
 *
 * If all four answers are already satisfied, THIS FILE HAS NOTHING TO OFFER and
 * must stay retired.
 * ============================================================================
 */

/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Twin_Core
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 * @deprecated 2026-09-16 Retired. Use `core/twin-core/event-stream/class-twin-event-taxonomy.php`.
 */

/**
 * BizCity_Twin_Event_Taxonomy — 15 canonical event types for Twin Event Stream.
 *
 * Phase 0.12 — Single source of truth for what may be emitted into
 * `bizcity_twin_event_stream`. Adding a new type requires:
 *   1. Update PHASE-0.12-TWIN-EVENT-STREAM-UNIFICATION.md §3
 *   2. Add JSON schema at core/twin-core/schemas/events/{type}.json
 *   3. Bump TAXONOMY_VERSION
 *   4. RFC ≥ 1 day on team channel (per PHASE-0-RULE-EVENT-STREAM R-EVT-2)
 *
 * VISUALIZATION CONTRACT (Phase 0.12 Wave B+ — TwinChat Thinking Timeline):
 * Every event emitted is rendered LIVE in the TwinChat ThinkingTimeline UI
 * (ported from Nexus modules/twinchat/nexus-src). To support the timeline
 * the following OPTIONAL payload fields are RECOGNIZED (not required):
 *
 *   • assistant_streaming_chunk.chunk_kind  ∈ 'reasoning' | 'content'
 *       (default 'content'). Reasoning chunks render in the inline
 *       "Thinking…" preview under the analyzing step; content chunks
 *       flow into the assistant message bubble.
 *
 *   • retrieval.results[].short_code  4-char lowercase slug (e.g. "obrv",
 *       "fl1n") for source chips on the sources_found timeline node.
 *   • retrieval.counts.{sources,images}  for "Found N sources + M images".
 *   • retrieval.phase  ∈ 'start' | 'complete'  (allows projector to map
 *       to AgentStep "retrieving" → "sources_found" transition).
 *
 *   • turn_start.mode    ∈ 'twinchat' | 'webchat' | 'notebook' | …
 *   • turn_complete.success  bool; turn_complete.duration_ms  int.
 *
 * Adding more visualization fields = NOT a taxonomy change (no version bump),
 * but MUST be documented here so FE adapter stays in sync.
 *
 * @since 2026-04-29
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

/*
 * ---------------------------------------------------------------------------
 * DEFUSED — class declaration intentionally disabled.
 * ---------------------------------------------------------------------------
 * [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-1.33C — the historical body
 * below is kept as read-only history, but it MUST NOT declare
 * `BizCity_Twin_Event_Taxonomy` again. A duplicate declaration behind a guard
 * is still a latent fatal: if any loader includes this path before the
 * canonical file, the canonical taxonomy is never defined and the Event Stream
 * contract silently degrades. Redeclaring it here would rebuild exactly the
 * defect this file was retired for.
 *
 * Do not uncomment this block. If you need a historical constant or payload
 * shape, read the canonical owner and add it there:
 *   core/twin-core/event-stream/class-twin-event-taxonomy.php
 * ---------------------------------------------------------------------------
 */
if ( false ) {
class BizCity_Twin_Event_Taxonomy {

	// Bumped 2026-04-30 (Sprint 5.0b) — added 5 NotebookLM-parity event types.
	const TAXONOMY_VERSION = 3; // [2026-08-03 Johnny Chu] P2 — register runtime memory_recall audit event.

	// ---- 15 canonical event types (Phase 0.12) --------------------------
	const USER_MESSAGE              = 'user_message';
	const ASSISTANT_MESSAGE         = 'assistant_message';
	const ASSISTANT_STREAMING_CHUNK = 'assistant_streaming_chunk';
	const TOOL_CALL                 = 'tool_call';
	const TOOL_RESULT               = 'tool_result';
	const LLM_REQUEST               = 'llm_request';
	const LLM_RESPONSE              = 'llm_response';
	const LLM_ERROR                 = 'llm_error';
	const CLASSIFICATION            = 'classification';
	const RETRIEVAL                 = 'retrieval';
	const DECISION                  = 'decision';
	const TURN_START                = 'turn_start';
	const TURN_COMPLETE             = 'turn_complete';
	const FOCUS_CHANGE              = 'focus_change';
	const MILESTONE                 = 'milestone';

	// ---- Sprint 5.0b — NotebookLM parity events --------------------------
	// All optional payload fields documented at
	// modules/twinchat/PHASE-0.5-SPRINT-5-NOTEBOOKLM-PARITY.md §2.0b.
	const SUGGESTION_EMITTED  = 'suggestion_emitted';   // server proposes follow-up chips
	const SUGGESTION_CLICKED  = 'suggestion_clicked';   // user click audit trail (FE → dispatch)
	const WELCOME_JOB         = 'welcome_job';          // status field: started|completed|failed
	const RESEARCH_JOB        = 'research_job';         // status field: started|tavily_returned|imported|failed
	const NOTE_PINNED         = 'note_pinned';          // user pins assistant message as note
	const MEMORY_RECALL              = 'memory_recall';        // Layer 0.5 memory context audit event

	/**
	 * Required payload fields per event type.
	 * (Keep tight — extra fields go in payload freely.)
	 *
	 * @return array<string, string[]>
	 */
	public static function required_fields(): array {
		return [
			self::USER_MESSAGE              => [ 'content' ],
			self::ASSISTANT_MESSAGE         => [ 'content' ],
			self::ASSISTANT_STREAMING_CHUNK => [ 'delta' ],
			self::TOOL_CALL                 => [ 'tool_name', 'call_id' ],
			self::TOOL_RESULT               => [ 'call_id', 'status' ],
			self::LLM_REQUEST               => [ 'model' ],
			self::LLM_RESPONSE              => [ 'model_used' ],
			self::LLM_ERROR                 => [ 'error_msg' ],
			self::CLASSIFICATION            => [ 'intent' ],
			self::RETRIEVAL                 => [ 'scope', 'query' ],
			self::DECISION                  => [ 'stage' ],
			self::TURN_START                => [ 'mode' ],
			self::TURN_COMPLETE             => [ 'success' ],
			self::FOCUS_CHANGE              => [ 'new_focus' ],
			self::MILESTONE                 => [ 'milestone_type' ],

			// Sprint 5.0b — NotebookLM parity
			self::SUGGESTION_EMITTED        => [ 'message_id', 'items' ],
			self::SUGGESTION_CLICKED        => [ 'message_id', 'text' ],
			self::WELCOME_JOB               => [ 'job_id', 'status' ],
			self::RESEARCH_JOB              => [ 'job_id', 'status' ],
			self::NOTE_PINNED               => [ 'note_id', 'message_id' ],
			self::MEMORY_RECALL             => [ 'trace_id', 'surface', 'counts', 'citations', 'block_len', 'latency_ms' ],
		];
	}

	/**
	 * Allowed event_source values.
	 *
	 * @return string[]
	 */
	public static function allowed_sources(): array {
		return [ 'twinchat', 'webchat', 'server', 'kg', 'tool', 'memory', 'notebook', 'system' ];
	}

	/**
	 * @return string[] All canonical event_type values.
	 */
	public static function all(): array {
		return array_keys( self::required_fields() );
	}

	/**
	 * Throws BizCity_Event_Validation_Exception if event_type ∉ taxonomy.
	 */
	public static function assert_valid_type( string $event_type ): void {
		if ( ! in_array( $event_type, self::all(), true ) ) {
			throw new BizCity_Event_Validation_Exception(
				"Unknown event_type '{$event_type}'. Allowed: " . implode( ', ', self::all() )
			);
		}
	}

	/**
	 * Throws BizCity_Event_Validation_Exception if event_source not allowed.
	 */
	public static function assert_valid_source( string $event_source ): void {
		if ( ! in_array( $event_source, self::allowed_sources(), true ) ) {
			throw new BizCity_Event_Validation_Exception(
				"Unknown event_source '{$event_source}'. Allowed: " . implode( ', ', self::allowed_sources() )
			);
		}
	}

	/**
	 * Validate payload has required fields for the given event_type.
	 *
	 * @return string[] Missing field names. Empty = valid.
	 */
	public static function validate_payload( string $event_type, array $payload ): array {
		$req = self::required_fields()[ $event_type ] ?? [];
		$missing = [];
		foreach ( $req as $field ) {
			if ( ! array_key_exists( $field, $payload ) ) {
				$missing[] = $field;
			}
		}
		return $missing;
	}

	/**
	 * Throws if payload missing any required field.
	 */
	public static function assert_payload_valid( string $event_type, array $payload ): void {
		$missing = self::validate_payload( $event_type, $payload );
		if ( ! empty( $missing ) ) {
			throw new BizCity_Event_Validation_Exception(
				"Event '{$event_type}' missing required payload fields: " . implode( ', ', $missing )
			);
		}
	}
}

/**
 * Exception thrown when an event fails validation at boundary.
 * Per R-EVT-7 — fail loud, no silent drop.
 */
class BizCity_Event_Validation_Exception extends \RuntimeException {}

} // end if ( false ) — defused historical body. See the banner above.
/*
 * ---------------------------------------------------------------------------
 * END OF DEFUSED BODY. Nothing above this line executes.
 *
 * Both `BizCity_Twin_Event_Taxonomy` and `BizCity_Event_Validation_Exception`
 * are declared here historically and BOTH are now owned by the canonical file:
 *   core/twin-core/event-stream/class-twin-event-taxonomy.php
 *
 * If this file is ever included, it now defines ZERO classes and therefore
 * cannot shadow or fatally collide with the canonical owner.
 * ---------------------------------------------------------------------------
 */
