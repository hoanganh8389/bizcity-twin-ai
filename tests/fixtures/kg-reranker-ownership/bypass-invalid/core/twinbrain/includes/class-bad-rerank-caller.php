<?php
/**
 * Broken fixture: code outside KG-Hub calling the reranker directly.
 *
 * Must report `R-TB.reranker_outside_owner`.
 */

defined( 'ABSPATH' ) || exit;

final class Fixture_Bad_Rerank_Caller {

	public function rerank_directly( array $candidates, string $query ): array {
		// Bypass: should call BizCity_KG_Retriever::instance()->search()/ask() instead.
		return BizCity_KG_Reranker::instance()->rerank( $candidates, $query );
	}
}
