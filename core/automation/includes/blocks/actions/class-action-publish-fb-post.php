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
				'image_urls'     => '{{consume_attachment.attachment_urls}}',
				'mode'           => 'scheduled',
				'delay_min'      => 5,
			),
			'fields' => array(
				array( 'name' => 'label',        'label' => 'Tên hiển thị',             'type' => 'text' ),
				// [2026-06-02 Johnny Chu] AUTOMATION UX FB-PICKER — picker thay 2 ô text.
				array( 'name' => 'fb_page_id',   'label' => 'Fanpage đăng bài',         'type' => 'fb_page_picker' ),
				array( 'name' => 'content',      'label' => 'Nội dung post',            'type' => 'textarea' ),
				array( 'name' => 'image_url',    'label' => 'Ảnh đính kèm (URL)',       'type' => 'text' ),
				array( 'name' => 'image_urls',   'label' => 'Nhiều ảnh (JSON/CSV)',     'type' => 'textarea' ),
				array( 'name' => 'mode',         'label' => 'Chế độ',                   'type' => 'select', 'options' => self::MODE_OPTIONS ),
				array( 'name' => 'delay_min',    'label' => 'Trễ (phút) cho scheduled', 'type' => 'number' ),
			),
		);
	}

	public function execute( array $ctx, array $data ) {
		$image_urls = $this->resolve_image_urls( $ctx, $data );
		$image   = (string) ( $image_urls[0] ?? '' );
		$content_artifact_id = class_exists( 'BizCity_Content_Artifact_Service' )
			? BizCity_Content_Artifact_Service::create_or_get_from_ctx( $ctx, array( 'content_type' => 'fb_post' ) )
			: 0;
		$content = (string) $this->resolve( $data['content']    ?? '', $ctx );
		$image   = trim( (string) $this->resolve( $data['image_url'] ?? '', $ctx ) );
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-MY-CONTENT-TRACE — scheduled FB event follows linked chat user before workflow creator fallback.
		$owner_user_id = $this->resolve_owner_user_id( $ctx, 0 );

		if ( $content === '' ) {
			if ( $content_artifact_id > 0 ) {
				BizCity_Content_Artifact_Service::mark_stage( $content_artifact_id, 'failed', array( '_bizcity_error_code' => 'no_content', '_bizcity_error_message' => 'publish_fb_post: nội dung rỗng.' ) );
			}
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
					$db   = BizCity_Facebook_Bot_Database::instance();
					if ( $owner_user_id > 0 && method_exists( $db, 'get_bots_by_user' ) ) {
						$bots = $db->get_bots_by_user( $owner_user_id );
					} elseif ( $owner_user_id <= 0 && method_exists( $db, 'get_admin_bots' ) ) {
						$bots = $db->get_admin_bots();
					} else {
						$bots = $db->get_active_bots();
					}
					foreach ( (array) $bots as $b ) {
						$row = (array) $b;
						$pid = trim( (string) ( $row['page_id'] ?? '' ) );
						if ( $pid === '' ) { continue; }
						// [2026-07-15 Johnny Chu] PHASE-TWINWEB F4 — enforce owner-page scope even in mode=all.
						if ( is_wp_error( $this->assert_page_owner( $pid, $owner_user_id ) ) ) { continue; }
						$targets[] = array(
							'page_id'   => $pid,
							'page_name' => (string) ( $row['bot_name'] ?? $pid ),
						);
					}
				} catch ( \Throwable $e ) { /* swallow */ }
			}
			if ( empty( $targets ) ) {
				if ( $content_artifact_id > 0 ) {
					// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-MY-CONTENT-TRACE — persist owner/page resolution failure before returning WP_Error.
					BizCity_Content_Artifact_Service::mark_stage( $content_artifact_id, 'failed', array( '_bizcity_error_code' => 'permission_denied', '_bizcity_error_message' => 'Không có fanpage nào thuộc owner hiện tại.' ) );
					BizCity_Content_Artifact_Service::append_trace( $content_artifact_id, array(
						'stage' => 'failed', 'source' => 'automation', 'block_id' => $this->id(), 'status' => 'fail',
						'run_id' => (string) ( $ctx['_run_id'] ?? '' ), 'workflow_id' => (int) ( $ctx['_workflow_id'] ?? 0 ),
						'error_code' => 'permission_denied', 'message' => 'No Facebook page belongs to current owner.',
						'ctx' => array( 'owner_user_id' => $owner_user_id, 'target_mode' => $target_mode ),
					) );
				}
				$this->note_event( 'publish_fb_post_owner_mismatch', array(
					'reason'        => 'permission_denied',
					'owner_user_id' => $owner_user_id,
					'workflow_id'   => (int) ( $ctx['_workflow_id'] ?? 0 ),
					'run_id'        => (string) ( $ctx['_run_id'] ?? '' ),
				) );
				return new WP_Error( 'permission_denied', 'publish_fb_post: mode=all nhưng không có fanpage nào thuộc owner hiện tại.' );
			}
		} else {
			$page_id = trim( (string) $this->resolve( $data['fb_page_id'] ?? '', $ctx ) );
			if ( $page_id === '' ) {
				// [2026-07-21 Johnny Chu] PHASE-ATH — customer cron FB templates default to the page pinned in Twin GPT My Channels.
				$page_id = $this->resolve_owner_mychannels_fb_page_id( $owner_user_id );
			}
			if ( $page_id === '' ) {
				if ( $content_artifact_id > 0 ) {
					BizCity_Content_Artifact_Service::mark_stage( $content_artifact_id, 'failed', array( '_bizcity_error_code' => 'no_page', '_bizcity_error_message' => 'publish_fb_post: chưa chọn fanpage (fb_page_id rỗng).' ) );
					BizCity_Content_Artifact_Service::append_trace( $content_artifact_id, array(
						'stage' => 'failed', 'source' => 'automation', 'block_id' => $this->id(), 'status' => 'fail',
						'run_id' => (string) ( $ctx['_run_id'] ?? '' ), 'workflow_id' => (int) ( $ctx['_workflow_id'] ?? 0 ),
						'error_code' => 'no_page', 'message' => 'Facebook page id is empty.',
					) );
				}
				return new WP_Error( 'no_page', 'publish_fb_post: chưa chọn fanpage (fb_page_id rỗng).' );
			}
			// [2026-07-15 Johnny Chu] PHASE-TWINWEB F4 — refuse scheduling when page ownership mismatches workflow owner.
			$owner_check = $this->assert_page_owner( $page_id, $owner_user_id );
			if ( is_wp_error( $owner_check ) ) {
				if ( $content_artifact_id > 0 ) {
					// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-MY-CONTENT-TRACE — persist owner mismatch so My Content is the customer-visible debugger.
					BizCity_Content_Artifact_Service::mark_stage( $content_artifact_id, 'failed', array( '_bizcity_error_code' => (string) $owner_check->get_error_code(), '_bizcity_error_message' => $owner_check->get_error_message() ) );
					BizCity_Content_Artifact_Service::append_trace( $content_artifact_id, array(
						'stage' => 'failed', 'source' => 'automation', 'block_id' => $this->id(), 'status' => 'fail',
						'run_id' => (string) ( $ctx['_run_id'] ?? '' ), 'workflow_id' => (int) ( $ctx['_workflow_id'] ?? 0 ),
						'error_code' => (string) $owner_check->get_error_code(), 'message' => $owner_check->get_error_message(),
						'ctx' => array( 'page_id' => $page_id, 'owner_user_id' => $owner_user_id ),
					) );
				}
				$this->note_event( 'publish_fb_post_owner_mismatch', array(
					'reason'        => 'permission_denied',
					'owner_user_id' => $owner_user_id,
					'workflow_id'   => (int) ( $ctx['_workflow_id'] ?? 0 ),
					'run_id'        => (string) ( $ctx['_run_id'] ?? '' ),
					'page_id'       => $page_id,
					'error'         => $owner_check->get_error_message(),
				) );
				return $owner_check;
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
			if ( $content_artifact_id > 0 ) {
				// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-MY-CONTENT-TRACE — surface missing bridge in My Content.
				BizCity_Content_Artifact_Service::mark_stage( $content_artifact_id, 'failed', array( '_bizcity_error_code' => 'crm_bridge_missing', '_bizcity_error_message' => 'BizCity_Automation_CRM_Bridge chưa load.' ) );
				BizCity_Content_Artifact_Service::append_trace( $content_artifact_id, array(
					'stage' => 'failed', 'source' => 'automation', 'block_id' => $this->id(), 'status' => 'fail',
					'run_id' => (string) ( $ctx['_run_id'] ?? '' ), 'workflow_id' => (int) ( $ctx['_workflow_id'] ?? 0 ),
					'error_code' => 'crm_bridge_missing', 'message' => 'Automation CRM bridge class is not loaded.',
				) );
			}
			$this->note_event( 'publish_fb_post_bridge_missing_error', array(
				'reason'      => 'crm_bridge_missing',
				'workflow_id' => (int) ( $ctx['_workflow_id'] ?? 0 ),
				'run_id'      => (string) ( $ctx['_run_id'] ?? '' ),
			) );
			return new WP_Error( 'no_crm_bridge', 'publish_fb_post: BizCity_Automation_CRM_Bridge chưa load.' );
		}
		if ( ! class_exists( 'BizCity_Scheduler_Manager' ) ) {
			if ( $content_artifact_id > 0 ) {
				// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-MY-CONTENT-TRACE — surface missing scheduler in My Content.
				BizCity_Content_Artifact_Service::mark_stage( $content_artifact_id, 'failed', array( '_bizcity_error_code' => 'scheduler_missing', '_bizcity_error_message' => 'BizCity_Scheduler_Manager chưa load.' ) );
				BizCity_Content_Artifact_Service::append_trace( $content_artifact_id, array(
					'stage' => 'failed', 'source' => 'automation', 'block_id' => $this->id(), 'status' => 'fail',
					'run_id' => (string) ( $ctx['_run_id'] ?? '' ), 'workflow_id' => (int) ( $ctx['_workflow_id'] ?? 0 ),
					'error_code' => 'scheduler_missing', 'message' => 'Scheduler manager class is not loaded.',
				) );
			}
			$this->note_event( 'publish_fb_post_scheduler_missing_error', array(
				'reason'      => 'scheduler_missing',
				'workflow_id' => (int) ( $ctx['_workflow_id'] ?? 0 ),
				'run_id'      => (string) ( $ctx['_run_id'] ?? '' ),
			) );
			return new WP_Error( 'no_scheduler', 'publish_fb_post: BizCity_Scheduler_Manager chưa load (core/scheduler chưa boot trên site này).' );
		}

		foreach ( $targets as $t ) {
			// [2026-07-21 Johnny Chu] PHASE-IMG-FIRST-FB-FIX — dedup same inbound/run before creating another Facebook scheduler event.
			$fb_idempotency_key = $this->build_fb_idempotency_key( $ctx, $owner_user_id, (string) $t['page_id'], (int) $content_artifact_id, $content, implode( '|', $image_urls ) );
			$existing_eid = $this->find_existing_fb_scheduler_event( $fb_idempotency_key, $owner_user_id );
			if ( $existing_eid > 0 ) {
				$this->note_event( 'publish_fb_post_duplicate_suppressed', array(
					'reason'      => 'idempotency_hit',
					'workflow_id' => (int) ( $ctx['_workflow_id'] ?? 0 ),
					'run_id'      => (string) ( $ctx['_run_id'] ?? '' ),
					'event_id'    => $existing_eid,
					'page_id'     => $t['page_id'],
				) );
				$events[] = array(
					'event_id' => $existing_eid,
					'page_id'  => $t['page_id'],
				);
				continue;
			}

			if ( $content_artifact_id > 0 ) {
				BizCity_Content_Artifact_Service::mark_stage( $content_artifact_id, 'fb_scheduling', array(
					'_bizcity_fb_page_id' => $t['page_id'],
					'_bizcity_fb_page_name' => $t['page_name'],
					'_bizcity_fb_publish_status' => 'pending',
				) );
				BizCity_Content_Artifact_Service::append_trace( $content_artifact_id, array(
					'stage' => 'fb_scheduling', 'source' => 'automation', 'block_id' => $this->id(), 'status' => 'start',
					'run_id' => (string) ( $ctx['_run_id'] ?? '' ), 'workflow_id' => (int) ( $ctx['_workflow_id'] ?? 0 ),
					'message' => 'Creating Facebook scheduler event.', 'ctx' => array( 'page_id' => $t['page_id'], 'mode' => $mode ),
				) );
			}
			// [2026-06-03 Johnny Chu] R-SCH-REPLY — forward inbound{} qua helper
			// để Scheduler Completion Notifier reply về đúng kênh khi publish xong.
			$metadata = $this->build_event_metadata( $ctx, array(
				'fb_page_id'        => $t['page_id'],
				'fb_page_name'      => $t['page_name'],
				'fb_content'        => $content,
				'fb_image_url'      => $image,
				'fb_image_urls'     => $image_urls,
				'fb_publish_status' => 'pending',
				'fb_idempotency_key' => $fb_idempotency_key,
				'content_id'        => $content_artifact_id,
				'content_uuid'      => $content_artifact_id > 0 ? (string) get_post_meta( $content_artifact_id, '_bizcity_content_uuid', true ) : '',
				// [2026-07-15 Johnny Chu] PHASE-TWINWEB F4 — carry explicit owner for runtime revalidation.
				'owner_user_id'     => $owner_user_id,
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
				'user_id'     => $owner_user_id,
				'status'      => 'active',
				'source'      => 'workflow',
				'metadata'    => $metadata,
			);

			$t0  = microtime( true );
			$eid = 0;
			try {
				$eid = (int) BizCity_Automation_CRM_Bridge::create_event( $payload );
			} catch ( \Throwable $ex ) {
				if ( $content_artifact_id > 0 ) {
					// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-MY-CONTENT-TRACE — persist scheduler create exception before block fails.
					BizCity_Content_Artifact_Service::mark_stage( $content_artifact_id, 'failed', array( '_bizcity_error_code' => 'crm_create_event_exception', '_bizcity_error_message' => $ex->getMessage() ) );
					BizCity_Content_Artifact_Service::append_trace( $content_artifact_id, array(
						'stage' => 'failed', 'source' => 'automation', 'block_id' => $this->id(), 'status' => 'fail',
						'run_id' => (string) ( $ctx['_run_id'] ?? '' ), 'workflow_id' => (int) ( $ctx['_workflow_id'] ?? 0 ),
						'error_code' => 'crm_create_event_exception', 'message' => $ex->getMessage(),
						'ctx' => array( 'page_id' => $t['page_id'] ),
					) );
				}
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
				if ( $content_artifact_id > 0 ) {
					BizCity_Content_Artifact_Service::mark_stage( $content_artifact_id, 'failed', array( '_bizcity_error_code' => 'crm_create_event_zero', '_bizcity_error_message' => 'publish_fb_post: CRM bridge trả event_id=0.' ) );
					BizCity_Content_Artifact_Service::append_trace( $content_artifact_id, array(
						'stage' => 'failed', 'source' => 'automation', 'block_id' => $this->id(), 'status' => 'fail',
						'run_id' => (string) ( $ctx['_run_id'] ?? '' ), 'workflow_id' => (int) ( $ctx['_workflow_id'] ?? 0 ),
						'error_code' => 'crm_create_event_zero', 'message' => 'CRM bridge returned event_id=0.',
						'ctx' => array( 'page_id' => $t['page_id'], 'elapsed_ms' => $elapsed_ms ),
					) );
				}
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

			if ( $content_artifact_id > 0 ) {
				BizCity_Content_Artifact_Service::mark_stage( $content_artifact_id, 'fb_pending', array( '_bizcity_scheduler_event_id' => $eid ) );
				BizCity_Content_Artifact_Service::append_trace( $content_artifact_id, array(
					'stage' => 'fb_pending', 'source' => 'automation', 'block_id' => $this->id(), 'status' => 'ok',
					'run_id' => (string) ( $ctx['_run_id'] ?? '' ), 'workflow_id' => (int) ( $ctx['_workflow_id'] ?? 0 ), 'event_id' => $eid,
					'message' => 'Facebook scheduler event created.', 'ctx' => array( 'page_id' => $t['page_id'], 'due_at' => $due_at ),
				) );
			}

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

	private function resolve_image_urls( array $ctx, array $data ): array {
		// [2026-07-21 Johnny Chu] R-AUTO-MULTI-ATTACH — FB scheduler metadata carries fb_image_urls[] for multi-photo Graph publish.
		$urls = array();
		if ( array_key_exists( 'image_urls', $data ) ) { $this->append_url_value( $this->resolve( $data['image_urls'], $ctx ), $urls ); }
		if ( array_key_exists( 'image_url', $data ) ) { $this->append_url_value( $this->resolve( $data['image_url'], $ctx ), $urls ); }
		foreach ( array( 'consume', 'consume_attachment', 'capture', 'trigger' ) as $key ) {
			if ( isset( $ctx[ $key ] ) && is_array( $ctx[ $key ] ) ) { $this->append_url_value( $ctx[ $key ]['attachment_urls'] ?? array(), $urls ); }
		}
		if ( isset( $ctx['trigger']['_resume'] ) && is_array( $ctx['trigger']['_resume'] ) ) { $this->append_url_value( $this->extract_urls_from_state( $ctx['trigger']['_resume'] ), $urls ); }
		$out = array();
		foreach ( $urls as $url ) { $url = trim( (string) $url ); if ( $url !== '' && filter_var( $url, FILTER_VALIDATE_URL ) ) { $out[ $url ] = $url; } }
		return array_values( $out );
	}

	private function extract_urls_from_state( array $state ): array {
		$urls = array();
		$this->append_url_value( $state['attachment_urls'] ?? array(), $urls );
		if ( ! empty( $state['attachments'] ) && is_array( $state['attachments'] ) ) {
			foreach ( $state['attachments'] as $item ) { if ( is_array( $item ) ) { $this->append_url_value( $item['wp_url'] ?? $item['url'] ?? $item['source_url'] ?? '', $urls ); } }
		}
		$this->append_url_value( $state['attachment_url'] ?? '', $urls );
		return $urls;
	}

	private function append_url_value( $value, array &$urls ): void {
		if ( is_array( $value ) ) { foreach ( $value as $item ) { $this->append_url_value( $item, $urls ); } return; }
		$value = trim( (string) $value );
		if ( $value === '' || strpos( $value, '{{' ) !== false ) { return; }
		$decoded = json_decode( $value, true );
		if ( is_array( $decoded ) ) { $this->append_url_value( $decoded, $urls ); return; }
		foreach ( preg_split( '/[\r\n,]+/', $value ) as $part ) { $part = trim( (string) $part ); if ( $part !== '' ) { $urls[] = $part; } }
	}

	/**
	 * [2026-07-15 Johnny Chu] PHASE-TWINWEB F4 — owner-page authorization gate.
	 *
	 * @return true|WP_Error
	 */
	private function assert_page_owner( string $page_id, int $owner_user_id ) {
		$page_id       = trim( $page_id );
		$owner_user_id = (int) $owner_user_id;

		if ( $page_id === '' ) {
			return new WP_Error( 'invalid_param', 'publish_fb_post: fb_page_id rỗng.' );
		}
		if ( ! class_exists( 'BizCity_Facebook_Bot_Database' ) ) {
			return new WP_Error( 'module_not_loaded', 'publish_fb_post: Facebook bot database chưa load để verify ownership.' );
		}

		$db  = BizCity_Facebook_Bot_Database::instance();
		$bot = null;

		if ( $owner_user_id > 0 && method_exists( $db, 'get_bot_by_user_page' ) ) {
			$bot = $db->get_bot_by_user_page( $owner_user_id, $page_id );
		}
		if ( ! $bot && method_exists( $db, 'get_bot_by_page_id' ) ) {
			$bot = $db->get_bot_by_page_id( $page_id );
		}
		if ( ! $bot ) {
			return new WP_Error( 'not_found', 'publish_fb_post: fanpage chưa được kết nối hoặc đã bị gỡ.' );
		}

		$bot_owner = (int) ( is_object( $bot ) ? ( $bot->user_id ?? 0 ) : ( $bot['user_id'] ?? 0 ) );

		if ( $owner_user_id > 0 ) {
			if ( $bot_owner === $owner_user_id ) {
				return true;
			}
			// [2026-07-15 Johnny Chu] PHASE-TWINWEB F4 — backward-compat for legacy admin-owned rows (user_id=0).
			// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-MY-CONTENT-TRACE — avoid user_can() in webhook/cron path; inspect user caps directly.
			if ( $bot_owner <= 0 && ( $this->owner_has_manage_options_cap( $owner_user_id ) || $this->owner_can_use_site_shared_page( $owner_user_id, $page_id, $bot ) ) ) {
				return true;
			}
			return new WP_Error( 'permission_denied', 'publish_fb_post: fanpage không thuộc owner của workflow.' );
		}

		if ( $bot_owner > 0 ) {
			return new WP_Error( 'permission_denied', 'publish_fb_post: thiếu owner_user_id hợp lệ cho fanpage user-owned.' );
		}

		return true;
	}

	private function resolve_owner_mychannels_fb_page_id( int $owner_user_id ): string {
		// [2026-07-21 Johnny Chu] PHASE-ATH — runtime fallback for /gpt customer morning Facebook templates.
		if ( $owner_user_id <= 0 ) {
			return '';
		}
		// [2026-07-27 Johnny Chu] R-PERF — read My Channels once through direct-SQL user-meta cache.
		$settings = class_exists( 'BizCity_User_Meta_Cache' )
			? BizCity_User_Meta_Cache::get( $owner_user_id, 'bizcity_twinweb_mychannels', array() )
			: get_user_meta( $owner_user_id, 'bizcity_twinweb_mychannels', true );
		$selected = is_array( $settings ) ? trim( sanitize_text_field( (string) ( $settings['selected_fb_page_id'] ?? '' ) ) ) : '';
		if ( $selected !== '' ) {
			return $selected;
		}

		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — default empty FB picker to newest owner page, then newest site-shared page.
		if ( ! class_exists( 'BizCity_Facebook_Bot_Database' ) ) {
			return '';
		}
		try {
			$db = BizCity_Facebook_Bot_Database::instance();
			$rows = method_exists( $db, 'get_bots_by_user' ) ? (array) $db->get_bots_by_user( $owner_user_id ) : array();
			if ( empty( $rows ) && method_exists( $db, 'get_admin_bots' ) ) {
				$rows = (array) $db->get_admin_bots();
			}
			foreach ( $rows as $row ) {
				$item = (array) $row;
				$page_id = trim( sanitize_text_field( (string) ( $item['page_id'] ?? '' ) ) );
				if ( $page_id !== '' ) {
					return $page_id;
				}
			}
		} catch ( \Throwable $e ) {
			return '';
		}
		return '';
	}

	private function build_fb_idempotency_key( array $ctx, int $owner_user_id, string $page_id, int $content_artifact_id, string $content, string $image ): string {
		// [2026-07-21 Johnny Chu] PHASE-IMG-FIRST-FB-FIX — prefer provider message id so webhook replay / dual matcher cannot double-schedule FB publish.
		$trigger = isset( $ctx['trigger'] ) && is_array( $ctx['trigger'] ) ? $ctx['trigger'] : array();
		$inbound = isset( $trigger['inbound'] ) && is_array( $trigger['inbound'] ) ? $trigger['inbound'] : array();
		$mid = trim( (string) ( $trigger['mid'] ?? $trigger['message_id'] ?? $inbound['message_id'] ?? '' ) );
		$workflow_id = (int) ( $ctx['_workflow_id'] ?? 0 );
		if ( $mid !== '' ) {
			$seed = array( 'mid', $owner_user_id, $workflow_id, $page_id, $mid );
		} else {
			$run_id = trim( (string) ( $ctx['_run_id'] ?? '' ) );
			$seed = array( 'run', $owner_user_id, $workflow_id, $page_id, $run_id, $content_artifact_id, md5( $content ), md5( $image ) );
		}
		return 'fbpub_' . md5( wp_json_encode( $seed ) );
	}

	private function find_existing_fb_scheduler_event( string $idempotency_key, int $owner_user_id ): int {
		// [2026-07-21 Johnny Chu] PHASE-IMG-FIRST-FB-FIX — reuse active/done scheduler event when same publish request was already scheduled.
		$idempotency_key = sanitize_text_field( $idempotency_key );
		if ( $idempotency_key === '' || ! class_exists( 'BizCity_Scheduler_Manager' ) ) {
			return 0;
		}
		global $wpdb;
		$table = BizCity_Scheduler_Manager::instance()->get_table();
		if ( ! is_string( $table ) || $table === '' ) {
			return 0;
		}
		$sql = $wpdb->prepare(
			"SELECT id FROM {$table} WHERE event_type = %s AND user_id = %d AND status IN ('active','done') AND metadata LIKE %s ORDER BY id DESC LIMIT 1",
			'fb_post',
			(int) $owner_user_id,
			'%' . $wpdb->esc_like( '"fb_idempotency_key":"' . $idempotency_key . '"' ) . '%'
		);
		return (int) $wpdb->get_var( $sql );
	}

	private function owner_has_manage_options_cap( int $owner_user_id ): bool {
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-MY-CONTENT-TRACE — safe capability check without current_user_can()/user_can().
		if ( $owner_user_id <= 0 || ! function_exists( 'get_userdata' ) ) {
			return false;
		}
		$user = get_userdata( $owner_user_id );
		if ( ! $user || ! is_object( $user ) ) {
			return false;
		}
		$allcaps = is_array( $user->allcaps ?? null ) ? $user->allcaps : array();
		if ( ! empty( $allcaps['manage_options'] ) ) {
			return true;
		}
		$roles = is_array( $user->roles ?? null ) ? $user->roles : array();
		return in_array( 'administrator', $roles, true );
	}

	private function owner_can_use_site_shared_page( int $owner_user_id, string $page_id, $bot ): bool {
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-MY-CONTENT-TRACE — contributor/member may use admin-owned shared page selected in Twin GPT MyChannels.
		if ( $owner_user_id <= 0 || $page_id === '' ) {
			return false;
		}
		$allowed = (bool) apply_filters( 'bizcity_twinweb_allow_member_publish_shared_fb_page', true, $owner_user_id, $page_id, $bot );
		if ( ! $allowed ) {
			return false;
		}
		// [2026-07-27 Johnny Chu] R-PERF — reuse the request-level My Channels cache.
		$settings = class_exists( 'BizCity_User_Meta_Cache' )
			? BizCity_User_Meta_Cache::get( $owner_user_id, 'bizcity_twinweb_mychannels', array() )
			: get_user_meta( $owner_user_id, 'bizcity_twinweb_mychannels', true );
		$selected = is_array( $settings ) ? trim( (string) ( $settings['selected_fb_page_id'] ?? '' ) ) : '';
		return $selected === '' || $selected === $page_id;
	}
}
