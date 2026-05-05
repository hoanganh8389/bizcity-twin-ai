<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Agents\Bootstrap
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 *
 * Phase 0.15 — Task 1.15.4
 * Register the built-in "echo" agent for Vòng 1 smoke tests.
 *
 * @since 1.13.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Echo Agent
 *
 * A minimal agent with no tools that simply echoes the user's message back.
 * Used exclusively for Vòng 1 integration smoke tests:
 *
 *   curl -X POST /wp-json/bizcity-twin/v1/run \
 *     -d '{"agent_name":"echo","messages":[{"role":"user","content":"hello"}]}'
 *
 * Expected response: { "status": "completed", "final_output": "echo: hello" }
 *
 * Remove or disable once real agents are registered.
 */
add_filter( 'bizcity_register_agent', function ( array $agents ): array {
	$agents['echo'] = new BizCity_TwinShell_Agent(
		'echo',
		// System instructions — the LLM is told to just echo
		'You are an echo agent. Repeat the user\'s message back to them exactly, prefixed with "echo: ".',
		[], // no tools
		[], // no handoffs
		null // use platform default model
	);
	return $agents;
} );
