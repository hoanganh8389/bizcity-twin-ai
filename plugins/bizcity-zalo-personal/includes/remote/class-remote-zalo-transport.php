<?php
/**
 * Remote Zalo Hub — LC-2 transport port implementation (XS-E13/B8).
 *
 * Plugs the existing generic `BizCity_Zalo_Transport` boundary (core/channel-gateway/includes/transport/)
 * so the CRM Zalo Personal adapter's send path (PHASE-0.82-A5) reaches Remote Zalo Hub with NO CRM code
 * change: `BizCity_Zalo_Transport_Registry::for_account()` already resolves by `BizCity_Zalo_Account_Flags`
 * provider, and any non-legacy transport instance already goes through `->send()` generically.
 *
 * @package BizCity_Zalo_Personal
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Remote_Zalo_Transport', false ) ) {
	return;
}

// [2026-09-30 Claude Sonnet 5] PHASE-0.82 B8/E13 — wire the remote client into the shared transport port.
final class BizCity_Remote_Zalo_Transport implements BizCity_Zalo_Transport {

	public function id(): string { return 'remote_zalo_hub'; }

	public function descriptor(): array {
		return class_exists( 'BizCity_Zalo_Transport_Capability' ) ? BizCity_Zalo_Transport_Capability::descriptor( 'remote_zalo_hub' ) : array();
	}

	/**
	 * @param array $target  bridge_account_id, peer_thread_id, thread_kind, conversation_id, inbox_id
	 * @param array $message text, content_type, attachments, mentions, quote
	 * @param array $ctx     trace_id, idempotency_key, actor_user_id, pause_minutes
	 */
	public function send( array $target, array $message, array $ctx ): array {
		if ( ! class_exists( 'BizCity_Remote_Zalo_Hub_Client' ) ) {
			return self::failed( 'transport_unavailable', 'Remote Zalo Hub transport is unavailable.', false );
		}
		$bridge_account_id = (string) ( $target['bridge_account_id'] ?? '' );
		$thread = (string) ( $target['peer_thread_id'] ?? '' );
		if ( '' === $bridge_account_id || '' === $thread ) {
			return self::failed( 'remote_recipient_missing', 'Missing account or thread for Remote Zalo Hub send.', false );
		}
		// 04 §6.5 row 1 — the guide's `/threads/{id}/messages` only accepts a thread that already exists;
		// C5 (capability-gated UI) is meant to hide "cuộc trò chuyện mới" for this transport, but a stray
		// call must still fail honestly rather than pretend to send.
		$attachments = is_array( $message['attachments'] ?? null ) ? array_filter( $message['attachments'] ) : array();
		if ( $attachments ) {
			// 04 §6.5 row 5 — v1 cannot send images/files; Bot Studio must degrade honestly, not silently drop the attachment.
			return self::failed( 'remote_attachment_unsupported', 'Remote Zalo Hub chưa gửi được ảnh/tệp trong phiên bản này.', false );
		}
		$idempotency_key = (string) ( $ctx['idempotency_key'] ?? '' );
		if ( '' === $idempotency_key ) {
			return self::failed( 'transport_idempotency_key_missing', 'Missing idempotency key for Remote Zalo Hub send.', false );
		}
		$body = array( 'text' => (string) ( $message['text'] ?? '' ) );
		$mentions = is_array( $message['mentions'] ?? null ) ? array_values( array_filter( $message['mentions'] ) ) : array();
		if ( $mentions ) { $body['mentions'] = $mentions; }
		// [2026-09-30 Claude Sonnet 5] PHASE-0.82 D82-26 — a STAFF send extends the provider's own pause (never a
		// second pause store); a bot/system send never pauses (0). `$ctx['pause_minutes']` is caller-supplied,
		// clamped here to the guide's 0-1440 range regardless of what was asked.
		$pause = max( 0, min( 1440, (int) ( $ctx['pause_minutes'] ?? 0 ) ) );
		if ( $pause > 0 ) { $body['pauseBotMinutes'] = $pause; }

		$result = self::client()->send_message( $bridge_account_id, $thread, $body, $idempotency_key, (string) ( $ctx['trace_id'] ?? '' ) );
		$normalized = self::normalize( $result );
		// [2026-09-30 Claude Sonnet 5] PHASE-0.82 B9 — a rate-limited send is queued for automatic retry under the
		// SAME idempotency key (safe: the provider dedupes by key, guide §4.3), not left for a human to notice and
		// resend by hand. `message_id` is additive on `$target` (adapter change, all legacy transports ignore it).
		if ( 'failed' === $normalized['outcome'] && ! empty( $normalized['error']['retryable'] ) && class_exists( 'BizCity_Remote_Zalo_Send_Queue' ) ) {
			BizCity_Remote_Zalo_Send_Queue::enqueue( array(
				'bridge_account_id' => $bridge_account_id, 'thread' => $thread, 'body' => $body, 'idempotency_key' => $idempotency_key,
				'message_id' => (int) ( $target['message_id'] ?? 0 ), 'code' => (string) $normalized['error']['code'], 'retry_after' => $normalized['retry_after'] ?? 60,
			) );
		}
		return $normalized;
	}

	public function account_status( string $bridge_account_id ): array {
		$unknown = array( 'bridge_account_id' => $bridge_account_id, 'transport_id' => $this->id(), 'session' => 'unknown', 'reply_owner_verified' => null, 'last_event_at' => null );
		if ( ! class_exists( 'BizCity_Remote_Zalo_Link_Service' ) ) { return $unknown; }
		$ref = 0 === strpos( $bridge_account_id, BizCity_Remote_Zalo_Link_Service::PREFIX ) ? substr( $bridge_account_id, strlen( BizCity_Remote_Zalo_Link_Service::PREFIX ) ) : $bridge_account_id;
		$account = BizCity_Remote_Zalo_Link_Service::remote_account( $ref );
		if ( ! is_array( $account ) ) { return $unknown; }
		$session = 'running' === (string) ( $account['status'] ?? '' ) ? 'connected' : 'stopped';
		// Tier 1-2: Bot Studio never confirmed as the sole replier on this transport (51 §4.3/§4.6) — reported
		// `false` rather than `null` so a UI checking this does not read "not applicable" as "safe to answer".
		return array( 'bridge_account_id' => $bridge_account_id, 'transport_id' => $this->id(), 'session' => $session, 'reply_owner_verified' => false, 'last_event_at' => null );
	}

	/** Config-only check (no network) — the dedicated network probe lives at `POST zalo-remote/check` (E3/C6). */
	public function health(): array {
		$version = class_exists( 'BizCity_Zalo_Transport_Capability' ) ? BizCity_Zalo_Transport_Capability::VERSION : '1.1.0';
		if ( ! class_exists( 'BizCity_Remote_Zalo_Credentials' ) ) {
			return array( array( 'check_id' => 'remote_zalo_hub.available', 'status' => 'FAIL', 'reason_code' => 'remote_not_loaded', 'duration_ms' => 0, 'detail' => 'Remote Zalo Hub module is not loaded.', 'request_id' => null, 'contract_version' => $version ) );
		}
		$conn = BizCity_Remote_Zalo_Credentials::public_view();
		$configured = ! empty( $conn['key_set'] ) && '' !== (string) ( $conn['base_url_host'] ?? '' );
		return array( array(
			'check_id' => 'remote_zalo_hub.configured',
			'status' => $configured ? 'PASS' : 'SKIP',
			'reason_code' => $configured ? 'ok' : 'remote_not_configured',
			'duration_ms' => 0,
			'detail' => $configured ? 'Remote Zalo Hub is configured.' : 'Remote Zalo Hub chưa cấu hình — xem Bot Studio › Cài đặt kết nối cho kiểm tra kết nối thật.',
			'request_id' => null,
			'contract_version' => $version,
		) );
	}

	private static function client() {
		return new BizCity_Remote_Zalo_Hub_Client();
	}

	/** LC-6 error → the transport port's `{ok,outcome,external_ids,replayed,retry_after,request_id,error}` shape. */
	private static function normalize( array $result ): array {
		$request_id = (string) ( $result['request_id'] ?? '' );
		if ( ! empty( $result['ok'] ) ) {
			$data = is_array( $result['data'] ?? null ) ? $result['data'] : array();
			$message_id = (string) ( $data['zaloMsgId'] ?? $data['id'] ?? '' );
			return array(
				'ok' => true, 'outcome' => 'sent', 'external_ids' => array_filter( array( $message_id ) ),
				'replayed' => ! empty( $result['replayed'] ), 'retry_after' => null, 'request_id' => $request_id, 'error' => null,
			);
		}
		$code = (string) ( $result['error']['code'] ?? 'remote_unreachable' );
		$retryable = ! empty( $result['error']['retryable'] );
		$retry_after = isset( $result['error']['retry_after'] ) && is_numeric( $result['error']['retry_after'] ) ? (int) $result['error']['retry_after'] : null;
		// [2026-09-30 Claude Sonnet 5] 04 §6.5 row 2 — a 429 has NOT actually been accepted by the provider. Until
		// B9 builds a real persisted retry queue, reporting it as an accepted "throttled" outcome would silently
		// drop the message while telling staff it was sent. Report it as a retryable FAILURE with `Retry-After`
		// surfaced, so the composer/CRM can show it as "chưa gửi — thử lại" rather than a false success.
		// `remote_outcome_unknown` also stays a failure and is explicitly non-retryable (03 §4.9): the caller must
		// reconcile before resending, never blindly retry under a new idempotency key.
		$outcome = 'remote_outcome_unknown' === $code ? 'outcome_unknown' : 'failed';
		return array(
			'ok' => false, 'outcome' => $outcome, 'external_ids' => array(), 'replayed' => false, 'retry_after' => $retry_after,
			'request_id' => $request_id,
			'error' => array( 'code' => $code, 'message' => self::message_for( $code ), 'hint' => self::hint_for( $code ), 'help_code' => $code, 'retryable' => 'remote_outcome_unknown' === $code ? false : $retryable ),
		);
	}

	private static function failed( string $code, string $message, bool $retryable ): array {
		return array( 'ok' => false, 'outcome' => 'failed', 'external_ids' => array(), 'replayed' => false, 'retry_after' => null, 'request_id' => '', 'error' => array( 'code' => $code, 'message' => $message, 'hint' => self::hint_for( $code ), 'help_code' => $code, 'retryable' => $retryable ) );
	}

	private static function message_for( string $code ): string {
		$map = array(
			'remote_not_found' => 'Cuộc trò chuyện này chưa tồn tại trên Remote Zalo Hub.',
			'remote_account_stopped' => 'Nick Remote Zalo đang dừng — cần đăng nhập lại.',
			'remote_throttled' => 'Remote Zalo Hub đang giới hạn nhịp gửi.',
			'remote_outcome_unknown' => 'Không rõ tin đã gửi hay chưa — không tự gửi lại.',
			'remote_auth_failed' => 'Khoá Remote Zalo Hub bị từ chối.',
			'remote_scope_missing' => 'Khoá Remote Zalo Hub thiếu quyền gửi tin.',
		);
		return $map[ $code ] ?? 'Không gửi được tin qua Remote Zalo Hub.';
	}

	private static function hint_for( string $code ): string {
		$map = array(
			'remote_not_found' => 'Nhánh Remote không mở được hội thoại mới — chỉ trả lời khách đã nhắn trước.',
			'remote_account_stopped' => 'Đăng nhập lại QR ở "Số đang kết nối".',
			'remote_throttled' => 'Hệ thống sẽ tự gửi lại; không thao tác thêm.',
			'remote_outcome_unknown' => 'Kiểm tra lại trong CRM Inbox trước khi gửi lại thủ công.',
			'remote_auth_failed' => 'Kiểm tra khoá ở Bot Studio → Cài đặt kết nối.',
			'remote_scope_missing' => 'Liên hệ đơn vị vận hành để mở quyền messages:send.',
		);
		return $map[ $code ] ?? 'Kiểm tra kết nối Remote Zalo Hub rồi thử lại.';
	}
}
