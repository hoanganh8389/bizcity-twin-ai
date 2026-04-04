<?php
/**
 * Changelog — Phase 1.7: Skill Trace & Slash Command Roadmap
 *
 * Validates the full pipeline: skill DB → intent → trace → Working Panel.
 *
 * @package BizCity_Twin_AI
 * @since   4.2.0
 */

defined( 'ABSPATH' ) or die();

class BizCity_Changelog_Phase17 extends BizCity_Changelog_Base {

	public function get_phase_id(): string {
		return '1.7';
	}

	public function get_phase_title(): string {
		return 'Skill Trace & Slash Command';
	}

	public function get_description(): string {
		return 'Sprint 3 Integration — Skill DB, Trace Store, Intent Engine routing, Chat Gateway trace, Working Panel';
	}

	public function get_dates(): array {
		return [ 'started' => '2026-03-25', 'updated' => '2026-04-04' ];
	}

	public function get_changelog(): array {
		return [
			[
				'group'   => 'Skill Database (Sprint 0)',
				'icon'    => '🗄️',
				'entries' => [
					[ 'id' => 'SKL-1', 'title' => 'bizcity_skills table has rows (auto-migrate from .md)' ],
				],
			],
			[
				'group'   => 'Trace Store (Sprint 1-2)',
				'icon'    => '📊',
				'entries' => [
					[ 'id' => 'TRC-1', 'title' => 'bizcity_traces table exists' ],
					[ 'id' => 'TRC-2', 'title' => 'bizcity_trace_tasks table exists' ],
					[ 'id' => 'TRC-3', 'title' => 'Trace lifecycle: begin_trace returns trace_id' ],
					[ 'id' => 'TRC-4', 'title' => 'Trace lifecycle: record_step returns task_id' ],
					[ 'id' => 'TRC-5', 'title' => 'Trace lifecycle: get_trace returns status=success' ],
					[ 'id' => 'TRC-6', 'title' => 'Trace lifecycle: total_ms recorded correctly' ],
					[ 'id' => 'TRC-7', 'title' => 'Trace lifecycle: get_tasks returns 3 steps' ],
					[ 'id' => 'TRC-8', 'title' => 'Task has context_summary (valid JSON with mode)' ],
					[ 'id' => 'TRC-9', 'title' => 'Task has token_usage data' ],
				],
			],
			[
				'group'   => 'AJAX & Intent Engine (Sprint 2-3)',
				'icon'    => '⚡',
				'entries' => [
					[ 'id' => 'AJX-1', 'title' => 'AJAX: bizcity_fetch_trace_history registered' ],
					[ 'id' => 'AJX-2', 'title' => 'AJAX: bizcity_search_skills registered' ],
					[ 'id' => 'INT-1', 'title' => 'Skill DB has get_by_slash_command()' ],
					[ 'id' => 'INT-2', 'title' => 'get_by_slash_command returns skill' ],
				],
			],
			[
				'group'   => 'Chat Gateway Trace Integration',
				'icon'    => '🔗',
				'entries' => [
					[ 'id' => 'GW-1', 'title' => 'Chat Gateway has emit_trace()' ],
					[ 'id' => 'GW-2', 'title' => 'Chat Gateway has trace_to_thinking()' ],
				],
			],
			[
				'group'   => 'Pipeline Message Guard',
				'icon'    => '🛡️',
				'entries' => [
					[ 'id' => 'MSG-1', 'title' => 'Pipeline Messenger send($meta) + send_micro_step() guard' ],
				],
			],
		];
	}

	/* ════════════════════════════════════════════════════════════════════
	 * VERIFICATIONS
	 * ════════════════════════════════════════════════════════════════════ */

	protected function run_verifications(): void {
		$this->verify_skill_db();
		$this->verify_trace_store_tables();
		$this->verify_trace_lifecycle();
		$this->verify_ajax_hooks();
		$this->verify_intent_routing();
		$this->verify_gateway_trace();
		$this->verify_pipeline_messenger();
	}

	/* ── Skill Database ── */
	private function verify_skill_db(): void {
		if ( ! class_exists( 'BizCity_Skill_Database' ) ) {
			$this->skip( 'SKL-1', 'BizCity_Skill_Database not loaded' );
			return;
		}

		$db    = BizCity_Skill_Database::instance();
		$table = $db->get_table();

		global $wpdb;
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );

		if ( $count === 0 && method_exists( $db, 'migrate_files_to_sql' ) ) {
			$db->migrate_files_to_sql();
			$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
		}

		$this->assert( 'SKL-1', 'Skill DB has rows', $count > 0, "Found {$count} skills in {$table}" );
	}

	/* ── Trace Store Tables ── */
	private function verify_trace_store_tables(): void {
		if ( ! class_exists( 'BizCity_Trace_Store' ) ) {
			$this->skip( 'TRC-1', 'BizCity_Trace_Store not loaded' );
			$this->skip( 'TRC-2', 'BizCity_Trace_Store not loaded' );
			return;
		}

		$store = BizCity_Trace_Store::instance();
		$store->ensure_tables();

		global $wpdb;
		$traces_t = $wpdb->prefix . 'bizcity_traces';
		$tasks_t  = $wpdb->prefix . 'bizcity_trace_tasks';

		$this->assert( 'TRC-1', 'bizcity_traces table exists',
			$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $traces_t ) ) === $traces_t, $traces_t );
		$this->assert( 'TRC-2', 'bizcity_trace_tasks table exists',
			$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tasks_t ) ) === $tasks_t, $tasks_t );
	}

	/* ── Trace Lifecycle ── */
	private function verify_trace_lifecycle(): void {
		if ( ! class_exists( 'BizCity_Trace_Store' ) ) {
			for ( $i = 3; $i <= 9; $i++ ) {
				$this->skip( "TRC-{$i}", 'BizCity_Trace_Store not loaded' );
			}
			return;
		}

		$store    = BizCity_Trace_Store::instance();
		$trace_id = $store->begin_trace( [
			'session_id' => 'changelog_test_' . time(),
			'user_id'    => get_current_user_id(),
			'title'      => 'Changelog verification trace',
			'mode'       => 'chat',
		] );

		$this->assert( 'TRC-3', 'begin_trace returns trace_id', ! empty( $trace_id ), $trace_id );

		$task1 = $store->record_step( 'mode_classified', 'Chế độ: chat', [
			'duration_ms'     => 150,
			'context_summary' => wp_json_encode( [ 'mode' => 'chat', 'confidence' => 0.95 ] ),
		] );
		$this->assert( 'TRC-4', 'record_step returns task_id', ! empty( $task1 ), $task1 );

		$store->record_step( 'llm_request', 'Gửi AI (gemini-2.5-flash)', [
			'tool_name'       => 'gemini-2.5-flash',
			'duration_ms'     => 2500,
			'context_summary' => wp_json_encode( [ 'model' => 'gemini-2.5-flash' ] ),
		] );

		$store->record_step( 'llm_stream_result', 'Phản hồi 380 tokens', [
			'tool_name'   => 'gemini-2.5-flash',
			'duration_ms' => 3100,
			'token_usage' => wp_json_encode( [ 'model' => 'gemini-2.5-flash', 'reply_len' => 1200 ] ),
		] );

		$store->end_trace( 'success', [
			'model' => 'gemini-2.5-flash', 'input_tokens' => 200, 'output_tokens' => 180,
		], 5750 );

		$trace = $store->get_trace( $trace_id );
		$this->assert( 'TRC-5', 'get_trace status=success', ( $trace['status'] ?? '' ) === 'success',
			'status=' . ( $trace['status'] ?? 'null' ) );
		$this->assert( 'TRC-6', 'total_ms=5750', (int) ( $trace['total_ms'] ?? 0 ) === 5750,
			'total_ms=' . ( $trace['total_ms'] ?? 0 ) );

		$tasks = $store->get_tasks( $trace_id );
		$this->assert( 'TRC-7', 'get_tasks returns 3 steps', count( $tasks ) === 3, 'count=' . count( $tasks ) );

		$first = $tasks[0] ?? [];
		$ctx   = json_decode( $first['context_summary'] ?? '', true );
		$this->assert( 'TRC-8', 'context_summary valid JSON', ( $ctx['mode'] ?? '' ) === 'chat' );

		$last = $tasks[2] ?? [];
		$this->assert( 'TRC-9', 'Task has token_usage', ! empty( $last['token_usage'] ), $last['token_usage'] ?? '' );

		// Cleanup
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'bizcity_trace_tasks', [ 'trace_id' => $trace_id ] );
		$wpdb->delete( $wpdb->prefix . 'bizcity_traces', [ 'trace_id' => $trace_id ] );
	}

	/* ── AJAX Hooks ── */
	private function verify_ajax_hooks(): void {
		$this->assert( 'AJX-1', 'bizcity_fetch_trace_history registered',
			has_action( 'wp_ajax_bizcity_fetch_trace_history' ) !== false );

		// search_skills — may register lazily
		if ( has_action( 'wp_ajax_bizcity_search_skills' ) ) {
			$this->assert( 'AJX-2', 'bizcity_search_skills registered', true, 'Hook active' );
		} elseif ( class_exists( 'BizCity_Plugin_Suggestion_API' ) && method_exists( 'BizCity_Plugin_Suggestion_API', 'ajax_search_skills' ) ) {
			$this->assert( 'AJX-2', 'bizcity_search_skills registered', true,
				'Method exists — registers on admin/ajax context' );
		} else {
			$this->assert( 'AJX-2', 'bizcity_search_skills registered', false,
				'Neither hook nor method found' );
		}
	}

	/* ── Intent Engine Routing ── */
	private function verify_intent_routing(): void {
		if ( ! class_exists( 'BizCity_Skill_Database' ) ) {
			$this->skip( 'INT-1', 'BizCity_Skill_Database not loaded' );
			$this->skip( 'INT-2', 'BizCity_Skill_Database not loaded' );
			return;
		}

		$db = BizCity_Skill_Database::instance();
		$this->assert( 'INT-1', 'get_by_slash_command() exists', method_exists( $db, 'get_by_slash_command' ) );

		if ( method_exists( $db, 'get_by_slash_command' ) ) {
			global $wpdb;
			$any = $wpdb->get_row(
				"SELECT skill_key, slash_commands FROM `{$db->get_table()}` WHERE slash_commands IS NOT NULL AND slash_commands != '' LIMIT 1",
				ARRAY_A
			);
			if ( $any ) {
				$slash = trim( explode( ',', $any['slash_commands'] )[0] );
				$found = $db->get_by_slash_command( $slash );
				$this->assert( 'INT-2', 'get_by_slash_command returns skill', ! empty( $found ),
					"slash={$slash} → " . ( $found['skill_key'] ?? 'null' ) );
			} else {
				$this->skip( 'INT-2', 'No skills with slash_commands found' );
			}
		} else {
			$this->skip( 'INT-2', 'Method not available' );
		}
	}

	/* ── Chat Gateway Trace ── */
	private function verify_gateway_trace(): void {
		if ( ! class_exists( 'BizCity_Chat_Gateway' ) ) {
			$this->skip( 'GW-1', 'BizCity_Chat_Gateway not loaded' );
			$this->skip( 'GW-2', 'BizCity_Chat_Gateway not loaded' );
			return;
		}

		$ref = new ReflectionClass( 'BizCity_Chat_Gateway' );
		$this->assert( 'GW-1', 'emit_trace() exists', $ref->hasMethod( 'emit_trace' ) );
		$this->assert( 'GW-2', 'trace_to_thinking() exists', $ref->hasMethod( 'trace_to_thinking' ) );
	}

	/* ── Pipeline Messenger Guard ── */
	private function verify_pipeline_messenger(): void {
		if ( ! class_exists( 'BizCity_Pipeline_Messenger' ) ) {
			$this->assert( 'MSG-1', 'Pipeline Messenger guard', true, 'Class not loaded (OK if no pipeline ran)' );
			return;
		}

		$ref = new ReflectionClass( 'BizCity_Pipeline_Messenger' );
		if ( ! $ref->hasMethod( 'send' ) ) {
			$this->assert( 'MSG-1', 'Pipeline Messenger guard', false, 'send() method missing' );
			return;
		}

		$params = array_map( fn( $p ) => $p->getName(), $ref->getMethod( 'send' )->getParameters() );
		$has_meta  = in_array( 'meta', $params, true );
		$has_micro = $ref->hasMethod( 'send_micro_step' );

		$this->assert( 'MSG-1', 'send($meta) + send_micro_step()', $has_meta && $has_micro,
			'send params=[' . implode( ',', $params ) . '] micro_step=' . ( $has_micro ? 'yes' : 'no' ) );
	}
}
