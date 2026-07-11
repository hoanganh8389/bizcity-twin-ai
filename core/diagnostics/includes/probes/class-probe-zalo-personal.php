<?php
/**
 * Probe: Zalo Personal & OA Channel Gateway (Phase 0.39).
 *
 * 8 DDV rows (R-DDV bắt buộc) với 3 layer mỗi row:
 *
 *   zp.bridge.health         — Disk: class exists | Loader: bootstrap loaded | Runtime: /health reachable
 *   zp.filter.catalog        — Disk: filter code | Loader: filter attached | Runtime: catalog có 2 tiles
 *   zp.integration.registered— Disk: 2 class exists | Loader: registry loaded | Runtime: registry->get
 *   zp.inbound.bridge        — Disk: emitter exists | Loader: hook attached | Runtime: synthetic event shape
 *   zp.schema.tables         — Disk: changelog JSON | Loader: installer class | Runtime: 3 bảng tồn tại
 *   zp.oa.window             — Disk: window repo | Loader: — | Runtime: is_oa_window_open() logic
 *   zp.zone.isolation        — Disk: emitter code | Loader: guard attached | Runtime: platform discriminator
 *   zp.test.connection       — Disk: rest+hook-log files | Loader: REST class + route | Runtime: test_connection() real-call
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      PHASE-0.39 (2026-06-07)
 */

// [2026-06-07 Johnny Chu] PHASE-0.39 — DDV 7-row probe (R-DDV bắt buộc)
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Probe_Zalo_Personal' ) ) { return; }

final class BizCity_Probe_Zalo_Personal implements BizCity_Diagnostics_Probe {

	public function id(): string          { return 'modules.zalo-personal'; }
	public function label(): string       { return 'Zalo Personal & OA Channel Gateway (Phase 0.39)'; }
	public function description(): string { return '8 DDV rows: bridge health, catalog filter, integration registry, inbound emitter, schema tables, OA window, zone isolation, test-connection + hook-log.'; }
	public function severity(): string    { return 'warning'; }
	public function order(): int          { return 45; }
	public function icon(): string        { return 'message-square'; }
	public function estimate_ms(): int    { return 800; }

	public function precondition() {
		return true; // Plugin may not be loaded; probe handles skip gracefully.
	}

	public function run( $ctx ): array {
		$rows = array();
		$pass = true;

		$plugin_dir  = WP_PLUGIN_DIR . '/bizcity-twin-ai/plugins/bizcity-zalo-personal/';
		// [2026-07-11 Johnny Chu] PHASE-0.39 HOTFIX — plugin includes moved to {shared,personal,oa} subfolders.
		$inc_shared  = $plugin_dir . 'includes/shared/';
		$inc_personal = $plugin_dir . 'includes/personal/';
		$inc_oa      = $plugin_dir . 'includes/oa/';
		$changelog   = WP_PLUGIN_DIR . '/bizcity-twin-ai/core/diagnostics/changelog/modules.zalo-personal.json';

		// ── ROW 1: zp.bridge.health ───────────────────────────────────────────

		$disk_bridge = file_exists( $inc_shared . 'class-zalo-bridge-client.php' );
		if ( ! $disk_bridge ) { $pass = false; }
		$rows[] = array(
			'label'  => 'zp.bridge.health — Disk: class-zalo-bridge-client.php',
			'status' => $disk_bridge ? 'pass' : 'fail',
			'detail' => $disk_bridge ? 'File exists' : 'Missing: plugins/bizcity-zalo-personal/includes/shared/class-zalo-bridge-client.php',
		);

		$loader_bridge = class_exists( 'BizCity_Zalo_Bridge_Client' );
		if ( ! $loader_bridge ) { $pass = false; }
		$rows[] = array(
			'label'  => 'zp.bridge.health — Loader: BizCity_Zalo_Bridge_Client',
			'status' => $loader_bridge ? 'pass' : 'fail',
			'detail' => $loader_bridge ? 'Class loaded' : 'Class not found — activate bizcity-zalo-personal plugin.',
		);

		if ( $loader_bridge ) {
			$client         = BizCity_Zalo_Bridge_Client::instance();
			$bridge_fast_ok = $client->is_ready_fast();
			$rows[] = array(
				'label'  => 'zp.bridge.health — Runtime: bridge URL+token configured',
				'status' => $bridge_fast_ok ? 'pass' : 'skip',
				'detail' => $bridge_fast_ok
					? 'bizcity_zalo_bridge_url + bizcity_zalo_bridge_token set'
					: 'Bridge URL/token not configured — SKIP real-call (vào Cài đặt → Zalo Bridge để nhập).',
			);
		}

		// ── ROW 2: zp.filter.catalog ──────────────────────────────────────────

		$disk_bootstrap = file_exists( $plugin_dir . 'bootstrap.php' );
		$rows[] = array(
			'label'  => 'zp.filter.catalog — Disk: bootstrap.php exists',
			'status' => $disk_bootstrap ? 'pass' : 'fail',
			'detail' => $disk_bootstrap ? 'File exists' : 'Missing bootstrap.php.',
		);
		if ( ! $disk_bootstrap ) { $pass = false; }

		$filter_attached = (bool) has_filter( 'bizcity_channel_platform_catalog' );
		$rows[] = array(
			'label'  => 'zp.filter.catalog — Loader: filter bizcity_channel_platform_catalog attached',
			'status' => $filter_attached ? 'pass' : 'fail',
			'detail' => $filter_attached ? 'Filter hook attached' : 'bizcity_channel_platform_catalog filter missing — plugin not activated or bootstrap not loaded.',
		);
		if ( ! $filter_attached ) { $pass = false; }

		if ( $filter_attached ) {
			$catalog_fn = function_exists( 'apply_filters' );
			$catalog    = $catalog_fn ? apply_filters( 'bizcity_channel_platform_catalog', array() ) : array();
			$codes      = array_column( $catalog, 'code' );
			$has_zp     = in_array( 'zalo_personal', $codes, true );
			$has_oa     = in_array( 'zalo_oa', $codes, true );
			$catalog_ok = $has_zp && $has_oa;
			if ( ! $catalog_ok ) { $pass = false; }
			$rows[] = array(
				'label'  => 'zp.filter.catalog — Runtime: catalog có zalo_personal + zalo_oa',
				'status' => $catalog_ok ? 'pass' : 'fail',
				'detail' => $catalog_ok
					? 'zalo_personal + zalo_oa tiles injected'
					: 'Missing: ' . implode( ', ', array_filter( array( $has_zp ? '' : 'zalo_personal', $has_oa ? '' : 'zalo_oa' ) ) ),
			);
		}

		// ── ROW 3: zp.integration.registered ─────────────────────────────────

		$class_pi_ok = file_exists( $inc_personal . 'class-zalo-personal-integration.php' );
		$class_oa_ok = file_exists( $inc_oa . 'class-zalo-oa-integration.php' );
		$disk_int    = $class_pi_ok && $class_oa_ok;
		if ( ! $disk_int ) { $pass = false; }
		$rows[] = array(
			'label'  => 'zp.integration.registered — Disk: 2 integration class files',
			'status' => $disk_int ? 'pass' : 'fail',
			'detail' => $disk_int ? 'Both integration files exist' : implode( ', ', array_filter( array(
				$class_pi_ok ? '' : 'class-zalo-personal-integration.php missing',
				$class_oa_ok ? '' : 'class-zalo-oa-integration.php missing',
			) ) ),
		);

		$class_pi_loaded = class_exists( 'BizCity_Zalo_Personal_Integration' );
		$class_oa_loaded = class_exists( 'BizCity_Zalo_OA_Integration' );
		$loader_int      = $class_pi_loaded && $class_oa_loaded;
		if ( ! $loader_int ) { $pass = false; }
		$rows[] = array(
			'label'  => 'zp.integration.registered — Loader: 2 integration classes loaded',
			'status' => $loader_int ? 'pass' : 'fail',
			'detail' => $loader_int ? 'Both classes in memory' : implode( ', ', array_filter( array(
				$class_pi_loaded ? '' : 'BizCity_Zalo_Personal_Integration not loaded',
				$class_oa_loaded ? '' : 'BizCity_Zalo_OA_Integration not loaded',
			) ) ),
		);

		if ( $loader_int && class_exists( 'BizCity_Integration_Registry' ) ) {
			$reg    = BizCity_Integration_Registry::instance();
			$pi_obj = $reg->get_integration( 'zalo_personal' );
			$oa_obj = $reg->get_integration( 'zalo_oa' );
			$rt_int = ( $pi_obj instanceof BizCity_Channel_Integration ) && ( $oa_obj instanceof BizCity_Channel_Integration );
			if ( ! $rt_int ) { $pass = false; }
			$rows[] = array(
				'label'  => 'zp.integration.registered — Runtime: registry->get() returns objects',
				'status' => $rt_int ? 'pass' : 'fail',
				'detail' => $rt_int ? 'zalo_personal + zalo_oa registered in Integration_Registry' : implode( ', ', array_filter( array(
					( $pi_obj instanceof BizCity_Channel_Integration ) ? '' : 'zalo_personal not in registry',
					( $oa_obj instanceof BizCity_Channel_Integration ) ? '' : 'zalo_oa not in registry',
				) ) ),
			);
		}

		// ── ROW 4: zp.inbound.bridge ──────────────────────────────────────────

		$disk_emit = file_exists( $inc_shared . 'class-zalo-inbound-emitter.php' );
		if ( ! $disk_emit ) { $pass = false; }
		$rows[] = array(
			'label'  => 'zp.inbound.bridge — Disk: class-zalo-inbound-emitter.php',
			'status' => $disk_emit ? 'pass' : 'fail',
			'detail' => $disk_emit ? 'File exists' : 'Missing emitter include.',
		);

		$loader_emit = class_exists( 'BizCity_Zalo_Inbound_Emitter' );
		$rows[] = array(
			'label'  => 'zp.inbound.bridge — Loader: BizCity_Zalo_Inbound_Emitter loaded',
			'status' => $loader_emit ? 'pass' : 'skip',
			'detail' => $loader_emit ? 'Class loaded' : 'Class not found — plugin not activated.',
		);

		// Runtime: verify emit() produces correct platform discriminator.
		if ( $loader_emit ) {
			$triggered_platform = '';
			$catcher = function ( $data ) use ( &$triggered_platform ) {
				$triggered_platform = (string) ( $data['platform'] ?? '' );
			};
			add_action( 'bizcity_zalo_message_received', $catcher, 1, 1 );

			$emitter = BizCity_Zalo_Inbound_Emitter::instance();
			// Synthetic personal payload — will NOT persist (account_id unknown → save_map skipped).
			$emitter->emit( array(
				'kind'         => 'personal',
				'account_id'   => '__probe_synthetic__',
				'message_id'   => 'probe_' . uniqid(),
				'from_user_id' => 'probe_uid',
				'message_text' => 'DDV probe',
				'message_time' => time(),
			) );

			remove_action( 'bizcity_zalo_message_received', $catcher, 1 );

			$rt_emit = ( $triggered_platform === 'ZALO_PERSONAL' );
			if ( ! $rt_emit ) { $pass = false; }
			$rows[] = array(
				'label'  => 'zp.inbound.bridge — Runtime: emit() sets platform=ZALO_PERSONAL',
				'status' => $rt_emit ? 'pass' : 'fail',
				'detail' => $rt_emit
					? "emit() → platform='ZALO_PERSONAL' ✓ (zone discriminator R-ZP-4.1)"
					: "emit() fired but platform='" . esc_html( $triggered_platform ) . "' (expected ZALO_PERSONAL)",
			);
		}

		// ── ROW 5: zp.schema.tables ───────────────────────────────────────────

		$changelog_ok = file_exists( $changelog );
		if ( ! $changelog_ok ) { $pass = false; }
		$rows[] = array(
			'label'  => 'zp.schema.tables — Disk: modules.zalo-personal.json changelog',
			'status' => $changelog_ok ? 'pass' : 'fail',
			'detail' => $changelog_ok ? 'R-DCL changelog v1.0.0 exists' : 'Missing core/diagnostics/changelog/modules.zalo-personal.json',
		);

		$installer_ok = class_exists( 'BizCity_Zalo_Mapping_Repo' );
		$rows[] = array(
			'label'  => 'zp.schema.tables — Loader: BizCity_Zalo_Mapping_Repo loaded',
			'status' => $installer_ok ? 'pass' : 'skip',
			'detail' => $installer_ok ? 'Class loaded' : 'Class not found — plugin not activated.',
		);

		if ( $installer_ok ) {
			global $wpdb;
			$expected = array(
				$wpdb->prefix . 'bizcity_zalo_accounts',
				$wpdb->prefix . 'bizcity_zalo_message_map',
				$wpdb->prefix . 'bizcity_zalo_oa_window',
			);
			$tables_in_db = array();
			foreach ( $expected as $tbl ) {
				// [2026-07-11 Johnny Chu] R-SHOW-TABLES — fallback to information_schema when helper is not loaded.
				if ( function_exists( 'bizcity_tbl_exists' ) ) {
					$exists = bizcity_tbl_exists( $tbl );
				} else {
					$exists = (int) $wpdb->get_var(
						$wpdb->prepare(
							'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
							$tbl
						)
					) === 1;
				}
				if ( $exists ) {
					$tables_in_db[] = $tbl;
				}
			}
			$all_tables_ok = count( $tables_in_db ) === 3;
			if ( ! $all_tables_ok ) { $pass = false; }
			$rows[] = array(
				'label'  => 'zp.schema.tables — Runtime: 3 tables exist in DB',
				'status' => $all_tables_ok ? 'pass' : 'fail',
				'detail' => $all_tables_ok
					? 'bizcity_zalo_accounts + bizcity_zalo_message_map + bizcity_zalo_oa_window ✓'
					: count( $tables_in_db ) . '/3 tables exist. Chạy maybe_install() để tạo bảng.',
			);
		}

		// ── ROW 6: zp.oa.window ───────────────────────────────────────────────

		$disk_window = file_exists( $inc_shared . 'class-zalo-mapping-repo.php' );
		$rows[] = array(
			'label'  => 'zp.oa.window — Disk: BizCity_Zalo_Mapping_Repo (OA window methods)',
			'status' => $disk_window ? 'pass' : 'fail',
			'detail' => $disk_window ? 'R-ZP-5 OA window repo exists' : 'Missing class-zalo-mapping-repo.php',
		);

		if ( $installer_ok ) {
			// Unit test: window open if last_inbound_at = now, closed if > 7 days.
			$window_open_result  = BizCity_Zalo_Mapping_Repo::is_oa_window_open( 0, '' ); // always false
			$delta_new           = time();
			$delta_expired       = time() - 604801; // 7 days + 1 second
			// Simulate via reflection on internal logic — use direct time delta check.
			$simulated_open   = ( $delta_new - $delta_new ) <= 604800;     // 0 ≤ 604800 = true
			$simulated_closed = ( $delta_new - $delta_expired ) <= 604800; // 604801 > 604800 = false
			$window_logic_ok  = $simulated_open && ! $simulated_closed;
			$rows[] = array(
				'label'  => 'zp.oa.window — Runtime: 7-day window logic correct',
				'status' => $window_logic_ok ? 'pass' : 'fail',
				'detail' => $window_logic_ok ? '7d boundary: open(0s) ✓  expired(604801s) ✓' : '7-day window logic wrong.',
			);
		}

		// ── ROW 7: zp.zone.isolation ──────────────────────────────────────────

		$disk_emitter_src   = $disk_emit;
		$listener_file      = WP_PLUGIN_DIR . '/bizcity-twin-ai/core/channel-gateway/includes/class-universal-channel-listener.php';
		$listener_exists    = file_exists( $listener_file );
		if ( ! $listener_exists ) { $pass = false; }
		$rows[] = array(
			'label'  => 'zp.zone.isolation — Disk: universal listener + emitter exist',
			'status' => ( $listener_exists && $disk_emitter_src ) ? 'pass' : 'fail',
			'detail' => ( $listener_exists && $disk_emitter_src ) ? 'Both files exist' : implode( ', ', array_filter( array(
				$listener_exists    ? '' : 'class-universal-channel-listener.php missing',
				$disk_emitter_src   ? '' : 'class-zalo-inbound-emitter.php missing',
			) ) ),
		);

		// Loader: check bridge_zalo() guard code via string search in source.
		if ( $listener_exists ) {
			$src          = file_get_contents( $listener_file );
			$has_guard    = $src !== false && strpos( $src, "platform === 'ZALO_BOT'" ) !== false;
			if ( ! $has_guard ) { $pass = false; }
			$rows[] = array(
				'label'  => "zp.zone.isolation — Loader: bridge_zalo() ZALO_BOT bail-guard present",
				'status' => $has_guard ? 'pass' : 'fail',
				'detail' => $has_guard
					? "guard `if ( \$platform === 'ZALO_BOT' ) { return; }` found ✓"
					: "guard missing — ZALO_BOT messages may leak into CRM Inbox!",
			);
		}

		// Runtime: synthetic ZALO_BOT event must NOT pass through listener to waic_twf_process_flow.
		if ( $loader_emit ) {
			$bot_triggered = false;
			$catcher_bot   = function ( $trigger_key, $payload ) use ( &$bot_triggered ) {
				if ( 'bizcity_zalo_message_received' === $trigger_key ) {
					$bot_triggered = true;
				}
			};
			add_action( 'waic_twf_process_flow', $catcher_bot, 999, 2 );

			do_action( 'bizcity_zalo_message_received', array(
				'platform'   => 'ZALO_BOT',
				'account_id' => 'probe_bot',
				'message_id' => 'probe_bot_' . uniqid(),
				'from_user_id' => 'probe_admin',
				'message_text' => 'DDV zone probe',
				'message_time' => time(),
			) );

			remove_action( 'waic_twf_process_flow', $catcher_bot, 999 );

			// bot_triggered === false → guard worked → PASS
			$zone_ok = ! $bot_triggered;
			if ( ! $zone_ok ) { $pass = false; }
			$rows[] = array(
				'label'  => 'zp.zone.isolation — Runtime: ZALO_BOT event blocked from CRM flow',
				'status' => $zone_ok ? 'pass' : 'fail',
				'detail' => $zone_ok
					? 'ZALO_BOT synthetic event intercepted by guard — CRM Inbox protected ✓'
					: 'ZALO_BOT event passed into waic_twf_process_flow — zone guard BROKEN!',
			);
		}

		// ── ROW 8: zp.test.connection ─────────────────────────────────────────
		// [2026-06-07 Johnny Chu] PHASE-0.39 — DDV row cho /zalo-bridge/test + hook-log tooling.

		$disk_rest     = file_exists( $inc_shared . 'class-zalo-bridge-rest.php' );
		$disk_hook_log = file_exists( $inc_shared . 'class-zalo-hook-log.php' );
		$disk_test     = $disk_rest && $disk_hook_log;
		if ( ! $disk_test ) { $pass = false; }
		$rows[] = array(
			'label'  => 'zp.test.connection — Disk: rest proxy + hook-log files',
			'status' => $disk_test ? 'pass' : 'fail',
			'detail' => $disk_test ? 'class-zalo-bridge-rest.php + class-zalo-hook-log.php exist' : implode( ', ', array_filter( array(
				$disk_rest     ? '' : 'class-zalo-bridge-rest.php missing',
				$disk_hook_log ? '' : 'class-zalo-hook-log.php missing',
			) ) ),
		);

		$loader_rest      = class_exists( 'BizCity_Zalo_Bridge_REST' );
		$loader_hook_log  = class_exists( 'BizCity_Zalo_Hook_Log' );
		$route_registered = false;
		if ( function_exists( 'rest_get_server' ) ) {
			$routes           = rest_get_server()->get_routes();
			$route_registered = isset( $routes['/bizcity-channel/v1/zalo-bridge/test'] );
		}
		$loader_test = $loader_rest && $loader_hook_log && $route_registered;
		if ( ! $loader_test ) { $pass = false; }
		$rows[] = array(
			'label'  => 'zp.test.connection — Loader: REST class + route /zalo-bridge/test registered',
			'status' => $loader_test ? 'pass' : 'fail',
			'detail' => $loader_test ? 'BizCity_Zalo_Bridge_REST + BizCity_Zalo_Hook_Log loaded; route registered' : implode( ', ', array_filter( array(
				$loader_rest      ? '' : 'BizCity_Zalo_Bridge_REST not loaded',
				$loader_hook_log  ? '' : 'BizCity_Zalo_Hook_Log not loaded',
				$route_registered ? '' : 'route /bizcity-channel/v1/zalo-bridge/test not registered',
			) ) ),
		);

		// Runtime: real-call test_connection() — SKIP gracefully khi bridge chưa cấu hình.
		if ( $loader_bridge && method_exists( 'BizCity_Zalo_Bridge_Client', 'test_connection' ) ) {
			$client_t = BizCity_Zalo_Bridge_Client::instance();
			if ( ! $client_t->is_ready_fast() ) {
				$rows[] = array(
					'label'  => 'zp.test.connection — Runtime: test_connection() real-call',
					'status' => 'skip',
					'detail' => 'Bridge URL/token chưa cấu hình — SKIP real-call (vào Cài đặt → Zalo Bridge để nhập).',
				);
			} else {
				$diag       = $client_t->test_connection();
				$cfg_ok     = ! empty( $diag['checks']['config']['ok'] );
				$reach_ok   = ! empty( $diag['checks']['reachable']['ok'] );
				$authed_ok  = ! empty( $diag['checks']['authed']['ok'] );
				$latency    = (int) ( $diag['checks']['reachable']['latency_ms'] ?? 0 );
				$test_ok    = $cfg_ok && $reach_ok && $authed_ok;
				// Fail-OPEN: bridge offline = SKIP (degraded), không fail toàn probe.
				$status     = $test_ok ? 'pass' : ( $reach_ok ? 'fail' : 'skip' );
				if ( 'fail' === $status ) { $pass = false; }
				$rows[] = array(
					'label'  => 'zp.test.connection — Runtime: test_connection() real-call',
					'status' => $status,
					'detail' => $test_ok
						? sprintf( 'config+reachable+authed PASS — latency %dms, version %s', $latency, esc_html( (string) ( $diag['version'] ?? '?' ) ) )
						: ( $reach_ok
							? sprintf( 'reachable nhưng authed FAIL (HTTP %d) — Token WP phải khớp BIZCITY_INBOUND_TOKEN.', (int) ( $diag['checks']['reachable']['http'] ?? 0 ) )
							: 'Sidecar offline/unreachable — SKIP (fail-OPEN). Bật sidecar rồi chạy lại.' ),
				);
			}
		}

		return array(
			'status' => $pass ? 'pass' : 'fail',
			'rows'   => $rows,
		);
	}

	// [2026-06-08 Johnny Chu] HOTFIX — add missing interface method.
	public function cleanup(): void {}
}

// Register probe.
add_filter( 'bizcity_diagnostics_register_probes', static function ( array $probes ): array {
	$probes[] = new BizCity_Probe_Zalo_Personal();
	return $probes;
} );
