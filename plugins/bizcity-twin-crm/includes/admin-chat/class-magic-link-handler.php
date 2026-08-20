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
	const QUERY_VAR_BLOG    = 'bizcity_blog_id';
	const QUERY_VAR_SSO     = 'bizcity_sso_return';

	public static function register(): void {
		static $registered = false;
		if ( $registered ) {
			return;
		}
		$registered = true;
		// Hook EARLY on `init` so we win over any legacy gitignored bot plugin
		// that may have its own ?bzzalolink= handler firing on init/parse_request
		// and exiting with a generic "Link không hợp lệ" page.
		add_action( 'init', array( __CLASS__, 'maybe_handle' ), 1 );
		// Belt-and-braces: also hook template_redirect very early in case some
		// other plugin short-circuits init.
		add_action( 'template_redirect', array( __CLASS__, 'maybe_handle' ), -100 );
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
		// [2026-08-20 Johnny Chu] HOTFIX-ZALOBOT-LINK - trace the direct username POST before any SSO-related branch is considered.
		if ( ! empty( $_POST['bzml_signon'] ) ) {
			error_log( sprintf( '[BizCity Magic Link] handler_username_post_seen method=%s blog_id=%d', (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ), (int) get_current_blog_id() ) );
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

		// [2026-08-20 Johnny Chu] HOTFIX-ZALOBOT-LINK - switch to the issuing tenant before verify() queries the shard-local token table.
		$callback_blog_id = isset( $_GET[ self::QUERY_VAR_BLOG ] ) ? absint( $_GET[ self::QUERY_VAR_BLOG ] ) : 0;
		$switched         = false;
		if ( is_multisite() && $callback_blog_id > 0 && $callback_blog_id !== (int) get_current_blog_id() ) {
			switch_to_blog( $callback_blog_id );
			$switched = true;
		}

		$result = BizCity_CRM_Magic_Link::verify( $token );
		if ( is_wp_error( $result ) ) {
			if ( $switched ) {
				restore_current_blog();
			}
			self::render_landing( array(
				'state'   => 'error',
				'code'    => $result->get_error_code(),
				'message' => $result->get_error_message(),
			) );
			exit;
		}

		// Switch to the issuing blog (multisite scope safety).
		if ( is_multisite() && (int) $result['blog_id'] !== get_current_blog_id() ) {
			switch_to_blog( (int) $result['blog_id'] );
			$switched = true;
		}

		// [2026-08-01 Johnny Chu] HOTFIX-ZALOBOT-LINK — a browser retry after
		// this user already consumed the token is safe and idempotent. Do not
		// turn a successful bind into a misleading "already used" error page.
		if ( ! empty( $result['_already_consumed_by_current_user'] ) ) {
			// [2026-08-20 Johnny Chu] HOTFIX-ZALOBOT-LINK - replay the canonical bind hook so a prior consume interrupted before identity persistence can self-heal.
			do_action( 'bizcity_crm_magic_link_consumed', $result, get_current_user_id() );
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
			// [2026-08-20 Johnny Chu] HOTFIX-ZALOBOT-URL-LINK — the token-bearing return URL is the explicit login action; no cookie recovery is needed.
			$is_explicit_sso_return = ! empty( $_GET[ self::QUERY_VAR_SSO ] );
			if ( empty( $_POST['bzml_confirm'] ) && $is_explicit_sso_return ) {
				// [2026-08-20 Johnny Chu] HOTFIX-ZALOBOT-URL-LINK — consume the CRM row directly from the URL returned by OAuth.
				$consumed = BizCity_CRM_Magic_Link::consume( (int) $result['id'], $user_id );
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
			// [2026-08-20 Johnny Chu] HOTFIX-ZALOBOT-URL-LINK — keep a direct browser visit read-only until the user confirms.
			error_log( sprintf( '[BizCity Magic Link] consume_waiting_for_confirm row_id=%d explicit_sso_return=%d user_id=%d blog_id=%d', (int) ( $result['id'] ?? 0 ), $is_explicit_sso_return ? 1 : 0, (int) $user_id, (int) get_current_blog_id() ) );
			self::render_landing( array(
				'state' => 'confirm',
				'row'   => $result,
				'token' => $token,
			) );
			exit;
		}

		// CASE B: anonymous → render login landing with token preserved in the SSO return URL.
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
	 * After consume — best-effort bind chat_id ↔ user via legacy linker if available.
	 *
	 * @param array $row
	 * @param int   $user_id
	 */
	public static function on_consumed( $row, $user_id ): void {
		if ( ! is_array( $row ) || ! $user_id ) {
			// [2026-08-20 Johnny Chu] HOTFIX-ZALOBOT-LINK - trace malformed CRM consume payloads.
			error_log( '[BizCity Magic Link] compatibility_bind_skipped reason=invalid_payload' );
			return;
		}
		// [2026-08-20 Johnny Chu] HOTFIX-ZALOBOT-LINK - trace the compatibility listener platform decision.
		error_log( sprintf( '[BizCity Magic Link] compatibility_bind_start platform=%s row_id=%d user_id=%d', strtoupper( (string) ( $row['platform'] ?? '' ) ), (int) ( $row['id'] ?? 0 ), (int) $user_id ) );

		// [2026-08-20 Johnny Chu] HOTFIX-ZALOBOT-URL-LINK — invoke the canonical linker directly when its consume hook was not registered by the current load path.
		$canonical_available = class_exists( 'BizCity_Channel_User_Linker' )
			&& method_exists( 'BizCity_Channel_User_Linker', 'on_magic_link_consumed' )
		;
		$canonical_hooked = false;
		if ( $canonical_available ) {
			$canonical_hooked = function_exists( 'has_action' )
				&& false !== has_action( 'bizcity_crm_magic_link_consumed', array( 'BizCity_Channel_User_Linker', 'on_magic_link_consumed' ) );
			if ( ! $canonical_hooked ) {
				try {
					BizCity_Channel_User_Linker::on_magic_link_consumed( $row, (int) $user_id );
				} catch ( Throwable $e ) {
					error_log( sprintf( '[BizCity Magic Link] canonical_direct_bind_failed row_id=%d reason=%s', (int) ( $row['id'] ?? 0 ), sanitize_key( (string) $e->getMessage() ) ) );
				}
			}
		}

		// [2026-08-20 Johnny Chu] HOTFIX-ZALOBOT-NOTIFY — canonical hook/direct invocation is the sole notification owner when available; never send a second legacy welcome.
		if ( ! $canonical_available
			&& strtoupper( (string) ( $row['platform'] ?? '' ) ) === 'ZALO_BOT'
			&& ! class_exists( 'BizCity_Gateway_Sender' )
			&& class_exists( 'BizCity_Zalobot_User_Linker' )
			&& method_exists( 'BizCity_Zalobot_User_Linker', 'send_welcome_after_link' )
		) {
			$meta     = ! empty( $row['meta_json'] ) ? json_decode( (string) $row['meta_json'], true ) : array();
			$identity = is_array( $meta ) && ! empty( $meta['channel_identity'] ) && is_array( $meta['channel_identity'] )
				? $meta['channel_identity'] : array();
			$fallback_chat_id = (string) ( $identity['external_user_id'] ?? '' );
			$fallback_bot_id  = (int) ( $identity['account_id'] ?? $row['bot_id'] ?? 0 );
			if ( $fallback_chat_id !== '' && $fallback_bot_id > 0 ) {
				try {
					BizCity_Zalobot_User_Linker::send_welcome_after_link( $fallback_bot_id, $fallback_chat_id, (int) $user_id );
					error_log( sprintf( '[BizCity Magic Link] fallback_notify_dispatched row_id=%d bot_id=%d user_hash=%s', (int) ( $row['id'] ?? 0 ), $fallback_bot_id, substr( md5( $fallback_chat_id ), 0, 10 ) ) );
				} catch ( Throwable $e ) {
					error_log( sprintf( '[BizCity Magic Link] fallback_notify_failed row_id=%d reason=%s', (int) ( $row['id'] ?? 0 ), sanitize_key( (string) $e->getMessage() ) ) );
				}
			}
		}

		// Best-effort legacy identity fallback when the canonical Channel Gateway linker is unavailable.
		if ( ! $canonical_available
			&& in_array( strtoupper( (string) ( $row['platform'] ?? '' ) ), array( 'ZALO', 'ZALO_BOT' ), true )
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
			// [2026-08-20 Johnny Chu] HOTFIX-ZALOBOT-LINK - prove whether production renders a theme override or the plugin template.
			error_log( sprintf( '[BizCity Magic Link] landing_template source=theme state=%s path_hash=%s', (string) ( $ctx['state'] ?? '' ), substr( hash( 'sha256', $theme ), 0, 12 ) ) );
			include $theme;
			return;
		}
		$tpl = BIZCITY_CRM_DIR . '/templates/magic-link-landing.php';
		if ( file_exists( $tpl ) ) {
			// [2026-08-20 Johnny Chu] HOTFIX-ZALOBOT-LINK - prove the active landing implementation without logging its server path.
			error_log( sprintf( '[BizCity Magic Link] landing_template source=plugin state=%s', (string) ( $ctx['state'] ?? '' ) ) );
			include $tpl;
			return;
		}
		// Fallback minimal output.
		status_header( 400 );
		echo '<!doctype html><meta charset="utf-8"><title>Link</title><p>'
			. esc_html( $ctx['message'] ?? 'Link không hợp lệ.' ) . '</p>';
	}
}
