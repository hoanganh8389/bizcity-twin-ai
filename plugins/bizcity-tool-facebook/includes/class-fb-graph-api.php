<?php
/**
 * BizCity Tool Facebook — Standalone Facebook Graph API Client
 *
 * Self-contained wrapper for Facebook Graph API v21.0.
 * Zero dependency on bizcity-facebook-bot or any other plugin.
 *
 * Covers:
 *   ① Pages  — post text, photo, video, reel to FB Page feed
 *   ② Messenger — send text/image/video/template/quick-replies to PSID
 *   ③ Comments — reply, hide, delete, get comments for a post
 *   ④ Instagram — create + publish photo/reel, reply to IG comments
 *   ⑤ Groups  — post to FB Group (requires group access token)
 *
 * Usage:
 *   $api = new BizCity_FB_Graph_API( $page_access_token, $page_id );
 *   $api->post_text( 'Hello world!' );
 *   $api->post_photo( 'https://...jpg', 'Caption' );
 *   $api->send_message( $psid, 'Hello!' );
 *
 * @package BizCity\TwinAI\ToolFacebook
 * @since   2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BizCity_FB_Graph_API {

    const GRAPH_VERSION = 'v21.0';
    const GRAPH_BASE    = 'https://graph.facebook.com/';

    /** @var string Page Access Token */
    private string $access_token;

    /** @var string Facebook Page ID */
    private string $page_id;

    /** @var int HTTP timeout in seconds */
    private int $timeout;

    public function __construct( string $access_token, string $page_id = '', int $timeout = 30 ) {
        $this->access_token = $access_token;
        $this->page_id      = $page_id;
        $this->timeout      = $timeout;
    }

    /* ══════════════════════════════════════════════════════════════════
     *  ① PAGE PUBLISHING
     * ══════════════════════════════════════════════════════════════════ */

    /**
     * Post text (with optional link) to FB Page feed.
     *
     * @param  string $message  Body text.
     * @param  string $link     Optional URL to attach.
     * @return array  { success, post_id, link, error? }
     */
    public function post_text( string $message, string $link = '' ): array {
        $body = [ 'message' => $message, 'access_token' => $this->access_token ];
        if ( $link ) $body['link'] = $link;

        return $this->call( $this->page_id . '/feed', $body );
    }

    /**
     * Post photo to FB Page.
     * Facebook uses /photos endpoint — images appear in album + timeline.
     *
     * @param  string $image_url Public URL of the image.
     * @param  string $caption   Optional text caption.
     * @return array  { success, post_id, error? }
     */
    public function post_photo( string $image_url, string $caption = '' ): array {
        $body = [
            'url'          => $image_url,
            'caption'      => $caption,
            'access_token' => $this->access_token,
        ];
        return $this->call( $this->page_id . '/photos', $body );
    }

    /**
     * Post video to FB Page.
     * Uses /videos endpoint — video appears in Page's video tab + feed.
     *
     * @param  string $video_url  Public direct URL of the video file (.mp4).
     * @param  string $title      Video title.
     * @param  string $description Video description.
     * @return array  { success, video_id, error? }
     */
    public function post_video( string $video_url, string $title = '', string $description = '' ): array {
        $body = [
            'file_url'     => $video_url,
            'title'        => $title,
            'description'  => $description,
            'access_token' => $this->access_token,
        ];
        return $this->call( $this->page_id . '/videos', $body );
    }

    /**
     * Post a Reel to FB Page.
     * Reels are short-form videos (≤ 90s, 9:16).
     * Phase 1: upload video, Phase 2: publish.
     *
     * @param  string $video_url   Public direct URL of the video.
     * @param  string $description Caption text.
     * @return array  { success, reel_id, error? }
     */
    public function post_reel( string $video_url, string $description = '' ): array {
        // Step 1: Upload video to FB (returns a video_id)
        $upload = $this->call( $this->page_id . '/video_reels', [
            'upload_phase'   => 'start',
            'access_token'   => $this->access_token,
        ] );

        if ( empty( $upload['success'] ) || empty( $upload['raw']['video_id'] ) ) {
            return array_merge( $upload, [ 'error_context' => 'reel upload_phase=start failed' ] );
        }

        $video_id = $upload['raw']['video_id'];

        // Step 2: Publish reel
        $publish = $this->call( $this->page_id . '/video_reels', [
            'video_id'     => $video_id,
            'upload_phase' => 'finish',
            'video_state'  => 'PUBLISHED',
            'description'  => $description,
            'file_url'     => $video_url,
            'access_token' => $this->access_token,
        ] );

        return $publish;
    }

    /* ══════════════════════════════════════════════════════════════════
     *  ② MESSENGER
     * ══════════════════════════════════════════════════════════════════ */

    /**
     * Send plain text message to Messenger user (PSID).
     *
     * @param  string $psid    Page-Scoped User ID.
     * @param  string $text    Message text.
     * @param  string $type    RESPONSE | UPDATE | MESSAGE_TAG
     * @return array
     */
    public function send_message( string $psid, string $text, string $type = 'RESPONSE' ): array {
        return $this->call( 'me/messages', [
            'recipient'      => [ 'id' => $psid ],
            'message'        => [ 'text' => $text ],
            'messaging_type' => $type,
            'access_token'   => $this->access_token,
        ] );
    }

    /**
     * Send image attachment to Messenger user.
     *
     * @param  string $psid      Recipient PSID.
     * @param  string $image_url Public URL of the image.
     * @param  string $caption   Optional caption sent as separate message.
     * @return array
     */
    public function send_image( string $psid, string $image_url, string $caption = '' ): array {
        $result = $this->call( 'me/messages', [
            'recipient'      => [ 'id' => $psid ],
            'message'        => [
                'attachment' => [
                    'type'    => 'image',
                    'payload' => [ 'url' => $image_url, 'is_reusable' => true ],
                ],
            ],
            'messaging_type' => 'RESPONSE',
            'access_token'   => $this->access_token,
        ] );
        if ( $caption ) $this->send_message( $psid, $caption );
        return $result;
    }

    /**
     * Send video attachment to Messenger user.
     *
     * @param  string $psid      Recipient PSID.
     * @param  string $video_url Public URL of the video.
     * @return array
     */
    public function send_video( string $psid, string $video_url ): array {
        return $this->call( 'me/messages', [
            'recipient'      => [ 'id' => $psid ],
            'message'        => [
                'attachment' => [
                    'type'    => 'video',
                    'payload' => [ 'url' => $video_url, 'is_reusable' => true ],
                ],
            ],
            'messaging_type' => 'RESPONSE',
            'access_token'   => $this->access_token,
        ] );
    }

    /**
     * Send quick replies (buttons) to Messenger user.
     *
     * @param  string $psid          Recipient PSID.
     * @param  string $text          Prompt text.
     * @param  array  $quick_replies [ [ 'content_type' => 'text', 'title' => '...', 'payload' => '...' ] ]
     * @return array
     */
    public function send_quick_replies( string $psid, string $text, array $quick_replies ): array {
        return $this->call( 'me/messages', [
            'recipient'      => [ 'id' => $psid ],
            'message'        => [ 'text' => $text, 'quick_replies' => $quick_replies ],
            'messaging_type' => 'RESPONSE',
            'access_token'   => $this->access_token,
        ] );
    }

    /**
     * Send generic template (carousel cards).
     *
     * @param  string $psid     Recipient PSID.
     * @param  array  $elements Array of card objects.
     * @return array
     */
    public function send_generic_template( string $psid, array $elements ): array {
        return $this->call( 'me/messages', [
            'recipient'      => [ 'id' => $psid ],
            'message'        => [
                'attachment' => [
                    'type'    => 'template',
                    'payload' => [
                        'template_type' => 'generic',
                        'elements'      => $elements,
                    ],
                ],
            ],
            'messaging_type' => 'RESPONSE',
            'access_token'   => $this->access_token,
        ] );
    }

    /**
     * Get user profile from Messenger PSID.
     *
     * @param  string $psid Recipient PSID.
     * @return array  { name, first_name, last_name, profile_pic, ... }
     */
    public function get_user_profile( string $psid ): array {
        $fields = 'name,first_name,last_name,profile_pic';
        $url    = self::GRAPH_BASE . self::GRAPH_VERSION . "/{$psid}?fields={$fields}&access_token=" . urlencode( $this->access_token );

        $response = wp_remote_get( $url, [ 'timeout' => $this->timeout ] );

        if ( is_wp_error( $response ) ) {
            return [ 'success' => false, 'error' => $response->get_error_message() ];
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        return [ 'success' => true ] + ( $data ?: [] );
    }

    /* ══════════════════════════════════════════════════════════════════
     *  ③ COMMENTS
     * ══════════════════════════════════════════════════════════════════ */

    /**
     * Reply to a FB comment.
     *
     * @param  string $comment_id  Full comment ID (e.g. PAGE_POST_ID_123).
     * @param  string $message     Reply text.
     * @return array
     */
    public function reply_comment( string $comment_id, string $message ): array {
        return $this->call( $comment_id . '/comments', [
            'message'      => $message,
            'access_token' => $this->access_token,
        ] );
    }

    /**
     * Get public comments for a FB post.
     *
     * @param  string $post_id  Full post ID (e.g. PAGE_ID_POST_ID).
     * @param  int    $limit    Max number of comments.
     * @return array
     */
    public function get_post_comments( string $post_id, int $limit = 25 ): array {
        $fields = 'id,from,message,created_time,like_count,can_reply_privately';
        $url    = self::GRAPH_BASE . self::GRAPH_VERSION
            . "/{$post_id}/comments?fields={$fields}&limit={$limit}&access_token=" . urlencode( $this->access_token );

        $response = wp_remote_get( $url, [ 'timeout' => $this->timeout ] );
        if ( is_wp_error( $response ) ) {
            return [ 'success' => false, 'error' => $response->get_error_message(), 'data' => [] ];
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        return [
            'success' => true,
            'data'    => $data['data'] ?? [],
            'paging'  => $data['paging'] ?? [],
        ];
    }

    /**
     * Hide/unhide a comment.
     *
     * @param  string $comment_id Comment ID.
     * @param  bool   $hidden     True to hide, false to show.
     * @return array
     */
    public function hide_comment( string $comment_id, bool $hidden = true ): array {
        return $this->call( $comment_id, [
            'is_hidden'    => $hidden ? 'true' : 'false',
            'access_token' => $this->access_token,
        ], 'POST' );
    }

    /**
     * Delete a comment.
     *
     * @param  string $comment_id Comment ID.
     * @return array
     */
    public function delete_comment( string $comment_id ): array {
        $url = self::GRAPH_BASE . self::GRAPH_VERSION . "/{$comment_id}?access_token=" . urlencode( $this->access_token );
        $response = wp_remote_request( $url, [ 'method' => 'DELETE', 'timeout' => $this->timeout ] );

        if ( is_wp_error( $response ) ) {
            return [ 'success' => false, 'error' => $response->get_error_message() ];
        }
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        return [ 'success' => ! empty( $data['success'] ) ];
    }

    /* ══════════════════════════════════════════════════════════════════
     *  ④ INSTAGRAM
     * ══════════════════════════════════════════════════════════════════ */

    /**
     * Publish a single photo to Instagram.
     * Two-step: create media container → publish.
     *
     * @param  string $ig_user_id  IG Business Account ID (from page connection).
     * @param  string $image_url   Public URL of the JPEG/PNG.
     * @param  string $caption     Caption text (supports hashtags).
     * @return array  { success, media_id, error? }
     */
    public function post_ig_photo( string $ig_user_id, string $image_url, string $caption = '' ): array {
        // Step 1: Create media container
        $container = $this->call( $ig_user_id . '/media', [
            'image_url'    => $image_url,
            'caption'      => $caption,
            'access_token' => $this->access_token,
        ] );

        if ( empty( $container['success'] ) || empty( $container['raw']['id'] ) ) {
            return array_merge( $container, [ 'error_context' => 'IG create media container failed' ] );
        }

        $creation_id = $container['raw']['id'];

        // Step 2: Publish
        $publish = $this->call( $ig_user_id . '/media_publish', [
            'creation_id'  => $creation_id,
            'access_token' => $this->access_token,
        ] );

        if ( ! empty( $publish['success'] ) && ! empty( $publish['raw']['id'] ) ) {
            $publish['media_id'] = $publish['raw']['id'];
        }
        return $publish;
    }

    /**
     * Publish a Reel to Instagram.
     * Two-step: create video container → publish.
     *
     * @param  string $ig_user_id IG Business Account ID.
     * @param  string $video_url  Public direct URL of .mp4 (≤ 90s, 9:16 ratio).
     * @param  string $caption    Caption text.
     * @param  string $share_to_feed  'true' | 'false' — also show in Feed.
     * @return array
     */
    public function post_ig_reel( string $ig_user_id, string $video_url, string $caption = '', string $share_to_feed = 'true' ): array {
        // Step 1: Create video container
        $container = $this->call( $ig_user_id . '/media', [
            'media_type'    => 'REELS',
            'video_url'     => $video_url,
            'caption'       => $caption,
            'share_to_feed' => $share_to_feed,
            'access_token'  => $this->access_token,
        ] );

        if ( empty( $container['success'] ) || empty( $container['raw']['id'] ) ) {
            return array_merge( $container, [ 'error_context' => 'IG create reel container failed' ] );
        }

        $creation_id = $container['raw']['id'];

        // Poll status until ready (media_status = FINISHED)
        $ready = $this->wait_for_ig_media( $ig_user_id, $creation_id );
        if ( ! $ready ) {
            return [ 'success' => false, 'error' => 'IG video processing timed out (5 min).' ];
        }

        // Step 2: Publish
        $publish = $this->call( $ig_user_id . '/media_publish', [
            'creation_id'  => $creation_id,
            'access_token' => $this->access_token,
        ] );

        if ( ! empty( $publish['success'] ) && ! empty( $publish['raw']['id'] ) ) {
            $publish['media_id'] = $publish['raw']['id'];
        }
        return $publish;
    }

    /**
     * Reply to an Instagram comment.
     *
     * @param  string $ig_user_id IG Business Account ID.
     * @param  string $comment_id IG comment ID.
     * @param  string $message    Reply text.
     * @return array
     */
    public function reply_ig_comment( string $ig_user_id, string $comment_id, string $message ): array {
        return $this->call( $ig_user_id . '/replies', [
            'commented_media_id' => $comment_id,
            'message'            => $message,
            'access_token'       => $this->access_token,
        ] );
    }

    /**
     * Get comments for an IG media object.
     *
     * @param  string $ig_media_id IG media ID.
     * @return array
     */
    public function get_ig_comments( string $ig_media_id ): array {
        $fields = 'id,text,username,timestamp,like_count,replies{id,text,username,timestamp}';
        $url    = self::GRAPH_BASE . self::GRAPH_VERSION
            . "/{$ig_media_id}/comments?fields={$fields}&access_token=" . urlencode( $this->access_token );

        $response = wp_remote_get( $url, [ 'timeout' => $this->timeout ] );
        if ( is_wp_error( $response ) ) {
            return [ 'success' => false, 'error' => $response->get_error_message(), 'data' => [] ];
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        return [ 'success' => true, 'data' => $data['data'] ?? [] ];
    }

    /* ══════════════════════════════════════════════════════════════════
     *  ⑤ GROUPS
     * ══════════════════════════════════════════════════════════════════ */

    /**
     * Post to a Facebook Group.
     * Requires publish_to_groups permission + group access token.
     *
     * @param  string $group_id         FB Group ID.
     * @param  string $message          Post text.
     * @param  string $group_access_token  Group-specific access token.
     * @param  string $link             Optional URL to attach.
     * @return array
     */
    public function post_to_group( string $group_id, string $message, string $group_access_token, string $link = '' ): array {
        $body = [
            'message'      => $message,
            'access_token' => $group_access_token,
        ];
        if ( $link ) $body['link'] = $link;

        // Temporarily use group token
        $orig = $this->access_token;
        $this->access_token = $group_access_token;
        $result = $this->call( $group_id . '/feed', $body );
        $this->access_token = $orig;

        return $result;
    }

    /**
     * Post a comment to an existing Group post (seeding).
     *
     * @param  string $post_id            Full FB post ID.
     * @param  string $message            Comment text.
     * @param  string $group_access_token Group token.
     * @return array
     */
    public function comment_on_post( string $post_id, string $message, string $group_access_token ): array {
        return $this->call( $post_id . '/comments', [
            'message'      => $message,
            'access_token' => $group_access_token,
        ] );
    }

    /* ══════════════════════════════════════════════════════════════════
     *  PAGE INFO
     * ══════════════════════════════════════════════════════════════════ */

    /**
     * Get IG Business Account ID linked to this FB Page.
     * Required before calling any IG API method.
     *
     * @return string|null IG user ID or null if not connected.
     */
    public function get_ig_account_id(): ?string {
        $url = self::GRAPH_BASE . self::GRAPH_VERSION
            . "/{$this->page_id}?fields=instagram_business_account&access_token=" . urlencode( $this->access_token );

        $response = wp_remote_get( $url, [ 'timeout' => $this->timeout ] );
        if ( is_wp_error( $response ) ) return null;

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        return $data['instagram_business_account']['id'] ?? null;
    }

    /* ══════════════════════════════════════════════════════════════════
     *  BATCH HELPER — post to multiple pages
     * ══════════════════════════════════════════════════════════════════ */

    /**
     * Post to multiple Page connections.
     * Used by pipeline tool post_facebook.
     *
     * @param  string $message   Text content.
     * @param  string $image_url Optional image URL.
     * @param  string $link      Optional article link to append as CTA.
     * @param  array  $pages     Array of { page_id, access_token, name } from BizCity_FB_Database.
     * @return array  Array of { success, page_id, post_id, link, error? }
     */
    public static function post_to_pages( string $message, string $image_url = '', string $link = '', array $pages = [] ): array {
        if ( empty( $pages ) ) {
            // Fall back to site-stored pages
            $pages = BizCity_FB_Database::get_active_pages();
        }

        if ( empty( $pages ) ) {
            return [];
        }

        // Append link as CTA
        $full_message = $message;
        if ( $link && filter_var( $link, FILTER_VALIDATE_URL ) ) {
            $full_message = rtrim( $message ) . "\n\n🔗 Đọc thêm: " . $link;
        }

        $results = [];

        foreach ( $pages as $page ) {
            $pid   = $page['page_id']     ?? $page['id'] ?? '';
            $token = $page['access_token'] ?? $page['page_access_token'] ?? '';

            if ( empty( $pid ) || empty( $token ) ) continue;

            $api = new self( $token, $pid );

            $result = $image_url
                ? $api->post_photo( $image_url, $full_message )
                : $api->post_text( $full_message );

            $results[] = array_merge( $result, [
                'page_id'   => $pid,
                'page_name' => $page['page_name'] ?? $page['name'] ?? '',
            ] );
        }

        return $results;
    }

    /* ══════════════════════════════════════════════════════════════════
     *  LOW-LEVEL HTTP
     * ══════════════════════════════════════════════════════════════════ */

    /**
     * Make a POST call to the Graph API.
     *
     * @param  string $endpoint  Path after graph.facebook.com/v21.0/ (no leading slash).
     * @param  array  $body      POST body (including access_token).
     * @param  string $method    POST | DELETE (default POST).
     * @return array  { success, post_id?, raw[], error?, error_code? }
     */
    private function call( string $endpoint, array $body, string $method = 'POST' ): array {
        // Ensure access_token is in body
        if ( ! isset( $body['access_token'] ) ) {
            $body['access_token'] = $this->access_token;
        }

        $url = self::GRAPH_BASE . self::GRAPH_VERSION . '/' . ltrim( $endpoint, '/' );

        $args = [
            'method'  => $method,
            'body'    => $body,
            'timeout' => $this->timeout,
            'headers' => [ 'Accept' => 'application/json' ],
        ];

        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            error_log( '[BizCity_FB_Graph_API] WP_Error: ' . $response->get_error_message() . ' endpoint=' . $endpoint );
            return [
                'success' => false,
                'error'   => $response->get_error_message(),
            ];
        }

        $http_code = (int) wp_remote_retrieve_response_code( $response );
        $raw       = json_decode( wp_remote_retrieve_body( $response ), true ) ?: [];

        if ( $http_code >= 400 ) {
            $err = $raw['error']['message'] ?? "HTTP {$http_code}";
            $code = $raw['error']['code'] ?? $http_code;
            error_log( "[BizCity_FB_Graph_API] Error {$code}: {$err} | endpoint={$endpoint}" );
            return [
                'success'    => false,
                'error'      => $err,
                'error_code' => $code,
                'raw'        => $raw,
            ];
        }

        // Resolve canonical result IDs
        $post_id = $raw['post_id'] ?? $raw['id'] ?? '';

        return [
            'success' => true,
            'post_id' => $post_id,
            'raw'     => $raw,
        ];
    }

    /* ══════════════════════════════════════════════════════════════════
     *  PRIVATE HELPERS
     * ══════════════════════════════════════════════════════════════════ */

    /**
     * Poll IG media status until FINISHED or timeout.
     * IG video processing can take 30–120 seconds.
     *
     * @param  string $ig_user_id
     * @param  string $creation_id
     * @param  int    $max_seconds
     * @return bool   True if ready, false if timed out.
     */
    private function wait_for_ig_media( string $ig_user_id, string $creation_id, int $max_seconds = 300 ): bool {
        $start = time();
        while ( ( time() - $start ) < $max_seconds ) {
            $url = self::GRAPH_BASE . self::GRAPH_VERSION
                . "/{$ig_user_id}/media?fields=status_code&creation_id={$creation_id}&access_token=" . urlencode( $this->access_token );

            $response = wp_remote_get( $url, [ 'timeout' => 15 ] );
            if ( is_wp_error( $response ) ) break;

            $data = json_decode( wp_remote_retrieve_body( $response ), true );
            $status = $data['data'][0]['status_code'] ?? '';

            if ( $status === 'FINISHED' ) return true;
            if ( $status === 'ERROR' )    return false;

            sleep( 10 ); // poll every 10 seconds
        }
        return false;
    }
}
