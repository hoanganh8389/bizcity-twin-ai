<?php
/**
 * Channel Gateway — Sprint Diagnostic (PHASE 0.31)
 *
 * Browser health-check that walks each task of the
 * `PHASE-0.31-INTEGRATE-CHANNEL-GATEWAY-UNIFY.md` roadmap and reports
 * whether the corresponding code artefacts are loaded, registered, and
 * wired up correctly.
 *
 * URL: /wp-admin/tools.php?page=bizcity-channel-gateway-sprint-diag
 * Capability: manage_options
 *
 * Sections:
 *   • Sprint 1 — Unblock TwinChat ra ngoài (T-S1.1 → T-S1.8)
 *   • Sprint 2 — Đủ matrix Channel cho S2-S5  (T-S2.* placeholders)
 *
 * Each row reports PASS / FAIL / SKIP + evidence (file path, class
 * name, hook count, registry size, etc.) so the operator can verify
 * the change WITHOUT shelling into the box.
 *
 * NOTE (2026-06-01 archival): All probes that inspect the legacy
 * `plugins/bizcity-automation/` folder were re-pointed to
 * `plugins/_archived/bizcity-automation/` because the bundled vertical
 * plugin was archived as part of the Phase 0.99 slim-down. The native
 * xyflow runtime in `core/automation/` (BE-1..BE-5) supersedes WAIC.
 * Probes here remain useful for un-archive / forensics scenarios; they
 * will report FAIL once the archived copy is also removed — that is the
 * intended signal that the legacy WAIC pipeline is fully retired.
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway
 * @since   PHASE 0.31
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Channel_Gateway_Sprint_Diagnostic {

	private static $instance = null;

	const PAGE_SLUG = 'bizcity-channel-gateway-sprint-diag';

	/** Probe collector — populated when run via probe wrapper. @var array<int,array> */
	private $collected_rows = array();

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Admin submenu removed (Consolidation M4, 2026-06-02). Smoke moved
		// to BizCity_Probe_Channel_Sprint. Class kept for render_page() +
		// task_row() collectors (still callable from probe wrapper).
	}

	/**
	 * Run all task checks in collect-only mode (no HTML output) and return
	 * the row dump. Used by BizCity_Probe_Channel_Sprint (Consolidation M4).
	 *
	 * @return array<int,array{task:string,status:string,check:string}>
	 */
	public function collect_results() {
		$this->collected_rows = array();
		ob_start();
		try {
			$this->render_page();
		} catch ( \Throwable $e ) {
			// fail-OPEN — return whatever rows were collected pre-throw.
			$this->collected_rows[] = array(
				'task'   => 'collect_results',
				'status' => 'FAIL',
				'check'  => 'render_page() threw: ' . $e->getMessage(),
			);
		}
		ob_end_clean();
		return $this->collected_rows;
	}

	/* =========================================================
	 * RENDER
	 * =======================================================*/

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden' );
		}

		$action  = isset( $_POST['bizcity_action'] ) ? sanitize_key( $_POST['bizcity_action'] ) : '';
		$nonce_ok = isset( $_POST['_wpnonce'] ) && wp_verify_nonce( $_POST['_wpnonce'], 'bizcity_cg_sprint_diag' );

		echo '<div class="wrap">';
		echo '<h1>BizCity Channel Gateway — Sprint Diagnostic</h1>';
		echo '<p><strong>Roadmap:</strong> <code>PHASE-0.31-INTEGRATE-CHANNEL-GATEWAY-UNIFY.md</code></p>';
		echo '<p>Each row maps 1:1 to a roadmap task. <code>PASS</code> = code change is live; <code>FAIL</code> = work missing/regressed; <code>SKIP</code> = depends on later sprint.</p>';

		// ---- Action handlers ----
		if ( $nonce_ok ) {
			if ( 'probe_send' === $action ) {
				$this->action_probe_send();
			} elseif ( 'probe_inbound' === $action ) {
				$this->action_probe_inbound();
			} elseif ( 'flush_rewrites' === $action ) {
				flush_rewrite_rules( false );
				$this->notice( 'success', 'Flushed rewrite rules.' );
			} elseif ( 'reset_opcache' === $action ) {
				$this->action_reset_opcache();
			} elseif ( 'kg_probe_rest' === $action ) {
				$this->action_kg_probe_rest();
			} elseif ( 'kg_probe_block' === $action ) {
				$this->action_kg_probe_block();
			} elseif ( 'kg_inspect_sql' === $action ) {
				$this->action_kg_inspect_sql();
			} elseif ( 'kg_set_token' === $action ) {
				$this->action_kg_set_token();
			} elseif ( 's6_4_inspect' === $action ) {
				$this->action_s6_4_inspect();
			} elseif ( 's6_4_dryrun' === $action ) {
				$this->action_s6_4_dryrun();
			} elseif ( 's6_4_migrate' === $action ) {
				$this->action_s6_4_migrate();
			}
		}

		// ---- Mu-plugin runtime sanity ----
		$this->render_mu_plugin_audit();

		// ---- Live registry overview ----
		$this->render_registry_overview();

		// ---- Sprint 1 ----
		echo '<h2 style="margin-top:30px">Sprint 1 — Unblock TwinChat ra ngoài</h2>';
		echo '<table class="widefat striped"><thead><tr>'
			. '<th style="width:90px">Task</th>'
			. '<th style="width:100px">Status</th>'
			. '<th>Check</th>'
			. '<th>Evidence</th>'
			. '</tr></thead><tbody>';

		$this->check_t_s1_1();
		$this->check_t_s1_2();
		$this->check_t_s1_3();
		$this->check_t_s1_4();
		$this->check_t_s1_5();
		$this->check_t_s1_6();
		$this->check_t_s1_7();
		$this->check_t_s1_8();

		echo '</tbody></table>';

		// ---- Sprint 1 live probes ----
		echo '<h2 style="margin-top:30px">Sprint 1 — Live probes</h2>';
		$this->render_probe_forms();

		// ---- T-S1.6 KG-Hub deep-dive ----
		$this->render_kg_section();

		// ---- Sprint 2 placeholders ----
		echo '<h2 style="margin-top:30px">Sprint 2 — Channel matrix (placeholders)</h2>';
		echo '<table class="widefat striped"><thead><tr>'
			. '<th style="width:90px">Task</th>'
			. '<th style="width:100px">Status</th>'
			. '<th>Check</th>'
			. '<th>Evidence</th>'
			. '</tr></thead><tbody>';

		$this->check_t_s2_1();
		$this->check_t_s2_2();
		$this->check_t_s2_3();
		$this->check_t_s2_4();
		$this->check_t_s2_5();
		$this->check_t_s2_6();
		$this->check_t_s2_7();

		echo '</tbody></table>';

		// Sprint 3 — Brain ⇄ Workflow events
		echo '<h2 style="margin-top:30px">Sprint 3 — Brain ⇄ Workflow events</h2>';
		echo '<table class="widefat striped"><thead><tr>'
			. '<th style="width:90px">Task</th>'
			. '<th style="width:100px">Status</th>'
			. '<th>Check</th>'
			. '<th>Evidence</th>'
			. '</tr></thead><tbody>';
		$this->check_t_s3_1();
		$this->check_t_s3_2();
		$this->check_t_s3_3();
		echo '</tbody></table>';

		// Sprint 4 — Demote bizchat-gateway + restructure menu
		echo '<h2 style="margin-top:30px">Sprint 4 — Menu restructure & gateway demotion</h2>';
		echo '<table class="widefat striped"><thead><tr>'
			. '<th style="width:90px">Task</th>'
			. '<th style="width:100px">Status</th>'
			. '<th>Check</th>'
			. '<th>Evidence</th>'
			. '</tr></thead><tbody>';
		$this->check_t_s4_1();
		$this->check_t_s4_2();
		$this->check_t_s4_3();
		echo '</tbody></table>';

		// Sprint 5 — Campaign + Loyalty + Form (S7) + Deprecate bizgpt-custom-flows
		echo '<h2 style="margin-top:30px">Sprint 5 — Campaign / Loyalty / Form / bizgpt-custom-flows migration</h2>';
		echo '<table class="widefat striped"><thead><tr>'
			. '<th style="width:90px">Task</th>'
			. '<th style="width:100px">Status</th>'
			. '<th>Check</th>'
			. '<th>Evidence</th>'
			. '</tr></thead><tbody>';
		$this->check_t_s5_1();
		$this->check_t_s5_2();
		$this->check_t_s5_3();
		$this->check_t_s5_4();
		$this->check_t_s5_5();
		echo '</tbody></table>';

		// Sprint 5.5 — Creative Canvas UX uplift (TwitCanva-inspired)
		echo '<h2 style="margin-top:30px">Sprint 5.5 — Creative Canvas UX (branch merge / test-run / wide canvas)</h2>';
		echo '<table class="widefat striped"><thead><tr>'
			. '<th style="width:90px">Task</th>'
			. '<th style="width:100px">Status</th>'
			. '<th>Check</th>'
			. '<th>Evidence</th>'
			. '</tr></thead><tbody>';
		$this->check_t_s5b_1();
		$this->check_t_s5b_2a();
		$this->check_t_s5b_2b();
		$this->check_t_s5b_3();
		echo '</tbody></table>';

		// Sprint 6 — Cleanup / unification
		echo '<h2 style="margin-top:30px">Sprint 6 — Cleanup / unification</h2>';
		echo '<table class="widefat striped"><thead><tr>'
			. '<th style="width:90px">Task</th>'
			. '<th style="width:100px">Status</th>'
			. '<th>Check</th>'
			. '<th>Evidence</th>'
			. '</tr></thead><tbody>';
		$this->check_t_s6_1();
		$this->check_t_s6_2();
		$this->check_t_s6_3();
		echo '</tbody></table>';

		// T-S6.4 migration control panel (replaces WP-CLI workflow).
		$this->render_s6_4_migration_panel();

		// Sprint 7 — PHASE 0.33 M1 (Webhook Router + daily log + Guru bindings)
		echo '<h2 style="margin-top:30px">Sprint 7 — PHASE 0.33 M1 · Webhook Gateway × Guru Unify</h2>';
		echo '<table class="widefat striped"><thead><tr>'
			. '<th style="width:90px">Task</th>'
			. '<th style="width:100px">Status</th>'
			. '<th>Check</th>'
			. '<th>Evidence</th>'
			. '</tr></thead><tbody>';
		$this->check_t_s7_1();
		$this->check_t_s7_2();
		$this->check_t_s7_3();
		$this->check_t_s7_4();
		$this->check_t_s7_5();
		$this->check_t_s7_6();
		$this->check_t_s7_7();
		$this->check_t_s7_8();
		$this->check_t_s7_9();
		$this->check_t_s7_10();
		$this->check_t_s7_11();
		$this->check_t_s7_12();
		$this->check_t_s7_13();
		echo '</tbody></table>';

		// Sprint CG-SPA — Per-platform workspace SPA
		// See: core/channel-gateway/PHASE-CG-SPA-WORKSPACE.md
		echo '<h2 style="margin-top:30px">Sprint CG-SPA · Per-platform workspace SPA</h2>';
		echo '<p>Spec: <code>core/channel-gateway/PHASE-CG-SPA-WORKSPACE.md</code></p>';
		echo '<table class="widefat striped"><thead><tr>'
			. '<th style="width:90px">Task</th>'
			. '<th style="width:100px">Status</th>'
			. '<th>Check</th>'
			. '<th>Evidence</th>'
			. '</tr></thead><tbody>';
		$this->check_t_cg_spa_1();
		$this->check_t_cg_spa_2();
		$this->check_t_cg_spa_3();
		$this->check_t_cg_spa_4();
		echo '</tbody></table>';

		echo '</div>';
	}

	/* =========================================================
	 * REGISTRY OVERVIEW
	 * =======================================================*/

	private function render_registry_overview() {
		echo '<h2>Live registries</h2>';
		echo '<table class="widefat striped" style="max-width:960px"><tbody>';

		// Channel adapters via gateway bridge
		$adapters = array();
		if ( class_exists( 'BizCity_Gateway_Bridge' ) ) {
			$bridge = BizCity_Gateway_Bridge::instance();
			if ( method_exists( $bridge, 'get_all_adapters' ) ) {
				$adapters = (array) $bridge->get_all_adapters();
			} elseif ( method_exists( $bridge, 'get_adapters' ) ) {
				$adapters = (array) $bridge->get_adapters();
			}
		}
		$adapter_list = array();
		foreach ( $adapters as $key => $a ) {
			$plat = is_object( $a ) && method_exists( $a, 'get_platform' ) ? $a->get_platform() : (string) $key;
			$adapter_list[] = sprintf( '%s → <code>%s</code>', esc_html( $plat ), esc_html( is_object( $a ) ? get_class( $a ) : (string) $a ) );
		}
		$this->row( 'Channel adapters registered (BizCity_Gateway_Bridge)', $adapter_list ? implode( '<br>', $adapter_list ) : '<em>none</em>' );

		// Channel integrations discovered via filter
		$channel_integs = apply_filters( 'bizcity_register_channel_integrations', array() );
		$integ_list = array();
		foreach ( (array) $channel_integs as $code => $entry ) {
			$cls  = is_array( $entry ) && isset( $entry['class'] ) ? $entry['class'] : (string) $entry;
			$file = is_array( $entry ) && isset( $entry['file'] )  ? $entry['file']  : '?';
			$integ_list[] = sprintf( '<code>%s</code> → <code>%s</code> <small>(%s)</small>',
				esc_html( $code ),
				esc_html( $cls ),
				esc_html( $this->short_path( $file ) )
			);
		}
		$this->row( 'Channel integrations (bizcity_register_channel_integrations)', $integ_list ? implode( '<br>', $integ_list ) : '<em>none</em>' );

		// Hook fire counts (sanity: did the action ever fire?)
		$this->row( 'did_action(bizcity_register_channel)', (int) did_action( 'bizcity_register_channel' ) );
		$this->row( 'did_action(plugins_loaded)',           (int) did_action( 'plugins_loaded' ) );

		echo '</tbody></table>';
	}

	/* =========================================================
	 * SPRINT 1 — TASK CHECKS
	 * =======================================================*/

	private function check_t_s1_1() {
		// WaicChannelIntegration skeleton in bizcity-automation
		$cls    = 'WaicChannelIntegration';
		$loaded = class_exists( $cls );
		$file   = $loaded ? ( new ReflectionClass( $cls ) )->getFileName() : '';
		$ok     = $loaded
			&& is_subclass_of( $cls, 'WaicIntegration' )
			&& method_exists( $cls, 'getAdapter' )
			&& method_exists( $cls, 'sendOutbound' )
			&& method_exists( $cls, 'getTriggerBlocks' )
			&& method_exists( $cls, 'getActionBlocks' );
		$this->task_row(
			'T-S1.1',
			$ok ? 'PASS' : 'FAIL',
			'Abstract WaicChannelIntegration loaded, extends WaicIntegration, declares channel API',
			$loaded
				? sprintf( '%s @ <code>%s</code><br>methods: getAdapter=%s, sendOutbound=%s, getTriggerBlocks=%s, getActionBlocks=%s',
					$cls,
					esc_html( $this->short_path( $file ) ),
					$this->yn( method_exists( $cls, 'getAdapter' ) ),
					$this->yn( method_exists( $cls, 'sendOutbound' ) ),
					$this->yn( method_exists( $cls, 'getTriggerBlocks' ) ),
					$this->yn( method_exists( $cls, 'getActionBlocks' ) )
				)
				: 'class WaicChannelIntegration not loaded — bizcity-automation/bootstrap.php may have skipped waicImportClass()'
		);
	}

	private function check_t_s1_2() {
		// WaicIntegrationsModel is loaded lazily by WaicFrame only when the
		// automation admin pages render. On tools.php it is NOT in memory, so
		// rely on source-file inspection to verify the patch is deployed.
		$model_file = WP_PLUGIN_DIR . '/bizcity-twin-ai/plugins/_archived/bizcity-automation/modules/workflow/models/integrations.php';
		$exists     = is_readable( $model_file );
		$src        = $exists ? (string) file_get_contents( $model_file ) : '';
		$has_method      = (bool) preg_match( '/function\s+getChannelIntegrations\s*\(/', $src );
		$has_filter      = false !== strpos( $src, 'bizcity_register_channel_integrations' );
		$has_channel_cat = (bool) preg_match( "/['\"]channel['\"]\s*=>/", $src );
		$registered = apply_filters( 'bizcity_register_channel_integrations', array() );
		$ok = $exists && $has_method && $has_filter && $has_channel_cat;
		$this->task_row(
			'T-S1.2',
			$ok ? 'PASS' : 'FAIL',
			'integrations.php has getChannelIntegrations(), filter wiring, and "channel" category',
			sprintf( 'file: %s | getChannelIntegrations(): %s | filter ref: %s | "channel" cat: %s | filter live entries: %d',
				$exists ? '<code>' . esc_html( $this->short_path( $model_file ) ) . '</code>' : 'MISSING',
				$this->yn( $has_method ),
				$this->yn( $has_filter ),
				$this->yn( $has_channel_cat ),
				count( (array) $registered )
			)
		);
	}

	private function check_t_s1_3() {
		// WaicChannelIntegration_facebook from mu-plugin via filter
		$registered = apply_filters( 'bizcity_register_channel_integrations', array() );
		$entry = isset( $registered['facebook'] ) ? $registered['facebook'] : null;
		$cls   = is_array( $entry ) && isset( $entry['class'] ) ? $entry['class'] : '';
		$file  = is_array( $entry ) && isset( $entry['file'] )  ? $entry['file']  : '';
		$expected_dir = 'mu-plugins/bizcity-facebook-bot';
		$file_ok = $file && false !== strpos( str_replace( '\\', '/', $file ), $expected_dir );
		$class_ok = $cls && class_exists( $cls ) && is_subclass_of( $cls, 'WaicChannelIntegration' );

		$ok = $entry && $file_ok && $class_ok;
		$this->task_row(
			'T-S1.3',
			$ok ? 'PASS' : 'FAIL',
			"'facebook' entry registered, file lives under mu-plugin, class extends WaicChannelIntegration",
			sprintf( 'entry: %s | class: %s | file: <code>%s</code> | mu-plugin file: %s | subclass: %s',
				$entry ? '<code>' . esc_html( wp_json_encode( $entry ) ) . '</code>' : 'MISSING',
				esc_html( $cls ),
				esc_html( $this->short_path( $file ) ),
				$this->yn( $file_ok ),
				$this->yn( $class_ok )
			)
		);
	}

	private function check_t_s1_4() {
		// Adapter migrated to mu-plugin (BUG-4)
		$mu_cls   = 'BizCity_Facebook_Bot_Channel_Adapter';
		$loaded   = class_exists( $mu_cls );
		$file     = $loaded ? ( new ReflectionClass( $mu_cls ) )->getFileName() : '';
		$mu_ok    = $loaded && false !== strpos( str_replace( '\\', '/', $file ), 'mu-plugins/bizcity-facebook-bot' );
		$registered_with_bridge = false;
		if ( class_exists( 'BizCity_Gateway_Bridge' ) ) {
			$bridge = BizCity_Gateway_Bridge::instance();
			$adapter = method_exists( $bridge, 'get_adapter' ) ? $bridge->get_adapter( 'FACEBOOK' ) : null;
			$registered_with_bridge = $adapter && is_object( $adapter ) && get_class( $adapter ) === $mu_cls;
		}
		$ok = $loaded && $mu_ok && $registered_with_bridge;
		$this->task_row(
			'T-S1.4',
			$ok ? 'PASS' : 'FAIL',
			'BizCity_Facebook_Bot_Channel_Adapter loaded from mu-plugin AND registered with gateway bridge as "FACEBOOK"',
			sprintf( 'class loaded: %s | mu-plugin file: %s (<code>%s</code>) | bridge registered: %s',
				$this->yn( $loaded ),
				$this->yn( $mu_ok ),
				esc_html( $this->short_path( $file ) ),
				$this->yn( $registered_with_bridge )
			)
		);
	}

	private function check_t_s1_5() {
		// Legacy /bizfbhook/ MUST be neutralized when mu-plugin owns webhook
		$legacy_cls = 'BizCity_FB_Webhook';
		$mu_present = class_exists( 'BizCity_Facebook_Bot_Plugin', false ) || class_exists( 'BizCity_Facebook_Bot_Channel_Adapter', false );

		// Inspect rewrite rules — /bizfbhook/ must NOT be present when mu owns
		$rules = (array) get_option( 'rewrite_rules', array() );
		$has_rule = false;
		foreach ( array_keys( $rules ) as $pattern ) {
			if ( false !== strpos( $pattern, 'bizfbhook' ) ) {
				$has_rule = true;
				break;
			}
		}

		// Hook count for legacy handlers
		global $wp_filter;
		$tr_hooks = isset( $wp_filter['template_redirect'] ) ? $wp_filter['template_redirect'] : null;
		$legacy_hook_present = false;
		if ( $tr_hooks && property_exists( $tr_hooks, 'callbacks' ) ) {
			foreach ( $tr_hooks->callbacks as $prio => $cbs ) {
				foreach ( $cbs as $cb ) {
					if ( is_array( $cb['function'] ?? null )
						&& is_object( $cb['function'][0] )
						&& get_class( $cb['function'][0] ) === $legacy_cls
					) {
						$legacy_hook_present = true;
						break 2;
					}
				}
			}
		}

		$legacy_class_loaded = class_exists( $legacy_cls, false );

		// Logic: when mu-plugin present, the legacy handler must be disabled
		// (no template_redirect callback) and ideally /bizfbhook/ rule absent.
		if ( $mu_present ) {
			$ok = ! $legacy_hook_present;
			$msg = sprintf( 'mu-plugin owns webhook | legacy class loaded: %s | legacy template_redirect hook attached: %s | /bizfbhook/ in rewrite rules: %s',
				$this->yn( $legacy_class_loaded ),
				$this->yn( $legacy_hook_present ),
				$this->yn( $has_rule )
			);
			$status = $ok ? 'PASS' : 'FAIL';
		} else {
			$ok = false;
			$status = 'FAIL';
			$msg = 'mu-plugin bizcity-facebook-bot NOT loaded — cannot validate dual-handler fix';
		}

		$this->task_row( 'T-S1.5', $status, 'Legacy /bizfbhook/ webhook fully neutralized when mu-plugin handler is active (BUG-3)', $msg );

		// Also check legacy adapter is guarded (BUG-4 part 2)
		$legacy_adapter = 'BizCity_Facebook_Channel_Adapter';
		$legacy_loaded  = class_exists( $legacy_adapter, false );
		$ok2 = $mu_present ? ! $legacy_loaded : true;
		$this->task_row(
			'T-S1.5b',
			$ok2 ? 'PASS' : 'FAIL',
			'Legacy BizCity_Facebook_Channel_Adapter NOT redeclared when mu-plugin adapter present',
			sprintf( 'legacy adapter class loaded: %s', $this->yn( $legacy_loaded ) )
		);
	}

	private function check_t_s1_6() {
		// nb_query_kg action + KG public REST API
		$block_file = $this->locate_block( 'nb_query_kg' );
		$rest_cls   = 'BizCity_KG_Public_API';
		$rest_route = '/bizcity/v1/kg/query';
		$diag_route = '/bizcity/v1/kg/query/diag';
		$routes     = rest_get_server() ? rest_get_server()->get_routes() : array();
		$route_present = isset( $routes[ $rest_route ] );
		$diag_present  = isset( $routes[ $diag_route ] );

		$block_class_ok = false;
		if ( $block_file ) {
			// Source-only check (block files are NOT autoloaded outside admin automation).
			$src = (string) file_get_contents( $block_file );
			$block_class_ok = (bool) preg_match( '/class\s+WaicAction_nb_query_kg\s+extends\s+WaicAction/', $src )
				&& false !== strpos( $src, 'BizCity_KG_Retriever' );
		}

		$ok = $block_file && $block_class_ok && class_exists( $rest_cls ) && $route_present && $diag_present;
		$this->task_row(
			'T-S1.6',
			$ok ? 'PASS' : 'FAIL',
			'Action block nb_query_kg + REST POST /wp-json/bizcity/v1/kg/query (+ /diag)',
			sprintf( 'block file: %s | block class declared in source: %s | REST class: %s | route /kg/query: %s | route /kg/query/diag: %s',
				$block_file ? '<code>' . esc_html( $this->short_path( $block_file ) ) . '</code>' : 'MISSING',
				$this->yn( $block_class_ok ),
				$this->yn( class_exists( $rest_cls ) ),
				$this->yn( $route_present ),
				$this->yn( $diag_present )
			)
		);
	}

	private function check_t_s1_7() {
		// wp_send_facebook_bot_text refactored to read account via WaicIntegration
		$file = $this->locate_block( 'wp_send_facebook_bot_text' );
		if ( ! $file || ! is_readable( $file ) ) {
			$this->task_row( 'T-S1.7', 'FAIL', 'Action wp_send_facebook_bot_text exists', 'block file not found' );
			return;
		}
		$src = (string) file_get_contents( $file );
		$uses_integration = (bool) preg_match( '/getIntegration\s*\(\s*[\'"]facebook[\'"]/', $src )
			|| (bool) preg_match( '/WaicChannelIntegration_facebook/', $src )
			|| (bool) preg_match( '/getIntegClass\s*\(\s*[\'"]facebook[\'"]/', $src );
		$this->task_row(
			'T-S1.7',
			$uses_integration ? 'PASS' : 'FAIL',
			'wp_send_facebook_bot_text reads account via WaicIntegration (with DB fallback OK)',
			sprintf( 'file: <code>%s</code> | integration lookup detected: %s',
				esc_html( $this->short_path( $file ) ),
				$this->yn( $uses_integration )
			)
		);
	}

	private function check_t_s1_8() {
		// PHASE 0.31 T-S1.8 — Demo workflow JSON deep validation.
		// Goal: catch broken variable refs / missing classes / chain gaps WITHOUT
		// firing real webhook. Replaces the old "file exists" heuristic which let
		// a JSON with dangling {{node#N.key}} references silently pass.
		$candidates = array();
		$base = WP_PLUGIN_DIR . '/bizcity-twin-ai/plugins/_archived/bizcity-automation/';
		foreach ( array( 'samples/workflows/', 'workflows/', 'modules/workflow/demo/', 'demo/' ) as $rel ) {
			$dir = $base . $rel;
			if ( is_dir( $dir ) ) {
				foreach ( glob( $dir . '*.json' ) ?: array() as $f ) {
					$src = (string) file_get_contents( $f );
					if ( false !== stripos( $src, 'nb_query_kg' )
						&& false !== stripos( $src, 'wp_send_facebook_bot_text' )
					) {
						$candidates[] = $f;
					}
				}
			}
		}

		if ( empty( $candidates ) ) {
			$this->task_row( 'T-S1.8', 'SKIP', 'Demo workflow JSON wiring wu_facebook_message_received → nb_query_kg → wp_send_facebook_bot_text', 'no demo workflow JSON found' );
			return;
		}

		$file   = $candidates[0];
		$report = $this->validate_workflow_json( $file );
		$status = $report['ok'] ? 'PASS' : 'FAIL';

		$lines   = array();
		$lines[] = '<code>' . esc_html( $this->short_path( $file ) ) . '</code>';
		foreach ( $report['steps'] as $step ) {
			$icon = $step['ok'] ? '✅' : '❌';
			$lines[] = $icon . ' ' . esc_html( $step['label'] )
				. ( ! empty( $step['detail'] ) ? ' — <code>' . esc_html( $step['detail'] ) . '</code>' : '' );
		}

		$this->task_row(
			'T-S1.8',
			$status,
			'Demo workflow JSON: chain + class load + variable references all green',
			implode( '<br>', $lines )
		);
	}

	/**
	 * Deep static validator for a workflow JSON definition.
	 *
	 * Steps:
	 *  1. JSON parses + schema (`nodes[]`, `edges[]`).
	 *  2. Trigger present, last node = wp_send_facebook_bot_text (or contract-defined).
	 *  3. For every node: block class loadable AND its `code` matches.
	 *  4. Edges form a DAG path from trigger → final action with no orphans.
	 *  5. Every `{{node#N.key}}` ref resolves to a variable declared by node N
	 *     (via `getVariables()` + `getRunValues()` keys harvested from source).
	 *  6. Every node-param key matching a setting name OR begins with literal
	 *     value (no template) — params not declared in `getSettings()` flagged.
	 *
	 * @return array{ok:bool, steps:array<int,array{label:string,ok:bool,detail:string}>}
	 */
	private function validate_workflow_json( string $file ): array {
		$steps = array();
		$add   = function ( $label, $ok, $detail = '' ) use ( &$steps ) {
			$steps[] = array( 'label' => $label, 'ok' => (bool) $ok, 'detail' => $detail );
		};

		$raw = file_get_contents( $file );
		$doc = json_decode( $raw, true );
		if ( ! is_array( $doc ) || ! isset( $doc['nodes'], $doc['edges'] ) ) {
			$add( 'JSON schema (nodes[] + edges[])', false, 'parse failed or keys missing' );
			return array( 'ok' => false, 'steps' => $steps );
		}
		$add( 'JSON schema (nodes[] + edges[])', true, count( $doc['nodes'] ) . ' nodes / ' . count( $doc['edges'] ) . ' edges' );

		// Index nodes by id; collect (id => code, type, params).
		$by_id = array();
		foreach ( $doc['nodes'] as $n ) {
			$id   = isset( $n['id'] ) ? (string) $n['id'] : '';
			$data = isset( $n['data'] ) && is_array( $n['data'] ) ? $n['data'] : array();
			$by_id[ $id ] = array(
				'type'   => isset( $data['type'] )   ? (string) $data['type']   : ( isset( $n['type'] ) ? (string) $n['type'] : '' ),
				'code'   => isset( $data['code'] )   ? (string) $data['code']   : '',
				'params' => isset( $data['params'] ) && is_array( $data['params'] ) ? $data['params'] : array(),
			);
		}

		// Step: chain matches contract (or default FB chain).
		$expected = isset( $doc['_validation_contract']['expected_chain'] ) && is_array( $doc['_validation_contract']['expected_chain'] )
			? array_values( $doc['_validation_contract']['expected_chain'] )
			: array( 'wu_facebook_message_received', 'nb_query_kg', 'ai_generate_text', 'wp_send_facebook_bot_text' );

		$actual_chain = array_values( array_map( static fn( $n ) => $n['code'], $by_id ) );
		$chain_ok     = ( $actual_chain === $expected );
		$add( 'Node codes match expected chain', $chain_ok, $chain_ok ? implode( ' → ', $actual_chain ) : 'expected ' . implode( '→', $expected ) . ' | actual ' . implode( '→', $actual_chain ) );

		// Step: edges link trigger → ... → last action with no breaks.
		$adj = array();
		foreach ( $doc['edges'] as $e ) {
			$adj[ (string) ( $e['source'] ?? '' ) ][] = (string) ( $e['target'] ?? '' );
		}
		$trigger_ids = array_keys( array_filter( $by_id, static fn( $n ) => $n['type'] === 'trigger' ) );
		$path_ok     = false;
		$reachable   = array();
		if ( ! empty( $trigger_ids ) ) {
			$start = reset( $trigger_ids );
			$queue = array( $start );
			$seen  = array( $start => 1 );
			while ( $queue ) {
				$cur = array_shift( $queue );
				$reachable[] = $cur;
				foreach ( ( $adj[ $cur ] ?? array() ) as $nxt ) {
					if ( isset( $seen[ $nxt ] ) ) { continue; }
					$seen[ $nxt ] = 1;
					$queue[]      = $nxt;
				}
			}
			$path_ok = count( $reachable ) === count( $by_id );
		}
		$add( 'Edges form connected DAG from trigger', $path_ok, sprintf( 'reachable %d / %d nodes', count( $reachable ), count( $by_id ) ) );

		// Step: every block file exists on disk AND declares the expected class name.
		// We intentionally do NOT @include_once block files: they extend abstract classes
		// (WaicTrigger / WaicAction) that only load inside automation runtime, causing a
		// fatal "Class not found" that @include + try/catch cannot suppress.
		// Source-scrape for `class WaicAction_X extends` is an honest substitute that
		// actually verifies the class name, not just file existence.
		$parents_loaded = class_exists( 'WaicTrigger', false ) && class_exists( 'WaicAction', false );
		$class_eval     = array();
		$all_classes_ok = true;
		foreach ( $by_id as $id => $node ) {
			$code      = $node['code'];
			$prefix    = $node['type'] === 'trigger' ? 'WaicTrigger_' : 'WaicAction_';
			$cls       = $prefix . $code;
			$file_path = $this->locate_block( $code );

			// If parents ARE loaded (e.g. automation admin page context) we can also
			// verify via class_exists after safe include.
			$runtime_ok = false;
			if ( $file_path && $parents_loaded && ! class_exists( $cls, false ) && is_readable( $file_path ) ) {
				try { @include_once $file_path; } catch ( \Throwable $e ) { /* ignore */ }
			}
			if ( $parents_loaded ) {
				$runtime_ok = class_exists( $cls, false );
			}

			// Primary verification (always runs): check source declares the class.
			$src_decl_ok = false;
			if ( $file_path && is_readable( $file_path ) ) {
				$src_snippet = (string) file_get_contents( $file_path );
				$src_decl_ok = (bool) preg_match( '/\bclass\s+' . preg_quote( $cls, '/' ) . '\b/', $src_snippet );
			}

			$ok_node = $file_path && $src_decl_ok;
			$class_eval[ $id ] = array(
				'cls'          => $cls,
				'file'         => $file_path ?: '',
				'ok'           => $ok_node,
				'src_decl_ok'  => $src_decl_ok,
				'runtime_ok'   => $runtime_ok,
			);
			if ( ! $ok_node ) { $all_classes_ok = false; }
		}
		$class_lines = array();
		foreach ( $class_eval as $id => $r ) {
			if ( $r['ok'] ) {
				$note = $r['runtime_ok'] ? 'class loaded' : 'declared in source';
				$class_lines[] = "node#{$id} {$r['cls']}: {$note}";
			} else {
				$why = ! $r['file'] ? 'file not found' : ( ! $r['src_decl_ok'] ? 'class NOT declared in source' : 'unknown' );
				$class_lines[] = "node#{$id} {$r['cls']}: FAIL ({$why})";
			}
		}
		$add( 'Block class declarations present', $all_classes_ok, implode( ' | ', $class_lines ) );

		// Step: variable references {{node#N.key}} all resolve.
		$declared_vars = array();
		foreach ( $by_id as $id => $node ) {
			$declared_vars[ $id ] = $this->harvest_node_variables( $node, $class_eval[ $id ] );
		}

		$bad_refs    = array();
		$ref_pattern = '/\{\{\s*node#(\w+)\.([\w\.]+)\s*\}\}/u';
		foreach ( $by_id as $id => $node ) {
			$flat = $this->flatten_strings( $node['params'] );
			foreach ( $flat as $param_key => $value ) {
				if ( preg_match_all( $ref_pattern, $value, $m, PREG_SET_ORDER ) ) {
					foreach ( $m as $hit ) {
						$src_id = $hit[1];
						$var    = $hit[2];
						$top    = explode( '.', $var )[0];
						if ( ! isset( $by_id[ $src_id ] ) ) {
							$bad_refs[] = "node#{$id}.{$param_key} → unknown node#{$src_id}";
							continue;
						}
						$ok_keys = $declared_vars[ $src_id ] ?? array();
						if ( ! in_array( $top, $ok_keys, true ) ) {
							$bad_refs[] = "node#{$id}.{$param_key} → node#{$src_id}.{$var} (declared: " . implode( ',', $ok_keys ) . ')';
						}
					}
				}
			}
		}
		$add( 'Variable refs {{node#N.key}} resolve', empty( $bad_refs ), empty( $bad_refs ) ? 'all green' : implode( ' || ', $bad_refs ) );

		$ok = true;
		foreach ( $steps as $s ) { if ( ! $s['ok'] ) { $ok = false; break; } }
		return array( 'ok' => $ok, 'steps' => $steps );
	}

	/**
	 * Collect declared output variable keys for a workflow node.
	 *
	 * Variable output contract (in order of authority):
	 *   A. Runtime instantiation — only when abstract parents are loaded (automation context).
	 *   B. setVariables() body scrape — PRIMARY for all nodes. This IS the declared output
	 *      contract: what the editor shows + what downstream `{{node#N.key}}` refs must use.
	 *   C. getRunValues() body scrape — TRIGGERS only. Triggers may expose runtime-only
	 *      keys (e.g. twf_chat_id) that are not listed in setVariables.
	 *
	 * DELIBERATELY excluded: `getResults()['result']` regex for actions.
	 * Reason: actions like ai_generate_text return `'result' => $error ? array() : array('content'...)`
	 * — a ternary that breaks `'result'\s*=>\s*array\s*\(` matching. setVariables is the
	 * ground truth; relying on getResults scrape was an accidental-pass path.
	 *
	 * @param array $node       {type, code, params}
	 * @param array $class_eval {cls, ok, runtime_ok, file}
	 * @return string[]
	 */
	private function harvest_node_variables( array $node, array $class_eval ): array {
		$keys = array();
		$cls  = $class_eval['cls'];

		// Path A: instantiate if runtime parents loaded.
		if ( ! empty( $class_eval['runtime_ok'] ) && class_exists( $cls, false ) ) {
			try {
				$inst = new $cls();
				if ( method_exists( $inst, 'getVariables' ) ) {
					$keys = array_keys( (array) $inst->getVariables() );
				}
			} catch ( \Throwable $e ) {
				$keys = array();
			}
		}

		$file = (string) ( $class_eval['file'] ?? '' );
		if ( $file && is_readable( $file ) ) {
			$src = (string) file_get_contents( $file );

			// Path B: scrape setVariables() $this->_variables = array(...) body.
			// This is the declared contract — run even when Path A succeeded.
			if ( preg_match( '/function\s+setVariables\b[\s\S]*?\$this->_variables\s*=\s*array\s*\(([\s\S]*?)\)\s*;/u', $src, $m ) ) {
				if ( preg_match_all( "/'([a-z_][a-z0-9_]*)'\s*=>/i", $m[1], $mm ) ) {
					$keys = array_unique( array_merge( $keys, $mm[1] ) );
				}
			}

			// Path C: scrape getRunValues() return array — TRIGGERS only.
			if ( $node['type'] === 'trigger'
				&& preg_match( '/function\s+getRunValues\b[\s\S]*?return\s+array\s*\(([\s\S]*?)\)\s*;/u', $src, $m )
			) {
				if ( preg_match_all( "/'([a-z_][a-z0-9_]*)'\s*=>/i", $m[1], $mm ) ) {
					$keys = array_unique( array_merge( $keys, $mm[1] ) );
				}
			}
		}

		return array_values( array_unique( array_filter( $keys, static fn( $k ) => is_string( $k ) && strlen( $k ) <= 40 ) ) );
	}

	/**
	 * Flatten a nested params array → ['dot.path' => string_value, ...].
	 */
	private function flatten_strings( array $arr, string $prefix = '' ): array {
		$out = array();
		foreach ( $arr as $k => $v ) {
			$key = $prefix === '' ? (string) $k : $prefix . '.' . $k;
			if ( is_array( $v ) ) {
				$out += $this->flatten_strings( $v, $key );
			} elseif ( is_string( $v ) || is_numeric( $v ) ) {
				$out[ $key ] = (string) $v;
			}
		}
		return $out;
	}

	/* =========================================================
	 * SPRINT 2 — TASK CHECKS (placeholders)
	 * =======================================================*/

	private function check_t_s2_1() {
		$registered = apply_filters( 'bizcity_register_channel_integrations', array() );
		$key = null;
		foreach ( array( 'zalo_bot', 'zalobot', 'zalo' ) as $k ) {
			if ( isset( $registered[ $k ] ) ) { $key = $k; break; }
		}
		$adapter_ok = class_exists( 'BizCity_Zalo_Bot_Channel_Adapter' );
		$ok = ( $key !== null ) && $adapter_ok;
		$this->task_row(
			'T-S2.1',
			$ok ? 'PASS' : 'SKIP',
			'WaicChannelIntegration_zalobot + adapter registered',
			$key !== null
				? '<code>key=' . esc_html( $key ) . ' → ' . esc_html( wp_json_encode( $registered[ $key ] ) ) . '</code> | adapter class: ' . $this->yn( $adapter_ok )
				: 'not registered yet (adapter class: ' . $this->yn( $adapter_ok ) . ')'
		);
	}

	private function check_t_s2_2() {
		// PHASE 0.31 audit 2026-05-07 — class_exists alone hides stub adapters that
		// always return false. We now also: (a) call doTest() to surface stub status,
		// (b) source-scrape send_outbound() for `return false` literal as the canonical
		// stub marker. Stub status downgrades PASS → WARN.
		$cls    = 'BizCity_Zalo_Hotline_Channel_Adapter';
		$loaded = class_exists( $cls );
		if ( ! $loaded ) {
			$this->task_row( 'T-S2.2', 'SKIP', 'BizCity_Zalo_Hotline_Channel_Adapter loaded', 'class not loaded' );
			return;
		}

		$evidence    = array( 'class loaded: YES' );
		$is_stub     = false;
		$stub_reason = '';

		// (a) doTest probe.
		if ( method_exists( $cls, 'doTest' ) ) {
			try {
				$probe = new $cls();
				$test_result = $probe->doTest( false );
				$status = method_exists( $probe, 'getParam' ) ? (int) $probe->getParam( '_status' ) : -1;
				$status_err = method_exists( $probe, 'getParam' ) ? (string) $probe->getParam( '_status_error' ) : '';
				$evidence[] = sprintf( 'doTest()=%s, _status=%d', $test_result ? 'true' : 'false', $status );
				if ( $status_err !== '' ) { $evidence[] = '_status_error: <code>' . esc_html( $status_err ) . '</code>'; }
				if ( $status === 4 || false !== stripos( $status_err, 'stub' ) ) {
					$is_stub     = true;
					$stub_reason = $status_err ?: 'doTest reports status=4 (warning)';
				}
			} catch ( \Throwable $e ) {
				$evidence[] = 'doTest() threw: <code>' . esc_html( $e->getMessage() ) . '</code>';
			}
		}

		// (b) source scrape for `return false;` literal in send_outbound().
		try {
			$ref      = new ReflectionClass( $cls );
			$src_file = $ref->getFileName();
			if ( $src_file && is_readable( $src_file ) ) {
				$src = (string) file_get_contents( $src_file );
				if ( preg_match( '/function\s+send_outbound[\s\S]*?\{([\s\S]*?)\n\s*\}/u', $src, $m ) ) {
					$body = $m[1];
					if ( preg_match( '/\breturn\s+false\s*;/', $body ) && ! preg_match( '/wp_remote_(post|get|request)|curl_exec/', $body ) ) {
						$is_stub     = true;
						$stub_reason = $stub_reason ?: 'send_outbound() body returns false with no HTTP call';
						$evidence[]  = 'send_outbound() body: <code>return false</code>, no HTTP call — STUB';
					} else {
						$evidence[] = 'send_outbound() body contains HTTP call: likely live';
					}
				}
			}
		} catch ( \Throwable $e ) {
			$evidence[] = 'reflection failed: <code>' . esc_html( $e->getMessage() ) . '</code>';
		}

		$status = $is_stub ? 'WARN' : 'PASS';
		if ( $is_stub ) {
			$evidence[] = '<strong style="color:#b26e00">⚠️ STUB DETECTED</strong> — ' . esc_html( $stub_reason ) . ' (Sprint 4 follow-up)';
		}

		$this->task_row(
			'T-S2.2',
			$status,
			'BizCity_Zalo_Hotline_Channel_Adapter loaded + send_outbound() not stub',
			implode( '<br>', $evidence )
		);
	}

	private function check_t_s2_3() {
		// PHASE 0.31 audit 2026-05-07 — the original check returned PASS the moment the
		// block .php file existed, hiding the fact that the action body called the
		// missing class `BizCity_Tool_Facebook::post_facebook()` (legacy plugin removed).
		// Now we also source-scrape the action for any class/method symbol it depends on
		// and verify each is loadable in this request — catches dead-call regressions.
		$f = $this->locate_block( 'wp_create_facebook_page_post' );
		if ( ! $f ) {
			$this->task_row( 'T-S2.3', 'SKIP', 'Action wp_create_facebook_page_post exists', 'block file missing' );
			return;
		}

		$src = (string) file_get_contents( $f );
		$evidence = array( '<code>' . esc_html( $this->short_path( $f ) ) . '</code>' );

		// Dependencies we expect to be loadable: discover via `ClassName::method(` syntax
		// inside getResults() body. Filter out PHP built-ins and `self`/`static`/`parent`.
		$missing = array();
		$present = array();
		if ( preg_match( '/function\s+getResults\b[\s\S]*?\n\s*\}\s*\}/u', $src, $m ) ) {
			$body = $m[0];
			// Strip comments so legacy class names mentioned in docblocks/inline notes
			// don't get scraped as live dependencies (audit 2026-05-07).
			$body = preg_replace( '!/\*.*?\*/!s', '', $body );
			$body = preg_replace( '~//[^\n]*~', '', $body );
			$body = preg_replace( '/^\s*#[^\n]*/m', '', $body );
			if ( preg_match_all( '/\b([A-Z][A-Za-z0-9_]+)::([a-z_][A-Za-z0-9_]*)\s*\(/', $body, $mm, PREG_SET_ORDER ) ) {
				$seen = array();
				foreach ( $mm as $hit ) {
					$cls = $hit[1]; $method = $hit[2];
					$key = $cls . '::' . $method;
					if ( isset( $seen[ $key ] ) ) { continue; }
					$seen[ $key ] = 1;
					if ( in_array( $cls, array( 'WP_Error' ), true ) ) { continue; }
					if ( class_exists( $cls ) && method_exists( $cls, $method ) ) {
						$present[] = $key;
					} else {
						$missing[] = $key;
					}
				}
			}
			// Also `new ClassName(`
			if ( preg_match_all( '/\bnew\s+([A-Z][A-Za-z0-9_]+)\s*\(/', $body, $mm ) ) {
				foreach ( array_unique( $mm[1] ) as $cls ) {
					$key = 'new ' . $cls;
					if ( class_exists( $cls ) ) { $present[] = $key; } else { $missing[] = $key; }
				}
			}
		}

		$evidence[] = 'present: <code>' . esc_html( implode( ', ', $present ) ?: '(none)' ) . '</code>';
		if ( $missing ) {
			$evidence[] = '<strong style="color:#b32d2e">missing:</strong> <code>' . esc_html( implode( ', ', $missing ) ) . '</code>';
		}

		// Final: PASS only if every referenced symbol is loadable.
		$status = $missing ? 'FAIL' : 'PASS';
		$this->task_row(
			'T-S2.3',
			$status,
			'Action wp_create_facebook_page_post exists + all runtime dependencies loadable',
			implode( '<br>', $evidence )
		);
	}

	private function check_t_s2_4() {
		$registered = apply_filters( 'bizcity_register_channel_integrations', array() );
		$has_integ  = isset( $registered['notebook'] );
		$create     = $this->locate_block( 'nb_create_note' );
		$attach     = $this->locate_block( 'nb_attach_artifact' );
		$ok = $has_integ && $create && $attach;
		$this->task_row(
			'T-S2.4',
			$ok ? 'PASS' : 'SKIP',
			'WaicChannelIntegration_notebook + actions nb_create_note, nb_attach_artifact',
			sprintf( 'integ: %s | nb_create_note: %s | nb_attach_artifact: %s',
				$this->yn( $has_integ ),
				$create ? 'OK' : 'missing',
				$attach ? 'OK' : 'missing'
			)
		);
	}

	private function check_t_s2_5() {
		$f = $this->locate_block( 'sy_create_schedule' );
		$this->task_row(
			'T-S2.5',
			$f ? 'PASS' : 'SKIP',
			'Action sy_create_schedule exists',
			$f ? '<code>' . esc_html( $this->short_path( $f ) ) . '</code>' : 'not implemented'
		);
	}

	private function check_t_s2_6() {
		$f = $this->locate_block( 'wu_webchat_message_received' );
		$this->task_row(
			'T-S2.6',
			$f ? 'PASS' : 'SKIP',
			'Trigger wu_webchat_message_received exists',
			$f ? '<code>' . esc_html( $this->short_path( $f ) ) . '</code>' : 'not implemented'
		);
	}

	private function check_t_s2_7() {
		$zb = $this->locate_block( 'wp_send_zalo_bot_text' );
		$zh = $this->locate_block( 'wp_send_zalo' );
		$ok = false;
		$evidence = array();
		foreach ( array( 'wp_send_zalo_bot_text' => $zb, 'wp_send_zalo' => $zh ) as $name => $f ) {
			if ( ! $f ) { $evidence[] = "$name: missing"; continue; }
			$src = (string) file_get_contents( $f );
			$detected = (bool) preg_match( '/getInteg(ration|Class)\s*\(\s*[\'"]zalo/', $src );
			$evidence[] = "$name: " . ( $detected ? 'integration lookup OK' : 'still DB-direct' );
			$ok = $ok || $detected;
		}
		$this->task_row( 'T-S2.7', $ok ? 'PASS' : 'SKIP', 'Zalo actions read account via Integration', implode( '<br>', $evidence ) );
	}

	/* =========================================================
	 * SPRINT 3 — TASK CHECKS
	 * =======================================================*/

	/**
	 * T-S3.1 — Notebook event triggers wired end-to-end:
	 *   (a) 3 trigger block files exist + class declared.
	 *   (b) `BizCity_KG_Source_Service::add_passage()` fires `bizcity_twin_notebook_event`.
	 *   (c) Bridge `bizcity_twin_notebook_event` → `waic_twf_process_flow` registered.
	 */
	private function check_t_s3_1() {
		$evidence = array();
		$problems = array();

		// (a) trigger block files + class declarations
		$expected = array(
			'nb_note_created' => 'WaicTrigger_nb_note_created',
			'nb_note_updated' => 'WaicTrigger_nb_note_updated',
			'nb_note_tagged'  => 'WaicTrigger_nb_note_tagged',
		);
		foreach ( $expected as $code => $cls ) {
			$f = $this->locate_block( $code );
			if ( ! $f ) { $problems[] = "trigger file missing: $code"; continue; }
			$src = (string) file_get_contents( $f );
			if ( ! preg_match( '/\bclass\s+' . preg_quote( $cls, '/' ) . '\b/', $src ) ) {
				$problems[] = "class $cls not declared in $code.php";
				continue;
			}
			$evidence[] = "$code: <code>" . esc_html( $this->short_path( $f ) ) . '</code>';
		}

		// (b) source-scrape add_passage() for the do_action call
		$svc_file = WP_PLUGIN_DIR . '/bizcity-twin-ai/core/knowledge/kg-hub/includes/class-kg-source-service.php';
		if ( is_readable( $svc_file ) ) {
			$svc_src = (string) file_get_contents( $svc_file );
			if ( preg_match( "/do_action\\(\\s*['\"]bizcity_twin_notebook_event['\"]\\s*,\\s*['\"]note_created['\"]/", $svc_src ) ) {
				$evidence[] = 'add_passage() fires <code>bizcity_twin_notebook_event(\'note_created\', ...)</code>';
			} else {
				$problems[] = 'add_passage() does NOT fire bizcity_twin_notebook_event(\'note_created\', ...)';
			}
		} else {
			$problems[] = 'class-kg-source-service.php not readable';
		}

		// (c) bridge registered (hook callback present)
		global $wp_filter;
		$bridge_ok = isset( $wp_filter['bizcity_twin_notebook_event'] );
		if ( $bridge_ok ) {
			$evidence[] = 'bridge hook <code>bizcity_twin_notebook_event</code> has ' . count( $wp_filter['bizcity_twin_notebook_event']->callbacks ) . ' priority bucket(s)';
		} else {
			$problems[] = 'bridge add_action(\'bizcity_twin_notebook_event\', ...) NOT registered';
		}

		// Forward-compat caveat — note_updated/note_tagged not yet fired anywhere
		$updated_fired = ( isset( $svc_src ) && preg_match( "/do_action\\(\\s*['\"]bizcity_twin_notebook_event['\"]\\s*,\\s*['\"]note_updated['\"]/", $svc_src ) );
		$tagged_fired  = ( isset( $svc_src ) && preg_match( "/do_action\\(\\s*['\"]bizcity_twin_notebook_event['\"]\\s*,\\s*['\"]note_tagged['\"]/", $svc_src ) );
		if ( ! $updated_fired ) { $evidence[] = '<span style="color:#b26e00">⚠ note_updated trigger declared but no fire point yet (Sprint 4)</span>'; }
		if ( ! $tagged_fired )  { $evidence[] = '<span style="color:#b26e00">⚠ note_tagged trigger declared but no fire point yet (Sprint 4)</span>'; }

		if ( $problems ) {
			array_unshift( $evidence, '<strong style="color:#b32d2e">FAIL:</strong> ' . esc_html( implode( '; ', $problems ) ) );
			$status = 'FAIL';
		} else {
			$status = ( $updated_fired && $tagged_fired ) ? 'PASS' : 'WARN';
		}
		$this->task_row(
			'T-S3.1',
			$status,
			'Triggers nb_note_created/updated/tagged + bridge wired',
			implode( '<br>', $evidence )
		);
	}

	/**
	 * T-S3.2 — TwinChat UI buttons (Tag note + Trigger workflow per note).
	 * Deferred to follow-up sprint; this check surfaces the gap honestly.
	 */
	private function check_t_s3_2() {
		// Look for any TwinChat UI asset that mentions the two button labels.
		$base    = WP_PLUGIN_DIR . '/bizcity-twin-ai';
		$found   = array();
		$globs   = array(
			$base . '/core/knowledge/views/*.php',
			$base . '/core/knowledge/assets/*.js',
			$base . '/plugins/bizcity-twinchat/**/*.php',
			$base . '/plugins/bizcity-twinchat/**/*.js',
		);
		foreach ( $globs as $g ) {
			foreach ( (array) glob( $g, GLOB_NOSORT ) as $f ) {
				if ( ! is_readable( $f ) ) { continue; }
				$src = (string) file_get_contents( $f );
				if ( preg_match( '/(Tag note|Trigger workflow|trigger_workflow|tag_note_btn)/i', $src ) ) {
					$found[] = $this->short_path( $f );
					if ( count( $found ) >= 5 ) { break 2; }
				}
			}
		}
		if ( $found ) {
			$this->task_row( 'T-S3.2', 'PASS', 'TwinChat note buttons (Tag note + Trigger workflow)',
				implode( '<br>', array_map( function ( $p ) { return '<code>' . esc_html( $p ) . '</code>'; }, $found ) ) );
		} else {
			$this->task_row( 'T-S3.2', 'SKIP', 'TwinChat note buttons (Tag note + Trigger workflow)',
				'<span style="color:#b26e00">⚠ Not implemented — UI buttons deferred (P1).</span>' );
		}
	}

	/**
	 * T-S3.3 — `ai_intent_router_json` action + LOGIC `un_branch` if/else block
	 * both present and runtime-loadable.
	 */
	private function check_t_s3_3() {
		$evidence = array();
		$problems = array();

		// ai_intent_router_json action
		$f1 = $this->locate_block( 'ai_intent_router_json' );
		if ( ! $f1 ) {
			$problems[] = 'ai_intent_router_json missing';
		} else {
			$src1 = (string) file_get_contents( $f1 );
			if ( ! preg_match( '/\bclass\s+WaicAction_ai_intent_router_json\b/', $src1 ) ) {
				$problems[] = 'WaicAction_ai_intent_router_json class not declared';
			}
			// Confirm declared output keys include the routing keys
			$has_type       = (bool) preg_match( "/['\"]type['\"]\\s*=>/", $src1 );
			$has_confidence = (bool) preg_match( "/['\"]confidence['\"]\\s*=>/", $src1 );
			$evidence[] = 'ai_intent_router_json: <code>' . esc_html( $this->short_path( $f1 ) ) . '</code> | output exposes type=' . $this->yn( $has_type ) . ' confidence=' . $this->yn( $has_confidence );
			if ( ! $has_type ) { $problems[] = 'router missing output key: type'; }
		}

		// un_branch logic block (lives under blocks/logics/)
		$base       = WP_PLUGIN_DIR . '/bizcity-twin-ai/plugins/_archived/bizcity-automation/modules/workflow/blocks';
		$candidates = array(
			$base . '/logics/un_branch.php',
			$base . '/actions/un_branch.php',
			$base . '/triggers/un_branch.php',
		);
		$logic_file = null;
		foreach ( $candidates as $c ) { if ( is_readable( $c ) ) { $logic_file = $c; break; } }
		if ( ! $logic_file ) {
			$problems[] = 'un_branch (LOGIC if/else) block file missing';
		} else {
			$src2 = (string) file_get_contents( $logic_file );
			$has_op   = (bool) preg_match( "/['\"]operator['\"]\\s*=>/", $src2 );
			$has_then = (bool) preg_match( "/output-then|output-else|sourceHandle/", $src2 );
			$evidence[] = 'un_branch: <code>' . esc_html( $this->short_path( $logic_file ) ) . '</code> | operator=' . $this->yn( $has_op ) . ' branch-handles=' . $this->yn( $has_then );
			if ( ! $has_op )   { $problems[] = 'un_branch missing operator setting'; }
			if ( ! $has_then ) { $problems[] = 'un_branch missing then/else sourceHandle output'; }
		}

		if ( $problems ) {
			array_unshift( $evidence, '<strong style="color:#b32d2e">FAIL:</strong> ' . esc_html( implode( '; ', $problems ) ) );
			$status = 'FAIL';
		} else {
			$status = 'PASS';
		}
		$this->task_row(
			'T-S3.3',
			$status,
			'ai_intent_router_json + un_branch (LOGIC if/else) wired',
			implode( '<br>', $evidence )
		);
	}

	/* =========================================================
	 * SPRINT 4 — TASK CHECKS
	 * =======================================================*/

	/**
	 * T-S4.1 — Top-level menu `bizcity-channels` exists and groups channel
	 * bot admin pages (Zalo Bot / FB Bot / Zalo Hotline) as submenus.
	 */
	private function check_t_s4_1() {
		global $menu, $submenu;
		$evidence = array();
		$problems = array();

		$has_top = false;
		if ( is_array( $menu ) ) {
			foreach ( $menu as $m ) {
				if ( isset( $m[2] ) && $m[2] === 'bizcity-channels' ) { $has_top = true; break; }
			}
		}
		if ( $has_top ) {
			$evidence[] = 'top-level <code>bizcity-channels</code> registered';
		} else {
			$problems[] = 'top-level <code>bizcity-channels</code> NOT registered';
		}

		$expected_subs = array( 'bizcity-zalo-bot-dashboard', 'bizcity-facebook-bots', 'bizcity-zalo-hotline' );
		$present_subs  = array();
		if ( isset( $submenu['bizcity-channels'] ) && is_array( $submenu['bizcity-channels'] ) ) {
			foreach ( $submenu['bizcity-channels'] as $row ) {
				$present_subs[] = isset( $row[2] ) ? $row[2] : '';
			}
		}
		foreach ( $expected_subs as $slug ) {
			if ( in_array( $slug, $present_subs, true ) ) {
				$evidence[] = '<code>' . esc_html( $slug ) . '</code> mounted under bizcity-channels';
			} else {
				// Not necessarily fatal — provider plugin may be inactive.
				$evidence[] = '<span style="color:#b26e00">⚠ <code>' . esc_html( $slug ) . '</code> not mounted (provider plugin inactive?)</span>';
			}
		}

		if ( $problems ) {
			array_unshift( $evidence, '<strong style="color:#b32d2e">FAIL:</strong> ' . esc_html( implode( '; ', $problems ) ) );
			$status = 'FAIL';
		} else {
			// PASS only if at least 1 expected channel sub mounted; else WARN.
			$mounted = array_intersect( $expected_subs, $present_subs );
			$status  = $mounted ? 'PASS' : 'WARN';
		}
		$this->task_row( 'T-S4.1', $status, 'Top-level <code>bizcity-channels</code> + Zalo/FB/Hotline submenus', implode( '<br>', $evidence ) );
	}

	/**
	 * T-S4.2 — `bizchat-gateway` page reduced to read-only deep-link dashboard
	 * (no longer registers per-channel admin submenus; cards link to the
	 * canonical Workflow Builder Integrations popup).
	 */
	private function check_t_s4_2() {
		global $submenu;
		$evidence = array();
		$problems = array();

		$gateway_subs = isset( $submenu['bizchat-gateway'] ) ? array_map( function ( $r ) { return isset( $r[2] ) ? $r[2] : ''; }, (array) $submenu['bizchat-gateway'] ) : array();
		$evidence[]   = 'gateway submenus: ' . ( $gateway_subs ? '<code>' . esc_html( implode( ', ', $gateway_subs ) ) . '</code>' : '<em>none</em>' );

		// These channel pages must NOT live under bizchat-gateway anymore.
		$forbidden = array( 'bizcity-zalo-bot-dashboard', 'bizcity-zalo-bots', 'bizcity-facebook-bots' );
		$leaked    = array_intersect( $forbidden, $gateway_subs );
		if ( $leaked ) {
			$problems[] = 'channel pages still mounted under bizchat-gateway: ' . implode( ', ', $leaked );
		}

		// Source-scrape: gateway render must use deep-link cards pointing to bizcity-integrations page.
		$menu_file = WP_PLUGIN_DIR . '/bizcity-twin-ai/includes/class-admin-menu.php';
		if ( is_readable( $menu_file ) ) {
			$src = (string) file_get_contents( $menu_file );
			if ( preg_match( '/render_gateway_page[\s\S]{0,2000}bizcity-integrations/', $src ) ) {
				$evidence[] = 'render_gateway_page() emits deep-link cards → <code>?page=bizcity-integrations&amp;code=…</code>';
			} else {
				$problems[] = 'render_gateway_page() not refactored to deep-link dashboard';
			}
		} else {
			$problems[] = 'class-admin-menu.php not readable';
		}

		if ( $problems ) {
			array_unshift( $evidence, '<strong style="color:#b32d2e">FAIL:</strong> ' . esc_html( implode( '; ', $problems ) ) );
			$status = 'FAIL';
		} else {
			$status = 'PASS';
		}
		$this->task_row( 'T-S4.2', $status, 'bizchat-gateway → read-only deep-link dashboard', implode( '<br>', $evidence ) );
	}

	/**
	 * T-S4.3 — Network admin global OAuth config page (FB App ID/Secret,
	 * Google client ID/Secret) via WaicIntegration proxy.
	 *
	 * Deferred at first pass; surface SKIP until the network page lands.
	 */
	private function check_t_s4_3() {
		$evidence = array();

		$net_file_candidates = array(
			WP_PLUGIN_DIR . '/bizcity-twin-ai/core/channel-gateway/includes/class-network-oauth-page.php',
			WPMU_PLUGIN_DIR . '/bizcity-channel-network-oauth.php',
		);
		$present = null;
		foreach ( $net_file_candidates as $c ) {
			if ( is_readable( $c ) ) { $present = $c; break; }
		}
		if ( $present ) {
			$evidence[] = 'network OAuth page: <code>' . esc_html( $this->short_path( $present ) ) . '</code>';
			$status     = 'PASS';
		} else {
			$evidence[] = '<span style="color:#646970">⚠ Not implemented — network admin OAuth page deferred (T-S4.3).</span>';
			$status     = 'SKIP';
		}
		$this->task_row( 'T-S4.3', $status, 'Network OAuth global config via WaicIntegration proxy', implode( '<br>', $evidence ) );
	}

	/* =========================================================
	 * SPRINT 6 — CLEANUP / UNIFICATION TASK CHECKS
	 *  T-S6.1  Unified channel_messages inbox table
	 *  T-S6.2  wp_get_schedules audit (duplicate cron)
	 *  T-S6.3  WaicIntegration::getAuthProxyUrl() central proxy
	 * =======================================================*/

	private function check_t_s6_1() {
		global $wpdb;
		$evidence = array();
		if ( ! class_exists( 'BizCity_Channel_Messages' ) ) {
			$this->task_row( 'T-S6.1', 'FAIL', 'Unified inbox wp_bizcity_channel_messages', 'class <code>BizCity_Channel_Messages</code> not loaded' );
			return;
		}
		$table = BizCity_Channel_Messages::table();
		$exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		$evidence[] = 'table <code>' . esc_html( $table ) . '</code>: ' . ( $exists ? '✅ present' : '❌ missing' );
		$schema_version = (string) get_option( BizCity_Channel_Messages::OPTION_VERSION, '' );
		$evidence[] = 'schema version: <code>' . esc_html( $schema_version ?: '(unset)' ) . '</code> (expected ' . BizCity_Channel_Messages::SCHEMA_VERSION . ')';
		if ( $exists ) {
			$row_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
			$evidence[] = 'rows: <code>' . $row_count . '</code>';
			$status = ( $schema_version === BizCity_Channel_Messages::SCHEMA_VERSION ) ? 'PASS' : 'WARN';
		} else {
			$status = 'FAIL';
			$evidence[] = '<span style="color:#b32d2e">Reload an admin page once to trigger maybe_install().</span>';
		}
		$this->task_row( 'T-S6.1', $status, 'Unified channel_messages inbox + schema install', implode( '<br>', $evidence ) );
	}

	private function check_t_s6_2() {
		$cron = _get_cron_array();
		if ( ! is_array( $cron ) || ! $cron ) {
			$this->task_row( 'T-S6.2', 'SKIP', 'wp_get_schedules audit', 'no cron events scheduled' );
			return;
		}
		$by_hook = array();
		foreach ( $cron as $ts => $hooks ) {
			if ( ! is_array( $hooks ) ) { continue; }
			foreach ( $hooks as $hook => $events ) {
				if ( ! is_array( $events ) ) { continue; }
				foreach ( $events as $sig => $event ) {
					$schedule = isset( $event['schedule'] ) && $event['schedule'] ? (string) $event['schedule'] : 'one-off';
					if ( ! isset( $by_hook[ $hook ] ) ) { $by_hook[ $hook ] = array(); }
					if ( ! isset( $by_hook[ $hook ][ $schedule ] ) ) { $by_hook[ $hook ][ $schedule ] = 0; }
					$by_hook[ $hook ][ $schedule ]++;
				}
			}
		}
		$dupes = array();
		foreach ( $by_hook as $hook => $sched_counts ) {
			$total_jobs = array_sum( $sched_counts );
			$slug_count = count( $sched_counts );
			if ( $slug_count > 1 || $total_jobs > 5 ) {
				$dupes[] = sprintf( '<code>%s</code> → %d slug(s), %d job(s): %s',
					esc_html( $hook ), $slug_count, $total_jobs,
					esc_html( wp_json_encode( $sched_counts ) )
				);
			}
		}
		$evidence = array(
			'total hooks scheduled: <code>' . count( $by_hook ) . '</code>',
			'duplicate/excessive: <code>' . count( $dupes ) . '</code>',
		);
		if ( $dupes ) {
			$evidence[] = '<details open><summary>Suspicious hooks</summary>' . implode( '<br>', $dupes ) . '</details>';
			$status = 'WARN';
		} else {
			$status = 'PASS';
		}
		$this->task_row( 'T-S6.2', $status, 'Cron schedules audit (no duplicates)', implode( '<br>', $evidence ) );
	}

	private function check_t_s6_3() {
		$evidence = array();
		$has_helper = class_exists( 'BizCity_OAuth_Proxy' );
		$evidence[] = 'BizCity_OAuth_Proxy helper: ' . $this->yn( $has_helper );
		if ( $has_helper ) {
			$evidence[] = 'init URL: <code>' . esc_html( BizCity_OAuth_Proxy::get_init_url() ) . '</code>';
		}
		$has_sitewide = class_exists( 'WaicIntegration' )
			&& method_exists( 'WaicIntegration', 'getSitewideOAuthGlobal' )
			&& method_exists( 'WaicIntegration', 'saveSitewideOAuthGlobals' );
		$evidence[] = 'WaicIntegration::getSitewideOAuthGlobal()/saveSitewideOAuthGlobals(): ' . $this->yn( $has_sitewide );
		$status = ( $has_helper && $has_sitewide ) ? 'PASS' : 'WARN';
		$this->task_row( 'T-S6.3', $status, 'OAuth proxy unification (helper + WaicIntegration sitewide)', implode( '<br>', $evidence ) );
	}

	/* =========================================================
	 * SPRINT 5 — TASK CHECKS
	 *  T-S5.1  wu_facebook_message_received: ref_filter (m.me referral)
	 *  T-S5.2  loyalty_award_points action
	 *  T-S5.3  crm_capture_lead action
	 *  T-S5.4  wp_form_submitted trigger (CF7/Gravity/Elementor)
	 *  T-S5.5  bizgpt-custom-flows migration scaffold
	 * =======================================================*/

	private function check_t_s5_1() {
		$evidence = array();
		$file     = $this->locate_block( 'wu_facebook_message_received' );
		if ( ! $file ) {
			$this->task_row( 'T-S5.1', 'FAIL', 'Trigger file present', '<code>wu_facebook_message_received.php</code> not found' );
			return;
		}
		$src = (string) @file_get_contents( $file );
		$has_ref_setting  = (bool) preg_match( "/'ref_filter'\s*=>/", $src );
		$has_extract_fn   = (bool) preg_match( '/function\s+extract_referral_(ref|info)\s*\(/i', $src );
		$has_ref_var      = (bool) preg_match( "/'ref'\s*=>/", $src ) && (bool) preg_match( "/'ref_source'\s*=>/", $src );
		$has_wildcard     = (bool) preg_match( '/substr\(\s*\$refFilter\s*,\s*-\s*1\s*\)\s*===\s*[\'"]\*[\'"]/', $src );

		$evidence[] = 'file: <code>' . esc_html( $this->short_path( $file ) ) . '</code>';
		$evidence[] = 'ref_filter setting: ' . $this->yn( $has_ref_setting );
		$evidence[] = 'extract_referral_*(): ' . $this->yn( $has_extract_fn );
		$evidence[] = 'ref / ref_source variable: ' . $this->yn( $has_ref_var );
		$evidence[] = 'wildcard prefix_* support: ' . $this->yn( $has_wildcard );

		$status = ( $has_ref_setting && $has_extract_fn && $has_ref_var ) ? 'PASS' : 'FAIL';
		$this->task_row( 'T-S5.1', $status, 'wu_facebook_message_received: m.me referral filter', implode( '<br>', $evidence ) );
	}

	private function check_t_s5_2() {
		$evidence = array();
		$file     = $this->locate_block( 'loyalty_award_points' );
		if ( ! $file ) {
			$this->task_row( 'T-S5.2', 'FAIL', 'Action file present', '<code>loyalty_award_points.php</code> not found' );
			return;
		}
		$src = (string) @file_get_contents( $file );
		$has_class    = (bool) preg_match( '/class\s+WaicAction_loyalty_award_points\s+extends\s+WaicAction/', $src );
		$has_filter   = (bool) preg_match( "/apply_filters\(\s*['\"]bizcity_loyalty_award_points['\"]/", $src );
		$has_settings = (bool) preg_match( "/'points'\s*=>/", $src ) && (bool) preg_match( "/'campaign'\s*=>/", $src );
		$has_unhandled = (bool) preg_match( "/bizcity_loyalty_award_unhandled/", $src );
		$no_user_meta  = ! (bool) preg_match( "/update_user_meta\(.*bizcity_loyalty_points/", $src );

		$evidence[] = 'file: <code>' . esc_html( $this->short_path( $file ) ) . '</code>';
		$evidence[] = 'class WaicAction_loyalty_award_points: ' . $this->yn( $has_class );
		$evidence[] = "filter `bizcity_loyalty_award_points`: " . $this->yn( $has_filter );
		$evidence[] = 'settings (points + campaign): ' . $this->yn( $has_settings );
		$evidence[] = 'unhandled hook (`bizcity_loyalty_award_unhandled`): ' . $this->yn( $has_unhandled );
		$evidence[] = 'no schema-by-side-effect (no user_meta write): ' . $this->yn( $no_user_meta );

		$status = ( $has_class && $has_filter && $has_settings && $no_user_meta ) ? 'PASS' : 'WARN';
		$this->task_row( 'T-S5.2', $status, 'loyalty_award_points action (entry-point only)', implode( '<br>', $evidence ) );
	}

	private function check_t_s5_3() {
		$evidence = array();
		$file     = $this->locate_block( 'crm_capture_lead' );
		if ( ! $file ) {
			$this->task_row( 'T-S5.3', 'FAIL', 'Action file present', '<code>crm_capture_lead.php</code> not found' );
			return;
		}
		$src = (string) @file_get_contents( $file );
		$has_class    = (bool) preg_match( '/class\s+WaicAction_crm_capture_lead\s+extends\s+WaicAction/', $src );
		$has_filter   = (bool) preg_match( "/apply_filters\(\s*['\"]bizcity_crm_capture_lead['\"]/", $src );
		$has_settings = (bool) preg_match( "/'source'\s*=>/", $src )
			&& (bool) preg_match( "/'psid'\s*=>/", $src )
			&& (bool) preg_match( "/'campaign'\s*=>/", $src );
		$has_unhandled = (bool) preg_match( "/bizcity_crm_lead_unhandled/", $src );
		$no_cpt        = ! (bool) preg_match( "/register_post_type\(\s*['\"]bizcity_lead['\"]/", $src );

		$evidence[] = 'file: <code>' . esc_html( $this->short_path( $file ) ) . '</code>';
		$evidence[] = 'class WaicAction_crm_capture_lead: ' . $this->yn( $has_class );
		$evidence[] = "filter `bizcity_crm_capture_lead`: " . $this->yn( $has_filter );
		$evidence[] = 'settings (source/psid/campaign): ' . $this->yn( $has_settings );
		$evidence[] = 'unhandled hook (`bizcity_crm_lead_unhandled`): ' . $this->yn( $has_unhandled );
		$evidence[] = 'no schema-by-side-effect (no CPT register): ' . $this->yn( $no_cpt );

		$status = ( $has_class && $has_filter && $has_settings && $no_cpt ) ? 'PASS' : 'WARN';
		$this->task_row( 'T-S5.3', $status, 'crm_capture_lead action (entry-point only)', implode( '<br>', $evidence ) );
	}

	private function check_t_s5_4() {
		$evidence = array();
		$file     = $this->locate_block( 'wp_form_submitted' );
		if ( ! $file ) {
			$this->task_row( 'T-S5.4', 'FAIL', 'Trigger file present', '<code>wp_form_submitted.php</code> not found' );
			return;
		}
		$src = (string) @file_get_contents( $file );
		$has_class     = (bool) preg_match( '/class\s+WaicTrigger_wp_form_submitted\s+extends\s+WaicTrigger/', $src );
		$has_cf7       = (bool) preg_match( "/wpcf7_mail_sent/", $src );
		$has_gravity   = (bool) preg_match( "/gform_after_submission/", $src );
		$has_elementor = (bool) preg_match( "#elementor_pro/forms/new_record#", $src );
		$has_emit      = (bool) preg_match( "/bizcity_wp_form_submitted/", $src );

		$evidence[] = 'file: <code>' . esc_html( $this->short_path( $file ) ) . '</code>';
		$evidence[] = 'class WaicTrigger_wp_form_submitted: ' . $this->yn( $has_class );
		$evidence[] = 'CF7 bridge (wpcf7_mail_sent): ' . $this->yn( $has_cf7 );
		$evidence[] = 'Gravity bridge (gform_after_submission): ' . $this->yn( $has_gravity );
		$evidence[] = 'Elementor bridge (elementor_pro/forms/new_record): ' . $this->yn( $has_elementor );
		$evidence[] = 'unified key bizcity_wp_form_submitted: ' . $this->yn( $has_emit );

		$bridges_ok = (int) $has_cf7 + (int) $has_gravity + (int) $has_elementor;
		$status     = ( $has_class && $has_emit && $bridges_ok >= 2 ) ? 'PASS'
			: ( ( $has_class && $has_emit ) ? 'WARN' : 'FAIL' );
		$this->task_row( 'T-S5.4', $status, 'wp_form_submitted trigger (CF7/Gravity/Elementor)', implode( '<br>', $evidence ) );
	}

	private function check_t_s5_5() {
		$evidence = array();
		$file = WP_PLUGIN_DIR . '/bizcity-twin-ai/core/channel-gateway/tools/migrate-bizgpt-custom-flows.php';
		if ( ! is_readable( $file ) ) {
			$this->task_row( 'T-S5.5', 'FAIL', 'Migration scaffold present', '<code>core/channel-gateway/tools/migrate-bizgpt-custom-flows.php</code> not found' );
			return;
		}
		$src = (string) @file_get_contents( $file );
		$has_class   = (bool) preg_match( '/class\s+BizCity_Bizgpt_Custom_Flows_Migrator/', $src );
		$has_inspect = (bool) preg_match( '/function\s+inspect\s*\(/i', $src );
		$has_plan    = (bool) preg_match( '/function\s+plan_row\s*\(/i', $src );
		$has_execute = (bool) preg_match( '/function\s+execute\s*\(/i', $src );

		$evidence[] = 'file: <code>' . esc_html( $this->short_path( $file ) ) . '</code>';
		$evidence[] = 'BizCity_Bizgpt_Custom_Flows_Migrator class: ' . $this->yn( $has_class );
		$evidence[] = 'inspect()/plan_row()/execute(): ' . $this->yn( $has_inspect && $has_plan && $has_execute );

		// Detect if legacy table currently exists in this site.
		global $wpdb;
		$tbl    = $wpdb->prefix . 'bizgpt_custom_flows';
		$exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) );
		$evidence[] = 'legacy table <code>' . esc_html( $tbl ) . '</code>: ' . $this->yn( $exists );

		// Scaffold-only — execute() returns WP_Error('not_implemented'). That's acceptable for SKIP.
		$writer_done = (bool) preg_match( "/'not_implemented'/", $src ) ? false : $has_execute;

		if ( $has_class && $has_inspect && $has_plan && ! $writer_done ) {
			$status = 'SKIP'; // scaffold present, writer deferred per roadmap (P3)
			$evidence[] = '<span style="color:#646970">Writer is intentionally a stub (P3). Roadmap: scaffold + deprecation note only.</span>';
		} elseif ( $writer_done ) {
			$status = 'PASS';
		} else {
			$status = 'FAIL';
		}
		$this->task_row( 'T-S5.5', $status, 'bizgpt-custom-flows migration scaffold', implode( '<br>', $evidence ) );
	}

	/* =========================================================
	 * SPRINT 5.5 — Creative Canvas UX
	 * =======================================================*/

	private function check_t_s5b_1() {
		// un_merge_branches logic block (true barrier — wait + variable union).
		$evidence = array();
		$file = $this->locate_block( 'un_merge_branches' );
		if ( ! $file ) {
			$this->task_row( 'T-S5b.1', 'FAIL', 'Logic block un_merge_branches present', 'file not found in blocks/logics/' );
			return;
		}
		$src = (string) @file_get_contents( $file );
		$has_class       = (bool) preg_match( '/class\s+WaicLogic_un_merge_branches\s+extends\s+WaicLogic/', $src );
		$has_wait_modes  = (bool) preg_match( "/['\"]wait_all['\"].*['\"]wait_any['\"].*['\"]race['\"]/s", $src );
		$has_strategy    = (bool) preg_match( "/['\"]merge_strategy['\"]/", $src )
		                && (bool) preg_match( "/['\"](keyed|union|array)['\"]/", $src );
		$has_cross_run   = (bool) preg_match( '/cross_run/i', $src ) && (bool) preg_match( '/transient/i', $src );
		$has_timeout     = (bool) preg_match( '/timeout_secs/', $src );
		$has_safe_flag   = (bool) preg_match( '/_test_run_safe\s*=\s*true/', $src );

		$evidence[] = 'file: <code>' . esc_html( $this->short_path( $file ) ) . '</code>';
		$evidence[] = 'class WaicLogic_un_merge_branches: ' . $this->yn( $has_class );
		$evidence[] = 'wait_mode (wait_all/wait_any/race): ' . $this->yn( $has_wait_modes );
		$evidence[] = 'merge_strategy (keyed/union/array): ' . $this->yn( $has_strategy );
		$evidence[] = 'cross_run barrier + transient: ' . $this->yn( $has_cross_run );
		$evidence[] = 'timeout_secs guard: ' . $this->yn( $has_timeout );
		$evidence[] = '_test_run_safe = true: ' . $this->yn( $has_safe_flag );

		$ok = $has_class && $has_wait_modes && $has_strategy && $has_cross_run && $has_timeout;
		$this->task_row( 'T-S5b.1', $ok ? 'PASS' : 'FAIL', 'Branch merge logic block', implode( '<br>', $evidence ) );
	}

	private function check_t_s5b_2a() {
		// AJAX endpoint waic_test_run_block + class file.
		$evidence = array();
		$file = WP_PLUGIN_DIR . '/bizcity-twin-ai/core/channel-gateway/includes/class-test-run-block-api.php';
		$file_ok = is_readable( $file );
		$src     = $file_ok ? (string) @file_get_contents( $file ) : '';
		$has_class = (bool) preg_match( '/class\s+BizCity_Test_Run_Block_API/', $src );
		$has_handler = (bool) preg_match( '/wp_ajax_waic_test_run_block/', $src );
		$has_nonce_check = (bool) preg_match( '/check_ajax_referer\s*\(\s*self::NONCE_ACTION/', $src );
		$has_cap_check   = (bool) preg_match( "/current_user_can\s*\(\s*['\"]manage_options['\"]\s*\)/", $src );
		$registered      = has_action( 'wp_ajax_waic_test_run_block' ) !== false
		                 && has_action( 'wp_ajax_waic_test_run_block' ) !== 0;
		// `has_action` returns priority int when registered, false otherwise.
		$registered_runtime = ( false !== has_action( 'wp_ajax_waic_test_run_block' ) );

		$evidence[] = 'file: <code>' . esc_html( $this->short_path( $file ) ) . '</code>';
		$evidence[] = 'class BizCity_Test_Run_Block_API: ' . $this->yn( $has_class );
		$evidence[] = 'wp_ajax_waic_test_run_block hook: ' . $this->yn( $has_handler );
		$evidence[] = 'nonce check: ' . $this->yn( $has_nonce_check );
		$evidence[] = 'capability check (manage_options): ' . $this->yn( $has_cap_check );
		$evidence[] = 'runtime: action registered: ' . $this->yn( $registered_runtime );

		$ok = $file_ok && $has_class && $has_handler && $has_nonce_check && $has_cap_check && $registered_runtime;
		$this->task_row( 'T-S5b.2a', $ok ? 'PASS' : 'FAIL', 'Test-Run AJAX endpoint registered + secured', implode( '<br>', $evidence ) );
	}

	private function check_t_s5b_2b() {
		// Risky-block safety flag honored across base API + key risky blocks.
		$evidence = array();
		$api_file = WP_PLUGIN_DIR . '/bizcity-twin-ai/core/channel-gateway/includes/class-test-run-block-api.php';
		$api_src  = is_readable( $api_file ) ? (string) @file_get_contents( $api_file ) : '';
		$has_blacklist = (bool) preg_match( '/RISKY_BLOCKS\s*=\s*array\s*\(/', $api_src );
		$has_reflection = (bool) preg_match( '/_test_run_safe/', $api_src );
		$evidence[] = 'API has RISKY_BLOCKS list: ' . $this->yn( $has_blacklist );
		$evidence[] = 'API reads $_test_run_safe via reflection: ' . $this->yn( $has_reflection );

		// Spot-check that key risky blocks exist (so blacklist matches reality).
		$samples = array(
			'wp_send_facebook_bot_text',
			'wp_create_facebook_page_post',
			'crm_capture_lead',
			'loyalty_award_points',
		);
		$found = 0;
		$missing = array();
		foreach ( $samples as $code ) {
			$f = $this->locate_block( $code );
			if ( $f ) { $found++; } else { $missing[] = $code; }
		}
		$evidence[] = sprintf( 'risky block files present: %d/%d%s', $found, count( $samples ),
			$missing ? ' (missing: ' . esc_html( implode( ', ', $missing ) ) . ')' : '' );

		// FE injector file present.
		$fe_file = WP_PLUGIN_DIR . '/bizcity-twin-ai/plugins/_archived/bizcity-automation/modules/workflow/assets-static/test-run-button.js';
		$fe_ok   = is_readable( $fe_file );
		$evidence[] = 'FE injector test-run-button.js: ' . $this->yn( $fe_ok ) . ( $fe_ok ? ' <code>' . esc_html( $this->short_path( $fe_file ) ) . '</code>' : '' );

		$status = ( $has_blacklist && $has_reflection && $fe_ok && $found >= 2 ) ? 'PASS'
			: ( ( $has_blacklist && $has_reflection ) ? 'WARN' : 'FAIL' );
		$this->task_row( 'T-S5b.2b', $status, 'Side-effect safety + FE button injector', implode( '<br>', $evidence ) );
	}

	private function check_t_s5b_3() {
		// Wide canvas CSS + JS shipped + enqueued.
		$evidence = array();
		$css_file = WP_PLUGIN_DIR . '/bizcity-twin-ai/plugins/_archived/bizcity-automation/modules/workflow/assets-static/wide-canvas.css';
		$js_file  = WP_PLUGIN_DIR . '/bizcity-twin-ai/plugins/_archived/bizcity-automation/modules/workflow/assets-static/wide-canvas.js';
		$view_file = WP_PLUGIN_DIR . '/bizcity-twin-ai/plugins/_archived/bizcity-automation/modules/workflow/views/workflow.php';

		$css_ok = is_readable( $css_file );
		$js_ok  = is_readable( $js_file );
		$css_src = $css_ok ? (string) @file_get_contents( $css_file ) : '';
		$js_src  = $js_ok  ? (string) @file_get_contents( $js_file )  : '';

		$has_class    = (bool) preg_match( '/\.waic-canvas--wide/', $css_src );
		$has_drawer   = (bool) preg_match( '/waic-drawer-slide-in/', $css_src );
		$has_toggle   = (bool) preg_match( '/waic-wide-canvas-toggle/', $js_src );
		$has_storage  = (bool) preg_match( '/localStorage/', $js_src );

		$view_src = is_readable( $view_file ) ? (string) @file_get_contents( $view_file ) : '';
		$enq_css  = (bool) preg_match( "/wide-canvas\\.css/", $view_src );
		$enq_js   = (bool) preg_match( "/wide-canvas\\.js/",  $view_src );

		$evidence[] = 'CSS file: ' . $this->yn( $css_ok ) . ' <code>' . esc_html( $this->short_path( $css_file ) ) . '</code>';
		$evidence[] = 'JS  file: ' . $this->yn( $js_ok )  . ' <code>' . esc_html( $this->short_path( $js_file ) )  . '</code>';
		$evidence[] = 'CSS .waic-canvas--wide selector: ' . $this->yn( $has_class );
		$evidence[] = 'CSS drawer slide-in animation: ' . $this->yn( $has_drawer );
		$evidence[] = 'JS toggle button injector: ' . $this->yn( $has_toggle );
		$evidence[] = 'JS persists pref via localStorage: ' . $this->yn( $has_storage );
		$evidence[] = 'view enqueues CSS + JS: ' . $this->yn( $enq_css && $enq_js );

		$ok = $css_ok && $js_ok && $has_class && $has_drawer && $has_toggle && $enq_css && $enq_js;
		$this->task_row( 'T-S5b.3', $ok ? 'PASS' : 'FAIL', 'Wide canvas CSS + toggle JS shipped & enqueued', implode( '<br>', $evidence ) );
	}

	/* =========================================================
	 * LIVE PROBES
	 * =======================================================*/

	private function render_probe_forms() {
		echo '<form method="post" style="display:inline-block;margin-right:10px">';
		wp_nonce_field( 'bizcity_cg_sprint_diag' );
		echo '<input type="hidden" name="bizcity_action" value="reset_opcache">';
		echo '<button class="button">Reset OPcache (force reload mu-plugin files)</button>';
		echo '</form>';

		echo '<form method="post" style="display:inline-block;margin-right:10px">';
		wp_nonce_field( 'bizcity_cg_sprint_diag' );
		echo '<input type="hidden" name="bizcity_action" value="probe_inbound">';
		echo '<button class="button">Probe inbound: simulate FB Messenger payload</button>';
		echo '</form>';

		echo '<form method="post" style="display:inline-block;margin-right:10px">';
		wp_nonce_field( 'bizcity_cg_sprint_diag' );
		echo '<input type="hidden" name="bizcity_action" value="probe_send">';
		echo '<input type="text" name="probe_chat_id" placeholder="fb_PAGEID_PSID" style="width:240px">';
		echo '<input type="text" name="probe_message" placeholder="hello from diagnostic" style="width:280px" value="ping from sprint-diagnostic">';
		echo '<button class="button button-primary">Probe outbound: send via gateway</button>';
		echo '</form>';

		echo '<form method="post" style="display:inline-block">';
		wp_nonce_field( 'bizcity_cg_sprint_diag' );
		echo '<input type="hidden" name="bizcity_action" value="flush_rewrites">';
		echo '<button class="button">Flush rewrite rules</button>';
		echo '</form>';
	}

	private function action_probe_inbound() {
		if ( ! class_exists( 'BizCity_Gateway_Bridge' ) ) {
			$this->notice( 'error', 'BizCity_Gateway_Bridge not loaded.' );
			return;
		}
		$adapter = BizCity_Gateway_Bridge::instance()->get_adapter( 'FACEBOOK' );
		if ( ! $adapter ) {
			$this->notice( 'error', 'FACEBOOK adapter not registered.' );
			return;
		}
		$fake_payload = array(
			'object' => 'page',
			'entry'  => array( array(
				'id'        => '0000000000',
				'time'      => time(),
				'messaging' => array( array(
					'sender'    => array( 'id' => '1111111111' ),
					'recipient' => array( 'id' => '0000000000' ),
					'timestamp' => time() * 1000,
					'message'   => array( 'mid' => 'm_diag_' . wp_generate_password( 8, false ), 'text' => 'diagnostic probe' ),
				) ),
			) ),
		);
		try {
			$normalized = $adapter->normalize_inbound( $fake_payload );
			$this->notice( 'success', 'normalize_inbound() returned ' . count( (array) $normalized ) . ' fields.' );
			echo '<pre style="background:#f6f7f7;padding:10px;max-width:960px;overflow:auto">' . esc_html( wp_json_encode( $normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ) . '</pre>';
		} catch ( \Throwable $e ) {
			$this->notice( 'error', 'normalize_inbound() threw: ' . $e->getMessage() );
		}
	}

	private function action_probe_send() {
		$chat_id = isset( $_POST['probe_chat_id'] ) ? sanitize_text_field( wp_unslash( $_POST['probe_chat_id'] ) ) : '';
		$msg     = isset( $_POST['probe_message'] ) ? sanitize_text_field( wp_unslash( $_POST['probe_message'] ) ) : '';
		if ( ! $chat_id || ! $msg ) {
			$this->notice( 'error', 'Need both chat_id and message.' );
			return;
		}
		if ( ! function_exists( 'bizcity_channel_send' ) ) {
			$this->notice( 'error', 'bizcity_channel_send() not available.' );
			return;
		}
		$result = bizcity_channel_send( $chat_id, $msg, 'text', array( 'source' => 'sprint-diag' ) );
		$class  = ! empty( $result['sent'] ) ? 'success' : 'error';
		$this->notice( $class, 'bizcity_channel_send result:' );
		echo '<pre style="background:#f6f7f7;padding:10px;max-width:960px;overflow:auto">' . esc_html( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ) . '</pre>';
	}

	/* =========================================================
	 * T-S1.6 — KG-Hub deep-dive (REST + SQL inspection)
	 * =======================================================*/

	private function render_kg_section() {
		echo '<h2 style="margin-top:30px">T-S1.6 — KG-Hub: REST + SQL probes</h2>';
		echo '<p>Validate the public REST surface (<code>/wp-json/bizcity/v1/kg/query</code>) end-to-end AND inspect the SQL the retriever issues, so we know the right rows actually come back.</p>';

		global $wpdb;
		$tbl_nb     = $wpdb->prefix . 'bizcity_kg_notebooks';
		$tbl_chunks = class_exists( 'BizCity_KG_Database' )
			? BizCity_KG_Database::instance()->tbl_source_chunks()
			: ( $wpdb->prefix . 'bizcity_kg_passages' );

		// Schema sanity
		$nb_exists     = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl_nb ) );
		$chunks_exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl_chunks ) );
		$nb_count      = $nb_exists     ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl_nb}" ) : 0;
		$chunk_count   = $chunks_exists ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl_chunks}" ) : 0;
		$embed_done    = $chunks_exists ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl_chunks} WHERE embed_status='done'" ) : 0;
		$embed_pending = $chunks_exists ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl_chunks} WHERE embed_status='pending'" ) : 0;
		$embed_err     = $chunks_exists ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl_chunks} WHERE embed_status='error'" ) : 0;

		echo '<table class="widefat striped" style="max-width:1200px"><tbody>';
		$this->row( 'Notebooks table', '<code>' . esc_html( $tbl_nb ) . '</code>' );
		$this->row( 'Notebooks rows', $nb_exists ? $nb_count : '<span style="color:#b32d2e">table missing</span>' );
		$this->row( 'Source-chunks table', '<code>' . esc_html( $tbl_chunks ) . '</code>' );
		$this->row( 'Chunks rows', $chunks_exists ? $chunk_count : '<span style="color:#b32d2e">table missing</span>' );
		$this->row( 'Embed status — done',    $embed_done );
		$this->row( 'Embed status — pending', $embed_pending );
		$this->row( 'Embed status — error',   $embed_err );
		$this->row( 'Public API token configured', $this->yn( '' !== (string) get_option( 'bizcity_kg_public_api_token', '' ) ) );
		echo '</tbody></table>';

		// Per-notebook breakdown — top 10 with chunk counts.
		if ( $nb_exists ) {
			$rows = $wpdb->get_results( "
				SELECT n.id, n.uuid, n.name,
					(SELECT COUNT(*) FROM {$tbl_chunks} c WHERE c.notebook_id = n.id) AS chunks_total,
					(SELECT COUNT(*) FROM {$tbl_chunks} c WHERE c.notebook_id = n.id AND c.embed_status='done') AS chunks_ready
				FROM {$tbl_nb} n
				ORDER BY chunks_ready DESC, n.id DESC
				LIMIT 10
			" );
			if ( $rows ) {
				echo '<h3 style="margin-top:14px">Top notebooks (by ready chunks)</h3>';
				echo '<table class="widefat striped" style="max-width:1200px"><thead><tr>'
					. '<th>id</th><th>name</th><th>uuid</th><th>chunks total</th><th>chunks ready (embed=done)</th><th>can RAG?</th></tr></thead><tbody>';
				foreach ( $rows as $r ) {
					$ok = ( (int) $r->chunks_ready ) > 0;
					printf( '<tr><td>%d</td><td>%s</td><td><code>%s</code></td><td>%d</td><td>%d</td><td>%s</td></tr>',
						(int) $r->id,
						esc_html( (string) $r->name ),
						esc_html( (string) $r->uuid ),
						(int) $r->chunks_total,
						(int) $r->chunks_ready,
						$this->badge( $ok ? 'PASS' : 'FAIL' )
					);
				}
				echo '</tbody></table>';
			}
		}

		// Probe forms.
		$default_nb = isset( $_POST['kg_notebook_id'] ) ? (int) $_POST['kg_notebook_id'] : 22;
		$default_q  = isset( $_POST['kg_query'] ) ? wp_unslash( (string) $_POST['kg_query'] ) : 'Twin AI là gì?';

		echo '<h3 style="margin-top:14px">Live probes</h3>';
		echo '<form method="post" style="display:inline-block;margin-right:10px;vertical-align:top">';
		wp_nonce_field( 'bizcity_cg_sprint_diag' );
		echo '<input type="hidden" name="bizcity_action" value="kg_probe_block">';
		echo '<input type="number" name="kg_notebook_id" value="' . esc_attr( (string) $default_nb ) . '" min="1" style="width:90px" placeholder="notebook_id"> ';
		echo '<input type="text" name="kg_query" value="' . esc_attr( $default_q ) . '" style="width:380px" placeholder="query"> ';
		echo '<button class="button button-primary">Probe block (direct retriever)</button>';
		echo '</form>';

		echo '<form method="post" style="display:inline-block;margin-right:10px;vertical-align:top">';
		wp_nonce_field( 'bizcity_cg_sprint_diag' );
		echo '<input type="hidden" name="bizcity_action" value="kg_probe_rest">';
		echo '<input type="number" name="kg_notebook_id" value="' . esc_attr( (string) $default_nb ) . '" min="1" style="width:90px"> ';
		echo '<input type="text" name="kg_query" value="' . esc_attr( $default_q ) . '" style="width:380px"> ';
		echo '<button class="button">Probe REST (loopback POST)</button>';
		echo '</form>';

		echo '<form method="post" style="display:inline-block;margin-right:10px;vertical-align:top">';
		wp_nonce_field( 'bizcity_cg_sprint_diag' );
		echo '<input type="hidden" name="bizcity_action" value="kg_inspect_sql">';
		echo '<input type="number" name="kg_notebook_id" value="' . esc_attr( (string) $default_nb ) . '" min="1" style="width:90px"> ';
		echo '<input type="text" name="kg_query" value="' . esc_attr( $default_q ) . '" style="width:380px" placeholder="query (optional, dùng để live-capture SQL)"> ';
		echo '<button class="button">Inspect SQL (capture wpdb queries)</button>';
		echo '</form>';

		echo '<form method="post" style="display:inline-block;vertical-align:top">';
		wp_nonce_field( 'bizcity_cg_sprint_diag' );
		echo '<input type="hidden" name="bizcity_action" value="kg_set_token">';
		echo '<input type="text" name="kg_token" value="" style="width:280px" placeholder="new public API token (leave empty to clear)"> ';
		echo '<button class="button">Set public API token</button>';
		echo '</form>';
	}

	/**
	 * Direct call to BizCity_KG_Retriever via the workflow block (no HTTP).
	 */
	private function action_kg_probe_block() {
		$nb_id = isset( $_POST['kg_notebook_id'] ) ? (int) $_POST['kg_notebook_id'] : 0;
		$q     = isset( $_POST['kg_query'] ) ? trim( wp_unslash( (string) $_POST['kg_query'] ) ) : '';
		if ( $nb_id <= 0 || '' === $q ) {
			$this->notice( 'error', 'kg_probe_block: need notebook_id and query.' );
			return;
		}
		if ( ! class_exists( 'BizCity_KG_Retriever' ) ) {
			$this->notice( 'error', 'BizCity_KG_Retriever not loaded.' );
			return;
		}
		$started = microtime( true );
		try {
			$raw = BizCity_KG_Retriever::instance()->ask( $nb_id, $q, array(
				'rerank_top_k' => 5,
				'expand_hops'  => 1,
				'answer'       => false,
			) );
		} catch ( \Throwable $e ) {
			$this->notice( 'error', 'retriever threw: ' . $e->getMessage() );
			return;
		}
		$took = (int) round( ( microtime( true ) - $started ) * 1000 );
		$passages = is_array( $raw ) && isset( $raw['passages'] ) ? (array) $raw['passages'] : array();
		$count = count( $passages );
		$status = $count > 0 ? 'success' : 'warning';
		$this->notice( $status, sprintf( 'Direct retriever returned %d passage(s) in %d ms.', $count, $took ) );
		if ( $count === 0 ) {
			echo '<div class="notice notice-warning"><p><strong>Empty result diagnosis:</strong> notebook may have 0 chunks with <code>embed_status=done</code>. Check the schema table above.</p></div>';
		}
		echo '<pre style="background:#f6f7f7;padding:10px;max-width:1200px;max-height:400px;overflow:auto">'
			. esc_html( wp_json_encode( array(
				'took_ms'       => $took,
				'passage_count' => $count,
				'passages_head' => array_slice( $passages, 0, 3 ),
				'keys_returned' => is_array( $raw ) ? array_keys( $raw ) : array(),
			), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) )
			. '</pre>';
	}

	/**
	 * Loopback POST to /wp-json/bizcity/v1/kg/query — proves REST registration + auth.
	 */
	private function action_kg_probe_rest() {
		$nb_id = isset( $_POST['kg_notebook_id'] ) ? (int) $_POST['kg_notebook_id'] : 0;
		$q     = isset( $_POST['kg_query'] ) ? trim( wp_unslash( (string) $_POST['kg_query'] ) ) : '';
		if ( $nb_id <= 0 || '' === $q ) {
			$this->notice( 'error', 'kg_probe_rest: need notebook_id and query.' );
			return;
		}
		// Use internal REST dispatch — avoids cookies/nonce issues, runs as the
		// current admin user so capability check passes.
		$req = new WP_REST_Request( 'POST', '/bizcity/v1/kg/query' );
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_body( wp_json_encode( array(
			'notebook_id' => $nb_id,
			'query'       => $q,
			'limit'       => 5,
			'answer'      => false,
		) ) );
		$res = rest_do_request( $req );
		if ( $res->is_error() ) {
			$err = $res->as_error();
			$this->notice( 'error', 'REST returned error: ' . $err->get_error_code() . ' — ' . $err->get_error_message() );
		} else {
			$data = $res->get_data();
			$count = is_array( $data ) && isset( $data['passage_count'] ) ? (int) $data['passage_count'] : 0;
			$this->notice( $count > 0 ? 'success' : 'warning',
				sprintf( 'REST status=%d, passage_count=%d, took_ms=%s',
					(int) $res->get_status(),
					$count,
					isset( $data['took_ms'] ) ? (int) $data['took_ms'] : 'n/a'
				)
			);
			echo '<pre style="background:#f6f7f7;padding:10px;max-width:1200px;max-height:400px;overflow:auto">'
				. esc_html( wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) )
				. '</pre>';
		}
	}

	/**
	 * Capture every SQL statement the retriever issues, plus run the candidate
	 * SQL the retriever is *expected* to run, so we can compare row counts.
	 */
	private function action_kg_inspect_sql() {
		global $wpdb;
		$nb_id = isset( $_POST['kg_notebook_id'] ) ? (int) $_POST['kg_notebook_id'] : 0;
		$probe_q = isset( $_POST['kg_query'] ) ? trim( wp_unslash( (string) $_POST['kg_query'] ) ) : '';
		if ( '' === $probe_q ) {
			$probe_q = 'diagnostic probe';
		}
		if ( $nb_id <= 0 ) {
			$this->notice( 'error', 'kg_inspect_sql: need notebook_id.' );
			return;
		}
		$tbl_chunks = class_exists( 'BizCity_KG_Database' )
			? BizCity_KG_Database::instance()->tbl_source_chunks()
			: ( $wpdb->prefix . 'bizcity_kg_passages' );
		$tbl_ents      = $wpdb->prefix . 'bizcity_kg_entities';
		$tbl_rels      = $wpdb->prefix . 'bizcity_kg_relations';

		// 0) Detect real columns of chunks table → tránh blank cell khi cột không tồn tại.
		$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$tbl_chunks}", 0 );
		$has_embed_status = in_array( 'embed_status', (array) $cols, true );
		$has_content      = in_array( 'content', (array) $cols, true );
		$has_text         = in_array( 'text', (array) $cols, true );
		$content_col      = $has_content ? 'content' : ( $has_text ? 'text' : null );

		echo '<h3>Schema introspection — <code>' . esc_html( $tbl_chunks ) . '</code></h3>';
		echo '<p>Columns detected: <code>' . esc_html( implode( ', ', (array) $cols ) ) . '</code></p>';
		if ( ! $has_embed_status ) {
			echo '<div class="notice notice-warning inline"><p><strong>Missing column <code>embed_status</code></strong> — Phase 0.6.5 migration chưa apply trên DB này. Retriever có thể fall back sang <code>embedding IS NOT NULL</code>.</p></div>';
		}
		if ( ! $content_col ) {
			echo '<div class="notice notice-error inline"><p><strong>Missing both <code>content</code> AND <code>text</code> columns</strong> — chunks table schema không khớp.</p></div>';
		}

		// 1) Static expectation queries — tự thích nghi với schema thật.
		$expect = array();
		$expect['rows for notebook (any status)'] = $wpdb->prepare(
			"SELECT COUNT(*) FROM {$tbl_chunks} WHERE notebook_id = %d", $nb_id
		);
		if ( $has_embed_status ) {
			$expect['rows for notebook (embed=done)']    = $wpdb->prepare( "SELECT COUNT(*) FROM {$tbl_chunks} WHERE notebook_id = %d AND embed_status='done'",    $nb_id );
			$expect['rows for notebook (embed=pending)'] = $wpdb->prepare( "SELECT COUNT(*) FROM {$tbl_chunks} WHERE notebook_id = %d AND embed_status='pending'", $nb_id );
			$expect['rows for notebook (embed=error)']   = $wpdb->prepare( "SELECT COUNT(*) FROM {$tbl_chunks} WHERE notebook_id = %d AND embed_status='error'",   $nb_id );
		}
		$expect['rows for notebook (embedding NOT NULL)'] = $wpdb->prepare(
			"SELECT COUNT(*) FROM {$tbl_chunks} WHERE notebook_id = %d AND embedding IS NOT NULL AND embedding != ''", $nb_id
		);
		$status_col = $has_embed_status ? 'embed_status' : "''";
		$content_expr = $content_col ? "LEFT({$content_col}, 80)" : "''";
		$expect['sample 5 chunks'] = $wpdb->prepare(
			"SELECT id, source_id, {$status_col} AS status, {$content_expr} AS content_head FROM {$tbl_chunks} WHERE notebook_id = %d ORDER BY id DESC LIMIT 5",
			$nb_id
		);

		// Entity/relation counts — vì retriever graph-first.
		$ents_exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl_ents ) );
		$rels_exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl_rels ) );
		if ( $ents_exists ) {
			$expect['ENTITIES — total notebook'] = $wpdb->prepare( "SELECT COUNT(*) FROM {$tbl_ents} WHERE notebook_id = %d", $nb_id );
			$expect['ENTITIES — approved + embedded (retriever-eligible)'] = $wpdb->prepare(
				"SELECT COUNT(*) FROM {$tbl_ents} WHERE notebook_id = %d AND status='approved' AND embedding IS NOT NULL AND deleted_at IS NULL",
				$nb_id
			);
		}
		if ( $rels_exists ) {
			$expect['RELATIONS — total notebook'] = $wpdb->prepare( "SELECT COUNT(*) FROM {$tbl_rels} WHERE notebook_id = %d", $nb_id );
			$expect['RELATIONS — approved + embedded (retriever-eligible)'] = $wpdb->prepare(
				"SELECT COUNT(*) FROM {$tbl_rels} WHERE notebook_id = %d AND status='approved' AND embedding IS NOT NULL AND deleted_at IS NULL",
				$nb_id
			);
		}

		echo '<h3>Expected SQL evidence (notebook #' . esc_html( (string) $nb_id ) . ')</h3>';
		echo '<table class="widefat striped" style="max-width:1400px"><thead><tr><th>Description</th><th>SQL</th><th>Result</th></tr></thead><tbody>';
		foreach ( $expect as $label => $sql ) {
			$wpdb->last_error = '';
			if ( false !== stripos( $label, 'sample' ) ) {
				$rows = $wpdb->get_results( $sql, ARRAY_A );
				if ( $wpdb->last_error ) {
					$out = '<span style="color:#b32d2e">SQL ERROR: ' . esc_html( $wpdb->last_error ) . '</span>';
				} else {
					$out = '<pre style="margin:0;max-height:240px;overflow:auto">' . esc_html( wp_json_encode( $rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ) . '</pre>';
				}
			} else {
				$val = $wpdb->get_var( $sql );
				if ( $wpdb->last_error ) {
					$out = '<span style="color:#b32d2e">SQL ERROR: ' . esc_html( $wpdb->last_error ) . '</span>';
				} elseif ( null === $val ) {
					$out = '<em style="color:#999">NULL (no rows / column missing)</em>';
				} else {
					$out = '<strong>' . esc_html( (string) $val ) . '</strong>';
				}
			}
			printf( '<tr><td>%s</td><td><code style="font-size:11px">%s</code></td><td>%s</td></tr>',
				esc_html( $label ),
				esc_html( $sql ),
				$out
			);
		}
		echo '</tbody></table>';

		echo '<div class="notice notice-info inline" style="margin-top:8px"><p><strong>Lưu ý kiến trúc:</strong> retriever là <strong>graph-first</strong> — nó vector-search trên <code>kg_entities</code> + <code>kg_relations</code> trước, rồi mới expand sang passages. Nếu 2 dòng "ENTITIES/RELATIONS approved+embedded" = 0 thì <em>chắc chắn</em> ra 0 passages dù chunks table có nhiều rows.</p></div>';

		// 2) Live capture: enable SAVEQUERIES, run the retriever, dump every query.
		if ( ! defined( 'SAVEQUERIES' ) ) {
			define( 'SAVEQUERIES', true );
		}
		$wpdb->queries = array();
		$baseline = count( $wpdb->queries );

		if ( ! class_exists( 'BizCity_KG_Retriever' ) ) {
			$this->notice( 'error', 'BizCity_KG_Retriever not loaded — cannot capture SQL.' );
			return;
		}

		$started = microtime( true );
		try {
			$raw = BizCity_KG_Retriever::instance()->ask( $nb_id, $probe_q, array(
				'rerank_top_k' => 5,
				'expand_hops'  => 1,
				'answer'       => false,
			) );
		} catch ( \Throwable $e ) {
			$this->notice( 'error', 'retriever threw: ' . $e->getMessage() );
			return;
		}
		$took = (int) round( ( microtime( true ) - $started ) * 1000 );
		$captured = array_slice( $wpdb->queries, $baseline );
		$pass_count = is_array( $raw ) && isset( $raw['passages'] ) ? count( (array) $raw['passages'] ) : 0;
		$ent_count  = is_array( $raw ) && isset( $raw['query_entities'] ) ? count( (array) $raw['query_entities'] ) : 0;
		$rel_count  = is_array( $raw ) && isset( $raw['reranked_relations'] ) ? count( (array) $raw['reranked_relations'] ) : 0;

		$this->notice( $pass_count > 0 ? 'success' : 'warning',
			sprintf( 'Retriever ran in %d ms with query=%s — %d entities matched, %d relations reranked, %d passage(s), %d SQL stmts.',
				$took, '"' . esc_html( $probe_q ) . '"', $ent_count, $rel_count, $pass_count, count( $captured )
			)
		);

		echo '<h3>Live SQL captured during retriever run</h3>';
		if ( ! $captured ) {
			echo '<p><em>No SQL captured (SAVEQUERIES may have been disabled before this request started — try Reset OPcache then reload).</em></p>';
		} else {
			echo '<table class="widefat striped" style="max-width:1400px"><thead><tr><th>#</th><th>ms</th><th>SQL</th></tr></thead><tbody>';
			foreach ( $captured as $i => $q ) {
				$sql = isset( $q[0] ) ? (string) $q[0] : '';
				$ms  = isset( $q[1] ) ? (float)  $q[1] * 1000 : 0;
				$short = strlen( $sql ) > 1200 ? substr( $sql, 0, 1200 ) . ' …' : $sql;
				printf( '<tr><td>%d</td><td>%.2f</td><td><code style="font-size:11px;white-space:pre-wrap">%s</code></td></tr>',
					$i + 1, $ms, esc_html( $short )
				);
			}
			echo '</tbody></table>';
		}
	}

	private function action_kg_set_token() {
		$token = isset( $_POST['kg_token'] ) ? trim( wp_unslash( (string) $_POST['kg_token'] ) ) : '';
		update_option( 'bizcity_kg_public_api_token', $token );
		$this->notice( 'success', '' === $token ? 'Public API token cleared.' : 'Public API token saved (' . strlen( $token ) . ' chars).' );
	}

	/* =========================================================
	 * HELPERS
	 * =======================================================*/

	private function locate_block( $code ) {
		$bases = array(
			WP_PLUGIN_DIR . '/bizcity-twin-ai/plugins/_archived/bizcity-automation/modules/workflow/blocks/actions/',
			WP_PLUGIN_DIR . '/bizcity-twin-ai/plugins/_archived/bizcity-automation/modules/workflow/blocks/triggers/',
			WP_PLUGIN_DIR . '/bizcity-twin-ai/plugins/_archived/bizcity-automation/modules/workflow/blocks/logics/',
			WP_PLUGIN_DIR . '/bizcity-twin-ai/plugins/_archived/bizcity-automation/modules/workflow/blocks/',
		);
		foreach ( $bases as $b ) {
			$f = $b . $code . '.php';
			if ( is_readable( $f ) ) {
				return $f;
			}
		}
		// Fallback: glob any depth
		$matches = glob( WP_PLUGIN_DIR . '/bizcity-twin-ai/plugins/_archived/bizcity-automation/modules/workflow/blocks/**/' . $code . '.php' ) ?: array();
		return $matches ? $matches[0] : '';
	}

	private function short_path( $abs ) {
		if ( ! $abs ) { return ''; }
		$wp = str_replace( '\\', '/', WP_CONTENT_DIR );
		$ab = str_replace( '\\', '/', (string) $abs );
		return ( 0 === strpos( $ab, $wp ) ) ? '/wp-content' . substr( $ab, strlen( $wp ) ) : $ab;
	}

	private function yn( $v ) {
		return $v ? '<span style="color:#1a7e1a">YES</span>' : '<span style="color:#b32d2e">NO</span>';
	}

	private function badge( $status ) {
		$colors = array(
			'PASS' => array( '#46b450', '#fff' ),
			'WARN' => array( '#ffb900', '#000' ),
			'FAIL' => array( '#dc3232', '#fff' ),
			'SKIP' => array( '#999',    '#fff' ),
		);
		$c = isset( $colors[ $status ] ) ? $colors[ $status ] : array( '#777', '#fff' );
		return sprintf( '<span style="display:inline-block;padding:2px 10px;border-radius:3px;font-weight:600;background:%s;color:%s">%s</span>', $c[0], $c[1], $status );
	}

	private function task_row( $task, $status, $check, $evidence ) {
		// Always record for collect_results() consumers.
		$this->collected_rows[] = array(
			'task'   => (string) $task,
			'status' => (string) $status,
			'check'  => (string) $check,
		);
		printf( '<tr><td><strong>%s</strong></td><td>%s</td><td>%s</td><td>%s</td></tr>',
			esc_html( $task ),
			$this->badge( $status ),
			esc_html( $check ),
			$evidence // already escaped per-field above
		);
	}

	/* =========================================================
	 * SPRINT CG-SPA — Per-platform workspace SPA
	 * See: core/channel-gateway/PHASE-CG-SPA-WORKSPACE.md
	 * =======================================================*/

	private function check_t_cg_spa_1() {
		$base    = dirname( __DIR__ ) . '/assets/dist/';
		$js      = $base . 'channel-gateway-app.js';
		$css     = $base . 'channel-gateway-app.css';
		$js_ok   = is_file( $js ) && filesize( $js ) > 50 * 1024;
		$css_ok  = is_file( $css ) && filesize( $css ) > 4 * 1024;
		$status  = ( $js_ok && $css_ok ) ? 'PASS' : 'FAIL';
		$ev = sprintf(
			'<code>js: %s (%s bytes, %s)</code><br><code>css: %s (%s bytes, %s)</code>',
			esc_html( $js_ok ? 'OK' : 'MISSING' ),
			esc_html( is_file( $js ) ? number_format( (int) filesize( $js ) ) : '0' ),
			esc_html( is_file( $js ) ? wp_date( 'Y-m-d H:i', (int) filemtime( $js ) ) : '—' ),
			esc_html( $css_ok ? 'OK' : 'MISSING' ),
			esc_html( is_file( $css ) ? number_format( (int) filesize( $css ) ) : '0' ),
			esc_html( is_file( $css ) ? wp_date( 'Y-m-d H:i', (int) filemtime( $css ) ) : '—' )
		);
		$this->task_row( 'T-CG-SPA.1', $status, 'SPA bundle artifacts present + sane filesize', $ev );
	}

	private function check_t_cg_spa_2() {
		$routes = function_exists( 'rest_get_server' ) ? rest_get_server()->get_routes() : array();
		$key    = '/bizcity-channel/v1/logs';
		$has    = isset( $routes[ $key ] );
		$perm_ok = false;
		if ( $has ) {
			foreach ( $routes[ $key ] as $route ) {
				$methods = (array) ( $route['methods'] ?? array() );
				if ( ! empty( $methods['GET'] ) || in_array( 'GET', $methods, true ) ) {
					$perm_ok = true;
					break;
				}
			}
		}
		$status = ( $has && $perm_ok ) ? 'PASS' : 'FAIL';
		$ev = sprintf(
			'<code>route registered: %s</code> · <code>GET method: %s</code>',
			esc_html( $has ? 'yes' : 'no' ),
			esc_html( $perm_ok ? 'yes' : 'no' )
		);
		$this->task_row( 'T-CG-SPA.2', $status, 'GET /bizcity-channel/v1/logs registered', $ev );
	}

	private function check_t_cg_spa_3() {
		if ( ! class_exists( 'BizCity_Webhook_Log' ) ) {
			$this->task_row( 'T-CG-SPA.3', 'FAIL', 'list_logs() loopback dispatch', '<code>BizCity_Webhook_Log not loaded</code>' );
			return;
		}
		$req = new WP_REST_Request( 'GET', '/bizcity-channel/v1/logs' );
		$req->set_query_params( array( 'days' => 3, 'limit' => 5 ) );
		$resp = rest_do_request( $req );
		$ok   = ! is_wp_error( $resp ) && $resp->get_status() === 200;
		$data = $ok ? (array) $resp->get_data() : array();
		$status = ( $ok && ( $data['success'] ?? false ) ) ? 'PASS' : 'FAIL';
		$ev = sprintf(
			'<code>HTTP %s</code> · <code>count=%s</code> · <code>filters=%s</code>',
			esc_html( $ok ? (string) $resp->get_status() : 'err' ),
			esc_html( (string) ( $data['count'] ?? '—' ) ),
			esc_html( wp_json_encode( $data['filters'] ?? new stdClass() ) )
		);
		$this->task_row( 'T-CG-SPA.3', $status, 'list_logs() loopback wraps BizCity_Webhook_Log::query()', $ev );
	}

	private function check_t_cg_spa_4() {
		if ( ! class_exists( 'BizCity_Channel_Gateway_Admin_Menu_SPA' ) ) {
			$this->task_row( 'T-CG-SPA.4', 'FAIL', 'BOOT.platforms ready-flag matrix matches registry', '<code>SPA admin menu class not loaded</code>' );
			return;
		}
		$ref = new ReflectionClass( 'BizCity_Channel_Gateway_Admin_Menu_SPA' );
		if ( ! $ref->hasMethod( 'platform_catalog' ) ) {
			$this->task_row( 'T-CG-SPA.4', 'FAIL', 'BOOT.platforms ready-flag matrix matches registry', '<code>platform_catalog() method not found</code>' );
			return;
		}
		$m = $ref->getMethod( 'platform_catalog' );
		$m->setAccessible( true );
		$inst    = new BizCity_Channel_Gateway_Admin_Menu_SPA();
		$catalog = (array) $m->invoke( $inst );

		$total     = count( $catalog );
		$ready_cnt = 0;
		$mismatch  = array();
		foreach ( $catalog as $p ) {
			if ( ! empty( $p['ready'] ) ) {
				$ready_cnt++;
			}
			// Google Phase-2 entries SHOULD be ready=false.
			if ( ( $p['group'] ?? '' ) === 'google' && ! empty( $p['ready'] ) ) {
				$mismatch[] = sprintf( '%s ready=true (expected Phase-2 false)', (string) $p['code'] );
			}
		}
		$status = ( empty( $mismatch ) && $total > 0 ) ? 'PASS' : 'FAIL';
		$ev = sprintf(
			'<code>platforms=%d · ready=%d</code>%s',
			(int) $total,
			(int) $ready_cnt,
			$mismatch ? '<br><code>mismatch: ' . esc_html( implode( '; ', $mismatch ) ) . '</code>' : ''
		);
		$this->task_row( 'T-CG-SPA.4', $status, 'BOOT.platforms catalog populated + Phase-2 google = ready=false', $ev );
	}

	private function row( $k, $v ) {
		printf( '<tr><th style="width:340px;text-align:left">%s</th><td>%s</td></tr>', esc_html( $k ), $v );
	}

	private function notice( $type, $msg ) {
		printf( '<div class="notice notice-%s"><p>%s</p></div>', esc_attr( $type ), esc_html( $msg ) );
	}

	/* =========================================================
	 * MU-PLUGIN RUNTIME AUDIT
	 * =======================================================*/

	private function render_mu_plugin_audit() {
		echo '<h2>Mu-plugin runtime audit (bizcity-facebook-bot)</h2>';
		echo '<p>Verifies the two PHASE 0.31 files are physically present, recently modified, AND actually loaded into the running PHP request.</p>';

		$files = array(
			'class-channel-adapter.php (T-S1.4)' => array(
				'path'  => WPMU_PLUGIN_DIR . '/bizcity-facebook-bot/includes/class-channel-adapter.php',
				'class' => 'BizCity_Facebook_Bot_Channel_Adapter',
			),
			'integration-facebook.php (T-S1.3)' => array(
				'path'  => WPMU_PLUGIN_DIR . '/bizcity-facebook-bot/includes/integration-facebook.php',
				'class' => 'WaicChannelIntegration_facebook',
			),
		);

		echo '<table class="widefat striped" style="max-width:1200px"><thead><tr>'
			. '<th>File</th><th>Exists</th><th>Modified</th>'
			. '<th>In get_included_files()</th><th>Class declared</th><th>Verdict</th>'
			. '</tr></thead><tbody>';
		$included = array_map( 'strtolower', array_map( function ( $p ) {
			return str_replace( '\\', '/', $p );
		}, get_included_files() ) );

		foreach ( $files as $label => $info ) {
			$path     = $info['path'];
			$exists   = is_readable( $path );
			$mtime    = $exists ? gmdate( 'Y-m-d H:i:s', filemtime( $path ) ) . ' UTC' : '—';
			$norm     = strtolower( str_replace( '\\', '/', $path ) );
			$loaded   = in_array( $norm, $included, true );
			$declared = class_exists( $info['class'], false );
			$ok       = $exists && $loaded && $declared;
			printf(
				'<tr><td>%s<br><code>%s</code></td><td>%s</td><td>%s</td><td>%s</td><td>%s (<code>%s</code>)</td><td>%s</td></tr>',
				esc_html( $label ),
				esc_html( $this->short_path( $path ) ),
				$this->yn( $exists ),
				esc_html( $mtime ),
				$this->yn( $loaded ),
				$this->yn( $declared ),
				esc_html( $info['class'] ),
				$this->badge( $ok ? 'PASS' : 'FAIL' )
			);
		}
		echo '</tbody></table>';

		// OPcache state — primary suspect when file exists+modified but NOT loaded.
		$op_enabled = function_exists( 'opcache_get_status' ) && @ini_get( 'opcache.enable' );
		$op_status  = $op_enabled ? @opcache_get_status( false ) : false;
		echo '<table class="widefat striped" style="max-width:1200px;margin-top:10px"><tbody>';
		$this->row( 'opcache.enable', ini_get( 'opcache.enable' ) ?: '0' );
		$this->row( 'opcache.validate_timestamps', ini_get( 'opcache.validate_timestamps' ) ?: '0' );
		$this->row( 'opcache.revalidate_freq', ini_get( 'opcache.revalidate_freq' ) ?: '0' );
		if ( is_array( $op_status ) ) {
			$this->row( 'opcache cached scripts', isset( $op_status['opcache_statistics']['num_cached_scripts'] ) ? (int) $op_status['opcache_statistics']['num_cached_scripts'] : 'n/a' );
			// Per-file cache check
			foreach ( $files as $label => $info ) {
				$cached = false;
				if ( isset( $op_status['scripts'] ) && is_array( $op_status['scripts'] ) ) {
					$norm = str_replace( '\\', '/', $info['path'] );
					foreach ( $op_status['scripts'] as $script_path => $_meta ) {
						if ( strtolower( str_replace( '\\', '/', $script_path ) ) === strtolower( $norm ) ) {
							$cached = $_meta;
							break;
						}
					}
				}
				$this->row(
					'opcache entry: ' . $label,
					$cached
						? sprintf( 'cached @ %s (timestamp %s)',
							esc_html( gmdate( 'H:i:s', $cached['last_used_timestamp'] ?? 0 ) ),
							esc_html( gmdate( 'Y-m-d H:i:s', $cached['timestamp'] ?? 0 ) )
						)
						: '<em>not in OPcache</em>'
				);
			}
		} else {
			$this->row( 'opcache_get_status()', 'unavailable' );
		}
		echo '</tbody></table>';

		// BizCity_Facebook_Bot_Plugin should be instantiated by mu-plugin bootstrap.
		echo '<table class="widefat striped" style="max-width:1200px;margin-top:10px"><tbody>';
		$this->row( 'class BizCity_Facebook_Bot_Plugin loaded', $this->yn( class_exists( 'BizCity_Facebook_Bot_Plugin', false ) ) );
		$this->row( 'class BizCity_Facebook_Bot_Database loaded', $this->yn( class_exists( 'BizCity_Facebook_Bot_Database', false ) ) );
		$this->row( 'WPMU_PLUGIN_DIR', WPMU_PLUGIN_DIR );
		echo '</tbody></table>';

		echo '<p><strong>If a file shows Exists=YES + In get_included_files()=NO,</strong> the most likely cause is OPcache holding a stale copy of <code>bootstrap.php</code> from before the include list was updated. Click <em>Reset OPcache</em> below and reload.</p>';

		// ---- bootstrap.php content audit (catches stale-deploy case) ----
		$bs_path = WPMU_PLUGIN_DIR . '/bizcity-facebook-bot/bootstrap.php';
		echo '<h3 style="margin-top:18px">bootstrap.php content audit</h3>';
		if ( ! is_readable( $bs_path ) ) {
			echo '<p style="color:#b32d2e">bootstrap.php NOT readable at <code>' . esc_html( $bs_path ) . '</code></p>';
		} else {
			$src      = (string) file_get_contents( $bs_path );
			$mtime    = gmdate( 'Y-m-d H:i:s', filemtime( $bs_path ) );
			$has_adapter = false !== strpos( $src, 'class-channel-adapter.php' );
			$has_integ   = false !== strpos( $src, 'integration-facebook.php' );
			echo '<table class="widefat striped" style="max-width:1200px"><tbody>';
			$this->row( 'bootstrap.php path', '<code>' . esc_html( $bs_path ) . '</code>' );
			$this->row( 'modified (UTC)', esc_html( $mtime ) );
			$this->row( 'size (bytes)', strlen( $src ) );
			$this->row( "contains 'class-channel-adapter.php'", $this->yn( $has_adapter ) );
			$this->row( "contains 'integration-facebook.php'", $this->yn( $has_integ ) );
			echo '</tbody></table>';
			if ( ! $has_adapter || ! $has_integ ) {
				echo '<div class="notice notice-error" style="margin-top:10px"><p><strong>STALE DEPLOY:</strong> the <code>$include_files</code> array in <code>bootstrap.php</code> on this server does not reference one or both new files. The two adapter/integration PHP files synced to disk, but the loader did not. Re-deploy <code>mu-plugins/bizcity-facebook-bot/bootstrap.php</code>.</p></div>';
			}
		}
	}

	private function action_reset_opcache() {
		if ( ! function_exists( 'opcache_reset' ) ) {
			$this->notice( 'error', 'opcache_reset() not available (OPcache disabled in php.ini).' );
			return;
		}
		$ok = @opcache_reset();
		if ( $ok ) {
			$this->notice( 'success', 'OPcache reset OK. Reload this page so the next request reads fresh PHP files.' );
		} else {
			$this->notice( 'error', 'opcache_reset() returned false (may be restricted by opcache.restrict_api).' );
		}
	}

	/* =========================================================
	 * T-S6.4 — FB data migration (UI replacement for WP-CLI)
	 * =======================================================*/

	/**
	 * Lazy-load the migration helper class. Returns true when ready.
	 */
	private function load_s6_4_helper(): bool {
		if ( class_exists( 'BizCity_Migrate_Bztfb' ) ) {
			return true;
		}
		$file = dirname( __DIR__ ) . '/tools/migrate-bztfb-to-channel-messages.php';
		if ( ! is_readable( $file ) ) {
			$this->notice( 'error', 'Migration helper not found: ' . esc_html( $file ) );
			return false;
		}
		require_once $file;
		return class_exists( 'BizCity_Migrate_Bztfb' );
	}

	private function render_s6_4_migration_panel() {
		echo '<h2 style="margin-top:30px">T-S6.4 — FB legacy data migration (<code>wp_bztfb_*</code> → <code>wp_bizcity_channel_messages</code>)</h2>';
		echo '<p>Use these buttons instead of the WP-CLI command <code>wp bizcity migrate-bztfb</code>. Steps: <strong>(1)</strong> Inspect → <strong>(2)</strong> Dry-run → <strong>(3)</strong> Confirm + Migrate.</p>';

		// Live state snapshot.
		if ( $this->load_s6_4_helper() ) {
			$snap = BizCity_Migrate_Bztfb::inspect();
			echo '<table class="widefat striped" style="max-width:960px"><tbody>';
			$srcs = array();
			foreach ( (array) ( $snap['source_tables'] ?? array() ) as $t ) {
				$srcs[] = sprintf( '<code>%s</code> (%d rows, %d cols)', esc_html( $t['name'] ), (int) $t['rows'], count( (array) ( $t['columns'] ?? array() ) ) );
			}
			$this->row( 'Source tables found', $srcs ? implode( '<br>', $srcs ) : '<em>none — nothing to migrate</em>' );
			$this->row( 'Destination table', $snap['destination_table'] ? '<code>' . esc_html( $snap['destination_table'] ) . '</code> (exists: ' . $this->yn( ! empty( $snap['destination_exists'] ) ) . ', current rows: ' . (int) ( $snap['destination_rows'] ?? 0 ) . ')' : '<em>BizCity_Channel_Messages not loaded</em>' );
			$this->row( 'BIZCITY_ALLOW_T_S6_4_MIGRATE constant', defined( 'BIZCITY_ALLOW_T_S6_4_MIGRATE' ) ? 'defined (CLI safety unlocked)' : '<em>not defined</em> — UI confirm phrase will substitute' );
			echo '</tbody></table>';
		}

		echo '<div style="margin-top:14px">';

		// Step 1: inspect.
		echo '<form method="post" style="display:inline-block;margin-right:10px">';
		wp_nonce_field( 'bizcity_cg_sprint_diag' );
		echo '<input type="hidden" name="bizcity_action" value="s6_4_inspect">';
		echo '<button class="button">1) Inspect (read-only)</button>';
		echo '</form>';

		// Step 2: dry-run.
		echo '<form method="post" style="display:inline-block;margin-right:10px">';
		wp_nonce_field( 'bizcity_cg_sprint_diag' );
		echo '<input type="hidden" name="bizcity_action" value="s6_4_dryrun">';
		echo '<input type="number" name="s6_4_limit" value="50" min="1" max="5000" style="width:90px" title="limit">';
		echo '<input type="number" name="s6_4_offset" value="0" min="0" style="width:90px" title="offset">';
		echo '<button class="button">2) Dry-run (no writes)</button>';
		echo '</form>';

		// Step 3: real migrate. Requires typed confirm phrase "MIGRATE".
		echo '<form method="post" style="display:inline-block" onsubmit="return (this.s6_4_confirm.value===\'MIGRATE\') || (alert(\'Type MIGRATE to confirm\'),false);">';
		wp_nonce_field( 'bizcity_cg_sprint_diag' );
		echo '<input type="hidden" name="bizcity_action" value="s6_4_migrate">';
		echo '<input type="number" name="s6_4_limit" value="500" min="1" max="10000" style="width:90px" title="limit">';
		echo '<input type="number" name="s6_4_offset" value="0" min="0" style="width:90px" title="offset">';
		echo '<input type="text" name="s6_4_confirm" placeholder="Type MIGRATE" style="width:140px" autocomplete="off">';
		echo '<button class="button button-primary" style="background:#b32d2e;border-color:#8c1d1c">3) MIGRATE (writes rows)</button>';
		echo '</form>';

		echo '</div>';
		echo '<p><small>After successful migration + manual verification, drop legacy tables (<code>DROP TABLE wp_bztfb_*</code>) and remove <code>plugins/bizcity-twin-ai/plugins/bizcity-tool-facebook/</code>. The button never deletes — only writes into the unified inbox.</small></p>';
	}

	private function action_s6_4_inspect() {
		if ( ! $this->load_s6_4_helper() ) { return; }
		$report = BizCity_Migrate_Bztfb::inspect();
		$this->notice( 'success', 'Inspect OK — see snapshot below.' );
		echo '<pre style="background:#f6f7f7;padding:10px;max-width:1200px;overflow:auto">' . esc_html( wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ) . '</pre>';
	}

	private function action_s6_4_dryrun() {
		if ( ! $this->load_s6_4_helper() ) { return; }
		$limit  = isset( $_POST['s6_4_limit'] ) ? max( 1, (int) $_POST['s6_4_limit'] ) : 50;
		$offset = isset( $_POST['s6_4_offset'] ) ? max( 0, (int) $_POST['s6_4_offset'] ) : 0;
		$report = BizCity_Migrate_Bztfb::execute( array( 'confirm' => false, 'limit' => $limit, 'offset' => $offset ) );
		$class  = ! empty( $report['ok'] ) ? 'success' : 'error';
		$this->notice( $class, sprintf( 'Dry-run done (limit=%d offset=%d) — would translate %d row(s); 0 written.', $limit, $offset, (int) ( $report['skipped_or_dup'] ?? 0 ) ) );
		echo '<pre style="background:#f6f7f7;padding:10px;max-width:1200px;overflow:auto">' . esc_html( wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ) . '</pre>';
	}

	private function action_s6_4_migrate() {
		if ( ! $this->load_s6_4_helper() ) { return; }
		$confirm = isset( $_POST['s6_4_confirm'] ) ? sanitize_text_field( wp_unslash( $_POST['s6_4_confirm'] ) ) : '';
		if ( 'MIGRATE' !== $confirm ) {
			$this->notice( 'error', 'Confirm phrase missing — type MIGRATE in the field next to the red button.' );
			return;
		}
		// UI confirm phrase + admin nonce + manage_options cap = sufficient gate.
		// Substitute for the wp-config constant the WP-CLI path requires.
		if ( ! defined( 'BIZCITY_ALLOW_T_S6_4_MIGRATE' ) ) {
			define( 'BIZCITY_ALLOW_T_S6_4_MIGRATE', true );
		}
		$limit  = isset( $_POST['s6_4_limit'] ) ? max( 1, (int) $_POST['s6_4_limit'] ) : 500;
		$offset = isset( $_POST['s6_4_offset'] ) ? max( 0, (int) $_POST['s6_4_offset'] ) : 0;
		$report = BizCity_Migrate_Bztfb::execute( array( 'confirm' => true, 'limit' => $limit, 'offset' => $offset ) );
		$class  = ! empty( $report['ok'] ) ? 'success' : 'error';
		$this->notice( $class, sprintf( 'Migrate done (limit=%d offset=%d) — inserted=%d, skipped/dup=%d. Next offset to resume: %d.', $limit, $offset, (int) ( $report['inserted'] ?? 0 ), (int) ( $report['skipped_or_dup'] ?? 0 ), (int) ( $report['next_offset'] ?? 0 ) ) );
		echo '<pre style="background:#f6f7f7;padding:10px;max-width:1200px;overflow:auto">' . esc_html( wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ) . '</pre>';
	}

	/* =========================================================
	 * SPRINT 7 — PHASE 0.33 M1 TASK CHECKS
	 * Webhook Router + daily-partition log + Guru bindings
	 * =======================================================*/

	private function check_t_s7_1() {
		$loaded = class_exists( 'BizCity_Webhook_Router' );
		global $wp_rewrite;
		$rules     = is_object( $wp_rewrite ) ? (array) $wp_rewrite->wp_rewrite_rules() : array();
		$canonical = false;
		foreach ( $rules as $pattern => $_ ) {
			if ( strpos( $pattern, 'biz/hook' ) !== false ) { $canonical = true; break; }
		}
		$qv_ok = false;
		if ( $loaded ) {
			$qvs = (array) apply_filters( 'query_vars', array() );
			$qv_ok = in_array( BizCity_Webhook_Router::QUERY_VAR, $qvs, true );
		}
		$intake_attached = isset( $GLOBALS['wp_filter']['parse_request'] );
		$ok = $loaded && $canonical && $intake_attached;
		$this->task_row(
			'T-S7.1',
			$ok ? 'PASS' : 'FAIL',
			'Webhook Router class loaded + canonical rewrite <code>^biz/hook/{platform}/?$</code> registered',
			sprintf(
				'class loaded: %s | canonical rewrite present: %s | query_var <code>%s</code> registered: %s | parse_request hook present: %s',
				$this->yn( $loaded ),
				$this->yn( $canonical ),
				esc_html( BizCity_Webhook_Router::QUERY_VAR ),
				$this->yn( $qv_ok ),
				$this->yn( $intake_attached )
			)
		);
	}

	private function check_t_s7_2() {
		// Legacy aliases must be detected by the Router (not requiring rewrite for ?fbhook=1).
		if ( ! class_exists( 'BizCity_Webhook_Router' ) ) {
			$this->task_row( 'T-S7.2', 'FAIL', 'Legacy URL aliases routed through Router', 'Router class not loaded' );
			return;
		}
		$legacy = BizCity_Webhook_Router::legacy_map();
		$tests  = array(
			'/bizfbhook/'    => 'FB_MESS',
			'/zalohook/'     => 'ZALO_BOT',
			'/bizhook/'      => 'ZALO_HOTLINE',
			'/webchat-hook/' => 'WEBCHAT',
		);
		$missing = array();
		foreach ( $tests as $path => $expected ) {
			$matched = false;
			foreach ( $legacy as $regex => $platform ) {
				if ( preg_match( $regex, $path ) && $platform === $expected ) { $matched = true; break; }
			}
			if ( ! $matched ) {
				$missing[] = $path;
			}
		}
		$ok = empty( $missing );
		$this->task_row(
			'T-S7.2',
			$ok ? 'PASS' : 'FAIL',
			'Legacy aliases (/bizfbhook/, /zalohook/, /bizhook/, /webchat-hook/) detected by Router',
			$ok
				? 'all 4 aliases match a legacy_map regex'
				: 'missing: <code>' . esc_html( implode( ', ', $missing ) ) . '</code>'
		);
	}

	private function check_t_s7_3() {
		if ( ! class_exists( 'BizCity_Webhook_Log' ) ) {
			$this->task_row( 'T-S7.3', 'FAIL', "Today's hook-logs/{Y_m_d}/ directory writeable", 'class not loaded' );
			return;
		}
		// Smoke write + delete to ensure dir + counter work.
		$res = BizCity_Webhook_Log::log( array(
			'platform'      => 'DIAG',
			'endpoint'      => '__diag__',
			'method'        => 'GET',
			'verify_status' => 'skipped',
		) );
		$ok   = ! empty( $res['id'] );
		$path = $ok ? BizCity_Webhook_Log::file_for( $res['date'], (int) $res['id'] ) : '';
		if ( $ok && is_file( $path ) ) {
			@unlink( $path );
		}
		$today_dir = BizCity_Webhook_Log::today_dir();
		$this->task_row(
			'T-S7.3',
			$ok ? 'PASS' : 'FAIL',
			"Today's <code>wp-content/hook-logs/{Y_m_d}/</code> directory writeable (file-based ledger)",
			sprintf( 'today dir: <code>%s</code> | smoke write id: %d | exists: %s',
				esc_html( str_replace( ABSPATH, '', $today_dir ) ),
				(int) ( $res['id'] ?? 0 ),
				$this->yn( is_dir( $today_dir ) )
			)
		);
	}

	private function check_t_s7_4() {
		$next     = wp_next_scheduled( BizCity_Webhook_Log::CRON_HOOK );
		$attached = isset( $GLOBALS['wp_filter'][ BizCity_Webhook_Log::CRON_HOOK ] );
		$parts    = BizCity_Webhook_Log::list_partitions();
		$ok       = $next && $attached;
		$this->task_row(
			'T-S7.4',
			$ok ? 'PASS' : 'FAIL',
			'Prune cron <code>bizcity_webhook_log_prune</code> scheduled (TTL ' . BizCity_Webhook_Log::TTL_DAYS . ' days, drops day-dirs)',
			sprintf( 'next run: %s | callback attached: %s | active day-dirs: %d (%s)',
				$next ? esc_html( wp_date( 'Y-m-d H:i', (int) $next ) ) : '<em>not scheduled</em>',
				$this->yn( $attached ),
				count( $parts ),
				esc_html( implode( ', ', array_slice( $parts, 0, 5 ) ) )
			)
		);
	}

	private function check_t_s7_5() {
		if ( ! class_exists( 'BizCity_Channel_Messages' ) ) {
			$this->task_row( 'T-S7.5', 'FAIL', 'channel_messages schema bumped to 1.1.0 + bindings table installed', 'BizCity_Channel_Messages missing' );
			return;
		}
		global $wpdb;
		$ver_msg     = (string) get_option( BizCity_Channel_Messages::OPTION_VERSION, '' );
		$msg_table   = BizCity_Channel_Messages::table();
		$cols        = (array) $wpdb->get_col( "SHOW COLUMNS FROM `{$msg_table}`" );
		$has_log_id  = in_array( 'webhook_log_id', $cols, true );
		$has_log_d   = in_array( 'webhook_log_date', $cols, true );
		$has_char    = in_array( 'character_id', $cols, true );

		$bind_ok = false;
		$bind_count = 0;
		if ( class_exists( 'BizCity_Channel_Binding' ) ) {
			$bind_table = BizCity_Channel_Binding::table();
			$bind_ok    = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $bind_table ) );
			if ( $bind_ok ) {
				$bind_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$bind_table}`" );
			}
		}
		$ok = version_compare( $ver_msg, '1.1.0', '>=' ) && $has_log_id && $has_log_d && $has_char && $bind_ok;
		$this->task_row(
			'T-S7.5',
			$ok ? 'PASS' : 'FAIL',
			'channel_messages schema ≥ 1.1.0 (webhook_log_id/date + character_id) + channel_bindings table installed',
			sprintf(
				'channel_messages version: <code>%s</code> | webhook_log_id=%s, webhook_log_date=%s, character_id=%s | bindings table exists=%s, rows=%d',
				esc_html( $ver_msg ),
				$this->yn( $has_log_id ),
				$this->yn( $has_log_d ),
				$this->yn( $has_char ),
				$this->yn( $bind_ok ),
				$bind_count
			)
		);
	}

	private function check_t_s7_6() {
		$file_path  = dirname( __FILE__ ) . '/class-universal-channel-listener.php';
		$file_ok    = file_exists( $file_path );
		$file_size  = $file_ok ? (int) filesize( $file_path ) : 0;
		$file_mtime = $file_ok ? gmdate( 'Y-m-d H:i:s', (int) filemtime( $file_path ) ) : '—';

		// Defensive lazy-load: if the file exists but the class hasn't been pulled in
		// (e.g. OPcache cached an older bootstrap.php that lacked the require_once),
		// require it now so the rest of this check + T-S7.7 + T-S7.8 reflect reality.
		if ( $file_ok && ! class_exists( 'BizCity_Universal_Channel_Listener', false ) ) {
			require_once $file_path;
			if ( class_exists( 'BizCity_Universal_Channel_Listener' )
				&& method_exists( 'BizCity_Universal_Channel_Listener', 'init' ) ) {
				BizCity_Universal_Channel_Listener::init();
			}
		}

		$loaded   = class_exists( 'BizCity_Universal_Channel_Listener' );
		$attached = isset( $GLOBALS['wp_filter']['waic_twf_process_flow'] );
		$priority_ok = false;
		if ( $attached ) {
			$callbacks = $GLOBALS['wp_filter']['waic_twf_process_flow']->callbacks ?? array();
			$bucket    = isset( $callbacks[5] ) ? (array) $callbacks[5] : array();
			foreach ( $bucket as $cb ) {
				$fn = $cb['function'] ?? null;
				if ( is_array( $fn ) && isset( $fn[1] ) && $fn[1] === 'on_trigger' ) {
					$priority_ok = true;
					break;
				}
			}
		}
		$keys = $loaded ? BizCity_Universal_Channel_Listener::trigger_keys() : array();
		$this->task_row(
			'T-S7.6',
			( $loaded && $priority_ok ) ? 'PASS' : 'FAIL',
			'Universal Channel Listener tap waic_twf_process_flow @ priority 5',
			sprintf(
				'class loaded: %s | hook attached: %s | on_trigger@5 found: %s | watching keys: <code>%s</code><br>file: <code>%s</code> | exists=%s, size=%d bytes, mtime=%s UTC',
				$this->yn( $loaded ),
				$this->yn( $attached ),
				$this->yn( $priority_ok ),
				esc_html( implode( ', ', $keys ) ),
				esc_html( $file_path ),
				$this->yn( $file_ok ),
				$file_size,
				esc_html( $file_mtime )
			)
		);
	}

	private function check_t_s7_7() {
		// Smoke fire — does an inbound trigger produce a channel_messages row + patch webhook_log?
		if ( ! class_exists( 'BizCity_Universal_Channel_Listener' ) || ! class_exists( 'BizCity_Channel_Messages' ) ) {
			$this->task_row( 'T-S7.7', 'FAIL', 'Listener smoke probe — fire fixture and verify channel_messages row', 'classes missing' );
			return;
		}
		global $wpdb;
		$msg_table = BizCity_Channel_Messages::table();
		$mid       = 'diag_' . wp_generate_password( 12, false, false );
		$payload   = array(
			'page_id'   => '__diag_page__',
			'user_id'   => '__diag_user__',
			'message'   => 'diag smoke ' . $mid,
			'mid'       => $mid,
			'timestamp' => time(),
		);
		do_action( 'waic_twf_process_flow', 'bizcity_facebook_message_received', $payload );

		$row_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$msg_table} WHERE platform=%s AND message_id=%s LIMIT 1",
			'FB_MESS', $mid
		) );
		$ok = $row_id > 0;
		if ( $row_id ) {
			$wpdb->delete( $msg_table, array( 'id' => $row_id ) );
		}
		$this->task_row(
			'T-S7.7',
			$ok ? 'PASS' : 'FAIL',
			'Listener smoke probe: fixture FB trigger creates channel_messages inbound row',
			sprintf( 'fired bizcity_facebook_message_received with mid=<code>%s</code> → row id=%d (cleaned up)',
				esc_html( $mid ),
				$row_id
			)
		);
	}

	private function check_t_s7_8() {
		// Confirm Zalo Bot direct action is bridged into waic_twf_process_flow.
		$bridged = false;
		if ( isset( $GLOBALS['wp_filter']['bizcity_zalo_message_received'] ) ) {
			$callbacks = $GLOBALS['wp_filter']['bizcity_zalo_message_received']->callbacks ?? array();
			$bucket    = isset( $callbacks[5] ) ? (array) $callbacks[5] : array();
			foreach ( $bucket as $cb ) {
				$fn = $cb['function'] ?? null;
				if ( is_array( $fn ) && isset( $fn[1] ) && $fn[1] === 'bridge_zalo' ) {
					$bridged = true;
					break;
				}
			}
		}
		$this->task_row(
			'T-S7.8',
			$bridged ? 'PASS' : 'FAIL',
			'Zalo Bot direct action <code>bizcity_zalo_message_received</code> bridged → <code>waic_twf_process_flow</code>',
			$bridged
				? 'bridge_zalo() attached at priority 5 — Zalo OA messages now flow through universal listener'
				: 'BUG-B not yet fixed — Zalo Bot inbound bypasses CRM and Automation listeners'
		);
	}

	private function check_t_s7_9() {
		$file_path  = dirname( __FILE__ ) . '/class-webhook-inspector.php';
		$file_ok    = file_exists( $file_path );
		$file_size  = $file_ok ? (int) filesize( $file_path ) : 0;
		$file_mtime = $file_ok ? gmdate( 'Y-m-d H:i:s', (int) filemtime( $file_path ) ) : '—';

		if ( $file_ok && ! class_exists( 'BizCity_Webhook_Inspector', false ) ) {
			require_once $file_path;
			if ( class_exists( 'BizCity_Webhook_Inspector' ) && method_exists( 'BizCity_Webhook_Inspector', 'init' ) ) {
				BizCity_Webhook_Inspector::init();
			}
		}

		$loaded   = class_exists( 'BizCity_Webhook_Inspector' );
		$slug     = $loaded && defined( 'BizCity_Webhook_Inspector::SLUG' ) ? BizCity_Webhook_Inspector::SLUG : 'bizcity-crm-webhook';
		global $admin_page_hooks;
		$crm_parent = isset( $admin_page_hooks['bizcity-crm'] );
		$page_url   = admin_url( ( $crm_parent ? 'admin.php' : 'tools.php' ) . '?page=' . $slug );
		$this->task_row(
			'T-S7.9',
			$loaded ? 'PASS' : 'FAIL',
			$crm_parent
				? 'Webhook Inspector class loaded + submenu registered (BizCity CRM → Webhook Inspector)'
				: 'Webhook Inspector class loaded + fallback registered (Tools → Webhook Inspector — CRM plugin not active)',
			sprintf(
				'class loaded: %s | page: <a href="%s" target="_blank">open</a><br>file: <code>%s</code> | exists=%s, size=%d bytes, mtime=%s UTC',
				$this->yn( $loaded ),
				esc_url( $page_url ),
				esc_html( $file_path ),
				$this->yn( $file_ok ),
				$file_size,
				esc_html( $file_mtime )
			)
		);
	}

	private function check_t_s7_10() {
		$server = rest_get_server();
		$routes = $server ? $server->get_routes() : array();
		$want = array(
			'/bizcity-channel/v1/inspector/logs',
			'/bizcity-channel/v1/inspector/log/(?P<date>\\d{4}_\\d{2}_\\d{2})/(?P<id>\\d+)',
			'/bizcity-channel/v1/inspector/bindings',
			'/bizcity-channel/v1/inspector/bindings/(?P<id>\\d+)/disable',
			'/bizcity-channel/v1/inspector/stats',
			'/bizcity-channel/v1/inspector/gurus',
			'/bizcity-channel/v1/inspector/channels',
			'/bizcity-channel/v1/inbox/send',
			'/bizcity-channel/v1/inbox/note',
			'/bizcity-channel/v1/inspector/replay/(?P<date>\d{4}_\d{2}_\d{2})/(?P<id>\d+)',
		);
		$found = array();
		$miss  = array();
		foreach ( $want as $r ) {
			if ( isset( $routes[ $r ] ) ) {
				$found[] = $r;
			} else {
				$miss[] = $r;
			}
		}
		$ok = empty( $miss );
		$this->task_row(
			'T-S7.10',
			$ok ? 'PASS' : 'FAIL',
			'Inspector REST routes registered under <code>bizcity-channel/v1</code> (10 endpoints incl. /gurus, /channels, /inbox/send, /inbox/note, /inspector/replay)',
			sprintf(
				'found %d/%d routes%s',
				count( $found ),
				count( $want ),
				$miss ? '<br>missing: <code>' . esc_html( implode( ', ', $miss ) ) . '</code>' : ''
			)
		);
	}

	/**
	 * T-S7.11 — PHASE 0.34 trace manifesto:
	 *   • bindings table has columns: mode, responder_pool_json, current_pool_index
	 *   • messages table has columns: responder_kind, responder_user_id
	 *   • BizCity_Channel_Binding::pick_responder() exists
	 */
	private function check_t_s7_11() {
		global $wpdb;
		$bind_tbl = class_exists( 'BizCity_Channel_Binding' )  ? BizCity_Channel_Binding::table()  : '';
		$msg_tbl  = class_exists( 'BizCity_Channel_Messages' ) ? BizCity_Channel_Messages::table() : '';

		// Force migration if needed.
		if ( class_exists( 'BizCity_Channel_Binding' ) )  { BizCity_Channel_Binding::maybe_install(); }
		if ( class_exists( 'BizCity_Channel_Messages' ) ) { BizCity_Channel_Messages::maybe_install(); }

		$bind_cols = $bind_tbl ? array_column( (array) $wpdb->get_results( "SHOW COLUMNS FROM {$bind_tbl}", ARRAY_A ), 'Field' ) : array();
		$msg_cols  = $msg_tbl  ? array_column( (array) $wpdb->get_results( "SHOW COLUMNS FROM {$msg_tbl}",  ARRAY_A ), 'Field' ) : array();

		$want_bind = array( 'mode', 'responder_pool_json', 'current_pool_index' );
		$want_msg  = array( 'responder_kind', 'responder_user_id' );

		$miss_bind = array_diff( $want_bind, $bind_cols );
		$miss_msg  = array_diff( $want_msg,  $msg_cols );

		$has_picker = method_exists( 'BizCity_Channel_Binding', 'pick_responder' );
		$ok = empty( $miss_bind ) && empty( $miss_msg ) && $has_picker;

		$evidence = sprintf(
			'binding cols: %s | messages cols: %s | pick_responder(): %s',
			$miss_bind ? '<span style="color:#b32d2e">missing ' . esc_html( implode( ',', $miss_bind ) ) . '</span>' : '<span style="color:#00712d">OK</span>',
			$miss_msg  ? '<span style="color:#b32d2e">missing ' . esc_html( implode( ',', $miss_msg  ) ) . '</span>' : '<span style="color:#00712d">OK</span>',
			$has_picker ? 'yes' : '<span style="color:#b32d2e">NO</span>'
		);

		$this->task_row(
			'T-S7.11',
			$ok ? 'PASS' : 'FAIL',
			'Trace manifesto schema (PHASE 0.34): binding.mode + messages.responder_kind/user_id + pick_responder()',
			$evidence
		);
	}

	/**
	 * T-S7.12 — Stamper runtime + outbound_logged hook + smoke write.
	 *   • BizCity_Responder_Stamper class loaded
	 *   • on_outbound_logged hook attached to bizcity_channel_outbound_logged
	 *   • Stamper push/pop works and a fixture record_outbound() returns id > 0
	 */
	private function check_t_s7_12() {
		$class_ok  = class_exists( 'BizCity_Responder_Stamper' );
		$hook_ok   = $class_ok && (bool) has_action( 'bizcity_channel_outbound_logged', array( 'BizCity_Responder_Stamper', 'on_outbound_logged' ) );
		$smoke_id  = 0;
		$smoke_err = '';
		if ( $class_ok && class_exists( 'BizCity_Channel_Messages' ) ) {
			BizCity_Responder_Stamper::push( array(
				'kind'         => 'auto',
				'character_id' => 0,
				'user_id'      => null,
				'mode'         => 'auto',
				'source'       => 'diag',
			) );
			$smoke_id = BizCity_Responder_Stamper::record_outbound( array(
				'platform'   => 'DIAG',
				'chat_id'    => 'diag_' . wp_generate_password( 6, false, false ),
				'message_id' => 'diag_' . wp_generate_password( 8, false, false ),
				'body'       => 'T-S7.12 stamper smoke',
				'status'     => 'sent',
			) );
			BizCity_Responder_Stamper::pop();
			// Cleanup fixture row.
			if ( $smoke_id ) {
				global $wpdb;
				$wpdb->delete( BizCity_Channel_Messages::table(), array( 'id' => $smoke_id ) );
			}
		} else {
			$smoke_err = 'Stamper or Channel_Messages class missing';
		}
		$ok = $class_ok && $hook_ok && $smoke_id > 0;
		$this->task_row(
			'T-S7.12',
			$ok ? 'PASS' : 'FAIL',
			'Responder Stamper wired (outbound auto-logged with responder_kind/user_id/character_id)',
			sprintf(
				'class loaded: %s | hook attached: %s | smoke insert id: %d %s',
				$this->yn( $class_ok ),
				$this->yn( $hook_ok ),
				$smoke_id,
				$smoke_err ? '<span style="color:#b32d2e">— ' . esc_html( $smoke_err ) . '</span>' : ''
			)
		);
	}

	/**
	 * T-S7.13 — Webhook Replay endpoint (PHASE 0.34 M5):
	 *   • BizCity_Webhook_Replay class loaded.
	 *   • REST route /inspector/replay/{date}/{id} registered.
	 *   • Smoke: log a fixture row → replay → assert child row created with is_replay=1.
	 */
	private function check_t_s7_13() {
		$class_ok = class_exists( 'BizCity_Webhook_Replay' );

		$server  = rest_get_server();
		$routes  = $server ? $server->get_routes() : array();
		$route   = '/bizcity-channel/v1/inspector/replay/(?P<date>\\d{4}_\\d{2}_\\d{2})/(?P<id>\\d+)';
		$route_ok = isset( $routes[ $route ] );

		$smoke_ok  = false;
		$smoke_err = '';
		$replay_id = 0;
		$parent_id = 0;

		if ( $class_ok && class_exists( 'BizCity_Webhook_Log' ) ) {
			// Seed a fixture original row.
			$seed = BizCity_Webhook_Log::log( array(
				'platform'      => 'DIAG',
				'endpoint'      => '/diag/replay/seed',
				'method'        => 'POST',
				'http_status'   => 200,
				'verify_status' => 'verified',
				'remote_ip'     => '127.0.0.1',
				'user_agent'    => 'BizCity-Diag/T-S7.13',
				'headers'       => array( 'x-diag' => 'replay-seed' ),
				'body_raw'      => '{"diag":"T-S7.13","ts":' . time() . '}',
			) );
			$parent_id = (int) ( $seed['id'] ?? 0 );

			if ( $parent_id > 0 ) {
				$row = BizCity_Webhook_Log::find( $seed['date'], $parent_id );
				if ( is_array( $row ) ) {
					$result = BizCity_Webhook_Replay::replay_row( $row );
					if ( ! empty( $result['ok'] ) && ! empty( $result['replay']['id'] ) ) {
						$replay_id = (int) $result['replay']['id'];
						$child = BizCity_Webhook_Log::find( $result['replay']['date'], $replay_id );
						$smoke_ok = is_array( $child )
							&& ! empty( $child['is_replay'] )
							&& (int) ( $child['parent_log_id'] ?? 0 ) === $parent_id;
						if ( ! $smoke_ok ) {
							$smoke_err = 'replay row missing is_replay/parent linkage';
						}
						// Cleanup replay row.
						$rep_path = BizCity_Webhook_Log::file_for( (string) $result['replay']['date'], $replay_id );
						if ( is_file( $rep_path ) ) { @unlink( $rep_path ); }
					} else {
						$smoke_err = isset( $result['message'] ) ? (string) $result['message'] : 'replay returned !ok';
					}
				} else {
					$smoke_err = 'seed row not readable';
				}
				// Cleanup parent fixture row.
				$par_path = BizCity_Webhook_Log::file_for( (string) $seed['date'], $parent_id );
				if ( is_file( $par_path ) ) { @unlink( $par_path ); }
			} else {
				$smoke_err = 'failed to seed fixture row';
			}
		} else {
			$smoke_err = 'Replay or Webhook_Log class missing';
		}

		$ok = $class_ok && $route_ok && $smoke_ok;
		$this->task_row(
			'T-S7.13',
			$ok ? 'PASS' : 'FAIL',
			'Webhook Replay endpoint (PHASE 0.34 M5): re-fires intake + emits <code>bizcity_channel_replay</code> with parent linkage',
			sprintf(
				'class: %s | route: %s | smoke parent#%d → replay#%d %s',
				$this->yn( $class_ok ),
				$this->yn( $route_ok ),
				$parent_id,
				$replay_id,
				$smoke_err ? '<span style="color:#b32d2e">— ' . esc_html( $smoke_err ) . '</span>' : '<span style="color:#00712d">OK</span>'
			)
		);
	}
}

BizCity_Channel_Gateway_Sprint_Diagnostic::instance();
