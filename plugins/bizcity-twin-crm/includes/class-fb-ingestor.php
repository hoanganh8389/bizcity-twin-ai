<?php
/**
 * BizCity CRM — Facebook Ingestor.
 *
 * Subscribes to the FB bot's existing trigger:
 *   do_action( 'waic_twf_process_flow', $trigger_key, $trigger_data )
 * for keys: bizcity_facebook_message_received, bizcity_facebook_image_received.
 *
 * Pushes inbound messages through the channel adapter normalizer and Repository.
 *
 * @package BizCity_Twin_CRM
 */

defined( 'ABSPATH' ) || exit;

class BizCity_CRM_Facebook_Ingestor {

	private static $instance = null;

	/**
	 * Request-scoped guard. When TRUE, downstream listeners that mirror
	 * outbound messages back into CRM (`on_outbound_sent`, `on_gateway_outbound`)
	 * MUST short-circuit — the CRM REST / AI Replier path already inserted its
	 * own `crm_messages` row before invoking the channel adapter, so any mirror
	 * would be a duplicate.
	 *
	 * @var bool
	 */
	private static $crm_outbound_in_flight = false;

	public static function set_crm_outbound_in_flight( bool $on ): void {
		self::$crm_outbound_in_flight = $on;
	}

	public static function is_crm_outbound_in_flight(): bool {
		return self::$crm_outbound_in_flight;
	}

	/**
	 * Map of `waic_twf_process_flow` trigger keys → CRM channel adapter code.
	 * (Class name kept as `..._Facebook_Ingestor` for BC; it now also dispatches Zalo & friends.)
	 */
	private const KEY_MAP = array(
		'bizcity_facebook_message_received' => 'facebook',
		'bizcity_facebook_image_received'   => 'facebook',
		'bizcity_facebook_comment_received' => 'facebook',
		'bizcity_zalo_message_received'     => 'zalo',
		// [2026-06-21 Johnny Chu] PHASE-0.39 GURU-BIND — ZALO_OA direct webhook path
		// fires waic_twf_process_flow('bizcity_zalo_oa_message_received', $_legacy_payload).
		// Route to 'zalo_oa' adapter so CRM inbox gets channel_type='zalo_oa' which
		// BizCity_CRM_Guru_Resolver uses to resolve UPPER('zalo_oa')='ZALO_OA' binding.
		'bizcity_zalo_oa_message_received'  => 'zalo_oa',
	);

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'waic_twf_process_flow', array( $this, 'on_workflow_trigger' ), 9, 2 );
	}

	/**
	 * @param string $trigger_key e.g. 'bizcity_facebook_message_received', 'bizcity_zalo_message_received'
	 * @param array  $trigger_data adapter-specific shape
	 */
	public function on_workflow_trigger( $trigger_key, $trigger_data = array() ): void {
		// [2026-06-21 Johnny Chu] PHASE-0.39 GURU-BIND — P2: trace entry.
		error_log( '[bizcity-crm-trace] P2 on_workflow_trigger key=' . ( is_string( $trigger_key ) ? $trigger_key : ( 'array[platform=' . ( $trigger_key['platform'] ?? '?' ) . ']' ) ) );

		// New Zalo Bot path: bizcity_gateway_fire_trigger() fires waic_twf_process_flow with
		// an ARRAY trigger (not a string key). Detect via $trigger_key['platform']==='zalo_bot'.
		if ( is_array( $trigger_key ) && ( $trigger_key['platform'] ?? '' ) === 'zalo_bot' ) {
			$this->ingest_zalo_bot_trigger( $trigger_key );
			return;
		}
		if ( ! is_string( $trigger_key ) ) { return; }
		$code = self::KEY_MAP[ $trigger_key ] ?? null;
		// [2026-06-21 Johnny Chu] PHASE-0.39 GURU-BIND — P3: trace adapter code.
		error_log( '[bizcity-crm-trace] P3 KEY_MAP code=' . ( $code ?? 'null(key_not_in_map)' ) );
		if ( ! $code ) { return; }
		if ( ! is_array( $trigger_data ) ) { return; }

		$adapter = BizCity_CRM_Channel_Registry::get( $code );
		// [2026-06-21 Johnny Chu] PHASE-0.39 GURU-BIND — P4: trace adapter.
		error_log( '[bizcity-crm-trace] P4 adapter=' . ( $adapter ? get_class( $adapter ) : 'null(not_registered)' ) );
		if ( ! $adapter ) {
			return;
		}

		try {
			$norm = $adapter->normalize_inbound( $trigger_data );
			error_log( '[bizcity-crm-trace] P5 normalize=' . ( $norm ? 'ok inbox_ref=' . ( $norm['inbox_ref'] ?? '?' ) : 'null' ) );
			if ( ! $norm ) {
				return;
			}
			$this->ingest( $adapter, $norm );
		} catch ( \Throwable $e ) {
			error_log( '[bizcity-crm-trace] P5-EXCEPTION ingest failed (' . $code . '): ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );
		}
	}

	/**
	 * Ingest a Zalo Bot trigger emitted with the new array-shape payload.
	 * Trigger fields (see bizcity-zalo-bot/includes/class-webhook-handler.php):
	 *   { platform=zalo_bot, chat_id=zalobot_{bot}_{uid}, user_id, message_id,
	 *     text, display_name, bot_id, bot_name, source_blog_id, raw, ... }
	 * We map them onto the Zalo adapter's normalize_inbound() shape.
	 * Inbox channel_ref_id is the numeric bot_id so resolve_chat_id() can
	 * later compose `zalobot_{bot}_{uid}` for the gateway.
	 */
	private function ingest_zalo_bot_trigger( array $t ): void {
		$adapter = BizCity_CRM_Channel_Registry::get( 'zalo' );
		if ( ! $adapter ) { return; }
		$bot_id  = (string) ( $t['bot_id'] ?? '' );
		$uid     = (string) ( $t['user_id'] ?? '' );
		$text    = (string) ( $t['text'] ?? '' );
		if ( $bot_id === '' || $uid === '' ) { return; }
		$payload = array(
			'conversation_id' => $bot_id,                                  // → inbox_ref
			'account_name'    => (string) ( $t['bot_name'] ?? $bot_id ),
			'from_user_id'    => $uid,
			'from_user_name'  => (string) ( $t['display_name'] ?? '' ),
			'message_text'    => $text,
			'message_id'      => (string) ( $t['message_id'] ?? '' ),
			'image_url'       => ( ( $t['attachment_type'] ?? '' ) === 'image' ) ? (string) ( $t['attachment_url'] ?? '' ) : '',
			'message_time'    => '',
		);
		try {
			$norm = $adapter->normalize_inbound( $payload );
			if ( $norm ) { $this->ingest( $adapter, $norm ); }
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[bizcity-crm] zalo bot ingest failed: ' . $e->getMessage() );
			}
		}
	}

	/**
	 * Public ingest path — also used by diagnostic page to inject a fixture.
	 *
	 * @return int message_id (0 if dedupe-skipped or invalid)
	 */
	public function ingest( BizCity_CRM_Channel_Adapter $adapter, array $norm ): int {
		// [2026-06-21 Johnny Chu] PHASE-0.39 GURU-BIND — P6: trace ingest entry.
		error_log( '[bizcity-crm-trace] P6 ingest code=' . $adapter->code() . ' inbox_ref=' . ( $norm['inbox_ref'] ?? '?' ) . ' source_id=' . ( $norm['source_id'] ?? '?' ) );

		// 1. Inbox.
		$inbox_id = BizCity_CRM_Repository::upsert_inbox(
			$adapter->code(),
			(string) $norm['inbox_ref'],
			array( 'name' => (string) ( $norm['inbox_name'] ?? '' ) )
		);
		error_log( '[bizcity-crm-trace] P7 upsert_inbox=' . (int) $inbox_id );
		if ( ! $inbox_id ) { return 0; }

		// 2. Contact + contact_inbox.
		$ids = BizCity_CRM_Repository::upsert_contact( $inbox_id, (string) $norm['source_id'], array(
			'name'       => (string) ( $norm['contact_name'] ?? '' ),
			'avatar_url' => $norm['contact_avatar'] ?? null,
		) );
		error_log( '[bizcity-crm-trace] P8 upsert_contact contact_id=' . ( $ids['contact_id'] ?? 0 ) . ' ci=' . ( $ids['contact_inbox_id'] ?? 0 ) );
		if ( empty( $ids['contact_inbox_id'] ) ) { return 0; }

		// [2026-06-13 Johnny Chu] PHASE-CG-CF7 — auto-create pipeline lead for Facebook / Zalo channel
		if ( ! empty( $ids['contact_id'] ) ) {
			$channel_src = ( $adapter->code() === 'facebook' ) ? 'facebook_page' : 'zalo_oa';
			self::maybe_create_pipeline_lead(
				(int) $ids['contact_id'],
				$channel_src,
				(string) ( $norm['inbox_ref'] ?? '' ),
				(string) ( $norm['contact_name'] ?? '' )
			);
		}

		// 3. Conversation.
		$conv_id = BizCity_CRM_Repository::open_or_get_conversation(
			$inbox_id,
			(int) $ids['contact_inbox_id']
		);
		error_log( '[bizcity-crm-trace] P9 conv_id=' . (int) $conv_id );
		if ( ! $conv_id ) { return 0; }

		// 4. Message.
		$msg_id = BizCity_CRM_Repository::insert_message( array(
			'conversation_id'    => $conv_id,
			'inbox_id'           => $inbox_id,
			'external_source_id' => (string) ( $norm['external_source_id'] ?? '' ),
			'content'            => (string) ( $norm['content'] ?? '' ),
			'content_type'       => (string) ( $norm['content_type'] ?? 'text' ),
			'message_type'       => 'incoming',
			'sender_type'        => 'contact',
			'sender_id'          => (int) $ids['contact_id'],
			'attachments'        => $norm['attachments'] ?? array(),
			'created_at'         => isset( $norm['received_at'] ) ? (string) $norm['received_at'] : current_time( 'mysql' ),
		) );
		// [2026-06-21 Johnny Chu] PHASE-0.39 GURU-BIND — P10: trace msg insert result.
		error_log( '[bizcity-crm-trace] P10 insert_message msg_id=' . (int) $msg_id . ' conv=' . (int) $conv_id . ' inbox=' . (int) $inbox_id . ' code=' . $adapter->code() );

		return $msg_id;
	}

	/* ============================================================
	 * OUTBOUND BRIDGES (PHASE 0.34)
	 * ============================================================ */

	/**
	 * Subscriber for `bizcity_facebook_message_sent` (legacy fb_messenger_reply()).
	 * Payload: { page_id, user_id, message, message_id, timestamp, platform, contact_name, sent_ok, http_code, error, ... }
	 *
	 * Trên thành công → mirror outbound bình thường (sender_type=agent_bot).
	 * Trên FAIL → vẫn insert vào inbox như SYSTEM note (sender_type=system) với
	 * marker `⚠️ FB SEND FAILED [http=… code=… err=…]` để admin nhìn thấy
	 * trực tiếp trong chat panel CRM thay vì phải lục error_log.
	 */
	public static function on_outbound_sent( $payload ): void {
		if ( ! is_array( $payload ) ) { return; }

		$sent_ok = isset( $payload['sent_ok'] ) ? (bool) $payload['sent_ok'] : true;

		// Success path: skip nếu CRM tự originate (đã insert row riêng).
		if ( $sent_ok && self::$crm_outbound_in_flight ) { return; }

		$page_id = (string) ( $payload['page_id'] ?? '' );
		$psid    = (string) ( $payload['user_id'] ?? '' );
		$text    = (string) ( $payload['message'] ?? '' );
		if ( $page_id === '' || $psid === '' || $text === '' ) { return; }

		// FAIL path: build system error note (always log, kể cả khi CRM-originated
		// — admin cần biết tin nhắn của họ bị FB chặn).
		if ( ! $sent_ok ) {
			$http_code = (int) ( $payload['http_code'] ?? 0 );
			$err_raw   = (string) ( $payload['error'] ?? '' );
			$reason    = self::classify_fb_error( $http_code, $err_raw );

			$banner   = sprintf(
				"⚠️ FB SEND FAILED — %s\n[http=%d] %s\n— Nội dung định gửi:\n%s",
				$reason,
				$http_code,
				$err_raw !== '' ? $err_raw : '(no error message)',
				mb_strimwidth( $text, 0, 500, '…', 'UTF-8' )
			);

			try {
				self::instance()->ingest_outbound( 'facebook', array(
					'inbox_ref'    => $page_id,
					'inbox_name'   => 'FB Page ' . $page_id,
					'source_id'    => $psid,
					'contact_name' => (string) ( $payload['contact_name'] ?? '' ),
					'content'      => $banner,
					'content_type' => 'text',
					'received_at'  => self::ts_to_mysql( $payload['timestamp'] ?? null ),
					'sender_type'  => 'system',
					'status'       => 'failed',
					'ai_metadata'  => array(
						'fb_send_failure' => array(
							'http_code' => $http_code,
							'error'     => $err_raw,
							'reason'    => $reason,
							'page_id'   => $page_id,
							'psid'      => $psid,
							'text'      => $text,
							'timestamp' => (int) ( $payload['timestamp'] ?? 0 ),
						),
					),
				) );
			} catch ( \Throwable $e ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( '[bizcity-crm] FB outbound FAIL mirror error: ' . $e->getMessage() );
				}
			}
			return;
		}

		try {
			self::instance()->ingest_outbound( 'facebook', array(
				'inbox_ref'          => $page_id,
				'inbox_name'         => 'FB Page ' . $page_id,
				'source_id'          => $psid,
				'contact_name'       => (string) ( $payload['contact_name'] ?? '' ),
				'content'            => $text,
				'content_type'       => 'text',
				'external_source_id' => (string) ( $payload['message_id'] ?? '' ),
				'received_at'        => self::ts_to_mysql( $payload['timestamp'] ?? null ),
				'sender_type'        => 'agent_bot',
			) );
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[bizcity-crm] FB outbound mirror failed: ' . $e->getMessage() );
			}
		}
	}

	/**
	 * Phân loại lỗi FB Graph API thành label dễ đọc cho admin.
	 *
	 * Bao gồm chính sách 24h (#10), token revoked (#190), rate limit (#613),
	 * etc. Mục đích: hiển thị 1 dòng trong CRM Inbox để admin hiểu ngay.
	 */
	private static function classify_fb_error( int $http_code, string $err ): string {
		$lower = mb_strtolower( $err, 'UTF-8' );

		// FB 24h messaging window policy.
		if ( strpos( $err, '(#10)' ) !== false
			|| strpos( $lower, 'outside the allowed window' ) !== false
			|| strpos( $lower, 'ngoài khoảng thời gian cho phép' ) !== false ) {
			return 'Quá hạn 24h — chính sách Messenger (#10). User cần nhắn lại trước, hoặc dùng MESSAGE_TAG/One-Time Notification.';
		}
		if ( strpos( $err, '(#190)' ) !== false
			|| strpos( $lower, 'access token' ) !== false ) {
			return 'Token Page hết hạn / bị thu hồi (#190). Cần kết nối lại FB Page.';
		}
		if ( strpos( $err, '(#613)' ) !== false
			|| strpos( $lower, 'rate limit' ) !== false ) {
			return 'Vượt giới hạn rate (#613). Đợi và thử lại.';
		}
		if ( strpos( $err, '(#100)' ) !== false ) {
			return 'Tham số không hợp lệ (#100). Có thể PSID/page_id sai.';
		}
		if ( strpos( $err, '(#200)' ) !== false || strpos( $err, '(#230)' ) !== false ) {
			return 'Thiếu quyền gửi tin (#200/#230). Cần cấp lại permission pages_messaging.';
		}
		if ( $http_code === 0 ) {
			return 'Network/transport error (không gọi được Graph API).';
		}
		return 'Lỗi không phân loại được (xem chi tiết bên dưới).';
	}

	/**
	 * Subscriber for `bizcity_channel_outbound_logged` (Channel Gateway sender).
	 * Payload: { chat_id, platform, message, type, extra, sent, error }
	 * Currently maps FB_MESS only (chat_id format `fb_<page>_<psid>`).
	 */
	public static function on_gateway_outbound( $payload ): void {
		if ( ! is_array( $payload ) ) { return; }
		if ( empty( $payload['sent'] ) ) { return; }
		// Dedupe #1: when CRM is the originator, the REST / AI Replier path
		// already inserted its own crm_messages row BEFORE invoking the channel
		// adapter (which then fires this hook through Gateway_Sender). Mirroring
		// here would create a duplicate `crm_messages` row → double bubble in
		// the CRM Inbox UI for Zalo Bot / future Gateway-based adapters.
		if ( self::$crm_outbound_in_flight ) { return; }
		// Dedupe #2: legacy explicit source tag (kept for non-CRM callers that
		// still pass extra.source manually, e.g. external integrations).
		$extra_source = (string) ( $payload['extra']['source'] ?? '' );
		if ( in_array( $extra_source, array( 'crm-adapter', 'crm-rest', 'crm-ai', 'crm-ai-replier' ), true ) ) {
			return;
		}
		$platform = strtoupper( (string) ( $payload['platform'] ?? '' ) );
		$chat_id  = (string) ( $payload['chat_id'] ?? '' );
		$text     = (string) ( $payload['message'] ?? '' );
		if ( $text === '' ) { return; }

		$adapter_code = '';
		$inbox_ref    = '';
		$source_id    = '';
		if ( $platform === 'FB_MESS' && strpos( $chat_id, 'fb_' ) === 0 ) {
			$parts = explode( '_', $chat_id, 3 );
			if ( count( $parts ) === 3 ) {
				[ , $inbox_ref, $source_id ] = $parts;
				$adapter_code = 'facebook';
			}
		} elseif ( $platform === 'ZALO_BOT' && strpos( $chat_id, 'zalobot_' ) === 0 ) {
			$parts = explode( '_', $chat_id, 3 );
			if ( count( $parts ) === 3 ) {
				[ , $inbox_ref, $source_id ] = $parts;
				$adapter_code = 'zalo';
			}
		}
		if ( $adapter_code === '' || $inbox_ref === '' || $source_id === '' ) { return; }

		try {
			self::instance()->ingest_outbound( $adapter_code, array(
				'inbox_ref'          => $inbox_ref,
				'inbox_name'         => $adapter_code === 'zalo' ? 'Zalo Bot ' . $inbox_ref : 'FB Page ' . $inbox_ref,
				'source_id'          => $source_id,
				'contact_name'       => '',
				'content'            => $text,
				'content_type'       => 'text',
				'external_source_id' => (string) ( $payload['extra']['mid'] ?? ( $payload['extra']['message_id'] ?? '' ) ),
				'received_at'        => current_time( 'mysql' ),
				'sender_type'        => 'agent_bot',
			) );
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[bizcity-crm] gateway outbound mirror failed: ' . $e->getMessage() );
			}
		}
	}

	/**
	 * Shared write path for outbound messages (mirrors ingest() but for outgoing).
	 *
	 * @return int message_id (0 if invalid / dedupe-skipped)
	 */
	public function ingest_outbound( string $adapter_code, array $norm ): int {
		// 1. Inbox (auto-create on the fly).
		$inbox_id = BizCity_CRM_Repository::upsert_inbox(
			$adapter_code,
			(string) $norm['inbox_ref'],
			array( 'name' => (string) ( $norm['inbox_name'] ?? '' ) )
		);
		if ( ! $inbox_id ) { return 0; }

		// 2. Contact (do not overwrite name if blank — keep existing).
		$contact_attrs = array();
		if ( ! empty( $norm['contact_name'] ) ) {
			$contact_attrs['name'] = (string) $norm['contact_name'];
		}
		$ids = BizCity_CRM_Repository::upsert_contact( $inbox_id, (string) $norm['source_id'], $contact_attrs );
		if ( empty( $ids['contact_inbox_id'] ) ) { return 0; }

		// 3. Conversation.
		$conv_id = BizCity_CRM_Repository::open_or_get_conversation(
			$inbox_id,
			(int) $ids['contact_inbox_id']
		);
		if ( ! $conv_id ) { return 0; }

		// Auto-stamp responder context from Stamper (so AI replies show G{character} pill).
		$stamp_kind = $norm['responder_kind'] ?? null;
		$stamp_cid  = isset( $norm['character_id'] ) ? (int) $norm['character_id'] : null;
		$stamp_uid  = isset( $norm['responder_user_id'] ) ? (int) $norm['responder_user_id'] : null;
		if ( ( $stamp_kind === null || $stamp_cid === null ) && class_exists( 'BizCity_Responder_Stamper' ) ) {
			$cur = BizCity_Responder_Stamper::current();
			if ( is_array( $cur ) ) {
				if ( $stamp_kind === null && ! empty( $cur['kind'] ) )         { $stamp_kind = (string) $cur['kind']; }
				if ( $stamp_cid  === null && ! empty( $cur['character_id'] ) ) { $stamp_cid  = (int) $cur['character_id']; }
				if ( $stamp_uid  === null && ! empty( $cur['user_id'] ) )      { $stamp_uid  = (int) $cur['user_id']; }
			}
		}
		if ( $stamp_kind === null ) { $stamp_kind = 'auto'; }

		// 4. Outbound message.
		$msg_id = BizCity_CRM_Repository::insert_message( array(
			'conversation_id'    => $conv_id,
			'inbox_id'           => $inbox_id,
			'external_source_id' => (string) ( $norm['external_source_id'] ?? '' ),
			'content'            => (string) ( $norm['content'] ?? '' ),
			'content_type'       => (string) ( $norm['content_type'] ?? 'text' ),
			'message_type'       => 'outgoing',
			'sender_type'        => (string) ( $norm['sender_type'] ?? 'agent_bot' ),
			'sender_id'          => 0,
			'status'             => (string) ( $norm['status'] ?? 'sent' ),
			'ai_metadata'        => isset( $norm['ai_metadata'] ) && is_array( $norm['ai_metadata'] ) ? $norm['ai_metadata'] : null,
			'responder_kind'     => $stamp_kind,
			'responder_user_id'  => $stamp_uid,
			'character_id'       => $stamp_cid,
			'attachments'        => $norm['attachments'] ?? array(),
			'created_at'         => isset( $norm['received_at'] ) ? (string) $norm['received_at'] : current_time( 'mysql' ),
		) );

		return (int) $msg_id;
	}

	private static function ts_to_mysql( $ts_ms_or_null ): string {
		$ts_ms = (int) ( $ts_ms_or_null ?? 0 );
		if ( $ts_ms <= 0 ) { return current_time( 'mysql' ); }
		$ts_s = (int) ( $ts_ms / 1000 );
		return function_exists( 'wp_date' ) ? wp_date( 'Y-m-d H:i:s', $ts_s ) : date( 'Y-m-d H:i:s', $ts_s );
	}

	/**
	 * Auto-create a pipeline lead for Facebook / Zalo channel when a contact is first seen.
	 * Idempotent: skips if a lead with matching (contact_id, source) already exists.
	 * Runs on every inbound message but the idempotency SELECT keeps overhead minimal.
	 *
	 * [2026-06-13 Johnny Chu] PHASE-CG-CF7 — Facebook / Zalo pipeline lead auto-creation
	 *
	 * @param int    $contact_id   CRM contact ID
	 * @param string $source       'facebook_page' or 'zalo_oa'
	 * @param string $inbox_ref    Page ID (FB) or OA ID (Zalo) from the inbox
	 * @param string $contact_name Display name of the contact
	 */
	private static function maybe_create_pipeline_lead( int $contact_id, string $source, string $inbox_ref, string $contact_name ): void {
		if ( ! class_exists( 'BizCity_CRM_DB_Installer_V2' ) ) {
			return;
		}
		global $wpdb;
		$leads_t = BizCity_CRM_DB_Installer_V2::tbl_crm_leads();
		if ( ! BizCity_CRM_DB_Installer_V2::table_exists( $leads_t ) ) {
			return;
		}
		// Idempotency: one lead per (contact, source).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM `{$leads_t}` WHERE contact_id = %d AND source = %s AND deleted_at IS NULL LIMIT 1",
			$contact_id,
			$source
		) );
		if ( $exists ) {
			return;
		}
		// source_ref unique per (source, contact) so UNIQUE(source, source_ref) is safe.
		$source_ref = 'contact:' . $contact_id . ( $inbox_ref !== '' ? ':inbox:' . $inbox_ref : '' );
		// Split contact_name into first / last.
		$name  = trim( $contact_name );
		$parts = $name !== '' ? explode( ' ', $name, 2 ) : array( '', '' );
		$first = sanitize_text_field( $parts[0] ?? '' );
		$last  = sanitize_text_field( $parts[1] ?? '' );
		$now   = current_time( 'mysql' );
		$label = ( $source === 'facebook_page' ) ? 'Facebook Page' : 'Zalo OA';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$leads_t,
			array(
				'first_name'  => $first,
				'last_name'   => $last,
				'source'      => $source,
				'source_ref'  => sanitize_text_field( $source_ref ),
				'status'      => 'new',
				'contact_id'  => $contact_id,
				'notes'       => 'Lead tự động từ ' . $label . ( $inbox_ref !== '' ? ': ' . sanitize_text_field( $inbox_ref ) : '' ),
				'custom_json' => wp_json_encode( array(
					'origin'    => $source,
					'inbox_ref' => $inbox_ref,
				) ),
				'created_at'  => $now,
				'updated_at'  => $now,
			)
		);
	}
}
