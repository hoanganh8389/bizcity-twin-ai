<?php
/**
 * BizCity Tool Facebook — Standalone Webhook Handler
 *
 * Registers endpoint /bizfbhook/ to receive Facebook Platform events.
 * Fully independent — does NOT use /facehook/ or bizcity-facebook-bot.
 *
 * Developer configures their own Facebook App with:
 *   Webhook URL:   https://client.com/bizfbhook/
 *   Verify Token:  stored in wp_options: bztfb_verify_token
 *   Subscriptions: messages, messaging_postbacks, feed (comments)
 *
 * Routing (POST events):
 *   entry.messaging[]      → handle_messenger()   (Messenger chatbot)
 *   entry.changes[].field  → handle_feed_change() (comments, likes)
 *
 * Hooks fired (for extensibility):
 *   bztfb_messenger_message  ( $page_id, $psid, $text, $attachments, $raw )
 *   bztfb_messenger_postback ( $page_id, $psid, $payload, $raw )
 *   bztfb_comment_added      ( $page_id, $post_id, $comment_id, $sender_id, $message, $raw )
 *   bztfb_comment_reply      ( ... same as above, parent_comment_id set )
 *
 * @package BizCity\TwinAI\ToolFacebook
 * @since   2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BizCity_FB_Webhook {

    private static ?self $instance = null;

    const ENDPOINT_SLUG = 'bizfbhook';
    const QUERY_VAR     = 'bztfb_webhook_route';

    public static function instance(): self {
        if ( is_null( self::$instance ) ) self::$instance = new self();
        return self::$instance;
    }

    public function __construct() {
        add_action( 'init',              [ $this, 'register_rewrite' ], 0 );
        add_filter( 'query_vars',        [ $this, 'add_query_var' ] );
        add_action( 'template_redirect', [ $this, 'handle_request' ], 0 );
        // Fallback: ?bizfbhook=1
        add_action( 'init',              [ $this, 'handle_query_fallback' ], 1 );
    }

    /* ── Rewrite ── */

    public function register_rewrite(): void {
        add_rewrite_rule( '^bizfbhook/?$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
    }

    public function add_query_var( array $vars ): array {
        $vars[] = self::QUERY_VAR;
        return $vars;
    }

    public function handle_request(): void {
        if ( get_query_var( self::QUERY_VAR ) !== '1' ) return;
        $this->dispatch();
    }

    public function handle_query_fallback(): void {
        if ( isset( $_GET['bizfbhook'] ) && (string) $_GET['bizfbhook'] === '1' ) {
            $this->dispatch();
        }
    }

    /* ── Main Dispatcher ── */

    private function dispatch(): void {
        // Prevent caching
        foreach ( [ 'DONOTCACHEPAGE', 'DONOTCACHEDB', 'DONOTCACHEOBJECT' ] as $c ) {
            if ( ! defined( $c ) ) define( $c, true );
        }

        $method = strtoupper( $_SERVER['REQUEST_METHOD'] ?? 'GET' );

        if ( $method === 'GET' ) {
            $this->verify_challenge();
            exit;
        }

        if ( $method === 'POST' ) {
            $this->process_event();
            exit;
        }

        http_response_code( 405 );
        echo 'Method Not Allowed';
        exit;
    }

    /* ── GET: Webhook Verification ── */

    private function verify_challenge(): void {
        $mode      = (string) ( $_GET['hub_mode']         ?? $_GET['hub.mode']         ?? '' );
        $token     = (string) ( $_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? '' );
        $challenge = (string) ( $_GET['hub_challenge']    ?? $_GET['hub.challenge']    ?? '' );

        $stored_token = get_option( 'bztfb_verify_token', 'bizfbhook' );

        if ( $mode === 'subscribe' && hash_equals( $stored_token, $token ) && $challenge !== '' ) {
            $this->log( 'Webhook VERIFIED' );
            while ( ob_get_level() ) ob_end_clean();
            http_response_code( 200 );
            header( 'Content-Type: text/plain; charset=utf-8' );
            echo $challenge;
            exit;
        }

        $this->log( 'Webhook verify FAILED', [ 'mode' => $mode, 'token' => $token ] );
        http_response_code( 403 );
        echo 'Forbidden';
        exit;
    }

    /* ── POST: Process Events ── */

    private function process_event(): void {
        $raw_input = file_get_contents( 'php://input' );

        // Signature verification (requires app_secret in settings)
        if ( ! $this->verify_signature( $raw_input ) ) {
            http_response_code( 403 );
            echo 'Invalid signature';
            exit;
        }

        if ( empty( $raw_input ) ) {
            status_header( 200 );
            echo 'OK';
            exit;
        }

        $payload = json_decode( $raw_input, true );

        if ( ! is_array( $payload ) || empty( $payload['entry'] ) ) {
            status_header( 200 );
            echo 'OK';
            exit;
        }

        foreach ( $payload['entry'] as $entry ) {
            $page_id = $entry['id'] ?? '';

            // ── Messenger events ──
            if ( ! empty( $entry['messaging'] ) ) {
                foreach ( $entry['messaging'] as $messaging ) {
                    $this->handle_messenger( $page_id, $messaging );
                }
            }

            // ── Feed changes (comments, reactions, etc.) ──
            if ( ! empty( $entry['changes'] ) ) {
                foreach ( $entry['changes'] as $change ) {
                    $this->handle_feed_change( $page_id, $change );
                }
            }
        }

        status_header( 200 );
        echo 'OK';
        exit;
    }

    /* ── Messenger Handler ── */

    private function handle_messenger( string $page_id, array $messaging ): void {
        $psid = $messaging['sender']['id'] ?? '';

        // Skip echoed messages (page sent to itself)
        if ( $psid === $page_id ) return;

        // Dedup
        $mid = $messaging['message']['mid'] ?? '';
        if ( $mid && get_transient( 'bztfb_mid_' . md5( $mid ) ) ) return;
        if ( $mid ) set_transient( 'bztfb_mid_' . md5( $mid ), 1, 2 * MINUTE_IN_SECONDS );

        // Update inbox
        BizCity_FB_Database::upsert_inbox( $psid, $page_id, [
            'last_message' => mb_substr( $messaging['message']['text'] ?? '[attachment]', 0, 200 ),
            'last_sender'  => 'user',
            'last_msg_id'  => $mid,
            'unread'       => 1,  // will be reset when staff/bot replies
        ] );

        // Log message
        $attachments = $messaging['message']['attachments'] ?? [];
        $attachment_url = '';
        $attachment_type = '';
        if ( ! empty( $attachments[0] ) ) {
            $attachment_type = $attachments[0]['type'] ?? '';
            $attachment_url  = $attachments[0]['payload']['url'] ?? '';
        }

        BizCity_FB_Database::log_message( [
            'psid'            => $psid,
            'page_id'         => $page_id,
            'message_id'      => $mid,
            'direction'       => 'in',
            'message_type'    => empty( $messaging['message']['text'] ) ? 'attachment' : 'text',
            'message_text'    => $messaging['message']['text'] ?? '',
            'attachment_type' => $attachment_type,
            'attachment_url'  => $attachment_url,
            'raw_payload'     => $messaging,
        ] );

        $text = $messaging['message']['text'] ?? '';

        // ── Hook: plugins can handle the message ──
        do_action( 'bztfb_messenger_message', $page_id, $psid, $text, $attachments, $messaging );

        // Postbacks
        if ( ! empty( $messaging['postback'] ) ) {
            $payload_str = $messaging['postback']['payload'] ?? '';
            do_action( 'bztfb_messenger_postback', $page_id, $psid, $payload_str, $messaging );
        }

        // ── Bridge to twin-ai Gateway (Channel Role + AI response) ──
        if ( $text && class_exists( 'BizCity_Gateway_Bridge' ) ) {
            $gw_payload = [
                'platform'    => 'FACEBOOK',
                'chat_id'     => 'fb_' . $page_id . '_' . $psid,
                'user_id'     => $psid,
                'client_name' => '',
                'message'     => $text,
                'message_id'  => $mid,
                'attachments' => [],
                'event_type'  => 'message',
                'bot_id'      => $page_id,
                'raw'         => $messaging,
            ];

            BizCity_Gateway_Bridge::instance()->fire_trigger( $gw_payload, $messaging );

        } elseif ( $text && has_filter( 'bztfb_ai_reply_message' ) ) {
            // ── Fallback: Built-in AI reply (legacy filter path) ──
            $ai_reply = apply_filters( 'bztfb_ai_reply_message', '', $page_id, $psid, $text );
            if ( $ai_reply ) {
                $page = BizCity_FB_Database::get_page( $page_id );
                if ( $page ) {
                    $api = new BizCity_FB_Graph_API( $page['page_access_token'], $page_id );
                    $api->send_message( $psid, $ai_reply );
                    BizCity_FB_Database::log_message( [
                        'psid'         => $psid,
                        'page_id'      => $page_id,
                        'direction'    => 'out',
                        'message_type' => 'text',
                        'message_text' => $ai_reply,
                    ] );
                }
            }
        }
    }

    /* ── Feed / Comment Handler ── */

    private function handle_feed_change( string $page_id, array $change ): void {
        $field = $change['field'] ?? '';
        $value = $change['value'] ?? [];

        if ( $field !== 'feed' ) return;

        $item  = $value['item']  ?? '';
        $verb  = $value['verb']  ?? '';

        // We only care about comments
        if ( $item !== 'comment' || ! in_array( $verb, [ 'add', 'edited' ], true ) ) return;

        $comment_id        = $value['comment_id']        ?? '';
        $parent_comment_id = $value['parent_id']         ?? '';
        $post_id           = $value['post_id']           ?? '';
        $sender_id         = $value['from']['id']        ?? '';
        $sender_name       = $value['from']['name']      ?? '';
        $message           = $value['message']           ?? '';

        if ( empty( $comment_id ) || $sender_id === $page_id ) return;

        // Log comment
        BizCity_FB_Database::log_comment( [
            'page_id'          => $page_id,
            'fb_post_id'       => $post_id,
            'post_type'        => 'feed',
            'comment_id'       => $comment_id,
            'parent_comment_id'=> $parent_comment_id,
            'sender_id'        => $sender_id,
            'sender_name'      => $sender_name,
            'message'          => $message,
        ] );

        // Hook
        do_action( 'bztfb_comment_added', $page_id, $post_id, $comment_id, $sender_id, $message, $value );

        // ── Built-in AI comment reply ──
        if ( has_filter( 'bztfb_ai_reply_comment' ) ) {
            $ai_reply = apply_filters( 'bztfb_ai_reply_comment', '', $page_id, $post_id, $message, $sender_name );
            if ( $ai_reply ) {
                $page = BizCity_FB_Database::get_page( $page_id );
                if ( $page ) {
                    $api = new BizCity_FB_Graph_API( $page['page_access_token'], $page_id );
                    $result = $api->reply_comment( $comment_id, $ai_reply );
                    if ( ! empty( $result['success'] ) ) {
                        BizCity_FB_Database::mark_comment_replied( $comment_id, $ai_reply );
                    }
                }
            }
        }
    }

    /* ── Signature Verification ── */

    /**
     * Verify X-Hub-Signature-256 header matches app_secret.
     * Only enforced if bztfb_app_secret is configured.
     */
    private function verify_signature( string $body ): bool {
        $app_secret = get_option( 'bztfb_app_secret', '' );
        if ( empty( $app_secret ) ) return true; // not configured → skip (dev mode)

        $header = $_SERVER['HTTP_X_HUB_SIGNATURE_256']
                ?? $_SERVER['HTTP_X_HUB_SIGNATURE']
                ?? '';

        if ( empty( $header ) ) return false;

        $algo      = 'sha256';
        $signature = 'sha256=' . hash_hmac( 'sha256', $body, $app_secret );

        return hash_equals( $signature, $header );
    }

    /* ── Logging ── */

    private function log( string $msg, array $ctx = [] ): void {
        $entry = '[BizCity_FB_Webhook] ' . $msg;
        if ( $ctx ) $entry .= ' | ' . wp_json_encode( $ctx, JSON_UNESCAPED_UNICODE );
        error_log( $entry );
    }
}
