<?php
/**
 * Action: video_submit — submit video generation job to BizCity gateway (Veo 3 / Kling).
 *
 * Flow:
 *   1. Resolve prompt, image_url, model từ ctx tokens.
 *   2. Call BizCity_Video_Client::submit() → task_id.
 *   3. Lưu {task_id, chat_id, platform} vào pending_state (slot video_task_id).
 *   4. Schedule cron `bizcity_automation_video_poll` +60s để poller xử lý async.
 *   5. Gửi reply Zalo "⏳ Đang tạo video..." ngay lập tức.
 *   6. Return {task_id, status, eta_sec} cho node downstream.
 *
 * Dev / "Chạy thử" mode (ctx['_dev_mode'] = true):
 *   Không schedule cron — return task_id + status='submitted' để FE test panel
 *   có thể dùng AJAX endpoint `bizcity_video_poll_ajax` để poll thủ công.
 *
 * Cron delay pattern:
 *   - Mode Bật (enabled): cron auto-poll mỗi 60s, tối đa 30 lần (30 phút).
 *   - Mode Dev / Chạy thử: poller AJAX 1 lần, FE tự poll.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Automation\Blocks\Actions
 * @since      2026-06-14 (PHASE-0.41 VIDEO-VEO3)
 */

// [2026-06-14 Johnny Chu] PHASE-0.41 VIDEO-VEO3 — action.video_submit block

defined( 'ABSPATH' ) || exit;

final class BizCity_Automation_Action_Video_Submit extends BizCity_Automation_Block_Base {

	public function id(): string   { return 'action.video_submit'; }
	public function kind(): string { return 'action'; }

	public function meta(): array {
		return array(
			'label'    => 'Tạo video (Kling i2v / PiAPI)', // [2026-06-14 Johnny Chu] PHASE-0.41 VIDEO-VEO3 — corrected label (no veo-3 on router)
			'short'    => 'video_submit',
			'category' => 'ai',
			'color'    => '#7c3aed',
			'icon'     => 'video',
			'defaults' => array(
				'label'          => 'video_submit',
				'prompt'         => '{{trigger.text}}',
				'image_url'      => '{{trigger._resume.attachment_url}}',
				'model'          => 'kling/v1-5/i2v-pro', // PiAPI image-to-video Pro
				'duration'       => 5,
				'aspect_ratio'   => '16:9',
				'reply_template' => '⏳ Em đang tạo video Kling cho sếp! ETA ~{{video_submit.eta_min}} phút. Em báo ngay khi xong 🎬',
				'reply_on_fail'  => '❌ Rất tiếc, tạo video thất bại: {{video_submit.error}}. Sếp thử lại sau nhé.',
			),
			'fields' => array(
				array( 'name' => 'label',          'label' => 'Tên hiển thị',         'type' => 'text' ),
				array( 'name' => 'prompt',         'label' => 'Prompt mô tả video',    'type' => 'textarea',
					'hint' => 'Hỗ trợ {{trigger.text}}, {{llm.output}}, v.v.' ),
				array( 'name' => 'image_url',      'label' => 'URL ảnh nguồn (image-to-video)', 'type' => 'text',
					'hint' => 'Để trống = text-to-video. {{trigger._resume.attachment_url}} lấy từ slot.' ),
				array( 'name' => 'model',          'label' => 'Model',                 'type' => 'select',
					// [2026-06-14 Johnny Chu] PHASE-0.41 VIDEO-VEO3 — PiAPI slash-format model IDs
					'options' => array(
						'kling/v1-5/i2v-pro',      // Kling 1.5 image-to-video Pro
						'kling/v1-5/i2v-standard', // Kling 1.5 image-to-video Standard
						'kling/v1-5/pro',          // Kling 1.5 text-to-video Pro
						'kling/v1-5/standard',     // Kling 1.5 text-to-video Standard
						'runway/gen3-turbo',       // Runway Gen3 Turbo
						'luma/dream-machine',      // Luma Dream Machine
						'minimax/video-01',        // Minimax Video-01
						'wan/v2.1',                // Wan v2.1
					) ),
				array( 'name' => 'duration',       'label' => 'Độ dài (giây)',         'type' => 'number',
					'hint' => '5 hoặc 10 giây.' ),
				array( 'name' => 'aspect_ratio',   'label' => 'Tỷ lệ khung hình',     'type' => 'select',
					'options' => array( '16:9', '9:16', '1:1' ) ),
				array( 'name' => 'reply_template', 'label' => 'Tin nhắn xác nhận',     'type' => 'textarea' ),
				array( 'name' => 'reply_on_fail',  'label' => 'Tin nhắn lỗi',         'type' => 'textarea' ),
			),
		);
	}

	public function execute( array $ctx, array $data ) {
		// ── 1. Resolve inputs ───────────────────────────────────────────────
		$prompt      = trim( (string) $this->resolve( $data['prompt']       ?? '', $ctx ) );
		$image_url   = trim( (string) $this->resolve( $data['image_url']    ?? '', $ctx ) );
		$model       = trim( (string) ( $data['model']        ?? 'kling/v1-5/i2v-pro' ) ); // [2026-06-14 Johnny Chu] PHASE-0.41 VIDEO-VEO3 — PiAPI model ID
		$duration    = max( 1, (int) ( $data['duration']     ?? 5 ) );
		$ratio       = (string) ( $data['aspect_ratio']   ?? '16:9' );

		if ( $prompt === '' ) {
			return new WP_Error( 'no_prompt', 'video_submit: prompt rỗng sau khi resolve.' );
		}

		// ── 2. Chat_id + platform (needed for poller to send reply later) ──
		$trigger  = is_array( $ctx['trigger'] ?? null ) ? $ctx['trigger'] : array();
		$chat_id  = (string) ( $trigger['chat_id']    ?? $ctx['chat_id']    ?? '' );
		$platform = (string) ( $trigger['platform']   ?? $trigger['channel'] ?? 'ZALO_BOT' );

		// ── 3. Call BizCity_Video_Client ────────────────────────────────────
		if ( ! class_exists( 'BizCity_Video_Client' ) ) {
			return array(
				'success'    => false,
				'task_id'    => '',
				'status'     => 'error',
				'error'      => 'BizCity_Video_Client chưa load.',
				'_degraded'  => true,
			);
		}

		$vc    = BizCity_Video_Client::instance();
		$opts  = array(
			'model'        => $model,
			'duration'     => $duration,
			'aspect_ratio' => $ratio,
		);
		if ( $image_url !== '' ) {
			$opts['image_url'] = $image_url;
		}

		$result = $vc->submit( $prompt, $opts );

		if ( empty( $result['success'] ) ) {
			// [2026-06-14 Johnny Chu] PHASE-0.41 VIDEO-VEO3 — specific fail messages per error_code
			$fail_text = self::build_fail_message( $result );
			if ( $fail_text !== '' && $chat_id !== '' ) {
				do_action( 'bizcity_channel_send', array(
					'platform' => $platform,
					'chat_id'  => $chat_id,
					'text'     => $fail_text,
				) );
			}
			$this->note_event( 'video_submit_failed', array(
				'reason' => 'gateway_error',
				'error'  => $result['error'] ?? '',
				'model'  => $model,
			) );
			return array(
				'success' => false,
				'task_id' => '',
				'status'  => 'error',
				'error'   => $result['error'] ?? 'Video submission failed.',
			);
		}

		$task_id = (string) ( $result['task_id'] ?? '' );
		if ( $task_id === '' ) {
			return new WP_Error( 'no_task_id', 'video_submit: gateway trả success nhưng task_id rỗng.' );
		}

		// ── 4. Lưu task metadata vào pending_state (slot key: video_task_id) ──
		if ( $chat_id !== '' && class_exists( 'BizCity_Automation_Pending_State' ) ) {
			BizCity_Automation_Pending_State::patch( $chat_id, array(
				'slots' => array(
					'video_task_id' => $task_id,
					'video_model'   => $model,
					'video_prompt'  => $prompt,
				),
			), 3600 ); // 60 min TTL — long enough for Veo 3
		}

		// ── 5. Lưu poll-job vào option để poller có thể resume ──────────────
		// [2026-06-14 Johnny Chu] PHASE-0.41 VIDEO-VEO3 — also write to bizcity_kling_jobs for monitor
		$kling_job_id = 0;
		if ( class_exists( 'BizCity_Video_Kling_Database' ) ) {
			$user_id_ctx  = (int) ( $ctx['user_id'] ?? $trigger['user_id'] ?? get_current_user_id() );
			$kling_job_id = (int) BizCity_Video_Kling_Database::create_job( array(
				'job_key'      => 'auto_' . $task_id,
				'task_id'      => $task_id,
				'prompt'       => $prompt,
				'image_url'    => $image_url !== '' ? $image_url : null,
				'duration'     => $duration,
				'aspect_ratio' => $ratio,
				'model'        => $model,
				'status'       => 'queued',
				'progress'     => 5,
				'metadata'     => wp_json_encode( array(
					'source'    => 'automation_block',
					'chat_id'   => $chat_id,
					'platform'  => $platform,
					'task_id'   => $task_id,
					'submitted' => current_time( 'mysql' ),
				), JSON_UNESCAPED_UNICODE ),
				'created_by'   => $user_id_ctx,
			) );
			if ( $kling_job_id && class_exists( 'BizCity_Video_Kling_Job_Monitor' ) ) {
				BizCity_Video_Kling_Job_Monitor::add_log( $kling_job_id, "Job submitted via Automation block. task_id={$task_id} model={$model}", 'info' );
			}
		}

		self::enqueue_poll_job( array(
			'task_id'          => $task_id,
			'chat_id'          => $chat_id,
			'platform'         => $platform,
			'model'            => $model,
			'prompt'           => $prompt,
			'image_url'        => $image_url,
			'reply_on_fail'    => (string) ( $data['reply_on_fail'] ?? '' ),
			'attempt'          => 0,
			'max_attempts'     => BizCity_Video_Client::POLL_MAX_ATTEMPTS,
			'created_at'       => time(),
			'kling_job_id'     => $kling_job_id, // [2026-06-14 Johnny Chu] PHASE-0.41 VIDEO-VEO3 — link to monitor DB row
		) );

		// ── 6. Schedule cron poll (+60s) unless dev_mode ────────────────────
		$dev_mode = ! empty( $ctx['_dev_mode'] );
		if ( ! $dev_mode ) {
			wp_schedule_single_event(
				time() + 60,
				'bizcity_automation_video_poll',
				array( $task_id )
			);
		}

		// ── 7. Send immediate ack reply (with ETA + monitor URL) ─────────────────────────────
		// [2026-06-14 Johnny Chu] PHASE-0.41 VIDEO-VEO3 — expose eta_min + model + monitor_url in ack ctx
		$eta_min     = max( 1, (int) round( ( $result['eta_sec'] ?? 120 ) / 60 ) );
		$monitor_url = $kling_job_id > 0
			? add_query_arg( array( 'page' => 'bizcity-kling-monitor', 'job_id' => $kling_job_id ), admin_url( 'admin.php' ) )
			: add_query_arg( array( 'page' => 'bizcity-kling-monitor' ), admin_url( 'admin.php' ) );
		$ack_ctx  = array_merge( $ctx, array(
			'video_submit' => array(
				'task_id'     => $task_id,
				'model'       => $model,
				'eta_min'     => $eta_min,
				'monitor_url' => $monitor_url,
				'kling_job_id' => $kling_job_id,
			),
		) );
		$ack_text = (string) $this->resolve( $data['reply_template'] ?? '', $ack_ctx );
		if ( $ack_text !== '' && $chat_id !== '' ) {
			do_action( 'bizcity_channel_send', array(
				'platform' => $platform,
				'chat_id'  => $chat_id,
				'text'     => $ack_text,
			) );
		}

		// [2026-06-14 Johnny Chu] PHASE-0.41 VIDEO-VEO3 — send monitor link as separate message for easy access
		if ( $kling_job_id > 0 && $chat_id !== '' ) {
			do_action( 'bizcity_channel_send', array(
				'platform' => $platform,
				'chat_id'  => $chat_id,
				'text'     => '📊 Theo dõi job tại: ' . $monitor_url,
			) );
		}

		$this->note_event( 'video_submit_ok', array(
			'task_id'      => $task_id,
			'model'        => $model,
			'eta_sec'      => $result['eta_sec'] ?? 120,
			'kling_job_id' => $kling_job_id,
		) );

		return array(
			'success'      => true,
			'task_id'      => $task_id,
			'status'       => $result['status'] ?? 'pending',
			'eta_sec'      => (int) ( $result['eta_sec'] ?? 120 ),
			'model'        => $model,
			'error'        => '',
			'kling_job_id' => $kling_job_id,  // [2026-06-14 Johnny Chu] PHASE-0.41 VIDEO-VEO3 — DB row in bizcity_kling_jobs
			'monitor_url'  => isset( $monitor_url ) ? $monitor_url : '',
		);
	}

	/* ── Fail message builder ─────────────────────────────────────────── */

	/**
	 * Build a user-facing Zalo fail message based on gateway error_code.
	 *
	 * [2026-06-14 Johnny Chu] PHASE-0.41 VIDEO-VEO3 — specific messages per error code
	 *
	 * @param array $result Return value from BizCity_Video_Client::submit().
	 * @return string UTF-8 text ready to send via bizcity_channel_send.
	 */
	private static function build_fail_message( array $result ): string {
		$error_code = (string) ( $result['error_code'] ?? '' );
		$error_text = (string) ( $result['error']      ?? '' );

		// Gateway unreachable (connection error, API key missing, etc.)
		if ( ! empty( $result['_degraded'] ) ) {
			return '⚠️ Dịch vụ tạo video đang tạm thời không khả dụng. Sếp thử lại sau ít phút nhé.';
		}

		// API key not configured on this site
		if ( $error_code === 'api_key_missing' || $error_code === 'api_key_invalid' ) {
			return '⚠️ Chưa cấu hình API key tạo video. Admin vui lòng vào Cài đặt → BizCity AI để cấu hình.';
		}

		// Not enough credits (HTTP 402)
		if ( $error_code === 'insufficient_credits' || $error_code === 'quota_exceeded' ) {
			return '❌ Tài khoản không đủ credit để tạo video. Vui lòng nạp thêm tại bizcity.vn/tai-khoan 💳';
		}

		// Rate limited / monthly video quota (HTTP 429)
		if ( $error_code === 'rate_limited' || $error_code === 'monthly_limit_exceeded' || $error_code === 'daily_limit_exceeded' ) {
			return '❌ Đã đạt giới hạn số video hôm nay. Sếp thử lại ngày mai hoặc nâng cấp gói tại bizcity.vn 🔄';
		}

		// Provider / PiAPI-side content policy rejection
		if ( $error_code === 'content_policy' || $error_code === 'safety_filter' ) {
			return '❌ Video bị từ chối do nội dung không phù hợp chính sách AI. Sếp điều chỉnh prompt và thử lại nhé.';
		}

		// Generic: use error text if available
		if ( $error_text !== '' ) {
			return '❌ Gửi yêu cầu tạo video thất bại: ' . $error_text . '. Sếp thử lại sau nhé.';
		}

		return '❌ Gửi yêu cầu tạo video thất bại. Sếp thử lại sau nhé.';
	}

	/* ── Static helpers for poll-job queue (WP options-based ring) ── */

	const JOBS_OPTION = 'bizcity_auto_video_poll_jobs';

	/**
	 * Enqueue a poll-job. Jobs stored as array keyed by task_id in WP option.
	 */
	public static function enqueue_poll_job( array $job ): void {
		$jobs = (array) get_option( self::JOBS_OPTION, array() );
		$jobs[ $job['task_id'] ] = $job;
		update_option( self::JOBS_OPTION, $jobs, false );
	}

	/**
	 * Get a poll-job by task_id, or null.
	 */
	public static function get_poll_job( string $task_id ) {
		$jobs = (array) get_option( self::JOBS_OPTION, array() );
		return isset( $jobs[ $task_id ] ) ? $jobs[ $task_id ] : null;
	}

	/**
	 * Update poll-job fields by task_id.
	 */
	public static function update_poll_job( string $task_id, array $changes ): void {
		$jobs = (array) get_option( self::JOBS_OPTION, array() );
		if ( ! isset( $jobs[ $task_id ] ) ) { return; }
		$jobs[ $task_id ] = array_merge( $jobs[ $task_id ], $changes );
		update_option( self::JOBS_OPTION, $jobs, false );
	}

	/**
	 * Remove a completed/failed poll-job.
	 */
	public static function remove_poll_job( string $task_id ): void {
		$jobs = (array) get_option( self::JOBS_OPTION, array() );
		unset( $jobs[ $task_id ] );
		update_option( self::JOBS_OPTION, $jobs, false );
	}
}
