<?php
/**
 * Clean fixture: the retriever's own directory calling the reranker.
 *
 * Must PASS with ZERO findings — this IS the owner. Also the regression
 * guard for the comment distinction: mentioning the class in a docblock must
 * NOT be flagged, only a real code call.
 */

defined( 'ABSPATH' ) || exit;

final class Fixture_Retriever_Example {

	/**
	 * Never call BizCity_KG_Reranker from a mention here.
	 */
	public function search( array $candidates, string $query ): array {
		return BizCity_KG_Reranker::instance()->rerank( $candidates, $query );
	}
}
