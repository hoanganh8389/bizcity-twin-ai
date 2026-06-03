<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Scheduler
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * BizCity Scheduler — Manager (DB CRUD + Context Builder)
 *
 * Manages the `bizcity_crm_events` table lifecycle (renamed from
 * `bizcity_scheduler_events` in schema v3 — see PHASE-0.35-WAVES.md
 * §A M-CRM.M12 v2 Calendar Unification).
 *
 * Fires action hooks for cross-module integration.
 *
 * Schema version tracked via wp_options (autoloaded) — zero SHOW TABLES on hot path.
 *
 * @package  BizCity_Scheduler
 * @since    2026-04-01
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Scheduler_Manager {

	private static $instance = null;

	/** @var string */
	private $table;

	const SCHEMA_VERSION     = 3;
	const SCHEMA_VERSION_KEY = 'bizcity_scheduler_schema_ver';

	/** Final unified table name (M-CRM.M12 v2 — phase 2). */
	const TABLE_NAME = 'bizcity_crm_events';

	/** Legacy scheduler table — renamed in migrate_to_3(). */
	const LEGACY_SCHEDULER_TABLE = 'bizcity_scheduler_events';

	public static function instance(): self {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . self::TABLE_NAME;
	}

	/* ================================================================
	 *  Schema
	 * ================================================================ */

	/** Per-blog physical-existence cache to avoid SHOW TABLES on hot paths. */
	private static $ready_blogs = [];

	/** Re-entrancy guard for self-heal. */
	private $is_installing = false;

	/** Transient key prefix for cross-request SHOW TABLES cache (1 day TTL). */
	private const TBL_OK_TRANSIENT = 'bizcity_sched_tbl_ok_';

	/**
	 * Check schema is up-to-date AND physical table exists on the current shard.
	 *
	 * Multisite + WPDB_Router (slave3/slave10) can desync the autoloaded
	 * version option from actual table state — the option lives in main DB
	 * while the table lives on a sharded slave. We verify with SHOW TABLES
	 * ONCE (cross-request transient), then cache in static for the remainder
	 * of the same request. Transient is cleared on every schema migration so
	 * shard-provisioning self-heal still works.
	 */
	private function table_ready(): bool {
		if ( ( (int) get_option( self::SCHEMA_VERSION_KEY, 0 ) ) < self::SCHEMA_VERSION ) {
			return false;
		}
		$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		// L1: in-request static.
		if ( isset( self::$ready_blogs[ $blog_id ] ) ) {
			return self::$ready_blogs[ $blog_id ];
		}
		// L2: cross-request transient — avoids SHOW TABLES on every page load.
		$transient_key = self::TBL_OK_TRANSIENT . $blog_id;
		if ( get_transient( $transient_key ) === 'yes' ) {
			return self::$ready_blogs[ $blog_id ] = true;
		}
		// L3: actual SHOW TABLES (fires once per install / after migration / after TTL).
		$exists = $this->table_exists( $this->table );
		if ( $exists ) {
			set_transient( $transient_key, 'yes', DAY_IN_SECONDS );
		}
		return self::$ready_blogs[ $blog_id ] = $exists;
	}

	/**
	 * Public readiness probe for external callers (cron, diagnostics).
	 */
	public function is_ready(): bool {
		return $this->table_ready();
	}

	/**
	 * Install or migrate the schema. Called from activation hook or first use.
	 *
	 * Self-heals when the option is set but the physical table is missing
	 * (e.g. shard provisioned after the option was first saved).
	 */
	public function ensure_schema(): void {
		if ( $this->is_installing ) {
			return;
		}
		$this->is_installing = true;
		try {
			$stored = (int) get_option( self::SCHEMA_VERSION_KEY, 0 );

			// Fast path: option current AND physical table confirmed (L1 static + L2 transient;
			// avoids SHOW TABLES on every ensure_schema() call after first check).
			if ( $this->table_ready() ) {
				return;
			}

			// About to migrate — flush cross-request cache so table_ready() re-verifies below.
			$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
			delete_transient( self::TBL_OK_TRANSIENT . $blog_id );
			unset( self::$ready_blogs[ $blog_id ] );

			$this->migrate( $stored );

			// Only commit the version option when the physical table actually exists.
			if ( $this->table_exists( $this->table ) ) {
				update_option( self::SCHEMA_VERSION_KEY, self::SCHEMA_VERSION, true );
				set_transient( self::TBL_OK_TRANSIENT . $blog_id, 'yes', DAY_IN_SECONDS );
				self::$ready_blogs[ $blog_id ] = true;
			}
		} finally {
			$this->is_installing = false;
		}
	}

	private function migrate( int $from ): void {
		if ( $from < 1 ) {
			$this->migrate_to_1();
		}

		if ( $from < 2 ) {
			$this->migrate_to_2();
		}

		if ( $from < 3 ) {
			$this->migrate_to_3();
		}
	}

	private function migrate_to_1(): void {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();

		// v1 historically created the legacy scheduler table; v3 renames it.
		// New installs jump straight to v3 schema below — but we still emit
		// the v1 CREATE so dbDelta works even if v3 step finds nothing to rename.
		$legacy = $wpdb->prefix . self::LEGACY_SCHEDULER_TABLE;

		$sql = "CREATE TABLE {$legacy} (
			id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id         BIGINT UNSIGNED NOT NULL,
			title           VARCHAR(255) NOT NULL DEFAULT '',
			description     TEXT,
			start_at        DATETIME NOT NULL,
			end_at          DATETIME DEFAULT NULL,
			all_day         TINYINT(1) NOT NULL DEFAULT 0,
			reminder_min    INT NOT NULL DEFAULT 15,
			reminder_sent   TINYINT(1) NOT NULL DEFAULT 0,
			google_event_id     VARCHAR(255) DEFAULT NULL,
			google_calendar_id  VARCHAR(255) DEFAULT 'primary',
			google_synced_at    DATETIME DEFAULT NULL,
			source          VARCHAR(32) NOT NULL DEFAULT 'user',
			ai_context      TEXT,
			status          VARCHAR(16) NOT NULL DEFAULT 'active',
			created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_user_start (user_id, start_at),
			KEY idx_reminder (reminder_sent, start_at, status),
			KEY idx_google (google_event_id)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	private function migrate_to_2(): void {
		global $wpdb;

		$legacy = $wpdb->prefix . self::LEGACY_SCHEDULER_TABLE;
		$wpdb->query( "ALTER TABLE {$legacy} ADD COLUMN reminder_claimed_at DATETIME DEFAULT NULL AFTER reminder_sent" );
	}

	/**
	 * Schema v3 — Calendar Unification (M-CRM.M12 v2, 2026-05-13).
	 *
	 * Steps:
	 *   1. If CRM legacy table {prefix}bizcity_crm_events exists with the OLD small
	 *      schema (BIGINT start_at), rename it → *_legacy_<date> to free the name.
	 *   2. Rename {prefix}bizcity_scheduler_events → {prefix}bizcity_crm_events.
	 *      If the scheduler table doesn't exist (fresh install on subsite),
	 *      create the unified table from scratch.
	 *   3. ALTER ADD: event_type, metadata, google_account_id (+ idx_event_type).
	 *   4. Backfill rows from CRM legacy into unified table
	 *      (FROM_UNIXTIME for start_at/end_at, JSON_OBJECT for metadata,
	 *       source='crm_calendar', event_type from legacy `type`).
	 */
	private function migrate_to_3(): void {
		global $wpdb;

		$legacy_scheduler = $wpdb->prefix . self::LEGACY_SCHEDULER_TABLE;
		$unified          = $wpdb->prefix . self::TABLE_NAME;
		$crm_legacy       = $unified . '_legacy_' . gmdate( 'Ymd' );

		// 1) Free the unified name: if a CRM-style table sits there, rename it.
		if ( $this->table_exists( $unified ) && ! $this->table_exists( $legacy_scheduler ) ) {
			// Could be: (a) we already migrated (no-op) or (b) old CRM small schema.
			// Detect by presence of `event_type` column (v3 marker).
			$has_event_type = (bool) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM information_schema.COLUMNS
				 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'event_type'",
				$unified
			) );
			if ( $has_event_type ) {
				return; // Already on v3.
			}
		}

		if ( $this->table_exists( $unified ) ) {
			// Old CRM table is in the way — rename it aside.
			$wpdb->query( "RENAME TABLE `{$unified}` TO `{$crm_legacy}`" );
		}

		// 2) Rename scheduler → unified (if scheduler exists).
		if ( $this->table_exists( $legacy_scheduler ) ) {
			$wpdb->query( "RENAME TABLE `{$legacy_scheduler}` TO `{$unified}`" );
		} else {
			// Fresh subsite — create unified table directly with full v3 schema.
			$charset = $wpdb->get_charset_collate();
			$sql = "CREATE TABLE {$unified} (
				id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id         BIGINT UNSIGNED NOT NULL,
				title           VARCHAR(255) NOT NULL DEFAULT '',
				description     TEXT,
				start_at        DATETIME NOT NULL,
				end_at          DATETIME DEFAULT NULL,
				all_day         TINYINT(1) NOT NULL DEFAULT 0,
				reminder_min    INT NOT NULL DEFAULT 15,
				reminder_sent   TINYINT(1) NOT NULL DEFAULT 0,
				reminder_claimed_at DATETIME DEFAULT NULL,
				google_event_id     VARCHAR(255) DEFAULT NULL,
				google_calendar_id  VARCHAR(255) DEFAULT 'primary',
				google_account_id   BIGINT UNSIGNED DEFAULT NULL,
				google_synced_at    DATETIME DEFAULT NULL,
				source          VARCHAR(32) NOT NULL DEFAULT 'user',
				ai_context      TEXT,
				status          VARCHAR(16) NOT NULL DEFAULT 'active',
				event_type      VARCHAR(32) NOT NULL DEFAULT 'meeting',
				metadata        LONGTEXT NULL,
				created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY idx_user_start (user_id, start_at),
				KEY idx_reminder (reminder_sent, start_at, status),
				KEY idx_google (google_event_id),
				KEY idx_event_type (event_type)
			) {$charset};";
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta( $sql );
			return; // No legacy data to backfill on fresh subsite.
		}

		// 3) ALTER ADD new columns + index.
		$wpdb->query( "ALTER TABLE `{$unified}`
			ADD COLUMN event_type VARCHAR(32) NOT NULL DEFAULT 'meeting' AFTER status,
			ADD COLUMN metadata LONGTEXT NULL AFTER event_type,
			ADD COLUMN google_account_id BIGINT UNSIGNED DEFAULT NULL AFTER google_calendar_id,
			ADD KEY idx_event_type (event_type)
		" );

		// 4) Backfill from CRM legacy table if it exists.
		if ( $this->table_exists( $crm_legacy ) ) {
			$wpdb->query(
				"INSERT INTO `{$unified}`
					(user_id, title, start_at, end_at, event_type, metadata, source, status, created_at, updated_at)
				 SELECT
					COALESCE(created_by, 0)                              AS user_id,
					title                                                AS title,
					FROM_UNIXTIME(start_at)                              AS start_at,
					FROM_UNIXTIME(end_at)                                AS end_at,
					COALESCE(NULLIF(type, ''), 'meeting')                AS event_type,
					JSON_OBJECT(
						'attendees',           COALESCE(JSON_EXTRACT(attendees_json, '$'), JSON_ARRAY()),
						'related_entity_type', related_entity_type,
						'related_entity_id',   related_entity_id,
						'migrated_from',       'crm_events_legacy'
					)                                                    AS metadata,
					'crm_calendar'                                       AS source,
					'active'                                             AS status,
					created_at,
					updated_at
				 FROM `{$crm_legacy}`"
			);
		}
	}

	/** SHOW TABLES helper (used only in migrate paths). */
	private function table_exists( string $table ): bool {
		global $wpdb;
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		return $found === $table;
	}

	/* ================================================================
	 *  CRUD
	 * ================================================================ */

	/**
	 * Create event.
	 *
	 * @param array $data {title, start_at, end_at?, description?, all_day?, reminder_min?, source?, ai_context?, user_id?}
	 * @return int|WP_Error  Event ID on success.
	 */
	public function create_event( array $data ) {
		if ( ! $this->table_ready() ) {
			$this->ensure_schema();
		}

		global $wpdb;

		$row = $this->sanitize_row( $data );
		if ( is_wp_error( $row ) ) {
			return $row;
		}

		$inserted = $wpdb->insert( $this->table, $row );
		if ( ! $inserted ) {
			return new \WP_Error( 'db_insert', 'Failed to insert event.' );
		}

		$event_id = (int) $wpdb->insert_id;
		$event    = $this->get_event( $event_id );

		/**
		 * Fires after an event is created.
		 *
		 * Consumers:
		 *   - Scheduler_Google → sync to Google Calendar
		 *   - Intent module    → refresh LLM context
		 *   - Automation       → trigger workflows
		 *   - Channel Gateway  → push notification to user
		 *
		 * @param object $event  Full DB row.
		 * @param array  $data   Original input data.
		 */
		do_action( 'bizcity_scheduler_event_created', $event, $data );

		return $event_id;
	}

	/**
	 * Update event.
	 *
	 * @param int      $id       Event ID.
	 * @param array    $data     Fields to update.
	 * @param int|null $user_id  If provided, enforce ownership (0 = skip check, admin bypass).
	 * @return true|WP_Error
	 */
	public function update_event( int $id, array $data, ?int $user_id = null ) {
		if ( ! $this->table_ready() ) {
			return new \WP_Error( 'no_table', 'Scheduler table not ready.' );
		}

		global $wpdb;

		$old = $this->get_event( $id );
		if ( ! $old ) {
			return new \WP_Error( 'not_found', 'Event not found.' );
		}

		// Ownership guard — skip only if $user_id === null (legacy callers) or admin
		if ( $user_id !== null && $user_id > 0 && (int) $old->user_id !== $user_id ) {
			return new \WP_Error( 'forbidden', 'Ban khong co quyen chinh su kien nay.' );
		}

		$allowed = [ 'title', 'description', 'start_at', 'end_at', 'all_day', 'reminder_min', 'status', 'source', 'ai_context', 'google_event_id', 'google_calendar_id', 'google_synced_at', 'google_account_id', 'event_type', 'metadata' ];
		$update  = [];
		foreach ( $allowed as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				$update[ $key ] = $data[ $key ];
			}
		}

		if ( empty( $update ) ) {
			return true;
		}

		// SQL-level defense: include user_id in WHERE when provided (defense-in-depth)
		$where = $user_id !== null && $user_id > 0
			? [ 'id' => $id, 'user_id' => $user_id ]
			: [ 'id' => $id ];
		$wpdb->update( $this->table, $update, $where );

		$event = $this->get_event( $id );

		/**
		 * @param object $event     Updated row.
		 * @param object $old       Previous row.
		 * @param array  $changed   Changed field keys.
		 */
		do_action( 'bizcity_scheduler_event_updated', $event, $old, array_keys( $update ) );

		return true;
	}

	/**
	 * Delete event.
	 *
	 * @param int      $id      Event ID.
	 * @param int|null $user_id If provided, enforce ownership.
	 */
	public function delete_event( int $id, ?int $user_id = null ) {
		if ( ! $this->table_ready() ) {
			return new \WP_Error( 'no_table', 'Scheduler table not ready.' );
		}

		global $wpdb;

		$event = $this->get_event( $id );
		if ( ! $event ) {
			return new \WP_Error( 'not_found', 'Event not found.' );
		}

		// Ownership guard
		if ( $user_id !== null && $user_id > 0 && (int) $event->user_id !== $user_id ) {
			return new \WP_Error( 'forbidden', 'Ban khong co quyen xoa su kien nay.' );
		}

		// SQL-level defense: include user_id in WHERE when provided
		$where  = $user_id !== null && $user_id > 0
			? [ 'id' => $id, 'user_id' => $user_id ]
			: [ 'id' => $id ];
		$format = $user_id !== null && $user_id > 0 ? [ '%d', '%d' ] : [ '%d' ];
		$wpdb->delete( $this->table, $where, $format );

		/**
		 * @param object $event  Deleted row (snapshot before deletion).
		 */
		do_action( 'bizcity_scheduler_event_deleted', $event );

		return true;
	}

	/* ================================================================
	 *  Queries
	 * ================================================================ */

	/**
	 * Get single event by ID.
	 *
	 * @param int      $id       Event ID.
	 * @param int|null $user_id  If provided, only return event owned by this user.
	 */
	public function get_event( int $id, ?int $user_id = null ) {
		if ( ! $this->table_ready() ) {
			return null;
		}
		global $wpdb;

		if ( $user_id !== null && $user_id > 0 ) {
			return $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE id = %d AND user_id = %d",
				$id, $user_id
			) );
		}

		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$this->table} WHERE id = %d",
			$id
		) );
	}

	/**
	 * List events for a user within a date range.
	 *
	 * @param int    $user_id
	 * @param string $from     Y-m-d or Y-m-d H:i:s
	 * @param string $to
	 * @param string $status   'active' | 'done' | 'cancelled' | 'all'
	 * @return array
	 */
	public function get_events( int $user_id, string $from, string $to, string $status = 'active', $event_type = '' ): array {
		if ( ! $this->table_ready() ) {
			return [];
		}
		global $wpdb;

		$where = "user_id = %d AND start_at <= %s AND (end_at >= %s OR (end_at IS NULL AND start_at >= %s))";
		$args  = [ $user_id, $to, $from, $from ];

		if ( $status && $status !== 'all' ) {
			$allowed_statuses = [ 'active', 'done', 'cancelled' ];
			if ( in_array( $status, $allowed_statuses, true ) ) {
				$where .= " AND status = %s";
				$args[] = $status;
			}
		}

		// Optional event_type filter — accepts string ("fb_post") or CSV
		// ("fb_post,web_post,reminder_zalo") or array. Caller is responsible
		// for using canonical types; unknown values just return zero rows.
		if ( ! empty( $event_type ) ) {
			$types = is_array( $event_type )
				? $event_type
				: array_filter( array_map( 'trim', explode( ',', (string) $event_type ) ) );
			if ( $types ) {
				$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
				$where .= " AND event_type IN ($placeholders)";
				$args   = array_merge( $args, $types );
			}
		}

		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$this->table} WHERE {$where} ORDER BY start_at ASC",
			...$args
		), ARRAY_A ) ?: [];
	}

	/**
	 * Get today's events for a user.
	 */
	public function get_today_events( int $user_id ): array {
		$today_start = current_time( 'Y-m-d' ) . ' 00:00:00';
		$today_end   = current_time( 'Y-m-d' ) . ' 23:59:59';
		return $this->get_events( $user_id, $today_start, $today_end );
	}

	/**
	 * Get events needing reminder notification.
	 *
	 * Two semantics share this queue:
	 *   - **Reminder (reminder_min > 0)**: fire BEFORE start_at, between
	 *     (start_at - reminder_min) and start_at. Used by CRM calendar.
	 *   - **Publisher (reminder_min = 0)**: fire AT/AFTER start_at, with 7-day
	 *     catch-up so cron lag doesn't lose events. Used by FB publisher,
	 *     scheduled posts, future webhook dispatchers…
	 *
	 * @return array
	 */
	public function get_pending_reminders(): array {
		if ( ! $this->table_ready() ) {
			return [];
		}
		global $wpdb;

		$now    = current_time( 'mysql' );
		$cutoff = wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( 7 * DAY_IN_SECONDS ) );

		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$this->table}
			 WHERE reminder_sent = 0
			   AND status = 'active'
			   AND (
			        ( reminder_min > 0
			          AND DATE_SUB(start_at, INTERVAL reminder_min MINUTE) <= %s
			          AND start_at >= %s )
			     OR ( reminder_min = 0
			          AND start_at <= %s
			          AND start_at >= %s )
			   )
			 ORDER BY start_at ASC
			 LIMIT 50",
			$now, $now, $now, $cutoff
		), ARRAY_A ) ?: [];
	}

	/**
	 * Atomically claim due reminders to prevent duplicate fire across concurrent cron runs.
	 */
	public function claim_due_reminders( int $limit = 50 ): array {
		if ( ! $this->table_ready() ) {
			return [];
		}

		global $wpdb;

		$now        = current_time( 'mysql' );
		$stale_lock = wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( 10 * MINUTE_IN_SECONDS ) );
		$catchup    = wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( 7 * DAY_IN_SECONDS ) );
		// Dual-semantic claim:
		//   (A) reminder_min > 0 → fire BEFORE start_at (CRM reminder).
		//   (B) reminder_min = 0 → fire AT/AFTER start_at, up to 7 days back
		//        (publisher events: FB post, future webhooks, etc.).
		$ids        = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM {$this->table}
			 WHERE reminder_sent = 0
			   AND status = 'active'
			   AND (
			        ( reminder_min > 0
			          AND DATE_SUB(start_at, INTERVAL reminder_min MINUTE) <= %s
			          AND start_at >= %s )
			     OR ( reminder_min = 0
			          AND start_at <= %s
			          AND start_at >= %s )
			   )
			   AND (reminder_claimed_at IS NULL OR reminder_claimed_at < %s)
			 ORDER BY start_at ASC
			 LIMIT %d",
			$now,
			$now,
			$now,
			$catchup,
			$stale_lock,
			$limit
		) );

		if ( empty( $ids ) ) {
			return [];
		}

		$claimed = [];
		foreach ( $ids as $id ) {
			$updated = $wpdb->query( $wpdb->prepare(
				"UPDATE {$this->table}
				 SET reminder_claimed_at = %s
				 WHERE id = %d
				   AND reminder_sent = 0
				   AND (reminder_claimed_at IS NULL OR reminder_claimed_at < %s)",
				$now,
				(int) $id,
				$stale_lock
			) );

			if ( 1 !== (int) $updated ) {
				continue;
			}

			$event = $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE id = %d",
				(int) $id
			), ARRAY_A );

			if ( ! empty( $event ) ) {
				$claimed[] = $event;
			}
		}

		return $claimed;
	}

	/**
	 * Mark reminder as sent.
	 */
	public function mark_reminder_sent( int $id ): void {
		if ( ! $this->table_ready() ) {
			return;
		}
		global $wpdb;
		$wpdb->update( $this->table, [ 'reminder_sent' => 1, 'reminder_claimed_at' => null ], [ 'id' => $id ], [ '%d', '%s' ], [ '%d' ] );
	}

	/**
	 * Release claimed reminder if processing failed.
	 */
	public function release_reminder_claim( int $id ): void {
		if ( ! $this->table_ready() ) {
			return;
		}
		global $wpdb;
		$wpdb->update( $this->table, [ 'reminder_claimed_at' => null ], [ 'id' => $id ], [ '%s' ], [ '%d' ] );
	}

	/* ================================================================
	 *  LLM Context Builder
	 * ================================================================ */

	/**
	 * Build compact text of today's events for LLM context injection.
	 *
	 * Used by:
	 *   - Intent module: inject into system prompt
	 *   - Twin Core: awareness layer
	 *
	 * @param int $user_id
	 * @return string  "Lịch hôm nay:\n- 09:00 Họp team\n- 14:00 Call khách"
	 */
	public function build_today_context( int $user_id ): string {
		$events = $this->get_today_events( $user_id );
		if ( empty( $events ) ) {
			return '';
		}

		$lines = [ 'Lịch hôm nay (' . current_time( 'd/m' ) . '):' ];
		foreach ( $events as $e ) {
			$time = $e['all_day'] ? 'Cả ngày' : date( 'H:i', strtotime( $e['start_at'] ) );
			$badge = '';
			if ( in_array( $e['source'], [ 'ai_plan', 'ai_task', 'ai_memory', 'workflow', 'composite' ], true ) ) {
				$badge = ' [AI]';
			} elseif ( in_array( $e['source'], [ 'google_sync', 'external_sync' ], true ) ) {
				$badge = ' [SYNC]';
			}
			$status_badge = '';
			if ( $e['status'] === 'done' ) {
				$status_badge = ' ✅';
			}
			$lines[] = "- {$time} {$e['title']}{$badge}{$status_badge}";
		}
		return implode( "\n", $lines );
	}

	/* ================================================================
	 *  Helpers
	 * ================================================================ */

	/**
	 * Sanitize and validate event data for insert.
	 *
	 * @return array|\WP_Error
	 */
	private function sanitize_row( array $data ) {
		if ( empty( $data['title'] ) ) {
			return new \WP_Error( 'missing_title', 'Title is required.' );
		}
		if ( empty( $data['start_at'] ) ) {
			return new \WP_Error( 'missing_start', 'Start time is required.' );
		}

		$source = $data['source'] ?? 'user';
		$allowed_sources = [
			'user', 'user_prompt', 'ai_plan', 'ai_task', 'ai_reminder', 'ai_memory',
			'workflow', 'composite', 'google_sync', 'external_sync',
			// Phase 2 (M-CRM.M12 v2) — CRM-originated rows.
			'crm_calendar', 'crm_inbox',
			// PHASE-CG-SCHEDULER v0.2 — Channel Gateway scheduled posts.
			'channel_gateway',
		];

		$event_type = $data['event_type'] ?? 'meeting';
		$allowed_event_types = [
			'meeting', 'workshop', 'training', 'internal', 'personal', 'task', 'reminder',
			// PHASE-CG-SCHEDULER v0.2 — Facebook scheduled post (handled by BizCity_FB_Publisher).
			'fb_post',
		];

		$row = [
			'user_id'       => (int) ( $data['user_id'] ?? get_current_user_id() ),
			'title'         => sanitize_text_field( $data['title'] ),
			'description'   => isset( $data['description'] ) ? wp_kses_post( $data['description'] ) : null,
			'start_at'      => sanitize_text_field( $data['start_at'] ),
			'end_at'        => ! empty( $data['end_at'] ) ? sanitize_text_field( $data['end_at'] ) : null,
			'all_day'       => ! empty( $data['all_day'] ) ? 1 : 0,
			'reminder_min'  => isset( $data['reminder_min'] ) ? absint( $data['reminder_min'] ) : 15,
			'source'        => in_array( $source, $allowed_sources, true ) ? $source : 'user',
			'ai_context'    => isset( $data['ai_context'] ) ? sanitize_text_field( $data['ai_context'] ) : null,
			'status'        => 'active',
			'event_type'    => in_array( $event_type, $allowed_event_types, true ) ? $event_type : 'meeting',
		];

		// metadata: accept array (encode) or pre-encoded JSON string. Anything else dropped.
		if ( isset( $data['metadata'] ) ) {
			if ( is_array( $data['metadata'] ) ) {
				$row['metadata'] = wp_json_encode( $data['metadata'] );
			} elseif ( is_string( $data['metadata'] ) && '' !== $data['metadata'] ) {
				// Validate JSON string before storing.
				json_decode( $data['metadata'] );
				if ( JSON_ERROR_NONE === json_last_error() ) {
					$row['metadata'] = $data['metadata'];
				}
			}
		}

		// Google account binding (Phase 4 will populate; Phase 2 only stores).
		if ( isset( $data['google_account_id'] ) ) {
			$row['google_account_id'] = absint( $data['google_account_id'] ) ?: null;
		}

		// Google Calendar fields — set during sync_from_google()
		if ( ! empty( $data['google_event_id'] ) ) {
			$row['google_event_id']    = sanitize_text_field( $data['google_event_id'] );
			$row['google_calendar_id'] = sanitize_text_field( $data['google_calendar_id'] ?? 'primary' );
			$row['google_synced_at']   = current_time( 'mysql' );
		}

		return $row;
	}

	/**
	 * Public accessor for table name (used by Google sync / Cron).
	 */
	public function get_table(): string {
		return $this->table;
	}
}
