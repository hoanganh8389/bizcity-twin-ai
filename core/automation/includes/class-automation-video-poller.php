<?php
/**
 * BizCity_Automation_Video_Poller — async poll + deliver for video jobs.
 *
 * Two operating modes:
 *
 * MODE 1 — Cron (Bật / Auto):
 *   Hook `bizcity_automation_video_poll` fires via wp_schedule_single_event.
 *   `on_poll_tick( $task_id )`:
 *     a. Load job meta from BizCity_Automation_Action_Video_Submit::get_poll_job().
 *     b. Call BizCity_Video_Client::get_status( $task_id ).
 *     c. If completed → sideload video to WP Media → send Zalo reply with URL.
 *     d. If pending/processing + attempt < max_attempts → reschedule +60s.
 *     e. If failed / max reached → send error reply, remove job.
 *     R-CRON-META: note_event() for every outcome.
 *
 * MODE 2 — Admin AJAX (Dev / Chạy thử):
 *   Handler `bizcity_video_poll_ajax` — polls once, returns JSON status.
 *   FE "Chạy thử" panel calls this endpoint to display live progress.
 *   Does NOT reschedule cron (one-shot per click).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Automation
 * @since      2026-06-14 (PHASE-0.41 VIDEO-VEO3)
 */

// [2026-06-14 Johnny Chu] PHASE-0.41 VIDEO-VEO3 — BizCity_Automation_Video_Poller

defined( 'ABSPATH' ) || exit;

final class BizCity_Automation_Video_Poller {

	public static function init(): void {
		// MODE 1 — cron hook
		add_action( 'bizcity_automation_video_poll', array( __CLASS__, 'on_poll_tick' ), 10, 1 );

		// MODE 2 — AJAX for dev "Chạy thử"
		add_action( 'wp_ajax_bizcity_video_poll_ajax', array( __CLASS__, 'on_ajax_poll' ) );
	}

	/* ─────────────────────────────────────────────────────────────
	 *  MODE 1 — Cron poller
	 * ───────────────────────────────────────────────────────────── */

	/**
	 * Called by WP cron hook `bizcity_automation_video_poll`.
	 *
	 * @param string $task_id
	 */
	public static function on_poll_tick( $task_id ): void {
		$task_id = (string) $task_id;
		if ( $task_id === '' ) { return; }

		// R-CRON-META note tick
		if ( class_exists( 'BizCity_Cron_Manager' ) ) {
			BizCity_Cron_Manager::instance()->note( array(
				'counters' => array( 'video_poll_ticks' => 1 ),
			) );
		}

		// Load job meta
		$job = null;
		if ( class_exists( 'BizCity_Automation_Action_Video_Submit' ) ) {
			$job = BizCity_Automation_Action_Video_Submit::get_poll_job( $task_id );
		}
		if ( ! is_array( $job ) ) {
			// Job may have been cleaned up already — silent exit.
			return;
		}

		$chat_id     = (string) ( $job['chat_id']     ?? '' );
		$platform    = (string) ( $job['platform']    ?? 'ZALO_BOT' );
		$attempt     = (int)    ( $job['attempt']     ?? 0 );
		$max_att     = (int)    ( $job['max_attempts'] ?? 30 );
		$reply_fail  = (string) ( $job['reply_on_fail'] ?? '❌ Tạo video thất bại. Sếp thử lại sau nhé.' );
		$kling_job_id = (int)   ( $job['kling_job_id'] ?? 0 ); // [2026-06-14 Johnny Chu] PHASE-0.41 VIDEO-VEO3 — DB row for monitor sync

		// Increment attempt counter
		$attempt++;
		BizCity_Automation_Action_Video_Submit::update_poll_job( $task_id, array( 'attempt' => $attempt ) );

		// Poll status
		if ( ! class_exists( 'BizCity_Video_Client' ) ) { return; }
		$vc     = BizCity_Video_Client::instance();
		$status = $vc->get_status( $task_id );

		if ( ! empty( $status['_degraded'] ) ) {
			// [2026-06-14 Johnny Chu] PHASE-0.41 VIDEO-VEO3 — track consecutive_degraded, notify at 3
			$consecutive = (int) ( $job['consecutive_degraded'] ?? 0 ) + 1;
			BizCity_Automation_Action_Video_Submit::update_poll_job( $task_id, array( 'consecutive_degraded' => $consecutive ) );
			if ( $consecutive === 3 && $chat_id !== '' ) {
				do_action( 'bizcity_channel_send', array(
					'platform' => $platform,
					'chat_id'  => $chat_id,
					'text'     => '⚠️ Kết nối đến máy chủ video đang bị gián đoạn. Em vẫn đang thử lại mỗi phút — sẽ báo sếp ngay khi có kết quả.',
				) );
			}
			self::maybe_reschedule( $task_id, $attempt, $max_att );
			return;
		}

		$st = strtolower( (string) ( $status['status'] ?? 'pending' ) );

		// ── Completed ───────────────────────────────────────────────────────
		if ( in_array( $st, array( 'completed', 'succeeded', 'success', 'done' ), true ) ) {
			$result_url = (string) ( $status['result_url'] ?? '' );
			if ( $result_url !== '' ) {
				// Sideload to WP Media Library
				$local_url = self::sideload_video( $result_url, $task_id );
				$send_url  = $local_url !== '' ? $local_url : $result_url;

				if ( $chat_id !== '' ) {
					// [2026-06-14 Johnny Chu] PHASE-0.41 VIDEO-VEO3 — include model + duration in done reply
					$model_label = self::short_model_label( (string) ( $job['model'] ?? '' ) );
					$model_note  = $model_label !== '' ? " [{$model_label}]" : '';
					$reply_text  = "🎬 Video đã xong{$model_note}! Đây là link của sếp:\n" . $send_url;
					do_action( 'bizcity_channel_send', array(
						'platform' => $platform,
						'chat_id'  => $chat_id,
						'text'     => $reply_text,
					) );
				}
			}
			// [2026-06-14 Johnny Chu] PHASE-0.41 VIDEO-VEO3 — sync kling DB row to completed
			if ( $kling_job_id > 0 && class_exists( 'BizCity_Video_Kling_Database' ) ) {
				$send_url_for_db = isset( $send_url ) ? $send_url : (string) ( $status['result_url'] ?? '' );
				BizCity_Video_Kling_Database::update_job( $kling_job_id, array(
					'status'    => 'completed',
					'progress'  => 100,
					'video_url' => (string) ( $status['result_url'] ?? '' ),
					'media_url' => $send_url_for_db,
				) );
				if ( class_exists( 'BizCity_Video_Kling_Job_Monitor' ) ) {
					BizCity_Video_Kling_Job_Monitor::add_log( $kling_job_id, 'Video completed after ' . $attempt . ' poll(s). URL: ' . $send_url_for_db, 'success' );
				}
			}
			// Cleanup
			BizCity_Automation_Action_Video_Submit::remove_poll_job( $task_id );
			if ( $chat_id !== '' && class_exists( 'BizCity_Automation_Pending_State' ) ) {
				// Clear video slot from pending state
				$state = BizCity_Automation_Pending_State::get( $chat_id );
				if ( isset( $state['slots']['video_task_id'] ) && $state['slots']['video_task_id'] === $task_id ) {
					unset( $state['slots']['video_task_id'], $state['slots']['video_model'], $state['slots']['video_prompt'] );
					BizCity_Automation_Pending_State::set( $chat_id, $state );
				}
			}
			self::cron_note( 'video_poll_completed', array(
				'task_id'    => $task_id,
				'attempt'    => $attempt,
				'result_url' => (string) ( $status['result_url'] ?? '' ),
			) );
			return;
		}

		// ── Failed ──────────────────────────────────────────────────────────
		if ( in_array( $st, array( 'failed', 'error' ), true ) ) {
			// [2026-06-14 Johnny Chu] PHASE-0.41 VIDEO-VEO3 — specific fail message from status.error
			$fail_msg = self::build_status_fail_message( $status, $reply_fail );
			if ( $chat_id !== '' ) {
				do_action( 'bizcity_channel_send', array(
					'platform' => $platform,
					'chat_id'  => $chat_id,
					'text'     => $fail_msg,
				) );
			}			// [2026-06-14 Johnny Chu] PHASE-0.41 VIDEO-VEO3 — sync kling DB row to failed
			if ( $kling_job_id > 0 && class_exists( 'BizCity_Video_Kling_Database' ) ) {
				$err = (string) ( $status['error'] ?? 'provider_failed' );
				BizCity_Video_Kling_Database::update_job( $kling_job_id, array(
					'status'        => 'failed',
					'error_message' => $err,
				) );
				if ( class_exists( 'BizCity_Video_Kling_Job_Monitor' ) ) {
					BizCity_Video_Kling_Job_Monitor::add_log( $kling_job_id, 'Job failed after ' . $attempt . ' poll(s): ' . $err, 'error' );
				}
			}			BizCity_Automation_Action_Video_Submit::remove_poll_job( $task_id );
			self::cron_note( 'video_poll_failed', array(
				'task_id' => $task_id,
				'reason'  => 'provider_failed',
				'attempt' => $attempt,
				'error'   => (string) ( $status['error'] ?? '' ),
			) );
			return;
		}

		// ── Still pending / processing ───────────────────────────────────────
		if ( $attempt >= $max_att ) {
			if ( $chat_id !== '' ) {
				do_action( 'bizcity_channel_send', array(
					'platform' => $platform,
					'chat_id'  => $chat_id,
					'text'     => '⚠️ Tạo video quá thời gian chờ (30 phút). Sếp vui lòng thử lại sau.',
				) );
			}
			// [2026-06-14 Johnny Chu] PHASE-0.41 VIDEO-VEO3 — sync DB row to failed+timeout
			if ( $kling_job_id > 0 && class_exists( 'BizCity_Video_Kling_Database' ) ) {
				BizCity_Video_Kling_Database::update_job( $kling_job_id, array(
					'status'        => 'failed',
					'error_message' => 'Timeout after ' . $attempt . ' poll attempts (30 min).',
				) );
				if ( class_exists( 'BizCity_Video_Kling_Job_Monitor' ) ) {
					BizCity_Video_Kling_Job_Monitor::add_log( $kling_job_id, 'Timeout after ' . $attempt . ' poll attempts.', 'warning' );
				}
			}
			BizCity_Automation_Action_Video_Submit::remove_poll_job( $task_id );
			self::cron_note( 'video_poll_timeout', array(
				'task_id' => $task_id,
				'reason'  => 'timeout',
				'attempt' => $attempt,
			) );
			return;
		}

		// [2026-06-14 Johnny Chu] PHASE-0.41 VIDEO-VEO3 — milestone progress notifications
		// Send status update at attempt 5 (5 min), 10, 15, 20 to keep user informed
		if ( $chat_id !== '' && in_array( $attempt, array( 5, 10, 15, 20 ), true ) ) {
			$progress_text = self::build_progress_message(
				$attempt,
				$max_att,
				(int) ( $status['progress'] ?? 0 ),
				$job
			);
			if ( $progress_text !== '' ) {
				do_action( 'bizcity_channel_send', array(
					'platform' => $platform,
					'chat_id'  => $chat_id,
					'text'     => $progress_text,
				) );
			}
		}

		// [2026-06-14 Johnny Chu] PHASE-0.41 VIDEO-VEO3 — sync DB row progress
		if ( $kling_job_id > 0 && class_exists( 'BizCity_Video_Kling_Database' ) ) {
			$progress_pct = (int) ( $status['progress'] ?? max( 5, min( 95, $attempt * 3 ) ) );
			BizCity_Video_Kling_Database::update_job( $kling_job_id, array(
				'status'   => 'processing',
				'progress' => $progress_pct,
			) );
		}

		// Reschedule
		self::maybe_reschedule( $task_id, $attempt, $max_att );
		self::cron_note( 'video_poll_pending', array(
			'task_id'  => $task_id,
			'attempt'  => $attempt,
			'progress' => (int) ( $status['progress'] ?? 0 ),
		) );
	}

	/* ─────────────────────────────────────────────────────────────
	 *  MODE 2 — AJAX dev poll (single-shot)
	 * ───────────────────────────────────────────────────────────── */

	public static function on_ajax_poll(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Không có quyền.' ), 403 );
		}
		$task_id = sanitize_text_field( (string) ( $_POST['task_id'] ?? '' ) );
		if ( $task_id === '' ) {
			wp_send_json_error( array( 'message' => 'task_id rỗng.' ), 400 );
		}
		if ( ! class_exists( 'BizCity_Video_Client' ) ) {
			wp_send_json_error( array( 'message' => 'BizCity_Video_Client chưa load.', '_degraded' => true ), 503 );
		}
		$status = BizCity_Video_Client::instance()->get_status( $task_id );
		$job    = class_exists( 'BizCity_Automation_Action_Video_Submit' )
			? BizCity_Automation_Action_Video_Submit::get_poll_job( $task_id )
			: null;
		wp_send_json_success( array(
			'task_id' => $task_id,
			'status'  => $status,
			'job'     => $job,
		) );
	}

	/* ─────────────────────────────────────────────────────────────
	 *  Internal helpers
	 * ───────────────────────────────────────────────────────────── */

	private static function maybe_reschedule( string $task_id, int $attempt, int $max ): void {
		if ( $attempt >= $max ) { return; }
		if ( ! wp_next_scheduled( 'bizcity_automation_video_poll', array( $task_id ) ) ) {
			wp_schedule_single_event( time() + 60, 'bizcity_automation_video_poll', array( $task_id ) );
		}
	}

	/**
	 * Download video URL and sideload into WP Media Library.
	 * Returns local attachment URL on success, empty string on failure.
	 */
	private static function sideload_video( string $url, string $task_id ): string {
		if ( $url === '' ) { return ''; }
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		$tmp = download_url( $url, 300 );
		if ( is_wp_error( $tmp ) ) {
			error_log( '[bizcity-video-poller] download_url failed: ' . $tmp->get_error_message() );
			return '';
		}
		$safe_id  = preg_replace( '/[^a-zA-Z0-9\-]/', '-', $task_id );
		$filename = 'bizvideo-' . $safe_id . '.mp4';
		$file     = array(
			'name'     => $filename,
			'tmp_name' => $tmp,
		);
		$att_id = media_handle_sideload( $file, 0 );
		if ( file_exists( $tmp ) ) {
			@unlink( $tmp );
		}
		if ( is_wp_error( $att_id ) ) {
			error_log( '[bizcity-video-poller] media_handle_sideload failed: ' . $att_id->get_error_message() );
			return '';
		}
		$local_url = wp_get_attachment_url( $att_id );
		return $local_url ? (string) $local_url : '';
	}

	private static function cron_note( string $event, array $data ): void {
		if ( ! class_exists( 'BizCity_Cron_Manager' ) ) { return; }
		try {
			BizCity_Cron_Manager::instance()->note_event( $event, $data );
		} catch ( \Throwable $e ) {
			// Never let evidence write break the poller.
		}
	}

	/* ─────────────────────────────────────────────────────────────
	 *  Message builders
	 * ───────────────────────────────────────────────────────────── */

	/**
	 * Build milestone progress message sent every 5 attempts (~5 min).
	 *
	 * [2026-06-14 Johnny Chu] PHASE-0.41 VIDEO-VEO3
	 *
	 * @param int   $attempt  Current attempt number (1-based).
	 * @param int   $max_att  Max attempts (usually 30).
	 * @param int   $progress Percent progress from gateway (0-100).
	 * @param array $job      Full job meta.
	 */
	private static function build_progress_message( int $attempt, int $max_att, int $progress, array $job ): string {
		$model_label = self::short_model_label( (string) ( $job['model'] ?? '' ) );
		$remaining   = max( 0, $max_att - $attempt );
		$pct         = $progress > 0 ? " ({$progress}%" . ')' : '';

		if ( $attempt <= 5 ) {
			return "⏳ Video {$model_label} đang render{$pct}… Đã {$attempt} phút, còn khoảng {$remaining} phút. Sếp cứ thả lảnh — em báo ngay khi xong! 🎬";
		}
		if ( $attempt <= 10 ) {
			return "⏳ Vẫn đang xử lý{$pct}… ({$attempt} phút). Model Kling đôi khi cần thêm thời gian, sắp xong rồi sếp ơi!";
		}
		if ( $attempt <= 15 ) {
			return "{$model_label} đang render{$pct}… ({$attempt} phút đã qua). Tối đa còn {$remaining} phút nữa là xong.";
		}
		return "⏳ Video vẫn đang tạo{$pct}… ({$attempt} phút). Tối đa {$remaining} phút nữa — sếp chờ thêm chút nhé!";
	}

	/**
	 * Build user-facing fail message from status.error (provider-side failure).
	 *
	 * [2026-06-14 Johnny Chu] PHASE-0.41 VIDEO-VEO3
	 *
	 * @param array  $status   Return value from BizCity_Video_Client::get_status().
	 * @param string $fallback Job's stored reply_on_fail text.
	 */
	private static function build_status_fail_message( array $status, string $fallback ): string {
		$error = trim( (string) ( $status['error'] ?? '' ) );

		if ( $error === '' ) {
			return $fallback !== '' ? $fallback : '❌ Video thất bại. Sếp thử lại sau nhé.';
		}

		// Content / safety policy rejection
		if (
			strpos( $error, 'content' ) !== false ||
			strpos( $error, 'safety' ) !== false ||
			strpos( $error, 'policy' ) !== false
		) {
			return '❌ Video bị từ chối do nội dung không phù hợp chính sách AI. Sếp điều chỉnh prompt và thử lại nhé.';
		}

		// Quota / credits exhausted on provider side
		if ( strpos( $error, 'quota' ) !== false || strpos( $error, 'credit' ) !== false ) {
			return '❌ Video thất bại do hết quota PiAPI. Sếp liên hệ admin để nạp thêm.';
		}

		// Timeout on provider side
		if ( strpos( $error, 'timeout' ) !== false ) {
			return '❌ Video thất bại do timeout bên provider. Sếp thử lại với prompt ngắn hơn nhé.';
		}

		// Invalid input (bad image URL, bad prompt, etc.)
		if ( strpos( $error, 'invalid' ) !== false || strpos( $error, 'not supported' ) !== false ) {
			return '❌ Video thất bại do dữ liệu đầu vào không hợp lệ: ' . $error . '. Sếp kiểm tra ảnh và prompt rồi thử lại.';
		}

		// Generic with actual error text
		return '❌ Video thất bại: ' . $error . '. Sếp thử lại hoặc đổi model khác nhé.';
	}

	/**
	 * Shorten PiAPI slash-format model ID to a human-readable label.
	 *
	 * [2026-06-14 Johnny Chu] PHASE-0.41 VIDEO-VEO3
	 */
	private static function short_model_label( string $model ): string {
		$map = array(
			'kling/v1-5/i2v-pro'      => 'Kling i2v Pro',
			'kling/v1-5/i2v-standard' => 'Kling i2v',
			'kling/v1-5/pro'          => 'Kling v1.5',
			'kling/v1-5/standard'     => 'Kling',
			'runway/gen3-turbo'       => 'Runway Gen3',
			'luma/dream-machine'      => 'Luma DM',
			'minimax/video-01'        => 'Minimax',
			'wan/v2.1'                => 'Wan v2.1',
		);
		return isset( $map[ $model ] ) ? $map[ $model ] : $model;
	}
}
