<?php
/**
 * Runtime contract probe for B2B2C account onboarding routes and denial UX.
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

if ( class_exists( 'BizCity_Probe_B2B2C_Account_Onboarding', false ) ) {
    return;
}

final class BizCity_Probe_B2B2C_Account_Onboarding implements BizCity_Diagnostics_Probe {

    public function id(): string {
        return 'b2b2c.account.onboarding';
    }

    public function label(): string {
        return 'B2B2C account onboarding';
    }

    public function description(): string {
        return 'Checks account routes, onboarding redirect and unauthenticated denial envelope without creating customer data.';
    }

    public function severity(): string {
        return 'critical';
    }

    public function order(): int {
        return 19;
    }

    public function icon(): string {
        return 'user-round-check';
    }

    public function estimate_ms(): int {
        return 300;
    }

    public function precondition() {
        // [2026-09-02 Johnny Chu] R-GW-8/B2C-F4 — this account-owner probe targets the B1 Hub only; B2 clients must use their client/proxy probes.
        $host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( preg_replace( '/:\d+$/', '', (string) $_SERVER['HTTP_HOST'] ) ) : '';
        if ( ! in_array( $host, array( 'bizcity.vn', 'www.bizcity.vn' ), true ) ) {
            return 'not_applicable_b2_client: Router Account Experience is owned by the B1 Hub, not this client host.';
        }
        $router_dir = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR . '/bizcity-llm-router/includes/' : '';
        $load_map  = array(
            'BizCity_Router_Auth'             => 'class-router-auth.php',
            'BizCity_Router_Usage'            => 'class-router-usage.php',
            'BizCity_Router_Account'          => 'class-router-account-rest.php',
            'BizCity_Router_Account_Experience' => 'class-router-account-experience.php',
            'BizCity_Router_Master_Schema'    => 'class-router-master-schema.php',
        );
        foreach ( $load_map as $class_name => $file_name ) {
            $file = $router_dir . $file_name;
            if ( ! class_exists( $class_name ) && $router_dir !== '' && is_file( $file ) && is_readable( $file ) && class_exists( 'BizCity_Safe_Loader' ) ) {
                BizCity_Safe_Loader::require_file( $file, 'diagnostics.b2b2c_onboarding.' . $class_name );
            }
        }
        if ( ! class_exists( 'BizCity_Router_Account' ) || ! class_exists( 'BizCity_Router_Account_Experience' ) ) {
            return new WP_Error( 'account_onboarding_classes_missing', 'Account REST/Experience classes are not loaded.' );
        }
        return true;
    }

    public function run( $ctx ): array {
        // [2026-09-02 Johnny Chu] B2C-F7 — preserve the diagnostics admin identity while testing unauthenticated access.
        $previous_user_id = (int) get_current_user_id();
        $fixture_key_id = 0;
        $fixture_user_id = 0;
        $fixture_plain_key = '';
        $cleanup_fixture = function () use ( &$fixture_key_id, &$fixture_user_id, &$fixture_plain_key ) {
            if ( $fixture_key_id <= 0 || $fixture_user_id <= 0 || $fixture_plain_key === '' ) {
                return;
            }
            global $wpdb;
            $table = $wpdb->base_prefix . 'bizcity_llm_api_keys';
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$table} WHERE id = %d AND user_id = %d AND key_hash = %s",
                $fixture_key_id,
                $fixture_user_id,
                hash( 'sha256', $fixture_plain_key )
            ) );
            $fixture_key_id = 0;
            $fixture_user_id = 0;
            $fixture_plain_key = '';
        };
        // [2026-09-01 Johnny Chu] B2C-F4 — use an isolated REST server so route registration does not fire unrelated rest_api_init installers in diagnostics.
        if ( class_exists( 'WP_REST_Server' ) && ! did_action( 'rest_api_init' ) ) {
            $GLOBALS['wp_rest_server'] = new WP_REST_Server();
        }
        // [2026-09-01 Johnny Chu] B2C-F4-DEPLOY-GATE — forced probe loading must not masquerade as an active live plugin.
        $router_plugin_file = 'bizcity-llm-router/bizcity-llm-router.php';
        $active_plugins     = (array) get_option( 'active_plugins', array() );
        $network_plugins    = (array) get_site_option( 'active_sitewide_plugins', array() );
        $router_is_active   = in_array( $router_plugin_file, $active_plugins, true ) || isset( $network_plugins[ $router_plugin_file ] );
        $ctx->emit_step( array(
            'label'  => 'Router plugin activation',
            'status' => $router_is_active ? 'pass' : 'fail',
            'detail' => $router_is_active ? 'bizcity-llm-router is active in the current WordPress lifecycle' : 'Router classes may be forced-loaded by diagnostics, but the plugin is inactive',
        ) );
        if ( ! $router_is_active ) {
            return array( 'status' => 'fail', 'summary' => 'Router plugin is inactive; account probe cannot claim deployed runtime.', 'error' => 'router_plugin_inactive', 'fix_hint' => 'Activate bizcity-llm-router, then rerun the focused onboarding probe.' );
        }
        BizCity_Router_Account::register_routes();
        BizCity_Router_Account_Experience::register_routes();
        $routes = rest_get_server()->get_routes();
        $required = array(
            'bizcity/v1/account/api-keys',
            'bizcity/v1/account/api-keys/status',
            'bizcity/v1/account/api-keys/limit-request',
            'bizcity/v1/account/api-keys/(?P<id>\\d+)',
            'bizcity/v1/account/api-keys/(?P<id>\\d+)/domain',
            'bizcity/v1/account/api-keys/(?P<id>\\d+)/domain/verification',
            'bizcity/v1/account/api-keys/(?P<id>\\d+)/domain/verify',
            'bizcity/v1/account/plan-checkout',
        );
        $missing = array();
        foreach ( $required as $route ) {
            if ( ! isset( $routes[ '/' . $route ] ) ) {
                $missing[] = $route;
            }
        }
        $ctx->emit_step( array(
            'label'  => 'Account route registration',
            'status' => empty( $missing ) ? 'pass' : 'fail',
            'detail' => empty( $missing ) ? count( $required ) . ' protected/account routes registered' : 'missing route(s)',
        ) );
        if ( $missing ) {
            return array( 'status' => 'fail', 'summary' => 'Account onboarding routes are incomplete.', 'error' => implode( ',', $missing ), 'fix_hint' => 'Load Router Account REST/Experience before rest_api_init and rerun the probe.' );
        }

        $onboarding = BizCity_Router_Account_Experience::registration_redirect( home_url( '/my-account/' ) );
        $onboarding_ok = (string) wp_parse_url( $onboarding, PHP_URL_QUERY ) !== '' && false !== strpos( $onboarding, 'bizcity_onboarding=1' );
        $ctx->emit_step( array(
            'label'  => 'Registration onboarding redirect',
            'status' => $onboarding_ok ? 'pass' : 'fail',
            'detail' => $onboarding_ok ? 'new registration points to My Account onboarding' : 'onboarding query flag missing',
        ) );
        if ( ! $onboarding_ok ) {
            return array( 'status' => 'fail', 'summary' => 'Registration onboarding redirect contract failed.', 'error' => 'onboarding_redirect_missing', 'fix_hint' => 'Preserve bizcity_onboarding=1 in registration_redirect().' );
        }

        wp_set_current_user( 0 );
        $request = new WP_REST_Request( 'POST', '/bizcity/v1/account/api-keys' );
        $denial = BizCity_Router_Account::handle_create_key( $request );
        $denial_data = $denial instanceof WP_REST_Response ? $denial->get_data() : array();
        $denial_status = $denial instanceof WP_REST_Response ? (int) $denial->get_status() : 0;
        $denial_ok = 401 === $denial_status
            && is_array( $denial_data )
            && ! empty( $denial_data['code'] )
            && ! empty( $denial_data['message'] )
            && ! empty( $denial_data['hint'] )
            && ! empty( $denial_data['help_code'] );
        $ctx->emit_step( array(
            'label'  => 'Unauthenticated create-key denial',
            'status' => $denial_ok ? 'pass' : 'fail',
            'detail' => $denial_ok ? '401 with code/message/hint/help_code' : 'denial envelope incomplete',
        ) );
        // [2026-09-02 Johnny Chu] B2C-F7 — restore the caller before any later step or early return.
        wp_set_current_user( $previous_user_id );
        if ( ! $denial_ok ) {
            return array( 'status' => 'fail', 'summary' => 'Unauthenticated account denial contract failed.', 'error' => 'account_error_envelope', 'fix_hint' => 'Return the standard account error payload before any key issuance.' );
        }

        $route_permissions_ok = true;
        foreach ( $required as $route ) {
            foreach ( (array) $routes[ '/' . $route ] as $endpoint ) {
                if ( isset( $endpoint['methods'] ) && isset( $endpoint['permission_callback'] )
                    && false !== strpos( (string) $route, '/account/' )
                    && is_string( $endpoint['permission_callback'] )
                    && 'is_user_logged_in' !== $endpoint['permission_callback']
                    && 'bizcity/v1/account/api-keys' === $route
                    && isset( $endpoint['callback'] )
                    && 'handle_create_key' === ( is_array( $endpoint['callback'] ) ? (string) end( $endpoint['callback'] ) : '' ) ) {
                    $route_permissions_ok = false;
                }
            }
        }
        $ctx->emit_step( array(
            'label'  => 'Create-key permission boundary',
            'status' => $route_permissions_ok ? 'pass' : 'fail',
            'detail' => $route_permissions_ok ? 'create route requires logged-in user' : 'create route permission callback is too broad',
        ) );
        if ( ! $route_permissions_ok ) {
            return array( 'status' => 'fail', 'summary' => 'Create-key permission boundary is too broad.', 'error' => 'create_permission_callback', 'fix_hint' => 'Set permission_callback to is_user_logged_in for member key creation.' );
        }

        global $wpdb;
        $fixture_user_id = (int) $wpdb->get_var(
            "SELECT u.ID
             FROM {$wpdb->users} u
             LEFT JOIN {$wpdb->base_prefix}bizcity_llm_api_keys k ON k.user_id = u.ID AND k.is_active = 1
             GROUP BY u.ID
             ORDER BY COUNT(k.id) ASC, u.ID ASC
             LIMIT 1"
        );
        if ( $fixture_user_id <= 0 ) {
            return array( 'status' => 'fail', 'summary' => 'No existing WordPress user is available for the disposable success fixture.', 'error' => 'fixture_user_missing', 'fix_hint' => 'Provide an existing test/admin user in the local WordPress database; the probe never creates one.' );
        }
        wp_set_current_user( $fixture_user_id );
        $fixture_domain = 'healthtest-' . strtolower( wp_generate_uuid4() ) . '.example.com';
        $fixture_request = new WP_REST_Request( 'POST', '/bizcity/v1/account/api-keys' );
        $fixture_request->set_header( 'Content-Type', 'application/json' );
        $fixture_request->set_body( wp_json_encode( array( 'label' => 'Diagnostics onboarding fixture', 'domain' => $fixture_domain ) ) );
        $fixture_response = BizCity_Router_Account::handle_create_key( $fixture_request );
        $fixture_data = $fixture_response instanceof WP_REST_Response ? $fixture_response->get_data() : array();
        $fixture_plain_key = is_array( $fixture_data ) ? (string) ( $fixture_data['api_key'] ?? '' ) : '';
        if ( $fixture_plain_key !== '' ) {
            global $wpdb;
            $fixture_key_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$wpdb->base_prefix}bizcity_llm_api_keys WHERE user_id = %d AND key_hash = %s LIMIT 1",
                $fixture_user_id,
                hash( 'sha256', $fixture_plain_key )
            ) );
        }
        $fixture_success = $fixture_response instanceof WP_REST_Response
            && 201 === (int) $fixture_response->get_status()
            && ! empty( $fixture_data['ok'] )
            && $fixture_plain_key !== ''
            && $fixture_key_id > 0;
        $fixture_level = $fixture_key_id > 0 ? (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT master_level FROM {$wpdb->base_prefix}bizcity_llm_api_keys WHERE id = %d AND user_id = %d LIMIT 1",
            $fixture_key_id,
            $fixture_user_id
        ) ) : '';
        $fixture_success = $fixture_success && BizCity_Router_Master_Schema::LEVEL_FREE === $fixture_level;
        $fixture_detail = $fixture_response instanceof WP_REST_Response
            ? 'status=' . (int) $fixture_response->get_status() . '; fields=' . implode( ',', array_keys( is_array( $fixture_data ) ? $fixture_data : array() ) )
            : 'response_type=' . ( is_object( $fixture_response ) ? get_class( $fixture_response ) : gettype( $fixture_response ) );
        if ( ! empty( $wpdb->last_error ) ) {
            $fixture_detail .= '; db_error=present';
        }
        $ctx->emit_step( array(
            'label'  => 'Logged-in create-key success fixture',
            'status' => $fixture_success ? 'pass' : 'fail',
            'detail' => $fixture_success ? '201 response, secret issued once, domain stored, master_level=free' : $fixture_detail,
        ) );
        $cleanup_user_id = $fixture_user_id;
        $cleanup_domain  = $fixture_domain;
        $cleanup_fixture();
        $cleanup_remaining = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->base_prefix}bizcity_llm_api_keys WHERE user_id = %d AND allowed_domain = %s",
                $cleanup_user_id,
                $cleanup_domain
            ) );
        $cleanup_ok = 0 === $cleanup_remaining;
        $ctx->emit_step( array(
            'label'  => 'Disposable fixture cleanup',
            'status' => $cleanup_ok ? 'pass' : 'fail',
            'detail' => $cleanup_ok ? 'test key row removed after assertion' : 'test key row remains',
        ) );
        // [2026-09-02 Johnny Chu] B2C-F7 — leave subsequent probes in the original admin context.
        wp_set_current_user( $previous_user_id );
        if ( ! $fixture_success || ! $cleanup_ok ) {
            return array( 'status' => 'fail', 'summary' => 'Logged-in API key creation success fixture failed.', 'error' => 'create_key_success_fixture', 'fix_hint' => 'Inspect handle_create_key(), domain persistence and Free-first key initialization.' );
        }

        return array(
            'status'  => 'pass',
            'summary' => 'Account route, onboarding redirect, denial and disposable logged-in create-key contract passed; fixture row was cleaned up and no provider call was made.',
        );
    }

    public function cleanup(): void {
    }
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
    $list[] = 'BizCity_Probe_B2B2C_Account_Onboarding';
    return $list;
} );
