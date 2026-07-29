<?php
/**
 * BizCity Diagnostics — kg.citation.link_resolve probe (PHASE-0.45-KG-FILE-GRAPH).
 *
 * Plants a REAL, disposable notebook + passage (tagged `__healthtest_`) and
 * drives the ACTUAL citation resolver contract (R-BRAIN-2 — "FE never parses
 * tokens, must call this resolver") via
 * `BizCity_Twin_Citation_Resolver::resolve_batch()`/`resolve_from_answer()`.
 * This is a genuine gap: `class-probe-twinweb-citation-continuity.php` only
 * checks `origin_url` metadata survives a thread reload — it never calls the
 * resolver itself, so it never proves a `[nb:X/pY]` token actually resolves
 * to the correct highlighted excerpt.
 *
 * Confirms:
 *   1. `resolve_batch(['[nb:{notebook}/p{passage}]'])` returns a record for
 *      that exact token (not silently dropped).
 *   2. `kind === 'nb'` and no `error` key.
 *   3. `evidence_excerpt` contains the unique probe token — i.e. the excerpt
 *      was built from the REAL (filestore-hydrated) passage content, not an
 *      empty/stale value.
 *   4. `extract_tokens()` correctly extracts the same token embedded inside
 *      a full markdown answer body (the shape TwinChat/TwinWeb SSE actually
 *      streams), proving `resolve_from_answer()` end-to-end works too.
 *
 * All test rows are deleted in cleanup(), which always runs (pass or fail).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-07-25 (PHASE-0.45-KG-FILE-GRAPH)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

// [2026-07-25 Johnny Chu] PHASE-0.45-KG-FILE-GRAPH — double-load guard.
if ( class_exists( 'BizCity_Probe_KG_Citation_Resolve', false ) ) {
	return;
}

final class BizCity_Probe_KG_Citation_Resolve implements BizCity_Diagnostics_Probe {

	/** @var int */
	private $nb_id = 0;
	/** @var string */
	private $nb_uuid = '';

	public function id(): string { return 'kg.citation.link_resolve'; }
	public function label(): string { return 'KG Citation Link — [nb:X/pY] resolves to real highlighted excerpt'; }
	public function description(): string {
		return 'Tạo notebook + passage thật (tagged __healthtest_), gọi BizCity_Twin_Citation_Resolver::resolve_batch()/resolve_from_answer() với token [nb:X/pY], xác nhận evidence_excerpt chứa đúng nội dung thật (không rỗng, không lỗi) — khác với probe continuity hiện có chỉ kiểm tra origin_url sống sót qua reload.';
	}
	public function severity(): string { return 'critical'; }
	public function order(): int { return 67; }
	public function icon(): string { return 'link'; }
	public function estimate_ms(): int { return 3000; } // 1 embed() call for add_passage()

	public function precondition() {
		$need = [
			'BizCity_KG_Notebook_Service', 'BizCity_KG_Source_Service', 'BizCity_KG_Notebook_Folder',
			'BizCity_Twin_Citation_Resolver',
		];
		foreach ( $need as $cls ) {
			if ( ! class_exists( $cls ) ) {
				return new WP_Error( 'kg_class_missing', $cls . ' chưa load — knowledge/kg-hub hoặc core/twinbrain bootstrap không hoàn tất.' );
			}
		}
		return true;
	}

	public function run( $ctx ): array {
		$steps    = [];
		$failures = [];
		$uniq     = substr( md5( uniqid( 'kgcit', true ) ), 0, 8 );
		$token    = 'kgcitprobe' . $uniq;

		// ── Step 1: disposable notebook + passage ───────────────────────────
		$nb = BizCity_KG_Notebook_Service::instance()->create( [
			'name'        => '__healthtest_kg_citation_' . $uniq,
			'description' => 'DDV probe — safe to delete',
		], get_current_user_id() ?: 1 );
		$this->nb_id   = (int) ( $nb['id'] ?? 0 );
		$this->nb_uuid = (string) ( $nb['uuid'] ?? '' );
		$this->step( $ctx, $steps, 'Runtime - create notebook', $this->nb_id > 0 && $this->nb_uuid !== '', 'notebook_id=' . $this->nb_id . ' uuid=' . $this->nb_uuid );
		if ( $this->nb_id <= 0 ) {
			return [ 'status' => 'fail', 'error' => 'Notebook create failed', 'steps' => $steps ];
		}

		$passage_text = "Trích đoạn citation probe {$token} dùng để kiểm tra evidence_excerpt của resolver.";
		$pid = BizCity_KG_Source_Service::instance()->add_passage( $this->nb_id, $passage_text, 'note' );
		$pid = is_wp_error( $pid ) ? 0 : (int) $pid;
		$this->step( $ctx, $steps, 'Runtime - add_passage()', $pid > 0, 'passage_id=' . $pid );
		if ( $pid <= 0 ) {
			$failures[] = 'add_passage_failed';
			return [ 'status' => 'fail', 'error' => implode( ';', $failures ), 'steps' => $steps ];
		}

		// ── Step 2: resolve_batch() with the real [nb:X/pY] token ──────────
		$citation_token = "[nb:{$this->nb_id}/p{$pid}]";
		$records = BizCity_Twin_Citation_Resolver::resolve_batch( [ $citation_token ], get_current_user_id() ?: 1 );
		$record  = $records[ $citation_token ] ?? null;
		$this->step( $ctx, $steps, 'Runtime - resolve_batch() returns a record for the token', is_array( $record ), 'keys=' . implode( ',', array_keys( $records ) ) );
		if ( ! is_array( $record ) ) { $failures[] = 'resolve_batch_no_record'; }

		if ( is_array( $record ) ) {
			$kind_ok = ( $record['kind'] ?? '' ) === 'nb';
			$no_error = empty( $record['error'] ?? '' );
			$this->step( $ctx, $steps, 'Runtime - record kind=nb, no error', $kind_ok && $no_error, 'kind=' . (string) ( $record['kind'] ?? '' ) . '; error=' . (string) ( $record['error'] ?? '(none)' ) );
			if ( ! $kind_ok ) { $failures[] = 'resolve_kind_mismatch'; }
			if ( ! $no_error ) { $failures[] = 'resolve_returned_error'; }

			$excerpt = (string) ( $record['evidence_excerpt'] ?? '' );
			$excerpt_ok = strpos( $excerpt, $token ) !== false;
			$this->step( $ctx, $steps, 'Runtime - evidence_excerpt contains real (filestore-hydrated) passage content', $excerpt_ok, 'excerpt_len=' . strlen( $excerpt ) . '; excerpt_preview=' . substr( $excerpt, 0, 80 ) );
			if ( ! $excerpt_ok ) { $failures[] = 'evidence_excerpt_empty_or_wrong'; }
		}

		// ── Step 3: resolve_from_answer() extracts + resolves from a full markdown body ─
		$answer_md = "Theo tài liệu, {$citation_token} xác nhận thông tin liên quan.";
		$from_answer = BizCity_Twin_Citation_Resolver::resolve_from_answer( $answer_md, get_current_user_id() ?: 1 );
		$tokens_ok = in_array( $citation_token, (array) ( $from_answer['tokens'] ?? [] ), true );
		$records_ok = isset( $from_answer['records'][ $citation_token ] );
		$this->step( $ctx, $steps, 'Runtime - resolve_from_answer() extracts + resolves token from markdown answer body', $tokens_ok && $records_ok, 'tokens=' . implode( ',', (array) ( $from_answer['tokens'] ?? [] ) ) . '; records_ok=' . ( $records_ok ? 'yes' : 'no' ) );
		if ( ! $tokens_ok || ! $records_ok ) { $failures[] = 'resolve_from_answer_failed'; }

		$status = empty( $failures ) ? 'pass' : 'fail';
		return [
			'status'   => $status,
			'summary'  => $status === 'pass'
				? 'KG citation link: [nb:X/pY] resolves to a real, non-empty highlighted excerpt via resolve_batch()/resolve_from_answer().'
				: 'KG citation link FAILED: ' . implode( ', ', array_unique( $failures ) ) . '.',
			'error'    => empty( $failures ) ? '' : implode( '; ', array_unique( $failures ) ),
			'fix_hint' => empty( $failures ) ? '' : 'Xem class-twinbrain-citation-resolver.php::resolve_notebook()/lookup_passage_excerpt() — kiểm tra Content_Router::hydrate_passages() trong đường resolver.',
			'steps'    => $steps,
		];
	}

	public function cleanup(): void {
		if ( $this->nb_id > 0 && class_exists( 'BizCity_KG_Notebook_Service' ) ) {
			BizCity_KG_Notebook_Service::instance()->delete( $this->nb_id );
		}
		if ( $this->nb_uuid !== '' && class_exists( 'BizCity_KG_Notebook_Folder' ) ) {
			BizCity_KG_Notebook_Folder::instance()->purge( 'notebooks', $this->nb_uuid );
		}
	}

	/**
	 * @param object           $ctx
	 * @param array<int,array> $steps
	 */
	private function step( $ctx, array &$steps, string $label, bool $ok, string $detail ): void {
		$step = [ 'label' => $label, 'status' => $ok ? 'pass' : 'fail', 'detail' => $detail ];
		$steps[] = $step;
		$ctx->emit_step( $step );
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $probes ) {
	$probes[] = 'BizCity_Probe_KG_Citation_Resolve';
	return $probes;
} );
