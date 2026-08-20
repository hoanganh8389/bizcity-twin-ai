<?php
/**
 * BizCity CRM — Magic Link issuer / verifier / consumer.
 *
 * PHASE 3.5 — Admin Chat (Wave A).
 *
 * Replaces legacy ?zid=<aes-encrypted> flow with a first-class single-use
 * token bound to (platform, chat_id, blog_id) and scoped to a TTL.
 *
 * Storage: {prefix}bizcity_crm_chat_magic_links (see class-db-installer.php).
 *
 * @package BizCity_Twin_CRM
 */

defined( 'ABSPATH' ) || exit;

class BizCity_CRM_Magic_Link {

	const DEFAULT_TTL = 1800; // 30 minutes
	const TOKEN_BYTES = 32;   // 256-bit entropy for the encrypted payload nonce
	const TOKEN_PREFIX = 'bzm2_';

	public static function table(): string {
		return BizCity_CRM_DB_Installer_V2::tbl_chat_magic_links();
	}

	/**
	 * Issue a new magic link.
	 *
	 * @param array $args {
	 *     @type string $platform     ZALO / FB_MESS / TELEGRAM / WHATSAPP. Required.
	 *     @type string $chat_id      Platform-specific chat/user identifier. Required.
	 *     @type int    $blog_id      Blog scope. Defaults to current blog.
	 *     @type string $bot_id       Bot/page identifier. Optional.
	 *     @type string $intent       login | admin | consent. Default 'login'.
	 *     @type int    $character_id Guru character id (audit). Optional.
	 *     @type int    $ttl_seconds  Default 1800.
	 *     @type array  $meta         Extra payload (notebook_id, redirect…).
	 * }
	 * @return array{token:string,url:string,expires_at:string,id:int}|WP_Error
	 */
	public static function issue( array $args ) {
		$platform = isset( $args['platform'] ) ? strtoupper( (string) $args['platform'] ) : '';
		$chat_id  = isset( $args['chat_id'] ) ? (string) $args['chat_id'] : '';
		if ( $platform === '' || $chat_id === '' ) {
			return new WP_Error( 'bizcity_crm_magic_link_invalid_args', 'platform and chat_id are required.' );
		}

		$blog_id      = isset( $args['blog_id'] ) ? (int) $args['blog_id'] : get_current_blog_id();
		$bot_id       = isset( $args['bot_id'] ) ? (string) $args['bot_id'] : '';
		$intent       = isset( $args['intent'] ) ? (string) $args['intent'] : 'login';
		$character_id = isset( $args['character_id'] ) ? (int) $args['character_id'] : 0;
		$ttl          = isset( $args['ttl_seconds'] ) ? max( 60, (int) $args['ttl_seconds'] ) : self::DEFAULT_TTL;
		$meta         = isset( $args['meta'] ) && is_array( $args['meta'] ) ? $args['meta'] : array();

		try {
			$token_nonce = BizCity_Codec::base64url_encode( random_bytes( self::TOKEN_BYTES ) );
		} catch ( Exception $e ) {
			return new WP_Error( 'bizcity_crm_magic_link_random_fail', $e->getMessage() );
		}
		// [2026-08-20 Johnny Chu] HOTFIX-ZALOBOT-URL-LINK — encrypt the channel identity into the URL token so the callback can carry chat mapping without cookies/transients.
		$raw = BizCity_Codec::encrypt_json_payload( array(
			'v'        => 2,
			'nonce'    => $token_nonce,
			'platform' => $platform,
			'chat_id'  => $chat_id,
			'bot_id'   => $bot_id,
			'blog_id'  => $blog_id,
			'expires'  => time() + $ttl,
		), self::token_key(), self::TOKEN_PREFIX, '' );
		if ( $raw === '' ) {
			return new WP_Error( 'bizcity_crm_magic_link_crypto_unavailable', 'Không thể mã hóa dữ liệu liên kết.' );
		}
		$hash = hash( 'sha256', $raw );

		global $wpdb;
		$now    = current_time( 'mysql' );
		$expire = gmdate( 'Y-m-d H:i:s', time() + $ttl );

		$ok = $wpdb->insert(
			self::table(),
			array(
				'token_hash'   => $hash,
				'platform'     => $platform,
				'chat_id'      => $chat_id,
				'bot_id'       => $bot_id,
				'blog_id'      => $blog_id,
				'intent'       => $intent,
				'character_id' => $character_id ?: null,
				'issued_ip'    => self::client_ip(),
				'expires_at'   => $expire,
				'meta_json'    => $meta ? wp_json_encode( $meta ) : null,
				'created_at'   => $now,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s' )
		);
		if ( $ok === false ) {
			// [2026-08-20 Johnny Chu] HOTFIX-ZALOBOT-LINK - identify token-row insert failure without logging the token or SQL.
			error_log( sprintf( '[BizCity Magic Link] issue_failed reason=db_insert blog_id=%d table=%s', $blog_id, self::table() ) );
			return new WP_Error( 'bizcity_crm_magic_link_insert_fail', $wpdb->last_error );
		}
		$id = (int) $wpdb->insert_id;
		// [2026-08-20 Johnny Chu] HOTFIX-ZALOBOT-LINK - prove the issued token row and tenant scope before sending the URL.
		error_log( sprintf( '[BizCity Magic Link] issue_ok row_id=%d token_hash=%s blog_id=%d dbname=%s route=%s table=%s', $id, substr( $hash, 0, 12 ), $blog_id, isset( $wpdb->dbname ) ? (string) $wpdb->dbname : '', isset( $wpdb->current_bizname ) ? (string) $wpdb->current_bizname : '', self::table() ) );

		$base = home_url( '/' );
		$url  = add_query_arg( 'bzzalolink', $raw, $base );
		/**
		 * Filter the magic-link URL (e.g. swap to a custom landing domain).
		 *
		 * @param string $url
		 * @param string $token  Raw token (do NOT log).
		 * @param array  $args   Issue args.
		 * @param int    $id     Row id.
		 */
		$url = (string) apply_filters( 'bizcity_crm_magic_link_url', $url, $raw, $args, $id );
		// [2026-08-20 Johnny Chu] HOTFIX-ZALOBOT-LINK - carry the issuing tenant so the callback can route before token verification.
		$url = add_query_arg( 'bizcity_blog_id', $blog_id, $url );

		do_action( 'bizcity_crm_magic_link_issued', $id, $args );

		return array(
			'id'         => $id,
			'token'      => $raw,
			'url'        => $url,
			'expires_at' => $expire,
		);
	}

	/**
	 * Verify a raw token. Does NOT consume.
	 *
	 * @return array|WP_Error  Row array on success.
	 */
	public static function verify( string $raw_token ) {
		$raw_token = trim( $raw_token );
		if ( $raw_token === '' ) {
			return new WP_Error( 'bizcity_crm_magic_link_empty', 'Empty token.' );
		}
		$hash = hash( 'sha256', $raw_token );

		global $wpdb;
		// [2026-08-20 Johnny Chu] HOTFIX-ZALOBOT-LINK - trace token lookup scope without logging the raw token.
		error_log( sprintf( '[BizCity Magic Link] verify_start token_hash=%s blog_id=%d dbname=%s route=%s table=%s', substr( $hash, 0, 12 ), (int) get_current_blog_id(), isset( $wpdb->dbname ) ? (string) $wpdb->dbname : '', isset( $wpdb->current_bizname ) ? (string) $wpdb->current_bizname : '', self::table() ) );
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE token_hash = %s LIMIT 1', $hash ),
			ARRAY_A
		);
		if ( ! $row ) {
			// [2026-08-20 Johnny Chu] HOTFIX-ZALOBOT-LINK - make the wrong-shard/not-found boundary observable.
			error_log( sprintf( '[BizCity Magic Link] verify_failed reason=%s token_hash=%s blog_id=%d dbname=%s table=%s', ! empty( $wpdb->last_error ) ? 'db_lookup_failed' : 'row_not_found', substr( $hash, 0, 12 ), (int) get_current_blog_id(), isset( $wpdb->dbname ) ? (string) $wpdb->dbname : '', self::table() ) );
			return new WP_Error( 'bizcity_crm_magic_link_not_found', 'Link không hợp lệ.' );
		}
		if ( ! empty( $row['consumed_at'] ) ) {
			// [2026-08-20 Johnny Chu] HOTFIX-ZALOBOT-LINK - distinguish a consumed token from a missing token.
			error_log( sprintf( '[BizCity Magic Link] verify_failed reason=consumed row_id=%d blog_id=%d', (int) ( $row['id'] ?? 0 ), (int) get_current_blog_id() ) );
			// [2026-08-01 Johnny Chu] HOTFIX-ZALOBOT-LINK — make a retry by the
			// same authenticated user idempotent after a successful consume.
			$current_user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
			if ( $current_user_id > 0 && (int) ( $row['user_id'] ?? 0 ) === $current_user_id ) {
				$row['_already_consumed_by_current_user'] = true;
				return $row;
			}
			return new WP_Error( 'bizcity_crm_magic_link_consumed', 'Link đã được sử dụng rồi.' );
		}
		if ( strtotime( $row['expires_at'] . ' UTC' ) < time() ) {
			// [2026-08-20 Johnny Chu] HOTFIX-ZALOBOT-LINK - distinguish an expired token from a missing token.
			error_log( sprintf( '[BizCity Magic Link] verify_failed reason=expired row_id=%d blog_id=%d', (int) ( $row['id'] ?? 0 ), (int) get_current_blog_id() ) );
			return new WP_Error( 'bizcity_crm_magic_link_expired', 'Link đã hết hạn.' );
		}
		// [2026-08-20 Johnny Chu] HOTFIX-ZALOBOT-URL-LINK — authenticate the embedded identity against the durable CRM row before allowing consume.
		if ( strpos( $raw_token, self::TOKEN_PREFIX ) === 0 ) {
			$payload = BizCity_Codec::decrypt_json_payload( $raw_token, self::token_key(), self::TOKEN_PREFIX );
			if ( ! is_array( $payload )
				|| (int) ( $payload['v'] ?? 0 ) !== 2
				|| strtoupper( (string) ( $payload['platform'] ?? '' ) ) !== strtoupper( (string) ( $row['platform'] ?? '' ) )
				|| ! hash_equals( (string) ( $row['chat_id'] ?? '' ), (string) ( $payload['chat_id'] ?? '' ) )
				|| (string) ( $row['bot_id'] ?? '' ) !== (string) ( $payload['bot_id'] ?? '' )
				|| (int) ( $row['blog_id'] ?? 0 ) !== (int) ( $payload['blog_id'] ?? 0 )
				|| (int) ( $payload['expires'] ?? 0 ) < time()
			) {
				error_log( sprintf( '[BizCity Magic Link] verify_failed reason=token_identity_mismatch row_id=%d blog_id=%d', (int) ( $row['id'] ?? 0 ), (int) get_current_blog_id() ) );
				return new WP_Error( 'bizcity_crm_magic_link_invalid', 'Link không hợp lệ.' );
			}
		}
		return $row;
	}

	/**
	 * Mark a row consumed and bind to a WP user.
	 *
	 * @return bool
	 */
	public static function consume( int $id, int $user_id ): bool {
		if ( $id <= 0 ) {
			// [2026-08-20 Johnny Chu] HOTFIX-ZALOBOT-LINK - trace invalid consume requests without logging the token.
			error_log( '[BizCity Magic Link] consume_failed reason=invalid_id' );
			return false;
		}
		// [2026-08-20 Johnny Chu] HOTFIX-ZALOBOT-LINK - trace the consume boundary before the CRM row update.
		error_log( sprintf( '[BizCity Magic Link] consume_start row_id=%d wp_user_id=%d blog_id=%d', $id, $user_id, (int) get_current_blog_id() ) );
		global $wpdb;
		$ok = $wpdb->update(
			self::table(),
			array(
				'consumed_at' => current_time( 'mysql' ),
				'consumed_ip' => self::client_ip(),
				'consumed_ua' => substr( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ), 0, 255 ),
				'user_id'     => $user_id ?: null,
			),
			array( 'id' => $id, 'consumed_at' => null ),
			array( '%s', '%s', '%s', '%d' ),
			array( '%d', '%s' )
		);
		if ( $ok === false || $ok === 0 ) {
			// [2026-08-20 Johnny Chu] HOTFIX-ZALOBOT-LINK - distinguish DB update failure from a missing listener.
			error_log( sprintf( '[BizCity Magic Link] consume_failed row_id=%d reason=%s', $id, false === $ok ? 'update_false' : 'already_consumed' ) );
			return false;
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A );
		if ( $row ) {
			// [2026-08-20 Johnny Chu] HOTFIX-ZALOBOT-LINK - prove the consumed row reaches both binding listeners.
			error_log( sprintf( '[BizCity Magic Link] consume_dispatch row_id=%d platform=%s blog_id=%d has_meta=%d', $id, strtoupper( (string) ( $row['platform'] ?? '' ) ), (int) ( $row['blog_id'] ?? get_current_blog_id() ), ! empty( $row['meta_json'] ) ? 1 : 0 ) );
			do_action( 'bizcity_crm_magic_link_consumed', $row, $user_id );
			error_log( sprintf( '[BizCity Magic Link] consume_dispatch_done row_id=%d', $id ) );
		} else {
			// [2026-08-20 Johnny Chu] HOTFIX-ZALOBOT-LINK - trace a consumed row that cannot be reloaded.
			error_log( sprintf( '[BizCity Magic Link] consume_failed row_id=%d reason=row_reload_failed', $id ) );
		}
		return true;
	}

	/* ----- helpers ----- */

	private static function token_key(): string {
		// [2026-08-20 Johnny Chu] CODEC-CORE — return key material so BizCity_Codec derives the same AES key as the original bzm2_ implementation.
		return wp_salt( 'auth' ) . '|' . wp_salt( 'secure_auth' ) . '|bizcity-crm-magic-link';
	}

	private static function client_ip(): string {
		$ip = (string) ( $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '' );
		if ( strpos( $ip, ',' ) !== false ) {
			$ip = trim( strtok( $ip, ',' ) );
		}
		return substr( $ip, 0, 64 );
	}
}
