<?php
/**
 * PHASE-0.60H D-H1 (option A, user-approved 2026-09-24) — BizCity_Context_Bank_Access::with_runtime_read().
 *
 * This is a change to a SECURITY BOUNDARY, so the tests pin what it must still refuse as much as what it newly
 * allows. The REAL access class runs in its own PHP process (support/context-bank-runtime-read-harness.php)
 * because the test must control the current user, their capabilities and the REST_REQUEST constant.
 *
 * // [2026-09-24 Claude Sonnet 5] PHASE-0.60H — test, not shipped code.
 */

use PHPUnit\Framework\TestCase;

final class ContextBankRuntimeReadTest extends TestCase {

	private static $cron = null;
	private static $rest = null;

	private static function run_harness( string $mode ): array {
		$cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/support/context-bank-runtime-read-harness.php' ) . ( 'rest' === $mode ? ' rest' : '' ) . ' 2>&1';
		$raw = (string) shell_exec( $cmd );
		$out = json_decode( $raw, true );
		if ( ! is_array( $out ) ) {
			self::fail( 'harness must print JSON, got: ' . substr( $raw, 0, 500 ) );
		}
		return $out;
	}

	private static function cron(): array {
		return self::$cron ?? ( self::$cron = self::run_harness( 'cron' ) );
	}

	public function test_without_a_grant_an_anonymous_read_is_still_denied_exactly_as_before(): void {
		$o = self::cron();
		$this->assertFalse( $o['no_grant_denied']['ok'] );
		$this->assertSame( 'context_bank_read_denied', $o['no_grant_denied']['reason'] );
		$this->assertFalse( $o['no_grant_pointer_denied']['ok'] );
	}

	public function test_inside_a_grant_the_granted_identity_is_readable_and_normalised(): void {
		$s = self::cron()['inside']['same_identity'];
		$this->assertTrue( $s['ok'] );
		$this->assertSame( 'runtime_identity', $s['scope'] );
		$this->assertSame( 'aaaaaaaa-1111-4222-8333-444444444444', $s['filters']['identity_uuid'], 'upper-case input normalised' );
		$this->assertArrayNotHasKey( 'wp_user_id', $s['filters'] );
	}

	public function test_the_grant_never_widens_to_another_identity_no_identity_or_wordpress_user_rows(): void {
		$in = self::cron()['inside'];
		foreach ( array( 'other_identity', 'no_identity', 'asks_wp_user_rows' ) as $case ) {
			$this->assertFalse( $in[ $case ]['ok'], $case );
			$this->assertSame( 'context_bank_runtime_scope_denied', $in[ $case ]['reason'], $case );
		}
	}

	public function test_each_pointer_must_be_the_granted_identity_contract_memory_kind_and_tenant(): void {
		$in = self::cron()['inside'];
		$this->assertTrue( $in['ptr_ok']['ok'] );
		foreach ( array( 'ptr_other_identity', 'ptr_other_contract', 'ptr_not_memory' ) as $case ) {
			$this->assertFalse( $in[ $case ]['ok'], $case );
		}
		$this->assertSame( 'context_bank_tenant_scope_denied', $in['ptr_other_blog']['reason'], 'the tenant check still runs first' );
	}

	public function test_nested_grants_use_the_innermost_and_restore_the_outer_one(): void {
		$in = self::cron()['inside'];
		$this->assertTrue( $in['nested']['inner_b']['ok'] );
		$this->assertFalse( $in['nested']['inner_a']['ok'], 'inside the inner grant only B is readable' );
		$this->assertTrue( $in['after_nested_a']['ok'], 'outer grant restored' );
	}

	public function test_the_grant_ends_with_the_callable_even_when_it_throws(): void {
		$o = self::cron();
		$this->assertTrue( $o['callable_ran'] );
		$this->assertFalse( $o['after_grant_denied']['ok'] );
		$this->assertTrue( $o['exception_propagated'] );
		$this->assertFalse( $o['after_exception_denied']['ok'], 'popped in finally — no leak past an exception' );
	}

	public function test_a_malformed_grant_is_refused_and_the_callable_never_runs(): void {
		$o = self::cron();
		foreach ( array( 'bad_uuid', 'no_contract', 'no_reason' ) as $case ) {
			$this->assertFalse( $o[ $case ]['called'], $case );
			$this->assertNull( $o[ $case ]['ret'], $case );
		}
	}

	public function test_a_logged_in_user_cannot_hold_the_grant_and_keeps_their_normal_scope(): void {
		$o = self::cron();
		$this->assertFalse( $o['logged_in_refused']['called'] );
		$this->assertSame( 'user', $o['logged_in_normal_scope']['scope'] );
		$this->assertSame( 5, $o['logged_in_normal_scope']['filters']['wp_user_id'], 'still forced to their own rows' );
	}

	public function test_inside_a_rest_request_the_grant_cannot_be_opened_at_all(): void {
		$rest = self::$rest ?? ( self::$rest = self::run_harness( 'rest' ) );
		$this->assertFalse( $rest['callable_ran'] );
		$this->assertNull( $rest['inside'] );
		$this->assertFalse( $rest['no_grant_denied']['ok'] );
	}
}
