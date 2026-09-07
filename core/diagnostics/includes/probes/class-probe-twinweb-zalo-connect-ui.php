<?php
/**
 * DDV probe for Twin GPT My Channels Zalo and future channel shell.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since 2026-08-29
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

if ( class_exists( 'BizCity_Probe_TwinWeb_Zalo_Connect_UI', false ) ) {
	return;
}

final class BizCity_Probe_TwinWeb_Zalo_Connect_UI implements BizCity_Diagnostics_Probe {

	const PROBE_ID = 'twingpt.mychannels.zalo_connect_ui';

	public function id(): string { return self::PROBE_ID; }

	public function label(): string { return 'Twin GPT · My Channels connect UI'; }

	public function description(): string {
		return 'Kiểm tra shell Kênh của tôi, Zalo Personal/OA/Bot separation, Web/Tiktok planned states và owner-scoped OA routes.';
	}

	public function severity(): string { return 'critical'; }

	public function order(): int { return 34; }

	public function icon(): string { return 'plug-zap'; }

	public function estimate_ms(): int { return 500; }

	public function precondition() { return true; }

	public function run( $ctx ): array {
		$plugin_dir = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : ( WP_CONTENT_DIR . '/plugins' );
		$base = $plugin_dir . '/bizcity-twin-ai/';
		$rest_file = $base . 'modules/twinweb/includes/class-twinweb-rest.php';
		// [2026-09-05 Johnny Chu - Chu Hoàng Anh] PHASE-1.33A-DDV — verify the Zalo Personal owner-binding boundary alongside the Twin GPT routes.
		$bridge_file = $base . 'plugins/bizcity-zalo-personal/includes/shared/class-zalo-bridge-rest.php';
		// [2026-08-29 Johnny Chu] PHASE-0-RULE-TWIN-GPT-FIRST-USER-ID-PII-SURFACE — include the C-surface projection and scope owners in the probe.
		$projection_file = $base . 'modules/twinweb/includes/class-twinweb-crm-projection.php';
		$access_file = $base . 'plugins/bizcity-twin-crm/includes/class-inbox-access.php';
		$ui_file = $base . 'modules/twinweb/ui/src/components/ChannelConnectHub.tsx';
		$api_file = $base . 'modules/twinweb/ui/src/api/myChannels.ts';
		$bundle_files = glob( $base . 'modules/twinweb/ui/dist/assets/*.js' );
		$bundle_file = is_array( $bundle_files ) && ! empty( $bundle_files ) ? (string) reset( $bundle_files ) : '';
		$source_ui_available = is_readable( $ui_file ) && is_readable( $api_file );
		$bundle_available = $bundle_file !== '' && is_readable( $bundle_file );
		$disk_ok = is_readable( $rest_file ) && is_readable( $bridge_file ) && is_readable( $projection_file ) && is_readable( $access_file ) && ( $source_ui_available || $bundle_available );
		$ctx->emit_step( array(
			'label' => 'Disk · unified channel artifacts',
			'status' => $disk_ok ? 'pass' : 'fail',
			'detail' => $disk_ok ? ( $source_ui_available ? 'REST/controller, C-safe scope and source UI artifacts are readable.' : 'REST/controller, C-safe scope and the built Twin GPT UI artifact are readable; development source is absent as expected on dist-only deployment.' ) : 'Backend or built My Channels artifacts are missing.',
		) );
		if ( ! $disk_ok ) {
			return array( 'status' => 'fail', 'summary' => 'Unified My Channels artifacts are missing.', 'error' => 'mychannels_artifact_missing', 'fix_hint' => 'Deploy the PHASE-0.45 Twin GPT UI and REST files, then rerun this probe.' );
		}

		$ui_src = $source_ui_available ? (string) file_get_contents( $ui_file ) : '';
		$api_src = $source_ui_available ? (string) file_get_contents( $api_file ) : '';
		$bundle_src = $bundle_available ? (string) file_get_contents( $bundle_file ) : '';
		$ui_contract_src = $ui_src . "\n" . $api_src . "\n" . $bundle_src;
		$rest_src = (string) file_get_contents( $rest_file );
		$bridge_src = (string) file_get_contents( $bridge_file );
		$projection_src = (string) file_get_contents( $projection_file );
		$access_src = (string) file_get_contents( $access_file );
		// [2026-08-29 Johnny Chu] PHASE-0-RULE-TWIN-GPT-FIRST-USER-ID-PII-SURFACE — verify literal source markers without evaluating runtime variables in the probe.
		$markers_ok = strpos( $ui_contract_src, 'Zalo Cá nhân' ) !== false
			&& ( strpos( $ui_contract_src, 'Zalo Bot' ) !== false || strpos( $ui_contract_src, 'zalo-bot' ) !== false )
			&& strpos( $ui_contract_src, 'zalo_personal' ) !== false
			&& strpos( $ui_contract_src, 'zalo_oa' ) !== false
			&& strpos( $ui_contract_src, 'internal' ) !== false
			&& strpos( $rest_src, "'group' => 'customer'" ) !== false
			&& strpos( $rest_src, "'group' => 'internal'" ) !== false
			&& ( strpos( $ui_contract_src, "id: 'web'" ) !== false || strpos( $ui_contract_src, 'id:"web"' ) !== false || strpos( $ui_contract_src, 'webchat' ) !== false )
			&& ( strpos( $ui_contract_src, "id: 'tiktok'" ) !== false || strpos( $ui_contract_src, 'id:"tiktok"' ) !== false )
			&& ( strpos( $ui_contract_src, 'getZaloOaAccounts' ) !== false || strpos( $ui_contract_src, 'zalo-oa/accounts' ) !== false )
			&& strpos( $rest_src, "resolve_scope( \$user_id, 'c' )" ) !== false
			&& strpos( $rest_src, 'shape_mychannels_zalo_personal_conversation' ) !== false
			&& strpos( $rest_src, 'shape_mychannels_zalo_personal_message' ) !== false
			&& strpos( $bridge_src, 'BizCity_Channel_User_Grant' ) !== false
			&& strpos( $bridge_src, 'bind_primary_from_current' ) !== false
			&& strpos( $bridge_src, 'connection_owner_user_id' ) !== false
			&& strpos( $bridge_src, "update_account_status( \$local_id, 'orphaned' )" ) !== false
			&& strpos( $rest_src, 'require_mychannels_grant_member' ) !== false
			&& strpos( $rest_src, 'begin_channel_grant_operation' ) !== false
			&& strpos( $rest_src, 'x-bizcity-idempotency-key' ) !== false
			&& strpos( $rest_src, 'channel_grant_error' ) !== false
			&& strpos( $projection_src, "resolve_scope( \$user_id, 'c' )" ) !== false
			&& strpos( $access_src, "'c' !== strtolower( $surface )" ) !== false;
		$ctx->emit_step( array(
			'label' => 'Disk · channel portfolio markers',
			'status' => $markers_ok ? 'pass' : 'fail',
			'detail' => $markers_ok ? 'Personal, OA, Bot, Web, Tiktok and C-surface privacy markers are present.' : 'Channel portfolio or C-surface privacy marker is incomplete.',
		) );
		if ( ! $markers_ok ) {
			$missing_markers = array();
			$marker_checks = array(
				'zalo_personal' => strpos( $ui_contract_src, 'zalo_personal' ) !== false,
				'zalo_oa' => strpos( $ui_contract_src, 'zalo_oa' ) !== false,
				'zalo_bot' => strpos( $ui_contract_src, 'Zalo Bot' ) !== false || strpos( $ui_contract_src, 'zalo-bot' ) !== false,
				'tiktok' => strpos( $ui_contract_src, 'tiktok' ) !== false,
				'web' => strpos( $ui_contract_src, 'webchat' ) !== false || strpos( $ui_contract_src, "id: 'web'" ) !== false || strpos( $ui_contract_src, 'id:"web"' ) !== false,
				'grant_routes' => strpos( $rest_src, 'require_mychannels_grant_member' ) !== false && strpos( $rest_src, 'channel-grants' ) !== false,
			);
			foreach ( $marker_checks as $marker => $present ) {
				if ( ! $present ) { $missing_markers[] = $marker; }
			}
			return array( 'status' => 'fail', 'summary' => 'Channel portfolio or C-surface privacy contract is incomplete.', 'error' => 'channel_portfolio_markers_missing', 'fix_hint' => 'Deploy matching REST and built Twin GPT artifacts; missing markers: ' . implode( ',', $missing_markers ) );
		}

		$loader_ok = class_exists( 'BizCity_TwinWeb_REST', false );
		$ctx->emit_step( array(
			'label' => 'Loader · Twin GPT REST class',
			'status' => $loader_ok ? 'pass' : 'fail',
			'detail' => $loader_ok ? 'BizCity_TwinWeb_REST is loaded.' : 'BizCity_TwinWeb_REST is not loaded.',
		) );
		if ( ! $loader_ok || ! function_exists( 'rest_get_server' ) ) {
			return array( 'status' => 'fail', 'summary' => 'Twin GPT REST loader is unavailable.', 'error' => 'twinweb_rest_not_loaded', 'fix_hint' => 'Load modules/twinweb REST before running the diagnostics probe.' );
		}

		$server = rest_get_server();
		$routes = $server ? $server->get_routes() : array();
		$expected = array(
			'/bizcity-twinweb/v1/mychannels/zalo-personal/accounts',
			'/bizcity-twinweb/v1/mychannels/zalo-personal/accounts/(?P<id>[A-Za-z0-9_-]+)/qr',
			'/bizcity-twinweb/v1/mychannels/zalo-personal/accounts/(?P<id>[A-Za-z0-9_-]+)/status',
			'/bizcity-twinweb/v1/mychannels/channel-grants/(?P<channel>[a-z0-9_-]+)/(?P<account_id>[A-Za-z0-9_-]+)',
			'/bizcity-twinweb/v1/mychannels/channel-grants/(?P<channel>[a-z0-9_-]+)/(?P<account_id>[A-Za-z0-9_-]+)/(?P<user_id>\d+)',
			'/bizcity-twinweb/v1/mychannels/channel-grants/(?P<channel>[a-z0-9_-]+)/(?P<account_id>[A-Za-z0-9_-]+)/transfer',
			'/bizcity-twinweb/v1/mychannels/zalo-oa/accounts',
			'/bizcity-twinweb/v1/mychannels/zalo-oa/accounts/connect-url',
			'/bizcity-twinweb/v1/mychannels/zalo-oa/accounts/(?P<id>[A-Za-z0-9_-]+)/status',
			'/bizcity-twinweb/v1/mychannels/zalo-oa/accounts/(?P<id>[A-Za-z0-9_-]+)/test',
			'/bizcity-twinweb/v1/mychannels/zalo-oa/accounts/(?P<id>[A-Za-z0-9_-]+)',
		);
		$missing = array();
		foreach ( $expected as $route ) {
			if ( ! isset( $routes[ $route ] ) ) {
				$missing[] = $route;
			}
		}
		$routes_ok = empty( $missing );
		$ctx->emit_step( array(
			'label' => 'Runtime · OA route registry',
			'status' => $routes_ok ? 'pass' : 'fail',
			'detail' => $routes_ok ? 'All member-scoped OA routes are registered.' : 'Missing: ' . implode( ', ', $missing ),
		) );
		if ( ! $routes_ok ) {
			return array( 'status' => 'fail', 'summary' => 'Member-scoped Zalo OA routes are incomplete.', 'error' => 'zalo_oa_routes_missing', 'fix_hint' => 'Check class-twinweb-rest.php route registration and loader order.' );
		}

		$ctx->emit_step( array(
			'label' => 'Runtime · two-key isolation',
			'status' => 'skip',
			'detail' => 'Requires two deployed Hub API-key fixtures; this read-only client probe does not create or mutate credentials.',
		) );
		return array(
			'status' => 'pass',
			'summary' => 'My Channels UI artifacts and member-scoped OA routes are present. Two-key Hub isolation remains a deployment DDV gate.',
			'error' => null,
			'fix_hint' => 'Run the two-key Hub smoke before changing Branch 20 from PRE-RELEASE.',
		);
	}

	public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_TwinWeb_Zalo_Connect_UI';
	return $list;
} );
