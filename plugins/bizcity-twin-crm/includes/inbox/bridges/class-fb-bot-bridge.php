<?php
/**
 * BizCity CRM — Facebook Bot Bridge.
 *
 * Single point of contact between the CRM Facebook adapter and the
 * `bizcity-facebook-bot` plugin. Adapters MUST go through this bridge
 * instead of calling `BizCity_Facebook_Bot_Database` / `BizCity_Facebook_Bot_API`
 * directly so a future signature change in the bot plugin only breaks ONE file.
 *
 * Wraps:
 *   - BizCity_Facebook_Bot_Database (instance singleton)
 *       · get_bot_by_page_id($page_id)
 *       · get_bots_by_user($user_id)            ← canonical method (NOT get_bots())
 *       · save_customer($data) / get_customer($psid, $page_id)
 *   - BizCity_Facebook_Bot_API
 *       · send_message / send_photo / send_file_message
 *
 * @package BizCity_Twin_CRM
 * @since   PHASE 0.35 (M7.W5.task-1)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_CRM_Bridge_FB {

	const BRIDGE_API_VERSION = '1.0.0';

	/**
	 * True iff the bot plugin is loaded enough to be useful.
	 */
	public static function is_available(): bool {
		return class_exists( 'BizCity_Facebook_Bot_Database', false )
			&& class_exists( 'BizCity_Facebook_Bot_API', false );
	}

	/**
	 * REST namespace registered by bizcity-facebook-bot — used by wizard webhook URL.
	 */
	public static function webhook_namespace(): string {
		return 'bizcity-facebook-bot/v1';
	}

	public static function webhook_url(): string {
		return home_url( '/wp-json/' . self::webhook_namespace() . '/webhook' );
	}

	public static function admin_page_url(): string {
		return admin_url( 'admin.php?page=bizcity-facebook-bots' );
	}

	/* -- Bot/page lookup -------------------------------------------------- */

	/**
	 * @return array|null bot row keyed array, or null
	 */
	public static function get_bot_by_page_id( string $page_id ): ?array {
		if ( ! self::is_available() ) { return null; }
		try {
			$db = BizCity_Facebook_Bot_Database::instance();
			if ( ! method_exists( $db, 'get_bot_by_page_id' ) ) { return null; }
			$bot = $db->get_bot_by_page_id( $page_id );
			if ( ! $bot ) { return null; }
			return (array) $bot;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Look up the page access token by page_id.
	 *
	 * Strategy:
	 *  1) get_bot_by_page_id (canonical)
	 *  2) get_bots_by_user(current_user_id) → match page_id (NOT the non-existent get_bots())
	 *  3) legacy option `fbm_page_access_token_{page_id}`
	 */
	public static function lookup_page_access_token( string $page_id ): string {
		if ( self::is_available() ) {
			try {
				$db = BizCity_Facebook_Bot_Database::instance();

				// 1) direct lookup
				if ( method_exists( $db, 'get_bot_by_page_id' ) ) {
					$bot = $db->get_bot_by_page_id( $page_id );
					if ( $bot ) {
						$bot = (array) $bot;
						if ( ! empty( $bot['page_access_token'] ) ) {
							return (string) $bot['page_access_token'];
						}
					}
				}

				// 2) per-user fallback (replaces buggy get_bots() call)
				if ( method_exists( $db, 'get_bots_by_user' ) ) {
					$uid = get_current_user_id();
					if ( $uid > 0 ) {
						foreach ( (array) $db->get_bots_by_user( $uid ) as $bot ) {
							$bot = (array) $bot;
							if ( ( (string) ( $bot['page_id'] ?? '' ) ) === $page_id
								&& ! empty( $bot['page_access_token'] ) ) {
								return (string) $bot['page_access_token'];
							}
						}
					}
				}

				// 3) site-admin bots (user_id=0) — used for shared pages
				if ( method_exists( $db, 'get_admin_bots' ) ) {
					foreach ( (array) $db->get_admin_bots() as $bot ) {
						$bot = (array) $bot;
						if ( ( (string) ( $bot['page_id'] ?? '' ) ) === $page_id
							&& ! empty( $bot['page_access_token'] ) ) {
							return (string) $bot['page_access_token'];
						}
					}
				}

				// 4) connected_pages table — source ma UI Pages da ket noi dang doc.
				// FB Connect Poster (legacy) ghi vao bang nay khi user OAuth, ke ca
				// khi bot_id=0. Truoc day lookup bo qua bang nay nen adapter tra
				// no page access token du UI hien thi co token / legacy_option.
				if ( method_exists( $db, 'get_connected_pages' ) ) {
					foreach ( (array) $db->get_connected_pages() as $r ) {
						$r = (array) $r;
						if ( ( (string) ( $r['page_id'] ?? '' ) ) === $page_id
							&& ! empty( $r['page_access_token'] ) ) {
							return (string) $r['page_access_token'];
						}
					}
				}
			} catch ( \Throwable $e ) {
				// fall through to option
			}
		}

		// 5) Legacy WP options.
		$opt = get_option( 'fbm_page_access_token_' . $page_id, '' );
		if ( $opt !== '' ) { return (string) $opt; }

		foreach ( (array) get_option( 'fb_pages_connected', array() ) as $p ) {
			$pid = (string) ( $p['id'] ?? ( $p['page_id'] ?? '' ) );
			if ( $pid === $page_id && ! empty( $p['access_token'] ) ) {
				return (string) $p['access_token'];
			}
		}
		return '';
	}

	/* -- Customer profile cache ------------------------------------------ */

	public static function get_customer( string $psid, string $page_id ): ?array {
		if ( ! self::is_available() ) { return null; }
		try {
			$db = BizCity_Facebook_Bot_Database::instance();
			if ( ! method_exists( $db, 'get_customer' ) ) { return null; }
			$row = $db->get_customer( $psid, $page_id );
			if ( ! $row ) { return null; }
			return (array) $row;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	public static function save_customer( array $data ): void {
		if ( ! self::is_available() ) { return; }
		try {
			$db = BizCity_Facebook_Bot_Database::instance();
			if ( method_exists( $db, 'save_customer' ) ) {
				$db->save_customer( $data );
			}
		} catch ( \Throwable $e ) { /* swallow */ }
	}

	/* -- Outbound senders ------------------------------------------------- */

	/**
	 * @return array{success:bool, external_source_id:?string, error:?string}
	 */
	public static function send_text( string $page_id, string $token, string $psid, string $text ): array {
		if ( ! self::is_available() ) {
			return array( 'success' => false, 'external_source_id' => null, 'error' => 'fb_bridge_unavailable' );
		}
		try {
			$api = new BizCity_Facebook_Bot_API( $token, $page_id );
			$res = $api->send_message( $psid, $text );
			return self::normalize_send_result( $res );
		} catch ( \Throwable $e ) {
			return array( 'success' => false, 'external_source_id' => null, 'error' => $e->getMessage() );
		}
	}

	public static function send_image( string $page_id, string $token, string $psid, string $image_url, string $caption = '' ): array {
		if ( ! self::is_available() ) {
			return array( 'success' => false, 'external_source_id' => null, 'error' => 'fb_bridge_unavailable' );
		}
		try {
			$api = new BizCity_Facebook_Bot_API( $token, $page_id );
			$res = $api->send_photo( $psid, $image_url, $caption );
			return self::normalize_send_result( $res );
		} catch ( \Throwable $e ) {
			return array( 'success' => false, 'external_source_id' => null, 'error' => $e->getMessage() );
		}
	}

	public static function send_file( string $page_id, string $token, string $psid, string $file_url ): array {
		if ( ! self::is_available() ) {
			return array( 'success' => false, 'external_source_id' => null, 'error' => 'fb_bridge_unavailable' );
		}
		try {
			$api = new BizCity_Facebook_Bot_API( $token, $page_id );
			if ( ! method_exists( $api, 'send_file_message' ) ) {
				return array( 'success' => false, 'external_source_id' => null, 'error' => 'send_file_message not available' );
			}
			$res = $api->send_file_message( $psid, $file_url );
			return self::normalize_send_result( $res );
		} catch ( \Throwable $e ) {
			return array( 'success' => false, 'external_source_id' => null, 'error' => $e->getMessage() );
		}
	}

	private static function normalize_send_result( $res ): array {
		if ( is_wp_error( $res ) ) {
			return array( 'success' => false, 'external_source_id' => null, 'error' => $res->get_error_message() );
		}
		$mid = '';
		if ( is_array( $res ) ) {
			$mid = (string) ( $res['message_id'] ?? '' );
		}
		return array( 'success' => true, 'external_source_id' => $mid, 'error' => null );
	}
}
