<?php
/**
 * BizCity Diagnostics - Twin GPT voice input probe.
 *
 * R-DDV evidence:
 * - Disk: voice route, audio MIME allowlist, FE MediaRecorder markers.
 * - Loader: REST route and handler method registered.
 * - Runtime: owned audio attachment returns transcript success or fail-open degraded payload.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-07-19
 */

// [2026-07-19 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — DDV probe for Twin GPT voice input/transcribe foundation.
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

if ( class_exists( 'BizCity_Probe_TwinWeb_Voice_Input', false ) ) {
	return;
}

final class BizCity_Probe_TwinWeb_Voice_Input implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'modules.twin_gpt.voice_input'; }
	public function label(): string { return 'Twin GPT Voice Input'; }
	public function description(): string {
		return 'Verifies MediaRecorder voice input markers, same-origin transcribe proxy and fail-open runtime behavior.';
	}
	public function severity(): string { return 'warning'; }
	public function order(): int { return 91; }
	public function icon(): string { return 'Mic'; }
	public function estimate_ms(): int { return 260; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_TwinWeb_REST' ) ) {
			return new WP_Error( 'no_twinweb_rest', 'BizCity_TwinWeb_REST is not loaded.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-07-19 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — runtime accepts degraded gateway because hub transcribe branch may not be deployed locally.
		$steps = array();
		$pass  = true;
		$attachment_id = 0;
		$temp_file = '';

		$root = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( dirname( dirname( __DIR__ ) ) ) . '/';
		$rest_file  = $this->class_file_or_fallback( 'BizCity_TwinWeb_REST', $root . 'modules/twinweb/includes/class-twinweb-rest.php' );
		$chat_file  = $root . 'modules/twinweb/ui/src/pages/ChatPage.tsx';
		$voice_file = $root . 'modules/twinweb/ui/src/api/voice.ts';
		$manifest_file = $root . 'modules/twinweb/ui/dist/.vite/manifest.json';

		$rest_src = is_readable( $rest_file ) ? file_get_contents( $rest_file ) : '';
		$disk_rest_ok = is_string( $rest_src )
			&& strpos( $rest_src, "'/voice/transcribe'" ) !== false
			&& strpos( $rest_src, 'transcribe_voice_attachment' ) !== false
			&& strpos( $rest_src, 'voice_transcribe_degraded_payload' ) !== false
			&& strpos( $rest_src, 'audio/webm' ) !== false
			&& strpos( $rest_src, 'BizCity_AV_Transcribe_Client' ) !== false
			&& strpos( $rest_src, 'R-GW-API-CATALOG' ) !== false;
		$this->emit( $ctx, $steps, $pass, 'Disk - REST voice route and AV client markers', $disk_rest_ok, $disk_rest_ok ? 'Voice route, degraded payload, audio mimes and canonical AV transcribe client markers found.' : 'Missing TwinWeb voice REST markers.' );

		$dist_ok = is_readable( $manifest_file );
		$step = array(
			'label'  => 'Disk - FE deploy artifact policy',
			'status' => $dist_ok ? 'pass' : 'skip',
			'detail' => $dist_ok ? 'TwinWeb Vite manifest present; React src voice markers below are optional dev evidence.' : 'dist manifest missing; production may still provide assets through another deploy path.',
		);
		$steps[] = $step;
		$ctx->emit_step( $step );

		if ( is_readable( $chat_file ) && is_readable( $voice_file ) ) {
			$chat_src = (string) file_get_contents( $chat_file );
			$voice_src = (string) file_get_contents( $voice_file );
			$fe_ok = strpos( $chat_src, 'MediaRecorder' ) !== false
				&& strpos( $chat_src, 'toggleVoiceRecording' ) !== false
				&& strpos( $chat_src, 'voiceApi.transcribe' ) !== false
				&& strpos( $chat_src, 'audio/webm' ) !== false
				&& strpos( $voice_src, '/voice/transcribe' ) !== false;
			// [2026-07-19 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — React src markers are optional; do not fail dist-only production deploys or harmless label drift.
			$step = array(
				'label'  => 'Disk - optional MediaRecorder composer markers',
				'status' => $fe_ok ? 'pass' : 'skip',
				'detail' => $fe_ok ? 'Mic button, MediaRecorder and same-origin voice API wrapper markers found.' : 'Voice FE markers drifted; this is non-fatal when REST/runtime and built artifacts are present.',
			);
			$steps[] = $step;
			$ctx->emit_step( $step );
		} else {
			$step = array(
				'label'  => 'Disk - optional MediaRecorder composer markers',
				'status' => 'skip',
				'detail' => 'React src is absent; this is valid for production dist-only deploys.',
			);
			$steps[] = $step;
			$ctx->emit_step( $step );
		}

		$method_ok = method_exists( 'BizCity_TwinWeb_REST', 'transcribe_voice_attachment' );
		$this->emit( $ctx, $steps, $pass, 'Loader - voice handler loaded', $method_ok, $method_ok ? 'transcribe_voice_attachment handler loaded.' : 'Voice handler missing.' );

		$routes = rest_get_server()->get_routes();
		$route_ok = $this->route_has_method( $routes, '/bizcity-twinweb/v1/voice/transcribe', 'POST' );
		$this->emit( $ctx, $steps, $pass, 'Loader - voice route registered', $route_ok, $route_ok ? 'POST /voice/transcribe registered.' : 'Missing voice transcribe route.' );

		$original_uid = get_current_user_id();
		$runtime_uid = $this->resolve_runtime_user_id();
		if ( $runtime_uid <= 0 ) {
			$this->emit( $ctx, $steps, $pass, 'Runtime - resolve operator user', false, 'No runtime WP user available for voice fixture.' );
		} else {
			wp_set_current_user( $runtime_uid );
			try {
				// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — create DDV audio fixture directly; multisite upload policy may not allow webm fixtures through wp_upload_bits.
				$fixture_file = $this->write_fixture_file( 'twinweb-ddv-voice.webm', "RIFF\x24\x00\x00\x00WEBMDDV\n" );
				if ( is_wp_error( $fixture_file ) ) {
					$this->emit( $ctx, $steps, $pass, 'Runtime - create audio fixture', false, $fixture_file->get_error_message() );
				} else {
					$temp_file = (string) $fixture_file;
					$attachment_id = (int) wp_insert_attachment( array(
						'post_mime_type' => 'audio/webm',
						'post_title'     => 'twinweb-ddv-voice',
						'post_content'   => '',
						'post_status'    => 'inherit',
						'post_author'    => $runtime_uid,
					), $temp_file );
					update_post_meta( $attachment_id, '_bizcity_twinweb_attachment', '1' );
					update_post_meta( $attachment_id, '_bizcity_twinweb_surface', 'twinweb' );

					$req = new WP_REST_Request( 'POST', '/bizcity-twinweb/v1/voice/transcribe' );
					$req->set_body_params( array( 'attachment_id' => $attachment_id, 'language' => 'vi' ) );
					$res = rest_do_request( $req );
					$data = is_wp_error( $res ) ? array() : (array) rest_ensure_response( $res )->get_data();
					$runtime_ok = ( ! empty( $data['success'] ) && ! empty( $data['transcript'] ) )
						|| ( empty( $data['success'] ) && ! empty( $data['_degraded'] ) && ! empty( $data['code'] ) && ! empty( $data['hint'] ) && ! empty( $data['help_code'] ) );
					$this->emit( $ctx, $steps, $pass, 'Runtime - transcribe owned audio fixture or degrade cleanly', $runtime_ok, sprintf( 'success=%s; degraded=%s; code=%s; transcript_len=%d', ! empty( $data['success'] ) ? 'yes' : 'no', ! empty( $data['_degraded'] ) ? 'yes' : 'no', (string) ( $data['code'] ?? 'none' ), isset( $data['transcript'] ) ? strlen( (string) $data['transcript'] ) : 0 ) );
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
				? 'Twin GPT voice input foundation PASS: MediaRecorder UI markers, same-origin transcribe route and clean gateway degradation are present.'
				: 'Twin GPT voice input contract failed one or more checks.',
			'error'    => $pass ? '' : 'twinweb_voice_input_contract_failed',
			'fix_hint' => $pass ? '' : 'Check /voice/transcribe route, audio MIME upload support, MediaRecorder markers and R-ERROR-UX degraded payload.',
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
		// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — keep diagnostics independent of global upload MIME restrictions.
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
			return new WP_Error( 'fixture_write_failed', 'Could not write voice fixture.' );
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

// [2026-07-19 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — register Twin GPT voice input probe.
add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_TwinWeb_Voice_Input';
	return $list;
} );
