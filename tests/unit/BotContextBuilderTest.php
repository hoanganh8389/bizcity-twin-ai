<?php
/**
 * PHASE-0.60E EA-3 · "Nghe passive trong nhóm" (doc §6 EA-3) — BizCity_Bot_Context_Builder tests.
 *
 * EA-3.2 pins TODAY'S behavior (default passive_listen_in_group=true): a group message that
 * never got an @mention still flows into history() on a later turn, because the CRM ingestor
 * writes it unconditionally and nothing at the read layer filtered it out before this feature.
 * EA-3.3 adds the opt-out: when the binding turns it off, those same rows must disappear from
 * history() — but ONLY the unmentioned incoming group rows; everything else (private chat,
 * @mentioned group rows, outgoing/assistant rows, legacy rows with no mention_detected flag at
 * all) must be unaffected, per the doc's "lọc ở tầng đọc, không chặn ingestor" instruction.
 *
 * // [2026-09-23 Claude Sonnet 5] PHASE-0.60E EA-3-test
 */

require_once __DIR__ . '/support/bot-studio-stubs.php';
require_once dirname( __DIR__, 2 ) . '/core/channel-gateway/includes/bot/class-bot-context-builder.php';

use PHPUnit\Framework\TestCase;

final class BotContextBuilderTest extends TestCase {

	protected function setUp(): void {
		BizCity_CRM_Repository::reset();
		BizCity_Bot_Context_Builder::$history_reader = null;
		BizCity_Bot_Context_Builder::$contact_block_reader = static function ( int $contact_id ): string { return ''; };
	}

	/** group row: message_type/thread_kind/mention_detected are the only fields history() reads besides content/id. */
	private function group_row( int $id, string $content, bool $mentioned, string $type = 'incoming' ): array {
		return array(
			'id'               => $id,
			'conversation_id'  => 9,
			'message_type'     => $type,
			'content'          => $content,
			'ai_metadata_json' => wp_json_encode( array( 'thread_kind' => 'group', 'mention_detected' => $mentioned ) ),
		);
	}

	private function private_row( int $id, string $content, string $type = 'incoming' ): array {
		return array( 'id' => $id, 'conversation_id' => 9, 'message_type' => $type, 'content' => $content );
	}

	public function test_default_keeps_unmentioned_group_row_pinning_todays_behavior(): void {
		// EA-3.2 — the exact behavior §1.1 of the doc calls "đúng nhưng tình cờ": no @mention,
		// no reply sent, but the row is still readable as context on a later turn.
		BizCity_CRM_Repository::$messages = array(
			$this->group_row( 1, 'khách A hỏi trong nhóm không @mention', false ),
			$this->group_row( 2, 'bot trả lời câu hỏi khác có @mention', true ),
		);
		$rows = BizCity_Bot_Context_Builder::history( 9, 20, 'crm', true );
		$this->assertCount( 2, $rows, 'passive_listen_in_group=true (default) must not drop anything' );
	}

	public function test_turned_off_excludes_unmentioned_group_row_only(): void {
		// EA-3.3 — turning it off must filter at the read layer: the unmentioned row disappears
		// from history() while the @mentioned one stays.
		BizCity_CRM_Repository::$messages = array(
			$this->group_row( 1, 'khách A hỏi trong nhóm không @mention', false ),
			$this->group_row( 2, 'khách B @mention bot', true ),
		);
		$rows = BizCity_Bot_Context_Builder::history( 9, 20, 'crm', false );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'khách B @mention bot', $rows[0]['content'] );
	}

	public function test_turned_off_never_touches_private_chat_rows(): void {
		BizCity_CRM_Repository::$messages = array(
			$this->private_row( 1, 'khách nhắn riêng' ),
			$this->private_row( 2, 'bot trả lời', 'outgoing' ),
		);
		$rows = BizCity_Bot_Context_Builder::history( 9, 20, 'crm', false );
		$this->assertCount( 2, $rows, 'EA-3 only scopes group threads (B6.3) — private chat is untouched' );
	}

	public function test_turned_off_never_drops_outgoing_group_rows(): void {
		// A staff/bot reply sent INTO the group is still relevant context even though it did not
		// itself carry an @mention flag from a customer.
		BizCity_CRM_Repository::$messages = array(
			$this->group_row( 1, 'khách hỏi không @mention', false ),
			$this->group_row( 2, 'nhân viên trả lời tay trong nhóm', false, 'outgoing' ),
		);
		$rows = BizCity_Bot_Context_Builder::history( 9, 20, 'crm', false );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'assistant', $rows[0]['role'] );
	}

	public function test_turned_off_keeps_legacy_row_with_no_mention_flag_at_all(): void {
		// A row written before this feature existed carries no mention_detected key — "unknown"
		// must never silently become "drop it" (would be a surprise regression on old data).
		BizCity_CRM_Repository::$messages = array(
			array( 'id' => 1, 'conversation_id' => 9, 'message_type' => 'incoming', 'content' => 'tin nhóm cũ, chưa có cờ mention', 'ai_metadata_json' => wp_json_encode( array( 'thread_kind' => 'group' ) ) ),
		);
		$rows = BizCity_Bot_Context_Builder::history( 9, 20, 'crm', false );
		$this->assertCount( 1, $rows );
	}
}
