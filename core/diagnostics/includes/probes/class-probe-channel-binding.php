<?php
/**
 * BizCity Diagnostics — channel-binding probe (R-GCB SoT health check).
 *
 * Covers GURU-UI Wave 0 W0.4 + W0.5 + orphan detection in a single
 * 3-layer DDV probe (R-DDV) for `bizcity-channel/v1/inspector/*` route
 * group AND the `BizCity_Channel_Binding` runtime gateway.
 *
 *   Layer 1 — DISK:
 *     - class-channel-binding.php + class-webhook-inspector.php +
 *       class-universal-channel-listener.php present + readable + no BOM.
 *   Layer 2 — LOADER:
 *     - Class `BizCity_Channel_Binding` + `BizCity_Webhook_Inspector`
 *       + `BizCity_Universal_Channel_Listener` exist in runtime.
 *     - `BizCity_Channel_Binding::table()` table exists in DB.
 *   Layer 3 — RUNTIME:
 *     - REST routes `/inspector/bindings`, `/inspector/gurus`,
 *       `/inspector/channels` registered.
 *     - Live dispatch GET /inspector/bindings + /inspector/gurus
 *       returns 200/401/403 (route reachable).
 *     - Orphan binding scan: rows where character_id points to non-
 *       publishable Guru (status NOT IN active/published) OR deleted Guru.
 *
 * Probe is read-only; orphan scan emits warning step (not fail) so admin
 * can decide whether to soft-disable via UI.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      GURU-UI W0.4+W0.5 (2026-06-03 Johnny Chu)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';


// [2026-06-08 Johnny Chu] HOTFIX — double-load guard (bootstrap may include via filter AND direct require).
if ( class_exists( 'BizCity_Probe_Channel_Binding', false ) ) {
	return;
}

final class BizCity_Probe_Channel_Binding implements BizCity_Diagnostics_Probe {

	const EXPECTED_ROUTES = [
		'/bizcity-channel/v1/inspector/bindings',
		'/bizcity-channel/v1/inspector/gurus',
		'/bizcity-channel/v1/inspector/channels',
	];

	const DISK_FILES = [
		'core/channel-gateway/includes/class-channel-binding.php'              => 'BizCity_Channel_Binding',
		'core/channel-gateway/includes/class-webhook-inspector.php'            => 'BizCity_Webhook_Inspector',
		'core/channel-gateway/includes/class-universal-channel-listener.php'   => 'BizCity_Universal_Channel_Listener',
	];

	public function id(): string          { return 'channel-binding.gateway'; }
	public function label(): string       { return 'Channel Binding · R-GCB SoT + REST + listener'; }
	public function description(): string {
		return 'Verify Guru↔Channel binding stack (Wave 0): class load → DB table → REST inspector routes → universal listener resolve → orphan scan.';
	}
	public function severity(): string    { return 'critical'; }
	public function order(): int          { return 31; }
	public function icon(): string        { return 'link'; }
	public function estimate_ms(): int    { return 600; }

	public function precondition() {
		return true;
	}

	public function run( $ctx ): array {
		$plugin_dir = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : ( WP_CONTENT_DIR . '/plugins' );
		$base       = $plugin_dir . '/bizcity-twin-ai/';

		// ─── LAYER 1 · DISK ────────────────────────────────────────────────
		foreach ( self::DISK_FILES as $rel => $class_name ) {
			$path = $base . $rel;
			$ok   = is_readable( $path );
			$sz   = $ok ? (int) filesize( $path ) : 0;
			$ctx->emit_step( [
				'label'  => 'Disk · ' . basename( $rel ),
				'status' => ( $ok && $sz > 500 ) ? 'pass' : 'fail',
				'detail' => $ok
					? sprintf( '%s · %s bytes', $rel, number_format( $sz ) )
					: 'NOT FOUND: ' . $rel,
			] );
			if ( ! $ok ) {
				return [
					'status'   => 'fail',
					'summary'  => 'Required file missing on disk: ' . $rel,
					'error'    => 'disk_file_missing',
					'fix_hint' => 'Deploy ' . $rel . ' lên server rồi re-run probe.',
				];
			}
			// BOM trap (PS 5.1).
			$head = (string) file_get_contents( $path, false, null, 0, 3 );
			$has_bom = ( strlen( $head ) === 3
				&& ord( $head[0] ) === 0xEF && ord( $head[1] ) === 0xBB && ord( $head[2] ) === 0xBF );
			if ( $has_bom ) {
				$ctx->emit_step( [
					'label'  => 'Disk · BOM check ' . basename( $rel ),
					'status' => 'fail',
					'detail' => 'UTF-8 BOM detected — sẽ break header() / output trước <?php.',
				] );
				return [
					'status'   => 'fail',
					'summary'  => 'BOM detected in ' . $rel,
					'error'    => 'bom_present',
					'fix_hint' => 'Re-save file as UTF-8 no-BOM (xem /memories/powershell-php-bom.md).',
				];
			}
		}

		// ─── LAYER 2 · LOADER ──────────────────────────────────────────────
		foreach ( self::DISK_FILES as $rel => $class_name ) {
			$loaded = class_exists( $class_name, false );
			$ctx->emit_step( [
				'label'  => 'Loader · ' . $class_name,
				'status' => $loaded ? 'pass' : 'fail',
				'detail' => $loaded ? 'class loaded' : 'class NOT loaded (OPcache stale OR fatal in file)',
			] );
			if ( ! $loaded ) {
				return [
					'status'   => 'fail',
					'summary'  => $class_name . ' not loaded in runtime.',
					'error'    => 'class_not_loaded',
					'fix_hint' => '1) Reset OPcache. 2) Deactivate→reactivate plugin. 3) tail debug.log tìm fatal.',
				];
			}
		}

		// DB table check via class API.
		global $wpdb;
		$tbl = '';
		if ( is_callable( array( 'BizCity_Channel_Binding', 'table' ) ) ) {
			$tbl = (string) call_user_func( array( 'BizCity_Channel_Binding', 'table' ) );
		} elseif ( is_callable( array( 'BizCity_Channel_Binding', 'instance' ) ) ) {
			$inst = BizCity_Channel_Binding::instance();
			if ( $inst && method_exists( $inst, 'table' ) ) {
				$tbl = (string) $inst->table();
			}
		}
		if ( $tbl === '' ) {
			$tbl = $wpdb->prefix . 'bizcity_channel_bindings';
		}
		$table_exists = ( bizcity_tbl_exists( $tbl ) ); // [2026-06-21 Johnny Chu] R-SHOW-TABLES
		$row_count    = $table_exists ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$tbl}`" ) : 0;
		$ctx->emit_step( [
			'label'  => 'Loader · DB table ' . $tbl,
			'status' => $table_exists ? 'pass' : 'fail',
			'detail' => $table_exists ? sprintf( 'table exists · %d rows', $row_count ) : 'TABLE MISSING',
		] );
		if ( ! $table_exists ) {
			return [
				'status'   => 'fail',
				'summary'  => 'bizcity_channel_bindings table missing.',
				'error'    => 'table_missing',
				'fix_hint' => 'Re-run Site Provisioner OR call BizCity_Channel_Binding::maybe_install() để dbDelta.',
			];
		}

		// ─── LAYER 3 · RUNTIME ─────────────────────────────────────────────
		// Force register routes nếu rest_api_init đã fire mà callback chưa hook.
		if ( is_callable( array( 'BizCity_Webhook_Inspector', 'register_rest_routes' ) ) ) {
			call_user_func( array( 'BizCity_Webhook_Inspector', 'register_rest_routes' ) );
		} elseif ( is_callable( array( 'BizCity_Webhook_Inspector', 'register_routes' ) ) ) {
			call_user_func( array( 'BizCity_Webhook_Inspector', 'register_routes' ) );
		}

		$server = rest_get_server();
		$all    = $server ? $server->get_routes() : array();
		$missing = array();
		$found   = array();
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
				'summary'  => 'Inspector REST routes missing.',
				'error'    => 'rest_routes_missing',
				'fix_hint' => 'Check class-webhook-inspector.php register_routes() callback và ::init() hook on rest_api_init.',
			];
		}

		// Live dispatch — bindings list.
		$req  = new WP_REST_Request( 'GET', '/bizcity-channel/v1/inspector/bindings' );
		$resp = $server->dispatch( $req );
		$code = $resp ? $resp->get_status() : 0;
		$ok_codes = array( 200, 401, 403 );
		$dispatch_ok = in_array( $code, $ok_codes, true );
		$ctx->emit_step( [
			'label'  => 'Runtime · dispatch /inspector/bindings',
			'status' => $dispatch_ok ? 'pass' : 'fail',
			'detail' => sprintf( 'HTTP %d %s', $code,
				$code === 200 ? '(OK)' : ( in_array( $code, array( 401, 403 ), true ) ? '(auth-gated)' : '(unexpected)' )
			),
		] );

		// Live dispatch — gurus list.
		$req2  = new WP_REST_Request( 'GET', '/bizcity-channel/v1/inspector/gurus' );
		$resp2 = $server->dispatch( $req2 );
		$code2 = $resp2 ? $resp2->get_status() : 0;
		$dispatch_ok2 = in_array( $code2, $ok_codes, true );
		$ctx->emit_step( [
			'label'  => 'Runtime · dispatch /inspector/gurus',
			'status' => $dispatch_ok2 ? 'pass' : 'fail',
			'detail' => sprintf( 'HTTP %d', $code2 ),
		] );

		// Universal listener wiring check — confirm resolve() is callable.
		$listener_resolve_ok = is_callable( array( 'BizCity_Channel_Binding', 'resolve' ) );
		$ctx->emit_step( [
			'label'  => 'Runtime · BizCity_Channel_Binding::resolve callable',
			'status' => $listener_resolve_ok ? 'pass' : 'fail',
			'detail' => $listener_resolve_ok
				? 'Universal listener can resolve (platform, account_id) → character_id.'
				: 'resolve() method missing — listener cannot inject character_id.',
		] );

		// Orphan scan — bindings → Guru status.
		$chars_tbl = $wpdb->prefix . 'bizcity_characters';
		$orphan_rows = array();
		$orphan_details = array();
		if ( bizcity_tbl_exists( $chars_tbl ) ) { // [2026-06-21 Johnny Chu] R-SHOW-TABLES
			$sql = "SELECT b.id, b.platform, b.account_id, b.character_id, b.status AS binding_status,
				c.id AS char_id, c.status AS char_status, c.name AS char_name
				FROM `{$tbl}` b
				LEFT JOIN `{$chars_tbl}` c ON c.id = b.character_id
				WHERE b.status = 1
				  AND ( c.id IS NULL OR LOWER(c.status) NOT IN ('active','published') )
				LIMIT 50";
			$orphan_rows = (array) $wpdb->get_results( $sql, ARRAY_A );
		}
		$orphan_count = count( $orphan_rows );
		foreach ( $orphan_rows as $r ) {
			$orphan_details[] = sprintf( '#%d %s/%s → char#%d (%s)',
				(int) $r['id'],
				(string) $r['platform'],
				(string) $r['account_id'],
				(int) $r['character_id'],
				$r['char_id'] === null ? 'DELETED' : ( 'status=' . (string) $r['char_status'] )
			);
		}
		$ctx->emit_step( [
			'label'  => 'Runtime · orphan binding scan',
			'status' => $orphan_count === 0 ? 'pass' : 'warn',
			'detail' => $orphan_count === 0
				? 'Không có binding nào trỏ vào Guru deleted/archived.'
				: sprintf( '%d binding(s) orphan: %s', $orphan_count, implode( ' · ', array_slice( $orphan_details, 0, 5 ) )
					. ( $orphan_count > 5 ? ' …' : '' ) ),
		] );

		// Final verdict — warn-only on orphan, fail only on real wiring break.
		$pass = $dispatch_ok && $dispatch_ok2 && $listener_resolve_ok;
		$summary = sprintf(
			'Routes %d/%d · table %d rows · orphan %d',
			count( $found ), count( self::EXPECTED_ROUTES ),
			$row_count, $orphan_count
		);
		return [
			'status'   => $pass ? 'pass' : 'fail',
			'summary'  => $summary,
			'error'    => $pass ? null : 'runtime_dispatch_failed',
			'fix_hint' => $orphan_count > 0
				? 'Mở Knowledge → Characters → tab Channels của từng Guru orphan để disable binding cũ, hoặc re-publish Guru.'
				: null,
		];
	}

	public function cleanup(): void {
		// Read-only probe.
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Channel_Binding';
	return $list;
} );
