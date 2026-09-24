<?php
/**
 * Bot Studio — real Apify Actor calls for the `scrape_social_data` tool (PHASE-0.60F OW-4, doc §6.1 G-10).
 *
 * A genuine HTTP call to Apify's `run-sync-get-dataset-items` endpoint using the character's
 * own token + per-platform Actor ID (0.60E D-E1/D-E3) — no mock/fake-success path.
 *
 * Confidence note (read before trusting this against a live token — same discipline as
 * class-bot-media-client.php's music path): Apify Actors are community-published and each one
 * defines its OWN input JSON schema. This client sends the single most common convention across
 * Apify's own scraper templates — `{"startUrls": [{"url": "..."}]}` — because that is what the
 * three example Actors referenced in the config UI (apify/facebook-pages-scraper,
 * clockworks/tiktok-scraper, streamers/youtube-scraper) are documented to accept. It is NOT
 * guaranteed for an arbitrary community Actor a site operator points this at. A run against an
 * Actor with a different input contract will fail with a real Apify validation error, not a
 * silent wrong answer — but this has not been smoke-tested against a live, funded Apify account.
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway\Bot
 * @since PHASE-0.60F OW-4 (2026-09-23)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Bot_Apify_Client {

	/** Apify's own sync-run wait cap is 300s server-side; this bounds our own HTTP wait well under that so a stuck Actor fails fast instead of hanging the bot turn. */
	const RUN_TIMEOUT_SECONDS  = 90;
	const HTTP_TIMEOUT_SECONDS = self::RUN_TIMEOUT_SECONDS + 15;
	const MAX_ITEMS            = 5;

	const PLATFORM_ACTOR_FIELD = array(
		'facebook' => 'actor_facebook',
		'tiktok'   => 'actor_tiktok',
		'youtube'  => 'actor_youtube',
		'shopee'   => 'actor_shopee',
	);

	/**
	 * @param int    $character_id
	 * @param string $platform  one of PLATFORM_ACTOR_FIELD's keys
	 * @param string $url       the public page/profile/video URL to scrape
	 * @return array{ok:true,items:array,total_returned:int}|WP_Error
	 */
	public static function scrape( int $character_id, string $platform, string $url ) {
		// [2026-09-23 Claude Sonnet 5] PHASE-0.60F OW-4 — cheap input validation runs BEFORE the
		// module-dependency check: a bad platform/URL should say so regardless of what else is or
		// isn't loaded, and it keeps these branches unit-testable without needing Config/Secrets
		// Repo (and therefore $wpdb) present at all.
		if ( $character_id <= 0 ) {
			return new WP_Error( 'invalid_param', 'Thiếu character_id.', array( 'status' => 422, 'help_code' => 'bot_apify_character_required' ) );
		}
		$actor_field = self::PLATFORM_ACTOR_FIELD[ $platform ] ?? '';
		if ( '' === $actor_field ) {
			return new WP_Error( 'invalid_param', 'Nền tảng không hỗ trợ: ' . $platform . '.', array( 'status' => 422, 'help_code' => 'bot_apify_platform_unknown' ) );
		}
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			return new WP_Error( 'invalid_param', 'URL không hợp lệ.', array( 'status' => 422, 'help_code' => 'bot_apify_url_invalid' ) );
		}
		if ( ! class_exists( 'BizCity_Bot_Config_Repo' ) || ! class_exists( 'BizCity_Bot_Secrets_Repo' ) ) {
			return new WP_Error( 'module_not_loaded', 'Bot Studio chưa sẵn sàng.', array( 'status' => 503, 'help_code' => 'module_not_loaded' ) );
		}

		$media_cfg = BizCity_Bot_Config_Repo::get( $character_id )['media']['apify'] ?? array();
		$actor_id  = trim( (string) ( $media_cfg[ $actor_field ] ?? '' ) );
		if ( '' === $actor_id ) {
			return new WP_Error( 'bot_apify_actor_missing', 'Chưa cấu hình Actor ID cho ' . $platform . '.', array( 'status' => 422, 'help_code' => 'bot_apify_actor_missing' ) );
		}
		$token = BizCity_Bot_Secrets_Repo::get_value( $character_id, 'apify_token' );
		if ( '' === $token ) {
			return new WP_Error( 'bot_provider_key_missing', 'Chưa có Apify token.', array( 'status' => 422, 'help_code' => 'bot_apify_token_missing' ) );
		}

		$endpoint = 'https://api.apify.com/v2/acts/' . rawurlencode( $actor_id )
			. '/run-sync-get-dataset-items?token=' . rawurlencode( $token )
			. '&timeout=' . self::RUN_TIMEOUT_SECONDS;

		if ( ! function_exists( 'wp_remote_post' ) ) {
			return new WP_Error( 'module_not_loaded', 'HTTP client chưa sẵn sàng.', array( 'status' => 503, 'help_code' => 'bot_apify_http_missing' ) );
		}
		$response = wp_remote_post( $endpoint, array(
			'timeout' => self::HTTP_TIMEOUT_SECONDS,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array( 'startUrls' => array( array( 'url' => $url ) ) ) ),
		) );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'provider_error', $response->get_error_message(), array( 'status' => 502, 'help_code' => 'bot_apify_http_error' ) );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = (string) wp_remote_retrieve_body( $response );
		if ( $status < 200 || $status >= 300 ) {
			$decoded = json_decode( $raw, true );
			$message = is_array( $decoded ) ? (string) ( $decoded['error']['message'] ?? '' ) : '';
			return new WP_Error(
				in_array( $status, array( 401, 403 ), true ) ? 'bot_provider_key_missing' : 'provider_error',
				'' !== $message ? $message : ( 'Apify trả lỗi HTTP ' . $status . '.' ),
				array( 'status' => 502, 'http_status' => $status, 'help_code' => 'bot_apify_provider_error' )
			);
		}
		$items = json_decode( $raw, true );
		if ( ! is_array( $items ) ) {
			return new WP_Error( 'provider_error', 'Apify trả phản hồi không hợp lệ (không phải mảng dataset item).', array( 'status' => 502, 'help_code' => 'bot_apify_invalid_response' ) );
		}
		return array(
			'ok'             => true,
			'items'          => array_slice( $items, 0, self::MAX_ITEMS ),
			'total_returned' => count( $items ),
		);
	}
}
