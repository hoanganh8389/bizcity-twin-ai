<?php
/**
 * R-ZP-ERR — contract `zalo-personal-session-errors@1`: the PHP catalog, the published doc and the B2 client
 * mirror must say the same thing, and every code the router (Hub) can emit must fold into a bucket.
 *
 * Pure unit test: no WordPress, no bridge.
 */

use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! function_exists( 'sanitize_key' ) ) {
    function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ); }
}

require_once dirname( __DIR__, 2 ) . '/plugins/bizcity-zalo-personal/includes/shared/class-zalo-session-errors.php';

class ZaloSessionErrorsContractTest extends TestCase {

    private const DOC = '/docs/contracts/ZALO-PERSONAL-SESSION-ERROR-CONTRACT-v1.md';
    private const JS  = '/plugins/bizcity-twin-crm/frontend/src/lib/zaloSession.js';

    /** Codes the router (bizcity-llm-router Zalo Personal bridge + LLM client transport) and sidecar emit today. */
    private const ROUTER_CODES = array(
        'managed_account_not_owned', 'managed_account_other_site', 'key_domain_mismatch', 'invalid_metadata',
        'feature_not_enabled', 'plan_missing', 'api_key_missing', 'no_api_key', 'key_inactive', 'account_limit_reached',
        'managed_bridge_not_configured', 'managed_bridge_unreachable', 'managed_bridge_upstream_error',
        'managed_bridge_invalid_response', 'managed_capacity_lock_timeout', 'managed_callback_secret_missing',
        'managed_callback_secret_failed', 'managed_registry_write_failed', 'unauthorized', 'service_auth_failed',
        'sidecar_account_missing', 'already_connected', 'qr_in_progress', 'qr_expired', 'qr_declined', 'qr_failed',
        'account_not_found', 'http_request_failed', 'operation_timedout', 'decode_failed',
    );

    private function root(): string { return dirname( __DIR__, 2 ); }

    public function test_every_router_code_folds_into_a_bucket(): void {
        foreach ( self::ROUTER_CODES as $code ) {
            $this->assertNotSame( '', BizCity_Zalo_Session_Errors::bucket_for( $code ), "Router code `{$code}` has no bucket — client would only see the generic sentence." );
        }
    }

    public function test_every_bucket_is_complete_and_uses_a_known_action(): void {
        foreach ( BizCity_Zalo_Session_Errors::ERRORS as $bucket => $entry ) {
            $this->assertNotEmpty( $entry['message'], "{$bucket}: message" );
            $this->assertNotEmpty( $entry['hint'], "{$bucket}: hint" );
            $this->assertContains( $entry['status'], array( 'blocked', 'degraded' ), "{$bucket}: status" );
            $this->assertArrayHasKey( $entry['action'], BizCity_Zalo_Session_Errors::ACTIONS, "{$bucket}: action" );
        }
    }

    public function test_aliases_are_unique_across_buckets(): void {
        $seen = array();
        foreach ( BizCity_Zalo_Session_Errors::ERRORS as $bucket => $entry ) {
            foreach ( $entry['aliases'] as $alias ) {
                $this->assertArrayNotHasKey( $alias, $seen, 'Alias `' . $alias . '` in both `' . ( $seen[ $alias ] ?? '' ) . '` and `' . $bucket . '`.' );
                $seen[ $alias ] = $bucket;
            }
        }
    }

    public function test_doc_lists_every_bucket_state_and_action(): void {
        $doc = (string) file_get_contents( $this->root() . self::DOC );
        $this->assertStringContainsString( BizCity_Zalo_Session_Errors::CONTRACT . '@' . BizCity_Zalo_Session_Errors::VERSION, $doc );
        foreach ( array_keys( BizCity_Zalo_Session_Errors::ERRORS ) as $bucket ) {
            $this->assertStringContainsString( "| `{$bucket}` |", $doc, "Doc is missing bucket {$bucket}" );
        }
        foreach ( array_keys( BizCity_Zalo_Session_Errors::STATES ) as $state ) {
            $this->assertStringContainsString( "| `{$state}` |", $doc, "Doc is missing state {$state}" );
        }
        foreach ( array_keys( BizCity_Zalo_Session_Errors::ACTIONS ) as $action ) {
            $this->assertStringContainsString( "| `{$action}` |", $doc, "Doc is missing action {$action}" );
        }
    }

    public function test_b2_client_mirror_uses_the_same_state_labels(): void {
        $js = (string) file_get_contents( $this->root() . self::JS );
        foreach ( BizCity_Zalo_Session_Errors::STATES as $state => $entry ) {
            $this->assertMatchesRegularExpression(
                '/\b' . preg_quote( $state, '/' ) . ":\s*\{\s*label:\s*'" . preg_quote( $entry['label'], '/' ) . "'/u",
                $js,
                "lib/zaloSession.js label for `{$state}` differs from the PHP catalog."
            );
        }
        foreach ( BizCity_Zalo_Session_Errors::ACTIONS as $action => $label ) {
            if ( $label === '' ) { continue; }
            $this->assertStringContainsString( "{$action}: '{$label}'", $js, "lib/zaloSession.js ACTION_LABELS.{$action} differs." );
        }
    }

    public function test_enrich_adds_action_and_keeps_specific_message(): void {
        $out = BizCity_Zalo_Session_Errors::enrich( array( 'ok' => false, 'code' => 'managed_bridge_unreachable' ) );
        $this->assertSame( 'relay_timeout', $out['reason_bucket'] );
        $this->assertSame( 'retry_later', $out['action'] );
        $this->assertSame( 'degraded', $out['operation_status'] );
        $this->assertNotEmpty( $out['message'] );

        $specific = BizCity_Zalo_Session_Errors::enrich( array( 'ok' => false, 'reason_bucket' => 'duplicate_zalo_login', 'message' => 'Trùng với «0931» (#12).' ) );
        $this->assertSame( 'Trùng với «0931» (#12).', $specific['message'] );
        $this->assertSame( 'delete_duplicate', $specific['action'] );

        $untouched = BizCity_Zalo_Session_Errors::enrich( array( 'ok' => true ) );
        $this->assertArrayNotHasKey( 'action', $untouched );
    }

    public function test_duplicate_and_revoked_never_offer_qr(): void {
        $this->assertFalse( BizCity_Zalo_Session_Errors::STATES['duplicate']['can_relogin'] );
        $this->assertFalse( BizCity_Zalo_Session_Errors::STATES['revoked']['can_relogin'] );
    }
}
