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
	const MAX_TOPIC    = 5000;
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

		/* Delete a source — accepts both DELETE and POST for hosting compatibility */
		register_rest_route( self::NAMESPACE, '/source/(?P<id>\d+)', [
			'methods'             => [ 'DELETE', 'POST' ],
			'callback'            => [ __CLASS__, 'handle_source_delete' ],
			'permission_callback' => [ __CLASS__, 'check_auth' ],
		] );

		/* Add URL source */
		register_rest_route( self::NAMESPACE, '/source/add-url', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_source_add_url' ],
			'permission_callback' => [ __CLASS__, 'check_auth' ],
		] );

		/* Add text (clipboard paste) source */
		register_rest_route( self::NAMESPACE, '/source/add-text', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_source_add_text' ],
			'permission_callback' => [ __CLASS__, 'check_auth' ],
		] );

		/* ── Web Search (Tavily via LLM Router gateway) ── */

		/* Synchronous web search */
		register_rest_route( self::NAMESPACE, '/source/search', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_search' ],
			'permission_callback' => [ __CLASS__, 'check_auth' ],
		] );

		/* Import selected search results as sources */
		register_rest_route( self::NAMESPACE, '/source/search/import', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_search_import' ],
			'permission_callback' => [ __CLASS__, 'check_auth' ],
		] );

		/* Create project (draft document) */
		register_rest_route( self::NAMESPACE, '/project/create', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_project_create' ],
			'permission_callback' => [ __CLASS__, 'check_auth' ],
		] );

		register_rest_route( self::NAMESPACE, '/generations', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'handle_list_generations' ],
			'permission_callback' => [ __CLASS__, 'check_auth' ],
		] );

		/* Get a single generation's snapshot (read-only preview) */
		register_rest_route( self::NAMESPACE, '/generation/(?P<gen_id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'handle_get_generation' ],
			'permission_callback' => [ __CLASS__, 'check_auth' ],
		] );

		/* Restore a generation snapshot */
		register_rest_route( self::NAMESPACE, '/generation/restore', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_restore_generation' ],
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

		// Sectional generation: multi-step pipeline for structured skeleton input
		$skeleton_json = $request->get_param( 'skeleton_json' );
		if ( is_array( $skeleton_json ) && $doc_type === 'document' && ! empty( $skeleton_json['skeleton'] ) ) {
			return self::generate_document_sectional( $request, $skeleton_json );
		}

		// Build source context if project has uploaded reference documents
		$source_context = '';
		$source_override = $request->get_param( 'source_context_override' );
		if ( ! empty( $source_override ) ) {
			$source_context = $source_override;
		} elseif ( $doc_id > 0 ) {
			$source_context = self::build_source_context( $doc_id, $topic );
		}

		// Log generation start
		$gen_id = self::log_generation( $doc_id, $user_id, 'generate', $topic );
		$start_time = microtime( true );

		// Select system prompt based on doc_type
		$system_prompt = self::get_system_prompt( $doc_type );
		$user_prompt   = self::get_user_prompt( $doc_type, $topic, $template, $theme, $slide_count, $source_context );

		// Call BizCity LLM Router
		$ai_response = self::call_llm( $system_prompt, $user_prompt );

		if ( is_wp_error( $ai_response ) ) {
			self::complete_generation( $gen_id, 'failed', $start_time, $ai_response->get_error_message() );
			return $ai_response;
		}

		// Spreadsheet returns CSV, not JSON
		if ( $doc_type === 'spreadsheet' ) {
			$schema = [ 'content' => trim( $ai_response ) ];
		} else {
			// Parse JSON from AI response
			$schema = self::parse_ai_json( $ai_response );

			if ( is_wp_error( $schema ) ) {
				self::complete_generation( $gen_id, 'failed', $start_time, $schema->get_error_message() );
				return $schema;
			}
		}

		// Ensure required fields
		$schema = self::ensure_defaults( $schema, $doc_type, $topic, $theme );

		if ( is_wp_error( $schema ) ) {
			self::complete_generation( $gen_id, 'failed', $start_time, $schema->get_error_message() );
			return $schema;
		}

		// Auto-save to get a doc_id for URL persistence
		$title = $schema['metadata']['title'] ?? $schema['presentation_title'] ?? $topic;
		$saved = self::auto_save_document( $doc_id, $user_id, $doc_type, $title, $template, $theme, $schema );

		self::complete_generation( $gen_id, 'completed', $start_time, null, $saved, $schema );

		return rest_ensure_response( [
			'success' => true,
			'data'    => $schema,
			'doc_id'  => $saved,
			'gen_id'  => $gen_id,
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
		$doc_id       = absint( $request->get_param( 'doc_id' ) ?: 0 );

		if ( empty( $instruction ) || empty( $current_json ) ) {
			return new \WP_Error( 'missing_params', 'Instruction and current document are required.', [ 'status' => 400 ] );
		}

		$context_string = wp_json_encode( $current_json );
		if ( strlen( $context_string ) > self::MAX_CONTEXT ) {
			$context_string = substr( $context_string, 0, self::MAX_CONTEXT );
		}

		// Build source context for reference/citation enrichment
		$source_context = '';
		if ( $doc_id > 0 ) {
			$source_context = self::build_source_context( $doc_id, $instruction );
		}

		$user_id = get_current_user_id();
		$gen_id = self::log_generation( $doc_id, $user_id, 'edit', $instruction );
		$start_time = microtime( true );

		$system_prompt = self::get_system_prompt( $doc_type );
		$user_prompt   = self::get_edit_prompt( $doc_type, $instruction, $context_string, $theme, $source_context );

		$ai_response = self::call_llm( $system_prompt, $user_prompt );

		if ( is_wp_error( $ai_response ) ) {
			self::complete_generation( $gen_id, 'failed', $start_time, $ai_response->get_error_message() );
			return $ai_response;
		}

		// Spreadsheet returns CSV, not JSON
		if ( $doc_type === 'spreadsheet' ) {
			$schema = [ 'content' => trim( $ai_response ) ];
		} else {
			$schema = self::parse_ai_json( $ai_response );

			if ( is_wp_error( $schema ) ) {
				self::complete_generation( $gen_id, 'failed', $start_time, $schema->get_error_message() );
				return $schema;
			}
		}

		$schema = self::ensure_defaults( $schema, $doc_type, '', $theme );

		if ( is_wp_error( $schema ) ) {
			self::complete_generation( $gen_id, 'failed', $start_time, $schema->get_error_message() );
			return $schema;
		}

		self::complete_generation( $gen_id, 'completed', $start_time, null, $doc_id, $schema );

		// Auto-save document with new schema
		if ( $doc_id > 0 ) {
			self::auto_save_document( $doc_id, get_current_user_id(), $doc_type, '', '', $theme, $schema );
		}

		return rest_ensure_response( [
			'success' => true,
			'data'    => $schema,
			'gen_id'  => $gen_id,
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
	private static function call_llm( string $system_prompt, string $user_prompt, int $max_tokens = 32000 ) {
		$messages = [
			[ 'role' => 'system', 'content' => $system_prompt ],
			[ 'role' => 'user',   'content' => $user_prompt ],
		];

		$llm_opts = [
			'model'       => 'anthropic/claude-sonnet-4',
			'purpose'     => 'executor',
			'temperature' => 0.4,
			'max_tokens'  => $max_tokens,
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
You are a Professional Presentation Architect creating expert-level presentation decks. You output STRICT JSON.

You MUST write in THE SAME LANGUAGE as the user's topic. If Vietnamese, write in Vietnamese. If English, write in English.

## OUTPUT SCHEMA

```
{
  "presentation_title": "string",
  "slides": [{
    "slide_layout": "title_slide|content_slide|section_header|two_column|image_slide",
    "title": "string",
    "subtitle": "string (only for title_slide/section_header)",
    "bullets": [{ "content": "string", "level": 0 }],
    "columns": [{ "title": "string", "bullets": [{ "content": "string", "level": 0 }] }],
    "icon": "string (emoji icon representing the slide topic, e.g. 📊, 🎯, 💡, 🏥)",
    "accent_color": "string (hex color for this slide's accent, e.g. #2563eb, #059669)",
    "notes": "string (speaker notes — 4-6 sentences)"
  }]
}
```

## QUALITY STANDARDS — FOLLOW STRICTLY

1. **Structure**: title_slide → overview/agenda slide → (section_header → 3-5 content slides per section) → summary/closing slide
2. **Content depth**: Each bullet MUST be a complete, substantive sentence with specific data, percentages, examples, or analysis — NOT vague phrases like "Improve quality" or "Important factor"
3. **Source citations**: When reference documents are provided, cite them inline: "(Nguồn: Khảo sát 2023, n=200)" or "(Theo Thông tư 08/2011/TT-BYT)". Include specific numbers and findings from sources.
4. **Speaker notes**: MUST be 4-6 sentences of detailed talking points with additional context, statistics, and transition cues
5. **Visual variety**: Alternate layouts — use two_column for comparisons/before-after, section_header to introduce new topics, content_slide for detailed analysis
6. **Icons**: Every slide gets a relevant emoji icon representing its topic
7. **Accent colors**: Vary accent_color across slides for visual rhythm — use professional colors
8. **Two-column slides**: Use columns array with title + bullets for each column. Great for: pros/cons, before/after, problem/solution, comparison tables
9. **Data-driven**: Include specific numbers, percentages, timelines, costs, KPIs in bullet content
10. **Hierarchy**: Use level 0 for main points (bold insights), level 1 for supporting details/evidence

## CRITICAL RULES
1. ONLY output valid JSON — no markdown, no code blocks
2. All strings must use proper JSON escaping  
3. First slide = title_slide with title + subtitle
4. Last slide = closing/thank-you slide with key takeaways
5. Generate 12-20 slides with REAL, SUBSTANTIVE, DETAILED content
6. Each content_slide must have 4-6 bullets (not 2-3 sparse ones)
7. For two_column layout, use "columns" array (2 items), NOT "bullets"
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
Create a professional, comprehensive, DETAILED presentation about: {$topic}

Requirements:
- Generate {$slide_count} slides minimum (aim for 15-20 for thorough coverage)
- title_slide first with compelling title + subtitle, closing slide last with key takeaways
- CONTENT DEPTH: Each content slide must have 4-6 substantive bullets. Each bullet must be a complete sentence with specific data, numbers, percentages, or concrete analysis — NOT generic phrases
- If reference documents are provided above, EXTRACT and CITE specific data: numbers, survey results, statistics, legal references, findings. Add "(Nguồn: ...)" inline citations
- Use two_column layout for comparisons: before/after, problem/solution, current state/target
- Speaker notes: 4-6 sentences per slide with talking points, transitions, and additional context
- Mix layouts: content_slide (60%), two_column (20%), section_header (15%), image_slide (5%)
- Every slide must have an "icon" field (emoji) and "accent_color" (hex)
- Include analysis slides with specific root causes, evidence, and proposed solutions
- For data tables or KPIs, present them as structured bullets with numbers prominent

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

	private static function get_edit_prompt( string $doc_type, string $instruction, string $context, string $theme, string $source_context = '' ): string {
		$source_block = '';
		if ( ! empty( $source_context ) ) {
			$source_block = "\n\nTÀI LIỆU THAM KHẢO (dùng để bổ sung trích dẫn, dữ liệu, hoặc phân tích sâu hơn khi user yêu cầu):\n---\n{$source_context}\n---\n";
		}

		if ( $doc_type === 'presentation' ) {
			return "Current presentation:\n{$context}{$source_block}\n\nUser request: {$instruction}\n\nUpdate the presentation based on the request. If user asks for citations or deeper analysis, USE the reference documents above. Return the COMPLETE updated JSON with changes applied. Output ONLY valid JSON.";
		}

		if ( $doc_type === 'spreadsheet' ) {
			return "Current spreadsheet data (CSV):\n{$context}{$source_block}\n\nUser request: {$instruction}\n\nUpdate the spreadsheet. Return updated CSV data only.";
		}

		return "CURRENT DOCUMENT JSON:\n{$context}{$source_block}\n\nUSER'S EDIT REQUEST: \"{$instruction}\"\n\nINSTRUCTIONS:\n1. Apply the specific changes requested\n2. If user asks for citations, references, or deeper analysis — USE the reference documents above to add real data, quotes, and source attributions\n3. Keep all other content unchanged\n4. Return the COMPLETE updated JSON\n5. Output ONLY valid JSON, no explanations";
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
	   SECTIONAL GENERATION — Multi-call pipeline
	   ═══════════════════════════════════════════════ */

	/**
	 * Generate document section-by-section based on skeleton nodes.
	 *
	 * Each skeleton node → one LLM call → reliable small JSON → assemble.
	 * Prevents truncation on large documents with rich source material.
	 */
	private static function generate_document_sectional( \WP_REST_Request $request, array $skeleton ) {
		@set_time_limit( 0 );

		$user_id  = get_current_user_id();
		$topic    = sanitize_textarea_field( $request->get_param( 'topic' ) ?: '' );
		$template = sanitize_text_field( $request->get_param( 'template_name' ) ?: 'blank' );
		$theme    = sanitize_text_field( $request->get_param( 'theme_name' ) ?: 'modern' );
		$doc_id   = absint( $request->get_param( 'doc_id' ) ?: 0 );

		// Source context
		$source_context = '';
		$source_override = $request->get_param( 'source_context_override' );
		if ( ! empty( $source_override ) ) {
			$source_context = $source_override;
		} elseif ( $doc_id > 0 ) {
			$source_context = self::build_source_context( $doc_id, $topic );
		}

		$gen_id     = self::log_generation( $doc_id, $user_id, 'generate', $topic );
		$start_time = microtime( true );

		$nucleus = $skeleton['nucleus'] ?? [];
		$nodes   = $skeleton['skeleton'] ?? [];
		$title   = $nucleus['title'] ?? $topic ?: 'Untitled';

		error_log( '[BZDoc] Sectional generation: ' . count( $nodes ) . ' sections for "' . $title . '"' );

		// Build document shell: metadata + theme + empty section container
		$schema = [
			'metadata' => [
				'title'   => $title,
				'subject' => $nucleus['thesis'] ?? '',
				'author'  => wp_get_current_user()->display_name ?: 'BizCity Doc Studio',
			],
			'theme' => [
				'name'            => $theme,
				'font_name'       => 'Times New Roman',
				'font_size'       => 13,
				'heading_font'    => 'Times New Roman',
				'primary_color'   => '1F4E79',
				'secondary_color' => '2E75B6',
			],
			'sections' => [ [
				'orientation' => 'portrait',
				'header' => [ 'text' => $title, 'align' => 'left', 'show_page_numbers' => false ],
				'footer' => [ 'text' => 'Trang', 'show_page_numbers' => true ],
				'elements' => [],
			] ],
		];

		// Title page elements
		$elements = [
			[ 'type' => 'heading1', 'text' => $title, 'alignment' => 'center' ],
		];
		if ( ! empty( $nucleus['thesis'] ) ) {
			$elements[] = [ 'type' => 'paragraph', 'text' => $nucleus['thesis'], 'alignment' => 'center' ];
		}
		$elements[] = [ 'type' => 'divider' ];

		// Generate each skeleton node as a separate LLM call
		$section_system = self::system_prompt_document_section();
		$total   = count( $nodes );
		$success = 0;

		// Build key points string for context
		$key_points_text = '';
		if ( ! empty( $skeleton['key_points'] ) ) {
			$kps = [];
			foreach ( $skeleton['key_points'] as $kp ) {
				$text = is_array( $kp ) ? ( $kp['text'] ?? $kp['point'] ?? '' ) : (string) $kp;
				if ( $text ) $kps[] = $text;
			}
			if ( $kps ) {
				$key_points_text = "Điểm chính của tài liệu:\n- " . implode( "\n- ", $kps );
			}
		}

		foreach ( $nodes as $idx => $node ) {
			$label    = is_array( $node ) ? ( $node['label'] ?? $node['heading'] ?? '' ) : (string) $node;
			$summary  = is_array( $node ) ? ( $node['summary'] ?? '' ) : '';
			$children = is_array( $node ) ? ( $node['children'] ?? [] ) : [];
			$is_last  = ( $idx === $total - 1 );

			$section_prompt = self::build_section_user_prompt(
				$label, $summary, $children, $source_context, $title, $key_points_text, $is_last
			);

			error_log( '[BZDoc] Sectional: generating section ' . ( $idx + 1 ) . '/' . $total . ': "' . $label . '"' );

			$ai_response = self::call_llm( $section_system, $section_prompt, 8000 );

			if ( is_wp_error( $ai_response ) ) {
				error_log( '[BZDoc] Sectional: section ' . ( $idx + 1 ) . ' LLM failed: ' . $ai_response->get_error_message() );
				$elements[] = [ 'type' => 'heading2', 'text' => $label ];
				$elements[] = [ 'type' => 'paragraph', 'text' => '[Không thể sinh nội dung cho phần này. Vui lòng thử chỉnh sửa bằng chat.]' ];
				$elements[] = [ 'type' => 'divider' ];
				continue;
			}

			$section_data = self::parse_ai_json( $ai_response );

			if ( is_wp_error( $section_data ) ) {
				error_log( '[BZDoc] Sectional: section ' . ( $idx + 1 ) . ' parse failed' );
				$elements[] = [ 'type' => 'heading2', 'text' => $label ];
				$elements[] = [ 'type' => 'paragraph', 'text' => '[Không thể phân tích JSON cho phần này. Vui lòng thử chỉnh sửa bằng chat.]' ];
				$elements[] = [ 'type' => 'divider' ];
				continue;
			}

			// Extract elements — model may return { "elements": [...] } or just [...]
			$section_elements = $section_data['elements'] ?? $section_data;
			if ( ! is_array( $section_elements ) || empty( $section_elements ) ) {
				$elements[] = [ 'type' => 'heading2', 'text' => $label ];
				$elements[] = [ 'type' => 'paragraph', 'text' => '[Nội dung rỗng.]' ];
				$elements[] = [ 'type' => 'divider' ];
				continue;
			}

			// Validate and append each element
			foreach ( $section_elements as $el ) {
				if ( is_array( $el ) && ! empty( $el['type'] ) ) {
					$elements[] = $el;
				}
			}

			if ( ! $is_last ) {
				$elements[] = [ 'type' => 'divider' ];
			}

			$success++;
		}

		error_log( '[BZDoc] Sectional: completed ' . $success . '/' . $total . ' sections, total elements: ' . count( $elements ) );

		$schema['sections'][0]['elements'] = $elements;
		$schema = self::ensure_defaults( $schema, 'document', $topic, $theme );

		if ( is_wp_error( $schema ) ) {
			self::complete_generation( $gen_id, 'failed', $start_time, $schema->get_error_message() );
			return $schema;
		}

		$saved = self::auto_save_document( $doc_id, $user_id, 'document', $title, $template, $theme, $schema );
		self::complete_generation( $gen_id, 'completed', $start_time, null, $saved, $schema );

		return rest_ensure_response( [
			'success' => true,
			'data'    => $schema,
			'doc_id'  => $saved,
			'gen_id'  => $gen_id,
		] );
	}

	/**
	 * System prompt for generating a single document section.
	 */
	private static function system_prompt_document_section(): string {
		return <<<'PROMPT'
You are a Professional Document Content Generator. You generate ONE SECTION of a Word document by outputting a JSON object with an "elements" array.

You MUST write in THE SAME LANGUAGE as the user's request. If Vietnamese, write entirely in Vietnamese.

## OUTPUT FORMAT
```json
{
  "elements": [
    { "type": "heading2", "text": "Section Title", "alignment": "left" },
    { "type": "paragraph", "text": "Opening paragraph...", "alignment": "justify" },
    { "type": "heading3", "text": "Sub-section Title" },
    { "type": "paragraph", "text": "Detailed content...", "alignment": "justify" },
    { "type": "paragraph", "text_runs": [
      { "text": "Bold term: ", "bold": true },
      { "text": "Explanation follows.", "bold": false }
    ] },
    { "type": "bullet_list", "items": ["Item 1", "Item 2"] },
    { "type": "numbered_list", "items": ["Step 1", "Step 2"] },
    { "type": "table", "style": "grid", "rows": [
      { "cells": ["Header 1", "Header 2", "Header 3"], "isHeader": true },
      { "cells": ["Data 1", "Data 2", "Data 3"] }
    ] }
  ]
}
```

## SECTION QUALITY STANDARDS
1. Start with heading2 for the section title
2. Opening paragraph: 3-5 sentences explaining context and importance
3. Use heading3 for each sub-topic within the section
4. Each sub-topic: paragraph + supporting evidence (table, list, or formatted text_runs)
5. Include at least one table with 3+ columns and 3+ data rows with realistic data
6. Use text_runs for inline bold/italic formatting on key terms
7. Generate 15-25 elements per section — comprehensive but focused
8. Use specific data: numbers, percentages, dates, concrete examples
9. If reference material is provided, cite specifics inline
10. Mix element types: paragraphs, bullet_list, numbered_list, tables, text_runs

## CRITICAL RULES
1. Output ONLY valid JSON: { "elements": [...] } — no markdown fences, no explanation
2. All string values must use proper JSON escaping (no raw newlines inside strings)
3. Tables MUST have isHeader: true on first row
4. Keep all content within this single section — do NOT generate other sections
PROMPT;
	}

	/**
	 * Build user prompt for a single section generation call.
	 */
	private static function build_section_user_prompt(
		string $label, string $summary, array $children,
		string $source_context, string $doc_title, string $key_points, bool $is_last
	): string {
		$parts = [];
		$parts[] = "Tài liệu: {$doc_title}";
		$parts[] = "Phần cần viết: {$label}";

		if ( $summary ) {
			$parts[] = "Tóm tắt: {$summary}";
		}

		if ( ! empty( $children ) ) {
			$parts[] = "\nCác mục con cần triển khai:";
			foreach ( $children as $child ) {
				$clabel   = is_array( $child ) ? ( $child['label'] ?? $child['text'] ?? '' ) : (string) $child;
				$csummary = is_array( $child ) ? ( $child['summary'] ?? '' ) : '';
				if ( $clabel ) {
					$parts[] = '- ' . $clabel . ( $csummary ? ': ' . $csummary : '' );
				}
			}
		}

		if ( $key_points ) {
			$parts[] = "\n" . $key_points;
		}

		if ( $is_last ) {
			$parts[] = "\nĐây là phần CUỐI CÙNG. Kết thúc bằng kết luận, đề xuất hoặc khuyến nghị cụ thể.";
		}

		if ( ! empty( $source_context ) ) {
			$parts[] = "\n=== TÀI LIỆU THAM KHẢO ===";
			$parts[] = "Trích xuất dữ liệu, thống kê, phát hiện liên quan đến phần này:";
			$parts[] = $source_context;
			$parts[] = "=== HẾT TÀI LIỆU ===";
		}

		$parts[] = "\nViết nội dung chi tiết, chuyên nghiệp cho phần này. Trả về JSON duy nhất.";

		return implode( "\n", $parts );
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

		// Aggressive fallback: progressively truncate from the last complete value
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			$aggressive = self::repair_truncated_json_aggressive( $content );
			if ( $aggressive ) {
				$data = json_decode( $aggressive, true );
				if ( json_last_error() === JSON_ERROR_NONE ) {
					error_log( '[BZDoc] Aggressive JSON repair succeeded, len=' . strlen( $aggressive ) );
				}
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

		// Remove orphaned key (a quoted string left without value after colon was stripped)
		// e.g. ...{"type": "paragraph", "text"  →  ...{"type": "paragraph"
		$json = preg_replace( '/,\s*"(?:[^"\\\\]|\\\\.)*"\s*$/s', '', $json );
		$json = rtrim( $json, " \t\n\r\0\x0B," );

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

	/**
	 * Aggressive JSON repair: find last position where JSON is valid up to a complete
	 * key-value pair, then close all open brackets.
	 * Scans backwards from the end for the last complete JSON value boundary.
	 */
	private static function repair_truncated_json_aggressive( string $content ): ?string {
		// Extract outermost { ... content
		$start = strpos( $content, '{' );
		if ( $start === false ) return null;
		$json = substr( $content, $start );

		// Find positions of all complete string endings (unescaped `"` followed by `,` or `:` or `}` or `]`)
		// We scan for patterns like: "value", or "value"} or "value"]
		// These mark boundaries where JSON was valid.
		$last_good = 0;
		$len       = strlen( $json );
		$in_string = false;
		$escape    = false;
		$stack     = [];

		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $json[ $i ];
			if ( $escape )    { $escape = false; continue; }
			if ( $ch === '\\' && $in_string ) { $escape = true; continue; }
			if ( $ch === '"' ) {
				$in_string = ! $in_string;
				if ( ! $in_string ) {
					// Just closed a string — this is a potential good boundary
					$last_good = $i + 1;
				}
				continue;
			}
			if ( $in_string ) continue;

			if ( $ch === '{' || $ch === '[' ) {
				$stack[] = $ch;
			} elseif ( $ch === '}' || $ch === ']' ) {
				if ( ! empty( $stack ) ) array_pop( $stack );
				$last_good = $i + 1; // after a close bracket is always a good boundary
			} elseif ( $ch === ',' ) {
				$last_good = $i; // before comma (we'll include the comma or not)
			}
		}

		if ( $last_good <= 1 ) return null;

		// Truncate to last good position
		$json = substr( $json, 0, $last_good );

		// Clean trailing partial tokens
		$json = rtrim( $json, " \t\n\r\0\x0B,:," );

		// Remove orphaned key (quoted string without value)
		$json = preg_replace( '/,?\s*"(?:[^"\\\\]|\\\\.)*"\s*$/s', '', $json );
		$json = rtrim( $json, " \t\n\r\0\x0B,:" );

		// Recount and close brackets
		$stack     = [];
		$in_string = false;
		$escape    = false;
		$scan_len  = strlen( $json );
		for ( $i = 0; $i < $scan_len; $i++ ) {
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

		$suffix = '';
		foreach ( array_reverse( $stack ) as $open ) {
			$suffix .= ( $open === '{' ) ? '}' : ']';
		}

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
		if ( empty( $sources ) ) {
			error_log( '[BZDoc] build_source_context: doc_id=' . $doc_id . ' — no sources found' );
			return '';
		}
		error_log( '[BZDoc] build_source_context: doc_id=' . $doc_id . ' — ' . count( $sources ) . ' sources' );

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
			if ( ! empty( $context ) ) {
				error_log( '[BZDoc] build_source_context: using embeddings, context_len=' . strlen( $context ) );
				return $context;
			}
		}

		// Fallback: all source content directly
		$fallback = BZDoc_Sources::get_all_content( $doc_id, 24000 );
		error_log( '[BZDoc] build_source_context: fallback get_all_content, len=' . strlen( $fallback ) );
		return $fallback;
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
		$source_id = absint( $request['id'] );
		if ( ! $source_id ) {
			return new \WP_Error( 'missing_source_id', 'source_id is required.', [ 'status' => 400 ] );
		}

		$ok = BZDoc_Sources::delete( $source_id );
		if ( ! $ok ) {
			return new \WP_Error( 'delete_error', 'Cannot delete source.', [ 'status' => 403 ] );
		}

		return rest_ensure_response( [ 'success' => true ] );
	}

	/* ═══════════════════════════════════════════════
	   ADD URL SOURCE — Fetch web page content
	   ═══════════════════════════════════════════════ */
	public static function handle_source_add_url( \WP_REST_Request $request ) {
		$doc_id = absint( $request->get_param( 'doc_id' ) );
		$url    = esc_url_raw( $request->get_param( 'url' ) );

		if ( ! $doc_id ) {
			return new \WP_Error( 'missing_doc_id', 'doc_id is required.', [ 'status' => 400 ] );
		}
		if ( ! $url || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return new \WP_Error( 'invalid_url', 'Vui lòng nhập URL hợp lệ.', [ 'status' => 400 ] );
		}

		// Fetch the URL
		$response = wp_remote_get( $url, [
			'timeout'    => 30,
			'user-agent' => 'BZDoc/1.0 (WordPress)',
		] );

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'fetch_error', 'Không thể tải URL: ' . $response->get_error_message(), [ 'status' => 502 ] );
		}

		$status = wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 400 ) {
			return new \WP_Error( 'http_error', 'URL trả về HTTP ' . $status, [ 'status' => 502 ] );
		}

		$html = wp_remote_retrieve_body( $response );
		if ( empty( $html ) ) {
			return new \WP_Error( 'empty_body', 'URL không có nội dung.', [ 'status' => 422 ] );
		}

		// Extract text from HTML
		$text = self::extract_text_from_html( $html );
		if ( mb_strlen( $text ) < 50 ) {
			return new \WP_Error( 'no_content', 'Không trích xuất được nội dung từ URL.', [ 'status' => 422 ] );
		}

		// Derive title from <title> tag
		$title = '';
		if ( preg_match( '/<title[^>]*>([^<]+)<\/title>/i', $html, $m ) ) {
			$title = html_entity_decode( trim( $m[1] ), ENT_QUOTES, 'UTF-8' );
		}
		if ( empty( $title ) ) {
			$title = wp_parse_url( $url, PHP_URL_HOST ) . wp_parse_url( $url, PHP_URL_PATH );
		}

		// Create source record
		$source_id = BZDoc_Sources::create( $doc_id, [
			'title'        => mb_substr( $title, 0, 200 ),
			'source_type'  => 'url',
			'source_url'   => $url,
			'content_text' => $text,
		] );

		if ( is_wp_error( $source_id ) ) {
			return $source_id;
		}

		$source = BZDoc_Sources::get( $source_id );

		// Schedule async embedding
		if ( function_exists( 'wp_schedule_single_event' ) ) {
			wp_schedule_single_event( time(), 'bzdoc_embed_source', [ $source_id ] );
		}

		return rest_ensure_response( [
			'success' => true,
			'source'  => [
				'id'               => $source_id,
				'title'            => $title,
				'source_type'      => 'url',
				'char_count'       => (int) $source->char_count,
				'token_estimate'   => (int) $source->token_estimate,
				'embedding_status' => 'pending',
				'chunk_count'      => 0,
			],
		] );
	}

	/**
	 * Extract readable text from HTML — strip tags, scripts, styles.
	 */
	private static function extract_text_from_html( string $html ): string {
		// Remove script and style blocks
		$html = preg_replace( '/<script[^>]*>.*?<\/script>/is', '', $html );
		$html = preg_replace( '/<style[^>]*>.*?<\/style>/is', '', $html );
		$html = preg_replace( '/<nav[^>]*>.*?<\/nav>/is', '', $html );
		$html = preg_replace( '/<footer[^>]*>.*?<\/footer>/is', '', $html );
		$html = preg_replace( '/<header[^>]*>.*?<\/header>/is', '', $html );

		// Convert block elements to newlines
		$html = preg_replace( '/<\/?(?:p|div|br|h[1-6]|li|tr|td|th|blockquote|pre)[^>]*>/i', "\n", $html );

		// Strip all remaining tags
		$text = wp_strip_all_tags( $html );

		// Decode entities
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );

		// Normalize whitespace
		$text = preg_replace( '/[ \t]+/', ' ', $text );
		$text = preg_replace( '/\n{3,}/', "\n\n", $text );
		$text = trim( $text );

		// Cap at 100K chars
		if ( mb_strlen( $text ) > 100000 ) {
			$text = mb_substr( $text, 0, 100000 );
		}

		return $text;
	}

	/* ═══════════════════════════════════════════════
	   GENERATION TRACKING — log each AI call
	   ═══════════════════════════════════════════════ */
	private static function log_generation( int $doc_id, int $user_id, string $action, string $prompt ): int {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'bzdoc_generations', [
			'doc_id'     => $doc_id,
			'user_id'    => $user_id,
			'action'     => $action,
			'status'     => 'pending',
			'prompt'     => mb_substr( $prompt, 0, 5000 ),
			'created_at' => current_time( 'mysql' ),
		] );
		return (int) $wpdb->insert_id;
	}

	private static function complete_generation( int $gen_id, string $status, float $start_time, ?string $error = null, int $doc_id = 0, $schema_snapshot = null ): void {
		if ( $gen_id <= 0 ) return;
		global $wpdb;
		$data = [
			'status'       => $status,
			'duration_ms'  => (int) ( ( microtime( true ) - $start_time ) * 1000 ),
			'completed_at' => current_time( 'mysql' ),
		];
		if ( $error ) {
			$data['error_message'] = mb_substr( $error, 0, 2000 );
		}
		if ( $doc_id > 0 ) {
			$data['doc_id'] = $doc_id;
		}
		if ( $schema_snapshot !== null ) {
			$data['schema_snapshot'] = wp_json_encode( $schema_snapshot );
		}
		$wpdb->update( $wpdb->prefix . 'bzdoc_generations', $data, [ 'id' => $gen_id ] );
	}

	/* ── List generations for a document ── */
	public static function handle_list_generations( \WP_REST_Request $request ) {
		global $wpdb;
		$user_id = get_current_user_id();
		$doc_id  = absint( $request->get_param( 'doc_id' ) ?: 0 );

		$where = "user_id = %d";
		$args  = [ $user_id ];

		if ( $doc_id > 0 ) {
			$where .= " AND doc_id = %d";
			$args[] = $doc_id;
		}

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, doc_id, action, status, prompt, model, tokens_used, duration_ms,
			        (schema_snapshot IS NOT NULL AND schema_snapshot != '') AS has_snapshot,
			        error_message, created_at, completed_at
			 FROM {$wpdb->prefix}bzdoc_generations
			 WHERE {$where}
			 ORDER BY created_at DESC
			 LIMIT 50",
			...$args
		) );

		// Cast has_snapshot to boolean + backfill missing snapshots from document
		if ( $rows && $doc_id > 0 ) {
			$needs_backfill = false;
			foreach ( $rows as &$row ) {
				$row->has_snapshot = (bool) $row->has_snapshot;
				if ( ! $row->has_snapshot && $row->status === 'completed' ) {
					$needs_backfill = true;
				}
			}
			unset( $row );

			// Backfill: for completed generations without snapshots, pull schema_json from document
			if ( $needs_backfill ) {
				$doc_schema = $wpdb->get_var( $wpdb->prepare(
					"SELECT schema_json FROM {$wpdb->prefix}bzdoc_documents WHERE id = %d AND user_id = %d",
					$doc_id, $user_id
				) );
				if ( $doc_schema ) {
					// Only the latest completed generation can safely use the current schema
					// For older ones, we store the current schema as a "best available" snapshot
					// and mark them as backfilled via the same column
					foreach ( $rows as &$row ) {
						if ( ! $row->has_snapshot && $row->status === 'completed' ) {
							$wpdb->update(
								$wpdb->prefix . 'bzdoc_generations',
								[ 'schema_snapshot' => $doc_schema ],
								[ 'id' => $row->id ],
								[ '%s' ],
								[ '%d' ]
							);
							$row->has_snapshot = true;
						}
					}
					unset( $row );
				}
			}
		} elseif ( $rows ) {
			foreach ( $rows as &$row ) {
				$row->has_snapshot = (bool) $row->has_snapshot;
			}
			unset( $row );
		}

		return rest_ensure_response( $rows ?: [] );
	}

	/* ── Restore a generation's schema snapshot to the document ── */
	public static function handle_restore_generation( \WP_REST_Request $request ) {
		global $wpdb;
		$gen_id  = absint( $request->get_param( 'gen_id' ) );
		$user_id = get_current_user_id();

		if ( ! $gen_id ) {
			return new \WP_Error( 'missing_gen_id', 'gen_id is required.', [ 'status' => 400 ] );
		}

		$gen = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, doc_id, schema_snapshot FROM {$wpdb->prefix}bzdoc_generations WHERE id = %d AND user_id = %d",
			$gen_id, $user_id
		) );

		if ( ! $gen ) {
			return new \WP_Error( 'not_found', 'Generation not found.', [ 'status' => 404 ] );
		}

		if ( empty( $gen->schema_snapshot ) ) {
			return new \WP_Error( 'no_snapshot', 'This version has no schema snapshot.', [ 'status' => 422 ] );
		}

		$schema = json_decode( $gen->schema_snapshot, true );
		if ( ! $schema ) {
			return new \WP_Error( 'corrupt_snapshot', 'Snapshot data is corrupt.', [ 'status' => 500 ] );
		}

		// Update the document with restored schema
		$doc_id = (int) $gen->doc_id;
		if ( $doc_id > 0 ) {
			$doc_table = $wpdb->prefix . 'bzdoc_documents';
			$owner = $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$doc_table} WHERE id = %d", $doc_id ) );
			if ( (int) $owner === $user_id ) {
				$wpdb->update( $doc_table, [
					'schema_json' => wp_json_encode( $schema ),
					'updated_at'  => current_time( 'mysql' ),
				], [ 'id' => $doc_id ] );
			}
		}

		// Log as a restore action
		$restore_gen_id = self::log_generation( $doc_id, $user_id, 'restore', 'Restored to version #' . $gen_id );
		self::complete_generation( $restore_gen_id, 'completed', microtime( true ), null, $doc_id, $schema );

		return rest_ensure_response( [
			'success' => true,
			'data'    => $schema,
			'gen_id'  => $restore_gen_id,
		] );
	}

	/* ── Get a single generation with its snapshot (read-only preview) ── */
	public static function handle_get_generation( \WP_REST_Request $request ) {
		global $wpdb;
		$gen_id  = absint( $request->get_param( 'gen_id' ) );
		$user_id = get_current_user_id();

		if ( ! $gen_id ) {
			return new \WP_Error( 'missing_gen_id', 'gen_id is required.', [ 'status' => 400 ] );
		}

		$gen = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, doc_id, action, status, prompt, duration_ms, schema_snapshot, error_message, created_at, completed_at
			 FROM {$wpdb->prefix}bzdoc_generations
			 WHERE id = %d AND user_id = %d",
			$gen_id, $user_id
		) );

		if ( ! $gen ) {
			return new \WP_Error( 'not_found', 'Generation not found.', [ 'status' => 404 ] );
		}

		$data = (array) $gen;

		// Decode snapshot JSON only if present
		if ( ! empty( $data['schema_snapshot'] ) ) {
			$data['schema_snapshot'] = json_decode( $data['schema_snapshot'], true );
		} else {
			$data['schema_snapshot'] = null;
		}

		return rest_ensure_response( $data );
	}

	/* ═══════════════════════════════════════════════
	   ADD TEXT SOURCE — clipboard/paste
	   ═══════════════════════════════════════════════ */
	public static function handle_source_add_text( \WP_REST_Request $request ) {
		$doc_id = absint( $request->get_param( 'doc_id' ) );
		$text   = $request->get_param( 'content_text' ) ?? '';
		$title  = sanitize_text_field( $request->get_param( 'title' ) ?? '' );

		if ( ! $doc_id ) {
			return new \WP_Error( 'missing_doc_id', 'doc_id is required.', [ 'status' => 400 ] );
		}
		if ( mb_strlen( trim( $text ) ) < 10 ) {
			return new \WP_Error( 'too_short', 'Nội dung quá ngắn (tối thiểu 10 ký tự).', [ 'status' => 400 ] );
		}

		if ( empty( $title ) ) {
			$title = mb_substr( trim( $text ), 0, 60 ) . ( mb_strlen( $text ) > 60 ? '…' : '' );
		}

		$source_id = BZDoc_Sources::create( $doc_id, [
			'title'        => $title,
			'source_type'  => 'text',
			'content_text' => $text,
		] );

		if ( is_wp_error( $source_id ) ) {
			return $source_id;
		}

		$source = BZDoc_Sources::get( $source_id );

		if ( function_exists( 'wp_schedule_single_event' ) ) {
			wp_schedule_single_event( time(), 'bzdoc_embed_source', [ $source_id ] );
		}

		return rest_ensure_response( [
			'success' => true,
			'source'  => [
				'id'               => (int) $source->id,
				'title'            => $source->title,
				'source_type'      => 'text',
				'char_count'       => (int) $source->char_count,
				'token_estimate'   => (int) $source->token_estimate,
				'embedding_status' => 'pending',
				'chunk_count'      => 0,
			],
		] );
	}

	/* ═══════════════════════════════════════════════
	   WEB SEARCH — via BizCity Search Router gateway
	   Uses BizCity_Search_Client (proven gateway client)
	   or bizcity_search() helper as fallback.
	   ═══════════════════════════════════════════════ */

	/**
	 * Synchronous web search via BizCity Search Router gateway.
	 * Uses the shared BizCity_Search_Client → search/router/v1/query.
	 */
	public static function handle_search( \WP_REST_Request $request ) {
		$query       = sanitize_text_field( $request->get_param( 'query' ) ?? '' );
		$max_results = min( 10, max( 1, absint( $request->get_param( 'max_results' ) ?? 5 ) ) );

		if ( empty( $query ) ) {
			return new \WP_Error( 'missing_query', 'Vui lòng nhập từ khóa tìm kiếm.', [ 'status' => 400 ] );
		}

		set_time_limit( 60 );

		// Priority 1: BizCity_Search_Client (gateway-routed, proven pattern)
		if ( class_exists( 'BizCity_Search_Client' ) && \BizCity_Search_Client::instance()->is_ready() ) {
			$results = \BizCity_Search_Client::instance()->search( $query, $max_results );

			if ( is_wp_error( $results ) ) {
				return $results;
			}

			return rest_ensure_response( [
				'success'    => true,
				'candidates' => $results, // already normalized by Search Client
				'query'      => $query,
			] );
		}

		// Priority 2: bizcity_search() helper (available from mu-plugin bizcity-openrouter)
		if ( function_exists( 'bizcity_search' ) ) {
			$raw = bizcity_search( $query, $max_results );

			// bizcity_search() returns { success, results, error } structure
			if ( ! empty( $raw['success'] ) && ! empty( $raw['results'] ) ) {
				return rest_ensure_response( [
					'success'    => true,
					'candidates' => $raw['results'],
					'query'      => $query,
				] );
			}

			$error_msg = $raw['error'] ?? 'Search failed';
			return new \WP_Error( 'search_error', $error_msg, [ 'status' => 502 ] );
		}

		return new \WP_Error( 'no_search_client', 'Chưa có search client. Kiểm tra bizcity-llm hoặc bizcity-openrouter đã active.', [ 'status' => 501 ] );
	}

	/**
	 * Import selected search candidates as doc sources.
	 * Receives candidates data directly from the frontend (no job dependency).
	 */
	public static function handle_search_import( \WP_REST_Request $request ) {
		$doc_id     = absint( $request->get_param( 'doc_id' ) );
		$candidates = $request->get_param( 'candidates' ) ?? [];

		if ( ! $doc_id ) {
			return new \WP_Error( 'missing_doc_id', 'doc_id is required.', [ 'status' => 400 ] );
		}
		if ( empty( $candidates ) || ! is_array( $candidates ) ) {
			return new \WP_Error( 'no_candidates', 'Chọn ít nhất 1 nguồn.', [ 'status' => 400 ] );
		}

		$imported = [];
		foreach ( $candidates as $c ) {
			$url     = esc_url_raw( $c['url'] ?? '' );
			$title   = sanitize_text_field( $c['title'] ?? '' );
			$content = $c['content'] ?? '';

			if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) continue;

			// If candidate has no content, fetch it.
			if ( mb_strlen( $content ) < 30 ) {
				$response = wp_remote_get( $url, [ 'timeout' => 20, 'user-agent' => 'BZDoc/1.0' ] );
				if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) >= 200 && wp_remote_retrieve_response_code( $response ) < 400 ) {
					$content = self::extract_text_from_html( wp_remote_retrieve_body( $response ) );
				}
			}

			if ( mb_strlen( $content ) < 30 ) continue;
			if ( empty( $title ) ) $title = wp_parse_url( $url, PHP_URL_HOST );

			$source_id = BZDoc_Sources::create( $doc_id, [
				'title'        => mb_substr( $title, 0, 200 ),
				'source_type'  => 'url',
				'source_url'   => $url,
				'content_text' => $content,
			] );

			if ( is_wp_error( $source_id ) ) continue;

			// Schedule embedding.
			if ( function_exists( 'wp_schedule_single_event' ) ) {
				wp_schedule_single_event( time(), 'bzdoc_embed_source', [ $source_id ] );
			}

			$source = BZDoc_Sources::get( $source_id );
			if ( $source ) {
				$imported[] = [
					'id'               => (int) $source->id,
					'title'            => $source->title,
					'source_type'      => 'url',
					'char_count'       => (int) $source->char_count,
					'token_estimate'   => (int) $source->token_estimate,
					'embedding_status' => 'pending',
					'chunk_count'      => 0,
				];
			}
		}

		return rest_ensure_response( [
			'success' => true,
			'sources' => $imported,
			'count'   => count( $imported ),
		] );
	}
}
