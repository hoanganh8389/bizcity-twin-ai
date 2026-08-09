<?php
/**
 * Diagnostics Table Activity — low-overhead runtime table telemetry.
 *
 * Observes WordPress database queries for blog-prefixed bizcity_* tables,
 * aggregates read/write counters in memory, and persists a compact per-blog
 * snapshot at most once per minute. SQL and query payloads are never stored.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics
 * @since      2026-08-01
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( class_exists( 'BizCity_Diagnostics_Table_Activity' ) ) {
	return;
}

final class BizCity_Diagnostics_Table_Activity {

	const OPTION_NAME       = 'bizcity_diagnostics_table_activity';
	const PERSIST_INTERVAL  = 60;
	const SNAPSHOT_VERSION  = 1;

	/** @var array<string,array> */
	private static $pending = array();

	/** @var bool */
	private static $booted = false;

	/**
	 * Register the query observer once per request.
	 */
	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		add_filter( 'query', array( __CLASS__, 'observe_query' ), 1 );
		register_shutdown_function( array( __CLASS__, 'flush' ) );
	}

	/**
	 * Observe a query without retaining SQL text.
	 *
	 * @param mixed $query SQL passed through WordPress' query filter.
	 * @return mixed
	 */
	public static function observe_query( $query ) {
		if ( ! is_string( $query ) || stripos( $query, 'bizcity_' ) === false ) {
			return $query;
		}

		$operation = self::operation( $query );
		if ( '' === $operation ) {
			return $query;
		}

		global $wpdb;
		$prefixes = array( (string) $wpdb->prefix );
		if ( ! empty( $wpdb->base_prefix ) && $wpdb->base_prefix !== $wpdb->prefix ) {
			$prefixes[] = (string) $wpdb->base_prefix;
		}

		$tables = self::tables_in_query( $query, $prefixes );
		if ( empty( $tables ) ) {
			return $query;
		}

		$now     = time();
		$context = self::context();
		foreach ( $tables as $suffix ) {
			if ( '' === $suffix ) {
				continue;
			}
			if ( ! isset( self::$pending[ $suffix ] ) ) {
				self::$pending[ $suffix ] = array(
					'first_seen'    => $now,
					'last_seen'     => $now,
					'last_read'     => 0,
					'last_write'    => 0,
					'last_schema'   => 0,
					'reads'        => 0,
					'writes'       => 0,
					'last_operation'=> $operation,
					'last_context'  => $context,
					'last_source'   => self::source_hint(),
				);
			}
			$entry = &self::$pending[ $suffix ];
			$entry['last_seen']      = $now;
			$entry['last_operation'] = $operation;
			$entry['last_context']   = $context;
			if ( 'write' === $operation ) {
				$entry['last_write'] = $now;
				$entry['writes']++;
			} elseif ( 'read' === $operation ) {
				$entry['last_read'] = $now;
				$entry['reads']++;
			} else {
				$entry['last_schema'] = $now;
			}
			unset( $entry );
		}

		return $query;
	}

	/**
	 * Persist the aggregate without creating a write on every request.
	 */
	public static function flush(): void {
		if ( empty( self::$pending ) || ! function_exists( 'get_option' ) ) {
			return;
		}

		$stored = get_option( self::OPTION_NAME, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$last_flush = (int) ( $stored['updated_at'] ?? 0 );
		if ( $last_flush > 0 && ( time() - $last_flush ) < self::PERSIST_INTERVAL ) {
			return;
		}

		$catalog = isset( $stored['tables'] ) && is_array( $stored['tables'] ) ? $stored['tables'] : array();
		foreach ( self::$pending as $suffix => $current ) {
			$old = isset( $catalog[ $suffix ] ) && is_array( $catalog[ $suffix ] ) ? $catalog[ $suffix ] : array();
			$catalog[ $suffix ] = array(
				'first_seen'     => min( (int) ( $old['first_seen'] ?? 0 ) ?: (int) $current['first_seen'], (int) $current['first_seen'] ),
				'last_seen'      => max( (int) ( $old['last_seen'] ?? 0 ), (int) $current['last_seen'] ),
				'last_read'      => max( (int) ( $old['last_read'] ?? 0 ), (int) $current['last_read'] ),
				'last_write'     => max( (int) ( $old['last_write'] ?? 0 ), (int) $current['last_write'] ),
				'last_schema'    => max( (int) ( $old['last_schema'] ?? 0 ), (int) $current['last_schema'] ),
				'reads'          => (int) ( $old['reads'] ?? 0 ) + (int) $current['reads'],
				'writes'         => (int) ( $old['writes'] ?? 0 ) + (int) $current['writes'],
				'last_operation' => (string) $current['last_operation'],
				'last_context'   => (string) $current['last_context'],
				'last_source'    => (string) $current['last_source'],
			);
		}

		update_option(
			self::OPTION_NAME,
			array(
				'version'    => self::SNAPSHOT_VERSION,
				'updated_at' => time(),
				'tables'     => $catalog,
			),
			false
		);
	}

	/**
	 * Read the persisted snapshot for the current blog.
	 *
	 * @return array<string,array>
	 */
	public static function snapshot(): array {
		$stored = get_option( self::OPTION_NAME, array() );
		return isset( $stored['tables'] ) && is_array( $stored['tables'] ) ? $stored['tables'] : array();
	}

	/**
	 * @param string $query
	 * @return string read|write|schema|''
	 */
	private static function operation( string $query ): string {
		if ( preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE|LOAD)\b/i', $query ) ) {
			return 'write';
		}
		if ( preg_match( '/^\s*(CREATE|ALTER|DROP|TRUNCATE)\b/i', $query ) ) {
			return 'schema';
		}
		if ( preg_match( '/^\s*(SELECT|WITH|SHOW|DESCRIBE|EXPLAIN)\b/i', $query ) ) {
			return 'read';
		}
		return '';
	}

	/**
	 * Extract table suffixes only from SQL table-reference positions.
	 *
	 * @param string   $query
	 * @param string[] $prefixes
	 * @return string[]
	 */
	private static function tables_in_query( string $query, array $prefixes ): array {
		$prefix_pattern = array();
		foreach ( $prefixes as $prefix ) {
			if ( '' !== $prefix ) {
				$prefix_pattern[] = preg_quote( $prefix, '/' );
			}
		}
		if ( empty( $prefix_pattern ) ) {
			return array();
		}

		$table_names = '(?:bizcity_[a-z0-9_]+|global_inbox_admin)';
		$pattern = '/\b(?:FROM|JOIN|INTO|UPDATE|TABLE)\s+(?:IF\s+(?:NOT\s+)?EXISTS\s+)?`?((?:(?:' . implode( '|', $prefix_pattern ) . ')' . $table_names . '|global_inbox_admin))`?/i';
		if ( ! preg_match_all( $pattern, $query, $matches ) || empty( $matches[1] ) ) {
			return array();
		}

		global $wpdb;
		$suffixes = array();
		foreach ( array_unique( $matches[1] ) as $physical ) {
			$suffix = '';
			if ( 'global_inbox_admin' === $physical ) {
				$suffix = $physical;
			} elseif ( strpos( $physical, (string) $wpdb->prefix ) === 0 ) {
				$suffix = substr( $physical, strlen( (string) $wpdb->prefix ) );
			} elseif ( ! empty( $wpdb->base_prefix ) && strpos( $physical, (string) $wpdb->base_prefix ) === 0 ) {
				$suffix = substr( $physical, strlen( (string) $wpdb->base_prefix ) );
			}
			if ( strpos( $suffix, 'bizcity_' ) === 0 || 'global_inbox_admin' === $suffix ) {
				$suffixes[] = $suffix;
			}
		}
		return array_values( array_unique( $suffixes ) );
	}

	private static function context(): string {
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			return 'cron';
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return 'rest';
		}
		if ( function_exists( 'is_admin' ) && is_admin() ) {
			return 'admin';
		}
		return 'frontend';
	}

	private static function source_hint(): string {
		$trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 8 );
		foreach ( $trace as $frame ) {
			$class = (string) ( $frame['class'] ?? '' );
			$function = (string) ( $frame['function'] ?? '' );
			if ( $class === __CLASS__ || $class === 'wpdb' ) {
				continue;
			}
			if ( $class !== '' && strpos( $class, 'BizCity_' ) === 0 ) {
				return $class . ( $function !== '' ? '::' . $function : '' );
			}
			if ( $class === '' && $function !== '' && $function !== 'observe_query' && $function !== 'source_hint' ) {
				return $function;
			}
		}
		return 'wpdb';
	}
}

// [2026-08-01 Johnny Chu] PHASE-1.23-TABLE-ACTIVITY — observe all request contexts with a compact, sampled telemetry store.
BizCity_Diagnostics_Table_Activity::boot();
