<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Agents\Bootstrap
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 *
 * Phase 0.13 / Vòng 3 + Phase 6.4 — Image Agent.
 *
 * Two tools:
 *   • compose_image_prompt — original Vòng 3 stub: returns a polished
 *     English prompt (no real generation). Useful when the user just
 *     wants to copy a prompt to paste elsewhere.
 *   • generate_image      — Phase 6.4 NEW: triggers the bzdoc-image
 *     pipeline through BZDoc_Notebook_Bridge and opens an iframe in the
 *     Canvas. HIL-gated when n_variants > 1 (cost guard).
 *
 * Compliance:
 *   - R-GW: prompt composing inside compose_image_prompt uses
 *     BizCity_LLM_Client. Real image generation goes through
 *     BizCity_Router_Proxy::generate_image inside the bzdoc pipeline.
 *   - Phase 0.15 §10 AC#3: needs_approval=true when n_variants>1.
 *   - Rule 8g F4: tool returns artifact_created so Canvas auto-opens.
 *
 * @since 6.4.0
 */

defined( 'ABSPATH' ) || exit;

/* ───────────────────────── tool 1: compose_image_prompt ───────────── */

$image_prompt_tool = new BizCity_TwinShell_Tool(
	'compose_image_prompt',
	'Compose a detailed image-generation prompt (English) from a Vietnamese or English idea. Includes subject, style, lighting, composition. Use when the user only wants the prompt text.',
	[
		'type'       => 'object',
		'properties' => [
			'idea'  => [
				'type'        => 'string',
				'description' => 'The user idea / scene description.',
			],
			'style' => [
				'type'        => 'string',
				'description' => 'Optional style hint (photorealistic, watercolor, cyberpunk, ...).',
			],
		],
		'required'   => [ 'idea' ],
	],
	function ( array $args, array $ctx ) {
		$idea  = isset( $args['idea'] ) ? trim( (string) $args['idea'] ) : '';
		$style = isset( $args['style'] ) ? trim( (string) $args['style'] ) : '';
		if ( $idea === '' ) {
			return [ 'ok' => false, 'error' => 'missing_idea' ];
		}

		if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
			return [ 'ok' => false, 'error' => 'llm_missing' ];
		}
		$llm = BizCity_LLM_Client::instance();
		if ( ! $llm->is_ready() ) {
			return [ 'ok' => false, 'error' => 'llm_not_ready' ];
		}

		$user_prompt = $style !== ''
			? sprintf( "Idea: %s\nPreferred style: %s", $idea, $style )
			: sprintf( 'Idea: %s', $idea );

		$result = $llm->chat( [
			[ 'role' => 'system', 'content' => "You are an image-prompt engineer. Turn the user idea into ONE detailed English prompt (1-3 sentences) including subject, style, lighting, composition, mood. Output ONLY the prompt — no labels, no quotes." ],
			[ 'role' => 'user',   'content' => $user_prompt ],
		], [ 'purpose' => 'chat' ] );

		if ( empty( $result['success'] ) ) {
			return [ 'ok' => false, 'error' => 'llm_failed', 'message' => $result['error'] ?? '' ];
		}

		return [
			'ok'     => true,
			'idea'   => $idea,
			'style'  => $style,
			'prompt' => trim( (string) ( $result['message'] ?? '' ) ),
		];
	}
);

/* ───────────────────────── tool 2: generate_image (Phase 6.4) ──────── */

$generate_image_tool = new BizCity_TwinShell_Tool(
	'generate_image',
	'Generate REAL image variants (1-4) via Nano Banana Pro (Gemini 3 Pro Image Preview). Use when the user explicitly asks to "tạo ảnh", "vẽ poster", "make image", "render". Opens a bzdoc-image canvas iframe; the FE shows variants for selection. Argument "topic" required.',
	[
		'type'       => 'object',
		'properties' => [
			'topic' => [
				'type'        => 'string',
				'description' => 'Idea / scene description (Vietnamese or English).',
			],
			'style_preset' => [
				'type'        => 'string',
				'description' => 'Optional style preset (photorealistic, cyberpunk, watercolor, ...).',
			],
			'aspect_ratio' => [
				'type'        => 'string',
				'description' => 'Aspect ratio of generated image.',
				'enum'        => [ '1:1', '3:2', '2:3', '16:9', '9:16' ],
			],
			'n_variants' => [
				'type'        => 'integer',
				'description' => 'Number of variants (1-4). Anything > 1 requires user approval (cost gate).',
				'minimum'     => 1,
				'maximum'     => 4,
				'default'     => 1,
			],
		],
		'required'   => [ 'topic' ],
	],
	// execute_callback — delegate to bzdoc bridge for the instant
	// "blank doc + autogen URL" handoff. The pipeline runs INSIDE the
	// bzdoc iframe, so this call returns in <1s with an edit_url that
	// Canvas opens immediately (Rule 8g F4).
	function ( array $args, array $ctx ) {
		$topic = isset( $args['topic'] ) ? trim( (string) $args['topic'] ) : '';
		if ( $topic === '' ) {
			return [ 'ok' => false, 'error' => 'missing_topic' ];
		}

		if ( ! class_exists( 'BZDoc_Notebook_Bridge' )
			|| ! method_exists( 'BZDoc_Notebook_Bridge', 'generate_from_skeleton_public' ) ) {
			return [
				'ok'      => false,
				'error'   => 'bzdoc_bridge_unavailable',
				'message' => 'Plugin BizCity Doc Studio chưa sẵn sàng (notebook bridge missing).',
			];
		}

		$nb_id = isset( $ctx['notebook_id'] ) ? (int) $ctx['notebook_id'] : 0;

		// PHASE-6.4 BUG-FIX (May 2026) — TRUSTED structured options come from
		// FE (ImageStudioTab) via context_overrides.image_opts. We trust these
		// over LLM-emitted args because:
		//   • LLM rule#1 forces topic VERBATIM, leaving little budget to also
		//     emit style/aspect/n_variants accurately;
		//   • FE values come straight from the form selector — no hallucination;
		//   • reference_images (dataURLs) and template_slug have NO place in the
		//     LLM tool schema, so they MUST come via context.
		// Fallback chain: $ctx_opts → $args → defaults.
		$ctx_opts   = isset( $ctx['image_opts'] ) && is_array( $ctx['image_opts'] ) ? $ctx['image_opts'] : [];
		$raw_style  = strtolower( trim( (string) ( $ctx_opts['style_preset'] ?? $args['style_preset'] ?? '' ) ) );
		$aspect_in  = (string) ( $ctx_opts['aspect_ratio'] ?? $args['aspect_ratio'] ?? '1:1' );
		$n_variants = max( 1, min( 4, (int) ( $ctx_opts['n_variants'] ?? $args['n_variants'] ?? 1 ) ) );
		$tpl_slug   = (string) ( $ctx_opts['template_slug']  ?? '' );
		$tpl_title  = (string) ( $ctx_opts['template_title'] ?? '' );

		// reference_images: FE gửi mảng [{ name, url, attachment_id }, …]
		// (May 2026: TwinChat upload sang WP Media trước → URL HTTPS, không
		// còn truyền base64 dataURL qua agent payload). Vẫn accept legacy
		// shape (string dataURL hoặc { dataUrl }) để không phá những flow cũ.
		// Bzdoc pipeline (`upload_ref_images_to_media`) chấp nhận cả URL lẫn
		// data URI — chỉ cần là string hợp lệ.
		$refs_in = $ctx_opts['reference_images'] ?? [];
		$refs    = [];
		if ( is_array( $refs_in ) ) {
			foreach ( $refs_in as $r ) {
				$candidate = '';
				if ( is_string( $r ) ) {
					$candidate = $r;
				} elseif ( is_array( $r ) ) {
					if ( ! empty( $r['url'] ) && is_string( $r['url'] ) ) {
						$candidate = $r['url'];
					} elseif ( ! empty( $r['dataUrl'] ) && is_string( $r['dataUrl'] ) ) {
						$candidate = $r['dataUrl'];
					}
				}
				$ok = false;
				if ( $candidate !== '' ) {
					if ( strpos( $candidate, 'data:image/' ) === 0 ) {
						$ok = true;
					} elseif ( strpos( $candidate, 'https://' ) === 0 || strpos( $candidate, 'http://' ) === 0 ) {
						$ok = true;
					}
				}
				if ( $ok ) {
					$refs[] = $candidate;
				}
				if ( count( $refs ) >= 4 ) break; // cap 4 (cùng UI cap)
			}
		}

		// PHASE-6.4 BUG-FIX (May 2026) — map TwinChat ImageStudioTab style ids
		// (auto/photo/illustration/cinematic/anime/3d/minimal) to bzdoc's
		// STYLE_PRESETS values (photoreal/cinematic/editorial/flat_illustration/
		// cyberpunk/studio_product/minimal_logo). Without this mapping the FE
		// preset selector falls back to "Tự động" because no option matches.
		$style_map = [
			''               => '',
			'auto'           => '',
			'photo'          => 'photoreal',
			'photoreal'      => 'photoreal',
			'photorealistic' => 'photoreal',
			'illustration'   => 'flat_illustration',
			'flat'           => 'flat_illustration',
			'flat_illustration' => 'flat_illustration',
			'cinematic'      => 'cinematic',
			'cinema'         => 'cinematic',
			'editorial'      => 'editorial',
			'anime'          => 'flat_illustration',
			'3d'             => 'studio_product',
			'studio_product' => 'studio_product',
			'product'        => 'studio_product',
			'minimal'        => 'minimal_logo',
			'minimal_logo'   => 'minimal_logo',
			'logo'           => 'minimal_logo',
			'cyberpunk'      => 'cyberpunk',
		];
		$style_preset = $style_map[ $raw_style ] ?? '';

		// PHASE-6.4 PROMPT-ARGS (May 2026) — TwinChat đã resolve preset slug
		// → prompt_id và pre-fill prompt_args (default || placeholder cho
		// từng argument). Pass through trực tiếp vào schema_json._autogen.
		// Bzdoc DocApp seeds ImagePromptInput → kickstart fires ngay không
		// cần user fill form thủ công.
		$prompt_id_in = isset( $ctx_opts['prompt_id'] ) ? (int) $ctx_opts['prompt_id'] : 0;
		$prompt_args_in = [];
		if ( isset( $ctx_opts['prompt_args'] ) && is_array( $ctx_opts['prompt_args'] ) ) {
			foreach ( $ctx_opts['prompt_args'] as $k => $v ) {
				if ( is_string( $k ) && ( is_string( $v ) || is_numeric( $v ) ) ) {
					$prompt_args_in[ $k ] = (string) $v;
				}
			}
		}
		// Default kickstart=true vì TwinChat luôn muốn auto-fire khi đã prefill
		// đủ. Cho phép FE override (kickstart: false) cho trường hợp debug.
		$kickstart_in = array_key_exists( 'kickstart', $ctx_opts )
			? (bool) $ctx_opts['kickstart']
			: true;

		$skeleton = [
			'nucleus' => [
				'title'  => $topic,
				'thesis' => '',
				'domain' => '',
			],
			'project_id' => $nb_id > 0 ? ( 'tc_' . $nb_id ) : '',
			'image_opts' => [
				'style_preset'     => $style_preset,
				'aspect_ratio'     => $aspect_in,
				'n_variants'       => $n_variants,
				// PHASE-6.4 NEW (May 2026) — reference_images (dataURL[]) +
				// template metadata. Bridge stores as-is in
				// schema_json._autogen.image_opts; bzdoc DocApp reads &
				// seeds ImagePromptInput.referenceImages on mount.
				'reference_images' => $refs,
				'template_slug'    => $tpl_slug,
				'template_title'   => $tpl_title,
				// Resolved preset id + pre-filled args (from TwinChat).
				'prompt_id'        => $prompt_id_in > 0 ? $prompt_id_in : null,
				'prompt_args'      => ! empty( $prompt_args_in ) ? $prompt_args_in : null,
				'kickstart'        => $kickstart_in,
			],
			'_raw_text'  => $topic,
			// PHASE-6.4 BUG-FIX (May 2026) — Kickstart so the bzdoc image iframe
			// auto-fires generation on load instead of waiting for a second click.
			// Bridge stamps `kickstart: true` into _autogen + appends ?kickstart=1
			// to the handoff URL; ImagePromptInput then triggers submit() on mount.
			// Without this the user lands on /tool-doc/?id=X&autogen=1 with the
			// form prefilled but stuck — "autogen" alone never fired the worker
			// for image doc_type (kickstart handler in DocApp explicitly skips
			// image because ImagePromptInput owns its own kickstart effect).
			'_kickstart' => $kickstart_in,
		];

		$result = BZDoc_Notebook_Bridge::generate_from_skeleton_public( $skeleton, 'image' );
		if ( is_wp_error( $result ) ) {
			return [
				'ok'      => false,
				'error'   => $result->get_error_code(),
				'message' => $result->get_error_message(),
			];
		}

		// Bridge returns either { content, content_format, data:{ doc_id, url } }
		// or a direct array — normalize both.
		$doc_id = 0; $edit_url = '';
		if ( is_array( $result ) ) {
			if ( ! empty( $result['data']['doc_id'] ) ) {
				$doc_id   = (int) $result['data']['doc_id'];
				$edit_url = (string) ( $result['data']['url'] ?? '' );
			} elseif ( ! empty( $result['doc_id'] ) ) {
				$doc_id   = (int) $result['doc_id'];
				$edit_url = (string) ( $result['edit_url'] ?? '' );
			}
		}
		if ( ! $doc_id ) {
			return [ 'ok' => false, 'error' => 'bridge_failed', 'raw' => $result ];
		}
		if ( $edit_url === '' ) {
			$edit_url = home_url( '/tool-doc/?id=' . $doc_id . '&autogen=1' );
		}

		$title = 'Image: ' . $topic;
		if ( $nb_id > 0 && class_exists( 'BizCity_Artifact_Source_Federation' ) ) {
			BizCity_Artifact_Source_Federation::stamp( 'bizcity-doc-image', $doc_id, $nb_id, $title, $edit_url );
		}

		return [
			'ok'        => true,
			'doc_id'    => $doc_id,
			'doc_type'  => 'image',
			'topic'     => $topic,
			'title'     => $title,
			'edit_url'  => $edit_url,
			// Rule 8g F4 — Canvas auto-opens this iframe.
			'artifact_created' => class_exists( 'BizCity_Artifact_Source_Federation' )
				? BizCity_Artifact_Source_Federation::make_artifact_created( 'bizcity-doc-image', $doc_id, $title, $edit_url, $nb_id )
				: [
					'plugin_name' => 'bizcity-doc-image',
					'studio_id'   => $doc_id,
					'title'       => $title,
					'edit_url'    => $edit_url,
				],
		];
	},
	null, // is_enabled — always
	// needs_approval — HIL gate when n_variants > 1 (each gpt-image-1
	// 1024x1024 ≈ $0.17, so 4 variants ≈ $0.68 — non-trivial cost).
	function ( array $args, array $ctx ): bool {
		// PHASE-6.4 BUG-FIX (May 2026) — check ctx first (FE truth) before LLM args.
		$ctx_opts = isset( $ctx['image_opts'] ) && is_array( $ctx['image_opts'] ) ? $ctx['image_opts'] : [];
		$n = (int) ( $ctx_opts['n_variants'] ?? $args['n_variants'] ?? 1 );
		return $n > 1;
	}
);

/* ───────────────────────── agent registration ──────────────────────── */

add_filter( 'bizcity_register_agent', function ( array $agents ) use ( $image_prompt_tool, $generate_image_tool ): array {
	$agents['image'] = new BizCity_TwinShell_Agent(
		'image',
		// System instructions
		"You are the Image Agent. You create images and image prompts for the user.\n\n"
		. "RULES:\n"
		. "1. If the user asks to ACTUALLY create / draw / render / 'tạo ảnh', 'vẽ poster', 'render hình' → call the `generate_image` tool. **CRITICAL — `topic` MUST preserve the user's prompt VERBATIM.** Do NOT translate, summarize, paraphrase, or convert Vietnamese ↔ English. If the user message contains a `<TOPIC>…</TOPIC>` block (sent by the Image Studio FE), pass the exact text between those tags as `topic` — character for character — including any 'Ngữ cảnh từ ghi chú đã ghim' / pinned-note context block inside it. Otherwise, if the message starts with a 'Tạo ảnh:' marker, pass everything after that marker as `topic` exactly as written. Only fill `style_preset` / `aspect_ratio` / `n_variants` from the explicit 'Phong cách:' / 'Tỉ lệ:' / 'Số variant:' lines if present. Default n_variants=1 unless the user explicitly asks for multiple options (then 2 or 4 — requires HIL approval).\n"
		. "2. If the user only asks for a prompt text to copy → call `compose_image_prompt`.\n"
		. "3. After `generate_image` succeeds, reply ONE short Vietnamese sentence telling them the image is ready in the Canvas (e.g. \"Đã tạo ảnh về <topic> — bạn xem ở khung Canvas bên phải.\"). Do NOT include the prompt or URL.\n"
		. "4. After `compose_image_prompt` succeeds, present `result.prompt` inside a fenced ```text``` block.\n"
		. "5. NEVER write the image prompt yourself — always call the tool.\n"
		. "6. NEVER attempt to generate images by emitting URLs or markdown.",
		[ $image_prompt_tool, $generate_image_tool ],
		[],   // no handoffs
		null  // default model
	);
	return $agents;
} );
