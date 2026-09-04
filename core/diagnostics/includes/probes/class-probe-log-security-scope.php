<?php
/**
 * Focused DDV for JSONL web protection and canonical Explorer scope.
 *
 * HTTP denial is executed only when the operator supplies an exact URL through
 * BIZCITY_DIAGNOSTICS_HTTP_PROBE_URL. The probe never guesses a host or path.
 *
 * @package BizCity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 */

defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'BizCity_Diagnostics_Probe' ) || class_exists( 'BizCity_Probe_Log_Security_Scope', false ) ) {
	return;
}

final class BizCity_Probe_Log_Security_Scope implements BizCity_Diagnostics_Probe {

	public function id(): string {
		return 'core.helper.log_security_scope';
	}

	public function label(): string {
		return 'JSONL public security and Explorer scope';
	}

	public function description(): string {
		return 'Checks registry-only Explorer access, traversal refusal, Apache/IIS protection artifacts and an explicit deployed HTTP denial URL.';
	}

	public function severity(): string {
		return 'critical';
	}

	public function order(): int {
		return 54;
	}

	public function icon(): string {
		return 'lock';
	}

	public function estimate_ms(): int {
		return 500;
	}

	public function precondition() {
		if ( ! class_exists( 'BizCity_JSONL_File_Logger' ) || ! class_exists( 'BizCity_Log_Contract_Registry' ) ) {
			return new WP_Error( 'log_security_dependencies_missing', 'JSONL logger or contract registry is not loaded.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-09-02 Johnny Chu] PHASE-1.30-G1 - verify the deployed security and scope boundaries without guessing a mapped host.
		$steps = array();
		$emit = function ( $label, $status, $detail ) use ( $ctx, &$steps ) {
			$step = array( 'label' => $label, 'status' => $status, 'detail' => $detail );
			$steps[] = $step;
			$ctx->emit_step( $step );
		};
		$root = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( __DIR__, 4 ) . '/';
		$explorer_file = $root . 'core/helper/class-bizcity-log-explorer.php';
		$rest_file = $root . 'core/channel-gateway/includes/class-channel-rest-api.php';
		$gateway_bootstrap = $root . 'core/channel-gateway/bootstrap.php';
		$explorer_source = is_readable( $explorer_file ) ? (string) file_get_contents( $explorer_file ) : '';
		$rest_source = is_readable( $rest_file ) ? (string) file_get_contents( $rest_file ) : '';
		$gateway_source = is_readable( $gateway_bootstrap ) ? (string) file_get_contents( $gateway_bootstrap ) : '';
		$disk_ok = class_exists( 'BizCity_Log_Explorer' )
			&& strpos( $explorer_source, "current_user_can( 'manage_options' )" ) !== false
			&& strpos( $explorer_source, 'BizCity_Log_Contract_Registry::all' ) !== false
			&& strpos( $explorer_source, 'check_admin_referer' ) !== false
			&& strpos( $rest_source, 'authorize_log_account' ) !== false;
		$emit( 'Disk - canonical Explorer and account-scope guards', $disk_ok ? 'pass' : 'fail', $disk_ok ? 'Explorer uses capability/contract/nonce guards and channel REST exposes account authorization.' : 'Explorer or channel REST scope guard source is incomplete.' );

		// [2026-09-02 Johnny Chu] PHASE-1.30-G1 - inspect the registered hook/source contract without calling rest_get_server(), which can trigger unrelated schema repair.
		$route_hook_ok = strpos( $gateway_source, "add_action( 'rest_api_init', [ 'BizCity_Channel_REST_API', 'init' ] )" ) !== false;
		$route_source_ok = strpos( $rest_source, "register_rest_route( self::NS, '/channel-logs'" ) !== false
			&& strpos( $rest_source, "register_rest_route( self::NS, '/logs'" ) !== false
			&& substr_count( $rest_source, 'require_manage_options' ) >= 2;
		$route_ok = is_readable( $rest_file ) && $route_hook_ok && $route_source_ok;
		$emit( 'Loader - channel log routes require manage_options', $route_ok ? 'pass' : 'fail', $route_ok ? 'Guarded Channel REST bootstrap declares both canonical log routes with the manage_options callback.' : 'Channel REST bootstrap hook or one canonical route capability declaration is missing.' );

		$contract = null;
		foreach ( BizCity_Log_Contract_Registry::all() as $item ) {
			if ( is_array( $item ) && ! empty( $item['jsonl_folder'] ) && ! empty( $item['jsonl_module'] ) ) {
				$contract = $item;
				break;
			}
		}
		$artifact_ok = false;
		if ( is_array( $contract ) ) {
			$location = BizCity_JSONL_File_Logger::location( $contract['jsonl_folder'], $contract['jsonl_module'] );
			$directory = (string) ( $location['directory'] ?? '' );
			$root_dir = $directory !== '' ? dirname( $directory ) : '';
			$artifact_ok = $root_dir !== '' && is_file( $root_dir . DIRECTORY_SEPARATOR . '.htaccess' )
				&& is_file( $root_dir . DIRECTORY_SEPARATOR . 'web.config' )
				&& is_file( $root_dir . DIRECTORY_SEPARATOR . 'index.php' );
		}
		$emit( 'Runtime - Apache/IIS upload-root protection artifacts', $artifact_ok ? 'pass' : 'fail', $artifact_ok ? 'The registered JSONL root contains deny, extension-filter and directory-index protection artifacts.' : 'A registered JSONL root is missing one or more protection artifacts.' );

		$traversal_ok = false;
		$unknown_reader_ok = false;
		if ( is_array( $contract ) ) {
			$folder = (string) $contract['jsonl_folder'];
			$module = (string) $contract['jsonl_module'];
			$traversal = BizCity_JSONL_File_Logger::read_page_location( $folder, $module, $folder . '/' . $module . '/../outside/2026-09-02.jsonl', 0, 1 );
			$traversal_ok = empty( $traversal['rows'] ) && empty( $traversal['complete'] );
			$unknown_reader_ok = BizCity_JSONL_File_Logger::query_contract( 'core.invalid.contract' ) === array()
				&& BizCity_JSONL_File_Logger::read_contract( 'core.invalid.contract', '2026-09-02', 1 ) === array();
		}
		$emit( 'Runtime - traversal and unknown-contract refusal', $traversal_ok && $unknown_reader_ok ? 'pass' : 'fail', $traversal_ok && $unknown_reader_ok ? 'Traversal was refused and unknown contract readers returned no rows.' : 'A path or contract boundary returned unexpected data.' );

		$http_status = 'skip';
		$http_detail = 'Set BIZCITY_DIAGNOSTICS_HTTP_PROBE_URL to the exact deployed JSONL URL for HTTP denial evidence.';
		$url = getenv( 'BIZCITY_DIAGNOSTICS_HTTP_PROBE_URL' );
		if ( is_string( $url ) && $url !== '' && function_exists( 'wp_remote_get' ) ) {
			$response = wp_remote_get( $url, array( 'timeout' => 10, 'redirection' => 0, 'sslverify' => true ) );
			if ( is_wp_error( $response ) ) {
				$http_status = 'fail';
				$http_detail = 'Explicit JSONL URL request failed before an HTTP denial response was observed.';
			} else {
				$code = (int) wp_remote_retrieve_response_code( $response );
				$body = (string) wp_remote_retrieve_body( $response );
				$http_status = in_array( $code, array( 403, 404 ), true ) && strpos( $body, 'event_uuid' ) === false ? 'pass' : 'fail';
				$http_detail = $http_status === 'pass' ? 'Explicit JSONL URL returned denial without JSONL content.' : 'Explicit JSONL URL did not return a safe 403/404 denial.';
			}
		}
		$emit( 'Runtime - explicit HTTP direct-file denial', $http_status, $http_detail );

		$static_runtime_ok = $disk_ok && $route_ok && $artifact_ok && $traversal_ok && $unknown_reader_ok;
		$status = $static_runtime_ok && $http_status === 'pass' ? 'pass' : ( $static_runtime_ok && $http_status === 'skip' ? 'skip' : 'fail' );
		return array(
			'status' => $status,
			'summary' => $status === 'pass' ? 'JSONL public-file security and authorized Explorer scope passed.' : ( $status === 'skip' ? 'Code/runtime scope guards passed; deployed HTTP denial evidence is still required.' : 'JSONL security or Explorer scope contract failed.' ),
			'error' => $status === 'fail' ? 'log_security_scope_failed' : '',
			'fix_hint' => $status === 'pass' ? '' : 'Verify capability, registry-only contract scope, upload deny artifacts and run the exact mapped JSONL URL with BIZCITY_DIAGNOSTICS_HTTP_PROBE_URL.',
			'steps' => $steps,
		);
	}

	public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Log_Security_Scope';
	return $list;
} );
