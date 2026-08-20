<?php
/**
 * Channel identity linker for Facebook Messenger, Zalo OA, and Zalo Bot.
 *
 * The CRM magic-link table owns one-time login tokens. This class owns only
 * the external identity mapping, so provider credentials stay in adapters.
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Channel_User_Linker {

	const SCHEMA_VERSION       = '1.5.0'; // [2026-07-28 Johnny Chu] R-DCL — migrate channel links to tenant-shard storage and options.
	const OPTION_VERSION       = 'bizcity_channel_user_links_schema';
	const PLATFORM_FB_MESS     = 'FB_MESS';
	const PLATFORM_ZALO_OA     = 'ZALO_OA';
	const PLATFORM_ZALO_BOT    = 'ZALO_BOT'; // [2026-08-06 Johnny Chu] HOTFIX-ZALOBOT-LINK — canonical admin-channel binding.
	const STATUS_PENDING        = 'pending';
	const STATUS_LINKED         = 'linked';
	const STATUS_UNLINKED       = 'unlinked';
	const TOKEN_TTL             = 1800;
	const LINK_MSG_COOLDOWN     = 300;

	/** @var array<string,int> */
	private static $resolve_cache = array();

	/** @var array<string,bool> */
	private static $table_exists = array();

	public static function init(): void {
		// [2026-07-27 Johnny Chu] PHASE-0.52 W1 — consume the shared CRM token once, then bind the external identity.
		add_action( 'bizcity_crm_magic_link_consumed', array( __CLASS__, 'on_magic_link_consumed' ), 20, 2 );
		add_action( 'bizcity_channel_normalized', array( __CLASS__, 'maybe_send_link_prompt' ), 7, 2 );
		add_action( 'bizcity_channel_message_received', array( __CLASS__, 'maybe_send_link_prompt_direct' ), 7, 1 );
	}

	public static function table(): string {
		// [2026-07-28 Johnny Chu] R-MSDB — channel link rows belong to the current tenant shard.
		global $wpdb;
		return $wpdb->prefix . 'bizcity_channel_user_links';
	}

	public static function supported_platform( string $platform ): bool {
		// [2026-08-06 Johnny Chu] HOTFIX-ZALOBOT-LINK — include Zalo Bot in the shared Channel Gateway identity boundary.
		return in_array( strtoupper( $platform ), array( self::PLATFORM_FB_MESS, self::PLATFORM_ZALO_OA, self::PLATFORM_ZALO_BOT ), true );
	}

	public static function maybe_install(): void {
		// [2026-07-28 Johnny Chu] R-MSDB — read the schema gate from the current tenant shard.
		$current = (string) get_option( self::OPTION_VERSION, '' );
		if ( self::SCHEMA_VERSION === $current ) {
			return;
		}
		self::install();
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			blog_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			platform VARCHAR(40) NOT NULL DEFAULT '',
			external_user_id VARCHAR(190) NOT NULL DEFAULT '',
			account_id VARCHAR(190) NOT NULL DEFAULT '',
			wp_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			link_token_hash CHAR(64) NOT NULL DEFAULT '',
			token_expires DATETIME NULL,
			linked_at DATETIME NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY uniq_identity (blog_id, platform, external_user_id, account_id),
			KEY idx_wp_user (blog_id, wp_user_id),
			KEY idx_token (link_token_hash),
			KEY idx_status (status)
		) {$charset};";
		dbDelta( $sql );
		// [2026-07-28 Johnny Chu] R-MSDB — update the tenant-local schema version after DDL succeeds.
		update_option( self::OPTION_VERSION, self::SCHEMA_VERSION, false );
		unset( self::$table_exists[ (int) get_current_blog_id() . ':' . $table ] );
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( 'bz_tbl_' . (int) get_current_blog_id() . '_' . crc32( $table ), 'bizcity_tbl' );
		}
	}

	/**
	 * Resolve a linked WordPress user. Results are request-cached by full identity.
	 */
	public static function resolve_wp_user( string $platform, string $external_user_id, string $account_id, int $blog_id = 0 ): int {
		$platform         = strtoupper( trim( $platform ) );
		$external_user_id = trim( $external_user_id );
		$account_id       = trim( $account_id );
		$blog_id          = $blog_id > 0 ? $blog_id : (int) get_current_blog_id();
		$cache_key        = $blog_id . ':' . $platform . ':' . $account_id . ':' . $external_user_id;

		if ( isset( self::$resolve_cache[ $cache_key ] ) ) {
			return (int) self::$resolve_cache[ $cache_key ];
		}
		if ( ! self::supported_platform( $platform ) || $external_user_id === '' || $account_id === '' ) {
			self::$resolve_cache[ $cache_key ] = 0;
			return 0;
		}
		if ( ! self::table_exists() ) {
			self::$resolve_cache[ $cache_key ] = 0;
			return 0;
		}

		global $wpdb;
		$user_id = (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT wp_user_id FROM ' . self::table() . ' WHERE blog_id=%d AND platform=%s AND external_user_id=%s AND account_id=%s AND status=%s LIMIT 1',
			$blog_id,
			$platform,
			$external_user_id,
			$account_id,
			self::STATUS_LINKED
		) );
		self::$resolve_cache[ $cache_key ] = $user_id;
		return $user_id;
	}

	/**
	 * Issue a CRM magic link and remember its hash for the identity mapping.
	 *
	 * @return array|WP_Error
	 */
	public static function issue_link( string $platform, string $external_user_id, string $account_id, int $blog_id = 0, array $meta = array() ) {
		// [2026-07-27 Johnny Chu] PHASE-0.52 W1 — keep the mapping write on the canonical WordPress DB handle.
		global $wpdb;
		$platform         = strtoupper( trim( $platform ) );
		$external_user_id = trim( $external_user_id );
		$account_id       = trim( $account_id );
		$blog_id          = $blog_id > 0 ? $blog_id : (int) get_current_blog_id();

		if ( ! self::supported_platform( $platform ) || $external_user_id === '' || $account_id === '' ) {
			return new WP_Error( 'invalid_param', 'Thiếu định danh kênh để tạo liên kết.' );
		}
		if ( ! class_exists( 'BizCity_CRM_Magic_Link' ) ) {
			return new WP_Error( 'module_not_loaded', 'Chưa sẵn sàng tạo liên kết tài khoản.' );
		}
		if ( ! self::table_exists() ) {
			return new WP_Error( 'schema_not_ready', 'Bảng liên kết tài khoản chưa sẵn sàng.' );
		}

		$existing = self::get_identity_row( $platform, $external_user_id, $account_id, $blog_id );
		if ( $existing && self::STATUS_LINKED === (string) $existing['status'] && (int) $existing['wp_user_id'] > 0 ) {
			return array( 'linked' => true, 'cooldown' => false, 'url' => '', 'expires_at' => '' );
		}
		if ( empty( $meta['force'] )
			&& $existing && self::STATUS_PENDING === (string) $existing['status'] && ! empty( $existing['updated_at'] )
			&& strtotime( (string) $existing['updated_at'] . ' UTC' ) > time() - self::LINK_MSG_COOLDOWN ) {
			return array( 'linked' => false, 'cooldown' => true, 'url' => '', 'expires_at' => (string) $existing['token_expires'] );
		}

		$link_meta = array_merge( $meta, array(
			'channel_identity' => array(
				'platform'         => $platform,
				'external_user_id' => $external_user_id,
				'account_id'       => $account_id,
				'blog_id'          => $blog_id,
			),
		) );
		$issued = BizCity_CRM_Magic_Link::issue( array(
			'platform'    => $platform,
			'chat_id'     => self::compose_chat_id( $platform, $account_id, $external_user_id ),
			'blog_id'     => $blog_id,
			'bot_id'      => $account_id,
			'intent'      => 'login',
			'ttl_seconds' => self::TOKEN_TTL,
			'meta'        => $link_meta,
		) );
		if ( is_wp_error( $issued ) ) {
			return $issued;
		}

		$now = current_time( 'mysql', true );
		$data = array(
			'blog_id'         => $blog_id,
			'platform'        => $platform,
			'external_user_id'=> $external_user_id,
			'account_id'      => $account_id,
			'wp_user_id'      => 0,
			'status'          => self::STATUS_PENDING,
			'link_token_hash' => hash( 'sha256', (string) $issued['token'] ),
			'token_expires'   => (string) ( $issued['expires_at'] ?? '' ),
			'updated_at'      => $now,
		);
		if ( $existing ) {
			$ok = $wpdb->update( self::table(), $data, array( 'id' => (int) $existing['id'] ) );
		} else {
			$data['created_at'] = $now;
			$ok = $wpdb->insert( self::table(), $data );
		}
		if ( false === $ok ) {
			return new WP_Error( 'db_error', 'Không lưu được trạng thái liên kết.' );
		}
		return array( 'linked' => false, 'cooldown' => false, 'url' => (string) $issued['url'], 'expires_at' => (string) $issued['expires_at'] );
	}

	/**
	 * Bind the identity after the shared CRM magic-link consumer succeeds.
	 */
	public static function on_magic_link_consumed( $row, int $wp_user_id ): void {
		if ( ! is_array( $row ) || $wp_user_id <= 0 ) {
			// [2026-08-20 Johnny Chu] HOTFIX-ZALOBOT-LINK - trace malformed canonical consume payloads.
			error_log( '[Zalo Link Trace] canonical_bind_skipped reason=invalid_payload' );
			return;
		}
		// [2026-08-20 Johnny Chu] HOTFIX-ZALOBOT-LINK - provision the canonical tenant identity table in the explicit magic-link consume context.
		self::maybe_install();
		$meta = ! empty( $row['meta_json'] ) ? json_decode( (string) $row['meta_json'], true ) : array();
		$identity = is_array( $meta ) && ! empty( $meta['channel_identity'] ) && is_array( $meta['channel_identity'] )
			? $meta['channel_identity'] : array();
		$platform = strtoupper( (string) ( $identity['platform'] ?? $row['platform'] ?? '' ) );
		// [2026-08-06 Johnny Chu] HOTFIX-ZALOBOT-LINK — normalize legacy CRM ZALO rows into the canonical ZALO_BOT platform.
		if ( $platform === 'ZALO' || $platform === 'ZALOBOT' ) {
			$platform = self::PLATFORM_ZALO_BOT;
		}
		$external = (string) ( $identity['external_user_id'] ?? $row['chat_id'] ?? '' );
		$account   = (string) ( $identity['account_id'] ?? $row['bot_id'] ?? '' );
		$blog_id   = (int) ( $identity['blog_id'] ?? $row['blog_id'] ?? get_current_blog_id() );
		error_log( sprintf( '[Zalo Link Trace] canonical_bind_start row_id=%d platform=%s account_id=%s external_hash=%s wp_user_id=%d blog_id=%d has_identity=%d', (int) ( $row['id'] ?? 0 ), $platform, $account, $external !== '' ? substr( md5( $external ), 0, 10 ) : '-', $wp_user_id, $blog_id, ! empty( $identity ) ? 1 : 0 ) );
		if ( ! self::supported_platform( $platform ) || $external === '' || $account === '' ) {
			// [2026-08-20 Johnny Chu] HOTFIX-ZALOBOT-LINK - expose the exact missing identity field without logging user identifiers.
			error_log( sprintf( '[Zalo Link Trace] canonical_bind_failed reason=identity_incomplete supported=%d external_present=%d account_present=%d', self::supported_platform( $platform ) ? 1 : 0, $external !== '' ? 1 : 0, $account !== '' ? 1 : 0 ) );
			return;
		}

		// [2026-08-06 Johnny Chu] HOTFIX-ZALOBOT-LINK — use one canonical bind path for login-link consumption.
		if ( ! self::bind_identity( $platform, $external, $account, $wp_user_id, $blog_id ) ) {
			// [2026-08-20 Johnny Chu] HOTFIX-ZALOBOT-LINK - report the bind stop after table/identity validation.
			error_log( sprintf( '[Zalo Link Trace] canonical_bind_failed reason=bind_identity_false platform=%s account_id=%s external_hash=%s wp_user_id=%d blog_id=%d', $platform, $account, substr( md5( $external ), 0, 10 ), $wp_user_id, $blog_id ) );
			return;
		}
		error_log( sprintf( '[Zalo Link Trace] canonical_bind_ok platform=%s account_id=%s external_hash=%s wp_user_id=%d blog_id=%d', $platform, $account, substr( md5( $external ), 0, 10 ), $wp_user_id, $blog_id ) );
		do_action( 'bizcity_channel_user_linked', $platform, $account, $external, $wp_user_id, $blog_id );

		if ( $platform === self::PLATFORM_ZALO_BOT ) {
			self::notify_zalobot_linked( $account, $external, $wp_user_id );
		}
	}

	/**
	 * Persist a channel identity binding in the Channel Gateway table.
	 *
	 * @return bool True when the canonical row was inserted or updated.
	 */
	public static function bind_identity( string $platform, string $external_user_id, string $account_id, int $wp_user_id, int $blog_id = 0 ): bool {
		// [2026-08-06 Johnny Chu] HOTFIX-ZALOBOT-LINK — expose the admin-BE bind primitive to Zalo Bot command/login flows.
		$platform         = strtoupper( trim( $platform ) );
		$external_user_id = trim( $external_user_id );
		$account_id       = trim( $account_id );
		$blog_id          = $blog_id > 0 ? $blog_id : (int) get_current_blog_id();
		if ( ! self::supported_platform( $platform ) || $external_user_id === '' || $account_id === '' || $wp_user_id <= 0 || ! self::table_exists() ) {
			// [2026-08-20 Johnny Chu] HOTFIX-ZALOBOT-LINK - expose the canonical bind precondition result.
			error_log( sprintf( '[Zalo Link Trace] bind_identity_rejected platform=%s account_present=%d external_present=%d wp_user_id=%d table_present=%d blog_id=%d', $platform, $account_id !== '' ? 1 : 0, $external_user_id !== '' ? 1 : 0, $wp_user_id, self::table_exists() ? 1 : 0, $blog_id ) );
			return false;
		}

		global $wpdb;
		$now      = current_time( 'mysql', true );
		$existing = self::get_identity_row( $platform, $external_user_id, $account_id, $blog_id );
		$data     = array(
			'blog_id'          => $blog_id,
			'platform'         => $platform,
			'external_user_id' => $external_user_id,
			'account_id'       => $account_id,
			'wp_user_id'       => $wp_user_id,
			'status'           => self::STATUS_LINKED,
			'link_token_hash'  => '',
			'token_expires'    => null,
			'linked_at'        => $now,
			'updated_at'       => $now,
		);
		$ok = $existing
			? $wpdb->update( self::table(), $data, array( 'id' => (int) $existing['id'] ) )
			: $wpdb->insert( self::table(), $data + array( 'created_at' => $now ) );
		if ( false === $ok ) {
			// [2026-08-20 Johnny Chu] HOTFIX-ZALOBOT-LINK - trace canonical row write failure without logging SQL.
			error_log( sprintf( '[Zalo Link Trace] bind_identity_failed reason=db_write platform=%s account_id=%s external_hash=%s blog_id=%d', $platform, $account_id, substr( md5( $external_user_id ), 0, 10 ), $blog_id ) );
			return false;
		}

		self::$resolve_cache[ $blog_id . ':' . $platform . ':' . $account_id . ':' . $external_user_id ] = $wp_user_id;
		if ( class_exists( 'BizCity_Identity_Hub' ) ) {
			// [2026-08-06 Johnny Chu] HOTFIX-ZALOBOT-LINK — the channel row is the binding authority; UUID enrichment must not erase a successful admin-BE bind.
			$identity = BizCity_Identity_Hub::bind( $platform, $account_id, $external_user_id, $wp_user_id, $blog_id, true );
		}
		return true;
	}

	/**
	 * Confirm a browser-consumed Zalo Bot login in the originating chat.
	 */
	public static function notify_zalobot_linked( string $bot_id, string $zalo_user_id, int $wp_user_id ): void {
		// [2026-08-06 Johnny Chu] HOTFIX-ZALOBOT-LINK — send confirmation through the canonical Gateway Sender using bot + chat identity.
		// [2026-08-20 Johnny Chu] HOTFIX-ZALOBOT-NOTIFY — make direct and hook-based confirmation delivery idempotent.
		$notice_key = $bot_id . ':' . $zalo_user_id . ':' . $wp_user_id;
		if ( ! empty( $GLOBALS['bizcity_zalobot_link_notice_sent'][ $notice_key ] ) ) {
			return;
		}
		if ( $bot_id === '' || $zalo_user_id === '' || ! class_exists( 'BizCity_Gateway_Sender' ) ) {
			// [2026-08-20 Johnny Chu] HOTFIX-ZALOBOT-LINK - trace notification prerequisites without logging the chat ID.
			error_log( sprintf( '[Zalo Link Trace] notify_skipped bot_present=%d user_present=%d sender_loaded=%d', $bot_id !== '' ? 1 : 0, $zalo_user_id !== '' ? 1 : 0, class_exists( 'BizCity_Gateway_Sender' ) ? 1 : 0 ) );
			return;
		}
		$user = get_user_by( 'id', $wp_user_id );
		$name = $user ? (string) $user->display_name : 'tài khoản WordPress của bạn';
		$chat_id = 'zalobot_' . $bot_id . '_' . $zalo_user_id;
		if ( class_exists( 'BizCity_Channel_File_Logger' ) ) {
			BizCity_Channel_File_Logger::write(
				BizCity_Channel_File_Logger::CH_ZALO_BOT,
				BizCity_Channel_File_Logger::LEVEL_INFO,
				'identity_linked',
				'Zalo Bot identity linked after magic-link consume.',
				array(
					'bot_id'         => (int) $bot_id,
					'zalo_user_hash' => substr( md5( $zalo_user_id ), 0, 10 ),
					'wp_user_id'     => $wp_user_id,
				)
			);
		}
		$result = BizCity_Gateway_Sender::instance()->send(
			$chat_id,
			"✅ Đăng nhập và kết nối thành công!\nTài khoản WordPress: {$name}\nTừ bây giờ Zalo Bot sẽ nhận diện đúng danh tính của bạn.",
			'text',
			array( 'bot_id' => (int) $bot_id, 'source' => 'channel_user_linker' )
		);
		// [2026-08-20 Johnny Chu] HOTFIX-ZALOBOT-LINK - capture the final outbound result for the browser-login confirmation.
		error_log( sprintf( '[Zalo Link Trace] notify_result sent=%d platform=%s error_code=%s', is_array( $result ) && ! empty( $result['sent'] ) ? 1 : 0, is_array( $result ) ? (string) ( $result['platform'] ?? '' ) : 'unknown', is_array( $result ) ? (string) ( $result['code'] ?? '' ) : '' ) );
		if ( is_array( $result ) && ! empty( $result['sent'] ) ) {
			$GLOBALS['bizcity_zalobot_link_notice_sent'][ $notice_key ] = true;
		}
	}

	/**
	 * Offer one account-link URL to an unresolved Zone 1 identity.
	 * The mapping row supplies the resend cooldown, so duplicate normalized
	 * events in one request cannot send repeated prompts.
	 */
	public static function maybe_send_link_prompt( $envelope, $trigger_key = '' ): void {
		// [2026-07-27 Johnny Chu] PHASE-0.52 W1 — prompt unresolved Zone 1 identities through the canonical sender.
		if ( ! is_array( $envelope ) ) {
			return;
		}
		$platform = strtoupper( (string) ( $envelope['platform'] ?? '' ) );
		if ( ! self::supported_platform( $platform ) ) {
			return;
		}
		// [2026-08-01 Johnny Chu] R-CH-IDMEM — guest Zone 1 identities are
		// sufficient for customer care. Keep issue_link() available for an
		// explicit user request, but never auto-nudge WP login in this path.
		if ( ! apply_filters( 'bizcity_channel_auto_login_prompt', false, $envelope, $trigger_key ) ) {
			return;
		}
		if ( self::is_group_payload( $envelope ) ) {
			return;
		}
		$account_id       = trim( (string) ( $envelope['account_id'] ?? '' ) );
		$external_user_id = trim( (string) ( $envelope['user_id'] ?? '' ) );
		if ( $external_user_id === '' ) {
			$external_user_id = trim( (string) ( $envelope['sender_user_id'] ?? '' ) );
		}
		if ( $account_id === '' || $external_user_id === '' ) {
			return;
		}
		$blog_id = (int) get_current_blog_id();
		if ( self::resolve_wp_user( $platform, $external_user_id, $account_id, $blog_id ) > 0 ) {
			return;
		}

		$issued = self::issue_link( $platform, $external_user_id, $account_id, $blog_id );
		if ( ! is_array( $issued ) || empty( $issued['url'] ) || ! class_exists( 'BizCity_Gateway_Sender' ) ) {
			return;
		}

		$send_platform = self::PLATFORM_FB_MESS === $platform ? 'FACEBOOK' : self::PLATFORM_ZALO_OA;
		$message       = 'Để AI nhận diện và cá nhân hóa hỗ trợ cho bạn, hãy mở liên kết này để kết nối tài khoản WordPress: ' . (string) $issued['url'];
		BizCity_Gateway_Sender::instance()->send_envelope( array(
			'platform'    => $send_platform,
			'instance_id' => $account_id,
			'recipient'   => $external_user_id,
			'message'     => $message,
			'type'        => 'text',
			'meta'        => array( 'source' => 'channel_identity_linker' ),
		) );
	}

	public static function maybe_send_link_prompt_direct( $payload ): void {
		// [2026-07-27 Johnny Chu] PHASE-0.52 W1 — cover Gateway Bridge paths that do not pass through UCL.
		if ( ! is_array( $payload ) ) {
			return;
		}
		$platform = strtoupper( (string) ( $payload['platform'] ?? '' ) );
		if ( $platform === 'FACEBOOK' ) {
			$platform = self::PLATFORM_FB_MESS;
		}
		if ( ! self::supported_platform( $platform ) ) {
			return;
		}
		if ( self::is_group_payload( $payload ) ) {
			return;
		}
		self::maybe_send_link_prompt( array(
			'platform'   => $platform,
			'account_id' => (string) ( $payload['account_id'] ?? $payload['instance_id'] ?? $payload['page_id'] ?? $payload['oa_id'] ?? '' ),
			'user_id'    => (string) ( $payload['sender_id'] ?? $payload['user_id'] ?? $payload['from_user_id'] ?? '' ),
			'chat_id'    => (string) ( $payload['chat_id'] ?? '' ),
		), 'direct_message' );
	}

	private static function get_identity_row( string $platform, string $external_user_id, string $account_id, int $blog_id ): ?array {
		if ( ! self::table_exists() ) {
			return null;
		}
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . ' WHERE blog_id=%d AND platform=%s AND external_user_id=%s AND account_id=%s LIMIT 1',
			$blog_id, $platform, $external_user_id, $account_id
		), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	private static function table_exists(): bool {
		$table = self::table();
		global $wpdb;
		$database = isset( $wpdb->dbname ) ? (string) $wpdb->dbname : '';
		$memo_key = (int) get_current_blog_id() . ':' . $database . ':' . $table;
		// [2026-08-09 Johnny Chu] R-CACHE/R-MSDB — preserve false memo and isolate physical database.
		if ( array_key_exists( $memo_key, self::$table_exists ) ) {
			return self::$table_exists[ $memo_key ];
		}
		if ( function_exists( 'bizcity_tbl_exists' ) ) {
			self::$table_exists[ $memo_key ] = (bool) bizcity_tbl_exists( $table );
			return self::$table_exists[ $memo_key ];
		}
		$wp_cache_key = 'bz_tbl_' . md5( $memo_key );
		$cached = wp_cache_get( $wp_cache_key, 'bizcity_tbl' );
		if ( false === $cached ) {
			$cached = (int) (bool) $wpdb->get_var( $wpdb->prepare(
				'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
				$table
			) );
			wp_cache_set( $wp_cache_key, $cached, 'bizcity_tbl', HOUR_IN_SECONDS );
		}
		self::$table_exists[ $memo_key ] = (bool) $cached;
		return self::$table_exists[ $memo_key ];
	}

	private static function is_group_payload( array $payload ): bool {
		return (string) ( $payload['chat_kind'] ?? '' ) === 'group'
			|| (string) ( $payload['provider_chat_type'] ?? '' ) === 'group';
	}

	private static function compose_chat_id( string $platform, string $account_id, string $external_user_id ): string {
		if ( self::PLATFORM_FB_MESS === $platform ) {
			return 'fb_' . $account_id . '_' . $external_user_id;
		}
		if ( self::PLATFORM_ZALO_BOT === $platform ) {
			return 'zalobot_' . $account_id . '_' . $external_user_id;
		}
		return 'zalooa_' . $account_id . '_' . $external_user_id;
	}
}
