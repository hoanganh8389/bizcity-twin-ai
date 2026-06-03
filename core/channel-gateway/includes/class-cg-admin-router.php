<?php
/**
 * Channel Gateway — CG Admin Router
 *
 * Listens to inbound Zalo messages from admin users (linked + `bizcity_channel_admin`
 * capability), classifies intent using BizCity_CMD_Classifier, creates a draft
 * scheduler event, and replies with a confirm preview.
 *
 * Also handles "đăng"/"hủy" quick-reply confirmation (transient-based HIL loop).
 *
 * REST endpoint:
 *   POST bizcity-channel/v1/tasks/{id}/confirm   { action: 'confirm'|'cancel' }
 *   → flip draft → active (confirm) or cancelled (cancel) via web admin.
 *
 * Confirm token transient:
 *   Key: bizcity_cg_confirm_{wp_user_id}
 *   Value: JSON { event_id, token, intent_type }
 *   TTL: 15 min (900 seconds)
 *
 * Capability bootstrap:
 *   `bizcity_channel_admin` is auto-granted to any user with `manage_options`.
 *   To revoke for specific admins: remove their `manage_options` OR manually set
 *   the cap to false on their user record.
 *
 * Hook entry: `bizcity_zalo_message_received` (fired by class-webhook-handler.php).
 * Message data keys used: bot_id, from_user_id, message_text.
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway
 * @since 1.5.0
 */

defined( 'ABSPATH' ) || exit;

class BizCity_CG_Admin_Router {

	const NS               = 'bizcity-channel/v1';
	const CONFIRM_TTL      = 900; // 15 minutes

	/** @var self|null */
	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Bootstrap: hook into Zalo inbound + REST + capability.
	 */
	public static function init(): void {
		$inst = self::instance();

		// Capability bootstrap: auto-grant bizcity_channel_admin to manage_options users.
		add_filter( 'user_has_cap', array( $inst, 'auto_grant_cap' ), 10, 3 );

		// Listen to every inbound Zalo message.
		add_action( 'bizcity_zalo_message_received', array( $inst, 'on_message' ), 5, 1 );

		// REST: web admin confirm/cancel.
		add_action( 'rest_api_init', array( $inst, 'register_rest' ) );
	}

	/* ═══════════════════════════════════════════════════════════
	 *  Capability bootstrap
	 * ═══════════════════════════════════════════════════════════ */

	/**
	 * Automatically grant bizcity_channel_admin to users with manage_options.
	 * Hooks: user_has_cap.
	 */
	public function auto_grant_cap( array $caps, array $cap_names, array $args ): array {
		if (
			in_array( 'bizcity_channel_admin', $cap_names, true )
			&& ! empty( $caps['manage_options'] )
		) {
			$caps['bizcity_channel_admin'] = true;
		}
		return $caps;
	}

	/* ═══════════════════════════════════════════════════════════
	 *  Inbound message handler
	 * ═══════════════════════════════════════════════════════════ */

	/**
	 * Main entry point for every Zalo message.
	 * Returns early (hands off to normal pipeline) for non-admin users and CHAT intent.
	 *
	 * @param array $msg Message data from bizcity_zalo_message_received.
	 */
	public function on_message( array $msg ): void {
		$bot_id       = (int) ( $msg['bot_id'] ?? 0 );
		$zalo_user_id = (string) ( $msg['from_user_id'] ?? '' );
		$text         = (string) ( $msg['message_text'] ?? '' );

		if ( ! $bot_id || ! $zalo_user_id || $text === '' ) {
			return;
		}

		// Only handle text messages (not follow/unfollow events).
		$event_name = (string) ( $msg['event_name'] ?? '' );
		if ( $event_name !== '' && $event_name !== 'follow_oa' && ! str_contains( $event_name, 'message' ) ) {
			return;
		}

		// 1. Resolve linked WP user.
		if ( ! class_exists( 'BizCity_Zalobot_User_Linker' ) ) {
			return;
		}
		$wp_user_id = BizCity_Zalobot_User_Linker::resolve_wp_user( $zalo_user_id, $bot_id );
		if ( $wp_user_id <= 0 ) {
			return; // Not linked — let normal pipeline handle.
		}

		// 2. Capability check.
		$user = get_userdata( $wp_user_id );
		if ( ! $user || ! user_can( $user, 'bizcity_channel_admin' ) ) {
			return;
		}

		// Resolve OA ID for outbound reply.
		$oa_id = $this->resolve_oa_id( $bot_id );
		if ( $oa_id === '' ) {
			return; // Can't reply — don't block the pipeline.
		}
		$reply_chat_id = 'zalobot_' . $oa_id . '_' . $zalo_user_id;

		// 3. Check if this is a confirm/cancel reply for a pending draft.
		if ( $this->maybe_handle_confirm( $wp_user_id, $text, $reply_chat_id ) ) {
			return; // Confirm handled — stop propagation.
		}

		// 4. Classify intent.
		if ( ! class_exists( 'BizCity_CMD_Classifier' ) ) {
			return;
		}
		$intent = BizCity_CMD_Classifier::classify( $text );
		if ( $intent['type'] === 'CHAT' ) {
			return; // Normal conversation — hand off.
		}

		// 5. Handle special non-scheduling intents inline.
		if ( $intent['type'] === 'list_tasks' ) {
			$this->reply_task_list( $wp_user_id, $reply_chat_id );
			return;
		}

		if ( $intent['type'] === 'cancel_task' ) {
			$this->handle_cancel_task( $intent['task_id'], $wp_user_id, $reply_chat_id );
			return;
		}

		// 6. Create draft scheduler event.
		$event_id = $this->create_draft_event( $wp_user_id, $bot_id, $zalo_user_id, $intent, $text );
		if ( is_wp_error( $event_id ) ) {
			bizcity_channel_send( $reply_chat_id, '⚠️ Không thể tạo task: ' . $event_id->get_error_message() );
			return;
		}

		// 7. Reply preview + confirm prompt.
		$this->reply_preview( $event_id, $intent, $wp_user_id, $reply_chat_id );
	}

	/* ═══════════════════════════════════════════════════════════
	 *  Confirm / Cancel reply parser
	 * ═══════════════════════════════════════════════════════════ */

	/**
	 * Check if message is a confirm/cancel quick-reply for a pending draft.
	 * Returns true if handled (caller should stop propagation).
	 */
	protected function maybe_handle_confirm( int $wp_user_id, string $text, string $reply_chat_id ): bool {
		$lower = mb_strtolower( trim( $text ), 'UTF-8' );

		$is_confirm = in_array( $lower, [ 'đăng', 'ok', 'confirm', 'yes', 'đồng ý' ], true );
		$is_cancel  = in_array( $lower, [ 'hủy', 'huỷ', 'cancel', 'không', 'no' ], true );

		if ( ! $is_confirm && ! $is_cancel ) {
			return false;
		}

		$pending = $this->get_pending_confirm( $wp_user_id );
		if ( ! $pending ) {
			// No pending task — let normal pipeline handle the keyword.
			return false;
		}

		$event_id = (int) ( $pending['event_id'] ?? 0 );

		if ( $is_cancel ) {
			$this->transition_event( $event_id, 'cancelled' );
			$this->clear_pending_confirm( $wp_user_id );
			bizcity_channel_send( $reply_chat_id, '⛔ Đã hủy task #' . $event_id . '.' );
			return true;
		}

		// Confirm → flip to active so cron picks it up on next tick.
		$ok = $this->transition_event( $event_id, 'active' );
		$this->clear_pending_confirm( $wp_user_id );

		if ( is_wp_error( $ok ) ) {
			bizcity_channel_send( $reply_chat_id, '⚠️ Lỗi xác nhận task #' . $event_id . ': ' . $ok->get_error_message() );
		} else {
			bizcity_channel_send( $reply_chat_id, '✅ Đã lên lịch task #' . $event_id . '. Hệ thống sẽ xử lý trong vài phút.' );
		}
		return true;
	}

	/* ═══════════════════════════════════════════════════════════
	 *  Draft event creation
	 * ═══════════════════════════════════════════════════════════ */

	/**
	 * Create a draft scheduler event for the given intent.
	 *
	 * @return int|WP_Error Event ID on success.
	 */
	protected function create_draft_event(
		int    $wp_user_id,
		int    $bot_id,
		string $zalo_user_id,
		array  $intent,
		string $raw_text
	) {
		if ( ! class_exists( 'BizCity_Scheduler_Manager' ) ) {
			return new WP_Error( 'no_scheduler', 'BizCity_Scheduler_Manager not available.' );
		}

		$mgr     = BizCity_Scheduler_Manager::instance();
		$type    = $intent['type'];
		$topic   = $intent['topic'];

		// Default: fire 1 minute from now (admin can adjust before confirming).
		$start_at = gmdate( 'Y-m-d H:i:s', time() + 60 );

		// Build metadata based on intent type.
		$metadata = [
			'origin_zalo_bot_id'  => $bot_id,
			'origin_zalo_user_id' => $zalo_user_id,
			'origin_input_text'   => $raw_text,
		];

		switch ( $type ) {
			case 'web_post':
				$metadata['web_title']          = $topic;
				$metadata['web_content']        = '';   // Draft Builder would fill this (Phase 2.3).
				$metadata['web_publish_status'] = 'pending';
				break;

			case 'fb_post':
				$metadata['fb_content']        = $topic;
				$metadata['fb_publish_status'] = 'pending';
				break;

			case 'reminder_zalo':
				$metadata['zalo_bot_id']         = $bot_id;
				$metadata['zalo_user_id']        = $zalo_user_id;
				$metadata['zalo_text']           = $topic ?: $raw_text;
				$metadata['zalo_reminder_status']= 'pending';
				// Parse 'when' into start_at (best-effort — keeps raw string for display).
				if ( $intent['when'] !== '' ) {
					$metadata['zalo_when_raw'] = $intent['when'];
					$parsed = strtotime( $intent['when'] );
					if ( $parsed && $parsed > time() ) {
						$start_at = gmdate( 'Y-m-d H:i:s', $parsed );
					}
				}
				break;
		}

		$event_id = $mgr->create_event( [
			'user_id'    => $wp_user_id,
			'title'      => $this->make_title( $type, $topic, $raw_text ),
			'start_at'   => $start_at,
			'status'     => 'draft',
			'event_type' => $type,
			'source'     => 'channel_gateway',
			'metadata'   => $metadata,
		] );

		return $event_id;
	}

	/* ═══════════════════════════════════════════════════════════
	 *  Reply helpers
	 * ═══════════════════════════════════════════════════════════ */

	/**
	 * Send a preview message + store confirm token.
	 */
	protected function reply_preview( int $event_id, array $intent, int $wp_user_id, string $reply_chat_id ): void {
		$type  = $intent['type'];
		$topic = $intent['topic'];

		$type_labels = [
			'web_post'      => '📝 Đăng bài web',
			'fb_post'       => '📘 Đăng Facebook',
			'reminder_zalo' => '🔔 Nhắc nhở Zalo',
		];
		$label = $type_labels[ $type ] ?? $type;

		$lines = [
			"{$label} — Task #{$event_id}",
			'',
			"📌 Chủ đề: {$topic}",
			'',
			'✅ Reply "đăng" để xác nhận · ⛔ "hủy" để bỏ',
			'⏰ Hết hạn sau 15 phút.',
		];

		if ( $type === 'reminder_zalo' && $intent['when'] !== '' ) {
			$lines[2] = "📌 Nhắc: {$topic}";
			array_splice( $lines, 3, 0, [ "🕐 Thời gian: {$intent['when']}" ] );
		}

		$this->store_pending_confirm( $wp_user_id, $event_id, $type );
		bizcity_channel_send( $reply_chat_id, implode( "\n", $lines ) );
	}

	/**
	 * Reply inline task list (last 5 active/draft for this user).
	 */
	protected function reply_task_list( int $wp_user_id, string $reply_chat_id ): void {
		if ( ! class_exists( 'BizCity_Scheduler_Manager' ) ) {
			bizcity_channel_send( $reply_chat_id, '⚠️ Không thể lấy danh sách: scheduler không khả dụng.' );
			return;
		}
		global $wpdb;
		$table = BizCity_Scheduler_Manager::instance()->get_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, title, event_type, status, start_at
			   FROM {$table}
			  WHERE user_id = %d AND status IN ('active','draft')
			  ORDER BY start_at ASC
			  LIMIT 5",
			$wp_user_id
		) );

		if ( empty( $rows ) ) {
			bizcity_channel_send( $reply_chat_id, '📋 Không có task nào đang hoạt động.' );
			return;
		}

		$lines = [ '📋 Danh sách task gần nhất:' ];
		foreach ( $rows as $r ) {
			$status_emoji = $r->status === 'draft' ? '🔵' : '🟢';
			$date         = substr( (string) $r->start_at, 0, 16 );
			$lines[]      = "{$status_emoji} #{$r->id} [{$r->event_type}] {$r->title} — {$date}";
		}
		bizcity_channel_send( $reply_chat_id, implode( "\n", $lines ) );
	}

	/**
	 * Handle inline cancel-by-ID command.
	 */
	protected function handle_cancel_task( int $task_id, int $wp_user_id, string $reply_chat_id ): void {
		if ( $task_id <= 0 ) {
			bizcity_channel_send( $reply_chat_id, '⚠️ ID task không hợp lệ.' );
			return;
		}
		$ok = $this->transition_event( $task_id, 'cancelled', $wp_user_id );
		if ( is_wp_error( $ok ) ) {
			bizcity_channel_send( $reply_chat_id, '⚠️ Hủy task #' . $task_id . ' thất bại: ' . $ok->get_error_message() );
		} else {
			bizcity_channel_send( $reply_chat_id, '⛔ Đã hủy task #' . $task_id . '.' );
		}
	}

	/* ═══════════════════════════════════════════════════════════
	 *  REST endpoint (bizcity-channel/v1/tasks/{id}/confirm)
	 * ═══════════════════════════════════════════════════════════ */

	public function register_rest(): void {
		register_rest_route( self::NS, '/tasks/(?P<id>\d+)/confirm', [
			'methods'             => 'POST',
			'callback'            => array( $this, 'rest_confirm_task' ),
			'permission_callback' => array( $this, 'rest_permission' ),
			'args'                => [
				'id'     => [ 'required' => true, 'type' => 'integer', 'minimum' => 1 ],
				'action' => [
					'required' => true,
					'type'     => 'string',
					'enum'     => [ 'confirm', 'cancel' ],
				],
			],
		] );
	}

	public function rest_permission(): bool {
		return current_user_can( 'bizcity_channel_admin' );
	}

	/**
	 * POST /bizcity-channel/v1/tasks/{id}/confirm
	 * Body: { action: 'confirm' | 'cancel' }
	 */
	public function rest_confirm_task( WP_REST_Request $request ): WP_REST_Response {
		$event_id = (int) $request->get_param( 'id' );
		$action   = (string) $request->get_param( 'action' );

		$new_status = $action === 'confirm' ? 'active' : 'cancelled';
		$ok         = $this->transition_event( $event_id, $new_status, get_current_user_id() );

		if ( is_wp_error( $ok ) ) {
			return new WP_REST_Response(
				[ 'success' => false, 'message' => $ok->get_error_message() ],
				400
			);
		}

		return new WP_REST_Response( [
			'success'  => true,
			'event_id' => $event_id,
			'status'   => $new_status,
		], 200 );
	}

	/* ═══════════════════════════════════════════════════════════
	 *  Helpers
	 * ═══════════════════════════════════════════════════════════ */

	/**
	 * Transition a scheduler event to a new status (ownership-aware).
	 *
	 * @param int      $event_id
	 * @param string   $new_status  active|cancelled|draft|done
	 * @param int|null $user_id     null = admin bypass (cron/webhook context).
	 * @return true|WP_Error
	 */
	protected function transition_event( int $event_id, string $new_status, ?int $user_id = null ) {
		if ( ! class_exists( 'BizCity_Scheduler_Manager' ) ) {
			return new WP_Error( 'no_scheduler', 'Scheduler not available.' );
		}
		return BizCity_Scheduler_Manager::instance()->update_event(
			$event_id,
			[ 'status' => $new_status ],
			$user_id
		);
	}

	/**
	 * Resolve OA ID from bizcity_zalo_bots for outbound reply.
	 */
	protected function resolve_oa_id( int $bot_id ): string {
		if ( $bot_id <= 0 ) {
			return '';
		}
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_zalo_bots';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$oa_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT oa_id FROM {$table} WHERE id = %d LIMIT 1",
			$bot_id
		) );
		return $oa_id !== null ? (string) $oa_id : '';
	}

	/**
	 * Humanize event title from intent.
	 */
	protected function make_title( string $type, string $topic, string $raw_text ): string {
		$prefix = [
			'web_post'      => '[Web] ',
			'fb_post'       => '[FB] ',
			'reminder_zalo' => '[Nhắc] ',
		][ $type ] ?? '';

		$base = $topic !== '' ? $topic : $raw_text;
		return $prefix . mb_substr( $base, 0, 200, 'UTF-8' );
	}

	/* ─── Transient helpers ─── */

	protected function store_pending_confirm( int $wp_user_id, int $event_id, string $intent_type ): void {
		set_transient(
			'bizcity_cg_confirm_' . $wp_user_id,
			wp_json_encode( [ 'event_id' => $event_id, 'intent_type' => $intent_type ] ),
			self::CONFIRM_TTL
		);
	}

	protected function get_pending_confirm( int $wp_user_id ): ?array {
		$raw = get_transient( 'bizcity_cg_confirm_' . $wp_user_id );
		if ( ! $raw ) {
			return null;
		}
		$data = json_decode( (string) $raw, true );
		return is_array( $data ) ? $data : null;
	}

	protected function clear_pending_confirm( int $wp_user_id ): void {
		delete_transient( 'bizcity_cg_confirm_' . $wp_user_id );
	}
}
