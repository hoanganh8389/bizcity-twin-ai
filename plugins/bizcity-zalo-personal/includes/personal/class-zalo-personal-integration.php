<?php
/**
 * BizCity Zalo Personal Channel Integration (PHASE-0.39)
 *
 * Handles Zalo cá nhân (QR login via zca-bridge sidecar).
 * - One account = one Zalo personal nick registered on the bridge.
 * - chat_id prefix: zalop_{bridge_account_id}_{thread_id}
 * - Inbound comes from bridge via POST /bizcity-channel/v1/zalo-bridge/inbound (Bearer verify).
 * - Outbound dispatched via BizCity_Zalo_Bridge_Client::enqueue_outbound().
 *
 * R-ZP-1: channel_type stays 'zalo' (CRM Adapter reused).
 * R-ZP-2: credentials live on sidecar; plugin stores only bridge_account_id + label.
 *
 * @package BizCity_Zalo_Personal
 * @since   1.0.0
 */

// [2026-06-07 Johnny Chu] PHASE-0.39 — Zalo Personal channel integration
defined( 'ABSPATH' ) || exit;

class BizCity_Zalo_Personal_Integration extends BizCity_Channel_Integration {

	// [2026-06-11 Johnny Chu] PHP74-COMPAT — add types to match parent BizCity_Integration typed props (untyped redecl = PHP fatal).
	protected string $code           = 'zalo_personal';
	protected string $platform       = 'ZALO_PERSONAL';
	protected string $name           = 'Zalo Cá nhân';
	protected string $desc           = 'Tài khoản Zalo cá nhân — đăng nhập QR qua zca-bridge. Nhận & gửi tin vào CRM Inbox.';
	protected string $logo           = 'zalo';
	protected string $default_role   = 'cskh';
	protected string $chat_id_prefix = 'zalop_';
	protected int    $order          = 21;

	/**
	 * Settings schema for the gateway registry.
	 * bridge_url + bridge_token are global but stored per-account for flexibility.
	 */
	protected array $settings = array(
		'label'             => array(
			'type'    => 'text',
			'label'   => 'Tên hiển thị',
			'desc'    => 'Nhãn dễ nhớ cho nick Zalo này (vd: Nick CSKH chính).',
			'default' => '',
		),
		'bridge_account_id' => array(
			'type'     => 'text',
			'label'    => 'Bridge Account ID',
			'desc'     => 'ID account trên zca-bridge (xem trang admin bridge).',
			'default'  => '',
			'required' => true,
		),
		'zalo_uid'          => array(
			'type'     => 'text',
			'label'    => 'Zalo UID',
			'desc'     => 'Tự điền sau khi login QR thành công.',
			'default'  => '',
			'readonly' => true,
		),
		'status'            => array(
			'type'     => 'text',
			'label'    => 'Trạng thái',
			'desc'     => 'pending_qr | connected | expired | logged_out',
			'default'  => 'pending_qr',
			'readonly' => true,
		),
	);

	// ── Webhook verification ──────────────────────────────────────────────

	/**
	 * Verify inbound request from bridge. Checks Bearer token.
	 *
	 * @param WP_REST_Request $request
	 * @return bool
	 */
	public function verify_webhook( WP_REST_Request $request ) {
		$stored_token = (string) get_option( BizCity_Zalo_Bridge_Client::OPTION_TOKEN, '' );
		if ( $stored_token === '' ) {
			return false;
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
	 * Normalize bridge inbound payload → canonical channel envelope.
	 *
	 * Bridge POST body shape:
	 *   { account_id, account_name, kind, from_user_id, from_user_name,
	 *     conversation_id, message_id, message_text, message_type,
	 *     message_time, image_url, file_url, file_name, raw }
	 *
	 * @param WP_REST_Request $request
	 * @param array           $account
	 * @return array Canonical envelope.
	 */
	public function normalize_inbound( WP_REST_Request $request, array $account ): array {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = array();
		}
		$account_id = (string) ( $body['account_id'] ?? '' );
		$thread_id  = (string) ( $body['from_user_id'] ?? '' );

		return array(
			'platform'    => 'ZALO_PERSONAL',
			'instance_id' => $account_id,
			'chat_id'     => 'zalop_' . $account_id . '_' . $thread_id,
			'sender_id'   => $thread_id,
			'text'        => (string) ( $body['message_text'] ?? '' ),
			'type'        => (string) ( $body['message_type'] ?? 'text' ),
			'media_url'   => (string) ( $body['image_url'] ?? ( $body['file_url'] ?? '' ) ),
			'raw'         => $body,
			'timestamp'   => isset( $body['message_time'] ) ? (int) $body['message_time'] : time(),
		);
	}

	// ── Outbound ─────────────────────────────────────────────────────────

	/**
	 * Send message via bridge → sidecar worker.
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
		// chat_id format: zalop_{bridge_account_id}_{thread_id}
		if ( is_string( $msg ) ) {
			$chat_id  = $msg;
			$text     = is_string( $account ) ? $account : '';
			// strrpos: use last underscore so bridge_account_id can safely contain underscores
			$stripped = substr( $chat_id, strlen( 'zalop_' ) );
			$sep      = strrpos( $stripped, '_' );
			if ( false === $sep || 0 === $sep ) {
				return array( 'sent' => false, 'error' => 'invalid_chat_id', 'platform' => 'ZALO_PERSONAL', 'mid' => '' );
			}
			$msg     = array(
				'text'      => $text,
				'type'      => 'text',
				'recipient' => substr( $stripped, $sep + 1 ),
				'media_url' => '',
			);
			$account = array( 'bridge_account_id' => substr( $stripped, 0, $sep ) );
		}

		if ( ! is_array( $msg ) || ! is_array( $account ) ) {
			return array( 'sent' => false, 'error' => 'invalid_args', 'platform' => 'ZALO_PERSONAL', 'mid' => '' );
		}

		$bridge_account_id = (string) ( $account['bridge_account_id'] ?? '' );
		if ( $bridge_account_id === '' ) {
			return array( 'sent' => false, 'error' => 'bridge_account_id missing', 'platform' => 'ZALO_PERSONAL', 'mid' => '' );
		}

		$recipient = (string) ( $msg['recipient'] ?? '' );
		$text      = (string) ( $msg['text']      ?? '' );
		$type      = (string) ( $msg['type']      ?? 'text' );
		$media_url = (string) ( $msg['media_url'] ?? '' );

		$attachments = array();
		if ( $media_url !== '' && $type !== 'text' ) {
			$attachments[] = array( 'url' => $media_url, 'name' => '' );
		}

		$client = BizCity_Zalo_Bridge_Client::instance();
		$result = $client->enqueue_outbound( $bridge_account_id, $recipient, $text, $type, $attachments );

		// [2026-06-07 Johnny Chu] PHASE-0.39 — capture outbound for the read-hook Logs tab.
		$sent = ! empty( $result['success'] ) && empty( $result['_degraded'] );
		if ( class_exists( 'BizCity_Zalo_Hook_Log' ) ) {
			BizCity_Zalo_Hook_Log::record_outbound(
				'ZALO_PERSONAL',
				(string) $bridge_account_id,
				(string) $recipient,
				(string) $text,
				$sent ? 'ok' : ( isset( $result['_degraded'] ) ? 'degraded' : 'error' ),
				$sent ? '' : (string) ( $result['message'] ?? '' )
			);
		}

		return array(
			'sent'     => $sent,
			'error'    => isset( $result['_degraded'] ) ? (string) ( $result['message'] ?? 'bridge_degraded' ) : '',
			'platform' => 'ZALO_PERSONAL',
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
