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
	// Hard cap on the user-facing "topic" / prompt field.
	// NOTE: counted in CHARACTERS via mb_strlen(), not bytes — so 50_000 here
	// means ~50k Vietnamese characters, not ~16k after UTF-8 byte expansion.
	// Raised 2026-05-06 from 5_000 (which was strlen-bytes → ~1.6k VN chars and
	// commonly rejected long-form notebook outlines with "Topic exceeds maximum
	// length").
	const MAX_TOPIC    = 50000;
	const MAX_CONTEXT  = 50000;

	/**
	 * When true, call_llm() skips bizcity_llm_chat_stream() and uses the direct
	 * bizcity_llm_chat() path. Set this before calling handle_generate() from
	 * internal PHP context (e.g. BZDoc_Notebook_Bridge) to avoid self-referencing
	 * HTTP requests which cause 503 under Apache/PHP-FPM.
	 */
	public static $force_direct_llm = false;

	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );

		// Multisite guard — switch to user's primary blog for every bzdoc/v1
		// request so `$wpdb->prefix` resolves to `wp_{blog_id}_*` (e.g.
		// `wp_1258_bzdoc_documents`) instead of root `wp_bzdoc_documents`.
		add_filter( 'rest_pre_dispatch',  [ __CLASS__, 'rest_switch_blog' ], 10, 3 );
		add_filter( 'rest_post_dispatch', [ __CLASS__, 'rest_restore_blog' ], 10, 3 );

		// Async image-gen worker (cron fallback when fastcgi_finish_request
		// is unavailable — receives doc_id, payload, blog_id).
		add_action( 'bzdoc_run_image_job', [ __CLASS__, 'run_image_job_cron' ], 10, 3 );
		add_action( 'bzdoc_run_image_edit_job', [ __CLASS__, 'run_image_edit_job_cron' ], 10, 3 );
	}

	/**
	 * Switch to the user's primary blog before dispatching any bzdoc/v1 route.
	 * Bound to `rest_pre_dispatch` (must return $result to allow normal flow).
	 */
	public static function rest_switch_blog( $result, $server, $request ) {
		if ( ! ( $request instanceof \WP_REST_Request ) ) {
			return $result;
		}
		$route = (string) $request->get_route();
		if ( strpos( $route, '/' . self::NAMESPACE . '/' ) !== 0 ) {
			return $result;
		}
		self::ensure_user_blog_context( $request );
		return $result;
	}

	/** Restore blog after the bzdoc/v1 request is dispatched. */
	public static function rest_restore_blog( $response, $server, $request ) {
		if ( $request instanceof \WP_REST_Request ) {
			$route = (string) $request->get_route();
			if ( strpos( $route, '/' . self::NAMESPACE . '/' ) === 0 ) {
				self::restore_blog_context();
			}
		}
		return $response;
	}

	public static function register_routes() {
		/* Generate document JSON schema via AI */
		register_rest_route( self::NAMESPACE, '/generate', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_generate' ],
			'permission_callback' => [ __CLASS__, 'check_auth' ],
		] );

		/* SSE streaming generate — keeps connection alive, prevents web server timeout */
		register_rest_route( self::NAMESPACE, '/generate/stream', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_generate_stream' ],
			'permission_callback' => [ __CLASS__, 'check_auth' ],
		] );

		/* Edit existing document via chat */
		register_rest_route( self::NAMESPACE, '/edit', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_edit' ],
			'permission_callback' => [ __CLASS__, 'check_auth' ],
		] );

		/* SSE streaming edit — prevents web server timeout on long edits */
		register_rest_route( self::NAMESPACE, '/edit/stream', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_edit_stream' ],
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

		/* Get source detail (with content_text) */
		register_rest_route( self::NAMESPACE, '/source/detail/(?P<id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'handle_source_get' ],
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

		/* Add YouTube video as source (transcript extraction) */
		register_rest_route( self::NAMESPACE, '/source/add-youtube', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_source_add_youtube' ],
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

		/* ── Phase 6.4: Image generation endpoints ── */
		register_rest_route( self::NAMESPACE, '/image/generate', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_image_generate' ],
			'permission_callback' => [ __CLASS__, 'check_auth' ],
		] );
		/*
		 * Async start/poll variant — kept for backward compat. New code should
		 * use /image/generate/direct instead (synchronous, no polling needed).
		 */
		register_rest_route( self::NAMESPACE, '/image/generate/start', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_image_generate_start' ],
			'permission_callback' => [ __CLASS__, 'check_auth' ],
		] );
		/*
		 * [2026-06-06 Johnny Chu] PHASE-IMAGE-SIMPLE — Direct synchronous route.
		 * OpenRouter image generation does NOT support streaming/async — gateway
		 * blocks until image is ready (6-60s). fastcgi_finish_request trick does
		 * not work reliably on all hosts. This route runs the pipeline in the
		 * same request and returns the full result immediately. WP_TIMEOUT must
		 * be ≥ 120s (already set via set_time_limit). Cloudflare Pro/Business
		 * customers can increase edge timeout; free tier will 524 on very slow
		 * prompts — acceptable trade-off vs. unreliable async.
		 */
		register_rest_route( self::NAMESPACE, '/image/generate/direct', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_image_generate_direct' ],
			'permission_callback' => [ __CLASS__, 'check_auth' ],
		] );
		register_rest_route( self::NAMESPACE, '/image/generate/status/(?P<id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'handle_image_generate_status' ],
			'permission_callback' => [ __CLASS__, 'check_auth' ],
		] );
		/*
		 * Image edit (Phase 6.4 Wave 2) — re-uses the same async +
		 * polling status endpoint as generate. Body shape:
		 *   { doc_id: int, parent_variant_index: int, instruction: string }
		 * Appends the edited variant to schema_json.variants[] (lineage via
		 * `parent_variant_index` + `edit_instruction`).
		 */
		register_rest_route( self::NAMESPACE, '/image/edit/start', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_image_edit_start' ],
			'permission_callback' => [ __CLASS__, 'check_auth' ],
		] );
		/*
		 * [2026-06-06 Johnny Chu] PHASE-IMAGE-SIMPLE — Direct synchronous edit.
		 * Same rationale as /image/generate/direct: OpenRouter does not support
		 * async image gen. Accepts extra_reference_images (data URIs or HTTPS URLs)
		 * uploaded by the user in the edit strip UI, forwarded to edit_variant().
		 */
		register_rest_route( self::NAMESPACE, '/image/edit/direct', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_image_edit_direct' ],
			'permission_callback' => [ __CLASS__, 'check_auth' ],
		] );
		register_rest_route( self::NAMESPACE, '/image/prompts/featured', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'handle_image_prompts_featured' ],
			'permission_callback' => [ __CLASS__, 'check_auth' ],
		] );
		register_rest_route( self::NAMESPACE, '/image/prompts/search', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'handle_image_prompts_search' ],
			'permission_callback' => [ __CLASS__, 'check_auth' ],
		] );
		register_rest_route( self::NAMESPACE, '/image/prompts/(?P<id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'handle_image_prompt_get' ],
			'permission_callback' => [ __CLASS__, 'check_auth' ],
		] );
		// PHASE-6.4 BUG-FIX (May 2026) — resolver theo slug. TwinChat ImageStudio
		// chỉ biết slug của bzdoc seed (vd 'vn-numerology-profile-poster'), cần
		// route này để DocApp map về prompt_id rồi bind vào ImagePromptInput
		// (load arguments + template).
		register_rest_route( self::NAMESPACE, '/image/prompts/by-slug/(?P<slug>[a-z0-9\-_]+)', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'handle_image_prompt_get_by_slug' ],
			'permission_callback' => [ __CLASS__, 'check_auth' ],
		] );
		// PHASE-6.4 SMART-INFER (May 2026) — đọc lịch sử hội thoại của
		// notebook (TwinChat session) + schema arguments của preset, gọi LLM
		// để tự suy luận giá trị từng field. Trả về prompt_args đã fill sẵn
		// để TwinChat ImageStudio handoff sang bzdoc với kickstart=true.
		register_rest_route( self::NAMESPACE, '/image/prompts/infer-args', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_image_prompt_infer_args' ],
			'permission_callback' => [ __CLASS__, 'check_auth' ],
		] );

		/* ── PHASE-6.4 Wave C6 (May 2026) — Parallel batch document generation.
		 * Workers run as async loopback sub-requests so N batches of sections
		 * are processed concurrently, then merged in section order.
		 * Auth is a one-time HMAC secret stored in a transient by the SSE handler.
		 */
		register_rest_route( self::NAMESPACE, '/generate/section-worker', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_section_worker' ],
			'permission_callback' => [ __CLASS__, 'check_worker_auth' ],
		] );
	}

	public static function check_auth() {
		return is_user_logged_in();
	}

	/**
	 * Auth check for parallel worker sub-requests.
	 * Validates a one-time secret stored by the dispatching SSE handler.
	 * Switches to the worker's blog context before reading the transient
	 * (required on multisite where the loopback request has no user session).
	 */
	public static function check_worker_auth( \WP_REST_Request $request ): bool {
		$job_id  = sanitize_text_field( $request->get_param( 'job_id' ) ?: '' );
		$secret  = sanitize_text_field( $request->get_param( 'worker_secret' ) ?: '' );
		$blog_id = absint( $request->get_param( 'blog_id' ) ?: 0 );
		if ( ! $job_id || ! $secret ) {
			return false;
		}
		// On multisite the loopback request has no authenticated user,
		// so rest_pre_dispatch never switches blog. Switch here manually.
		$switched = false;
		if ( $blog_id > 0 && function_exists( 'is_multisite' ) && is_multisite()
			&& $blog_id !== (int) get_current_blog_id() ) {
			switch_to_blog( $blog_id );
			$switched = true;
		}
		$stored = get_transient( "bzdoc_wauth_{$job_id}" );
		$valid  = ( is_string( $stored ) && $stored !== '' && hash_equals( $stored, $secret ) );
		if ( $switched ) {
			restore_current_blog();
		}
		return $valid;
	}

	/* ═══════════════════════════════════════════════
	   MULTISITE GUARD
	   ───────────────────────────────────────────────
	   bzdoc data lives in per-blog tables (`wp_{blog_id}_bzdoc_*`).
	   When the REST endpoint is hit from the wrong blog context (e.g.
	   Twin Canvas iframe served from network root `bizcity.vn` while the
	   user actually owns blog 1258 = huongnguyen.vibeyeu.com.vn), the
	   default `$wpdb->prefix` would point at `wp_bzdoc_*` (root tables
	   that don't exist for this user).

	   Rule: every bzdoc REST handler MUST run inside the user's primary
	   blog context so `$wpdb->prefix` resolves to `wp_{blog_id}_*`.

	   Resolution priority:
	     1. Explicit `blog_id` param on the request (trusted only when the
	        current user is a member of that blog).
	     2. `get_active_blog_for_user()` — user's primary blog.
	     3. Current blog (no-op).
	   ═══════════════════════════════════════════════ */

	/** Track switches so we can pop in pairs. */
	private static $blog_switch_depth = 0;

	/**
	 * Switch to the correct blog for this request. Call at the very top of
	 * every REST handler that touches bzdoc tables. Always pair with
	 * `restore_blog_context()` before returning.
	 */
	public static function ensure_user_blog_context( \WP_REST_Request $request = null ) {
		if ( ! is_multisite() ) {
			return;
		}
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}

		$target = 0;
		if ( $request ) {
			$req_blog = absint( $request->get_param( 'blog_id' ) );
			if ( $req_blog && is_user_member_of_blog( $user_id, $req_blog ) ) {
				$target = $req_blog;
			}
		}
		if ( ! $target ) {
			$primary = get_active_blog_for_user( $user_id );
			$target  = $primary ? (int) $primary->blog_id : 0;
		}
		if ( ! $target || $target === (int) get_current_blog_id() ) {
			return;
		}

		switch_to_blog( $target );
		self::$blog_switch_depth++;
	}

	/** Restore blog context if `ensure_user_blog_context()` switched. */
	public static function restore_blog_context() {
		while ( self::$blog_switch_depth > 0 ) {
			restore_current_blog();
			self::$blog_switch_depth--;
		}
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

		// Char-count validation (mb_strlen) so multi-byte UTF-8 — e.g. Vietnamese
		// diacritics that occupy 2-3 bytes per char — is not unfairly counted as 3x.
		if ( mb_strlen( $topic ) > self::MAX_TOPIC ) {
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
		$title = $schema['metadata']['title'] ?? $schema['presentation_title'] ?? $schema['title'] ?? $topic;
		$saved = self::auto_save_document( $doc_id, $user_id, $doc_type, $title, $template, $theme, $schema );

		self::complete_generation( $gen_id, 'completed', $start_time, null, $saved, $schema );

		// PHASE-0.13 — Graph-RAG citation validation (warn-only on first pass).
		$citation_report = self::run_citation_validator( $schema );

		return rest_ensure_response( [
			'success'    => true,
			'data'       => $schema,
			'doc_id'     => $saved,
			'gen_id'     => $gen_id,
			'validation' => $citation_report,
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
	   EDIT/STREAM — SSE streaming edit endpoint
	   ═══════════════════════════════════════════════ */
	public static function handle_edit_stream( \WP_REST_Request $request ) {
		@set_time_limit( 0 );
		@ignore_user_abort( true );

		// Disable compression at PHP/Apache level — required for SSE on LiteSpeed
		@ini_set( 'zlib.output_compression', '0' );
		@ini_set( 'output_buffering', '0' );
		@ini_set( 'implicit_flush', '1' );
		if ( function_exists( 'apache_setenv' ) ) { @apache_setenv( 'no-gzip', '1' ); }

		while ( ob_get_level() > 0 ) { ob_end_clean(); }
		ob_implicit_flush( true );

		header( 'Content-Type: text/event-stream; charset=utf-8' );
		header( 'Cache-Control: no-cache, no-store, must-revalidate, no-transform' );
		header( 'X-Accel-Buffering: no' );
		header( 'Content-Encoding: none' );
		header( 'Connection: keep-alive' );
		header( 'Access-Control-Allow-Origin: ' . esc_url_raw( get_site_url() ) );

		// 4KB padding comment — defeats LiteSpeed/proxy SSE buffering by forcing initial flush
		echo ':' . str_repeat( ' ', 4096 ) . "\n\n";
		flush();

		self::sse_send( 'connected', [ 'status' => 'connected' ] );

		$instruction  = sanitize_textarea_field( $request->get_param( 'instruction' ) ?: '' );
		$current_json = $request->get_param( 'current_json' );
		$doc_type     = sanitize_text_field( $request->get_param( 'doc_type' ) ?: 'document' );
		$theme        = sanitize_text_field( $request->get_param( 'theme_name' ) ?: 'modern' );
		$doc_id       = absint( $request->get_param( 'doc_id' ) ?: 0 );

		if ( empty( $instruction ) || empty( $current_json ) ) {
			self::sse_send( 'error', [ 'message' => 'Instruction and current document are required.' ] );
			exit;
		}

		$context_string = wp_json_encode( $current_json );
		if ( strlen( $context_string ) > self::MAX_CONTEXT ) {
			$context_string = substr( $context_string, 0, self::MAX_CONTEXT );
		}

		$source_context = '';
		if ( $doc_id > 0 ) {
			$source_context = self::build_source_context( $doc_id, $instruction );
		}

		$user_id    = get_current_user_id();
		$gen_id     = self::log_generation( $doc_id, $user_id, 'edit', $instruction );
		$start_time = microtime( true );

		self::sse_send( 'progress', [ 'status' => 'editing', 'message' => 'Đang chỉnh sửa tài liệu...' ] );

		$system_prompt = self::get_system_prompt( $doc_type );
		$user_prompt   = self::get_edit_prompt( $doc_type, $instruction, $context_string, $theme, $source_context );

		$tick         = 0;
		$on_keepalive = static function () use ( &$tick ) {
			$tick++;
			self::sse_send( 'ping', [ 'tick' => $tick ] );
		};

		// Cap at 8000 tokens — keep generation under ~160s to dodge Cloudflare 524.
		$ai_response = self::call_llm( $system_prompt, $user_prompt, 8000, $on_keepalive );

		if ( is_wp_error( $ai_response ) ) {
			self::complete_generation( $gen_id, 'failed', $start_time, $ai_response->get_error_message() );
			self::sse_send( 'error', [ 'message' => $ai_response->get_error_message() ] );
			exit;
		}

		self::sse_send( 'progress', [ 'status' => 'parsing', 'message' => 'Đang xử lý kết quả...' ] );

		if ( $doc_type === 'spreadsheet' ) {
			$schema = [ 'content' => trim( $ai_response ) ];
		} else {
			$schema = self::parse_ai_json( $ai_response );
			if ( is_wp_error( $schema ) ) {
				self::complete_generation( $gen_id, 'failed', $start_time, $schema->get_error_message() );
				self::sse_send( 'error', [ 'message' => $schema->get_error_message() ] );
				exit;
			}
		}

		$schema = self::ensure_defaults( $schema, $doc_type, '', $theme );
		if ( is_wp_error( $schema ) ) {
			self::complete_generation( $gen_id, 'failed', $start_time, $schema->get_error_message() );
			self::sse_send( 'error', [ 'message' => $schema->get_error_message() ] );
			exit;
		}

		self::complete_generation( $gen_id, 'completed', $start_time, null, $doc_id, $schema );

		if ( $doc_id > 0 ) {
			self::auto_save_document( $doc_id, $user_id, $doc_type, '', '', $theme, $schema );
		}

		self::sse_send( 'done', [
			'success' => true,
			'data'    => $schema,
			'gen_id'  => $gen_id,
		] );
		exit;
	}

	/* ═══════════════════════════════════════════════
	   GENERATE/STREAM — SSE streaming endpoint
	   Keeps web-server connection alive with ping events
	   so LiteSpeed/Apache don't kill the PHP process
	   before generation completes.
	   ═══════════════════════════════════════════════ */
	public static function handle_generate_stream( \WP_REST_Request $request ) {
		@set_time_limit( 0 );
		@ignore_user_abort( true );

		// Disable compression at PHP/Apache level — required for SSE on LiteSpeed
		@ini_set( 'zlib.output_compression', '0' );
		@ini_set( 'output_buffering', '0' );
		@ini_set( 'implicit_flush', '1' );
		if ( function_exists( 'apache_setenv' ) ) { @apache_setenv( 'no-gzip', '1' ); }

		// Flush all output buffers so we can stream directly
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		ob_implicit_flush( true );

		// SSE headers
		header( 'Content-Type: text/event-stream; charset=utf-8' );
		header( 'Cache-Control: no-cache, no-store, must-revalidate, no-transform' );
		header( 'X-Accel-Buffering: no' ); // nginx / LiteSpeed: disable proxy buffering
		header( 'Content-Encoding: none' );
		header( 'Connection: keep-alive' );
		header( 'Access-Control-Allow-Origin: ' . esc_url_raw( get_site_url() ) );

		// 4KB padding comment — defeats LiteSpeed/proxy SSE buffering by forcing initial flush
		echo ':' . str_repeat( ' ', 4096 ) . "\n\n";
		flush();

		self::sse_send( 'connected', [ 'status' => 'connected' ] );

		$user_id     = get_current_user_id();
		$doc_type    = sanitize_text_field( $request->get_param( 'doc_type' ) ?: 'document' );
		$topic       = sanitize_textarea_field( $request->get_param( 'topic' ) ?: '' );
		$template    = sanitize_text_field( $request->get_param( 'template_name' ) ?: 'blank' );
		$theme       = sanitize_text_field( $request->get_param( 'theme_name' ) ?: 'modern' );
		$slide_count = absint( $request->get_param( 'slide_count' ) ?: 10 );
		$doc_id      = absint( $request->get_param( 'doc_id' ) ?: 0 );
		$notebook_id      = absint( $request->get_param( 'notebook_id' ) ?: 0 );
		// S0.11 fallback — Studio autogen flow: the notebook_bridge saves
		// notebook_id to bzdoc_documents at creation time, but the FE's
		// selectedNotebookId state may not be seeded yet when kickstart fires
		// (React state update is async, 60 ms timeout isn't always enough).
		// Fall back to the DB-bound value so skeleton injection always runs.
		if ( $notebook_id === 0 && $doc_id > 0 ) {
			$notebook_id = self::lookup_doc_notebook_id( $doc_id );
		}
		// Sprint 0★ FE handoff — user may edit the auto-generated summary in
		// the “Tóm tắt notebook” textarea; if non-empty we use it verbatim and
		// SKIP the auto Adapter::get_prompt_block() call (avoid double-inject).
		$notebook_summary = trim( (string) ( $request->get_param( 'notebook_summary' ) ?: '' ) );

		// PHASE-0-RULE-SKELETON S0.11 — when a notebook_id is present and the
		// upstream skeleton is ready, inject Adapter::get_prompt_block() as a
		// system-context prefix on the topic. We also snapshot the current
		// skeleton_version onto bzdoc_documents.source_skeleton_version (S0.2)
		// so the FE can later show a stale banner (S0.14) when the underlying
		// notebook moves on. Idempotent + defensive — if the adapter class
		// isn't loaded (KG-Hub disabled) we simply skip the injection.
		if ( $notebook_id > 0 && class_exists( 'BizCity_KG_Skeleton_Adapter' ) ) {
			try {
				$skel_block = $notebook_summary !== ''
					? "## NOTEBOOK REFERENCE — user-edited summary\n" . $notebook_summary
					: (string) BizCity_KG_Skeleton_Adapter::get_prompt_block( $notebook_id );
				if ( $skel_block !== '' ) {
					$topic = $skel_block . "\n\n---\n\n" . $topic;
					self::sse_send( 'progress', [
						'status'           => 'skeleton_injected',
						'message'          => 'Đã chèn skeleton từ notebook #' . $notebook_id . ( $notebook_summary !== '' ? ' (user-edited)' : '' ),
						'bytes'            => strlen( $skel_block ),
						'source'           => $notebook_summary !== '' ? 'user' : 'auto',
						// S0.14 — FE stale-banner: version at time of inject so FE can
						// detect if notebook was rebuilt before the doc was saved.
						'skeleton_version' => class_exists( 'BizCity_KG_Skeleton_Adapter' )
							? (int) BizCity_KG_Skeleton_Adapter::get_version( $notebook_id ) : 0,
					] );
				}
				if ( $doc_id > 0 ) {
					global $wpdb;
					$ver = (int) BizCity_KG_Skeleton_Adapter::get_version( $notebook_id );
					$wpdb->update(
						$wpdb->prefix . 'bzdoc_documents',
						[ 'notebook_id' => $notebook_id, 'source_skeleton_version' => $ver ],
						[ 'id' => $doc_id ],
						[ '%d', '%d' ],
						[ '%d' ]
					);
				}
			} catch ( \Throwable $e ) {
				error_log( '[BZDoc][S0.11] skeleton inject failed nb=' . $notebook_id . ': ' . $e->getMessage() );
			}
		}

		if ( empty( $topic ) && $template === 'blank' ) {
			self::sse_send( 'error', [ 'message' => 'Topic or template is required.' ] );
			exit;
		}

		// Char-count validation (see handle_generate above).
		if ( mb_strlen( $topic ) > self::MAX_TOPIC ) {
			self::sse_send( 'error', [ 'message' => 'Topic exceeds maximum length.' ] );
			exit;
		}

		// Sectional generation (skeleton_json present)
		$skeleton_json    = $request->get_param( 'skeleton_json' );
		$parallel_batches = absint( $request->get_param( 'parallel_batches' ) ?: 0 );

		// PHASE-6.4 Wave C6.3 (May 2026) — DEFENSIVE DEFAULT.
		// Khi doc_type=document, không có skeleton sẵn, và FE không yêu cầu
		// rõ ràng (parallel_batches===0 hoặc thiếu), tự bật 3 luồng. Người
		// dùng vẫn có thể vô hiệu hoá bằng parallel_batches=1 (sequential).
		// Lý do: nếu không bật, request rơi xuống single-shot LLM 8000 tokens
		// → 110s+, hay bị Cloudflare 524. Parallel an toàn hơn nhiều.
		$parallel_raw = $request->get_param( 'parallel_batches' );
		$parallel_explicit_off = ( $parallel_raw === 1 || $parallel_raw === '1' );
		if ( $doc_type === 'document'
			&& ! $parallel_explicit_off
			&& $parallel_batches < 2
			&& empty( $skeleton_json ) ) {
			$parallel_batches = 3;
		}

		// ── DIAGNOSTICS (PHASE-6.4 Wave C6.2) ────────────────────────────────
		// In ra mọi tham số quan trọng để chẩn đoán vì sao parallel mode không
		// được kích hoạt. Xuất qua SSE event `debug` (FE EventStream sẽ thấy)
		// + qua error_log để có dấu vết server-side.
		$all_params  = $request->get_params();
		$param_keys  = array_keys( $all_params );
		$body_raw    = $request->get_body();
		$body_len    = strlen( (string) $body_raw );
		$body_first  = mb_substr( (string) $body_raw, 0, 200, 'UTF-8' );
		$dbg = [
			'route_version'        => 'C6.5-2026-05-08-curlmulti',
			'doc_type'             => $doc_type,
			'parallel_batches_raw' => $request->get_param( 'parallel_batches' ),
			'parallel_batches_int' => $parallel_batches,
			'has_skeleton_json'    => is_array( $skeleton_json ),
			'skeleton_keys'        => is_array( $skeleton_json ) ? array_keys( $skeleton_json ) : null,
			'skeleton_count'       => is_array( $skeleton_json ) && ! empty( $skeleton_json['skeleton'] ) ? count( $skeleton_json['skeleton'] ) : 0,
			'topic_len'            => mb_strlen( $topic ),
			'doc_id'               => $doc_id,
			'param_keys'           => $param_keys,
			'content_type'         => $request->get_content_type()['value'] ?? null,
			'body_len'             => $body_len,
			'body_first_200'       => $body_first,
		];
		error_log( '[BZDoc][Diag] handle_generate_stream entry: ' . wp_json_encode( $dbg ) );
		self::sse_send( 'debug', $dbg );

		if ( is_array( $skeleton_json ) && $doc_type === 'document' && ! empty( $skeleton_json['skeleton'] ) ) {
			if ( $parallel_batches >= 2 ) {
				// PHASE-6.4 Wave C6 — concurrent N-batch mode.
				self::generate_document_parallel_sse( $request, $skeleton_json, $parallel_batches );
			} else {
				// Default: sequential sectional (split_two supported inside).
				self::generate_document_sectional_sse( $request, $skeleton_json );
			}
			exit;
		}

		// PHASE-6.4 Wave C6.1 (May 2026) — AUTO-SKELETON for parallel mode.
		// Khi FE yêu cầu parallel_batches >= 2 nhưng không gửi skeleton_json
		// (đường đi từ TwinChat handoff / standalone bzdoc UI không qua
		// Notebook Studio, vì vậy không có skeleton dựng sẵn) — BE phải tự
		// dựng outline trước (1 LLM call ngắn ~20-30s) rồi mới fan-out N
		// workers song song. Nếu không, code sẽ rơi xuống single-shot LLM
		// 8000 tokens (~110s) và parallel_batches bị bỏ qua hoàn toàn.
		if ( $doc_type === 'document' && $parallel_batches >= 2 ) {
			// Build source context cho cả skeleton + workers.
			$src_ctx = '';
			$src_override = $request->get_param( 'source_context_override' );
			if ( ! empty( $src_override ) ) {
				$src_ctx = $src_override;
			} elseif ( $doc_id > 0 ) {
				$src_ctx = self::build_source_context( $doc_id, $topic );
			}

			self::sse_send( 'progress', [
				'status'  => 'building_skeleton',
				'message' => 'Đang dựng outline để chia luồng song song...',
			] );

			$auto_skeleton = self::build_auto_skeleton( $topic, $src_ctx, $parallel_batches );
			if ( is_wp_error( $auto_skeleton ) ) {
				self::sse_send( 'error', [ 'message' => 'Skeleton build failed: ' . $auto_skeleton->get_error_message() ] );
				exit;
			}

			self::sse_send( 'progress', [
				'status'   => 'skeleton_ready',
				'sections' => count( $auto_skeleton['skeleton'] ?? [] ),
				'message'  => 'Outline xong, bắt đầu chia ' . $parallel_batches . ' luồng...',
			] );

			// Inject source context override so worker batches reuse it
			// without re-querying KG (build_source_context có thể đắt).
			$request->set_param( 'source_context_override', $src_ctx );
			self::generate_document_parallel_sse( $request, $auto_skeleton, $parallel_batches );
			exit;
		}

		// Build source context
		$source_context  = '';
		$source_override = $request->get_param( 'source_context_override' );
		if ( ! empty( $source_override ) ) {
			$source_context = $source_override;
		} elseif ( $doc_id > 0 ) {
			$source_context = self::build_source_context( $doc_id, $topic );
		}

		$gen_id     = self::log_generation( $doc_id, $user_id, 'generate', $topic );
		$start_time = microtime( true );

		self::sse_send( 'progress', [ 'status' => 'generating', 'message' => 'Đang tạo tài liệu...' ] );

		$system_prompt = self::get_system_prompt( $doc_type );
		$user_prompt   = self::get_user_prompt( $doc_type, $topic, $template, $theme, $slide_count, $source_context );

		// Send SSE ping every ~3s while waiting for LLM to respond
		$tick         = 0;
		$on_keepalive = static function () use ( &$tick ) {
			$tick++;
			self::sse_send( 'ping', [ 'tick' => $tick ] );
		};

		// Cap at 8000 tokens — keep generation under ~160s to dodge Cloudflare 524.
		// For longer docs, use the sectional skeleton flow (per-section streaming).
		$ai_response = self::call_llm( $system_prompt, $user_prompt, 8000, $on_keepalive );

		if ( is_wp_error( $ai_response ) ) {
			self::complete_generation( $gen_id, 'failed', $start_time, $ai_response->get_error_message() );
			self::sse_send( 'error', [ 'message' => $ai_response->get_error_message() ] );
			exit;
		}

		self::sse_send( 'progress', [ 'status' => 'parsing', 'message' => 'Đang xử lý kết quả...' ] );

		if ( $doc_type === 'spreadsheet' ) {
			$schema = [ 'content' => trim( $ai_response ) ];
		} else {
			$schema = self::parse_ai_json( $ai_response );
			if ( is_wp_error( $schema ) ) {
				self::complete_generation( $gen_id, 'failed', $start_time, $schema->get_error_message() );
				self::sse_send( 'error', [ 'message' => $schema->get_error_message() ] );
				exit;
			}
		}

		$schema = self::ensure_defaults( $schema, $doc_type, $topic, $theme );
		if ( is_wp_error( $schema ) ) {
			self::complete_generation( $gen_id, 'failed', $start_time, $schema->get_error_message() );
			self::sse_send( 'error', [ 'message' => $schema->get_error_message() ] );
			exit;
		}

		$title = $schema['metadata']['title'] ?? $schema['presentation_title'] ?? $schema['title'] ?? $topic;
		$saved = self::auto_save_document( $doc_id, $user_id, $doc_type, $title, $template, $theme, $schema );
		self::complete_generation( $gen_id, 'completed', $start_time, null, $saved, $schema );

		self::sse_send( 'done', [
			'success' => true,
			'data'    => $schema,
			'doc_id'  => $saved,
			'gen_id'  => $gen_id,
		] );
		exit;
	}

	/**
	 * Send a Server-Sent Event to the browser.
	 */
	private static function sse_send( string $event, array $data ): void {
		echo 'event: ' . $event . "\n";
		echo 'data: ' . wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n\n";
		// Add 1KB padding on every event to keep LiteSpeed flushing immediately
		echo ':' . str_repeat( ' ', 1024 ) . "\n\n";
		if ( ob_get_level() > 0 ) {
			@ob_flush();
		}
		flush();
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

		$doc_type    = sanitize_text_field( $request->get_param( 'doc_type' ) ?: '' );
		$notebook_id = intval( $request->get_param( 'notebook_id' ) ?: 0 );

		$where = $wpdb->prepare( "WHERE user_id = %d", $user_id );
		if ( $doc_type ) {
			$where .= $wpdb->prepare( " AND doc_type = %s", $doc_type );
		}
		if ( $notebook_id > 0 ) {
			$where .= $wpdb->prepare( " AND notebook_id = %d", $notebook_id );
		}

		$rows = $wpdb->get_results(
			"SELECT id, doc_type, title, template_name, theme_name, status, notebook_id, created_at, updated_at FROM {$table} {$where} ORDER BY updated_at DESC LIMIT 50"
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
	private static function call_llm( string $system_prompt, string $user_prompt, int $max_tokens = 8000, ?callable $on_keepalive = null ) {
		$messages = [
			[ 'role' => 'system', 'content' => $system_prompt ],
			[ 'role' => 'user',   'content' => $user_prompt ],
		];

		$llm_opts = [
			'model'          => 'anthropic/claude-sonnet-4-5',
			// Cross-vendor fallback so Anthropic outages don't kill doc generation.
			// Hub default fallback for 'executor' is also Anthropic (claude-sonnet-4),
			// which means an Anthropic-wide 502 takes both down. Override here.
			'fallback_model' => 'google/gemini-2.5-pro',
			'purpose'        => 'executor',
			'temperature'    => 0.3,
			'max_tokens'     => $max_tokens,
			'timeout'        => 300,
		];

		if ( $on_keepalive !== null ) {
			$llm_opts['on_keepalive'] = $on_keepalive;
		}

		// Prefer streaming — keeps proxy connection alive, prevents 502
		// Skip when force_direct_llm is set (internal call from Notebook Bridge)
		// to avoid self-referencing HTTP requests which cause 503 under Apache.
		if ( ! self::$force_direct_llm && function_exists( 'bizcity_llm_chat_stream' ) ) {
			error_log( '[BZDoc] Using bizcity_llm_chat_stream()' );
			$full    = '';
			$last_hb = microtime( true );
			$result  = bizcity_llm_chat_stream( $messages, $llm_opts,
				function ( $delta, $full_so_far ) use ( &$full, &$last_hb, $on_keepalive ) {
					$full = $full_so_far; // accumulate full response
					// Cloudflare 100 s idle-timeout guard:
					// Send a heartbeat to the client SSE stream every 30 s while
					// Claude accumulates output, otherwise CF kills the connection
					// before the response is complete (especially for >8 000 token docs).
					if ( microtime( true ) - $last_hb >= 30.0 ) {
						if ( $on_keepalive !== null ) {
							( $on_keepalive )();
						} else {
							// Fallback when called without a keepalive handler
							// (e.g. force_direct_llm path — should not reach here, but safe).
							echo ": heartbeat\n\n";
							if ( ob_get_level() > 0 ) { @ob_flush(); }
							@flush();
						}
						$last_hb = microtime( true );
					}
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
			case 'mindmap':
				return self::system_prompt_mindmap();
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
   - Opening paragraph explaining the section's purpose (3-5 sentences)
   - Detailed sub-sections with heading3 for each sub-topic
   - Supporting data: tables with complete rows/columns, numbered lists, concrete examples
   - TOTAL document: 60-100 elements minimum when source documents are provided; 40-60 for no-source generation

3. **Source-based generation** (when reference documents are provided):
   - EXTRACT ALL structured content: every step, every criterion, every scoring table, every assessment tool
   - For protocol/framework documents: build a complete reusable template structure, then demonstrate with 2-3 specific application examples
   - Do NOT summarize or condense source content — reproduce it fully in structured form
   - Preserve exact Vietnamese medical/technical terminology from the source
   - **MANDATORY INLINE CITATIONS**: For EVERY substantive claim, statistic, criterion, threshold, or recommendation derived from a source, you MUST cite the source by name in the format `(Nguồn: "<tên tài liệu>")` or `(Theo "<tên tài liệu>")` immediately after the claim. The source name appears in the `[TÀI LIỆU #X | Nguồn: "..."]` or `[ĐOẠN TRÍCH #X | Nguồn: "..."]` headers in the reference block.
   - **DIRECT QUOTES**: For at least 30% of the substantive claims, include a direct verbatim quote from the source using `text_runs` with `"italic": true`, immediately followed by a deep analytical paragraph that explains, applies, or contextualizes the quote (3-5 sentences minimum, NOT a restatement).
   - **MULTI-SOURCE SYNTHESIS**: When multiple sources address the same topic, compare/contrast them in dedicated paragraphs — name each source explicitly, identify agreements/differences, and synthesize a conclusion.
   - **NO SHALLOW PARAPHRASING**: Replace any 1-2 sentence shallow paragraph with: quote + 3-5 sentence analysis + practical application. Empty/generic statements like "rất quan trọng", "cần lưu ý", "có ý nghĩa lớn" are FORBIDDEN unless backed by a quoted source line.
   - **CITATION FOOTER**: Add a final section `## Tài liệu tham khảo` listing every source actually cited, formatted as a numbered_list: `[1] "Tên tài liệu"`.

4. **Formatting richness**:
   - Use text_runs for inline formatting: bold key terms, italic for emphasis
   - Tables with 3-5 columns and 4-10 data rows (realistic data, complete scoring tables)
   - Mix paragraph, bullet_list, numbered_list, table in every section
   - Use divider between major sections

5. **Professional tone**: Formal language, specific data points, concrete recommendations

## CRITICAL RULES
1. ONLY output valid JSON — no markdown, no explanations, no code blocks
2. All string values must use proper JSON escaping (no raw newlines inside strings)
3. Colors are hex WITHOUT # prefix (e.g., "1F4E79")
4. font_size is number (e.g., 13), NOT string
5. Every heading must follow hierarchy: heading1 > heading2 > heading3
6. Tables MUST have isHeader: true on first row
7. Generate COMPREHENSIVE content — a full professional document, NOT a skeleton
8. NEVER truncate or omit sections to save space — output the complete document
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

	/* ── Phase 6.3: Mindmap system prompt ── */
	private static function system_prompt_mindmap(): string {
		return <<<'PROMPT'
You are a Mindmap Architect. You create comprehensive, hierarchical mindmaps by outputting STRICT JSON.

You MUST write in THE SAME LANGUAGE as the user's topic/prompt. Vietnamese topic → Vietnamese labels.

## REQUIRED OUTPUT SCHEMA

```json
{
  "title": "string — main topic name",
  "root": {
    "id": "root",
    "label": "string — same as title",
    "children": [
      {
        "id": "1",
        "label": "string — main branch (2–6 words)",
        "note": "string — 1 sentence context for this branch",
        "children": [
          {
            "id": "1.1",
            "label": "string — sub-topic",
            "note": "string — optional detail",
            "children": [
              { "id": "1.1.1", "label": "string — leaf node" }
            ]
          }
        ]
      }
    ]
  },
  "theme": "colorful",
  "direction": "LR"
}
```

## QUALITY RULES

1. Root: 1 node — the main topic label
2. Level 1 (direct root children): 5–8 branches covering ALL key dimensions/aspects/categories
3. Level 2: 3–5 sub-topics per branch with specific, actionable labels
4. Level 3 (optional): detail/leaf nodes — max 3 per parent (use only for important breakdowns)
5. Max depth: 4 levels total (root + 3 levels)
6. Node IDs: "root" → "1","2",... → "1.1","1.2",... → "1.1.1",...
7. Node labels: CONCISE (2–7 words), SPECIFIC — no vague phrases like "Important factor"
8. "note" field: Include on Level 1 & Level 2 (1 brief sentence each)
9. If REFERENCE DOCUMENTS are provided: extract key concepts, terminology, facts — build branches from source content; include "(Nguồn: ...)" in note fields
10. Match source language. Vietnamese topic → all labels & notes in Vietnamese.

## CRITICAL RULES
- ONLY output valid JSON — zero markdown, zero code blocks, zero explanations
- All strings properly JSON-escaped (no raw newlines inside values)
- Generate a COMPLETE, SUBSTANTIVE mindmap — not a skeleton
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
			// PHASE-0.13 — Graph-RAG mode adds an extra `[src:N#pM]` citation
			// requirement on top of the human-readable (Nguồn: "X") citations.
			// Detect by looking for the `id:[src:` annotation injected by
			// build_kg_graph_context().
			$is_graph_rag = ( strpos( $source_context, 'id:[src:' ) !== false );
			$graph_rag_block = '';
			if ( $is_graph_rag ) {
				$graph_rag_block = <<<GRAPHRAG

CHẾ ĐỘ GRAPH-RAG (BẮT BUỘC ĐỌC):
Mỗi đoạn trích bắt đầu bằng header có dạng [ĐOẠN TRÍCH #X | Nguồn: "TÊN" | id:[src:NNN#pMMM]].
Phần `[src:NNN#pMMM]` là MÃ ĐỊNH DANH CHÍNH XÁC của đoạn (NNN = source_id, MMM = passage_id).

YÊU CẦU TRÍCH DẪN GRAPH-RAG (THÊM VÀO ngoài quy tắc A-F bên dưới):
G. Sau MỖI nhận định lấy từ một đoạn trích, ngoài (Nguồn: "TÊN") bạn PHẢI thêm marker chính xác `[src:NNN#pMMM]` lấy NGUYÊN VĂN từ header của đoạn đó (KHÔNG bịa số, KHÔNG đổi NNN/MMM, KHÔNG dùng [1]/[2] hay [src:1] kiểu thứ tự).
   Ví dụ ĐÚNG: "Tỉ lệ phát hiện sớm đạt 78% (Nguồn: \"Báo cáo HTN 2025\") [src:142#p9]."
   Ví dụ SAI:  "[src:1]" hoặc "[1]" hoặc "[src:142]" thiếu #pMMM.
H. Marker `[src:NNN#pMMM]` đặt SAU dấu chấm câu hoặc cuối câu, ở dạng plain text (KHÔNG bọc bold/italic, KHÔNG đặt trong text_runs riêng).
I. Nếu một câu tổng hợp nhiều đoạn, liệt kê tất cả markers cách nhau bằng khoảng trắng: `… [src:142#p9] [src:188#p3]`.
J. CẤM TUYỆT ĐỐI sáng tạo `[src:N#pM]` không có trong header. Mọi marker bịa sẽ bị reject ở khâu validate.
GRAPHRAG;
			}

			$source_block = <<<SOURCES

=== TÀI LIỆU THAM KHẢO (NGUỒN CHÍNH — ĐỌC KỸ TRƯỚC KHI TẠO) ===
QUAN TRỌNG: Tài liệu dưới đây là CƠ SỞ CHÍNH để tạo nội dung. Bạn PHẢI:
1. TRÍCH XUẤT ĐẦY ĐỦ: tất cả quy trình, bước thực hiện, tiêu chí đánh giá, bảng điểm, công cụ, chỉ số, mục tiêu cụ thể có trong tài liệu
2. BÁM SÁT nội dung gốc — không bỏ sót bước nào, không tự sáng tạo nội dung không có trong tài liệu
3. NẾU topic yêu cầu "khung", "template", "quy trình" → tạo document có cấu trúc rõ ràng, đầy đủ mọi bước từ tài liệu, với phần ví dụ ứng dụng cho từng mặt bệnh/tình huống cụ thể
4. GIỮ NGUYÊN thuật ngữ chuyên môn, tên công cụ, tên thang điểm từ tài liệu gốc

ĐỊNH DẠNG NGUỒN: Mỗi đoạn dưới đây bắt đầu bằng header [TÀI LIỆU #X | Nguồn: "<TÊN TÀI LIỆU>"] hoặc [ĐOẠN TRÍCH #X | Nguồn: "<TÊN TÀI LIỆU>"]. Phần trong dấu ngoặc kép là TÊN NGUỒN — bạn PHẢI dùng nguyên văn tên này khi trích dẫn (cấm bịa tên nguồn).{$graph_rag_block}

YÊU CẦU TRÍCH DẪN BẮT BUỘC:
A. MỌI nhận định, ngưỡng số, tiêu chí, khuyến nghị, thống kê lấy từ nguồn PHẢI kết thúc bằng (Nguồn: "<tên>") hoặc (Theo "<tên>").
B. Mỗi heading2 chính phải có TỐI THIỂU 2-3 trích dẫn nguyên văn (verbatim quotes) in nghiêng, định dạng:
   { "type": "paragraph", "text_runs": [
     { "text": "Theo \"<tên nguồn>\": ", "bold": true },
     { "text": "\"<câu trích nguyên văn 5-25 từ>\"", "italic": true }
   ] }
C. NGAY SAU mỗi trích dẫn nguyên văn phải là 1 đoạn paragraph PHÂN TÍCH SÂU 3-5 câu (giải thích ý nghĩa, áp dụng thực tế, đối chiếu với nguồn khác — KHÔNG diễn đạt lại).
D. Khi 2+ tài liệu cùng đề cập 1 vấn đề: phải có đoạn so sánh nêu rõ tên cả hai nguồn và điểm tương đồng/khác biệt.
E. Cấm câu chung chung kiểu "rất quan trọng", "có ý nghĩa lớn", "cần lưu ý" trừ khi đi kèm trích dẫn cụ thể.
F. Cuối tài liệu BẮT BUỘC có section "## Tài liệu tham khảo" liệt kê numbered_list tất cả nguồn đã trích, format: [1] "Tên tài liệu".

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

			case 'mindmap':
				return <<<PROMPT
{$source_block}
Create a comprehensive, detailed mindmap about: {$topic}

Requirements:
- Root node = main topic label
- 5–8 Level 1 branches covering ALL key dimensions, aspects, or categories of the topic
- 3–5 Level 2 nodes per branch with SPECIFIC details, data points, examples — not vague labels
- Level 3 (optional): up to 3 leaf nodes per parent for important breakdowns only
- Include "note" field on every Level 1 and Level 2 node (1 brief sentence context each)
- If reference documents are provided above: extract key concepts, terminology, data → build branches from source content; reference sources in note fields as "(Nguồn: ...)"
- All labels: CONCISE (2–7 words), SUBSTANTIVE — no filler phrases like "General overview"

Return ONLY the complete JSON.
PROMPT;

			default:
				$has_sources = ! empty( $source_context );
				$length_guide = $has_sources
					? '60-100 elements total (source-based documents MUST be comprehensive — extract ALL steps, criteria, tables from reference)'
					: '40-60 elements total. Professional document — concise but thorough.';
				$source_guide = $has_sources ? <<<SRC

**SOURCE EXTRACTION MODE** — Reference documents are provided. You MUST:
- Extract EVERY step, criterion, scoring table, assessment tool, objective from the source documents
- For framework/template requests: Build a complete reusable template with clear sections for each process step, then provide worked examples showing how to apply it to specific cases/diseases/scenarios
- Cite source content directly: preserve exact terminology, tool names, scoring scales, cutoff values
- Do NOT omit details to save space — completeness is required
SRC
					: '';
				return <<<PROMPT
{$source_block}
Create a comprehensive, professional document about: {$topic}

Theme: {$theme}
{$template_guide}
{$source_guide}

REQUIREMENTS — Follow these strictly:
1. **Length**: Generate {$length_guide}.
2. **Structure**: Use heading1 for document title (centered), heading2 for major sections, heading3 for sub-sections.
3. **Content depth**: Each heading2 section needs:
   - Opening paragraph (3-5 sentences explaining context)
   - 2-3 heading3 sub-sections with detailed content
   - Supporting elements: tables (3+ columns, 4+ rows), numbered_list, bullet_list
   - Use text_runs for inline bold/italic formatting on key terms
4. **Data & Evidence**: Include specific numbers, percentages, cutoff values, scoring criteria. NOT vague statements.
5. **Tables**: Every major section must have at least one detailed table. For assessment tools/criteria: build complete scoring tables with all rows and columns from the source.
6. **Professional formatting**: Use divider between major sections. Justify paragraph alignment.
7. **Framework/Template sections**: If topic is a process/protocol → include a blank template section AND a filled worked example for at least 2-3 specific application cases.
8. **Conclusion**: End with concrete recommendations or action items.

Return ONLY the complete JSON document.
PROMPT;
		}
	}

	private static function get_edit_prompt( string $doc_type, string $instruction, string $context, string $theme, string $source_context = '' ): string {
		$source_block = '';
		$citation_rules = '';
		if ( ! empty( $source_context ) ) {
			$source_block = "\n\n=== TÀI LIỆU THAM KHẢO ===\n"
				. "Mỗi đoạn dưới đây bắt đầu bằng header [TÀI LIỆU #X | Nguồn: \"<TÊN>\"] hoặc [ĐOẠN TRÍCH #X | Nguồn: \"<TÊN>\"]. "
				. "Phần trong dấu ngoặc kép là TÊN NGUỒN — phải dùng nguyên văn khi trích dẫn (cấm bịa).\n---\n{$source_context}\n---\n";

			$citation_rules = "\nQUY TẮC TRÍCH DẪN BẮT BUỘC khi cập nhật:\n"
				. "1. MỌI nội dung mới/sửa lấy từ tài liệu phải có (Nguồn: \"<tên>\") hoặc (Theo \"<tên>\") ở cuối câu.\n"
				. "2. Bổ sung tối thiểu 2 trích dẫn nguyên văn (in nghiêng) bằng text_runs:\n"
				. "   { \"type\": \"paragraph\", \"text_runs\": [ { \"text\": \"Theo \\\"<tên>\\\": \", \"bold\": true }, { \"text\": \"\\\"<câu nguyên văn>\\\"\", \"italic\": true } ] }\n"
				. "3. NGAY SAU mỗi trích dẫn phải có paragraph PHÂN TÍCH SÂU 3-5 câu (giải thích, áp dụng — không diễn đạt lại).\n"
				. "4. Cấm câu chung chung kiểu \"rất quan trọng\", \"có ý nghĩa\" trừ khi đi kèm trích dẫn cụ thể.\n"
				. "5. Nếu user yêu cầu \"sâu hơn\", \"chi tiết\", \"trích dẫn\", \"phân tích kỹ\" → MỞ RỘNG mỗi section ít nhất gấp đôi với trích dẫn + phân tích.\n";
		}

		if ( $doc_type === 'mindmap' ) {
			return "CURRENT MINDMAP JSON:\n{$context}{$source_block}{$citation_rules}\n\n"
				. "USER REQUEST: \"{$instruction}\"\n\n"
				. "Update the mindmap tree based on the request. "
				. "You may add branches, rename labels, expand sub-topics, or restructure.\n"
				. "Return ONLY the COMPLETE updated mindmap JSON. No markdown, no explanations.";
		}

		if ( $doc_type === 'presentation' ) {
			return "Current presentation:\n{$context}{$source_block}{$citation_rules}\n\nUser request: {$instruction}\n\nUpdate the presentation. Apply citation rules above when source documents are provided. Return the COMPLETE updated JSON. Output ONLY valid JSON.";
		}

		if ( $doc_type === 'spreadsheet' ) {
			return "Current spreadsheet data (CSV):\n{$context}{$source_block}\n\nUser request: {$instruction}\n\nUpdate the spreadsheet. Return updated CSV data only.";
		}

		return "CURRENT DOCUMENT JSON:\n{$context}{$source_block}{$citation_rules}\n\nUSER'S EDIT REQUEST: \"{$instruction}\"\n\nINSTRUCTIONS:\n1. Apply the specific changes requested\n2. Áp dụng QUY TẮC TRÍCH DẪN BẮT BUỘC ở trên cho mọi nội dung mới/sửa khi có tài liệu tham khảo\n3. Keep all unchanged content intact\n4. Return the COMPLETE updated JSON\n5. Output ONLY valid JSON, no explanations";
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

			// Use higher token budget when source context is present (complex tables)
			$section_tokens = ! empty( $source_context ) ? 11000 : 8000;
			$ai_response = self::call_llm( $section_system, $section_prompt, $section_tokens );

			// Retry once if LLM call failed
			if ( is_wp_error( $ai_response ) ) {
				error_log( '[BZDoc] Sectional: section ' . ( $idx + 1 ) . ' failed, retrying...' );
				$ai_response = self::call_llm( $section_system, $section_prompt, $section_tokens );
			}

			if ( is_wp_error( $ai_response ) ) {
				error_log( '[BZDoc] Sectional: section ' . ( $idx + 1 ) . ' LLM failed after retry: ' . $ai_response->get_error_message() );
				$elements[] = [ 'type' => 'heading2', 'text' => $label ];
				$elements[] = [ 'type' => 'paragraph', 'text' => '[Không thể sinh nội dung cho phần này. Vui lòng thử chỉnh sửa bằng chat.]' ];
				$elements[] = [ 'type' => 'divider' ];
				continue;
			}

			$section_data = self::parse_ai_json( $ai_response );

			// If parse failed, retry with simpler prompt (no source context to reduce token pressure)
			if ( is_wp_error( $section_data ) ) {
				error_log( '[BZDoc] Sectional: section ' . ( $idx + 1 ) . ' parse failed, retrying without source context...' );
				$retry_prompt = self::build_section_user_prompt(
					$label, $summary, $children, '', $title, $key_points_text, $is_last
				);
				$ai_retry = self::call_llm( $section_system, $retry_prompt, 6000 );
				if ( ! is_wp_error( $ai_retry ) ) {
					$section_data = self::parse_ai_json( $ai_retry );
				}
			}

			if ( is_wp_error( $section_data ) ) {
				error_log( '[BZDoc] Sectional: section ' . ( $idx + 1 ) . ' parse failed after retry' );
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

			// Validate, repair empty tables, and append each element
			foreach ( $section_elements as $el ) {
				if ( is_array( $el ) && ! empty( $el['type'] ) ) {
					// Repair tables that have empty cells (truncation artifacts)
					if ( $el['type'] === 'table' && ! empty( $el['rows'] ) ) {
						$el = self::repair_empty_table_cells( $el );
					}
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

	/* ═══════════════════════════════════════════════════════════════════
	   PHASE-6.4 Wave C6 (May 2026) — PARALLEL BATCH DOCUMENT GENERATION
	   ═══════════════════════════════════════════════════════════════════ */

	/**
	 * Async worker endpoint — processes one batch of sections synchronously,
	 * saves results to a transient, then returns. Called via non-blocking
	 * loopback POST from generate_document_parallel_sse().
	 *
	 * Auth: one-time HMAC secret in bzdoc_wauth_{job_id} transient.
	 */
	public static function handle_section_worker( \WP_REST_Request $request ): \WP_REST_Response {
		@ignore_user_abort( true );
		@set_time_limit( 300 );

		$job_id         = sanitize_text_field( $request->get_param( 'job_id' ) ?: '' );
		$batch_no       = absint( $request->get_param( 'batch_no' ) );
		$sections       = $request->get_param( 'sections' ) ?: [];
		$topic          = sanitize_textarea_field( $request->get_param( 'topic' ) ?: '' );
		$title          = sanitize_text_field( $request->get_param( 'title' ) ?: $topic );
		$source_context = (string) ( $request->get_param( 'source_context' ) ?: '' );
		$key_points     = (string) ( $request->get_param( 'key_points' ) ?: '' );
		$blog_id        = absint( $request->get_param( 'blog_id' ) ?: 0 );

		// PHASE-6.4 Wave C6.4 — diag: loopback có chạy tới đây không?
		error_log( sprintf(
			'[BZDoc][Worker] ENTRY job=%s batch=%d sections=%d blog=%d ip=%s ua=%s',
			$job_id, $batch_no, count( (array) $sections ), $blog_id,
			$_SERVER['REMOTE_ADDR'] ?? '?',
			substr( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '?' ), 0, 60 )
		) );

		// Switch blog context for multisite — the loopback request arrives without
		// an authenticated user session, so rest_pre_dispatch won't switch blogs.
		// Without this, set_transient / get_transient use the wrong blog's option table.
		$blog_switched = false;
		if ( $blog_id > 0 && function_exists( 'is_multisite' ) && is_multisite()
			&& $blog_id !== (int) get_current_blog_id() ) {
			switch_to_blog( $blog_id );
			$blog_switched = true;
		}

		$worker_key = "bzdoc_worker_{$job_id}_{$batch_no}";

		// Mark as working so the polling SSE handler can show live progress.
		set_transient( $worker_key, [ 'status' => 'working', 'elements' => [], 'progress' => [] ], 600 );

		$section_system = self::system_prompt_document_section();
		$results        = []; // [ { global_idx, label, elements[] } ]

		foreach ( (array) $sections as $entry ) {
			$global_idx = absint( $entry['global_idx'] ?? 0 );
			$is_last    = ! empty( $entry['is_last'] );
			$node       = $entry['node'] ?? '';
			$label      = is_array( $node ) ? ( $node['label'] ?? $node['heading'] ?? '' ) : (string) $node;
			$summary    = is_array( $node ) ? ( $node['summary'] ?? '' ) : '';
			$children   = is_array( $node ) ? ( $node['children'] ?? [] ) : [];

			$section_prompt = self::build_section_user_prompt(
				$label, $summary, $children, $source_context, $title, $key_points, $is_last
			);

			$section_tokens = ! empty( $source_context ) ? 11000 : 8000;
			$ai_response    = self::call_llm_blocking( $section_system, $section_prompt, $section_tokens );

			// One automatic retry on failure.
			if ( is_wp_error( $ai_response ) ) {
				$ai_response = self::call_llm_blocking( $section_system, $section_prompt, $section_tokens );
			}

			if ( is_wp_error( $ai_response ) ) {
				$results[] = [
					'global_idx' => $global_idx,
					'label'      => $label,
					'elements'   => [
						[ 'type' => 'heading2',  'text' => $label ],
						[ 'type' => 'paragraph', 'text' => '[Không thể sinh nội dung cho phần này. Vui lòng chỉnh sửa bằng chat.]' ],
						[ 'type' => 'divider' ],
					],
				];
				// Update progress in transient for live display.
				set_transient( $worker_key, [ 'status' => 'working', 'elements' => $results, 'progress' => array_column( $results, 'label' ) ], 600 );
				continue;
			}

			$section_data = self::parse_ai_json( $ai_response );

			// If parse failed, retry without source context.
			if ( is_wp_error( $section_data ) ) {
				$retry_prompt = self::build_section_user_prompt( $label, $summary, $children, '', $title, $key_points, $is_last );
				$ai_retry     = self::call_llm_blocking( $section_system, $retry_prompt, 6000 );
				if ( ! is_wp_error( $ai_retry ) ) {
					$section_data = self::parse_ai_json( $ai_retry );
				}
			}

			if ( is_wp_error( $section_data ) ) {
				$results[] = [
					'global_idx' => $global_idx,
					'label'      => $label,
					'elements'   => [
						[ 'type' => 'heading2',  'text' => $label ],
						[ 'type' => 'paragraph', 'text' => '[Lỗi phân tích JSON. Vui lòng chỉnh sửa bằng chat.]' ],
						[ 'type' => 'divider' ],
					],
				];
				set_transient( $worker_key, [ 'status' => 'working', 'elements' => $results, 'progress' => array_column( $results, 'label' ) ], 600 );
				continue;
			}

			$section_elements = $section_data['elements'] ?? $section_data;
			$merged           = [];
			if ( is_array( $section_elements ) && ! empty( $section_elements ) ) {
				foreach ( $section_elements as $el ) {
					if ( is_array( $el ) && ! empty( $el['type'] ) ) {
						if ( $el['type'] === 'table' && ! empty( $el['rows'] ) ) {
							$el = self::repair_empty_table_cells( $el );
						}
						$merged[] = $el;
					}
				}
			}
			if ( empty( $merged ) ) {
				$merged = [
					[ 'type' => 'heading2',  'text' => $label ],
					[ 'type' => 'paragraph', 'text' => '[Nội dung rỗng.]' ],
				];
			}
			if ( ! $is_last ) {
				$merged[] = [ 'type' => 'divider' ];
			}

			$results[] = [ 'global_idx' => $global_idx, 'label' => $label, 'elements' => $merged ];

			// Write incremental progress so the SSE poller can show per-section status.
			set_transient( $worker_key, [ 'status' => 'working', 'elements' => $results, 'progress' => array_column( $results, 'label' ) ], 600 );
		}

		// Mark batch complete.
		set_transient( $worker_key, [ 'status' => 'done', 'elements' => $results ], 600 );

		if ( $blog_switched ) {
			restore_current_blog();
		}

		return new \WP_REST_Response( [ 'ok' => true, 'count' => count( $results ) ], 200 );
	}

	/**
	 * Blocking LLM call (no streaming) — for use inside worker sub-requests
	 * where there is no SSE connection to keep alive.
	 */
	private static function call_llm_blocking( string $system_prompt, string $user_prompt, int $max_tokens = 8000 ) {
		$messages = [
			[ 'role' => 'system', 'content' => $system_prompt ],
			[ 'role' => 'user',   'content' => $user_prompt ],
		];
		$opts = [
			'model'          => 'anthropic/claude-sonnet-4-5',
			'fallback_model' => 'google/gemini-2.5-pro',
			'purpose'        => 'executor',
			'temperature'    => 0.3,
			'max_tokens'     => $max_tokens,
			'timeout'        => 300,
		];

		if ( function_exists( 'bizcity_llm_chat' ) ) {
			$result = bizcity_llm_chat( $messages, $opts );
			if ( ! empty( $result['success'] ) ) {
				return $result['message'] ?? '';
			}
			return new \WP_Error( 'llm_error', $result['error'] ?? 'LLM blocking call failed' );
		}

		return new \WP_Error( 'llm_unavailable', 'bizcity_llm_chat() is not available.' );
	}

	/**
	 * PHASE-6.4 Wave C6.1 (May 2026) — auto-build a `nucleus + skeleton[]`
	 * outline from a free-form topic so parallel batch mode can run on
	 * standalone bzdoc UI flows that do NOT come from Notebook Studio
	 * (which would normally provide the skeleton). Returns the same shape
	 * `generate_document_parallel_sse()` expects.
	 *
	 * Cost: ~1 LLM call ~20-30s, ~2k output tokens.
	 *
	 * @param string $topic            User's request / prompt.
	 * @param string $source_context   Optional KG / source-pack text.
	 * @param int    $batch_count      Used to size the outline (3 sections
	 *                                 per batch → balanced workers).
	 * @return array|\WP_Error  Skeleton array or WP_Error on parse failure.
	 */
	private static function build_auto_skeleton( string $topic, string $source_context, int $batch_count ) {
		$batch_count = max( 2, min( 3, $batch_count ) );
		$target      = $batch_count * 3; // 6 or 9 sections — balanced fan-out.

		$system = "Bạn là kiến trúc sư outline tài liệu chuyên nghiệp. " .
			"Bạn CHỈ trả về JSON object (không markdown fence, không giải thích). " .
			"Viết bằng tiếng Việt nếu yêu cầu của user là tiếng Việt.\n\n" .
			"## SCHEMA BẮT BUỘC\n" .
			"```json\n" .
			"{\n" .
			"  \"nucleus\": { \"title\": \"<tên tài liệu>\", \"thesis\": \"<luận điểm chính 1-2 câu>\" },\n" .
			"  \"skeleton\": [\n" .
			"    {\n" .
			"      \"label\": \"<số thứ tự>. <Tiêu đề phần>\",\n" .
			"      \"summary\": \"<1-2 câu mô tả nội dung sẽ viết>\",\n" .
			"      \"children\": [\"<mục con 1>\", \"<mục con 2>\", \"<mục con 3>\"]\n" .
			"    }\n" .
			"  ],\n" .
			"  \"key_points\": [\"<điểm cốt lõi 1>\", \"<điểm cốt lõi 2>\", \"<điểm cốt lõi 3>\"]\n" .
			"}\n" .
			"```\n\n" .
			"## QUY TẮC\n" .
			"1. Chính xác {$target} phần trong skeleton (không nhiều hơn, không ít hơn).\n" .
			"2. Mỗi phần phải độc lập — viết được riêng mà không cần phần khác.\n" .
			"3. label đánh số rõ: \"1. ...\", \"2. ...\".\n" .
			"4. children: 2-4 mục con cụ thể cho mỗi phần.\n" .
			"5. summary phải gợi ý dữ liệu/ví dụ cụ thể, KHÔNG được mơ hồ.\n" .
			"6. nucleus.title: ngắn gọn (≤ 80 ký tự), không sao chép nguyên prompt.\n" .
			"7. key_points: 4-6 điểm cốt lõi xuyên suốt tài liệu.\n" .
			"8. Output JSON ASCII-safe (Unicode escape \\uXXXX cho tiếng Việt).";

		$user_parts = [];
		$user_parts[] = "Yêu cầu của user:\n" . trim( $topic );
		if ( $source_context !== '' ) {
			// Trim source context to ~6k chars để skeleton call nhanh.
			$src_trim = mb_substr( $source_context, 0, 6000, 'UTF-8' );
			$user_parts[] = "\n## TÀI LIỆU THAM KHẢO\n" . $src_trim;
		}
		$user_parts[] = "\nDựng skeleton {$target} phần theo schema trên. Output ONLY JSON.";

		$user = implode( "\n\n", $user_parts );

		// PHASE-6.4 Wave C6.4 (May 2026) — STREAMING + KEEPALIVE.
		// Trước đây dùng call_llm_blocking → 20-30s im lặng → Cloudflare/
		// LiteSpeed cắt SSE connection → FE thấy "Stream ended without a
		// done event". Giờ dùng call_llm (streaming) với on_keepalive ping
		// mỗi delta chunk → connection sống đến khi skeleton xong.
		$tick_sk = 0;
		$on_ka_sk = static function () use ( &$tick_sk ) {
			$tick_sk++;
			self::sse_send( 'ping', [ 'tick' => 'sk_' . $tick_sk ] );
		};
		$ai = self::call_llm( $system, $user, 2500, $on_ka_sk );
		if ( is_wp_error( $ai ) ) {
			return $ai;
		}

		$parsed = self::parse_ai_json( $ai );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}
		if ( ! is_array( $parsed ) || empty( $parsed['skeleton'] ) || ! is_array( $parsed['skeleton'] ) ) {
			return new \WP_Error( 'bad_skeleton', 'Skeleton output thiếu trường `skeleton[]`.' );
		}
		// Ensure nucleus.title fallback.
		if ( empty( $parsed['nucleus']['title'] ) ) {
			$parsed['nucleus']['title'] = mb_substr( trim( $topic ), 0, 80, 'UTF-8' ) ?: 'Tài liệu';
		}
		return $parsed;
	}

	/**
	 * Parallel SSE handler — splits sections into $batch_count batches,
	 * fires each as an async (non-blocking) loopback sub-request, then polls
	 * the result transients while sending SSE progress/ping events.
	 * Sections are distributed round-robin so each worker gets a fair share.
	 *
	 * Fall-through: if all workers time out (loopback blocked), missing
	 * sections get placeholder elements — document still saves.
	 */
	private static function generate_document_parallel_sse( \WP_REST_Request $request, array $skeleton, int $batch_count ): void {
		$user_id  = get_current_user_id();
		$topic    = sanitize_textarea_field( $request->get_param( 'topic' ) ?: '' );
		$template = sanitize_text_field( $request->get_param( 'template_name' ) ?: 'blank' );
		$theme    = sanitize_text_field( $request->get_param( 'theme_name' ) ?: 'modern' );
		$doc_id   = absint( $request->get_param( 'doc_id' ) ?: 0 );

		// Source context
		$source_context  = '';
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
		$total   = count( $nodes );

		// Key-points context for section prompts.
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

		// Round-robin distribute sections across $batch_count batches.
		// e.g. 9 sections × 3 batches → batch 0=[0,3,6], batch 1=[1,4,7], batch 2=[2,5,8]
		// This ensures balanced workloads and avoids one batch being all heavy sections.
		$batch_count = max( 2, min( 3, $batch_count ) );
		$batches     = array_fill( 0, $batch_count, [] );
		foreach ( $nodes as $i => $node ) {
			$batches[ $i % $batch_count ][] = [
				'global_idx' => $i,
				'is_last'    => ( $i === $total - 1 ),
				'node'       => $node,
			];
		}

		self::sse_send( 'progress', [
			'status'      => 'parallel_start',
			'total'       => $total,
			'batch_count' => $batch_count,
			'message'     => "Khởi động {$batch_count} luồng song song cho {$total} phần...",
		] );

		// PHASE-6.4 Wave C6.5 (May 2026) — IN-PROCESS curl_multi.
		// Bỏ async loopback REST (Cloudflare/firewall hay chặn server tự
		// gọi REST của chính mình → workers không bao giờ chạy → toàn bộ
		// rơi về placeholder). Thay bằng curl_multi_exec gọi N HTTP requests
		// song song trực tiếp tới LLM gateway, cùng PHP process. Hoạt động
		// trên mọi hosting, không phụ thuộc loopback.
		// [2026-06-10 Johnny Chu] HOTFIX — per-site option (not network-wide sitemeta)
		$gateway_url = trim( (string) get_option( 'bizcity_llm_gateway_url', '' ) );
		$api_key     = trim( (string) get_option( 'bizcity_llm_api_key', '' ) );
		if ( $gateway_url === '' || $api_key === '' ) {
			self::sse_send( 'error', [ 'message' => 'LLM gateway chưa cấu hình.' ] );
			return;
		}
		$endpoint = rtrim( $gateway_url, '/' ) . '/wp-json/bizcity/v1/llm/chat';

		// 1 curl handle = 1 SECTION (max parallelism). 9 sections → 9 handles.
		$section_system = self::system_prompt_document_section();
		$mh = curl_multi_init();
		$handles = []; // [global_idx => [ch, batch_no, label]]
		foreach ( $batches as $bn => $batch ) {
			foreach ( $batch as $entry ) {
				$global_idx = (int) $entry['global_idx'];
				$node       = $entry['node'];
				$is_last    = ! empty( $entry['is_last'] );
				$label      = is_array( $node ) ? ( $node['label'] ?? $node['heading'] ?? '' ) : (string) $node;
				$summary    = is_array( $node ) ? ( $node['summary'] ?? '' ) : '';
				$children   = is_array( $node ) ? ( $node['children'] ?? [] ) : [];

				$user_prompt = self::build_section_user_prompt(
					$label, $summary, $children, $source_context, $title, $key_points_text, $is_last
				);

				$body = wp_json_encode( [
					'model'          => 'anthropic/claude-sonnet-4-5',
					'fallback_model' => 'google/gemini-2.5-pro',
					'messages'       => [
						[ 'role' => 'system', 'content' => $section_system ],
						[ 'role' => 'user',   'content' => $user_prompt ],
					],
					'temperature'    => 0.3,
					'max_tokens'     => 6000,
					'purpose'        => 'executor',
					'timeout'        => 280,
					'site_url'       => home_url(),
				] );

				$ch = curl_init( $endpoint );
				curl_setopt_array( $ch, [
					CURLOPT_POST           => true,
					CURLOPT_POSTFIELDS     => $body,
					CURLOPT_HTTPHEADER     => [
						'Content-Type: application/json',
						'Authorization: Bearer ' . $api_key,
						'X-Site-URL: ' . home_url(),
					],
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_TIMEOUT        => 280,
					CURLOPT_CONNECTTIMEOUT => 15,
					CURLOPT_SSL_VERIFYPEER => false,
					CURLOPT_SSL_VERIFYHOST => 0,
				] );
				curl_multi_add_handle( $mh, $ch );
				$handles[ $global_idx ] = [
					'ch'       => $ch,
					'batch_no' => $bn,
					'label'    => $label,
					'is_last'  => $is_last,
					'done'     => false,
					'result'   => null,
				];
			}
		}

		error_log( sprintf( '[BZDoc][CurlMulti] START sections=%d endpoint=%s',
			count( $handles ), $endpoint ) );

		self::sse_send( 'progress', [
			'status'  => 'parallel_dispatched',
			'message' => count( $handles ) . " phần đang được tạo song song...",
		] );

		// ── curl_multi exec loop với SSE keepalive ───────────────────────────
		$active   = null;
		$tick     = 0;
		$batch_done_count = array_fill( 0, $batch_count, 0 );
		$batch_size_map   = [];
		foreach ( $batches as $bn => $batch ) {
			$batch_size_map[ $bn ] = count( $batch );
		}
		$deadline = microtime( true ) + 280.0;

		do {
			$status = curl_multi_exec( $mh, $active );
			// Wait up to 1.5s for activity, then loop to send keepalive ping.
			if ( $active && $status === CURLM_OK ) {
				curl_multi_select( $mh, 1.5 );
			}

			// Drain finished handles.
			while ( $info = curl_multi_info_read( $mh ) ) {
				if ( $info['msg'] !== CURLMSG_DONE ) continue;
				$ch = $info['handle'];
				// Find which global_idx this handle belongs to.
				$found_idx = null;
				foreach ( $handles as $gidx => &$h ) {
					if ( $h['ch'] === $ch && ! $h['done'] ) {
						$found_idx = $gidx;
						$h['done'] = true;
						$body = curl_multi_getcontent( $ch );
						$code = curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
						$err  = curl_error( $ch );
						$h['result'] = [ 'code' => $code, 'body' => $body, 'err' => $err ];
						break;
					}
				}
				unset( $h );
				curl_multi_remove_handle( $mh, $ch );
				curl_close( $ch );

				if ( $found_idx !== null ) {
					$bn = $handles[ $found_idx ]['batch_no'];
					$batch_done_count[ $bn ]++;
					self::sse_send( 'progress', [
						'status'  => 'batch_working',
						'batch'   => $bn + 1,
						'batches' => $batch_count,
						'done'    => $batch_done_count[ $bn ],
						'of'      => $batch_size_map[ $bn ],
						'message' => "Luồng " . ( $bn + 1 ) . ": {$batch_done_count[$bn]}/{$batch_size_map[$bn]} phần xong.",
					] );
					if ( $batch_done_count[ $bn ] === $batch_size_map[ $bn ] ) {
						self::sse_send( 'progress', [
							'status'  => 'batch_done',
							'batch'   => $bn + 1,
							'batches' => $batch_count,
							'message' => "Luồng " . ( $bn + 1 ) . " hoàn thành.",
						] );
					}
				}
			}

			// Keepalive ping every loop iteration.
			self::sse_send( 'ping', [ 'tick' => ++$tick ] );

			if ( microtime( true ) > $deadline ) {
				error_log( '[BZDoc][CurlMulti] DEADLINE reached, aborting remaining handles' );
				break;
			}
		} while ( $active && $status === CURLM_OK );

		// Cleanup any leftover handles (timeout / abort).
		foreach ( $handles as $gidx => $h ) {
			if ( ! $h['done'] && is_resource( $h['ch'] ) ) {
				curl_multi_remove_handle( $mh, $h['ch'] );
				curl_close( $h['ch'] );
			}
		}
		curl_multi_close( $mh );

		// ── Parse responses → all_results[ global_idx ] = elements[] ─────────
		$all_results = [];
		$ok_count    = 0;
		$fail_count  = 0;
		foreach ( $handles as $gidx => $h ) {
			$entry_label = $h['label'] ?: ( 'Phần ' . ( $gidx + 1 ) );
			if ( ! $h['done'] || ! $h['result'] ) {
				$fail_count++;
				$all_results[ $gidx ] = [
					[ 'type' => 'heading2',  'text' => $entry_label ],
					[ 'type' => 'paragraph', 'text' => '[Phần này không kịp tạo (timeout). Vui lòng tạo lại bằng chat.]' ],
					[ 'type' => 'divider' ],
				];
				continue;
			}
			$res = $h['result'];
			if ( $res['code'] !== 200 || $res['err'] ) {
				$fail_count++;
				error_log( sprintf( '[BZDoc][CurlMulti] section=%d HTTP %d err=%s body_first=%s',
					$gidx, $res['code'], $res['err'], substr( (string) $res['body'], 0, 200 ) ) );
				$all_results[ $gidx ] = [
					[ 'type' => 'heading2',  'text' => $entry_label ],
					[ 'type' => 'paragraph', 'text' => '[Lỗi gateway HTTP ' . $res['code'] . '. Vui lòng tạo lại bằng chat.]' ],
					[ 'type' => 'divider' ],
				];
				continue;
			}
			$decoded = json_decode( (string) $res['body'], true );
			$ai_text = '';
			if ( is_array( $decoded ) && ! empty( $decoded['success'] ) ) {
				$ai_text = (string) ( $decoded['message'] ?? '' );
			}
			$parsed = $ai_text !== '' ? self::parse_ai_json( $ai_text ) : null;
			if ( is_array( $parsed ) && ! empty( $parsed['elements'] ) ) {
				$ok_count++;
				$all_results[ $gidx ] = $parsed['elements'];
			} else {
				$fail_count++;
				error_log( sprintf( '[BZDoc][CurlMulti] section=%d parse failed, ai_len=%d',
					$gidx, mb_strlen( $ai_text ) ) );
				$all_results[ $gidx ] = [
					[ 'type' => 'heading2',  'text' => $entry_label ],
					[ 'type' => 'paragraph', 'text' => '[Không phân tích được kết quả AI cho phần này.]' ],
					[ 'type' => 'divider' ],
				];
			}
		}

		error_log( sprintf( '[BZDoc][CurlMulti] DONE ok=%d fail=%d total=%d ticks=%d',
			$ok_count, $fail_count, count( $handles ), $tick ) );

		ksort( $all_results );

		// ── Assemble final schema ─────────────────────────────────────────────
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
				'header'      => [ 'text' => $title, 'align' => 'left', 'show_page_numbers' => false ],
				'footer'      => [ 'text' => 'Trang', 'show_page_numbers' => true ],
				'elements'    => [],
			] ],
		];

		// Title page + all section elements in order.
		$elements = [
			[ 'type' => 'heading1', 'text' => $title, 'alignment' => 'center' ],
		];
		if ( ! empty( $nucleus['thesis'] ) ) {
			$elements[] = [ 'type' => 'paragraph', 'text' => $nucleus['thesis'], 'alignment' => 'center' ];
		}
		$elements[] = [ 'type' => 'divider' ];
		foreach ( $all_results as $section_els ) {
			foreach ( $section_els as $el ) {
				$elements[] = $el;
			}
		}

		self::sse_send( 'progress', [
			'status'  => 'saving',
			'total'   => $total,
			'message' => 'Đang lưu tài liệu...',
		] );

		$schema['sections'][0]['elements'] = $elements;
		$schema = self::ensure_defaults( $schema, 'document', $topic, $theme );

		if ( is_wp_error( $schema ) ) {
			self::complete_generation( $gen_id, 'failed', $start_time, $schema->get_error_message() );
			self::sse_send( 'error', [ 'message' => $schema->get_error_message() ] );
			return;
		}

		$saved = self::auto_save_document( $doc_id, $user_id, 'document', $title, $template, $theme, $schema );
		self::complete_generation( $gen_id, 'completed', $start_time, null, $saved, $schema );

		self::sse_send( 'done', [
			'success' => true,
			'data'    => $schema,
			'doc_id'  => $saved,
			'gen_id'  => $gen_id,
		] );
	}

	/**
	 * SSE variant of generate_document_sectional.
	 * Sends progress events after each section so the web-server
	 * connection stays alive during the full multi-step generation.
	 */
	private static function generate_document_sectional_sse( \WP_REST_Request $request, array $skeleton ): void {
		$user_id  = get_current_user_id();
		$topic    = sanitize_textarea_field( $request->get_param( 'topic' ) ?: '' );
		$template = sanitize_text_field( $request->get_param( 'template_name' ) ?: 'blank' );
		$theme    = sanitize_text_field( $request->get_param( 'theme_name' ) ?: 'modern' );
		$doc_id   = absint( $request->get_param( 'doc_id' ) ?: 0 );

		// Source context
		$source_context  = '';
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
		$total   = count( $nodes );

		self::sse_send( 'progress', [
			'status'  => 'start',
			'current' => 0,
			'total'   => $total,
			'message' => "Bắt đầu tạo {$total} phần...",
		] );

		// Build document shell
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
				'header'      => [ 'text' => $title, 'align' => 'left', 'show_page_numbers' => false ],
				'footer'      => [ 'text' => 'Trang', 'show_page_numbers' => true ],
				'elements'    => [],
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

		$section_system = self::system_prompt_document_section();
		$success        = 0;

		// PHASE-6.4 Wave C5 (May 2026) — Split-two mode.
		// When the FE sets split_two=true, we run the section loop in TWO
		// batches against the SAME doc_id: after batch 1 finishes we save the
		// (still-incomplete) schema, send a `part_done` SSE event so the
		// iframe shows the first half immediately, then continue with batch 2
		// and finally send the usual `done`. Total wall-clock is the same as
		// running all sections in one go, but the user sees content earlier
		// AND if the second half times out the first half is already saved
		// and viewable.
		$split_two = (bool) $request->get_param( 'split_two' );
		if ( $split_two && $total >= 2 ) {
			$mid     = (int) ceil( $total / 2 );
			$batches = [ array_slice( $nodes, 0, $mid ), array_slice( $nodes, $mid ) ];
		} else {
			$batches = [ $nodes ];
		}

		$global_idx = 0; // running index across all batches for progress display
		foreach ( $batches as $batch_no => $batch_nodes ) {
			$is_split    = count( $batches ) > 1;
			$batch_label = $is_split ? ( $batch_no === 0 ? 'Phần 1/2' : 'Phần 2/2' ) : '';
			if ( $is_split ) {
				self::sse_send( 'progress', [
					'status'  => 'batch_start',
					'batch'   => $batch_no + 1,
					'batches' => count( $batches ),
					'message' => "Bắt đầu {$batch_label}...",
				] );
			}

			foreach ( $batch_nodes as $node ) {
				$global_idx++;
				$idx      = $global_idx - 1;
				$label    = is_array( $node ) ? ( $node['label'] ?? $node['heading'] ?? '' ) : (string) $node;
				$summary  = is_array( $node ) ? ( $node['summary'] ?? '' ) : '';
				$children = is_array( $node ) ? ( $node['children'] ?? [] ) : [];
				$is_last  = ( $global_idx === $total );

			self::sse_send( 'progress', [
				'status'  => 'generating',
				'current' => $idx + 1,
				'total'   => $total,
				'section' => $label,
				'message' => 'Đang tạo phần ' . ( $idx + 1 ) . "/{$total}: {$label}",
			] );

			$section_prompt = self::build_section_user_prompt(
				$label, $summary, $children, $source_context, $title, $key_points_text, $is_last
			);

			// Keepalive ping while waiting for this section's LLM call
			$tick         = 0;
			$on_keepalive = static function () use ( &$tick ) {
				$tick++;
				self::sse_send( 'ping', [ 'tick' => $tick ] );
			};

			$section_tokens = ! empty( $source_context ) ? 11000 : 8000;
			$ai_response    = self::call_llm( $section_system, $section_prompt, $section_tokens, $on_keepalive );

			// Retry once on LLM failure
			if ( is_wp_error( $ai_response ) ) {
				$ai_response = self::call_llm( $section_system, $section_prompt, $section_tokens, $on_keepalive );
			}

			if ( is_wp_error( $ai_response ) ) {
				$elements[] = [ 'type' => 'heading2',  'text' => $label ];
				$elements[] = [ 'type' => 'paragraph',  'text' => '[Không thể sinh nội dung cho phần này. Vui lòng thử chỉnh sửa bằng chat.]' ];
				$elements[] = [ 'type' => 'divider' ];
				continue;
			}

			$section_data = self::parse_ai_json( $ai_response );

			// If parse failed, retry without source context to reduce token pressure
			if ( is_wp_error( $section_data ) ) {
				$retry_prompt = self::build_section_user_prompt(
					$label, $summary, $children, '', $title, $key_points_text, $is_last
				);
				$ai_retry = self::call_llm( $section_system, $retry_prompt, 6000, null );
				if ( ! is_wp_error( $ai_retry ) ) {
					$section_data = self::parse_ai_json( $ai_retry );
				}
			}

			if ( is_wp_error( $section_data ) ) {
				$elements[] = [ 'type' => 'heading2',  'text' => $label ];
				$elements[] = [ 'type' => 'paragraph',  'text' => '[Không thể phân tích JSON cho phần này. Vui lòng thử chỉnh sửa bằng chat.]' ];
				$elements[] = [ 'type' => 'divider' ];
				continue;
			}

			$section_elements = $section_data['elements'] ?? $section_data;
			if ( ! is_array( $section_elements ) || empty( $section_elements ) ) {
				$elements[] = [ 'type' => 'heading2',  'text' => $label ];
				$elements[] = [ 'type' => 'paragraph',  'text' => '[Nội dung rỗng.]' ];
				$elements[] = [ 'type' => 'divider' ];
				continue;
			}

			foreach ( $section_elements as $el ) {
				if ( is_array( $el ) && ! empty( $el['type'] ) ) {
					if ( $el['type'] === 'table' && ! empty( $el['rows'] ) ) {
						$el = self::repair_empty_table_cells( $el );
					}
					$elements[] = $el;
				}
			}

			if ( ! $is_last ) {
				$elements[] = [ 'type' => 'divider' ];
			}

			$success++;
			} // end inner foreach (sections in this batch)

			// PHASE-6.4 Wave C5 — Flush partial schema after batch 1 of split mode.
			// We persist the doc and emit `part_done` so the iframe can render
			// the first half right away. If batch 2 later fails or times out,
			// the user still has the first half safely saved.
			if ( $is_split && $batch_no < count( $batches ) - 1 ) {
				$schema['sections'][0]['elements'] = $elements;
				$partial = self::ensure_defaults( $schema, 'document', $topic, $theme );
				if ( ! is_wp_error( $partial ) ) {
					$saved_partial = self::auto_save_document( $doc_id, $user_id, 'document', $title, $template, $theme, $partial );
					if ( $saved_partial > 0 ) {
						$doc_id = $saved_partial; // reuse for batch 2 save
					}
					self::sse_send( 'part_done', [
						'batch'   => $batch_no + 1,
						'batches' => count( $batches ),
						'doc_id'  => $doc_id,
						'data'    => $partial,
						'message' => "Đã xong {$batch_label}, đang viết tiếp...",
					] );
				}
			}
		} // end outer foreach (batches)

		self::sse_send( 'progress', [
			'status'  => 'saving',
			'current' => $total,
			'total'   => $total,
			'message' => 'Đang lưu tài liệu...',
		] );

		$schema['sections'][0]['elements'] = $elements;
		$schema = self::ensure_defaults( $schema, 'document', $topic, $theme );

		if ( is_wp_error( $schema ) ) {
			self::complete_generation( $gen_id, 'failed', $start_time, $schema->get_error_message() );
			self::sse_send( 'error', [ 'message' => $schema->get_error_message() ] );
			return;
		}

		$saved = self::auto_save_document( $doc_id, $user_id, 'document', $title, $template, $theme, $schema );
		self::complete_generation( $gen_id, 'completed', $start_time, null, $saved, $schema );

		self::sse_send( 'done', [
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
9. Mix element types: paragraphs, bullet_list, numbered_list, tables, text_runs

## CITATION RULES (MANDATORY when reference material is provided)
The reference block uses headers `[ĐOẠN TRÍCH #X | Nguồn: "<title>"]` or `[TÀI LIỆU #X | Nguồn: "<title>"]`. The string inside the quotes is the SOURCE NAME.

1. **Inline citation**: Every claim, threshold, criterion, statistic, or recommendation taken from the reference MUST end with `(Nguồn: "<source name>")` or `(Theo "<source name>")`.
2. **Direct quotes**: Include at least 2 direct verbatim quotes per section as a paragraph using `text_runs` with `"italic": true`. Format:
   ```
   { "type": "paragraph", "text_runs": [
     { "text": "Theo \"<source name>\": ", "bold": true },
     { "text": "\"<verbatim quote from source>\"", "italic": true }
   ] }
   ```
3. **Deep analysis after each quote**: Every direct quote MUST be followed by an analytical paragraph (3-5 sentences) that interprets, applies, or contextualizes the quote — NOT a restatement.
4. **Multi-source compare/contrast**: When 2+ sources address the same point, write a paragraph naming both sources and explaining their agreement / divergence.
5. **No vague claims**: Generic phrases like "rất quan trọng", "có ý nghĩa", "cần lưu ý" are FORBIDDEN unless backed by a quoted source line.

## CRITICAL RULES
1. Output ONLY valid JSON: { "elements": [...] } — no markdown fences, no explanation
2. All string values must use proper JSON escaping (no raw newlines inside strings)
3. Tables MUST have isHeader: true on first row
4. Keep all content within this single section — do NOT generate other sections
5. ALL table cells MUST be filled with data — empty string "" cells are FORBIDDEN. Fill every cell.
6. Do NOT truncate tables — if a table has N rows in the source, generate ALL N rows
7. If you are running low on output space, PRIORITIZE completing the current table before starting a new element
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
			$parts[] = "Trích xuất dữ liệu, thống kê, phát hiện liên quan đến phần này.";
			$parts[] = "Mỗi đoạn trích đều có header dạng [ĐOẠN TRÍCH #X | Nguồn: \"<TÊN TÀI LIỆU>\"] hoặc [TÀI LIỆU #X | Nguồn: \"<TÊN TÀI LIỆU>\"].";
			$parts[] = "Phần tên tài liệu trong dấu \" là TÊN NGUỒN bạn PHẢI dùng khi trích dẫn (không bịa tên).";
			$parts[] = $source_context;
			$parts[] = "=== HẾT TÀI LIỆU ===";

			$parts[] = "\nYÊU CẦU TRÍCH DẪN BẮT BUỘC (vì có tài liệu tham khảo):";
			$parts[] = "1. MỌI nhận định, ngưỡng số, tiêu chí, khuyến nghị lấy từ tài liệu phải kết thúc bằng (Nguồn: \"<tên tài liệu>\") hoặc (Theo \"<tên tài liệu>\").";
			$parts[] = "2. Trong phần này phải có TỐI THIỂU 2 trích dẫn nguyên văn (in nghiêng), mỗi trích dẫn ngay sau là 1 đoạn phân tích sâu 3-5 câu (giải thích, áp dụng, đối chiếu — KHÔNG diễn đạt lại).";
			$parts[] = "3. Format trích dẫn nguyên văn dùng text_runs:";
			$parts[] = "   { \"type\": \"paragraph\", \"text_runs\": [ { \"text\": \"Theo \\\"<tên>\\\": \", \"bold\": true }, { \"text\": \"\\\"<câu trích nguyên văn>\\\"\", \"italic\": true } ] }";
			$parts[] = "4. Khi 2+ tài liệu cùng đề cập cùng vấn đề, phải có 1 đoạn so sánh/đối chiếu nêu rõ tên cả hai nguồn.";
			$parts[] = "5. Cấm câu chung chung kiểu \"rất quan trọng\", \"có ý nghĩa\", \"cần lưu ý\" trừ khi đi kèm trích dẫn nguồn.";
		}

		$parts[] = "\nYÊU CẦU BẮT BUỘC:";
		$parts[] = "1. Viết nội dung đầy đủ, chi tiết, chuyên nghiệp cho phần này.";
		$parts[] = "2. MỌI BẢNG phải được điền ĐẦY ĐỦ tất cả các ô — TUYỆT ĐỐI không để ô trống. Nếu thiếu dữ liệu, điền nội dung phù hợp từ kiến thức chuyên môn.";
		$parts[] = "3. Không cắt ngắn bảng — điền đủ tất cả hàng dữ liệu theo tài liệu gốc.";
		$parts[] = "4. Trả về JSON duy nhất, không markdown, không giải thích.";

		return implode( "\n", $parts );
	}

	/* ═══════════════════════════════════════════════
	   HELPERS
	   ═══════════════════════════════════════════════ */

	/**
	 * Repair table elements that have empty cells due to truncation.
	 * Removes rows where all non-header cells are empty strings.
	 * Marks surviving rows with a warning if any single cell is empty.
	 */
	private static function repair_empty_table_cells( array $el ): array {
		if ( empty( $el['rows'] ) || ! is_array( $el['rows'] ) ) return $el;

		$col_count = 0;
		$repaired_rows = [];
		$has_data_rows = false;

		foreach ( $el['rows'] as $row ) {
			if ( empty( $row['cells'] ) || ! is_array( $row['cells'] ) ) continue;

			$cells = $row['cells'];
			if ( $col_count === 0 ) $col_count = count( $cells );

			$is_header = ! empty( $row['isHeader'] );

			if ( $is_header ) {
				$repaired_rows[] = $row;
				continue;
			}

			// Check if row is entirely empty (truncation artifact)
			$non_empty = array_filter( $cells, fn( $c ) => trim( (string) $c ) !== '' );
			if ( empty( $non_empty ) ) {
				// Skip completely empty rows (were not generated due to token cutoff)
				error_log( '[BZDoc] Removed empty table row (truncation artifact)' );
				continue;
			}

			// Fill individual empty cells with a placeholder so rendering doesn't break
			if ( count( $non_empty ) < count( $cells ) ) {
				error_log( '[BZDoc] Partial empty cells in row — filling with placeholder' );
				$cells = array_map( fn( $c ) => trim( (string) $c ) !== '' ? $c : '—', $cells );
				$row['cells'] = $cells;
			}

			$repaired_rows[] = $row;
			$has_data_rows = true;
		}

		// If all data rows were stripped (entire table was empty), keep original
		// to avoid losing the header context
		if ( ! $has_data_rows && count( $el['rows'] ) > 1 ) {
			$header = $el['rows'][0];
			$placeholder_cells = array_fill( 0, $col_count ?: 3, '[Chưa có dữ liệu]' );
			$el['rows'] = [
				$header,
				[ 'cells' => $placeholder_cells ],
			];
			error_log( '[BZDoc] Table had no data rows after repair — added placeholder row' );
			return $el;
		}

		$el['rows'] = $repaired_rows;
		return $el;
	}

	/**
	 * Walk the JSON char-by-char and:
	 * 1. Escape raw control characters (TAB=0x09, LF=0x0A, CR=0x0D, etc.) that
	 *    appear inside JSON string values — they are illegal there and cause
	 *    JSON_ERROR_STATE_MISMATCH in PHP's json_decode.
	 * 2. Remove invalid escape sequences (\v, \a, \e, \j, … ) that some LLMs
	 *    generate (valid in JavaScript but not in JSON spec RFC 8259).
	 *
	 * This is the #1 cause of "State mismatch" errors from LLM-generated JSON.
	 */
	private static function fix_json_strings( string $json ): string {
		$result    = '';
		$len       = strlen( $json );
		$in_string = false;
		$escape    = false;

		for ( $i = 0; $i < $len; $i++ ) {
			$ch  = $json[ $i ];
			$ord = ord( $ch );

			if ( $escape ) {
				$escape = false;
				// Valid JSON escape chars after a backslash: " \ / b f n r t u
				if ( $ch === '"'  || $ch === '\\'  || $ch === '/'  ||
				     $ch === 'b'  || $ch === 'f'   || $ch === 'n'  ||
				     $ch === 'r'  || $ch === 't'   || $ch === 'u' ) {
					$result .= $ch;
				} else {
					// Invalid escape: drop the backslash we already appended.
					$result = substr( $result, 0, -1 );
					if ( $ord < 0x20 ) {
						// The char itself is a control char — convert to valid escape.
						$map    = [ 0x08 => '\\b', 0x09 => '\\t', 0x0A => '\\n', 0x0C => '\\f', 0x0D => '\\r' ];
						$result .= $map[ $ord ] ?? sprintf( '\\u%04x', $ord );
					} else {
						// Normal printable char after invalid backslash — keep as-is.
						$result .= $ch;
					}
				}
				continue;
			}

			if ( $ch === '\\' && $in_string ) {
				$escape  = true;
				$result .= $ch;
				continue;
			}

			if ( $ch === '"' ) {
				$in_string = ! $in_string;
				$result   .= $ch;
				continue;
			}

			// Raw control character inside a JSON string — must be escaped.
			if ( $in_string && $ord < 0x20 ) {
				$map    = [ 0x08 => '\\b', 0x09 => '\\t', 0x0A => '\\n', 0x0C => '\\f', 0x0D => '\\r' ];
				$result .= $map[ $ord ] ?? sprintf( '\\u%04x', $ord );
				continue;
			}

			$result .= $ch;
		}

		return $result;
	}

	private static function parse_ai_json( string $content ) {
		// Log raw length for debugging truncation
		$raw_len = strlen( $content );
		error_log( '[BZDoc] AI response length: ' . $raw_len . ' chars' );

		// Strip markdown code fences
		$content = preg_replace( '/^```(?:json)?\s*/m', '', $content );
		$content = preg_replace( '/\s*```\s*$/m', '', $content );
		$content = trim( $content );

		// Strip always-illegal control chars globally (NUL, SOH…BS, VT, FF, SO…US).
		// NOTE: we intentionally keep 0x09(TAB), 0x0A(LF), 0x0D(CR) here because
		// they are valid JSON whitespace between tokens; fix_json_strings() will
		// escape them when they appear inside string values.
		$content = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $content );

		// Repair invalid UTF-8 sequences — common when the LLM response is cut
		// mid-multibyte character (e.g. mid Vietnamese diacritic). PHP json_decode
		// returns JSON_ERROR_STATE_MISMATCH for invalid UTF-8.
		if ( function_exists( 'mb_convert_encoding' ) ) {
			$cleaned = @mb_convert_encoding( $content, 'UTF-8', 'UTF-8' );
			if ( is_string( $cleaned ) ) $content = $cleaned;
		}

		// Fix raw control chars (TAB/LF/CR) inside JSON string values and remove
		// invalid escape sequences (\v, \a, \j, etc. — legal in JS but not JSON).
		// This is the primary cause of JSON_ERROR_STATE_MISMATCH from LLM output.
		$content = self::fix_json_strings( $content );

		$data = json_decode( $content, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			// Retry: extract outermost { ... }
			if ( preg_match( '/\{[\s\S]*\}/u', $content, $m ) ) {
				$extracted = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $m[0] );
				$extracted = self::fix_json_strings( $extracted );
				$data      = json_decode( $extracted, true );
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

		// ── Phase 6.3: Mindmap defaults ──
		if ( $doc_type === 'mindmap' ) {
			if ( empty( $schema['root'] ) || ! is_array( $schema['root'] ) ) {
				return new \WP_Error( 'invalid_schema', 'Invalid mindmap structure: missing root node.', [ 'status' => 500 ] );
			}
			if ( empty( $schema['title'] ) ) {
				$schema['title'] = $topic ?: 'Mindmap';
			}
			$schema['theme']     = $schema['theme']     ?? 'colorful';
			$schema['direction'] = $schema['direction'] ?? 'LR';
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

	/**
	 * PHASE-0.13 — last Graph-RAG retrieval result captured by build_source_context().
	 * Holds the passages list (shape compatible with bizcity_kg_validate_citations_in_json)
	 * so the post-LLM validator can verify cited [src:N#pM] markers without re-querying.
	 *
	 * @var array{ passages: array<int, array{source_id:int, passage_id:int, source_title:string, index:int}>, kg_entities: array<int, array{id:int, name:string}>, mode: string }
	 */
	private static $last_kg_context = [
		'passages'    => [],
		'kg_entities' => [],
		'mode'        => '',
	];

	/**
	 * Public read-only accessor for the last KG context — used by post-LLM validator.
	 */
	public static function get_last_kg_context(): array {
		return self::$last_kg_context;
	}

	/**
	 * PHASE-0.13 — validate citation markers in a generated bzdoc schema against
	 * the passages list captured by build_kg_graph_context(). Returns a report
	 * suitable for the FE `validation` event (mirrors TwinChat shape).
	 *
	 * Warn-only: never blocks the response on first pass. Hallucinated markers
	 * are logged so the team can tune the prompt before flipping to enforce mode.
	 *
	 * @param mixed $schema  Decoded JSON schema (or raw text for spreadsheets).
	 * @return array {
	 *   mode: string ('graph'|'vector'|'raw'|'none'),
	 *   ok:   bool,
	 *   total_markers: int,
	 *   missing: array,           — hallucinated marker labels
	 *   hallucinated_fields: array,
	 *   passage_count: int,       — passages actually fed to the LLM
	 * }
	 */
	private static function run_citation_validator( $schema ): array {
		$ctx = self::$last_kg_context;
		$mode = (string) ( $ctx['mode'] ?? 'none' );
		$base = [
			'mode'                => $mode,
			'ok'                  => true,
			'total_markers'       => 0,
			'missing'             => [],
			'hallucinated_fields' => [],
			'passage_count'       => count( $ctx['passages'] ?? [] ),
		];
		// Only validate Graph-RAG mode; vector/raw paths use prose citations.
		if ( $mode !== 'graph' || empty( $ctx['passages'] ) ) {
			return $base;
		}
		if ( ! function_exists( 'bizcity_kg_validate_citations_in_json' ) ) {
			return $base;
		}
		$report = bizcity_kg_validate_citations_in_json(
			$schema,
			$ctx['passages'],
			$ctx['kg_entities'] ?? [],
			[]
		);
		if ( ! is_array( $report ) ) return $base;

		$out = array_merge( $base, [
			'ok'                  => (bool) ( $report['ok'] ?? true ),
			'total_markers'       => (int) ( $report['total_markers'] ?? 0 ),
			'missing'             => array_values( (array) ( $report['missing'] ?? [] ) ),
			'hallucinated_fields' => array_values( (array) ( $report['hallucinated_fields'] ?? [] ) ),
		] );

		if ( ! empty( $out['missing'] ) ) {
			error_log( sprintf(
				'[BZDoc] CITATION-V2 hallucinated markers (warn-only): %s | passages_available=%d',
				wp_json_encode( $out['missing'] ),
				$out['passage_count']
			) );
		}
		return $out;
	}

	private static function build_source_context( int $doc_id, string $topic ): string {
		// Reset per-request state so a previous call doesn't leak into validator.
		self::$last_kg_context = [ 'passages' => [], 'kg_entities' => [], 'mode' => '' ];

		$sources = BZDoc_Sources::list_by_doc( $doc_id );
		if ( empty( $sources ) ) {
			error_log( '[BZDoc] build_source_context: doc_id=' . $doc_id . ' — no sources found' );
			return '';
		}
		error_log( '[BZDoc] build_source_context: doc_id=' . $doc_id . ' — ' . count( $sources ) . ' sources' );

		// PHASE-0.13 — Graph-RAG path. Use BizCity_KG_Retriever when this doc is
		// bound to a notebook AND the retriever class is loaded. Falls back to
		// the legacy embedder/raw-concat path on any failure.
		$notebook_id = self::lookup_doc_notebook_id( $doc_id );
		if ( $notebook_id > 0 && ! empty( $topic ) && class_exists( 'BizCity_KG_Retriever' ) ) {
			$kg_context = self::build_kg_graph_context( $notebook_id, $topic );
			if ( $kg_context !== '' ) {
				error_log( sprintf(
					'[BZDoc] build_source_context: Graph-RAG hit doc=%d notebook=%d passages=%d ctx_len=%d',
					$doc_id, $notebook_id, count( self::$last_kg_context['passages'] ), strlen( $kg_context )
				) );
				return $kg_context;
			}
			error_log( '[BZDoc] build_source_context: Graph-RAG miss for notebook=' . $notebook_id . ' — falling back to embedder' );
		}

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
				self::$last_kg_context['mode'] = 'vector';
				return $context;
			}
		}

		// Fallback: all source content directly
		$fallback = BZDoc_Sources::get_all_content( $doc_id, 24000 );
		error_log( '[BZDoc] build_source_context: fallback get_all_content, len=' . strlen( $fallback ) );
		self::$last_kg_context['mode'] = 'raw';
		return $fallback;
	}

	/**
	 * PHASE-0.13 — read the notebook_id binding for a bzdoc document.
	 * Returns 0 when the doc is unbound or doesn't exist.
	 */
	private static function lookup_doc_notebook_id( int $doc_id ): int {
		if ( $doc_id <= 0 ) return 0;
		global $wpdb;
		$tbl = $wpdb->prefix . 'bzdoc_documents';
		$nb  = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT notebook_id FROM {$tbl} WHERE id = %d",
			$doc_id
		) );
		return max( 0, $nb );
	}

	/**
	 * PHASE-0.13 — call BizCity_KG_Retriever and format the resulting passages
	 * as a context block annotated with `[src:N#pM]` IDs the LLM is instructed
	 * to cite. Stores the passages map in self::$last_kg_context for validator.
	 *
	 * @return string Empty string when no graph data is available.
	 */
	private static function build_kg_graph_context( int $notebook_id, string $question ): string {
		try {
			$retr = BizCity_KG_Retriever::instance()->ask( $notebook_id, $question, [
				'answer'         => false,
				'seed_entities'  => 4,
				'seed_relations' => 20,
				'rerank_top_k'   => 6,
				'expand_hops'    => 1,
			] );
		} catch ( \Throwable $e ) {
			error_log( '[BZDoc] KG_Retriever threw: ' . $e->getMessage() );
			return '';
		}

		if ( ! is_array( $retr ) || empty( $retr['passages'] ) ) {
			return '';
		}

		$passages = is_array( $retr['passages'] ) ? array_slice( $retr['passages'], 0, 8 ) : [];
		if ( empty( $passages ) ) return '';

		// Resolve source titles via the shared helper (cross-table lookup).
		$source_ids = [];
		foreach ( $passages as $p ) {
			$sid = (int) ( $p['source_id'] ?? 0 );
			if ( $sid > 0 ) $source_ids[ $sid ] = true;
		}
		$titles_by_id = [];
		if ( ! empty( $source_ids ) && class_exists( 'BizCity_KG_Source_Service' ) ) {
			$svc = BizCity_KG_Source_Service::instance();
			foreach ( array_keys( $source_ids ) as $sid ) {
				$meta = $svc->lookup_source_meta( (int) $sid );
				if ( $meta && ! empty( $meta['title'] ) ) {
					$titles_by_id[ (int) $sid ] = (string) $meta['title'];
				}
			}
		}
		// Top-up missing titles directly from kg_sources.
		$missing = array_values( array_diff( array_keys( $source_ids ), array_keys( $titles_by_id ) ) );
		if ( ! empty( $missing ) && class_exists( 'BizCity_KG_Database' ) ) {
			global $wpdb;
			$tbl_src = BizCity_KG_Database::instance()->tbl_sources();
			$placeholders = implode( ',', array_fill( 0, count( $missing ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, title FROM {$tbl_src} WHERE id IN ({$placeholders})",
				$missing
			), ARRAY_A );
			if ( is_array( $rows ) ) {
				foreach ( $rows as $r ) {
					$titles_by_id[ (int) $r['id'] ] = (string) $r['title'];
				}
			}
		}

		// Build context block. Each passage carries its canonical [src:N#pM] tag
		// so the LLM can cite it byte-for-byte without inventing IDs.
		$blocks   = [];
		$cap_used = 0;
		$cap_max  = 24000; // chars budget — comparable to legacy raw fallback.
		$idx      = 1;
		$source_meta_for_validator = [];
		foreach ( $passages as $p ) {
			$sid     = (int) ( $p['source_id'] ?? 0 );
			$pid     = (int) ( $p['id'] ?? 0 );
			$content = (string) ( $p['content'] ?? '' );
			if ( $sid <= 0 || $content === '' ) continue;
			$title = $titles_by_id[ $sid ] ?? ( 'Source #' . $sid );
			$tag   = $pid > 0 ? sprintf( '[src:%d#p%d]', $sid, $pid ) : sprintf( '[src:%d]', $sid );

			$remaining = $cap_max - $cap_used;
			if ( $remaining <= 200 ) break;
			$body = mb_strlen( $content ) > $remaining ? mb_substr( $content, 0, $remaining ) : $content;

			$blocks[] = sprintf(
				"[ĐOẠN TRÍCH #%d | Nguồn: \"%s\" | id:%s]\n%s",
				$idx, $title, $tag, $body
			);
			$cap_used += mb_strlen( $body );

			$source_meta_for_validator[] = [
				'index'        => $idx,
				'source_id'    => $sid,
				'passage_id'   => $pid,
				'source_title' => $title,
			];
			$idx++;
		}

		if ( empty( $blocks ) ) return '';

		// Build KG entity citation list (subgraph nodes) so validator can verify [ent:N].
		$kg_entities = [];
		if ( ! empty( $retr['subgraph']['nodes'] ) && is_array( $retr['subgraph']['nodes'] ) ) {
			foreach ( array_slice( $retr['subgraph']['nodes'], 0, 8 ) as $node ) {
				$eid  = (int) ( $node['id'] ?? 0 );
				$name = (string) ( $node['name'] ?? $node['label'] ?? '' );
				if ( $eid > 0 ) {
					$kg_entities[] = [ 'id' => $eid, 'name' => $name ];
				}
			}
		}

		self::$last_kg_context = [
			'passages'    => $source_meta_for_validator,
			'kg_entities' => $kg_entities,
			'mode'        => 'graph',
		];

		return implode( "\n---\n", $blocks );
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

		// Title column is VARCHAR(255). Topics from image-gen / agents can be
		// much longer (MAX_TOPIC = 5000) → truncate multibyte-safe to fit the
		// column without tripping wpdb's strict length check.
		if ( function_exists( 'mb_substr' ) ) {
			$title = mb_substr( $title, 0, 200, 'UTF-8' );
		} else {
			$title = substr( $title, 0, 200 );
		}

		// Defensive normalization — strip 4-byte UTF-8 (emoji/supplementary
		// planes) and control chars unconditionally. Custom tables often
		// don't report a charset via get_col_charset(), so we can't rely
		// on the conditional path. wpdb::strip_invalid_text() rejects with
		// the cryptic "may be too long or contains invalid data" otherwise.

		// Step 1: round-trip through mb_convert_encoding to drop any
		// malformed UTF-8 byte sequences (lone surrogates, truncated
		// multibyte). Without this, the /u regex below silent-fails and
		// returns the original (still-broken) string.
		if ( function_exists( 'mb_convert_encoding' ) ) {
			$cleaned = @mb_convert_encoding( $title, 'UTF-8', 'UTF-8' );
			if ( is_string( $cleaned ) ) $title = $cleaned;
		}
		if ( function_exists( 'wp_encode_emoji' ) ) {
			$title = wp_encode_emoji( $title );
		}
		// Drop any remaining non-BMP code points (>U+FFFF).
		$title = preg_replace( '/[\x{10000}-\x{10FFFF}]/u', '', $title ) ?? $title;
		// Drop ASCII control chars (sanitize_text_field already kills tab/newline).
		$title = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $title ) ?? $title;
		// Re-truncate after entity expansion may have grown the string.
		if ( function_exists( 'mb_substr' ) ) {
			$title = mb_substr( $title, 0, 200, 'UTF-8' );
		}

		if ( $title === '' ) {
			$title = 'Untitled';
		}

		// Self-heal: ensure tables exist before first insert of a new doc_type.
		if ( class_exists( 'BZDoc_Installer' ) ) {
			BZDoc_Installer::maybe_create_tables();
		}

		$row = [
			'user_id'       => $user_id,
			'doc_type'      => $doc_type,
			'title'         => $title,
			'template_name' => 'blank',
			'theme_name'    => 'modern',
			'schema_json'   => '{}',
			'status'        => 'draft',
			'created_at'    => current_time( 'mysql' ),
			'updated_at'    => current_time( 'mysql' ),
		];

		$inserted = $wpdb->insert( $table, $row );

		// Fail-safe: if wpdb's strip_invalid_text rejects the title (cryptic
		// "may be too long or contains invalid data"), retry with an
		// ASCII-only short title. The real topic lives in schema_json so
		// nothing user-visible is lost — only the doc-list label degrades.
		if ( $inserted === false ) {
			$last_err = $wpdb->last_error ?: '';
			if ( stripos( $last_err, 'title' ) !== false || stripos( $last_err, 'invalid data' ) !== false ) {
				error_log( sprintf(
					'[BZDoc][project_create] retry with safe title. orig_hex=%s',
					bin2hex( substr( $title, 0, 80 ) )
				) );
				$safe_title = preg_replace( '/[^A-Za-z0-9 _\-.,!?]/', '', $title );
				$safe_title = trim( substr( $safe_title, 0, 100 ) );
				if ( $safe_title === '' ) {
					$safe_title = ucfirst( $doc_type ) . ' ' . gmdate( 'Y-m-d H:i' );
				}
				$row['title'] = $safe_title;
				$inserted = $wpdb->insert( $table, $row );
				if ( $inserted !== false ) {
					$title = $safe_title;
				}
			}
		}

		if ( $inserted === false ) {
			error_log( sprintf(
				'[BZDoc][project_create] insert failed. blog_id=%d, table=%s, doc_type=%s, last_error=%s',
				(int) get_current_blog_id(),
				$table,
				$doc_type,
				$wpdb->last_error ?: '(empty)'
			) );
			error_log( sprintf(
				'[BZDoc][project_create] title hex (first 80B): %s',
				bin2hex( substr( $title, 0, 80 ) )
			) );
			return new \WP_Error(
				'db_insert_failed',
				'DB insert thất bại: ' . ( $wpdb->last_error ?: 'unknown error' ),
				[ 'status' => 500 ]
			);
		}

		$doc_id = (int) $wpdb->insert_id;
		if ( ! $doc_id ) {
			error_log( sprintf(
				'[BZDoc][project_create] insert OK but insert_id=0. blog_id=%d, table=%s',
				(int) get_current_blog_id(),
				$table
			) );
			return new \WP_Error( 'db_no_insert_id', 'insert_id = 0 sau khi insert.', [ 'status' => 500 ] );
		}

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

	/* ═════════════════════════════════════════════
	   GET SOURCE DETAIL — returns full source including content_text
	   ═════════════════════════════════════════════ */
	public static function handle_source_get( \WP_REST_Request $request ) {
		$source_id = absint( $request['id'] );
		if ( ! $source_id ) {
			return new \WP_Error( 'missing_source_id', 'source_id is required.', [ 'status' => 400 ] );
		}

		$source = BZDoc_Sources::get( $source_id );
		if ( ! $source ) {
			return new \WP_Error( 'not_found', 'Source not found.', [ 'status' => 404 ] );
		}

		// Security: only the owner can view source content
		$user_id = get_current_user_id();
		if ( (int) $source->user_id !== $user_id && ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'forbidden', 'Access denied.', [ 'status' => 403 ] );
		}

		return rest_ensure_response( [
			'id'               => (int) $source->id,
			'title'            => (string) $source->title,
			'source_type'      => (string) $source->source_type,
			'source_url'       => (string) ( $source->source_url ?? '' ),
			'char_count'       => (int) $source->char_count,
			'token_estimate'   => (int) $source->token_estimate,
			'chunk_count'      => (int) $source->chunk_count,
			'embedding_status' => (string) $source->embedding_status,
			'created_at'       => (string) $source->created_at,
			'content_text'     => (string) ( $source->content_text ?? '' ),
		] );
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
	/**
	 * Upload base64 reference images to WP Media Library and return HTTPS URLs.
	 *
	 * Why: sending raw base64 data URIs inline to OpenRouter / OpenAI can hit
	 * request-size limits and is slower than letting the provider fetch a URL.
	 * Uploading once → storing a permanent WP attachment → re-using the URL
	 * for both the LLM compose step and the image generation call.
	 *
	 * @param string[] $raw_refs  Array of data:image/... base64 URIs or existing HTTPS URLs.
	 * @param int      $user_id   Author for the new attachments.
	 * @return string[]           Same-length(-or-less) array of HTTPS attachment URLs.
	 */
	private static function upload_ref_images_to_media( array $raw_refs, int $user_id ): array {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$urls = [];
		foreach ( array_slice( $raw_refs, 0, 4 ) as $ref ) {
			if ( ! is_string( $ref ) || $ref === '' ) continue;

			// Already a plain HTTPS URL — keep as-is, no upload needed.
			if ( strpos( $ref, 'https://' ) === 0 ) {
				$urls[] = $ref;
				continue;
			}

			// Must be a data URI.
			if ( strpos( $ref, 'data:image/' ) !== 0 ) continue;

			// Parse: data:image/jpeg;base64,<payload>
			$comma = strpos( $ref, ',' );
			if ( $comma === false ) continue;

			$meta_part = substr( $ref, 5, $comma - 5 ); // "image/jpeg;base64"
			$b64       = substr( $ref, $comma + 1 );
			$bytes     = base64_decode( $b64, true );
			if ( $bytes === false || $bytes === '' ) continue;

			// Derive extension from MIME.
			$mime = strtolower( explode( ';', $meta_part )[0] ); // "image/jpeg"
			$ext  = [
				'image/jpeg' => 'jpg',
				'image/jpg'  => 'jpg',
				'image/png'  => 'png',
				'image/webp' => 'webp',
				'image/gif'  => 'gif',
			][ $mime ] ?? 'jpg';

			$upload = wp_upload_dir();
			if ( ! empty( $upload['error'] ) ) continue;

			$filename = sprintf( 'bzdoc-ref-%d-%s.%s', $user_id, wp_generate_password( 8, false, false ), $ext );
			$filepath = trailingslashit( $upload['path'] ) . $filename;

			if ( false === file_put_contents( $filepath, $bytes ) ) continue;

			$file_array = [
				'name'     => $filename,
				'tmp_name' => $filepath,
			];

			$att_id = media_handle_sideload( $file_array, 0, 'BizCity Doc Reference Image' );
			if ( is_wp_error( $att_id ) ) {
				@unlink( $filepath );
				continue;
			}

			// Tag the attachment so it can be cleaned up later if needed.
			update_post_meta( $att_id, '_bzdoc_ref_image', '1' );
			if ( $user_id ) {
				wp_update_post( [ 'ID' => $att_id, 'post_author' => $user_id ] );
			}

			$url = wp_get_attachment_url( $att_id );
			if ( $url ) $urls[] = $url;
		}

		return $urls;
	}

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
	   ADD YOUTUBE SOURCE — Extract transcript
	   ═══════════════════════════════════════════════ */
	public static function handle_source_add_youtube( \WP_REST_Request $request ) {
		$doc_id = absint( $request->get_param( 'doc_id' ) );
		$url    = sanitize_text_field( $request->get_param( 'url' ) ?? '' );

		if ( ! $doc_id ) {
			return new \WP_Error( 'missing_doc_id', 'doc_id is required.', [ 'status' => 400 ] );
		}
		if ( empty( $url ) ) {
			return new \WP_Error( 'missing_url', 'URL YouTube là bắt buộc.', [ 'status' => 400 ] );
		}

		$source_id = BZDoc_Sources::add_youtube( $doc_id, $url );
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
				'source_type'      => 'youtube',
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

	/* ═══════════════════════════════════════════════
	   Phase 6.4 — Image generation REST handlers
	   ═══════════════════════════════════════════════ */

	public static function handle_image_generate( \WP_REST_Request $request ) {
		@set_time_limit( 0 );

		$user_id = get_current_user_id();
		$doc_id  = absint( $request->get_param( 'doc_id' ) );

		// Auto-create a new image doc if not provided.
		if ( $doc_id === 0 ) {
			$topic_raw   = sanitize_text_field( (string) $request->get_param( 'topic' ) ?: 'Untitled Image' );
			$title_short = function_exists( 'mb_substr' )
				? mb_substr( $topic_raw, 0, 200, 'UTF-8' )
				: substr( $topic_raw, 0, 200 );

			$create = new \WP_REST_Request( 'POST' );
			$create->set_param( 'doc_type', 'image' );
			$create->set_param( 'title', $title_short );
			$resp = self::handle_project_create( $create );
			if ( is_wp_error( $resp ) ) {
				error_log( '[BZDoc][image_generate] project_create returned WP_Error: ' . $resp->get_error_code() . ' — ' . $resp->get_error_message() );
				return $resp;
			}
			$d = $resp->get_data();
			$doc_id = (int) ( $d['doc_id'] ?? 0 );
			if ( ! $doc_id ) {
				global $wpdb;
				$debug = sprintf(
					'doc_id=0 sau project_create. blog_id=%d, prefix=%s, table_exists=%s, last_error=%s, response=%s',
					(int) get_current_blog_id(),
					$wpdb->prefix,
					( $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}bzdoc_documents'" ) ? 'yes' : 'NO' ),
					$wpdb->last_error ?: '(empty)',
					wp_json_encode( $d )
				);
				error_log( '[BZDoc][image_generate] ' . $debug );
				return new \WP_Error( 'create_failed', 'Không tạo được image doc. Debug: ' . $debug, [ 'status' => 500 ] );
			}
		} else {
			// Verify ownership.
			global $wpdb;
			$owner = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT user_id FROM {$wpdb->prefix}bzdoc_documents WHERE id = %d", $doc_id
			) );
			if ( $owner !== $user_id ) {
				return new \WP_Error( 'forbidden', 'Bạn không sở hữu doc này.', [ 'status' => 403 ] );
			}
		}

		$payload = [
			'topic'        => sanitize_textarea_field( (string) $request->get_param( 'topic' ) ?: '' ),
			'prompt_id'    => absint( $request->get_param( 'prompt_id' ) ),
			'prompt_args'  => (array) ( $request->get_param( 'prompt_args' ) ?: [] ),
			'style_preset' => sanitize_text_field( (string) $request->get_param( 'style_preset' ) ?: '' ),
			'aspect_ratio' => sanitize_text_field( (string) $request->get_param( 'aspect_ratio' ) ?: '1:1' ),
			'n_variants'   => max( 1, min( 4, absint( $request->get_param( 'n_variants' ) ?: 1 ) ) ),
			'user_id'      => $user_id,
		];

		$result = BZDoc_Image_Pipeline::run( $doc_id, $payload );
		if ( is_wp_error( $result ) ) return $result;

		// Persist schema_json so the doc page can re-load variants without re-generating.
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'bzdoc_documents',
			[
				'schema_json' => wp_json_encode( [
					'doc_type'     => 'image',
					'topic'        => $payload['topic'],
					'final_prompt' => $result['final_prompt'],
					'aspect_ratio' => $result['aspect_ratio'],
					'variants'     => $result['variants'],
					'citations'    => $result['citations'],
					'job_id'       => $result['job_id'],
					'updated_at'   => current_time( 'mysql' ),
				], JSON_UNESCAPED_UNICODE ),
				'updated_at'  => current_time( 'mysql' ),
			],
			[ 'id' => $doc_id ]
		);

		return rest_ensure_response( [ 'success' => true, 'data' => $result ] );
	}

	/* ═══════════════════════════════════════════════
	   Phase 6.4 — Async image generation (start + poll)
	   ───────────────────────────────────────────────
	   Cloudflare's edge has a hard 100s request timeout. Heavy multimodal
	   image models (e.g. `openai/gpt-5.4-image-2`) routinely take 60-180s.
	   We split the synchronous flow:

	     1. POST /image/generate/start  → returns doc_id immediately,
	        marks `schema_json.status = 'pending'`, then detaches via
	        `fastcgi_finish_request()` and runs the pipeline in the
	        background process (no client connection).
	     2. GET /image/generate/status/{id} → frontend polls every ~2s,
	        reads `schema_json.status`. Returns 'pending' | 'done' | 'failed'
	        plus the full result payload when complete.

	   Status flow stored in `schema_json`:
	     { status, started_at, finished_at, error?, ...result fields }
	   ═══════════════════════════════════════════════ */

	public static function handle_image_generate_start( \WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$doc_id  = absint( $request->get_param( 'doc_id' ) );

		// 1. Auto-create doc if needed (reuses sanitization + multisite guard).
		if ( $doc_id === 0 ) {
			$topic_raw   = sanitize_text_field( (string) $request->get_param( 'topic' ) ?: 'Untitled Image' );
			$title_short = function_exists( 'mb_substr' )
				? mb_substr( $topic_raw, 0, 200, 'UTF-8' )
				: substr( $topic_raw, 0, 200 );

			$create = new \WP_REST_Request( 'POST' );
			$create->set_param( 'doc_type', 'image' );
			$create->set_param( 'title', $title_short );
			$resp = self::handle_project_create( $create );
			if ( is_wp_error( $resp ) ) {
				return $resp;
			}
			$d      = $resp->get_data();
			$doc_id = (int) ( $d['doc_id'] ?? 0 );
			if ( ! $doc_id ) {
				return new \WP_Error( 'create_failed', 'Không tạo được image doc.', [ 'status' => 500 ] );
			}
		} else {
			global $wpdb;
			$owner = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT user_id FROM {$wpdb->prefix}bzdoc_documents WHERE id = %d", $doc_id
			) );
			if ( $owner !== $user_id ) {
				return new \WP_Error( 'forbidden', 'Bạn không sở hữu doc này.', [ 'status' => 403 ] );
			}
		}

		// 2. Build payload (same shape as the sync endpoint).
		$payload = [
			'topic'        => sanitize_textarea_field( (string) $request->get_param( 'topic' ) ?: '' ),
			'prompt_id'    => absint( $request->get_param( 'prompt_id' ) ),
			'prompt_args'  => (array) ( $request->get_param( 'prompt_args' ) ?: [] ),
			'style_preset' => sanitize_text_field( (string) $request->get_param( 'style_preset' ) ?: '' ),
			'aspect_ratio' => sanitize_text_field( (string) $request->get_param( 'aspect_ratio' ) ?: '1:1' ),
			'n_variants'   => max( 1, min( 4, absint( $request->get_param( 'n_variants' ) ?: 1 ) ) ),
			'user_id'      => $user_id,
		];

		// Phase 6.4 Wave 2 — log generation row so version history surfaces
		// image runs the same way as doc/presentation/mindmap.
		$payload['gen_id']     = self::log_generation( $doc_id, $user_id, 'generate', $payload['topic'] );
		$payload['start_time'] = microtime( true );

		// Reference images (image-to-image): accept up to 4 base64 data URIs
		// or HTTPS URLs.
		//
		// Strategy: Upload base64 refs to WP Media → get permanent CDN URLs.
		//   - CDN URLs are stored in `reference_images` for the LLM compose
		//     step (vision model reads the face/product from the URL) and for
		//     long-term storage in schema_json.
		//   - Original base64 data URIs are stored in `reference_images_b64`
		//     so the image GENERATION model always receives the actual pixel
		//     data inline, bypassing any CDN access-control or fetch-timeout
		//     issues that would cause the model to silently ignore the image.
		$refs_in = $request->get_param( 'reference_images' );
		if ( is_array( $refs_in ) && ! empty( $refs_in ) ) {
			error_log( '[BZDoc Ref] Received ' . count( $refs_in ) . ' raw ref(s). First prefix: ' . substr( (string) ( $refs_in[0] ?? '' ), 0, 40 ) );

			// Keep original base64 data URIs for the generation step.
			$refs_b64 = [];
			foreach ( array_slice( $refs_in, 0, 4 ) as $r ) {
				if ( is_string( $r ) && $r !== '' &&
					( strpos( $r, 'data:image/' ) === 0 || strpos( $r, 'https://' ) === 0 ) ) {
					$refs_b64[] = $r;
				}
			}
			if ( $refs_b64 ) $payload['reference_images_b64'] = $refs_b64;

			// Upload to WP media → CDN URLs for compose step + storage.
			$refs = self::upload_ref_images_to_media( $refs_in, $user_id );
			error_log( '[BZDoc Ref] After upload: ' . count( $refs ) . ' URL(s). ' . implode( ' | ', array_map( fn($u) => substr($u,0,80), $refs ) ) );
			if ( $refs ) $payload['reference_images'] = $refs;
		} else {
			error_log( '[BZDoc Ref] No reference_images in request.' );
		}

		// Optional model override from frontend (UI picker). Whitelist a small
		// set of OpenRouter image models to prevent arbitrary model strings.
		$model_in = sanitize_text_field( (string) $request->get_param( 'image_model' ) );
		$allowed_models = [
			'openai/gpt-5.4-image-2',
			'openai/gpt-image-1',
			'google/gemini-3-pro-image-preview',
			'google/gemini-2.5-flash-image',
			'google/gemini-2.5-flash-image-preview',
		];
		if ( $model_in && in_array( $model_in, $allowed_models, true ) ) {
			$payload['image_model'] = $model_in;
			error_log( '[BZDoc Ref] User selected model: ' . $model_in );
		}

		// 3. Mark pending and capture the blog so the worker switches back.
		global $wpdb;
		$blog_id = (int) get_current_blog_id();
		$wpdb->update(
			$wpdb->prefix . 'bzdoc_documents',
			[
				'schema_json' => wp_json_encode( [
					'doc_type'   => 'image',
					'status'     => 'pending',
					'started_at' => current_time( 'mysql' ),
					'topic'      => $payload['topic'],
				], JSON_UNESCAPED_UNICODE ),
				'updated_at'  => current_time( 'mysql' ),
			],
			[ 'id' => $doc_id ]
		);

		// 4. Send response NOW — client only needs the doc_id.
		$response = rest_ensure_response( [
			'success' => true,
			'doc_id'  => $doc_id,
			'status'  => 'pending',
		] );

		// 5. Detach: flush response to client + close connection so the
		// browser (and Cloudflare) move on. PHP keeps running below.
		// `fastcgi_finish_request` works on PHP-FPM / LiteSpeed LSAPI; if
		// missing (rare), fall back to WP cron.
		$detached = false;
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			// Ship the REST response through the normal WP pipeline first
			// by registering a shutdown step that runs the worker after
			// fastcgi_finish_request has been called.
			$server = rest_get_server();
			if ( $server instanceof \WP_REST_Server ) {
				// Send headers + body manually then finish.
				$result = $server->response_to_data( $response, false );
				status_header( 200 );
				header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
				echo wp_json_encode( $result );
				if ( function_exists( 'litespeed_finish_request' ) ) {
					litespeed_finish_request();
				}
				fastcgi_finish_request();
				$detached = true;
			}
		}

		if ( ! $detached ) {
			// Fallback: schedule via WP-Cron (single-event). Slower (waits
			// for next pageload to spawn) but always works.
			wp_schedule_single_event(
				time(),
				'bzdoc_run_image_job',
				[ $doc_id, $payload, $blog_id ]
			);
			return $response;
		}

		// ── Background work (no client connection) ──────────────────
		@set_time_limit( 0 );
		@ignore_user_abort( true );
		self::run_image_job( $doc_id, $payload, $blog_id );
		exit;
	}

	/**
	 * Cron fallback — invoked when `fastcgi_finish_request` is unavailable.
	 */
	public static function run_image_job_cron( $doc_id, $payload, $blog_id ) {
		@set_time_limit( 0 );
		self::run_image_job( (int) $doc_id, (array) $payload, (int) $blog_id );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// [2026-06-06 Johnny Chu] PHASE-IMAGE-SIMPLE — Direct (synchronous) route.
	// OpenRouter image gen does NOT support async/streaming — the gateway call
	// blocks until the image is ready. fastcgi_finish_request was unreliable on
	// this host. This handler runs BZDoc_Image_Pipeline in the same request and
	// returns the full result JSON immediately. No polling required.
	// ─────────────────────────────────────────────────────────────────────────
	public static function handle_image_generate_direct( \WP_REST_Request $request ) {
		@set_time_limit( 300 );
		@ignore_user_abort( true );

		$user_id = get_current_user_id();
		$doc_id  = absint( $request->get_param( 'doc_id' ) );

		// Auto-create doc if not provided.
		if ( $doc_id === 0 ) {
			$topic_raw   = sanitize_text_field( (string) $request->get_param( 'topic' ) ?: 'Untitled Image' );
			$title_short = function_exists( 'mb_substr' )
				? mb_substr( $topic_raw, 0, 200, 'UTF-8' )
				: substr( $topic_raw, 0, 200 );

			$create = new \WP_REST_Request( 'POST' );
			$create->set_param( 'doc_type', 'image' );
			$create->set_param( 'title', $title_short );
			$resp = self::handle_project_create( $create );
			if ( is_wp_error( $resp ) ) {
				return $resp;
			}
			$d      = $resp->get_data();
			$doc_id = (int) ( $d['doc_id'] ?? 0 );
			if ( ! $doc_id ) {
				return new \WP_Error( 'create_failed', 'Không tạo được image doc.', [ 'status' => 500 ] );
			}
		} else {
			global $wpdb;
			$owner = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT user_id FROM {$wpdb->prefix}bzdoc_documents WHERE id = %d", $doc_id
			) );
			if ( $owner !== $user_id ) {
				return new \WP_Error( 'forbidden', 'Bạn không sở hữu doc này.', [ 'status' => 403 ] );
			}
		}

		// Build payload (same shape as async variant).
		$payload = [
			'topic'        => sanitize_textarea_field( (string) $request->get_param( 'topic' ) ?: '' ),
			'prompt_id'    => absint( $request->get_param( 'prompt_id' ) ),
			'prompt_args'  => (array) ( $request->get_param( 'prompt_args' ) ?: [] ),
			'style_preset' => sanitize_text_field( (string) $request->get_param( 'style_preset' ) ?: '' ),
			'aspect_ratio' => sanitize_text_field( (string) $request->get_param( 'aspect_ratio' ) ?: '1:1' ),
			'n_variants'   => max( 1, min( 4, absint( $request->get_param( 'n_variants' ) ?: 1 ) ) ),
			'user_id'      => $user_id,
		];

		$payload['gen_id']     = self::log_generation( $doc_id, $user_id, 'generate', $payload['topic'] );
		$payload['start_time'] = microtime( true );

		// Reference images.
		$refs_in = $request->get_param( 'reference_images' );
		if ( is_array( $refs_in ) && ! empty( $refs_in ) ) {
			$refs_b64 = [];
			foreach ( array_slice( $refs_in, 0, 4 ) as $r ) {
				if ( is_string( $r ) && $r !== '' &&
					( strpos( $r, 'data:image/' ) === 0 || strpos( $r, 'https://' ) === 0 ) ) {
					$refs_b64[] = $r;
				}
			}
			if ( $refs_b64 ) {
				$payload['reference_images_b64'] = $refs_b64;
			}
			$refs = self::upload_ref_images_to_media( $refs_in, $user_id );
			if ( $refs ) {
				$payload['reference_images'] = $refs;
			}
		}

		// Model whitelist.
		$model_in      = sanitize_text_field( (string) $request->get_param( 'image_model' ) );
		$allowed_models = [
			'openai/gpt-image-1',
			'google/gemini-3-pro-image-preview',
			'google/gemini-2.5-flash-image',
			'google/gemini-2.5-flash-image-preview',
		];
		if ( $model_in && in_array( $model_in, $allowed_models, true ) ) {
			$payload['image_model'] = $model_in;
		}

		// Mark doc pending before run.
		global $wpdb;
		$blog_id = (int) get_current_blog_id();
		$wpdb->update(
			$wpdb->prefix . 'bzdoc_documents',
			[
				'schema_json' => wp_json_encode( [
					'doc_type'   => 'image',
					'status'     => 'pending',
					'started_at' => current_time( 'mysql' ),
					'topic'      => $payload['topic'],
				], JSON_UNESCAPED_UNICODE ),
				'updated_at' => current_time( 'mysql' ),
			],
			[ 'id' => $doc_id ]
		);

		// Run pipeline synchronously — blocks until image ready.
		self::run_image_job( $doc_id, $payload, $blog_id );

		// Read back the persisted result from schema_json.
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT schema_json FROM {$wpdb->prefix}bzdoc_documents WHERE id = %d", $doc_id
		) );
		$schema = $row ? json_decode( $row->schema_json, true ) : [];

		if ( empty( $schema ) || ( $schema['status'] ?? '' ) === 'failed' ) {
			$err = $schema['error'] ?? 'Không sinh được ảnh.';
			return rest_ensure_response( [
				'success'    => false,
				'error'      => $err,
				'error_code' => $schema['error_code'] ?? 'image_gen_failed',
			] );
		}

		return rest_ensure_response( [
			'success' => true,
			'data'    => [
				'doc_id'       => $doc_id,
				'job_id'       => $schema['job_id'] ?? 0,
				'aspect_ratio' => $schema['aspect_ratio'] ?? $payload['aspect_ratio'],
				'n_variants'   => $schema['n_variants'] ?? count( (array) ( $schema['variants'] ?? [] ) ),
				'final_prompt' => $schema['final_prompt'] ?? '',
				'negative'     => $schema['negative'] ?? '',
				'citations'    => $schema['citations'] ?? [],
				'variants'     => $schema['variants'] ?? [],
			],
		] );
	}

	/**
	 * Actual worker — runs `BZDoc_Image_Pipeline` and persists the result
	 * (or error) into `schema_json` so the status endpoint can pick it up.
	 */
	private static function run_image_job( int $doc_id, array $payload, int $blog_id ) {
		$switched = false;
		if ( is_multisite() && $blog_id && $blog_id !== (int) get_current_blog_id() ) {
			switch_to_blog( $blog_id );
			$switched = true;
		}

		try {
			$result = BZDoc_Image_Pipeline::run( $doc_id, $payload );
		} catch ( \Throwable $e ) {
			$result = new \WP_Error( 'image_pipeline_exception', $e->getMessage() );
		}

		$gen_id     = (int) ( $payload['gen_id'] ?? 0 );
		$start_time = (float) ( $payload['start_time'] ?? microtime( true ) );

		global $wpdb;
		if ( is_wp_error( $result ) ) {
			$wpdb->update(
				$wpdb->prefix . 'bzdoc_documents',
				[
					'schema_json' => wp_json_encode( [
						'doc_type'    => 'image',
						'status'      => 'failed',
						'error'       => $result->get_error_message(),
						'error_code'  => $result->get_error_code(),
						'finished_at' => current_time( 'mysql' ),
						'topic'       => $payload['topic'] ?? '',
					], JSON_UNESCAPED_UNICODE ),
					'updated_at'  => current_time( 'mysql' ),
				],
				[ 'id' => $doc_id ]
			);
			if ( $gen_id ) {
				self::complete_generation( $gen_id, 'failed', $start_time, $result->get_error_message() );
			}
		} else {
			$schema_snapshot = [
				'doc_type'         => 'image',
				'status'           => 'done',
				'topic'            => $payload['topic'] ?? '',
				'final_prompt'     => $result['final_prompt'] ?? '',
				'aspect_ratio'     => $result['aspect_ratio'] ?? '1:1',
				'variants'         => $result['variants'] ?? [],
				'citations'        => $result['citations'] ?? [],
				'job_id'           => $result['job_id'] ?? 0,
				'n_variants'       => count( (array) ( $result['variants'] ?? [] ) ),
				'finished_at'      => current_time( 'mysql' ),
				// Phase 6.4 — carry the original user-uploaded references into
				// the doc schema so edit_variant() can re-attach them as
				// identity anchors and prevent subject drift across edits.
				'reference_images' => $result['reference_images'] ?? ( $payload['reference_images'] ?? [] ),
			];
			$wpdb->update(
				$wpdb->prefix . 'bzdoc_documents',
				[
					'schema_json' => wp_json_encode( $schema_snapshot, JSON_UNESCAPED_UNICODE ),
					'updated_at'  => current_time( 'mysql' ),
				],
				[ 'id' => $doc_id ]
			);
			if ( $gen_id ) {
				self::complete_generation( $gen_id, 'completed', $start_time, null, $doc_id, $schema_snapshot );
			}
		}

		if ( $switched ) {
			restore_current_blog();
		}
	}

	/**
	 * POST /image/edit/start — async edit of an existing variant.
	 *
	 * Body: { doc_id: int (required), parent_variant_index: int, instruction: string }
	 *
	 * Returns the doc_id immediately + status='pending'. Use the same
	 * GET /image/generate/status/{doc_id} endpoint to poll. The edited
	 * variant gets appended to schema_json.variants[] (not replacing).
	 */
	public static function handle_image_edit_start( \WP_REST_Request $request ) {
		$user_id     = get_current_user_id();
		$doc_id      = absint( $request->get_param( 'doc_id' ) );
		$parent_idx  = (int) $request->get_param( 'parent_variant_index' );
		$instruction = trim( (string) $request->get_param( 'instruction' ) );

		if ( ! $doc_id ) {
			return new \WP_Error( 'missing_doc_id', 'doc_id required.', [ 'status' => 400 ] );
		}
		if ( $instruction === '' ) {
			return new \WP_Error( 'missing_instruction', 'Cần mô tả chỉnh sửa.', [ 'status' => 400 ] );
		}

		// Ownership check.
		global $wpdb;
		$owner = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT user_id FROM {$wpdb->prefix}bzdoc_documents WHERE id = %d",
			$doc_id
		) );
		if ( ! $owner ) {
			return new \WP_Error( 'doc_not_found', 'Doc không tồn tại.', [ 'status' => 404 ] );
		}
		if ( $owner !== $user_id ) {
			return new \WP_Error( 'forbidden', 'Bạn không sở hữu doc này.', [ 'status' => 403 ] );
		}

		$payload = [
			'parent_variant_index' => max( 0, $parent_idx ),
			'instruction'          => $instruction,
			'user_id'              => $user_id,
		];

		// Phase 6.4 Wave 2 — log edit generation so version history records it.
		$payload['gen_id']     = self::log_generation( $doc_id, $user_id, 'edit', $instruction );
		$payload['start_time'] = microtime( true );

		// Mark pending — preserve existing variants[] but flip status so
		// the polling client knows a job is running. We DON'T overwrite
		// the schema; we set a side-channel flag.
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT schema_json FROM {$wpdb->prefix}bzdoc_documents WHERE id = %d",
			$doc_id
		) );
		$schema = json_decode( (string) $row->schema_json, true ) ?: [];
		$schema['status']            = 'pending';
		$schema['edit_in_progress']  = true;
		$schema['edit_instruction']  = $instruction;
		$schema['edit_started_at']   = current_time( 'mysql' );
		$wpdb->update(
			$wpdb->prefix . 'bzdoc_documents',
			[
				'schema_json' => wp_json_encode( $schema, JSON_UNESCAPED_UNICODE ),
				'updated_at'  => current_time( 'mysql' ),
			],
			[ 'id' => $doc_id ]
		);

		$blog_id  = (int) get_current_blog_id();
		$response = rest_ensure_response( [
			'success' => true,
			'doc_id'  => $doc_id,
			'status'  => 'pending',
		] );

		// Detach + run inline (same pattern as handle_image_generate_start).
		$detached = false;
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			$server = rest_get_server();
			if ( $server instanceof \WP_REST_Server ) {
				$result = $server->response_to_data( $response, false );
				status_header( 200 );
				header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
				echo wp_json_encode( $result );
				if ( function_exists( 'litespeed_finish_request' ) ) {
					litespeed_finish_request();
				}
				fastcgi_finish_request();
				$detached = true;
			}
		}

		if ( ! $detached ) {
			wp_schedule_single_event( time(), 'bzdoc_run_image_edit_job', [ $doc_id, $payload, $blog_id ] );
			return $response;
		}

		@set_time_limit( 0 );
		@ignore_user_abort( true );
		self::run_image_edit_job( $doc_id, $payload, $blog_id );
		exit;
	}

	public static function run_image_edit_job_cron( $doc_id, $payload, $blog_id ) {
		self::run_image_edit_job( (int) $doc_id, (array) $payload, (int) $blog_id );
	}

	private static function run_image_edit_job( int $doc_id, array $payload, int $blog_id ) {
		$switched = false;
		if ( is_multisite() && $blog_id && $blog_id !== (int) get_current_blog_id() ) {
			switch_to_blog( $blog_id );
			$switched = true;
		}

		try {
			$result = BZDoc_Image_Pipeline::edit_variant( $doc_id, $payload );
		} catch ( \Throwable $e ) {
			$result = new \WP_Error( 'image_edit_exception', $e->getMessage() );
		}

		$gen_id     = (int) ( $payload['gen_id'] ?? 0 );
		$start_time = (float) ( $payload['start_time'] ?? microtime( true ) );

		global $wpdb;
		// Reload current schema so we don't clobber unrelated fields.
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT schema_json FROM {$wpdb->prefix}bzdoc_documents WHERE id = %d",
			$doc_id
		) );
		$schema = json_decode( (string) ( $row->schema_json ?? '{}' ), true ) ?: [];
		unset( $schema['edit_in_progress'], $schema['edit_started_at'] );

		if ( is_wp_error( $result ) ) {
			$schema['status']     = 'failed';
			$schema['error']      = $result->get_error_message();
			$schema['error_code'] = $result->get_error_code();
		} else {
			$schema['doc_type']     = 'image';
			$schema['status']       = 'done';
			$schema['final_prompt'] = $result['final_prompt'] ?? ( $schema['final_prompt'] ?? '' );
			$schema['aspect_ratio'] = $result['aspect_ratio'] ?? ( $schema['aspect_ratio'] ?? '1:1' );
			$schema['variants']     = $result['variants'] ?? ( $schema['variants'] ?? [] );
			$schema['citations']    = $result['citations'] ?? ( $schema['citations'] ?? [] );
			$schema['job_id']       = $result['job_id'] ?? ( $schema['job_id'] ?? 0 );
			$schema['n_variants']   = count( (array) $schema['variants'] );
			$schema['finished_at']  = current_time( 'mysql' );
		}

		$wpdb->update(
			$wpdb->prefix . 'bzdoc_documents',
			[
				'schema_json' => wp_json_encode( $schema, JSON_UNESCAPED_UNICODE ),
				'updated_at'  => current_time( 'mysql' ),
			],
			[ 'id' => $doc_id ]
		);

		if ( $gen_id ) {
			if ( is_wp_error( $result ) ) {
				self::complete_generation( $gen_id, 'failed', $start_time, $result->get_error_message() );
			} else {
				self::complete_generation( $gen_id, 'completed', $start_time, null, $doc_id, $schema );
			}
		}

		if ( $switched ) {
			restore_current_blog();
		}
	}

	// ─────────────────────────────────────────────────────────────────────────
	// [2026-06-06 Johnny Chu] PHASE-IMAGE-SIMPLE — Direct (synchronous) edit.
	// Runs edit_variant() in the same request and returns the full result.
	// Accepts extra_reference_images uploaded by the user in the edit strip.
	// ─────────────────────────────────────────────────────────────────────────
	public static function handle_image_edit_direct( \WP_REST_Request $request ) {
		@set_time_limit( 300 );
		@ignore_user_abort( true );

		$user_id     = get_current_user_id();
		$doc_id      = absint( $request->get_param( 'doc_id' ) );
		$parent_idx  = (int) $request->get_param( 'parent_variant_index' );
		$instruction = trim( (string) $request->get_param( 'instruction' ) );

		if ( ! $doc_id ) {
			return new \WP_Error( 'missing_doc_id', 'doc_id required.', [ 'status' => 400 ] );
		}
		if ( $instruction === '' ) {
			return new \WP_Error( 'missing_instruction', 'Cần mô tả chỉnh sửa.', [ 'status' => 400 ] );
		}

		// Ownership check.
		global $wpdb;
		$owner = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT user_id FROM {$wpdb->prefix}bzdoc_documents WHERE id = %d",
			$doc_id
		) );
		if ( ! $owner ) {
			return new \WP_Error( 'doc_not_found', 'Doc không tồn tại.', [ 'status' => 404 ] );
		}
		if ( $owner !== $user_id ) {
			return new \WP_Error( 'forbidden', 'Bạn không sở hữu doc này.', [ 'status' => 403 ] );
		}

		$payload = [
			'parent_variant_index' => max( 0, $parent_idx ),
			'instruction'          => $instruction,
			'user_id'              => $user_id,
		];

		$payload['gen_id']     = self::log_generation( $doc_id, $user_id, 'edit', $instruction );
		$payload['start_time'] = microtime( true );

		// Extra reference images uploaded by the user in the edit strip (data URIs
		// or HTTPS URLs). These are forwarded to edit_variant() as
		// `extra_reference_images` where the pipeline adds them to the input_images
		// array alongside the parent variant and original generation refs.
		$extra_refs_in = $request->get_param( 'extra_reference_images' );
		if ( is_array( $extra_refs_in ) && ! empty( $extra_refs_in ) ) {
			$extra_refs = [];
			foreach ( array_slice( $extra_refs_in, 0, 3 ) as $r ) {
				if ( is_string( $r ) && $r !== '' &&
					( strpos( $r, 'data:image/' ) === 0 || strpos( $r, 'https://' ) === 0 ) ) {
					$extra_refs[] = $r;
				}
			}
			if ( $extra_refs ) {
				$payload['extra_reference_images'] = $extra_refs;
			}
		}

		// Run edit synchronously.
		$blog_id  = (int) get_current_blog_id();
		$switched = false;
		if ( is_multisite() && $blog_id && $blog_id !== (int) get_current_blog_id() ) {
			switch_to_blog( $blog_id );
			$switched = true;
		}

		try {
			$result = BZDoc_Image_Pipeline::edit_variant( $doc_id, $payload );
		} catch ( \Throwable $e ) {
			$result = new \WP_Error( 'image_edit_exception', $e->getMessage() );
		}

		$gen_id     = (int) ( $payload['gen_id'] ?? 0 );
		$start_time = (float) ( $payload['start_time'] ?? microtime( true ) );

		// Reload schema + merge result.
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT schema_json FROM {$wpdb->prefix}bzdoc_documents WHERE id = %d",
			$doc_id
		) );
		$schema = json_decode( (string) ( $row->schema_json ?? '{}' ), true ) ?: [];
		unset( $schema['edit_in_progress'], $schema['edit_started_at'] );

		if ( is_wp_error( $result ) ) {
			$schema['status']     = 'failed';
			$schema['error']      = $result->get_error_message();
			$schema['error_code'] = $result->get_error_code();
			$wpdb->update(
				$wpdb->prefix . 'bzdoc_documents',
				[
					'schema_json' => wp_json_encode( $schema, JSON_UNESCAPED_UNICODE ),
					'updated_at'  => current_time( 'mysql' ),
				],
				[ 'id' => $doc_id ]
			);
			if ( $gen_id ) {
				self::complete_generation( $gen_id, 'failed', $start_time, $result->get_error_message() );
			}
			if ( $switched ) restore_current_blog();
			return rest_ensure_response( [
				'success'    => false,
				'error'      => $result->get_error_message(),
				'error_code' => $result->get_error_code(),
			] );
		}

		$schema['doc_type']     = 'image';
		$schema['status']       = 'done';
		$schema['final_prompt'] = $result['final_prompt'] ?? ( $schema['final_prompt'] ?? '' );
		$schema['aspect_ratio'] = $result['aspect_ratio'] ?? ( $schema['aspect_ratio'] ?? '1:1' );
		$schema['variants']     = $result['variants'] ?? ( $schema['variants'] ?? [] );
		$schema['citations']    = $result['citations'] ?? ( $schema['citations'] ?? [] );
		$schema['job_id']       = $result['job_id'] ?? ( $schema['job_id'] ?? 0 );
		$schema['n_variants']   = count( (array) $schema['variants'] );
		$schema['finished_at']  = current_time( 'mysql' );

		$wpdb->update(
			$wpdb->prefix . 'bzdoc_documents',
			[
				'schema_json' => wp_json_encode( $schema, JSON_UNESCAPED_UNICODE ),
				'updated_at'  => current_time( 'mysql' ),
			],
			[ 'id' => $doc_id ]
		);
		if ( $gen_id ) {
			self::complete_generation( $gen_id, 'completed', $start_time, null, $doc_id, $schema );
		}
		if ( $switched ) restore_current_blog();

		return rest_ensure_response( [
			'success' => true,
			'data'    => [
				'doc_id'       => $doc_id,
				'job_id'       => $schema['job_id'],
				'aspect_ratio' => $schema['aspect_ratio'],
				'n_variants'   => $schema['n_variants'],
				'final_prompt' => $schema['final_prompt'],
				'negative'     => $schema['negative'] ?? '',
				'citations'    => $schema['citations'],
				'variants'     => $schema['variants'],
			],
		] );
	}

	public static function handle_image_generate_status( \WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$doc_id  = absint( $request['id'] );
		if ( ! $doc_id ) {
			return new \WP_Error( 'missing_id', 'doc_id required', [ 'status' => 400 ] );
		}

		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT user_id, schema_json FROM {$wpdb->prefix}bzdoc_documents WHERE id = %d",
			$doc_id
		) );
		if ( ! $row ) {
			return new \WP_Error( 'not_found', 'Doc không tồn tại.', [ 'status' => 404 ] );
		}
		if ( (int) $row->user_id !== $user_id ) {
			return new \WP_Error( 'forbidden', 'Bạn không sở hữu doc này.', [ 'status' => 403 ] );
		}

		$schema = json_decode( $row->schema_json ?: '{}', true ) ?: [];
		$status = (string) ( $schema['status'] ?? 'unknown' );

		$out = [
			'success' => true,
			'doc_id'  => $doc_id,
			'status'  => $status,
		];

		if ( $status === 'done' ) {
			$out['data'] = [
				'doc_id'       => $doc_id,
				'job_id'       => (int) ( $schema['job_id'] ?? 0 ),
				'aspect_ratio' => (string) ( $schema['aspect_ratio'] ?? '1:1' ),
				'final_prompt' => (string) ( $schema['final_prompt'] ?? '' ),
				'citations'    => (array) ( $schema['citations'] ?? [] ),
				'variants'     => (array) ( $schema['variants'] ?? [] ),
				'n_variants'   => count( (array) ( $schema['variants'] ?? [] ) ),
			];
		} elseif ( $status === 'failed' ) {
			$out['error']      = (string) ( $schema['error'] ?? 'unknown' );
			$out['error_code'] = (string) ( $schema['error_code'] ?? 'image_failed' );
		} else {
			// Pending — surface streaming heartbeat so the UI can prove the
			// upstream socket is still actively receiving bytes (not stalled).
			if ( class_exists( 'BZDoc_Image_Pipeline' )
				&& method_exists( 'BZDoc_Image_Pipeline', 'get_job_heartbeat' ) ) {
				$hb = BZDoc_Image_Pipeline::get_job_heartbeat( $doc_id );
				if ( is_array( $hb ) ) {
					$out['heartbeat'] = [
						'ts'        => (int) $hb['ts'],
						'age_s'     => max( 0, time() - (int) $hb['ts'] ),
						'variant'   => (int) ( $hb['variant'] ?? 0 ),
						'event'     => (string) ( $hb['event'] ?? '' ),
					];
				}
			}
		}

		return rest_ensure_response( $out );
	}

	public static function handle_image_prompts_featured( \WP_REST_Request $request ) {
		$lang  = sanitize_text_field( (string) $request->get_param( 'lang' ) ?: 'vi' );
		$limit = absint( $request->get_param( 'limit' ) ?: 12 );
		$rows  = BZDoc_Image_Prompts_Database::list_featured( $limit, $lang );
		return rest_ensure_response( [ 'success' => true, 'items' => self::decode_prompt_rows( $rows ) ] );
	}

	public static function handle_image_prompts_search( \WP_REST_Request $request ) {
		$q     = sanitize_text_field( (string) $request->get_param( 'q' ) ?: '' );
		$lang  = sanitize_text_field( (string) $request->get_param( 'lang' ) ?: 'vi' );
		$limit = absint( $request->get_param( 'limit' ) ?: 20 );
		$rows  = BZDoc_Image_Prompts_Database::search( $q, $limit, $lang );
		return rest_ensure_response( [ 'success' => true, 'items' => self::decode_prompt_rows( $rows ) ] );
	}

	public static function handle_image_prompt_get( \WP_REST_Request $request ) {
		$id = absint( $request['id'] );
		$row = BZDoc_Image_Prompts_Database::get_by_id( $id );
		if ( ! $row ) {
			return new \WP_Error( 'not_found', 'Prompt không tồn tại.', [ 'status' => 404 ] );
		}
		$decoded = self::decode_prompt_rows( [ $row ] );
		// Include full template + arguments for editor.
		$decoded[0]['template']  = json_decode( (string) $row['template_json'], true );
		$decoded[0]['arguments'] = json_decode( (string) $row['arguments_json'], true );
		return rest_ensure_response( [ 'success' => true, 'item' => $decoded[0] ] );
	}

	/**
	 * PHASE-6.4 BUG-FIX (May 2026) — slug → prompt detail resolver.
	 * Used by DocApp when TwinChat handoff carries `image_opts.template_slug`
	 * (instead of a numeric prompt_id) so we can bind ImagePromptInput to the
	 * correct preset and render its argument form.
	 */
	public static function handle_image_prompt_get_by_slug( \WP_REST_Request $request ) {
		$slug = sanitize_title( (string) $request['slug'] );
		if ( $slug === '' ) {
			return new \WP_Error( 'bad_slug', 'Slug rỗng.', [ 'status' => 400 ] );
		}
		$row = BZDoc_Image_Prompts_Database::get_by_slug( $slug );
		if ( ! $row ) {
			return new \WP_Error( 'not_found', 'Không tìm thấy preset cho slug: ' . $slug, [ 'status' => 404 ] );
		}
		$decoded = self::decode_prompt_rows( [ $row ] );
		$decoded[0]['template']  = json_decode( (string) $row['template_json'], true );
		$decoded[0]['arguments'] = json_decode( (string) $row['arguments_json'], true );
		return rest_ensure_response( [ 'success' => true, 'item' => $decoded[0] ] );
	}

	/**
	 * PHASE-6.4 SMART-INFER (May 2026) — POST /image/prompts/infer-args
	 *
	 * Body: { notebook_id: int, slug: string, message_limit?: int }
	 * Đọc N tin nhắn cuối của TwinChat session gắn với notebook_id, ghép với
	 * schema arguments của preset, gọi LLM (JSON mode) để suy luận value cho
	 * từng key. Trả về:
	 *   { success, prompt_id, prompt_args: {key→value}, used_messages_count,
	 *     model_used, missing_required: [...], usage }
	 *
	 * Nếu LLM fail / parse fail → trả 502 để FE fallback dùng default + KHÔNG
	 * kickstart (theo quy ước UX đã chốt).
	 */
	public static function handle_image_prompt_infer_args( \WP_REST_Request $request ) {
		self::ensure_user_blog_context( $request );

		try {
			$notebook_id = absint( $request->get_param( 'notebook_id' ) );
			$slug        = sanitize_title( (string) $request->get_param( 'slug' ) );
			$msg_limit   = (int) ( $request->get_param( 'message_limit' ) ?: 20 );
			$msg_limit   = max( 4, min( 50, $msg_limit ) );

			if ( ! $notebook_id ) {
				self::restore_blog_context();
				return new \WP_Error( 'missing_notebook_id', 'notebook_id bắt buộc.', [ 'status' => 400 ] );
			}
			if ( $slug === '' ) {
				self::restore_blog_context();
				return new \WP_Error( 'missing_slug', 'slug bắt buộc.', [ 'status' => 400 ] );
			}

			// 1) Load preset
			$preset = BZDoc_Image_Prompts_Database::get_by_slug( $slug );
			if ( ! $preset ) {
				self::restore_blog_context();
				return new \WP_Error( 'preset_not_found', "Preset '{$slug}' không tồn tại", [ 'status' => 404 ] );
			}
			$arguments_schema = json_decode( (string) ( $preset['arguments_json'] ?? '' ), true );
			if ( ! is_array( $arguments_schema ) || empty( $arguments_schema ) ) {
				self::restore_blog_context();
				return rest_ensure_response( [
					'success'             => true,
					'prompt_id'           => (int) $preset['id'],
					'prompt_args'         => new \stdClass(),
					'used_messages_count' => 0,
					'model_used'          => '',
					'missing_required'    => [],
					'note'                => 'Preset không có arguments cần infer.',
				] );
			}

			// 2) Load N messages từ webchat unified table (platform=TWINCHAT, project_id=notebook_id)
			global $wpdb;
			$tbl = $wpdb->prefix . 'bizcity_webchat_messages';
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT message_from, message_text
				   FROM {$tbl}
				  WHERE project_id = %s
				    AND platform_type = %s
				    AND status = 'visible'
				  ORDER BY id DESC
				  LIMIT %d",
				(string) $notebook_id,
				'TWINCHAT',
				$msg_limit
			), ARRAY_A );

			if ( empty( $rows ) ) {
				self::restore_blog_context();
				return new \WP_Error( 'no_messages', 'Notebook chưa có tin nhắn TwinChat nào để suy luận.', [ 'status' => 404 ] );
			}
			$rows = array_reverse( $rows ); // ascending (oldest → newest)

			$conversation = '';
			foreach ( $rows as $r ) {
				$role = ( $r['message_from'] === 'bot' ) ? 'Assistant' : ucfirst( (string) $r['message_from'] );
				$txt  = trim( wp_strip_all_tags( (string) $r['message_text'] ) );
				if ( $txt === '' ) continue;
				if ( mb_strlen( $txt ) > 1500 ) {
					$txt = mb_substr( $txt, 0, 1500 ) . '…';
				}
				$conversation .= "{$role}: {$txt}\n\n";
			}

			// 3) Build prompt cho LLM
			// NOTE: arguments schema dùng key 'name' (không phải 'key') — xem image-prompts-seed.php
			$schema_compact = [];
			foreach ( $arguments_schema as $a ) {
				if ( ! is_array( $a ) ) continue;
				$name = (string) ( $a['name'] ?? $a['key'] ?? '' );
				if ( $name === '' ) continue;
				$entry = [
					'name'     => $name,
					'label'    => (string) ( $a['label'] ?? $name ),
					'type'     => (string) ( $a['type'] ?? 'text' ),
					'required' => ! empty( $a['required'] ),
				];
				if ( ! empty( $a['default'] ) )       $entry['default_example'] = $a['default'];
				if ( ! empty( $a['placeholder'] ) )   $entry['placeholder']     = $a['placeholder'];
				if ( ! empty( $a['options'] ) )       $entry['options']         = $a['options'];
				if ( ! empty( $a['description'] ) )   $entry['description']     = $a['description'];
				$schema_compact[] = $entry;
			}
			if ( empty( $schema_compact ) ) {
				self::restore_blog_context();
				return rest_ensure_response( [
					'success'             => true,
					'prompt_id'           => (int) $preset['id'],
					'prompt_args'         => new \stdClass(),
					'used_messages_count' => 0,
					'model_used'          => '',
					'missing_required'    => [],
					'note'                => 'Schema rỗng (không có field hợp lệ).',
				] );
			}
			$schema_json    = wp_json_encode( $schema_compact, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
			$expected_keys  = array_map( function( $a ){ return $a['name']; }, $schema_compact );
			$skeleton       = (object) array_fill_keys( $expected_keys, '' );
			$skeleton_json  = wp_json_encode( $skeleton, JSON_UNESCAPED_UNICODE );
			$keys_csv       = implode( ', ', $expected_keys );

			$system = "Bạn là EXTRACTOR. Nhiệm vụ: đọc HỘI THOẠI giữa User và Assistant rồi TRÍCH (extract) giá trị cho từng field trong SCHEMA. Đây là EXTRACT, KHÔNG phải GENERATE.\n\n"
				. "QUY TẮC BẮT BUỘC (vi phạm = output bị loại):\n"
				. "1. CHỈ trả JSON object thuần. Không markdown, không giải thích.\n"
				. "2. JSON PHẢI chứa ĐẦY ĐỦ các key sau: {$keys_csv}.\n"
				. "3. Mỗi value PHẢI là chuỗi nguyên văn (hoặc paraphrase rất sát) đã xuất hiện trong HỘI THOẠI từ phía User. Nếu không tìm thấy bằng chứng rõ ràng → để \"\" (chuỗi rỗng).\n"
				. "4. CẤM TUYỆT ĐỐI bịa, suy diễn, tính toán, hay tra cứu. Cụ thể:\n"
				. "   • CẤM tự suy ra cung hoàng đạo (zodiac), mệnh ngũ hành (element), thần số học (life_path / birthday_no / soul_urge / destiny), v.v. từ tên hay ngày sinh. Chỉ điền nếu User TỰ NÓI RÕ chuỗi đó (ví dụ: 'tôi cung Bạch Dương').\n"
				. "   • CẤM dùng default_example / placeholder làm value. Chúng chỉ để bạn HIỂU field, KHÔNG phải để copy.\n"
				. "   • CẤM dùng tên mẫu, slogan mẫu, palette mẫu nếu User chưa khai.\n"
				. "5. type=date → dd/MM/yyyy. type=select → chọn đúng 1 giá trị trong options (chỉ khi User đã chọn rõ).\n"
				. "6. Khi mâu thuẫn, ưu tiên phát ngôn gần nhất của User.\n"
				. "7. Nếu hội thoại không liên quan đến chủ đề preset (ví dụ User nói chuyện khác, chưa khai báo gì cho hồ sơ) → trả TẤT CẢ value bằng \"\". Đó là kết quả ĐÚNG.\n\n"
				. "SCHEMA (mô tả từng field — default_example/placeholder CHỈ để hiểu, KHÔNG copy):\n{$schema_json}\n\n"
				. "ĐỊNH DẠNG OUTPUT BẮT BUỘC (giữ nguyên các key, chỉ thay value bằng dữ liệu User đã khai, hoặc \"\"):\n{$skeleton_json}";

			$user = "HỘI THOẠI:\n\n{$conversation}\n---\nTRÍCH (không sinh mới) JSON theo skeleton. Field nào User chưa khai rõ → để \"\". Bắt buộc đủ key: {$keys_csv}.";

			// 4) Gọi LLM (JSON mode) — model rẻ
			if ( ! function_exists( 'bizcity_llm_chat' ) ) {
				self::restore_blog_context();
				return new \WP_Error( 'llm_unavailable', 'LLM helper chưa sẵn sàng.', [ 'status' => 503 ] );
			}

			$resp = bizcity_llm_chat(
				[
					[ 'role' => 'system', 'content' => $system ],
					[ 'role' => 'user',   'content' => $user ],
				],
				[
					'model'       => 'google/gemini-2.5-flash',
					'purpose'     => 'fast',
					'temperature' => 0.2,
					'max_tokens'  => 1200,
					'timeout'     => 30,
					'extra_body'  => [ 'response_format' => [ 'type' => 'json_object' ] ],
				]
			);

			if ( empty( $resp['success'] ) ) {
				$err = isset( $resp['error'] ) ? (string) $resp['error'] : 'LLM call failed';
				error_log( '[BZDoc][infer-args] LLM fail: ' . $err );
				self::restore_blog_context();
				return new \WP_Error( 'llm_error', $err, [ 'status' => 502 ] );
			}

			$raw = (string) ( $resp['message'] ?? '' );
			// Strip markdown fence nếu model bướng bỉnh.
			$raw = trim( $raw );
			$raw = preg_replace( '/^```(?:json)?\s*|\s*```$/m', '', $raw );
			$parsed = json_decode( $raw, true );
			if ( ! is_array( $parsed ) ) {
				error_log( '[BZDoc][infer-args] JSON parse fail. Raw: ' . substr( $raw, 0, 500 ) );
				self::restore_blog_context();
				return new \WP_Error( 'parse_error', 'LLM trả về không phải JSON hợp lệ.', [
					'status'  => 502,
					'raw'     => substr( $raw, 0, 500 ),
				] );
			}

			// 5) Sanitize: chỉ giữ key trong schema, ép string, kiểm required.
			$valid_keys = [];
			$required_keys = [];
			foreach ( $schema_compact as $a ) {
				$valid_keys[ $a['name'] ] = true;
				if ( ! empty( $a['required'] ) ) $required_keys[] = $a['name'];
			}
			$prompt_args = [];
			foreach ( $parsed as $k => $v ) {
				if ( ! isset( $valid_keys[ $k ] ) ) continue;
				if ( is_array( $v ) || is_object( $v ) ) {
					$v = wp_json_encode( $v );
				}
				$prompt_args[ $k ] = (string) $v;
			}
			$missing = [];
			foreach ( $required_keys as $rk ) {
				if ( ! isset( $prompt_args[ $rk ] ) || $prompt_args[ $rk ] === '' ) {
					$missing[] = $rk;
				}
			}

			self::restore_blog_context();

			return rest_ensure_response( [
				'success'             => true,
				'prompt_id'           => (int) $preset['id'],
				'prompt_args'         => (object) $prompt_args, // object để JSON {} thay vì []
				'used_messages_count' => count( $rows ),
				'model_used'          => (string) ( $resp['model'] ?? '' ),
				'missing_required'    => $missing,
				'usage'               => $resp['usage'] ?? null,
			] );

		} catch ( \Throwable $e ) {
			self::restore_blog_context();
			error_log( '[BZDoc][infer-args] exception: ' . $e->getMessage() );
			return new \WP_Error( 'exception', $e->getMessage(), [ 'status' => 500 ] );
		}
	}

	private static function decode_prompt_rows( array $rows ): array {
		$out = [];
		foreach ( $rows as $r ) {
			$out[] = [
				'id'                 => (int) $r['id'],
				'slug'               => $r['slug'],
				'title'              => $r['title'],
				'description'        => $r['description'],
				'cover_url'          => $r['cover_url'],
				'language'           => $r['language'] ?? 'vi',
				'categories'         => json_decode( (string) ( $r['categories_json'] ?? '[]' ), true ) ?: [],
				'arguments'          => json_decode( (string) ( $r['arguments_json'] ?? '[]' ), true ) ?: [],
				'source_attribution' => $r['source_attribution'] ?? '',
			];
		}
		return $out;
	}
}
