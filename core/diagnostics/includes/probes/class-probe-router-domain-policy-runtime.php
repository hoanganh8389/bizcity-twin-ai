<?php
/**
 * Runtime contract probe for the Hub domain-policy warn state machine.
 *
 * This probe is read-only apart from a short named-lock acquire/release check.
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

if ( class_exists( 'BizCity_Probe_Router_Domain_Policy_Runtime', false ) ) {
    return;
}

final class BizCity_Probe_Router_Domain_Policy_Runtime implements BizCity_Diagnostics_Probe {

    const LOCK_NAME = 'bizcity_diag_domain_policy_probe';

    public function id(): string {
        return 'router.domain-policy.runtime';
    }

    public function label(): string {
        return 'Router domain policy warn runtime';
    }

    public function description(): string {
        return 'Checks G7.4 schema, state resolution, checkpoint/lock contract and retention support-hold fields without provider calls.';
    }

    public function severity(): string {
        return 'critical';
    }

    public function order(): int {
        return 18;
    }

    public function icon(): string {
        return 'shield-check';
    }

    public function estimate_ms(): int {
        return 250;
    }

    public function precondition() {
        // [2026-09-02 Johnny Chu] R-GW-8/B2C-G7.4 — domain-policy activation evidence belongs to the B1 Hub; B2 clients are intentionally not Router owners.
        $host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( preg_replace( '/:\d+$/', '', (string) $_SERVER['HTTP_HOST'] ) ) : '';
        if ( ! in_array( $host, array( 'bizcity.vn', 'www.bizcity.vn' ), true ) ) {
            return 'not_applicable_b2_client: Router domain policy is owned and enforced at the B1 Hub.';
        }
        // [2026-09-01 Johnny Chu] B2C-G7.4 — diagnostics CLI may not load the Hub plugin; load only the guarded classes required by this read-only probe.
        $router_dir = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR . '/bizcity-llm-router/includes/' : '';
        $load_map  = array(
            'BizCity_Router_Auth'  => 'class-router-auth.php',
            'BizCity_Router_Usage' => 'class-router-usage.php',
        );
        foreach ( $load_map as $class_name => $file_name ) {
            $file = $router_dir . $file_name;
            if ( ! class_exists( $class_name ) && $router_dir !== '' && is_file( $file ) && is_readable( $file ) && class_exists( 'BizCity_Safe_Loader' ) ) {
                BizCity_Safe_Loader::require_file( $file, 'diagnostics.router_domain_policy.' . $class_name );
            }
        }
        if ( ! class_exists( 'BizCity_Router_Auth' ) || ! class_exists( 'BizCity_Router_Usage' ) ) {
            return new WP_Error( 'router_policy_classes_missing', 'Router Auth/Usage classes are not loaded.' );
        }
        global $wpdb;
        if ( ! $wpdb || ! $wpdb->dbh ) {
            return new WP_Error( 'router_policy_db_missing', 'WordPress database connection is not available.' );
        }
        return true;
    }

    public function run( $ctx ): array {
        global $wpdb;
        // [2026-09-02 Johnny Chu] R-GW-8/B2C-G7.4 — distinguish active Hub lifecycle evidence from diagnostics-only class loading.
        $router_plugin_file = 'bizcity-llm-router/bizcity-llm-router.php';
        $active_plugins     = (array) get_option( 'active_plugins', array() );
        $network_plugins    = (array) get_site_option( 'active_sitewide_plugins', array() );
        $router_is_active   = in_array( $router_plugin_file, $active_plugins, true ) || isset( $network_plugins[ $router_plugin_file ] );
        $ctx->emit_step( array(
            'label'  => 'Router plugin activation',
            'status' => $router_is_active ? 'pass' : 'fail',
            'detail' => $router_is_active ? 'bizcity-llm-router is active in the current Hub lifecycle' : 'Router classes may be forced-loaded by diagnostics, but the plugin is inactive',
        ) );
        if ( ! $router_is_active ) {
            return array( 'status' => 'fail', 'summary' => 'Router plugin is inactive; domain-policy probe cannot claim Hub runtime.', 'error' => 'router_plugin_inactive', 'fix_hint' => 'Activate bizcity-llm-router on the B1 Hub, then rerun the focused domain-policy probe with --host=bizcity.vn.' );
        }
        $keys_table = $wpdb->base_prefix . 'bizcity_llm_api_keys';
        $logs_table = $wpdb->base_prefix . 'bizcity_llm_usage_logs';
        $key_columns = $wpdb->get_col( "SHOW COLUMNS FROM {$keys_table}", 0 ) ?: array();
        $log_columns = $wpdb->get_col( "SHOW COLUMNS FROM {$logs_table}", 0 ) ?: array();
        $required_keys = array( 'domain_verified_at', 'domain_verification_expires_at', 'domain_verification_grace_until', 'domain_last_checked_at', 'domain_last_check_reason', 'domain_verification_failure_count' );
        $required_logs = array( 'domain_policy_state', 'domain_policy_reason', 'domain_signal_source', 'domain_allowed_hash', 'domain_client_hash', 'domain_policy_action', 'policy_correlation_id', 'support_hold_until', 'support_hold_reason' );
        $missing_keys = array_diff( $required_keys, $key_columns );
        $missing_logs = array_diff( $required_logs, $log_columns );

        $ctx->emit_step( array(
            'label'  => 'G7.4 schema fields',
            'status' => empty( $missing_keys ) && empty( $missing_logs ) ? 'pass' : 'fail',
            'detail' => empty( $missing_keys ) && empty( $missing_logs ) ? 'verification/audit/support-hold fields present' : 'missing fields detected',
        ) );
        if ( $missing_keys || $missing_logs ) {
            return array(
                'status'   => 'fail',
                'summary'  => 'G7.4 schema fields are incomplete.',
                'error'    => implode( ',', array_merge( $missing_keys, $missing_logs ) ),
                'fix_hint' => 'Run the Router schema migration in a maintenance context, then rerun this probe.',
            );
        }

        $request_match = new WP_REST_Request( 'GET', '/bizcity/v1/llm/chat' );
        $request_match->set_header( 'X-BizCity-Client-Site', 'example.com' );
        $request_missing = new WP_REST_Request( 'GET', '/bizcity/v1/llm/chat' );
        $request_mismatch = new WP_REST_Request( 'GET', '/bizcity/v1/llm/chat' );
        $request_mismatch->set_header( 'X-BizCity-Client-Site', 'other.example' );
        $matrix = array(
            'legacy_unbound' => array( 'allowed_domain' => '', 'domain_verified' => 0, 'request' => $request_match ),
            'development' => array( 'allowed_domain' => 'localhost', 'domain_verified' => 0, 'request' => $request_match ),
            'domain_changed' => array( 'allowed_domain' => 'example.com', 'domain_verified' => 0, 'domain_last_check_reason' => 'domain_just_changed', 'request' => $request_match ),
            'production_unverified' => array( 'allowed_domain' => 'example.com', 'domain_verified' => 0, 'request' => $request_match ),
            'production_verified_missing_signal' => array( 'allowed_domain' => 'example.com', 'domain_verified' => 1, 'domain_verification_expires_at' => date( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ), 'request' => $request_missing ),
            'production_verified_match' => array( 'allowed_domain' => 'example.com', 'domain_verified' => 1, 'domain_verification_expires_at' => date( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ), 'request' => $request_match ),
            'production_verified_mismatch' => array( 'allowed_domain' => 'example.com', 'domain_verified' => 1, 'domain_verification_expires_at' => date( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ), 'request' => $request_mismatch ),
            'verification_stale' => array( 'allowed_domain' => 'example.com', 'domain_verified' => 1, 'domain_verification_expires_at' => '2000-01-01 00:00:00', 'request' => $request_match ),
        );
        $states_ok = true;
        foreach ( $matrix as $expected => $row ) {
            $actual = BizCity_Router_Auth::resolve_domain_policy_state( (object) $row, $row['request'] );
            if ( (string) ( $actual['state'] ?? '' ) !== $expected || (string) ( $actual['action'] ?? '' ) !== 'warn' ) {
                $states_ok = false;
            }
        }
        $ctx->emit_step( array(
            'label'  => 'Warn state matrix',
            'status' => $states_ok ? 'pass' : 'fail',
            'detail' => $states_ok ? '8 canonical states resolve to warn without denial' : 'state/action mismatch',
        ) );
        if ( ! $states_ok ) {
            return array( 'status' => 'fail', 'summary' => 'G7 warn state matrix failed.', 'error' => 'unexpected_state', 'fix_hint' => 'Inspect resolve_domain_policy_state() precedence and warn action.' );
        }

        $lock_acquired = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', self::LOCK_NAME ) );
        $lock_released = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::LOCK_NAME ) );
        $checkpoint = get_option( 'bizcity_router_domain_recheck_checkpoint', array() );
        $checkpoint_ok = empty( $checkpoint ) || ( is_array( $checkpoint ) && isset( $checkpoint['run_id'], $checkpoint['last_id'], $checkpoint['processed'] ) );
        $ctx->emit_step( array(
            'label'  => 'Re-check lock/checkpoint',
            'status' => 1 === $lock_acquired && 1 === $lock_released && $checkpoint_ok ? 'pass' : 'fail',
            'detail' => $checkpoint_ok ? 'named lock acquire/release and checkpoint shape OK' : 'checkpoint shape invalid',
        ) );
        if ( 1 !== $lock_acquired || 1 !== $lock_released || ! $checkpoint_ok ) {
            return array( 'status' => 'fail', 'summary' => 'G7.4 batch safety contract failed.', 'error' => 'lock_or_checkpoint', 'fix_hint' => 'Check GET_LOCK/RELEASE_LOCK ownership and checkpoint fields.' );
        }

        $hold_callable = is_callable( array( 'BizCity_Router_Usage', 'set_domain_policy_support_hold' ) );
        $purge_callable = is_callable( array( 'BizCity_Router_Usage', 'purge_expired_domain_policy_audit' ) );
        $ctx->emit_step( array(
            'label'  => 'Retention/support hold contract',
            'status' => $hold_callable && $purge_callable ? 'pass' : 'fail',
            'detail' => $hold_callable && $purge_callable ? 'bounded hold and retention purge callable' : 'usage retention methods missing',
        ) );
        if ( ! $hold_callable || ! $purge_callable ) {
            return array( 'status' => 'fail', 'summary' => 'Retention/support hold methods are unavailable.', 'error' => 'usage_retention_contract_missing', 'fix_hint' => 'Load BizCity_Router_Usage with G7.4 retention methods.' );
        }

        return array(
            'status'  => 'pass',
            'summary' => 'G7.4 schema, warn state matrix, lock/checkpoint and retention contract passed without provider calls.',
        );
    }

    public function cleanup(): void {
    }
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
    $list[] = 'BizCity_Probe_Router_Domain_Policy_Runtime';
    return $list;
} );
