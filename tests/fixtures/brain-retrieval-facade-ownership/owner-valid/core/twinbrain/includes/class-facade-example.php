<?php
/**
 * Clean fixture: the facade itself calling the canonical builder.
 *
 * Must PASS with ZERO findings — this IS the owner.
 */

defined( 'ABSPATH' ) || exit;

final class Fixture_Facade_Example {

	public function pack( array $context ): array {
		return BizCity_Context_Bank_Retrieval_Pack::build( $context );
	}
}
