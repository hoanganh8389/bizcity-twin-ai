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
	const TOKEN_BYTES = 32;   // 256-bit entropy → 43 chars base64url

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
			$raw = self::base64url_encode( random_bytes( self::TOKEN_BYTES ) );
		} catch ( Exception $e ) {
			return new WP_Error( 'bizcity_crm_magic_link_random_fail', $e->getMessage() );
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
			return new WP_Error( 'bizcity_crm_magic_link_insert_fail', $wpdb->last_error );
		}
		$id = (int) $wpdb->insert_id;

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
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE token_hash = %s LIMIT 1', $hash ),
			ARRAY_A
		);
		if ( ! $row ) {
			return new WP_Error( 'bizcity_crm_magic_link_not_found', 'Link không hợp lệ.' );
		}
		if ( ! empty( $row['consumed_at'] ) ) {
			return new WP_Error( 'bizcity_crm_magic_link_consumed', 'Link đã được sử dụng rồi.' );
		}
		if ( strtotime( $row['expires_at'] . ' UTC' ) < time() ) {
			return new WP_Error( 'bizcity_crm_magic_link_expired', 'Link đã hết hạn.' );
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
			return false;
		}
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
			return false;
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A );
		if ( $row ) {
			do_action( 'bizcity_crm_magic_link_consumed', $row, $user_id );
		}
		return true;
	}

	/* ----- helpers ----- */

	private static function base64url_encode( string $bin ): string {
		return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' );
	}

	private static function client_ip(): string {
		$ip = (string) ( $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '' );
		if ( strpos( $ip, ',' ) !== false ) {
			$ip = trim( strtok( $ip, ',' ) );
		}
		return substr( $ip, 0, 64 );
	}
}
