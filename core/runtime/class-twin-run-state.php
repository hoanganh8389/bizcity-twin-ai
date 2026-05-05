<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Runtime
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 *
 * Phase 0.13 — Task 1.13.3
 * BizCity_Twin_RunState — RunState value-object with DB persistence.
 *
 * @since 1.13.0
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Twin_RunState' ) ) return;

/**
 * BizCity Twin Run State
 *
 * Immutable-ish value object representing a single agent run.
 * Serializes to/from JSON for storage in bizcity_trace_runs.state_json.
 *
 * Status values:
 *   running       — LLM turn in progress
 *   paused_hil    — awaiting human approval (tool calls blocked)
 *   completed     — run finished, final_output set
 *   failed        — run aborted with error
 */
final class BizCity_Twin_RunState {

	/** @var string Unique run identifier e.g. "run_a1b2c3d4e5f6g7h8" */
	public $run_id;

	/** @var string Linked conversation/session identifier */
	public $conversation_id;

	/** @var string Agent name from registry */
	public $agent_name;

	/** @var string running|paused_hil|completed|failed */
	public $status;

	/** @var array<int, array> Full message history [{role, content}, ...] */
	public $messages;

	/** @var array Pending tool calls blocked for HIL approval */
	public $pending_tool_calls;

	/** @var array Interruption descriptors [{call_id, tool_name, arguments}, ...] */
	public $interruptions;

	/** @var array Arbitrary context (user_id, blog_id, overrides, ...) */
	public $context;

	/** @var string|null Final text output when status === 'completed' */
	public $final_output;

	/** @var string|null Error message when status === 'failed' */
	public $error;

	/**
	 * Parent run identifier when this run is a sub-run spawned by another
	 * agent (handoff / agent-as-tool). Null for top-level runs.
	 *
	 * @since 3.13.4 (Vòng 3)
	 * @var string|null
	 */
	public $parent_run_id;

	/* ================================================================
	 *  Construction helpers
	 * ================================================================ */

	private function __construct() {}

	/**
	 * Create a fresh RunState for a new run.
	 *
	 * @param string $agent_name  Agent to run.
	 * @param array  $ctx         Context (user_id, blog_id, ...).
	 * @return self
	 */
	public static function create( string $agent_name, array $ctx = [] ): self {
		$state                    = new self();
		$state->run_id            = 'run_' . bin2hex( self::random_bytes( 8 ) );
		$state->conversation_id   = $ctx['conversation_id'] ?? ( 'conv_' . bin2hex( self::random_bytes( 6 ) ) );
		$state->agent_name        = $agent_name;
		$state->status            = 'running';
		$state->messages          = [];
		$state->pending_tool_calls = [];
		$state->interruptions     = [];
		$state->context           = $ctx;
		$state->final_output      = null;
		$state->error             = null;
		$state->parent_run_id     = isset( $ctx['parent_run_id'] ) ? (string) $ctx['parent_run_id'] : null;
		return $state;
	}

	/**
	 * Deserialize a RunState from its JSON string representation.
	 *
	 * @param string $json
	 * @return self
	 * @throws \RuntimeException on invalid JSON.
	 */
	public static function from_string( string $json ): self {
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			throw new \RuntimeException( 'BizCity_Twin_RunState::from_string — invalid JSON' );
		}

		$state                     = new self();
		$state->run_id             = $data['run_id'] ?? '';
		$state->conversation_id    = $data['conversation_id'] ?? '';
		$state->agent_name         = $data['agent_name'] ?? '';
		$state->status             = $data['status'] ?? 'running';
		$state->messages           = (array) ( $data['messages'] ?? [] );
		$state->pending_tool_calls = (array) ( $data['pending_tool_calls'] ?? [] );
		$state->interruptions      = (array) ( $data['interruptions'] ?? [] );
		$state->context            = (array) ( $data['context'] ?? [] );
		$state->final_output       = isset( $data['final_output'] ) ? (string) $data['final_output'] : null;
		$state->error              = isset( $data['error'] ) ? (string) $data['error'] : null;
		$state->parent_run_id      = isset( $data['parent_run_id'] ) ? (string) $data['parent_run_id'] : null;
		return $state;
	}

	/**
	 * Serialize this RunState to a JSON string.
	 *
	 * @return string
	 */
	public function to_string(): string {
		return wp_json_encode( [
			'run_id'             => $this->run_id,
			'conversation_id'    => $this->conversation_id,
			'agent_name'         => $this->agent_name,
			'status'             => $this->status,
			'messages'           => $this->messages,
			'pending_tool_calls' => $this->pending_tool_calls,
			'interruptions'      => $this->interruptions,
			'context'            => $this->context,
			'final_output'       => $this->final_output,
			'error'              => $this->error,
			'parent_run_id'      => $this->parent_run_id,
		], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	/* ================================================================
	 *  HIL helpers
	 * ================================================================ */

	/**
	 * Approve a pending tool call by call_id.
	 *
	 * @param string $call_id
	 */
	public function approve( string $call_id ): void {
		$this->pending_tool_calls = array_values( array_filter(
			$this->pending_tool_calls,
			function ( $tc ) use ( $call_id ) {
				return ( $tc['call_id'] ?? '' ) !== $call_id;
			}
		) );

		$this->interruptions = array_values( array_filter(
			$this->interruptions,
			function ( $i ) use ( $call_id ) {
				return ( $i['call_id'] ?? '' ) !== $call_id;
			}
		) );
	}

	/**
	 * Reject a pending tool call by call_id.
	 *
	 * @param string      $call_id
	 * @param string|null $reason  Optional rejection message.
	 */
	public function reject( string $call_id, $reason = null ): void {
		$this->approve( $call_id ); // Remove from pending

		// Append a synthetic tool-rejection message for LLM context
		$this->messages[] = [
			'role'    => 'tool',
			'content' => $reason ?? 'Tool call rejected by user.',
		];
	}

	/**
	 * Return current interruption descriptors.
	 *
	 * @return array
	 */
	public function get_interruptions(): array {
		return $this->interruptions;
	}

	/* ================================================================
	 *  DB persistence
	 * ================================================================ */

	/**
	 * Persist this RunState to bizcity_trace_runs.
	 *
	 * Creates a new row on first call (when run doesn't exist yet) or
	 * updates an existing row.
	 */
	public function persist(): void {
		$store = BizCity_Trace_Store::instance();
		$json  = $this->to_string();

		$existing = $store->load_run( $this->run_id );

		if ( $existing === null ) {
			$store->create_run(
				$this->run_id,
				$this->conversation_id,
				$this->agent_name,
				(int) ( $this->context['user_id'] ?? 0 ),
				$json,
				[
					'context_snapshot' => $this->context,
				]
			);
		} else {
			$store->save_run_state(
				$this->run_id,
				$json,
				$this->status,
				$this->interruptions
			);
		}
	}

	/**
	 * Load a RunState from the DB by run_id.
	 *
	 * @param string $run_id
	 * @return self|null  Null if not found.
	 */
	public static function load( string $run_id ): ?self {
		$row = BizCity_Trace_Store::instance()->load_run( $run_id );
		if ( $row === null || empty( $row['state_json'] ) ) {
			return null;
		}
		return self::from_string( $row['state_json'] );
	}

	/* ================================================================
	 *  Internal helpers
	 * ================================================================ */

	/**
	 * PHP 7.4-compatible random_bytes wrapper.
	 *
	 * @param int $len
	 * @return string
	 */
	private static function random_bytes( int $len ): string {
		return function_exists( 'random_bytes' ) ? random_bytes( $len ) : openssl_random_pseudo_bytes( $len );
	}
}
