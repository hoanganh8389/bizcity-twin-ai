<?php
/**
 * Bizcity Twin AI — Zalo Channel Formatter (PHASE-0.35 GURU-ZALO-BOT §1.5).
 *
 * Concrete formatter for Zalo Bot Platform messages. Constraints baked in:
 *   - No markdown rendering — strip `**bold**`, headings, links into plain.
 *   - 2000-char hard cap per message (Zalo OA limit; we leave 200ch for footer).
 *   - Sources surfaced as a plain "📚 Nguồn:" block below the reply.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\ChannelGateway\Formatters
 * @since      Phase 0.35 (2026-05-26)
 * @license    GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BizCity_Channel_Formatter' ) ) {
    require_once __DIR__ . '/class-channel-formatter.php';
}

if ( class_exists( 'BizCity_Zalo_Formatter', false ) ) {
    return;
}

final class BizCity_Zalo_Formatter extends BizCity_Channel_Formatter {

    const MAX_BODY   = 1800;
    const MAX_FOOTER = 200;

    public function channel(): string { return 'zalo'; }

    public function format( BizCity_Guru_Reply_DTO $dto, array $envelope = [] ): BizCity_Channel_Send_DTO {
        $text = $this->markdown_to_plain( $dto->text );
        $text = BizCity_Guru_Citation_Formatter::strip( $text );
        $text = $this->truncate( $text, self::MAX_BODY );

        $footer = $this->build_sources_footer( $dto->citations );
        if ( $footer !== '' ) {
            $footer = "\n\n📚 " . $this->truncate( $footer, self::MAX_FOOTER - 4 );
            $text .= $footer;
        }

        $send                = new BizCity_Channel_Send_DTO();
        $send->channel       = 'zalo';
        $send->recipient_ref = (string) ( $envelope['recipient_ref'] ?? $envelope['chat_id'] ?? '' );
        $send->text          = $text;
        $send->footer_blocks = $footer !== '' ? [ trim( $footer ) ] : [];
        $send->meta          = [
            'trace_id'     => $dto->trace_id,
            'character_id' => $dto->character_id,
            'bot_id'       => $envelope['bot_id']      ?? null,
            'zalo_user_id' => $envelope['zalo_user_id'] ?? null,
        ];
        return $send;
    }

    /**
     * Lightweight markdown→plain — preserves line breaks, removes formatting.
     */
    private function markdown_to_plain( string $md ): string {
        $s = $md;
        // Code fences → plain code body.
        $s = preg_replace( '/```[a-z0-9_-]*\n?/i', '', $s );
        $s = str_replace( '```', '', $s );
        // Inline code.
        $s = preg_replace( '/`([^`]+)`/', '$1', $s );
        // Bold / italic / strikethrough markers.
        $s = preg_replace( '/(\*\*|__)(.+?)\1/s', '$2', $s );
        $s = preg_replace( '/(\*|_)(.+?)\1/s', '$2', $s );
        $s = preg_replace( '/~~(.+?)~~/s', '$1', $s );
        // Headings.
        $s = preg_replace( '/^#{1,6}\s+/m', '', $s );
        // Links [label](url) → "label (url)".
        $s = preg_replace( '/\[([^\]]+)\]\((https?:\/\/[^\s\)]+)\)/', '$1 ($2)', $s );
        // Images.
        $s = preg_replace( '/!\[[^\]]*\]\([^\)]+\)/', '', $s );
        // Blockquote markers.
        $s = preg_replace( '/^>\s?/m', '', $s );
        // Bullet markers → "• ".
        $s = preg_replace( '/^[\-\*\+]\s+/m', '• ', $s );
        // Collapse 3+ blank lines.
        $s = preg_replace( "/\n{3,}/", "\n\n", $s );
        return trim( (string) $s );
    }

    private function truncate( string $s, int $max ): string {
        if ( $max <= 0 || mb_strlen( $s ) <= $max ) { return $s; }
        return rtrim( mb_substr( $s, 0, $max - 1 ) ) . '…';
    }
}

// Self-register with the dispatcher.
add_filter( 'bizcity_channel_formatters', static function ( $map ) {
    if ( ! is_array( $map ) ) { $map = []; }
    if ( ! isset( $map['zalo'] ) ) {
        $map['zalo'] = new BizCity_Zalo_Formatter();
    }
    return $map;
} );
