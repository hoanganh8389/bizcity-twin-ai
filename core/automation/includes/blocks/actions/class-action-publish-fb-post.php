<?php
/**
 * Action: Publish to Facebook Page (via core/scheduler + BizCity_FB_Publisher).
 *
 * R-CH compliant: KHÔNG gọi Graph API trực tiếp. Tạo CRM event với
 * `event_type='fb_post'` + metadata { fb_page_id, fb_content, fb_image_url } —
 * BizCity_FB_Publisher (đã hook vào `bizcity_scheduler_reminder_fire`) sẽ
 * publish + ghi `fb_post_id` / `fb_permalink` ngược lại metadata.
 *
 * Mode mặc định = `scheduled` (due_at = now + 5 phút) → staff giám sát có cửa
 * sổ huỷ trước khi reminder fire. Mode `now` set due_at = now.
 *
 * Output: { event_id, mode, due_at }.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Automation\Blocks\Actions
 * @since      AUTOMATION BE-7.C (2026-05-30)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Automation_Action_Publish_FB_Post extends BizCity_Automation_Block_Base {

	const MODE_OPTIONS = array( 'scheduled', 'now' );

	public function id(): string   { return 'action.publish_fb_post'; }
	public function kind(): string { return 'action'; }

	public function meta(): array {
		return array(
			'label'    => 'Đăng Facebook Page',
			'short'    => 'publish_fb_post',
			'category' => 'output',
			'color'    => '#1d4ed8',
			'icon'     => 'facebook',
			'defaults' => array(
				'label'          => 'publish_fb_post',
				'fb_target_mode' => 'single',
				'fb_page_id'     => '',
				'fb_page_name'   => '',
				'content'        => '{{llm.content}}',
				'image_url'      => '{{consume_attachment.attachment_url}}',
				'mode'           => 'scheduled',
				'delay_min'      => 5,
			),
			'fields' => array(
				array( 'name' => 'label',        'label' => 'Tên hiển thị',             'type' => 'text' ),
				// [2026-06-02 Johnny Chu] AUTOMATION UX FB-PICKER — picker thay 2 ô text.
				array( 'name' => 'fb_page_id',   'label' => 'Fanpage đăng bài',         'type' => 'fb_page_picker' ),
				array( 'name' => 'content',      'label' => 'Nội dung post',            'type' => 'textarea' ),
				array( 'name' => 'image_url',    'label' => 'Ảnh đính kèm (URL)',       'type' => 'text' ),
				array( 'name' => 'mode',         'label' => 'Chế độ',                   'type' => 'select', 'options' => self::MODE_OPTIONS ),
				array( 'name' => 'delay_min',    'label' => 'Trễ (phút) cho scheduled', 'type' => 'number' ),
			),
		);
	}

	public function execute( array $ctx, array $data ) {
		$content = (string) $this->resolve( $data['content']    ?? '', $ctx );
		$image   = trim( (string) $this->resolve( $data['image_url'] ?? '', $ctx ) );

		if ( $content === '' ) {
			return new WP_Error( 'no_content', 'publish_fb_post: nội dung rỗng.' );
		}

		$mode = (string) ( $data['mode'] ?? 'scheduled' );
		if ( ! in_array( $mode, self::MODE_OPTIONS, true ) ) { $mode = 'scheduled'; }
		$delay  = max( 0, (int) ( $data['delay_min'] ?? 5 ) );
		$due_ts = $mode === 'now' ? time() : ( time() + $delay * MINUTE_IN_SECONDS );
		$due_at = gmdate( 'Y-m-d H:i:s', $due_ts );

		// [2026-06-02 Johnny Chu] AUTOMATION UX FB-PICKER — resolve target list.
		// fb_target_mode='all' → fan-out 1 event mỗi active FB bot.
		// 'single' (default) → dùng fb_page_id đã chọn.
		$target_mode = ( (string) ( $data['fb_target_mode'] ?? 'single' ) ) === 'all' ? 'all' : 'single';
		$targets     = array();

		if ( $target_mode === 'all' ) {
			if ( class_exists( 'BizCity_Facebook_Bot_Database' ) ) {
				try {
					$bots = BizCity_Facebook_Bot_Database::instance()->get_active_bots();
					foreach ( (array) $bots as $b ) {
						$row = (array) $b;
						$pid = trim( (string) ( $row['page_id'] ?? '' ) );
						if ( $pid === '' ) { continue; }
						$targets[] = array(
							'page_id'   => $pid,
							'page_name' => (string) ( $row['bot_name'] ?? $pid ),
						);
					}
				} catch ( \Throwable $e ) { /* swallow */ }
			}
			if ( empty( $targets ) ) {
				return new WP_Error( 'no_pages', 'publish_fb_post: mode=all nhưng chưa có fanpage nào active.' );
			}
		} else {
			$page_id = trim( (string) $this->resolve( $data['fb_page_id'] ?? '', $ctx ) );
			if ( $page_id === '' ) {
				return new WP_Error( 'no_page', 'publish_fb_post: chưa chọn fanpage (fb_page_id rỗng).' );
			}
			$targets[] = array(
				'page_id'   => $page_id,
				'page_name' => (string) $this->resolve( $data['fb_page_name'] ?? '', $ctx ),
			);
		}

		$events = array();
		// [2026-06-02 Johnny Chu] AUTOMATION HARDEN — defensive guards + per-event
		// note_event để diagnose hang (trước đây block để CRM bridge fatal/timeout
		// → runner log step=RUN nhưng không bao giờ thấy OK/FAIL).
		if ( ! class_exists( 'BizCity_Automation_CRM_Bridge' ) ) {
			$this->note_event( 'publish_fb_post_bridge_missing_error', array(
				'reason'      => 'crm_bridge_missing',
				'workflow_id' => (int) ( $ctx['_workflow_id'] ?? 0 ),
				'run_id'      => (string) ( $ctx['_run_id'] ?? '' ),
			) );
			return new WP_Error( 'no_crm_bridge', 'publish_fb_post: BizCity_Automation_CRM_Bridge chưa load.' );
		}
		if ( ! class_exists( 'BizCity_Scheduler_Manager' ) ) {
			$this->note_event( 'publish_fb_post_scheduler_missing_error', array(
				'reason'      => 'scheduler_missing',
				'workflow_id' => (int) ( $ctx['_workflow_id'] ?? 0 ),
				'run_id'      => (string) ( $ctx['_run_id'] ?? '' ),
			) );
			return new WP_Error( 'no_scheduler', 'publish_fb_post: BizCity_Scheduler_Manager chưa load (core/scheduler chưa boot trên site này).' );
		}

		foreach ( $targets as $t ) {
			// [2026-06-03 Johnny Chu] R-SCH-REPLY — forward inbound{} qua helper
			// để Scheduler Completion Notifier reply về đúng kênh khi publish xong.
			$metadata = $this->build_event_metadata( $ctx, array(
				'fb_page_id'        => $t['page_id'],
				'fb_page_name'      => $t['page_name'],
				'fb_content'        => $content,
				'fb_image_url'      => $image,
				'fb_publish_status' => 'pending',
			) );

			$payload = array(
				'event_type'  => 'fb_post',
				'title'       => '[automation] FB post → ' . $t['page_id'],
				'description' => mb_substr( $content, 0, 240 ),
				'start_at'    => $due_at,
				'related_id'  => $ctx['_run_id'] ?? '',
				'workflow_id' => $ctx['_workflow_id'] ?? 0,
				// [2026-06-02 Johnny Chu] AUTOMATION SCHED-OWNER — gán event
				// vào owner của workflow để hiện trên calendar UI của họ.
				// Fallback get_current_user_id() = 0 trong cron context → event
				// mồ côi, calendar trống. Runner đã inject _owner_user_id.
				'user_id'     => (int) ( $ctx['_owner_user_id'] ?? $ctx['trigger']['wp_user_id'] ?? 0 ),
				'status'      => 'active',
				'source'      => 'workflow',
				'metadata'    => $metadata,
			);

			$t0  = microtime( true );
			$eid = 0;
			try {
				$eid = (int) BizCity_Automation_CRM_Bridge::create_event( $payload );
			} catch ( \Throwable $ex ) {
				$this->note_event( 'publish_fb_post_create_event_failed', array(
					'reason'      => 'crm_bridge_exception',
					'workflow_id' => (int) ( $ctx['_workflow_id'] ?? 0 ),
					'run_id'      => (string) ( $ctx['_run_id'] ?? '' ),
					'page_id'     => $t['page_id'],
					'error'       => $ex->getMessage(),
				) );
				return new WP_Error( 'crm_create_event_exception', 'publish_fb_post: ' . $ex->getMessage() );
			}
			$elapsed_ms = (int) ( ( microtime( true ) - $t0 ) * 1000 );

			if ( $eid <= 0 ) {
				$this->note_event( 'publish_fb_post_create_event_failed', array(
					'reason'      => 'crm_bridge_zero_id',
					'workflow_id' => (int) ( $ctx['_workflow_id'] ?? 0 ),
					'run_id'      => (string) ( $ctx['_run_id'] ?? '' ),
					'page_id'     => $t['page_id'],
					'elapsed_ms'  => $elapsed_ms,
				) );
				return new WP_Error( 'crm_create_event_zero', 'publish_fb_post: CRM bridge trả event_id=0 (xem scheduler logs).' );
			}

			$this->note_event( 'publish_fb_post_event_created', array(
				'reason'      => 'ok',
				'workflow_id' => (int) ( $ctx['_workflow_id'] ?? 0 ),
				'run_id'      => (string) ( $ctx['_run_id'] ?? '' ),
				'event_id'    => $eid,
				'page_id'     => $t['page_id'],
				'elapsed_ms'  => $elapsed_ms,
			) );

			$events[] = array(
				'event_id' => $eid,
				'page_id'  => $t['page_id'],
			);
		}

		// Backward-compat: single-target path keeps original output shape.
		if ( $target_mode === 'single' ) {
			$first = $events[0];
			return array(
				'event_id' => $first['event_id'],
				'mode'     => $mode,
				'due_at'   => $due_at,
				'page_id'  => $first['page_id'],
			);
		}

		return array(
			'target_mode' => 'all',
			'mode'        => $mode,
			'due_at'      => $due_at,
			'count'       => count( $events ),
			'events'      => $events,
		);
	}
}
