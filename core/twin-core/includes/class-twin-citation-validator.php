<?php
/**
 * Bizcity Twin AI — Twin Citation Validator
 *
 * Sprint 4.7g — Validate that LLM cited at least one source per sourced answer.
 * Re-prompt 1 lần nếu thiếu (nguyên tắc: tránh hallucination).
 *
 * Sprint 4.5i — Extended with numeric [n] marker validation for passages
 * injected via extra_system (Hình thức C / Contract §4.3).
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Twin_Core
 * @since 2026-04-26
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Twin_Citation_Validator {

	/**
	 * Validate alphanumeric citation IDs (format `[a3x9]`) — Sprint 4.7g.
	 *
	 * Used by the agent loop to verify that tool-sourced passages are cited.
	 *
	 * @param string   $answer
	 * @param string[] $expected_cite_ids  IDs đã cấp cho LLM
	 * @return array{valid:bool, found:string[], missing:string[], reason:string}
	 */
	public static function validate( string $answer, array $expected_cite_ids ): array {
		$expected = array_values( array_unique( array_map( 'strtolower', $expected_cite_ids ) ) );
		$found    = BizCity_Twin_Citation_Id_Generator::extract_from_text( $answer );

		if ( empty( $expected ) ) {
			// Không có source nào → chỉ cần answer không claim "according to source" mà không cite.
			return [ 'valid' => true, 'found' => $found, 'missing' => [], 'reason' => 'no-sources' ];
		}

		// Yêu cầu: ít nhất 1 trong $expected phải xuất hiện.
		$intersect = array_values( array_intersect( $expected, $found ) );
		if ( ! empty( $intersect ) ) {
			return [ 'valid' => true, 'found' => $intersect, 'missing' => [], 'reason' => 'ok' ];
		}
		return [
			'valid'   => false,
			'found'   => $found,
			'missing' => $expected,
			'reason'  => 'no-citation-marker-in-answer',
		];
	}

	/**
	 * Build re-prompt instruction khi alphanumeric validation fail.
	 */
	public static function reprompt_message( array $missing_ids ): string {
		$ids_str = implode( ', ', array_map( static fn( $id ) => '[' . $id . ']', $missing_ids ) );
		return "Your previous answer did not cite any of the provided sources. "
			. "Re-write the answer including at least one citation marker from: {$ids_str}. "
			. "Each fact you state must be backed by a citation in square brackets.";
	}

	/* ──────────────────────────────────────────────────────────────────── */
	/* Sprint 4.5i — Numeric [n] marker validator (Contract §4.3)          */
	/* ──────────────────────────────────────────────────────────────────── */

	/**
	 * Extract all numeric citation markers `[1]`, `[2]`, … from an answer.
	 *
	 * Only matches pure integers inside brackets — NOT `[a3x9]`, `[K1]`, `[IMG-x]`.
	 *
	 * @param  string $answer
	 * @return int[]  Sorted unique list of found indices.
	 */
	public static function extract_numeric_markers( string $answer ): array {
		if ( preg_match_all( '/\[(\d+)\]/', $answer, $m ) ) {
			$nums = array_map( 'intval', $m[1] );
			$nums = array_values( array_unique( array_filter( $nums, static fn( $n ) => $n > 0 ) ) );
			sort( $nums );
			return $nums;
		}
		return [];
	}

	/**
	 * Phase 0.6 — Detect any KG-Hub citation marker present in answer.
	 *
	 * Matches `[src:187]`, `[src:187#p9921]`, `[K1]`, `[ent:45]`, `[draft:12]`,
	 * `[rel:99]`. Used to short-circuit numeric validation when the LLM follows
	 * the new citation contract instead of bare `[n]`.
	 *
	 * @return array<int, string>  Distinct labels (without brackets) found.
	 */
	public static function extract_kg_markers( string $answer ): array {
		$pattern = '/\[(src:\d+(?:#p\d+)?|ent:\d+|rel:\d+|draft:\d+|K\d+|IMG-[A-Za-z0-9_-]+)\]/';
		if ( preg_match_all( $pattern, $answer, $m ) ) {
			return array_values( array_unique( $m[1] ) );
		}
		return [];
	}

	/**
	 * Validate numeric [n] citation markers in an answer.
	 *
	 * Requires that the answer cites at least ONE passage from the injected
	 * context block (indices 1 … $passage_count). If the answer contains
	 * generic numeric refs that fall within range, it's considered valid.
	 *
	 * @param  string $answer
	 * @param  int    $passage_count  Number of passages provided in extra_system.
	 * @return array{valid:bool, found:int[], reason:string}
	 */
	public static function validate_numeric( string $answer, int $passage_count ): array {
		if ( $passage_count <= 0 ) {
			return [ 'valid' => true, 'found' => [], 'reason' => 'no-passages' ];
		}

		// Phase 0.6 — accept new KG citation labels ([src:N], [src:N#pM], [K1], …)
		// as a valid grounding signal. The host now injects passages with these
		// labels, and the system prompt instructs the LLM to copy them verbatim.
		$kg_markers = self::extract_kg_markers( $answer );
		if ( ! empty( $kg_markers ) ) {
			return [ 'valid' => true, 'found' => $kg_markers, 'reason' => 'kg-marker-present' ];
		}

		$found = self::extract_numeric_markers( $answer );

		// At least one index must be in range [1 … passage_count].
		$in_range = array_values( array_filter( $found, static fn( $n ) => $n >= 1 && $n <= $passage_count ) );
		if ( ! empty( $in_range ) ) {
			return [ 'valid' => true, 'found' => $in_range, 'reason' => 'ok' ];
		}

		// Answer has no numeric markers at all — hallucination risk.
		if ( empty( $found ) ) {
			return [ 'valid' => false, 'found' => [], 'reason' => 'no-numeric-citation-in-answer' ];
		}

		// Answer has numeric markers but all are out of range (e.g. page numbers).
		return [ 'valid' => false, 'found' => $found, 'reason' => 'numeric-markers-out-of-range' ];
	}

	/**
	 * Build re-prompt instruction when numeric [n] validation fails.
	 *
	 * Deliberately terse: the answer + this message must fit in the context
	 * window together with the original system prompt.
	 *
	 * @param int $passage_count Number of passages that were provided.
	 */
	public static function reprompt_numeric( int $passage_count ): string {
		return "Your previous answer did not include any citation markers from the Knowledge Context. "
			. "Sentences that DRAW a fact from a passage must cite it using the EXACT label printed in square brackets at the start of each passage "
			. "(e.g. [src:187#p9921] for KG passages, or [1]…[{$passage_count}] for legacy numeric labels). "
			. "Re-write the answer following the ANCHOR-AND-EXPAND policy: ~20% anchored facts (with [N] markers) + ~80% your own expansion (no marker, in your own voice). "
			. "Do NOT invent passage labels. Expansion sentences from general knowledge are fine and do NOT need a marker.";
	}
}
