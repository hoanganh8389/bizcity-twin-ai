<?php
/**
 * User Resolver — chat_id → WP user_id
 *
 * Resolves a channel chat_id to a WordPress user_id using the global_user_admin table,
 * WP usermeta fallback, and optionally creates a new user.
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway
 * @since 1.3.0
 */

defined( 'ABSPATH' ) || exit;

class BizCity_User_Resolver {

	/** @var self|null */
	private static $instance = null;

	/** @var array Runtime cache: chat_id → wp_user_id */
	private $cache = [];

	private function __construct() {}

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Resolve a chat identifier to the canonical durable identity context.
	 *
	 * Legacy mappings remain available as an unknown identity projection; this
	 * method never promotes a legacy or soft session into a new UUID.
	 *
	 * @return array|null { identity_uuid, contact_id, wp_user_id, identity_state }
	 */
	public function resolve_identity( string $chat_id, int $blog_id = 0 ) {
		// [2026-07-28 Johnny Chu] R-CH-IDMEM — expose the durable identity contract without breaking int-only legacy callers.
		if ( $chat_id === '' ) {
			return null;
		}
		$blog_id = $blog_id > 0 ? $blog_id : (int) get_current_blog_id();
		if ( $this->is_group_chat_id( $chat_id ) ) {
			return null;
		}
		if ( class_exists( 'BizCity_Identity_Hub' ) ) {
			$identity = BizCity_Identity_Hub::resolve_from_opts( array( 'chat_id' => $chat_id ), $blog_id );
			if ( is_array( $identity ) && ! empty( $identity['identity_uuid'] ) ) {
				$stable = ! empty( $identity['binding']['is_stable'] );
				return array(
					'identity_uuid'  => (string) $identity['identity_uuid'],
					'contact_id'     => (int) ( $identity['contact_id'] ?? 0 ),
					'wp_user_id'     => (int) ( $identity['wp_user_id'] ?? 0 ),
					'identity_state' => $stable ? 'stable' : 'unknown',
				);
			}
		}
		$legacy_user_id = $this->resolve( $chat_id, $blog_id );
		if ( $legacy_user_id <= 0 ) {
			return null;
		}
		return array(
			'identity_uuid'  => '',
			'contact_id'     => 0,
			'wp_user_id'     => $legacy_user_id,
			'identity_state' => 'unknown',
		);
	}

	/**
	 * Resolve chat_id → WP user_id.
	 *
	 * Priority:
	 *   1. global_user_admin table (client_id + blog_id)
	 *   2. global_user_admin table (client_id only — any blog)
	 *   3. Legacy twf_get_user_id_by_chat_id() if available
	 *   4. 0 (unresolved)
	 *
	 * @param string $chat_id   Full chat_id with platform prefix.
	 * @param int    $blog_id   Optional blog_id context for multisite.
	 * @return int WP user_id or 0 if unresolved.
	 */
	public function resolve( string $chat_id, int $blog_id = 0 ): int {
		if ( $chat_id === '' ) {
			return 0;
		}

		$cache_key = $chat_id . ':' . $blog_id;
		if ( isset( $this->cache[ $cache_key ] ) ) {
			return $this->cache[ $cache_key ];
		}

		// [2026-07-17 Johnny Chu] PHASE-TWINWEB F4 — fail closed: group-like chat_id must never resolve as personal identity.
		if ( $this->is_group_chat_id( $chat_id ) ) {
			$this->cache[ $cache_key ] = 0;
			error_log( sprintf( '[User Resolver] 🚫 Refuse group-like chat_id: %s', substr( $chat_id, 0, 80 ) ) );
			return 0;
		}

		$blog_id = $blog_id ?: get_current_blog_id();
		// [2026-07-28 Johnny Chu] R-CH-IDMEM — a known canonical binding outranks WP-user and legacy chat mappings.
		if ( class_exists( 'BizCity_Identity_Hub' ) ) {
			$identity = BizCity_Identity_Hub::resolve_from_opts( array( 'chat_id' => $chat_id ), (int) $blog_id );
			if ( is_array( $identity ) && ! empty( $identity['identity_uuid'] ) ) {
				$user_id = (int) ( $identity['wp_user_id'] ?? 0 );
				$this->cache[ $cache_key ] = $user_id;
				return $user_id;
			}
		}

		// [2026-07-27 Johnny Chu] PHASE-0.52 W1 — resolve Zone 1 identities with account-scoped mapping first.
		$user_id = $this->resolve_from_channel_linker( $chat_id, $blog_id );
		if ( ! $user_id && ( strpos( $chat_id, 'fb_' ) === 0 || strpos( $chat_id, 'zalooa_' ) === 0 ) ) {
			// [2026-07-27 Johnny Chu] PHASE-0.52 W1 — unresolved Zone 1 identities must not fall back to raw global client IDs.
			$this->cache[ $cache_key ] = 0;
			return 0;
		}

		// Priority 0b: Zalo Bot User Linker (BUG-4 fix)
		if ( ! $user_id ) {
			$user_id = $this->resolve_from_zalo_linker( $chat_id, $blog_id );
		}

		if ( ! $user_id ) {
			// Strip platform prefix to get raw client_id
			$client_id = $this->extract_client_id( $chat_id );

			// Priority 1+2: global_user_admin
			$user_id = $this->resolve_from_global_table( $client_id, $blog_id );
		}

		// Priority 3: Legacy helper
		if ( ! $user_id && function_exists( 'twf_get_user_id_by_chat_id' ) ) {
			$user_id = (int) twf_get_user_id_by_chat_id( $chat_id );
		}

		$this->cache[ $cache_key ] = $user_id;

		if ( $user_id ) {
			error_log( sprintf( '[User Resolver] ✅ %s → user_id=%d (blog=%d)', $chat_id, $user_id, $blog_id ) );
		}

		return $user_id;
	}

	/**
	 * Query global_user_admin table.
	 *
	 * @param string $client_id Raw client ID (without prefix).
	 * @param int    $blog_id
	 * @return int
	 */
	private function resolve_from_global_table( string $client_id, int $blog_id ): int {
		if ( $client_id === '' ) {
			return 0;
		}

		global $globaldb;
		$db = ( isset( $globaldb ) && $globaldb ) ? $globaldb : $GLOBALS['wpdb'];
		$table = $db->base_prefix . 'global_user_admin';

		// Check table exists (once per request)
		// [2026-06-22 Johnny Chu] R-SHOW-TABLES — use information_schema + dual cache
		static $table_exists = null;
		if ( null === $table_exists ) {
			$ck           = 'bz_tbl_' . (int) get_current_blog_id() . '_' . crc32( $table );
			$cached       = wp_cache_get( $ck, 'bizcity_tbl' );
			if ( false === $cached ) {
				$cached = (int) (bool) $db->get_var( $db->prepare(
					'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
					$table
				) );
				wp_cache_set( $ck, $cached, 'bizcity_tbl', HOUR_IN_SECONDS );
			}
			$table_exists = (bool) $cached;
		}
		if ( ! $table_exists ) {
			return 0;
		}

		// Priority 1: client_id + blog_id
		$row = $db->get_row( $db->prepare(
			"SELECT user_id, user_slave_id FROM {$table} WHERE client_id = %s AND blog_id = %d ORDER BY updated_at DESC LIMIT 1",
			$client_id,
			$blog_id
		) );

		// Priority 2: client_id only (any blog)
		if ( ! $row ) {
			$row = $db->get_row( $db->prepare(
				"SELECT user_id, user_slave_id FROM {$table} WHERE client_id = %s ORDER BY updated_at DESC LIMIT 1",
				$client_id
			) );
		}

		if ( $row ) {
			return ! empty( $row->user_slave_id ) ? (int) $row->user_slave_id : (int) $row->user_id;
		}

		return 0;
	}

	/**
	 * Resolve Zone 1 Facebook Messenger and Zalo OA identities.
	 *
	 * Chat formats are fb_{page_id}_{psid} and zalooa_{oa_id}_{uid}.
	 * Account-scoped lookup prevents two Pages/OAs sharing a provider user id
	 * from resolving to the wrong WordPress user.
	 */
	private function resolve_from_channel_linker( string $chat_id, int $blog_id ): int {
		if ( ! class_exists( 'BizCity_Channel_User_Linker' ) ) {
			return 0;
		}

		$prefix = '';
		$platform = '';
		if ( strpos( $chat_id, 'fb_' ) === 0 ) {
			$prefix   = 'fb_';
			$platform = BizCity_Channel_User_Linker::PLATFORM_FB_MESS;
		} elseif ( strpos( $chat_id, 'zalooa_' ) === 0 ) {
			$prefix   = 'zalooa_';
			$platform = BizCity_Channel_User_Linker::PLATFORM_ZALO_OA;
		} else {
			return 0;
		}

		$tail    = substr( $chat_id, strlen( $prefix ) );
		$sep_pos = strpos( $tail, '_' );
		if ( false === $sep_pos ) {
			return 0;
		}
		$account_id       = substr( $tail, 0, $sep_pos );
		$external_user_id = substr( $tail, $sep_pos + 1 );
		if ( $account_id === '' || $external_user_id === '' ) {
			return 0;
		}

		return BizCity_Channel_User_Linker::resolve_wp_user( $platform, $external_user_id, $account_id, $blog_id );
	}

	/**
	 * Resolve Zalo Bot chat_id via bizcity_zalobot_user_links table.
	 *
	 * chat_id format: "zalobot_{bot_id}_{zalo_user_id}"
	 * Delegates to BizCity_Zalobot_User_Linker::resolve_wp_user() when available.
	 * If resolved, also syncs the mapping into global_user_admin for faster future lookups.
	 *
	 * @param string $chat_id Full chat_id with platform prefix.
	 * @param int    $blog_id
	 * @return int WP user_id or 0.
	 */
	private function resolve_from_zalo_linker( string $chat_id, int $blog_id ): int {
		// Only handle zalobot_ prefix
		if ( strpos( $chat_id, 'zalobot_' ) !== 0 ) {
			return 0;
		}

		if ( ! class_exists( 'BizCity_Zalobot_User_Linker' ) ) {
			return 0;
		}

		// Parse "zalobot_{bot_id}_{zalo_user_id}"
		$without_prefix = substr( $chat_id, strlen( 'zalobot_' ) ); // "1_abc" or "1_abc123"
		$sep_pos        = strpos( $without_prefix, '_' );
		if ( false === $sep_pos ) {
			return 0;
		}

		$bot_id       = (int) substr( $without_prefix, 0, $sep_pos );
		$zalo_user_id = substr( $without_prefix, $sep_pos + 1 );
		// [2026-07-21 Johnny Chu] PHASE-ZALOBOT-ASTRO — private conversation target still maps to sender identity; group remains refused by is_group_chat_id().
		if ( strpos( $zalo_user_id, 'private_' ) === 0 ) {
			$zalo_user_id = substr( $zalo_user_id, 8 );
		}

		if ( ! $bot_id || $zalo_user_id === '' ) {
			return 0;
		}

		$user_id = BizCity_Zalobot_User_Linker::resolve_wp_user( $zalo_user_id, $bot_id );

		// Sync to global_user_admin for faster future lookups
		if ( $user_id ) {
			$client_id = $this->extract_client_id( $chat_id );
			$this->save_mapping( $client_id, $blog_id, $user_id );
			error_log( sprintf( '[User Resolver] 🔗 Zalo Linker resolved: %s → user_id=%d (bot=%d)', $chat_id, $user_id, $bot_id ) );
		}

		return $user_id;
	}

	/**
	 * Save/update user mapping in global_user_admin.
	 *
	 * @param string $client_id Raw client ID.
	 * @param int    $blog_id
	 * @param int    $user_id   WP user_id.
	 * @param string $domain    Site domain.
	 * @return bool
	 */
	public function save_mapping( string $client_id, int $blog_id, int $user_id, string $domain = '' ): bool {
		global $globaldb;
		$db = ( isset( $globaldb ) && $globaldb ) ? $globaldb : $GLOBALS['wpdb'];
		$table = $db->base_prefix . 'global_user_admin';

		$existing = $db->get_var( $db->prepare(
			"SELECT id FROM {$table} WHERE blog_id = %d AND client_id = %s",
			$blog_id,
			$client_id
		) );

		if ( $existing ) {
			return (bool) $db->update(
				$table,
				[
					'user_id'    => $user_id,
					'domain'     => $domain,
					'updated_at' => current_time( 'mysql' ),
				],
				[ 'id' => $existing ]
			);
		}

		return (bool) $db->insert( $table, [
			'blog_id'    => $blog_id,
			'client_id'  => $client_id,
			'user_id'    => $user_id,
			'domain'     => $domain ?: '',
			'user_level' => 'editor',
			'created_at' => current_time( 'mysql' ),
			'updated_at' => current_time( 'mysql' ),
		] );
	}

	/**
	 * Extract raw client_id from prefixed chat_id.
	 *
	 * @param string $chat_id
	 * @return string
	 */
	private function extract_client_id( string $chat_id ): string {
		// Remove known prefixes
		$prefixes = [ 'zalobot_', 'zalo_', 'fb_', 'messenger_', 'webchat_', 'sess_', 'wcs_', 'adminchat_', 'admin_chat_', 'admin_' ];
		foreach ( $prefixes as $p ) {
			if ( strpos( $chat_id, $p ) === 0 ) {
				return substr( $chat_id, strlen( $p ) );
			}
		}
		// Also check registered adapter prefixes
		if ( class_exists( 'BizCity_Gateway_Bridge' ) ) {
			foreach ( BizCity_Gateway_Bridge::instance()->get_adapters() as $adapter ) {
				$prefix = $adapter->get_prefix();
				if ( $prefix && strpos( $chat_id, $prefix ) === 0 ) {
					return substr( $chat_id, strlen( $prefix ) );
				}
			}
		}
		return $chat_id; // Numeric or unknown — return as-is
	}

	/**
	 * Detect obviously group/thread-like chat identifiers.
	 *
	 * Group chat_id must not be used as personal identity keys.
	 */
	private function is_group_chat_id( string $chat_id ): bool {
		if ( strpos( $chat_id, 'zalobot_' ) !== 0 ) {
			return false;
		}

		$tail    = substr( $chat_id, strlen( 'zalobot_' ) );
		$sep_pos = strpos( $tail, '_' );
		if ( false === $sep_pos ) {
			return false;
		}

		$recipient_id = strtolower( (string) substr( $tail, $sep_pos + 1 ) );
		if ( $recipient_id === '' ) {
			return false;
		}

		$group_markers = array(
			'group_',
			'group-',
			'grp_',
			'grp-',
			'thread_',
			'thread-',
			'conversation_',
			'conversation-',
			'chatgroup_',
			'chatgroup-',
			'chat_',
			'chat-',
		);

		foreach ( $group_markers as $marker ) {
			if ( strpos( $recipient_id, $marker ) === 0 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Ensure global_user_admin table exists.
	 */
	public static function maybe_install(): void {
		global $wpdb;

		$table   = $wpdb->base_prefix . 'global_user_admin';
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$table} (
			id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			blog_id BIGINT UNSIGNED NOT NULL,
			client_id VARCHAR(50) NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			user_slave_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			domain VARCHAR(255) NOT NULL DEFAULT '',
			user_level VARCHAR(50) DEFAULT 'editor',
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			UNIQUE KEY uniq_admin (blog_id, client_id, user_id)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}
}
