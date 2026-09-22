<?php
/**
 * Read-only DDV probe for the experimental Zalo Personal group-history route.
 *
 * This probe checks the route contract and invalid-input boundary without
 * calling the provider, changing a live session or ingesting CRM/archive data.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since PHASE-0.39F (2026-09-03)
 */

defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'BizCity_Diagnostics_Probe' ) || class_exists( 'BizCity_Probe_Zalo_Personal_Group_History_Contract', false ) ) {
	return;
}

final class BizCity_Probe_Zalo_Personal_Group_History_Contract implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'modules.zalo-personal.group_history_contract'; }
	public function label(): string { return 'Zalo Personal experimental group history contract'; }
	public function description(): string { return 'Kiểm tra route lịch sử nhóm thử nghiệm, response dry-run và chặn request thiếu thread_ref trước provider transport; không import CRM/archive.'; }
	public function severity(): string { return 'warning'; }
	public function order(): int { return 47; }
	public function icon(): string { return 'history'; }
	public function estimate_ms(): int { return 150; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_Zalo_Bridge_REST' ) || ! method_exists( 'BizCity_Zalo_Bridge_REST', 'handle_group_history' ) ) {
			return new WP_Error( 'zalo_group_history_handler_missing', 'Zalo group-history handler is not loaded.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-09-03 03:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.39F-H6-GROUP-DDV — verify the experimental group-history boundary without provider or CRM side effects.
		$steps = array();
		$emit = function ( $layer, $label, $ok, $detail ) use ( $ctx, &$steps ) {
			$step = array(
				'layer'  => $layer,
				'label'  => $label,
				'status' => $ok ? 'pass' : 'fail',
				'detail' => $detail,
			);
			$steps[] = $step;
			$ctx->emit_step( $step );
		};

		$root = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( __DIR__, 4 ) . '/';
		$rest_file = $root . 'plugins/bizcity-zalo-personal/includes/shared/class-zalo-bridge-rest.php';
		$client_file = $root . 'plugins/bizcity-zalo-personal/includes/shared/class-zalo-bridge-client.php';
		// [2026-09-03 03:25 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.39F-H3-GROUP-DDV — include the exact-key Hub artifact in the discovery contract.
		$hub_file = $root . '../bizcity-llm-router/includes/class-router-zalo-personal-bridge-rest.php';
		// [2026-09-18 11:11 AM Johnny Chu - Chu Hoàng Anh] R-DDV — the deployed sidecar lives outside the plugin; resolve it from BIZCITY_ZCA_BRIDGE_ROOT (env or constant) and fall back to the repository bundle. No operator path is hard-coded in shared code.
		$sidecar_root = getenv( 'BIZCITY_ZCA_BRIDGE_ROOT' );
		if ( ( ! is_string( $sidecar_root ) || trim( $sidecar_root ) === '' ) && defined( 'BIZCITY_ZCA_BRIDGE_ROOT' ) ) {
			$sidecar_root = (string) BIZCITY_ZCA_BRIDGE_ROOT;
		}
		$sidecar_origin = ( is_string( $sidecar_root ) && trim( $sidecar_root ) !== '' ) ? 'runtime' : 'bundle';
		$sidecar_root = 'runtime' === $sidecar_origin
			? rtrim( (string) $sidecar_root, '/\\' )
			: $root . 'plugins/bizcity-zalo-personal/_library/zca-bridge-main';
		$sidecar_file = $sidecar_root . '/src/wp/wpRoutes.ts';
		$history_file = $sidecar_root . '/src/zalo/groupHistory.ts';
		$rest_source = is_readable( $rest_file ) ? (string) file_get_contents( $rest_file ) : '';
		$client_source = is_readable( $client_file ) ? (string) file_get_contents( $client_file ) : '';
		$hub_source = is_readable( $hub_file ) ? (string) file_get_contents( $hub_file ) : '';
		$sidecar_source = is_readable( $sidecar_file ) ? (string) file_get_contents( $sidecar_file ) : '';
		$history_source = is_readable( $history_file ) ? (string) file_get_contents( $history_file ) : '';

		// Named checks per artifact group, so a failure says exactly what is missing.
		$missing_in = static function ( $source, array $markers ) {
			$missing = array();
			foreach ( $markers as $name => $needle ) {
				if ( strpos( $source, $needle ) === false ) {
					$missing[] = $name;
				}
			}
			return $missing;
		};

		// 1. Client PHP (always shipped with the plugin) — a gap here is a real FAIL.
		$client_missing = array();
		if ( $rest_source === '' ) {
			$client_missing[] = 'class-zalo-bridge-rest.php unreadable';
		}
		if ( $client_source === '' ) {
			$client_missing[] = 'class-zalo-bridge-client.php unreadable';
		}
		$client_missing = array_merge( $client_missing, $missing_in( $rest_source, array(
			'rest.handle_group_history'            => 'handle_group_history',
			'rest.handle_group_history_candidates' => 'handle_group_history_candidates',
			'rest.route_history_group'             => 'history/group',
			'rest.route_history_groups'            => 'history/groups',
			'rest.get_group_history'               => 'get_group_history',
			'rest.resume_supported_false'          => "'resume_supported' => false",
			'rest.storage_target'                  => "'storage_target' => 'context_bank_filestore'",
			'rest.duplicate_policy'                => "'duplicate_policy' => 'record_id_before_write'",
			'rest.write_enabled_false'             => "'write_enabled' => false",
			'rest.pagination_unavailable'          => 'history_pagination_unavailable',
		) ), $missing_in( $client_source, array(
			'client.get_group_candidates' => 'function get_group_candidates',
			'client.get_group_history'    => 'function get_group_history',
		) ) );
		$client_ok = empty( $client_missing );
		$emit( 'Disk', 'Client group-history PHP route and bridge client markers', $client_ok, $client_ok ? 'Route, bridge client and dry-run policy markers are present.' : 'Missing: ' . implode( ', ', $client_missing ) . '.' );

		// 2. Hub router (only present on the B1 Hub) — skip on a client-only host.
		$hub_ok = true;
		if ( $hub_source === '' ) {
			$step = array( 'layer' => 'Disk', 'label' => 'Hub group-history normalization markers', 'status' => 'skip', 'detail' => 'Hub router source is not present on this host; validate these markers on the deployed Hub.' );
			$steps[] = $step;
			$ctx->emit_step( $step );
		} else {
			$hub_missing = $missing_in( $hub_source, array(
				'hub.normalize_group_history_response' => 'normalize_group_history_response',
				'hub.handle_group_history_candidates'  => 'handle_group_history_candidates',
				'hub.route_history_groups'             => 'history/groups',
				'hub.route_history_group_thread'       => 'history/group?threadRef=',
				'hub.resume_supported_false'           => "'resume_supported' => false",
				'hub.pagination_unavailable'           => 'history_pagination_unavailable',
				'hub.storage_target'                   => "'storage_target' => 'context_bank_filestore'",
				'hub.duplicate_policy'                 => "'duplicate_policy' => 'record_id_before_write'",
				'hub.write_enabled_false'              => "'write_enabled' => false",
			) );
			$hub_ok = empty( $hub_missing );
			$emit( 'Disk', 'Hub group-history normalization markers', $hub_ok, $hub_ok ? 'Hub normalizer enforces the dry-run storage envelope.' : 'Missing: ' . implode( ', ', $hub_missing ) . '.' );
		}

		// 3. Sidecar source (deployed outside the plugin) — skip when it is not readable here.
		$sidecar_ok = true;
		if ( $sidecar_source === '' || $history_source === '' ) {
			$step = array( 'layer' => 'Disk', 'label' => 'Sidecar group-history dry-run markers (' . $sidecar_origin . ')', 'status' => 'skip', 'detail' => 'Sidecar source is not readable on this host. Set BIZCITY_ZCA_BRIDGE_ROOT (environment or constant) to the deployed sidecar root to include it.' );
			$steps[] = $step;
			$ctx->emit_step( $step );
		} else {
			$sidecar_missing = array_merge( $missing_in( $sidecar_source, array(
				'sidecar.import_mode_dry_run'    => 'import_mode: "dry_run"',
				'sidecar.side_effects_false'     => 'side_effects_allowed: false',
				'sidecar.resume_supported_false' => 'resume_supported: false',
				'sidecar.storage_target'         => 'storage_target: "context_bank_filestore"',
				'sidecar.duplicate_policy'       => 'duplicate_policy: "record_id_before_write"',
				'sidecar.write_enabled_false'    => 'write_enabled: false',
			) ), $missing_in( $history_source, array(
				'history.origin_historical_import' => 'origin: "historical_import"',
				'history.dedupe_key_hash'          => 'dedupe_key_hash',
				'history.context_record_id'        => 'context_record_id',
			) ) );
			$sidecar_ok = empty( $sidecar_missing );
			$emit( 'Disk', 'Sidecar group-history dry-run markers (' . $sidecar_origin . ')', $sidecar_ok, $sidecar_ok ? 'Sidecar route and normalization keep the dry-run, no-write envelope.' : 'Missing: ' . implode( ', ', $sidecar_missing ) . '.' );
		}

		$disk_ok = $client_ok && $hub_ok && $sidecar_ok;

		$loader_ok = class_exists( 'BizCity_Zalo_Bridge_REST', false )
			&& method_exists( 'BizCity_Zalo_Bridge_REST', 'handle_group_history' )
			&& method_exists( 'BizCity_Zalo_Bridge_REST', 'handle_group_history_candidates' )
			&& class_exists( 'BizCity_Zalo_Bridge_Client', false )
			&& method_exists( 'BizCity_Zalo_Bridge_Client', 'get_group_history' )
			&& method_exists( 'BizCity_Zalo_Bridge_Client', 'get_group_candidates' );
		$emit( 'Loader', 'Group-history REST handler and bridge client are loaded', $loader_ok, $loader_ok ? 'Active PHP classes expose the read-only group-history boundary.' : 'Group-history PHP handler or client method is not loaded.' );

		$route_ok = false;
		$discovery_route_ok = false;
		if ( function_exists( 'rest_get_server' ) ) {
			foreach ( (array) rest_get_server()->get_routes() as $path => $_handlers ) {
				$path = (string) $path;
				if ( false !== strpos( $path, '/bizcity-channel/v1/zalo-bridge/accounts/' ) && false !== strpos( $path, '/history/group' ) ) {
					$route_ok = true;
				}
				if ( false !== strpos( $path, '/bizcity-channel/v1/zalo-bridge/accounts/' ) && false !== strpos( $path, '/history/groups' ) ) {
					$discovery_route_ok = true;
				}
			}
		}
		$emit( 'Runtime', 'Experimental group-history route is registered', $route_ok, $route_ok ? 'Route found in rest_get_server()->get_routes().' : 'Group-history route is absent from the active REST server.' );
		$emit( 'Runtime', 'Hash-only group discovery route is registered', $discovery_route_ok, $discovery_route_ok ? 'Discovery route found in rest_get_server()->get_routes().' : 'Group discovery route is absent from the active REST server.' );

		$invalid_ok = false;
		if ( class_exists( 'WP_REST_Request' ) ) {
			$request = new WP_REST_Request( 'GET', '/bizcity-channel/v1/zalo-personal/accounts/16/history/group' );
			$request->set_param( 'id', '16' );
			$request->set_param( 'thread_ref', '' );
			$request->set_param( 'count', 10 );
			$response = BizCity_Zalo_Bridge_REST::handle_group_history( $request );
			$data = is_object( $response ) && method_exists( $response, 'get_data' ) ? $response->get_data() : array();
			$invalid_ok = is_array( $data )
				&& empty( $data['ok'] )
				&& ! empty( $data['_degraded'] )
				&& (string) ( $data['code'] ?? '' ) === 'invalid_param'
				&& (string) ( $data['help_code'] ?? '' ) === 'invalid_param_generic'
				&& isset( $data['hint'] );
		}
		$emit( 'Runtime', 'Missing thread_ref is rejected before bridge transport', $invalid_ok, $invalid_ok ? 'Invalid history input returns an explicit degraded error without provider access.' : 'Missing thread_ref was not rejected with the required error payload.' );

		$cursor_ok = false;
		if ( class_exists( 'WP_REST_Request' ) ) {
			// [2026-09-03 04:35 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.39F-H4-GROUP-DDV — prove WordPress rejects unsupported cursor replay before the bridge client is called.
			$cursor_request = new WP_REST_Request( 'GET', '/bizcity-channel/v1/zalo-bridge/accounts/16/history/group' );
			$cursor_request->set_param( 'id', '16' );
			$cursor_request->set_param( 'thread_ref', 'probe-thread-ref' );
			$cursor_request->set_param( 'cursor', 'probe-cursor' );
			$cursor_request->set_param( 'count', 10 );
			$cursor_response = BizCity_Zalo_Bridge_REST::handle_group_history( $cursor_request );
			$cursor_data = is_object( $cursor_response ) && method_exists( $cursor_response, 'get_data' ) ? $cursor_response->get_data() : array();
			$cursor_ok = is_array( $cursor_data )
				&& empty( $cursor_data['ok'] )
				&& ! empty( $cursor_data['_degraded'] )
				&& (string) ( $cursor_data['code'] ?? '' ) === 'history_pagination_unavailable'
				&& (string) ( $cursor_data['reason_bucket'] ?? '' ) === 'history_pagination_unavailable'
				&& false === strpos( wp_json_encode( $cursor_data ), 'probe-cursor' );
		}
		$emit( 'Runtime', 'Unsupported cursor replay is rejected before bridge transport', $cursor_ok, $cursor_ok ? 'Cursor replay returns a dry-run degraded response without calling the bridge client.' : 'Cursor replay was not rejected at the WordPress boundary.' );

		$hub_guard_ok = true;
		if ( class_exists( 'BizCity_Router_Zalo_Personal_Bridge_REST', false ) && method_exists( 'BizCity_Router_Zalo_Personal_Bridge_REST', 'normalize_group_history_response' ) ) {
			// [2026-09-03 05:05 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.39F-CONTEXT-BANK-DDV — prove Hub rejects stale history envelopes before exposing them to the client.
			$guard_method = new ReflectionMethod( 'BizCity_Router_Zalo_Personal_Bridge_REST', 'normalize_group_history_response' );
			$guard_method->setAccessible( true );
			$valid_envelope = array( 'ok' => true, 'success' => true, 'import_mode' => 'dry_run', 'side_effects_allowed' => false, 'resume_supported' => false, 'storage_target' => 'context_bank_filestore', 'duplicate_policy' => 'record_id_before_write', 'write_enabled' => false, 'groups' => array() );
			$valid_result = $guard_method->invoke( null, $valid_envelope, 'discovery' );
			$stale_result = $guard_method->invoke( null, array( 'ok' => true, 'success' => true, 'groups' => array() ), 'discovery' );
			$hub_guard_ok = $valid_result === $valid_envelope
				&& is_array( $stale_result )
				&& ! empty( $stale_result['_degraded'] )
				&& (string) ( $stale_result['code'] ?? '' ) === 'history_contract_mismatch'
				&& (string) ( $stale_result['storage_target'] ?? '' ) === 'context_bank_filestore'
				&& (string) ( $stale_result['duplicate_policy'] ?? '' ) === 'record_id_before_write'
				&& false === (bool) ( $stale_result['write_enabled'] ?? true );
			$emit( 'Runtime', 'Hub rejects stale storage envelopes before client consumption', $hub_guard_ok, $hub_guard_ok ? 'Valid dry-run policy is preserved; missing or unsafe storage policy becomes history_contract_mismatch.' : 'Hub accepted a stale or unsafe history storage envelope.' );
		} else {
			$step = array( 'layer' => 'Runtime', 'label' => 'Hub storage-envelope guard', 'status' => 'skip', 'detail' => 'Hub-only router class is not loaded in the client runtime; validate this fixture on the deployed Hub.' );
			$steps[] = $step;
			$ctx->emit_step( $step );
		}

		$pass = $disk_ok && $loader_ok && $route_ok && $discovery_route_ok && $invalid_ok && $cursor_ok && $hub_guard_ok;
		// [2026-09-18 11:11 AM Johnny Chu - Chu Hoàng Anh] R-DDV — Hub-only skips are not applicable on a client host, but an unreadable sidecar source is missing evidence: warn, never a silent pass.
		$sidecar_skipped = $sidecar_source === '' || $history_source === '';
		$status = ! $pass ? 'fail' : ( $sidecar_skipped ? 'warn' : 'pass' );
		return array(
			'status'   => $status,
			'summary'  => 'pass' === $status
				? 'Experimental group-history route contract and pre-transport safety checks passed.'
				: ( 'warn' === $status ? 'Group-history PHP and runtime checks passed; sidecar dry-run markers were not verifiable on this host.' : 'Experimental group-history contract checks failed.' ),
			'fix_hint' => 'pass' === $status ? '' : ( 'warn' === $status
				? 'Set BIZCITY_ZCA_BRIDGE_ROOT (environment or constant) to the deployed sidecar root and rerun: php bin/diagnostics-run.php --filter=modules.zalo-personal.group_history_contract --format=json'
				: 'Fix the failing step(s) above: keep group history bounded and dry-run, register the PHP route, and reject missing thread_ref before bridge transport.' ),
			'steps'    => $steps,
		);
	}

	public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	if ( ! in_array( 'BizCity_Probe_Zalo_Personal_Group_History_Contract', $list, true ) ) {
		$list[] = 'BizCity_Probe_Zalo_Personal_Group_History_Contract';
	}
	return $list;
} );
