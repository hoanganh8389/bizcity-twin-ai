<?php
/**
 * DDV probe for the PiAPI image-task gateway/client boundary.
 *
 * Uses WordPress HTTP and option filters only; no provider credential or real
 * PiAPI request is used.
 *
 * @package Bizcity_Twin_AI
 * @since 1.25.0
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

if ( class_exists( 'BizCity_Probe_PiAPI_Image_Task', false ) ) {
    return;
}

final class BizCity_Probe_PiAPI_Image_Task implements BizCity_Diagnostics_Probe {

    public function id(): string { return 'core.piapi.image_task'; }
    public function label(): string { return 'PiAPI image-task gateway/client contract'; }
    public function description(): string { return 'Mock kiểm tra route, key boundary, idempotency, trace và polling của PiAPI image task.'; }
    public function severity(): string { return 'critical'; }
    public function order(): int { return 19; }
    public function icon(): string { return 'image'; }
    public function estimate_ms(): int { return 300; }
    public function precondition() { return true; }

    public function run( $ctx ): array {
        // [2026-08-10 Johnny Chu] PHASE-1.25-PIAPI-DDV — mock gateway contract evidence.
        $steps = array();
        $root  = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) ) . '/';
        $client_file = $root . 'core/bizcity-llm/includes/class-piapi-client.php';
        $disk_ok = is_readable( $client_file );
        $steps[] = array(
            'label'  => 'Disk — PiAPI client exists',
            'status' => $disk_ok ? 'pass' : 'fail',
            'detail' => $client_file,
        );
        if ( ! $disk_ok ) {
            return array( 'status' => 'fail', 'summary' => 'PiAPI client file is missing.', 'steps' => $steps );
        }

        $loaded = class_exists( 'BizCity_PiAPI_Client' ) && method_exists( 'BizCity_PiAPI_Client', 'submit_image_task' );
        $steps[] = array(
            'label'  => 'Loader — PiAPI client is loaded',
            'status' => $loaded ? 'pass' : 'fail',
            'detail' => $loaded ? 'BizCity_PiAPI_Client' : 'missing class or method',
        );
        if ( ! $loaded ) {
            return array( 'status' => 'fail', 'summary' => 'PiAPI client is not loaded.', 'steps' => $steps );
        }

        $captured      = array();
        $submit_attempts = 0;
        $http_filter = function ( $pre, $args, $url ) use ( &$captured, &$submit_attempts ) {
            $captured[] = array( 'url' => (string) $url, 'args' => is_array( $args ) ? $args : array() );
            if ( strpos( (string) $url, '/image-task' ) !== false ) {
                $request_body = json_decode( (string) ( $args['body'] ?? '' ), true );
                if ( (string) ( $request_body['operation'] ?? '' ) === 'image_edit' ) {
                    return array(
                        'headers'  => array( 'content-type' => 'application/json' ),
                        'body'     => wp_json_encode( array( 'success' => false, 'code' => 'unsupported_operation', 'message' => 'mock unsupported operation' ) ),
                        'response' => array( 'code' => 400, 'message' => 'Bad Request' ),
                    );
                }
                $submit_attempts++;
                if ( $submit_attempts === 1 ) {
                    return array(
                        'headers'  => array( 'content-type' => 'application/json' ),
                        'body'     => wp_json_encode( array( 'success' => false, 'code' => 'provider_error', 'message' => 'mock upstream failure' ) ),
                        'response' => array( 'code' => 503, 'message' => 'Service Unavailable' ),
                    );
                }
                return array(
                    'headers'  => array( 'content-type' => 'application/json' ),
                    'body'     => wp_json_encode( array( 'success' => true, 'task_id' => 'probe_img_001', 'operation' => 'remove_background', 'status' => 'pending' ) ),
                    'response' => array( 'code' => 202, 'message' => 'Accepted' ),
                );
            }
            if ( strpos( (string) $url, 'probe_auth_001' ) !== false ) {
                return array(
                    'headers'  => array( 'content-type' => 'application/json' ),
                    'body'     => wp_json_encode( array( 'error' => 'invalid token' ) ),
                    'response' => array( 'code' => 401, 'message' => 'Unauthorized' ),
                );
            }
            return array(
                'headers'  => array( 'content-type' => 'application/json' ),
                'body'     => wp_json_encode( array( 'success' => true, 'task_id' => 'probe_img_001', 'status' => 'completed', 'output' => array( 'image_url' => 'https://cdn.invalid/probe.png' ) ) ),
                'response' => array( 'code' => 200, 'message' => 'OK' ),
            );
        };

        add_filter( 'pre_option_bizcity_llm_gateway_url', array( __CLASS__, 'gateway_url' ) );
        add_filter( 'pre_option_bizcity_llm_api_key', array( __CLASS__, 'gateway_key' ) );
        add_filter( 'pre_http_request', $http_filter, 10, 3 );
        $client = BizCity_PiAPI_Client::instance();
        $submit = $client->submit_image_task( 'remove_background', array( 'image_url' => 'https://example.invalid/source.png' ), array( 'idempotency_key' => 'probe_img_idem', 'trace_id' => 'probe_img_trace' ) );
        $poll   = $client->get_task( 'probe_img_001' );
        $auth_failure = $client->get_task( 'probe_auth_001' );
        $unsupported = $client->submit_image_task( 'image_edit', array( 'image_url' => 'https://example.invalid/source.png' ), array( 'idempotency_key' => 'probe_img_unsupported', 'trace_id' => 'probe_img_trace_unsupported' ) );
        remove_filter( 'pre_option_bizcity_llm_gateway_url', array( __CLASS__, 'gateway_url' ) );
        remove_filter( 'pre_option_bizcity_llm_api_key', array( __CLASS__, 'gateway_key' ) );
        remove_filter( 'pre_http_request', $http_filter, 10 );

        $first = $captured[0] ?? array( 'url' => '', 'args' => array() );
        $headers = is_array( $first['args']['headers'] ?? null ) ? $first['args']['headers'] : array();
        $runtime_ok = ! empty( $submit['success'] )
            && (string) ( $submit['task_id'] ?? '' ) === 'probe_img_001'
            && ! empty( $poll['success'] )
            && strpos( (string) $first['url'], '/wp-json/piapi/router/v1/image-task' ) !== false
            && ! empty( $headers['Authorization'] )
            && strpos( (string) $headers['Authorization'], 'Bearer biz-' ) === 0
            && ! empty( $headers['X-Trace-Id'] )
            && ! empty( $headers['X-Idempotency-Key'] )
            && $submit_attempts >= 2;
        $steps[] = array(
            'label'  => 'Runtime — mock submit/poll retries and preserves gateway contract',
            'status' => $runtime_ok ? 'pass' : 'fail',
            'detail' => wp_json_encode( array( 'submit' => $submit, 'poll' => $poll, 'request_count' => count( $captured ), 'submit_attempts' => $submit_attempts ) ),
        );

        $unsupported_ok = empty( $unsupported['success'] )
            && (string) ( $unsupported['code'] ?? '' ) === 'unsupported_operation';
        $steps[] = array(
            'label'  => 'Runtime — unsupported image operation fails closed',
            'status' => $unsupported_ok ? 'pass' : 'fail',
            'detail' => wp_json_encode( $unsupported ),
        );

        $auth_ok = empty( $auth_failure['success'] )
            && (string) ( $auth_failure['code'] ?? '' ) === 'auth_required'
            && ! empty( $auth_failure['trace_id'] );
        $steps[] = array(
            'label'  => 'Runtime — legacy HTTP auth failure maps to error envelope',
            'status' => $auth_ok ? 'pass' : 'fail',
            'detail' => wp_json_encode( $auth_failure ),
        );

        return array(
            'status'  => $runtime_ok && $unsupported_ok && $auth_ok ? 'pass' : 'fail',
            'summary' => $runtime_ok && $unsupported_ok && $auth_ok ? 'PiAPI image-task mock contract passed.' : 'PiAPI image-task mock contract failed.',
            'fix_hint'=> 'Load BizCity_PiAPI_Client and verify gateway route/header propagation.',
            'steps'   => $steps,
        );
    }

    public static function gateway_url( $value ) { return 'https://piapi-probe.invalid'; }
    public static function gateway_key( $value ) { return 'biz-aaaaaaaaaaaaaaaa'; }
    public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $probes ) {
    $probes[] = 'BizCity_Probe_PiAPI_Image_Task';
    return $probes;
} );
