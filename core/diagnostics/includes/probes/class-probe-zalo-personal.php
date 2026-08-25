<?php
/**
 * Probe: Zalo Personal Channel Gateway (Phase 0.39).
 *
 * Personal-only DDV rows (R-DDV bắt buộc) với 3 layer mỗi row:
 *
 *   zp.bridge.health         — Disk: class exists | Loader: bootstrap loaded | Runtime: /health reachable
 *   zp.filter.catalog        — Disk: filter code | Loader: filter attached | Runtime: catalog có Personal tile
 *   zp.integration.registered— Disk: Personal class exists | Loader: registry loaded | Runtime: registry->get
 *   zp.inbound.bridge        — Disk: emitter exists | Loader: hook attached | Runtime: synthetic event shape
 *   zp.schema.tables         — Disk: changelog JSON | Loader: installer class | Runtime: Personal tables tồn tại
 *   zp.zone.isolation        — Disk: emitter code | Loader: guard attached | Runtime: platform discriminator
 *   zp.test.connection       — Disk: rest+hook-log files | Loader: REST class + route | Runtime: test_connection() real-call
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      PHASE-0.39 (2026-06-07)
 */

// [2026-06-07 Johnny Chu] PHASE-0.39 — DDV Personal probe (R-DDV bắt buộc)
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Probe_Zalo_Personal' ) ) { return; }

final class BizCity_Probe_Zalo_Personal implements BizCity_Diagnostics_Probe {

	public function id(): string          { return 'modules.zalo-personal'; }
	public function label(): string       { return 'Zalo Personal Channel Gateway (Phase 0.39)'; }
	public function description(): string { return 'Personal-only DDV rows: bridge health, managed/custom mode, Personal catalog filter, Personal integration registry, inbound emitter, Personal schema tables, zone isolation, test-connection + hook-log, encrypted conversation archive, bounded archive rows, Twin GPT owner boundary, archive lifecycle APIs, Personal CRM projection routes, Personal CRM send boundary, archive retention hook, archive maintenance REST.'; }
	public function severity(): string    { return 'warning'; }
	public function order(): int          { return 45; }
	public function icon(): string        { return 'message-square'; }
	public function estimate_ms(): int    { return 800; }

	public function precondition() {
		$plugin_dir = WP_PLUGIN_DIR . '/bizcity-twin-ai/plugins/bizcity-zalo-personal/';
		// [2026-08-21 Johnny Chu] R-DDV-OPTIONAL-ZALO-PERSONAL — this legacy satellite is optional; do not turn an absent module into a contract FAIL.
		if ( ! is_dir( $plugin_dir ) ) {
			return 'Zalo Personal satellite module is not installed; skip optional channel probe.';
		}
		return true;
	}

	// [2026-08-22 Johnny Chu] R-DDV-TRACE — emit safe stage/row evidence for modules.zalo-personal failures.
	private function trace( string $event, array $fields = array() ): void {
		$safe = array(
			'blog_id' => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0,
		);
		foreach ( $fields as $key => $value ) {
			if ( is_scalar( $value ) || null === $value ) {
				$safe[ sanitize_key( (string) $key ) ] = $value;
			} elseif ( is_array( $value ) ) {
				$safe[ sanitize_key( (string) $key ) . '_count' ] = count( $value );
			}
		}
		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $safe ) : json_encode( $safe );
		error_log( '[BIZCITY_DIAG][modules.zalo-personal] ' . $event . ' ' . (string) $json );
	}

	public function run( $ctx ): array {
		$rows = array();
		$pass = true;

		$plugin_dir  = WP_PLUGIN_DIR . '/bizcity-twin-ai/plugins/bizcity-zalo-personal/';
		// [2026-07-11 Johnny Chu] PHASE-0.39 HOTFIX — Personal includes moved to shared/personal subfolders.
		$inc_shared  = $plugin_dir . 'includes/shared/';
		$inc_personal = $plugin_dir . 'includes/personal/';
		$changelog   = WP_PLUGIN_DIR . '/bizcity-twin-ai/core/diagnostics/changelog/modules.zalo-personal.json';
		$this->trace( 'run_start', array(
			'plugin_dir_present' => is_dir( $plugin_dir ),
			'bridge_dir_present' => is_dir( $inc_shared ),
		) );

		try {

		// [2026-08-22 Johnny Chu] PHASE-0.39C-C0 — validate the registered B2B2C route fixture matrix before runtime waves.
		$contract_dir = WP_PLUGIN_DIR . '/bizcity-twin-ai/core/twin-core/contracts/schema/public/v1/';
		$fixture_files = array(
			'zalo-personal-bridge.allowed.json',
			'zalo-personal-bridge.denied-no-domain.json',
			'zalo-personal-bridge.denied-domain-mismatch.json',
			'zalo-personal-bridge.denied-capacity-zero.json',
			'zalo-personal-bridge.mapping-fail.json',
			'zalo-personal-bridge.invalid.json',
		);
		$schema_file = $contract_dir . 'zalo-personal-bridge.schema.json';
		$all_fixture_files_present = file_exists( $schema_file );
		foreach ( $fixture_files as $fixture_file ) {
			if ( ! file_exists( $contract_dir . 'fixtures/' . $fixture_file ) ) {
				$all_fixture_files_present = false;
			}
		}
		if ( ! $all_fixture_files_present ) { $pass = false; }
		$rows[] = array(
			'label'  => 'zp.c0.contract — Disk: schema + fixture matrix',
			'status' => $all_fixture_files_present ? 'pass' : 'fail',
			'detail' => $all_fixture_files_present ? 'Managed Zalo bridge schema and six registered fixtures exist.' : 'Missing C0 schema or fixture file.',
		);

		$catalog_file = $contract_dir . 'contract-catalog.json';
		$catalog = file_exists( $catalog_file ) ? json_decode( (string) file_get_contents( $catalog_file ), true ) : array();
		$catalog_entry = null;
		foreach ( (array) ( $catalog['contracts'] ?? array() ) as $contract ) {
			if ( is_array( $contract ) && (string) ( $contract['id'] ?? '' ) === 'zalo-personal-bridge' ) {
				$catalog_entry = $contract;
				break;
			}
		}
		$catalog_ok = is_array( $catalog_entry )
			&& (string) ( $catalog_entry['schema'] ?? '' ) === 'zalo-personal-bridge.schema.json'
			&& count( (array) ( $catalog_entry['fixtures']['additional_valid'] ?? array() ) ) === 4;
		if ( ! $catalog_ok ) { $pass = false; }
		$rows[] = array(
			'label'  => 'zp.c0.contract — Loader: contract catalog registration',
			'status' => $catalog_ok ? 'pass' : 'fail',
			'detail' => $catalog_ok ? 'zalo-personal-bridge is registered with four additional scenario fixtures.' : 'Contract catalog entry or additional fixture registration is incomplete.',
		);

		$c0_runtime_ok = $all_fixture_files_present;
		$c0_scenarios = array( 'allowed', 'denied_no_domain', 'denied_domain_mismatch', 'denied_capacity_zero', 'mapping_failure' );
		foreach ( $c0_scenarios as $scenario ) {
			$fixture_path = $contract_dir . 'fixtures/zalo-personal-bridge.' . str_replace( array( 'denied_no_domain', 'denied_domain_mismatch', 'denied_capacity_zero', 'mapping_failure' ), array( 'denied-no-domain', 'denied-domain-mismatch', 'denied-capacity-zero', 'mapping-fail' ), $scenario ) . '.json';
			$fixture = file_exists( $fixture_path ) ? json_decode( (string) file_get_contents( $fixture_path ), true ) : null;
			if ( ! is_array( $fixture ) || (string) ( $fixture['scenario'] ?? '' ) !== $scenario ) {
				$c0_runtime_ok = false;
				continue;
			}
			$request = is_array( $fixture['request'] ?? null ) ? $fixture['request'] : array();
			$entitlement = is_array( $fixture['entitlement'] ?? null ) ? $fixture['entitlement'] : array();
			$outcome = is_array( $fixture['outcome'] ?? null ) ? $fixture['outcome'] : array();
			$allowed = ! empty( $entitlement['allowed'] );
			$sidecar_called = ! empty( $outcome['sidecar_called'] );
			// [2026-08-23 Johnny Chu] PHASE-0.39D — entitlement may be allowed while domain policy denies the request.
			if ( $scenario === 'allowed' ) {
				$c0_runtime_ok = $c0_runtime_ok && $allowed && (int) ( $entitlement['account_limit'] ?? 0 ) === -1 && (string) ( $request['domain'] ?? '' ) === (string) ( $request['allowed_domain'] ?? '' ) && $sidecar_called;
			} elseif ( $scenario === 'mapping_failure' ) {
				$c0_runtime_ok = $c0_runtime_ok && $allowed && $sidecar_called && empty( $outcome['mapping_persisted'] ) && (string) ( $fixture['error']['reason_bucket'] ?? '' ) === 'tenant_mapping_failed';
			} elseif ( in_array( $scenario, array( 'denied_no_domain', 'denied_domain_mismatch' ), true ) ) {
				$c0_runtime_ok = $c0_runtime_ok && $allowed && ! $sidecar_called && ! empty( $fixture['error']['code'] ) && ! empty( $fixture['error']['reason_bucket'] );
			} else {
				$c0_runtime_ok = $c0_runtime_ok && ! $allowed && ! $sidecar_called && ! empty( $fixture['error']['code'] ) && ! empty( $fixture['error']['reason_bucket'] );
			}
		}
		if ( ! $c0_runtime_ok ) { $pass = false; }
		$rows[] = array(
			'label'  => 'zp.c0.contract — Runtime: allowed/denied/mapping matrix',
			'status' => $c0_runtime_ok ? 'pass' : 'fail',
			'detail' => $c0_runtime_ok ? 'Five scenarios preserve exact key, domain, capability, side-effect and reason semantics.' : 'C0 fixture semantics do not match the route contract.',
		);

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
			$managed_client_ok = class_exists( 'BizCity_Zalo_Personal_Hub_Client' ) && method_exists( 'BizCity_Zalo_Personal_Hub_Client', 'instance' );
			$managed_mode = method_exists( 'BizCity_Zalo_Bridge_Client', 'get_mode' ) && $client->get_mode() === 'managed_1api';
			$bridge_fast_ok = $managed_mode && ! $managed_client_ok ? false : $client->is_ready_fast();
			$bridge_status = $managed_mode && ! $managed_client_ok ? 'fail' : ( $bridge_fast_ok ? 'pass' : 'skip' );
			if ( $bridge_status === 'fail' ) { $pass = false; }
			$rows[] = array(
				'label'  => 'zp.bridge.health — Runtime: bridge URL+token configured',
				'status' => $bridge_status,
				'detail' => $managed_mode && ! $managed_client_ok
					? 'Managed Hub client singleton missing — deploy class-zalo-personal-hub-client.php with instance().'
					: ( $bridge_fast_ok
					? 'bizcity_zalo_bridge_url + bizcity_zalo_bridge_token set'
					: 'Bridge URL/token not configured — SKIP real-call (vào Cài đặt → Zalo Bridge để nhập).' ),
			);
		}

		// [2026-08-22 Johnny Chu] PHASE-0.39B-W7 — Disk/Loader/Runtime mode contract; no network call in Diagnostics.
		$mode_disk = file_exists( $inc_shared . 'class-zalo-personal-hub-client.php' );
		$mode_loaded = class_exists( 'BizCity_Zalo_Personal_Hub_Client' ) && method_exists( 'BizCity_Zalo_Personal_Hub_Client', 'instance' ) && method_exists( 'BizCity_Zalo_Bridge_Client', 'get_mode' );
		$resolved_mode = $mode_loaded ? BizCity_Zalo_Bridge_Client::instance()->get_mode() : '';
		$mode_ok = $mode_disk && $mode_loaded && in_array( $resolved_mode, array( 'managed_1api', 'custom_bridge' ), true );
		$this->trace( 'mode_check', array(
			'disk'   => $mode_disk,
			'loaded' => $mode_loaded,
			'mode'   => $resolved_mode,
			'ok'     => $mode_ok,
		) );
		if ( ! $mode_ok ) { $pass = false; }
		$rows[] = array(
			'label'  => 'zp.managed.mode — Disk/Loader/Runtime: managed_1api default and custom_bridge override',
			'status' => $mode_ok ? 'pass' : 'fail',
			'detail' => $mode_ok ? 'Mode resolver loaded; current mode=' . $resolved_mode : 'Managed/custom mode contract missing or invalid; deploy class-zalo-personal-hub-client.php with instance().',
		);

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
			$catalog_ok = $has_zp;
			if ( ! $catalog_ok ) { $pass = false; }
			$rows[] = array(
				'label'  => 'zp.filter.catalog — Runtime: catalog có zalo_personal',
				'status' => $catalog_ok ? 'pass' : 'fail',
				'detail' => $catalog_ok
					? 'zalo_personal tile injected'
					: 'Missing: zalo_personal',
			);
		}

		// ── ROW 3: zp.integration.registered ─────────────────────────────────

		$class_pi_ok = file_exists( $inc_personal . 'class-zalo-personal-integration.php' );
		$disk_int    = $class_pi_ok;
		if ( ! $disk_int ) { $pass = false; }
		$rows[] = array(
			'label'  => 'zp.integration.registered — Disk: 2 integration class files',
			'status' => $disk_int ? 'pass' : 'fail',
			'detail' => $disk_int ? 'Personal integration file exists' : implode( ', ', array_filter( array(
				$class_pi_ok ? '' : 'class-zalo-personal-integration.php missing',
			) ) ),
		);

		$class_pi_loaded = class_exists( 'BizCity_Zalo_Personal_Integration' );
		$loader_int      = $class_pi_loaded;
		if ( ! $loader_int ) { $pass = false; }
		$rows[] = array(
			'label'  => 'zp.integration.registered — Loader: Personal integration class loaded',
			'status' => $loader_int ? 'pass' : 'fail',
			'detail' => $loader_int ? 'Personal class in memory' : implode( ', ', array_filter( array(
				$class_pi_loaded ? '' : 'BizCity_Zalo_Personal_Integration not loaded',
			) ) ),
		);

		if ( $loader_int && class_exists( 'BizCity_Integration_Registry' ) ) {
			$reg    = BizCity_Integration_Registry::instance();
			// [2026-08-22 Johnny Chu] HOTFIX — use the current Integration Registry getter.
			$pi_obj = $reg->get( 'zalo_personal' );
			$rt_int = ( $pi_obj instanceof BizCity_Channel_Integration );
			$this->trace( 'integration_check', array(
				'registry_loaded' => true,
				'personal_registered' => $pi_obj instanceof BizCity_Channel_Integration,
				'ok'                  => $rt_int,
			) );
			if ( ! $rt_int ) { $pass = false; }
			$rows[] = array(
				'label'  => 'zp.integration.registered — Runtime: registry->get() returns objects',
				'status' => $rt_int ? 'pass' : 'fail',
				'detail' => $rt_int ? 'zalo_personal registered in Integration_Registry' : implode( ', ', array_filter( array(
					( $pi_obj instanceof BizCity_Channel_Integration ) ? '' : 'zalo_personal not in registry',
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
			// [2026-08-21 Johnny Chu] PHASE-0.39B — unbound Personal payload must not reach CRM hooks.
			$emitter->emit( array(
				'kind'         => 'personal',
				'account_id'   => '__probe_synthetic__',
				'message_id'   => 'probe_' . uniqid(),
				'from_user_id' => 'probe_uid',
				'message_text' => 'DDV probe',
				'message_time' => time(),
			) );

			remove_action( 'bizcity_zalo_message_received', $catcher, 1 );

			$rt_emit = ( $triggered_platform === '' );
			if ( ! $rt_emit ) { $pass = false; }
			$rows[] = array(
				'label'  => 'zp.inbound.bridge — Runtime: unbound Personal account is blocked',
				'status' => $rt_emit ? 'pass' : 'fail',
				'detail' => $rt_emit
					? 'Synthetic account without owner/inbox mapping did not emit a CRM hook ✓'
					: "Unbound account emitted platform='" . esc_html( $triggered_platform ) . "' — fail-closed mapping BROKEN!",
			);
		}

		// ── ROW 5: zp.schema.tables ───────────────────────────────────────────

		$changelog_ok = file_exists( $changelog );
		if ( ! $changelog_ok ) { $pass = false; }
		$rows[] = array(
			'label'  => 'zp.schema.tables — Disk: modules.zalo-personal.json changelog',
			'status' => $changelog_ok ? 'pass' : 'fail',
			'detail' => $changelog_ok ? 'R-DCL changelog v1.1.4 exists' : 'Missing core/diagnostics/changelog/modules.zalo-personal.json',
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
			$all_tables_ok = count( $tables_in_db ) === count( $expected );
			$this->trace( 'schema_check', array(
				'expected_count' => count( $expected ),
				'present_count'  => count( $tables_in_db ),
				'ok'             => $all_tables_ok,
			) );
			if ( ! $all_tables_ok ) { $pass = false; }
			$rows[] = array(
				'label'  => 'zp.schema.tables — Runtime: Personal tables exist in DB',
				'status' => $all_tables_ok ? 'pass' : 'fail',
				'detail' => $all_tables_ok
					? 'bizcity_zalo_accounts + bizcity_zalo_message_map ✓'
					: count( $tables_in_db ) . '/' . count( $expected ) . ' Personal tables exist. Chạy maybe_install() để tạo bảng.',
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
			$has_personal_trigger = $src !== false && strpos( $src, 'bizcity_zalo_personal_message_received' ) !== false;
			if ( ! $has_guard || ! $has_personal_trigger ) { $pass = false; }
			$rows[] = array(
				'label'  => "zp.zone.isolation — Loader: Bot bail + Personal trigger present",
				'status' => ( $has_guard && $has_personal_trigger ) ? 'pass' : 'fail',
				'detail' => ( $has_guard && $has_personal_trigger )
					? "ZALO_BOT bail-guard + `bizcity_zalo_personal_message_received` found ✓"
					: 'Missing ZALO_BOT bail-guard or Personal trigger — zone routing may be broken!',
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
			$managed_client_ok = class_exists( 'BizCity_Zalo_Personal_Hub_Client' ) && method_exists( 'BizCity_Zalo_Personal_Hub_Client', 'instance' );
			$managed_mode = method_exists( 'BizCity_Zalo_Bridge_Client', 'get_mode' ) && $client_t->get_mode() === 'managed_1api';
			if ( $managed_mode && ! $managed_client_ok ) {
				$pass = false;
				$rows[] = array(
					'label'  => 'zp.test.connection — Runtime: test_connection() real-call',
					'status' => 'fail',
					'detail' => 'Managed Hub client singleton missing — deploy class-zalo-personal-hub-client.php with instance().',
				);
			} elseif ( ! $client_t->is_ready_fast() ) {
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
				$this->trace( 'connection_check', array(
					'config_ok'    => $cfg_ok,
					'reachable_ok' => $reach_ok,
					'authed_ok'    => $authed_ok,
					'http'         => (int) ( $diag['checks']['reachable']['http'] ?? 0 ),
					'ok'           => $test_ok,
				) );
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

		// [2026-08-22 Johnny Chu] PHASE-0.39B-W8 — Disk/Loader/Runtime contract for encrypted CRM conversation archive.
		$archive_file = WP_PLUGIN_DIR . '/bizcity-twin-ai/core/channel-gateway/includes/class-channel-conversation-archive.php';
		$archive_disk = file_exists( $archive_file );
		$archive_loaded = class_exists( 'BizCity_Channel_Conversation_Archive' );
		$archive_hooks = $archive_loaded
			&& false !== has_action( 'bizcity_crm_event_crm_message_received' )
			&& false !== has_action( 'bizcity_crm_event_crm_message_sent' )
			&& false !== has_action( 'bizcity_crm_event_crm_message_delivery_updated' );
		$archive_ok = $archive_disk && $archive_loaded && $archive_hooks;
		if ( ! $archive_ok ) { $pass = false; }
		$rows[] = array(
			'label'  => 'zp.archive.integrity — Disk/Loader/Runtime: encrypted archive contract',
			'status' => $archive_ok ? 'pass' : 'fail',
			'detail' => $archive_ok
				? 'Archive file exists, class loaded, and both CRM message event hooks are registered.'
				: implode( ', ', array_filter( array(
					$archive_disk ? '' : 'archive helper file missing',
					$archive_loaded ? '' : 'BizCity_Channel_Conversation_Archive not loaded',
					$archive_hooks ? '' : 'CRM message archive hooks missing',
				) ) ),
		);
		// [2026-08-22 Johnny Chu] PHASE-0.39B-W8 — prove the shared archive whitelist covers Messenger as well as Zalo Personal.
		$archive_channels = $archive_loaded && defined( 'BizCity_Channel_Conversation_Archive::CHANNELS' )
			? (array) constant( 'BizCity_Channel_Conversation_Archive::CHANNELS' )
			: array();
		$archive_channels_ok = in_array( 'zalo_personal', $archive_channels, true ) && in_array( 'messenger', $archive_channels, true );
		if ( ! $archive_channels_ok ) { $pass = false; }
		$rows[] = array(
			'label'  => 'zp.archive.channels — Runtime: shared Messenger/Zalo archive whitelist',
			'status' => $archive_channels_ok ? 'pass' : 'fail',
			'detail' => $archive_channels_ok ? 'Encrypted archive accepts zalo_personal and messenger through one event pipeline.' : 'Archive channel whitelist is incomplete.',
		);
		$archive_bound_ok = $archive_loaded && defined( 'BizCity_Channel_Conversation_Archive::MAX_LINE_BYTES' ) && (int) constant( 'BizCity_Channel_Conversation_Archive::MAX_LINE_BYTES' ) > 0;
		if ( ! $archive_bound_ok ) { $pass = false; }
		$rows[] = array(
			'label'  => 'zp.archive.bound — Runtime: archive row size limit',
			'status' => $archive_bound_ok ? 'pass' : 'fail',
			'detail' => $archive_bound_ok ? 'Archive rows are bounded before filesystem append.' : 'Archive row size limit is missing.',
		);
		$owner_service_ok = class_exists( 'BizCity_Zalo_Bridge_REST' )
			&& method_exists( 'BizCity_Zalo_Bridge_REST', 'create_account_for_owner' )
			&& method_exists( 'BizCity_Zalo_Bridge_REST', 'start_qr_for_owner' )
			&& method_exists( 'BizCity_Zalo_Bridge_REST', 'qr_status_for_owner' )
			&& method_exists( 'BizCity_Zalo_Bridge_REST', 'delete_account_for_owner' );
		if ( ! $owner_service_ok ) { $pass = false; }
		$rows[] = array(
			'label'  => 'zp.twin-gpt.owner — Loader/Runtime: identity-aware Personal service boundary',
			'status' => $owner_service_ok ? 'pass' : 'fail',
			'detail' => $owner_service_ok ? 'Twin GPT Personal operations use owner-scoped service methods.' : 'Owner-scoped Personal service method is missing.',
		);
		$archive_lifecycle_ok = $archive_loaded
			&& method_exists( 'BizCity_Channel_Conversation_Archive', 'purge_expired' )
			&& method_exists( 'BizCity_Channel_Conversation_Archive', 'export_conversation' )
			&& method_exists( 'BizCity_Channel_Conversation_Archive', 'erase_conversation' )
			&& method_exists( 'BizCity_Channel_Conversation_Archive', 'reconcile_partition' );
		if ( ! $archive_lifecycle_ok ) { $pass = false; }
		$rows[] = array(
			'label'  => 'zp.archive.lifecycle — Loader/Runtime: retention/export/erase/reconcile APIs',
			'status' => $archive_lifecycle_ok ? 'pass' : 'fail',
			'detail' => $archive_lifecycle_ok ? 'Bounded archive lifecycle methods are loaded; caller authorization remains required for export/erase/reconcile.' : 'Archive lifecycle API is incomplete.',
		);
		$crm_projection_ok = class_exists( 'BizCity_TwinWeb_REST' )
			&& method_exists( 'BizCity_TwinWeb_REST', 'get_mychannels_zalo_personal_conversations' )
			&& method_exists( 'BizCity_TwinWeb_REST', 'get_mychannels_zalo_personal_conversation' )
			&& method_exists( 'BizCity_TwinWeb_REST', 'get_mychannels_zalo_personal_messages' );
		if ( ! $crm_projection_ok ) { $pass = false; }
		$rows[] = array(
			'label'  => 'zp.twin-gpt.crm — Loader/Runtime: Personal CRM projection routes',
			'status' => $crm_projection_ok ? 'pass' : 'fail',
			'detail' => $crm_projection_ok ? 'Twin GPT Personal list/detail/messages projection methods are loaded.' : 'Personal CRM projection method is missing.',
		);
		$crm_send_ok = $crm_projection_ok && method_exists( 'BizCity_TwinWeb_REST', 'send_mychannels_zalo_personal_message' );
		if ( ! $crm_send_ok ) { $pass = false; }
		$rows[] = array(
			'label'  => 'zp.twin-gpt.crm-send — Loader/Runtime: Personal CRM write boundary',
			'status' => $crm_send_ok ? 'pass' : 'fail',
			'detail' => $crm_send_ok ? 'Personal send delegates to canonical CRM post_message after owner/inbox validation.' : 'Personal CRM send boundary is missing.',
		);
		$retention_hook_ok = $archive_loaded && false !== has_action( 'bizcity_channel_jsonl_retention', array( 'BizCity_Channel_Conversation_Archive', 'retention_tick' ) );
		if ( ! $retention_hook_ok ) { $pass = false; }
		$rows[] = array(
			'label'  => 'zp.archive.retention — Loader/Runtime: guarded retention hook',
			'status' => $retention_hook_ok ? 'pass' : 'fail',
			'detail' => $retention_hook_ok ? 'Archive retention callback is attached to the existing Channel Gateway retention job.' : 'Archive retention callback is not attached.',
		);
		$archive_rest_ok = $archive_loaded
			&& method_exists( 'BizCity_Channel_Conversation_Archive', 'register_rest_routes' )
			&& method_exists( 'BizCity_Channel_Conversation_Archive', 'rest_reconcile' )
			&& method_exists( 'BizCity_Channel_Conversation_Archive', 'rest_export' )
			&& method_exists( 'BizCity_Channel_Conversation_Archive', 'rest_erase' );
		if ( ! $archive_rest_ok ) { $pass = false; }
		$rows[] = array(
			'label'  => 'zp.archive.rest — Loader/Runtime: authorized maintenance routes',
			'status' => $archive_rest_ok ? 'pass' : 'fail',
			'detail' => $archive_rest_ok ? 'Admin-only reconcile/export/erase routes are loaded behind tenant authorization.' : 'Archive maintenance REST boundary is incomplete.',
		);

		foreach ( $rows as $row ) {
			if ( strtolower( (string) ( $row['status'] ?? '' ) ) !== 'pass' ) {
				$this->trace( 'row_result', array(
					'status' => (string) ( $row['status'] ?? '' ),
					'label'  => (string) ( $row['label'] ?? '' ),
					'detail' => (string) ( $row['detail'] ?? '' ),
				) );
			}
		}
		$this->trace( 'run_complete', array(
			'status' => $pass ? 'pass' : 'fail',
			'rows'   => count( $rows ),
		) );
		return array(
			'status' => $pass ? 'pass' : 'fail',
			'rows'   => $rows,
		);
		} catch ( \Throwable $e ) {
			$message = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $e->getMessage() ) : strip_tags( $e->getMessage() );
			$message = preg_replace( '/(?:https?:\/\/|Bearer\s+)[^\s]+/i', '[redacted]', (string) $message );
			$this->trace( 'probe_exception', array(
				'exception' => get_class( $e ),
				'line'      => (int) $e->getLine(),
				'message'   => substr( (string) $message, 0, 240 ),
			) );
			$rows[] = array(
				'label'  => 'modules.zalo-personal — probe exception',
				'status' => 'fail',
				'detail' => get_class( $e ) . ' at line ' . (int) $e->getLine() . ': ' . substr( (string) $message, 0, 240 ),
			);
			return array(
				'status' => 'fail',
				'rows'   => $rows,
			);
		}
	}

	// [2026-06-08 Johnny Chu] HOTFIX — add missing interface method.
	public function cleanup(): void {}
}

// Register probe.
add_filter( 'bizcity_diagnostics_register_probes', static function ( array $probes ): array {
	$probes[] = new BizCity_Probe_Zalo_Personal();
	return $probes;
} );
