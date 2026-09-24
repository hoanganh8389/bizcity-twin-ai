<?php
/**
 * PHASE-0.60H D-H7 — Zalo Cá nhân has exactly ONE auto-replier (Bot Studio). The CRM AI auto-reply listener
 * must yield that channel and keep every other channel it owns today.
 *
 * // [2026-09-24 Claude Opus 5.5] PHASE-0.60H D-H7-test
 */

require_once dirname( __DIR__, 2 ) . '/plugins/bizcity-twin-crm/includes/class-ai-autoreply-listener.php';

use PHPUnit\Framework\TestCase;

final class CrmAutoreplyYieldsToBotStudioTest extends TestCase {

	protected function tearDown(): void {
		$GLOBALS['__bzc_hooks'] = array();
	}

	public function test_zalo_personal_is_owned_by_bot_studio_and_other_channels_are_not(): void {
		$this->assertTrue( BizCity_CRM_AI_Autoreply_Listener::yields_to_bot_studio( 'zalo_personal' ) );
		$this->assertTrue( BizCity_CRM_AI_Autoreply_Listener::yields_to_bot_studio( 'ZALO_PERSONAL' ) );
		foreach ( array( 'facebook', 'messenger', 'zalo_oa', 'webchat', '' ) as $channel ) {
			$this->assertFalse( BizCity_CRM_AI_Autoreply_Listener::yields_to_bot_studio( $channel ), $channel . ' keeps the CRM AI_Replier' );
		}
	}

	public function test_rollback_filter_can_hand_zalo_personal_back(): void {
		add_filter( 'bizcity_crm_ai_autoreply_yield_to_bot_studio', '__return_false' );
		$this->assertFalse( BizCity_CRM_AI_Autoreply_Listener::yields_to_bot_studio( 'zalo_personal' ) );
	}

	/**
	 * The listener's full path needs a live CRM; this pins the ORDER that matters: the yield check runs before
	 * AI_Replier is ever called, so a zalo_personal message can never reach a second reply.
	 */
	public function test_listener_checks_the_yield_before_calling_ai_replier(): void {
		$src    = (string) file_get_contents( dirname( __DIR__, 2 ) . '/plugins/bizcity-twin-crm/includes/class-ai-autoreply-listener.php' );
		$body   = substr( $src, (int) strpos( $src, 'function on_message_received' ) );
		$yield  = strpos( $body, 'self::yields_to_bot_studio(' );
		$reply  = strpos( $body, 'BizCity_CRM_AI_Replier::reply(' );
		$this->assertNotFalse( $yield, 'on_message_received must consult yields_to_bot_studio()' );
		$this->assertNotFalse( $reply );
		$this->assertLessThan( $reply, $yield );
	}
}
