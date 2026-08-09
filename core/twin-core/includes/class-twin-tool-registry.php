<?php
/**
 * Bizcity Twin AI — Twin Tool Registry
 *
 * Sprint 4.7a — Singleton registry cho mọi `BizCity_Twin_Tool`. Plugin đăng ký
 * tool qua filter `bizcity_twin_register_tool`. Twin_Agent_Loop lấy danh sách
 * tool theo subset cho phép từ caller.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Twin_Core
 * @since 2026-04-26
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( ! interface_exists( 'BizCity_Twin_Tool' ) ) {
	require_once __DIR__ . '/interface-twin-tool.php';
}

class BizCity_Twin_Tool_Registry {

	/** @var BizCity_Twin_Tool_Registry|null */
	private static $instance = null;

	/** @var array<string, BizCity_Twin_Tool> */
	private $tools = [];

	/** @var bool */
	private $loaded = false;

	/** @var array<string,array<string,mixed>> Request-local idempotency results. */
	private $idempotency_results = [];

	private function __construct() {}

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Lazy-load: gọi filter lần đầu khi cần.
	 */
	private function ensure_loaded(): void {
		if ( $this->loaded ) {
			return;
		}
		$this->loaded = true;

		/**
		 * Plugin add tool:
		 *   add_filter( 'bizcity_twin_register_tool', function( $registry ) {
		 *       $registry['my_tool'] = new My_Tool();
		 *       return $registry;
		 *   } );
		 *
		 * Registry trả về: array<string, BizCity_Twin_Tool>
		 */
		$external = apply_filters( 'bizcity_twin_register_tool', [] );
		if ( is_array( $external ) ) {
			foreach ( $external as $name => $tool ) {
				if ( $tool instanceof BizCity_Twin_Tool ) {
					$this->tools[ (string) $tool->name() ] = $tool;
				}
			}
		}
	}

	/**
	 * Đăng ký programmatic (không qua filter).
	 */
	public function register( BizCity_Twin_Tool $tool ): void {
		$this->ensure_loaded();
		$this->tools[ $tool->name() ] = $tool;
	}

	public function get( string $name ): ?BizCity_Twin_Tool {
		$this->ensure_loaded();
		return $this->tools[ $name ] ?? null;
	}

	/**
	 * Execute a tool through the framework security boundary.
	 *
	 * Existing tools continue to implement only execute(); permission metadata
	 * is optional and can be supplied by the registry filters.
	 *
	 * @param string               $name
	 * @param array<string,mixed>  $args
	 * @param array<string,mixed>  $context
	 * @return array<string,mixed>
	 */
	public function execute( string $name, array $args, array $context = [] ): array {
		// [2026-07-30 Johnny Chu] PHASE-1.22-SEC — single guarded tool boundary.
		$started_at  = microtime( true );
		$tool        = $this->get( $name );
		$trace_id    = isset( $context['trace_id'] ) ? (string) $context['trace_id'] : $this->new_execution_id( 'trace' );
		$reliability = class_exists( 'BizCity_Twin_Runtime_Reliability' ) ? BizCity_Twin_Runtime_Reliability::instance() : null;
		$idempotency = isset( $context['idempotency_key'] )
			? (string) $context['idempotency_key']
			: 'twin_' . substr( hash( 'sha256', $trace_id . '|' . $name . '|' . wp_json_encode( $args ) ), 0, 32 );
		$context['trace_id']        = $trace_id;
		$context['idempotency_key'] = $idempotency;
		$extension_id = isset( $context['extension_id'] ) ? (string) $context['extension_id'] : '';
		if ( '' !== $extension_id && class_exists( 'BizCity_Twin_Capability_Consent' ) && BizCity_Twin_Capability_Consent::has_manifest( $extension_id ) ) {
			// [2026-07-30 Johnny Chu] PHASE-1.22-SEC — manifest security policy is authoritative for extension execution.
			$security = BizCity_Twin_Capability_Consent::security_for( $extension_id );
			$context['network_policy'] = isset( $security['network_policy'] ) && is_array( $security['network_policy'] ) ? $security['network_policy'] : array();
			$context['upload_policy']  = isset( $security['upload_policy'] ) && is_array( $security['upload_policy'] ) ? $security['upload_policy'] : array();
		}

		// [2026-07-30 Johnny Chu] PHASE-1.22-RUNTIME — replay protection before side effects.
		$replayed = $this->get_idempotency_result( $idempotency );
		if ( is_array( $replayed ) ) {
			$replayed['idempotency_replayed'] = true;
			return $replayed;
		}
		if ( ! $this->acquire_execution_lock( $idempotency ) ) {
			return array(
				'ok'               => false,
				'error'            => 'Tool execution is already in progress.',
				'code'             => 'execution_locked',
				'retriable'        => true,
				'trace_id'         => $trace_id,
				'idempotency_key'  => $idempotency,
			);
		}

		if ( null === $tool ) {
			$result = array(
				'ok'    => false,
				'error' => 'Tool "' . $name . '" not registered.',
				'code'  => 'tool_not_found',
			);
			// [2026-07-30 Johnny Chu] PHASE-1.22-RUNTIME — correlate early tool lookup failures.
			$result['trace_id']        = $trace_id;
			$result['idempotency_key'] = $idempotency;
			$this->audit_execution( $name, $context, $result, $started_at, '' );
			$this->release_execution_lock( $idempotency );
			return $result;
		}

		$decision = class_exists( 'BizCity_Twin_Capability_Guard' )
			? BizCity_Twin_Capability_Guard::authorize( $name, $tool, $context )
			: array( 'allowed' => true, 'permission' => '', 'approval_gate' => '' );
		if ( empty( $decision['allowed'] ) ) {
			$result = array(
				'ok'          => false,
				'error'       => (string) ( $decision['message'] ?? 'Tool permission denied.' ),
				'code'        => (string) ( $decision['code'] ?? 'permission_denied' ),
				'hint'        => (string) ( $decision['hint'] ?? '' ),
				'help_code'   => (string) ( $decision['help_code'] ?? 'permission_required' ),
				'permission'  => (string) ( $decision['permission'] ?? '' ),
			);
			// [2026-07-30 Johnny Chu] PHASE-1.22-RUNTIME — preserve correlation on authorization failures.
			$result['trace_id']        = $trace_id;
			$result['idempotency_key'] = $idempotency;
			$this->audit_execution( $name, $context, $result, $started_at, (string) ( $decision['permission'] ?? '' ) );
			$this->release_execution_lock( $idempotency );
			return $result;
		}

		if ( $reliability ) {
			$budget_ms = (int) ( $reliability->policy()['timeout_budget']['default_ms'] ?? 15000 );
			$context['trace_headers'] = (array) ( $reliability->policy()['trace']['propagate_headers'] ?? array() );
			$context['deadline_at'] = isset( $context['deadline_at'] )
				? (float) $context['deadline_at']
				: microtime( true ) + ( max( 1, $budget_ms ) / 1000 );
			$runtime_gate = $reliability->before_execution( $name, $context );
			if ( empty( $runtime_gate['allowed'] ) ) {
				$result = array(
					'ok'              => false,
					'error'           => (string) ( $runtime_gate['message'] ?? 'Runtime execution is temporarily unavailable.' ),
					'code'            => (string) ( $runtime_gate['code'] ?? 'runtime_rejected' ),
					'hint'            => 'Thử lại sau khi runtime ổn định.',
					'help_code'       => 'runtime_execution_retry',
					'retriable'       => true,
					'retry_after_ms'  => (int) ( $runtime_gate['retry_after_ms'] ?? 0 ),
				);
				// [2026-07-30 Johnny Chu] PHASE-1.22-RUNTIME — preserve correlation on quota/circuit rejection.
				$result['trace_id']        = $trace_id;
				$result['idempotency_key'] = $idempotency;
				$this->audit_execution( $name, $context, $result, $started_at, (string) ( $decision['permission'] ?? '' ) );
				$this->release_execution_lock( $idempotency );
				return $result;
			}
		}

		$attempts = 0;
		do {
			$attempts++;
			if ( $reliability && isset( $context['deadline_at'] ) && microtime( true ) >= (float) $context['deadline_at'] ) {
				$result = array( 'ok' => false, 'error' => 'Tool execution exceeded its time budget.', 'code' => 'timeout', 'retriable' => true );
				break;
			}

			try {
				$result = $tool->execute( $args, $context );
				if ( ! is_array( $result ) ) {
					$result = array( 'ok' => false, 'error' => 'Tool returned an invalid result.', 'code' => 'invalid_tool_result' );
				}
			} catch ( \Throwable $exception ) {
				$result = array(
					'ok'        => false,
					'error'     => 'Tool execution failed.',
					'code'      => 'tool_execution_failed',
					'retriable' => true,
				);
				error_log( '[bizcity-twin] tool execution failed: ' . $exception->getMessage() );
			}

			if ( ! $reliability ) {
				break;
			}
			$bucket = $reliability->classify_result( $result );
			if ( ! $reliability->should_retry( $result, $bucket, $attempts, $context ) ) {
				break;
			}
			$delay_ms = $reliability->backoff_ms( $bucket, $attempts );
			if ( $delay_ms > 0 ) {
				$remaining_ms = max( 0, (int) floor( ( (float) $context['deadline_at'] - microtime( true ) ) * 1000 ) );
				usleep( min( $delay_ms, $remaining_ms ) * 1000 );
			}
		} while ( $reliability && $attempts < $reliability->max_attempts( $bucket ) );

		$result['trace_id']        = $trace_id;
		$result['idempotency_key'] = $idempotency;
		$result['attempts']        = $attempts;
		if ( $reliability ) {
			// [2026-07-30 Johnny Chu] PHASE-1.22-RUNTIME — persist metrics, breaker state, and exhausted failures.
			$reliability->record_outcome( $name, $context, $result, $attempts, $started_at );
		}
		$this->audit_execution( $name, $context, $result, $started_at, (string) ( $decision['permission'] ?? '' ) );
		$this->release_execution_lock( $idempotency );
		if ( $this->should_store_idempotency( $result ) ) {
			$this->store_idempotency_result( $idempotency, $result );
		}
		return $result;
	}

	private function acquire_execution_lock( string $key ): bool {
		if ( ! function_exists( 'wp_cache_add' ) ) {
			return true;
		}
		return (bool) wp_cache_add( 'lock_' . md5( $key ), microtime( true ), 'bizcity_twin_runtime', 60 );
	}

	private function release_execution_lock( string $key ): void {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( 'lock_' . md5( $key ), 'bizcity_twin_runtime' );
		}
	}

	private function should_store_idempotency( array $result ): bool {
		return ! in_array( (string) ( $result['code'] ?? '' ), array( 'permission_denied', 'approval_required', 'scope_mismatch', 'tool_not_found' ), true );
	}

	private function get_idempotency_result( string $key ): ?array {
		if ( isset( $this->idempotency_results[ $key ] ) ) {
			return $this->idempotency_results[ $key ];
		}
		if ( function_exists( 'wp_cache_get' ) ) {
			$cached = wp_cache_get( 'result_' . md5( $key ), 'bizcity_twin_runtime' );
			if ( is_array( $cached ) ) {
				$this->idempotency_results[ $key ] = $cached;
				return $cached;
			}
		}
		return null;
	}

	private function store_idempotency_result( string $key, array $result ): void {
		$this->idempotency_results[ $key ] = $result;
		if ( function_exists( 'wp_cache_set' ) ) {
			$ttl = defined( 'HOUR_IN_SECONDS' ) ? HOUR_IN_SECONDS : 3600;
			wp_cache_set( 'result_' . md5( $key ), $result, 'bizcity_twin_runtime', $ttl );
		}
	}

	private function audit_execution( string $name, array $context, array $result, float $started_at, string $permission ): void {
		if ( ! class_exists( 'BizCity_Twin_Runtime_Audit' ) ) {
			return;
		}
		BizCity_Twin_Runtime_Audit::record( 'tool_execution', array(
			'trace_id'        => (string) ( $context['trace_id'] ?? '' ),
			'idempotency_key' => (string) ( $context['idempotency_key'] ?? '' ),
			'user_id'         => (int) ( $context['user_id'] ?? 0 ),
			'session_id'      => (string) ( $context['session_id'] ?? '' ),
			'tool'            => $name,
			'permission'      => $permission,
			'status'          => ! empty( $result['ok'] ) ? 'success' : 'denied_or_error',
			'code'            => (string) ( $result['code'] ?? '' ),
			'duration_ms'     => max( 0, (int) round( ( microtime( true ) - $started_at ) * 1000 ) ),
			'arg_count'       => (int) ( $context['arg_count'] ?? 0 ),
		) );
	}

	private function new_execution_id( string $prefix ): string {
		$random = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : sha1( uniqid( '', true ) );
		return $prefix . '_' . $random;
	}

	/**
	 * Lấy tất cả tool. Nếu truyền $allowed (whitelist) thì chỉ trả subset.
	 *
	 * @param string[]|null $allowed
	 * @return array<string, BizCity_Twin_Tool>
	 */
	public function get_all( ?array $allowed = null ): array {
		$this->ensure_loaded();
		if ( null === $allowed ) {
			return $this->tools;
		}
		$out = [];
		foreach ( $allowed as $name ) {
			if ( isset( $this->tools[ $name ] ) ) {
				$out[ $name ] = $this->tools[ $name ];
			}
		}
		return $out;
	}

	/**
	 * Render danh sách tool thành đoạn system-prompt cho LLM.
	 *
	 * Format này LLM-agnostic — work với mọi provider (OpenAI, Anthropic, Gemini,
	 * Ollama). LLM được hướng dẫn output `<tool name="x">{json args}</tool>`.
	 *
	 * @param string[]|null $allowed
	 */
	public function render_prompt_section( ?array $allowed = null ): string {
		$tools = $this->get_all( $allowed );
		if ( empty( $tools ) ) {
			return '';
		}

		$lines   = [];
		$lines[] = '## AVAILABLE TOOLS';
		$lines[] = 'You can call these tools to gather information BEFORE answering. Each tool returns JSON.';
		$lines[] = '';
		$lines[] = 'TO CALL A TOOL: output EXACTLY this format on its own line and STOP:';
		$lines[] = '<tool name="TOOL_NAME">{"arg1":"value","arg2":123}</tool>';
		$lines[] = '';
		$lines[] = 'Rules:';
		$lines[] = '- Only ONE tool call per response. After STOP, the system runs the tool and replies with results.';
		$lines[] = '- If you have enough information, DO NOT call any tool — write the final answer directly.';
		$lines[] = '- Maximum 3 tool calls per conversation. Plan accordingly.';
		$lines[] = '- Tool args MUST be a single-line valid JSON object.';
		$lines[] = '';
		$lines[] = '### Tool catalogue:';

		foreach ( $tools as $tool ) {
			$schema_json = wp_json_encode( $tool->parameters_schema(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			$lines[]     = '';
			$lines[]     = '#### ' . $tool->name();
			$lines[]     = $tool->description();
			$lines[]     = 'Schema: ' . $schema_json;
		}

		$lines[] = '';
		$lines[] = '### Example tool call:';
		$lines[] = '<tool name="search_kg">{"query":"founder of BizCity","top_k":3}</tool>';
		$lines[] = '';

		return implode( "\n", $lines );
	}

	/**
	 * Parse LLM output để trích tool call. Trả về NULL nếu không có.
	 *
	 * Match: `<tool name="xxx">{...json...}</tool>` (multi-line JSON OK).
	 *
	 * @return array{name:string,args:array,raw:string}|null
	 */
	public static function parse_tool_call( string $llm_output ): ?array {
		if ( false === strpos( $llm_output, '<tool' ) ) {
			return null;
		}
		// Greedy không tốt — dùng non-greedy, tôn trọng newline trong JSON.
		if ( ! preg_match( '#<tool\s+name=["\']([a-z0-9_]+)["\']\s*>(.*?)</tool>#is', $llm_output, $m ) ) {
			return null;
		}
		$name    = strtolower( trim( $m[1] ) );
		$raw_arg = trim( $m[2] );
		$args    = [];
		if ( '' !== $raw_arg ) {
			$decoded = json_decode( $raw_arg, true );
			if ( is_array( $decoded ) ) {
				$args = $decoded;
			}
		}
		return [
			'name' => $name,
			'args' => $args,
			'raw'  => $m[0],
		];
	}
}
