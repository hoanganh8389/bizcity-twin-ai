<?php
/**
 * Exactly-once contract for Automation external side effects.
 *
 * Local runner logs prevent replay after a recorded OK/SKIP. External
 * providers must consume the stable idempotency key; an interrupted request
 * is unknown and must be reconciled before a retry.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Automation
 * @since 2026-08-16
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Automation_Side_Effect_Contract' ) ) {
	return;
}

final class BizCity_Automation_Side_Effect_Contract {

	const STATUS_CONFIRMED = 'confirmed';
	const STATUS_SENT      = 'sent';
	const STATUS_UNKNOWN   = 'unknown';
	const STATUS_FAILED    = 'failed';

	public static function key( string $run_id, string $node_id, string $side_effect_key, int $blog_id = 0 ): string {
		// [2026-08-16 Johnny Chu] MPR-V5-IDEMPOTENCY — HMAC-scoped provider key across blog, run, node and process crash.
		$material = ( $blog_id > 0 ? $blog_id : 0 ) . '|' . trim( $run_id ) . '|' . trim( $node_id ) . '|' . trim( $side_effect_key );
		$secret = function_exists( 'wp_salt' ) ? (string) wp_salt( 'auth' ) : ( defined( 'AUTH_KEY' ) ? (string) AUTH_KEY : 'bizcity-side-effect' );
		return 'bcse_' . hash_hmac( 'sha256', $material, $secret );
	}

	public static function context( string $run_id, string $node_id, array $data = array() ): array {
		// [2026-08-16 Johnny Chu] MPR-V5-IDEMPOTENCY — require an explicit business side_effect_key for external writes.
		$blog_id = (int) ( $data['blog_id'] ?? ( function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 0 ) );
		$operation = sanitize_key( (string) ( $data['operation'] ?? $data['side_effect_key'] ?? $data['idempotency_scope'] ?? '' ) );
		$resource_id = sanitize_key( (string) ( $data['resource_id'] ?? 'workflow' ) );
		$side_effect_scope = $operation . '|' . $resource_id;
		if ( $operation === '' ) {
			return array(
				'known'             => false,
				'requires_provider' => true,
				'status'            => 'missing_key',
				'side_effect_key'   => '',
				'idempotency_key'   => '',
			);
		}
		return array(
			'known'             => true,
			'requires_provider' => true,
			'status'            => 'ready',
			'side_effect_key'   => $blog_id . '|' . trim( $run_id ) . '|' . trim( $node_id ) . '|' . $side_effect_scope,
			'idempotency_key'   => self::key( $run_id, $node_id, $side_effect_scope, $blog_id ),
			'blog_id'           => $blog_id,
			'operation'         => $operation,
			'resource_id'       => $resource_id,
		);
	}

	public static function provider_result( $result ): array {
		// [2026-08-17 Johnny Chu] MPR-V5-GATE5 — normalize provider outcomes before Runner persistence or retry decisions.
		$request_id = self::provider_request_id( $result );
		if ( is_wp_error( $result ) ) {
			$code = (string) $result->get_error_code();
			$unknown = in_array( $code, array( 'timeout', 'http_request_failed', 'connection_failed', 'provider_unknown' ), true );
			return self::outcome( $unknown ? self::STATUS_UNKNOWN : self::STATUS_FAILED, $unknown ? 'provider_result_unknown' : 'provider_rejected', $request_id );
		}
		if ( ! is_array( $result ) ) {
			return self::outcome( self::STATUS_FAILED, 'provider_rejected', $request_id );
		}

		$raw_status = $result['status'] ?? '';
		$explicit = strtolower( trim( (string) ( $result['side_effect_status'] ?? $result['provider_status'] ?? ( is_string( $raw_status ) ? $raw_status : '' ) ) ) );
		if ( in_array( $explicit, array( self::STATUS_CONFIRMED, self::STATUS_SENT, self::STATUS_UNKNOWN, self::STATUS_FAILED ), true ) ) {
			return self::outcome( $explicit, self::reason_for_status( $explicit ), $request_id );
		}
		if ( ! empty( $result['confirmed'] ) ) {
			return self::outcome( self::STATUS_CONFIRMED, 'provider_confirmed', $request_id );
		}
		if ( ! empty( $result['sent'] ) || ! empty( $result['success'] ) ) {
			return self::outcome( self::STATUS_SENT, 'provider_sent', $request_id );
		}
		if ( isset( $result['status'] ) && is_numeric( $result['status'] ) ) {
			$http_status = (int) $result['status'];
			return self::outcome( $http_status >= 200 && $http_status < 300 ? self::STATUS_SENT : self::STATUS_FAILED, $http_status >= 200 && $http_status < 300 ? 'provider_sent' : 'provider_rejected', $request_id );
		}
		if ( isset( $result['status'] ) && in_array( strtolower( (string) $result['status'] ), array( 'ok', 'success' ), true ) ) {
			return self::outcome( self::STATUS_SENT, 'provider_sent', $request_id );
		}
		return self::outcome( self::STATUS_UNKNOWN, 'provider_result_unknown', $request_id );
	}

	public static function reconcile( $result, $resolver = null ): array {
		// [2026-08-17 Johnny Chu] MPR-V5-GATE5 — expose explicit status reconciliation without automatic provider retry.
		$outcome = self::provider_result( $result );
		if ( $outcome['status'] !== self::STATUS_UNKNOWN || ! is_callable( $resolver ) ) {
			return $outcome;
		}
		try {
			$resolved = call_user_func( $resolver, $result, $outcome );
		} catch ( \Throwable $e ) {
			return self::outcome( self::STATUS_UNKNOWN, 'reconciliation_failed', $outcome['provider_request_id'] );
		}
		$reconciled = self::provider_result( $resolved );
		$reconciled['reconciled'] = $reconciled['status'] !== self::STATUS_UNKNOWN;
		if ( ! $reconciled['reconciled'] ) {
			$reconciled['reason_code'] = 'reconciliation_unknown';
		}
		return $reconciled;
	}

	private static function outcome( string $status, string $reason_code, string $request_id = '' ): array {
		return array(
			'status'              => $status,
			'retry_allowed'       => false,
			'reason_code'         => $reason_code,
			'provider_request_id' => $request_id,
		);
	}

	private static function reason_for_status( string $status ): string {
		return $status === self::STATUS_CONFIRMED ? 'provider_confirmed'
			: ( $status === self::STATUS_SENT ? 'provider_sent'
				: ( $status === self::STATUS_FAILED ? 'provider_rejected' : 'provider_result_unknown' ) );
	}

	private static function provider_request_id( $result ): string {
		if ( ! is_array( $result ) ) {
			return '';
		}
		$request_id = (string) ( $result['provider_request_id'] ?? $result['request_id'] ?? $result['mid'] ?? '' );
		return substr( sanitize_text_field( $request_id ), 0, 128 );
	}
}
