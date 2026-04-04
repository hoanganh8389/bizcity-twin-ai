<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Intent
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * BizCity Tool Trace — Standardized Execution Progress API
 *
 * Lightweight helper for tracking multi-step execution INSIDE a tool callback.
 * Sends progress to THREE channels simultaneously:
 *   1. SSE → React frontend (WorkingIndicator shows real-time progress)
 *   2. error_log → PHP log files (debugging)
 *   3. Pipeline log → DB + SSE log event (structured audit trail)
 *
 * Usage inside tool callback:
 *   $trace = BizCity_Job_Trace::start( $session_id, 'write_article', [
 *       'T1' => 'Viết nội dung bài',
 *       'T2' => 'Tạo ảnh bìa',
 *       'T3' => 'Đăng bài lên WordPress',
 *   ] );
 *   $trace->step( 'T1', 'running' );
 *   // ... do work ...
 *   $trace->step( 'T1', 'done', [ 'title' => $title ] );
 *   $trace->step( 'T2', 'running' );
 *   // ... do work ...
 *   $trace->complete( $result );
 *
 * Quick one-liner (global helper — no trace instance needed):
 *   bizcity_tool_trace( 'Đang tải ảnh lên...', [ 'url' => $url ] );
 *
 * Each step() call fires:
 *   - `bizcity_intent_status` → SSE status text (typing indicator)
 *   - `bizcity_intent_pipeline_log` → SSE log event (WorkingIndicator steps)
 *   - error_log() → PHP log for debugging
 *
 * The trace is stored in wp_options (autoload=no) so admin ajax can poll
 * status at any time — useful for long-running jobs like video.
 *
 * @package BizCity_Intent
 * @since   2.0.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Job_Trace {

    /** @var string */
    private $trace_id;

    /** @var string */
    private $session_id;

    /** @var string */
    private $tool_name;

    /** @var array  Step definitions: [ 'T1' => [ 'title' => '...', 'status' => 'pending', 'data' => [] ] ] */
    private $steps = [];

    /** @var string  Overall status: running | done | failed */
    private $status = 'running';

    /** @var float */
    private $started_at;

    /** @var float  Timestamp of the last step transition (for per-step elapsed). */
    private $last_step_at;

    /** @var self|null  Currently active trace instance (singleton for global helper). */
    private static $current = null;

    /* ══════════════════════════════════════════════════════════
     *  Factory
     * ══════════════════════════════════════════════════════════ */

    /**
     * Create and start a new job trace.
     *
     * @param string $session_id  Chat session ID.
     * @param string $tool_name   Tool being executed (e.g. 'write_article').
     * @param array  $step_map    Ordered steps: [ 'T1' => 'Step title', 'T2' => '...', ... ]
     * @return self
     */
    public static function start( string $session_id, string $tool_name, array $step_map ): self {
        $trace = new self();
        $trace->trace_id     = 'jt_' . $tool_name . '_' . substr( md5( $session_id . microtime() ), 0, 10 );
        $trace->session_id   = $session_id;
        $trace->tool_name    = $tool_name;
        $trace->started_at   = microtime( true );
        $trace->last_step_at = $trace->started_at;
        $trace->status       = 'running';

        foreach ( $step_map as $step_id => $title ) {
            $trace->steps[ $step_id ] = [
                'title'  => $title,
                'status' => 'pending',   // pending | running | done | failed | skipped
                'data'   => [],
                'error'  => '',
            ];
        }

        $trace->save();

        // Register as current trace for global helper
        self::$current = $trace;

        // ── Channel 1: error_log ──
        error_log( "[Tool Trace] Started: {$trace->trace_id} ({$tool_name}) — " . count( $step_map ) . ' steps' );

        // ── Channel 2: SSE pipeline log (structured) ──
        $total = count( $step_map );
        do_action( 'bizcity_intent_pipeline_log', 'tool_trace', [
            'trace_id'  => $trace->trace_id,
            'tool_name' => $tool_name,
            'action'    => 'start',
            'total'     => $total,
            'step_num'  => 0,
            'label'     => "Bắt đầu: {$tool_name} ({$total} bước)",
        ], 'info', round( ( microtime( true ) - $trace->started_at ) * 1000, 2 ) );

        return $trace;
    }

    /**
     * Load an existing trace by ID.
     *
     * @param string $trace_id
     * @return self|null
     */
    public static function load( string $trace_id ): ?self {
        $data = get_option( 'bizcity_jtrace_' . $trace_id );
        if ( ! $data || ! is_array( $data ) ) {
            return null;
        }

        $trace = new self();
        $trace->trace_id     = $trace_id;
        $trace->session_id   = $data['session_id'] ?? '';
        $trace->tool_name    = $data['tool_name']  ?? '';
        $trace->steps        = $data['steps']       ?? [];
        $trace->status       = $data['status']      ?? 'running';
        $trace->started_at   = $data['started_at']  ?? 0;
        $trace->last_step_at = $trace->started_at;

        return $trace;
    }

    /**
     * Get the currently active trace instance (set by ::start()).
     *
     * @return self|null
     */
    public static function current(): ?self {
        return self::$current;
    }

    /* ══════════════════════════════════════════════════════════
     *  Step tracking
     * ══════════════════════════════════════════════════════════ */

    /**
     * Update a step's status and optionally store output data.
     *
     * Fires progress to ALL three channels:
     *   1. SSE status text (typing indicator)
     *   2. SSE pipeline log (structured step for WorkingIndicator)
     *   3. error_log (debugging)
     *
     * @param string $step_id  Step key (T1, T2, ...).
     * @param string $status   pending | running | done | failed | skipped
     * @param array  $data     Output data from this step (merged into existing).
     * @param string $error    Error message (when status=failed).
     * @return self
     */
    public function step( string $step_id, string $status, array $data = [], string $error = '' ): self {
        if ( ! isset( $this->steps[ $step_id ] ) ) {
            return $this;
        }

        $this->steps[ $step_id ]['status'] = $status;
        if ( $data ) {
            $this->steps[ $step_id ]['data'] = array_merge( $this->steps[ $step_id ]['data'], $data );
        }
        if ( $error ) {
            $this->steps[ $step_id ]['error'] = $error;
        }

        // ── Compute metrics ──
        $step_title = $this->steps[ $step_id ]['title'];
        $step_keys  = array_keys( $this->steps );
        $step_num   = array_search( $step_id, $step_keys ) + 1;
        $total      = count( $this->steps );
        $now        = microtime( true );
        $elapsed_ms = round( ( $now - $this->started_at ) * 1000, 2 );
        $step_ms    = round( ( $now - $this->last_step_at ) * 1000, 2 );
        $this->last_step_at = $now;

        $icon = $this->get_step_icon( $step_id );

        if ( $status === 'running' ) {
            // ── Channel 1: error_log ──
            error_log( "[Tool Trace] {$this->trace_id} [{$step_num}/{$total}] running: {$step_title}" );

            // ── Channel 2: SSE status text (typing indicator) ──
            do_action( 'bizcity_intent_status', "{$icon} [{$step_num}/{$total}] {$step_title}..." );

            // ── Channel 3: SSE pipeline log (structured — WorkingIndicator) ──
            do_action( 'bizcity_intent_pipeline_log', 'tool_trace', [
                'trace_id'  => $this->trace_id,
                'tool_name' => $this->tool_name,
                'action'    => 'running',
                'step_id'   => $step_id,
                'step_num'  => $step_num,
                'total'     => $total,
                'label'     => "{$icon} [{$step_num}/{$total}] {$step_title}",
            ], 'info', $elapsed_ms );

        } elseif ( $status === 'done' ) {
            // ── Channel 1: error_log ──
            error_log( "[Tool Trace] {$this->trace_id} [{$step_num}/{$total}] done: {$step_title} ({$step_ms}ms)" );

            // ── Channel 3: SSE pipeline log (structured) ──
            do_action( 'bizcity_intent_pipeline_log', 'tool_trace', [
                'trace_id'  => $this->trace_id,
                'tool_name' => $this->tool_name,
                'action'    => 'done',
                'step_id'   => $step_id,
                'step_num'  => $step_num,
                'total'     => $total,
                'label'     => "✓ [{$step_num}/{$total}] {$step_title}",
                'step_ms'   => $step_ms,
                'output'    => ! empty( $data ) ? array_keys( $data ) : [],
            ], 'info', $elapsed_ms );

        } elseif ( $status === 'failed' ) {
            // ── Channel 1: error_log ──
            error_log( "[Tool Trace] {$this->trace_id} [{$step_num}/{$total}] FAILED: {$step_title} — {$error}" );

            // ── Channel 2: SSE status text ──
            do_action( 'bizcity_intent_status', "❌ [{$step_num}/{$total}] {$step_title}: {$error}" );

            // ── Channel 3: SSE pipeline log (structured) ──
            do_action( 'bizcity_intent_pipeline_log', 'tool_trace', [
                'trace_id'  => $this->trace_id,
                'tool_name' => $this->tool_name,
                'action'    => 'failed',
                'step_id'   => $step_id,
                'step_num'  => $step_num,
                'total'     => $total,
                'label'     => "❌ [{$step_num}/{$total}] {$step_title}",
                'error'     => $error,
            ], 'error', $elapsed_ms );

        } elseif ( $status === 'skipped' ) {
            error_log( "[Tool Trace] {$this->trace_id} [{$step_num}/{$total}] skipped: {$step_title}" );
        }

        $this->save();
        return $this;
    }

    /**
     * Quick log — fire a one-off trace message without a predefined step.
     * Useful for ad-hoc progress updates within a step.
     *
     * @param string $message  Human-readable progress text.
     * @param array  $data     Optional data payload.
     * @param string $level    info | warn | error
     * @return self
     */
    public function log( string $message, array $data = [], string $level = 'info' ): self {
        $elapsed_ms = round( ( microtime( true ) - $this->started_at ) * 1000, 2 );

        // ── Channel 1: error_log ──
        error_log( "[Tool Trace] {$this->trace_id} [{$level}] {$message}" );

        // ── Channel 2: SSE status text ──
        if ( $level !== 'error' ) {
            do_action( 'bizcity_intent_status', $message );
        }

        // ── Channel 3: SSE pipeline log ──
        do_action( 'bizcity_intent_pipeline_log', 'tool_trace', [
            'trace_id'  => $this->trace_id,
            'tool_name' => $this->tool_name,
            'action'    => 'log',
            'label'     => $message,
            'log_data'  => $data,
        ], $level, $elapsed_ms );

        return $this;
    }

    /**
     * Get output data from a completed step (for passing to next step).
     *
     * @param string $step_id
     * @return array
     */
    public function get_step_data( string $step_id ): array {
        return $this->steps[ $step_id ]['data'] ?? [];
    }

    /**
     * Mark the entire trace as completed.
     *
     * @param array $final_data  Final result data (post_url, etc.)
     * @return self
     */
    public function complete( array $final_data = [] ): self {
        $this->status = 'done';

        if ( $final_data ) {
            // Store final result in the last step's data
            $last_key = array_key_last( $this->steps );
            if ( $last_key ) {
                $this->steps[ $last_key ]['data'] = array_merge(
                    $this->steps[ $last_key ]['data'],
                    $final_data
                );
            }
        }

        $duration = round( ( microtime( true ) - $this->started_at ) * 1000 );

        // ── Channel 1: error_log ──
        error_log( "[Tool Trace] {$this->trace_id} COMPLETED in {$duration}ms" );

        // ── Channel 3: SSE pipeline log (structured) ──
        do_action( 'bizcity_intent_pipeline_log', 'tool_trace', [
            'trace_id'  => $this->trace_id,
            'tool_name' => $this->tool_name,
            'action'    => 'complete',
            'total_ms'  => $duration,
            'label'     => "✅ {$this->tool_name} hoàn thành ({$duration}ms)",
        ], 'info', (float) $duration );

        $this->save();

        // Clear current trace singleton
        if ( self::$current === $this ) {
            self::$current = null;
        }

        // Schedule cleanup after 1 hour
        wp_schedule_single_event( time() + 3600, 'bizcity_job_trace_cleanup', [ $this->trace_id ] );

        return $this;
    }

    /**
     * Mark the entire trace as failed.
     *
     * @param string $error  Error message.
     * @return self
     */
    public function fail( string $error ): self {
        $this->status = 'failed';

        // ── Channel 1: error_log ──
        error_log( "[Tool Trace] {$this->trace_id} FAILED: {$error}" );

        // ── Channel 3: SSE pipeline log ──
        $duration = round( ( microtime( true ) - $this->started_at ) * 1000 );
        do_action( 'bizcity_intent_pipeline_log', 'tool_trace', [
            'trace_id'  => $this->trace_id,
            'tool_name' => $this->tool_name,
            'action'    => 'failed',
            'total_ms'  => $duration,
            'label'     => "❌ {$this->tool_name} thất bại: {$error}",
            'error'     => $error,
        ], 'error', (float) $duration );

        $this->save();

        // Clear current trace singleton
        if ( self::$current === $this ) {
            self::$current = null;
        }

        return $this;
    }

    /* ══════════════════════════════════════════════════════════
     *  Getters
     * ══════════════════════════════════════════════════════════ */

    public function get_trace_id(): string    { return $this->trace_id; }
    public function get_session_id(): string  { return $this->session_id; }
    public function get_tool_name(): string   { return $this->tool_name; }
    public function get_status(): string      { return $this->status; }
    public function get_steps(): array        { return $this->steps; }
    public function get_started_at(): float   { return $this->started_at; }

    /**
     * Get a summary array for AJAX polling response.
     *
     * @return array
     */
    public function to_array(): array {
        return [
            'trace_id'   => $this->trace_id,
            'session_id' => $this->session_id,
            'tool_name'  => $this->tool_name,
            'status'     => $this->status,
            'steps'      => $this->steps,
            'started_at' => $this->started_at,
            'elapsed_ms' => round( ( microtime( true ) - $this->started_at ) * 1000 ),
        ];
    }

    /* ══════════════════════════════════════════════════════════
     *  Persistence (wp_options, lightweight)
     * ══════════════════════════════════════════════════════════ */

    private function save(): void {
        update_option( 'bizcity_jtrace_' . $this->trace_id, [
            'session_id' => $this->session_id,
            'tool_name'  => $this->tool_name,
            'status'     => $this->status,
            'steps'      => $this->steps,
            'started_at' => $this->started_at,
        ], false ); // autoload = false
    }

    /**
     * Delete trace from DB (cleanup).
     */
    public function delete(): void {
        delete_option( 'bizcity_jtrace_' . $this->trace_id );
    }

    /**
     * Get an emoji icon for each step based on step_id patterns.
     */
    private function get_step_icon( string $step_id ): string {
        $title = strtolower( $this->steps[ $step_id ]['title'] ?? '' );

        if ( strpos( $title, 'viết' ) !== false || strpos( $title, 'nội dung' ) !== false ) return '✍️';
        if ( strpos( $title, 'ảnh' ) !== false || strpos( $title, 'hình' ) !== false )       return '🎨';
        if ( strpos( $title, 'đăng' ) !== false || strpos( $title, 'publish' ) !== false )   return '📝';
        if ( strpos( $title, 'video' ) !== false )                                           return '🎬';
        if ( strpos( $title, 'dịch' ) !== false || strpos( $title, 'translate' ) !== false ) return '🌐';
        if ( strpos( $title, 'tìm' ) !== false || strpos( $title, 'search' ) !== false )    return '🔍';
        if ( strpos( $title, 'gửi' ) !== false || strpos( $title, 'send' ) !== false )      return '📤';
        if ( strpos( $title, 'tải' ) !== false || strpos( $title, 'upload' ) !== false )     return '📥';
        if ( strpos( $title, 'phân tích' ) !== false || strpos( $title, 'analyz' ) !== false ) return '📊';

        return '⚙️';
    }
}

/* ══════════════════════════════════════════════════════════════
 *  Global helper functions — zero-boilerplate tracing for plugins
 * ══════════════════════════════════════════════════════════════ */

/**
 * Fire a one-off trace event to all 3 channels (SSE status, SSE log, error_log).
 *
 * Use this inside tool callbacks for quick progress updates without
 * setting up a full trace with step_map.
 *
 * Example:
 *   bizcity_tool_trace( '🔍 Đang tìm kiếm sản phẩm...' );
 *   bizcity_tool_trace( '📝 Đã tạo bài viết', [ 'post_id' => 123 ] );
 *   bizcity_tool_trace( 'Lỗi kết nối API', [], 'error' );
 *
 * If a BizCity_Job_Trace instance is active (::current()), delegates to its log().
 * Otherwise fires events directly.
 *
 * @param string $message  Human-readable progress text.
 * @param array  $data     Optional data payload.
 * @param string $level    info | warn | error
 */
function bizcity_tool_trace( string $message, array $data = [], string $level = 'info' ): void {
    // If there is an active trace instance, delegate to it
    $trace = BizCity_Job_Trace::current();
    if ( $trace ) {
        $trace->log( $message, $data, $level );
        return;
    }

    // Standalone mode — fire events directly
    // ── Channel 1: error_log ──
    error_log( "[Tool Trace] [{$level}] {$message}" );

    // ── Channel 2: SSE status text ──
    if ( $level !== 'error' ) {
        do_action( 'bizcity_intent_status', $message );
    }

    // ── Channel 3: SSE pipeline log ──
    do_action( 'bizcity_intent_pipeline_log', 'tool_trace', [
        'action'   => 'log',
        'label'    => $message,
        'log_data' => $data,
    ], $level, 0.0 );
}

/* ── Cleanup hook ── */
add_action( 'bizcity_job_trace_cleanup', function ( $trace_id ) {
    delete_option( 'bizcity_jtrace_' . $trace_id );
} );
