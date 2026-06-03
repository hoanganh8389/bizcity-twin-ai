<?php
/**
 * BizCity_Automation_CRM_Bridge — canonical adapter from automation payload
 * → core/scheduler `bizcity_crm_events` row.
 *
 * Why: legacy code in automation-runner + create_crm_event block fired the
 * filter `bizcity_crm_event_create_filter` / action `bizcity_crm_event_create`,
 * but no listener exists on either inside this codebase. As a result, no
 * scheduler row was ever inserted for automation runs / FB posts. This bridge
 * calls `BizCity_Scheduler_Manager::create_event()` directly using the same
 * sanitisation contract as the canonical scheduler REST + tools.
 *
 * Field mapping (legacy → canonical):
 *   - due_at   → start_at
 *   - body     → description
 *   - status (int 1=ok / 3=fail) → status (string active|done|cancelled)
 *   - event_type='automation_run' → 'task' (not in scheduler whitelist)
 *
 * `metadata` always merges `{workflow_id, related_id}` so Scheduler page can
 * pivot back to the run.
 *
 * R-CH compliant. No direct Graph / wp_insert_post calls.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Automation
 * @since      AUTOMATION BE-7.D (2026-05-30)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Automation_CRM_Bridge {

	/** event_type values accepted by Scheduler_Manager::sanitize_row(). */
	const ALLOWED_EVENT_TYPES = array(
		'meeting', 'workshop', 'training', 'internal', 'personal', 'task', 'reminder',
		'fb_post', 'web_post', 'reminder_zalo',
		'woo_product_create', 'woo_product_edit', 'woo_order_create', 'lead_report',
	);

	/** source values accepted by Scheduler_Manager::sanitize_row(). */
	const ALLOWED_SOURCES = array(
		'user', 'user_prompt', 'ai_plan', 'ai_task', 'ai_reminder', 'ai_memory',
		'workflow', 'composite', 'google_sync', 'external_sync',
		'crm_calendar', 'crm_inbox', 'channel_gateway',
	);

	/**
	 * Create a CRM event from automation-shaped payload.
	 *
	 * @param array $payload See class doc-block for accepted keys.
	 * @return int Event ID (0 on failure).
	 */
	public static function create_event( array $payload ): int {
		// ── Map start_at ─────────────────────────────────────────────────
		$start_raw = (string) ( $payload['start_at'] ?? $payload['due_at'] ?? '' );
		if ( $start_raw === '' ) {
			$start_at = current_time( 'mysql' );
		} else {
			$ts       = strtotime( $start_raw );
			$start_at = $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : current_time( 'mysql' );
		}

		// ── Map status ───────────────────────────────────────────────────
		$status_raw = $payload['status'] ?? 'active';
		if ( is_int( $status_raw ) || ctype_digit( (string) $status_raw ) ) {
			$n      = (int) $status_raw;
			$status = $n === 2 ? 'done' : ( $n === 3 ? 'cancelled' : 'active' );
		} else {
			$s      = strtolower( (string) $status_raw );
			$status = in_array( $s, array( 'active', 'draft', 'done', 'cancelled' ), true ) ? $s : 'active';
		}

		// ── Map event_type ───────────────────────────────────────────────
		$event_type = (string) ( $payload['event_type'] ?? 'task' );
		if ( ! in_array( $event_type, self::ALLOWED_EVENT_TYPES, true ) ) {
			// 'automation_run' / 'lead_capture' / unknown → coerce to 'task'.
			$event_type = 'task';
		}

		// ── Source ───────────────────────────────────────────────────────
		$source = (string) ( $payload['source'] ?? 'workflow' );
		if ( ! in_array( $source, self::ALLOWED_SOURCES, true ) ) {
			$source = 'workflow';
		}

		// ── Metadata merge (workflow back-link is mandatory) ─────────────
		$metadata = array();
		if ( isset( $payload['metadata'] ) && is_array( $payload['metadata'] ) ) {
			$metadata = $payload['metadata'];
		}
		if ( ! empty( $payload['workflow_id'] ) ) {
			$metadata['workflow_id'] = (int) $payload['workflow_id'];
		}
		if ( ! empty( $payload['related_id'] ) ) {
			$metadata['run_id'] = (string) $payload['related_id'];
		}

		$row = array(
			'user_id'      => (int) ( $payload['user_id'] ?? get_current_user_id() ),
			'title'        => (string) ( $payload['title'] ?? '[automation] event' ),
			'description'  => (string) ( $payload['description'] ?? $payload['body'] ?? '' ),
			'start_at'     => $start_at,
			'end_at'       => ! empty( $payload['end_at'] ) ? (string) $payload['end_at'] : null,
			'all_day'      => ! empty( $payload['all_day'] ) ? 1 : 0,
			'reminder_min' => isset( $payload['reminder_min'] ) ? max( 0, (int) $payload['reminder_min'] ) : 0,
			'event_type'   => $event_type,
			'status'       => $status,
			'source'       => $source,
			'metadata'     => $metadata,
		);

		if ( class_exists( 'BizCity_Scheduler_Manager' ) ) {
			$id = BizCity_Scheduler_Manager::instance()->create_event( $row );
			if ( is_int( $id ) ) {
				// Fire legacy hook for any external listener (idempotent shadow).
				do_action( 'bizcity_crm_event_create', array_merge( $payload, array( 'event_id' => $id ) ) );
				return $id;
			}
			if ( is_wp_error( $id ) ) {
				error_log( '[automation] CRM bridge create_event failed: ' . $id->get_error_message() );
			}
		}

		// Last-resort legacy fallback (allows external CRM plugin to handle).
		$event_id = apply_filters( 'bizcity_crm_event_create_filter', null, $payload );
		do_action( 'bizcity_crm_event_create', $payload );
		return is_numeric( $event_id ) ? (int) $event_id : 0;
	}
}
