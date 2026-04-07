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
 * Research Ranker — scores and sorts Tavily candidates.
 *
 * Formula:
 *   score = (relevance × 0.5) + (freshness × 0.2) + (domain_authority × 0.2) + (content_depth × 0.1)
 *
 * Output is a 0.0–5.0 value displayed as a ⭐ rating.
 */
class BCN_Research_Ranker {

    // Domain whitelist tiers (domain_authority component)
    private static $domain_tiers = [
        // Tier 1 — Academic / Research → 1.0
        1 => [
            'arxiv.org', 'scholar.google.com', 'semanticscholar.org',
            'pubmed.ncbi.nlm.nih.gov', 'researchgate.net', 'jstor.org',
            'ieeexplore.ieee.org', 'dl.acm.org', 'springer.com',
        ],
        // Tier 2 — Authoritative Tech / News / Universities → 0.8
        2 => [
            'openai.com', 'anthropic.com', 'deepmind.google', 'google.com',
            'microsoft.com', 'meta.com', 'mit.edu', 'stanford.edu',
            'harvard.edu', 'nature.com', 'science.org', 'bbc.com', 'reuters.com',
            'techcrunch.com', 'wired.com', 'thenextweb.com',
        ],
        // Tier 3 — Quality Dev / Blogs / Docs → 0.6
        3 => [
            'medium.com', 'towardsdatascience.com', 'huggingface.co',
            'github.com', 'dev.to', 'substack.com', 'hashnode.com',
            'docs.python.org', 'developer.mozilla.org', 'pytorch.org',
            'tensorflow.org', 'langchain.com',
        ],
    ];

    private static $tier_scores = [ 1 => 1.0, 2 => 0.8, 3 => 0.6 ];
    private static $default_authority = 0.4;

    // Freshness decay: content older than this many days scores 0
    private static $freshness_days = 365;

    /**
     * Rank an array of candidates (from BCN_Tavily_Client::search).
     * Returns them sorted by score DESC, with score appended (0.0–5.0).
     *
     * @param array  $candidates
     * @param int    $top_n  Return only the top N results.
     * @return array
     */
    public static function rank( array $candidates, $top_n = 5 ) {
        foreach ( $candidates as &$item ) {
            $item['score_raw']    = self::compute( $item );
            $item['score']        = round( $item['score_raw'] * 5.0, 1 );
            $item['tier']         = self::get_tier( $item['domain'] ?? '' );
            $item['selected']     = false;
        }
        unset( $item );

        usort( $candidates, fn( $a, $b ) => $b['score_raw'] <=> $a['score_raw'] );

        return array_slice( $candidates, 0, max( 1, (int) $top_n ) );
    }

    // ── Private ──────────────────────────────────────────────────────────

    private static function compute( array $item ) {
        $relevance   = self::relevance( $item );
        $freshness   = self::freshness( $item['published_at'] ?? '' );
        $authority   = self::domain_authority( $item['domain'] ?? '' );
        $depth       = self::content_depth( $item['content'] ?? '' );

        return ( $relevance * 0.5 ) + ( $freshness * 0.2 ) + ( $authority * 0.2 ) + ( $depth * 0.1 );
    }

    /** Normalize Tavily relevance score (0–1). */
    private static function relevance( array $item ) {
        return min( 1.0, max( 0.0, (float) ( $item['score'] ?? 0.0 ) ) );
    }

    /** Freshness decay — 1.0 for today, 0.0 for items ≥ freshness_days old. */
    private static function freshness( $published_at ) {
        if ( empty( $published_at ) ) return 0.5; // unknown date → middle value
        try {
            $ts  = strtotime( $published_at );
            if ( ! $ts ) return 0.5;
            $age_days = ( time() - $ts ) / DAY_IN_SECONDS;
            return max( 0.0, 1.0 - ( $age_days / self::$freshness_days ) );
        } catch ( \Exception $e ) {
            return 0.5;
        }
    }

    /** Domain authority tier score. */
    private static function domain_authority( $domain ) {
        $tier = self::get_tier( $domain );
        if ( $tier && isset( self::$tier_scores[ $tier ] ) ) {
            return self::$tier_scores[ $tier ];
        }
        return self::$default_authority;
    }

    private static function get_tier( $domain ) {
        if ( empty( $domain ) ) return null;
        foreach ( self::$domain_tiers as $tier => $domains ) {
            foreach ( $domains as $d ) {
                if ( $domain === $d || str_ends_with( $domain, '.' . $d ) ) {
                    return $tier;
                }
            }
        }
        return null;
    }

    /** Content depth based on length, capped at MAX_CONTENT. */
    private static function content_depth( $content ) {
        $max = class_exists( 'BizCity_Search_Client' ) ? BizCity_Search_Client::MAX_CONTENT : 5000;
        return min( 1.0, mb_strlen( (string) $content ) / $max );
    }
}
