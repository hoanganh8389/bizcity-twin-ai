<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Twin_Core
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * BizCity Twin Trace — Structured pipeline trace for Twin Core.
 *
 * Plugs into the existing bizcity_intent_pipeline_log action so that
 * traces flow through SSE → browser console + WorkingIndicator
 * for both /chat/ and /note/ pipelines.
 *
 * Usage:
 *   BizCity_Twin_Trace::log( 'focus_resolve', [ 'mode' => 'emotion' ] );
 *   BizCity_Twin_Trace::gate( 'transit', false, 'Mode: knowledge, topic: no match' );
 *   BizCity_Twin_Trace::layer( 'companion', true, 12.3 );
 *
 * @package  BizCity_Twin_Core
 * @version  0.1.0
 * @since    2026-03-22
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Twin_Trace {

    /** @var float Request-start time for elapsed calculation */
    private static $start_time = 0;

    /** @var array Accumulated trace entries for the current request */
    private static $entries = [];

    /** @var bool Whether trace is enabled */
    private static $enabled = true;

    /** @var array<string,array<string,mixed>> Bounded runtime span stack. */
    private static $runtime_spans = [];

    /** @var int Maximum semantic runtime events retained in one request. */
    private static $runtime_event_limit = 200;

    /**
     * Initialize trace timer. Called once at bootstrap.
     */
    public static function init(): void {
        self::$start_time = microtime( true );
        self::$enabled    = defined( 'BIZCITY_TWIN_TRACE_ENABLED' )
            ? BIZCITY_TWIN_TRACE_ENABLED
            : true; // enabled by default when Twin Core is loaded
    }

    /**
     * Log a generic Twin Core pipeline step.
     *
     * @param string $step  Step identifier (prefixed 'twin:' automatically)
     * @param array  $data  Structured payload
     * @param string $level 'info' | 'warn' | 'error'
     */
    public static function log( string $step, array $data = [], string $level = 'info' ): void {
        if ( ! self::$enabled ) {
            return;
        }

        $elapsed = self::elapsed();
        $entry   = [
            'step'  => 'twin:' . $step,
            'data'  => $data,
            'level' => $level,
            'ms'    => $elapsed,
        ];

        self::$entries[] = $entry;

        // Fire into the existing pipeline_log action → SSE → browser
        do_action( 'bizcity_intent_pipeline_log', $entry['step'], $data, $level, $elapsed );
    }

    /**
     * Log a Focus Gate decision (inject / skip).
     *
     * @param string $layer   Layer key (transit, astro, companion, session, etc.)
     * @param bool   $allowed Whether the layer was allowed
     * @param string $reason  Human-readable reason
     */
    public static function gate( string $layer, bool $allowed, string $reason = '' ): void {
        self::log( 'gate', [
            'layer'   => $layer,
            'allowed' => $allowed,
            'reason'  => $reason,
        ] );
    }

    /**
     * Log context layer injection with timing.
     *
     * @param string $layer    Layer name
     * @param bool   $injected Whether content was actually injected
     * @param float  $ms       Time spent building this layer
     * @param int    $tokens   Estimated token count (0 if skipped)
     */
    public static function layer( string $layer, bool $injected, float $ms = 0, int $tokens = 0 ): void {
        self::log( 'layer', [
            'layer'    => $layer,
            'injected' => $injected,
            'build_ms' => round( $ms, 2 ),
            'tokens'   => $tokens,
        ] );
    }

    /**
     * Log focus profile resolve result.
     *
     * @param string $mode          Classified mode
     * @param string $source        Where mode came from: 'args' | 'classifier' | 'fallback'
     * @param array  $focus_profile The resolved profile
     */
    public static function profile_resolved( string $mode, string $source, array $focus_profile ): void {
        // Build a compact summary: only non-default values
        $summary = [];
        foreach ( $focus_profile as $k => $v ) {
            if ( $k[0] === '_' ) {
                continue; // skip internal keys like _message, _mode
            }
            if ( $v === false ) {
                $summary[ $k ] = '❌';
            } elseif ( $v === true ) {
                $summary[ $k ] = '✅';
            } elseif ( is_string( $v ) ) {
                $summary[ $k ] = $v;
            } elseif ( is_int( $v ) ) {
                $summary[ $k ] = $v;
            }
        }

        self::log( 'focus_resolve', [
            'mode'    => $mode,
            'source'  => $source,
            'profile' => $summary,
        ] );
    }

    /**
     * Log memory mode decision.
     *
     * @param string $mode     Memory mode: all/relevant/explicit
     * @param int    $loaded   How many memories loaded from DB
     * @param int    $filtered How many memories after filtering
     */
    public static function memory( string $mode, int $loaded, int $filtered ): void {
        self::log( 'memory_filter', [
            'mode'     => $mode,
            'loaded'   => $loaded,
            'filtered' => $filtered,
        ] );
    }

    /**
     * Log the final prompt assembly summary.
     *
     * @param array $sections Map of section name → token estimate
     * @param int   $total_tokens Total estimated tokens
     * @param float $total_ms     Total build time
     */
    public static function prompt_summary( array $sections, int $total_tokens, float $total_ms ): void {
        self::log( 'prompt_summary', [
            'sections'     => $sections,
            'total_tokens' => $total_tokens,
            'total_ms'     => round( $total_ms, 2 ),
        ] );
    }

    /* ── Sprint 3 — Distribution trace ─────────────────────────────── */

    /**
     * Log a distribution step (per-tool result).
     *
     * @param string $tool    Distribution tool key (e.g. 'publish_wp_post').
     * @param string $status  'ok' | 'error'
     * @param array  $details Extra data: url, post_id, error, ms…
     */
    public static function distribution( $tool, $status, array $details = array() ) {
        self::log( 'twin:distribution', array_merge( array(
            'tool'   => $tool,
            'status' => $status,
        ), $details ) );
    }

    /**
     * Log multi-channel distribution summary.
     *
     * @param int   $output_id   Studio output ID.
     * @param array $results     Array of per-tool results.
     * @param float $total_ms    Total elapsed milliseconds.
     */
    public static function distribution_summary( $output_id, array $results, $total_ms = 0 ) {
        $ok_count  = 0;
        $err_count = 0;
        foreach ( $results as $r ) {
            if ( ! empty( $r['success'] ) ) {
                $ok_count++;
            } else {
                $err_count++;
            }
        }
        self::log( 'twin:distribution_summary', array(
            'output_id' => $output_id,
            'total'     => count( $results ),
            'ok'        => $ok_count,
            'errors'    => $err_count,
            'total_ms'  => round( $total_ms, 2 ),
        ) );
    }

    /**
     * Get all trace entries for the current request.
     *
     * @return array
     */
    public static function get_entries(): array {
        return self::$entries;
    }

    /**
     * Get elapsed ms since trace init.
     *
     * @return float
     */
    private static function elapsed(): float {
        if ( self::$start_time <= 0 ) {
            self::$start_time = microtime( true );
        }
        return round( ( microtime( true ) - self::$start_time ) * 1000, 2 );
    }

    /**
     * Reset trace (between requests or for testing).
     */
    public static function reset(): void {
        self::$entries    = [];
        self::$start_time = microtime( true );
        self::$runtime_spans = [];
    }

    /**
     * Start a bounded semantic runtime span.
     *
     * This is in-memory only. It does not write the event stream, database,
     * cache or provider payloads, and is intended for W4 observe-only tracing.
     *
     * @param string $feature Feature boundary, e.g. twincore or memory.
     * @param string $operation Operation name.
     * @param array  $context Safe scalar metadata only.
     * @return string Span event ID, or an empty string when disabled.
     */
    public static function runtime_enter( string $feature, string $operation, array $context = [] ): string {
        if ( ! self::$enabled || count( self::$entries ) >= self::$runtime_event_limit ) {
            return '';
        }
        // [2026-08-10 Johnny Chu] PHASE-1.23-CANONICAL-W4 - bounded parent-child
        // runtime trace; no raw prompt, user-meta, SQL or provider payloads.
        $event_id = function_exists( 'wp_generate_uuid4' )
            ? 'rt_' . wp_generate_uuid4()
            : 'rt_' . uniqid( '', true );
        $parent_id = '';
        if ( ! empty( self::$runtime_spans ) ) {
            $parent = end( self::$runtime_spans );
            $parent_id = isset( $parent['event_id'] ) ? (string) $parent['event_id'] : '';
        }
        self::$runtime_spans[ $event_id ] = [
            'event_id'   => $event_id,
            'parent_id'  => $parent_id,
            'feature'    => self::safe_label( $feature ),
            'operation'  => self::safe_label( $operation ),
            'started_at' => microtime( true ),
        ];
        self::runtime_log( 'enter', self::$runtime_spans[ $event_id ], self::safe_context( $context ) );
        return $event_id;
    }

    /**
     * Finish a semantic runtime span.
     *
     * @param string $event_id Span ID from runtime_enter().
     * @param string $result pass|fail|degraded.
     * @param array  $data Safe scalar metadata only.
     * @return void
     */
    public static function runtime_exit( string $event_id, string $result = 'pass', array $data = [] ): void {
        if ( $event_id === '' || ! isset( self::$runtime_spans[ $event_id ] ) ) {
            return;
        }
        $span = self::$runtime_spans[ $event_id ];
        unset( self::$runtime_spans[ $event_id ] );
        $result = in_array( $result, [ 'pass', 'fail', 'degraded' ], true ) ? $result : 'fail';
        $data['elapsed_ms'] = round( ( microtime( true ) - (float) $span['started_at'] ) * 1000, 2 );
        $data['result'] = $result;
        self::runtime_log( 'exit', $span, self::safe_context( $data ) );
    }

    /**
     * Return only semantic runtime entries for Diagnostics/probes.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function runtime_entries(): array {
        return array_values( array_filter( self::$entries, static function ( $entry ) {
            return isset( $entry['step'] ) && $entry['step'] === 'twin:runtime';
        } ) );
    }

    /**
     * Return bounded metadata for spans that have not exited yet.
     *
     * @return array<int,array<string,string>>
     */
    public static function runtime_open_spans(): array {
        $open = [];
        foreach ( self::$runtime_spans as $span ) {
            $open[] = [
                'event_id'        => (string) ( $span['event_id'] ?? '' ),
                'parent_event_id' => (string) ( $span['parent_id'] ?? '' ),
                'feature'         => (string) ( $span['feature'] ?? '' ),
                'operation'       => (string) ( $span['operation'] ?? '' ),
            ];
        }
        return $open;
    }

    private static function runtime_log( string $phase, array $span, array $data = [] ): void {
        if ( count( self::$entries ) >= self::$runtime_event_limit ) {
            return;
        }
        self::$entries[] = [
            'step'  => 'twin:runtime',
            'data'  => array_merge(
                [
                    'phase'            => $phase,
                    'event_id'         => (string) ( $span['event_id'] ?? '' ),
                    'parent_event_id'  => (string) ( $span['parent_id'] ?? '' ),
                    'feature'          => (string) ( $span['feature'] ?? '' ),
                    'operation'        => (string) ( $span['operation'] ?? '' ),
                ],
                $data
            ),
            'level' => ( isset( $data['result'] ) && $data['result'] === 'fail' ) ? 'error' : 'info',
            'ms'    => self::elapsed(),
        ];
    }

    private static function safe_label( string $value ): string {
        return substr( preg_replace( '/[^A-Za-z0-9_.:-]/', '', $value ), 0, 120 );
    }

    private static function safe_context( array $data ): array {
        $safe = [];
        foreach ( $data as $key => $value ) {
            if ( is_scalar( $value ) || $value === null ) {
                $safe[ self::safe_label( (string) $key ) ] = is_string( $value )
                    ? substr( $value, 0, 120 )
                    : $value;
            } elseif ( is_array( $value ) ) {
                $safe[ self::safe_label( (string) $key ) . '_count' ] = count( $value );
            }
        }
        return $safe;
    }

    /* ================================================================
     * Phase 1.9 — Resource Resolve trace
     * ================================================================ */

    /**
     * Log a resource resolve step (Phase 1.9 unified resource bundle).
     *
     * @param array $data {
     *   @type string $profile       Resource profile used.
     *   @type array  $skill         { found, method, title, ms }
     *   @type array  $session_spec  { found, topic, facts, ms }
     *   @type array  $notes         { count, matched, ms }
     *   @type array  $sources       { count, method, titles, ms }
     *   @type array  $knowledge     { count, skipped, ms }
     *   @type float  $resolve_ms    Total resolve time.
     * }
     */
    public static function resource_resolve( array $data ): void {
        self::log( 'resource_resolve', $data );
    }

    /* ================================================================
     * Phase 2 Priority 5 — Additional trace methods for event taxonomy
     * ================================================================ */

    /**
     * Log a focus decision (context selected as primary focus).
     *
     * @param string $label  Focus item label
     * @param float  $score  Focus score
     * @param string $reason Why this was selected
     */
    public static function focus( string $label, float $score, string $reason = '' ): void {
        self::log( 'focus_decision', [
            'label'  => $label,
            'score'  => $score,
            'reason' => $reason,
        ] );
    }

    /**
     * Log a suppress decision (context excluded from prompt).
     *
     * @param string $label  Suppressed item label
     * @param string $reason Why it was suppressed
     */
    public static function suppress( string $label, string $reason = '' ): void {
        self::log( 'suppress_decision', [
            'label'  => $label,
            'reason' => $reason,
        ] );
    }

    /**
     * Log a tool recommendation.
     *
     * @param string $tool_name Recommended tool name
     * @param float  $score     Tool fit score
     * @param string $reason    Why recommended
     */
    public static function tool_recommended( string $tool_name, float $score, string $reason = '' ): void {
        self::log( 'tool_recommended', [
            'tool_name' => $tool_name,
            'score'     => $score,
            'reason'    => $reason,
        ] );
    }

    /**
     * Log a tool execution result.
     *
     * @param string $tool_name    Tool that was executed
     * @param string $result_status success|failure|timeout
     * @param float  $latency_ms   Execution time
     */
    public static function tool_executed( string $tool_name, string $result_status, float $latency_ms ): void {
        self::log( 'tool_executed', [
            'tool_name'     => $tool_name,
            'result_status' => $result_status,
            'latency_ms'    => round( $latency_ms, 2 ),
        ] );
    }

    /**
     * Log a milestone event.
     *
     * @param string $milestone_type  Type of milestone
     * @param string $label           Milestone description
     * @param float  $score           Milestone score
     */
    public static function milestone( string $milestone_type, string $label, float $score = 0.0 ): void {
        self::log( 'milestone', [
            'type'  => $milestone_type,
            'label' => $label,
            'score' => $score,
        ] );
    }

    /**
     * Log a prompt parse result.
     *
     * @param int    $prompt_spec_id
     * @param float  $confidence
     * @param string $mode
     * @param int    $objective_count
     */
    public static function prompt_parsed( int $prompt_spec_id, float $confidence, string $mode, int $objective_count ): void {
        self::log( 'prompt_parsed', [
            'prompt_spec_id'  => $prompt_spec_id,
            'confidence'      => $confidence,
            'mode'            => $mode,
            'objective_count' => $objective_count,
        ] );
    }
}
