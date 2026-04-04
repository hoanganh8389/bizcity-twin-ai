<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Plugins\Companion_Notebook
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

defined( 'ABSPATH' ) || exit;

/**
 * AJAX Handler — backup endpoints for admin context.
 */
class BCN_Ajax_Handler {

    public function register() {
        $actions = [
            'bcn_chat_stream'            => 'handle_chat_stream',
            'bcn_upload_source'          => 'handle_upload_source',
            'bcn_pin_message'            => 'handle_pin_message',
            'bcn_generate_studio'        => 'handle_generate_studio',
            'bcn_generate_studio_status' => 'handle_generate_studio_status',
            'bcn_embed_source'           => 'handle_embed_source',
            'bcn_embed_project'          => 'handle_embed_project',
            'bcn_session_close'          => 'handle_session_close',
        ];

        foreach ( $actions as $action => $method ) {
            add_action( "wp_ajax_{$action}", [ $this, $method ] );
        }
    }

    public function handle_chat_stream() {
        $engine = new BCN_Chat_Engine();
        $engine->handle_sse();
    }

    public function handle_upload_source() {
        check_ajax_referer( 'bcn_ajax' );

        $project_id = sanitize_text_field( $_POST['project_id'] ?? '' );
        if ( ! $project_id || empty( $_FILES['file'] ) ) {
            wp_send_json_error( 'Missing data' );
        }

        $sources = new BCN_Sources();
        $id = $sources->upload( $project_id, $_FILES['file'] );

        if ( is_wp_error( $id ) ) {
            wp_send_json_error( $id->get_error_message() );
        }

        wp_send_json_success( $sources->get( $id ) );
    }

    public function handle_pin_message() {
        check_ajax_referer( 'bcn_ajax' );

        $project_id = sanitize_text_field( $_POST['project_id'] ?? '' );
        $message_id = absint( $_POST['message_id'] ?? 0 );

        if ( ! $project_id || ! $message_id ) {
            wp_send_json_error( 'Missing data' );
        }

        $notes = new BCN_Notes();
        $id = $notes->pin_from_message( $project_id, $message_id, get_current_user_id() );

        if ( is_wp_error( $id ) ) {
            wp_send_json_error( $id->get_error_message() );
        }

        global $wpdb;
        $note = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . BCN_Schema_Extend::table_notes() . " WHERE id = %d",
            $id
        ) );

        wp_send_json_success( $note );
    }

    public function handle_generate_studio() {
        check_ajax_referer( 'bcn_ajax' );

        $project_id = sanitize_text_field( $_POST['project_id'] ?? '' );
        $tool_type  = sanitize_text_field( $_POST['tool_type'] ?? '' );

        if ( ! $project_id || ! $tool_type ) {
            wp_send_json_error( 'Missing data' );
        }

        $user_id = get_current_user_id();

        // ── Async: return job_id immediately to avoid Cloudflare 524 ──
        $job_id = 'bcn_studio_' . bin2hex( random_bytes( 8 ) );

        set_transient( 'bcn_studio_job_' . $job_id, [
            'status'     => 'processing',
            'started_at' => time(),
            'tool_type'  => $tool_type,
            'project_id' => $project_id,
        ], 600 );

        // Flush response to browser before running the heavy AI call
        ob_end_clean();
        header( 'Content-Type: application/json; charset=UTF-8' );
        $payload = wp_json_encode( [
            'success' => true,
            'data'    => [ 'job_id' => $job_id, 'async' => true ],
        ] );
        header( 'Content-Length: ' . strlen( $payload ) );
        echo $payload;

        if ( function_exists( 'fastcgi_finish_request' ) ) {
            fastcgi_finish_request();
        } else {
            if ( ob_get_level() ) ob_end_flush();
            flush();
        }

        // ── Background processing ──
        ignore_user_abort( true );
        set_time_limit( 300 );

        $studio = new BCN_Studio();
        $id     = $studio->generate( $project_id, $tool_type, $user_id );

        if ( is_wp_error( $id ) ) {
            set_transient( 'bcn_studio_job_' . $job_id, [
                'status' => 'failed',
                'error'  => $id->get_error_message(),
            ], 600 );
        } else {
            set_transient( 'bcn_studio_job_' . $job_id, [
                'status' => 'completed',
                'data'   => $studio->get_output( $id ),
            ], 600 );
        }

        exit;
    }

    public function handle_generate_studio_status() {
        check_ajax_referer( 'bcn_ajax' );

        $job_id = sanitize_text_field( $_POST['job_id'] ?? '' );
        if ( ! $job_id ) {
            wp_send_json_error( [ 'message' => 'Missing job_id' ] );
        }

        $job = get_transient( 'bcn_studio_job_' . $job_id );
        if ( ! $job ) {
            wp_send_json_error( [ 'message' => 'Job not found or expired', 'status' => 'not_found' ] );
        }

        if ( $job['status'] === 'processing' ) {
            wp_send_json_success( [
                'status'  => 'processing',
                'elapsed' => time() - ( $job['started_at'] ?? time() ),
            ] );
        }

        if ( $job['status'] === 'failed' ) {
            delete_transient( 'bcn_studio_job_' . $job_id );
            wp_send_json_error( [ 'message' => $job['error'] ?? 'Generation failed', 'status' => 'failed' ] );
        }

        // completed
        $data = $job['data'] ?? null;
        delete_transient( 'bcn_studio_job_' . $job_id );
        wp_send_json_success( [ 'status' => 'completed', 'data' => $data ] );
    }

    public function handle_embed_source() {
        check_ajax_referer( 'bcn_ajax' );

        $source_id = absint( $_POST['source_id'] ?? 0 );
        if ( ! $source_id ) {
            wp_send_json_error( 'Missing source_id' );
        }

        $model = sanitize_text_field( $_POST['model'] ?? '' );

        // Increase time limit for large sources.
        if ( function_exists( 'set_time_limit' ) ) {
            set_time_limit( 300 );
        }

        $embedder = new BCN_Embedder();
        $result   = $embedder->embed_source( $source_id, $model );

        if ( ! $result['success'] ) {
            wp_send_json_error( $result['error'] );
        }

        wp_send_json_success( [
            'source_id' => $source_id,
            'chunks'    => $result['chunks'],
        ] );
    }

    public function handle_embed_project() {
        check_ajax_referer( 'bcn_ajax' );

        $project_id = sanitize_text_field( $_POST['project_id'] ?? '' );
        if ( ! $project_id ) {
            wp_send_json_error( 'Missing project_id' );
        }

        $model = sanitize_text_field( $_POST['model'] ?? '' );

        if ( function_exists( 'set_time_limit' ) ) {
            set_time_limit( 600 );
        }

        $embedder = new BCN_Embedder();
        $result   = $embedder->embed_project( $project_id, $model );

        wp_send_json_success( $result );
    }

    /**
     * Handle session close — force research memory summary.
     * Called via navigator.sendBeacon() when user closes the tab.
     */
    public function handle_session_close() {
        check_ajax_referer( 'bcn_ajax' );

        $project_id = sanitize_text_field( $_POST['project_id'] ?? '' );
        $session_id = sanitize_text_field( $_POST['session_id'] ?? '' );
        $user_id    = get_current_user_id();

        if ( ! $project_id || ! $user_id ) {
            wp_send_json_error( 'Missing data' );
        }

        // If no session_id provided, look up the active one.
        if ( ! $session_id ) {
            $messages_handler = new BCN_Messages();
            $session_id = $messages_handler->ensure_session( $project_id, $user_id );
        }

        BCN_Research_Memory::instance()->force_summarize( $project_id, $session_id, $user_id );
        wp_send_json_success( [ 'summarized' => true ] );
    }
}
