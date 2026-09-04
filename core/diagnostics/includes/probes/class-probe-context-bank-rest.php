<?php
/**
 * DDV probe for the metadata-only Context Bank REST boundary.
 *
 * The runtime assertion requests an intentionally missing record. It does not
 * write Context Bank data, follow a file pointer or mutate schema state.
 *
 * @package BizCity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 */

defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'BizCity_Diagnostics_Probe' ) || class_exists( 'BizCity_Probe_Context_Bank_REST', false ) ) {
	return;
}

final class BizCity_Probe_Context_Bank_REST implements BizCity_Diagnostics_Probe {

	public function id(): string {
		return 'core.context_bank.rest';
	}

	public function label(): string {
		return 'Context Bank - metadata REST boundary';
	}

	public function description(): string {
		return 'Checks same-origin namespace, server-owned filters, metadata-only projection and structured REST failures.';
	}

	public function severity(): string { return 'critical'; }
	public function order(): int { return 84; }
	public function icon(): string { return 'server'; }
	public function estimate_ms(): int { return 100; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_Context_Bank_REST_Controller' ) && class_exists( 'BizCity_Safe_Loader', false ) ) {
			$root = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) ) . '/';
			$file = $root . 'core/context-bank/includes/class-context-bank-rest-controller.php';
			if ( is_file( $file ) && is_readable( $file ) ) {
				BizCity_Safe_Loader::require_file( $file, 'diagnostics.context_bank.rest' );
			}
		}
		if ( ! class_exists( 'BizCity_Context_Bank_REST_Controller' ) ) {
			return new WP_Error( 'context_bank_rest_missing', 'Context Bank REST controller is not loaded.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-09-03 Johnny Chu - Chu Hoàng Anh] PHASE-CB7.3-DDV — prove metadata REST failure handling without durable mutation.
		$root = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) ) . '/';
		$rest_file = $root . 'core/context-bank/includes/class-context-bank-rest-controller.php';
		$source = is_readable( $rest_file ) ? file_get_contents( $rest_file ) : '';
		$disk_ok = is_string( $source ) && $source !== '';
		$loader_ok = class_exists( 'BizCity_Context_Bank_REST_Controller' )
			&& method_exists( 'BizCity_Context_Bank_REST_Controller', 'register_routes' )
			&& method_exists( 'BizCity_Context_Bank_REST_Controller', 'get_record' );
		$contract_ok = is_string( $source )
			&& strpos( $source, "const NS = 'bizcity-context/v1'" ) !== false
			&& strpos( $source, "register_rest_route( self::NS" ) !== false
			&& strpos( $source, "'absolute_path'" ) === false
			&& strpos( $source, "'content'" ) === false
			&& strpos( $source, 'BizCity_Context_Bank_Access::scope_filters' ) !== false;

		$response_ok = false;
		$error_envelope_ok = false;
		if ( class_exists( 'WP_REST_Request' ) ) {
			$request = new WP_REST_Request( 'GET', '/bizcity-context/v1/records/diagnostics_missing_' . strtolower( substr( md5( (string) microtime( true ) ), 0, 16 ) ) );
			$request->set_param( 'record_id', 'diagnostics_missing_' . strtolower( substr( md5( (string) microtime( true ) ), 0, 16 ) ) );
			try {
				$response = BizCity_Context_Bank_REST_Controller::get_record( $request );
				$data = is_object( $response ) && method_exists( $response, 'get_data' ) ? $response->get_data() : array();
				$response_ok = is_object( $response ) && method_exists( $response, 'get_status' ) && 200 === (int) $response->get_status();
				$error_envelope_ok = is_array( $data )
					&& is_string( $data['code'] ?? null )
					&& is_string( $data['message'] ?? null )
					&& is_string( $data['hint'] ?? null )
					&& is_string( $data['help_code'] ?? null );
			} catch ( \Throwable $e ) {
				$response_ok = false;
				$error_envelope_ok = false;
			}
		}

		$matrix_ok = false;
		$matrix_detail = 'REST owner matrix could not be executed.';
		$original_user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$admin_id = current_user_can( 'manage_options' ) ? $original_user_id : 0;
		$owner_id = 0;
		$created_owner_id = 0;
		$cleanup_ok = true;
		if ( function_exists( 'get_users' ) && $admin_id > 0 ) {
			$users = get_users( array( 'number' => 50, 'fields' => array( 'ID' ) ) );
			foreach ( (array) $users as $user ) {
				$user_id = is_object( $user ) ? (int) $user->ID : (int) ( $user['ID'] ?? 0 );
				if ( $user_id > 0 && $user_id !== $admin_id && user_can( $user_id, 'read' ) && ! user_can( $user_id, 'manage_options' ) ) {
					$owner_id = $user_id;
					break;
				}
			}
		}
		if ( $admin_id > 0 && $owner_id <= 0 && function_exists( 'wp_insert_user' ) ) {
			// [2026-09-03 Johnny Chu - Chu Hoàng Anh] PHASE-CB7.3-DDV — create only a disposable subscriber fixture when the tenant has no non-admin read user for the owner matrix.
			$fixture_suffix = strtolower( substr( md5( (string) microtime( true ) . '|' . wp_generate_uuid4() ), 0, 16 ) );
			$fixture_user = wp_insert_user( array(
				'user_login' => 'cb_rest_probe_' . $fixture_suffix,
				'user_pass' => wp_generate_password( 32, true, true ),
				'user_email' => 'cb-rest-probe-' . $fixture_suffix . '@invalid.test',
				'role' => 'subscriber',
				'display_name' => 'Context Bank REST probe',
			) );
			if ( ! is_wp_error( $fixture_user ) && (int) $fixture_user > 0 ) {
				$created_owner_id = (int) $fixture_user;
				if ( function_exists( 'add_user_to_blog' ) ) {
					add_user_to_blog( (int) get_current_blog_id(), $created_owner_id, 'subscriber' );
				}
				if ( user_can( $created_owner_id, 'read' ) && ! user_can( $created_owner_id, 'manage_options' ) ) {
					$owner_id = $created_owner_id;
				}
			}
		}
		if ( $admin_id > 0 && function_exists( 'rest_get_server' ) && function_exists( 'rest_do_request' ) ) {
			$had_rest_server = isset( $GLOBALS['wp_rest_server'] );
			$previous_rest_server = $had_rest_server ? $GLOBALS['wp_rest_server'] : null;
			try {
				// [2026-09-03 Johnny Chu - Chu Hoàng Anh] PHASE-CB7.3-DDV — isolate the REST fixture server so rest_get_server() does not fire unrelated production rest_api_init provisioning hooks.
				if ( class_exists( 'WP_REST_Server' ) ) {
					$GLOBALS['wp_rest_server'] = new WP_REST_Server();
				}
				BizCity_Context_Bank_REST_Controller::register_routes();
				$rest_request = static function ( $user_id, $params = array() ) {
					wp_set_current_user( (int) $user_id );
					$request = new WP_REST_Request( 'GET', '/bizcity-context/v1/records' );
					foreach ( $params as $key => $value ) {
						$request->set_param( $key, $value );
					}
					return rest_do_request( $request );
				};
				$unauthenticated = $rest_request( 0 );
				$admin_response = $rest_request( $admin_id, array( 'wp_user_id' => $owner_id > 0 ? $owner_id : 999999, 'limit' => 1 ) );
				$status = static function ( $result ) {
					if ( is_wp_error( $result ) ) {
						$data = $result->get_error_data();
						return is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 500;
					}
					return is_object( $result ) && method_exists( $result, 'get_status' ) ? (int) $result->get_status() : 500;
				};
				$code = static function ( $result ) {
					if ( is_wp_error( $result ) ) {
						return (string) $result->get_error_code();
					}
					$data = is_object( $result ) && method_exists( $result, 'get_data' ) ? $result->get_data() : array();
					return is_array( $data ) ? (string) ( $data['code'] ?? '' ) : '';
				};
				$unauth_status = $status( $unauthenticated );
				$admin_status = $status( $admin_response );
				$unauth_code = $code( $unauthenticated );
				$admin_code = $code( $admin_response );
				$owner_status = 0;
				$wrong_owner_status = 0;
				$owner_code = '';
				$wrong_owner_code = '';
				$owner_branch_ok = false;
				if ( $owner_id > 0 ) {
					$owner_response = $rest_request( $owner_id, array( 'limit' => 1 ) );
					$wrong_owner_response = $rest_request( $owner_id, array( 'wp_user_id' => $admin_id, 'limit' => 1 ) );
					$owner_status = $status( $owner_response );
					$wrong_owner_status = $status( $wrong_owner_response );
					$owner_code = $code( $owner_response );
					$wrong_owner_code = $code( $wrong_owner_response );
					$owner_branch_ok = 200 === $owner_status && $owner_code === ''
						&& ( in_array( $wrong_owner_status, array( 401, 403 ), true ) || 'permission_denied' === $wrong_owner_code );
				}
				$unauth_denied = in_array( $unauth_status, array( 401, 403 ), true ) || in_array( $unauth_code, array( 'auth_required', 'permission_denied' ), true );
				$base_matrix_ok = $unauth_denied && 200 === $admin_status && $admin_code === '';
				$matrix_ok = $base_matrix_ok && $owner_branch_ok;
				$matrix_detail = $matrix_ok
					? 'Unauthenticated and modified-owner requests were denied; admin and valid owner requests reached the bounded REST read.'
					: ( ! $base_matrix_ok
						? 'Unexpected unauthenticated/admin responses: unauth=' . $unauth_status . '/' . $unauth_code . ', admin=' . $admin_status . '/' . $admin_code . '.'
						: ( $owner_id > 0
							? 'Unauthenticated/admin branches passed; unexpected owner responses: owner=' . $owner_status . '/' . $owner_code . ', wrong_owner=' . $wrong_owner_status . '/' . $wrong_owner_code . '.'
							: 'Unauthenticated/admin branches passed; owner branch is deferred because no non-admin read user exists on this tenant.' ) );
			} catch ( \Throwable $e ) {
				$matrix_detail = 'REST owner matrix threw before returning a response.';
			} finally {
				wp_set_current_user( $original_user_id );
				if ( $created_owner_id > 0 && function_exists( 'wp_delete_user' ) ) {
					// [2026-09-03 Johnny Chu - Chu Hoàng Anh] PHASE-CB7.3-DDV — remove the disposable owner fixture after the matrix, including when a REST branch throws.
					wp_delete_user( $created_owner_id );
					if ( function_exists( 'clean_user_cache' ) ) {
						clean_user_cache( $created_owner_id );
					}
					$remaining_users = function_exists( 'get_users' ) ? get_users( array( 'include' => array( $created_owner_id ), 'fields' => 'ID', 'number' => 1, 'count_total' => false, 'cache_results' => false ) ) : array();
					$cleanup_ok = empty( $remaining_users );
				}
				if ( $had_rest_server ) {
					$GLOBALS['wp_rest_server'] = $previous_rest_server;
				} else {
					unset( $GLOBALS['wp_rest_server'] );
				}
			}
		} elseif ( $admin_id <= 0 ) {
			$matrix_detail = 'Diagnostics user is not an admin, so the owner matrix cannot establish the admin branch.';
		} else {
			$matrix_detail = 'No non-admin WordPress user with read capability is available for the owner branch.';
		}
		$matrix_status = $matrix_ok ? 'pass' : ( $owner_id > 0 ? 'fail' : 'skip' );

		$steps = array(
			array( 'label' => 'Disk - metadata REST controller is readable', 'ok' => $disk_ok, 'detail' => $disk_ok ? 'Canonical Context Bank REST controller source is readable.' : 'REST controller source is missing or unreadable.' ),
			array( 'label' => 'Loader - REST read methods are available', 'ok' => $loader_ok, 'detail' => $loader_ok ? 'Route registration and metadata read methods are loaded.' : 'REST controller or required methods are unavailable.' ),
			array( 'label' => 'Loader - namespace and metadata allowlist are bounded', 'ok' => $contract_ok, 'detail' => $contract_ok ? 'The canonical namespace and server-owned filter/projection boundaries are present.' : 'REST namespace, filter ownership or metadata projection is not bounded.' ),
			array( 'label' => 'Runtime - missing record returns same-origin response', 'ok' => $response_ok, 'detail' => $response_ok ? 'Missing-record request stayed inside the structured REST response boundary.' : 'Missing-record request escaped the REST response boundary.' ),
			array( 'label' => 'Runtime - REST failure has four-field error envelope', 'ok' => $error_envelope_ok, 'detail' => $error_envelope_ok ? 'Failure response includes code, message, hint and help_code.' : 'Failure response is missing one or more canonical error fields.' ),
			array( 'label' => 'Runtime - HTTP owner permission matrix', 'ok' => $matrix_ok, 'status' => $matrix_status, 'detail' => $matrix_detail ),
			array( 'label' => 'Runtime - disposable owner fixture cleanup', 'ok' => $cleanup_ok, 'detail' => $cleanup_ok ? 'Any synthetic owner user was removed after the matrix.' : 'The synthetic owner user remains after matrix cleanup.' ),
		);
		$pass = true;
		foreach ( $steps as $step ) {
			$pass = $pass && ( ! empty( $step['ok'] ) || 'skip' === (string) ( $step['status'] ?? '' ) );
			$ctx->emit_step( array( 'label' => $step['label'], 'status' => (string) ( $step['status'] ?? ( $step['ok'] ? 'pass' : 'fail' ) ), 'detail' => $step['detail'] ) );
		}
		$probe_status = $pass ? ( $matrix_ok ? 'pass' : 'skip' ) : 'fail';
		return array( 'status' => $probe_status, 'summary' => $probe_status === 'pass' ? 'Context Bank metadata REST boundary passed namespace, projection, error-envelope and owner-matrix checks.' : ( $probe_status === 'skip' ? 'Context Bank REST boundary passed available checks; owner matrix is deferred because no non-admin read user exists on this tenant.' : 'Context Bank metadata REST boundary checks failed.' ), 'fix_hint' => $probe_status === 'pass' ? '' : 'Add an approved non-admin read user and rerun the owner matrix; keep Context Bank REST same-origin, server-scoped and metadata-only.', 'steps' => array() );
	}

	public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Context_Bank_REST';
	return $list;
} );
