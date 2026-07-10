<?php
/**
 * BizCity Diagnostics — twinbrain.retrieval.hydration probe (R-TB-HYDRATE).
 *
 * Guards against regression of the 2026-05-27 P0 bug where TwinBrain
 * `Perspective_Runner` fallbacks (`fetch_recent_passages` /
 * `fetch_passages_by_keyword`) SELECTed only `(id, notebook_id, content)`
 * without shard cols + skipped `BizCity_KG_Content_Router::hydrate_passages()`.
 * Under R-VFS v2 (`storage_ver=2`, body in `.bin` shard) every fallback row
 * came back with empty body → matched_tokens=[] → Final Composer collapsed
 * to web-only answers (Tavily) with hallucinated `[nb:0/p0]` citations.
 *
 * Probe picks the most recent notebook that has ≥1 `storage_ver=2` passage,
 * then asserts:
 *
 *   • `fetch_recent_passages()` returns rows whose `content` is non-empty
 *     after the Content_Router hydration step.
 *   • `fetch_passages_by_keyword()` accepts an extracted body token from a
 *     real v2 row and round-trips back to ≥1 passage (proves overfetch +
 *     hydrate + PHP filter path is wired correctly).
 *   • `run_sources_only()` produces `stance=sources_only` + ≥1 citation
 *     whose passage body length > 0.
 *
 * No data is planted or deleted; probe is purely read-only.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-05-27 (R-TB-HYDRATE Fix D)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';


// [2026-06-08 Johnny Chu] HOTFIX — double-load guard (bootstrap may include via filter AND direct require).
if ( class_exists( 'BizCity_Probe_TwinBrain_Retrieval_Hydration', false ) ) {
	return;
}

final class BizCity_Probe_TwinBrain_Retrieval_Hydration implements BizCity_Diagnostics_Probe {

	const OVERFETCH_PROBE = 10;

	public function id(): string          { return 'twinbrain.retrieval.hydration'; }
	public function label(): string       { return 'TwinBrain Retrieval Hydration (R-TB-HYDRATE)'; }
	public function description(): string {
		return 'Read-only probe: pick a v2 notebook → fetch_recent_passages() phải có body sau hydrate → fetch_passages_by_keyword() round-trip token thật → run_sources_only() emit citation có body. Bảo vệ regression P0 2026-05-27 (perspective trống → final answer toàn Tavily).';
	}
	public function severity(): string { return 'critical'; }
	public function order(): int       { return 62; } // sandwich between memory recall (60) and writer-explicit (~65)
	public function icon(): string     { return 'database-zap'; }
	public function estimate_ms(): int { return 600; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_TwinBrain_Perspective_Runner' ) ) {
			return 'BizCity_TwinBrain_Perspective_Runner chưa load — twinbrain bootstrap không hoàn tất.';
		}
		if ( ! class_exists( 'BizCity_KG_Database' ) ) {
			return 'BizCity_KG_Database chưa load — knowledge module chưa active.';
		}
		if ( ! class_exists( 'BizCity_KG_Content_Router' ) ) {
			return 'BizCity_KG_Content_Router chưa load — VFS filestore chưa wire.';
		}
		return true;
	}

	public function run( $ctx ): array {
		global $wpdb;
		$db  = BizCity_KG_Database::instance();
		if ( ! method_exists( $db, 'tbl_passages' ) ) {
			return [ 'status' => 'fail', 'error' => 'KG_Database::tbl_passages() missing.' ];
		}
		$tbl = $db->tbl_passages();

		// Step 1 — find a notebook that has ≥1 storage_ver=2 passage.
		$nb_id = (int) $wpdb->get_var(
			"SELECT notebook_id FROM {$tbl}
			  WHERE storage_ver = 2 AND notebook_id > 0
			  GROUP BY notebook_id
			  ORDER BY MAX(id) DESC
			  LIMIT 1"
		);
		$ctx->emit_step( [
			'label'  => 'Pick v2 notebook',
			'status' => $nb_id > 0 ? 'pass' : 'skip',
			'detail' => $nb_id > 0 ? ( 'notebook_id=' . $nb_id ) : 'no storage_ver=2 passages — skip (probe needs v2 data to verify hydration)',
		] );
		if ( $nb_id <= 0 ) {
			return [
				'status'  => 'skip',
				'summary' => 'Site chưa có v2 passage nào — skip probe. Sẽ tự PASS/FAIL khi notebook đầu tiên promote sang storage_ver=2.',
			];
		}

		// Step 2 — fetch_recent_passages() via reflection (private method).
		$runner = BizCity_TwinBrain_Perspective_Runner::instance();
		$rows   = $this->invoke_private( $runner, 'fetch_recent_passages', [ $nb_id, self::OVERFETCH_PROBE ] );
		if ( $rows instanceof \Throwable ) {
			return [ 'status' => 'fail', 'error' => 'fetch_recent_passages exception: ' . $rows->getMessage() ];
		}
		$rows = (array) $rows;
		$n    = count( $rows );

		// Count rows with non-empty body AFTER hydrate. THE critical assertion.
		$with_body = 0;
		$sample    = '';
		$sample_id = 0;
		foreach ( $rows as $r ) {
			$body = (string) ( $r['content'] ?? '' );
			if ( $body !== '' ) {
				$with_body++;
				if ( $sample === '' ) {
					$sample    = $body;
					$sample_id = (int) ( $r['id'] ?? 0 );
				}
			}
		}
		$ctx->emit_step( [
			'label'  => 'fetch_recent_passages() body hydrated',
			'status' => ( $n > 0 && $with_body > 0 ) ? 'pass' : 'fail',
			'detail' => sprintf( '%d/%d rows have non-empty body after Content_Router hydration', $with_body, $n ),
		] );
		if ( $n === 0 ) {
			return [
				'status'   => 'fail',
				'error'    => sprintf( 'Notebook #%d has v2 passages but fetch_recent_passages() returned 0 rows.', $nb_id ),
				'fix_hint' => 'Check $wpdb->last_error; có thể SELECT phá vì schema mismatch.',
			];
		}
		if ( $with_body === 0 ) {
			return [
				'status'   => 'fail',
				'summary'  => 'R-TB-HYDRATE VIOLATED — every row from fetch_recent_passages() has empty body. Hydration broken.',
				'error'    => 'All ' . $n . ' rows have content=""; Content_Router::hydrate_passages() not invoked OR shard cols missing in SELECT.',
				'fix_hint' => 'Verify SELECT includes storage_ver,file_shard,file_offset,file_length AND hydrate_passages() called after get_results(). See docs/TWINBRAIN-RULE-PASSAGE-HYDRATION.md §2a.',
			];
		}

		// Step 3 — extract a token from real body, round-trip through keyword fallback.
		$token = $this->extract_probe_token( $sample );
		$ctx->emit_step( [
			'label'  => 'Extract probe token from passage #' . $sample_id,
			'status' => $token !== '' ? 'pass' : 'skip',
			'detail' => $token !== '' ? ( '"' . $token . '"' ) : 'body too short / no alpha word ≥4 chars',
		] );

		if ( $token !== '' ) {
			$kw_rows = $this->invoke_private( $runner, 'fetch_passages_by_keyword', [ $nb_id, [ $token ], self::OVERFETCH_PROBE ] );
			if ( $kw_rows instanceof \Throwable ) {
				return [ 'status' => 'fail', 'error' => 'fetch_passages_by_keyword exception: ' . $kw_rows->getMessage() ];
			}
			$kw_rows = (array) $kw_rows;
			$kw_with_body = 0;
			foreach ( $kw_rows as $r ) {
				if ( (string) ( $r['content'] ?? '' ) !== '' ) $kw_with_body++;
			}
			$ok = ( count( $kw_rows ) > 0 && $kw_with_body > 0 );
			$ctx->emit_step( [
				'label'  => 'fetch_passages_by_keyword() round-trip',
				'status' => $ok ? 'pass' : 'fail',
				'detail' => sprintf( '%d rows, %d with body (token=%s)', count( $kw_rows ), $kw_with_body, $token ),
			] );
			if ( ! $ok ) {
				return [
					'status'   => 'fail',
					'summary'  => 'Keyword fallback failed to round-trip a token extracted from a real v2 passage.',
					'error'    => 'Token "' . $token . '" exists in passage #' . $sample_id . ' body but fetch_passages_by_keyword returned 0 hydrated rows.',
					'fix_hint' => 'Verify fetch_passages_by_keyword overfetches recency rows + hydrates via fetch_recent_passages() + filters via mb_stripos (not WHERE content LIKE). See docs/TWINBRAIN-RULE-PASSAGE-HYDRATION.md §2b.',
				];
			}
		}

		// Step 4 — run_sources_only() end-to-end smoke (uses extracted token as prompt).
		$prompt    = $token !== '' ? $token : 'test';
		$trace_id  = 'PROBE_HYDRATE_' . wp_generate_uuid4();
		$cands     = [ [ 'notebook_id' => $nb_id, 'label' => 'probe', 'reason' => 'probe' ] ];
		$opts      = [ 'keyword_tokens' => $token !== '' ? [ $token ] : [] ];

		// run_sources_only() is private — invoke via reflection. It emits
		// `brain_perspective_answer` events; event table absorbs them safely.
		$answers = $this->invoke_private( $runner, 'run_sources_only', [ $trace_id, $prompt, $cands, $opts ] );
		if ( $answers instanceof \Throwable ) {
			return [ 'status' => 'fail', 'error' => 'run_sources_only exception: ' . $answers->getMessage() ];
		}
		$answers = (array) $answers;
		$row     = $answers[0] ?? [];
		$stance  = (string) ( $row['stance'] ?? '' );
		$cites   = (array) ( $row['citations'] ?? [] );

		$ctx->emit_step( [
			'label'  => 'run_sources_only() produced citations',
			'status' => ( $stance === 'sources_only' && count( $cites ) > 0 ) ? 'pass' : ( count( $cites ) === 0 ? 'fail' : 'pass' ),
			'detail' => sprintf( 'stance=%s · %d citations · model=%s', $stance ?: '∅', count( $cites ), (string) ( $row['model'] ?? '' ) ),
		] );

		if ( $stance !== 'sources_only' || count( $cites ) === 0 ) {
			return [
				'status'   => 'fail',
				'summary'  => 'run_sources_only() did not produce a sources_only stance with citations for a v2 notebook containing the probe token.',
				'error'    => 'stance=' . ( $stance ?: '∅' ) . ', citations=' . count( $cites ),
				'fix_hint' => 'Check Perspective_Runner::run_sources_only() — passages array probably empty after hydrate+rerank path.',
			];
		}

		return [
			'status'  => 'pass',
			'summary' => sprintf(
				'R-TB-HYDRATE OK · notebook #%d · recent=%d/%d body · sources_only · %d citations · token="%s"',
				$nb_id, $with_body, $n, count( $cites ), $token
			),
		];
	}

	public function cleanup(): void {
		// Read-only probe — nothing to clean.
	}

	/**
	 * Extract a probe-friendly token from a passage body: longest alpha-only
	 * word ≥4 chars from the first 1 KB. Returns '' if none qualifies.
	 */
	private function extract_probe_token( string $body ): string {
		$head = mb_substr( $body, 0, 1024, 'UTF-8' );
		if ( ! preg_match_all( '/\p{L}{4,}/u', $head, $m ) ) return '';
		$words = $m[0] ?? [];
		// Prefer the longest distinct word, ties broken alphabetically.
		$words = array_values( array_unique( $words ) );
		usort( $words, static function ( $a, $b ) {
			$diff = mb_strlen( $b, 'UTF-8' ) - mb_strlen( $a, 'UTF-8' );
			return $diff !== 0 ? $diff : strcmp( $a, $b );
		} );
		$pick = (string) ( $words[0] ?? '' );
		// Cap to 24 chars to avoid pathological glued strings.
		return mb_substr( $pick, 0, 24, 'UTF-8' );
	}

	/**
	 * Call a private/protected method via Reflection. Returns the return value
	 * on success, or the thrown Throwable on failure (caller checks instanceof).
	 */
	private function invoke_private( object $obj, string $method, array $args ) {
		try {
			$ref = new \ReflectionMethod( $obj, $method );
			$ref->setAccessible( true );
			return $ref->invokeArgs( $obj, $args );
		} catch ( \Throwable $e ) {
			return $e;
		}
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_TwinBrain_Retrieval_Hydration';
	return $list;
} );
