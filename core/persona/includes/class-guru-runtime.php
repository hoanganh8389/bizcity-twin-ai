<?php
/**
 * Bizcity Twin AI — Guru Runtime (PHASE-0.35 GURU-ZALO-BOT §1.2).
 *
 * Single authoritative entry point for every channel that wants a Guru reply.
 * R-GURU-UNIFY contract — no plugin SHALL call BizCity_LLM_Client directly to
 * answer an inbound channel message; everything funnels through
 * `BizCity_Guru_Runtime::instance()->reply()` so 3-layer context, citation
 * canonicalisation, event-stream trace, and cost/latency accounting are
 * applied uniformly.
 *
 * Phase 1 pipeline (linear, deterministic):
 *   1. resolve_envelope()  — validate, default channel/character.
 *   2. resolve_context()   — BizCity_Twin_Guru_Context::collect() (3-layer).
 *   3. classify_breadth()  — STUB: always 'single_notebook' in Phase 1.
 *   4. plan()              — STUB: single retrieve+compose step.
 *   5. synthesize()        — BizCity_LLM_Client::chat() with system+user.
 *   6. canonicalize()      — citation pass.
 *   7. emit + return DTO.
 *
 * Phase 2 adds skill binding + tool intent; Phase 3 escalates to TwinBrain
 * when breadth='cross_notebook'.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Persona
 * @since      Phase 0.35 (2026-05-26)
 * @license    GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/dto/class-guru-reply-dto.php';
require_once __DIR__ . '/class-guru-citation-formatter.php';

if ( class_exists( 'BizCity_Guru_Runtime', false ) ) {
    return;
}

final class BizCity_Guru_Runtime {

    /** @var self|null */
    private static $instance = null;

    public static function instance(): self {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() { /* singleton */ }

    /**
     * Produce a Guru reply for a channel envelope.
     *
     * @param array $envelope {
     *   @type int    $character_id Required.
     *   @type int    $notebook_id  Optional — defaults 0.
     *   @type string $channel      Required — 'zalo'|'facebook'|'webchat'|...
     *   @type string $prompt       Required — end-user input text.
     *   @type int    $user_id      Optional — WordPress user id.
     *   @type array  $history      Optional — recent turns [{role,content}].
     *   @type array  $attachments  Optional — URLs.
     * }
     * @param array $opts {
     *   @type string $purpose  LLM purpose tag (default 'chat').
     *   @type int    $max_tokens
     * }
     * @return BizCity_Guru_Reply_DTO|WP_Error
     */
    public function reply( array $envelope, array $opts = [] ) {
        $started = microtime( true );

        $trace_id = $this->generate_trace_id();
        $channel  = strtolower( (string) ( $envelope['channel'] ?? '' ) );
        $char_id  = (int) ( $envelope['character_id'] ?? 0 );
        $nb_id    = (int) ( $envelope['notebook_id']  ?? 0 );
        $user_id  = (int) ( $envelope['user_id']      ?? 0 );
        $prompt   = trim( (string) ( $envelope['prompt'] ?? '' ) );

        if ( $char_id <= 0 ) {
            return new WP_Error( 'guru_envelope_invalid', 'character_id is required' );
        }
        if ( $channel === '' ) {
            return new WP_Error( 'guru_envelope_invalid', 'channel is required' );
        }
        if ( $prompt === '' ) {
            return new WP_Error( 'guru_envelope_invalid', 'prompt is required' );
        }

        $this->emit_event( 'guru_turn_started', [
            'trace_id'     => $trace_id,
            'character_id' => $char_id,
            'notebook_id'  => $nb_id,
            'channel'      => $channel,
            'user_id'      => $user_id,
            'prompt_len'   => mb_strlen( $prompt ),
        ] );

        // ── Step 2: resolve 3-layer context ────────────────────────────
        $context = [];
        if ( class_exists( 'BizCity_Twin_Guru_Context' ) ) {
            $context = BizCity_Twin_Guru_Context::collect( $char_id, $nb_id, $user_id );
        }

        // ── Step 3/4: breadth + plan (Phase 1 stubs) ───────────────────
        $breadth = 'single_notebook';

        // ── Step 5: synthesize via LLM ─────────────────────────────────
        $messages = $this->build_messages( $context, $envelope );

        if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
            return $this->error_dto( $trace_id, $char_id, $channel, $started,
                'llm_missing', 'BizCity_LLM_Client not loaded' );
        }

        $llm_opts = array_merge( [
            'purpose'  => 'chat',
            'trace_id' => $trace_id,
        ], $opts );

        $llm = BizCity_LLM_Client::instance();
        $resp = $llm->chat( $messages, $llm_opts );

        // BizCity_LLM_Client::chat() always returns an array — never WP_Error.
        // Shape: { success:bool, message:string, model, usage, error, … }.
        if ( empty( $resp['success'] ) ) {
            $err_code = 'llm_error';
            $err_msg  = is_string( $resp['error'] ?? null ) ? $resp['error'] : 'LLM gateway error';
            return $this->error_dto( $trace_id, $char_id, $channel, $started, $err_code, $err_msg );
        }

        $reply_text = $this->extract_text( $resp );
        $tokens     = $this->extract_tokens( $resp );

        // ── Step 6: canonicalise citations ─────────────────────────────
        // Phase 1: passages list = empty (no retrieval layer yet); the
        // canonicaliser will still rewrite legacy tags + strip orphans
        // reported in DTO.meta.citations_missing.
        $cano = BizCity_Guru_Citation_Formatter::canonicalize( $reply_text, [] );

        // ── Step 7: build DTO + emit ───────────────────────────────────
        $dto               = new BizCity_Guru_Reply_DTO();
        $dto->trace_id     = $trace_id;
        $dto->runtime      = BizCity_Guru_Reply_DTO::RUNTIME_GURU;
        $dto->text         = $cano['text'];
        $dto->citations    = $cano['citations'];
        $dto->character_id = $char_id;
        $dto->channel      = $channel;
        $dto->tokens       = $tokens;
        $dto->latency_ms   = (int) round( ( microtime( true ) - $started ) * 1000 );
        $dto->meta         = [
            'notebook_id'        => $nb_id,
            'user_id'            => $user_id,
            'breadth'            => $breadth,
            'context_layers'     => [
                'l1' => $context['l1_instruction_preview'] !== '' ? 1 : 0,
                'l2' => count( $context['l2_guru_sources'] ?? [] ),
                'l3' => count( $context['l3_personal_artifacts'] ?? [] ),
            ],
            'citations_missing'  => $cano['missing'],
        ];

        $valid = $dto->validate();
        if ( is_wp_error( $valid ) ) {
            // R-EVT-7: contract failures emit and propagate.
            $this->emit_event( 'guru_turn_failed', [
                'trace_id' => $trace_id,
                'reason'   => 'dto_invalid',
                'error'    => $valid->get_error_message(),
            ] );
            return $valid;
        }

        $this->emit_event( 'guru_turn_completed', [
            'trace_id'   => $trace_id,
            'character_id' => $char_id,
            'channel'    => $channel,
            'tokens'     => $tokens,
            'latency_ms' => $dto->latency_ms,
            'reply_len'  => mb_strlen( $dto->text ),
        ] );

        return $dto;
    }

    /* ───────────────────────── helpers ─────────────────────────────── */

    /**
     * Compose the messages array fed to LLM client. The 3-layer context is
     * surfaced as a single system message; chat history (if any) is appended
     * verbatim, and the user prompt closes the array.
     */
    private function build_messages( array $context, array $envelope ): array {
        $lines = [];

        $char_name = (string) ( $context['character_name'] ?? '' );
        if ( $char_name !== '' ) {
            $lines[] = 'You are "' . $char_name . '" — a BizCity Twin Guru. Respond in Vietnamese unless asked otherwise.';
        }

        $instr = (string) ( $context['l1_instruction_preview'] ?? '' );
        if ( $instr !== '' ) {
            $lines[] = '';
            $lines[] = '=== INSTRUCTION ===';
            $lines[] = $instr;
        }

        $l2 = $context['l2_guru_sources'] ?? [];
        if ( ! empty( $l2 ) ) {
            $lines[] = '';
            $lines[] = '=== GURU KNOWLEDGE ===';
            foreach ( $l2 as $i => $s ) {
                $lines[] = sprintf( '[%d] (%s) %s', $i + 1,
                    (string) ( $s['type']  ?? '' ),
                    (string) ( $s['title'] ?? '' ) );
            }
        }

        $l3 = $context['l3_personal_artifacts'] ?? [];
        if ( ! empty( $l3 ) ) {
            $lines[] = '';
            $lines[] = '=== PERSONAL ARTIFACTS ===';
            foreach ( $l3 as $i => $a ) {
                $lines[] = sprintf( '[%d] (%s) %s', $i + 1,
                    (string) ( $a['type']  ?? '' ),
                    (string) ( $a['title'] ?? '' ) );
            }
        }

        $lines[] = '';
        $lines[] = 'When citing a knowledge passage, ALWAYS use the canonical inline tag [src:SOURCE_ID#pPASSAGE_ID]. Do not invent passage ids.';

        $system = implode( "\n", $lines );

        $messages = [];
        if ( $system !== '' ) {
            $messages[] = [ 'role' => 'system', 'content' => $system ];
        }

        $history = is_array( $envelope['history'] ?? null ) ? $envelope['history'] : [];
        foreach ( $history as $h ) {
            $role    = (string) ( $h['role']    ?? '' );
            $content = (string) ( $h['content'] ?? '' );
            if ( $role === '' || $content === '' ) { continue; }
            if ( ! in_array( $role, [ 'user', 'assistant', 'system' ], true ) ) { continue; }
            $messages[] = [ 'role' => $role, 'content' => $content ];
        }

        $messages[] = [ 'role' => 'user', 'content' => (string) $envelope['prompt'] ];

        return $messages;
    }

    /**
     * Extract reply text from BizCity_LLM_Client::chat() return.
     * Primary shape: { success:bool, message:string, … }
     * Defensive fallbacks for other shapes used elsewhere in the codebase.
     */
    private function extract_text( $resp ): string {
        if ( is_string( $resp ) )                     { return $resp; }
        if ( ! is_array( $resp ) )                    { return ''; }
        // Canonical BizCity_LLM_Client shape.
        if ( isset( $resp['message'] ) && is_string( $resp['message'] ) ) {
            return $resp['message'];
        }
        // Additional fallback shapes (direct OpenRouter / OpenAI response).
        if ( isset( $resp['text'] ) )                 { return (string) $resp['text']; }
        if ( isset( $resp['content'] ) )              { return (string) $resp['content']; }
        if ( isset( $resp['choices'][0]['message']['content'] ) ) {
            return (string) $resp['choices'][0]['message']['content'];
        }
        if ( isset( $resp['choices'][0]['text'] ) ) {
            return (string) $resp['choices'][0]['text'];
        }
        return '';
    }

    private function extract_tokens( $resp ): array {
        $out = [ 'prompt' => 0, 'completion' => 0, 'total' => 0 ];
        if ( ! is_array( $resp ) ) { return $out; }
        // BizCity_LLM_Client shape uses 'usage' sub-key.
        $u = $resp['usage'] ?? $resp['tokens'] ?? null;
        if ( ! is_array( $u ) ) { return $out; }
        $out['prompt']     = (int) ( $u['prompt_tokens']     ?? $u['input_tokens']  ?? $u['prompt']     ?? 0 );
        $out['completion'] = (int) ( $u['completion_tokens'] ?? $u['output_tokens'] ?? $u['completion'] ?? 0 );
        $out['total']      = (int) ( $u['total_tokens']      ?? $u['total']         ?? ( $out['prompt'] + $out['completion'] ) );
        return $out;
    }

    private function error_dto( string $trace_id, int $char_id, string $channel, float $started, string $code, string $msg ): BizCity_Guru_Reply_DTO {
        $dto = new BizCity_Guru_Reply_DTO();
        $dto->trace_id     = $trace_id;
        $dto->runtime      = BizCity_Guru_Reply_DTO::RUNTIME_GURU;
        $dto->text         = 'Xin lỗi, hiện chưa thể trả lời. Vui lòng thử lại sau.';
        $dto->character_id = $char_id;
        $dto->channel      = $channel;
        $dto->latency_ms   = (int) round( ( microtime( true ) - $started ) * 1000 );
        $dto->error        = [ 'code' => $code, 'message' => $msg ];

        $this->emit_event( 'guru_turn_failed', [
            'trace_id' => $trace_id,
            'reason'   => $code,
            'error'    => $msg,
        ] );
        return $dto;
    }

    private function emit_event( string $type, array $payload ): void {
        if ( class_exists( 'BizCity_Twin_Event_Bus' ) ) {
            try { BizCity_Twin_Event_Bus::dispatch( $type, $payload ); } catch ( \Throwable $e ) { /* never let event bus break runtime */ }
        }
    }

    private function generate_trace_id(): string {
        if ( function_exists( 'wp_generate_uuid4' ) ) {
            return wp_generate_uuid4();
        }
        // Minimal fallback.
        return sprintf( '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),
            mt_rand( 0, 0xffff ),
            mt_rand( 0, 0x0fff ) | 0x4000,
            mt_rand( 0, 0x3fff ) | 0x8000,
            mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff )
        );
    }
}
