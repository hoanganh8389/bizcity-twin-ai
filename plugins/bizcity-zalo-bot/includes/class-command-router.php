<?php
/**
 * BizCity Zalo Bot — Command Router
 *
 * Parses inbound Zalo Bot messages for known trigger keywords and
 * dispatches the appropriate action BEFORE Guru AI Bridge (priority 5).
 *
 * Hook priority on `bizcity_zalo_message_received`:
 *   3 — User Linker: auto-send login link for unlinked users
 *   4 — Command Router (this class): explicit command triggers ← HERE
 *   5 — Guru Bridge: AI reply
 *  10 — Legacy Gateway Bridge
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
		// [2026-06-19 Johnny Chu] ADMIN-GUIDE — explicit command trigger layer
		add_action( 'bizcity_zalo_message_received', [ __CLASS__, 'handle' ], 4, 1 );
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

		$bot_id   = (int)    ( $msg['bot_id']         ?? 0 );
		$zalo_uid = (string) ( $msg['from_user_id']   ?? '' );
		$display  = (string) ( $msg['from_user_name'] ?? '' );
		$text     = trim( (string) ( $msg['message_text'] ?? '' ) );

		if ( $bot_id <= 0 || $zalo_uid === '' || $text === '' ) { return; }

		$cmd = self::detect_command( $text );
		if ( $cmd === '' ) { return; } // not a known command — let AI handle it

		// Fetch bot row (need API object to send reply).
		global $wpdb;
		$tbl = $wpdb->prefix . 'bizcity_zalo_bots';
		$bot = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$tbl} WHERE id = %d LIMIT 1",
			$bot_id
		) );
		if ( ! $bot ) { return; }

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
				"✅ Bạn đã đăng nhập rồi!\n"
				. "Tài khoản: {$name}\n\n"
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

		BizCity_Zalobot_User_Linker::maybe_send_login_link( $zalo_uid, $bot_id, $bot, $display );
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

	/* ── Helpers ────────────────────────────────────────────────────── */

	/**
	 * Detect which command group the message text belongs to.
	 *
	 * Returns one of: 'login' | 'unlink' | 'info' | 'help' | ''
	 */
	private static function detect_command( string $text ): string {
		$t = mb_strtolower( $text, 'UTF-8' );

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
		foreach ( self::$help_kw as $kw ) {
			if ( strpos( $t, $kw ) !== false ) { return 'help'; }
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
