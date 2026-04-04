<?php
/**
 * Changelog — Phase 1.2: Skill Pipeline Integration
 *
 * Validates: Skill Pipeline Bridge, Skill Context, Skill Manager,
 *            Skill Tool Map, Memory Spec, Block Schema Adapter.
 *
 * @package BizCity_Twin_AI
 * @since   4.2.0
 */

defined( 'ABSPATH' ) or die();

class BizCity_Changelog_Phase12 extends BizCity_Changelog_Base {

	public function get_phase_id(): string {
		return '1.2';
	}

	public function get_phase_title(): string {
		return 'Skill Pipeline Integration';
	}

	public function get_description(): string {
		return 'Skill Pipeline Bridge: queue → process pending. Skill Context, Manager, Tool Map. Block Schema Adapter, Memory Spec integration.';
	}

	public function get_dates(): array {
		return [ 'started' => '2026-03-12', 'updated' => '2026-03-22' ];
	}

	public function get_changelog(): array {
		return [
			[
				'group'   => 'Skill Pipeline Bridge',
				'icon'    => '🌉',
				'entries' => [
					[ 'id' => 'SP-1', 'title' => 'BizCity_Skill_Pipeline_Bridge class exists' ],
					[ 'id' => 'SP-2', 'title' => 'Singleton — get_instance()' ],
					[ 'id' => 'SP-3', 'title' => 'queue_pipeline_generation()' ],
					[ 'id' => 'SP-4', 'title' => 'process_pending_pipeline()' ],
					[ 'id' => 'SP-5', 'title' => 'get_pipeline_result() or get_status()' ],
				],
			],
			[
				'group'   => 'Skill Management',
				'icon'    => '🎓',
				'entries' => [
					[ 'id' => 'SM-1', 'title' => 'BizCity_Skill_Context class exists' ],
					[ 'id' => 'SM-2', 'title' => 'BizCity_Skill_Manager class exists' ],
					[ 'id' => 'SM-3', 'title' => 'BizCity_Skill_Tool_Map class exists' ],
				],
			],
			[
				'group'   => 'Schema & Memory',
				'icon'    => '📐',
				'entries' => [
					[ 'id' => 'BK-1', 'title' => 'BizCity_Block_Schema_Adapter class exists' ],
					[ 'id' => 'BK-2', 'title' => 'BizCity_Memory_Spec class exists' ],
					[ 'id' => 'BK-3', 'title' => 'Memory Spec build or compile method' ],
				],
			],
		];
	}

	protected function run_verifications(): void {
		$this->verify_pipeline_bridge();
		$this->verify_skill_management();
		$this->verify_schema_memory();
	}

	/* ── Skill Pipeline Bridge ── */
	private function verify_pipeline_bridge(): void {
		$exists = class_exists( 'BizCity_Skill_Pipeline_Bridge' );
		$this->assert( 'SP-1', 'BizCity_Skill_Pipeline_Bridge exists', $exists );

		if ( ! $exists ) {
			$this->skip( 'SP-2', 'Bridge not loaded' );
			$this->skip( 'SP-3', 'Bridge not loaded' );
			$this->skip( 'SP-4', 'Bridge not loaded' );
			$this->skip( 'SP-5', 'Bridge not loaded' );
			return;
		}

		$this->assert( 'SP-2', 'Singleton get_instance()',
			method_exists( 'BizCity_Skill_Pipeline_Bridge', 'get_instance' ) );

		$this->assert( 'SP-3', 'queue_pipeline_generation()',
			method_exists( 'BizCity_Skill_Pipeline_Bridge', 'queue_pipeline_generation' ) );

		$this->assert( 'SP-4', 'process_pending_pipeline()',
			method_exists( 'BizCity_Skill_Pipeline_Bridge', 'process_pending_pipeline' ) );

		$has_result = method_exists( 'BizCity_Skill_Pipeline_Bridge', 'get_pipeline_result' )
		           || method_exists( 'BizCity_Skill_Pipeline_Bridge', 'get_status' );
		$this->assert( 'SP-5', 'get_pipeline_result() or get_status()', $has_result,
			$has_result ? 'Available' : 'No result accessor found' );
	}

	/* ── Skill Management ── */
	private function verify_skill_management(): void {
		$classes = [
			'SM-1' => 'BizCity_Skill_Context',
			'SM-2' => 'BizCity_Skill_Manager',
			'SM-3' => 'BizCity_Skill_Tool_Map',
		];

		foreach ( $classes as $id => $class ) {
			$this->assert( $id, "{$class} exists", class_exists( $class ),
				class_exists( $class ) ? 'Loaded' : 'Not loaded' );
		}
	}

	/* ── Schema & Memory ── */
	private function verify_schema_memory(): void {
		$this->assert( 'BK-1', 'BizCity_Block_Schema_Adapter exists',
			class_exists( 'BizCity_Block_Schema_Adapter' ),
			class_exists( 'BizCity_Block_Schema_Adapter' ) ? 'Loaded' : 'Not loaded' );

		$spec_exists = class_exists( 'BizCity_Memory_Spec' );
		$this->assert( 'BK-2', 'BizCity_Memory_Spec exists', $spec_exists );

		if ( ! $spec_exists ) {
			$this->skip( 'BK-3', 'Memory Spec not loaded' );
			return;
		}

		$has_build = method_exists( 'BizCity_Memory_Spec', 'build' )
		          || method_exists( 'BizCity_Memory_Spec', 'compile' )
		          || method_exists( 'BizCity_Memory_Spec', 'get_spec' );
		$this->assert( 'BK-3', 'Memory Spec build/compile/get_spec', $has_build,
			$has_build ? 'Method available' : 'No builder method found' );
	}
}
