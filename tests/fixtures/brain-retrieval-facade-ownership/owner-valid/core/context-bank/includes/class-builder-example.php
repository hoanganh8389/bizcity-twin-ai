<?php
/**
 * Clean fixture: a helper inside the builder's own directory.
 *
 * Must PASS with ZERO findings — this IS the owner. Also the regression
 * guard for the comment distinction: mentioning the class in a docblock must
 * NOT be flagged, only a real code call.
 */

defined( 'ABSPATH' ) || exit;

final class Fixture_Builder_Example {

	/**
	 * Never call BizCity_Context_Bank_Retrieval_Pack::build() from a mention here.
	 */
	public function rebuild_for_test( array $context ): array {
		return BizCity_Context_Bank_Retrieval_Pack::build( $context );
	}
}
