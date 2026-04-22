<?php
/**
 * AI Template Generator — Phase 3.6
 *
 * Two generation modes:
 *   PA1 (Vision):    Image → AI Vision → lidojs JSON template
 *   PA2 (Variation): Skeleton template + prompt → LLM → new variations
 *
 * Uses bizcity_llm_chat() via core/bizcity-llm for all LLM calls (Claude Sonnet preferred).
 *
 * @package BizCity_Tool_Image
 * @since   3.6.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class BizCity_AI_Template_Generator {

    /**
     * Preferred model for template generation.
     * Used as override — fallback handled automatically by BizCity_LLM_Client.
     */
    const MODEL_PRIMARY  = 'anthropic/claude-sonnet-4';

    /** Canvas presets. */
    const CANVAS_PRESETS = array(
        'instagram-post'  => array( 'width' => 1080, 'height' => 1080 ),
        'instagram-story' => array( 'width' => 1080, 'height' => 1920 ),
        'facebook-post'   => array( 'width' => 1200, 'height' => 630 ),
        'facebook-cover'  => array( 'width' => 1640, 'height' => 924 ),
        'youtube-thumb'   => array( 'width' => 1280, 'height' => 720 ),
        'a4-portrait'     => array( 'width' => 2480, 'height' => 3508 ),
        'square'          => array( 'width' => 900,  'height' => 900 ),
    );

    /* ═══════════════════════════════════════════════════════════════════════
     * SHARED: BizCity LLM Client bridge
     * ═══════════════════════════════════════════════════════════════════════ */

    /**
     * Check if BizCity LLM client is available and configured.
     *
     * Uses bizcity_llm_is_ready() from core/bizcity-llm — works in both
     * gateway mode (proxies to bizcity.vn hub) and direct mode (own OpenRouter key).
     */
    public static function llm_ready(): bool {
        return function_exists( 'bizcity_llm_is_ready' )
            && bizcity_llm_is_ready();
    }

    /**
     * Call LLM with text messages via bizcity_llm_chat().
     *
     * BizCity_LLM_Client handles: gateway/direct routing, fallback on failure,
     * usage logging, timeout, rate-limit retry — all transparent.
     */
    private static function llm_chat( array $messages, array $params = array() ): array {
        $options = array(
            'model'       => $params['model'] ?? self::MODEL_PRIMARY,
            'purpose'     => $params['purpose'] ?? 'vision',
            'temperature' => $params['temperature'] ?? 0.7,
            'max_tokens'  => $params['max_tokens'] ?? 8000,
            'timeout'     => $params['timeout'] ?? 120,
        );

        return bizcity_llm_chat( $messages, $options );
    }

    /**
     * Call LLM with vision (image + text) via bizcity_llm_chat().
     *
     * Vision works with the same chat() call — just use OpenAI multimodal
     * message format with image_url content blocks.
     */
    private static function llm_vision( string $image_url, string $prompt, array $params = array() ): array {
        $messages = array(
            array( 'role' => 'user', 'content' => array(
                array( 'type' => 'image_url', 'image_url' => array( 'url' => $image_url ) ),
                array( 'type' => 'text', 'text' => $prompt ),
            )),
        );

        $options = array(
            'model'       => $params['model'] ?? self::MODEL_PRIMARY,
            'purpose'     => 'vision',
            'temperature' => $params['temperature'] ?? 0.4,
            'max_tokens'  => $params['max_tokens'] ?? 12000,
            'timeout'     => $params['timeout'] ?? 180,
        );

        return bizcity_llm_chat( $messages, $options );
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * PA1: Vision-to-Template
     * ═══════════════════════════════════════════════════════════════════════ */

    /**
     * Generate a lidojs template from a reference image.
     *
     * @param string $image_url  URL or base64 data URI of the reference image.
     * @param array  $options    {
     *     @type string $canvas_preset  One of CANVAS_PRESETS keys (default: 'square').
     *     @type int    $canvas_width   Custom width (overrides preset).
     *     @type int    $canvas_height  Custom height (overrides preset).
     *     @type string $description    Optional context about the design.
     *     @type string $language       Target language for text (default: 'vi').
     * }
     * @return array { success: bool, template: array|null, raw_response: string, error: string|null }
     */
    public static function vision_to_template( string $image_url, array $options = array() ): array {
        if ( ! self::llm_ready() ) {
            return array( 'success' => false, 'template' => null, 'raw_response' => '', 'error' => 'BizCity LLM not configured.' );
        }

        // Resolve canvas size.
        $preset = $options['canvas_preset'] ?? 'square';
        $canvas = self::CANVAS_PRESETS[ $preset ] ?? self::CANVAS_PRESETS['square'];
        if ( ! empty( $options['canvas_width'] ) && ! empty( $options['canvas_height'] ) ) {
            $canvas = array(
                'width'  => (int) $options['canvas_width'],
                'height' => (int) $options['canvas_height'],
            );
        }

        $description = $options['description'] ?? '';
        $language    = $options['language'] ?? 'vi';

        $prompt = self::build_vision_prompt( $canvas, $description, $language );
        $result = self::llm_vision( $image_url, $prompt );

        if ( empty( $result['success'] ) ) {
            return array(
                'success'      => false,
                'template'     => null,
                'raw_response' => $result['message'] ?? '',
                'error'        => $result['error'] ?? 'LLM call failed.',
            );
        }

        // Extract JSON from response.
        $template = self::extract_json_from_response( $result['message'] );
        if ( ! $template ) {
            return array(
                'success'      => false,
                'template'     => null,
                'raw_response' => $result['message'],
                'error'        => 'Failed to parse template JSON from AI response.',
            );
        }

        // Wrap single page in array if needed.
        if ( isset( $template['name'] ) || isset( $template['layers'] ) ) {
            $template = array( $template );
        }

        return array(
            'success'      => true,
            'template'     => $template,
            'raw_response' => $result['message'],
            'model'        => $result['model'] ?? self::MODEL_PRIMARY,
            'error'        => null,
        );
    }

    /**
     * Build the system + user prompt for vision-to-template.
     */
    private static function build_vision_prompt( array $canvas, string $description, string $language ): string {
        $w = $canvas['width'];
        $h = $canvas['height'];

        $example_layer = wp_json_encode( array(
            'type'   => array( 'resolvedName' => 'TextLayer' ),
            'props'  => array(
                'position'     => array( 'x' => 100, 'y' => 200 ),
                'boxSize'      => array( 'width' => 400, 'height' => 80 ),
                'scale'        => 1.5,
                'rotate'       => 0,
                'text'         => '<p style="font-family: \'Arial\';font-size: 45px;color: rgb(255,255,255);">Sample Text</p>',
                'fonts'        => array(),
                'colors'       => array( 'rgb(255,255,255)' ),
                'fontSizes'    => array( 45 ),
                'effect'       => null,
                'transparency' => 1,
            ),
            'locked' => false,
            'child'  => array(),
            'parent' => 'ROOT',
        ), JSON_UNESCAPED_SLASHES );

        $desc_line = $description ? "\nDesign context: {$description}" : '';

        return <<<PROMPT
You are a professional graphic designer AI. Analyze this reference image and recreate its layout as a lidojs (canva-editor) JSON template.

Canvas size: {$w} x {$h} pixels.{$desc_line}
Target language for any text: {$language}

## Output Format

Return ONLY a valid JSON array (one page) with this exact structure:
```json
[{
  "name": "",
  "notes": "",
  "layers": {
    "ROOT": {
      "type": { "resolvedName": "RootLayer" },
      "props": {
        "boxSize": { "width": {$w}, "height": {$h} },
        "position": { "x": 0, "y": 0 },
        "rotate": 0,
        "color": "<background color as rgb()>",
        "image": null,
        "gradientBackground": null
      },
      "locked": false,
      "child": ["<layer_id_1>", "<layer_id_2>", ...],
      "parent": null
    },
    "<layer_id_1>": { ... },
    "<layer_id_2>": { ... }
  }
}]
```

## Layer Types

Available layer types and their props:

### TextLayer
{$example_layer}

### ShapeLayer
Props: position, boxSize, rotate, clipPath (SVG path like "M 0 0 L 256 0 L 256 256 L 0 256 Z"), scale, color (rgb string), shapeSize ({width, height}), transparency (0-1), gradientBackground (null or object)

### FrameLayer
Props: position, boxSize, rotate, clipPath, scale, image ({boxSize, position, rotate, thumb, url}), transparency

### GroupLayer
Props: position, boxSize, scale, rotate, transparency. Has child array with nested layer IDs.

## Rules
1. Layer IDs must be unique strings like "ca_XXXXXXX" (7 random alphanumeric chars after "ca_").
2. All position x,y are in pixels relative to the canvas top-left.
3. Text must use inline HTML: `<p style="font-family: 'Font Name';font-size: Npx;color: rgb(r,g,b);">text</p>`
4. Use Google Fonts names for font-family.
5. Colors must be in rgb() format.
6. Analyze the image carefully: identify text blocks, shapes, images, their positions, sizes, colors, and fonts.
7. Be precise with positioning — elements should match the reference layout proportionally.
8. DO NOT include markdown fences or any text outside the JSON array.
PROMPT;
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * PA2: Variation Engine
     * ═══════════════════════════════════════════════════════════════════════ */

    /**
     * Generate variations of an existing template.
     *
     * @param array  $skeleton   The base template (verbose lidojs page array).
     * @param string $prompt     User prompt describing desired variations.
     * @param array  $options    {
     *     @type int    $count       Number of variations (default: 3, max: 10).
     *     @type string $language    Target language (default: 'vi').
     *     @type array  $vary_fields Fields to vary: 'text', 'colors', 'fonts', 'effects'. Default: all.
     * }
     * @return array { success: bool, variations: array[], error: string|null }
     */
    public static function generate_variations( array $skeleton, string $prompt, array $options = array() ): array {
        if ( ! self::llm_ready() ) {
            return array( 'success' => false, 'variations' => array(), 'error' => 'BizCity LLM not configured.' );
        }

        $count       = min( (int) ( $options['count'] ?? 3 ), 10 );
        $language    = $options['language'] ?? 'vi';
        $vary_fields = $options['vary_fields'] ?? array( 'text', 'colors', 'fonts', 'effects' );

        // Extract variable slots from skeleton.
        $slots = self::extract_slots( $skeleton );
        if ( empty( $slots ) ) {
            return array( 'success' => false, 'variations' => array(), 'error' => 'No variable slots found in template.' );
        }

        $system_prompt = self::build_variation_system_prompt( $slots, $vary_fields, $language );
        $user_prompt   = "Generate {$count} variations based on this request:\n\n{$prompt}\n\nReturn a JSON array of {$count} variation objects.";

        $messages = array(
            array( 'role' => 'system', 'content' => $system_prompt ),
            array( 'role' => 'user',   'content' => $user_prompt ),
        );

        $result = self::llm_chat( $messages, array( 'temperature' => 0.8 ) );

        if ( empty( $result['success'] ) ) {
            return array(
                'success'    => false,
                'variations' => array(),
                'error'      => $result['error'] ?? 'LLM call failed.',
            );
        }

        // Parse variation patches.
        $patches = self::extract_json_from_response( $result['message'] );
        if ( ! $patches || ! is_array( $patches ) ) {
            return array(
                'success'      => false,
                'variations'   => array(),
                'raw_response' => $result['message'],
                'error'        => 'Failed to parse variation JSON from AI response.',
            );
        }

        // Apply each patch to the skeleton.
        $variations = array();
        foreach ( $patches as $patch ) {
            $variation = self::apply_variation_patch( $skeleton, $patch );
            if ( $variation ) {
                $variations[] = $variation;
            }
        }

        return array(
            'success'      => true,
            'variations'   => $variations,
            'count'        => count( $variations ),
            'model'        => $result['model'] ?? self::MODEL_PRIMARY,
            'raw_response' => $result['message'],
            'error'        => null,
        );
    }

    /**
     * Extract variable slots from a template skeleton.
     * Identifies text content, colors, fonts, effects that can be varied.
     */
    private static function extract_slots( array $skeleton ): array {
        $pages = isset( $skeleton['layers'] ) ? array( $skeleton ) : $skeleton;
        $slots = array();

        foreach ( $pages as $page_idx => $page ) {
            if ( empty( $page['layers'] ) ) continue;

            foreach ( $page['layers'] as $layer_id => $layer ) {
                if ( $layer_id === 'ROOT' ) {
                    // Root background color.
                    $slots[] = array(
                        'page'     => $page_idx,
                        'layer_id' => 'ROOT',
                        'field'    => 'background_color',
                        'current'  => $layer['props']['color'] ?? 'rgb(255,255,255)',
                    );
                    continue;
                }

                $type = $layer['type']['resolvedName'] ?? '';

                if ( $type === 'TextLayer' ) {
                    // Extract plain text from HTML.
                    $html = $layer['props']['text'] ?? '';
                    $plain = wp_strip_all_tags( $html );
                    $slots[] = array(
                        'page'     => $page_idx,
                        'layer_id' => $layer_id,
                        'type'     => 'text',
                        'field'    => 'text',
                        'current'  => $plain,
                        'html'     => $html,
                        'colors'   => $layer['props']['colors'] ?? array(),
                        'fonts'    => array_map( function( $f ) {
                            return $f['family'] ?? $f['name'] ?? '';
                        }, $layer['props']['fonts'] ?? array() ),
                        'font_size' => ( $layer['props']['fontSizes'] ?? array( 45 ) )[0],
                        'effect'    => $layer['props']['effect'] ?? null,
                    );
                } elseif ( $type === 'ShapeLayer' ) {
                    $slots[] = array(
                        'page'     => $page_idx,
                        'layer_id' => $layer_id,
                        'type'     => 'shape',
                        'field'    => 'color',
                        'current'  => $layer['props']['color'] ?? '',
                    );
                }
            }
        }

        return $slots;
    }

    /**
     * Build system prompt for variation generation.
     */
    private static function build_variation_system_prompt( array $slots, array $vary_fields, string $language ): string {
        $slots_json = wp_json_encode( $slots, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );

        $fields_desc = array();
        if ( in_array( 'text', $vary_fields ) )    $fields_desc[] = '- **text**: Change text content (translate/rewrite for the theme). Keep HTML structure, only change the inner text and color.';
        if ( in_array( 'colors', $vary_fields ) )   $fields_desc[] = '- **colors**: Change color palette (use harmonious rgb() values).';
        if ( in_array( 'fonts', $vary_fields ) )    $fields_desc[] = '- **fonts**: Change font families (use Google Fonts names).';
        if ( in_array( 'effects', $vary_fields ) )  $fields_desc[] = '- **effects**: Change text effects (null, or {"name":"shadow"|"echo"|"outline","settings":{...}}).';
        $fields_list = implode( "\n", $fields_desc );

        return <<<PROMPT
You are a creative design AI that generates template variations. Given a base template's variable slots, produce diverse variations.

Target language: {$language}

## Current Template Slots
```json
{$slots_json}
```

## What to vary
{$fields_list}

## Output Format
Return a JSON array of variation objects. Each variation is an object with layer_id keys mapping to new values:
```json
[
  {
    "name": "Variation description",
    "changes": {
      "ROOT": { "background_color": "rgb(30, 30, 30)" },
      "ca_XXXXXXX": {
        "text": "<p style=\"font-family: 'Roboto';font-size: 45px;color: rgb(255,200,0);\">NEW TEXT</p>",
        "colors": ["rgb(255,200,0)"],
        "fonts": ["Roboto"]
      },
      "ca_YYYYYYY": {
        "color": "rgb(255, 100, 50)"
      }
    }
  }
]
```

## Rules
1. Each variation must be visually distinct (different color palette, different text, different mood).
2. Text must be complete HTML with inline styles matching the font-family, font-size, and color.
3. Colors must use rgb() format.
4. Font names must be valid Google Fonts.
5. Keep the structural layout identical — only change content and styling.
6. DO NOT include markdown fences or text outside the JSON array.
PROMPT;
    }

    /**
     * Apply a variation patch to a template skeleton.
     *
     * @param array $skeleton Base template pages.
     * @param array $patch    { "name": "...", "changes": { "layer_id": { field: value } } }
     * @return array|null Modified template or null on failure.
     */
    private static function apply_variation_patch( array $skeleton, array $patch ): ?array {
        if ( empty( $patch['changes'] ) || ! is_array( $patch['changes'] ) ) {
            return null;
        }

        // Deep-clone the skeleton.
        $result = json_decode( wp_json_encode( $skeleton ), true );
        $pages  = isset( $result['layers'] ) ? array( &$result ) : $result;

        foreach ( $patch['changes'] as $layer_id => $changes ) {
            foreach ( $pages as &$page ) {
                if ( ! isset( $page['layers'][ $layer_id ] ) ) continue;

                $layer = &$page['layers'][ $layer_id ];

                if ( $layer_id === 'ROOT' ) {
                    if ( isset( $changes['background_color'] ) ) {
                        $layer['props']['color'] = sanitize_text_field( $changes['background_color'] );
                    }
                    continue;
                }

                $type = $layer['type']['resolvedName'] ?? '';

                if ( $type === 'TextLayer' ) {
                    if ( isset( $changes['text'] ) ) {
                        $layer['props']['text'] = wp_kses_post( $changes['text'] );
                    }
                    if ( isset( $changes['colors'] ) && is_array( $changes['colors'] ) ) {
                        $layer['props']['colors'] = array_map( 'sanitize_text_field', $changes['colors'] );
                    }
                    if ( isset( $changes['fonts'] ) && is_array( $changes['fonts'] ) ) {
                        // Update font family in fonts array.
                        foreach ( $changes['fonts'] as $idx => $family ) {
                            if ( isset( $layer['props']['fonts'][ $idx ] ) ) {
                                $layer['props']['fonts'][ $idx ]['family'] = sanitize_text_field( $family );
                                $layer['props']['fonts'][ $idx ]['name']   = sanitize_text_field( $family ) . ' Regular';
                            }
                        }
                    }
                    if ( array_key_exists( 'effect', $changes ) ) {
                        // Sanitize: only allow null or object with known keys.
                        $effect = $changes['effect'];
                        if ( is_array( $effect ) && isset( $effect['name'] ) ) {
                            $effect['name'] = sanitize_text_field( $effect['name'] );
                        } elseif ( ! is_null( $effect ) ) {
                            $effect = null;
                        }
                        $layer['props']['effect'] = $effect;
                    }
                } elseif ( $type === 'ShapeLayer' ) {
                    if ( isset( $changes['color'] ) ) {
                        $layer['props']['color'] = sanitize_text_field( $changes['color'] );
                    }
                }

                unset( $layer );
            }
            unset( $page );
        }

        return isset( $result['layers'] ) ? $result : $result;
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * HELPERS
     * ═══════════════════════════════════════════════════════════════════════ */

    /**
     * Extract JSON array/object from an LLM response that may contain markdown fences.
     */
    private static function extract_json_from_response( string $response ) {
        // Try direct parse first.
        $decoded = json_decode( $response, true );
        if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
            return $decoded;
        }

        // Extract from markdown code fences.
        if ( preg_match( '/```(?:json)?\s*\n?([\s\S]*?)\n?```/', $response, $m ) ) {
            $decoded = json_decode( trim( $m[1] ), true );
            if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
                return $decoded;
            }
        }

        // Try to find JSON array/object boundaries.
        $start = false;
        $bracket = null;
        for ( $i = 0; $i < strlen( $response ); $i++ ) {
            if ( $response[ $i ] === '[' || $response[ $i ] === '{' ) {
                $start = $i;
                $bracket = $response[ $i ] === '[' ? ']' : '}';
                break;
            }
        }
        if ( $start !== false ) {
            // Find matching closing bracket.
            $depth = 0;
            for ( $i = $start; $i < strlen( $response ); $i++ ) {
                if ( $response[ $i ] === ( $bracket === ']' ? '[' : '{' ) ) $depth++;
                if ( $response[ $i ] === $bracket ) $depth--;
                if ( $depth === 0 ) {
                    $json_str = substr( $response, $start, $i - $start + 1 );
                    $decoded = json_decode( $json_str, true );
                    if ( json_last_error() === JSON_ERROR_NONE ) {
                        return $decoded;
                    }
                    break;
                }
            }
        }

        return null;
    }

    /**
     * Generate a random lidojs layer ID.
     */
    public static function random_layer_id(): string {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $id = 'ca_';
        for ( $i = 0; $i < 7; $i++ ) {
            $id .= $chars[ wp_rand( 0, strlen( $chars ) - 1 ) ];
        }
        return $id;
    }

    /**
     * Get available skeleton templates from DB for variation.
     *
     * @param int $limit Max templates to return.
     * @return array [ { id, description, data (parsed), pages, thumb_url } ]
     */
    public static function get_skeleton_templates( int $limit = 50 ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'bztimg_editor_templates';

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, description, data_json, pages, img_url, attachment_id FROM {$table} ORDER BY sort_order ASC, id ASC LIMIT %d",
            $limit
        ) );

        $templates = array();
        foreach ( $rows as $row ) {
            $data = json_decode( $row->data_json, true );
            $templates[] = array(
                'id'          => (int) $row->id,
                'description' => $row->description,
                'data'        => $data,
                'pages'       => (int) $row->pages,
                'thumb_url'   => BizCity_REST_API_Editor_Assets::resolve_thumb_url_public( $row ),
            );
        }

        return $templates;
    }

    /**
     * Save a generated template to DB.
     *
     * @param array  $template_data Verbose lidojs page array.
     * @param string $description   Description/keywords.
     * @param string $source        'ai_vision' or 'ai_variation'.
     * @return int|false Inserted row ID or false.
     */
    public static function save_template( array $template_data, string $description, string $source = 'ai_variation' ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bztimg_editor_templates';

        // Determine canvas size from ROOT layer.
        $pages = isset( $template_data['layers'] ) ? array( $template_data ) : $template_data;
        $first_page = $pages[0] ?? array();
        $root_props = $first_page['layers']['ROOT']['props'] ?? array();
        $width  = (int) ( $root_props['boxSize']['width'] ?? 900 );
        $height = (int) ( $root_props['boxSize']['height'] ?? 900 );

        // Get next sort_order.
        $max_sort = (int) $wpdb->get_var( "SELECT MAX(sort_order) FROM {$table}" );

        $ok = $wpdb->insert( $table, array(
            'description' => sanitize_text_field( $description . ' [' . $source . ']' ),
            'data_json'   => wp_json_encode( $template_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
            'pages'       => count( $pages ),
            'width'       => $width,
            'height'      => $height,
            'img_url'     => '',
            'sort_order'  => $max_sort + 1,
        ), array( '%s', '%s', '%d', '%d', '%d', '%s', '%d' ) );

        if ( $ok === false ) {
            error_log( '[AI_Template_Generator] save_template INSERT failed: ' . $wpdb->last_error . ' source=' . $source );
            return false;
        }

        $insert_id = $wpdb->insert_id;

        // Register in Unified Output Store (PHASE-1.9).
        if ( class_exists( 'BizCity_Output_Store' ) ) {
            BizCity_Output_Store::register_media_output( array(
                'workshop'      => BizCity_Output_Store::WORKSHOP_CANVA_EDITOR,
                'media_type'    => 'design',
                'tool_type'     => 'ai_template_' . $source,
                'title'         => sanitize_text_field( $description ),
                'file_url'      => '',
                'thumbnail_url' => '',
                'user_id'       => get_current_user_id(),
                'input_snapshot' => array( 'source' => $source, 'template_id' => $insert_id, 'pages' => count( $pages ) ),
            ) );
        }

        return $insert_id;
    }
}
