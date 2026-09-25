<?php
/**
 * Class BizCity_KG_AV_Chunker
 *
 * Phase 0.7 / Wave E0.AV / Sprint D — temporal-aware passage chunker for
 * audio/video transcripts.
 *
 * Input  : raw transcript text from BizCity_Router_Transcribe_REST. Expected
 *          to contain `[mm:ss]`, `[scene]`, and `[speaker:N]` markers per the
 *          v2 prompt — but the chunker is tolerant: missing markers fall back
 *          to a fixed-window split.
 *
 * Output : array of segments, each:
 *          [
 *              'text'      => string,       // visible passage (markers stripped)
 *              'start_ts'  => int,          // seconds (0 if unknown)
 *              'end_ts'    => int,          // seconds (0 if unknown)
 *              'speaker'   => int|null,     // 1-based ordinal, null if unknown
 *              'is_scene'  => bool,         // segment crosses a [scene] break
 *              'meta'      => array,        // ad-hoc bag for downstream embed writer
 *          ]
 *
 * Splitting policy (priority order):
 *   1. `[scene]` break — always produces a segment boundary.
 *   2. `[speaker:N]` change — boundary unless the previous segment is < 60 chars.
 *   3. `[mm:ss]` marker that pushes accumulator over MAX_CHARS — boundary.
 *   4. Hard cap at MAX_CHARS — boundary at next sentence-end (period/?/!/。).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BizCity_KG_AV_Chunker {

	const MAX_CHARS  = 1500;
	const MIN_CHARS  =   60;

	/**
	 * @param string $transcript Raw transcript text from the gateway.
	 * @return array list of segments (see class docblock).
	 */
	public static function chunk( string $transcript ): array {
		$transcript = trim( $transcript );
		if ( $transcript === '' ) return [];

		$segments = [];
		$buf      = '';
		$cur      = [
			'start_ts' => 0,
			'end_ts'   => 0,
			'speaker'  => null,
			'is_scene' => false,
			'last_ts'  => 0,
		];

		$lines = preg_split( '/\r\n|\r|\n/', $transcript );
		foreach ( $lines as $raw_line ) {
			$line = trim( $raw_line );
			if ( $line === '' ) {
				$buf .= "\n";
				continue;
			}

			// Hard breaks first.
			if ( strcasecmp( $line, '[scene]' ) === 0 ) {
				if ( $buf !== '' ) {
					$segments[] = self::finalize_segment( $buf, $cur );
					$buf = '';
				}
				$cur['is_scene'] = true;
				$cur['start_ts'] = $cur['last_ts'];
				continue;
			}

			// Speaker change: `[speaker:3]`
			if ( preg_match( '/^\[speaker:(\d+)\]$/i', $line, $m ) ) {
				$new_speaker = (int) $m[1];
				if ( $cur['speaker'] !== null && $cur['speaker'] !== $new_speaker && strlen( $buf ) >= self::MIN_CHARS ) {
					$segments[] = self::finalize_segment( $buf, $cur );
					$buf = '';
					$cur['start_ts'] = $cur['last_ts'];
					$cur['is_scene'] = false;
				}
				$cur['speaker'] = $new_speaker;
				continue;
			}

			// Inline timestamp marker(s) `[mm:ss]` or `[hh:mm:ss]`. May appear at
			// the start of a line or embedded mid-line.
			$line_ts = self::scan_timestamps( $line );
			if ( ! empty( $line_ts ) ) {
				$first = $line_ts[0];
				if ( $cur['start_ts'] === 0 && $cur['last_ts'] === 0 ) {
					$cur['start_ts'] = $first;
				}
				$cur['last_ts'] = end( $line_ts );
			}

			// Strip markers from the visible text.
			$visible = self::strip_markers( $line );
			if ( $visible !== '' ) {
				$buf = $buf === '' ? $visible : ( $buf . ' ' . $visible );
			}

			// Boundary check: enforce MAX_CHARS at sentence end.
			if ( strlen( $buf ) >= self::MAX_CHARS ) {
				$cut = self::cut_at_sentence_end( $buf );
				$segments[] = self::finalize_segment( $cut['head'], $cur );
				$buf = ltrim( $cut['tail'] );
				$cur['start_ts'] = $cur['last_ts'];
				$cur['is_scene'] = false;
			}
		}

		if ( $buf !== '' ) {
			$segments[] = self::finalize_segment( $buf, $cur );
		}

		// Collapse zero-length / whitespace-only segments and finalize end_ts.
		$out = [];
		foreach ( $segments as $i => $seg ) {
			$txt = trim( $seg['text'] );
			if ( $txt === '' ) continue;
			// end_ts of segment N = start_ts of segment N+1 (or last_ts of N if final).
			$next = $segments[ $i + 1 ] ?? null;
			$end  = $seg['end_ts'];
			if ( $end === 0 && is_array( $next ) ) {
				$end = (int) $next['start_ts'];
			}
			$seg['text']   = $txt;
			$seg['end_ts'] = max( $end, $seg['start_ts'] );
			$out[] = $seg;
		}
		return $out;
	}

	/* ── helpers ── */

	/** Find every `[mm:ss]` or `[h:mm:ss]` marker in a line, return seconds list. */
	private static function scan_timestamps( string $line ): array {
		$out = [];
		if ( preg_match_all( '/\[(\d{1,2}):(\d{1,2})(?::(\d{1,2}))?\]/', $line, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $m ) {
				if ( isset( $m[3] ) && $m[3] !== '' ) {
					$secs = ( (int) $m[1] ) * 3600 + ( (int) $m[2] ) * 60 + (int) $m[3];
				} else {
					$secs = ( (int) $m[1] ) * 60 + (int) $m[2];
				}
				$out[] = $secs;
			}
		}
		return $out;
	}

	private static function strip_markers( string $line ): string {
		// Remove timestamps, scene/speaker markers but keep [music] / [NO_SPEECH_DETECTED].
		$line = preg_replace( '/\[(\d{1,2}):(\d{1,2})(?::(\d{1,2}))?\]/', '', $line );
		$line = preg_replace( '/\[speaker:\d+\]/i', '', $line );
		return trim( (string) $line );
	}

	private static function cut_at_sentence_end( string $buf ): array {
		// Look back from MAX_CHARS to find a sentence terminator. If none, hard cut.
		$len   = strlen( $buf );
		$slack = min( 200, $len - self::MIN_CHARS );
		for ( $i = self::MAX_CHARS; $i > self::MAX_CHARS - $slack; $i-- ) {
			$ch = $buf[ $i - 1 ] ?? '';
			if ( in_array( $ch, [ '.', '?', '!', '。', '！', '？' ], true ) ) {
				return [
					'head' => substr( $buf, 0, $i ),
					'tail' => substr( $buf, $i ),
				];
			}
		}
		return [
			'head' => substr( $buf, 0, self::MAX_CHARS ),
			'tail' => substr( $buf, self::MAX_CHARS ),
		];
	}

	private static function finalize_segment( string $text, array $cur ): array {
		return [
			'text'     => trim( $text ),
			'start_ts' => (int) $cur['start_ts'],
			'end_ts'   => (int) $cur['last_ts'],
			'speaker'  => $cur['speaker'],
			'is_scene' => (bool) $cur['is_scene'],
			'meta'     => [
				'chunker' => 'av_temporal_v1',
			],
		];
	}
}
