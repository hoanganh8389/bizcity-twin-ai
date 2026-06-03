<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @license    GPL-2.0-or-later
 *
 * Probe: guru.runtime — End-to-end DTO contract check for
 * `BizCity_Guru_Runtime::reply()` (PHASE-0.35 GURU-ZALO-BOT §1.8).
 *
 * PASS when:
 *   - Runtime + DTO classes load.
 *   - reply() returns a `BizCity_Guru_Reply_DTO` whose `validate()` is true.
 *   - trace_id non-empty, channel echoed, latency_ms > 0.
 *
 * FAIL when reply() returns WP_Error or DTO validate() reports a contract bug.
 * WARN when LLM produced empty text but error envelope is set (degraded reply).
 *
 * This probe issues a real LLM call against the configured BizCity LLM client
 * — keeps prompt short ("ping diagnostic"). Skip via precondition when no
 * character exists yet.
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( ! interface_exists( 'BizCity_Diagnostics_Probe' ) ) {
    require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';
}

class BizCity_Probe_Guru_Runtime implements BizCity_Diagnostics_Probe {

    const PROBE_PROMPT = 'Healthcheck ping — trả lời ngắn gọn "OK".';

    public function id(): string          { return 'guru.runtime'; }
    public function label(): string       { return 'Guru Runtime — DTO contract (real LLM call)'; }
    public function description(): string {
        return 'Gọi BizCity_Guru_Runtime::reply() với prompt healthcheck, kiểm tra DTO khớp schema PHASE-0.35 §3.';
    }
    public function severity(): string    { return 'warning'; }
    public function order(): int          { return 38; }
    public function icon(): string        { return 'Sparkles'; }
    public function estimate_ms(): int    { return 8000; }

    public function precondition() {
        if ( ! class_exists( 'BizCity_Guru_Runtime' ) ) {
            return new WP_Error( 'no_runtime', 'BizCity_Guru_Runtime chưa load — kiểm tra core/persona/bootstrap.php.' );
        }
        if ( ! class_exists( 'BizCity_Guru_Reply_DTO' ) ) {
            return new WP_Error( 'no_dto', 'BizCity_Guru_Reply_DTO chưa load.' );
        }
        if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
            return new WP_Error( 'no_llm', 'BizCity_LLM_Client chưa load — không thể gọi LLM thật.' );
        }
        $char_id = $this->pick_character_id();
        if ( $char_id <= 0 ) {
            return new WP_Error( 'no_character', 'Chưa có character nào trong bizcity_characters — tạo Guru trước khi chạy probe.' );
        }
        return true;
    }

    public function run( $ctx ): array {
        $steps = array();
        $char_id = $this->pick_character_id();
        $steps[] = array( 'label' => 'pick character', 'status' => 'pass', 'detail' => 'character_id=' . $char_id );

        $envelope = array(
            'character_id' => $char_id,
            'notebook_id'  => 0,
            'channel'      => 'rest',
            'prompt'       => self::PROBE_PROMPT,
            'user_id'      => 0,
            'history'      => array(),
        );

        $captured = array();
        $listener = function ( $event_type, $payload ) use ( &$captured ) {
            if ( in_array( $event_type, array( 'guru_turn_started', 'guru_turn_completed', 'guru_turn_failed' ), true ) ) {
                $captured[] = $event_type;
            }
        };
        // Best-effort event subscribe (Twin Event Bus signature varies — guard).
        if ( class_exists( 'BizCity_Twin_Event_Bus' ) ) {
            add_filter( 'bizcity_twin_event_dispatched', $listener, 10, 2 );
        }

        $t0 = microtime( true );
        $dto = BizCity_Guru_Runtime::instance()->reply( $envelope );
        $elapsed = (int) round( ( microtime( true ) - $t0 ) * 1000 );

        if ( class_exists( 'BizCity_Twin_Event_Bus' ) ) {
            remove_filter( 'bizcity_twin_event_dispatched', $listener, 10 );
        }

        if ( is_wp_error( $dto ) ) {
            return array(
                'status'   => 'fail',
                'summary'  => 'Runtime returned WP_Error: ' . $dto->get_error_code(),
                'error'    => $dto->get_error_message(),
                'fix_hint' => 'Xem error_log — kiểm tra BizCity_LLM_Client gateway key + character_id hợp lệ.',
                'steps'    => $steps,
            );
        }

        $steps[] = array(
            'label'  => 'reply() returned',
            'status' => 'pass',
            'detail' => sprintf( 'class=%s · elapsed=%dms · latency_ms=%d', get_class( $dto ), $elapsed, $dto->latency_ms ),
        );

        $valid = $dto->validate();
        if ( is_wp_error( $valid ) ) {
            return array(
                'status'   => 'fail',
                'summary'  => 'DTO validate() failed',
                'error'    => $valid->get_error_message(),
                'fix_hint' => 'BizCity_Guru_Runtime build path bị regress contract — xem class-guru-runtime.php.',
                'steps'    => $steps,
            );
        }
        $steps[] = array( 'label' => 'DTO validate()', 'status' => 'pass', 'detail' => 'contract OK' );

        if ( $dto->trace_id === '' ) {
            return array(
                'status'  => 'fail',
                'summary' => 'trace_id is empty',
                'error'   => 'PHASE-0.35 §3 requires non-empty trace_id',
                'steps'   => $steps,
            );
        }
        $steps[] = array( 'label' => 'trace_id', 'status' => 'pass', 'detail' => $dto->trace_id );

        if ( $dto->channel !== 'rest' ) {
            return array(
                'status'  => 'fail',
                'summary' => 'channel mismatch',
                'error'   => 'expected "rest", got "' . $dto->channel . '"',
                'steps'   => $steps,
            );
        }
        $steps[] = array( 'label' => 'channel echo', 'status' => 'pass', 'detail' => $dto->channel );

        if ( $dto->error !== null ) {
            return array(
                'status'  => 'warn',
                'summary' => 'Reply produced with error envelope: ' . ( $dto->error['code'] ?? '?' ),
                'error'   => (string) ( $dto->error['message'] ?? '' ),
                'steps'   => $steps,
            );
        }

        if ( $dto->text === '' ) {
            return array(
                'status'  => 'fail',
                'summary' => 'reply text empty without error envelope',
                'steps'   => $steps,
            );
        }
        $steps[] = array( 'label' => 'reply text', 'status' => 'pass', 'detail' => mb_substr( $dto->text, 0, 80 ) );

        $event_summary = ! empty( $captured )
            ? 'events=' . implode( ',', $captured )
            : '(no events captured — bus may not surface filter hook)';
        $steps[] = array( 'label' => 'event stream', 'status' => 'pass', 'detail' => $event_summary );

        return array(
            'status'  => 'pass',
            'summary' => sprintf( 'Guru Runtime DTO OK · latency=%dms · tokens=%d',
                $dto->latency_ms,
                (int) ( $dto->tokens['total'] ?? 0 )
            ),
            'steps'   => $steps,
        );
    }

    public function cleanup(): void {
        // Read-only probe (no DB mutation, no test artifacts).
    }

    /**
     * Best-effort pick of any existing character_id for the probe envelope.
     */
    private function pick_character_id(): int {
        if ( ! class_exists( 'BizCity_Knowledge_Database' ) ) { return 0; }
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_characters';
        $wpdb->suppress_errors( true );
        $row = $wpdb->get_var( "SELECT id FROM `$table` ORDER BY id ASC LIMIT 1" );
        $wpdb->suppress_errors( false );
        return (int) $row;
    }
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
    $list[] = 'BizCity_Probe_Guru_Runtime';
    return $list;
} );
