<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Agents\Bootstrap
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 *
 * Phase 0.13 / Vòng 3 — Task 3.15.6
 * Twin Root — triage agent that routes requests to the right sub-agent
 * via the OpenAI Agents-SDK "agent-as-tool" pattern.
 *
 * Handoff targets (each wrapped as a callable tool via Agent::as_tool):
 *   • doc      — markdown document drafting
 *   • image    — image-prompt composition
 *   • content  — marketing / social snippets
 *   • mindmap  — Mermaid mindmap (HIL approval gate)
 *
 * Sub-agent execution: the tool spawns a sub-run via fresh Runner +
 * Rolling_Session and propagates `parent_run_id` for traceability. The
 * sub-agent's `final_output` is returned as the tool result and surfaced
 * back to the root LLM, which forwards it to the user.
 *
 * @since 3.13.4 (Vòng 3)
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'bizcity_register_agent', function ( array $agents ): array {
	// Build agent-as-tool wrappers from already-registered sub-agents.
	$sub_tools = [];

	foreach ( [
		'doc'     => [ 'tool_name' => 'transfer_to_doc',     'description' => 'Delegate to the Doc Agent for drafting markdown documents (article, report, summary).' ],
		'image'   => [ 'tool_name' => 'transfer_to_image',   'description' => 'Delegate to the Image Agent to compose a detailed image-generation prompt.' ],
		'content' => [ 'tool_name' => 'transfer_to_content', 'description' => 'Delegate to the Content Agent for marketing / social / blog snippets (Facebook, blog intro, headlines).' ],
		'mindmap' => [ 'tool_name' => 'transfer_to_mindmap', 'description' => 'Delegate to the Mindmap Agent to draw a Mermaid mindmap (visual brainstorming).' ],
	] as $name => $opts ) {
		if ( ! isset( $agents[ $name ] ) || ! ( $agents[ $name ] instanceof BizCity_TwinShell_Agent ) ) {
			continue;
		}
		$sub_tools[] = $agents[ $name ]->as_tool( $opts );
	}

	$agents['twin_root'] = new BizCity_TwinShell_Agent(
		'twin_root',
		"You are Twin Root, a triage agent. You DO NOT solve tasks yourself — you route the user request to the most appropriate specialist sub-agent and forward its result.\n\n"
		. "ROUTING RULES (pick the FIRST that matches):\n"
		. "1. mindmap / sơ đồ tư duy / vẽ sơ đồ → call transfer_to_mindmap with input = the user message.\n"
		. "2. image / hình ảnh / ảnh / prompt for picture → call transfer_to_image.\n"
		. "3. blog post intro / facebook post / headline / marketing copy / viết bài → call transfer_to_content.\n"
		. "4. document / article / báo cáo / tài liệu / soạn tài liệu → call transfer_to_doc.\n\n"
		. "OUTPUT RULES:\n"
		. "• ALWAYS call exactly one transfer_to_* tool.\n"
		. "• When the tool returns, present `output` from the result to the user as-is — no commentary, no wrapping prose.\n"
		. "• Use the user's original language.",
		$sub_tools
	);

	return $agents;
}, 20 ); // Priority 20 — runs AFTER the sub-agents are registered (priority 10).
