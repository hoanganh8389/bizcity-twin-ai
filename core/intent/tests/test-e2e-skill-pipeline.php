<?php
/**
 * PHASE-1.2 S5 — End-to-End Verification Script
 *
 * Validates the full Archetype C pipeline flow:
 *   Skill detect → Bridge queue → Pipeline generate → Todos create →
 *   Middleware checkpoint → Resume from DB → Contract validate
 *
 * Run via WP-CLI:   wp eval-file core/intent/tests/test-e2e-skill-pipeline.php
 * Or via browser:    ?bizc_test_e2e=1 (admin only, logged in)
 *
 * @package BizCity_Intent
 * @since   4.3.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

/**
 * Gate: only run for admins via CLI or ?bizc_test_e2e=1
 */
function bizc_e2e_should_run(): bool {
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		return true;
	}
	if ( isset( $_GET['bizc_test_e2e'] ) && current_user_can( 'manage_options' ) ) {
		return true;
	}
	return false;
}

if ( ! bizc_e2e_should_run() ) {
	return;
}

/* ================================================================
 *  Test Runner
 * ================================================================ */

class BizCity_E2E_Test_Runner {

	private array $results = [];
	private int $pass = 0;
	private int $fail = 0;

	public function run(): void {
		$this->out( '=== PHASE-1.2 S5: E2E Skill → Pipeline Test ===' );
		$this->out( '' );

		$this->test_01_archetype_detection();
		$this->test_02_skill_context_routing();
		$this->test_03_bridge_queue_payload();
		$this->test_04_todos_crud();
		$this->test_05_pipeline_resume_contract();
		$this->test_06_resume_rebuild_variables();
		$this->test_07_mismatch_detection();
		$this->test_08_middleware_todo_checkpoint();

		$this->out( '' );
		$this->out( '=== Results: ' . $this->pass . ' passed, ' . $this->fail . ' failed ===' );
	}

	/* ────────────────────────────────────────────
	 *  T01: Archetype Detection
	 * ──────────────────────────────────────────── */
	private function test_01_archetype_detection(): void {
		$this->out( '--- T01: Archetype Detection ---' );

		if ( ! class_exists( 'BizCity_Skill_Context' ) ) {
			$this->skip( 'T01', 'BizCity_Skill_Context not loaded' );
			return;
		}

		// A: no tools
		$this->assert_eq( 'T01a', 'A',
			BizCity_Skill_Context::detect_archetype( [ 'tools' => [], 'related_tools' => [] ] ),
			'Empty tools → A'
		);

		// B: 1 tool
		$this->assert_eq( 'T01b', 'B',
			BizCity_Skill_Context::detect_archetype( [ 'tools' => [ 'woo_create_product' ] ] ),
			'1 tool → B'
		);

		// C: 2+ tools
		$this->assert_eq( 'T01c', 'C',
			BizCity_Skill_Context::detect_archetype( [ 'tools' => [ 'create_workflow', 'send_message' ] ] ),
			'2 tools → C'
		);

		// C: output_format = json_workflow (B4 fix)
		$this->assert_eq( 'T01d', 'C',
			BizCity_Skill_Context::detect_archetype( [ 'tools' => [ 'single_tool' ], 'output_format' => 'json_workflow' ] ),
			'json_workflow override → C'
		);

		// Explicit archetype takes priority
		$this->assert_eq( 'T01e', 'A',
			BizCity_Skill_Context::detect_archetype( [ 'archetype' => 'A', 'tools' => [ 'create_workflow', 'send_message' ] ] ),
			'Explicit A overrides tool count'
		);
	}

	/* ────────────────────────────────────────────
	 *  T02: Skill Context Routing (C → action fired)
	 * ──────────────────────────────────────────── */
	private function test_02_skill_context_routing(): void {
		$this->out( '--- T02: Skill Context Routing ---' );

		if ( ! class_exists( 'BizCity_Skill_Context' ) ) {
			$this->skip( 'T02', 'BizCity_Skill_Context not loaded' );
			return;
		}

		// Check that bizcity_skill_trigger_pipeline action exists
		$this->assert_true( 'T02a',
			has_action( 'bizcity_skill_trigger_pipeline' ) !== false,
			'bizcity_skill_trigger_pipeline action is registered'
		);

		// Check Bridge is wired
		if ( class_exists( 'BizCity_Skill_Pipeline_Bridge' ) ) {
			$this->assert_true( 'T02b', true, 'BizCity_Skill_Pipeline_Bridge class exists' );
		} else {
			$this->skip( 'T02b', 'Bridge class not loaded' );
		}
	}

	/* ────────────────────────────────────────────
	 *  T03: Bridge Queue Payload Structure
	 * ──────────────────────────────────────────── */
	private function test_03_bridge_queue_payload(): void {
		$this->out( '--- T03: Bridge Queue Payload ---' );

		if ( ! class_exists( 'BizCity_Skill_Pipeline_Bridge' ) ) {
			$this->skip( 'T03', 'Bridge not loaded' );
			return;
		}

		// Fire the action with mock data and verify transient was set
		$mock_skill = [
			'path'        => 'test/mock-skill.md',
			'frontmatter' => [
				'title' => 'E2E Test Skill',
				'tools' => [ 'tool_a', 'tool_b' ],
			],
			'content'  => '# Test skill content',
			'score'    => 85,
			'reasons'  => [ 'goal', 'tool' ],
			'archetype' => 'C',
		];

		$mock_args = [
			'mode'          => 'execution',
			'message'       => 'test e2e pipeline',
			'session_id'    => 'e2e_test_session_' . time(),
			'channel'       => 'adminchat',
			'engine_result' => [
				'goal'    => 'tool_a',
				'action'  => 'passthrough',
				'channel' => 'adminchat',
				'meta'    => [ 'goal' => 'tool_a', 'intent_key' => 'tool_a' ],
			],
		];

		// Capture current user for transient key
		$user_id   = get_current_user_id();
		$index_key = 'bizcity_skill_pipe_idx_' . $user_id;

		// Fire action
		do_action( 'bizcity_skill_trigger_pipeline', $mock_skill, $mock_args );

		// Check transient was created
		$transient_key = get_transient( $index_key );
		$this->assert_true( 'T03a',
			! empty( $transient_key ),
			'Index transient created: ' . ( $transient_key ?: 'null' )
		);

		if ( $transient_key ) {
			$payload = get_transient( $transient_key );
			$this->assert_true( 'T03b',
				is_array( $payload ) && ! empty( $payload['skill_tools'] ),
				'Payload has skill_tools'
			);
			$this->assert_eq( 'T03c', 'adminchat',
				$payload['channel'] ?? 'unknown',
				'Channel from engine_result[channel] (B10 fix)'
			);
			$this->assert_eq( 'T03d', $mock_args['session_id'],
				$payload['session_id'] ?? '',
				'session_id from $args (B3 fix)'
			);

			// Cleanup
			delete_transient( $transient_key );
			delete_transient( $index_key );
		}
	}

	/* ────────────────────────────────────────────
	 *  T04: Todos CRUD
	 * ──────────────────────────────────────────── */
	private function test_04_todos_crud(): void {
		$this->out( '--- T04: Todos CRUD ---' );

		if ( ! class_exists( 'BizCity_Intent_Todos' ) ) {
			$this->skip( 'T04', 'BizCity_Intent_Todos not loaded' );
			return;
		}

		$pipeline_id = 'e2e_test_pipe_' . time();
		$test_steps  = [
			[ 'tool_name' => 'ai_research',      'label' => 'Research',      'node_id' => '4', 'node_code' => 'it_call_tool' ],
			[ 'tool_name' => 'ai_write_article',  'label' => 'Write Article', 'node_id' => '5', 'node_code' => 'it_call_tool' ],
			[ 'tool_name' => 'wp_create_post',    'label' => 'Publish',       'node_id' => '6', 'node_code' => 'it_call_tool' ],
		];

		// Create
		$count = BizCity_Intent_Todos::create_from_plan( $pipeline_id, $test_steps, 1, [
			'task_id'          => 999,
			'pipeline_version' => 1,
		] );
		$this->assert_eq( 'T04a', 3, $count, 'Created 3 todo rows' );

		// Read
		$todos = BizCity_Intent_Todos::get_pipeline_todos( $pipeline_id );
		$this->assert_eq( 'T04b', 3, count( $todos ), 'Read 3 todos' );

		// Verify v4.0 columns
		$first = $todos[0] ?? [];
		$this->assert_eq( 'T04c', '999', (string) ( $first['task_id'] ?? 0 ), 'task_id stored' );
		$this->assert_eq( 'T04d', '1', (string) ( $first['pipeline_version'] ?? 0 ), 'pipeline_version stored' );
		$this->assert_eq( 'T04e', '4', $first['node_id'] ?? '', 'node_id stored' );

		// Update with node_output_json (B8 fix)
		$updated = BizCity_Intent_Todos::update_status( $pipeline_id, 'ai_research', 'COMPLETED', [
			'node_id'          => '4',
			'score'            => 90,
			'output_summary'   => 'Research done',
			'node_input_json'  => [ 'query' => 'test' ],
			'node_output_json' => [ 'result' => 'research data', 'post_id' => 42 ],
		] );
		$this->assert_true( 'T04f', $updated, 'update_status via node_id succeeded' );

		// Progress
		$progress = BizCity_Intent_Todos::get_progress( $pipeline_id );
		$this->assert_eq( 'T04g', 1, $progress['completed'], 'Progress: 1 completed' );
		$this->assert_eq( 'T04h', 2, $progress['pending'], 'Progress: 2 pending' );

		// Cleanup
		BizCity_Intent_Todos::delete_pipeline_todos( $pipeline_id );
		$after = BizCity_Intent_Todos::get_pipeline_todos( $pipeline_id );
		$this->assert_eq( 'T04i', 0, count( $after ), 'Cleanup: 0 rows after delete' );
	}

	/* ────────────────────────────────────────────
	 *  T05: Pipeline Resume — Contract Validation
	 * ──────────────────────────────────────────── */
	private function test_05_pipeline_resume_contract(): void {
		$this->out( '--- T05: Pipeline Resume Contract ---' );

		if ( ! class_exists( 'BizCity_Pipeline_Resume' ) ) {
			$this->skip( 'T05', 'BizCity_Pipeline_Resume not loaded' );
			return;
		}

		// Contract should fail for non-existent pipeline
		$result = BizCity_Pipeline_Resume::validate_contract( 'nonexistent_pipe_999' );
		$this->assert_eq( 'T05a', 'NO_TODOS', $result['status'], 'Non-existent pipeline → NO_TODOS' );
		$this->assert_true( 'T05b', ! $result['ready'], 'Contract not ready for missing pipeline' );

		// Create test todos WITHOUT valid task_id
		if ( class_exists( 'BizCity_Intent_Todos' ) ) {
			$pipe_id = 'e2e_contract_' . time();
			BizCity_Intent_Todos::create_from_plan( $pipe_id, [
				[ 'tool_name' => 'test_tool', 'label' => 'Step 1', 'node_id' => '4' ],
			], 1, [] ); // No task_id

			$result = BizCity_Pipeline_Resume::validate_contract( $pipe_id );
			$this->assert_eq( 'T05c', 'NO_TASK_ID', $result['status'], 'Missing task_id → NO_TASK_ID' );

			// Cleanup
			BizCity_Intent_Todos::delete_pipeline_todos( $pipe_id );
		}

		// can_resume for non-existent
		$check = BizCity_Pipeline_Resume::can_resume( 'nonexistent' );
		$this->assert_true( 'T05d', ! $check['can_resume'], 'can_resume=false for non-existent pipeline' );
	}

	/* ────────────────────────────────────────────
	 *  T06: Resume Rebuild Variables
	 * ──────────────────────────────────────────── */
	private function test_06_resume_rebuild_variables(): void {
		$this->out( '--- T06: Resume Rebuild Variables ---' );

		if ( ! class_exists( 'BizCity_Pipeline_Resume' ) ) {
			$this->skip( 'T06', 'Pipeline Resume not loaded' );
			return;
		}

		// Use reflection to test private rebuild_variables method
		$ref    = new ReflectionMethod( 'BizCity_Pipeline_Resume', 'rebuild_variables' );
		$ref->setAccessible( true );

		$mock_todos = [
			[
				'status'           => 'COMPLETED',
				'node_id'          => '4',
				'step_index'       => 0,
				'node_output_json' => json_encode( [ 'result' => 'data_a', 'post_id' => 10 ] ),
			],
			[
				'status'           => 'COMPLETED',
				'node_id'          => '5',
				'step_index'       => 1,
				'node_output_json' => json_encode( [ 'result' => 'data_b', 'url' => 'https://example.com' ] ),
			],
			[
				'status'           => 'PENDING',
				'node_id'          => '6',
				'step_index'       => 2,
				'node_output_json' => null,
			],
		];

		$vars = $ref->invoke( null, $mock_todos );

		$this->assert_true( 'T06a', isset( $vars['node#4'] ), 'Variables contain node#4' );
		$this->assert_true( 'T06b', isset( $vars['node#5'] ), 'Variables contain node#5' );
		$this->assert_true( 'T06c', ! isset( $vars['node#6'] ), 'PENDING node#6 not in variables' );
		$this->assert_eq( 'T06d', 10, $vars['node#4']['post_id'] ?? null, 'node#4 post_id=10' );
	}

	/* ────────────────────────────────────────────
	 *  T07: Mismatch Detection
	 * ──────────────────────────────────────────── */
	private function test_07_mismatch_detection(): void {
		$this->out( '--- T07: Mismatch Detection ---' );

		if ( ! class_exists( 'BizCity_Pipeline_Resume' ) ) {
			$this->skip( 'T07', 'Pipeline Resume not loaded' );
			return;
		}

		$ref = new ReflectionMethod( 'BizCity_Pipeline_Resume', 'detect_mismatches' );
		$ref->setAccessible( true );

		// Matching: 3 todos, 3 action nodes (excluding planner+verifier)
		$todos_3 = [
			[ 'status' => 'PENDING', 'node_id' => '4', 'tool_name' => 'ai_research' ],
			[ 'status' => 'PENDING', 'node_id' => '5', 'tool_name' => 'ai_write' ],
			[ 'status' => 'PENDING', 'node_id' => '6', 'tool_name' => 'wp_publish' ],
		];
		$graph_nodes = [
			[ 'id' => '1', 'type' => 'trigger', 'data' => [ 'code' => 'chat_trigger' ] ],
			[ 'id' => '2', 'type' => 'action',  'data' => [ 'code' => 'it_todos_planner' ] ],
			[ 'id' => '3', 'type' => 'action',  'data' => [ 'code' => 'ai_verify' ] ],
			[ 'id' => '4', 'type' => 'action',  'data' => [ 'code' => 'ai_research' ] ],
			[ 'id' => '5', 'type' => 'action',  'data' => [ 'code' => 'ai_write' ] ],
			[ 'id' => '6', 'type' => 'action',  'data' => [ 'code' => 'wp_publish' ] ],
			[ 'id' => '7', 'type' => 'action',  'data' => [ 'code' => 'it_summary_verifier' ] ],
		];

		$mm = $ref->invoke( null, $todos_3, $graph_nodes );
		$blocking = array_filter( $mm, function( $m ) { return ( $m['severity'] ?? '' ) === 'blocking'; } );
		$this->assert_true( 'T07a', empty( $blocking ), 'Matching count: no blocking mismatch' );

		// Mismatch: 2 todos vs 3 action nodes, diff > 1, not started → warning
		$todos_2 = [
			[ 'status' => 'PENDING', 'node_id' => '4', 'tool_name' => 'ai_research' ],
			[ 'status' => 'PENDING', 'node_id' => '5', 'tool_name' => 'ai_write' ],
		];
		$mm2 = $ref->invoke( null, $todos_2, $graph_nodes );
		$this->assert_true( 'T07b', ! empty( $mm2 ), 'Count diff>1 detected as mismatch' );
		$this->assert_eq( 'T07c', 'warning', $mm2[0]['severity'] ?? '', 'Not started → warning (rebuildable)' );

		// Mismatch: 2 todos vs 3 action, has progress → blocking
		$todos_progress = [
			[ 'status' => 'COMPLETED', 'node_id' => '4', 'tool_name' => 'ai_research' ],
			[ 'status' => 'PENDING',   'node_id' => '5', 'tool_name' => 'ai_write' ],
		];
		$mm3 = $ref->invoke( null, $todos_progress, $graph_nodes );
		$blocking3 = array_filter( $mm3, function( $m ) { return ( $m['severity'] ?? '' ) === 'blocking'; } );
		$this->assert_true( 'T07d', ! empty( $blocking3 ), 'Count diff + progress → blocking' );

		// Orphan node_id
		$todos_orphan = [
			[ 'status' => 'PENDING', 'node_id' => '99', 'tool_name' => 'ai_research' ],
			[ 'status' => 'PENDING', 'node_id' => '5',  'tool_name' => 'ai_write' ],
			[ 'status' => 'PENDING', 'node_id' => '6',  'tool_name' => 'wp_publish' ],
		];
		$mm4 = $ref->invoke( null, $todos_orphan, $graph_nodes );
		$orphan_mm = array_filter( $mm4, function( $m ) { return $m['type'] === 'orphan_node_id'; } );
		$this->assert_true( 'T07e', ! empty( $orphan_mm ), 'Orphan node_id=99 detected' );
	}

	/* ────────────────────────────────────────────
	 *  T08: Middleware Todo Checkpoint (B8 verify)
	 * ──────────────────────────────────────────── */
	private function test_08_middleware_todo_checkpoint(): void {
		$this->out( '--- T08: Middleware todo checkpoint structure ---' );

		if ( ! class_exists( 'BizCity_Pipeline_Middleware' ) ) {
			$this->skip( 'T08', 'Pipeline Middleware not loaded' );
			return;
		}

		// Verify the middleware class has the boot method (hooks are registered)
		$this->assert_true( 'T08a',
			method_exists( 'BizCity_Pipeline_Middleware', 'boot' ),
			'Middleware has boot() method'
		);

		// Verify BizCity_Intent_Todos has the expected methods
		if ( class_exists( 'BizCity_Intent_Todos' ) ) {
			$this->assert_true( 'T08b',
				method_exists( 'BizCity_Intent_Todos', 'update_status' ),
				'Todos has update_status()'
			);
			$this->assert_true( 'T08c',
				method_exists( 'BizCity_Intent_Todos', 'get_progress' ),
				'Todos has get_progress()'
			);
		}

		// Verify Pipeline Resume is registered
		$this->assert_true( 'T08d',
			class_exists( 'BizCity_Pipeline_Resume' ),
			'BizCity_Pipeline_Resume class is loaded'
		);
	}

	/* ────────────────────────────────────────────
	 *  Assertion Helpers
	 * ──────────────────────────────────────────── */

	private function assert_eq( string $id, $expected, $actual, string $label ): void {
		if ( $expected == $actual ) {
			$this->pass++;
			$this->out( "  ✅ {$id}: {$label}" );
		} else {
			$this->fail++;
			$this->out( "  ❌ {$id}: {$label} — expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
		}
	}

	private function assert_true( string $id, bool $condition, string $label ): void {
		if ( $condition ) {
			$this->pass++;
			$this->out( "  ✅ {$id}: {$label}" );
		} else {
			$this->fail++;
			$this->out( "  ❌ {$id}: {$label}" );
		}
	}

	private function skip( string $id, string $reason ): void {
		$this->out( "  ⏭️ {$id}: SKIP — {$reason}" );
	}

	private function out( string $msg ): void {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::log( $msg );
		} else {
			echo $msg . "\n";
		}
	}
}

// Run
( new BizCity_E2E_Test_Runner() )->run();
