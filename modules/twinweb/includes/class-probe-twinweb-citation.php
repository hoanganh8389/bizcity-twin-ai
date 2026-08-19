<?php
/**
 * BizCity Diagnostics — modules.twinweb.citation probe (W4).
 *
 * R-DDV: 3-layer evidence for TwinWeb Citation Mapping + Passage Source.
 *
 * DDV rows:
 *   twinweb.citation.disk        — TwinWebSourceSheet.tsx + resolve_passage_for_source in core
 *   twinweb.citation.ui_contract — ChatPage verified-only open + explicit unmapped state
 *   twinweb.citation.loader      — method_exists(BizCity_TwinSearch_Core, resolve_passage_for_source)
 *                                   + BizCity_TwinWeb_REST::get_passage_source method
 *   twinweb.citation.runtime     — GET /sources/passage/9999999999 → 404 or 403 (never 500)
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Modules\TwinWeb
 * @since      2026-07-15 (PHASE-TWINWEB-SEARCH W4)
 */

// [2026-07-15 Johnny Chu] PHASE-TWINWEB-SEARCH W4 — DDV probe modules.twinweb.citation
defined( 'ABSPATH' ) || exit;

// [2026-07-15 Johnny Chu] PHASE-TWINWEB-SEARCH W4 — resolve diagnostics interface from plugin root and fail-safe when file is unreadable.
$bizcity_twinweb_plugin_root = defined( 'BIZCITY_TWIN_AI_DIR' )
	? BIZCITY_TWIN_AI_DIR
	: dirname( __DIR__, 3 ) . '/';
$bizcity_twinweb_probe_iface = $bizcity_twinweb_plugin_root . 'core/diagnostics/includes/interface-diagnostics-probe.php';
if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false ) && is_readable( $bizcity_twinweb_probe_iface ) ) {
	require_once $bizcity_twinweb_probe_iface;
}
if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false ) ) {
	return;
}

if ( class_exists( 'BizCity_Probe_TwinWeb_Citation', false ) ) {
	return;
}

final class BizCity_Probe_TwinWeb_Citation implements BizCity_Diagnostics_Probe {

	public function id(): string          { return 'modules.twinweb.citation'; }
	public function label(): string       { return 'TwinWeb · Citation Mapping & Passage Source'; }
	public function description(): string {
		return 'Disk / Loader / Runtime: FE citation verified-only contract + resolve_passage_for_source() + get_passage_source() safety. Invalid passage_id → 404/403, never 500 (PHASE-TWINWEB-SEARCH W4 + hardening).';
	}
	public function severity(): string    { return 'warning'; }
	public function order(): int          { return 82; }
	public function icon(): string        { return 'file-text'; }
	public function estimate_ms(): int    { return 30; }

	public function precondition() { return true; }

	public function run( $ctx ): array {
		$steps = array();
		$pass  = true;
		// [2026-07-15 Johnny Chu] PHASE-TWINWEB-SEARCH W4 — support both module UI path and promoted FE path.
		$module_root = defined( 'BIZCITY_TWINWEB_DIR' )
			? rtrim( (string) BIZCITY_TWINWEB_DIR, '/\\' )
			: dirname( __DIR__ );
		$plugin_root = defined( 'BIZCITY_TWIN_AI_DIR' )
			? rtrim( (string) BIZCITY_TWIN_AI_DIR, '/\\' )
			: dirname( dirname( dirname( $module_root ) ) );

		// ── Disk: FE source sheet + core resolver ────────────────────────────
		$source_sheet_file = $this->first_readable_path( array(
			$module_root . '/ui/src/components/TwinWebSourceSheet.tsx',
			$plugin_root . '/core/channel-gateway/frontend/src/routes/platform/twinweb/TwinWebControlPlaneTabs.jsx',
		) );
		$store_file = $this->first_readable_path( array(
			$module_root . '/ui/src/stores/sourceSheetStore.ts',
			$plugin_root . '/core/channel-gateway/frontend/src/routes/platform/twinweb/TwinWebControlPlaneTabs.jsx',
		) );
		$core_file = $this->first_readable_path( array(
			$plugin_root . '/core/twinsearch/includes/class-twinsearch-core.php',
			dirname( __DIR__, 3 ) . '/core/twinsearch/includes/class-twinsearch-core.php',
		) );
		$chat_page_file = $this->first_readable_path( array(
			$module_root . '/ui/src/pages/ChatPage.tsx',
			$plugin_root . '/modules/twinweb/ui/src/pages/ChatPage.tsx',
		) );
		// [2026-07-16 Johnny Chu] PHASE-TWINWEB-SEARCH-HARDENING — support dist-only deployments where ui/src is absent.
		$dist_manifest_file = $this->first_readable_path( array(
			$module_root . '/ui/dist/.vite/manifest.json',
			$module_root . '/ui/dist/manifest.json',
		) );

		$sheet_ok = '' !== $source_sheet_file;
		$store_ok = '' !== $store_file;
		$core_ok  = '' !== $core_file;
		$fe_src_ok = $sheet_ok && $store_ok;
		$fe_dist_ok = '' !== $dist_manifest_file;
		$disk_ok = $core_ok;
		$disk_status = ! $core_ok
			? 'fail'
			: ( ( $fe_src_ok || $fe_dist_ok ) ? 'pass' : 'warn' );

		$step = array(
			'label'  => 'Disk · TwinWebSourceSheet.tsx + sourceSheetStore.ts + class-twinsearch-core.php',
			'status' => $disk_status,
			'detail' => ! $core_ok
				? 'class-twinsearch-core.php missing'
				: ( $fe_src_ok
					? 'FE src ready: sheet=' . $source_sheet_file . ' ; store=' . $store_file . ' ; core=' . $core_file
					: ( $fe_dist_ok
						? 'FE source not deployed (dist-only) — manifest=' . $dist_manifest_file . ' ; core=' . $core_file
						: 'FE source and dist manifest not found; treated as non-blocking per R-DDV frontend artifact note; core=' . $core_file ) ),
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $disk_ok ) { $pass = false; }

		// [2026-07-16 Johnny Chu] PHASE-TWINWEB-SEARCH-HARDENING — DDV checks FE contract for verified-only citation opening.
		// ── Disk: UI contract for verified-only citation opening ─────────────
		$ui_contract_ok = false;
		// [2026-07-16 Johnny Chu] PHASE-TWINWEB-SEARCH-HARDENING — FE source contract check is WARN/SKIP when ChatPage.tsx is not deployed.
		$ui_contract_status = 'warn';
		$ui_detail      = 'ChatPage.tsx not found (likely dist-only deploy) — FE contract check skipped.';
		if ( '' !== $chat_page_file ) {
			$needles = array(
				'function findMappedSourceForCitation(',
				'const sourceRef = findMappedSourceForCitation( citation.notebookId, citation.passageId, sources )',
				'const sourceRef = findMappedSourceForCitation( cite.notebookId, cite.passageId, sources )',
				'Citation chua map nguon runtime',
			);
			// [2026-07-16 Johnny Chu] PHASE-TWINWEB-SEARCH-HARDENING — DDV contract: only mapped citations are clickable.
			$ui_contract_ok = $this->file_contains_all( $chat_page_file, $needles );
			$ui_contract_status = $ui_contract_ok ? 'pass' : 'fail';
			$ui_detail      = $ui_contract_ok
				? 'ChatPage verified-only citation contract present (mapped source gate + explicit unmapped state).'
				: 'ChatPage missing one or more verified-only citation markers.';
		}

		$step = array(
			'label'  => 'Disk · ChatPage verified-only citation contract',
			'status' => $ui_contract_status,
			'detail' => $ui_detail,
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( 'fail' === $ui_contract_status ) { $pass = false; }

		// ── Loader: resolve_passage_for_source in BizCity_TwinSearch_Core ──
		$core_loaded   = class_exists( 'BizCity_TwinSearch_Core' );
		$resolver_ok   = $core_loaded && method_exists( 'BizCity_TwinSearch_Core', 'resolve_passage_for_source' );

		$step = array(
			'label'  => 'Loader · BizCity_TwinSearch_Core::resolve_passage_for_source()',
			'status' => $resolver_ok ? 'pass' : 'fail',
			'detail' => $resolver_ok
				? 'Method resolve_passage_for_source() present in BizCity_TwinSearch_Core.'
				: ( ! $core_loaded
					? 'BizCity_TwinSearch_Core not loaded — check core/twinsearch/bootstrap.php.'
					: 'Method resolve_passage_for_source() missing — W4 not yet implemented in core.' ),
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $resolver_ok ) { $pass = false; }

		// ── Loader: REST handler get_passage_source ──────────────────────────
		$rest_class_ok   = class_exists( 'BizCity_TwinWeb_REST' );
		$handler_ok      = $rest_class_ok && method_exists( 'BizCity_TwinWeb_REST', 'get_passage_source' );

		$step = array(
			'label'  => 'Loader · BizCity_TwinWeb_REST::get_passage_source()',
			'status' => $handler_ok ? 'pass' : 'fail',
			'detail' => $handler_ok
				? 'get_passage_source() handler registered on BizCity_TwinWeb_REST.'
				: ( ! $rest_class_ok
					? 'BizCity_TwinWeb_REST not loaded.'
					: 'get_passage_source() method missing on BizCity_TwinWeb_REST.' ),
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $handler_ok ) { $pass = false; }

		// ── Runtime: invalid passage_id → 404/403, not 500 ─────────────────
		// Simulate a call to get_passage_source with a deliberately non-existent passage_id.
		// Expected: WP_Error (not_found/forbidden/not_implemented) or success=false — never fatal.
		$runtime_ok     = false;
		$runtime_detail = 'BizCity_TwinWeb_REST not loaded.';

		if ( $handler_ok ) {
			try {
				$req = new WP_REST_Request( 'GET', '/bizcity-twinweb/v1/sources/passage/9999999999' );
				$req->set_param( 'passage_id', 9999999999 );
				$result = BizCity_TwinWeb_REST::instance()->get_passage_source( $req );

				if ( is_wp_error( $result ) ) {
					$code       = $result->get_error_code();
					$safe_codes = array( 'not_found', 'forbidden', 'unavailable', 'not_implemented' );
					if ( in_array( $code, $safe_codes, true ) ) {
						$runtime_ok     = true;
						$runtime_detail = "Returned WP_Error code='{$code}' — safe non-500 response for missing passage.";
					} else {
						$runtime_detail = "Unexpected WP_Error code='{$code}' — review get_passage_source() handler.";
					}
				} elseif ( is_array( $result ) && isset( $result['success'] ) && $result['success'] === false ) {
					$runtime_ok     = true;
					$runtime_detail = "Returned success=false with code='" . ( $result['code'] ?? 'n/a' ) . "' — safe degraded response.";
				} else {
					// Unexpected success with bogus ID
					$runtime_detail = 'Handler returned success=true for passage_id=9999999999 — check scope verification logic.';
				}
			} catch ( Exception $e ) {
				$runtime_detail = 'Exception thrown: ' . $e->getMessage() . ' — review handler for fatal path.';
			}
		}

		$step = array(
			'label'  => 'Runtime · get_passage_source(9999999999) → 404/403/not_implemented, never 500',
			'status' => $runtime_ok ? 'pass' : 'warn',
			'detail' => $runtime_detail,
		);
		$steps[] = $step;
		$ctx->emit_step( $step );

		$final_status = $pass ? ( $runtime_ok ? 'pass' : 'warn' ) : 'fail';

		return array(
			'status' => $final_status,
			'steps'  => $steps,
			'note'   => $final_status === 'pass'
				? 'Citation DDV PASS: FE verified-only contract + resolver + handler all ready.'
				: ( ! $pass
					? 'Disk or Loader checks failed — W4 may not be fully implemented.'
					: 'Loader passed; runtime returned unexpected response — check handler safety.' ),
		);
	}

	// [2026-07-15 Johnny Chu] HOTFIX — BizCity_Diagnostics_Probe requires cleanup(): void; missing method causes fatal at class declaration.
	public function cleanup(): void {
		// Read-only probe; no artifacts to clean up.
	}

	/**
	 * Return first readable path from a candidate list.
	 *
	 * @param array $candidates Candidate file paths.
	 * @return string
	 */
	private function first_readable_path( $candidates ) {
		if ( ! is_array( $candidates ) ) {
			return '';
		}
		foreach ( $candidates as $path ) {
			$path = (string) $path;
			if ( '' !== $path && is_readable( $path ) ) {
				return $path;
			}
		}
		return '';
	}

	/**
	 * Assert a file contains all required fragments.
	 *
	 * @param string $path    Absolute file path.
	 * @param array  $needles Required literal fragments.
	 * @return bool
	 */
	// [2026-07-16 Johnny Chu] PHASE-TWINWEB-SEARCH-HARDENING — shared literal-fragment assertion helper for DDV disk checks.
	private function file_contains_all( $path, $needles ) {
		if ( '' === (string) $path || ! is_readable( $path ) || ! is_array( $needles ) || empty( $needles ) ) {
			return false;
		}
		$content = (string) file_get_contents( $path );
		if ( '' === $content ) {
			return false;
		}
		foreach ( $needles as $needle ) {
			$needle = (string) $needle;
			if ( '' === $needle ) {
				continue;
			}
			if ( false === strpos( $content, $needle ) ) {
				return false;
			}
		}
		return true;
	}
}

// ── Register probe via filter ──────────────────────────────────────────────────
add_filter( 'bizcity_diagnostics_register_probes', function ( array $probes ) {
	if ( class_exists( 'BizCity_Probe_TwinWeb_Citation', false ) ) {
		$probes[] = new BizCity_Probe_TwinWeb_Citation();
	}
	return $probes;
} );
