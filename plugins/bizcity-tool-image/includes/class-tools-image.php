<?php
/**
 * BizCity Tool Image — Tool Callbacks
 *
 * Tạo ảnh AI bằng FLUX.2, Gemini, Seedream, GPT-5 Image.
 * Lưu lịch sử tạo ảnh, hỗ trợ pipeline output image_url.
 *
 * @package BizCity_Tool_Image
 * @since   2.0.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class BizCity_Tool_Image {

    /** Supported models — OpenRouter model IDs */
    const MODELS = [
        'flux-pro'     => [ 'label' => 'FLUX.2 Pro',          'provider' => 'openrouter', 'model_id' => 'black-forest-labs/flux.2-pro'              ],
        'flux-flex'    => [ 'label' => 'FLUX.2 Flex',         'provider' => 'openrouter', 'model_id' => 'black-forest-labs/flux.2-flex'             ],
        'flux-max'     => [ 'label' => 'FLUX.2 Max',          'provider' => 'openrouter', 'model_id' => 'black-forest-labs/flux.2-max'              ],
        'flux-klein'   => [ 'label' => 'FLUX.2 Klein (fast)', 'provider' => 'openrouter', 'model_id' => 'black-forest-labs/flux.2-klein-4b'         ],
        'gemini-image' => [ 'label' => 'Gemini Flash Image',  'provider' => 'openrouter', 'model_id' => 'google/gemini-2.5-flash-image'             ],
        'gemini-pro'   => [ 'label' => 'Gemini Pro Image',    'provider' => 'openrouter', 'model_id' => 'google/gemini-3-pro-image-preview'         ],
        'seedream'     => [ 'label' => 'Seedream 4.5',        'provider' => 'openrouter', 'model_id' => 'bytedance-seed/seedream-4.5'               ],
        'gpt-image'    => [ 'label' => 'GPT-5 Image',         'provider' => 'openrouter', 'model_id' => 'openai/gpt-5-image'                       ],
        'gpt-image-mini' => [ 'label' => 'GPT-5 Image Mini',  'provider' => 'openrouter', 'model_id' => 'openai/gpt-5-image-mini'                  ],
    ];

    /** Map pixel sizes → OpenRouter aspect_ratio */
    const SIZE_TO_ASPECT = [
        '1024x1024' => '1:1',
        '1024x1536' => '2:3',
        '1536x1024' => '3:2',
        '768x1344'  => '9:16',
        '1344x768'  => '16:9',
        '1024x1792' => '9:16',
        '1792x1024' => '16:9',
        '512x512'   => '1:1',
    ];

    /** Log prefix */
    private static function log( string $message, $data = null ): void {
        $entry = '[BizCity Tool Image] ' . $message;
        if ( $data !== null ) {
            $entry .= ' | ' . ( is_string( $data ) ? $data : wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
        }
        error_log( $entry );
    }

    /** Valid sizes */
    const VALID_SIZES = [
        '1024x1024', '1024x1536', '1536x1024', '768x1344', '1344x768',
        '1024x1792', '1792x1024', '512x512',
    ];

    const PLUGIN_LABEL = 'Image AI';

    /* ══════════════════════════════════════════════════════
     *  PRIMARY TOOL: generate_image
     * ══════════════════════════════════════════════════════ */
    public static function generate_image( array $slots ): array {
        $user_id = $slots['user_id'] ?? get_current_user_id();
        $meta    = $slots['_meta'] ?? [];

        if ( ! $user_id ) {
            return [
                'success' => false, 'complete' => true,
                'message' => '⚠️ Bạn cần đăng nhập để tạo ảnh.',
                'data'    => [],
            ];
        }

        // ── Step 1: Check creation_mode ──
        $creation_mode = sanitize_text_field( $slots['creation_mode'] ?? '' );

        if ( empty( $creation_mode ) ) {
            return [
                'success'        => false,
                'complete'       => false,
                'message'        => "Bạn muốn tạo ảnh theo cách nào?\n\n1️⃣ **Từ ảnh cảm hứng** — Gửi 1 hoặc nhiều ảnh mẫu, AI sẽ tạo ảnh mới dựa trên phong cách/nội dung ảnh đó.\n2️⃣ **Từ ý tưởng** — Mô tả chi tiết ý tưởng bằng text, AI sẽ tạo ảnh từ đầu.",
                'missing_fields' => [ 'creation_mode' ],
                'data'           => [],
            ];
        }

        $prompt    = self::resolve_prompt( $slots );
        $image_url = esc_url_raw( $slots['image_url'] ?? '' );
        $model     = sanitize_text_field( $slots['model'] ?? get_option( 'bztimg_default_model', 'flux-pro' ) );
        $size      = sanitize_text_field( $slots['size'] ?? get_option( 'bztimg_default_size', '1024x1024' ) );
        $style     = sanitize_text_field( $slots['style'] ?? 'auto' );

        // ── Step 2: Mode-specific validation ──
        if ( $creation_mode === 'reference' ) {
            // Reference mode: must have image URL(s)
            if ( empty( $image_url ) ) {
                return [
                    'success'        => false,
                    'complete'       => false,
                    'message'        => "📸 Vui lòng gửi link ảnh tham chiếu (1 hoặc nhiều ảnh).\nBạn có thể gửi URL ảnh hoặc upload ảnh trực tiếp.",
                    'missing_fields' => [ 'image_url' ],
                    'data'           => [],
                ];
            }
            // Default prompt for reference mode if user didn't provide extra instructions
            if ( empty( $prompt ) ) {
                $prompt = 'Create a professional high-quality image inspired by the reference image, maintaining the style and composition';
            }
        } else {
            // Text mode: must have detailed prompt
            if ( empty( $prompt ) ) {
                return [
                    'success'        => false,
                    'complete'       => false,
                    'message'        => "✍️ Hãy mô tả chi tiết ý tưởng ảnh của bạn:\n• Chủ đề chính là gì?\n• Bối cảnh/không gian?\n• Tông màu/ánh sáng?\n• Phong cách?\n• Mục đích sử dụng?",
                    'missing_fields' => [ 'prompt' ],
                    'data'           => [],
                ];
            }
        }

        // ── Auto-detect purpose & enhance prompt ──
        $purpose = sanitize_text_field( $slots['purpose'] ?? '' );
        if ( empty( $purpose ) && class_exists( 'BizCity_Tool_Image_Intent_Provider' ) ) {
            $purpose = BizCity_Tool_Image_Intent_Provider::detect_purpose( $prompt ) ?? '';
        }
        if ( ! empty( $purpose ) && class_exists( 'BizCity_Tool_Image_Intent_Provider' ) ) {
            $prefix = BizCity_Tool_Image_Intent_Provider::get_purpose_prefix( $purpose );
            if ( $prefix ) {
                $prompt = $prefix . $prompt;
            }
        }

        if ( ! array_key_exists( $model, self::MODELS ) ) {
            $model = 'flux-pro';
        }
        if ( ! in_array( $size, self::VALID_SIZES, true ) ) {
            $size = '1024x1024';
        }

        // Apply style modifiers to prompt
        $enhanced_prompt = self::apply_style( $prompt, $style );

        // Create job record in DB
        $job_id = self::create_job( $user_id, $enhanced_prompt, $model, $size, $style, $image_url, $meta );

        // Call API
        self::log( 'generate_image', [
            'model' => $model, 'size' => $size, 'style' => $style,
            'prompt_len' => mb_strlen( $enhanced_prompt ),
            'has_ref' => ! empty( $image_url ),
        ] );

        $result = self::call_image_api( $model, $enhanced_prompt, $size, $image_url );

        if ( ! $result['success'] ) {
            self::log( 'generate_image FAILED', $result['error'] ?? 'Unknown' );
            self::update_job_status( $job_id, 'failed', $result['error'] ?? 'API Error' );
            return [
                'success' => false, 'complete' => true,
                'message' => '❌ Lỗi tạo ảnh: ' . ( $result['error'] ?? 'Unknown error' ),
                'data'    => [ 'job_id' => $job_id ],
            ];
        }

        $generated_url = $result['image_url'];

        // Auto-save to Media Library (skip if already saved from base64 in call_openrouter)
        $attachment_id = null;
        $saved_url     = $generated_url;
        if ( strpos( $generated_url, home_url() ) !== false || strpos( $generated_url, 'media.bizcity.vn' ) !== false ) {
            // Already in Media Library (saved from base64) — just find the attachment ID
            $attachment_id = attachment_url_to_postid( $generated_url );
            $saved_url     = $generated_url;
        } else {
            $attachment_id = self::save_to_media( $generated_url, $prompt );
            $saved_url     = $attachment_id ? wp_get_attachment_url( $attachment_id ) : $generated_url;
        }

        // Update job as completed
        self::update_job_completed( $job_id, $saved_url, $attachment_id );

        // Build response
        $model_label = self::MODELS[ $model ]['label'] ?? $model;
        $purpose_labels = [
            'product' => '📦 Sản phẩm', 'portrait' => '👤 Chân dung', 'landscape' => '🌄 Phong cảnh',
            'social' => '📱 Social Media', 'food' => '🍜 Ẩm thực',
        ];
        $msg  = "🎨 **Đã tạo ảnh thành công!**\n\n";
        $msg .= "🤖 **Model:** {$model_label}\n";
        $msg .= "📐 **Kích thước:** {$size}\n";
        if ( ! empty( $purpose ) && isset( $purpose_labels[ $purpose ] ) ) {
            $msg .= "🎯 **Mục đích:** {$purpose_labels[ $purpose ]}\n";
        }
        if ( $style !== 'auto' ) {
            $msg .= "🎭 **Phong cách:** {$style}\n";
        }
        if ( $attachment_id ) {
            $msg .= "💾 **Đã lưu Media Library** (ID: #{$attachment_id})\n";
        }
        $msg .= "\n🔗 {$saved_url}";

        // Chat notification
        self::notify_chat( $user_id, $job_id, $saved_url, $meta );

        return [
            'success'  => true,
            'complete' => true,
            'message'  => $msg,
            'data'     => [
                'type'          => 'image',
                'image_url'     => $saved_url,
                'url'           => $saved_url,
                'attachment_id' => $attachment_id,
                'job_id'        => $job_id,
                'model'         => $model,
                'size'          => $size,
                'prompt'        => $prompt,
            ],
        ];
    }

    /* ══════════════════════════════════════════════════════
     *  SECONDARY TOOL: generate_product_image
     * ══════════════════════════════════════════════════════ */
    public static function generate_product_image( array $slots ): array {
        $style = $slots['style'] ?? 'ecommerce';
        $product_name = $slots['product_name'] ?? '';

        // Style prefixes for product photography
        $style_prefixes = [
            'ecommerce' => 'High-end commercial product photography, place the product in a beautifully styled environment with complementary props, professional studio lighting with soft shadows, clean sharp focus, luxury brand aesthetic, ',
            'lifestyle'  => 'Lifestyle product photography, place the product in a natural curated scene with warm ambient lighting, editorial storytelling composition, aspirational setting with depth and texture, ',
            'flat-lay'   => 'Elegant flat lay product arrangement, top-down view, styled surface with complementary objects and textures, organized artistic layout, soft even lighting, ',
            'studio'     => 'Premium studio product photography, dramatic rim lighting, dark moody background with subtle gradients, hero shot composition, cinematic depth of field, luxury feel, ',
        ];

        $prefix = $style_prefixes[ $style ] ?? $style_prefixes['ecommerce'];
        $prompt = $slots['prompt'] ?? $product_name ?? '';

        if ( empty( $prompt ) ) {
            return [
                'success'        => false,
                'complete'       => false,
                'message'        => '📦 Mô tả sản phẩm cần tạo ảnh (tên, đặc điểm, kiểu dáng):',
                'missing_fields' => [ 'prompt' ],
                'data'           => [],
            ];
        }

        $slots['prompt'] = $prefix . $prompt;
        $slots['model']  = $slots['model'] ?? 'flux-pro';
        $slots['size']   = $slots['size'] ?? '1024x1024';
        $slots['style']  = 'auto'; // already applied via prefix

        return self::generate_image( $slots );
    }

    /* ══════════════════════════════════════════════════════
     *  SECONDARY TOOL: list_my_images
     * ══════════════════════════════════════════════════════ */
    public static function list_my_images( array $slots ): array {
        $user_id = $slots['user_id'] ?? get_current_user_id();
        if ( ! $user_id ) {
            return [
                'success' => false, 'complete' => true,
                'message' => '⚠️ Bạn cần đăng nhập.',
                'data'    => [],
            ];
        }

        global $wpdb;
        $table = $wpdb->prefix . 'bztimg_jobs';
        if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) ) !== $table ) {
            return [
                'success' => true, 'complete' => true,
                'message' => '📭 Chưa có ảnh nào.',
                'data'    => [ 'items' => [] ],
            ];
        }

        $limit = min( max( (int) ( $slots['limit'] ?? 10 ), 1 ), 50 );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, prompt, model, size, style, status, image_url, attachment_id, created_at
             FROM {$table} WHERE user_id = %d ORDER BY created_at DESC LIMIT %d",
            $user_id, $limit
        ), ARRAY_A );

        if ( empty( $rows ) ) {
            return [
                'success' => true, 'complete' => true,
                'message' => '📭 Chưa tạo ảnh AI nào.',
                'data'    => [ 'items' => [] ],
            ];
        }

        $lines = [ "🖼️ **Ảnh AI đã tạo** ({$limit} gần nhất):\n" ];
        foreach ( $rows as $i => $row ) {
            $idx    = $i + 1;
            $st     = $row['status'] === 'completed' ? '✅' : ( $row['status'] === 'failed' ? '❌' : '⏳' );
            $date   = wp_date( 'd/m H:i', strtotime( $row['created_at'] ) );
            $prompt = mb_strimwidth( $row['prompt'], 0, 60, '...' );
            $lines[] = "{$idx}. {$st} {$prompt} — *{$row['model']}* ({$date})";
        }

        return [
            'success' => true, 'complete' => true,
            'message' => implode( "\n", $lines ),
            'data'    => [ 'type' => 'image_list', 'items' => $rows ],
        ];
    }

    /* ══════════════════════════════════════════════════════
     *  API CALLS
     * ══════════════════════════════════════════════════════ */

    private static function call_image_api( string $model, string $prompt, string $size, string $ref_image = '' ): array {
        $model_info = self::MODELS[ $model ] ?? self::MODELS['flux-pro'];

        if ( $model_info['provider'] === 'openai' ) {
            return self::call_openai( $prompt, $size );
        }

        return self::call_openrouter( $model_info['model_id'], $prompt, $size, $ref_image );
    }

    /**
     * OpenRouter Gateway — FLUX.2, Gemini, Seedream, GPT-5 Image, etc.
     *
     * @see https://openrouter.ai/docs/guides/overview/multimodal/image-generation
     *
     * Endpoint:  POST /api/v1/chat/completions
     * Body:      model, messages, modalities: ["image"] or ["image","text"], image_config
     * Response:  choices[0].message.images[0].image_url.url  (base64 data URL)
     */
    private static function call_openrouter( string $model_id, string $prompt, string $size, string $ref_image = '' ): array {

        /* ── Resolve API key ── */
        $api_key = get_option( 'bztimg_api_key', '' );
        if ( empty( $api_key ) && function_exists( 'bizcity_openrouter_get_api_key' ) ) {
            $api_key = bizcity_openrouter_get_api_key();
        }
        if ( empty( $api_key ) ) {
            $api_key = get_site_option( 'bizcity_openrouter_api_key', '' );
        }
        if ( empty( $api_key ) ) {
            self::log( 'call_openrouter: No API key configured' );
            return [ 'success' => false, 'error' => 'Chưa cấu hình API Key. Vào Settings hoặc cấu hình OpenRouter.' ];
        }

        /* ── Endpoint ── */
        $endpoint = rtrim( get_option( 'bztimg_api_endpoint', 'https://openrouter.ai/api/v1' ), '/' );

        // Auto-fix stale PiAPI endpoint
        if ( strpos( $endpoint, 'piapi.ai' ) !== false ) {
            $endpoint = 'https://openrouter.ai/api/v1';
            update_option( 'bztimg_api_endpoint', $endpoint );
            self::log( 'call_openrouter: auto-migrated endpoint from PiAPI to OpenRouter' );
        }

        $url      = $endpoint . '/chat/completions';

        /* ── Build messages ── */
        $content = [];
        $content[] = [ 'type' => 'text', 'text' => $prompt ];

        // If reference image → add as image_url in messages (img2img)
        if ( ! empty( $ref_image ) ) {
            $content[] = [
                'type'      => 'image_url',
                'image_url' => [ 'url' => $ref_image ],
            ];
        }

        $messages = [
            [
                'role'    => 'user',
                'content' => $content,
            ],
        ];

        /* ── image_config: aspect_ratio ── */
        $aspect_ratio = self::SIZE_TO_ASPECT[ $size ] ?? '1:1';

        /* ── Body ── */
        $body = [
            'model'      => $model_id,
            'messages'   => $messages,
            'modalities' => [ 'image' ],
            'stream'     => false,
            'image_config' => [
                'aspect_ratio' => $aspect_ratio,
            ],
        ];

        self::log( 'call_openrouter REQUEST', [
            'url'   => $url,
            'model' => $model_id,
            'aspect' => $aspect_ratio,
            'has_ref' => ! empty( $ref_image ),
        ] );

        /* ── HTTP request ── */
        $response = wp_remote_post( $url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
                'HTTP-Referer'  => home_url(),
                'X-Title'       => 'BizCity Tool Image',
            ],
            'body'    => wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
            'timeout' => 180,
        ] );

        if ( is_wp_error( $response ) ) {
            self::log( 'call_openrouter WP_ERROR', $response->get_error_message() );
            return [ 'success' => false, 'error' => 'Lỗi kết nối: ' . $response->get_error_message() ];
        }

        $http_code = wp_remote_retrieve_response_code( $response );
        $raw_body  = wp_remote_retrieve_body( $response );
        $data      = json_decode( $raw_body, true );

        self::log( 'call_openrouter RESPONSE', [
            'http_code'  => $http_code,
            'has_data'   => ! empty( $data ),
            'has_choices' => ! empty( $data['choices'] ),
            'error'      => $data['error'] ?? null,
        ] );

        /* ── Error handling ── */
        if ( $http_code !== 200 || empty( $data ) ) {
            $err_msg = 'HTTP ' . $http_code;
            if ( ! empty( $data['error']['message'] ) ) {
                $err_msg = $data['error']['message'];
            } elseif ( ! empty( $data['error'] ) && is_string( $data['error'] ) ) {
                $err_msg = $data['error'];
            }
            self::log( 'call_openrouter ERROR', $err_msg );
            return [ 'success' => false, 'error' => $err_msg ];
        }

        /* ── Extract image from response ── */
        $image_url = self::extract_openrouter_image( $data );

        if ( empty( $image_url ) ) {
            // Log full response for debugging
            self::log( 'call_openrouter NO IMAGE in response', mb_substr( $raw_body, 0, 2000 ) );
            return [ 'success' => false, 'error' => 'API phản hồi nhưng không có ảnh. Kiểm tra model có hỗ trợ image generation.' ];
        }

        /* ── If base64 data URL → save to WP uploads ── */
        if ( strpos( $image_url, 'data:image/' ) === 0 ) {
            self::log( 'call_openrouter: base64 image received, saving to media' );
            $saved_url = self::save_base64_data_url_to_media( $image_url );
            if ( $saved_url ) {
                return [ 'success' => true, 'image_url' => $saved_url ];
            }
            return [ 'success' => false, 'error' => 'Nhận được ảnh base64 nhưng không lưu được vào Media.' ];
        }

        return [ 'success' => true, 'image_url' => $image_url ];
    }

    /**
     * Extract image URL/base64 from OpenRouter response.
     *
     * Response format:
     * choices[0].message.images[0].image_url.url  → base64 data URL or http URL
     */
    private static function extract_openrouter_image( array $data ): string {
        // Primary: choices[0].message.images[0].image_url.url
        if ( ! empty( $data['choices'][0]['message']['images'] ) ) {
            foreach ( $data['choices'][0]['message']['images'] as $img ) {
                if ( ! empty( $img['image_url']['url'] ) ) {
                    return $img['image_url']['url'];
                }
                // Alternative: direct url
                if ( ! empty( $img['url'] ) ) {
                    return $img['url'];
                }
            }
        }
        // Fallback: choices[0].message.content may contain inline image
        $content = $data['choices'][0]['message']['content'] ?? '';
        if ( is_string( $content ) && preg_match( '/https?:\/\/\S+\.(?:png|jpg|jpeg|webp)/i', $content, $m ) ) {
            return $m[0];
        }
        return '';
    }

    /**
     * Save a base64 data URL (data:image/png;base64,...) to WP Media Library.
     */
    private static function save_base64_data_url_to_media( string $data_url ): ?string {
        // Parse: data:image/png;base64,iVBOR...
        if ( ! preg_match( '/^data:image\/(\w+);base64,(.+)$/s', $data_url, $m ) ) {
            self::log( 'save_base64_data_url: invalid format' );
            return null;
        }

        $ext  = $m[1] === 'jpeg' ? 'jpg' : $m[1]; // png, jpg, webp
        $raw  = base64_decode( $m[2] );

        if ( ! $raw || strlen( $raw ) < 100 ) {
            self::log( 'save_base64_data_url: decode failed or too small' );
            return null;
        }

        $filename = 'ai-image-' . time() . '-' . wp_rand( 100, 999 ) . '.' . $ext;
        $upload   = wp_upload_bits( $filename, null, $raw );

        if ( ! empty( $upload['error'] ) ) {
            self::log( 'save_base64_data_url: wp_upload_bits error', $upload['error'] );
            return null;
        }

        $file_type  = wp_check_filetype( $upload['file'] );
        $attachment = [
            'post_mime_type' => $file_type['type'],
            'post_title'     => 'AI Generated Image',
            'post_status'    => 'inherit',
        ];

        $att_id = wp_insert_attachment( $attachment, $upload['file'] );
        if ( is_wp_error( $att_id ) ) {
            self::log( 'save_base64_data_url: wp_insert_attachment failed', $att_id->get_error_message() );
            return null;
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        wp_update_attachment_metadata( $att_id, wp_generate_attachment_metadata( $att_id, $upload['file'] ) );

        $url = wp_get_attachment_url( $att_id );
        self::log( 'save_base64_data_url: saved', [ 'att_id' => $att_id, 'url' => $url ] );

        return $url;
    }

    /**
     * OpenAI GPT-Image / DALL-E (direct, not via OpenRouter).
     */
    private static function call_openai( string $prompt, string $size ): array {
        $api_key = get_option( 'bztimg_openai_key', '' );
        if ( empty( $api_key ) ) {
            $api_key = get_option( 'twf_openai_api_key', '' );
        }
        if ( empty( $api_key ) ) {
            self::log( 'call_openai: No API key configured' );
            return [ 'success' => false, 'error' => 'Chưa cấu hình OpenAI API Key.' ];
        }

        // Map sizes to OpenAI supported
        $openai_sizes = [ '1024x1024', '1024x1792', '1792x1024' ];
        if ( ! in_array( $size, $openai_sizes, true ) ) {
            $size = '1024x1024';
        }

        $body = [
            'model'  => 'gpt-image-1',
            'prompt' => $prompt,
            'n'      => 1,
            'size'   => $size,
        ];

        self::log( 'call_openai REQUEST', [ 'model' => 'gpt-image-1', 'size' => $size ] );

        $response = wp_remote_post( 'https://api.openai.com/v1/images/generations', [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode( $body ),
            'timeout' => 120,
        ] );

        if ( is_wp_error( $response ) ) {
            self::log( 'call_openai WP_ERROR', $response->get_error_message() );
            return [ 'success' => false, 'error' => 'Lỗi kết nối: ' . $response->get_error_message() ];
        }

        $http_code = wp_remote_retrieve_response_code( $response );
        $data      = json_decode( wp_remote_retrieve_body( $response ), true );

        self::log( 'call_openai RESPONSE', [ 'http_code' => $http_code ] );

        if ( ! empty( $data['data'][0]['url'] ) ) {
            return [ 'success' => true, 'image_url' => $data['data'][0]['url'] ];
        }

        // Base64 response
        if ( ! empty( $data['data'][0]['b64_json'] ) ) {
            $url = self::save_base64_data_url_to_media( 'data:image/png;base64,' . $data['data'][0]['b64_json'] );
            if ( $url ) {
                return [ 'success' => true, 'image_url' => $url ];
            }
        }

        $err = $data['error']['message'] ?? 'OpenAI không phản hồi. HTTP ' . $http_code;
        self::log( 'call_openai ERROR', $err );
        return [ 'success' => false, 'error' => $err ];
    }

    /* ══════════════════════════════════════════════════════
     *  DB HELPERS
     * ══════════════════════════════════════════════════════ */

    private static function create_job( int $user_id, string $prompt, string $model, string $size, string $style, string $ref_image, array $meta ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'bztimg_jobs';

        $wpdb->insert( $table, [
            'user_id'     => $user_id,
            'prompt'      => mb_substr( $prompt, 0, 1000 ),
            'model'       => $model,
            'size'        => $size,
            'style'       => $style,
            'ref_image'   => $ref_image,
            'status'      => 'processing',
            'session_id'  => $meta['session_id'] ?? '',
            'chat_id'     => $meta['message_id'] ?? '',
            'created_at'  => current_time( 'mysql' ),
        ], [ '%d','%s','%s','%s','%s','%s','%s','%s','%s','%s' ] );

        return (int) $wpdb->insert_id;
    }

    private static function update_job_status( int $job_id, string $status, string $error = '' ): void {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'bztimg_jobs',
            [ 'status' => $status, 'error_message' => $error, 'updated_at' => current_time( 'mysql' ) ],
            [ 'id' => $job_id ]
        );
    }

    private static function update_job_completed( int $job_id, string $image_url, ?int $attachment_id ): void {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'bztimg_jobs',
            [
                'status'        => 'completed',
                'image_url'     => $image_url,
                'attachment_id' => $attachment_id ?: 0,
                'updated_at'    => current_time( 'mysql' ),
            ],
            [ 'id' => $job_id ]
        );
    }

    /* ══════════════════════════════════════════════════════
     *  MEDIA HELPERS
     * ══════════════════════════════════════════════════════ */

    public static function save_to_media( string $url, string $title = '' ): ?int {
        if ( function_exists( 'twf_upload_image_to_media_library' ) ) {
            $att_id = twf_upload_image_to_media_library( $url, sanitize_title( $title ?: 'ai-image' ) );
            if ( $att_id && ! is_wp_error( $att_id ) ) return (int) $att_id;
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';

        $tmp = download_url( $url );
        if ( is_wp_error( $tmp ) ) return null;

        $file_array = [
            'name'     => 'ai-image-' . time() . '.png',
            'tmp_name' => $tmp,
        ];

        $attachment_id = media_handle_sideload( $file_array, 0, $title ?: 'AI Generated Image' );
        @unlink( $tmp );

        return is_wp_error( $attachment_id ) ? null : (int) $attachment_id;
    }

    // save_base64_to_media removed — replaced by save_base64_data_url_to_media above

    /* ══════════════════════════════════════════════════════
     *  PROMPT HELPERS
     * ══════════════════════════════════════════════════════ */

    private static function resolve_prompt( array $slots ): string {
        foreach ( [ 'prompt', 'message', 'content', 'title', 'question' ] as $key ) {
            if ( ! empty( $slots[ $key ] ) && is_string( $slots[ $key ] ) ) {
                return sanitize_textarea_field( trim( $slots[ $key ] ) );
            }
        }
        return '';
    }

    private static function apply_style( string $prompt, string $style ): string {
        $style_mods = [
            'photorealistic' => ', photorealistic, highly detailed, professional photography, 8K UHD, sharp focus',
            'artistic'       => ', artistic painting style, creative composition, vibrant colors, masterpiece',
            'anime'          => ', anime style, detailed illustration, vibrant anime art, studio quality',
            'illustration'   => ', digital illustration, clean lines, professional vector art, detailed',
        ];

        if ( isset( $style_mods[ $style ] ) ) {
            return $prompt . $style_mods[ $style ];
        }

        return $prompt;
    }

    /* ══════════════════════════════════════════════════════
     *  CHAT NOTIFICATION
     * ══════════════════════════════════════════════════════ */

    private static function notify_chat( int $user_id, int $job_id, string $image_url, array $meta ): void {
        if ( ! function_exists( 'bizcity_webchat_log_message' ) ) return;

        $session_id = $meta['session_id'] ?? '';
        if ( empty( $session_id ) ) return;

        bizcity_webchat_log_message( [
            'session_id' => $session_id,
            'role'       => 'assistant',
            'content'    => "🎨 Ảnh AI đã tạo xong! Job #{$job_id}\n🔗 {$image_url}",
            'user_id'    => $user_id,
        ] );
    }

    /* ══════════════════════════════════════════════════════
     *  PROMPT LIBRARY (static data)
     * ══════════════════════════════════════════════════════ */

    public static function get_prompt_library(): array {
        return [
            'portrait' => [
                'label' => '👤 Chân dung',
                'icon'  => '👤',
                'prompts' => [
                    [ 'title' => 'Chân dung nghệ thuật',         'prompt' => 'Professional portrait photography, soft natural lighting, shallow depth of field, bokeh background, elegant pose, studio quality' ],
                    [ 'title' => 'Chân dung áo dài',             'prompt' => 'Vietnamese woman in traditional ao dai, flower garden background, golden hour lighting, graceful pose, cultural beauty' ],
                    [ 'title' => 'Business headshot',            'prompt' => 'Professional corporate headshot, clean background, confident expression, business attire, studio lighting' ],
                    [ 'title' => 'Chân dung cinematic',          'prompt' => 'Cinematic portrait, dramatic rim lighting, moody atmosphere, film grain, shallow DOF, anamorphic flare' ],
                    [ 'title' => 'Fantasy character',            'prompt' => 'Fantasy character portrait, ethereal lighting, magical aura, detailed costume, painterly style, epic composition' ],
                    [ 'title' => 'Anime character',              'prompt' => 'Beautiful anime character portrait, detailed eyes, vibrant hair, clean lineart, studio quality illustration' ],
                    [ 'title' => 'Chân dung vintage',            'prompt' => 'Vintage film portrait, 35mm Kodak Portra 400, natural wardrobe, nostalgic mood, grain texture, analog feel' ],
                    [ 'title' => 'Street portrait',              'prompt' => 'Urban street portrait, neon city lights background, night photography, reflection, candid pose, moody color grade' ],
                ],
            ],
            'product' => [
                'label' => '📦 Sản phẩm',
                'icon'  => '📦',
                'prompts' => [
                    [ 'title' => 'Sản phẩm nền trắng',          'prompt' => 'Product photography on white background, clean studio lighting, sharp focus, commercial quality, high detail' ],
                    [ 'title' => 'Sản phẩm lifestyle',           'prompt' => 'Lifestyle product photography, natural setting, warm ambient lighting, editorial style, storytelling composition' ],
                    [ 'title' => 'Flat lay sản phẩm',            'prompt' => 'Flat lay product arrangement, top-down view, minimalist background, organized layout, pastel colors' ],
                    [ 'title' => 'Sản phẩm cao cấp',            'prompt' => 'Luxury product photography, dark marble background, dramatic lighting, premium feel, reflection surface' ],
                    [ 'title' => 'Đồ ăn / Food',                'prompt' => 'Professional food photography, appetizing presentation, garnished detail, warm lighting, shallow DOF, rustic table' ],
                    [ 'title' => 'Mỹ phẩm / Cosmetics',         'prompt' => 'Cosmetic product shot, water droplets, fresh flowers, soft pink background, clean and luxurious, beauty photography' ],
                    [ 'title' => 'Công nghệ / Tech',             'prompt' => 'Technology product photography, sleek minimal background, gradient lighting, futuristic feel, clean reflections' ],
                    [ 'title' => 'Thời trang / Fashion',         'prompt' => 'Fashion product photography, model wearing item, editorial style, magazine quality, dynamic pose, studio backdrop' ],
                    [ 'title' => 'Trang sức / Jewelry',          'prompt' => 'Jewelry product photography, macro detail, sparkle highlights, velvet background, luxury presentation' ],
                    [ 'title' => 'Đồ uống / Beverage',           'prompt' => 'Beverage product photography, condensation drops, ice cubes, splash effect, refreshing feel, commercial quality' ],
                ],
            ],
            'landscape' => [
                'label' => '🌄 Phong cảnh',
                'icon'  => '🌄',
                'prompts' => [
                    [ 'title' => 'Hoàng hôn bãi biển',           'prompt' => 'Golden hour beach sunset, dramatic clouds, ocean waves, palm trees silhouette, warm color palette, landscape photography' ],
                    [ 'title' => 'Núi non hùng vĩ',              'prompt' => 'Majestic mountain landscape, snow-capped peaks, alpine lake reflection, sunrise, dramatic sky, ultra wide angle' ],
                    [ 'title' => 'Phố cổ Việt Nam',              'prompt' => 'Vietnamese old quarter street, lanterns, traditional architecture, morning mist, bicycle, nostalgic atmosphere' ],
                    [ 'title' => 'Rừng tre xanh',                'prompt' => 'Bamboo forest path, green canopy, sunlight filtering through, peaceful zen atmosphere, Japanese style' ],
                    [ 'title' => 'Thành phố về đêm',             'prompt' => 'City skyline at night, neon lights reflection, rain-wet streets, cyberpunk atmosphere, long exposure' ],
                    [ 'title' => 'Ruộng bậc thang',              'prompt' => 'Rice terraces at sunrise, morning mist, Vietnamese countryside, green layers, farmers at work, aerial view' ],
                    [ 'title' => 'Vũ trụ / Space',               'prompt' => 'Deep space nebula, colorful gas clouds, stars, cosmic dust, galaxies, ethereal glow, astronomical photography' ],
                    [ 'title' => 'Underwater world',             'prompt' => 'Underwater coral reef, tropical fish, sunlight rays through water, turquoise ocean, marine life, crystal clear' ],
                ],
            ],
            'social_media' => [
                'label' => '📱 Social Media',
                'icon'  => '📱',
                'prompts' => [
                    [ 'title' => 'Instagram Story',              'prompt' => 'Modern Instagram story design, gradient background, bold typography, lifestyle mood, trendy aesthetic, 9:16 vertical' ],
                    [ 'title' => 'Facebook Post',                'prompt' => 'Eye-catching social media post, bright colors, engaging design, modern layout, bold text overlay, scroll-stopping' ],
                    [ 'title' => 'YouTube Thumbnail',            'prompt' => 'YouTube thumbnail design, dramatic expression, bold title text, bright colors, high contrast, click-worthy, 16:9 landscape' ],
                    [ 'title' => 'TikTok Cover',                 'prompt' => 'TikTok cover image, trendy aesthetic, vibrant colors, Gen-Z style, creative text overlay, vertical 9:16' ],
                    [ 'title' => 'LinkedIn Banner',              'prompt' => 'Professional LinkedIn banner, corporate design, clean layout, brand colors, modern geometric shapes, business aesthetic' ],
                    [ 'title' => 'Blog Header',                  'prompt' => 'Blog header image, clean minimalist design, relevant imagery, soft colors, professional typography overlay area' ],
                    [ 'title' => 'Poster quảng cáo',             'prompt' => 'Advertising poster design, eye-catching layout, product showcase, promotional text space, vibrant colors, marketing material' ],
                    [ 'title' => 'Avatar / Profile pic',         'prompt' => 'Professional avatar design, clean circular composition, friendly expression, solid color background, approachable look' ],
                ],
            ],
            'artistic' => [
                'label' => '🎨 Nghệ thuật',
                'icon'  => '🎨',
                'prompts' => [
                    [ 'title' => 'Tranh sơn dầu',               'prompt' => 'Oil painting masterpiece, classical art style, rich color palette, visible brushstrokes, gallery quality, Renaissance inspiration' ],
                    [ 'title' => 'Watercolor art',               'prompt' => 'Watercolor painting, soft washes, flowing colors, delicate details, paper texture, botanical illustration style' ],
                    [ 'title' => 'Pop Art',                      'prompt' => 'Pop art style, bold outlines, halftone dots, bright primary colors, Andy Warhol inspired, comic book aesthetic' ],
                    [ 'title' => 'Minimalist art',               'prompt' => 'Minimalist abstract art, geometric shapes, limited color palette, clean composition, modern gallery style' ],
                    [ 'title' => 'Art Nouveau',                  'prompt' => 'Art Nouveau illustration, ornate floral borders, elegant flowing lines, Alphonse Mucha inspired, decorative poster' ],
                    [ 'title' => 'Pixel Art',                    'prompt' => 'Pixel art style, retro game aesthetic, 16-bit graphics, colorful sprites, nostalgic gaming art' ],
                    [ 'title' => 'Surrealism',                   'prompt' => 'Surrealist artwork, dream-like composition, impossible architecture, melting reality, Salvador Dali inspired, mind-bending' ],
                    [ 'title' => 'Ukiyo-e Japanese',             'prompt' => 'Ukiyo-e woodblock print style, Japanese art, bold outlines, flat colors, nature scene, Hokusai inspired, traditional' ],
                ],
            ],
            'interior_architecture' => [
                'label' => '🏠 Nội thất & Kiến trúc',
                'icon'  => '🏠',
                'prompts' => [
                    [ 'title' => 'Phòng khách hiện đại',         'prompt' => 'Modern living room interior design, minimalist furniture, natural light, warm wood tones, indoor plants, Scandinavian style' ],
                    [ 'title' => 'Phòng ngủ luxury',             'prompt' => 'Luxury bedroom interior, king bed, velvet textures, ambient lighting, hotel suite style, elegant color scheme' ],
                    [ 'title' => 'Quán café Việt Nam',           'prompt' => 'Vietnamese cafe interior, industrial style, hanging plants, natural materials, warm lighting, Instagram-worthy' ],
                    [ 'title' => 'Văn phòng startup',            'prompt' => 'Startup office space, open plan, colorful accents, collaborative areas, modern furniture, creative workspace' ],
                    [ 'title' => 'Nhà phố Việt Nam',             'prompt' => 'Vietnamese townhouse design, narrow facade, tropical plants, natural ventilation, modern tropical architecture' ],
                    [ 'title' => 'Kitchen design',               'prompt' => 'Modern kitchen interior, marble countertop, pendant lights, open shelving, brass hardware, white and wood palette' ],
                    [ 'title' => 'Bathroom spa',                 'prompt' => 'Spa-like bathroom design, freestanding bathtub, natural stone, rain shower, candles, eucalyptus, zen atmosphere' ],
                    [ 'title' => 'Kiến trúc futuristic',         'prompt' => 'Futuristic architecture, parametric design, glass and steel, organic curves, green building, sustainable design' ],
                ],
            ],
            'branding' => [
                'label' => '🏷️ Branding & Logo',
                'icon'  => '🏷️',
                'prompts' => [
                    [ 'title' => 'Logo minimalist',              'prompt' => 'Minimalist logo design, clean geometric shapes, versatile mark, professional, modern branding, white background' ],
                    [ 'title' => 'Logo mascot',                  'prompt' => 'Mascot logo design, friendly character, vibrant colors, cartoon style, memorable brand identity, clean vector' ],
                    [ 'title' => 'Business card mockup',         'prompt' => 'Professional business card mockup, elegant design, embossed text, premium paper texture, brand identity presentation' ],
                    [ 'title' => 'Packaging design',             'prompt' => 'Product packaging design mockup, modern label, premium materials, brand colors, shelf-ready, retail presentation' ],
                    [ 'title' => 'Brand guideline',              'prompt' => 'Brand guideline page, color palette display, typography specimen, logo usage rules, clean layout, professional design' ],
                    [ 'title' => 'Letterhead design',            'prompt' => 'Corporate letterhead design, professional header, brand logo placement, clean layout, premium stationery' ],
                ],
            ],
            'concept_art' => [
                'label' => '⚔️ Concept Art & Game',
                'icon'  => '⚔️',
                'prompts' => [
                    [ 'title' => 'Game character',               'prompt' => 'RPG game character concept art, detailed armor design, dynamic pose, fantasy warrior, epic background, detailed illustration' ],
                    [ 'title' => 'Sci-fi environment',           'prompt' => 'Sci-fi environment concept art, futuristic city, flying vehicles, holographic displays, atmospheric perspective' ],
                    [ 'title' => 'Fantasy landscape',            'prompt' => 'Fantasy landscape painting, floating islands, magical crystals, ancient ruins, epic scale, concept art quality' ],
                    [ 'title' => 'Creature design',              'prompt' => 'Creature concept art, detailed anatomy, unique design, multiple angles, fantasy monster, professional illustration' ],
                    [ 'title' => 'Weapon design',                'prompt' => 'Fantasy weapon concept art, ornate sword design, magical glow, detailed engravings, multiple views, game asset quality' ],
                    [ 'title' => 'Vehicle concept',              'prompt' => 'Futuristic vehicle concept art, sleek aerodynamic design, detailed mechanical parts, multiple angles, sci-fi racing' ],
                    [ 'title' => 'Mecha / Robot',                'prompt' => 'Mecha robot concept art, detailed mechanical joints, battle-worn texture, dynamic pose, giant scale, anime inspired' ],
                    [ 'title' => 'Dungeon environment',          'prompt' => 'Dark dungeon environment, torchlight, stone corridors, treasure chest, mysterious atmosphere, game level design' ],
                ],
            ],
        ];
    }
}
