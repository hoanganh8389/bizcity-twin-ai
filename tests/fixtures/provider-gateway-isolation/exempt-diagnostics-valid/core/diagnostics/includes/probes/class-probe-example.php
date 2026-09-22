<?php
/**
 * Regression guard: a Hub-only diagnostics probe that legitimately calls the
 * Router classes it is testing.
 *
 * Must PASS with ZERO findings — exempted like the real
 * class-probe-router-domain-policy-runtime.php, which this mirrors.
 */

defined( 'ABSPATH' ) || exit;

final class Fixture_Probe_Example {

	public function run() {
		return BizCity_Router_Auth::resolve_domain_policy_state( (object) array(), null );
	}
}
