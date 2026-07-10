<?php
/**
 * BizCoach Pro — Astro Data Checklist
 *
 * Tracks per-coachee data fetch status for all astronomical endpoints.
 * Single source of truth: {prefix}bccm_astro_checklist table.
 *
 * Data keys tracked:
 *   Western: western_planets · western_houses · western_aspects · western_wheel_chart
 *   Vedic:   vedic_planets · vedic_extended · vedic_navamsa
 *   Transit: transit (cron-managed — flagged pending after natal, done after cron runs)
 *
 * Validator schema:
 *   key         VARCHAR(64) — data_key slug
 *   status      ENUM('pending','done','failed','partial')
 *   count_items INT — e.g. number of planets/houses returned
 *   last_fetched_at DATETIME
 *   error_msg   TEXT
 *
 * @since 2026-07-04 (PHASE-VEDIC-FAA2)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCoach_Astro_Checklist', false ) ) { return; }

class BizCoach_Astro_Checklist {

	// [2026-07-04 Johnny Chu] PHASE-VEDIC-FAA2 — all canonical data keys
	const KEY_WESTERN_PLANETS     = 'western_planets';
	const KEY_WESTERN_HOUSES      = 'western_houses';
	const KEY_WESTERN_ASPECTS     = 'western_aspects';
	const KEY_WESTERN_WHEEL_CHART = 'western_wheel_chart';
	const KEY_VEDIC_PLANETS       = 'vedic_planets';
	const KEY_VEDIC_EXTENDED      = 'vedic_extended';
	const KEY_VEDIC_NAVAMSA       = 'vedic_navamsa';
	const KEY_TRANSIT             = 'transit';

	const STATUS_PENDING  = 'pending';
	const STATUS_DONE     = 'done';
	const STATUS_FAILED   = 'failed';
	const STATUS_PARTIAL  = 'partial';

	const SCHEMA_VERSION = '1.0.0';
	const VERSION_OPTION = 'bccm_astro_checklist_schema_ver';

	// Expected minimum counts per key for PASS validation
	const MIN_COUNT = array(
		'western_planets'     => 10,
		'western_houses'      => 12,
		'western_aspects'     => 5,
		'western_wheel_chart' => 1,  // url present
		'vedic_planets'       => 9,
		'vedic_extended'      => 9,
		'vedic_navamsa'       => 9,
		'transit'             => 5,
	);

	/* ----------------------------------------------------------------
	 * Table installer
	 * ---------------------------------------------------------------- */

	public static function install(): void {
		// [2026-07-04 Johnny Chu] PHASE-VEDIC-FAA2 — create bccm_astro_checklist table
		global $wpdb;
		$table   = $wpdb->prefix . 'bccm_astro_checklist';
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			coachee_id bigint(20) unsigned NOT NULL,
			data_key varchar(64) NOT NULL,
			status varchar(16) NOT NULL DEFAULT 'pending',
			count_items int(11) NOT NULL DEFAULT 0,
			last_fetched_at datetime DEFAULT NULL,
			error_msg text DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY uniq_coachee_key (coachee_id, data_key),
			KEY idx_coachee (coachee_id),
			KEY idx_status (status)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::VERSION_OPTION, self::SCHEMA_VERSION );
	}

	public static function maybe_install(): void {
		if ( get_option( self::VERSION_OPTION, '' ) !== self::SCHEMA_VERSION ) {
			self::install();
		}
	}

	/* ----------------------------------------------------------------
	 * CRUD
	 * ---------------------------------------------------------------- */

	/**
	 * Upsert a checklist row.
	 *
	 * @param int    $coachee_id
	 * @param string $data_key   One of KEY_* constants
	 * @param string $status     One of STATUS_* constants
	 * @param int    $count      Number of items returned (0 if not applicable)
	 * @param string $error_msg  Error message if failed ('' otherwise)
	 */
	public static function upsert( $coachee_id, $data_key, $status, $count = 0, $error_msg = '' ): void {
		// [2026-07-04 Johnny Chu] PHASE-VEDIC-FAA2 — upsert checklist row
		global $wpdb;
		$table = $wpdb->prefix . 'bccm_astro_checklist';
		$now   = current_time( 'mysql' );

		$existing_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE coachee_id = %d AND data_key = %s LIMIT 1",
			(int) $coachee_id, (string) $data_key
		) );

		$data = array(
			'status'          => (string) $status,
			'count_items'     => (int) $count,
			'last_fetched_at' => ( $status !== self::STATUS_PENDING ) ? $now : null,
			'error_msg'       => (string) $error_msg,
			'updated_at'      => $now,
		);
		$formats = array( '%s', '%d', '%s', '%s', '%s' );

		if ( $existing_id > 0 ) {
			$wpdb->update( $table, $data, array( 'id' => $existing_id ), $formats, array( '%d' ) );
		} else {
			$data['coachee_id'] = (int) $coachee_id;
			$data['data_key']   = (string) $data_key;
			$data['created_at'] = $now;
			$formats[]          = '%d';
			$formats[]          = '%s';
			$formats[]          = '%s';
			$wpdb->insert( $table, $data, $formats );
		}
	}

	/** Mark a key as done with count. */
	public static function mark_done( $coachee_id, $data_key, $count = 0 ): void {
		self::upsert( $coachee_id, $data_key, self::STATUS_DONE, $count );
	}

	/** Mark a key as failed with error message. */
	public static function mark_failed( $coachee_id, $data_key, $error_msg = '' ): void {
		self::upsert( $coachee_id, $data_key, self::STATUS_FAILED, 0, $error_msg );
	}

	/** Mark a key as pending (scheduled for cron). */
	public static function mark_pending( $coachee_id, $data_key ): void {
		self::upsert( $coachee_id, $data_key, self::STATUS_PENDING, 0 );
	}

	/**
	 * Get full checklist for a coachee.
	 * Returns array of rows, one per known data_key.
	 * Keys not yet in DB are returned with status='pending'.
	 */
	public static function get_for_coachee( $coachee_id ): array {
		// [2026-07-04 Johnny Chu] PHASE-VEDIC-FAA2 — fetch checklist rows
		global $wpdb;
		$table = $wpdb->prefix . 'bccm_astro_checklist';
		$rows  = $wpdb->get_results( $wpdb->prepare(
			"SELECT data_key, status, count_items, last_fetched_at, error_msg, updated_at
			 FROM {$table} WHERE coachee_id = %d ORDER BY id ASC",
			(int) $coachee_id
		), ARRAY_A );

		// Build index keyed by data_key
		$indexed = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $r ) {
				$indexed[ $r['data_key'] ] = $r;
			}
		}

		// Return all canonical keys, filling in 'pending' for missing rows
		$all_keys = array(
			self::KEY_WESTERN_PLANETS,
			self::KEY_WESTERN_HOUSES,
			self::KEY_WESTERN_ASPECTS,
			self::KEY_WESTERN_WHEEL_CHART,
			self::KEY_VEDIC_PLANETS,
			self::KEY_VEDIC_EXTENDED,
			self::KEY_VEDIC_NAVAMSA,
			self::KEY_TRANSIT,
		);

		$result = array();
		foreach ( $all_keys as $k ) {
			if ( isset( $indexed[ $k ] ) ) {
				$row = $indexed[ $k ];
				$result[] = array(
					'key'             => $k,
					'status'          => $row['status'],
					'count_items'     => (int) $row['count_items'],
					'last_fetched_at' => $row['last_fetched_at'],
					'error_msg'       => $row['error_msg'],
					'updated_at'      => $row['updated_at'],
					'min_expected'    => self::MIN_COUNT[ $k ] ?? 0,
					'label'           => self::key_label( $k ),
					'system'          => self::key_system( $k ),
				);
			} else {
				$result[] = array(
					'key'             => $k,
					'status'          => self::STATUS_PENDING,
					'count_items'     => 0,
					'last_fetched_at' => null,
					'error_msg'       => null,
					'updated_at'      => null,
					'min_expected'    => self::MIN_COUNT[ $k ] ?? 0,
					'label'           => self::key_label( $k ),
					'system'          => self::key_system( $k ),
				);
			}
		}

		return $result;
	}

	/** Summary counts: done / failed / pending */
	public static function get_summary( $coachee_id ): array {
		$checklist = self::get_for_coachee( $coachee_id );
		$done    = 0;
		$failed  = 0;
		$pending = 0;
		foreach ( $checklist as $item ) {
			if ( $item['status'] === self::STATUS_DONE ) { $done++; }
			elseif ( $item['status'] === self::STATUS_FAILED ) { $failed++; }
			else { $pending++; }
		}
		$total = count( $checklist );
		return array(
			'done'       => $done,
			'failed'     => $failed,
			'pending'    => $pending,
			'total'      => $total,
			'is_complete' => ( $done + $failed === $total && $failed === 0 ),
		);
	}

	/* ----------------------------------------------------------------
	 * Helpers
	 * ---------------------------------------------------------------- */

	public static function key_label( string $key ): string {
		$labels = array(
			'western_planets'     => 'Western — Planets',
			'western_houses'      => 'Western — Houses (12 nhà)',
			'western_aspects'     => 'Western — Aspects (góc chiếu)',
			'western_wheel_chart' => 'Western — Wheel Chart (SVG URL)',
			'vedic_planets'       => 'Vedic — Planets (Rasi)',
			'vedic_extended'      => 'Vedic — Extended (Nakshatra + Pada)',
			'vedic_navamsa'       => 'Vedic — Navamsa D9 Chart',
			'transit'             => 'Transit (hành tinh quá cảnh hôm nay)',
		);
		return $labels[ $key ] ?? $key;
	}

	public static function key_system( string $key ): string {
		if ( strpos( $key, 'western' ) === 0 ) { return 'western'; }
		if ( strpos( $key, 'vedic' )   === 0 ) { return 'vedic'; }
		if ( $key === 'transit' )               { return 'transit'; }
		return 'unknown';
	}
}
