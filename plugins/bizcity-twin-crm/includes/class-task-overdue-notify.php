<?php
/**
 * PHASE-0.55 A5 — proactive nudges for the member work assistant (§5.6).
 *
 * Two events, both internal Zone 2 pings (no customer name/phone/id, ever):
 *   - `bizcity_crm_task_overdue`  — fired once per task the moment it crosses
 *      its due date (detected by an hourly cron scan; the *scan* runs hourly,
 *      but that is not "the reminder schedule" — D55-4 explicitly leaves the
 *      "when/how often to actually notify someone" decision to the site's own
 *      automation workflow subscribing to this hook or the custom-workflow
 *      webhook below).
 *   - `bizcity_crm_task_reviewed` — fired from `BizCity_CRM_Task_Handoff::leader_action('review', …)`
 *      when a leader marks a `done` task accepted/needs_rework (§5.7).
 *
 * Off by default, same shape as `class-task-handoff-notify.php` (throttle,
 * R-LM-8 chat_id resolution, N-06 custom-workflow escape hatch). A site that
 * wants a fixed "8am roundup" instead of per-event pings should leave both
 * options below OFF and instead call `BizCity_CRM_Task_Overdue_Notify::digest_for_member()`
 * from its own scheduled automation — see `class-twinweb-crm-pipeline-rest.php::get_work_digest()`
 * for the numbers-only REST surface a workflow can poll on its own schedule.
 *
 * @package BizCity_Twin_CRM
 * @since   PHASE-0.55 2026-09-19
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Task_Overdue_Notify', false ) ) {
	return;
}

final class BizCity_CRM_Task_Overdue_Notify {

	const OPTION_ENABLED_OVERDUE  = 'bizcity_crm_task_overdue_zalo_bot';
	const OPTION_ENABLED_REVIEWED = 'bizcity_crm_task_reviewed_zalo_bot';
	const OPTION_WORKFLOW         = 'bizcity_crm_task_overdue_zalo_bot_workflow';
	const THROTTLE_PREFIX         = 'bzc_overdue_notify_';
	const THROTTLE_TTL            = 600; // 10 minutes, same floor as handoff notify §1.5.
	const CRON_HOOK                = 'bizcity_crm_task_overdue_scan';
	const SCAN_BATCH               = 500;

	public static function register(): void {
		add_action( self::CRON_HOOK, array( __CLASS__, 'scan' ), 10, 0 );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::CRON_HOOK );
		}
		add_action( 'bizcity_crm_task_overdue', array( __CLASS__, 'on_overdue' ), 10, 3 );
		add_action( 'bizcity_crm_task_reviewed', array( __CLASS__, 'on_reviewed' ), 10, 4 );
	}

	/**
	 * Detect tasks that just crossed their due date and fire `task_overdue`
	 * exactly once per task (idempotent via the audit log — no new column,
	 * R-CRMX-10). Re-checks `normalize_status()`/`is_overdue()` in PHP rather
	 * than trusting a raw `status IN (...)` filter, because legacy rows use
	 * status values outside the current enum (see `normalize_status()`).
	 */
	public static function scan(): void {
		if ( ! class_exists( 'BizCity_CRM_Task_Handoff' ) || ! class_exists( 'BizCity_CRM_DB_Installer_V2' ) ) {
			return;
		}
		global $wpdb;
		$tasks_tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_tasks();
		$audit_tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_audit_log();
		if ( ! BizCity_CRM_DB_Installer_V2::table_exists( $audit_tbl ) || ! BizCity_CRM_DB_Installer_V2::table_exists( $tasks_tbl ) ) {
			return;
		}
		$today = current_time( 'Y-m-d' );
		$candidates = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, assignee_id, due_date, status, completed FROM `{$tasks_tbl}` "
			. "WHERE deleted_at IS NULL AND assignee_id IS NOT NULL AND assignee_id > 0 "
			. "AND due_date IS NOT NULL AND due_date < %s ORDER BY id DESC LIMIT %d",
			$today,
			self::SCAN_BATCH
		), ARRAY_A );
		if ( empty( $candidates ) ) {
			return;
		}
		$ids = array_map( static function ( $r ) { return (int) $r['id']; }, $candidates );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$already = (array) $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT entity_id FROM `{$audit_tbl}` WHERE entity_type = %s AND action = 'task_overdue' AND entity_id IN ({$placeholders})",
			array_merge( array( BizCity_CRM_Task_Handoff::AUDIT_ENTITY ), $ids )
		) );
		$already = array_map( 'intval', $already );

		foreach ( $candidates as $row ) {
			$task_id = (int) $row['id'];
			if ( in_array( $task_id, $already, true ) ) {
				continue; // already fired once for this task.
			}
			if ( ! in_array( BizCity_CRM_Task_Handoff::normalize_status( $row ), BizCity_CRM_Task_Handoff::OPEN_STATUSES, true ) ) {
				continue; // done/cancelled — not a pending task anymore.
			}
			if ( ! BizCity_CRM_Task_Handoff::is_overdue( $row ) ) {
				continue; // defensive re-check against the same normalization the rest of the app uses.
			}
			// Write the marker first so a slow/failed listener downstream can never cause a repeat fire.
			if ( class_exists( 'BizCity_CRM_Audit_Log' ) ) {
				BizCity_CRM_Audit_Log::log( BizCity_CRM_Task_Handoff::AUDIT_ENTITY, $task_id, 'task_overdue', null, array( 'due_date' => $row['due_date'] ) );
			}
			do_action( 'bizcity_crm_task_overdue', $task_id, (int) $row['assignee_id'], (string) $row['due_date'] );
		}
	}

	public static function on_overdue( $task_id, $assignee_id, $due_date ): void {
		self::maybe_notify( (int) $assignee_id, 'overdue', sprintf( 'Một việc của bạn vừa quá hạn (%s).', self::format_due( (string) $due_date ) ) );
	}

	/** @param string $verdict `accepted`|`needs_rework` (§5.7). */
	public static function on_reviewed( $task_id, $assignee_id, $actor_id, $verdict ): void {
		$verdict = sanitize_key( (string) $verdict );
		$text = 'accepted' === $verdict
			? 'Trưởng nhóm vừa xác nhận một việc bạn báo hoàn thành là đạt yêu cầu.'
			: 'Trưởng nhóm vừa yêu cầu làm lại một việc bạn đã báo hoàn thành.';
		self::maybe_notify( (int) $assignee_id, 'reviewed', $text );
	}

	private static function maybe_notify( int $assignee_id, string $kind, string $text ): void {
		if ( $assignee_id <= 0 || ! self::enabled( $kind, $assignee_id ) || ! self::claim_throttle( $kind, $assignee_id ) ) {
			return;
		}
		$chat_id = self::chat_id_for_user( $assignee_id );
		if ( '' === $chat_id ) {
			return; // R-LM-8: not linked -> no send, no fallback.
		}
		$workspace = apply_filters( 'bizcity_crm_task_handoff_workspace_url', home_url( '/gpt/mytasks/' ) );
		$workflow = self::custom_workflow();
		if ( $workflow ) {
			self::dispatch_custom_workflow( $workflow, $chat_id, $assignee_id, $kind, (string) $workspace );
			return;
		}
		if ( ! class_exists( 'BizCity_Gateway_Sender' ) ) {
			return;
		}
		$result = BizCity_Gateway_Sender::instance()->send(
			$chat_id,
			$text . ' Mở "Việc của tôi" trong ' . esc_url_raw( (string) $workspace ),
			'text',
			array( 'source' => 'crm_task_' . $kind )
		);
		self::log( $assignee_id, $kind, is_array( $result ) && ! empty( $result['sent'] ) );
	}

	/** `['slug' => string, 'secret' => string]` from N-06, or `null` when unset/incomplete → keep the built-in template. */
	private static function custom_workflow(): ?array {
		$cfg = get_option( self::OPTION_WORKFLOW, array() );
		$slug = is_array( $cfg ) ? trim( (string) ( $cfg['slug'] ?? '' ) ) : '';
		$secret = is_array( $cfg ) ? (string) ( $cfg['secret'] ?? '' ) : '';
		return ( '' !== $slug && '' !== $secret ) ? array( 'slug' => $slug, 'secret' => $secret ) : null;
	}

	/** Same escape hatch as `class-task-handoff-notify.php` N-06: hand off to the site's own workflow. */
	private static function dispatch_custom_workflow( array $workflow, string $chat_id, int $assignee_id, string $kind, string $workspace ): void {
		if ( ! class_exists( 'BizCity_Automation_Trigger_Matcher' ) ) {
			return;
		}
		$result = BizCity_Automation_Trigger_Matcher::instance()->dispatch_webhook(
			$workflow['slug'],
			array(
				'chat_id'           => $chat_id,
				'recipient_user_id' => $assignee_id,
				'kind'              => 'task_' . $kind,
				'link'              => esc_url_raw( $workspace ),
			),
			$workflow['secret']
		);
		self::log( $assignee_id, $kind, ! is_wp_error( $result ) && ! empty( $result['ok'] ), is_wp_error( $result ) ? $result->get_error_code() : '' );
	}

	private static function log( int $assignee_id, string $kind, bool $sent, string $error_code = '' ): void {
		if ( ! class_exists( 'BizCity_Channel_File_Logger' ) || ! defined( 'BizCity_Channel_File_Logger::CH_ZALO_BOT' ) ) {
			return;
		}
		$detail = array( 'assignee_user_id' => $assignee_id, 'kind' => $kind, 'sent' => $sent ? 1 : 0 );
		if ( '' !== $error_code ) { $detail['error_code'] = $error_code; }
		BizCity_Channel_File_Logger::write( BizCity_Channel_File_Logger::CH_ZALO_BOT, BizCity_Channel_File_Logger::LEVEL_INFO, 'crm_task_' . $kind . '_notified', 'Internal proactive task ping.', $detail );
	}

	/** Off by default; site option + per-assignee filter, same pattern as handoff notify. */
	private static function enabled( string $kind, int $assignee_id ): bool {
		$option = 'reviewed' === $kind ? self::OPTION_ENABLED_REVIEWED : self::OPTION_ENABLED_OVERDUE;
		$enabled = (bool) get_option( $option, false );
		return (bool) apply_filters( $option, $enabled, $assignee_id );
	}

	private static function claim_throttle( string $kind, int $assignee_id ): bool {
		$key = self::THROTTLE_PREFIX . $kind . '_' . $assignee_id;
		if ( false !== get_transient( $key ) ) {
			return false;
		}
		set_transient( $key, time(), self::THROTTLE_TTL );
		return true;
	}

	private static function format_due( string $due_date ): string {
		$parts = explode( '-', substr( $due_date, 0, 10 ) );
		return 3 === count( $parts ) ? sprintf( '%s/%s', $parts[2], $parts[1] ) : $due_date;
	}

	/** @see class-task-handoff-notify.php::chat_id_for_user() — same resolution order (R-LM-8). */
	private static function chat_id_for_user( int $user_id ): string {
		if ( class_exists( 'BizCity_Channel_User_Linker' ) && method_exists( 'BizCity_Channel_User_Linker', 'zalo_bot_target_for_user' ) ) {
			$target = BizCity_Channel_User_Linker::zalo_bot_target_for_user( $user_id );
			return (string) ( $target['chat_id'] ?? '' );
		}
		return '';
	}
}
