<?php
/**
 * Diagnostics Column Inspector — schema-drift detector for registered tables.
 *
 * Loads every column row from `information_schema.COLUMNS` in a SINGLE query
 * (filtered by `wpdb->prefix%`) and compares each physical table against a
 * declared "expected columns" list. Modules register their expected schema via
 *
 *   add_filter( 'bizcity_diagnostics_expected_columns', function ( array $map ) {
 *       $map['bizcity_kg_passages'] = [ 'id', 'notebook_id', 'content', 'content_hash', ... ];
 *       return $map;
 *   } );
 *
 * The key is the table SUFFIX (without `wpdb->prefix`) — so the same filter
 * value works across multisite shards. Columns are compared case-insensitively
 * by name only (types/keys are out of scope — installer dbDelta owns that).
 *
 * Diff status:
 *   - 'no_table'  : table missing (so column check is meaningless)
 *   - 'no_schema' : no expected column list registered for this table
 *   - 'ok'        : actual ⊇ expected (extras are tolerated)
 *   - 'drift'     : actual is missing at least one expected column
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics
 * @since      PHASE-0.41 L8 (2026-05-21)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

final class BizCity_Diagnostics_Column_Inspector {

	/** @var array<string,array<int,string>>|null `lower(physical) => [col1, col2,...]` */
	private static $actual_cache = null;

	/** @var array<string,array<int,string>>|null `suffix => [col1, col2,...]` from filter */
	private static $expected_cache = null;

	/**
	 * Actual column names for a physical table (case-preserved).
	 *
	 * @param string $physical Full table name (with prefix).
	 * @return array<int,string> Empty array when table is missing.
	 */
	public static function actual_columns( string $physical ): array {
		$map = self::load_actual_map();
		return $map[ strtolower( $physical ) ] ?? [];
	}

	/**
	 * Expected column names for a registered table.
	 *
	 * @param string $suffix Table suffix (no prefix).
	 * @return array<int,string> Empty when no schema registered.
	 */
	public static function expected_columns( string $suffix ): array {
		$map = self::load_expected_map();
		return $map[ $suffix ] ?? [];
	}

	/**
	 * Diff one registered table (registry row) against actual schema.
	 *
	 * @param array $row Registry row (must have 'name', 'physical', 'exists').
	 * @return array {
	 *     @type string $status   'no_table' | 'no_schema' | 'ok' | 'drift'
	 *     @type array  $actual
	 *     @type array  $expected
	 *     @type array  $missing  Expected ∖ actual (case-insensitive)
	 *     @type array  $extra    Actual ∖ expected (informational)
	 * }
	 */
	public static function diff( array $row ): array {
		$physical = (string) ( $row['physical'] ?? '' );
		$suffix   = (string) ( $row['name']     ?? '' );

		if ( empty( $row['exists'] ) ) {
			return [
				'status'   => 'no_table',
				'actual'   => [],
				'expected' => self::expected_columns( $suffix ),
				'missing'  => [],
				'extra'    => [],
			];
		}

		$actual   = self::actual_columns( $physical );
		$expected = self::expected_columns( $suffix );

		if ( empty( $expected ) ) {
			return [
				'status'   => 'no_schema',
				'actual'   => $actual,
				'expected' => [],
				'missing'  => [],
				'extra'    => [],
			];
		}

		$actual_lc   = array_map( 'strtolower', $actual );
		$expected_lc = array_map( 'strtolower', $expected );

		$missing = [];
		foreach ( $expected as $col ) {
			if ( ! in_array( strtolower( $col ), $actual_lc, true ) ) {
				$missing[] = $col;
			}
		}
		$extra = [];
		foreach ( $actual as $col ) {
			if ( ! in_array( strtolower( $col ), $expected_lc, true ) ) {
				$extra[] = $col;
			}
		}

		return [
			'status'   => empty( $missing ) ? 'ok' : 'drift',
			'actual'   => $actual,
			'expected' => $expected,
			'missing'  => $missing,
			'extra'    => $extra,
		];
	}

	/** Reset memos (tests). */
	public static function flush(): void {
		self::$actual_cache   = null;
		self::$expected_cache = null;
	}

	/* ────────────────────────────────────────────────────────────── */

	private static function load_actual_map(): array {
		if ( null !== self::$actual_cache ) {
			return self::$actual_cache;
		}

		global $wpdb;
		$pattern = $wpdb->esc_like( $wpdb->prefix ) . '%';
		$prev    = $wpdb->suppress_errors( true );
		$rows    = $wpdb->get_results( $wpdb->prepare(
			"SELECT TABLE_NAME, COLUMN_NAME, ORDINAL_POSITION
			 FROM information_schema.COLUMNS
			 WHERE TABLE_SCHEMA = DATABASE()
			   AND TABLE_NAME LIKE %s
			 ORDER BY TABLE_NAME, ORDINAL_POSITION",
			$pattern
		), ARRAY_A );
		$wpdb->suppress_errors( $prev );

		$map = [];
		if ( is_array( $rows ) ) {
			foreach ( $rows as $r ) {
				$tn = strtolower( (string) $r['TABLE_NAME'] );
				$map[ $tn ][] = (string) $r['COLUMN_NAME'];
			}
		}
		return self::$actual_cache = $map;
	}

	private static function load_expected_map(): array {
		if ( null !== self::$expected_cache ) {
			return self::$expected_cache;
		}
		$raw = apply_filters( 'bizcity_diagnostics_expected_columns', [] );
		$out = [];
		if ( is_array( $raw ) ) {
			foreach ( $raw as $suffix => $cols ) {
				if ( ! is_string( $suffix ) || ! is_array( $cols ) ) {
					continue;
				}
				$norm = [];
				foreach ( $cols as $c ) {
					$c = (string) $c;
					if ( $c !== '' ) {
						$norm[] = $c;
					}
				}
				if ( $norm ) {
					$out[ $suffix ] = $norm;
				}
			}
		}
		return self::$expected_cache = $out;
	}
}
