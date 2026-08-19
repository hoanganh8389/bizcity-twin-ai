<?php
/**
 * BizCity Diagnostics — modules.twinweb.document_search probe (W2+W3).
 *
 * R-DDV: 3-layer evidence for TwinWeb Guru-scoped document search
 * and conversation search.
 *
 * DDV rows:
 *   twinweb.doc_search.disk       — REST handler methods + FE api file exist
 *   twinweb.doc_search.loader     — BizCity_TwinSearch_Core loaded + search_documents method
 *   twinweb.doc_search.runtime    — search_documents(empty query) returns valid result structure
 *   twinweb.conv_search.loader    — REST class has search_conversations method
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Modules\TwinWeb
 * @since      2026-07-15 (PHASE-TWINWEB-SEARCH W2+W3)
 */

// [2026-07-15 Johnny Chu] PHASE-TWINWEB-SEARCH W2/W3 — DDV probe modules.twinweb.document_search
defined( 'ABSPATH' ) || exit;

// [2026-07-15 Johnny Chu] PHASE-TWINWEB-SEARCH W2/W3 — resolve diagnostics interface from plugin root and fail-safe when file is unreadable.
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

if ( class_exists( 'BizCity_Probe_TwinWeb_Document_Search', false ) ) {
	return;
}

final class BizCity_Probe_TwinWeb_Document_Search implements BizCity_Diagnostics_Probe {

	public function id(): string          { return 'modules.twinweb.document_search'; }
	public function label(): string       { return 'TwinWeb · Document & Conversation Search'; }
	public function description(): string {
		return 'Disk / Loader / Runtime: bizcity-twinweb/v1/search/documents (W2), search/conversations (W3), BizCity_TwinSearch_Core delegate, degrade-safe Guru-scope resolve.';
	}
	public function severity(): string    { return 'warning'; }
	public function order(): int          { return 81; }
	public function icon(): string        { return 'search'; }
	public function estimate_ms(): int    { return 50; }

	public function precondition() { return true; }

	public function run( $ctx ): array {
		$steps = array();
		$pass  = true;

		// [2026-07-15 Johnny Chu] PHASE-TWINWEB-SEARCH W2/W3 — support both module UI path and promoted FE path.
		$module_root = defined( 'BIZCITY_TWINWEB_DIR' )
			? rtrim( (string) BIZCITY_TWINWEB_DIR, '/\\' )
			: dirname( __DIR__ );
		$plugin_root = defined( 'BIZCITY_TWIN_AI_DIR' )
			? rtrim( (string) BIZCITY_TWIN_AI_DIR, '/\\' )
			: dirname( dirname( dirname( $module_root ) ) );

		// ── Disk: REST file + FE api/search.ts ──────────────────────────────
		$rest_candidates = array(
			$module_root . '/includes/class-twinweb-rest.php',
			__DIR__ . '/class-twinweb-rest.php',
		);
		$fe_candidates = array(
			$module_root . '/ui/src/api/search.ts',
			$plugin_root . '/modules/twinweb/ui/src/api/search.ts',
			$plugin_root . '/core/channel-gateway/frontend/src/routes/platform/twinweb/TwinWebSearchDialog.tsx',
		);
		// [2026-07-20 Johnny Chu] PHASE-TWINWEB-SEARCH-HARDENING — FE src may be absent on dist-only deploys; accept built Vite artifact as non-blocking evidence.
		$fe_dist_candidates = array(
			$module_root . '/ui/dist/.vite/manifest.json',
			$module_root . '/ui/dist/manifest.json',
			$plugin_root . '/modules/twinweb/ui/dist/.vite/manifest.json',
			$plugin_root . '/modules/twinweb/ui/dist/manifest.json',
		);

		$rest_file = $this->first_readable_path( $rest_candidates );
		$fe_file   = $this->first_readable_path( $fe_candidates );
		$fe_dist_file = $this->first_readable_path( $fe_dist_candidates );

		$rest_readable = '' !== $rest_file;
		$fe_readable   = '' !== $fe_file;
		$fe_dist_readable = '' !== $fe_dist_file;
		$disk_ok       = $rest_readable;
		$disk_status   = ! $rest_readable
			? 'fail'
			: ( ( $fe_readable || $fe_dist_readable ) ? 'pass' : 'warn' );

		$step = array(
			'label'  => 'Disk · class-twinweb-rest.php + ui/src/api/search.ts readable',
			'status' => $disk_status,
			'detail' => ! $rest_readable
				? 'class-twinweb-rest.php missing (all candidates)'
				: ( $fe_readable
					? 'REST=' . $rest_file . ' ; FE=' . $fe_file
					: ( $fe_dist_readable
						? 'FE source not deployed (dist-only) — REST=' . $rest_file . ' ; manifest=' . $fe_dist_file
						: 'FE source and dist manifest missing; treated as non-blocking per R-DDV frontend artifact note. REST=' . $rest_file ) ),
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $disk_ok ) { $pass = false; }

		// ── Loader: TwinSearch_Core class + method contract ─────────────────
		$core_ok   = class_exists( 'BizCity_TwinSearch_Core' );
		$method_ok = $core_ok && method_exists( 'BizCity_TwinSearch_Core', 'search_documents' )
			&& method_exists( 'BizCity_TwinSearch_Core', 'resolve_scope' );

		$step = array(
			'label'  => 'Loader · BizCity_TwinSearch_Core::search_documents() + resolve_scope()',
			'status' => ( $core_ok && $method_ok ) ? 'pass' : 'fail',
			'detail' => ( $core_ok && $method_ok )
				? 'Class loaded; search_documents() + resolve_scope() present.'
				: ( ! $core_ok
					? 'BizCity_TwinSearch_Core not loaded — check core/twinsearch/bootstrap.php.'
					: 'Method search_documents() or resolve_scope() missing in class.' ),
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $core_ok || ! $method_ok ) { $pass = false; }

		// ── Loader: REST class has search_documents + search_conversations ──
		$rest_class_ok  = class_exists( 'BizCity_TwinWeb_REST' );
		$has_doc_method = $rest_class_ok && method_exists( 'BizCity_TwinWeb_REST', 'search_documents' );
		$has_conv_method= $rest_class_ok && method_exists( 'BizCity_TwinWeb_REST', 'search_conversations' );

		$step = array(
			'label'  => 'Loader · BizCity_TwinWeb_REST::search_documents() + search_conversations()',
			'status' => ( $has_doc_method && $has_conv_method ) ? 'pass' : 'fail',
			'detail' => ( $has_doc_method && $has_conv_method )
				? 'Both handlers registered on BizCity_TwinWeb_REST.'
				: implode( '; ', array_filter( array(
					$rest_class_ok ? '' : 'BizCity_TwinWeb_REST not loaded',
					$has_doc_method  ? '' : 'search_documents() method missing',
					$has_conv_method ? '' : 'search_conversations() method missing (W3)',
				) ) ),
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $has_doc_method || ! $has_conv_method ) { $pass = false; }

		// ── Runtime: empty-query fast path returns well-formed structure ────
		// Call search_documents with empty string — must return array with expected keys
		// without querying DB (BizCity_TwinSearch_Core short-circuits on empty query).
		$runtime_ok     = false;
		$runtime_detail = 'BizCity_TwinSearch_Core not loaded.';

		if ( $core_ok && $method_ok ) {
			try {
				$result = BizCity_TwinSearch_Core::instance()->search_documents( array(
					'query'        => '',
					'scope'        => 'character',
					'character_id' => 0,
					'user_id'      => 0,
					'page'         => 1,
					'per_page'     => 10,
				) );

				$has_results_key     = is_array( $result ) && array_key_exists( 'results', $result );
				$has_tokens_key      = is_array( $result ) && array_key_exists( 'tokens',  $result );
				$has_total_pages_key = is_array( $result ) && array_key_exists( 'total_pages', $result );
				$runtime_ok          = $has_results_key && $has_tokens_key && $has_total_pages_key;

				$runtime_detail = $runtime_ok
					? 'search_documents({empty query}) returned {results, tokens, total_pages} keys — empty-query fast path OK.'
					: 'search_documents returned array but missing required keys: '
						. implode( ', ', array_filter( array(
							$has_results_key     ? '' : 'results',
							$has_tokens_key      ? '' : 'tokens',
							$has_total_pages_key ? '' : 'total_pages',
						) ) );
			} catch ( Exception $e ) {
				$runtime_detail = 'Exception during search_documents call: ' . $e->getMessage();
			}
		}

		$step = array(
			'label'  => 'Runtime · search_documents(empty query) returns {results, tokens, total_pages}',
			'status' => $runtime_ok ? 'pass' : 'warn',
			'detail' => $runtime_detail,
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		// Runtime warn is non-fatal — core may degrade gracefully on zero character_id.

		$final_status = $pass ? ( $runtime_ok ? 'pass' : 'warn' ) : 'fail';

		return array(
			'status' => $final_status,
			'steps'  => $steps,
			'note'   => $final_status === 'pass'
				? 'W2+W3 document/conversation search ready.'
				: ( ! $pass
					? 'Disk or Loader checks failed — review above steps.'
					: 'Core loaded but runtime returned degraded result — check TwinSearch core.' ),
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
}

// ── Register probe via filter ──────────────────────────────────────────────────
add_filter( 'bizcity_diagnostics_register_probes', function ( array $probes ) {
	if ( class_exists( 'BizCity_Probe_TwinWeb_Document_Search', false ) ) {
		$probes[] = new BizCity_Probe_TwinWeb_Document_Search();
	}
	return $probes;
} );
