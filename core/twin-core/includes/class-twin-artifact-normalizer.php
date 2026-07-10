<?php
/**
 * BizCity_Twin_Artifact_Normalizer
 *
 * Normalizes raw block execute() output into a standardized artifact
 * for the BizCity_TwinBrain_Workflow_Pipeline artifact pool.
 *
 * Each artifact:
 *  - is trimmed to ≤ 4 KB of text content (full payload kept in SSE stream only).
 *  - carries the source node_id and node_kind for citation and compose prompt.
 *  - has a human-readable `summary` (≤120 chars) for the workflow_step SSE row.
 *
 * @package BizCity_TwinCore
 * @since   1.0.0 [2026-06-19 Johnny Chu] PHASE-TWB-WORKFLOW W1
 */

// Direct file access guard.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class BizCity_Twin_Artifact_Normalizer {

	/**
	 * Maximum text body kept in the artifact pool (characters).
	 * Truncation prevents context explosion when all artifacts are injected
	 * into the final compose prompt.
	 */
	const MAX_BODY_CHARS = 4000;

	/**
	 * Maximum length of the human-readable summary field (characters).
	 */
	const MAX_SUMMARY_CHARS = 120;

	/**
	 * Normalize raw block execute() output into a pool artifact.
	 *
	 * [2026-06-19 Johnny Chu] PHASE-TWB-WORKFLOW W1
	 *
	 * @param mixed  $raw    Whatever the block returned from execute().
	 * @param array  $node   Graph node array { id, kind, label, config }.
	 * @param array  $ctx    Execution context (for metadata — NOT stored in artifact).
	 * @return array {
	 *   node_id:    string,
	 *   node_kind:  string,
	 *   label:      string,
	 *   type:       string,  // text|json|html|url_list|error|empty
	 *   body:       string,  // truncated text ≤ MAX_BODY_CHARS
	 *   summary:    string,  // ≤ MAX_SUMMARY_CHARS, shown in workflow_step SSE
	 *   raw_type:   string,  // PHP gettype() of original $raw
	 *   ms:         int,     // execution time (set by caller after execute())
	 * }
	 */
	public static function normalize( $raw, array $node, array $ctx ): array {
		$node_id   = (string) ( $node['id']    ?? 'unknown' );
		$node_kind = (string) ( $node['kind']  ?? 'unknown' );
		$label     = (string) ( $node['label'] ?? $node_id );

		$base = array(
			'node_id'   => $node_id,
			'node_kind' => $node_kind,
			'label'     => $label,
			'raw_type'  => gettype( $raw ),
			'ms'        => 0, // filled by pipeline after execute()
		);

		// WP_Error → error artifact.
		if ( is_wp_error( $raw ) ) {
			$msg = $raw->get_error_message();
			return array_merge( $base, array(
				'type'    => 'error',
				'body'    => self::trunc( $msg, self::MAX_BODY_CHARS ),
				'summary' => self::trunc( $msg, self::MAX_SUMMARY_CHARS ),
			) );
		}

		// null / false / empty → empty artifact (soft-fail; pipeline continues).
		if ( $raw === null || $raw === false || $raw === '' || $raw === [] ) {
			return array_merge( $base, array(
				'type'    => 'empty',
				'body'    => '',
				'summary' => 'Không có kết quả.',
			) );
		}

		// String → text.
		if ( is_string( $raw ) ) {
			$is_html = self::looks_like_html( $raw );
			return array_merge( $base, array(
				'type'    => $is_html ? 'html' : 'text',
				'body'    => self::trunc( $raw, self::MAX_BODY_CHARS ),
				'summary' => self::make_summary( $raw ),
			) );
		}

		// Array: check for common structural patterns.
		if ( is_array( $raw ) ) {
			return self::normalize_array( $raw, $base );
		}

		// Object → cast to array and process.
		if ( is_object( $raw ) ) {
			return self::normalize_array( (array) $raw, $base );
		}

		// Scalar (int, float, bool) → stringify.
		$str = (string) $raw;
		return array_merge( $base, array(
			'type'    => 'text',
			'body'    => $str,
			'summary' => $str,
		) );
	}

	// ── Private helpers ──────────────────────────────────────────────────────

	/**
	 * Normalize an array output. Handles common block response shapes:
	 *  - { success, body|content|text|answer_md|output, summary?, ... }
	 *  - flat string-keyed record (e.g. CRM event, WP post)
	 *  - numeric list of items (search results, passages)
	 *
	 * [2026-06-19 Johnny Chu] PHASE-TWB-WORKFLOW W1
	 *
	 * @param array $arr  The raw array.
	 * @param array $base Partial artifact.
	 * @return array
	 */
	private static function normalize_array( array $arr, array $base ): array {
		// 1. Detect url_list (numeric array of strings starting with http).
		if ( ! empty( $arr ) && isset( $arr[0] ) && is_string( $arr[0] ) && substr( $arr[0], 0, 4 ) === 'http' ) {
			$body    = implode( "\n", array_slice( $arr, 0, 20 ) );
			$cnt     = count( $arr );
			$summary = $cnt . ' URL' . ( $cnt !== 1 ? 's' : '' );
			return array_merge( $base, array(
				'type'    => 'url_list',
				'body'    => self::trunc( $body, self::MAX_BODY_CHARS ),
				'summary' => $summary,
			) );
		}

		// 2. Standard block response: check well-known text keys.
		// [2026-06-20 Johnny Chu] PHASE-TWB-WORKFLOW W7 — added 'snippet' for action.search_kg output.
		$text_keys = array( 'answer_md', 'content', 'body', 'text', 'output', 'message', 'result', 'snippet' );
		foreach ( $text_keys as $key ) {
			if ( isset( $arr[ $key ] ) && is_string( $arr[ $key ] ) && $arr[ $key ] !== '' ) {
				$body    = $arr[ $key ];
				$summary = isset( $arr['summary'] ) ? (string) $arr['summary'] : self::make_summary( $body );
				return array_merge( $base, array(
					'type'    => self::looks_like_html( $body ) ? 'html' : 'text',
					'body'    => self::trunc( $body, self::MAX_BODY_CHARS ),
					'summary' => self::trunc( $summary, self::MAX_SUMMARY_CHARS ),
				) );
			}
		}

		// 3. List of associative arrays (passages, search hits).
		if ( isset( $arr[0] ) && is_array( $arr[0] ) ) {
			$lines = array();
			foreach ( array_slice( $arr, 0, 10 ) as $item ) {
				// Common KG passage keys.
				$t = (string) ( $item['chunk_text'] ?? $item['text'] ?? $item['content'] ?? '' );
				if ( $t !== '' ) {
					$lines[] = self::trunc( $t, 400 );
				}
			}
			if ( ! empty( $lines ) ) {
				$body    = implode( "\n\n---\n\n", $lines );
				$cnt     = count( $arr );
				$summary = $cnt . ' đoạn · ' . self::make_summary( $lines[0] );
				return array_merge( $base, array(
					'type'    => 'text',
					'body'    => self::trunc( $body, self::MAX_BODY_CHARS ),
					'summary' => self::trunc( $summary, self::MAX_SUMMARY_CHARS ),
				) );
			}
		}

		// 4. Fallback: JSON-encode the array.
		$json    = (string) wp_json_encode( $arr );
		$summary = self::make_summary( $json );
		return array_merge( $base, array(
			'type'    => 'json',
			'body'    => self::trunc( $json, self::MAX_BODY_CHARS ),
			'summary' => $summary,
		) );
	}

	/**
	 * Build a ≤ MAX_SUMMARY_CHARS summary from a text string.
	 * Strips HTML tags, collapses whitespace, word-boundary truncates.
	 *
	 * [2026-06-19 Johnny Chu] PHASE-TWB-WORKFLOW W1
	 */
	private static function make_summary( string $text ): string {
		$clean = wp_strip_all_tags( $text );
		// Normalize whitespace (tabs, newlines, multiple spaces).
		$clean = (string) preg_replace( '/\s+/', ' ', $clean );
		$clean = trim( $clean );
		if ( strlen( $clean ) <= self::MAX_SUMMARY_CHARS ) {
			return $clean;
		}
		// Word-boundary truncate.
		$cut = substr( $clean, 0, self::MAX_SUMMARY_CHARS );
		$sp  = strrpos( $cut, ' ' );
		if ( $sp !== false && $sp > ( self::MAX_SUMMARY_CHARS - 20 ) ) {
			$cut = substr( $cut, 0, $sp );
		}
		return $cut . '…';
	}

	/**
	 * Hard-truncate a string to $max_chars.
	 * [2026-06-19 Johnny Chu] PHASE-TWB-WORKFLOW W1
	 */
	private static function trunc( string $str, int $max_chars ): string {
		if ( strlen( $str ) <= $max_chars ) {
			return $str;
		}
		return substr( $str, 0, $max_chars ) . '…';
	}

	/**
	 * Heuristic: does this string look like HTML?
	 * [2026-06-19 Johnny Chu] PHASE-TWB-WORKFLOW W1
	 */
	private static function looks_like_html( string $str ): bool {
		return strpos( $str, '<' ) !== false && strpos( $str, '>' ) !== false;
	}
}
