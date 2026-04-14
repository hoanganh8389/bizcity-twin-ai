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
 * Main Plugin Class — Singleton boot.
 */
class BCN_Plugin {
    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function boot() {
        // Schema — create tables on first load if needed.
        add_action( 'init', [ BCN_Schema_Extend::class, 'maybe_upgrade' ] );

        // Allow notebook file types for upload.
        add_filter( 'upload_mimes', [ $this, 'add_notebook_mimes' ] );
        add_filter( 'wp_check_filetype_and_ext', [ $this, 'fix_notebook_filetype' ], 10, 5 );

        // Admin page.
        $admin = new BCN_Admin_Page();
        // Menu registration moved to BizCity_Admin_Menu (centralized).
        add_action( 'admin_enqueue_scripts', [ $admin, 'enqueue_assets' ] );

        // REST API.
        $rest = new BCN_REST_API();
        add_action( 'rest_api_init', [ $rest, 'register_routes' ] );

        // AJAX handlers.
        $ajax = new BCN_Ajax_Handler();
        $ajax->register();

        // Chat engine hooks (source context injection).
        $chat = new BCN_Chat_Engine();
        $chat->register_hooks();

        // Cron.
        $cron = new BCN_Cron();
        add_action( 'bcn_cleanup_outputs', [ $cron, 'cron_cleanup_outputs' ] );
        add_action( 'bcn_retry_failed_sources', [ $cron, 'cron_retry_failed_sources' ] );

        // Deep Research — async job handler.
        // Registered for BOTH WP-Cron (single event) and Action Scheduler (if available).
        add_action( 'bcn_process_research_job', function ( $job_id, $max_results = 5, $language = 'vi' ) {
            ( new BCN_Deep_Research() )->process_job( (int) $job_id, (int) $max_results, (string) $language );
        }, 10, 3 );

        // Admin-ajax loopback handler (fallback when WP-Cron also fails).
        // Accepts both logged-in and non-logged-in requests (the nonce replaces auth).
        add_action( 'wp_ajax_bcn_run_research_job',        [ $this, 'ajax_run_research_job' ] );
        add_action( 'wp_ajax_nopriv_bcn_run_research_job', [ $this, 'ajax_run_research_job' ] );

        // ── BizCity Intent Engine: provide skeleton as Layer 7 context ──
        // Filter fired by BizCity_Context_Builder::build_notebook_skeleton_context()
        // when a project_id is active during a chat turn.
        add_filter( 'bcn_build_skeleton_context', [ $this, 'provide_skeleton_context' ], 10, 2 );

        // Invalidate skeleton when project sources or notes change.
        add_action( 'bcn_source_added',   [ $this, 'invalidate_skeleton_on_change' ] );
        add_action( 'bcn_source_deleted', [ $this, 'invalidate_skeleton_on_change' ] );
        add_action( 'bcn_note_created',   [ $this, 'invalidate_skeleton_on_change' ] );
        add_action( 'bcn_note_updated',   [ $this, 'invalidate_skeleton_on_change' ] );
        add_action( 'bcn_note_deleted',   [ $this, 'invalidate_skeleton_on_change' ] );

        // When a note is saved (created or updated), proactively rebuild the skeleton
        // on shutdown — after the response is already sent, in the same PHP process.
        // No WP-Cron, no admin-ajax, no extra HTTP round-trip.
        add_action( 'bcn_note_created', [ $this, 'queue_skeleton_rebuild_on_shutdown' ], 20, 2 );
        add_action( 'bcn_note_updated', [ $this, 'queue_skeleton_rebuild_on_shutdown' ], 20, 2 );
    }

    /**
     * Provide Notebook context via keyword-matched notes for Layer 7.
     *
     * Called by BizCity_Context_Builder via filter `bcn_build_skeleton_context`.
     * Searches research_auto + chat_pinned + manual notes by keywords
     * extracted from the current user message via $_REQUEST.
     *
     * @param string $context    Current context (empty).
     * @param string $project_id The active webchat project UUID.
     * @return string
     */
    public function provide_skeleton_context( $context, $project_id ) {
        // For NOTEBOOK platform, research context is already injected by
        // BCN_Chat_Engine::inject_source_context (pri 15). Skip to avoid duplication.
        $platform = sanitize_text_field( $_REQUEST['platform_type'] ?? '' );
        if ( $platform === 'NOTEBOOK' ) {
            return $context;
        }

        if ( empty( $project_id ) || ! class_exists( 'BCN_Research_Memory' ) ) {
            return $context;
        }

        // For non-NOTEBOOK (e.g. /chat/ via Context Builder), provide research notes.
        $message = sanitize_text_field( $_REQUEST['message'] ?? '' );
        if ( ! $message ) return $context;

        return BCN_Research_Memory::instance()->build_research_context( $project_id, $message, 2000 );
    }

    /**
     * Invalidate the skeleton cache when sources or notes change.
     * Accepts source_id or note_id as $first argument — used to look up project_id.
     *
     * @param int    $item_id   Source or note DB row ID.
     * @param string $project_id Optional project_id if already known.
     */
    public function invalidate_skeleton_on_change( $item_id, $project_id = '' ) {
        if ( empty( $project_id ) ) return;
        BCN_Studio_Input_Builder::invalidate( $project_id );
    }

    /**
     * Queue a skeleton rebuild to run on PHP shutdown — after the HTTP response
     * has already been sent to the browser.
     *
     * Triggered by bcn_note_created / bcn_note_updated. Uses a static map so
     * multiple note saves in the same request only queue one rebuild per project.
     *
     * @param int    $item_id    Note DB row ID.
     * @param string $project_id Project UUID.
     */
    public function queue_skeleton_rebuild_on_shutdown( $item_id, $project_id = '' ) {
        if ( empty( $project_id ) || ! class_exists( 'BCN_Studio_Input_Builder' ) ) return;

        static $queued = [];
        if ( isset( $queued[ $project_id ] ) ) return; // Already queued this request.
        $queued[ $project_id ] = true;

        error_log( '[BCN Skeleton] Rebuild queued on shutdown for project: ' . $project_id
            . ' (note_id=' . $item_id . ')' );

        add_action( 'shutdown', function () use ( $project_id ) {
            if ( ! class_exists( 'BCN_Studio_Input_Builder' ) ) return;

            error_log( '[BCN Skeleton] Shutdown rebuild started for project: ' . $project_id );

            $skeleton = BCN_Studio_Input_Builder::build( $project_id, [ 'force' => true ] );

            error_log( sprintf(
                '[BCN Skeleton] Shutdown rebuild complete. project=%s | has_skeleton=%s | has_raw_text=%s | notes=%d | sources=%d',
                $project_id,
                ! empty( $skeleton['skeleton'] ) ? 'YES (' . count( $skeleton['skeleton'] ) . ' nodes)' : 'NO',
                ! empty( $skeleton['_raw_text'] ) ? 'YES (' . strlen( $skeleton['_raw_text'] ) . ' chars)' : 'NO',
                $skeleton['meta']['note_count'] ?? 0,
                $skeleton['meta']['source_count'] ?? 0
            ) );
        } );
    }

    /**
     * Admin-ajax handler: bcn_run_research_job
     * Called by the non-blocking loopback fired from BCN_Deep_Research::schedule_async().
     * Nonce validation replaces login check (the request comes from the server itself).
     */
    public function ajax_run_research_job() {
        $job_id      = absint( $_POST['job_id']      ?? 0 );
        $max_results = absint( $_POST['max_results'] ?? 5 );
        $language    = sanitize_text_field( $_POST['language'] ?? 'vi' );
        $nonce       = sanitize_text_field( $_POST['nonce']    ?? '' );

        if ( ! $job_id || ! wp_verify_nonce( $nonce, 'bcn_research_async_' . $job_id ) ) {
            wp_die( 'forbidden', '', [ 'response' => 403 ] );
        }

        ignore_user_abort( true );
        set_time_limit( 120 );

        ( new BCN_Deep_Research() )->process_job( $job_id, $max_results, $language );

        wp_die( 'ok' );
    }

    public function activate() {
        BCN_Schema_Extend::extend_tables();
        ( new BCN_Cron() )->schedule();
        flush_rewrite_rules();
    }

    public function deactivate() {
        ( new BCN_Cron() )->unschedule();
    }

    /**
     * Allow notebook-specific file types to be uploaded.
     */
    public function add_notebook_mimes( $mimes ) {
        $mimes['md']   = 'text/markdown';
        $mimes['csv']  = 'text/csv';
        $mimes['txt']  = 'text/plain';
        $mimes['docx'] = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
        $mimes['json'] = 'application/json';
        $mimes['sql']  = 'text/x-sql';
        return $mimes;
    }

    /**
     * WordPress sometimes rejects .md/.sql/.json even with upload_mimes filter
     * because finfo_file returns a different MIME. This forces correct types.
     */
    public function fix_notebook_filetype( $data, $file, $filename, $mimes, $real_mime = '' ) {
        if ( ! empty( $data['ext'] ) && ! empty( $data['type'] ) ) {
            return $data;
        }

        $ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
        $notebook_mimes = [
            'md'   => 'text/markdown',
            'json' => 'application/json',
            'sql'  => 'text/x-sql',
            'csv'  => 'text/csv',
        ];

        if ( isset( $notebook_mimes[ $ext ] ) ) {
            $data['ext']             = $ext;
            $data['type']            = $notebook_mimes[ $ext ];
            $data['proper_filename'] = $filename;
        }

        return $data;
    }
}
