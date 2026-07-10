<?php
/**
 * BizCity CRM — Zalo Bot Bridge.
 *
 * Wraps `bizcity-zalo-bot` plugin so the CRM Zalo adapter never touches the
 * bot plugin's classes directly. Critical because the bot's API signatures
 * are easy to misremember:
 *   - `BizCity_Zalo_Bot_API::__construct( $access_token )`  ← 1 arg only
 *   - `BizCity_Zalo_Bot_API::send_text_message($user_id, $text)` ← NOT send_text()
 *   - `BizCity_Zalo_Bot_Database::get_active_bots()` / `get_bot($id)` ← NO get_bot_by_oa_id
 *
 * Webhook is delivered via rewrite rule `/zalohook/{bot_id}` (NOT REST).
 *
 * @package BizCity_Twin_CRM
 * @since   PHASE 0.35 (M7.W5.task-1)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_CRM_Bridge_Zalo {

	const BRIDGE_API_VERSION = '1.0.0';

	public static function is_available(): bool {
		return class_exists( 'BizCity_Zalo_Bot_API', false )
			&& class_exists( 'BizCity_Zalo_Bot_Database', false );
	}

	public static function webhook_url( $bot_id = null ): string {
		// Bot plugin uses a top-level rewrite rule: /zalohook/{bot_id}
		$slug = $bot_id !== null ? '/zalohook/' . rawurlencode( (string) $bot_id ) : '/zalohook/';
		return home_url( $slug );
	}

	public static function admin_page_url(): string {
		return admin_url( 'admin.php?page=bizcity-zalo-bot' );
	}

	/* -- Bot lookup ------------------------------------------------------- */

	/**
	 * Find a bot row whose oa_id matches.
	 * @return array|null
	 */
	public static function lookup_bot_by_oa( string $oa_id ): ?array {
		if ( ! self::is_available() ) { return null; }
		try {
			$db = BizCity_Zalo_Bot_Database::instance();
			if ( ! method_exists( $db, 'get_active_bots' ) ) { return null; }
			foreach ( (array) $db->get_active_bots() as $bot ) {
				$bot = (array) $bot;
				$candidate = (string) ( $bot['oa_id'] ?? $bot['app_id'] ?? '' );
				if ( $candidate !== '' && $candidate === $oa_id ) {
					return $bot;
				}
			}
		} catch ( \Throwable $e ) {
			return null;
		}
		return null;
	}

	/**
	 * Look up a bot by numeric bot_id (when channel_ref_id stores bot_id directly).
	 * @return array|null
	 */
	public static function get_bot( int $bot_id ): ?array {
		if ( ! self::is_available() || $bot_id <= 0 ) { return null; }
		try {
			$db = BizCity_Zalo_Bot_Database::instance();
			if ( ! method_exists( $db, 'get_bot' ) ) { return null; }
			$bot = $db->get_bot( $bot_id );
			return $bot ? (array) $bot : null;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Resolve access_token from either an OA id (string) or a numeric bot_id.
	 */
	public static function lookup_access_token( string $ref ): string {
		if ( ctype_digit( $ref ) ) {
			$bot = self::get_bot( (int) $ref );
			if ( $bot && ! empty( $bot['access_token'] ) ) {
				return (string) $bot['access_token'];
			}
		}
		$bot = self::lookup_bot_by_oa( $ref );
		if ( $bot && ! empty( $bot['access_token'] ) ) {
			return (string) $bot['access_token'];
		}
		// Legacy option fallback.
		return (string) get_option( 'zalo_oa_access_token_' . $ref, '' );
	}

	/* -- Outbound senders ------------------------------------------------- */

	/**
	 * @return array{success:bool, external_source_id:?string, error:?string}
	 */
	public static function send_text( string $token, string $user_id, string $text ): array {
		if ( $token === '' ) {
			return array( 'success' => false, 'external_source_id' => null, 'error' => 'no_access_token' );
		}
		if ( ! class_exists( 'BizCity_Zalo_Bot_API', false ) ) {
			return self::raw_post( $token, array(
				'recipient' => array( 'user_id' => $user_id ),
				'message'   => array( 'text'    => $text ),
			) );
		}
		try {
			// Constructor takes 1 arg only — passing $oa_id breaks future deploys.
			$api = new BizCity_Zalo_Bot_API( $token );
			if ( ! method_exists( $api, 'send_text_message' ) ) {
				return array( 'success' => false, 'external_source_id' => null, 'error' => 'send_text_message missing' );
			}
			$res = $api->send_text_message( $user_id, $text );
			return self::normalize_send_result( $res );
		} catch ( \Throwable $e ) {
			return array( 'success' => false, 'external_source_id' => null, 'error' => $e->getMessage() );
		}
	}

	public static function send_image( string $token, string $user_id, string $image_url ): array {
		if ( $token === '' ) {
			return array( 'success' => false, 'external_source_id' => null, 'error' => 'no_access_token' );
		}
		if ( class_exists( 'BizCity_Zalo_Bot_API', false ) ) {
			try {
				$api = new BizCity_Zalo_Bot_API( $token );
				if ( method_exists( $api, 'send_image_message' ) ) {
					return self::normalize_send_result( $api->send_image_message( $user_id, $image_url ) );
				}
			} catch ( \Throwable $e ) {
				return array( 'success' => false, 'external_source_id' => null, 'error' => $e->getMessage() );
			}
		}
		// Raw fallback — Zalo media template payload.
		return self::raw_post( $token, array(
			'recipient' => array( 'user_id' => $user_id ),
			'message'   => array(
				'attachment' => array(
					'type'    => 'template',
					'payload' => array(
						'template_type' => 'media',
						'elements'      => array(
							array( 'media_type' => 'image', 'url' => $image_url ),
						),
					),
				),
			),
		) );
	}

	private static function raw_post( string $token, array $payload ): array {
		$resp = wp_remote_post( 'https://openapi.zalo.me/v3.0/oa/message/cs', array(
			'headers' => array(
				'access_token' => $token,
				'Content-Type' => 'application/json',
			),
			'timeout' => 15,
			'body'    => wp_json_encode( $payload ),
		) );
		if ( is_wp_error( $resp ) ) {
			return array( 'success' => false, 'external_source_id' => null, 'error' => $resp->get_error_message() );
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		if ( ! is_array( $body ) || ( isset( $body['error'] ) && (int) $body['error'] !== 0 ) ) {
			return array(
				'success'            => false,
				'external_source_id' => null,
				'error'              => isset( $body['message'] ) ? (string) $body['message'] : 'zalo_send_failed',
			);
		}
		return array(
			'success'            => true,
			'external_source_id' => (string) ( $body['data']['message_id'] ?? '' ),
			'error'              => null,
		);
	}

	private static function normalize_send_result( $res ): array {
		if ( is_wp_error( $res ) ) {
			return array( 'success' => false, 'external_source_id' => null, 'error' => $res->get_error_message() );
		}
		// Zalo API returns array shaped { error: 0, message: "Success", data: { message_id: "..." } }
		if ( is_array( $res ) ) {
			if ( isset( $res['error'] ) && (int) $res['error'] !== 0 ) {
				return array(
					'success'            => false,
					'external_source_id' => null,
					'error'              => (string) ( $res['message'] ?? 'zalo_api_error' ),
				);
			}
			$mid = (string) ( $res['data']['message_id'] ?? ( $res['message_id'] ?? '' ) );
			return array( 'success' => true, 'external_source_id' => $mid, 'error' => null );
		}
		return array( 'success' => true, 'external_source_id' => '', 'error' => null );
	}
}
