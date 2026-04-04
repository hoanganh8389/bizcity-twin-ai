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
 * BCN_Intent_Detector
 *
 * Centralised intent detection for the Notebook chat engine.
 * Each intent maps to a UI action on the frontend (open dialog, show pill, etc.)
 *
 * Intents
 * ───────
 *  INTENT_SEARCH          – user wants to find / add sources from the web
 *  INTENT_START_RESEARCH  – explicit "start deep research now" (auto-open + auto-run)
 *  INTENT_CREATE_NOTE     – user wants to save a note / decision / todo
 *  INTENT_CREATE_CONTENT  – user wants to create slide / brief / report
 *  INTENT_ANALYZE         – user wants to compare / summarize / evaluate from sources
 *  INTENT_GENERAL         – normal Q&A (no special routing)
 */
class BCN_Intent_Detector {

    // ── Intent constants ──────────────────────────────────────────────
    const INTENT_SEARCH         = 'search';
    const INTENT_START_RESEARCH = 'start_research';
    const INTENT_CREATE_NOTE    = 'create_note';
    const INTENT_CREATE_CONTENT = 'create_content';
    const INTENT_ANALYZE        = 'analyze';
    const INTENT_GENERAL        = 'general';

    // ── Public API ────────────────────────────────────────────────────

    /**
     * Detect the primary intent from a user message.
     *
     * @param  string $message Raw user message.
     * @return array {
     *   'intent' => self::INTENT_*,
     *   'query'  => string  — cleaned query relevant to the intent
     * }
     */
    public static function detect( string $message ): array {
        $msg = mb_strtolower( $message );

        if ( self::match( $msg, self::start_research_patterns() ) ) {
            return [
                'intent' => self::INTENT_START_RESEARCH,
                'query'  => self::trim_keyword( $message, self::start_research_patterns() ),
            ];
        }

        if ( self::match( $msg, self::search_patterns() ) ) {
            return [
                'intent' => self::INTENT_SEARCH,
                'query'  => self::trim_keyword( $message, self::search_patterns() ),
            ];
        }

        if ( self::match( $msg, self::create_content_patterns() ) ) {
            return [ 'intent' => self::INTENT_CREATE_CONTENT, 'query' => $message ];
        }

        if ( self::match( $msg, self::create_note_patterns() ) ) {
            return [ 'intent' => self::INTENT_CREATE_NOTE, 'query' => $message ];
        }

        if ( self::match( $msg, self::analyze_patterns() ) ) {
            return [ 'intent' => self::INTENT_ANALYZE, 'query' => $message ];
        }

        return [ 'intent' => self::INTENT_GENERAL, 'query' => $message ];
    }

    /**
     * Returns true when the message indicates the user wants to search / add
     * sources — covers both INTENT_SEARCH and INTENT_START_RESEARCH.
     */
    public static function is_search( string $message ): bool {
        $msg = mb_strtolower( $message );
        return self::match( $msg, self::search_patterns() )
            || self::match( $msg, self::start_research_patterns() );
    }

    /**
     * Returns true specifically for an explicit "start deep research now" request.
     * Frontend should open AddSourceDialog AND auto-start the search immediately.
     */
    public static function is_start_research( string $message ): bool {
        return self::match( mb_strtolower( $message ), self::start_research_patterns() );
    }

    /**
     * Returns true when the user EXPLICITLY asks to search the web / add more sources.
     * This is a stricter subset of INTENT_SEARCH — used when the project already
     * has sources and we only want to open AddSourceDialog for clear web-search intent,
     * not for generic “tìm hiểu thêm” that should be answered from existing docs.
     */
    public static function is_explicit_web_search( string $message ): bool {
        return self::match( mb_strtolower( $message ), self::explicit_web_search_patterns() );
    }

    // ── Pattern lists ─────────────────────────────────────────────────

    private static function search_patterns(): array {
        return [
            // Vietnamese
            'tìm kiếm thêm', 'tìm kiếm nguồn', 'tìm nguồn',
            'bổ sung nguồn', 'thêm nguồn', 'thêm tài liệu',
            'tìm tài liệu', 'tìm hiểu thêm trên web',
            'tìm trên internet', 'tìm trên mạng',
            'tìm kiếm web', 'tìm kiếm internet',
            'muốn tìm thêm', 'cần tìm thêm nguồn',
            'tìm thêm về', 'tra cứu thêm',
            // English / mixed
            'search web', 'research về', 'deep search',
            'find sources', 'add sources',
        ];
    }

    /**
     * Stricter patterns — only when user CLEARLY wants to search the web.
     * Used for context-aware routing when the project already has sources
     * (so generic "tìm hiểu" falls through to AI answering from docs).
     */
    private static function explicit_web_search_patterns(): array {
        return [
            // Clear web-search signals
            'tìm kiếm nguồn', 'tìm nguồn mới', 'tìm thêm nguồn',
            'bổ sung nguồn', 'thêm nguồn', 'thêm tài liệu mới',
            'tìm hiểu thêm trên web', 'tìm trên internet', 'tìm trên mạng',
            'tìm kiếm web', 'tìm kiếm internet',
            'cần tìm thêm nguồn', 'muốn tìm thêm nguồn',
            'search web', 'find sources', 'add sources', 'deep search',
        ];
    }

    private static function start_research_patterns(): array {
        return [
            // Vietnamese — explicit "start" signals
            'bắt đầu nghiên cứu', 'bắt đầu tìm kiếm', 'bắt đầu tìm',
            'bắt đầu deep research', 'chạy deep research',
            'mở deep research', 'thực hiện deep research',
            'deep research về', 'nghiên cứu sâu', 'nghiên cứu chuyên sâu',
            'tìm kiếm sâu', 'nghiên cứu thêm', 'tìm hiểu sâu hơn',
            // English / mixed
            'deep research', 'start research',
        ];
    }

    private static function create_note_patterns(): array {
        return [
            'lưu lại', 'ghi chú', 'tạo note', 'tạo ghi chú',
            'lưu vào note', 'lưu thành note', 'tóm tắt lưu',
            'tạo decision', 'tạo todo', 'đánh dấu quan trọng',
            'lưu ý quan trọng', 'ghi lại',
        ];
    }

    private static function create_content_patterns(): array {
        return [
            'tạo slide', 'tạo brief', 'tạo báo cáo', 'viết báo cáo',
            'tạo bài thuyết trình', 'tạo outline', 'tạo kế hoạch',
            'viết tóm tắt', 'tạo mục lục', 'xuất file',
            'create slide', 'create brief',
        ];
    }

    private static function analyze_patterns(): array {
        return [
            'so sánh', 'phân tích', 'tóm tắt tài liệu',
            'tổng hợp', 'đánh giá', 'nhận xét tài liệu',
            'liệt kê', 'thống kê từ tài liệu', 'phân tích nguồn',
            'tổng quan', 'rút ra kết luận',
        ];
    }

    // ── Helpers ───────────────────────────────────────────────────────

    private static function match( string $lower_msg, array $patterns ): bool {
        foreach ( $patterns as $p ) {
            if ( mb_strpos( $lower_msg, $p ) !== false ) return true;
        }
        return false;
    }

    /**
     * Strip the matched keyword from the beginning/middle of the message so the
     * remainder is a clean search query passed to AddSourceDialog.
     */
    private static function trim_keyword( string $message, array $patterns ): string {
        $msg = mb_strtolower( $message );
        foreach ( $patterns as $p ) {
            $pos = mb_strpos( $msg, $p );
            if ( false !== $pos ) {
                // Text after the keyword is usually the actual topic.
                $after = trim( mb_substr( $message, $pos + mb_strlen( $p ) ) );
                // Strip leading "về", "của" etc.
                $after = preg_replace( '/^(về|của|cho|với)\s+/u', '', $after );
                return $after ?: trim( mb_substr( $message, 0, $pos ) ) ?: $message;
            }
        }
        return $message;
    }
}
