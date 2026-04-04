<?php
/**
 * Changelog — Phase 1: Unified Pipeline & Multi-Goal Orchestration
 *
 * Validates: Core Planner, Scenario Generator, Step Executor,
 *            Intent Tools registry, Trust Tier hierarchy.
 *
 * @package BizCity_Twin_AI
 * @since   4.2.0
 */

defined( 'ABSPATH' ) or die();

class BizCity_Changelog_Phase1 extends BizCity_Changelog_Base {

	public function get_phase_id(): string {
		return '1';
	}

	public function get_phase_title(): string {
		return 'Unified Pipeline & Multi-Goal Orchestration';
	}

	public function get_description(): string {
		return 'Trust Ranking (TIER 0-4), Core Planner multi-goal, Scenario Generator, Step Executor, Intent Tools registry, bc_instant_run trigger.';
	}

	public function get_dates(): array {
		return [ 'started' => '2026-03-01', 'updated' => '2026-03-15' ];
	}

	public function get_changelog(): array {
		return [
			[
				'group'   => 'Orchestration Engine',
				'icon'    => '🎯',
				'entries' => [
					[ 'id' => 'OE-1', 'title' => 'BizCity_Core_Planner class exists' ],
					[ 'id' => 'OE-2', 'title' => 'BizCity_Scenario_Generator class exists' ],
					[ 'id' => 'OE-3', 'title' => 'BizCity_Step_Executor class exists' ],
				],
			],
			[
				'group'   => 'Intent Tools Registry',
				'icon'    => '🔧',
				'entries' => [
					[ 'id' => 'IT-1', 'title' => 'BizCity_Intent_Tools class exists' ],
					[ 'id' => 'IT-2', 'title' => 'execute() method — unified tool dispatch' ],
					[ 'id' => 'IT-3', 'title' => 'has() + get_schema() + get_callback()' ],
					[ 'id' => 'IT-4', 'title' => 'BizCity_Intent_Tool_Index class exists' ],
				],
			],
			[
				'group'   => 'Trust Tier System',
				'icon'    => '🛡️',
				'entries' => [
					[ 'id' => 'TT-1', 'title' => 'Trust Tier constants/hierarchy in tool registry' ],
					[ 'id' => 'TT-2', 'title' => 'bizcity_tool_registry table or meta exists' ],
				],
			],
			[
				'group'   => 'Trigger & Legacy Migration',
				'icon'    => '🔄',
				'entries' => [
					[ 'id' => 'TG-1', 'title' => 'bc_instant_run trigger registered' ],
					[ 'id' => 'TG-2', 'title' => 'helper-legacy directory exists (migrated helpers)' ],
				],
			],
		];
	}

	protected function run_verifications(): void {
		$this->verify_orchestration();
		$this->verify_intent_tools();
		$this->verify_trust_tier();
		$this->verify_triggers();
	}

	/* ── Orchestration ── */
	private function verify_orchestration(): void {
		$this->assert( 'OE-1', 'BizCity_Core_Planner exists',
			class_exists( 'BizCity_Core_Planner' ),
			class_exists( 'BizCity_Core_Planner' ) ? 'core/intent/includes/orchestration/' : 'Not loaded' );

		$this->assert( 'OE-2', 'BizCity_Scenario_Generator exists',
			class_exists( 'BizCity_Scenario_Generator' ),
			class_exists( 'BizCity_Scenario_Generator' ) ? 'Loaded' : 'Not loaded' );

		$this->assert( 'OE-3', 'BizCity_Step_Executor exists',
			class_exists( 'BizCity_Step_Executor' ),
			class_exists( 'BizCity_Step_Executor' ) ? 'Loaded' : 'Not loaded' );
	}

	/* ── Intent Tools ── */
	private function verify_intent_tools(): void {
		$exists = class_exists( 'BizCity_Intent_Tools' );
		$this->assert( 'IT-1', 'BizCity_Intent_Tools exists', $exists );

		if ( ! $exists ) {
			$this->skip( 'IT-2', 'Intent Tools not loaded' );
			$this->skip( 'IT-3', 'Intent Tools not loaded' );
			$this->skip( 'IT-4', 'Intent Tools not loaded' );
			return;
		}

		$this->assert( 'IT-2', 'execute() exists', method_exists( 'BizCity_Intent_Tools', 'execute' ) );

		$has_has    = method_exists( 'BizCity_Intent_Tools', 'has' );
		$has_schema = method_exists( 'BizCity_Intent_Tools', 'get_schema' );
		$has_cb     = method_exists( 'BizCity_Intent_Tools', 'get_callback' );
		$this->assert( 'IT-3', 'has() + get_schema() + get_callback()',
			$has_has && $has_schema && $has_cb,
			'has=' . ( $has_has ? '✅' : '❌' ) . ' schema=' . ( $has_schema ? '✅' : '❌' ) . ' callback=' . ( $has_cb ? '✅' : '❌' ) );

		$this->assert( 'IT-4', 'BizCity_Intent_Tool_Index exists',
			class_exists( 'BizCity_Intent_Tool_Index' ),
			class_exists( 'BizCity_Intent_Tool_Index' ) ? 'Loaded' : 'Not loaded' );
	}

	/* ── Trust Tier ── */
	private function verify_trust_tier(): void {
		// Check if trust tier constants exist in Intent Tools or a dedicated class
		$has_tier = false;
		$tier_detail = '';

		if ( class_exists( 'BizCity_Intent_Tools' ) ) {
			$ref = new ReflectionClass( 'BizCity_Intent_Tools' );
			foreach ( $ref->getConstants() as $name => $val ) {
				if ( stripos( $name, 'TIER' ) !== false || stripos( $name, 'TRUST' ) !== false ) {
					$has_tier = true;
					$tier_detail = "Found constant: {$name}";
					break;
				}
			}
			if ( ! $has_tier ) {
				// Check if trust_tier field is used in schemas
				$file = $ref->getFileName();
				if ( $file && is_readable( $file ) ) {
					$src = file_get_contents( $file );
					$has_tier = strpos( $src, 'trust_tier' ) !== false || strpos( $src, 'trust_level' ) !== false;
					$tier_detail = $has_tier ? 'trust_tier referenced in code' : 'No trust_tier reference found';
				}
			}
		}

		$this->assert( 'TT-1', 'Trust Tier in tool registry', $has_tier, $tier_detail );

		// Check DB table or tool meta
		global $wpdb;
		$tool_table = $wpdb->prefix . 'bizcity_tool_registry';
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tool_table ) ) === $tool_table;

		if ( $table_exists ) {
			$cols = $wpdb->get_results( "SHOW COLUMNS FROM `{$tool_table}`", ARRAY_A );
			$col_names = array_column( $cols, 'Field' );
			$has_col = in_array( 'trust_tier', $col_names, true );
			$this->assert( 'TT-2', 'Tool registry table + trust_tier column', $has_col,
				'Columns: ' . implode( ', ', array_slice( $col_names, 0, 8 ) ) );
		} else {
			// May use tool schema/meta instead of dedicated table
			$this->skip( 'TT-2', 'bizcity_tool_registry table not found — trust tier may be in schema meta' );
		}
	}

	/* ── Triggers ── */
	private function verify_triggers(): void {
		// bc_instant_run
		$has_trigger = false;
		if ( class_exists( 'WaicTrigger_bc_instant_run' ) ) {
			$has_trigger = true;
		} elseif ( function_exists( 'waic_get_trigger_types' ) ) {
			$types = waic_get_trigger_types();
			$has_trigger = isset( $types['bc_instant_run'] );
		}
		// Fallback: check if the file exists
		if ( ! $has_trigger ) {
			$file = dirname( __DIR__ ) . '/modules/webchat/blocks/triggers/bc_instant_run.php';
			$has_trigger = file_exists( $file );
		}

		$this->assert( 'TG-1', 'bc_instant_run trigger', $has_trigger,
			$has_trigger ? 'Trigger available' : 'Trigger not found' );

		$legacy_dir = dirname( __DIR__ ) . '/core/helper-legacy';
		$this->assert( 'TG-2', 'helper-legacy directory exists', is_dir( $legacy_dir ),
			is_dir( $legacy_dir ) ? 'Migrated helpers present' : 'Directory missing' );
	}
}
