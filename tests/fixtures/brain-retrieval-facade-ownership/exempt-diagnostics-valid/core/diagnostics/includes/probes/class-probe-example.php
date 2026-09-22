<?php
/**
 * Regression guard: a diagnostics probe building its own bounded pack for a
 * runtime check.
 *
 * Must PASS with ZERO findings — exempted, same convention as the real
 * class-probe-context-bank-retrieval-pack.php / class-probe-release-rollback-drill.php.
 */

defined( 'ABSPATH' ) || exit;

final class Fixture_Probe_Example {

	public function run() {
		return BizCity_Context_Bank_Retrieval_Pack::build( array( 'mode' => 'context_bank' ) );
	}
}
