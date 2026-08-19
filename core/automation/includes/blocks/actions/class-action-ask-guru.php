<?php
/**
 * Action: Ask a selected Guru through the canonical TwinBrain runtime.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Automation\Blocks\Actions
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Automation_Action_Ask_Guru' ) ) {
	return;
}

final class BizCity_Automation_Action_Ask_Guru extends BizCity_Automation_Block_Base {

	const CACHE_GROUP = 'bizcity_automation_ask_guru';
	const DEFAULT_TIMEOUT = 30;

	public function id(): string { return 'action.ask_guru'; }
	public function kind(): string { return 'action'; }

	public function meta(): array {
		return array(
			'label' => 'Hỏi Guru',
			'short' => 'ask_guru',
			'category' => 'brain',
			'color' => '#0f766e',
			'icon' => 'brain',
			'defaults' => array(
				'label' => 'Hỏi Guru',
				'character_id' => 0,
				'query' => '{{trigger.text}}',
				'web_mode' => 'off',
				'timeout_seconds' => self::DEFAULT_TIMEOUT,
			),
			'fields' => array(
				array( 'name' => 'label', 'label' => 'Tên hiển thị', 'type' => 'text' ),
				array( 'name' => 'character_id', 'label' => 'Guru ID', 'type' => 'number', 'hint' => 'Guru được server kiểm tra lại trước khi chạy.' ),
				array( 'name' => 'query', 'label' => 'Câu hỏi', 'type' => 'textarea', 'hint' => '{{trigger.text}}' ),
				array( 'name' => 'web_mode', 'label' => 'Vertical', 'type' => 'select', 'options' => array( 'off' => 'Notebook / Brain', 'woo_bizops' => 'Woo BizOps' ) ),
				array( 'name' => 'timeout_seconds', 'label' => 'Timeout (giây)', 'type' => 'number' ),
			),
		);
	}

	public function execute( array $ctx, array $data ) {
		// [2026-08-16 Johnny Chu] PHASE-TWB-GURU-ASK — synchronous MVP through canonical TwinBrain runtime.
		$query = trim( (string) $this->resolve( $data['query'] ?? '{{trigger.text}}', $ctx ) );
		$guru_id = (int) ( $data['character_id'] ?? 0 );
		if ( $guru_id <= 0 ) {
			$guru_id = (int) ( $ctx['trigger']['character_id'] ?? $ctx['character_id'] ?? $ctx['payload']['character_id'] ?? 0 );
		}
		$web_mode = sanitize_key( (string) ( $data['web_mode'] ?? 'off' ) );
		if ( ! in_array( $web_mode, array( 'off', 'woo_bizops' ), true ) ) {
			$web_mode = 'off';
		}
		if ( $query === '' || $guru_id <= 0 ) {
			return $this->error_result( 'invalid_param', 'Cần có Guru và câu hỏi để thực thi.', 'Chọn Guru hợp lệ và nhập câu hỏi rồi thử lại.', 'invalid_param_generic' );
		}
		if ( ! class_exists( 'BizCity_TwinBrain_Runtime' ) || ! class_exists( 'BizCity_Knowledge_Database' ) ) {
			return $this->error_result( 'module_not_loaded', 'TwinBrain hoặc Knowledge chưa được nạp.', 'Kiểm tra loader TwinBrain và Knowledge rồi thử lại.', 'module_not_loaded' );
		}
		if ( ! class_exists( 'BizCity_TwinBrain_Guru_Policy' ) ) {
			return $this->error_result( 'module_not_loaded', 'Policy Guru chưa được nạp.', 'Kiểm tra loader TwinBrain policy rồi thử lại.', 'module_not_loaded' );
		}
		$owner_user_id = $this->resolve_owner_user_id( $ctx );
		if ( $owner_user_id <= 0 ) {
			return $this->error_result( 'identity_unlinked', 'Chưa xác định được chủ tài khoản cho yêu cầu Guru.', 'Liên kết channel với tài khoản WordPress trước khi chạy workflow.', 'auth_required' );
		}
		$guru = BizCity_Knowledge_Database::instance()->get_character( $guru_id );
		if ( ! $guru ) {
			return $this->error_result( 'not_found', 'Không tìm thấy Guru trong site hiện tại.', 'Chọn lại Guru thuộc site hiện tại rồi thử lại.', 'twinweb_guru_scope' );
		}
		$timeout = max( 5, min( 60, (int) ( $data['timeout_seconds'] ?? self::DEFAULT_TIMEOUT ) ) );
		$workflow_id = (int) ( $ctx['_workflow_id'] ?? 0 );
		$run_id = (string) ( $ctx['_run_id'] ?? $ctx['run_id'] ?? '' );
		$block_id = (string) ( $ctx['_block_id'] ?? $this->id() );
		$dedupe_key = md5( $workflow_id . '|' . $run_id . '|' . $block_id . '|' . $guru_id . '|' . $web_mode . '|' . $query );
		$cached = wp_cache_get( $dedupe_key, self::CACHE_GROUP );
		if ( is_array( $cached ) && ! empty( $cached['ok'] ) ) {
			$cached['deduped'] = true;
			return $cached;
		}
		$platform = (string) ( $ctx['trigger']['platform'] ?? $ctx['platform'] ?? '' );
		$account_id = (string) ( $ctx['trigger']['account_id'] ?? $ctx['account_id'] ?? '' );
		$target_resource = isset( $ctx['target_resource'] ) && is_array( $ctx['target_resource'] )
			? $ctx['target_resource']
			: array( 'scope' => 'twinbrain', 'owner_user_id' => $owner_user_id, 'blog_id' => function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 0 );
		$capability = 'woo_bizops' === $web_mode ? BizCity_TwinBrain_Guru_Policy::CAP_WOO_BIZOPS : BizCity_TwinBrain_Guru_Policy::CAP_GURU_CHAT;
		if ( ! class_exists( 'BizCity_TwinBrain_Guru_Policy' ) ) {
			return $this->error_result( 'module_not_loaded', 'Policy Guru chưa được nạp.', 'Kiểm tra loader TwinBrain policy rồi thử lại.', 'module_not_loaded' );
		}
		$decision = BizCity_TwinBrain_Guru_Policy::decide( array(
			'user_id' => $owner_user_id,
			'guru_id' => $guru_id,
			'surface' => (string) ( $ctx['surface'] ?? 'automation' ),
			'platform' => $platform,
			'account_id' => $account_id,
			'target_resource' => $target_resource,
			'capability' => $capability,
		) );
		if ( empty( $decision['allowed'] ) ) {
			$this->note_event( 'ask_guru_denied', array( 'guru_id' => $guru_id, 'web_mode' => $web_mode, 'reason' => (string) ( $decision['reason'] ?? '' ) ) );
			$denied = BizCity_TwinBrain_Guru_Policy::deny_payload( $decision );
			return array_merge( $denied, array( 'ok' => 0, 'answer_md' => (string) ( $denied['message'] ?? '' ), 'trace_id' => '' ) );
		}
		$opts = array(
			'user_id' => $owner_user_id,
			'guru_id' => $guru_id,
			'web_mode' => $web_mode,
			'surface' => (string) ( $ctx['surface'] ?? 'automation' ),
			'platform' => $platform,
			'account_id' => $account_id,
			'target_resource' => $target_resource,
			'session_id' => (string) ( $ctx['session_id'] ?? ( 'ask_guru_' . $workflow_id . '_' . $run_id ) ),
			'answer_depth' => 'balanced',
			'source' => 'automation',
		);
		$started_at = microtime( true );
		try {
			$runtime = BizCity_TwinBrain_Runtime::instance();
			$start = $runtime->start_turn( $query, $opts );
			$trace_id = (string) ( $start['trace_id'] ?? '' );
			$done = $runtime->complete_turn( $trace_id, $query, (array) ( $start['candidates'] ?? array() ), (array) ( $start['tool_candidates'] ?? array() ), array_merge( $opts, array( 'pre_mpr_triage' => (array) ( $start['pre_mpr_triage'] ?? array() ), 'goal_loop_state' => (array) ( $start['goal_loop_state'] ?? array() ), 'goal_contract' => (array) ( $start['goal_contract'] ?? array() ) ) ) );
		} catch ( \Throwable $e ) {
			$this->note_event( 'ask_guru_failed', array( 'guru_id' => $guru_id, 'reason' => 'runtime_error', 'error' => $e->getMessage() ) );
			return $this->error_result( 'twin_agent_exception', 'TwinBrain không hoàn tất câu hỏi Guru.', 'Thử lại sau hoặc kiểm tra trace workflow.', 'twin_agent_exception' );
		}
		$duration_ms = (int) round( ( microtime( true ) - $started_at ) * 1000 );
		if ( $duration_ms > ( $timeout * 1000 ) ) {
			$this->note_event( 'ask_guru_failed', array( 'guru_id' => $guru_id, 'reason' => 'timeout', 'duration_ms' => $duration_ms ) );
			return $this->error_result( 'timeout', 'Câu hỏi Guru vượt quá thời gian cho phép.', 'Giảm độ sâu câu hỏi hoặc chạy lại workflow.', 'timeout' );
		}
		$answer_md = (string) ( $done['synthesis']['answer_md'] ?? '' );
		$result = array( 'ok' => true, 'success' => true, 'guru_id' => $guru_id, 'trace_id' => (string) ( $start['trace_id'] ?? '' ), 'answer_md' => $answer_md, 'citations' => (array) ( $done['synthesis']['citations'] ?? array() ), 'duration_ms' => $duration_ms, 'web_mode' => $web_mode, 'deduped' => false );
		wp_cache_set( $dedupe_key, $result, self::CACHE_GROUP, HOUR_IN_SECONDS );
		$this->note_event( 'ask_guru_answered', array( 'guru_id' => $guru_id, 'trace_id' => $result['trace_id'], 'web_mode' => $web_mode, 'duration_ms' => $duration_ms ) );
		return $result;
	}

	private function error_result( string $code, string $message, string $hint, string $help_code ): array {
		$payload = class_exists( 'BizCity_Error_Payload' ) ? BizCity_Error_Payload::make( $code, $message, $hint, $help_code ) : array( 'success' => false, '_degraded' => true, 'code' => $code, 'message' => $message, 'hint' => $hint, 'help_code' => $help_code );
		return array_merge( $payload, array( 'ok' => 0, 'answer_md' => $message, 'trace_id' => '' ) );
	}
}
