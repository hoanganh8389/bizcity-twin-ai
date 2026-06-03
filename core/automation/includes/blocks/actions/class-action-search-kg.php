<?php
/**
 * Action: Search Knowledge Graph (delegate sang core/knowledge).
 *
 * Tìm class consumer thực tế qua filter `bizcity_automation_search_kg` để
 * giữ loose coupling. Nếu không có listener, trả mock với hits=0.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Automation\Blocks\Actions
 * @since      AUTOMATION BE-2 (2026-05-29)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Automation_Action_Search_KG extends BizCity_Automation_Block_Base {
	public function id(): string   { return 'action.search_kg'; }
	public function kind(): string { return 'action'; }
	public function meta(): array {
		return array(
			'label'    => 'Tra cứu Knowledge Graph',
			'short'    => 'search_kg',
			'category' => 'action',
			'color'    => '#475569',
			'icon'     => 'search',
			'defaults' => array(
				'label' => 'search_kg',
				'query' => '{{trigger.text}}',
				'top_k' => 5,
			),
			'fields'   => array(
				array( 'name' => 'label', 'label' => 'Tên hiển thị', 'type' => 'text' ),
				array( 'name' => 'query', 'label' => 'Câu truy vấn',  'type' => 'textarea', 'hint' => 'hỗ trợ {{trigger.text}}' ),
				array( 'name' => 'top_k', 'label' => 'Top K',          'type' => 'number' ),
			),
		);
	}
	public function execute( array $ctx, array $data ) {
		$query = (string) $this->resolve( $data['query'] ?? '', $ctx );
		$top_k = max( 1, min( 20, (int) ( $data['top_k'] ?? 5 ) ) );
		$out   = apply_filters( 'bizcity_automation_search_kg', null, $query, $top_k, $ctx );

		if ( is_array( $out ) ) {
			return $out;
		}
		if ( is_wp_error( $out ) ) {
			return $out;
		}
		// Fallback no-op when no consumer registered.
		return array( 'hits' => 0, 'snippet' => '', 'query' => $query, '_degraded' => 'no_kg_provider' );
	}
}
