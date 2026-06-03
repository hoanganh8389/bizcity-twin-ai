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

		$declared = BizCity_Diagnostics_Changelog_Loader::tables();
		if ( ! isset( $declared[ $suffix ] ) ) {
			return self::envelope( $suffix, false, 'no_json', [], [ 'No JSON changelog declares this table.' ], $start );
		}
		$def = $declared[ $suffix ];

		$physical = $wpdb->prefix . $suffix;
		$exists   = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $physical ) );

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
			}
		}

		foreach ( $def['indexes'] as $idx_name => $idx_def ) {
			if ( ! is_array( $idx_def ) ) { continue; }
			if ( isset( $actual_idx[ $idx_name ] ) ) { continue; }
			$cols = array_values( array_filter( (array) ( $idx_def['cols'] ?? [] ) ) );
			if ( ! $cols ) { continue; }
			$unique = ! empty( $idx_def['unique'] ) ? 'UNIQUE ' : '';
			$cols_sql = implode( ', ', array_map( static fn( $c ) => '`' . str_replace( '`', '', (string) $c ) . '`', $cols ) );
			$sql = sprintf( 'ALTER TABLE `%s` ADD %sINDEX `%s` (%s)', $physical, $unique, $idx_name, $cols_sql );
			$statements[] = $sql;
			$ok = $wpdb->query( $sql );
			if ( $ok === false ) {
				$errors[] = sprintf( 'ADD INDEX %s failed: %s', $idx_name, $wpdb->last_error );
			}
		}

		$action = $statements ? ( $errors ? 'partial' : 'altered' ) : 'noop';
		$ok     = ! $errors;
		if ( $statements && $ok ) {
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
	 * Build a CREATE TABLE statement from JSON definition. dbDelta-friendly:
	 * one column per line, PRIMARY KEY at end, KEY lines for indexes.
	 */
	private static function build_create_sql( string $physical, array $def ): string {
		$lines = [];
		$pk    = null;
		foreach ( $def['columns'] as $name => $col ) {
			if ( ! is_array( $col ) || empty( $col['type'] ) ) { continue; }
			$lines[] = sprintf( '`%s` %s', $name, $col['type'] );
			if ( ! empty( $col['pk'] ) ) {
				$pk = (string) $name;
			}
		}
		if ( $pk !== null ) {
			$lines[] = sprintf( 'PRIMARY KEY (`%s`)', $pk );
		}
		foreach ( $def['indexes'] as $name => $idx ) {
			if ( ! is_array( $idx ) ) { continue; }
			$cols = array_values( array_filter( (array) ( $idx['cols'] ?? [] ) ) );
			if ( ! $cols ) { continue; }
			$unique = ! empty( $idx['unique'] ) ? 'UNIQUE ' : '';
			$cols_sql = implode( ', ', array_map( static fn( $c ) => '`' . str_replace( '`', '', (string) $c ) . '`', $cols ) );
			$lines[] = sprintf( '%sKEY `%s` (%s)', $unique, $name, $cols_sql );
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
