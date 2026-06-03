<?php
/**
 * Diagnostics Schema Changelog Loader — Phase 0.41 L9.b T7.
 *
 * Loads every `*.json` under `core/diagnostics/changelog/` (skipping `_shared/`),
 * parses them into a canonical PHP shape, and exposes:
 *
 *   - all()              → keyed by module_id
 *   - tables()           → keyed by physical table suffix
 *   - expected_columns() → suffix => [col => type]
 *   - column_since()     → suffix.col => "X.Y.Z" (for "Since" UI column)
 *
 * Bridges into the existing `bizcity_diagnostics_expected_columns` filter so
 * the Column Inspector picks JSON-declared schemas automatically — modules
 * keep their hand-written `ensure_table()` until the migration finishes.
 *
 * Spec: see `changelog/_shared/schema-v1.md`.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics
 * @since      2026-05-21 (Phase 0.41 L9.b)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

final class BizCity_Diagnostics_Changelog_Loader {

	/** @var array<string,array>|null memoized full registry */
	private static $cache = null;

	/** Folder containing JSON files (relative to BIZCITY_DIAGNOSTICS_DIR). */
	private const SUBDIR = 'changelog';

	/**
	 * Register the bridge filter once. Idempotent.
	 */
	public static function register_hooks(): void {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		add_filter( 'bizcity_diagnostics_expected_columns', [ __CLASS__, 'bridge_to_inspector_filter' ], 10, 1 );
	}

	/** Return all parsed JSON files keyed by `module_id`. */
	public static function all(): array {
		if ( self::$cache !== null ) {
			return self::$cache;
		}
		$out = [];
		$dir = trailingslashit( BIZCITY_DIAGNOSTICS_DIR ) . self::SUBDIR;
		if ( ! is_dir( $dir ) ) {
			return self::$cache = $out;
		}
		$files = glob( $dir . '/*.json' );
		if ( ! is_array( $files ) ) {
			return self::$cache = $out;
		}
		foreach ( $files as $file ) {
			$base = basename( $file );
			if ( $base[0] === '_' ) {
				continue; // skip _shared/* if accidentally globbed
			}
			$raw = file_get_contents( $file );
			if ( $raw === false ) {
				continue;
			}
			$data = json_decode( $raw, true );
			if ( ! is_array( $data ) || empty( $data['module_id'] ) ) {
				continue;
			}
			$data['__file'] = $file;
			$out[ (string) $data['module_id'] ] = $data;
		}
		return self::$cache = $out;
	}

	/** Force-reload (tests, after JSON edit). */
	public static function flush(): void {
		self::$cache = null;
	}

	/**
	 * Return every declared table keyed by physical suffix.
	 *
	 * @return array<string,array{module_id:string,owner:string,installer_id:string,since:string,columns:array,indexes:array,purpose:string}>
	 */
	public static function tables(): array {
		$out = [];
		foreach ( self::all() as $mid => $mod ) {
			$tables = isset( $mod['tables'] ) && is_array( $mod['tables'] ) ? $mod['tables'] : [];
			foreach ( $tables as $name => $def ) {
				if ( ! is_array( $def ) ) {
					continue;
				}
				$out[ (string) $name ] = [
					'module_id'    => (string) $mid,
					'owner'        => (string) ( $mod['owner']        ?? '' ),
					'installer_id' => (string) ( $mod['installer_id'] ?? '' ),
					'since'        => (string) ( $def['since']        ?? '' ),
					'purpose'      => (string) ( $def['purpose']      ?? '' ),
					'engine'       => (string) ( $def['engine']       ?? 'InnoDB' ),
					'charset'      => (string) ( $def['charset']      ?? 'utf8mb4' ),
					'collate'      => (string) ( $def['collate']      ?? 'utf8mb4_unicode_ci' ),
					'columns'      => is_array( $def['columns'] ?? null ) ? $def['columns'] : [],
					'indexes'      => is_array( $def['indexes'] ?? null ) ? $def['indexes'] : [],
				];
			}
		}
		return $out;
	}

	/**
	 * suffix => [colName => 'TYPE …'] for the Column Inspector.
	 * Deprecated columns are still listed so existing-column matching works.
	 *
	 * @return array<string,array<string,string>>
	 */
	public static function expected_columns(): array {
		$out = [];
		foreach ( self::tables() as $suffix => $t ) {
			$cols = [];
			foreach ( $t['columns'] as $name => $def ) {
				if ( ! is_array( $def ) ) {
					continue;
				}
				$cols[ (string) $name ] = (string) ( $def['type'] ?? '' );
			}
			if ( $cols ) {
				$out[ $suffix ] = $cols;
			}
		}
		return $out;
	}

	/**
	 * suffix.colName => 'since-version' map for UI "Since" column.
	 *
	 * @return array<string,string>
	 */
	public static function column_since(): array {
		$out = [];
		foreach ( self::tables() as $suffix => $t ) {
			foreach ( $t['columns'] as $name => $def ) {
				if ( is_array( $def ) && ! empty( $def['since'] ) ) {
					$out[ $suffix . '.' . $name ] = (string) $def['since'];
				}
			}
		}
		return $out;
	}

	/**
	 * Bridge into Column Inspector filter. Merges JSON-declared columns into
	 * whatever modules registered manually. JSON wins when both present.
	 *
	 * @param array<string,array<int|string,string>> $map suffix => col names
	 * @return array<string,array<int|string,string>>
	 */
	public static function bridge_to_inspector_filter( $map ): array {
		if ( ! is_array( $map ) ) {
			$map = [];
		}
		foreach ( self::expected_columns() as $suffix => $cols ) {
			// Inspector accepts either a sequential list of names OR an
			// associative col=>type map. Keep names list for backward compat.
			$map[ $suffix ] = array_keys( $cols );
		}
		return $map;
	}
}

// Self-register so loader is active even when admin page is not loaded
// (REST + cron paths still need the bridge to make Column Inspector accurate).
BizCity_Diagnostics_Changelog_Loader::register_hooks();
