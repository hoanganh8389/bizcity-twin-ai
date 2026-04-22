<?php
/**
 * REST API for Content Creator — SSE streaming + file operations.
 *
 * Endpoints:
 *   GET  /bzcc/v1/file/{id}/stream   — SSE stream for outline generation
 *   GET  /bzcc/v1/file/{id}          — Get file status + chunks
 *   POST /bzcc/v1/file/{id}/generate — Trigger content generation for a file
 *   POST /bzcc/v1/chunk/{id}/action  — Copy, edit, regenerate, save actions
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BZCC_Rest_API {

	const NAMESPACE = 'bzcc/v1';

	public static function init(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	public static function register_routes(): void {
		/* ── File status + chunks ── */
		register_rest_route( self::NAMESPACE, '/file/(?P<id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'get_file' ],
			'permission_callback' => [ __CLASS__, 'check_auth' ],
			'args'                => [
				'id' => [ 'type' => 'integer', 'required' => true ],
			],
		] );

		/* ── Trigger generation (outline → chunks) ── */
		register_rest_route( self::NAMESPACE, '/file/(?P<id>\d+)/generate', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'generate_file' ],
			'permission_callback' => [ __CLASS__, 'check_auth' ],
			'args'                => [
				'id' => [ 'type' => 'integer', 'required' => true ],
			],
		] );

		/* ── SSE stream ── */
		register_rest_route( self::NAMESPACE, '/file/(?P<id>\d+)/stream', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'stream_file' ],
			'permission_callback' => [ __CLASS__, 'check_auth' ],
			'args'                => [
				'id' => [ 'type' => 'integer', 'required' => true ],
			],
		] );

		/* ── Chunk action (copy, edit, regenerate) ── */
		register_rest_route( self::NAMESPACE, '/chunk/(?P<id>\d+)/action', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'chunk_action' ],
			'permission_callback' => [ __CLASS__, 'check_auth' ],
			'args'                => [
				'id'     => [ 'type' => 'integer', 'required' => true ],
				'action' => [ 'type' => 'string', 'required' => true ],
			],
		] );

		/* ── Per-chunk SSE stream (parallel mode) ── */
		register_rest_route( self::NAMESPACE, '/chunk/(?P<id>\d+)/stream', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'stream_chunk_single' ],
			'permission_callback' => [ __CLASS__, 'check_auth' ],
			'args'                => [
				'id' => [ 'type' => 'integer', 'required' => true ],
			],
		] );

		/* ── History: list user's files ── */
		register_rest_route( self::NAMESPACE, '/files', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'list_files' ],
			'permission_callback' => [ __CLASS__, 'check_auth' ],
			'args'                => [
				'status' => [ 'type' => 'string', 'default' => '' ],
				'search' => [ 'type' => 'string', 'default' => '' ],
				'limit'  => [ 'type' => 'integer', 'default' => 50 ],
				'offset' => [ 'type' => 'integer', 'default' => 0 ],
			],
		] );

		/* ── Delete file ── */
		register_rest_route( self::NAMESPACE, '/file/(?P<id>\d+)', [
			'methods'             => 'DELETE',
			'callback'            => [ __CLASS__, 'delete_file' ],
			'permission_callback' => [ __CLASS__, 'check_auth' ],
			'args'                => [
				'id' => [ 'type' => 'integer', 'required' => true ],
			],
		] );

		/* ── Template: export single ── */
		register_rest_route( self::NAMESPACE, '/template/(?P<id>\d+)/export', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'export_template' ],
			'permission_callback' => [ __CLASS__, 'check_admin' ],
			'args'                => [
				'id' => [ 'type' => 'integer', 'required' => true ],
			],
		] );

		/* ── Template: export all ── */
		register_rest_route( self::NAMESPACE, '/templates/export', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'export_all_templates' ],
			'permission_callback' => [ __CLASS__, 'check_admin' ],
		] );

		/* ── Template: import ── */
		register_rest_route( self::NAMESPACE, '/templates/import', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'import_templates' ],
			'permission_callback' => [ __CLASS__, 'check_admin' ],
		] );

		/* ── Template: duplicate ── */
		register_rest_route( self::NAMESPACE, '/template/(?P<id>\d+)/duplicate', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'duplicate_template' ],
			'permission_callback' => [ __CLASS__, 'check_admin' ],
			'args'                => [
				'id' => [ 'type' => 'integer', 'required' => true ],
			],
		] );

		/* ── Video: poll job status ── */
		register_rest_route( self::NAMESPACE, '/video/poll', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'handle_video_poll' ],
			'permission_callback' => [ __CLASS__, 'check_auth' ],
		] );

		/* ── Continue: add more sections via chat prompt ── */
		register_rest_route( self::NAMESPACE, '/file/(?P<id>\d+)/continue', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'continue_generate' ],
			'permission_callback' => [ __CLASS__, 'check_auth' ],
			'args'                => [
				'id'     => [ 'type' => 'integer', 'required' => true ],
				'prompt' => [ 'type' => 'string',  'required' => true ],
				'tone'   => [ 'type' => 'string',  'default'  => '' ],
				'length' => [ 'type' => 'string',  'default'  => '' ],
			],
		] );

		/* ── Smart Input: Vision preview (Phase 3.2 Sprint 1) ── */
		register_rest_route( self::NAMESPACE, '/smart-input/vision', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'smart_input_vision' ],
			'permission_callback' => [ __CLASS__, 'check_auth' ],
			'args'                => [
				'attachment_id' => [ 'type' => 'integer', 'required' => true ],
				'vision_prompt' => [ 'type' => 'string',  'default'  => '' ],
				'max_tokens'    => [ 'type' => 'integer', 'default'  => 1500 ],
			],
		] );

		/* ── Smart Input: File preview (Phase 3.2 Sprint 2) ── */
		register_rest_route( self::NAMESPACE, '/smart-input/file', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'smart_input_file' ],
			'permission_callback' => [ __CLASS__, 'check_auth' ],
			'args'                => [
				'attachment_id' => [ 'type' => 'integer', 'required' => true ],
				'max_rows'      => [ 'type' => 'integer', 'default'  => 100 ],
			],
		] );
	}

	/* ── Auth ── */

	public static function check_auth(): bool {
		return is_user_logged_in();
	}

	public static function check_admin(): bool {
		return current_user_can( 'manage_options' );
	}

	/* ═══════════════════════════════════════
	 *  GET /file/{id}
	 * ═══════════════════════════════════════ */
	public static function get_file( WP_REST_Request $request ): WP_REST_Response {
		$file_id = (int) $request->get_param( 'id' );
		$file    = BZCC_File_Manager::get_by_id( $file_id );

		if ( ! $file ) {
			return new WP_REST_Response( [ 'error' => 'File not found' ], 404 );
		}

		// Ownership check
		if ( (int) $file->user_id !== get_current_user_id() ) {
			return new WP_REST_Response( [ 'error' => 'Forbidden' ], 403 );
		}

		$template = BZCC_Template_Manager::get_by_id( (int) $file->template_id );
		$chunks   = BZCC_Chunk_Meta_Manager::get_by_file_with_content( $file_id );

		// Parse outline for step labels
		$outline = json_decode( $file->outline, true ) ?: [];

		return new WP_REST_Response( [
			'file'     => [
				'id'             => (int) $file->id,
				'title'          => $file->title,
				'status'         => $file->status,
				'outline_status' => $file->outline_status,
				'chunk_count'    => (int) $file->chunk_count,
				'chunk_done'     => (int) $file->chunk_done,
				'form_data'      => json_decode( $file->form_data, true ),
				'outline'        => $outline,
				'created_at'     => $file->created_at,
			],
			'template' => $template ? [
				'id'          => (int) $template->id,
				'title'       => $template->title,
				'icon_emoji'  => $template->icon_emoji,
				'description' => $template->description,
			] : null,
			'chunks'   => array_map( function ( $c ) {
				return [
					'id'          => (int) $c->id,
					'chunk_index' => (int) $c->chunk_index,
					'node_status' => $c->node_status,
					'platform'    => $c->platform,
					'stage_label' => $c->stage_label,
					'stage_emoji' => $c->stage_emoji,
					'content'     => $c->content ?? '',
					'format'      => $c->format ?? 'markdown',
					'hashtags'    => $c->hashtags,
					'cta_text'    => $c->cta_text,
					'image_url'   => $c->image_url,
					'video_url'   => $c->video_url,
					'notes'       => $c->notes,
				];
			}, $chunks ),
		] );
	}

	/* ═══════════════════════════════════════
	 *  POST /file/{id}/generate
	 *
	 *  Triggers the AI generation pipeline:
	 *  1. Build system prompt with form_data variables
	 *  2. Call LLM to generate outline (JSON with sections)
	 *  3. Create chunk_meta records per outline node
	 *  4. Update file status → 'generating'
	 *  5. Return outline so frontend can start SSE
	 * ═══════════════════════════════════════ */
	public static function generate_file( WP_REST_Request $request ): WP_REST_Response {
		$file_id = (int) $request->get_param( 'id' );
		$file    = BZCC_File_Manager::get_by_id( $file_id );

		if ( ! $file ) {
			return new WP_REST_Response( [ 'error' => 'File not found' ], 404 );
		}

		if ( (int) $file->user_id !== get_current_user_id() ) {
			return new WP_REST_Response( [ 'error' => 'Forbidden' ], 403 );
		}

		// Already generated?
		if ( $file->status !== 'pending' ) {
			return new WP_REST_Response( [
				'status'  => $file->status,
				'message' => 'File đã được xử lý.',
			] );
		}

		$template  = BZCC_Template_Manager::get_by_id( (int) $file->template_id );
		$form_data = json_decode( $file->form_data, true ) ?: [];

		if ( ! $template ) {
			return new WP_REST_Response( [ 'error' => 'Template not found' ], 404 );
		}

		// ── Step 1: Build prompts with variable substitution ──
		$system_prompt  = $template->system_prompt ?: '';
		$outline_prompt = $template->outline_prompt ?: '';
		$chunk_prompt   = $template->chunk_prompt ?: '';
		$original_system = $system_prompt; // Keep original for placeholder detection

		foreach ( $form_data as $key => $val ) {
			$placeholder = '{{' . $key . '}}';
			$str_val     = is_array( $val ) ? implode( ', ', $val ) : (string) $val;
			$system_prompt  = str_replace( $placeholder, $str_val, $system_prompt );
			$outline_prompt = str_replace( $placeholder, $str_val, $outline_prompt );
			$chunk_prompt   = str_replace( $placeholder, $str_val, $chunk_prompt );
		}

		// ── Step 1.5: Smart Input Pipeline (Phase 3.2) ──
		try {
			$smart_result = BZCC_Smart_Input_Pipeline::run( $form_data, $template, get_current_user_id() );
		} catch ( \Throwable $e ) {
			error_log( '[BZCC] Smart Input Pipeline failed: ' . $e->getMessage() );
			$smart_result = [ 'has_content' => false, 'smart_context' => '', 'smart_input_result' => [] ];
		}

		if ( $smart_result['has_content'] ) {
			list( $system_prompt, $outline_prompt, $chunk_prompt ) = BZCC_Smart_Input_Pipeline::inject(
				$system_prompt,
				$outline_prompt,
				$chunk_prompt,
				$smart_result['smart_context'],
				$original_system
			);

			// Store smart input trace in file record for debugging
			BZCC_File_Manager::update( $file_id, [
				'form_data' => wp_json_encode( array_merge( $form_data, [
					'__smart_input' => $smart_result['smart_input_result'],
				] ) ),
			] );

			error_log( '[BZCC] Smart Input injected: ' . mb_strlen( $smart_result['smart_context'] ) . ' chars' );
		}

		error_log( '[BZCC] generate_file #' . $file_id . ' | template=' . $template->slug . ' | form_keys=' . implode( ',', array_keys( $form_data ) ) );

		// ── Step 2: Detect template mode + build context-aware outline prompt ──
		$output_platforms = json_decode( $template->output_platforms ?? '[]', true ) ?: [];

		// Extract user-selected platforms from form data (multi-select field)
		$selected_platforms = [];
		foreach ( [ 'platforms', 'platform', 'channels' ] as $_pk ) {
			if ( isset( $form_data[ $_pk ] ) ) {
				$_v = $form_data[ $_pk ];
				$selected_platforms = is_array( $_v ) ? $_v : array_filter( array_map( 'trim', explode( ',', (string) $_v ) ) );
				break;
			}
		}

		// Resolve active platforms: form selection → template output_platforms → fallback
		$active_platforms = array_filter( ! empty( $selected_platforms ) ? $selected_platforms : $output_platforms );

		// Determine document mode: single general platform, or outline_prompt explicitly targets steps/sections
		$is_document_mode = (
			( count( $output_platforms ) === 1 && ( $output_platforms[0] ?? '' ) === 'general' )
			|| ( count( $active_platforms ) === 1 && reset( $active_platforms ) === 'general' )
			|| ( ! empty( $outline_prompt ) && preg_match( '/\bgeneral\b|step_?\d|\bphần\b|\bbước\b|quy trình|tài liệu|lộ trình/ui', $outline_prompt ) )
		);

		error_log( '[BZCC] generate_file mode=' . ( $is_document_mode ? 'document' : 'campaign' ) . ' | active_platforms=' . implode( ',', $active_platforms ) );

		// ── Step 3: Build outline_system based on detected mode ──
		if ( $is_document_mode ) {
			$outline_system = implode( "\n", [
				'Bạn là trợ lý lập kế hoạch nội dung. Nhiệm vụ DUY NHẤT: tạo dàn ý (outline) dưới dạng JSON.',
				'',
				'CHẾ ĐỘ: TÀI LIỆU / KẾ HOẠCH / QUY TRÌNH',
				'- Mỗi section là 1 PHẦN LOGIC trong tài liệu/kế hoạch, có thứ tự rõ ràng.',
				'- Đặt "platform": "general" cho TẤT CẢ section.',
				'- Tên section (label) phải mô tả CHÍNH XÁC nội dung sẽ được viết trong phần đó.',
				'- Tạo 5-10 sections phù hợp với loại tài liệu, có logic tuần tự.',
				'',
				'QUY TẮC BẮT BUỘC:',
				'- CHỈ trả về JSON hợp lệ, KHÔNG viết nội dung chi tiết, KHÔNG giải thích.',
				'- Response bắt đầu bằng { và kết thúc bằng }',
				'',
				'FORMAT JSON BẮT BUỘC:',
				'{',
				'  "title": "Tên tài liệu/kế hoạch",',
				'  "sections": [',
				'    {',
				'      "label": "Tên phần/bước cụ thể",',
				'      "emoji": "📋",',
				'      "description": "Mô tả ngắn nội dung của phần này (1-2 câu)",',
				'      "platform": "general",',
				'      "stage": "step_1",',
				'      "content_type": "section",',
				'      "word_count": 300',
				'    }',
				'  ]',
				'}',
				'',
				'Các stage hợp lệ: step_1, step_2, step_3, ... (đánh số theo thứ tự)',
				'Các content_type hợp lệ: section, step, analysis, guide, checklist, summary, flowchart',
			] );
		} else {
			// Campaign / multi-platform mode
			$platforms_list = ! empty( $active_platforms )
				? implode( ', ', array_values( $active_platforms ) )
				: 'facebook, tiktok, instagram, email, zalo';
			$outline_system = implode( "\n", [
				'Bạn là trợ lý lập kế hoạch nội dung. Nhiệm vụ DUY NHẤT: tạo dàn ý (outline) dưới dạng JSON.',
				'',
				'CHẾ ĐỘ: CHIẾN DỊCH MARKETING ĐA KÊNH',
				'- Mỗi section là 1 bài viết/kịch bản riêng cho 1 kênh truyền thông cụ thể.',
				'- CHỈ tạo section cho các kênh sau (KHÔNG thêm kênh khác): ' . $platforms_list,
				'- Với mỗi kênh, tạo 1-3 bài theo giai đoạn phễu phù hợp.',
				'- Tổng số sections: 5-10 tùy số kênh.',
				'',
				'QUY TẮC BẮT BUỘC:',
				'- CHỈ trả về JSON hợp lệ, KHÔNG viết nội dung chi tiết, KHÔNG giải thích.',
				'- Response bắt đầu bằng { và kết thúc bằng }',
				'',
				'FORMAT JSON BẮT BUỘC:',
				'{',
				'  "title": "Tên chiến dịch",',
				'  "sections": [',
				'    {',
				'      "label": "Tiêu đề bài viết/kịch bản",',
				'      "emoji": "📘",',
				'      "description": "Mô tả ngắn nội dung cần viết (1-2 câu)",',
				'      "platform": "facebook",',
				'      "stage": "awareness",',
				'      "content_type": "post",',
				'      "word_count": 200',
				'    }',
				'  ]',
				'}',
				'',
				'Các platform hợp lệ: ' . $platforms_list . ', general',
				'Các stage hợp lệ: awareness, interest, trust, action, loyalty',
				'Các content_type hợp lệ: post, story, script, reel, message, email, article, carousel',
			] );
		}

		// ── Smart fallback when outline_prompt is empty ──
		if ( empty( trim( $outline_prompt ) ) ) {
			if ( $is_document_mode ) {
				$outline_prompt = 'Phân tích yêu cầu trên và tạo dàn ý phù hợp. '
					. 'Đảm bảo các section có thứ tự logic, mỗi section bao quát 1 khía cạnh quan trọng. '
					. 'Tên section phải mô tả chính xác nội dung sẽ được viết trong phần đó, không chung chung.';
			} else {
				$_ph = ! empty( $active_platforms ) ? 'Chỉ tạo section cho các kênh: ' . implode( ', ', array_values( $active_platforms ) ) . '. ' : '';
				$outline_prompt = $_ph
					. 'Dựa trên thông tin chiến dịch, tạo dàn ý nội dung thực tế và cụ thể. '
					. 'Mỗi section phải có tiêu đề rõ ràng mô tả bài viết/kịch bản cụ thể sẽ được tạo ra.';
			}
		}

		// Build user message for outline LLM call
		$outline_user = "BỐI CẢNH:\n" . $system_prompt . "\n\nYÊU CẦU OUTLINE:\n" . $outline_prompt
			. "\n\nNHẮC LẠI: CHỈ trả JSON, KHÔNG viết nội dung. Bắt đầu bằng { kết thúc bằng }";

		error_log( '[BZCC] Calling LLM for outline...' );

		$outline_response = self::call_llm( $outline_system, $outline_user, [
			'purpose'     => 'content_planning',
			'temperature' => 0.3,
			'max_tokens'  => 2000,
		] );

		if ( is_wp_error( $outline_response ) ) {
			error_log( '[BZCC] Outline LLM FAILED: ' . $outline_response->get_error_message() );
			BZCC_File_Manager::update( $file_id, [ 'status' => 'failed' ] );
			return new WP_REST_Response( [
				'error'   => 'LLM generation failed',
				'message' => $outline_response->get_error_message(),
			], 500 );
		}

		error_log( '[BZCC] Outline raw response (first 500): ' . mb_substr( $outline_response, 0, 500 ) );

		// Parse outline
		$outline_sections = self::parse_outline( $outline_response );

		error_log( '[BZCC] Parsed outline: ' . count( $outline_sections ) . ' sections' );

		if ( empty( $outline_sections ) ) {
			error_log( '[BZCC] WARNING: outline parse returned 0 sections, full response: ' . mb_substr( $outline_response, 0, 1000 ) );
			// Fallback: mode-aware single section
			$_fb_platform = $is_document_mode ? 'general' : ( reset( $active_platforms ) ?: 'general' );
			$_fb_stage    = $is_document_mode ? 'step_1'  : 'awareness';
			$_fb_type     = $is_document_mode ? 'section' : 'post';
			$outline_sections = [ [
				'label'        => $template->title,
				'emoji'        => '📝',
				'description'  => 'Tạo nội dung hoàn chỉnh',
				'platform'     => $_fb_platform,
				'stage'        => $_fb_stage,
				'content_type' => $_fb_type,
				'word_count'   => 400,
			] ];
		}

		// ── Step 3: Create chunk_meta records ──
		$created_chunks = [];

		foreach ( $outline_sections as $i => $section ) {
			$insert_data = [
				'file_id'     => $file_id,
				'chunk_index' => $i,
				'node_status' => 'pending',
				'platform'    => sanitize_text_field( $section['platform'] ?? '' ),
				'stage_label' => sanitize_text_field( $section['label'] ?? "Phần " . ( $i + 1 ) ),
				'stage_emoji' => sanitize_text_field( $section['emoji'] ?? '📝' ),
				'hashtags'    => '',
				'cta_text'    => '',
				'notes'       => wp_json_encode( $section ),
				'last_prompt' => $chunk_prompt,
			];

			$cid = BZCC_Chunk_Meta_Manager::insert( $insert_data );

			/* Retry once on failure (transient DB issue) */
			if ( ! $cid ) {
				usleep( 50000 ); // 50ms
				$cid = BZCC_Chunk_Meta_Manager::insert( $insert_data );
			}

			if ( ! $cid ) {
				error_log( '[BZCC] SKIP chunk index=' . $i . ' — insert failed after retry' );
				continue;
			}

			$created_chunks[] = [
				'id'          => $cid,
				'chunk_index' => $i,
				'label'       => sanitize_text_field( $section['label'] ?? "Phần " . ( $i + 1 ) ),
				'emoji'       => sanitize_text_field( $section['emoji'] ?? '📝' ),
				'platform'    => sanitize_text_field( $section['platform'] ?? '' ),
			];
		}

		$chunk_count = count( $created_chunks );

		// ── Step 4: Update file ──
		BZCC_File_Manager::update( $file_id, [
			'outline'        => wp_json_encode( $outline_sections ),
			'outline_status' => 'approved',
			'chunk_count'    => $chunk_count,
			'status'         => 'generating',
		] );

		return new WP_REST_Response( [
			'file_id'     => $file_id,
			'status'      => 'generating',
			'outline'     => $outline_sections,
			'chunks'      => $created_chunks,
			'chunk_count' => $chunk_count,
		] );
	}

	/* ═══════════════════════════════════════
	 *  POST /file/{id}/continue
	 *
	 *  Chat-style continuation: takes a user prompt, generates new outline
	 *  sections, appends chunk_meta records, returns them for SSE streaming.
	 * ═══════════════════════════════════════ */
	public static function continue_generate( WP_REST_Request $request ): WP_REST_Response {
		$file_id = (int) $request->get_param( 'id' );
		$prompt  = sanitize_textarea_field( $request->get_param( 'prompt' ) );
		$tone    = sanitize_text_field( $request->get_param( 'tone' ) ?: '' );
		$length  = sanitize_text_field( $request->get_param( 'length' ) ?: '' );

		$file = BZCC_File_Manager::get_by_id( $file_id );
		if ( ! $file || (int) $file->user_id !== get_current_user_id() ) {
			return new WP_REST_Response( [ 'error' => 'Forbidden' ], 403 );
		}

		$template  = BZCC_Template_Manager::get_by_id( (int) $file->template_id );
		$form_data = json_decode( $file->form_data, true ) ?: [];
		$existing_outline = json_decode( $file->outline, true ) ?: [];
		$existing_count   = count( $existing_outline );

		if ( ! $template ) {
			return new WP_REST_Response( [ 'error' => 'Template not found' ], 404 );
		}

		// Build system prompt with variable substitution
		$system_prompt = $template->system_prompt ?: '';
		foreach ( $form_data as $key => $val ) {
			$placeholder = '{{' . $key . '}}';
			$str_val     = is_array( $val ) ? implode( ', ', $val ) : (string) $val;
			$system_prompt = str_replace( $placeholder, $str_val, $system_prompt );
		}

		// Determine mode from existing outline
		$all_general = true;
		foreach ( $existing_outline as $s ) {
			if ( ( $s['platform'] ?? '' ) !== 'general' ) { $all_general = false; break; }
		}
		$is_document_mode = $all_general;

		// Build existing section summary for context
		$existing_summary = '';
		foreach ( $existing_outline as $i => $s ) {
			$existing_summary .= ( $i + 1 ) . '. ' . ( $s['emoji'] ?? '' ) . ' ' . ( $s['label'] ?? '' ) . "\n";
		}

		// Tone & length instructions
		$tone_instruction   = $tone   ? "Giọng điệu: {$tone}. " : '';
		$length_instruction = '';
		if ( $length === 'short' )  $length_instruction = 'Mỗi section chỉ cần 150-200 từ. ';
		if ( $length === 'medium' ) $length_instruction = 'Mỗi section khoảng 300-400 từ. ';
		if ( $length === 'long' )   $length_instruction = 'Mỗi section chi tiết 500-800 từ. ';

		// Build outline system for continuation
		$continue_system = implode( "\n", [
			'Bạn là trợ lý lập kế hoạch nội dung. Nhiệm vụ: tạo THÊM sections mới (dạng JSON) dựa trên yêu cầu của người dùng.',
			'',
			'CÁC SECTION ĐÃ CÓ (KHÔNG tạo lại):',
			$existing_summary,
			'',
			$tone_instruction . $length_instruction,
			'QUY TẮC:',
			'- CHỈ tạo section MỚI, KHÔNG lặp lại section đã có.',
			'- platform: "' . ( $is_document_mode ? 'general' : 'giữ nguyên theo yêu cầu' ) . '"',
			'- stage: "step_' . ( $existing_count + 1 ) . '", step_' . ( $existing_count + 2 ) . '... (tiếp nối)',
			'- CHỈ trả JSON, bắt đầu bằng { kết thúc bằng }',
			'',
			'FORMAT JSON:',
			'{ "sections": [ { "label":"...", "emoji":"...", "description":"...", "platform":"' . ( $is_document_mode ? 'general' : '..." ' ) . '", "stage":"step_N", "content_type":"section", "word_count":300 } ] }',
		] );

		$continue_user = "BỐI CẢNH GỐC:\n" . $system_prompt . "\n\nYÊU CẦU BỔ SUNG:\n" . $prompt
			. "\n\nNHẮC LẠI: CHỈ trả JSON sections MỚI. Bắt đầu bằng { kết thúc bằng }";

		error_log( '[BZCC] continue_generate #' . $file_id . ' | prompt=' . mb_substr( $prompt, 0, 100 ) );

		$response = self::call_llm( $continue_system, $continue_user, [
			'purpose'     => 'content_planning',
			'temperature' => 0.4,
			'max_tokens'  => 2000,
		] );

		if ( is_wp_error( $response ) ) {
			return new WP_REST_Response( [ 'error' => $response->get_error_message() ], 500 );
		}

		$new_sections = self::parse_outline( $response );
		if ( empty( $new_sections ) ) {
			return new WP_REST_Response( [ 'error' => 'Không thể tạo outline bổ sung. Thử lại với yêu cầu cụ thể hơn.' ], 422 );
		}

		// Create chunk_meta for new sections
		$chunk_prompt = $template->chunk_prompt ?: '';
		foreach ( $form_data as $key => $val ) {
			$placeholder = '{{' . $key . '}}';
			$str_val     = is_array( $val ) ? implode( ', ', $val ) : (string) $val;
			$chunk_prompt = str_replace( $placeholder, $str_val, $chunk_prompt );
		}

		$created_chunks = [];
		foreach ( $new_sections as $i => $section ) {
			$chunk_index = $existing_count + $i;
			$cid = BZCC_Chunk_Meta_Manager::insert( [
				'file_id'     => $file_id,
				'chunk_index' => $chunk_index,
				'node_status' => 'pending',
				'platform'    => sanitize_text_field( $section['platform'] ?? 'general' ),
				'stage_label' => sanitize_text_field( $section['label'] ?? "Phần " . ( $chunk_index + 1 ) ),
				'stage_emoji' => sanitize_text_field( $section['emoji'] ?? '📝' ),
				'hashtags'    => '',
				'cta_text'    => '',
				'notes'       => wp_json_encode( $section ),
				'last_prompt' => $chunk_prompt,
			] );

			if ( $cid ) {
				$created_chunks[] = [
					'id'          => $cid,
					'chunk_index' => $chunk_index,
					'label'       => sanitize_text_field( $section['label'] ?? "Phần " . ( $chunk_index + 1 ) ),
					'emoji'       => sanitize_text_field( $section['emoji'] ?? '📝' ),
					'platform'    => sanitize_text_field( $section['platform'] ?? 'general' ),
				];
			}
		}

		if ( empty( $created_chunks ) ) {
			return new WP_REST_Response( [ 'error' => 'Không thể tạo chunks mới.' ], 500 );
		}

		// Merge outlines and update file
		$merged_outline = array_merge( $existing_outline, $new_sections );
		BZCC_File_Manager::update( $file_id, [
			'outline'     => wp_json_encode( $merged_outline ),
			'chunk_count' => count( $merged_outline ),
			'status'      => 'generating',
		] );

		return new WP_REST_Response( [
			'file_id'       => $file_id,
			'status'        => 'generating',
			'new_sections'  => $new_sections,
			'chunks'        => $created_chunks,
			'chunk_count'   => count( $merged_outline ),
			'start_index'   => $existing_count,
		] );
	}

	/* ═══════════════════════════════════════
	 *  GET /file/{id}/stream — SSE
	 *
	 *  Sequentially generates content for each chunk.
	 *  Sends events: outline, chunk_start, chunk_delta, chunk_done, done
	 * ═══════════════════════════════════════ */
	public static function stream_file( WP_REST_Request $request ): void {
		@set_time_limit( 0 );

		$file_id = (int) $request->get_param( 'id' );
		$file    = BZCC_File_Manager::get_by_id( $file_id );

		if ( ! $file || (int) $file->user_id !== get_current_user_id() ) {
			header( 'HTTP/1.1 403 Forbidden' );
			exit;
		}

		$template = BZCC_Template_Manager::get_by_id( (int) $file->template_id );
		if ( ! $template ) {
			header( 'HTTP/1.1 404 Not Found' );
			exit;
		}

		// Clean any stray output before SSE headers
		while ( ob_get_level() > 0 ) {
			@ob_end_clean();
		}

		// SSE headers
		header( 'Content-Type: text/event-stream' );
		header( 'Cache-Control: no-cache' );
		header( 'Connection: keep-alive' );
		header( 'X-Accel-Buffering: no' );

		// Prevent PHP errors from corrupting SSE stream
		@ini_set( 'display_errors', '0' );
		ignore_user_abort( true );

		$form_data     = json_decode( $file->form_data, true ) ?: [];
		$chunks        = BZCC_Chunk_Meta_Manager::get_by_file( $file_id );
		$system_prompt = $template->system_prompt ?: '';
		$chunk_prompt  = $template->chunk_prompt ?: '';

		// Variable substitution
		foreach ( $form_data as $key => $val ) {
			$placeholder = '{{' . $key . '}}';
			$str_val     = is_array( $val ) ? implode( ', ', $val ) : (string) $val;
			$system_prompt = str_replace( $placeholder, $str_val, $system_prompt );
			$chunk_prompt  = str_replace( $placeholder, $str_val, $chunk_prompt );
		}

		// Enforce content creation rules (not strategy analysis)
		$system_prompt .= "\n\nQUY TẮC BẮT BUỘC:"
			. "\n- KHÔNG bắt đầu bằng lời mở đầu, chào hỏi, nhận xét (VD: 'Tuyệt vời!', 'Chắc chắn rồi!', 'Với vai trò là AI...')"
			. "\n- TUYỆT ĐỐI KHÔNG lặp lại, tóm tắt, hay hiển thị lại prompt/instruction đã nhận"
			. "\n- KHÔNG ghi 'System Prompt:', 'Chunk Prompt:', 'Outline Prompt:', 'YÊU CẦU:', 'QUY TẮC:' hay bất kỳ header instruction nào"
			. "\n- BẮT ĐẦU NGAY bằng nội dung thực tế (tiêu đề bài viết hoặc nội dung đầu tiên)"
			. "\n- Viết NỘI DUNG THỰC TẾ sẵn sàng đăng/gửi trên nền tảng được chỉ định"
			. "\n- KHÔNG viết phân tích chiến lược, nghiên cứu thị trường, hay bài luận học thuật"
			. "\n- Mỗi phần nội dung phải ĐỘC ĐÁO, khác biệt hoàn toàn với các phần khác"
			. "\n- Bám sát tiêu đề và hướng dẫn cụ thể của từng phần"
			. "\n- Format sạch: tiêu đề ngắn gọn, xuống dòng rõ ràng, dùng bullet/numbered list khi cần";

		error_log( '[BZCC SSE] stream_file #' . $file_id . ' | chunks=' . count( $chunks ) . ' | status=' . $file->status );

		// Send outline event
		$outline_data = json_decode( $file->outline, true ) ?: [];
		self::sse_send( 'outline', [
			'file_id'     => $file_id,
			'chunk_count' => count( $chunks ),
			'outline'     => $outline_data,
		] );

		error_log( '[BZCC SSE] Sent outline event with ' . count( $outline_data ) . ' sections, ' . count( $chunks ) . ' chunks to generate' );

		try {
			// Generate each chunk sequentially
			$prev_content = '';
			foreach ( $chunks as $i => $chunk ) {
				// Skip already completed or in-progress chunks (avoid duplicate with parallel mode)
				if ( in_array( $chunk->node_status, [ 'completed', 'generating' ], true ) ) {
					self::sse_send( 'chunk_done', [
						'chunk_index' => (int) $chunk->chunk_index,
						'chunk_id'    => (int) $chunk->id,
						'content'     => $chunk->notes, // already generated
						'skipped'     => true,
					] );
					continue;
				}

				$section_info = json_decode( $chunk->notes, true ) ?: [];

				// Build chunk-specific prompt — support both naming conventions
				$label       = $section_info['label'] ?? $section_info['title'] ?? $chunk->stage_label;
				$description = $section_info['description'] ?? $section_info['instructions'] ?? '';
				$platform    = $section_info['platform'] ?? $chunk->platform ?? '';
				$stage       = $section_info['stage'] ?? '';
				$word_count  = (string) ( $section_info['word_count'] ?? 200 );

				$prompt = self::build_enhanced_chunk_prompt(
					$chunk_prompt,
					$label,
					$description,
					$platform,
					$stage,
					$word_count,
					$i + 1,
					count( $chunks ),
					$prev_content,
					$section_info
				);

				// Mark chunk as generating
				BZCC_Chunk_Meta_Manager::transition_status( (int) $chunk->id, 'generating' );

				error_log( '[BZCC SSE] chunk_start #' . $i . ' | id=' . $chunk->id . ' | platform=' . $chunk->platform . ' | label=' . $chunk->stage_label );
				error_log( '[BZCC SSE] chunk_prompt (first 300): ' . mb_substr( $prompt, 0, 300 ) );

				self::sse_send( 'chunk_start', [
					'chunk_index' => (int) $chunk->chunk_index,
					'chunk_id'    => (int) $chunk->id,
					'label'       => $chunk->stage_label,
					'emoji'       => $chunk->stage_emoji,
					'platform'    => $chunk->platform,
				] );

				// Stream LLM response
				$full_content = self::stream_llm_chunk(
					$system_prompt,
					$prompt,
					(int) $chunk->chunk_index,
					(int) $chunk->id,
					[
						'purpose'     => $template->model_purpose ?: 'content_creation',
						'temperature' => (float) $template->temperature,
						'max_tokens'  => (int) ( $template->max_tokens ?: 4000 ),
					]
				);

				error_log( '[BZCC SSE] chunk_done #' . $i . ' | content_len=' . strlen( $full_content ) );

				// Save generated content to studio_outputs
				self::save_chunk_content( (int) $chunk->id, $full_content, $label );
				BZCC_Chunk_Meta_Manager::transition_status( (int) $chunk->id, 'completed' );
				BZCC_File_Manager::increment_chunk_done( $file_id );

				$prev_content = mb_substr( $full_content, 0, 500 ); // context window for next chunk

				self::sse_send( 'chunk_done', [
					'chunk_index' => (int) $chunk->chunk_index,
					'chunk_id'    => (int) $chunk->id,
				] );
			}

			// All done
			BZCC_File_Manager::update( $file_id, [ 'status' => 'completed' ] );

			error_log( '[BZCC SSE] DONE file #' . $file_id . ' | chunks_processed=' . count( $chunks ) );

			// Push completion summary to webchat
			$webchat_session = $file->session_id ?? '';
			if ( $webchat_session && class_exists( 'BizCity_WebChat_Database' ) ) {
				$result_url = admin_url( 'admin.php?page=bizcity-content-creator&view=result&file_id=' . $file_id );
				$webchat_db = new BizCity_WebChat_Database();
				$webchat_db->log_message( [
					'session_id'   => $webchat_session,
					'user_id'      => (int) $file->user_id,
					'message_text' => sprintf(
						"🎉 **Hoàn thành!** Đã tạo xong %d phần nội dung.\n\n[📄 Xem kết quả](%s)",
						count( $chunks ),
						$result_url
					),
					'message_from' => 'bot',
					'message_type' => 'tool_result',
					'plugin_slug'  => 'content-creator',
					'tool_name'    => 'content_creator_complete',
				] );
			}

			self::sse_send( 'done', [
				'file_id' => $file_id,
				'status'  => 'completed',
			] );
		} catch ( \Throwable $e ) {
			error_log( '[BZCC SSE] Fatal in stream_file #' . $file_id . ': ' . $e->getMessage() );
			self::sse_send( 'error', [
				'file_id' => $file_id,
				'message' => 'Generation error: ' . $e->getMessage(),
			] );
		}

		exit;
	}

	/* ═══════════════════════════════════════
	 *  GET /chunk/{id}/stream — SSE for a single chunk (parallel mode)
	 *
	 *  Frontend opens one EventSource per chunk for concurrent generation.
	 *  Events: chunk_start, chunk_delta, chunk_error, chunk_done
	 * ═══════════════════════════════════════ */
	public static function stream_chunk_single( WP_REST_Request $request ): void {
		@set_time_limit( 0 );

		$chunk_id = (int) $request->get_param( 'id' );
		$chunk    = BZCC_Chunk_Meta_Manager::get_by_id( $chunk_id );

		if ( ! $chunk ) {
			header( 'HTTP/1.1 404 Not Found' );
			exit;
		}

		$file = BZCC_File_Manager::get_by_id( (int) $chunk->file_id );
		if ( ! $file || (int) $file->user_id !== get_current_user_id() ) {
			header( 'HTTP/1.1 403 Forbidden' );
			exit;
		}

		$template = BZCC_Template_Manager::get_by_id( (int) $file->template_id );
		if ( ! $template ) {
			header( 'HTTP/1.1 404 Not Found' );
			exit;
		}

		// SSE headers
		while ( ob_get_level() > 0 ) { @ob_end_clean(); }
		header( 'Content-Type: text/event-stream' );
		header( 'Cache-Control: no-cache' );
		header( 'Connection: keep-alive' );
		header( 'X-Accel-Buffering: no' );

		// Prevent PHP errors from corrupting SSE stream
		@ini_set( 'display_errors', '0' );
		ignore_user_abort( true );

		$chunk_index = (int) $chunk->chunk_index;

		// Already completed? Send done immediately
		if ( $chunk->node_status === 'completed' ) {
			self::sse_send( 'chunk_done', [
				'chunk_index' => $chunk_index,
				'chunk_id'    => $chunk_id,
				'skipped'     => true,
				'all_done'    => false,
			] );
			exit;
		}

		// Build prompts with variable substitution
		$form_data     = json_decode( $file->form_data, true ) ?: [];
		$system_prompt = $template->system_prompt ?: '';
		$chunk_prompt  = $template->chunk_prompt ?: '';

		foreach ( $form_data as $key => $val ) {
			$ph      = '{{' . $key . '}}';
			$str_val = is_array( $val ) ? implode( ', ', $val ) : (string) $val;
			$system_prompt = str_replace( $ph, $str_val, $system_prompt );
			$chunk_prompt  = str_replace( $ph, $str_val, $chunk_prompt );
		}

		// Enforce content creation rules (not strategy analysis)
		$system_prompt .= "\n\nQUY TẮC BẮT BUỘC:"
			. "\n- KHÔNG bắt đầu bằng lời mở đầu, chào hỏi, nhận xét (VD: 'Tuyệt vời!', 'Chắc chắn rồi!', 'Với vai trò là AI...')"
			. "\n- TUYỆT ĐỐI KHÔNG lặp lại, tóm tắt, hay hiển thị lại prompt/instruction đã nhận"
			. "\n- KHÔNG ghi 'System Prompt:', 'Chunk Prompt:', 'Outline Prompt:', 'YÊU CẦU:', 'QUY TẮC:' hay bất kỳ header instruction nào"
			. "\n- BẮT ĐẦU NGAY bằng nội dung thực tế (tiêu đề bài viết hoặc nội dung đầu tiên)"
			. "\n- Viết NỘI DUNG THỰC TẾ sẵn sàng đăng/gửi trên nền tảng được chỉ định"
			. "\n- KHÔNG viết phân tích chiến lược, nghiên cứu thị trường, hay bài luận học thuật"
			. "\n- Mỗi phần nội dung phải ĐỘC ĐÁO, khác biệt hoàn toàn với các phần khác"
			. "\n- Bám sát tiêu đề và hướng dẫn cụ thể của từng phần"
			. "\n- Format sạch: tiêu đề ngắn gọn, xuống dòng rõ ràng, dùng bullet/numbered list khi cần";

		// Build chunk-specific prompt
		$section_info = json_decode( $chunk->notes, true ) ?: [];
		$all_chunks   = BZCC_Chunk_Meta_Manager::get_by_file( (int) $chunk->file_id );
		$total        = count( $all_chunks );

		$label       = $section_info['label'] ?? $section_info['title'] ?? $chunk->stage_label;
		$description = $section_info['description'] ?? $section_info['instructions'] ?? '';
		$platform    = $section_info['platform'] ?? $chunk->platform ?? '';
		$stage       = $section_info['stage'] ?? '';
		$word_count  = (string) ( $section_info['word_count'] ?? 200 );

		$prompt = self::build_enhanced_chunk_prompt(
			$chunk_prompt,
			$label,
			$description,
			$platform,
			$stage,
			$word_count,
			$chunk_index + 1,
			$total,
			'',
			$section_info
		);

		try {
			// Mark as generating
			BZCC_Chunk_Meta_Manager::transition_status( $chunk_id, 'generating' );

			error_log( '[BZCC SSE-P] chunk_start #' . $chunk_index . ' | id=' . $chunk_id . ' | platform=' . $chunk->platform );

			self::sse_send( 'chunk_start', [
				'chunk_index' => $chunk_index,
				'chunk_id'    => $chunk_id,
				'label'       => $chunk->stage_label,
				'emoji'       => $chunk->stage_emoji,
				'platform'    => $chunk->platform,
			] );

			// Stream LLM response
			$full_content = self::stream_llm_chunk(
				$system_prompt,
				$prompt,
				$chunk_index,
				$chunk_id,
				[
					'purpose'     => $template->model_purpose ?: 'content_creation',
					'temperature' => (float) $template->temperature,
					'max_tokens'  => (int) ( $template->max_tokens ?: 4000 ),
				]
			);

			error_log( '[BZCC SSE-P] chunk_done #' . $chunk_index . ' | content_len=' . strlen( $full_content ) );

			// Save generated content to studio_outputs
			self::save_chunk_content( $chunk_id, $full_content, $label );

			// Mark completed
			BZCC_Chunk_Meta_Manager::transition_status( $chunk_id, 'completed' );
			BZCC_File_Manager::increment_chunk_done( (int) $chunk->file_id );

			// ── Push chunk result to webchat_messages if session_id exists ──
			$webchat_session = $file->session_id ?? '';
			error_log( '[BZCC] chunk_done: file_id=' . $chunk->file_id
				. ' session_id=' . ( $webchat_session ?: '(empty)' )
				. ' class=' . ( class_exists( 'BizCity_WebChat_Database' ) ? 'YES' : 'NO' ) );
			if ( $webchat_session && class_exists( 'BizCity_WebChat_Database' ) ) {
				$preview = mb_substr( wp_strip_all_tags( $full_content ), 0, 300 );
				$webchat_db = new BizCity_WebChat_Database();
				$webchat_db->log_message( [
					'session_id'   => $webchat_session,
					'user_id'      => (int) $file->user_id,
					'message_text' => sprintf(
						"✅ **%s** (phần %d/%d)\n\n%s%s",
						$label,
						$chunk_index + 1,
						$total,
						$preview,
						strlen( $full_content ) > 300 ? '…' : ''
					),
					'message_from' => 'bot',
					'message_type' => 'tool_result',
					'plugin_slug'  => 'content-creator',
					'tool_name'    => 'content_creator_execute',
				] );
				error_log( '[BZCC] chunk→webchat: session=' . $webchat_session . ' chunk=#' . ( $chunk_index + 1 ) . '/' . $total );
			}

			// Check if ALL chunks are now done → mark file completed
			$updated_chunks = BZCC_Chunk_Meta_Manager::get_by_file( (int) $chunk->file_id );
			$all_done = true;
			foreach ( $updated_chunks as $c ) {
				if ( $c->node_status !== 'completed' ) {
					$all_done = false;
					break;
				}
			}
			if ( $all_done ) {
				BZCC_File_Manager::update( (int) $chunk->file_id, [ 'status' => 'completed' ] );

				// Push completion summary to webchat
				if ( $webchat_session && class_exists( 'BizCity_WebChat_Database' ) ) {
					$result_url = admin_url( 'admin.php?page=bizcity-content-creator&view=result&file_id=' . $chunk->file_id );
					$webchat_db_done = new BizCity_WebChat_Database();
					$webchat_db_done->log_message( [
						'session_id'   => $webchat_session,
						'user_id'      => (int) $file->user_id,
						'message_text' => sprintf(
							"🎉 **Hoàn thành!** Đã tạo xong %d phần nội dung.\n\n[📄 Xem kết quả](%s)",
							$total,
							$result_url
						),
						'message_from' => 'bot',
						'message_type' => 'tool_result',
						'plugin_slug'  => 'content-creator',
						'tool_name'    => 'content_creator_complete',
					] );
				}
			}

			self::sse_send( 'chunk_done', [
				'chunk_index' => $chunk_index,
				'chunk_id'    => $chunk_id,
				'all_done'    => $all_done,
			] );
		} catch ( \Throwable $e ) {
			error_log( '[BZCC SSE-P] Fatal in stream_chunk_single #' . $chunk_id . ': ' . $e->getMessage() );
			self::sse_send( 'error', [
				'chunk_index' => $chunk_index,
				'chunk_id'    => $chunk_id,
				'message'     => 'Generation error: ' . $e->getMessage(),
			] );
		}

		exit;
	}

	/* ═══════════════════════════════════════
	 *  POST /chunk/{id}/action
	 * ═══════════════════════════════════════ */
	public static function chunk_action( WP_REST_Request $request ): WP_REST_Response {
		$chunk_id = (int) $request->get_param( 'id' );
		$action   = sanitize_key( $request->get_param( 'action' ) );

		$chunk = BZCC_Chunk_Meta_Manager::get_by_id( $chunk_id );
		if ( ! $chunk ) {
			return new WP_REST_Response( [ 'error' => 'Chunk not found' ], 404 );
		}

		// Ownership check via file
		$file = BZCC_File_Manager::get_by_id( (int) $chunk->file_id );
		if ( ! $file || (int) $file->user_id !== get_current_user_id() ) {
			return new WP_REST_Response( [ 'error' => 'Forbidden' ], 403 );
		}

		switch ( $action ) {
			case 'save':
				$notes = sanitize_textarea_field( $request->get_param( 'notes' ) ?? '' );
				BZCC_Chunk_Meta_Manager::update( $chunk_id, [ 'notes' => $notes ] );
				return new WP_REST_Response( [ 'success' => true ] );

			case 'edit':
				$content = wp_kses_post( $request->get_param( 'content' ) ?? '' );
				BZCC_Chunk_Meta_Manager::update( $chunk_id, [
					'edit_count' => ( (int) $chunk->edit_count ) + 1,
				] );
				// Update studio output if linked
				if ( $chunk->studio_output_id ) {
					global $wpdb;
					$wpdb->update(
						$wpdb->prefix . 'bizcity_webchat_studio_outputs',
						[ 'content' => $content, 'status' => 'edited' ],
						[ 'id' => (int) $chunk->studio_output_id ]
					);
				}
				return new WP_REST_Response( [ 'success' => true ] );

			case 'retry':
				// Reset chunk status so stream_chunk_single will re-generate
				BZCC_Chunk_Meta_Manager::update( $chunk_id, [
					'node_status'     => 'pending',
					'studio_output_id' => 0,
				] );
				// Also reset file status if it was completed
				if ( $file->status === 'completed' ) {
					BZCC_File_Manager::update( (int) $file->id, [
						'status'     => 'generating',
						'chunk_done' => max( 0, (int) $file->chunk_done - 1 ),
					] );
				}
				error_log( '[BZCC] Retry chunk #' . $chunk_id . ' — reset to pending' );
				return new WP_REST_Response( [ 'success' => true, 'chunk_id' => $chunk_id ] );

			case 'gen-image':
				return self::handle_gen_image( $chunk, $request );

			case 'gen-video':
				return self::handle_gen_video( $chunk, $request );

			case 'save-video':
				return self::handle_save_video( $chunk, $request );

			default:
				return new WP_REST_Response( [ 'error' => 'Unknown action' ], 400 );
		}
	}

	/* ═══════════════════════════════════════
	 *  Generate image for a chunk
	 * ═══════════════════════════════════════ */
	private static function handle_gen_image( object $chunk, WP_REST_Request $request ): WP_REST_Response {
		$prompt    = sanitize_textarea_field( $request->get_param( 'prompt' ) ?? '' );
		$image_url = esc_url_raw( $request->get_param( 'image_url' ) ?? '' );
		$size      = sanitize_text_field( $request->get_param( 'size' ) ?? '1024x1024' );
		$model     = sanitize_text_field( $request->get_param( 'model' ) ?? '' );

		/* Handle reference image file upload */
		$files = $request->get_file_params();
		if ( ! empty( $files['reference_image'] ) && empty( $image_url ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			$att_id = media_handle_upload( 'reference_image', 0 );
			if ( ! is_wp_error( $att_id ) ) {
				$image_url = wp_get_attachment_url( $att_id );
			}
		}

		if ( empty( $prompt ) ) {
			return new WP_REST_Response( [ 'error' => 'Prompt trống' ], 400 );
		}

		/* Inject outline context from chunk metadata */
		$section_info = json_decode( $chunk->notes, true ) ?: [];
		$label        = $section_info['label'] ?? $section_info['title'] ?? $chunk->stage_label ?? '';
		$description  = $section_info['description'] ?? $section_info['instructions'] ?? '';
		$platform     = $section_info['platform'] ?? $chunk->platform ?? '';
		$stage        = $section_info['stage'] ?? '';
		$content_type = $section_info['content_type'] ?? '';

		$context_parts = [];
		if ( $label )        $context_parts[] = 'Tiêu đề mục: ' . $label;
		if ( $description )  $context_parts[] = 'Mô tả: ' . $description;
		if ( $platform )     $context_parts[] = 'Nền tảng: ' . $platform;
		if ( $stage )        $context_parts[] = 'Giai đoạn: ' . $stage;
		if ( $content_type ) $context_parts[] = 'Loại nội dung: ' . $content_type;

		if ( ! empty( $context_parts ) ) {
			$prompt .= "\n\nCONTEXT (outline section this image belongs to):\n" . implode( "\n", $context_parts )
			         . "\n→ The image MUST visually match this section's topic, platform style, and target audience.";
		}

		/* Enhance prompt with quality + anti-hallucination rules */
		if ( ! empty( $image_url ) ) {
			/* Reference mode: preserve the original image content */
			$prompt .= "\n\nCRITICAL RULES FOR REFERENCE IMAGE:"
			         . "\n- You MUST preserve the original product/subject from the reference image exactly as-is."
			         . "\n- Do NOT alter, remove, replace, or reimagine the main subject (product, logo, packaging, label)."
			         . "\n- Keep the product appearance, brand identity, colors, and design faithful to the reference."
			         . "\n- Only enhance the BACKGROUND, lighting, composition, and overall scene around the product."
			         . "\n- The reference image content must remain clearly recognizable in the output."
			         . "\n- Do NOT render additional text, letters, or characters that were not in the original.";
		} else {
			/* Text mode: pure illustration */
			$prompt .= "\n\nIMPORTANT RULES:"
			         . "\n- Do NOT render any text, letters, words, or characters inside the image."
			         . "\n- Focus on visual illustration: clean composition, vibrant colors, professional quality."
			         . "\n- Output a high-resolution, photorealistic or professionally illustrated image.";
		}

		/* Use BizCity_Tool_Image if available */
		if ( class_exists( 'BizCity_Tool_Image' ) ) {
			/* Resolve model: hardcode seedream (best quality for social content) */
			$resolved_model = 'seedream';

			error_log( '[BZCC gen-image] chunk=' . $chunk->id
				. ' | model=' . $resolved_model
				. ' | size=' . $size
				. ' | has_ref=' . ( ! empty( $image_url ) ? 'yes' : 'no' )
				. ' | prompt_len=' . mb_strlen( $prompt )
				. ' | prompt_first200=' . mb_substr( $prompt, 0, 200 )
			);

			$slots = [
				'prompt'        => $prompt,
				'image_url'     => $image_url,
				'model'         => $resolved_model,
				'size'          => $size,
				'style'         => 'photorealistic',
				'creation_mode' => ! empty( $image_url ) ? 'reference' : 'text',
				'user_id'       => get_current_user_id(),
				'_meta'         => [ 'session_id' => 'content_creator_chunk_' . $chunk->id ],
			];

			$result = BizCity_Tool_Image::generate_image( $slots );

			if ( ! empty( $result['success'] ) && ! empty( $result['data']['image_url'] ) ) {
				$gen_url = $result['data']['image_url'];
				BZCC_Chunk_Meta_Manager::update( (int) $chunk->id, [
					'image_url' => $gen_url,
					'image_id'  => (int) ( $result['data']['attachment_id'] ?? 0 ),
				] );

				// Persist image into studio_outputs content (markdown syntax)
				if ( $chunk->studio_output_id ) {
					global $wpdb;
					$tbl     = $wpdb->prefix . 'bizcity_webchat_studio_outputs';
					$current = $wpdb->get_var( $wpdb->prepare(
						"SELECT content FROM {$tbl} WHERE id = %d", (int) $chunk->studio_output_id
					) );
					$img_md      = "\n\n![AI Generated](" . esc_url( $gen_url ) . ")\n\n";
					$new_content = trim( $current ) . $img_md;
					$wpdb->update( $tbl, [ 'content' => $new_content ], [ 'id' => (int) $chunk->studio_output_id ] );
				}

				error_log( '[BZCC] gen-image OK chunk=' . $chunk->id . ' url=' . $gen_url );
				return new WP_REST_Response( [
					'success'   => true,
					'image_url' => $gen_url,
					'chunk_id'  => (int) $chunk->id,
				] );
			}

			return new WP_REST_Response( [
				'error'   => $result['message'] ?? 'Tạo ảnh thất bại',
				'success' => false,
			], 500 );
		}

		return new WP_REST_Response( [ 'error' => 'Plugin BizCity Tool Image chưa được cài đặt.' ], 501 );
	}

	/* ═══════════════════════════════════════
	 *  Generate video for a chunk (via BizCity Video Kling)
	 * ═══════════════════════════════════════ */
	private static function handle_gen_video( object $chunk, WP_REST_Request $request ): WP_REST_Response {
		if ( ! class_exists( 'BizCity_Tool_Kling' ) ) {
			return new WP_REST_Response( [ 'error' => 'Plugin B-roll Video chưa được cài đặt.' ], 501 );
		}

		$prompt       = sanitize_textarea_field( $request->get_param( 'prompt' ) ?? '' );
		$image_url    = esc_url_raw( $request->get_param( 'image_url' ) ?? '' );
		$duration     = intval( $request->get_param( 'duration' ) ?? 5 );
		$aspect_ratio = sanitize_text_field( $request->get_param( 'aspect_ratio' ) ?? '9:16' );
		$model        = sanitize_text_field( $request->get_param( 'model' ) ?? '2.6|pro' );

		if ( empty( $prompt ) && empty( $image_url ) ) {
			return new WP_REST_Response( [ 'error' => 'Cần ít nhất prompt hoặc ảnh.' ], 400 );
		}

		$user_id = get_current_user_id();

		error_log( '[BZCC gen-video] chunk=' . $chunk->id
			. ' | model=' . $model
			. ' | duration=' . $duration
			. ' | ratio=' . $aspect_ratio
			. ' | has_img=' . ( ! empty( $image_url ) ? 'yes' : 'no' )
			. ' | prompt_len=' . mb_strlen( $prompt )
		);

		$slots = [
			'message'      => $prompt,
			'image_url'    => $image_url,
			'duration'     => $duration,
			'aspect_ratio' => $aspect_ratio,
			'model'        => $model,
			'user_id'      => $user_id,
		];

		$context = [
			'user_id'    => $user_id,
			'session_id' => 'content_creator_chunk_' . $chunk->id,
		];

		try {
			$result = BizCity_Tool_Kling::create_video( $slots, $context );
		} catch ( \Throwable $e ) {
			error_log( '[BZCC gen-video] EXCEPTION chunk=' . $chunk->id . ' | ' . $e->getMessage() . ' | ' . $e->getFile() . ':' . $e->getLine() );
			return new WP_REST_Response( [
				'error'   => 'Lỗi tạo video: ' . $e->getMessage(),
				'success' => false,
			], 500 );
		}

		if ( ! empty( $result['success'] ) ) {
			$job_id = $result['data']['job_id'] ?? 0;
			error_log( '[BZCC gen-video] OK chunk=' . $chunk->id . ' | job_id=' . $job_id );

			return new WP_REST_Response( [
				'success'  => true,
				'job_id'   => $job_id,
				'task_id'  => $result['data']['task_id'] ?? '',
				'status'   => $result['data']['status'] ?? 'queued',
				'chunk_id' => (int) $chunk->id,
				'message'  => $result['message'] ?? 'Video đang được tạo...',
			] );
		}

		return new WP_REST_Response( [
			'error'   => $result['message'] ?? 'Tạo video thất bại',
			'success' => false,
		], 500 );
	}

	/* ═══════════════════════════════════════
	 *  Poll video job status (REST — replaces admin-ajax bvk_poll_jobs)
	 * ═══════════════════════════════════════ */
	public static function handle_video_poll( WP_REST_Request $request ): WP_REST_Response {
		if ( ! class_exists( 'BizCity_Video_Kling_Database' ) ) {
			return new WP_REST_Response( [
				'jobs'  => [],
				'stats' => [ 'total' => 0, 'done' => 0, 'active' => 0 ],
			] );
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return new WP_REST_Response( [ 'error' => 'Vui lòng đăng nhập.' ], 401 );
		}

		global $wpdb;
		$jobs_table = BizCity_Video_Kling_Database::get_table_name( 'jobs' );
		$has_table  = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $jobs_table ) ) === $jobs_table;

		if ( ! $has_table ) {
			return new WP_REST_Response( [
				'jobs'  => [],
				'stats' => [ 'total' => 0, 'done' => 0, 'active' => 0 ],
			] );
		}

		$jobs = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, prompt, status, progress, video_url, media_url, attachment_id, model, duration, aspect_ratio, checkpoints, error_message, created_at, updated_at
			 FROM {$jobs_table} WHERE created_by = %d ORDER BY created_at DESC LIMIT 20",
			$user_id
		), ARRAY_A );

		foreach ( $jobs as &$j ) {
			$j['checkpoints'] = ! empty( $j['checkpoints'] ) ? json_decode( $j['checkpoints'], true ) : [];
		}
		unset( $j );

		$total  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$jobs_table} WHERE created_by = %d", $user_id ) );
		$done   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$jobs_table} WHERE created_by = %d AND status = 'completed'", $user_id ) );
		$active = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$jobs_table} WHERE created_by = %d AND status IN ('queued','processing')", $user_id ) );

		return new WP_REST_Response( [
			'jobs'  => $jobs,
			'stats' => compact( 'total', 'done', 'active' ),
		] );
	}

	/* ═══════════════════════════════════════
	 *  Save video URL to chunk meta
	 * ═══════════════════════════════════════ */
	private static function handle_save_video( object $chunk, WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;

		$video_url = esc_url_raw( $request->get_param( 'video_url' ) ?? '' );
		$video_id  = intval( $request->get_param( 'video_id' ) ?? 0 );

		if ( empty( $video_url ) ) {
			return new WP_REST_Response( [ 'error' => 'Missing video_url' ], 400 );
		}

		$meta_table = $wpdb->prefix . 'bizcity_creator_chunk_meta';
		$wpdb->update(
			$meta_table,
			[ 'video_url' => $video_url, 'video_id' => $video_id ],
			[ 'chunk_id' => (int) $chunk->id ],
			[ '%s', '%d' ],
			[ '%d' ]
		);

		/* Persist to studio_outputs */
		$out_table = $wpdb->prefix . 'bizcity_webchat_studio_outputs';
		$existing = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, content FROM {$out_table} WHERE reference_type = 'creator_chunk' AND reference_id = %d",
			(int) $chunk->id
		) );

		$video_md = "\n\n[Video AI](" . $video_url . ')';
		if ( $existing ) {
			$wpdb->update(
				$out_table,
				[ 'content' => $existing->content . $video_md ],
				[ 'id' => $existing->id ],
				[ '%s' ],
				[ '%d' ]
			);
		}

		error_log( '[BZCC save-video] chunk=' . $chunk->id . ' | video_url=' . $video_url );

		return new WP_REST_Response( [ 'success' => true, 'chunk_id' => (int) $chunk->id ] );
	}

	/* ═══════════════════════════════════════
	 *  Smart Input: Vision Preview (Phase 3.2)
	 * ═══════════════════════════════════════ */
	public static function smart_input_vision( WP_REST_Request $request ): WP_REST_Response {
		$attachment_id = (int) $request->get_param( 'attachment_id' );
		$vision_prompt = sanitize_textarea_field( $request->get_param( 'vision_prompt' ) ?: '' );
		$max_tokens    = min( (int) $request->get_param( 'max_tokens' ), 3000 ) ?: 1500;

		if ( ! $attachment_id || ! wp_attachment_is_image( $attachment_id ) ) {
			return new WP_REST_Response( [ 'error' => 'Invalid attachment' ], 400 );
		}

		$result = BZCC_Vision_Processor::process_single( $attachment_id, $vision_prompt, $max_tokens );

		if ( empty( $result['success'] ) ) {
			return new WP_REST_Response( [ 'error' => $result['description'] ?: 'Vision processing failed' ], 500 );
		}

		return new WP_REST_Response( [
			'description' => $result['description'],
			'tokens_used' => $result['tokens_used'] ?? 0,
		] );
	}

	/* ═══════════════════════════════════════
	 *  Smart Input: File Preview (Phase 3.2)
	 * ═══════════════════════════════════════ */
	public static function smart_input_file( WP_REST_Request $request ): WP_REST_Response {
		$attachment_id = (int) $request->get_param( 'attachment_id' );
		$max_rows      = min( (int) $request->get_param( 'max_rows' ), 500 ) ?: 100;

		if ( ! $attachment_id ) {
			return new WP_REST_Response( [ 'error' => 'Invalid attachment' ], 400 );
		}

		$file_path = get_attached_file( $attachment_id );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return new WP_REST_Response( [ 'error' => 'File not found' ], 404 );
		}

		$result = BZCC_File_Processor::process_single( $attachment_id, $max_rows );

		if ( empty( $result['success'] ) ) {
			return new WP_REST_Response( [ 'error' => $result['content'] ?: 'File processing failed' ], 500 );
		}

		return new WP_REST_Response( [
			'content'  => $result['content'],
			'size'     => mb_strlen( $result['content'] ),
			'rows'     => $result['rows'] ?? 0,
			'filename' => basename( $file_path ),
		] );
	}

	/* ═══════════════════════════════════════
	 *  LLM Integration Helpers
	 * ═══════════════════════════════════════ */

	/**
	 * Call LLM for outline generation (non-streaming).
	 */
	private static function call_llm( string $system, string $user, array $opts = [] ) {
		// Build OpenAI-format messages
		$messages = [
			[ 'role' => 'system', 'content' => $system ],
			[ 'role' => 'user',   'content' => $user ],
		];

		$llm_opts = [
			'purpose'     => $opts['purpose'] ?? 'content_creation',
			'temperature' => (float) ( $opts['temperature'] ?? 0.7 ),
			'max_tokens'  => (int) ( $opts['max_tokens'] ?? 4000 ),
			'timeout'     => 120,
		];

		// Use bizcity-llm-router if available
		if ( function_exists( 'bizcity_llm_chat' ) ) {
			$result = bizcity_llm_chat( $messages, $llm_opts );
			if ( ! empty( $result['success'] ) ) {
				return $result['message'] ?? '';
			}
			return new WP_Error( 'llm_error', $result['error'] ?? 'LLM call failed' );
		}

		return new WP_Error( 'llm_unavailable', 'bizcity_llm_chat() is not available' );
	}

	/**
	 * Stream LLM response for a single chunk, sending SSE deltas.
	 */
	private static function stream_llm_chunk( string $system, string $user, int $chunk_index, int $chunk_id, array $opts = [] ): string {
		$full = '';

		// Build OpenAI-format messages
		$messages = [
			[ 'role' => 'system', 'content' => $system ],
			[ 'role' => 'user',   'content' => $user ],
		];

		$llm_opts = [
			'purpose'     => $opts['purpose'] ?? 'content_creation',
			'temperature' => (float) ( $opts['temperature'] ?? 0.7 ),
			'max_tokens'  => (int) ( $opts['max_tokens'] ?? 4000 ),
			'timeout'     => 120,
		];

		// Use bizcity-llm-router streaming if available
		if ( function_exists( 'bizcity_llm_chat_stream' ) ) {
			$result = bizcity_llm_chat_stream( $messages, $llm_opts, function ( $delta, $full_so_far ) use ( &$full, $chunk_index, $chunk_id ) {
				$full = $full_so_far;
				self::sse_send( 'chunk_delta', [
					'chunk_index' => $chunk_index,
					'chunk_id'    => $chunk_id,
					'delta'       => $delta,
				] );
			} );

			if ( ! empty( $result['success'] ) ) {
				if ( empty( $full ) ) {
					$full = $result['message'] ?? '';
				}
				return $full;
			}

			// LLM stream failed — send error event, return partial if any
			$err_msg = $result['error'] ?? 'LLM stream failed';
			error_log( '[BZCC SSE] Stream error chunk #' . $chunk_index . ': ' . $err_msg );
			self::sse_send( 'chunk_error', [
				'chunk_index' => $chunk_index,
				'chunk_id'    => $chunk_id,
				'error'       => $err_msg,
			] );
			return $full ?: ( '⚠️ ' . $err_msg );
		}

		// Fallback: non-streaming call, simulate streaming
		$content = self::call_llm( $system, $user, $opts );
		if ( is_wp_error( $content ) ) {
			$err_msg = $content->get_error_message();
			error_log( '[BZCC SSE] LLM error chunk #' . $chunk_index . ': ' . $err_msg );
			self::sse_send( 'chunk_error', [
				'chunk_index' => $chunk_index,
				'chunk_id'    => $chunk_id,
				'error'       => $err_msg,
			] );
			return '⚠️ Không thể tạo nội dung: ' . $err_msg;
		}
		$full = $content;

		// Simulate streaming by sending in 200-char chunks
		$parts = str_split( $full, 200 );
		foreach ( $parts as $part ) {
			self::sse_send( 'chunk_delta', [
				'chunk_index' => $chunk_index,
				'chunk_id'    => $chunk_id,
				'delta'       => $part,
			] );
			usleep( 30000 ); // 30ms between chunks for smooth animation
		}

		return $full;
	}

	/**
	 * Parse outline response into flat sections array.
	 *
	 * Handles two formats:
	 * A) Direct array: [ {"label":"...", ...}, ... ]
	 * B) Wrapper object: { "title":"...", "sections": [ {"label":"...", ...}, ... ] }
	 *
	 * Each section is normalised to have at least: label, emoji, description, platform, stage.
	 */
	private static function parse_outline( string $response ): array {
		$parsed = null;

		// 1. Extract JSON from markdown code blocks (```json ... ```)
		if ( preg_match( '/```(?:json)?\s*([\[\{][\s\S]*?[\]\}])\s*```/', $response, $m ) ) {
			$parsed = json_decode( $m[1], true );
		}

		// 2. Try direct JSON parse
		if ( ! is_array( $parsed ) ) {
			$parsed = json_decode( $response, true );
		}

		// 3. Try to find any JSON block in response
		if ( ! is_array( $parsed ) ) {
			if ( preg_match( '/[\[\{][\s\S]*[\]\}]/', $response, $m ) ) {
				$parsed = json_decode( $m[0], true );
			}
		}

		if ( ! is_array( $parsed ) ) {
			return [];
		}

		// If it's a wrapper object with a "sections" key, extract that
		if ( isset( $parsed['sections'] ) && is_array( $parsed['sections'] ) ) {
			$parsed = $parsed['sections'];
		}

		// Must be a sequential (indexed) array of section objects
		if ( ! isset( $parsed[0] ) ) {
			return [];
		}

		// Flatten nested structures: sections may contain scripts[], items[], posts[]
		$flat = [];
		foreach ( $parsed as $sec ) {
			if ( ! is_array( $sec ) ) {
				continue;
			}
			// Check for nested child arrays (scripts, items, posts, children)
			$children_key = null;
			foreach ( [ 'scripts', 'items', 'posts', 'children', 'contents' ] as $key ) {
				if ( ! empty( $sec[ $key ] ) && is_array( $sec[ $key ] ) && isset( $sec[ $key ][0] ) ) {
					$children_key = $key;
					break;
				}
			}
			if ( $children_key ) {
				// Expand children — each child inherits parent-level platform/stage/content_type
				$parent_platform     = $sec['platform'] ?? '';
				$parent_stage        = $sec['stage'] ?? '';
				$parent_content_type = $sec['content_type'] ?? '';
				$parent_label        = $sec['label'] ?? $sec['title'] ?? '';
				foreach ( $sec[ $children_key ] as $child ) {
					if ( ! is_array( $child ) ) {
						continue;
					}
					$child['platform']     = $child['platform'] ?? $parent_platform;
					$child['stage']        = $child['stage'] ?? $parent_stage;
					$child['content_type'] = $child['content_type'] ?? $parent_content_type;
					// Prefix child label with parent context if child has no label
					if ( empty( $child['label'] ) && empty( $child['title'] ) ) {
						$child['label'] = $parent_label;
					}
					$flat[] = $child;
				}
			} else {
				$flat[] = $sec;
			}
		}

		// Normalise each section — map alternate key names
		$sections = [];
		foreach ( $flat as $i => $sec ) {
			$normalised = [
				'label'        => $sec['label'] ?? $sec['title'] ?? $sec['stage_label'] ?? ( 'Phần ' . ( $i + 1 ) ),
				'emoji'        => $sec['emoji'] ?? $sec['stage_emoji'] ?? '📝',
				'description'  => $sec['description'] ?? $sec['instructions'] ?? '',
				'platform'     => $sec['platform'] ?? '',
				'stage'        => $sec['stage'] ?? '',
				'stage_label'  => $sec['stage_label'] ?? $sec['label'] ?? '',
				'stage_emoji'  => $sec['stage_emoji'] ?? $sec['emoji'] ?? '📝',
				'content_type' => $sec['content_type'] ?? '',
				'word_count'   => (int) ( $sec['word_count'] ?? 200 ),
			];
			// Preserve all extra keys from outline (video_duration, video_format, script_style, etc.)
			foreach ( $sec as $k => $v ) {
				if ( ! isset( $normalised[ $k ] ) && ! is_array( $v ) ) {
					$normalised[ $k ] = $v;
				}
			}
			$sections[] = $normalised;
		}

		return $sections;
	}

	/* ── Persist chunk content to studio_outputs ── */

	/**
	 * Get platform-specific writing instructions.
	 *
	 * Tells the LLM HOW to format content for each platform so output
	 * is ready-to-publish instead of generic analysis.
	 */
	private static function get_platform_instructions( string $platform, string $content_type = '' ): string {
		$map = [
			'facebook' => implode( "\n", [
				'FORMAT: Bài đăng Facebook',
				'- Mở đầu bằng hook gây tò mò (1-2 câu thu hút, có thể dùng câu hỏi hoặc insight bất ngờ)',
				'- Thân bài: 3-5 đoạn ngắn, mỗi đoạn 2-3 câu, xen kẽ emoji phù hợp',
				'- Dùng xuống dòng nhiều để dễ đọc trên mobile',
				'- Kết thúc bằng CTA rõ ràng (comment, inbox, click link...)',
				'- Thêm 3-5 hashtag phù hợp ở cuối',
			] ),
			'instagram' => implode( "\n", [
				'FORMAT: Caption Instagram / Infographic',
				'- Hook: 1 câu ngắn gây chú ý (có thể ALL CAPS hoặc emoji mở đầu)',
				'- Nội dung: Chia thành các điểm đánh số hoặc bullet ngắn gọn',
				'- Mỗi point tối đa 1-2 câu, dễ chuyển thành slide carousel',
				'- Tone: Trẻ trung, visual-friendly, dùng emoji tự nhiên',
				'- CTA: "Save lại", "Share cho bạn bè", "Follow để xem thêm"',
				'- 10-15 hashtag phổ biến + niche ở cuối',
			] ),
			'tiktok' => implode( "\n", [
				'FORMAT: Script video TikTok/Reels (15-60 giây)',
				'- HOOK (3 giây đầu): Câu mở gây shock/tò mò, VD: "Mẹ bỉm ơi, đừng mắc sai lầm này!"',
				'- BODY: 3-5 điểm chính, mỗi điểm 1-2 câu nói ngắn gọn',
				'- Viết dạng KỊCH BẢN NÓI, không phải văn viết. Dùng ngôn ngữ đời thường.',
				'- CTA (3 giây cuối): "Follow để biết thêm", "Comment số 1 để nhận..."',
				'- Gợi ý hành động trên màn hình: [Chỉ tay], [Zoom vào sản phẩm], [Text overlay]',
			] ),
			'youtube' => implode( "\n", [
				'FORMAT: Script YouTube Short (< 60 giây)',
				'- Hook mạnh trong 3 giây đầu',
				'- Nội dung: Chia 3-5 scene, mỗi scene có mô tả hành động ngắn',
				'- Kết bằng CTA: Subscribe, Like, xem video dài hơn',
			] ),
			'zalo' => implode( "\n", [
				'FORMAT: Tin nhắn Zalo OA / SMS Broadcast',
				'- Ngắn gọn: Tối đa 500 ký tự',
				'- Mở đầu: Chào + tên thương hiệu',
				'- Thân: 1-2 câu giá trị chính, 1 ưu đãi/thông tin hấp dẫn',
				'- CTA: Link hoặc hành động cụ thể (Nhắn "OK" để nhận, Click link...)',
				'- Tone: Thân thiện, gần gũi, không quá formal',
			] ),
			'email' => implode( "\n", [
				'FORMAT: Email Marketing',
				'- Subject line: Ngắn gọn, gây tò mò (< 50 ký tự)',
				'- Preview text: 1 câu bổ sung cho subject',
				'- Body: Chào hỏi ngắn → Vấn đề/insight → Giải pháp → CTA button',
				'- Mỗi đoạn tối đa 2-3 câu, có spacing rõ ràng',
				'- CTA: 1 nút chính rõ ràng (Click here, Đăng ký ngay, Nhận ưu đãi)',
				'- P.S. line (tùy chọn): Nhắc lại urgency hoặc bonus',
			] ),
			'website' => implode( "\n", [
				'FORMAT: Bài viết Blog / Landing page',
				'- Tiêu đề: Hấp dẫn, chứa keyword, có thể dùng số hoặc câu hỏi',
				'- Intro: 2-3 câu nêu vấn đề, tạo sự đồng cảm, hứa hẹn giải pháp',
				'- Body: Chia H2/H3 rõ ràng, mỗi section có bullet points hoặc numbered list',
				'- Viết dạng bài đăng blog, KHÔNG phải phân tích chiến lược marketing',
				'- CTA cuối bài: Kêu gọi hành động cụ thể',
			] ),
			'image' => implode( "\n", [
				'FORMAT: Mô tả ảnh quảng cáo / Creative brief',
				'- Headline: Ngắn, mạnh (< 10 từ)',
				'- Subheadline: 1 câu bổ sung',
				'- Body copy: 2-3 bullet ngắn',
				'- CTA text trên ảnh',
				'- Mô tả visual: Bố cục, màu sắc, hình ảnh gợi ý',
			] ),
		];

		return $map[ $platform ] ?? "FORMAT: Nội dung marketing\n- Viết dạng sẵn sàng đăng, không phải phân tích chiến lược";
	}

	/**
	 * Build the enhanced chunk prompt with platform context and content enforcement.
	 */
	private static function build_enhanced_chunk_prompt(
		string $base_prompt,
		string $label,
		string $description,
		string $platform,
		string $stage,
		string $word_count,
		int $section_index,
		int $total_sections,
		string $prev_content = '',
		array $section_info = []
	): string {
		$prompt = $base_prompt;

		// Standard placeholder substitution
		$prompt = str_replace( '{{chunk_title}}', $label, $prompt );
		$prompt = str_replace( '{{outline_item}}', $description, $prompt );
		$prompt = str_replace( '{{word_count}}', $word_count, $prompt );
		$prompt = str_replace( '{{section_label}}', $label, $prompt );
		$prompt = str_replace( '{{section_index}}', (string) $section_index, $prompt );
		$prompt = str_replace( '{{section_total}}', (string) $total_sections, $prompt );
		$prompt = str_replace( '{{section_description}}', $description, $prompt );
		$prompt = str_replace( '{{previous_content}}', $prev_content, $prompt );
		$prompt = str_replace( '{{platform}}', $platform, $prompt );
		$prompt = str_replace( '{{stage}}', $stage, $prompt );

		// Replace extra outline fields from section_info (video_duration, video_format, script_style, etc.)
		foreach ( $section_info as $key => $val ) {
			if ( is_string( $val ) || is_numeric( $val ) ) {
				$prompt = str_replace( '{{' . $key . '}}', (string) $val, $prompt );
			}
		}

		// Remove any remaining unresolved {{...}} placeholders
		$prompt = preg_replace( '/\{\{[a-zA-Z_][a-zA-Z0-9_]*\}\}/', '', $prompt );

		// Inject platform-specific format instructions
		$platform_guide = self::get_platform_instructions( $platform );

		$prompt .= "\n\n" . $platform_guide;

		$prompt .= "\n\nQUY TẮC BẮT BUỘC:"
			. "\n- Viết NỘI DUNG THỰC TẾ sẵn sàng đăng/gửi, KHÔNG viết phân tích chiến lược hay nghiên cứu thị trường"
			. "\n- Nội dung phải KHÁC BIỆT hoàn toàn so với các phần khác trong cùng chiến dịch"
			. "\n- Tiêu đề bài viết phải bám sát: \"" . $label . "\""
			. "\n- Nền tảng: " . $platform . " — tuân thủ format " . $platform . " ở trên"
			. "\n- Độ dài: khoảng " . $word_count . " từ";

		return $prompt;
	}

	/**
	 * Strip prompt preamble that some LLMs echo back.
	 * Removes known instruction headers and content before the real output starts.
	 */
	private static function strip_prompt_preamble( string $content ): string {
		// Remove known prompt headers echoed by LLM
		$headers = [
			'System Prompt:', 'Chunk Prompt:', 'Outline Prompt:',
			'YÊU CẦU:', 'QUY TẮC:', 'QUY TẮC BẮT BUỘC:', 'QUY TẮC VIẾT BẮT BUỘC:',
			'BỐI CẢNH:', 'FORMAT:', 'NHẮC LẠI:',
		];

		// Pattern: find everything from start up to (and including) the last instruction header block
		// Then keep only the content after it
		$pattern = '/^.*?(?:' . implode( '|', array_map( 'preg_quote', $headers ) ) . ').*?(?=\n(?:\d+\.|#{1,4}\s|[-*•]\s|\*\*)|$)/su';

		// Try to find where actual content starts (first heading, numbered list, or bullet)
		if ( preg_match( '/\n(\d+\.\s|\#{1,4}\s|[-*•]\s|\*\*)/su', $content, $m, PREG_OFFSET_CAPTURE ) ) {
			$actual_start = $m[0][1];
			// Check if any instruction header exists before this point
			$prefix = substr( $content, 0, $actual_start );
			$has_prompt_echo = false;
			foreach ( $headers as $h ) {
				if ( mb_stripos( $prefix, $h ) !== false ) {
					$has_prompt_echo = true;
					break;
				}
			}
			if ( $has_prompt_echo ) {
				$content = ltrim( substr( $content, $actual_start ) );
				error_log( '[BZCC] strip_prompt_preamble: removed ' . $actual_start . ' chars of preamble' );
			}
		}

		return $content;
	}

	private static function save_chunk_content( int $chunk_id, string $content, string $label ): void {
		// Strip any prompt preamble the LLM may have echoed
		$content = self::strip_prompt_preamble( $content );

		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_webchat_studio_outputs';

		$wpdb->insert( $table, [
			'user_id'        => get_current_user_id(),
			'caller'         => 'content_creator',
			'tool_type'      => 'content',
			'title'          => mb_substr( $label, 0, 255 ),
			'content'        => $content,
			'content_format' => 'markdown',
			'token_count'    => str_word_count( $content ),
			'status'         => 'ready',
			'created_at'     => current_time( 'mysql' ),
		] );

		$output_id = (int) $wpdb->insert_id;
		if ( $output_id ) {
			BZCC_Chunk_Meta_Manager::update( $chunk_id, [ 'studio_output_id' => $output_id ] );
		}

		error_log( '[BZCC] save_chunk_content chunk_id=' . $chunk_id . ' → output_id=' . $output_id . ' | len=' . strlen( $content ) );
	}

	/* ── SSE helper ── */

	private static function sse_send( string $event, array $data ): void {
		echo "event: {$event}\n";
		echo 'data: ' . wp_json_encode( $data ) . "\n\n";

		if ( function_exists( 'fastcgi_finish_request' ) ) {
			// Don't call it here — we're still streaming
		}
		@ob_flush();
		flush();
	}

	/* ═══════════════════════════════════════
	 *  GET /files — List user's files (history)
	 * ═══════════════════════════════════════ */
	public static function list_files( WP_REST_Request $request ): WP_REST_Response {
		$user_id = get_current_user_id();
		$status  = sanitize_key( $request->get_param( 'status' ) ?: '' );
		$search  = sanitize_text_field( $request->get_param( 'search' ) ?: '' );
		$limit   = min( 100, max( 1, (int) $request->get_param( 'limit' ) ) );
		$offset  = max( 0, (int) $request->get_param( 'offset' ) );

		$files = BZCC_File_Manager::search_by_user( $user_id, $status, $search, $limit, $offset );
		$total = BZCC_File_Manager::count_by_user( $user_id );

		$items = [];
		foreach ( $files as $f ) {
			$items[] = [
				'id'             => (int) $f->id,
				'title'          => $f->title ?: ( $f->template_title ?? 'File #' . (int) $f->id ),
				'status'         => $f->status,
				'template_title' => $f->template_title ?? '',
				'template_emoji' => $f->template_emoji ?? '📄',
				'category_name'  => $f->category_name ?? '',
				'chunk_count'    => (int) $f->chunk_count,
				'chunk_done'     => (int) $f->chunk_done,
				'platforms_csv'  => $f->platforms_csv ?? '',
				'created_at'     => $f->created_at,
				'updated_at'     => $f->updated_at,
			];
		}

		return new WP_REST_Response( [
			'files' => $items,
			'total' => $total,
		] );
	}

	/* ═══════════════════════════════════════
	 *  DELETE /file/{id} — Delete a file
	 * ═══════════════════════════════════════ */
	public static function delete_file( WP_REST_Request $request ): WP_REST_Response {
		$file_id = (int) $request->get_param( 'id' );
		$file    = BZCC_File_Manager::get_by_id( $file_id );

		if ( ! $file ) {
			return new WP_REST_Response( [ 'error' => 'File not found' ], 404 );
		}

		if ( (int) $file->user_id !== get_current_user_id() ) {
			return new WP_REST_Response( [ 'error' => 'Forbidden' ], 403 );
		}

		// Delete chunks first
		$chunk_ids = BZCC_Chunk_Meta_Manager::get_by_file( $file_id );
		foreach ( $chunk_ids as $ch ) {
			BZCC_Chunk_Meta_Manager::delete( (int) $ch->id );
		}

		BZCC_File_Manager::delete( $file_id );

		return new WP_REST_Response( [ 'success' => true ] );
	}

	/* ═══════════════════════════════════════
	 *  Template Import / Export / Duplicate
	 * ═══════════════════════════════════════ */

	/**
	 * Export columns — exclude internal IDs and counters.
	 */
	private static function template_export_data( object $tpl ): array {
		$data = (array) $tpl;
		unset( $data['id'], $data['author_id'], $data['use_count'], $data['created_at'], $data['updated_at'] );

		// Decode JSON columns so they export as arrays, not escaped strings
		foreach ( [ 'form_fields', 'wizard_steps', 'output_platforms', 'settings' ] as $col ) {
			if ( isset( $data[ $col ] ) && is_string( $data[ $col ] ) ) {
				$decoded = json_decode( $data[ $col ], true );
				if ( is_array( $decoded ) ) {
					$data[ $col ] = $decoded;
				}
			}
		}

		return $data;
	}

	/** GET /template/{id}/export */
	public static function export_template( WP_REST_Request $request ): WP_REST_Response {
		$tpl = BZCC_Template_Manager::get_by_id( (int) $request->get_param( 'id' ) );
		if ( ! $tpl ) {
			return new WP_REST_Response( [ 'error' => 'Template not found' ], 404 );
		}

		return new WP_REST_Response( [
			'version'   => '1.0',
			'plugin'    => 'bizcity-content-creator',
			'exported'  => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'templates' => [ self::template_export_data( $tpl ) ],
		] );
	}

	/** GET /templates/export */
	public static function export_all_templates( WP_REST_Request $request ): WP_REST_Response {
		$all    = BZCC_Template_Manager::get_all();
		$export = array_map( [ __CLASS__, 'template_export_data' ], $all );

		return new WP_REST_Response( [
			'version'   => '1.0',
			'plugin'    => 'bizcity-content-creator',
			'exported'  => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'templates' => $export,
		] );
	}

	/** POST /templates/import */
	public static function import_templates( WP_REST_Request $request ): WP_REST_Response {
		$body = $request->get_json_params();

		if ( empty( $body['templates'] ) || ! is_array( $body['templates'] ) ) {
			return new WP_REST_Response( [ 'error' => 'Invalid JSON: missing "templates" array' ], 400 );
		}

		$results  = [];
		$imported = 0;
		$skipped  = 0;
		$renamed  = 0;

		foreach ( $body['templates'] as $tpl_data ) {
			if ( ! is_array( $tpl_data ) || empty( $tpl_data['slug'] ) || empty( $tpl_data['title'] ) ) {
				$skipped++;
				continue;
			}

			// Encode JSON columns back to strings for DB
			foreach ( [ 'form_fields', 'wizard_steps', 'output_platforms', 'settings' ] as $col ) {
				if ( isset( $tpl_data[ $col ] ) && is_array( $tpl_data[ $col ] ) ) {
					$tpl_data[ $col ] = wp_json_encode( $tpl_data[ $col ], JSON_UNESCAPED_UNICODE );
				}
			}

			// Remove internal fields that shouldn't be imported
			unset( $tpl_data['id'], $tpl_data['use_count'], $tpl_data['created_at'], $tpl_data['updated_at'] );

			// Check if slug already exists — auto-rename with (1), (2), etc.
			$base_slug  = sanitize_title( $tpl_data['slug'] );
			$base_title = $tpl_data['title'];
			$existing   = BZCC_Template_Manager::get_by_slug( $base_slug );

			if ( $existing ) {
				$n = 1;
				do {
					$try_slug = $base_slug . '-' . $n;
					$clash    = BZCC_Template_Manager::get_by_slug( $try_slug );
					$n++;
				} while ( $clash );

				$tpl_data['slug']  = $try_slug;
				$tpl_data['title'] = $base_title . ' (' . ( $n - 1 ) . ')';
			}

			$new_id = BZCC_Template_Manager::insert( $tpl_data );
			$results[] = [ 'slug' => $tpl_data['slug'], 'action' => $existing ? 'renamed' : 'created', 'id' => $new_id ];

			if ( $existing ) {
				$renamed++;
			}
			$imported++;
		}

		return new WP_REST_Response( [
			'imported' => $imported,
			'renamed'  => $renamed,
			'skipped'  => $skipped,
			'results'  => $results,
		] );
	}

	/** POST /template/{id}/duplicate */
	public static function duplicate_template( WP_REST_Request $request ): WP_REST_Response {
		$id     = (int) $request->get_param( 'id' );
		$new_id = BZCC_Template_Manager::duplicate( $id );

		if ( ! $new_id ) {
			return new WP_REST_Response( [ 'error' => 'Template not found' ], 404 );
		}

		$new_tpl = BZCC_Template_Manager::get_by_id( $new_id );

		return new WP_REST_Response( [
			'success' => true,
			'id'      => $new_id,
			'title'   => $new_tpl ? $new_tpl->title : '',
			'slug'    => $new_tpl ? $new_tpl->slug : '',
		] );
	}
}