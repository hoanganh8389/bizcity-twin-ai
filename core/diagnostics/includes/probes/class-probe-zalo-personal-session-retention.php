<?php
/**
 * Probe: Zalo Personal session retention/recovery hardening (PHASE-0.39C-C8).
 *
 * Source-evidence only (Disk layer). The sidecar is a separate Node process;
 * this probe proves the retry/classification/telemetry/message-split code is
 * present in source, the same way zp.key-scope.denial / zp.callback.scope
 * (class-probe-zalo-personal.php) prove Hub Branch 19 source contracts
 * without loading the Hub plugin. Runtime proof for the TS sidecar lives in
 * its own vitest suite, not here.
 *
 * @package BizCity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since PHASE-0.39C-C8
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Probe_Zalo_Personal_Session_Retention', false ) ) {
	return;
}

final class BizCity_Probe_Zalo_Personal_Session_Retention implements BizCity_Diagnostics_Probe {

	public function id(): string          { return 'modules.zalo-personal.session_retention'; }
	public function label(): string       { return 'Zalo Personal session retention/recovery hardening (C8)'; }
	public function description(): string { return 'Checks restore-on-boot error classification/retry, session-status telemetry, non-Personal settings restart policy, and the split expired/disconnected/bridge-unavailable UI messaging.'; }
	public function severity(): string    { return 'warning'; }
	public function order(): int          { return 47; }
	public function icon(): string        { return 'refresh-cw'; }
	public function estimate_ms(): int    { return 100; }

	public function precondition() {
		$plugin_dir = WP_PLUGIN_DIR . '/bizcity-twin-ai/plugins/bizcity-zalo-personal/';
		if ( ! is_dir( $plugin_dir ) ) {
			return 'Zalo Personal satellite module is not installed; skip optional session-retention probe.';
		}
		return true;
	}

	public function run( $ctx ): array {
		$rows = array();
		$pass = true;

		$root = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : WP_PLUGIN_DIR . '/bizcity-twin-ai/';
		$runtime_bridge_root = getenv( 'BIZCITY_ZCA_BRIDGE_ROOT' );
		// [2026-09-18 11:11 AM Johnny Chu - Chu Hoàng Anh] R-AGENT-PARITY / R-DDV — no operator path in shared code: resolve the deployed sidecar from BIZCITY_ZCA_BRIDGE_ROOT (environment or constant), then fall back to the repository bundle.
		if ( ( ! is_string( $runtime_bridge_root ) || trim( $runtime_bridge_root ) === '' ) && defined( 'BIZCITY_ZCA_BRIDGE_ROOT' ) ) {
			$runtime_bridge_root = (string) BIZCITY_ZCA_BRIDGE_ROOT;
		}
		if ( ! is_string( $runtime_bridge_root ) || trim( $runtime_bridge_root ) === '' ) {
			$runtime_bridge_root = '';
		}
		$sidecar_source_origin = $runtime_bridge_root !== '' ? 'runtime' : 'bundle';
		$sidecar_root = $runtime_bridge_root !== '' ? rtrim( $runtime_bridge_root, '/\\' ) : $root . 'plugins/bizcity-zalo-personal/_library/zca-bridge-main';

		$main_file = $sidecar_root . '/src/main.ts';
		$restore_file = $sidecar_root . '/src/zalo/sessionRestore.ts';
		$account_repo_file = $sidecar_root . '/src/store/accountRepo.ts';
		$migration_file = $sidecar_root . '/src/store/migrations/012_session_lifecycle.sql';
		$settings_routes_file = $sidecar_root . '/src/admin/settingsRoutes.ts';
		$rest_file = $root . 'plugins/bizcity-zalo-personal/includes/shared/class-zalo-bridge-rest.php';
		$inbox_page_file = $root . 'modules/twinweb/ui/src/pages/CrmInboxPage.tsx';

		$main_source = is_readable( $main_file ) ? (string) file_get_contents( $main_file ) : '';
		$restore_source = is_readable( $restore_file ) ? (string) file_get_contents( $restore_file ) : '';
		$account_repo_source = is_readable( $account_repo_file ) ? (string) file_get_contents( $account_repo_file ) : '';
		$settings_routes_source = is_readable( $settings_routes_file ) ? (string) file_get_contents( $settings_routes_file ) : '';
		$rest_source = is_readable( $rest_file ) ? (string) file_get_contents( $rest_file ) : '';
		$inbox_page_source = is_readable( $inbox_page_file ) ? (string) file_get_contents( $inbox_page_file ) : '';
		// [2026-09-18 11:11 AM Johnny Chu - Chu Hoàng Anh] R-DDV / R-DDV-FE — sidecar and React sources are often not deployed next to the plugin; a missing source is SKIP with the way to include it, never a FAIL.
		$sidecar_readable = $main_source !== '' || $restore_source !== '' || $account_repo_source !== '' || $settings_routes_source !== '';
		$sidecar_skip_detail = 'Sidecar source (' . $sidecar_source_origin . ') is not readable on this host. Set BIZCITY_ZCA_BRIDGE_ROOT (environment or constant) to the deployed sidecar root to include it.';

		// Row 1 — restore-on-boot classification + retry (Task 1).
		// The logic lives in src/zalo/sessionRestore.ts (extracted so it is unit
		// testable — main.ts is a script with module-level side effects and exports
		// nothing); main.ts must still wire it in.
		$restore_logic_ok = $restore_source !== ''
			&& strpos( $restore_source, 'isZaloAuthRejection' ) !== false
			&& strpos( $restore_source, 'RESTORE_RETRY_DELAYS_MS' ) !== false
			&& strpos( $restore_source, 'ZcaApiError' ) !== false;
		$restore_wired_ok = $main_source !== '' && strpos( $main_source, 'restoreAccountSession' ) !== false;
		$retry_ok = $restore_logic_ok && $restore_wired_ok;
		if ( $sidecar_readable && ! $retry_ok ) { $pass = false; }
		$rows[] = array(
			'label'  => 'zp.session.restore-retry — Disk (' . $sidecar_source_origin . '): classify auth-rejection vs transport error, retry with backoff',
			'status' => ! $sidecar_readable ? 'skip' : ( $retry_ok ? 'pass' : 'fail' ),
			'detail' => ! $sidecar_readable ? $sidecar_skip_detail : ( $retry_ok
				? 'sessionRestore.ts distinguishes ZcaApiError (Zalo rejected) from transport errors and retries before marking a session expired; main.ts wires it in.'
				: ( $restore_logic_ok ? 'main.ts no longer calls restoreAccountSession().' : 'Restore-on-boot classification/retry markers are missing from src/zalo/sessionRestore.ts.' ) ),
		);

		// Row 2 — telemetry columns + migration (Task 2).
		$telemetry_ok = file_exists( $migration_file )
			&& $account_repo_source !== ''
			&& strpos( $account_repo_source, 'last_status_reason' ) !== false;
		if ( $sidecar_readable && ! $telemetry_ok ) { $pass = false; }
		$rows[] = array(
			'label'  => 'zp.session.telemetry — Disk: last_status_reason/last_status_changed_at migration + repo wiring',
			'status' => ! $sidecar_readable ? 'skip' : ( $telemetry_ok ? 'pass' : 'fail' ),
			'detail' => ! $sidecar_readable ? $sidecar_skip_detail : ( $telemetry_ok ? '012_session_lifecycle.sql exists and accountRepo.updateStatus() persists a reason.' : 'Session lifecycle telemetry migration or accountRepo wiring is missing.' ),
		);

		// Row 3 — settings save restart policy (Task 3).
		// [2026-09-17 09:00 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.39C-C8 — the spec
		// allowed keeping onApply() when a field is genuinely cached at boot.
		// resolveSettings() runs once at boot and the Chatwoot/OA clients are
		// constructed from the resolved cfg, so these fields ARE boot-cached.
		// The correct outcome is therefore an explicit, documented decision —
		// accept either the "no restart" implementation or the documented reason.
		$has_no_restart = $settings_routes_source !== ''
			&& strpos( $settings_routes_source, 'NO-AUTO-RESTART' ) !== false
			&& strpos( $settings_routes_source, 'restarting: false' ) !== false;
		$has_documented_boot_cache = $settings_routes_source !== ''
			&& strpos( $settings_routes_source, 'BOOT-CACHED-RESTART-REQUIRED' ) !== false;
		$no_auto_restart_ok = $has_no_restart || $has_documented_boot_cache;
		if ( $sidecar_readable && ! $no_auto_restart_ok ) { $pass = false; }
		$rows[] = array(
			'label'  => 'zp.session.restart-policy — Disk: Chatwoot/OA settings save documents or avoids the sidecar restart',
			'status' => ! $sidecar_readable ? 'skip' : ( $no_auto_restart_ok ? 'pass' : 'fail' ),
			'detail' => ! $sidecar_readable ? $sidecar_skip_detail : ( $no_auto_restart_ok
				? ( $has_no_restart
					? 'settingsRoutes.ts no longer restarts on non-Personal fields.'
					: 'settingsRoutes.ts keeps the restart and documents why: these fields are read once at boot (resolveSettings) by boot-constructed clients.' )
				: 'settingsRoutes.ts neither avoids the restart nor documents why it is required.' ),
		);

		// Row 4 — readiness envelope surfaces session_live / session_disconnected (Task 4a).
		$readiness_ok = $rest_source !== ''
			&& strpos( $rest_source, "'session_disconnected'" ) !== false
			&& strpos( $rest_source, "'session_live'" ) !== false;
		if ( ! $readiness_ok ) { $pass = false; }
		$rows[] = array(
			'label'  => 'zp.session.readiness-split — Disk: readiness_envelope() surfaces session_live and session_disconnected',
			'status' => $readiness_ok ? 'pass' : 'fail',
			'detail' => $readiness_ok ? 'class-zalo-bridge-rest.php exposes session_live and the session_disconnected state distinct from expired.' : 'readiness_envelope() still collapses live-session loss into status alone.',
		);

		// Row 5 — CRM Inbox UI message split (Task 4b); old combined sentence must be gone.
		$old_combined_gone = $inbox_page_source !== ''
			&& strpos( $inbox_page_source, 'Phiên Zalo Personal đã hết hoặc bridge chưa sẵn sàng' ) === false;
		$split_ok = $inbox_page_source !== ''
			&& strpos( $inbox_page_source, 'sessionDisconnected' ) !== false
			&& strpos( $inbox_page_source, 'bridgeUnavailable' ) !== false
			&& $old_combined_gone;
		$inbox_src_present = $inbox_page_source !== '';
		if ( $inbox_src_present && ! $split_ok ) { $pass = false; }
		$rows[] = array(
			'label'  => 'zp.session.ui-split — Disk: CrmInboxPage splits expired/disconnected/bridge-unavailable messaging',
			'status' => ! $inbox_src_present ? 'skip' : ( $split_ok ? 'pass' : 'fail' ),
			'detail' => ! $inbox_src_present
				? 'React source CrmInboxPage.tsx is not deployed on this host (dist-only deployment); verify the split banners in the built Twin GPT UI.'
				: ( $split_ok ? 'The combined "expired or bridge not ready" sentence was replaced by three distinct, correctly-scoped states.' : ( $old_combined_gone ? 'sessionDisconnected/bridgeUnavailable branches are missing.' : 'The old combined sentence is still present in the deployed CrmInboxPage.tsx — deploy the current source and rebuilt dist.' ) ),
		);

		foreach ( $rows as $row ) {
			$ctx->emit_step( $row );
		}

		// [2026-09-18 11:11 AM Johnny Chu - Chu Hoàng Anh] R-DDV — skipped rows are missing evidence, not a PASS: report warn with the rows that still need a source.
		$skipped_rows = array();
		foreach ( $rows as $row ) {
			if ( 'skip' === $row['status'] ) {
				$skipped_rows[] = strtok( (string) $row['label'], ' ' );
			}
		}
		$status = ! $pass ? 'fail' : ( empty( $skipped_rows ) ? 'pass' : 'warn' );
		return array(
			'status' => $status,
			'summary' => 'pass' === $status
				? 'C8 session retention/recovery source contract present.'
				: ( 'warn' === $status ? 'C8 contract checks that could run passed; ' . count( $skipped_rows ) . ' row(s) lack a readable source on this host.' : 'C8 session retention/recovery source contract incomplete.' ),
			'error' => 'fail' === $status ? 'zalo_personal_session_retention_contract_incomplete' : '',
			'fix_hint' => 'pass' === $status ? '' : ( 'warn' === $status
				? 'Provide the missing sources for: ' . implode( ', ', $skipped_rows ) . ' — set BIZCITY_ZCA_BRIDGE_ROOT for the sidecar rows; the UI row needs the React source or a check of the built UI.'
				: 'Restore the C8 source contract: sessionRestore.ts classification/retry wired from main.ts, 012_session_lifecycle.sql + accountRepo reason, documented settings restart policy, readiness session_live/session_disconnected, and the split CrmInboxPage banners.' ),
		);
	}

	public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', static function ( array $probes ): array {
	$probes[] = new BizCity_Probe_Zalo_Personal_Session_Retention();
	return $probes;
} );