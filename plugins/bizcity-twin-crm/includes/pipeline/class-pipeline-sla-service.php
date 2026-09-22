<?php
/**
 * BizCity CRM — SLA deadline service (PHASE-0.63A WP-3.3/3.4/3.5). **LANE A OWNS THIS FILE.**
 *
 * This is the only writer of `bizcity_crm_pipeline_deadlines`; every transition in the run service
 * calls `sync_for_run()`.
 *
 * @package BizCity_Twin_CRM
 * @since 2026-09-21 (PHASE-0.63A WP-3, stub)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Pipeline_SLA_Service', false ) ) {
	return;
}

final class BizCity_CRM_Pipeline_SLA_Service {

	/** Create / update / cancel the deadlines a run should have right now. @return array|WP_Error */
	public static function sync_for_run( int $run_id ) {
		// [2026-09-21 PHASE-0.63A] Materialise absolute deadlines; the minute runner only reads the queue.
		$run = self::load_run( $run_id );
		if ( ! $run ) {
			return self::error( 'run_not_found', 'Không tìm thấy pipeline đang chạy.', 404, 'Tải lại pipeline rồi thử lại.' );
		}
		$definition = self::definition_for( $run );
		$custom     = self::decode( $run['custom_json'] ?? '' );
		$desired    = self::desired_deadlines( $run, $custom, $definition );
		$written    = 0;
		foreach ( $desired as $deadline ) {
			$result = self::upsert_deadline( $deadline );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$written++;
		}
		self::cancel_stale( $run_id, array_keys( $desired ) );
		return array( 'run_id' => $run_id, 'deadlines' => $written, 'cancelled_stale' => true );
	}

	/** Stop the clock on the deadlines whose target just happened. @return array|WP_Error */
	public static function mark_target_reached( int $run_id, string $target ) {
		$run = self::load_run( $run_id );
		if ( ! $run ) { return self::error( 'run_not_found', 'Không tìm thấy pipeline đang chạy.', 404, 'Tải lại pipeline rồi thử lại.' ); }
		$definition = self::definition_for( $run );
		$custom = self::decode( $run['custom_json'] ?? '' );
		if ( ! self::target_reached( $target, $run, $custom ) ) {
			return array( 'run_id' => $run_id, 'target' => $target, 'met' => 0 );
		}
		$rule_ids = self::rule_ids_for_target( $definition, $target );
		if ( empty( $rule_ids ) ) {
			return array( 'run_id' => $run_id, 'target' => $target, 'met' => 0 );
		}
		global $wpdb;
		$table = self::deadlines_table();
		$now = self::db_now();
		$placeholders = implode( ',', array_fill( 0, count( $rule_ids ), '%s' ) );
		$updated = $wpdb->query( $wpdb->prepare(
			"UPDATE `{$table}` SET state = 'met', met_at = %s, next_fire_at = NULL, claimed_by = NULL, claimed_at = NULL, updated_at = %s WHERE run_id = %d AND rule_id IN ({$placeholders}) AND state IN ('pending','at_risk','breached')",
			array_merge( array( $now, $now, $run_id ), $rule_ids )
		) );
		if ( $updated > 0 && class_exists( 'BizCity_CRM_Reporting_Rollup' ) ) {
			BizCity_CRM_Reporting_Rollup::record_fact( 'pipeline_sla_met', 0, gmdate( 'Y-m-d H:i:s' ), 1, 'pipeline-sla-met:' . $run_id . ':' . implode( ',', $rule_ids ) . ':' . self::db_now() );
		}
		return array( 'run_id' => $run_id, 'target' => $target, 'met' => (int) $updated, 'definition' => $definition['kind'] ?? '' );
	}

	/** Cancel every pending deadline of a closed run. @return array|WP_Error */
	public static function cancel_for_run( int $run_id, string $reason = '' ) {
		if ( ! self::load_run( $run_id ) ) { return self::error( 'run_not_found', 'Không tìm thấy pipeline đang chạy.', 404, 'Tải lại pipeline rồi thử lại.' ); }
		global $wpdb;
		$table = self::deadlines_table();
		$now = self::db_now();
		$updated = $wpdb->query( $wpdb->prepare(
			"UPDATE `{$table}` SET state = 'cancelled', next_fire_at = NULL, claimed_by = NULL, claimed_at = NULL, last_error = %s, updated_at = %s WHERE run_id = %d AND state NOT IN ('met','cancelled')",
			self::text( $reason, 190 ),
			$now,
			$run_id
		) );
		return array( 'run_id' => $run_id, 'cancelled' => (int) $updated );
	}

	/** Start / stop the pause accumulator (`pause_when`). @return array|WP_Error */
	public static function set_paused( int $run_id, bool $paused ) {
		if ( ! self::load_run( $run_id ) ) { return self::error( 'run_not_found', 'Không tìm thấy pipeline đang chạy.', 404, 'Tải lại pipeline rồi thử lại.' ); }
		global $wpdb;
		$table = self::deadlines_table();
		$now_ts = self::now_ts();
		$now = self::db_now();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE run_id = %d AND state IN ('pending','at_risk')", $run_id ), ARRAY_A );
		$changed = 0;
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$id = (int) ( $row['id'] ?? 0 );
			if ( $id <= 0 ) { continue; }
			if ( $paused && empty( $row['paused_at'] ) ) {
				$changed += (int) $wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET paused_at = %s, updated_at = %s WHERE id = %d AND paused_at IS NULL", $now, $now, $id ) );
			} elseif ( ! $paused && ! empty( $row['paused_at'] ) ) {
				$pause_ts = self::parse_db_time( (string) $row['paused_at'] );
				$delta = max( 0, $now_ts - $pause_ts );
				$changed += (int) $wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET paused_ms = paused_ms + %d, due_at = DATE_ADD(due_at, INTERVAL %d SECOND), next_fire_at = IF(next_fire_at IS NULL, NULL, DATE_ADD(next_fire_at, INTERVAL %d SECOND)), paused_at = NULL, updated_at = %s WHERE id = %d AND paused_at IS NOT NULL", $delta * 1000, $delta, $delta, $now, $id ) );
			}
		}
		return array( 'run_id' => $run_id, 'paused' => $paused, 'changed' => $changed );
	}

	/** DTO `pipeline-sla-state@1.0.0` for one run — what the SLA chip on the rail reads. @return array|WP_Error */
	public static function state_for_run( int $run_id ) {
		$run = self::load_run( $run_id );
		if ( ! $run ) { return self::error( 'run_not_found', 'Không tìm thấy pipeline đang chạy.', 404, 'Tải lại pipeline rồi thử lại.' ); }
		global $wpdb;
		$table = self::deadlines_table();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, run_id, pipeline_kind, stage_key, rule_id, layer, due_at, state, level, next_fire_at, met_at, breached_at, paused_ms, paused_at FROM `{$table}` WHERE run_id = %d ORDER BY due_at ASC, id ASC", $run_id ), ARRAY_A );
		$now = self::now_ts();
		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$due = self::parse_db_time( (string) ( $row['due_at'] ?? '' ) );
			$out[] = array(
				'rule_id' => (string) ( $row['rule_id'] ?? '' ), 'layer' => (string) ( $row['layer'] ?? 'step' ),
				'stage_key' => (string) ( $row['stage_key'] ?? '' ), 'due_at' => (string) ( $row['due_at'] ?? '' ),
				'state' => (string) ( $row['state'] ?? 'pending' ), 'level' => (int) ( $row['level'] ?? 0 ),
				'next_fire_at' => $row['next_fire_at'] ?: null, 'remaining_s' => max( 0, $due - $now ),
				'met_at' => $row['met_at'] ?: null, 'breached_at' => $row['breached_at'] ?: null,
				'paused_ms' => (int) ( $row['paused_ms'] ?? 0 ), 'paused' => ! empty( $row['paused_at'] ),
			);
		}
		return array( 'contract' => 'pipeline-sla-state', 'version' => '1.0.0', 'run_id' => $run_id, 'items' => $out );
	}

	/** `{run_id}:{rule_id}:{stage_key}:{level}` — the exactly-once key (WP-3.8). */
	public static function dedupe_key( int $run_id, string $rule_id, string $stage_key, int $level ): string {
		return $run_id . ':' . $rule_id . ':' . $stage_key . ':' . $level;
	}

	private static function desired_deadlines( array $run, array $custom, array $definition ): array {
		$out = array();
		$rules = array();
		foreach ( (array) ( $definition['rules'] ?? array() ) as $rule ) { if ( is_array( $rule ) ) { $rules[] = $rule; } }
		foreach ( (array) ( $definition['process_sla'] ?? array() ) as $rule ) { if ( is_array( $rule ) ) { $rules[] = array_merge( $rule, array( 'layer' => 'process' ) ); } }
		if ( isset( $definition['process_sla']['anchor'] ) && is_array( $definition['process_sla'] ) ) { $rules[] = array_merge( $definition['process_sla'], array( 'layer' => 'process' ) ); }
		foreach ( (array) ( $definition['stages'] ?? array() ) as $stage ) {
			if ( ! is_array( $stage ) ) { continue; }
			$stage_key = (string) ( $stage['key'] ?? '' );
			$sla = is_array( $stage['sla'] ?? null ) ? $stage['sla'] : array();
			if ( isset( $sla['start_within'] ) && is_array( $sla['start_within'] ) ) { $rules[] = self::sugar_rule( $stage_key . ':start', 'wait', $stage_key, $sla['start_within'], 'stage_started(' . $stage_key . ')' ); }
			if ( isset( $sla['finish_within'] ) && is_array( $sla['finish_within'] ) ) { $from = (string) ( $sla['finish_within']['from'] ?? 'stage_started' ); $rules[] = self::sugar_rule( $stage_key . ':finish', 'step', $stage_key, $sla['finish_within'], 'stage_done(' . $stage_key . ')', $from ); }
		}
		foreach ( $rules as $index => $rule ) {
			$rule_id = self::text( $rule['id'] ?? ( 'rule_' . $index ), 64 );
			$anchor = (string) ( $rule['anchor'] ?? '' );
			$anchor_ts = self::anchor_time( $anchor, $run, $custom );
			if ( $anchor_ts <= 0 ) { continue; }
			$clock = (string) ( $rule['clock'] ?? $definition['clock'] ?? '24x7' );
			$calendar = self::calendar_for( $clock, $definition );
			$due = class_exists( 'BizCity_CRM_Pipeline_SLA_Clock' ) ? BizCity_CRM_Pipeline_SLA_Clock::due_from( $anchor_ts, (string) ( $rule['offset'] ?? '+0m' ), $calendar ) : new WP_Error( 'sla_clock_missing', 'Bộ tính hạn SLA chưa sẵn sàng.' );
			if ( is_wp_error( $due ) ) { continue; }
			$stage_key = self::stage_from_target( (string) ( $rule['target'] ?? '' ) );
			$dedupe = self::dedupe_key( (int) $run['id'], $rule_id, $stage_key, 0 );
			$out[ $dedupe ] = array( 'run_id' => (int) $run['id'], 'pipeline_kind' => (string) ( $run['pipeline_kind'] ?? $definition['kind'] ?? '' ), 'stage_key' => '' !== $stage_key ? $stage_key : null, 'rule_id' => $rule_id, 'layer' => (string) ( $rule['layer'] ?? 'step' ), 'anchor_at' => self::db_time( $anchor_ts ), 'due_at' => self::db_time( (int) $due ), 'level' => 0, 'next_fire_at' => self::first_fire_at( $anchor_ts, (int) $due, (array) ( $rule['on_miss'] ?? array() ) ), 'state' => self::target_reached( (string) ( $rule['target'] ?? '' ), $run, $custom ) ? 'met' : 'pending', 'met_at' => self::target_reached( (string) ( $rule['target'] ?? '' ), $run, $custom ) ? self::db_now() : null, 'breached_at' => null, 'paused_ms' => 0, 'paused_at' => null, 'attempts' => 0, 'last_error' => null, 'dedupe_key' => $dedupe, 'created_at' => self::db_now(), 'updated_at' => self::db_now() );
		}
		return $out;
	}

	private static function sugar_rule( string $id, string $layer, string $stage, array $window, string $target, string $from = 'stage_started' ): array { return array( 'id' => $id, 'layer' => $layer, 'anchor' => 'stage_ready' === $from ? 'stage_ready(' . $stage . ')' : 'stage_started(' . $stage . ')', 'offset' => (string) ( $window['within'] ?? '+0m' ), 'target' => $target, 'on_miss' => (array) ( $window['on_miss'] ?? array() ) ); }
	private static function upsert_deadline( array $data ) { global $wpdb; if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_row' ) || ! method_exists( $wpdb, 'insert' ) || ! method_exists( $wpdb, 'update' ) ) { return self::error( 'db_unavailable', 'Hàng đợi SLA chưa sẵn sàng.', 503, 'Thử lại khi CRM database đã sẵn sàng.' ); } $table = self::deadlines_table(); $stage_key = $data['stage_key'] ?? null; $existing = null; if ( null === $stage_key ) { $existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE run_id = %d AND rule_id = %s AND stage_key IS NULL LIMIT 1", $data['run_id'], $data['rule_id'] ), ARRAY_A ); } else { $existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE run_id = %d AND rule_id = %s AND stage_key = %s LIMIT 1", $data['run_id'], $data['rule_id'], $stage_key ), ARRAY_A ); } if ( is_array( $existing ) ) { $fields = array( 'anchor_at' => $data['anchor_at'], 'due_at' => $data['due_at'], 'updated_at' => self::db_now() ); if ( 'met' === (string) $data['state'] && ! in_array( (string) $existing['state'], array( 'met', 'cancelled' ), true ) ) { $fields['state'] = 'met'; $fields['met_at'] = $data['met_at'] ?: self::db_now(); $fields['next_fire_at'] = null; } elseif ( in_array( (string) $existing['state'], array( 'pending', 'at_risk' ), true ) && empty( $existing['paused_at'] ) ) { $fields['next_fire_at'] = $data['next_fire_at']; } $wpdb->update( $table, $fields, array( 'id' => (int) $existing['id'] ) ); return (int) $existing['id']; } return $wpdb->insert( $table, $data ) ? (int) $wpdb->insert_id : self::error( 'sla_deadline_write_failed', 'Không thể ghi hạn SLA.', 500, 'Thử lại sau.' ); }
	private static function cancel_stale( int $run_id, array $desired ) { global $wpdb; if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'query' ) ) { return; } $table = self::deadlines_table(); $keep = empty( $desired ) ? "''" : implode( ',', array_map( static function ( $key ) use ( $wpdb ) { return "'" . esc_sql( $key ) . "'"; }, $desired ) ); $wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET state = 'cancelled', next_fire_at = NULL, updated_at = %s WHERE run_id = %d AND state IN ('pending','at_risk') AND dedupe_key NOT IN ({$keep})", self::db_now(), $run_id ) ); }
	private static function load_run( int $run_id ): ?array { global $wpdb; if ( $run_id <= 0 || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_row' ) || ! method_exists( $wpdb, 'prepare' ) ) { return null; } $table = class_exists( 'BizCity_CRM_DB_Installer_V2' ) ? BizCity_CRM_DB_Installer_V2::tbl_crm_opportunities() : ( $wpdb->prefix . 'bizcity_crm_opportunities' ); $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d AND deleted_at IS NULL LIMIT 1", $run_id ), ARRAY_A ); return is_array( $row ) ? $row : null; }
	private static function definition_for( array $run ): array { $kind = (string) ( $run['pipeline_kind'] ?? '' ); $version = (int) ( $run['pipeline_def_version'] ?? 0 ); $definition = class_exists( 'BizCity_CRM_Pipeline_Registry' ) ? BizCity_CRM_Pipeline_Registry::get_version( $kind, $version ) : null; return is_array( $definition ) ? $definition : array( 'kind' => $kind ); }
	private static function anchor_time( string $anchor, array $run, array $custom ): int { if ( 'run_created' === $anchor ) { return self::parse_db_time( (string) ( $run['created_at'] ?? '' ) ); } if ( preg_match( '/^(stage_ready|stage_started)\(([^)]+)\)$/', $anchor, $m ) ) { $entry = is_array( $custom['stages'][ $m[2] ] ?? null ) ? $custom['stages'][ $m[2] ] : array(); return 'stage_started' === $m[1] ? (int) ( $entry['started_at'] ?? 0 ) : (int) ( $entry['ready_at'] ?? $entry['started_at'] ?? $entry['done_at'] ?? 0 ); } if ( preg_match( '/^field:([a-z][a-z0-9_]*)$/', $anchor, $m ) ) { return self::parse_db_time( (string) ( $custom[ $m[1] ] ?? $run[ $m[1] ] ?? '' ) ); } if ( 'assigned' === $anchor || 'accepted' === $anchor || 'checkin' === $anchor || 'appointment_at' === $anchor ) { return self::parse_db_time( (string) ( $custom[ $anchor . '_at' ] ?? $custom[ $anchor ] ?? '' ) ); } if ( 'last_inbound' === $anchor || 'first_outbound' === $anchor ) { return self::parse_db_time( (string) ( $custom[ $anchor . '_at' ] ?? '' ) ); } if ( preg_match( '/^exception_raised\(([^)]+)\)$/', $anchor, $m ) ) { foreach ( (array) ( $custom['exceptions'] ?? array() ) as $exception ) { if ( is_array( $exception ) && (string) ( $exception['key'] ?? '' ) === $m[1] ) { return (int) ( $exception['raised_at'] ?? 0 ); } } } return 0; }
	private static function target_reached( string $target, array $run, array $custom ): bool { if ( 'run_terminal' === $target ) { return in_array( (string) ( $run['status'] ?? '' ), array( 'won', 'lost', 'closed' ), true ); } if ( preg_match( '/^stage_(started|done)\(([^)]+)\)$/', $target, $m ) ) { $entry = is_array( $custom['stages'][ $m[2] ] ?? null ) ? $custom['stages'][ $m[2] ] : array(); return 'started' === $m[1] ? ! empty( $entry['started_at'] ) : 'done' === ( $entry['state'] ?? '' ); } if ( preg_match( '/^stage_in\(\[([^]]+)\]\)$/', $target, $m ) ) { foreach ( explode( ',', $m[1] ) as $stage ) { if ( self::target_reached( 'stage_started(' . trim( $stage ) . ')', $run, $custom ) ) { return true; } } return false; } if ( 'exception_ack' === $target || 'exception_resolved' === $target ) { foreach ( (array) ( $custom['exceptions'] ?? array() ) as $exception ) { if ( is_array( $exception ) && ( $target === 'exception_ack' ? ! empty( $exception['ack_at'] ) : ! empty( $exception['resolved_at'] ) ) ) { return true; } } return false; } return ! empty( $custom[ $target . '_at' ] ); }
	private static function rule_ids_for_target( array $definition, string $target ): array { $ids = array(); foreach ( self::rules_for_definition( $definition ) as $index => $rule ) { if ( (string) ( $rule['target'] ?? '' ) === $target ) { $ids[] = self::text( $rule['id'] ?? ( 'rule_' . $index ), 64 ); } } return array_values( array_unique( $ids ) ); }
	private static function rules_for_definition( array $definition ): array { $rules = array(); foreach ( (array) ( $definition['rules'] ?? array() ) as $rule ) { if ( is_array( $rule ) ) { $rules[] = $rule; } } if ( is_array( $definition['process_sla'] ?? null ) ) { $rules[] = array_merge( $definition['process_sla'], array( 'layer' => 'process' ) ); } foreach ( (array) ( $definition['stages'] ?? array() ) as $stage ) { if ( ! is_array( $stage ) ) { continue; } $key = (string) ( $stage['key'] ?? '' ); $sla = is_array( $stage['sla'] ?? null ) ? $stage['sla'] : array(); if ( isset( $sla['start_within'] ) ) { $rules[] = self::sugar_rule( $key . ':start', 'wait', $key, $sla['start_within'], 'stage_started(' . $key . ')' ); } if ( isset( $sla['finish_within'] ) ) { $rules[] = self::sugar_rule( $key . ':finish', 'step', $key, $sla['finish_within'], 'stage_done(' . $key . ')', (string) ( $sla['finish_within']['from'] ?? 'stage_started' ) ); } } return $rules; }
	private static function calendar_for( string $clock, array $definition ): array { if ( 0 !== strpos( $clock, 'calendar:' ) ) { return array(); } $name = substr( $clock, 9 ); return is_array( $definition['calendars'][ $name ] ?? null ) ? $definition['calendars'][ $name ] : array(); }
	private static function stage_from_target( string $target ): string { if ( preg_match( '/^stage_(?:started|done)\(([^)]+)\)$/', $target, $m ) ) { return $m[1]; } return ''; }
	private static function first_fire_at( int $anchor, int $due, array $ladder ) { if ( empty( $ladder ) ) { return self::db_time( $due ); } $earliest = $due; foreach ( $ladder as $rung ) { if ( ! is_array( $rung ) ) { continue; } $when = class_exists( 'BizCity_CRM_Pipeline_SLA_Clock' ) ? BizCity_CRM_Pipeline_SLA_Clock::rung_at( $anchor, $due, (string) ( $rung['at'] ?? '0' ) ) : $due; if ( ! is_wp_error( $when ) ) { $earliest = min( $earliest, (int) $when ); } } return self::db_time( $earliest ); }
	private static function deadlines_table(): string { global $wpdb; return class_exists( 'BizCity_CRM_DB_Installer_V2' ) ? BizCity_CRM_DB_Installer_V2::tbl_pipeline_deadlines() : ( $wpdb->prefix . 'bizcity_crm_pipeline_deadlines' ); }
	private static function decode( $raw ): array { $value = json_decode( (string) $raw, true ); return is_array( $value ) ? $value : array(); }
	private static function now_ts(): int { return function_exists( 'current_time' ) ? (int) current_time( 'timestamp' ) : time(); }
	private static function db_now(): string { return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ); }
	private static function db_time( int $timestamp ): string { return function_exists( 'wp_date' ) && function_exists( 'wp_timezone' ) ? wp_date( 'Y-m-d H:i:s', $timestamp, wp_timezone() ) : gmdate( 'Y-m-d H:i:s', $timestamp ); }
	private static function parse_db_time( string $value ): int { if ( '' === $value ) { return 0; } $timestamp = strtotime( $value ); return false === $timestamp ? 0 : (int) $timestamp; }
	private static function text( $value, int $limit ): string { $text = trim( (string) $value ); return function_exists( 'sanitize_text_field' ) ? substr( sanitize_text_field( $text ), 0, $limit ) : substr( $text, 0, $limit ); }
	private static function error( string $code, string $message, int $status, string $hint ): WP_Error { return new WP_Error( $code, $message, array( 'status' => $status, 'hint' => $hint, 'help_code' => 'pipeline_' . $code ) ); }
}
