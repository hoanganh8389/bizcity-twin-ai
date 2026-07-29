<?php
/**
 * Daily session memory blocks for lightweight channel/workflow continuity.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Automation\Blocks\Actions
 */

defined( 'ABSPATH' ) || exit;

abstract class BizCity_Automation_Action_Session_Memory_Base extends BizCity_Automation_Block_Base {
	protected function memory_key( array $ctx, array $data ): string {
		// [2026-07-22 Johnny Chu] PHASE-3-TWIN-GPT — scope daily workflow memory by owner/chat/workflow without adding schema.
		$trigger = is_array( $ctx['trigger'] ?? null ) ? $ctx['trigger'] : array();
		$scope_template = (string) ( $data['scope'] ?? 'workflow_chat_day' );
		$owner = (int) ( $trigger['wp_user_id'] ?? $ctx['_owner_user_id'] ?? 0 );
		$chat_id = (string) ( $trigger['chat_id'] ?? $trigger['conversation_chat_id'] ?? '' );
		$workflow_id = (int) ( $ctx['_workflow_id'] ?? 0 );
		$date = gmdate( 'Ymd', current_time( 'timestamp', true ) );
		$raw = implode( '|', array( $scope_template, $owner, $chat_id, $workflow_id, $date ) );
		return 'bz_auto_mem_day_' . md5( $raw );
	}

	protected function clamp_text( string $text, int $max_chars ): string {
		$max_chars = max( 200, min( 12000, $max_chars ) );
		if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
			return mb_strlen( $text ) > $max_chars ? mb_substr( $text, -1 * $max_chars ) : $text;
		}
		return strlen( $text ) > $max_chars ? substr( $text, -1 * $max_chars ) : $text;
	}
}

final class BizCity_Automation_Action_Load_Session_Memory_Day extends BizCity_Automation_Action_Session_Memory_Base {
	public function id(): string { return 'action.load_session_memory_day'; }
	public function kind(): string { return 'action'; }
	public function meta(): array {
		return array(
			'label' => 'Nạp memory phiên trong ngày',
			'short' => 'load_day_memory',
			'category' => 'memory',
			'color' => '#0f766e',
			'icon' => 'history',
			'defaults' => array( 'label' => 'Nạp memory ngày', 'scope' => 'workflow_chat_day', 'max_chars' => 3500 ),
			'fields' => array(
				array( 'name' => 'label', 'label' => 'Tên hiển thị', 'type' => 'text' ),
				array( 'name' => 'scope', 'label' => 'Scope', 'type' => 'text' ),
				array( 'name' => 'max_chars', 'label' => 'Giới hạn ký tự', 'type' => 'number' ),
			),
		);
	}
	public function execute( array $ctx, array $data ) {
		// [2026-07-22 Johnny Chu] PHASE-3-TWIN-GPT — read same-day rolling context before BTnet LLM compose.
		$key = $this->memory_key( $ctx, $data );
		$text = (string) get_transient( $key );
		$text = $this->clamp_text( $text, (int) ( $data['max_chars'] ?? 3500 ) );
		return array( 'key' => $key, 'context' => $text, 'has_context' => $text !== '' );
	}
}

final class BizCity_Automation_Action_Append_Session_Memory_Day extends BizCity_Automation_Action_Session_Memory_Base {
	public function id(): string { return 'action.append_session_memory_day'; }
	public function kind(): string { return 'action'; }
	public function meta(): array {
		return array(
			'label' => 'Ghi memory phiên trong ngày',
			'short' => 'append_day_memory',
			'category' => 'memory',
			'color' => '#115e59',
			'icon' => 'save',
			'defaults' => array( 'label' => 'Ghi memory ngày', 'scope' => 'workflow_chat_day', 'input' => 'User: {{trigger.text}}\nBot: {{llm.output}}', 'ttl_hours' => 30, 'max_chars' => 5000 ),
			'fields' => array(
				array( 'name' => 'label', 'label' => 'Tên hiển thị', 'type' => 'text' ),
				array( 'name' => 'scope', 'label' => 'Scope', 'type' => 'text' ),
				array( 'name' => 'input', 'label' => 'Nội dung ghi', 'type' => 'textarea' ),
				array( 'name' => 'ttl_hours', 'label' => 'TTL giờ', 'type' => 'number' ),
				array( 'name' => 'max_chars', 'label' => 'Giới hạn ký tự', 'type' => 'number' ),
			),
		);
	}
	public function execute( array $ctx, array $data ) {
		// [2026-07-22 Johnny Chu] PHASE-3-TWIN-GPT — append rolling same-day transcript after workflow reply is produced.
		$key = $this->memory_key( $ctx, $data );
		$old = (string) get_transient( $key );
		$line = trim( (string) $this->resolve( $data['input'] ?? '', $ctx ) );
		$next = trim( $old . "\n\n" . $line );
		$next = $this->clamp_text( $next, (int) ( $data['max_chars'] ?? 5000 ) );
		$ttl = max( HOUR_IN_SECONDS, (int) ( $data['ttl_hours'] ?? 30 ) * HOUR_IN_SECONDS );
		set_transient( $key, $next, $ttl );
		return array( 'key' => $key, 'saved' => true, 'chars' => function_exists( 'mb_strlen' ) ? mb_strlen( $next ) : strlen( $next ) );
	}
}
