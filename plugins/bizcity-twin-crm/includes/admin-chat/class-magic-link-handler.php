<?php
/**
 * BizCity CRM — Magic Link landing handler.
 *
 * PHASE 3.5 — Admin Chat (Wave A).
 *
 * Hooks into template_redirect to catch:
 *   - ?bzzalolink=<token>   (new Phase 3.5 flow)
 *   - ?zid=<token>          (alias for forward-compat — only if token is base64url
 *                            length matching new format; legacy AES tokens fall
 *                            through to existing [zalo_login_form] shortcode)
 *
 * @package BizCity_Twin_CRM
 */

defined( 'ABSPATH' ) || exit;

class BizCity_CRM_Magic_Link_Handler {

	const QUERY_VAR_PRIMARY = 'bzzalolink';
	const QUERY_VAR_ALIAS   = 'zid';
	const RETURN_COOKIE      = 'bizcity_crm_magic_link_return';

	public static function register(): void {
		// Hook EARLY on `init` so we win over any legacy gitignored bot plugin
		// that may have its own ?bzzalolink= handler firing on init/parse_request
		// and exiting with a generic "Link không hợp lệ" page.
		add_action( 'init', array( __CLASS__, 'maybe_handle' ), 1 );
		// Belt-and-braces: also hook template_redirect very early in case some
		// other plugin short-circuits init.
		add_action( 'template_redirect', array( __CLASS__, 'maybe_handle' ), -100 );
		// [2026-08-13 Johnny Chu] HOTFIX-MAGIC-LINK-SSO-RETURN — consume the token when SSO returns to my-account instead of the original landing URL.
		add_action( 'template_redirect', array( __CLASS__, 'maybe_consume_return_cookie' ), -90 );
		add_action( 'bizcity_crm_magic_link_consumed', array( __CLASS__, 'on_consumed' ), 10, 2 );
	}

	public static function maybe_handle(): void {
		static $handled = false;
		if ( $handled ) { return; }

		// Skip admin/cron/REST/CLI surfaces — magic-link is a public landing only.
		if ( is_admin() || ( defined( 'DOING_CRON' ) && DOING_CRON )
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			|| ( defined( 'WP_CLI' ) && WP_CLI )
		) {
			return;
		}

		$token = '';
		if ( ! empty( $_GET[ self::QUERY_VAR_PRIMARY ] ) ) {
			$token = sanitize_text_field( wp_unslash( $_GET[ self::QUERY_VAR_PRIMARY ] ) );
		} elseif ( ! empty( $_GET[ self::QUERY_VAR_ALIAS ] ) ) {
			// Only handle ?zid= if it looks like our base64url token (>= 40 chars,
			// charset [A-Za-z0-9_-]). Legacy AES tokens contain '+'/'/'/'=' or are
			// shorter — let the existing [zalo_login_form] shortcode handle those.
			$candidate = (string) wp_unslash( $_GET[ self::QUERY_VAR_ALIAS ] );
			if ( strlen( $candidate ) >= 40 && preg_match( '/^[A-Za-z0-9_-]+$/', $candidate ) ) {
				$token = $candidate;
			}
		}
		if ( $token === '' ) {
			return;
		}
		// [2026-08-01 Johnny Chu] HOTFIX-ZALOBOT-LINK — the init:1 landing
		// handler must not exit before the anonymous login form can process its
		// POST. Let the same handler run again at template_redirect, where the
		// landing template owns the sign-on form and token consume.
		if ( ! is_user_logged_in()
			&& 'POST' === strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) )
			&& ! empty( $_POST['bzml_signon'] )
			&& did_action( 'template_redirect' ) === 0 ) {
			return;
		}

		$handled = true;
		nocache_headers();
		header( 'Referrer-Policy: no-referrer' );

		$result = BizCity_CRM_Magic_Link::verify( $token );
		if ( is_wp_error( $result ) ) {
			self::render_landing( array(
				'state'   => 'error',
				'code'    => $result->get_error_code(),
				'message' => $result->get_error_message(),
			) );
			exit;
		}

		// Switch to the issuing blog (multisite scope safety).
		$switched = false;
		if ( is_multisite() && (int) $result['blog_id'] !== get_current_blog_id() ) {
			switch_to_blog( (int) $result['blog_id'] );
			$switched = true;
		}

		// [2026-08-01 Johnny Chu] HOTFIX-ZALOBOT-LINK — a browser retry after
		// this user already consumed the token is safe and idempotent. Do not
		// turn a successful bind into a misleading "already used" error page.
		if ( ! empty( $result['_already_consumed_by_current_user'] ) ) {
			if ( $switched ) {
				restore_current_blog();
			}
			wp_safe_redirect( self::success_redirect_url( $result, get_current_user_id() ) );
			exit;
		}

		// [2026-08-01 Johnny Chu] HOTFIX-ZALOBOT-LINK — only an explicit POST
		// confirmation may consume a token. A GET must remain read-only so browser
		// prefetch/scanners cannot burn a valid Zalo linker before the user acts.
		if ( is_user_logged_in() ) {
			$user_id = get_current_user_id();
			// [2026-08-13 Johnny Chu] HOTFIX-MAGIC-LINK-SSO-RETURN — authenticated SSO return is the explicit link action; consume without a second confirmation click.
			if ( empty( $_POST['bzml_confirm'] ) && self::return_cookie_matches( $token ) ) {
				$consumed = BizCity_CRM_Magic_Link::consume( (int) $result['id'], $user_id );
				self::clear_return_cookie();
				if ( $switched ) {
					restore_current_blog();
				}
				if ( $consumed ) {
					wp_safe_redirect( self::success_redirect_url( $result, $user_id ) );
					exit;
				}
				self::render_landing( array(
					'state'   => 'error',
					'code'    => 'bizcity_crm_magic_link_consume_failed',
					'message' => 'Đăng nhập thành công nhưng chưa ghi được liên kết Zalo. Vui lòng yêu cầu link mới.',
				) );
				exit;
			}
			if ( ! empty( $_POST['bzml_confirm'] ) ) {
				check_admin_referer( 'bzml_confirm_' . substr( hash( 'sha256', $token ), 0, 16 ) );
				$consumed = BizCity_CRM_Magic_Link::consume( (int) $result['id'], $user_id );
				self::clear_return_cookie();
				if ( $switched ) {
					restore_current_blog();
				}
				if ( $consumed ) {
					wp_safe_redirect( self::success_redirect_url( $result, $user_id ) );
					exit;
				}
				self::render_landing( array(
					'state'   => 'error',
					'code'    => 'bizcity_crm_magic_link_consume_failed',
					'message' => 'Không thể xác nhận liên kết. Vui lòng yêu cầu link mới.',
				) );
				exit;
			}
			self::render_landing( array(
				'state' => 'confirm',
				'row'   => $result,
				'token' => $token,
			) );
			exit;
		}

		// CASE B: anonymous → render login landing with token preserved in form.
		self::set_return_cookie( $token );
		self::render_landing( array(
			'state' => 'login',
			'row'   => $result,
			'token' => $token,
		) );
		if ( $switched ) {
			restore_current_blog();
		}
		exit;
	}

	/**
	 * Recover a token when an SSO callback redirects to my-account.
	 */
	public static function maybe_consume_return_cookie(): void {
		if ( ! is_user_logged_in() || ! empty( $_GET[ self::QUERY_VAR_PRIMARY ] ) ) {
			return;
		}
		$token = isset( $_COOKIE[ self::RETURN_COOKIE ] )
			? sanitize_text_field( wp_unslash( $_COOKIE[ self::RETURN_COOKIE ] ) )
			: '';
		if ( $token === '' ) {
			return;
		}
		self::clear_return_cookie();
		$result = BizCity_CRM_Magic_Link::verify( $token );
		if ( is_wp_error( $result ) ) {
			return;
		}
		$user_id  = get_current_user_id();
		$switched = false;
		if ( is_multisite() && (int) $result['blog_id'] !== get_current_blog_id() ) {
			switch_to_blog( (int) $result['blog_id'] );
			$switched = true;
		}
		$consumed = BizCity_CRM_Magic_Link::consume( (int) $result['id'], $user_id );
		if ( $switched ) {
			restore_current_blog();
		}
		if ( $consumed ) {
			wp_safe_redirect( self::success_redirect_url( $result, $user_id ) );
			exit;
		}
	}

	public static function clear_return_cookie(): void {
		if ( headers_sent() ) {
			return;
		}
		setcookie( self::RETURN_COOKIE, '', time() - 3600, '/', '', is_ssl(), true );
	}

	private static function set_return_cookie( string $token ): void {
		if ( $token === '' || headers_sent() ) {
			return;
		}
		setcookie( self::RETURN_COOKIE, $token, time() + 1800, '/', '', is_ssl(), true );
	}

	private static function return_cookie_matches( string $token ): bool {
		$cookie = isset( $_COOKIE[ self::RETURN_COOKIE ] )
			? sanitize_text_field( wp_unslash( $_COOKIE[ self::RETURN_COOKIE ] ) )
			: '';
		return $cookie !== '' && hash_equals( hash( 'sha256', $token ), hash( 'sha256', $cookie ) );
	}

	/**
	 * After consume — best-effort bind chat_id ↔ user via legacy linker if available.
	 *
	 * @param array $row
	 * @param int   $user_id
	 */
	public static function on_consumed( $row, $user_id ): void {
		if ( ! is_array( $row ) || ! $user_id ) {
			return;
		}

		// Best-effort: if Zalo linker is available (gitignored bot plugin) — call it
		// to bind chat_id ↔ wp_user. This is a pure mapping (NOT a privilege grant).
		if ( strtoupper( (string) ( $row['platform'] ?? '' ) ) === 'ZALO'
			&& class_exists( 'BizCity_Zalobot_User_Linker' )
			&& method_exists( 'BizCity_Zalobot_User_Linker', 'link' )
		) {
			// [2026-08-06 Johnny Chu] HOTFIX-ZALOBOT-LINK — use channel_identity metadata so legacy compatibility never stores the composed chat_id as the Zalo user id.
			$meta     = ! empty( $row['meta_json'] ) ? json_decode( (string) $row['meta_json'], true ) : array();
			$identity = is_array( $meta ) && ! empty( $meta['channel_identity'] ) && is_array( $meta['channel_identity'] )
				? $meta['channel_identity'] : array();
			$legacy_chat_id = (string) ( $identity['external_user_id'] ?? $row['chat_id'] ?? '' );
			$legacy_bot_id  = (string) ( $identity['account_id'] ?? $row['bot_id'] ?? '' );
			try {
				BizCity_Zalobot_User_Linker::link(
					$legacy_chat_id,
					$legacy_bot_id,
					(int) $user_id
				);
			} catch ( Throwable $e ) {
				// silent — audit row already records consume.
			}
		}

		// PHASE 3.5 Wave B — issue admin-chat grant (auto-grant heuristic).
		if ( class_exists( 'BizCity_CRM_Admin_Chat_Grants' ) ) {
			BizCity_CRM_Admin_Chat_Grants::on_magic_link_consumed( $row, (int) $user_id );
		}

		// SECURITY: do NOT touch global_user_admin / user_level here. Privilege
		// elevation must be opt-in via admin UI (Wave B grants table).
	}

	private static function success_redirect_url( array $row, int $user_id ): string {
		$default = home_url( '/my-account/?welcome=1&platform=' . rawurlencode( strtolower( $row['platform'] ) ) );
		/**
		 * Filter the post-consume redirect URL.
		 *
		 * @param string $url
		 * @param array  $row
		 * @param int    $user_id
		 */
		return (string) apply_filters( 'bizcity_crm_magic_link_redirect', $default, $row, $user_id );
	}

	private static function render_landing( array $ctx ): void {
		// Allow theme overrides via locate_template().
		$theme = locate_template( array( 'bizcity-magic-link-landing.php' ) );
		if ( $theme ) {
			include $theme;
			return;
		}
		$tpl = BIZCITY_CRM_DIR . '/templates/magic-link-landing.php';
		if ( file_exists( $tpl ) ) {
			include $tpl;
			return;
		}
		// Fallback minimal output.
		status_header( 400 );
		echo '<!doctype html><meta charset="utf-8"><title>Link</title><p>'
			. esc_html( $ctx['message'] ?? 'Link không hợp lệ.' ) . '</p>';
	}
}
