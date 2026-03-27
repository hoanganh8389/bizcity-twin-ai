<?php
/**
 * Blog Resolver — chat_id → blog_id (multisite)
 *
 * Resolves a channel chat_id to the correct blog_id in a multisite network.
 * Uses global_user_admin, zalo bot tables, and transient caching.
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway
 * @since 1.3.0
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Blog_Resolver {

	/** @var self|null */
	private static $instance = null;

	/** @var array Runtime cache: key → blog_id */
	private $cache = [];

	private function __construct() {}

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Resolve chat_id → blog_id.
	 *
	 * Priority:
	 *   1. Explicit blog_id in payload (e.g. from zalo bot webhook)
	 *   2. global_user_admin table lookup by client_id
	 *   3. Zalo bot table scan (for zalobot_ prefix)
	 *   4. Current blog as fallback
	 *
	 * @param string $chat_id Full chat_id with platform prefix.
	 * @param array  $payload Optional normalized payload with extra context.
	 * @return int blog_id.
	 */
	public function resolve( string $chat_id, array $payload = [] ): int {
		if ( ! is_multisite() ) {
			return get_current_blog_id();
		}

		if ( $chat_id === '' ) {
			return get_current_blog_id();
		}

		if ( isset( $this->cache[ $chat_id ] ) ) {
			return $this->cache[ $chat_id ];
		}

		// Priority 1: Explicit blog_id in payload
		if ( ! empty( $payload['blog_id'] ) ) {
			$this->cache[ $chat_id ] = (int) $payload['blog_id'];
			return $this->cache[ $chat_id ];
		}

		// Priority 2: Zalo Bot — resolve via bot_id
		if ( strpos( $chat_id, 'zalobot_' ) === 0 ) {
			$bot_id = 0;
			$stripped = substr( $chat_id, 8 ); // remove 'zalobot_'
			if ( preg_match( '/^(\d+)_/', $stripped, $m ) ) {
				$bot_id = (int) $m[1];
			}
			if ( $bot_id ) {
				$blog_id = $this->resolve_bot_blog( $bot_id );
				if ( $blog_id ) {
					$this->cache[ $chat_id ] = $blog_id;
					return $blog_id;
				}
			}
		}

		// Priority 3: global_user_admin table
		$client_id = $this->extract_client_id( $chat_id );
		$blog_id   = $this->resolve_from_global_table( $client_id );
		if ( $blog_id ) {
			$this->cache[ $chat_id ] = $blog_id;
			return $blog_id;
		}

		// Fallback: current blog
		$this->cache[ $chat_id ] = get_current_blog_id();
		return $this->cache[ $chat_id ];
	}

	/**
	 * Resolve blog_id for a Zalo bot.
	 *
	 * Migrated from bizcity_gateway_resolve_bot_blog_id().
	 *
	 * @param int $bot_id
	 * @return int blog_id or 0.
	 */
	public function resolve_bot_blog( int $bot_id ): int {
		global $wpdb;

		if ( ! $bot_id ) {
			return 0;
		}

		if ( isset( $this->cache[ 'bot:' . $bot_id ] ) ) {
			return $this->cache[ 'bot:' . $bot_id ];
		}

		// Priority 1: Transient cache from webhook handler
		$cached = get_transient( 'zalobot_source_blog_' . $bot_id );
		if ( $cached ) {
			$this->cache[ 'bot:' . $bot_id ] = (int) $cached;
			return (int) $cached;
		}

		// Priority 2: Current blog
		$table_current = $wpdb->prefix . 'bizcity_zalo_bots';
		$table_exists  = $wpdb->get_var( "SHOW TABLES LIKE '{$table_current}'" ) === $table_current;
		if ( $table_exists ) {
			$exists = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$table_current} WHERE id = %d",
				$bot_id
			) );
			if ( $exists ) {
				$this->cache[ 'bot:' . $bot_id ] = get_current_blog_id();
				return get_current_blog_id();
			}
		}

		// Priority 3: User assignment → primary blog
		if ( class_exists( 'BizCity_Zalo_Bot_Dashboard' ) ) {
			$wp_user_id = BizCity_Zalo_Bot_Dashboard::resolve_user_for_bot( $bot_id );
			if ( $wp_user_id ) {
				$primary_blog = get_user_meta( $wp_user_id, 'primary_blog', true );
				if ( $primary_blog ) {
					$this->cache[ 'bot:' . $bot_id ] = (int) $primary_blog;
					return (int) $primary_blog;
				}
			}
		}

		// Priority 4: Scan all blogs in multisite
		if ( is_multisite() ) {
			$blogs = $wpdb->get_col(
				"SELECT blog_id FROM {$wpdb->blogs} WHERE archived = 0 AND deleted = 0 ORDER BY blog_id DESC LIMIT 50"
			);
			foreach ( $blogs as $blog_id ) {
				$table_name   = $wpdb->get_blog_prefix( $blog_id ) . 'bizcity_zalo_bots';
				$table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table_name}'" ) === $table_name;
				if ( ! $table_exists ) {
					continue;
				}
				$found = $wpdb->get_var( $wpdb->prepare(
					"SELECT id FROM {$table_name} WHERE id = %d",
					$bot_id
				) );
				if ( $found ) {
					$this->cache[ 'bot:' . $bot_id ] = (int) $blog_id;
					error_log( sprintf( '[Blog Resolver] 🔍 Found bot #%d in blog #%d', $bot_id, $blog_id ) );
					return (int) $blog_id;
				}
			}
		}

		$this->cache[ 'bot:' . $bot_id ] = 0;
		return 0;
	}

	/**
	 * Query global_user_admin for blog_id by client_id.
	 *
	 * @param string $client_id
	 * @return int
	 */
	private function resolve_from_global_table( string $client_id ): int {
		if ( $client_id === '' ) {
			return 0;
		}

		global $globaldb;
		$db    = ( isset( $globaldb ) && $globaldb ) ? $globaldb : $GLOBALS['wpdb'];
		$table = $db->base_prefix . 'global_user_admin';

		static $table_exists = null;
		if ( null === $table_exists ) {
			$table_exists = $db->get_var( "SHOW TABLES LIKE '{$table}'" ) === $table;
		}
		if ( ! $table_exists ) {
			return 0;
		}

		$blog_id = (int) $db->get_var( $db->prepare(
			"SELECT blog_id FROM {$table} WHERE client_id = %s ORDER BY updated_at DESC LIMIT 1",
			$client_id
		) );

		return $blog_id;
	}

	/**
	 * Extract raw client_id from prefixed chat_id.
	 *
	 * @param string $chat_id
	 * @return string
	 */
	private function extract_client_id( string $chat_id ): string {
		$prefixes = [ 'zalobot_', 'zalo_', 'fb_', 'messenger_', 'webchat_', 'sess_', 'wcs_', 'adminchat_', 'admin_chat_', 'admin_' ];
		foreach ( $prefixes as $p ) {
			if ( strpos( $chat_id, $p ) === 0 ) {
				return substr( $chat_id, strlen( $p ) );
			}
		}
		return $chat_id;
	}

	/**
	 * Ensure global_inbox_admin table exists.
	 */
	public static function maybe_install_inbox(): void {
		global $wpdb;

		$table   = $wpdb->base_prefix . 'global_inbox_admin';
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$table} (
			id BIGINT AUTO_INCREMENT PRIMARY KEY,
			client_id VARCHAR(32),
			client_name VARCHAR(255),
			platform_type VARCHAR(20),
			page_id VARCHAR(40),
			message_id VARCHAR(64),
			message_text TEXT,
			message_type VARCHAR(10),
			created_at DATETIME,
			blog_id INT DEFAULT 0,
			flow_id INT DEFAULT 0,
			reminded_at DATETIME NULL DEFAULT NULL,
			reminder_msg_id INT DEFAULT 0,
			meta LONGTEXT
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}
}
