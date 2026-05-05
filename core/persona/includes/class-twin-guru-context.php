<?php
/**
 * Bizcity Twin AI — Twin Guru Context (Wave 0.18.5).
 *
 * Implements PHASE-0-RULE-TWIN-GURU §R-TG-1..3 — surface the 3-layer Guru
 * context (instruction + knowledge + personal artifacts) to both the LLM
 * provider AND the front-end timeline so users can SEE which Guru context
 * was applied to a given turn.
 *
 *   L1 — Guru Instruction       : `character.system_prompt` (already seeded
 *                                  by BizCity_Twin_Context_Resolver line 81).
 *   L2 — Guru Knowledge         : `kg_sources WHERE character_id = char_id`
 *                                  (pulled by BizCity_Knowledge_Context_API
 *                                  at priority 95 of `bizcity_chat_system_prompt`).
 *   L3 — Personal Artifacts     : `kg_sources WHERE scope=notebook AND
 *                                  scope_id=nb AND source_type IN persona_kinds`.
 *                                  THIS file is the only place L3 is wired in.
 *
 * Public API:
 *   BizCity_Twin_Guru_Context::collect( $character_id, $notebook_id, $user_id = null )
 *     → array{character_id, character_name, l1_instruction_preview,
 *             l2_guru_sources[], l3_personal_artifacts[], applied_at}
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Persona
 * @since      Wave 0.18.5
 * @license    GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Twin_Guru_Context', false ) ) {
    return;
}

class BizCity_Twin_Guru_Context {

    /** Max passages to surface per layer in the UI payload. */
    const L2_MAX = 8;
    const L3_MAX = 12;

    /** Per-request collected payload, keyed by "$character_id:$notebook_id". */
    private static $cache = [];

    public static function init(): void {
        // L3 — Personal Artifacts injection at priority 17 (between Notebook
        // companion sources at 15 and Research/Skill at 92/93).
        add_filter( 'bizcity_chat_system_prompt', [ __CLASS__, 'inject_personal_artifacts' ], 17, 2 );
    }

    /**
     * Filter callback — append L3 personal artifact passages to the system
     * prompt. Idempotent: skips silently when notebook has no character or
     * no persona-typed sources.
     */
    public static function inject_personal_artifacts( $prompt, $args ) {
        $prompt = (string) $prompt;
        $character_id = (int) ( $args['character_id'] ?? 0 );
        $notebook_id  = (int) ( $args['engine_result']['meta']['notebook_id'] ?? $args['notebook_id'] ?? 0 );
        $user_id      = (int) ( $args['user_id'] ?? 0 );

        $payload = self::collect( $character_id, $notebook_id, $user_id );
        $artifacts = $payload['l3_personal_artifacts'];
        if ( empty( $artifacts ) ) {
            return $prompt;
        }

        $lines = [ '', '=== TWIN GURU PERSONAL ARTIFACTS ===' ];
        foreach ( $artifacts as $i => $a ) {
            $title = (string) ( $a['title'] ?? '' );
            $type  = (string) ( $a['type']  ?? '' );
            $lines[] = sprintf( '[%d] (%s) %s', $i + 1, $type, $title );
        }
        $lines[] = '=== END PERSONAL ARTIFACTS ===';

        return $prompt . "\n" . implode( "\n", $lines );
    }

    /**
     * Build the 3-layer Guru context payload. Memoised per request to avoid
     * redundant DB hits when both the filter and the SSE emitter request it.
     */
    public static function collect( int $character_id, int $notebook_id, ?int $user_id = null ): array {
        $key = $character_id . ':' . $notebook_id;
        if ( isset( self::$cache[ $key ] ) ) {
            return self::$cache[ $key ];
        }

        $payload = [
            'character_id'           => $character_id,
            'character_name'         => '',
            'character_avatar'       => '',
            'l1_instruction_preview' => '',
            'l2_guru_sources'        => [],
            'l3_personal_artifacts'  => [],
            'applied_at'             => time(),
        ];

        // ── L1 — Guru Instruction preview ───────────────────────────────
        if ( $character_id > 0 && class_exists( 'BizCity_Knowledge_Database' ) ) {
            $character = BizCity_Knowledge_Database::instance()->get_character( $character_id );
            if ( $character ) {
                $payload['character_name']   = (string) ( $character->display_name ?? $character->name ?? '' );
                $payload['character_avatar'] = (string) ( $character->avatar_url   ?? '' );
                $instr = (string) ( $character->system_prompt ?? '' );
                if ( $instr !== '' ) {
                    $payload['l1_instruction_preview'] = mb_substr( $instr, 0, 320 )
                        . ( mb_strlen( $instr ) > 320 ? '…' : '' );
                }
            }
        }

        // ── L2 — Guru Knowledge sources (character-scoped) ──────────────
        if ( $character_id > 0 && class_exists( 'BizCity_Knowledge_Database' ) ) {
            $rows = BizCity_Knowledge_Database::instance()->get_knowledge_sources( $character_id, 'completed' );
            $rows = is_array( $rows ) ? $rows : [];
            foreach ( array_slice( $rows, 0, self::L2_MAX ) as $r ) {
                $payload['l2_guru_sources'][] = [
                    'id'     => (int)    ( is_object( $r ) ? ( $r->id ?? 0 )          : ( $r['id'] ?? 0 ) ),
                    'title'  => (string) ( is_object( $r ) ? ( $r->source_name ?? '' ): ( $r['source_name'] ?? '' ) ),
                    'type'   => (string) ( is_object( $r ) ? ( $r->source_type ?? '' ): ( $r['source_type'] ?? '' ) ),
                    'url'    => (string) ( is_object( $r ) ? ( $r->source_url ?? '' ) : ( $r['source_url']  ?? '' ) ),
                ];
            }
        }

        // ── L3 — Personal Artifacts (notebook-scoped, persona kinds) ────
        if ( $notebook_id > 0 && class_exists( 'BizCity_KG' ) ) {
            $persona_kinds = self::persona_kinds();
            $items = BizCity_KG::list_sources( [
                'plugin'    => 'twinchat',
                'scope_id'  => $notebook_id,
            ] );
            $items = is_array( $items ) ? $items : [];
            $count = 0;
            foreach ( $items as $it ) {
                if ( $count >= self::L3_MAX ) break;
                $type = (string) ( $it['source_type'] ?? $it['type'] ?? '' );
                // Persona kinds filter is best-effort: when registry is empty
                // we accept all source kinds (safer fallback than dropping all).
                if ( ! empty( $persona_kinds ) && ! in_array( $type, $persona_kinds, true ) ) {
                    continue;
                }
                $payload['l3_personal_artifacts'][] = [
                    'id'    => (int)    ( $it['id'] ?? 0 ),
                    'title' => (string) ( $it['title'] ?? $it['source_name'] ?? '' ),
                    'type'  => $type,
                ];
                $count++;
            }
        }

        self::$cache[ $key ] = $payload;
        return $payload;
    }

    /** Reset memo cache (call between turns / in tests). */
    public static function reset_cache(): void {
        self::$cache = [];
    }

    /**
     * Return registered persona-kind source types. Falls back to empty array
     * (caller treats as "no filter — accept all") so the feature degrades
     * gracefully on installs without persona providers.
     */
    private static function persona_kinds(): array {
        if ( ! class_exists( 'BizCity_Persona_Registry' ) ) return [];
        $kinds = BizCity_Persona_Registry::instance()->all_source_kinds();
        return array_values( array_unique( array_map( 'strval', $kinds ) ) );
    }
}
