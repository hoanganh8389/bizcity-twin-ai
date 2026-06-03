<?php
/**
 * Bizcity Twin AI — KG_Identity_Extractor
 *
 * PURE FUNCTION (no DB, no LLM by default) that extracts structured
 * identifiers (SKU, order code, customer code, version, …) from a piece
 * of text — both the user QUERY and each retrieved passage CONTENT.
 *
 * Transparency-first design (PHASE-0.3-KGHUB-case-identity-algorithm.md §4.9):
 *   Output is consumed by `class-tool-search-kg.php` to build an
 *   `identity_report` and tag each passage with ✅/⚠️/❓, so the LLM
 *   can NEVER mix prices/values across different SKUs without an
 *   explicit "Lưu ý — mã KHÁC …:" disclaimer.
 *
 * Output shape (per call):
 *   [
 *     [
 *       'id_kind'       => 'sku',
 *       'canonical_id'  => 'FS 369I',
 *       'evidence_span' => 'FS 369I',
 *       'offsets'       => [12, 47, 102],   // byte positions in $text
 *       'occurrences'   => 3,
 *       'score'         => 1.0,
 *       'match_reason'  => 'exact_regex',   // exact_regex | exact_llm | alias | fuzzy | context_only | fallback
 *     ],
 *     ...
 *   ]
 *
 * Patterns are configurable per-blog via the `bizcity_kg_identity_patterns`
 * filter (default templates below cover the 11 id_kind documented in §4.5).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge\KG_Hub
 * @author     Johnny Chu (Chu Hoàng Anh)
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @since      2026-05-08  Phase 0.3 — Identity Algorithm (transparency-first)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_KG_Identity_Extractor {

	/**
	 * Default regex bank per id_kind. Each pattern MUST be a valid PCRE
	 * pattern with delimiters; capture group 0 is taken as the raw match.
	 *
	 * Order matters: more specific patterns first (sku-FS, order-DH, …)
	 * so a broad fallback like generic alphanumeric does not absorb
	 * higher-precision matches.
	 *
	 * NOTE — case sensitivity: regexes are NOT marked /i for `sku`,
	 * `order`, `invoice`, `contract`, `customer`, `employee`, `campaign`
	 * because case is part of the canonical_id (FS 369I ≠ fs 369i).
	 * They ARE marked /i for `version`, `endpoint`, `tx`.
	 *
	 * @return array<string, array{regex: string[], canonicalize: callable, score: float}>
	 */
	public static function default_patterns() {
		return [
			'sku' => [
				// Vietnamese SKU shapes. Examples:
				//   FS 369I, FS-369I, 836G, 836GS, ABC1234, KX-12A
				// Heuristic: 1-4 letters + optional separator
				// + 2-5 digits + optional 0-3 trailing letters.
				// Anchored on word boundaries to avoid swallowing words.
				//
				// 2026-05-14 — case-insensitive variant added to capture
				// passage content where SKU was written lowercase / no-space
				// (e.g. "fs369i", "fs 369i"). Dedupe by canonical_id (see
				// canon_sku → uppercases + normalizes space) collapses
				// overlap with the strict uppercase patterns below.
				'regex' => [
					'/\b[A-Z]{2,4}[\s\-]?\d{2,5}[A-Z]{0,3}\b/u',     // FS 369I, ABC-1234 (strict)
					'/\b\d{3,5}[A-Z]{1,3}\b/u',                       // 836G, 836GS (strict)
					'/\b[A-Za-z]{2,4}[\s\-]?\d{2,5}[A-Za-z]{0,3}\b/u', // fs369i, FS-369I, fs 369i (loose)
					'/\b\d{3,5}[A-Za-z]{1,3}\b/u',                    // 836g, 836gs (loose)
				],
				'canonicalize' => [ __CLASS__, 'canon_sku' ],
				'score'        => 1.0,
			],
			'order' => [
				'regex' => [ '/\b(?:DH|ORD|PO)[\-\/]\d{2,4}[\-\/]\d{3,6}\b/u' ],
				'canonicalize' => [ __CLASS__, 'canon_upper_dash' ],
				'score'        => 1.0,
			],
			'invoice' => [
				'regex' => [ '/\b(?:HD|INV)[\-\/]\d{2,4}[\-\/]\d{3,6}\b/u' ],
				'canonicalize' => [ __CLASS__, 'canon_upper_dash' ],
				'score'        => 1.0,
			],
			'contract' => [
				// Includes Vietnamese "HĐ" prefix.
				'regex' => [ '/\b(?:H[ĐD]|CT)[\-\/]\d{2,4}[\-\/]\d{3,6}\b/u' ],
				'canonicalize' => [ __CLASS__, 'canon_upper_dash' ],
				'score'        => 1.0,
			],
			'customer' => [
				'regex' => [ '/\bKH[\-]?\d{4,8}\b/u', '/\bCTV[\-]?\d{2,6}\b/u' ],
				'canonicalize' => [ __CLASS__, 'canon_upper_dash' ],
				'score'        => 1.0,
			],
			'employee' => [
				'regex' => [ '/\bNV[\-]?\d{3,6}\b/u' ],
				'canonicalize' => [ __CLASS__, 'canon_upper_dash' ],
				'score'        => 1.0,
			],
			'version' => [
				// v1.2.3, V1.23 — preserve dot structure (NOT collapsed).
				'regex' => [ '/\bv\d+(?:\.\d+){1,3}\b/iu' ],
				'canonicalize' => [ __CLASS__, 'canon_version' ],
				'score'        => 0.85,
			],
			'endpoint' => [
				// /v1/users/42, /v2/orders/abc-1
				'regex' => [ '/(?<![A-Za-z0-9])\/v\d+\/[a-z0-9_\/\-]+/iu' ],
				'canonicalize' => [ __CLASS__, 'canon_endpoint' ],
				'score'        => 0.9,
			],
			'campaign' => [
				'regex' => [ '/\bCMP[\-][A-Z0-9][A-Z0-9\-]{1,30}\b/u' ],
				'canonicalize' => [ __CLASS__, 'canon_upper_dash' ],
				'score'        => 1.0,
			],
			'location' => [
				// Vietnamese chi-nhánh codes: CN-HN-01, CN-HCM-001
				'regex' => [ '/\bCN[\-][A-Z]{2,4}[\-]\d{1,4}\b/u' ],
				'canonicalize' => [ __CLASS__, 'canon_upper_dash' ],
				'score'        => 1.0,
			],
			'tx' => [
				'regex' => [ '/\b0x[a-fA-F0-9]{6,}\b/u' ],
				'canonicalize' => [ __CLASS__, 'canon_lower' ],
				'score'        => 0.9,
			],
		];
	}

	/**
	 * Extract identifiers from a piece of text.
	 *
	 * @param string $text
	 * @param array  $opts {
	 *     @type string[] $kinds              Limit to these id_kinds (default: all).
	 *     @type bool     $allow_overlapping  If false, dedupe by canonical_id (default: false).
	 *     @type int      $max_per_kind       Cap per kind (default: 16).
	 * }
	 * @return array Identity records (see file header for shape).
	 */
	public static function extract( $text, array $opts = [] ) {
		$text = (string) $text;
		if ( $text === '' ) {
			return [];
		}

		$opts = array_merge( [
			'kinds'             => [],
			'allow_overlapping' => false,
			'max_per_kind'      => 16,
		], $opts );

		/**
		 * Filter the identity-extractor pattern bank. Lets a tenant override
		 * defaults or add custom id_kinds via mu-plugin.
		 *
		 * @param array $patterns Default pattern bank — see default_patterns().
		 */
		$bank = (array) apply_filters( 'bizcity_kg_identity_patterns', self::default_patterns() );

		$out = [];
		$seen_global = [];   // dedupe key = "id_kind::canonical_id"

		foreach ( $bank as $kind => $cfg ) {
			if ( ! empty( $opts['kinds'] ) && ! in_array( $kind, (array) $opts['kinds'], true ) ) {
				continue;
			}
			$regexes = isset( $cfg['regex'] ) ? (array) $cfg['regex'] : [];
			$canon   = isset( $cfg['canonicalize'] ) && is_callable( $cfg['canonicalize'] ) ? $cfg['canonicalize'] : null;
			$score   = isset( $cfg['score'] ) ? (float) $cfg['score'] : 0.9;

			$buckets = []; // canonical_id => record
			$count   = 0;

			foreach ( $regexes as $regex ) {
				if ( $count >= (int) $opts['max_per_kind'] ) break;
				$matches = [];
				$ok = @preg_match_all( $regex, $text, $matches, PREG_OFFSET_CAPTURE );
				if ( ! $ok ) continue;
				foreach ( $matches[0] as $m ) {
					if ( $count >= (int) $opts['max_per_kind'] ) break;
					$raw    = (string) $m[0];
					$offset = (int) $m[1];
					$cid    = $canon ? call_user_func( $canon, $raw ) : trim( $raw );
					if ( $cid === '' ) continue;
					$key = $kind . '::' . $cid;
					if ( ! $opts['allow_overlapping'] && isset( $seen_global[ $key ] ) ) {
						// Already captured — just add offset to existing record.
						$out[ $seen_global[ $key ] ]['offsets'][]   = $offset;
						$out[ $seen_global[ $key ] ]['occurrences'] = count( $out[ $seen_global[ $key ] ]['offsets'] );
						continue;
					}
					if ( ! isset( $buckets[ $cid ] ) ) {
						$buckets[ $cid ] = [
							'id_kind'       => $kind,
							'canonical_id'  => $cid,
							'evidence_span' => $raw,
							'offsets'       => [ $offset ],
							'occurrences'   => 1,
							'score'         => $score,
							'match_reason'  => 'exact_regex',
						];
					} else {
						$buckets[ $cid ]['offsets'][] = $offset;
						$buckets[ $cid ]['occurrences']++;
					}
					$count++;
				}
			}

			foreach ( $buckets as $cid => $rec ) {
				$idx                = count( $out );
				$out[]              = $rec;
				$seen_global[ $kind . '::' . $cid ] = $idx;
			}
		}

		// Sort by score desc, then occurrences desc (most prominent first).
		usort( $out, static function ( $a, $b ) {
			if ( $a['score'] === $b['score'] ) {
				return $b['occurrences'] - $a['occurrences'];
			}
			return ( $a['score'] < $b['score'] ) ? 1 : -1;
		} );

		return $out;
	}

	/**
	 * Pick the highest-confidence identity from a list (≥ threshold).
	 * Returns null if none qualifies — chunk is "không có chủ".
	 *
	 * @param array $identities
	 * @param float $threshold  default 0.6
	 * @return array|null
	 */
	public static function primary( array $identities, $threshold = 0.6 ) {
		foreach ( $identities as $rec ) {
			if ( (float) $rec['score'] >= (float) $threshold ) {
				return $rec;
			}
		}
		return null;
	}

	/**
	 * Build a stable label like `sku::FS 369I` for use as map key / display.
	 */
	public static function label( $id_kind, $canonical_id ) {
		return (string) $id_kind . '::' . (string) $canonical_id;
	}

	/**
	 * Cheap "are these two identities related?" heuristic. Used by the
	 * tool wrapper to mark passages as ⚠️ MÃ KHÁC (related) vs unrelated.
	 *
	 * Same id_kind AND ( same prefix ≥ 2 chars  OR  Levenshtein ≤ 2 ).
	 */
	public static function are_related( array $a, array $b ) {
		if ( ( $a['id_kind'] ?? '' ) !== ( $b['id_kind'] ?? '' ) ) return false;
		$x = (string) ( $a['canonical_id'] ?? '' );
		$y = (string) ( $b['canonical_id'] ?? '' );
		if ( $x === '' || $y === '' || $x === $y ) return false;

		$xn = preg_replace( '/[^a-z0-9]/i', '', $x );
		$yn = preg_replace( '/[^a-z0-9]/i', '', $y );

		// Token overlap on letters (e.g. "369i" inside "FS 369I").
		$xd = preg_replace( '/[^0-9]/', '', $xn );
		$yd = preg_replace( '/[^0-9]/', '', $yn );
		if ( $xd !== '' && $yd !== '' && ( strpos( $xd, $yd ) !== false || strpos( $yd, $xd ) !== false ) ) {
			return true;
		}

		// Same prefix ≥ 2 chars.
		$len = min( strlen( $xn ), strlen( $yn ) );
		if ( $len >= 2 ) {
			$pref = 0;
			for ( $i = 0; $i < $len; $i++ ) {
				if ( strtolower( $xn[ $i ] ) === strtolower( $yn[ $i ] ) ) $pref++;
				else break;
			}
			if ( $pref >= 2 ) return true;
		}

		// Levenshtein on short ids (fast PHP built-in caps at 255).
		if ( strlen( $xn ) <= 64 && strlen( $yn ) <= 64 ) {
			$lev = @levenshtein( strtolower( $xn ), strtolower( $yn ) );
			if ( $lev !== -1 && $lev <= 2 ) return true;
		}
		return false;
	}

	// ─── Canonicalizers ────────────────────────────────────────────────

	/**
	 * SKU canonicalization.
	 *
	 * 2026-05-14 — UPGRADED to UPPERCASE + normalized-separator so that
	 * lowercase / no-space variants in passage content ("fs369i",
	 * "fs 369i", "FS-369I") collapse to the same canonical_id as the
	 * uppercase form in the user query ("FS 369I"). Without this, the
	 * identity-overlay tagging in class-twin-context-resolver.php
	 * would mark every passage as ❓ unidentified and trip the
	 * forced-refusal branch even when the SKU is clearly present.
	 *
	 * Rules:
	 *   - Uppercase (UTF-8 safe).
	 *   - Collapse runs of [space, '-', en-dash, em-dash] → single space.
	 *   - If shape is LETTERS+DIGITS+TAIL with NO separator, insert one
	 *     space between the leading letter-block and the digit-block.
	 *
	 * Examples:
	 *   "FS-369I"  → "FS 369I"
	 *   "fs369i"   → "FS 369I"
	 *   "fs  369i" → "FS 369I"
	 *   "836GS"    → "836GS"   (digit-first, untouched)
	 */
	public static function canon_sku( $raw ) {
		$s = trim( (string) $raw );
		$s = function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $s, 'UTF-8' ) : strtoupper( $s );
		$s = preg_replace( '/[\s\-\x{2013}\x{2014}]+/u', ' ', $s );
		// Insert space between leading letter-block (2-4) and digit-block.
		$s = preg_replace( '/^([A-Z]{2,4})(\d)/u', '$1 $2', $s );
		return $s;
	}

	public static function canon_upper_dash( $raw ) {
		$s = strtoupper( trim( (string) $raw ) );
		// Normalize "/" and en-dash to "-".
		$s = preg_replace( '/[\/–—]/u', '-', $s );
		$s = preg_replace( '/\s+/u', '', $s );
		return $s;
	}

	public static function canon_lower( $raw ) {
		return strtolower( trim( (string) $raw ) );
	}

	/**
	 * Version: lower the leading 'v', drop trailing ".0" once, but DO NOT
	 * collapse "v1.23" with "v1.2.3" — they remain distinct.
	 */
	public static function canon_version( $raw ) {
		$s = strtolower( trim( (string) $raw ) );
		$s = preg_replace( '/^v/', 'v', $s );
		// Drop one trailing ".0" only.
		$s = preg_replace( '/\.0$/', '', $s );
		return $s;
	}

	public static function canon_endpoint( $raw ) {
		$s = strtolower( trim( (string) $raw ) );
		$s = rtrim( $s, '/' );
		return $s;
	}
}
