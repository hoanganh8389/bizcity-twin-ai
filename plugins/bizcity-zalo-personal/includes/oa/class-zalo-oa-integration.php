<?php
/**
 * BizCity Zalo OA Channel Integration (PHASE-0.39)
 *
 * Handles Zalo Official Account — OAuth v4 + webhook MAC + token refresh.
 * - One account = one Zalo OA (oa_id + oauth flow via sidecar).
 * - chat_id prefix: zalooa_{oa_id}_{user_id}
 * - Inbound: sidecar POST → /bizcity-channel/v1/zalo-bridge/inbound (Bearer verify).
 * - Outbound: bridge client → sidecar job_queue → OA Open API v3.0.
 *
 * R-ZP-5: check OA CSKH 7-day window before outbound (code -230 prevention).
 * R-ZP-1: channel_type stays 'zalo' (CRM Adapter reused).
 * R-ZP-2: OAuth tokens live on sidecar only.
 *
 * @package BizCity_Zalo_Personal
 * @since   1.0.0
 */

// [2026-06-07 Johnny Chu] PHASE-0.39 — Zalo OA channel integration
defined( 'ABSPATH' ) || exit;

class BizCity_Zalo_OA_Integration extends BizCity_Channel_Integration {

	// [2026-06-11 Johnny Chu] PHP74-COMPAT — add types to match parent BizCity_Integration typed props (untyped redecl = PHP fatal).
	protected string $code           = 'zalo_oa';
	protected string $platform       = 'ZALO_OA';
	protected string $name           = 'Zalo OA (OAuth)';
	protected string $desc           = 'Official Account — OAuth v4 + webhook MAC + auto-refresh token + cửa sổ CSKH 7 ngày.';
	protected string $logo           = 'zalo';
	protected string $default_role   = 'cskh';
	protected string $chat_id_prefix = 'zalooa_';
	protected int    $order          = 22;

	protected array $settings = array(
		'label'             => array(
			'type'    => 'text',
			'label'   => 'Tên OA hiển thị',
			'desc'    => 'Nhãn dễ nhớ cho OA này.',
			'default' => '',
		),
		'bridge_account_id' => array(
			'type'     => 'text',
			'label'    => 'Bridge Account ID',
			'desc'     => 'ID account OA trên zca-bridge.',
			'default'  => '',
			'required' => true,
		),
		'oa_id'             => array(
			'type'     => 'text',
			'label'    => 'Zalo OA ID',
			'desc'     => 'Tự điền sau khi cấp quyền OAuth thành công.',
			'default'  => '',
			'readonly' => true,
		),
		'status'            => array(
			'type'     => 'text',
			'label'    => 'Trạng thái',
			'desc'     => 'pending_qr | connected | expired',
			'default'  => 'pending_qr',
			'readonly' => true,
		),
	);

	// ── Webhook verification ──────────────────────────────────────────────

	/**
	 * Verify Bearer token from bridge (same mechanism as Personal).
	 *
	 * @param WP_REST_Request $request
	 * @return bool
	 */
	public function verify_webhook( WP_REST_Request $request ) {
		$stored_token = (string) get_option( BizCity_Zalo_Bridge_Client::OPTION_TOKEN, '' );
		// [2026-06-20 Johnny Chu] PHASE-0.39 — No bridge token configured means this OA
		// uses direct OAuth (no zca-bridge sidecar). Skip Bearer check so Zalo's direct
		// webhook POST (which has no Authorization header) is accepted.
		if ( $stored_token === '' ) {
			return true;
		}
		$header = (string) $request->get_header( 'authorization' );
		$bearer = '';
		if ( strpos( $header, 'Bearer ' ) === 0 ) {
			$bearer = substr( $header, 7 );
		}
		return hash_equals( $stored_token, $bearer );
	}

	// ── Inbound normalization ─────────────────────────────────────────────

	/**
	 * Normalize OA inbound from bridge.
	 * Bridge body shape same as Personal but kind='oa', conversation_id=oa_id.
	 *
	 * @param WP_REST_Request $request
	 * @param array           $account
	 * @return array
	 */
	public function normalize_inbound( WP_REST_Request $request, array $account ): array {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = array();
		}
		$oa_id     = (string) ( $body['conversation_id'] ?? ( $body['account_id'] ?? '' ) );
		$uid       = (string) ( $body['from_user_id'] ?? '' );

		return array(
			'platform'    => 'ZALO_OA',
			'instance_id' => $oa_id,
			'chat_id'     => 'zalooa_' . $oa_id . '_' . $uid,
			'sender_id'   => $uid,
			'text'        => (string) ( $body['message_text'] ?? '' ),
			'type'        => (string) ( $body['message_type'] ?? 'text' ),
			'media_url'   => (string) ( $body['image_url'] ?? ( $body['file_url'] ?? '' ) ),
			'raw'         => $body,
			'timestamp'   => isset( $body['message_time'] ) ? (int) $body['message_time'] : time(),
		);
	}

	// ── Outbound ─────────────────────────────────────────────────────────

	/**
	 * Send via bridge → sidecar → OA Open API.
	 * R-ZP-5: guard OA 7-day CSKH window before sending.
	 *
	 * Supports two calling conventions:
	 *  - send_envelope() path (normal):  $msg=array, $account=array
	 *  - Gateway_Sender::send() path (legacy): $msg=chat_id string, $account=message string
	 *
	 * Type hints are intentionally omitted to allow both conventions (PHP 7.4 contravariance).
	 *
	 * @param array|string $msg     Outbound envelope OR chat_id string (legacy).
	 * @param array|string $account Decrypted account creds OR message string (legacy).
	 * @return array { sent:bool, error:string, platform:string, mid:string }
	 */
	// [2026-06-07 Johnny Chu] PHASE-0.39 — drop type hints: legacy Gateway_Sender::send() passes string args
	public function send_outbound( $msg, $account = null ) {
		// Legacy path: Gateway_Sender::send($chat_id, $message, ...) → send_outbound(string, string, array)
		// chat_id format: zalooa_{oa_id}_{user_id}
		if ( is_string( $msg ) ) {
			$chat_id   = $msg;
			$text      = is_string( $account ) ? $account : '';
			// strrpos: use last underscore so oa_id can safely contain underscores
			$stripped  = substr( $chat_id, strlen( 'zalooa_' ) );
			$sep       = strrpos( $stripped, '_' );
			if ( false === $sep || 0 === $sep ) {
				return array( 'sent' => false, 'error' => 'invalid_chat_id', 'platform' => 'ZALO_OA', 'mid' => '' );
			}
			$oa_id_raw = substr( $stripped, 0, $sep );
			$recipient = substr( $stripped, $sep + 1 );
			// Look up bridge_account_id from DB — needed by sidecar client
			$acct_row  = BizCity_Zalo_Mapping_Repo::find_account_by_zalo_oa_id( $oa_id_raw );
			$bridge_id = ( $acct_row && isset( $acct_row['bridge_account_id'] ) )
				? (string) $acct_row['bridge_account_id']
				: '';
			$msg     = array( 'text' => $text, 'type' => 'text', 'recipient' => $recipient, 'media_url' => '' );
			$account = array( 'bridge_account_id' => $bridge_id, 'oa_id' => $oa_id_raw );
		}

		if ( ! is_array( $msg ) || ! is_array( $account ) ) {
			return array( 'sent' => false, 'error' => 'invalid_args', 'platform' => 'ZALO_OA', 'mid' => '' );
		}

		$bridge_account_id = (string) ( $account['bridge_account_id'] ?? '' );
		if ( $bridge_account_id === '' ) {
			return array( 'sent' => false, 'error' => 'bridge_account_id missing', 'platform' => 'ZALO_OA', 'mid' => '' );
		}

		$recipient = (string) ( $msg['recipient'] ?? '' );
		$oa_id     = (string) ( $account['oa_id'] ?? '' );

		// [2026-06-07 Johnny Chu] PHASE-0.39 R-ZP-5 — OA CSKH window guard.
		if ( $oa_id !== '' && $recipient !== '' ) {
			$window_open = BizCity_Zalo_Mapping_Repo::is_oa_window_open( $oa_id, $recipient );
			if ( ! $window_open ) {
				return array(
					'sent'     => false,
					'error'    => 'zalo_oa_window_closed',
					'platform' => 'ZALO_OA',
					'mid'      => '',
				);
			}
		}

		$text      = (string) ( $msg['text']      ?? '' );
		$type      = (string) ( $msg['type']      ?? 'text' );
		$media_url = (string) ( $msg['media_url'] ?? '' );
		$attachments = array();
		if ( $media_url !== '' && $type !== 'text' ) {
			$attachments[] = array( 'url' => $media_url, 'name' => '' );
		}

		$client = BizCity_Zalo_Bridge_Client::instance();
		$result = $client->enqueue_outbound( $bridge_account_id, $recipient, $text, $type, $attachments );

		$sent = ! empty( $result['success'] ) && empty( $result['_degraded'] );

		// Update OA window cs_sent_count after successful send.
		if ( $sent && $oa_id !== '' && $recipient !== '' ) {
			BizCity_Zalo_Mapping_Repo::increment_oa_cs_count( $oa_id, $recipient );
		}

		// [2026-06-07 Johnny Chu] PHASE-0.39 — capture outbound for the read-hook Logs tab.
		if ( class_exists( 'BizCity_Zalo_Hook_Log' ) ) {
			BizCity_Zalo_Hook_Log::record_outbound(
				'ZALO_OA',
				(string) $bridge_account_id,
				(string) $recipient,
				(string) $text,
				$sent ? 'ok' : ( isset( $result['_degraded'] ) ? 'degraded' : 'error' ),
				$sent ? '' : (string) ( $result['message'] ?? '' )
			);
		}

		return array(
			'sent'     => $sent,
			'error'    => $sent ? '' : (string) ( $result['message'] ?? 'bridge_degraded' ),
			'platform' => 'ZALO_OA',
			'mid'      => (string) ( $result['job_id'] ?? '' ),
		);
	}

	// ── Health ────────────────────────────────────────────────────────────

	public function health(): array {
		$client = BizCity_Zalo_Bridge_Client::instance();
		$start  = microtime( true );
		$result = $client->list_accounts();
		$ok     = ! empty( $result['success'] ) && empty( $result['_degraded'] );
		return array(
			'ok'               => $ok,
			'latency_ms'       => (int) round( ( microtime( true ) - $start ) * 1000 ),
			'last_error'       => $ok ? '' : (string) ( $result['message'] ?? 'bridge_unreachable' ),
			'last_success_at'  => '',
			'token_expires_at' => '',
		);
	}
}
