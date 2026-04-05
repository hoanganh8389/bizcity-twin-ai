<?php
/**
 * Changelog — Phase 1.9: Unified Output — Studio × Chat × Automation
 *
 * Validates: Resource Resolver, Output Store, Tool Registry,
 * Multi-channel Distribution, Trace integration, WP-Cron cleanup.
 *
 * @package BizCity_Twin_AI
 * @since   4.5.0
 */

defined( 'ABSPATH' ) or die();

class BizCity_Changelog_Phase19 extends BizCity_Changelog_Base {

	public function get_phase_id(): string {
		return '1.9';
	}

	public function get_phase_title(): string {
		return 'Unified Output: Studio × Chat × Automation';
	}

	public function get_description(): string {
		return 'Resource Resolver (6 layers), Output Store (auto-save + cleanup), Tool Registry (additive aggregator), Multi-channel Distribution (Web+FB), Trace integration';
	}

	public function get_dates(): array {
		return [ 'started' => '2026-03-28', 'updated' => '2026-04-05' ];
	}

	public function get_changelog(): array {
		return [
			[
				'group'   => 'Resource Resolver (Sprint 0)',
				'icon'    => '📦',
				'entries' => [
					[ 'id' => 'RR-1', 'title' => 'BizCity_Resource_Resolver class loaded' ],
					[ 'id' => 'RR-2', 'title' => 'resolve() method exists' ],
					[ 'id' => 'RR-3', 'title' => 'Trace method resource_resolve() exists on BizCity_Twin_Trace' ],
				],
			],
			[
				'group'   => 'Output Store (Sprint 1)',
				'icon'    => '💾',
				'entries' => [
					[ 'id' => 'OS-1', 'title' => 'BizCity_Output_Store class loaded' ],
					[ 'id' => 'OS-2', 'title' => 'studio_outputs table exists (via BCN_Schema_Extend)' ],
					[ 'id' => 'OS-3', 'title' => 'Table has Phase 1.9 columns (caller, tool_id, task_id, invoke_id)' ],
					[ 'id' => 'OS-4', 'title' => 'save_artifact() method exists' ],
					[ 'id' => 'OS-5', 'title' => 'update_distribution_result() method exists' ],
					[ 'id' => 'OS-6', 'title' => 'cleanup_old_outputs() method exists' ],
					[ 'id' => 'OS-7', 'title' => 'WP-Cron bizcity_output_store_cleanup scheduled' ],
				],
			],
			[
				'group'   => 'Tool Registry (Sprint 2)',
				'icon'    => '🧰',
				'entries' => [
					[ 'id' => 'TR-1', 'title' => 'BizCity_Tool_Registry class loaded' ],
					[ 'id' => 'TR-2', 'title' => 'register() + seal() + get_all() methods exist' ],
					[ 'id' => 'TR-3', 'title' => 'get_studio_tools() returns array' ],
					[ 'id' => 'TR-4', 'title' => 'get_at_tools() returns array' ],
					[ 'id' => 'TR-5', 'title' => 'get_distribution_tools() returns array' ],
					[ 'id' => 'TR-6', 'title' => 'AJAX: bizcity_tool_registry_list registered' ],
				],
			],
			[
				'group'   => 'AJAX — Studio Outputs (Sprint 1-2)',
				'icon'    => '⚡',
				'entries' => [
					[ 'id' => 'AJ-1', 'title' => 'AJAX: bizcity_webchat_studio_outputs registered' ],
					[ 'id' => 'AJ-2', 'title' => 'AJAX: bizcity_webchat_studio_generate registered' ],
					[ 'id' => 'AJ-3', 'title' => 'AJAX: bizcity_webchat_studio_delete_output registered' ],
					[ 'id' => 'AJ-4', 'title' => 'AJAX: bizcity_webchat_studio_skeleton registered' ],
				],
			],
			[
				'group'   => 'Multi-channel Distribution (Sprint 3)',
				'icon'    => '🚀',
				'entries' => [
					[ 'id' => 'DS-1', 'title' => 'AJAX: bizcity_webchat_studio_distribute registered' ],
					[ 'id' => 'DS-2', 'title' => 'ajax_studio_distribute() supports dist_tools[] (multi-channel)' ],
					[ 'id' => 'DS-3', 'title' => 'Distribution functions: bizcity_dist_publish_wp_post exists' ],
					[ 'id' => 'DS-4', 'title' => 'Distribution functions: bizcity_dist_post_facebook exists' ],
					[ 'id' => 'DS-5', 'title' => 'Distribution functions: bizcity_dist_send_email exists' ],
					[ 'id' => 'DS-6', 'title' => 'BizCity_Twin_Trace::distribution() method exists' ],
					[ 'id' => 'DS-7', 'title' => 'BizCity_Twin_Trace::distribution_summary() method exists' ],
				],
			],
			[
				'group'   => 'Auto-Cleanup & Cron (Sprint 4)',
				'icon'    => '🧹',
				'entries' => [
					[ 'id' => 'CL-1', 'title' => 'schedule_cleanup() method exists' ],
					[ 'id' => 'CL-2', 'title' => 'WP-Cron event scheduled (twicedaily)' ],
					[ 'id' => 'CL-3', 'title' => 'cleanup_old_outputs respects pinned + external_url' ],
				],
			],
		];
	}

	/* ════════════════════════════════════════════════════════════════════
	 * VERIFICATIONS
	 * ════════════════════════════════════════════════════════════════════ */

	protected function run_verifications(): void {
		$this->verify_resource_resolver();
		$this->verify_output_store();
		$this->verify_tool_registry();
		$this->verify_ajax_hooks();
		$this->verify_distribution();
		$this->verify_cleanup();
	}

	/* ── Resource Resolver ── */
	private function verify_resource_resolver(): void {
		$loaded = class_exists( 'BizCity_Resource_Resolver' );
		$detail = $loaded ? 'OK' : $this->class_load_detail( 'class-resource-resolver.php' );
		$this->assert( 'RR-1', 'BizCity_Resource_Resolver loaded', $loaded, $detail );

		if ( ! $loaded ) {
			$this->skip( 'RR-2', 'resolve() check skipped — class not loaded' );
		} else {
			$this->assert( 'RR-2', 'resolve() method exists',
				method_exists( 'BizCity_Resource_Resolver', 'resolve' ) );
		}

		// Trace method on BizCity_Twin_Trace
		if ( class_exists( 'BizCity_Twin_Trace' ) ) {
			$this->assert( 'RR-3', 'Trace::resource_resolve() exists',
				method_exists( 'BizCity_Twin_Trace', 'resource_resolve' ) );
		} else {
			$this->skip( 'RR-3', 'BizCity_Twin_Trace not loaded' );
		}
	}

	/* ── Output Store ── */
	private function verify_output_store(): void {
		$loaded = class_exists( 'BizCity_Output_Store' );
		$detail = $loaded ? 'OK' : $this->class_load_detail( 'class-output-store.php' );
		$this->assert( 'OS-1', 'BizCity_Output_Store loaded', $loaded, $detail );

		if ( ! $loaded ) {
			for ( $i = 2; $i <= 7; $i++ ) {
				$this->skip( "OS-{$i}", 'BizCity_Output_Store not loaded' );
			}
			return;
		}

		// OS-2: Table exists
		if ( ! class_exists( 'BCN_Schema_Extend' ) ) {
			$this->skip( 'OS-2', 'BCN_Schema_Extend not loaded' );
			$this->skip( 'OS-3', 'BCN_Schema_Extend not loaded' );
		} else {
			global $wpdb;
			$table = BCN_Schema_Extend::table_studio_outputs();
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
			$this->assert( 'OS-2', 'studio_outputs table exists', $exists, $table );

			// OS-3: Phase 1.9 columns
			if ( $exists ) {
				$cols   = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`" );
				$needed = [ 'caller', 'tool_id', 'task_id', 'invoke_id' ];
				$missing = array_diff( $needed, $cols );
				$this->assert( 'OS-3', 'Phase 1.9 columns present', empty( $missing ),
					empty( $missing ) ? 'All 4 columns found' : 'Missing: ' . implode( ', ', $missing ) );
			} else {
				$this->skip( 'OS-3', 'Table does not exist' );
			}
		}

		// OS-4 .. OS-6: Methods
		$this->assert( 'OS-4', 'save_artifact() exists',
			method_exists( 'BizCity_Output_Store', 'save_artifact' ) );
		$this->assert( 'OS-5', 'update_distribution_result() exists',
			method_exists( 'BizCity_Output_Store', 'update_distribution_result' ) );
		$this->assert( 'OS-6', 'cleanup_old_outputs() exists',
			method_exists( 'BizCity_Output_Store', 'cleanup_old_outputs' ) );

		// OS-7: Cron hook bound — chỉ kiểm tra, KHÔNG tự gọi schedule_cleanup()
		$has_hook = has_action( 'bizcity_output_store_cleanup' );
		$next     = wp_next_scheduled( 'bizcity_output_store_cleanup' );
		$ok       = $has_hook !== false || $next !== false;
		if ( $ok ) {
			$detail = $next ? 'Next run: ' . date( 'Y-m-d H:i:s', $next ) : 'hook registered, cron event pending';
		} else {
			$has_method = method_exists( 'BizCity_Output_Store', 'schedule_cleanup' );
			$detail = $has_method
				? 'Method exists but cron not registered — deploy core/tools/bootstrap.php (need plugins_loaded @21)'
				: 'schedule_cleanup() method missing';
		}
		$this->assert( 'OS-7', 'WP-Cron cleanup hook active', $ok, $detail );
	}

	/* ── Tool Registry ── */
	private function verify_tool_registry(): void {
		$loaded = class_exists( 'BizCity_Tool_Registry' );
		$detail = $loaded ? 'OK' : $this->class_load_detail( 'class-tool-registry.php' );
		$this->assert( 'TR-1', 'BizCity_Tool_Registry loaded', $loaded, $detail );

		if ( ! $loaded ) {
			for ( $i = 2; $i <= 6; $i++ ) {
				$this->skip( "TR-{$i}", 'BizCity_Tool_Registry not loaded' );
			}
			return;
		}

		// TR-2: Core methods
		$core_ok = method_exists( 'BizCity_Tool_Registry', 'register' )
			&& method_exists( 'BizCity_Tool_Registry', 'seal' )
			&& method_exists( 'BizCity_Tool_Registry', 'get_all' );
		$this->assert( 'TR-2', 'register + seal + get_all exist', $core_ok );

		// TR-3: Studio tools
		if ( method_exists( 'BizCity_Tool_Registry', 'get_studio_tools' ) ) {
			$studio = BizCity_Tool_Registry::get_studio_tools();
			$this->assert( 'TR-3', 'get_studio_tools() returns array', is_array( $studio ),
				'count=' . ( is_array( $studio ) ? count( $studio ) : 'N/A' ) );
		} else {
			$this->assert( 'TR-3', 'get_studio_tools() exists', false );
		}

		// TR-4: At tools
		if ( method_exists( 'BizCity_Tool_Registry', 'get_at_tools' ) ) {
			$at = BizCity_Tool_Registry::get_at_tools();
			$this->assert( 'TR-4', 'get_at_tools() returns array', is_array( $at ),
				'count=' . ( is_array( $at ) ? count( $at ) : 'N/A' ) );
		} else {
			$this->assert( 'TR-4', 'get_at_tools() exists', false );
		}

		// TR-5: Distribution tools
		if ( method_exists( 'BizCity_Tool_Registry', 'get_distribution_tools' ) ) {
			$dist = BizCity_Tool_Registry::get_distribution_tools();
			$this->assert( 'TR-5', 'get_distribution_tools() returns array', is_array( $dist ),
				'count=' . ( is_array( $dist ) ? count( $dist ) : 'N/A' ) );
		} else {
			$this->assert( 'TR-5', 'get_distribution_tools() exists', false );
		}

		// TR-6: AJAX hook
		$this->assert( 'TR-6', 'bizcity_tool_registry_list AJAX registered',
			has_action( 'wp_ajax_bizcity_tool_registry_list' ) !== false );
	}

	/* ── AJAX Hooks — Studio Outputs ── */
	private function verify_ajax_hooks(): void {
		$hooks = [
			'AJ-1' => 'bizcity_webchat_studio_outputs',
			'AJ-2' => 'bizcity_webchat_studio_generate',
			'AJ-3' => 'bizcity_webchat_studio_delete_output',
			'AJ-4' => 'bizcity_webchat_studio_skeleton',
		];

		foreach ( $hooks as $id => $action ) {
			$this->assert( $id, "wp_ajax_{$action} registered",
				has_action( "wp_ajax_{$action}" ) !== false );
		}
	}

	/* ── Distribution (Sprint 3) ── */
	private function verify_distribution(): void {
		// DS-1: AJAX hook
		$this->assert( 'DS-1', 'bizcity_webchat_studio_distribute AJAX registered',
			has_action( 'wp_ajax_bizcity_webchat_studio_distribute' ) !== false );

		// DS-2: Multi-channel support — check method accepts dist_tools[]
		if ( class_exists( 'BizCity_WebChat_Ajax_Handlers' ) && method_exists( 'BizCity_WebChat_Ajax_Handlers', 'ajax_studio_distribute' ) ) {
			$ref    = new ReflectionMethod( 'BizCity_WebChat_Ajax_Handlers', 'ajax_studio_distribute' );
			$source = $this->read_method_source(
				$ref->getFileName(), $ref->getStartLine(), min( $ref->getStartLine() + 40, $ref->getEndLine() )
			);
			$multi = strpos( $source, 'dist_tools' ) !== false;
			$this->assert( 'DS-2', 'ajax_studio_distribute supports dist_tools[]', $multi,
				$multi ? 'dist_tools[] detected in method body' : 'dist_tools[] not found' );
		} else {
			$this->skip( 'DS-2', 'BizCity_WebChat_Ajax_Handlers::ajax_studio_distribute not found' );
		}

		// DS-3 .. DS-5: Distribution functions
		$dist_fns = [
			'DS-3' => 'bizcity_dist_publish_wp_post',
			'DS-4' => 'bizcity_dist_post_facebook',
			'DS-5' => 'bizcity_dist_send_email',
		];
		foreach ( $dist_fns as $id => $fn ) {
			$this->assert( $id, "{$fn}() exists", function_exists( $fn ) );
		}

		// DS-6, DS-7: Trace methods
		if ( class_exists( 'BizCity_Twin_Trace' ) ) {
			$this->assert( 'DS-6', 'Trace::distribution() exists',
				method_exists( 'BizCity_Twin_Trace', 'distribution' ) );
			$this->assert( 'DS-7', 'Trace::distribution_summary() exists',
				method_exists( 'BizCity_Twin_Trace', 'distribution_summary' ) );
		} else {
			$this->skip( 'DS-6', 'BizCity_Twin_Trace not loaded' );
			$this->skip( 'DS-7', 'BizCity_Twin_Trace not loaded' );
		}
	}

	/* ── Cleanup / Cron (Sprint 4) ── */
	private function verify_cleanup(): void {
		if ( ! class_exists( 'BizCity_Output_Store' ) ) {
			$this->skip( 'CL-1', 'BizCity_Output_Store not loaded' );
			$this->skip( 'CL-2', 'BizCity_Output_Store not loaded' );
			$this->skip( 'CL-3', 'BizCity_Output_Store not loaded' );
			return;
		}

		// CL-1: schedule_cleanup exists
		$this->assert( 'CL-1', 'schedule_cleanup() exists',
			method_exists( 'BizCity_Output_Store', 'schedule_cleanup' ) );

		// CL-2: WP-Cron event actually scheduled — chỉ kiểm tra thật
		$next = wp_next_scheduled( 'bizcity_output_store_cleanup' );
		$this->assert( 'CL-2', 'Cron event scheduled (twicedaily)', $next !== false,
			$next ? 'Next run: ' . date( 'Y-m-d H:i:s', $next ) : 'Not scheduled — deploy core/tools/bootstrap.php to register plugins_loaded @21' );

		// CL-3: cleanup respects pinned + external_url (source code check)
		$ref = new ReflectionMethod( 'BizCity_Output_Store', 'cleanup_old_outputs' );
		$source = $this->read_method_source( $ref->getFileName(), $ref->getStartLine(), $ref->getEndLine() );

		$has_pinned = strpos( $source, 'pinned' ) !== false;
		$has_url    = strpos( $source, 'external_url' ) !== false;
		$this->assert( 'CL-3', 'Cleanup respects pinned + external_url', $has_pinned && $has_url,
			'pinned=' . ( $has_pinned ? 'yes' : 'no' ) . ' external_url=' . ( $has_url ? 'yes' : 'no' ) );
	}

	/* ════════════════════════════════════════════════════════════════════
	 * HELPERS
	 * ════════════════════════════════════════════════════════════════════ */

	/**
	 * Build a human-readable diagnostic string when a class is not loaded.
	 */
	private function class_load_detail( string $filename ): string {
		if ( ! defined( 'BIZCITY_TOOLS_DIR' ) ) {
			return 'BIZCITY_TOOLS_DIR not defined — core/tools/bootstrap.php not loaded';
		}
		$file = BIZCITY_TOOLS_DIR . $filename;
		if ( ! file_exists( $file ) ) {
			return 'File not deployed: ' . $filename;
		}
		return 'File exists but class not defined — core/tools/bootstrap.php on server missing require_once';
	}
}
