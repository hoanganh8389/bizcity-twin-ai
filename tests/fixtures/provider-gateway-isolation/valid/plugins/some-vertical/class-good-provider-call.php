<?php
/**
 * Clean fixture: a vertical plugin routing through the canonical gateway.
 *
 * Must PASS with ZERO findings. Also the regression guard for the comment
 * distinction: mentioning "BizCity_Router_Proxy" in a docblock/log string
 * must NOT be flagged, only a bare code identifier.
 */

defined( 'ABSPATH' ) || exit;

final class Fixture_Good_Provider_Call {

	/**
	 * Never call BizCity_Router_Proxy directly — this mention is prose, not code.
	 */
	public function ask( string $prompt ): string {
		error_log( 'Routing through BizCity_Router_Proxy is forbidden by R-GW-8.' );
		return BizCity_LLM_Client::instance()->generate( $prompt );
	}
}
