<?php
/**
 * Bot Studio — @mention helpers (PHASE-0.60K K1).
 *
 * Pure functions, no WordPress calls. A tag is a `{pos,len,uid}` triple over the UTF-16 code units of the sent text
 * (Zalo/zca-js index JS strings): `mb_strlen()` would count "@😀" as 2 and shift every tag after an emoji, and the
 * sidecar rejects any `pos+len` past the text length measured in UTF-16.
 *
 * Trap ported from Libe-Zalo (2026-09-16): a model that already wrote "@Nam" and then gets a second "@Nam" prepended
 * sends "@Nam @Nam Dạ anh…" — so every leading @Name for a target is stripped BEFORE the one real tag is inserted.
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway\Bot
 */

// [2026-09-24 Claude Sonnet 5] PHASE-0.60K K1
defined( 'ABSPATH' ) || exit;

final class BizCity_Bot_Mentions {

	/** More than this in one reply reads as spam and Zalo may treat it so. */
	const MAX_TARGETS = 3;

	/** Length in UTF-16 code units (what the sidecar and Zalo measure). */
	public static function utf16_len( string $s ): int {
		if ( '' === $s ) {
			return 0;
		}
		if ( function_exists( 'mb_convert_encoding' ) ) {
			return (int) ( strlen( (string) mb_convert_encoding( $s, 'UTF-16LE', 'UTF-8' ) ) / 2 );
		}
		// No mbstring: count code points, +1 for each astral one (4-byte UTF-8 lead byte).
		return (int) preg_match_all( '/./us', $s ) + (int) preg_match_all( '/[\x{10000}-\x{10FFFF}]/u', $s );
	}

	/**
	 * Remove every leading "@Name" (repeated, with trailing space/comma/colon) for the given names.
	 * Linear per name — no nested quantifier over user text.
	 *
	 * @param string[] $names
	 */
	public static function strip_leading( string $text, array $names ): string {
		$text = ltrim( $text );
		$changed = true;
		while ( $changed && '' !== $text ) {
			$changed = false;
			foreach ( $names as $name ) {
				$name = trim( (string) $name );
				if ( '' === $name ) {
					continue;
				}
				$tag = '@' . $name;
				if ( 0 === strncasecmp( $text, $tag, strlen( $tag ) ) ) {
					$rest = substr( $text, strlen( $tag ) );
					// "@Nam" must end at a boundary: "@Nam" then "inh" is a different word, not our tag.
					if ( '' === $rest || preg_match( '/^[\s,:;.!?]/u', $rest ) ) {
						$text    = ltrim( $rest, " \t,:" );
						$changed = true;
					}
				}
			}
		}
		return $text;
	}

	/**
	 * Put one real tag per target at the head of the reply.
	 *
	 * @param array<int,array{uid:string,name:string}> $targets uid must be digits (3–32), name non-empty.
	 * @return array{text:string,mentions:array<int,array{pos:int,len:int,uid:string}>}
	 */
	public static function apply( string $reply, array $targets ): array {
		$clean = array();
		$seen  = array();
		foreach ( $targets as $t ) {
			$uid  = trim( (string) ( $t['uid'] ?? '' ) );
			$name = trim( preg_replace( '/\s+/u', ' ', (string) ( $t['name'] ?? '' ) ) );
			if ( ! preg_match( '/^\d{3,32}$/', $uid ) || '' === $name || isset( $seen[ $uid ] ) ) {
				continue;
			}
			$seen[ $uid ] = true;
			$clean[]      = array( 'uid' => $uid, 'name' => $name );
			if ( count( $clean ) >= self::MAX_TARGETS ) {
				break;
			}
		}
		if ( empty( $clean ) ) {
			return array( 'text' => $reply, 'mentions' => array() );
		}
		$body     = self::strip_leading( $reply, array_column( $clean, 'name' ) );
		$prefix   = '';
		$mentions = array();
		foreach ( $clean as $t ) {
			$tag        = '@' . $t['name'];
			$mentions[] = array( 'pos' => self::utf16_len( $prefix ), 'len' => self::utf16_len( $tag ), 'uid' => $t['uid'] );
			$prefix    .= $tag . ' ';
		}
		return array( 'text' => $prefix . $body, 'mentions' => $mentions );
	}
}
