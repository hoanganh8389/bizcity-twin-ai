<?php
/**
 * Phase 6.4 — Image generation pipeline.
 *
 * 4-step pipeline orchestrated from the bzdoc Canvas iframe (NOT TwinChat):
 *
 *   a. Build KG bundle           ← BizCity_Twin_Context_Resolver  (R-C1)
 *   b. Compose final prompt      ← BizCity_LLM_Client              (R-GW)
 *   c. Generate image variants   ← BizCity_Router_Proxy::generate_image (R-GW)
 *   d. Sideload + federation     ← wp_handle_sideload + Artifact stamp
 *
 * Compliance:
 *   - V1 (R-GW): NEVER call OpenAI/Anthropic directly.
 *   - V2 (KG-Hub): build_bundle('doc', $doc_id) with graceful fallback.
 *   - V8 (Federation): stamp per attachment so ImageAgent + Canvas Bridge
 *     can resolve the artifact in twin context later.
 *
 * @package BizCity_Doc
 * @since   0.4.72
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BZDoc_Image_Pipeline {

	/**
	 * Aspect ratio → OpenAI Images API size string.
	 */
	const SIZE_MAP = [
		'1:1'  => '1024x1024',
		'3:2'  => '1536x1024',
		'2:3'  => '1024x1536',
		'16:9' => '1792x1024',
		'9:16' => '1024x1792',
	];

	/**
	 * Run the full pipeline. Returns ImageSchema (variants populated) or WP_Error.
	 *
	 * Expected $request keys:
	 *   topic         (string, required)  — natural language idea
	 *   prompt_id     (int, optional)     — catalog template id
	 *   prompt_args   (array, optional)   — Raycast args for template
	 *   style_preset  (string, optional)
	 *   aspect_ratio  (string, default 1:1)
	 *   n_variants    (int 1-4, default 1)
	 *   user_id       (int, default current)
	 */
	public static function run( int $doc_id, array $request ) {
		$user_id = (int) ( $request['user_id'] ?? get_current_user_id() );
		$topic   = trim( (string) ( $request['topic'] ?? '' ) );

		$aspect = isset( $request['aspect_ratio'] ) ? (string) $request['aspect_ratio'] : '1:1';
		if ( ! isset( self::SIZE_MAP[ $aspect ] ) ) $aspect = '1:1';

		$n = max( 1, min( 4, (int) ( $request['n_variants'] ?? 1 ) ) );

		/* ── Step 0: Resolve template if requested ─────────────────── */
		$resolved_template_text = '';
		$prompt_id = (int) ( $request['prompt_id'] ?? 0 );
		if ( $prompt_id > 0 ) {
			$row = BZDoc_Image_Prompts_Database::get_by_id( $prompt_id );
			if ( ! $row ) {
				return new \WP_Error( 'prompt_not_found', 'Prompt template không tồn tại.' );
			}
			$tmpl = json_decode( (string) $row['template_json'], true );
			$args = isset( $request['prompt_args'] ) && is_array( $request['prompt_args'] )
				? $request['prompt_args'] : [];
			if ( $topic && empty( $args['topic'] ) ) $args['topic'] = $topic;

			$valid = BZDoc_Image_Argument_Resolver::validate( $tmpl, $args );
			if ( is_wp_error( $valid ) ) return $valid;

			$substituted = BZDoc_Image_Argument_Resolver::substitute( $tmpl, $args );
			$resolved_template_text = is_array( $substituted )
				? wp_json_encode( $substituted, JSON_UNESCAPED_UNICODE )
				: (string) $substituted;
		}

		if ( $topic === '' && $resolved_template_text === '' ) {
			return new \WP_Error( 'missing_topic', 'Cần chủ đề hoặc template.' );
		}

		// Reference images — two separate arrays:
		//   $reference_images     = CDN URLs (used by LLM compose step for vision analysis).
		//   $reference_images_gen = base64 data URIs (used by image generation step so the
		//                           model always receives actual pixel data, bypassing CDN
		//                           access-control or fetch-timeout issues).
		// When reference_images_b64 is available (set by REST handler after WP media upload),
		// use that for generation. Otherwise fall back to reference_images (may be URL or b64).
		$reference_images = [];
		if ( ! empty( $request['reference_images'] ) && is_array( $request['reference_images'] ) ) {
			foreach ( array_slice( $request['reference_images'], 0, 4 ) as $ref ) {
				if ( is_string( $ref ) && $ref !== '' ) {
					$reference_images[] = $ref;
				}
			}
		}

		$reference_images_gen = [];
		if ( ! empty( $request['reference_images_b64'] ) && is_array( $request['reference_images_b64'] ) ) {
			foreach ( array_slice( $request['reference_images_b64'], 0, 4 ) as $ref ) {
				if ( is_string( $ref ) && $ref !== '' ) {
					$reference_images_gen[] = $ref;
				}
			}
		}
		if ( empty( $reference_images_gen ) ) {
			// Fallback: no separate b64 — use the same array as compose step.
			$reference_images_gen = $reference_images;
		}

		error_log( '[BZDoc Pipeline] reference_images count=' . count( $reference_images ) . ' | gen_count=' . count( $reference_images_gen ) . ' | first_gen=' . substr( (string) ( $reference_images_gen[0] ?? 'NONE' ), 0, 60 ) );

		/* ── Step a: KG bundle (V2 KG-Hub R-C1) ────────────────────── */
		$bundle = self::fetch_kg_bundle( $doc_id, $topic );

		/* ── Step b: LLM compose final image prompt (V1 R-GW) ──────── */
		$style = trim( (string) ( $request['style_preset'] ?? '' ) );
		$composed = self::compose_prompt_via_llm( $topic, $resolved_template_text, $bundle, $style, $reference_images );
		if ( is_wp_error( $composed ) ) return $composed;
		$final_prompt = $composed['prompt'];
		$citations    = $composed['citations'];

		/* ── Step b1: Persist a job row for audit + rate limit ─────── */
		$job_id = self::insert_job_row( $doc_id, $user_id, $prompt_id, $final_prompt, $request, $aspect, $n );

		/* ── Step c: generate variants qua BizCity_LLM_Client (R-GW-8) ──
		 * Client KHÔNG cài bizcity-llm-router. Phải gọi qua LLM Client →
		 * proxy REST https://bizcity.vn/wp-json/bizcity/v1/llm/images/generations
		 * với Bearer biz-xxx. */
		if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
			self::mark_job_failed( $job_id, 'llm_client_unavailable' );
			return new \WP_Error( 'llm_client_unavailable', 'BizCity LLM client chưa load (core/bizcity-llm).' );
		}
		$llm_client = BizCity_LLM_Client::instance();
		if ( ! $llm_client->is_ready() ) {
			self::mark_job_failed( $job_id, 'api_key_missing' );
			return new \WP_Error( 'api_key_missing', 'BizCity API key chưa cấu hình (Settings → BizCity TwinChat).' );
		}

		$size = self::SIZE_MAP[ $aspect ];
		$variants = [];
		$attachment_ids = [];

		for ( $i = 0; $i < $n; $i++ ) {
			/**
			 * Default to Nano Banana Pro (Gemini 3 Pro Image Preview) — best
			 * character consistency + fast turnaround. User can override via UI
			 * picker; filter to switch to alternatives like
			 * `openai/gpt-5.4-image-2` or `openai/gpt-image-1`.
			 */
			$user_model = isset( $request['image_model'] ) ? (string) $request['image_model'] : '';
			$default_model = $user_model !== '' ? $user_model : 'google/gemini-3-pro-image-preview';
			$image_model = apply_filters(
				'bzdoc_image_model',
				$default_model,
				$doc_id,
				$request
			);

			// Per-variant heartbeat — vẫn touch để polling endpoint không tưởng
			// pipeline chết. Stream-event callback bỏ vì gateway client gọi qua
			// REST blocking (server-side bizcity.vn tự stream tới OpenRouter rồi
			// trả image_url/b64_json một lượt khi xong).
			self::touch_job_heartbeat( $doc_id, $i, 'start' );

			error_log( '[BZDoc Pipeline] Calling generate | model=' . $image_model . ' | input_images=' . count( $reference_images_gen ) . ' | prompt_len=' . strlen( $final_prompt ) );

			/* Gọi qua BizCity_LLM_Client → proxy REST tới gateway bizcity.vn.
			 * Truyền stream=true để gateway tự keep-alive với OpenRouter (R-GW-8.3). */
			$gen = $llm_client->generate_image( $final_prompt, [
				'model'        => $image_model,
				'size'         => $size,
				'n'            => 1,
				'timeout'      => 600,
				'input_images' => $reference_images_gen,
				'stream'       => true,
			] );
			if ( empty( $gen['success'] ) ) {
				self::mark_job_failed( $job_id, $gen['error'] ?? 'image_gen_failed' );
				if ( $i === 0 ) {
					return new \WP_Error( 'image_gen_failed', $gen['error'] ?? 'Không sinh được ảnh.' );
				}
				break; // partial success — return what we have
			}

			$att = self::sideload_image( $gen, $doc_id, $i, $final_prompt );
			if ( is_wp_error( $att ) ) {
				if ( $i === 0 ) return $att;
				break;
			}

			$attachment_ids[] = $att['attachment_id'];
			$variants[] = [
				'index'         => $i,
				'attachment_id' => $att['attachment_id'],
				'url'           => $att['url'],
				'width'         => $att['width'],
				'height'        => $att['height'],
				'mime'          => $att['mime'],
				'prompt'        => $final_prompt,
				'citations'     => $citations,
			];

			/* ── Step d: Federation stamp per variant (V8) ─────────── */
			if ( class_exists( 'BizCity_Artifact_Source_Federation' ) ) {
				$title = sprintf( '%s — Variant %d', $topic !== '' ? $topic : 'Image', $i + 1 );
				BizCity_Artifact_Source_Federation::stamp(
					'bizcity-doc-image',
					(int) $att['attachment_id'],
					$doc_id,
					$title,
					$att['url']
				);
			}
		}

		self::mark_job_complete( $job_id, $attachment_ids );

		return [
			'doc_id'           => $doc_id,
			'job_id'           => $job_id,
			'aspect_ratio'     => $aspect,
			'n_variants'       => count( $variants ),
			'final_prompt'     => $final_prompt,
			'citations'        => $citations,
			'variants'         => $variants,
			// Phase 6.4 — original user-uploaded reference images, persisted
			// so subsequent edit_variant() calls can re-anchor the model to
			// the true product/face and avoid identity drift across edits.
			'reference_images' => $reference_images,
		];
	}

	/* ───────────────────────── helpers ───────────────────────── */

	private static function fetch_kg_bundle( int $doc_id, string $question ): array {
		$empty = [ 'passages' => [], 'entities' => [], 'citations_map' => [] ];
		if ( ! class_exists( 'BizCity_Twin_Context_Resolver' )
			|| ! method_exists( 'BizCity_Twin_Context_Resolver', 'build_bundle' ) ) {
			return $empty;
		}
		try {
			$bundle = BizCity_Twin_Context_Resolver::build_bundle( [
				'scope_type' => 'doc',
				'scope_id'   => $doc_id,
				'question'   => $question,
				'budget'     => 4000,
			] );
			return is_array( $bundle ) ? $bundle : $empty;
		} catch ( \Throwable $e ) {
			error_log( '[BZDoc Image] KG bundle failed: ' . $e->getMessage() );
			return $empty;
		}
	}

	/**
	 * Compose the final image prompt via LLM.
	 *
	 * When $reference_images are provided (HTTPS URLs or data: URIs), the
	 * method switches to a vision-capable model and embeds them in the user
	 * message so the LLM can analyse brand identity, colours, typography, and
	 * layout from the reference before writing the generation prompt.
	 * This mirrors what ChatGPT does internally and is the primary reason
	 * GPT Image 2 results from our pipeline were less detailed.
	 *
	 * @param string   $topic            Free-text user idea (may be empty if template given).
	 * @param string   $template_text    JSON-serialised template after argument substitution.
	 * @param array    $bundle           KG context bundle.
	 * @param string   $style            Optional style preset.
	 * @param string[] $reference_images Optional list of HTTPS / data: image URIs.
	 * @return array{prompt:string,citations:array}|WP_Error
	 */
	private static function compose_prompt_via_llm(
		string $topic,
		string $template_text,
		array $bundle,
		string $style,
		array $reference_images = []
	) {
		if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
			return new \WP_Error( 'llm_unavailable', 'LLM Router chưa sẵn sàng.' );
		}
		$llm = BizCity_LLM_Client::instance();
		if ( ! $llm->is_ready() ) {
			return new \WP_Error( 'llm_not_ready', 'LLM Router chưa cấu hình API key.' );
		}

		$ctx_block = self::format_bundle_for_prompt( $bundle );

		$text_payload = '';
		if ( $template_text !== '' ) {
			// Label explicitly as DATA_FIELDS so the LLM treats values as
			// authoritative facts — names, dates, numbers must NOT be changed.
			$text_payload .= "=== DATA_FIELDS (AUTHORITATIVE — use all values verbatim) ===\n"
				. $template_text
				. "\n=== END DATA_FIELDS ===\n\n";
		}
		if ( $topic !== '' ) {
			$text_payload .= "Ý tưởng bổ sung từ người dùng: " . $topic . "\n";
		}
		if ( $style !== '' ) {
			$text_payload .= "Style ưu tiên: " . $style . "\n";
		}
		if ( $ctx_block !== '' ) {
			$text_payload .= "\nNgữ cảnh dự án (KG):\n" . $ctx_block . "\n";
		}

		// Build vision-aware user message when reference images are provided.
		// IMPORTANT: images are used for VISUAL STYLE analysis only (colours,
		// layout, product shape). All factual data (names, dates, brand text)
		// come exclusively from DATA_FIELDS above — never from the images.
		$has_refs = ! empty( $reference_images );
		if ( $has_refs ) {
			$user_content = [
				[
					'type' => 'text',
					'text' =>
						"Dưới đây là ảnh tham chiếu để phân tích màu sắc, typography và phong cách trực quan.\n"
						. "⚠ QUAN TRỌNG: Chỉ dùng ảnh để nhận diện màu sắc, bố cục, tông phong cách, "
						. "đặc điểm ngoại hình (để mô tả chân dung), hoặc hình dáng sản phẩm.\n"
						. "TUYỆT ĐỐI KHÔNG suy luận hoặc thay thế bất kỳ tên người, ngày tháng, "
						. "số liệu, tên thương hiệu nào từ ảnh — tất cả dữ liệu đó đã có trong DATA_FIELDS.\n\n"
						. $text_payload,
				],
			];
			// Cap at 4 reference images to stay within token budget.
			foreach ( array_slice( $reference_images, 0, 4 ) as $img_url ) {
				$user_content[] = [
					'type'      => 'image_url',
					'image_url' => [ 'url' => $img_url, 'detail' => 'high' ],
				];
			}
		} else {
			$user_content = $text_payload;
		}

		$chat_options = [
			'purpose'     => $has_refs ? 'vision' : 'compose',
			'temperature' => 0.7,
			'max_tokens'  => 1200,
			'timeout'     => 45,
		];

		$resp = $llm->chat( [
			[ 'role' => 'system', 'content' => self::system_prompt_image( $has_refs ) ],
			[ 'role' => 'user',   'content' => $user_content ],
		], $chat_options );

		if ( empty( $resp['success'] ) ) {
			return new \WP_Error( 'llm_failed', $resp['error'] ?? 'LLM compose failed.' );
		}

		$decoded = self::extract_json( (string) ( $resp['message'] ?? '' ) );
		if ( ! is_array( $decoded ) || empty( $decoded['prompt'] ) ) {
			// Fallback — use raw message as prompt.
			return [
				'prompt'    => trim( (string) ( $resp['message'] ?? $topic ) ),
				'citations' => [],
			];
		}

		return [
			'prompt'    => trim( (string) $decoded['prompt'] ),
			'citations' => isset( $decoded['references'] ) && is_array( $decoded['references'] )
				? $decoded['references'] : [],
		];
	}

	/**
	 * System prompt for the LLM image-prompt composer.
	 *
	 * @param bool $has_references True when reference images are embedded in
	 *   the user message — activates brand-analysis instructions.
	 */
	private static function system_prompt_image( bool $has_references = false ): string {
		$ref_block = $has_references ? <<<'REF'

REFERENCE IMAGE ANALYSIS — extract the following from the attached images:

A. COLOUR & STYLE (always do this):
   - List dominant colours (hex approximations or descriptive names).
   - Note typography style: weight, serif/sans, decorative vs minimal.
   - Note layout language: grid, margins, spacing, whitespace mood.
   - Note overall visual brand mood: modern/classic, warm/cool, vibrant/muted.

B. PRODUCT / OBJECT (if present):
   - Describe shape, packaging material, label text visible, surface finish.
   - Note proportions: tall/squat, slim/wide, round/angular.

C. PERSON PORTRAIT (if a person's face or body is present — CRITICAL for poster templates):
   - Hair: colour, length, texture (straight/wavy/curly), style (loose/tied/parted).
   - Skin tone: describe using standard descriptors (fair, light olive, warm tan, deep brown, etc.).
   - Facial features: eye shape (almond/round), eyebrow style, jawline (soft/defined), overall face shape.
   - Expression & pose: confident, warm, looking directly, slight smile, etc.
   - Clothing visible in photo: collar type, colour, style (formal/casual).
   *** Reproduce this description VERBATIM in Section 2 (MAIN SUBJECT) of the prompt. ***
   *** THEN add the sentence: "PORTRAIT REFERENCE: Use the attached image [1] as the face
       reference — preserve ethnic features, skin tone, hair, and facial structure exactly." ***

⚠ CRITICAL: Do NOT identify or name anyone in the photos. Never extract names, ages, dates,
  or personal details from images. ALL text data comes exclusively from DATA_FIELDS.
REF
			: '';

		return <<<IMG_SYSPROMPT
You are a senior Visual Brand Designer and Image-Prompt Engineer for BizCity Doc Studio.
Your output is fed directly to GPT Image 2 (state-of-the-art commercial image model) which responds
exceptionally well to highly detailed, structured prompts. Vague one-liner prompts produce mediocre
results; rich, layered prompts produce commercial-quality output.
{$ref_block}
⚠ DATA INTEGRITY RULES — read before writing a single word:
DI-1. All DATA_FIELDS values are USER-SUPPLIED FACTS and are AUTHORITATIVE.
      Reproduce every name, date, number, brand name, slogan, and certificate code VERBATIM.
      Do NOT paraphrase, translate, invent, or replace any factual value.
DI-2. If a person's name appears in DATA_FIELDS (e.g. full_name="Nguyễn Thị Hiền"),
      THAT is the name to use. Do not infer a name from a photo or guess from context.
DI-3. If dates appear in DATA_FIELDS (dob, year, etc.), use those exact dates.
      Do not recalculate or change them.
DI-4. The reference photo is used ONLY to capture the person's physical appearance for
      the portrait illustration — hair, skin tone, facial features, expression, clothing.
      Never use the photo to derive or replace any text data.
DI-5. Brand names, product names, slogans, certifications must appear in the generated
      image EXACTLY as specified in DATA_FIELDS — correct spelling, correct language.
OUTPUT — STRICT JSON only (no markdown fences, no commentary before or after):
{
  "prompt": "<DETAILED generation prompt — see structure below>",
  "negative": "<comma-separated things to AVOID — blurry, watermark, extra limbs…>",
  "references": [ { "cite": "[1]", "source": "<KG source name verbatim>" } ]
}

PROMPT STRUCTURE — your "prompt" value MUST cover ALL applicable sections:

1. IMAGE TYPE & PURPOSE
   State what kind of image this is and its intended use.
   Examples: "Professional Vietnamese product marketing poster for social media ad",
   "Restaurant menu hero shot for landing page", "B2B SaaS explainer infographic".

2. MAIN SUBJECT
   Describe the hero element in rich detail — shape, size, colour, texture, any text on it.
   For products: packaging colour, label text (keep Vietnamese text exact), size/shape.
   For people: If a reference photo was provided (see Reference Image Analysis section C above),
     reproduce the full appearance description (hair, skin tone, facial features, expression)
     EXACTLY as extracted. Then add: "PORTRAIT REFERENCE: Use attached image [1] as the face
     reference — preserve ethnic features, skin tone, hair colour, and facial structure exactly."
     If NO reference photo, describe based on DATA_FIELDS context (age range, attire, pose).

3. BACKGROUND & SETTING
   Specific environment, colours, textures, gradients.
   For posters: gradient direction and exact colour stops.
   For outdoor: location feel, time of day, weather.

4. LAYOUT & COMPOSITION
   How the canvas is divided. Where each element sits (top-left, center, lower-third…).
   Hierarchy: what is largest, what is secondary.
   For posters/ads: headline text placement, subheadline, body copy zone, CTA area.

5. COLOUR PALETTE
   List 3-5 key colours with vivid names or hex approximations.
   E.g. "deep ocean blue #0047AB, pure white #FFFFFF, accent gold #C9A83C".

6. TYPOGRAPHY & TEXT IN IMAGE
   If any text should appear IN the generated image:
   - Write the EXACT text strings (in the original language, e.g. Vietnamese).
   - Specify placement, font style (bold serif / clean sans-serif / handwritten script).
   - Specify size hierarchy (headline large, sub-headline medium, body small).
   GPT Image 2 can render Vietnamese text accurately — use it fully.

7. LIGHTING & ATMOSPHERE
   Photography / rendering style. E.g. "soft studio three-point lighting",
   "golden-hour warm side lighting", "cinematic Rembrandt key light",
   "clean flat commercial product lighting", "dramatic moody backlit fog".

8. STYLE DIRECTIVE
   Overall aesthetic: "professional commercial photography", "editorial fashion",
   "modern flat vector illustration", "watercolour infographic",
   "photorealistic 3D render", "minimalist Bauhaus poster", etc.

9. QUALITY DIRECTIVE (always include)
   "Professional commercial quality, ultra-sharp detail, print-ready resolution,
    no watermarks, no logos from other brands, no extra fingers or limbs."

RULES:
R1. Output JSON only — no fences, no preamble, no trailing text.
R2. Keep all Vietnamese / non-English text that should appear IN the image exactly as provided.
R3. Total prompt length: 300-600 words. Short prompts = poor quality. Be specific and concrete.
R4. Ground visual brief on any KG bundle passages provided; cite them as [n] in the prompt text
    and list in references[]. If no KG bundle, leave references as [].
R5. NEVER include real celebrity names, copyrighted characters, NSFW, or political figures.
R6. NEVER add camera brand names or stock-photo watermarks.
R7. If a style preset is given, make it the dominant style directive in section 8.
R8. For product/branding images: section 5 (colour palette) and section 6 (typography) are
    the most important — do NOT skip them.
R9. DATA_FIELDS are FACTS — reproduce every name, date, number, brand name, certificate code,
    slogan verbatim. Do NOT paraphrase, omit, invent, or substitute any data value.
R10. Reference photos are for VISUAL APPEARANCE only (portrait features, product shape, colours).
     Do NOT use photos to guess or derive any text-based data (names, dates, brand text).
R11. When reference images are provided AND the output involves a person/portrait:
     Section 2 of the prompt MUST include the extracted appearance description (from Analysis C)
     followed by the phrase: "PORTRAIT REFERENCE: Use attached image [1] as the face reference —
     preserve ethnic features, skin tone, hair, and facial structure exactly."
     This ensures the generation model anchors its output to the actual reference photo.
IMG_SYSPROMPT;
	}

	private static function format_bundle_for_prompt( array $bundle ): string {
		$passages = $bundle['passages'] ?? [];
		if ( ! is_array( $passages ) || empty( $passages ) ) return '';
		$lines = [];
		$i = 0;
		foreach ( $passages as $p ) {
			$i++;
			$src = is_array( $p ) ? ( $p['source_title'] ?? $p['source'] ?? 'src' ) : 'src';
			$txt = is_array( $p ) ? ( $p['text'] ?? '' ) : (string) $p;
			$lines[] = sprintf( '[%d] (%s) %s', $i, $src, mb_substr( (string) $txt, 0, 280 ) );
			if ( $i >= 6 ) break;
		}
		return implode( "\n", $lines );
	}

	private static function extract_json( string $raw ): ?array {
		$raw = trim( $raw );
		// Strip markdown fences if any.
		if ( strpos( $raw, '```' ) !== false ) {
			$raw = preg_replace( '/```(?:json)?/i', '', $raw );
			$raw = trim( $raw, "` \n\r\t" );
		}
		$decoded = json_decode( $raw, true );
		if ( is_array( $decoded ) ) return $decoded;
		// Try to find first {...}.
		if ( preg_match( '/\{[\s\S]*\}/', $raw, $m ) ) {
			$decoded = json_decode( $m[0], true );
			if ( is_array( $decoded ) ) return $decoded;
		}
		return null;
	}

	/**
	 * Save image bytes to the uploads dir + create attachment.
	 *
	 * @return array{attachment_id:int,url:string,width:int,height:int,mime:string}|WP_Error
	 */
	private static function sideload_image( array $gen, int $doc_id, int $variant_index, string $prompt_text ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$upload = wp_upload_dir();
		if ( ! empty( $upload['error'] ) ) {
			return new \WP_Error( 'upload_dir_failed', $upload['error'] );
		}

		$basename = sprintf( 'bzdoc-img-%d-v%d-%s.png', $doc_id, $variant_index, wp_generate_password( 6, false, false ) );
		$tmp_file = trailingslashit( $upload['path'] ) . $basename;

		$bytes = '';
		if ( ! empty( $gen['b64_json'] ) ) {
			$bytes = base64_decode( (string) $gen['b64_json'], true );
			if ( $bytes === false ) {
				return new \WP_Error( 'decode_failed', 'Không decode được b64_json.' );
			}
		} elseif ( ! empty( $gen['image_url'] ) ) {
			$resp = wp_remote_get( (string) $gen['image_url'], [ 'timeout' => 60 ] );
			if ( is_wp_error( $resp ) ) return $resp;
			$bytes = wp_remote_retrieve_body( $resp );
			if ( empty( $bytes ) ) return new \WP_Error( 'download_failed', 'Tải ảnh từ URL thất bại.' );
		} else {
			return new \WP_Error( 'empty_payload', 'Router không trả về ảnh.' );
		}

		if ( false === file_put_contents( $tmp_file, $bytes ) ) {
			return new \WP_Error( 'write_failed', 'Không ghi được file ảnh.' );
		}

		$file_array = [
			'name'     => $basename,
			'tmp_name' => $tmp_file,
		];

		$att_id = media_handle_sideload( $file_array, 0, sprintf( 'BizCity Doc Image — doc %d variant %d', $doc_id, $variant_index ) );
		if ( is_wp_error( $att_id ) ) {
			@unlink( $tmp_file );
			return $att_id;
		}

		// Persist the prompt as attachment meta for traceability.
		update_post_meta( $att_id, '_bzdoc_image_prompt', wp_strip_all_tags( $prompt_text ) );
		update_post_meta( $att_id, '_bzdoc_image_doc_id', $doc_id );

		$meta = wp_get_attachment_metadata( $att_id );
		$url  = wp_get_attachment_url( $att_id );

		return [
			'attachment_id' => (int) $att_id,
			'url'           => (string) $url,
			'width'         => (int) ( $meta['width']  ?? 0 ),
			'height'        => (int) ( $meta['height'] ?? 0 ),
			'mime'          => (string) get_post_mime_type( $att_id ),
		];
	}

	private static function insert_job_row( int $doc_id, int $user_id, int $prompt_id, string $resolved, array $request, string $aspect, int $n ): int {
		global $wpdb;
		$wpdb->insert( BZDoc_Image_Prompts_Database::table_jobs(), [
			'doc_id'              => $doc_id,
			'user_id'             => $user_id,
			'prompt_template_id'  => $prompt_id,
			'resolved_prompt'     => $resolved,
			'arguments_json'      => wp_json_encode( $request['prompt_args'] ?? [] ),
			'status'              => 'running',
			'n_variants'          => $n,
			'aspect_ratio'        => $aspect,
			'model'               => 'gpt-image-1',
			'cost_estimate_cents' => $n * 17, // ~$0.17 per gpt-image-1 1024 std
			'created_at'          => current_time( 'mysql' ),
		] );
		return (int) $wpdb->insert_id;
	}

	private static function mark_job_complete( int $job_id, array $attachment_ids ): void {
		if ( ! $job_id ) return;
		global $wpdb;
		$wpdb->update( BZDoc_Image_Prompts_Database::table_jobs(), [
			'status'              => 'completed',
			'attachment_ids_json' => wp_json_encode( $attachment_ids ),
			'completed_at'        => current_time( 'mysql' ),
		], [ 'id' => $job_id ] );
	}

	private static function mark_job_failed( int $job_id, string $error ): void {
		if ( ! $job_id ) return;
		global $wpdb;
		$wpdb->update( BZDoc_Image_Prompts_Database::table_jobs(), [
			'status'        => 'failed',
			'error_message' => $error,
			'completed_at'  => current_time( 'mysql' ),
		], [ 'id' => $job_id ] );
	}

	/**
	 * Heartbeat from the streaming write-callback. Stored as a transient (1h
	 * TTL) keyed by doc_id — the polling endpoint already has doc_id, so this
	 * avoids a join back to the jobs table for every poll.
	 */
	private static function touch_job_heartbeat( int $doc_id, int $variant_index, string $event_type ): void {
		if ( ! $doc_id ) return;
		set_transient(
			'bzdoc_imgjob_hb_' . $doc_id,
			[ 'ts' => time(), 'variant' => $variant_index, 'event' => $event_type ],
			HOUR_IN_SECONDS
		);
	}

	/**
	 * Public read accessor for the streaming heartbeat — used by the polling
	 * status endpoint to surface "still working" vs "stalled".
	 *
	 * @return array{ts:int,variant:int,event:string}|null
	 */
	public static function get_job_heartbeat( int $doc_id ): ?array {
		if ( ! $doc_id ) return null;
		$hb = get_transient( 'bzdoc_imgjob_hb_' . $doc_id );
		return is_array( $hb ) ? $hb : null;
	}

	/**
	 * Edit an existing variant — sends the source image + a natural-language
	 * instruction back to the image model, which returns a refined version.
	 *
	 * Expected $request keys:
	 *   parent_variant_index (int, required) — index in current variants[]
	 *   instruction          (string, required) — "thêm cây dù vàng", "ấm hơn"…
	 *   user_id              (int, default current)
	 *
	 * Returns the same shape as `run()` — the new edited variant is appended
	 * to existing variants[] (NOT replacing) so the user keeps lineage.
	 */
	public static function edit_variant( int $doc_id, array $request ) {
		$user_id     = (int) ( $request['user_id'] ?? get_current_user_id() );
		$instruction = trim( (string) ( $request['instruction'] ?? '' ) );
		$parent_idx  = (int) ( $request['parent_variant_index'] ?? -1 );

		if ( $instruction === '' ) {
			return new \WP_Error( 'missing_instruction', 'Cần mô tả chỉnh sửa.' );
		}

		// 1. Load current schema_json to find the parent variant URL.
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT schema_json FROM {$wpdb->prefix}bzdoc_documents WHERE id = %d",
			$doc_id
		) );
		if ( ! $row ) {
			return new \WP_Error( 'doc_not_found', 'Doc không tồn tại.' );
		}
		$schema   = json_decode( (string) $row->schema_json, true ) ?: [];
		$variants = (array) ( $schema['variants'] ?? [] );
		if ( $parent_idx < 0 || $parent_idx >= count( $variants ) || empty( $variants[ $parent_idx ]['url'] ) ) {
			return new \WP_Error( 'parent_not_found', 'Không tìm thấy ảnh nguồn.' );
		}
		$parent          = $variants[ $parent_idx ];
		$parent_url      = (string) $parent['url'];
		$parent_prompt   = (string) ( $parent['prompt'] ?? $schema['final_prompt'] ?? '' );
		$aspect          = (string) ( $schema['aspect_ratio'] ?? '1:1' );
		if ( ! isset( self::SIZE_MAP[ $aspect ] ) ) $aspect = '1:1';
		$size            = self::SIZE_MAP[ $aspect ];

		// 2. Compose final edit prompt (parent prompt + instruction).
		//    Strong identity-preservation directive prevents the model from
		//    re-imagining the subject (product label, face, brand wordmark)
		//    when applying stylistic tweaks like "chiếu sáng/bóng bẩy hơn".
		$has_original_refs = ! empty( $schema['reference_images'] ) && is_array( $schema['reference_images'] );
		$identity_clause   = $has_original_refs
			? "IDENTITY LOCK — the additional reference images attached are the user's ORIGINAL brand assets (product / portrait). The previously generated image is attached for layout context only. You MUST keep the subject visually IDENTICAL to the original references: exact label artwork, brand wordmark spelling, product silhouette, colour, proportions, facial features. Do NOT redesign, restyle, replace, or invent a new product / person. Only modify the aspects requested in the edit instruction."
			: "IDENTITY LOCK — the attached image is the source. You MUST keep the main subject (product, label text, brand wordmark, person's face) visually IDENTICAL. Only modify what the edit instruction asks for. Do NOT redesign or invent a new variation of the product / subject.";

		$edit_prompt = "Original image prompt:\n" . $parent_prompt . "\n\n"
			. "Edit instruction:\n" . $instruction . "\n\n"
			. $identity_clause . "\n\n"
			. "Apply the requested edit while preserving everything else — framing, layout, all text content, all logos, certifications, and the subject's exact identity.";

		// 3. Persist a job row for audit.
		$job_id = self::insert_job_row( $doc_id, $user_id, 0, $edit_prompt, $request, $aspect, 1 );

		// 4. Resolve image to a publicly fetchable URL OR data URI. OpenRouter
		//    needs to GET the URL — if our WP is behind auth/firewall we fall
		//    back to base64 inline. Try URL first, fallback to base64.
		$image_payload = self::prepare_image_for_upstream( $parent_url );
		if ( is_wp_error( $image_payload ) ) {
			self::mark_job_failed( $job_id, $image_payload->get_error_message() );
			return $image_payload;
		}

		// 4b. Re-attach the user's ORIGINAL reference images so the model has
		//     an anchor for the true subject identity even if the parent
		//     image already drifted slightly. Order matters for OpenRouter:
		//     parent image FIRST (the canvas to edit), then originals (the
		//     ground-truth references). Cap at 4 total to keep request size
		//     reasonable.
		$input_images = [ $image_payload ];
		if ( $has_original_refs ) {
			foreach ( array_slice( (array) $schema['reference_images'], 0, 3 ) as $ref ) {
				if ( is_string( $ref ) && $ref !== '' ) {
					$input_images[] = $ref;
				}
			}
		}

		// 5. Call gateway via BizCity_LLM_Client (R-GW-8 — client KHÔNG có Router_Proxy).
		if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
			self::mark_job_failed( $job_id, 'llm_client_unavailable' );
			return new \WP_Error( 'llm_client_unavailable', 'BizCity LLM client chưa load.' );
		}
		$llm_edit = BizCity_LLM_Client::instance();
		if ( ! $llm_edit->is_ready() ) {
			self::mark_job_failed( $job_id, 'api_key_missing' );
			return new \WP_Error( 'api_key_missing', 'BizCity API key chưa cấu hình.' );
		}

		$image_model = apply_filters( 'bzdoc_image_model', 'openai/gpt-5.4-image-2', $doc_id, $request );

		self::touch_job_heartbeat( $doc_id, 999, 'edit-start' );

		$gen = $llm_edit->generate_image( $edit_prompt, [
			'model'        => $image_model,
			'size'         => $size,
			'n'            => 1,
			'timeout'      => 600,
			'input_images' => $input_images,
			'stream'       => true,
		] );

		if ( empty( $gen['success'] ) ) {
			self::mark_job_failed( $job_id, $gen['error'] ?? 'image_edit_failed' );
			return new \WP_Error( 'image_edit_failed', $gen['error'] ?? 'Edit ảnh thất bại.' );
		}

		// 6. Sideload the new variant + append to schema.
		$next_idx = count( $variants );
		$att = self::sideload_image( $gen, $doc_id, $next_idx, $edit_prompt );
		if ( is_wp_error( $att ) ) {
			self::mark_job_failed( $job_id, $att->get_error_message() );
			return $att;
		}

		$new_variant = [
			'index'                => $next_idx,
			'attachment_id'        => $att['attachment_id'],
			'url'                  => $att['url'],
			'width'                => $att['width'],
			'height'               => $att['height'],
			'mime'                 => $att['mime'],
			'prompt'               => $edit_prompt,
			'parent_variant_index' => $parent_idx,
			'edit_instruction'     => $instruction,
			'citations'            => [],
		];
		$variants[] = $new_variant;

		// Federation stamp.
		if ( class_exists( 'BizCity_Artifact_Source_Federation' ) ) {
			BizCity_Artifact_Source_Federation::stamp(
				'bizcity-doc-image',
				(int) $att['attachment_id'],
				$doc_id,
				sprintf( 'Image edit · v%d', $next_idx + 1 ),
				$att['url']
			);
		}

		self::mark_job_complete( $job_id, [ (int) $att['attachment_id'] ] );

		return [
			'doc_id'       => $doc_id,
			'job_id'       => $job_id,
			'aspect_ratio' => $aspect,
			'n_variants'   => count( $variants ),
			'final_prompt' => $edit_prompt,
			'citations'    => (array) ( $schema['citations'] ?? [] ),
			'variants'     => $variants,
		];
	}

	/**
	 * Make an image safely fetchable by OpenRouter. Returns either:
	 *   - the original URL if it looks publicly reachable, OR
	 *   - a `data:image/<mime>;base64,...` URI if we need to inline it.
	 *
	 * Heuristic: we ALWAYS inline as base64 to avoid OpenRouter failing on
	 * private/dev URLs, auth-protected media, or hosts behind Cloudflare
	 * Access. Cost: extra ~500KB-2MB per request. Trade-off accepted.
	 */
	private static function prepare_image_for_upstream( string $url ) {
		// Try local filesystem read first (fast path for same-host attachments).
		$path = self::url_to_local_path( $url );
		$bytes = '';
		$mime  = 'image/png';
		if ( $path && file_exists( $path ) ) {
			$bytes = (string) file_get_contents( $path );
			$mime  = wp_check_filetype( $path )['type'] ?? 'image/png';
		} else {
			// Remote fetch fallback.
			$resp = wp_remote_get( $url, [ 'timeout' => 30 ] );
			if ( is_wp_error( $resp ) ) {
				return new \WP_Error( 'image_fetch_failed', 'Không tải được ảnh nguồn: ' . $resp->get_error_message() );
			}
			$bytes = (string) wp_remote_retrieve_body( $resp );
			$ct    = (string) wp_remote_retrieve_header( $resp, 'content-type' );
			if ( $ct ) $mime = $ct;
		}
		if ( $bytes === '' ) {
			return new \WP_Error( 'image_empty', 'Ảnh nguồn rỗng.' );
		}
		return 'data:' . $mime . ';base64,' . base64_encode( $bytes );
	}

	private static function url_to_local_path( string $url ): ?string {
		$upload = wp_upload_dir();
		if ( empty( $upload['baseurl'] ) || empty( $upload['basedir'] ) ) return null;
		if ( strpos( $url, $upload['baseurl'] ) !== 0 ) return null;
		return $upload['basedir'] . substr( $url, strlen( $upload['baseurl'] ) );
	}
}
