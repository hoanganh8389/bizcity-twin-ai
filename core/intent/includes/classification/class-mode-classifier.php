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
 * BizCity Mode Classifier — compatibility alias.
 *
 * [2026-09-25 Claude Opus 5.5] CORE-REDUCTION WP-11 C5 (R-INTENT-MIN) — the 65 KB LLM-first classifier, its SQL cache and
 * the conversation-state escapes are gone. Every method delegates to BizCity_Intent_Router. Kept only so third-party
 * code that still names `BizCity_Mode_Classifier` (constants, ::instance()->classify(), extract_url) does not fatal.
 *
 * SUNSET: remove once no plugin references this class (in-repo callers were switched to BizCity_Intent_Router in C5).
 *
 * @deprecated 5.0.0 Use BizCity_Intent_Router::instance()->route().
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( class_exists( 'BizCity_Mode_Classifier', false ) ) {
    return;
}

class BizCity_Mode_Classifier {

    const MODE_EMOTION    = 'emotion';
    const MODE_REFLECTION = 'reflection';
    const MODE_KNOWLEDGE  = 'knowledge';
    const MODE_EXECUTION  = 'execution';
    const MODE_AMBIGUOUS  = 'ambiguous';

    const CONFIDENCE_THRESHOLD = 0.6;

    const VALID_MODES = [
        self::MODE_EMOTION,
        self::MODE_REFLECTION,
        self::MODE_KNOWLEDGE,
        self::MODE_EXECUTION,
        self::MODE_AMBIGUOUS,
    ];

    /** @var self|null */
    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function set_kci_ratio( int $ratio ): void {
        BizCity_Intent_Router::set_kci_ratio( $ratio );
    }

    public static function set_mention_override( bool $override ): void {
        BizCity_Intent_Router::set_mention_override( $override );
    }

    /**
     * @param string     $message
     * @param array|null $conversation Ignored — intent keeps no conversation state (D-26).
     * @param array      $attachments
     * @return array { mode, confidence, method, is_memory, meta, intent, reason }
     */
    public function classify( $message, $conversation = null, $attachments = [] ) {
        return BizCity_Intent_Router::instance()->route( (string) $message, [ 'attachments' => (array) $attachments ] );
    }

    public function extract_url( $text ) {
        return BizCity_Intent_Router::instance()->extract_url( $text );
    }

    public function has_file_upload( $attachments ) {
        return BizCity_Intent_Router::instance()->has_file_upload( $attachments );
    }

    public static function get_mode_label( $mode ) {
        return BizCity_Intent_Router::get_mode_label( $mode );
    }
}
