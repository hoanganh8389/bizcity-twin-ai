<?php
/**
 * DDV probe for the Phase 1.24 PageBuilder adoption slice and optional Video Kling feature.
 *
 * Uses source markers and mock gateway HTTP only. No provider credential or
 * production mutation is used.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since 1.24.0
 */

defined( 'ABSPATH' ) || exit;

// [2026-08-25 Johnny Chu] R-SAFE-LOADER — load the probe contract through the guarded core loader.
if ( ! class_exists( 'BizCity_Safe_Loader', false ) ) {
	$_bizcity_safe_loader = dirname( __DIR__, 4 ) . '/core/helper/class-bizcity-safe-loader.php';
	if ( is_file( $_bizcity_safe_loader ) && is_readable( $_bizcity_safe_loader ) ) {
		require_once $_bizcity_safe_loader;
	}
	unset( $_bizcity_safe_loader );
}
if ( ! class_exists( 'BizCity_Safe_Loader', false ) ) {
	return;
}
if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false )
	&& ! BizCity_Safe_Loader::require_file( dirname( __DIR__ ) . '/interface-diagnostics-probe.php', 'diagnostics.probe_interface' ) ) {
	return;
}

if ( class_exists( 'BizCity_Probe_Framework_Package_Adoption', false ) ) {
	return;
}

final class BizCity_Probe_Framework_Package_Adoption implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'core.framework.package_adoption'; }
	public function label(): string { return 'Phase 1.24 PageBuilder adoption + optional Video Kling'; }
	public function description(): string { return 'Kiểm tra error envelope, mutation boundary và PageBuilder; chỉ kiểm tra Video client bằng mock khi plugin phụ được deploy.'; }
	public function severity(): string { return 'critical'; }
	public function order(): int { return 21; }
	public function icon(): string { return 'package-check'; }
	public function estimate_ms(): int { return 400; }
	public function precondition() {
		$root = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) ) . '/';
		$required_dirs = array( $root . 'plugins/bizcity-pagebuilder' );
		foreach ( $required_dirs as $dir ) {
			if ( ! is_dir( $dir ) ) {
				return new WP_Error( 'required_package_missing', 'PageBuilder framework package is not deployed in this CI checkout.' );
			}
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-08-10 Johnny Chu] PHASE-1.24-DDV — package adoption evidence for PageBuilder and Video Kling.
		$steps = array();
		$root  = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) ) . '/';

		$pagebuilder_files = array(
			'plugins/bizcity-pagebuilder/includes/class-rest-api.php',
			'plugins/bizcity-pagebuilder/includes/class-submission-handler.php',
		);
		$pagebuilder_transport_files = array(
			'plugins/bizcity-pagebuilder/app/src/api.ts',
			'plugins/bizcity-pagebuilder/assets/dist/pagebuilder-app.js',
		);
		$video_plugin_files = array(
			'plugins/bizcity-video-kling/lib/kling_api.php',
			'plugins/bizcity-video-kling/includes/class-tools-kling.php',
		);
		$video_available = is_dir( $root . 'plugins/bizcity-video-kling' );
		$missing = array();
		foreach ( array_merge( $pagebuilder_files, $pagebuilder_transport_files ) as $relative ) {
			if ( ! is_readable( $root . $relative ) ) {
				$missing[] = $relative;
			}
		}
		$steps[] = array(
			'label'  => 'Disk — PageBuilder adoption files exist',
			'status' => empty( $missing ) ? 'pass' : 'fail',
			'detail' => empty( $missing ) ? 'Framework-facing PageBuilder files are readable.' : implode( ', ', $missing ),
		);
		if ( ! empty( $missing ) ) {
			return array( 'status' => 'fail', 'summary' => 'PageBuilder adoption files are missing.', 'steps' => $steps );
		}

		$pagebuilder_source = $this->read_sources( $root, $pagebuilder_files );
		$pagebuilder_transport_source = $this->read_sources( $root, $pagebuilder_transport_files );
		$video_source = $video_available ? $this->read_sources( $root, $video_plugin_files ) : '';
		$pagebuilder_static = false !== strpos( $pagebuilder_source, 'BizCity_Error_Payload' )
			&& false !== strpos( $pagebuilder_source, 'send_submission_error' )
			&& false !== strpos( $pagebuilder_source, 'rest_error' );
		$video_static = ! $video_available || ( false === strpos( $video_source, 'bizcity_video_kling_api_key' )
			&& false === strpos( $video_source, 'bizcity_video_kling_openai_api_key' )
			&& false === strpos( $video_source, 'twf_openai_api_key' )
			&& false === strpos( $video_source, 'api.piapi.ai' )
			&& false === strpos( $video_source, 'api.openai.com' )
			&& false !== strpos( $video_source, 'BizCity_Video_Client' ) );
		$pagebuilder_transport_static = false !== strpos( $pagebuilder_transport_source, 'saveProject' )
			&& false !== strpos( $pagebuilder_transport_source, 'deleteProject' )
			&& false !== strpos( $pagebuilder_transport_source, 'publishProject' )
			&& false !== strpos( $pagebuilder_transport_source, 'X-Idempotency-Key' );
		$steps[] = array(
			'label'  => 'Disk — PageBuilder boundary markers and optional Video provider quarantine',
			'status' => $pagebuilder_static && $pagebuilder_transport_static ? 'pass' : 'fail',
			'detail' => wp_json_encode( array( 'pagebuilder_error_boundary' => $pagebuilder_static, 'pagebuilder_mutation_transport' => $pagebuilder_transport_static, 'video_provider_quarantine' => $video_available ? $video_static : 'skip_optional_absent' ) ),
		);

		$loaded = class_exists( 'BizCity_Error_Payload' )
			&& class_exists( 'BizCity_Twin_Mutation_Guard' )
			&& class_exists( 'BizCity_Twin_Mutation_Store' )
			&& ( ! $video_available || ( class_exists( 'BizCity_Video_Client' )
				&& method_exists( 'BizCity_Video_Client', 'submit' )
				&& method_exists( 'BizCity_Video_Client', 'get_status' ) ) );
		$pagebuilder_rest_file = $root . 'plugins/bizcity-pagebuilder/includes/class-rest-api.php';
		if ( ! class_exists( 'BZPB_Rest_API', false ) && is_readable( $pagebuilder_rest_file ) ) {
			BizCity_Safe_Loader::require_file( $pagebuilder_rest_file, 'diagnostics.pagebuilder_rest' );
		}
		$loaded = $loaded && class_exists( 'BZPB_Rest_API', false ) && method_exists( 'BZPB_Rest_API', 'normalize_rest_error' );
		$steps[] = array(
			'label'  => 'Loader — PageBuilder boundary, mutation, and optional video client classes loaded',
			'status' => $loaded ? 'pass' : 'fail',
			'detail' => $loaded ? 'Required classes and methods are loaded.' : 'One or more required classes/methods are unavailable.',
		);
		if ( ! $loaded ) {
			return array( 'status' => 'fail', 'summary' => 'Package adoption classes are not loaded.', 'steps' => $steps );
		}

		$payload = BizCity_Error_Payload::make(
			'invalid_param',
			'Probe input không hợp lệ.',
			'Kiểm tra dữ liệu rồi thử lại.',
			'invalid_param_generic'
		);
		$error_contract_ok = isset( $payload['code'], $payload['message'], $payload['hint'], $payload['help_code'] )
			&& 'invalid_param' === (string) $payload['code'];
		$mutation = BizCity_Twin_Mutation_Guard::validate(
			array(
				'contract'        => 'mutation-contract',
				'version'         => '1.0.0',
				'trace_id'        => 'probe-phase-124',
				'idempotency_key' => 'probe-phase-124',
				'action'          => 'create',
				'resource'        => array( 'type' => 'pagebuilder_project', 'scope' => 'probe' ),
			),
			array( 'permissions' => array( 'content.write' ) )
		);
		$mutation_ok = ! empty( $mutation['allowed'] );
		$denied_mutation = BizCity_Twin_Mutation_Guard::validate(
			array(
				'contract'        => 'mutation-contract',
				'version'         => '1.0.0',
				'trace_id'        => 'probe-phase-124-denied',
				'idempotency_key' => 'probe-phase-124-denied',
				'action'          => 'create',
				'resource'        => array( 'type' => 'pagebuilder_project', 'scope' => 'probe-denied' ),
			),
			array( 'permissions' => array() )
		);
		$mutation_denied_ok = empty( $denied_mutation['allowed'] )
			&& 'permission_denied' === (string) ( $denied_mutation['code'] ?? '' );
		$pagebuilder_request = new WP_REST_Request( 'POST', '/bzpb/v1/generate' );
		$pagebuilder_error = new WP_Error( 'llm_error', 'Raw provider stack detail must not be public.', array( 'status' => 502 ) );
		$pagebuilder_response = BZPB_Rest_API::normalize_rest_error( $pagebuilder_error, null, $pagebuilder_request );
		$pagebuilder_data = is_object( $pagebuilder_response ) && method_exists( $pagebuilder_response, 'get_data' )
			? (array) $pagebuilder_response->get_data()
			: array();
		$pagebuilder_error_ok = isset( $pagebuilder_data['code'], $pagebuilder_data['message'], $pagebuilder_data['hint'], $pagebuilder_data['help_code'] )
			&& 'llm_error' === (string) $pagebuilder_data['code']
			&& false === strpos( (string) $pagebuilder_data['message'], 'Raw provider' );
		// [2026-08-13 Johnny Chu] PHASE-1.24-DDV — preserve PageBuilder mutation error mapping and help catalog key.
		$pagebuilder_mutation_error = BZPB_Rest_API::normalize_rest_error(
			new WP_Error( 'mutation_contract_invalid', 'Missing mutation header.', array( 'status' => 400 ) ),
			null,
			new WP_REST_Request( 'POST', '/bzpb/v1/save' )
		);
		$pagebuilder_mutation_data = is_object( $pagebuilder_mutation_error ) && method_exists( $pagebuilder_mutation_error, 'get_data' )
			? (array) $pagebuilder_mutation_error->get_data()
			: array();
		$pagebuilder_mutation_error_ok = isset( $pagebuilder_mutation_data['code'], $pagebuilder_mutation_data['help_code'] )
			&& 'invalid_param' === (string) $pagebuilder_mutation_data['code']
			&& 'mutation_contract_invalid' === (string) $pagebuilder_mutation_data['help_code'];
		$pagebuilder_missing_upload = BZPB_Rest_API::handle_upload_image( new WP_REST_Request( 'POST', '/bzpb/v1/upload-image' ) );
		$pagebuilder_missing_data = is_object( $pagebuilder_missing_upload ) && method_exists( $pagebuilder_missing_upload, 'get_data' )
			? (array) $pagebuilder_missing_upload->get_data()
			: array();
		$pagebuilder_upload_ok = isset( $pagebuilder_missing_data['code'], $pagebuilder_missing_data['message'], $pagebuilder_missing_data['hint'], $pagebuilder_missing_data['help_code'] )
			&& 'invalid_param' === (string) $pagebuilder_missing_data['code'];
		$pagebuilder_invalid_upload_ok = false;
		$pagebuilder_attachment_failure_ok = false;
		$mutation_store_ok = false;
		$store_mutation = array(
			'contract'        => 'mutation-contract',
			'version'         => '1.0.0',
			'trace_id'        => 'probe-store-phase-124',
			'idempotency_key' => 'probe-store-phase-124',
			'action'          => 'create',
			'resource'        => array( 'type' => 'pagebuilder_project', 'scope' => 'probe-store' ),
		);
		$store_context = array( 'user_id' => 0, 'blog_id' => function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 0 );
		if ( class_exists( 'BizCity_Twin_Mutation_Store' ) ) {
			$store_new = BizCity_Twin_Mutation_Store::begin( $store_mutation, $store_context, 'probe-store-hash' );
			if ( 'new' === (string) ( $store_new['status'] ?? '' ) ) {
				$store_pending = BizCity_Twin_Mutation_Store::begin( $store_mutation, $store_context, 'probe-store-hash' );
				BizCity_Twin_Mutation_Store::complete( (string) $store_new['key'], 'probe-store-hash', array( 'success' => true, 'probe' => true ) );
				$store_replay = BizCity_Twin_Mutation_Store::begin( $store_mutation, $store_context, 'probe-store-hash' );
				$store_conflict = BizCity_Twin_Mutation_Store::begin( $store_mutation, $store_context, 'probe-store-other-hash' );
				$mutation_store_ok = 'pending' === (string) ( $store_pending['status'] ?? '' )
					&& 'replay' === (string) ( $store_replay['status'] ?? '' )
					&& ! empty( $store_replay['response']['probe'] )
					&& 'conflict' === (string) ( $store_conflict['status'] ?? '' );
				BizCity_Twin_Mutation_Store::release( (string) $store_new['key'] );
			}
		}
		$pagebuilder_probe_tmp = function_exists( 'wp_tempnam' ) ? wp_tempnam( 'bzpb_probe_' ) : tempnam( sys_get_temp_dir(), 'bzpb_probe_' );
		if ( $pagebuilder_probe_tmp ) {
			file_put_contents( $pagebuilder_probe_tmp, 'not-an-image' );
			$invalid_request = new WP_REST_Request( 'POST', '/bzpb/v1/upload-image' );
			$invalid_request->set_file_params( array( 'file' => array(
				'name'     => 'probe.txt',
				'type'     => 'text/plain',
				'tmp_name' => $pagebuilder_probe_tmp,
				'error'    => UPLOAD_ERR_OK,
				'size'     => 12,
			) ) );
			$pagebuilder_invalid_upload = BZPB_Rest_API::handle_upload_image( $invalid_request );
			$pagebuilder_invalid_data = is_object( $pagebuilder_invalid_upload ) && method_exists( $pagebuilder_invalid_upload, 'get_data' )
				? (array) $pagebuilder_invalid_upload->get_data()
				: array();
			$pagebuilder_invalid_upload_ok = isset( $pagebuilder_invalid_data['code'], $pagebuilder_invalid_data['message'], $pagebuilder_invalid_data['hint'], $pagebuilder_invalid_data['help_code'] )
				&& 'invalid_param' === (string) $pagebuilder_invalid_data['code'];
			@unlink( $pagebuilder_probe_tmp );
		}
		$pagebuilder_attachment_tmp = function_exists( 'wp_tempnam' ) ? wp_tempnam( 'bzpb_probe_attachment_' ) : tempnam( sys_get_temp_dir(), 'bzpb_probe_attachment_' );
		if ( $pagebuilder_attachment_tmp ) {
			$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=' );
			file_put_contents( $pagebuilder_attachment_tmp, $png );
			$forced_upload_failure = function ( $file ) {
				$file['error'] = 'probe_attachment_failure';
				return $file;
			};
			add_filter( 'wp_handle_upload_prefilter', $forced_upload_failure, 10, 1 );
			$failure_request = new WP_REST_Request( 'POST', '/bzpb/v1/upload-image' );
			$failure_request->set_file_params( array( 'file' => array(
				'name'     => 'probe.png',
				'type'     => 'image/png',
				'tmp_name' => $pagebuilder_attachment_tmp,
				'error'    => UPLOAD_ERR_OK,
				'size'     => strlen( $png ),
			) ) );
			$attachment_failure = BZPB_Rest_API::handle_upload_image( $failure_request );
			remove_filter( 'wp_handle_upload_prefilter', $forced_upload_failure, 10 );
			$attachment_failure_data = is_object( $attachment_failure ) && method_exists( $attachment_failure, 'get_data' )
				? (array) $attachment_failure->get_data()
				: array();
			$pagebuilder_attachment_failure_ok = isset( $attachment_failure_data['code'], $attachment_failure_data['message'], $attachment_failure_data['hint'], $attachment_failure_data['help_code'] )
				&& 'upload_rejected' === (string) $attachment_failure_data['code'];
			@unlink( $pagebuilder_attachment_tmp );
		}
		$steps[] = array(
			'label'  => 'Runtime — PageBuilder mutation allow/deny and error contract',
			'status' => $error_contract_ok && $mutation_ok && $mutation_denied_ok && $pagebuilder_error_ok && $pagebuilder_mutation_error_ok && $pagebuilder_upload_ok && $pagebuilder_invalid_upload_ok && $pagebuilder_attachment_failure_ok && $mutation_store_ok ? 'pass' : 'fail',
			'detail' => wp_json_encode( array( 'error_contract' => $error_contract_ok, 'mutation_allowed' => $mutation_ok, 'mutation_denied_before_side_effect' => $mutation_denied_ok, 'rest_error_boundary' => $pagebuilder_error_ok, 'mutation_error_mapping' => $pagebuilder_mutation_error_ok, 'missing_upload_envelope' => $pagebuilder_upload_ok, 'invalid_mime_envelope' => $pagebuilder_invalid_upload_ok, 'attachment_failure_envelope' => $pagebuilder_attachment_failure_ok, 'mutation_store_replay_conflict' => $mutation_store_ok ) ),
		);

		if ( ! $video_available ) {
			$steps[] = array(
				'label'  => 'Runtime — optional Video client mock submit/poll',
				'status' => 'skip',
				'detail' => 'Video Kling is not deployed; optional feature probe skipped.',
			);
			$video_runtime_ok = true;
		} else {
		$captured              = array();
		$submit_attempts       = 0;
		$provider_submit_count = 0;
		$transient_sent        = false;
		$accepted_request_hash = '';
		$accepted_task_id      = '';
		$http_filter = function ( $pre, $args, $url ) use ( &$captured, &$submit_attempts, &$provider_submit_count, &$transient_sent, &$accepted_request_hash, &$accepted_task_id ) {
			$captured[] = array( 'url' => (string) $url, 'args' => is_array( $args ) ? $args : array() );
			if ( strpos( (string) $url, '/generate' ) !== false ) {
				$submit_attempts++;
				$body = json_decode( (string) ( $args['body'] ?? '' ), true );
				$body = is_array( $body ) ? $body : array();
				$request_hash = md5( wp_json_encode( array(
					(string) ( $body['prompt'] ?? '' ),
					(string) ( $body['model'] ?? '' ),
					(int) ( $body['duration'] ?? 0 ),
					(string) ( $body['aspect_ratio'] ?? '' ),
					(string) ( $body['negative_prompt'] ?? '' ),
					! empty( $body['with_audio'] ),
					(string) ( $body['image_url'] ?? '' ),
				) ) );
				if ( ! $transient_sent ) {
					$transient_sent = true;
					return array(
						'headers'  => array( 'content-type' => 'application/json' ),
						'body'     => wp_json_encode( array( 'success' => false, 'code' => 'provider_error', 'message' => 'mock transient failure' ) ),
						'response' => array( 'code' => 503, 'message' => 'Service Unavailable' ),
					);
				}
				if ( '' !== $accepted_task_id ) {
					if ( $request_hash !== $accepted_request_hash ) {
						return array(
							'headers'  => array( 'content-type' => 'application/json' ),
							'body'     => wp_json_encode( array( 'success' => false, 'code' => 'idempotency_conflict', 'message' => 'mock payload conflict' ) ),
							'response' => array( 'code' => 409, 'message' => 'Conflict' ),
						);
					}
					return array(
						'headers'  => array( 'content-type' => 'application/json' ),
						'body'     => wp_json_encode( array( 'success' => true, 'task_id' => $accepted_task_id, 'status' => 'pending', 'idempotency_replayed' => true, 'trace_id' => 'probe_video_replay' ) ),
						'response' => array( 'code' => 200, 'message' => 'OK' ),
					);
				}
				$provider_submit_count++;
				$accepted_request_hash = $request_hash;
				$accepted_task_id = 'probe_video_001';
				return array(
					'headers'  => array( 'content-type' => 'application/json' ),
					'body'     => wp_json_encode( array( 'success' => true, 'task_id' => 'probe_video_001', 'status' => 'pending', 'trace_id' => 'probe_video_trace' ) ),
					'response' => array( 'code' => 202, 'message' => 'Accepted' ),
				);
			}
			return array(
				'headers'  => array( 'content-type' => 'application/json' ),
				'body'     => wp_json_encode( array( 'success' => true, 'task_id' => 'probe_video_001', 'status' => 'completed', 'progress' => 100, 'result_url' => 'https://cdn.invalid/probe.mp4', 'trace_id' => 'probe_video_poll' ) ),
				'response' => array( 'code' => 200, 'message' => 'OK' ),
			);
		};

		add_filter( 'pre_option_bizcity_llm_gateway_url', array( __CLASS__, 'gateway_url' ) );
		add_filter( 'pre_option_bizcity_llm_api_key', array( __CLASS__, 'gateway_key' ) );
		add_filter( 'pre_http_request', $http_filter, 10, 3 );
		try {
			$client = BizCity_Video_Client::instance();
			$submit = $client->submit( 'Probe video', array( 'model' => 'kling/v1-5/i2v-pro', 'idempotency_key' => 'probe_video_idem', 'trace_id' => 'probe_video_trace' ) );
			$replay_submit = $client->submit( 'Probe video', array( 'model' => 'kling/v1-5/i2v-pro', 'idempotency_key' => 'probe_video_idem', 'trace_id' => 'probe_video_trace' ) );
			$conflict_submit = $client->submit( 'Changed probe video', array( 'model' => 'kling/v1-5/i2v-pro', 'idempotency_key' => 'probe_video_idem', 'trace_id' => 'probe_video_conflict' ) );
			$poll   = $client->get_status( 'probe_video_001' );
		} finally {
			remove_filter( 'pre_option_bizcity_llm_gateway_url', array( __CLASS__, 'gateway_url' ) );
			remove_filter( 'pre_option_bizcity_llm_api_key', array( __CLASS__, 'gateway_key' ) );
			remove_filter( 'pre_http_request', $http_filter, 10 );
		}

		$first = $captured[0] ?? array( 'url' => '', 'args' => array() );
		$headers = is_array( $first['args']['headers'] ?? null ) ? $first['args']['headers'] : array();
		$authorization = $this->header_value( $headers, 'authorization' );
		$trace_header = $this->header_value( $headers, 'x-trace-id' );
		$idempotency_header = $this->header_value( $headers, 'x-idempotency-key' );
		$route_ok = strpos( (string) $first['url'], '/wp-json/video/router/v1/generate' ) !== false;
		$auth_ok = strpos( $authorization, 'Bearer biz-' ) === 0;
		$trace_ok = '' !== $trace_header;
		$idempotency_ok = '' !== $idempotency_header;
		$last = $captured[ count( $captured ) - 1 ] ?? array( 'args' => array() );
		$last_headers = is_array( $last['args']['headers'] ?? null ) ? $last['args']['headers'] : array();
		$replay_headers = array();
		for ( $capture_index = count( $captured ) - 1; $capture_index >= 0; $capture_index-- ) {
			if ( false !== strpos( (string) ( $captured[ $capture_index ]['url'] ?? '' ), '/generate' ) ) {
				$replay_headers = is_array( $captured[ $capture_index ]['args']['headers'] ?? null ) ? $captured[ $capture_index ]['args']['headers'] : array();
				break;
			}
		}
		$replay_idempotency = $this->header_value( $replay_headers, 'x-idempotency-key' );
		$retry_ok = $submit_attempts >= 2;
		$replay_key_ok = ! empty( $replay_submit['success'] )
			&& (string) ( $replay_submit['task_id'] ?? '' ) === 'probe_video_001'
			&& ! empty( $replay_submit['idempotency_replayed'] )
			&& 'probe_video_idem' === $replay_idempotency;
		$conflict_ok = empty( $conflict_submit['success'] )
			&& 'idempotency_conflict' === (string) ( $conflict_submit['code'] ?? '' );
		$provider_once_ok = 1 === $provider_submit_count;
		$video_runtime_ok = ! empty( $submit['success'] )
			&& (string) ( $submit['task_id'] ?? '' ) === 'probe_video_001'
			&& ! empty( $poll['success'] )
			&& $route_ok
			&& $auth_ok
			&& $trace_ok
			&& $idempotency_ok
			&& $retry_ok
			&& $replay_key_ok
			&& $conflict_ok
			&& $provider_once_ok;
		$steps[] = array(
			'label'  => 'Runtime — Video client mock submit/poll preserves gateway headers',
			'status' => $video_runtime_ok ? 'pass' : 'fail',
			'detail' => wp_json_encode( array(
				'checks' => array( 'submit' => ! empty( $submit['success'] ), 'task_id' => (string) ( $submit['task_id'] ?? '' ), 'poll' => ! empty( $poll['success'] ), 'route' => $route_ok, 'auth' => $auth_ok, 'trace' => $trace_ok, 'idempotency' => $idempotency_ok, 'retry' => $retry_ok, 'replay_key_reused' => $replay_key_ok, 'payload_conflict' => $conflict_ok, 'provider_called_once' => $provider_once_ok ),
				'request_count' => count( $captured ),
				'submit_attempts' => $submit_attempts,
				'provider_submit_count' => $provider_submit_count,
				'url' => (string) $first['url'],
				'header_names' => array_keys( $headers ),
			) ),
		);
		}

		$status = $pagebuilder_static && $video_static && $pagebuilder_transport_static && $error_contract_ok && $mutation_ok && $mutation_denied_ok && $pagebuilder_error_ok && $pagebuilder_mutation_error_ok && $video_runtime_ok ? 'pass' : 'fail';
		return array(
			'status'   => $status,
			'summary'  => $status === 'pass' ? 'Phase 1.24 package adoption mock contract passed.' : 'Phase 1.24 package adoption contract has pending failures.',
			'fix_hint' => 'Review PageBuilder upload/submission envelope, Video client transport, and provider-key quarantine steps.',
			'steps'    => $steps,
		);
	}

	private function read_sources( $root, array $files ): string {
		$source = '';
		foreach ( $files as $relative ) {
			$contents = @file_get_contents( $root . $relative );
			if ( false !== $contents ) {
				$source .= "\n" . $contents;
			}
		}
		return $source;
	}

	private function header_value( array $headers, string $name ): string {
		foreach ( $headers as $key => $value ) {
			if ( strtolower( (string) $key ) === strtolower( $name ) ) {
				return (string) $value;
			}
		}
		return '';
	}

	public static function gateway_url( $value ) { return 'https://video-probe.invalid'; }
	public static function gateway_key( $value ) { return 'biz-aaaaaaaaaaaaaaaa'; }
	public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $probes ) {
	$probes[] = 'BizCity_Probe_Framework_Package_Adoption';
	return $probes;
} );
