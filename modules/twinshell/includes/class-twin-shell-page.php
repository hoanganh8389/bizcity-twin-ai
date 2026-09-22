<?php
/**
 * Twin Shell — Public page /twin/.
 *
 * Renders the iframe-shell wrapper. Mirrors the conventions used by
 * BizCity_TwinChat_Public_Page (rewrite + theme isolation + minimal HTML5
 * shell), but keeps the front-end as plain JS — no Vite build needed for v1.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Modules\TwinShell
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Twin_Shell_Page {

	const QUERY_VAR   = 'bizcity_twin_shell';
	const REWRITE_KEY = '^twin/?$';
	const OPTION_KEY  = 'bizcity_twin_shell_rewrite_flushed_v2';

	// [2026-09-15 Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G5 — serve the built CoreUI Control Panel artifact from assets/dist.
	const PANEL_QUERY_VAR   = 'bizcity_twin_panel';
	const PANEL_REWRITE_KEY = '^twin/panel/?$';
	const PANEL_DIST_DIR     = 'assets/dist/';
	// Marks a /twin/ load that came from the Control Panel fail-open, so the shell renders instead of bouncing to the wp-admin wrapper. Also makes the cause visible in the URL and access logs.
	const PANEL_FALLBACK_ARG = 'bizcity_panel_unavailable';

	private static $instance = null;
	private $registered = false;

	/**
	 * Whether this /twin/ request is being rendered inside a host frame.
	 *
	 * [2026-09-16 02:30 PM Johnny Chu - Chu Hoàng Anh] PHASE-TWINSHELL-CHROME-HOTFIX
	 * Set by maybe_render() and consumed by render() so the embed marker can be
	 * carried into the shell config without re-deriving it from globals.
	 *
	 * @var bool
	 */
	private $is_embedded = false;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Canonical URL for the Twin Shell, safe on every permalink configuration.
	 *
	 * - Pretty permalinks ON  → `home_url('/twin/')`
	 * - Pretty permalinks OFF → `home_url('/?bizcity_twin_shell=1')` (fallback
	 *   that uses the registered query_var and avoids the 404 caused when the
	 *   `^twin/?$` rewrite rule cannot match on plain permalinks).
	 *
	 * @param array $args Extra query args to append (e.g. `['plugin' => 'crm']`).
	 * @return string
	 */
	public static function shell_url( array $args = [] ) {
		$pretty = (string) get_option( 'permalink_structure', '' ) !== '';
		$base   = $pretty
			? home_url( '/twin/' )
			: home_url( '/?' . self::QUERY_VAR . '=1' );
		if ( ! empty( $args ) ) {
			$base = add_query_arg( $args, $base );
		}
		return $base;
	}

	/**
	 * Query args that mark a shell URL as host-embedded and loop-safe.
	 *
	 * [2026-09-16 03:30 PM Johnny Chu - Chu Hoàng Anh] PHASE-TWINSHELL-CHROME-HOTFIX2
	 *
	 * `bizcity_embed` renders the standalone shell instead of redirecting, and
	 * `bizcity_admin_wrapper` is the structural loop-breaker: any /twin/ URL that
	 * originated inside the wp-admin wrapper can never trigger the admin hand-off
	 * again, so a redirect loop is impossible by construction.
	 *
	 * @param bool $embedded Whether the current request is host-embedded.
	 * @return array
	 */
	public static function embedded_args( $embedded ) {
		return $embedded
			? array( 'bizcity_embed' => '1', 'bizcity_admin_wrapper' => '1' )
			: array();
	}

	/**
	 * R-ROUTE-7 validator for the canonical `r` (plugin-relative route) parameter.
	 *
	 * [2026-09-18 Johnny Chu - Chu Hoàng Anh] PHASE-0-RULE-URL-ROUTE P1 — shared so every place
	 * that forwards `r` (this class's own `/twin/` -> wrapper redirect, and the wp-admin
	 * wrapper's `render_page()`) applies the SAME rule instead of drifting like the `_iurl`
	 * check already has across 3 separate copies. `r` is NEVER used as a full URL — the shell
	 * always joins it to the registry's own entry URL for the plugin — but it must still be a
	 * same-origin relative path so nothing can smuggle an absolute/protocol-relative URL through
	 * a parameter operators and integrations treat as inert route text.
	 *
	 * @param string $value Raw, still-unslashed value.
	 * @return bool
	 */
	public static function is_safe_route( $value ) {
		$value = (string) $value;
		if ( '' === $value || strlen( $value ) > 2048 ) {
			return false;
		}
		if ( '/' !== substr( $value, 0, 1 ) ) {
			return false;
		}
		if ( 0 === strpos( $value, '//' ) ) {
			return false;
		}
		if ( false !== strpos( $value, '://' ) || false !== strpos( $value, '\\' ) ) {
			return false;
		}
		if ( preg_match( '/[\x00-\x1F\x7F]/', $value ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Canonical URL for the built CoreUI Control Panel artifact.
	 *
	 * [2026-09-15 Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G5 — the
	 * artifact is served by PHP so the REST config can be injected without
	 * editing compiled files.
	 *
	 * @param array $args Extra query args to append.
	 * @return string
	 */
	public static function panel_url( array $args = [] ) {
		$pretty = (string) get_option( 'permalink_structure', '' ) !== '';
		$base   = $pretty
			? home_url( '/twin/panel/' )
			: home_url( '/?' . self::PANEL_QUERY_VAR . '=1' );
		if ( ! empty( $args ) ) {
			$base = add_query_arg( $args, $base );
		}
		return $base;
	}

	/**
	 * Absolute path of the built Control Panel entry file, or '' when absent.
	 *
	 * @return string
	 */
	public static function panel_index_file() {
		$file = BIZCITY_TWIN_SHELL_DIR . self::PANEL_DIST_DIR . 'index.html';
		return is_readable( $file ) ? $file : '';
	}

	/**
	 * Return whether the current operator may open the Control Panel.
	 *
	 * [2026-09-21 05:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-HOTFIX —
	 * Super Admin is a network identity and must not be denied by a filtered or
	 * blog-scoped `manage_options` check on a mapped tenant.
	 *
	 * @return bool
	 */
	public static function can_access_panel() {
		return ( function_exists( 'is_super_admin' ) && is_super_admin() )
			|| current_user_can( 'manage_options' )
			|| current_user_can( 'manage_network' );
	}

	/**
	 * Locale key the built Control Panel should render with.
	 *
	 * [2026-09-15 Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G5 — map the
	 * WordPress document locale to a catalog key the bundle ships.
	 *
	 * @return string
	 */
	public static function panel_locale() {
		// [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G6-03 — honour the operator's own
		// WordPress language (User Preferences) before the site language.
		$raw = function_exists( 'get_user_locale' ) ? (string) get_user_locale() : '';
		if ( '' === $raw ) {
			$raw = (string) get_bloginfo( 'language' );
		}
		$raw = str_replace( '-', '_', $raw );
		if ( '' === $raw ) {
			return 'en';
		}
		if ( 0 === strpos( $raw, 'vi' ) ) {
			return 'vi_VN';
		}
		return 'en';
	}

	public function register() {
		// [2026-07-09 Johnny Chu] PHASE-TWINSHELL-IMPL — idempotent register.
		if ( $this->registered ) {
			return;
		}
		$this->registered = true;

		add_action( 'init',              [ $this, 'add_rewrite_rule' ] );
		add_filter( 'query_vars',        [ $this, 'add_query_var' ] );
		add_action( 'template_redirect', [ $this, 'maybe_render' ] );
		// Disable Query Monitor on /twin/.
		add_filter( 'qm/dispatch/html',  [ $this, 'disable_qm' ] );
		add_filter( 'qm/process',        [ $this, 'disable_qm' ] );
	}

	public function add_rewrite_rule() {
		// [2026-06-09 Johnny Chu] HOTFIX — flush removed from init:10 (Transposh/WC loop).
		// One-time flush is handled by admin_init guard in modules/twinshell/bootstrap.php.
		add_rewrite_rule(
			self::REWRITE_KEY,
			'index.php?' . self::QUERY_VAR . '=1',
			'top'
		);
		// [2026-09-15 Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G5 — built Control Panel route.
		add_rewrite_rule(
			self::PANEL_REWRITE_KEY,
			'index.php?' . self::PANEL_QUERY_VAR . '=1',
			'top'
		);
	}

	public function add_query_var( $vars ) {
		$vars[] = self::QUERY_VAR;
		$vars[] = self::PANEL_QUERY_VAR;
		return $vars;
	}

	public function maybe_render() {
		// [2026-09-15 Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G5 — built Control Panel artifact route.
		if ( get_query_var( self::PANEL_QUERY_VAR ) ) {
			$this->maybe_render_panel();
			return;
		}

		if ( ! get_query_var( self::QUERY_VAR ) ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			// [2026-07-09 Johnny Chu] PHASE-TWINSHELL-ACTIVITY-LOG — audit unauthenticated shell access.
			$this->emit_activity_event( 'shell.guard.not_logged_in', array(
				'outcome' => 'blocked',
				'route'   => home_url( add_query_arg( null, null ) ),
			) );
			$redirect = home_url( add_query_arg( null, null ) );
			wp_safe_redirect( wp_login_url( $redirect ) );
			exit;
		}

		// [2026-09-16 12:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-TWINSHELL-CHROME — send operators to the native wp-admin wrapper so WordPress renders its own admin bar and sidebar; the embedded iframe carries bizcity_embed=1 and keeps rendering the standalone shell.
		// [2026-09-16 03:30 PM Johnny Chu - Chu Hoàng Anh] PHASE-TWINSHELL-CHROME-HOTFIX2 — make the hand-off strictly ONE-WAY. A /twin/ -> admin.php redirect combined with any admin.php -> /twin/ redirect in the same install is an Apache internal redirect loop (AH00124 -> HTTP 500), which is exactly the reported failure. The wrapper must never send the top window back, and this side must never bounce a request that just came from the wrapper.
		$is_embedded = ! empty( $_GET['bizcity_embed'] )
			|| ! empty( $_GET['bizcity_iframe'] )
			|| ( isset( $_SERVER['HTTP_SEC_FETCH_DEST'] ) && 'iframe' === strtolower( (string) $_SERVER['HTTP_SEC_FETCH_DEST'] ) );
		$this->is_embedded = $is_embedded;
		$loop_breaker = '1' === (string) ( isset( $_GET['bizcity_admin_wrapper'] ) ? $_GET['bizcity_admin_wrapper'] : '' );
		$referer      = isset( $_SERVER['HTTP_REFERER'] ) ? (string) $_SERVER['HTTP_REFERER'] : '';
		$came_from_wrapper = '' !== $referer && false !== strpos( $referer, 'page=bizcity-twinchat' );
		// [2026-09-16 Johnny Chu - Chu Hoang Anh] PHASE-0-SETTING-PANEL-G5-HOTFIX — /twin/panel/ fails open to this shell when the built artifact is absent. Without this breaker the fail-open lands on /twin/, which then bounces an operator to admin.php?page=bizcity-twinchat, so a missing Control Panel artifact looked like "the Setting Panel does not exist" instead of a deploy gap.
		$panel_fallback = '1' === (string) ( isset( $_GET[ self::PANEL_FALLBACK_ARG ] ) ? $_GET[ self::PANEL_FALLBACK_ARG ] : '' );
		// [2026-09-21 05:15 PM Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-HOTFIX —
		// keep the standalone /twin/ → wp-admin hand-off limited to site admins.
		// Super Admins without a blog role must stay on standalone TwinShell; using
		// the broader Control Panel gate here creates an admin-wrapper redirect loop.
		if ( ! $is_embedded && ! $loop_breaker && ! $came_from_wrapper && ! $panel_fallback && current_user_can( 'manage_options' ) ) {
			$target = add_query_arg(
				array( 'page' => 'bizcity-twinchat', 'bizcity_admin_wrapper' => '1' ),
				admin_url( 'admin.php' )
			);
			if ( isset( $_GET['plugin'] ) && '' !== $_GET['plugin'] ) {
				$target = add_query_arg( 'plugin', sanitize_key( wp_unslash( $_GET['plugin'] ) ), $target );
			}
			$forward = array(
				'notebook_id', 'notebook', 'session', 'session_id', 'thread', 'tab',
				'id', 'task_id', 'inbox', 'contact_id', 'doc', 'instance_id', '_iurl',
				// [2026-09-18 Johnny Chu - Chu Hoàng Anh] PHASE-0-RULE-URL-ROUTE P1 — `r` is the
				// canonical plugin-relative route (TRC v1); it replaces `_iurl` for any plugin
				// that has opted into `route_mode`. Both are forwarded during the migration
				// window so a plugin still on the legacy path keeps working unchanged.
				'r',
			);
			foreach ( $forward as $key ) {
				if ( isset( $_GET[ $key ] ) && '' !== $_GET[ $key ] ) {
					$value = sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
					if ( '_iurl' === $key || 'r' === $key ) {
						// [2026-09-17 Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G5 — `_iurl` (and, since
						// P1, `r`) is a same-origin path that can carry its own `?`, `&` and `#` (e.g. the
						// Control Panel hash route). add_query_arg() does not encode values, so without
						// rawurlencode the `#…` became this redirect's fragment and the wrapper reopened the
						// panel on its default item.
						if ( ! self::is_safe_route( $value ) ) {
							continue;
						}
						$value = rawurlencode( $value );
					}
					$target = add_query_arg( $key, $value, $target );
				}
			}
			wp_safe_redirect( $target, 302 );
			exit;
		}

		$this->render();
		exit;
	}

	/**
	 * Serve the built CoreUI Control Panel artifact from `assets/dist/`.
	 *
	 * [2026-09-15 Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G5 — the
	 * compiled bundle is never edited; PHP only injects the same-origin REST
	 * config and rewrites relative asset URLs to the plugin URL. Missing
	 * artifact fails open to the plain-JS shell instead of a blank page.
	 *
	 * @return void
	 */
	private function maybe_render_panel() {
		if ( ! is_user_logged_in() ) {
			$this->emit_activity_event( 'shell.guard.not_logged_in', array(
				'outcome' => 'blocked',
				'route'   => self::panel_url(),
			) );
			wp_safe_redirect( wp_login_url( self::panel_url() ) );
			exit;
		}

		// Fail closed on capability; the artifact is an admin surface. Super Admin
		// is explicitly accepted because network identity can outlive blog roles.
		if ( ! self::can_access_panel() ) {
			$this->emit_activity_event( 'shell.guard.capability_denied', array(
				'outcome' => 'blocked',
				'route'   => self::panel_url(),
			) );
			wp_die( esc_html__( 'You do not have permission to access the Control Panel.', 'bizcity-twin-ai' ) );
		}

		$index = self::panel_index_file();
		if ( '' === $index ) {
			// Fail open: artifact not deployed yet → keep the working shell.
			$this->emit_activity_event( 'shell.guard.panel_artifact_missing', array(
				'outcome' => 'degraded',
				'route'   => self::panel_url(),
			) );
			wp_safe_redirect( self::shell_url( array( self::PANEL_FALLBACK_ARG => '1' ) ) );
			exit;
		}

		$html = (string) file_get_contents( $index );
		if ( '' === $html ) {
			wp_safe_redirect( self::shell_url( array( self::PANEL_FALLBACK_ARG => '1' ) ) );
			exit;
		}

		$dist_url = BIZCITY_TWIN_SHELL_URL . self::PANEL_DIST_DIR;

		// Rewrite relative artifact references to absolute plugin URLs so the
		// browser never requests /twin/panel/assets/* (which WordPress cannot route).
		// [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G5-HOTFIX — the bundle now ships under
		// fixed names (assets/twin-shell.js|css) and the web server sends them with a 30-day max-age, so
		// operators kept running the first combined build after every deploy. Version each asset by its
		// own mtime+size: a redeploy changes the URL, an unchanged file keeps its cache.
		$dist_dir = BIZCITY_TWIN_SHELL_DIR . self::PANEL_DIST_DIR;
		$html     = (string) preg_replace_callback(
			'#="\./assets/([A-Za-z0-9._/-]+)"#',
			static function ( $match ) use ( $dist_url, $dist_dir ) {
				$relative = (string) $match[1];
				$path     = $dist_dir . 'assets/' . $relative;
				$version  = is_readable( $path ) ? ( filemtime( $path ) . '-' . filesize( $path ) ) : BIZCITY_TWIN_SHELL_VERSION;
				return '="' . esc_url( $dist_url . 'assets/' . $relative . '?ver=' . rawurlencode( (string) $version ) ) . '"';
			},
			$html
		);
		$html = str_replace( '="./manifest.json"', '="' . esc_url( $dist_url . 'manifest.json' ) . '"', $html );
		$html = str_replace( '="./favicon.ico"', '="' . esc_url( $dist_url . 'favicon.ico' ) . '"', $html );

		// [2026-09-15 Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G5 — keep the document language in sync with the injected locale.
		$html_lang = 'vi_VN' === self::panel_locale() ? 'vi' : 'en';
		$html = preg_replace( '/<html lang="[^"]*"/', '<html lang="' . esc_attr( $html_lang ) . '"', $html, 1 );

		$config = (string) wp_json_encode( [
			'restRoot' => esc_url_raw( rest_url( 'bizcity-twinchat/v1/' ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'panelUrl' => esc_url_raw( self::panel_url() ),
			'shellUrl' => esc_url_raw( self::shell_url() ),
			'userId'   => (int) get_current_user_id(),
			// [2026-09-15 Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G5 — expose the WordPress document locale so the panel can pick vi_VN without a second request.
			'locale'   => self::panel_locale(),
			// [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G6-03 — apply the saved theme/density/motion on first paint instead of one browser's localStorage.
			'appearance' => class_exists( 'BizCity_Twin_Shell_Appearance' )
				? BizCity_Twin_Shell_Appearance::effective( (int) get_current_user_id() )
				: null,
		] );

		$inject = '<script data-cfasync="false">window.BIZCITY_TWIN_SHELL = ' . $config . ';</script>' . "\n";
		if ( false !== strpos( $html, '</head>' ) ) {
			$html = str_replace( '</head>', $inject . '</head>', $html );
		} else {
			$html = $inject . $html;
		}

		$this->emit_activity_event( 'shell.nav.open_panel', array(
			'route'   => self::panel_url(),
			'target'  => 'control-panel',
			'outcome' => 'success',
		) );

		// The HTML carries a per-user nonce and the versioned asset URLs, so it must never be served from a
		// page cache: a stale copy would pin an old bundle and an expired nonce.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow', true );
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- compiled artifact, config injected as JSON.
		exit;
	}

	public function disable_qm( $val ) {
		if ( get_query_var( self::QUERY_VAR ) || get_query_var( self::PANEL_QUERY_VAR ) ) {
			return false;
		}
		return $val;
	}

	/**
	 * Emit TwinShell activity into canonical event stream as milestone rows.
	 *
	 * Fail-open: shell rendering must continue even if telemetry fails.
	 *
	 * @param string $milestone_type Shell action code (e.g. shell.nav.open_plugin).
	 * @param array  $payload        Extra payload fields.
	 */
	private function emit_activity_event( $milestone_type, array $payload = array() ) {
		if ( ! class_exists( 'BizCity_Twin_Event_Bus' ) || ! class_exists( 'BizCity_Twin_Event_Taxonomy' ) ) {
			return;
		}
		if ( ! method_exists( 'BizCity_Twin_Event_Bus', 'dispatch_v2' ) ) {
			return;
		}

		$event_payload = array_merge(
			array(
				'milestone_type' => (string) $milestone_type,
				'surface'        => 'twinshell',
				'action'         => (string) $milestone_type,
				'outcome'        => isset( $payload['outcome'] ) ? (string) $payload['outcome'] : 'success',
			),
			$payload
		);

		// [2026-07-09 Johnny Chu] PHASE-TWINSHELL-ACTIVITY-LOG — stable session grouping for shell events.
		$session_id = 'shell_' . (int) get_current_blog_id() . '_' . (int) get_current_user_id();

		try {
			BizCity_Twin_Event_Bus::dispatch_v2(
				BizCity_Twin_Event_Taxonomy::MILESTONE,
				$event_payload,
				array(
					'event_source' => 'system',
					'user_id'      => (int) get_current_user_id(),
					'session_id'   => $session_id,
				)
			);
		} catch ( \Throwable $e ) {
			error_log( '[TwinShell] activity emit failed: ' . $e->getMessage() );
		}
	}

	private function render() {
		$registry = BizCity_Twin_Shell_Registry::instance();
		$plugins  = $registry->all();

		// Filter to plugins the current user can see AND that are unlocked
		// (core entries OR non-core whose `requires` is satisfied). Locked
		// non-core entries are kept in `$locked_map` so bookmarked URLs can
		// still render a friendly "Pro / not active" notice instead of a
		// silent fallback to TwinChat.
		$visible    = [];
		$locked_map = [];

		// [2026-06-08 Johnny Chu] PHASE-MEMBERSHIP — resolve current user's plan for gate check.
		// [2026-06-09 Johnny Chu] HOTFIX — also consider hub master_level (site API key tier).
		// Two independent money tiers:
		//   - master_level (site tier from hub API key: free|pro|master_pro|master_premium)
		//   - user_plan (local membership: free|pro|plus)
		// If the hub has set this site to pro/premium, admin users should not see features
		// gated behind "pro" as locked. Use whichever tier is higher.
		$user_plan = 'free';
		if ( class_exists( 'BizCity_Membership_Manager' ) ) {
			$user_plan = BizCity_Membership_Manager::instance()->plan_for_user( get_current_user_id() );
		}
		// Map hub master_level → local plan slug for comparison.
		// [2026-06-10 Johnny Chu] HOTFIX — per-site option
		$hub_level        = (string) get_option( 'bizcity_hub_master_level', 'free' );
		$hub_level_map    = array(
			'free'             => 'free',
			'pro'              => 'pro',
			'master_pro'       => 'pro',
			'master_premium'   => 'plus',
			'premium'          => 'plus',
			'master_plus'      => 'plus',
			'plus'             => 'plus',
		);
		$hub_plan         = isset( $hub_level_map[ $hub_level ] ) ? $hub_level_map[ $hub_level ] : 'free';
		// Use whichever grants more access.
		if ( BizCity_Twin_Shell_Registry::plan_order( $hub_plan ) > BizCity_Twin_Shell_Registry::plan_order( $user_plan ) ) {
			$user_plan = $hub_plan;
		}

		foreach ( $plugins as $p ) {
			if ( ! empty( $p['capability'] ) && ! current_user_can( $p['capability'] ) ) {
				continue;
			}

			$has_plan_gate = ! empty( $p['plan'] ) && 'free' !== $p['plan'];

			if ( $has_plan_gate ) {
				// Plan-gated entries are ALWAYS shown in ActivityBar (upgrade incentive).
				// plan_locked  = user tier is below required tier.
				// plugin_locked = plan ok but plugin (requires) not installed.
				$plan_locked = BizCity_Twin_Shell_Registry::plan_order( $user_plan )
				               < BizCity_Twin_Shell_Registry::plan_order( $p['plan'] );

				// [2026-06-09 Johnny Chu] HOTFIX — if hub_plan covers the plan requirement,
				// bypass plugin_locked (don't require the add-on class to be present).
				// Rationale: master_pro site = plugin is provisioned server-side; if the class
				// doesn't exist the /crm/ iframe will give its own error — not TwinShell's job.
				$hub_covers_plan = BizCity_Twin_Shell_Registry::plan_order( $hub_plan )
				                   >= BizCity_Twin_Shell_Registry::plan_order( $p['plan'] );

				// [2026-09-13 09:15 PM Johnny Chu - Chu Hoàng Anh] PHASE-1.29 — private Pro utilities stay locked when their package is absent, even if the Hub plan is sufficient.
				$plugin_locked = ( ! $plan_locked )
				                 && ( ! empty( $p['pro_package'] ) || ! $hub_covers_plan )
				                 && ( ! empty( $p['requires'] ) )
				                 && ( ! BizCity_Twin_Shell_Registry::requirement_met( $p['requires'] ) );

				$p['plan_locked']   = $plan_locked;
				$p['plan_badge']    = strtoupper( $p['plan'] ); // e.g. 'PRO', 'PLUS'
				$p['plugin_locked'] = $plugin_locked;
				$visible[] = $p;
				continue;
			}

			// Non-plan-gated: original requires gate.
			if ( ! empty( $p['locked'] ) ) {
				$locked_map[ $p['id'] ] = $p;
				continue;
			}
			$p['plan_locked']   = false;
			$p['plan_badge']    = '';
			$p['plugin_locked'] = false;
			$visible[] = $p;
		}

		// Resolve initial plugin from ?plugin=, falling back to default.
		$req_plugin = isset( $_GET['plugin'] ) ? sanitize_key( wp_unslash( $_GET['plugin'] ) ) : '';

		// [2026-06-08 Johnny Chu] PHASE-MEMBERSHIP — bookmarked URL hitting a
		// plan-locked or plugin-locked entry → short-circuit with the appropriate notice.
		if ( '' !== $req_plugin ) {
			foreach ( $visible as $p_entry ) {
				if ( $p_entry['id'] !== $req_plugin ) {
					continue;
				}
				if ( ! empty( $p_entry['plan_locked'] ) ) {
					// [2026-07-09 Johnny Chu] PHASE-TWINSHELL-ACTIVITY-LOG — track plan gate blocks.
					$this->emit_activity_event( 'shell.guard.plan_locked', array(
						'outcome'       => 'blocked',
						'plugin_id'     => (string) $p_entry['id'],
						'route'         => self::shell_url( array( 'plugin' => (string) $p_entry['id'] ) ),
						'user_plan'     => (string) $user_plan,
						'required_plan' => isset( $p_entry['plan'] ) ? (string) $p_entry['plan'] : 'free',
					) );
					$this->render_plan_locked_notice( $p_entry );
					return;
				}
				if ( ! empty( $p_entry['plugin_locked'] ) ) {
					// [2026-07-09 Johnny Chu] PHASE-TWINSHELL-ACTIVITY-LOG — track unavailable plugin blocks.
					$this->emit_activity_event( 'shell.guard.plugin_locked', array(
						'outcome'   => 'blocked',
						'plugin_id' => (string) $p_entry['id'],
						'route'     => self::shell_url( array( 'plugin' => (string) $p_entry['id'] ) ),
					) );
					$this->render_locked_notice( $p_entry );
					return;
				}
				break;
			}
		}

		// Bookmarked URL hitting a (plugin not installed) locked entry.
		if ( '' !== $req_plugin && isset( $locked_map[ $req_plugin ] ) ) {
			// [2026-07-09 Johnny Chu] PHASE-TWINSHELL-ACTIVITY-LOG — locked plugin reached via deep-link.
			$this->emit_activity_event( 'shell.guard.plugin_locked', array(
				'outcome'   => 'blocked',
				'plugin_id' => (string) $req_plugin,
				'route'     => self::shell_url( array( 'plugin' => (string) $req_plugin ) ),
			) );
			$this->render_locked_notice( $locked_map[ $req_plugin ] );
			return;
		}

		$initial = '';
		foreach ( $visible as $p ) {
			if ( $p['id'] === $req_plugin ) {
				$initial = $p['id'];
				break;
			}
		}
		if ( '' === $initial ) {
			$initial = $registry->default_id();
			// Make sure default is in visible list.
			$ok = false;
			foreach ( $visible as $p ) {
				if ( $p['id'] === $initial ) { $ok = true; break; }
			}
			if ( ! $ok && ! empty( $visible ) ) {
				$initial = $visible[0]['id'];
			}
		}

		$initial_url = $initial ? $registry->build_iframe_url( $initial, $_GET ) : '';

		// [2026-07-09 Johnny Chu] PHASE-TWINSHELL-ACTIVITY-LOG — core shell navigation activity evidence.
		$open_route = '' !== $req_plugin
			? self::shell_url( array( 'plugin' => (string) $req_plugin ) )
			: self::shell_url();
		$this->emit_activity_event( 'shell.nav.open_shell', array(
			'route'   => $open_route,
			'target'  => 'shell',
			'outcome' => 'success',
		) );

		if ( '' !== $initial ) {
			$nav_action = '' !== $req_plugin ? 'shell.nav.open_deep_link' : 'shell.nav.open_plugin';
			$this->emit_activity_event( $nav_action, array(
				'plugin_id' => (string) $initial,
				'route'     => (string) $initial_url,
				'target'    => (string) $initial,
				'outcome'   => 'success',
			) );
		}

		$config = (string) wp_json_encode( [
			'restRoot'      => esc_url_raw( rest_url( 'bizcity-twinchat/v1/' ) ),
			'nonce'         => wp_create_nonce( 'wp_rest' ),
			'plugins'       => $visible,
			'defaultPlugin' => $initial,
			'initialUrl'    => $initial_url,
			// [2026-09-16 02:30 PM Johnny Chu - Chu Hoàng Anh] PHASE-TWINSHELL-CHROME-HOTFIX — carry the embed + loop-breaker markers so nested navigations resolve to the embedded shell instead of bouncing back into wp-admin.
			'shellUrl'      => esc_url_raw( self::shell_url( self::embedded_args( $this->is_embedded ) ) ),
			'pluginUrl'     => BIZCITY_TWIN_SHELL_URL,
		] );

		$ver       = BIZCITY_TWIN_SHELL_VERSION;
		$site_name = esc_html( get_bloginfo( 'name' ) );
		$lang      = esc_attr( get_bloginfo( 'language' ) );

		$js_file   = BIZCITY_TWIN_SHELL_DIR . 'assets/twin-shell.js';
		$css_file  = BIZCITY_TWIN_SHELL_DIR . 'assets/twin-shell.css';
		$js_ver    = file_exists( $js_file )  ? filemtime( $js_file )  : $ver;
		$css_ver   = file_exists( $css_file ) ? filemtime( $css_file ) : $ver;

		$shell_js  = BIZCITY_TWIN_SHELL_URL . 'assets/twin-shell.js?ver='  . $js_ver;
		$shell_css = BIZCITY_TWIN_SHELL_URL . 'assets/twin-shell.css?ver=' . $css_ver;

		// Phase 0.13 — primitives bundle (picker, source upload, monitor stub).
		$prim_js_file  = BIZCITY_TWIN_SHELL_DIR . 'assets/primitives/twin-primitives.js';
		$prim_css_file = BIZCITY_TWIN_SHELL_DIR . 'assets/primitives/twin-primitives.css';
		$upload_js_file = BIZCITY_TWIN_SHELL_DIR . 'assets/primitives/twin-source-upload.js';
		$prim_js  = BIZCITY_TWIN_SHELL_URL . 'assets/primitives/twin-primitives.js?ver='  . ( file_exists( $prim_js_file )  ? filemtime( $prim_js_file )  : $ver );
		$prim_css = BIZCITY_TWIN_SHELL_URL . 'assets/primitives/twin-primitives.css?ver=' . ( file_exists( $prim_css_file ) ? filemtime( $prim_css_file ) : $ver );
		$upload_js = BIZCITY_TWIN_SHELL_URL . 'assets/primitives/twin-source-upload.js?ver=' . ( file_exists( $upload_js_file ) ? filemtime( $upload_js_file ) : $ver );
		$prim_cfg = (string) wp_json_encode( [
			'restRoot' => esc_url_raw( rest_url( BizCity_Twin_Shell_Primitives::NS . '/' ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'userId'   => get_current_user_id(),
			'plugin'   => 'twinshell',
		] );

		header( 'Content-Type: text/html; charset=utf-8' );
		echo '<!DOCTYPE html>' . "\n";
		echo '<html lang="' . $lang . '">' . "\n";
		echo '<head>' . "\n";
		echo '<meta charset="utf-8">' . "\n";
		echo '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n";
		echo '<title>Twin — ' . $site_name . '</title>' . "\n";
		echo '<link rel="stylesheet" href="' . esc_url( $shell_css ) . '">' . "\n";
		echo '<link rel="stylesheet" href="' . esc_url( $prim_css ) . '">' . "\n";
		echo '<style>html,body{margin:0;padding:0;height:100%;overflow:hidden;background:#0f1115;color:#e6e6e6;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;}</style>' . "\n";
		echo '</head>' . "\n";
		echo '<body>' . "\n";
		echo '<div id="twin-shell"></div>' . "\n";
		echo '<script data-cfasync="false">window.BIZCITY_TWIN_SHELL = ' . $config . ';</script>' . "\n";
		echo '<script data-cfasync="false">window.BIZCITY_TWIN_PRIMITIVES_CFG = ' . $prim_cfg . ';</script>' . "\n";
		echo '<script data-cfasync="false" src="' . esc_url( $prim_js ) . '"></script>' . "\n";
		echo '<script data-cfasync="false" src="' . esc_url( $upload_js ) . '"></script>' . "\n";
		echo '<script data-cfasync="false" src="' . esc_url( $shell_js ) . '"></script>' . "\n";
		echo '</body></html>' . "\n";
	}

	public static function on_activate() {
		delete_option( self::OPTION_KEY );
	}

	/**
	 * Render the "Pro / not active" notice for a locked plugin when reached
	 * via a bookmarked /twin/?plugin=<id> URL. Stand-alone HTML page (no
	 * shell JS / iframe) so the user sees the message instantly.
	 *
	 * @param array $p Locked plugin entry (from the registry).
	 */
	private function render_locked_notice( $p ) {
		$shell_url   = esc_url( self::shell_url( self::embedded_args( $this->is_embedded ) ) );
		$account_url = 'https://bizcity.vn/my-account/';
		$label       = isset( $p['label'] ) ? (string) $p['label'] : (string) $p['id'];
		$emoji       = isset( $p['emoji'] ) && $p['emoji'] !== '' ? (string) $p['emoji'] : '🔒';
		$desc        = isset( $p['desc'] ) ? (string) $p['desc'] : '';
		$pro_package = isset( $p['pro_package'] ) ? (string) $p['pro_package'] : '';
		$lang        = esc_attr( get_bloginfo( 'language' ) );
		$site_name   = esc_html( get_bloginfo( 'name' ) );

		header( 'Content-Type: text/html; charset=utf-8' );
		echo '<!DOCTYPE html>' . "\n";
		echo '<html lang="' . $lang . '">' . "\n";
		echo '<head>' . "\n";
		echo '<meta charset="utf-8">' . "\n";
		echo '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n";
		echo '<title>' . esc_html( $label ) . ' — ' . $site_name . '</title>' . "\n";
		echo '<style>'
			. 'html,body{margin:0;padding:0;height:100%;background:#0f1115;color:#e6e6e6;'
			. 'font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;}'
			. '.wrap{min-height:100%;display:flex;align-items:center;justify-content:center;padding:24px;}'
			. '.card{max-width:520px;width:100%;background:#171a21;border:1px solid #262b36;'
			. 'border-radius:16px;padding:32px;box-shadow:0 8px 32px rgba(0,0,0,.4);text-align:center;}'
			. '.emoji{font-size:48px;line-height:1;margin-bottom:12px;}'
			. 'h1{font-size:22px;font-weight:600;margin:0 0 8px;color:#fff;}'
			. '.badge{display:inline-block;background:#3b2f1a;color:#facc15;font-size:11px;'
			. 'font-weight:600;letter-spacing:.5px;text-transform:uppercase;padding:4px 10px;'
			. 'border-radius:999px;margin-bottom:16px;}'
			. 'p{font-size:14px;line-height:1.6;color:#a1a8b7;margin:0 0 8px;}'
			. '.actions{margin-top:24px;display:flex;gap:12px;justify-content:center;flex-wrap:wrap;}'
			. '.btn{display:inline-flex;align-items:center;justify-content:center;padding:10px 18px;'
			. 'border-radius:10px;font-size:14px;font-weight:500;text-decoration:none;transition:.15s;}'
			. '.btn-primary{background:#6366f1;color:#fff;}'
			. '.btn-primary:hover{background:#4f46e5;}'
			. '.btn-ghost{background:transparent;color:#a1a8b7;border:1px solid #2a3040;}'
			. '.btn-ghost:hover{color:#fff;border-color:#3a4257;}'
			. '</style>' . "\n";
		echo '</head><body>' . "\n";
		echo '<div class="wrap"><div class="card">' . "\n";
		echo '<div class="emoji">' . esc_html( $emoji ) . '</div>' . "\n";
		echo '<div class="badge">' . esc_html__( 'Pro / Add-on', 'bizcity-twin-ai' ) . '</div>' . "\n";
		echo '<h1>' . esc_html( $label ) . '</h1>' . "\n";
		$notice = $pro_package !== ''
			? sprintf( 'Tính năng này thuộc gói Pro của BizCity. Cài đặt và kích hoạt plugin %s để tiếp tục.', $pro_package )
			: 'Tính năng này thuộc gói Pro của BizCity. Cài đặt và kích hoạt plugin tương ứng để tiếp tục.';
		echo '<p>' . esc_html( $notice ) . '</p>' . "\n";
		if ( '' !== $desc ) {
			echo '<p style="margin-top:8px;color:#7b8294;">' . esc_html( $desc ) . '</p>' . "\n";
		}
		echo '<div class="actions">' . "\n";
		echo '<a class="btn btn-primary" href="' . esc_url( $account_url ) . '" target="_blank" rel="noopener">'
			. esc_html__( 'Nâng cấp / Quản lý gói', 'bizcity-twin-ai' ) . '</a>' . "\n";
		echo '<a class="btn btn-ghost" href="' . $shell_url . '">'
			. esc_html__( '← Quay lại Twin', 'bizcity-twin-ai' ) . '</a>' . "\n";
		echo '</div></div></div>' . "\n";
		echo '</body></html>' . "\n";
	}

	/**
	 * Render the "Plan required" notice when a plan-gated plugin is accessed
	 * without the required membership tier.
	 *
	 * [2026-06-08 Johnny Chu] PHASE-MEMBERSHIP — plan gate notice.
	 *
	 * @param array $p Plugin entry (from registry, with plan_badge set).
	 */
	private function render_plan_locked_notice( $p ) {
		$shell_url   = esc_url( self::shell_url( self::embedded_args( $this->is_embedded ) ) );
		$account_url = 'https://bizcity.vn/my-account/';
		$label       = isset( $p['label'] ) ? (string) $p['label'] : (string) $p['id'];
		$emoji       = isset( $p['emoji'] ) && '' !== $p['emoji'] ? (string) $p['emoji'] : '⭐';
		$desc        = isset( $p['desc'] ) ? (string) $p['desc'] : '';
		$plan_badge  = isset( $p['plan_badge'] ) ? strtoupper( (string) $p['plan_badge'] ) : 'PRO';
		$pro_package = isset( $p['pro_package'] ) ? (string) $p['pro_package'] : '';
		$lang        = esc_attr( get_bloginfo( 'language' ) );
		$site_name   = esc_html( get_bloginfo( 'name' ) );

		// Badge gradient: PRO = gold, PLUS/PREMIUM = purple.
		$badge_bg = ( 'PRO' === $plan_badge )
			? 'linear-gradient(135deg,#f59e0b,#d97706)'
			: 'linear-gradient(135deg,#8b5cf6,#6d28d9)';

		header( 'Content-Type: text/html; charset=utf-8' );
		echo '<!DOCTYPE html>' . "\n";
		echo '<html lang="' . $lang . '">' . "\n";
		echo '<head>' . "\n";
		echo '<meta charset="utf-8">' . "\n";
		echo '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n";
		echo '<title>' . esc_html( $label ) . ' — ' . $site_name . '</title>' . "\n";
		echo '<style>'
			. 'html,body{margin:0;padding:0;height:100%;background:#0f1115;color:#e6e6e6;'
			. 'font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;}'
			. '.wrap{min-height:100%;display:flex;align-items:center;justify-content:center;padding:24px;}'
			. '.card{max-width:520px;width:100%;background:#171a21;border:1px solid #262b36;'
			. 'border-radius:16px;padding:32px;box-shadow:0 8px 32px rgba(0,0,0,.4);text-align:center;}'
			. '.emoji{font-size:48px;line-height:1;margin-bottom:12px;}'
			. 'h1{font-size:22px;font-weight:600;margin:0 0 8px;color:#fff;}'
			. '.badge{display:inline-flex;align-items:center;gap:4px;padding:4px 12px;border-radius:999px;'
			. 'font-size:11px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;'
			. 'color:#fff;margin-bottom:16px;background:' . esc_attr( $badge_bg ) . ';}'
			. 'p{font-size:14px;line-height:1.6;color:#a1a8b7;margin:0 0 8px;}'
			. '.perks{margin:16px 0;padding:12px 16px;background:#0d1117;border-radius:10px;'
			. 'text-align:left;font-size:13px;color:#8a8f9b;line-height:1.8;}'
			. '.actions{margin-top:24px;display:flex;gap:12px;justify-content:center;flex-wrap:wrap;}'
			. '.btn{display:inline-flex;align-items:center;justify-content:center;padding:10px 18px;'
			. 'border-radius:10px;font-size:14px;font-weight:500;text-decoration:none;transition:.15s;}'
			. '.btn-primary{background:' . esc_attr( $badge_bg ) . ';color:#fff;}'
			. '.btn-ghost{background:transparent;color:#a1a8b7;border:1px solid #2a3040;}'
			. '.btn-ghost:hover{color:#fff;border-color:#3a4257;}'
			. '</style>' . "\n";
		echo '</head><body>' . "\n";
		echo '<div class="wrap"><div class="card">' . "\n";
		echo '<div class="emoji">' . esc_html( $emoji ) . '</div>' . "\n";
		echo '<div class="badge">⭐ ' . esc_html( $plan_badge ) . '</div>' . "\n";
		echo '<h1>' . esc_html( $label ) . '</h1>' . "\n";
		$notice = $pro_package !== ''
			? sprintf( 'Tính năng này thuộc gói Pro của BizCity. Cài đặt và kích hoạt plugin %s để tiếp tục.', $pro_package )
			: sprintf( 'Tính năng này yêu cầu gói %s. Nâng cấp để sử dụng ngay.', $plan_badge );
		echo '<p>' . esc_html( $notice ) . '</p>' . "\n";
		if ( '' !== $desc ) {
			echo '<p style="margin-top:8px;color:#7b8294;">' . esc_html( $desc ) . '</p>' . "\n";
		}
		echo '<div class="actions">' . "\n";
		echo '<a class="btn btn-primary" href="' . esc_url( $account_url ) . '" target="_blank" rel="noopener">'
			/* translators: %s: plan name e.g. PRO */
			. sprintf( esc_html__( 'Nâng cấp lên %s', 'bizcity-twin-ai' ), esc_html( $plan_badge ) )
			. '</a>' . "\n";
		echo '<a class="btn btn-ghost" href="' . $shell_url . '">'
			. esc_html__( '← Quay lại Twin', 'bizcity-twin-ai' ) . '</a>' . "\n";
		echo '</div></div></div>' . "\n";
		echo '</body></html>' . "\n";
	}
}
