<?php
/**
 * Content Ops — Scheduler / Queue Worker
 *
 * Strategy: real cron preferred (WP-CLI `wp bizcity-content scheduler run`).
 * Fallback: wp_schedule_event minute tick — diagnostic WARN nếu heartbeat
 * không cập nhật < 5 phút.
 *
 * Lock model: row-level lock_token + lock_expires_at để nhiều worker không
 * publish trùng.
 *
 * @package BizCity_Twin_AI
 * @subpackage Content_Ops
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Content_Scheduler {

	const CRON_HOOK         = 'bizcity_content_scheduler_tick';
	const HEARTBEAT_OPTION  = 'bizcity_content_scheduler_heartbeat';
	const BATCH_SIZE        = 10;
	const LOCK_TTL_SECONDS  = 300;
	const MAX_ATTEMPTS      = 3;

	public static function init(): void {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_minute_schedule' ) );
		add_action( 'init', array( __CLASS__, 'maybe_schedule_cron' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'run' ) );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'bizcity-content scheduler', 'BizCity_Content_Scheduler_CLI' );
		}
	}

	public static function add_minute_schedule( $schedules ) {
		if ( ! isset( $schedules['every_minute'] ) ) {
			$schedules['every_minute'] = array(
				'interval' => 60,
				'display'  => 'Every Minute',
			);
		}
		return $schedules;
	}

	public static function maybe_schedule_cron(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, 'every_minute', self::CRON_HOOK );
		}
	}

	public static function unschedule(): void {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
		}
	}

	public static function queue_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_schedule_queue';
	}

	/**
	 * Enqueue a publish job for a post-target.
	 */
	public static function enqueue( int $post_id, int $target_id, string $run_at_mysql ): int {
		global $wpdb;
		$wpdb->insert(
			self::queue_table(),
			array(
				'post_id'    => $post_id,
				'target_id'  => $target_id,
				'blog_id'    => get_current_blog_id(),
				'run_at'     => $run_at_mysql,
				'status'     => 'pending',
				'attempts'   => 0,
				'created_at' => current_time( 'mysql' ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Main worker tick. Returns summary {processed, ok, failed}.
	 */
	public static function run(): array {
		update_option( self::HEARTBEAT_OPTION, time(), false );

		global $wpdb;
		$queue   = self::queue_table();
		$now     = current_time( 'mysql' );
		$token   = wp_generate_uuid4();
		$expires = gmdate( 'Y-m-d H:i:s', time() + self::LOCK_TTL_SECONDS );

		// Atomic-ish reserve: pick due rows that aren't currently locked.
		// (Best-effort — for high concurrency move to SELECT FOR UPDATE in a TX.)
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE $queue
				 SET lock_token=%s, lock_expires_at=%s
				 WHERE status='pending'
				   AND run_at <= %s
				   AND (lock_expires_at IS NULL OR lock_expires_at < %s)
				 ORDER BY run_at ASC
				 LIMIT %d",
				$token,
				$expires,
				$now,
				$now,
				self::BATCH_SIZE
			)
		);

		$jobs = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM $queue WHERE lock_token=%s", $token ),
			ARRAY_A
		);

		$ok     = 0;
		$failed = 0;
		foreach ( (array) $jobs as $job ) {
			$res = self::process_job( $job );
			if ( ! empty( $res['ok'] ) ) {
				++$ok;
			} else {
				++$failed;
			}
		}

		return array(
			'processed' => count( (array) $jobs ),
			'ok'        => $ok,
			'failed'    => $failed,
		);
	}

	/**
	 * Process a single queue job: load post + target, publish via channel filter,
	 * update statuses.
	 */
	public static function process_job( array $job ): array {
		global $wpdb;
		$queue = self::queue_table();
		$now   = current_time( 'mysql' );

		$post   = BizCity_Content_Post_Repo::find( (int) $job['post_id'] );
		$target = null;
		$row    = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . BizCity_Content_Post_Repo::targets_table() . ' WHERE id=%d',
				(int) $job['target_id']
			),
			ARRAY_A
		);
		if ( $row ) {
			$target = $row;
		}

		if ( ! $post || ! $target ) {
			$wpdb->update(
				$queue,
				array(
					'status'      => 'failed',
					'last_error'  => 'post_or_target_missing',
					'finished_at' => $now,
				),
				array( 'id' => (int) $job['id'] )
			);
			return array( 'ok' => false, 'error' => 'post_or_target_missing' );
		}

		/**
		 * Allow channel modules to handle publishing for their platform.
		 *
		 * Filter should return array{ok:bool, channel_message_id?:string, error?:string, response?:array}.
		 * If no handler returns ok, scheduler falls back to BizCity_Gateway_Sender.
		 */
		$default_result = array( 'ok' => false, 'error' => 'no_publisher_registered' );
		$result         = apply_filters( 'bizcity_content_publish', $default_result, $post, $target );

		// Fallback: try unified gateway sender (treat instance_id as chat_id).
		if ( empty( $result['ok'] ) && class_exists( 'BizCity_Gateway_Sender' ) && ! empty( $target['instance_id'] ) ) {
			$send = BizCity_Gateway_Sender::instance()->send(
				(string) $target['instance_id'],
				(string) ( $post['title'] . "\n\n" . wp_strip_all_tags( (string) $post['body'] ) ),
				'text',
				array()
			);
			if ( ! empty( $send['sent'] ) ) {
				$result = array( 'ok' => true, 'response' => $send );
			} else {
				$result = array(
					'ok'    => false,
					'error' => (string) ( $send['error'] ?? 'gateway_send_failed' ),
				);
			}
		}

		$attempts = (int) $job['attempts'] + 1;

		if ( ! empty( $result['ok'] ) ) {
			BizCity_Content_Post_Repo::update_target(
				(int) $target['id'],
				array(
					'publish_status'     => 'published',
					'published_at'       => $now,
					'channel_message_id' => (string) ( $result['channel_message_id'] ?? '' ),
					'error'              => null,
					'response_json'      => wp_json_encode( $result['response'] ?? null ),
				)
			);
			$wpdb->update(
				$queue,
				array(
					'status'      => 'done',
					'attempts'    => $attempts,
					'finished_at' => $now,
				),
				array( 'id' => (int) $job['id'] )
			);
			// Promote master post when at least 1 target published.
			BizCity_Content_Post_Repo::update(
				(int) $post['id'],
				array( 'status' => 'published', 'published_at' => $now )
			);
			return array( 'ok' => true );
		}

		$failed_terminal = $attempts >= self::MAX_ATTEMPTS;
		$next_run        = gmdate( 'Y-m-d H:i:s', time() + min( 3600, 60 * pow( 2, $attempts ) ) );

		BizCity_Content_Post_Repo::update_target(
			(int) $target['id'],
			array(
				'publish_status' => $failed_terminal ? 'failed' : 'pending',
				'error'          => (string) $result['error'],
			)
		);
		$wpdb->update(
			$queue,
			array(
				'status'      => $failed_terminal ? 'failed' : 'pending',
				'attempts'    => $attempts,
				'last_error'  => (string) $result['error'],
				'run_at'      => $failed_terminal ? $job['run_at'] : $next_run,
				'lock_token'  => '',
				'lock_expires_at' => null,
				'finished_at' => $failed_terminal ? $now : null,
			),
			array( 'id' => (int) $job['id'] )
		);
		return array( 'ok' => false, 'error' => (string) $result['error'] );
	}

	public static function status(): array {
		global $wpdb;
		$q   = self::queue_table();
		$now = current_time( 'mysql' );

		$due       = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $q WHERE status='pending' AND run_at<=%s", $now ) );
		$in_flight = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $q WHERE lock_expires_at IS NOT NULL AND lock_expires_at>=%s", $now ) );
		$failed_24 = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $q WHERE status='failed' AND finished_at>=%s", gmdate( 'Y-m-d H:i:s', time() - 86400 ) ) );
		$hb        = (int) get_option( self::HEARTBEAT_OPTION, 0 );

		return array(
			'heartbeat_at'   => $hb,
			'heartbeat_age'  => $hb ? ( time() - $hb ) : null,
			'due_count'      => $due,
			'in_flight'      => $in_flight,
			'failed_24h'     => $failed_24,
			'next_cron_run'  => (int) wp_next_scheduled( self::CRON_HOOK ),
		);
	}
}

/**
 * WP-CLI integration. Define only when WP_CLI active.
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) {

	class BizCity_Content_Scheduler_CLI {

		/**
		 * Run scheduler tick. Idempotent.
		 *
		 * ## EXAMPLES
		 *     wp bizcity-content scheduler run
		 */
		public function run() {
			$res = BizCity_Content_Scheduler::run();
			\WP_CLI::success( sprintf( 'Processed %d (ok=%d, failed=%d)', $res['processed'], $res['ok'], $res['failed'] ) );
		}

		/**
		 * Print scheduler status.
		 */
		public function status() {
			$st = BizCity_Content_Scheduler::status();
			\WP_CLI::log( wp_json_encode( $st, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		}
	}
}
