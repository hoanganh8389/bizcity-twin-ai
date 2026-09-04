<?php
/**
 * Action: TwinBrain deep research with AskBrain parity evidence.
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Automation_Action_TwinBrain_Deep_Research' ) ) {
	return;
}

final class BizCity_Automation_Action_TwinBrain_Deep_Research extends BizCity_Automation_Block_Base {

	public function id(): string { return 'action.twinbrain_deep_research'; }
	public function kind(): string { return 'action'; }

	public function meta(): array {
		return array(
			'label'    => 'TwinBrain Deep Research',
			'short'    => 'twinbrain_deep_research',
			'category' => 'brain',
			'color'    => '#0369a1',
			'icon'     => 'search-check',
			'defaults' => array(
				'label'       => 'TwinBrain Deep Research',
				'question'    => '{{trigger.message_text_clean}}',
				'max_sources' => 8,
			),
			'fields'   => array(
				array( 'name' => 'label', 'label' => 'Tên hiển thị', 'type' => 'text' ),
				array( 'name' => 'question', 'label' => 'Câu hỏi', 'type' => 'textarea', 'hint' => '{{trigger.message_text_clean}}' ),
				array( 'name' => 'max_sources', 'label' => 'Số nguồn tối đa', 'type' => 'number', 'hint' => '1-8' ),
			),
		);
	}

	public function execute( array $ctx, array $data ) {
		// [2026-09-01 Johnny Chu] PHASE-0.45-W5 — call TwinBrain Runtime once and preserve its canonical evidence pack.
		$trigger = isset( $ctx['trigger'] ) && is_array( $ctx['trigger'] ) ? $ctx['trigger'] : array();
		$owner   = $this->resolve_owner_user_id( $ctx, 0 );
		$question = trim( (string) $this->resolve( $data['question'] ?? '{{trigger.message_text_clean}}', $ctx ) );
		if ( $question === '' ) {
			return $this->error_result( 'invalid_param', 'Chưa có câu hỏi nghiên cứu.', 'Nhập câu hỏi rồi thử lại.', 'invalid_param_generic' );
		}
		if ( $owner <= 0 ) {
			return $this->error_result( 'auth_required', 'Chưa liên kết tài khoản cho yêu cầu nghiên cứu.', 'Liên kết tài khoản Twin GPT trước khi chạy nghiên cứu.', 'auth_required' );
		}
		if ( ! class_exists( 'BizCity_TwinBrain_Runtime' ) ) {
			return $this->error_result( 'module_not_loaded', 'TwinBrain chưa sẵn sàng.', 'Kiểm tra loader TwinBrain rồi thử lại.', 'module_not_loaded' );
		}

		$max_sources = max( 1, min( 8, (int) ( $data['max_sources'] ?? 8 ) ) );
		$runtime_opts = array(
			'user_id'           => $owner,
			'surface'           => 'zalo_bot_group',
			'platform'          => (string) ( $trigger['platform'] ?? 'ZALO_BOT' ),
			'channel'           => 'ZALO_BOT',
			'account_id'        => (string) ( $trigger['account_id'] ?? $trigger['bot_id'] ?? '' ),
			'chat_id'           => (string) ( $trigger['conversation_chat_id'] ?? $trigger['chat_id'] ?? '' ),
			'external_user_id'  => (string) ( $trigger['sender_user_id'] ?? $trigger['user_id'] ?? '' ),
			'chat_kind'         => 'group',
			'web_mode'          => 'deep',
			'answer_depth'      => 'high',
			'session_id'        => (string) ( $trigger['canonical_session_key'] ?? ( 'zalobot_' . (string) ( $trigger['account_id'] ?? $trigger['bot_id'] ?? '' ) . '_' . (string) ( $trigger['sender_user_id'] ?? $trigger['user_id'] ?? '' ) ) ),
			'notebook_policy'   => 'askbrain_parity',
			'citation_mode'     => 'every_claim',
			'max_sources'       => $max_sources,
			'source'            => 'automation',
		);
		try {
			$runtime = BizCity_TwinBrain_Runtime::instance();
			$start = $runtime->start_turn( $question, $runtime_opts );
			$trace_id = (string) ( $start['trace_id'] ?? '' );
			if ( $trace_id === '' ) {
				return $this->error_result( 'twin_agent_exception', 'TwinBrain chưa bắt đầu được nghiên cứu.', 'Thử lại sau hoặc kiểm tra Diagnostics.', 'twin_agent_exception' );
			}
			$done = $runtime->complete_turn(
				$trace_id,
				$question,
				(array) ( $start['candidates'] ?? array() ),
				(array) ( $start['tool_candidates'] ?? array() ),
				$runtime_opts
			);
		} catch ( \Throwable $e ) {
			$this->note_event( 'twinbrain_deep_research_failed', array( 'reason' => 'runtime_exception' ) );
			return $this->error_result( 'twin_agent_exception', 'TwinBrain không hoàn tất nghiên cứu.', 'Thử lại sau hoặc kiểm tra trace workflow.', 'twin_agent_exception' );
		}

		$synthesis = isset( $done['synthesis'] ) && is_array( $done['synthesis'] ) ? $done['synthesis'] : array();
		$web_research = isset( $done['web_research'] ) && is_array( $done['web_research'] ) ? $done['web_research'] : array();
		$answer = (string) ( $synthesis['answer_md'] ?? $done['answer_md'] ?? '' );
		$notebook = isset( $done['notebook_source'] ) && is_array( $done['notebook_source'] ) ? $done['notebook_source'] : array();
		$citations = isset( $synthesis['citations'] ) && is_array( $synthesis['citations'] ) ? $synthesis['citations'] : array();
		// [2026-09-01 Johnny Chu] PHASE-0.45-W5 — preserve canonical Web Deep evidence when synthesis omits its citation list.
		if ( empty( $citations ) && ! empty( $web_research['citations'] ) ) {
			$citations = array_values( (array) $web_research['citations'] );
			if ( ! empty( $web_research['answer_md'] ) ) {
				$answer = (string) $web_research['answer_md'];
			}
		}
		if ( $answer === '' ) {
			return $this->error_result( 'retrieval_error', 'Chưa tạo được câu trả lời nghiên cứu.', 'Kiểm tra nguồn và thử lại.', 'retrieval_error' );
		}
		$this->note_event( 'twinbrain_deep_research_ok', array( 'citation_count' => count( $citations ), 'final_context_count' => count( (array) ( $notebook['final_context_chunks'] ?? array() ) ) ) );
		return array(
			'ok'                    => true,
			'success'               => true,
			'trace_id'              => $trace_id,
			'answer_md'             => $answer,
			'citations'             => $citations,
			'citation_count'        => count( $citations ),
			'graph_vector_rerank_pack' => (array) ( $notebook['graph_vector_rerank_pack'] ?? array() ),
			'graph_entities'        => (array) ( $notebook['graph_entities'] ?? array() ),
			'retrieval_candidates'  => (array) ( $notebook['retrieval_candidates'] ?? array() ),
			'final_context_chunks'  => (array) ( $notebook['final_context_chunks'] ?? array() ),
			'notebook_source_map'   => (array) ( $notebook['map'] ?? array() ),
			'citation_mode'         => 'every_claim',
			'notebook_policy'       => 'askbrain_parity',
			'user_id'               => $owner,
			'chat_kind'             => 'group',
		);
	}

	private function error_result( string $code, string $message, string $hint, string $help_code ): array {
		$payload = class_exists( 'BizCity_Error_Payload' ) ? BizCity_Error_Payload::make( $code, $message, $hint, $help_code ) : array( 'success' => false, '_degraded' => true, 'code' => $code, 'message' => $message, 'hint' => $hint, 'help_code' => $help_code );
		return array_merge( $payload, array( 'ok' => 0, 'answer_md' => $message, 'trace_id' => '' ) );
	}
}