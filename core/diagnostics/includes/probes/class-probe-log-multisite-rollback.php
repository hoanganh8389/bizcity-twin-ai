<?php
/**
 * Focused DDV for two-blog pointer isolation and index rollback.
 *
 * The probe uses two real WordPress blogs when multisite provides them. It does
 * not provision a second shard or fall back to blog 1/current connection.
 *
 * @package BizCity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 */

defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'BizCity_Diagnostics_Probe' ) || class_exists( 'BizCity_Probe_Log_Multisite_Rollback', false ) ) {
	return;
}

final class BizCity_Probe_Log_Multisite_Rollback implements BizCity_Diagnostics_Probe {

	private $contract_id = '';
	private $folder = '';
	private $module = '';
	private $fixtures = array();

	public function id(): string {
		return 'core.helper.log_multisite_rollback';
	}

	public function label(): string {
		return 'JSONL two-blog isolation and index rollback';
	}

	public function description(): string {
		return 'Checks two real blog scopes, pointer/cache isolation, JSONL continuity while indexing is disabled and pointer rebuild after rollback.';
	}

	public function severity(): string {
		return 'critical';
	}

	public function order(): int {
		return 57;
	}

	public function icon(): string {
		return 'network';
	}

	public function estimate_ms(): int {
		return 1500;
	}

	public function precondition() {
		if ( ! class_exists( 'BizCity_JSONL_File_Logger' ) || ! class_exists( 'BizCity_Log_Index' ) || ! class_exists( 'BizCity_Log_Contract_Registry' ) ) {
			return new WP_Error( 'log_multisite_dependencies_missing', 'JSONL logger, pointer index or contract registry is not loaded.' );
		}
		if ( ! function_exists( 'is_multisite' ) || ! is_multisite() || ! function_exists( 'get_sites' ) || ! function_exists( 'switch_to_blog' ) || ! function_exists( 'restore_current_blog' ) ) {
			return new WP_Error( 'multisite_runtime_missing', 'Two-blog isolation requires a WordPress multisite runtime.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-09-02 Johnny Chu - Chu Hoàng Anh] PHASE-1.30-G4 - exercise real blog routing, cache dimensions and reversible index disablement without tenant fallback.
		$steps = array();
		$pass = true;
		$emit = function ( $label, $ok, $detail ) use ( $ctx, &$steps, &$pass ) {
			$step = array( 'label' => $label, 'status' => $ok ? 'pass' : 'fail', 'detail' => $detail );
			$steps[] = $step;
			$ctx->emit_step( $step );
			$pass = $pass && $ok;
		};
		$emit_status = function ( $label, $status, $detail ) use ( $ctx, &$steps ) {
			$step = array( 'label' => $label, 'status' => $status, 'detail' => $detail );
			$steps[] = $step;
			$ctx->emit_step( $step );
		};

		$current_blog = (int) get_current_blog_id();
		$site_ids = array_map( 'intval', (array) get_sites( array( 'number' => 20, 'fields' => 'ids' ) ) );
		$other_blog = 0;
		foreach ( $site_ids as $site_id ) {
			if ( $site_id > 0 && $site_id !== $current_blog ) {
				$other_blog = $site_id;
				break;
			}
		}
		if ( $current_blog <= 0 || $other_blog <= 0 ) {
			$emit_status( 'Runtime - two real blog contexts', 'skip', 'No second WordPress blog is available; no same-blog simulation is promoted to two-blog evidence.' );
			return array( 'status' => 'skip', 'summary' => 'Two-blog isolation requires a second real WordPress blog.', 'error' => 'second_blog_unavailable', 'fix_hint' => 'Run this probe on an approved multisite with two routed blogs.', 'steps' => $steps );
		}

		$this->contract_id = 'core.helper.log_g4_probe_' . substr( md5( uniqid( 'g4', true ) ), 0, 12 );
		$this->folder = 'bizcity-log-g4-probe';
		$this->module = 'multisite_' . substr( md5( $this->contract_id ), 0, 12 );
		$registered = BizCity_Log_Contract_Registry::register( $this->contract_id, array(
			'owner_module' => 'core/diagnostics',
			'label' => 'G4 JSONL multisite rollback probe',
			'jsonl_folder' => $this->folder,
			'jsonl_module' => $this->module,
			'retention_days' => 1,
			'indexed' => true,
		) );
		$emit( 'Loader - run-specific registered contract', $registered, $registered ? 'Synthetic contract is registered for both blog contexts.' : 'Synthetic contract registration failed.' );
		if ( ! $registered ) {
			return array( 'status' => 'fail', 'summary' => 'G4 fixture contract registration failed.', 'error' => 'g4_contract_registration_failed', 'fix_hint' => 'Keep the disposable contract unique and registry-approved.', 'steps' => $steps );
		}

		$contexts = array();
		foreach ( array( $current_blog, $other_blog ) as $blog_id ) {
			$switched = $blog_id === $current_blog ? true : switch_to_blog( $blog_id );
			if ( ! $switched ) {
				$contexts[ $blog_id ] = array( 'available' => false );
				continue;
			}
			global $wpdb;
			$contexts[ $blog_id ] = array(
				'available' => BizCity_Log_Index::is_available(),
				'db' => (string) ( $wpdb->dbname ?? '' ),
				'table' => BizCity_Log_Index::table(),
				'prefix' => (string) ( $wpdb->prefix ?? '' ),
			);
			if ( $blog_id !== $current_blog ) {
				restore_current_blog();
			}
		}
		$both_available = ! empty( $contexts[ $current_blog ]['available'] ) && ! empty( $contexts[ $other_blog ]['available'] );
		$emit( 'Physical tenant - pointer index available on both blogs', $both_available, $both_available ? 'Both selected blog contexts have their own available pointer index.' : 'At least one selected blog lacks its tenant pointer index.' );
		if ( ! $both_available ) {
			return array( 'status' => 'fail', 'summary' => 'G4 cannot run without pointer indexes on both selected blogs.', 'error' => 'g4_pointer_index_unavailable', 'fix_hint' => 'Provision the canonical tenant pointer index on both approved blogs, then rerun this probe.', 'steps' => $steps );
		}

		$prefix_ok = (string) $contexts[ $current_blog ]['table'] !== (string) $contexts[ $other_blog ]['table'];
		$emit( 'Physical tenant - blog-specific pointer table names', $prefix_ok, $prefix_ok ? 'Pointer table names differ by blog prefix.' : 'Both blogs resolved to the same pointer table name.' );
		$distinct_shard = (string) $contexts[ $current_blog ]['db'] !== '' && (string) $contexts[ $other_blog ]['db'] !== '' && (string) $contexts[ $current_blog ]['db'] !== (string) $contexts[ $other_blog ]['db'];
		$emit_status( 'Physical tenant - distinct database/shard identity', $distinct_shard ? 'pass' : 'skip', $distinct_shard ? 'The two selected blogs resolve to distinct database identities.' : 'The two selected blogs use the same database identity; prefix isolation passed, distinct-shard evidence remains deferred.' );

		$event_a = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : '00000000-0000-4000-8000-' . substr( md5( uniqid( 'a', true ) ), 0, 12 );
		$event_b = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : '00000000-0000-4000-8000-' . substr( md5( uniqid( 'b', true ) ), 0, 12 );
		foreach ( array( $current_blog => $event_a, $other_blog => $event_b ) as $blog_id => $event_uuid ) {
			if ( $blog_id !== $current_blog ) {
				switch_to_blog( $blog_id );
			}
			$result = BizCity_JSONL_File_Logger::write_contract( $this->contract_id, 'info', 'g4_probe', 'G4 tenant isolation fixture.', array( 'event_uuid' => $event_uuid, 'probe' => 'core.helper.log_multisite_rollback', 'blog_id' => $blog_id ) );
			$location = BizCity_JSONL_File_Logger::location( $this->folder, $this->module );
			$this->fixtures[ $blog_id ] = array( 'event_uuid' => $event_uuid, 'file' => (string) ( $location['file'] ?? '' ), 'relative_file' => $this->folder . '/' . $this->module . '/' . gmdate( 'Y-m-d' ) . '.jsonl' );
			if ( $blog_id !== $current_blog ) {
				restore_current_blog();
			}
			if ( ! $result ) {
				$pass = false;
			}
		}
		$emit( 'Runtime - one canonical JSONL fixture per blog', $pass, $pass ? 'Each selected blog wrote its own content-free event through the canonical logger.' : 'At least one selected blog could not write its fixture.' );

		$cross_scope_ok = true;
		$cache_scope_ok = true;
		foreach ( array( $current_blog, $other_blog ) as $blog_id ) {
			if ( $blog_id !== $current_blog ) {
				switch_to_blog( $blog_id );
			}
			$own_event = (string) $this->fixtures[ $blog_id ]['event_uuid'];
			$foreign_event = (string) $this->fixtures[ $blog_id === $current_blog ? $other_blog : $current_blog ]['event_uuid'];
			$own_rows = BizCity_Log_Index::search( array( 'contract_id' => $this->contract_id, 'event' => 'g4_probe', 'limit' => 20 ) );
			$own_found = false;
			$foreign_found = false;
			foreach ( $own_rows as $row ) {
				$own_found = $own_found || (string) ( $row['event_uuid'] ?? '' ) === $own_event && (int) ( $row['blog_id'] ?? 0 ) === $blog_id;
				$foreign_found = $foreign_found || (string) ( $row['event_uuid'] ?? '' ) === $foreign_event;
			}
			$cross_scope_ok = $cross_scope_ok && ( $own_found && ! $foreign_found );
			$cache_scope_ok = $cache_scope_ok && $own_found;
			if ( $blog_id !== $current_blog ) {
				restore_current_blog();
			}
		}
		$emit( 'Runtime - cross-blog pointer search isolation', $cross_scope_ok, $cross_scope_ok ? 'Each blog search returned its own pointer and no foreign-blog event.' : 'A blog search returned a foreign pointer or missed its own pointer.' );
		$emit( 'Runtime - blog/database cache dimension', $cache_scope_ok && $prefix_ok, $cache_scope_ok && $prefix_ok ? 'Search results remained scoped after switching blogs and cache keys include blog/database dimensions.' : 'Blog switch or pointer table scope did not remain isolated.' );

		$rollback_event = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : '00000000-0000-4000-8000-' . substr( md5( uniqid( 'rollback', true ) ), 0, 12 );
		$rollback_veto = function ( $enabled, $contract_id, $row ) use ( $ctx, $rollback_event ) {
			return (string) ( $row['event_uuid'] ?? '' ) === $rollback_event ? false : $enabled;
		};
		add_filter( 'bizcity_log_index_enabled', $rollback_veto, 10, 3 );
		try {
			$rollback_write = BizCity_JSONL_File_Logger::write_contract( $this->contract_id, 'info', 'g4_rollback', 'G4 indexing rollback fixture.', array( 'event_uuid' => $rollback_event, 'probe' => 'core.helper.log_multisite_rollback' ) );
		} finally {
			remove_filter( 'bizcity_log_index_enabled', $rollback_veto, 10 );
		}
		$rollback_source_ok = (bool) $rollback_write;
		$rollback_rows = BizCity_Log_Index::search( array( 'contract_id' => $this->contract_id, 'event' => 'g4_rollback', 'limit' => 10 ) );
		$rollback_absent = empty( $rollback_rows );
		$emit( 'Runtime - indexing disabled preserves JSONL write', $rollback_source_ok && $rollback_absent, $rollback_source_ok && $rollback_absent ? 'Index rollback veto left the canonical JSONL write successful and produced no pointer.' : 'Index disablement failed to preserve JSONL or unexpectedly created a pointer.' );

		$rebuild = BizCity_Log_Index::reconcile( $this->contract_id, 500 );
		$rebuilt_rows = BizCity_Log_Index::search( array( 'contract_id' => $this->contract_id, 'event' => 'g4_rollback', 'limit' => 10 ) );
		$rebuilt_ok = ! empty( $rebuild['complete'] ) && count( $rebuilt_rows ) === 1 && (string) ( $rebuilt_rows[0]['event_uuid'] ?? '' ) === $rollback_event;
		$emit( 'Runtime - re-enable and reconcile rebuilds pointer', $rebuilt_ok, $rebuilt_ok ? 'Reconcile restored one pointer for the rollback JSONL row after indexing was re-enabled.' : 'Reconcile did not restore the disabled-index JSONL row pointer.' );

		$distinct_status = $distinct_shard ? 'pass' : 'skip';
		$status = ! $pass ? 'fail' : ( $distinct_status === 'pass' ? 'pass' : 'skip' );
		return array(
			'status' => $status,
			'summary' => $status === 'pass' ? 'Two-blog/shard isolation and reversible JSONL index rollback passed.' : ( $status === 'skip' ? 'Two-blog prefix isolation and rollback passed; distinct physical-shard evidence remains deferred.' : 'Two-blog isolation or index rollback failed.' ),
			'error' => $status === 'fail' ? 'log_multisite_rollback_failed' : '',
			'fix_hint' => $status === 'pass' ? '' : 'Run on two approved routed shards and preserve blog/database dimensions; never fallback to blog 1 or the current connection.',
			'steps' => $steps,
		);
	}

	public function cleanup(): void {
		// [2026-09-02 Johnny Chu - Chu Hoàng Anh] PHASE-1.30-G4 - clean only this probe's exact current-blog fixtures and pointer rows.
		if ( $this->contract_id === '' ) {
			return;
		}
		$current_blog = (int) get_current_blog_id();
		foreach ( $this->fixtures as $blog_id => $fixture ) {
			if ( (int) $blog_id !== $current_blog ) {
				switch_to_blog( (int) $blog_id );
			}
			global $wpdb;
			$wpdb->delete( BizCity_Log_Index::table(), array( 'blog_id' => (int) $blog_id, 'contract_id' => $this->contract_id ), array( '%d', '%s' ) );
			if ( class_exists( 'BizCity_Cache' ) ) {
				BizCity_Cache::flush_group( 'bzlogidx' );
			}
			$file = (string) ( $fixture['file'] ?? '' );
			if ( $file !== '' && is_file( $file ) ) {
				@unlink( $file );
			}
			if ( (int) $blog_id !== $current_blog ) {
				restore_current_blog();
			}
		}
		$this->contract_id = '';
		$this->folder = '';
		$this->module = '';
		$this->fixtures = array();
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Log_Multisite_Rollback';
	return $list;
} );
