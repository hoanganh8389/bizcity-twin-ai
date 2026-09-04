<?php
/**
 * Minimal public-SDK-only extension fixture for Phase 0.41 acceptance tests.
 *
 * This fixture deliberately has no provider, database, filesystem or internal
 * runtime dependency. A real extension may replace the manifest source while
 * keeping the same single SDK registration boundary.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Twin_Core\Contracts\Fixtures
 * @since 2026-09-02 (PHASE-0.41-CRM-ONE-BRAIN)
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BizCity_Framework_Sample_Extension', false ) ) {
	final class BizCity_Framework_Sample_Extension {

		public static function register( array $manifest ) {
			// [2026-09-02 11:29 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.41-W6 — keep the sample extension on the single public SDK registration boundary.
			if ( ! class_exists( 'BizCity_Framework_SDK', false ) ) {
				return false;
			}
			return BizCity_Framework_SDK::register( $manifest, new self() );
		}
	}
}
