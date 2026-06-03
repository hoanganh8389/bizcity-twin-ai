<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Cron
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 *
 * BizCity_Cron_MCP — MCP-ready tool facade.
 *
 * Exposes 3 callable tools to the TwinBrain agent layer:
 *
 *   cron.list_jobs()           → array of jobs (registry + health).
 *   cron.run_one(job_id)       → invoke a job synchronously, return result.
 *   cron.list_retries()        → pending + dead retry rows.
 *
 * The actual MCP bridge picks these up via the
 * `bizcity_mcp_register_tools` filter. Until the bridge ships, these are
 * directly callable PHP methods (same signature as REST).
 *
 * R-GW-8: this class lives client-side and NEVER calls bizcity-llm-router.
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Cron_MCP {

	public static function register(): void {
		add_filter( 'bizcity_mcp_register_tools', [ __CLASS__, 'register_tools' ], 10, 1 );
	}

	/**
	 * Tool descriptors for the MCP bridge.
	 *
	 * @param array $tools Existing tool list.
	 * @return array
	 */
	public static function register_tools( $tools ): array {
		$tools = is_array( $tools ) ? $tools : [];

		$tools[] = [
			'name'        => 'cron.list_jobs',
			'description' => 'List all cron jobs registered with BizCity_Cron_Manager (job_id, hook, next/last run, last status). Use to diagnose scheduling.',
			'input_schema' => [
				'type' => 'object',
				'properties' => new \stdClass(),
				'additionalProperties' => false,
			],
			'handler'     => [ __CLASS__, 'tool_list_jobs' ],
		];

		$tools[] = [
			'name'        => 'cron.run_one',
			'description' => 'Run a registered cron job NOW (synchronous). On failure, the job is queued into the retry table with exponential backoff.',
			'input_schema' => [
				'type' => 'object',
				'properties' => [
					'job_id' => [ 'type' => 'string', 'description' => 'job_id from cron.list_jobs (e.g. scheduler.reminder.scan).' ],
				],
				'required' => [ 'job_id' ],
				'additionalProperties' => false,
			],
			'handler'     => [ __CLASS__, 'tool_run_one' ],
		];

		$tools[] = [
			'name'        => 'cron.list_retries',
			'description' => 'List pending/dead retries from the cron retry queue.',
			'input_schema' => [
				'type' => 'object',
				'properties' => new \stdClass(),
				'additionalProperties' => false,
			],
			'handler'     => [ __CLASS__, 'tool_list_retries' ],
		];

		return $tools;
	}

	public static function tool_list_jobs( array $args = [] ): array {
		return [ 'ok' => true, 'jobs' => BizCity_Cron_Manager::instance()->all() ];
	}

	public static function tool_run_one( array $args = [] ): array {
		$job_id = isset( $args['job_id'] ) ? (string) $args['job_id'] : '';
		if ( $job_id === '' ) {
			return [ 'ok' => false, 'error' => 'missing_job_id' ];
		}
		return BizCity_Cron_Manager::instance()->run_now( $job_id );
	}

	public static function tool_list_retries( array $args = [] ): array {
		global $wpdb;
		$t = $wpdb->prefix . BizCity_Cron_Manager::TABLE_RETRIES;
		$wpdb->suppress_errors( true );
		$rows = (array) $wpdb->get_results(
			"SELECT job_id, attempt, status, next_run_at, last_error FROM {$t} WHERE status IN ('pending','dead') ORDER BY next_run_at ASC LIMIT 100",
			ARRAY_A
		);
		$wpdb->suppress_errors( false );
		return [ 'ok' => true, 'retries' => $rows ];
	}
}
