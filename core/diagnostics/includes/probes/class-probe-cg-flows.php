<?php
/**
 * BizCity Diagnostics — channel-gateway.flows probe.
 *
 * Verify 3-layer wiring (R-DDV) for the ported Flows sub-module
 * (`core/channel-gateway/includes/flows/`).
 *
 *   Layer 1 — DISK:
 *     - flows/bootstrap.php, class-flow-installer.php, class-flow-handler.php,
 *       class-flow-rest.php, class-flow-admin-page.php exist + readable + no BOM.
 *     - core/channel-gateway/bootstrap.php requires `flows/bootstrap.php`.
 *   Layer 2 — LOADER:
 *     - classes BizCity_CG_Flow_{Installer,Handler,REST,Admin_Page} loaded.
 *     - backward-compat function `bizgpt_flow_remove_vietnamese_accents` exists.
 *   Layer 3 — RUNTIME:
 *     - Table `wp_bizcity_crm_flows` exists with column `reply_mode`.
 *     - REST route `/bizcity/cg/v1/flows` registered.
 *     - Real-call: strip_accents('chào') === 'chao'.
 *     - INSERT a `__healthtest_` row + SELECT + DELETE round-trip.
 *
 * Schema source of truth: core/diagnostics/changelog/modules.flows.json
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      PHASE-N (2026-05-25)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

final class BizCity_Probe_CG_Flows implements BizCity_Diagnostics_Probe {

	const DISK_FILES = array(
		'core/channel-gateway/includes/flows/bootstrap.php',
		'core/channel-gateway/includes/flows/class-flow-installer.php',
		'core/channel-gateway/includes/flows/class-flow-ref-codec.php',
		'core/channel-gateway/includes/flows/class-flow-handler.php',
		'core/channel-gateway/includes/flows/class-flow-rest.php',
		'core/channel-gateway/includes/flows/class-flow-admin-page.php',
	);

	const REQUIRED_CLASSES = array(
		'BizCity_CG_Flow_Installer',
		'BizCity_CG_Flow_Ref_Codec',
		'BizCity_CG_Flow_Handler',
		'BizCity_CG_Flow_REST',
	);

	const EXPECTED_ROUTE = '/bizcity-channel/v1/flows';
	const EXPECTED_COLUMN = 'reply_mode';

	public function id(): string          { return 'channel-gateway.flows'; }
	public function label(): string       { return 'Channel Gateway · Flows (ported)'; }
	public function description(): string {
		return 'Verify the ported bizgpt-custom-flows sub-module: disk → loader → runtime (table, REST route, strip_accents, INSERT/SELECT/DELETE round-trip).';
	}
	public function severity(): string    { return 'critical'; }
	public function order(): int          { return 35; }
	public function icon(): string        { return 'workflow'; }
	public function estimate_ms(): int    { return 500; }

	public function precondition() { return true; }

	public function run( $ctx ): array {
		$plugin_dir = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : ( WP_CONTENT_DIR . '/plugins' );
		$base       = $plugin_dir . '/bizcity-twin-ai/';
		$steps      = array();

		// ─── LAYER 1 · DISK ────────────────────────────────────────────────
		foreach ( self::DISK_FILES as $rel ) {
			$path = $base . $rel;
			$exists = is_readable( $path );
			$size = $exists ? filesize( $path ) : 0;
			$ctx->emit_step( $s = array(
				'label'  => 'Disk · ' . basename( $rel ),
				'status' => ( $exists && $size > 0 ) ? 'pass' : 'fail',
				'detail' => $exists ? "{$rel} · " . number_format( $size ) . ' bytes' : 'MISSING ' . $rel,
			) );
			$steps[] = $s;
			if ( ! $exists ) {
				return array(
					'status'   => 'fail',
					'summary'  => 'File thiếu: ' . $rel,
					'error'    => 'file_missing',
					'fix_hint' => 'Deploy ' . $rel . ' lên server (Phase A flows port).',
					'steps'    => $steps,
				);
			}
			// BOM trap (PS 5.1).
			$head = file_get_contents( $path, false, null, 0, 3 );
			$has_bom = ( $head !== false && strlen( $head ) === 3
				&& ord( $head[0] ) === 0xEF && ord( $head[1] ) === 0xBB && ord( $head[2] ) === 0xBF );
			if ( $has_bom ) {
				$steps[] = $s = array( 'label' => 'Disk · BOM', 'status' => 'fail', 'detail' => 'BOM in ' . basename( $rel ) );
				$ctx->emit_step( $s );
				return array(
					'status'   => 'fail',
					'summary'  => 'BOM detected in ' . $rel,
					'error'    => 'bom_present',
					'fix_hint' => 'Re-save with create_file/replace_string_in_file (UTF-8 no BOM).',
					'steps'    => $steps,
				);
			}
		}

		// CG bootstrap requires flows/bootstrap.php?
		$cg_boot = $base . 'core/channel-gateway/bootstrap.php';
		$cg_boot_src = is_readable( $cg_boot ) ? (string) file_get_contents( $cg_boot ) : '';
		$has_req = ( strpos( $cg_boot_src, "flows/bootstrap.php" ) !== false );
		$steps[] = $s = array(
			'label'  => 'Disk · CG bootstrap requires flows/',
			'status' => $has_req ? 'pass' : 'fail',
			'detail' => $has_req ? 'OK' : 'core/channel-gateway/bootstrap.php không require flows/bootstrap.php',
		);
		$ctx->emit_step( $s );
		if ( ! $has_req ) {
			return array(
				'status'   => 'fail',
				'summary'  => 'CG bootstrap chưa wire flows sub-module.',
				'error'    => 'cg_bootstrap_not_wired',
				'fix_hint' => 'Add `require_once $gateway_dir . "flows/bootstrap.php";` to core/channel-gateway/bootstrap.php.',
				'steps'    => $steps,
			);
		}

		// ─── LAYER 2 · LOADER ─────────────────────────────────────────────
		foreach ( self::REQUIRED_CLASSES as $cls ) {
			$ok = class_exists( $cls );
			$steps[] = $s = array(
				'label'  => 'Loader · class ' . $cls,
				'status' => $ok ? 'pass' : 'fail',
				'detail' => $ok ? 'loaded' : 'NOT loaded (OPcache stale?)',
			);
			$ctx->emit_step( $s );
			if ( ! $ok ) {
				return array(
					'status'   => 'fail',
					'summary'  => 'Class ' . $cls . ' không load.',
					'error'    => 'class_missing',
					'fix_hint' => 'Reset OPcache hoặc verify file deploy thành công.',
					'steps'    => $steps,
				);
			}
		}
		// Backward-compat wrapper: intentionally disabled while legacy
		// `bizgpt-custom-flows` plugin is still active (it declares the
		// function file-scope without guard → would fatal nếu ta shadow).
		// Probe chỉ kiểm tra function tồn tại (do plugin nào declare cũng OK).
		$has_compat = function_exists( 'bizgpt_flow_remove_vietnamese_accents' );
		$legacy_active = file_exists( WP_PLUGIN_DIR . '/bizgpt-custom-flows/bizgpt-custom-flows.php' );
		$steps[] = $s = array(
			'label'  => 'Loader · backward-compat fn',
			'status' => $has_compat ? 'pass' : ( $legacy_active ? 'fail' : 'skip' ),
			'detail' => $has_compat
				? 'bizgpt_flow_remove_vietnamese_accents() defined (declared by ' . ( $legacy_active ? 'legacy plugin' : 'new bootstrap' ) . ')'
				: ( $legacy_active ? 'legacy plugin present but function missing — check load order' : 'wrapper disabled (Phase D pending re-enable)' ),
		);
		$ctx->emit_step( $s );

		// ─── LAYER 3 · RUNTIME ────────────────────────────────────────────
		global $wpdb;
		$tbl = BizCity_CG_Flow_Installer::table();
		$tbl_exists = BizCity_CG_Flow_Installer::table_exists( $tbl );
		$steps[] = $s = array(
			'label'  => 'Runtime · table',
			'status' => $tbl_exists ? 'pass' : 'fail',
			'detail' => $tbl_exists ? $tbl : $tbl . ' MISSING',
		);
		$ctx->emit_step( $s );
		if ( ! $tbl_exists ) {
			return array(
				'status'   => 'fail',
				'summary'  => 'Table ' . $tbl . ' chưa tồn tại.',
				'error'    => 'table_missing',
				'fix_hint' => 'Mở Tools → BizCity Diagnostics → 🔧 Auto-fix all (sẽ gọi BizCity_CG_Flow_Installer::ensure_table()). Lưu ý: plugin lỗi thời `bizgpt-custom-flows` KHÔNG cần active — mọi logic flows đã dời về core/channel-gateway/includes/flows/.',
				'steps'    => $steps,
			);
		}

		// Column reply_mode present?
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$cols = (array) $wpdb->get_col( "SHOW COLUMNS FROM {$tbl}" );
		$has_col = in_array( self::EXPECTED_COLUMN, $cols, true );
		$steps[] = $s = array(
			'label'  => 'Runtime · column reply_mode',
			'status' => $has_col ? 'pass' : 'fail',
			'detail' => $has_col ? 'present' : 'missing column reply_mode',
		);
		$ctx->emit_step( $s );
		if ( ! $has_col ) {
			return array(
				'status'   => 'fail',
				'summary'  => 'Column reply_mode chưa được auto-create.',
				'error'    => 'column_missing',
				'fix_hint' => 'Verify modules.flows.json v1.0.0 declares reply_mode + re-run Auto_Create.',
				'steps'    => $steps,
			);
		}

		// REST route registered?
		$server = rest_get_server();
		$routes = method_exists( $server, 'get_routes' ) ? array_keys( $server->get_routes() ) : array();
		$route_ok = in_array( self::EXPECTED_ROUTE, $routes, true );
		$steps[] = $s = array(
			'label'  => 'Runtime · REST route',
			'status' => $route_ok ? 'pass' : 'fail',
			'detail' => $route_ok ? self::EXPECTED_ROUTE : self::EXPECTED_ROUTE . ' NOT in server routes',
		);
		$ctx->emit_step( $s );
		if ( ! $route_ok ) {
			return array(
				'status'   => 'fail',
				'summary'  => 'REST route ' . self::EXPECTED_ROUTE . ' không đăng ký.',
				'error'    => 'route_missing',
				'fix_hint' => 'Verify BizCity_CG_Flow_REST::init() called in flows/bootstrap.php.',
				'steps'    => $steps,
			);
		}

		// strip_accents real-call.
		$accent = BizCity_CG_Flow_Handler::strip_accents( 'chào bạn' );
		$ok_accent = ( $accent === 'chao ban' );
		$steps[] = $s = array(
			'label'  => 'Runtime · strip_accents',
			'status' => $ok_accent ? 'pass' : 'fail',
			'detail' => 'in=chào bạn → out=' . $accent,
		);
		$ctx->emit_step( $s );

		// Ref codec round-trip. Prefers legacy twf_*; falls back to internal AES.
		$legacy_present = function_exists( 'twf_encrypt_chat_id' ) && function_exists( 'twf_decrypt_chat_id' );
		$enc_token = BizCity_CG_Flow_Ref_Codec::encode( 42 );
		$dec_id    = BizCity_CG_Flow_Ref_Codec::decode( $enc_token );
		$codec_ok  = ( $enc_token !== '' && $dec_id === 42 );
		$steps[] = $s = array(
			'label'  => 'Runtime · ref-codec round-trip',
			'status' => $codec_ok ? 'pass' : 'fail',
			'detail' => 'encode(42)=' . ( $enc_token !== '' ? substr( $enc_token, 0, 16 ) . '… (' . strlen( $enc_token ) . 'B)' : 'EMPTY' )
				. ' → decode=' . $dec_id
				. ' · ' . ( $legacy_present ? 'via legacy twf_*' : 'via internal AES fallback' ),
		);
		$ctx->emit_step( $s );

		// INSERT/SELECT/DELETE round-trip with __healthtest_ prefix.
		$marker = '__healthtest_' . wp_generate_password( 8, false, false );
		$ins = $wpdb->insert( $tbl, array(
			'message'           => $marker,
			'message_khong_dau' => $marker,
			'shortcode'         => '[' . $marker . ']',
			'action_type'       => 'run_shortcode',
			'reply_mode'        => 'direct',
			'action_config'     => '{}',
			'prompt'            => '',
			'reminder_delay'    => 0,
			'reminder_unit'     => 'minutes',
			'reminder_text'     => '',
			'delay_only'        => 0,
			'updated_at'        => current_time( 'mysql' ),
		) );
		$insert_id = $ins ? (int) $wpdb->insert_id : 0;
		$round_trip = false;
		if ( $insert_id ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, reply_mode FROM {$tbl} WHERE id=%d", $insert_id ) );
			$round_trip = ( $row && (string) $row->reply_mode === 'direct' );
			$wpdb->delete( $tbl, array( 'id' => $insert_id ) );
		}
		$steps[] = $s = array(
			'label'  => 'Runtime · INSERT/SELECT/DELETE',
			'status' => $round_trip ? 'pass' : 'fail',
			'detail' => $round_trip ? 'round-trip id=' . $insert_id : 'failed: ' . $wpdb->last_error,
		);
		$ctx->emit_step( $s );

		if ( ! $ok_accent || ! $round_trip ) {
			return array(
				'status'   => 'fail',
				'summary'  => 'Runtime smoke fail.',
				'error'    => $ok_accent ? 'round_trip_failed' : 'strip_accents_failed',
				'steps'    => $steps,
			);
		}

		// Migration option (non-blocking — informational).
		$migrated = (int) get_option( BizCity_CG_Flow_Installer::OPT_MIGRATED );
		$count    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl}" );

		// Anti-duplicate guard: interim wp_bizcity_cg_flows MUST NOT exist after migration.
		$interim         = BizCity_CG_Flow_Installer::interim_table();
		$interim_exists  = BizCity_CG_Flow_Installer::table_exists( $interim );
		$steps[]         = $s = array(
			'label'  => 'Runtime · interim table dropped',
			'status' => $interim_exists ? 'fail' : 'pass',
			'detail' => $interim_exists
				? "DUPLICATE: {$interim} still exists — should have been RENAMEd into {$tbl}. Run Flows admin page to trigger cleanup."
				: "OK — {$interim} không tồn tại (canonical = {$tbl} only).",
		);
		$ctx->emit_step( $s );

		return array(
			'status'  => $interim_exists ? 'fail' : 'pass',
			'summary' => sprintf( 'OK — %d rows · migrated=%d · interim_dropped=%s', $count, $migrated, $interim_exists ? 'NO' : 'YES' ),
			'steps'   => $steps,
		);
	}

	public function cleanup(): void {
		// Per-row deletion already happens inside run(). Belt-and-braces wipe
		// in case a previous run aborted mid-way.
		global $wpdb;
		if ( ! class_exists( 'BizCity_CG_Flow_Installer' ) ) { return; }
		$tbl = BizCity_CG_Flow_Installer::table();
		if ( ! BizCity_CG_Flow_Installer::table_exists( $tbl ) ) { return; }
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$tbl} WHERE message LIKE %s",
			'__healthtest_%'
		) );
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_CG_Flows';
	return $list;
} );
