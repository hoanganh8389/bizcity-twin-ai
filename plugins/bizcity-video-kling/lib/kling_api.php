<?php
/**
 * Kling API Helper Functions
 * Support: PiAPI Gateway (https://piapi.ai)
 * 
 * Provides:
 * - Create video generation task
 * - Poll task status
 * - Download video to WordPress Media Library
 * - R2 upload support
 */

if (!defined('ABSPATH')) exit;

/**
 * Log helper
 */
function waic_kling_log($msg, $data = null) {
    if (is_array($msg) || is_object($msg)) {
        $data = $msg;
        $msg  = 'log';
    }
    $line = '[BizCity-Kling] ' . $msg;
    if ($data !== null) {
        $line .= ' ' . wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    error_log($line);
}

/**
 * Get API config from settings or constants.
 *
 * Supports 2 modes:
 * 1. Direct PiAPI — when local API key is configured
 * 2. BizCity Gateway — when no local key, routes through bizcity-llm-router
 * 
 * @param array $settings Override settings
 * @return array Config array with 'mode' = 'direct' | 'gateway'
 */
function waic_kling_get_api_config(array $settings = []): array {
    $api_key = ! empty( $settings['api_key'] )
        ? trim( $settings['api_key'] )
        : get_option( 'bizcity_video_kling_api_key', '' );

    // Fallback to constant
    if ( empty( $api_key ) && defined( 'BIZCITY_KLING_API_KEY' ) ) {
        $api_key = BIZCITY_KLING_API_KEY;
    }

    // Direct PiAPI mode
    if ( ! empty( $api_key ) ) {
        $endpoint = ! empty( $settings['endpoint'] )
            ? trim( $settings['endpoint'] )
            : get_option( 'bizcity_video_kling_endpoint', 'https://api.piapi.ai/api/v1' );

        return apply_filters( 'waic_kling_api_config', [
            'mode'     => 'direct',
            'endpoint' => untrailingslashit( $endpoint ),
            'api_key'  => $api_key,
            'timeout'  => ! empty( $settings['timeout'] ) ? (int) $settings['timeout'] : 60,
        ], $settings );
    }

    // BizCity Gateway mode — use LLM Router API key as bearer token
    // Use get_site_option for multisite compatibility (network-level option)
    $gateway_key = get_site_option( 'bizcity_llm_api_key', '' );
    if ( empty( $gateway_key ) ) {
        $gateway_key = get_option( 'bizcity_llm_api_key', '' );
    }
    $gateway_url = get_site_option( 'bizcity_llm_gateway_url', '' );
    if ( empty( $gateway_url ) ) {
        $gateway_url = get_option( 'bizcity_llm_gateway_url', '' );
    }

    // Use Hub namespace (bizcity/llmhub/v1) — always available via mu-plugin bizcity-openrouter.
    // video/router/v1 requires bizcity-llm-router plugin active, which may not be the case.
    if ( empty( $gateway_url ) ) {
        $gateway_url = 'https://bizcity.vn/wp-json/bizcity/llmhub/v1/video';
    } else {
        $gateway_url = untrailingslashit( $gateway_url );
        // Strip any existing REST namespace suffix to get the base URL
        $gateway_url = preg_replace( '#/wp-json/.*$#', '', $gateway_url );
        // If it's a bare domain, just use it
        $gateway_url = untrailingslashit( $gateway_url );
        $gateway_url = $gateway_url . '/wp-json/bizcity/llmhub/v1/video';
    }

    return apply_filters( 'waic_kling_api_config', [
        'mode'     => 'gateway',
        'endpoint' => untrailingslashit( $gateway_url ),
        'api_key'  => $gateway_key,
        'timeout'  => ! empty( $settings['timeout'] ) ? (int) $settings['timeout'] : 60,
    ], $settings );
}

/**
 * HTTP POST helper
 */
function waic_kling_http_post(string $url, array $headers, array $body, int $timeout = 60): array {
    // Log chỉ metadata — bỏ qua image_url / prompt dài để tránh log file phình to.
    $log_body = $body;
    if ( isset( $log_body['image_url'] ) )      $log_body['image_url']      = '[' . strlen( (string) $log_body['image_url'] ) . ' chars]';
    if ( isset( $log_body['input']['image_url'] ) ) $log_body['input']['image_url'] = '[truncated]';
    if ( isset( $log_body['input']['image'] ) )     $log_body['input']['image']     = '[truncated]';
    waic_kling_log('http_post', ['url' => $url, 'body' => $log_body]);
    
    $res = wp_remote_post($url, [
        'timeout' => $timeout,
        'headers' => $headers,
        'body'    => wp_json_encode($body),
    ]);

    if (is_wp_error($res)) {
        return ['ok' => false, 'error' => $res->get_error_message()];
    }
    
    $code = wp_remote_retrieve_response_code($res);
    $raw_body = wp_remote_retrieve_body($res);
    $json = json_decode($raw_body, true);
    
    waic_kling_log('http_post.response', ['code' => $code, 'body' => $json]);
    
    if ($code < 200 || $code >= 300) {
        return [
            'ok' => false, 
            'error' => 'HTTP ' . $code, 
            'raw' => $json,
            'raw_body' => $raw_body
        ];
    }
    
    return ['ok' => true, 'data' => $json];
}

/**
 * HTTP GET helper
 */
function waic_kling_http_get(string $url, array $headers, int $timeout = 60): array {
    $res = wp_remote_get($url, [
        'timeout' => $timeout,
        'headers' => $headers,
    ]);

    if (is_wp_error($res)) {
        return ['ok' => false, 'error' => $res->get_error_message()];
    }
    
    $code = wp_remote_retrieve_response_code($res);
    $json = json_decode(wp_remote_retrieve_body($res), true);
    
    if ($code < 200 || $code >= 300) {
        return ['ok' => false, 'error' => 'HTTP ' . $code, 'raw' => $json];
    }
    
    return ['ok' => true, 'data' => $json];
}

/**
 * Get available Kling models (version + mode combinations)
 * Format: version|mode
 * 
 * @return array Model list with key => label
 */
function waic_kling_get_models(): array {
    return [
        '1.5|std'  => 'Kling v1.5 Standard ($0.26/10s) - Supports Extend',
        '1.5|pro'  => 'Kling v1.5 Pro ($0.46/10s) - Supports Extend',
        '1.6|std'  => 'Kling v1.6 Standard ($0.26/10s) - Supports Extend',
        '1.6|pro'  => 'Kling v1.6 Pro ($0.46/10s) - Supports Extend',
        '2.1|std'  => 'Kling v2.1 Standard ($0.26/10s)',
        '2.1|pro'  => 'Kling v2.1 Pro ($0.46/10s)',
        '2.5|std'  => 'Kling v2.5 Standard ($0.20/10s)',
        '2.5|pro'  => 'Kling v2.5 Pro ($0.33/10s)',
        '2.6|std'  => 'Kling v2.6 Standard ($0.20/10s)',
        '2.6|pro'  => 'Kling v2.6 Pro ($0.33/10s) - Latest',
    ];
}

/**
 * Get all available models across all engines (Kling, SeeDance, Sora, Veo)
 * 
 * Key format:
 * - Kling (legacy):   "version|mode"       e.g. "2.6|pro"
 * - Other engines:    "engine:variant"      e.g. "seedance:1.0"
 * 
 * @return array [ model_key => [ 'label' => ..., 'engine' => ..., 'group' => ... ] ]
 */
function waic_kling_get_all_models(): array {
    return [
        // ── Kling AI (default engine) ──
        '2.6|pro'  => [ 'label' => 'Kling v2.6 Pro',      'engine' => 'kling', 'group' => 'Kling AI' ],
        '2.6|std'  => [ 'label' => 'Kling v2.6 Standard',  'engine' => 'kling', 'group' => 'Kling AI' ],
        '2.5|pro'  => [ 'label' => 'Kling v2.5 Pro',      'engine' => 'kling', 'group' => 'Kling AI' ],
        '1.6|pro'  => [ 'label' => 'Kling v1.6 Pro',      'engine' => 'kling', 'group' => 'Kling AI' ],

        // ── SeeDance (ByteDance) ──
        'seedance:1.0' => [ 'label' => 'SeeDance v1.0',   'engine' => 'seedance', 'group' => 'SeeDance' ],

        // ── Sora (OpenAI) ──
        'sora:v1'      => [ 'label' => 'Sora v1',         'engine' => 'sora', 'group' => 'Sora (OpenAI)' ],

        // ── Veo (Google) ──
        'veo:3'        => [ 'label' => 'Veo 3',           'engine' => 'veo', 'group' => 'Veo (Google)' ],

        // ── Hailuo AI (MiniMax) ──
        'hailuo:2.3'      => [ 'label' => 'Hailuo 2.3',      'engine' => 'hailuo', 'group' => 'Hailuo AI' ],
        'hailuo:2.3-fast' => [ 'label' => 'Hailuo 2.3 Fast', 'engine' => 'hailuo', 'group' => 'Hailuo AI' ],
        'hailuo:02'       => [ 'label' => 'Hailuo 02',       'engine' => 'hailuo', 'group' => 'Hailuo AI' ],
    ];
}

/**
 * Check if model supports extend_video
 * Only Kling 1.5 and 1.6 support extend_video
 * 
 * @param string $model_str Model string (e.g., "2.6|pro")
 * @return bool
 */
function waic_kling_model_supports_extend(string $model_str): bool {
    $parsed = waic_kling_parse_model($model_str);
    // Only Kling 1.x models support extend
    if (($parsed['engine'] ?? 'kling') !== 'kling') return false;
    $version = $parsed['version'];
    return in_array($version, ['1.5', '1.6']);
}

/**
 * Parse model string to engine, version, and mode
 * 
 * Formats:
 * - "2.6|pro"          → engine=kling, version=2.6, mode=pro  (legacy Kling)
 * - "seedance:1.0"     → engine=seedance, version=1.0, mode=null
 * - "sora:v1"          → engine=sora, version=v1, mode=null
 * - "veo:3"            → engine=veo, version=3, mode=null
 * 
 * @param string $model_str
 * @return array ['engine' => ..., 'version' => ..., 'mode' => ...]
 */
function waic_kling_parse_model(string $model_str): array {
    // New multi-engine format: "engine:variant"
    if (strpos($model_str, ':') !== false) {
        list($engine, $variant) = explode(':', $model_str, 2);
        $engine = strtolower(trim($engine));
        // For kling explicit prefix: "kling:2.6|pro"
        if ($engine === 'kling' && strpos($variant, '|') !== false) {
            list($version, $mode) = explode('|', $variant);
            return ['engine' => 'kling', 'version' => $version, 'mode' => $mode];
        }
        return ['engine' => $engine, 'version' => $variant, 'mode' => null];
    }

    // Legacy Kling format: "2.6|pro"
    if (strpos($model_str, '|') !== false) {
        list($version, $mode) = explode('|', $model_str);
        return ['engine' => 'kling', 'version' => $version, 'mode' => $mode];
    }
    
    // Old format conversions
    $old_map = [
        'kling-v1'   => ['engine' => 'kling', 'version' => '1.5', 'mode' => 'pro'],
        'kling-v1-5' => ['engine' => 'kling', 'version' => '1.5', 'mode' => 'pro'],
        'kling-v1-6' => ['engine' => 'kling', 'version' => '1.6', 'mode' => 'pro'],
        'kling/v1-5/pro/image-to-video' => ['engine' => 'kling', 'version' => '1.5', 'mode' => 'pro'],
        'kling/v1-5/standard/image-to-video' => ['engine' => 'kling', 'version' => '1.5', 'mode' => 'std'],
    ];
    
    if (isset($old_map[$model_str])) {
        return $old_map[$model_str];
    }
    
    // Default
    return ['engine' => 'kling', 'version' => '2.6', 'mode' => 'pro'];
}

/**
 * Create video generation task (supports Kling, SeeDance, Sora, Veo via PiAPI)
 * 
 * In gateway mode, routes through BizCity LLM Router instead of PiAPI directly.
 * 
 * @param array $settings API settings (api_key, endpoint, model)
 * @param array $input Task input parameters (prompt, duration, aspect_ratio, image_url)
 * @return array Result with task_id
 */
function waic_kling_create_task(array $settings, array $input): array {
    $cfg = waic_kling_get_api_config($settings);

    error_log('[BVK create_task] mode=' . ($cfg['mode'] ?? '?') . ' | endpoint=' . ($cfg['endpoint'] ?? '?') . ' | has_key=' . (empty($cfg['api_key']) ? 'NO' : 'yes(' . strlen($cfg['api_key']) . ')'));
    
    if (empty($cfg['api_key'])) {
        return ['ok' => false, 'error' => 'Missing API key. Configure PiAPI key or BizCity API key.'];
    }

    $model_str = !empty($settings['model']) ? $settings['model'] : '2.6|pro';

    // ── Gateway mode: call BizCity Router ──
    if (($cfg['mode'] ?? 'direct') === 'gateway') {
        return waic_kling_create_task_via_gateway($cfg, $model_str, $input);
    }

    // ── Direct PiAPI mode ──
    $url = $cfg['endpoint'] . '/task';
    $headers = [
        'Content-Type' => 'application/json',
        'X-API-Key'    => $cfg['api_key'],
    ];

    $parsed = waic_kling_parse_model($model_str);
    $engine = $parsed['engine'] ?? 'kling';

    $payload = waic_kling_build_engine_payload($engine, $parsed, $input);

    waic_kling_log('create_task', ['mode' => 'direct', 'engine' => $engine, 'model' => $model_str, 'payload' => $payload]);

    return waic_kling_http_post($url, $headers, $payload, $cfg['timeout']);
}

/**
 * Create task via BizCity Gateway (video/router/v1/generate).
 * Maps local model format to Hub model format.
 */
function waic_kling_create_task_via_gateway(array $cfg, string $model_str, array $input): array {
    $url = $cfg['endpoint'] . '/generate';
    $headers = [
        'Content-Type'  => 'application/json',
        'Authorization' => 'Bearer ' . $cfg['api_key'],
    ];

    // Map local model string to Hub model format
    $hub_model = waic_kling_map_model_to_hub($model_str);

    $body = [
        'prompt'       => $input['prompt'] ?? '',
        'model'        => $hub_model,
        'duration'     => intval($input['duration'] ?? 5),
        'aspect_ratio' => $input['aspect_ratio'] ?? '9:16',
        'plugin_name'  => 'bizcity-video-kling',
        'site_url'     => home_url(),
    ];

    if (!empty($input['image_url'])) {
        $body['image_url'] = $input['image_url'];
    }
    if (!empty($input['motion_reference_url'])) {
        $body['motion_reference_url'] = $input['motion_reference_url'];
    }
    if (!empty($input['negative_prompt'])) {
        $body['negative_prompt'] = $input['negative_prompt'];
    }
    if (!empty($input['with_audio'])) {
        $body['with_audio'] = true;
    }

    waic_kling_log('create_task', ['mode' => 'gateway', 'url' => $url, 'model' => $hub_model]);

    $result = waic_kling_http_post($url, $headers, $body, $cfg['timeout']);

    // Normalize gateway response to match PiAPI format expected by the rest of the code
    if (($result['ok'] ?? false) && !empty($result['data']['task_id'])) {
        return [
            'ok'   => true,
            'data' => [
                'data' => [
                    'task_id' => $result['data']['task_id'],
                    'status'  => $result['data']['status'] ?? 'pending',
                ],
            ],
            'cost_usd' => $result['data']['cost_usd'] ?? 0,
            'via'      => 'bizcity_gateway',
        ];
    }

    return $result;
}

/**
 * Map local model string (2.6|pro, seedance:1.0, etc.) to Hub model format (kling/v1-5/pro, etc.)
 */
function waic_kling_map_model_to_hub(string $model_str): string {
    $parsed = waic_kling_parse_model($model_str);
    $engine = $parsed['engine'] ?? 'kling';

    if ($engine === 'kling') {
        $v    = str_replace('.', '-', $parsed['version'] ?? '2.6');
        $mode = ($parsed['mode'] ?? 'pro') === 'std' ? 'standard' : ($parsed['mode'] ?? 'pro');
        return "kling/v{$v}/{$mode}";
    }

    // Other engines: map directly
    $hub_map = [
        'seedance' => 'wan/v2.1',         // SeeDance maps to Wan
        'sora'     => 'runway/gen3-alpha', // Sora maps to Runway
        'veo'      => 'luma/ray2',         // Veo maps to Luma
        'hailuo'   => 'hailuo/video-01',   // Hailuo (MiniMax)
    ];

    return $hub_map[$engine] ?? "kling/v1-5/pro";
}

/**
 * Build engine-specific PiAPI payload
 * 
 * @param string $engine Engine name (kling, seedance, sora, veo)
 * @param array  $parsed Parsed model info (engine, version, mode)
 * @param array  $input  User input (prompt, duration, aspect_ratio, image_url, ...)
 * @return array PiAPI-compatible payload
 */
function waic_kling_build_engine_payload(string $engine, array $parsed, array $input): array {
    $prompt       = $input['prompt'] ?? '';
    $duration     = (int)($input['duration'] ?? 5);
    $aspect_ratio = $input['aspect_ratio'] ?? '9:16';
    $image_url    = $input['image_url'] ?? '';

    switch ($engine) {

        /* ── Kling AI ─────────────────────────────────────── */
        case 'kling':
            $api_input = [
                'prompt'       => $prompt,
                'mode'         => $parsed['mode'] ?? 'pro',
                'version'      => $parsed['version'] ?? '2.6',
                'duration'     => min($duration, 10),
                'aspect_ratio' => $aspect_ratio,
                'cfg_scale'    => 0.5,
            ];
            if (!empty($input['with_audio'])) {
                $api_input['with_audio'] = true;
                $api_input['audio'] = true;
            }
            if (!empty($image_url)) {
                $api_input['image_url'] = $image_url;
            }
            if (!empty($input['motion_reference_url'])) {
                $api_input['motion_video'] = $input['motion_reference_url'];
            }
            if (!empty($input['negative_prompt'])) {
                $api_input['negative_prompt'] = $input['negative_prompt'];
            }
            return [
                'model'     => 'kling',
                'task_type' => 'video_generation',
                'input'     => $api_input,
            ];

        /* ── SeeDance (ByteDance) ─────────────────────────── */
        case 'seedance':
            $api_input = [
                'prompt'       => $prompt,
                'duration'     => min($duration, 10),
                'aspect_ratio' => $aspect_ratio,
            ];
            if (!empty($image_url)) {
                $api_input['image_url'] = $image_url;
            }
            return [
                'model'     => 'seedance',
                'task_type' => 'video_generation',
                'input'     => $api_input,
            ];

        /* ── Sora (OpenAI) ────────────────────────────────── */
        case 'sora':
            $api_input = [
                'prompt'       => $prompt,
                'duration'     => min($duration, 20),
                'aspect_ratio' => $aspect_ratio,
            ];
            if (!empty($image_url)) {
                $api_input['image_url'] = $image_url;
            }
            return [
                'model'     => 'sora',
                'task_type' => 'video_generation',
                'input'     => $api_input,
            ];

        /* ── Veo (Google) ─────────────────────────────────── */
        case 'veo':
            $api_input = [
                'prompt'       => $prompt,
                'duration'     => min($duration, 8),
                'aspect_ratio' => $aspect_ratio,
            ];
            if (!empty($image_url)) {
                $api_input['image_url'] = $image_url;
            }
            return [
                'model'     => 'veo',
                'task_type' => 'video_generation',
                'input'     => $api_input,
            ];

        /* ── Hailuo AI (MiniMax) ──────────────────────────── */
        case 'hailuo':
            $variant = $parsed['version'] ?? '2.3';
            // Map Hailuo variant → PiAPI task_type
            $hailuo_map = [
                '2.3'      => 'hailuo_video_2.3',
                '2.3-fast' => 'hailuo_video_2.3_fast',
                '02'       => 'hailuo_video_02',
            ];
            $task_type = $hailuo_map[ $variant ] ?? 'hailuo_video_2.3';

            $api_input = [
                'prompt'       => $prompt,
                'duration'     => min($duration, 5), // Hailuo max 5s
                'aspect_ratio' => $aspect_ratio,
            ];
            if (!empty($image_url)) {
                $api_input['image_url'] = $image_url;
            }
            return [
                'model'     => 'hailuo',
                'task_type' => $task_type,
                'input'     => $api_input,
            ];

        /* ── Fallback → Kling ─────────────────────────────── */
        default:
            waic_kling_log('Unknown engine, fallback to kling', ['engine' => $engine]);
            return waic_kling_build_engine_payload('kling', $parsed, $input);
    }
}

/**
 * Extend existing video (for longer videos)
 * 
 * @param array $settings API settings
 * @param string $origin_task_id Task ID of video to extend
 * @param string $prompt Optional prompt for extension direction
 * @return array Result with new task_id
 */
function waic_kling_extend_video(array $settings, string $origin_task_id, string $prompt = ''): array {
    $cfg = waic_kling_get_api_config($settings);
    
    if (empty($cfg['api_key'])) {
        return ['ok' => false, 'error' => 'Missing API key'];
    }

    $url = $cfg['endpoint'] . '/task';
    $headers = [
        'Content-Type' => 'application/json',
        'X-API-Key'    => $cfg['api_key'],
    ];

    $api_input = [
        'origin_task_id' => $origin_task_id,
    ];
    
    // Add prompt if provided (for continuation direction)
    if (!empty($prompt)) {
        $api_input['prompt'] = $prompt;
    }
    
    $payload = [
        'model'     => 'kling',
        'task_type' => 'extend_video',
        'input'     => $api_input,
    ];

    waic_kling_log('extend_video', ['origin_task_id' => $origin_task_id, 'prompt' => $prompt]);

    return waic_kling_http_post($url, $headers, $payload, $cfg['timeout']);
}

/**
 * Calculate segments needed for target duration
 * 
 * @param int $total_duration Target total duration in seconds
 * @param int $segment_duration Duration per segment (default 10s)
 * @return array Segment info
 */
function waic_kling_calculate_segments(int $total_duration, int $segment_duration = 10): array {
    $total_duration = max(5, $total_duration);
    $segment_duration = min(10, max(5, $segment_duration));
    
    $num_segments = ceil($total_duration / $segment_duration);
    $first_duration = min($total_duration, $segment_duration);
    
    return [
        'total_duration' => $total_duration,
        'segment_duration' => $segment_duration,
        'num_segments' => $num_segments,
        'first_duration' => $first_duration,
        'extend_count' => max(0, $num_segments - 1),
    ];
}

/**
 * Get task status
 * 
 * In gateway mode, routes through BizCity LLM Router.
 * 
 * @param array $settings API settings
 * @param string $task_id Task ID
 * @return array Task status data
 */
function waic_kling_get_task(array $settings, string $task_id): array {
    $cfg = waic_kling_get_api_config($settings);
    
    if (empty($cfg['api_key'])) {
        return ['ok' => false, 'error' => 'Missing API key'];
    }

    // ── Gateway mode ──
    if (($cfg['mode'] ?? 'direct') === 'gateway') {
        $url = $cfg['endpoint'] . '/status?task_id=' . rawurlencode($task_id);
        $headers = [
            'Authorization' => 'Bearer ' . $cfg['api_key'],
        ];

        $result = waic_kling_http_get($url, $headers, $cfg['timeout']);

        // Normalize gateway response to match PiAPI format
        if (($result['ok'] ?? false) && isset($result['data']['status'])) {
            return [
                'ok'   => true,
                'data' => [
                    'data' => [
                        'task_id'   => $task_id,
                        'status'    => $result['data']['status'],
                        'progress'  => $result['data']['progress'] ?? 0,
                        'video_url' => $result['data']['video_url'] ?? '',
                        'output'    => [
                            'video_url' => $result['data']['video_url'] ?? '',
                        ],
                    ],
                ],
                'via' => 'bizcity_gateway',
            ];
        }

        return $result;
    }

    // ── Direct PiAPI mode ──
    $url = $cfg['endpoint'] . '/task/' . rawurlencode($task_id);
    $headers = [
        'X-API-Key' => $cfg['api_key'],
    ];

    return waic_kling_http_get($url, $headers, $cfg['timeout']);
}

/**
 * Normalize status from different gateway responses
 * 
 * @param array $payload API response
 * @return string Normalized status
 */
function waic_kling_normalize_status(array $payload): string {
    // Try different paths
    $candidates = [
        $payload['status'] ?? null,
        $payload['data']['status'] ?? null,
        $payload['data']['data']['status'] ?? null,
        $payload['task']['status'] ?? null,
    ];
    
    foreach ($candidates as $status) {
        if ($status) {
            return strtolower((string)$status);
        }
    }
    
    return 'unknown';
}

/**
 * Extract video URL from response
 * 
 * @param array $payload API response
 * @return string|null Video URL
 */
function waic_kling_extract_video_url(array $payload): ?string {
    // PiAPI format: output.works[0].video.resource
    if (!empty($payload['output']['works'][0]['video']['resource'])) {
        return $payload['output']['works'][0]['video']['resource'];
    }
    
    // Also check data wrapper
    if (!empty($payload['data']['output']['works'][0]['video']['resource'])) {
        return $payload['data']['output']['works'][0]['video']['resource'];
    }
    
    // Fallback candidates for other formats
    $candidates = [
        $payload['output']['video_url'] ?? null,
        $payload['data']['output']['video_url'] ?? null,
        $payload['data']['output']['url'] ?? null,
        $payload['output']['url'] ?? null,
        $payload['result']['video_url'] ?? null,
        $payload['data']['result']['video_url'] ?? null,
        $payload['task']['output']['video_url'] ?? null,
        $payload['video_url'] ?? null,
    ];
    
    foreach ($candidates as $url) {
        if (is_string($url) && filter_var($url, FILTER_VALIDATE_URL)) {
            return $url;
        }
    }
    
    return null;
}

/**
 * Download video to WordPress Media Library
 * 
 * @param string $video_url Video URL
 * @param string $filename Filename
 * @return array Result with attachment_id and media_url
 */
function waic_kling_download_video_to_media(string $video_url, string $filename = 'kling-video.mp4'): array {
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    waic_kling_log('download_start', ['url' => $video_url, 'filename' => $filename]);
    
    $tmp = download_url($video_url, 300);
    if (is_wp_error($tmp)) {
        return ['ok' => false, 'error' => $tmp->get_error_message()];
    }

    $file_array = [
        'name'     => sanitize_file_name($filename),
        'tmp_name' => $tmp,
    ];

    $attach_id = media_handle_sideload($file_array, 0);
    if (is_wp_error($attach_id)) {
        @unlink($tmp);
        return ['ok' => false, 'error' => $attach_id->get_error_message()];
    }

    $media_url = wp_get_attachment_url($attach_id);
    
    waic_kling_log('download_complete', ['attachment_id' => $attach_id, 'url' => $media_url]);

    return [
        'ok'            => true,
        'attachment_id' => (int)$attach_id,
        'media_url'     => $media_url,
    ];
}

/**
 * Upload video to R2 (if R2 helper exists)
 * 
 * @param string $video_url Source video URL
 * @param string $filename Target filename
 * @return array Result with R2 URL
 */
function waic_kling_upload_video_to_r2(string $video_url, string $filename = 'kling-video.mp4'): array {
    // Check if R2 helper available
    if (!function_exists('twf_upload_to_r2_from_url')) {
        return ['ok' => false, 'error' => 'R2 helper not available'];
    }
    
    waic_kling_log('r2_upload_start', ['url' => $video_url, 'filename' => $filename]);
    
    // Download to temp first
    $tmp = download_url($video_url, 300);
    if (is_wp_error($tmp)) {
        return ['ok' => false, 'error' => $tmp->get_error_message()];
    }
    
    // Upload to R2
    $result = twf_upload_to_r2_from_url($video_url, 'videos/' . $filename);
    
    @unlink($tmp);
    
    if (isset($result['url'])) {
        waic_kling_log('r2_upload_complete', ['r2_url' => $result['url']]);
        return [
            'ok' => true,
            'r2_url' => $result['url'],
            'result' => $result,
        ];
    }
    
    return ['ok' => false, 'error' => 'R2 upload failed', 'result' => $result];
}

/**
 * Transient key helper for job storage
 * 
 * @param string $job_id Job ID
 * @return string Transient key
 */
function waic_kling_job_key(string $job_id): string {
    return 'waic_kling_job_' . md5($job_id);
}

/**
 * Get task types
 * 
 * @return array Task types
 */
function waic_kling_get_task_types(): array {
    return [
        'text_to_video' => __('Text to Video', 'bizcity-video-kling'),
        'image_to_video' => __('Image to Video', 'bizcity-video-kling'),
    ];
}

/**
 * Get aspect ratios
 * 
 * @return array Aspect ratios
 */
function waic_kling_get_aspect_ratios(): array {
    return [
        '16:9' => __('16:9 (Landscape)', 'bizcity-video-kling'),
        '9:16' => __('9:16 (Portrait/Social)', 'bizcity-video-kling'),
        '1:1'  => __('1:1 (Square)', 'bizcity-video-kling'),
    ];
}
