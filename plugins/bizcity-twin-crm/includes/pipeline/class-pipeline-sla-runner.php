<?php
/**
 * BizCity CRM — SLA runner (PHASE-0.63A WP-3.6/3.7/3.8). **LANE A OWNS THIS FILE.**
 *
 * Register through `BizCity_Cron_Manager::register()` (never a bare
 * `wp_schedule_event`), take `try_lock()` at job level, claim rows with the UPDATE…WHERE pattern of
 * `class-scheduler-manager.php:753-822`, batch 100, and note every fire. The hot query is fixed by
 * WP-3.6 so it keeps using `idx_due`:
 *
 *     SELECT * FROM {deadlines}
 *      WHERE state IN ('pending','at_risk') AND next_fire_at IS NOT NULL AND next_fire_at <= %s
 *      ORDER BY next_fire_at ASC LIMIT 100
 *
 * A missed deadline never changes a stage — it flags, notifies, escalates, asks for reassignment or
 * emits. Advancing work because a clock ran out is the one behaviour this engine may not have.
 *
 * @package BizCity_Twin_CRM
 * @since 2026-09-21 (PHASE-0.63A WP-3, stub)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Pipeline_SLA_Runner', false ) ) {
	return;
}

final class BizCity_CRM_Pipeline_SLA_Runner {

	const JOB_ID   = 'crm_pipeline_sla_runner';
	const HOOK     = 'bizcity_crm_pipeline_sla_tick';
	const INTERVAL = 'bizcity_every_minute';
	const BATCH    = 100;

	/** Register the 60s job and its callback. */
	public static function register(): void {
		// [2026-09-21 07:30 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.63A WP-3.6 — register through Cron Manager only.
		add_action( self::HOOK, array( __CLASS__, 'tick' ) );
		if ( class_exists( 'BizCity_Cron_Manager' ) ) {
			BizCity_Cron_Manager::instance()->register( array(
				'id'          => self::JOB_ID,
				'hook'        => self::HOOK,
				'interval'    => self::INTERVAL,
				'owner'       => 'bizcity-twin-crm',
				'description' => 'Scan pipeline SLA deadlines and fire one escalation rung (PHASE-0.63A WP-3.6)',
				'singleton'   => true,
				'enabled'     => true,
				'retention'   => 14,
			) );
		}
	}

	/** One pass over the due queue. @return array|WP_Error */
	public static function tick() {
		// [2026-09-21 07:30 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.63A WP-3.6 — job lock, bounded claim, and cron evidence.
		$cron = class_exists( 'BizCity_Cron_Manager' ) ? BizCity_Cron_Manager::instance() : null;
		if ( $cron && method_exists( $cron, 'is_locked_out' ) && $cron->is_locked_out( self::JOB_ID ) ) {
			return array( 'scanned' => 0, 'claimed' => 0, 'fired' => 0, 'skipped' => 1 );
		}
		if ( $cron && method_exists( $cron, 'try_lock' ) && ! $cron->try_lock( self::JOB_ID, 90 ) ) {
			return array( 'scanned' => 0, 'claimed' => 0, 'fired' => 0, 'skipped' => 1 );
		}
		$worker = self::worker_id();
		$claimed = self::claim_due( $worker, self::BATCH );
		if ( is_wp_error( $claimed ) ) {
			if ( $cron && method_exists( $cron, 'note' ) ) {
				$cron->note( array( 'counters' => array( 'scanned' => 0, 'claimed' => 0, 'fired' => 0, 'errors' => 1 ) ) );
				$cron->note_event( 'pipeline_sla_tick_failed', array( 'reason' => $claimed->get_error_code() ) );
			}
			return $claimed;
		}
		$counts = array( 'scanned' => count( $claimed ), 'claimed' => count( $claimed ), 'fired' => 0, 'errors' => 0 );
		foreach ( $claimed as $deadline ) {
			$result = self::fire( $deadline );
			if ( is_wp_error( $result ) ) {
				$counts['errors']++;
				self::release_claim( (int) $deadline['id'], $result->get_error_code() );
				if ( $cron && method_exists( $cron, 'note_event' ) ) {
					$cron->note_event( 'pipeline_sla_fire_failed', array( 'deadline_id' => (int) $deadline['id'], 'reason' => $result->get_error_code() ) );
				}
				continue;
			}
			$counts['fired']++;
			if ( $cron && method_exists( $cron, 'note_event' ) ) {
				$cron->note_event( 'pipeline_sla_rung_fired', array( 'deadline_id' => (int) $deadline['id'], 'run_id' => (int) $deadline['run_id'], 'rule_id' => (string) $deadline['rule_id'] ) );
			}
		}
		if ( $cron && method_exists( $cron, 'note' ) ) {
			$cron->note( array( 'counters' => $counts ) );
		}
		return $counts;
	}

	/** Claim a batch with an atomic lease. @return array|WP_Error */
	public static function claim_due( string $worker, int $limit = self::BATCH ) {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_results' ) || ! method_exists( $wpdb, 'query' ) ) {
			return self::error( 'db_unavailable', 'Hàng đợi SLA chưa sẵn sàng.', 503 );
		}
		$limit = max( 1, min( self::BATCH, (int) $limit ) );
		$table = self::deadlines_table();
		$now = self::db_now();
		$stale = self::db_time( self::now_ts() - 600 );
		// Keep this range query aligned with idx_due (PHASE-0.63A WP-3.6 hot query).
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table}
			 WHERE state IN ('pending','at_risk') AND next_fire_at IS NOT NULL AND next_fire_at <= %s
			 ORDER BY next_fire_at ASC LIMIT %d",
			$now,
			$limit
		), ARRAY_A );
		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$id = (int) ( $row['id'] ?? 0 );
			if ( $id <= 0 ) { continue; }
			$changed = $wpdb->query( $wpdb->prepare(
				"UPDATE `{$table}` SET claimed_by = %s, claimed_at = %s, attempts = attempts + 1, updated_at = %s
				 WHERE id = %d AND state IN ('pending','at_risk') AND next_fire_at IS NOT NULL AND next_fire_at <= %s
				 AND ( claimed_at IS NULL OR claimed_at < %s )",
				$worker,
				$now,
				$now,
				$id,
				$now,
				$stale
			) );
			if ( 1 === (int) $changed ) {
				$row['claimed_by'] = $worker;
				$row['claimed_at'] = $now;
				$out[] = $row;
			}
		}
		return $out;
	}

	/** Run one rung's action list. @return array|WP_Error */
	public static function fire( array $deadline ) {
		// [2026-09-21 07:30 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.63A WP-3.7 — fire only declared actions; never advance a stage.
		$run_id = (int) ( $deadline['run_id'] ?? 0 );
		$definition = self::definition_for_deadline( $deadline );
		$rule = self::rule_for_deadline( $definition, (string) ( $deadline['rule_id'] ?? '' ) );
		if ( ! $rule ) {
			return self::error( 'sla_rule_not_found', 'Không tìm thấy luật SLA đã ghim.', 409 );
		}
		// [2026-09-21 09:15 PM OpenAI GPT-5.6 Luna] PHASE-0.63A WP-3.6 — normalize persisted DATETIME values before calculating rung order.
		$anchor_ts = isset( $deadline['anchor_ts'] ) ? (int) $deadline['anchor_ts'] : self::parse_db_time( (string) ( $deadline['anchor_at'] ?? '' ) );
		$due_ts    = isset( $deadline['due_ts'] ) ? (int) $deadline['due_ts'] : self::parse_db_time( (string) ( $deadline['due_at'] ?? '' ) );
		$ladder = self::ordered_ladder( $rule['on_miss'] ?? array(), $anchor_ts, $due_ts );
		$level = (int) ( $deadline['level'] ?? 0 );
		$rung = $ladder[ $level ] ?? array( 'at' => '0', 'do' => 'flag(breached)' );
		// [2026-09-23] Use the run service's role context (owner/creator/stage-assignee) instead of
		// collapsing everything onto `owner_id` — `owner_of_run`/`creator_of_run`/`assignee_of_stage`
		// each need their own value, and assignee should be whoever worked THIS stage, not just the owner.
		$ctx = class_exists( 'BizCity_CRM_Pipeline_Run_Service' ) && method_exists( 'BizCity_CRM_Pipeline_Run_Service', 'role_context' )
			? BizCity_CRM_Pipeline_Run_Service::role_context( $run_id, (string) ( $deadline['stage_key'] ?? '' ) )
			: array( 'run_id' => $run_id, 'stage_key' => (string) ( $deadline['stage_key'] ?? '' ), 'owner_id' => self::run_assignee_id( $run_id ), 'creator_id' => 0, 'assignee_id' => self::run_assignee_id( $run_id ) );
		$deadline['assignee_id'] = $ctx['assignee_id'];
		$deadline['owner_id'] = $ctx['owner_id'];
		foreach ( preg_split( '/\s*\+\s*/', (string) ( $rung['do'] ?? '' ) ) as $action ) {
			$result = self::dispatch_action( trim( $action ), $definition, $ctx, $deadline );
			if ( is_wp_error( $result ) ) { return $result; }
		}
		$next = $ladder[ $level + 1 ] ?? null;
		$at = (string) ( $rung['at'] ?? '0' );
		$breach = '0' === $at || 0 === strpos( $at, '+' );
		if ( '0' === $at ) {
			self::report_sla_metric( $run_id, 'pipeline_sla_breached', (string) ( $deadline['rule_id'] ?? '' ) );
		}
		$now = self::db_now();
		$state = $next ? 'at_risk' : ( $breach ? 'breached' : 'at_risk' );
		$next_fire = null;
		if ( $next ) {
			$next_fire = self::rung_time( $anchor_ts, $due_ts, (string) ( $next['at'] ?? '0' ) );
		}
		global $wpdb;
		$table = self::deadlines_table();
		$fields = array( 'level' => $level + 1, 'state' => $state, 'next_fire_at' => $next_fire, 'claimed_by' => null, 'claimed_at' => null, 'updated_at' => $now );
		if ( $breach && empty( $deadline['breached_at'] ) ) { $fields['breached_at'] = $now; }
		$updated = $wpdb->update( $table, $fields, array( 'id' => (int) $deadline['id'], 'claimed_by' => (string) ( $deadline['claimed_by'] ?? '' ) ) );
		if ( false === $updated ) { return self::error( 'sla_deadline_update_failed', 'Không thể cập nhật trạng thái SLA.', 500 ); }
		if ( 0 === (int) $updated ) { return self::error( 'sla_claim_lost', 'Lease SLA đã hết hạn hoặc đã được worker khác nhận.', 409 ); }
		return array( 'deadline_id' => (int) $deadline['id'], 'level' => $level + 1, 'state' => $state, 'next_fire_at' => $next_fire );
	}

	private static function run_assignee_id( int $run_id ): int {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) { return 0; }
		$table = class_exists( 'BizCity_CRM_DB_Installer_V2' ) ? BizCity_CRM_DB_Installer_V2::tbl_crm_opportunities() : $wpdb->prefix . 'bizcity_crm_opportunities';
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT owner_id FROM `{$table}` WHERE id = %d LIMIT 1", $run_id ) );
	}

	private static function report_sla_metric( int $run_id, string $metric, string $rule_id ): void {
		if ( ! class_exists( 'BizCity_CRM_Reporting_Rollup' ) ) { return; }
		BizCity_CRM_Reporting_Rollup::record_fact( $metric, 0, gmdate( 'Y-m-d H:i:s' ), 1, 'pipeline-sla:' . $run_id . ':' . $rule_id . ':' . $metric . ':' . gmdate( 'YmdHi' ) );
	}

	private static function dispatch_action( string $action, array $definition, array $ctx, array $deadline ) {
		if ( '' === $action || ! preg_match( '/^([a-z_]+)\(([^()]*)\)$/', $action, $match ) ) { return self::error( 'sla_action_invalid', 'Hành động SLA không hợp lệ.', 422 ); }
		$name = $match[1];
		$argument = trim( $match[2] );
		if ( 'flag' === $name ) { return true; }
		if ( 'emit' === $name ) { do_action( 'bizcity_crm_pipeline_sla_' . sanitize_key( $argument ), $deadline, $ctx ); return true; }
		if ( 'reassign' === $name ) { do_action( 'bizcity_crm_pipeline_sla_reassign', $argument, $deadline, $ctx ); return true; }
		if ( in_array( $name, array( 'notify', 'escalate' ), true ) ) {
			$recipients = class_exists( 'BizCity_CRM_Pipeline_Roles' ) ? BizCity_CRM_Pipeline_Roles::resolve_recipients( $definition, $argument, $ctx ) : array();
			if ( is_wp_error( $recipients ) ) { return $recipients; }
			if ( class_exists( 'BizCity_CRM_Pipeline_Notify' ) ) {
				return 'escalate' === $name ? BizCity_CRM_Pipeline_Notify::escalate( $recipients, $deadline ) : BizCity_CRM_Pipeline_Notify::notify( $recipients, $deadline );
			}
			return true;
		}
		if ( class_exists( 'BizCity_CRM_Pipeline_Registry' ) && isset( BizCity_CRM_Pipeline_Registry::sla_actions()[ $name ] ) ) {
			do_action( 'bizcity_crm_pipeline_sla_action_' . sanitize_key( $name ), $argument, $deadline, $ctx );
			return true;
		}
		return self::error( 'sla_action_unregistered', 'Hành động SLA chưa được đăng ký.', 422 );
	}

	private static function definition_for_deadline( array $deadline ): array { $kind = (string) ( $deadline['pipeline_kind'] ?? '' ); $version = 0; global $wpdb; if ( is_object( $wpdb ) && method_exists( $wpdb, 'get_var' ) && method_exists( $wpdb, 'prepare' ) ) { $table = class_exists( 'BizCity_CRM_DB_Installer_V2' ) ? BizCity_CRM_DB_Installer_V2::tbl_crm_opportunities() : $wpdb->prefix . 'bizcity_crm_opportunities'; $version = (int) $wpdb->get_var( $wpdb->prepare( "SELECT pipeline_def_version FROM `{$table}` WHERE id = %d", (int) ( $deadline['run_id'] ?? 0 ) ) ); } $definition = class_exists( 'BizCity_CRM_Pipeline_Registry' ) ? BizCity_CRM_Pipeline_Registry::get_version( $kind, $version ) : null; return is_array( $definition ) ? $definition : array( 'kind' => $kind ); }
	private static function rule_for_deadline( array $definition, string $id ): ?array { $rules = array(); foreach ( (array) ( $definition['rules'] ?? array() ) as $rule ) { if ( is_array( $rule ) ) { $rules[] = $rule; } } if ( is_array( $definition['process_sla'] ?? null ) ) { $rules[] = array_merge( $definition['process_sla'], array( 'layer' => 'process' ) ); } foreach ( (array) ( $definition['stages'] ?? array() ) as $stage ) { if ( ! is_array( $stage ) ) { continue; } $key = (string) ( $stage['key'] ?? '' ); $sla = is_array( $stage['sla'] ?? null ) ? $stage['sla'] : array(); if ( isset( $sla['start_within'] ) ) { $rules[] = array( 'id' => $key . ':start', 'anchor' => 'stage_started(' . $key . ')', 'offset' => $sla['start_within']['within'] ?? '+0m', 'target' => 'stage_started(' . $key . ')', 'on_miss' => $sla['start_within']['on_miss'] ?? array() ); } if ( isset( $sla['finish_within'] ) ) { $from = (string) ( $sla['finish_within']['from'] ?? 'stage_started' ); $rules[] = array( 'id' => $key . ':finish', 'anchor' => ( 'stage_ready' === $from ? 'stage_ready(' . $key . ')' : 'stage_started(' . $key . ')' ), 'offset' => $sla['finish_within']['within'] ?? '+0m', 'target' => 'stage_done(' . $key . ')', 'on_miss' => $sla['finish_within']['on_miss'] ?? array() ); } } foreach ( $rules as $rule ) { if ( (string) ( $rule['id'] ?? '' ) === $id ) { return $rule; } } return null; }
	private static function ordered_ladder( array $ladder, int $anchor, int $due ): array { usort( $ladder, static function ( $a, $b ) use ( $anchor, $due ) { $at = self::rung_timestamp( $anchor, $due, (string) ( $a['at'] ?? '0' ) ); $bt = self::rung_timestamp( $anchor, $due, (string) ( $b['at'] ?? '0' ) ); return $at <=> $bt; } ); return $ladder; }
	private static function rung_timestamp( int $anchor, int $due, string $at ): ?int { $timestamp = class_exists( 'BizCity_CRM_Pipeline_SLA_Clock' ) ? BizCity_CRM_Pipeline_SLA_Clock::rung_at( $anchor, $due, $at ) : $due; return is_wp_error( $timestamp ) ? null : (int) $timestamp; }
	private static function rung_time( int $anchor, int $due, string $at ): ?string { $timestamp = self::rung_timestamp( $anchor, $due, $at ); return null === $timestamp ? null : self::db_time( $timestamp ); }
	private static function release_claim( int $id, string $error ): void { global $wpdb; if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'update' ) ) { return; } $wpdb->update( self::deadlines_table(), array( 'claimed_by' => null, 'claimed_at' => null, 'last_error' => sanitize_text_field( $error ), 'updated_at' => self::db_now() ), array( 'id' => $id ) ); }
	private static function worker_id(): string { return substr( 'crm-sla-' . md5( (string) getmypid() . '|' . microtime( true ) ), 0, 64 ); }
	private static function deadlines_table(): string { global $wpdb; return class_exists( 'BizCity_CRM_DB_Installer_V2' ) ? BizCity_CRM_DB_Installer_V2::tbl_pipeline_deadlines() : $wpdb->prefix . 'bizcity_crm_pipeline_deadlines'; }
	private static function now_ts(): int { return function_exists( 'current_time' ) ? (int) current_time( 'timestamp' ) : time(); }
	private static function db_now(): string { return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ); }
	private static function db_time( int $timestamp ): string { return function_exists( 'wp_date' ) && function_exists( 'wp_timezone' ) ? wp_date( 'Y-m-d H:i:s', $timestamp, wp_timezone() ) : gmdate( 'Y-m-d H:i:s', $timestamp ); }
	private static function parse_db_time( string $value ): int { $parsed = strtotime( $value ); return false === $parsed ? 0 : (int) $parsed; }
	private static function error( string $code, string $message, int $status ): WP_Error { return new WP_Error( $code, $message, array( 'status' => $status, 'hint' => 'Kiểm tra lại cấu hình và thử lại.', 'help_code' => 'pipeline_' . $code ) ); }
}
