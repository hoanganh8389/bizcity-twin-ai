<?php
/**
 * Bizcity Twin AI — Guru Citation Formatter (PHASE-0.35 GURU-ZALO-BOT §1.4).
 *
 * Canonicalises every inline citation tag in a Guru reply to the kg-hub form
 * `[src:N#pM]` and cross-validates the tag set against the passages the
 * runtime actually retrieved. Delegates structural validation to
 * `bizcity_kg_validate_citations()` (kg-hub helper) when available.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Persona
 * @since      Phase 0.35 (2026-05-26)
 * @license    GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Guru_Citation_Formatter', false ) ) {
    return;
}

final class BizCity_Guru_Citation_Formatter {

    /** Canonical inline tag regex. */
    const RX_CANON = '/\[src:(\d+)#p(\d+)\]/';

    /** Legacy / draft forms we coerce → canonical. */
    const RX_LEGACY = '/\[N(\d+)P(\d+)\]/';

    /**
     * Normalise tags in $text and return both the rewritten body and the
     * citations[] payload (intersection of tags found in text × passages
     * available to the runtime).
     *
     * @param string $text     Raw LLM output.
     * @param array  $passages Each: { source_id:int, passage_id:int,
     *                                  nb_id?:int, source_label?:string }.
     * @return array{text:string, citations:array<int,array>, missing:array<int,string>}
     */
    public static function canonicalize( string $text, array $passages = [] ): array {
        // 1) Coerce legacy form [N{src}P{p}] → [src:{src}#p{p}].
        $text = preg_replace( self::RX_LEGACY, '[src:$1#p$2]', $text );

        // 2) Index passages by (source_id, passage_id).
        $index = [];
        foreach ( $passages as $p ) {
            $sid = (int) ( $p['source_id'] ?? 0 );
            $pid = (int) ( $p['passage_id'] ?? 0 );
            if ( $sid > 0 && $pid > 0 ) {
                $index[ $sid . ':' . $pid ] = [
                    'nb_id'        => (int)    ( $p['nb_id']        ?? 0 ),
                    'passage_id'   => $pid,
                    'source_id'    => $sid,
                    'source_label' => (string) ( $p['source_label'] ?? '' ),
                ];
            }
        }

        // 3) Collect tags actually present in text, deduped + ordered.
        $citations = [];
        $missing   = [];
        if ( preg_match_all( self::RX_CANON, $text, $m, PREG_SET_ORDER ) ) {
            $seen = [];
            foreach ( $m as $hit ) {
                $sid = (int) $hit[1];
                $pid = (int) $hit[2];
                $key = $sid . ':' . $pid;
                if ( isset( $seen[ $key ] ) ) { continue; }
                $seen[ $key ] = true;
                if ( isset( $index[ $key ] ) ) {
                    $citations[] = $index[ $key ];
                } else {
                    $missing[] = $hit[0];
                }
            }
        }

        // 4) Optional kg-hub structural validator (defensive — non-fatal).
        if ( function_exists( 'bizcity_kg_validate_citations' ) ) {
            try { bizcity_kg_validate_citations( $text, $citations ); } catch ( \Throwable $e ) { /* swallow */ }
        }

        return [
            'text'      => (string) $text,
            'citations' => $citations,
            'missing'   => $missing,
        ];
    }

    /**
     * Strip all citation tags (used by formatters that can't display them,
     * e.g. SMS). The footer builder is responsible for surfacing sources.
     */
    public static function strip( string $text ): string {
        return (string) preg_replace( self::RX_CANON, '', $text );
    }
}
