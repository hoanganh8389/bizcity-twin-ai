<?php
/**
 * BizCity_Automation_Trigger_Matcher — central trigger dispatcher (BE-4).
 *
 * Listens to canonical hooks and routes inbound events to matching workflows:
 *
 *   1. `bizcity_channel_message_received`  (Channel Gateway inbound)
 *        → match workflows with trigger_type ∈ { zalo_inbound, fb_comment }
 *          + filter (trigger_config_json.filter) optionally contained in text.
 *
 *   2. `bizcity_scheduler_reminder_fire`   (Scheduler — event due)
 *        → if `event_type === 'automation_workflow'`, read
 *          metadata.workflow_id + metadata.payload → enqueue + run.
 *          THIS is how user "lên lịch chạy automation" qua trang Scheduler:
 *          tạo event mới với event_type=automation_workflow, đặt due_at,
 *          metadata = { workflow_id, payload? }. Scheduler-cron fire reminder
 *          → matcher dispatch → runner execute.
 *
 *   3. cron `bizcity_automation_cron_dispatch`
 *        → scan workflows trigger_type=cron, parse `schedule` expression,
 *          fire khi tới giờ. Bookkeeping qua option
 *          `bizcity_automation_cron_last_fired_<wf_id>`.
 *
 *   4. REST `POST /webhook/<slug>` (public, token-protected)
 *        → entry point cho 3rd-party hệ thống gọi vào.
 *          Handler nằm trong class-automation-rest.php; matcher chỉ cung cấp
 *          dispatch_webhook() helper.
 *
 * Tất cả enqueue đều ĐI QUA cron (defer) để tránh chặn request chính
 * (channel inbound webhook, scheduler tick…). Cron dispatcher đã có sẵn
 * BE-3 (`bizcity_automation_cron_dispatch` mỗi phút).
 *
 * R-CRON-META: matcher chính nó KHÔNG ghi cron meta (chỉ enqueue);
 * runner đã note `runs_picked/done/failed` + reason buckets ở BE-3.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Automation
 * @since      AUTOMATION BE-4 (2026-05-29)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Automation_Trigger_Matcher {

	const SCHEDULER_EVENT_TYPE = 'automation_workflow';
	const OPT_CRON_LAST        = 'bizcity_automation_cron_last_fired_';

	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) { self::$instance = new self(); }
		return self::$instance;
	}

	public static function init(): void {
		$self = self::instance();
		// Inbound channel messages — canonical Gateway Bridge path.
		add_action( 'bizcity_channel_message_received', array( $self, 'on_channel_message' ), 30, 1 );
		// UCL normalized envelope — fires for Zalo Bot standalone + FB + WebChat
		// (which never go through Gateway Bridge). Without this subscriber the
		// matcher silently misses ~100% of real Zalo inbound on prod.
		add_action( 'bizcity_channel_normalized', array( $self, 'on_channel_normalized' ), 30, 2 );
		// PG-S9-fix v4 — Zalo Bot raw intake. UCL envelope KHÔNG có media_url
		// (chỉ message_text). Subscribe trực tiếp vào intake để extract
		// attachments[0].payload.url cho Logic 1 (media-first stash).
		// Priority 5 — chạy TRƯỚC listener bus (priority 10) nhưng vẫn sau
		// universal-channel-listener bridge (priority 5).
		add_action( 'bizcity_zalo_webhook_intake', array( $self, 'on_zalo_intake' ), 5, 3 );
		// Scheduler reminder fire (priority 45 — after FB/Zalo/Woo handlers).
		add_action( 'bizcity_scheduler_reminder_fire', array( $self, 'on_scheduler_fire' ), 45, 1 );
		// Cron scan (piggy-back on runner dispatcher tick).
		add_action( BizCity_Automation_Runner::CRON_HOOK, array( $self, 'on_cron_scan' ), 5 );
	}

	private function __construct() {}

	// ─── (1) Channel inbound ─────────────────────────────────────────────
	public function on_channel_message( $payload ): void {
		if ( ! is_array( $payload ) ) { return; }
		$platform = strtoupper( (string) ( $payload['platform'] ?? '' ) );
		// Filter wp_user_id 'ASSISTANT' echo to avoid loops.
		$role     = strtoupper( (string) ( $payload['channel_role'] ?? '' ) );
		if ( $role === 'ASSISTANT' ) {
			BizCity_Automation_Matcher_Trace::note( 'rejected_role', array(
				'platform' => $platform,
				'chat_id'  => (string) ( $payload['chat_id'] ?? '' ),
				'detail'   => 'channel_role=ASSISTANT (loop guard)',
			) );
			return;
		}

		// BE-6.D — derive event_subtype for Facebook (messaging vs feed/comment).
		// Channel gateway adapter chưa emit field này, mình parse từ raw.
		$event_subtype = (string) ( $payload['event_subtype'] ?? '' );
		if ( $event_subtype === '' && $platform === 'FACEBOOK' ) {
			$entry         = $payload['raw']['entry'][0] ?? array();
			$event_subtype = ! empty( $entry['messaging'] ) ? 'messenger'
				: ( ! empty( $entry['changes'] ) ? 'feed' : 'unknown' );
		}

		$trigger_type = '';
		if ( strpos( $platform, 'ZALO' ) !== false ) {
			$trigger_type = 'zalo_inbound';
		} elseif ( strpos( $platform, 'TELEGRAM' ) !== false ) {
			$trigger_type = 'telegram_inbound';
		} elseif ( $platform === 'FACEBOOK' ) {
			$trigger_type = $event_subtype === 'feed' ? 'fb_comment' : 'fb_message';
		} else {
			// Generic channel — let plugins map via filter.
			$trigger_type = (string) apply_filters( 'bizcity_automation_map_trigger_type', '', $platform, $payload );
		}
		if ( $trigger_type === '' ) {
			BizCity_Automation_Matcher_Trace::note( 'no_trigger_type', array(
				'platform' => $platform,
				'chat_id'  => (string) ( $payload['chat_id'] ?? '' ),
				'detail'   => 'platform unmapped',
			) );
			return;
		}

		$text    = (string) ( $payload['message'] ?? $payload['text'] ?? '' );
		$inst    = (string) ( $payload['instance_id'] ?? $payload['account_id'] ?? '' );
		$chat_id = (string) ( $payload['chat_id'] ?? '' );

		// [2026-06-02 Johnny Chu] AUTOMATION DEDUP — persistent mid dedup.
		// `self::$seen_mids` chỉ chống trùng trong CÙNG PHP request. Khi cùng
		// 1 inbound được dispatch ở 2 request khác nhau (webhook intake +
		// listener bus replay sau đó), enqueue lần 2 sẽ tạo run trùng. Dùng
		// transient (TTL 5 phút) để dedup persistent xuyên request.
		$mid = (string) ( $payload['mid'] ?? $payload['message_id'] ?? '' );
		if ( $mid !== '' && $this->mid_seen_persistent( $platform, $mid ) ) {
			BizCity_Automation_Matcher_Trace::note( 'dedup_skip', array(
				'platform' => $platform,
				'chat_id'  => $chat_id,
				'text'     => $text,
				'detail'   => 'cross-request mid=' . $mid . ' already enqueued (transient hit)',
			) );
			return;
		}

		// PG-S9-fix — trace entry point so user can có thread to debug.
		BizCity_Automation_Matcher_Trace::note( 'enter', array(
			'platform'     => $platform,
			'chat_id'      => $chat_id,
			'text'         => $text,
			'media_url'    => (string) ( $payload['media_url'] ?? '' ),
			'trigger_type' => $trigger_type,
			'detail'       => 'inst=' . $inst,
		) );

		// Build canonical run payload once (shared cho mọi workflow + resume).
		// PHASE-0-RULE-CHANNEL-UNIFY (1.2) — re-export account_id, character_id,
		// user_id alias để block hạ nguồn (reply_*, mpr_think) không phải biết
		// format từng platform. character_id = guru bind từ Channel Binding.
		$sender_id = (string) ( $payload['sender_id'] ?? $payload['user_id'] ?? '' );
		$run_payload = array(
			'channel'       => $payload['platform']  ?? '',
			'platform'      => $payload['platform']  ?? '',
			'event_subtype' => $event_subtype,
			'text'          => $text,
			'message'       => $text,
			'instance_id'   => $inst,
			'account_id'    => $inst,
			'sender_id'     => $sender_id,
			'user_id'       => $sender_id, // alias: reply_zalo legacy đọc trigger.user_id
			'wp_user_id'    => (int) ( $payload['wp_user_id'] ?? 0 ),
			'character_id'  => (int) ( $payload['character_id'] ?? 0 ),
			'chat_id'       => $chat_id,
			'mid'           => $payload['mid'] ?? $payload['message_id'] ?? '',
			'media_url'     => $payload['media_url']  ?? '',
			'media_kind'    => $payload['media_kind'] ?? '',
			'raw'           => $payload['raw']        ?? null,
			'_trigger'      => $trigger_type,
		);

		// ─── BE-7.C — Resume rule (priority over keyword/fallback) ───────
		// Multi-turn slot: nếu chat_id có pending_state với workflow_id thì
		// CHỈ chạy đúng workflow đó (resume), bỏ qua keyword + fallback.
		// Pending state được set bởi `action.set_pending_intent` ở turn trước.
		if ( $chat_id !== '' && class_exists( 'BizCity_Automation_Pending_State' ) ) {
			$pending = BizCity_Automation_Pending_State::get( $chat_id );

			// PG-S9-fix (Logic 2) — Auto-merge incoming media_url vào
			// pending.attachment_url khi turn resume mang ảnh. set_pending_intent
			// chỉ lưu intent/workflow_id/slots — KHÔNG biết về media. Không có
			// dòng này thì cond `_resume.attachment_url != ''` luôn false ở turn 2
			// → flow rơi vào nhánh "hỏi gửi ảnh" lần nữa (dead loop).
			if ( ! empty( $pending ) && empty( $pending['attachment_url'] ) && ! empty( $payload['media_url'] ) ) {
				BizCity_Automation_Pending_State::patch( $chat_id, array(
					'attachment_url' => (string) $payload['media_url'],
				) );
				$pending['attachment_url'] = (string) $payload['media_url'];
			}

			$wf_id = (int) ( $pending['workflow_id'] ?? 0 );
			if ( $wf_id > 0 ) {
				$wf = BizCity_Automation_Repo_Workflows::find( $wf_id );
				if ( $wf && (int) ( $wf['enabled'] ?? 0 ) === 1 ) {
					$resume_payload = array_merge( $run_payload, array(
						'_resume'  => $pending,
						'_trigger' => $trigger_type,
					) );
					$this->enqueue_and_optionally_run( $wf, $resume_payload, false );
					BizCity_Automation_Matcher_Trace::note( 'resume_pending', array(
						'platform'     => $platform,
						'chat_id'      => $chat_id,
						'text'         => $text,
						'trigger_type' => $trigger_type,
						'wf_id'        => (int) $wf['id'],
						'detail'       => 'pending pinned wf_id=' . (int) $wf['id'] . ' attachment=' . ( ! empty( $pending['attachment_url'] ) ? 'YES' : 'NO' ),
					) );
					return; // resume preempts keyword / fallback.
				}
				// Workflow biến mất / disabled → clear pending để không kẹt.
				BizCity_Automation_Pending_State::clear( $chat_id );
			}

			// PG-S9-fix (Logic 1) — User gửi ẢNH TRƯỚC khi nói nội dung gì:
			// Lưu attachment vào pending (workflow_id=0 → không pin), reply hỏi
			// "muốn làm gì". Lượt sau matcher vẫn chạy keyword bình thường, nhưng
			// $run_payload['_resume'] đã chứa attachment_url (xem ngay dưới) nên
			// workflow trúng keyword đọc được ảnh đã gửi.
			$has_media       = ! empty( $payload['media_url'] );
			$text_trim       = trim( $text );
			$pending_is_empty = empty( $pending ) || empty( array_filter( array(
				$pending['intent']         ?? '',
				$pending['attachment_url'] ?? '',
				$pending['workflow_id']    ?? 0,
			) ) );
			if ( $has_media && $text_trim === '' && $pending_is_empty ) {
				BizCity_Automation_Pending_State::set( $chat_id, array(
					'intent'         => 'awaiting_media_purpose',
					'workflow_id'    => 0,
					'attachment_url' => (string) $payload['media_url'],
					'slots'          => array(),
				) );
				if ( function_exists( 'bizcity_channel_send' ) ) {
					bizcity_channel_send(
						$chat_id,
						'📎 Em đã nhận ảnh. Sếp muốn em làm gì với ảnh này? (vd: "đăng bài", "đăng FB"…) — em giữ ảnh trong 15 phút.'
					);
				}
				BizCity_Automation_Matcher_Trace::note( 'media_stash', array(
					'platform'  => $platform,
					'chat_id'   => $chat_id,
					'media_url' => (string) $payload['media_url'],
					'detail'    => 'image-first — stash + asked purpose',
				) );
				return; // pre-empt keyword + fallback for this media-only turn.
			}

			// Pending tồn tại NHƯNG không pin workflow (vd Logic 1 ở trên đã set
			// attachment_url): inject pending vào _resume để keyword-matched
			// workflow đọc được ảnh đã stash.
			if ( ! empty( $pending ) ) {
				$run_payload['_resume'] = $pending;
			}
		}

		$wfs = $this->find_active_workflows( $trigger_type );

		// ─── BE-7.D — Ref-based rule (priority over keyword/fallback) ────
		// Khi user click deep-link `m.me/<page>?ref=f.<uuid>` hoặc quét QR
		// chứa `?ref=f.<uuid>`, FB gửi `entry[].messaging[].(postback.)?referral.ref`.
		// Tương tự: Zalo `?ref=z.<uuid>`, Telegram `?start=t_<uuid>`.
		// → Khớp uuid với `trigger_config.scenario_uuid` và CHỈ chạy đúng wf đó,
		// bỏ qua keyword + fallback. Ref-based ăn keyword vì user chủ động pick.
		$ref_uuid = $this->extract_ref_uuid( $payload, $platform );
		if ( $ref_uuid !== '' ) {
			$ref_matched = array();
			foreach ( $wfs as $wf ) {
				$cfg  = $this->trigger_config( $wf );
				$uuid = trim( (string) ( $cfg['scenario_uuid'] ?? '' ) );
				if ( $uuid === '' || strcasecmp( $uuid, $ref_uuid ) !== 0 ) { continue; }
				// Optional instance-id sanity check.
				$wanted_inst = trim( (string) ( $cfg['instance_id'] ?? '' ) );
				if ( $wanted_inst !== '' && $wanted_inst !== $inst ) { continue; }
				$ref_matched[] = $wf;
			}
			if ( ! empty( $ref_matched ) ) {
				$run_payload['_ref'] = $ref_uuid;
				foreach ( $ref_matched as $wf ) {
					$this->enqueue_and_optionally_run( $wf, $run_payload, false );
				}
				$ids = array_map( static function ( $w ) { return (int) $w['id']; }, $ref_matched );
				BizCity_Automation_Matcher_Trace::note( 'matched_ref', array(
					'platform'     => $platform,
					'chat_id'      => $chat_id,
					'text'         => $text,
					'trigger_type' => $trigger_type,
					'detail'       => 'ref_uuid=' . $ref_uuid . ' fired wf_ids=' . implode( ',', $ids ),
				) );
				if ( class_exists( 'BizCity_Automation_File_Logger' ) ) {
					foreach ( $ids as $wfid ) {
						BizCity_Automation_File_Logger::note_decision( (int) $wfid, 'matcher.matched_ref', array(
							'platform'     => $platform,
							'chat_id'      => $chat_id,
							'ref_uuid'     => $ref_uuid,
							'trigger_type' => $trigger_type,
							'detail'       => 'ref-link click / qr scan',
						) );
					}
				}
				// [2026-06-02 Johnny Chu] AUTOMATION ACK — gửi reply xác nhận match ref.
				$this->send_match_ack( $run_payload, $ref_matched, 'ref' );
				return; // ref-based pre-empts keyword + fallback.
			}
			// Ref present nhưng không workflow nào claim → fall through sang keyword.
			BizCity_Automation_Matcher_Trace::note( 'ref_unmatched', array(
				'platform'     => $platform,
				'chat_id'      => $chat_id,
				'trigger_type' => $trigger_type,
				'detail'       => 'ref_uuid=' . $ref_uuid . ' no workflow claimed',
			) );
		}

		// BE-7.B — Fallback rule: nếu KHÔNG workflow nào match keyword/filter,
		// chạy các workflow đánh dấu `trigger_config.is_fallback=true` (sort theo
		// `priority` desc; mặc định 0). Đảm bảo mọi tin nhắn luôn có response —
		// thay vì im lặng khi user không gõ trúng keyword nào.
		$matched   = array();   // workflows passed filter (non-fallback).
		$fallbacks = array();   // workflows with is_fallback=true (sorted by priority).

		foreach ( $wfs as $wf ) {
			$cfg = $this->trigger_config( $wf );
			// Instance filter — áp dụng cho cả matched lẫn fallback.
			$wanted_inst = trim( (string) ( $cfg['instance_id'] ?? '' ) );
			if ( $wanted_inst !== '' && $wanted_inst !== $inst ) { continue; }

			$is_fallback = ! empty( $cfg['is_fallback'] );
			if ( $is_fallback ) {
				$fallbacks[] = array( 'wf' => $wf, 'cfg' => $cfg,
					'priority' => (int) ( $cfg['priority'] ?? 0 ) );
				continue;
			}
			// Non-fallback → BẮT BUỘC pass filter.
			if ( ! $this->channel_filter_match( $cfg, $text, $payload ) ) { continue; }
			$matched[] = array( 'wf' => $wf, 'cfg' => $cfg );
		}

		if ( ! empty( $matched ) ) {
			foreach ( $matched as $row ) {
				$this->enqueue_and_optionally_run( $row['wf'], $run_payload, false );
			}
			$ids = array_map( static function ( $r ) { return (int) $r['wf']['id']; }, $matched );
			BizCity_Automation_Matcher_Trace::note( 'matched_keyword', array(
				'platform'     => $platform,
				'chat_id'      => $chat_id,
				'text'         => $text,
				'trigger_type' => $trigger_type,
				'detail'       => 'fired wf_ids=' . implode( ',', $ids ),
			) );
			// PG-S9-fix v6 — fan-out mirror per matched workflow so each wf-{id}.jsonl
			// has a `matcher.matched_keyword` entry even before runner executes.
			if ( class_exists( 'BizCity_Automation_File_Logger' ) ) {
				foreach ( $ids as $wfid ) {
					BizCity_Automation_File_Logger::note_decision( (int) $wfid, 'matcher.matched_keyword', array(
						'platform'     => $platform,
						'chat_id'      => $chat_id,
						'text'         => $text,
						'trigger_type' => $trigger_type,
						'detail'       => 'fired with siblings=' . implode( ',', $ids ),
					) );
				}
			}
			// [2026-06-02 Johnny Chu] AUTOMATION ACK — gửi reply xác nhận match keyword
			// để user biết yc đã vào đúng workflow (UX feedback ngay lập tức, không
			// phải đợi workflow chạy xong mới thấy reply thật).
			$this->send_match_ack( $run_payload, array_column( $matched, 'wf' ), 'keyword' );
			return;
		}

		// Không workflow nào match → fan ra fallback (theo priority desc).
		if ( empty( $fallbacks ) ) {
			// PHASE-0-RULE-CHANNEL-UNIFY (1.2) — Built-in default reply safety net.
			// Khi không có workflow nào match VÀ không có is_fallback workflow,
			// chạy TwinBrain MPR Think trực tiếp + send qua channel sender.
			// Filter cho phép site tắt nếu muốn im lặng.
			if ( apply_filters( 'bizcity_automation_default_reply_enabled', true, $run_payload ) ) {
				if ( class_exists( 'BizCity_Automation_Default_Reply' ) ) {
					BizCity_Automation_Default_Reply::handle( $run_payload );
					BizCity_Automation_Matcher_Trace::note( 'default_reply', array(
						'platform'     => $platform,
						'chat_id'      => $chat_id,
						'text'         => $text,
						'trigger_type' => $trigger_type,
						'detail'       => 'no keyword + no fallback — ran TwinBrain default reply',
					) );
				} else {
					BizCity_Automation_Matcher_Trace::note( 'silent', array(
						'platform'     => $platform,
						'chat_id'      => $chat_id,
						'text'         => $text,
						'trigger_type' => $trigger_type,
						'detail'       => 'BizCity_Automation_Default_Reply class missing',
					) );
				}
			} else {
				BizCity_Automation_Matcher_Trace::note( 'silent', array(
					'platform'     => $platform,
					'chat_id'      => $chat_id,
					'text'         => $text,
					'trigger_type' => $trigger_type,
					'detail'       => 'default_reply disabled by filter',
				) );
			}
			return;
		}
		usort( $fallbacks, static function ( $a, $b ) {
			return ( $b['priority'] <=> $a['priority'] );
		} );
		$payload_fb = array_merge( $run_payload, array( '_fallback' => true ) );
		$fb_ids = array();
		foreach ( $fallbacks as $row ) {
			$this->enqueue_and_optionally_run( $row['wf'], $payload_fb, false );
			$fb_ids[] = (int) $row['wf']['id'];
		}
		BizCity_Automation_Matcher_Trace::note( 'fallback_fired', array(
			'platform'     => $platform,
			'chat_id'      => $chat_id,
			'text'         => $text,
			'trigger_type' => $trigger_type,
			'detail'       => 'fired fallback wf_ids=' . implode( ',', $fb_ids ),
		) );
	}

	// ─── (1b) UCL normalized envelope ────────────────────────────────────
	/**
	 * Bridge `bizcity_channel_normalized` (UCL canonical, fires for Zalo Bot
	 * standalone + FB + WebChat that bypass Gateway Bridge) into the same
	 * `on_channel_message()` codepath by adapting the envelope shape.
	 *
	 * Dedup is request-scoped by (platform, message_id) so when both this hook
	 * AND `bizcity_channel_message_received` fire in the same request, we only
	 * enqueue once.
	 *
	 * @param array  $envelope    UCL canonical envelope.
	 * @param string $trigger_key Original WAIC trigger key (unused here).
	 */
	public function on_channel_normalized( $envelope, $trigger_key = '' ): void {
		if ( ! is_array( $envelope ) ) { return; }
		$platform = strtoupper( (string) ( $envelope['platform'] ?? '' ) );
		$mid      = (string)        ( $envelope['message_id'] ?? '' );
		$dedup    = $platform . '|' . $mid;
		if ( $mid !== '' && isset( self::$seen_mids[ $dedup ] ) ) {
			BizCity_Automation_Matcher_Trace::note( 'dedup_skip', array(
				'platform' => $platform,
				'chat_id'  => (string) ( $envelope['chat_id'] ?? '' ),
				'detail'   => 'envelope mid=' . $mid . ' already processed in request',
			) );
			return;
		}
		if ( $mid !== '' ) { self::$seen_mids[ $dedup ] = true; }

		// UCL platform codes: ZALO_BOT / FB_MESS / FB_FEED / WEBCHAT / TELEGRAM
		// → map back to what on_channel_message() expects in `platform` field.
		$platform_norm = $platform;
		if ( $platform === 'FB_MESS' || $platform === 'FB_FEED' ) {
			$platform_norm = 'FACEBOOK';
		}
		$event_subtype = '';
		if ( $platform === 'FB_FEED' )                                        { $event_subtype = 'feed'; }
		elseif ( $platform === 'FB_MESS' )                                    { $event_subtype = 'messenger'; }
		elseif ( ( $envelope['event_type'] ?? '' ) === 'comment' )            { $event_subtype = 'feed'; }

		$adapted = array(
			'platform'      => $platform_norm,
			'event_subtype' => $event_subtype,
			'message'       => (string) ( $envelope['message']    ?? '' ),
			'instance_id'   => (string) ( $envelope['account_id'] ?? '' ),
			'account_id'    => (string) ( $envelope['account_id'] ?? '' ),
			'sender_id'     => (string) ( $envelope['user_id']    ?? '' ),
			'user_id'       => (string) ( $envelope['user_id']    ?? '' ),
			'wp_user_id'    => (int)    ( $envelope['wp_user_id']   ?? 0 ),
			'character_id'  => (int)    ( $envelope['character_id'] ?? 0 ),
			'chat_id'       => (string) ( $envelope['chat_id']    ?? '' ),
			'mid'           => $mid,
			'message_id'    => $mid,
			'media_url'     => (string) ( $envelope['media_url']  ?? '' ),
			'media_kind'    => (string) ( $envelope['media_kind'] ?? '' ),
			'channel_role'  => 'USER', // UCL skips ASSISTANT echos upstream.
			'raw'           => $envelope['raw'] ?? null,
		);
		$this->on_channel_message( $adapted );
	}

	/** Request-scoped dedup so canonical + normalized don't double-enqueue. */
	private static $seen_mids = array();

	/**
	 * PG-S9-fix v4 — Zalo Bot raw webhook intake.
	 *
	 * Hook `bizcity_zalo_webhook_intake($data, $secret_token, $intake_bot)` fires
	 * at the very top of /zalohook (xem PHASE-0-DOC-CHANNEL-LISTENING.md §Adapter).
	 * Đây là CHỖ DUY NHẤT có raw `$message['attachments'][0]['payload']['url']`
	 * để extract media URL cho Logic 1 (image-first stash). UCL envelope
	 * (`bizcity_channel_normalized`) chỉ giữ `message_text` → matcher không biết
	 * có ảnh hay không.
	 *
	 * Re-shape thành on_channel_message() payload thay vì duplicate logic.
	 * Dedup chính trong on_channel_message qua $seen_mids.
	 */
	public function on_zalo_intake( $data, $secret_token = '', $intake_bot = null ): void {
		if ( ! is_array( $data ) ) { return; }
		$event_name = (string) ( $data['event_name'] ?? '' );
		// Chỉ quan tâm message.*.received events. Skip follow/unfollow/typing/…
		if ( strpos( $event_name, 'message.' ) !== 0 ) { return; }

		$message = is_array( $data['message'] ?? null ) ? $data['message'] : array();
		if ( empty( $message ) ) { return; }

		$bot_id  = $intake_bot && isset( $intake_bot->id ) ? (string) $intake_bot->id : '';
		$user_id = (string) ( $message['from']['id'] ?? '' );
		if ( $bot_id === '' || $user_id === '' ) { return; }

		// Extract media URL.
		//
		// Zalo Bot API (NEW format, message.image.received) puts the CDN URL
		// directly at `$message['photo_url']` — verified against
		// bizcity-zalo-bot/includes/class-webhook-handler.php::process_new_zalo_format()
		// (image case @ ~line 488). The `attachments[0].payload.url` path
		// belongs to the LEGACY Zalo OA format (user_send_image) and is kept
		// here only as a fallback for cross-shape resilience.
		$media_url  = '';
		$media_kind = '';
		if ( ! empty( $message['photo_url'] ) ) {
			$media_url  = (string) $message['photo_url'];
			$media_kind = 'image';
		} elseif ( ! empty( $message['file_url'] ) ) {
			$media_url  = (string) $message['file_url'];
			$media_kind = 'file';
		} else {
			$attachments = is_array( $message['attachments'] ?? null ) ? $message['attachments'] : array();
			if ( ! empty( $attachments[0]['payload']['url'] ) ) {
				$media_url  = (string) $attachments[0]['payload']['url'];
				$media_kind = (string) ( $attachments[0]['type'] ?? '' );
			}
		}
		// Skip non-media events when no text either — avoid spamming Logic 1
		// path with empty noise.
		$text = (string) ( $message['text'] ?? $message['caption'] ?? '' );
		if ( $media_url === '' && trim( $text ) === '' ) { return; }

		// event_name = "message.image.received" → kind = "image"
		$parts    = explode( '.', $event_name );
		$msg_kind = isset( $parts[1] ) ? (string) $parts[1] : 'message';

		$chat_id = 'zalobot_' . $bot_id . '_' . $user_id;
		$mid     = (string) ( $message['message_id'] ?? '' );

		$adapted = array(
			'platform'      => 'ZALO_BOT',
			'event_subtype' => '',
			'message'       => $text,
			'text'          => $text,
			'instance_id'   => $bot_id,
			'account_id'    => $bot_id,
			'sender_id'     => $user_id,
			'user_id'       => $user_id,
			'wp_user_id'    => 0,
			'character_id'  => 0,
			'chat_id'       => $chat_id,
			'mid'           => $mid,
			'message_id'    => $mid,
			'media_url'     => $media_url,
			'media_kind'    => $msg_kind ?: $media_kind,
			'channel_role'  => 'USER',
			'raw'           => $data,
		);
		// Dedup ngay tại entry để on_channel_message + on_channel_normalized không
		// duplicate stash trên cùng 1 message (intake bắn trước, UCL fires sau).
		$dedup = 'ZALO_BOT|' . $mid;
		if ( $mid !== '' && isset( self::$seen_mids[ $dedup ] ) ) {
			BizCity_Automation_Matcher_Trace::note( 'dedup_skip', array(
				'platform' => 'ZALO_BOT',
				'chat_id'  => $chat_id,
				'detail'   => 'intake mid=' . $mid . ' already processed',
			) );
			return;
		}
		if ( $mid !== '' ) { self::$seen_mids[ $dedup ] = true; }

		// Trace evidence: confirms intake hook fired + media extraction result.
		// Reads in matcher trace UI as `intake` row — if missing, hook itself
		// never fired (check bizcity-zalo-bot plugin or webhook URL routing).
		BizCity_Automation_Matcher_Trace::note( 'intake', array(
			'platform'  => 'ZALO_BOT',
			'chat_id'   => $chat_id,
			'text'      => $text,
			'media_url' => $media_url,
			'detail'    => 'event=' . $event_name . ' kind=' . $msg_kind . ' mid=' . $mid,
		) );

		$this->on_channel_message( $adapted );
	}

	// ─── (2) Scheduler reminder fire ─────────────────────────────────────
	public function on_scheduler_fire( $event ): void {
		$event = is_object( $event ) ? (array) $event : (array) $event;
		if ( empty( $event['id'] ) )                                     { return; }
		if ( ( $event['event_type'] ?? '' ) !== self::SCHEDULER_EVENT_TYPE ) { return; }
		if ( ( $event['status'] ?? '' ) !== 'active' )                   { return; }

		$meta = $this->decode_metadata( $event['metadata'] ?? '' );
		$wf_id = (int) ( $meta['workflow_id'] ?? 0 );
		if ( $wf_id <= 0 ) {
			$this->note_event( 'automation_scheduler_invalid_metadata', array(
				'event_id' => (int) $event['id'],
				'reason'   => 'invalid_metadata',
				'detail'   => 'missing metadata.workflow_id',
			) );
			return;
		}
		$wf = BizCity_Automation_Repo_Workflows::find( $wf_id );
		if ( ! $wf || empty( $wf['enabled'] ) ) {
			$this->note_event( 'automation_scheduler_workflow_missing_error', array(
				'event_id'    => (int) $event['id'],
				'workflow_id' => $wf_id,
				'reason'      => 'invalid_metadata',
				'detail'      => $wf ? 'workflow disabled' : 'workflow row not found',
			) );
			return;
		}

		$payload = is_array( $meta['payload'] ?? null ) ? $meta['payload'] : array();
		$payload = array_merge( $payload, array(
			'_trigger'         => 'scheduler',
			'_scheduler_event' => (int) $event['id'],
			'scheduled_for'    => $event['start_at'] ?? null,
		) );

		// Run sync since we're already in cron context (scheduler cron handler).
		$this->enqueue_and_optionally_run( $wf, $payload, true );
	}

	// ─── (3) Cron scan trigger.cron ──────────────────────────────────────
	public function on_cron_scan(): void {
		$wfs = $this->find_active_workflows( 'cron' );
		$now = time();
		foreach ( $wfs as $wf ) {
			$cfg = $this->trigger_config( $wf );
			$schedule = (string) ( $cfg['schedule'] ?? '' );
			if ( $schedule === '' ) { continue; }

			$last_opt = self::OPT_CRON_LAST . (int) $wf['id'];
			$last_at  = (int) get_option( $last_opt, 0 );
			if ( ! $this->cron_should_fire( $schedule, $now, $last_at ) ) { continue; }

			update_option( $last_opt, $now, false );
			$this->enqueue_and_optionally_run( $wf, array(
				'_trigger' => 'cron',
				'fired_at' => gmdate( 'c', $now ),
				'schedule' => $schedule,
			), true );
		}
	}

	// ─── (4) Webhook dispatch (called from REST handler) ─────────────────
	/**
	 * Dispatch a webhook payload to a workflow identified by slug.
	 * Returns array { ok, run_id } | WP_Error.
	 */
	public function dispatch_webhook( string $slug, array $payload, ?string $token = null ) {
		// BE-6.B — capture-first hook for FE test listener (listener tự match slug).
		do_action( 'bizcity_automation_webhook_received', $slug, $payload );

		$wfs = BizCity_Automation_Repo_Workflows::query( array(
			'trigger_type' => 'webhook',
			'enabled'      => 1,
			'limit'        => 200,
		) );
		$found = null;
		foreach ( $wfs['rows'] as $wf ) {
			$cfg = $this->trigger_config( $wf );
			if ( ( $cfg['slug'] ?? '' ) === $slug ) { $found = $wf; break; }
		}
		if ( ! $found ) {
			return new WP_Error( 'webhook_not_found', 'Không có workflow nào dùng slug này.', array( 'status' => 404 ) );
		}
		$cfg    = $this->trigger_config( $found );
		$secret = (string) ( $cfg['secret'] ?? '' );
		// SECURITY (R-WEBHOOK): secret BAT BUOC. Empty secret = open endpoint
		// → to chuc co the bi tan cong tu xa qua slug doan duoc.
		if ( $secret === '' ) {
			return new WP_Error(
				'webhook_secret_missing',
				'Webhook workflow chua cau hinh trigger_config.secret. Mo workflow va dat secret truoc khi expose endpoint.',
				array( 'status' => 503 )
			);
		}
		if ( ! is_string( $token ) || $token === '' || ! hash_equals( $secret, (string) $token ) ) {
			return new WP_Error( 'webhook_token_invalid', 'Token webhook khong hop le.', array( 'status' => 401 ) );
		}

		$run_id = BizCity_Automation_Repo_Runs::enqueue( (int) $found['id'], array_merge( $payload, array(
			'_trigger' => 'webhook',
			'_slug'    => $slug,
		) ) );
		if ( is_wp_error( $run_id ) ) { return $run_id; }

		// Webhook caller expects fast 202 — defer to cron.
		do_action( 'bizcity_automation_run_enqueued', $run_id, (int) $found['id'], $payload );
		return array( 'ok' => true, 'run_id' => $run_id, 'mode' => 'deferred' );
	}

	// ─── Helpers ─────────────────────────────────────────────────────────

	private function find_active_workflows( string $trigger_type ): array {
		$out = BizCity_Automation_Repo_Workflows::query( array(
			'trigger_type' => $trigger_type,
			'enabled'      => 1,
			'limit'        => 200,
		) );
		return $out['rows'] ?? array();
	}

	private function trigger_config( array $wf ): array {
		$raw = $wf['trigger_config'] ?? null;
		if ( is_array( $raw ) ) { return $raw; }
		if ( is_string( $raw ) && $raw !== '' ) {
			$decoded = json_decode( $raw, true );
			return is_array( $decoded ) ? $decoded : array();
		}
		// Fallback: re-decode trigger_config_json column directly.
		if ( isset( $wf['trigger_config_json'] ) && is_string( $wf['trigger_config_json'] ) ) {
			$decoded = json_decode( $wf['trigger_config_json'], true );
			return is_array( $decoded ) ? $decoded : array();
		}
		return array();
	}

	private function decode_metadata( $raw ): array {
		if ( is_array( $raw ) ) { return $raw; }
		if ( is_string( $raw ) && $raw !== '' ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) { return $decoded; }
		}
		return array();
	}

	private function channel_filter_match( array $cfg, string $text, array $payload ): bool {
		// filter = empty → match all.
		$filter = trim( (string) ( $cfg['filter'] ?? '' ) );
		if ( $filter === '' ) { return true; }
		// Per-page filter for FB.
		$page_id = (string) ( $cfg['page_id'] ?? '' );
		if ( $page_id !== '' ) {
			$payload_page = (string) ( $payload['raw']['entry'][0]['id'] ?? $payload['page_id'] ?? '' );
			if ( $payload_page !== '' && $payload_page !== $page_id ) { return false; }
		}
		// BE-7.D-keywords — OR-match cả mảng `keywords[]` (Bot-Bán-Hàng style).
		// Mặc định fallback substring `filter` để giữ tương thích với data cũ.
		$keywords = isset( $cfg['keywords'] ) && is_array( $cfg['keywords'] )
			? array_filter( array_map( 'strval', $cfg['keywords'] ) )
			: array();
		if ( ! empty( $keywords ) ) {
			$haystack = $text === '' ? '' : mb_strtolower( $text );
			foreach ( $keywords as $kw ) {
				$kw_norm = mb_strtolower( trim( (string) $kw ) );
				if ( $kw_norm !== '' && $haystack !== '' && mb_strpos( $haystack, $kw_norm ) !== false ) {
					return true;
				}
			}
			return false;
		}
		return $text !== '' && stripos( $text, $filter ) !== false;
	}

	/**
	 * BE-7.D — Trích `ref` từ payload deep-link / postback / QR scan.
	 *
	 * Conventions (đặt bởi FE Scenario builder, scenarioLinks.js):
	 *   FB Messenger : ref = "f.<uuid>"
	 *   Zalo OA Bot  : ref = "z.<uuid>"
	 *   Telegram     : start payload = "t_<uuid>"
	 *
	 * @return string Lowercase uuid (no prefix), or '' nếu không có ref.
	 */
	private function extract_ref_uuid( array $payload, string $platform ): string {
		$candidates = array();

		// Direct fields some channels may already normalize.
		foreach ( array( 'ref', 'referral', 'start_payload' ) as $k ) {
			if ( isset( $payload[ $k ] ) && is_string( $payload[ $k ] ) ) {
				$candidates[] = $payload[ $k ];
			}
		}

		$raw = $payload['raw'] ?? null;
		if ( is_array( $raw ) ) {
			// FB Messenger: entry[].messaging[].postback.referral.ref OR entry[].messaging[].referral.ref
			if ( ! empty( $raw['entry'] ) && is_array( $raw['entry'] ) ) {
				foreach ( $raw['entry'] as $entry ) {
					if ( empty( $entry['messaging'] ) || ! is_array( $entry['messaging'] ) ) { continue; }
					foreach ( $entry['messaging'] as $msg ) {
						$cand = $msg['postback']['referral']['ref'] ?? $msg['referral']['ref'] ?? '';
						if ( is_string( $cand ) && $cand !== '' ) { $candidates[] = $cand; }
					}
				}
			}
			// Zalo OA: oa.referral.ref (vendor convention) — tolerate variants.
			if ( ! empty( $raw['referral']['ref'] ) && is_string( $raw['referral']['ref'] ) ) {
				$candidates[] = (string) $raw['referral']['ref'];
			}
			// Telegram: message.text starting with "/start <payload>" hoặc message.entities link.
			$tg_text = (string) ( $raw['message']['text'] ?? '' );
			if ( $tg_text !== '' && stripos( $tg_text, '/start ' ) === 0 ) {
				$candidates[] = trim( substr( $tg_text, 7 ) );
			}
			// Telegram start_param chuẩn (Bot API): callback_query.data hoặc message['start_payload'] (custom adapter).
			if ( ! empty( $raw['start_payload'] ) && is_string( $raw['start_payload'] ) ) {
				$candidates[] = (string) $raw['start_payload'];
			}
		}

		// Allow site-specific extractor for custom platforms.
		$candidates = (array) apply_filters( 'bizcity_automation_extract_ref_candidates', $candidates, $payload, $platform );

		foreach ( $candidates as $cand ) {
			$uuid = $this->parse_ref_uuid( (string) $cand );
			if ( $uuid !== '' ) { return $uuid; }
		}
		return '';
	}

	/**
	 * Parse "f.<uuid>", "z.<uuid>", "t_<uuid>", or bare uuid → lowercase uuid.
	 * Defensive: chỉ accept hex-ish 16-64 chars để tránh ai đó inject ref lung tung.
	 */
	private function parse_ref_uuid( string $ref ): string {
		$ref = trim( $ref );
		if ( $ref === '' ) { return ''; }
		// Strip known prefixes: "f.", "z.", "t_", "scenario_", "<FLOW>_".
		$ref = preg_replace( '/^(?:f|z|t)[._]/i', '', $ref ) ?? $ref;
		$ref = preg_replace( '/^<FLOW>_/i', '', $ref ) ?? $ref;
		$ref = preg_replace( '/^scenario_/i', '', $ref ) ?? $ref;
		// Some referrals carry trailing ".ref.<client_id>" (referral link variant).
		if ( strpos( $ref, '.ref.' ) !== false ) {
			$ref = (string) substr( $ref, 0, strpos( $ref, '.ref.' ) );
		}
		$ref = strtolower( trim( $ref ) );
		// Accept 16-64 alphanumeric chars (uuid no-dash, or hex digest, or base36).
		if ( preg_match( '/^[a-z0-9]{16,64}$/', $ref ) ) {
			return $ref;
		}
		return '';
	}

	/**
	 * Minimal cron expression eval. Supported formats:
	 *   - star-slash-N space-separated  -> every N minutes (cron shorthand)
	 *   - "0 H * * *"                    -> daily at hour H (site TZ)
	 *   - "every:N:minutes"              -> custom shorthand
	 *   - anything else                  -> daily check via last_at >= 24h
	 */
	private function cron_should_fire( string $schedule, int $now, int $last_at ): bool {
		$schedule = trim( $schedule );

		// Shorthand: every:N:minutes
		if ( preg_match( '/^every:(\d+):minutes?$/i', $schedule, $m ) ) {
			$interval = max( 1, (int) $m[1] ) * MINUTE_IN_SECONDS;
			return ( $now - $last_at ) >= $interval;
		}
		// Cron */N * * * *
		if ( preg_match( '#^\*/(\d+)\s+\*\s+\*\s+\*\s+\*$#', $schedule, $m ) ) {
			$interval = max( 1, (int) $m[1] ) * MINUTE_IN_SECONDS;
			return ( $now - $last_at ) >= $interval;
		}
		// Cron 0 H * * *  (daily at hour H, site timezone)
		if ( preg_match( '#^0\s+(\d{1,2})\s+\*\s+\*\s+\*$#', $schedule, $m ) ) {
			$target_hour = (int) $m[1];
			$now_hour    = (int) wp_date( 'G', $now );
			if ( $now_hour !== $target_hour ) { return false; }
			// Avoid double-fire within the same hour.
			return ( $now - $last_at ) >= ( HOUR_IN_SECONDS - MINUTE_IN_SECONDS );
		}
		// Fallback: daily.
		return ( $now - $last_at ) >= DAY_IN_SECONDS;
	}

	/**
	 * Enqueue + (optionally) execute synchronously.
	 *
	 * @param array $wf
	 * @param array $payload
	 * @param bool  $run_sync If true & runner exists, execute immediately
	 *                        (only safe inside cron context — caller decides).
	 * @return string|WP_Error run_id
	 */
	private function enqueue_and_optionally_run( array $wf, array $payload, bool $run_sync ) {
		$run_id = BizCity_Automation_Repo_Runs::enqueue( (int) $wf['id'], $payload );
		if ( is_wp_error( $run_id ) ) {
			$this->note_event( 'automation_enqueue_failed_error', array(
				'workflow_id' => (int) $wf['id'],
				'reason'      => 'enqueue_error',
				'error'       => $run_id->get_error_message(),
			) );
			return $run_id;
		}
		do_action( 'bizcity_automation_run_enqueued', $run_id, (int) $wf['id'], $payload );

		if ( $run_sync && class_exists( 'BizCity_Automation_Runner' ) ) {
			BizCity_Automation_Runner::instance()->execute( $run_id );
		}
		return $run_id;
	}

	private function note_event( string $name, array $data ): void {
		if ( ! class_exists( 'BizCity_Cron_Manager' ) ) { return; }
		BizCity_Cron_Manager::instance()->note_event( $name, $data );
	}

	/**
	 * [2026-06-02 Johnny Chu] AUTOMATION DEDUP — cross-request mid dedup.
	 * Trả true NẾU mid đã thấy trong 5 phút qua (đã enqueue). Lần đầu thấy →
	 * set transient + trả false để run tiếp.
	 */
	private function mid_seen_persistent( string $platform, string $mid ): bool {
		if ( $mid === '' ) { return false; }
		$key = 'bizcity_aut_mid_' . md5( strtoupper( $platform ) . '|' . $mid );
		if ( get_transient( $key ) ) { return true; }
		set_transient( $key, 1, 5 * MINUTE_IN_SECONDS );
		return false;
	}

	/**
	 * [2026-06-02 Johnny Chu] AUTOMATION ACK — gửi tin nhắn xác nhận ngay khi
	 * matcher match được workflow (keyword hoặc ref-based), trước khi runner
	 * thực thi workflow thật.
	 *
	 * Mục đích: user thấy feedback ngay ("Đã nhận yêu cầu · <tên wf>")
	 * thay vì phải đợi 5-30s workflow chạy xong mới có reply thật.
	 *
	 * Skip safely khi:
	 *   • filter `bizcity_automation_match_ack_enabled` trả false
	 *   • thiếu chat_id (không biết gửi về đâu)
	 *   • `BizCity_Gateway_Sender` chưa load
	 *   • payload `_test` hoặc `_dry_run` (FE Chạy thử tự hiển thị đã đủ)
	 *
	 * @param array  $run_payload Canonical run payload (phải có chat_id + platform).
	 * @param array  $matched_wfs Mảng workflow rows đã match (mỗi row có 'id', 'name').
	 * @param string $reason      'keyword' | 'ref' — dùng cho note_event + filter context.
	 */
	private function send_match_ack( array $run_payload, array $matched_wfs, string $reason ): void {
		$chat_id  = (string) ( $run_payload['chat_id'] ?? '' );
		$platform = (string) ( $run_payload['platform'] ?? '' );
		if ( $chat_id === '' || empty( $matched_wfs ) ) { return; }

		// Skip FE Chạy thử — panel đã hiển thị "✓ Capture" tại chỗ.
		if ( ! empty( $run_payload['_test'] ) || ! empty( $run_payload['_dry_run'] ) ) { return; }

		if ( ! apply_filters( 'bizcity_automation_match_ack_enabled', true, $run_payload, $matched_wfs, $reason ) ) {
			return;
		}
		if ( ! class_exists( 'BizCity_Gateway_Sender' ) ) { return; }

		$names = array();
		foreach ( $matched_wfs as $wf ) {
			$nm = trim( (string) ( $wf['name'] ?? $wf['slug'] ?? '' ) );
			if ( $nm !== '' ) { $names[] = $nm; }
		}
		if ( empty( $names ) ) { return; }
		$names_str = implode( ' + ', array_slice( $names, 0, 3 ) );

		$default_text = sprintf( '✓ Đã nhận yêu cầu · %s. Vui lòng chờ trong giây lát…', $names_str );
		$text = (string) apply_filters(
			'bizcity_automation_match_ack_text',
			$default_text,
			$names,
			$run_payload,
			$reason
		);
		$text = trim( $text );
		if ( $text === '' ) { return; }

		try {
			$res = BizCity_Gateway_Sender::instance()->send( $chat_id, $text, 'text', array(
				'source' => 'automation.match_ack',
			) );
			BizCity_Automation_Matcher_Trace::note( 'match_ack_sent', array(
				'platform' => $platform,
				'chat_id'  => $chat_id,
				'detail'   => sprintf(
					'reason=%s sent=%s wfs=%s err=%s',
					$reason,
					! empty( $res['sent'] ) ? '1' : '0',
					$names_str,
					(string) ( $res['error'] ?? '' )
				),
			) );
		} catch ( \Throwable $e ) {
			$this->note_event( 'automation_match_ack_failed', array(
				'platform' => $platform,
				'chat_id'  => $chat_id,
				'reason'   => '*_error',
				'error'    => $e->getMessage(),
			) );
		}
	}
}
