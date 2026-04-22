<?php
/**
 * Code Engine — core generation pipeline.
 *
 * Orchestrates: prompt building → LLM call (via bizcity-llm-router) → code streaming.
 * Supports: create (from screenshot/text), edit (iterative with history).
 *
 * IMPORTANT: PHP is single-threaded. Variants are generated SEQUENTIALLY,
 * not in parallel. Each variant streams to the SSE client in real-time.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BZCode_Engine {

	/* ── Supported stacks ── */
	const STACKS = [
		'html_tailwind'  => [ 'label' => 'HTML + Tailwind CSS',  'ext' => 'html' ],
		'html_css'       => [ 'label' => 'HTML + CSS',           'ext' => 'html' ],
		'react_tailwind' => [ 'label' => 'React + Tailwind CSS', 'ext' => 'tsx'  ],
		'vue_tailwind'   => [ 'label' => 'Vue + Tailwind CSS',   'ext' => 'vue'  ],
		'bootstrap'      => [ 'label' => 'HTML + Bootstrap 5',   'ext' => 'html' ],
	];

	const MAX_VARIANTS    = 4;
	const MAX_EDIT_TURNS  = 20;
	const DEFAULT_MODEL   = 'claude-sonnet-4-20250514';

	/* ═══════════════════════════════════════════════
	 *  CREATE — generate code from screenshot or text
	 * ═══════════════════════════════════════════════ */

	/**
	 * @param array $params {
	 *   mode:       'screenshot'|'text',
	 *   prompt:     string,
	 *   images:     string[] (base64 data URLs or R2 URLs),
	 *   stack:      string (key from STACKS),
	 *   project_id: int (0 = auto-create),
	 *   variants:   int (1-4),
	 *   model:      string (optional override),
	 *   user_id:    int,
	 * }
	 * @param callable $on_chunk  fn( int $variant_index, string $delta )
	 * @param callable $on_done   fn( int $variant_index, string $full_code, array $usage )
	 * @param callable $on_error  fn( int $variant_index, string $error )
	 */
	public static function create( array $params, callable $on_chunk, callable $on_done, callable $on_error ): array {
		$stack      = $params['stack'] ?? 'html_tailwind';
		$num_vars   = min( max( (int) ( $params['variants'] ?? 2 ), 1 ), self::MAX_VARIANTS );
		$model      = $params['model'] ?? self::DEFAULT_MODEL;
		$user_id    = (int) ( $params['user_id'] ?? get_current_user_id() );

		// 1. Resolve model via purpose if no explicit override
		if ( empty( $params['model'] ) && function_exists( 'bizcity_llm_get_model' ) ) {
			$purpose = ! empty( $params['images'] ) ? 'vision' : 'code';
			$model   = bizcity_llm_get_model( $purpose ) ?: self::DEFAULT_MODEL;
		}

		// 2. Ensure project exists
		$project_id = (int) ( $params['project_id'] ?? 0 );
		if ( ! $project_id ) {
			$project_id = BZCode_Project_Manager::insert( [
				'user_id' => $user_id,
				'title'   => mb_substr( $params['prompt'] ?? 'New Project', 0, 100 ),
				'stack'   => $stack,
			] );
			if ( ! $project_id ) {
				$on_error( 0, 'Failed to create project.' );
				return [];
			}
		}

		// 3. Create page (index)
		$page_id = BZCode_Page_Manager::insert( [
			'project_id' => $project_id,
			'title'      => 'index',
			'slug'       => 'index',
		] );

		// 4. Build prompts
		$source_context = $params['source_context'] ?? '';
		if ( empty( $source_context ) && $project_id && class_exists( 'BZCode_Source_Manager' ) ) {
			$source_context = BZCode_Source_Manager::get_all_content( $project_id );
		}
		$has_sources   = ! empty( $source_context );
		$system_prompt = self::build_system_prompt( $stack, false, $has_sources );
		$params['source_context'] = $source_context;
		$user_message  = self::build_create_message( $params );

		// 5. Create variant rows (all status=generating)
		$variant_ids = [];
		for ( $i = 0; $i < $num_vars; $i++ ) {
			$variant_ids[ $i ] = BZCode_Variant_Manager::insert( [
				'page_id'         => $page_id,
				'variant_index'   => $i,
				'model_used'      => $model,
				'generation_type' => 'create',
				'is_selected'     => $i === 0 ? 1 : 0,
			] );
		}

		// 6. Dispatch variants SEQUENTIALLY (PHP is single-threaded)
		//    Each variant streams tokens to client via on_chunk callback in real-time
		foreach ( $variant_ids as $vi => $vid ) {
			self::dispatch_llm_request( [
				'variant_id'      => $vid,
				'variant_index'   => $vi,
				'system_prompt'   => $system_prompt,
				'messages'        => [ $user_message ],
				'model'           => $model,
				'stack'           => $stack,
				'user_id'         => $user_id,
				'generation_type' => 'create',
				'project_id'      => $project_id,
				'prompt_summary'  => mb_substr( $params['prompt'] ?? '', 0, 200 ),
				'caller'          => $params['caller'] ?? 'studio',
			], $on_chunk, $on_done, $on_error );
		}

		return [
			'project_id'  => $project_id,
			'page_id'     => $page_id,
			'variant_ids' => $variant_ids,
		];
	}

	/* ═══════════════════════════════════════════════
	 *  EDIT — iterative code modification
	 * ═══════════════════════════════════════════════ */

	public static function edit( array $params, callable $on_chunk, callable $on_done, callable $on_error ): array {
		$project_id  = (int) ( $params['project_id'] ?? 0 );
		$instruction = $params['instruction'] ?? '';
		$model       = $params['model'] ?? self::DEFAULT_MODEL;

		if ( empty( $params['model'] ) && function_exists( 'bizcity_llm_get_model' ) ) {
			$model = bizcity_llm_get_model( 'code' ) ?: self::DEFAULT_MODEL;
		}

		$project = BZCode_Project_Manager::get_by_id( $project_id );
		if ( ! $project ) {
			$on_error( 0, 'Project not found.' );
			return [];
		}

		$stack = $project->stack;
		$pages = BZCode_Page_Manager::get_by_project( $project_id );
		if ( empty( $pages ) ) {
			$on_error( 0, 'No pages in project.' );
			return [];
		}

		$page     = $pages[0];
		$selected = BZCode_Variant_Manager::get_selected( (int) $page->id );
		if ( ! $selected ) {
			$on_error( 0, 'No selected variant to edit.' );
			return [];
		}

		$current_code = $selected->code;
		$history      = json_decode( $selected->history_json, true ) ?: [];

		// Build edit messages (append to history for continuity)
		$system_prompt = self::build_system_prompt( $stack, true );
		$new_user_msg  = self::build_edit_message( $instruction, $current_code, $params['images'] ?? [] );
		$messages      = array_merge( $history, [ $new_user_msg ] );

		// Deselect old variant before creating new one
		BZCode_Variant_Manager::select_variant( (int) $page->id, 0 );

		// Create new variant for the edit
		$variant_id = BZCode_Variant_Manager::insert( [
			'page_id'         => (int) $page->id,
			'variant_index'   => 0,
			'model_used'      => $model,
			'generation_type' => 'edit',
			'is_selected'     => 1,
			'history_json'    => wp_json_encode( $messages ),
		] );

		// Wrap on_done to also save assistant response into history
		$wrapped_on_done = function ( int $vi, string $code, array $usage ) use (
			$variant_id, $messages, $on_done
		) {
			// Append assistant response to history for next edit turn
			$messages[] = [ 'role' => 'assistant', 'content' => $code ];
			BZCode_Variant_Manager::update( $variant_id, [
				'history_json' => wp_json_encode( $messages ),
			] );
			$on_done( $vi, $code, $usage );
		};

		self::dispatch_llm_request( [
			'variant_id'      => $variant_id,
			'variant_index'   => 0,
			'system_prompt'   => $system_prompt,
			'messages'        => $messages,
			'model'           => $model,
			'stack'           => $stack,
			'user_id'         => (int) ( $project->user_id ?? get_current_user_id() ),
			'generation_type' => 'edit',
			'project_id'      => $project_id,
			'prompt_summary'  => mb_substr( $instruction, 0, 200 ),
			'caller'          => $params['caller'] ?? 'studio',
		], $on_chunk, $wrapped_on_done, $on_error );

		return [
			'project_id' => $project_id,
			'variant_id' => $variant_id,
		];
	}

	/* ═══════════════════════════════════════════════
	 *  PROMPT BUILDERS
	 * ═══════════════════════════════════════════════ */

	private static function build_system_prompt( string $stack, bool $is_edit = false, bool $has_sources = false ): string {
		$stack_label = self::STACKS[ $stack ]['label'] ?? 'HTML + Tailwind CSS';

		$base = <<<PROMPT
You are an expert web developer who specializes in building pixel-perfect, responsive web pages.
You write clean, production-ready {$stack_label} code.

RULES:
- Generate a COMPLETE, single-file web page unless told otherwise.
- Use modern best practices for the chosen stack.
- Make the design responsive (mobile-first).
- Use semantic HTML elements.
- Include all necessary CSS/styles inline or via CDN links.
- For images, use placeholder images from https://placehold.co with descriptive alt text.
- Do NOT add any explanations — output ONLY the code.
- Wrap the entire code output in a single code block.
PROMPT;

		if ( $stack === 'html_tailwind' || $stack === 'react_tailwind' || $stack === 'vue_tailwind' ) {
			$base .= "\n- Include Tailwind CSS via CDN: <script src=\"https://cdn.tailwindcss.com\"></script>";
		}
		if ( $stack === 'bootstrap' ) {
			$base .= "\n- Include Bootstrap 5 via CDN.";
		}
		if ( $stack === 'react_tailwind' ) {
			$base .= "\n- Use React with Babel standalone for single-file: <script src=\"https://unpkg.com/react@18/umd/react.production.min.js\"></script>";
			$base .= "\n- Output a single HTML file that boots React inline.";
		}
		if ( $stack === 'vue_tailwind' ) {
			$base .= "\n- Use Vue 3 via CDN: <script src=\"https://unpkg.com/vue@3/dist/vue.global.prod.js\"></script>";
			$base .= "\n- Output a single HTML file that boots Vue inline.";
		}

		if ( $is_edit ) {
			$base .= <<<EOT

EDIT MODE:
- You are editing an existing web page.
- The user will provide the current code and edit instructions.
- Apply ONLY the requested changes. Do NOT rewrite unrelated sections.
- Output the COMPLETE updated code (not just the diff).
EOT;
		}

		if ( $has_sources ) {
			$base .= <<<'EOT'

SOURCE-DRIVEN GENERATION:
- The user has provided reference source documents with real data (product info, company details, pricing, features, etc.)
- USE the source data as the primary content — do NOT invent/fabricate information.
- Extract key details from sources: company name, product features, pricing, testimonials, contact info, etc.
- Build a professional landing page with REAL content from the sources.
- Structure: NAVBAR → HERO → SOCIAL PROOF → FEATURES/BENEFITS → HOW IT WORKS → TESTIMONIALS → PRICING → FAQ → FINAL CTA → FOOTER
- Nội dung tiếng Việt nếu nguồn là tiếng Việt.
- Use placeholder images from https://placehold.co/{w}x{h}/{hex}/{hex}?text={text}
- Pure CSS FAQ accordion via <details>/<summary>
- Professional agency quality, NOT cheap template look.
EOT;
		}

		return $base;
	}

	private static function build_create_message( array $params ): array {
		$content = [];

		// Add images (screenshot/mockup) if present
		$images = $params['images'] ?? [];
		foreach ( $images as $img ) {
			$content[] = [
				'type'      => 'image_url',
				'image_url' => [ 'url' => $img ],
			];
		}

		$prompt = $params['prompt'] ?? '';
		if ( empty( $prompt ) && ! empty( $images ) ) {
			$prompt = 'Generate code that closely replicates this screenshot/design. Match the layout, colors, typography, and spacing as closely as possible.';
		}

		// Inject source context if available
		$source_context = $params['source_context'] ?? '';
		if ( $source_context ) {
			$prompt .= "\n\n═══ NỘI DUNG NGUỒN (REAL DATA — sử dụng thông tin này, KHÔNG bịa) ═══\n" . $source_context;
		}

		$content[] = [
			'type' => 'text',
			'text' => $prompt,
		];

		return [
			'role'    => 'user',
			'content' => $content,
		];
	}

	private static function build_edit_message( string $instruction, string $current_code, array $images = [] ): array {
		$content = [];

		foreach ( $images as $img ) {
			$content[] = [
				'type'      => 'image_url',
				'image_url' => [ 'url' => $img ],
			];
		}

		$text = "Here is the current code:\n\n```html\n{$current_code}\n```\n\nEdit instruction: {$instruction}";

		$content[] = [
			'type' => 'text',
			'text' => $text,
		];

		return [
			'role'    => 'user',
			'content' => $content,
		];
	}

	/* ═══════════════════════════════════════════════
	 *  LLM DISPATCH — via bizcity_llm_chat_stream()
	 *
	 *  API: bizcity_llm_chat_stream( $messages, $options, $on_chunk )
	 *  on_chunk signature: fn( string $delta, string $full_accumulated )
	 *  Returns: [ 'success', 'message', 'usage', 'model', ... ]
	 * ═══════════════════════════════════════════════ */

	private static function dispatch_llm_request( array $config, callable $on_chunk, callable $on_done, callable $on_error ): void {
		$variant_id    = $config['variant_id'];
		$variant_index = $config['variant_index'];

		// ── Generation log: start ──
		$gen_id     = self::log_generation( $config );
		$start_time = microtime( true );

		$messages = array_merge(
			[ [ 'role' => 'system', 'content' => $config['system_prompt'] ] ],
			$config['messages']
		);

		$llm_options = [
			'model'       => $config['model'],
			'purpose'     => ! empty( $config['images'] ) ? 'vision' : 'code',
			'temperature' => 0.7,
			'max_tokens'  => 8000,
			'timeout'     => 120,
		];

		if ( ! function_exists( 'bizcity_llm_chat_stream' ) ) {
			BZCode_Variant_Manager::update( $variant_id, [
				'status'        => 'error',
				'error_message' => 'LLM router not available (bizcity_llm_chat_stream missing).',
			] );
			$on_error( $variant_index, 'LLM router not available. Please install bizcity-llm-router.' );
			return;
		}

		// bizcity_llm_chat_stream() is BLOCKING — streams tokens via on_chunk
		// callback, then returns the full result. No DB writes per token;
		// buffer in memory and write once at the end.
		$result = bizcity_llm_chat_stream(
			$messages,
			$llm_options,
			function ( string $delta, string $full_so_far ) use ( $variant_index, $on_chunk ) {
				// Stream each delta token to the SSE client
				$on_chunk( $variant_index, $delta );
			}
		);

		if ( ! empty( $result['success'] ) && ! empty( $result['message'] ) ) {
			$code  = self::extract_code( $result['message'] );
			$usage = $result['usage'] ?? [];

			// Single DB write with the full code
			BZCode_Variant_Manager::update( $variant_id, [
				'code'         => $code,
				'status'       => 'complete',
				'token_input'  => (int) ( $usage['prompt_tokens'] ?? 0 ),
				'token_output' => (int) ( $usage['completion_tokens'] ?? 0 ),
			] );

			// ── Generation log: complete with snapshot ──
			self::complete_generation( $gen_id, 'complete', $start_time, null, $variant_id, $code,
				(int) ( $usage['prompt_tokens'] ?? 0 ) + (int) ( $usage['completion_tokens'] ?? 0 ),
				$result['model'] ?? $config['model']
			);

			// Register artifact in Unified Output Store (studio_outputs)
			self::register_output( $variant_id, $code, $config );

			$on_done( $variant_index, $code, $usage );
		} else {
			$error_msg = $result['error'] ?? 'LLM request failed.';
			BZCode_Variant_Manager::update( $variant_id, [
				'status'        => 'error',
				'error_message' => mb_substr( $error_msg, 0, 500 ),
			] );

			// ── Generation log: error ──
			self::complete_generation( $gen_id, 'error', $start_time, $error_msg, $variant_id );

			$on_error( $variant_index, $error_msg );
		}
	}

	/* ═══════════════════════════════════════════════
	 *  UNIFIED OUTPUT STORE — register artifact
	 * ═══════════════════════════════════════════════ */

	/**
	 * Register completed variant as artifact in BizCity_Output_Store.
	 * Studio tab shows card with preview link + editor link.
	 */
	private static function register_output( int $variant_id, string $code, array $config ): void {
		if ( ! class_exists( 'BizCity_Output_Store' ) ) {
			return;
		}

		$user_id    = (int) ( $config['user_id'] ?? get_current_user_id() );
		$project_id = (int) ( $config['project_id'] ?? 0 );
		$gen_type   = $config['generation_type'] ?? 'create';
		$model      = $config['model'] ?? '';
		$stack      = $config['stack'] ?? 'html_tailwind';
		$prompt     = $config['prompt_summary'] ?? '';

		$preview_url = home_url( "/tool-code/preview/{$variant_id}/" );
		$editor_url  = $project_id ? home_url( "/tool-code/project/{$project_id}/" ) : '';

		$title = $gen_type === 'edit'
			? 'Code Edit — ' . mb_substr( $prompt, 0, 80 )
			: 'Code Builder — ' . mb_substr( $prompt, 0, 80 );

		BizCity_Output_Store::register_media_output( [
			'workshop'       => BizCity_Output_Store::WORKSHOP_CODE_BUILDER,
			'media_type'     => 'document',
			'title'          => $title ?: 'Code Builder Output',
			'file_url'       => $preview_url,
			'thumbnail_url'  => $preview_url,
			'content'        => wp_json_encode( [
				'variant_id'  => $variant_id,
				'project_id'  => $project_id,
				'editor_url'  => $editor_url,
				'stack'       => $stack,
				'gen_type'    => $gen_type,
				'code_length' => strlen( $code ),
			] ),
			'content_format' => 'json',
			'user_id'        => $user_id,
			'tool_id'        => 'code_' . $gen_type,
			'tool_type'      => 'code_builder',
			'caller'         => $config['caller'] ?? 'studio',
			'input_snapshot' => [
				'prompt' => $prompt,
				'model'  => $model,
				'stack'  => $stack,
			],
		] );
	}

	/* ═══════════════════════════════════════════════
	 *  GENERATION LOGGING — checkpoint per prompt
	 * ═══════════════════════════════════════════════ */

	/**
	 * Log a generation start (checkpoint).
	 */
	private static function log_generation( array $config ): int {
		global $wpdb;
		$wpdb->insert( BZCode_Installer::table_generations(), [
			'project_id' => (int) ( $config['project_id'] ?? 0 ),
			'variant_id' => (int) ( $config['variant_id'] ?? 0 ),
			'user_id'    => (int) ( $config['user_id'] ?? get_current_user_id() ),
			'action'     => $config['generation_type'] ?? 'create',
			'status'     => 'pending',
			'prompt'     => mb_substr( $config['prompt_summary'] ?? '', 0, 5000 ),
			'model'      => $config['model'] ?? '',
			'created_at' => current_time( 'mysql', true ),
		] );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Complete a generation log with final status + code snapshot.
	 */
	private static function complete_generation(
		int $gen_id, string $status, float $start_time,
		?string $error = null, int $variant_id = 0,
		?string $code_snapshot = null, int $tokens_used = 0, string $model = ''
	): void {
		if ( $gen_id <= 0 ) return;
		global $wpdb;
		$data = [
			'status'       => $status,
			'duration_ms'  => (int) ( ( microtime( true ) - $start_time ) * 1000 ),
			'completed_at' => current_time( 'mysql', true ),
		];
		if ( $variant_id > 0 ) {
			$data['variant_id'] = $variant_id;
		}
		if ( $error ) {
			$data['error_message'] = mb_substr( $error, 0, 2000 );
		}
		if ( $code_snapshot !== null ) {
			$data['code_snapshot'] = $code_snapshot;
		}
		if ( $tokens_used > 0 ) {
			$data['tokens_used'] = $tokens_used;
		}
		if ( $model ) {
			$data['model'] = $model;
		}
		$wpdb->update( BZCode_Installer::table_generations(), $data, [ 'id' => $gen_id ] );
	}

	/* ═══════════════════════════════════════════════
	 *  SECTIONAL CREATE — generate landing page by sections
	 *
	 *  Instead of one monolithic LLM call, this:
	 *  1. First call: generate a section plan (skeleton)
	 *  2. Then: generate each section sequentially, streaming tokens
	 *  3. Assemble all sections into one HTML
	 *
	 *  Benefits: richer content, consistent style across sections,
	 *  avoids token limit truncation on long pages.
	 * ═══════════════════════════════════════════════ */

	/**
	 * @param array $params Same as create(), plus:
	 *   sections: string[] (optional — user-defined section list)
	 * @param callable $on_chunk   fn( int $variant_index, string $delta )
	 * @param callable $on_done    fn( int $variant_index, string $full_code, array $usage )
	 * @param callable $on_error   fn( int $variant_index, string $error )
	 * @param callable $on_section fn( int $section_index, int $total, string $section_name )
	 */
	public static function create_sectional(
		array $params, callable $on_chunk, callable $on_done,
		callable $on_error, callable $on_section
	): array {
		$stack    = $params['stack'] ?? 'html_css';
		$model    = $params['model'] ?? self::DEFAULT_MODEL;
		$user_id  = (int) ( $params['user_id'] ?? get_current_user_id() );
		$prompt   = $params['prompt'] ?? '';

		if ( empty( $params['model'] ) && function_exists( 'bizcity_llm_get_model' ) ) {
			$purpose = ! empty( $params['images'] ) ? 'vision' : 'code';
			$model   = bizcity_llm_get_model( $purpose ) ?: self::DEFAULT_MODEL;
		}

		// 1. Ensure project
		$project_id = (int) ( $params['project_id'] ?? 0 );
		if ( ! $project_id ) {
			$project_id = BZCode_Project_Manager::insert( [
				'user_id' => $user_id,
				'title'   => mb_substr( $prompt, 0, 100 ) ?: 'Landing Page',
				'stack'   => $stack,
			] );
			if ( ! $project_id ) {
				$on_error( 0, 'Failed to create project.' );
				return [];
			}
		}

		// 2. Create page
		$page_id = BZCode_Page_Manager::insert( [
			'project_id' => $project_id,
			'title'      => 'index',
			'slug'       => 'index',
		] );

		// 3. Create variant row
		$variant_id = BZCode_Variant_Manager::insert( [
			'page_id'         => $page_id,
			'variant_index'   => 0,
			'model_used'      => $model,
			'generation_type' => 'create',
			'is_selected'     => 1,
		] );

		// 4. Log generation
		$gen_id     = self::log_generation( [
			'project_id'      => $project_id,
			'variant_id'      => $variant_id,
			'user_id'         => $user_id,
			'generation_type' => 'sectional',
			'prompt_summary'  => mb_substr( $prompt, 0, 200 ),
			'model'           => $model,
		] );
		$start_time = microtime( true );

		// 5. Determine sections
		$sections = $params['sections'] ?? [];
		if ( empty( $sections ) ) {
			$sections = self::plan_sections( $prompt, $params['images'] ?? [], $model );
		}
		if ( empty( $sections ) ) {
			$sections = [ 'Hero', 'Features', 'About', 'Testimonials', 'Pricing', 'CTA', 'Footer' ];
		}

		// 5b. Load source context for richer generation
		$source_context = $params['source_context'] ?? '';
		if ( empty( $source_context ) && $project_id && class_exists( 'BZCode_Source_Manager' ) ) {
			$source_context = BZCode_Source_Manager::get_all_content( $project_id );
		}

		$total_sections = count( $sections );
		$total_usage    = [ 'prompt_tokens' => 0, 'completion_tokens' => 0 ];
		$assembled_html = '';
		$section_codes  = [];

		// 6. Generate each section
		foreach ( $sections as $si => $section_name ) {
			$on_section( $si, $total_sections, $section_name );

			$section_prompt = self::build_section_prompt(
				$prompt, $section_name, $si, $total_sections,
				$params['images'] ?? [], $stack, $assembled_html, $source_context
			);

			$system = self::build_section_system_prompt( $stack, $si === 0, $si === $total_sections - 1 );

			if ( ! function_exists( 'bizcity_llm_chat_stream' ) ) {
				$on_error( 0, 'LLM router not available.' );
				self::complete_generation( $gen_id, 'error', $start_time, 'LLM router missing', $variant_id );
				return [];
			}

			$messages = [
				[ 'role' => 'system', 'content' => $system ],
				[ 'role' => 'user', 'content' => $section_prompt ],
			];

			$result = bizcity_llm_chat_stream(
				$messages,
				[ 'model' => $model, 'purpose' => 'code', 'temperature' => 0.7, 'max_tokens' => 4000, 'timeout' => 90 ],
				function ( string $delta, string $full_so_far ) use ( $on_chunk ) {
					$on_chunk( 0, $delta );
				}
			);

			if ( ! empty( $result['success'] ) && ! empty( $result['message'] ) ) {
				$section_code = self::extract_code( $result['message'] );
				$section_codes[] = $section_code;
				$assembled_html .= "\n<!-- ═══ Section: {$section_name} ═══ -->\n" . $section_code;
				$usage = $result['usage'] ?? [];
				$total_usage['prompt_tokens']     += (int) ( $usage['prompt_tokens'] ?? 0 );
				$total_usage['completion_tokens']  += (int) ( $usage['completion_tokens'] ?? 0 );
			} else {
				$error_msg = $result['error'] ?? "Section {$section_name} failed.";
				$on_error( 0, $error_msg );
				// Continue with remaining sections even if one fails
			}
		}

		// 7. Assemble full HTML page
		$full_code = self::assemble_sections( $assembled_html, $stack, $prompt );

		// 8. Save to variant
		BZCode_Variant_Manager::update( $variant_id, [
			'code'         => $full_code,
			'status'       => 'complete',
			'token_input'  => $total_usage['prompt_tokens'],
			'token_output' => $total_usage['completion_tokens'],
		] );

		// 9. Complete generation log
		self::complete_generation( $gen_id, 'complete', $start_time, null, $variant_id, $full_code,
			$total_usage['prompt_tokens'] + $total_usage['completion_tokens'], $model
		);

		self::register_output( $variant_id, $full_code, [
			'project_id'      => $project_id,
			'user_id'         => $user_id,
			'model'           => $model,
			'stack'           => $stack,
			'generation_type' => 'sectional',
			'prompt_summary'  => mb_substr( $prompt, 0, 200 ),
			'caller'          => $params['caller'] ?? 'studio',
		] );

		$on_done( 0, $full_code, $total_usage );

		return [
			'project_id'  => $project_id,
			'page_id'     => $page_id,
			'variant_ids' => [ $variant_id ],
			'sections'    => $sections,
		];
	}

	/**
	 * Ask LLM to plan landing page sections based on the prompt.
	 * Returns array of section names or empty on failure.
	 */
	private static function plan_sections( string $prompt, array $images, string $model ): array {
		if ( ! function_exists( 'bizcity_llm_chat_stream' ) ) {
			return [];
		}

		$plan_prompt = <<<PROMPT
You are a landing page architect. Given the user's request below, output a JSON array of section names
for a professional landing page. Each section name should be short (1-3 words).

Typical sections: Hero, Navigation, Features, About, Services, Testimonials, Pricing, Team, FAQ, CTA, Contact, Footer.
Choose 5-10 sections that best fit the request. Output ONLY a JSON array, e.g.:
["Hero", "Features", "Pricing", "Testimonials", "CTA", "Footer"]

User request: {$prompt}
PROMPT;

		$result = bizcity_llm_chat_stream(
			[
				[ 'role' => 'system', 'content' => 'Output ONLY a JSON array of section names. No explanation.' ],
				[ 'role' => 'user', 'content' => $plan_prompt ],
			],
			[ 'model' => $model, 'purpose' => 'code', 'temperature' => 0.3, 'max_tokens' => 500, 'timeout' => 30 ],
			function () {} // no streaming needed for plan
		);

		if ( ! empty( $result['success'] ) && ! empty( $result['message'] ) ) {
			$text = trim( $result['message'] );
			// Extract JSON array
			if ( preg_match( '/\[.*\]/s', $text, $m ) ) {
				$sections = json_decode( $m[0], true );
				if ( is_array( $sections ) && count( $sections ) >= 3 ) {
					return array_map( 'trim', $sections );
				}
			}
		}

		return [];
	}

	/**
	 * Build system prompt for a single section generation.
	 */
	private static function build_section_system_prompt( string $stack, bool $is_first, bool $is_last ): string {
		$stack_label = self::STACKS[ $stack ]['label'] ?? 'HTML + CSS';

		$prompt = <<<PROMPT
You are an expert web developer creating ONE SECTION of a landing page.
You write clean, production-ready {$stack_label} code.

RULES:
- Output ONLY the HTML for this ONE section (a <section>, <header>, <nav>, or <footer> element).
- Do NOT output <!DOCTYPE>, <html>, <head>, or <body> tags.
- Do NOT output <style> blocks — use inline styles or Tailwind/framework classes.
- Make the section responsive (mobile-first).
- Use semantic HTML.
- For images, use https://placehold.co with descriptive alt text.
- Output ONLY code, no explanations.
PROMPT;

		if ( $stack === 'html_css' ) {
			$prompt .= "\n- Use inline styles or <style> scoped within the section.";
		}
		if ( str_contains( $stack, 'tailwind' ) ) {
			$prompt .= "\n- Use Tailwind CSS utility classes.";
		}
		if ( $stack === 'bootstrap' ) {
			$prompt .= "\n- Use Bootstrap 5 classes.";
		}
		if ( $is_first ) {
			$prompt .= "\n- This is the FIRST section (hero/header). Set the visual tone: colors, fonts, overall style.";
		}
		if ( $is_last ) {
			$prompt .= "\n- This is the LAST section (footer/CTA). Include closing elements.";
		}

		return $prompt;
	}

	/**
	 * Build user prompt for a single section, including context from previous sections.
	 */
	private static function build_section_prompt(
		string $overall_prompt, string $section_name, int $section_index,
		int $total_sections, array $images, string $stack, string $previous_html,
		string $source_context = ''
	): string {
		$content = [];

		// Include images only for the first section (hero — sets visual tone)
		if ( $section_index === 0 && ! empty( $images ) ) {
			foreach ( $images as $img ) {
				$content[] = [
					'type'      => 'image_url',
					'image_url' => [ 'url' => $img ],
				];
			}
		}

		$position = ( $section_index + 1 ) . '/' . $total_sections;
		$text = "Landing page topic: {$overall_prompt}\n\n";
		$text .= "Generate section [{$position}]: **{$section_name}**\n\n";

		if ( $section_index > 0 && ! empty( $previous_html ) ) {
			// Send a style hint from previous HTML (trimmed to save tokens)
			$style_hint = self::extract_style_hint( $previous_html );
			if ( $style_hint ) {
				$text .= "STYLE CONTEXT (match these patterns for consistency):\n{$style_hint}\n\n";
			}
		}

		// Inject source context for richer content (trimmed per section)
		if ( $source_context ) {
			$text .= "═══ NỘI DUNG NGUỒN (sử dụng data thật, KHÔNG bịa) ═══\n";
			$text .= mb_substr( $source_context, 0, 8000 ) . "\n\n";
		}

		$text .= "Requirements:\n";
		$text .= "- Match the overall design language, colors, and typography from previous sections.\n";
		$text .= "- This section should be visually cohesive with the rest of the page.\n";
		$text .= "- Make it professional and conversion-focused.\n";
		if ( $source_context ) {
			$text .= "- USE real data from the sources above. Do NOT invent product names, pricing, or testimonials.\n";
		}

		$content[] = [ 'type' => 'text', 'text' => $text ];

		return is_array( $content ) && count( $content ) === 1 && $content[0]['type'] === 'text'
			? $content[0]['text']
			: $content;
	}

	/**
	 * Extract color/font/class patterns from existing HTML for style consistency.
	 */
	private static function extract_style_hint( string $html ): string {
		$hints = [];

		// Extract Tailwind color classes
		if ( preg_match_all( '/(?:bg|text|border)-(?:[a-z]+-\d{2,3})/i', $html, $m ) ) {
			$colors = array_unique( $m[0] );
			$hints[] = 'Colors used: ' . implode( ', ', array_slice( $colors, 0, 15 ) );
		}

		// Extract inline color values
		if ( preg_match_all( '/(?:color|background(?:-color)?)\s*:\s*(#[0-9a-fA-F]{3,8}|rgb[^)]+\))/i', $html, $m ) ) {
			$inline_colors = array_unique( $m[1] );
			$hints[] = 'Inline colors: ' . implode( ', ', array_slice( $inline_colors, 0, 10 ) );
		}

		// Extract font families
		if ( preg_match_all( '/font-family\s*:\s*([^;}"]+)/i', $html, $m ) ) {
			$fonts = array_unique( array_map( 'trim', $m[1] ) );
			$hints[] = 'Fonts: ' . implode( ', ', array_slice( $fonts, 0, 5 ) );
		}

		return implode( "\n", $hints );
	}

	/**
	 * Assemble section codes into a full HTML page with head/body wrapper.
	 */
	private static function assemble_sections( string $sections_html, string $stack, string $title ): string {
		$cdn = '';
		if ( str_contains( $stack, 'tailwind' ) ) {
			$cdn = '<script src="https://cdn.tailwindcss.com"></script>';
		} elseif ( $stack === 'bootstrap' ) {
			$cdn = '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">';
		}

		$safe_title = esc_html( mb_substr( $title, 0, 100 ) ?: 'Landing Page' );

		return <<<HTML
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{$safe_title}</title>
  {$cdn}
</head>
<body>
{$sections_html}
</body>
</html>
HTML;
	}

	/* ═══════════════════════════════════════════════
	 *  UTILS
	 * ═══════════════════════════════════════════════ */

	/**
	 * Extract code from LLM response (strip markdown code fences).
	 */
	public static function extract_code( string $text ): string {
		// Try to extract from code block
		if ( preg_match( '/```(?:html|tsx|vue|css|jsx)?\s*\n(.*?)```/s', $text, $m ) ) {
			return trim( $m[1] );
		}
		// If response starts with <!DOCTYPE or <html, it's already raw code
		$trimmed = trim( $text );
		if ( stripos( $trimmed, '<!DOCTYPE' ) === 0 || stripos( $trimmed, '<html' ) === 0 ) {
			return $trimmed;
		}
		return $trimmed;
	}
}
