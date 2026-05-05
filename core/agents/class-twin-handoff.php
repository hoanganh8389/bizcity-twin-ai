<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Agents
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 *
 * Phase 0.13 — Vòng 3 — Task 3.15.1
 * BizCity_TwinShell_Handoff — handoff descriptor (transfer to another agent).
 *
 * Mirrors OpenAI Agents SDK pattern: a handoff is a special tool that, when
 * invoked, transfers control from the current agent to a target agent. The
 * Runner can choose to either:
 *   (a) Spawn a sub-run (target inherits parent_run_id) and inject the result
 *       back as a tool message — implemented via Agent::as_tool() in 3.15.2.
 *   (b) Replace the active agent in-place mid-run (true handoff) — Runner
 *       integration left for future iteration.
 *
 * For Vòng 3 sprint 1 we expose the metadata only; consumers wire it up via
 * Agent::as_tool() so handoff = "agent-as-tool" semantics.
 *
 * @since 3.13.4 (Vòng 3)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_TwinShell_Handoff' ) ) return;

final class BizCity_TwinShell_Handoff {

	/** @var string Target agent name (must resolve via Agent_Registry) */
	public $target_agent;

	/** @var string Tool name surfaced to the LLM, default "transfer_to_<target>" */
	public $tool_name;

	/** @var string Description shown to the calling LLM */
	public $description;

	/**
	 * Optional callable to filter the input message history before forwarding
	 * it to the sub-agent. Signature: function(array $messages): array
	 *
	 * @var callable|null
	 */
	private $input_history_filter;

	/**
	 * Optional template returned to the calling LLM when the handoff fires.
	 * Use `{result}` placeholder to inject sub-agent final_output.
	 *
	 * @var string|null
	 */
	private $transfer_message_template;

	/**
	 * @param string        $target_agent
	 * @param array{
	 *   tool_name?:string,
	 *   description?:string,
	 *   input_history_filter?:callable|null,
	 *   transfer_message?:string|null,
	 * } $opts
	 */
	public function __construct( string $target_agent, array $opts = [] ) {
		$this->target_agent              = $target_agent;
		$this->tool_name                 = isset( $opts['tool_name'] ) && $opts['tool_name'] !== ''
			? (string) $opts['tool_name']
			: 'transfer_to_' . $target_agent;
		$this->description               = isset( $opts['description'] ) && $opts['description'] !== ''
			? (string) $opts['description']
			: sprintf( 'Hand off the conversation to the %s agent.', $target_agent );
		$this->input_history_filter      = isset( $opts['input_history_filter'] ) && is_callable( $opts['input_history_filter'] )
			? $opts['input_history_filter']
			: null;
		$this->transfer_message_template = isset( $opts['transfer_message'] ) ? (string) $opts['transfer_message'] : null;
	}

	/**
	 * Apply optional history filter; returns messages unchanged when none set.
	 *
	 * @param array $messages
	 * @return array
	 */
	public function filter_history( array $messages ): array {
		if ( $this->input_history_filter === null ) return $messages;
		$out = call_user_func( $this->input_history_filter, $messages );
		return is_array( $out ) ? $out : $messages;
	}

	/**
	 * Render the transfer message returned to the calling LLM.
	 *
	 * @param string $sub_result
	 * @return string
	 */
	public function get_transfer_message( string $sub_result ): string {
		if ( $this->transfer_message_template === null ) return $sub_result;
		return str_replace( '{result}', $sub_result, $this->transfer_message_template );
	}
}
