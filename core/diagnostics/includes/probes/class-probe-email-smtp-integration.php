<?php
/**
 * BizCity Diagnostics — email-smtp integration probe (PHASE-CG-SMTP-INTEGRATION v1.0)
 *
 * R-DDV 3-layer check:
 *   Layer 1 — DISK:
 *     - class-email-smtp-integration.php exists + readable
 *     - class-email-smtp-rest.php exists + readable
 *     - No BOM in either file
 *   Layer 2 — LOADER:
 *     - Constant BIZCITY_CHANNEL_GATEWAY_LOADED defined
 *     - Class BizCity_Email_SMTP_Integration exists in runtime
 *     - Class BizCity_Email_SMTP_REST exists in runtime
 *     - 'email_smtp' registered in BizCity_Integration_Registry
 *   Layer 3 — RUNTIME:
 *     - REST route POST /bizcity-channel/v1/email-smtp/test-send registered
 *     - REST route GET  /bizcity-channel/v1/email-smtp/contacts registered
 *     - REST route GET  /bizcity-channel/v1/email-smtp/stats registered
 *     - api_get_stats() returns expected shape
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      PHASE-CG-SMTP-INTEGRATION v1.0 (2026-06-10)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

if ( class_exists( 'BizCity_Probe_Email_SMTP_Integration', false ) ) {
	return;
}

final class BizCity_Probe_Email_SMTP_Integration implements BizCity_Diagnostics_Probe {

	public function id(): string          { return 'email-smtp.integration'; }
	public function label(): string       { return 'Email SMTP · Channel Integration (CG)'; }
	public function description(): string {
		return '3-layer DDV: Disk (files present, no BOM) → Loader (classes loaded, registry entry) → Runtime (REST routes registered, stats API returns valid shape).';
	}
	public function severity(): string    { return 'warning'; }
	public function order(): int          { return 85; }
	public function icon(): string        { return 'mail'; }
	public function estimate_ms(): int    { return 300; }

	public function precondition() {
		return true; // Self-contained — probe tests its own prerequisites.
	}

	public function run( $ctx ): array {
		$plugin_dir = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : ( WP_CONTENT_DIR . '/plugins' );
		$base       = $plugin_dir . '/bizcity-twin-ai/';

		$steps   = array();
		$ok      = true;

		/* ── Layer 1: DISK ───────────────────────────────────────────────── */

		$files = array(
			'integration' => 'core/channel-gateway/includes/adapters/class-email-smtp-integration.php',
			'rest'        => 'core/channel-gateway/includes/class-email-smtp-rest.php',
		);

		foreach ( $files as $key => $rel ) {
			$abs     = $base . $rel;
			$exists  = is_readable( $abs );
			$has_bom = false;
			if ( $exists ) {
				$bytes   = file_get_contents( $abs, false, null, 0, 3 );
				$has_bom = ( $bytes === "\xEF\xBB\xBF" );
			}
			$pass = $exists && ! $has_bom;
			$ok   = $ok && $pass;
			$steps[] = array(
				'label'  => 'Disk · ' . $key . ' file',
				'status' => $pass ? 'pass' : 'fail',
				'detail' => $pass
					? 'OK: ' . $rel
					: ( ! $exists ? 'File không tồn tại: ' . $rel : 'BOM detected (PowerShell write trap)' ),
			);
		}

		/* ── Layer 2: LOADER ──────────────────────────────────────────────── */

		$cg_loaded = defined( 'BIZCITY_CHANNEL_GATEWAY_LOADED' );
		$ok        = $ok && $cg_loaded;
		$steps[]   = array(
			'label'  => 'Loader · BIZCITY_CHANNEL_GATEWAY_LOADED',
			'status' => $cg_loaded ? 'pass' : 'fail',
			'detail' => $cg_loaded ? 'Constant defined.' : 'channel-gateway bootstrap not loaded.',
		);

		$class_integ = class_exists( 'BizCity_Email_SMTP_Integration', false );
		$ok          = $ok && $class_integ;
		$steps[]     = array(
			'label'  => 'Loader · BizCity_Email_SMTP_Integration',
			'status' => $class_integ ? 'pass' : 'fail',
			'detail' => $class_integ ? 'Class loaded.' : 'Class not found — check require_once in bootstrap.php.',
		);

		$class_rest = class_exists( 'BizCity_Email_SMTP_REST', false );
		$ok         = $ok && $class_rest;
		$steps[]    = array(
			'label'  => 'Loader · BizCity_Email_SMTP_REST',
			'status' => $class_rest ? 'pass' : 'fail',
			'detail' => $class_rest ? 'Class loaded.' : 'Class not found — check require_once in bootstrap.php.',
		);

		$registry_ok = false;
		if ( class_exists( 'BizCity_Integration_Registry', false ) ) {
			$reg         = BizCity_Integration_Registry::instance();
			$channel     = $reg->get( 'email_smtp' );
			$registry_ok = ( $channel instanceof BizCity_Email_SMTP_Integration );
		}
		$ok      = $ok && $registry_ok;
		$steps[] = array(
			'label'  => 'Loader · Registry: email_smtp',
			'status' => $registry_ok ? 'pass' : 'fail',
			'detail' => $registry_ok
				? 'BizCity_Email_SMTP_Integration registered.'
				: 'email_smtp not found in BizCity_Integration_Registry — check bootstrap registration.',
		);

		/* ── Layer 3: RUNTIME ─────────────────────────────────────────────── */

		$expected_routes = array(
			'POST /bizcity-channel/v1/email-smtp/test-send',
			'GET /bizcity-channel/v1/email-smtp/contacts',
			'GET /bizcity-channel/v1/email-smtp/stats',
		);

		$server = rest_get_server();
		$registered_routes = method_exists( $server, 'get_routes' ) ? $server->get_routes() : array();

		foreach ( $expected_routes as $expected ) {
			list( , $route_path ) = explode( ' ', $expected, 2 );
			$registered = isset( $registered_routes[ $route_path ] );
			$ok         = $ok && $registered;
			$steps[]    = array(
				'label'  => 'Runtime · route: ' . $expected,
				'status' => $registered ? 'pass' : 'fail',
				'detail' => $registered ? 'Route registered.' : 'Route missing — check BizCity_Email_SMTP_REST::init().',
			);
		}

		// api_get_stats() shape check.
		$stats_ok     = false;
		$stats_detail = '';
		if ( class_exists( 'BizCity_Email_SMTP_Integration', false )
			&& method_exists( 'BizCity_Email_SMTP_Integration', 'api_get_stats' ) ) {
			$stats = BizCity_Email_SMTP_Integration::api_get_stats();
			$stats_ok = isset( $stats['accounts'], $stats['ok'], $stats['fail'], $stats['total'] );
			$stats_detail = $stats_ok
				? 'accounts=' . $stats['accounts'] . ' ok=' . $stats['ok'] . ' fail=' . $stats['fail']
				: 'Unexpected shape: ' . wp_json_encode( $stats );
		} else {
			$stats_detail = 'api_get_stats() method not found.';
		}
		$ok      = $ok && $stats_ok;
		$steps[] = array(
			'label'  => 'Runtime · api_get_stats() shape',
			'status' => $stats_ok ? 'pass' : 'fail',
			'detail' => $stats_detail,
		);

		$fail_count = count( array_filter( $steps, function ( $s ) { return $s['status'] === 'fail'; } ) );

		return array(
			'status'   => $ok ? 'pass' : 'fail',
			'summary'  => $ok
				? 'Email SMTP integration OK — ' . count( $steps ) . ' checks passed.'
				: $fail_count . ' / ' . count( $steps ) . ' checks failed.',
			'fix_hint' => $ok ? '' : 'Xem từng step fail ở trên. Thường: (1) file chưa deploy, (2) OPcache stale, (3) bootstrap.php thiếu require_once.',
			'steps'    => $steps,
		);
	}

	// [2026-06-11 Johnny Chu] PHP74-COMPAT — interface BizCity_Diagnostics_Probe requires cleanup(): void; missing = fatal at class declaration.
	public function cleanup(): void {}
}

// Self-register via filter (R-DDV pattern).
add_filter( 'bizcity_diagnostics_register_probes', function ( $probes ) {
	$probes[] = 'BizCity_Probe_Email_SMTP_Integration';
	return $probes;
} );
