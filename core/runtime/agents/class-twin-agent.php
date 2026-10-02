<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Agents
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 *
 * Phase 0.15 — Task 1.15.1
 * BizCity_Twin_Agent — Agent definition value object.
 *
 * @since 1.13.0
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_TwinShell_Agent' ) ) return;

/**
 * BizCity Twin Agent
 *
 * Value object that describes a named agent: its system instructions,
 * available tools, and permitted handoff targets. The Runner resolves an
 * agent by name from BizCity_Twin_Agent_Registry and uses this object to
 * build the LLM system prompt and tool list.
 *
 * Vòng 1: tools and handoffs are stored but not executed (no tool loop yet).
 * Vòng 2: tool execution loop added in BizCity_Twin_Runner.
 * Vòng 3: handoff expansion.
 */
final class BizCity_TwinShell_Agent {

	/** @var string Agent identifier — snake_case e.g. "echo", "product_advisor" */
	public $name;

	/** @var string System instructions for the LLM */
	public $instructions;

	/** @var BizCity_TwinShell_Tool[]  Available tools (empty for Vòng 1 echo agent) */
	public $tools;

	/** @var string[]  Names of agents this agent may hand off to */
	public $handoffs;

	/** @var string|null  LLM model override; null = use platform default */
	public $model;

	/**
	 * @param string                       $name
	 * @param string                       $instructions
	 * @param BizCity_TwinShell_Tool[]     $tools     (optional)
	 * @param string[]                     $handoffs  (optional)
	 * @param string|null                  $model     (optional)
	 */
	public function __construct(
		string $name,
		string $instructions,
		array $tools = [],
		array $handoffs = [],
		$model = null
	) {
		$this->name         = $name;
		$this->instructions = $instructions;
		$this->tools        = $tools;
		$this->handoffs     = $handoffs;
		$this->model        = $model;
	}

	/**
	 * Return enabled tools for a given context.
	 *
	 * @param array $ctx
	 * @return BizCity_TwinShell_Tool[]
	 */
	public function get_enabled_tools( array $ctx = [] ): array {
		return array_values( array_filter(
			$this->tools,
			function ( BizCity_TwinShell_Tool $tool ) use ( $ctx ) {
				return $tool->is_enabled( $ctx );
			}
		) );
	}

	/**
	 * Return OpenAI-compatible tools array for enabled tools.
	 *
	 * @param array $ctx
	 * @return array
	 */
	public function get_tools_spec( array $ctx = [] ): array {
		return array_map(
			function ( BizCity_TwinShell_Tool $tool ) {
				return $tool->to_function_spec();
			},
			$this->get_enabled_tools( $ctx )
		);
	}

	/**
	 * Wrap this agent into a callable tool so a parent agent may invoke it.
	 *
	 * Phase 0.13 / Vòng 3 — Task 3.15.2.
	 *
	 * Implements OpenAI Agents-SDK "agent-as-tool" pattern. When the parent LLM
	 * calls this tool with `{ "input": "..." }`, the callback spawns a sub-run
	 * via a fresh BizCity_Twin_Runner instance using the global Agent_Registry
	 * + a new in-memory Rolling_Session. The sub-run inherits `parent_run_id`
	 * from the calling context and returns its `final_output` to the parent.
	 *
	 * @param array{
	 *   tool_name?:string,
	 *   description?:string,
	 *   parameters_schema?:array,
	 * } $opts
	 * @return BizCity_TwinShell_Tool
	 */
	public function as_tool( array $opts = [] ): BizCity_TwinShell_Tool {
		$tool_name        = isset( $opts['tool_name'] ) && $opts['tool_name'] !== ''
			? (string) $opts['tool_name']
			: 'call_' . $this->name;
		$description      = isset( $opts['description'] ) && $opts['description'] !== ''
			? (string) $opts['description']
			: sprintf( 'Delegate the task to the %s agent and return its result.', $this->name );
		$schema           = isset( $opts['parameters_schema'] ) && is_array( $opts['parameters_schema'] )
			? $opts['parameters_schema']
			: [
				'type'       => 'object',
				'properties' => [
					'input' => [
						'type'        => 'string',
						'description' => 'The task or question to forward to the sub-agent.',
					],
				],
				'required'   => [ 'input' ],
			];

		$target_agent_name = $this->name;

		$callback = function ( array $args, array $ctx ) use ( $target_agent_name ) {
			$input = isset( $args['input'] ) ? (string) $args['input'] : '';
			if ( $input === '' ) {
				return [ 'error' => 'as_tool: empty input' ];
			}

			if ( ! class_exists( 'BizCity_Twin_Agent_Registry' )
				|| ! class_exists( 'BizCity_Twin_Runner' )
				|| ! class_exists( 'BizCity_Twin_Rolling_Session' ) ) {
				return [ 'error' => 'as_tool: runtime classes missing' ];
			}

			$registry = BizCity_Twin_Agent_Registry::instance();
			$session  = new BizCity_Twin_Rolling_Session();
			$runner   = new BizCity_Twin_Runner( $registry, $session );

			$parent_run_id = isset( $ctx['run_id'] ) ? (string) $ctx['run_id'] : '';

			$sub_ctx = array_merge( $ctx, [
				'parent_run_id' => $parent_run_id !== '' ? $parent_run_id : null,
			] );
			// Strip parent-scoped controls so the sub-run starts fresh.
			unset( $sub_ctx['run_id'], $sub_ctx['decisions'] );

			$state = $runner->run( $target_agent_name, $input, $sub_ctx );

			// PHASE-6.4 BUG-FIX (May 2026) — Canvas auto-open across agent-as-tool
			// boundary. When a sub-agent (e.g. doc) calls a real tool (e.g.
			// `draft_document`) that returns `artifact_created`, the runner emits
			// `tool_executed` on the SUB-run's event stream — which the FE never
			// polls (it only polls the parent run_id, plus a fixed list of known
			// sub-agent names via TwinHilCards. Even those subscribe to the
			// CHILD's stream, but only AFTER `subagent_started` lands; tight
			// races + the fact that the artifact event fires INSIDE the sub-run
			// before subagent_completed mean the parent FE may never see it).
			//
			// Fix: walk sub-run messages for any tool result containing
			// `artifact_created` and re-emit a `tool_executed` event into the
			// PARENT run stream. Existing FE handler in agentRuntimeStore.ts
			// for `tool_executed` already opens Canvas idempotently (guarded
			// by AUTO_OPENED_ARTIFACTS set keyed by plugin_name:studio_id).
			$bubbled_artifact = null;
			if ( is_array( $state->messages ) ) {
				foreach ( $state->messages as $msg ) {
					if ( ( $msg['role'] ?? '' ) !== 'tool' ) continue;
					$payload = isset( $msg['content'] ) ? json_decode( (string) $msg['content'], true ) : null;
					if ( ! is_array( $payload ) ) continue;
					if ( ! empty( $payload['artifact_created'] ) && is_array( $payload['artifact_created'] ) ) {
						$bubbled_artifact = $payload['artifact_created']; // last-wins
					}
				}
			}

			// Vòng 3 / Sprint 6 — Canvas activation bridge.
			// Emit `subagent_started` + `subagent_completed` into the PARENT run
			// stream so the FE (which polls parent run_id) can flip the child
			// agent's running flag, set its lastRunId to the sub-run, and surface
			// the final output. The child's self-gating panel opens automatically
			// because `running` becomes true (hasActivity=true).
			if ( $parent_run_id !== '' && class_exists( 'BizCity_TwinShell_Event_Bus' ) ) {
				BizCity_TwinShell_Event_Bus::emit(
					$parent_run_id,
					BizCity_TwinShell_Event_Bus::TYPE_SUBAGENT_STARTED,
					[
						'agent_name' => $target_agent_name,
						'sub_run_id' => $state->run_id,
						'input'      => $input,
					]
				);
				// PHASE-6.4 — Bubble the sub-agent's artifact to the parent stream
				// BEFORE subagent_completed so FE handler runs while the run
				// slice is still considered active.
				if ( $bubbled_artifact !== null ) {
					BizCity_TwinShell_Event_Bus::emit(
						$parent_run_id,
						BizCity_TwinShell_Event_Bus::TYPE_TOOL_EXECUTED,
						[
							'call_id'          => 'subagent_' . $state->run_id,
							'tool_name'        => 'transfer_to_' . $target_agent_name,
							'result_preview'   => '[bubbled artifact from sub-agent ' . $target_agent_name . ']',
							'artifact_created' => $bubbled_artifact,
						]
					);
				}
				BizCity_TwinShell_Event_Bus::emit(
					$parent_run_id,
					BizCity_TwinShell_Event_Bus::TYPE_SUBAGENT_COMPLETED,
					[
						'agent_name' => $target_agent_name,
						'sub_run_id' => $state->run_id,
						'status'     => $state->status,
						'output'     => is_string( $state->final_output ) ? $state->final_output : '',
						'error'      => $state->error,
					]
				);
			}

			// Vòng 3 / Sprint 7 hotfix — when the sub-run pauses for HIL approval,
			// surface an explicit instruction so the parent LLM does NOT keep
			// re-calling this tool (which would spawn another sub-run + another
			// approval card on every parent iteration, looping the canvas).
			// The user is now interacting with the sub-agent's panel directly;
			// the parent should yield a short acknowledgement and stop.
			if ( $state->status === 'paused_hil' ) {
				return [
					'sub_run_id'  => $state->run_id,
					'status'      => 'paused_hil',
					'output'      => sprintf(
						'Đã chuyển task sang **%s** — đang chờ bạn phê duyệt ở khung Canvas bên phải.',
						$target_agent_name
					),
					'error'       => null,
					'_terminal'   => true,
				];
			}

			$result = [
				'sub_run_id' => $state->run_id,
				'status'     => $state->status,
				'output'     => is_string( $state->final_output ) ? $state->final_output : '',
				'error'      => $state->error,
			];
			// PHASE-6.4 BUG-FIX — surface bubbled artifact in tool result too,
			// so the parent runner's TYPE_TOOL_EXECUTED emission (which sees
			// THIS callback's return value via $tool_result['artifact_created'])
			// also pushes it onto the parent stream. Redundant with the direct
			// emit above but covers any FE/event-bus race.
			if ( $bubbled_artifact !== null ) {
				$result['artifact_created'] = $bubbled_artifact;
			}
			return $result;
		};

		return new BizCity_TwinShell_Tool(
			$tool_name,
			$description,
			$schema,
			$callback
		);
	}
}
