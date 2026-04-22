<?php
/**
 * BZDoc REST API — Document generation & CRUD endpoints.
 *
 * Architecture:
 *   User prompt → REST API → BizCity LLM Router → AI generates JSON schema
 *   → Return JSON to React frontend → Client-side convert to DOCX/PDF/PPTX/XLSX
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BZDoc_Rest_API {

	const NAMESPACE    = 'bzdoc/v1';
	const MAX_TOPIC    = 2000;
	const MAX_CONTEXT  = 50000;

	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	public static function register_routes() {
		/* Generate document JSON schema via AI */
		register_rest_route( self::NAMESPACE, '/generate', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_generate' ],
			'permission_callback' => [ __CLASS__, 'check_auth' ],
		] );

		/* Edit existing document via chat */
		register_rest_route( self::NAMESPACE, '/edit', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_edit' ],
			'permission_callback' => [ __CLASS__, 'check_auth' ],
		] );

		/* Save document to DB */
		register_rest_route( self::NAMESPACE, '/save', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_save' ],
			'permission_callback' => [ __CLASS__, 'check_auth' ],
		] );

		/* List user documents */
		register_rest_route( self::NAMESPACE, '/list', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'handle_list' ],
			'permission_callback' => [ __CLASS__, 'check_auth' ],
		] );

		/* Get single document */
		register_rest_route( self::NAMESPACE, '/get/(?P<id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'handle_get' ],
			'permission_callback' => [ __CLASS__, 'check_auth' ],
		] );

		/* ── Source management ── */

		/* Upload source file */
		register_rest_route( self::NAMESPACE, '/source/upload', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_source_upload' ],
			'permission_callback' => [ __CLASS__, 'check_auth' ],
		] );

		/* Embed a source */
		register_rest_route( self::NAMESPACE, '/source/embed', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_source_embed' ],
			'permission_callback' => [ __CLASS__, 'check_auth' ],
		] );

		/* List sources for a document */
		register_rest_route( self::NAMESPACE, '/source/list/(?P<doc_id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'handle_source_list' ],
			'permission_callback' => [ __CLASS__, 'check_auth' ],
		] );

		/* Delete a source */
		register_rest_route( self::NAMESPACE, '/source/delete', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_source_delete' ],
			'permission_callback' => [ __CLASS__, 'check_auth' ],
		] );

		/* Create project (draft document) */
		register_rest_route( self::NAMESPACE, '/project/create', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_project_create' ],
			'permission_callback' => [ __CLASS__, 'check_auth' ],
		] );
	}

	public static function check_auth() {
		return is_user_logged_in();
	}

	/* ═══════════════════════════════════════════════
	   GENERATE — AI creates document JSON schema
	   ═══════════════════════════════════════════════ */
	public static function handle_generate( \WP_REST_Request $request ) {
		@set_time_limit( 0 ); // LLM calls can take 60-120s

		$user_id   = get_current_user_id();
		$doc_type  = sanitize_text_field( $request->get_param( 'doc_type' ) ?: 'document' );
		$topic     = sanitize_textarea_field( $request->get_param( 'topic' ) ?: '' );
		$template  = sanitize_text_field( $request->get_param( 'template_name' ) ?: 'blank' );
		$theme     = sanitize_text_field( $request->get_param( 'theme_name' ) ?: 'modern' );
		$slide_count = absint( $request->get_param( 'slide_count' ) ?: 10 );
		$doc_id    = absint( $request->get_param( 'doc_id' ) ?: 0 );

		if ( empty( $topic ) && $template === 'blank' ) {
			return new \WP_Error( 'missing_topic', 'Topic or template is required.', [ 'status' => 400 ] );
		}

		if ( strlen( $topic ) > self::MAX_TOPIC ) {
			return new \WP_Error( 'topic_too_long', 'Topic exceeds maximum length.', [ 'status' => 400 ] );
		}

		// Build source context if project has uploaded reference documents
		$source_context = '';
		if ( $doc_id > 0 ) {
			$source_context = self::build_source_context( $doc_id, $topic );
		}

		// Select system prompt based on doc_type
		$system_prompt = self::get_system_prompt( $doc_type );
		$user_prompt   = self::get_user_prompt( $doc_type, $topic, $template, $theme, $slide_count, $source_context );

		// Call BizCity LLM Router
		$ai_response = self::call_llm( $system_prompt, $user_prompt );

		if ( is_wp_error( $ai_response ) ) {
			return $ai_response;
		}

		// Spreadsheet returns CSV, not JSON
		if ( $doc_type === 'spreadsheet' ) {
			$schema = [ 'content' => trim( $ai_response ) ];
		} else {
			// Parse JSON from AI response
			$schema = self::parse_ai_json( $ai_response );

			if ( is_wp_error( $schema ) ) {
				return $schema;
			}
		}

		// Ensure required fields
		$schema = self::ensure_defaults( $schema, $doc_type, $topic, $theme );

		if ( is_wp_error( $schema ) ) {
			return $schema;
		}

		// Auto-save to get a doc_id for URL persistence
		$title = $schema['metadata']['title'] ?? $schema['presentation_title'] ?? $topic;
		$saved = self::auto_save_document( $doc_id, $user_id, $doc_type, $title, $template, $theme, $schema );

		return rest_ensure_response( [
			'success' => true,
			'data'    => $schema,
			'doc_id'  => $saved,
		] );
	}

	/* ═══════════════════════════════════════════════
	   EDIT — Chat-based document editing
	   ═══════════════════════════════════════════════ */
	public static function handle_edit( \WP_REST_Request $request ) {
		@set_time_limit( 0 ); // LLM calls can take 60-120s

		$instruction  = sanitize_textarea_field( $request->get_param( 'instruction' ) ?: '' );
		$current_json = $request->get_param( 'current_json' );
		$doc_type     = sanitize_text_field( $request->get_param( 'doc_type' ) ?: 'document' );
		$theme        = sanitize_text_field( $request->get_param( 'theme_name' ) ?: 'modern' );

		if ( empty( $instruction ) || empty( $current_json ) ) {
			return new \WP_Error( 'missing_params', 'Instruction and current document are required.', [ 'status' => 400 ] );
		}

		$context_string = wp_json_encode( $current_json );
		if ( strlen( $context_string ) > self::MAX_CONTEXT ) {
			$context_string = substr( $context_string, 0, self::MAX_CONTEXT );
		}

		$system_prompt = self::get_system_prompt( $doc_type );
		$user_prompt   = self::get_edit_prompt( $doc_type, $instruction, $context_string, $theme );

		$ai_response = self::call_llm( $system_prompt, $user_prompt );

		if ( is_wp_error( $ai_response ) ) {
			return $ai_response;
		}

		// Spreadsheet returns CSV, not JSON
		if ( $doc_type === 'spreadsheet' ) {
			$schema = [ 'content' => trim( $ai_response ) ];
		} else {
			$schema = self::parse_ai_json( $ai_response );

			if ( is_wp_error( $schema ) ) {
				return $schema;
			}
		}

		$schema = self::ensure_defaults( $schema, $doc_type, '', $theme );

		if ( is_wp_error( $schema ) ) {
			return $schema;
		}

		return rest_ensure_response( [
			'success' => true,
			'data'    => $schema,
		] );
	}

	/* ═══════════════════════════════════════════════
	   SAVE / LIST / GET — CRUD operations
	   ═══════════════════════════════════════════════ */
	public static function handle_save( \WP_REST_Request $request ) {
		global $wpdb;
		$table   = $wpdb->prefix . 'bzdoc_documents';
		$user_id = get_current_user_id();

		$doc_id  = absint( $request->get_param( 'id' ) );
		$data    = [
			'user_id'       => $user_id,
			'doc_type'      => sanitize_text_field( $request->get_param( 'doc_type' ) ?: 'document' ),
			'title'         => sanitize_text_field( $request->get_param( 'title' ) ?: 'Untitled' ),
			'template_name' => sanitize_text_field( $request->get_param( 'template_name' ) ?: 'blank' ),
			'theme_name'    => sanitize_text_field( $request->get_param( 'theme_name' ) ?: 'modern' ),
			'schema_json'   => wp_json_encode( $request->get_param( 'schema_json' ) ),
			'status'        => 'draft',
			'updated_at'    => current_time( 'mysql' ),
		];

		if ( $doc_id > 0 ) {
			// Verify ownership
			$owner = $wpdb->get_var( $wpdb->prepare(
				"SELECT user_id FROM {$table} WHERE id = %d", $doc_id
			) );
			if ( (int) $owner !== $user_id ) {
				return new \WP_Error( 'forbidden', 'You do not own this document.', [ 'status' => 403 ] );
			}
			$result = $wpdb->update( $table, $data, [ 'id' => $doc_id ] );
			if ( $result === false ) {
				error_log( '[BZDoc] UPDATE failed: ' . $wpdb->last_error );
				return new \WP_Error( 'db_error', 'Failed to update document.', [ 'status' => 500 ] );
			}
		} else {
			$data['created_at'] = current_time( 'mysql' );
			$result = $wpdb->insert( $table, $data );
			if ( $result === false ) {
				error_log( '[BZDoc] INSERT failed: ' . $wpdb->last_error );
				return new \WP_Error( 'db_error', 'Failed to save document.', [ 'status' => 500 ] );
			}
			$doc_id = $wpdb->insert_id;
		}

		return rest_ensure_response( [ 'id' => $doc_id, 'success' => true ] );
	}

	public static function handle_list( \WP_REST_Request $request ) {
		global $wpdb;
		$table   = $wpdb->prefix . 'bzdoc_documents';
		$user_id = get_current_user_id();

		$doc_type = sanitize_text_field( $request->get_param( 'doc_type' ) ?: '' );
		$where    = $wpdb->prepare( "WHERE user_id = %d", $user_id );
		if ( $doc_type ) {
			$where .= $wpdb->prepare( " AND doc_type = %s", $doc_type );
		}

		$rows = $wpdb->get_results(
			"SELECT id, doc_type, title, template_name, theme_name, status, created_at, updated_at FROM {$table} {$where} ORDER BY updated_at DESC LIMIT 50"
		);

		return rest_ensure_response( $rows ?: [] );
	}

	public static function handle_get( \WP_REST_Request $request ) {
		global $wpdb;
		$table   = $wpdb->prefix . 'bzdoc_documents';
		$user_id = get_current_user_id();
		$doc_id  = absint( $request->get_param( 'id' ) );

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE id = %d AND user_id = %d", $doc_id, $user_id
		) );

		if ( ! $row ) {
			return new \WP_Error( 'not_found', 'Document not found.', [ 'status' => 404 ] );
		}

		$row->schema_json = json_decode( $row->schema_json, true );

		return rest_ensure_response( $row );
	}

	/* ═══════════════════════════════════════════════
	   LLM CALL — Streaming to avoid gateway 502 timeout
	   Uses bizcity_llm_chat_stream() internally but
	   accumulates full response (no SSE to browser).
	   ═══════════════════════════════════════════════ */
	private static function call_llm( string $system_prompt, string $user_prompt ) {
		$messages = [
			[ 'role' => 'system', 'content' => $system_prompt ],
			[ 'role' => 'user',   'content' => $user_prompt ],
		];

		$llm_opts = [
			'model'       => 'anthropic/claude-sonnet-4',
			'purpose'     => 'executor',
			'temperature' => 0.4,
			'max_tokens'  => 32000,
			'timeout'     => 180,
		];

		// Prefer streaming — keeps proxy connection alive, prevents 502
		if ( function_exists( 'bizcity_llm_chat_stream' ) ) {
			error_log( '[BZDoc] Using bizcity_llm_chat_stream()' );
			$full = '';
			$result = bizcity_llm_chat_stream( $messages, $llm_opts,
				function ( $delta, $full_so_far ) use ( &$full ) {
					$full = $full_so_far; // just accumulate, no SSE output
				}
			);

			if ( ! empty( $result['success'] ) ) {
				// Prefer $result['message'] (clean) over $full (from callback)
				$response = ! empty( $result['message'] ) ? $result['message'] : $full;
				error_log( '[BZDoc] Stream success. Response length: ' . strlen( $response ) . ' chars' );
				return $response;
			}

			error_log( '[BZDoc] Stream failed: ' . ( $result['error'] ?? 'unknown' ) );
			return new \WP_Error( 'llm_error', $result['error'] ?? 'LLM stream failed' );
		}

		// Fallback: blocking call (may 502 on slow responses)
		if ( function_exists( 'bizcity_llm_chat' ) ) {
			$result = bizcity_llm_chat( $messages, $llm_opts );

			if ( ! empty( $result['success'] ) ) {
				return $result['message'] ?? '';
			}

			return new \WP_Error( 'llm_error', $result['error'] ?? 'LLM call failed' );
		}

		return new \WP_Error( 'llm_unavailable', 'bizcity_llm_chat() is not available. Please ensure BizCity Twin AI core is active.' );
	}

	/* ═══════════════════════════════════════════════
	   SYSTEM PROMPTS — Per document type
	   ═══════════════════════════════════════════════ */
	private static function get_system_prompt( string $doc_type ): string {
		switch ( $doc_type ) {
			case 'presentation':
				return self::system_prompt_presentation();
			case 'spreadsheet':
				return self::system_prompt_spreadsheet();
			default:
				return self::system_prompt_document();
		}
	}

	private static function system_prompt_document(): string {
		return <<<'PROMPT'
You are a Professional Document Architect. You create expert-level Microsoft Word documents by outputting STRICT JSON.

You MUST write in THE SAME LANGUAGE as the user's topic/prompt. If the topic is in Vietnamese, write entirely in Vietnamese. If in English, write in English.

## OUTPUT SCHEMA

```
{
  "metadata": { "title": "string", "subject": "string", "author": "string" },
  "theme": {
    "name": "modern|classic|professional|creative|minimal",
    "font_name": "Times New Roman",
    "font_size": 13,
    "heading_font": "Times New Roman",
    "primary_color": "1F4E79",
    "secondary_color": "2E75B6"
  },
  "sections": [{
    "orientation": "portrait|landscape",
    "header": { "text": "string", "align": "left|center|right", "show_page_numbers": false },
    "footer": { "text": "Trang", "show_page_numbers": true },
    "elements": [
      { "type": "heading1", "text": "string", "alignment": "center" },
      { "type": "heading2", "text": "string", "alignment": "left" },
      { "type": "heading3", "text": "string" },
      { "type": "paragraph", "text": "string", "alignment": "justify" },
      { "type": "paragraph", "text_runs": [
        { "text": "Bold label: ", "bold": true },
        { "text": "Normal text follows", "bold": false }
      ] },
      { "type": "bullet_list", "items": ["Item 1", "Item 2"] },
      { "type": "numbered_list", "items": ["Step 1", "Step 2"] },
      { "type": "table", "style": "grid", "rows": [
        { "cells": ["Header 1", "Header 2", "Header 3"], "isHeader": true },
        { "cells": ["Data 1", "Data 2", "Data 3"] }
      ] },
      { "type": "divider" }
    ]
  }]
}
```

## DOCUMENT QUALITY STANDARDS

1. **Structure**: Follow professional report/academic structure:
   - Title page elements (heading1 centered + metadata)
   - Table of contents hint (heading2 for each major section)
   - Introduction/Background → Main body → Analysis → Conclusion/Recommendations
   - Each major section: heading2 → context paragraph → detailed sub-sections (heading3) → evidence/data

2. **Content depth**: Each section MUST have:
   - Opening paragraph explaining the section's purpose (2-3 sentences)
   - Detailed sub-points with heading3 for each
   - Supporting data: tables, numbered lists, concrete examples
   - TOTAL document: 40-60 elements (keep JSON compact to avoid truncation)

3. **Formatting richness**:
   - Use text_runs for inline formatting: bold key terms, italic for emphasis
   - Tables with 3-5 columns and 4-10 data rows (realistic data)
   - Mix paragraph, bullet_list, numbered_list, table in every section
   - Use divider between major sections

4. **Professional tone**: Formal language, specific data points, concrete recommendations

## CRITICAL RULES
1. ONLY output valid JSON — no markdown, no explanations, no code blocks
2. All string values must use proper JSON escaping (no raw newlines inside strings)
3. Colors are hex WITHOUT # prefix (e.g., "1F4E79")
4. font_size is number (e.g., 13), NOT string
5. Every heading must follow hierarchy: heading1 > heading2 > heading3
6. Tables MUST have isHeader: true on first row
7. Generate COMPREHENSIVE content — a full professional document, NOT a skeleton
PROMPT;
	}

	private static function system_prompt_presentation(): string {
		return <<<'PROMPT'
You are a Professional Presentation Architect. You create expert-level PowerPoint decks by outputting STRICT JSON.

You MUST write in THE SAME LANGUAGE as the user's topic. If Vietnamese, write in Vietnamese. If English, write in English.

## OUTPUT SCHEMA

```
{
  "presentation_title": "string",
  "slides": [{
    "slide_layout": "title_slide|content_slide|section_header|two_column|image_slide",
    "title": "string",
    "subtitle": "string (only for title_slide)",
    "bullets": [{ "content": "string", "level": 0 }],
    "notes": "string (speaker notes — 3-5 sentences)"
  }]
}
```

## QUALITY STANDARDS

1. **Structure**: title_slide → section_header → 2-4 content slides per section → closing slide
2. **Content**: Each bullet is a complete insight, not a vague phrase. Use data, percentages, specifics.
3. **Notes**: Speaker notes MUST be detailed talking points (3-5 sentences each)
4. **Balance**: 3-5 bullets per content slide, level 0 = main point, level 1 = supporting detail
5. **Visual variety**: Mix content_slide, two_column, section_header. Don't repeat same layout.

## CRITICAL RULES
1. ONLY output valid JSON — no markdown, no code blocks
2. All strings must use proper JSON escaping
3. First slide = title_slide with title + subtitle
4. Last slide = closing/thank-you slide
5. Generate 10-20 slides with real, substantive content
PROMPT;
	}

	private static function system_prompt_spreadsheet(): string {
		return <<<'PROMPT'
You are a Professional Spreadsheet Architect. You generate CSV data for Excel.

You MUST write in THE SAME LANGUAGE as the user's topic.

Output STRICT CSV format — no markdown, no code blocks, no explanations.

RULES:
1. First row = column headers (clear, descriptive names)
2. Generate 15-50 rows of realistic, diverse data
3. Include calculated columns (totals, percentages, formulas) where appropriate
4. Use comma as delimiter
5. Wrap cells containing commas in double quotes
6. Numbers should be realistic (not round numbers — use decimals, varied ranges)
7. Dates in YYYY-MM-DD format
8. ONLY output CSV, nothing else
PROMPT;
	}

	/* ═══════════════════════════════════════════════
	   USER PROMPTS — Per doc type & mode
	   ═══════════════════════════════════════════════ */
	private static function get_user_prompt( string $doc_type, string $topic, string $template, string $theme, int $slide_count, string $source_context = '' ): string {
		$template_guide = self::get_template_structure( $template );

		// Build source reference block if sources are provided
		$source_block = '';
		if ( ! empty( $source_context ) ) {
			$source_block = <<<SOURCES

=== TÀI LIỆU THAM KHẢO (Reference Documents) ===
Analyze the following reference materials carefully. Use their structure, data, style, and content as the basis for generating the document.
Extract key themes, data points, formatting patterns, and domain-specific terminology from these sources.

{$source_context}
=== HẾT TÀI LIỆU THAM KHẢO ===

SOURCES;
		}

		switch ( $doc_type ) {
			case 'presentation':
				return <<<PROMPT
{$source_block}
Create a professional, comprehensive presentation about: {$topic}

Requirements:
- Generate {$slide_count} slides minimum
- title_slide first, closing slide last
- Each content slide: 3-5 substantive bullets with real data/insights
- Speaker notes: 3-5 sentences of detailed talking points per slide
- Mix layouts: content_slide, two_column, section_header
- Content must be specific, data-driven, actionable — NOT generic

Return ONLY the complete JSON.
PROMPT;

			case 'spreadsheet':
				return <<<PROMPT
{$source_block}
Create a professional spreadsheet about: {$topic}

Requirements:
- Clear, descriptive column headers
- 20-40 rows of realistic, varied data
- Include summary/calculated columns (totals, %, growth rate)
- Numbers must be realistic (not round)
- Dates in YYYY-MM-DD format

Return ONLY CSV data.
PROMPT;

			default:
				return <<<PROMPT
{$source_block}
Create a comprehensive, professional document about: {$topic}

Theme: {$theme}
{$template_guide}

REQUIREMENTS — Follow these strictly:
1. **Length**: Generate 40-60 elements total. This is a professional document — concise but thorough.
2. **Structure**: Use heading1 for document title (centered), heading2 for major sections, heading3 for sub-sections.
3. **Content depth**: Each heading2 section needs:
   - Opening paragraph (3-5 sentences explaining context)
   - 2-3 heading3 sub-sections with detailed content
   - Supporting elements: tables (3+ columns, 4+ rows), numbered_list, bullet_list
   - Use text_runs for inline bold/italic formatting on key terms
4. **Data & Evidence**: Include specific numbers, percentages, dates, examples. NOT vague statements.
5. **Tables**: Every major section should have at least one table with realistic data.
6. **Professional formatting**: Use divider between major sections. Justify paragraph alignment.
7. **Conclusion**: End with concrete recommendations or action items.

Return ONLY the complete JSON document.
PROMPT;
		}
	}

	private static function get_edit_prompt( string $doc_type, string $instruction, string $context, string $theme ): string {
		if ( $doc_type === 'presentation' ) {
			return "Current presentation:\n{$context}\n\nUser request: {$instruction}\n\nUpdate the presentation based on the request. Return the COMPLETE updated JSON with changes applied. Output ONLY valid JSON.";
		}

		if ( $doc_type === 'spreadsheet' ) {
			return "Current spreadsheet data (CSV):\n{$context}\n\nUser request: {$instruction}\n\nUpdate the spreadsheet. Return updated CSV data only.";
		}

		return "CURRENT DOCUMENT JSON:\n{$context}\n\nUSER'S EDIT REQUEST: \"{$instruction}\"\n\nINSTRUCTIONS:\n1. Apply the specific changes requested\n2. Keep all other content unchanged\n3. Return the COMPLETE updated JSON\n4. Output ONLY valid JSON, no explanations";
	}

	/* ═══════════════════════════════════════════════
	   TEMPLATE STRUCTURES
	   ═══════════════════════════════════════════════ */
	private static function get_template_structure( string $template ): string {
		$templates = [
			'report' => "Template: BÁO CÁO CHUYÊN NGHIỆP\nStructure: Trang bìa → Mục lục → Tóm tắt điều hành → Giới thiệu → Phân tích hiện trạng → Phân tích chi tiết (2-3 phần) → Kết quả/Đánh giá → Đề xuất/Khuyến nghị → Kết luận → Phụ lục",
			'proposal' => "Template: ĐỀ XUẤT/PROPOSAL\nStructure: Trang bìa → Tóm tắt → Bối cảnh & Vấn đề → Giải pháp đề xuất → Phạm vi công việc → Kế hoạch triển khai (timeline + bảng) → Ngân sách chi tiết (bảng) → Rủi ro & Giảm thiểu → Lợi ích kỳ vọng → Kết luận",
			'contract' => "Template: HỢP ĐỒNG\nStructure: Tiêu đề hợp đồng → Thông tin các bên → Căn cứ pháp lý → Điều khoản chung → Phạm vi công việc → Giá trị & Thanh toán → Quyền & Nghĩa vụ → Bảo mật → Vi phạm & Xử lý → Điều khoản chung → Chữ ký",
			'resume' => "Template: CV/HỒ SƠ\nStructure: Thông tin cá nhân → Mục tiêu nghề nghiệp → Kinh nghiệm làm việc → Học vấn → Kỹ năng → Chứng chỉ → Dự án nổi bật → Tham chiếu",
			'invoice' => "Template: HÓA ĐƠN\nStructure: Logo/Header → Thông tin người bán → Thông tin người mua → Bảng chi tiết sản phẩm/dịch vụ → Tổng cộng → Điều khoản thanh toán → Ghi chú",
			'meeting' => "Template: BIÊN BẢN HỌP\nStructure: Tiêu đề cuộc họp → Thông tin (ngày, địa điểm, thành phần) → Nội dung thảo luận → Quyết định → Phân công nhiệm vụ (bảng: người - việc - deadline) → Hẹn cuộc họp tiếp theo",
			'blank' => '',
		];

		return $templates[ $template ] ?? '';
	}

	/* ═══════════════════════════════════════════════
	   HELPERS
	   ═══════════════════════════════════════════════ */
	private static function parse_ai_json( string $content ) {
		// Log raw length for debugging truncation
		$raw_len = strlen( $content );
		error_log( '[BZDoc] AI response length: ' . $raw_len . ' chars' );

		// Clean JSON from AI response — strip markdown fences
		$content = preg_replace( '/^```(?:json)?\s*/m', '', $content );
		$content = preg_replace( '/\s*```\s*$/m', '', $content );
		$content = trim( $content );

		// Strip control characters that break json_decode
		$content = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $content );

		$data = json_decode( $content, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			// Retry: extract outermost { ... }
			if ( preg_match( '/\{[\s\S]*\}/u', $content, $m ) ) {
				$extracted = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $m[0] );
				$data = json_decode( $extracted, true );
			}
		}

		// Truncation recovery: if JSON is cut off mid-stream, try to close it
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			$repaired = self::repair_truncated_json( $content );
			if ( $repaired ) {
				$data = json_decode( $repaired, true );
			}
		}

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			error_log( '[BZDoc] JSON parse error: ' . json_last_error_msg() . ' — first 800 chars: ' . substr( $content, 0, 800 ) );
			error_log( '[BZDoc] JSON parse error — last 300 chars: ' . substr( $content, -300 ) );
			return new \WP_Error( 'parse_error', 'Failed to parse AI response: ' . json_last_error_msg(), [ 'status' => 500 ] );
		}

		return $data;
	}

	/**
	 * Attempt to repair truncated JSON by closing unclosed brackets/braces.
	 * Handles mid-string truncation common with Vietnamese text hitting token limits.
	 */
	private static function repair_truncated_json( string $json ): ?string {
		// Find the first {
		$start = strpos( $json, '{' );
		if ( $start === false ) return null;
		$json = substr( $json, $start );

		// Pass 1: walk through tracking string state and bracket stack
		$len          = strlen( $json );
		$in_string    = false;
		$escape       = false;
		$string_start = -1; // byte offset where current open string began
		$stack        = []; // open brackets

		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $json[ $i ];
			if ( $escape )    { $escape = false; continue; }
			if ( $ch === '\\' && $in_string ) { $escape = true; continue; }
			if ( $ch === '"' ) {
				if ( ! $in_string ) {
					$in_string    = true;
					$string_start = $i;
				} else {
					$in_string = false;
				}
				continue;
			}
			if ( $in_string ) continue;
			if ( $ch === '{' || $ch === '[' ) {
				$stack[] = $ch;
			} elseif ( $ch === '}' || $ch === ']' ) {
				if ( ! empty( $stack ) ) array_pop( $stack );
			}
		}

		// If brackets are balanced and not inside a string, nothing to repair
		if ( empty( $stack ) && ! $in_string ) return null;

		// If we ended inside a string, truncate back to before that string
		if ( $in_string && $string_start >= 0 ) {
			$json = substr( $json, 0, $string_start );
		}

		// Clean trailing partial tokens: commas, colons, whitespace
		$json = rtrim( $json, " \t\n\r\0\x0B,:" );

		// Pass 2: recount brackets on the cleaned string
		$stack     = [];
		$in_string = false;
		$escape    = false;
		$new_len   = strlen( $json );
		for ( $i = 0; $i < $new_len; $i++ ) {
			$ch = $json[ $i ];
			if ( $escape )    { $escape = false; continue; }
			if ( $ch === '\\' && $in_string ) { $escape = true; continue; }
			if ( $ch === '"' ) { $in_string = ! $in_string; continue; }
			if ( $in_string ) continue;
			if ( $ch === '{' || $ch === '[' ) {
				$stack[] = $ch;
			} elseif ( $ch === '}' || $ch === ']' ) {
				if ( ! empty( $stack ) ) array_pop( $stack );
			}
		}

		// Close remaining open brackets in reverse order
		$suffix = '';
		foreach ( array_reverse( $stack ) as $open ) {
			$suffix .= ( $open === '{' ) ? '}' : ']';
		}

		if ( empty( $suffix ) ) return null;

		error_log( '[BZDoc] Repaired truncated JSON — removed ' . ( $len - $new_len ) . ' trailing chars, appended ' . strlen( $suffix ) . ' closing brackets' );
		return $json . $suffix;
	}

	private static function ensure_defaults( $schema, string $doc_type, string $topic, string $theme ) {
		if ( $doc_type === 'presentation' ) {
			if ( empty( $schema['presentation_title'] ) ) {
				$schema['presentation_title'] = $topic ?: 'Untitled Presentation';
			}
			if ( empty( $schema['slides'] ) || ! is_array( $schema['slides'] ) ) {
				return new \WP_Error( 'invalid_schema', 'Invalid presentation structure.', [ 'status' => 500 ] );
			}
			return $schema;
		}

		if ( $doc_type === 'spreadsheet' ) {
			// Spreadsheet returns CSV string, wrap in object
			if ( is_string( $schema ) ) {
				return [ 'content' => $schema ];
			}
			return $schema;
		}

		// Document defaults
		if ( empty( $schema['metadata']['title'] ) ) {
			$schema['metadata']          = $schema['metadata'] ?? [];
			$schema['metadata']['title'] = $topic ?: 'Untitled Document';
		}

		if ( empty( $schema['theme'] ) ) {
			$schema['theme'] = [
				'name'            => $theme,
				'font_name'       => 'Calibri',
				'font_size'       => 11,
				'primary_color'   => '2563EB',
				'secondary_color' => '64748B',
			];
		}

		if ( empty( $schema['sections'] ) || ! is_array( $schema['sections'] ) ) {
			return new \WP_Error( 'invalid_schema', 'Invalid document structure: missing sections.', [ 'status' => 500 ] );
		}

		return $schema;
	}

	/* ═══════════════════════════════════════════════
	   AUTO-SAVE — Create/update project on generate
	   ═══════════════════════════════════════════════ */
	private static function auto_save_document( int $doc_id, int $user_id, string $doc_type, string $title, string $template, string $theme, $schema ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'bzdoc_documents';

		$data = [
			'user_id'       => $user_id,
			'doc_type'      => $doc_type,
			'title'         => sanitize_text_field( $title ),
			'template_name' => $template,
			'theme_name'    => $theme,
			'schema_json'   => wp_json_encode( $schema ),
			'status'        => 'draft',
			'updated_at'    => current_time( 'mysql' ),
		];

		if ( $doc_id > 0 ) {
			// Verify ownership
			$owner = $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$table} WHERE id = %d", $doc_id ) );
			if ( (int) $owner === $user_id ) {
				$wpdb->update( $table, $data, [ 'id' => $doc_id ] );
				return $doc_id;
			}
		}

		// Insert new
		$data['created_at'] = current_time( 'mysql' );
		$wpdb->insert( $table, $data );
		return (int) $wpdb->insert_id;
	}

	/* ═══════════════════════════════════════════════
	   SOURCE CONTEXT — Build RAG context from sources
	   ═══════════════════════════════════════════════ */
	private static function build_source_context( int $doc_id, string $topic ): string {
		$sources = BZDoc_Sources::list_by_doc( $doc_id );
		if ( empty( $sources ) ) return '';

		// Check if any sources are embedded
		$has_embeddings = false;
		foreach ( $sources as $s ) {
			if ( $s['embedding_status'] === 'done' && $s['chunk_count'] > 0 ) {
				$has_embeddings = true;
				break;
			}
		}

		// Use semantic search if embeddings available
		if ( $has_embeddings && ! empty( $topic ) ) {
			$context = BZDoc_Embedder::build_context( $topic, $doc_id, 6000 );
			if ( ! empty( $context ) ) return $context;
		}

		// Fallback: all source content directly
		return BZDoc_Sources::get_all_content( $doc_id, 24000 );
	}

	/* ═══════════════════════════════════════════════
	   SOURCE ENDPOINTS
	   ═══════════════════════════════════════════════ */

	public static function handle_project_create( \WP_REST_Request $request ) {
		global $wpdb;
		$table   = $wpdb->prefix . 'bzdoc_documents';
		$user_id = get_current_user_id();

		$doc_type = sanitize_text_field( $request->get_param( 'doc_type' ) ?: 'document' );
		$title    = sanitize_text_field( $request->get_param( 'title' ) ?: 'Untitled' );

		$wpdb->insert( $table, [
			'user_id'       => $user_id,
			'doc_type'      => $doc_type,
			'title'         => $title,
			'template_name' => 'blank',
			'theme_name'    => 'modern',
			'schema_json'   => '{}',
			'status'        => 'draft',
			'created_at'    => current_time( 'mysql' ),
			'updated_at'    => current_time( 'mysql' ),
		] );

		$doc_id = (int) $wpdb->insert_id;

		return rest_ensure_response( [ 'success' => true, 'doc_id' => $doc_id ] );
	}

	public static function handle_source_upload( \WP_REST_Request $request ) {
		$doc_id = absint( $request->get_param( 'doc_id' ) );
		if ( ! $doc_id ) {
			return new \WP_Error( 'missing_doc_id', 'doc_id is required.', [ 'status' => 400 ] );
		}

		$files = $request->get_file_params();
		if ( empty( $files['file'] ) ) {
			return new \WP_Error( 'no_file', 'No file uploaded.', [ 'status' => 400 ] );
		}

		$source_id = BZDoc_Sources::upload( $doc_id, $files['file'] );
		if ( is_wp_error( $source_id ) ) {
			return $source_id;
		}

		$source = BZDoc_Sources::get( $source_id );

		// Embed async — schedule via wp_schedule_single_event if available,
		// otherwise skip (user can trigger manually or it runs at generate time).
		if ( function_exists( 'wp_schedule_single_event' ) ) {
			wp_schedule_single_event( time(), 'bzdoc_embed_source', [ $source_id ] );
		}

		return rest_ensure_response( [
			'success'  => true,
			'source'   => [
				'id'               => $source_id,
				'title'            => $source->title,
				'char_count'       => (int) $source->char_count,
				'token_estimate'   => (int) $source->token_estimate,
				'embedding_status' => 'pending',
				'chunk_count'      => 0,
			],
		] );
	}

	public static function handle_source_embed( \WP_REST_Request $request ) {
		$source_id = absint( $request->get_param( 'source_id' ) );
		if ( ! $source_id ) {
			return new \WP_Error( 'missing_source_id', 'source_id is required.', [ 'status' => 400 ] );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 300 );
		}

		$result = BZDoc_Embedder::embed_source( $source_id );

		if ( ! $result['success'] ) {
			return new \WP_Error( 'embed_error', $result['error'], [ 'status' => 500 ] );
		}

		return rest_ensure_response( [
			'success' => true,
			'chunks'  => $result['chunks'],
			'model'   => $result['model'] ?? '',
		] );
	}

	public static function handle_source_list( \WP_REST_Request $request ) {
		$doc_id = absint( $request->get_param( 'doc_id' ) );
		if ( ! $doc_id ) {
			return new \WP_Error( 'missing_doc_id', 'doc_id is required.', [ 'status' => 400 ] );
		}

		return rest_ensure_response( BZDoc_Sources::list_by_doc( $doc_id ) );
	}

	public static function handle_source_delete( \WP_REST_Request $request ) {
		$source_id = absint( $request->get_param( 'source_id' ) );
		if ( ! $source_id ) {
			return new \WP_Error( 'missing_source_id', 'source_id is required.', [ 'status' => 400 ] );
		}

		$ok = BZDoc_Sources::delete( $source_id );
		if ( ! $ok ) {
			return new \WP_Error( 'delete_error', 'Cannot delete source.', [ 'status' => 403 ] );
		}

		return rest_ensure_response( [ 'success' => true ] );
	}
}
