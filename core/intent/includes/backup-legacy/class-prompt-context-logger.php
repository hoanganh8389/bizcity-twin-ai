<?php
/**
 * BizCity Intent — Prompt & Context File Logger
 *
 * Logs the exact LLM prompt + context for each classification call
 * to individual files for easy debugging and analysis.
 *
 * File structure:
 *   logs/
 *     site-{blog_id}/
 *       user-{user_id}/
 *         {Y-m-d}_{H-i-s}_{trace_id}_{step}.md
 *
 * Each file contains:
 *   - User message (original)
 *   - Regex pre-match result
 *   - Focused schema sent to LLM
 *   - Full LLM prompt
 *   - LLM raw response
 *   - Final classification result (after post-LLM corrections)
 *   - Pipeline decision trace
 *
 * Enable via: define('BIZCITY_INTENT_LOG_PROMPTS', true)
 * Or via WP option: bizcity_intent_log_prompts = 1
 *
 * @package BizCity_Intent
 * @since   3.9.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Prompt_Context_Logger {

    /** @var self|null */
    private static $instance = null;

    /** @var string Base logs directory */
    private $logs_dir;

    /** @var bool Whether logging is enabled */
    private $enabled;

    /** @var string Current trace ID (set per process() call) */
    private $trace_id = '';

    /** @var int Current user ID */
    private $user_id = 0;

    /** @var int Current blog/site ID */
    private $blog_id = 1;

    /** @var string Timestamp for current request */
    private $timestamp = '';

    /** @var array Accumulated log entries for current trace */
    private $entries = [];

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        $this->logs_dir = BIZCITY_INTENT_DIR . '/logs';
        $this->enabled  = $this->is_enabled();
    }

    /**
     * Check if prompt logging is enabled.
     */
    private function is_enabled() {
        if ( defined( 'BIZCITY_INTENT_LOG_PROMPTS' ) ) {
            return (bool) BIZCITY_INTENT_LOG_PROMPTS;
        }
        return (bool) get_option( 'bizcity_intent_log_prompts', false );
    }

    /**
     * Begin a new trace — call at start of each process() cycle.
     *
     * @param int    $user_id
     * @param string $trace_id Optional trace ID (reuse from Intent Logger).
     */
    public function begin_trace( $user_id = 0, $trace_id = '' ) {
        if ( ! $this->enabled ) return;

        $this->user_id   = intval( $user_id ) ?: get_current_user_id();
        $this->blog_id   = get_current_blog_id();
        $this->trace_id  = $trace_id ?: wp_generate_uuid4();
        $this->timestamp = gmdate( 'Y-m-d_H-i-s' );
        $this->entries   = [];
    }

    /**
     * Check if a trace is currently active.
     *
     * @return bool
     */
    public function has_trace() {
        return ! empty( $this->trace_id );
    }

    /**
     * Log the mode classification prompt + context + result.
     *
     * @param string     $message          Original user message.
     * @param array|null $conversation     Active conversation data.
     * @param array|null $regex_match      Regex pre-match result from match_goal_by_regex().
     * @param string     $focused_schema   The focused tool schema sent to LLM.
     * @param string     $llm_prompt       Full LLM prompt text.
     * @param string     $llm_raw_response Raw LLM JSON response.
     * @param array|null $llm_parsed       Parsed LLM result (mode, confidence, goal...).
     * @param array      $final_result     Final classify() result (after post-corrections).
     * @param array      $meta             Extra metadata (model, tokens, overrides...).
     */
    public function log_classification(
        $message,
        $conversation = null,
        $regex_match  = null,
        $focused_schema = '',
        $llm_prompt     = '',
        $llm_raw_response = '',
        $llm_parsed     = null,
        $final_result   = [],
        $meta           = []
    ) {
        if ( ! $this->enabled ) return;

        $content = $this->build_classification_log(
            $message, $conversation, $regex_match,
            $focused_schema, $llm_prompt, $llm_raw_response,
            $llm_parsed, $final_result, $meta
        );

        $this->write_log_file( 'classify', $content );
    }

    /**
     * Log any pipeline step with custom data.
     *
     * @param string $step  Step name (e.g. 'router', 'planner', 'tool_execute').
     * @param array  $data  Arbitrary data to log.
     */
    public function log_step( $step, array $data ) {
        if ( ! $this->enabled ) return;

        $lines = [];
        $lines[] = '# Pipeline Step: ' . $step;
        $lines[] = '';
        $lines[] = '**Timestamp:** ' . gmdate( 'Y-m-d H:i:s' ) . ' UTC';
        $lines[] = '**Trace:** ' . $this->trace_id;
        $lines[] = '';

        foreach ( $data as $key => $value ) {
            $lines[] = '## ' . $key;
            $lines[] = '';
            if ( is_string( $value ) ) {
                $lines[] = $value;
            } else {
                $lines[] = '```json';
                $lines[] = json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
                $lines[] = '```';
            }
            $lines[] = '';
        }

        $this->write_log_file( $step, implode( "\n", $lines ) );
    }

    /* ════════════════════════════════════════════════════════
     *  Internal builders
     * ════════════════════════════════════════════════════════ */

    /**
     * Build the full classification log content as Markdown.
     */
    private function build_classification_log(
        $message, $conversation, $regex_match,
        $focused_schema, $llm_prompt, $llm_raw_response,
        $llm_parsed, $final_result, $meta
    ) {
        $lines = [];

        // Header
        $lines[] = '# Mode Classification Log';
        $lines[] = '';
        $lines[] = '| Field | Value |';
        $lines[] = '|-------|-------|';
        $lines[] = '| Timestamp | ' . gmdate( 'Y-m-d H:i:s' ) . ' UTC |';
        $lines[] = '| Trace ID | ' . $this->trace_id . ' |';
        $lines[] = '| Site ID | ' . $this->blog_id . ' |';
        $lines[] = '| User ID | ' . $this->user_id . ' |';
        $lines[] = '| Model | ' . ( $meta['llm_model'] ?? 'N/A' ) . ' |';
        $lines[] = '';

        // 1. User Message
        $lines[] = '## 1. User Message';
        $lines[] = '';
        $lines[] = '```';
        $lines[] = $message;
        $lines[] = '```';
        $lines[] = '';

        // 2. Conversation Context
        $lines[] = '## 2. Conversation Context';
        $lines[] = '';
        if ( $conversation && ! empty( $conversation['goal'] ) ) {
            $lines[] = '```json';
            $lines[] = json_encode( [
                'goal'          => $conversation['goal'] ?? '',
                'goal_label'    => $conversation['goal_label'] ?? '',
                'status'        => $conversation['status'] ?? '',
                'waiting_field' => $conversation['waiting_field'] ?? '',
                'waiting_for'   => $conversation['waiting_for'] ?? '',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
            $lines[] = '```';
        } else {
            $lines[] = '_No active conversation/goal._';
        }
        $lines[] = '';

        // 3. Regex Pre-Match
        $lines[] = '## 3. Regex Pre-Match Result';
        $lines[] = '';
        if ( $regex_match ) {
            $lines[] = '**⚠️ REGEX MATCHED** — this biases the entire pipeline:';
            $lines[] = '';
            $lines[] = '```json';
            $lines[] = json_encode( $regex_match, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
            $lines[] = '```';
            $lines[] = '';
            $lines[] = '> The regex match causes:';
            $lines[] = '> 1. ★ marker in focused schema (LLM bias)';
            $lines[] = '> 2. `⚡ REGEX PRE-MATCH` hint in LLM prompt';
            $lines[] = '> 3. v3.8.2 post-LLM MODE-level override (regex → execution)';
        } else {
            $lines[] = '_No regex match._';
        }
        $lines[] = '';

        // 4. Focused Schema
        $lines[] = '## 4. Focused Tool Schema (sent to LLM)';
        $lines[] = '';
        if ( $focused_schema ) {
            $lines[] = '```';
            $lines[] = $focused_schema;
            $lines[] = '```';
        } else {
            $lines[] = '_Empty schema._';
        }
        $lines[] = '';

        // 5. Full LLM Prompt
        $lines[] = '## 5. Full LLM Prompt';
        $lines[] = '';
        $lines[] = '<details>';
        $lines[] = '<summary>Click to expand full prompt (' . mb_strlen( $llm_prompt, 'UTF-8' ) . ' chars)</summary>';
        $lines[] = '';
        $lines[] = '```';
        $lines[] = $llm_prompt;
        $lines[] = '```';
        $lines[] = '';
        $lines[] = '</details>';
        $lines[] = '';

        // 6. LLM Raw Response
        $lines[] = '## 6. LLM Raw Response';
        $lines[] = '';
        $lines[] = '```json';
        $lines[] = $llm_raw_response ?: '(empty or failed)';
        $lines[] = '```';
        $lines[] = '';

        // 7. LLM Parsed Result
        $lines[] = '## 7. LLM Parsed Result';
        $lines[] = '';
        if ( $llm_parsed ) {
            $lines[] = '```json';
            $lines[] = json_encode( $llm_parsed, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
            $lines[] = '```';
        } else {
            $lines[] = '_LLM parsing failed or returned null._';
        }
        $lines[] = '';

        // 8. Final Result (after post-LLM corrections)
        $lines[] = '## 8. Final Classification Result';
        $lines[] = '';
        $lines[] = '```json';
        $lines[] = json_encode( $final_result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
        $lines[] = '```';
        $lines[] = '';

        // 9. Override Analysis
        $has_override = ! empty( $final_result['meta']['intent_result']['_regex_override'] );
        $mode_changed = isset( $meta['original_llm_mode'] ) && $meta['original_llm_mode'] !== ( $final_result['mode'] ?? '' );
        $goal_changed = $llm_parsed && isset( $llm_parsed['intent_result']['goal'] )
                        && isset( $final_result['meta']['intent_result']['goal'] )
                        && $llm_parsed['intent_result']['goal'] !== $final_result['meta']['intent_result']['goal'];

        if ( $has_override || $mode_changed || $goal_changed ) {
            $lines[] = '## 9. ⚠️ Override Analysis';
            $lines[] = '';
            if ( $mode_changed ) {
                $lines[] = '- **MODE OVERRIDE:** LLM said `' . ( $meta['original_llm_mode'] ?? '?' )
                         . '` → regex forced `' . ( $final_result['mode'] ?? '?' ) . '`';
            }
            if ( $goal_changed ) {
                $lines[] = '- **GOAL OVERRIDE:** LLM said `' . ( $llm_parsed['intent_result']['goal'] ?? '?' )
                         . '` → regex forced `' . ( $final_result['meta']['intent_result']['goal'] ?? '?' ) . '`';
            }
            if ( $has_override ) {
                $lines[] = '- **`_regex_override` = true** — classification was overridden by regex pattern match';
            }
            $lines[] = '';
        }

        return implode( "\n", $lines );
    }

    /**
     * Write a log file to the filesystem.
     *
     * @param string $step    Step name for filename.
     * @param string $content File content.
     */
    private function write_log_file( $step, $content ) {
        $dir = $this->get_log_dir();
        if ( ! $dir ) return;

        // Filename: {date}_{time}_{trace_short}_{step}.md
        $trace_short = substr( $this->trace_id, 0, 8 );
        $filename    = sprintf(
            '%s_%s_%s.md',
            $this->timestamp,
            $trace_short,
            sanitize_file_name( $step )
        );

        $filepath = $dir . '/' . $filename;

        // Write atomically
        $tmp = $filepath . '.tmp';
        if ( @file_put_contents( $tmp, $content ) !== false ) {
            @rename( $tmp, $filepath );
        }
    }

    /**
     * Get/create the log directory for current site + user.
     *
     * @return string|false Directory path, or false on failure.
     */
    private function get_log_dir() {
        $dir = sprintf(
            '%s/site-%d/user-%d',
            $this->logs_dir,
            $this->blog_id,
            $this->user_id
        );

        if ( ! is_dir( $dir ) ) {
            if ( ! wp_mkdir_p( $dir ) ) {
                return false;
            }
            // .htaccess to prevent web access
            $htaccess = $this->logs_dir . '/.htaccess';
            if ( ! file_exists( $htaccess ) ) {
                @file_put_contents( $htaccess, "Deny from all\n" );
            }
            // index.php for extra safety
            $index = $this->logs_dir . '/index.php';
            if ( ! file_exists( $index ) ) {
                @file_put_contents( $index, "<?php // Silence is golden.\n" );
            }
        }

        return $dir;
    }

    /**
     * Cleanup old log files (retention policy).
     * Call via WP-Cron or manually.
     *
     * @param int $max_age_days Maximum age in days (default: 7).
     */
    public function cleanup( $max_age_days = 7 ) {
        if ( ! is_dir( $this->logs_dir ) ) return;

        $cutoff = time() - ( $max_age_days * DAY_IN_SECONDS );

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $this->logs_dir, FilesystemIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ( $iterator as $file ) {
            if ( $file->isFile() && $file->getExtension() === 'md' ) {
                if ( $file->getMTime() < $cutoff ) {
                    @unlink( $file->getPathname() );
                }
            }
        }

        // Remove empty directories
        foreach ( $iterator as $file ) {
            if ( $file->isDir() ) {
                @rmdir( $file->getPathname() ); // only removes if empty
            }
        }
    }

    /**
     * Get log files for a specific user, optionally filtered by date.
     *
     * @param int         $user_id
     * @param string|null $date    Date filter (Y-m-d format).
     * @param int         $limit   Max files to return.
     * @return array      Array of [ 'path' => ..., 'filename' => ..., 'mtime' => ... ]
     */
    public function get_logs( $user_id, $date = null, $limit = 50 ) {
        $dir = sprintf(
            '%s/site-%d/user-%d',
            $this->logs_dir,
            get_current_blog_id(),
            intval( $user_id )
        );

        if ( ! is_dir( $dir ) ) return [];

        $files = [];
        foreach ( glob( $dir . '/*.md' ) as $filepath ) {
            $filename = basename( $filepath );

            // Date filter
            if ( $date && strpos( $filename, $date ) !== 0 ) {
                continue;
            }

            $files[] = [
                'path'     => $filepath,
                'filename' => $filename,
                'mtime'    => filemtime( $filepath ),
                'size'     => filesize( $filepath ),
            ];
        }

        // Sort newest first
        usort( $files, function( $a, $b ) {
            return $b['mtime'] - $a['mtime'];
        });

        return array_slice( $files, 0, $limit );
    }

    /**
     * Read a specific log file's content.
     *
     * @param int    $user_id
     * @param string $filename
     * @return string|false
     */
    public function read_log( $user_id, $filename ) {
        // Sanitize to prevent path traversal
        $filename = basename( $filename );
        if ( ! preg_match( '/^[\d_\-a-zA-Z]+\.md$/', $filename ) ) {
            return false;
        }

        $filepath = sprintf(
            '%s/site-%d/user-%d/%s',
            $this->logs_dir,
            get_current_blog_id(),
            intval( $user_id ),
            $filename
        );

        if ( ! file_exists( $filepath ) ) return false;

        return file_get_contents( $filepath );
    }
}
