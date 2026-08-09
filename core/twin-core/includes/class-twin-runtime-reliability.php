<?php
/**
 * Shared runtime reliability policy and evidence for Twin executions.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Twin_Core
 * @since 1.2.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BizCity_Twin_Runtime_Reliability' ) ) {
	final class BizCity_Twin_Runtime_Reliability {

		/** @var BizCity_Twin_Runtime_Reliability|null */
		private static $instance = null;

		/** @var array<string,mixed>|null */
		private $policy = null;

		private function __construct() {}

		public static function instance(): self {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/**
		 * Return the validated runtime policy used by framework executions.
		 *
		 * @return array<string,mixed>
		 */
		public function policy(): array {
			if ( null !== $this->policy ) {
				return $this->policy;
			}

			$this->policy = array(
				'contract'        => 'runtime-execution-policy',
				'version'         => '1.0.0',
				'idempotency'     => array(
					'required'        => true,
					'key_ttl_seconds' => 86400,
					'header'          => 'x-idempotency-key',
				),
				'retry_policy'    => array(
					'transient'    => array( 'max_attempts' => 3, 'initial_backoff_ms' => 200, 'max_backoff_ms' => 3000 ),
					'rate_limited' => array( 'max_attempts' => 5, 'initial_backoff_ms' => 500, 'max_backoff_ms' => 60000 ),
					'timeout'      => array( 'max_attempts' => 2, 'initial_backoff_ms' => 300, 'max_backoff_ms' => 4000 ),
					'permanent'    => array( 'max_attempts' => 0, 'initial_backoff_ms' => 0, 'max_backoff_ms' => 0 ),
				),
				'dead_letter'    => array( 'enabled' => true, 'queue_name' => 'bizcity_dlx', 'max_age_seconds' => 172800 ),
				'concurrency'    => array( 'lock_scope' => 'resource', 'lock_ttl_seconds' => 30, 'max_inflight' => 8 ),
				'circuit_breaker' => array( 'failure_threshold' => 5, 'recovery_timeout_seconds' => 60, 'half_open_max_calls' => 2 ),
				'backpressure'   => array( 'queue_limit' => 500, 'drop_policy' => 'defer', 'quota_per_minute' => 120 ),
				'timeout_budget' => array( 'default_ms' => 15000, 'provider_ms' => 20000, 'channel_ms' => 12000 ),
				'trace'          => array( 'propagate_headers' => array( 'x-trace-id', 'x-request-id' ) ),
				'slo'            => array( 'success_rate_target' => 0.99, 'p95_latency_ms' => 2500, 'citation_coverage_target' => 0.85, 'tool_error_rate_max' => 0.03 ),
			);

			if ( function_exists( 'apply_filters' ) ) {
				$filtered = apply_filters( 'bizcity_twin_runtime_policy', $this->policy );
				if ( is_array( $filtered ) ) {
					$this->policy = $this->merge_policy( $this->policy, $filtered );
				}
			}

			return $this->policy;
		}

		/**
		 * Reserve a quota slot and reject an unhealthy upstream before side effects.
		 *
		 * @param string              $name
		 * @param array<string,mixed> $context
		 * @return array{allowed:bool,code?:string,message?:string,retry_after_ms?:int}
		 */
		public function before_execution( string $name, array $context = [] ): array {
			// [2026-07-30 Johnny Chu] PHASE-1.22-RUNTIME — enforce quota and circuit state at the common boundary.
			$threshold = (int) $this->policy()['circuit_breaker']['failure_threshold'];
			$half_open_max = (int) $this->policy()['circuit_breaker']['half_open_max_calls'];
			$state_key = $this->state_key( 'circuit', $name );
			$state     = $this->cache_get( $state_key );
			$now       = time();
			if ( is_array( $state ) && ! empty( $state['open_until'] ) && (int) $state['open_until'] > $now ) {
				return array( 'allowed' => false, 'code' => 'circuit_open', 'message' => 'Tool upstream is temporarily unavailable.', 'retry_after_ms' => max( 1000, ( (int) $state['open_until'] - $now ) * 1000 ) );
			}
			if ( is_array( $state ) && ! empty( $state['half_open'] ) && (int) ( $state['half_open_calls'] ?? 0 ) >= $half_open_max ) {
				return array( 'allowed' => false, 'code' => 'circuit_open', 'message' => 'Tool upstream is recovering; retry shortly.', 'retry_after_ms' => 1000 );
			}
			if ( is_array( $state ) && (int) ( $state['failures'] ?? 0 ) >= $threshold ) {
				$state['failures'] = 0;
				$state['half_open'] = true;
				$state['half_open_calls'] = 0;
				$this->cache_set( $state_key, $state, (int) $this->policy()['circuit_breaker']['recovery_timeout_seconds'] );
			}
			if ( is_array( $state ) && ! empty( $state['half_open'] ) ) {
				$state['half_open_calls'] = (int) ( $state['half_open_calls'] ?? 0 ) + 1;
				$this->cache_set( $state_key, $state, (int) $this->policy()['circuit_breaker']['recovery_timeout_seconds'] );
			}

			$quota = (int) $this->policy()['backpressure']['quota_per_minute'];
			$quota_key = $this->state_key( 'quota', $name . ':' . (int) ( $context['user_id'] ?? 0 ) . ':' . gmdate( 'YmdHi' ) );
			$count = (int) $this->cache_get( $quota_key );
			if ( $count >= $quota ) {
				return array( 'allowed' => false, 'code' => 'quota_exceeded', 'message' => 'Tool execution quota has been reached.', 'retry_after_ms' => 60000 );
			}
			$this->cache_set( $quota_key, $count + 1, 120 );

			return array( 'allowed' => true );
		}

		/**
		 * Classify a tool result into the public retry buckets.
		 *
		 * @param array<string,mixed> $result
		 * @return string
		 */
		public function classify_result( array $result ): string {
			$code = strtolower( (string) ( $result['code'] ?? '' ) );
			if ( false !== strpos( $code, 'rate' ) || in_array( $code, array( 'http_429', 'too_many_requests' ), true ) ) {
				return 'rate_limited';
			}
			if ( false !== strpos( $code, 'timeout' ) || false !== strpos( $code, 'timed_out' ) ) {
				return 'timeout';
			}
			if ( ! empty( $result['retriable'] ) ) {
				return 'transient';
			}
			return 'permanent';
		}

		public function max_attempts( string $bucket ): int {
			$rule = $this->policy()['retry_policy'][ $bucket ] ?? $this->policy()['retry_policy']['permanent'];
			return max( 1, (int) $rule['max_attempts'] );
		}

		public function backoff_ms( string $bucket, int $attempt ): int {
			$rule  = $this->policy()['retry_policy'][ $bucket ] ?? $this->policy()['retry_policy']['permanent'];
			$delay = (int) $rule['initial_backoff_ms'] * ( 2 ** max( 0, $attempt - 1 ) );
			return min( (int) $rule['max_backoff_ms'], max( 0, $delay ) );
		}

		public function should_retry( array $result, string $bucket, int $attempt, array $context = [] ): bool {
			if ( empty( $result['retriable'] ) || $attempt >= $this->max_attempts( $bucket ) ) {
				return false;
			}
			$deadline = isset( $context['deadline_at'] ) ? (float) $context['deadline_at'] : 0.0;
			return 0.0 === $deadline || microtime( true ) < $deadline;
		}

		/**
		 * Record outcome metrics, circuit state, and a metadata-only DLQ item.
		 *
		 * @param string              $name
		 * @param array<string,mixed> $context
		 * @param array<string,mixed> $result
		 */
		public function record_outcome( string $name, array $context, array $result, int $attempts, float $started_at ): void {
			$success = ! empty( $result['ok'] );
			$bucket  = $this->classify_result( $result );
			$state_key = $this->state_key( 'circuit', $name );
			$state     = $this->cache_get( $state_key );
			if ( ! is_array( $state ) ) {
				$state = array( 'failures' => 0, 'open_until' => 0 );
			}
			if ( $success ) {
				$state = array( 'failures' => 0, 'open_until' => 0 );
			} elseif ( 'permanent' !== $bucket ) {
				$state['failures'] = (int) ( $state['failures'] ?? 0 ) + 1;
				if ( ! empty( $state['half_open'] ) || $state['failures'] >= (int) $this->policy()['circuit_breaker']['failure_threshold'] ) {
					$state['open_until'] = time() + (int) $this->policy()['circuit_breaker']['recovery_timeout_seconds'];
					$state['half_open'] = false;
					$state['half_open_calls'] = 0;
				}
			}
			$this->cache_set( $state_key, $state, (int) $this->policy()['circuit_breaker']['recovery_timeout_seconds'] + 60 );

			$metric_key = $this->state_key( 'metric', $name . ':' . gmdate( 'Ymd' ) );
			$metric     = $this->cache_get( $metric_key );
			if ( ! is_array( $metric ) ) {
				$metric = array( 'total' => 0, 'success' => 0, 'errors' => 0, 'latency_ms_total' => 0, 'attempts' => 0 );
			}
			$metric['total']            = (int) $metric['total'] + 1;
			$metric['success']          = (int) $metric['success'] + ( $success ? 1 : 0 );
			$metric['errors']           = (int) $metric['errors'] + ( $success ? 0 : 1 );
			$metric['latency_ms_total'] = (int) $metric['latency_ms_total'] + max( 0, (int) round( ( microtime( true ) - $started_at ) * 1000 ) );
			$metric['attempts']         = (int) $metric['attempts'] + max( 1, $attempts );
			$this->cache_set( $metric_key, $metric, 172800 );
			if ( class_exists( 'BizCity_Twin_SLO_Store' ) ) {
				BizCity_Twin_SLO_Store::record( $name, array(
					'ok'         => $success,
					'code'       => $result['code'] ?? '',
					'bucket'     => $bucket,
					'attempts'   => $attempts,
					'latency_ms' => max( 0, (int) round( ( microtime( true ) - $started_at ) * 1000 ) ),
				) );
			}

			$dlq_codes = array( 'permission_denied', 'approval_required', 'scope_mismatch', 'tool_not_found', 'circuit_open', 'quota_exceeded' );
			if ( ! $success && ! in_array( (string) ( $result['code'] ?? '' ), $dlq_codes, true ) && ! empty( $this->policy()['dead_letter']['enabled'] ) ) {
				$this->enqueue_dead_letter( $name, $context, $result, $bucket, $attempts );
			}
		}

		/** @return array<string,mixed>|null */
		public function metrics( string $name ): ?array {
			$value = $this->cache_get( $this->state_key( 'metric', $name . ':' . gmdate( 'Ymd' ) ) );
			return is_array( $value ) ? $value : null;
		}

		private function enqueue_dead_letter( string $name, array $context, array $result, string $bucket, int $attempts ): void {
			$queue_key = $this->state_key( 'dlq', (string) $this->policy()['dead_letter']['queue_name'] );
			$queue     = $this->cache_get( $queue_key );
			if ( ! is_array( $queue ) ) {
				$queue = array();
			}
			$queue[] = array(
				'queued_at'       => gmdate( 'c' ),
				'tool'            => $name,
				'trace_id'        => (string) ( $context['trace_id'] ?? '' ),
				'idempotency_key' => (string) ( $context['idempotency_key'] ?? '' ),
				'code'            => (string) ( $result['code'] ?? '' ),
				'bucket'          => $bucket,
				'attempts'        => $attempts,
			);
			$limit = (int) $this->policy()['backpressure']['queue_limit'];
			if ( count( $queue ) > $limit ) {
				$queue = array_slice( $queue, -$limit );
			}
			$this->cache_set( $queue_key, $queue, (int) $this->policy()['dead_letter']['max_age_seconds'] );
			if ( function_exists( 'do_action' ) ) {
				do_action( 'bizcity_twin_runtime_dlq_enqueued', end( $queue ) );
			}
		}

		private function merge_policy( array $base, array $override ): array {
			foreach ( $override as $key => $value ) {
				if ( is_array( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) ) {
					$base[ $key ] = $this->merge_policy( $base[ $key ], $value );
				} elseif ( array_key_exists( $key, $base ) ) {
					$base[ $key ] = $value;
				}
			}
			return $base;
		}

		private function state_key( string $type, string $value ): string {
			$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
			return 'runtime_' . $type . '_' . $blog_id . '_' . md5( $value );
		}

		private function cache_get( string $key ) {
			if ( function_exists( 'wp_cache_get' ) ) {
				return wp_cache_get( $key, 'bizcity_twin_runtime' );
			}
			return null;
		}

		private function cache_set( string $key, $value, int $ttl ): void {
			if ( function_exists( 'wp_cache_set' ) ) {
				wp_cache_set( $key, $value, 'bizcity_twin_runtime', max( 1, $ttl ) );
			}
		}
	}
}