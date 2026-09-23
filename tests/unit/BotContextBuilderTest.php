<?php
/**
 * PHASE-0.60A B6.1–B6.7 — context builder: window, budget, contact block, hybrid fill, isolation.
 * // [2026-09-23 04:40 PM Claude Fable 5.1] PHASE-0.60A-test
 */

require_once __DIR__ . '/support/bot-studio-stubs.php';
require_once dirname( __DIR__, 2 ) . '/core/channel-gateway/includes/bot/class-bot-context-builder.php';

use PHPUnit\Framework\TestCase;

final class BotContextBuilderTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['__bzc_hooks'] = array();
		BizCity_Bot_Context_Builder::$history_reader       = null;
		BizCity_Bot_Context_Builder::$contact_block_reader = null;
	}

	protected function tearDown(): void {
		$GLOBALS['__bzc_hooks'] = array();
		BizCity_Bot_Context_Builder::$history_reader       = null;
		BizCity_Bot_Context_Builder::$contact_block_reader = null;
	}

	private function reader( array $per_conversation ): void {
		BizCity_Bot_Context_Builder::$history_reader = static function ( int $conversation_id, int $limit ) use ( $per_conversation ) {
			$rows = $per_conversation[ $conversation_id ] ?? array();
			return array_slice( $rows, -$limit );
		};
	}

	private function rows( int $n, string $prefix = 'm' ): array {
		$out = array();
		for ( $i = 1; $i <= $n; $i++ ) {
			$out[] = array( 'id' => $i, 'role' => $i % 2 ? 'user' : 'assistant', 'content' => $prefix . $i, 'source' => 'crm' );
		}
		return $out;
	}

	public function test_persona_first_then_history_of_this_conversation_only(): void {
		$this->reader( array( 7 => $this->rows( 5, 'seven-' ), 8 => $this->rows( 5, 'eight-' ) ) );
		$built = BizCity_Bot_Context_Builder::build( (object) array( 'system_prompt' => 'Bạn là trợ lý.' ), 7, 0, array( 'history_limit' => 20 ) );
		$this->assertSame( 'system', $built['messages'][0]['role'] );
		$this->assertStringStartsWith( 'Bạn là trợ lý.', $built['messages'][0]['content'] );
		$joined = wp_json_encode( $built['messages'] );
		$this->assertStringContainsString( 'seven-5', $joined );
		$this->assertStringNotContainsString( 'eight-', $joined, 'B6.7 — never another conversation\'s messages' );
		$this->assertSame( 5, $built['meta']['history_kept'] );
	}

	public function test_history_limit_and_char_budget_drop_oldest_never_cut_inside(): void {
		$rows = array();
		for ( $i = 1; $i <= 6; $i++ ) {
			$rows[] = array( 'id' => $i, 'role' => 'user', 'content' => str_repeat( chr( 96 + $i ), 1000 ), 'source' => 'crm' );
		}
		$this->reader( array( 1 => $rows ) );
		$built = BizCity_Bot_Context_Builder::build( (object) array( 'system_prompt' => 'P' ), 1, 0, array( 'history_limit' => 6, 'char_budget' => 2500 ) );
		$this->assertSame( 2, $built['meta']['history_kept'], 'only the two newest 1000-char messages fit a 2500 budget' );
		$this->assertSame( 4, $built['meta']['history_dropped'] );
		foreach ( array_slice( $built['messages'], 1 ) as $m ) {
			$this->assertSame( 1000, mb_strlen( $m['content'] ), 'a kept message is never truncated' );
		}
		$this->assertSame( 'f', $built['messages'][ count( $built['messages'] ) - 1 ]['content'][0], 'newest kept' );
	}

	public function test_contact_block_is_included_and_capped(): void {
		$this->reader( array( 1 => $this->rows( 2 ) ) );
		BizCity_Bot_Context_Builder::$contact_block_reader = static function ( int $contact_id ) {
			return 'Người đang nhắn: liên hệ #' . $contact_id . ' ' . str_repeat( 'x', 2000 );
		};
		$built = BizCity_Bot_Context_Builder::build( (object) array( 'system_prompt' => 'P' ), 1, 42, array() );
		$this->assertTrue( $built['meta']['contact_block'] );
		$this->assertStringContainsString( 'liên hệ #42', $built['messages'][0]['content'] );
		$this->assertLessThan( 2000, mb_strlen( $built['messages'][0]['content'] ), 'contact block cannot push history out (0.60B §6 rule 3)' );
		$built = BizCity_Bot_Context_Builder::build( (object) array( 'system_prompt' => 'P' ), 1, 0, array() );
		$this->assertFalse( $built['meta']['contact_block'], 'no contact → no block, not an error' );
	}

	public function test_hybrid_fills_from_context_bank_seam_and_labels_source(): void {
		$this->reader( array( 1 => $this->rows( 3 ) ) );
		add_filter( 'bizcity_bot_context_history_fill', static function ( $fill, $conversation_id, $missing, $rows ) {
			return array(
				array( 'id' => 0, 'role' => 'user', 'content' => 'old from filestore', 'source' => 'filestore' ),
				array( 'id' => 0, 'role' => 'assistant', 'content' => 'summary of earlier', 'source' => 'summary' ),
			);
		}, 10, 4 );
		$history = BizCity_Bot_Context_Builder::history( 1, 10, 'hybrid' );
		$this->assertCount( 5, $history );
		$this->assertSame( 'filestore', $history[0]['source'] );
		$this->assertSame( 'crm', $history[4]['source'] );
		$crm_only = BizCity_Bot_Context_Builder::history( 1, 10, 'crm' );
		$this->assertCount( 3, $crm_only, '"Chỉ CRM" never consults the seam' );
		$preview = BizCity_Bot_Context_Builder::preview( 1, 10 );
		$this->assertSame( 'filestore', $preview[4]['source'] );
		$this->assertSame( 'tóm tắt', $preview[3]['role'] );
	}

	public function test_system_block_carries_injection_guard_and_no_markdown_rule(): void {
		$this->reader( array( 1 => array() ) );
		$built = BizCity_Bot_Context_Builder::build( (object) array( 'system_prompt' => '' ), 1, 0, array() );
		$this->assertStringContainsString( 'không dùng markdown', $built['messages'][0]['content'] );
		$this->assertStringContainsString( '[DỮ LIỆU NGOÀI]', $built['messages'][0]['content'] );
	}
}
