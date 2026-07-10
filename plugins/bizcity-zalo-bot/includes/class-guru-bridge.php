<?php
/**
 * BizCity Zalo Bot — Guru Bridge (PHASE-0.35 GURU-ZALO-BOT §1.6).
 *
 * R-GURU-UNIFY first-mover. Replaces the legacy "fire trigger → Chat Gateway
 * → bizcity-admin-hook-zalo → direct LLM" reply path with the unified
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
        // Priority 5 — runs BEFORE BizCity_Zalo_Bot_Gateway_Bridge::bridge_to_gateway() at 10.
        add_action( 'bizcity_zalo_message_received', [ $this, 'maybe_handle' ], 5, 1 );
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

        // [2026-06-18 Johnny Chu] ADMIN-GUIDE — Skip AI reply when user is not linked yet.
        // BizCity_Zalobot_User_Linker::maybe_auto_send_link() (priority 3) sets this flag
        // and already sent a login-link message; no point replying with AI noise.
        if ( ! empty( $GLOBALS['bizcity_zalobot_unlinked_skip'] ) ) { return; }

        // Feature gate: default ON (1), admin can flip to 0 to disable.
        // [2026-06-18 Johnny Chu] ADMIN-GUIDE — default changed from 0 to 1
        if ( (int) get_option( 'bizcity_zalo_guru_enabled', 1 ) !== 1 ) { return; }

        // Runtime + formatter must be present.
        if ( ! class_exists( 'BizCity_Guru_Runtime' ) || ! class_exists( 'BizCity_Channel_Formatter' ) ) {
            return;
        }

        $bot_id    = (int)    ( $message_data['bot_id']         ?? 0 );
        $user_z    = (string) ( $message_data['from_user_id']   ?? '' );
        $text      = trim( (string) ( $message_data['message_text'] ?? '' ) );
        $msg_id    = (string) ( $message_data['message_id']     ?? '' );

        if ( $bot_id <= 0 || $user_z === '' || $text === '' ) { return; }

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

        $sent = $this->dispatch_zalo( $bot_id, $user_z, $send );

        if ( $sent ) {
            // [2026-06-19 Johnny Chu] ADMIN-GUIDE — set global so process_new_zalo_format
            // skips bizcity_gateway_fire_trigger (prevents double-response to user).
            $GLOBALS['bizcity_zalobot_guru_handled'] = true;
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
            remove_action( 'bizcity_zalo_message_received', [ $bridge, 'bridge_to_gateway' ], 10 );
        }
    }
}
