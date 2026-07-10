<?php
/**
 * Bizcity Twin AI — TwinChat Chunker
 *
 * Sprint 4.8c + 4.8d (Nexus port) — Heading-aware markdown/text chunker +
 * chunk-level dedup (noise filter + Jaccard 5-gram word-shingle similarity).
 *
 * Pipeline:
 *   chunk()         → split text by markdown headings, slide window inside
 *                     long sections, attach heading_path metadata
 *   dedup_chunks()  → drop noise chunks (whitespace / numeric / too short)
 *                     and near-duplicates (Jaccard ≥ DEDUP_THRESHOLD)
 *
 * Standalone (no DB), pure PHP, multibyte-safe. Designed for PHP 7.4.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Modules\TwinChat
 * @since      2026-04-27
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_TwinChat_Chunker {

	const CHUNK_CHARS       = 1500;
	const CHUNK_OVERLAP     = 200;
	const MIN_CHUNK_CHARS   = 60;     // chunks shorter than this → noise candidate
	const SHINGLE_SIZE      = 5;      // word n-gram for Jaccard
	const DEDUP_THRESHOLD   = 0.95;
	const MAX_HEADING_DEPTH = 6;

	/**
	 * Split text into heading-aware chunks. Returns array of:
	 *   [ 'text' => string, 'heading_path' => string[], 'heading' => string ]
	 *
	 * @param string $text
	 * @param int    $chunk_chars   override default
	 * @param int    $chunk_overlap override default
	 * @return array<int,array{text:string,heading_path:array<int,string>,heading:string}>
	 */
	public static function chunk( string $text, int $chunk_chars = self::CHUNK_CHARS, int $chunk_overlap = self::CHUNK_OVERLAP ): array {
		$text = self::normalize( $text );
		if ( '' === $text ) {
			return [];
		}
		$sections = self::split_by_heading( $text );

		$out = [];
		foreach ( $sections as $sec ) {
			$body = trim( (string) $sec['body'] );
			if ( '' === $body ) {
				continue;
			}
			$pieces = self::slide_window( $body, $chunk_chars, $chunk_overlap );
			foreach ( $pieces as $piece ) {
				$out[] = [
					'text'         => $piece,
					'heading_path' => $sec['path'],
					'heading'      => empty( $sec['path'] ) ? '' : end( $sec['path'] ),
				];
			}
		}
		return $out;
	}

	/**
	 * Drop noise chunks + near-duplicates. Preserves order.
	 *
	 * @param array<int,array{text:string,heading_path?:array,heading?:string}> $chunks
	 * @return array{kept:array<int,array>,dropped:array{noise:int,duplicate:int}}
	 */
	public static function dedup_chunks( array $chunks ): array {
		$kept       = [];
		$kept_sets  = []; // parallel: shingle sets for kept chunks
		$noise      = 0;
		$duplicate  = 0;

		foreach ( $chunks as $ch ) {
			$text = isset( $ch['text'] ) ? (string) $ch['text'] : '';
			if ( self::is_noise( $text ) ) {
				$noise++;
				continue;
			}
			$shingles = self::shingles( $text, self::SHINGLE_SIZE );
			$is_dup   = false;
			foreach ( $kept_sets as $other ) {
				if ( self::jaccard( $shingles, $other ) >= self::DEDUP_THRESHOLD ) {
					$is_dup = true;
					break;
				}
			}
			if ( $is_dup ) {
				$duplicate++;
				continue;
			}
			$kept[]      = $ch;
			$kept_sets[] = $shingles;
		}

		return [
			'kept'    => $kept,
			'dropped' => [ 'noise' => $noise, 'duplicate' => $duplicate ],
		];
	}

	/* ──────────────────────  Internal helpers  ────────────────────── */

	private static function normalize( string $text ): string {
		// Strip BOM, normalize newlines, collapse 3+ blank lines.
		$text = preg_replace( "/\xEF\xBB\xBF/", '', $text );
		$text = str_replace( [ "\r\n", "\r" ], "\n", $text );
		$text = preg_replace( "/[ \t]+/", ' ', $text );
		$text = preg_replace( "/\n{3,}/", "\n\n", $text );
		return trim( (string) $text );
	}

	/**
	 * Parse markdown headings (# .. ######) into ordered sections with running
	 * heading_path stack. Plain text without headings → single root section.
	 *
	 * @return array<int,array{path:array<int,string>,body:string}>
	 */
	private static function split_by_heading( string $text ): array {
		$lines       = explode( "\n", $text );
		$sections    = [];
		$path        = [];
		$buffer      = [];

		$flush = function () use ( &$sections, &$path, &$buffer ) {
			if ( empty( $buffer ) ) {
				return;
			}
			$body = implode( "\n", $buffer );
			$buffer = [];
			if ( '' === trim( $body ) ) {
				return;
			}
			$sections[] = [ 'path' => $path, 'body' => $body ];
		};

		foreach ( $lines as $line ) {
			if ( preg_match( '/^(#{1,6})\s+(.+?)\s*#*$/u', $line, $m ) ) {
				$flush();
				$level = min( strlen( $m[1] ), self::MAX_HEADING_DEPTH );
				$title = trim( $m[2] );
				// Truncate the path to (level-1) and push.
				$path  = array_slice( $path, 0, $level - 1 );
				$path[] = $title;
				continue;
			}
			$buffer[] = $line;
		}
		$flush();

		if ( empty( $sections ) ) {
			$sections[] = [ 'path' => [], 'body' => $text ];
		}
		return $sections;
	}

	/**
	 * Multibyte sliding window. Tries to break at sentence boundary inside the
	 * last 25% of the window when possible; otherwise hard-cut.
	 *
	 * @return array<int,string>
	 */
	private static function slide_window( string $text, int $chunk_chars, int $overlap ): array {
		$len = mb_strlen( $text );
		if ( $len === 0 ) return [];
		if ( $chunk_chars < 200 ) $chunk_chars = 200;
		if ( $overlap < 0 || $overlap >= $chunk_chars ) $overlap = (int) ( $chunk_chars * 0.15 );
		if ( $len <= $chunk_chars ) return [ $text ];

		$out   = [];
		$start = 0;
		$step  = $chunk_chars - $overlap;
		while ( $start < $len ) {
			$piece = mb_substr( $text, $start, $chunk_chars );
			// Try to end at a sentence/paragraph boundary in the last 25%.
			if ( mb_strlen( $piece ) >= $chunk_chars ) {
				$tail_off = (int) ( $chunk_chars * 0.75 );
				$tail     = mb_substr( $piece, $tail_off );
				$best     = -1;
				foreach ( [ "\n\n", '. ', '。', '！', '？', '! ', '? ', "\n" ] as $needle ) {
					$pos = mb_strrpos( $tail, $needle );
					if ( false !== $pos && $pos > $best ) {
						$best = $pos + mb_strlen( $needle );
					}
				}
				if ( $best > 0 ) {
					$piece = mb_substr( $piece, 0, $tail_off + $best );
				}
			}
			$piece = trim( $piece );
			if ( '' !== $piece ) {
				$out[] = $piece;
			}
			$advance = max( 1, mb_strlen( $piece ) - $overlap );
			$start  += $advance;
		}
		return $out;
	}

	/** Heuristic: too short / mostly digits/punct / whitespace-only → noise. */
	private static function is_noise( string $text ): bool {
		$trim = trim( $text );
		if ( '' === $trim ) return true;
		if ( mb_strlen( $trim ) < self::MIN_CHUNK_CHARS ) return true;

		// Strip whitespace + punctuation; require at least 30% letters (any unicode letter).
		$letters_count = preg_match_all( '/\p{L}/u', $trim );
		$total         = mb_strlen( $trim );
		if ( $total > 0 && ( $letters_count / $total ) < 0.30 ) {
			return true;
		}
		// Common boilerplate page-number / footer patterns.
		if ( preg_match( '/^(page|trang|p\.)\s*\d+/iu', $trim ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Build a set of word n-gram shingles from text. Lowercased, accent-folded
	 * (best-effort for VN/EN), tokenised by unicode word chars.
	 *
	 * @return array<string,bool>  associative set
	 */
	private static function shingles( string $text, int $n ): array {
		$norm   = self::normalize_for_shingle( $text );
		$tokens = preg_split( '/[^\p{L}\p{N}]+/u', $norm, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $tokens ) || count( $tokens ) < $n ) {
			// Fallback: treat the entire normalized string as a single shingle.
			$key = trim( implode( ' ', (array) $tokens ) );
			return $key === '' ? [] : [ $key => true ];
		}
		$set = [];
		$cnt = count( $tokens ) - $n + 1;
		for ( $i = 0; $i < $cnt; $i++ ) {
			$gram = implode( ' ', array_slice( $tokens, $i, $n ) );
			$set[ $gram ] = true;
		}
		return $set;
	}

	private static function normalize_for_shingle( string $text ): string {
		$text = mb_strtolower( $text );
		// Accent-fold for ASCII (best effort; preserves CJK).
		if ( function_exists( 'iconv' ) ) {
			$ascii = @iconv( 'UTF-8', 'ASCII//TRANSLIT//IGNORE', $text );
			if ( false !== $ascii && '' !== $ascii ) {
				$text = $ascii;
			}
		}
		return $text;
	}

	private static function jaccard( array $a, array $b ): float {
		if ( empty( $a ) && empty( $b ) ) return 1.0;
		if ( empty( $a ) || empty( $b ) ) return 0.0;
		// Use array_intersect_key on associative sets for speed.
		$inter = count( array_intersect_key( $a, $b ) );
		$union = count( $a ) + count( $b ) - $inter;
		if ( $union <= 0 ) return 0.0;
		return $inter / $union;
	}
}
