<?php
/**
 * Channel Binding — Guru × Channel mapping (PHASE 0.33 M3 prep, schema in M1)
 *
 * Each row: (platform, account_id) → character_id (Guru).
 * account_id='*' is the platform-wide fallback when no exact match.
 *
 * Public API:
 *   BizCity_Channel_Binding::maybe_install()
 *   BizCity_Channel_Binding::resolve($platform, $account_id) : ?array
 *   BizCity_Channel_Binding::all() : array
 *   BizCity_Channel_Binding::upsert($args) : int
 *   BizCity_Channel_Binding::disable($id) : bool
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway
 * @since 1.5.0 (PHASE 0.33 M1)
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Channel_Binding {

	/**
	 * 1.0.0 → 1.1.0 (PHASE 0.33 M4.2):
	 *   + mode VARCHAR(20)              ('auto'|'manual'|'hybrid'|'roundrobin')
	 *   + responder_pool_json LONGTEXT  JSON array [{kind:'guru'|'user', id, weight}]
	 *   + current_pool_index INT        rotation pointer for roundrobin
	 */
	const SCHEMA_VERSION = '1.1.0';
	const OPTION_VERSION = 'bizcity_channel_bindings_schema';
	// [2026-07-24 Johnny Chu] PHASE-DIAG-PERF — version the read cache independently
	// from the schema so a resolution-contract change can invalidate old payloads.
	const CACHE_VERSION = '1';
	const CACHE_GROUP   = 'bizcity_channel_binding';
	const CACHE_TTL     = 600; // 10 minutes; writes invalidate immediately.
	const CACHE_GENERATION_OPTION = 'bizcity_channel_bindings_cache_generation';

	const MODE_AUTO       = 'auto';
	const MODE_MANUAL     = 'manual';
	const MODE_HYBRID     = 'hybrid';
	const MODE_ROUNDROBIN = 'roundrobin';

	public static function modes(): array {
		return array( self::MODE_AUTO, self::MODE_MANUAL, self::MODE_HYBRID, self::MODE_ROUNDROBIN );
	}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_channel_bindings';
	}

	public static function maybe_install(): void {
		$cur = (string) get_option( self::OPTION_VERSION, '' );
		if ( $cur === self::SCHEMA_VERSION ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		global $wpdb;
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			blog_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			platform VARCHAR(40) NOT NULL DEFAULT '',
			account_id VARCHAR(190) NOT NULL DEFAULT '',
			character_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			status TINYINT UNSIGNED NOT NULL DEFAULT 1,
			auto_reply TINYINT UNSIGNED NOT NULL DEFAULT 0,
			mode VARCHAR(20) NOT NULL DEFAULT 'auto',
			responder_pool_json LONGTEXT NULL,
			current_pool_index INT NOT NULL DEFAULT 0,
			fallback_assignee BIGINT UNSIGNED NULL,
			meta_json LONGTEXT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_binding (blog_id, platform, account_id),
			KEY idx_character (character_id),
			KEY idx_status (status),
			KEY idx_mode (mode)
		) {$charset};";
		dbDelta( $sql );
		update_option( self::OPTION_VERSION, self::SCHEMA_VERSION, false );
	}

	/**
	 * Resolve a binding. Tries exact (platform, account_id) first, then
	 * (platform, '*') fallback. Returns null if no active binding found.
	 *
	 * @return array|null
	 */
	public static function resolve( string $platform, string $account_id ): ?array {
		global $wpdb;
		$platform = strtoupper( $platform );
		$blog_id  = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		$table    = self::table();
		$cache_key = self::resolve_cache_key( $blog_id, $platform, $account_id );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( is_array( $cached ) && isset( $cached['found'] ) ) {
			return ! empty( $cached['found'] ) && is_array( $cached['row'] ) ? $cached['row'] : null;
		}

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table}
			 WHERE blog_id=%d AND platform=%s AND account_id=%s AND status=1
			 LIMIT 1",
			$blog_id, $platform, $account_id
		), ARRAY_A );

		if ( ! $row ) {
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM {$table}
				 WHERE blog_id=%d AND platform=%s AND account_id='*' AND status=1
				 LIMIT 1",
				$blog_id, $platform
			), ARRAY_A );
		}

		$resolved = is_array( $row ) ? $row : null;
		wp_cache_set(
			$cache_key,
			array(
				'found' => null !== $resolved,
				'row'   => $resolved,
			),
			self::CACHE_GROUP,
			self::CACHE_TTL
		);
		return $resolved;
	}

	/** [2026-07-24 Johnny Chu] PHASE-DIAG-PERF — isolate cache by blog and binding identity. */
	private static function resolve_cache_key( int $blog_id, string $platform, string $account_id ): string {
		$generation = (int) get_option( self::CACHE_GENERATION_OPTION . '_' . $blog_id, 1 );
		return 'resolve_' . self::CACHE_VERSION . '_' . $generation . '_' . $blog_id . '_' . md5( $platform . '|' . $account_id );
	}

	/** [2026-07-24 Johnny Chu] PHASE-DIAG-PERF — bump generation so exact and wildcard results expire together. */
	private static function invalidate_resolve_cache( int $blog_id, string $platform, string $account_id ): void {
		$option = self::CACHE_GENERATION_OPTION . '_' . $blog_id;
		$next   = (int) get_option( $option, 1 ) + 1;
		update_option( $option, $next, false );
	}

	public static function all(): array {
		global $wpdb;
		$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		// [2026-08-13 Johnny Chu] R-CACHE/R-MSDB — cache the tenant-scoped roster by blog and binding generation.
		$generation = (int) get_option( self::CACHE_GENERATION_OPTION . '_' . $blog_id, 1 );
		$cache_key = 'all_' . self::CACHE_VERSION . '_' . $generation . '_' . $blog_id;
		$cached = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		// [2026-08-13 Johnny Chu] R-MSDB/R-ZONE — never expose bindings from another tenant blog.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE blog_id=%d ORDER BY platform, account_id',
				$blog_id
			),
			ARRAY_A
		);
		$rows = is_array( $rows ) ? $rows : array();
		wp_cache_set( $cache_key, $rows, self::CACHE_GROUP, self::CACHE_TTL );
		return $rows;
	}

	public static function upsert( array $args ): int {
		global $wpdb;
		$platform     = strtoupper( (string) ( $args['platform'] ?? '' ) );
		$account_id   = (string) ( $args['account_id'] ?? '' );
		$character_id = (int) ( $args['character_id'] ?? 0 );
		if ( $platform === '' || $account_id === '' || $character_id <= 0 ) {
			return 0;
		}
		$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		$existing = (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM ' . self::table() . ' WHERE blog_id=%d AND platform=%s AND account_id=%s LIMIT 1',
			$blog_id, $platform, $account_id
		) );
		$mode = isset( $args['mode'] ) ? (string) $args['mode'] : ( ! empty( $args['auto_reply'] ) ? self::MODE_AUTO : self::MODE_MANUAL );
		if ( ! in_array( $mode, self::modes(), true ) ) {
			$mode = self::MODE_AUTO;
		}
		$pool = isset( $args['responder_pool'] ) && is_array( $args['responder_pool'] ) ? $args['responder_pool'] : array();
		$row = array(
			'blog_id'             => $blog_id,
			'platform'            => $platform,
			'account_id'          => $account_id,
			'character_id'        => $character_id,
			'status'              => isset( $args['status'] ) ? (int) $args['status'] : 1,
			'auto_reply'          => ( $mode === self::MODE_AUTO || $mode === self::MODE_HYBRID ) ? 1 : 0,
			'mode'                => $mode,
			'responder_pool_json' => $pool ? wp_json_encode( $pool ) : '',
			'fallback_assignee'   => isset( $args['fallback_assignee'] ) ? (int) $args['fallback_assignee'] : null,
			'meta_json'           => isset( $args['meta'] ) ? wp_json_encode( $args['meta'] ) : '',
			'updated_at'          => current_time( 'mysql' ),
		);
		if ( $existing > 0 ) {
			$updated = $wpdb->update( self::table(), $row, array( 'id' => $existing ) );
			if ( false !== $updated ) {
				self::invalidate_resolve_cache( $blog_id, $platform, $account_id );
			}
			return $existing;
		}
		$row['created_at'] = current_time( 'mysql' );
		$ok = $wpdb->insert( self::table(), $row );
		if ( false !== $ok ) {
			self::invalidate_resolve_cache( $blog_id, $platform, $account_id );
		}
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	public static function disable( int $id ): bool {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT platform, account_id FROM ' . self::table() . ' WHERE id=%d LIMIT 1', $id ), ARRAY_A );
		$updated = $wpdb->update( self::table(), array( 'status' => 0, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $id ) );
		if ( false !== $updated && is_array( $row ) ) {
			$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
			self::invalidate_resolve_cache( $blog_id, (string) $row['platform'], (string) $row['account_id'] );
		}
		return false !== $updated;
	}

	/**
	 * Pick the next responder for a binding row, honoring mode.
	 *
	 * Returns array { kind: 'guru'|'user'|'system', id: int, character_id: ?int, user_id: ?int, mode: string }
	 *
	 * - auto/hybrid → returns the binding's primary character (Guru); user_id null until composer overrides.
	 * - manual    → returns fallback_assignee user (if any) as user_id, character_id null.
	 * - roundrobin→ rotates current_pool_index across responder_pool_json; persists pointer.
	 */
	public static function pick_responder( array $binding ): array {
		$mode = isset( $binding['mode'] ) ? (string) $binding['mode'] : self::MODE_AUTO;
		$cid  = isset( $binding['character_id'] ) ? (int) $binding['character_id'] : 0;
		$uid  = isset( $binding['fallback_assignee'] ) ? (int) $binding['fallback_assignee'] : 0;

		if ( $mode === self::MODE_MANUAL ) {
			return array( 'kind' => 'user', 'id' => $uid, 'character_id' => null, 'user_id' => $uid ?: null, 'mode' => $mode );
		}
		if ( $mode === self::MODE_ROUNDROBIN ) {
			$pool = isset( $binding['responder_pool_json'] ) && $binding['responder_pool_json']
				? json_decode( (string) $binding['responder_pool_json'], true )
				: array();
			if ( is_array( $pool ) && $pool ) {
				$idx  = isset( $binding['current_pool_index'] ) ? (int) $binding['current_pool_index'] : 0;
				$idx  = ( $idx < 0 || $idx >= count( $pool ) ) ? 0 : $idx;
				$slot = $pool[ $idx ];
				$next = ( $idx + 1 ) % count( $pool );
				global $wpdb;
				$wpdb->update( self::table(), array( 'current_pool_index' => $next ), array( 'id' => (int) ( $binding['id'] ?? 0 ) ) );
				$kind = isset( $slot['kind'] ) ? (string) $slot['kind'] : 'guru';
				$sid  = isset( $slot['id'] )   ? (int) $slot['id']      : 0;
				return array(
					'kind'         => $kind,
					'id'           => $sid,
					'character_id' => $kind === 'guru' ? $sid : null,
					'user_id'      => $kind === 'user' ? $sid : null,
					'mode'         => $mode,
				);
			}
		}
		// auto / hybrid (or roundrobin with empty pool fallback) → the primary Guru.
		return array( 'kind' => 'guru', 'id' => $cid, 'character_id' => $cid ?: null, 'user_id' => null, 'mode' => $mode );
	}

	/**
	 * [2026-06-07 Johnny Chu] PHASE-3.5 WB — resolve_id_by_chat() helper.
	 *
	 * Resolve a binding row from a canonical chat_id.
	 * Canonical chat_id format (R-CH-UNI): `<platform>_<account_id>_<user_id>`
	 * Examples: `zalobot_5_2845f9add3e23abc63f3`, `facebook_123_abc456`
	 *
	 * Parses the chat_id to extract platform + account_id, then delegates to resolve().
	 *
	 * @param  string $chat_id Canonical chat ID.
	 * @return array|null      Binding row or null if not found.
	 */
	public static function resolve_id_by_chat( $chat_id ) {
		// [2026-06-07 Johnny Chu] PHASE-3.5 WB — parse chat_id → (platform, account_id)
		$chat_id = (string) $chat_id;
		if ( $chat_id === '' ) {
			return null;
		}

		// Format: <platform>_<account_id>_<user_id>
		// account_id may itself contain underscores (e.g. "12345_abcde") — use first segment as
		// platform, second as account_id, remainder as user_id. However account_id is captured
		// as everything between the first and last underscore groups.
		// Canonical: "zalobot_<bot_id>_<uid>" → platform=zalobot, account_id=<bot_id>.
		// Facebook:  "facebook_<page_id>_<psid>" → platform=facebook, account_id=<page_id>.
		$parts = explode( '_', $chat_id );
		if ( count( $parts ) < 3 ) {
			// Malformed chat_id: try treating entire string as platform lookup with wildcard.
			$platform = strtoupper( $parts[0] );
			return self::resolve( $platform, '*' );
		}

		$platform   = strtoupper( $parts[0] );
		// account_id = parts[1], user_id = implode('_', parts[2..])
		$account_id = $parts[1];

		$row = self::resolve( $platform, $account_id );
		if ( $row ) {
			return $row;
		}
		// Fallback: wildcard account.
		return self::resolve( $platform, '*' );
	}
}
