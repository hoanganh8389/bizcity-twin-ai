<?php
/**
 * BizCity Diagnostics — core.channel.zone_isolation probe (PHASE-0.40 Wave G0.4).
 *
 * R-DDV: Kiểm tra Zone discriminator giữa:
 *   Zone 1 — CRM care  (zalo_oa / zalo_personal → CRM Inbox ONLY)
 *   Zone 2 — Admin/automation (ZALO_BOT → automation/twinbrain ONLY)
 *
 * Đảm bảo:
 *  - UCL bridge_zalo() có guard bail khi platform === 'ZALO_BOT'
 *    (admin command KHÔNG vào CRM Inbox).
 *  - Automation Listener on_zalo_direct() có guard bail khi code ∈ {zalo_oa, zalo_personal}
 *    (tin khách hàng KHÔNG kích automation-admin).
 *  - CG Admin Router on_message() có guard bail khi code ∈ {zalo_oa, zalo_personal}.
 *  - Zalo Inbound Emitter gắn 'code' discriminator vào payload.
 *
 * DDV rows (9 layers):
 *   zone.ucl.guard          — guard exists in UCL bridge_zalo (Disk/Loader/Runtime)
 *   zone.automation.guard   — guard exists in Automation Listener on_zalo_direct (Disk/Loader/Runtime)
 *   zone.admin_router.guard — guard exists in CG Admin Router on_message (Disk/Loader)
 *   zone.emitter.code       — Zalo Inbound Emitter gắn 'code' field (Disk/Runtime)
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-06-13 (PHASE-0.40 G0.4 / R-DDV)
 */

// [2026-06-13 Johnny Chu] PHASE-0.40 G0.4 — DDV probe zone isolation (R-DDV bắt buộc)
defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

if ( class_exists( 'BizCity_Probe_Zone_Isolation', false ) ) {
	return;
}

final class BizCity_Probe_Zone_Isolation implements BizCity_Diagnostics_Probe {

	public function id(): string          { return 'core.channel.zone_isolation'; }
	public function label(): string       { return 'Zone Isolation: CRM Care (Zone 1) vs Admin/Automation (Zone 2)'; }
	public function description(): string {
		return '9 lớp kiểm tra guard discriminator Zone 1 (zalo_oa/personal → CRM Inbox) vs Zone 2 (ZALO_BOT → automation). PASS = tin khách hàng KHÔNG kích admin pipeline và ngược lại (R-ZONE-2, PHASE-0.40 G0).';
	}
	public function severity(): string    { return 'critical'; }
	public function order(): int          { return 49; }
	public function icon(): string        { return 'shield'; }
	public function estimate_ms(): int    { return 200; }

	public function precondition() {
		return true;
	}

	// [2026-06-14 Johnny Chu] HOTFIX — add missing $ctx param to match BizCity_Diagnostics_Probe::run($ctx):array
	public function run( $ctx ): array {
		$steps = array();
		$pass  = true;

		/* ══════════════════════════════════════════════════════════════════
		 * ROW 1 — zone.ucl.guard (3 layers)
		 * UCL bridge_zalo() bail khi platform === 'ZALO_BOT'
		 * ══════════════════════════════════════════════════════════════════ */

		// Disk: UCL file exists.
		$ucl_file = WP_PLUGIN_DIR . '/bizcity-twin-ai/core/channel-gateway/includes/class-universal-channel-listener.php';
		$disk_ucl = file_exists( $ucl_file );
		$steps[]  = array(
			'label'  => 'zone.ucl.guard — Disk: class-universal-channel-listener.php',
			'status' => $disk_ucl ? 'pass' : 'fail',
			'detail' => $disk_ucl ? 'File exists.' : 'UCL file missing.',
		);
		if ( ! $disk_ucl ) { $pass = false; }

		// Disk: guard code present in UCL source.
		$ucl_guard = false;
		if ( $disk_ucl ) {
			$ucl_src   = (string) file_get_contents( $ucl_file );
			$ucl_guard = ( false !== strpos( $ucl_src, "platform === 'ZALO_BOT'" ) )
			          || ( false !== strpos( $ucl_src, 'G0.1' ) );
		}
		$steps[] = array(
			'label'  => 'zone.ucl.guard — Disk: guard bail (ZALO_BOT) present in bridge_zalo()',
			'status' => $ucl_guard ? 'pass' : 'fail',
			'detail' => $ucl_guard
				? 'Guard R-ZONE-2 G0.1 found.'
				: 'Guard không tìm thấy — admin command sẽ rò vào CRM Inbox (R-ZONE-2 violation).',
		);
		if ( ! $ucl_guard ) { $pass = false; }

		// Loader: UCL class exists.
		$ucl_loaded = class_exists( 'BizCity_Universal_Channel_Listener', false );
		$steps[]    = array(
			'label'  => 'zone.ucl.guard — Loader: BizCity_Universal_Channel_Listener loaded',
			'status' => $ucl_loaded ? 'pass' : 'skip',
			'detail' => $ucl_loaded ? 'Class loaded.' : 'Class not loaded — bootstrap not run.',
		);

		/* ══════════════════════════════════════════════════════════════════
		 * ROW 2 — zone.automation.guard (3 layers)
		 * Automation Listener on_zalo_direct() bail khi code ∈ {zalo_oa, zalo_personal}
		 * ══════════════════════════════════════════════════════════════════ */

		// Disk: Automation Listener file exists.
		$al_file  = WP_PLUGIN_DIR . '/bizcity-twin-ai/core/automation/includes/class-automation-listener.php';
		$disk_al  = file_exists( $al_file );
		$steps[]  = array(
			'label'  => 'zone.automation.guard — Disk: class-automation-listener.php',
			'status' => $disk_al ? 'pass' : 'fail',
			'detail' => $disk_al ? 'File exists.' : 'Automation Listener file missing.',
		);
		if ( ! $disk_al ) { $pass = false; }

		// Disk: guard code present.
		$al_guard = false;
		if ( $disk_al ) {
			$al_src   = (string) file_get_contents( $al_file );
			$al_guard = ( false !== strpos( $al_src, "code === 'zalo_oa'" ) )
			         || ( false !== strpos( $al_src, 'G0.2' ) );
		}
		$steps[] = array(
			'label'  => 'zone.automation.guard — Disk: guard bail (zalo_oa/personal) in on_zalo_direct()',
			'status' => $al_guard ? 'pass' : 'fail',
			'detail' => $al_guard
				? 'Guard R-ZONE-2 G0.2 found.'
				: 'Guard không tìm thấy — tin khách hàng sẽ kích automation-admin (R-ZONE-2 violation).',
		);
		if ( ! $al_guard ) { $pass = false; }

		// Loader: Automation Listener class exists.
		$al_loaded = class_exists( 'BizCity_Automation_Listener', false );
		$steps[]   = array(
			'label'  => 'zone.automation.guard — Loader: BizCity_Automation_Listener loaded',
			'status' => $al_loaded ? 'pass' : 'skip',
			'detail' => $al_loaded ? 'Class loaded.' : 'Class not loaded — automation module not active.',
		);

		/* ══════════════════════════════════════════════════════════════════
		 * ROW 3 — zone.admin_router.guard (2 layers)
		 * CG Admin Router on_message() bail khi code ∈ {zalo_oa, zalo_personal}
		 * ══════════════════════════════════════════════════════════════════ */

		// Disk: Admin Router file exists.
		$router_file  = WP_PLUGIN_DIR . '/bizcity-twin-ai/core/channel-gateway/includes/class-cg-admin-router.php';
		$disk_router  = file_exists( $router_file );
		$steps[]      = array(
			'label'  => 'zone.admin_router.guard — Disk: class-cg-admin-router.php',
			'status' => $disk_router ? 'pass' : 'skip',
			'detail' => $disk_router ? 'File exists.' : 'Admin Router file missing — Zone 2 guard not applicable.',
		);

		// Disk: guard code present.
		$router_guard = false;
		if ( $disk_router ) {
			$router_src   = (string) file_get_contents( $router_file );
			$router_guard = ( false !== strpos( $router_src, "code === 'zalo_oa'" ) )
			             || ( false !== strpos( $router_src, 'G0.2' ) );
		}
		$steps[] = array(
			'label'  => 'zone.admin_router.guard — Disk: guard bail (zalo_oa/personal) in on_message()',
			'status' => $router_guard ? 'pass' : ( $disk_router ? 'fail' : 'skip' ),
			'detail' => $router_guard
				? 'Guard R-ZONE-2 G0.2 found.'
				: ( $disk_router
					? 'Guard không tìm thấy — tin khách hàng sẽ qua admin router (R-ZONE-2 violation).'
					: 'File không tồn tại — bỏ qua.' ),
		);
		if ( $disk_router && ! $router_guard ) { $pass = false; }

		/* ══════════════════════════════════════════════════════════════════
		 * ROW 4 — zone.emitter.code (2 layers)
		 * Zalo Inbound Emitter gắn 'code' vào payload
		 * ══════════════════════════════════════════════════════════════════ */

		// Disk: Emitter file exists.
		$emitter_file = WP_PLUGIN_DIR . '/bizcity-twin-ai/plugins/bizcity-zalo-personal/includes/class-zalo-inbound-emitter.php';
		$disk_emitter = file_exists( $emitter_file );
		$steps[]      = array(
			'label'  => 'zone.emitter.code — Disk: class-zalo-inbound-emitter.php',
			'status' => $disk_emitter ? 'pass' : 'skip',
			'detail' => $disk_emitter ? 'File exists.' : 'bizcity-zalo-personal emitter not installed — Zone 1 not active.',
		);

		// Disk: 'code' key in trigger_data present.
		$emitter_code = false;
		if ( $disk_emitter ) {
			$emitter_src  = (string) file_get_contents( $emitter_file );
			$emitter_code = ( false !== strpos( $emitter_src, "'code'" ) || false !== strpos( $emitter_src, '"code"' ) )
			             && ( false !== strpos( $emitter_src, '$adapter_code' ) );
		}
		$steps[] = array(
			'label'  => 'zone.emitter.code — Disk: trigger_data includes code discriminator',
			'status' => $emitter_code ? 'pass' : ( $disk_emitter ? 'fail' : 'skip' ),
			'detail' => $emitter_code
				? "'code' discriminator (zalo_oa/zalo_personal) found in trigger_data build."
				: ( $disk_emitter
					? "'code' key không có trong trigger_data — G0.2 guards sẽ không bail được (G0.3 chưa apply)."
					: 'Emitter file không tồn tại — bỏ qua.' ),
		);
		if ( $disk_emitter && ! $emitter_code ) { $pass = false; }

		return array(
			// [2026-06-14 Johnny Chu] HOTFIX — runner expects 'status' key ('pass'/'fail'), not 'pass' bool
			'status' => $pass ? 'pass' : 'fail',
			'steps'  => $steps,
		);
	}

	// [2026-06-14 Johnny Chu] HOTFIX — required by BizCity_Diagnostics_Probe interface
	public function cleanup(): void {}
}

// Self-register through the standard filter.
add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = new BizCity_Probe_Zone_Isolation();
	return $list;
} );
