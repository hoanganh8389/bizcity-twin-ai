<?php
/**
 * WebChat Channel Adapter — Legacy Shim (PHASE 0.37 M3.W3)
 *
 * Delegates outbound to BizCity_WebChat_Trigger / BizCity_WebChat_Database.
 * M4 will add real webhook normalization.
 *
 * @package    BizCity_Twin_AI
 * @subpackage Channel_Gateway
 * @since      PHASE 0.37
 */

defined( 'ABSPATH' ) || exit;

class BizCity_WebChat_Adapter extends BizCity_Channel_Adapter_Base {

	public function get_platform(): string {
		return 'WEBCHAT';
	}

	public function get_prefix(): string {
		return 'webchat_';
	}

	public function get_endpoints(): array {
		return [ '/bizcity-channel/v1/webhook/webchat' ];
	}

	/**
	 * Outbound: persist a bot/agent message into wp_bizcity_webchat_messages so the
	 * visitor float widget picks it up on its next /wp-json/bizcity-webchat/v1/pull.
	 *
	 * Used by:
	 *   - Auto replies fired through BizCity_Gateway_Sender (not the legacy path,
	 *     which goes via webchat-trigger directly).
	 *   - **CRM Inbox composer** (POST /bizcity/cg/v1/inbox/send) — Phase 0.36 W3.
	 *
	 * `chat_id` arrives as `webchat_<session_id>` (Universal_Channel_Listener
	 * compose prefix). We strip that prefix to recover the raw session_id used
	 * by the float widget's poll loop.
	 *
	 * Note: this path does NOT write to `_bizcity_channel_messages` itself —
	 * BizCity_Gateway_Sender fires `bizcity_channel_outbound_logged` after we
	 * return, and BizCity_Responder_Stamper::on_outbound_logged() auto-stamps
	 * the row with the active Stamper::push() context (manual/hybrid + user_id +
	 * character_id from binding).
	 */
	public function send_outbound( string $chat_id, string $message, array $options = [] ): bool {
		$message = (string) $message;
		if ( $message === '' ) {
			return false;
		}

		// Recover raw webchat session_id (strip `webchat_` / `web_` / `sess_` prefixes).
		$session_id = $chat_id;
		foreach ( array( 'webchat_', 'web_', 'sess_', 'wcs_' ) as $p ) {
			if ( strpos( $session_id, $p ) === 0 ) {
				$session_id = substr( $session_id, strlen( $p ) );
				break;
			}
		}
		if ( $session_id === '' ) {
			return false;
		}

		$type        = (string) ( $options['type'] ?? 'text' );
		$attachments = isset( $options['attachments'] ) && is_array( $options['attachments'] )
			? $options['attachments'] : array();
		$message_id  = isset( $options['message_id'] ) && $options['message_id'] !== ''
			? (string) $options['message_id']
			: uniqid( 'bcm_' );

		// Identify the responder for downstream UIs that read wp_bizcity_webchat_messages.
		$client_name = 'BizChat Bot';
		if ( class_exists( 'BizCity_Responder_Stamper' ) ) {
			$ctx = BizCity_Responder_Stamper::current();
			if ( ! empty( $ctx['user_id'] ) ) {
				$user = get_userdata( (int) $ctx['user_id'] );
				if ( $user ) {
					$client_name = $user->display_name ?: $user->user_login;
				}
			}
		}

		$ok = false;
		if ( class_exists( 'BizCity_WebChat_Database' ) ) {
			$db  = BizCity_WebChat_Database::instance();
			$res = $db->log_message( array(
				'session_id'    => $session_id,
				'user_id'       => 0,
				'client_name'   => $client_name,
				'message_id'    => $message_id,
				'message_text'  => $message,
				'message_from'  => 'bot',
				'message_type'  => $type,
				'attachments'   => $attachments,
				'platform_type' => 'WEBCHAT',
			) );
			$ok = ( $res !== false );
		}

		// Optional realtime broadcast (WebSocket / SSE bridges).
		do_action( 'bizcity_webchat_push_message', $session_id, $message, $options );

		return $ok;
	}

	protected function test_connection(): array {
		$ok = class_exists( 'BizCity_WebChat_Database' ) || class_exists( 'BizCity_WebChat_Trigger' );
		return [
			'success' => $ok,
			'error'   => $ok ? '' : 'BizCity_WebChat_Database / BizCity_WebChat_Trigger not loaded',
			'note'    => 'WebChat is local — no external API to ping',
		];
	}
}
