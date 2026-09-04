<?php
/**
 * Probe: Channel · Zone Isolation (Phase 0.40 G0.4).
 *
 * Verifies R-ZONE-2 discriminator is correctly in place:
 *   Disk   — universal listener + zalo-bot gateway/guru bridge files exist.
 *   Loader — guard code present in source (static analysis).
 *   Runtime— synthetic ZALO_BOT payload is accepted by the CRM Operations
 *            contract but does not enter the Customer Care flow; synthetic
 *            zalo_oa payload does not fire the automation-admin bridge.
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
	public function description(): string { return 'R-ZONE-2: ZALO_BOT vào CRM Admin Operations nhưng không vào Customer Care; zalo_oa/zalo_personal không fire automation-admin bridge.'; }
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
		$crm_root          = dirname( $plugin_root ) . '/plugins/bizcity-twin-crm';
		$crm_contract_file = $crm_root . '/includes/inbox/class-channel-contract.php';
		$crm_adapter_file  = $crm_root . '/includes/inbox/adapters/class-adapter-zalo-bot.php';
		$crm_listener_file = $crm_root . '/includes/class-ai-autoreply-listener.php';
		$crm_ingestor_file = $crm_root . '/includes/inbox/class-fb-ingestor.php';

		$listener_ok    = file_exists( $listener_file );
		$guru_ok        = file_exists( $guru_bridge_file );
		$gw_ok          = file_exists( $gw_bridge_file );
		$wh_ok          = file_exists( $wh_file );
		$crm_contract_ok = file_exists( $crm_contract_file );
		$crm_adapter_ok  = file_exists( $crm_adapter_file );
		$crm_listener_ok = file_exists( $crm_listener_file );
		$crm_ingestor_ok = file_exists( $crm_ingestor_file );
		$disk_ok        = $listener_ok && $guru_ok && $gw_ok && $wh_ok && $crm_contract_ok && $crm_adapter_ok && $crm_listener_ok && $crm_ingestor_ok;

		if ( ! $disk_ok ) { $pass = false; }
		$rows[] = array(
			'label'  => 'Disk: zone-routing and CRM Operations files exist',
			'status' => $disk_ok ? 'pass' : 'fail',
			'detail' => $disk_ok ? 'listener + guru-bridge + gateway-bridge + webhook-handler + CRM contract/adapter/listener' : implode( ', ', array_filter( array(
				$listener_ok ? '' : 'class-universal-channel-listener.php',
				$guru_ok     ? '' : 'class-guru-bridge.php',
				$gw_ok       ? '' : 'class-gateway-bridge.php',
				$wh_ok       ? '' : 'class-webhook-handler.php',
				$crm_contract_ok ? '' : 'class-channel-contract.php',
				$crm_adapter_ok  ? '' : 'class-adapter-zalo-bot.php',
				$crm_listener_ok ? '' : 'class-ai-autoreply-listener.php',
				$crm_ingestor_ok ? '' : 'inbox/class-fb-ingestor.php',
			) ) ) . ' missing',
		);

		// ── LAYER 2: LOADER (static guard check) ──────────────────────────────
		$listener_src    = $listener_ok ? (string) file_get_contents( $listener_file ) : '';
		$guru_src        = $guru_ok     ? (string) file_get_contents( $guru_bridge_file ) : '';
		$gw_src          = $gw_ok       ? (string) file_get_contents( $gw_bridge_file ) : '';
		$wh_src          = $wh_ok       ? (string) file_get_contents( $wh_file ) : '';
		$crm_listener_src = $crm_listener_ok ? (string) file_get_contents( $crm_listener_file ) : '';
		$crm_ingestor_src = $crm_ingestor_ok ? (string) file_get_contents( $crm_ingestor_file ) : '';

		// G0.1: Universal Listener bails on ZALO_BOT Customer Care handling.
		$guard_listener = $listener_src && strpos( $listener_src, "=== 'ZALO_BOT'" ) !== false;
		// G0.2: guru-bridge bails on zalo_oa/zalo_personal.
		$guard_guru     = $guru_src && strpos( $guru_src, "'zalo_oa'" ) !== false && strpos( $guru_src, "'zalo_personal'" ) !== false;
		// G0.2: gateway-bridge bails on zalo_oa/zalo_personal.
		$guard_gw       = $gw_src && strpos( $gw_src, "'zalo_oa'" ) !== false && strpos( $gw_src, "'zalo_personal'" ) !== false;
		// G0.3: webhook-handler injects platform=ZALO_BOT field.
		$guard_wh       = $wh_src && strpos( $wh_src, "'platform'" ) !== false && strpos( $wh_src, "'ZALO_BOT'" ) !== false;

		$guard_crm = $crm_listener_src && strpos( $crm_listener_src, "'zalo_bot'" ) !== false;
		$guard_ingestor = $crm_ingestor_src && strpos( $crm_ingestor_src, 'resolve_trigger_code' ) !== false && strpos( $crm_ingestor_src, "'ZALO_PERSONAL'" ) !== false && strpos( $crm_ingestor_src, "'ZALO_OA'" ) !== false;
		$loader_ok = $guard_listener && $guard_guru && $guard_gw && $guard_wh && $guard_crm && $guard_ingestor;
		if ( ! $loader_ok ) { $pass = false; }
		$rows[] = array(
			'label'  => 'Loader: R-ZONE-2 guard and CRM customer-AI skip are present',
			'status' => $loader_ok ? 'pass' : 'fail',
			'detail' => $loader_ok ? 'all guards confirmed' : implode( '; ', array_filter( array(
				$guard_listener ? '' : 'Universal Listener missing ZALO_BOT bail',
				$guard_guru     ? '' : 'Guru Bridge missing zalo_oa/zalo_personal bail',
				$guard_gw       ? '' : 'Gateway Bridge missing zalo_oa/zalo_personal bail',
				$guard_wh       ? '' : 'Webhook Handler missing platform=ZALO_BOT field',
				$guard_crm      ? '' : 'CRM AI listener missing zalo_bot Zone 2 skip',
				$guard_ingestor ? '' : 'CRM ingestor missing discriminator-based Zalo routing',
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

		// [2026-08-30 Johnny Chu] R-CRM-ZALOBOT-ADMIN-ZONE — runtime proof that the shared Zalo action resolves by payload identity.
		$discriminator_ok = false;
		$discriminator_detail = 'CRM ingestor is not loaded.';
		if ( class_exists( 'BizCity_CRM_Facebook_Ingestor', false ) ) {
			try {
				$resolve_method = new ReflectionMethod( 'BizCity_CRM_Facebook_Ingestor', 'resolve_trigger_code' );
				$resolve_method->setAccessible( true );
				$bot_code = $resolve_method->invoke( null, 'bizcity_zalo_message_received', array( 'platform' => 'ZALO_BOT', 'code' => 'zalo_bot' ) );
				$personal_code = $resolve_method->invoke( null, 'bizcity_zalo_message_received', array( 'platform' => 'ZALO_PERSONAL', 'code' => 'zalo_personal' ) );
				$oa_code = $resolve_method->invoke( null, 'bizcity_zalo_message_received', array( 'platform' => 'ZALO_OA', 'code' => 'zalo_oa' ) );
				$unknown_code = $resolve_method->invoke( null, 'bizcity_zalo_message_received', array( 'platform' => 'ZALO_UNKNOWN' ) );
				$conflict_code = $resolve_method->invoke( null, 'bizcity_zalo_message_received', array( 'platform' => 'ZALO_BOT', 'code' => 'zalo_personal' ) );
				$discriminator_ok = $bot_code === 'zalo_bot' && $personal_code === 'zalo_personal' && $oa_code === 'zalo_oa' && null === $unknown_code && null === $conflict_code;
				$discriminator_detail = $discriminator_ok ? 'Generic Zalo action resolves Bot, Personal and OA independently; unknown payload is refused.' : 'Generic Zalo action returned a cross-zone or non-fail-closed mapping.';
			} catch ( Throwable $e ) {
				$discriminator_detail = 'Discriminator reflection failed: ' . $e->getMessage();
			}
		}
		if ( ! $discriminator_ok ) { $pass = false; }
		$rows[] = array(
			'label'  => 'Runtime: shared Zalo action resolves by explicit platform/code',
			'status' => $discriminator_ok ? 'pass' : 'fail',
			'detail' => $discriminator_detail,
		);

		// ── LAYER 3: RUNTIME ──────────────────────────────────────────────────
		// [2026-08-30 Johnny Chu] R-CRM-ZALOBOT-ADMIN-ZONE — inspect the canonical listener map without firing production hooks.
		$zone2_isolated = false;
		$zone2_detail = 'Universal Channel Listener is not loaded.';
		if ( class_exists( 'BizCity_Universal_Channel_Listener', false ) ) {
			try {
				$listener_ref = new ReflectionClass( 'BizCity_Universal_Channel_Listener' );
				$map_property = $listener_ref->getProperty( 'map' );
				$map_property->setAccessible( true );
				$listener_map = $map_property->getValue();
				$generic_bot_map = isset( $listener_map['bizcity_zalo_message_received'] )
					&& (string) ( $listener_map['bizcity_zalo_message_received']['platform'] ?? '' ) === 'ZALO_BOT';
				$bridge_src = $listener_ok ? $listener_src : '';
				$zone2_isolated = $generic_bot_map && strpos( $bridge_src, "if ( \$platform === 'ZALO_BOT' )" ) !== false;
				$zone2_detail = $zone2_isolated ? 'Generic Bot map is explicit and bridge_zalo bails before Customer Care dispatch; no hook was fired.' : 'Bot map or bridge discriminator is incomplete.';
			} catch ( Throwable $e ) {
				$zone2_detail = 'Listener map reflection failed: ' . $e->getMessage();
			}
		}
		if ( ! $zone2_isolated ) { $pass = false; }
		$rows[] = array(
			'label'  => 'Runtime: ZALO_BOT KHÔNG dispatch Customer Care flow',
			'status' => $zone2_isolated ? 'pass' : 'fail',
			'detail' => $zone2_detail,
		);

		// [2026-08-30 Johnny Chu] R-CRM-ZALOBOT-ADMIN-ZONE — prove Bot CRM storage is enabled without creating a CRM row.
		$bot_contract = class_exists( 'BizCity_CRM_Channel_Contract', false )
			? BizCity_CRM_Channel_Contract::normalize_inbound( 'zalo_bot', array(
				'inbox_ref' => '__healthtest_bot',
				'source_id' => '__healthtest_user',
				'content' => '__healthtest_bot_crm_contract',
				'content_type' => 'text',
				'attachments' => array(),
				'external_source_id' => '__healthtest_bot_message',
				'received_at' => '2026-08-30 00:00:00',
			) )
			: null;
		$bot_crm_enabled = is_array( $bot_contract )
			&& ( $bot_contract['channel_code'] ?? '' ) === 'zalo_bot'
			&& ( $bot_contract['framework']['zone'] ?? '' ) === 'admin'
			&& true === ( $bot_contract['framework']['crm_enabled'] ?? false )
			&& ( $bot_contract['framework']['ai_policy'] ?? '' ) === 'automation_owner';
		if ( ! $bot_crm_enabled ) { $pass = false; }
		$rows[] = array(
			'label'  => 'Runtime: ZALO_BOT is accepted by CRM Admin Operations contract',
			'status' => $bot_crm_enabled ? 'pass' : 'fail',
			'detail' => $bot_crm_enabled
				? 'zalo_bot is CRM-enabled, admin-zoned and Automation-owned; no SQL fixture was created.'
				: 'zalo_bot was rejected or its CRM/zone/AI ownership descriptor is incomplete.',
		);

		// [2026-08-30 Johnny Chu] R-CRM-ZALOBOT-ADMIN-ZONE — verify the dedicated OA route from the canonical listener map without creating CRM rows.
		$zone1_routed = false;
		if ( isset( $listener_map ) && is_array( $listener_map ) ) {
			$zone1_routed = isset( $listener_map['bizcity_zalo_oa_message_received'] )
				&& (string) ( $listener_map['bizcity_zalo_oa_message_received']['platform'] ?? '' ) === 'ZALO_OA';
		}
		if ( ! $zone1_routed ) { $pass = false; }
		$rows[] = array(
			'label'  => 'Runtime: zalo_oa has a dedicated Zone 1 CRM route',
			'status' => $zone1_routed ? 'pass' : 'fail',
			'detail' => $zone1_routed
				? 'zalo_oa has an explicit ZALO_OA listener route; no synthetic hook was fired.'
				: 'zalo_oa route is missing from the canonical listener map.',
		);

		// [2026-07-21 Johnny Chu] PHASE-ZALOBOT-GROUP W6 — runtime proof that outbound parser targets group id, not sender id.
		$group_parse_ok = false;
		$group_parse_detail = 'BizCity_Zalo_Bot_Gateway_Bridge not loaded.';
		if ( ! class_exists( 'BizCity_Zalo_Bot_Gateway_Bridge', false ) && $gw_ok ) {
			if ( class_exists( 'BizCity_Safe_Loader', false ) ) {
				// [2026-09-01 Johnny Chu] R-SAFE-LOADER - load the probe dependency through the guarded artifact loader.
				BizCity_Safe_Loader::require_file( $gw_bridge_file, 'diagnostics.channel_zone.gateway_bridge' );
			}
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

		$crm_parser_ok = false;
		if ( class_exists( 'BizCity_CRM_Adapter_ZaloBot', false ) ) {
			$private = BizCity_CRM_Adapter_ZaloBot::parse_chat_id( 'zalobot_7_private_zpr-healthtest' );
			$group  = BizCity_CRM_Adapter_ZaloBot::parse_chat_id( 'zalobot_7_group_zgr-healthtest' );
			$crm_parser_ok = is_array( $private )
				&& (string) ( $private['source_id'] ?? '' ) === 'zpr-healthtest'
				&& (string) ( $private['thread_kind'] ?? '' ) === 'personal'
				&& is_array( $group )
				&& (string) ( $group['source_id'] ?? '' ) === 'group:zgr-healthtest'
				&& (string) ( $group['thread_kind'] ?? '' ) === 'group';
		}
		if ( ! $crm_parser_ok ) { $pass = false; }
		$rows[] = array(
			'label'  => 'Runtime: CRM Bot parser normalizes private/group source IDs',
			'status' => $crm_parser_ok ? 'pass' : 'fail',
			'detail' => $crm_parser_ok ? 'private UID and group:<id> are normalized before CRM storage.' : 'CRM Bot adapter parser is unavailable or returned an invalid identity.',
		);

		return array(
			'status'  => $pass ? 'pass' : 'fail',
			'summary' => $pass
				? 'Zone isolation OK: ZALO_BOT is CRM-enabled in Admin Operations, shared triggers are discriminator-routed, and Customer Care remains isolated.'
				: 'Zone isolation FAIL — check CRM contract, adapter routing and channel-gateway guards.',
			'fix_hint' => 'Kiểm tra adapter zalo_bot, discriminator platform/code, CRM crm_enabled policy và guard Customer Care trước khi chạy lại probe.',
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
