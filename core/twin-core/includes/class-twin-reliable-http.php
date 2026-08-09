<?php
/**
 * Shared outbound HTTP reliability adapter for Twin integrations.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Twin_Core
 * @since 1.3.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BizCity_Twin_Reliable_HTTP' ) ) {
	final class BizCity_Twin_Reliable_HTTP {

		/**
		 * Execute an outbound request with common deadline, retry, and circuit policy.
		 *
		 * @param string              $name
		 * @param string              $url
		 * @param array<string,mixed> $args
		 * @param array<string,mixed> $context
		 * @return mixed WP_HTTP response or WP_Error.
		 */
		public static function request( $name, $url, array $args = array(), array $context = array() ) {
			// [2026-07-30 Johnny Chu] PHASE-1.22-RUNTIME — centralize outbound retry and deadline behavior.
			$reliability = class_exists( 'BizCity_Twin_Runtime_Reliability' )
				? BizCity_Twin_Runtime_Reliability::instance()
				: null;
			$started_at = microtime( true );
			$policy     = $reliability ? $reliability->policy() : array();
			$deadline   = isset( $context['deadline_at'] )
				? (float) $context['deadline_at']
				: ( microtime( true ) + ( (int) ( $policy['timeout_budget']['provider_ms'] ?? 20000 ) / 1000 ) );
			$context['deadline_at'] = $deadline;
			$context['trace_id'] = self::header_value( $args, 'x-trace-id', $context['trace_id'] ?? self::trace_id() );
			$context['idempotency_key'] = self::header_value( $args, 'x-idempotency-key', $context['idempotency_key'] ?? self::idempotency_key( $name, $url, $args ) );

			$headers = isset( $args['headers'] ) && is_array( $args['headers'] ) ? $args['headers'] : array();
			$headers['X-Trace-Id'] = (string) $context['trace_id'];
			$headers['X-Idempotency-Key'] = (string) $context['idempotency_key'];
			$args['headers'] = $headers;
			if ( ! isset( $args['redirection'] ) ) {
				$args['redirection'] = 0;
			}

			if ( $reliability ) {
				$decision = $reliability->before_execution( (string) $name, $context );
				if ( empty( $decision['allowed'] ) ) {
					$result = array( 'ok' => false, 'code' => (string) ( $decision['code'] ?? 'runtime_rejected' ), 'retriable' => true );
					$reliability->record_outcome( (string) $name, $context, $result, 0, $started_at );
					return new WP_Error( $result['code'], (string) ( $decision['message'] ?? 'Outbound request temporarily unavailable.' ), array( 'retry_after_ms' => (int) ( $decision['retry_after_ms'] ?? 0 ) ) );
				}
			}

			$attempt = 0;
			$last_result = array( 'ok' => false, 'code' => 'http_request_failed', 'retriable' => true );
			while ( microtime( true ) < $deadline ) {
				$attempt++;
				$timeout_ms = max( 1000, (int) floor( ( $deadline - microtime( true ) ) * 1000 ) );
				$args['timeout'] = min( isset( $args['timeout'] ) ? max( 1, (int) $args['timeout'] ) * 1000 : $timeout_ms, $timeout_ms ) / 1000;
				$response = wp_remote_request( (string) $url, $args );
				if ( is_wp_error( $response ) ) {
					$last_result = array( 'ok' => false, 'code' => 'transport_error', 'retriable' => true );
				} else {
					$status = (int) wp_remote_retrieve_response_code( $response );
					$last_result = array(
						'ok'        => $status >= 200 && $status < 300,
						'code'      => $status >= 500 ? 'http_5xx' : ( 429 === $status ? 'http_429' : ( $status >= 200 && $status < 300 ? 'ok' : 'http_error' ) ),
						'retriable' => $status >= 500 || 429 === $status,
					);
				}
				$bucket = $reliability ? $reliability->classify_result( $last_result ) : 'permanent';
				if ( ! $reliability || ! $reliability->should_retry( $last_result, $bucket, $attempt, $context ) ) {
					break;
				}
				$delay = $reliability->backoff_ms( $bucket, $attempt );
				if ( $delay > 0 ) {
					usleep( min( $delay, max( 0, (int) floor( ( $deadline - microtime( true ) ) * 1000000 ) / 1000 ) ) * 1000 );
				}
			}

			if ( $reliability ) {
				$reliability->record_outcome( (string) $name, $context, $last_result, max( 1, $attempt ), $started_at );
			}
			return $response ?? new WP_Error( 'http_request_failed', 'Outbound request deadline exceeded.' );
		}

		private static function header_value( array $args, $name, $fallback ) {
			$headers = isset( $args['headers'] ) && is_array( $args['headers'] ) ? $args['headers'] : array();
			foreach ( $headers as $key => $value ) {
				if ( strtolower( (string) $key ) === strtolower( (string) $name ) && '' !== (string) $value ) {
					return (string) $value;
				}
			}
			return (string) $fallback;
		}

		private static function trace_id() {
			return function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'twin-', true );
		}

		private static function idempotency_key( $name, $url, array $args ) {
			$body = isset( $args['body'] ) && is_scalar( $args['body'] ) ? (string) $args['body'] : '';
			return 'twin-' . md5( (string) $name . '|' . (string) $url . '|' . $body );
		}
	}
}
