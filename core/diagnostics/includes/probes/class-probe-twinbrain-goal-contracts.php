<?php
/**
 * DDV probe for the G12 Goal Contract projection and JSONL contract.
 *
 * Default mode is read-only. Runtime write/reconciliation evidence remains
 * opt-in until the projection table has been provisioned on the target shard.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since 2026-08-03
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

if ( class_exists( 'BizCity_Probe_TwinBrain_Goal_Contracts', false ) ) {
	return;
}

final class BizCity_Probe_TwinBrain_Goal_Contracts implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'twinbrain.goal_contracts'; }
	public function label(): string { return 'TwinBrain Goal Contract G12'; }
	public function description(): string { return 'Checks the G12 current-state projection registration, changelog contract, owner boundary, and read-only JSONL/runtime surface.'; }
	public function severity(): string { return 'warning'; }
	public function order(): int { return 66; }
	public function icon(): string { return 'target'; }
	public function estimate_ms(): int { return 35; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_TwinBrain_Goal_Contract_Store' ) ) {
			return new WP_Error( 'class_missing', 'Goal Contract Store chưa load.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-08-03 Johnny Chu] G12.7 — read-only Disk/Loader/Runtime contract checks; no synthetic DB write by default.
		$steps = array();
		$ok = true;
		$plugin_root = defined( 'BIZCITY_TWIN_AI_DIR' )
			? rtrim( (string) BIZCITY_TWIN_AI_DIR, '/\\' ) . '/'
			: dirname( dirname( dirname( dirname( __DIR__ ) ) ) ) . '/';
		$store_file = $plugin_root . 'core/twinbrain/includes/class-twinbrain-goal-contract-store.php';
		$changelog_file = $plugin_root . 'core/diagnostics/changelog/core.twinbrain.json';
		$ok = $this->step( $ctx, $steps, 'Disk: Goal Contract Store', is_readable( $store_file ) || class_exists( 'BizCity_TwinBrain_Goal_Contract_Store' ), $store_file ) && $ok;

		$changelog = is_readable( $changelog_file ) ? json_decode( (string) file_get_contents( $changelog_file ), true ) : null;
		$changelog_ok = is_array( $changelog )
			&& (string) ( $changelog['current_version'] ?? '' ) === BizCity_TwinBrain_Goal_Contract_Store::DB_VERSION
			&& isset( $changelog['tables'][ BizCity_TwinBrain_Goal_Contract_Store::TABLE_BASE ] )
			&& isset( $changelog['tables'][ BizCity_TwinBrain_Goal_Contract_Store::TABLE_BASE ]['columns']['event_seq'] );
		$ok = $this->step( $ctx, $steps, 'Disk: R-DCL Goal Contract table', $changelog_ok, $changelog_file . ' version=' . (string) ( $changelog['current_version'] ?? '' ) ) && $ok;

		$methods_ok = method_exists( 'BizCity_TwinBrain_Goal_Contract_Store', 'get_by_goal_id' )
			&& method_exists( 'BizCity_TwinBrain_Goal_Contract_Store', 'get_by_scope' )
			&& method_exists( 'BizCity_TwinBrain_Goal_Contract_Store', 'list_active' )
			&& method_exists( 'BizCity_TwinBrain_Goal_Contract_Store', 'upsert' )
			&& method_exists( 'BizCity_TwinBrain_Goal_Contract_Store', 'ensure_schema' )
			&& method_exists( 'BizCity_TwinBrain_Goal_Contract_Store', 'jsonl_path' )
			&& method_exists( 'BizCity_TwinBrain_Goal_Contract_Store', 'rebuild_from_event_stream' )
			&& method_exists( 'BizCity_TwinBrain_Goal_Contract_Store', 'reconcile' );
		$ok = $this->step( $ctx, $steps, 'Loader: Goal Contract Store API', $methods_ok, 'Projection read/write boundary and explicit provisioner entry point are loaded.' ) && $ok;

		$registry_ok = class_exists( 'BizCity_Schema_Registry' )
			&& BizCity_Schema_Registry::is_registered( BizCity_TwinBrain_Goal_Contract_Store::TABLE_BASE );
		$ok = $this->step( $ctx, $steps, 'Loader: Schema Registry registration', $registry_ok, $registry_ok ? 'R-CR registration exists before provisioning.' : 'Goal Contract table is not registered in BizCity_Schema_Registry.' ) && $ok;
		$provisioner_ok = false;
		if ( class_exists( 'BizCity_Site_Provisioner' ) && method_exists( 'BizCity_Site_Provisioner', 'get_installers' ) ) {
			foreach ( BizCity_Site_Provisioner::get_installers() as $installer ) {
				if ( is_array( $installer ) && (string) ( $installer['id'] ?? '' ) === 'twinbrain_goal_contracts' ) {
					$provisioner_ok = is_callable( $installer['callback'] ?? null );
					break;
				}
			}
		}
		$ok = $this->step( $ctx, $steps, 'Loader: Site Provisioner installer', $provisioner_ok, $provisioner_ok ? 'twinbrain_goal_contracts installer is visible to the canonical provisioner.' : 'G12 installer is not visible to Site Provisioner.' ) && $ok;

		$table_exists = function_exists( 'bizcity_tbl_exists' )
			? (bool) bizcity_tbl_exists( BizCity_TwinBrain_Goal_Contract_Store::table() )
			: false;
		if ( ! $table_exists ) {
			$this->skip( $ctx, $steps, 'Runtime: current projection read', 'SKIP: table is not provisioned on this shard; run Site Provisioner/Diagnostics after G12.1.' );
			$this->skip( $ctx, $steps, 'Runtime: physical projection schema', 'SKIP: table is not provisioned on this shard.' );
		} else {
			$schema = $this->physical_schema();
			$schema_ok = ! empty( $schema['columns_ok'] ) && ! empty( $schema['indexes_ok'] );
			$ok = $this->step( $ctx, $steps, 'Runtime: physical projection schema', $schema_ok, wp_json_encode( $schema ) ) && $ok;
			$read = BizCity_TwinBrain_Goal_Contract_Store::get_by_goal_id( (int) get_current_blog_id(), 'ddv_nonexistent_goal' );
			$read_ok = is_array( $read );
			$ok = $this->step( $ctx, $steps, 'Runtime: current projection read', $read_ok, $read_ok ? 'Tenant-scoped read returns an array without writing.' : 'Projection read contract failed.' ) && $ok;
		}

		$path_api_ok = method_exists( 'BizCity_TwinBrain_Goal_Contract_Store', 'jsonl_path' );
		$ok = $this->step( $ctx, $steps, 'Loader: per-goal JSONL path API', $path_api_ok, 'Path uses wp_upload_dir() and a collision-safe goal path key.' ) && $ok;
		$live_enabled = (bool) apply_filters( 'bizcity_diagnostics_goal_contract_live_write', false );
		if ( $live_enabled && $table_exists ) {
			$live = $this->run_live_round_trip();
			$ok = $this->step( $ctx, $steps, 'Runtime: Goal Contract SQL/JSONL round-trip', ! empty( $live['ok'] ), (string) ( $live['detail'] ?? '' ) ) && $ok;
		} elseif ( $live_enabled ) {
			$this->skip( $ctx, $steps, 'Runtime: Goal Contract SQL/JSONL round-trip', 'SKIP: projection table is not provisioned on the active shard.' );
		} else {
			$this->skip( $ctx, $steps, 'Runtime: projection/JSONL round-trip', 'SKIP: enable bizcity_diagnostics_goal_contract_live_write only on a sandbox shard after provisioning.' );
		}

		return array(
			'ok' => $ok,
			'status' => $ok ? 'PASS' : 'FAIL',
			'steps' => $steps,
			'failures' => $ok ? array() : array( 'twin_goal_contracts_contract_failed' ),
		);
	}

	private function physical_schema(): array {
		global $wpdb;
		$table = BizCity_TwinBrain_Goal_Contract_Store::table();
		$required_columns = array( 'id', 'blog_id', 'goal_id', 'identity_uuid', 'session_id', 'current_turn_id', 'scoreboard_version', 'conversation_goal', 'obligations_json', 'scoreboard_json', 'contract_status', 'retrieve_round', 'turn_count', 'event_stream_id', 'source_event_uuid', 'event_seq', 'jsonl_path', 'created_at', 'updated_at' );
		$column_rows = $wpdb->get_col( $wpdb->prepare( 'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table ) );
		$index_rows = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table ) );
		$required_indexes = array( 'PRIMARY', 'idx_goal_id', 'idx_identity', 'idx_status_updated', 'idx_event_seq' );
		$missing_columns = array_values( array_diff( $required_columns, (array) $column_rows ) );
		$missing_indexes = array_values( array_diff( $required_indexes, (array) $index_rows ) );
		return array(
			'table' => $table,
			'columns_ok' => empty( $missing_columns ),
			'indexes_ok' => empty( $missing_indexes ),
			'missing_columns' => $missing_columns,
			'missing_indexes' => $missing_indexes,
		);
	}

	private function run_live_round_trip(): array {
		// [2026-08-03 Johnny Chu] G12.7 — opt-in sandbox only; append-only Event Stream rows are never deleted by the probe.
		if ( ! class_exists( 'BizCity_TwinBrain_Goal_Loop_Repository' ) || ! class_exists( 'BizCity_TwinBrain_Goal_Contract_Store' ) ) {
			return array( 'ok' => false, 'detail' => 'Goal Loop Repository or Contract Store is unavailable.' );
		}
		$suffix = strtolower( wp_generate_password( 12, false, false ) );
		$identity_uuid = 'ddv_contract_' . $suffix;
		$session_id = 'ddv_contract_session_' . substr( md5( $suffix ), 0, 16 );
		$goal_id = 'ddv_contract_goal_' . substr( md5( $identity_uuid ), 0, 20 );
		$opts = array(
			'identity_uuid' => $identity_uuid,
			'blog_id' => (int) get_current_blog_id(),
			'user_id' => 0,
			'session_id' => $session_id,
			'event_source' => 'twinbrain',
			'trace_id' => 'ddv_contract_trace_' . $suffix,
			'turn_id' => 'T01',
		);
		$base = array(
			'goal_id' => $goal_id,
			'blog_id' => (int) get_current_blog_id(),
			'identity_uuid' => $identity_uuid,
			'session_id' => $session_id,
			'primary_goal' => 'G12 sandbox contract projection',
			'conversation_goal' => array(
				'user_outcome' => 'Synthetic outcome without user data',
				'conversation_mode' => 'consulting',
				'decision_required' => true,
				'closure_condition' => 'Synthetic DoD met',
			),
			'answer_obligations' => array(
				array( 'id' => 'Q1', 'question' => 'Synthetic question one?', 'type' => 'fact', 'priority' => 'must', 'status' => 'open' ),
			),
			'status' => 'clarifying',
		);
		$opened = BizCity_TwinBrain_Goal_Loop_Repository::open( $base, $opts );
		if ( $opened === '' ) {
			return array( 'ok' => false, 'detail' => 'Sandbox open() failed.' );
		}
		$opts['turn_id'] = 'T02';
		$progress = $base;
		$progress['status'] = 'executing';
		$progress['answer_obligations'][0]['status'] = 'answered';
		$progress['answer_obligations'][] = array( 'id' => 'Q2', 'question' => 'Synthetic question two?', 'type' => 'comparison', 'priority' => 'should', 'status' => 'open' );
		$progress['resolution_scoreboard'] = array(
			'scoreboard_version' => 'v1',
			'rows' => array(
				array( 'obligation_id' => 'Q1', 'coverage' => 1.0, 'evidence_ref' => array( 'synthetic:e1' ), 'route' => 'PASS' ),
				array( 'obligation_id' => 'Q2', 'coverage' => 0.4, 'evidence_ref' => array(), 'route' => 'PATCH' ),
			),
			'overall_ready_for_final' => false,
			'retrieve_round' => 0,
			'method' => 'ddv_fixture',
		);
		$opts['reflection_result'] = array( 'verdict' => 'REVISE', 'route' => 'PATCH', 'completion_score' => 0.7, 'retrieve_round' => 0 );
		$progressed = BizCity_TwinBrain_Goal_Loop_Repository::progress( $progress, $opts );
		if ( $progressed === '' ) {
			return array( 'ok' => false, 'detail' => 'Sandbox progress() failed.' );
		}
		$opts['turn_id'] = 'T03';
		$closed = $progress;
		$closed['status'] = 'completed';
		$closed['completion_score'] = 1.0;
		$closed['definition_of_done'] = array( array( 'id' => 'ddv', 'label' => 'Synthetic evidence', 'status' => 'done', 'evidence' => array( 'synthetic:done' ) ) );
		$closed['closure_signal'] = array( 'type' => 'user_completed', 'evidence' => 'ddv_sandbox' );
		$closed_uuid = BizCity_TwinBrain_Goal_Loop_Repository::close( $closed, $opts );
		if ( $closed_uuid === '' ) {
			return array( 'ok' => false, 'detail' => 'Sandbox close() failed.' );
		}
		$current = BizCity_TwinBrain_Goal_Contract_Store::get_by_goal_id( (int) get_current_blog_id(), $goal_id );
		$path = BizCity_TwinBrain_Goal_Contract_Store::jsonl_path( $goal_id );
		$lines = $path !== '' && file_exists( $path ) ? (array) @file( $path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) : array();
		$event_types = array();
		foreach ( $lines as $line ) {
			$row = json_decode( (string) $line, true );
			if ( is_array( $row ) ) {
				$event_types[ (string) ( $row['event_type'] ?? '' ) ] = true;
			}
		}
		$rebuild = BizCity_TwinBrain_Goal_Contract_Store::rebuild_from_event_stream( (int) get_current_blog_id(), $goal_id, $identity_uuid, true );
		$required = array( 'goal.parsed', 'contract.created', 'contract.patched', 'scoreboard.scored', 'reflection.completed', 'contract.closed' );
		$trace_ok = true;
		foreach ( $required as $type ) {
			if ( empty( $event_types[ $type ] ) ) {
				$trace_ok = false;
				break;
			}
		}
		$projection_ok = is_array( $current )
			&& (string) ( $current['goal_id'] ?? '' ) === $goal_id
			&& (string) ( $current['contract_status'] ?? '' ) === 'closed'
			&& count( (array) ( $current['obligations'] ?? array() ) ) >= 2;
		return array(
			'ok' => $projection_ok && $trace_ok && ! empty( $rebuild['ok'] ),
			'detail' => $projection_ok && $trace_ok && ! empty( $rebuild['ok'] )
				? 'PASS: 3 canonical events projected, fine-grained JSONL taxonomy present, and replay rebuild succeeded.'
				: 'Projection/trace/rebuild mismatch: ' . wp_json_encode( array( 'projection' => $current, 'event_types' => array_keys( $event_types ), 'rebuild' => $rebuild ) ),
		);
	}

	private function step( $ctx, array &$steps, string $label, bool $passed, string $detail ): bool {
		$row = array( 'label' => $label, 'status' => $passed ? 'pass' : 'fail', 'detail' => $detail );
		$steps[] = $row;
		if ( is_object( $ctx ) && method_exists( $ctx, 'emit_step' ) ) {
			$ctx->emit_step( $row );
		}
		return $passed;
	}

	private function skip( $ctx, array &$steps, string $label, string $detail ): void {
		$row = array( 'label' => $label, 'status' => 'skip', 'detail' => $detail );
		$steps[] = $row;
		if ( is_object( $ctx ) && method_exists( $ctx, 'emit_step' ) ) {
			$ctx->emit_step( $row );
		}
	}

	public function cleanup(): void {
		// [2026-08-03 Johnny Chu] G12.7 — read-only default probe creates no rows or files.
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $probes ) {
	$probes[] = 'BizCity_Probe_TwinBrain_Goal_Contracts';
	return $probes;
} );
