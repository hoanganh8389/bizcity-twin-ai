<?php
/**
 * BizCity Diagnostics - astro.relation_ashtakoot_path probe (PHASE-FAA2-NEXT).
 *
 * 3-layer evidence (R-DDV):
 * - Disk: self-service REST uses BizCoach_Pro_Astro_Client::ashtakoot and no legacy wrapper call.
 * - Loader: required BizCoach classes/methods are loaded.
 * - Runtime: client wrapper returns canonical shape, relation handlers return R-ERROR-UX payload shape.
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

if ( class_exists( 'BizCity_Probe_Astro_Relation_Ashtakoot', false ) ) {
	return;
}

final class BizCity_Probe_Astro_Relation_Ashtakoot implements BizCity_Diagnostics_Probe {

	public function id(): string       { return 'astro.relation_ashtakoot_path'; }
	public function label(): string    { return 'Astro Relation Ashtakoot Path (BizCoach)'; }
	public function description(): string {
		return 'Verify relation -> ashtakoot wrapper path and R-ERROR-UX payload contract for relation handlers.';
	}
	public function severity(): string { return 'warning'; }
	public function order(): int       { return 46; }
	public function icon(): string     { return 'heart'; }
	public function estimate_ms(): int { return 350; }

	public function precondition() {
		if ( ! defined( 'BCPRO_DIR' ) ) {
			return 'BCPRO_DIR is not defined - bizcoach-pro may be inactive.';
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-07-07 Johnny Chu] PHASE-FAA2-NEXT - DDV probe for relation/ashtakoot path.
		$steps    = array();
		$failures = array();

		$bcpro_dir      = rtrim( (string) BCPRO_DIR, '/\\' );
		$rest_file      = $bcpro_dir . '/includes/frontend/class-self-service-rest.php';
		$relation_file  = $bcpro_dir . '/includes/astro/class-relation-manager.php';
		$client_file    = $bcpro_dir . '/includes/astro/class-astro-client.php';

		$disk_ok = file_exists( $rest_file ) && file_exists( $relation_file ) && file_exists( $client_file );
		$steps[] = array(
			'layer' => 'disk',
			'ok'    => $disk_ok,
			'msg'   => $disk_ok
				? 'Required files exist (self-service-rest, relation-manager, astro-client).'
				: 'Missing one or more required files in bizcoach-pro astro relation path.',
		);
		if ( ! $disk_ok ) {
			$failures[] = 'required_files_missing';
		}

		$rest_src = file_exists( $rest_file ) ? (string) file_get_contents( $rest_file ) : '';
		$has_wrapper_call = ( strpos( $rest_src, 'BizCoach_Pro_Astro_Client::ashtakoot' ) !== false );
		$has_legacy_call  = ( strpos( $rest_src, 'BizCity_Astro_Client::instance()->ashtakoot' ) !== false );
		$wrapper_ok       = $has_wrapper_call && ! $has_legacy_call;
		$steps[] = array(
			'layer' => 'disk',
			'ok'    => $wrapper_ok,
			'msg'   => $wrapper_ok
				? 'Wrapper callsite OK: BizCoach_Pro_Astro_Client::ashtakoot (legacy mismatch removed).'
				: 'Wrapper mismatch detected (missing BizCoach wrapper call or legacy BizCity instance call still present).',
		);
		if ( ! $wrapper_ok ) {
			$failures[] = 'wrapper_mismatch';
		}

		$rest_class_ok     = class_exists( 'BizCoach_Pro_Self_Service_REST' );
		$relation_class_ok = class_exists( 'BizCoach_Pro_Relation_Manager' );
		$client_class_ok   = class_exists( 'BizCoach_Pro_Astro_Client' );
		$steps[] = array(
			'layer' => 'loader',
			'ok'    => ( $rest_class_ok && $relation_class_ok && $client_class_ok ),
			'msg'   => ( $rest_class_ok && $relation_class_ok && $client_class_ok )
				? 'Required classes loaded (REST, Relation Manager, Astro Client).'
				: 'One or more required classes are not loaded.',
		);
		if ( ! $rest_class_ok || ! $relation_class_ok || ! $client_class_ok ) {
			$failures[] = 'classes_missing';
		}

		$method_ok = $rest_class_ok
			&& method_exists( 'BizCoach_Pro_Self_Service_REST', 'create_relation' )
			&& method_exists( 'BizCoach_Pro_Self_Service_REST', 'get_relation' )
			&& method_exists( 'BizCoach_Pro_Self_Service_REST', 'interpret_relation' )
			&& $client_class_ok
			&& method_exists( 'BizCoach_Pro_Astro_Client', 'ashtakoot' );
		$steps[] = array(
			'layer' => 'loader',
			'ok'    => $method_ok,
			'msg'   => $method_ok
				? 'Required methods declared (create/get/interpret_relation + astro client ashtakoot).'
				: 'Missing one or more required methods for relation/ashtakoot path.',
		);
		if ( ! $method_ok ) {
			$failures[] = 'methods_missing';
		}

		if ( $client_class_ok && method_exists( 'BizCoach_Pro_Astro_Client', 'ashtakoot' ) ) {
			try {
				$client_resp = BizCoach_Pro_Astro_Client::ashtakoot( array() );
				$client_ok = is_array( $client_resp )
					&& array_key_exists( 'success', $client_resp )
					&& array_key_exists( 'envelope', $client_resp );
				$steps[] = array(
					'layer' => 'runtime',
					'ok'    => $client_ok,
					'msg'   => $client_ok
						? 'Astro client ashtakoot() returned canonical wrapper shape.'
						: 'Astro client ashtakoot() returned invalid shape.',
				);
				if ( ! $client_ok ) {
					$failures[] = 'runtime_client_shape';
				}
			} catch ( \Throwable $e ) {
				$steps[] = array(
					'layer' => 'runtime',
					'ok'    => false,
					'msg'   => 'Astro client ashtakoot() threw exception: ' . $e->getMessage(),
				);
				$failures[] = 'runtime_client_exception';
			}
		}

		if ( $rest_class_ok && method_exists( 'BizCoach_Pro_Self_Service_REST', 'create_relation' ) ) {
			try {
				$req = new WP_REST_Request( 'POST', '/bizcity-bizcoach/v1/me/relations' );
				$req->set_param( 'subject_coachee', 0 );
				$resp = BizCoach_Pro_Self_Service_REST::create_relation( $req );
				$data = ( $resp instanceof WP_REST_Response ) ? $resp->get_data() : $resp;
				$payload_ok = is_array( $data )
					&& isset( $data['success'] )
					&& false === $data['success']
					&& isset( $data['code'] )
					&& isset( $data['message'] )
					&& array_key_exists( 'hint', $data )
					&& array_key_exists( 'help_code', $data );
				$steps[] = array(
					'layer' => 'runtime',
					'ok'    => $payload_ok,
					'msg'   => $payload_ok
						? 'create_relation() returns R-ERROR-UX payload shape on invalid input.'
						: 'create_relation() does not return R-ERROR-UX payload shape.',
				);
				if ( ! $payload_ok ) {
					$failures[] = 'runtime_create_payload';
				}
			} catch ( \Throwable $e ) {
				$steps[] = array(
					'layer' => 'runtime',
					'ok'    => false,
					'msg'   => 'create_relation() runtime exception: ' . $e->getMessage(),
				);
				$failures[] = 'runtime_create_exception';
			}
		}

		if ( $rest_class_ok && method_exists( 'BizCoach_Pro_Self_Service_REST', 'interpret_relation' ) ) {
			try {
				$req = new WP_REST_Request( 'POST', '/bizcity-bizcoach/v1/me/relations/0/interpret' );
				$req->set_param( 'id', 0 );
				$resp = BizCoach_Pro_Self_Service_REST::interpret_relation( $req );
				$data = ( $resp instanceof WP_REST_Response ) ? $resp->get_data() : $resp;
				$payload_ok = is_array( $data )
					&& isset( $data['success'] )
					&& false === $data['success']
					&& isset( $data['code'] )
					&& isset( $data['message'] )
					&& array_key_exists( 'hint', $data )
					&& array_key_exists( 'help_code', $data );
				$steps[] = array(
					'layer' => 'runtime',
					'ok'    => $payload_ok,
					'msg'   => $payload_ok
						? 'interpret_relation() returns R-ERROR-UX payload shape for invalid relation id.'
						: 'interpret_relation() does not return R-ERROR-UX payload shape.',
				);
				if ( ! $payload_ok ) {
					$failures[] = 'runtime_interpret_payload';
				}
			} catch ( \Throwable $e ) {
				$steps[] = array(
					'layer' => 'runtime',
					'ok'    => false,
					'msg'   => 'interpret_relation() runtime exception: ' . $e->getMessage(),
				);
				$failures[] = 'runtime_interpret_exception';
			}
		}

		if ( ! empty( $failures ) ) {
			return array(
				'status'   => 'fail',
				'steps'    => $steps,
				'summary'  => 'Astro relation/ashtakoot path issue(s): ' . implode( ', ', $failures ),
				'error'    => implode( '; ', $failures ),
				'fix_hint' => 'Check wrapper callsite in self-service REST and normalize relation handlers to BizCity_Error_Payload::make().',
			);
		}

		return array(
			'status'  => 'pass',
			'steps'   => $steps,
			'summary' => 'Astro relation/ashtakoot path OK - wrapper contract and relation error payloads are valid.',
		);
	}

	public function cleanup(): void {
		// Read-only probe.
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Astro_Relation_Ashtakoot';
	return $list;
} );
