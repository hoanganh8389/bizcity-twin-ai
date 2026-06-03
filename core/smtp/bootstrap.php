<?php
/**
 * BizCity SMTP Module — wp_mail() bridge over Gmail / generic SMTP
 *
 * Default-on infrastructure module shipped with bizcity-twin-ai.
 * Replaces the legacy mu-plugin `wp-content/mu-plugins/bizcity-smtp-gmail.php`
 * (which can be removed after this module is loaded — see § Migration).
 *
 * ── Configuration precedence (highest → lowest) ─────────────────────
 *   1. PHP constants in `wp-config.php` (BIZCITY_SMTP_*)
 *   2. WP option `bizcity_smtp_settings` (admin-editable, future Channel UI)
 *   3. (none — module no-ops, default `wp_mail()` continues unchanged)
 *
 * ── Required keys ───────────────────────────────────────────────────
 *   host, port, user, pass, from, from_name, secure (tls|ssl|''), auth (1|0)
 *
 * ── Override vs fallback semantics (2026-05-12 update) ─────────────
 * Mu-plugin `bizcity-smtp-gmail.php` (nếu còn) vẫn load TRƯỚC plugin
 * thường — nó register `phpmailer_init` ở priority mặc định (10).
 * Module này register cùng hook ở priority **999** → CHẠY SAU và GHI ĐÈ
 * toàn bộ field trên `$phpmailer` (Host/Port/User/Pass/From/…).
 *
 *   - Nếu `resolve_config()` trả config hợp lệ → core/smtp **override**
 *     mu-plugin (admin có thể đổi credential ngay từ UI, không phải
 *     động vào file mu-plugin).
 *   - Nếu `resolve_config()` trả `null` (chưa cấu hình ở admin / wp-config) →
 *     module **không bind** → mu-plugin tiếp tục hoạt động như cũ
 *     (fallback an toàn — không mất khả năng gửi mail).
 *
 * Hằng `BIZCITY_SMTP_LOADED` được define ở đầu file để các module khác
 * (admin-menu, diagnostics) biết core/smtp đã được nạp; nó KHÔNG còn
 * dùng làm guard early-return nữa.
 *
 * ── Future Channel Settings tie-in (M-CRM.M12 / Channel Settings) ──
 * Admin UI in CRM SPA will write to option `bizcity_smtp_settings`.
 * The module then auto-applies on the next page load — no plugin
 * deactivation/reactivation needed.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\SMTP
 * @since      1.3.8 (2026-05-12)
 */

defined( 'ABSPATH' ) || exit;

// Signal: core/smtp module has been loaded. Other modules (admin-menu
// diagnostics, channel-gateway) check `defined('BIZCITY_SMTP_LOADED')`
// to know the bridge is active. Defined unconditionally so the signal
// reflects "module loaded", not "bind succeeded" — bind only runs if
// `resolve_config()` finds a usable config (else mu-plugin fallback).
if ( ! defined( 'BIZCITY_SMTP_LOADED' ) ) {
	define( 'BIZCITY_SMTP_LOADED', true );
}

if ( ! class_exists( 'BizCity_SMTP' ) ) {

	/**
	 * Thin SMTP bridge — option-driven with constant override fallback.
	 */
	final class BizCity_SMTP {

		const OPTION_KEY = 'bizcity_smtp_settings';

		/**
		 * Resolve final config (constants > option > none).
		 *
		 * @return array{host:string,port:int,user:string,pass:string,from:string,from_name:string,secure:string,auth:bool}|null
		 */
		public static function resolve_config(): ?array {
			$opt = get_option( self::OPTION_KEY, array() );
			$opt = is_array( $opt ) ? $opt : array();

			$cfg = array(
				'host'      => defined( 'BIZCITY_SMTP_HOST' )      ? (string) BIZCITY_SMTP_HOST      : (string) ( $opt['host']      ?? '' ),
				'port'      => defined( 'BIZCITY_SMTP_PORT' )      ? (int)    BIZCITY_SMTP_PORT      : (int)    ( $opt['port']      ?? 587 ),
				'user'      => defined( 'BIZCITY_SMTP_USER' )      ? (string) BIZCITY_SMTP_USER      : (string) ( $opt['user']      ?? '' ),
				'pass'      => defined( 'BIZCITY_SMTP_PASS' )      ? (string) BIZCITY_SMTP_PASS      : (string) ( $opt['pass']      ?? '' ),
				'from'      => defined( 'BIZCITY_SMTP_FROM' )      ? (string) BIZCITY_SMTP_FROM      : (string) ( $opt['from']      ?? '' ),
				'from_name' => defined( 'BIZCITY_SMTP_FROM_NAME' ) ? (string) BIZCITY_SMTP_FROM_NAME : (string) ( $opt['from_name'] ?? get_bloginfo( 'name' ) ),
				'secure'    => defined( 'BIZCITY_SMTP_SECURE' )    ? (string) BIZCITY_SMTP_SECURE    : (string) ( $opt['secure']    ?? 'tls' ),
				'auth'      => defined( 'BIZCITY_SMTP_AUTH' )      ? (bool)   BIZCITY_SMTP_AUTH      : (bool)   ( $opt['auth']      ?? true ),
			);

			// Filter for runtime overrides (e.g. per-tenant in multisite).
			$cfg = apply_filters( 'bizcity_smtp_config', $cfg );

			// Sanity: must have host + user + pass + from to be useful.
			if ( $cfg['host'] === '' || $cfg['user'] === '' || $cfg['pass'] === '' || $cfg['from'] === '' ) {
				return null;
			}

			// Skip placeholder credentials (legacy mu-plugin bail check).
			if ( $cfg['user'] === 'your-email@gmail.com' ) {
				return null;
			}

			return $cfg;
		}

		public static function bind(): void {
			$cfg = self::resolve_config();
			if ( $cfg === null ) {
				return;
			}

			add_action( 'phpmailer_init', static function ( $phpmailer ) use ( $cfg ) {
				$phpmailer->isSMTP();
				$phpmailer->Host       = $cfg['host'];
				$phpmailer->Port       = $cfg['port'];
				$phpmailer->SMTPSecure = $cfg['secure'];
				$phpmailer->SMTPAuth   = $cfg['auth'];
				$phpmailer->Username   = $cfg['user'];
				$phpmailer->Password   = $cfg['pass'];
				$phpmailer->From       = $cfg['from'];
				$phpmailer->FromName   = $cfg['from_name'];
			}, 999 );

			add_filter( 'wp_mail_from',      static function () use ( $cfg ) { return $cfg['from']; }, 999 );
			add_filter( 'wp_mail_from_name', static function () use ( $cfg ) { return $cfg['from_name']; }, 999 );
		}
	}
}

BizCity_SMTP::bind();
