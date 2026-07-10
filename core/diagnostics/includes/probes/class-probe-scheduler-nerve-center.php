<?php
/**
 * BizCity Diagnostics — scheduler.nerve_center probe.
 *
 * R-DDV evidence cho Wave SCH-NC (PHASE-SCHEDULER-AS-NERVE-CENTER):
 *
 *   W2: Adapter Registry + Interface + 6 built-in adapters loaded.
 *   W3: Manager validate qua adapter + fire `bizcity_scheduler_event_completed`
 *       hook khi status: active → done.
 *   W4: BizCity_Scheduler_Completion_Notifier listener attached @10.
 *
 * 11 read-only steps (W3 step có DB round-trip nhưng tự cleanup).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      Wave SCH-NC W9 (2026-06-03)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';


// [2026-06-08 Johnny Chu] HOTFIX — double-load guard (bootstrap may include via filter AND direct require).
if ( class_exists( 'BizCity_Probe_Scheduler_Nerve_Center', false ) ) {
	return;
}

final class BizCity_Probe_Scheduler_Nerve_Center implements BizCity_Diagnostics_Probe {

	/** @var int|null Event id created during W3 step (cleanup target). */
	private $cleanup_event_id = null;

	public function id(): string          { return 'scheduler.nerve_center'; }
	public function label(): string       { return 'Scheduler · Nerve Center (SCH-NC W2/W3/W4)'; }
	public function description(): string {
		return 'Verify Adapter Registry (6 built-in), Manager validate hook, completion-notifier listener wired & event_completed fires on status flip.';
	}
	public function severity(): string    { return 'critical'; }
	public function order(): int          { return 50; }
	public function icon(): string        { return 'calendar-alt'; }
	public function estimate_ms(): int    { return 600; }

	public function precondition() {
		// [2026-06-03 Johnny Chu] SCH-NC W9 — guard required classes.
		foreach ( array(
			'BizCity_Scheduler_Manager',
			'BizCity_Scheduler_Adapter_Registry',
			'BizCity_Scheduler_Completion_Notifier',
			'BizCity_Scheduler_Inbound_Provenance',
		) as $cls ) {
			if ( ! class_exists( $cls ) ) {
				return new WP_Error( 'class_missing', $cls . ' chưa load.' );
			}
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-06-03 Johnny Chu] SCH-NC W9 — 11 step probe.
		$steps  = array();
		$all_ok = true;

		$dir = dirname( __DIR__, 3 ) . '/scheduler/includes';

		// ── Step 1: Disk — interface file ──────────────────────────────
		$f = $dir . '/interface-scheduler-event-adapter.php';
		$ok = is_readable( $f );
		$steps[] = $s = $this->step( 'Disk: interface-scheduler-event-adapter.php', $ok, $ok ? $f : 'NOT found at ' . $f );
		$ctx->emit_step( $s ); $all_ok &= $ok;

		// ── Step 2: Disk — registry file ───────────────────────────────
		$f = $dir . '/class-scheduler-adapter-registry.php';
		$ok = is_readable( $f );
		$steps[] = $s = $this->step( 'Disk: class-scheduler-adapter-registry.php', $ok, $ok ? $f : 'NOT found at ' . $f );
		$ctx->emit_step( $s ); $all_ok &= $ok;

		// ── Step 3: Disk — completion notifier ─────────────────────────
		$f = $dir . '/class-scheduler-completion-notifier.php';
		$ok = is_readable( $f );
		$steps[] = $s = $this->step( 'Disk: class-scheduler-completion-notifier.php', $ok, $ok ? $f : 'NOT found at ' . $f );
		$ctx->emit_step( $s ); $all_ok &= $ok;

		// ── Step 4: Loader — interface + classes ───────────────────────
		$ok = interface_exists( 'BizCity_Scheduler_Event_Adapter' )
			&& class_exists( 'BizCity_Scheduler_Adapter_Base' )
			&& class_exists( 'BizCity_Scheduler_Completion_Notifier' );
		$steps[] = $s = $this->step( 'Loader: interface + base + notifier', $ok, $ok ? 'OK' : 'Missing class_exists for one of them' );
		$ctx->emit_step( $s ); $all_ok &= $ok;

		// ── Step 5: Registry — 6 built-in event_types registered ───────
		$expected = array( 'fb_post', 'web_post', 'reminder_zalo', 'telegram_send', 'reminder_personal', 'automation_workflow' );
		$got      = BizCity_Scheduler_Adapter_Registry::event_types();
		$missing  = array_diff( $expected, $got );
		$ok       = empty( $missing );
		$steps[] = $s = $this->step(
			'Registry: 6 built-in adapters registered',
			$ok,
			$ok ? 'event_types=' . implode( ',', $got ) : 'MISSING: ' . implode( ',', $missing )
		);
		$ctx->emit_step( $s ); $all_ok &= $ok;

		// ── Step 6: Registry — get(fb_post) returns adapter ────────────
		$adp = BizCity_Scheduler_Adapter_Registry::get( 'fb_post' );
		$ok  = $adp instanceof BizCity_Scheduler_Event_Adapter && $adp->event_type() === 'fb_post';
		$steps[] = $s = $this->step( 'Registry: get(fb_post) → adapter instance', $ok,
			$ok ? 'class=' . get_class( $adp ) . ' label="' . $adp->label() . '"' : 'NULL or wrong type' );
		$ctx->emit_step( $s ); $all_ok &= $ok;

		// ── Step 7: validate(reminder_personal) reject thiếu inbound ───
		// metadata.inbound là array nhưng rỗng → base validate pass (key có),
		// adapter specific validate bắt “thiếu platform/chat_id”.
		$bad = BizCity_Scheduler_Adapter_Registry::validate( 'reminder_personal', array(
			'event_type' => 'reminder_personal',
			'metadata'   => array( 'inbound' => array() ),
		) );
		$ok  = is_wp_error( $bad ) && $bad->get_error_code() === 'sched_adapter_inbound_required';
		$steps[] = $s = $this->step(
			'Adapter: reminder_personal reject metadata.inbound rỗng',
			$ok,
			$ok ? 'Got expected WP_Error code sched_adapter_inbound_required'
			    : 'Unexpected: ' . ( is_wp_error( $bad ) ? $bad->get_error_code() : wp_json_encode( $bad ) )
		);
		$ctx->emit_step( $s ); $all_ok &= $ok;

		// ── Step 8: validate(reminder_personal) accept payload đủ ──────
		$good = BizCity_Scheduler_Adapter_Registry::validate( 'reminder_personal', array(
			'event_type' => 'reminder_personal',
			'metadata'   => array( 'inbound' => array( 'platform' => 'ZALO', 'chat_id' => 'zalo:123' ) ),
		) );
		$ok = $good === true;
		$steps[] = $s = $this->step( 'Adapter: reminder_personal accept inbound đủ', $ok,
			$ok ? 'true' : ( is_wp_error( $good ) ? $good->get_error_message() : wp_json_encode( $good ) ) );
		$ctx->emit_step( $s ); $all_ok &= $ok;

		// ── Step 9: hook listener — completion notifier attached ───────
		BizCity_Scheduler_Completion_Notifier::init(); // idempotent
		$ok = (bool) has_action( 'bizcity_scheduler_event_completed',
			array( 'BizCity_Scheduler_Completion_Notifier', 'on_completed' ) );
		$steps[] = $s = $this->step( 'Hook: notifier on_completed attached @10', $ok,
			$ok ? 'has_action=true' : 'NOT attached — check Completion_Notifier::init() bootstrap call' );
		$ctx->emit_step( $s ); $all_ok &= $ok;

		// ── Step 10: Manager fires event_completed when status → done ──
		// [2026-06-03 Johnny Chu] SCH-NC W9 — DB round-trip; tự cleanup.
		$mgr = BizCity_Scheduler_Manager::instance();
		$captured = (object) array( 'fired' => false, 'event_id' => 0 );
		$listener = function ( $event_id, $event ) use ( $captured ) {
			$captured->fired    = true;
			$captured->event_id = (int) $event_id;
		};
		add_action( 'bizcity_scheduler_event_completed', $listener, 99, 2 );

		$user_id = get_current_user_id() ?: 1;
		$created = $mgr->create_event( array(
			'user_id'    => $user_id,
			'title'      => '[SCH-NC probe] event_completed test',
			'start_at'   => current_time( 'mysql' ),
			'event_type' => 'meeting', // không bắt validate strict
			'status'     => 'active',
			'source'     => 'user',
		) );
		$create_ok = is_int( $created ) && $created > 0;
		if ( $create_ok ) {
			$this->cleanup_event_id = $created;
			$mgr->update_event( $created, array( 'status' => 'done' ) );
		}
		remove_action( 'bizcity_scheduler_event_completed', $listener, 99 );

		$ok = $create_ok && $captured->fired && $captured->event_id === $created;
		$steps[] = $s = $this->step(
			'Manager: status active→done fires event_completed',
			$ok,
			$create_ok
				? ( $captured->fired
					? 'Fired with event_id=' . $captured->event_id
					: 'Create OK (#' . $created . ') NHƯNG hook KHÔNG fire — check update_event status flip logic' )
				: ( is_wp_error( $created ) ? 'create_event WP_Error: ' . $created->get_error_message() : 'create_event returned ' . wp_json_encode( $created ) )
		);
		$ctx->emit_step( $s ); $all_ok &= $ok;

		// ── Step 11: W5 — Inbound Provenance helper loaded + builds canonical block.
		// [2026-06-03 Johnny Chu] SCH-NC W9 — verify W5 helper.
		$helper_ok = class_exists( 'BizCity_Scheduler_Inbound_Provenance' );
		$steps[] = $s = $this->step(
			'W5: BizCity_Scheduler_Inbound_Provenance loaded',
			$helper_ok,
			$helper_ok ? 'OK' : 'class not found — check scheduler/bootstrap.php require'
		);
		$ctx->emit_step( $s ); $all_ok &= $helper_ok;

		// ── Step 12: W5 — build() returns canonical inbound block ──────
		if ( $helper_ok ) {
			$blk = BizCity_Scheduler_Inbound_Provenance::build( 'ZALO', 'zalo:abc', array(
				'user_id'    => 'zalo:abc',
				'account_id' => 42,
				'raw_text'   => 'nhắc tôi 9h sáng mai',
				'intent_tag' => 'reminder',
			) );
			$ok = is_array( $blk )
				&& $blk['platform'] === 'ZALO'
				&& $blk['chat_id'] === 'zalo:abc'
				&& $blk['account_id'] === 42
				&& ! empty( $blk['captured_at'] )
				&& BizCity_Scheduler_Inbound_Provenance::is_valid( $blk );
			$steps[] = $s = $this->step(
				'W5: build() returns canonical {platform, chat_id, captured_at, …}',
				$ok,
				$ok ? 'platform=ZALO chat_id=zalo:abc captured_at=' . $blk['captured_at']
				    : 'Block sai schema: ' . wp_json_encode( $blk )
			);
			$ctx->emit_step( $s ); $all_ok &= $ok;
		}

		// ── Step 13: W6 — HIL Router + Cron loaded ─────────────────────
		// [2026-06-03 Johnny Chu] SCH-NC W9 — verify W6 components.
		$hil_ok = class_exists( 'BizCity_Scheduler_HIL_Router' )
			&& class_exists( 'BizCity_Scheduler_HIL_Cron' );
		$steps[] = $s = $this->step(
			'W6: HIL Router + HIL Cron loaded',
			$hil_ok,
			$hil_ok ? 'OK' : 'BizCity_Scheduler_HIL_Router/Cron không load — check scheduler/bootstrap.php'
		);
		$ctx->emit_step( $s ); $all_ok &= $hil_ok;

		// ── Step 14: W6 — HIL Router attached @5 trên channel inbound ──
		if ( $hil_ok ) {
			BizCity_Scheduler_HIL_Router::init();
			$prio = has_action( 'bizcity_channel_message_received',
				array( 'BizCity_Scheduler_HIL_Router', 'maybe_handle' ) );
			$ok = $prio === 5;
			$steps[] = $s = $this->step(
				'W6: HIL listener attached @5 (before automation matcher @30)',
				$ok,
				$ok ? 'has_action priority=5' : 'priority=' . wp_json_encode( $prio ) . ' (expected 5)'
			);
			$ctx->emit_step( $s ); $all_ok &= $ok;
		}

		// ── Step 15: W6 — keyword classifier matrix ────────────────────
		if ( $hil_ok ) {
			$cases = array(
				'OK'           => 'confirm',
				'ok'           => 'confirm',
				'✅'           => 'confirm',
				'đồng ý'       => 'confirm',
				'chốt'         => 'confirm',
				'huỷ'          => 'cancel',
				'hủy'          => 'cancel',
				'❌'           => 'cancel',
				'sửa 19h30'    => 'edit',
				'sua ngay mai 8h' => 'edit',
				'tao muon dat lich'  => '', // không match
			);
			$wrong = array();
			foreach ( $cases as $input => $expect ) {
				$got = BizCity_Scheduler_HIL_Router::classify_reply( $input );
				if ( $got !== $expect ) {
					$wrong[] = '"' . $input . '"=>' . $got . '(want ' . $expect . ')';
				}
			}
			$ok = empty( $wrong );
			$steps[] = $s = $this->step(
				'W6: classify_reply OK/Hủy/Sửa keyword matrix',
				$ok,
				$ok ? 'all ' . count( $cases ) . ' cases pass' : 'mismatched: ' . implode( '; ', $wrong )
			);
			$ctx->emit_step( $s ); $all_ok &= $ok;
		}

		// ── Step 16: W6 — TwinBrain tool registered ────────────────────
		$tool_class_ok = class_exists( 'BizCity_TwinBrain_Scheduler_Tool_Set_Reminder' );
		$registry = apply_filters( 'bizcity_twin_register_tool', array() );
		$tool_ok  = $tool_class_ok
			&& is_array( $registry )
			&& isset( $registry['scheduler_set_reminder'] )
			&& $registry['scheduler_set_reminder'] instanceof BizCity_Twin_Tool;
		$steps[] = $s = $this->step(
			'W6: TwinBrain tool scheduler_set_reminder registered',
			$tool_ok,
			$tool_ok ? 'class=' . get_class( $registry['scheduler_set_reminder'] )
			         : 'class_exists=' . ( $tool_class_ok ? 'yes' : 'no' ) . ' registry_keys=' . implode( ',', array_keys( (array) $registry ) )
		);
		$ctx->emit_step( $s ); $all_ok &= $tool_ok;

		// ── Step 17: W6 — HIL timeout cron scheduled ───────────────────
		if ( $hil_ok ) {
			$next = wp_next_scheduled( BizCity_Scheduler_HIL_Cron::CRON_HOOK );
			$ok   = is_int( $next ) && $next > 0;
			$steps[] = $s = $this->step(
				'W6: HIL timeout cron scheduled',
				$ok,
				$ok ? 'next_run=' . gmdate( 'Y-m-d H:i:s', $next ) : 'wp_next_scheduled returned ' . wp_json_encode( $next )
			);
			$ctx->emit_step( $s ); $all_ok &= $ok;
		}

		// ── Step 18: W7 REST GET /stats registered ────────────────────
		// [2026-06-03 Johnny Chu] SCH-NC W7 — verify stats endpoint live + canonical shape.
		$server = rest_get_server();
		$routes = $server ? $server->get_routes() : array();
		$route_ok = isset( $routes['/bizcity-scheduler/v1/stats'] );
		$steps[] = $s = $this->step(
			'W7: REST GET /bizcity-scheduler/v1/stats registered',
			$route_ok,
			$route_ok ? 'route registered' : 'route MISSING — check class-scheduler-rest-api.php'
		);
		$ctx->emit_step( $s ); $all_ok &= $route_ok;

		// ── Step 19: dispatch /stats?scope=user → 200 + canonical shape ─
		if ( $route_ok && $server ) {
			$req = new \WP_REST_Request( 'GET', '/bizcity-scheduler/v1/stats' );
			$req->set_query_params( array( 'scope' => 'user' ) );
			$resp   = $server->dispatch( $req );
			$data   = is_object( $resp ) && method_exists( $resp, 'get_data' ) ? $resp->get_data() : null;
			$status = is_object( $resp ) ? (int) $resp->get_status() : 0;
			$shape_ok = is_array( $data )
				&& ! empty( $data['ok'] )
				&& isset( $data['stats']['total'] )
				&& isset( $data['stats']['by_status'] )
				&& isset( $data['stats']['by_type'] )
				&& isset( $data['stats']['by_source'] );
			$ok = ( $status === 200 && $shape_ok );
			$steps[] = $s = $this->step(
				'W7: GET /stats?scope=user returns 200 + canonical shape',
				$ok,
				$ok
					? 'status=200 stats_keys=' . implode( ',', array_keys( $data['stats'] ) )
					: 'status=' . $status . ' shape_ok=' . ( $shape_ok ? 'yes' : 'no' )
			);
			$ctx->emit_step( $s ); $all_ok &= $ok;
		}

		// ── Step 20: schema present ─ kiểm table + version option (hỗn hợp) ─
		// DCL declares `bizcity_scheduler_db_version` nhưng manager thực tế set
		// `bizcity_scheduler_schema_ver` (int). Chấp nhận bất kỳ trong hai +
		// fallback kiểm table tồn tại.
		global $wpdb;
		$tbl       = method_exists( BizCity_Scheduler_Manager::instance(), 'get_table' )
			? BizCity_Scheduler_Manager::instance()->get_table()
			: $wpdb->prefix . 'bizcity_crm_events';
		$tbl_found = bizcity_tbl_exists( $tbl ); // [2026-06-21 Johnny Chu] R-SHOW-TABLES
		$ver_db    = (string) get_option( 'bizcity_scheduler_db_version', '' );
		$ver_int   = (int) get_option( 'bizcity_scheduler_schema_ver', 0 );
		$ver_ok    = ( $ver_db !== '' && version_compare( $ver_db, '3.0.0', '>=' ) ) || ( $ver_int >= 3 );
		$ok        = $tbl_found && $ver_ok;
		$steps[] = $s = $this->step(
			'DCL: scheduler schema present (table + version)',
			$ok,
			$ok
				? 'table=' . $tbl . ' schema_ver=' . $ver_int . ' db_version=' . ( $ver_db !== '' ? $ver_db : 'unset' )
				: 'table_found=' . ( $tbl_found ? 'yes' : 'NO ' . $tbl ) . ' schema_ver=' . $ver_int . ' db_version=' . wp_json_encode( $ver_db )
		);
		$ctx->emit_step( $s ); $all_ok &= $ok;

		return $this->build_result( $steps, (bool) $all_ok );
	}

	public function cleanup(): void {
		// [2026-06-03 Johnny Chu] SCH-NC W9 — drop test event row.
		if ( $this->cleanup_event_id !== null && $this->cleanup_event_id > 0
			&& class_exists( 'BizCity_Scheduler_Manager' ) ) {
			BizCity_Scheduler_Manager::instance()->delete_event( (int) $this->cleanup_event_id );
			$this->cleanup_event_id = null;
		}
	}

	// ─── helpers ─────────────────────────────────────────────────────────

	private function step( $label, $ok, $detail ) {
		return array(
			'label'  => $label,
			'status' => $ok ? 'pass' : 'fail',
			'detail' => $detail,
		);
	}

	private function build_result( array $steps, $all_ok ) {
		$failed = array();
		foreach ( $steps as $s ) {
			if ( is_array( $s ) && ( $s['status'] ?? '' ) === 'fail' ) {
				$failed[] = $s['label'] . ( ! empty( $s['detail'] ) ? ' (' . $s['detail'] . ')' : '' );
			}
		}
		return array(
			'status'  => $all_ok ? 'pass' : 'fail',
			'summary' => $all_ok
				? 'Scheduler Nerve Center: registry + 6 adapters + validate + completion-notifier hook + status-flip event firing OK.'
				: 'SCH-NC FAIL — ' . count( $failed ) . ' step(s): ' . implode( ' | ', $failed ),
			'steps'   => $steps,
		);
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = new BizCity_Probe_Scheduler_Nerve_Center();
	return $list;
} );
