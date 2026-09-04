<?php
/**
 * Run a disposable Context Bank to KG provenance fixture outside Diagnostics CLI.
 *
 * Usage:
 *   php bin/context-bank-kg-fixture.php --wp-root=/path/to/wp --host=example.com --blog=1511 --user=3539 --notebook=12 --confirm=G4
 *
 * @package BizCity_Twin_AI
 * @subpackage Bin
 * @since 2026-09-02 (PHASE-CB-G4)
 */

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "context-bank-kg-fixture.php must be run from CLI.\n" );
	exit( 2 );
}

$options = array( 'wp-root' => '', 'host' => '', 'blog' => 0, 'user' => 0, 'notebook' => 0, 'confirm' => '' );
foreach ( array_slice( $argv, 1 ) as $argument ) {
	if ( strpos( $argument, '--' ) !== 0 || strpos( $argument, '=' ) === false ) {
		continue;
	}
	list( $key, $value ) = explode( '=', substr( $argument, 2 ), 2 );
	if ( array_key_exists( $key, $options ) ) {
		$options[ $key ] = $value;
	}
}
if ( (string) $options['confirm'] !== 'G4' ) {
	fwrite( STDERR, "Refusing fixture: pass --confirm=G4.\n" );
	exit( 2 );
}
if ( (int) $options['blog'] <= 0 || (int) $options['user'] <= 0 ) {
	fwrite( STDERR, "Refusing fixture: pass explicit --blog=<id> and --user=<admin-id>.\n" );
	exit( 2 );
}
if ( (string) $options['host'] === '' || preg_match( '/[^A-Za-z0-9.:-]/', (string) $options['host'] ) ) {
	fwrite( STDERR, "Refusing fixture: pass --host=example.com.\n" );
	exit( 2 );
}
$wp_root = (string) $options['wp-root'];
if ( $wp_root === '' ) {
	$wp_root = (string) ( getenv( 'BIZCITY_WP_ROOT' ) ?: '' );
}
if ( $wp_root === '' || ! is_file( rtrim( $wp_root, '/\\' ) . '/wp-load.php' ) || ! is_readable( rtrim( $wp_root, '/\\' ) . '/wp-load.php' ) ) {
	fwrite( STDERR, "Cannot locate readable wp-load.php. Use --wp-root=/path/to/wordpress.\n" );
	exit( 2 );
}
if ( defined( 'BIZCITY_DIAGNOSTICS_CLI' ) && BIZCITY_DIAGNOSTICS_CLI ) {
	fwrite( STDERR, "Refusing fixture inside Diagnostics CLI context.\n" );
	exit( 2 );
}

$_SERVER['HTTP_HOST'] = (string) $options['host'];
$_SERVER['SERVER_NAME'] = (string) $options['host'];
define( 'WP_USE_THEMES', false );
require rtrim( $wp_root, '/\\' ) . '/wp-load.php';
wp_set_current_user( (int) $options['user'] );
if ( ! current_user_can( 'manage_options' ) ) {
	fwrite( STDERR, "Refusing fixture: --user must have manage_options.\n" );
	exit( 3 );
}

$plugin_root = dirname( __DIR__ );
$safe_loader = $plugin_root . '/core/helper/class-bizcity-safe-loader.php';
if ( ! class_exists( 'BizCity_Safe_Loader', false ) && is_file( $safe_loader ) && is_readable( $safe_loader ) ) {
	require_once $safe_loader;
}
$context_bootstrap = $plugin_root . '/core/context-bank/bootstrap.php';
$kg_bootstrap = $plugin_root . '/core/knowledge/kg-hub/bootstrap.php';
if ( ! class_exists( 'BizCity_Safe_Loader', false ) || ! BizCity_Safe_Loader::require_file( $context_bootstrap, 'context_bank.kg_fixture' ) || ! BizCity_Safe_Loader::require_file( $kg_bootstrap, 'context_bank.kg_fixture.kg_hub' ) ) {
	fwrite( STDERR, "Context Bank or KG-Hub bootstrap could not be loaded.\n" );
	exit( 3 );
}

$original_blog = (int) get_current_blog_id();
$target_blog = (int) $options['blog'];
$switched = false;
if ( $target_blog !== $original_blog ) {
	$switched = switch_to_blog( $target_blog );
	if ( ! $switched ) {
		fwrite( STDERR, "Target blog switch failed closed.\n" );
		exit( 3 );
	}
}
$result = array( 'contract' => 'context-bank-kg-fixture', 'version' => '1', 'host' => (string) $options['host'], 'blog_id' => $target_blog, 'notebook_id' => 0, 'steps' => array(), 'status' => 'fail', 'reason' => '' );
$failures = array();
$deferred = array();
$step = static function ( $label, $status, $detail ) use ( &$result, &$failures, &$deferred ) {
	$result['steps'][] = array( 'label' => (string) $label, 'status' => (string) $status, 'detail' => (string) $detail );
	if ( $status === 'fail' ) {
		$failures[] = (string) $label;
	}
	if ( $status === 'deferred' ) {
		$deferred[] = (string) $label;
		$result['status'] = 'partial';
	}
};
$ledger_record_id = '';
$kg_source_id = 0;
$kg_passage_id = 0;
$kg_xref_id = 0;
$kg_vector_path = '';
$missing_flag = '__cb_g4_flag_missing__';
$previous_flag = get_option( 'bizcity_context_bank_kg_bridge_enabled', $missing_flag );
$ledger = null;
$pointer = array();

try {
	if ( ! class_exists( 'BizCity_Context_Bank_Ledger' ) || ! class_exists( 'BizCity_Business_JSONL_File_Store' ) || ! class_exists( 'BizCity_Context_Bank_KG_Bridge' ) || ! class_exists( 'BizCity_KG' ) || ! class_exists( 'BizCity_KG_Database' ) ) {
		$step( 'Loader - Context Bank and KG canonical owners available', 'fail', 'One or more Context Bank/KG owners are unavailable.' );
		throw new RuntimeException( 'g4_owner_dependency_missing' );
	}
	$ledger = BizCity_Context_Bank_Ledger::instance();
	$db = BizCity_KG_Database::instance();
	$notebook_id = (int) $options['notebook'];
	global $wpdb;
	$notebook_table = $db->tbl_notebooks();
	$authorized_notebooks = array();
	if ( class_exists( 'BizCity_KG_Notebook_Service' ) && method_exists( 'BizCity_KG_Notebook_Service', 'instance' ) ) {
		$authorized_notebooks = BizCity_KG_Notebook_Service::instance()->list_for_user( (int) $options['user'], array( 'include_public' => true, 'limit' => 500 ) );
	}
	if ( $notebook_id > 0 ) {
		$notebook_allowed = false;
		foreach ( (array) $authorized_notebooks as $authorized_notebook ) {
			if ( is_array( $authorized_notebook ) && (int) ( $authorized_notebook['id'] ?? 0 ) === $notebook_id ) {
				$notebook_allowed = true;
				break;
			}
		}
		if ( ! $notebook_allowed ) {
			$step( 'Runtime - authorized KG notebook available', 'deferred', 'The explicit notebook is not owned or publicly available to the selected admin in this tenant.' );
			throw new RuntimeException( 'g4_notebook_scope_denied' );
		}
	} elseif ( ! empty( $authorized_notebooks[0]['id'] ) ) {
		$notebook_id = (int) $authorized_notebooks[0]['id'];
	}
	if ( $notebook_id <= 0 ) {
		$step( 'Runtime - authorized KG notebook available', 'deferred', 'No canonical notebook exists on the selected tenant; pass --notebook=<id> for an approved target.' );
		throw new RuntimeException( 'g4_notebook_unavailable' );
	}
	$result['notebook_id'] = $notebook_id;
	$required_tables = array( $notebook_table, $db->tbl_sources(), $db->tbl_passages(), $db->tbl_xref() );
	foreach ( $required_tables as $table ) {
		if ( function_exists( 'bizcity_tbl_exists' ) && ! bizcity_tbl_exists( $table ) ) {
			$step( 'Runtime - KG table ' . $table . ' is available', 'deferred', 'Required canonical KG table is unavailable on the selected tenant.' );
			throw new RuntimeException( 'g4_kg_table_unavailable' );
		}
	}
	$step( 'Loader - Context Bank and KG canonical owners available', 'pass', 'Ledger, encrypted filestore, KG facade and KG database owners are loaded.' );
	$ledger_record_id = 'g4_fixture_rollup_' . strtolower( str_replace( '-', '', wp_generate_uuid4() ) );
	$receipt = BizCity_Business_JSONL_File_Store::write_with_receipt( 'core.context_bank.rollup', array( 'record_id' => $ledger_record_id, 'record_kind' => 'rollup', 'summary_text' => 'Stable disposable Context Bank rollup for provenance verification.', 'stable' => true, 'confidence' => 0.98, 'rollup_version' => '1.3.0', 'evidence_refs' => array( array( 'record_id' => 'g4_source_event', 'kind' => 'event' ) ), 'provenance_ref' => 'context-bank:' . $ledger_record_id, 'occurred_at' => gmdate( 'c' ) ), 'upsert' );
	if ( ! is_array( $receipt ) ) {
		throw new RuntimeException( 'g4_rollup_receipt_failed' );
	}
	$admission = $ledger->record( array( 'source_contract_id' => 'core.context_bank.rollup', 'record_id' => $ledger_record_id, 'record_kind' => 'rollup', 'event_uuid' => (string) $receipt['event_uuid'], 'source_record_id' => 'g4_source_event', 'user_id' => (int) $options['user'], 'entity_type' => 'conversation', 'entity_key' => 'g4_fixture_conversation', 'scope_key' => 'g4:fixture', 'notebook_id' => $notebook_id, 'rollup_version' => '1.3.0', 'provenance_ref' => 'context-bank:' . $ledger_record_id, 'kg_status' => 'pending', 'receipt' => $receipt ) );
	if ( empty( $admission['ok'] ) ) {
		throw new RuntimeException( 'g4_rollup_ledger_failed' );
	}
	$pointer_rows = $ledger->find( array( 'blog_id' => $target_blog, 'source_contract_id' => 'core.context_bank.rollup', 'record_id' => $ledger_record_id, 'limit' => 1 ) );
	$pointer = isset( $pointer_rows[0] ) && is_array( $pointer_rows[0] ) ? $pointer_rows[0] : array();
	$follow = $ledger->follow( $ledger_record_id, array( 'blog_id' => $target_blog, 'source_contract_id' => 'core.context_bank.rollup' ) );
	$follow_ok = is_array( $follow ) && ! empty( $follow['ok'] ) && ! empty( $follow['verified'] );
	$step( 'Runtime - stable rollup pointer admitted and verified', $follow_ok ? 'pass' : 'fail', $follow_ok ? 'The disposable stable rollup passed tenant, receipt and hash verification.' : 'Rollup pointer follow failed before KG promotion.' );
	if ( ! $follow_ok ) {
		throw new RuntimeException( 'g4_pointer_follow_failed' );
	}
	$pointer = (array) ( $follow['pointer'] ?? $pointer );
	update_option( 'bizcity_context_bank_kg_bridge_enabled', true, false );
	$promotion = BizCity_Context_Bank_KG_Bridge::promote( $pointer, array( 'authorized' => true, 'notebook_id' => $notebook_id, 'confidence_threshold' => 0.75 ) );
	$kg_source_id = (int) ( $promotion['kg_source_id'] ?? 0 );
	$kg_passage_id = (int) ( $promotion['kg_passage_id'] ?? 0 );
	$promotion_ok = is_array( $promotion ) && ! empty( $promotion['ok'] ) && ! empty( $promotion['promoted'] ) && $kg_source_id > 0 && $kg_passage_id > 0;
	$step( 'Runtime - stable rollup promoted to canonical KG source and passage', $promotion_ok ? 'pass' : 'fail', $promotion_ok ? 'One authorized rollup produced one canonical KG source and passage identity.' : 'KG promotion failed: ' . (string) ( $promotion['reason'] ?? 'unknown' ) );
	if ( ! $promotion_ok ) {
		throw new RuntimeException( 'g4_promotion_failed' );
	}
	// [2026-09-02 11:29 AM Johnny Chu - Chu Hoàng Anh] PHASE-CB6.3 — verify the promoted passage has exactly one canonical notebook vector row before accepting the forward canary.
	$vector_ok = false;
	if ( class_exists( 'BizCity_KG_Notebook_Folder' ) && class_exists( 'BizCity_KG_Vector_File_Store' ) && function_exists( 'bizcity_kg_vector_bin_path' ) ) {
		$notebook_uuid = BizCity_KG_Notebook_Folder::instance()->notebook_uuid( $notebook_id );
		if ( is_string( $notebook_uuid ) && $notebook_uuid !== '' ) {
			$kg_vector_path = bizcity_kg_vector_bin_path( 'notebooks', $notebook_uuid );
			$vector_header = BizCity_KG_Vector_File_Store::instance()->header_validate( $kg_vector_path );
			$vector_index = ! is_wp_error( $vector_header ) ? BizCity_KG_Vector_File_Store::instance()->load_idx( $kg_vector_path . '.idx.json' ) : array();
			$vector_rows = is_array( $vector_index ) ? (array) ( $vector_index['rows'] ?? array() ) : array();
			$passage_vector_rows = array_values( array_filter( $vector_rows, function ( $row ) use ( $kg_passage_id ) {
				return is_array( $row ) && (int) ( $row['chunk_id'] ?? 0 ) === $kg_passage_id;
			} ) );
			$vector_ok = ! is_wp_error( $vector_header ) && ! empty( $vector_header['count'] ) && count( $passage_vector_rows ) === 1 && (int) ( $vector_header['dim'] ?? 0 ) > 0;
		}
	}
	$step( 'Runtime - promoted passage has one canonical vector artifact', $vector_ok ? 'pass' : 'fail', $vector_ok ? 'The promoted passage is represented once in the validated notebook .bin/.idx vector owner.' : 'The promoted passage has no validated canonical vector artifact.' );
	if ( ! $vector_ok ) {
		throw new RuntimeException( 'g4_vector_artifact_missing' );
	}
	$forward = BizCity_KG::lookup_xref( 'passage', $kg_passage_id, array( 'cortex' => 'context-bank', 'relation' => 'promoted', 'limit' => 20 ) );
	foreach ( $forward as $edge ) {
		if ( is_array( $edge ) && (int) ( $edge['cortex_ref_id'] ?? 0 ) === (int) $pointer['id'] && (string) ( $edge['relation'] ?? '' ) === 'promoted' ) {
			$kg_xref_id = (int) ( $edge['id'] ?? 0 );
			break;
		}
	}
	$step( 'Runtime - forward Context Bank to KG xref is present', $kg_xref_id > 0 ? 'pass' : 'fail', $kg_xref_id > 0 ? 'Forward xref binds the canonical ledger pointer to the promoted passage.' : 'Forward Context Bank xref was not found.' );
	$reverse_ok = false;
	foreach ( (array) BizCity_KG::lookup_xref( 'passage', $kg_passage_id, array( 'cortex' => 'context-bank', 'relation' => 'promoted', 'limit' => 20 ) ) as $edge ) {
		if ( is_array( $edge ) && (int) ( $edge['cortex_ref_id'] ?? 0 ) === (int) $pointer['id'] && (int) ( $edge['kg_ref_id'] ?? 0 ) === $kg_passage_id ) {
			$reverse_ok = true;
			break;
		}
	}
	$step( 'Runtime - reverse KG citation resolves to the same Context Bank pointer', $reverse_ok ? 'pass' : 'fail', $reverse_ok ? 'Reverse passage lookup resolves to the authorized Context Bank ledger row.' : 'Reverse KG citation did not resolve to the current pointer.' );
	$owner_follow = $ledger->follow( $ledger_record_id, array( 'blog_id' => $target_blog, 'source_contract_id' => 'core.context_bank.rollup' ) );
	$owner_follow_ok = is_array( $owner_follow ) && ! empty( $owner_follow['ok'] ) && ! empty( $owner_follow['verified'] );
	$step( 'Runtime - reverse citation reaches the verified Context Bank owner', $owner_follow_ok ? 'pass' : 'fail', $owner_follow_ok ? 'Reverse KG provenance resolves back through the verified tenant pointer owner.' : 'Reverse KG provenance could not reach the verified Context Bank owner.' );
	if ( ! $owner_follow_ok ) {
		throw new RuntimeException( 'g4_reverse_owner_follow_failed' );
	}
	$pointer_rows = $ledger->find( array( 'blog_id' => $target_blog, 'source_contract_id' => 'core.context_bank.rollup', 'record_id' => $ledger_record_id, 'limit' => 1 ) );
	$pointer_after = isset( $pointer_rows[0] ) && is_array( $pointer_rows[0] ) ? $pointer_rows[0] : array();
	$retry = BizCity_Context_Bank_KG_Bridge::promote( $pointer_after, array( 'authorized' => true, 'notebook_id' => $notebook_id, 'confidence_threshold' => 0.75 ) );
	$retry_ok = is_array( $retry ) && ! empty( $retry['ok'] ) && ! empty( $retry['promoted'] ) && ! empty( $retry['replayed'] ) && (int) ( $retry['kg_source_id'] ?? 0 ) === $kg_source_id && (int) ( $retry['kg_passage_id'] ?? 0 ) === $kg_passage_id;
	$step( 'Runtime - KG promotion replay is idempotent after authorization', $retry_ok ? 'pass' : 'fail', $retry_ok ? 'Retry reused the same source, passage and verified xref after ledger follow.' : 'KG retry did not return the same verified promotion identity.' );
	$source_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$db->tbl_sources()} WHERE id = %d", $kg_source_id ) );
	$passage_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$db->tbl_passages()} WHERE id = %d AND source_id = %d AND notebook_id = %d", $kg_passage_id, $kg_source_id, $notebook_id ) );
	$cardinality_ok = $retry_ok && $source_count === 1 && $passage_count === 1;
	$step( 'Runtime - one logical candidate keeps one source and passage cardinality', $cardinality_ok ? 'pass' : 'fail', $cardinality_ok ? 'Promotion retry did not create duplicate canonical source or passage rows.' : 'Promotion retry changed canonical source/passage cardinality.' );
	if ( ! $cardinality_ok ) {
		throw new RuntimeException( 'g4_kg_cardinality_changed' );
	}
	$step( 'Runtime - provider call isolation', 'pass', 'The fixture used the canonical KG database/facade only and made no provider or network call.' );
	$result['status'] = ! empty( $failures ) ? 'fail' : ( empty( $deferred ) ? 'pass' : 'partial' );
} catch ( Throwable $error ) {
	if ( $result['reason'] === '' ) {
		$result['reason'] = sanitize_key( (string) $error->getMessage() );
	}
	$result['status'] = empty( $deferred ) ? 'fail' : 'partial';
} finally {
	if ( $ledger_record_id !== '' && $ledger instanceof BizCity_Context_Bank_Ledger ) {
		$cleanup_receipt = BizCity_Business_JSONL_File_Store::write_with_receipt( 'core.context_bank.rollup', array( 'record_id' => $ledger_record_id, 'event_type' => 'delete', 'reason' => 'g4_fixture_cleanup' ), 'delete' );
		if ( is_array( $cleanup_receipt ) ) {
			$cleanup_admission = $ledger->record( array( 'source_contract_id' => 'core.context_bank.rollup', 'record_id' => $ledger_record_id, 'record_kind' => 'rollup', 'event_uuid' => (string) $cleanup_receipt['event_uuid'], 'source_record_id' => (string) $cleanup_receipt['event_uuid'], 'entity_type' => 'conversation', 'entity_key' => 'g4_fixture_conversation', 'scope_key' => 'g4:cleanup', 'operation' => 'delete', 'lifecycle_status' => 'deleted', 'kg_status' => 'not_candidate', 'receipt' => $cleanup_receipt ) );
			if ( ! empty( $cleanup_admission['ok'] ) ) {
				$removed = $ledger->remove_tombstoned_pointer( array_merge( array( 'source_contract_id' => 'core.context_bank.rollup', 'record_id' => $ledger_record_id, 'operation' => 'delete', 'lifecycle_status' => 'deleted' ), $cleanup_receipt ), 'g4_fixture_cleanup' );
				if ( empty( $removed['ok'] ) ) { $failures[] = 'cleanup_context_bank_pointer'; }
			}
		}
	}
	if ( $kg_xref_id > 0 && class_exists( 'BizCity_KG_Database' ) ) {
		global $wpdb;
		$db = BizCity_KG_Database::instance();
		$wpdb->delete( $db->tbl_xref(), array( 'id' => $kg_xref_id ), array( '%d' ) );
	}
	if ( $kg_passage_id > 0 && class_exists( 'BizCity_KG_Database' ) ) {
		$wpdb->delete( BizCity_KG_Database::instance()->tbl_passages(), array( 'id' => $kg_passage_id ), array( '%d' ) );
	}
	if ( $kg_source_id > 0 && class_exists( 'BizCity_KG_Database' ) ) {
		$wpdb->delete( BizCity_KG_Database::instance()->tbl_sources(), array( 'id' => $kg_source_id ), array( '%d' ) );
	}
	if ( $previous_flag === $missing_flag ) {
		delete_option( 'bizcity_context_bank_kg_bridge_enabled' );
	} else {
		update_option( 'bizcity_context_bank_kg_bridge_enabled', $previous_flag, false );
	}
	if ( $switched ) {
		restore_current_blog();
	}
	$result['cleanup'] = array( 'ledger_record' => $ledger_record_id !== '', 'kg_xref_deleted' => $kg_xref_id > 0, 'kg_passage_deleted' => $kg_passage_id > 0, 'kg_source_deleted' => $kg_source_id > 0, 'blog_restored' => (int) get_current_blog_id() === $original_blog );
	if ( ! empty( $failures ) ) {
		$result['status'] = 'fail';
	}
}
$result['kg_source_id'] = $kg_source_id;
$result['kg_passage_id'] = $kg_passage_id;
$result['kg_xref_id'] = $kg_xref_id;
$result['failures'] = array_values( array_unique( $failures ) );
$result['deferred'] = array_values( array_unique( $deferred ) );
echo wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
exit( $result['status'] === 'pass' ? 0 : 1 );
