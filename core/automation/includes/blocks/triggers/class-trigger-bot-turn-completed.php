<?php
/**
 * Trigger: Bot Studio turn completed (the Guru bot has just replied to a customer).
 *
 * Subscribes the canonical `bizcity_bot_turn_completed` action fired by
 * BizCity_Bot_Turn_Runner after a bot reply was actually sent. Owner of the event is Channel
 * Gateway (Bot Studio); this block only exposes it to workflows (PHASE-0.60G G2).
 *
 * Trigger ctx shape (merged into the run payload):
 * - `conversation_id`, `contact_id`, `character_id`, `message_id` : CRM ids (ints)
 * - `account_id` / `instance_id` : Zalo personal account the reply went out from
 * - `chat_id`, `trace_id`        : provider chat id + bot turn trace id
 * - `inbound`                    : canonical provenance (platform/chat_id/account_id/message_id)
 *
 * The reply text is intentionally NOT in the payload: read it from the CRM message by `message_id`.
 * The payload carries `_no_automation_reentry` so a workflow that answers back over the same
 * channel can never re-enter the inbound matcher.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Automation\Blocks\Triggers
 * @since      PHASE-0.60G G2
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Automation_Trigger_Bot_Turn_Completed extends BizCity_Automation_Block_Base {

	const TRIGGER_TYPE = 'bot_turn_completed';

	public function id(): string   { return 'trigger.bot_turn_completed'; }
	public function kind(): string { return 'trigger'; }
	public function meta(): array {
		return array(
			'label'    => 'Bot Studio · bot vừa trả lời xong',
			'short'    => 'Bot done',
			'category' => 'trigger',
			'color'    => '#0d9488',
			'icon'     => 'bot',
			'defaults' => array(
				'label'       => 'Bot Studio · bot vừa trả lời xong',
				'instance_id' => '',
				'guru_id'     => 0,
			),
			'fields'   => array(
				array( 'name' => 'label',       'label' => 'Tên hiển thị',       'type' => 'text' ),
				// Plain text on purpose: the channel registry behind `channel_instance_picker` does not list
				// Zalo personal accounts, so a picker would always be empty. Id = Bot Studio → Accounts.
				array( 'name' => 'instance_id', 'label' => 'Account ID (Zalo cá nhân)', 'type' => 'text', 'hint' => 'để trống = mọi account; lấy ID ở Bot Studio → Accounts' ),
				array( 'name' => 'guru_id', 'label' => 'Guru (chỉ chạy khi bot của Guru này trả lời)', 'type' => 'guru_picker', 'hint' => 'để trống = mọi guru' ),
			),
		);
	}

	public function execute( array $ctx, array $data ) {
		return isset( $ctx['trigger'] ) && is_array( $ctx['trigger'] ) ? $ctx['trigger'] : array();
	}

	/**
	 * Pure: turn the raw `bizcity_bot_turn_completed` event into a run payload.
	 *
	 * @param mixed $event Raw hook argument.
	 * @return array Empty when the event carries no conversation to attach the run to.
	 */
	public static function build_payload( $event ): array {
		if ( ! is_array( $event ) ) {
			return array();
		}
		$conversation_id = (int) ( $event['conversation_id'] ?? 0 );
		if ( $conversation_id <= 0 ) {
			return array();
		}
		$account_id = (string) ( $event['account_id'] ?? '' );
		$chat_id    = (string) ( $event['chat_id'] ?? '' );
		$message_id = (int) ( $event['message_id'] ?? 0 );

		return array(
			'channel'              => 'ZALO_PERSONAL',
			'platform'             => 'ZALO_PERSONAL',
			'account_id'           => $account_id,
			'instance_id'          => $account_id,
			'conversation_id'      => $conversation_id,
			'contact_id'           => (int) ( $event['contact_id'] ?? 0 ),
			'character_id'         => (int) ( $event['character_id'] ?? 0 ),
			'message_id'           => $message_id,
			'chat_id'              => $chat_id,
			'conversation_chat_id' => $chat_id,
			'trace_id'             => (string) ( $event['trace_id'] ?? '' ),
			'_trigger'             => self::TRIGGER_TYPE,
			'_no_automation_reentry' => true,
			'inbound'              => array(
				'platform'   => 'ZALO_PERSONAL',
				'chat_id'    => $chat_id,
				'user_id'    => '',
				'account_id' => $account_id,
				'message_id' => (string) $message_id,
				'raw_text'   => '',
			),
		);
	}

	/**
	 * Pure: why a workflow's trigger config does NOT accept this payload ('' = accepted).
	 *
	 * @param array $cfg     Decoded trigger_config.
	 * @param array $payload Output of build_payload().
	 */
	public static function reject_reason( array $cfg, array $payload ): string {
		$wanted_account = trim( (string) ( $cfg['instance_id'] ?? $cfg['account_id'] ?? '' ) );
		if ( '' !== $wanted_account && $wanted_account !== (string) ( $payload['account_id'] ?? '' ) ) {
			return 'account_mismatch';
		}
		$wanted_guru = (int) ( $cfg['guru_id'] ?? 0 );
		if ( $wanted_guru > 0 && $wanted_guru !== (int) ( $payload['character_id'] ?? 0 ) ) {
			return 'guru_mismatch';
		}
		return '';
	}
}
