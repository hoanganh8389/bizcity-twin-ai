<?php
/**
 * BizCity_Youtube_Transcriber — caption-track extractor for YouTube URLs.
 *
 * Sprint PHASE-0.7 Wave E0.YT (caption-only mode).
 *
 * Strategy:
 *   1. Detect YouTube URL → extract 11-char video ID (youtu.be / youtube.com / shorts).
 *   2. Fetch watch page HTML (one wp_remote_get).
 *   3. Parse `ytInitialPlayerResponse` JSON → captionTracks[].baseUrl.
 *   4. Pick best track:
 *        - Match $opts['lang'] if provided
 *        - Else prefer 'vi', 'en', 'en-US', then first non-ASR
 *        - Then first ASR
 *   5. Fetch baseUrl (XML format default) → strip <text> tags → plain transcript.
 *   6. Return { text, video_id, title, channel, duration_sec, lang, is_asr,
 *               url, source_kind:'youtube' } or WP_Error.
 *
 * Tier policy (caller decides via BizCity_Entitlement::can('learning.youtube')):
 *   - Caption-mode: free for everyone (just URL fetches, no LLM cost).
 *   - ASR fallback (when no captions): paid-only — returns 'youtube_no_captions'
 *     so caller can show upgrade CTA. Not implemented yet.
 *
 * @package BizCity_Twin_AI\KG_Hub\Clients
 * @since   PHASE-0.7 Wave E0.YT
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Youtube_Transcriber {

    const FETCH_TIMEOUT     = 12;
    const USER_AGENT        = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';
    const MAX_TRANSCRIPT_BYTES = 2 * 1024 * 1024; // 2 MB hard cap

    /** @var BizCity_Youtube_Transcriber|null */
    private static $instance = null;

    public static function instance(): BizCity_Youtube_Transcriber {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Detect whether a URL is a YouTube link (any common shape).
     */
    public static function is_youtube_url( string $url ): bool {
        if ( $url === '' ) return false;
        $host = parse_url( $url, PHP_URL_HOST );
        if ( ! $host ) return false;
        $host = strtolower( $host );
        return $host === 'youtu.be'
            || $host === 'www.youtube.com'
            || $host === 'youtube.com'
            || $host === 'm.youtube.com'
            || $host === 'music.youtube.com';
    }

    /**
     * Extract the 11-char video ID from a YouTube URL.
     *
     * Supports:
     *   https://youtu.be/<ID>?...
     *   https://www.youtube.com/watch?v=<ID>&...
     *   https://www.youtube.com/shorts/<ID>
     *   https://www.youtube.com/embed/<ID>
     */
    public static function extract_video_id( string $url ): string {
        $parts = wp_parse_url( $url );
        if ( empty( $parts['host'] ) ) return '';
        $host = strtolower( $parts['host'] );
        $path = isset( $parts['path'] ) ? trim( (string) $parts['path'], '/' ) : '';
        $qs   = [];
        if ( ! empty( $parts['query'] ) ) {
            wp_parse_str( $parts['query'], $qs );
        }

        if ( $host === 'youtu.be' && $path !== '' ) {
            return self::sanitize_id( explode( '/', $path )[0] );
        }
        if ( strpos( $path, 'shorts/' ) === 0 ) {
            return self::sanitize_id( substr( $path, 7 ) );
        }
        if ( strpos( $path, 'embed/' ) === 0 ) {
            return self::sanitize_id( substr( $path, 6 ) );
        }
        if ( ! empty( $qs['v'] ) ) {
            return self::sanitize_id( (string) $qs['v'] );
        }
        return '';
    }

    private static function sanitize_id( string $raw ): string {
        $raw = preg_replace( '/[^A-Za-z0-9_-]/', '', $raw );
        return strlen( (string) $raw ) === 11 ? (string) $raw : '';
    }

    /**
     * Main entry — fetch the transcript for a YouTube URL.
     *
     * @param string $url
     * @param array  $opts { lang?: string, prefer_no_asr?: bool }
     * @return array|WP_Error
     */
    public function fetch( string $url, array $opts = [] ) {
        if ( ! self::is_youtube_url( $url ) ) {
            return new WP_Error( 'youtube_not_a_yt_url', 'URL is not a YouTube link.' );
        }
        $video_id = self::extract_video_id( $url );
        if ( $video_id === '' ) {
            return new WP_Error( 'youtube_invalid_url', 'Could not extract YouTube video ID from URL.' );
        }

        $watch_url = 'https://www.youtube.com/watch?v=' . rawurlencode( $video_id );

        // PRIMARY: InnerTube API — clean JSON, no consent wall, no CAPTCHA.
        $player = $this->fetch_player_via_innertube( $video_id );

        // FALLBACK: scrape watch-page HTML (legacy path, often blocked by consent gate).
        if ( is_wp_error( $player ) ) {
            $innertube_err = $player;
            $html = $this->http_get( $watch_url . '&hl=en&persist_hl=1&bpctr=9999999999&has_verified=1' );
            if ( is_wp_error( $html ) ) {
                // Surface the InnerTube error (more informative) when both fail.
                return $innertube_err;
            }
            $player = $this->extract_player_response( $html );
            if ( is_wp_error( $player ) ) {
                return $player;
            }
        }

        // Basic metadata (best-effort).
        $details      = is_array( $player['videoDetails'] ?? null ) ? $player['videoDetails'] : [];
        $title        = (string) ( $details['title'] ?? '' );
        $channel      = (string) ( $details['author'] ?? '' );
        $duration_sec = isset( $details['lengthSeconds'] ) ? (int) $details['lengthSeconds'] : 0;

        // Caption tracks live under captions.playerCaptionsTracklistRenderer.captionTracks.
        $tracks = [];
        if ( isset( $player['captions']['playerCaptionsTracklistRenderer']['captionTracks'] )
             && is_array( $player['captions']['playerCaptionsTracklistRenderer']['captionTracks'] ) ) {
            $tracks = $player['captions']['playerCaptionsTracklistRenderer']['captionTracks'];
        }

        if ( empty( $tracks ) ) {
            return new WP_Error(
                'youtube_no_captions',
                'No captions available for this video. ASR (automatic speech recognition) is required and is part of the Pro plan.',
                [
                    'video_id'     => $video_id,
                    'title'        => $title,
                    'duration_sec' => $duration_sec,
                    'asr_required' => true,
                    'http_status'  => 422,
                ]
            );
        }

        $picked = $this->pick_track( $tracks, isset( $opts['lang'] ) ? (string) $opts['lang'] : '' );
        if ( ! $picked ) {
            return new WP_Error( 'youtube_no_track_pickable', 'Caption tracks present but none could be selected.' );
        }

        $base_url = (string) ( $picked['baseUrl'] ?? '' );
        if ( $base_url === '' ) {
            return new WP_Error( 'youtube_track_no_url', 'Selected caption track has no baseUrl.' );
        }
        $lang_code = (string) ( $picked['languageCode'] ?? ( $picked['vssId'] ?? '' ) );
        $is_asr    = ( ( $picked['kind'] ?? '' ) === 'asr' );

        // Fetch the timedtext XML.
        $xml = $this->http_get( $base_url );
        if ( is_wp_error( $xml ) ) {
            return $xml;
        }

        $text = $this->xml_to_plain( $xml );
        if ( $text === '' ) {
            return new WP_Error( 'youtube_empty_transcript', 'Caption track returned empty transcript.' );
        }

        return [
            'text'         => $text,
            'video_id'     => $video_id,
            'title'        => $title,
            'channel'      => $channel,
            'duration_sec' => $duration_sec,
            'lang'         => $lang_code,
            'is_asr'       => $is_asr,
            'url'          => $watch_url,
            'source_kind'  => 'youtube',
            'meta'         => [
                'caption_tracks_count' => count( $tracks ),
                'picked_lang'          => $lang_code,
                'is_asr'               => $is_asr,
            ],
        ];
    }

    /* ================================================================
     *  Internals
     * ================================================================ */

    /**
     * Pick the best caption track per preferences.
     */
    private function pick_track( array $tracks, string $prefer_lang ): ?array {
        if ( empty( $tracks ) ) return null;

        $by_lang = [];
        foreach ( $tracks as $t ) {
            if ( ! is_array( $t ) ) continue;
            $code = strtolower( (string) ( $t['languageCode'] ?? '' ) );
            if ( $code !== '' ) $by_lang[ $code ][] = $t;
        }

        // 1. explicit preference
        if ( $prefer_lang !== '' ) {
            $pl = strtolower( $prefer_lang );
            if ( ! empty( $by_lang[ $pl ] ) ) return $by_lang[ $pl ][0];
        }
        // 2. vi → en preference
        foreach ( [ 'vi', 'en', 'en-us', 'en-gb' ] as $code ) {
            if ( ! empty( $by_lang[ $code ] ) ) {
                // Prefer non-ASR within this language
                foreach ( $by_lang[ $code ] as $t ) {
                    if ( ( $t['kind'] ?? '' ) !== 'asr' ) return $t;
                }
                return $by_lang[ $code ][0];
            }
        }
        // 3. First non-ASR overall
        foreach ( $tracks as $t ) {
            if ( is_array( $t ) && ( $t['kind'] ?? '' ) !== 'asr' ) return $t;
        }
        // 4. Anything
        return is_array( $tracks[0] ) ? $tracks[0] : null;
    }

    /**
     * Pull `ytInitialPlayerResponse` JSON out of the watch page HTML.
     *
     * Uses a balanced-brace scanner because the JSON is large (>300KB) and
     * lazy-regex matches sometimes truncate at internal '}' inside strings.
     */
    private function extract_player_response( string $html ) {
        $needles = [
            'var ytInitialPlayerResponse = ',
            'ytInitialPlayerResponse = ',
            '"playerResponse":"',
        ];
        $start = false;
        $hit_needle = '';
        foreach ( $needles as $n ) {
            $pos = strpos( $html, $n );
            if ( $pos !== false ) {
                $start = $pos + strlen( $n );
                $hit_needle = $n;
                break;
            }
        }
        if ( $start === false ) {
            return new WP_Error( 'youtube_player_response_missing', 'ytInitialPlayerResponse not found in page HTML (YouTube may have returned a consent / age-gate / CAPTCHA page).' );
        }

        // The third needle wraps the JSON inside a string — handle separately.
        if ( $hit_needle === '"playerResponse":"' ) {
            $end = strpos( $html, '"', $start );
            $raw = substr( $html, $start, $end - $start );
            $raw = stripslashes( $raw );
            $data = json_decode( $raw, true );
            if ( ! is_array( $data ) ) {
                return new WP_Error( 'youtube_player_response_invalid', 'Failed to JSON-decode escaped playerResponse.' );
            }
            return $data;
        }

        // Balanced-brace scan starting at $start (which should point at '{').
        $len = strlen( $html );
        if ( $start >= $len || $html[ $start ] !== '{' ) {
            return new WP_Error( 'youtube_player_response_invalid', 'Expected JSON object after needle.' );
        }
        $depth     = 0;
        $in_string = false;
        $escape    = false;
        $end       = -1;
        for ( $i = $start; $i < $len; $i++ ) {
            $c = $html[ $i ];
            if ( $escape ) { $escape = false; continue; }
            if ( $c === '\\' ) { $escape = true; continue; }
            if ( $c === '"' ) { $in_string = ! $in_string; continue; }
            if ( $in_string ) continue;
            if ( $c === '{' ) { $depth++; continue; }
            if ( $c === '}' ) {
                $depth--;
                if ( $depth === 0 ) { $end = $i; break; }
            }
        }
        if ( $end === -1 ) {
            return new WP_Error( 'youtube_player_response_invalid', 'Unbalanced braces while scanning ytInitialPlayerResponse.' );
        }
        $json = substr( $html, $start, ( $end - $start ) + 1 );
        $data = json_decode( $json, true );
        if ( ! is_array( $data ) ) {
            return new WP_Error( 'youtube_player_response_invalid', 'Failed to JSON-decode ytInitialPlayerResponse (length=' . strlen( $json ) . ').' );
        }
        return $data;
    }

    /**
     * Convert YouTube timedtext XML → plain transcript text.
     *
     * Handles the classic XML format:
     *   <transcript><text start="..." dur="...">caption line</text>…</transcript>
     */
    private function xml_to_plain( string $xml ): string {
        if ( $xml === '' ) return '';
        // Decode HTML entities inside <text> nodes after extracting them.
        if ( ! preg_match_all( '#<text[^>]*>(.*?)</text>#s', $xml, $matches ) ) {
            // Fallback: strip all XML tags
            return trim( html_entity_decode( wp_strip_all_tags( $xml ), ENT_QUOTES | ENT_XML1, 'UTF-8' ) );
        }
        $parts = [];
        foreach ( $matches[1] as $raw ) {
            $line = html_entity_decode( $raw, ENT_QUOTES | ENT_XML1, 'UTF-8' );
            // Convert XML escape entities a second time — captions sometimes double-encoded.
            $line = html_entity_decode( $line, ENT_QUOTES | ENT_XML1, 'UTF-8' );
            $line = wp_strip_all_tags( $line );
            $line = preg_replace( "/\s+/u", ' ', $line );
            $line = trim( (string) $line );
            if ( $line !== '' ) $parts[] = $line;
        }
        $out = implode( "\n", $parts );
        if ( strlen( $out ) > self::MAX_TRANSCRIPT_BYTES ) {
            $out = substr( $out, 0, self::MAX_TRANSCRIPT_BYTES );
        }
        return $out;
    }

    private function http_get( string $url ) {
        $res = wp_remote_get( $url, [
            'timeout'     => self::FETCH_TIMEOUT,
            'redirection' => 5,
            'user-agent'  => self::USER_AGENT,
            'headers'     => [
                'Accept-Language' => 'en-US,en;q=0.9,vi;q=0.8',
                // CONSENT cookie bypasses Google's EU/cookie consent interstitial
                // which otherwise serves an empty / interstitial body.
                'Cookie'          => 'CONSENT=YES+cb.20210328-17-p0.en+FX+000; SOCS=CAI',
            ],
        ] );
        if ( is_wp_error( $res ) ) {
            return $res;
        }
        $code = (int) wp_remote_retrieve_response_code( $res );
        if ( $code < 200 || $code >= 400 ) {
            return new WP_Error( 'youtube_http_' . $code, 'YouTube fetch returned HTTP ' . $code );
        }
        $body = (string) wp_remote_retrieve_body( $res );
        if ( $body === '' ) {
            return new WP_Error( 'youtube_empty_body', 'YouTube returned empty body.' );
        }
        return $body;
    }

    /**
     * PRIMARY player-data fetch — uses YouTube's internal InnerTube API.
     *
     * This is what the YouTube web/mobile player itself calls. It returns
     * pure JSON (no HTML wrapper), bypasses the cookie/consent interstitial,
     * and is far more reliable than scraping the watch-page HTML.
     *
     * Endpoint:  POST https://www.youtube.com/youtubei/v1/player
     * Auth:      public web API key (same one shipped in the YouTube web client).
     *
     * Returns the decoded `playerResponse` shape (same as scraped HTML), or
     * WP_Error on transport / parse failure.
     */
    private function fetch_player_via_innertube( string $video_id ) {
        // Public web client API key — published in the YouTube web bundle.
        // It rotates rarely; if it ever changes we'll fall back to HTML scrape.
        // NOTE (PHASE-0.98 secret-scan): this is NOT a private credential —
        // it is shipped in plain JS to every browser visiting youtube.com.
        // Whitelisted in bin/secret-scan.ps1 $allowlist.
        $api_key = 'AIzaSyAO_FJ2SlqU8Q4STEHLGCilw_Y9_11qcW8';
        $endpoint = 'https://www.youtube.com/youtubei/v1/player?key=' . $api_key
            . '&prettyPrint=false';

        $body = wp_json_encode( [
            'videoId'       => $video_id,
            'contentCheckOk' => true,
            'racyCheckOk'   => true,
            'context' => [
                'client' => [
                    // ANDROID client doesn't get age-gate / consent-wall HTML.
                    'clientName'        => 'ANDROID',
                    'clientVersion'     => '19.09.37',
                    'androidSdkVersion' => 30,
                    'hl'                => 'en',
                    'gl'                => 'US',
                    'userAgent'         => 'com.google.android.youtube/19.09.37 (Linux; U; Android 11) gzip',
                ],
            ],
        ] );

        $res = wp_remote_post( $endpoint, [
            'timeout'     => self::FETCH_TIMEOUT,
            'redirection' => 3,
            'headers'     => [
                'Content-Type'    => 'application/json',
                'User-Agent'      => 'com.google.android.youtube/19.09.37 (Linux; U; Android 11) gzip',
                'X-YouTube-Client-Name'    => '3',     // 3 = ANDROID
                'X-YouTube-Client-Version' => '19.09.37',
                'Accept'          => 'application/json',
                'Accept-Language' => 'en-US,en;q=0.9',
            ],
            'body' => $body,
        ] );

        if ( is_wp_error( $res ) ) {
            return $res;
        }
        $code = (int) wp_remote_retrieve_response_code( $res );
        if ( $code < 200 || $code >= 400 ) {
            return new WP_Error( 'youtube_innertube_http_' . $code, 'InnerTube returned HTTP ' . $code );
        }
        $raw = (string) wp_remote_retrieve_body( $res );
        if ( $raw === '' ) {
            return new WP_Error( 'youtube_innertube_empty', 'InnerTube returned empty body.' );
        }
        $data = json_decode( $raw, true );
        if ( ! is_array( $data ) ) {
            return new WP_Error( 'youtube_innertube_invalid_json', 'InnerTube body is not JSON (length=' . strlen( $raw ) . ').' );
        }
        // InnerTube can return playabilityStatus.status='ERROR' when video is
        // private / removed / region-blocked. Surface a useful message.
        $status = (string) ( $data['playabilityStatus']['status'] ?? '' );
        if ( $status !== '' && $status !== 'OK' ) {
            $reason = (string) ( $data['playabilityStatus']['reason'] ?? $status );
            return new WP_Error(
                'youtube_unplayable',
                'YouTube reports this video is unplayable: ' . $reason,
                [ 'status' => $status ]
            );
        }
        return $data;
    }

    /* =====================================================================
     * SPRINT C — AV FALLBACK
     *
     * When fetch() returns `youtube_no_captions` we fall back to:
     *   1. shell-out to yt-dlp to extract bestaudio (mp3) into a temp file
     *   2. side-load the temp file as a WP attachment (so AV adapter can read URL)
     *   3. delegate to BizCity_AV_Transcribe_Client::transcribe() (Vision LLM)
     *
     * Hard-cap yt-dlp invocation at 90s. Returns the same shape as fetch() so
     * callers do not need to special-case the source.
     * ===================================================================*/

    /**
     * @param string $url
     * @param array  $opts { lang?: string, allow_av_fallback?: bool, user_id?: int }
     * @return array|WP_Error
     */
    public function fetch_with_av_fallback( string $url, array $opts = [] ) {
        $primary = $this->fetch( $url, $opts );
        if ( ! is_wp_error( $primary ) ) return $primary;

        $code = $primary->get_error_code();
        if ( $code !== 'youtube_no_captions' ) return $primary;
        if ( empty( $opts['allow_av_fallback'] ) && ! apply_filters( 'bizcity_youtube_av_fallback_default_on', true ) ) {
            return $primary;
        }

        // Capability checks before spending resources.
        $bin = $this->locate_ytdlp_binary();
        if ( $bin === '' ) {
            return new WP_Error(
                'youtube_av_fallback_no_ytdlp',
                'No captions available and yt-dlp is not installed on the host — cannot use AV fallback.',
                [ 'status' => 503, 'asr_required' => true ]
            );
        }
        if ( ! class_exists( 'BizCity_AV_Transcribe_Client' ) ) {
            return new WP_Error(
                'youtube_av_fallback_no_client',
                'AV transcribe client not loaded.',
                [ 'status' => 503 ]
            );
        }

        // Phase 0.8 — dedicated `youtube_av_fallback` quota (separate from `av_file`).
        // Tighter cap on paid (5/day vs 20/day) to bound bandwidth + transcribe cost.
        // Free tier shares the same 1/day budget — same as a direct AV upload.
        $uid = (int) ( $opts['user_id'] ?? get_current_user_id() );
        if ( $uid > 0 && class_exists( 'BizCity_Entitlement' ) ) {
            $quota = BizCity_Entitlement::check_quota( $uid, 'youtube_av_fallback', 1 );
            if ( is_wp_error( $quota ) || ( is_array( $quota ) && empty( $quota['ok'] ) ) ) {
                $msg = is_wp_error( $quota )
                    ? $quota->get_error_message()
                    : sprintf(
                        'Daily YouTube AV fallback quota exhausted (%d/%d).',
                        (int) ( $quota['used_today'] ?? 0 ),
                        (int) ( $quota['free_day']   ?? 0 )
                    );
                return new WP_Error(
                    'youtube_av_fallback_quota_exhausted',
                    $msg,
                    [
                        'status'           => 402,
                        'feature'          => 'learning.youtube_av',
                        'requires_feature' => 'learning.youtube_av',
                        'unit'             => 'youtube_av_fallback',
                        'reason'           => is_array( $quota ) ? ( $quota['reason'] ?? '' ) : '',
                    ]
                );
            }
        }

        $video_id = self::extract_video_id( $url );
        $tmp_dir  = trailingslashit( get_temp_dir() );
        $tmp_id   = 'yt_' . $video_id . '_' . wp_generate_password( 6, false, false );
        $tmp_out  = $tmp_dir . $tmp_id . '.%(ext)s';
        $expect   = $tmp_dir . $tmp_id . '.mp3';

        // Build a single shell-safe command. yt-dlp exits non-zero on failure.
        // -x = extract audio, --audio-format mp3, -f bestaudio = pick smallest audio stream.
        $cmd = sprintf(
            '%s -x --audio-format mp3 -f bestaudio --no-playlist --no-warnings --quiet -o %s %s 2>&1',
            escapeshellarg( $bin ),
            escapeshellarg( $tmp_out ),
            escapeshellarg( $url )
        );

        $started = microtime( true );
        // 90s hard timeout via `timeout` shell builtin if available; otherwise rely on yt-dlp itself.
        $output = $this->shell_exec_with_timeout( $cmd, 90 );
        $elapsed = (int) ( ( microtime( true ) - $started ) * 1000 );

        if ( ! file_exists( $expect ) ) {
            return new WP_Error(
                'youtube_av_fallback_download_failed',
                'yt-dlp did not produce an output file (' . $elapsed . 'ms). Last output: ' . substr( (string) $output, 0, 240 ),
                [ 'status' => 502 ]
            );
        }

        // Side-load the mp3 into WP Media so the AV adapter can read it via a URL.
        $attachment_id = $this->sideload_local_file( $expect, $opts['user_id'] ?? 0, $url );
        @unlink( $expect );
        if ( is_wp_error( $attachment_id ) ) return $attachment_id;

        $media_url = wp_get_attachment_url( $attachment_id );
        if ( ! $media_url ) {
            return new WP_Error( 'youtube_av_fallback_no_url', 'Side-loaded attachment has no URL.', [ 'status' => 500 ] );
        }

        $client = BizCity_AV_Transcribe_Client::instance();
        $av_res = $client->transcribe( $media_url, 'audio', [
            'lang'          => isset( $opts['lang'] ) ? (string) $opts['lang'] : 'auto',
            'mime'          => 'audio/mpeg',
            'attachment_id' => $attachment_id,
            'user_id'       => $opts['user_id'] ?? 0,
        ] );
        if ( is_wp_error( $av_res ) ) return $av_res;

        // Re-shape into the youtube transcript contract so callers don't branch.
        $text = (string) ( $av_res['text'] ?? '' );

        // Phase 0.8 — count one unit against the youtube_av_fallback quota.
        // Note: the AV transcribe client already records `av_file` usage for the
        // underlying transcribe call; this row tracks the YT-fallback path
        // separately so dashboards can see how often captions failed.
        if ( $uid > 0 && class_exists( 'BizCity_Entitlement' ) ) {
            BizCity_Entitlement::record_usage( $uid, 'youtube_av_fallback', 1, [
                'feature'       => 'learning.youtube_av',
                'video_id'      => $video_id,
                'attachment_id' => $attachment_id,
                'ytdlp_ms'      => $elapsed,
            ] );
        }

        return [
            'text'         => $text,
            'title'        => '',
            'video_id'     => $video_id,
            'lang'         => (string) ( $av_res['meta']['lang'] ?? '' ),
            'source_url'   => $url,
            'source_kind'  => 'youtube',
            'modality'     => 'youtube_av_fallback',
            'meta'         => array_merge(
                is_array( $av_res['meta'] ?? null ) ? $av_res['meta'] : [],
                [
                    'fallback'      => 'av',
                    'attachment_id' => $attachment_id,
                    'ytdlp_ms'      => $elapsed,
                ]
            ),
        ];
    }

    private function locate_ytdlp_binary(): string {
        if ( ! function_exists( 'shell_exec' ) ) return '';
        $disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
        if ( in_array( 'shell_exec', $disabled, true ) ) return '';
        $candidates = ( strncasecmp( PHP_OS, 'WIN', 3 ) === 0 )
            ? [ 'yt-dlp.exe', 'youtube-dl.exe' ]
            : [ 'yt-dlp', 'youtube-dl' ];
        foreach ( $candidates as $bin ) {
            $cmd = ( strncasecmp( PHP_OS, 'WIN', 3 ) === 0 )
                ? "where {$bin} 2>NUL"
                : "command -v {$bin} 2>/dev/null";
            $out = trim( (string) @shell_exec( $cmd ) );
            if ( $out !== '' ) {
                // `where` may return multiple lines on Windows — take first.
                $first = strtok( $out, "\r\n" );
                if ( is_string( $first ) && $first !== '' ) return $first;
            }
        }
        return '';
    }

    /** Best-effort timeout wrapper. Falls back to plain shell_exec if `timeout` is missing. */
    private function shell_exec_with_timeout( string $cmd, int $seconds ): string {
        if ( strncasecmp( PHP_OS, 'WIN', 3 ) === 0 ) {
            // Windows has no portable shell timeout; rely on yt-dlp's internal timeouts.
            return (string) @shell_exec( $cmd );
        }
        // GNU coreutils `timeout` kills after N seconds; SIGTERM by default.
        $wrapped = sprintf( 'timeout %d %s', max( 5, $seconds ), $cmd );
        return (string) @shell_exec( $wrapped );
    }

    private function sideload_local_file( string $path, int $user_id, string $source_url ) {
        if ( ! function_exists( 'wp_insert_attachment' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
        }
        $upload = wp_handle_sideload(
            [
                'name'     => basename( $path ),
                'type'     => 'audio/mpeg',
                'tmp_name' => $path,
                'error'    => 0,
                'size'     => filesize( $path ),
            ],
            [ 'test_form' => false ]
        );
        if ( ! empty( $upload['error'] ) ) {
            return new WP_Error( 'youtube_av_fallback_sideload_failed', $upload['error'] );
        }
        $attachment_id = wp_insert_attachment(
            [
                'post_mime_type' => 'audio/mpeg',
                'post_title'     => 'YouTube AV fallback ' . wp_basename( $source_url ),
                'post_status'    => 'inherit',
                'post_author'    => $user_id ?: get_current_user_id(),
            ],
            $upload['file']
        );
        if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
            return new WP_Error( 'youtube_av_fallback_attach_failed', 'wp_insert_attachment failed.' );
        }
        wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $upload['file'] ) );
        return (int) $attachment_id;
    }
}
