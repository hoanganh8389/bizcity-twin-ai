<?php
/**
 * Read-only DDV probe for PHASE-0.56 H-15 — CRM message storage lifecycle.
 *
 * This probe never enables offload, writes SQL, archives messages, deletes
 * files, calls providers, or creates fixtures. It reports the current tenant's
 * disk/loader/schema state and bounded lifecycle counters only.
 */

defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'BizCity_Diagnostics_Probe' ) || class_exists( 'BizCity_Probe_CRM_Message_Storage', false ) ) {
	return;
}

final class BizCity_Probe_CRM_Message_Storage implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'core.crm.message_storage'; }
	public function label(): string { return 'CRM message storage lifecycle (PHASE-0.56 H-15)'; }
	public function description(): string { return 'Read-only check of CRM hot/archive/offload indexes, archive receipt pointers, keyring wiring and the production offload flag.'; }
	public function severity(): string { return 'critical'; }
	public function order(): int { return 89; }
	public function icon(): string { return 'database'; }
	public function estimate_ms(): int { return 500; }

	public function precondition() {
		foreach ( array( 'BizCity_CRM_DB_Installer_V2', 'BizCity_CRM_Repository', 'BizCity_Channel_Conversation_Archive' ) as $class ) {
			if ( ! class_exists( $class ) ) { return new WP_Error( 'crm_message_storage_dependency_missing', $class . ' is not loaded.' ); }
		}
		if ( ! function_exists( 'get_current_blog_id' ) || (int) get_current_blog_id() <= 0 ) {
			return new WP_Error( 'crm_message_storage_tenant_missing', 'A routed tenant context is required.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		global $wpdb;
		$root = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR . '/bizcity-twin-ai' : '';
		$archive_file = $root . '/core/channel-gateway/includes/class-channel-conversation-archive.php';
		$repository_file = $root . '/plugins/bizcity-twin-crm/includes/class-repository.php';
		$disk_ok = is_readable( $archive_file ) && is_readable( $repository_file );
		$ctx->emit_step( array( 'label' => 'Disk · archive and repository artifacts', 'status' => $disk_ok ? 'pass' : 'fail', 'detail' => $disk_ok ? 'Archive owner and CRM repository are readable.' : 'Required storage artifacts are missing or unreadable.' ) );

		$loader_ok = method_exists( 'BizCity_Channel_Conversation_Archive', 'read_batch' )
			&& method_exists( 'BizCity_Channel_Conversation_Archive', 'archive_key_version' )
			&& method_exists( 'BizCity_CRM_Repository', 'hydrate_messages' )
			&& method_exists( 'BizCity_CRM_Repository', 'offload_staged_tick' );
		$ctx->emit_step( array( 'label' => 'Loader · storage read/write gates', 'status' => $loader_ok ? 'pass' : 'fail', 'detail' => $loader_ok ? 'read_batch, key version, hydrate_messages and staged tick are loaded.' : 'One or more canonical storage methods are unavailable.' ) );

		$flag = function_exists( 'get_option' ) ? (bool) get_option( 'bizcity_crm_message_offload_enabled', false ) : false;
		$ctx->emit_step( array( 'label' => 'Safety · production offload remains disabled', 'status' => ! $flag ? 'pass' : 'fail', 'detail' => ! $flag ? 'bizcity_crm_message_offload_enabled=false.' : 'Offload flag is enabled; staged rollout evidence is incomplete.' ) );

		$messages = BizCity_CRM_DB_Installer_V2::tbl_messages();
		$receipts = BizCity_CRM_DB_Installer_V2::tbl_archive_receipts();
		$tables_ok = BizCity_CRM_DB_Installer_V2::table_exists( $messages ) && BizCity_CRM_DB_Installer_V2::table_exists( $receipts );
		$ctx->emit_step( array( 'label' => 'Runtime · storage tables exist', 'status' => $tables_ok ? 'pass' : 'fail', 'detail' => $tables_ok ? 'Messages and archive receipts tables exist in the current tenant.' : 'Storage tables are missing.' ) );
		if ( ! $tables_ok ) {
			return array( 'status' => 'fail', 'summary' => 'CRM storage tables are unavailable.', 'error' => 'crm_storage_tables_missing', 'fix_hint' => 'Run the tenant-safe CRM schema installer before storage validation.' );
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( 24 * HOUR_IN_SECONDS ) );
		$hot_stale = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$messages}` WHERE content_storage_state = 'hot' AND created_at < %s", $cutoff ) );
		$missing_offsets = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$receipts}` WHERE archive_status = 'written' AND (byte_offset IS NULL OR line_bytes IS NULL)" );
		$missing_offsets_v2 = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$receipts}` WHERE archive_status = 'written' AND archive_schema_version >= 2 AND (byte_offset IS NULL OR line_bytes IS NULL)" );
		$missing_offsets_legacy = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$receipts}` WHERE archive_status = 'written' AND archive_schema_version < 2 AND (byte_offset IS NULL OR line_bytes IS NULL)" );
		$written_receipts = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$receipts}` WHERE archive_status = 'written'" );
		$expired = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$messages}` WHERE content_storage_state = 'expired'" );
		$offloaded = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$messages}` WHERE content_storage_state = 'offloaded'" );
		$dry_run_candidates = 0;
		$dry_run_ok = method_exists( 'BizCity_CRM_Repository', 'offload_archived_messages' );
		$legacy_pointer_dry_run = array( 'ok' => false, 'reason' => 'legacy_pointer_reconciler_unavailable' );
		if ( method_exists( 'BizCity_Channel_Conversation_Archive', 'reconcile_legacy_receipt_pointers' ) ) {
			// [2026-09-21 05:30 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.56-H-11 — inventory legacy pointer repair without writing receipt rows.
			$legacy_pointer_dry_run = BizCity_Channel_Conversation_Archive::reconcile_legacy_receipt_pointers( 100, true );
		}
		if ( $dry_run_ok ) {
			// [2026-09-21 05:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.56-H-11 — invoke the bounded selector in dry-run mode only; this must not mutate SQL, files or the feature flag.
			$dry_run_candidates = (int) BizCity_CRM_Repository::offload_archived_messages( current_time( 'mysql' ), 25, 20, true );
		}
		$lifecycle_ok = 0 === $hot_stale && 0 === $missing_offsets;
		$ctx->emit_step( array( 'label' => 'Runtime · lifecycle counters', 'status' => $lifecycle_ok ? 'pass' : 'fail', 'detail' => sprintf( 'hot_over_24h=%d; offloaded=%d; expired=%d; receipts_missing_pointer=%d.', $hot_stale, $offloaded, $expired, $missing_offsets ) ) );
		$ctx->emit_step( array( 'label' => 'Runtime · receipt pointer classification', 'status' => 0 === $missing_offsets ? 'pass' : 'fail', 'detail' => sprintf( 'written_receipts=%d; missing_pointer_v2=%d; missing_pointer_legacy=%d.', $written_receipts, $missing_offsets_v2, $missing_offsets_legacy ) ) );
		$ctx->emit_step( array( 'label' => 'Runtime · H-11 offload selector dry-run', 'status' => $dry_run_ok ? 'pass' : 'fail', 'detail' => $dry_run_ok ? sprintf( 'dry_run=true; eligible_candidates=%d; no SQL/file mutation performed.', $dry_run_candidates ) : 'Canonical offload selector is unavailable.' ) );
		$ctx->emit_step( array( 'label' => 'Runtime · legacy receipt pointer repair dry-run', 'status' => ! empty( $legacy_pointer_dry_run['ok'] ) ? 'pass' : 'fail', 'detail' => sprintf( 'dry_run=true; receipts_scanned=%d; matched=%d; repairable=%d; files_missing=%d; archive_lines_scanned=%d; malformed_lines=%d; hash_mismatches=%d; hash_matches_exact=%d; hash_matches_without_newline=%d; unmatched_receipts=%d; partitions=%d; no receipt mutation performed.', (int) ( $legacy_pointer_dry_run['scanned'] ?? 0 ), (int) ( $legacy_pointer_dry_run['matched'] ?? 0 ), (int) ( $legacy_pointer_dry_run['matched'] ?? 0 ), (int) ( $legacy_pointer_dry_run['files_missing'] ?? 0 ), (int) ( $legacy_pointer_dry_run['archive_lines_scanned'] ?? 0 ), (int) ( $legacy_pointer_dry_run['malformed_lines'] ?? 0 ), (int) ( $legacy_pointer_dry_run['hash_mismatches'] ?? 0 ), (int) ( $legacy_pointer_dry_run['hash_matches_exact'] ?? 0 ), (int) ( $legacy_pointer_dry_run['hash_matches_without_newline'] ?? 0 ), (int) ( count( $legacy_pointer_dry_run['partitions'] ?? array() ) ) ) ) );

		$keyring_ok = method_exists( 'BizCity_Channel_Conversation_Archive', 'rotate_archive_key' ) && method_exists( 'BizCity_Channel_Conversation_Archive', 'archive_key_version' );
		$ctx->emit_step( array( 'label' => 'Runtime · archive keyring contract', 'status' => $keyring_ok ? 'pass' : 'fail', 'detail' => $keyring_ok ? 'Key version and rotation methods are available; no rotation was performed.' : 'Keyring contract is unavailable.' ) );

		$pass = $disk_ok && $loader_ok && ! $flag && $tables_ok && $lifecycle_ok && $dry_run_ok && ! empty( $legacy_pointer_dry_run['ok'] ) && $keyring_ok;
		return array(
			'status' => $pass ? 'pass' : 'fail',
			'summary' => $pass ? 'CRM message storage is read-only healthy; production offload remains disabled.' : 'CRM message storage lifecycle has a failing read-only check.',
			'error' => $pass ? '' : 'crm_message_storage_probe_failed',
			'fix_hint' => $pass ? '' : 'Do not enable production offload until storage counters, receipt pointers and keyring health are clean.',
			'metrics' => array( 'hot_over_24h' => $hot_stale, 'offloaded' => $offloaded, 'expired' => $expired, 'receipts_missing_pointer' => $missing_offsets, 'receipts_missing_pointer_v2' => $missing_offsets_v2, 'receipts_missing_pointer_legacy' => $missing_offsets_legacy, 'written_receipts' => $written_receipts, 'dry_run_candidates' => $dry_run_candidates, 'legacy_pointer_dry_run' => $legacy_pointer_dry_run, 'offload_enabled' => $flag ),
		);
	}

	public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_CRM_Message_Storage';
	return $list;
} );