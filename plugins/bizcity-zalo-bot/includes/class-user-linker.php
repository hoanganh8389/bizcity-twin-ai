<?php
/**
 * BizCity Zalo Bot — User Linker
 *
 * Manages binding Zalo user_ids to WordPress user_ids.
 *
 * Flow:
 *   1. Zalo user messages bot → resolve_wp_user() → 0 (not linked)
 *   2. maybe_send_login_link() sends a WP login URL via bot API (cooldown 5 min)
 *   3. User opens URL → if not logged in → redirects to wp-login → redirects back
 *   4. handle_login_callback() validates token → stores link in DB
 *   5. Subsequent messages → resolve_wp_user() → wp_user_id → full user context
 *
 * Table: {base_prefix}bizcity_zalobot_user_links (network-wide, multisite-safe)
 *
 * @package BizCity_Zalo_Bot
 * @since   1.1.0
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Zalobot_User_Linker {

	const DB_VERSION_OPTION = 'bizcity_zalobot_links_db_ver';
	const DB_VERSION        = '1.1';
	const LINK_PARAM        = 'bzzalolink';
	const TOKEN_TTL         = 1800; // 30 minutes in seconds
	const LINK_MSG_COOLDOWN = 300;  // 5 minute cooldown between link resends

	/* ── Table ── */

	public static function table(): string {
		global $wpdb;
		return $wpdb->base_prefix . 'bizcity_zalobot_user_links';
	}

	/* ── Install / Upgrade ── */

	public static function install(): void {
		global $wpdb;

		if ( get_site_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$charset = $wpdb->get_charset_collate();
		$table   = self::table();

		$sql = "CREATE TABLE IF NOT EXISTS {$table} (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			zalo_user_id varchar(100) NOT NULL,
			bot_id bigint(20) UNSIGNED NOT NULL,
			blog_id bigint(20) UNSIGNED NOT NULL DEFAULT 1,
			wp_user_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
			notebook_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
			link_token varchar(64) NOT NULL DEFAULT '',
			token_expires datetime DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			display_name varchar(255) NOT NULL DEFAULT '',
			linked_at datetime DEFAULT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY uniq_zalo_bot (zalo_user_id, bot_id),
			KEY wp_user_id (wp_user_id),
			KEY notebook_id (notebook_id),
			KEY status (status),
			KEY link_token (link_token(32))
		) {$charset};";

		dbDelta( $sql );

		// Safety: dbDelta sometimes skips column-only ALTERs on legacy tables.
		// Explicitly ensure notebook_id column exists.
		$has_col = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
			  WHERE table_schema = DATABASE() AND table_name = %s AND column_name = 'notebook_id'",
			$table
		) );
		if ( ! (int) $has_col ) {
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN notebook_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 AFTER wp_user_id, ADD KEY notebook_id (notebook_id)" );
		}

		update_site_option( self::DB_VERSION_OPTION, self::DB_VERSION );
		error_log( '[Zalo User Linker] Table installed/upgraded: ' . $table . ' v' . self::DB_VERSION );
	}

	/* ════════════════════════════════════════════════
	 * CORE: Resolve WP user_id from Zalo user_id
	 * ════════════════════════════════════════════════ */

	/**
	 * Resolve WordPress user_id for a Zalo user on a given bot.
	 *
	 * @param  string $zalo_user_id  Zalo platform user ID (message.from.id)
	 * @param  int    $bot_id        Bot instance ID
	 * @return int    WP user_id, or 0 if not linked
	 */
	public static function resolve_wp_user( string $zalo_user_id, int $bot_id ): int {
		global $wpdb;

		// Request-level cache
		static $cache = [];
		$key = $bot_id . '_' . $zalo_user_id;
		if ( isset( $cache[ $key ] ) ) {
			return (int) $cache[ $key ];
		}

		$result = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT wp_user_id FROM " . self::table() . "
			 WHERE zalo_user_id = %s AND bot_id = %d AND status = 'linked'
			 LIMIT 1",
			$zalo_user_id,
			$bot_id
		) );

		$cache[ $key ] = $result;
		return $result;
	}

	/* ════════════════════════════════════════════════
	 * SEND LOGIN LINK (with cooldown)
	 * ════════════════════════════════════════════════ */

	/**
	 * If user is not linked, send a login link via bot API.
	 *
	 * Includes 5-minute cooldown to prevent spamming the same user.
	 *
	 * @param  string $zalo_user_id  Zalo user ID
	 * @param  int    $bot_id        Bot instance ID
	 * @param  object $bot           Bot DB row (has id, bot_token, etc.)
	 * @param  string $display_name  User's Zalo display name
	 * @return bool   true if link was sent, false if already linked or on cooldown
	 */
	public static function maybe_send_login_link(
		string $zalo_user_id,
		int $bot_id,
		object $bot,
		string $display_name = ''
	): bool {
		// Already linked — no-op
		if ( self::resolve_wp_user( $zalo_user_id, $bot_id ) > 0 ) {
			return false;
		}

		// Cooldown: don't spam login links
		$cooldown_key = 'bzzalolink_cd_' . md5( $zalo_user_id . '_' . $bot_id );
		if ( get_transient( $cooldown_key ) ) {
			return false;
		}

		// Generate token and upsert pending record
		$token   = wp_generate_password( 48, false );
		$expires = gmdate( 'Y-m-d H:i:s', time() + self::TOKEN_TTL );

		global $wpdb;
		$table = self::table();

		$existing = $wpdb->get_row( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE zalo_user_id = %s AND bot_id = %d LIMIT 1",
			$zalo_user_id,
			$bot_id
		) );

		$now = current_time( 'mysql', true );

		if ( $existing ) {
			$wpdb->update(
				$table,
				[
					'link_token'    => $token,
					'token_expires' => $expires,
					'display_name'  => $display_name,
					'status'        => 'pending',
					'updated_at'    => $now,
				],
				[ 'id' => $existing->id ],
				[ '%s', '%s', '%s', '%s', '%s' ],
				[ '%d' ]
			);
		} else {
			$wpdb->insert( $table, [
				'zalo_user_id'  => $zalo_user_id,
				'bot_id'        => $bot_id,
				'blog_id'       => get_current_blog_id(),
				'link_token'    => $token,
				'token_expires' => $expires,
				'display_name'  => $display_name,
				'status'        => 'pending',
				'created_at'    => $now,
				'updated_at'    => $now,
			] );
		}

		// PHASE 3.5 Wave A bridge: prefer CRM magic-link issuer so the new
		// handler (which also creates Wave B grant on consume) owns the URL.
		// Falls back to legacy token if CRM plugin not loaded.
		$link_url = add_query_arg( self::LINK_PARAM, $token, home_url( '/' ) );
		if ( class_exists( 'BizCity_CRM_Magic_Link' ) ) {
			$issued = BizCity_CRM_Magic_Link::issue( [
				'platform' => 'ZALO',
				'chat_id'  => $zalo_user_id,
				'bot_id'   => (string) $bot_id,
				'intent'   => 'login',
				'meta'     => [ 'display_name' => $display_name ],
			] );
			if ( ! is_wp_error( $issued ) && ! empty( $issued['url'] ) ) {
				$link_url = $issued['url'];
			}
		}

		$greeting      = $display_name ? "Xin chào {$display_name}! " : 'Xin chào! ';
		$message       = $greeting
			. "Để AI biết bạn là ai và hỗ trợ cá nhân hóa tốt hơn, vui lòng đăng nhập:\n"
			. "🔗 " . $link_url . "\n\n"
			. "Link có hiệu lực trong 30 phút.";

		self::send_via_bot( $bot, $zalo_user_id, $message );

		// Set cooldown
		set_transient( $cooldown_key, 1, self::LINK_MSG_COOLDOWN );

		error_log( sprintf(
			'[Zalo User Linker] 📲 Login link sent → Zalo user=%s bot_id=%d',
			$zalo_user_id,
			$bot_id
		) );

		return true;
	}

	/* ════════════════════════════════════════════════
	 * LOGIN CALLBACK HANDLER
	 * ════════════════════════════════════════════════ */

	/**
	 * Boot on `init` — handles ?bzzalolink=TOKEN flow.
	 * Call once from bootstrap.
	 */
	public static function boot_callback(): void {
		// PHASE 3.5: Wave A handler (BizCity_CRM_Magic_Link_Handler) takes over
		// the ?bzzalolink= flow at init:1. Skip legacy callback if it's loaded
		// to avoid double-handling and the spurious "Link không hợp lệ" page.
		if ( class_exists( 'BizCity_CRM_Magic_Link_Handler' ) ) {
			return;
		}
		add_action( 'init', [ __CLASS__, 'handle_login_callback' ], 5 );
	}

	/**
	 * PHASE 3.5 Wave A bridge — upsert the (zalo_user_id, bot_id) ↔ wp_user_id
	 * mapping. Called by `BizCity_CRM_Magic_Link_Handler::on_consumed()` after
	 * the new magic-link is successfully consumed.
	 *
	 * Pure mapping only — NOT a privilege grant. Privilege flows through
	 * `BizCity_CRM_Admin_Chat_Grants` (3-axis grants table).
	 *
	 * @param string     $zalo_user_id  Zalo platform user id (chat_id).
	 * @param string|int $bot_id        Bot instance id (string-cast to int for legacy table).
	 * @param int        $wp_user_id    Resolved WordPress user id.
	 * @return bool                     true on insert/update success.
	 */
	public static function link( string $zalo_user_id, $bot_id, int $wp_user_id ): bool {
		if ( $zalo_user_id === '' || $wp_user_id <= 0 ) { return false; }
		$bot_id_int = (int) $bot_id;

		global $wpdb;
		$table = self::table();
		$now   = current_time( 'mysql', true );

		$existing = $wpdb->get_row( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE zalo_user_id = %s AND bot_id = %d LIMIT 1",
			$zalo_user_id,
			$bot_id_int
		) );

		if ( $existing ) {
			$ok = $wpdb->update(
				$table,
				[
					'wp_user_id'    => $wp_user_id,
					'status'        => 'linked',
					'linked_at'     => $now,
					'link_token'    => '',
					'token_expires' => null,
					'updated_at'    => $now,
				],
				[ 'id' => $existing->id ]
			);
		} else {
			$ok = $wpdb->insert( $table, [
				'zalo_user_id' => $zalo_user_id,
				'bot_id'       => $bot_id_int,
				'blog_id'      => get_current_blog_id(),
				'wp_user_id'   => $wp_user_id,
				'status'       => 'linked',
				'linked_at'    => $now,
				'created_at'   => $now,
				'updated_at'   => $now,
			] );
		}

		if ( $ok !== false ) {
			do_action( 'bizcity_zalobot_user_linked', $bot_id_int, $zalo_user_id, $wp_user_id );
		}
		return $ok !== false;
	}

	/**
	 * Handle the ?bzzalolink=TOKEN URL.
	 *   • Not logged in  → bounce to wp-login, redirect back here after.
	 *   • Logged in      → validate token → store link → show success page.
	 */
	public static function handle_login_callback(): void {
		$token = sanitize_text_field( $_GET[ self::LINK_PARAM ] ?? '' );
		if ( $token === '' ) {
			return;
		}

		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM " . self::table() . " WHERE link_token = %s AND status = 'pending' LIMIT 1",
			$token
		) );

		if ( ! $row ) {
			self::render_result_page( 'error', 'Link không hợp lệ hoặc đã được sử dụng rồi.' );
			exit;
		}

		if ( $row->token_expires && strtotime( $row->token_expires ) < time() ) {
			self::render_result_page( 'expired', 'Link đã hết hạn. Nhắn tin lại cho bot để nhận link mới.' );
			exit;
		}

		// Not logged in → redirect to WP login, come back after
		if ( ! is_user_logged_in() ) {
			$return_url = add_query_arg( self::LINK_PARAM, $token, home_url( '/' ) );
			wp_redirect( wp_login_url( $return_url ) );
			exit;
		}

		// Logged in → establish the link
		$wp_user_id = get_current_user_id();
		$wpdb->update(
			self::table(),
			[
				'wp_user_id'    => $wp_user_id,
				'status'        => 'linked',
				'linked_at'     => current_time( 'mysql', true ),
				'link_token'    => '',       // consume token (one-time use)
				'token_expires' => null,
				'updated_at'    => current_time( 'mysql', true ),
			],
			[ 'id' => $row->id ]
		);

		$user = wp_get_current_user();

		do_action( 'bizcity_zalobot_user_linked', (int) $row->bot_id, $row->zalo_user_id, $wp_user_id );

		error_log( sprintf(
			'[Zalo User Linker] ✅ Linked: zalo=%s bot_id=%d → WP user #%d (%s)',
			$row->zalo_user_id,
			$row->bot_id,
			$wp_user_id,
			$user->user_login
		) );

		self::render_result_page( 'success', '', $row, $user );
		exit;
	}

	/* ════════════════════════════════════════════════
	 * QUERY HELPERS
	 * ════════════════════════════════════════════════ */

	/**
	 * Get all links for a WP user.
	 */
	public static function get_links_for_wp_user( int $wp_user_id ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM " . self::table() . " WHERE wp_user_id = %d ORDER BY linked_at DESC",
			$wp_user_id
		), ARRAY_A ) ?: [];
	}

	/**
	 * Get all links (admin overview).
	 *
	 * @param array $args  { status: string, limit: int, offset: int }
	 */
	public static function get_all_links( array $args = [] ): array {
		global $wpdb;
		$args   = wp_parse_args( $args, [ 'status' => '', 'limit' => 50, 'offset' => 0 ] );
		$where  = 'WHERE 1=1';
		$params = [];

		if ( $args['status'] !== '' ) {
			$where   .= ' AND status = %s';
			$params[] = $args['status'];
		}

		$sql      = "SELECT * FROM " . self::table() . " {$where} ORDER BY id DESC LIMIT %d OFFSET %d";
		$params[] = (int) $args['limit'];
		$params[] = (int) $args['offset'];

		return $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A ) ?: [];
	}

	/**
	 * Count all links by status.
	 */
	public static function count_by_status(): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT status, COUNT(*) AS cnt FROM " . self::table() . " GROUP BY status",
			ARRAY_A
		) ?: [];
		$out = [ 'linked' => 0, 'pending' => 0, 'unlinked' => 0 ];
		foreach ( $rows as $r ) {
			$out[ $r['status'] ] = (int) $r['cnt'];
		}
		return $out;
	}

	/**
	 * Unlink a binding (sets status=unlinked, clears wp_user_id).
	 */
	public static function unlink( int $link_id ): bool {
		global $wpdb;
		return (bool) $wpdb->update(
			self::table(),
			[ 'status' => 'unlinked', 'wp_user_id' => 0, 'updated_at' => current_time( 'mysql', true ) ],
			[ 'id' => $link_id ],
			[ '%s', '%d', '%s' ],
			[ '%d' ]
		);
	}

	/* ════════════════════════════════════════════════
	 * PRIVATE HELPERS
	 * ════════════════════════════════════════════════ */

	private static function send_via_bot( object $bot, string $zalo_user_id, string $text ): void {
		if ( ! function_exists( 'bizcity_get_zalo_bot_api' ) ) {
			return;
		}
		$api = bizcity_get_zalo_bot_api( (int) $bot->id );
		if ( $api ) {
			$api->send_message( $zalo_user_id, $text );
		}
	}

	private static function render_result_page(
		string $type,
		string $message = '',
		?object $row = null,
		?object $user = null
	): void {
		if ( ! headers_sent() ) {
			status_header( $type === 'success' ? 200 : 400 );
			nocache_headers();
			header( 'Content-Type: text/html; charset=utf-8' );
		}

		$icon  = $type === 'success' ? '✅' : ( $type === 'expired' ? '⏰' : '❌' );
		$color = $type === 'success' ? '#10b981' : ( $type === 'expired' ? '#f59e0b' : '#ef4444' );
		if ( $type === 'success' ) {
			$title = 'Kết nối thành công!';
		} elseif ( $type === 'expired' ) {
			$title = 'Link đã hết hạn';
		} else {
			$title = 'Link không hợp lệ';
		}

		$body = '';
		if ( $type === 'success' && $row && $user ) {
			$zalo_name = esc_html( $row->display_name );
			$wp_name   = esc_html( $user->display_name );
			$body      = "<p>Tài khoản Zalo <strong>{$zalo_name}</strong> đã được kết nối với tài khoản <strong>{$wp_name}</strong>.</p>"
			           . "<p>Từ nay, khi nhắn tin cho bot Zalo, AI sẽ nhận diện bạn và sử dụng đầy đủ thông tin cá nhân hóa.</p>";
		} else {
			$body = '<p>' . esc_html( $message ) . '</p>';
		}

		$home = esc_url( home_url( '/' ) );

		echo "<!doctype html><html lang='vi'><head><meta charset='utf-8'>"
		   . "<title>" . esc_html( $title ) . "</title>"
		   . "<meta name='viewport' content='width=device-width,initial-scale=1'>"
		   . "<style>"
		   . "body{font-family:system-ui,-apple-system,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f8fafc}"
		   . ".card{max-width:480px;width:100%;background:#fff;border-radius:16px;box-shadow:0 8px 32px rgba(0,0,0,.1);padding:40px 32px;text-align:center}"
		   . ".icon{font-size:64px;margin-bottom:16px}.title{font-size:22px;font-weight:700;color:#0f172a;margin-bottom:12px}"
		   . ".body{color:#64748b;font-size:15px;line-height:1.6;margin-bottom:28px}"
		   . ".btn{display:inline-block;padding:12px 28px;border-radius:12px;font-weight:600;text-decoration:none;color:#fff}"
		   . "</style></head><body>"
		   . "<div class='card'>"
		   . "<div class='icon'>{$icon}</div>"
		   . "<h1 class='title'>" . esc_html( $title ) . "</h1>"
		   . "<div class='body'>{$body}</div>"
		   . "<a class='btn' href='{$home}' style='background:{$color}'>Về trang chủ</a>"
		   . "</div></body></html>";
	}
}
