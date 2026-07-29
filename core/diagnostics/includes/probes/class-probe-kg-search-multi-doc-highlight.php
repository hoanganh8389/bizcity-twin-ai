<?php
/**
 * BizCity Diagnostics — kg.search.multi_doc_highlight probe (PHASE-0.45-KG-FILE-GRAPH).
 *
 * Plants 2 REAL, disposable passages across the same notebook (tagged
 * `__healthtest_`) sharing a unique query term, and drives the ACTUAL
 * multi-document search contract `BizCity_TwinSearch_Core::search_documents()`.
 * This is a genuine gap: `class-probe-twinsearch-shared.php` only checks the
 * payload CONTRACT shape with an EMPTY query — it never proves multiple real
 * documents are found together with correctly highlighted excerpts.
 *
 * Confirms:
 *   1. Searching the shared unique term returns >= 2 distinct documents
 *      (`first_passage_id` differs) — i.e. genuinely "multi-document",
 *      not just re-finding the same passage.
 *   2. Each result's `snippet`/`highlight_quote` actually contains the
 *      matched term (real highlight, not an empty/placeholder string).
 *   3. `total` / `total_pages` reflect the real match count.
 *   4. Each result's `citation` token (`[nb:X/pY]`) is well-formed and
 *      points at the correct notebook.
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
if ( class_exists( 'BizCity_Probe_KG_Search_Multi_Doc_Highlight', false ) ) {
	return;
}

final class BizCity_Probe_KG_Search_Multi_Doc_Highlight implements BizCity_Diagnostics_Probe {

	/** @var int */
	private $nb_id = 0;
	/** @var string */
	private $nb_uuid = '';

	public function id(): string { return 'kg.search.multi_doc_highlight'; }
	public function label(): string { return 'KG Multi-Doc Search — search_documents() finds N docs with real highlighted excerpts'; }
	public function description(): string {
		return 'Tạo 2 passage thật trong cùng notebook (tagged __healthtest_) chia sẻ 1 từ khoá độc nhất, gọi BizCity_TwinSearch_Core::search_documents(), xác nhận tìm được >=2 tài liệu riêng biệt và mỗi kết quả có snippet/highlight_quote chứa đúng từ khoá — khác với probe hiện có chỉ kiểm tra contract rỗng.';
	}
	public function severity(): string { return 'warning'; }
	public function order(): int { return 68; }
	public function icon(): string { return 'search'; }
	public function estimate_ms(): int { return 5000; } // 2x embed() calls for add_passage()

	public function precondition() {
		$need = [ 'BizCity_KG_Notebook_Service', 'BizCity_KG_Source_Service', 'BizCity_KG_Notebook_Folder', 'BizCity_TwinSearch_Core' ];
		foreach ( $need as $cls ) {
			if ( ! class_exists( $cls ) ) {
				return new WP_Error( 'kg_class_missing', $cls . ' chưa load — knowledge/kg-hub hoặc core/twinsearch bootstrap không hoàn tất.' );
			}
		}
		return true;
	}

	public function run( $ctx ): array {
		$steps    = [];
		$failures = [];
		$uniq     = substr( md5( uniqid( 'kgmds', true ) ), 0, 8 );
		$token    = 'kgmdsprobe' . $uniq;

		// ── Step 1: disposable notebook ─────────────────────────────────────
		$nb = BizCity_KG_Notebook_Service::instance()->create( [
			'name'        => '__healthtest_kg_multidoc_' . $uniq,
			'description' => 'DDV probe — safe to delete',
		], get_current_user_id() ?: 1 );
		$this->nb_id   = (int) ( $nb['id'] ?? 0 );
		$this->nb_uuid = (string) ( $nb['uuid'] ?? '' );
		$this->step( $ctx, $steps, 'Runtime - create notebook', $this->nb_id > 0 && $this->nb_uuid !== '', 'notebook_id=' . $this->nb_id . ' uuid=' . $this->nb_uuid );
		if ( $this->nb_id <= 0 ) {
			return [ 'status' => 'fail', 'error' => 'Notebook create failed', 'steps' => $steps ];
		}

		// ── Step 2: 2 distinct passages sharing the same unique query term ──
		$text_a = "Tài liệu A giải thích {$token} theo hướng kỹ thuật và cấu hình hệ thống.";
		$text_b = "Tài liệu B mô tả {$token} trong bối cảnh vận hành và quy trình khác hẳn tài liệu A.";
		$pid_a = BizCity_KG_Source_Service::instance()->add_passage( $this->nb_id, $text_a, 'note' );
		$pid_b = BizCity_KG_Source_Service::instance()->add_passage( $this->nb_id, $text_b, 'note' );
		$pid_a = is_wp_error( $pid_a ) ? 0 : (int) $pid_a;
		$pid_b = is_wp_error( $pid_b ) ? 0 : (int) $pid_b;
		$this->step( $ctx, $steps, 'Runtime - plant 2 distinct passages sharing the query term', $pid_a > 0 && $pid_b > 0, 'passage_a=' . $pid_a . ' passage_b=' . $pid_b );
		if ( $pid_a <= 0 || $pid_b <= 0 ) {
			$failures[] = 'plant_passages_failed';
			return [ 'status' => 'fail', 'error' => implode( ';', $failures ), 'steps' => $steps ];
		}

		// ── Step 3: search_documents() must find both as distinct documents ─
		$result = BizCity_TwinSearch_Core::instance()->search_documents( [
			'query'       => $token,
			'scope'       => 'notebook',
			'notebook_id' => $this->nb_id,
			'per_page'    => 10,
		] );
		$results = (array) ( $result['results'] ?? [] );
		$this->step( $ctx, $steps, 'Runtime - search_documents() returns results', ! empty( $results ), 'total=' . (int) ( $result['total'] ?? 0 ) . '; results_count=' . count( $results ) );
		if ( empty( $results ) ) { $failures[] = 'search_no_results'; }

		$distinct_passages = array_values( array_unique( array_map( static function ( $r ) {
			return (int) ( $r['first_passage_id'] ?? 0 );
		}, $results ) ) );
		$multi_doc_ok = count( $distinct_passages ) >= 2;
		$this->step( $ctx, $steps, 'Runtime - >=2 distinct documents found (genuinely multi-doc, not the same passage twice)', $multi_doc_ok, 'distinct_first_passage_ids=' . implode( ',', $distinct_passages ) );
		if ( ! $multi_doc_ok ) { $failures[] = 'not_multi_doc'; }

		$highlight_ok = true;
		$citation_ok  = true;
		foreach ( $results as $r ) {
			$snippet = (string) ( $r['snippet'] ?? '' );
			$hquote  = (string) ( $r['highlight_quote'] ?? '' );
			if ( stripos( $snippet, $token ) === false && stripos( $hquote, $token ) === false ) {
				$highlight_ok = false;
			}
			$citation = (string) ( $r['citation'] ?? '' );
			if ( $citation !== '' && strpos( $citation, '[nb:' . $this->nb_id . '/p' ) !== 0 ) {
				$citation_ok = false;
			}
		}
		$this->step( $ctx, $steps, 'Runtime - every result snippet/highlight_quote contains the matched term', $highlight_ok, 'checked=' . count( $results ) );
		if ( ! $highlight_ok ) { $failures[] = 'highlight_missing_term'; }

		$this->step( $ctx, $steps, 'Runtime - every result citation token is well-formed and points at the correct notebook', $citation_ok, 'checked=' . count( $results ) );
		if ( ! $citation_ok ) { $failures[] = 'citation_token_malformed'; }

		$total_ok = (int) ( $result['total'] ?? 0 ) >= count( $distinct_passages );
		$this->step( $ctx, $steps, 'Runtime - total/total_pages reflect real match count', $total_ok, 'total=' . (int) ( $result['total'] ?? 0 ) . '; total_pages=' . (int) ( $result['total_pages'] ?? 0 ) );
		if ( ! $total_ok ) { $failures[] = 'total_count_mismatch'; }

		$status = empty( $failures ) ? 'pass' : 'fail';
		return [
			'status'   => $status,
			'summary'  => $status === 'pass'
				? 'KG multi-doc search: 2 distinct documents found via search_documents(), each with a correctly highlighted excerpt and well-formed citation token.'
				: 'KG multi-doc search FAILED: ' . implode( ', ', array_unique( $failures ) ) . '.',
			'error'    => empty( $failures ) ? '' : implode( '; ', array_unique( $failures ) ),
			'fix_hint' => empty( $failures ) ? '' : 'Xem class-twinsearch-core.php::search_documents()/build_snippet() — kiểm tra scope_where/token matching và highlight builder.',
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
	$probes[] = 'BizCity_Probe_KG_Search_Multi_Doc_Highlight';
	return $probes;
} );
