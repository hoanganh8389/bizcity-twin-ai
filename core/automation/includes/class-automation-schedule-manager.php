<?php
/**
 * BizCity_Automation_Schedule_Manager
 *
 * ─── Cache Contract ─────────────────────────────────────────────────────────
 * group : 'auto'
 * keys  : 'sched_events_<wf_id>'  → upcoming events for a workflow
 * TTL   : 120 s
 * flush : sync_workflow_events(), cancel_workflow_events(), mark_event_done()
 * ────────────────────────────────────────────────────────────────────────────
 *
 * Bridges Automation workflows (trigger.cron) with bizcity_crm_events so the
 * Automation Calendar UI can read/manage upcoming runs.
 *
 * Responsibilities:
 *  1. sync_workflow_events()  — generate next N occurrences into crm_events.
 *  2. cancel_workflow_events() — cancel all 'active' events for a workflow.
 *  3. mark_event_done()       — called by on_cron_scan after successful fire.
 *  4. cron_next_timestamps()  — pure: parse cron + return N next timestamps.
 *
 * PHP 7.4 compatible (no union types, no nullsafe, no str_contains).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Automation
 * @since      AUTOMATION-CAL (2026-06-14)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Automation_Schedule_Manager {

	const CACHE_GROUP  = 'auto';
	const EVENT_TYPE   = 'automation_workflow';
	const EVENT_SOURCE = 'workflow';

	// Maximum occurrences to pre-create per workflow.
	const DEFAULT_OCCURRENCES = 30;

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	// ─── Public API ──────────────────────────────────────────────────────

	/**
	 * Sync upcoming events for a workflow that has a cron trigger.
	 *
	 * Call this after workflow create, update, or enable/disable toggle.
	 *
	 * @param array $workflow Full workflow row.
	 */
	public function sync_workflow_events( array $workflow ) {
		// [2026-06-14 Johnny Chu] AUTOMATION-CAL — sync crm_events for cron workflow
		$wf_id   = (int) $workflow['id'];
		$enabled = (int) ( $workflow['enabled'] ?? 0 );
		$ttype   = (string) ( $workflow['trigger_type'] ?? '' );

		// Only trigger.cron workflows need event rows.
		if ( $ttype !== 'cron' ) {
			return;
		}

		$cfg      = array();
		$cfg_raw  = $workflow['trigger_config_json'] ?? ( $workflow['trigger_config'] ?? '' );
		if ( is_array( $cfg_raw ) ) {
			$cfg = $cfg_raw;
		} elseif ( is_string( $cfg_raw ) && $cfg_raw !== '' ) {
			$decoded = json_decode( $cfg_raw, true );
			if ( is_array( $decoded ) ) {
				$cfg = $decoded;
			}
		}
		$schedule = (string) ( $cfg['schedule'] ?? '' );
		if ( $schedule === '' ) {
			return;
		}

		// Cancel existing pending events first (safe re-sync).
		$this->cancel_workflow_events( $wf_id );

		if ( ! $enabled ) {
			// Workflow disabled — just cancel, no new events.
			BizCity_Cache::flush_group( self::CACHE_GROUP );
			return;
		}

		// Generate N timestamps and insert.
		$timestamps = $this->cron_next_timestamps( $schedule, self::DEFAULT_OCCURRENCES );
		if ( empty( $timestamps ) ) {
			return;
		}

		$scheduler = $this->get_scheduler();
		if ( ! $scheduler ) {
			return;
		}

		$wf_name = (string) ( $workflow['name'] ?? ( 'Workflow #' . $wf_id ) );
		$user_id = (int) ( $workflow['created_by'] ?? get_current_user_id() );

		foreach ( $timestamps as $idx => $ts ) {
			$meta = array(
				'workflow_id'   => $wf_id,
				'workflow_name' => $wf_name,
				'cron_expr'     => $schedule,
				'recurrence'    => 'cron',
				'occurrence'    => $idx + 1,
				'run_status'    => 'pending',
				'inbound'       => array(
					'platform'  => 'ADMIN',
					'chat_id'   => '',
					'user_id'   => (string) $user_id,
					'intent_tag'=> 'workflow_cron',
				),
			);
			$scheduler->create_event( array(
				'user_id'    => $user_id,
				'title'      => $wf_name,
				'start_at'   => gmdate( 'Y-m-d H:i:s', $ts ),
				'status'     => 'active',
				'event_type' => self::EVENT_TYPE,
				'source'     => self::EVENT_SOURCE,
				// [2026-06-14 Johnny Chu] GAP-1 — fire AT start_at, not 15 min early (default).
				'reminder_min' => 0,
				'metadata'   => $meta,
			) );
		}

		BizCity_Cache::flush_group( self::CACHE_GROUP );
	}

	/**
	 * Cancel all pending events for a workflow (set status → 'cancelled').
	 *
	 * @param int $wf_id Workflow ID.
	 */
	public function cancel_workflow_events( int $wf_id ) {
		// [2026-06-14 Johnny Chu] AUTOMATION-CAL — cancel crm_events on disable/delete
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_crm_events';
		if ( ! $this->table_exists( $table ) ) {
			return;
		}

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$table}` SET status = 'cancelled', updated_at = %s
				  WHERE event_type = %s
				    AND source = %s
				    AND status = 'active'
				    AND JSON_EXTRACT(metadata, '$.workflow_id') = %d",
				current_time( 'mysql' ),
				self::EVENT_TYPE,
				self::EVENT_SOURCE,
				$wf_id
			)
		);

		BizCity_Cache::flush_group( self::CACHE_GROUP );
	}

	/**
	 * Mark the next pending event for a workflow as done.
	 * Called by BizCity_Automation_Trigger_Matcher::on_cron_scan after firing.
	 *
	 * @param int $wf_id    Workflow ID (used to find event when event_id=0).
	 * @param int $event_id Optional: exact crm_events.id — skips lookup query.
	 */
	public function mark_event_done( int $wf_id, int $event_id = 0 ) {
		// [2026-06-14 Johnny Chu] GAP-2/GAP-3 — mark crm_event done; merge metadata (not replace); accept event_id to skip lookup
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_crm_events';
		if ( ! $this->table_exists( $table ) ) {
			return;
		}

		$now = current_time( 'mysql' );

		// Resolve the row to update.
		if ( $event_id > 0 ) {
			// Caller provided exact ID (from on_scheduler_fire) — use directly.
			$row_id = $event_id;
		} else {
			// Find the earliest active event at or before now for this workflow.
			$found = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id FROM `{$table}`
					  WHERE event_type = %s
					    AND source = %s
					    AND status = 'active'
					    AND JSON_EXTRACT(metadata, '$.workflow_id') = %d
					    AND start_at <= %s
					  ORDER BY start_at ASC
					  LIMIT 1",
					self::EVENT_TYPE,
					self::EVENT_SOURCE,
					$wf_id,
					$now
				)
			);
			if ( ! $found ) {
				BizCity_Cache::flush_group( self::CACHE_GROUP );
				return;
			}
			$row_id = (int) $found->id;
		}

		$scheduler = $this->get_scheduler();
		if ( ! $scheduler ) {
			BizCity_Cache::flush_group( self::CACHE_GROUP );
			return;
		}

		// [2026-06-14 Johnny Chu] GAP-2 — read existing metadata, MERGE, then update.
		// R-SCH-REPLY: NEVER replace metadata wholesale — decode → merge → encode.
		$existing_event = $scheduler->get_event( $row_id );
		$existing_meta  = array();
		if ( $existing_event && ! empty( $existing_event->metadata ) ) {
			$decoded = json_decode( $existing_event->metadata, true );
			if ( is_array( $decoded ) ) {
				$existing_meta = $decoded;
			}
		}
		$merged_meta = array_merge( $existing_meta, array(
			'run_status' => 'done',
			'done_at'    => $now,
		) );

		$scheduler->update_event( $row_id, array(
			'status'   => 'done',
			'metadata' => $merged_meta,
		) );

		BizCity_Cache::flush_group( self::CACHE_GROUP );
	}

	/**
	 * Get upcoming events for a workflow from the cache or DB.
	 *
	 * @param int $wf_id     Workflow ID.
	 * @param int $limit     Max rows.
	 * @return array
	 */
	public function get_events_for_workflow( int $wf_id, int $limit = 60 ) {
		$cache_key = 'sched_events_' . $wf_id;
		$cached    = BizCity_Cache::get( self::CACHE_GROUP, $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$scheduler = $this->get_scheduler();
		if ( ! $scheduler ) {
			return array();
		}

		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_crm_events';
		if ( ! $this->table_exists( $table ) ) {
			return array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$table}`
				  WHERE event_type = %s
				    AND source = %s
				    AND JSON_EXTRACT(metadata, '$.workflow_id') = %d
				  ORDER BY start_at ASC
				  LIMIT %d",
				self::EVENT_TYPE,
				self::EVENT_SOURCE,
				$wf_id,
				$limit
			),
			ARRAY_A
		);

		$result = is_array( $rows ) ? $rows : array();
		BizCity_Cache::set( self::CACHE_GROUP, $cache_key, $result, 120 );
		return $result;
	}

	// ─── Cron expression parser ───────────────────────────────────────────

	/**
	 * Parse a cron expression and return the next N Unix timestamps from $start.
	 *
	 * Supports standard 5-field cron: min hour dom month dow.
	 * No year field. No special strings (@hourly etc) — keep it simple.
	 *
	 * @param string $expr    Cron expression e.g. "0 9 * * *".
	 * @param int    $count   Number of occurrences to return.
	 * @param int    $start   Unix timestamp to start searching from (default: now).
	 * @return int[]          Array of Unix timestamps.
	 */
	public function cron_next_timestamps( string $expr, int $count = 30, int $start = 0 ) {
		// [2026-06-14 Johnny Chu] AUTOMATION-CAL — cron expression parser (5-field)
		if ( $start <= 0 ) {
			$start = time();
		}

		$parts = preg_split( '/\s+/', trim( $expr ), 5 );
		if ( ! is_array( $parts ) || count( $parts ) < 5 ) {
			return array();
		}

		list( $f_min, $f_hour, $f_dom, $f_month, $f_dow ) = $parts;

		$results = array();
		// Start from next minute boundary.
		$cursor = $start - ( $start % 60 ) + 60;

		// Safety: max 60*24*365 = 525600 iterations (~1 year look-ahead).
		$max_iter = 525600;
		$iter     = 0;

		while ( count( $results ) < $count && $iter < $max_iter ) {
			$iter++;
			$m   = (int) gmdate( 'i', $cursor );
			$h   = (int) gmdate( 'G', $cursor );
			$dom = (int) gmdate( 'j', $cursor );
			$mon = (int) gmdate( 'n', $cursor );
			$dow = (int) gmdate( 'w', $cursor ); // 0=Sun…6=Sat

			if (
				$this->cron_field_matches( $f_month, $mon, 1, 12 ) &&
				$this->cron_field_matches( $f_dom,   $dom, 1, 31 ) &&
				$this->cron_field_matches( $f_dow,   $dow, 0, 6 ) &&
				$this->cron_field_matches( $f_hour,  $h,   0, 23 ) &&
				$this->cron_field_matches( $f_min,   $m,   0, 59 )
			) {
				$results[] = $cursor;
				// Skip to next minute to avoid duplicate.
				$cursor += 60;
				continue;
			}

			$cursor += 60;
		}

		return $results;
	}

	/**
	 * Check whether a single cron field matches a value.
	 *
	 * Supports: * / step / list / range (a-b) / range+step (a-b/c).
	 *
	 * @param string $field  Field string from cron expression.
	 * @param int    $value  Current value.
	 * @param int    $min    Field minimum.
	 * @param int    $max    Field maximum.
	 * @return bool
	 */
	private function cron_field_matches( string $field, int $value, int $min, int $max ) {
		// Handle comma-separated lists.
		if ( strpos( $field, ',' ) !== false ) {
			foreach ( explode( ',', $field ) as $part ) {
				if ( $this->cron_field_matches( trim( $part ), $value, $min, $max ) ) {
					return true;
				}
			}
			return false;
		}

		// Handle step: */N or a-b/N.
		if ( strpos( $field, '/' ) !== false ) {
			$sub   = explode( '/', $field, 2 );
			$range = $sub[0];
			$step  = max( 1, (int) $sub[1] );
			if ( $range === '*' || $range === '' ) {
				return ( ( $value - $min ) % $step ) === 0;
			}
			// Range/step e.g. 0-30/5
			if ( strpos( $range, '-' ) !== false ) {
				$bounds = explode( '-', $range, 2 );
				$lo     = (int) $bounds[0];
				$hi     = (int) $bounds[1];
				return $value >= $lo && $value <= $hi && ( ( $value - $lo ) % $step ) === 0;
			}
			return false;
		}

		// Wildcard.
		if ( $field === '*' ) {
			return true;
		}

		// Range a-b.
		if ( strpos( $field, '-' ) !== false ) {
			$bounds = explode( '-', $field, 2 );
			$lo     = (int) $bounds[0];
			$hi     = (int) $bounds[1];
			return $value >= $lo && $value <= $hi;
		}

		// Exact value.
		return (int) $field === $value;
	}

	// ─── Helpers ─────────────────────────────────────────────────────────

	private function get_scheduler() {
		if ( ! class_exists( 'BizCity_Scheduler_Manager' ) ) {
			return null;
		}
		return BizCity_Scheduler_Manager::instance();
	}

	private function table_exists( string $table ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}
}
