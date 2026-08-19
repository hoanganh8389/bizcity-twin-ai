<?php
/**
 * BizCity Zalo Bot — Command Router
 *
 * Parses inbound Zalo Bot messages for known trigger keywords and
 * dispatches the appropriate action BEFORE Guru AI Bridge (priority 5).
 *
 * Hook priority on `bizcity_channel_normalized`:
 *   3 — User Linker: auto-send login link for unlinked users
 *   4 — Command Router (this class): explicit command triggers ← HERE
 *   5 — Guru Bridge: AI reply
 *  10 — Legacy Gateway Bridge
 *
 * [2026-07-24 Johnny Chu] HOTFIX — precedence vs Automation Trigger Matcher.
 * `BizCity_Automation_Trigger_Matcher` subscribes `bizcity_zalo_webhook_intake`
 * at priority 5, which fires BEFORE `bizcity_zalo_message_received` in the
 * same request (see class-webhook-handler.php::handle_zalohook()). When the
 * matcher finds an ENABLED keyword/ref/slash workflow for this message, it
 * flags `$GLOBALS['bizcity_automation_matched_mids'][$message_id] = true`.
 * Command Router MUST check this flag and bail — a real automation workflow
 * scenario always wins over the generic identity commands below. See
 * `core/automation/docs/RULE-INBOUND-DISPATCH-PRIORITY.md` for the full
 * cross-hook precedence table.
 *
 * Supported commands (Vietnamese + English + no-diacritic aliases):
 *
 *   đăng nhập / login / đăng ký / register / liên kết / kết nối / connect
 *     → If not linked: force-send login link (bypasses 5-min cooldown,
 *       user explicitly asked). Set skip-AI flag.
 *     → If already linked: confirm + prompt to try AI commands.
 *
 *   hủy liên kết / unlink / đăng xuất / bỏ liên kết
 *     → Remove existing link. Prompt user to re-link.
 *
 *   tôi là ai / thông tin / info / ai đây / tài khoản
 *     → Show linked WP user display_name + email.
 *     → If not linked: prompt to đăng nhập.
 *
 *   xem trí nhớ / trí nhớ / memory / my memory
 *     → Show the linked user's recent TwinBrain memories.
 *     → If not linked: prompt to đăng nhập instead of returning an empty list.
 *
 *   help / trợ giúp / lệnh / hướng dẫn / menu
 *     → Send command list.
 *
 * @package BizCity_Zalo_Bot
 * @since   1.5.0
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Zalobot_Command_Router', false ) ) {
	return;
}

class BizCity_Zalobot_Command_Router {

	/** @var array<string,bool> */
	private static $handled_message_ids = array();

	/**
	 * [2026-07-24 Johnny Chu] HOTFIX — max trimmed message length (chars)
	 * still eligible for generic keyword command detection. Longer text is
	 * always treated as a content/task instruction, not an identity command.
	 */
	private const MAX_COMMAND_LEN = 40;

	/** @var string[] Commands that force-send a login link. */
	private static $login_kw = [
		'đăng nhập', 'dang nhap',
		'đăng ký',   'dang ky',
		'login',     'register',
		'liên kết',  'lien ket',
		'kết nối',   'ket noi',
		'connect',   'bind',
	];

	/** @var string[] Commands that remove the current link. */
	private static $unlink_kw = [
		'hủy liên kết',  'huy lien ket',
		'huỷ liên kết',
		'bỏ liên kết',   'bo lien ket',
		'unlink',
		'đăng xuất',     'dang xuat',
		'thoát',         'thoat',
		'logout',
	];

	/** @var string[] Commands that show linked account info. */
	private static $info_kw = [
		'tôi là ai', 'toi la ai',
		'thông tin',  'thong tin',
		'info',
		'ai đây',    'ai day',
		'tài khoản', 'tai khoan',
		'my account',
	];

	/** @var string[] Commands that show the linked user's TwinBrain memory. */
	private static $memory_kw = [
		'xem trí nhớ', 'xem tri nho',
		'trí nhớ', 'tri nho',
		'memory', 'my memory',
	];

	/** @var string[] Commands that show the help/command list. */
	private static $help_kw = [
		'help',
		'trợ giúp',  'tro giup',
		'giúp đỡ',   'giup do',
		'lệnh',      'lenh',
		'hướng dẫn', 'huong dan',
		'menu',
		'commands',
	];

	/* ── Boot ───────────────────────────────────────────────────────── */

	/**
	 * Register the hook. Call once from bootstrap after User Linker.
	 */
	public static function boot(): void {
		// [2026-07-30 Johnny Chu] R-CH-UNI — command routing consumes the canonical envelope after UCL.
		add_action( 'bizcity_channel_normalized', [ __CLASS__, 'handle_normalized' ], 4, 2 );
	}

	/**
	 * Adapt the canonical envelope to the legacy handler shape internally.
	 * No business consumer subscribes to the raw channel hook anymore.
	 *
	 * @param mixed  $msg
	 * @param string $trigger_key
	 */
	public static function handle_normalized( $msg, $trigger_key = '' ): void {
		// [2026-07-30 Johnny Chu] R-CH-UNI — enforce Zone 2 and identity completeness at the adapter boundary.
		if ( ! is_array( $msg ) || (string) ( $msg['platform'] ?? '' ) !== 'ZALO_BOT' ) {
			return;
		}
		$bot_id     = (int) ( $msg['account_id'] ?? 0 );
		$zalo_uid   = (string) ( $msg['user_id'] ?? $msg['sender_user_id'] ?? '' );
		$text       = trim( (string) ( $msg['message'] ?? $msg['message_text_clean'] ?? $msg['raw_text'] ?? '' ) );
		$message_id = trim( (string) ( $msg['message_id'] ?? '' ) );
		$chat_id    = trim( (string) ( $msg['chat_id'] ?? '' ) );
		if ( $bot_id <= 0 || $zalo_uid === '' || $text === '' || $message_id === '' || $chat_id === '' ) {
			return;
		}
		self::handle( array(
			'code'           => 'zalo_bot',
			'bot_id'         => $bot_id,
			'from_user_id'   => $zalo_uid,
			'from_user_name' => (string) ( $msg['display_name'] ?? '' ),
			'message_text'   => $text,
			'message_id'     => $message_id,
		) );
	}

	/* ── Main handler ───────────────────────────────────────────────── */

	/**
	 * @param mixed $msg  bizcity_zalo_message_received payload.
	 */
	public static function handle( $msg ): void {
		if ( ! is_array( $msg ) ) { return; }

		// Zone 2 only — bail for Zone 1 (zalo_oa, zalo_personal).
		$code = (string) ( $msg['code'] ?? '' );
		if ( $code === 'zalo_oa' || $code === 'zalo_personal' ) { return; }

		$bot_id     = (int)    ( $msg['bot_id']         ?? 0 );
		$zalo_uid   = (string) ( $msg['from_user_id']   ?? '' );
		$display    = (string) ( $msg['from_user_name'] ?? '' );
		$text       = trim( (string) ( $msg['message_text'] ?? '' ) );
		$message_id = (string) ( $msg['message_id']     ?? '' );

		if ( $bot_id <= 0 || $zalo_uid === '' || $text === '' ) { return; }

		// [2026-07-24 Johnny Chu] HOTFIX — R-INBOUND-DISPATCH-PRIORITY: an
		// enabled automation workflow already claimed this exact message
		// (matched by ref/slash/keyword in BizCity_Automation_Trigger_Matcher,
		// which runs earlier on `bizcity_zalo_webhook_intake`). A matched
		// workflow scenario always outranks the generic identity commands
		// below — bail so we don't send an unrelated account-info/help card
		// on top of (or instead of) the workflow's real reply.
		// [2026-08-13 Johnny Chu] HOTFIX-ZALOBOT-LINK-PRECEDENCE — even a stale matcher flag must not suppress the reserved identity-binding command.
		$is_link_command = self::extract_link_nonce( $text ) !== '';
		if ( ! $is_link_command && $message_id !== '' && ! empty( $GLOBALS['bizcity_automation_matched_mids'][ $message_id ] ) ) {
			return;
		}

		// [2026-07-24 Johnny Chu] HOTFIX — messages carrying an image/file
		// attachment are always a content/task request (e.g. "Thợ ảnh" photo
		// edit + đăng FB), never an identity command. Bail early so the
		// automation image-purpose flow (priority 1) is the only responder;
		// otherwise a generic keyword substring below (e.g. "thông tin") can
		// still fire and send an unrelated account-info card on top of it.
		$has_attachment = ! empty( $msg['image_url'] ) || ! empty( $msg['file_url'] );
		if ( $has_attachment ) { return; }

		// Fetch bot row (need API object to send reply).
		global $wpdb;
		$tbl = $wpdb->prefix . 'bizcity_zalo_bots';
		$bot = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$tbl} WHERE id = %d LIMIT 1",
			$bot_id
		) );
		if ( ! $bot ) { return; }

		// [2026-07-16 Johnny Chu] PHASE-TWINWEB W3 — explicit member link command
		// `/link <nonce>` from Twin GPT deep-link binding flow.
		// [2026-07-21 Johnny Chu] PHASE-TWINWEB W3 — parse /link before generic command detection; otherwise /link is treated as unknown and no confirmation is sent.
		$link_nonce = self::extract_link_nonce( $text );
		if ( $link_nonce !== '' ) {
			// [2026-08-06 Johnny Chu] HOTFIX-ZALOBOT-LINK — prevent a direct-hook plus normalized-hook replay from consuming the nonce twice.
			if ( $message_id !== '' && isset( self::$handled_message_ids[ $message_id ] ) ) {
				return;
			}
			if ( $message_id !== '' ) {
				self::$handled_message_ids[ $message_id ] = true;
			}
			self::trace( 'link_command_detected', array(
				'bot_id'         => $bot_id,
				'zalo_user_hash' => substr( md5( $zalo_uid ), 0, 10 ),
				'nonce_hash'     => substr( md5( $link_nonce ), 0, 10 ),
			) );
			self::handle_link_nonce( $bot, $zalo_uid, $bot_id, $display, $link_nonce );
			$GLOBALS['bizcity_zalobot_unlinked_skip'] = true;
			return;
		}

		$cmd = self::detect_command( $text );
		if ( $cmd === '' ) { return; } // not a known command — let AI handle it
		// [2026-08-06 Johnny Chu] HOTFIX-ZALOBOT-LINK — dedupe generic identity commands across direct and normalized delivery.
		if ( $message_id !== '' && isset( self::$handled_message_ids[ $message_id ] ) ) {
			return;
		}
		if ( $message_id !== '' ) {
			self::$handled_message_ids[ $message_id ] = true;
		}

		$wp_user_id = class_exists( 'BizCity_Zalobot_User_Linker' )
			? BizCity_Zalobot_User_Linker::resolve_wp_user( $zalo_uid, $bot_id )
			: 0;

		switch ( $cmd ) {
			case 'login':
				self::handle_login( $bot, $zalo_uid, $bot_id, $wp_user_id, $display );
				break;
			case 'unlink':
				self::handle_unlink( $bot, $zalo_uid, $bot_id, $wp_user_id );
				break;
			case 'info':
				self::handle_info( $bot, $zalo_uid, $bot_id, $wp_user_id );
				break;
			case 'memory':
				self::handle_memory( $bot, $zalo_uid, $wp_user_id );
				break;
			case 'help':
				self::handle_help( $bot, $zalo_uid );
				break;
		}

		// Suppress Guru AI + Legacy bridge for this turn — we already replied.
		$GLOBALS['bizcity_zalobot_unlinked_skip'] = true;
	}

	/* ── Command handlers ───────────────────────────────────────────── */

	/**
	 * "đăng nhập" / "login" — send / resend login link.
	 */
	private static function handle_login(
		object $bot,
		string $zalo_uid,
		int    $bot_id,
		int    $wp_user_id,
		string $display
	): void {
		if ( $wp_user_id > 0 ) {
			// Already linked — tell them and prompt AI commands.
			$user = get_user_by( 'id', $wp_user_id );
			$name = $user ? $user->display_name : "User #{$wp_user_id}";
			self::send( $bot, $zalo_uid,
				"✅ Bạn đang sử dụng tài khoản: {$name}\n"
				. "Bạn đã đăng nhập và liên kết Zalo Bot thành công.\n\n"
				. "Thử ra lệnh nhé: nhắc lịch, đăng Facebook, hỏi đáp, chiêm tinh…"
			);
			return;
		}

		if ( ! class_exists( 'BizCity_Zalobot_User_Linker' ) ) {
			self::send( $bot, $zalo_uid, '❌ Tính năng đăng nhập chưa sẵn sàng. Vui lòng thử lại sau.' );
			return;
		}

		// Clear cooldown so explicit request always gets the link immediately.
		$cooldown_key = 'bzzalolink_cd_' . md5( $zalo_uid . '_' . $bot_id );
		delete_transient( $cooldown_key );

		self::send( $bot, $zalo_uid, 'ℹ️ Bạn chưa đăng nhập bằng tài khoản nào.' );
		$sent = BizCity_Zalobot_User_Linker::maybe_send_login_link( $zalo_uid, $bot_id, $bot, $display, true );
		if ( ! $sent ) {
			self::send( $bot, $zalo_uid, '❌ Chưa tạo được đường link đăng nhập. Vui lòng thử lại sau.' );
		}
	}

	/**
	 * "hủy liên kết" / "unlink" — remove the link.
	 */
	private static function handle_unlink(
		object $bot,
		string $zalo_uid,
		int    $bot_id,
		int    $wp_user_id
	): void {
		if ( $wp_user_id <= 0 ) {
			self::send( $bot, $zalo_uid,
				'ℹ️ Bạn chưa liên kết tài khoản nào. Nhắn "đăng nhập" để kết nối.'
			);
			return;
		}

		if ( ! class_exists( 'BizCity_Zalobot_User_Linker' ) ) { return; }

		// Find the link row ID.
		global $wpdb;
		$link_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM " . BizCity_Zalobot_User_Linker::table()
			. " WHERE zalo_user_id = %s AND bot_id = %d AND status = 'linked' LIMIT 1",
			$zalo_uid,
			$bot_id
		) );

		if ( $link_id && BizCity_Zalobot_User_Linker::unlink( $link_id ) ) {
			// Clear request-cache so next resolve_wp_user() returns 0.
			// (The static $cache is private; just use a fresh request.)
			self::send( $bot, $zalo_uid,
				"✅ Đã hủy liên kết tài khoản thành công.\n"
				. 'Nhắn "đăng nhập" bất cứ lúc nào để kết nối lại.'
			);
			do_action( 'bizcity_zalobot_user_unlinked', $bot_id, $zalo_uid, $wp_user_id );
		} else {
			self::send( $bot, $zalo_uid, '❌ Không thể hủy liên kết. Vui lòng thử lại sau.' );
		}
	}

	/**
	 * "tôi là ai" / "info" — show linked account info.
	 */
	private static function handle_info(
		object $bot,
		string $zalo_uid,
		int    $bot_id,
		int    $wp_user_id
	): void {
		if ( $wp_user_id <= 0 ) {
			self::send( $bot, $zalo_uid,
				"ℹ️ Bạn chưa liên kết tài khoản.\nNhắn \"đăng nhập\" để kết nối ngay."
			);
			return;
		}

		$user = get_user_by( 'id', $wp_user_id );
		if ( ! $user ) {
			self::send( $bot, $zalo_uid, '❌ Không tìm thấy tài khoản. Thử "hủy liên kết" rồi "đăng nhập" lại.' );
			return;
		}

		// Mask email for privacy.
		$email  = $user->user_email;
		$masked = self::mask_email( $email );

		self::send( $bot, $zalo_uid,
			"👤 Thông tin tài khoản:\n"
			. "• Tên: {$user->display_name}\n"
			. "• Email: {$masked}\n\n"
			. 'Nhắn "hủy liên kết" nếu muốn đổi tài khoản.'
		);
	}

	/**
	 * "xem trí nhớ" / "memory" — show recent owner-scoped TwinBrain memories.
	 */
	private static function handle_memory(
		object $bot,
		string $zalo_uid,
		int    $wp_user_id
	): void {
		// [2026-07-27 Johnny Chu] PHASE-0.52 W5 — require a linked WP owner;
		// never expose an anonymous/session-bucket memory list.
		if ( $wp_user_id <= 0 ) {
			self::send( $bot, $zalo_uid,
				"ℹ️ Bạn chưa liên kết tài khoản nên chưa thể xem trí nhớ.\n"
				. 'Nhắn "đăng nhập" để kết nối ngay.'
			);
			return;
		}

		// [2026-07-27 Johnny Chu] R-CH-FILE-LOG — evidence before the
		// owner-memory read; payload contains no memory text or PII.
		self::trace( 'memory_list_attempt', array(
			'wp_user_id' => $wp_user_id,
		) );

		if ( ! class_exists( 'BizCity_User_Memory' ) ) {
			self::send( $bot, $zalo_uid,
				'❌ Bộ nhớ chưa sẵn sàng. Vui lòng thử lại sau.'
			);
			return;
		}

		$rows = BizCity_User_Memory::instance()->get_memories( array(
			'user_id'    => $wp_user_id,
			'session_id' => '',
			'limit'      => 10,
			'order_by'   => 'updated_at',
		) );
		$rows = is_array( $rows ) ? $rows : array();

		if ( empty( $rows ) ) {
			self::send( $bot, $zalo_uid,
				"🧠 Bạn chưa có memory nào.\n"
				. 'Hãy nói "hãy nhớ ..." để Twin GPT ghi nhớ điều quan trọng.'
			);
			return;
		}

		$lines = array( '🧠 Trí nhớ của bạn (mới nhất):' );
		foreach ( $rows as $index => $row ) {
			$text = trim( wp_strip_all_tags( (string) ( $row->memory_text ?? '' ) ) );
			if ( $text === '' ) {
				continue;
			}
			if ( function_exists( 'mb_substr' ) ) {
				$text = mb_substr( $text, 0, 220, 'UTF-8' );
			} else {
				$text = substr( $text, 0, 220 );
			}
			$lines[] = ( (int) $index + 1 ) . '. ' . $text;
		}

		$lines[] = '';
		$lines[] = 'Mở Twin GPT để chỉnh sửa hoặc xoá memory.';
		self::send( $bot, $zalo_uid, implode( "\n", $lines ) );
	}

	/**
	 * "help" / "trợ giúp" — show command list.
	 */
	private static function handle_help( object $bot, string $zalo_uid ): void {
		self::send( $bot, $zalo_uid,
			"🤖 Các lệnh bạn có thể dùng:\n\n"
			. "🔑 đăng nhập — Kết nối tài khoản\n"
			. "🚪 hủy liên kết — Ngắt kết nối\n"
			. "👤 thông tin — Xem tài khoản đang kết nối\n"
			. "❓ help — Xem danh sách lệnh này\n\n"
			. "Sau khi đăng nhập, bạn có thể ra lệnh tự nhiên:\n"
			. "📅 nhắc lịch · 📝 đăng Facebook · 🔍 tìm kiếm · ⭐ chiêm tinh · 💬 hỏi đáp"
		);
	}

	/**
	 * [2026-07-16 Johnny Chu] PHASE-TWINWEB W3 — consume member-issued
	 * `/link <nonce>` and bind deterministic identity.
	 */
	private static function handle_link_nonce(
		object $bot,
		string $zalo_uid,
		int $bot_id,
		string $display,
		string $nonce
	): void {
		if ( ! class_exists( 'BizCity_Zalobot_User_Linker' ) ) {
			self::trace( 'link_command_failed', array( 'bot_id' => $bot_id, 'reason' => 'linker_missing' ) );
			self::send( $bot, $zalo_uid, '❌ Tính năng liên kết chưa sẵn sàng. Vui lòng thử lại sau.' );
			return;
		}

		$res = BizCity_Zalobot_User_Linker::consume_twin_gpt_link_nonce(
			$nonce,
			$bot_id,
			$zalo_uid,
			$display
		);
		if ( is_wp_error( $res ) ) {
			self::trace( 'link_command_failed', array(
				'bot_id'     => $bot_id,
				'reason'     => (string) $res->get_error_code(),
				'nonce_hash' => substr( md5( $nonce ), 0, 10 ),
			) );
			$current_user_id = BizCity_Zalobot_User_Linker::resolve_wp_user( $zalo_uid, $bot_id );
			if ( $current_user_id > 0 ) {
				$current_user = get_user_by( 'id', $current_user_id );
				$current_name = $current_user ? $current_user->display_name : "User #{$current_user_id}";
				self::send( $bot, $zalo_uid,
					"ℹ️ Mã liên kết này không còn dùng được, nhưng bạn đang sử dụng tài khoản: {$current_name}.\n"
					. 'Tài khoản Zalo Bot đã được liên kết rồi.'
				);
			} else {
				self::send( $bot, $zalo_uid, 'ℹ️ Mã liên kết không còn dùng được. Bạn chưa đăng nhập bằng tài khoản nào.' );
				$cooldown_key = 'bzzalolink_cd_' . md5( $zalo_uid . '_' . $bot_id );
				delete_transient( $cooldown_key );
				$sent = BizCity_Zalobot_User_Linker::maybe_send_login_link( $zalo_uid, $bot_id, $bot, $display, true );
				if ( ! $sent ) {
					self::send( $bot, $zalo_uid, '❌ Chưa tạo được đường link đăng nhập mới. Vui lòng thử lại sau.' );
				}
			}
			return;
		}

		$wp_user_id = isset( $res['wp_user_id'] ) ? (int) $res['wp_user_id'] : 0;
		$user       = $wp_user_id > 0 ? get_user_by( 'id', $wp_user_id ) : null;
		$name       = $user ? $user->display_name : 'tài khoản của bạn';

		self::trace( 'link_command_bound', array(
			'bot_id'         => $bot_id,
			'wp_user_id'     => $wp_user_id,
			'zalo_user_hash' => substr( md5( $zalo_uid ), 0, 10 ),
		) );

		self::send( $bot, $zalo_uid,
			"✅ Liên kết thành công!\n"
			. "Tài khoản WordPress: {$name}\n\n"
			. 'Từ bây giờ bot sẽ nhận diện đúng danh tính của bạn cho Twin GPT, Astro và automation.'
		);
	}

	/* ── Helpers ────────────────────────────────────────────────────── */

	/**
	 * Detect which command group the message text belongs to.
	 *
	 * Returns one of: 'login' | 'unlink' | 'info' | 'help' | ''
	 */
	private static function detect_command( string $text ): string {
		$t = mb_strtolower( trim( $text ), 'UTF-8' );

		// [2026-07-24 Johnny Chu] HOTFIX — generic keywords like "thông tin"
		// / "tài khoản" / "info" are common Vietnamese words that legitimately
		// appear inside long content/task requests (e.g. "...có thông tin
		// sđt: ... để đăng bài fb"). A plain strpos() substring match false-
		// positives on those and hijacks the turn with an unrelated account
		// card. Only treat the message as an explicit command when it is
		// short/standalone — real commands are typed alone, not buried in a
		// long instruction.
		if ( mb_strlen( $t, 'UTF-8' ) > self::MAX_COMMAND_LEN ) { return ''; }

		// Unlink must be checked BEFORE login (contains "liên kết" substring too).
		foreach ( self::$unlink_kw as $kw ) {
			if ( strpos( $t, $kw ) !== false ) { return 'unlink'; }
		}
		foreach ( self::$login_kw as $kw ) {
			if ( strpos( $t, $kw ) !== false ) { return 'login'; }
		}
		foreach ( self::$info_kw as $kw ) {
			if ( strpos( $t, $kw ) !== false ) { return 'info'; }
		}
		foreach ( self::$memory_kw as $kw ) {
			if ( strpos( $t, $kw ) !== false ) { return 'memory'; }
		}
		foreach ( self::$help_kw as $kw ) {
			if ( strpos( $t, $kw ) !== false ) { return 'help'; }
		}

		return '';
	}

	/**
	 * Extract nonce from strict `/link <nonce>` command.
	 */
	private static function extract_link_nonce( string $text ): string {
		$t = trim( $text );
		if ( $t === '' ) {
			return '';
		}

		if ( preg_match( '/^\/?link\s+([a-zA-Z0-9_-]{8,80})$/i', $t, $m ) ) {
			return sanitize_text_field( (string) $m[1] );
		}

		return '';
	}

	/**
	 * Send a text message via bot API.
	 */
	private static function send( object $bot, string $zalo_uid, string $text ): void {
		if ( ! function_exists( 'bizcity_get_zalo_bot_api' ) ) { return; }
		$api = bizcity_get_zalo_bot_api( (int) $bot->id );
		if ( $api ) {
			$api->send_message( $zalo_uid, $text );
		}
	}

	private static function trace( string $event, array $ctx = array() ): void {
		// [2026-07-21 Johnny Chu] PHASE-TWINWEB W3 — JSONL evidence for /link listener/catch/bind without exposing raw nonce or full Zalo user id.
		if ( class_exists( 'BizCity_Channel_File_Logger' ) ) {
			BizCity_Channel_File_Logger::write(
				BizCity_Channel_File_Logger::CH_ZALO_BOT,
				BizCity_Channel_File_Logger::LEVEL_INFO,
				$event,
				'ZaloBot command router event',
				$ctx
			);
		}
	}

	/**
	 * Mask email for display: john@example.com → j***@example.com
	 */
	private static function mask_email( string $email ): string {
		$parts = explode( '@', $email );
		if ( count( $parts ) !== 2 ) { return '***'; }
		$local  = $parts[0];
		$domain = $parts[1];
		$masked = mb_substr( $local, 0, 1, 'UTF-8' ) . str_repeat( '*', max( 3, mb_strlen( $local, 'UTF-8' ) - 1 ) );
		return $masked . '@' . $domain;
	}
}
