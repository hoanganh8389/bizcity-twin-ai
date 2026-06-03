<?php
/**
 * Facebook OAuth bridge between the Channel Gateway and the legacy
 * `bizcity-facebook-bot` plugin's OAuth flow (PHASE 0.37 M4.1).
 *
 * Flow:
 *   1. FE button on a saved facebook_page account → POST /facebook/oauth-start
 *      with { account_uid }.
 *   2. Bridge stashes { account_uid, time } in user meta then returns the
 *      OAuth start URL (`?biz_fb_oauth=user_start&num=0`).
 *   3. Legacy handler runs OAuth, fires `bizcity_fb_oauth_complete` action
 *      and applies `bizcity_fb_oauth_user_redirect` filter.
 *   4. Bridge copies first page's id/name/access_token into the matching
 *      gateway account, parks a result transient, then redirects the user
 *      back to the SPA with ?biz_fb_oauth_status=success.
 *
 * @package    BizCity_Twin_AI
 * @subpackage Channel_Gateway
 * @since      PHASE 0.37 M4.1
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Facebook_Page_OAuth_Bridge {

	private const PENDING_META = 'bizcity_cg_fb_oauth_pending';
	private const RESULT_PFX   = 'bizcity_cg_fb_oauth_result_';
	private const SPA_PAGE     = 'bizchat-gateway-spa';
	private const PENDING_TTL  = 10 * MINUTE_IN_SECONDS;

	/**
	 * Channel Gateway's own public landing slug (replaces legacy `/tool-facebook/`).
	 * Used as default redirect target after FB OAuth when no PENDING_META is present
	 * (e.g. admin-mode OAuth, fallback flow, or pending expired).
	 */
	public const PUBLIC_SLUG   = 'channel';

	public static function init(): void {
		$self = new self();
		add_filter( 'bizcity_fb_oauth_user_redirect', [ $self, 'maybe_override_redirect' ], 10, 4 );
		add_action( 'bizcity_fb_oauth_complete',     [ $self, 'on_oauth_complete' ],       10, 4 );
		add_action( 'rest_api_init',                 [ $self, 'register_routes' ] );

		// Register `/channel/` rewrite + early request handler. Runs at `init`
		// priority 1 so even if downstream code fatals during template_redirect,
		// the OAuth-return bounce still completes.
		add_action( 'init',                          [ $self, 'register_public_slug' ], 5 );
		add_action( 'parse_request',                 [ $self, 'maybe_handle_public_slug' ], 1 );

		// Catch-all bounce for stale `/tool-facebook/` URLs (legacy plugin archived).
		add_action( 'init',                          [ $self, 'maybe_bounce_legacy_landing' ], 1 );
	}

	/**
	 * Register `/channel/` rewrite so the slug is owned by Channel Gateway
	 * (independent of any bundled plugin like bizcity-tool-facebook).
	 */
	public function register_public_slug(): void {
		add_rewrite_rule(
			'^' . self::PUBLIC_SLUG . '/?$',
			'index.php?bizcity_channel_landing=1',
			'top'
		);
		add_filter( 'query_vars', function ( $vars ) {
			$vars[] = 'bizcity_channel_landing';
			return $vars;
		} );

		// One-shot flush so the /channel/ rule shows up the first time this
		// version is loaded. Bumping FLUSH_KEY in code re-triggers the flush.
		$flush_key   = 'bizcity_cg_public_slug_flushed';
		$flush_value = '2026-05-24-channel-v1';
		if ( get_option( $flush_key ) !== $flush_value ) {
			flush_rewrite_rules( false );
			update_option( $flush_key, $flush_value, false );
		}
	}

	/**
	 * Handle `/channel/` requests AS EARLY AS POSSIBLE. We don't actually
	 * render anything: the slug exists purely as an OAuth landing pad. Users
	 * who visit it get bounced into the SPA (so admins see the OAuth result)
	 * or onto the public marketplace page (for visitors who aren't logged in).
	 */
	public function maybe_handle_public_slug( $wp ): void {
		$path = strtok( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), '?' );
		$norm = trim( (string) $path, '/' );
		if ( $norm !== self::PUBLIC_SLUG ) {
			return;
		}
		$this->bounce_landing();
	}

	/**
	 * If the user lands on `/tool-facebook/` (legacy archived plugin) — possibly
	 * with OAuth status query args — redirect them to `/channel/` (canonical) or
	 * directly to the SPA. Runs at `init` priority 1 so it fires BEFORE the
	 * archived plugin's template handler (now missing → would 500).
	 */
	public function maybe_bounce_legacy_landing(): void {
		$path = strtok( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), '?' );
		$norm = trim( (string) $path, '/' );
		if ( $norm !== 'tool-facebook' ) {
			return;
		}
		$this->bounce_landing();
	}

	/**
	 * Shared bounce logic: admins → SPA (with OAuth query preserved); guests →
	 * home. Always exits.
	 */
	private function bounce_landing(): void {
		$status  = isset( $_GET['biz_fb_oauth_status'] ) ? sanitize_text_field( wp_unslash( $_GET['biz_fb_oauth_status'] ) ) : '';
		$count   = isset( $_GET['biz_fb_pages_count'] ) ? absint( $_GET['biz_fb_pages_count'] ) : 0;
		$err     = isset( $_GET['biz_fb_oauth_error'] ) ? sanitize_text_field( wp_unslash( $_GET['biz_fb_oauth_error'] ) ) : '';

		if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
			$args = array();
			if ( $status !== '' ) { $args['biz_fb_oauth_status'] = $status; }
			if ( $count > 0 )     { $args['biz_fb_pages_count']  = $count; }
			if ( $err !== '' )    { $args['biz_fb_oauth_error']  = $err; }
			$base = admin_url( 'admin.php?page=' . self::SPA_PAGE );
			$url  = ( $args ? add_query_arg( $args, $base ) : $base ) . '#/p/facebook_page/pages';
			wp_safe_redirect( $url );
			exit;
		}

		wp_safe_redirect( home_url( '/' ) );
		exit;
	}

	/* ─── REST ─── */

	public function register_routes(): void {
		register_rest_route(
			'bizcity-channel/v1',
			'/facebook/oauth-start',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'rest_start' ],
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'args' => [
					'account_uid' => [
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					],
				],
			]
		);

		register_rest_route(
			'bizcity-channel/v1',
			'/facebook/oauth-result',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'rest_result' ],
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			]
		);
	}

	public function rest_start( WP_REST_Request $request ) {
		$account_uid = (string) $request->get_param( 'account_uid' );
		if ( $account_uid === '' ) {
			return new WP_Error( 'fb_oauth_no_uid', 'account_uid is required.', [ 'status' => 400 ] );
		}

		// Confirm the uid belongs to a facebook_page account on this site.
		if ( ! class_exists( 'BizCity_Integration_Registry' ) ) {
			return new WP_Error( 'fb_oauth_no_registry', 'Integration registry unavailable.', [ 'status' => 500 ] );
		}
		$registry = BizCity_Integration_Registry::instance();
		$accounts = $registry->get_accounts( 'facebook_page' );
		$found    = false;
		foreach ( $accounts as $acc ) {
			if ( ( $acc['_uid'] ?? '' ) === $account_uid ) {
				$found = true;
				break;
			}
		}
		if ( ! $found ) {
			return new WP_Error( 'fb_oauth_no_account', 'Account not found for facebook_page.', [ 'status' => 404 ] );
		}

		if ( ! class_exists( 'BizCity_Facebook_OAuth' ) ) {
			return new WP_Error( 'fb_oauth_unavailable', 'Plugin bizcity-facebook-bot chưa kích hoạt.', [ 'status' => 503 ] );
		}

		// Stash pending hand-off in user meta (per-user, single-pending).
		update_user_meta(
			get_current_user_id(),
			self::PENDING_META,
			[
				'account_uid' => $account_uid,
				'time'        => time(),
				'return_url'  => $this->build_spa_return_url( $account_uid ),
			]
		);

		// Build OAuth start URL. Legacy plugin reads its own App ID/Secret
		// from user meta / WAIC; we just kick off the flow.
		$start_url = home_url( '/?biz_fb_oauth=user_start&num=0' );

		return rest_ensure_response( [
			'ok'           => true,
			'redirect_url' => esc_url_raw( $start_url ),
		] );
	}

	/**
	 * Pop (read-and-delete) the OAuth result transient for the current user.
	 * Called by the SPA on mount when ?biz_fb_oauth_status=success is in URL.
	 */
	public function rest_result( WP_REST_Request $request ) {
		$key = self::RESULT_PFX . get_current_user_id();
		$res = get_site_transient( $key );
		delete_site_transient( $key );
		if ( ! is_array( $res ) ) {
			return rest_ensure_response( [ 'has_result' => false ] );
		}
		return rest_ensure_response( array_merge( [ 'has_result' => true ], $res ) );
	}

	/* ─── OAuth completion hooks ─── */

	/**
	 * Override the legacy redirect (`/tool-facebook/`) to bounce the user
	 * back to the Channel Gateway SPA instead.
	 *
	 * - If PENDING_META is present: bounce to the original `return_url` (SPA
	 *   pages tab, optionally with edit hash).
	 * - Otherwise: bounce to `/channel/` so user NEVER lands on the archived
	 *   `/tool-facebook/` slug.
	 */
	public function maybe_override_redirect( $url, $user_id, $pages, $blog_id ) {
		$ok    = ! empty( $pages );
		$count = is_array( $pages ) ? count( $pages ) : 0;

		$pending = get_user_meta( $user_id, self::PENDING_META, true );
		$has_pending = is_array( $pending )
			&& ! empty( $pending['account_uid'] )
			&& ! empty( $pending['return_url'] )
			&& ( time() - (int) ( $pending['time'] ?? 0 ) ) <= self::PENDING_TTL;

		$base = $has_pending
			? $pending['return_url']
			: home_url( '/' . self::PUBLIC_SLUG . '/' );

		return add_query_arg(
			[
				'biz_fb_oauth_status' => $ok ? 'success' : 'error',
				'biz_fb_pages_count'  => $count,
			],
			$base
		);
	}

	/**
	 * On OAuth complete (user mode), copy the FIRST page's credentials
	 * into the pending gateway account row.
	 */
	public function on_oauth_complete( $pages, $blog_id, $user_id, $app_source ): void {
		if ( $app_source !== 'user' ) {
			return; // Admin-mode (Phương án A) bypasses the gateway hand-off.
		}
		$pending = get_user_meta( $user_id, self::PENDING_META, true );
		if ( empty( $pending['account_uid'] ) ) {
			return;
		}
		$account_uid = (string) $pending['account_uid'];
		$pending_ts  = (int) ( $pending['time'] ?? 0 );

		// Clear the meta no matter what — single-shot hand-off.
		delete_user_meta( $user_id, self::PENDING_META );

		if ( ( time() - $pending_ts ) > self::PENDING_TTL ) {
			$this->set_result( $user_id, [ 'ok' => false, 'error' => 'OAuth pending expired.', 'account_uid' => $account_uid ] );
			return;
		}
		if ( empty( $pages[0] ) || ! is_array( $pages[0] ) ) {
			$this->set_result( $user_id, [ 'ok' => false, 'error' => 'OAuth không trả về page nào.', 'account_uid' => $account_uid ] );
			return;
		}
		if ( ! class_exists( 'BizCity_Integration_Registry' ) ) {
			return;
		}

		$page  = $pages[0];
		$patch = [
			'_uid'              => $account_uid,
			'page_id'           => (string) ( $page['id'] ?? '' ),
			'page_name'         => (string) ( $page['name'] ?? '' ),
			'page_access_token' => (string) ( $page['access_token'] ?? '' ),
			// Mirror page_id into instance_id so the gateway routes inbound
			// webhooks to this account.
			'instance_id'       => (string) ( $page['id'] ?? '' ),
		];

		// Make sure we operate on the right subsite when on multisite.
		$switched = false;
		if ( is_multisite() && $blog_id && get_current_blog_id() !== (int) $blog_id ) {
			switch_to_blog( (int) $blog_id );
			$switched = true;
		}

		$registry = BizCity_Integration_Registry::instance();
		$result   = $registry->save_channel_account( 'facebook_page', $patch );

		if ( $switched ) {
			restore_current_blog();
		}

		if ( is_wp_error( $result ) ) {
			$this->set_result( $user_id, [
				'ok'          => false,
				'error'       => $result->get_error_message(),
				'account_uid' => $account_uid,
			] );
			return;
		}
		$this->set_result( $user_id, [
			'ok'          => true,
			'account_uid' => $account_uid,
			'page_id'     => $patch['page_id'],
			'page_name'   => $patch['page_name'],
		] );
	}

	/* ─── Helpers ─── */

	/**
	 * SPA URL to bounce back into: settings tab of the Facebook platform
	 * workspace, with a hint to highlight the edited account.
	 */
	private function build_spa_return_url( string $account_uid ): string {
		$base = admin_url( 'admin.php?page=' . self::SPA_PAGE );
		// Land on the Pages tab so user sees the freshly-connected page.
		$hash = '#/p/facebook_page/pages?edit=' . rawurlencode( $account_uid );
		return $base . $hash;
	}

	private function set_result( int $user_id, array $payload ): void {
		set_site_transient( self::RESULT_PFX . $user_id, $payload, 5 * MINUTE_IN_SECONDS );
	}
}

BizCity_Facebook_Page_OAuth_Bridge::init();
