<?php
/**
 * Zalo Bot OA Channel Integration (PHASE 0.37 M4)
 *
 * Handles inbound webhooks from Zalo Official Account (OA) and outbound
 * replies via Zalo Open API v3.0 using access_token / refresh_token auth.
 *
 * One account = one Zalo OA (OA ID + access_token).
 * This is DISTINCT from BizCity_Zalo_Hotline_Adapter (which uses personal accounts).
 *
 * Webhook URL: /wp-json/bizcity-channel/v1/webhook/zalo_bot/{oa_id}
 *
 * Zalo OA API docs: https://developers.zalo.me/docs/api/official-account-api
 *
 * @package    BizCity_Twin_AI
 * @subpackage Channel_Gateway
 * @since      PHASE 0.37 M4
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Zalo_Bot_OA_Integration extends BizCity_Channel_Integration {

	protected string $code           = 'zalo_bot';
	protected string $platform       = 'ZALO_BOT';
	protected string $name           = 'Zalo Bot OA';
	protected string $desc           = 'Zalo Official Account — nhận & gửi tin qua Zalo Open API v3.0 (access_token).';
	protected string $logo           = 'zalo';
	protected string $default_role   = 'cskh';
	protected string $chat_id_prefix = 'zalobot_';
	protected int    $order          = 20;

	const ZALO_API_BASE     = 'https://openapi.zalo.me';
	const ZALO_SEND_TIMEOUT = 15;

	protected array $settings = [
		'oa_id' => [
			'type'     => 'text',
			'label'    => 'OA ID',
			'desc'     => 'Numeric Zalo Official Account ID.',
			'default'  => '',
			'required' => true,
		],
		'oa_name' => [
			'type'     => 'text',
			'label'    => 'OA Name',
			'desc'     => 'Tự điền sau khi test thành công.',
			'default'  => '',
			'readonly' => true,
		],
		'app_id' => [
			'type'    => 'text',
			'label'   => 'App ID',
			'desc'    => 'App ID trên Zalo Developers (dùng để làm mới access_token).',
			'default' => '',
		],
		'app_secret' => [
			'type'    => 'password',
			'label'   => 'App Secret',
			'desc'    => 'App Secret để verify chữ ký webhook (mac param).',
			'default' => '',
			'encrypt' => true,
		],
		'access_token' => [
			'type'     => 'password',
			'label'    => 'Access Token',
			'desc'     => 'Zalo OA Access Token (3600s). Sẽ tự làm mới nếu có refresh_token.',
			'default'  => '',
			'encrypt'  => true,
			'required' => true,
		],
		'refresh_token' => [
			'type'    => 'password',
			'label'   => 'Refresh Token',
			'desc'    => 'Zalo OA Refresh Token (90 ngày). Để trống nếu không dùng auto-refresh.',
			'default' => '',
			'encrypt' => true,
		],
		'token_expires_at' => [
			'type'     => 'text',
			'label'    => 'Token hết hạn lúc',
			'desc'     => 'ISO 8601 UTC. Tự cập nhật khi refresh thành công.',
			'default'  => '',
			'readonly' => true,
		],
		'verify_token' => [
			'type'    => 'text',
			'label'   => 'Verify Token (tuỳ chọn)',
			'desc'    => 'Chuỗi bí mật dùng để verify webhook subscription nếu Zalo hỗ trợ.',
			'default' => '',
		],
	];

	protected array $private_params = [ 'app_secret', 'refresh_token' ];
	protected array $signal_params  = [ 'oa_id', 'access_token' ];

	protected array $trigger_blocks = [ 'wu_zalobot_message_received' ];
	protected array $action_blocks  = [ 'wa_send_zalo_bot_message' ];

	/* ═══════════════════════════════════════════
	 *  Inbound — verify webhook
	 * ═══════════════════════════════════════════ */

	/**
	 * Verify Zalo OA webhook request.
	 *
	 * GET  → optional challenge response (verify_token match).
	 * POST → HMAC-SHA256 via `mac` query/body param if app_secret set.
	 *
	 * @param WP_REST_Request $request
	 * @return bool|WP_Error
	 */
	public function verify_webhook( WP_REST_Request $request ) {
		$method = $request->get_method();

		if ( $method === 'GET' ) {
			$challenge    = $request->get_param( 'challenge' );
			$token        = $request->get_param( 'verify_token' );
			$stored_token = $this->get_decrypted_param( 'verify_token' );

			if ( $stored_token !== '' && $token !== null ) {
				if ( ! hash_equals( $stored_token, (string) $token ) ) {
					return new WP_Error( 'zalobot_verify_failed', 'Verify token mismatch', [ 'status' => 403 ] );
				}
			}
			return true; // challenge echo handled by REST webhook handler
		}

		// POST: mac-based verification (Zalo appends ?mac= to webhook URL or body).
		$app_secret = $this->get_decrypted_param( 'app_secret' );
		if ( $app_secret === '' ) {
			return true; // no secret → skip signature check
		}

		$mac_param = $request->get_param( 'mac' );
		if ( ! $mac_param ) {
			// Some Zalo webhook versions omit mac; accept without check.
			return true;
		}

		$body     = $request->get_body();
		$expected = hash_hmac( 'sha256', $body, $app_secret );

		if ( ! hash_equals( $expected, (string) $mac_param ) ) {
			return new WP_Error( 'zalobot_mac_invalid', 'Webhook mac mismatch', [ 'status' => 403 ] );
		}

		return true;
	}

	/* ═══════════════════════════════════════════
	 *  Inbound — normalize Zalo OA payload
	 * ═══════════════════════════════════════════ */

	/**
	 * Normalize a Zalo OA webhook event into the canonical envelope.
	 *
	 * Zalo OA v3 event structure:
	 * {
	 *   "app_id": "...",
	 *   "event_name": "user_send_text",
	 *   "timestamp": "...",
	 *   "sender": { "id": "USER_ID" },
	 *   "recipient": { "id": "OA_ID" },
	 *   "message": { "msg_id": "...", "text": "..." }
	 * }
	 *
	 * @param WP_REST_Request $request
	 * @param array           $account Decrypted account credentials.
	 * @return array Canonical envelope or [] to skip.
	 */
	public function normalize_inbound( WP_REST_Request $request, array $account ): array {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return [];
		}

		$event_name  = (string) ( $body['event_name'] ?? '' );
		$sender_id   = (string) ( $body['sender']['id'] ?? '' );
		$oa_id       = (string) ( $body['recipient']['id'] ?? ( $account['oa_id'] ?? '' ) );
		$timestamp   = (int) ( $body['timestamp'] ?? time() );
		$message     = $body['message'] ?? [];

		if ( ! $sender_id ) {
			return [];
		}

		$text      = '';
		$type      = 'unknown';
		$media_url = '';
		$mid       = (string) ( $message['msg_id'] ?? '' );

		switch ( $event_name ) {
			case 'user_send_text':
				$text = (string) ( $message['text'] ?? '' );
				$type = 'text';
				break;
			case 'user_send_image':
				$type      = 'image';
				$media_url = (string) ( $message['attachments'][0]['payload']['url'] ?? '' );
				break;
			case 'user_send_file':
				$type      = 'file';
				$media_url = (string) ( $message['attachments'][0]['payload']['url'] ?? '' );
				break;
			case 'user_send_sticker':
				$type = 'sticker';
				break;
			case 'follow':
			case 'unfollow':
				$type = $event_name;
				break;
			default:
				$type = $event_name ?: 'unknown';
		}

		$chat_id = 'zalobot_' . $oa_id . '_' . $sender_id;

		return [
			'platform'    => 'ZALO_BOT',
			'instance_id' => $oa_id,
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
	 *  Outbound — Zalo OA Send API v3
	 * ═══════════════════════════════════════════ */

	/**
	 * Send a message via Zalo OA Send API.
	 *
	 * @param array $msg     Outbound envelope.
	 * @param array $account Decrypted account credentials.
	 * @return array|WP_Error
	 */
	public function send_outbound( array $msg, array $account ) {
		// Ping test.
		if ( ( $msg['type'] ?? '' ) === 'ping' || ( $msg['recipient'] ?? '' ) === '__ping__' ) {
			return $this->api_get_oa_info( $account );
		}

		$token     = $this->maybe_refresh_token( $account );
		$recipient = $msg['recipient'] ?? '';
		$text      = $msg['text'] ?? $msg['message'] ?? '';
		$type      = $msg['type'] ?? 'text';

		if ( ! $token ) {
			return new WP_Error( 'zalobot_no_token', 'access_token is not configured.' );
		}
		if ( ! $recipient ) {
			return new WP_Error( 'zalobot_no_recipient', 'recipient (user_id) is required.' );
		}

		$body = [
			'recipient' => [ 'user_id' => $recipient ],
			'message'   => $this->build_message_payload( $text, $type, $msg ),
		];

		$response = wp_remote_post(
			self::ZALO_API_BASE . '/v3.0/oa/message/cs',
			[
				'timeout'     => self::ZALO_SEND_TIMEOUT,
				'headers'     => [
					'Content-Type'  => 'application/json',
					'access_token'  => $token,
				],
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

		// Zalo error codes: 0 = success, negative = error.
		$zalo_error = (int) ( $decoded['error'] ?? -1 );
		$sent       = $code === 200 && $zalo_error === 0;

		if ( ! $sent ) {
			$err_msg = $decoded['message'] ?? "HTTP {$code} / Zalo error {$zalo_error}";
			return [
				'sent'     => false,
				'error'    => $err_msg,
				'platform' => 'ZALO_BOT',
				'mid'      => '',
			];
		}

		return [
			'sent'     => true,
			'error'    => '',
			'platform' => 'ZALO_BOT',
			'mid'      => (string) ( $decoded['data']['message_id'] ?? '' ),
		];
	}

	/* ═══════════════════════════════════════════
	 *  Connection test
	 * ═══════════════════════════════════════════ */

	/**
	 * Test: call GET /v2.0/oa/getoa to verify access_token.
	 */
	public function do_test(): void {
		$account = $this->get_decrypted_params( true );
		$result  = $this->api_get_oa_info( $account );

		if ( is_wp_error( $result ) ) {
			$this->account['_status']       = 7;
			$this->account['_status_error'] = $result->get_error_message();
			return;
		}

		$sent = ! empty( $result['sent'] );
		$this->account['_status']       = $sent ? 1 : 7;
		$this->account['_status_error'] = $sent ? '' : ( (string) ( $result['error'] ?? '' ) );
		if ( $sent && ! empty( $result['oa_name'] ) ) {
			$this->account['oa_name'] = $result['oa_name'];
		}
		if ( $sent ) {
			$this->account['_last_success_at'] = gmdate( 'Y-m-d H:i:s' );
		}
	}

	/* ═══════════════════════════════════════════
	 *  Helpers
	 * ═══════════════════════════════════════════ */

	/**
	 * GET /v2.0/oa/getoa — lightweight OA info + token validation.
	 */
	private function api_get_oa_info( array $account ): array {
		$token = $this->maybe_refresh_token( $account );
		if ( ! $token ) {
			return [ 'sent' => false, 'error' => 'access_token missing', 'platform' => 'ZALO_BOT', 'mid' => '' ];
		}

		$response = wp_remote_get(
			self::ZALO_API_BASE . '/v2.0/oa/getoa',
			[
				'timeout' => 10,
				'headers' => [ 'access_token' => $token ],
			]
		);

		if ( is_wp_error( $response ) ) {
			return [ 'sent' => false, 'error' => $response->get_error_message(), 'platform' => 'ZALO_BOT', 'mid' => '' ];
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		$zalo_error = (int) ( $decoded['error'] ?? -1 );
		if ( $code !== 200 || $zalo_error !== 0 ) {
			$err = $decoded['message'] ?? "HTTP {$code}";
			return [ 'sent' => false, 'error' => $err, 'platform' => 'ZALO_BOT', 'mid' => '' ];
		}

		return [
			'sent'     => true,
			'error'    => '',
			'platform' => 'ZALO_BOT',
			'mid'      => '',
			'oa_name'  => $decoded['data']['name'] ?? '',
			'oa_id'    => $decoded['data']['oa_id'] ?? '',
		];
	}

	/**
	 * Return the current access token, refreshing it if expired.
	 * Returns empty string if no token is available.
	 *
	 * Note: auto-refresh requires app_id + app_secret + refresh_token.
	 */
	private function maybe_refresh_token( array $account ): string {
		$token         = $account['access_token'] ?? '';
		$expires_at    = $account['token_expires_at'] ?? '';
		$refresh_token = $account['refresh_token'] ?? '';
		$app_id        = $account['app_id'] ?? '';
		$app_secret    = $account['app_secret'] ?? '';

		if ( ! $token ) {
			return '';
		}

		// If no expiry info or no refresh credentials, just return the token.
		if ( ! $expires_at || ! $refresh_token || ! $app_id || ! $app_secret ) {
			return $token;
		}

		// Check if expired (refresh 5 minutes before expiry).
		$expires_ts = strtotime( $expires_at );
		if ( $expires_ts && ( $expires_ts - 300 ) > time() ) {
			return $token; // still valid
		}

		// Attempt to refresh.
		$new_token = $this->api_refresh_token( $refresh_token, $app_id, $app_secret );
		if ( $new_token ) {
			// Update stored account (persisted by caller via do_test() or next save).
			$this->account['access_token']    = $new_token['access_token'];
			$this->account['token_expires_at'] = gmdate( 'Y-m-d H:i:s', time() + (int) ( $new_token['expires_in'] ?? 3600 ) );
			return $this->account['access_token'];
		}

		return $token; // fall back to possibly-expired token
	}

	/**
	 * Call Zalo OAuth to refresh the access token.
	 *
	 * @return array|null Array with 'access_token' and 'expires_in', or null on failure.
	 */
	private function api_refresh_token( string $refresh_token, string $app_id, string $app_secret ): ?array {
		$response = wp_remote_post(
			'https://oauth.zaloapp.com/v4/oa/access_token',
			[
				'timeout' => 10,
				'headers' => [
					'secret_key'   => $app_secret,
					'Content-Type' => 'application/x-www-form-urlencoded',
				],
				'body' => [
					'refresh_token' => $refresh_token,
					'app_id'        => $app_id,
					'grant_type'    => 'refresh_token',
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $decoded['access_token'] ) ) {
			return null;
		}

		return $decoded;
	}

	/**
	 * Build Zalo OA message payload.
	 */
	private function build_message_payload( string $text, string $type, array $msg ): array {
		if ( $type === 'image' && ! empty( $msg['media_url'] ) ) {
			return [
				'attachment' => [
					'type'    => 'template',
					'payload' => [
						'template_type' => 'media',
						'elements'      => [
							[ 'media_type' => 'image', 'url' => $msg['media_url'] ],
						],
					],
				],
			];
		}
		// Default: text message.
		return [ 'text' => $text ];
	}
}
