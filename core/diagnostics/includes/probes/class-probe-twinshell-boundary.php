<?php
/**
 * BizCity Diagnostics — core.twinshell.boundary probe
 *
 * [2026-07-09 Johnny Chu] PHASE-TWINSHELL-IMPL — R-DDV probe for TwinShell
 * boundary stack (bootstrap/page/registry/rest/bridge).
 *
 * 3-layer evidence:
 *   Layer 1 (Disk)   — critical TwinShell files exist + no BOM + key guards present.
 *   Layer 2 (Loader) — core classes loaded + hooks registered.
 *   Layer 3 (Runtime)— REST routes /shell/plugins + /shell/self registered (GET),
 *                      registry payload shape stable, iframe URL carries bizcity_iframe=1.
 *
 * [2026-09-19 Johnny Chu - Chu Hoàng Anh] PHASE-0-RULE-URL-ROUTE — Layer 3 also carries the
 * R-ROUTE contract (TRC v1 §4.10): every `route_mode != 'legacy'` entry must resolve to a
 * non-empty iframe entry (`route_entry` or `public_slug` — mirrors `routeEntryBase()` in
 * twin-shell.js), and every declared `legacy_params` template must be a non-empty path starting
 * with '/'. Both FAIL (not warn) on violation — a plugin silently unreachable through its own
 * ActivityBar button is a real regression, not a "not migrated yet" state.
 *
 * Read-only probe — no user data mutation.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-07-09 (PHASE-TWINSHELL-IMPL)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( ! interface_exists( 'BizCity_Diagnostics_Probe' ) ) {
	require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';
}

if ( class_exists( 'BizCity_Probe_TwinShell_Boundary', false ) ) {
	return;
}

final class BizCity_Probe_TwinShell_Boundary implements BizCity_Diagnostics_Probe {

	public function id(): string          { return 'core.twinshell.boundary'; }
	public function label(): string       { return 'TwinShell · Boundary Stack (bootstrap/page/registry/rest/bridge)'; }
	public function description(): string {
		return 'R-DDV cho TwinShell boundary: file guards, class/hook load, REST /shell/plugins + /shell/self, registry contract, iframe gate và R-ROUTE contract (route_mode entries có iframe entry hợp lệ, legacy_params template hợp lệ).';
	}
	public function severity(): string    { return 'warning'; }
	public function order(): int          { return 62; }
	public function icon(): string        { return 'layout-dashboard'; }
	public function estimate_ms(): int    { return 250; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_Twin_Shell_Registry' ) ) {
			return new WP_Error(
				'twinshell_not_loaded',
				'BizCity_Twin_Shell_Registry chưa load — kiểm tra modules/twinshell/bootstrap.php.'
			);
		}
		return true;
	}

	public function run( $ctx ): array {
		$failed = false;

		/* ------------------------------------------------------------
		 * Layer 1 — Disk
		 * ------------------------------------------------------------ */
		$base = WP_PLUGIN_DIR . '/bizcity-twin-ai/modules/twinshell/';
		$disk_checks = array(
			'bootstrap.php' => array(
				'path'    => $base . 'bootstrap.php',
				'markers' => array( 'BIZCITY_TWIN_SHELL_BOOTSTRAPPED', 'BizCity_Rewrite_Flush_Registry::register' ),
			),
			'class-twin-shell-page.php' => array(
				'path'    => $base . 'includes/class-twin-shell-page.php',
				'markers' => array( 'emit_activity_event', 'shell.guard.plan_locked', 'private $registered = false' ),
			),
			'class-twin-shell-registry.php' => array(
				'path'    => $base . 'includes/class-twin-shell-registry.php',
				'markers' => array( 'has_plan_gate', 'plan_badge', 'build_iframe_url' ),
			),
			'class-twin-shell-rest.php' => array(
				'path'    => $base . 'includes/class-twin-shell-rest.php',
				'markers' => array( '/shell/plugins', '/shell/self', 'permission_logged_in' ),
			),
			'class-twin-shell-bridge.php' => array(
				'path'    => $base . 'includes/class-twin-shell-bridge.php',
				'markers' => array( 'is_embed_intent_request', 'sessionId', 'traceId' ),
			),
		);

		foreach ( $disk_checks as $label => $cfg ) {
			$path = $cfg['path'];
			if ( ! file_exists( $path ) ) {
				$failed = true;
				$ctx->emit_step( array(
					'label'  => 'Layer 1 · Disk · ' . $label,
					'status' => 'fail',
					'detail' => 'Missing file: ' . $path,
				) );
				continue;
			}

			$first3 = file_get_contents( $path, false, null, 0, 3 );
			if ( $first3 === "\xEF\xBB\xBF" ) {
				$failed = true;
				$ctx->emit_step( array(
					'label'  => 'Layer 1 · Disk · ' . $label,
					'status' => 'fail',
					'detail' => 'BOM detected (UTF-8 BOM) — must be no-BOM for PHP files.',
				) );
				continue;
			}

			$src = (string) file_get_contents( $path );
			$missing_markers = array();
			foreach ( (array) $cfg['markers'] as $marker ) {
				if ( strpos( $src, (string) $marker ) === false ) {
					$missing_markers[] = (string) $marker;
				}
			}

			$ok = empty( $missing_markers );
			if ( ! $ok ) {
				$failed = true;
			}
			$ctx->emit_step( array(
				'label'  => 'Layer 1 · Disk · ' . $label,
				'status' => $ok ? 'pass' : 'fail',
				'detail' => $ok
					? 'exists + no BOM + markers ok'
					: 'missing markers: ' . implode( ', ', $missing_markers ),
			) );
		}

		/* ------------------------------------------------------------
		 * Layer 2 — Loader
		 * ------------------------------------------------------------ */
		$class_expect = array(
			'BizCity_Twin_Shell_Page',
			'BizCity_Twin_Shell_Registry',
			'BizCity_Twin_Shell_REST',
			'BizCity_Twin_Shell_Bridge',
		);
		$missing_classes = array();
		foreach ( $class_expect as $cls ) {
			if ( ! class_exists( $cls ) ) {
				$missing_classes[] = $cls;
			}
		}
		$class_ok = empty( $missing_classes );
		if ( ! $class_ok ) {
			$failed = true;
		}
		$ctx->emit_step( array(
			'label'  => 'Layer 2 · Loader · TwinShell classes',
			'status' => $class_ok ? 'pass' : 'fail',
			'detail' => $class_ok ? 'all core classes loaded' : 'missing: ' . implode( ', ', $missing_classes ),
		) );

		$hook_checks = array(
			'template_redirect::maybe_render' => has_action( 'template_redirect', array( BizCity_Twin_Shell_Page::instance(), 'maybe_render' ) ),
			'rest_api_init::register_routes' => has_action( 'rest_api_init', array( BizCity_Twin_Shell_REST::instance(), 'register_routes' ) ),
			'wp_enqueue_scripts::bridge' => has_action( 'wp_enqueue_scripts', array( BizCity_Twin_Shell_Bridge::instance(), 'maybe_enqueue' ) ),
		);
		foreach ( $hook_checks as $hook_label => $priority ) {
			$ok = false !== $priority;
			if ( ! $ok ) {
				$failed = true;
			}
			$ctx->emit_step( array(
				'label'  => 'Layer 2 · Hook · ' . $hook_label,
				'status' => $ok ? 'pass' : 'fail',
				'detail' => $ok ? 'priority=' . (string) $priority : 'missing hook',
			) );
		}

		/* ------------------------------------------------------------
		 * Layer 3 — Runtime
		 * ------------------------------------------------------------ */
		$server = rest_get_server();
		$routes = (array) $server->get_routes();

		$route_expect = array(
			'/bizcity-twinchat/v1/shell/plugins' => 'GET',
			'/bizcity-twinchat/v1/shell/self'    => 'GET',
		);
		foreach ( $route_expect as $route_key => $must_method ) {
			if ( ! isset( $routes[ $route_key ] ) ) {
				$failed = true;
				$ctx->emit_step( array(
					'label'  => 'Layer 3 · REST · ' . $route_key,
					'status' => 'fail',
					'detail' => 'route missing',
				) );
				continue;
			}

			$methods = array();
			$perm_cb_ok = false;
			foreach ( (array) $routes[ $route_key ] as $ep ) {
				if ( ! is_array( $ep ) ) {
					continue;
				}
				if ( ! empty( $ep['methods'] ) && is_array( $ep['methods'] ) ) {
					foreach ( $ep['methods'] as $m => $enabled ) {
						if ( $enabled ) {
							$methods[] = strtoupper( (string) $m );
						}
					}
				}
				if ( ! empty( $ep['permission_callback'] ) && is_array( $ep['permission_callback'] ) ) {
					$cb = $ep['permission_callback'];
					if ( isset( $cb[1] ) && (string) $cb[1] === 'permission_logged_in' ) {
						$perm_cb_ok = true;
					}
				}
			}

			$methods = array_values( array_unique( $methods ) );
			$method_ok = in_array( strtoupper( $must_method ), $methods, true );
			$ok = $method_ok;
			if ( '/bizcity-twinchat/v1/shell/self' === $route_key ) {
				$ok = $ok && $perm_cb_ok;
			}
			if ( ! $ok ) {
				$failed = true;
			}
			$ctx->emit_step( array(
				'label'  => 'Layer 3 · REST · ' . $route_key,
				'status' => $ok ? 'pass' : 'fail',
				'detail' => 'methods=' . implode( '|', $methods )
					. ( '/bizcity-twinchat/v1/shell/self' === $route_key ? ( ' · perm_cb=' . ( $perm_cb_ok ? 'permission_logged_in' : 'missing/other' ) ) : '' ),
			) );
		}

		$registry = BizCity_Twin_Shell_Registry::instance();
		$plugins  = (array) $registry->all();
		$schema_ok = true;
		$schema_missing = array();
		foreach ( $plugins as $i => $p ) {
			if ( ! is_array( $p ) ) {
				$schema_ok = false;
				$schema_missing[] = 'row_' . $i . ' not array';
				continue;
			}
			$required_keys = array( 'id', 'label', 'mode', 'section', 'is_core', 'available', 'locked', 'has_plan_gate', 'plan_badge' );
			foreach ( $required_keys as $rk ) {
				if ( ! array_key_exists( $rk, $p ) ) {
					$schema_ok = false;
					$schema_missing[] = (string) ( isset( $p['id'] ) ? $p['id'] : 'row_' . $i ) . '.' . $rk;
				}
			}
		}
		if ( ! $schema_ok ) {
			$failed = true;
		}
		$ctx->emit_step( array(
			'label'  => 'Layer 3 · Registry payload schema',
			'status' => $schema_ok ? 'pass' : 'fail',
			'detail' => $schema_ok
				? 'plugins=' . count( $plugins ) . ' · required keys present'
				: 'missing=' . implode( ', ', array_slice( $schema_missing, 0, 8 ) ),
		) );

		// [2026-09-13 10:30 PM Johnny Chu - Chu Hoàng Anh] PHASE-1.29 — prove visible Pro activity entries carry an explicit package owner for their badge/notice contract.
		$pro_ids = array( 'astro', 'doc', 'image', 'video' );
		$pro_rows = array();
		foreach ( $plugins as $plugin_row ) {
			if ( is_array( $plugin_row ) && in_array( (string) ( $plugin_row['id'] ?? '' ), $pro_ids, true ) ) {
				$pro_rows[ (string) $plugin_row['id'] ] = $plugin_row;
			}
		}
		$pro_contract_ok = true;
		$pro_missing = array();
		foreach ( $pro_ids as $pro_id ) {
			if ( ! isset( $pro_rows[ $pro_id ] ) ) {
				$pro_contract_ok = false;
				$pro_missing[] = $pro_id . '.missing';
				continue;
			}
			if ( (string) ( $pro_rows[ $pro_id ]['plan_badge'] ?? '' ) !== 'PRO' ) {
				$pro_contract_ok = false;
				$pro_missing[] = $pro_id . '.plan_badge';
			}
			if ( trim( (string) ( $pro_rows[ $pro_id ]['pro_package'] ?? '' ) ) === '' ) {
				$pro_contract_ok = false;
				$pro_missing[] = $pro_id . '.pro_package';
			}
		}
		if ( ! $pro_contract_ok ) {
			$failed = true;
		}
		$ctx->emit_step( array(
			'label'  => 'Layer 3 · Registry Pro badge/package contract',
			'status' => $pro_contract_ok ? 'pass' : 'fail',
			'detail' => $pro_contract_ok ? 'Astro/Doc/Image/Video entries expose PRO badge and package owner.' : 'missing=' . implode( ', ', $pro_missing ),
		) );

		// [2026-09-16 04:30 PM Johnny Chu - Chu Hoàng Anh] PHASE-TWINSHELL-NAV-GROUP — prove the ActivityBar grouping contract: every plan-gated (non-must-load) entry sits in the bottom group, and QR Studio is the last entry of the top group so it is the visible boundary.
		$group_contract_ok = true;
		$group_problems    = array();
		$top_ids           = array();
		$last_top_id       = '';
		foreach ( $plugins as $plugin_row ) {
			if ( ! is_array( $plugin_row ) ) {
				continue;
			}
			$row_id      = (string) ( $plugin_row['id'] ?? '' );
			$row_section = (string) ( $plugin_row['section'] ?? '' );
			if ( 'top' === $row_section ) {
				$top_ids[]     = $row_id;
				$last_top_id   = $row_id;
			}
			if ( ! empty( $plugin_row['has_plan_gate'] ) && 'bottom' !== $row_section ) {
				$group_contract_ok = false;
				$group_problems[]  = $row_id . '.section=' . $row_section . ' (expected bottom)';
			}
		}
		if ( 'qr' !== $last_top_id ) {
			$group_contract_ok = false;
			$group_problems[]  = 'last_top=' . ( $last_top_id !== '' ? $last_top_id : 'none' ) . ' (expected qr)';
		}
		if ( ! $group_contract_ok ) {
			$failed = true;
		}
		$ctx->emit_step( array(
			'label'  => 'Layer 3 · ActivityBar grouping boundary',
			'status' => $group_contract_ok ? 'pass' : 'fail',
			'detail' => $group_contract_ok
				? 'plan-gated entries are bottom · top group ends with qr (top=' . count( $top_ids ) . ')'
				: implode( ', ', array_slice( $group_problems, 0, 8 ) ),
		) );

		$default_id = (string) $registry->default_id();
		$iframe_url = $default_id !== '' ? (string) $registry->build_iframe_url( $default_id, array() ) : '';
		$iframe_ok  = $iframe_url !== '' && strpos( $iframe_url, 'bizcity_iframe=1' ) !== false;
		if ( ! $iframe_ok ) {
			$failed = true;
		}
		$ctx->emit_step( array(
			'label'  => 'Layer 3 · iframe URL guard param',
			'status' => $iframe_ok ? 'pass' : 'fail',
			'detail' => $iframe_ok
				? 'default=' . $default_id . ' · bizcity_iframe=1 present'
				: 'default=' . $default_id . ' · invalid iframe url',
		) );

		/* ------------------------------------------------------------
		 * [2026-09-19 Johnny Chu - Chu Hoàng Anh] PHASE-0-RULE-URL-ROUTE — R-ROUTE contract
		 * checks (TRC v1 §4.10). Mirrors the JS `routeEntryBase()` resolution logic
		 * (twin-shell.js): a `route_mode != 'legacy'` entry with NEITHER `route_entry` NOR
		 * `public_slug` set would make `buildIframeUrlForRoute()` return '' client-side,
		 * silently breaking that entry's iframe entirely (`ensureIframe()` returns null,
		 * nothing renders, no console error visible to an operator). This must FAIL, not warn
		 * — it is a genuine misconfiguration, not "not migrated yet".
		 * ------------------------------------------------------------ */
		$route_mode_ids   = array();
		$route_entry_ok   = true;
		$route_entry_bad  = array();
		$legacy_params_ok = true;
		$legacy_params_bad = array();
		foreach ( $plugins as $plugin_row ) {
			if ( ! is_array( $plugin_row ) ) {
				continue;
			}
			$row_id   = (string) ( $plugin_row['id'] ?? '' );
			$row_mode = (string) ( $plugin_row['route_mode'] ?? 'legacy' );
			if ( 'legacy' !== $row_mode ) {
				$route_mode_ids[] = $row_id . '(' . $row_mode . ')';
				$has_entry = '' !== trim( (string) ( $plugin_row['route_entry'] ?? '' ) )
					|| '' !== trim( (string) ( $plugin_row['public_slug'] ?? '' ) );
				if ( ! $has_entry ) {
					$route_entry_ok  = false;
					$route_entry_bad[] = $row_id . ' (no route_entry/public_slug — buildIframeUrlForRoute() would return empty)';
				}
			}

			$legacy_params = is_array( $plugin_row['legacy_params'] ?? null ) ? $plugin_row['legacy_params'] : array();
			foreach ( $legacy_params as $query_key => $template ) {
				$template_str = (string) $template;
				if ( '' === $template_str || '/' !== substr( $template_str, 0, 1 ) ) {
					$legacy_params_ok = false;
					$legacy_params_bad[] = $row_id . '.legacy_params.' . (string) $query_key . '="' . $template_str . '" (must be non-empty and start with /)';
				}
			}
		}
		if ( ! $route_entry_ok ) {
			$failed = true;
		}
		$ctx->emit_step( array(
			'label'  => 'Layer 3 · R-ROUTE · route_mode entries have a resolvable iframe entry',
			'status' => $route_entry_ok ? 'pass' : 'fail',
			'detail' => empty( $route_mode_ids )
				? 'no entry has opted into route_mode yet (all legacy) — nothing to check'
				: ( $route_entry_ok
					? 'route_mode entries: ' . implode( ', ', $route_mode_ids ) . ' — all have route_entry or public_slug'
					: implode( '; ', $route_entry_bad ) ),
		) );

		if ( ! $legacy_params_ok ) {
			$failed = true;
		}
		$ctx->emit_step( array(
			'label'  => 'Layer 3 · R-ROUTE · legacy_params template shape',
			'status' => $legacy_params_ok ? 'pass' : 'fail',
			'detail' => $legacy_params_ok
				? 'every declared legacy_params template is a non-empty path starting with /'
				: implode( '; ', $legacy_params_bad ),
		) );

		$uid = (int) get_current_user_id();
		if ( $uid > 0 && isset( $routes['/bizcity-twinchat/v1/shell/self'] ) ) {
			$req = new WP_REST_Request( 'GET', '/bizcity-twinchat/v1/shell/self' );
			$res = rest_do_request( $req );
			$data = $res instanceof WP_REST_Response ? $res->get_data() : array();
			$self_ok = is_array( $data )
				&& ! empty( $data['success'] )
				&& isset( $data['user']['id'] )
				&& (int) $data['user']['id'] === $uid;
			if ( ! $self_ok ) {
				$failed = true;
			}
			$ctx->emit_step( array(
				'label'  => 'Layer 3 · /shell/self current-user scope',
				'status' => $self_ok ? 'pass' : 'fail',
				'detail' => $self_ok ? 'user.id=' . $uid : 'response invalid or user mismatch',
			) );
		} else {
			$ctx->emit_step( array(
				'label'  => 'Layer 3 · /shell/self current-user scope',
				'status' => 'skip',
				'detail' => 'No logged-in user context for runtime self-route assertion.',
			) );
		}

		if ( $failed ) {
			return array(
				'status'   => 'fail',
				'summary'  => 'TwinShell boundary probe failed — xem các Layer FAIL ở trên.',
				'fix_hint' => 'Kiểm tra modules/twinshell bootstrap + route registration + registry schema keys (has_plan_gate/plan_badge) + bridge embed guard.',
			);
		}

		return array(
			'status'  => 'pass',
			'summary' => 'TwinShell boundary stack PASS: disk+loader+runtime contract ổn định.',
		);
	}

	public function cleanup(): void {
		// Read-only probe.
	}
}

// [2026-07-09 Johnny Chu] PHASE-TWINSHELL-IMPL — register probe into diagnostics catalog.
add_filter( 'bizcity_diagnostics_register_probes', function ( array $list ): array {
	$list[] = new BizCity_Probe_TwinShell_Boundary();
	return $list;
} );
