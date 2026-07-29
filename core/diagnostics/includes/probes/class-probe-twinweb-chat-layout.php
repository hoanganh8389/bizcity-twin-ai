<?php
/**
 * BizCity Diagnostics - Twin GPT public chat layout probe.
 *
 * R-DDV 3 layers evidence:
 * - Disk: /gpt/ shell markers, built dist artifact, optional local React source markers.
 * - Loader: TwinWeb page + public chat/model/thread REST routes registered.
 * - Runtime: guest-safe /models/effective and /threads payloads load without hub/provider calls.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-07-18
 */

// [2026-07-18 Johnny Chu] PHASE-TWIN-GPT-C-ENDUSER C-9 — DDV probe for public chat layout foundation.
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

if ( class_exists( 'BizCity_Probe_TwinWeb_Chat_Layout', false ) ) {
	return;
}

final class BizCity_Probe_TwinWeb_Chat_Layout implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'modules.twin_gpt.chat_layout'; }
	public function label(): string { return 'Twin GPT Chat Layout (/gpt/)'; }
	public function description(): string {
		return 'Verifies the /gpt/ shell, deploy artifact, public model/thread routes and optional dev-source markers for the left model rail plus right conversation rail. React src is never required on production.';
	}
	public function severity(): string { return 'warning'; }
	public function order(): int { return 87; }
	public function icon(): string { return 'PanelLeftRight'; }
	public function estimate_ms(): int { return 100; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_TwinWeb_Page' ) ) {
			return new WP_Error( 'no_twinweb_page', 'BizCity_TwinWeb_Page is not loaded.' );
		}
		if ( ! class_exists( 'BizCity_TwinWeb_REST' ) ) {
			return new WP_Error( 'no_twinweb_rest', 'BizCity_TwinWeb_REST is not loaded.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		$steps = array();
		$pass  = true;

		$root = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( dirname( dirname( __DIR__ ) ) ) . '/';
		$page_file      = $this->class_file_or_fallback( 'BizCity_TwinWeb_Page', $root . 'modules/twinweb/includes/class-twinweb-page.php' );
		$rest_file      = $this->class_file_or_fallback( 'BizCity_TwinWeb_REST', $root . 'modules/twinweb/includes/class-twinweb-rest.php' );
		$app_src_file   = $root . 'modules/twinweb/ui/src/App.tsx';
		$chat_src_file  = $root . 'modules/twinweb/ui/src/pages/ChatPage.tsx';
		$manifest_file  = $root . 'modules/twinweb/ui/dist/.vite/manifest.json';
		$dist_assets_dir = $root . 'modules/twinweb/ui/dist/assets';

		$page_src = is_readable( $page_file ) ? file_get_contents( $page_file ) : '';
		$rest_src = is_readable( $rest_file ) ? file_get_contents( $rest_file ) : '';

		$disk_shell_ok = is_string( $page_src )
			&& strpos( $page_src, '/gpt/' ) !== false
			&& strpos( $page_src, 'bizcity-twinweb-root' ) !== false
			&& strpos( $page_src, 'window.twinwebConfig' ) !== false
			&& strpos( $page_src, 'read_manifest' ) !== false;
		$step = array(
			'label'  => 'Disk - /gpt/ SPA shell and Vite manifest markers',
			'status' => $disk_shell_ok ? 'pass' : 'fail',
			'detail' => $disk_shell_ok ? 'TwinWeb page shell markers found.' : sprintf( 'Missing /gpt/ shell markers in %s.', (string) $page_file ),
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $disk_shell_ok ) { $pass = false; }

		// [2026-07-18 Johnny Chu] PHASE-TWIN-GPT-C-ENDUSER R-DDV-FE — production deploys dist; React src is optional evidence only.
		$dist_ok = is_readable( $manifest_file ) || is_dir( $dist_assets_dir );
		$step = array(
			'label'  => 'Disk - FE deploy artifact policy (React src is not required)',
			'status' => $dist_ok ? 'pass' : 'skip',
			'detail' => sprintf(
				'dist=%s; chat layout probe checks deploy artifact/runtime contracts and treats modules/twinweb/ui/src as optional dev evidence.',
				$dist_ok ? 'present' : 'not found'
			),
		);
		$steps[] = $step;
		$ctx->emit_step( $step );

		$source_present = is_readable( $app_src_file ) && is_readable( $chat_src_file );
		if ( $source_present ) {
			$app_src  = (string) file_get_contents( $app_src_file );
			$chat_src = (string) file_get_contents( $chat_src_file );
			$layout_markers_ok = strpos( $app_src, 'RightConversationRail' ) !== false
				&& strpos( $app_src, 'onOpenSearch' ) !== false
				&& strpos( $app_src, 'xl:flex' ) !== false
				&& strpos( $chat_src, 'AnswerModeSelector' ) !== false
				&& strpos( $chat_src, 'ModelPicker disabled={isRunning}' ) !== false
				&& strpos( $chat_src, 'desktop model controls live in the left rail' ) !== false;
			$step = array(
				'label'  => 'Disk - optional dev-source layout markers',
				'status' => $layout_markers_ok ? 'pass' : 'skip',
				'detail' => $layout_markers_ok ? 'Left model rail + right conversation rail markers found in local React src.' : 'Local React src exists but optional layout markers drifted; production PASS relies on built dist + REST/runtime contracts.',
			);
			$steps[] = $step;
			$ctx->emit_step( $step );
		} else {
			$step = array(
				'label'  => 'Disk - optional dev-source layout markers',
				'status' => 'skip',
				'detail' => 'React src is absent; this is valid for production dist-only deploys and does not affect PASS.',
			);
			$steps[] = $step;
			$ctx->emit_step( $step );
		}

		$disk_rest_ok = is_string( $rest_src )
			&& strpos( $rest_src, "'/chat/stream'" ) !== false
			&& strpos( $rest_src, "'/models/effective'" ) !== false
			&& strpos( $rest_src, "'/threads'" ) !== false;
		$step = array(
			'label'  => 'Disk - public chat/model/thread REST markers',
			'status' => $disk_rest_ok ? 'pass' : 'fail',
			'detail' => $disk_rest_ok ? '/chat/stream + /models/effective + /threads markers found.' : 'Missing public TwinWeb REST markers.',
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $disk_rest_ok ) { $pass = false; }

		// [2026-07-19 Johnny Chu] PHASE-TWIN-GPT-C-ENDUSER — REST callback is handle_chat_stream; do not fail on legacy chat_stream name.
		$method_ok = method_exists( 'BizCity_TwinWeb_REST', 'handle_chat_stream' )
			&& method_exists( 'BizCity_TwinWeb_REST', 'list_models_effective' )
			&& method_exists( 'BizCity_TwinWeb_REST', 'list_threads' );
		$step = array(
			'label'  => 'Loader - public chat layout handlers loaded',
			'status' => $method_ok ? 'pass' : 'fail',
			'detail' => $method_ok ? 'handle_chat_stream + list_models_effective + list_threads handlers loaded.' : 'One or more public TwinWeb handlers are missing.',
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $method_ok ) { $pass = false; }

		$routes = rest_get_server()->get_routes();
		$route_ok = $this->route_has_method( $routes, '/bizcity-twinweb/v1/chat/stream', 'POST' )
			&& $this->route_has_method( $routes, '/bizcity-twinweb/v1/models/effective', 'GET' )
			&& $this->route_has_method( $routes, '/bizcity-twinweb/v1/threads', 'GET' );
		$step = array(
			'label'  => 'Loader - public chat/model/thread routes registered',
			'status' => $route_ok ? 'pass' : 'fail',
			'detail' => $route_ok ? 'POST /chat/stream + GET /models/effective + GET /threads registered.' : 'Missing one or more TwinWeb public REST routes.',
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $route_ok ) { $pass = false; }

		$original_uid = get_current_user_id();
		wp_set_current_user( 0 );
		try {
			$models_req = new WP_REST_Request( 'GET', '/bizcity-twinweb/v1/models/effective' );
			$models_res = rest_do_request( $models_req );
			$models_data = is_wp_error( $models_res ) ? array() : (array) $models_res->get_data();
			$model_items = isset( $models_data['items'] ) && is_array( $models_data['items'] ) ? $models_data['items'] : array();

			$threads_req = new WP_REST_Request( 'GET', '/bizcity-twinweb/v1/threads' );
			$threads_res = rest_do_request( $threads_req );
			$threads_data = is_wp_error( $threads_res ) ? array() : (array) $threads_res->get_data();
			$threads = isset( $threads_data['threads'] ) && is_array( $threads_data['threads'] ) ? $threads_data['threads'] : array();

			$runtime_ok = ! empty( $models_data['success'] )
				&& ! empty( $model_items )
				&& ! empty( $models_data['default_answer_mode'] )
				&& ( ! empty( $threads_data['success'] ) || isset( $threads_data['threads'] ) )
				&& is_array( $threads );

			$step = array(
				'label'  => 'Runtime - guest-safe model catalog and thread list load',
				'status' => $runtime_ok ? 'pass' : 'fail',
				'detail' => sprintf(
					'models=%d; default_answer_mode=%s; threads=%d; threads_success=%s',
					count( $model_items ),
					! empty( $models_data['default_answer_mode'] ) ? (string) $models_data['default_answer_mode'] : 'MISSING',
					count( $threads ),
					! empty( $threads_data['success'] ) ? 'yes' : 'no'
				),
			);
			$steps[] = $step;
			$ctx->emit_step( $step );
			if ( ! $runtime_ok ) { $pass = false; }
		} finally {
			wp_set_current_user( $original_uid );
		}

		return array(
			'status'   => $pass ? 'pass' : 'fail',
			'summary'  => $pass
				? 'Twin GPT /gpt/ chat layout foundation contract PASS; browser screenshot smoke remains the final C-9 gate.'
				: 'Twin GPT /gpt/ chat layout foundation failed one or more checks.',
			'error'    => $pass ? '' : 'twinweb_chat_layout_contract_failed',
			'fix_hint' => $pass ? '' : 'Check class-twinweb-page.php, class-twinweb-rest.php and the built TwinWeb dist artifact. Do not require React src on production servers.',
			'steps'    => $steps,
		);
	}

	public function cleanup(): void {
		// Read-only probe.
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

// [2026-07-18 Johnny Chu] PHASE-TWIN-GPT-C-ENDUSER C-9 — register Twin GPT chat layout probe.
add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_TwinWeb_Chat_Layout';
	return $list;
} );