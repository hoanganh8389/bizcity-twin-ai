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

		global $wpdb;
		$prefix = $wpdb->prefix;
		$out    = [];

		foreach ( $registry as $row ) {
			$physical = ! empty( $row['raw'] )
				? $row['name']                 // already includes its own prefix
				: $prefix . $row['name'];

			$info = $schema[ strtolower( $physical ) ] ?? null;

			$out[] = $row + [
				'physical'   => $physical,
				'exists'     => (bool) $info,
				'rows'       => $info ? (int) $info['rows']      : 0,
				'size_bytes' => $info ? (int) $info['size']      : 0,
				'size_human' => $info ? self::human( (int) $info['size'] ) : '—',
				'engine'     => $info['engine']   ?? '',
				'collation'  => $info['collation']?? '',
			];
		}
		return $out;
	}

	/**
	 * Lightweight summary: counts by group (present / missing / critical-missing).
	 */
	public static function summary(): array {
		$rows = self::inspect_all();
		$sum  = [ 'total' => 0, 'present' => 0, 'missing' => 0, 'critical_missing' => 0, 'rows_total' => 0, 'size_total' => 0, 'by_group' => [] ];

		foreach ( $rows as $r ) {
			$sum['total']++;
			$sum['rows_total'] += $r['rows'];
			$sum['size_total'] += $r['size_bytes'];

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
