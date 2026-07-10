<?php
/**
 * Zalo OA — Direct PHP Integration (no Node bridge)
 *
 * Zone 1 — Kênh khách hàng (Customer Care).
 * Kết nối Zalo Official Account qua OAuth v4 + webhook MAC thuần PHP.
 * KHÔNG yêu cầu zca-bridge sidecar.
 *
 * Webhook URL: /wp-json/bizcity-channel/v1/webhook/zalo_oa/{account_uid}
 * OAuth callback: /wp-json/bizcity-channel/v1/zalo-oa/oauth/callback
 *
 * Auth flow:
 *   1. Admin enters app_id + app_secret + oa_id.
 *   2. Click "Cấp quyền OA" → GET /zalo-oa/oauth/connect-url → redirect to Zalo.
 *   3. Zalo redirects back → /zalo-oa/oauth/callback?code=...&state=...
 *   4. Callback exchanges code → access_token + refresh_token, saves to account.
 *   5. Token auto-refresh 5 min before expiry on every outbound call.
 *
 * Outbound: POST https://openapi.zalo.me/v3.0/oa/message/cs
 *   - Header: access_token: {token}
 *   - Cửa sổ CSKH 7 ngày — Zalo code -230 mapped to R-ERROR-UX.
 *
 * [2026-06-19 Johnny Chu] PHASE-0.39 — Zalo OA direct PHP integration (no bridge).
 *
 * @package    BizCity_Twin_AI
 * @subpackage Channel_Gateway
 */

defined( 'ABSPATH' ) || exit;

// [2026-06-19 Johnny Chu] PHASE-0.39 — Renamed to BizCity_CG_Zalo_OA_Integration to avoid
// collision with bizcity-zalo-personal's BizCity_Zalo_OA_Integration class.
if ( class_exists( 'BizCity_CG_Zalo_OA_Integration' ) ) {
	return;
}

class BizCity_CG_Zalo_OA_Integration extends BizCity_Channel_Integration {

	// [2026-06-19 Johnny Chu] PHASE-0.39 — Zone 1 code/platform (distinct from zalo_bot Zone 2).
	protected string $code           = 'zalo_oa';
	protected string $platform       = 'ZALO_OA';
	protected string $name           = 'Zalo OA (Direct)';
	protected string $desc           = 'Zalo Official Account — OAuth v4, webhook MAC, thuần PHP, không cần bridge.';
	protected string $logo           = 'zalo';
	protected string $default_role   = 'cskh';
	protected string $chat_id_prefix = 'zalooa_';
	protected int    $order          = 21;

	const ZALO_API_BASE     = 'https://openapi.zalo.me';
	const ZALO_OAUTH_BASE   = 'https://oauth.zaloapp.com';
	const ZALO_SEND_TIMEOUT = 15;

	protected array $settings = array(
		'oa_id'            => array(
			'type'     => 'text',
			'label'    => 'OA ID',
			'desc'     => 'Numeric Zalo Official Account ID (lấy từ Zalo OA Manager).',
			'default'  => '',
			'required' => true,
		),
		'oa_name'          => array(
			'type'     => 'text',
			'label'    => 'Tên OA',
			'desc'     => 'Tự điền sau khi kết nối thành công.',
			'default'  => '',
			'readonly' => true,
		),
		'app_id'           => array(
			'type'     => 'text',
			'label'    => 'App ID',
			'desc'     => 'App ID trên Zalo Developers Console.',
			'default'  => '',
			'required' => true,
		),
		'app_secret'       => array(
			'type'    => 'password',
			'label'   => 'App Secret',
			'desc'    => 'App Secret (dùng để verify webhook MAC và refresh token).',
			'default' => '',
			'encrypt' => true,
		),
		'access_token'     => array(
			'type'     => 'password',
			'label'    => 'Access Token',
			'desc'     => 'Tự cập nhật sau OAuth. Để trống nếu chưa cấp quyền.',
			'default'  => '',
			'encrypt'  => true,
			'readonly' => true,
		),
		'refresh_token'    => array(
			'type'     => 'password',
			'label'    => 'Refresh Token',
			'desc'     => 'Tự cập nhật sau OAuth. Hết hạn sau 90 ngày.',
			'default'  => '',
			'encrypt'  => true,
			'readonly' => true,
		),
		'token_expires_at' => array(
			'type'     => 'text',
			'label'    => 'Token hết hạn lúc',
			'desc'     => 'UTC ISO 8601. Tự cập nhật sau refresh.',
			'default'  => '',
			'readonly' => true,
		),
		'verify_token'     => array(
			'type'    => 'text',
			'label'   => 'Verify Token (tuỳ chọn)',
			'desc'    => 'Chuỗi bí mật verify webhook subscription GET challenge.',
			'default' => '',
		),
	);

	protected array $private_params = array( 'app_secret', 'refresh_token' );
	protected array $signal_params  = array( 'oa_id', 'access_token' );

	/* ═══════════════════════════════════════════
	 *  Inbound — verify webhook
	 * ═══════════════════════════════════════════ */

	/**
	 * Verify Zalo OA webhook.
	 * GET → challenge; POST → HMAC-SHA256 mac param.
	 *
	 * @param WP_REST_Request $request
	 * @return bool|WP_Error
	 */
	public function verify_webhook( WP_REST_Request $request ) {
		$method = $request->get_method();

		if ( $method === 'GET' ) {
			$token        = $request->get_param( 'verify_token' );
			$stored_token = $this->get_decrypted_param( 'verify_token' );
			if ( $stored_token !== '' && $token !== null ) {
				if ( ! hash_equals( $stored_token, (string) $token ) ) {
					return new WP_Error( 'zalooa_verify_failed', 'Verify token mismatch.', array( 'status' => 403 ) );
				}
			}
			return true;
		}

		// POST: mac-based verification.
		// [2026-06-20 Johnny Chu] PHASE-0.39 — Guard: no account loaded = skip all verification.
		// This happens when the singleton integration (stored in bridge/registry without account)
		// is called directly. Always pass through so the caller can load the real account first.
		$_acct_snapshot = $this->get_account();
		// error_log( '[bizcity-zalooa] TRACE verify_webhook POST account_empty=' . ( empty( $_acct_snapshot ) ? 'yes' : 'no' ) );
		if ( empty( $_acct_snapshot ) ) {
			// error_log( '[bizcity-zalooa] TRACE skip verify: no account loaded' );
			return true;
		}

		$app_secret = $this->get_decrypted_param( 'app_secret' );
		// error_log( '[bizcity-zalooa] TRACE app_secret empty=' . ( $app_secret === '' ? 'yes' : 'no' ) );
		if ( $app_secret === '' ) {
			return true; // no secret → skip
		}

		$mac_param = $request->get_param( 'mac' );
		// error_log( '[bizcity-zalooa] TRACE mac_param=' . ( $mac_param ? 'present' : 'absent' ) );
		if ( ! $mac_param ) {
			return true; // some versions omit mac
		}

		$body     = $request->get_body();
		$expected = hash_hmac( 'sha256', $body, $app_secret );
		// error_log( '[bizcity-zalooa] TRACE mac expected=' . substr( $expected, 0, 16 ) . '... provided=' . substr( (string) $mac_param, 0, 16 ) . '...' );

		if ( ! hash_equals( $expected, (string) $mac_param ) ) {
			// error_log( '[bizcity-zalooa] TRACE mac MISMATCH → 403' );
			return new WP_Error( 'zalooa_mac_invalid', 'Webhook mac mismatch.', array( 'status' => 403 ) );
		}

		return true;
	}

	/* ═══════════════════════════════════════════
	 *  Inbound — normalize Zalo OA payload
	 * ═══════════════════════════════════════════ */

	/**
	 * Normalize Zalo OA webhook event (Zone 1 — CRM care).
	 *
	 * @param WP_REST_Request $request
	 * @param array           $account
	 * @return array Canonical envelope or [] to skip.
	 */
	public function normalize_inbound( WP_REST_Request $request, array $account ): array {
		// [2026-06-21 Johnny Chu] PHASE-0.39 GURU-BIND — get_json_params() returns null when
		// WP_REST_Request fails to parse Content-Type. Fallback to get_params() which always
		// has the merged data (proven by JSONL 'params' field containing full sender/recipient).
		$body = $request->get_json_params();
		if ( ! is_array( $body ) || empty( $body ) ) {
			$body = $request->get_params();
		}
		error_log( '[bizcity-cg-trace] P0b body_source=' . ( is_array( $request->get_json_params() ) ? 'json' : 'params' ) . ' event=' . ( $body['event_name'] ?? '?' ) . ' sender_id=' . ( $body['sender']['id'] ?? 'missing' ) );
		if ( ! is_array( $body ) ) {
			return array();
		}

		$event_name = (string) ( $body['event_name'] ?? '' );
		$sender_id  = (string) ( $body['sender']['id'] ?? '' );
		$oa_id      = (string) ( $body['recipient']['id'] ?? ( $account['oa_id'] ?? '' ) );
		$timestamp  = (int) ( $body['timestamp'] ?? time() );
		$message    = $body['message'] ?? array();

		// [2026-06-21 Johnny Chu] PHASE-0.39 GURU-BIND — P0c: trace key fields.
		error_log( '[bizcity-cg-trace] P0c event=' . $event_name . ' sender_id=' . $sender_id . ' oa_id=' . $oa_id );

		// [2026-06-21 Johnny Chu] PHASE-0.39 GURU-BIND — Skip oa_* events (OA outbound echo).
		// Zalo sends oa_send_text/oa_send_image/oa_send_file when OA sends a message OUT.
		// sender_id in these events = OA's own ID → must NOT be processed as inbound.
		if ( strpos( $event_name, 'oa_' ) === 0 ) {
			error_log( '[bizcity-cg-trace] P0-SKIP oa_echo event=' . $event_name . ' sender=' . $sender_id );
			return array();
		}

		if ( ! $sender_id ) {
			return array();
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
				// On follow — queue contact sync.
				if ( $event_name === 'follow' && class_exists( 'BizCity_Zalo_OA_Contact_Sync' ) ) {
					BizCity_Zalo_OA_Contact_Sync::schedule_sync( $sender_id, $oa_id );
				}
				break;
			default:
				$type = $event_name ?: 'unknown';
		}

		// [2026-06-19 Johnny Chu] PHASE-0.39 — Zone 1 prefix: zalooa_ (R-ZP-1).
		$chat_id = 'zalooa_' . $oa_id . '_' . $sender_id;

		return array(
			'platform'    => 'ZALO_OA',     // Zone 1 discriminator (R-ZP-4.1)
			'code'        => 'zalo_oa',
			'instance_id' => $oa_id,
			'chat_id'     => $chat_id,
			'sender_id'   => $sender_id,
			'text'        => $text,
			'type'        => $type,
			'media_url'   => $media_url,
			'mid'         => $mid,
			'raw'         => $body,
			'timestamp'   => $timestamp,
		);
	}

	/* ═══════════════════════════════════════════
	 *  Outbound — Zalo OA Send API v3
	 * ═══════════════════════════════════════════ */

	/**
	 * Send message via Zalo OA Send API.
	 *
	 * @param array $msg
	 * @param array $account
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
			return new WP_Error( 'zalooa_no_token', 'access_token chưa được cấu hình. Hãy cấp quyền OA trước.' );
		}
		if ( ! $recipient ) {
			return new WP_Error( 'zalooa_no_recipient', 'recipient (user_id) là bắt buộc.' );
		}

		$body_payload = array(
			'recipient' => array( 'user_id' => $recipient ),
			'message'   => $this->build_message_payload( $text, $type, $msg ),
		);

		$response = wp_remote_post(
			self::ZALO_API_BASE . '/v3.0/oa/message/cs',
			array(
				'timeout'     => self::ZALO_SEND_TIMEOUT,
				'headers'     => array(
					'Content-Type' => 'application/json',
					'access_token' => $token,
				),
				'body'        => wp_json_encode( $body_payload ),
				'data_format' => 'body',
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$raw     = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $raw, true );

		$zalo_error = (int) ( $decoded['error'] ?? -1 );
		$sent       = $code === 200 && $zalo_error === 0;

		if ( ! $sent ) {
			$err_ux = self::map_zalo_error( $zalo_error );
			return array(
				'sent'      => false,
				'error'     => $decoded['message'] ?? "HTTP {$code}",
				'platform'  => 'ZALO_OA',
				'mid'       => '',
				'code'      => $err_ux['code'],
				'message'   => $err_ux['message'],
				'hint'      => $err_ux['hint'],
				'help_code' => $err_ux['help_code'],
			);
		}

		return array(
			'sent'     => true,
			'error'    => '',
			'platform' => 'ZALO_OA',
			'mid'      => (string) ( $decoded['data']['message_id'] ?? '' ),
		);
	}

	/* ═══════════════════════════════════════════
	 *  Connection test
	 * ═══════════════════════════════════════════ */

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
	 *  User Profile API
	 * ═══════════════════════════════════════════ */

	/**
	 * Fetch Zalo user profile.
	 *
	 * @param string $user_id
	 * @param array  $account
	 * @return array|null
	 */
	public function api_get_user_profile( string $user_id, array $account ) {
		$token = $this->maybe_refresh_token( $account );
		if ( ! $token || ! $user_id ) {
			return null;
		}

		$url  = add_query_arg( array( 'user_id' => $user_id ), self::ZALO_API_BASE . '/v3.0/oa/user/detail' );
		$resp = wp_remote_get(
			$url,
			array(
				'timeout' => 8,
				'headers' => array( 'access_token' => $token ),
			)
		);

		if ( is_wp_error( $resp ) ) {
			return null;
		}

		$decoded = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( ! is_array( $decoded ) || (int) ( $decoded['error'] ?? -1 ) !== 0 ) {
			return null;
		}

		return is_array( $decoded['data'] ?? null ) ? $decoded['data'] : null;
	}

	/* ═══════════════════════════════════════════
	 *  Helpers
	 * ═══════════════════════════════════════════ */

	/**
	 * GET /v2.0/oa/getoa — lightweight OA info + token validation.
	 *
	 * @param array $account
	 * @return array
	 */
	private function api_get_oa_info( array $account ): array {
		$token = $this->maybe_refresh_token( $account );
		if ( ! $token ) {
			return array( 'sent' => false, 'error' => 'access_token missing — hãy cấp quyền OA.', 'platform' => 'ZALO_OA', 'mid' => '' );
		}

		$response = wp_remote_get(
			self::ZALO_API_BASE . '/v2.0/oa/getoa',
			array(
				'timeout' => 10,
				'headers' => array( 'access_token' => $token ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'sent' => false, 'error' => $response->get_error_message(), 'platform' => 'ZALO_OA', 'mid' => '' );
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		$zalo_error = (int) ( $decoded['error'] ?? -1 );
		if ( $code !== 200 || $zalo_error !== 0 ) {
			return array( 'sent' => false, 'error' => $decoded['message'] ?? "HTTP {$code}", 'platform' => 'ZALO_OA', 'mid' => '' );
		}

		return array(
			'sent'     => true,
			'error'    => '',
			'platform' => 'ZALO_OA',
			'mid'      => '',
			'oa_name'  => $decoded['data']['name'] ?? '',
			'oa_id'    => $decoded['data']['oa_id'] ?? '',
		);
	}

	/**
	 * Return valid access token, refresh if needed.
	 *
	 * @param array $account
	 * @return string Empty string if unavailable.
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

		if ( ! $expires_at || ! $refresh_token || ! $app_id || ! $app_secret ) {
			return $token;
		}

		$expires_ts = strtotime( $expires_at );
		if ( $expires_ts && ( $expires_ts - 300 ) > time() ) {
			return $token;
		}

		$new_token = $this->api_refresh_token( $refresh_token, $app_id, $app_secret );
		if ( $new_token ) {
			$this->account['access_token']     = $new_token['access_token'];
			$this->account['token_expires_at'] = gmdate( 'Y-m-d H:i:s', time() + (int) ( $new_token['expires_in'] ?? 3600 ) );
			$_uid = isset( $this->account['_uid'] ) ? (string) $this->account['_uid'] : '';
			if ( $_uid !== '' && class_exists( 'BizCity_Integration_Registry' ) ) {
				BizCity_Integration_Registry::instance()->update_channel_account_status(
					$this->code,
					$_uid,
					$this->get_encrypted_params()
				);
			}
			return $this->account['access_token'];
		}

		return $token;
	}

	/**
	 * POST https://oauth.zaloapp.com/v4/oa/access_token — refresh flow.
	 *
	 * @param string $refresh_token
	 * @param string $app_id
	 * @param string $app_secret
	 * @return array|null
	 */
	private function api_refresh_token( string $refresh_token, string $app_id, string $app_secret ) {
		$response = wp_remote_post(
			self::ZALO_OAUTH_BASE . '/v4/oa/access_token',
			array(
				'timeout' => 10,
				'headers' => array(
					'secret_key'   => $app_secret,
					'Content-Type' => 'application/x-www-form-urlencoded',
				),
				'body'    => array(
					'refresh_token' => $refresh_token,
					'app_id'        => $app_id,
					'grant_type'    => 'refresh_token',
				),
			)
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
	 *
	 * @param string $text
	 * @param string $type
	 * @param array  $msg
	 * @return array
	 */
	private function build_message_payload( string $text, string $type, array $msg ): array {
		if ( $type === 'image' && ! empty( $msg['media_url'] ) ) {
			return array(
				'attachment' => array(
					'type'    => 'template',
					'payload' => array(
						'template_type' => 'media',
						'elements'      => array(
							array( 'media_type' => 'image', 'url' => $msg['media_url'] ),
						),
					),
				),
			);
		}
		return array( 'text' => $text );
	}

	/**
	 * Map Zalo API error code to R-ERROR-UX payload.
	 *
	 * @param int $zalo_code
	 * @return array
	 */
	private static function map_zalo_error( int $zalo_code ): array {
		$map = array(
			-201 => array(
				'code'      => 'page_not_connected',
				'message'   => 'Người dùng chưa quan tâm OA hoặc đã hủy theo dõi.',
				'hint'      => 'Yêu cầu người dùng quan tâm Zalo OA trước khi gửi tin nhắn.',
				'help_code' => 'zalo_no_follower',
			),
			-216 => array(
				'code'      => 'token_invalid',
				'message'   => 'Access Token Zalo OA hết hạn hoặc không hợp lệ.',
				'hint'      => 'Vào Cài đặt → Zalo OA → Cấp quyền lại để lấy token mới.',
				'help_code' => 'zalo_bad_token',
			),
			-218 => array(
				'code'      => 'quota_exceeded',
				'message'   => 'Đã hết hạn mức gửi tin Zalo OA hôm nay.',
				'hint'      => 'Kiểm tra quota hàng ngày trên Zalo Developers Console.',
				'help_code' => 'zalo_quota_exceeded',
			),
			-230 => array(
				'code'      => 'token_scope_missing',
				'message'   => 'Quá 7 ngày kể từ tin cuối của khách. Cửa sổ CSKH đã đóng.',
				'hint'      => 'Chờ khách nhắn lại hoặc dùng ZNS/template để mở cửa sổ CSKH.',
				'help_code' => 'zalo_oa_window_closed',
			),
		);

		return isset( $map[ $zalo_code ] ) ? $map[ $zalo_code ] : array(
			'code'      => 'gateway_degraded',
			'message'   => 'Gửi tin Zalo OA thất bại.',
			'hint'      => 'Kiểm tra access_token và trạng thái kết nối OA.',
			'help_code' => 'zalo_send_error',
		);
	}
}
