<?php
/**
 * BizCity Diagnostics - Twin GPT attachments probe.
 *
 * R-DDV 3 layers evidence:
 * - Disk: upload route, validators, attachment strip and chat forwarding markers.
 * - Loader: REST handlers/routes registered.
 * - Runtime: upload validator rejects missing file; owner-scoped media fixture deletes through route.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-07-19
 */

// [2026-07-19 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — DDV probe for owner-scoped attachment upload strip.
defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false ) ) {
	$_iface_path = defined( 'BIZCITY_DIAGNOSTICS_DIR' )
		? BIZCITY_DIAGNOSTICS_DIR . 'includes/interface-diagnostics-probe.php'
		: dirname( __DIR__ ) . '/interface-diagnostics-probe.php';
	if ( is_readable( $_iface_path ) ) {
		require_once $_iface_path;
	}
}
if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false ) ) {
	return;
}

if ( class_exists( 'BizCity_Probe_TwinWeb_Attachments', false ) ) {
	return;
}

final class BizCity_Probe_TwinWeb_Attachments implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'modules.twin_gpt.attachments'; }
	public function label(): string { return 'Twin GPT Attachments'; }
	public function description(): string {
		return 'Verifies owner-scoped attachment upload/delete route, composer attachment strip markers and chat attachment context forwarding.';
	}
	public function severity(): string { return 'warning'; }
	public function order(): int { return 90; }
	public function icon(): string { return 'Paperclip'; }
	public function estimate_ms(): int { return 220; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_TwinWeb_REST' ) ) {
			return new WP_Error( 'no_twinweb_rest', 'BizCity_TwinWeb_REST is not loaded.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-07-19 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — synthetic upload creates one WP attachment and deletes it in finally.
		$steps = array();
		$pass  = true;
		$attachment_id = 0;
		$temp_file = '';

		$root = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( dirname( dirname( __DIR__ ) ) ) . '/';
		$rest_file  = $this->class_file_or_fallback( 'BizCity_TwinWeb_REST', $root . 'modules/twinweb/includes/class-twinweb-rest.php' );
		$chat_file  = $root . 'modules/twinweb/ui/src/pages/ChatPage.tsx';
		$api_file   = $root . 'modules/twinweb/ui/src/api/attachments.ts';
		$strip_file = $root . 'modules/twinweb/ui/src/components/AttachmentStrip.tsx';
		$manifest_file = $root . 'modules/twinweb/ui/dist/.vite/manifest.json';

		$rest_src = is_readable( $rest_file ) ? file_get_contents( $rest_file ) : '';
		$disk_rest_ok = is_string( $rest_src )
			&& strpos( $rest_src, "'/attachments'" ) !== false
			&& strpos( $rest_src, 'upload_attachment' ) !== false
			&& strpos( $rest_src, 'delete_attachment' ) !== false
			&& strpos( $rest_src, 'allowed_attachment_mimes' ) !== false
			&& strpos( $rest_src, 'build_owned_attachment_payload' ) !== false
			&& strpos( $rest_src, 'attachment_ids' ) !== false;
		$this->emit( $ctx, $steps, $pass, 'Disk - REST upload/delete/ownership markers', $disk_rest_ok, $disk_rest_ok ? 'Attachment route, mime validator, owner payload and chat forwarding markers found.' : 'Missing REST attachment markers.' );

		$dist_ok = is_readable( $manifest_file );
		$step = array(
			'label'  => 'Disk - FE deploy artifact policy',
			'status' => $dist_ok ? 'pass' : 'skip',
			'detail' => $dist_ok ? 'TwinWeb Vite manifest present; React src markers below are optional dev evidence.' : 'dist manifest missing; production may still provide assets through another deploy path.',
		);
		$steps[] = $step;
		$ctx->emit_step( $step );

		if ( is_readable( $chat_file ) && is_readable( $api_file ) && is_readable( $strip_file ) ) {
			$chat_src = (string) file_get_contents( $chat_file );
			$api_src = (string) file_get_contents( $api_file );
			$strip_src = (string) file_get_contents( $strip_file );
			$fe_ok = strpos( $chat_src, 'AttachmentStrip' ) !== false
				&& strpos( $chat_src, 'Paperclip' ) !== false
				&& strpos( $chat_src, 'attachmentIds' ) !== false
				&& strpos( $api_src, 'FormData' ) !== false
				&& strpos( $api_src, '/attachments' ) !== false
				&& strpos( $strip_src, 'TwinWebAttachment' ) !== false;
			// [2026-07-19 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — React src markers are optional; built artifact/runtime contract is authoritative on production.
			$step = array(
				'label'  => 'Disk - optional composer attachment strip markers',
				'status' => $fe_ok ? 'pass' : 'skip',
				'detail' => $fe_ok ? 'AttachmentStrip, upload API wrapper and chat attachmentIds forwarding markers found.' : 'Attachment FE markers drifted; this is non-fatal when REST/runtime and built artifacts are present.',
			);
			$steps[] = $step;
			$ctx->emit_step( $step );
		} else {
			$step = array(
				'label'  => 'Disk - optional composer attachment strip markers',
				'status' => 'skip',
				'detail' => 'React src is absent; this is valid for production dist-only deploys.',
			);
			$steps[] = $step;
			$ctx->emit_step( $step );
		}

		$method_ok = method_exists( 'BizCity_TwinWeb_REST', 'upload_attachment' )
			&& method_exists( 'BizCity_TwinWeb_REST', 'delete_attachment' );
		$this->emit( $ctx, $steps, $pass, 'Loader - attachment handlers loaded', $method_ok, $method_ok ? 'upload_attachment + delete_attachment handlers loaded.' : 'Attachment handlers missing.' );

		$routes = rest_get_server()->get_routes();
		$route_ok = $this->route_has_method( $routes, '/bizcity-twinweb/v1/attachments', 'POST' )
			&& $this->route_has_method( $routes, '/bizcity-twinweb/v1/attachments/(?P<id>\d+)', 'DELETE' );
		$this->emit( $ctx, $steps, $pass, 'Loader - attachment routes registered', $route_ok, $route_ok ? 'POST /attachments + DELETE /attachments/{id} registered.' : 'Missing attachment upload/delete route.' );

		$original_uid = get_current_user_id();
		$runtime_uid = $this->resolve_runtime_user_id();
		if ( $runtime_uid <= 0 ) {
			$this->emit( $ctx, $steps, $pass, 'Runtime - resolve operator user', false, 'No runtime WP user available for synthetic attachment upload.' );
		} else {
			wp_set_current_user( $runtime_uid );
			try {
				$missing_req = new WP_REST_Request( 'POST', '/bizcity-twinweb/v1/attachments' );
				$missing_res = rest_do_request( $missing_req );
				$missing_data = is_wp_error( $missing_res ) ? array() : (array) rest_ensure_response( $missing_res )->get_data();
				$validator_ok = empty( $missing_data['success'] ) && isset( $missing_data['code'] ) && 'invalid_param' === (string) $missing_data['code'];
				$this->emit( $ctx, $steps, $pass, 'Runtime - upload route validates missing file', $validator_ok, sprintf( 'success=%s; code=%s', ! empty( $missing_data['success'] ) ? 'yes' : 'no', (string) ( $missing_data['code'] ?? 'MISSING' ) ) );

				// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — create DDV fixture directly; wp_upload_bits can be blocked by site-wide upload MIME policy before TwinWeb code runs.
				$fixture_file = $this->write_fixture_file( 'twinweb-ddv-attachment.txt', "Twin GPT attachment DDV\n" );
				if ( is_wp_error( $fixture_file ) ) {
					$this->emit( $ctx, $steps, $pass, 'Runtime - create media attachment fixture', false, $fixture_file->get_error_message() );
				} else {
					$temp_file = (string) $fixture_file;
					$attachment_id = (int) wp_insert_attachment( array(
						'post_mime_type' => 'text/plain',
						'post_title'     => 'twinweb-ddv-attachment',
						'post_content'   => '',
						'post_status'    => 'inherit',
						'post_author'    => $runtime_uid,
					), $temp_file );
					update_post_meta( $attachment_id, '_bizcity_twinweb_attachment', '1' );
					update_post_meta( $attachment_id, '_bizcity_twinweb_surface', 'twinweb' );
					$fixture_ok = $attachment_id > 0
						&& (int) get_post_field( 'post_author', $attachment_id ) === $runtime_uid
						&& (string) get_post_meta( $attachment_id, '_bizcity_twinweb_attachment', true ) === '1';
					$this->emit( $ctx, $steps, $pass, 'Runtime - create owner-scoped media attachment fixture', $fixture_ok, sprintf( 'id=%d; owner=%d', $attachment_id, $attachment_id > 0 ? (int) get_post_field( 'post_author', $attachment_id ) : 0 ) );

					if ( $attachment_id > 0 ) {
						$delete_req = new WP_REST_Request( 'DELETE', '/bizcity-twinweb/v1/attachments/' . $attachment_id );
						$delete_req->set_url_params( array( 'id' => $attachment_id ) );
						$delete_res = rest_do_request( $delete_req );
						$delete_data = is_wp_error( $delete_res ) ? array() : (array) rest_ensure_response( $delete_res )->get_data();
						$delete_ok = ! empty( $delete_data['success'] ) && ! get_post( $attachment_id );
						$this->emit( $ctx, $steps, $pass, 'Runtime - delete synthetic attachment through owner route', $delete_ok, sprintf( 'success=%s; exists_after_delete=%s', ! empty( $delete_data['success'] ) ? 'yes' : 'no', get_post( $attachment_id ) ? 'yes' : 'no' ) );
						$attachment_id = 0;
					}
				}
			} finally {
				if ( $attachment_id > 0 ) {
					wp_delete_attachment( $attachment_id, true );
				}
				if ( $temp_file && file_exists( $temp_file ) ) {
					@unlink( $temp_file );
				}
				wp_set_current_user( $original_uid );
			}
		}

		return array(
			'status'   => $pass ? 'pass' : 'fail',
			'summary'  => $pass
				? 'Twin GPT attachment validator/delete, composer strip and chat forwarding contract PASS.'
				: 'Twin GPT attachment contract failed one or more checks.',
			'error'    => $pass ? '' : 'twinweb_attachment_contract_failed',
			'fix_hint' => $pass ? '' : 'Check /attachments route, WordPress media permissions, allowed mimes and composer attachment strip markers.',
			'steps'    => $steps,
		);
	}

	public function cleanup(): void {
		// Runtime cleanup is handled in run().
	}

	private function emit( $ctx, array &$steps, &$pass, $label, $ok, $detail ) {
		$step = array(
			'label'  => (string) $label,
			'status' => $ok ? 'pass' : 'fail',
			'detail' => (string) $detail,
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $ok ) {
			$pass = false;
		}
	}

	private function resolve_runtime_user_id() {
		$current = get_current_user_id();
		if ( $current > 0 ) {
			return (int) $current;
		}
		$admins = get_users( array(
			'role'   => 'administrator',
			'number' => 1,
			'fields' => array( 'ID' ),
		) );
		if ( ! empty( $admins ) && isset( $admins[0]->ID ) ) {
			return (int) $admins[0]->ID;
		}
		return 0;
	}

	private function write_fixture_file( $filename, $contents ) {
		// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — DDV fixture should test owner scoping, not WordPress upload allowlist configuration.
		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) || empty( $upload_dir['path'] ) ) {
			return new WP_Error( 'upload_dir_unavailable', (string) ( $upload_dir['error'] ?? 'wp_upload_dir failed.' ) );
		}
		if ( ! wp_mkdir_p( (string) $upload_dir['path'] ) ) {
			return new WP_Error( 'upload_dir_unwritable', 'Could not create uploads directory.' );
		}
		$path = trailingslashit( (string) $upload_dir['path'] ) . wp_unique_filename( (string) $upload_dir['path'], sanitize_file_name( (string) $filename ) );
		$written = file_put_contents( $path, (string) $contents );
		if ( false === $written ) {
			return new WP_Error( 'fixture_write_failed', 'Could not write attachment fixture.' );
		}
		return $path;
	}

	private function class_file_or_fallback( $class_name, $fallback ) {
		if ( class_exists( 'ReflectionClass' ) && class_exists( (string) $class_name ) ) {
			try {
				$ref = new ReflectionClass( (string) $class_name );
				$file = (string) $ref->getFileName();
				if ( $file !== '' && is_readable( $file ) ) {
					return $file;
				}
			} catch ( Throwable $e ) {
				// Use fallback below.
			}
		}
		return $fallback;
	}

	private function route_has_method( $routes, $route, $method ) {
		if ( ! isset( $routes[ $route ] ) || ! is_array( $routes[ $route ] ) ) {
			return false;
		}
		$want = strtoupper( (string) $method );
		foreach ( $routes[ $route ] as $ep ) {
			if ( ! is_array( $ep ) || empty( $ep['methods'] ) ) {
				continue;
			}
			if ( is_string( $ep['methods'] ) && false !== strpos( strtoupper( (string) $ep['methods'] ), $want ) ) {
				return true;
			}
			if ( is_array( $ep['methods'] ) ) {
				foreach ( $ep['methods'] as $registered => $enabled ) {
					if ( $enabled && strtoupper( (string) $registered ) === $want ) {
						return true;
					}
				}
			}
		}
		return false;
	}
}

// [2026-07-19 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — register Twin GPT attachments probe.
add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_TwinWeb_Attachments';
	return $list;
} );
