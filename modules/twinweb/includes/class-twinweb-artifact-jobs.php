<?php
/**
 * TwinWeb — Artifact Jobs Store
 *
 * Durable async state for Twin GPT Agent Tool handoffs and Artifact Canvas polling.
 *
 * PHP 7.4 compatible.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Modules\TwinWeb
 * @since 2026-07-20 (PHASE-TWIN-GPT-AGENT-TOOLS AT-7)
 */
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_TwinWeb_Artifact_Jobs' ) ) { return; }

class BizCity_TwinWeb_Artifact_Jobs {

	const CRON_HOOK     = 'bizcity_twinweb_artifact_jobs_poll';
	const CRON_INTERVAL = 'bizcity_twinweb_artifact_jobs_minute';
	const CRON_JOB_ID   = 'twinweb.artifact_jobs_poll';
	const CRON_BATCH    = 20;

	const STATUS_QUEUED  = 'queued';
	const STATUS_RUNNING = 'running';
	const STATUS_WAITING = 'waiting_owner';
	const STATUS_READY   = 'ready';
	const STATUS_FAILED  = 'failed';
	const STATUS_CANCELLED = 'cancelled';

	/** @var array<int,bool> */
	private static $table_ready_cache = array();

	/** @var bool */
	private static $cron_booted = false;

	/**
	 * Register cron hook/schedule for durable artifact job polling.
	 */
	public static function init_cron() {
		// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — AT-7.6 durable owner-status poller boot.
		if ( self::$cron_booted ) {
			return;
		}
		self::$cron_booted = true;

		add_filter( 'cron_schedules', array( __CLASS__, 'register_cron_interval' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_cron' ) );

		if ( class_exists( 'BizCity_Cron_Manager' ) ) {
			BizCity_Cron_Manager::instance()->register( array(
				'id'          => self::CRON_JOB_ID,
				'hook'        => self::CRON_HOOK,
				'interval'    => self::CRON_INTERVAL,
				'owner'       => 'modules/twinweb',
				'description' => 'Poll durable Twin GPT artifact jobs and sync owner-service status.',
				'singleton'   => true,
				'enabled'     => true,
				'retention'   => 7,
			) );
			return;
		}

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, self::CRON_INTERVAL, self::CRON_HOOK );
		}
	}

	/**
	 * Add TwinWeb artifact poll interval.
	 *
	 * @param array $schedules Cron schedules.
	 * @return array
	 */
	public static function register_cron_interval( $schedules ) {
		// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — one-minute poll cadence for visible Canvas jobs.
		if ( ! is_array( $schedules ) ) {
			$schedules = array();
		}
		if ( ! isset( $schedules[ self::CRON_INTERVAL ] ) ) {
			$schedules[ self::CRON_INTERVAL ] = array(
				'interval' => 60,
				'display'  => __( 'Every minute - Twin GPT artifact jobs', 'bizcity-twin-ai' ),
			);
		}
		return $schedules;
	}

	/**
	 * Cron callback: poll due non-terminal artifact jobs.
	 */
	public static function run_cron() {
		// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — R-CRON-META counters/reason buckets for AT-7.6 polling.
		$summary = self::poll_due_jobs( self::CRON_BATCH );
		if ( class_exists( 'BizCity_Cron_Manager' ) ) {
			$cron = BizCity_Cron_Manager::instance();
			$cron->note( array( 'counters' => array(
				'artifact_jobs_scanned' => (int) ( $summary['scanned'] ?? 0 ),
				'artifact_jobs_ready'   => (int) ( $summary['ready'] ?? 0 ),
				'artifact_jobs_pending' => (int) ( $summary['pending'] ?? 0 ),
				'artifact_jobs_failed'  => (int) ( $summary['failed'] ?? 0 ),
				'artifact_jobs_skipped' => (int) ( $summary['skipped'] ?? 0 ),
			) ) );
			foreach ( (array) ( $summary['events'] ?? array() ) as $event ) {
				if ( is_array( $event ) && ! empty( $event['name'] ) ) {
					$name = (string) $event['name'];
					unset( $event['name'] );
					$cron->note_event( $name, $event );
				}
			}
		}
	}

	/**
	 * Poll due jobs once.
	 *
	 * @param int $limit Batch limit.
	 * @return array
	 */
	public static function poll_due_jobs( $limit = 20 ) {
		// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — owner-service status sync for durable Artifact Canvas jobs.
		$summary = array(
			'scanned' => 0,
			'ready'   => 0,
			'pending' => 0,
			'failed'  => 0,
			'skipped' => 0,
			'events'  => array(),
		);
		if ( ! self::table_ready() ) {
			$summary['events'][] = array( 'name' => 'artifact_jobs_poll_skipped', 'reason' => 'schema_missing' );
			return $summary;
		}

		$jobs = self::get_due_jobs( $limit );
		if ( empty( $jobs ) ) {
			return $summary;
		}

		$original_user_id = (int) get_current_user_id();
		foreach ( $jobs as $job ) {
			$summary['scanned']++;
			try {
				$result = self::poll_one_job( $job );
			} catch ( \Throwable $e ) {
				// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — fail one job closed, keep AT-7.6 cron batch alive.
				$result = self::mark_job_poll_exception( $job, $e );
			} finally {
				wp_set_current_user( $original_user_id );
			}
			$bucket = isset( $result['bucket'] ) ? (string) $result['bucket'] : 'pending';
			if ( isset( $summary[ $bucket ] ) ) {
				$summary[ $bucket ]++;
			}
			if ( ! empty( $result['event'] ) && is_array( $result['event'] ) ) {
				$summary['events'][] = $result['event'];
			}
		}
		return $summary;
	}

	/**
	 * Full table name for the current blog.
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_twinweb_artifact_jobs';
	}

	/**
	 * Create a durable artifact job row.
	 *
	 * @param array $data Job attributes.
	 * @return array|WP_Error Normalized row or error.
	 */
	public static function create( array $data ) {
		// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — create owner-scoped durable job state for AT-7.
		if ( ! self::ensure_table_ready() ) {
			return new WP_Error( 'schema_missing', 'Twin GPT artifact jobs table is not ready.' );
		}

		global $wpdb;
		$job_id = isset( $data['job_id'] ) ? sanitize_key( (string) $data['job_id'] ) : '';
		if ( '' === $job_id ) {
			$job_id = self::new_job_id();
		}

		$identity = isset( $data['identity'] ) && is_array( $data['identity'] ) ? $data['identity'] : array();
		$now      = current_time( 'mysql' );
		$insert   = array(
			'job_id'        => $job_id,
			'run_id'        => self::sanitize_short_text( $data, 'run_id', 80 ),
			'thread_id'     => self::sanitize_short_text( $data, 'thread_id', 80 ),
			'message_id'    => isset( $data['message_id'] ) ? absint( $data['message_id'] ) : 0,
			'owner_user_id' => isset( $identity['user_id'] ) ? absint( $identity['user_id'] ) : ( isset( $data['owner_user_id'] ) ? absint( $data['owner_user_id'] ) : 0 ),
			'guest_sid'     => isset( $identity['guest_sid'] ) ? sanitize_text_field( (string) $identity['guest_sid'] ) : self::sanitize_short_text( $data, 'guest_sid', 64 ),
			'tool_slug'     => self::sanitize_short_text( $data, 'tool_slug', 96 ),
			'artifact_type' => self::sanitize_short_text( $data, 'artifact_type', 32 ),
			'status'        => self::normalize_status( isset( $data['status'] ) ? (string) $data['status'] : self::STATUS_QUEUED ),
			'progress'      => self::normalize_progress( isset( $data['progress'] ) ? $data['progress'] : 0 ),
			'reason_bucket' => self::sanitize_short_text( $data, 'reason_bucket', 64 ),
			'owner_job_id'  => self::sanitize_short_text( $data, 'owner_job_id', 80 ),
			'artifact_id'   => self::sanitize_short_text( $data, 'artifact_id', 80 ),
			'status_url'    => isset( $data['status_url'] ) ? esc_url_raw( (string) $data['status_url'] ) : '',
			'preview_url'   => isset( $data['preview_url'] ) ? esc_url_raw( (string) $data['preview_url'] ) : '',
			'download_url'  => isset( $data['download_url'] ) ? esc_url_raw( (string) $data['download_url'] ) : '',
			'input_json'    => self::encode_json( isset( $data['input'] ) ? $data['input'] : ( isset( $data['input_json'] ) ? $data['input_json'] : null ) ),
			'result_json'   => self::encode_json( isset( $data['result'] ) ? $data['result'] : ( isset( $data['result_json'] ) ? $data['result_json'] : null ) ),
			'error_payload' => self::encode_json( isset( $data['error_payload'] ) ? $data['error_payload'] : null ),
			'attempt_count' => isset( $data['attempt_count'] ) ? absint( $data['attempt_count'] ) : 0,
			'created_at'    => $now,
			'updated_at'    => $now,
			'next_poll_at'  => self::sanitize_datetime( isset( $data['next_poll_at'] ) ? $data['next_poll_at'] : '' ),
		);

		$ok = $wpdb->insert( self::table(), $insert );
		if ( false === $ok ) {
			return new WP_Error( 'db_insert_failed', 'Could not create Twin GPT artifact job.' );
		}

		return self::get_by_job_id( $job_id );
	}

	/**
	 * Fetch one job by public job id, optionally enforcing owner identity.
	 *
	 * @param string $job_id Job id.
	 * @param array  $identity Optional identity tuple.
	 * @return array|null|WP_Error
	 */
	public static function get_by_job_id( $job_id, array $identity = array() ) {
		// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — status reads are owner-scoped for public Twin GPT.
		$job_id = sanitize_key( (string) $job_id );
		if ( '' === $job_id || ! self::table_ready() ) {
			return null;
		}

		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE job_id = %s LIMIT 1', $job_id ) );
		if ( ! $row ) {
			return null;
		}

		if ( ! empty( $identity ) && ! self::row_belongs_to_identity( $row, $identity ) ) {
			return new WP_Error( 'permission_denied', 'Artifact job does not belong to current identity.' );
		}

		return self::normalize_row( $row );
	}

	/**
	 * Requeue a failed owner-backed job for another status poll.
	 *
	 * @param string $job_id
	 * @param array  $identity
	 * @return array|WP_Error|null
	 */
	public static function retry( $job_id, array $identity = array() ) {
		// [2026-08-01 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS AT-7 — owner-scoped retry resets durable state without invoking a provider from REST.
		$job = self::get_by_job_id( $job_id, $identity );
		if ( is_wp_error( $job ) || null === $job ) {
			return $job;
		}

		if ( self::STATUS_FAILED !== (string) ( $job['status'] ?? '' ) ) {
			return new WP_Error( 'retry_unavailable', 'Artifact này chưa ở trạng thái có thể thử lại.' );
		}
		$status_url = (string) ( $job['status_url'] ?? '' );
		if ( '' === $status_url || false !== strpos( $status_url, '/artifacts/jobs/' ) ) {
			return new WP_Error( 'retry_unavailable', 'Artifact chưa có owner job để thử lại.' );
		}

		return self::update( $job_id, array(
			'status'        => self::STATUS_QUEUED,
			'progress'      => 0,
			'reason_bucket' => '',
			'error_payload' => null,
			'attempt_count' => (int) ( $job['attempt_count'] ?? 0 ) + 1,
			'last_poll_at'  => null,
			'finished_at'   => null,
			'next_poll_at'  => current_time( 'mysql' ),
		) );
	}

	/**
	 * Update one job by id.
	 *
	 * @param string $job_id Job id.
	 * @param array  $fields Allowed fields to patch.
	 * @return array|WP_Error|null
	 */
	public static function update( $job_id, array $fields ) {
		// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — central patch path keeps status/progress normalized.
		$job_id = sanitize_key( (string) $job_id );
		if ( '' === $job_id || ! self::table_ready() ) {
			return null;
		}

		$patch = array( 'updated_at' => current_time( 'mysql' ) );
		foreach ( array( 'run_id', 'thread_id', 'tool_slug', 'artifact_type', 'reason_bucket', 'owner_job_id', 'artifact_id' ) as $key ) {
			if ( array_key_exists( $key, $fields ) ) {
				$patch[ $key ] = self::sanitize_short_text( $fields, $key, $key === 'tool_slug' ? 96 : 80 );
			}
		}
		foreach ( array( 'status_url', 'preview_url', 'download_url' ) as $key ) {
			if ( array_key_exists( $key, $fields ) ) {
				$patch[ $key ] = esc_url_raw( (string) $fields[ $key ] );
			}
		}
		if ( array_key_exists( 'status', $fields ) ) {
			$patch['status'] = self::normalize_status( (string) $fields['status'] );
		}
		if ( array_key_exists( 'progress', $fields ) ) {
			$patch['progress'] = self::normalize_progress( $fields['progress'] );
		}
		if ( array_key_exists( 'attempt_count', $fields ) ) {
			$patch['attempt_count'] = absint( $fields['attempt_count'] );
		}
		foreach ( array( 'input_json' => 'input', 'result_json' => 'result', 'error_payload' => 'error_payload' ) as $column => $alias ) {
			if ( array_key_exists( $alias, $fields ) ) {
				$patch[ $column ] = self::encode_json( $fields[ $alias ] );
			} elseif ( array_key_exists( $column, $fields ) ) {
				$patch[ $column ] = self::encode_json( $fields[ $column ] );
			}
		}
		foreach ( array( 'started_at', 'finished_at', 'last_poll_at', 'next_poll_at' ) as $key ) {
			if ( array_key_exists( $key, $fields ) ) {
				$patch[ $key ] = self::sanitize_datetime( $fields[ $key ] );
			}
		}

		global $wpdb;
		$ok = $wpdb->update( self::table(), $patch, array( 'job_id' => $job_id ) );
		if ( false === $ok ) {
			return new WP_Error( 'db_update_failed', 'Could not update Twin GPT artifact job.' );
		}

		return self::get_by_job_id( $job_id );
	}

	/**
	 * Return due jobs ordered oldest first.
	 *
	 * @param int $limit Batch size.
	 * @return array
	 */
	private static function get_due_jobs( $limit ) {
		global $wpdb;
		$limit = max( 1, min( 100, absint( $limit ) ) );
		$now = current_time( 'mysql' );
		$table = self::table();
		return (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table}
			 WHERE status IN ('queued','running','waiting_owner')
			   AND status_url <> ''
			   AND (next_poll_at IS NULL OR next_poll_at = '0000-00-00 00:00:00' OR next_poll_at <= %s)
			 ORDER BY COALESCE(next_poll_at, created_at) ASC, id ASC
			 LIMIT %d",
			$now,
			$limit
		) );
	}

	/**
	 * Poll one owner status URL and patch durable job state.
	 *
	 * @param object $job DB row.
	 * @return array
	 */
	private static function poll_one_job( $job ) {
		$job_id = (string) $job->job_id;
		$status_url = (string) $job->status_url;
		if ( false !== strpos( $status_url, '/artifacts/jobs/' ) ) {
			self::update( $job_id, array(
				'next_poll_at'  => self::next_poll_at( 300 ),
				'reason_bucket' => 'self_status_url_skipped',
			) );
			return array( 'bucket' => 'skipped', 'event' => array( 'name' => 'artifact_jobs_poll_skipped', 'job_id' => $job_id, 'reason' => 'self_status_url_skipped' ) );
		}

		$owner_user_id = (int) $job->owner_user_id;
		if ( $owner_user_id <= 0 ) {
			self::update( $job_id, array(
				'status'        => self::STATUS_FAILED,
				'progress'      => 0,
				'reason_bucket' => 'owner_missing',
				'error_payload' => self::error_payload( 'permission_denied', 'Không xác định được chủ sở hữu artifact.', 'Đăng nhập rồi tạo lại artifact.', 'permission_denied' ),
				'finished_at'   => current_time( 'mysql' ),
			) );
			return array( 'bucket' => 'failed', 'event' => array( 'name' => 'artifact_jobs_poll_failed', 'job_id' => $job_id, 'reason' => 'owner_missing' ) );
		}

		$request = self::request_from_status_url( $status_url );
		if ( ! $request ) {
			self::update( $job_id, array(
				'next_poll_at'  => self::next_poll_at( 300 ),
				'reason_bucket' => 'invalid_status_url',
			) );
			return array( 'bucket' => 'skipped', 'event' => array( 'name' => 'artifact_jobs_poll_skipped', 'job_id' => $job_id, 'reason' => 'invalid_status_url' ) );
		}

		wp_set_current_user( $owner_user_id );
		$response = rest_do_request( $request );
		if ( is_wp_error( $response ) ) {
			self::update( $job_id, array(
				'status'        => self::STATUS_WAITING,
				'next_poll_at'  => self::next_poll_at( 120 ),
				'reason_bucket' => 'owner_status_error',
				'last_poll_at'  => current_time( 'mysql' ),
			) );
			return array( 'bucket' => 'pending', 'event' => array( 'name' => 'artifact_jobs_poll_pending', 'job_id' => $job_id, 'reason' => 'owner_status_error', 'error_code' => $response->get_error_code() ) );
		}

		$data = (array) rest_ensure_response( $response )->get_data();
		return self::apply_owner_status_payload( $job, $data );
	}

	/**
	 * Convert an owner status REST payload into job state.
	 *
	 * @param object $job DB row.
	 * @param array  $data Owner payload.
	 * @return array
	 */
	private static function apply_owner_status_payload( $job, array $data ) {
		$job_id = (string) $job->job_id;
		$owner_status = sanitize_key( (string) ( $data['status'] ?? 'unknown' ) );
		if ( 'done' === $owner_status ) {
			$owner_status = 'ready';
		}
		$preview_url = isset( $data['preview_url'] ) ? (string) $data['preview_url'] : '';
		$download_url = isset( $data['download_url'] ) ? (string) $data['download_url'] : '';
		if ( ( '' === $preview_url || '' === $download_url ) && ! empty( $data['data']['variants'] ) && is_array( $data['data']['variants'] ) ) {
			$first = isset( $data['data']['variants'][0] ) && is_array( $data['data']['variants'][0] ) ? $data['data']['variants'][0] : array();
			$preview_url = $preview_url ?: (string) ( $first['url'] ?? '' );
			$download_url = $download_url ?: (string) ( $first['url'] ?? '' );
		}

		if ( 'ready' === $owner_status ) {
			self::update( $job_id, array(
				'status'       => self::STATUS_READY,
				'progress'     => 100,
				'preview_url'  => $preview_url,
				'download_url' => $download_url,
				'result'       => $data,
				'last_poll_at' => current_time( 'mysql' ),
				'finished_at'  => current_time( 'mysql' ),
			) );
			return array( 'bucket' => 'ready', 'event' => array( 'name' => 'artifact_jobs_poll_ready', 'job_id' => $job_id, 'artifact_type' => (string) $job->artifact_type ) );
		}

		if ( 'failed' === $owner_status || empty( $data['success'] ) && ! empty( $data['code'] ) ) {
			$code = sanitize_key( (string) ( $data['code'] ?? $data['error_code'] ?? 'owner_status_failed' ) );
			$message = sanitize_text_field( (string) ( $data['message'] ?? $data['error'] ?? 'Artifact owner service failed.' ) );
			self::update( $job_id, array(
				'status'        => self::STATUS_FAILED,
				'progress'      => 0,
				'reason_bucket' => $code,
				'error_payload' => self::error_payload( $code, $message, 'Tạo lại artifact hoặc kiểm tra owner service.', 'automation_run_failed' ),
				'result'        => $data,
				'last_poll_at'  => current_time( 'mysql' ),
				'finished_at'   => current_time( 'mysql' ),
			) );
			return array( 'bucket' => 'failed', 'event' => array( 'name' => 'artifact_jobs_poll_failed', 'job_id' => $job_id, 'reason' => $code ) );
		}

		$progress = isset( $data['progress'] ) ? absint( $data['progress'] ) : max( 10, (int) $job->progress );
		self::update( $job_id, array(
			'status'       => self::STATUS_WAITING,
			'progress'     => $progress,
			'result'       => $data,
			'last_poll_at' => current_time( 'mysql' ),
			'next_poll_at' => self::next_poll_at( 60 ),
		) );
		return array( 'bucket' => 'pending', 'event' => array( 'name' => 'artifact_jobs_poll_pending', 'job_id' => $job_id, 'status' => $owner_status ) );
	}

	/**
	 * Mark a single job after an unexpected poll exception.
	 *
	 * @param object     $job DB row.
	 * @param \Throwable $e Exception.
	 * @return array
	 */
	private static function mark_job_poll_exception( $job, \Throwable $e ) {
		$job_id = isset( $job->job_id ) ? (string) $job->job_id : '';
		if ( '' !== $job_id ) {
			self::update( $job_id, array(
				'status'        => self::STATUS_WAITING,
				'next_poll_at'  => self::next_poll_at( 300 ),
				'reason_bucket' => 'poll_exception',
				'error_payload' => self::error_payload( 'automation_run_failed', 'Không kiểm tra được trạng thái artifact.', 'Thử lại sau hoặc mở artifact trong app gốc.', 'automation_run_failed' ),
				'last_poll_at'  => current_time( 'mysql' ),
			) );
		}
		return array(
			'bucket' => 'pending',
			'event'  => array(
				'name'            => 'artifact_jobs_poll_exception',
				'job_id'          => $job_id,
				'reason'          => 'poll_exception',
				'exception_class' => get_class( $e ),
				'message'         => sanitize_text_field( $e->getMessage() ),
			),
		);
	}

	/**
	 * Convert a DB row to API-safe payload.
	 *
	 * @param object $row Raw DB row.
	 * @return array
	 */
	public static function normalize_row( $row ) {
		$status = self::normalize_status( (string) $row->status );
		return array(
			'job_id'        => (string) $row->job_id,
			'run_id'        => (string) $row->run_id,
			'thread_id'     => (string) $row->thread_id,
			'message_id'    => (int) $row->message_id,
			'owner_user_id' => (int) $row->owner_user_id,
			'guest_sid'     => (string) $row->guest_sid,
			'tool_slug'     => (string) $row->tool_slug,
			'artifact_type' => (string) $row->artifact_type,
			'status'        => $status,
			'progress'      => self::normalize_progress( $row->progress ),
			'reason_bucket' => (string) $row->reason_bucket,
			'owner_job_id'  => (string) $row->owner_job_id,
			'artifact_id'   => (string) $row->artifact_id,
			'status_url'    => esc_url_raw( (string) $row->status_url ),
			'preview_url'   => esc_url_raw( (string) $row->preview_url ),
			'download_url'  => esc_url_raw( (string) $row->download_url ),
			'input'         => self::decode_json( $row->input_json ),
			'result'        => self::decode_json( $row->result_json ),
			'error_payload' => self::decode_json( $row->error_payload ),
			'attempt_count' => (int) $row->attempt_count,
			'created_at'    => (string) $row->created_at,
			'updated_at'    => (string) $row->updated_at,
			'started_at'    => (string) $row->started_at,
			'finished_at'   => (string) $row->finished_at,
			'last_poll_at'  => (string) $row->last_poll_at,
			'next_poll_at'  => (string) $row->next_poll_at,
			'is_terminal'   => in_array( $status, array( self::STATUS_READY, self::STATUS_FAILED, self::STATUS_CANCELLED ), true ),
		);
	}

	/**
	 * Check whether the physical table exists without SHOW TABLES.
	 */
	public static function table_ready() {
		$blog_id = (int) get_current_blog_id();
		if ( array_key_exists( $blog_id, self::$table_ready_cache ) ) {
			return self::$table_ready_cache[ $blog_id ];
		}

		$table = self::table();
		if ( function_exists( 'bizcity_tbl_exists' ) ) {
			self::$table_ready_cache[ $blog_id ] = (bool) bizcity_tbl_exists( $table );
			return self::$table_ready_cache[ $blog_id ];
		}

		global $wpdb;
		self::$table_ready_cache[ $blog_id ] = (bool) $wpdb->get_var( $wpdb->prepare(
			'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
			$table
		) );
		return self::$table_ready_cache[ $blog_id ];
	}

	private static function ensure_table_ready() {
		if ( self::table_ready() ) {
			return true;
		}
		if ( class_exists( 'BizCity_TwinWeb_Installer' ) ) {
			BizCity_TwinWeb_Installer::ensure_artifact_jobs_table();
			self::$table_ready_cache[ (int) get_current_blog_id() ] = null;
			unset( self::$table_ready_cache[ (int) get_current_blog_id() ] );
		}
		return self::table_ready();
	}

	private static function row_belongs_to_identity( $row, array $identity ) {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		$user_id = isset( $identity['user_id'] ) ? (int) $identity['user_id'] : 0;
		if ( $user_id > 0 ) {
			return (int) $row->owner_user_id === $user_id;
		}
		$guest_sid = isset( $identity['guest_sid'] ) ? (string) $identity['guest_sid'] : '';
		return $guest_sid !== '' && (string) $row->guest_sid !== '' && hash_equals( (string) $row->guest_sid, $guest_sid );
	}

	private static function new_job_id() {
		return 'twaj_' . substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 32 );
	}

	private static function normalize_status( $status ) {
		$status = sanitize_key( (string) $status );
		$allowed = array(
			self::STATUS_QUEUED,
			self::STATUS_RUNNING,
			self::STATUS_WAITING,
			self::STATUS_READY,
			self::STATUS_FAILED,
			self::STATUS_CANCELLED,
		);
		return in_array( $status, $allowed, true ) ? $status : self::STATUS_QUEUED;
	}

	private static function normalize_progress( $progress ) {
		return max( 0, min( 100, absint( $progress ) ) );
	}

	private static function sanitize_short_text( array $data, $key, $max ) {
		$value = isset( $data[ $key ] ) ? sanitize_text_field( (string) $data[ $key ] ) : '';
		if ( strlen( $value ) > $max ) {
			$value = substr( $value, 0, $max );
		}
		return $value;
	}

	private static function sanitize_datetime( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return null;
		}
		return preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value ) ? $value : null;
	}

	private static function encode_json( $value ) {
		if ( null === $value || '' === $value ) {
			return null;
		}
		if ( is_string( $value ) ) {
			$decoded = json_decode( $value, true );
			if ( json_last_error() === JSON_ERROR_NONE ) {
				$value = $decoded;
			}
		}
		$encoded = wp_json_encode( $value );
		return false === $encoded ? null : $encoded;
	}

	private static function decode_json( $value ) {
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( '' === $value ) {
			return null;
		}
		$decoded = json_decode( $value, true );
		return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
	}

	/**
	 * Build an internal REST request from a same-origin status URL.
	 *
	 * @param string $status_url Absolute or relative REST URL.
	 * @return WP_REST_Request|null
	 */
	private static function request_from_status_url( $status_url ) {
		$parts = parse_url( (string) $status_url );
		$path = isset( $parts['path'] ) ? (string) $parts['path'] : '';
		if ( '' === $path ) {
			return null;
		}

		$needle = '/bizcity-twinweb/v1/';
		$pos = strpos( $path, $needle );
		if ( false === $pos ) {
			return null;
		}
		$route = substr( $path, $pos );
		if ( false !== strpos( $route, '/artifacts/jobs/' ) ) {
			return null;
		}

		$request = new WP_REST_Request( 'GET', $route );
		$params = array();
		if ( ! empty( $parts['query'] ) ) {
			parse_str( (string) $parts['query'], $params );
			foreach ( $params as $key => $value ) {
				$request->set_param( sanitize_key( (string) $key ), is_scalar( $value ) ? (string) $value : $value );
			}
		}
		return $request;
	}

	private static function error_payload( $code, $message, $hint, $help_code ) {
		return array(
			'code'      => sanitize_key( (string) $code ),
			'message'   => sanitize_text_field( (string) $message ),
			'hint'      => sanitize_text_field( (string) $hint ),
			'help_code' => sanitize_key( (string) $help_code ),
		);
	}

	private static function next_poll_at( $seconds ) {
		// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — keep next_poll_at in WP local time; due query compares with current_time('mysql').
		return date( 'Y-m-d H:i:s', current_time( 'timestamp' ) + absint( $seconds ) );
	}
}