<?php
/**
 * Bot Studio — AI source resolution: BizCity 1API vs external API (PHASE-0.60C).
 *
 * MVP (doc 0.60C §2.3): the choice lives at SITE level in the options
 * `core/bizcity-llm` already owns (`bizcity_llm_mode` = gateway|direct,
 * `bizcity_llm_api_key`). Per-character override stays `inherit`, so this class
 * never stores a key and never opens an HTTP client — it only decides, and the
 * decision feeds `BizCity_LLM_Client`, which already branches on get_mode().
 *
 * Four branches (doc 0.60C §4), kept as a contract so the per-character
 * override can be switched on later without redesign:
 *   1. direct  + own key present → use override
 *   2. direct  + own key missing → IGNORE override, fall through to (4), badge "thiếu khóa"
 *   3. gateway                  → force 1API regardless of site mode
 *   4. inherit (DEFAULT)        → site mode (default gateway = 1API)
 *
 * Invariant pinned by tests: a gateway key is never sent to an external base
 * URL and vice versa — (2) is the branch that guarantees it.
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway\Bot
 * @since PHASE-0.60C (2026-09-23)
 */

// [2026-09-23 03:15 PM Claude Fable 5.1] PHASE-0.60C D1.3/D1.4/D1.6 — site-level dual choice, no new option, no new HTTP client.
defined( 'ABSPATH' ) || exit;

final class BizCity_Bot_Provider {

	const MODE_INHERIT = 'inherit';
	const MODE_GATEWAY = 'gateway';
	const MODE_DIRECT  = 'direct';

	/** Services the bot may want that 1API does not offer today (doc 0.60C §3.1). */
	const GATEWAY_GAPS = array( 'tts', 'stt', 'create_music' );

	/**
	 * Resolve the effective source for one turn.
	 *
	 * @param array $override  { provider_mode?, has_own_key?, base_url?, model? } — MVP callers pass [] (inherit).
	 * @param string $site_mode 'gateway'|'direct' (defaults to the option `bizcity_llm_mode`).
	 * @return array{mode:string,source:string,branch:int,override_ignored:bool,badge:string}
	 */
	public static function effective( array $override = array(), string $site_mode = '' ): array {
		if ( $site_mode === '' ) {
			$site_mode = function_exists( 'get_option' ) ? (string) get_option( 'bizcity_llm_mode', 'gateway' ) : 'gateway';
		}
		$site_mode = $site_mode === self::MODE_DIRECT ? self::MODE_DIRECT : self::MODE_GATEWAY;
		$mode      = isset( $override['provider_mode'] ) ? sanitize_key( (string) $override['provider_mode'] ) : self::MODE_INHERIT;
		if ( ! in_array( $mode, array( self::MODE_INHERIT, self::MODE_GATEWAY, self::MODE_DIRECT ), true ) ) {
			$mode = self::MODE_INHERIT;
		}

		if ( self::MODE_DIRECT === $mode ) {
			if ( ! empty( $override['has_own_key'] ) ) {
				return array( 'mode' => self::MODE_DIRECT, 'source' => 'character', 'branch' => 1, 'override_ignored' => false, 'badge' => '' );
			}
			// Branch 2 — never forward the site key to a foreign base URL; fall back to site config and say so.
			return array( 'mode' => $site_mode, 'source' => 'site', 'branch' => 2, 'override_ignored' => true, 'badge' => 'thiếu khóa' );
		}
		if ( self::MODE_GATEWAY === $mode ) {
			return array( 'mode' => self::MODE_GATEWAY, 'source' => 'character', 'branch' => 3, 'override_ignored' => false, 'badge' => '' );
		}
		return array( 'mode' => $site_mode, 'source' => 'site', 'branch' => 4, 'override_ignored' => false, 'badge' => '' );
	}

	/**
	 * End-anchored host match (D1.6): "api.googleapis.com.evil.example" must NOT match "googleapis.com".
	 */
	public static function host_matches( string $url, string $allowed_host ): bool {
		$host = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );
		$allowed_host = strtolower( trim( $allowed_host ) );
		if ( $host === '' || $allowed_host === '' ) {
			return false;
		}
		if ( $host === $allowed_host ) {
			return true;
		}
		$suffix = '.' . $allowed_host;
		return substr( $host, -strlen( $suffix ) ) === $suffix;
	}

	/** Which requested capabilities are not available on the gateway (1API) today. */
	public static function gateway_gaps( array $requested_tools ): array {
		return array_values( array_intersect( array_map( 'strval', $requested_tools ), self::GATEWAY_GAPS ) );
	}

	/**
	 * Read-only status for the UI cards (doc 0.60C §6). Never returns a key.
	 */
	public static function site_status(): array {
		$mode = self::effective();
		$has_key = false;
		$gateway_host = '';
		if ( class_exists( 'BizCity_LLM_Client' ) ) {
			try {
				$client  = BizCity_LLM_Client::instance();
				$has_key = trim( (string) $client->get_api_key() ) !== '';
				$gateway_host = (string) parse_url( (string) $client->get_gateway_url(), PHP_URL_HOST );
			} catch ( \Throwable $e ) {
				$has_key = false;
			}
		}
		return array(
			'site_mode'      => $mode['mode'],
			'branch'         => $mode['branch'],
			'has_key'        => $has_key,
			'gateway_host'   => $gateway_host,
			'gateway_gaps'   => self::GATEWAY_GAPS,
			'override_level' => 'site', // MVP — per-character override is post-MVP (doc 0.60C §2.3).
			'settings_hint'  => 'Đổi nguồn AI ở trang Cài đặt BizCity LLM của site (R-1API-9); Guru không giữ khóa riêng.',
		);
	}
}
