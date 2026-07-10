<?php
/**
 * Bizcity Twin AI — KG-Hub Per-Source Progress Log (PHASE-0.13 Wave 10c)
 *
 * Append-only evidence trail for every per-source learning state transition.
 * Built to diagnose the "100% → 0% reset → re-loop" bug observed on blog 1258
 * notebook 2 (9 sweep-driven learning runs without termination).
 *
 * What it records (per-source timeline):
 *   • extract_started        — extract_passage() began
 *   • passage_done           — bizcity_kg_extraction_passage_done fired
 *   • passage_error          — bizcity_kg_extraction_passage_error fired
 *   • batch_done             — bizcity_kg_extraction_batch_done fired
 *   • sweep_enqueued         — sweep cron found stranded chunks → re-enqueued job
 *   • force_reset            — extract_notebook_pending( force=true ) flipped done→pending
 *   • complete               — first time aggregate hit done == total
 *   • aggregate_drop         — aggregate dropped from full → partial (detector for the loop)
 *
 * Storage: per-blog table `wp_<n>_bizcity_kg_source_progress_log` (multisite-shard native).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge\KG_Hub
 * @since      PHASE-0.13 (2026-05-01)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_KG_Source_Progress_Log {

	const SCHEMA_VERSION    = '1.0.0';
	const OPTION_VERSION    = 'bizcity_kg_source_progress_log_version';
	const RETENTION_DAYS    = 30;

	/** Per-blog migration cache (multisite cron may walk many blogs in one request). */
	private static $migrated_blogs = [];

	/* ─── Lifecycle ─────────────────────────────────────────────────────── */

	public static function bind() {
		add_action( 'init', [ __CLASS__, 'maybe_install' ], 6 );

		// Hook into existing extractor lifecycle (PHASE-0.7 Wave 0 actions).
		add_action( 'bizcity_kg_extraction_passage_done',  [ __CLASS__, 'on_passage_done' ], 20, 1 );
		add_action( 'bizcity_kg_extraction_passage_error', [ __CLASS__, 'on_passage_error' ], 20, 1 );
		add_action( 'bizcity_kg_extraction_batch_done',    [ __CLASS__, 'on_batch_done' ], 20, 1 );
	}

	public static function maybe_install() {
		$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1;
		if ( isset( self::$migrated_blogs[ $blog_id ] ) ) return;

		// Version check FIRST — avoids SHOW TABLES on every request when schema is current.
		// SHOW TABLES is only issued when the version option doesn't match (real upgrade needed).
		if ( get_option( self::OPTION_VERSION ) === self::SCHEMA_VERSION ) {
			self::$migrated_blogs[ $blog_id ] = true;
			return;
		}

		global $wpdb;
		$table = self::table();

		// Version mismatch — check physical existence to decide CREATE vs additive ALTER.
		// [2026-06-21 Johnny Chu] R-SHOW-TABLES
		$table_exists = bizcity_tbl_exists( $table );

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$cs = $wpdb->get_charset_collate();

		dbDelta( "CREATE TABLE IF NOT EXISTS {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			notebook_id BIGINT UNSIGNED DEFAULT NULL,
			source_id BIGINT UNSIGNED DEFAULT NULL,
			passage_id BIGINT UNSIGNED DEFAULT NULL,
			event VARCHAR(40) NOT NULL,
			triggered_by VARCHAR(40) NOT NULL DEFAULT 'system'
				COMMENT 'cron:sweep|manual|ingest|extract|user|api',
			counts_total INT UNSIGNED DEFAULT NULL,
			counts_done INT UNSIGNED DEFAULT NULL,
			counts_error INT UNSIGNED DEFAULT NULL,
			payload TEXT COMMENT 'JSON',
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY source_id (source_id),
			KEY notebook_id (notebook_id),
			KEY event (event),
			KEY created_at (created_at)
		) {$cs};" );

		// Post-verify: only mark cache + bump option when the table is actually
		// present, so dbDelta silent failures retry on the next request instead
		// of poisoning the request with insert errors forever.
		// [2026-06-21 Johnny Chu] R-SHOW-TABLES
		$created = bizcity_tbl_exists( $table );
		if ( ! $created ) {
			error_log( '[KG Source Progress Log] dbDelta failed to create ' . $table
			           . ' on blog ' . $blog_id . ' — will retry next request.' );
			return;
		}

		update_option( self::OPTION_VERSION, self::SCHEMA_VERSION, false );
		self::$migrated_blogs[ $blog_id ] = true;
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_kg_source_progress_log';
	}

	/* ─── Recording API ─────────────────────────────────────────────────── */

	/**
	 * Append a new event row. Best-effort — never throws (logging must not
	 * break the learning pipeline).
	 *
	 * @param array $args {
	 *   @type int    $notebook_id
	 *   @type int    $source_id
	 *   @type int    $passage_id
	 *   @type string $event           Required. See class header for vocabulary.
	 *   @type string $triggered_by    Optional. Defaults to 'system'.
	 *   @type int    $counts_total    Optional. Snapshot of current source aggregate.
	 *   @type int    $counts_done     Optional.
	 *   @type int    $counts_error    Optional.
	 *   @type array  $payload         Optional. Free-form JSON.
	 * }
	 */
	public static function record( array $args ) {
		try {
			self::maybe_install();
			$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1;
			// Guard: maybe_install() may have failed (dbDelta silent fail / no
			// CREATE perm). Skip insert silently rather than letting wpdb spam
			// "table doesn't exist" via its SHOW FULL COLUMNS introspection.
			if ( ! isset( self::$migrated_blogs[ $blog_id ] ) ) {
				return;
			}
			global $wpdb;
			$wpdb->insert( self::table(), [
				'notebook_id'  => isset( $args['notebook_id'] ) ? (int) $args['notebook_id'] : null,
				'source_id'    => isset( $args['source_id'] )   ? (int) $args['source_id']   : null,
				'passage_id'   => isset( $args['passage_id'] )  ? (int) $args['passage_id']  : null,
				'event'        => substr( (string) ( $args['event'] ?? '' ), 0, 40 ),
				'triggered_by' => substr( (string) ( $args['triggered_by'] ?? 'system' ), 0, 40 ),
				'counts_total' => isset( $args['counts_total'] ) ? (int) $args['counts_total'] : null,
				'counts_done'  => isset( $args['counts_done'] )  ? (int) $args['counts_done']  : null,
				'counts_error' => isset( $args['counts_error'] ) ? (int) $args['counts_error'] : null,
				'payload'      => isset( $args['payload'] ) ? wp_json_encode( $args['payload'] ) : null,
			] );
		} catch ( \Throwable $e ) {
			error_log( '[KG Source Progress Log] record failed: ' . $e->getMessage() );
		}
	}

	/* ─── Query API (used by REST endpoint) ─────────────────────────────── */

	/**
	 * Fetch the most recent N events for a source, newest first.
	 *
	 * @param int $source_id
	 * @param int $limit
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_for_source( $source_id, $limit = 100 ) {
		global $wpdb;
		self::maybe_install();
		$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1;
		if ( ! isset( self::$migrated_blogs[ $blog_id ] ) ) { return []; }
		$source_id = (int) $source_id;
		$limit     = max( 1, min( 500, (int) $limit ) );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, notebook_id, source_id, passage_id, event, triggered_by,
			        counts_total, counts_done, counts_error, payload, created_at
			   FROM " . self::table() . "
			  WHERE source_id = %d
			  ORDER BY id DESC
			  LIMIT %d",
			$source_id, $limit
		), ARRAY_A );
		return is_array( $rows ) ? array_map( [ __CLASS__, 'hydrate' ], $rows ) : [];
	}

	/**
	 * Fetch a notebook-wide timeline (all sources + sweep events with NULL source).
	 */
	public static function get_for_notebook( $notebook_id, $limit = 200 ) {
		global $wpdb;
		self::maybe_install();
		$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1;
		if ( ! isset( self::$migrated_blogs[ $blog_id ] ) ) { return []; }
		$notebook_id = (int) $notebook_id;
		$limit       = max( 1, min( 1000, (int) $limit ) );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, notebook_id, source_id, passage_id, event, triggered_by,
			        counts_total, counts_done, counts_error, payload, created_at
			   FROM " . self::table() . "
			  WHERE notebook_id = %d
			  ORDER BY id DESC
			  LIMIT %d",
			$notebook_id, $limit
		), ARRAY_A );
		return is_array( $rows ) ? array_map( [ __CLASS__, 'hydrate' ], $rows ) : [];
	}

	/**
	 * Summarise per-source activity: counts of each event type and last_event_at.
	 * Used by the FE evidence panel to flag suspicious patterns
	 * (e.g. > 1 force_reset, > 3 sweep_enqueued for the same source).
	 */
	public static function summarise_for_source( $source_id ) {
		global $wpdb;
		self::maybe_install();
		$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1;
		if ( ! isset( self::$migrated_blogs[ $blog_id ] ) ) { return []; }
		$source_id = (int) $source_id;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT event, COUNT(*) AS n, MAX(created_at) AS last_at
			   FROM " . self::table() . "
			  WHERE source_id = %d
			  GROUP BY event",
			$source_id
		), ARRAY_A );
		$out = [];
		foreach ( (array) $rows as $r ) {
			$out[ (string) $r['event'] ] = [
				'count'   => (int) $r['n'],
				'last_at' => (string) $r['last_at'],
			];
		}
		return $out;
	}

	private static function hydrate( $row ) {
		$row['payload'] = isset( $row['payload'] ) && $row['payload'] !== ''
			? json_decode( (string) $row['payload'], true )
			: null;
		return $row;
	}

	/* ─── Action hooks (auto-bound in bind()) ───────────────────────────── */

	public static function on_passage_done( $args ) {
		if ( ! is_array( $args ) ) return;
		$src = self::resolve_source_id_from_passage( (int) ( $args['passage_id'] ?? 0 ) );
		self::record( [
			'notebook_id'  => (int) ( $args['notebook_id'] ?? 0 ),
			'source_id'    => $src,
			'passage_id'   => (int) ( $args['passage_id']  ?? 0 ),
			'event'        => 'passage_done',
			'triggered_by' => self::detect_trigger(),
			'payload'      => [
				'triplets'  => (int) ( $args['triplets']  ?? 0 ),
				'cache_hit' => (bool) ( $args['cache_hit'] ?? false ),
			],
		] );
	}

	public static function on_passage_error( $args ) {
		if ( ! is_array( $args ) ) return;
		$src = self::resolve_source_id_from_passage( (int) ( $args['passage_id'] ?? 0 ) );
		self::record( [
			'notebook_id'  => (int) ( $args['notebook_id'] ?? 0 ),
			'source_id'    => $src,
			'passage_id'   => (int) ( $args['passage_id']  ?? 0 ),
			'event'        => 'passage_error',
			'triggered_by' => self::detect_trigger(),
			'payload'      => [
				'error' => (string) ( $args['error'] ?? '' ),
			],
		] );
	}

	public static function on_batch_done( $args ) {
		if ( ! is_array( $args ) ) return;
		// Notebook-wide event (no single source_id). Stored with source_id=NULL.
		self::record( [
			'notebook_id'  => (int) ( $args['notebook_id'] ?? 0 ),
			'event'        => 'batch_done',
			'triggered_by' => self::detect_trigger(),
			'payload'      => [
				'processed'      => (int)   ( $args['processed']      ?? 0 ),
				'total_triplets' => (int)   ( $args['total_triplets'] ?? 0 ),
				'errors'         => (int)   ( $args['errors']         ?? 0 ),
				'remaining'      => (int)   ( $args['remaining']      ?? 0 ),
				'time_exceeded'  => (bool)  ( $args['time_exceeded']  ?? false ),
				'elapsed_s'      => (float) ( $args['elapsed_s']      ?? 0 ),
			],
		] );
	}

	/* ─── Helpers ───────────────────────────────────────────────────────── */

	/**
	 * Look up source_id for a passage. Cached per request to avoid N+1 in batch.
	 */
	private static function resolve_source_id_from_passage( $passage_id ) {
		static $cache = [];
		$passage_id = (int) $passage_id;
		if ( $passage_id <= 0 ) return null;
		if ( array_key_exists( $passage_id, $cache ) ) return $cache[ $passage_id ];
		global $wpdb;
		if ( ! class_exists( 'BizCity_KG_Database' ) ) return null;
		$tbl = BizCity_KG_Database::instance()->tbl_passages();
		$sid = $wpdb->get_var( $wpdb->prepare(
			"SELECT source_id FROM {$tbl} WHERE id = %d", $passage_id
		) );
		$cache[ $passage_id ] = $sid !== null ? (int) $sid : null;
		return $cache[ $passage_id ];
	}

	/**
	 * Best-effort detection of who triggered the current request.
	 * Reads WP-CLI / cron / REST flags + a thread-local set by callers
	 * (sweep cron sets `bizcity_kg_progress_log_trigger` filter).
	 */
	private static function detect_trigger() {
		$override = apply_filters( 'bizcity_kg_progress_log_trigger', '' );
		if ( $override ) return (string) $override;
		if ( defined( 'WP_CLI' ) && WP_CLI ) return 'cli';
		if ( wp_doing_cron() ) return 'cron';
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) return 'rest';
		return 'system';
	}
}
