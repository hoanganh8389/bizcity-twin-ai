<?php
/**
 * Runtime DDV for approved zero-row DROP and metadata cache invalidation.
 *
 * The probe creates a disposable empty fixture table for a retired suffix that
 * is currently absent, marks it ready_to_drop with an approval reference, then
 * executes the shared drop path and verifies table-cache invalidation effects.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since 2026-08-28
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! interface_exists( 'BizCity_Diagnostics_Probe' ) ) {
	return;
}

if ( class_exists( 'BizCity_Probe_Legacy_Table_Approved_Drop', false ) ) {
	return;
}

final class BizCity_Probe_Legacy_Table_Approved_Drop implements BizCity_Diagnostics_Probe {

	/** @var string */
	private $fixture_suffix = '';

	/** @var string */
	private $fixture_physical = '';

	/** @var mixed */
	private $states_before = null;

	public function id(): string {
		return 'core.legacy_table.approved_drop';
	}

	public function label(): string {
		return 'Legacy tables - approved zero-row drop and cache invalidation';
	}

	public function description(): string {
		return 'Creates a disposable approved empty legacy fixture, executes the shared drop path, and verifies metadata cache invalidation without touching retained data.';
	}

	public function severity(): string {
		return 'critical';
	}

	public function order(): int {
		return 24;
	}

	public function icon(): string {
		return 'trash-2';
	}

	public function estimate_ms(): int {
		return 200;
	}

	public function precondition() {
		if ( ! class_exists( 'BizCity_Legacy_Table_Policy' ) ) {
			return new WP_Error( 'legacy_policy_missing', 'Legacy table policy is not loaded.' );
		}
		if ( ! function_exists( 'bizcity_tbl_exists' ) || ! function_exists( 'bizcity_tbl_invalidate' ) ) {
			return new WP_Error( 'legacy_metadata_cache_missing', 'Table metadata cache helpers are not loaded.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-08-28 Johnny Chu] PHASE-1.30-DDV — execute the approved empty DROP path on a disposable fixture and verify cache invalidation effects.
		global $wpdb;
		$steps = array();
		$pass = true;
		$emit = function ( $label, $ok, $detail ) use ( $ctx, &$steps, &$pass ) {
			$step = array( 'label' => $label, 'status' => $ok ? 'pass' : 'fail', 'detail' => $detail );
			$steps[] = $step;
			$ctx->emit_step( $step );
			$pass = $pass && $ok;
		};

		$this->states_before = get_option( BizCity_Legacy_Table_Policy::OPTION, null );
		$candidate = $this->pick_absent_retired_candidate();
		if ( '' === $candidate ) {
			$emit( 'Fixture candidate is available', false, 'No absent retired table suffix is available for a disposable drop fixture.' );
			return array(
				'status' => 'fail',
				'summary' => 'Approved zero-row drop probe could not find a safe disposable fixture candidate.',
				'steps' => $steps,
			);
		}

		$this->fixture_suffix = $candidate;
		$this->fixture_physical = BizCity_Legacy_Table_Policy::physical_name( $candidate );
		$emit( 'Fixture candidate is available', true, 'Using absent retired suffix ' . $this->fixture_suffix . ' at ' . $this->fixture_physical . '.' );

		if ( ! $this->create_fixture_table() ) {
			$emit( 'Create empty fixture table', false, $wpdb->last_error ? $wpdb->last_error : 'CREATE TABLE failed for fixture.' );
			return array(
				'status' => 'fail',
				'summary' => 'Approved zero-row drop probe could not create the disposable fixture table.',
				'steps' => $steps,
			);
		}
		$emit( 'Create empty fixture table', true, 'Disposable fixture table created with zero rows.' );

		// [2026-08-28 Johnny Chu] PHASE-1.30-DDV — prime the table-existence cache only after CREATE to avoid stale false entries from earlier absent checks.
		$exists_before = bizcity_tbl_exists( $this->fixture_physical );
		$emit( 'Prime metadata cache with positive existence', $exists_before, $exists_before ? 'Fixture exists and is cached as present.' : 'Fixture table existence check failed before DROP.' );

		$approval_ref = 'diag-approved-drop-' . gmdate( 'YmdHis' );
		$ready_marked = BizCity_Legacy_Table_Policy::mark_ready_to_drop( $this->fixture_suffix, $approval_ref );
		$drop_allowed = BizCity_Legacy_Table_Policy::can_drop( $this->fixture_suffix );
		$emit( 'Ready-to-drop approval is recorded', $ready_marked && $drop_allowed, $ready_marked && $drop_allowed ? 'Policy accepted approval reference ' . $approval_ref . '.' : 'Policy did not enter drop-eligible state.' );

		$count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . $this->fixture_physical . '`' );
		$emit( 'Zero-row gate is satisfied on the fixture', 0 === $count, 0 === $count ? 'Fixture count is 0 before DROP.' : 'Fixture count is not zero: ' . $count . '.' );

		$generation_before = isset( $GLOBALS['bizcity_table_cache_generation'] ) ? (int) $GLOBALS['bizcity_table_cache_generation'] : 0;
		$drop_ok = BizCity_Legacy_Table_Policy::drop_approved_empty( $this->fixture_suffix );
		$emit( 'Policy drop_approved_empty succeeds for approved zero-row table', $drop_ok, $drop_ok ? 'Shared DROP path returned success.' : 'Shared DROP path returned failure.' );

		$generation_after = isset( $GLOBALS['bizcity_table_cache_generation'] ) ? (int) $GLOBALS['bizcity_table_cache_generation'] : 0;
		$cache_generation_bumped = $generation_after > $generation_before;
		$emit( 'DROP invalidates metadata cache generation', $cache_generation_bumped, $cache_generation_bumped ? 'Table cache generation changed from ' . $generation_before . ' to ' . $generation_after . '.' : 'Table cache generation did not change after DROP.' );

		$absent_after_drop = ! $this->table_exists_raw( $this->fixture_physical );
		$emit( 'Table is physically absent after approved DROP', $absent_after_drop, $absent_after_drop ? 'Fixture table no longer exists on current blog/shard.' : 'Fixture table still exists in information_schema after DROP.' );

		$dropped_state = BizCity_Legacy_Table_Policy::get_state( $this->fixture_suffix ) === BizCity_Legacy_Table_Policy::STATE_DROPPED;
		$emit( 'Policy state transitions to dropped after successful DROP', $dropped_state, $dropped_state ? 'Policy state is dropped for the fixture suffix.' : 'Policy state was not updated to dropped.' );

		return array(
			'status' => $pass ? 'pass' : 'fail',
			'summary' => $pass ? 'Approved zero-row DROP and metadata cache invalidation passed on a disposable legacy fixture.' : 'Approved zero-row DROP or cache invalidation failed on the disposable legacy fixture.',
			'steps' => $steps,
			'artifacts' => array(
				array( 'kind' => 'legacy_fixture', 'id' => $this->fixture_suffix, 'label' => $this->fixture_physical ),
			),
		);
	}

	public function cleanup(): void {
		if ( $this->fixture_physical !== '' ) {
			global $wpdb;
			$wpdb->query( 'DROP TABLE IF EXISTS `' . $this->fixture_physical . '`' );
			if ( function_exists( 'bizcity_tbl_invalidate' ) ) {
				bizcity_tbl_invalidate( $this->fixture_physical );
			}
		}
		if ( $this->states_before === null ) {
			delete_option( BizCity_Legacy_Table_Policy::OPTION );
		} else {
			update_option( BizCity_Legacy_Table_Policy::OPTION, $this->states_before, false );
		}
		$this->fixture_suffix = '';
		$this->fixture_physical = '';
		$this->states_before = null;
	}

	private function pick_absent_retired_candidate(): string {
		$candidates = array(
			'bizcity_intent_one_shot',
			'bizcity_intent_traces',
			'bizcity_intent_tasks',
			'bizcity_twin_state_focus',
			'bizcity_twin_state_snapshot',
			'bizcity_twin_state_resolver',
			'bizcity_twin_state_session',
			'bizcity_twin_state_log',
			'bizcity_twin_state_kv',
			'bizcity_twinchat_welcome_jobs',
			'bizcity_twinchat_notes',
			'bizcity_twin_identity',
			'bizcity_twin_focus_state',
			'bizcity_twin_timeline_state',
			'bizcity_twin_journeys',
		);
		foreach ( $candidates as $candidate ) {
			$physical = BizCity_Legacy_Table_Policy::physical_name( $candidate );
			// [2026-08-28 Johnny Chu] PHASE-1.30-DDV — choose a fixture candidate using raw metadata so cache priming stays one-directional in this run.
			if ( ! $this->table_exists_raw( $physical ) ) {
				return $candidate;
			}
		}
		return '';
	}

	private function create_fixture_table(): bool {
		global $wpdb;
		$collate = method_exists( $wpdb, 'get_charset_collate' ) ? (string) $wpdb->get_charset_collate() : '';
		$sql = 'CREATE TABLE `' . $this->fixture_physical . '` ('
			. 'id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,'
			. 'PRIMARY KEY (id)'
			. ') ' . $collate;
		$created = $wpdb->query( $sql );
		if ( false === $created ) {
			return false;
		}
		if ( function_exists( 'bizcity_tbl_invalidate' ) ) {
			bizcity_tbl_invalidate( $this->fixture_physical );
		}
		return true;
	}

	private function table_exists_raw( $table_name ): bool {
		global $wpdb;
		$present = (int) (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
				$table_name
			)
		);
		return $present === 1;
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Legacy_Table_Approved_Drop';
	return $list;
} );
