<?php
/**
 * Broken fixture: a vertical plugin calling the server-only Router directly.
 *
 * Must report `R-GW8.router_reference_outside_gateway`.
 */

defined( 'ABSPATH' ) || exit;

final class Fixture_Bad_Provider_Call {

	public function ask( string $prompt ): string {
		// R-GW-8 violation: BizCity_Router_Proxy belongs to the separate
		// server-side bizcity-llm-router companion, never to client code.
		return BizCity_Router_Proxy::generate( $prompt );
	}
}
