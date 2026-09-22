<?php
/**
 * Broken fixture: a vertical plugin building the retrieval pack directly.
 *
 * Must report `R-TB.retrieval_outside_facade`.
 */

defined( 'ABSPATH' ) || exit;

final class Fixture_Bad_Retrieval {

	public function fetch_context( array $request ): array {
		// Bypass: should call BizCity_Brain_Retrieval_Facade::pack() instead.
		return BizCity_Context_Bank_Retrieval_Pack::build( $request );
	}
}
