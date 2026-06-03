<?php
/**
 * Bizcity Twin AI — GuruReplyDTO (PHASE-0.35 GURU-ZALO-BOT §3).
 *
 * Canonical reply contract returned by every BizCity_Guru_Runtime turn,
 * regardless of which channel (Zalo, Facebook, WebChat, TwinChat, …) the
 * request originated from. R-GURU-UNIFY tier-1 rule: NO channel-specific
 * reply shape may leak into the runtime layer.
 *
 * Citation format = `[src:N#pM]` (source_id, passage_id) — kg-hub canonical
 * form (NOT the legacy `[N{nb}P{p}]` shown in early roadmap drafts).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Persona\DTO
 * @since      Phase 0.35 (2026-05-26)
 * @license    GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Guru_Reply_DTO', false ) ) {
    return;
}

final class BizCity_Guru_Reply_DTO {

    /** Allowed runtime kinds. */
    const RUNTIME_GURU      = 'guru_native';
    const RUNTIME_TWINBRAIN = 'twinbrain';

    /** @var string UUID — joins to bizcity_guru_traces.trace_id. */
    public $trace_id = '';

    /** @var string self::RUNTIME_* */
    public $runtime = self::RUNTIME_GURU;

    /** @var string Markdown reply body (with inline [src:N#pM] citations). */
    public $text = '';

    /**
     * @var array<int, array{nb_id:int, passage_id:int, source_id:int, source_label:string}>
     *   Every passage cited inline in $text MUST appear here. Validated by
     *   bizcity_kg_validate_citations() (kg-hub helper).
     */
    public $citations = [];

    /**
     * @var array<int, array{skill_id:int, name:string, status:string, output?:array}>
     *   Phase 2+ populates this when skills are invoked. Phase 1 = empty.
     */
    public $skill_results = [];

    /**
     * @var array<int, array{label:string, text:string}>
     *   Phase 3+ TwinBrain cross-notebook perspectives. Phase 1 = empty.
     */
    public $perspectives = [];

    /** @var int Character (Guru) id that owned this turn. */
    public $character_id = 0;

    /** @var string Channel slug — 'zalo' | 'facebook' | 'webchat' | 'twinchat' | 'rest'. */
    public $channel = '';

    /** @var array{prompt?:int, completion?:int, total?:int} Token accounting. */
    public $tokens = [ 'prompt' => 0, 'completion' => 0, 'total' => 0 ];

    /** @var float USD cost estimate (Phase 5 cost gate). */
    public $cost_usd = 0.0;

    /** @var int Wall-clock latency milliseconds (start → DTO ready). */
    public $latency_ms = 0;

    /**
     * @var array{code:string, message:string}|null  Non-fatal error envelope.
     *   When set, $text may still contain a graceful fallback message.
     */
    public $error = null;

    /** @var array Free-form meta (notebook_id, user_id, breadth, …). */
    public $meta = [];

    /* ─────────────────────────────────────────────────────────────────── */

    public function to_array(): array {
        return [
            'trace_id'      => $this->trace_id,
            'runtime'       => $this->runtime,
            'text'          => $this->text,
            'citations'     => array_values( $this->citations ),
            'skill_results' => array_values( $this->skill_results ),
            'perspectives'  => array_values( $this->perspectives ),
            'character_id'  => $this->character_id,
            'channel'       => $this->channel,
            'tokens'        => $this->tokens,
            'cost_usd'      => $this->cost_usd,
            'latency_ms'    => $this->latency_ms,
            'error'         => $this->error,
            'meta'          => $this->meta,
        ];
    }

    public static function from_array( array $a ): self {
        $dto = new self();
        $dto->trace_id      = (string) ( $a['trace_id']      ?? '' );
        $dto->runtime       = (string) ( $a['runtime']       ?? self::RUNTIME_GURU );
        $dto->text          = (string) ( $a['text']          ?? '' );
        $dto->citations     = is_array( $a['citations']     ?? null ) ? $a['citations']     : [];
        $dto->skill_results = is_array( $a['skill_results'] ?? null ) ? $a['skill_results'] : [];
        $dto->perspectives  = is_array( $a['perspectives']  ?? null ) ? $a['perspectives']  : [];
        $dto->character_id  = (int)    ( $a['character_id']  ?? 0 );
        $dto->channel       = (string) ( $a['channel']       ?? '' );
        $dto->tokens        = is_array( $a['tokens']        ?? null ) ? $a['tokens']        : [];
        $dto->cost_usd      = (float)  ( $a['cost_usd']      ?? 0 );
        $dto->latency_ms    = (int)    ( $a['latency_ms']    ?? 0 );
        $dto->error         = isset( $a['error'] ) && is_array( $a['error'] ) ? $a['error'] : null;
        $dto->meta          = is_array( $a['meta']          ?? null ) ? $a['meta']          : [];
        return $dto;
    }

    /**
     * @return true|WP_Error  true when contract holds, WP_Error otherwise.
     */
    public function validate() {
        if ( $this->trace_id === '' ) {
            return new WP_Error( 'guru_dto_invalid', 'trace_id is required' );
        }
        if ( ! in_array( $this->runtime, [ self::RUNTIME_GURU, self::RUNTIME_TWINBRAIN ], true ) ) {
            return new WP_Error( 'guru_dto_invalid', 'runtime must be guru_native|twinbrain' );
        }
        if ( $this->error === null && $this->text === '' ) {
            return new WP_Error( 'guru_dto_invalid', 'text empty without error envelope' );
        }
        foreach ( $this->citations as $i => $c ) {
            if ( ! isset( $c['source_id'], $c['passage_id'] ) ) {
                return new WP_Error( 'guru_dto_invalid', "citations[$i] missing source_id/passage_id" );
            }
        }
        return true;
    }

    /**
     * Build a stable canonical tag for inline citations.
     */
    public static function citation_tag( int $source_id, int $passage_id ): string {
        return sprintf( '[src:%d#p%d]', $source_id, $passage_id );
    }
}

if ( ! class_exists( 'BizCity_Channel_Send_DTO', false ) ) {

    /**
     * Channel-bound dispatch envelope produced by a Channel Formatter from
     * a GuruReplyDTO. Each adapter consumes this shape and ships it.
     */
    final class BizCity_Channel_Send_DTO {
        /** @var string */ public $channel = '';
        /** @var string */ public $recipient_ref = '';
        /** @var string */ public $text = '';
        /** @var array  */ public $attachments = [];
        /** @var array  */ public $quick_replies = [];
        /** @var array  */ public $footer_blocks = [];
        /** @var array  */ public $meta = [];

        public function to_array(): array {
            return [
                'channel'       => $this->channel,
                'recipient_ref' => $this->recipient_ref,
                'text'          => $this->text,
                'attachments'   => $this->attachments,
                'quick_replies' => $this->quick_replies,
                'footer_blocks' => $this->footer_blocks,
                'meta'          => $this->meta,
            ];
        }
    }
}
