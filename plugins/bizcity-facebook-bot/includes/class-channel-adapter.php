<?php
/**
 * BizCity Facebook Bot — Channel Adapter (PHASE 0.31 T-S1.4)
 *
 * Implements the gateway-side `BizCity_Channel_Adapter` interface so
 * Facebook Messenger / Page bots routed by this mu-plugin participate
 * in the unified channel-gateway pipeline.
 *
 * Migrated from `plugins/bizcity-twin-ai/plugins/bizcity-tool-facebook/
 * includes/class-channel-adapter.php` (BUG-4 in PHASE-0.31 audit §L2.2):
 * the adapter previously lived in a plugin that may be disabled in
 * `$_bizcity_bundled_must_load`, leaving the always-on mu-plugin without
 * a registered adapter. Moving it here guarantees registration whenever
 * Facebook Bot is active.
 *
 * Storage source of truth:
 *   - wp_bizcity_facebook_bots (BizCity_Facebook_Bot_Database)
 *   - wp_bizcity_facebook_inbox / _logs
 *
 * @package BizCity\FacebookBot
 * @since   1.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Defer registration until the channel-gateway interface is loaded.
if ( ! interface_exists( 'BizCity_Channel_Adapter' ) ) {
	return;
}

class BizCity_Facebook_Bot_Channel_Adapter implements BizCity_Channel_Adapter, BizCity_Channel_Magic_Link_Capable {

	public function get_platform(): string {
		return 'FACEBOOK';
	}

	public function get_prefix(): string {
		return 'fb_';
	}

	public function get_endpoints(): array {
		// Mu-plugin uses query-var fallback (?fbhook=1) loaded at init priority 0.
		// The legacy /bizfbhook/ rewrite from bizcity-tool-facebook is being
		// retired in T-S1.5; keep both listed during the deprecation window so
		// gateway dashboards reflect reality.
		return array( '?fbhook=1', '/bizfbhook/' );
	}

	/**
	 * Verify Facebook webhook signature (X-Hub-Signature-256).
	 * Both legacy `bztfb_app_secret` and new `bizcity_facebook_bot_app_secret`
	 * are checked to keep installs migrated mid-flight working.
	 */
	public function verify_webhook( array $request ): bool {
		$app_secret = get_option( 'bizcity_facebook_bot_app_secret', '' );
		if ( empty( $app_secret ) ) {
			$app_secret = get_option( 'bztfb_app_secret', '' );
		}
		if ( empty( $app_secret ) ) {
			return true; // dev mode
		}
		$body   = isset( $request['body'] ) ? $request['body'] : '';
		$header = '';
		if ( ! empty( $request['headers']['x-hub-signature-256'] ) ) {
			$header = $request['headers']['x-hub-signature-256'];
		} elseif ( ! empty( $request['headers']['HTTP_X_HUB_SIGNATURE_256'] ) ) {
			$header = $request['headers']['HTTP_X_HUB_SIGNATURE_256'];
		}
		if ( empty( $header ) ) {
			return false;
		}
		$expected = 'sha256=' . hash_hmac( 'sha256', $body, $app_secret );
		return hash_equals( $expected, $header );
	}

	/**
	 * Normalize raw Messenger event to gateway-standard shape.
	 *
	 * @param array $raw_data  Pre-parsed event from the webhook handler.
	 *                         Expected keys: page_id, psid, text, message_id,
	 *                         sender_name, attachments[].
	 */
	public function normalize_inbound( array $raw_data ): array {
		$page_id     = isset( $raw_data['page_id'] )     ? (string) $raw_data['page_id']     : '';
		$psid        = isset( $raw_data['psid'] )        ? (string) $raw_data['psid']        : '';
		$text        = isset( $raw_data['text'] )        ? (string) $raw_data['text']        : '';
		$mid         = isset( $raw_data['message_id'] )  ? (string) $raw_data['message_id']  : '';
		$name        = isset( $raw_data['sender_name'] ) ? (string) $raw_data['sender_name'] : '';

		$attachments = array();
		if ( ! empty( $raw_data['attachments'] ) && is_array( $raw_data['attachments'] ) ) {
			foreach ( $raw_data['attachments'] as $att ) {
				$attachments[] = array(
					'type' => isset( $att['type'] ) ? $att['type'] : '',
					'url'  => isset( $att['payload']['url'] )
						? $att['payload']['url']
						: ( isset( $att['url'] ) ? $att['url'] : '' ),
				);
			}
		}

		// Resolve bot_id (numeric) when possible — workflow blocks key off it.
		$bot_id = 0;
		if ( $page_id && class_exists( 'BizCity_Facebook_Bot_Database' ) ) {
			$bot = BizCity_Facebook_Bot_Database::instance()->get_bot_by_page_id( $page_id );
			if ( $bot && isset( $bot->id ) ) {
				$bot_id = (int) $bot->id;
			}
		}

		return array(
			'platform'    => 'FACEBOOK',
			'chat_id'     => 'fb_' . $page_id . '_' . $psid,
			'user_id'     => $psid,
			'client_name' => $name,
			'message'     => $text,
			'message_id'  => $mid,
			'attachments' => $attachments,
			'event_type'  => 'message',
			'bot_id'      => $bot_id ? $bot_id : $page_id,
			'page_id'     => $page_id,
			'raw'         => $raw_data,
		);
	}

	/**
	 * Send outbound text to a Messenger user.
	 *
	 * @param string $chat_id   Accepts:
	 *                            - fb_{page_id}_{psid}        (preferred)
	 *                            - fb_{psid}                  (single-page setup)
	 *                            - messenger_{psid}           (legacy)
	 *                            - {psid}                     (raw)
	 * @param string $message
	 * @param array  $options   May include `bot_id` or `page_id` to force routing
	 *                          when chat_id does not embed page_id, plus an
	 *                          `_account` payload injected by `WaicChannelIntegration::sendOutbound()`.
	 */
	public function send_outbound( string $chat_id, string $message, array $options = array() ): bool {
		$stripped = preg_replace( '/^(fb_|messenger_)/', '', $chat_id );
		$page_id  = isset( $options['page_id'] ) ? (string) $options['page_id'] : '';
		$psid     = $stripped;

		if ( preg_match( '/^(\d+)_(\d+)$/', $stripped, $m ) ) {
			$page_id = $page_id ?: $m[1];
			$psid    = $m[2];
		}

		if ( ! class_exists( 'BizCity_Facebook_Bot_Database' )
			|| ! class_exists( 'BizCity_Facebook_Bot_API' ) ) {
			return $this->fallback_legacy_send( $psid, $message );
		}

		$db  = BizCity_Facebook_Bot_Database::instance();
		$bot = null;

		if ( ! empty( $options['bot_id'] ) ) {
			$bot = $db->get_bot( (int) $options['bot_id'] );
		}
		if ( ! $bot && $page_id ) {
			$bot = $db->get_bot_by_page_id( $page_id );
		}
		if ( ! $bot ) {
			$bots = $db->get_active_bots();
			$bot  = ! empty( $bots ) ? $bots[0] : null;
		}
		if ( ! $bot || empty( $bot->page_access_token ) ) {
			return $this->fallback_legacy_send( $psid, $message );
		}

		$api    = new BizCity_Facebook_Bot_API( $bot->page_access_token, $bot->page_id );
		$result = $api->send_message( $psid, $message );

		$sent = ! is_wp_error( $result )
			&& ( ! empty( $result['message_id'] ) || ! empty( $result['recipient_id'] ) );

		if ( $sent && method_exists( $db, 'log_event' ) ) {
			$db->log_event(
				(int) $bot->id,
				'outbound_message',
				array( 'page_id' => $bot->page_id, 'psid' => $psid, 'text' => $message ),
				$psid, '', '', $message
			);
		}
		return $sent;
	}

	/**
	 * Last-ditch fallback to the legacy free-floating sender.
	 */
	private function fallback_legacy_send( string $psid, string $message ): bool {
		if ( function_exists( 'fbm_send_text_to_user' ) ) {
			return (bool) fbm_send_text_to_user( $psid, $message );
		}
		return false;
	}

	/**
	 * FB Messenger magic-link — builds m.me/{page_id}?ref=TOKEN deep-link.
	 *
	 * chat_id format expected: fb_{page_id}_{psid}  (or bare psid).
	 * Resolves page_id from DB when not embedded in chat_id.
	 *
	 * The resulting URL uses the m.me link format:
	 *   https://m.me/{page_id}?ref=BZ_{token_slug}
	 *
	 * [2026-06-13 Johnny Chu] PHASE-3.5-WD — FB Messenger magic-link
	 *
	 * @param string $chat_id
	 * @param array  $args
	 * @return array|WP_Error
	 */
	public function issue_magic_link( string $chat_id, array $args = array() ) {
		if ( ! class_exists( 'BizCity_CRM_Magic_Link' ) ) {
			return new WP_Error( 'bizcity_magic_link_unavailable', 'BizCity_CRM_Magic_Link class not loaded.' );
		}

		// Parse page_id + psid from composite chat_id.
		$stripped = preg_replace( '/^(fb_|messenger_)/', '', $chat_id );
		$page_id  = isset( $args['page_id'] ) ? (string) $args['page_id'] : '';

		if ( preg_match( '/^(\d+)_(\d+)$/', $stripped, $m ) ) {
			$page_id = $page_id ?: $m[1];
		}

		// Fallback: resolve page_id from first active bot when not embedded.
		if ( $page_id === '' && class_exists( 'BizCity_Facebook_Bot_Database' ) ) {
			$bots    = BizCity_Facebook_Bot_Database::instance()->get_active_bots();
			$page_id = ! empty( $bots[0] ) ? (string) $bots[0]->page_id : '';
		}

		$args['platform'] = 'FB_MESS';
		$args['chat_id']  = $chat_id;
		unset( $args['page_id'] );

		$result = BizCity_CRM_Magic_Link::issue( $args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Override url to m.me deep-link when page_id is available.
		if ( $page_id !== '' ) {
			$ref_param    = 'BZ_' . substr( $result['token'], 0, 60 );
			$messenger_url = 'https://m.me/' . rawurlencode( $page_id ) . '?ref=' . rawurlencode( $ref_param );
			$result['url']       = $messenger_url;
			$result['deep_link'] = 'fb-messenger://user-thread/' . rawurlencode( $page_id ) . '?ref=' . rawurlencode( $ref_param );
		}

		return $result;
	}
}

/**
 * Register adapter with the gateway bridge as soon as the bridge is ready.
 * Channel gateway fires `bizcity_register_channel` after its own bootstrap.
 */
add_action( 'bizcity_register_channel', function ( $bridge ) {
	if ( $bridge instanceof BizCity_Gateway_Bridge ) {
		$bridge->register_adapter( new BizCity_Facebook_Bot_Channel_Adapter() );
	}
}, 5 );

/**
 * Defensive: if `bizcity_register_channel` already fired before this file
 * loaded (mu-plugin order), register directly.
 */
if ( did_action( 'bizcity_register_channel' ) && class_exists( 'BizCity_Gateway_Bridge' ) ) {
	BizCity_Gateway_Bridge::instance()->register_adapter(
		new BizCity_Facebook_Bot_Channel_Adapter()
	);
}
