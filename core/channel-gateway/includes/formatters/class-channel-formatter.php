<?php
/**
 * Bizcity Twin AI — Channel Formatter base (PHASE-0.35 GURU-ZALO-BOT §1.5).
 *
 * Abstract bridge between a channel-agnostic `BizCity_Guru_Reply_DTO` and the
 * channel-bound `BizCity_Channel_Send_DTO` an adapter actually transmits.
 * Concrete subclasses live alongside this file (`class-zalo-formatter.php`,
 * `class-facebook-formatter.php`, …) and self-register via the
 * `bizcity_channel_formatter` filter.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\ChannelGateway\Formatters
 * @since      Phase 0.35 (2026-05-26)
 * @license    GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Channel_Formatter', false ) ) {
    return;
}

abstract class BizCity_Channel_Formatter {

    /** Slug returned by channel() — used by the dispatcher. */
    abstract public function channel(): string;

    /**
     * Convert a Guru DTO into a channel-bound payload ready for the adapter.
     */
    abstract public function format( BizCity_Guru_Reply_DTO $dto, array $envelope = [] ): BizCity_Channel_Send_DTO;

    /**
     * Build a unified "Nguồn" footer block from DTO citations. Channels that
     * cannot render markdown call this to surface attribution as plain text.
     */
    protected function build_sources_footer( array $citations, int $max = 4 ): string {
        if ( empty( $citations ) ) { return ''; }
        $lines = [ 'Nguồn:' ];
        $i = 0;
        foreach ( $citations as $c ) {
            if ( $i >= $max ) break;
            $label = (string) ( $c['source_label'] ?? '' );
            if ( $label === '' ) {
                $label = sprintf( 'src #%d', (int) ( $c['source_id'] ?? 0 ) );
            }
            $lines[] = sprintf( '%d) %s', ++$i, $label );
        }
        return implode( "\n", $lines );
    }

    /**
     * Stateless dispatcher — pick the right concrete formatter for $channel.
     *
     * @param string $channel
     * @return BizCity_Channel_Formatter|null
     */
    public static function for_channel( string $channel ) {
        $channel = strtolower( $channel );
        $registry = apply_filters( 'bizcity_channel_formatters', [] );
        if ( isset( $registry[ $channel ] ) && $registry[ $channel ] instanceof BizCity_Channel_Formatter ) {
            return $registry[ $channel ];
        }
        return null;
    }
}
