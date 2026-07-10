<?php
/**
 * BizCity Channel — Magic Link Capable Interface (Phase 3.5 Wave D).
 *
 * Adapters MAY implement this interface to customise how a magic-link URL is
 * generated or delivered for their platform. Adapters that do NOT implement
 * this interface fall back to the generic `BizCity_CRM_Magic_Link::issue()`
 * handler via `BizCity_Channel_Adapter_Base::issue_magic_link()`.
 *
 * Platforms where a platform-specific deep-link is preferred
 * (e.g. Telegram `tg://resolve?domain=botname&start=TOKEN`):
 *   - `BizCity_Telegram_Adapter` — generates `tg://` deep-link
 *   - FB Messenger deep-link via `m.me/PAGE?ref=TOKEN` (handled inline)
 *
 * Usage:
 *   if ( $adapter instanceof BizCity_Channel_Magic_Link_Capable ) {
 *       $result = $adapter->issue_magic_link( $chat_id, $args );
 *   } else {
 *       $result = $adapter->issue_magic_link( $chat_id, $args ); // base default
 *   }
 *
 * @package    BizCity_Twin_AI
 * @subpackage Channel_Gateway
 * @since      PHASE 3.5-WD
 */

defined( 'ABSPATH' ) || exit;

interface BizCity_Channel_Magic_Link_Capable {

	/**
	 * Issue a magic link for the given chat_id on this adapter's platform.
	 *
	 * @param string $chat_id   Platform-canonical chat_id (with or without prefix).
	 * @param array  $args {
	 *     Optional overrides passed to BizCity_CRM_Magic_Link::issue().
	 *     @type int    $blog_id
	 *     @type string $bot_id        Bot / page identifier (auto-resolved if empty).
	 *     @type string $intent        login | admin | consent. Default 'login'.
	 *     @type int    $character_id  Guru character id.
	 *     @type int    $ttl_seconds   Default 1800.
	 *     @type array  $meta          Extra payload.
	 * }
	 * @return array{token:string,url:string,expires_at:string,id:int}|WP_Error
	 */
	public function issue_magic_link( string $chat_id, array $args = array() );
}
