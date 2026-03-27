<?php
defined( 'ABSPATH' ) || exit;

/**
 * Deep Research — orchestrates Tavily search, ranking, and source import.
 *
 * Flow:
 *   1. run()           → creates a Job row (status: pending), schedules async
 *   2. process_job()   → called by WP action hook (synchronous fallback if no AS)
 *   3. import_selected() → takes chosen URLs from completed job → BCN_Sources::add()
 */
class BCN_Deep_Research {

    private function table() {
        return BCN_Schema_Extend::table_research_jobs();
    }

    // ── Public API ────────────────────────────────────────────────────────

    /**
     * Start a research job for a project.
     *
     * @param string $project_id
     * @param string $query
     * @param array  $options   max_results (int), language (string)
     * @return int|WP_Error  Job ID on success.
     */
    public function run( $project_id, $query, array $options = [] ) {
        global $wpdb;

        $query = trim( $query );
        if ( empty( $query ) ) {
            return new WP_Error( 'empty_query', 'Query cannot be empty.' );
        }

        $max_results = min( 10, max( 1, (int) ( $options['max_results'] ?? 5 ) ) );
        $language    = sanitize_text_field( $options['language'] ?? 'vi' );

        // Insert job row.
        $wpdb->insert( $this->table(), [
            'project_id' => $project_id,
            'user_id'    => get_current_user_id(),
            'query'      => $query,
            'status'     => 'pending',
        ] );
        $job_id = (int) $wpdb->insert_id;
        if ( ! $job_id ) {
            return new WP_Error( 'db_error', 'Failed to create research job.' );
        }

        // ── Async scheduling (Action Scheduler is disabled on this server) ──
        //
        // Priority order:
        //   1. WP-Cron single event  — lightweight, no extra tables, works on all installs
        //   2. Non-blocking admin-ajax loopback — fires immediately, no cron dependency
        //   3. Synchronous fallback  — blocks HTTP response but always works
        //
        $scheduled = $this->schedule_async( $job_id, $max_results, $language );

        if ( ! $scheduled ) {
            // Synchronous fallback: runs in the current request.
            $this->process_job( $job_id, $max_results, $language );
        }

        return $job_id;
    }

    /**
     * Get job status + candidates (for polling).
     *
     * @param int    $job_id
     * @param string $project_id  For ownership check.
     * @return array|WP_Error
     */
    public function get_status( $job_id, $project_id ) {
        global $wpdb;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE id = %d AND project_id = %s LIMIT 1",
            $job_id, $project_id
        ) );

        if ( ! $row ) {
            return new WP_Error( 'not_found', 'Research job not found.' );
        }

        $candidates = [];
        if ( ! empty( $row->result_json ) ) {
            $candidates = json_decode( $row->result_json, true ) ?: [];
        }

        return [
            'job_id'         => (int) $row->id,
            'status'         => $row->status,
            'total_urls'     => (int) $row->total_urls,
            'processed_urls' => (int) $row->processed_urls,
            'candidates'     => $candidates,
            'error'          => $row->error_message,
        ];
    }

    /**
     * Import selected URLs from a completed job as project sources.
     *
     * @param int    $job_id
     * @param array  $selected_urls  Array of URL strings to import.
     * @param string $project_id     For ownership check.
     * @return array|WP_Error  Imported source IDs.
     */
    public function import_selected( $job_id, array $selected_urls, $project_id ) {
        global $wpdb;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE id = %d AND project_id = %s AND status = 'completed' LIMIT 1",
            $job_id, $project_id
        ) );

        if ( ! $row ) {
            return new WP_Error( 'not_found', 'Completed research job not found.' );
        }

        $candidates = json_decode( $row->result_json, true ) ?: [];
        if ( empty( $candidates ) ) {
            return new WP_Error( 'no_results', 'No candidates in research job.' );
        }

        // Index candidates by URL for quick lookup.
        $by_url = [];
        foreach ( $candidates as $c ) {
            $by_url[ $c['url'] ] = $c;
        }

        $sources_model = new BCN_Sources();
        $imported_ids  = [];

        foreach ( $selected_urls as $url ) {
            $url = esc_url_raw( trim( $url ) );
            if ( empty( $url ) || ! isset( $by_url[ $url ] ) ) continue;

            $candidate = $by_url[ $url ];

            // Use pre-fetched content — skip HTTP re-extraction.
            $source_id = $sources_model->add( $project_id, [
                'source_type'  => 'url',
                'source_url'   => $url,
                'title'        => $candidate['title'] ?: $url,
                'content_text' => $candidate['content'] ?? '',
                'skip_extract' => true,
            ] );

            if ( ! is_wp_error( $source_id ) ) {
                $imported_ids[] = (int) $source_id;
            }
        }

        return $imported_ids;
    }

    /**
     * Delete / cancel a job.
     *
     * @param int    $job_id
     * @param string $project_id
     * @return bool|WP_Error
     */
    public function delete_job( $job_id, $project_id ) {
        global $wpdb;

        $deleted = $wpdb->delete(
            $this->table(),
            [ 'id' => $job_id, 'project_id' => $project_id ],
            [ '%d', '%s' ]
        );

        if ( $deleted === false ) {
            return new WP_Error( 'db_error', 'Failed to delete job.' );
        }
        return true;
    }

    // ── Async scheduling helpers ──────────────────────────────────────────

    /**
     * Try to schedule the job asynchronously.
     *
     * Priority:
     * 1. Non-blocking admin-ajax loopback — fires immediately while user is on the page.
     *    Also schedules a WP-Cron event 90 s later as a safety net (in case the admin-ajax
     *    PHP process is killed when the user navigates away before it finishes).
     * 2. WP-Cron only — used when the admin-ajax loopback POST itself fails (network error).
     *
     * @return bool  true if async dispatch succeeded, false if caller must run synchronously.
     */
    private function schedule_async( int $job_id, int $max_results, string $language ): bool {

        // ── 1. Admin-ajax non-blocking loopback ─────────────────────────────
        // Fire a POST to admin-ajax.php?action=bcn_run_research_job with
        // blocking=false so this returns instantly and the PHP process runs in
        // the background (ignore_user_abort(true) keeps it alive even after the
        // user navigates away).
        $ajax_url = admin_url( 'admin-ajax.php' );
        $nonce    = wp_create_nonce( 'bcn_research_async_' . $job_id );

        $response = wp_remote_post( $ajax_url, [
            'blocking'    => false,
            'timeout'     => 1,
            'sslverify'   => false,
            'redirection' => 0,
            'body'        => [
                'action'      => 'bcn_run_research_job',
                'job_id'      => $job_id,
                'max_results' => $max_results,
                'language'    => $language,
                'nonce'       => $nonce,
            ],
        ] );

        if ( ! is_wp_error( $response ) ) {
            // Schedule a WP-Cron safety net 90 s later.  If admin-ajax finishes
            // on time the job will be 'completed' and process_job() will bail
            // immediately (idempotency check).  If the process was killed the
            // cron event picks up and finishes the job in the background so the
            // user sees the results when they return to the page.
            wp_schedule_single_event(
                time() + 90,
                'bcn_process_research_job',
                [ $job_id, $max_results, $language ]
            );
            error_log( "[BCN Research] Job #{$job_id} dispatched via admin-ajax loopback (+ WP-Cron 90 s safety net)." );
            return true;
        }

        // ── 2. WP-Cron fallback ─────────────────────────────────────────────
        // Admin-ajax POST failed; schedule the job via WP-Cron so it runs on
        // the next page load / spawn_cron() call.  The user will see the results
        // next time they open the notebook.
        $ok = wp_schedule_single_event(
            time() - 1,
            'bcn_process_research_job',
            [ $job_id, $max_results, $language ]
        );

        if ( false !== $ok ) {
            if ( function_exists( 'spawn_cron' ) ) {
                spawn_cron();
            }
            error_log( "[BCN Research] Job #{$job_id} scheduled via WP-Cron (admin-ajax fallback)." );
            return true;
        }

        error_log( "[BCN Research] Job #{$job_id} async dispatch failed entirely — will run synchronously." );
        return false;
    }

    // ── Async handler ─────────────────────────────────────────────────────

    /**
     * Process job — called by WP-Cron, admin-ajax, or synchronously as fallback.
     *
     * @param int $job_id
     * @param int $max_results
     */
    public function process_job( $job_id, $max_results = 5, $language = 'vi' ) {
        global $wpdb;

        $table = $this->table();

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d LIMIT 1",
            $job_id
        ) );

        if ( ! $row || $row->status === 'completed' || $row->status === 'failed' ) {
            return;
        }

        // Mark running.
        $wpdb->update( $table, [ 'status' => 'running' ], [ 'id' => $job_id ] );

        // Search.
        $results = BCN_Tavily_Client::search( $row->query, max( $max_results * 2, 10 ), $language );

        if ( is_wp_error( $results ) ) {
            $wpdb->update( $table, [
                'status'        => 'failed',
                'error_message' => $results->get_error_message(),
            ], [ 'id' => $job_id ] );
            return;
        }

        // Rank — reduce to top $max_results.
        $ranked = BCN_Research_Ranker::rank( $results, $max_results );

        $total = count( $ranked );

        $wpdb->update( $table, [
            'status'         => 'completed',
            'total_urls'     => $total,
            'processed_urls' => $total,
            'result_json'    => wp_json_encode( $ranked, JSON_UNESCAPED_UNICODE ),
        ], [ 'id' => $job_id ] );
    }
}
