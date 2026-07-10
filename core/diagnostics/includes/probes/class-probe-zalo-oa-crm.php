<?php
/**
 * BizCity Diagnostics — cg.zalo-oa-crm probe (Sprint ZA-1 DDV).
 *
 * R-DDV: 5 DDV row groups (15 layer checks) for the Zalo OA → CRM CSKH pipeline.
 * Maps to Sprint ZA-1 through ZA-5 in docs/channels/zalo-oa/ZALO-OA-INTEGRATION.md.
 *
 * DDV rows:
 *   zoa.adapter      — Disk: adapter file exists (no BOM) | Loader: class registered in
 *                      Integration_Registry | Runtime: test connection (SKIP if not configured)
 *
 *   zoa.webhook      — Disk: Channel REST API + namespace constant | Loader: bizcity-channel/v1
 *                      routes registered | Runtime: OA account has oa_id configured
 *
 *   zoa.token        — Disk: maybe_refresh_token method exists | Loader: required credential
 *                      fields declared | Runtime: token not already expired (SKIP if no account)
 *
 *   zoa.crm.tables   — Disk: BizCity_Channel_Messages class file | Loader: class loaded |
 *                      Runtime: bizcity_channel_messages + bizcity_crm_events tables exist
 *
 *   zoa.crm.scheduler — Disk: BizCity_Scheduler_Manager file | Loader: class exists |
 *                       Runtime: create_event() + update_event() methods available
 *                       (precondition for Sprint ZA-1.2 inbound CRM event creation)
 *
 * Gap detection (shows FAIL with fix_hint, not fatal):
 *   - Token persistence: refresh_token present but no token_expires_at → ZA-1.1 not done
 *   - Inbound meta forwarding: BizCity_Scheduler_Manager loaded but no CRM event
 *     creation logic detected → ZA-1.2 not done
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-06-13 (Sprint ZA-5 / R-DDV)
 */

// [2026-06-13 Johnny Chu] ZA-5 — DDV probe for Zalo OA CRM integration (R-DDV bắt buộc)
defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

if ( class_exists( 'BizCity_Probe_Zalo_OA_CRM', false ) ) {
	return;
}

final class BizCity_Probe_Zalo_OA_CRM implements BizCity_Diagnostics_Probe {

	public function id(): string          { return 'cg.zalo-oa-crm'; }
	public function label(): string       { return 'Zalo OA → CRM CSKH Pipeline (Sprint ZA-1 DDV)'; }
	public function description(): string {
		return '15 lớp kiểm tra: adapter disk+loader+runtime, webhook config, token refresh logic, bizcity_channel_messages + bizcity_crm_events tables, BizCity_Scheduler_Manager bridge. PASS = Zalo OA sẵn sàng cho CSKH inbound + auto-reply.';
	}
	public function severity(): string    { return 'warning'; }
	public function order(): int          { return 47; }
	public function icon(): string        { return 'message-circle'; }
	public function estimate_ms(): int    { return 900; }

	/** @var array<string,mixed> First configured zalo_bot account (decrypted) or []. */
	private $account = array();

	public function precondition() {
		return true; // All gaps handled gracefully as SKIP or fail-with-hint.
	}

	public function run( $ctx ): array {
		$steps = array();
		$pass  = true;

		// Resolve the first configured Zalo OA account (decrypted, or empty).
		$this->account = $this->resolve_account();

		/* ══════════════════════════════════════════════════════════════════
		 * ROW 1 — zoa.adapter (3 layers)
		 * ══════════════════════════════════════════════════════════════════ */

		$adapter_file = WP_PLUGIN_DIR . '/bizcity-twin-ai/core/channel-gateway/includes/adapters/class-zalo-bot-oa-integration.php';

		// Disk: file exists + no BOM
		if ( ! file_exists( $adapter_file ) ) {
			$steps[] = array( 'label' => 'zoa.adapter — Disk: adapter file exists', 'status' => 'fail', 'detail' => 'Missing: ' . $adapter_file );
			$pass    = false;
		} else {
			$bom = ( file_get_contents( $adapter_file, false, null, 0, 3 ) === "\xEF\xBB\xBF" );
			$steps[] = array(
				'label'  => 'zoa.adapter — Disk: class-zalo-bot-oa-integration.php' . ( $bom ? ' (BOM!)' : '' ),
				'status' => $bom ? 'fail' : 'pass',
				'detail' => $bom ? 'BOM detected — file ghi bằng Set-Content UTF8 (PS 5.1), PHP sẽ output trước <?php.' : number_format( filesize( $adapter_file ) ) . ' bytes, no BOM.',
			);
			if ( $bom ) { $pass = false; }
		}

		// Loader: class in memory + registered in Integration_Registry
		$adapter_loaded = class_exists( 'BizCity_Zalo_Bot_OA_Integration', false );
		$steps[]        = array(
			'label'  => 'zoa.adapter — Loader: BizCity_Zalo_Bot_OA_Integration loaded',
			'status' => $adapter_loaded ? 'pass' : 'fail',
			'detail' => $adapter_loaded ? 'Class in memory.' : 'class_exists() = false — core/channel-gateway không load adapter (check $bizcity_admin_ctx gate).',
		);
		if ( ! $adapter_loaded ) { $pass = false; }

		// Runtime: Integration_Registry returns adapter instance for 'zalo_bot'.
		// [2026-06-13 Johnny Chu] ZA-5 BUG FIX — Registry method is get(), not get_integration().
		if ( $adapter_loaded && class_exists( 'BizCity_Integration_Registry', false ) ) {
			$reg = BizCity_Integration_Registry::instance();
			$obj = $reg->get( 'zalo_bot' );
			$rt_adapter = ( $obj instanceof BizCity_Channel_Integration );
			$steps[]    = array(
				'label'  => 'zoa.adapter — Runtime: Integration_Registry[zalo_bot] = object',
				'status' => $rt_adapter ? 'pass' : 'fail',
				'detail' => $rt_adapter ? 'Integration object returned.' : 'get(“zalo_bot”) returned null — adapter chưa đăng ký (check bizcity_register_integrations hook).',
			);
			if ( ! $rt_adapter ) { $pass = false; }
		} else {
			$steps[] = array( 'label' => 'zoa.adapter — Runtime: Integration_Registry', 'status' => 'skip', 'detail' => 'BizCity_Integration_Registry not loaded.' );
		}

		/* ══════════════════════════════════════════════════════════════════
		 * ROW 2 — zoa.webhook (3 layers)
		 * ══════════════════════════════════════════════════════════════════ */

		// Disk: Channel REST API file
		$rest_file = WP_PLUGIN_DIR . '/bizcity-twin-ai/core/channel-gateway/includes/class-channel-rest-api.php';
		$disk_rest = file_exists( $rest_file );
		$steps[]   = array(
			'label'  => 'zoa.webhook — Disk: class-channel-rest-api.php',
			'status' => $disk_rest ? 'pass' : 'fail',
			'detail' => $disk_rest ? 'File exists.' : 'Missing REST API file — check core/channel-gateway.',
		);
		if ( ! $disk_rest ) { $pass = false; }

		// Loader: namespace `bizcity-channel/v1` + generic webhook route registered.
		// [2026-06-13 Johnny Chu] ZA-5 BUG FIX — BizCity_Channel_REST_API registers ONE generic
		// route: /webhook/(?P<platform>[a-z0-9_-]+)/(?P<instance_id>[a-z0-9_-]+)
		// NOT a per-platform route. The probe must match the regex route key, not a literal
		// '/bizcity-channel/v1/webhook/zalo_bot' string (which will never exist in routes[]).
		$routes      = rest_get_server() ? rest_get_server()->get_routes() : array();
		$ns_key      = '/bizcity-channel/v1';
		$has_ns      = isset( $routes[ $ns_key ] ) || ! empty( array_filter( array_keys( $routes ), function ( $r ) { return 0 === strpos( $r, '/bizcity-channel/v1/' ); } ) );
		// The generic webhook route key matches any platform including zalo_bot:
		$has_zalo_wh = ! empty( array_filter( array_keys( $routes ), function ( $r ) {
			return false !== strpos( $r, '/bizcity-channel/v1/webhook/' );
		} ) );
		$steps[]     = array(
			'label'  => 'zoa.webhook — Loader: /bizcity-channel/v1/webhook/zalo_bot/* registered',
			'status' => $has_zalo_wh ? 'pass' : ( $has_ns ? 'fail' : 'fail' ),
			'detail' => $has_zalo_wh
				? 'Unified webhook route /webhook/{platform}/{instance_id} registered (covers zalo_bot, R-CH-NS compliant).'
				: ( $has_ns ? 'Namespace bizcity-channel/v1 exists but /webhook/* route missing — class-channel-rest-api.php không đăng ký route webhook.' : 'Namespace bizcity-channel/v1 not found — class-channel-rest-api.php chưa load.' ),
		);
		if ( ! $has_zalo_wh ) { $pass = false; }

		// Runtime: configured account has oa_id
		$has_account = ! empty( $this->account );
		$has_oa_id   = $has_account && ! empty( $this->account['oa_id'] );
		$steps[]     = array(
			'label'  => 'zoa.webhook — Runtime: OA account configured with oa_id',
			'status' => $has_oa_id ? 'pass' : 'skip',
			'detail' => $has_oa_id
				? 'oa_id = ' . esc_html( $this->account['oa_id'] ) . '.'
				: ( $has_account ? 'Account exists but oa_id empty — điền OA ID trong Channel Gateway settings.' : 'Chưa có tài khoản Zalo OA — SKIP. Vào Channel Gateway → Thêm tài khoản Zalo Bot OA.' ),
		);

		/* ══════════════════════════════════════════════════════════════════
		 * ROW 3 — zoa.token (3 layers)
		 * ══════════════════════════════════════════════════════════════════ */

		// Disk: maybe_refresh_token method declared
		$has_refresh_method = $adapter_loaded && method_exists( 'BizCity_Zalo_Bot_OA_Integration', 'send_outbound' );
		// Note: maybe_refresh_token is private so we check via reflection if available.
		if ( $adapter_loaded && class_exists( 'ReflectionClass', false ) ) {
			$ref                = new ReflectionClass( 'BizCity_Zalo_Bot_OA_Integration' );
			$has_refresh_method = $ref->hasMethod( 'maybe_refresh_token' );
		}
		$steps[] = array(
			'label'  => 'zoa.token — Disk: maybe_refresh_token() method declared',
			'status' => $has_refresh_method ? 'pass' : 'fail',
			'detail' => $has_refresh_method ? 'Method exists.' : 'maybe_refresh_token() missing — Sprint ZA-1.1 not done.',
		);
		if ( ! $has_refresh_method ) { $pass = false; }

		// Loader: required credential fields in settings definition
		$has_settings = false;
		if ( $adapter_loaded ) {
			$ref_obj      = new ReflectionClass( 'BizCity_Zalo_Bot_OA_Integration' );
			$settings_def = array();
			if ( $ref_obj->hasProperty( 'settings' ) ) {
				$prop         = $ref_obj->getProperty( 'settings' );
				$prop->setAccessible( true );
				$tmp          = new BizCity_Zalo_Bot_OA_Integration( array() );
				$settings_def = $prop->getValue( $tmp );
			}
			$required_fields = array( 'access_token', 'refresh_token', 'app_id', 'app_secret', 'token_expires_at' );
			$missing_fields  = array();
			foreach ( $required_fields as $f ) {
				if ( ! isset( $settings_def[ $f ] ) ) { $missing_fields[] = $f; }
			}
			$has_settings = empty( $missing_fields );
			$steps[]      = array(
				'label'  => 'zoa.token — Loader: required credential fields in settings',
				'status' => $has_settings ? 'pass' : 'fail',
				'detail' => $has_settings
					? 'access_token, refresh_token, app_id, app_secret, token_expires_at declared.'
					: 'Missing fields: ' . implode( ', ', $missing_fields ) . ' — settings definition incomplete.',
			);
			if ( ! $has_settings ) { $pass = false; }
		} else {
			$steps[] = array( 'label' => 'zoa.token — Loader: credential fields check', 'status' => 'skip', 'detail' => 'Adapter not loaded.' );
		}

		// Runtime: token expiry check (gap detection for ZA-1.1)
		if ( $has_account ) {
			$expires_at    = $this->account['token_expires_at'] ?? '';
			$has_token     = ! empty( $this->account['access_token'] );
			$has_rt        = ! empty( $this->account['refresh_token'] );
			$has_app_creds = ! empty( $this->account['app_id'] ) && ! empty( $this->account['app_secret'] );

			if ( $has_rt && $has_app_creds && empty( $expires_at ) ) {
				// GAP: refresh_token set but token_expires_at empty → ZA-1.1 not done (token not persisted)
				$steps[] = array(
					'label'  => 'zoa.token — Runtime: token_expires_at presence (ZA-1.1 gap)',
					'status' => 'fail',
					'detail' => 'refresh_token + app creds có nhưng token_expires_at trống → maybe_refresh_token() chưa persist token về registry. Sprint ZA-1.1 chưa implement.',
				);
				$pass = false;
			} elseif ( $has_token && $expires_at ) {
				$expiry_ts = strtotime( $expires_at );
				if ( $expiry_ts && $expiry_ts < time() ) {
					$steps[] = array(
						'label'  => 'zoa.token — Runtime: access_token còn hạn',
						'status' => $has_rt ? 'skip' : 'fail',
						'detail' => 'Token đã hết hạn lúc ' . $expires_at . '. '
							. ( $has_rt ? 'refresh_token có — sẽ auto-refresh khi gọi API tiếp theo.' : 'Không có refresh_token — vào Channel Gateway điền lại token.' ),
					);
					if ( ! $has_rt ) { $pass = false; }
				} else {
					$remaining = $expiry_ts ? (int) round( ( $expiry_ts - time() ) / 60 ) : 0;
					$steps[]   = array(
						'label'  => 'zoa.token — Runtime: access_token còn hạn',
						'status' => 'pass',
						'detail' => 'Còn ' . $remaining . ' phút. Refresh token: ' . ( $has_rt ? 'có' : 'không có' ) . '.',
					);
				}
			} else {
				$steps[] = array(
					'label'  => 'zoa.token — Runtime: token check',
					'status' => $has_token ? 'skip' : 'fail',
					'detail' => $has_token ? 'access_token set nhưng không có token_expires_at — không thể kiểm tra hạn dùng.' : 'access_token trống — điền token trong Channel Gateway settings.',
				);
				if ( ! $has_token ) { $pass = false; }
			}
		} else {
			$steps[] = array( 'label' => 'zoa.token — Runtime: token check', 'status' => 'skip', 'detail' => 'Chưa cấu hình tài khoản — SKIP.' );
		}

		/* ══════════════════════════════════════════════════════════════════
		 * ROW 4 — zoa.crm.tables (3 layers)
		 * ══════════════════════════════════════════════════════════════════ */
		global $wpdb;

		// Disk: BizCity_Channel_Messages class file
		$cm_file = WP_PLUGIN_DIR . '/bizcity-twin-ai/core/channel-gateway/includes/class-channel-messages.php';
		$disk_cm = file_exists( $cm_file );
		$steps[] = array(
			'label'  => 'zoa.crm.tables — Disk: class-channel-messages.php',
			'status' => $disk_cm ? 'pass' : 'fail',
			'detail' => $disk_cm ? 'File exists.' : 'Missing — bizcity_channel_messages table không tạo được.',
		);
		if ( ! $disk_cm ) { $pass = false; }

		// Loader: BizCity_Channel_Messages class loaded
		$cm_loaded = class_exists( 'BizCity_Channel_Messages', false );
		$steps[]   = array(
			'label'  => 'zoa.crm.tables — Loader: BizCity_Channel_Messages',
			'status' => $cm_loaded ? 'pass' : 'fail',
			'detail' => $cm_loaded ? 'Class loaded.' : 'class_exists() = false — channel-gateway bootstrap chưa load class-channel-messages.php.',
		);
		if ( ! $cm_loaded ) { $pass = false; }

		// Runtime: bizcity_channel_messages + bizcity_crm_events tables exist
		$tbl_cm = $cm_loaded ? BizCity_Channel_Messages::table() : ( $wpdb->prefix . 'bizcity_channel_messages' );
		$tbl_ev = $wpdb->prefix . 'bizcity_crm_events';

		foreach ( array( $tbl_cm => 'bizcity_channel_messages', $tbl_ev => 'bizcity_crm_events' ) as $tbl => $label ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$exists  = (bool) $wpdb->get_var( $wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
				$tbl
			) );
			$steps[] = array(
				'label'  => 'zoa.crm.tables — Runtime: ' . $label . ' exists',
				'status' => $exists ? 'pass' : 'fail',
				'detail' => $exists ? 'Table found.' : 'Table not found: ' . $tbl . '. Run diagnostics auto-create.',
			);
			if ( ! $exists ) { $pass = false; }
		}

		/* ══════════════════════════════════════════════════════════════════
		 * ROW 5 — zoa.crm.scheduler (3 layers)
		 *
		 * Checks that BizCity_Scheduler_Manager is available — required for
		 * Sprint ZA-1.2 (inbound CRM event creation via create_event()).
		 * ══════════════════════════════════════════════════════════════════ */

		// Disk: scheduler manager file
		$sched_file = WP_PLUGIN_DIR . '/bizcity-twin-ai/core/scheduler/includes/class-scheduler-manager.php';
		$disk_sched = file_exists( $sched_file );
		$steps[]    = array(
			'label'  => 'zoa.crm.scheduler — Disk: class-scheduler-manager.php',
			'status' => $disk_sched ? 'pass' : 'fail',
			'detail' => $disk_sched ? 'File exists.' : 'Missing — Sprint ZA-1.2 CRM event creation sẽ không hoạt động.',
		);
		if ( ! $disk_sched ) { $pass = false; }

		// Loader: class exists
		$sched_loaded = class_exists( 'BizCity_Scheduler_Manager', false );
		$steps[]      = array(
			'label'  => 'zoa.crm.scheduler — Loader: BizCity_Scheduler_Manager',
			'status' => $sched_loaded ? 'pass' : 'fail',
			'detail' => $sched_loaded ? 'Class loaded.' : 'class_exists() = false — core/scheduler bootstrap chưa load. Sprint ZA-1.2 blocked.',
		);
		if ( ! $sched_loaded ) { $pass = false; }

		// Runtime: create_event + update_event methods available
		if ( $sched_loaded ) {
			$has_create = method_exists( 'BizCity_Scheduler_Manager', 'create_event' );
			$has_update = method_exists( 'BizCity_Scheduler_Manager', 'update_event' );
			$sched_rt   = $has_create && $has_update;

			// Gap detection (ZA-1.2): check if inbound meta forwarding is already implemented
			// by looking for ZALO_BOT in the channel listener or adapter.
			$listener_file = WP_PLUGIN_DIR . '/bizcity-twin-ai/core/channel-gateway/includes/class-universal-channel-listener.php';
			$za1_2_done    = false;
			if ( file_exists( $listener_file ) ) {
				$content   = file_get_contents( $listener_file );
				$za1_2_done = ( false !== strpos( $content, 'zalo_inbound' ) || false !== strpos( $content, 'ZALO_BOT.*create_event' ) );
			}
			// Also check adapter file for create_event call
			if ( ! $za1_2_done && file_exists( $adapter_file ) ) {
				$a_content  = file_get_contents( $adapter_file );
				$za1_2_done = ( false !== strpos( $a_content, 'create_event' ) && false !== strpos( $a_content, 'ZALO_BOT' ) );
			}

			$steps[] = array(
				'label'  => 'zoa.crm.scheduler — Runtime: create_event() + update_event() available',
				'status' => $sched_rt ? 'pass' : 'fail',
				'detail' => $sched_rt
					? 'Methods exist. '
					  . ( $za1_2_done ? '✓ Inbound CRM event forwarding detected (ZA-1.2 done).' : '⚠ Inbound CRM event forwarding NOT detected (Sprint ZA-1.2 chưa implement — xem docs/channels/zalo-oa/ZALO-OA-INTEGRATION.md §6).' )
					: 'create_event: ' . ( $has_create ? 'OK' : 'MISSING' ) . ' | update_event: ' . ( $has_update ? 'OK' : 'MISSING' ),
			);
			if ( ! $sched_rt ) { $pass = false; }
			// ZA-1.2 not done is advisory only (pass=true still if scheduler API exists)
		} else {
			$steps[] = array( 'label' => 'zoa.crm.scheduler — Runtime: scheduler methods', 'status' => 'skip', 'detail' => 'Class not loaded.' );
		}

		/* ══════════════════════════════════════════════════════════════════
		 * Result
		 * ══════════════════════════════════════════════════════════════════ */

		$fail_count = count( array_filter( $steps, function ( $s ) { return ( $s['status'] ?? '' ) === 'fail'; } ) );
		$skip_count = count( array_filter( $steps, function ( $s ) { return ( $s['status'] ?? '' ) === 'skip'; } ) );

		if ( ! $pass ) {
			return array(
				'status'   => 'fail',
				'summary'  => sprintf( 'Zalo OA CRM: %d FAIL · %d SKIP · %d bước — xem fix_hint.', $fail_count, $skip_count, count( $steps ) ),
				'error'    => 'Một hoặc nhiều lớp kiểm tra FAIL.',
				'fix_hint' => 'Xem chi tiết từng bước. Đọc docs/channels/zalo-oa/ZALO-OA-INTEGRATION.md §6 để biết thứ tự implement Sprint ZA-1 → ZA-5.',
				'steps'    => $steps,
			);
		}

		return array(
			'status'  => 'pass',
			'summary' => sprintf( 'Zalo OA CRM: %d bước PASS · %d SKIP. Adapter, webhook, token, tables, scheduler OK.', count( $steps ) - $fail_count - $skip_count, $skip_count ),
			'steps'   => $steps,
		);
	}

	public function cleanup(): void { /* read-only probe — nothing to clean */ }

	/* ──────────────────────────────────────────
	 * Private helpers
	 * ────────────────────────────────────────── */

	/**
	 * Return first decrypted zalo_bot account from Integration_Registry, or [].
	 *
	 * @return array
	 */
	private function resolve_account(): array {
		if ( ! class_exists( 'BizCity_Integration_Registry', false ) ) {
			return array();
		}
		$reg      = BizCity_Integration_Registry::instance();
		$accounts = method_exists( $reg, 'get_accounts_by_code' )
			? $reg->get_accounts_by_code( 'zalo_bot' )
			: array();

		if ( empty( $accounts ) ) {
			return array();
		}

		// Decrypt if method available.
		$first = reset( $accounts );
		if ( method_exists( $reg, 'decrypt_account' ) ) {
			$first = $reg->decrypt_account( $first );
		}
		return is_array( $first ) ? $first : array();
	}
}

// Register via filter (in case bootstrap requires this file via filter path).
add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Zalo_OA_CRM';
	return $list;
} );
