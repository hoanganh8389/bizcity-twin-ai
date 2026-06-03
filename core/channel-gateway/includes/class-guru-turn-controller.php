<?php
/**
 * Bizcity Twin AI — Guru Turn REST Controller (PHASE-0.35 GURU-ZALO-BOT §1.3).
 *
 * Cross-process entry point that fronts `BizCity_Guru_Runtime::reply()` over
 * `POST /bizcity-channel/v1/guru/turn`. Same-site channel plugins SHOULD call
 * the PHP API directly for latency; this REST seam exists for:
 *   - remote channel processes (separate worker, cron-isolated runtime),
 *   - admin smoke-test / diagnostics probes,
 *   - third-party integrations that already speak BizCity Channel namespace.
 *
 * Permission model: caller MUST present either
 *   - a valid WP nonce ('wp_rest') for an admin user, OR
 *   - the shared inter-plugin secret in header `X-BizCity-Guru-Key`
 *     (option `bizcity_guru_internal_key`).
 *
 * Namespace `bizcity-channel/v1` enforced per R-CH-NS.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\ChannelGateway
 * @since      Phase 0.35 (2026-05-26)
 * @license    GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Guru_Turn_Controller', false ) ) {
    return;
}

final class BizCity_Guru_Turn_Controller {

    const NS    = 'bizcity-channel/v1';
    const ROUTE = '/guru/turn';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
    }

    public static function register_routes(): void {
        register_rest_route( self::NS, self::ROUTE, [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'handle_turn' ],
            'permission_callback' => [ __CLASS__, 'permission_check' ],
            'args'                => [
                'character_id' => [ 'type' => 'integer', 'required' => true ],
                'channel'      => [ 'type' => 'string',  'required' => true ],
                'prompt'       => [ 'type' => 'string',  'required' => true ],
                'notebook_id'  => [ 'type' => 'integer', 'required' => false ],
                'user_id'      => [ 'type' => 'integer', 'required' => false ],
                'history'      => [ 'type' => 'array',   'required' => false ],
            ],
        ] );
    }

    /**
     * @param WP_REST_Request $request
     * @return true|WP_Error
     */
    public static function permission_check( $request ) {
        // (1) Admin nonce path.
        if ( current_user_can( 'manage_options' ) ) {
            return true;
        }
        // (2) Inter-plugin shared key.
        $key = (string) $request->get_header( 'X-BizCity-Guru-Key' );
        $expected = (string) get_option( 'bizcity_guru_internal_key', '' );
        if ( $expected !== '' && hash_equals( $expected, $key ) ) {
            return true;
        }
        return new WP_Error( 'rest_forbidden', 'Authentication required for guru/turn', [ 'status' => 401 ] );
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public static function handle_turn( $request ) {
        if ( ! class_exists( 'BizCity_Guru_Runtime' ) ) {
            return new WP_Error( 'guru_runtime_missing', 'BizCity_Guru_Runtime not loaded', [ 'status' => 500 ] );
        }

        $envelope = [
            'character_id' => (int)    $request->get_param( 'character_id' ),
            'notebook_id'  => (int)    $request->get_param( 'notebook_id' ),
            'channel'      => (string) $request->get_param( 'channel' ),
            'prompt'       => (string) $request->get_param( 'prompt' ),
            'user_id'      => (int)    $request->get_param( 'user_id' ),
            'history'      => (array)  ( $request->get_param( 'history' ) ?: [] ),
            'attachments'  => (array)  ( $request->get_param( 'attachments' ) ?: [] ),
        ];

        $result = BizCity_Guru_Runtime::instance()->reply( $envelope );
        if ( is_wp_error( $result ) ) {
            return new WP_Error(
                $result->get_error_code(),
                $result->get_error_message(),
                [ 'status' => 422 ]
            );
        }

        return new WP_REST_Response( $result->to_array(), 200 );
    }
}
