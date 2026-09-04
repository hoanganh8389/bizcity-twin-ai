<?php
/**
 * Diagnostics Auto-Create — Phase 0.41 L9.b T9.
 *
 * Given a table suffix declared in a JSON changelog, reconcile actual DB
 * state with the declared schema using ONLY additive statements:
 *
 *   - CREATE TABLE IF NOT EXISTS
 *   - ALTER TABLE ADD COLUMN  (if column missing)
 *   - ALTER TABLE ADD INDEX   (if index missing)
 *
 * It NEVER drops, modifies, or narrows. Destructive changes still require a
 * hand-written migration through the Site Provisioner.
 *
 * Spec: §5.4.5 of PHASE-0.41 ADDENDUM.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics
 * @since      2026-05-21 (Phase 0.41 L9.b)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

final class BizCity_Diagnostics_Auto_Create {

	/**
	 * Reconcile one table.
	 *
	 * @param string $suffix Table suffix without wpdb prefix (e.g. `bizcity_webchat_sources`).
	 * @return array{ok:bool,action:string,statements:array<int,string>,errors:array<int,string>,took_ms:int,table:string}
	 */
	public static function run( string $suffix ): array {
		global $wpdb;
		$start = microtime( true );
		// [2026-09-01 Johnny Chu] PHASE-1.30-DEAD-SQL-COHORT — central auto-create must refuse retired legacy tables before metadata or DDL access.
		if ( class_exists( 'BizCity_Legacy_Table_Policy' ) && BizCity_Legacy_Table_Policy::install_blocked( $suffix ) ) {
			return self::envelope( $suffix, true, 'retired', [], [], $start );
		}

		$declared = BizCity_Diagnostics_Changelog_Loader::tables();
		if ( ! isset( $declared[ $suffix ] ) ) {
			return self::envelope( $suffix, false, 'no_json', [], [ 'No JSON changelog declares this table.' ], $start );
		}
		$def = $declared[ $suffix ];

		$physical = $wpdb->prefix . $suffix;
		// [2026-07-14 Johnny Chu] R-SHOW-TABLES — use table-exists helper (no SHOW TABLES LIKE).
		$exists   = self::table_exists( $physical );

		$statements = [];
		$errors     = [];

		if ( ! $exists ) {
			$sql = self::build_create_sql( $physical, $def );
			$statements[] = $sql;
			// CREATE TABLE — single statement (no IF NOT EXISTS race here is fine — we just checked).
			$res = $wpdb->query( $sql );
			if ( $res === false ) {
				$errors[] = 'CREATE failed: ' . $wpdb->last_error;
				return self::envelope( $suffix, false, 'create_failed', $statements, $errors, $start );
			}
			// [2026-08-26 Johnny Chu] R-METADATA-CACHE — invalidate the negative table result before any follow-up probe or installer read.
			if ( function_exists( 'bizcity_tbl_invalidate' ) ) {
				bizcity_tbl_invalidate( $physical );
			}
			if ( class_exists( 'BizCity_Diagnostics_Table_Inspector' ) ) {
				// [2026-09-02 Johnny Chu] R-PERF-DIAG — refresh explicit inventory after CREATE, never during normal dashboard rendering.
				BizCity_Diagnostics_Table_Inspector::flush_cache();
			}
			self::audit( $suffix, 'create', $statements );
			return self::envelope( $suffix, true, 'created', $statements, $errors, $start );
		}

		// Table exists — do additive ALTERs only.
		$actual_cols = self::describe_columns( $physical );
		$actual_idx  = self::describe_indexes( $physical );

		foreach ( $def['columns'] as $col_name => $col_def ) {
			if ( ! is_array( $col_def ) ) { continue; }
			// Skip deprecated columns — we don't (re)add columns flagged as legacy.
			if ( ! empty( $col_def['deprecated_since'] ) && ! isset( $actual_cols[ $col_name ] ) ) {
				continue;
			}
			if ( isset( $actual_cols[ $col_name ] ) ) {
				continue; // exists — DO NOT modify
			}
			$type = (string) ( $col_def['type'] ?? '' );
			if ( $type === '' ) { continue; }
			$sql  = sprintf( 'ALTER TABLE `%s` ADD COLUMN `%s` %s', $physical, $col_name, $type );
			$statements[] = $sql;
			$ok = $wpdb->query( $sql );
			if ( $ok === false ) {
				$errors[] = sprintf( 'ADD COLUMN %s failed: %s', $col_name, $wpdb->last_error );
			} else {
				// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — let indexes that reference newly added columns run in the same pass.
				$actual_cols[ $col_name ] = $type;
			}
		}

		foreach ( $def['indexes'] as $idx_name => $idx_def ) {
			if ( ! is_array( $idx_def ) ) { continue; }
			// [2026-07-14 Johnny Chu] R-DCL — PRIMARY is handled separately by CREATE builder.
			if ( ! empty( $idx_def['pk'] ) || strtoupper( (string) $idx_name ) === 'PRIMARY' ) { continue; }
			if ( isset( $actual_idx[ $idx_name ] ) ) { continue; }
			$cols = self::normalize_index_columns( (array) ( $idx_def['cols'] ?? [] ) );
			if ( ! $cols ) { continue; }
			if ( ! self::all_index_columns_exist( $cols, $actual_cols ) ) {
				$errors[] = sprintf( 'ADD INDEX %s skipped: one or more columns missing.', $idx_name );
				continue;
			}
			// [2026-07-14 Johnny Chu] HOTFIX — avoid repeated ALTER UNIQUE failures on duplicate rows.
			if ( ! empty( $idx_def['unique'] ) && self::has_unique_conflict( $physical, $cols ) ) {
				$errors[] = sprintf( 'ADD UNIQUE INDEX %s skipped: duplicate rows exist.', $idx_name );
				continue;
			}
			$unique = ! empty( $idx_def['unique'] ) ? 'UNIQUE ' : '';
			$cols_sql = self::build_index_cols_sql( $cols );
			$sql = sprintf( 'ALTER TABLE `%s` ADD %sINDEX `%s` (%s)', $physical, $unique, $idx_name, $cols_sql );
			$statements[] = $sql;
			$ok = $wpdb->query( $sql );
			if ( $ok === false ) {
				// [2026-07-20 Johnny Chu] R-DCL — ADD INDEX is idempotent; if MySQL says the key already exists, treat as success and avoid admin_init retry spam.
				if ( stripos( (string) $wpdb->last_error, 'Duplicate key name' ) !== false ) {
					$actual_idx[ $idx_name ] = true;
					$wpdb->last_error = '';
					continue;
				}
				$errors[] = sprintf( 'ADD INDEX %s failed: %s', $idx_name, $wpdb->last_error );
			}
		}

		$action = $statements ? ( $errors ? 'partial' : 'altered' ) : 'noop';
		$ok     = ! $errors;
		if ( $statements && $ok ) {
			// [2026-08-26 Johnny Chu] R-METADATA-CACHE — clear cached schema negatives after additive ALTERs.
			if ( function_exists( 'bizcity_tbl_invalidate' ) ) {
				bizcity_tbl_invalidate( $physical );
			}
			if ( class_exists( 'BizCity_Diagnostics_Table_Inspector' ) ) {
				// [2026-09-02 Johnny Chu] R-PERF-DIAG — refresh explicit inventory after additive ALTER/INDEX changes.
				BizCity_Diagnostics_Table_Inspector::flush_cache();
			}
			self::audit( $suffix, $action, $statements );
		}
		return self::envelope( $suffix, $ok, $action, $statements, $errors, $start );
	}

	/** SHOW COLUMNS into name=>type map. */
	private static function describe_columns( string $physical ): array {
		global $wpdb;
		$rows = $wpdb->get_results( "SHOW COLUMNS FROM `{$physical}`", ARRAY_A );
		$out  = [];
		if ( is_array( $rows ) ) {
			foreach ( $rows as $r ) {
				if ( ! empty( $r['Field'] ) ) {
					$out[ (string) $r['Field'] ] = (string) ( $r['Type'] ?? '' );
				}
			}
		}
		return $out;
	}

	/** SHOW INDEX into name=>cols map. */
	private static function describe_indexes( string $physical ): array {
		global $wpdb;
		$rows = $wpdb->get_results( "SHOW INDEX FROM `{$physical}`", ARRAY_A );
		$out  = [];
		if ( is_array( $rows ) ) {
			foreach ( $rows as $r ) {
				$name = (string) ( $r['Key_name'] ?? '' );
				if ( $name === '' || $name === 'PRIMARY' ) { continue; }
				$out[ $name ][ (int) ( $r['Seq_in_index'] ?? 0 ) ] = (string) ( $r['Column_name'] ?? '' );
			}
		}
		return $out;
	}

	/**
	 * [2026-07-14 Johnny Chu] R-SHOW-TABLES — canonical table existence check.
	 */
	private static function table_exists( string $physical ): bool {
		if ( function_exists( 'bizcity_tbl_exists' ) ) {
			return (bool) bizcity_tbl_exists( $physical );
		}

		static $s = [];
		if ( isset( $s[ $physical ] ) ) {
			return $s[ $physical ];
		}

		global $wpdb;
		$ck      = 'bz_tbl_' . (int) get_current_blog_id() . '_' . crc32( $physical );
		$present = wp_cache_get( $ck, 'bizcity_tbl' );
		if ( false === $present ) {
			$present = (int) (bool) $wpdb->get_var( $wpdb->prepare(
				'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
				$physical
			) );
			wp_cache_set( $ck, $present, 'bizcity_tbl', HOUR_IN_SECONDS );
		}

		$s[ $physical ] = (bool) $present;
		return $s[ $physical ];
	}

	/**
	 * Normalize index columns, supporting prefix index syntax like col(64).
	 *
	 * @param array $cols Raw col specs from JSON.
	 * @return array<int,array{name:string,length:int}>
	 */
	private static function normalize_index_columns( array $cols ): array {
		$out = [];
		foreach ( $cols as $raw ) {
			$raw = trim( str_replace( '`', '', (string) $raw ) );
			if ( $raw === '' ) {
				continue;
			}

			if ( preg_match( '/^([a-zA-Z0-9_]+)\((\d+)\)$/', $raw, $m ) ) {
				$out[] = [ 'name' => (string) $m[1], 'length' => (int) $m[2] ];
				continue;
			}

			$out[] = [ 'name' => $raw, 'length' => 0 ];
		}
		return $out;
	}

	/**
	 * Render normalized index columns to SQL fragment.
	 *
	 * @param array<int,array{name:string,length:int}> $cols
	 */
	private static function build_index_cols_sql( array $cols ): string {
		$parts = [];
		foreach ( $cols as $c ) {
			$col = '`' . str_replace( '`', '', (string) $c['name'] ) . '`';
			if ( ! empty( $c['length'] ) ) {
				$col .= '(' . (int) $c['length'] . ')';
			}
			$parts[] = $col;
		}
		return implode( ', ', $parts );
	}

	/**
	 * Ensure all columns referenced by an index exist before ALTER ADD INDEX.
	 *
	 * @param array<int,array{name:string,length:int}> $cols
	 * @param array<string,string> $actual_cols
	 */
	private static function all_index_columns_exist( array $cols, array $actual_cols ): bool {
		foreach ( $cols as $c ) {
			if ( ! isset( $actual_cols[ (string) $c['name'] ] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Check whether a UNIQUE index would fail because duplicate tuples already exist.
	 *
	 * @param string $physical
	 * @param array<int,array{name:string,length:int}> $cols
	 */
	private static function has_unique_conflict( string $physical, array $cols ): bool {
		global $wpdb;
		$group_cols = implode( ', ', array_map( static function ( $c ) {
			return '`' . str_replace( '`', '', (string) $c['name'] ) . '`';
		}, $cols ) );

		if ( $group_cols === '' ) {
			return false;
		}

		$sql = "SELECT 1 FROM `{$physical}` GROUP BY {$group_cols} HAVING COUNT(*) > 1 LIMIT 1";
		return (bool) $wpdb->get_var( $sql );
	}

	/**
	 * Build a CREATE TABLE statement from JSON definition. dbDelta-friendly:
	 * one column per line, PRIMARY KEY at end, KEY lines for indexes.
	 */
	private static function build_create_sql( string $physical, array $def ): string {
		$lines = [];
		$pk_sql = '';
		foreach ( $def['columns'] as $name => $col ) {
			if ( ! is_array( $col ) || empty( $col['type'] ) ) { continue; }
			$lines[] = sprintf( '`%s` %s', $name, $col['type'] );
			if ( ! empty( $col['pk'] ) ) {
				$pk_sql = '`' . str_replace( '`', '', (string) $name ) . '`';
			}
		}
		foreach ( $def['indexes'] as $name => $idx ) {
			if ( ! is_array( $idx ) ) { continue; }
			$cols = self::normalize_index_columns( (array) ( $idx['cols'] ?? [] ) );
			if ( ! $cols ) { continue; }
			if ( ! empty( $idx['pk'] ) || strtoupper( (string) $name ) === 'PRIMARY' ) {
				if ( $pk_sql === '' ) {
					$pk_sql = self::build_index_cols_sql( $cols );
				}
				continue;
			}
			$unique = ! empty( $idx['unique'] ) ? 'UNIQUE ' : '';
			$cols_sql = self::build_index_cols_sql( $cols );
			$lines[] = sprintf( '%sKEY `%s` (%s)', $unique, $name, $cols_sql );
		}
		if ( $pk_sql !== '' ) {
			$lines[] = sprintf( 'PRIMARY KEY (%s)', $pk_sql );
		}
		$engine  = $def['engine']  ?? 'InnoDB';
		$charset = $def['charset'] ?? 'utf8mb4';
		$collate = $def['collate'] ?? 'utf8mb4_unicode_ci';
		return sprintf(
			"CREATE TABLE IF NOT EXISTS `%s` (\n  %s\n) ENGINE=%s DEFAULT CHARSET=%s COLLATE=%s",
			$physical,
			implode( ",\n  ", $lines ),
			$engine,
			$charset,
			$collate
		);
	}

	/** Emit twin_event_stream row + error reporter entry. */
	private static function audit( string $suffix, string $action, array $statements ): void {
		if ( class_exists( 'BizCity_Error_Reporter' ) ) {
			BizCity_Error_Reporter::record( [
				'code'    => 'schema_auto_repaired',
				'module'  => 'diagnostics/auto-create',
				'title'   => sprintf( 'Auto-create %s on %s', $action, $suffix ),
				'detail'  => implode( "\n", $statements ),
				'context' => [ 'table' => $suffix, 'action' => $action, 'count' => count( $statements ) ],
				'source'  => 'be',
			] );
		}
	}

	private static function envelope( string $suffix, bool $ok, string $action, array $statements, array $errors, float $start ): array {
		global $wpdb;
		return [
			'ok'         => $ok,
			'action'     => $action,
			'statements' => $statements,
			'errors'     => $errors,
			'took_ms'    => (int) round( ( microtime( true ) - $start ) * 1000 ),
			'table'      => $wpdb->prefix . $suffix,
		];
	}
}
