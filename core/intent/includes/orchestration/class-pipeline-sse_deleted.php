<?php
/**
 * Pipeline SSE — Server-Sent Events endpoint for real-time pipeline monitoring.
 *
 * Fires `bizcity_pipeline_node_event` action → transient queue → SSE stream.
 *
 * @package BizCity_Twin_AI
 * @since   Phase 1.2 — Pipeline Visualization Sidebar (v2.4)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BizCity_Pipeline_SSE {

    /** Transient prefix for SSE queues */
    const QUEUE_PREFIX = 'bc_sse_';

    /** Max SSE connection time (seconds) */
    const MAX_CONNECTION_TIME = 60;

    /** Queue poll interval (microseconds) */
    const POLL_INTERVAL = 500000; // 0.5s

    /** Queue TTL (seconds) */
    const QUEUE_TTL = 300; // 5 min buffer

    /**
     * Boot hooks.
     */
    public static function init() {
        add_action( 'wp_ajax_bizc_pipeline_sse', [ __CLASS__, 'handle_sse' ] );
        add_action( 'bizcity_pipeline_node_event', [ __CLASS__, 'enqueue_event' ], 10, 1 );
    }

    /**
     * SSE endpoint — streams pipeline node events to the browser.
     *
     * URL: admin-ajax.php?action=bizc_pipeline_sse&pid=…&_wpnonce=…
     */
    public static function handle_sse() {
        $pid = isset( $_GET['pid'] ) ? sanitize_text_field( wp_unslash( $_GET['pid'] ) ) : '';

        if ( ! $pid || ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Forbidden', 403 );
        }

        check_ajax_referer( 'bizc_pipeline_nonce', '_wpnonce' );

        // Disable output buffering for real-time streaming
        if ( function_exists( 'apache_setenv' ) ) {
            apache_setenv( 'no-gzip', '1' );
        }
        @ini_set( 'zlib.output_compression', '0' ); // phpcs:ignore

        header( 'Content-Type: text/event-stream; charset=utf-8' );
        header( 'Cache-Control: no-cache' );
        header( 'X-Accel-Buffering: no' );

        // Flush any existing output buffers
        while ( ob_get_level() > 0 ) {
            ob_end_flush();
        }

        $last_index = 0;
        $start      = time();

        while ( ( time() - $start ) < self::MAX_CONNECTION_TIME ) {
            $queue      = get_transient( self::QUEUE_PREFIX . $pid );
            $queue      = is_array( $queue ) ? $queue : [];
            $new_events = array_slice( $queue, $last_index );

            foreach ( $new_events as $evt ) {
                echo 'data: ' . wp_json_encode( $evt ) . "\n\n";
                $last_index++;
            }

            // Check for pipeline completion
            if ( ! empty( $new_events ) ) {
                $last_evt = end( $new_events );
                if ( isset( $last_evt['event'] ) && $last_evt['event'] === 'pipeline_done' ) {
                    echo "event: done\ndata: {}\n\n";
                    break;
                }
            }

            if ( function_exists( 'ob_flush' ) ) {
                @ob_flush();
            }
            flush();

            if ( connection_aborted() ) {
                break;
            }

            usleep( self::POLL_INTERVAL );
        }

        // Final done signal
        echo "event: done\ndata: {}\n\n";
        if ( function_exists( 'ob_flush' ) ) {
            @ob_flush();
        }
        flush();
        exit;
    }

    /**
     * Hook handler — push an event into the transient queue.
     *
     * Usage:
     *   do_action( 'bizcity_pipeline_node_event', [
     *       'pipeline_id'    => 'abc123',
     *       'node_id'        => '3',
     *       'event'          => 'started|completed|failed|waiting|pipeline_done',
     *       'tool'           => 'generate_blog_content',
     *       'skill_used'     => 'marketing-blog-v1',
     *       'duration_ms'    => 3241,
     *       'output_preview' => 'Marketing 2026 — xu hướng mới...',
     *       'progress'       => 0.65,
     *       'log_line'       => '[CONTENT-ENGINE] build_skill_prompt...',
     *   ] );
     *
     * @param array $event Event payload.
     */
    public static function enqueue_event( $event ) {
        if ( empty( $event['pipeline_id'] ) ) {
            return;
        }

        $queue_key = self::QUEUE_PREFIX . sanitize_text_field( $event['pipeline_id'] );
        $queue     = get_transient( $queue_key );
        $queue     = is_array( $queue ) ? $queue : [];

        $event['timestamp'] = microtime( true );
        $queue[]            = $event;

        set_transient( $queue_key, $queue, self::QUEUE_TTL );
    }

    /**
     * Clean up a finished pipeline's event queue.
     *
     * @param string $pipeline_id Pipeline execution ID.
     */
    public static function cleanup( $pipeline_id ) {
        delete_transient( self::QUEUE_PREFIX . sanitize_text_field( $pipeline_id ) );
    }
}
