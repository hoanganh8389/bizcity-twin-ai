<?php
defined( 'ABSPATH' ) || exit;

/**
 * Cron — Scheduled tasks.
 */
class BCN_Cron {

    public function schedule() {
        if ( ! wp_next_scheduled( 'bcn_cleanup_outputs' ) ) {
            wp_schedule_event( time(), 'daily', 'bcn_cleanup_outputs' );
        }
        if ( ! wp_next_scheduled( 'bcn_retry_failed_sources' ) ) {
            wp_schedule_event( time(), 'hourly', 'bcn_retry_failed_sources' );
        }
    }

    public function unschedule() {
        wp_clear_scheduled_hook( 'bcn_cleanup_outputs' );
        wp_clear_scheduled_hook( 'bcn_retry_failed_sources' );
    }

    /**
     * Cleanup studio outputs older than 30 days that are in error state.
     */
    public function cron_cleanup_outputs() {
        global $wpdb;
        $table = BCN_Schema_Extend::table_studio_outputs();
        $wpdb->query(
            "DELETE FROM {$table} WHERE status = 'error' AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );
    }

    /**
     * Retry failed source extractions (max 3 attempts).
     */
    public function cron_retry_failed_sources() {
        global $wpdb;
        $table = BCN_Schema_Extend::table_source_extractor();

        $failed = $wpdb->get_results(
            "SELECT * FROM {$table} WHERE status = 'failed' AND attempt_count < 3 ORDER BY created_at ASC LIMIT 5"
        );

        foreach ( $failed as $job ) {
            $wpdb->update( $table, [
                'status'        => 'processing',
                'attempt_count' => $job->attempt_count + 1,
                'started_at'    => current_time( 'mysql' ),
            ], [ 'id' => $job->id ] );

            $extractor = new BCN_Source_Extractor();
            $result = $extractor->extract( $job->extractor_type, $job->input_url );

            if ( ! empty( $result['error'] ) ) {
                $wpdb->update( $table, [
                    'status'     => 'failed',
                    'last_error' => $result['error'],
                ], [ 'id' => $job->id ] );
            } else {
                // Update the source content.
                $sources_table = BCN_Schema_Extend::table_sources();
                $wpdb->update( $sources_table, [
                    'content_text'  => $result['text'],
                    'char_count'    => mb_strlen( $result['text'] ),
                    'token_estimate' => (int) ( mb_strlen( $result['text'] ) / 4 ),
                    'status'        => 'ready',
                ], [ 'id' => $job->source_id ] );

                $wpdb->update( $table, [
                    'status'           => 'completed',
                    'output_chars'     => mb_strlen( $result['text'] ),
                    'completed_at'     => current_time( 'mysql' ),
                    'processing_time_ms' => 0,
                ], [ 'id' => $job->id ] );
            }
        }
    }
}
