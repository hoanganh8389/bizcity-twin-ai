<?php
/**
 * Durable cross-channel customer identity hub.
 *
 * The hub owns the canonical customer UUID and channel bindings. CRM and
 * WordPress users are projections or optional bindings, not the root identity.
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Identity_Hub {

	const SCHEMA_VERSION = '1.6.0'; // [2026-07-28 Johnny Chu] R-DCL — tenant-shard identity storage and option gate.
	const OPTION_VERSION = 'bizcity_identity_hub_schema';
	const STATUS_ACTIVE   = 'active';
	const STATUS_MERGED   = 'merged';
	const PLATFORM_WP     = 'WP_USER';
	const PLATFORM_WEB    = 'WEBCHAT';

	/** @var array<string,bool> */
	private static $table_exists = array();

	/** @var array<string,array<string,mixed>> */
	private static $uuid_cache = array();

	public static function table_contacts(): string {
		// [2026-07-28 Johnny Chu] R-MSDB — durable identity belongs to the current tenant shard, not the Global DB.
		global $wpdb;
		return $wpdb->prefix . 'bizcity_identity_contacts';
	}

	public static function table_bindings(): string {
		// [2026-07-28 Johnny Chu] R-MSDB — channel bindings must be isolated per tenant shard.
		global $wpdb;
		return $wpdb->prefix . 'bizcity_identity_bindings';
	}

	public static function maybe_install(): void {
		// [2026-07-28 Johnny Chu] R-MSDB — schema version is tenant-scoped and must live in the current shard options table.
		$current = (string) get_option( self::OPTION_VERSION, '' );
		if ( self::SCHEMA_VERSION === $current ) {
			return;
		}
		self::install();
	}

	public static function install(): void {
		// [2026-07-28 Johnny Chu] R-DCL — create the additive canonical identity tables after schema registration.
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$contacts = self::table_contacts();
		$bindings = self::table_bindings();
		$sql = array(
			"CREATE TABLE {$contacts} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				identity_uuid CHAR(36) NOT NULL,
				blog_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				primary_wp_user_id BIGINT UNSIGNED NULL,
				display_label VARCHAR(190) NULL,
				merged_into_uuid CHAR(36) NULL,
				status VARCHAR(20) NOT NULL DEFAULT 'active',
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				UNIQUE KEY uniq_uuid (identity_uuid),
				KEY idx_blog_wp_user (blog_id, primary_wp_user_id),
				KEY idx_merged (merged_into_uuid),
				KEY idx_status (status)
			) {$charset};",
			"CREATE TABLE {$bindings} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				identity_uuid CHAR(36) NOT NULL,
				blog_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				platform VARCHAR(32) NOT NULL DEFAULT '',
				account_id VARCHAR(190) NOT NULL DEFAULT '',
				external_ref VARCHAR(190) NOT NULL DEFAULT '',
				is_stable TINYINT(1) NOT NULL DEFAULT 1,
				first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				UNIQUE KEY uniq_binding (blog_id, platform, account_id, external_ref),
				KEY idx_identity (identity_uuid),
				KEY idx_stable (blog_id, is_stable)
			) {$charset};",
		);
		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}
		// [2026-07-28 Johnny Chu] R-MSDB — persist the version only after the current tenant DDL completes.
		update_option( self::OPTION_VERSION, self::SCHEMA_VERSION, false );
		self::$table_exists = array();
		self::$uuid_cache   = array();
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( 'bz_tbl_identity_' . crc32( $contacts ), 'bizcity_tbl' );
			wp_cache_delete( 'bz_tbl_identity_' . crc32( $bindings ), 'bizcity_tbl' );
		}
	}

	/**
	 * Bind a durable channel identity to a canonical customer UUID.
	 *
	 * @return array|WP_Error
	 */
	public static function bind( string $platform, string $account_id, string $external_ref, int $wp_user_id = 0, int $blog_id = 0, bool $is_stable = true, array $meta = array() ) {
		// [2026-07-28 Johnny Chu] R-CH-IDMEM — resolve or create one durable identity without treating WP user as the root.
		$platform     = self::normalize_platform( $platform );
		$account_id   = trim( $account_id );
		$external_ref = trim( $external_ref );
		$blog_id      = $blog_id > 0 ? $blog_id : (int) get_current_blog_id();
		if ( self::is_group_meta( $meta ) ) {
			return new WP_Error( 'identity_group_forbidden', 'Không thể gắn cuộc trò chuyện nhóm vào hồ sơ cá nhân.' );
		}
		// [2026-07-28 Johnny Chu] R-CH-IDMEM — a soft web/session hint never creates a durable customer root.
		if ( ! $is_stable ) {
			return new WP_Error( 'identity_soft_not_durable', 'Phiên tạm chưa đủ ổn định để tạo hồ sơ khách hàng.' );
		}
		if ( $platform === '' || $external_ref === '' || strlen( $platform ) > 32 || strlen( $account_id ) > 190 || strlen( $external_ref ) > 190 ) {
			return new WP_Error( 'invalid_param', 'Thiếu hoặc sai định danh khách hàng.' );
		}
		if ( ! self::table_exists( self::table_contacts() ) || ! self::table_exists( self::table_bindings() ) ) {
			return new WP_Error( 'schema_not_ready', 'Bảng định danh khách hàng chưa sẵn sàng.' );
		}

		$binding = self::get_binding( $platform, $account_id, $external_ref, $blog_id );
		$identity_uuid = $binding ? (string) $binding['identity_uuid'] : '';
		$contact       = $identity_uuid !== '' ? self::get_contact( $identity_uuid ) : null;

		if ( ! $identity_uuid && $wp_user_id > 0 ) {
			$contact = self::get_contact_by_wp_user( $wp_user_id, $blog_id );
			if ( $contact ) {
				$identity_uuid = (string) $contact['identity_uuid'];
			}
		}
		if ( ! $identity_uuid ) {
			$identity_uuid = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : self::fallback_uuid();
			$now = current_time( 'mysql', true );
			$inserted = $GLOBALS['wpdb']->insert(
				self::table_contacts(),
				array(
					'identity_uuid'      => $identity_uuid,
					'blog_id'            => $blog_id,
					'primary_wp_user_id' => $wp_user_id > 0 ? $wp_user_id : null,
					'display_label'      => isset( $meta['display_label'] ) ? sanitize_text_field( (string) $meta['display_label'] ) : null,
					'status'             => self::STATUS_ACTIVE,
					'created_at'         => $now,
					'updated_at'         => $now,
				),
				array( '%s', '%d', '%d', '%s', '%s', '%s', '%s' )
			);
			if ( false === $inserted ) {
				return new WP_Error( 'db_error', 'Không tạo được định danh khách hàng.' );
			}
			$contact = self::get_contact( $identity_uuid );
		}

		if ( ! $contact ) {
			return new WP_Error( 'identity_not_found', 'Không tìm thấy định danh khách hàng.' );
		}
		$canonical = self::resolve( $identity_uuid );
		if ( ! $canonical ) {
			return new WP_Error( 'identity_not_active', 'Định danh khách hàng không còn hoạt động.' );
		}
		$identity_uuid = (string) $canonical['identity_uuid'];
		$contact       = self::get_contact( $identity_uuid );
		if ( $wp_user_id > 0 && $contact && ! self::attach_wp_user( $contact, $wp_user_id ) ) {
			return new WP_Error( 'identity_conflict', 'Định danh khách hàng đang gắn với tài khoản khác.' );
		}

		$now = current_time( 'mysql', true );
		if ( $binding ) {
			$updated = $GLOBALS['wpdb']->update(
				self::table_bindings(),
				array(
					'identity_uuid' => $identity_uuid,
					'is_stable'    => (int) ( $is_stable || ! empty( $binding['is_stable'] ) ),
					'last_seen_at' => $now,
				),
				array( 'id' => (int) $binding['id'] ),
				array( '%s', '%d', '%s' ),
				array( '%d' )
			);
			if ( false === $updated ) {
				return new WP_Error( 'db_error', 'Không cập nhật được liên kết định danh.' );
			}
		} else {
			$inserted = $GLOBALS['wpdb']->insert(
				self::table_bindings(),
				array(
					'identity_uuid' => $identity_uuid,
					'blog_id'      => $blog_id,
					'platform'     => $platform,
					'account_id'   => $account_id,
					'external_ref' => $external_ref,
					'is_stable'    => (int) $is_stable,
					'first_seen_at'=> $now,
					'last_seen_at' => $now,
				),
				array( '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s' )
			);
			if ( false === $inserted ) {
				return new WP_Error( 'db_error', 'Không lưu được liên kết định danh.' );
			}
		}

		// [2026-07-28 Johnny Chu] R-MSDB — keep the in-request UUID cache isolated by tenant blog.
		self::$uuid_cache[ (int) get_current_blog_id() . ':' . $identity_uuid ] = $canonical;
		if ( $wp_user_id > 0 && function_exists( 'update_user_meta' ) ) {
			$existing_meta = (string) get_user_meta( $wp_user_id, 'bizcity_identity_uuid', true );
			if ( $existing_meta !== $identity_uuid ) {
				update_user_meta( $wp_user_id, 'bizcity_identity_uuid', $identity_uuid );
			}
		}
		do_action( 'bizcity_identity_bound', $identity_uuid, array(
			'platform'     => $platform,
			'account_id'   => $account_id,
			'external_ref' => $external_ref,
			'wp_user_id'   => $wp_user_id,
			'blog_id'      => $blog_id,
			'is_stable'    => (bool) $is_stable,
		) );
		return self::resolve( $identity_uuid );
	}

	/**
	 * Resolve an identity from runtime options without creating a new identity.
	 */
	public static function resolve_from_opts( array $opts, int $blog_id = 0 ): ?array {
		// [2026-07-28 Johnny Chu] R-CH-IDMEM — identity resolution precedes subject, memory, KG, and channel behavior.
		$identity_uuid = trim( (string) ( $opts['identity_uuid'] ?? '' ) );
		if ( $identity_uuid !== '' ) {
			$resolved = self::resolve( $identity_uuid );
			if ( $resolved ) {
				return $resolved;
			}
		}
		$blog_id   = $blog_id > 0 ? $blog_id : (int) get_current_blog_id();
		$wp_user_id = (int) ( $opts['wp_user_id'] ?? $opts['user_id'] ?? 0 );
		if ( $wp_user_id > 0 ) {
			$resolved = self::get_contact_by_wp_user( $wp_user_id, $blog_id );
			if ( $resolved ) {
				return self::resolve( (string) $resolved['identity_uuid'] );
			}
		}
		$platform = self::normalize_platform( (string) ( $opts['platform'] ?? $opts['platform_type'] ?? '' ) );
		$account  = trim( (string) ( $opts['account_id'] ?? $opts['page_id'] ?? $opts['oa_id'] ?? $opts['bot_id'] ?? '' ) );
		$external = trim( (string) ( $opts['external_user_id'] ?? $opts['external_ref'] ?? $opts['sender_user_id'] ?? '' ) );
		if ( $external === '' ) {
			$external = trim( (string) ( $opts['channel_user_id'] ?? '' ) );
		}
		if ( $platform === '' || $external === '' ) {
			$parsed = self::parse_chat_id( (string) ( $opts['chat_id'] ?? '' ) );
			if ( $parsed ) {
				$platform = $platform !== '' ? $platform : $parsed['platform'];
				$account  = $account !== '' ? $account : $parsed['account_id'];
				$external = $external !== '' ? $external : $parsed['external_ref'];
			}
		}
		if ( $platform === '' || $external === '' ) {
			return null;
		}
		return self::resolve_binding( $platform, $account, $external, $blog_id );
	}

	/**
	 * Resolve a single channel binding to the active canonical identity.
	 */
	public static function resolve_binding( string $platform, string $account_id, string $external_ref, int $blog_id = 0 ): ?array {
		// [2026-07-28 Johnny Chu] R-CH-IDMEM — channel aliases resolve through the UUID hub, never directly to a WP user.
		$platform     = self::normalize_platform( $platform );
		$account_id   = trim( $account_id );
		$external_ref = trim( $external_ref );
		$blog_id      = $blog_id > 0 ? $blog_id : (int) get_current_blog_id();
		if ( $platform === '' || $external_ref === '' || ! self::table_exists( self::table_bindings() ) ) {
			return null;
		}
		$binding = self::get_binding( $platform, $account_id, $external_ref, $blog_id );
		if ( ! $binding ) {
			return null;
		}
		$resolved = self::resolve( (string) $binding['identity_uuid'] );
		if ( ! $resolved ) {
			return null;
		}
		$resolved['binding'] = array(
			'platform'     => $platform,
			'account_id'   => $account_id,
			'external_ref' => $external_ref,
			'is_stable'    => ! empty( $binding['is_stable'] ),
		);
		return $resolved;
	}

	/**
	 * Resolve a canonical UUID and follow merge pointers.
	 */
	public static function resolve( string $identity_uuid ): ?array {
		// [2026-07-28 Johnny Chu] R-CH-IDMEM — follow merge pointers with a bounded chain to fail closed on corrupt data.
		$identity_uuid = strtolower( trim( $identity_uuid ) );
		if ( ! self::is_uuid( $identity_uuid ) || ! self::table_exists( self::table_contacts() ) ) {
			return null;
		}
		$cache_key = (int) get_current_blog_id() . ':' . $identity_uuid;
		if ( isset( self::$uuid_cache[ $cache_key ] ) ) {
			return self::$uuid_cache[ $cache_key ];
		}
		$visited = array();
		$current = $identity_uuid;
		for ( $depth = 0; $depth < 8; $depth++ ) {
			if ( isset( $visited[ $current ] ) ) {
				return null;
			}
			$visited[ $current ] = true;
			$row = self::get_contact( $current );
			if ( ! $row ) {
				return null;
			}
			$next = strtolower( trim( (string) ( $row['merged_into_uuid'] ?? '' ) ) );
			if ( (string) $row['status'] !== self::STATUS_MERGED || ! self::is_uuid( $next ) ) {
				$result = array(
					'identity_uuid'      => (string) $row['identity_uuid'],
					'contact_id'         => (int) $row['id'],
					'blog_id'            => (int) $row['blog_id'],
					'wp_user_id'         => (int) $row['primary_wp_user_id'],
					'primary_wp_user_id' => (int) $row['primary_wp_user_id'],
					'status'             => (string) $row['status'],
					'display_label'      => (string) $row['display_label'],
				);
				self::$uuid_cache[ $cache_key ] = $result;
				return $result;
			}
			$current = $next;
		}
		return null;
	}

	public static function parse_chat_id( string $chat_id ): ?array {
		// [2026-07-28 Johnny Chu] R-CH-IDMEM — parse only known personal prefixes; group IDs never become customer identities.
		$chat_id = trim( $chat_id );
		$prefixes = array(
			'fb_'      => 'FB_MESS',
			'zalooa_'  => 'ZALO_OA',
			'zalobot_' => 'ZALO_BOT',
			'tg_'      => 'TELEGRAM',
			'webchat_' => 'WEBCHAT',
		);
		foreach ( $prefixes as $prefix => $platform ) {
			if ( strpos( $chat_id, $prefix ) !== 0 ) {
				continue;
			}
			$tail  = substr( $chat_id, strlen( $prefix ) );
			$parts = explode( '_', $tail, 2 );
			if ( count( $parts ) !== 2 || $parts[0] === '' || $parts[1] === '' ) {
				return null;
			}
			return array(
				'platform'     => $platform,
				'account_id'   => $parts[0],
				'external_ref' => $parts[1],
			);
		}
		return null;
	}

	public static function normalize_platform( string $platform ): string {
		// [2026-07-28 Johnny Chu] R-CH-IDMEM — normalize aliases once so every channel consumer shares the same platform vocabulary.
		$platform = strtoupper( trim( $platform ) );
		$aliases = array(
			'FACEBOOK'           => 'FB_MESS',
			'FACEBOOK_MESSENGER' => 'FB_MESS',
			'MESSENGER'          => 'FB_MESS',
			'ZALOOA'             => 'ZALO_OA',
			'ZALO_OA'            => 'ZALO_OA',
			'ZALOBOT'            => 'ZALO_BOT',
			'ZALO_BOT'           => 'ZALO_BOT',
			'TELEGRAM_BOT'       => 'TELEGRAM',
			'WEB_CHAT'           => 'WEBCHAT',
		);
		return isset( $aliases[ $platform ] ) ? $aliases[ $platform ] : $platform;
	}

	private static function get_binding( string $platform, string $account_id, string $external_ref, int $blog_id ): ?array {
		// [2026-07-28 Johnny Chu] R-CH-IDMEM — use the full scoped binding tuple to prevent cross-account collisions.
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . self::table_bindings() . ' WHERE blog_id=%d AND platform=%s AND account_id=%s AND external_ref=%s LIMIT 1',
			$blog_id,
			$platform,
			$account_id,
			$external_ref
		), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	private static function get_contact( string $identity_uuid ): ?array {
		// [2026-07-28 Johnny Chu] R-CH-IDMEM — keep contact reads scoped to the canonical UUID.
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . self::table_contacts() . ' WHERE identity_uuid=%s LIMIT 1',
			$identity_uuid
		), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	private static function get_contact_by_wp_user( int $wp_user_id, int $blog_id ): ?array {
		// [2026-07-28 Johnny Chu] R-CH-IDMEM — WP user lookup is only an optional projection back into the UUID owner.
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . self::table_contacts() . ' WHERE blog_id=%d AND primary_wp_user_id=%d AND status=%s LIMIT 1',
			$blog_id,
			$wp_user_id,
			self::STATUS_ACTIVE
		), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	private static function attach_wp_user( array $contact, int $wp_user_id ): bool {
		// [2026-07-28 Johnny Chu] R-CH-IDMEM — reject conflicting ownership; only verified linking may attach the WP projection.
		$current = (int) ( $contact['primary_wp_user_id'] ?? 0 );
		if ( $current > 0 && $current !== $wp_user_id ) {
			return false;
		}
		if ( $current === 0 ) {
			$updated = $GLOBALS['wpdb']->update(
				self::table_contacts(),
				array( 'primary_wp_user_id' => $wp_user_id, 'updated_at' => current_time( 'mysql', true ) ),
				array( 'identity_uuid' => (string) $contact['identity_uuid'] ),
				array( '%d', '%s' ),
				array( '%s' )
			);
			if ( false === $updated ) {
				return false;
			}
		}
		return true;
	}

	private static function table_exists( string $table ): bool {
		// [2026-07-28 Johnny Chu] R-SHOW-TABLES — use information_schema with static and persistent cache, never metadata scans.
		global $wpdb;
		$database = isset( $wpdb->dbname ) ? (string) $wpdb->dbname : '';
		$memo_key = (int) get_current_blog_id() . ':' . $database . ':' . $table;
		// [2026-08-09 Johnny Chu] R-MSDB/R-CACHE — isolate static memo by blog and physical database.
		if ( array_key_exists( $memo_key, self::$table_exists ) ) {
			return self::$table_exists[ $memo_key ];
		}
		$cache_key = 'bz_tbl_identity_' . md5( $memo_key );
		$cached = function_exists( 'wp_cache_get' ) ? wp_cache_get( $cache_key, 'bizcity_tbl' ) : false;
		if ( false === $cached ) {
			$cached = (int) (bool) $wpdb->get_var( $wpdb->prepare(
				'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
				$table
			) );
			if ( function_exists( 'wp_cache_set' ) ) {
				wp_cache_set( $cache_key, $cached, 'bizcity_tbl', HOUR_IN_SECONDS );
			}
		}
		self::$table_exists[ $memo_key ] = (bool) $cached;
		return self::$table_exists[ $memo_key ];
	}

	private static function is_group_meta( array $meta ): bool {
		// [2026-07-28 Johnny Chu] R-TWEB-15 — group chat is conversation context, never a personal identity.
		return (string) ( $meta['chat_kind'] ?? '' ) === 'group'
			|| (string) ( $meta['provider_chat_type'] ?? '' ) === 'group';
	}

	private static function is_uuid( string $value ): bool {
		return (bool) preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value );
	}

	private static function fallback_uuid(): string {
		// [2026-07-28 Johnny Chu] R-CH-IDMEM — retain UUID shape on unusual bootstrap paths without weakening uniqueness.
		$bytes = function_exists( 'random_bytes' ) ? random_bytes( 16 ) : md5( uniqid( '', true ), true );
		$bytes[6] = chr( ord( $bytes[6] ) & 0x0f | 0x40 );
		$bytes[8] = chr( ord( $bytes[8] ) & 0x3f | 0x80 );
		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $bytes ), 4 ) );
	}
}
