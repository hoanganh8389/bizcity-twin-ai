<?php
/**
 * BizCity Diagnostics — channel-gateway.rest probe.
 *
 * Verify 3-layer wiring (R-DDV) cho bizcity-channel/v1 REST namespace,
 * đặc biệt nhóm `/facebook/*` routes vừa được thêm Phase 5 (FB SPA tabs):
 *
 *   Layer 1 — DISK:
 *     - bootstrap.php hiện có require_once class-facebook-page-rest.php?
 *     - File adapter tồn tại + readable + size > 0?
 *     - Có BOM 3-byte không (PowerShell trap)?
 *   Layer 2 — LOADER:
 *     - Constant BIZCITY_CHANNEL_GATEWAY_LOADED defined?
 *     - Class BizCity_Facebook_Page_REST exists in runtime?
 *     - Phương thức ::init() đã được gọi (rest_api_init hook registered)?
 *   Layer 3 — RUNTIME:
 *     - REST server liệt kê route /bizcity-channel/v1/facebook/settings?
 *     - Self-call GET /facebook/settings (admin context) trả 200?
 *
 * Khi bất kỳ layer fail → probe trả status=fail + fix_hint cụ thể
 * (thường: "OPcache stale → reset" hoặc "deploy file mới lên server").
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-05-22 (Phase 5 — FB SPA tab debugging)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';


// [2026-06-08 Johnny Chu] HOTFIX — double-load guard (bootstrap may include via filter AND direct require).
if ( class_exists( 'BizCity_Probe_Channel_Gateway_REST', false ) ) {
	return;
}

final class BizCity_Probe_Channel_Gateway_REST implements BizCity_Diagnostics_Probe {

	const EXPECTED_ROUTES = [
		'/bizcity-channel/v1/facebook/settings',
		'/bizcity-channel/v1/facebook/pages',
		'/bizcity-channel/v1/facebook/bots',
		'/bizcity-channel/v1/facebook/history',
		'/bizcity-channel/v1/facebook/test-send',
		'/bizcity-channel/v1/facebook/post',
	];

	const ADAPTER_FILE   = 'core/channel-gateway/includes/adapters/class-facebook-page-rest.php';
	const BOOTSTRAP_FILE = 'core/channel-gateway/bootstrap.php';
	const REQUIRE_NEEDLE = "class-facebook-page-rest.php";
	const TARGET_CLASS   = 'BizCity_Facebook_Page_REST';

	public function id(): string          { return 'channel-gateway.rest'; }
	public function label(): string       { return 'Channel Gateway · FB REST routes'; }
	public function description(): string {
		return 'Verify bizcity-channel/v1/facebook/* routes (Phase 5 SPA tabs): disk → bootstrap require → class load → REST server route registry.';
	}
	public function severity(): string    { return 'critical'; }
	public function order(): int          { return 30; }
	public function icon(): string        { return 'plug-zap'; }
	public function estimate_ms(): int    { return 400; }

	public function precondition() {
		return true; // No external dep — probe itself is the check.
	}

	public function run( $ctx ): array {
		$plugin_dir = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : ( WP_CONTENT_DIR . '/plugins' );
		$base       = $plugin_dir . '/bizcity-twin-ai/';

		// ─── LAYER 1 · DISK ────────────────────────────────────────────────
		$adapter_path = $base . self::ADAPTER_FILE;
		$boot_path    = $base . self::BOOTSTRAP_FILE;

		$adapter_ok = is_readable( $adapter_path );
		$adapter_sz = $adapter_ok ? filesize( $adapter_path ) : 0;
		$ctx->emit_step( [
			'label'  => 'Disk · adapter file',
			'status' => ( $adapter_ok && $adapter_sz > 1000 ) ? 'pass' : 'fail',
			'detail' => $adapter_ok
				? sprintf( '%s · %s bytes', self::ADAPTER_FILE, number_format( $adapter_sz ) )
				: 'NOT FOUND: ' . self::ADAPTER_FILE,
		] );
		if ( ! $adapter_ok ) {
			return [
				'status'   => 'fail',
				'summary'  => 'Adapter file thiếu trên server webroot.',
				'error'    => 'adapter_file_missing',
				'fix_hint' => 'Deploy ' . self::ADAPTER_FILE . ' lên server (FTP/git/rsync) rồi re-run probe.',
			];
		}

		// BOM check (PS 5.1 trap — see /memories/powershell-php-bom.md).
		$head = file_get_contents( $adapter_path, false, null, 0, 3 );
		$has_bom = ( $head !== false && strlen( $head ) === 3
			&& ord( $head[0] ) === 0xEF && ord( $head[1] ) === 0xBB && ord( $head[2] ) === 0xBF );
		$ctx->emit_step( [
			'label'  => 'Disk · BOM check',
			'status' => $has_bom ? 'fail' : 'pass',
			'detail' => $has_bom
				? 'UTF-8 BOM detected — sẽ break header() / breakout output trước <?php.'
				: 'No BOM (correct).',
		] );
		if ( $has_bom ) {
			return [
				'status'   => 'fail',
				'summary'  => 'Adapter file có BOM → PHP sẽ output 3 bytes trước <?php → break routes registration.',
				'error'    => 'bom_present',
				'fix_hint' => 'Re-save file bằng create_file/replace_string_in_file tool (UTF-8 no BOM), KHÔNG dùng Set-Content -Encoding UTF8 trong PS 5.1.',
			];
		}

		// bootstrap.php require line present?
		$boot_ok = is_readable( $boot_path );
		$boot_src = $boot_ok ? (string) file_get_contents( $boot_path ) : '';
		$boot_has_require = ( strpos( $boot_src, self::REQUIRE_NEEDLE ) !== false );
		$ctx->emit_step( [
			'label'  => 'Disk · bootstrap require',
			'status' => $boot_has_require ? 'pass' : 'fail',
			'detail' => $boot_has_require
				? 'bootstrap.php has require_once for ' . self::REQUIRE_NEEDLE
				: 'bootstrap.php KHÔNG require ' . self::REQUIRE_NEEDLE . ' — file load nhưng adapter không bao giờ được include.',
		] );
		if ( ! $boot_has_require ) {
			return [
				'status'   => 'fail',
				'summary'  => 'bootstrap.php trên server thiếu dòng require_once cho adapter.',
				'error'    => 'bootstrap_missing_require',
				'fix_hint' => 'Deploy bootstrap.php mới (có line: require_once $adapters_dir . \'class-facebook-page-rest.php\';) rồi reset OPcache.',
			];
		}

		// ─── LAYER 2 · LOADER ──────────────────────────────────────────────
		$gw_loaded = defined( 'BIZCITY_CHANNEL_GATEWAY_LOADED' );
		$ctx->emit_step( [
			'label'  => 'Loader · channel-gateway bootstrap',
			'status' => $gw_loaded ? 'pass' : 'fail',
			'detail' => $gw_loaded ? 'BIZCITY_CHANNEL_GATEWAY_LOADED defined.' : 'Constant chưa define — channel-gateway/bootstrap.php không run.',
		] );
		if ( ! $gw_loaded ) {
			return [
				'status'   => 'fail',
				'summary'  => 'Channel-gateway bootstrap không được load → bizcity-twin-ai.php có thể đã skip include.',
				'error'    => 'gateway_bootstrap_not_loaded',
				'fix_hint' => 'Check bizcity-twin-ai.php require_once core/channel-gateway/bootstrap.php; verify plugin active.',
			];
		}

		$class_loaded = class_exists( self::TARGET_CLASS, false ); // no autoload
		$ctx->emit_step( [
			'label'  => 'Loader · adapter class',
			'status' => $class_loaded ? 'pass' : 'fail',
			'detail' => $class_loaded
				? self::TARGET_CLASS . ' loaded in runtime.'
				: self::TARGET_CLASS . ' NOT loaded — OPcache stale OR PHP fatal trong file.',
		] );

		// Hook registration check — has_action returns priority int when found, false otherwise.
		$rest_fired_before = (int) did_action( 'rest_api_init' );
		$hook_pri          = has_action( 'rest_api_init', array( self::TARGET_CLASS, 'register_routes' ) );
		$ctx->emit_step( [
			'label'  => 'Loader · rest_api_init hook',
			'status' => $hook_pri !== false ? 'pass' : 'fail',
			'detail' => sprintf(
				'did_action=%d · has_action(%s::register_routes)=%s',
				$rest_fired_before,
				self::TARGET_CLASS,
				$hook_pri === false ? 'NO' : ( 'YES @ priority ' . (int) $hook_pri )
			),
		] );
		if ( ! $class_loaded ) {
			// Try to capture last PHP error for hint.
			$last_err = error_get_last();
			$err_hint = '';
			if ( is_array( $last_err ) && ! empty( $last_err['message'] ) ) {
				$err_hint = sprintf( ' Last PHP error: %s @ %s:%d',
					$last_err['message'],
					basename( (string) ( $last_err['file'] ?? '' ) ),
					(int) ( $last_err['type'] ?? 0 )
				);
			}
			return [
				'status'   => 'fail',
				'summary'  => 'File có trên disk + bootstrap require đúng nhưng class không load → OPcache cache bản cũ bootstrap.php (chưa có require), HOẶC fatal khi parse file mới.',
				'error'    => 'class_not_loaded' . $err_hint,
				'fix_hint' => '1) Run: php -r "opcache_reset();" (CLI hoặc tạo loader.php gọi). 2) Hoặc deactivate→reactivate plugin BizCity Twin AI. 3) Tail wp-content/debug.log tìm fatal trong class-facebook-page-rest.php.',
			];
		}

		// ─── LAYER 3 · RUNTIME ─────────────────────────────────────────────
		// Probe runs in admin context (tools.php). rest_api_init may not have
		// fired yet, AND do_action() is a no-op once did_action()>0 even if our
		// callback wasn't reachable then. So we call register_routes() directly
		// — that's idempotent (register_rest_route just overwrites).
		$server = rest_get_server();
		$direct_called = false;
		if ( is_callable( array( self::TARGET_CLASS, 'register_routes' ) ) ) {
			call_user_func( array( self::TARGET_CLASS, 'register_routes' ) );
			$direct_called = true;
		}
		$ctx->emit_step( [
			'label'  => 'Runtime · force register_routes()',
			'status' => $direct_called ? 'pass' : 'fail',
			'detail' => $direct_called
				? 'Direct call BizCity_Facebook_Page_REST::register_routes() executed.'
				: 'Method register_routes() not callable — class missing static method.',
		] );

		$all     = $server ? $server->get_routes() : [];
		$missing = [];
		$found   = [];
		foreach ( self::EXPECTED_ROUTES as $r ) {
			if ( isset( $all[ $r ] ) ) { $found[] = $r; } else { $missing[] = $r; }
		}
		$ctx->emit_step( [
			'label'  => 'Runtime · REST route registry',
			'status' => empty( $missing ) ? 'pass' : 'fail',
			'detail' => sprintf( '%d/%d routes registered. Missing: %s',
				count( $found ), count( self::EXPECTED_ROUTES ),
				empty( $missing ) ? '(none)' : implode( ', ', $missing )
			),
		] );
		if ( ! empty( $missing ) ) {
			return [
				'status'   => 'fail',
				'summary'  => sprintf( '%d/%d facebook routes thiếu trong REST server registry.',
					count( $missing ), count( self::EXPECTED_ROUTES )
				),
				'error'    => 'rest_routes_missing',
				'fix_hint' => 'Class loaded nhưng ::init() chưa hook vào rest_api_init. Kiểm class-facebook-page-rest.php cuối file có `BizCity_Facebook_Page_REST::init();` và init() có add_action(\'rest_api_init\', ...).',
			];
		}

		// Live self-call (cheap) — hit /facebook/settings GET.
		$req  = new WP_REST_Request( 'GET', '/bizcity-channel/v1/facebook/settings' );
		$resp = $server->dispatch( $req );
		$code = $resp ? $resp->get_status() : 0;
		$pass = in_array( $code, [ 200, 401, 403 ], true ); // 401/403 = route exists but auth blocked — still proves registration.
		$ctx->emit_step( [
			'label'  => 'Runtime · live dispatch',
			'status' => $pass ? 'pass' : 'fail',
			'detail' => sprintf( 'GET /facebook/settings → HTTP %d %s',
				$code,
				$code === 200 ? '(OK)' : ( in_array( $code, [ 401, 403 ], true ) ? '(auth-gated, route OK)' : '(unexpected)' )
			),
		] );

		return [
			'status'  => $pass ? 'pass' : 'fail',
			'summary' => $pass
				? sprintf( 'All %d FB routes registered + dispatch OK.', count( self::EXPECTED_ROUTES ) )
				: sprintf( 'Routes registered nhưng dispatch trả HTTP %d.', $code ),
			'error'   => $pass ? null : 'dispatch_failed_' . $code,
			'fix_hint'=> $pass ? null : 'Check permission_callback in class-facebook-page-rest.php route definitions.',
		];
	}

	public function cleanup(): void {
		// Read-only probe.
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Channel_Gateway_REST';
	return $list;
} );
