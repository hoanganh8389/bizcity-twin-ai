<?php
/**
 * DDV probe for the reference extension JSONL/index and KG Hub paths.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since 1.3.8
 */

defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'BizCity_Diagnostics_Probe' ) ) {
	return;
}

if ( class_exists( 'BizCity_Probe_Reference_Plugin_Wave5', false ) ) {
	return;
}

final class BizCity_Probe_Reference_Plugin_Wave5 implements BizCity_Diagnostics_Probe {

	private $notebook_id = 0;
	private $notebook_name = '';
	private $source_id = 0;
	private $passage_id = 0;

	public function id(): string { return 'examples.reference_plugin.wave5'; }
	public function label(): string { return 'Reference plugin JSONL/index and KG Hub'; }
	public function description(): string { return 'Verifies canonical JSONL append, bizcity_log_index pointer/hash follow, and typed source ingestion into central KG Hub tables.'; }
	public function severity(): string { return 'warning'; }
	public function order(): int { return 57; }
	public function icon(): string { return 'database-zap'; }
	public function estimate_ms(): int { return 500; }

	public function precondition() {
		// [2026-08-29 Johnny Chu] PHASE-VIBE-WAVE5 — load the example fixture before checking its registered contracts.
		$root = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) ) . '/';
		if ( class_exists( 'BizCity_Safe_Loader', false ) ) {
			$framework_contracts = $root . 'core/twin-core/contracts/framework-contracts.php';
			$content_contracts = $root . 'core/twin-core/contracts/content-contracts.php';
			if ( ! interface_exists( 'BizCity_Tool_Interface' ) && is_file( $framework_contracts ) && is_readable( $framework_contracts ) ) {
				BizCity_Safe_Loader::require_file( $framework_contracts, 'examples.reference_plugin.framework_contracts' );
			}
			if ( ! interface_exists( 'BizCity_KG_Source_Adapter_Interface' ) && is_file( $content_contracts ) && is_readable( $content_contracts ) ) {
				BizCity_Safe_Loader::require_file( $content_contracts, 'examples.reference_plugin.content_contracts' );
			}
		}
		$reference_file = $root . 'examples/bizcity-reference-plugin/bizcity-reference-plugin.php';
		if ( ! class_exists( 'BizCity_Reference_Source_Adapter' ) && class_exists( 'BizCity_Safe_Loader', false ) && is_file( $reference_file ) && is_readable( $reference_file ) ) {
			BizCity_Safe_Loader::require_file( $reference_file, 'examples.reference_plugin.wave5' );
		}
		if ( class_exists( 'BizCity_Reference_Wave5_Evidence' ) && method_exists( 'BizCity_Reference_Wave5_Evidence', 'register_contract' ) ) {
			BizCity_Reference_Wave5_Evidence::register_contract();
		}
		$required = array( 'BizCity_JSONL_File_Logger', 'BizCity_Log_Contract_Registry', 'BizCity_Log_Index', 'BizCity_KG', 'BizCity_KG_Database' );
		foreach ( $required as $class ) {
			if ( ! class_exists( $class ) ) {
				return new WP_Error( 'wave5_dependency_missing', 'Wave 5 dependency is unavailable: ' . $class );
			}
		}
		if ( ! BizCity_Log_Contract_Registry::has( 'plugins.bizcity_reference.wave5' ) ) {
			return new WP_Error( 'wave5_log_contract_missing', 'Reference Wave 5 log contract is not registered.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-08-29 Johnny Chu] PHASE-VIBE-WAVE5 — prove file-first indexed evidence and central KG passage ingestion.
		$steps = array();
		$pass = true;
		$root = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) ) . '/';
		$emit = function ( $label, $ok, $detail ) use ( $ctx, &$steps, &$pass ) {
			$step = array( 'label' => $label, 'status' => $ok ? 'pass' : 'fail', 'detail' => $detail );
			$steps[] = $step;
			$ctx->emit_step( $step );
			if ( ! $ok ) {
				$pass = false;
			}
		};

		$reference_file = $root . 'examples/bizcity-reference-plugin/bizcity-reference-plugin.php';
		$manifest_file = $root . 'examples/bizcity-reference-plugin/manifest.json';
		$disk_ok = is_readable( $reference_file ) && is_readable( $manifest_file );
		$emit( 'Disk - reference plugin and manifest exist', $disk_ok, $disk_ok ? 'Reference plugin source and manifest are readable.' : 'Reference plugin artifact or manifest is missing.' );
		if ( ! $disk_ok ) {
			return array( 'status' => 'fail', 'summary' => 'Reference Wave 5 artifacts are missing.', 'steps' => $steps );
		}

		if ( ! class_exists( 'BizCity_Reference_Source_Adapter' ) && class_exists( 'BizCity_Safe_Loader', false ) ) {
			BizCity_Safe_Loader::require_file( $reference_file, 'examples.reference_plugin.wave5' );
		}
		$loader_ok = class_exists( 'BizCity_Reference_Source_Adapter' ) && class_exists( 'BizCity_Reference_Wave5_Evidence' );
		$emit( 'Loader - reference source and evidence classes loaded', $loader_ok, $loader_ok ? 'Typed KG source adapter and indexed evidence helper are loaded.' : 'Reference plugin classes were not loaded.' );
		if ( ! $loader_ok ) {
			return array( 'status' => 'fail', 'summary' => 'Reference Wave 5 classes are unavailable.', 'steps' => $steps );
		}

		$trace_id = 'wave5-' . substr( md5( (string) microtime( true ) . '|' . wp_rand() ), 0, 16 );
		$logged = BizCity_Reference_Wave5_Evidence::write_log( $trace_id, array( 'probe' => $this->id(), 'source_id' => 'reference.wave5' ) );
		$emit( 'Runtime - canonical JSONL evidence append', $logged, $logged ? 'write_contract() appended one reference evidence row.' : 'write_contract() did not append the reference evidence row.' );

		$pointer_rows = BizCity_Reference_Wave5_Evidence::indexed_rows( $trace_id );
		$pointer = ! empty( $pointer_rows[0] ) && is_array( $pointer_rows[0] ) ? $pointer_rows[0] : array();
		$pointer_ok = ! empty( $pointer ) && (string) ( $pointer['contract_id'] ?? '' ) === BizCity_Reference_Wave5_Evidence::LOG_CONTRACT;
		$emit( 'Runtime - bizcity_log_index finds contract pointer', $pointer_ok, $pointer_ok ? 'Pointer id=' . (int) $pointer['id'] . ' is scoped to the reference contract.' : 'No contract pointer was found for the reference trace.' );

		$follow_ok = false;
		if ( $pointer_ok ) {
			$verified = BizCity_JSONL_File_Logger::verify_pointer( (string) $pointer['jsonl_folder'], (string) $pointer['jsonl_module'], (string) $pointer['relative_file'], (int) $pointer['byte_offset'], (string) $pointer['row_hash'] );
			$follow_ok = ! empty( $verified['valid'] );
		}
		$emit( 'Runtime - JSONL pointer offset/hash follows exact row', $follow_ok, $follow_ok ? 'Pointer resolves to the durable JSONL row.' : 'Pointer offset/hash does not resolve to the JSONL row.' );

		global $wpdb;
		$db = BizCity_KG_Database::instance();
		$notebook_name = '__healthtest_wave5_' . substr( md5( $trace_id ), 0, 12 );
		$this->notebook_name = $notebook_name;
		$notebook_table = $db->tbl_notebooks();
		$notebook_inserted = false !== $wpdb->insert( $notebook_table, array( 'name' => $notebook_name, 'description' => 'Wave 5 diagnostics fixture.', 'owner_id' => (int) get_current_user_id(), 'notebook_scope' => 'business_kb' ), array( '%s', '%s', '%d', '%s' ) );
		$this->notebook_id = $notebook_inserted ? (int) $wpdb->insert_id : 0;
		$emit( 'Runtime - disposable KG notebook fixture created', $this->notebook_id > 0, $this->notebook_id > 0 ? 'notebook_id=' . $this->notebook_id : 'Could not create a disposable notebook fixture.' );

		$kg_result = array();
		if ( $this->notebook_id > 0 ) {
			$adapter = new BizCity_Reference_Source_Adapter();
			$kg_result = $adapter->ingest_to_kg( $this->notebook_id, (int) get_current_user_id(), $trace_id );
			$this->source_id = is_array( $kg_result ) ? (int) ( $kg_result['source_id'] ?? 0 ) : 0;
			$this->passage_id = is_array( $kg_result ) && ! empty( $kg_result['passage_ids'][0] ) ? (int) $kg_result['passage_ids'][0] : 0;
		}
		$ingest_ok = is_array( $kg_result ) && $this->source_id > 0 && $this->passage_id > 0 && (int) ( $kg_result['notebook_id'] ?? 0 ) === $this->notebook_id;
		$emit( 'Runtime - typed source adapter ingests central KG source/passage', $ingest_ok, $ingest_ok ? 'source_id=' . $this->source_id . ', passage_id=' . $this->passage_id . ', notebook_id=' . $this->notebook_id : 'Central KG ingestion did not return source and passage IDs.' );

		$stored_ok = false;
		if ( $ingest_ok ) {
			$stored_source = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$db->tbl_sources()} WHERE id = %d AND origin_plugin = %s", $this->source_id, 'bizcity.reference' ) );
			$stored_passage = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$db->tbl_passages()} WHERE id = %d AND source_id = %d AND notebook_id = %d", $this->passage_id, $this->source_id, $this->notebook_id ) );
			$stored_ok = (int) $stored_source === $this->source_id && (int) $stored_passage === $this->passage_id;
		}
		$emit( 'Runtime - KG source and passage are queryable in the target notebook', $stored_ok, $stored_ok ? 'Central kg_sources and kg_passages rows match the adapter result.' : 'Central KG rows were not found with the expected ownership fields.' );

		return array(
			'status'  => $pass ? 'pass' : 'fail',
			'summary' => $pass ? 'Reference plugin JSONL/index and KG Hub Wave 5 passed.' : 'Reference plugin Wave 5 evidence failed.',
			'fix_hint'=> 'Load the reference plugin through Safe Loader, register its indexed log contract, and route source data through BizCity_KG.',
			'steps'   => $steps,
		);
	}

	public function cleanup(): void {
		// [2026-08-29 Johnny Chu] PHASE-VIBE-WAVE5 — remove only the disposable notebook/source/passage rows created by this probe.
		if ( ! class_exists( 'BizCity_KG_Database' ) || ! function_exists( 'get_current_blog_id' ) ) {
			return;
		}
		global $wpdb;
		$db = BizCity_KG_Database::instance();
		if ( $this->passage_id > 0 ) {
			$wpdb->delete( $db->tbl_passages(), array( 'id' => $this->passage_id ), array( '%d' ) );
		}
		if ( $this->source_id > 0 ) {
			$wpdb->delete( $db->tbl_sources(), array( 'id' => $this->source_id ), array( '%d' ) );
		}
		if ( $this->notebook_id > 0 ) {
			$wpdb->delete( $db->tbl_notebooks(), array( 'id' => $this->notebook_id, 'name' => $this->notebook_name ), array( '%d', '%s' ) );
		}
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Reference_Plugin_Wave5';
	return $list;
} );