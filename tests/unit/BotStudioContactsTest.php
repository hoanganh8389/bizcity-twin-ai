<?php
/**
 * PHASE-0.60J BG-6/BG-7 — pure helpers of the Bot Studio Contacts projection and the per-account trace.
 *
 * group_contacts(): CRM conversation rows → one entry per contact (groups dropped, UID masked, only custom_meta
 * exposed from additional_attributes). trace_accounts(): one customer's conversations grouped per number.
 *
 * // [2026-09-24 Claude Sonnet 5] PHASE-0.60J
 */

require_once dirname( __DIR__, 2 ) . '/core/channel-gateway/includes/bot/class-bot-rest.php';
require_once dirname( __DIR__, 2 ) . '/core/channel-gateway/includes/bot/class-bot-studio-rest.php';

use PHPUnit\Framework\TestCase;

final class BotStudioContactsTest extends TestCase {

	private function row( int $id, int $contact_id, string $source, string $account, string $activity, array $attrs = array(), string $ref = '' ): array {
		return array(
			'id'                   => $id,
			'contact_id'           => $contact_id,
			'source_id'            => $source,
			'account_id'           => $account,
			'inbox_ref_id'         => $ref,
			'contact_name'         => 'Khách ' . $contact_id,
			'contact_avatar'       => null,
			'last_activity_at'     => $activity,
			'last_message_content' => 'msg ' . $id,
			'contact_attributes'   => json_encode( $attrs ),
		);
	}

	public function test_one_entry_per_contact_with_newest_conversation_and_per_number_breakdown(): void {
		$rows = array(
			$this->row( 5, 1, '5620912345086', '25', '2026-09-24 10:00:00' ),
			$this->row( 4, 1, '5620912345086', '26', '2026-09-24 12:00:00' ),
			$this->row( 3, 2, '8870000000001', '25', '2026-09-23 09:00:00' ),
		);
		$out = BizCity_Bot_Studio_REST::group_contacts( $rows );
		$this->assertCount( 2, $out );
		$this->assertSame( 1, $out[0]['contact_id'], 'newest activity first' );
		$this->assertSame( 2, $out[0]['conversation_count'] );
		$this->assertSame( 4, $out[0]['conversation_id'], 'points at the newest conversation (opens Sessions detail)' );
		$this->assertCount( 2, $out[0]['accounts'] );
		$this->assertSame( array( '25', '26' ), array_column( $out[0]['accounts'], 'account_id' ) );
	}

	public function test_group_threads_and_contactless_rows_are_dropped_and_uid_is_masked(): void {
		$rows = array(
			$this->row( 1, 1, 'group:1234567890123', '25', '2026-09-24 10:00:00' ),
			$this->row( 2, 0, '5620912345086', '25', '2026-09-24 10:00:00' ),
			$this->row( 3, 7, '5620912345086', '25', '2026-09-24 10:00:00' ),
		);
		$out = BizCity_Bot_Studio_REST::group_contacts( $rows );
		$this->assertCount( 1, $out );
		$this->assertSame( '562…86', $out[0]['external_uid'] );
		$this->assertStringNotContainsString( '5620912345086', json_encode( $out ) );
	}

	public function test_only_custom_meta_is_exposed_from_additional_attributes(): void {
		$attrs = array(
			'zalo_profile' => array( 'display_name' => 'SECRET-PROFILE' ),
			'birthday_meta' => array( 'source' => 'staff' ),
			'custom_meta'  => array( 'vip' => true, 'nguon' => 'ads' ),
		);
		$out = BizCity_Bot_Studio_REST::group_contacts( array( $this->row( 1, 1, '5620912345086', '25', '2026-09-24 10:00:00', $attrs ) ) );
		$this->assertSame( array( 'vip' => true, 'nguon' => 'ads' ), $out[0]['custom_meta'] );
		$this->assertSame( 2, $out[0]['custom_meta_keys'] );
		$encoded = json_encode( $out );
		$this->assertStringNotContainsString( 'SECRET-PROFILE', $encoded, 'system attribute keys must never leave the CRM owner' );
		$this->assertStringNotContainsString( 'zalo_profile', $encoded );
	}

	public function test_account_falls_back_to_the_inbox_when_the_column_is_empty(): void {
		$out = BizCity_Bot_Studio_REST::group_contacts( array( $this->row( 1, 1, '5620912345086', '', '2026-09-24 10:00:00', array(), '25' ) ) );
		$this->assertSame( '25', $out[0]['accounts'][0]['account_id'], 'conversations.account_id is empty on real rows (0.60I)' );
	}

	public function test_trace_accounts_groups_one_customer_per_number_newest_first(): void {
		$rows  = array(
			$this->row( 10, 1, '5620912345086', '25', '2026-09-24 08:00:00' ),
			$this->row( 11, 1, '5620912345086', '', '2026-09-24 11:00:00', array(), '26' ),
			$this->row( 12, 1, '5620912345086', '25', '2026-09-24 09:00:00' ),
		);
		$trace = BizCity_Bot_Studio_REST::trace_accounts( $rows );
		$this->assertSame( array( '26', '25' ), array_column( $trace, 'account_id' ) );
		$this->assertSame( 2, $trace[1]['conversations'] );
		$this->assertSame( 12, $trace[1]['conversation_id'], 'newest conversation of that number' );
		$this->assertSame( array(), BizCity_Bot_Studio_REST::trace_accounts( array() ) );
	}
}
