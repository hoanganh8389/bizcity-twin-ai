<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class BZPB_Rest_API {

	const NAMESPACE = 'bzpb/v1';

	public static function init(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
		// [2026-08-10 Johnny Chu] PHASE-1.24-PAGEBUILDER — normalize every public bzpb REST WP_Error at one boundary.
		add_filter( 'rest_post_dispatch', [ __CLASS__, 'normalize_rest_error' ], 10, 3 );
		add_action( 'wp_ajax_bzpb_save_inline_edits', [ __CLASS__, 'handle_save_inline_edits' ] );
		// Upload via admin-ajax (same pattern as bizcity-tool-image)
		add_action( 'wp_ajax_bzpb_upload_image', [ __CLASS__, 'ajax_upload_image' ] );
	}

	public static function normalize_rest_error( $response, $server = null, $request = null ) {
		if ( ! is_wp_error( $response ) || ! $request || ! is_object( $request ) || ! method_exists( $request, 'get_route' ) ) {
			return $response;
		}
		$route = (string) $request->get_route();
		if ( 0 !== strpos( $route, '/' . self::NAMESPACE . '/' ) ) {
			return $response;
		}

		// [2026-08-10 Johnny Chu] PHASE-1.24-PAGEBUILDER — keep technical WP_Error details out of public REST responses.
		$code = (string) $response->get_error_code();
		$map  = self::public_error_map( $code );
		$payload = class_exists( 'BizCity_Error_Payload' )
			? BizCity_Error_Payload::make( $map['code'], $map['message'], $map['hint'], $map['help_code'] )
			: array(
				'success'   => false,
				'_degraded' => true,
				'code'      => $map['code'],
				'message'   => $map['message'],
				'hint'      => $map['hint'],
				'help_code' => $map['help_code'],
			);
		$status = $response->get_error_data( $code );
		$status = is_array( $status ) && isset( $status['status'] ) ? (int) $status['status'] : 400;
		return new \WP_REST_Response( $payload, $status > 0 ? $status : 400 );
	}

	private static function public_error_map( $code ) {
		$code = sanitize_key( (string) $code );
		$map = array(
			'auth_error'             => array( 'auth_required', 'Phiên đăng nhập không hợp lệ.', 'Đăng nhập lại rồi thử lại.', 'auth_session_expired' ),
			'module_not_loaded'      => array( 'module_not_loaded', 'PageBuilder chưa sẵn sàng.', 'Kiểm tra các module BizCity rồi thử lại.', 'module_not_loaded' ),
			'permission_denied'      => array( 'permission_denied', 'Bạn không có quyền thực hiện thao tác này.', 'Kiểm tra quyền tài khoản rồi thử lại.', 'permission_required' ),
			'not_found'              => array( 'not_found', 'Không tìm thấy dữ liệu PageBuilder.', 'Chọn lại dữ liệu rồi thử lại.', 'not_found' ),
			'invalid_image'          => array( 'invalid_param', 'Ảnh đầu vào không hợp lệ.', 'Chọn ảnh đúng định dạng rồi thử lại.', 'pagebuilder_upload_format' ),
			'image_too_large'        => array( 'invalid_param', 'Ảnh vượt quá giới hạn cho phép.', 'Giảm kích thước ảnh rồi thử lại.', 'pagebuilder_upload_size' ),
			'forbidden_url'          => array( 'permission_denied', 'URL này không được phép truy cập.', 'Chọn URL công khai hợp lệ rồi thử lại.', 'permission_denied' ),
			'invalid_url'             => array( 'invalid_param', 'URL đầu vào không hợp lệ.', 'Kiểm tra URL rồi thử lại.', 'invalid_param_generic' ),
			'missing_prompt'         => array( 'invalid_param', 'Thiếu nội dung yêu cầu.', 'Nhập nội dung rồi thử lại.', 'invalid_param_generic' ),
			'missing_html'           => array( 'invalid_param', 'Thiếu mã HTML đầu vào.', 'Dán mã HTML rồi thử lại.', 'invalid_param_generic' ),
			'invalid_config'         => array( 'invalid_param', 'Cấu hình website không hợp lệ.', 'Kiểm tra cấu hình rồi thử lại.', 'invalid_param_generic' ),
			'missing_params'         => array( 'invalid_param', 'Thiếu tham số bắt buộc.', 'Bổ sung dữ liệu rồi thử lại.', 'invalid_param_generic' ),
			'missing_id'             => array( 'invalid_param', 'Thiếu mã project.', 'Chọn project rồi thử lại.', 'invalid_param_generic' ),
			'json_error'             => array( 'llm_error', 'Kết quả AI không đúng định dạng.', 'Thử tạo lại nội dung.', 'gateway_degraded' ),
			'empty_response'         => array( 'gateway_degraded', 'Nguồn đầu vào không trả về dữ liệu.', 'Kiểm tra nguồn rồi thử lại.', 'gateway_degraded' ),
			'screenshot_failed'      => array( 'gateway_degraded', 'Không thể tạo ảnh chụp từ URL.', 'Thử lại sau vài phút.', 'gateway_degraded' ),
			'fetch_failed'           => array( 'gateway_degraded', 'Không thể tải dữ liệu từ URL.', 'Kiểm tra URL rồi thử lại.', 'gateway_degraded' ),
			'llm_error'              => array( 'llm_error', 'Dịch vụ AI tạm thời không khả dụng.', 'Thử lại sau vài phút.', 'gateway_degraded' ),
			'llm_unavailable'        => array( 'module_not_loaded', 'Dịch vụ AI chưa sẵn sàng.', 'Kiểm tra BizCity AI Gateway rồi thử lại.', 'module_not_loaded' ),
			'db_error'               => array( 'automation_run_failed', 'Không thể lưu dữ liệu PageBuilder.', 'Thử lại sau vài phút.', 'gateway_degraded' ),
			'encode_error'           => array( 'automation_run_failed', 'Không thể lưu cấu hình website.', 'Thử lại sau vài phút.', 'gateway_degraded' ),
			'cf7_create_failed'      => array( 'automation_run_failed', 'Không thể tạo biểu mẫu liên hệ.', 'Kiểm tra Contact Form 7 rồi thử lại.', 'gateway_degraded' ),
			// [2026-08-13 Johnny Chu] PHASE-1.24-PAGEBUILDER — preserve the mutation contract error at the REST boundary.
			'mutation_contract_invalid' => array( 'invalid_param', 'Thiếu mã xác nhận cho thao tác PageBuilder.', 'Tải lại trình chỉnh sửa rồi thử lại thao tác.', 'mutation_contract_invalid' ),
			'mutation_in_progress'   => array( 'gateway_degraded', 'Thao tác PageBuilder đang được xử lý.', 'Đợi thao tác hiện tại hoàn tất rồi thử lại.', 'retry_later' ),
			'idempotency_conflict'   => array( 'invalid_param', 'Mã xác nhận đã được dùng cho dữ liệu khác.', 'Tạo lại thao tác rồi thử lại.', 'mutation_contract_invalid' ),
		);
		$item = $map[ $code ] ?? array( 'gateway_degraded', 'PageBuilder không thể hoàn tất thao tác.', 'Thử lại sau vài phút.', 'gateway_degraded' );
		return array( 'code' => $item[0], 'message' => $item[1], 'hint' => $item[2], 'help_code' => $item[3] );
	}

	public static function register_routes(): void {
		/* ── Generate website via AI ── */
		register_rest_route( self::NAMESPACE, '/generate', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_generate' ],
			'permission_callback' => [ __CLASS__, 'check_auth' ],
		] );

		/* ── Save project ── */
		register_rest_route( self::NAMESPACE, '/save', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_save' ],
			'permission_callback' => [ __CLASS__, 'check_auth' ],
		] );

		/* ── List projects ── */
		register_rest_route( self::NAMESPACE, '/projects', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'handle_list_projects' ],
			'permission_callback' => [ __CLASS__, 'check_auth' ],
		] );

		/* ── Get single project ── */
		register_rest_route( self::NAMESPACE, '/project/(?P<id>\d+)', [
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'handle_get_project' ],
				'permission_callback' => [ __CLASS__, 'check_auth' ],
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ __CLASS__, 'handle_delete_project' ],
				'permission_callback' => [ __CLASS__, 'check_auth' ],
			],
		] );

		/* ── Publish → WP page ── */
		register_rest_route( self::NAMESPACE, '/publish', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_publish' ],
			'permission_callback' => [ __CLASS__, 'check_auth' ],
		] );

		/* ── Edit via AI instruction ── */
		register_rest_route( self::NAMESPACE, '/edit', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_edit' ],
			'permission_callback' => [ __CLASS__, 'check_auth' ],
		] );

		/* ── List generations (version history) for a project ── */
		register_rest_route( self::NAMESPACE, '/generations/(?P<project_id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'handle_list_generations' ],
			'permission_callback' => [ __CLASS__, 'check_auth' ],
		] );

		/* ── Get single generation (to preview snapshot) ── */
		register_rest_route( self::NAMESPACE, '/generation/(?P<id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'handle_get_generation' ],
			'permission_callback' => [ __CLASS__, 'check_auth' ],
		] );

		/* ── Restore a generation (write its snapshot back as current) ── */
		register_rest_route( self::NAMESPACE, '/generation/restore', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_restore_generation' ],
			'permission_callback' => [ __CLASS__, 'check_auth' ],
		] );

		/* ── Upload image → WordPress Media Library, return URL ── */
		register_rest_route( self::NAMESPACE, '/upload-image', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_upload_image' ],
			'permission_callback' => [ __CLASS__, 'check_auth' ],
		] );

		/* ── Generate from screenshot (vision) ── */
		register_rest_route( self::NAMESPACE, '/generate-from-screenshot', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_generate_from_screenshot' ],
			'permission_callback' => [ __CLASS__, 'check_auth' ],
		] );

		/* ── Screenshot a URL → base64 image ── */
		register_rest_route( self::NAMESPACE, '/screenshot-url', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_screenshot_url' ],
			'permission_callback' => [ __CLASS__, 'check_auth' ],
		] );

		/* ── Import HTML → SiteConfig ── */
		register_rest_route( self::NAMESPACE, '/import-html', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_import_html' ],
			'permission_callback' => [ __CLASS__, 'check_auth' ],
		] );

		/* ── Generate image via DALL-E / Flux ── */
		register_rest_route( self::NAMESPACE, '/generate-image', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_generate_image' ],
			'permission_callback' => [ __CLASS__, 'check_auth' ],
		] );

		/* ── Multi-turn agentic generate ── */
		register_rest_route( self::NAMESPACE, '/generate-agent', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_generate_agent' ],
			'permission_callback' => [ __CLASS__, 'check_auth' ],
		] );

		/* ── Hybrid: screenshot hero (raw HTML) + JSON blocks ── */
		register_rest_route( self::NAMESPACE, '/generate-hybrid', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_generate_hybrid' ],
			'permission_callback' => [ __CLASS__, 'check_auth' ],
		] );

		/* ── Diagnose / repair page meta (admin only) ── */
		register_rest_route( self::NAMESPACE, '/diagnose/(?P<page_id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'handle_diagnose' ],
			'permission_callback' => function () { return current_user_can( 'manage_options' ); },
		] );

		/* ── Repair: re-save post meta from bzpb_projects (admin only) ── */
		register_rest_route( self::NAMESPACE, '/repair/(?P<page_id>\d+)', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_repair' ],
			'permission_callback' => function () { return current_user_can( 'manage_options' ); },
		] );

		/* ── Create CF7 Form — [2026-06-27 Johnny Chu] PHASE-PB-LEADFORM ── */
		register_rest_route( self::NAMESPACE, '/create-cf7-form', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_create_cf7_form' ],
			'permission_callback' => function () { return current_user_can( 'manage_options' ); },
		] );

		/* ── Page Tracking sync status — [2026-06-27 Johnny Chu] PHASE-PB-LEADFORM Wave 4 ── */
		register_rest_route( self::NAMESPACE, '/page-tracking/(?P<project_id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'handle_get_page_tracking' ],
			'permission_callback' => function () { return current_user_can( 'manage_options' ); },
		] );
	}

	public static function check_auth(): bool {
		// Check if user is logged in via cookie
		if ( ! is_user_logged_in() ) {
			return false;
		}
		
		// CRITICAL: Validate user actually exists in current shard
		// In multisite sharding, cookie may exist but user may not exist in this shard's DB
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return false;
		}
		
		// Verify user exists in database (prevents capabilities.php fatal error)
		$user = get_userdata( $user_id );
		if ( ! $user || ! $user->exists() ) {
			error_log( "[BZPB] Auth failed: user_id={$user_id} does not exist in current shard" );
			// Clear invalid cookie to prevent further errors
			wp_clear_auth_cookie();
			return false;
		}
		
		return true;
	}

	private static function preflight_project_mutation( \WP_REST_Request $request, $action, $project_id = 0 ) {
		// [2026-07-30 Johnny Chu] PHASE-1.22-RUNTIME — require mutation correlation before PageBuilder DB writes.
		if ( ! class_exists( 'BizCity_Twin_Mutation_Guard' ) ) {
			return new \WP_Error( 'module_not_loaded', 'Mutation guard chưa được tải.', array( 'status' => 500 ) );
		}
		$trace_id        = sanitize_text_field( (string) $request->get_header( 'x-trace-id' ) );
		$idempotency_key = sanitize_text_field( (string) $request->get_header( 'x-idempotency-key' ) );
		if ( '' === $trace_id && function_exists( 'wp_generate_uuid4' ) ) {
			$trace_id = wp_generate_uuid4();
		}
		if ( '' === $idempotency_key ) {
			return new \WP_Error( 'mutation_contract_invalid', 'Thiếu x-idempotency-key cho thao tác PageBuilder.', array(
				'status'    => 400,
				'hint'      => 'Gửi x-idempotency-key ổn định cho mỗi lần lưu.',
				'help_code' => 'mutation_contract_invalid',
			) );
		}
		$mutation = array(
			'contract'        => 'mutation-contract',
			'version'         => '1.0.0',
			'trace_id'        => $trace_id,
			'idempotency_key' => $idempotency_key,
			'action'          => (string) $action,
			'resource'        => array(
				'type'  => 'pagebuilder_project',
				'id'    => max( 0, (int) $project_id ),
				'scope' => 'pagebuilder_project:' . max( 0, (int) $project_id ),
			),
		);
		$permissions    = 'delete' === (string) $action
			? array( 'content.delete' )
			: ( 'publish' === (string) $action ? array( 'content.publish' ) : array( 'content.write' ) );
		$approved_gates = 'delete' === (string) $action
			? array( 'delete_data' )
			: ( 'publish' === (string) $action ? array( 'publish_content' ) : array() );
		$context        = array(
			'user_id'        => get_current_user_id(),
			'permissions'    => $permissions,
			'approved_gates' => $approved_gates,
		);
		$check = BizCity_Twin_Mutation_Guard::validate( $mutation, $context );
		if ( empty( $check['allowed'] ) ) {
			return new \WP_Error( (string) $check['code'], (string) $check['message'], array(
				'status'    => 403,
				'hint'      => (string) $check['hint'],
				'help_code' => (string) $check['help_code'],
			) );
		}
		return array( 'mutation' => $mutation, 'context' => $context );
	}

	private static function error_payload( $code, $message, $hint, $help_code, $context = array() ) {
		// [2026-08-10 Johnny Chu] PHASE-1.24-PAGEBUILDER — centralize REST/AJAX upload error payloads.
		return class_exists( 'BizCity_Error_Payload' )
			? BizCity_Error_Payload::make( $code, $message, $hint, $help_code, $context )
			: array(
				'success'   => false,
				'_degraded' => true,
				'code'      => (string) $code,
				'message'   => (string) $message,
				'hint'      => (string) $hint,
				'help_code' => (string) $help_code,
				'context'   => (array) $context,
			);
	}

	private static function rest_error( $code, $message, $hint, $help_code, $status = 400, $context = array() ) {
		return new \WP_REST_Response(
			self::error_payload( $code, $message, $hint, $help_code, $context ),
			(int) $status
		);
	}

	/* ═══════════════════════════════════════════════
	   GENERATE — AI prompt → SiteConfig JSON
	   ═══════════════════════════════════════════════ */
	public static function handle_generate( \WP_REST_Request $request ) {
		$prompt = sanitize_textarea_field( $request->get_param( 'prompt' ) );
		if ( empty( $prompt ) ) {
			return new \WP_Error( 'missing_prompt', 'Prompt is required.', [ 'status' => 400 ] );
		}

		$theme = sanitize_text_field( $request->get_param( 'theme' ) );

		$user_prompt = $prompt;
		if ( ! empty( $theme ) ) {
			$user_prompt .= "\n\nUse the \"{$theme}\" theme preset.";
		}
		$user_prompt .= "\n\nRespond with a complete JSON SiteConfig object ONLY. No markdown, no explanation.";

		$system_prompt = self::get_system_prompt();
		$ai_response   = self::call_llm( $system_prompt, $user_prompt );

		if ( is_wp_error( $ai_response ) ) {
			return $ai_response;
		}

		// Parse JSON from response — retry once on invalid JSON
		$config = self::parse_ai_json( $ai_response );
		if ( is_wp_error( $config ) ) {
			error_log( '[BZPB] First JSON parse failed, retrying with repair prompt...' );
			$repair_prompt = "Your previous response was not valid JSON. Here is what you returned:\n\n"
				. substr( $ai_response, 0, 2000 )
				. "\n\nPlease fix it and return ONLY a valid JSON SiteConfig object with a \"blocks\" array. No markdown fences, no explanation.";
			$retry_response = self::call_llm( $system_prompt, $repair_prompt, 8000 );
			if ( is_wp_error( $retry_response ) ) {
				return $config; // Return original error
			}
			$config = self::parse_ai_json( $retry_response );
			if ( is_wp_error( $config ) ) {
				return $config;
			}
		}

		// Validate block types
		$config = self::validate_blocks( $config );

		// Auto-save as new project
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return new \WP_Error( 'auth_error', 'Invalid user session.', [ 'status' => 401 ] );
		}
		$project_id = self::save_project_to_db( $user_id, $config['name'] ?? $prompt, $config );

		if ( ! $project_id ) {
			return new \WP_Error( 'db_error', 'Generated successfully but failed to save project.', [ 'status' => 500 ] );
		}

		// Auto-snapshot this generation
		self::log_generation_completed( $project_id, $user_id, 'generate', $prompt, $config, 'anthropic/claude-sonnet-4' );

		return rest_ensure_response( [
			'success'    => true,
			'project_id' => $project_id,
			'config'     => $config,
		] );
	}

	/* ═══════════════════════════════════════════════
	   GENERATE FROM SCREENSHOT — Vision AI → SiteConfig
	   ═══════════════════════════════════════════════ */
	public static function handle_generate_from_screenshot( \WP_REST_Request $request ) {
		$image_data = $request->get_param( 'image_data' );
		$prompt     = sanitize_textarea_field( $request->get_param( 'prompt' ) ?? '' );
		$theme      = sanitize_text_field( $request->get_param( 'theme' ) ?? '' );
		$mode       = sanitize_text_field( $request->get_param( 'mode' ) ?? 'auto' );

		if ( empty( $image_data ) || ! preg_match( '/^data:image\/(png|jpeg|jpg|webp|gif);base64,/i', $image_data ) ) {
			return new \WP_Error( 'invalid_image', 'Valid base64 image data is required.', [ 'status' => 400 ] );
		}

		// Enforce size limit: 5MB raw data
		if ( strlen( $image_data ) > 5 * 1024 * 1024 * 1.4 ) { // base64 is ~1.37× raw
			return new \WP_Error( 'image_too_large', 'Image exceeds 5 MB limit.', [ 'status' => 413 ] );
		}

		// Resolve 'auto' mode based on prompt length
		if ( $mode === 'auto' ) {
			$mode = mb_strlen( $prompt ) > 20 ? 'reference' : 'replicate';
		}

		if ( $mode === 'reference' && empty( $prompt ) ) {
			return new \WP_Error( 'missing_prompt', 'Prompt is required in reference mode.', [ 'status' => 400 ] );
		}

		$user_text = self::build_vision_prompt( $mode, $prompt, $theme );

		$system_prompt = self::get_system_prompt();
		$ai_response   = self::call_llm_vision( $system_prompt, $user_text, $image_data );

		if ( is_wp_error( $ai_response ) ) {
			return $ai_response;
		}

		$config = self::parse_ai_json( $ai_response );
		if ( is_wp_error( $config ) ) {
			// Retry once
			$repair_prompt = "Invalid JSON. Fix and return only a valid JSON SiteConfig:\n" . substr( $ai_response, 0, 2000 );
			$retry         = self::call_llm( $system_prompt, $repair_prompt, 8000 );
			if ( ! is_wp_error( $retry ) ) {
				$config = self::parse_ai_json( $retry );
			}
			if ( is_wp_error( $config ) ) {
				return $config;
			}
		}

		$config = self::validate_blocks( $config );

		$user_id    = get_current_user_id();
		$project_id = self::save_project_to_db( $user_id, $config['name'] ?? 'Screenshot site', $config );
		if ( ! $project_id ) {
			return new \WP_Error( 'db_error', 'Failed to save project.', [ 'status' => 500 ] );
		}

		$label = $mode === 'reference'
			? "Tham chiếu giao diện + prompt: {$prompt}"
			: ( ! empty( $prompt ) ? "Screenshot nhân bản: {$prompt}" : 'Generate from screenshot' );
		self::log_generation_completed( $project_id, $user_id, $mode === 'reference' ? 'screenshot_reference' : 'screenshot', $label, $config, 'anthropic/claude-sonnet-4' );

		return rest_ensure_response( [
			'success'    => true,
			'project_id' => $project_id,
			'config'     => $config,
			'mode'       => $mode,
		] );
	}

	/**
	 * Build the user-facing prompt text for vision calls.
	 * 'replicate'  — AI copies layout + content from image
	 * 'reference'  — AI uses image as visual/style guide; content from $prompt
	 */
	private static function build_vision_prompt( string $mode, string $prompt, string $theme ): string {
		$theme_line = ! empty( $theme ) ? "\nTheme preset: \"{$theme}\"." : '';

		if ( $mode === 'reference' ) {
			return <<<PROMPT
Đây là ảnh tham chiếu giao diện website.

MỤC TIÊU CỦA BẠN:
{$prompt}

NHIỆM VỤ:
1. Học layout/cấu trúc từ ảnh: vị trí navbar, hero, số lượng sections, cách sắp xếp columns, spacing
2. Học style từ ảnh: bảng màu, kiểu font, border-radius, card style, button style
3. Tạo NỘI DUNG hoàn toàn mới theo mục tiêu trên — KHÔNG sao chép nội dung từ ảnh
4. Chọn block types BizCity phù hợp nhất cho mục tiêu (navbar, hero, features, pricing, testimonials, cta, contact, footer, v.v.)
5. Tạo copy, headline, description tiếng Việt sắc bén, đúng với chiến dịch/mục tiêu đã nêu
6. Đặt tên site, tagline phản ánh đúng mục tiêu{$theme_line}

Trả về JSON SiteConfig hoàn chỉnh. Không markdown, không giải thích.
PROMPT;
		}

		// replicate mode
		$notes = ! empty( $prompt ) ? "\nGhi chú bổ sung: {$prompt}" : '';
		return <<<PROMPT
Phân tích screenshot website này và tái tạo thành BizCity SiteConfig JSON.

NHIỆM VỤ:
1. Nhận diện các section chính (navbar, hero, features, pricing, testimonials, contact, footer, v.v.)
2. Tái tạo layout, số cột, spacing từ ảnh
3. Giữ nguyên màu sắc và style (bảng màu, border-radius, typography) từ ảnh
4. Giữ nguyên nội dung text nếu đọc được, hoặc tạo nội dung tương tự
5. Chọn block types BizCity phù hợp nhất{$notes}{$theme_line}

Trả về JSON SiteConfig hoàn chỉnh. Không markdown, không giải thích.
PROMPT;
	}

	/* ═══════════════════════════════════════════════
	   IMPORT HTML — Convert existing HTML code to SiteConfig
	   ═══════════════════════════════════════════════ */
	public static function handle_import_html( \WP_REST_Request $request ) {
		$html  = $request->get_param( 'html' );
		$theme = sanitize_text_field( $request->get_param( 'theme' ) ?? '' );

		if ( empty( $html ) || ! is_string( $html ) ) {
			return new \WP_Error( 'missing_html', 'HTML content is required.', [ 'status' => 400 ] );
		}

		// Limit size to prevent token explosion (~12 KB of HTML is plenty for AI analysis)
		$html_truncated = mb_substr( strip_tags( $html, '<div><section><header><footer><nav><main><aside><h1><h2><h3><h4><h5><p><ul><ol><li><span><a><button><img><form><input><textarea>' ), 0, 12000 );
		$theme_line     = ! empty( $theme ) ? "\nTheme preset: \"{$theme}\"." : '';

		$user_prompt = <<<PROMPT
Dưới đây là mã HTML của một website:

```html
{$html_truncated}
```

NHIỆM VỤ:
1. Phân tích cấu trúc: nhận diện các section (navbar, hero, features, pricing, testimonials, contact, footer, v.v.)
2. Chuyển đổi từng section thành BizCity block với type phù hợp
3. Giữ nguyên nội dung text, headline, mô tả từ HTML
4. Tái tạo màu sắc và style gần nhất có thể trong BizCity theme
5. Đảm bảo layout logic (columns, spacing) phản ánh đúng HTML gốc{$theme_line}

Trả về JSON SiteConfig hoàn chỉnh. Không markdown, không giải thích.
PROMPT;

		$system_prompt = self::get_system_prompt();
		$ai_response   = self::call_llm( $system_prompt, $user_prompt, 16000 );

		if ( is_wp_error( $ai_response ) ) {
			return $ai_response;
		}

		$config = self::parse_ai_json( $ai_response );
		if ( is_wp_error( $config ) ) {
			$repair_prompt = "Invalid JSON. Fix and return only a valid JSON SiteConfig:\n" . substr( $ai_response, 0, 2000 );
			$retry         = self::call_llm( $system_prompt, $repair_prompt, 8000 );
			if ( ! is_wp_error( $retry ) ) {
				$config = self::parse_ai_json( $retry );
			}
			if ( is_wp_error( $config ) ) {
				return $config;
			}
		}

		$config     = self::validate_blocks( $config );
		$user_id    = get_current_user_id();
		$project_id = self::save_project_to_db( $user_id, $config['name'] ?? 'Imported site', $config );

		if ( ! $project_id ) {
			return new \WP_Error( 'db_error', 'Generated but failed to save project.', [ 'status' => 500 ] );
		}

		self::log_generation_completed( $project_id, $user_id, 'import_html', 'HTML import', $config, 'anthropic/claude-sonnet-4' );

		return rest_ensure_response( [
			'success'    => true,
			'project_id' => $project_id,
			'config'     => $config,
		] );
	}

	/* ═══════════════════════════════════════════════
	   GENERATE HYBRID — 2-pass: hero HTML + JSON blocks
	   Pass 1: Vision → extract above-fold as raw HTML/CSS
	   Pass 2: Vision → generate remaining blocks as JSON
	   Result: custom-html block + standard JSON blocks
	   ═══════════════════════════════════════════════ */
	public static function handle_generate_hybrid( \WP_REST_Request $request ) {
		$image_data = $request->get_param( 'image_data' );
		$prompt     = sanitize_textarea_field( $request->get_param( 'prompt' ) ?? '' );
		$theme      = sanitize_text_field( $request->get_param( 'theme' ) ?? '' );

		if ( empty( $image_data ) || ! preg_match( '/^data:image\/(png|jpeg|jpg|webp|gif);base64,/i', $image_data ) ) {
			return new \WP_Error( 'invalid_image', 'Valid base64 image data is required.', [ 'status' => 400 ] );
		}
		if ( strlen( $image_data ) > 5 * 1024 * 1024 * 1.4 ) {
			return new \WP_Error( 'image_too_large', 'Image exceeds 5 MB limit.', [ 'status' => 413 ] );
		}

		$theme_line  = ! empty( $theme ) ? "\nTheme preset: \"{$theme}\"." : '';
		$extra_notes = ! empty( $prompt ) ? "\nGhi chú bổ sung: {$prompt}" : '';

		// ── Pass 1: Extract hero/above-fold as raw self-contained HTML ──
		$hero_prompt = <<<PROMPT
Bạn là một front-end engineer cao cấp chuyên pixel-perfect HTML reproduction.

NHIỆM VỤ: Phân tích ảnh chụp màn hình website và tái tạo section HERO (above-the-fold) thành HTML với inline CSS chính xác đến 99%.

QUY TẮC BẮT BUỘC:
1. CHỈ trả về đoạn HTML thuần — KHÔNG có <html>, <head>, <body>, <style>, markdown, giải thích
2. Dùng 100% inline style attribute — tuyệt đối không dùng class hay external CSS
3. TOÀN TRANG RỘNG: section ngoài cùng: width:100%; min-height:100vh (hoặc 80vh nếu hero ngắn)

PHÂN TÍCH MÀU SẮC (quan trọng nhất):
- Đọc chính xác màu nền: solid color (#hex), linear-gradient hoặc radial-gradient với đúng color-stop và góc độ
- Nếu có gradient text: dùng background:linear-gradient(...); -webkit-background-clip:text; -webkit-text-fill-color:transparent; background-clip:text
- Tái tạo overlay, glow, blur nền nếu thấy trong ảnh
- Giữ đúng opacity của các layer

TYPOGRAPHY (phải chính xác):
- Ước lượng font-size bằng px dựa trên tỷ lệ ảnh (heading lớn ~48-80px, subheading ~20-28px, body ~14-16px)
- font-weight: đúng theo ảnh (400/500/600/700/800/900)
- letter-spacing, line-height, text-align theo ảnh
- font-family: -apple-system, "Segoe UI", system-ui, sans-serif

LAYOUT:
- Dùng flexbox (display:flex; flex-direction:column; align-items:center; justify-content:center) cho centering
- Container nội dung: max-width:1100px; margin:0 auto; padding:80px 40px
- Nếu có layout 2 cột: display:grid; grid-template-columns:1fr 1fr; gap:40px
- Tái tạo badge/pill bằng: display:inline-flex; align-items:center; gap:8px; padding:6px 14px; border-radius:999px; border:1px solid; font-size:13px
- Tái tạo button với đúng màu, border-radius, padding, font-weight

CHI TIẾT CẦN TÁI TẠO:
- Dấu chấm/icon trước badge text
- Bullet points hoặc check list
- Khoảng cách giữa các phần tử (margin-top, margin-bottom)
- Hình ảnh/mockup bên phải nếu có (dùng placeholder hoặc border để giữ vị trí)

{$extra_notes}

Chỉ trả về HTML. Không có gì khác.
PROMPT;


		$hero_html_raw = self::call_llm_vision( '', $hero_prompt, $image_data, 8000 );
		if ( is_wp_error( $hero_html_raw ) ) {
			return self::handle_generate_from_screenshot( $request );
		}

		// Strip accidental markdown code fences
		$hero_html = preg_replace( '/^```[a-z]*\s*/i', '', trim( $hero_html_raw ) );
		$hero_html = preg_replace( '/\s*```$/', '', $hero_html );

		// ── Pass 2: Generate remaining blocks as structured JSON ──
		$blocks_prompt = "Phân tích ảnh chụp màn hình website này.\n\nNHIỆM VỤ: Tạo BizCity SiteConfig JSON. KHÔNG tạo block hero — chỉ tạo các block SAU hero: navbar (nếu có), features, pricing, testimonials, cta, contact, footer, v.v.\n\nQUY TẮC:\n1. Dùng đúng block types: navbar, features, pricing, testimonials, stats, faq, team, contact, newsletter, logocloud, content, divider, banner, cta, footer\n2. Giữ tông màu, phong cách từ ảnh{$theme_line}\n3. Nội dung thực tế từ ảnh hoặc tương tự{$extra_notes}\n\nTrả về JSON SiteConfig hoàn chỉnh. Không markdown, không giải thích.";

		$system_prompt = self::get_system_prompt();
		$ai_response   = self::call_llm_vision( $system_prompt, $blocks_prompt, $image_data, 16000 );

		if ( is_wp_error( $ai_response ) ) {
			return self::handle_generate_from_screenshot( $request );
		}

		$config = self::parse_ai_json( $ai_response );
		if ( is_wp_error( $config ) ) {
			$repair = "Invalid JSON. Fix:\n" . substr( $ai_response, 0, 2000 );
			$retry  = self::call_llm( $system_prompt, $repair, 8000 );
			if ( ! is_wp_error( $retry ) ) {
				$config = self::parse_ai_json( $retry );
			}
			if ( is_wp_error( $config ) ) {
				return self::handle_generate_from_screenshot( $request );
			}
		}

		$config = self::validate_blocks( $config );

		// Remove hero blocks AI still generated — we have our own
		$config['blocks'] = array_values( array_filter(
			$config['blocks'] ?? [],
			function( $b ) { return ! in_array( $b['type'] ?? '', [ 'hero' ], true ); }
		) );

		// Prepend custom-html hero block
		$hero_block = [
			'id'      => 'hero-hybrid-' . uniqid(),
			'type'    => 'custom-html',
			'variant' => 'default',
			'props'   => [ 'html' => $hero_html ],
		];
		array_unshift( $config['blocks'], $hero_block );

		// Ensure navbar (if any) stays first
		usort( $config['blocks'], function( $a, $b ) {
			$order = [ 'navbar' => 0, 'custom-html' => 1 ];
			return ( $order[ $a['type'] ] ?? 5 ) <=> ( $order[ $b['type'] ] ?? 5 );
		} );

		$user_id    = get_current_user_id();
		$project_id = self::save_project_to_db( $user_id, $config['name'] ?? 'Hybrid site', $config );
		if ( ! $project_id ) {
			return new \WP_Error( 'db_error', 'Failed to save project.', [ 'status' => 500 ] );
		}

		self::log_generation_completed( $project_id, $user_id, 'hybrid_screenshot', 'Hybrid: hero HTML + JSON blocks', $config, 'anthropic/claude-sonnet-4' );

		return rest_ensure_response( [
			'success'    => true,
			'project_id' => $project_id,
			'config'     => $config,
			'mode'       => 'hybrid',
		] );
	}

	/* ═══════════════════════════════════════════════
	   GENERATE IMAGE — DALL-E 3 / Unsplash fallback
	   ═══════════════════════════════════════════════ */
	public static function handle_generate_image( \WP_REST_Request $request ) {
		$image_prompt = sanitize_textarea_field( $request->get_param( 'prompt' ) ?? '' );
		if ( empty( $image_prompt ) ) {
			return new \WP_Error( 'missing_prompt', 'Image prompt is required.', [ 'status' => 400 ] );
		}

		// [2026-07-30 Johnny Chu] R-GW-8/R-1API-AUTH — route image generation through the managed client; provider credentials never belong to PageBuilder.
		if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
			return rest_ensure_response( BizCity_Error_Payload::module_not_loaded( 'BizCity LLM Client' ) );
		}
		$client = BizCity_LLM_Client::instance();
		if ( ! $client->is_ready() ) {
			return rest_ensure_response( BizCity_Error_Payload::gateway_degraded() );
		}

		$result = $client->generate_image( $image_prompt, array(
			'model'   => 'gpt-image-1',
			'n'       => 1,
			'size'    => '1792x1024',
			'purpose' => 'pagebuilder_image',
		) );
		if ( empty( $result['success'] ) ) {
			return rest_ensure_response( BizCity_Error_Payload::make(
				'gateway_degraded',
				'Không thể tạo hình ảnh từ dịch vụ AI.',
				'Thử lại sau vài phút hoặc kiểm tra kết nối BizCity.',
				'gateway_degraded'
			) );
		}

		return rest_ensure_response( array(
			'success' => true,
			'url'     => (string) ( $result['image_url'] ?? '' ),
			'b64_json'=> (string) ( $result['b64_json'] ?? '' ),
			'source'  => 'bizcity_llm_gateway',
			'model'   => (string) ( $result['model'] ?? 'gpt-image-1' ),
		) );
	}

	/* ═══════════════════════════════════════════════
	   GENERATE AGENT — Multi-turn agentic pipeline
	   Turn 1: Outline → block plan
	   Turn 2: Build → full SiteConfig
	   Turn 3: Review → fix inconsistencies
	   ═══════════════════════════════════════════════ */
	public static function handle_generate_agent( \WP_REST_Request $request ) {
		$prompt = sanitize_textarea_field( $request->get_param( 'prompt' ) );
		$theme  = sanitize_text_field( $request->get_param( 'theme' ) ?? '' );

		if ( empty( $prompt ) ) {
			return new \WP_Error( 'missing_prompt', 'Prompt is required.', [ 'status' => 400 ] );
		}

		$system_prompt = self::get_system_prompt();
		$steps         = [];
		$theme_line    = ! empty( $theme ) ? "\nTheme preset: \"{$theme}\"." : '';

		/* ── Turn 1: Outline ── */
		$steps[] = '📋 Bước 1/3: Phân tích yêu cầu và lập kế hoạch blocks...';

		$outline_prompt = <<<PROMPT
Phân tích yêu cầu website sau và lập kế hoạch cấu trúc:

"{$prompt}"{$theme_line}

CHỈ trả về JSON array danh sách blocks:
[
  {"type": "navbar", "reason": "..."},
  {"type": "hero",   "reason": "..."},
  ...
]

Types hợp lệ: navbar, hero, features, pricing, cta, footer, testimonials, stats, faq, team, contact, newsletter, logocloud, content, image, gallery, banner, divider
Trả về JSON array ONLY. Không markdown.
PROMPT;

		$outline_response = self::call_llm( $system_prompt, $outline_prompt, 2000 );
		if ( is_wp_error( $outline_response ) ) {
			return $outline_response;
		}

		$outline_json = preg_replace( '/^```(?:json)?\s*/i', '', trim( $outline_response ) );
		$outline_json = preg_replace( '/\s*```\s*$/', '', $outline_json );
		$outline      = json_decode( $outline_json, true );

		$block_list = '';
		if ( is_array( $outline ) && ! empty( $outline ) ) {
			foreach ( $outline as $i => $b ) {
				$block_list .= ( $i + 1 ) . '. ' . ( $b['type'] ?? '?' ) . ' — ' . ( $b['reason'] ?? '' ) . "\n";
			}
		} else {
			$block_list = '(auto-detect blocks from requirements)';
		}

		/* ── Turn 2: Build ── */
		$steps[] = '🏗️ Bước 2/3: Xây dựng toàn bộ website...';

		$build_prompt = <<<PROMPT
Tạo website hoàn chỉnh cho yêu cầu:

"{$prompt}"{$theme_line}

KẾ HOẠCH BLOCKS:
{$block_list}

NHIỆM VỤ:
- Tạo đúng những blocks trong kế hoạch theo thứ tự
- Viết copy, headline, description chi tiết và sắc bén (không dùng placeholder)
- Tạo màu sắc harmonious, layout đẹp, props đầy đủ
- Mỗi block list phải có ít nhất 3-5 items (features, testimonials, pricing, faq...)

Trả về JSON SiteConfig hoàn chỉnh. Không markdown, không giải thích.
PROMPT;

		$build_response = self::call_llm( $system_prompt, $build_prompt, 16000 );
		if ( is_wp_error( $build_response ) ) {
			return $build_response;
		}

		$config = self::parse_ai_json( $build_response );
		if ( is_wp_error( $config ) ) {
			$repair_prompt = "Invalid JSON. Fix and return only valid JSON SiteConfig:\n" . substr( $build_response, 0, 2000 );
			$retry         = self::call_llm( $system_prompt, $repair_prompt, 8000 );
			if ( ! is_wp_error( $retry ) ) {
				$config = self::parse_ai_json( $retry );
			}
			if ( is_wp_error( $config ) ) {
				return $config;
			}
		}

		/* ── Turn 3: Review & Polish ── */
		$steps[] = '✨ Bước 3/3: Hoàn thiện và kiểm tra chất lượng...';

		$config_json_preview = mb_substr( $build_response, 0, 8000 );
		$review_prompt       = <<<PROMPT
Review và cải thiện BizCity SiteConfig JSON này cho yêu cầu: "{$prompt}"

KIỂM TRA:
1. Tất cả blocks có đủ props không? (không trống, không placeholder "Lorem ipsum")
2. Màu sắc theme nhất quán không?
3. Nội dung có đúng với yêu cầu không?
4. Có thiếu block quan trọng nào không (footer luôn cần có)?

JSON hiện tại (có thể bị cắt):
{$config_json_preview}

Nếu cần sửa, trả về JSON SiteConfig ĐÃ SỬA HOÀN CHỈNH.
Nếu đã ổn, trả lại nguyên JSON cũ.
Trả về JSON ONLY. Không markdown.
PROMPT;

		$review_response = self::call_llm( $system_prompt, $review_prompt, 16000 );
		if ( ! is_wp_error( $review_response ) ) {
			$reviewed = self::parse_ai_json( $review_response );
			if ( ! is_wp_error( $reviewed ) ) {
				$config = $reviewed;
			}
		}

		$config     = self::validate_blocks( $config );
		$user_id    = get_current_user_id();
		$project_id = self::save_project_to_db( $user_id, $config['name'] ?? $prompt, $config );

		if ( ! $project_id ) {
			return new \WP_Error( 'db_error', 'Generated but failed to save project.', [ 'status' => 500 ] );
		}

		self::log_generation_completed( $project_id, $user_id, 'generate_agent', $prompt, $config, 'anthropic/claude-sonnet-4' );

		return rest_ensure_response( [
			'success'    => true,
			'project_id' => $project_id,
			'config'     => $config,
			'steps'      => $steps,
		] );
	}

	/* ═══════════════════════════════════════════════
	   SCREENSHOT URL — server-side URL → base64 image
	   Uses wp_remote_get + GD/Imagick if needed.
	   ═══════════════════════════════════════════════ */
	public static function handle_screenshot_url( \WP_REST_Request $request ) {
		$url = esc_url_raw( $request->get_param( 'url' ) ?? '' );

		if ( empty( $url ) || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return new \WP_Error( 'invalid_url', 'A valid URL is required.', [ 'status' => 400 ] );
		}

		// Block private/local IP ranges (SSRF protection)
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( empty( $host ) ) {
			return new \WP_Error( 'invalid_url', 'Cannot resolve host.', [ 'status' => 400 ] );
		}

		$private_patterns = [ '/^localhost$/i', '/^127\./', '/^10\./', '/^172\.(1[6-9]|2\d|3[01])\./', '/^192\.168\./', '/^::1$/' ];
		foreach ( $private_patterns as $pattern ) {
			if ( preg_match( $pattern, $host ) ) {
				return new \WP_Error( 'forbidden_url', 'Private/internal URLs are not allowed.', [ 'status' => 403 ] );
			}
		}

		// Try ScreenshotOne API if configured, else fallback to wp_remote_get HTML → AI
		$screenshot_api_key = defined( 'BZPB_SCREENSHOT_API_KEY' ) ? BZPB_SCREENSHOT_API_KEY : get_option( 'bzpb_screenshot_api_key', '' );

		if ( ! empty( $screenshot_api_key ) ) {
			// Use ScreenshotOne.com API
			$api_url  = add_query_arg( [
				'access_key'             => $screenshot_api_key,
				'url'                    => rawurlencode( $url ),
				'format'                 => 'jpg',
				'viewport_width'         => 1280,
				'viewport_height'        => 900,
				'full_page'              => 'false',
				'image_quality'          => 80,
				'block_ads'              => 'true',
				'block_cookie_banners'   => 'true',
			], 'https://api.screenshotone.com/take' );

			$response = wp_remote_get( $api_url, [ 'timeout' => 30 ] );
			if ( is_wp_error( $response ) ) {
				return new \WP_Error( 'screenshot_failed', $response->get_error_message(), [ 'status' => 502 ] );
			}
			$body = wp_remote_retrieve_body( $response );
			$code = wp_remote_retrieve_response_code( $response );
			if ( $code !== 200 || empty( $body ) ) {
				return new \WP_Error( 'screenshot_failed', "Screenshot API returned HTTP {$code}.", [ 'status' => 502 ] );
			}

			$image_data = 'data:image/jpeg;base64,' . base64_encode( $body );
		} else {
			// Fallback: fetch HTML source, return an AI-readable summary rather than a real screenshot
			$response = wp_remote_get( $url, [
				'timeout'    => 15,
				'user-agent' => 'Mozilla/5.0 (compatible; BizCity-PageBuilder/1.0)',
			] );
			if ( is_wp_error( $response ) ) {
				return new \WP_Error( 'fetch_failed', $response->get_error_message(), [ 'status' => 502 ] );
			}
			$html = wp_remote_retrieve_body( $response );
			if ( empty( $html ) ) {
				return new \WP_Error( 'empty_response', 'URL returned empty content.', [ 'status' => 502 ] );
			}

			// Return placeholder + HTML as first 4000 chars for AI use
			return rest_ensure_response( [
				'success'    => true,
				'image_data' => '',     // No real screenshot
				'html_hint'  => substr( strip_tags( $html ), 0, 4000 ),
				'mode'       => 'html_fallback',
				'message'    => 'No screenshot API key configured. Using HTML analysis mode. Set BZPB_SCREENSHOT_API_KEY or bzpb_screenshot_api_key option to enable real screenshots.',
			] );
		}

		return rest_ensure_response( [
			'success'    => true,
			'image_data' => $image_data,
			'mode'       => 'screenshot',
		] );
	}

	/* ═══════════════════════════════════════════════
	   SAVE — Store SiteConfig to DB
	   ═══════════════════════════════════════════════ */
	public static function handle_save( \WP_REST_Request $request ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bzpb_projects';

		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return new \WP_Error( 'auth_error', 'Invalid user session.', [ 'status' => 401 ] );
		}
		$project_id = absint( $request->get_param( 'project_id' ) );
		$title      = sanitize_text_field( $request->get_param( 'title' ) ?? '' );
		$config     = $request->get_param( 'config' );

		if ( empty( $config ) || ! is_array( $config ) ) {
			return new \WP_Error( 'invalid_config', 'SiteConfig is required.', [ 'status' => 400 ] );
		}

		$config_json = wp_json_encode( $config, JSON_UNESCAPED_UNICODE );
		// [2026-07-30 Johnny Chu] PHASE-1.22-RUNTIME — guard project create/update before database side effects.
		$preflight = self::preflight_project_mutation( $request, $project_id ? 'update' : 'create', $project_id );
		if ( is_wp_error( $preflight ) ) {
			return $preflight;
		}
		$request_hash = md5( wp_json_encode( array( $project_id, $title, $config ) ) );
		$mutation_state = class_exists( 'BizCity_Twin_Mutation_Store' )
			? BizCity_Twin_Mutation_Store::begin( $preflight['mutation'], $preflight['context'], $request_hash )
			: array( 'status' => 'new', 'key' => '' );
		if ( 'conflict' === (string) ( $mutation_state['status'] ?? '' ) ) {
			return self::rest_error( 'idempotency_conflict', 'Mã idempotency đã dùng cho dữ liệu khác.', 'Tạo mã idempotency mới rồi thử lại.', 'mutation_contract_invalid', 409 );
		}
		if ( 'pending' === (string) ( $mutation_state['status'] ?? '' ) ) {
			return self::rest_error( 'mutation_in_progress', 'Thao tác PageBuilder đang được xử lý.', 'Đợi thao tác hiện tại hoàn tất rồi thử lại.', 'retry_later', 409 );
		}
		if ( 'replay' === (string) ( $mutation_state['status'] ?? '' ) ) {
			$replayed = (array) ( $mutation_state['response'] ?? array() );
			$replayed['idempotency_replayed'] = true;
			return new \WP_REST_Response( $replayed, 200 );
		}

		if ( $project_id ) {
			// Update existing — verify ownership
			$existing = $wpdb->get_row( $wpdb->prepare(
				"SELECT id FROM {$table} WHERE id = %d AND user_id = %d",
				$project_id, $user_id
			) );
			if ( ! $existing ) {
				BizCity_Twin_Mutation_Guard::record( $preflight['mutation'], 'rejected', $preflight['context'] );
				if ( ! empty( $mutation_state['key'] ) && class_exists( 'BizCity_Twin_Mutation_Store' ) ) { BizCity_Twin_Mutation_Store::release( $mutation_state['key'] ); }
				return new \WP_Error( 'not_found', 'Project not found.', [ 'status' => 404 ] );
			}

			$result = $wpdb->update( $table, [
				'title'       => $title ?: ( $config['name'] ?? '' ),
				'site_config' => $config_json,
				'updated_at'  => current_time( 'mysql' ),
			], [ 'id' => $project_id ] );
			if ( $result === false ) {
				BizCity_Twin_Mutation_Guard::record( $preflight['mutation'], 'failed', $preflight['context'] );
				if ( ! empty( $mutation_state['key'] ) && class_exists( 'BizCity_Twin_Mutation_Store' ) ) { BizCity_Twin_Mutation_Store::release( $mutation_state['key'] ); }
				error_log( '[BZPB] UPDATE failed: ' . $wpdb->last_error );
				return new \WP_Error( 'db_error', 'Failed to update project.', [ 'status' => 500 ] );
			}
			BizCity_Twin_Mutation_Guard::record( $preflight['mutation'], 'success', $preflight['context'] );

		} else {
			// Create new
			$result = $wpdb->insert( $table, [
				'user_id'     => $user_id,
				'title'       => $title ?: ( $config['name'] ?? 'Untitled' ),
				'site_config' => $config_json,
				'status'      => 'draft',
				'created_at'  => current_time( 'mysql' ),
				'updated_at'  => current_time( 'mysql' ),
			] );
			if ( $result === false ) {
				BizCity_Twin_Mutation_Guard::record( $preflight['mutation'], 'failed', $preflight['context'] );
				if ( ! empty( $mutation_state['key'] ) && class_exists( 'BizCity_Twin_Mutation_Store' ) ) { BizCity_Twin_Mutation_Store::release( $mutation_state['key'] ); }
				error_log( '[BZPB] INSERT failed: ' . $wpdb->last_error );
				return new \WP_Error( 'db_error', 'Failed to create project.', [ 'status' => 500 ] );
			}
			$project_id = (int) $wpdb->insert_id;
			BizCity_Twin_Mutation_Guard::record( $preflight['mutation'], 'success', $preflight['context'] );
		}

		$response = array(
			'success'    => true,
			'project_id' => $project_id,
			'idempotency_replayed' => false,
		);
		if ( ! empty( $mutation_state['key'] ) && class_exists( 'BizCity_Twin_Mutation_Store' ) ) {
			BizCity_Twin_Mutation_Store::complete( $mutation_state['key'], $request_hash, $response );
		}
		return rest_ensure_response( $response );
	}

	/* ═══════════════════════════════════════════════
	   LIST PROJECTS
	   ═══════════════════════════════════════════════ */
	public static function handle_list_projects( \WP_REST_Request $request ) {
		global $wpdb;
		$table   = $wpdb->prefix . 'bzpb_projects';
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return new \WP_Error( 'auth_error', 'Invalid user session.', [ 'status' => 401 ] );
		}

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, title, status, thumbnail_url, published_page_id, created_at, updated_at
			 FROM {$table} WHERE user_id = %d ORDER BY updated_at DESC LIMIT 50",
			$user_id
		) );

		return rest_ensure_response( [ 'success' => true, 'projects' => $rows ] );
	}

	/* ═══════════════════════════════════════════════
	   GET PROJECT
	   ═══════════════════════════════════════════════ */
	public static function handle_get_project( \WP_REST_Request $request ) {
		global $wpdb;
		$table      = $wpdb->prefix . 'bzpb_projects';
		$user_id    = get_current_user_id();
		if ( $user_id <= 0 ) {
			return new \WP_Error( 'auth_error', 'Invalid user session.', [ 'status' => 401 ] );
		}
		$project_id = absint( $request['id'] );

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE id = %d AND user_id = %d",
			$project_id, $user_id
		) );

		if ( ! $row ) {
			return new \WP_Error( 'not_found', 'Project not found.', [ 'status' => 404 ] );
		}

		$row->site_config = json_decode( $row->site_config, true );

		return rest_ensure_response( [ 'success' => true, 'project' => $row ] );
	}

	/* ═══════════════════════════════════════════════
	   DELETE PROJECT
	   ═══════════════════════════════════════════════ */
	public static function handle_delete_project( \WP_REST_Request $request ) {
		global $wpdb;
		$table      = $wpdb->prefix . 'bzpb_projects';
		$user_id    = get_current_user_id();
		if ( $user_id <= 0 ) {
			return new \WP_Error( 'auth_error', 'Invalid user session.', [ 'status' => 401 ] );
		}
		$project_id = absint( $request['id'] );
		// [2026-07-30 Johnny Chu] PHASE-1.22-RUNTIME — guard project deletion before database side effects.
		$preflight = self::preflight_project_mutation( $request, 'delete', $project_id );
		if ( is_wp_error( $preflight ) ) {
			return $preflight;
		}
		$request_hash = md5( wp_json_encode( array( 'delete', $project_id ) ) );
		$mutation_state = class_exists( 'BizCity_Twin_Mutation_Store' )
			? BizCity_Twin_Mutation_Store::begin( $preflight['mutation'], $preflight['context'], $request_hash )
			: array( 'status' => 'new', 'key' => '' );
		if ( 'conflict' === (string) ( $mutation_state['status'] ?? '' ) ) {
			return self::rest_error( 'idempotency_conflict', 'Mã idempotency đã dùng cho thao tác khác.', 'Tạo mã idempotency mới rồi thử lại.', 'mutation_contract_invalid', 409 );
		}
		if ( 'pending' === (string) ( $mutation_state['status'] ?? '' ) ) {
			return self::rest_error( 'mutation_in_progress', 'Thao tác xóa đang được xử lý.', 'Đợi thao tác hiện tại hoàn tất rồi thử lại.', 'retry_later', 409 );
		}
		if ( 'replay' === (string) ( $mutation_state['status'] ?? '' ) ) {
			$replayed = (array) ( $mutation_state['response'] ?? array() );
			$replayed['idempotency_replayed'] = true;
			return new \WP_REST_Response( $replayed, 200 );
		}

		$deleted = $wpdb->delete( $table, [
			'id'      => $project_id,
			'user_id' => $user_id,
		] );

		if ( $deleted === false ) {
			BizCity_Twin_Mutation_Guard::record( $preflight['mutation'], 'failed', $preflight['context'] );
			if ( ! empty( $mutation_state['key'] ) && class_exists( 'BizCity_Twin_Mutation_Store' ) ) { BizCity_Twin_Mutation_Store::release( $mutation_state['key'] ); }
			error_log( '[BZPB] DELETE failed: ' . $wpdb->last_error );
			return new \WP_Error( 'db_error', 'Failed to delete project.', [ 'status' => 500 ] );
		}
		if ( $deleted === 0 ) {
			BizCity_Twin_Mutation_Guard::record( $preflight['mutation'], 'rejected', $preflight['context'] );
			if ( ! empty( $mutation_state['key'] ) && class_exists( 'BizCity_Twin_Mutation_Store' ) ) { BizCity_Twin_Mutation_Store::release( $mutation_state['key'] ); }
			return new \WP_Error( 'not_found', 'Project not found or already deleted.', [ 'status' => 404 ] );
		}
		BizCity_Twin_Mutation_Guard::record( $preflight['mutation'], 'success', $preflight['context'] );

		$response = array( 'success' => true, 'idempotency_replayed' => false );
		if ( ! empty( $mutation_state['key'] ) && class_exists( 'BizCity_Twin_Mutation_Store' ) ) {
			BizCity_Twin_Mutation_Store::complete( $mutation_state['key'], $request_hash, $response );
		}
		return rest_ensure_response( $response );
	}

	/* ═══════════════════════════════════════════════
	   PUBLISH — Export HTML → WordPress page
	   ═══════════════════════════════════════════════ */
	public static function handle_publish( \WP_REST_Request $request ) {
		global $wpdb;
		$table      = $wpdb->prefix . 'bzpb_projects';
		$user_id    = get_current_user_id();
		if ( $user_id <= 0 ) {
			return new \WP_Error( 'auth_error', 'Invalid user session.', [ 'status' => 401 ] );
		}
		$project_id = absint( $request->get_param( 'project_id' ) );

		if ( ! $project_id ) {
			return new \WP_Error( 'missing_id', 'project_id is required.', [ 'status' => 400 ] );
		}
		// [2026-07-30 Johnny Chu] PHASE-1.22-RUNTIME — require publish approval before page/meta side effects.
		$preflight = self::preflight_project_mutation( $request, 'publish', $project_id );
		if ( is_wp_error( $preflight ) ) {
			return $preflight;
		}

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE id = %d AND user_id = %d",
			$project_id, $user_id
		) );

		if ( ! $row ) {
			BizCity_Twin_Mutation_Guard::record( $preflight['mutation'], 'rejected', $preflight['context'] );
			return new \WP_Error( 'not_found', 'Project not found.', [ 'status' => 404 ] );
		}

		$site_config = json_decode( $row->site_config, true );
		if ( empty( $site_config ) ) {
			BizCity_Twin_Mutation_Guard::record( $preflight['mutation'], 'rejected', $preflight['context'] );
			return new \WP_Error( 'empty_config', 'No site config to publish.', [ 'status' => 400 ] );
		}
		$request_hash = md5( wp_json_encode( array( 'publish', $project_id, $site_config ) ) );
		$mutation_state = class_exists( 'BizCity_Twin_Mutation_Store' )
			? BizCity_Twin_Mutation_Store::begin( $preflight['mutation'], $preflight['context'], $request_hash )
			: array( 'status' => 'new', 'key' => '' );
		if ( 'conflict' === (string) ( $mutation_state['status'] ?? '' ) ) {
			return self::rest_error( 'idempotency_conflict', 'Mã idempotency đã dùng cho dữ liệu publish khác.', 'Tạo mã idempotency mới rồi thử lại.', 'mutation_contract_invalid', 409 );
		}
		if ( 'pending' === (string) ( $mutation_state['status'] ?? '' ) ) {
			return self::rest_error( 'mutation_in_progress', 'Thao tác publish đang được xử lý.', 'Đợi thao tác hiện tại hoàn tất rồi thử lại.', 'retry_later', 409 );
		}
		if ( 'replay' === (string) ( $mutation_state['status'] ?? '' ) ) {
			$replayed = (array) ( $mutation_state['response'] ?? array() );
			$replayed['idempotency_replayed'] = true;
			return new \WP_REST_Response( $replayed, 200 );
		}

		// Do NOT save rendered HTML to post_content — wp_kses_post() strips <style> tags
		// but keeps the CSS text, which then appears as visible text in the page body.
		// The canvas template (loaded via template_redirect) renders fresh from meta instead.
		$page_name = $row->title ?: ( $site_config['name'] ?? 'Page Builder Site' );

		// Upsert WP page
		$page_data = [
			'post_title'   => $page_name,
			'post_content' => '',
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_author'  => $user_id,
		];

		if ( ! empty( $row->published_page_id ) ) {
			$page_data['ID'] = (int) $row->published_page_id;
			$page_id = wp_update_post( $page_data, true );
		} else {
			$page_id = wp_insert_post( $page_data, true );
		}

		if ( is_wp_error( $page_id ) ) {
			BizCity_Twin_Mutation_Guard::record( $preflight['mutation'], 'failed', $preflight['context'] );
			if ( ! empty( $mutation_state['key'] ) && class_exists( 'BizCity_Twin_Mutation_Store' ) ) { BizCity_Twin_Mutation_Store::release( $mutation_state['key'] ); }
			return $page_id;
		}

		// Store SiteConfig in post_meta for re-editing.
		// Validate encoding before saving to prevent silent corruption.
		$meta_json = wp_json_encode( $site_config, JSON_UNESCAPED_UNICODE );
		if ( ! $meta_json ) {
			// Fallback: try without UNESCAPED_UNICODE (escapes non-ASCII, always succeeds)
			$meta_json = wp_json_encode( $site_config );
		}
		if ( ! $meta_json ) {
			BizCity_Twin_Mutation_Guard::record( $preflight['mutation'], 'failed', $preflight['context'] );
			if ( ! empty( $mutation_state['key'] ) && class_exists( 'BizCity_Twin_Mutation_Store' ) ) { BizCity_Twin_Mutation_Store::release( $mutation_state['key'] ); }
			error_log( '[BZPB] CRITICAL: wp_json_encode failed on site_config for page_id=' . $page_id );
			return new \WP_Error( 'encode_error', 'Failed to encode site config.', [ 'status' => 500 ] );
		}
		$decoded_test = json_decode( $meta_json, true );
		if ( ! is_array( $decoded_test ) ) {
			BizCity_Twin_Mutation_Guard::record( $preflight['mutation'], 'failed', $preflight['context'] );
			if ( ! empty( $mutation_state['key'] ) && class_exists( 'BizCity_Twin_Mutation_Store' ) ) { BizCity_Twin_Mutation_Store::release( $mutation_state['key'] ); }
			error_log( '[BZPB] CRITICAL: encode-then-decode roundtrip failed for page_id=' . $page_id
				. ' json_error=' . json_last_error_msg() );
			return new \WP_Error( 'encode_error', 'Site config roundtrip validation failed.', [ 'status' => 500 ] );
		}
		update_post_meta( $page_id, '_bzpb_site_config', $meta_json );
		update_post_meta( $page_id, '_bzpb_project_id', $project_id );

		// Use blank canvas template to prevent theme (Flatsome etc.) from overriding styles
		update_post_meta( $page_id, '_wp_page_template', 'bzpb-canvas' );

		// Update project with published page ID
		$result = $wpdb->update( $table, [
			'published_page_id' => $page_id,
			'status'            => 'published',
			'updated_at'        => current_time( 'mysql' ),
		], [ 'id' => $project_id ] );
		if ( $result === false ) {
			BizCity_Twin_Mutation_Guard::record( $preflight['mutation'], 'failed', $preflight['context'] );
			if ( ! empty( $mutation_state['key'] ) && class_exists( 'BizCity_Twin_Mutation_Store' ) ) { BizCity_Twin_Mutation_Store::release( $mutation_state['key'] ); }
			error_log( '[BZPB] Publish UPDATE failed: ' . $wpdb->last_error );
			return new \WP_Error( 'db_error', 'Page đã tạo nhưng không thể cập nhật trạng thái project.', array(
				'status'    => 500,
				'hint'      => 'Kiểm tra quyền ghi project rồi thử publish lại.',
				'help_code' => 'mutation_persist_failed',
			) );
		}
		BizCity_Twin_Mutation_Guard::record( $preflight['mutation'], 'success', $preflight['context'] );

		$response = array(
			'success'  => true,
			'page_id'  => $page_id,
			'page_url' => get_permalink( $page_id ),
			'idempotency_replayed' => false,
		);
		if ( ! empty( $mutation_state['key'] ) && class_exists( 'BizCity_Twin_Mutation_Store' ) ) {
			BizCity_Twin_Mutation_Store::complete( $mutation_state['key'], $request_hash, $response );
		}
		return rest_ensure_response( $response );
	}

	/* ═══════════════════════════════════════════════
	   DIAGNOSE — Inspect raw DB state for a page (admin)
	   ═══════════════════════════════════════════════ */
	public static function handle_diagnose( \WP_REST_Request $request ) {
		global $wpdb;
		$page_id = absint( $request['page_id'] );
		$table   = $wpdb->prefix . 'bzpb_projects';

		// Raw post meta (direct SQL — bypasses WP caching)
		$meta_raw = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_bzpb_site_config' LIMIT 1",
			$page_id
		) );
		$project_id = (int) get_post_meta( $page_id, '_bzpb_project_id', true );

		// Project row
		$proj_json = '';
		if ( $project_id ) {
			$proj_json = (string) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				"SELECT site_config FROM {$table} WHERE id = %d LIMIT 1",
				$project_id
			) );
		}

		$meta_decoded   = json_decode( (string) $meta_raw, true );
		$proj_decoded   = json_decode( $proj_json, true );

		return rest_ensure_response( [
			'page_id'             => $page_id,
			'project_id'          => $project_id,
			'meta_raw_len'        => strlen( (string) $meta_raw ),
			'meta_first_100'      => substr( (string) $meta_raw, 0, 100 ),
			'meta_json_error'     => json_last_error_msg(),
			'meta_decode_ok'      => is_array( $meta_decoded ),
			'meta_blocks_count'   => is_array( $meta_decoded ) ? count( $meta_decoded['blocks'] ?? [] ) : null,
			'proj_raw_len'        => strlen( $proj_json ),
			'proj_first_100'      => substr( $proj_json, 0, 100 ),
			'proj_decode_ok'      => is_array( $proj_decoded ),
			'proj_blocks_count'   => is_array( $proj_decoded ) ? count( $proj_decoded['blocks'] ?? [] ) : null,
		] );
	}

	/* ═══════════════════════════════════════════════
	   REPAIR — Re-save post meta from bzpb_projects (admin)
	   ═══════════════════════════════════════════════ */
	public static function handle_repair( \WP_REST_Request $request ) {
		global $wpdb;
		$page_id    = absint( $request['page_id'] );
		$table      = $wpdb->prefix . 'bzpb_projects';
		$project_id = (int) get_post_meta( $page_id, '_bzpb_project_id', true );

		if ( ! $project_id ) {
			return new \WP_Error( 'no_project', 'No _bzpb_project_id meta on this page.', [ 'status' => 404 ] );
		}

		$proj_json = (string) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT site_config FROM {$table} WHERE id = %d LIMIT 1",
			$project_id
		) );

		if ( empty( $proj_json ) ) {
			return new \WP_Error( 'no_project_data', 'Project row not found or site_config empty.', [ 'status' => 404 ] );
		}

		$config = json_decode( $proj_json, true );
		if ( ! is_array( $config ) ) {
			return new \WP_Error( 'bad_proj_json', 'Project site_config is also invalid JSON: ' . json_last_error_msg(), [ 'status' => 500 ] );
		}

		$new_meta = wp_json_encode( $config, JSON_UNESCAPED_UNICODE );
		if ( ! $new_meta ) {
			$new_meta = wp_json_encode( $config );
		}

		update_post_meta( $page_id, '_bzpb_site_config', $new_meta );

		return rest_ensure_response( [
			'success'     => true,
			'page_id'     => $page_id,
			'project_id'  => $project_id,
			'new_meta_len' => strlen( (string) $new_meta ),
			'blocks'      => count( $config['blocks'] ?? [] ),
		] );
	}

	/* ═══════════════════════════════════════════════
	   EDIT — Modify existing project via AI instruction
	   ═══════════════════════════════════════════════ */
	public static function handle_edit( \WP_REST_Request $request ) {
		global $wpdb;
		$table      = $wpdb->prefix . 'bzpb_projects';
		$user_id    = get_current_user_id();
		if ( $user_id <= 0 ) {
			return new \WP_Error( 'auth_error', 'Invalid user session.', [ 'status' => 401 ] );
		}
		$project_id = absint( $request->get_param( 'project_id' ) );
		$instruction = sanitize_textarea_field( $request->get_param( 'instruction' ) );

		if ( ! $project_id || empty( $instruction ) ) {
			return new \WP_Error( 'missing_params', 'project_id and instruction are required.', [ 'status' => 400 ] );
		}

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE id = %d AND user_id = %d",
			$project_id, $user_id
		) );

		if ( ! $row ) {
			return new \WP_Error( 'not_found', 'Project not found.', [ 'status' => 404 ] );
		}

		$current_config = json_decode( $row->site_config, true );
		$config_json    = wp_json_encode( $current_config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );

		$system_prompt = self::get_system_prompt();
		$user_prompt   = "Here is the CURRENT website JSON config:\n\n```json\n{$config_json}\n```\n\n"
			. "USER INSTRUCTION: {$instruction}\n\n"
			. "Apply the instruction above to modify the config. Return the COMPLETE updated JSON SiteConfig. "
			. "Keep all existing blocks/content that the user didn't ask to change. "
			. "Respond with a complete JSON SiteConfig object ONLY. No markdown, no explanation.";

		$ai_response = self::call_llm( $system_prompt, $user_prompt );

		if ( is_wp_error( $ai_response ) ) {
			return $ai_response;
		}

		$updated_config = self::parse_ai_json( $ai_response );
		if ( is_wp_error( $updated_config ) ) {
			return $updated_config;
		}

		$updated_config = self::validate_blocks( $updated_config );

		// Save updated config
		$config_json_save = wp_json_encode( $updated_config, JSON_UNESCAPED_UNICODE );
		$save_result = $wpdb->update( $table, [
			'site_config' => $config_json_save,
			'updated_at'  => current_time( 'mysql' ),
		], [ 'id' => $project_id ] );

		if ( $save_result === false ) {
			error_log( '[BZPB] Edit UPDATE failed: ' . $wpdb->last_error );
			return new \WP_Error( 'db_error', 'Failed to save edited config.', [ 'status' => 500 ] );
		}

		// Auto-snapshot this edit
		self::log_generation_completed( $project_id, $user_id, 'edit', $instruction, $updated_config, 'anthropic/claude-sonnet-4' );

		return rest_ensure_response( [
			'success'    => true,
			'project_id' => $project_id,
			'config'     => $updated_config,
		] );
	}

	/* ═══════════════════════════════════════════════
	   LLM CALL — bizcity_llm_chat_stream() bridge
	   Accumulates full response (no SSE to browser).
	   ═══════════════════════════════════════════════ */
	private static function call_llm( string $system_prompt, string $user_prompt, int $max_tokens = 16000 ) {
		$messages = [
			[ 'role' => 'system', 'content' => $system_prompt ],
			[ 'role' => 'user',   'content' => $user_prompt ],
		];

		$llm_opts = [
			'model'       => 'anthropic/claude-sonnet-4',
			'purpose'     => 'executor',
			'temperature' => 0.6,
			'max_tokens'  => $max_tokens,
			'timeout'     => 180,
		];

		if ( function_exists( 'bizcity_llm_chat_stream' ) ) {
			$full   = '';
			$result = bizcity_llm_chat_stream( $messages, $llm_opts,
				function ( $delta, $full_so_far ) use ( &$full ) {
					$full = $full_so_far;
				}
			);

			if ( ! empty( $result['success'] ) ) {
				$response = ! empty( $result['message'] ) ? $result['message'] : $full;
				return $response;
			}

			error_log( '[BZPB] LLM stream failed: ' . ( $result['error'] ?? 'unknown' ) );
			return new \WP_Error( 'llm_error', $result['error'] ?? 'LLM stream failed', [ 'status' => 502 ] );
		}

		if ( function_exists( 'bizcity_llm_chat' ) ) {
			$result = bizcity_llm_chat( $messages, $llm_opts );
			if ( ! empty( $result['success'] ) ) {
				return $result['message'] ?? '';
			}
			return new \WP_Error( 'llm_error', $result['error'] ?? 'LLM call failed', [ 'status' => 502 ] );
		}

		return new \WP_Error( 'llm_unavailable', 'LLM router not available. Please ensure BizCity Twin AI core is active.', [ 'status' => 503 ] );
	}

	/* ═══════════════════════════════════════════════
	   LLM VISION CALL — image + text user message
	   ═══════════════════════════════════════════════ */
	private static function call_llm_vision( string $system_prompt, string $user_text, string $image_data_url, int $max_tokens = 16000 ) {
		// Build multipart user content (Claude vision format)
		$user_content = [
			[
				'type'      => 'image',
				'source'    => [
					'type'       => 'base64',
					'media_type' => self::extract_mime_type( $image_data_url ),
					'data'       => self::extract_base64_data( $image_data_url ),
				],
			],
			[
				'type' => 'text',
				'text' => $user_text,
			],
		];

		$messages = [
			[ 'role' => 'system',  'content' => $system_prompt ],
			[ 'role' => 'user',    'content' => $user_content  ],
		];

		$llm_opts = [
			'model'       => 'anthropic/claude-sonnet-4',
			'purpose'     => 'executor',
			'temperature' => 0.5,
			'max_tokens'  => $max_tokens,
			'timeout'     => 180,
		];

		if ( function_exists( 'bizcity_llm_chat_stream' ) ) {
			$full   = '';
			$result = bizcity_llm_chat_stream( $messages, $llm_opts,
				function ( $delta, $full_so_far ) use ( &$full ) {
					$full = $full_so_far;
				}
			);
			if ( ! empty( $result['success'] ) ) {
				return ! empty( $result['message'] ) ? $result['message'] : $full;
			}
			return new \WP_Error( 'llm_error', $result['error'] ?? 'LLM vision stream failed', [ 'status' => 502 ] );
		}

		if ( function_exists( 'bizcity_llm_chat' ) ) {
			$result = bizcity_llm_chat( $messages, $llm_opts );
			if ( ! empty( $result['success'] ) ) {
				return $result['message'] ?? '';
			}
			return new \WP_Error( 'llm_error', $result['error'] ?? 'LLM vision call failed', [ 'status' => 502 ] );
		}

		return new \WP_Error( 'llm_unavailable', 'LLM router not available.', [ 'status' => 503 ] );
	}

	private static function extract_mime_type( string $data_url ): string {
		preg_match( '/^data:(image\/[a-zA-Z+]+);base64,/', $data_url, $m );
		return $m[1] ?? 'image/jpeg';
	}

	private static function extract_base64_data( string $data_url ): string {
		$pos = strpos( $data_url, ',', 0 );
		return $pos !== false ? substr( $data_url, $pos + 1 ) : $data_url;
	}

	/* ═══════════════════════════════════════════════
	   PARSE AI JSON — strip markdown fences, validate
	   ═══════════════════════════════════════════════ */
	private static function parse_ai_json( string $raw ) {
		// Strip markdown code fences
		$json = preg_replace( '/^```(?:json)?\s*/i', '', trim( $raw ) );
		$json = preg_replace( '/\s*```\s*$/', '', $json );

		$data = json_decode( $json, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			error_log( '[BZPB] JSON parse error: ' . json_last_error_msg() . "\nRaw: " . substr( $raw, 0, 500 ) );
			return new \WP_Error( 'json_error', 'AI returned invalid JSON: ' . json_last_error_msg(), [ 'status' => 422 ] );
		}

		// Validate minimum structure
		if ( ! isset( $data['blocks'] ) || ! is_array( $data['blocks'] ) ) {
			return new \WP_Error( 'invalid_config', 'AI response missing blocks array.', [ 'status' => 422 ] );
		}

		return $data;
	}

	/**
	 * Validate block types — strip unknown types, ensure unique IDs.
	 */
	private static function validate_blocks( array $config ): array {
		$valid_types = [
			'navbar', 'hero', 'features', 'pricing', 'cta', 'footer',
			'testimonials', 'stats', 'faq', 'team', 'contact', 'newsletter',
			'logocloud', 'content', 'image', 'video', 'gallery', 'divider', 'banner',
			'custom-html',  // Hybrid mode: raw HTML hero section
		];
		$seen_ids = [];
		$clean_blocks = [];

		foreach ( $config['blocks'] as $block ) {
			$type = $block['type'] ?? '';
			if ( ! in_array( $type, $valid_types, true ) ) {
				continue; // Skip unknown block types
			}
			// Ensure unique ID
			$id = $block['id'] ?? $type . '-' . ( count( $clean_blocks ) + 1 );
			if ( isset( $seen_ids[ $id ] ) ) {
				$id .= '-' . wp_rand( 100, 999 );
			}
			$seen_ids[ $id ] = true;
			$block['id'] = $id;
			$block['variant'] = $block['variant'] ?? 'default';
			$block['props']   = $block['props'] ?? [];
			$clean_blocks[] = $block;
		}

		$config['blocks'] = $clean_blocks;

		// Ensure name and theme exist
		if ( empty( $config['name'] ) ) {
			$config['name'] = 'Untitled Website';
		}
		if ( empty( $config['theme'] ) || ! is_array( $config['theme'] ) ) {
			$config['theme'] = [
				'bg0' => '#ffffff', 'bg1' => '#f4f4f5', 'text0' => '#09090b',
				'text1' => '#71717a', 'accent' => '#2563eb', 'border' => '#e4e4e7',
				'fontSans' => 'Inter', 'fontDisplay' => 'Inter', 'fontMono' => 'JetBrains Mono', 'radius' => 8,
			];
		}

		return $config;
	}

	/* ═══════════════════════════════════════════════
	   SAVE PROJECT HELPER
	   ═══════════════════════════════════════════════ */
	private static function save_project_to_db( int $user_id, string $title, array $config ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'bzpb_projects';

		$result = $wpdb->insert( $table, [
			'user_id'     => $user_id,
			'title'       => $title,
			'site_config' => wp_json_encode( $config, JSON_UNESCAPED_UNICODE ),
			'status'      => 'draft',
			'created_at'  => current_time( 'mysql' ),
			'updated_at'  => current_time( 'mysql' ),
		] );
		if ( $result === false ) {
			error_log( '[BZPB] save_project_to_db INSERT failed: ' . $wpdb->last_error );
			return 0;
		}

		return (int) $wpdb->insert_id;
	}

	/* ═══════════════════════════════════════════════════════════════
	   SYSTEM PROMPT — Full block schema for AI generation
	   Ported from OpenPage /api/generate.ts system prompt
	   ═══════════════════════════════════════════════════════════════ */
	public static function get_system_prompt(): string {
		return <<<'PROMPT'
You are a professional website architect. You create modern, visually stunning websites by outputting STRICT JSON.

You MUST write content in THE SAME LANGUAGE as the user's prompt. If the prompt is in Vietnamese, write entirely in Vietnamese. If in English, write in English.

## Output Format

Return a single JSON object with this exact structure:

```
{
  "name": "Site Name",
  "theme": { ThemeConfig },
  "blocks": [ BlockConfig, BlockConfig, ... ]
}
```

## ThemeConfig

```
{
  "bg0": "#hex",       // Primary background
  "bg1": "#hex",       // Secondary/card background
  "text0": "#hex",     // Primary text color
  "text1": "#hex",     // Secondary/muted text
  "accent": "#hex",    // Accent/CTA color
  "border": "#hex",    // Border color
  "fontSans": "Inter", // Body font (Google Fonts)
  "fontDisplay": "Space Grotesk", // Heading font
  "fontMono": "JetBrains Mono",   // Code font
  "radius": 8          // Border radius in px
}
```

### Theme Presets (pick ONE or create custom):

- **dark-minimal**: bg0=#09090b, bg1=#18181b, text0=#fafafa, text1=#a1a1aa, accent=#22c55e, border=#27272a, fontSans=Inter, fontDisplay=Space Grotesk, radius=8
- **ivory**: bg0=#fffbeb, bg1=#ffffff, text0=#1c1917, text1=#78716c, accent=#f59e0b, border=#e7e5e4, fontSans=Lora, fontDisplay=Playfair Display, radius=12
- **clean**: bg0=#ffffff, bg1=#f4f4f5, text0=#09090b, text1=#71717a, accent=#2563eb, border=#e4e4e7, fontSans=Inter, fontDisplay=Inter, radius=8
- **sand**: bg0=#faf7f2, bg1=#f5f0e8, text0=#292524, text1=#78716c, accent=#b45309, border=#e7e5e4, fontSans=DM Sans, fontDisplay=Fraunces, radius=6
- **amber**: bg0=#fffbeb, bg1=#fef3c7, text0=#1c1917, text1=#78716c, accent=#d97706, border=#fde68a, fontSans=Inter, fontDisplay=Sora, radius=10
- **ocean**: bg0=#0c1222, bg1=#162032, text0=#e2e8f0, text1=#94a3b8, accent=#38bdf8, border=#1e293b, fontSans=Inter, fontDisplay=Plus Jakarta Sans, radius=8
- **rose**: bg0=#fff1f2, bg1=#ffe4e6, text0=#1c1917, text1=#78716c, accent=#e11d48, border=#fecdd3, fontSans=Inter, fontDisplay=Outfit, radius=12
- **purple-haze**: bg0=#0a0118, bg1=#1a0a2e, text0=#e9d5ff, text1=#a78bfa, accent=#a855f7, border=#2e1065, fontSans=Inter, fontDisplay=Space Grotesk, radius=8
- **slate**: bg0=#0f172a, bg1=#1e293b, text0=#f1f5f9, text1=#94a3b8, accent=#6366f1, border=#334155, fontSans=Inter, fontDisplay=Plus Jakarta Sans, radius=6
- **forest**: bg0=#052e16, bg1=#14532d, text0=#ecfdf5, text1=#86efac, accent=#22c55e, border=#166534, fontSans=Inter, fontDisplay=Space Grotesk, radius=8

## Block Types (19 types)

Each block has: id (unique string), type, variant, and props.

### 1. navbar
Variants: "default", "centered"
Props: { logo: string, links: string[], ctaText: string }

### 2. hero
Variants: "centered", "split", "gradient", "minimal"
Props: { badge?: string, headline: string, subheadline: string, primaryCta: string, secondaryCta?: string, image?: string }

### 3. features
Variants: "grid", "list", "alternating"
Props: { title: string, subtitle?: string, items: [{ icon: string, title: string, description: string }] }
Icons: Use Lucide icon names (Zap, Shield, Bot, Globe, Sparkles, Code, Heart, Star, etc.)

### 4. pricing
Variants: "simple", "comparison"
Props: { title: string, subtitle?: string, tiers: [{ name: string, price: string, period: string, description: string, features: string[], cta: string, featured?: boolean }] }

### 5. cta
Variants: "simple", "split"
Props: { title: string, description: string, primaryCta: string, secondaryCta?: string }

### 6. footer
Variants: "simple", "multi-column", "minimal"
Props: { logo?: string, copyright: string, columns?: [{ title: string, links: string[] }], socialLinks?: string[] }

### 7. testimonials
Variants: "cards", "carousel", "spotlight"
Props: { title?: string, items: [{ quote: string, author: string, role?: string, company?: string, rating?: number, avatar?: string }] }

### 8. stats
Variants: "grid", "bar", "counter"
Props: { title?: string, items: [{ value: string, label: string, suffix?: string }] }

### 9. faq
Variants: "accordion"
Props: { title?: string, items: [{ question: string, answer: string }] }

### 10. team
Variants: "grid"
Props: { title?: string, subtitle?: string, members: [{ name: string, role: string, bio?: string, avatar?: string }] }

### 11. contact
Variants: "form"
Props: { title: string, subtitle?: string, fields: string[], submitText: string }

### 12. newsletter
Variants: "simple"
Props: { title: string, subtitle?: string, placeholder: string, buttonText: string }

### 13. logocloud
Variants: "default"
Props: { title?: string, logos: string[] }

### 14. content
Variants: "prose", "columns", "highlight"
Props: { title?: string, body: string, columns?: number }
Body supports Markdown.

### 15. image
Variants: "hero-image", "side-by-side", "grid"
Props: { src: string, alt: string, caption?: string, images?: [{ src: string, alt: string }] }

### 16. video
Variants: "youtube", "vimeo"
Props: { url: string, title?: string }

### 17. gallery
Variants: "grid", "masonry"
Props: { title?: string, images: [{ src: string, alt: string, caption?: string }] }

### 18. divider
Variants: "line", "space", "dots"
Props: { height?: number }

### 19. banner
Variants: "ribbon", "bar"
Props: { text: string, ctaText?: string, ctaUrl?: string, dismissible?: boolean }

## Rules

1. ALWAYS start with a "navbar" block and end with a "footer" block.
2. Use 5-12 blocks total for a complete website.
3. Generate REAL, contextual content — no placeholder text like "Lorem ipsum".
4. Each block id MUST be unique (e.g., "hero-1", "features-1", "pricing-1").
5. Choose appropriate variants based on content and layout needs.
6. Use a consistent theme that matches the website's purpose.
7. For Vietnamese content, use fonts that support Vietnamese characters well: "Be Vietnam Pro", "Inter", "Nunito", "Open Sans".
8. Output ONLY the JSON object. No markdown fences, no explanation, no commentary.
PROMPT;
	}

	/* ═══════════════════════════════════════════════
	   VERSION HISTORY — List generations for project
	   ═══════════════════════════════════════════════ */
	public static function handle_list_generations( \WP_REST_Request $request ) {
		global $wpdb;
		$user_id    = get_current_user_id();
		$project_id = absint( $request['project_id'] );

		// Verify user owns the project
		$projects_table = $wpdb->prefix . 'bzpb_projects';
		$exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$projects_table} WHERE id = %d AND user_id = %d",
			$project_id, $user_id
		) );
		if ( ! $exists ) {
			return new \WP_Error( 'not_found', 'Project not found.', [ 'status' => 404 ] );
		}

		$gen_table = $wpdb->prefix . 'bzpb_generations';
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, project_id, user_id, action, status, prompt, model, tokens_used, duration_ms, error_message, created_at, completed_at
			 FROM {$gen_table}
			 WHERE project_id = %d AND status = 'completed'
			 ORDER BY created_at DESC LIMIT 50",
			$project_id
		) );

		// Truncate prompt for list view (save bandwidth)
		foreach ( $rows as $row ) {
			$row->prompt_preview = mb_substr( $row->prompt, 0, 120 );
		}

		return rest_ensure_response( [ 'success' => true, 'generations' => $rows ] );
	}

	/* ═══════════════════════════════════════════════
	   VERSION HISTORY — Get single generation (with snapshot)
	   ═══════════════════════════════════════════════ */
	public static function handle_get_generation( \WP_REST_Request $request ) {
		global $wpdb;
		$user_id = get_current_user_id();
		$gen_id  = absint( $request['id'] );

		$gen_table = $wpdb->prefix . 'bzpb_generations';
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$gen_table} WHERE id = %d AND user_id = %d",
			$gen_id, $user_id
		) );

		if ( ! $row ) {
			return new \WP_Error( 'not_found', 'Generation not found.', [ 'status' => 404 ] );
		}

		$row->config_snapshot = json_decode( $row->config_snapshot, true );

		return rest_ensure_response( [ 'success' => true, 'generation' => $row ] );
	}

	/* ═══════════════════════════════════════════════
	   VERSION HISTORY — Restore a generation
	   ═══════════════════════════════════════════════ */
	public static function handle_restore_generation( \WP_REST_Request $request ) {
		global $wpdb;
		$user_id = get_current_user_id();
		$gen_id  = absint( $request->get_param( 'generation_id' ) );

		if ( ! $gen_id ) {
			return new \WP_Error( 'missing_params', 'generation_id is required.', [ 'status' => 400 ] );
		}

		$gen_table      = $wpdb->prefix . 'bzpb_generations';
		$projects_table = $wpdb->prefix . 'bzpb_projects';

		$gen = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$gen_table} WHERE id = %d AND user_id = %d",
			$gen_id, $user_id
		) );

		if ( ! $gen ) {
			return new \WP_Error( 'not_found', 'Generation not found.', [ 'status' => 404 ] );
		}

		$config = json_decode( $gen->config_snapshot, true );
		if ( empty( $config ) || ! is_array( $config ) ) {
			return new \WP_Error( 'invalid_snapshot', 'Generation snapshot is invalid.', [ 'status' => 422 ] );
		}

		// Write snapshot back to project
		$config_json = wp_json_encode( $config, JSON_UNESCAPED_UNICODE );
		$result = $wpdb->update( $projects_table, [
			'site_config' => $config_json,
			'updated_at'  => current_time( 'mysql' ),
		], [ 'id' => $gen->project_id, 'user_id' => $user_id ] );

		if ( $result === false ) {
			return new \WP_Error( 'db_error', 'Failed to restore project.', [ 'status' => 500 ] );
		}

		// Log the restore action itself as a new generation entry
		$restore_label = sprintf( 'Khôi phục về phiên bản #%d', $gen_id );
		self::log_generation_completed( $gen->project_id, $user_id, 'restore', $restore_label, $config, '' );

		return rest_ensure_response( [
			'success'    => true,
			'project_id' => (int) $gen->project_id,
			'config'     => $config,
		] );
	}

	/* ═══════════════════════════════════════════════
	   GENERATION HELPERS — Auto-snapshot on AI calls
	   ═══════════════════════════════════════════════ */

	/**
	 * Log a completed generation (auto-snapshot).
	 * Call after successful AI generate / edit / restore / manual save.
	 */
	public static function log_generation_completed(
		int $project_id,
		int $user_id,
		string $action,
		string $prompt,
		array $config,
		string $model,
		int $tokens_used = 0,
		int $duration_ms = 0
	): int {
		global $wpdb;
		$table = $wpdb->prefix . 'bzpb_generations';

		$result = $wpdb->insert( $table, [
			'project_id'      => $project_id,
			'user_id'         => $user_id,
			'action'          => $action,
			'status'          => 'completed',
			'prompt'          => mb_substr( $prompt, 0, 2000 ),
			'model'           => $model,
			'tokens_used'     => $tokens_used,
			'duration_ms'     => $duration_ms,
			'config_snapshot' => wp_json_encode( $config, JSON_UNESCAPED_UNICODE ),
			'error_message'   => '',
			'created_at'      => current_time( 'mysql' ),
			'completed_at'    => current_time( 'mysql' ),
		] );

		if ( $result === false ) {
			error_log( '[BZPB] log_generation_completed INSERT failed: ' . $wpdb->last_error );
			return 0;
		}

		return (int) $wpdb->insert_id;
	}

	/* ═══════════════════════════════════════════════
	   INLINE EDIT — Save contenteditable changes
	   ═══════════════════════════════════════════════ */
	public static function handle_save_inline_edits() {
		check_ajax_referer( 'bzpb-inline-edit', 'nonce' );

		// Validate user exists before checking capability
		$user_id = get_current_user_id();
		if ( $user_id <= 0 || ! get_userdata( $user_id ) || ! current_user_can( 'manage_options' ) ) {
			// [2026-07-30 Johnny Chu] R-ERROR-UX — return the canonical actionable error envelope for inline-edit authorization.
			wp_send_json_error( BizCity_Error_Payload::make( 'permission_denied', 'Bạn không có quyền sửa nội dung trang.', 'Đăng nhập bằng tài khoản quản trị rồi thử lại.', 'permission_required' ) );
		}

		$page_id = isset( $_POST['page_id'] ) ? intval( $_POST['page_id'] ) : 0;
		$changes_json = isset( $_POST['changes'] ) ? wp_unslash( $_POST['changes'] ) : '';
		
		if ( ! $page_id || empty( $changes_json ) ) {
			// [2026-07-30 Johnny Chu] R-ERROR-UX — normalize missing inline-edit input for REST/AJAX consumers.
			wp_send_json_error( BizCity_Error_Payload::make( 'invalid_param', 'Thiếu trang hoặc nội dung cần cập nhật.', 'Chọn trang và nhập thay đổi trước khi lưu.', 'invalid_param_generic' ) );
		}

		$changes = json_decode( $changes_json, true );
		if ( ! $changes ) {
			// [2026-07-30 Johnny Chu] R-ERROR-UX — expose a user-safe validation action instead of a raw JSON error.
			wp_send_json_error( BizCity_Error_Payload::make( 'invalid_param', 'Dữ liệu chỉnh sửa không hợp lệ.', 'Tải lại trang rồi nhập lại nội dung cần sửa.', 'invalid_param_generic' ) );
		}

		// Get current post content
		$post = get_post( $page_id );
		if ( ! $post ) {
			// [2026-07-30 Johnny Chu] R-ERROR-UX — normalize missing PageBuilder target errors.
			wp_send_json_error( BizCity_Error_Payload::make( 'not_found', 'Không tìm thấy trang cần sửa.', 'Chọn lại trang rồi thử lưu lần nữa.', 'not_found' ) );
		}

		$content = $post->post_content;

		// Apply changes
		foreach ( $changes as $change ) {
			$old = $change['oldContent'];
			$new = $change['newContent'];
			
			// Simple string replace (could be enhanced with DOMDocument for more accuracy)
			$content = str_replace( $old, $new, $content );
		}

		// Update post
		$result = wp_update_post( [
			'ID'           => $page_id,
			'post_content' => $content,
		] );

		if ( is_wp_error( $result ) ) {
			// [2026-07-30 Johnny Chu] R-ERROR-UX — keep database details out of the user payload.
			wp_send_json_error( BizCity_Error_Payload::from_wp_error( $result, 'Kiểm tra quyền chỉnh sửa rồi thử lại.', 'content_save_failed' ) );
		}

		// Also update SiteConfig in post_meta if exists
		$project_id = get_post_meta( $page_id, '_bzpb_project_id', true );
		if ( $project_id ) {
			global $wpdb;
			$table = $wpdb->prefix . 'bzpb_projects';
			
			// Get current config
			$project = $wpdb->get_row( $wpdb->prepare(
				"SELECT site_config FROM {$table} WHERE id = %d",
				$project_id
			) );

			if ( $project ) {
				$config = json_decode( $project->site_config, true );
				
				// Try to update block props (best-effort — may not map perfectly)
				// This is complex, so we'll keep it simple for now
				// Just regenerate HTML from existing config
				
				// Update updated_at in DB
				$wpdb->update(
					$table,
					[ 'updated_at' => current_time( 'mysql' ) ],
					[ 'id' => $project_id ],
					[ '%s' ],
					[ '%d' ]
				);
			}
		}

		wp_send_json_success( [ 'message' => 'Saved successfully' ] );
	}

	/* ═══════════════════════════════════════════════
	   UPLOAD IMAGE — multipart/form-data → WP Media
	   Returns: { success, url, attachment_id }
	   ═══════════════════════════════════════════════ */
	public static function handle_upload_image( \WP_REST_Request $request ) {
		// Validate file was sent
		$files = $request->get_file_params();
		if ( empty( $files['file'] ) ) {
			// [2026-08-10 Johnny Chu] PHASE-1.24-PAGEBUILDER — map missing REST upload to error envelope.
			return self::rest_error( 'invalid_param', 'Chưa nhận được file ảnh.', 'Chọn file ảnh rồi thử lại.', 'pagebuilder_upload_required' );
		}

		$file = $files['file'];

		// Check MIME type — only accept images
		$allowed_types = [ 'image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/svg+xml' ];
		$finfo         = new finfo( FILEINFO_MIME_TYPE );
		$detected_type = $finfo->file( $file['tmp_name'] );
		if ( ! in_array( $detected_type, $allowed_types, true ) ) {
			// [2026-08-10 Johnny Chu] PHASE-1.24-PAGEBUILDER — map MIME rejection to error envelope.
			return self::rest_error( 'invalid_param', 'File tải lên không phải ảnh hợp lệ.', 'Chọn PNG, JPEG, GIF hoặc WebP rồi thử lại.', 'pagebuilder_upload_format' );
		}

		// File size limit: 5MB
		if ( $file['size'] > 5 * 1024 * 1024 ) {
			// [2026-08-10 Johnny Chu] PHASE-1.24-PAGEBUILDER — map upload size rejection to error envelope.
			return self::rest_error( 'invalid_param', 'Ảnh tải lên vượt quá giới hạn 5 MB.', 'Giảm kích thước file rồi thử lại.', 'pagebuilder_upload_size' );
		}

		// Use WordPress media_handle_upload to save to Media Library
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		// Override _FILES so media_handle_upload can find it
		$_FILES['bzpb_upload'] = $file;

		$attachment_id = media_handle_upload( 'bzpb_upload', 0 );

		// Cleanup our override
		unset( $_FILES['bzpb_upload'] );

		if ( is_wp_error( $attachment_id ) ) {
			// [2026-08-10 Johnny Chu] PHASE-1.24-PAGEBUILDER — hide media errors behind a safe REST envelope.
			return self::rest_error( 'upload_rejected', 'Không thể lưu ảnh vào thư viện media.', 'Kiểm tra quyền upload rồi thử lại.', 'pagebuilder_upload_failed', 500 );
		}

		$url = wp_get_attachment_url( $attachment_id );

		return rest_ensure_response( [
			'success'       => true,
			'url'           => $url,
			'attachment_id' => $attachment_id,
		] );
	}

	/* ═══════════════════════════════════════════════
	   AJAX UPLOAD IMAGE — admin-ajax.php version
	   Same pattern as bizcity-tool-image ajax_upload_image.
	   action=bzpb_upload_image, nonce verified against 'bzpb_nonce', file key='file'
	   ═══════════════════════════════════════════════ */
	public static function ajax_upload_image() {
		check_ajax_referer( 'bzpb_nonce', 'nonce' );

		if ( empty( $_FILES['file'] ) ) {
			// [2026-08-10 Johnny Chu] PHASE-1.24-PAGEBUILDER — map missing AJAX upload to error envelope.
			wp_send_json_error( self::error_payload( 'invalid_param', 'Chưa nhận được file ảnh.', 'Chọn file ảnh rồi thử lại.', 'pagebuilder_upload_required' ) );
		}

		// Validate MIME type from actual file content (not spoofable header)
		$allowed_types = [ 'image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/svg+xml' ];
		$finfo         = new finfo( FILEINFO_MIME_TYPE );
		$detected_type = $finfo->file( $_FILES['file']['tmp_name'] );
		if ( ! in_array( $detected_type, $allowed_types, true ) ) {
			// [2026-08-10 Johnny Chu] PHASE-1.24-PAGEBUILDER — map AJAX MIME rejection to error envelope.
			wp_send_json_error( self::error_payload( 'invalid_param', 'File tải lên không phải ảnh hợp lệ.', 'Chọn PNG, JPEG, GIF hoặc WebP rồi thử lại.', 'pagebuilder_upload_format' ) );
		}

		if ( $_FILES['file']['size'] > 5 * 1024 * 1024 ) {
			// [2026-08-10 Johnny Chu] PHASE-1.24-PAGEBUILDER — map AJAX upload size rejection to error envelope.
			wp_send_json_error( self::error_payload( 'invalid_param', 'Ảnh tải lên vượt quá giới hạn 5 MB.', 'Giảm kích thước file rồi thử lại.', 'pagebuilder_upload_size' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$attachment_id = media_handle_upload( 'file', 0 );

		if ( is_wp_error( $attachment_id ) ) {
			// [2026-08-10 Johnny Chu] PHASE-1.24-PAGEBUILDER — hide media errors behind a safe AJAX envelope.
			wp_send_json_error( self::error_payload( 'upload_rejected', 'Không thể lưu ảnh vào thư viện media.', 'Kiểm tra quyền upload rồi thử lại.', 'pagebuilder_upload_failed' ) );
		}

		$url = wp_get_attachment_url( $attachment_id );

		wp_send_json_success( [
			'url'           => $url,
			'attachment_id' => $attachment_id,
		] );
	}

	/* ═══════════════════════════════════════════════
	   CREATE CF7 FORM — [2026-06-27 Johnny Chu] PHASE-PB-LEADFORM
	   POST /bzpb/v1/create-cf7-form
	   Body: { project_id, block_id, title, fields[] }
	   Response: { cf7_form_id, shortcode }
	   ═══════════════════════════════════════════════ */
	public static function handle_create_cf7_form( \WP_REST_Request $request ) {
		// [2026-06-27 Johnny Chu] PHASE-PB-LEADFORM — guard: CF7 must be active
		if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
			return new \WP_Error(
				'cf7_not_active',
				'Contact Form 7 plugin chưa được kích hoạt trên site này.',
				[ 'status' => 400 ]
			);
		}

		$project_id    = (int) $request->get_param( 'project_id' );
		$block_id      = sanitize_text_field( $request->get_param( 'block_id' ) );
		$title         = sanitize_text_field( $request->get_param( 'title' ) );
		$fields_raw    = $request->get_param( 'fields' );
		// [2026-06-27 Johnny Chu] PHASE-PB-LEADFORM Wave 4 — tracking config from Settings tab
		$fb_pixel_id   = sanitize_text_field( $request->get_param( 'fb_pixel_id' ) ?: '' );
		$ga_id         = sanitize_text_field( $request->get_param( 'ga_id' ) ?: '' );
		$gtm_id        = sanitize_text_field( $request->get_param( 'gtm_id' ) ?: '' );
		$tracking_event = sanitize_key( $request->get_param( 'tracking_event' ) ?: 'lead_submit' );
		$page_path     = sanitize_text_field( $request->get_param( 'page_path' ) ?: '' );

		if ( empty( $title ) ) {
			$title = 'Lead Form';
		}
		$fields = is_array( $fields_raw ) ? $fields_raw : [];

		// [2026-07-02 Johnny Chu] PHASE-PB-LEADFORM — DEDUP: check if a CF7 form was
		// already created for this project+block combination. Re-use it instead of
		// creating a duplicate every time Publish is clicked.
		$dedup_key = 'bzpb_p' . $project_id . '_b' . sanitize_key( $block_id );
		$existing_id = 0;
		if ( $project_id > 0 && $block_id ) {
			$found = get_posts( array(
				'post_type'      => 'wpcf7_contact_form',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'meta_key'       => '_bzpb_block_id',
				'meta_value'     => $dedup_key,
				'fields'         => 'ids',
			) );
			if ( ! empty( $found ) ) {
				$existing_id = (int) $found[0];
			}
		}

		if ( $existing_id > 0 ) {
			// Already exists — return existing form ID (idempotent)
			$shortcode = sprintf( '[contact-form-7 id="%d" title="%s"]', $existing_id, esc_attr( $title ) );
			if ( $project_id > 0 && $block_id ) {
				self::patch_block_props( $project_id, $block_id, array(
					'cf7FormId'    => $existing_id,
					'cf7ShortCode' => $shortcode,
				) );
			}
			return rest_ensure_response( array(
				'success'     => true,
				'cf7_form_id' => $existing_id,
				'shortcode'   => $shortcode,
				'reused'      => true,
			) );
		}

		// [2026-08-21 Johnny Chu] PHASE-PB-TRACKING Wave 6.1 item 2 — atomic lock closes the
		// race window between the "check existing" read above and the "create" write below
		// when two Publish requests fire for the same project+block at the same time.
		// add_option() is atomic at the DB level (unique key on wp_options.option_name),
		// so the first concurrent caller wins the lock; others get false immediately.
		$lock_name    = '';
		$lock_acquired = true;
		if ( $project_id > 0 && $block_id ) {
			$lock_name     = 'bzpb_cf7_lock_' . md5( $dedup_key );
			$lock_acquired = self::acquire_cf7_dedup_lock( $lock_name );
			if ( ! $lock_acquired ) {
				return new \WP_Error(
					'cf7_create_in_progress',
					'Form liên hệ đang được tạo ở một yêu cầu khác, vui lòng thử lại sau ít giây.',
					array( 'status' => 409 )
				);
			}
		}

		try {
			// Double-checked locking: another request may have created (and released
			// the lock for) this exact project+block while we were waiting.
			if ( $project_id > 0 && $block_id ) {
				$found_after_lock = get_posts( array(
					'post_type'      => 'wpcf7_contact_form',
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'meta_key'       => '_bzpb_block_id',
					'meta_value'     => $dedup_key,
					'fields'         => 'ids',
				) );
				if ( ! empty( $found_after_lock ) ) {
					$existing_id = (int) $found_after_lock[0];
					$shortcode   = sprintf( '[contact-form-7 id="%d" title="%s"]', $existing_id, esc_attr( $title ) );
					self::patch_block_props( $project_id, $block_id, array(
						'cf7FormId'    => $existing_id,
						'cf7ShortCode' => $shortcode,
					) );
					return rest_ensure_response( array(
						'success'     => true,
						'cf7_form_id' => $existing_id,
						'shortcode'   => $shortcode,
						'reused'      => true,
					) );
				}
			}

			// Build CF7 form body from field definitions
			$form_body = self::build_cf7_form_body( $fields );

			// Insert CF7 post (wpcf7 custom post type)
			$post_id = wp_insert_post( array(
				'post_type'   => 'wpcf7_contact_form',
				'post_status' => 'publish',
				'post_title'  => sanitize_text_field( $title ),
			) );

			if ( is_wp_error( $post_id ) || ! $post_id ) {
				return new \WP_Error( 'cf7_create_failed', 'Không tạo được CF7 form.', array( 'status' => 500 ) );
			}

			// Save CF7 form content and mail settings as post meta
			update_post_meta( $post_id, '_form', $form_body );
			update_post_meta( $post_id, '_mail', self::build_cf7_mail_meta( $title, $fields ) );
			update_post_meta( $post_id, '_messages', array() );
			update_post_meta( $post_id, '_additional_settings', '' );
			update_post_meta( $post_id, '_wpcf7', WPCF7_VERSION );
			// [2026-07-02 Johnny Chu] PHASE-PB-LEADFORM — stamp dedup key so future
			// Publish calls can find and reuse this form without creating duplicates.
			update_post_meta( $post_id, '_bzpb_block_id', $dedup_key );

			$shortcode = sprintf( '[contact-form-7 id="%d" title="%s"]', $post_id, esc_attr( $title ) );

			// Auto-register CF7 field mapping for CRM
			self::auto_register_cf7_mapping( $post_id, $fields, array(
				'fb_pixel_id'    => $fb_pixel_id,
				'ga_id'          => $ga_id,
				'gtm_id'         => $gtm_id,
				'tracking_event' => $tracking_event,
				'page_path'      => $page_path,
			) );

			// Patch the block props in the saved project config
			if ( $project_id > 0 && $block_id ) {
				self::patch_block_props( $project_id, $block_id, [
					'cf7FormId'    => $post_id,
					'cf7ShortCode' => $shortcode,
				] );
			}

			return rest_ensure_response( [
				'success'    => true,
				'cf7_form_id' => $post_id,
				'shortcode'  => $shortcode,
			] );
		} finally {
			if ( $lock_name ) {
				self::release_cf7_dedup_lock( $lock_name );
			}
		}
	}

	/**
	 * Acquire an atomic per-site lock keyed by $lock_name.
	 *
	 * Uses add_option() which is atomic at the DB level (unique key on
	 * wp_options.option_name) — the first concurrent caller wins the lock,
	 * all others get false immediately without a race window.
	 *
	 * [2026-08-21 Johnny Chu] PHASE-PB-TRACKING Wave 6.1 item 2
	 *
	 * @param string $lock_name Option name to use as the lock.
	 * @return bool True if the lock was acquired.
	 */
	private static function acquire_cf7_dedup_lock( string $lock_name ): bool {
		if ( add_option( $lock_name, (string) time(), '', 'no' ) ) {
			return true;
		}
		// Stale lock recovery: a crashed/timed-out request may have left the lock
		// behind. Reclaim it after 30s so a real outage doesn't deadlock Publish.
		$existing = (int) get_option( $lock_name );
		if ( $existing > 0 && ( time() - $existing ) > 30 ) {
			delete_option( $lock_name );
			return add_option( $lock_name, (string) time(), '', 'no' );
		}
		return false;
	}

	/**
	 * Release a lock acquired via acquire_cf7_dedup_lock().
	 *
	 * @param string $lock_name Option name used as the lock.
	 * @return void
	 */
	private static function release_cf7_dedup_lock( string $lock_name ) {
		delete_option( $lock_name );
	}

	/**
	 * Build CF7 form body HTML from field definitions.
	 * PHP 7.4 compatible — uses strpos() instead of str_contains().
	 *
	 * @param array $fields FormField[] from block props.
	 * @return string CF7 form template markup.
	 */
	private static function build_cf7_form_body( array $fields ) {
		// [2026-06-27 Johnny Chu] PHASE-PB-LEADFORM — generate CF7 form template
		$lines = [];
		foreach ( $fields as $f ) {
			$name  = sanitize_key( isset( $f['name'] ) ? $f['name'] : 'field' );
			$label = isset( $f['label'] ) ? sanitize_text_field( $f['label'] ) : $name;
			$type  = isset( $f['type'] ) ? $f['type'] : 'text';
			$req   = ! empty( $f['required'] ) ? '*' : '';
			$ph    = isset( $f['placeholder'] ) ? esc_attr( $f['placeholder'] ) : '';

			switch ( $type ) {
				case 'email':
					$tag = "[email{$req} {$name} placeholder \"{$ph}\"]";
					break;
				case 'tel':
					$tag = "[tel{$req} {$name} placeholder \"{$ph}\"]";
					break;
				case 'textarea':
					$tag = "[textarea{$req} {$name} placeholder \"{$ph}\"]";
					break;
				case 'select':
					// [2026-06-27 Johnny Chu] PHASE-PB-LEADFORM BUGFIX — CF7 select needs each option as separate quoted token
					$opts_raw = isset( $f['options'] ) && is_array( $f['options'] ) ? $f['options'] : array();
					$opts_str = implode( ' ', array_map( function ( $o ) { return '"' . esc_attr( $o ) . '"'; }, $opts_raw ) );
					$tag = "[select{$req} {$name} {$opts_str}]";
					break;
				case 'checkbox':
					$tag = "[checkbox {$name} \"{$label}\"]";
					break;
				case 'hidden':
					$tag = "[hidden {$name}]";
					break;
				default:
					$tag = "[text{$req} {$name} placeholder \"{$ph}\"]";
					break;
			}

			if ( $type !== 'hidden' ) {
				$lines[] = "<label> {$label}<br>\n    {$tag}</label>";
			} else {
				$lines[] = $tag;
			}
		}
		$lines[] = '[submit "Gửi"]';
		return implode( "\n\n", $lines );
	}

	/**
	 * Build CF7 _mail meta array.
	 *
	 * @param string $title Form title.
	 * @param array  $fields FormField[] from block props.
	 * @return array CF7 mail settings array.
	 */
	private static function build_cf7_mail_meta( $title, array $fields ) {
		// [2026-06-27 Johnny Chu] PHASE-PB-LEADFORM — auto-build CF7 mail template
		$to       = get_option( 'admin_email' );
		$body_parts = [];
		foreach ( $fields as $f ) {
			$name  = sanitize_key( isset( $f['name'] ) ? $f['name'] : 'field' );
			$label = isset( $f['label'] ) ? sanitize_text_field( $f['label'] ) : $name;
			$body_parts[] = "{$label}: [{$name}]";
		}
		// [2026-06-27 Johnny Chu] PHASE-PB-LEADFORM BUGFIX — include active:1 required by CF7 mail 1 schema
		return [
			'active'     => 1,
			'subject'    => '[' . get_bloginfo( 'name' ) . '] ' . $title,
			'sender'     => get_bloginfo( 'name' ) . ' <' . $to . '>',
			'body'       => implode( "\n", $body_parts ),
			'recipient'  => $to,
			'additional_headers' => 'Reply-To: [your-email]',
			'attachments'        => '',
			'use_html'           => 0,
			'exclude_blank'      => 0,
		];
	}

	/**
	 * Auto-register CF7 → CRM field mapping using existing CG option.
	 * PHP 7.4 compatible — uses strpos() instead of str_contains().
	 *
	 * @param int   $form_id CF7 post ID.
	 * @param array $fields  FormField[].
	 */
	private static function auto_register_cf7_mapping( $form_id, array $fields, array $page_tracking = array() ) {
		// [2026-06-27 Johnny Chu] PHASE-PB-LEADFORM — auto-wire CF7 → CRM mappings
		$mappings = get_option( 'bizcity_cg_cf7_mappings', [] );
		if ( ! is_array( $mappings ) ) {
			$mappings = [];
		}

		$crm_map = [];
		foreach ( $fields as $f ) {
			$name = sanitize_key( isset( $f['name'] ) ? $f['name'] : '' );
			if ( ! $name ) {
				continue;
			}
			// Auto-detect common CRM field mappings by name substring (PHP 7.4: strpos)
			if ( strpos( $name, 'email' ) !== false ) {
				$crm_map[ $name ] = 'email';
			} elseif ( strpos( $name, 'phone' ) !== false || strpos( $name, 'tel' ) !== false ) {
				$crm_map[ $name ] = 'phone';
			} elseif ( strpos( $name, 'name' ) !== false ) {
				$crm_map[ $name ] = 'name';
			} else {
				$crm_map[ $name ] = $name;
			}
		}

		// [2026-06-27 Johnny Chu] PHASE-PB-LEADFORM Wave 4 — merge full mapping record (preserve existing fields)
		$existing = isset( $mappings[ (string) $form_id ] ) && is_array( $mappings[ (string) $form_id ] )
			? $mappings[ (string) $form_id ]
			: array();

		$mappings[ (string) $form_id ] = array_merge( $existing, array(
			'form_id'          => $form_id,
			'form_title'       => get_the_title( $form_id ) ?: 'PB Form #' . $form_id,
			'enabled'          => true,
			'field_map'        => $crm_map,
			'auto_tag'         => array( 'pagebuilder', 'lead' ),
			'default_owner_id' => 0,
			'updated_at'       => gmdate( 'c' ),
			'_source'          => 'pagebuilder_auto',
			'page_tracking'    => array(
				'fb_pixel_id'    => sanitize_text_field( $page_tracking['fb_pixel_id'] ?? '' ),
				'ga_id'          => sanitize_text_field( $page_tracking['ga_id'] ?? '' ),
				'gtm_id'         => sanitize_text_field( $page_tracking['gtm_id'] ?? '' ),
				'tracking_event' => sanitize_key( $page_tracking['tracking_event'] ?? 'lead_submit' ),
				'page_path'      => sanitize_text_field( $page_tracking['page_path'] ?? '' ),
			),
		) );
		update_option( 'bizcity_cg_cf7_mappings', $mappings );
	}

	/**
	 * Patch block props in a saved project's JSON config.
	 *
	 * @param int    $project_id WP post ID of the bzpb project.
	 * @param string $block_id   Block UUID to patch.
	 * @param array  $props      Key-value props to merge into block.props.
	 */
	private static function patch_block_props( $project_id, $block_id, array $props ) {
		// [2026-06-27 Johnny Chu] PHASE-PB-LEADFORM — patch saved config with CF7 form ID
		$raw = get_post_meta( $project_id, '_bzpb_config', true );
		if ( empty( $raw ) ) {
			return;
		}
		$config = json_decode( $raw, true );
		if ( ! is_array( $config ) ) {
			return;
		}

		// Walk top-level blocks and pages[*].blocks
		$patched = false;
		if ( isset( $config['blocks'] ) && is_array( $config['blocks'] ) ) {
			foreach ( $config['blocks'] as &$block ) {
				if ( isset( $block['id'] ) && $block['id'] === $block_id ) {
					if ( ! isset( $block['props'] ) ) {
						$block['props'] = [];
					}
					$block['props'] = array_merge( $block['props'], $props );
					$patched = true;
					break;
				}
			}
			unset( $block );
		}
		if ( ! $patched && isset( $config['pages'] ) && is_array( $config['pages'] ) ) {
			foreach ( $config['pages'] as &$page ) {
				if ( ! isset( $page['blocks'] ) || ! is_array( $page['blocks'] ) ) {
					continue;
				}
				foreach ( $page['blocks'] as &$block ) {
					if ( isset( $block['id'] ) && $block['id'] === $block_id ) {
						if ( ! isset( $block['props'] ) ) {
							$block['props'] = [];
						}
						$block['props'] = array_merge( $block['props'], $props );
						$patched = true;
						break 2;
					}
				}
				unset( $block );
			}
			unset( $page );
		}

		if ( $patched ) {
			update_post_meta( $project_id, '_bzpb_config', wp_json_encode( $config ) );
		}
	}

	/* ═══════════════════════════════════════════════
	   GET PAGE TRACKING — [2026-06-27 Johnny Chu] PHASE-PB-LEADFORM Wave 4
	   GET /bzpb/v1/page-tracking/{project_id}
	   Returns tracking config + sync status for all lead-form blocks in a project.
	   ═══════════════════════════════════════════════ */
	public static function handle_get_page_tracking( \WP_REST_Request $request ) {
		$project_id = (int) $request->get_param( 'project_id' );
		if ( ! $project_id ) {
			return rest_ensure_response( [ 'success' => false, 'forms' => [] ] );
		}

		$raw = get_post_meta( $project_id, '_bzpb_config', true );
		if ( empty( $raw ) ) {
			return rest_ensure_response( [ 'success' => true, 'forms' => [] ] );
		}

		$config = json_decode( $raw, true );
		if ( ! is_array( $config ) ) {
			return rest_ensure_response( [ 'success' => true, 'forms' => [] ] );
		}

		// Collect all lead-form blocks across top-level blocks and pages
		$lead_form_blocks = [];
		$all_blocks = isset( $config['blocks'] ) && is_array( $config['blocks'] ) ? $config['blocks'] : [];
		if ( isset( $config['pages'] ) && is_array( $config['pages'] ) ) {
			foreach ( $config['pages'] as $page ) {
				if ( isset( $page['blocks'] ) && is_array( $page['blocks'] ) ) {
					$all_blocks = array_merge( $all_blocks, $page['blocks'] );
				}
			}
		}
		foreach ( $all_blocks as $block ) {
			if ( isset( $block['type'] ) && $block['type'] === 'lead-form' ) {
				$lead_form_blocks[] = $block;
			}
		}

		// Build sync status for each lead-form block
		$all_mappings = get_option( 'bizcity_cg_cf7_mappings', [] );
		if ( ! is_array( $all_mappings ) ) {
			$all_mappings = [];
		}

		$forms = [];
		foreach ( $lead_form_blocks as $block ) {
			$props       = isset( $block['props'] ) && is_array( $block['props'] ) ? $block['props'] : [];
			$cf7_form_id = isset( $props['cf7FormId'] ) ? (int) $props['cf7FormId'] : 0;
			$mapping     = $cf7_form_id ? ( $all_mappings[ (string) $cf7_form_id ] ?? null ) : null;

			$forms[] = [
				'block_id'       => $block['id'] ?? '',
				'title'          => $props['title'] ?? '',
				'cf7_form_id'    => $cf7_form_id,
				'cf7_shortcode'  => $props['cf7ShortCode'] ?? '',
				'tracking_event' => $props['trackingEvent'] ?? '',
				'synced'         => $mapping !== null,
				'page_tracking'  => $mapping ? ( $mapping['page_tracking'] ?? [] ) : [],
				'crm_enabled'    => $mapping ? ( ! empty( $mapping['enabled'] ) ) : false,
			];
		}

		return rest_ensure_response( [
			'success' => true,
			'forms'   => $forms,
		] );
	}
}