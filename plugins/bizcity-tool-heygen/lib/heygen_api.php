<?php
/**
 * HeyGen API Library
 *
 * Wrapper functions for HeyGen REST API v2.
 * Docs: https://docs.heygen.com/reference
 *
 * @package BizCity_Tool_HeyGen
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Make an authenticated request to HeyGen API
 *
 * @param string $method   HTTP method (GET, POST, DELETE)
 * @param string $path     API path (e.g. /v2/video/generate)
 * @param array  $body     Request body (for POST)
 * @param array  $settings ['api_key' => ..., 'endpoint' => ...]
 * @return array ['ok' => bool, 'data' => ..., 'error' => ...]
 */
function bizcity_heygen_api_request( $method, $path, $body = [], $settings = [] ) {
    $api_key  = $settings['api_key'] ?? get_option( 'bizcity_tool_heygen_api_key', '' );
    $endpoint = $settings['endpoint'] ?? get_option( 'bizcity_tool_heygen_endpoint', 'https://api.heygen.com' );

    if ( empty( $api_key ) ) {
        return [ 'ok' => false, 'error' => 'HeyGen API key chưa được cấu hình.' ];
    }

    $url = rtrim( $endpoint, '/' ) . $path;

    $args = [
        'method'  => strtoupper( $method ),
        'headers' => [
            'X-Api-Key'   => $api_key,
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        ],
        'timeout' => 60,
    ];

    if ( ! empty( $body ) && in_array( $args['method'], [ 'POST', 'PUT', 'PATCH' ], true ) ) {
        $args['body'] = wp_json_encode( $body );
    }

    $response = wp_remote_request( $url, $args );

    if ( is_wp_error( $response ) ) {
        return [ 'ok' => false, 'error' => $response->get_error_message() ];
    }

    $code = wp_remote_retrieve_response_code( $response );
    $raw  = wp_remote_retrieve_body( $response );
    $data = json_decode( $raw, true );

    if ( $code >= 200 && $code < 300 ) {
        return [ 'ok' => true, 'data' => $data, 'http_code' => $code ];
    }

    $error_msg = $data['message'] ?? $data['error']['message'] ?? $data['error'] ?? '';
    if ( empty( $error_msg ) ) {
        // Non-JSON response — use raw body (truncated)
        $error_msg = "HTTP {$code}" . ( $raw ? ': ' . mb_strimwidth( $raw, 0, 200, '...' ) : '' );
    }
    if ( is_array( $error_msg ) ) {
        $error_msg = wp_json_encode( $error_msg, JSON_UNESCAPED_UNICODE );
    }
    return [ 'ok' => false, 'error' => $error_msg, 'data' => $data, 'http_code' => $code ];
}

/* ═══════════════════════════════════════════════════════
 *  Voice Cloning
 * ═══════════════════════════════════════════════════════ */

/**
 * Clone a voice from audio sample URL
 *
 * HeyGen /v2/voices/clone requires multipart/form-data with 'file' field.
 * We download the audio first, then upload as multipart.
 *
 * @param string $audio_url    URL of the voice sample audio file
 * @param string $voice_name   Display name for the cloned voice
 * @return array ['ok' => bool, 'voice_id' => '...', 'error' => '...']
 */
function bizcity_heygen_clone_voice( $audio_url, $voice_name = 'BizCity Voice' ) {
    $api_key  = get_option( 'bizcity_tool_heygen_api_key', '' );
    $endpoint = get_option( 'bizcity_tool_heygen_endpoint', 'https://api.heygen.com' );

    if ( empty( $api_key ) ) {
        return [ 'ok' => false, 'error' => 'HeyGen API key chưa được cấu hình.' ];
    }

    // Download audio file to temp
    $tmp_file = download_url( $audio_url, 60 );
    if ( is_wp_error( $tmp_file ) ) {
        return [ 'ok' => false, 'error' => 'Không thể tải file audio: ' . $tmp_file->get_error_message() ];
    }

    // Determine filename and mime
    $filename = basename( wp_parse_url( $audio_url, PHP_URL_PATH ) ) ?: 'voice_sample.m4a';
    $mime     = wp_check_filetype( $filename )['type'] ?: 'audio/mp4';

    // Build multipart boundary
    $boundary = wp_generate_password( 24, false );
    $body     = '';

    // File field
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Disposition: form-data; name=\"file\"; filename=\"{$filename}\"\r\n";
    $body .= "Content-Type: {$mime}\r\n\r\n";
    $body .= file_get_contents( $tmp_file ) . "\r\n";

    // Voice name field
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Disposition: form-data; name=\"voice_name\"\r\n\r\n";
    $body .= $voice_name . "\r\n";

    $body .= "--{$boundary}--\r\n";

    // Clean up temp file
    @unlink( $tmp_file );

    $url = rtrim( $endpoint, '/' ) . '/v2/voices/clone';

    $response = wp_remote_post( $url, [
        'headers' => [
            'X-Api-Key'    => $api_key,
            'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
            'Accept'       => 'application/json',
        ],
        'body'    => $body,
        'timeout' => 120,
    ] );

    if ( is_wp_error( $response ) ) {
        return [ 'ok' => false, 'error' => $response->get_error_message() ];
    }

    $code = wp_remote_retrieve_response_code( $response );
    $data = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $code < 200 || $code >= 300 ) {
        $err = $data['message'] ?? $data['error']['message'] ?? $data['error'] ?? "HTTP {$code}";
        if ( is_array( $err ) ) $err = wp_json_encode( $err, JSON_UNESCAPED_UNICODE );
        return [ 'ok' => false, 'error' => $err, 'http_code' => $code, 'data' => $data ];
    }

    $voice_id = $data['data']['voice_id']
        ?? $data['voice_id']
        ?? '';

    if ( empty( $voice_id ) ) {
        return [ 'ok' => false, 'error' => 'No voice_id in response', 'data' => $data ];
    }

    return [ 'ok' => true, 'voice_id' => $voice_id, 'data' => $data ];
}

/**
 * List available voices
 */
function bizcity_heygen_list_voices() {
    return bizcity_heygen_api_request( 'GET', '/v2/voices' );
}

/**
 * Delete a cloned voice
 */
function bizcity_heygen_delete_voice( $voice_id ) {
    return bizcity_heygen_api_request( 'DELETE', '/v2/voices/' . urlencode( $voice_id ) );
}

/* ═══════════════════════════════════════════════════════
 *  Video Generation
 *
 *  Try 1: POST /v2/video (Avatar IV — flat, accepts image_url)
 *  Try 2: POST /v2/video/generate (legacy — nested video_inputs)
 *
 *  Docs: https://docs.heygen.com/reference/create-video-1
 * ═══════════════════════════════════════════════════════ */

/**
 * Create a lipsync video — tries Avatar IV first, falls back to legacy
 *
 * @param array $params
 * @return array ['ok' => bool, 'video_id' => '...', 'error' => '...']
 */
function bizcity_heygen_create_video( $params ) {
    $mode = $params['mode'] ?? 'text';

    // ── Build Avatar IV body (POST /v2/video) ──
    $body = [];

    if ( ! empty( $params['avatar_id'] ) ) {
        $body['avatar_id'] = $params['avatar_id'];
    } elseif ( ! empty( $params['image_url'] ) ) {
        $body['image_url'] = $params['image_url'];
    }

    if ( $mode === 'audio' && ! empty( $params['audio_url'] ) ) {
        $body['audio_url'] = $params['audio_url'];
    } else {
        $body['script']   = $params['script'] ?? '';
        $body['voice_id'] = $params['voice_id'] ?? '';
    }

    $body['resolution']   = $params['resolution'] ?? '720p';
    $body['aspect_ratio'] = $params['aspect_ratio'] ?? '16:9';

    if ( ! empty( $params['title'] ) ) {
        $body['title'] = $params['title'];
    }

    error_log( '[BTHG] create_video request body: ' . wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );

    // ── Try 1: POST /v2/video (Avatar IV) ──
    $result = bizcity_heygen_api_request( 'POST', '/v2/video', $body );

    error_log( '[BTHG] /v2/video response: HTTP ' . ( $result['http_code'] ?? '?' ) . ' — ' . wp_json_encode( $result['data'] ?? $result['error'] ?? 'empty', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );

    // If /v2/video works, use it
    if ( ! empty( $result['ok'] ) ) {
        return bizcity_heygen_extract_video_id( $result );
    }

    // ── Try 2: POST /v2/video/generate (legacy fallback) ──
    $http_code = $result['http_code'] ?? 0;
    if ( in_array( $http_code, [ 404, 405, 501 ], true ) ) {
        error_log( '[BTHG] /v2/video returned ' . $http_code . ', trying /v2/video/generate fallback...' );

        $legacy_body = bizcity_heygen_build_legacy_body( $params, $mode );
        if ( $legacy_body ) {
            $result2 = bizcity_heygen_api_request( 'POST', '/v2/video/generate', $legacy_body );
            error_log( '[BTHG] /v2/video/generate response: HTTP ' . ( $result2['http_code'] ?? '?' ) . ' — ' . wp_json_encode( $result2['data'] ?? $result2['error'] ?? 'empty', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );

            if ( ! empty( $result2['ok'] ) ) {
                return bizcity_heygen_extract_video_id( $result2 );
            }

            // Both failed — return the more informative error
            $err2 = $result2['error'] ?? 'Unknown';
            return [ 'ok' => false, 'error' => "v2/video: HTTP {$http_code}. v2/video/generate: {$err2}", 'data' => $result2['data'] ?? null ];
        }
    }

    return [ 'ok' => false, 'error' => $result['error'] ?? 'Create video failed', 'data' => $result['data'] ?? null ];
}

/**
 * Extract video_id from API response
 */
function bizcity_heygen_extract_video_id( $result ) {
    $video_id = $result['data']['data']['video_id']
        ?? $result['data']['video_id']
        ?? '';

    if ( empty( $video_id ) ) {
        return [ 'ok' => false, 'error' => 'No video_id in response', 'data' => $result['data'] ];
    }

    return [ 'ok' => true, 'video_id' => $video_id, 'data' => $result['data'] ];
}

/**
 * Build legacy /v2/video/generate body from flat params
 */
function bizcity_heygen_build_legacy_body( $params, $mode ) {
    // Character
    $character = [];
    if ( ! empty( $params['avatar_id'] ) ) {
        $aid = $params['avatar_id'];
        $character['type']      = 'talking_photo';
        $character['talking_photo_id'] = $aid;
    } elseif ( ! empty( $params['image_url'] ) ) {
        // Legacy endpoint doesn't support image_url directly — can't fallback
        return null;
    } else {
        return null;
    }

    // Voice
    $voice = [];
    if ( $mode === 'audio' && ! empty( $params['audio_url'] ) ) {
        $voice['type']      = 'audio';
        $voice['audio_url'] = $params['audio_url'];
    } else {
        $voice['type']     = 'text';
        $voice['input']    = $params['script'] ?? '';
        $voice['voice_id'] = $params['voice_id'] ?? '';
    }

    return [
        'video_inputs' => [
            [
                'character' => $character,
                'voice'     => $voice,
            ],
        ],
    ];
}

/* ═══════════════════════════════════════════════════════
 *  Photo Avatar (Upload → Create Group → Train → Use)
 *
 *  Docs: https://docs.heygen.com/docs/photo-avatars-api
 * ═══════════════════════════════════════════════════════ */

/**
 * Upload an image file to HeyGen asset storage
 *
 * Endpoint: POST https://upload.heygen.com/v1/asset
 * Body: raw binary image data, Content-Type must match the file.
 *
 * @param string $image_path  Absolute local file path (or URL to download first)
 * @param string $mime_type   e.g. image/jpeg, image/png
 * @return array ['ok' => bool, 'asset_id' => '...']
 */
function bizcity_heygen_upload_asset( $image_path, $mime_type = 'image/jpeg' ) {
    $api_key = get_option( 'bizcity_tool_heygen_api_key', '' );
    if ( empty( $api_key ) ) {
        return [ 'ok' => false, 'error' => 'HeyGen API key chưa được cấu hình.' ];
    }

    if ( ! file_exists( $image_path ) ) {
        return [ 'ok' => false, 'error' => 'File không tồn tại: ' . $image_path ];
    }

    $binary = file_get_contents( $image_path );
    if ( $binary === false || strlen( $binary ) === 0 ) {
        return [ 'ok' => false, 'error' => 'Không đọc được file hoặc file rỗng.' ];
    }

    error_log( '[BTHG] upload_asset: file=' . $image_path . ' size=' . strlen( $binary ) . ' mime=' . $mime_type );

    $url = 'https://upload.heygen.com/v1/asset';

    $response = wp_remote_post( $url, [
        'headers' => [
            'X-Api-Key'    => $api_key,
            'Content-Type' => $mime_type,
            'Accept'       => 'application/json',
        ],
        'body'    => $binary,
        'timeout' => 120,
    ] );

    if ( is_wp_error( $response ) ) {
        return [ 'ok' => false, 'error' => $response->get_error_message() ];
    }

    $code = wp_remote_retrieve_response_code( $response );
    $raw  = wp_remote_retrieve_body( $response );
    $data = json_decode( $raw, true );

    error_log( '[BTHG] upload_asset => HTTP ' . $code . ' | RAW: ' . mb_strimwidth( $raw, 0, 1000, '...' ) );

    if ( $code < 200 || $code >= 300 ) {
        $err = $data['message'] ?? $data['error'] ?? "HTTP {$code}";
        return [ 'ok' => false, 'error' => is_array( $err ) ? wp_json_encode( $err ) : $err, 'data' => $data ];
    }

    // HeyGen upload response — extract image_key
    // Doc example format: "image/47b2367366d94ee79894ed1f692b33ae/original"
    // Response shapes seen in the wild:
    //   {"code":100, "data":{"id":"image/xxx/original","url":"https://..."}}
    //   {"data":{"image_key":"image/xxx/original","url":"https://..."}}
    //   {"data":"image/xxx/original"}  (data is a string directly)
    //   {"data":{"asset_id":"xxx","url":"https://..."}}

    $image_key = '';
    $d = $data['data'] ?? null;

    // Case: data is a string directly (e.g. "image/xxx/original")
    if ( is_string( $d ) && ! empty( $d ) && strpos( $d, 'http' ) !== 0 ) {
        $image_key = $d;
        error_log( '[BTHG] upload_asset: data is string directly: ' . $image_key );
    }

    // Case: data is an object — try key-like fields (NOT url, which is a full https link)
    if ( empty( $image_key ) && is_array( $d ) ) {
        foreach ( [ 'image_key', 'asset_id', 'id', 'key' ] as $field ) {
            if ( ! empty( $d[ $field ] ) && is_string( $d[ $field ] ) ) {
                $image_key = $d[ $field ];
                error_log( '[BTHG] upload_asset: found in data.' . $field . ': ' . $image_key );
                break;
            }
        }
    }

    // Case: fields at root level
    if ( empty( $image_key ) ) {
        foreach ( [ 'image_key', 'asset_id', 'id', 'key' ] as $field ) {
            if ( ! empty( $data[ $field ] ) && is_string( $data[ $field ] ) ) {
                $image_key = $data[ $field ];
                error_log( '[BTHG] upload_asset: found in root.' . $field . ': ' . $image_key );
                break;
            }
        }
    }

    // Fallback: try to extract from url field — strip domain to get path key
    // e.g. "https://files5.heygen.ai/image/xxx/original" → "image/xxx/original"
    if ( empty( $image_key ) ) {
        $url_val = '';
        if ( is_array( $d ) && ! empty( $d['url'] ) ) $url_val = $d['url'];
        if ( empty( $url_val ) && ! empty( $data['url'] ) ) $url_val = $data['url'];

        if ( $url_val && preg_match( '#(image/[a-f0-9]+/\w+)#i', $url_val, $m ) ) {
            $image_key = $m[1];
            error_log( '[BTHG] upload_asset: extracted from url path: ' . $image_key );
        }
    }

    if ( empty( $image_key ) ) {
        error_log( '[BTHG] upload_asset: FAILED to extract image_key! Full response: ' . $raw );
        return [ 'ok' => false, 'error' => 'No image_key in upload response. Raw: ' . mb_strimwidth( $raw, 0, 500, '...' ), 'data' => $data ];
    }

    error_log( '[BTHG] upload_asset: FINAL image_key = ' . $image_key );
    return [ 'ok' => true, 'image_key' => $image_key, 'data' => $data ];
}

/**
 * Create a Photo Avatar Group from an uploaded image
 *
 * POST /v2/photo_avatar/avatar_group/create
 * Body: { name, image_key }
 *
 * @param string $name       Avatar group name
 * @param string $image_key  Asset ID from upload_asset()
 * @return array ['ok' => bool, 'group_id' => '...', 'avatar_id' => '...']
 */
function bizcity_heygen_create_avatar_group( $name, $image_key ) {
    $body = [
        'name'      => $name,
        'image_key' => $image_key,
    ];

    error_log( '[BTHG] create_avatar_group: ' . wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );

    $result = bizcity_heygen_api_request( 'POST', '/v2/photo_avatar/avatar_group/create', $body );

    error_log( '[BTHG] create_avatar_group response: HTTP ' . ( $result['http_code'] ?? '?' ) . ' | ' . wp_json_encode( $result['data'] ?? $result['error'] ?? 'empty', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );

    if ( empty( $result['ok'] ) ) {
        return [ 'ok' => false, 'error' => $result['error'] ?? 'Create avatar group failed', 'data' => $result['data'] ?? null ];
    }

    $d = $result['data']['data'] ?? $result['data'] ?? [];

    $group_id  = $d['group_id'] ?? $d['id'] ?? '';
    $avatar_id = $d['avatar_id'] ?? $d['talking_photo_id'] ?? '';

    error_log( '[BTHG] create_avatar_group: group_id=' . $group_id . ' avatar_id=' . $avatar_id );

    return [
        'ok'        => true,
        'group_id'  => $group_id,
        'avatar_id' => $avatar_id,
        'data'      => $d,
    ];
}

/**
 * Add looks (images) to an existing Photo Avatar Group
 *
 * POST /v2/photo_avatar/avatar_group/add
 * Body: { group_id, image_keys: [...], name }
 *
 * @param string $group_id   Avatar group ID
 * @param array  $image_keys Array of image_key strings
 * @param string $name       Look name
 * @return array
 */
function bizcity_heygen_add_looks_to_group( $group_id, $image_keys, $name = 'Default Look' ) {
    $body = [
        'group_id'   => $group_id,
        'image_keys' => $image_keys,
        'name'       => $name,
    ];

    error_log( '[BTHG] add_looks_to_group: ' . wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );

    $result = bizcity_heygen_api_request( 'POST', '/v2/photo_avatar/avatar_group/add', $body );

    error_log( '[BTHG] add_looks_to_group response: HTTP ' . ( $result['http_code'] ?? '?' ) . ' | ' . wp_json_encode( $result['data'] ?? $result['error'] ?? 'empty', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );

    if ( empty( $result['ok'] ) ) {
        return [ 'ok' => false, 'error' => $result['error'] ?? 'Add looks failed', 'data' => $result['data'] ?? null ];
    }

    return [ 'ok' => true, 'data' => $result['data'] ];
}

/**
 * Train a Photo Avatar Group
 *
 * POST /v2/photo_avatar/train
 * Body: { group_id }
 *
 * @param string $group_id
 * @return array ['ok' => bool]
 */
function bizcity_heygen_train_avatar_group( $group_id ) {
    $body = [ 'group_id' => $group_id ];

    error_log( '[BTHG] train_avatar_group: group_id=' . $group_id );

    $result = bizcity_heygen_api_request( 'POST', '/v2/photo_avatar/train', $body );

    error_log( '[BTHG] train_avatar_group response: ' . wp_json_encode( $result['data'] ?? $result['error'] ?? 'empty', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );

    if ( empty( $result['ok'] ) ) {
        return [ 'ok' => false, 'error' => $result['error'] ?? 'Train avatar group failed', 'data' => $result['data'] ?? null ];
    }

    return [ 'ok' => true, 'data' => $result['data'] ];
}

/**
 * Get Photo Avatar training status
 *
 * GET /v2/photo_avatar/train/status/{group_id}
 *
 * @param string $group_id
 * @return array ['ok' => bool, 'status' => 'pending'|'ready', 'avatar_id' => '...']
 */
function bizcity_heygen_get_training_status( $group_id ) {
    $result = bizcity_heygen_api_request( 'GET', '/v2/photo_avatar/train/status/' . urlencode( $group_id ) );

    error_log( '[BTHG] training_status ' . $group_id . ' => ' . wp_json_encode( $result['data'] ?? $result['error'] ?? 'empty', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );

    if ( empty( $result['ok'] ) ) {
        return [ 'ok' => false, 'error' => $result['error'] ?? 'Get training status failed' ];
    }

    $d = $result['data']['data'] ?? $result['data'] ?? [];

    // For Photo Avatars, the group_id IS the avatar_id / talking_photo_id
    $avatar_id = $d['avatar_id'] ?? $d['talking_photo_id'] ?? $d['group_id'] ?? $d['id'] ?? $group_id;

    return [
        'ok'        => true,
        'status'    => $d['status'] ?? 'unknown',
        'avatar_id' => $avatar_id,
        'group_id'  => $d['group_id'] ?? $group_id,
        'data'      => $d,
    ];
}

/**
 * Get Photo Avatar details
 *
 * GET /v2/photo_avatar/{id}
 *
 * @param string $avatar_id
 * @return array
 */
function bizcity_heygen_get_photo_avatar( $avatar_id ) {
    return bizcity_heygen_api_request( 'GET', '/v2/photo_avatar/' . urlencode( $avatar_id ) );
}

/**
 * Full pipeline: Upload image → Create Group → Train
 * Returns group_id immediately; caller should poll training status.
 *
 * @param string $image_path  Local file path of image
 * @param string $mime_type   MIME type
 * @param string $name        Avatar name
 * @return array ['ok' => bool, 'group_id' => '...', 'avatar_id' => '...', 'status' => 'pending']
 */
function bizcity_heygen_push_photo_avatar( $image_path, $mime_type, $name ) {
    // Step 1: Upload asset
    error_log( '[BTHG] push_photo_avatar: START — name=' . $name . ' file=' . $image_path . ' mime=' . $mime_type );

    $upload = bizcity_heygen_upload_asset( $image_path, $mime_type );
    if ( empty( $upload['ok'] ) ) {
        return [ 'ok' => false, 'error' => 'Upload thất bại: ' . ( $upload['error'] ?? 'Unknown' ), 'step' => 'upload' ];
    }
    $image_key = $upload['image_key'];
    error_log( '[BTHG] push_photo_avatar: Step 1 OK — image_key=' . $image_key );

    // Step 2: Create avatar group
    $group = bizcity_heygen_create_avatar_group( $name, $image_key );
    if ( empty( $group['ok'] ) ) {
        return [ 'ok' => false, 'error' => 'Tạo avatar group thất bại: ' . ( $group['error'] ?? 'Unknown' ), 'step' => 'create_group', 'image_key' => $image_key ];
    }

    $group_id  = $group['group_id'];
    $avatar_id = $group['avatar_id'];
    error_log( '[BTHG] push_photo_avatar: Step 2 OK — group_id=' . $group_id . ' avatar_id=' . $avatar_id );

    // Step 3: Train — retry with delay because look needs processing time after group creation
    // The create_avatar_group response shows status=pending, meaning the look is still uploading.
    // We retry up to 6 times with 5s delay (max ~30s wait) for the look to finish processing.
    $max_retries   = 6;
    $retry_delay   = 5; // seconds
    $train         = null;
    $last_error    = '';

    for ( $attempt = 1; $attempt <= $max_retries; $attempt++ ) {
        if ( $attempt > 1 ) {
            error_log( '[BTHG] push_photo_avatar: Train attempt ' . $attempt . '/' . $max_retries . ' — waiting ' . $retry_delay . 's for look processing...' );
            sleep( $retry_delay );
        }

        $train = bizcity_heygen_train_avatar_group( $group_id );

        if ( ! empty( $train['ok'] ) ) {
            error_log( '[BTHG] push_photo_avatar: Step 3 OK — training started (attempt ' . $attempt . ')' );
            break;
        }

        $last_error = $train['error'] ?? 'Unknown';

        // Only retry if the error is about image not ready; other errors are fatal
        if ( stripos( $last_error, 'No valid image' ) === false && stripos( $last_error, 'not completed' ) === false ) {
            error_log( '[BTHG] push_photo_avatar: Train fatal error (no retry): ' . $last_error );
            break;
        }

        error_log( '[BTHG] push_photo_avatar: Train attempt ' . $attempt . ' failed: ' . $last_error );
    }

    if ( empty( $train['ok'] ) ) {
        return [
            'ok'        => false,
            'error'     => 'Train thất bại (sau ' . $max_retries . ' lần thử): ' . $last_error,
            'step'      => 'train',
            'group_id'  => $group_id,
            'avatar_id' => $avatar_id,
            'image_key' => $image_key,
        ];
    }

    return [
        'ok'        => true,
        'group_id'  => $group_id,
        'avatar_id' => $avatar_id,
        'image_key' => $image_key,
        'status'    => 'pending',
    ];
}

/**
 * List avatars from HeyGen account (includes photo avatars / talking photos)
 *
 * @return array ['ok' => bool, 'photos' => [...], 'error' => '...']
 */
function bizcity_heygen_list_talking_photos() {
    $list = [];

    // 1. Fetch user's uploaded Photo Avatars first (these go on top)
    $pa_result = bizcity_heygen_list_photo_avatars();
    if ( ! empty( $pa_result['ok'] ) ) {
        foreach ( $pa_result['avatars'] as $pa ) {
            // HeyGen may use various field names across API versions
            $gid = $pa['group_id'] ?? $pa['avatar_group_id'] ?? $pa['photo_avatar_id'] ?? $pa['id'] ?? '';

            // Image URL: check looks array first, then direct fields
            $img = '';
            if ( ! empty( $pa['looks'] ) && is_array( $pa['looks'] ) ) {
                $first_look = reset( $pa['looks'] );
                $img = $first_look['image_url'] ?? $first_look['circle_image'] ?? '';
            }
            if ( empty( $img ) ) {
                $img = $pa['image_url'] ?? $pa['preview_image_url'] ?? $pa['circle_image'] ?? '';
            }

            $list[] = [
                'avatar_id'         => $gid,
                'avatar_name'       => $pa['name'] ?? $pa['avatar_name'] ?? 'Photo Avatar',
                'talking_photo_id'  => $gid,
                'preview_image_url' => $img,
                'type'              => 'photo_avatar',
                'status'            => $pa['status'] ?? $pa['training_status'] ?? '',
            ];
        }
    }

    // 2. Fetch HeyGen library avatars
    $result = bizcity_heygen_api_request( 'GET', '/v2/avatars' );

    if ( ! empty( $result['ok'] ) ) {
        $avatars = $result['data']['data']['avatars']
            ?? $result['data']['avatars']
            ?? $result['data']['data']
            ?? [];

        foreach ( $avatars as $a ) {
            $list[] = [
                'avatar_id'         => $a['avatar_id'] ?? '',
                'avatar_name'       => $a['avatar_name'] ?? $a['name'] ?? '',
                'talking_photo_id'  => $a['talking_photo_id'] ?? '',
                'preview_image_url' => $a['preview_image_url'] ?? $a['image_url'] ?? '',
                'type'              => $a['type'] ?? 'unknown',
                'status'            => '',
            ];
        }
    }

    if ( empty( $list ) ) {
        $err = $result['error'] ?? $pa_result['error'] ?? 'No avatars found';
        return [ 'ok' => false, 'error' => $err, 'data' => $result['data'] ?? null ];
    }

    return [ 'ok' => true, 'photos' => $list ];
}

/**
 * List user's uploaded Photo Avatars
 *
 * Tries multiple HeyGen API endpoints:
 *   1) GET /v2/photo_avatar
 *   2) GET /v2/photo_avatars
 *
 * @return array ['ok' => bool, 'avatars' => [...]]
 */
function bizcity_heygen_list_photo_avatars() {
    // Try multiple possible endpoints — HeyGen uses /v2/photo_avatar/... pattern for create/train
    $endpoints = [ '/v2/photo_avatar', '/v2/photo_avatars' ];
    $result    = null;

    foreach ( $endpoints as $ep ) {
        $result = bizcity_heygen_api_request( 'GET', $ep );
        $http   = $result['http_code'] ?? 0;
        error_log( '[BTHG] list_photo_avatars try ' . $ep . ' => HTTP ' . $http . ' | ' . wp_json_encode( $result['data'] ?? $result['error'] ?? 'empty', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );

        if ( ! empty( $result['ok'] ) ) {
            break; // found working endpoint
        }
        // Only try next endpoint on 404/405
        if ( ! in_array( $http, [ 404, 405, 501 ], true ) ) {
            break;
        }
    }

    if ( empty( $result['ok'] ) ) {
        error_log( '[BTHG] list_photo_avatars ALL endpoints failed: ' . ( $result['error'] ?? 'Unknown' ) );
        return [ 'ok' => false, 'error' => $result['error'] ?? 'List photo avatars failed' ];
    }

    // Parse response — try many possible structures
    $d = $result['data']['data'] ?? $result['data'] ?? [];
    $avatars = $d['photo_avatars'] ?? $d['avatar_groups'] ?? $d['avatars'] ?? $d['list'] ?? [];

    // If $d is a flat indexed array (no nested key), use it directly
    if ( empty( $avatars ) && is_array( $d ) && ! empty( $d ) && isset( $d[0] ) ) {
        $avatars = $d;
    }

    // If $avatars is an associative array (single item), wrap it
    if ( ! empty( $avatars ) && isset( $avatars['id'] ) ) {
        $avatars = [ $avatars ];
    }

    error_log( '[BTHG] list_photo_avatars: found ' . count( $avatars ) . ' photo avatar(s)' );

    return [ 'ok' => true, 'avatars' => $avatars ];
}

/**
 * Get video generation status
 *
 * Tries GET /v2/videos/{video_id} first, falls back to GET /v1/video_status.get
 *
 * @param string $video_id HeyGen video_id
 * @return array ['ok' => bool, 'status' => '...', 'video_url' => '...']
 */
function bizcity_heygen_get_video_status( $video_id ) {
    // Try 1: GET /v2/videos/{video_id}
    $result = bizcity_heygen_api_request( 'GET', '/v2/videos/' . urlencode( $video_id ) );

    error_log( '[BTHG] get_video_status /v2/videos/' . $video_id . ' => HTTP ' . ( $result['http_code'] ?? '?' ) . ' | ' . wp_json_encode( $result['data'] ?? $result['error'] ?? 'empty', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );

    // Fallback: GET /v1/video_status.get
    $http_code = $result['http_code'] ?? 0;
    if ( empty( $result['ok'] ) && in_array( $http_code, [ 404, 405, 501 ], true ) ) {
        error_log( '[BTHG] /v2/videos/ returned ' . $http_code . ', trying /v1/video_status.get fallback...' );
        $result = bizcity_heygen_api_request( 'GET', '/v1/video_status.get?video_id=' . urlencode( $video_id ) );
        error_log( '[BTHG] /v1/video_status.get => HTTP ' . ( $result['http_code'] ?? '?' ) . ' | ' . wp_json_encode( $result['data'] ?? $result['error'] ?? 'empty', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
    }

    if ( empty( $result['ok'] ) ) {
        return [ 'ok' => false, 'error' => $result['error'] ?? 'Get status failed', 'http_code' => $result['http_code'] ?? 0 ];
    }

    $data   = $result['data']['data'] ?? $result['data'] ?? [];
    $status = $data['status'] ?? 'unknown';

    return [
        'ok'        => true,
        'status'    => $status,
        'video_url' => $data['video_url'] ?? '',
        'data'      => $data,
    ];
}

/**
 * Normalize HeyGen status to our internal states
 */
function bizcity_heygen_normalize_status( $heygen_status ) {
    $map = [
        'completed' => 'completed',
        'success'   => 'completed',
        'done'      => 'completed',
        'processing' => 'processing',
        'pending'    => 'queued',
        'waiting'    => 'queued',
        'failed'     => 'failed',
        'error'      => 'failed',
    ];
    return $map[ strtolower( $heygen_status ) ] ?? 'processing';
}

/**
 * Download video to WordPress Media Library
 *
 * @param string $video_url   Remote video URL
 * @param string $filename    Desired filename
 * @return array ['ok' => bool, 'url' => '...', 'attachment_id' => int]
 */
function bizcity_heygen_download_to_media( $video_url, $filename = '' ) {
    if ( empty( $video_url ) ) {
        return [ 'ok' => false, 'error' => 'Empty video URL' ];
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $tmp = download_url( $video_url, 120 );

    if ( is_wp_error( $tmp ) ) {
        return [ 'ok' => false, 'error' => $tmp->get_error_message() ];
    }

    if ( empty( $filename ) ) {
        $filename = 'heygen-video-' . time() . '.mp4';
    }

    $file_array = [
        'name'     => sanitize_file_name( $filename ),
        'tmp_name' => $tmp,
    ];

    $attach_id = media_handle_sideload( $file_array, 0 );

    if ( is_wp_error( $attach_id ) ) {
        if ( file_exists( $tmp ) ) {
            wp_delete_file( $tmp );
        }
        return [ 'ok' => false, 'error' => $attach_id->get_error_message() ];
    }

    return [
        'ok'            => true,
        'url'           => wp_get_attachment_url( $attach_id ),
        'media_url'     => wp_get_attachment_url( $attach_id ),
        'attachment_id' => $attach_id,
    ];
}

/* ══════════════════════════════════════════════════════════════════
 *  Video Avatar — Upload video + create training + poll status
 * ══════════════════════════════════════════════════════════════════ */

/**
 * Upload a video asset to HeyGen for avatar training.
 *
 * Uses the same upload endpoint as images: https://upload.heygen.com/v1/asset
 * Content-Type is the video mime type (binary body, NOT multipart).
 *
 * @param string $video_path  Local file path.
 * @param string $mime_type   e.g. 'video/mp4'.
 * @return array ['ok' => bool, 'video_key' => '...' ]
 */
function bizcity_heygen_upload_video_asset( $video_path, $mime_type = 'video/mp4' ) {
    $api_key = get_option( 'bizcity_tool_heygen_api_key', '' );

    if ( empty( $api_key ) ) {
        return [ 'ok' => false, 'error' => 'API key chưa cấu hình.' ];
    }

    if ( ! file_exists( $video_path ) ) {
        return [ 'ok' => false, 'error' => 'File không tồn tại: ' . $video_path ];
    }

    $binary = file_get_contents( $video_path );
    if ( $binary === false || strlen( $binary ) === 0 ) {
        return [ 'ok' => false, 'error' => 'Không đọc được file hoặc file rỗng.' ];
    }

    error_log( '[BTHG] upload_video_asset: file=' . $video_path . ' size=' . strlen( $binary ) . ' mime=' . $mime_type );

    $url = 'https://upload.heygen.com/v1/asset';

    $response = wp_remote_post( $url, [
        'headers' => [
            'X-Api-Key'    => $api_key,
            'Content-Type' => $mime_type,
            'Accept'       => 'application/json',
        ],
        'body'    => $binary,
        'timeout' => 300,
    ] );

    if ( is_wp_error( $response ) ) {
        return [ 'ok' => false, 'error' => $response->get_error_message() ];
    }

    $code = wp_remote_retrieve_response_code( $response );
    $raw  = wp_remote_retrieve_body( $response );
    $data = json_decode( $raw, true );

    error_log( '[BTHG] upload_video_asset => HTTP ' . $code . ' | RAW: ' . mb_strimwidth( $raw, 0, 1000, '...' ) );

    if ( $code < 200 || $code >= 300 ) {
        $err = $data['message'] ?? $data['error'] ?? "HTTP {$code}";
        return [ 'ok' => false, 'error' => is_array( $err ) ? wp_json_encode( $err ) : $err, 'http_code' => $code, 'data' => $data ];
    }

    // Extract video_key — same logic as image upload
    $video_key = '';
    $d = $data['data'] ?? null;

    if ( is_string( $d ) && ! empty( $d ) && strpos( $d, 'http' ) !== 0 ) {
        $video_key = $d;
    }

    if ( empty( $video_key ) && is_array( $d ) ) {
        foreach ( [ 'video_key', 'asset_id', 'id', 'key', 'image_key' ] as $field ) {
            if ( ! empty( $d[ $field ] ) && is_string( $d[ $field ] ) ) {
                $video_key = $d[ $field ];
                break;
            }
        }
    }

    if ( empty( $video_key ) ) {
        foreach ( [ 'video_key', 'asset_id', 'id', 'key' ] as $field ) {
            if ( ! empty( $data[ $field ] ) && is_string( $data[ $field ] ) ) {
                $video_key = $data[ $field ];
                break;
            }
        }
    }

    // Fallback: extract from url field
    if ( empty( $video_key ) ) {
        $url_val = '';
        if ( is_array( $d ) && ! empty( $d['url'] ) ) $url_val = $d['url'];
        if ( empty( $url_val ) && ! empty( $data['url'] ) ) $url_val = $data['url'];

        if ( $url_val && preg_match( '#((?:video|image)/[a-f0-9]+/\w+)#i', $url_val, $m ) ) {
            $video_key = $m[1];
        }
    }

    if ( empty( $video_key ) ) {
        error_log( '[BTHG] upload_video_asset: FAILED to extract video_key! Full response: ' . $raw );
        return [ 'ok' => false, 'error' => 'No video_key in upload response. Raw: ' . mb_strimwidth( $raw, 0, 500, '...' ), 'data' => $data ];
    }

    // Also extract the full URL (needed for some create endpoints)
    $video_url = '';
    if ( is_array( $d ) && ! empty( $d['url'] ) ) $video_url = $d['url'];
    if ( empty( $video_url ) && ! empty( $data['url'] ) ) $video_url = $data['url'];

    error_log( '[BTHG] upload_video_asset: FINAL video_key = ' . $video_key . ' video_url = ' . $video_url );
    return [ 'ok' => true, 'video_key' => $video_key, 'video_url' => $video_url, 'raw' => $data ];
}

/**
 * Create a video avatar on HeyGen using an uploaded video.
 *
 * Tries multiple endpoints:
 *   1. POST /v2/video_avatar  (newer)
 *   2. POST /v1/video_avatar.create (legacy)
 *   3. POST /v2/avatars (generic)
 *
 * @param string $name       Avatar name.
 * @param string $video_key  Asset ID from upload.
 * @return array ['ok' => bool, 'avatar_id' => '...']
 */
function bizcity_heygen_create_video_avatar( $name, $video_key, $video_url = '' ) {
    // Try multiple body shapes & endpoints
    $attempts = [
        [ '/v2/video_avatar', [ 'avatar_name' => $name, 'video_url' => $video_url ] ],
        [ '/v2/video_avatar', [ 'avatar_name' => $name, 'video_asset_id' => $video_key ] ],
        [ '/v2/video_avatar', [ 'name' => $name, 'video_url' => $video_url ] ],
        [ '/v2/video_avatar', [ 'name' => $name, 'video_asset_id' => $video_key ] ],
        [ '/v1/video_avatar.create', [ 'avatar_name' => $name, 'video_url' => $video_url ] ],
    ];

    $last_error = '';
    foreach ( $attempts as $i => $attempt ) {
        list( $path, $body ) = $attempt;
        error_log( '[BTHG] create_video_avatar attempt #' . ($i+1) . ': ' . $path . ' body=' . wp_json_encode( $body ) );

        $result = bizcity_heygen_api_request( 'POST', $path, $body );

        error_log( '[BTHG] create_video_avatar attempt #' . ($i+1) . ' => ok=' . ( $result['ok'] ? 'true' : 'false' ) . ' error=' . ( $result['error'] ?? '' ) . ' data=' . wp_json_encode( $result['data'] ?? [] ) );

        if ( $result['ok'] ) {
            $d = $result['data']['data'] ?? $result['data'] ?? [];
            $avatar_id = $d['avatar_id'] ?? $d['video_avatar_id'] ?? $d['id'] ?? '';

            error_log( '[BTHG] create_video_avatar SUCCESS via ' . $path . ' => avatar_id=' . $avatar_id );

            return [
                'ok'        => true,
                'avatar_id' => $avatar_id,
                'status'    => $d['status'] ?? 'pending',
                'raw'       => $d,
            ];
        }

        $last_error = $result['error'] ?? 'Unknown';

        // If error is not 404/403/405, no point trying other endpoints
        $http_code = $result['http_code'] ?? 0;
        if ( $http_code && ! in_array( $http_code, [ 403, 404, 405, 400 ], true ) ) {
            break;
        }
    }

    error_log( '[BTHG] create_video_avatar ALL attempts failed. Last error: ' . $last_error );
    return [ 'ok' => false, 'error' => $last_error ];
}

/**
 * Get video avatar training status.
 *
 * HeyGen API: GET /v2/video_avatar/{avatar_id}
 *
 * @param string $avatar_id  Video avatar ID.
 * @return array ['ok' => bool, 'status' => '...']
 */
function bizcity_heygen_get_video_avatar_status( $avatar_id ) {
    $result = bizcity_heygen_api_request( 'GET', '/v2/video_avatar/' . urlencode( $avatar_id ) );

    if ( ! $result['ok'] ) {
        return $result;
    }

    $d = $result['data']['data'] ?? $result['data'] ?? [];

    return [
        'ok'        => true,
        'status'    => strtolower( $d['status'] ?? 'pending' ),
        'avatar_id' => $d['avatar_id'] ?? $d['video_avatar_id'] ?? $avatar_id,
        'raw'       => $d,
    ];
}
