<?php
/**
 * Action: Ensure the inbound sender has a linked WordPress identity.
 *
 * This block is deliberately side-effect free. Link issuance remains owned by
 * the Zalo Bot linker/command flow; workflows only branch on the result.
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Automation_Action_Ensure_Linked_User' ) ) {
	return;
}

final class BizCity_Automation_Action_Ensure_Linked_User extends BizCity_Automation_Block_Base {

	public function id(): string { return 'action.ensure_linked_user'; }
	public function kind(): string { return 'action'; }

	public function meta(): array {
		return array(
			'label'    => 'Kiểm tra liên kết tài khoản',
			'short'    => 'ensure_linked_user',
			'category' => 'identity',
			'color'    => '#0f766e',
			'icon'     => 'shield-check',
			'defaults' => array( 'label' => 'Kiểm tra liên kết tài khoản' ),
			'fields'   => array( array( 'name' => 'label', 'label' => 'Tên hiển thị', 'type' => 'text' ) ),
		);
	}

	public function execute( array $ctx, array $data ) {
		// [2026-09-01 Johnny Chu] PHASE-0.45-W4 — branch on the linked sender, never on runner workflow fallback owner.
		$trigger = isset( $ctx['trigger'] ) && is_array( $ctx['trigger'] ) ? $ctx['trigger'] : array();
		$owner   = (int) ( $trigger['wp_user_id'] ?? 0 );
		if ( $owner > 0 ) {
			return array( 'ok' => true, 'linked' => true, 'wp_user_id' => $owner, 'status' => 'linked' );
		}

		$chat_kind = sanitize_key( (string) ( $trigger['chat_kind'] ?? 'private' ) );
		return array(
			'ok'          => false,
			'linked'      => false,
			'wp_user_id'  => 0,
			'status'      => 'login_required',
			'chat_kind'   => $chat_kind,
			'public_hint' => $chat_kind === 'group'
				? 'Bạn cần nhắn riêng Bot BizCity hoặc dùng /link trong Twin GPT để liên kết tài khoản.'
				: 'Bạn cần liên kết tài khoản Twin GPT trước khi dùng kịch bản này.',
		);
	}
}