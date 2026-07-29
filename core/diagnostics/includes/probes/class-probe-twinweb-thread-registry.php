<?php
/**
 * BizCity Diagnostics - Twin GPT thread registry probe.
 *
 * R-DDV 3 layers evidence:
 * - Disk: thread registry, thread_spec, search highlight, drag/drop and customer queue markers.
 * - Loader: TwinWeb REST/registry methods and routes registered.
 * - Runtime: synthetic thread create/PATCH/search/delete plus admin customer queue payload.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-07-19
 */

// [2026-07-19 Johnny Chu] PHASE-TWINWEB-THREADS — DDV probe for stable thread_spec, registry, search highlight and customer queue foundation.
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

if ( class_exists( 'BizCity_Probe_TwinWeb_Thread_Registry', false ) ) {
	return;
}

final class BizCity_Probe_TwinWeb_Thread_Registry implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'modules.twin_gpt.thread_registry'; }
	public function label(): string { return 'Twin GPT Thread Registry + Search'; }
	public function description(): string {
		return 'Verifies stable thread_spec metadata, TwinWeb registry DTO, conversation search highlight, drag/drop wiring markers and admin customer care/revenue queue foundation.';
	}
	public function severity(): string { return 'warning'; }
	public function order(): int { return 88; }
	public function icon(): string { return 'MessagesSquare'; }
	public function estimate_ms(): int { return 180; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_TwinWeb_REST' ) ) {
			return new WP_Error( 'no_twinweb_rest', 'BizCity_TwinWeb_REST is not loaded.' );
		}
		if ( ! class_exists( 'BizCity_TwinWeb_Thread_Registry' ) ) {
			return new WP_Error( 'no_thread_registry', 'BizCity_TwinWeb_Thread_Registry is not loaded.' );
		}
		if ( ! class_exists( 'BizCity_TwinWeb_Identity' ) ) {
			return new WP_Error( 'no_twinweb_identity', 'BizCity_TwinWeb_Identity is not loaded.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-07-19 Johnny Chu] PHASE-TWINWEB-THREADS — runtime creates one synthetic thread and cleans it up in finally.
		$steps = array();
		$pass  = true;
		$created_thread_id = 0;

		$root = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( dirname( dirname( __DIR__ ) ) ) . '/';
		$rest_file     = $this->class_file_or_fallback( 'BizCity_TwinWeb_REST', $root . 'modules/twinweb/includes/class-twinweb-rest.php' );
		$registry_file = $this->class_file_or_fallback( 'BizCity_TwinWeb_Thread_Registry', $root . 'modules/twinweb/includes/class-twinweb-thread-registry.php' );
		$sidebar_file  = $root . 'modules/twinweb/ui/src/components/TwinwebSidebar.tsx';
		$search_file   = $root . 'modules/twinweb/ui/src/components/TwinWebSearchDialog.tsx';
		$types_file    = $root . 'modules/twinweb/ui/src/types/index.ts';
		$manifest_file = $root . 'modules/twinweb/ui/dist/.vite/manifest.json';

		$rest_src     = is_readable( $rest_file ) ? file_get_contents( $rest_file ) : '';
		$registry_src = is_readable( $registry_file ) ? file_get_contents( $registry_file ) : '';

		$disk_registry_ok = is_string( $registry_src )
			&& strpos( $registry_src, 'SPEC_SCHEMA' ) !== false
			&& strpos( $registry_src, 'bizcity.twin.thread_spec.v1' ) !== false
			&& strpos( $registry_src, 'normalize_twinweb_row' ) !== false
			&& strpos( $registry_src, 'bizcity_twin_thread_registry_surfaces' ) !== false;
		$this->emit( $ctx, $steps, $pass, 'Disk - unified thread registry foundation markers', $disk_registry_ok, $disk_registry_ok ? 'Registry schema, normalizer and surface filter markers found.' : 'Missing thread registry markers.' );

		$disk_rest_ok = is_string( $rest_src )
			&& strpos( $rest_src, 'thread_spec' ) !== false
			&& strpos( $rest_src, 'record_thread_turn_summary' ) !== false
			&& strpos( $rest_src, 'search_tokens' ) !== false
			&& strpos( $rest_src, 'highlight_text' ) !== false
			&& strpos( $rest_src, "'/admin/customer-queue'" ) !== false
			&& strpos( $rest_src, 'admin_get_customer_queue' ) !== false;
		$this->emit( $ctx, $steps, $pass, 'Disk - REST thread_spec/search/customer-queue markers', $disk_rest_ok, $disk_rest_ok ? 'Thread spec, rolling summary, search highlight and customer queue markers found.' : 'Missing REST markers for thread registry sprint.' );

		$dist_ok = is_readable( $manifest_file );
		$step = array(
			'label'  => 'Disk - FE deploy artifact policy',
			'status' => $dist_ok ? 'pass' : 'skip',
			'detail' => $dist_ok ? 'TwinWeb Vite manifest present; React src markers below are optional dev evidence.' : 'dist manifest missing; production may still provide assets through another deploy path.',
		);
		$steps[] = $step;
		$ctx->emit_step( $step );

		$source_present = is_readable( $sidebar_file ) && is_readable( $search_file ) && is_readable( $types_file );
		if ( $source_present ) {
			$sidebar_src = (string) file_get_contents( $sidebar_file );
			$search_src = (string) file_get_contents( $search_file );
			$types_src = (string) file_get_contents( $types_file );
			$fe_markers_ok = strpos( $sidebar_src, 'moveThreadToProject' ) !== false
				&& strpos( $sidebar_src, 'draggable' ) !== false
				&& strpos( $search_src, 'SnippetHighlight text={hit.title}' ) !== false
				&& strpos( $types_src, 'interface ThreadSpec' ) !== false;
			$this->emit( $ctx, $steps, $pass, 'Disk - optional dev-source drag/drop + highlight markers', $fe_markers_ok, $fe_markers_ok ? 'ThreadSpec, drag/drop and conversation highlight markers found in local React src.' : 'React src exists but thread sprint markers are missing or drifted.' );
		} else {
			$step = array(
				'label'  => 'Disk - optional dev-source drag/drop + highlight markers',
				'status' => 'skip',
				'detail' => 'React src is absent; this is valid for production dist-only deploys.',
			);
			$steps[] = $step;
			$ctx->emit_step( $step );
		}

		$method_ok = method_exists( 'BizCity_TwinWeb_REST', 'create_thread' )
			&& method_exists( 'BizCity_TwinWeb_REST', 'update_thread' )
			&& method_exists( 'BizCity_TwinWeb_REST', 'search_conversations' )
			&& method_exists( 'BizCity_TwinWeb_REST', 'admin_get_customer_queue' )
			&& method_exists( 'BizCity_TwinWeb_Thread_Registry', 'normalize_twinweb_row' )
			&& method_exists( 'BizCity_TwinWeb_Thread_Registry', 'surfaces' );
		$this->emit( $ctx, $steps, $pass, 'Loader - thread registry handlers loaded', $method_ok, $method_ok ? 'Thread CRUD/search/customer queue handlers and registry methods loaded.' : 'One or more thread registry handlers are missing.' );

		$routes = rest_get_server()->get_routes();
		$route_ok = $this->route_has_method( $routes, '/bizcity-twinweb/v1/threads', 'POST' )
			&& $this->route_has_method( $routes, '/bizcity-twinweb/v1/threads/(?P<id>\d+)', 'PATCH' )
			&& $this->route_has_method( $routes, '/bizcity-twinweb/v1/search/conversations', 'GET' )
			&& $this->route_has_method( $routes, '/bizcity-twinweb/v1/admin/customer-queue', 'GET' );
		$this->emit( $ctx, $steps, $pass, 'Loader - thread/search/customer queue routes registered', $route_ok, $route_ok ? 'POST /threads + PATCH /threads/{id} + GET /search/conversations + GET /admin/customer-queue registered.' : 'Missing one or more TwinWeb thread sprint routes.' );

		$original_uid = get_current_user_id();
		$runtime_uid = $this->resolve_runtime_user_id();
		if ( $runtime_uid <= 0 ) {
			$this->emit( $ctx, $steps, $pass, 'Runtime - resolve operator user', false, 'No runtime WP user available for owner-scoped synthetic thread check.' );
		} else {
			wp_set_current_user( $runtime_uid );
			try {
				$create_req = new WP_REST_Request( 'POST', '/bizcity-twinweb/v1/threads' );
				$create_req->set_body_params( array(
					'title'       => 'DDV Thread Spec Smoke ' . gmdate( 'His' ),
					'app_type'    => 'chat',
					'mode'        => 'notebooks',
					'answer_mode' => 'thinking',
					'model'       => 'auto',
				) );
				$create_res = rest_do_request( $create_req );
				$create_data = is_wp_error( $create_res ) ? array() : (array) rest_ensure_response( $create_res )->get_data();
				$created_thread_id = (int) ( $create_data['id'] ?? 0 );
				$created_spec = isset( $create_data['thread_spec'] ) && is_array( $create_data['thread_spec'] ) ? $create_data['thread_spec'] : array();
				$create_ok = $created_thread_id > 0
					&& (string) ( $created_spec['schema'] ?? '' ) === BizCity_TwinWeb_Thread_Registry::SPEC_SCHEMA
					&& (string) ( $created_spec['mode'] ?? '' ) === 'notebooks'
					&& (string) ( $created_spec['answer_mode'] ?? '' ) === 'thinking'
					&& isset( $create_data['title_source'] );
				$this->emit( $ctx, $steps, $pass, 'Runtime - create thread returns stable thread_spec', $create_ok, sprintf( 'thread_id=%d; schema=%s; mode=%s; answer_mode=%s', $created_thread_id, (string) ( $created_spec['schema'] ?? 'MISSING' ), (string) ( $created_spec['mode'] ?? 'MISSING' ), (string) ( $created_spec['answer_mode'] ?? 'MISSING' ) ) );

				if ( $created_thread_id > 0 ) {
					$patch_req = new WP_REST_Request( 'PATCH', '/bizcity-twinweb/v1/threads/' . $created_thread_id );
					$patch_req->set_url_params( array( 'id' => $created_thread_id ) );
					$patch_req->set_body_params( array(
						'thread_spec' => array(
							'mode'                  => 'products',
							'answer_mode'           => 'instant',
							'model'                 => 'auto',
							'profile_template_slug' => 'ddv_thread_spec',
							'source_scope_hash'     => 'ddv-smoke',
						),
					) );
					$patch_res = rest_do_request( $patch_req );
					$patch_data = is_wp_error( $patch_res ) ? array() : (array) rest_ensure_response( $patch_res )->get_data();
					$patch_spec = isset( $patch_data['thread_spec'] ) && is_array( $patch_data['thread_spec'] ) ? $patch_data['thread_spec'] : array();
					$patch_ok = (int) ( $patch_data['id'] ?? 0 ) === $created_thread_id
						&& (string) ( $patch_spec['mode'] ?? '' ) === 'products'
						&& (string) ( $patch_spec['profile_template_slug'] ?? '' ) === 'ddv_thread_spec'
						&& (string) ( $patch_spec['source_scope_hash'] ?? '' ) === 'ddv-smoke';
					$this->emit( $ctx, $steps, $pass, 'Runtime - PATCH merges safe thread_spec hints', $patch_ok, sprintf( 'mode=%s; profile_template_slug=%s; source_scope_hash=%s', (string) ( $patch_spec['mode'] ?? 'MISSING' ), (string) ( $patch_spec['profile_template_slug'] ?? 'MISSING' ), (string) ( $patch_spec['source_scope_hash'] ?? 'MISSING' ) ) );

					$search_req = new WP_REST_Request( 'GET', '/bizcity-twinweb/v1/search/conversations' );
					$search_req->set_query_params( array( 'q' => 'DDV Thread', 'per_page' => 10 ) );
					$search_res = rest_do_request( $search_req );
					$search_data = is_wp_error( $search_res ) ? array() : (array) rest_ensure_response( $search_res )->get_data();
					$search_payload = isset( $search_data['data'] ) && is_array( $search_data['data'] ) ? $search_data['data'] : array();
					$results = isset( $search_payload['results'] ) && is_array( $search_payload['results'] ) ? $search_payload['results'] : array();
					$found = false;
					$highlight_found = false;
					foreach ( $results as $result ) {
						if ( isset( $result['thread_id'] ) && (int) $result['thread_id'] === $created_thread_id ) {
							$found = true;
							$highlight_found = isset( $result['highlight_title'] ) && false !== strpos( (string) $result['highlight_title'], '<mark>' );
						}
					}
					$tokens = isset( $search_payload['tokens'] ) && is_array( $search_payload['tokens'] ) ? $search_payload['tokens'] : array();
					$search_ok = $found && $highlight_found && count( $tokens ) >= 2;
					$this->emit( $ctx, $steps, $pass, 'Runtime - conversation search returns highlight tokens', $search_ok, sprintf( 'found=%s; highlight=%s; tokens=%d; results=%d', $found ? 'yes' : 'no', $highlight_found ? 'yes' : 'no', count( $tokens ), count( $results ) ) );
				}

				$queue_req = new WP_REST_Request( 'GET', '/bizcity-twinweb/v1/admin/customer-queue' );
				$queue_req->set_query_params( array( 'limit' => 5 ) );
				$queue_res = rest_do_request( $queue_req );
				$queue_data = is_wp_error( $queue_res ) ? array() : (array) rest_ensure_response( $queue_res )->get_data();
				$queue_ok = ! empty( $queue_data['success'] )
					&& isset( $queue_data['summary'] ) && is_array( $queue_data['summary'] )
					&& isset( $queue_data['care_queue'] ) && is_array( $queue_data['care_queue'] )
					&& isset( $queue_data['revenue_queue'] ) && is_array( $queue_data['revenue_queue'] )
					&& array_key_exists( '_degraded', $queue_data );
				$this->emit( $ctx, $steps, $pass, 'Runtime - admin customer queue fail-open payload', $queue_ok, sprintf( 'success=%s; degraded=%s; care=%d; revenue=%d', ! empty( $queue_data['success'] ) ? 'yes' : 'no', ! empty( $queue_data['_degraded'] ) ? 'yes' : 'no', isset( $queue_data['care_queue'] ) && is_array( $queue_data['care_queue'] ) ? count( $queue_data['care_queue'] ) : 0, isset( $queue_data['revenue_queue'] ) && is_array( $queue_data['revenue_queue'] ) ? count( $queue_data['revenue_queue'] ) : 0 ) );
			} finally {
				if ( $created_thread_id > 0 ) {
					$delete_req = new WP_REST_Request( 'DELETE', '/bizcity-twinweb/v1/threads/' . $created_thread_id );
					$delete_req->set_url_params( array( 'id' => $created_thread_id ) );
					rest_do_request( $delete_req );
					$this->delete_synthetic_thread_direct( $created_thread_id );
				}
				wp_set_current_user( $original_uid );
			}
		}

		return array(
			'status'   => $pass ? 'pass' : 'fail',
			'summary'  => $pass
				? 'Twin GPT thread registry/spec/search/customer queue contract PASS; browser drag/drop smoke remains the UX gate.'
				: 'Twin GPT thread registry/spec/search/customer queue contract failed one or more checks.',
			'error'    => $pass ? '' : 'twinweb_thread_registry_contract_failed',
			'fix_hint' => $pass ? '' : 'Check class-twinweb-rest.php, class-twinweb-thread-registry.php, TwinWeb thread routes and the conversation search/customer queue endpoints.',
			'steps'    => $steps,
		);
	}

	public function cleanup(): void {
		// Runtime cleanup is handled in run() so the synthetic thread id stays local.
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
		if ( $current > 0 && current_user_can( 'manage_options' ) ) {
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
		return $current > 0 ? (int) $current : 0;
	}

	private function delete_synthetic_thread_direct( $thread_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_twinweb_threads';
		if ( $thread_id > 0 ) {
			$wpdb->delete( $table, array( 'id' => (int) $thread_id ) );
		}
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

// [2026-07-19 Johnny Chu] PHASE-TWINWEB-THREADS — register Twin GPT thread registry probe.
add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_TwinWeb_Thread_Registry';
	return $list;
} );
