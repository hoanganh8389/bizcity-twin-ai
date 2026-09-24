<?php
/**
 * PHASE-0.60H — POST /inspector/bindings must forward `office_hours` to BizCity_Channel_Binding::upsert().
 *
 * Regression: the route built its own 7-key $args and silently dropped `office_hours`, so the per-number
 * giờ trực / pause_on_manual_reply / require_mention_in_group / disabled_tools sent by the Zalo Cá nhân
 * panel (and the CRM per-phone bot sheet) were never persisted. Runs the REAL handler in its own PHP
 * process (support/inspector-binding-upsert-harness.php) and asserts on the arguments the binding layer got.
 *
 * // [2026-09-24 Claude Sonnet 5] PHASE-0.60H — test, not shipped code.
 */

use PHPUnit\Framework\TestCase;

final class WebhookInspectorBindingUpsertTest extends TestCase {

	private static function run_harness(): array {
		$cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/support/inspector-binding-upsert-harness.php' ) . ' 2>&1';
		$raw = (string) shell_exec( $cmd );
		$out = json_decode( $raw, true );
		if ( ! is_array( $out ) ) {
			self::fail( 'harness must print JSON, got: ' . substr( $raw, 0, 400 ) );
		}
		return $out;
	}

	public function test_office_hours_sent_by_the_caller_reaches_the_binding_layer(): void {
		$out = self::run_harness();
		$this->assertSame( 200, $out['with_hours']['status'] );
		$hours = $out['with_hours']['args']['office_hours'] ?? null;
		$this->assertIsArray( $hours, 'office_hours must be forwarded, not dropped' );
		$this->assertSame( array( 'generate_image' ), $hours['disabled_tools'], 'per-number tool policy travels with it' );
		$this->assertTrue( $hours['pause_on_manual_reply'] );
		$this->assertTrue( $hours['require_mention_in_group'] );
		$this->assertSame( 'ZALO_PERSONAL', $out['with_hours']['args']['platform'] );
		$this->assertSame( 'bridge-9', $out['with_hours']['args']['account_id'] );
		$this->assertSame( 5, $out['with_hours']['args']['character_id'] );
	}

	/** Binding::upsert() is isset-gated on this key: a plain Guru swap must NOT send it, or it would wipe the stored hours. */
	public function test_a_plain_guru_swap_does_not_send_office_hours_so_stored_hours_survive(): void {
		$out = self::run_harness();
		$this->assertSame( 200, $out['without_hours']['status'] );
		$this->assertArrayNotHasKey( 'office_hours', $out['without_hours']['args'], 'absent key = leave the stored value untouched' );
		$this->assertArrayNotHasKey( 'office_hours', $out['junk_hours']['args'], 'a non-array value is ignored, never coerced to an empty schedule' );
	}

	public function test_missing_account_id_is_still_rejected_before_any_write(): void {
		$out = self::run_harness();
		$this->assertSame( 400, $out['missing_account']['status'] );
		$this->assertSame( 3, $out['missing_account']['calls_after'], 'the rejected call never reached upsert()' );
	}
}
