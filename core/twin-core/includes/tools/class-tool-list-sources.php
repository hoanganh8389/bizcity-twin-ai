<?php
/**
 * Bizcity Twin AI — Tool: list_sources
 *
 * Sprint 4.7b — Tool wrap `BizCity_KG::list_sources()`. Cho LLM biết user đã
 * upload những source nào trong scope hiện tại, để gợi ý / phân loại / xác nhận.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Twin_Core\Tools
 * @since 2026-04-26
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Tool_List_Sources implements BizCity_Twin_Tool {

	public function name(): string {
		return 'list_sources';
	}

	public function description(): string {
		return 'List all sources (documents, URLs, files) currently attached to the user\'s scope. Use when user asks "what sources do I have", "what files have I uploaded", or to verify scope contents before making assumptions.';
	}

	public function parameters_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'limit'  => [ 'type' => 'integer', 'default' => 50, 'maximum' => 200 ],
				'search' => [ 'type' => 'string',  'description' => 'Optional substring filter on source title.' ],
			],
			'required'   => [],
		];
	}

	public function execute( array $args, array $context ): array {
		$scope    = $context['scope'] ?? [];
		$plugin   = (string) ( $scope['plugin'] ?? 'twinchat' );
		$scope_id = (int) ( $scope['scope_id'] ?? $scope['id'] ?? 0 );

		if ( $scope_id <= 0 ) {
			return [ 'ok' => false, 'error' => 'Missing scope_id in context' ];
		}
		if ( ! class_exists( 'BizCity_KG' ) ) {
			return [ 'ok' => false, 'error' => 'KG facade not available' ];
		}

		$res = BizCity_KG::list_sources(
			[ 'plugin' => $plugin, 'scope_id' => $scope_id ],
			[
				'limit'  => max( 1, min( 200, (int) ( $args['limit'] ?? 50 ) ) ),
				'search' => (string) ( $args['search'] ?? '' ),
			]
		);

		if ( is_wp_error( $res ) ) {
			return [ 'ok' => false, 'error' => $res->get_error_message() ];
		}
		$sources = is_array( $res ) ? $res : [];

		// Strip large fields, keep summary.
		$lite = [];
		foreach ( $sources as $s ) {
			$lite[] = [
				'id'         => (int) ( $s['id'] ?? $s['source_id'] ?? 0 ),
				'title'      => (string) ( $s['title'] ?? $s['name'] ?? '' ),
				'type'       => (string) ( $s['type'] ?? $s['source_type'] ?? '' ),
				'created_at' => (string) ( $s['created_at'] ?? '' ),
			];
		}

		return [
			'ok'      => true,
			'result'  => [ 'sources' => $lite, 'count' => count( $lite ) ],
			'summary' => sprintf( 'list_sources: %d items in scope %s#%d', count( $lite ), $plugin, $scope_id ),
		];
	}
}
