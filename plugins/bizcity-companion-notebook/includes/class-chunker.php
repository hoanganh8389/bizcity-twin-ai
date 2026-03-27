<?php
defined( 'ABSPATH' ) || exit;

/**
 * BCN_Chunker — Smart text chunking for embedding.
 *
 * Splits large documents into overlapping chunks optimized for
 * embedding models (target ~500 tokens ≈ 2000 chars).
 */
class BCN_Chunker {

    /** @var int Target characters per chunk (~500 tokens). */
    private int $chunk_size;

    /** @var int Overlap in characters between adjacent chunks. */
    private int $overlap;

    /**
     * @param int $chunk_size  Target chunk size in characters (default 2000 ≈ 500 tokens).
     * @param int $overlap     Overlap between chunks in characters (default 200).
     */
    public function __construct( int $chunk_size = 2000, int $overlap = 200 ) {
        $this->chunk_size = max( 200, $chunk_size );
        $this->overlap    = min( $chunk_size / 2, max( 0, $overlap ) );
    }

    /**
     * Chunk text into segments suitable for embedding.
     *
     * Strategy: split by paragraphs first, then merge small paragraphs
     * and split large ones by sentences. Maintains semantic coherence.
     *
     * @param string $text       The full text to chunk.
     * @param string $doc_title  Optional document title (prepended to each chunk for context).
     * @return array[] Array of [ 'content' => string, 'token_count' => int, 'chunk_index' => int ]
     */
    public function chunk( string $text, string $doc_title = '' ): array {
        $text = $this->normalize( $text );

        if ( empty( $text ) ) {
            return [];
        }

        // Small document — single chunk.
        if ( mb_strlen( $text ) <= $this->chunk_size ) {
            $content = $doc_title ? "[{$doc_title}]\n{$text}" : $text;
            return [ [
                'content'     => $content,
                'token_count' => $this->estimate_tokens( $content ),
                'chunk_index' => 0,
            ] ];
        }

        // Split into paragraphs.
        $paragraphs = preg_split( '/\n{2,}/', $text );
        $paragraphs = array_filter( array_map( 'trim', $paragraphs ) );
        $paragraphs = array_values( $paragraphs );

        $chunks = [];
        $buffer = '';
        $chunk_index = 0;

        foreach ( $paragraphs as $para ) {
            // If single paragraph exceeds chunk size, split by sentences.
            if ( mb_strlen( $para ) > $this->chunk_size ) {
                // Flush current buffer first.
                if ( $buffer !== '' ) {
                    $chunks[] = $this->make_chunk( $buffer, $doc_title, $chunk_index++ );
                    $buffer = $this->get_overlap_text( $buffer );
                }
                // Split large paragraph into sentence-based chunks.
                $sentence_chunks = $this->split_by_sentences( $para );
                foreach ( $sentence_chunks as $sc ) {
                    $candidate = $buffer !== '' ? $buffer . "\n\n" . $sc : $sc;
                    if ( mb_strlen( $candidate ) > $this->chunk_size && $buffer !== '' ) {
                        $chunks[] = $this->make_chunk( $buffer, $doc_title, $chunk_index++ );
                        $buffer = $this->get_overlap_text( $buffer );
                        $buffer = $buffer !== '' ? $buffer . "\n\n" . $sc : $sc;
                    } else {
                        $buffer = $candidate;
                    }
                }
                continue;
            }

            $candidate = $buffer !== '' ? $buffer . "\n\n" . $para : $para;

            if ( mb_strlen( $candidate ) > $this->chunk_size ) {
                // Flush buffer, start new with overlap + current paragraph.
                $chunks[] = $this->make_chunk( $buffer, $doc_title, $chunk_index++ );
                $overlap_text = $this->get_overlap_text( $buffer );
                $buffer = $overlap_text !== '' ? $overlap_text . "\n\n" . $para : $para;
            } else {
                $buffer = $candidate;
            }
        }

        // Flush remaining.
        if ( trim( $buffer ) !== '' ) {
            $chunks[] = $this->make_chunk( $buffer, $doc_title, $chunk_index );
        }

        return $chunks;
    }

    /**
     * Split a long paragraph into sentence groups.
     */
    private function split_by_sentences( string $text ): array {
        // Split on sentence boundaries (., !, ?) followed by whitespace.
        // Use alternation for fixed-length lookbehinds (PCRE compatibility).
        $sentences = preg_split( '/(?:(?<=[.!?])|(?<=[.!?]["\x27)\]]))\s+/u', $text );
        if ( ! is_array( $sentences ) ) {
            // Fallback: split by any whitespace-after-period pattern.
            $sentences = preg_split( '/[.!?]\K\s+/u', $text );
        }
        if ( ! is_array( $sentences ) ) {
            return [ $text ]; // Return as single block if all regex fails.
        }
        $sentences = array_filter( array_map( 'trim', $sentences ) );

        $groups = [];
        $current = '';

        foreach ( $sentences as $sentence ) {
            $candidate = $current !== '' ? $current . ' ' . $sentence : $sentence;
            if ( mb_strlen( $candidate ) > $this->chunk_size && $current !== '' ) {
                $groups[] = $current;
                $current  = $sentence;
            } else {
                $current = $candidate;
            }
        }

        if ( $current !== '' ) {
            $groups[] = $current;
        }

        return $groups;
    }

    /**
     * Get overlap text from the end of a buffer.
     */
    private function get_overlap_text( string $buffer ): string {
        if ( $this->overlap <= 0 || mb_strlen( $buffer ) <= $this->overlap ) {
            return '';
        }
        $tail = mb_substr( $buffer, -$this->overlap );
        // Try to start at a word boundary.
        $space_pos = mb_strpos( $tail, ' ' );
        if ( $space_pos !== false && $space_pos < mb_strlen( $tail ) / 2 ) {
            $tail = mb_substr( $tail, $space_pos + 1 );
        }
        return $tail;
    }

    /**
     * Build a chunk record.
     */
    private function make_chunk( string $content, string $doc_title, int $index ): array {
        $final = $doc_title ? "[{$doc_title}]\n{$content}" : $content;
        return [
            'content'     => $final,
            'token_count' => $this->estimate_tokens( $final ),
            'chunk_index' => $index,
        ];
    }

    /**
     * Normalize whitespace.
     */
    private function normalize( string $text ): string {
        $text = str_replace( "\r\n", "\n", $text );
        $text = str_replace( "\r", "\n", $text );
        $text = preg_replace( '/[ \t]+/', ' ', $text );
        $text = preg_replace( '/\n{4,}/', "\n\n\n", $text );
        return trim( $text );
    }

    /**
     * Rough token estimate: chars / 4.
     */
    private function estimate_tokens( string $text ): int {
        return (int) ceil( mb_strlen( $text ) / 4 );
    }
}
