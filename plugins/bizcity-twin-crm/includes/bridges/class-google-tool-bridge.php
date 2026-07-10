<?php
/**
 * BizCity CRM — Google Tool Bridge.
 *
 * Wraps `bizgpt-tool-google` so the CRM Email adapter can use Gmail OAuth as
 * an alternative to plain IMAP. Centralises all access to BZGoogle_*
 * classes — adapters MUST go through this bridge.
 *
 * Resolution model:
 *   - get_token()  is keyed by (blog_id, user_id, [google_email])
 *   - For an inbox row, we map `settings_json.google_account_user_id` → user_id
 *     so multiple agents can each connect their own Gmail to one inbox row.
 *
 * @package BizCity_Twin_CRM
 * @since   PHASE 0.35 (M7.W5.task-1)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_CRM_Bridge_Google {

	const BRIDGE_API_VERSION = '1.0.0';

	public static function is_available(): bool {
		return defined( 'BZGOOGLE_VERSION' )
			&& class_exists( 'BZGoogle_Token_Store', false )
			&& class_exists( 'BZGoogle_Google_Service', false );
	}

	public static function admin_page_url(): string {
		return admin_url( 'admin.php?page=' . ( defined( 'BZGOOGLE_SLUG' ) ? BZGOOGLE_SLUG : 'bizgpt-tool-google' ) );
	}

	public static function connect_url(): string {
		// Front-end Google Studio page — user clicks "Connect Google" there.
		return home_url( '/tool-google/' );
	}

	/* -- Account discovery ----------------------------------------------- */

	/**
	 * List Google accounts the current (or a specified) WP user has connected.
	 *
	 * @return array<int, array{id:int, google_email:string, status:string, expires_at:string}>
	 */
	public static function list_oauth_accounts( ?int $user_id = null ): array {
		if ( ! self::is_available() ) { return array(); }
		$uid = $user_id ?? get_current_user_id();
		if ( $uid <= 0 ) { return array(); }
		$blog_id = get_current_blog_id();
		try {
			$rows = BZGoogle_Token_Store::get_accounts( $blog_id, $uid );
		} catch ( \Throwable $e ) {
			return array();
		}
		$out = array();
		foreach ( (array) $rows as $r ) {
			$r = (array) $r;
			$out[] = array(
				'id'           => (int) ( $r['id'] ?? 0 ),
				'google_email' => (string) ( $r['google_email'] ?? '' ),
				'status'       => (string) ( $r['status'] ?? '' ),
				'expires_at'   => (string) ( $r['expires_at'] ?? '' ),
			);
		}
		return $out;
	}

	/**
	 * Test that a usable token exists for (blog, user, email).
	 *
	 * @return array{ok:bool, error?:string, expires_at?:string}
	 */
	public static function test_token( int $user_id, string $google_email = '' ): array {
		if ( ! self::is_available() ) {
			return array( 'ok' => false, 'error' => 'bizgpt-tool-google plugin not active' );
		}
		try {
			$blog_id = get_current_blog_id();
			$tok = BZGoogle_Token_Store::get_token( $blog_id, $user_id, $google_email );
			if ( ! $tok ) {
				return array( 'ok' => false, 'error' => 'No Google token. Click Connect Google.' );
			}
			return array( 'ok' => true, 'expires_at' => (string) ( $tok['expires_at'] ?? '' ) );
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'error' => $e->getMessage() );
		}
	}

	/* -- Gmail send / list ----------------------------------------------- */

	/**
	 * Send an email through Gmail API on behalf of a connected account.
	 *
	 * @return array{success:bool, external_source_id:?string, error:?string}
	 */
	public static function gmail_send( int $user_id, string $google_email, array $args ): array {
		if ( ! self::is_available() ) {
			return array( 'success' => false, 'external_source_id' => null, 'error' => 'google_bridge_unavailable' );
		}
		try {
			$blog_id = get_current_blog_id();
			// Service resolves the most-recently-used account for (blog, user)
			// when google_email is empty; otherwise it filters by email.
			if ( $google_email !== '' ) {
				// Force the desired account by re-saving — light no-op token bump
				// is unnecessary here because get_token($blog,$user,$email) is
				// honoured below via the same filter chain. We rely on
				// gmail_send($blog,$user,$args) which currently picks the
				// newest account for the user; for now we accept that limit
				// and document it.
				_doing_it_wrong(
					__METHOD__,
					'gmail_send currently uses the most-recent Google account for the user; multi-account selection lands in M7.W5.task-4 follow-up.',
					'PHASE 0.35'
				);
			}
			$res = BZGoogle_Google_Service::gmail_send( $blog_id, $user_id, $args );
			if ( is_wp_error( $res ) ) {
				return array( 'success' => false, 'external_source_id' => null, 'error' => $res->get_error_message() );
			}
			$mid = '';
			if ( is_array( $res ) ) {
				$mid = (string) ( $res['id'] ?? ( $res['threadId'] ?? '' ) );
			}
			return array( 'success' => true, 'external_source_id' => $mid !== '' ? ( 'gmail:' . $mid ) : '', 'error' => null );
		} catch ( \Throwable $e ) {
			return array( 'success' => false, 'external_source_id' => null, 'error' => $e->getMessage() );
		}
	}

	/**
	 * Pull recent Gmail messages for inbox polling.
	 *
	 * @return array<int, array> raw Gmail message dicts (see BZGoogle_Google_Service::gmail_list)
	 */
	public static function gmail_poll( int $user_id, array $args = array() ): array {
		if ( ! self::is_available() ) { return array(); }
		try {
			$blog_id = get_current_blog_id();
			$res = BZGoogle_Google_Service::gmail_list( $blog_id, $user_id, $args );
			if ( is_wp_error( $res ) || ! is_array( $res ) ) { return array(); }
			return $res;
		} catch ( \Throwable $e ) {
			return array();
		}
	}
}
