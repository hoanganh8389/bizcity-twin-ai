<?php
/**
 * BizCity Zalo Bot — Guru Bridge (PHASE-0.35 GURU-ZALO-BOT §1.6).
 *
 * R-GURU-UNIFY first-mover. Replaces the legacy "fire trigger → Chat Gateway
 * → direct LLM" reply path with the unified
 * `BizCity_Guru_Runtime` pipeline. Concretely, when enabled this class:
 *
 *   1. Hooks `bizcity_zalo_message_received` at priority 5 (BEFORE the
 *      legacy Gateway Bridge at priority 10).
 *   2. Resolves the bot → character_id binding (filter
 *      `bizcity_zalo_guru_character_id`).
 *   3. Calls `BizCity_Guru_Runtime::instance()->reply()`.
 *   4. Formats the DTO via `BizCity_Zalo_Formatter`.
 *   5. Sends through the existing Zalo Bot Platform API.
 *   6. Suppresses the legacy bridge for this turn (removes priority-10 hook
 *      callback so we don't double-respond).
 *
 * **Disabled by default** — flip option `bizcity_zalo_guru_enabled = 1` to
 * opt in. Phase 2 will make this the default once admin UI ships.
 *
 * @package BizCity_Zalo_Bot
 * @since   1.4.1 (PHASE-0.35 2026-05-26)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Zalo_Bot_Guru_Bridge', false ) ) {
    return;
}

class BizCity_Zalo_Bot_Guru_Bridge {

    /** @var self|null */
    private static $instance = null;

    public static function instance(): self {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // [2026-08-09 Johnny Chu] R-CH-UNI — consume the canonical Zone 2 envelope before Gateway Bridge.
        add_action( 'bizcity_channel_normalized', [ $this, 'handle_normalized' ], 5, 2 );
    }

    /**
     * Adapt the canonical envelope to the existing Guru runtime payload shape.
     */
    public function handle_normalized( $envelope, $trigger_key = '' ): void {
        if ( ! is_array( $envelope ) || (string) ( $envelope['platform'] ?? '' ) !== 'ZALO_BOT' ) {
            return;
        }

        $payload = $envelope;
        $payload['code']             = 'zalo_bot';
        $payload['bot_id']           = (int) ( $envelope['account_id'] ?? 0 );
        $payload['from_user_id']     = (string) ( $envelope['user_id'] ?? '' );
        $payload['message_text']     = (string) ( $envelope['message_text_clean'] ?? $envelope['message'] ?? '' );
        $payload['message_id']       = (string) ( $envelope['message_id'] ?? '' );
        $this->maybe_handle( $payload );
    }

    /**
     * Decide whether to take over the turn; if so, dispatch via Guru Runtime
     * and suppress the legacy bridge for this request.
     */
    public function maybe_handle( $message_data ) {
        if ( ! is_array( $message_data ) || empty( $message_data ) ) { return; }

        // [2026-06-07 Johnny Chu] PHASE-0.40 G0.2 R-ZONE-2 — discriminator bail.
        // zalo_oa and zalo_personal carry customer messages (Zone 1 CRM care).
        // This bridge is Zone 2 only — bail so customers don’t trigger admin automation.
        $code = (string) ( $message_data['code'] ?? '' );
        if ( $code === 'zalo_oa' || $code === 'zalo_personal' ) {
            return;
        }

        // Feature gate.
        if ( (int) get_option( 'bizcity_zalo_guru_enabled', 0 ) !== 1 ) { return; }

        // Runtime + formatter must be present.
        if ( ! class_exists( 'BizCity_Channel_Formatter' ) ) {
            return;
        }

        $bot_id    = (int)    ( $message_data['bot_id']         ?? 0 );
        $user_z    = (string) ( $message_data['from_user_id']   ?? '' );
        $text      = trim( (string) ( $message_data['message_text'] ?? '' ) );
        $msg_id    = (string) ( $message_data['message_id']     ?? '' );

        if ( $bot_id <= 0 || $user_z === '' || $text === '' ) { return; }

        // [2026-07-24 Johnny Chu] RULE-INBOUND-DISPATCH-PRIORITY — an enabled
        // automation workflow already claimed this exact message (matched by
        // ref/slash/keyword in BizCity_Automation_Trigger_Matcher, which runs
        // earlier on `bizcity_zalo_webhook_intake`). A matched workflow
        // scenario always outranks the generic Guru AI reply — bail so we
        // don't double-reply on top of the workflow's real answer/ACK.
        if ( $msg_id !== '' && ! empty( $GLOBALS['bizcity_automation_matched_mids'][ $msg_id ] ) ) {
            return;
        }

        // Resolve character binding.
        // Phase 2 priority: BizCity_Channel_Binding table (written by Guru AI card in SPA).
        // Phase 1 fallback: wp_options per-bot, then global default.
        // Final: filter override for programmatic control.
        $char_id = 0;
        if ( class_exists( 'BizCity_Channel_Binding' ) ) {
            $binding = BizCity_Channel_Binding::resolve( 'ZALO', (string) $bot_id );
            if ( $binding && ! empty( $binding['character_id'] ) ) {
                $char_id = (int) $binding['character_id'];
            }
        }
        if ( $char_id <= 0 ) {
            $char_id = (int) get_option( 'bizcity_zalobot_guru_char_' . $bot_id, 0 );
        }
        if ( $char_id <= 0 ) {
            $char_id = (int) get_option( 'bizcity_zalo_guru_default_character_id', 0 );
        }
        $char_id = (int) apply_filters( 'bizcity_zalo_guru_character_id', $char_id, $bot_id, $message_data );

        if ( $char_id <= 0 ) {
            // No binding — let legacy bridge handle it.
            return;
        }

        // Resolve WP user (best-effort, mirrors Gateway Bridge logic).
        $wp_user_id = 0;
        if ( class_exists( 'BizCity_Zalobot_User_Linker' ) ) {
            $wp_user_id = (int) BizCity_Zalobot_User_Linker::resolve_wp_user( $user_z, $bot_id );
        }

        // [2026-08-01 Johnny Chu] PHASE-TWIN-GOAL-LOOP-G3 — opt linked Zalo Bot turns into the canonical TwinBrain pipeline.
        $use_twinbrain = (bool) apply_filters( 'bizcity_zalo_guru_bridge_use_twinbrain', false, $bot_id, $user_z, $message_data, $wp_user_id );
        if ( $use_twinbrain ) {
            if ( $wp_user_id <= 0 ) {
                error_log( '[Zalo Guru Bridge] TwinBrain path requires a linked WordPress user' );
                return;
            }
            if ( ! class_exists( 'BizCity_TwinBrain_Adapter_ZaloBot' ) || ! class_exists( 'BizCity_Guru_Reply_DTO' ) ) {
                error_log( '[Zalo Guru Bridge] TwinBrain adapter unavailable' );
                return;
            }
            $brain_envelope = array(
                'platform'           => 'ZALO_BOT',
                'channel'            => 'ZALO_BOT',
                'account_id'         => (string) $bot_id,
                'external_user_id'   => $user_z,
                'chat_id'            => 'zalobot_' . $bot_id . '_' . $user_z,
                'text'               => $text,
                'wp_user_id'         => $wp_user_id,
                'channel_class'      => 'user_bound',
                'identity_is_stable' => true,
                'identity_guest_bind'=> false,
                'guru_id'            => $char_id,
                'notebook_id'        => (int) apply_filters( 'bizcity_zalo_guru_notebook_id', 0, $bot_id, $char_id, $message_data ),
                'surface'            => 'zalo_bot',
            );
            $brain_res = ( new BizCity_TwinBrain_Adapter_ZaloBot() )->handle( $brain_envelope );
            if ( empty( $brain_res['ok'] ) || (string) ( $brain_res['answer'] ?? '' ) === '' ) {
                error_log( '[Zalo Guru Bridge] TwinBrain adapter returned no answer' );
                return;
            }
            $dto = BizCity_Guru_Reply_DTO::from_array( array(
                'trace_id'     => (string) ( $brain_res['trace_id'] ?? '' ),
                'runtime'      => BizCity_Guru_Reply_DTO::RUNTIME_TWINBRAIN,
                'text'         => (string) $brain_res['answer'],
                'character_id' => $char_id,
                'channel'      => 'zalo',
                'meta'         => array(
                    'wp_user_id'    => $wp_user_id,
                    'identity_uuid' => (string) ( $brain_res['identity_uuid'] ?? '' ),
                    'session_id'    => (string) ( $brain_res['session_id'] ?? '' ),
                ),
            ) );
        }

        if ( ! $use_twinbrain ) {
            // Legacy Guru execution remains the rollback path until channel DDV passes.
            if ( ! class_exists( 'BizCity_Guru_Runtime' ) ) {
                return;
            }
            $envelope = [
            'character_id' => $char_id,
            'notebook_id'  => (int) apply_filters( 'bizcity_zalo_guru_notebook_id', 0, $bot_id, $char_id, $message_data ),
            'channel'      => 'zalo',
            'prompt'       => $text,
            'user_id'      => $wp_user_id,
            'history'      => [],
            'meta'         => [
                'bot_id'       => $bot_id,
                'zalo_user_id' => $user_z,
                'message_id'   => $msg_id,
            ],
            ];

            $dto = BizCity_Guru_Runtime::instance()->reply( $envelope );

            if ( is_wp_error( $dto ) ) {
                error_log( '[Zalo Guru Bridge] runtime error: ' . $dto->get_error_message() );
                return; // Fall through to legacy bridge.
            }
        }

        $formatter = BizCity_Channel_Formatter::for_channel( 'zalo' );
        if ( ! $formatter ) {
            error_log( '[Zalo Guru Bridge] no formatter registered for channel=zalo' );
            return;
        }

        $send = $formatter->format( $dto, [
            'recipient_ref' => $user_z,
            'bot_id'        => $bot_id,
            'zalo_user_id'  => $user_z,
        ] );

        // [2026-08-01 Johnny Chu] PHASE-0.39 GURU-BIND — remove internal citations before direct Zalo Bot delivery.
        if ( class_exists( 'BizCity_Guru_Citation_Formatter' ) ) {
            $send->text = trim( BizCity_Guru_Citation_Formatter::strip( (string) $send->text ) );
        } else {
            $send->text = trim( preg_replace( '/\[(?:src:[A-Za-z0-9_-]+(?:#p|p)\d+|nb:[A-Za-z0-9_-]+\/p\d+|N\d+P\d+)\]/i', '', (string) $send->text ) );
        }

        $sent = $this->dispatch_zalo( $bot_id, $user_z, $send );

        if ( $sent ) {
            // Suppress legacy bridge for this turn.
            $this->suppress_legacy_bridge();
        }
    }

    /**
     * Dispatch the formatted reply via the Zalo Bot Platform API.
     */
    private function dispatch_zalo( int $bot_id, string $zalo_user_id, BizCity_Channel_Send_DTO $send ): bool {
        if ( ! function_exists( 'bizcity_get_zalo_bot_api' ) ) {
            error_log( '[Zalo Guru Bridge] bizcity_get_zalo_bot_api() unavailable' );
            return false;
        }
        $api = bizcity_get_zalo_bot_api( $bot_id );
        if ( ! $api ) {
            error_log( '[Zalo Guru Bridge] bot #' . $bot_id . ' API not initialised' );
            return false;
        }
        try {
            // BizCity Zalo Bot API: send_message($chat_id, $text).
            $result = $api->send_message( $zalo_user_id, $send->text );
            return ! empty( $result );
        } catch ( \Throwable $e ) {
            error_log( '[Zalo Guru Bridge] send_message threw: ' . $e->getMessage() );
            return false;
        }
    }

    private function suppress_legacy_bridge(): void {
        if ( class_exists( 'BizCity_Zalo_Bot_Gateway_Bridge' ) ) {
            $bridge = BizCity_Zalo_Bot_Gateway_Bridge::instance();
            // [2026-08-09 Johnny Chu] R-CH-UNI — suppress the normalized Gateway Bridge callback for this turn.
            remove_action( 'bizcity_channel_normalized', [ $bridge, 'bridge_normalized_to_gateway' ], 10 );
        }
    }
}
