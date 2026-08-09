<?php
/**
 * Diagnostics Orphan Cleaner — quarantine review for deprecated tables.
 *
 * Safety policy (simplified per operator request 2026-05-21):
 *   1. ONLY considers tables in the curated `deprecated_tables()` list.
 *   2. Empty-table guard: a table is dropped ONLY if `COUNT(*) = 0`. Tables
 *      with ANY rows are SKIPPED (operator must export/migrate first).
 *   3. Quarantine guard: entries marked `quarantine_only` are tracked but
 *      never dropped by this cleaner; the owning migration must sign off.
 *   4. Per-shard scope: only acts on the CURRENT site's `$wpdb`. In multisite
 *      each subsite must be visited (each shard has its own DB).
 *   5. Capability gate: only triggered while rendering an admin page that
 *      requires `manage_options` (the Tools → BizCity Diagnostics page).
 *   6. Throttle: runs at most once per hour per blog (transient guard) to
 *      avoid hammering on every admin reload.
 *   7. Audit log: every run is appended to option `bizcity_diagnostics_orphan_log`
 *      (capped at 50 entries).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics
 * @since      2026-05-21
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

final class BizCity_Diagnostics_Orphan_Cleaner {

	const LOG_OPTION       = 'bizcity_diagnostics_orphan_log';
	const LOG_MAX          = 50;
	const THROTTLE_TRANSIENT = 'bizcity_diagnostics_orphan_last_run';
	const THROTTLE_SECONDS = HOUR_IN_SECONDS;

	/**
	 * Inspect each deprecated table on the current shard.
	 *
	 * @return array<int,array{
	 *   name:string, physical:string, reason:string,
	 *   module:string, feature:string, purpose:string, class:string,
	 *   related_tables:array<int,string>, orphan_gate:string,
	 *   exists:bool, rows:int, size_human:string,
	 *   safe_to_drop:bool, skip_reason:string
	 * }>
	 */
	public static function preview(): array {
		global $wpdb;
		$out      = [];
		$prefix   = $wpdb->prefix;
		$entries  = BizCity_Diagnostics_Table_Registry::deprecated_tables();

		$prev_suppress = $wpdb->suppress_errors( true );

		foreach ( $entries as $e ) {
			$physical = ! empty( $e['raw'] )
				? $e['name']
				: ( ( ( $e['prefix_scope'] ?? 'blog' ) === 'base' ) ? $wpdb->base_prefix : $prefix ) . $e['name'];

			// [2026-07-31 Johnny Chu] R-SHOW-TABLES — quarantine preview uses the cached metadata helper and fails closed if unavailable.
			$exists = function_exists( 'bizcity_tbl_exists' ) && bizcity_tbl_exists( $physical );

			$rows = 0;
			$size_human = '—';
			$safe = false;
			$skip = '';
			$quarantine_only = ! empty( $e['quarantine_only'] );
			// [2026-08-01 Johnny Chu] PHASE-1.24-LOG-ORPHAN-GATE — carry dependency/gate hints from deprecated registry into preview rows.
			$related_tables = array_values( array_filter( array_map( 'strval', is_array( $e['related_tables'] ?? null ) ? $e['related_tables'] : array() ) ) );
			$orphan_gate    = (string) ( $e['orphan_gate'] ?? '' );

			if ( $exists ) {
				$rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$physical}`" );
				$info = $wpdb->get_row( $wpdb->prepare(
					"SELECT (DATA_LENGTH + INDEX_LENGTH) AS sz
					 FROM information_schema.TABLES
					 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
					$physical
				), ARRAY_A );
				$size_human = $info ? size_format( (int) $info['sz'], 2 ) : '—';

				if ( $quarantine_only ) {
					$skip = 'QUARANTINE ONLY — owner migration/sign-off required before drop';
					// [2026-08-01 Johnny Chu] PHASE-1.24-LOG-ORPHAN-GATE — expose explicit migration gate to prevent premature SQL drops.
					if ( $orphan_gate !== '' ) {
						$skip .= ' · gate: ' . $orphan_gate;
					}
				} elseif ( $rows === 0 ) {
					$safe = true;
				} else {
					$skip = sprintf( 'HAS %d ROWS — export/migrate before drop', $rows );
				}
			} else {
				$skip = 'Already absent';
			}

			$out[] = [
				'name'         => $e['name'],
				'physical'     => $physical,
				'reason'       => $e['reason'],
				'module'       => (string) ( $e['module']  ?? 'deprecated' ),
				'feature'      => (string) ( $e['feature'] ?? 'legacy cleanup' ),
				'purpose'      => (string) ( $e['purpose'] ?? '' ),
				'class'        => (string) ( $e['class']   ?? '' ),
				'related_tables' => $related_tables,
				'orphan_gate'    => $orphan_gate,
				'exists'       => $exists,
				'rows'         => $rows,
				'size_human'   => $size_human,
				'safe_to_drop' => $safe,
				'skip_reason'  => $skip,
				'quarantine_only' => $quarantine_only,
			];
		}

		$wpdb->suppress_errors( $prev_suppress );
		return $out;
	}

	/**
	 * Auto-drop ALL empty deprecated tables on the current shard.
	 * Tables with rows > 0 are skipped. No dry-run, no constant gate.
	 *
	 * @param bool $force Bypass the per-hour throttle.
	 * @return array{
	 *   blog_id:int, prefix:string, throttled:bool,
	 *   actions:array<int,array{name:string,physical:string,action:string,detail:string}>
	 * }
	 */
	public static function auto_drop( bool $force = false ): array {
		global $wpdb;

		$result = [
			'blog_id'   => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0,
			'prefix'    => $wpdb->prefix,
			'throttled' => false,
			'actions'   => [],
		];

		// Throttle: skip if ran recently on this blog.
		if ( ! $force && get_transient( self::THROTTLE_TRANSIENT ) ) {
			$result['throttled'] = true;
			return $result;
		}

		$preview = self::preview();
		foreach ( $preview as $row ) {
			$action = 'skipped';
			$detail = '';

			if ( ! $row['exists'] ) {
				$action = 'noop';
				$detail = 'already absent';
			} elseif ( ! empty( $row['quarantine_only'] ) ) {
				$action = 'skipped';
				$detail = $row['skip_reason'];
			} elseif ( ! $row['safe_to_drop'] ) {
				$action = 'skipped';
				$detail = $row['skip_reason'];
			} else {
				$ok = $wpdb->query( "DROP TABLE IF EXISTS `{$row['physical']}`" );
				if ( $ok === false ) {
					$action = 'error';
					$detail = $wpdb->last_error ?: 'unknown';
				} else {
					$action = 'dropped';
					$detail = sprintf( 'DROP TABLE OK (was %s, 0 rows)', $row['size_human'] );
				}
			}

			$result['actions'][] = [
				'name'     => $row['name'],
				'physical' => $row['physical'],
				'action'   => $action,
				'detail'   => $detail,
			];
		}

		set_transient( self::THROTTLE_TRANSIENT, time(), self::THROTTLE_SECONDS );
		self::append_log( $result );
		return $result;
	}

	/** Append a compact log entry (capped). */
	private static function append_log( array $result ): void {
		$log = get_option( self::LOG_OPTION, [] );
		if ( ! is_array( $log ) ) {
			$log = [];
		}
		$log[] = [
			'ts'      => gmdate( 'c' ),
			'user'    => function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0,
			'blog_id' => $result['blog_id'],
			'prefix'  => $result['prefix'],
			'summary' => self::summarise( $result['actions'] ),
		];
		if ( count( $log ) > self::LOG_MAX ) {
			$log = array_slice( $log, -self::LOG_MAX );
		}
		update_option( self::LOG_OPTION, $log, false );
	}

	private static function summarise( array $actions ): array {
		$counts = [ 'dropped' => 0, 'skipped' => 0, 'noop' => 0, 'error' => 0 ];
		foreach ( $actions as $a ) {
			$k = $a['action'] ?? 'skipped';
			if ( isset( $counts[ $k ] ) ) {
				$counts[ $k ]++;
			}
		}
		return $counts;
	}

	/** Recent log entries (newest last). */
	public static function get_log(): array {
		$log = get_option( self::LOG_OPTION, [] );
		return is_array( $log ) ? $log : [];
	}
}
