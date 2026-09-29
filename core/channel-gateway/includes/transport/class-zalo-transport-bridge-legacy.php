<?php
/**
 * Legacy wrapper for the existing zca and managed Hub send paths (LC-2/LC-4).
 *
 * @package BizCity_Twin_AI
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Zalo_Transport_Bridge_Legacy', false ) ) {
	return;
}

final class BizCity_Zalo_Transport_Bridge_Legacy implements BizCity_Zalo_Transport {

	/** @var string */
	private $transport_id;

	public function __construct( string $transport_id ) {
		$this->transport_id = $transport_id;
	}

	public function id(): string {
		return $this->transport_id;
	}

	public function descriptor(): array {
		return BizCity_Zalo_Transport_Capability::descriptor( $this->transport_id );
	}

	public function send( array $target, array $message, array $ctx ): array {
		if ( ! class_exists( 'BizCity_Zalo_Bridge_Client' ) ) {
			return self::failed( 'transport_unavailable', 'Zalo bridge client is unavailable.' );
		}
		$bridge = BizCity_Zalo_Bridge_Client::instance();
		$result = $bridge->enqueue_outbound(
			(string) ( $target['bridge_account_id'] ?? '' ),
			(string) ( $target['peer_thread_id'] ?? '' ),
			(string) ( $message['text'] ?? '' ),
			(string) ( $message['content_type'] ?? 'text' ),
			is_array( $message['attachments'] ?? null ) ? $message['attachments'] : array(),
			(string) ( $target['thread_kind'] ?? 'personal' ) === 'group' ? 'group' : 'user',
			is_array( $message['mentions'] ?? null ) ? $message['mentions'] : array(),
			(string) ( $ctx['idempotency_key'] ?? '' ),
			is_array( $message['quote'] ?? null ) ? $message['quote'] : array()
		);
		return self::normalize_result( $result, (string) ( $ctx['trace_id'] ?? '' ) );
	}

	public function account_status( string $bridge_account_id ): array {
		if ( ! class_exists( 'BizCity_Zalo_Bridge_Client' ) || ! method_exists( 'BizCity_Zalo_Bridge_Client', 'instance' ) ) {
			return array( 'bridge_account_id' => $bridge_account_id, 'transport_id' => $this->transport_id, 'session' => 'unknown', 'reply_owner_verified' => null, 'last_event_at' => null );
		}
		$result = BizCity_Zalo_Bridge_Client::instance()->get_account( $bridge_account_id );
		$status = (string) ( $result['account']['status'] ?? '' );
		$session = 'connected' === $status || 'running' === $status ? 'connected' : ( 'stopped' === $status ? 'stopped' : 'unknown' );
		return array( 'bridge_account_id' => $bridge_account_id, 'transport_id' => $this->transport_id, 'session' => $session, 'reply_owner_verified' => null, 'last_event_at' => null );
	}

	public function health(): array {
		if ( ! class_exists( 'BizCity_Zalo_Bridge_Client' ) ) {
			return array( array( 'check_id' => 'legacy_bridge.available', 'status' => 'SKIP', 'reason_code' => 'transport_unavailable', 'duration_ms' => 0, 'detail' => 'Legacy bridge client is unavailable.', 'request_id' => null, 'contract_version' => BizCity_Zalo_Transport_Capability::VERSION ) );
		}
		$result = BizCity_Zalo_Bridge_Client::instance()->health();
		$ok = ! empty( $result['success'] ) || ! empty( $result['ok'] );
		return array( array( 'check_id' => 'legacy_bridge.health', 'status' => $ok ? 'PASS' : 'FAIL', 'reason_code' => $ok ? 'ok' : 'bridge_degraded', 'duration_ms' => 0, 'detail' => $ok ? 'Legacy bridge health check passed.' : 'Legacy bridge health check failed.', 'request_id' => null, 'contract_version' => BizCity_Zalo_Transport_Capability::VERSION ) );
	}

	private static function normalize_result( array $result, string $request_id ): array {
		$success = ! empty( $result['success'] ) && empty( $result['_degraded'] );
		$outcome = sanitize_key( (string) ( $result['outcome'] ?? $result['delivery_status'] ?? '' ) );
		if ( ! in_array( $outcome, array( 'sent', 'queued', 'throttled', 'outcome_unknown' ), true ) ) {
			$outcome = $success ? 'queued' : 'failed';
		}
		$error = null;
		if ( ! $success || 'failed' === $outcome ) {
			$error = array( 'code' => (string) ( $result['code'] ?? 'transport_failed' ), 'message' => (string) ( $result['message'] ?? 'Transport failed.' ), 'hint' => 'Check the connected Zalo transport and try again.', 'help_code' => 'zalo_transport_failed', 'retryable' => ! empty( $result['retryable'] ) );
		}
		return array( 'ok' => $success, 'outcome' => $outcome, 'external_ids' => array_filter( array( (string) ( $result['message_id'] ?? $result['job_id'] ?? '' ) ) ), 'replayed' => ! empty( $result['idempotency_replayed'] ), 'retry_after' => isset( $result['retry_after'] ) && is_numeric( $result['retry_after'] ) ? (int) $result['retry_after'] : null, 'request_id' => $request_id, 'error' => $error );
	}

	private static function failed( string $code, string $message ): array {
		return array( 'ok' => false, 'outcome' => 'failed', 'external_ids' => array(), 'replayed' => false, 'retry_after' => null, 'request_id' => '', 'error' => array( 'code' => $code, 'message' => $message, 'hint' => 'Check the connected Zalo transport and try again.', 'help_code' => 'zalo_transport_failed', 'retryable' => false ) );
	}
}
