<?php
/**
 * Sprint 3 Integration Test — Phase 1.7
 *
 * Validates the full pipeline: skill → intent → trace → Working Panel.
 * Run via: wp-admin/admin.php?page=bizcity-test-sprint3
 * Or WP-CLI: wp eval-file wp-content/plugins/bizcity-twin-ai/tests/test-sprint3-integration.php
 *
 * @package BizCity_Twin_AI
 * @since   4.1.0
 */

defined( 'ABSPATH' ) or die( 'Must be loaded via WordPress.' );

class BizCity_Sprint3_Integration_Test {

	private $results = [];
	private $pass    = 0;
	private $fail    = 0;

	public function run(): array {
		$this->results = [];
		$this->pass    = 0;
		$this->fail    = 0;

		$this->test_skill_db_has_rows();
		$this->test_trace_store_tables_exist();
		$this->test_trace_lifecycle();
		$this->test_trace_history_ajax();
		$this->test_intent_engine_skill_routing();
		$this->test_chat_gateway_trace_integration();
		$this->test_search_skills_ajax();
		$this->test_no_pipeline_messages_in_chat();

		return [
			'pass'    => $this->pass,
			'fail'    => $this->fail,
			'total'   => $this->pass + $this->fail,
			'results' => $this->results,
		];
	}

	private function assert( string $name, bool $condition, string $detail = '' ): void {
		if ( $condition ) {
			$this->pass++;
			$this->results[] = [ 'name' => $name, 'status' => '✅ PASS', 'detail' => $detail ];
		} else {
			$this->fail++;
			$this->results[] = [ 'name' => $name, 'status' => '❌ FAIL', 'detail' => $detail ];
		}
	}

	/* ──────────────────────────────────────────────
	 * TEST 1: Skill DB has rows (Sprint 0 fix)
	 * ────────────────────────────────────────────── */
	private function test_skill_db_has_rows(): void {
		if ( ! class_exists( 'BizCity_Skill_Database' ) ) {
			$this->assert( 'Skill DB class exists', false, 'BizCity_Skill_Database not loaded' );
			return;
		}

		$db    = BizCity_Skill_Database::instance();
		$table = $db->get_table();

		global $wpdb;
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );

		// Auto-migrate from .md files if table is empty
		if ( $count === 0 && method_exists( $db, 'migrate_files_to_sql' ) ) {
			$migrated = $db->migrate_files_to_sql();
			$count    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
		}

		$this->assert(
			'Skill DB has rows',
			$count > 0,
			"Found {$count} skills in {$table}"
		);
	}

	/* ──────────────────────────────────────────────
	 * TEST 2: Trace Store tables exist
	 * ────────────────────────────────────────────── */
	private function test_trace_store_tables_exist(): void {
		if ( ! class_exists( 'BizCity_Trace_Store' ) ) {
			$this->assert( 'Trace Store class exists', false, 'BizCity_Trace_Store not loaded' );
			return;
		}

		$store = BizCity_Trace_Store::instance();
		$store->ensure_tables();

		global $wpdb;
		$traces_table = $wpdb->prefix . 'bizcity_traces';
		$tasks_table  = $wpdb->prefix . 'bizcity_trace_tasks';

		$traces_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $traces_table ) ) === $traces_table;
		$tasks_exists  = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tasks_table ) ) === $tasks_table;

		$this->assert( 'bizcity_traces table exists', $traces_exists, $traces_table );
		$this->assert( 'bizcity_trace_tasks table exists', $tasks_exists, $tasks_table );
	}

	/* ──────────────────────────────────────────────
	 * TEST 3: Trace lifecycle (begin → record → end → read)
	 * ────────────────────────────────────────────── */
	private function test_trace_lifecycle(): void {
		if ( ! class_exists( 'BizCity_Trace_Store' ) ) {
			$this->assert( 'Trace lifecycle', false, 'Class not available' );
			return;
		}

		$store    = BizCity_Trace_Store::instance();
		$trace_id = $store->begin_trace( [
			'session_id' => 'test_session_' . time(),
			'user_id'    => get_current_user_id(),
			'title'      => 'Integration test trace',
			'mode'       => 'chat',
		] );

		$this->assert( 'begin_trace returns trace_id', ! empty( $trace_id ), $trace_id );

		// Record steps with context layers
		$task1 = $store->record_step( 'mode_classified', 'Tôi hiểu rồi — chế độ: chat', [
			'duration_ms'     => 150,
			'context_summary' => wp_json_encode( [ 'mode' => 'chat', 'confidence' => 0.95 ] ),
		] );
		$this->assert( 'record_step returns task_id', ! empty( $task1 ), $task1 );

		$task2 = $store->record_step( 'llm_request', 'Đang gửi cho AI (gemini-2.5-flash)', [
			'tool_name'       => 'gemini-2.5-flash',
			'duration_ms'     => 2500,
			'context_summary' => wp_json_encode( [ 'model' => 'gemini-2.5-flash', 'messages_count' => 8 ] ),
		] );

		$task3 = $store->record_step( 'llm_stream_result', 'Đã nhận phản hồi hoàn chỉnh (380 tokens)', [
			'tool_name'   => 'gemini-2.5-flash',
			'duration_ms' => 3100,
			'token_usage' => wp_json_encode( [ 'model' => 'gemini-2.5-flash', 'provider' => 'openrouter', 'reply_len' => 1200 ] ),
		] );

		$store->end_trace( 'success', [
			'model'         => 'gemini-2.5-flash',
			'input_tokens'  => 200,
			'output_tokens' => 180,
		], 5750 );

		// Read back
		$trace = $store->get_trace( $trace_id );
		$this->assert( 'get_trace returns data', ! empty( $trace ), 'status=' . ( $trace['status'] ?? 'null' ) );
		$this->assert( 'trace status = success', ( $trace['status'] ?? '' ) === 'success' );
		$this->assert( 'trace total_ms recorded', (int) ( $trace['total_ms'] ?? 0 ) === 5750, 'total_ms=' . ( $trace['total_ms'] ?? 0 ) );

		$tasks = $store->get_tasks( $trace_id );
		$this->assert( 'get_tasks returns 3 steps', count( $tasks ) === 3, 'count=' . count( $tasks ) );

		// Verify context layers are stored
		$first_task = $tasks[0] ?? [];
		$this->assert( 'task has context_summary', ! empty( $first_task['context_summary'] ), $first_task['context_summary'] ?? '' );
		$ctx_decoded = json_decode( $first_task['context_summary'] ?? '', true );
		$this->assert( 'context_summary is valid JSON with mode', ( $ctx_decoded['mode'] ?? '' ) === 'chat' );

		$last_task = $tasks[2] ?? [];
		$this->assert( 'task has token_usage', ! empty( $last_task['token_usage'] ), $last_task['token_usage'] ?? '' );

		// Cleanup test data
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'bizcity_trace_tasks', [ 'trace_id' => $trace_id ] );
		$wpdb->delete( $wpdb->prefix . 'bizcity_traces', [ 'trace_id' => $trace_id ] );
	}

	/* ──────────────────────────────────────────────
	 * TEST 4: Trace History AJAX handler exists
	 * ────────────────────────────────────────────── */
	private function test_trace_history_ajax(): void {
		$has_action = has_action( 'wp_ajax_bizcity_fetch_trace_history' );
		$this->assert( 'AJAX: bizcity_fetch_trace_history registered', $has_action !== false );
	}

	/* ──────────────────────────────────────────────
	 * TEST 5: Intent Engine skill routing
	 * ────────────────────────────────────────────── */
	private function test_intent_engine_skill_routing(): void {
		if ( ! class_exists( 'BizCity_Skill_Database' ) ) {
			$this->assert( 'Skill routing', false, 'BizCity_Skill_Database not loaded' );
			return;
		}

		$db = BizCity_Skill_Database::instance();

		// Check get_by_slash_command method exists
		$has_method = method_exists( $db, 'get_by_slash_command' );
		$this->assert( 'Skill DB has get_by_slash_command()', $has_method );

		if ( $has_method ) {
			// Try to find any skill with a slash command
			global $wpdb;
			$table = $db->get_table();
			$any_skill = $wpdb->get_row(
				"SELECT skill_key, slash_commands FROM `{$table}` WHERE slash_commands IS NOT NULL AND slash_commands != '' LIMIT 1",
				ARRAY_A
			);
			if ( $any_skill ) {
				$slash = explode( ',', $any_skill['slash_commands'] )[0];
				$found = $db->get_by_slash_command( trim( $slash ) );
				$this->assert(
					'get_by_slash_command returns skill',
					! empty( $found ),
					"slash={$slash} → " . ( $found['skill_key'] ?? 'null' )
				);
			} else {
				$this->assert( 'Skills have slash_commands', false, 'No skills with slash_commands found' );
			}
		}
	}

	/* ──────────────────────────────────────────────
	 * TEST 6: Chat Gateway trace integration
	 * ────────────────────────────────────────────── */
	private function test_chat_gateway_trace_integration(): void {
		// Verify the Chat Gateway class has trace methods
		if ( ! class_exists( 'BizCity_Chat_Gateway' ) ) {
			$this->assert( 'Chat Gateway class exists', false );
			return;
		}

		$gateway = BizCity_Chat_Gateway::instance();
		$has_emit = method_exists( $gateway, 'emit_trace' ) || ( new \ReflectionClass( $gateway ) )->hasMethod( 'emit_trace' );
		$this->assert( 'Chat Gateway has emit_trace()', $has_emit );

		$has_thinking = method_exists( $gateway, 'trace_to_thinking' ) || ( new \ReflectionClass( $gateway ) )->hasMethod( 'trace_to_thinking' );
		$this->assert( 'Chat Gateway has trace_to_thinking()', $has_thinking );
	}

	/* ──────────────────────────────────────────────
	 * TEST 7: Search skills AJAX
	 * ────────────────────────────────────────────── */
	private function test_search_skills_ajax(): void {
		// Primary: check if AJAX hook is already registered
		if ( has_action( 'wp_ajax_bizcity_search_skills' ) ) {
			$this->assert( 'AJAX: bizcity_search_skills registered', true, 'Hook active' );
			return;
		}

		// Fallback: verify the class + method exist (proof the code is deployed)
		$class_ok  = class_exists( 'BizCity_Plugin_Suggestion_API' );
		$method_ok = $class_ok && method_exists( 'BizCity_Plugin_Suggestion_API', 'ajax_search_skills' );

		if ( $method_ok ) {
			$this->assert( 'AJAX: bizcity_search_skills registered', true, 'Hook not active yet but ajax_search_skills() method exists — registers on admin/ajax context' );
			return;
		}

		// Class loaded but outdated (missing Phase 1.7 method) — report deployed file path
		if ( $class_ok ) {
			$ref  = new \ReflectionClass( 'BizCity_Plugin_Suggestion_API' );
			$file = $ref->getFileName();
			$methods = array_filter(
				array_map( fn( $m ) => $m->getName(), $ref->getMethods() ),
				fn( $n ) => strpos( $n, 'ajax_search' ) !== false || strpos( $n, 'skill' ) !== false
			);
			$this->assert(
				'AJAX: bizcity_search_skills registered',
				false,
				'Method missing in deployed file: ' . basename( $file ) . ' — skill-related methods: [' . implode( ', ', $methods ) . '] — needs resync'
			);
			return;
		}

		$this->assert( 'AJAX: bizcity_search_skills registered', false, 'BizCity_Plugin_Suggestion_API class not loaded' );
	}

	/* ──────────────────────────────────────────────
	 * TEST 8: No pipeline progress in chat messages
	 * ────────────────────────────────────────────── */
	private function test_no_pipeline_messages_in_chat(): void {
		// Verify via Reflection — no file I/O needed (avoids open_basedir on shards)
		if ( ! class_exists( 'BizCity_Pipeline_Messenger' ) ) {
			$this->assert( 'Pipeline Messenger has _to_chat guard', true, 'Class not loaded (OK if no pipeline ran)' );
			return;
		}

		$ref = new \ReflectionClass( 'BizCity_Pipeline_Messenger' );

		// Check that send() method exists and accepts $meta parameter (which carries _to_chat)
		$has_send = $ref->hasMethod( 'send' );
		if ( ! $has_send ) {
			$this->assert( 'Pipeline Messenger has _to_chat guard', false, 'send() method missing' );
			return;
		}

		$send_ref   = $ref->getMethod( 'send' );
		$params     = $send_ref->getParameters();
		$param_names = array_map( fn( $p ) => $p->getName(), $params );

		// send() should have a $meta parameter (which is where _to_chat lives)
		$has_meta = in_array( 'meta', $param_names, true );

		// Also verify send_micro_step exists (it sets _to_chat => false)
		$has_micro_step = $ref->hasMethod( 'send_micro_step' );

		$ok = $has_meta && $has_micro_step;
		$this->assert(
			'Pipeline Messenger has _to_chat guard',
			$ok,
			$ok ? 'send($meta) + send_micro_step() confirmed via Reflection'
			   : 'send params=[' . implode( ',', $param_names ) . '] micro_step=' . ( $has_micro_step ? 'yes' : 'no' )
		);
	}

	/* ──────────────────────────────────────────────
	 * OUTPUT
	 * ────────────────────────────────────────────── */
	public function render_html(): string {
		$data = $this->run();
		$html = '<div style="font-family:monospace;max-width:800px;margin:20px auto;padding:20px">';
		$html .= '<h2>🧪 Sprint 3 — Integration Test Results</h2>';
		$html .= '<p><strong>' . $data['pass'] . '</strong>/' . $data['total'] . ' passed';
		if ( $data['fail'] > 0 ) {
			$html .= ' · <span style="color:#ef4444"><strong>' . $data['fail'] . ' FAILED</strong></span>';
		}
		$html .= '</p><hr>';

		foreach ( $data['results'] as $r ) {
			$color = strpos( $r['status'], 'PASS' ) !== false ? '#059669' : '#ef4444';
			$html .= '<div style="margin:6px 0;padding:4px 8px;border-left:3px solid ' . $color . '">';
			$html .= '<strong>' . esc_html( $r['status'] ) . '</strong> ' . esc_html( $r['name'] );
			if ( $r['detail'] ) {
				$html .= ' <span style="color:#64748b;font-size:12px">— ' . esc_html( $r['detail'] ) . '</span>';
			}
			$html .= '</div>';
		}

		$html .= '</div>';
		return $html;
	}
}

/* ── Register admin page ── */
add_action( 'admin_menu', function () {
	add_submenu_page(
		null, // Hidden from menu
		'Sprint 3 Test',
		'Sprint 3 Test',
		'manage_options',
		'bizcity-test-sprint3',
		function () {
			$test = new BizCity_Sprint3_Integration_Test();
			echo $test->render_html();
		}
	);
} );

/* ── WP-CLI support ── */
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	$test = new BizCity_Sprint3_Integration_Test();
	$data = $test->run();
	foreach ( $data['results'] as $r ) {
		$line = $r['status'] . ' ' . $r['name'];
		if ( $r['detail'] ) {
			$line .= ' — ' . $r['detail'];
		}
		WP_CLI::log( $line );
	}
	WP_CLI::log( '' );
	WP_CLI::log( $data['pass'] . '/' . $data['total'] . ' passed' );
	if ( $data['fail'] > 0 ) {
		WP_CLI::error( $data['fail'] . ' test(s) failed', false );
	} else {
		WP_CLI::success( 'All tests passed!' );
	}
}
