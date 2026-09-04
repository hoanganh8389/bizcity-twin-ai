<?php
/**
 * Real-call probe for the Zalo Bot group deep-research automation action.
 *
 * The probe invokes TwinBrain only. It never sends a Zalo message and does not
 * create workflow/run fixtures; outbound delivery is covered by the provider
 * smoke matrix separately.
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Probe_Zalobot_Deep_Research_Runtime' ) ) {
	return;
}

final class BizCity_Probe_Zalobot_Deep_Research_Runtime implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'web.zalobot.deep_research'; }
	public function label(): string { return 'Zalo Bot Deep Research action: citations + AskBrain parity'; }
	public function description(): string { return 'Real-call TwinBrain automation action check; no Zalo outbound send, no workflow fixture, gateway budget required.'; }
	public function severity(): string { return 'warning'; }
	public function order(): int { return 36; }
	public function icon(): string { return 'search-check'; }
	public function estimate_ms(): int { return 60000; }

	public function precondition() {
		if ( defined( 'BIZCITY_DIAGNOSTICS_MOCK' ) && BIZCITY_DIAGNOSTICS_MOCK ) {
			return 'Mock mode: bỏ qua real-call Zalo Bot Deep Research action.';
		}
		if ( ! class_exists( 'BizCity_Automation_Action_TwinBrain_Deep_Research' ) ) {
			return 'Deep Research automation action chưa load.';
		}
		if ( ! class_exists( 'BizCity_TwinBrain_Runtime' ) ) {
			return 'TwinBrain Runtime chưa load.';
		}
		return true;
	}

	public function run( $ctx ): array {
		$action = new BizCity_Automation_Action_TwinBrain_Deep_Research();
		$trigger = array(
			'platform' => 'ZALO_BOT',
			'account_id' => '1',
			'bot_id' => 1,
			'wp_user_id' => 1513,
			'sender_user_id' => 'probe-zalo-user',
			'user_id' => 'probe-zalo-user',
			'chat_kind' => 'group',
			'provider_chat_id' => 'zgr-ddv-deep',
			'conversation_chat_id' => 'zalobot_1_group_zgr-ddv-deep',
			'canonical_session_key' => 'zalobot_1_probe-zalo-user',
			'message_text_clean' => 'nghiên cứu ngắn về nguyên tắc an toàn dữ liệu doanh nghiệp',
			'text' => 'nghiên cứu ngắn về nguyên tắc an toàn dữ liệu doanh nghiệp',
		);
		$ctx->emit_step( array( 'label' => 'Action input', 'status' => 'pass', 'detail' => 'group target preserved; outbound send disabled by probe design' ) );
		try {
			$result = $action->execute( array( 'trigger' => $trigger, '_owner_user_id' => 1513, '_run_id' => 'probe-zalobot-deep', '_workflow_id' => 0 ), array( 'question' => '{{trigger.message_text_clean}}', 'max_sources' => 8 ) );
		} catch ( \Throwable $e ) {
			return array( 'status' => 'fail', 'summary' => 'Deep research action exception.', 'error' => get_class( $e ) . ': ' . $e->getMessage(), 'fix_hint' => 'Kiểm tra gateway LLM/Search và TwinBrain Runtime trace.' );
		}
		$answer = trim( (string) ( $result['answer_md'] ?? '' ) );
		$citations = (array) ( $result['citations'] ?? array() );
		$final_chunks = (array) ( $result['final_context_chunks'] ?? array() );
		$pack = (array) ( $result['graph_vector_rerank_pack'] ?? array() );
		$ctx->emit_step( array( 'label' => 'Action answer + citations', 'status' => $answer !== '' && count( $citations ) > 0 ? 'pass' : 'fail', 'detail' => strlen( $answer ) . ' chars; citations=' . count( $citations ) ) );
		$parity_ok = array_key_exists( 'graph_vector_rerank_pack', $result )
			&& array_key_exists( 'graph_entities', $result )
			&& array_key_exists( 'retrieval_candidates', $result )
			&& array_key_exists( 'final_context_chunks', $result )
			&& ( ! empty( $final_chunks ) || ! empty( $pack ) );
		$ctx->emit_step( array( 'label' => 'AskBrain parity pack', 'status' => $parity_ok ? 'pass' : 'fail', 'detail' => 'graph=' . count( $pack ) . '; entities=' . count( (array) ( $result['graph_entities'] ?? array() ) ) . '; candidates=' . count( (array) ( $result['retrieval_candidates'] ?? array() ) ) . '; final=' . count( $final_chunks ) ) );
		if ( $answer === '' || empty( $citations ) || ! $parity_ok ) {
			return array( 'status' => 'fail', 'summary' => 'Deep Research action returned incomplete answer/evidence contract.', 'error' => 'answer_or_citations_or_parity_missing', 'fix_hint' => 'Inspect action.twinbrain_deep_research output and Notebook Source Layer.' );
		}
		return array( 'status' => 'pass', 'summary' => 'TwinBrain deep-research action returned answer, citations, and AskBrain parity pack.' );
	}

	public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Zalobot_Deep_Research_Runtime';
	return $list;
} );