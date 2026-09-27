<?php
/**
 * Channel Menu Migration — PHASE 0.37 M1.W2
 *
 * Re-registers the 13 legacy `bizcity-channels` submenus into the
 * `bizchat-gateway` hub (group=channels) so all channel admin pages
 * are reachable via a single URL namespace:
 *
 *   admin.php?page=bizchat-gateway&group=channels&sub={slug}
 *
 * Strategy (non-destructive per PHASE-0-RULE-CHANNEL-ONLY.md §"ko làm mới"):
 *   - We only ADD subpage registrations via the hook; the old
 *     `bizcity-channels` parent + its submenus remain until M1.W3.
 *   - T-P0.37.1.2.7 adds admin_init redirects so bookmarks / external
 *     links pointing to old slugs land on the hub automatically.
 *
 * Migration map (old slug → new hub URL):
 *   bizcity-zalo-bot-dashboard   → group=channels&sub=zalo-bot
 *   bizcity-zalo-bot-assign      → group=channels&sub=zalo-bot-assign
 *   bizcity-zalo-bots            → group=channels&sub=zalo-bots
 *   bizcity-zalo-bot-listener    → group=channels&sub=zalo-bot-listener
 *   bizcity-zalo-bot-test-api    → group=channels&sub=zalo-bot-test-api
 *   bizcity-zalo-bot-logs        → group=channels&sub=zalo-bot-logs
 *   zalo-video-guider            → group=channels&sub=zalo-legacy-guide
 *   zalo-users-admin             → group=channels&sub=zalo-user-mapping
 *   zalo-guider                  → group=channels&sub=zalo-legacy
 *   bizcity-facebook-bots        → group=channels&sub=facebook-page
 *   bizcity-facebook-bot-connect → group=channels&sub=facebook-connect
 *   bizcity-zalo-hotline         → group=channels&sub=zalo-hotline
 *
 * @package    BizCity_Twin_AI
 * @subpackage Channel_Gateway
 * @since      PHASE 0.37 M1.W2
 * @see        PHASE-0.37-UNIFY-CHANNEL-FB-ZALO-EMAIL.md M1.W2
 * @see        PHASE-0-RULE-CHANNEL-ONLY.md R-CH-1
 */

defined( 'ABSPATH' ) || exit;

// ═══════════════════════════════════════════════════════════════
//  T-P0.37.1.2.1–6  Register subpages via bizchat_gateway_register_subpages
//  Priority 10 = runs BEFORE Registry fires its own admin_menu @15.
// ═══════════════════════════════════════════════════════════════

add_action( 'bizchat_gateway_register_subpages', function ( $reg ) {
	if ( ! ( $reg instanceof BizCity_Channel_Menu_Registry ) ) {
		return;
	}

	$td = 'bizcity-twin-ai';
	// [2026-09-01 Johnny Chu] PHASE-1.30-ZALO-MEMORY-REMOVE — legacy Zalo memory submenu and redirect are retired.

	// ── T-P0.37.1.2.1 — Zalo Bot Dashboard ──────────────────────────
	if ( class_exists( 'BizCity_Zalo_Bot_Dashboard', false ) ) {
		$dash = BizCity_Zalo_Bot_Dashboard::instance();
		$reg->add_subpage( [
			'group'    => 'channels',
			'slug'     => 'zalo-bot',
			'title'    => __( 'Zalo Bot OA', $td ),
			'icon'     => '🤖',
			'callback' => [ $dash, 'render_dashboard' ],
			'order'    => 10,
		] );
	}

	// ── T-P0.37.1.2.2 — Zalo Bot Account Assign ─────────────────────
	if ( class_exists( 'BizCity_Zalo_Bot_Dashboard', false ) ) {
		$dash = BizCity_Zalo_Bot_Dashboard::instance();
		$reg->add_subpage( [
			'group'    => 'channels',
			'slug'     => 'zalo-bot-assign',
			'title'    => __( 'Zalo Connections', $td ),
			'icon'     => '🔗',
			'callback' => [ $dash, 'render_assign_page' ],
			'order'    => 15,
		] );
	}

	// ── T-P0.37.1.2.3 — Zalo Bot Admin sub-pages (5 pages) ──────────
	if ( class_exists( 'BizCity_Zalo_Bot_Admin_Menu', false ) ) {
		$zb = BizCity_Zalo_Bot_Admin_Menu::instance();
		foreach ( [
			[ 'zalo-bots',         __( 'Zalo Bots', $td ),     '🤖', [ $zb, 'render_page' ],          20 ],
			[ 'zalo-bot-listener', __( 'Zalo Webhook', $td ),   '🔔', [ $zb, 'render_listener_page' ],  25 ],
			[ 'zalo-bot-test-api', __( 'Zalo Test API', $td ),  '🧪', [ $zb, 'render_test_api_page' ],  30 ],
			[ 'zalo-bot-logs',     __( 'Zalo Logs', $td ),      '📜', [ $zb, 'render_logs_page' ],       35 ],
		] as [ $slug, $title, $icon, $cb, $order ] ) {
			$reg->add_subpage( [
				'group'    => 'channels',
				'slug'     => $slug,
				'title'    => $title,
				'icon'     => $icon,
				'callback' => $cb,
				'order'    => $order,
			] );
		}
	}

	// [2026-09-27 Claude Opus 5.5] CORE-REDUCTION WP-12 R8 — T-P0.37.1.2.4 legacy Zalo BizCity guide pages removed (Hotline channel retired, R-ONE-AXIS D-29).

	// ── T-P0.37.1.2.5 — Facebook pages (2 pages) ─────────────────────
	if ( class_exists( 'BizCity_Facebook_Bot_Admin_Menu', false ) ) {
		$fb = BizCity_Facebook_Bot_Admin_Menu::instance();
		$reg->add_subpage( [
			'group'    => 'channels',
			'slug'     => 'facebook-page',
			'title'    => __( 'Facebook Bots', $td ),
			'icon'     => '📘',
			'callback' => [ $fb, 'render_page' ],
			'order'    => 60,
		] );
		$reg->add_subpage( [
			'group'    => 'channels',
			'slug'     => 'facebook-connect',
			'title'    => __( 'FB Connect', $td ),
			'icon'     => '🔗',
			'callback' => [ $fb, 'render_connect_page' ],
			'order'    => 65,
		] );
	}

	// [2026-09-27 Claude Opus 5.5] CORE-REDUCTION WP-12 R8 — T-P0.37.1.2.6 Zalo Hotline page removed; it rendered a permanent
	// "Module chưa được nạp" warning once plugins/bizcity-zalo-bizcity was archived (R-ONE-AXIS D-29).

	/* ═══════════════════════════════════════════════════════════
	 *  M1.W3 — Demote orphan integrations into the hub.
	 *  T-P0.37.1.3.2 — bzgoogle-settings → integrations group
	 *  T-P0.37.1.3.3 — bizcity-scheduler → integrations group
	 *  T-P0.37.1.3.4 — BizCity SMTP    → channels group (sub=email)
	 * ═══════════════════════════════════════════════════════════ */

	if ( class_exists( 'BZGoogle_Admin', false ) ) {
		$reg->add_subpage( [
			'group'    => 'integrations',
			'slug'     => 'google',
			'title'    => __( 'Google Tools', $td ),
			'icon'     => '🔍',
			'callback' => [ 'BZGoogle_Admin', 'render_page' ],
			'order'    => 10,
		] );
	}

	// 2026-06-04 — Unified Google Hub (single sign-in via bizcity.vn pretty URL).
	// Available whenever BizCity_Google_Hub helper is loaded (always, via core/bizcity-llm).
	if ( class_exists( 'BizCity_Google_Hub_Page', false ) ) {
		$reg->add_subpage( [
			'group'    => 'integrations',
			'slug'     => 'google-hub',
			'title'    => __( 'Google Hub', $td ),
			'icon'     => '🔗',
			'callback' => [ 'BizCity_Google_Hub_Page', 'render' ],
			'order'    => 5,
		] );
	}

	if ( class_exists( 'BizCity_Scheduler_Admin_Page', false ) ) {
		// [2026-06-13 Johnny Chu] HOTFIX — use lazy closure so ::instance() is not called
		// at registration time (avoids double-init if class loads later in the request).
		$reg->add_subpage( [
			'group'    => 'integrations',
			'slug'     => 'scheduler',
			'title'    => __( 'Scheduler', $td ),
			'icon'     => '📅',
			'callback' => static function () {
				if ( class_exists( 'BizCity_Scheduler_Admin_Page' ) ) {
					BizCity_Scheduler_Admin_Page::instance()->render_page();
				} else {
					echo '<div class="wrap"><div class="notice notice-warning"><p>Scheduler module chưa được nạp.</p></div></div>';
				}
			},
			'order'    => 20,
		] );
	}

	// SMTP — only register if a renderer exists (no top-level menu currently).
	$smtp_callback = null;
	if ( function_exists( 'bizcity_smtp_render_settings_page' ) ) {
		$smtp_callback = 'bizcity_smtp_render_settings_page';
	} elseif ( class_exists( 'BizCity_SMTP_Admin_Menu', false ) && method_exists( 'BizCity_SMTP_Admin_Menu', 'render_page' ) ) {
		$smtp_callback = [ BizCity_SMTP_Admin_Menu::instance(), 'render_page' ];
	}
	if ( $smtp_callback ) {
		$reg->add_subpage( [
			'group'    => 'channels',
			'slug'     => 'email',
			'title'    => __( 'Email / SMTP', $td ),
			'icon'     => '✉️',
			'callback' => $smtp_callback,
			'order'    => 80,
		] );
	}

}, 10 );

// ═══════════════════════════════════════════════════════════════
//  T-P0.37.1.3.1 — Demote `bizcity-channels` parent menu
//  Runs at admin_menu @ priority 999 so we run AFTER all add_menu_page.
//  We DON'T remove submenus (they're still useful as deep links until
//  full migration is done) — we only remove the top-level entry from
//  the sidebar so users naturally use bizchat-gateway hub instead.
// ═══════════════════════════════════════════════════════════════

add_action( 'admin_menu', function () {
	// Remove the standalone parent entry; submenus moved to hub already.
	remove_menu_page( 'bizcity-channels' );
}, 999 );

// ═══════════════════════════════════════════════════════════════
//  T-P0.37.1.2.7  Redirect legacy bizcity-channels/* slugs → hub
//  Runs at admin_init so bookmarks / external links still work.
// ═══════════════════════════════════════════════════════════════

add_action( 'admin_init', function () {
	if ( wp_doing_ajax() || ! is_admin() ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	if ( ! $page ) {
		return;
	}

	$redirect_map = [
		'bizcity-zalo-bot-dashboard'   => 'group=channels&sub=zalo-bot',
		'bizcity-zalo-bot-assign'      => 'group=channels&sub=zalo-bot-assign',
		'bizcity-zalo-bots'            => 'group=channels&sub=zalo-bots',
		'bizcity-zalo-bot-listener'    => 'group=channels&sub=zalo-bot-listener',
		'bizcity-zalo-bot-test-api'    => 'group=channels&sub=zalo-bot-test-api',
		'bizcity-zalo-bot-logs'        => 'group=channels&sub=zalo-bot-logs',
		// [2026-09-27 Claude Opus 5.5] CORE-REDUCTION WP-12 R8 — the Hotline subpages are gone; old bookmarks land on the
		// Channels hub instead of a sub without a callback (which renders a blank page, see the scheduler note below).
		'zalo-video-guider'            => 'group=channels',
		'zalo-users-admin'             => 'group=channels',
		'zalo-guider'                  => 'group=channels',
		'bizcity-facebook-bots'        => 'group=channels&sub=facebook-page',
		'bizcity-facebook-bot-connect' => 'group=channels&sub=facebook-connect',
		'bizcity-zalo-hotline'         => 'group=channels',
		// M1.W3 demotions
		'bizcity-channels'             => 'group=channels',
		'bzgoogle-settings'            => 'group=integrations&sub=google',
		// [2026-06-13 Johnny Chu] HOTFIX — removed bizcity-scheduler from redirect map.
		// Scheduler has its own React SPA admin page; redirecting into the Channel Gateway
		// hub caused a blank page because the sub-page callback was not reliably registered
		// at admin_init time. page=bizcity-scheduler now loads directly.
	];

	if ( isset( $redirect_map[ $page ] ) ) {
		wp_safe_redirect(
			admin_url( 'admin.php?page=bizchat-gateway&' . $redirect_map[ $page ] ),
			301
		);
		exit;
	}
} );
