<?php
/**
 * Changelog — Phase 1.10: Agentic Skill Orchestration — 5-Step Pipeline
 *
 * Validates: Skill Pipeline Bridge (Archetype D inference + bookend injection),
 * 5 WaicAction blocks (planner, research, memory, content, reflection),
 * Studio Outputs integration, Working Panel trace, Pipeline Messenger.
 *
 * @package BizCity_Twin_AI
 * @since   4.6.0
 */

defined( 'ABSPATH' ) or die();

class BizCity_Changelog_Phase110 extends BizCity_Changelog_Base {

	public function get_phase_id(): string {
		return '1.10';
	}

	public function get_phase_title(): string {
		return 'Agentic Skill Orchestration — 5-Step Pipeline';
	}

	public function get_description(): string {
		return 'Archetype D skill→pipeline inference, bookend injection (memory+reflection), 5 WaicAction blocks, Studio Outputs per-step, Working Panel trace, auto-chain variable wiring';
	}

	public function get_dates(): array {
		return [ 'started' => '2026-04-01', 'updated' => '2026-04-05' ];
	}

	public function get_changelog(): array {
		return [
			[
				'group'   => 'Skill Pipeline Bridge — Archetype D (Sprint 0)',
				'icon'    => '🔗',
				'entries' => [
					[ 'id' => 'BR-1', 'title' => 'BizCity_Skill_Pipeline_Bridge class loaded' ],
					[ 'id' => 'BR-2', 'title' => 'get_step_inference_map() has it_call_research entry' ],
					[ 'id' => 'BR-3', 'title' => 'get_step_inference_map() has it_call_content entry' ],
					[ 'id' => 'BR-4', 'title' => 'get_step_inference_map() has it_call_memory entry' ],
					[ 'id' => 'BR-5', 'title' => 'get_step_inference_map() has it_call_reflection entry' ],
					[ 'id' => 'BR-6', 'title' => 'inject_bookend_nodes() method exists' ],
					[ 'id' => 'BR-7', 'title' => 'generate_pipeline_from_steps() method exists' ],
				],
			],
			[
				'group'   => 'Block: it_todos_planner (Sprint 1a)',
				'icon'    => '📋',
				'entries' => [
					[ 'id' => 'PL-1', 'title' => 'WaicAction_it_todos_planner class loaded' ],
					[ 'id' => 'PL-2', 'title' => 'Block code = it_todos_planner' ],
					[ 'id' => 'PL-3', 'title' => 'getResults() fires mw:execute_start trace' ],
					[ 'id' => 'PL-4', 'title' => 'save_plan_studio_output() method exists' ],
				],
			],
			[
				'group'   => 'Block: it_call_research (Sprint 0)',
				'icon'    => '🔍',
				'entries' => [
					[ 'id' => 'RS-1', 'title' => 'WaicAction_it_call_research class loaded' ],
					[ 'id' => 'RS-2', 'title' => 'Block code = it_call_research' ],
					[ 'id' => 'RS-3', 'title' => 'save_studio_output() method exists' ],
					[ 'id' => 'RS-4', 'title' => 'write_to_bcn_sources() method exists (dual-write)' ],
					[ 'id' => 'RS-5', 'title' => 'write_to_webchat_sources() method exists (dual-write)' ],
					[ 'id' => 'RS-6', 'title' => 'Output variable: research_summary declared' ],
				],
			],
			[
				'group'   => 'Block: it_call_memory (Sprint 1b)',
				'icon'    => '🧠',
				'entries' => [
					[ 'id' => 'MM-1', 'title' => 'WaicAction_it_call_memory class loaded' ],
					[ 'id' => 'MM-2', 'title' => 'Block code = it_call_memory' ],
					[ 'id' => 'MM-3', 'title' => 'save_studio_output() method exists' ],
					[ 'id' => 'MM-4', 'title' => 'create_conversation_snapshot() method exists' ],
					[ 'id' => 'MM-5', 'title' => 'Output variable: memory_spec declared' ],
				],
			],
			[
				'group'   => 'Block: it_call_content (Sprint 2)',
				'icon'    => '📝',
				'entries' => [
					[ 'id' => 'CT-1', 'title' => 'WaicAction_it_call_content class loaded' ],
					[ 'id' => 'CT-2', 'title' => 'Block code = it_call_content' ],
					[ 'id' => 'CT-3', 'title' => 'save_content_studio_output() method exists' ],
					[ 'id' => 'CT-4', 'title' => 'inherit_from_previous_nodes() recognizes research_summary' ],
					[ 'id' => 'CT-5', 'title' => 'inherit_from_previous_nodes() recognizes memory_spec' ],
					[ 'id' => 'CT-6', 'title' => 'getResults() fires mw:execute_start trace' ],
				],
			],
			[
				'group'   => 'Block: it_call_reflection (Sprint 3)',
				'icon'    => '🎯',
				'entries' => [
					[ 'id' => 'RF-1', 'title' => 'WaicAction_it_call_reflection class loaded' ],
					[ 'id' => 'RF-2', 'title' => 'Block code = it_call_reflection' ],
					[ 'id' => 'RF-3', 'title' => 'save_reflection_studio_output() method exists' ],
					[ 'id' => 'RF-4', 'title' => 'get_session_studio_outputs() method exists' ],
					[ 'id' => 'RF-5', 'title' => 'run_llm_assessment() method exists' ],
					[ 'id' => 'RF-6', 'title' => 'send_retry_message() method exists' ],
					[ 'id' => 'RF-7', 'title' => 'EXPECTED_TOOLS constant includes all 4 blocks' ],
				],
			],
			[
				'group'   => 'Pipeline Wiring — Auto-chain & Bookends (Sprint 0-3)',
				'icon'    => '⛓️',
				'entries' => [
					[ 'id' => 'PW-1', 'title' => 'Content auto-chain references correct research node index' ],
					[ 'id' => 'PW-2', 'title' => 'Bookend: it_call_memory auto-injected between research & content' ],
					[ 'id' => 'PW-3', 'title' => 'Bookend: it_call_reflection auto-appended at end' ],
					[ 'id' => 'PW-4', 'title' => 'ai_expert_research.md skill file exists' ],
					[ 'id' => 'PW-5', 'title' => 'ai_expert_research.md has archetype D (steps: array)' ],
				],
			],
			[
				'group'   => 'Studio Outputs & Trace (Cross-cutting)',
				'icon'    => '💾',
				'entries' => [
					[ 'id' => 'SO-1', 'title' => 'BizCity_Output_Store::save_artifact() callable' ],
					[ 'id' => 'SO-2', 'title' => 'Pipeline trace action bizcity_intent_pipeline_log registered' ],
					[ 'id' => 'SO-3', 'title' => 'BizCity_Pipeline_Messenger class available' ],
					[ 'id' => 'SO-4', 'title' => 'BizCity_Intent_Todos class available' ],
				],
			],
			[
				'group'   => 'Intent Core Integration — Scenario Generator (D-path)',
				'icon'    => '🎬',
				'entries' => [
					[ 'id' => 'IC-1', 'title' => 'BizCity_Scenario_Generator::generate_from_agentic() exists' ],
					[ 'id' => 'IC-2', 'title' => 'tool_labels has it_call_research entry' ],
					[ 'id' => 'IC-3', 'title' => 'tool_labels has it_call_memory entry' ],
					[ 'id' => 'IC-4', 'title' => 'tool_labels has it_call_reflection entry' ],
					[ 'id' => 'IC-5', 'title' => 'tool_taxonomy has pipeline_infra/pipeline_block entries' ],
					[ 'id' => 'IC-6', 'title' => 'save_d_pipeline_as_task() calls generate_from_agentic()' ],
					[ 'id' => 'IC-7', 'title' => 'Fallback insert uses correct WAIC column format' ],
				],
			],
		];
	}

	/* ════════════════════════════════════════════════════════════════════
	 * VERIFICATIONS
	 * ════════════════════════════════════════════════════════════════════ */

	protected function run_verifications(): void {
		$this->verify_bridge();
		$this->verify_planner_block();
		$this->verify_research_block();
		$this->verify_memory_block();
		$this->verify_content_block();
		$this->verify_reflection_block();
		$this->verify_pipeline_wiring();
		$this->verify_studio_trace();
		$this->verify_intent_core();
	}

	/* ── Skill Pipeline Bridge ── */
	private function verify_bridge(): void {
		$loaded = class_exists( 'BizCity_Skill_Pipeline_Bridge' );
		$this->assert( 'BR-1', 'BizCity_Skill_Pipeline_Bridge loaded', $loaded,
			$loaded ? 'OK' : 'class-skill-pipeline-bridge.php not loaded' );

		if ( ! $loaded ) {
			for ( $i = 2; $i <= 7; $i++ ) {
				$this->skip( "BR-{$i}", 'Bridge class not loaded' );
			}
			return;
		}

		// BR-2..BR-5: Inference map entries
		$ref = new ReflectionMethod( 'BizCity_Skill_Pipeline_Bridge', 'get_step_inference_map' );
		$ref->setAccessible( true );
		$map    = $ref->invoke( null );
		$values = array_values( $map );

		$this->assert( 'BR-2', 'Inference map has it_call_research',     in_array( 'it_call_research', $values, true ) );
		$this->assert( 'BR-3', 'Inference map has it_call_content',      in_array( 'it_call_content', $values, true ) );
		$this->assert( 'BR-4', 'Inference map has it_call_memory',       in_array( 'it_call_memory', $values, true ) );
		$this->assert( 'BR-5', 'Inference map has it_call_reflection',   in_array( 'it_call_reflection', $values, true ) );

		// BR-6: inject_bookend_nodes method
		$this->assert( 'BR-6', 'inject_bookend_nodes() exists',
			method_exists( 'BizCity_Skill_Pipeline_Bridge', 'inject_bookend_nodes' ) );

		// BR-7: generate_pipeline_from_steps method
		$this->assert( 'BR-7', 'generate_pipeline_from_steps() exists',
			method_exists( 'BizCity_Skill_Pipeline_Bridge', 'generate_pipeline_from_steps' ) );
	}

	/* ── it_todos_planner ── */
	private function verify_planner_block(): void {
		$loaded = class_exists( 'WaicAction_it_todos_planner' );
		$this->assert( 'PL-1', 'WaicAction_it_todos_planner loaded', $loaded,
			$loaded ? 'OK' : 'it_todos_planner.php block not discovered' );

		if ( ! $loaded ) {
			for ( $i = 2; $i <= 4; $i++ ) {
				$this->skip( "PL-{$i}", 'Block class not loaded' );
			}
			return;
		}

		// PL-2: code
		$block = new WaicAction_it_todos_planner();
		$this->assert( 'PL-2', 'Block code = it_todos_planner',
			$this->get_block_code( $block ) === 'it_todos_planner' );

		// PL-3: trace in getResults
		$source = $this->get_method_source( 'WaicAction_it_todos_planner', 'getResults' );
		$this->assert( 'PL-3', 'getResults fires mw:execute_start',
			strpos( $source, 'mw:execute_start' ) !== false );

		// PL-4: studio output method
		$this->assert( 'PL-4', 'save_plan_studio_output() exists',
			method_exists( 'WaicAction_it_todos_planner', 'save_plan_studio_output' ) );
	}

	/* ── it_call_research ── */
	private function verify_research_block(): void {
		$loaded = class_exists( 'WaicAction_it_call_research' );
		$this->assert( 'RS-1', 'WaicAction_it_call_research loaded', $loaded,
			$loaded ? 'OK' : 'it_call_research.php block not discovered' );

		if ( ! $loaded ) {
			for ( $i = 2; $i <= 6; $i++ ) {
				$this->skip( "RS-{$i}", 'Block class not loaded' );
			}
			return;
		}

		// RS-2: code
		$block = new WaicAction_it_call_research();
		$this->assert( 'RS-2', 'Block code = it_call_research',
			$this->get_block_code( $block ) === 'it_call_research' );

		// RS-3: studio output method
		$this->assert( 'RS-3', 'save_studio_output() exists',
			method_exists( 'WaicAction_it_call_research', 'save_studio_output' ) );

		// RS-4: dual-write BCN
		$this->assert( 'RS-4', 'write_to_bcn_sources() exists',
			method_exists( 'WaicAction_it_call_research', 'write_to_bcn_sources' ) );

		// RS-5: dual-write webchat
		$this->assert( 'RS-5', 'write_to_webchat_sources() exists',
			method_exists( 'WaicAction_it_call_research', 'write_to_webchat_sources' ) );

		// RS-6: output variable declared
		$vars = $block->getVariables();
		$this->assert( 'RS-6', 'research_summary variable declared',
			isset( $vars['research_summary'] ) );
	}

	/* ── it_call_memory ── */
	private function verify_memory_block(): void {
		$loaded = class_exists( 'WaicAction_it_call_memory' );
		$this->assert( 'MM-1', 'WaicAction_it_call_memory loaded', $loaded,
			$loaded ? 'OK' : 'it_call_memory.php block not discovered' );

		if ( ! $loaded ) {
			for ( $i = 2; $i <= 5; $i++ ) {
				$this->skip( "MM-{$i}", 'Block class not loaded' );
			}
			return;
		}

		// MM-2: code
		$block = new WaicAction_it_call_memory();
		$this->assert( 'MM-2', 'Block code = it_call_memory',
			$this->get_block_code( $block ) === 'it_call_memory' );

		// MM-3: studio output
		$this->assert( 'MM-3', 'save_studio_output() exists',
			method_exists( 'WaicAction_it_call_memory', 'save_studio_output' ) );

		// MM-4: conversation snapshot
		$this->assert( 'MM-4', 'create_conversation_snapshot() exists',
			method_exists( 'WaicAction_it_call_memory', 'create_conversation_snapshot' ) );

		// MM-5: output variable
		$vars = $block->getVariables();
		$this->assert( 'MM-5', 'memory_spec variable declared',
			isset( $vars['memory_spec'] ) );
	}

	/* ── it_call_content ── */
	private function verify_content_block(): void {
		$loaded = class_exists( 'WaicAction_it_call_content' );
		$this->assert( 'CT-1', 'WaicAction_it_call_content loaded', $loaded,
			$loaded ? 'OK' : 'it_call_content.php block not discovered' );

		if ( ! $loaded ) {
			for ( $i = 2; $i <= 6; $i++ ) {
				$this->skip( "CT-{$i}", 'Block class not loaded' );
			}
			return;
		}

		// CT-2: code
		$block = new WaicAction_it_call_content();
		$this->assert( 'CT-2', 'Block code = it_call_content',
			$this->get_block_code( $block ) === 'it_call_content' );

		// CT-3: studio output
		$this->assert( 'CT-3', 'save_content_studio_output() exists',
			method_exists( 'WaicAction_it_call_content', 'save_content_studio_output' ) );

		// CT-4: inherit recognizes research_summary
		$source = $this->get_method_source( 'WaicAction_it_call_content', 'inherit_from_previous_nodes' );
		$this->assert( 'CT-4', 'inherit recognizes research_summary',
			strpos( $source, 'research_summary' ) !== false );

		// CT-5: inherit recognizes memory_spec
		$this->assert( 'CT-5', 'inherit recognizes memory_spec',
			strpos( $source, 'memory_spec' ) !== false );

		// CT-6: trace in getResults
		$gr_source = $this->get_method_source( 'WaicAction_it_call_content', 'getResults' );
		$this->assert( 'CT-6', 'getResults fires mw:execute_start',
			strpos( $gr_source, 'mw:execute_start' ) !== false );
	}

	/* ── it_call_reflection ── */
	private function verify_reflection_block(): void {
		$loaded = class_exists( 'WaicAction_it_call_reflection' );
		$this->assert( 'RF-1', 'WaicAction_it_call_reflection loaded', $loaded,
			$loaded ? 'OK' : 'it_call_reflection.php block not discovered' );

		if ( ! $loaded ) {
			for ( $i = 2; $i <= 7; $i++ ) {
				$this->skip( "RF-{$i}", 'Block class not loaded' );
			}
			return;
		}

		// RF-2: code
		$block = new WaicAction_it_call_reflection();
		$this->assert( 'RF-2', 'Block code = it_call_reflection',
			$this->get_block_code( $block ) === 'it_call_reflection' );

		// RF-3: studio output
		$this->assert( 'RF-3', 'save_reflection_studio_output() exists',
			method_exists( 'WaicAction_it_call_reflection', 'save_reflection_studio_output' ) );

		// RF-4: session studio outputs
		$this->assert( 'RF-4', 'get_session_studio_outputs() exists',
			method_exists( 'WaicAction_it_call_reflection', 'get_session_studio_outputs' ) );

		// RF-5: LLM assessment
		$this->assert( 'RF-5', 'run_llm_assessment() exists',
			method_exists( 'WaicAction_it_call_reflection', 'run_llm_assessment' ) );

		// RF-6: retry message
		$this->assert( 'RF-6', 'send_retry_message() exists',
			method_exists( 'WaicAction_it_call_reflection', 'send_retry_message' ) );

		// RF-7: EXPECTED_TOOLS constant
		$ref = new ReflectionClass( 'WaicAction_it_call_reflection' );
		if ( $ref->hasConstant( 'EXPECTED_TOOLS' ) ) {
			$expected = $ref->getConstant( 'EXPECTED_TOOLS' );
			$has_all  = in_array( 'it_todos_planner', $expected, true )
				&& in_array( 'it_call_research', $expected, true )
				&& in_array( 'it_call_memory', $expected, true )
				&& in_array( 'it_call_content', $expected, true );
			$this->assert( 'RF-7', 'EXPECTED_TOOLS has all 4 blocks', $has_all,
				'Contains: ' . implode( ', ', $expected ) );
		} else {
			$this->assert( 'RF-7', 'EXPECTED_TOOLS constant exists', false );
		}
	}

	/* ── Pipeline Wiring ── */
	private function verify_pipeline_wiring(): void {
		// PW-1: Content auto-chain uses tracked research node index
		if ( class_exists( 'BizCity_Skill_Pipeline_Bridge' ) ) {
			$source = $this->get_method_source( 'BizCity_Skill_Pipeline_Bridge', 'generate_pipeline_from_steps' );
			$has_tracked = strpos( $source, '$research_node_index' ) !== false;
			$this->assert( 'PW-1', 'Content auto-chain tracks research node index', $has_tracked,
				$has_tracked ? 'Uses $research_node_index — safe across memory insertion' : 'Uses hardcoded node_index - 1' );
		} else {
			$this->skip( 'PW-1', 'Bridge class not loaded' );
		}

		// PW-2: Bookend memory injection
		if ( class_exists( 'BizCity_Skill_Pipeline_Bridge' ) ) {
			$source = $this->get_method_source( 'BizCity_Skill_Pipeline_Bridge', 'inject_bookend_nodes' );
			$has_memory = strpos( $source, 'it_call_memory' ) !== false;
			$this->assert( 'PW-2', 'Bookend injects it_call_memory', $has_memory );
		} else {
			$this->skip( 'PW-2', 'Bridge class not loaded' );
		}

		// PW-3: Bookend reflection injection
		if ( class_exists( 'BizCity_Skill_Pipeline_Bridge' ) ) {
			$source = $this->get_method_source( 'BizCity_Skill_Pipeline_Bridge', 'inject_bookend_nodes' );
			$has_reflection = strpos( $source, 'it_call_reflection' ) !== false;
			$this->assert( 'PW-3', 'Bookend injects it_call_reflection', $has_reflection );
		} else {
			$this->skip( 'PW-3', 'Bridge class not loaded' );
		}

		// PW-4: Skill file exists
		$skill_path = defined( 'BIZCITY_TWIN_AI_DIR' )
			? BIZCITY_TWIN_AI_DIR . 'core/skills/library/ai_expert_research.md'
			: '';
		if ( $skill_path && file_exists( $skill_path ) ) {
			$this->assert( 'PW-4', 'ai_expert_research.md exists', true );

			// PW-5: Has steps array (archetype D)
			$raw = file_get_contents( $skill_path );
			$has_steps = (bool) preg_match( '/^steps:\s*$/m', $raw );
			$this->assert( 'PW-5', 'ai_expert_research.md has steps: (archetype D)', $has_steps,
				$has_steps ? 'Archetype D confirmed' : 'steps: key not found in frontmatter' );
		} else {
			$alt_path = dirname( __DIR__, 2 ) . '/core/skills/library/ai_expert_research.md';
			$exists   = file_exists( $alt_path );
			$this->assert( 'PW-4', 'ai_expert_research.md exists', $exists,
				$exists ? $alt_path : 'File not found — check deployment' );

			if ( $exists ) {
				$raw = file_get_contents( $alt_path );
				$has_steps = (bool) preg_match( '/^steps:\s*$/m', $raw );
				$this->assert( 'PW-5', 'ai_expert_research.md has steps: (archetype D)', $has_steps );
			} else {
				$this->skip( 'PW-5', 'Skill file not found' );
			}
		}
	}

	/* ── Studio Outputs & Trace ── */
	private function verify_studio_trace(): void {
		// SO-1: Output Store
		$this->assert( 'SO-1', 'BizCity_Output_Store::save_artifact() callable',
			class_exists( 'BizCity_Output_Store' ) && method_exists( 'BizCity_Output_Store', 'save_artifact' ),
			class_exists( 'BizCity_Output_Store' ) ? 'OK' : 'BizCity_Output_Store not loaded' );

		// SO-2: Pipeline trace action
		$has_hook = has_action( 'bizcity_intent_pipeline_log' );
		$this->assert( 'SO-2', 'bizcity_intent_pipeline_log action has listeners',
			$has_hook !== false,
			$has_hook !== false ? 'Listeners registered' : 'No listeners — Working Panel trace will be silent' );

		// SO-3: Pipeline Messenger
		$this->assert( 'SO-3', 'BizCity_Pipeline_Messenger available',
			class_exists( 'BizCity_Pipeline_Messenger' ),
			class_exists( 'BizCity_Pipeline_Messenger' ) ? 'OK' : 'Not loaded — pipeline progress messages will be skipped' );

		// SO-4: Intent Todos
		$this->assert( 'SO-4', 'BizCity_Intent_Todos available',
			class_exists( 'BizCity_Intent_Todos' ),
			class_exists( 'BizCity_Intent_Todos' ) ? 'OK' : 'Not loaded — planner + reflection blocks will fail' );
	}

	/* ── Intent Core Integration ── */
	private function verify_intent_core(): void {
		$sg_loaded = class_exists( 'BizCity_Scenario_Generator' );

		// IC-1: generate_from_agentic exists
		$has_method = $sg_loaded && method_exists( 'BizCity_Scenario_Generator', 'generate_from_agentic' );
		$this->assert( 'IC-1', 'generate_from_agentic() exists', $has_method,
			$has_method ? 'OK' : ( $sg_loaded ? 'Method missing' : 'Scenario Generator not loaded' ) );

		if ( ! $sg_loaded ) {
			for ( $i = 2; $i <= 7; $i++ ) {
				$this->skip( "IC-{$i}", 'Scenario Generator not loaded' );
			}
			return;
		}

		// IC-2..IC-4: tool_labels for new blocks
		$ref = new ReflectionProperty( 'BizCity_Scenario_Generator', 'tool_labels' );
		$ref->setAccessible( true );
		$labels = $ref->getValue();

		$this->assert( 'IC-2', 'tool_labels has it_call_research',   isset( $labels['it_call_research'] ) );
		$this->assert( 'IC-3', 'tool_labels has it_call_memory',     isset( $labels['it_call_memory'] ) );
		$this->assert( 'IC-4', 'tool_labels has it_call_reflection', isset( $labels['it_call_reflection'] ) );

		// IC-5: tool_taxonomy has pipeline_infra entries
		$ref2 = new ReflectionProperty( 'BizCity_Scenario_Generator', 'tool_taxonomy' );
		$ref2->setAccessible( true );
		$taxonomy = $ref2->getValue();
		$has_infra = isset( $taxonomy['it_call_research'] )
			&& ( $taxonomy['it_call_research']['family'] ?? '' ) === 'pipeline_infra';
		$this->assert( 'IC-5', 'tool_taxonomy has pipeline_infra entries', $has_infra );

		// IC-6: save_d_pipeline_as_task calls generate_from_agentic
		if ( class_exists( 'BizCity_Skill_Pipeline_Bridge' ) ) {
			$source = $this->get_method_source( 'BizCity_Skill_Pipeline_Bridge', 'save_d_pipeline_as_task' );
			$calls_agentic = strpos( $source, 'generate_from_agentic' ) !== false;
			$this->assert( 'IC-6', 'save_d_pipeline_as_task uses generate_from_agentic', $calls_agentic,
				$calls_agentic ? 'OK' : 'Still calls generate() — format mismatch!' );
		} else {
			$this->skip( 'IC-6', 'Bridge class not loaded' );
		}

		// IC-7: Fallback uses correct WAIC columns
		if ( class_exists( 'BizCity_Skill_Pipeline_Bridge' ) ) {
			$source = $this->get_method_source( 'BizCity_Skill_Pipeline_Bridge', 'save_d_pipeline_as_task' );
			$has_feature = strpos( $source, "'feature'" ) !== false;
			$has_author  = strpos( $source, "'author'" ) !== false;
			$no_user_id  = strpos( $source, "'user_id'" ) === false || strpos( $source, "'user_id'     => \$user_id," ) === false;
			$this->assert( 'IC-7', 'Fallback uses WAIC columns (feature, author, params)',
				$has_feature && $has_author,
				( $has_feature && $has_author ) ? 'OK' : 'Missing feature/author columns — insert will fail' );
		} else {
			$this->skip( 'IC-7', 'Bridge class not loaded' );
		}
	}

	/* ════════════════════════════════════════════════════════════════════
	 * ════════════════════════════════════════════════════════════════════ */

	/**
	 * Get the block code from a WaicAction instance via reflection.
	 */
	private function get_block_code( $block ): string {
		if ( ! is_object( $block ) ) {
			return '';
		}
		$ref = new ReflectionObject( $block );
		if ( $ref->hasProperty( '_code' ) ) {
			$prop = $ref->getProperty( '_code' );
			$prop->setAccessible( true );
			return (string) $prop->getValue( $block );
		}
		return '';
	}
}
