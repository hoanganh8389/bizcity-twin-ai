<?php
/**
 * Diagnostics Table Inspector — physical-state snapshot for registered tables.
 *
 * Uses `information_schema.TABLES` ONCE per request (single query, no per-table
 * SHOW TABLES) and falls back to SHOW TABLES LIKE when the schema view is
 * filtered out. All numbers are best-effort (MySQL row counts on InnoDB are
 * approximate — that's fine for an admin dashboard).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics
 * @since      2026-05-20
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

final class BizCity_Diagnostics_Table_Inspector {

	/** @var array<string,array>|null per-request memo */
	private static $schema_cache = null;

	/**
	 * Snapshot every registered table on the current blog/shard.
	 *
	 * @return array<int,array>  Each entry adds keys: exists, rows, size_bytes, size_human, error.
	 */
	public static function inspect_all(): array {
		$registry = BizCity_Diagnostics_Table_Registry::get_tables();
		$schema   = self::load_schema_snapshot();
		$activity = class_exists( 'BizCity_Diagnostics_Table_Activity' )
			? BizCity_Diagnostics_Table_Activity::snapshot()
			: [];
		$deprecated = [];
		foreach ( BizCity_Diagnostics_Table_Registry::deprecated_tables() as $entry ) {
			if ( empty( $entry['raw'] ) ) {
				$deprecated[ (string) $entry['name'] ] = (string) ( $entry['reason'] ?? '' );
			}
		}

		global $wpdb;
		$prefix = $wpdb->prefix;
		$out    = [];

		foreach ( $registry as $row ) {
			$physical = ! empty( $row['raw'] )
				? $row['name']                 // already includes its own prefix
				: $prefix . $row['name'];

			$info = $schema[ strtolower( $physical ) ] ?? null;
			$telemetry = isset( $activity[ $row['name'] ] ) && is_array( $activity[ $row['name'] ] )
				? $activity[ $row['name'] ]
				: [];
			$last_read  = (int) ( $telemetry['last_read']  ?? 0 );
			$last_write = (int) ( $telemetry['last_write'] ?? 0 );
			$last_used  = max( $last_read, $last_write );
			$total_ops  = (int) ( $telemetry['reads'] ?? 0 ) + (int) ( $telemetry['writes'] ?? 0 );
			if ( ! $info ) {
				$activity_status = 'missing';
			} elseif ( isset( $deprecated[ $row['name'] ] ) ) {
				$activity_status = 'orphan';
			} elseif ( $last_used > 0 && ( time() - $last_used ) <= 30 * DAY_IN_SECONDS ) {
				$activity_status = 'active';
			} elseif ( $last_used > 0 ) {
				$activity_status = 'dormant';
			} elseif ( (int) ( $info['rows'] ?? 0 ) > 0 ) {
				$activity_status = 'unobserved_data';
			} else {
				$activity_status = 'unobserved_empty';
			}
			$stability = 'unverified';
			if ( 'active' === $activity_status ) {
				$stability = $total_ops >= 3 ? 'stable' : 'recent';
			} elseif ( 'dormant' === $activity_status ) {
				$stability = 'stale';
			} elseif ( 'orphan' === $activity_status ) {
				$stability = 'retired';
			}

			$out[] = $row + [
				'physical'   => $physical,
				'exists'     => (bool) $info,
				'rows'       => $info ? (int) $info['rows']      : 0,
				'size_bytes' => $info ? (int) $info['size']      : 0,
				'size_human' => $info ? self::human( (int) $info['size'] ) : '—',
				'engine'     => $info['engine']   ?? '',
				'collation'  => $info['collation']?? '',
				'activity_status' => $activity_status,
				'activity_stability' => $stability,
				'activity_reason' => $deprecated[ $row['name'] ] ?? '',
				'last_used_at'    => $last_used,
				'last_read_at'    => $last_read,
				'last_write_at'   => $last_write,
				'last_used_at_iso'  => $last_used  ? gmdate( 'c', $last_used )  : '',
				'last_read_at_iso'  => $last_read  ? gmdate( 'c', $last_read )  : '',
				'last_write_at_iso' => $last_write ? gmdate( 'c', $last_write ) : '',
				'read_count'      => (int) ( $telemetry['reads']  ?? 0 ),
				'write_count'     => (int) ( $telemetry['writes'] ?? 0 ),
				'last_context'    => (string) ( $telemetry['last_context'] ?? '' ),
				'last_source'     => (string) ( $telemetry['last_source']  ?? '' ),
				'activity_first_seen' => (int) ( $telemetry['first_seen'] ?? 0 ),
			];
		}
		return $out;
	}

	/**
	 * Lightweight summary: counts by group (present / missing / critical-missing).
	 */
	public static function summary(): array {
		$rows = self::inspect_all();
		$sum  = [
			'total' => 0, 'present' => 0, 'missing' => 0, 'critical_missing' => 0,
			'rows_total' => 0, 'size_total' => 0, 'by_group' => [],
			'active' => 0, 'stable' => 0, 'dormant' => 0, 'orphan' => 0,
			'unobserved' => 0, 'unobserved_data' => 0,
		];

		foreach ( $rows as $r ) {
			$sum['total']++;
			$sum['rows_total'] += $r['rows'];
			$sum['size_total'] += $r['size_bytes'];
			$status = (string) ( $r['activity_status'] ?? 'unobserved_empty' );
			if ( 'stable' === (string) ( $r['activity_stability'] ?? '' ) ) {
				$sum['stable']++;
			}
			if ( in_array( $status, [ 'active', 'dormant', 'orphan' ], true ) ) {
				$sum[ $status ]++;
			} elseif ( strpos( $status, 'unobserved_' ) === 0 ) {
				$sum['unobserved']++;
				if ( 'unobserved_data' === $status ) {
					$sum['unobserved_data']++;
				}
			}

			$g = $r['group'];
			if ( ! isset( $sum['by_group'][ $g ] ) ) {
				$sum['by_group'][ $g ] = [ 'total' => 0, 'present' => 0, 'missing' => 0 ];
			}
			$sum['by_group'][ $g ]['total']++;

			if ( $r['exists'] ) {
				$sum['present']++;
				$sum['by_group'][ $g ]['present']++;
			} else {
				$sum['missing']++;
				$sum['by_group'][ $g ]['missing']++;
				if ( $r['critical'] ) {
					$sum['critical_missing']++;
				}
			}
		}

		global $wpdb;
		foreach ( BizCity_Diagnostics_Table_Registry::deprecated_tables() as $entry ) {
			$physical = ! empty( $entry['raw'] )
				? (string) $entry['name']
				: ( ( ( $entry['prefix_scope'] ?? 'blog' ) === 'base' ) ? $wpdb->base_prefix : $wpdb->prefix ) . $entry['name'];
			if ( isset( self::load_schema_snapshot()[ strtolower( $physical ) ] ) ) {
				$sum['orphan']++;
			}
		}
		return $sum;
	}

	/**
	 * Pull TABLE_NAME / TABLE_ROWS / DATA_LENGTH+INDEX_LENGTH for every
	 * bizcity-related table in the current shard's database. Keyed by
	 * lowercase TABLE_NAME so PHP-side lookup is O(1).
	 */
	private static function load_schema_snapshot(): array {
		if ( null !== self::$schema_cache ) {
			return self::$schema_cache;
		}

		global $wpdb;
		$pattern = $wpdb->esc_like( $wpdb->prefix ) . '%';

		// Suppress router noise — some shards reject information_schema.
		$prev = $wpdb->suppress_errors( true );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT TABLE_NAME, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH, ENGINE, TABLE_COLLATION
			 FROM information_schema.TABLES
			 WHERE TABLE_SCHEMA = DATABASE()
			   AND TABLE_NAME LIKE %s",
			$pattern
		), ARRAY_A );
		$wpdb->suppress_errors( $prev );

		$map = [];
		if ( is_array( $rows ) ) {
			foreach ( $rows as $r ) {
				$map[ strtolower( $r['TABLE_NAME'] ) ] = [
					'rows'      => (int) $r['TABLE_ROWS'],
					'size'      => (int) $r['DATA_LENGTH'] + (int) $r['INDEX_LENGTH'],
					'engine'    => (string) $r['ENGINE'],
					'collation' => (string) $r['TABLE_COLLATION'],
				];
			}
		}
		return self::$schema_cache = $map;
	}

	/** Human-readable size. */
	private static function human( int $bytes ): string {
		if ( $bytes < 1024 ) {
			return $bytes . ' B';
		}
		$units = [ 'KB', 'MB', 'GB', 'TB' ];
		$i     = -1;
		$n     = $bytes;
		do {
			$n /= 1024;
			$i++;
		} while ( $n >= 1024 && $i < count( $units ) - 1 );
		return sprintf( '%.2f %s', $n, $units[ $i ] );
	}
}
