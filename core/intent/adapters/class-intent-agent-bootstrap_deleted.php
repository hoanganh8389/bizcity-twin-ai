<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Intent\Adapters
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 *
 * Phase 0.16 / Vòng 4 — Task 4.16.5
 * Register the Intent agents (root + 3 specialists) into the global
 * `BizCity_Twin_Agent_Registry` via the `bizcity_register_agent` filter.
 *
 * Filter timing (matches the Vòng 3 twin_root pattern):
 *   • Sub-agents register at priority 10 so they exist when the root agent
 *     is built.
 *   • The root agent registers at priority 20 and wraps each sub-agent via
 *     `Agent::as_tool()` to expose `transfer_to_intent_*` tools.
 *
 * Instructions live in `core/intent/instructions/*.md` and are loaded once
 * per request when the filter fires (cheap; PHP opcode cache covers the rest).
 *
 * @since 4.0.0 (Vòng 4)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Intent_Agent_Bootstrap {

	const INSTRUCTIONS_DIR = __DIR__; // overridden in init()

	public static function init(): void {
		add_filter( 'bizcity_register_agent', [ __CLASS__, 'register_sub_agents' ], 10, 1 );
		add_filter( 'bizcity_register_agent', [ __CLASS__, 'register_root_agent' ], 20, 1 );
	}

	/**
	 * Priority 10 — register the three Intent specialist agents.
	 *
	 * @param array $agents
	 * @return array
	 */
	public static function register_sub_agents( $agents ) {
		if ( ! is_array( $agents ) ) {
			$agents = [];
		}
		if ( ! class_exists( 'BizCity_TwinShell_Agent' ) ) {
			return $agents;
		}

		$dir = dirname( __DIR__ ) . '/instructions';

		// ── Knowledge sub-agent: read-only / Q&A tools ──
		$knowledge_tools = [];
		if ( class_exists( 'BizCity_Intent_Tool_Migrator' ) ) {
			$knowledge_tools = BizCity_Intent_Tool_Migrator::wrap_many( [
				'help_guide',
				'find_customer',
				'customer_stats',
				'product_stats',
				'list_orders',
				'inventory_report',
				'inventory_journal',
				'generate_report',
			] );
		}
		$agents['intent_knowledge'] = new BizCity_TwinShell_Agent(
			'intent_knowledge',
			self::load( $dir . '/knowledge.md', 'You are the BizCity knowledge assistant. Answer in Vietnamese.' ),
			$knowledge_tools
		);

		// ── Execution sub-agent: write / publish / single-action tools ──
		$execution_tools = [];
		if ( class_exists( 'BizCity_Intent_Tool_Migrator' ) ) {
			$execution_tools = BizCity_Intent_Tool_Migrator::wrap_many( [
				'post_facebook',
				'create_product',
				'edit_product',
				'create_order',
				'set_reminder',
				'warehouse_receipt',
				'publish_article',
				'publish_article_social',
			] );
		}
		$agents['intent_execution'] = new BizCity_TwinShell_Agent(
			'intent_execution',
			self::load( $dir . '/execution.md', 'You execute single-tool actions. Reply in Vietnamese.' ),
			$execution_tools
		);

		// ── Skill workflow sub-agent: multi-step / creative pipelines ──
		$skill_tools = [];
		if ( class_exists( 'BizCity_Intent_Tool_Migrator' ) ) {
			$skill_tools = BizCity_Intent_Tool_Migrator::wrap_many( [
				'write_article',
				'create_video',
				'article_to_video',
				'content_suite',
				'publish_article',
				'publish_article_social',
				'post_facebook',
			] );
		}
		$agents['intent_skill_workflow'] = new BizCity_TwinShell_Agent(
			'intent_skill_workflow',
			self::load( $dir . '/skill_workflow.md', 'You handle multi-step content workflows. Reply in Vietnamese.' ),
			$skill_tools
		);

		return $agents;
	}

	/**
	 * Priority 20 — register the root triage agent. Depends on the sub-agents
	 * being present in the filter array.
	 *
	 * @param array $agents
	 * @return array
	 */
	public static function register_root_agent( $agents ) {
		if ( ! is_array( $agents ) ) {
			$agents = [];
		}
		if ( ! class_exists( 'BizCity_TwinShell_Agent' ) ) {
			return $agents;
		}

		$tools = [];
		foreach (
			[
				'intent_knowledge'      => 'transfer_to_intent_knowledge',
				'intent_execution'      => 'transfer_to_intent_execution',
				'intent_skill_workflow' => 'transfer_to_intent_skill_workflow',
			]
			as $sub_name => $tool_name
		) {
			if ( isset( $agents[ $sub_name ] ) && $agents[ $sub_name ] instanceof BizCity_TwinShell_Agent ) {
				$tools[] = $agents[ $sub_name ]->as_tool( [
					'tool_name'   => $tool_name,
					'description' => sprintf( 'Hand off the request to the %s sub-agent and return its reply.', $sub_name ),
				] );
			}
		}

		$dir = dirname( __DIR__ ) . '/instructions';

		$agents['intent_root'] = new BizCity_TwinShell_Agent(
			'intent_root',
			self::load( $dir . '/triage.md', 'You are the Intent triage agent. Route every request to one transfer_to_* tool.' ),
			$tools
		);

		return $agents;
	}

	/**
	 * Read a file or fall back to a stub instructions string.
	 */
	private static function load( string $path, string $fallback ): string {
		if ( is_readable( $path ) ) {
			$contents = file_get_contents( $path );
			if ( is_string( $contents ) && $contents !== '' ) {
				return $contents;
			}
		}
		return $fallback;
	}
}
