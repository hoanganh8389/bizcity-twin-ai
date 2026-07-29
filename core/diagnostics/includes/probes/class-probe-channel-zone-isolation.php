<?php
/**
 * Probe: Channel · Zone Isolation (Phase 0.40 G0.4).
 *
 * Verifies R-ZONE-2 discriminator is correctly in place:
 *   Disk   — universal listener + zalo-bot gateway/guru bridge files exist.
 *   Loader — guard code present in source (static analysis).
 *   Runtime— synthetic ZALO_BOT payload does NOT create CRM conversation;
 *            synthetic zalo_oa payload does NOT fire automation-admin bridge.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      PHASE-0.40.G0.4 (2026-06-07)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Probe_Channel_Zone_Isolation' ) ) { return; }

// [2026-06-07 Johnny Chu] PHASE-0.40 G0.4 — DDV zone isolation probe.
final class BizCity_Probe_Channel_Zone_Isolation implements BizCity_Diagnostics_Probe {

	public function id(): string          { return 'core.channel.zone_isolation'; }
	public function label(): string       { return 'Channel · Zone Isolation (CRM vs Admin)'; }
	public function description(): string { return 'R-ZONE-2: ZALO_BOT inbound KHÔNG vào CRM Inbox; zalo_oa/zalo_personal KHÔNG fire automation-admin bridge (discriminator guard).'; }
	public function severity(): string    { return 'critical'; }
	public function order(): int          { return 43; }
	public function icon(): string        { return 'shield-check'; }
	public function estimate_ms(): int    { return 200; }

	public function precondition() {
		return true;
	}

	public function run( $ctx ): array {
		$rows = array();
		$pass = true;

		$plugin_root = dirname( __DIR__, 3 ); // core/diagnostics/includes/probes → core → plugin root

		// ── LAYER 1: DISK ─────────────────────────────────────────────────────
		$listener_file     = $plugin_root . '/channel-gateway/includes/class-universal-channel-listener.php';
		$guru_bridge_file  = WP_PLUGIN_DIR . '/bizcity-twin-ai/plugins/bizcity-zalo-bot/includes/class-guru-bridge.php';
		$gw_bridge_file    = WP_PLUGIN_DIR . '/bizcity-twin-ai/plugins/bizcity-zalo-bot/includes/class-gateway-bridge.php';
		$wh_file           = WP_PLUGIN_DIR . '/bizcity-twin-ai/plugins/bizcity-zalo-bot/includes/class-webhook-handler.php';

		$listener_ok    = file_exists( $listener_file );
		$guru_ok        = file_exists( $guru_bridge_file );
		$gw_ok          = file_exists( $gw_bridge_file );
		$wh_ok          = file_exists( $wh_file );
		$disk_ok        = $listener_ok && $guru_ok && $gw_ok && $wh_ok;

		if ( ! $disk_ok ) { $pass = false; }
		$rows[] = array(
			'label'  => 'Disk: 4 zone-routing files exist',
			'status' => $disk_ok ? 'pass' : 'fail',
			'detail' => $disk_ok ? 'listener + guru-bridge + gateway-bridge + webhook-handler' : implode( ', ', array_filter( array(
				$listener_ok ? '' : 'class-universal-channel-listener.php',
				$guru_ok     ? '' : 'class-guru-bridge.php',
				$gw_ok       ? '' : 'class-gateway-bridge.php',
				$wh_ok       ? '' : 'class-webhook-handler.php',
			) ) ) . ' missing',
		);

		// ── LAYER 2: LOADER (static guard check) ──────────────────────────────
		$listener_src    = $listener_ok ? (string) file_get_contents( $listener_file ) : '';
		$guru_src        = $guru_ok     ? (string) file_get_contents( $guru_bridge_file ) : '';
		$gw_src          = $gw_ok       ? (string) file_get_contents( $gw_bridge_file ) : '';
		$wh_src          = $wh_ok       ? (string) file_get_contents( $wh_file ) : '';

		// G0.1: Universal Listener bails on ZALO_BOT.
		$guard_listener = $listener_src && strpos( $listener_src, "=== 'ZALO_BOT'" ) !== false;
		// G0.2: guru-bridge bails on zalo_oa/zalo_personal.
		$guard_guru     = $guru_src && strpos( $guru_src, "'zalo_oa'" ) !== false && strpos( $guru_src, "'zalo_personal'" ) !== false;
		// G0.2: gateway-bridge bails on zalo_oa/zalo_personal.
		$guard_gw       = $gw_src && strpos( $gw_src, "'zalo_oa'" ) !== false && strpos( $gw_src, "'zalo_personal'" ) !== false;
		// G0.3: webhook-handler injects platform=ZALO_BOT field.
		$guard_wh       = $wh_src && strpos( $wh_src, "'platform'" ) !== false && strpos( $wh_src, "'ZALO_BOT'" ) !== false;

		$loader_ok = $guard_listener && $guard_guru && $guard_gw && $guard_wh;
		if ( ! $loader_ok ) { $pass = false; }
		$rows[] = array(
			'label'  => 'Loader: R-ZONE-2 guard code present in all 4 files',
			'status' => $loader_ok ? 'pass' : 'fail',
			'detail' => $loader_ok ? 'all guards confirmed' : implode( '; ', array_filter( array(
				$guard_listener ? '' : 'Universal Listener missing ZALO_BOT bail',
				$guard_guru     ? '' : 'Guru Bridge missing zalo_oa/zalo_personal bail',
				$guard_gw       ? '' : 'Gateway Bridge missing zalo_oa/zalo_personal bail',
				$guard_wh       ? '' : 'Webhook Handler missing platform=ZALO_BOT field',
			) ) ),
		);

		// [2026-07-21 Johnny Chu] PHASE-ZALOBOT-GROUP W6 — DDV group/private conversation contract for MVP.
		$group_contract_listener = $listener_src && strpos( $listener_src, 'conversation_chat_id' ) !== false && strpos( $listener_src, 'provider_chat_id' ) !== false && strpos( $listener_src, 'chat_kind' ) !== false;
		$group_contract_gw       = $gw_src && strpos( $gw_src, 'conversation_chat_id' ) !== false && strpos( $gw_src, 'provider_chat_id' ) !== false && strpos( $gw_src, 'chat_kind' ) !== false;
		$group_contract_wh       = $wh_src && strpos( $wh_src, 'provider_chat_type' ) !== false && strpos( $wh_src, 'message_text_clean' ) !== false && strpos( $wh_src, 'sender_user_id' ) !== false;
		$group_contract_ok       = $group_contract_listener && $group_contract_gw && $group_contract_wh;
		if ( ! $group_contract_ok ) { $pass = false; }
		$rows[] = array(
			'label'  => 'Loader: ZaloBot group/private conversation contract present',
			'status' => $group_contract_ok ? 'pass' : 'fail',
			'detail' => $group_contract_ok ? 'UCL + gateway bridge + webhook handler preserve sender/target split.' : implode( '; ', array_filter( array(
				$group_contract_listener ? '' : 'UCL missing conversation metadata pass-through',
				$group_contract_gw       ? '' : 'Gateway bridge missing explicit group/private target fields',
				$group_contract_wh       ? '' : 'Webhook handler missing group/private derivation fields',
			) ) ),
		);

		// ── LAYER 3: RUNTIME ──────────────────────────────────────────────────
		// Synthetic test: fire bizcity_zalo_message_received with platform=ZALO_BOT
		// and verify Universal Listener does NOT dispatch waic_twf_process_flow CRM path.
		$crm_dispatched = false;
		$admin_dispatched = false;

		// Capture waic_twf_process_flow dispatch.
		$capture_crm = static function ( $trigger, $payload ) use ( &$crm_dispatched ) {
			// [2026-06-07 Johnny Chu] PHASE-0.40 G0.4 — synthetic capture, read-only
			if ( $trigger === 'bizcity_zalo_message_received'
				&& isset( $payload['platform'] ) && $payload['platform'] === 'ZALO_BOT'
			) {
				$crm_dispatched = true;
			}
		};
		add_action( 'waic_twf_process_flow', $capture_crm, 1, 2 );

		// Fire ZALO_BOT synthetic event.
		do_action( 'bizcity_zalo_message_received', array(
			'platform'      => 'ZALO_BOT',
			'code'          => 'zalo_bot',
			'bot_id'        => 0,
			'from_user_id'  => '__healthtest_zone_u1',
			'message_id'    => '__healthtest_zone_m1',
			'message_text'  => '__healthtest_zone_isolation',
		) );

		remove_action( 'waic_twf_process_flow', $capture_crm, 1 );

		$zone2_isolated = ! $crm_dispatched;
		if ( ! $zone2_isolated ) { $pass = false; }
		$rows[] = array(
			'label'  => 'Runtime: ZALO_BOT synthetic event KHÔNG dispatch CRM waic_twf_process_flow',
			'status' => $zone2_isolated ? 'pass' : 'fail',
			'detail' => $zone2_isolated
				? 'Universal Listener correctly bailed — ZALO_BOT stayed in Zone 2'
				: 'ZALO_BOT event leaked into waic_twf_process_flow (CRM Inbox) — R-ZONE-2 broken',
		);

		// Synthetic test: fire with code=zalo_oa, verify guru/gateway bridge bails.
		// (We check static guard above; here we verify Universal Listener DOES pass it through.)
		$crm_dispatched_oa = false;
		$capture_crm_oa = static function ( $trigger, $payload ) use ( &$crm_dispatched_oa ) {
			if ( $trigger === 'bizcity_zalo_message_received'
				&& isset( $payload['code'] ) && $payload['code'] === 'zalo_oa'
			) {
				$crm_dispatched_oa = true;
			}
		};
		add_action( 'waic_twf_process_flow', $capture_crm_oa, 1, 2 );

		do_action( 'bizcity_zalo_message_received', array(
			'platform'      => 'ZALO_OA',
			'code'          => 'zalo_oa',
			'account_id'    => '__healthtest_zone_oa1',
			'from_user_id'  => '__healthtest_zone_u2',
			'message_id'    => '__healthtest_zone_m2',
			'message_text'  => '__healthtest_zone_crm',
		) );

		remove_action( 'waic_twf_process_flow', $capture_crm_oa, 1 );

		$zone1_routed = $crm_dispatched_oa;
		if ( ! $zone1_routed ) { $pass = false; }
		$rows[] = array(
			'label'  => 'Runtime: zalo_oa synthetic event DOES reach waic_twf_process_flow (Zone 1 CRM)',
			'status' => $zone1_routed ? 'pass' : 'fail',
			'detail' => $zone1_routed
				? 'zalo_oa correctly routed through CRM Inbox path (Zone 1)'
				: 'zalo_oa event NOT reaching waic_twf_process_flow — CRM Inbox broken for OA',
		);

		// [2026-07-21 Johnny Chu] PHASE-ZALOBOT-GROUP W6 — runtime proof that outbound parser targets group id, not sender id.
		$group_parse_ok = false;
		$group_parse_detail = 'BizCity_Zalo_Bot_Gateway_Bridge not loaded.';
		if ( ! class_exists( 'BizCity_Zalo_Bot_Gateway_Bridge', false ) && $gw_ok ) {
			require_once $gw_bridge_file;
		}
		if ( class_exists( 'BizCity_Zalo_Bot_Gateway_Bridge', false ) ) {
			try {
				$bridge = BizCity_Zalo_Bot_Gateway_Bridge::instance();
				$method = new ReflectionMethod( $bridge, 'parse_zalobot_chat_id' );
				$method->setAccessible( true );
				$parsed = $method->invoke( $bridge, 'zalobot_7_group_zgr-healthtest' );
				$group_parse_ok = is_array( $parsed )
					&& (int) ( $parsed['bot_id'] ?? 0 ) === 7
					&& (string) ( $parsed['chat_kind'] ?? '' ) === 'group'
					&& (string) ( $parsed['zalo_user_id'] ?? '' ) === 'zgr-healthtest';
				$group_parse_detail = $group_parse_ok ? 'Parsed explicit group chat target zgr-healthtest.' : 'Parser returned unexpected shape for zalobot_7_group_zgr-healthtest.';
			} catch ( Throwable $e ) {
				$group_parse_detail = 'Parser reflection failed: ' . $e->getMessage();
			}
		}
		if ( ! $group_parse_ok ) { $pass = false; }
		$rows[] = array(
			'label'  => 'Runtime: ZaloBot explicit group chat_id parses to group target',
			'status' => $group_parse_ok ? 'pass' : 'fail',
			'detail' => $group_parse_detail,
		);

		return array(
			'status'  => $pass ? 'pass' : 'fail',
			'summary' => $pass
				? 'Zone isolation OK: ZALO_BOT stays in Zone 2 (admin/automation); zalo_oa routes to Zone 1 (CRM Inbox).'
				: 'Zone isolation FAIL — check guard code in channel-gateway + bizcity-zalo-bot.',
			'steps'   => $rows,
		);
	}

	public function cleanup(): void {
		// No artifacts created — synthetic events are fire-and-forget.
	}
}

// Register probe.
add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Channel_Zone_Isolation';
	return $list;
} );
