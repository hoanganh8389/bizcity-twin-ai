<?php
/**
 * Action: Run Products (lookup / stock / learn).
 *
 * Thin automation wrapper around shared service:
 * BizCity_TwinBrain_Product_Resolver_Service::resolve_by_query().
 *
 * Output vars:
 *   {{nX.ok}}, {{nX.intent}}, {{nX.query}}
 *   {{nX.detected_count}}, {{nX.detected_products_json}}
 *   {{nX.missing_constraints_json}}, {{nX.sheet_recommended}}, {{nX.sheet_seed_json}}
 *   {{nX.sheet_handoff_json}}, {{nX.sheet_handoff_status}}, {{nX.sheet_id}}, {{nX.sheet_token}}
 *   {{nX.need_count}}, {{nX.matched_count}}, {{nX.gap_count}}
 *   {{nX.catalog_md}}, {{nX.gaps_md}}, {{nX.final_answer_md}}
 *   {{nX.source_of_truth_links_json}}, {{nX.source_block_md}}, {{nX.internal_link_count}}, {{nX.public_link_count}}
 *   {{nX.citations_json}}, {{nX.degraded}}
 *   {{nX.error_code}}, {{nX.error_message}}
 *
 * [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS - action.run_products.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Automation\Blocks\Actions
 * @since      PHASE-TWB-PRODUCTS (2026-07-15)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Automation_Action_Run_Products extends BizCity_Automation_Block_Base {

	const ALLOWED_INTENTS = array( 'product_lookup', 'stock_price', 'product_learn', 'need_solution' );

	public function id(): string   { return 'action.run_products'; }
	public function kind(): string { return 'action'; }

	public function meta(): array {
		// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS — unify visible block branding as Super-MRO.
		return array(
			'label'    => 'Tra cuu Super-MRO',
			'short'    => 'run_products',
			'category' => 'commerce',
			'color'    => '#0f766e',
			'icon'     => 'shopping-cart',
			'defaults' => array(
				'label'            => 'run_products',
				'query'            => '{{trigger.text}}',
				'intent_hint'      => '',
				'want_enrichment'  => true,
				'max_results'      => 10,
				'source_marker'    => 'zalobot_chat',
			),
			'fields' => array(
				array( 'name' => 'label', 'label' => 'Ten hien thi', 'type' => 'text' ),
				array( 'name' => 'query', 'label' => 'Cau hoi Super-MRO', 'type' => 'textarea', 'hint' => '{{trigger.text}}' ),
				array( 'name' => 'intent_hint', 'label' => 'Intent hint', 'type' => 'select', 'options' => array( '', 'product_lookup', 'stock_price', 'product_learn', 'need_solution' ), 'hint' => 'De trong de auto-classify' ),
				array( 'name' => 'want_enrichment', 'label' => 'Bat web enrich fallback', 'type' => 'toggle' ),
				array( 'name' => 'max_results', 'label' => 'So ket qua toi da', 'type' => 'number', 'hint' => '1-20, mac dinh 10' ),
				array( 'name' => 'source_marker', 'label' => 'Source marker', 'type' => 'text', 'hint' => 'Mac dinh zalobot_chat' ),
			),
		);
	}

	public function execute( array $ctx, array $data ) {
		// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS - run products block via shared resolver.
		$query = trim( (string) $this->resolve( $data['query'] ?? '{{trigger.text}}', $ctx ) );
		if ( $query === '' ) {
			return $this->fail_result( 'invalid_param', 'Noi dung cau hoi san pham dang rong.', '' );
		}

		if ( ! class_exists( 'BizCity_TwinBrain_Product_Resolver_Service' ) ) {
			return $this->fail_result( 'product_service_missing', 'Dich vu san pham chua san sang.', $query );
		}

		$intent_hint = sanitize_key( (string) ( $data['intent_hint'] ?? '' ) );
		if ( ! in_array( $intent_hint, self::ALLOWED_INTENTS, true ) ) {
			$intent_hint = '';
		}
		$want_enrichment = ! isset( $data['want_enrichment'] ) || ! empty( $data['want_enrichment'] );
		$max_results     = max( 1, min( 20, (int) ( $data['max_results'] ?? 10 ) ) );
		$source_marker   = sanitize_key( (string) ( $data['source_marker'] ?? 'zalobot_chat' ) );
		if ( $source_marker === '' ) {
			$source_marker = 'zalobot_chat';
		}
		// [2026-07-16 Johnny Chu] PHASE-TWINWEB F4 — enforce canonical automation owner for product resolver handoff.
		$owner_user_id = $this->resolve_owner_user_id( $ctx );
		if ( $owner_user_id <= 0 ) {
			return $this->fail_result( 'owner_missing', 'Khong resolve duoc owner user de chay super-mro.', $query );
		}

		$svc = BizCity_TwinBrain_Product_Resolver_Service::instance();
		$res = $svc->resolve_by_query( $query, array(
			'intent_hint'      => $intent_hint,
			'want_enrichment'  => $want_enrichment,
			'max_results'      => $max_results,
			'user_id'          => $owner_user_id,
			'surface'          => 'automation_zalobot',
			'source_marker'    => $source_marker,
			'sse'              => null,
		) );

		if ( empty( $res['success'] ) ) {
			$code = (string) ( $res['_degraded'] ?? 'products_failed' );
			$msg  = (string) ( $res['message'] ?? 'Khong tim duoc thong tin san pham phu hop.' );
			$this->note_event( 'run_products_failed', array(
				'reason'  => $code,
				'message' => $msg,
				'query'   => mb_substr( $query, 0, 160 ),
			) );
			return $this->fail_result( $code, $msg, $query );
		}

		// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS V2-1 - expose detected product list for workflow-level intent evidence.
		$citations = isset( $res['citations'] ) && is_array( $res['citations'] ) ? $res['citations'] : array();
		$detected  = isset( $res['detected_products'] ) && is_array( $res['detected_products'] ) ? $res['detected_products'] : array();
		// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS — expose constraints + BOQ/sheet recommendation for workflow routing.
		$missing   = isset( $res['missing_constraints'] ) && is_array( $res['missing_constraints'] ) ? $res['missing_constraints'] : array();
		$sheet_recommended = ! empty( $res['sheet_recommended'] );
		$sheet_seed = isset( $res['sheet_seed'] ) && is_array( $res['sheet_seed'] ) ? $res['sheet_seed'] : array();
		$sheet_handoff = isset( $res['sheet_handoff'] ) && is_array( $res['sheet_handoff'] ) ? $res['sheet_handoff'] : array();
		// [2026-07-16 Johnny Chu] PHASE-TWB-PRODUCTS-SOURCE-LAYER - forward source-of-truth link contract to automation outputs.
		$source_links = isset( $res['source_of_truth_links'] ) && is_array( $res['source_of_truth_links'] ) ? $res['source_of_truth_links'] : array();
		$source_links_json = isset( $res['source_of_truth_links_json'] )
			? (string) $res['source_of_truth_links_json']
			: (string) wp_json_encode( $source_links, JSON_UNESCAPED_UNICODE );
		$source_block_md = isset( $res['source_block_md'] ) ? (string) $res['source_block_md'] : '';
		$internal_link_count = (int) ( $res['internal_link_count'] ?? 0 );
		$public_link_count = (int) ( $res['public_link_count'] ?? 0 );
		$out = array(
			'ok'              => 1,
			'intent'          => (string) ( $res['intent'] ?? '' ),
			'query'           => $query,
			'detected_count'  => (int) ( $res['detected_count'] ?? count( $detected ) ),
			'detected_products_json' => wp_json_encode( $detected, JSON_UNESCAPED_UNICODE ),
			'missing_constraints_json' => wp_json_encode( $missing, JSON_UNESCAPED_UNICODE ),
			'sheet_recommended' => $sheet_recommended ? 1 : 0,
			'sheet_seed_json'  => wp_json_encode( $sheet_seed, JSON_UNESCAPED_UNICODE ),
			'sheet_handoff_json' => wp_json_encode( $sheet_handoff, JSON_UNESCAPED_UNICODE ),
			'sheet_handoff_status' => (string) ( $sheet_handoff['status'] ?? '' ),
			'sheet_id'         => (int) ( $sheet_handoff['sheet_id'] ?? 0 ),
			'sheet_token'      => (string) ( $sheet_handoff['token'] ?? '' ),
			'need_count'      => (int) ( $res['need_count'] ?? 1 ),
			'matched_count'   => (int) ( $res['matched_count'] ?? 0 ),
			'gap_count'       => (int) ( $res['gap_count'] ?? 0 ),
			'catalog_md'      => (string) ( $res['catalog_md'] ?? '' ),
			'gaps_md'         => (string) ( $res['gaps_md'] ?? '' ),
			'final_answer_md' => (string) ( $res['final_answer_md'] ?? '' ),
			'source_of_truth_links_json' => $source_links_json !== '' ? $source_links_json : '[]',
			'source_block_md' => $source_block_md,
			'internal_link_count' => $internal_link_count,
			'public_link_count' => $public_link_count,
			'citations_json'  => wp_json_encode( $citations, JSON_UNESCAPED_UNICODE ),
			'citations'       => wp_json_encode( $citations, JSON_UNESCAPED_UNICODE ),
			'degraded'        => (string) ( $res['_degraded'] ?? '' ),
			'error_code'      => '',
			'error_message'   => '',
		);

		$this->note_event( 'run_products_done', array(
			'intent'        => (string) $out['intent'],
			'detected_count'=> (int) $out['detected_count'],
			'missing_constraints' => count( $missing ),
			'sheet_recommended'  => (int) $out['sheet_recommended'],
			'sheet_handoff_status' => (string) $out['sheet_handoff_status'],
			'matched_count' => (int) $out['matched_count'],
			'gap_count'     => (int) $out['gap_count'],
			'degraded'      => (string) $out['degraded'],
		) );

		return $out;
	}

	/**
	 * @param string $code
	 * @param string $message
	 * @param string $query
	 * @return array<string,mixed>
	 */
	private function fail_result( string $code, string $message, string $query ): array {
		// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS V2-1 - keep fail payload shape parity with detected_* keys.
		// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS — keep parity for missing_constraints/sheet outputs in fail branch.
		return array(
			'ok'              => 0,
			'intent'          => '',
			'query'           => $query,
			'detected_count'  => 0,
			'detected_products_json' => '[]',
			'missing_constraints_json' => '[]',
			'sheet_recommended' => 0,
			'sheet_seed_json'  => '{}',
			'sheet_handoff_json' => '{}',
			'sheet_handoff_status' => '',
			'sheet_id'         => 0,
			'sheet_token'      => '',
			'need_count'      => 0,
			'matched_count'   => 0,
			'gap_count'       => 0,
			'catalog_md'      => '',
			'gaps_md'         => '',
			'final_answer_md' => '',
			'source_of_truth_links_json' => '[]',
			'source_block_md' => '',
			'internal_link_count' => 0,
			'public_link_count' => 0,
			'citations_json'  => '[]',
			'citations'       => '[]',
			'degraded'        => '',
			'error_code'      => $code,
			'error_message'   => $message,
		);
	}
}
