<?php
/**
 * PHASE-0.60D S1.2–S1.8, E-D1/E-D2 — contact-scoped astro subject, ask-once, capture, identity invariant.
 * // [2026-09-23 04:40 PM Claude Fable 5.1] PHASE-0.60D-test
 */

require_once __DIR__ . '/support/bot-studio-stubs.php';
require_once dirname( __DIR__, 2 ) . '/core/channel-gateway/includes/bot/class-bot-vn-date.php';
require_once dirname( __DIR__, 2 ) . '/core/channel-gateway/includes/bot/class-bot-astro-tool.php';

use PHPUnit\Framework\TestCase;

final class BotAstroToolTest extends TestCase {

	private $contacts = array();
	private $writes   = array();

	protected function setUp(): void {
		$GLOBALS['bzc_transfer_transients'] = array();
		$GLOBALS['bizcity_transients_stub'] = array();
		$this->contacts = array(
			41 => array( 'id' => 41, 'name' => 'Khách A', 'birthday' => '1990-03-12', 'additional_attributes' => wp_json_encode( array( 'birth_time' => '07:00', 'birthday_meta' => array( 'source' => 'customer_stated' ) ) ) ),
			42 => array( 'id' => 42, 'name' => 'Khách B', 'birthday' => null, 'additional_attributes' => '' ),
		);
		$this->writes = array();
		$contacts = &$this->contacts;
		$writes   = &$this->writes;
		BizCity_Bot_Astro_Tool::$contact_reader  = static function ( int $id ) use ( &$contacts ) { return $contacts[ $id ] ?? null; };
		BizCity_Bot_Astro_Tool::$birthday_writer = static function ( int $id, string $date, string $time, array $meta ) use ( &$writes, &$contacts ) {
			$writes[] = compact( 'id', 'date', 'time', 'meta' );
			$contacts[ $id ]['birthday'] = $date;
			return true;
		};
	}

	protected function tearDown(): void {
		BizCity_Bot_Astro_Tool::$contact_reader  = null;
		BizCity_Bot_Astro_Tool::$birthday_writer = null;
	}

	public function test_two_contacts_two_conversations_each_get_their_own_subject(): void {
		// E-D2 — identity isolation: the subject follows contact_id of the conversation, nothing else.
		$a = BizCity_Bot_Astro_Tool::resolve( array( 'contact_id' => 41, 'conversation_id' => 1, 'account_id' => '3' ) );
		$b = BizCity_Bot_Astro_Tool::resolve( array( 'contact_id' => 42, 'conversation_id' => 2, 'account_id' => '3' ) );
		$this->assertTrue( $a['success'] );
		$this->assertSame( '1990-03-12', $a['birth']['date'] );
		$this->assertSame( '07:00', $a['birth']['time'] );
		$this->assertFalse( $b['success'] );
		$this->assertSame( 'astro_birth_data_missing', $b['_degraded'], 'reuses the existing reason bucket' );
	}

	public function test_missing_birth_data_asks_once_then_answers_generally(): void {
		$claim = array( 'contact_id' => 42, 'conversation_id' => 2 );
		$first = BizCity_Bot_Astro_Tool::run( array(), $claim );
		$this->assertTrue( $first['ask'] );
		$this->assertStringContainsString( 'MỘT lần', $first['content'] );
		$second = BizCity_Bot_Astro_Tool::run( array(), $claim );
		$this->assertFalse( $second['ask'], 'S1.5 — never asks twice in the same conversation' );
		$this->assertStringContainsString( 'NÓI THẬT', $second['content'] );
		$this->assertStringContainsString( 'KHÔNG bịa', $second['content'] );
	}

	public function test_capture_saves_customer_stated_birthday_with_message_id_and_clears_ask(): void {
		$claim = array( 'contact_id' => 42, 'conversation_id' => 2, 'message_id' => 555 );
		$this->assertSame( 'none', BizCity_Bot_Astro_Tool::capture_from_message( $claim, '13/03/1990' )['status'], 'nothing is captured unless the bot asked' );
		BizCity_Bot_Astro_Tool::run( array(), $claim ); // asks
		$cap = BizCity_Bot_Astro_Tool::capture_from_message( $claim, '13/03/1990 khoảng 7h sáng' );
		$this->assertSame( 'ok', $cap['status'] );
		$this->assertTrue( $cap['saved'] );
		$this->assertCount( 1, $this->writes );
		$this->assertSame( 42, $this->writes[0]['id'] );
		$this->assertSame( '1990-03-13', $this->writes[0]['date'] );
		$this->assertSame( '07:00', $this->writes[0]['time'] );
		$this->assertSame( 'customer_stated', $this->writes[0]['meta']['source'] );
		$this->assertSame( 555, $this->writes[0]['meta']['message_id'] );
		$this->assertFalse( BizCity_Bot_Astro_Tool::already_asked( 2 ) );
		$this->assertTrue( BizCity_Bot_Astro_Tool::resolve( $claim )['success'], 'E-D1 — the next turn finds the subject' );
	}

	public function test_ambiguous_date_is_confirmed_not_guessed(): void {
		$claim = array( 'contact_id' => 42, 'conversation_id' => 3, 'message_id' => 1 );
		BizCity_Bot_Astro_Tool::run( array(), $claim );
		$cap = BizCity_Bot_Astro_Tool::capture_from_message( $claim, '05/06/1990' );
		$this->assertSame( 'ambiguous', $cap['status'] );
		$this->assertFalse( $cap['saved'] );
		$this->assertCount( 0, $this->writes );
		$this->assertStringContainsString( '05/06/1990', BizCity_Bot_Astro_Tool::confirm_instruction( $cap ) );
		$this->assertTrue( BizCity_Bot_Astro_Tool::already_asked( 3 ), 'still waiting for the confirmation' );
	}

	public function test_source_never_touches_logged_in_identity_or_account_owner(): void {
		// S1.4 negative test — R-COACHEE re-read for a customer channel (doc 0.60D §2.4).
		$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/core/channel-gateway/includes/bot/class-bot-astro-tool.php' );
		$code = preg_replace( '#/\*.*?\*/#s', '', $src );          // strip docblocks
		$code = preg_replace( '#//[^\n]*#', '', (string) $code );   // strip line comments
		$this->assertStringNotContainsString( 'get_current_user_id', (string) $code );
		$this->assertStringNotContainsString( 'is_self', (string) $code );
		$this->assertStringNotContainsString( 'owner_user_id', (string) $code );
		$this->assertStringNotContainsString( 'resolve_by_user', (string) $code );
		$this->assertStringNotContainsString( 'bccm_get_self_coachee', (string) $code );
	}
}
