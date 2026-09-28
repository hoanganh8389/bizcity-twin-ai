<?php
/**
 * BizCity Zalo Personal — notebook version for the zalo-hub notebook pack (PHASE-0.81 C1.3, contract C-4/C-5).
 *
 * `version` = 'v-' + 8 hex of sha1( passage count | max(id) | summed length ). It moves when a passage is added, removed,
 * or its length changes, so the cell (config bundle `agents[].knowledge.versions`, C-4 list) knows when to pull chunks
 * again. Cached 60 s per notebook; BizCity_Zalo_Hub_Guru_Invalidate flushes it when kg-hub reports a change.
 *
 * [2026-09-28 Claude Sonnet 5] PHASE-0.81 S-2 (peer review, confirmed) — deliberately NOT `max(updated_at)`:
 * `bizcity_kg_passages.updated_at` is `ON UPDATE CURRENT_TIMESTAMP`, and the triplet extractor
 * (class-kg-triplet-extractor.php) writes `extraction_status` on every passage with no content change. Keying the
 * version on `updated_at` would move it on every extraction tick, and because the C-4 chunk route always answers
 * `full:true`, the cell would re-pull the whole notebook every 5-minute tick — a 20k-passage notebook is ~100 pages
 * against the Hub's 120 req/min/key relay limit. `updated_at` is still returned for the C-4 list's display field, just
 * not fed into the fingerprint.
 *
 * @package BizCity_Zalo_Personal
 * @since   PHASE-0.81 (2026-09-27)
 */

// [2026-09-27 Claude Opus 5.5] PHASE-0.81 C1.3.
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Zalo_Guru_Knowledge_Version', false ) ) {
	return;
}

final class BizCity_Zalo_Guru_Knowledge_Version {

	const CACHE_PREFIX = 'bizcity_zh_nbv_';
	const CACHE_TTL    = 60;

	/**
	 * Test seam: `stats(notebook_id)` => {count:int, bytes:int, updated_at:string, max_id:int} | null (notebook missing).
	 *
	 * @var array<string,callable>
	 */
	public static $readers = array();

	public static function for_notebook( int $notebook_id ): string {
		return (string) self::stats( $notebook_id )['version'];
	}

	/**
	 * @return array{version:string,count:int,bytes:int,updated_at:string} `updated_at` ISO-8601 UTC ('' when empty).
	 */
	public static function stats( int $notebook_id ): array {
		$key    = self::CACHE_PREFIX . $notebook_id;
		$cached = function_exists( 'get_transient' ) ? get_transient( $key ) : false;
		if ( is_array( $cached ) && isset( $cached['version'] ) ) {
			return $cached;
		}
		$raw   = self::read( $notebook_id );
		$max_at = (string) ( $raw['updated_at'] ?? '' );
		$count  = (int) ( $raw['count'] ?? 0 );
		$bytes  = (int) ( $raw['bytes'] ?? 0 );
		$max_id = (int) ( $raw['max_id'] ?? 0 );
		$out = array(
			// content-only fingerprint (S-2): count + max(id) catches insert/delete, bytes catches an edited length.
			'version'    => 'v-' . substr( sha1( $count . '|' . $max_id . '|' . $bytes ), 0, 8 ),
			'count'      => $count,
			'bytes'      => $bytes,
			'updated_at' => '' === $max_at ? '' : gmdate( 'Y-m-d\TH:i:s\Z', (int) strtotime( $max_at . ' UTC' ) ),
		);
		if ( function_exists( 'set_transient' ) ) {
			set_transient( $key, $out, self::CACHE_TTL );
		}
		return $out;
	}

	public static function flush( int $notebook_id ): void {
		if ( function_exists( 'delete_transient' ) ) {
			delete_transient( self::CACHE_PREFIX . $notebook_id );
		}
	}

	private static function read( int $notebook_id ): array {
		if ( isset( self::$readers['stats'] ) ) {
			return (array) call_user_func( self::$readers['stats'], $notebook_id );
		}
		if ( $notebook_id <= 0 || ! class_exists( 'BizCity_KG_Database' ) ) {
			return array();
		}
		global $wpdb;
		$tbl = BizCity_KG_Database::instance()->tbl_passages();
		// Passages moved to the filestore keep an empty `content` and their length in `file_length` (storage_ver 2).
		$len = function_exists( 'bizcity_columns_exist' ) && bizcity_columns_exist( $tbl, array( 'file_length' ) )
			? 'LENGTH(content) + COALESCE(file_length, 0)'
			: 'LENGTH(content)';
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS n, MAX(id) AS max_id, MAX(updated_at) AS max_at, COALESCE(SUM({$len}), 0) AS bytes FROM {$tbl} WHERE notebook_id = %d", $notebook_id ), ARRAY_A );
		return is_array( $row ) ? array( 'count' => (int) $row['n'], 'max_id' => (int) $row['max_id'], 'bytes' => (int) $row['bytes'], 'updated_at' => (string) ( $row['max_at'] ?? '' ) ) : array();
	}
}
