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
 * Manages the `bizcity_scheduler_events` table lifecycle.
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

	const SCHEMA_VERSION     = 2;
	const SCHEMA_VERSION_KEY = 'bizcity_scheduler_schema_ver';

	public static function instance(): self {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'bizcity_scheduler_events';
	}

	/* ================================================================
	 *  Schema
	 * ================================================================ */

	/**
	 * Check schema is up-to-date (autoloaded option — zero extra queries).
	 */
	private function table_ready(): bool {
		return ( (int) get_option( self::SCHEMA_VERSION_KEY, 0 ) ) >= self::SCHEMA_VERSION;
	}

	/**
	 * Install or migrate the schema. Called from activation hook or first use.
	 */
	public function ensure_schema(): void {
		$stored = (int) get_option( self::SCHEMA_VERSION_KEY, 0 );
		if ( $stored >= self::SCHEMA_VERSION ) {
			return;
		}

		$this->migrate( $stored );
		update_option( self::SCHEMA_VERSION_KEY, self::SCHEMA_VERSION, true );
	}

	private function migrate( int $from ): void {
		if ( $from < 1 ) {
			$this->migrate_to_1();
		}

		if ( $from < 2 ) {
			$this->migrate_to_2();
		}
	}

	private function migrate_to_1(): void {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$this->table} (
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

		$wpdb->query( "ALTER TABLE {$this->table} ADD COLUMN reminder_claimed_at DATETIME DEFAULT NULL AFTER reminder_sent" );
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
	 * @param int   $id    Event ID.
	 * @param array $data  Fields to update.
	 * @return true|WP_Error
	 */
	public function update_event( int $id, array $data ) {
		if ( ! $this->table_ready() ) {
			return new \WP_Error( 'no_table', 'Scheduler table not ready.' );
		}

		global $wpdb;

		$old = $this->get_event( $id );
		if ( ! $old ) {
			return new \WP_Error( 'not_found', 'Event not found.' );
		}

		$allowed = [ 'title', 'description', 'start_at', 'end_at', 'all_day', 'reminder_min', 'status', 'source', 'ai_context', 'google_event_id', 'google_calendar_id', 'google_synced_at' ];
		$update  = [];
		foreach ( $allowed as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				$update[ $key ] = $data[ $key ];
			}
		}

		if ( empty( $update ) ) {
			return true;
		}

		$wpdb->update( $this->table, $update, [ 'id' => $id ] );

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
	 */
	public function delete_event( int $id ) {
		if ( ! $this->table_ready() ) {
			return new \WP_Error( 'no_table', 'Scheduler table not ready.' );
		}

		global $wpdb;

		$event = $this->get_event( $id );
		if ( ! $event ) {
			return new \WP_Error( 'not_found', 'Event not found.' );
		}

		$wpdb->delete( $this->table, [ 'id' => $id ], [ '%d' ] );

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
	 */
	public function get_event( int $id ) {
		if ( ! $this->table_ready() ) {
			return null;
		}
		global $wpdb;
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
	public function get_events( int $user_id, string $from, string $to, string $status = 'active' ): array {
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
	 * @return array  Rows where reminder_sent=0 AND now >= start_at - reminder_min.
	 */
	public function get_pending_reminders(): array {
		if ( ! $this->table_ready() ) {
			return [];
		}
		global $wpdb;

		$now = current_time( 'mysql' );

		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$this->table}
			 WHERE reminder_sent = 0
			   AND status = 'active'
			   AND reminder_min > 0
			   AND DATE_SUB(start_at, INTERVAL reminder_min MINUTE) <= %s
			   AND start_at >= %s
			 ORDER BY start_at ASC
			 LIMIT 50",
			$now, $now
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
		$ids        = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM {$this->table}
			 WHERE reminder_sent = 0
			   AND status = 'active'
			   AND reminder_min > 0
			   AND DATE_SUB(start_at, INTERVAL reminder_min MINUTE) <= %s
			   AND start_at >= %s
			   AND (reminder_claimed_at IS NULL OR reminder_claimed_at < %s)
			 ORDER BY start_at ASC
			 LIMIT %d",
			$now,
			$now,
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
		$row = [
			'user_id'       => (int) ( $data['user_id'] ?? get_current_user_id() ),
			'title'         => sanitize_text_field( $data['title'] ),
			'description'   => isset( $data['description'] ) ? wp_kses_post( $data['description'] ) : null,
			'start_at'      => sanitize_text_field( $data['start_at'] ),
			'end_at'        => ! empty( $data['end_at'] ) ? sanitize_text_field( $data['end_at'] ) : null,
			'all_day'       => ! empty( $data['all_day'] ) ? 1 : 0,
			'reminder_min'  => isset( $data['reminder_min'] ) ? absint( $data['reminder_min'] ) : 15,
			'source'        => in_array( $source, [ 'user', 'user_prompt', 'ai_plan', 'ai_task', 'ai_reminder', 'ai_memory', 'workflow', 'composite', 'google_sync', 'external_sync' ], true )
				? $source : 'user',
			'ai_context'    => isset( $data['ai_context'] ) ? sanitize_text_field( $data['ai_context'] ) : null,
			'status'        => 'active',
		];

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
