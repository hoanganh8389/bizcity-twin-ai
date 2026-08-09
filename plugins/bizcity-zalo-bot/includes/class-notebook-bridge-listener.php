<?php
/**
 * BizCity_Zalobot_Notebook_Bridge_Listener
 *
 * Detects "@notebook ..." / "@ghichu ..." capture commands (equivalent
 * aliases, see `BizCity_KG_Channel_Notebook_Bridge::parse_capture_command()`)
 * on the canonical `bizcity_zalo_message_received` bus (text, image, file)
 * and routes them through `BizCity_KG_Channel_Notebook_Bridge::capture()` so
 * a note / photo / document sent via Zalo Bot becomes a KG notebook source
 * without the user ever opening the TwinChat UI.
 *
 * Hook priority on `bizcity_zalo_message_received`:
 *   3 — User Linker
 *   4 — Command Router (identity commands) / THIS LISTENER (@notebook/@ghichu capture)
 *   5 — Guru Bridge (AI reply)
 *  10 — Legacy Gateway Bridge
 *
 * Both this listener and the Command Router run at priority 4 but never
 * collide: Command Router's keyword list has no "@notebook"/"@ghichu" entry,
 * and this listener bails immediately when `parse_capture_command()` finds
 * no marker, so exactly one of the two ever takes action for a given
 * inbound message.
 *
 * Works identically for group and private chats: replies are routed to
 * `provider_chat_id` (the raw Zalo conversation id — group id or private
 * user id), not `sender_user_id`, so a group capture replies in the group,
 * not as a DM to the sender.
 *
 * [2026-07-24 Johnny Chu] PHASE-0.46 W4 S4.6 — CLOSED (was a known gap):
 * a real `message.voice.received` payload confirmed Zalo voice messages
 * carry NO caption field at all (unlike `message.image.received`, which
 * has `caption`). A bare voice memo can therefore never itself contain the
 * capture marker.
 *
 * [2026-07-24 Johnny Chu] PHASE-0.46 W4 S4.7 — SUPERSEDES S4.6's single-slot
 * buffer with a full multi-file conversational flow (files sent BEFORE
 * and/or AFTER the trigger, always confirmed via "gửi thêm files không?"
 * before saving), backed by two dedicated transient stores on
 * `BizCity_KG_Channel_Notebook_Bridge`:
 *
 *   1) INBOX (list) — `queue_pending_attachment()` / `drain_pending_attachments()`.
 *      Any attachment arriving with NO capture marker queues here. The
 *      FIRST attachment in a fresh burst gets a one-time "sếp muốn em làm gì
 *      với file này?" prompt (not repeated for subsequent files in the same
 *      burst, to avoid spam if several photos land back-to-back).
 *
 *   2) SESSION — `start_capture_session()` / `append_session_attachment()` /
 *      `set_session_awaiting_more()` / `end_capture_session()`. Opened the
 *      moment "@notebook"/"@ghichu <title>" is seen (draining the inbox into
 *      it), then always asks "sếp có gửi thêm files không?" before
 *      finalizing — a "không/xong/..." reply finalizes via `capture_batch()`
 *      (ONE notebook, N items); a "có/..." reply keeps the session open; a
 *      bare attachment while awaiting appends silently; an ambiguous reply
 *      is ignored (lets AI/other listeners answer that turn normally).
 *
 * [2026-07-26 Johnny Chu] PHASE-0.46 W5 R4-R5 — both stores above ALSO
 * best-effort mirror every attachment into the generic
 * `BizCity_Automation_Pending_State` slot store (see
 * `BizCity_KG_Channel_Notebook_Bridge::mirror_attachment_to_automation_pending_state()`),
 * so any OTHER automation workflow reading that store never loses a file
 * that landed here first. "@ghichu" no longer triggers a separate
 * automation-keyword workflow (`tpl_zalo_capture_to_notebook_v1` is retired
 * at runtime via `maybe_disable_legacy_ghichu_workflow()`) — THIS listener
 * is now the single owner of both capture markers.
 *
 * Finalize sends exactly 2 synchronous replies ("đã tải lên" + "đã embedding
 * xong" — `ingest()` is confirmed synchronous, no cron hop) followed later by
 * ONE async "đã học xong" reply from `BizCity_KG_Channel_Progress_Notifier`
 * once every item in the batch finishes KG extraction.
 *
 * See docs/roadmaps/PHASE-0.46-CHANNEL-NOTEBOOK-BRIDGE.md §6 and §Wave 5.
 *
 * [2026-07-24 Johnny Chu] PHASE-0.46 W1 — initial implementation.
 * [2026-07-26 Johnny Chu] PHASE-0.46 W5 — @ghichu/@notebook trigger
 * unification; retire competing automation keyword workflow.
 *
 * @package BizCity_Zalo_Bot
 * @since   PHASE-0.46 Wave 1
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Zalobot_Notebook_Bridge_Listener', false ) ) {
	return;
}

class BizCity_Zalobot_Notebook_Bridge_Listener {

	/**
	 * Register the hook. Call once from bootstrap after Command Router.
	 */
	public static function boot(): void {
		// [2026-08-09 Johnny Chu] R-CH-UNI — notebook capture consumes the canonical Zone 2 envelope.
		add_action( 'bizcity_channel_normalized', array( __CLASS__, 'handle_normalized' ), 4, 2 );
		// [2026-07-26 Johnny Chu] PHASE-0.46 W5 R5 — "@ghichu" is now a native
		// alias of "@notebook" handled directly by THIS listener's session/
		// confirm-more-files state machine (see class docblock). The legacy
		// automation-workflow keyword trigger (tpl_zalo_capture_to_notebook_v1,
		// filter "@ghichu") is retired to avoid a second, disconnected capture
		// path that could not see files queued in this listener's own inbox.
		// Idempotent, cheap (checked once per request via a static guard).
		add_action( 'init', array( __CLASS__, 'maybe_disable_legacy_ghichu_workflow' ), 30 );
		// [2026-07-26 Johnny Chu] PHASE-0.46 W6 HOTFIX-2 — human-in-loop exit #1:
		// a genuinely NEW automation trigger firing for the SAME Zalo Bot
		// conversation while a capture session is still open must close that
		// stale session instead of leaving it open until TTL. See method doc.
		add_action( 'bizcity_automation_run_enqueued', array( __CLASS__, 'maybe_close_session_on_new_trigger' ), 10, 3 );
	}

	/**
	 * Adapt the canonical envelope and raw attachment metadata to the capture state machine.
	 */
	public static function handle_normalized( $envelope, $trigger_key = '' ): void {
		if ( ! is_array( $envelope ) || (string) ( $envelope['platform'] ?? '' ) !== 'ZALO_BOT' ) {
			return;
		}

		$raw = is_array( $envelope['raw'] ?? null ) ? $envelope['raw'] : array();
		$payload = $envelope;
		$payload['code']                = 'zalo_bot';
		$payload['bot_id']              = (int) ( $envelope['account_id'] ?? 0 );
		$payload['from_user_id']        = (string) ( $envelope['user_id'] ?? '' );
		$payload['sender_user_id']      = (string) ( $envelope['sender_user_id'] ?? $envelope['user_id'] ?? '' );
		$payload['message_text_clean']  = (string) ( $envelope['message_text_clean'] ?? $envelope['message'] ?? '' );
		$payload['message_text']        = $payload['message_text_clean'];
		$payload['text']                = $payload['message_text_clean'];
		$payload['provider_chat_id']    = (string) ( $envelope['provider_chat_id'] ?? $raw['provider_chat_id'] ?? $envelope['chat_id'] ?? '' );
		$payload['chat_kind']           = (string) ( $envelope['chat_kind'] ?? $raw['chat_kind'] ?? 'private' );
		$payload['wp_user_id']          = (int) ( $envelope['wp_user_id'] ?? 0 );
		foreach ( array( 'attachment_type', 'image_url', 'image_name', 'file_url', 'file_name', 'voice_url', 'unsupported_event' ) as $key ) {
			if ( array_key_exists( $key, $raw ) ) {
				$payload[ $key ] = $raw[ $key ];
			}
		}
		self::handle( $payload );
	}

	/**
	 * [2026-07-26 Johnny Chu] PHASE-0.46 W5 R5 — one-time, idempotent runtime
	 * migration: disable any already-imported automation workflow that still
	 * owns the "@ghichu" keyword trigger, so it stops racing the native
	 * listener above and creating a second, file-less notebook. Safe to run
	 * every request (single cheap indexed query, short-circuited after the
	 * first successful pass via an option flag).
	 */
	public static function maybe_disable_legacy_ghichu_workflow(): void {
		if ( get_option( 'bizcity_zalobot_ghichu_legacy_wf_disabled' ) ) {
			return;
		}
		// [2026-07-26 Johnny Chu] PHASE-0.46 W5 R5 / R-PERF — this is a
		// one-time DB migration; skip it on plain frontend page loads and
		// only run it in admin/REST/cron context so it doesn't add a query
		// to public-facing requests before the flag is set.
		$in_ctx = is_admin()
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			|| ( defined( 'DOING_CRON' ) && DOING_CRON )
			|| ( defined( 'WP_CLI' ) && WP_CLI );
		if ( ! $in_ctx ) {
			return;
		}
		if ( ! class_exists( 'BizCity_Automation_Repo_Workflows' ) ) {
			return;
		}
		$result = BizCity_Automation_Repo_Workflows::query( array(
			'trigger_type' => 'zalo_inbound',
			'enabled'      => 1,
			'limit'        => 50,
		) );
		$rows = isset( $result['rows'] ) && is_array( $result['rows'] ) ? $result['rows'] : array();
		foreach ( $rows as $row ) {
			// [2026-07-26 Johnny Chu] PHASE-0.46 W5 R5-FIX — the keyword
			// filter lives in the WORKFLOW-level `trigger_config_json.filter`
			// column (already decoded to `$row['trigger_config']['filter']`
			// by `BizCity_Automation_Repo_Workflows::hydrate()`), NOT inside
			// a graph node's `data.filter` — see
			// class-automation-trigger-matcher.php header comment.
			$cfg    = is_array( $row['trigger_config'] ?? null ) ? $row['trigger_config'] : array();
			$filter = trim( (string) ( $cfg['filter'] ?? '' ) );
			if ( $filter === '@ghichu' ) {
				BizCity_Automation_Repo_Workflows::soft_delete( (int) $row['id'] );
			}
		}
		update_option( 'bizcity_zalobot_ghichu_legacy_wf_disabled', 1, false );
	}

	/**
	 * @param mixed $msg bizcity_zalo_message_received payload.
	 */
	public static function handle( $msg ): void {
		if ( ! is_array( $msg ) ) { return; }

		// Zone 2 only — bail for Zone 1 (zalo_oa, zalo_personal) per R-ZONE.
		$code = (string) ( $msg['code'] ?? '' );
		if ( $code === 'zalo_oa' || $code === 'zalo_personal' ) { return; }

		// [2026-07-26 Johnny Chu] HOTFIX-DIAG — trace every invocation so a
		// silent no-reply (no PHP error, no send_message TRACE line) can be
		// pinpointed to the exact bail branch instead of guessed at. Cheap
		// (single error_log call), remove once R5 silent-drop root cause
		// is confirmed fixed.
		error_log( sprintf(
			'[NotebookBridge] handle() enter mid=%s attach_type=%s text_len=%d',
			(string) ( $msg['message_id'] ?? '' ),
			(string) ( $msg['attachment_type'] ?? 'text' ),
			mb_strlen( (string) ( $msg['message_text_clean'] ?? $msg['text'] ?? '' ), 'UTF-8' )
		) );

		$bot_id           = (int) ( $msg['bot_id'] ?? 0 );
		// [2026-07-24 Johnny Chu] PHASE-0.46 W1 — reply target MUST be the
		// conversation (group or private), never the sender identity alone,
		// otherwise a group @notebook capture would DM the sender instead of
		// replying in the group.
		$provider_chat_id = (string) ( $msg['provider_chat_id'] ?? $msg['from_user_id'] ?? '' );
		$zalo_uid         = (string) ( $msg['sender_user_id'] ?? $msg['from_user_id'] ?? '' );
		$message_id       = (string) ( $msg['message_id'] ?? '' );
		$chat_kind        = sanitize_key( (string) ( $msg['chat_kind'] ?? 'private' ) );
		$chat_kind        = $chat_kind === 'group' ? 'group' : 'private';
		$attachment_type  = (string) ( $msg['attachment_type'] ?? 'text' );
		$text             = trim( (string) ( $msg['message_text_clean'] ?? $msg['text'] ?? '' ) );
		$raw_event_name   = (string) ( $msg['raw']['event_name'] ?? $msg['unsupported_event'] ?? '' );

		if ( $bot_id <= 0 || $provider_chat_id === '' ) { return; }
		if ( ! class_exists( 'BizCity_KG_Channel_Notebook_Bridge' ) ) { return; }

		// Build kind/attachment for THIS message BEFORE checking for the
		// "@notebook" marker — a bare voice message (confirmed to NEVER
		// carry a caption) must still be queueable even though it can't
		// itself contain the marker.
		$kind       = 'text';
		$attachment = array();
		if ( $attachment_type === 'image' && ! empty( $msg['image_url'] ) ) {
			$kind       = 'image';
			$attachment = array(
				'kind'       => 'image',
				'url'        => (string) $msg['image_url'],
				'source_url' => (string) $msg['image_url'],
				'file_name'  => (string) ( $msg['image_name'] ?? '' ),
			);
			// [2026-07-25 Johnny Chu] PHASE-0.46 S3.1 — persist latest REAL Zalo
			// image payload evidence (safe snapshot) so DDV can verify live
			// webhook shape without requiring ad-hoc log scraping.
			self::remember_live_image_payload_evidence( $msg, $attachment );
		} elseif ( $attachment_type === 'file' && ! empty( $msg['file_url'] ) ) {
			$kind       = 'file';
			$attachment = array(
				'kind'       => 'file',
				'url'        => (string) $msg['file_url'],
				'source_url' => (string) $msg['file_url'],
				'file_name'  => (string) ( $msg['file_name'] ?? '' ),
			);
		} elseif ( $attachment_type === 'audio' && ! empty( $msg['voice_url'] ) ) {
			$kind       = 'audio';
			$attachment = array(
				'kind'       => 'audio',
				'url'        => (string) $msg['voice_url'],
				'source_url' => (string) $msg['voice_url'],
			);
		}
		if ( ! empty( $attachment ) ) {
			$attachment['message_id'] = $message_id;
		}

		// [2026-07-26 Johnny Chu] PHASE-0.46 W5 R4 — canonical
		// `BizCity_Automation_Pending_State` chat_id format for this channel
		// (matches `class-webhook-handler.php`'s `$conversation_chat_id` and the
		// Pending_State alias regex) so mirrored attachments are actually found
		// by OTHER automation workflows reading that store.
		$automation_chat_id = 'zalobot_' . $bot_id . '_' . ( $chat_kind === 'group' ? 'group_' : 'private_' ) . $provider_chat_id;

		$parsed = BizCity_KG_Channel_Notebook_Bridge::parse_capture_command( $text );
		$bridge = 'BizCity_KG_Channel_Notebook_Bridge';

		// [2026-07-26 Johnny Chu] HOTFIX-DIAG — see enter trace above; confirms
		// whether the "@ghichu/@notebook" marker was actually detected in $text.
		error_log( sprintf(
			'[NotebookBridge] parse_capture_command mid=%s matched=%s title=%s',
			$message_id,
			$parsed === null ? 'no' : 'yes',
			$parsed === null ? '-' : substr( (string) ( $parsed['title'] ?? '' ), 0, 40 )
		) );

		global $wpdb;
		$tbl = $wpdb->prefix . 'bizcity_zalo_bots';

		// ---------- 0. Doc-export combo: "xuất file word tóm tắt" ----------
		// [2026-07-24 Johnny Chu] PHASE-0.46 W5 S5.4 — a bare export phrase
		// carries NO "@notebook" marker, so it MUST be checked before the
		// "B. No @notebook marker" branch below returns early. Deliberately
		// a plain-text-only trigger (no attachment path) — exporting is a
		// command about EXISTING captured notes, not a new capture item.
		if ( $parsed === null && $kind === 'text' && self::is_doc_export_command( $text ) ) {
			$bot = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tbl} WHERE id = %d LIMIT 1", $bot_id ) );
			if ( ! $bot ) { return; }

			$wp_user_id = (int) ( $msg['wp_user_id'] ?? 0 );
			if ( $wp_user_id <= 0 && class_exists( 'BizCity_Zalobot_User_Linker' ) ) {
				$wp_user_id = BizCity_Zalobot_User_Linker::resolve_wp_user( $zalo_uid, $bot_id );
			}
			if ( $wp_user_id <= 0 ) {
				self::send( $bot, $provider_chat_id,
					"ℹ️ Bạn cần liên kết tài khoản trước khi xuất tài liệu.\nNhắn \"đăng nhập\" để kết nối, sau đó thử lại."
				);
				$GLOBALS['bizcity_zalobot_unlinked_skip'] = true;
				return;
			}

			$res = BizCity_KG_Channel_Notebook_Bridge::export_day_notebook_as_document( $wp_user_id );
			if ( is_wp_error( $res ) ) {
				self::send( $bot, $provider_chat_id, self::format_export_error( $res ) );
				$GLOBALS['bizcity_zalobot_unlinked_skip'] = true;
				return;
			}

			self::send( $bot, $provider_chat_id, sprintf(
				"📄 Đã tạo bản nháp tài liệu \"%s\" từ ghi chú hôm nay.\nMở link để xem/tạo nội dung và tải về: %s",
				(string) ( $res['title'] ?? '' ),
				(string) ( $res['url'] ?? '' )
			) );
			$GLOBALS['bizcity_zalobot_unlinked_skip'] = true;
			return;
		}

		$session = $bridge::get_capture_session( 'zalobot', $provider_chat_id );

		// ---------- A. An open session is waiting for "thêm files không?" ----------
		if ( is_array( $session ) && ! empty( $session['awaiting_more'] ) ) {
			if ( ! empty( $attachment ) ) {
				// More files arriving while awaiting — append silently. The
				// grouped "đã tải/đã embedding" reply is sent once at finalize,
				// not per file, per the requested UX.
				$bridge::append_session_attachment( 'zalobot', $provider_chat_id, $attachment, $bridge::PENDING_TTL_DEFAULT, $automation_chat_id );
				$GLOBALS['bizcity_zalobot_unlinked_skip'] = true;
				return;
			}

			if ( $attachment_type === 'unsupported' || $raw_event_name === 'message.unsupported.received' ) {
				$bot = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tbl} WHERE id = %d LIMIT 1", $bot_id ) );
				if ( $bot && ! self::should_skip_unsupported_notice( $bot_id, $message_id ) ) {
					// [2026-07-26 Johnny Chu] PHASE-0.46 W6 HOTFIX — unsupported
					// files can arrive while an open capture session is awaiting
					// "gửi thêm files?". Mint session-mode upload link here too,
					// otherwise this path falls through as ambiguous text and the
					// file is effectively lost.
					self::send_unsupported_guidance_with_upload_link( $bot, $bot_id, $provider_chat_id, $message_id, $zalo_uid, $msg, $session, $chat_kind, $automation_chat_id, $bridge );
					$GLOBALS['bizcity_zalobot_unlinked_skip'] = true;
				}
				return;
			}

			$decision = self::classify_more_files_reply( $text );
			if ( $decision === 'ambiguous' ) {
				return; // leave session open, let AI/other listeners answer this turn.
			}

			$bot = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tbl} WHERE id = %d LIMIT 1", $bot_id ) );
			if ( ! $bot ) { return; }

			if ( $decision === 'no' ) {
				// [2026-07-26 Johnny Chu] PHASE-0.46 W6 HOTFIX-2 — explicit human-in-loop
				// closure signal for "không/xong" replies so the user gets an immediate
				// "đã đóng phiên" confirmation before the save/embedding status messages.
				self::send( $bot, $provider_chat_id,
					"🔒 Đã đóng phiên ghi chú theo yêu cầu của sếp. Em đang lưu lại và chuyển sang task tiếp theo nhé."
				);
				self::finalize_capture_session( $bot, $provider_chat_id, $session );
			} else { // 'yes'
				$bridge::set_session_awaiting_more( 'zalobot', $provider_chat_id, true );
				// [2026-07-26 Johnny Chu] PHASE-0.46 W6 HOTFIX-2 — also offer the
				// instant upload link here so the user can upload a document/large
				// file directly instead of only relying on Zalo's own attach flow
				// (which cannot carry documents at all — see unsupported-file path).
				$upload_url = self::mint_session_upload_link( $bot_id, $provider_chat_id, $zalo_uid, $msg, $session, $chat_kind, $automation_chat_id, $bridge );
				$more_text  = "👌 Sếp gửi tiếp ảnh/file/ghi âm cho mình nhé.";
				if ( $upload_url !== '' ) {
					$more_text .= "\n📎 Hoặc bấm link sau để upload nhanh (nhất là file tài liệu): " . $upload_url;
				}
				$more_text .= "\nXong thì nhắn \"không\" hoặc \"xong\" để mình lưu lại.";
				self::send( $bot, $provider_chat_id, $more_text );
			}
			$GLOBALS['bizcity_zalobot_unlinked_skip'] = true;
			return;
		}

		// ---------- B. No "@notebook"/"@ghichu" marker in THIS message ----------
		if ( $parsed === null ) {
			if ( ! empty( $attachment ) ) {
				$before_count = $bridge::peek_pending_attachment_count( 'zalobot', $provider_chat_id );
				$bridge::queue_pending_attachment( 'zalobot', $provider_chat_id, $attachment, $bridge::PENDING_TTL_DEFAULT, $automation_chat_id );
				if ( $before_count === 0 ) {
					// Only prompt once per burst (0 → 1 transition), so 3-4
					// photos sent back-to-back don't each trigger a reply.
					$bot = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tbl} WHERE id = %d LIMIT 1", $bot_id ) );
					if ( $bot ) {
						self::send( $bot, $provider_chat_id,
							"📎 Em đã nhận được files, sếp muốn em làm gì ạ?\nGõ \"@ghichu <tiêu đề>\" (hoặc \"@notebook <tiêu đề>\") để lưu vào não Twin GPT, ví dụ: \"@ghichu tạo cuộc họp\"."
						);
						$GLOBALS['bizcity_zalobot_unlinked_skip'] = true;
					}
				}
			} elseif ( $attachment_type === 'unsupported' || $raw_event_name === 'message.unsupported.received' ) {
				$bot = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tbl} WHERE id = %d LIMIT 1", $bot_id ) );
				if ( $bot && ! self::should_skip_unsupported_notice( $bot_id, $message_id ) ) {
					self::send_unsupported_guidance_with_upload_link( $bot, $bot_id, $provider_chat_id, $message_id, $zalo_uid, $msg, $session, $chat_kind, $automation_chat_id, $bridge );
					$GLOBALS['bizcity_zalobot_unlinked_skip'] = true;
				}
			}
			return; // let AI/command router handle this turn as usual
		}

		// ---------- C. "@notebook"/"@ghichu <title>" marker found — resolve identity, start session ----------
		// [2026-07-26 Johnny Chu] PHASE-0.46 W5 R5 — this listener is now the
		// SOLE owner of both capture markers; the legacy automation-workflow
		// preemption for "@ghichu" (tpl_zalo_capture_to_notebook_v1) was
		// retired (see boot()/maybe_disable_legacy_ghichu_workflow()) so a
		// matched bridge command is never ceded to a competing workflow.

		$bot = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tbl} WHERE id = %d LIMIT 1", $bot_id ) );
		if ( ! $bot ) { return; }

		$wp_user_id = (int) ( $msg['wp_user_id'] ?? 0 );
		if ( $wp_user_id <= 0 && class_exists( 'BizCity_Zalobot_User_Linker' ) ) {
			$wp_user_id = BizCity_Zalobot_User_Linker::resolve_wp_user( $zalo_uid, $bot_id );
		}
		if ( $wp_user_id <= 0 ) {
			self::send( $bot, $provider_chat_id,
				"ℹ️ Bạn cần liên kết tài khoản trước khi lưu vào não Twin GPT.\nNhắn \"đăng nhập\" để kết nối, sau đó thử lại @notebook."
			);
			$GLOBALS['bizcity_zalobot_unlinked_skip'] = true;
			return;
		}

		$title_hint = (string) ( $parsed['title'] ?? '' );
		$content    = trim( (string) ( $parsed['content'] ?? '' ) );
		$has_explicit_body = strpos( $text, ':' ) !== false;
		$pending_before    = $bridge::peek_pending_attachment_count( 'zalobot', $provider_chat_id );
		// If the trigger message ITSELF carries an attachment (e.g. an image
		// with caption "@notebook tạo cuộc họp"), the caption is the TITLE
		// only — matches legacy single-capture behavior (no duplicate near-
		// identical text source alongside the image).
		$seed_content = ( $attachment === array() ) ? $content : '';
		// [2026-07-24 Johnny Chu] PHASE-0.46 W4 S4.7-FIX — if files were sent
		// BEFORE this trigger and user types bare "@notebook <title>" (no
		// ':' body), treat it as title-only marker instead of auto-creating a
		// redundant text item whose content equals the title.
		if ( $seed_content !== '' && $pending_before > 0 && ! $has_explicit_body && $seed_content === $title_hint ) {
			$seed_content = '';
		}

		$new_session = $bridge::start_capture_session( 'zalobot', $provider_chat_id, array(
			'user_id'         => $wp_user_id,
			'chat_kind'       => $chat_kind,
			'title_hint'      => $title_hint,
			'content'         => $seed_content,
			'text_message_id' => $message_id,
			'inbound_base'    => array(
				'platform'   => 'ZALOBOT',
				'chat_id'    => $provider_chat_id,
				'user_id'    => $zalo_uid,
				'account_id' => (string) $bot_id,
			),
		) );
		if ( ! empty( $attachment ) ) {
			$new_session = $bridge::append_session_attachment( 'zalobot', $provider_chat_id, $attachment, $bridge::PENDING_TTL_DEFAULT, $automation_chat_id ) ?? $new_session;
		}

		$has_anything = $seed_content !== '' || ! empty( $new_session['attachments'] );
		if ( ! $has_anything ) {
			$bridge::end_capture_session( 'zalobot', $provider_chat_id );
			self::send( $bot, $provider_chat_id,
				"ℹ️ Bạn muốn ghi gì vào não Twin GPT?\nVí dụ: \"@notebook hop sale: hôm nay chốt được 3 hợp đồng bảo hiểm ô tô\"."
			);
			$GLOBALS['bizcity_zalobot_unlinked_skip'] = true;
			return;
		}

		$bridge::set_session_awaiting_more( 'zalobot', $provider_chat_id, true );
		// [2026-07-26 Johnny Chu] PHASE-0.46 W6 HOTFIX-8 — proactively mint the
		// upload link and warn about unsupported document types UP FRONT here
		// (session just opened), instead of only reacting AFTER the user
		// already wasted an attempt sending a docx/pdf that Zalo Bot rejects.
		$upload_url = self::mint_session_upload_link( $bot_id, $provider_chat_id, $zalo_uid, $msg, $new_session, $chat_kind, $automation_chat_id, $bridge );
		$more_text  = "🙋 Sếp có muốn gửi thêm ảnh/file/ghi âm nào nữa không?\nGửi tiếp file, hoặc nhắn \"không\"/\"xong\" để mình lưu lại ngay.";
		if ( $upload_url !== '' ) {
			$more_text .= "\n⚠️ Lưu ý: Zalobot chưa hỗ trợ nhận trực tiếp file dạng tài liệu (Word/Excel/PDF...).";
			$more_text .= "\n📎 Sếp bấm link này để upload nhiều tài liệu cùng lúc: " . $upload_url;
		}
		self::send( $bot, $provider_chat_id, $more_text );
		$GLOBALS['bizcity_zalobot_unlinked_skip'] = true;
	}

	/**
	 * [2026-07-26 Johnny Chu] PHASE-0.46 W6 HOTFIX-2 — human-in-loop exit #1:
	 * a genuinely NEW automation trigger (e.g. "marketing, hãy làm việc này…")
	 * firing while a "@ghichu" capture session is still open+awaiting_more for
	 * the SAME Zalo Bot conversation must close that stale session instead of
	 * leaving it open in the background until TTL (25 min). The matcher's own
	 * `notebook_capture_preempt` guard only bails when the CURRENT message
	 * itself carries the marker — it has no notion of an already-open session
	 * — so cleanup happens here, reactively, once a DIFFERENT workflow has
	 * actually fired for this same conversation.
	 *
	 * @param string|int $run_id
	 * @param int        $workflow_id
	 * @param array      $payload canonical automation run payload (see
	 *                            class-automation-trigger-matcher.php `$run_payload`).
	 */
	public static function maybe_close_session_on_new_trigger( $run_id, $workflow_id, $payload ): void {
		if ( ! is_array( $payload ) ) { return; }
		if ( strtoupper( (string) ( $payload['platform'] ?? '' ) ) !== 'ZALO_BOT' ) { return; }
		$provider_chat_id = (string) ( $payload['provider_chat_id'] ?? '' );
		if ( $provider_chat_id === '' ) { return; }
		if ( ! class_exists( 'BizCity_KG_Channel_Notebook_Bridge' ) ) { return; }

		$bridge  = 'BizCity_KG_Channel_Notebook_Bridge';
		$session = $bridge::get_capture_session( 'zalobot', $provider_chat_id );
		if ( ! is_array( $session ) || empty( $session['awaiting_more'] ) ) { return; }

		$bridge::end_capture_session( 'zalobot', $provider_chat_id );

		$bot_id = (int) ( $payload['account_id'] ?? 0 );
		if ( $bot_id <= 0 ) { return; }
		global $wpdb;
		$tbl = $wpdb->prefix . 'bizcity_zalo_bots';
		$bot = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tbl} WHERE id = %d LIMIT 1", $bot_id ) );
		if ( ! $bot ) { return; }

		self::send( $bot, $provider_chat_id,
			"🔒 Đã đóng phiên ghi chú (sếp chuyển sang việc khác rồi). Muốn ghi tiếp thì nhắn \"@ghichu <tiêu đề>\" nhé."
		);
	}

	/**
	 * Classify a bare text reply while a capture session is awaiting more
	 * files. Returns 'yes' | 'no' | 'ambiguous' (unrecognized → let other
	 * listeners/AI answer that turn normally, session stays open).
	 *
	 * [2026-07-26 Johnny Chu] PHASE-0.46 W6 HOTFIX-2 — human-in-loop exit #2:
	 * short natural phrases like "ok xong rồi" / "thôi vậy nhé" do not
	 * exact-match any single word above (whole-string `in_array()` only), so
	 * they used to fall through to 'ambiguous' and leave the session open
	 * silently instead of finalizing/continuing. Added a bounded (≤6 tokens)
	 * whole-word fallback so a no/yes word appearing ANYWHERE inside a short
	 * confirmation phrase still counts, with 'no' words checked first so a
	 * closing intent (e.g. "xong" in "ok xong rồi") always wins over a
	 * filler acknowledgement word (e.g. "ok").
	 */
	private static function classify_more_files_reply( string $text ): string {
		$norm = mb_strtolower( trim( $text ) );
		$norm = trim( $norm, ".!?…, \t\n\r\0\x0B" );
		if ( $norm === '' ) {
			return 'ambiguous';
		}
		$no_words  = array( 'khong', 'không', 'ko', 'k', 'no', 'het', 'hết', 'xong', 'done', 'stop', 'thoi', 'thôi' );
		$yes_words = array( 'co', 'có', 'yes', 'ok', 'okay', 'duoc', 'được', 'tiep', 'tiếp', 'yep' );
		if ( in_array( $norm, $no_words, true ) ) {
			return 'no';
		}
		if ( in_array( $norm, $yes_words, true ) ) {
			return 'yes';
		}
		$tokens = preg_split( '/[\s,]+/u', $norm, -1, PREG_SPLIT_NO_EMPTY );
		if ( is_array( $tokens ) && count( $tokens ) > 1 && count( $tokens ) <= 6 ) {
			foreach ( $tokens as $tok ) {
				if ( in_array( $tok, $no_words, true ) ) {
					return 'no';
				}
			}
			foreach ( $tokens as $tok ) {
				if ( in_array( $tok, $yes_words, true ) ) {
					return 'yes';
				}
			}
		}
		return 'ambiguous';
	}

	/**
	 * Close the session, ingest everything collected (text note + all
	 * attachments) into ONE notebook via `capture_batch()`, and send the
	 * synchronous "đã tải + đã embedding" replies. The final "đã học xong"
	 * reply is sent later, asynchronously, by
	 * `BizCity_KG_Channel_Progress_Notifier` once KG extraction finishes for
	 * every item in the batch.
	 */
	private static function finalize_capture_session( object $bot, string $provider_chat_id, array $session ): void {
		$bridge = 'BizCity_KG_Channel_Notebook_Bridge';
		// End FIRST — a duplicate/late reply must never re-finalize the same session.
		$bridge::end_capture_session( 'zalobot', $provider_chat_id );

		$attachments = is_array( $session['attachments'] ?? null ) ? $session['attachments'] : array();
		$content     = trim( (string) ( $session['content'] ?? '' ) );
		$title_hint  = (string) ( $session['title_hint'] ?? '' );
		$user_id     = (int) ( $session['user_id'] ?? 0 );
		$chat_kind   = (string) ( $session['chat_kind'] ?? 'private' );
		$inbound_base = is_array( $session['inbound_base'] ?? null ) ? $session['inbound_base'] : array();

		$items = array();
		if ( $content !== '' ) {
			$items[] = array(
				'kind'       => 'text',
				'content'    => $content,
				'title_hint' => $title_hint,
				'message_id' => (string) ( $session['text_message_id'] ?? '' ),
			);
		}
		// [2026-07-24 Johnny Chu] PHASE-0.46 W4 S4.7 BUGFIX — `build_ingest_payload()`
		// reads attachment fields from `$item['attachment']` (a NESTED array), but
		// `$att` here is the FLAT shape produced by `queue_pending_attachment()`/
		// `append_session_attachment()` (`{kind,url,source_url,file_name,message_id}`).
		// Merging it flat onto the item meant `$item['attachment']` was always
		// missing, so EVERY image/file/audio batch item silently failed with
		// `notebook_bridge_no_attachment` — text-only batches were unaffected.
		// Confirmed by cross-checking the DDV probe's step (h), which (correctly)
		// nests under 'attachment' and therefore never caught this real-world
		// mismatch. Wrapping `$att` under 'attachment' here matches the DDV
		// probe's already-correct shape and what `capture()`'s single-item path
		// (envelope['attachment']) has always expected.
		foreach ( $attachments as $att ) {
			$items[] = array(
				'kind'       => (string) ( $att['kind'] ?? 'file' ),
				'title_hint' => $title_hint,
				'message_id' => (string) ( $att['message_id'] ?? '' ),
				'attachment' => $att,
			);
		}
		if ( empty( $items ) ) {
			self::send( $bot, $provider_chat_id, "ℹ️ Không có nội dung/tệp nào để lưu — bạn thử lại @notebook giúp mình." );
			return;
		}

		// [2026-08-02 Johnny Chu] PHASE-SKILLS-JOURNAL — persist the
		// canonical Journal Entry before KG projection; replay returns the same row.
		$journal_entry = self::create_journal_entry_from_capture( $session, $items, $provider_chat_id );
		if ( is_wp_error( $journal_entry ) ) {
			self::send( $bot, $provider_chat_id, '⚠️ Không thể lưu nhật ký lúc này. Vui lòng thử lại sau.' );
			return;
		}

		$base_envelope = array(
			'user_id'          => $user_id,
			'channel'          => 'zalobot',
			'chat_id'          => $provider_chat_id,
			'chat_kind'        => $chat_kind,
			'provider_chat_id' => $provider_chat_id,
			'scope_type'       => $chat_kind,
			'scope_id'         => $provider_chat_id,
			'title_hint'       => $title_hint,
			'inbound'          => $inbound_base,
			'journal_entry_id' => (int) ( $journal_entry['id'] ?? 0 ),
		);

		$res = BizCity_KG_Channel_Notebook_Bridge::instance()->capture_batch( $base_envelope, $items );
		if ( is_wp_error( $res ) ) {
			if ( class_exists( 'BizCity_Journal_Database' ) ) {
				BizCity_Journal_Database::instance()->mark_learning_projection(
					(int) $journal_entry['id'],
					$user_id,
					array(
						'learning_status' => 'failed',
						'learning_error'  => $res->get_error_code(),
					)
				);
			}
			self::send( $bot, $provider_chat_id, self::format_capture_error( $res ) );
			return;
		}

		$notebook_name = (string) ( $res['notebook_name'] ?? '' );
		$succeeded     = (int) ( $res['succeeded'] ?? 0 );
		// [2026-07-25 Johnny Chu] PHASE-0.46 W4.5.3 — queued media/file items now learn via cron dispatch.
		$queued        = (int) ( $res['queued'] ?? 0 );
		$total         = (int) ( $res['total'] ?? 0 );
		$created       = ! empty( $res['notebook_created'] );
		// [2026-08-02 Johnny Chu] PHASE-SKILLS-JOURNAL — synchronous text
		// ingest is already learned; only queued media remains asynchronous.
		$failed_count = count( (array) ( $res['failed'] ?? array() ) );
		$projection_status = $queued > 0
			? 'queued'
			: ( ( $succeeded > 0 && $failed_count === 0 ) ? 'learned' : ( $succeeded > 0 ? 'retryable' : 'failed' ) );
		$projection_source_id = self::first_projection_source_id( $res );
		if ( class_exists( 'BizCity_Journal_Database' ) ) {
			BizCity_Journal_Database::maybe_install();
			BizCity_Journal_Database::instance()->mark_learning_projection(
				(int) $journal_entry['id'],
				$user_id,
				array(
					'learning_status' => $projection_status,
					'notebook_id'     => (int) ( $res['notebook_id'] ?? 0 ),
					'kg_source_id'    => $projection_source_id,
					'metadata'        => array(
						'channel'  => 'zalobot',
						'batch_id' => (string) ( $res['batch_id'] ?? '' ),
						'inbound'  => $inbound_base,
					),
				)
			);
		}
		// [2026-07-25 Johnny Chu] PHASE-0.46 W4.6 — explicit accepted file list
		// + source-scoped learning-log share link for channel confirmations.
		$accepted_summary = self::build_batch_accept_summary( $items, $res );
		$share_link = self::create_learning_share_link(
			(int) ( $res['notebook_id'] ?? 0 ),
			(int) ( $accepted_summary['first_source_id'] ?? 0 )
		);

		$accepted = $succeeded + $queued;
		$tai_msg = $created
			? sprintf( "📥 Đã tạo ghi chú mới \"%s\" và nhận %d/%d mục.", $notebook_name, $accepted, $total )
			: sprintf( "📥 Đã nhận %d/%d mục vào ghi chú \"%s\".", $accepted, $total, $notebook_name );
		self::send( $bot, $provider_chat_id, $tai_msg );

		if ( ! empty( $accepted_summary['files_text'] ) ) {
			self::send( $bot, $provider_chat_id, "📂 Tệp đã đưa lên để học:\n" . (string) $accepted_summary['files_text'] );
		}
		if ( ! empty( $share_link['url'] ) ) {
			$link_msg = "🔎 Link theo dõi learning log: " . (string) $share_link['url'];
			if ( ! empty( $share_link['notebook_slug'] ) ) {
				// [2026-07-26 Johnny Chu] PHASE-0.46 W5 R3 — show the stable slug separately so Zalo users can copy and track it.
				$link_msg .= "\nSlug notebook: " . (string) $share_link['notebook_slug'];
			}
			if ( ! empty( $share_link['expires_at'] ) ) {
				$link_msg .= "\n(hết hạn: " . (string) $share_link['expires_at'] . ' UTC)';
			}
			self::send( $bot, $provider_chat_id, $link_msg );
		}

		if ( $succeeded > 0 ) {
			self::send( $bot, $provider_chat_id, sprintf( "🧩 Đã embedding xong %d mục. Twin GPT đang dựng đồ thị tri thức...", $succeeded ) );
		}
		if ( $queued > 0 ) {
			self::send( $bot, $provider_chat_id, sprintf( "⏱️ %d mục đã được đưa vào hàng đợi cron để học nền. Twin GPT sẽ báo khi học xong.", $queued ) );
		}
		if ( ! empty( $res['failed'] ) ) {
			self::send( $bot, $provider_chat_id, sprintf( "⚠️ %d mục không lưu được — bạn gửi lại giúp mình nếu cần.", count( $res['failed'] ) ) );
		}
	}

	/**
	 * Create the canonical Journal row for a finalized Zalo capture.
	 *
	 * @return array|WP_Error
	 */
	private static function create_journal_entry_from_capture( array $session, array $items, string $provider_chat_id ) {
		if ( ! class_exists( 'BizCity_Journal_Database' ) ) {
			return new WP_Error( 'journal_module_not_loaded', 'Journal chưa sẵn sàng.' );
		}
		$user_id = (int) ( $session['user_id'] ?? 0 );
		if ( $user_id <= 0 ) {
			return new WP_Error( 'journal_owner_missing', 'Thiếu người sở hữu nhật ký.' );
		}
		$title = sanitize_text_field( (string) ( $session['title_hint'] ?? '' ) );
		if ( $title === '' ) {
			$title = 'Nhật ký Zalo ' . current_time( 'd/m/Y' );
		}
		$body = trim( (string) ( $session['content'] ?? '' ) );
		$attachment_parts = array();
		$key_parts = array(
			'zalobot',
			(string) ( $session['user_id'] ?? 0 ),
			$provider_chat_id,
			(string) ( $session['started_at'] ?? '' ),
			(string) ( $session['text_message_id'] ?? '' ),
		);
		foreach ( $items as $item ) {
			$attachment = is_array( $item['attachment'] ?? null ) ? $item['attachment'] : array();
			$name = (string) ( $attachment['file_name'] ?? $attachment['name'] ?? $attachment['url'] ?? '' );
			$kind = sanitize_key( (string) ( $item['kind'] ?? 'file' ) );
			if ( $name !== '' ) {
				$attachment_parts[] = '- ' . $kind . ': ' . sanitize_text_field( $name );
			}
			$key_parts[] = (string) ( $item['message_id'] ?? '' ) . ':' . $name . ':' . $kind;
		}
		if ( ! empty( $attachment_parts ) ) {
			$body .= ( $body !== '' ? "\n\n" : '' ) . "## Tệp đính kèm\n" . implode( "\n", $attachment_parts );
		}
		if ( $body === '' ) {
			$body = 'Nội dung được ghi nhận từ Zalo.';
		}
		$key = 'zalo_capture:' . hash( 'sha256', implode( '|', $key_parts ) );
		BizCity_Journal_Database::maybe_install();
		return BizCity_Journal_Database::instance()->create( array(
			'owner_user_id'   => $user_id,
			'workspace_id'    => 'notion',
			'title'           => $title,
			'body'            => $body,
			'status'          => 'captured',
			'source_type'     => 'zalo',
			'source_ref'      => $provider_chat_id,
			'idempotency_key' => $key,
			'metadata'        => array(
				'platform' => 'ZALOBOT',
				'inbound'  => (array) ( $session['inbound_base'] ?? array() ),
			),
		) );
	}

	private static function first_projection_source_id( array $result ): int {
		foreach ( array( 'items', 'queued_jobs' ) as $bucket ) {
			foreach ( (array) ( $result[ $bucket ] ?? array() ) as $row ) {
				if ( is_array( $row ) && ! empty( $row['kg_source_id'] ) ) {
					return (int) $row['kg_source_id'];
				}
				if ( is_array( $row ) && ! empty( $row['source_id'] ) ) {
					return (int) $row['source_id'];
				}
			}
		}
		return 0;
	}

	/**
	 * [2026-07-25 Johnny Chu] PHASE-0.46 W4.6 — summarize accepted non-text
	 * items from capture_batch() result into a user-facing file list.
	 *
	 * @return array{files_text:string,first_source_id:int}
	 */
	private static function build_batch_accept_summary( array $items, array $res ): array {
		$accepted_indexes = array();
		$first_source_id  = 0;
		$attachment_source_id = 0;
		$source_by_index  = array();

		foreach ( (array) ( $res['items'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$idx = isset( $row['index'] ) ? (int) $row['index'] : -1;
			if ( $idx >= 0 ) {
				$accepted_indexes[] = $idx;
				if ( ! empty( $row['source_id'] ) ) {
					$source_by_index[ $idx ] = (int) $row['source_id'];
				}
			}
			if ( $first_source_id <= 0 && ! empty( $row['source_id'] ) ) {
				$first_source_id = (int) $row['source_id'];
			}
		}
		foreach ( (array) ( $res['queued_jobs'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$idx = isset( $row['index'] ) ? (int) $row['index'] : -1;
			if ( $idx >= 0 ) {
				$accepted_indexes[] = $idx;
				if ( ! empty( $row['source_id'] ) ) {
					$source_by_index[ $idx ] = (int) $row['source_id'];
				}
			}
			if ( $first_source_id <= 0 && ! empty( $row['source_id'] ) ) {
				$first_source_id = (int) $row['source_id'];
			}
		}

		$accepted_indexes = array_values( array_unique( array_filter( $accepted_indexes, static function ( $idx ) {
			return is_int( $idx ) && $idx >= 0;
		} ) ) );
		sort( $accepted_indexes );

		$lines = array();
		foreach ( $accepted_indexes as $idx ) {
			$item = isset( $items[ $idx ] ) && is_array( $items[ $idx ] ) ? $items[ $idx ] : array();
			$kind = sanitize_key( (string) ( $item['kind'] ?? '' ) );
			if ( $kind === '' || $kind === 'text' ) {
				continue;
			}
			// [2026-07-26 Johnny Chu] PHASE-0.46 W6 HOTFIX-10 — learning-share
			// link must monitor the accepted ATTACHMENT source (file/image/audio),
			// not the first accepted item overall (which is often index 0 text).
			if ( $attachment_source_id <= 0 && ! empty( $source_by_index[ $idx ] ) ) {
				$attachment_source_id = (int) $source_by_index[ $idx ];
			}
			$lines[] = '- ' . self::capture_item_display_name( $item, $kind );
		}

		return array(
			'files_text'      => empty( $lines ) ? '' : implode( "\n", $lines ),
			'first_source_id' => $attachment_source_id > 0 ? $attachment_source_id : $first_source_id,
		);
	}

	/**
	 * [2026-07-25 Johnny Chu] PHASE-0.46 W4.6 — stable human label per
	 * accepted attachment item for Zalo confirmation reply.
	 */
	private static function capture_item_display_name( array $item, string $kind ): string {
		$att = isset( $item['attachment'] ) && is_array( $item['attachment'] ) ? $item['attachment'] : array();
		$name = trim( (string) ( $att['file_name'] ?? '' ) );
		if ( $name === '' ) {
			$url = trim( (string) ( $att['source_url'] ?? $att['url'] ?? '' ) );
			if ( $url !== '' ) {
				$path = (string) wp_parse_url( $url, PHP_URL_PATH );
				$name = basename( $path );
			}
		}
		if ( $name !== '' ) {
			return sanitize_text_field( $name );
		}
		if ( $kind === 'image' ) {
			return 'Ảnh đính kèm';
		}
		if ( $kind === 'audio' ) {
			return 'Ghi âm đính kèm';
		}
		return 'Tài liệu đính kèm';
	}

	/**
	 * [2026-07-25 Johnny Chu] PHASE-0.46 W4.6 — mint source-scoped learning
	 * log share link for channel reply when adapter/runtime is available.
	 *
	 * @return array{url:string,expires_at:string,notebook_slug:string}
	 */
	private static function create_learning_share_link( int $notebook_id, int $source_id ): array {
		if ( $notebook_id <= 0 || $source_id <= 0 || ! class_exists( 'BizCity_TwinChat_Learning_Share_Adapter' ) ) {
			return array( 'url' => '', 'expires_at' => '', 'notebook_slug' => '' );
		}

		$link = BizCity_TwinChat_Learning_Share_Adapter::instance()->create_link( $notebook_id, $source_id, array(
			'ttl_s' => 30 * DAY_IN_SECONDS,
		) );
		if ( is_wp_error( $link ) || ! is_array( $link ) ) {
			return array( 'url' => '', 'expires_at' => '', 'notebook_slug' => '' );
		}

		return array(
			'url'           => (string) ( $link['url'] ?? '' ),
			'expires_at'    => (string) ( $link['expires_at'] ?? '' ),
			'notebook_slug' => (string) ( $link['notebook_slug'] ?? '' ),
		);
	}

	/**
	 * Send a text message via bot API to the given (raw) provider chat id —
	 * works for both private user ids and group ids.
	 */
	private static function send( object $bot, string $chat_id, string $text ): void {
		// [2026-07-26 Johnny Chu] HOTFIX-DIAG — silent-drop instrumentation:
		// send_message() itself already error_logs a TRACE line, but the two
		// bail conditions below (helper missing / api null) were completely
		// silent. Log them so a missing reply can be told apart from "handle()
		// never reached this call at all".
		if ( ! function_exists( 'bizcity_get_zalo_bot_api' ) ) {
			error_log( '[NotebookBridge] send() bail: bizcity_get_zalo_bot_api() not defined' );
			return;
		}
		$api = bizcity_get_zalo_bot_api( (int) $bot->id );
		if ( ! $api ) {
			error_log( '[NotebookBridge] send() bail: bizcity_get_zalo_bot_api returned falsy for bot_id=' . (int) $bot->id );
			return;
		}
		$GLOBALS['_bizcity_channel_send_trace'] = array( 'trace_id' => uniqid( 'nb_', true ), 'source' => 'notebook_bridge_listener' );
		$api->send_message( $chat_id, $text );
	}

	/**
	 * [2026-07-26 Johnny Chu] PHASE-0.46 W6 HOTFIX-2 — extracted from
	 * `send_unsupported_guidance_with_upload_link()` so the SAME instant
	 * upload-link mint logic can also be reused from the "yes, gửi thêm"
	 * reply (Zalo Bot cannot deliver document attachments to the bot at
	 * all, so the link is the only reliable path for files, not just an
	 * error-recovery fallback). Returns '' when a link cannot be minted
	 * (unlinked user, missing service classes, or provider error).
	 */
	private static function mint_session_upload_link(
		int $bot_id,
		string $provider_chat_id,
		string $zalo_uid,
		array $msg,
		?array $session,
		string $chat_kind,
		string $automation_chat_id,
		string $bridge
	): string {
		if ( ! class_exists( 'BizCity_KG_Channel_Upload_Link_Service' ) || ! class_exists( 'BizCity_Zalobot_Upload_Link_Handler' ) ) {
			return '';
		}
		$wp_user_id = (int) ( $msg['wp_user_id'] ?? 0 );
		if ( $wp_user_id <= 0 && class_exists( 'BizCity_Zalobot_User_Linker' ) ) {
			$wp_user_id = BizCity_Zalobot_User_Linker::resolve_wp_user( $zalo_uid, $bot_id );
		}
		if ( $wp_user_id <= 0 ) {
			return '';
		}
		$link_mode   = ( is_array( $session ) && ! empty( $session['user_id'] ) ) ? 'session' : 'pending';
		$notebook_id = 0;
		if ( $link_mode === 'session' ) {
			$notebook_id = (int) $bridge::instance()->ensure_session_notebook( 'zalobot', $provider_chat_id, array(
				'user_id'   => $wp_user_id,
				'channel'   => 'zalobot',
				'chat_id'   => $provider_chat_id,
				'chat_kind' => $chat_kind,
			) );
		}
		$link = BizCity_KG_Channel_Upload_Link_Service::create( array(
			'channel'            => 'zalobot',
			'chat_key'           => $provider_chat_id,
			'provider_chat_id'   => $provider_chat_id,
			'bot_id'             => $bot_id,
			'wp_user_id'         => $wp_user_id,
			'mode'               => $link_mode,
			'notebook_id'        => $notebook_id,
			'automation_chat_id' => $automation_chat_id,
		) );
		if ( is_wp_error( $link ) ) {
			return '';
		}
		return (string) BizCity_Zalobot_Upload_Link_Handler::build_url( (string) $link['token'] );
	}

	/**
	 * [2026-07-26 Johnny Chu] PHASE-0.46 W6 HOTFIX — canonical guidance for
	 * `message.unsupported.received` with instant upload-link fallback.
	 * [2026-07-26 Johnny Chu] PHASE-0.46 W6 HOTFIX-2 — explicit wording: state
	 * plainly that Zalo Bot does not support document uploads yet, so the
	 * user isn't left guessing why a link showed up instead of a normal ack.
	 */
	private static function send_unsupported_guidance_with_upload_link(
		object $bot,
		int $bot_id,
		string $provider_chat_id,
		string $message_id,
		string $zalo_uid,
		array $msg,
		?array $session,
		string $chat_kind,
		string $automation_chat_id,
		string $bridge
	): void {
		$upload_url = self::mint_session_upload_link( $bot_id, $provider_chat_id, $zalo_uid, $msg, $session, $chat_kind, $automation_chat_id, $bridge );

		if ( $upload_url !== '' ) {
			$text_out = "⚠️ Hiện nay Zalobot chưa hỗ trợ gửi file tài liệu, mình sẽ gửi đường dẫn để bạn tự upload lên cho mình nhé."
				. "\n📎 " . $upload_url;
		} else {
			$text_out = "⚠️ Em nhận được file nhưng chưa lưu được (Zalobot chưa hỗ trợ gửi file tài liệu qua chat)."
				. "\nSếp thử gửi lại dưới dạng ảnh/PDF/ghi âm hoặc gửi lại file trực tiếp từ điện thoại, rồi nhắn @ghichu để em lưu tiếp nhé.";
		}
		self::send( $bot, $provider_chat_id, $text_out );

		if ( class_exists( 'BizCity_Zalo_Bot_Database' ) ) {
			BizCity_Zalo_Bot_Database::instance()->log_event( $bot_id, 'unsupported.guidance.sent', array(
				'chat_id'        => $provider_chat_id,
				'message_id'     => $message_id,
				'upload_link'    => $upload_url !== '' ? 1 : 0,
			), $provider_chat_id, $message_id, '', '' );
		}
	}

	/**
	 * [2026-07-26 Johnny Chu] PHASE-0.46 W5 HOTFIX — one-shot throttle for
	 * unsupported-file notices so webhook retries do not spam user chats.
	 */
	private static function should_skip_unsupported_notice( int $bot_id, string $message_id ): bool {
		if ( $bot_id <= 0 || $message_id === '' ) {
			return false;
		}
		$key = 'bizcity_nb_unsupported_notice_' . md5( $bot_id . '|' . $message_id );
		if ( get_transient( $key ) ) {
			return true;
		}
		set_transient( $key, 1, DAY_IN_SECONDS );
		return false;
	}

	/**
	 * [2026-07-25 Johnny Chu] PHASE-0.46 S3.1 — capture bounded, sanitized
	 * evidence rows for REAL Zalo image webhook payloads.
	 *
	 * Stored in option `bizcity_notebook_live_zalo_image_evidence` as a short
	 * ring buffer (newest first, max 10 rows). Query strings are stripped from
	 * URLs to avoid leaking transient provider tokens in diagnostics output.
	 */
	private static function remember_live_image_payload_evidence( array $msg, array $attachment ): void {
		$provider_chat_id = (string) ( $msg['provider_chat_id'] ?? $msg['from_user_id'] ?? '' );
		$raw_url          = (string) ( $attachment['source_url'] ?? $attachment['url'] ?? '' );
		$safe_url         = self::strip_url_query_fragment( $raw_url );

		$row = array(
			'captured_at'        => current_time( 'mysql' ),
			'event_type'         => (string) ( $msg['event_type'] ?? '' ),
			'attachment_type'    => (string) ( $msg['attachment_type'] ?? 'image' ),
			'message_id'         => (string) ( $msg['message_id'] ?? '' ),
			'chat_kind'          => (string) ( $msg['chat_kind'] ?? 'private' ),
			'provider_chat_hash' => $provider_chat_id !== '' ? substr( hash( 'sha256', $provider_chat_id ), 0, 16 ) : '',
			'image_name'         => (string) ( $msg['image_name'] ?? '' ),
			'mime_hint'          => (string) ( $msg['mime'] ?? $msg['mime_type'] ?? '' ),
			'caption_present'    => trim( (string) ( $msg['caption'] ?? '' ) ) !== '',
			'url_no_query'       => $safe_url,
			'payload_snapshot'   => array(
				'provider_chat_id_present' => $provider_chat_id !== '',
				'sender_user_id_present'   => ! empty( $msg['sender_user_id'] ),
				'from_user_id_present'     => ! empty( $msg['from_user_id'] ),
				'image_url_present'        => ! empty( $msg['image_url'] ),
				'caption_present'          => trim( (string) ( $msg['caption'] ?? '' ) ) !== '',
				'message_text_present'     => trim( (string) ( $msg['message_text_clean'] ?? $msg['text'] ?? '' ) ) !== '',
			),
		);

		$rows = get_option( 'bizcity_notebook_live_zalo_image_evidence', array() );
		if ( ! is_array( $rows ) ) {
			$rows = array();
		}
		array_unshift( $rows, $row );
		if ( count( $rows ) > 10 ) {
			$rows = array_slice( $rows, 0, 10 );
		}
		update_option( 'bizcity_notebook_live_zalo_image_evidence', $rows, false );
	}

	/**
	 * Remove query string + fragment from a URL before storing diagnostics
	 * evidence to reduce risk of leaking transient provider tokens.
	 */
	private static function strip_url_query_fragment( string $url ): string {
		$url = trim( $url );
		if ( $url === '' ) {
			return '';
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return $url;
		}
		$scheme = isset( $parts['scheme'] ) ? (string) $parts['scheme'] . '://' : 'https://';
		$path   = isset( $parts['path'] ) ? (string) $parts['path'] : '';
		$port   = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		return $scheme . (string) $parts['host'] . $port . $path;
	}

	/**
	 * [2026-07-24 Johnny Chu] PHASE-0.46 HARDEN-3 — avoid leaking raw WP_Error
	 * strings directly to end users; return concise actionable Vietnamese copy.
	 */
	private static function format_capture_error( WP_Error $err ): string {
		$code = (string) $err->get_error_code();
		switch ( $code ) {
			case 'notebook_bridge_empty_text':
				return "ℹ️ Chưa có nội dung để lưu.\nVí dụ: @notebook hop sale: hôm nay chốt được 3 hợp đồng.";
			case 'notebook_bridge_no_attachment':
			case 'notebook_bridge_empty_url':
			case 'notebook_bridge_download_failed':
			case 'notebook_bridge_sideload_failed':
				return "❌ Không đọc được tệp đính kèm từ cuộc trò chuyện này.\nBạn gửi lại tệp/ảnh rồi thử lại @notebook giúp mình.";
			case 'file_too_large':
			case 'office_file_too_large':
			case 'av_file_too_large':
				return "❌ Tệp vượt quá giới hạn kích thước cho phép.\nBạn nén/chia nhỏ tệp rồi gửi lại giúp mình.";
			case 'unsupported_ext':
				return "❌ Định dạng tệp này hiện chưa hỗ trợ trong luồng @notebook.\nBạn đổi sang PDF/DOCX/XLSX/TXT/MD hoặc gửi ảnh rồi thử lại.";
			// [2026-07-24 Johnny Chu] PHASE-0.46 W4 S4.4 — AV adapter (audio/video) real error catalog.
			case 'tier_required':
			case 'quota_exceeded_free':
				return "⚠️ Gói hiện tại chưa bật tính năng học từ ghi âm/video hoặc đã hết hạn mức hôm nay.\nBạn nâng cấp gói hoặc thử lại vào ngày mai giúp mình.";
			case 'av_no_speech':
				return "ℹ️ Không nghe rõ nội dung trong file ghi âm.\nBạn thử ghi âm lại rõ hơn rồi gửi lại giúp mình.";
			case 'av_url_unreachable':
			case 'av_not_configured':
			case 'av_client_missing':
				return "❌ Hệ thống xử lý ghi âm/video chưa sẵn sàng.\nBạn thử lại sau ít phút; nếu vẫn lỗi, vui lòng báo quản trị viên.";
			default:
				return "❌ Chưa lưu được vào não Twin GPT lúc này. Bạn thử lại sau ít phút.";
		}
	}

	/**
	 * [2026-07-24 Johnny Chu] PHASE-0.46 W5 S5.4 — deterministic phrase match
	 * (verb + document-noun), NOT an NLU/LLM classifier, matching this
	 * codebase's existing convention for capture/reply trigger detection
	 * (see `classify_more_files_reply()` above) — avoids extra LLM cost on
	 * every single inbound Zalo message just to catch this rare command.
	 */
	private static function is_doc_export_command( string $text ): bool {
		$t = mb_strtolower( trim( $text ) );
		if ( $t === '' ) {
			return false;
		}
		$has_verb = ( strpos( $t, 'xuất' ) !== false || strpos( $t, 'xuat' ) !== false
			|| strpos( $t, 'tạo' ) !== false || strpos( $t, 'tao' ) !== false
			|| strpos( $t, 'soạn' ) !== false || strpos( $t, 'soan' ) !== false );
		if ( ! $has_verb ) {
			return false;
		}
		$has_noun = ( strpos( $t, 'file word' ) !== false || strpos( $t, 'file pdf' ) !== false
			|| strpos( $t, 'văn bản' ) !== false || strpos( $t, 'van ban' ) !== false
			|| strpos( $t, 'tài liệu' ) !== false || strpos( $t, 'tai lieu' ) !== false
			|| strpos( $t, ' word' ) !== false || strpos( $t, ' pdf' ) !== false );
		return $has_noun;
	}

	/**
	 * [2026-07-24 Johnny Chu] PHASE-0.46 W5 S5.4 — R-ERROR-UX-style Vietnamese
	 * copy for `export_day_notebook_as_document()` failures, mirroring
	 * `format_capture_error()`'s style/switch structure.
	 */
	private static function format_export_error( WP_Error $err ): string {
		$code = (string) $err->get_error_code();
		switch ( $code ) {
			case 'notebook_bridge_no_notebook_today':
				return "ℹ️ Hôm nay bạn chưa lưu ghi chú nào để xuất tài liệu.\nGõ \"@notebook <tiêu đề>: <nội dung>\" để lưu ghi chú trước, rồi thử xuất lại.";
			case 'notebook_bridge_notebook_not_found':
				return "❌ Không tìm thấy ghi chú để xuất tài liệu.\nBạn thử lưu ghi chú mới rồi xuất lại giúp mình.";
			case 'bzdoc_bridge_unavailable':
			case 'notebook_bridge_deps_missing':
				return "❌ BizCity Doc Studio chưa sẵn sàng trên site này.\nVui lòng báo quản trị viên giúp mình.";
			case 'bzdoc_bridge_failed':
				return "❌ Chưa tạo được bản nháp tài liệu lúc này.\nBạn thử lại sau ít phút; nếu vẫn lỗi, vui lòng báo quản trị viên.";
			default:
				return "❌ Chưa xuất được tài liệu lúc này.\nBạn thử lại sau ít phút; nếu vẫn lỗi, vui lòng báo quản trị viên.";
		}
	}
}
