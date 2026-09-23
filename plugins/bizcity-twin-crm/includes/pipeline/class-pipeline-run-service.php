<?php
/**
 * BizCity CRM — Pipeline Run Service (PHASE-0.63A WP-2). **LANE A OWNS THIS FILE.**
 *
 * Signature stub created by lane S (master §4 S-3); implemented by lane A for WP-2.
 *
 * Contract this must honour when implemented (0.63 §3, 0.63A WP-2):
 *  - `custom_json.stages{key:{state,started_at,done_at,by}}` is the source of truth; `pipeline_stage`
 *    becomes a derived value so the old sales rail keeps working.
 *  - Gates, exceptions and evidence are enforced HERE, server-side, never trusted from the FE.
 *  - Every transition writes audit and re-syncs SLA deadlines.
 *
 * @package BizCity_Twin_CRM
 * @since 2026-09-21 (PHASE-0.63A WP-2, stub)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Pipeline_Run_Service', false ) ) {
	return;
}

final class BizCity_CRM_Pipeline_Run_Service {

	/** Open a run of `$kind` for a subject, pinning the current definition version. @return int|WP_Error */
	public static function open_run( int $contact_id, string $kind, array $args = array() ) {
		// [2026-09-21 PHASE-0.63A] Open one pinned run per contact and pipeline kind.
		if ( $contact_id <= 0 ) {
			return self::error( 'contact_required', 'Cần chọn đối tượng của pipeline.', 422, 'Chọn một contact rồi thử lại.' );
		}
		$kind = self::clean_kind( $kind );
		$definition = class_exists( 'BizCity_CRM_Pipeline_Registry' ) ? BizCity_CRM_Pipeline_Registry::get( $kind ) : null;
		if ( ! is_array( $definition ) ) {
			return self::error( 'pipeline_not_found', 'Không tìm thấy định nghĩa pipeline.', 404, 'Chọn một pipeline đang được bật.' );
		}
		global $wpdb;
		$table = self::opportunities_table();
		// [2026-09-23] Only reuse a run that is still OPEN. Before this fix the query ignored `status`
		// entirely, so a second sales cycle (or a second service booking, or a second purchase request)
		// for the same contact silently reattached to the first run's terminal (won/lost/closed) row
		// forever — R-PIPE-4's "one run per contact per kind" means one *current* run, not one *lifetime*
		// run. `service` needs this most directly: every ca is its own run, and the previous ca is closed
		// (`status='won'`, set by `transition()` on a terminal stage) by the time the next one is booked.
		$existing = $wpdb->get_row( $wpdb->prepare(
			"SELECT id FROM `{$table}` WHERE contact_id = %d AND pipeline_kind = %s AND status = 'open' AND deleted_at IS NULL ORDER BY id DESC LIMIT 1",
			$contact_id,
			$kind
		), ARRAY_A );
		if ( is_array( $existing ) && (int) ( $existing['id'] ?? 0 ) > 0 ) {
			return (int) $existing['id'];
		}

		$now      = function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
		$now_ts   = function_exists( 'current_time' ) ? (int) current_time( 'timestamp' ) : time();
		$stages   = self::initial_stages( $definition, $now_ts );
		$first    = self::first_stage_key( $definition );
		$version  = class_exists( 'BizCity_CRM_Pipeline_Registry' ) ? BizCity_CRM_Pipeline_Registry::current_version( $kind ) : 0;
		$custom   = array(
			'pipeline'             => true,
			'pipeline_kind'        => $kind,
			'pipeline_def_version' => $version,
			'pipeline_stage'       => $first,
			'stage_at'             => $now_ts,
			'stages'               => $stages,
			'exceptions'           => array(),
			'_lock'                => 0,
		);
		// [2026-09-23 PHASE-0.69] `appointment_at` is a top-level custom_json field, not a per-stage one —
		// the SLA clock's `field:`/literal `appointment_at` anchor (0.63 §2.1, registry FUTURE_ANCHORS)
		// reads it from here. A service-kind run needs it from the moment it opens, since `depart_prep`/
		// `checkin_prep` are both relative to it, not to any one stage. Any kind may set it — the field is
		// generic, only `service.json` currently declares rules that anchor on it.
		$appointment_at = self::parse_appointment_at( $args['appointment_at'] ?? '' );
		if ( '' !== $appointment_at ) { $custom['appointment_at'] = $appointment_at; }
		// [2026-09-23 PHASE-0.69 M-03] Which team offers this — the SLA `emit(service_reassign_needed)`
		// listener needs it to call the matcher, and nothing else on a run says which team it belongs to
		// (a run is per-contact, teams are not). Generic field, not `service`-specific, same reasoning as
		// `appointment_at` above.
		if ( isset( $args['team_id'] ) && (int) $args['team_id'] > 0 ) { $custom['team_id'] = (int) $args['team_id']; }
		// [2026-09-23 PHASE-0.69 D69-5] Which `catalogs.services[]` entry this booking is — resolves the
		// default duration (`Pipeline_Registry::catalog_service()`) without hardcoding it a second time at
		// the REST layer. Generic field; only `service.json` declares a `catalogs.services[]` today.
		$service_key = preg_match( '/^[a-z0-9_]{1,64}$/', (string) ( $args['service_key'] ?? '' ) ) ? (string) $args['service_key'] : '';
		if ( '' !== $service_key ) { $custom['service_key'] = $service_key; }
		$row = array(
			'name'                 => isset( $args['name'] ) ? self::text( $args['name'], 255 ) : 'Pipeline · ' . $kind . ' · ' . $contact_id,
			'contact_id'           => $contact_id,
			'owner_id'             => isset( $args['owner_id'] ) ? (int) $args['owner_id'] : ( function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0 ),
			'stage'               => $first,
			'status'              => 'open',
			'pipeline_kind'       => $kind,
			'pipeline_def_id'     => isset( $args['pipeline_def_id'] ) ? (int) $args['pipeline_def_id'] : null,
			'pipeline_def_version'=> $version,
			'custom_json'         => self::json( $custom ),
			'created_by'          => isset( $args['created_by'] ) ? (int) $args['created_by'] : ( function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : null ),
			'created_at'          => $now,
			'updated_at'          => $now,
		);
		if ( false === $wpdb->insert( $table, $row ) ) {
			return self::error( 'run_create_failed', 'Không thể mở pipeline lúc này.', 500, 'Thử lại sau.' );
		}
		$run_id = (int) $wpdb->insert_id;
		self::audit( $run_id, 'created', null, array( 'pipeline_kind' => $kind, 'contact_id' => $contact_id ) );
		self::sync_sla( $run_id );
		return $run_id;
	}

	/** Read the run state a surface renders: stages, derived stage, exceptions, lock. @return array|WP_Error */
	public static function get_run( int $run_id ) {
		$row = self::load_row( $run_id );
		if ( ! $row ) {
			return self::error( 'run_not_found', 'Không tìm thấy pipeline đang chạy.', 404, 'Tải lại danh sách pipeline.' );
		}
		$custom     = self::decode( $row['custom_json'] ?? '' );
		$definition = self::definition_for_row( $row );
		return self::shape_run( $row, $custom, $definition );
	}

	/** @return array|WP_Error `gate_blocked` with the missing step keys when a gate is closed. */
	public static function start_stage( int $run_id, string $stage_key, array $args = array() ) {
		return self::transition( $run_id, $stage_key, 'doing', $args );
	}

	/** @return array|WP_Error `exception_open` / `evidence_required` / `stale_write` as applicable. */
	public static function complete_stage( int $run_id, string $stage_key, array $args = array() ) {
		$row = self::load_row( $run_id );
		if ( ! $row ) { return self::error( 'run_not_found', 'Không tìm thấy pipeline đang chạy.', 404, 'Tải lại danh sách pipeline.' ); }
		$custom     = self::decode( $row['custom_json'] ?? '' );
		$definition = self::definition_for_row( $row );
		$step       = self::step_info( $definition, $stage_key );
		if ( ! $step ) { return self::error( 'stage_not_found', 'Không tìm thấy bước pipeline.', 404, 'Chọn một bước hợp lệ.' ); }
		$blocked = self::blocking_exception( $custom, $stage_key );
		if ( $blocked ) { return self::error( 'exception_open', 'Bước đang bị chặn bởi một ngoại lệ chưa xử lý.', 409, 'Nhận hoặc xử lý ngoại lệ trước khi đóng bước.' ); }
		$missing = self::missing_requirements( $step, $args );
		if ( ! empty( $missing ) ) {
			return new WP_Error( 'evidence_required', 'Bước chưa đủ dữ liệu hoặc bằng chứng.', array( 'status' => 422, 'missing' => $missing, 'hint' => 'Bổ sung trường và bằng chứng bắt buộc.', 'help_code' => 'pipeline_evidence_required' ) );
		}
		$evidence_result = self::persist_step_evidence( $run_id, $stage_key, $step, $args, $row );
		if ( is_wp_error( $evidence_result ) ) { return $evidence_result; }
		return self::transition( $run_id, $stage_key, 'done', $args );
	}

	/** @return array|WP_Error */
	public static function block_stage( int $run_id, string $stage_key, array $args = array() ) {
		return self::transition( $run_id, $stage_key, 'blocked', $args );
	}

	/** @return array|WP_Error */
	public static function reopen_stage( int $run_id, string $stage_key, array $args = array() ) {
		return self::transition( $run_id, $stage_key, 'ready', $args );
	}

	/** @return array|WP_Error */
	public static function raise_exception( int $run_id, string $exception_key, array $args = array() ) {
		return self::exception_transition( $run_id, $exception_key, 'open', $args );
	}

	/** @return array|WP_Error */
	public static function ack_exception( int $run_id, string $exception_key, array $args = array() ) {
		return self::exception_transition( $run_id, $exception_key, 'ack', $args );
	}

	/** @return array|WP_Error */
	public static function resolve_exception( int $run_id, string $exception_key, array $args = array() ) {
		return self::exception_transition( $run_id, $exception_key, 'resolved', $args );
	}

	/**
	 * Reschedule the run's `appointment_at` (0.69 D69-4 — no recurring bookings; a moved/duplicated ca is
	 * a manual edit of one run's appointment time). Re-syncs SLA so `depart_prep`/`checkin_prep` recompute
	 * against the new time instead of firing against the stale one.
	 *
	 * @return array|WP_Error
	 */
	public static function set_appointment( int $run_id, string $appointment_at, array $args = array() ) {
		$row = self::load_row( $run_id );
		if ( ! $row ) { return self::error( 'run_not_found', 'Không tìm thấy pipeline đang chạy.', 404, 'Tải lại pipeline.' ); }
		$parsed = self::parse_appointment_at( $appointment_at );
		if ( '' === $parsed ) { return self::error( 'appointment_at_invalid', 'Thời điểm hẹn không hợp lệ.', 422, 'Chọn lại ngày giờ hẹn.' ); }
		$custom = self::decode( $row['custom_json'] ?? '' );
		$definition = self::definition_for_row( $row );
		$old_lock = (int) ( $custom['_lock'] ?? 0 );
		if ( self::lock_enabled( $definition ) ) {
			if ( ! array_key_exists( 'lock_version', $args ) ) { return self::error( 'lock_version_required', 'Thiếu phiên bản khóa của pipeline.', 409, 'Tải lại pipeline rồi gửi lại phiên bản khóa hiện tại.' ); }
			if ( (int) $args['lock_version'] !== $old_lock ) { return self::error( 'stale_write', 'Pipeline đã được thay đổi bởi người khác.', 409, 'Tải lại pipeline trước khi ghi tiếp.' ); }
		}
		$before = $custom;
		$custom['appointment_at'] = $parsed;
		$custom['_lock'] = $old_lock + 1;
		$now = function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
		$updated = self::write_row( $row, $custom, (string) $row['status'], $now, $definition );
		if ( is_wp_error( $updated ) ) { return $updated; }
		self::audit( $run_id, 'appointment_rescheduled', $before, $custom );
		self::sync_sla( $run_id );
		return self::shape_run( $updated, $custom, $definition );
	}

	/** The id of the one OPEN run of `$kind` for a contact, or 0. Used by background writers that need a
	 * target run without a UI-supplied run id (e.g. 0.69's passive location extraction from a message). */
	public static function open_run_id_for_contact( int $contact_id, string $kind ): int {
		if ( $contact_id <= 0 ) { return 0; }
		global $wpdb;
		$table = self::opportunities_table();
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM `{$table}` WHERE contact_id = %d AND pipeline_kind = %s AND status = 'open' AND deleted_at IS NULL ORDER BY id DESC LIMIT 1",
			$contact_id,
			self::clean_kind( $kind )
		) );
	}

	/**
	 * Attach a customer's last-known coordinates to the run (0.69 §4.4 L3: `custom_json.service_address`).
	 * Not a stage transition — no audit/SLA re-sync, no lock_version requirement, since this is a passive
	 * background write triggered by message ingest, not a user-initiated edit with a UI to show a conflict.
	 * A concurrent stage transition can never lose this data: `write_row()`'s CAS is keyed on the WHOLE
	 * `custom_json` blob, so if it loses the race here it silently no-ops and the next inbound location
	 * message retries — never corrupts, at worst briefly stale.
	 *
	 * @return bool
	 */
	public static function set_service_address( int $run_id, array $point, ?int $message_id = null ): bool {
		$row = self::load_row( $run_id );
		if ( ! $row ) { return false; }
		$custom = self::decode( $row['custom_json'] ?? '' );
		$custom['service_address'] = array(
			'lat'             => (float) ( $point['lat'] ?? 0 ),
			'lng'             => (float) ( $point['lng'] ?? 0 ),
			'label'           => isset( $point['label'] ) ? self::text( $point['label'], 190 ) : null,
			'from_message_id' => null !== $message_id ? (int) $message_id : null,
		);
		$custom['_lock'] = (int) ( $custom['_lock'] ?? 0 ) + 1;
		$now = function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
		global $wpdb;
		$table = self::opportunities_table();
		$updated = $wpdb->query( $wpdb->prepare(
			"UPDATE `{$table}` SET custom_json = %s, updated_at = %s WHERE id = %d AND custom_json = %s",
			self::json( $custom ),
			$now,
			$run_id,
			(string) ( $row['custom_json'] ?? '' )
		) );
		return 1 === (int) $updated;
	}

	/**
	 * Persist a "who could take this instead" suggestion (0.69 M-03) — never assigns anyone; the framework
	 * proposes, a human picks (R-WORK-PIPE §0 "linh hoạt trước", same rule that keeps SLA breach from ever
	 * moving a stage on its own). Same non-transition write shape as `set_service_address()` — passive,
	 * triggered by an SLA `emit()` action, not a user click.
	 *
	 * @param array $suggestion Result of `BizCity_CRM_Service_Matcher::candidates()`.
	 * @return bool
	 */
	public static function set_reassign_suggestion( int $run_id, array $suggestion ): bool {
		$row = self::load_row( $run_id );
		if ( ! $row ) { return false; }
		$custom = self::decode( $row['custom_json'] ?? '' );
		$candidates = array();
		foreach ( (array) ( $suggestion['candidates'] ?? array() ) as $candidate ) {
			if ( ! is_array( $candidate ) ) { continue; }
			$candidates[] = array(
				'user_id'      => (int) ( $candidate['user_id'] ?? 0 ),
				'display_name' => self::text( $candidate['display_name'] ?? '', 120 ),
			);
		}
		$custom['reassign_suggestion'] = array(
			'at'         => function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ),
			'candidates' => $candidates,
		);
		$custom['_lock'] = (int) ( $custom['_lock'] ?? 0 ) + 1;
		$now = function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
		global $wpdb;
		$table = self::opportunities_table();
		$updated = $wpdb->query( $wpdb->prepare(
			"UPDATE `{$table}` SET custom_json = %s, updated_at = %s WHERE id = %d AND custom_json = %s",
			self::json( $custom ),
			$now,
			$run_id,
			(string) ( $row['custom_json'] ?? '' )
		) );
		return 1 === (int) $updated;
	}

	/** Runs of one contact — feeds the conversation target selector (D62-2). @return array|WP_Error */
	public static function runs_for_contact( int $contact_id ) {
		if ( $contact_id <= 0 ) { return array(); }
		global $wpdb;
		$table = self::opportunities_table();
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM `{$table}` WHERE contact_id = %d AND pipeline_kind <> '' AND deleted_at IS NULL ORDER BY updated_at DESC, id DESC",
			$contact_id
		), ARRAY_A );
		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$custom = self::decode( $row['custom_json'] ?? '' );
			$out[] = self::shape_run( $row, $custom, self::definition_for_row( $row ) );
		}
		return $out;
	}

	/** Apply one server-side stage transition. */
	private static function transition( int $run_id, string $stage_key, string $state, array $args ) {
		$row = self::load_row( $run_id );
		if ( ! $row ) { return self::error( 'run_not_found', 'Không tìm thấy pipeline đang chạy.', 404, 'Tải lại pipeline.' ); }
		$custom     = self::decode( $row['custom_json'] ?? '' );
		$definition = self::definition_for_row( $row );
		$step       = self::step_info( $definition, $stage_key );
		if ( ! $step ) { return self::error( 'stage_not_found', 'Không tìm thấy bước pipeline.', 404, 'Chọn một bước hợp lệ.' ); }
		if ( 'doing' === $state && ! self::gate_open( $definition, $custom, $stage_key ) ) {
			return new WP_Error( 'gate_blocked', 'Bước này chưa mở vì còn bước tiền đề.', array( 'status' => 409, 'missing' => self::gate_missing( $definition, $custom, $stage_key ), 'hint' => 'Hoàn thành các bước tiền đề rồi thử lại.', 'help_code' => 'pipeline_gate_blocked' ) );
		}
		$old_lock = (int) ( $custom['_lock'] ?? 0 );
		if ( self::lock_enabled( $definition ) ) {
			if ( ! array_key_exists( 'lock_version', $args ) ) {
				return self::error( 'lock_version_required', 'Thiếu phiên bản khóa của pipeline.', 409, 'Tải lại pipeline rồi gửi lại phiên bản khóa hiện tại.' );
			}
			if ( (int) $args['lock_version'] !== $old_lock ) {
				return self::error( 'stale_write', 'Pipeline đã được thay đổi bởi người khác.', 409, 'Tải lại pipeline trước khi ghi tiếp.' );
			}
		}
		$now_ts = function_exists( 'current_time' ) ? (int) current_time( 'timestamp' ) : time();
		$now   = function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
		$before = $custom;
		if ( ! isset( $custom['stages'] ) || ! is_array( $custom['stages'] ) ) { $custom['stages'] = self::initial_stages( $definition, $now_ts ); }
		$entry = is_array( $custom['stages'][ $stage_key ] ?? null ) ? $custom['stages'][ $stage_key ] : array();
		$entry['state'] = $state;
		if ( 'doing' === $state || 'ready' === $state ) { $entry['started_at'] = 'doing' === $state ? $now_ts : ( $entry['started_at'] ?? null ); }
		if ( 'done' === $state ) { $entry['done_at'] = $now_ts; }
		$entry['by'] = isset( $args['actor_id'] ) ? (int) $args['actor_id'] : ( function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0 );
		// [2026-09-23 PHASE-0.69 M-02] `by` is who PERFORMED the transition (the dispatcher clicking
		// "Assign"), not necessarily who the stage is ABOUT — those are the same person for most pipeline
		// kinds (whoever starts a stage is doing that stage's work) but not for `service`'s `assigned`
		// stage, where a dispatcher assigns a *different* person. `role_context()` prefers this explicit
		// field over `by` when present; every other kind never sets it, so `by` alone still decides
		// `assignee_of_stage` for them — unchanged behaviour.
		if ( isset( $args['stage_assignee_id'] ) && (int) $args['stage_assignee_id'] > 0 ) { $entry['assignee_id'] = (int) $args['stage_assignee_id']; }
		if ( isset( $args['data'] ) && is_array( $args['data'] ) ) { $entry['data'] = $args['data']; }
		$custom['stages'][ $stage_key ] = $entry;
		if ( 'doing' === $state ) {
			self::ensure_sub_step_tasks( (int) $row['id'], $stage_key, $step, $entry, $row, $args );
		}
		$custom['pipeline_stage'] = self::derived_stage( $definition, $custom['stages'], $stage_key );
		$custom['stage_at'] = $now_ts;
		$custom['_lock'] = $old_lock + 1;
		$status = self::is_terminal( $definition, $stage_key ) && 'done' === $state ? 'won' : ( 'blocked' === $state ? 'open' : (string) ( $row['status'] ?? 'open' ) );
		$updated = self::write_row( $row, $custom, $status, $now, $definition );
		if ( is_wp_error( $updated ) ) { return $updated; }
		self::audit( $run_id, 'stage_' . $state, $before, $custom );
		self::sync_sla( $run_id );
		self::emit_lifecycle( $run_id, (int) ( $row['contact_id'] ?? 0 ), (string) ( $row['pipeline_kind'] ?? $definition['kind'] ?? '' ), $custom['pipeline_stage'] ?? $stage_key, 'stage_' . $state );
		if ( 'won' === $status || 'lost' === $status || 'closed' === $status ) {
			self::cancel_open_sla( $run_id, 'run_terminal' );
		}
		do_action( 'bizcity_crm_pipeline_stage_' . $state, $run_id, $stage_key, $args );
		return self::shape_run( $updated, $custom, $definition );
	}

	private static function exception_transition( int $run_id, string $key, string $state, array $args ) {
		$row = self::load_row( $run_id );
		if ( ! $row ) { return self::error( 'run_not_found', 'Không tìm thấy pipeline đang chạy.', 404, 'Tải lại pipeline.' ); }
		$definition = self::definition_for_row( $row );
		$declared = null;
		foreach ( (array) ( $definition['exceptions'] ?? array() ) as $exception ) {
			if ( is_array( $exception ) && (string) ( $exception['key'] ?? '' ) === $key ) { $declared = $exception; break; }
		}
		if ( null === $declared ) { return self::error( 'exception_not_found', 'Không tìm thấy loại ngoại lệ.', 404, 'Chọn một ngoại lệ hợp lệ.' ); }
		$custom = self::decode( $row['custom_json'] ?? '' );
		$list   = is_array( $custom['exceptions'] ?? null ) ? $custom['exceptions'] : array();
		$found  = false;
		$now_ts = function_exists( 'current_time' ) ? (int) current_time( 'timestamp' ) : time();
		foreach ( $list as &$exception ) {
			if ( (string) ( $exception['key'] ?? '' ) !== $key || ( 'open' !== $state && 'open' === ( $exception['state'] ?? '' ) ) ) { continue; }
			$exception['state'] = $state;
			$exception[ 'ack' === $state ? 'ack_at' : ( 'resolved' === $state ? 'resolved_at' : 'raised_at' ) ] = $now_ts;
			$found = true;
			break;
		}
		unset( $exception );
		if ( 'open' === $state ) {
			foreach ( $list as $existing ) {
				if ( (string) ( $existing['key'] ?? '' ) === $key && in_array( (string) ( $existing['state'] ?? '' ), array( 'open', 'ack' ), true ) ) {
					return self::error( 'exception_already_open', 'Ngoại lệ này đã được mở.', 409, 'Xử lý ngoại lệ đang có trước khi mở lại.' );
				}
			}
			$list[] = array( 'key' => $key, 'stage' => (string) ( $args['stage_key'] ?? '' ), 'state' => 'open', 'raised_at' => $now_ts, 'raised_by' => (int) ( $args['actor_id'] ?? 0 ) );
			$found = true;
		}
		if ( ! $found ) { return self::error( 'exception_state_invalid', 'Ngoại lệ không ở trạng thái có thể cập nhật.', 409, 'Tải lại trạng thái ngoại lệ.' ); }
		$before = $custom;
		$custom['exceptions'] = $list;
		$custom['_lock'] = (int) ( $custom['_lock'] ?? 0 ) + 1;
		$now = function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
		$updated = self::write_row( $row, $custom, (string) $row['status'], $now, $definition );
		if ( is_wp_error( $updated ) ) { return $updated; }
		self::audit( $run_id, 'exception_' . $state, $before, $custom );
		self::sync_sla( $run_id );
		self::emit_lifecycle( $run_id, (int) ( $row['contact_id'] ?? 0 ), (string) ( $row['pipeline_kind'] ?? $definition['kind'] ?? '' ), (string) ( $args['stage_key'] ?? '' ), 'exception_' . $state );
		return self::shape_run( $updated, $custom, $definition );
	}

	private static function load_row( int $run_id ): ?array { global $wpdb; if ( $run_id <= 0 || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_row' ) || ! method_exists( $wpdb, 'prepare' ) ) { return null; } $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM `' . self::opportunities_table() . '` WHERE id = %d AND deleted_at IS NULL LIMIT 1', $run_id ), ARRAY_A ); return is_array( $row ) ? $row : null; }
	private static function opportunities_table(): string { return class_exists( 'BizCity_CRM_DB_Installer_V2' ) ? BizCity_CRM_DB_Installer_V2::tbl_crm_opportunities() : ( isset( $GLOBALS['wpdb']->prefix ) ? $GLOBALS['wpdb']->prefix . 'bizcity_crm_opportunities' : 'wp_bizcity_crm_opportunities' ); }
	private static function definition_for_row( array $row ): array { $kind = self::clean_kind( (string) ( $row['pipeline_kind'] ?? 'sales' ) ); $version = (int) ( $row['pipeline_def_version'] ?? 0 ); $definition = class_exists( 'BizCity_CRM_Pipeline_Registry' ) ? BizCity_CRM_Pipeline_Registry::get_version( $kind, $version ) : null; return is_array( $definition ) ? $definition : array( 'kind' => $kind, 'stages' => array() ); }
	private static function first_stage_key( array $definition ): string { $stage = (array) ( $definition['stages'][0] ?? array() ); return (string) ( $stage['key'] ?? '' ); }
	private static function initial_stages( array $definition, int $now_ts ): array { $out = array(); foreach ( (array) ( $definition['stages'] ?? array() ) as $stage ) { if ( ! is_array( $stage ) || '' === (string) ( $stage['key'] ?? '' ) ) { continue; } $out[ (string) $stage['key'] ] = array( 'state' => 'ready' ); foreach ( (array) ( $stage['sub_steps'] ?? array() ) as $sub ) { if ( is_array( $sub ) && '' !== (string) ( $sub['key'] ?? '' ) ) { $out[ (string) $sub['key'] ] = array( 'state' => 'ready' ); } } } return $out; }
	private static function step_info( array $definition, string $key ): ?array { foreach ( (array) ( $definition['stages'] ?? array() ) as $stage ) { if ( ! is_array( $stage ) ) { continue; } if ( (string) ( $stage['key'] ?? '' ) === $key ) { return $stage; } foreach ( (array) ( $stage['sub_steps'] ?? array() ) as $sub ) { if ( is_array( $sub ) && (string) ( $sub['key'] ?? '' ) === $key ) { return $sub; } } } return null; }
	private static function all_step_keys( array $definition ): array { $keys = array(); foreach ( (array) ( $definition['stages'] ?? array() ) as $stage ) { if ( is_array( $stage ) && '' !== (string) ( $stage['key'] ?? '' ) ) { $keys[] = (string) $stage['key']; foreach ( (array) ( $stage['sub_steps'] ?? array() ) as $sub ) { if ( is_array( $sub ) && '' !== (string) ( $sub['key'] ?? '' ) ) { $keys[] = (string) $sub['key']; } } } } return $keys; }
	private static function is_done( array $stages, string $key ): bool { return 'done' === (string) ( $stages[ $key ]['state'] ?? '' ); }
	private static function gate_missing( array $definition, array $custom, string $key ): array { $missing = array(); $stages = is_array( $custom['stages'] ?? null ) ? $custom['stages'] : array(); foreach ( (array) ( $definition['gates'] ?? array() ) as $gate ) { if ( ! is_array( $gate ) || (string) ( $gate['stage'] ?? '' ) !== $key ) { continue; } $requires = is_array( $gate['requires'] ?? null ) ? $gate['requires'] : array(); if ( isset( $requires['all_of'] ) ) { foreach ( (array) $requires['all_of'] as $required ) { if ( ! self::is_done( $stages, (string) $required ) ) { $missing[] = (string) $required; } } } elseif ( isset( $requires['any_of'] ) ) { $any_done = false; foreach ( (array) $requires['any_of'] as $required ) { if ( self::is_done( $stages, (string) $required ) ) { $any_done = true; break; } } if ( ! $any_done ) { $missing = array_map( 'strval', (array) $requires['any_of'] ); } } } return array_values( array_unique( $missing ) ); }
	private static function gate_open( array $definition, array $custom, string $key ): bool { return empty( self::gate_missing( $definition, $custom, $key ) ); }
	private static function derived_stage( array $definition, array $stages, string $changed ): string { foreach ( (array) ( $definition['stages'] ?? array() ) as $stage ) { if ( is_array( $stage ) && self::is_done( $stages, (string) ( $stage['key'] ?? '' ) ) === false && ( 'doing' === ( $stages[ (string) ( $stage['key'] ?? '' ) ]['state'] ?? '' ) || (string) ( $stage['key'] ?? '' ) === $changed ) ) { return (string) $stage['key']; } } return $changed; }
	private static function blocking_exception( array $custom, string $stage_key ): bool { foreach ( (array) ( $custom['exceptions'] ?? array() ) as $exception ) { if ( is_array( $exception ) && in_array( (string) ( $exception['state'] ?? '' ), array( 'open', 'ack' ), true ) && ( empty( $exception['stage'] ) || (string) $exception['stage'] === $stage_key ) ) { return true; } } return false; }
	private static function missing_requirements( array $step, array $args ): array { $requires = is_array( $step['requires'] ?? null ) ? $step['requires'] : array(); $data = is_array( $args['data'] ?? null ) ? $args['data'] : array(); $missing = array(); foreach ( (array) ( $requires['fields'] ?? array() ) as $field ) { if ( ! array_key_exists( $field, $data ) || '' === trim( (string) $data[ $field ] ) ) { $missing[] = 'field:' . $field; } } $evidence = is_array( $args['evidence'] ?? null ) ? $args['evidence'] : array(); $required_evidence = (array) ( $requires['evidence'] ?? array() ); $min_count = max( 0, (int) ( $requires['min_count'] ?? 0 ) ); if ( count( $evidence ) < $min_count ) { $missing[] = 'evidence:min_count'; } foreach ( $required_evidence as $kind ) { $found = false; foreach ( $evidence as $item ) { if ( ( is_string( $item ) && $item === $kind ) || ( is_array( $item ) && (string) ( $item['type'] ?? '' ) === $kind ) ) { $found = true; break; } } if ( ! $found ) { $missing[] = 'evidence:' . $kind; } } return $missing; }
	private static function is_terminal( array $definition, string $key ): bool { $step = self::step_info( $definition, $key ); return is_array( $step ) && ! empty( $step['terminal'] ); }
	/** Normalize a caller-supplied appointment time to `Y-m-d H:i:s`, or '' when unparseable/absent. */
	private static function parse_appointment_at( $value ): string { $value = trim( (string) $value ); if ( '' === $value ) { return ''; } $ts = strtotime( $value ); return false === $ts ? '' : ( function_exists( 'wp_date' ) && function_exists( 'wp_timezone' ) ? (string) wp_date( 'Y-m-d H:i:s', $ts, wp_timezone() ) : gmdate( 'Y-m-d H:i:s', $ts ) ); }
	private static function shape_run( array $row, array $custom, array $definition ): array { return array( 'id' => (int) $row['id'], 'contact_id' => (int) ( $row['contact_id'] ?? 0 ), 'owner_id' => (int) ( $row['owner_id'] ?? 0 ), 'created_by' => (int) ( $row['created_by'] ?? 0 ), 'pipeline_kind' => (string) ( $row['pipeline_kind'] ?? $definition['kind'] ?? '' ), 'pipeline_def_id' => isset( $row['pipeline_def_id'] ) ? (int) $row['pipeline_def_id'] : null, 'pipeline_def_version' => (int) ( $row['pipeline_def_version'] ?? 0 ), 'status' => (string) ( $row['status'] ?? 'open' ), 'stage' => (string) ( $custom['pipeline_stage'] ?? $row['stage'] ?? '' ), 'stages' => is_array( $custom['stages'] ?? null ) ? $custom['stages'] : array(), 'exceptions' => is_array( $custom['exceptions'] ?? null ) ? $custom['exceptions'] : array(), 'lock_version' => (int) ( $custom['_lock'] ?? 0 ),
		// [2026-09-23 PHASE-0.69] Generic top-level custom_json fields, exposed whenever present — any kind
		// may set them, only `service` does today. `null`/empty-array when unset, never a missing key, so a
		// consumer never needs an `isset()` guard.
		'appointment_at' => isset( $custom['appointment_at'] ) ? (string) $custom['appointment_at'] : null,
		'service_key' => isset( $custom['service_key'] ) ? (string) $custom['service_key'] : null,
		'service_address' => is_array( $custom['service_address'] ?? null ) ? $custom['service_address'] : null,
		'team_id' => isset( $custom['team_id'] ) ? (int) $custom['team_id'] : null,
		'reassign_suggestion' => is_array( $custom['reassign_suggestion'] ?? null ) ? $custom['reassign_suggestion'] : null,
		'definition' => $definition ); }

	/**
	 * Recipient-resolution context for one stage of a run (0.63 §3.5 `roles{}`).
	 *
	 * `assignee_of_stage` means whoever actually worked THIS stage, not the run's overall owner — read
	 * from `custom_json.stages[key].by` (set by every `transition()`), falling back to the run owner
	 * when the stage has not been touched yet (e.g. a gate/SLA rule firing before anyone opened it).
	 *
	 * @return array{run_id:int,stage_key:string,owner_id:int,creator_id:int,assignee_id:int}
	 */
	public static function role_context( int $run_id, string $stage_key = '' ): array {
		$row = self::load_row( $run_id );
		if ( ! $row ) { return array( 'run_id' => $run_id, 'stage_key' => $stage_key, 'owner_id' => 0, 'creator_id' => 0, 'assignee_id' => 0 ); }
		$custom = self::decode( $row['custom_json'] ?? '' );
		$owner_id = (int) ( $row['owner_id'] ?? 0 );
		$stage_entry = '' !== $stage_key && is_array( $custom['stages'][ $stage_key ] ?? null ) ? $custom['stages'][ $stage_key ] : array();
		// `assignee_id` (explicit, set via `stage_assignee_id` — M-02) wins over `by` (who merely
		// transitioned the stage) when both are present.
		$stage_by = (int) ( $stage_entry['assignee_id'] ?? $stage_entry['by'] ?? 0 );
		return array(
			'run_id'      => $run_id,
			'stage_key'   => $stage_key,
			'owner_id'    => $owner_id,
			'creator_id'  => (int) ( $row['created_by'] ?? 0 ),
			'assignee_id' => $stage_by > 0 ? $stage_by : $owner_id,
		);
	}
	private static function write_row( array $row, array $custom, string $status, string $now, array $definition ) { global $wpdb; $old_json = (string) ( $row['custom_json'] ?? '' ); $new_json = self::json( $custom ); $table = self::opportunities_table(); $sql = "UPDATE `{$table}` SET stage = %s, status = %s, custom_json = %s, updated_at = %s WHERE id = %d"; $params = array( (string) ( $custom['pipeline_stage'] ?? $row['stage'] ?? '' ), $status, $new_json, $now, (int) $row['id'] ); if ( self::lock_enabled( $definition ) ) { $sql .= ' AND custom_json = %s'; $params[] = $old_json; } $changed = $wpdb->query( $wpdb->prepare( $sql, $params ) ); if ( 1 !== (int) $changed ) { return self::error( 'stale_write', 'Pipeline đã được thay đổi bởi người khác.', 409, 'Tải lại pipeline trước khi ghi tiếp.' ); } $row['stage'] = $custom['pipeline_stage'] ?? $row['stage']; $row['status'] = $status; $row['custom_json'] = $new_json; $row['updated_at'] = $now; return $row; }
	private static function audit( int $run_id, string $action, ?array $before, ?array $after ): void { if ( class_exists( 'BizCity_CRM_Audit_Log' ) ) { BizCity_CRM_Audit_Log::log( 'crm_opportunity', $run_id, $action, $before, $after, array( 'user_id' => function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0 ) ); } }
	private static function sync_sla( int $run_id ): void { if ( class_exists( 'BizCity_CRM_Pipeline_SLA_Service' ) && method_exists( 'BizCity_CRM_Pipeline_SLA_Service', 'sync_for_run' ) ) { BizCity_CRM_Pipeline_SLA_Service::sync_for_run( $run_id ); } }
	private static function cancel_open_sla( int $run_id, string $reason ): void { if ( class_exists( 'BizCity_CRM_Pipeline_SLA_Service' ) && method_exists( 'BizCity_CRM_Pipeline_SLA_Service', 'cancel_for_run' ) ) { BizCity_CRM_Pipeline_SLA_Service::cancel_for_run( $run_id, $reason ); } }
	private static function ensure_sub_step_tasks( int $run_id, string $stage_key, array $step, array $entry, array $row, array $args ): void { if ( 'task' !== (string) ( $step['mode'] ?? '' ) || ! class_exists( 'BizCity_CRM_DB_Installer_V2' ) ) { return; } global $wpdb; $table = BizCity_CRM_DB_Installer_V2::tbl_crm_tasks(); $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$table}` WHERE related_entity_type = 'pipeline_run' AND related_entity_id = %d AND title = %s AND deleted_at IS NULL LIMIT 1", $run_id, self::text( $step['label'] ?? $stage_key, 180 ) ) ); if ( $existing ) { return; } $now = function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ); $assignee = isset( $args['assignee_id'] ) ? (int) $args['assignee_id'] : (int) ( $entry['by'] ?? $row['owner_id'] ?? 0 ); $wpdb->insert( $table, array( 'title' => self::text( $step['label'] ?? $stage_key, 180 ), 'status' => 'open', 'priority' => 'medium', 'due_date' => null, 'assignee_id' => $assignee > 0 ? $assignee : null, 'related_entity_type' => 'pipeline_run', 'related_entity_id' => $run_id, 'notes' => self::text( $step['key'] ?? $stage_key, 180 ), 'data_json' => isset( $args['data'] ) && is_array( $args['data'] ) ? self::json( $args['data'] ) : null, 'completed' => 0, 'created_by' => isset( $args['actor_id'] ) ? (int) $args['actor_id'] : ( function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : null ), 'created_at' => $now, 'updated_at' => $now ) ); }
	private static function persist_step_evidence( int $run_id, string $stage_key, array $step, array $args, array $row ) { if ( ! class_exists( 'BizCity_CRM_DB_Installer_V2' ) ) { return new WP_Error( 'crm_storage_unavailable', 'Kho dữ liệu CRM chưa sẵn sàng.', array( 'status' => 503, 'hint' => 'Thử lại khi CRM database đã sẵn sàng.', 'help_code' => 'pipeline_storage_unavailable' ) ); } $data = is_array( $args['data'] ?? null ) ? $args['data'] : array(); $evidence = is_array( $args['evidence'] ?? null ) ? $args['evidence'] : array(); $documents = is_array( $args['documents'] ?? null ) ? $args['documents'] : array(); if ( empty( $data ) && empty( $evidence ) && empty( $documents ) ) { return true; } global $wpdb; $task_table = BizCity_CRM_DB_Installer_V2::tbl_crm_tasks(); $title = self::text( $step['label'] ?? $stage_key, 180 ); $task = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$task_table}` WHERE related_entity_type = 'pipeline_run' AND related_entity_id = %d AND title = %s AND deleted_at IS NULL ORDER BY id DESC LIMIT 1", $run_id, $title ), ARRAY_A ); $payload = array( 'stage_key' => $stage_key, 'fields' => $data, 'evidence' => $evidence, 'updated_at' => function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ) ); $existing_data = $task && ! empty( $task['data_json'] ) ? self::decode( $task['data_json'] ) : array(); $payload['history'] = is_array( $existing_data['history'] ?? null ) ? $existing_data['history'] : array(); $payload['history'][] = array( 'at' => $payload['updated_at'], 'actor_id' => isset( $args['actor_id'] ) ? (int) $args['actor_id'] : ( function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0 ), 'fields' => $data, 'evidence' => $evidence ); if ( ! $task ) { $now = $payload['updated_at']; $created = $wpdb->insert( $task_table, array( 'title' => $title, 'status' => 'done', 'priority' => 'medium', 'assignee_id' => (int) ( $args['assignee_id'] ?? $row['owner_id'] ?? 0 ) ?: null, 'related_entity_type' => 'pipeline_run', 'related_entity_id' => $run_id, 'notes' => self::text( $step['key'] ?? $stage_key, 180 ), 'data_json' => self::json( $payload ), 'completed' => 1, 'completed_at' => $now, 'created_by' => (int) ( $args['actor_id'] ?? 0 ) ?: null, 'created_at' => $now, 'updated_at' => $now ) ); if ( ! $created ) { return new WP_Error( 'evidence_task_write_failed', 'Không thể lưu dữ liệu bằng chứng của bước.', array( 'status' => 500, 'hint' => 'Thử lại trước khi đóng bước.', 'help_code' => 'pipeline_evidence_write_failed' ) ); } } else { $updated = $wpdb->update( $task_table, array( 'data_json' => self::json( $payload ), 'status' => 'done', 'completed' => 1, 'completed_at' => $payload['updated_at'], 'updated_at' => $payload['updated_at'] ), array( 'id' => (int) $task['id'] ) ); if ( false === $updated ) { return new WP_Error( 'evidence_task_write_failed', 'Không thể cập nhật dữ liệu bằng chứng của bước.', array( 'status' => 500, 'hint' => 'Thử lại trước khi đóng bước.', 'help_code' => 'pipeline_evidence_write_failed' ) ); } } if ( ! empty( $documents ) ) { $doc_table = BizCity_CRM_DB_Installer_V2::tbl_crm_documents(); foreach ( $documents as $document ) { if ( ! is_array( $document ) || '' === trim( (string) ( $document['name'] ?? '' ) ) || '' === trim( (string) ( $document['path'] ?? '' ) ) ) { continue; } $name = self::text( $document['name'], 255 ); $type = self::text( $document['type'] ?? 'file', 64 ); $original_path = (string) $document['path']; $path = function_exists( 'esc_url_raw' ) ? esc_url_raw( $original_path ) : self::text( $original_path, 512 ); $remote_only = false; if ( self::is_evidence_photo_type( $type ) && self::is_remote_evidence_url( $path ) ) { $mirrored = self::sideload_evidence_photo( $path, $name ); if ( null !== $mirrored ) { $path = $mirrored; } else { $remote_only = true; } } $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$doc_table}` WHERE related_entity_type = 'pipeline_run' AND related_entity_id = %d AND name = %s AND path = %s LIMIT 1", $run_id, $name, $path ) ); if ( $exists ) { continue; } $wpdb->insert( $doc_table, array( 'name' => $name, 'type' => $type, 'size_bytes' => max( 0, (int) ( $document['size_bytes'] ?? 0 ) ), 'path' => $path, 'uploaded_by' => (int) ( $args['actor_id'] ?? 0 ) ?: ( function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : null ), 'related_entity_type' => 'pipeline_run', 'related_entity_id' => $run_id, 'uploaded_at' => $payload['updated_at'] ) ); if ( ! $wpdb->insert_id ) { return new WP_Error( 'evidence_document_write_failed', 'Không thể gắn tài liệu bằng chứng cho pipeline.', array( 'status' => 500, 'hint' => 'Kiểm tra tài liệu rồi thử lại.', 'help_code' => 'pipeline_document_write_failed' ) ); } if ( $remote_only && class_exists( 'BizCity_CRM_Audit_Log' ) ) { BizCity_CRM_Audit_Log::log( 'crm_document', (int) $wpdb->insert_id, 'evidence_remote_only', null, array( 'run_id' => $run_id, 'stage_key' => $stage_key, 'name' => $name ), array( 'user_id' => (int) ( $args['actor_id'] ?? 0 ) ) ); } } } return true; }

	/**
	 * PHASE-0.69 §4.5/L-08 — a Zalo attachment's `data_url` is the provider's CDN, which is not durable
	 * (`class-adapter-zalo.php:40-46`: nothing downloads it to WP Media). An evidence photo IS the record
	 * of a completed step (booking check-in, QC pass, goods-received) — a broken image link there is a
	 * broken audit trail, not a missing thumbnail. So a `photo`/`image` evidence document with a remote URL
	 * gets mirrored into WP Media at the moment the step closes; only evidence documents, never the whole
	 * chat's photos (that would be unbounded storage growth for no audit value).
	 */
	private static function is_evidence_photo_type( string $type ): bool {
		return in_array( strtolower( $type ), array( 'photo', 'image' ), true );
	}

	/** A provider CDN link, not already a local WP Media URL (so re-running this on an already-mirrored
	 * document is a no-op, not a re-download). */
	private static function is_remote_evidence_url( string $path ): bool {
		if ( ! preg_match( '#^https?://#i', $path ) ) { return false; }
		$upload_base = function_exists( 'wp_upload_dir' ) ? (string) ( wp_upload_dir()['baseurl'] ?? '' ) : '';
		return '' === $upload_base || 0 !== strpos( $path, $upload_base );
	}

	/**
	 * @return string|null Local WP Media URL, or null when the download/attach failed — caller keeps the
	 *                      original remote URL and flags `evidence_remote_only` (never blocks the step).
	 */
	private static function sideload_evidence_photo( string $url, string $name ) {
		if ( ! self::ensure_media_includes() ) {
			return null;
		}
		$tmp = download_url( $url, 15 );
		if ( is_wp_error( $tmp ) ) {
			return null;
		}
		$basename = '' !== trim( $name ) ? $name : ( (string) ( parse_url( $url, PHP_URL_PATH ) ?: 'evidence.jpg' ) );
		$file_array = array( 'name' => function_exists( 'sanitize_file_name' ) ? sanitize_file_name( $basename ) : $basename, 'tmp_name' => $tmp );
		$attachment_id = media_handle_sideload( $file_array, 0 );
		if ( is_wp_error( $attachment_id ) ) {
			if ( file_exists( $tmp ) ) { @unlink( $tmp ); }
			return null;
		}
		$local_url = wp_get_attachment_url( $attachment_id );
		return is_string( $local_url ) && '' !== $local_url ? $local_url : null;
	}

	/** WP Media functions are admin-only includes — load them on demand (this runs from a REST request,
	 * never wp-admin). Degrades to "unavailable" rather than fatal when the install is unusually thin. */
	private static function ensure_media_includes(): bool {
		if ( function_exists( 'media_handle_sideload' ) && function_exists( 'download_url' ) ) {
			return true;
		}
		if ( ! defined( 'ABSPATH' ) ) {
			return false;
		}
		foreach ( array( 'wp-admin/includes/file.php', 'wp-admin/includes/media.php', 'wp-admin/includes/image.php' ) as $relative ) {
			$file = ABSPATH . $relative;
			if ( is_file( $file ) ) { require_once $file; }
		}
		return function_exists( 'media_handle_sideload' ) && function_exists( 'download_url' );
	}
	private static function emit_lifecycle( int $run_id, int $contact_id, string $kind, string $stage, string $event ): void { if ( class_exists( 'BizCity_CRM_Event_Emitter' ) ) { BizCity_CRM_Event_Emitter::emit( 'crm_pipeline_lifecycle', array( 'run_id' => $run_id, 'contact_id' => max( 0, $contact_id ), 'pipeline_kind' => sanitize_key( $kind ), 'stage_key' => self::text( $stage, 64 ), 'event' => sanitize_key( $event ), 'occurred_at' => function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ) ) ); } self::report_lifecycle_metric( $run_id, $contact_id, $event ); }
	private static function report_lifecycle_metric( int $run_id, int $contact_id, string $event ): void { if ( ! class_exists( 'BizCity_CRM_Reporting_Rollup' ) ) { return; } $metric = array( 'stage_started' => 'pipeline_stage_changed', 'stage_done' => 'pipeline_step_done', 'stage_doing' => 'pipeline_stage_changed', 'stage_blocked' => 'pipeline_stage_changed', 'exception_open' => 'pipeline_exception_opened', 'exception_ack' => 'pipeline_exception_acknowledged', 'exception_resolved' => 'pipeline_exception_resolved' )[ $event ] ?? ''; if ( '' !== $metric ) { BizCity_CRM_Reporting_Rollup::record_fact( $metric, 0, gmdate( 'Y-m-d H:i:s' ), 1, 'pipeline:' . $run_id . ':' . $event . ':' . microtime( true ), $contact_id ); } }
	private static function lock_enabled( array $definition ): bool { return ! empty( $definition['lock_version'] ); }
	private static function clean_kind( string $kind ): string { return preg_match( '/^[a-z][a-z0-9_-]{0,31}$/', $kind ) ? $kind : ''; }
	private static function text( $value, int $limit ): string { $value = trim( (string) $value ); return function_exists( 'sanitize_text_field' ) ? substr( sanitize_text_field( $value ), 0, $limit ) : substr( $value, 0, $limit ); }
	private static function json( array $value ): string { return function_exists( 'wp_json_encode' ) ? wp_json_encode( $value ) : json_encode( $value ); }
	private static function decode( $raw ): array { $value = json_decode( (string) $raw, true ); return is_array( $value ) ? $value : array(); }
	private static function error( string $code, string $message, int $status, string $hint ): WP_Error { return new WP_Error( $code, $message, array( 'status' => $status, 'hint' => $hint, 'help_code' => 'pipeline_' . $code ) ); }
}
