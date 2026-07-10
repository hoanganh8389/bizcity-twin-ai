<?php
/**
 * Bizcity TwinChat — Welcome Job Queue (Sprint 5.1)
 *
 * Schedules an async background job that generates the AI-welcome message
 * after a new source is ingested into a TwinChat notebook. Mirrors the
 * Learning_Job_Queue pattern (Action Scheduler when present, wp-cron+spawn
 * otherwise) but lives independently so a heavy learning job does not block
 * the cheap welcome summary.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Modules\TwinChat\Welcome
 * @since 2026-04-30
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_TwinChat_Welcome_Job_Queue {

	const HOOK_RUN = 'bizcity_twinchat_welcome_run';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Insert tc_welcome_jobs row + schedule async worker.
	 *
	 * @param array $args { notebook_id (int, required), source_id (int, required), user_id (int) }
	 * @return int|WP_Error new job id on success
	 */
	public function enqueue( array $args ) {
		$notebook_id = (int) ( $args['notebook_id'] ?? 0 );
		$source_id   = (int) ( $args['source_id']   ?? 0 );
		$user_id     = (int) ( $args['user_id']     ?? get_current_user_id() );

		if ( $notebook_id <= 0 || $source_id <= 0 ) {
			return new WP_Error( 'invalid_args', 'notebook_id + source_id required' );
		}

		$db = BizCity_TwinChat_Welcome_Database::instance();
		$db->maybe_install();

		// Dedupe: if a welcome was already scheduled / done for this source, skip.
		if ( $db->source_already_welcomed( $notebook_id, $source_id ) ) {
			return new WP_Error( 'already_welcomed', 'welcome already in flight or done' );
		}

		$job_id = $db->insert( [
			'notebook_id' => $notebook_id,
			'source_id'   => $source_id,
			'user_id'     => $user_id,
			'status'      => 'queued',
		] );
		if ( $job_id <= 0 ) {
			return new WP_Error( 'insert_failed', 'Failed to insert welcome job row' );
		}

		// Twin Event Stream — lifecycle event #1 of 2 (started). Wave 5.0b
		// taxonomy registered welcome_job (status: started|completed|failed).
		$this->dispatch_event( 'started', [
			'job_id'      => $job_id,
			'notebook_id' => $notebook_id,
			'source_id'   => $source_id,
		], $notebook_id );

		$this->schedule( $job_id, $notebook_id );

		return $job_id;
	}

	/**
	 * Schedule worker.
	 *
	 * NOTE 2026-04-30 — In this environment Action Scheduler async actions sit
	 * indefinitely without a tick (loopback-blocked / no admin pageload). The
	 * learning queue side-steps this by exposing a `/learning/jobs/{id}/tick`
	 * endpoint that the FE drives via XHR. For the welcome lane we take a
	 * simpler route: schedule via wp-cron + immediately spawn a non-blocking
	 * loopback to wp-cron.php. If that loopback is also blocked, the request's
	 * own `shutdown` action will run the job synchronously after the response
	 * has been sent (via fastcgi_finish_request when available) so the
	 * welcome bubble still appears within seconds of the upload completing.
	 */
	protected function schedule( $job_id, $notebook_id ) {
		$args = [ (int) $job_id ];

		// Path A — wp-cron (loopback). Cheap; no external dep.
		if ( ! wp_next_scheduled( self::HOOK_RUN, $args ) ) {
			wp_schedule_single_event( time(), self::HOOK_RUN, $args );
		}
		$this->spawn_cron();

		// Path B — guaranteed local fallback. Run inline at PHP shutdown so
		// even when both AS and wp-cron loopbacks are unreachable the welcome
		// still fires within this request's lifecycle. We flush the response
		// first (where supported) so the user's upload XHR returns immediately.
		add_action( 'shutdown', function () use ( $job_id ) {
			static $ran = [];
			if ( isset( $ran[ $job_id ] ) ) {
				return;
			}
			$ran[ $job_id ] = true;

			// Re-check status inside shutdown — wp-cron may have already run it.
			$row = BizCity_TwinChat_Welcome_Database::instance()->get( $job_id );
			if ( ! $row || ! in_array( $row['status'], [ 'queued', 'running' ], true ) ) {
				return;
			}

			if ( function_exists( 'fastcgi_finish_request' ) ) {
				@fastcgi_finish_request();
			}
			try {
				BizCity_TwinChat_Welcome_Runner::instance()->run_job( (int) $job_id );
			} catch ( \Throwable $e ) {
				error_log( '[twinchat-welcome] shutdown-runner threw: ' . $e->getMessage() );
			}
		}, 99 );
	}

	/** Best-effort non-blocking ping to wp-cron.php to fire the scheduled event right away. */
	protected function spawn_cron() {
		$url = site_url( 'wp-cron.php?doing_wp_cron=' . microtime( true ) );
		wp_remote_post( $url, [
			'timeout'   => 0.01,
			'blocking'  => false,
			'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
		] );
	}

	/** Internal: emit a welcome_job v2 event. Swallowed exceptions — events must never break the pipeline. */
	public static function dispatch_event( $status, array $payload, $notebook_id = 0 ) {
		if ( ! class_exists( 'BizCity_Twin_Event_Bus' ) ) {
			return;
		}
		try {
			$payload['status']      = (string) $status;
			$payload['notebook_id'] = isset( $payload['notebook_id'] ) ? (int) $payload['notebook_id'] : (int) $notebook_id;
			BizCity_Twin_Event_Bus::dispatch_v2( 'welcome_job', $payload, [
				'event_source' => 'system',
			] );
		} catch ( \Throwable $e ) {
			error_log( '[twinchat-welcome] dispatch_v2 failed: ' . $e->getMessage() );
		}
	}
}
