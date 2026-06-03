<?php
/**
 * BizCity_KG_AV_Adapter — audio/video Source Adapter (E0.AV).
 *
 * Pipeline:
 *   1. Adapter receives a local file path from the ingest pipeline.
 *   2. Tier gate via BizCity_Entitlement::can('learning.av').
 *   3. Side-load the file into the WP Media Library (if not already there)
 *      to obtain a publicly fetchable URL.
 *   4. Call BizCity_AV_Transcribe_Client → /bizcity/v1/tools/transcribe
 *      (Vision LLM via OpenRouter — Gateway-only per R-GW).
 *   5. Return canonical adapter shape with modality 'audio' or 'video'.
 *
 * The transcript is then chunked + embedded by the existing pipeline → KG passages.
 *
 * Caller may pass `$opts['attachment_id']` to skip the side-load step (the pipeline
 * already has the WP attachment ID — typical case from JSON ingest).
 *
 * Quotas (enforced server-side by the gateway, see BizCity_Entitlement::default_matrix):
 *   - free       : 1 av_file / day
 *   - paid (Pro) : 20 av_file / day
 *   - enterprise : effectively unlimited
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge\KGHub\Adapters
 * @since      PHASE-0.7 Wave E0.AV
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_KG_AV_Adapter implements BizCity_KG_Source_Adapter {

    const MAX_FILE_BYTES = 250 * 1024 * 1024; // 250 MB hard cap (gateway has its own)

    /** ext → kind (audio|video) */
    const AUDIO_EXTS = [ 'mp3', 'wav', 'm4a', 'aac', 'ogg', 'oga', 'opus', 'flac', '3gp', 'amr' ];
    const VIDEO_EXTS = [ 'mp4', 'mov', 'm4v', 'webm', 'mkv', 'avi', 'mpeg', 'mpg', '3gpp' ];

    public static function id() {
        return 'av';
    }

    public static function supports( $ext, $mime ) {
        $ext  = strtolower( (string) $ext );
        $mime = strtolower( (string) $mime );
        if ( in_array( $ext, self::AUDIO_EXTS, true ) ) return true;
        if ( in_array( $ext, self::VIDEO_EXTS, true ) ) return true;
        if ( strpos( $mime, 'audio/' ) === 0 ) return true;
        if ( strpos( $mime, 'video/' ) === 0 ) return true;
        return false;
    }

    public function extract( $file_path, array $opts ) {
        if ( ! is_string( $file_path ) || $file_path === '' || ! file_exists( $file_path ) ) {
            return new WP_Error( 'av_file_missing', 'Audio/Video source file not found.', [ 'http_status' => 400 ] );
        }
        if ( ! is_readable( $file_path ) ) {
            return new WP_Error( 'av_file_unreadable', 'Audio/Video file is not readable.', [ 'http_status' => 400 ] );
        }
        $size = (int) @filesize( $file_path );
        if ( $size > self::MAX_FILE_BYTES ) {
            return new WP_Error(
                'av_file_too_large',
                sprintf( 'Media file too large: %d bytes (max %d).', $size, self::MAX_FILE_BYTES ),
                [ 'http_status' => 413 ]
            );
        }

        // 1) Tier / quota gate (client-side fast-fail; gateway re-checks).
        $user_id = isset( $opts['user_id'] ) && intval( $opts['user_id'] ) > 0
            ? intval( $opts['user_id'] )
            : get_current_user_id();
        if ( $user_id > 0 && class_exists( 'BizCity_Entitlement' ) ) {
            if ( ! BizCity_Entitlement::can( $user_id, 'learning.av' ) ) {
                if ( method_exists( 'BizCity_Entitlement', 'record_blocked' ) ) {
                    BizCity_Entitlement::record_blocked( $user_id, [
                        'code'        => 'tier_required',
                        'modality'    => 'learning.av',
                        'feature'     => 'learning.av',
                        'plugin_name' => 'bizcity-twin-ai',
                    ] );
                }
                return new WP_Error(
                    'tier_required',
                    'Audio/Video learning is not enabled on your plan.',
                    [ 'http_status' => 402, 'feature' => 'learning.av' ]
                );
            }
            $quota = BizCity_Entitlement::check_quota( $user_id, 'av_file', 1 );
            if ( empty( $quota['ok'] ) ) {
                $reason = $quota['reason'] ?? 'tier_required';
                return new WP_Error(
                    $reason,
                    $reason === 'quota_exceeded_free'
                        ? sprintf(
                            'Daily audio/video quota reached (%d/%d). Upgrade to Pro for 20 files/day.',
                            intval( $quota['used_today'] ?? 0 ),
                            intval( $quota['free_day'] ?? 0 )
                        )
                        : 'Audio/Video learning requires an active plan.',
                    [
                        'http_status'    => 402,
                        'feature'        => 'learning.av',
                        'used_today'     => intval( $quota['used_today']     ?? 0 ),
                        'free_day'       => intval( $quota['free_day']       ?? 0 ),
                        'remaining_free' => intval( $quota['remaining_free'] ?? 0 ),
                    ]
                );
            }
        }

        // 2) Resolve a publicly fetchable URL for the gateway.
        $ext  = strtolower( (string) ( $opts['ext']  ?? pathinfo( $file_path, PATHINFO_EXTENSION ) ) );
        $mime = (string) ( $opts['mime'] ?? '' );
        if ( $mime === '' && function_exists( 'mime_content_type' ) ) {
            $mime = (string) @mime_content_type( $file_path );
        }
        $kind = in_array( $ext, self::VIDEO_EXTS, true ) || strpos( $mime, 'video/' ) === 0
            ? 'video' : 'audio';

        $attachment_id = isset( $opts['attachment_id'] ) ? intval( $opts['attachment_id'] ) : 0;
        $media_url     = '';

        if ( $attachment_id > 0 ) {
            // Bust object cache — third-party offloaders (R2/S3) often update
            // _wp_attached_file directly via DB without firing clean_attachment_cache().
            if ( function_exists( 'clean_attachment_cache' ) ) {
                clean_attachment_cache( $attachment_id );
            }
            wp_cache_delete( $attachment_id, 'post_meta' );
            $media_url = (string) wp_get_attachment_url( $attachment_id );
        }
        if ( $media_url === '' ) {
            $sideloaded = $this->sideload_to_media( $file_path, $opts );
            if ( is_wp_error( $sideloaded ) ) {
                return $sideloaded;
            }
            $attachment_id = (int) $sideloaded['attachment_id'];
            // Same cache-bust after sideload — async offloaders may have moved
            // the file between insert_attachment and our URL read.
            if ( function_exists( 'clean_attachment_cache' ) ) {
                clean_attachment_cache( $attachment_id );
            }
            wp_cache_delete( $attachment_id, 'post_meta' );
            $media_url = (string) wp_get_attachment_url( $attachment_id );
            if ( $media_url === '' ) {
                $media_url = (string) $sideloaded['url'];
            }
        }
        if ( $media_url === '' ) {
            return new WP_Error( 'av_no_public_url', 'Could not obtain a public URL for the media file.', [ 'http_status' => 500 ] );
        }

        // Allow site-level rewrite (e.g. R2/S3 proxy URL) before sending to gateway.
        $media_url = (string) apply_filters( 'bizcity_av_media_url', $media_url, $attachment_id, $opts );

        // 2b) Pre-flight HEAD probe — Gemini will return a 404 origin error after
        // burning latency, so verify the URL is fetchable from the public internet
        // first. Only WARN-fail on 4xx; allow other statuses (some CDNs block HEAD).
        $probe = wp_remote_head( $media_url, [
            'timeout'     => 6,
            'redirection' => 3,
            'user-agent'  => 'BizCity-AV-Adapter/1.0 preflight',
        ] );
        if ( ! is_wp_error( $probe ) ) {
            $code = (int) wp_remote_retrieve_response_code( $probe );
            if ( $code >= 400 && $code < 500 ) {
                return new WP_Error(
                    'av_url_unreachable',
                    sprintf( 'Media URL not reachable (HTTP %d): %s. Check that the file is public — if you use an offloader (R2/S3) make sure the URL rewrite filter is active so /wp-content/uploads/* serves through the CDN.', $code, $media_url ),
                    [
                        'http_status'   => 502,
                        'media_url'     => $media_url,
                        'probe_status'  => $code,
                        'attachment_id' => $attachment_id,
                    ]
                );
            }
        }

        // 3) Call gateway transcribe client.
        if ( ! class_exists( 'BizCity_AV_Transcribe_Client' ) ) {
            return new WP_Error( 'av_client_missing', 'BizCity_AV_Transcribe_Client class not loaded.', [ 'http_status' => 500 ] );
        }
        $client = BizCity_AV_Transcribe_Client::instance();
        if ( ! $client->is_configured() ) {
            return new WP_Error( 'av_not_configured', 'BizCity LLM gateway not configured (URL/API key missing).', [ 'http_status' => 503 ] );
        }

        $client_opts = [
            'mime'         => $mime,
            'lang'         => isset( $opts['av_lang'] ) ? (string) $opts['av_lang'] : 'auto',
            'prompt_hint'  => isset( $opts['av_prompt_hint'] ) ? (string) $opts['av_prompt_hint'] : '',
            'max_tokens'   => isset( $opts['av_max_tokens'] ) ? intval( $opts['av_max_tokens'] ) : 8000,
            'timeout'      => isset( $opts['av_timeout'] ) ? max( 60, intval( $opts['av_timeout'] ) ) : 180,
            'duration_sec' => isset( $opts['duration_sec'] ) ? intval( $opts['duration_sec'] ) : 0,
            'plugin_name'  => 'kg-hub/av-adapter',
        ];
        if ( ! empty( $opts['av_model'] ) ) {
            $client_opts['model'] = (string) $opts['av_model'];
        }

        $result = $client->transcribe( $media_url, $kind, $client_opts );
        if ( is_wp_error( $result ) ) {
            // Surface gateway errors as-is (already carry http_status).
            return $result;
        }

        $text = isset( $result['text'] ) ? (string) $result['text'] : '';
        if ( trim( $text ) === '' || trim( $text ) === '[NO_SPEECH_DETECTED]' ) {
            return new WP_Error(
                'av_no_speech',
                'No intelligible speech detected in the media file.',
                [ 'http_status' => 422, 'kind' => $kind ]
            );
        }

        // Sprint D — temporal chunking. The chunker tolerates missing markers
        // and falls back to a single segment if no timestamps are present.
        $segments = [];
        if ( class_exists( 'BizCity_KG_AV_Chunker' ) ) {
            $temporal = BizCity_KG_AV_Chunker::chunk( $text );
            $idx = 0;
            foreach ( $temporal as $seg ) {
                $segments[] = [
                    'page_num' => ++$idx, // 1-based ordinal so existing pipeline keys still work
                    'text'     => (string) $seg['text'],
                    'start_ts' => (int)    $seg['start_ts'],
                    'end_ts'   => (int)    $seg['end_ts'],
                    'speaker'  => isset( $seg['speaker'] ) ? $seg['speaker'] : null,
                    'is_scene' => ! empty( $seg['is_scene'] ),
                ];
            }
        }
        if ( empty( $segments ) ) {
            $segments = [ [ 'page_num' => 1, 'text' => $text, 'start_ts' => 0, 'end_ts' => 0 ] ];
        }

        return [
            'text'     => $text,
            'segments' => $segments,
            'assets'   => [],
            'modality' => $kind, // 'audio' | 'video'
            'meta'     => [
                'attachment_id'  => $attachment_id,
                'media_url'      => $media_url,
                'mime'           => $mime,
                'ext'            => $ext,
                'kind'           => $kind,
                'model'          => isset( $result['model'] ) ? (string) $result['model'] : '',
                'fallback_used'  => ! empty( $result['fallback_used'] ),
                'cost_usd'       => isset( $result['cost_usd'] ) ? (float) $result['cost_usd'] : 0.0,
                'latency_ms'     => isset( $result['latency_ms'] ) ? intval( $result['latency_ms'] ) : 0,
                'lang'           => $client_opts['lang'],
                'engine'         => 'vision_llm',
                'segment_count'  => count( $segments ),
                'chunker'        => class_exists( 'BizCity_KG_AV_Chunker' ) ? 'av_temporal_v1' : 'single',
            ],
        ];
    }

    /**
     * Side-load a local tmp file into the WP Media Library.
     * Reuses existing attachment if the source path is already inside uploads.
     *
     * @return array|WP_Error  {attachment_id, url}
     */
    private function sideload_to_media( $file_path, array $opts ) {
        if ( ! function_exists( 'wp_handle_sideload' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if ( ! function_exists( 'wp_insert_attachment' ) ) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/post.php';
        }

        // Allow side-loading common A/V mimes even on locked-down installs.
        $mime_filter = function ( $mimes ) {
            $extra = [
                'mp3'  => 'audio/mpeg',
                'wav'  => 'audio/wav',
                'm4a'  => 'audio/m4a',
                'aac'  => 'audio/aac',
                'ogg'  => 'audio/ogg',
                'opus' => 'audio/ogg',
                'flac' => 'audio/flac',
                'mp4'  => 'video/mp4',
                'm4v'  => 'video/mp4',
                'mov'  => 'video/quicktime',
                'webm' => 'video/webm',
                'mkv'  => 'video/x-matroska',
                '3gp'  => 'video/3gpp',
            ];
            return array_merge( (array) $mimes, $extra );
        };
        add_filter( 'upload_mimes', $mime_filter, 99 );

        $original_name = isset( $opts['filename'] ) && $opts['filename'] !== ''
            ? sanitize_file_name( (string) $opts['filename'] )
            : basename( $file_path );

        // wp_handle_sideload moves the source file into uploads/. To keep the
        // original tmp file (the ingest pipeline may still need it), copy first.
        $tmp_copy = wp_tempnam( $original_name );
        if ( ! $tmp_copy || ! @copy( $file_path, $tmp_copy ) ) {
            remove_filter( 'upload_mimes', $mime_filter, 99 );
            return new WP_Error( 'av_tmp_copy_failed', 'Could not stage media file for upload.', [ 'http_status' => 500 ] );
        }

        $file_array = [
            'name'     => $original_name,
            'tmp_name' => $tmp_copy,
            'error'    => 0,
            'size'     => (int) @filesize( $tmp_copy ),
        ];

        $overrides = [
            'test_form' => false,
            'test_size' => true,
            'action'    => 'wp_handle_sideload',
        ];
        $sideload = wp_handle_sideload( $file_array, $overrides );
        remove_filter( 'upload_mimes', $mime_filter, 99 );

        if ( isset( $sideload['error'] ) ) {
            @unlink( $tmp_copy );
            return new WP_Error( 'av_sideload_failed', (string) $sideload['error'], [ 'http_status' => 500 ] );
        }

        $attachment = [
            'post_mime_type' => (string) ( $sideload['type'] ?? ( $opts['mime'] ?? '' ) ),
            'post_title'     => preg_replace( '/\.[^.]+$/', '', $original_name ),
            'post_content'   => '',
            'post_status'    => 'inherit',
        ];
        $attach_id = wp_insert_attachment( $attachment, $sideload['file'], 0, true );
        if ( is_wp_error( $attach_id ) ) {
            return $attach_id;
        }
        // Generate metadata (audio/video duration etc.).
        $meta = wp_generate_attachment_metadata( $attach_id, $sideload['file'] );
        wp_update_attachment_metadata( $attach_id, $meta );

        return [
            'attachment_id' => (int) $attach_id,
            'url'           => (string) ( $sideload['url'] ?? wp_get_attachment_url( $attach_id ) ),
        ];
    }
}
