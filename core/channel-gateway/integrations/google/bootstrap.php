<?php
/**
 * Channel Gateway integration — Google connection (OAuth hub client, token store, Google service, REST, admin).
 *
 * [2026-09-30 Claude Opus 5.5] CORE-REDUCTION WP-16 B-3b-G (R-LEAN-4, owner 2026-09-30) — moved here from the bundled plugin plugins/bizgpt-tool-google
 * (archived at plugins/_archived/bizgpt-tool-google-20260930/) so the connection sits on the channel axis
 * (channel ⇒ CRM): the CRM Gmail IMAP channel, the CRM Google bridge, the Scheduler calendar sync and the
 * Google Hub all use it. Compatibility is deliberate: same class names (BZGoogle_*), same constants
 * (BZGOOGLE_VERSION, BZGOOGLE_SLUG = 'bizgpt-tool-google' — the admin page slug), same options (bzgoogle_*), same
 * table (bizcity_google_accounts), same REST namespace and cron hook — stored tokens keep working.
 * Left behind: the intent tools (BZGoogle_Tools — client tools are cut, Q-W16-3) and the /tool-google/ studio page,
 * which now redirects to where the connection is managed.
 *
 * Loaded by bizcity-twin-ai.php under the same gate the bundled copy had (admin / REST / cron / CLI / /tool-* / /twin/).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// [2026-09-30 Claude Opus 5.5] CORE-REDUCTION WP-16 B-3b-G (R-LEAN-4, owner 2026-09-30) — a standalone copy of the old plugin, if a site still has one active, wins; never define twice.
if ( defined( 'BZGOOGLE_VERSION' ) ) {
    return;
}


/* ── Constants ─────────────────────────────────────────────── */
define( 'BZGOOGLE_VERSION',  '1.0.1' );
define( 'BZGOOGLE_DIR',      plugin_dir_path( __FILE__ ) );
define( 'BZGOOGLE_URL',      plugin_dir_url( __FILE__ ) );
define( 'BZGOOGLE_SLUG',     'bizgpt-tool-google' );
define( 'BZGOOGLE_FILE',     __FILE__ );

/* ── Autoload classes ──────────────────────────────────────── */
require_once BZGOOGLE_DIR . 'includes/class-installer.php';
require_once BZGOOGLE_DIR . 'includes/class-token-store.php';
require_once BZGOOGLE_DIR . 'includes/class-google-oauth.php';
require_once BZGOOGLE_DIR . 'includes/class-google-service.php';
require_once BZGOOGLE_DIR . 'includes/class-admin.php';
require_once BZGOOGLE_DIR . 'includes/class-rest-api.php';
require_once BZGOOGLE_DIR . 'includes/class-cron.php';

/* ── [2026-09-30 Claude Opus 5.5] CORE-REDUCTION WP-16 B-3b-G (R-LEAN-4, owner 2026-09-30) ──
 * Activation/deactivation hooks removed: this is no longer a WordPress plugin file. Tables self-heal below
 * (BZGoogle_Installer::maybe_create_tables), the token-refresh cron schedules itself on init. */

/* ── Self-healing: create tables if activation hook was skipped ── */
BZGoogle_Installer::maybe_create_tables();

/* ── Init ──────────────────────────────────────────────────── */
add_action( 'init', [ 'BZGoogle_Google_OAuth', 'register_rewrite_rules' ] );
add_action( 'init', [ 'BZGoogle_Admin',        'init' ] );
add_action( 'rest_api_init', [ 'BZGoogle_REST_API', 'register_routes' ] );

/* ── Cron ──────────────────────────────────────────────────── */
add_action( 'init', [ 'BZGoogle_Cron', 'schedule' ] );
add_action( 'bzgoogle_refresh_tokens', [ 'BZGoogle_Cron', 'refresh_expiring_tokens' ] );

/* ── Early route: handle /google-auth/* at init level ──────── *
 * Bypasses WP_Query entirely so it works even when rewrite     *
 * rules haven't been flushed (404 → canonical redirect → 500). */
add_action( 'init', function () {
    if ( empty( $_SERVER['REQUEST_URI'] ) ) return;
    $path = trim( parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ), '/' );
    if ( preg_match( '/^google-auth\/([a-z]+)$/', $path, $m ) ) {
        BZGoogle_Google_OAuth::handle_request_direct( $m[1] );
        // handle_request_direct exits internally — should never reach here
        exit;
    }
}, 20 );

/* ── Query vars (kept for when rewrite rules ARE flushed) ──── */
add_filter( 'query_vars', function ( $vars ) {
    $vars[] = 'bzgoogle_action';
    return $vars;
} );

/* ── Template redirect fallback (only if init handler missed it) ── */
add_action( 'template_redirect', [ 'BZGoogle_Google_OAuth', 'handle_request' ], 1 );

/* ══════════════════════════════════════════════════════════════
 *  [2026-09-30 Claude Opus 5.5] CORE-REDUCTION WP-16 B-3b-G (R-LEAN-4, owner 2026-09-30) — /tool-google/ studio retired.
 *  Old links (CRM Google bridge, bookmarks) land where the connection is managed:
 *  site managers → the Google settings page; other logged-in users → their profile (the
 *  "Kết nối Google" section, BZGoogle_Admin::render_profile_section); visitors → login.
 * ══════════════════════════════════════════════════════════════ */
add_action( 'template_redirect', static function () {
    $uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
    if ( ! preg_match( '#^/tool-google(?:/|\?|$)#', $uri ) ) {
        return;
    }
    if ( ! is_user_logged_in() ) {
        wp_safe_redirect( wp_login_url( admin_url( 'profile.php' ) ), 302 );
    } elseif ( current_user_can( 'manage_options' ) ) {
        wp_safe_redirect( admin_url( 'admin.php?page=' . BZGOOGLE_SLUG ), 301 );
    } else {
        wp_safe_redirect( admin_url( 'profile.php' ), 301 );
    }
    exit;
}, 0 );

/* ══════════════════════════════════════════════════════════════
 *  PHASE 0.31 Sprint 6 follow-up — surface "⚙ Cài đặt riêng →"
 *  shortcut in WAIC integration dialog for Gmail / Google Calendar
 *  rows. [2026-09-30 Claude Opus 5.5] CORE-REDUCTION WP-16 B-3b-G: it now points at the Google settings admin page
 *  (the /tool-google/ studio is retired). Avoids users
 *  duplicating Client ID/Secret editing in 2 places.
 * ══════════════════════════════════════════════════════════════ */
add_filter( 'bizcity_integrations_data', function ( $integs ) {
    $url = admin_url( 'admin.php?page=' . BZGOOGLE_SLUG );
    foreach ( array( 'gmail', 'googlecalendar' ) as $code ) {
        if ( isset( $integs[ $code ] ) && empty( $integs[ $code ]['config_url'] ) ) {
            $integs[ $code ]['config_url'] = $url;
        }
    }
    return $integs;
} );
