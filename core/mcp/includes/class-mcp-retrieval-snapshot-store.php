<?php
/**
 * BizCity_MCP_Retrieval_Snapshot_Store — deterministic cache-key + stable
 * sort + persistence for `brain.search` result snapshots.
 *
 * This is the hardest piece of the MCP contract: two identical requests
 * MUST reuse the same immutable snapshot (source doc §7.4/§7.5) unless the
 * underlying KG content actually changed, and passage ordering inside a
 * snapshot MUST be reproducible (score DESC, then source_id/passage_id ASC
 * tie-break) so citation_id lists are stable across repeated calls.
 *
 * `compute_kg_revision()` is a cheap content fingerprint (COUNT + MAX(updated_at)
 * across passages/entities/relations scoped to the notebook set) — it is NOT
 * a new retrieval pipeline, only a change-detector used to decide snapshot
 * reuse vs refresh. All actual retrieval still goes through
 * BizCity_KG_Retriever::ask() (see class-brain-mcp-service.php).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\MCP
 * @since      2026-07-27 (PHASE-0.53-MCP Wave A)
 */

defined( 'ABSPATH' ) || exit;

// [2026-07-27 Johnny Chu] PHASE-0.53-MCP — new file, deterministic snapshot cache.
final class BizCity_MCP_Retrieval_Snapshot_Store {

	const PROFILE_DEFAULT = 'kg-rag-strict-v1';
	const PROFILE_VERSION = '1.0.0';

	public static function tbl() {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_mcp_retrieval_snapshots';
	}

	/**
	 * Normalize a raw query string for hashing/cache-key purposes: trim,
	 * collapse whitespace, Unicode NFC-normalize when the intl extension is
	 * available (degrades gracefully to raw string otherwise), lowercase.
	 *
	 * @return array { original:string, normalized:string, hash:string }
	 */
	public static function normalize_query( $query ) {
		$q = trim( (string) $query );
		$q = preg_replace( '/\s+/u', ' ', $q );
		if ( $q === null ) {
			$q = trim( (string) $query ); // preg_replace can return null on invalid UTF-8; fall back to raw trim.
		}
		if ( class_exists( 'Normalizer' ) ) {
			$nfc = Normalizer::normalize( $q, Normalizer::FORM_C );
			if ( $nfc !== false ) {
				$q = $nfc;
			}
		}
		$lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $q, 'UTF-8' ) : strtolower( $q );
		return array(
			'original'   => $q,
			'normalized' => $lower,
			'hash'       => 'sha256:' . hash( 'sha256', $lower ),
		);
	}

	/**
	 * Deterministic sha256 cache key. Notebook IDs are sorted numerically
	 * before hashing so {2,1} and {1,2} produce the same key.
	 *
	 * @return string 64-char hex sha256.
	 */
	public static function build_cache_key( $client_id, $normalized_query, array $notebook_ids, $profile_version, $kg_revision, $top_k, $graph_depth ) {
		$sorted = array_map( 'intval', $notebook_ids );
		sort( $sorted, SORT_NUMERIC );
		$raw = implode( '|', array(
			(string) $client_id,
			(string) $normalized_query,
			implode( ',', $sorted ),
			(string) $profile_version,
			(string) $kg_revision,
			(string) (int) $top_k,
			(string) (int) $graph_depth,
		) );
		return hash( 'sha256', $raw );
	}

	/**
	 * Cheap KG content fingerprint scoped to a notebook set. Used only to
	 * decide whether a cached snapshot is still fresh — NOT a retrieval
	 * query. Returns a short opaque string, safe to store in cache_key
	 * composition and in the snapshot row itself.
	 */
	public static function compute_kg_revision( array $notebook_ids ) {
		$ids = array_values( array_unique( array_map( 'intval', $notebook_ids ) ) );
		if ( empty( $ids ) || ! class_exists( 'BizCity_KG_Database' ) ) {
			return 'kgrev_empty';
		}

		global $wpdb;
		$db      = BizCity_KG_Database::instance();
		$ids_csv = implode( ',', $ids );

		// [2026-07-27 Johnny Chu] PHASE-0.53-MCP — fingerprint only, reuses existing
		// tables via BizCity_KG_Database table helpers; no new schema, no new retrieval.
		$row = $wpdb->get_row(
			"SELECT
				(SELECT COUNT(*) FROM {$db->tbl_passages()}  WHERE notebook_id IN ({$ids_csv})) AS pc,
				(SELECT COUNT(*) FROM {$db->tbl_entities()}  WHERE notebook_id IN ({$ids_csv})) AS ec,
				(SELECT COUNT(*) FROM {$db->tbl_relations()} WHERE notebook_id IN ({$ids_csv})) AS rc,
				(SELECT MAX(updated_at) FROM {$db->tbl_passages()}  WHERE notebook_id IN ({$ids_csv})) AS pu,
				(SELECT MAX(updated_at) FROM {$db->tbl_entities()}  WHERE notebook_id IN ({$ids_csv})) AS eu,
				(SELECT MAX(updated_at) FROM {$db->tbl_relations()} WHERE notebook_id IN ({$ids_csv})) AS ru",
			ARRAY_A
		);
		$row = is_array( $row ) ? $row : array();

		$fingerprint = implode( '|', array(
			(string) ( isset( $row['pc'] ) ? $row['pc'] : 0 ),
			(string) ( isset( $row['ec'] ) ? $row['ec'] : 0 ),
			(string) ( isset( $row['rc'] ) ? $row['rc'] : 0 ),
			(string) ( isset( $row['pu'] ) ? $row['pu'] : '' ),
			(string) ( isset( $row['eu'] ) ? $row['eu'] : '' ),
			(string) ( isset( $row['ru'] ) ? $row['ru'] : '' ),
		) );

		return 'kgrev_' . substr( hash( 'sha256', $ids_csv . '|' . $fingerprint ), 0, 24 );
	}

	/**
	 * Stable sort: score DESC, then source_id ASC, then passage_id ASC.
	 * Reindexes to a 0-based array (array_values) so callers can safely
	 * assign `rank = index + 1` afterwards.
	 *
	 * @param array[] $passages Each item has score.final (float) / source_id / passage_id.
	 * @return array[]
	 */
	public static function stable_sort( array $passages ) {
		usort( $passages, static function ( $a, $b ) {
			$sa = isset( $a['score']['final'] ) ? (float) $a['score']['final'] : 0.0;
			$sb = isset( $b['score']['final'] ) ? (float) $b['score']['final'] : 0.0;
			if ( abs( $sa - $sb ) > 0.0000001 ) {
				return $sa > $sb ? -1 : 1; // score DESC
			}
			$sida = (int) ( isset( $a['source_id'] ) ? $a['source_id'] : 0 );
			$sidb = (int) ( isset( $b['source_id'] ) ? $b['source_id'] : 0 );
			if ( $sida !== $sidb ) {
				return ( $sida < $sidb ) ? -1 : 1; // source_id ASC
			}
			$pida = (int) ( isset( $a['passage_id'] ) ? $a['passage_id'] : 0 );
			$pidb = (int) ( isset( $b['passage_id'] ) ? $b['passage_id'] : 0 );
			if ( $pida === $pidb ) { return 0; }
			return ( $pida < $pidb ) ? -1 : 1; // passage_id ASC
		} );
		return array_values( $passages );
	}

	/**
	 * @return array|null Hydrated snapshot row, or null if no non-expired match.
	 */
	public static function find_reusable( $client_id, $cache_key ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM " . self::tbl() . " WHERE client_id = %s AND cache_key = %s AND expires_at > %s ORDER BY id DESC LIMIT 1",
				(string) $client_id,
				(string) $cache_key,
				current_time( 'mysql' )
			),
			ARRAY_A
		);
		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * @return string snapshot_uuid
	 */
	public static function save( array $snapshot ) {
		global $wpdb;
		$uuid = 'rs_' . str_replace( '-', '', wp_generate_uuid4() );
		$ttl  = isset( $snapshot['ttl_seconds'] ) ? max( 60, (int) $snapshot['ttl_seconds'] ) : 3600;

		$wpdb->insert(
			self::tbl(),
			array(
				'snapshot_uuid'    => $uuid,
				'user_id'          => (int) $snapshot['user_id'],
				'client_id'        => (string) $snapshot['client_id'],
				'cache_key'        => (string) $snapshot['cache_key'],
				'original_query'   => (string) $snapshot['original_query'],
				'normalized_query' => (string) $snapshot['normalized_query'],
				'scope_json'       => wp_json_encode( $snapshot['scope'] ),
				'profile_name'     => (string) $snapshot['profile_name'],
				'profile_version'  => (string) $snapshot['profile_version'],
				'kg_revision'      => (string) $snapshot['kg_revision'],
				'payload_json'     => wp_json_encode( $snapshot['payload'] ),
				'created_at'       => current_time( 'mysql' ),
				'expires_at'       => date( 'Y-m-d H:i:s', time() + $ttl ),
			)
		);
		return $uuid;
	}

	/**
	 * @return array|null
	 */
	public static function get( $snapshot_uuid ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM " . self::tbl() . " WHERE snapshot_uuid = %s LIMIT 1", (string) $snapshot_uuid ),
			ARRAY_A
		);
		return $row ? self::hydrate( $row ) : null;
	}

	private static function hydrate( array $row ) {
		$scope   = json_decode( (string) $row['scope_json'], true );
		$payload = json_decode( (string) $row['payload_json'], true );
		$row['scope']      = is_array( $scope ) ? $scope : array();
		$row['payload']    = is_array( $payload ) ? $payload : array();
		$row['is_expired'] = strtotime( (string) $row['expires_at'] ) < time();
		return $row;
	}
}
