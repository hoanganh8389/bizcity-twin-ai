<?php
/**
 * Facebook Page / Messenger Channel Integration (PHASE 0.37 M4)
 *
 * Handles inbound Messenger webhooks (page-scoped) and outbound replies
 * via the Facebook Graph API (Send API v18.0+).
 *
 * One account = one Facebook Page (Page ID + Page Access Token).
 * The same integration handles both "Facebook Page" and "Facebook Messenger"
 * SPA workspace views — both share platform = 'FACEBOOK'.
 *
 * Webhook URL: /wp-json/bizcity-channel/v1/webhook/facebook/{page_id}
 *
 * @package    BizCity_Twin_AI
 * @subpackage Channel_Gateway
 * @since      PHASE 0.37 M4
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Facebook_Page_Integration extends BizCity_Channel_Integration {

	protected string $code           = 'facebook_page';
	protected string $platform       = 'FACEBOOK';
	protected string $name           = 'Facebook Page';
	protected string $desc           = 'Nhận & gửi tin nhắn từ Fanpage qua Messenger Platform (Graph API v18.0+).';
	protected string $logo           = 'facebook';
	protected string $default_role   = 'cskh';
	protected string $chat_id_prefix = 'fb_';
	protected int    $order          = 10;

	/**
	 * Field schema — OAuth-first, mirrors plugins/bizcity-facebook-bot
	 * "Phương án B — Tự cấu hình Developer App" UX.
	 *
	 * User inputs ONLY app-level credentials (App ID + App Secret + optional
	 * Verify Token + Privacy Policy URL). Page-level fields (page_id, page
	 * access token, page name) are auto-populated by the OAuth callback
	 * `?biz_fb_oauth=callback` after the user clicks "Kết nối Facebook" —
	 * users should NEVER paste a page access token by hand.
	 */
	protected array $settings = [
		'app_id' => [
			'type'     => 'text',
			'label'    => 'App ID',
			'desc'     => 'Facebook App ID lấy từ developers.facebook.com → App Settings → Basic.',
			'default'  => '',
			'required' => true,
		],
		'app_secret' => [
			'type'     => 'password',
			'label'    => 'App Secret',
			'desc'     => 'Dùng để (1) đổi user-token → page-token trong OAuth, (2) verify chữ ký X-Hub-Signature-256 trên webhook.',
			'default'  => '',
			'encrypt'  => true,
			'required' => true,
		],
		'privacy_policy_url' => [
			'type'    => 'url',
			'label'   => 'Privacy Policy URL',
			'desc'    => 'Bắt buộc trong App Settings → Basic của Meta. Vd: https://yoursite.vn/chinh-sach-bao-mat-quyen-rieng-tu/',
			'default' => '',
		],
		'verify_token' => [
			'type'    => 'text',
			'label'   => 'Verify Token',
			'desc'    => 'Chuỗi bí mật do bạn tự đặt, nhập trùng vào ô "Verify Token" khi đăng ký Webhook trên Meta. Mặc định: bizgpt.',
			'default' => 'bizgpt',
		],
		'page_id' => [
			'type'     => 'text',
			'label'    => 'Page ID',
			'desc'     => 'Tự điền sau khi bấm "Kết nối Facebook" và chọn Fanpage qua OAuth.',
			'default'  => '',
			'readonly' => true,
		],
		'page_name' => [
			'type'     => 'text',
			'label'    => 'Page Name',
			'desc'     => 'Tự điền sau OAuth.',
			'default'  => '',
			'readonly' => true,
		],
		'page_access_token' => [
			'type'     => 'password',
			'label'    => 'Page Access Token',
			'desc'     => 'Tự điền sau OAuth (long-lived page token). KHÔNG cần & KHÔNG nên paste tay.',
			'default'  => '',
			'encrypt'  => true,
			'readonly' => true,
		],
	];

	protected array $private_params = [ 'app_secret' ];
	/**
	 * Changing any of these requires re-running OAuth (the page-token issued
	 * for the previous app/page becomes invalid). page_id/page_access_token
	 * are written by OAuth callback only.
	 */
	protected array $signal_params  = [ 'app_id', 'page_id' ];

	protected array $trigger_blocks = [ 'wu_facebook_message_received' ];
	protected array $action_blocks  = [ 'wa_send_facebook_message' ];

	/* ═══════════════════════════════════════════
	 *  Inbound — verify webhook signature
	 * ═══════════════════════════════════════════ */

	/**
	 * Verify inbound webhook.
	 *
	 * GET  → hub.challenge echo after verify_token match.
	 * POST → X-Hub-Signature-256 HMAC-SHA256 check (requires app_secret).
	 *
	 * @param WP_REST_Request $request
	 * @return bool|WP_Error
	 */
	public function verify_webhook( WP_REST_Request $request ) {
		$method = $request->get_method();

		if ( $method === 'GET' ) {
			// Facebook webhook subscription verification.
			$mode         = $request->get_param( 'hub_mode' ) ?? $request->get_param( 'hub.mode' );
			$token        = $request->get_param( 'hub_verify_token' ) ?? $request->get_param( 'hub.verify_token' );
			$stored_token = $this->get_decrypted_param( 'verify_token' );

			if ( $mode === 'subscribe' && $stored_token !== '' && hash_equals( $stored_token, (string) $token ) ) {
				return true;
			}
			if ( $stored_token === '' ) {
				// No verify_token configured — accept but warn.
				return true;
			}
			return new WP_Error( 'fb_verify_failed', 'Verify token mismatch', [ 'status' => 403 ] );
		}

		// POST: verify X-Hub-Signature-256 if app_secret is set.
		$app_secret = $this->get_decrypted_param( 'app_secret' );
		if ( $app_secret === '' ) {
			// No secret configured — accept without signature check.
			return true;
		}

		$sig_header = $request->get_header( 'X-Hub-Signature-256' );
		if ( ! $sig_header ) {
			return new WP_Error( 'fb_sig_missing', 'Missing X-Hub-Signature-256', [ 'status' => 403 ] );
		}

		$body     = $request->get_body();
		$expected = 'sha256=' . hash_hmac( 'sha256', $body, $app_secret );

		if ( ! hash_equals( $expected, $sig_header ) ) {
			return new WP_Error( 'fb_sig_invalid', 'X-Hub-Signature-256 mismatch', [ 'status' => 403 ] );
		}

		return true;
	}

	/* ═══════════════════════════════════════════
	 *  Inbound — normalize Messenger payload
	 * ═══════════════════════════════════════════ */

	/**
	 * Normalize a Facebook Messenger webhook event into the canonical envelope.
	 *
	 * FB sends batched entries; each entry may have multiple messaging events.
	 * We return only the FIRST message event (subsequent events are handled by
	 * the Universal Listener calling normalize_inbound() again per event — or
	 * the caller iterates entries itself).
	 *
	 * @param WP_REST_Request $request
	 * @param array           $account  Decrypted account credentials.
	 * @return array Canonical envelope or [] to skip.
	 */
	public function normalize_inbound( WP_REST_Request $request, array $account ): array {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) || ( $body['object'] ?? '' ) !== 'page' ) {
			return [];
		}

		$entry     = $body['entry'][0] ?? null;
		$messaging = $entry['messaging'][0] ?? null;

		if ( ! $messaging ) {
			return [];
		}

		$sender_id   = (string) ( $messaging['sender']['id'] ?? '' );
		$page_id     = (string) ( $entry['id'] ?? '' );
		$timestamp   = (int)    ( $messaging['timestamp'] ?? time() );
		$msg         = $messaging['message'] ?? [];
		$postback    = $messaging['postback'] ?? [];

		$text     = '';
		$type     = 'unknown';
		$mid      = '';
		$media_url = '';

		if ( ! empty( $msg ) ) {
			$mid  = (string) ( $msg['mid'] ?? '' );
			$text = (string) ( $msg['text'] ?? '' );

			if ( $text !== '' ) {
				$type = 'text';
			} elseif ( ! empty( $msg['attachments'] ) ) {
				$att       = $msg['attachments'][0];
				$att_type  = $att['type'] ?? '';
				$type      = in_array( $att_type, [ 'image', 'video', 'audio', 'file' ], true ) ? $att_type : 'file';
				$media_url = (string) ( $att['payload']['url'] ?? '' );
			}
		} elseif ( ! empty( $postback ) ) {
			$type = 'postback';
			$text = (string) ( $postback['title'] ?? $postback['payload'] ?? '' );
		}

		if ( ! $sender_id ) {
			return [];
		}

		$instance_id = $page_id ?: ( $account['page_id'] ?? '' );
		$chat_id     = 'fb_' . $instance_id . '_' . $sender_id;

		return [
			'platform'    => 'FACEBOOK',
			'instance_id' => $instance_id,
			'chat_id'     => $chat_id,
			'sender_id'   => $sender_id,
			'text'        => $text,
			'type'        => $type,
			'media_url'   => $media_url,
			'mid'         => $mid,
			'raw'         => $body,
			'timestamp'   => $timestamp,
		];
	}

	/* ═══════════════════════════════════════════
	 *  Outbound — Facebook Send API
	 * ═══════════════════════════════════════════ */

	/**
	 * Send a message to a Facebook Messenger user.
	 *
	 * @param array $msg     Outbound envelope.
	 * @param array $account Decrypted account credentials.
	 * @return array|WP_Error
	 */
	public function send_outbound( array $msg, array $account ) {
		// Ping test — verify token works via /me endpoint.
		if ( ( $msg['type'] ?? '' ) === 'ping' || ( $msg['recipient'] ?? '' ) === '__ping__' ) {
			return $this->api_get_me( $account );
		}

		$token     = $account['page_access_token'] ?? '';
		$recipient = $msg['recipient'] ?? '';
		$text      = $msg['text'] ?? $msg['message'] ?? '';
		$type      = $msg['type'] ?? 'text';

		if ( ! $token ) {
			return new WP_Error( 'fb_no_token', 'page_access_token is not configured.' );
		}
		if ( ! $recipient ) {
			return new WP_Error( 'fb_no_recipient', 'recipient (PSID) is required.' );
		}

		$body = [
			'recipient' => [ 'id' => $recipient ],
			'message'   => $this->build_message_payload( $text, $type, $msg ),
		];

		$response = wp_remote_post(
			'https://graph.facebook.com/v18.0/me/messages?access_token=' . rawurlencode( $token ),
			[
				'timeout'     => 15,
				'headers'     => [ 'Content-Type' => 'application/json' ],
				'body'        => wp_json_encode( $body ),
				'data_format' => 'body',
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$raw     = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $raw, true );
		$sent    = $code === 200 && isset( $decoded['message_id'] );

		if ( ! $sent ) {
			$fb_error = $decoded['error']['message'] ?? "HTTP {$code}";
			return [
				'sent'     => false,
				'error'    => $fb_error,
				'platform' => 'FACEBOOK',
				'mid'      => '',
			];
		}

		return [
			'sent'     => true,
			'error'    => '',
			'platform' => 'FACEBOOK',
			'mid'      => (string) ( $decoded['message_id'] ?? '' ),
		];
	}

	/* ═══════════════════════════════════════════
	 *  Connection test
	 * ═══════════════════════════════════════════ */

	/**
	 * Test: call GET /me?fields=id,name to verify access token is valid.
	 */
	public function do_test(): void {
		$account = $this->get_decrypted_params( true );
		$result  = $this->api_get_me( $account );

		if ( is_wp_error( $result ) ) {
			$this->account['_status']       = 7;
			$this->account['_status_error'] = $result->get_error_message();
			return;
		}

		$sent = ! empty( $result['sent'] );
		$this->account['_status']       = $sent ? 1 : 7;
		$this->account['_status_error'] = $sent ? '' : ( (string) ( $result['error'] ?? '' ) );
		if ( $sent && ! empty( $result['name'] ) ) {
			$this->account['page_name'] = $result['name'];
		}
		if ( $sent ) {
			$this->account['_last_success_at'] = gmdate( 'Y-m-d H:i:s' );
		}
	}

	/* ═══════════════════════════════════════════
	 *  Helpers
	 * ═══════════════════════════════════════════ */

	/**
	 * GET /me?fields=id,name — lightweight token validation.
	 */
	private function api_get_me( array $account ): array {
		$token = $account['page_access_token'] ?? '';
		if ( ! $token ) {
			return [ 'sent' => false, 'error' => 'page_access_token missing', 'platform' => 'FACEBOOK', 'mid' => '' ];
		}

		$response = wp_remote_get(
			'https://graph.facebook.com/v18.0/me?fields=id,name&access_token=' . rawurlencode( $token ),
			[ 'timeout' => 10 ]
		);

		if ( is_wp_error( $response ) ) {
			return [ 'sent' => false, 'error' => $response->get_error_message(), 'platform' => 'FACEBOOK', 'mid' => '' ];
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code !== 200 || empty( $decoded['id'] ) ) {
			$err = $decoded['error']['message'] ?? "HTTP {$code}";
			return [ 'sent' => false, 'error' => $err, 'platform' => 'FACEBOOK', 'mid' => '' ];
		}

		return [
			'sent'     => true,
			'error'    => '',
			'platform' => 'FACEBOOK',
			'mid'      => '',
			'id'       => $decoded['id'],
			'name'     => $decoded['name'] ?? '',
		];
	}

	/**
	 * Build a Messenger message payload from type + text.
	 */
	private function build_message_payload( string $text, string $type, array $msg ): array {
		if ( $type === 'image' && ! empty( $msg['media_url'] ) ) {
			return [
				'attachment' => [
					'type'    => 'image',
					'payload' => [ 'url' => $msg['media_url'], 'is_reusable' => true ],
				],
			];
		}
		// Default: text message.
		return [ 'text' => $text ];
	}
}
