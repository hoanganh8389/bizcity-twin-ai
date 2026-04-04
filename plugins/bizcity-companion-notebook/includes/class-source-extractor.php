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
 * Source Extractor — Extract text from PDF, URL, YouTube, DOCX.
 */
class BCN_Source_Extractor {

    /**
     * @return array{text: string, metadata: array, error: string|null}
     */
    public function extract( $type, $input ) {
        switch ( $type ) {
            case 'pdf':
                return $this->extract_pdf( $input );
            case 'url':
                return $this->extract_url( $input );
            case 'youtube':
                return $this->extract_youtube( $input );
            case 'docx':
                return $this->extract_docx( $input );
            case 'pptx':
                return $this->extract_pptx( $input );
            case 'xlsx':
                return $this->extract_xlsx( $input );
            case 'audio':
                return $this->extract_audio( $input );
            case 'image':
                return $this->extract_image( $input );
            case 'json':
                return $this->extract_json( $input );
            case 'sql':
                return $this->extract_sql( $input );
            case 'csv':
                return $this->extract_csv( $input );
            case 'text':
            default:
                return $this->extract_text( $input );
        }
    }

    public function extract_pdf( $file_path ) {
        // Try pdftotext command first (fast, accurate).
        if ( $this->can_exec() ) {
            $escaped = escapeshellarg( $file_path );
            $output = shell_exec( "pdftotext -layout {$escaped} -" );
            if ( $output ) {
                return [ 'text' => trim( $output ), 'metadata' => [], 'error' => null ];
            }
        }

        // Fallback: read raw — very basic but works for simple PDFs.
        $content = file_get_contents( $file_path );
        if ( $content ) {
            // Basic text extraction from PDF streams.
            $text = $this->basic_pdf_text( $content );
            return [ 'text' => $text, 'metadata' => [], 'error' => null ];
        }

        return [ 'text' => '', 'metadata' => [], 'error' => 'Could not extract PDF text' ];
    }

    public function extract_url( $url ) {
        $response = wp_remote_get( $url, [
            'timeout'    => 30,
            'user-agent' => 'Mozilla/5.0 (compatible; BizCity Notebook Bot)',
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'text' => '', 'metadata' => [], 'error' => $response->get_error_message() ];
        }

        $html = wp_remote_retrieve_body( $response );
        $text = $this->clean_html( $html );

        return [
            'text'     => $text,
            'metadata' => [ 'url' => $url, 'status' => wp_remote_retrieve_response_code( $response ) ],
            'error'    => null,
        ];
    }

    public function extract_youtube( $url ) {
        // Extract video ID.
        preg_match( '/(?:v=|youtu\.be\/|embed\/)([a-zA-Z0-9_-]{11})/', $url, $m );
        $video_id = $m[1] ?? '';
        if ( ! $video_id ) {
            return [ 'text' => '', 'metadata' => [], 'error' => 'Invalid YouTube URL' ];
        }

        // Try YouTube transcript API (community captions).
        $transcript_url = "https://www.youtube.com/watch?v={$video_id}";
        $response = wp_remote_get( $transcript_url, [ 'timeout' => 15 ] );

        if ( is_wp_error( $response ) ) {
            return [ 'text' => "YouTube Video: {$url}\n(Transcript not available)", 'metadata' => [ 'video_id' => $video_id ], 'error' => null ];
        }

        $body = wp_remote_retrieve_body( $response );

        // Extract title.
        preg_match( '/<title>(.+?)<\/title>/', $body, $title_m );
        $title = html_entity_decode( $title_m[1] ?? 'YouTube Video', ENT_QUOTES );

        // Try to get caption track URL from page source.
        if ( preg_match( '/"captionTracks":\s*(\[.+?\])/', $body, $cap_m ) ) {
            $tracks = json_decode( $cap_m[1], true );
            if ( ! empty( $tracks[0]['baseUrl'] ) ) {
                $cap_response = wp_remote_get( $tracks[0]['baseUrl'], [ 'timeout' => 15 ] );
                if ( ! is_wp_error( $cap_response ) ) {
                    $cap_xml = wp_remote_retrieve_body( $cap_response );
                    // Parse XML captions.
                    preg_match_all( '/<text[^>]*>(.+?)<\/text>/', $cap_xml, $texts );
                    if ( ! empty( $texts[1] ) ) {
                        $transcript = implode( ' ', array_map( 'html_entity_decode', $texts[1] ) );
                        return [
                            'text'     => "Video: {$title}\n\nTranscript:\n{$transcript}",
                            'metadata' => [ 'video_id' => $video_id, 'title' => $title ],
                            'error'    => null,
                        ];
                    }
                }
            }
        }

        return [
            'text'     => "Video: {$title}\nURL: {$url}\n(Auto-transcript not available)",
            'metadata' => [ 'video_id' => $video_id, 'title' => $title ],
            'error'    => null,
        ];
    }

    public function extract_docx( $file_path ) {
        // DOCX = zip file with XML content.
        if ( ! class_exists( 'ZipArchive' ) ) {
            return [ 'text' => '', 'metadata' => [], 'error' => 'ZipArchive not available' ];
        }

        $zip = new ZipArchive();
        if ( $zip->open( $file_path ) !== true ) {
            return [ 'text' => '', 'metadata' => [], 'error' => 'Could not open DOCX file' ];
        }

        $xml = $zip->getFromName( 'word/document.xml' );
        $zip->close();

        if ( ! $xml ) {
            return [ 'text' => '', 'metadata' => [], 'error' => 'No document.xml in DOCX' ];
        }

        // Strip XML tags, keep paragraph breaks.
        $text = preg_replace( '/<w:p[^>]*>/', "\n", $xml );
        $text = wp_strip_all_tags( $text );
        $text = preg_replace( '/\n{3,}/', "\n\n", trim( $text ) );

        return [ 'text' => $text, 'metadata' => [], 'error' => null ];
    }

    public function extract_json( $input ) {
        $raw = file_exists( $input ) ? file_get_contents( $input ) : $input;
        if ( ! $raw ) {
            return [ 'text' => '', 'metadata' => [], 'error' => 'Empty JSON input' ];
        }

        $decoded = json_decode( $raw, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            // Not valid JSON — treat as plain text.
            return [ 'text' => trim( $raw ), 'metadata' => [ 'json_error' => json_last_error_msg() ], 'error' => null ];
        }

        // Pretty-print with readable formatting.
        $text = $this->json_to_readable( $decoded );
        return [ 'text' => $text, 'metadata' => [ 'type' => 'json' ], 'error' => null ];
    }

    public function extract_sql( $input ) {
        $raw = file_exists( $input ) ? file_get_contents( $input ) : $input;
        if ( ! $raw ) {
            return [ 'text' => '', 'metadata' => [], 'error' => 'Empty SQL input' ];
        }

        // Remove multi-line comments but keep single-line comments (context).
        $text = preg_replace( '/\/\*(?!.*licence|.*copyright).*?\*\//si', '', $raw );
        // Normalize whitespace.
        $text = preg_replace( '/\n{3,}/', "\n\n", trim( $text ) );

        return [ 'text' => $text, 'metadata' => [ 'type' => 'sql' ], 'error' => null ];
    }

    public function extract_csv( $input ) {
        $file_path = file_exists( $input ) ? $input : null;

        if ( $file_path ) {
            $handle = fopen( $file_path, 'r' );
            if ( ! $handle ) {
                return [ 'text' => '', 'metadata' => [], 'error' => 'Could not open CSV file' ];
            }

            $headers = fgetcsv( $handle );
            if ( ! $headers ) {
                fclose( $handle );
                return [ 'text' => '', 'metadata' => [], 'error' => 'Empty CSV file' ];
            }

            $lines = [ 'Columns: ' . implode( ', ', $headers ) ];
            $row_num = 0;

            while ( ( $row = fgetcsv( $handle ) ) !== false && $row_num < 10000 ) {
                $row_num++;
                $parts = [];
                foreach ( $headers as $i => $col ) {
                    $val = $row[ $i ] ?? '';
                    if ( $val !== '' ) {
                        $parts[] = "{$col}: {$val}";
                    }
                }
                $lines[] = "Row {$row_num}: " . implode( ' | ', $parts );
            }

            fclose( $handle );

            return [
                'text'     => implode( "\n", $lines ),
                'metadata' => [ 'type' => 'csv', 'rows' => $row_num, 'columns' => count( $headers ) ],
                'error'    => null,
            ];
        }

        // Raw CSV string.
        return [ 'text' => trim( $input ), 'metadata' => [ 'type' => 'csv' ], 'error' => null ];
    }

    public function extract_text( $input ) {
        // If it's a file path, read it.
        if ( file_exists( $input ) ) {
            $input = file_get_contents( $input );
        }
        return [ 'text' => trim( $input ), 'metadata' => [], 'error' => null ];
    }

    private function json_to_readable( $data, $prefix = '' ) {
        $lines = [];

        if ( is_array( $data ) && array_is_list( $data ) ) {
            foreach ( $data as $i => $item ) {
                $label = $prefix ? "{$prefix}[{$i}]" : "Item {$i}";
                if ( is_scalar( $item ) ) {
                    $lines[] = "{$label}: {$item}";
                } else {
                    $lines[] = $this->json_to_readable( $item, $label );
                }
            }
        } elseif ( is_array( $data ) ) {
            foreach ( $data as $key => $val ) {
                $label = $prefix ? "{$prefix}.{$key}" : $key;
                if ( is_scalar( $val ) ) {
                    $lines[] = "{$label}: {$val}";
                } elseif ( is_null( $val ) ) {
                    $lines[] = "{$label}: null";
                } else {
                    $lines[] = $this->json_to_readable( $val, $label );
                }
            }
        } else {
            $lines[] = "{$prefix}: {$data}";
        }

        return implode( "\n", $lines );
    }

    private function clean_html( $html ) {
        // Remove script, style, nav, footer, header.
        $html = preg_replace( '/<(script|style|nav|footer|header|aside|form|iframe)[^>]*>.*?<\/\1>/si', '', $html );
        // Try to find main/article content.
        if ( preg_match( '/<(article|main)[^>]*>(.*?)<\/\1>/si', $html, $m ) ) {
            $html = $m[2];
        }
        // Convert block elements to newlines.
        $html = preg_replace( '/<\/(p|div|li|h[1-6]|tr|br)[^>]*>/i', "\n", $html );
        $text = wp_strip_all_tags( $html );
        // Clean up whitespace.
        $text = preg_replace( '/[ \t]+/', ' ', $text );
        $text = preg_replace( '/\n{3,}/', "\n\n", trim( $text ) );
        return $text;
    }

    private function basic_pdf_text( $content ) {
        // Very basic PDF text extraction — handles simple text streams.
        $text = '';
        preg_match_all( '/\((.*?)\)/', $content, $matches );
        if ( ! empty( $matches[1] ) ) {
            $text = implode( '', $matches[1] );
        }
        return trim( $text ) ?: '(PDF text extraction failed — install pdftotext for better results)';
    }

    /**
     * Extract text from PowerPoint (.pptx) — ZIP-based XML.
     */
    public function extract_pptx( $file_path ) {
        if ( ! class_exists( 'ZipArchive' ) ) {
            return [ 'text' => '', 'metadata' => [], 'error' => 'ZipArchive not available' ];
        }

        $zip = new ZipArchive();
        if ( $zip->open( $file_path ) !== true ) {
            return [ 'text' => '', 'metadata' => [], 'error' => 'Could not open PPTX file' ];
        }

        $slides = [];
        for ( $i = 1; $i <= 999; $i++ ) {
            $xml = $zip->getFromName( "ppt/slides/slide{$i}.xml" );
            if ( ! $xml ) break;

            // Extract text from <a:t> elements.
            preg_match_all( '/<a:t>([^<]+)<\/a:t>/', $xml, $matches );
            if ( ! empty( $matches[1] ) ) {
                $slides[] = "--- Slide {$i} ---\n" . implode( ' ', $matches[1] );
            }
        }
        $zip->close();

        if ( empty( $slides ) ) {
            return [ 'text' => '', 'metadata' => [], 'error' => 'No text found in PPTX' ];
        }

        return [
            'text'     => implode( "\n\n", $slides ),
            'metadata' => [ 'type' => 'pptx', 'slide_count' => count( $slides ) ],
            'error'    => null,
        ];
    }

    /**
     * Extract text from Excel (.xlsx) — ZIP-based XML.
     */
    public function extract_xlsx( $file_path ) {
        if ( ! class_exists( 'ZipArchive' ) ) {
            return [ 'text' => '', 'metadata' => [], 'error' => 'ZipArchive not available' ];
        }

        $zip = new ZipArchive();
        if ( $zip->open( $file_path ) !== true ) {
            return [ 'text' => '', 'metadata' => [], 'error' => 'Could not open XLSX file' ];
        }

        // Read shared strings.
        $shared = [];
        $ss_xml = $zip->getFromName( 'xl/sharedStrings.xml' );
        if ( $ss_xml ) {
            preg_match_all( '/<t[^>]*>([^<]+)<\/t>/', $ss_xml, $m );
            $shared = $m[1] ?? [];
        }

        $lines = [];
        for ( $s = 1; $s <= 50; $s++ ) {
            $xml = $zip->getFromName( "xl/worksheets/sheet{$s}.xml" );
            if ( ! $xml ) break;

            $lines[] = "--- Sheet {$s} ---";
            preg_match_all( '/<row[^>]*>(.*?)<\/row>/s', $xml, $rows );
            $row_num = 0;
            foreach ( $rows[1] ?? [] as $row_xml ) {
                if ( ++$row_num > 5000 ) break;
                preg_match_all( '/<c[^>]*(?:t="s"[^>]*)?>\s*<v>([^<]+)<\/v>/s', $row_xml, $cells );
                preg_match_all( '/<c[^>]*>/', $row_xml, $cell_tags );
                $vals = [];
                $ci = 0;
                foreach ( $cells[0] ?? [] as $cell_block ) {
                    preg_match( '/<v>([^<]+)<\/v>/', $cell_block, $vm );
                    $v = $vm[1] ?? '';
                    if ( strpos( $cell_block, 't="s"' ) !== false && isset( $shared[ (int) $v ] ) ) {
                        $v = $shared[ (int) $v ];
                    }
                    $vals[] = $v;
                }
                if ( $vals ) $lines[] = implode( ' | ', $vals );
            }
        }
        $zip->close();

        if ( count( $lines ) <= 1 ) {
            return [ 'text' => '', 'metadata' => [], 'error' => 'No data found in XLSX' ];
        }

        return [
            'text'     => implode( "\n", $lines ),
            'metadata' => [ 'type' => 'xlsx' ],
            'error'    => null,
        ];
    }

    /**
     * Extract text from audio files via OpenRouter Whisper / transcription.
     * Falls back to metadata-only if no transcription API available.
     */
    public function extract_audio( $file_path ) {
        $size     = filesize( $file_path );
        $ext      = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
        $duration = '';

        // Try getID3 for metadata (WP bundles it).
        if ( function_exists( 'wp_read_audio_metadata' ) ) {
            $meta = wp_read_audio_metadata( $file_path );
            if ( ! empty( $meta['length_formatted'] ) ) {
                $duration = $meta['length_formatted'];
            }
        }

        // Try OpenAI-compatible Whisper transcription.
        if ( function_exists( 'bizcity_openrouter_transcribe' ) ) {
            $result = bizcity_openrouter_transcribe( $file_path );
            if ( ! empty( $result['text'] ) ) {
                $transcript = $result['text'];
                $header = "[Audio file: {$ext}";
                if ( $duration ) $header .= ", duration: {$duration}";
                $header .= "]\n\nTranscript:\n";
                return [
                    'text'     => $header . $transcript,
                    'metadata' => [ 'type' => 'audio', 'duration' => $duration, 'transcribed' => true ],
                    'error'    => null,
                ];
            }
        }

        // Fallback: store minimal metadata so source exists.
        $header = "[Audio file: {$ext}";
        if ( $duration ) $header .= ", duration: {$duration}";
        $header .= ", size: " . size_format( $size ) . "]";
        $header .= "\n\n(Transcription không khả dụng — cần cấu hình Whisper API)";

        return [
            'text'     => $header,
            'metadata' => [ 'type' => 'audio', 'duration' => $duration, 'transcribed' => false ],
            'error'    => null,
        ];
    }

    /**
     * Extract text from images via OCR or Vision model.
     * Falls back to metadata-only if no vision API available.
     */
    public function extract_image( $file_path ) {
        $size = filesize( $file_path );
        $ext  = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
        $dims = '';

        $img_size = @getimagesize( $file_path );
        if ( $img_size ) {
            $dims = "{$img_size[0]}x{$img_size[1]}";
        }

        // Try Vision model (Gemini, GPT-4V, etc.).
        if ( function_exists( 'bizcity_openrouter_vision' ) ) {
            $result = bizcity_openrouter_vision( $file_path, 'Hãy mô tả chi tiết nội dung hình ảnh này. Nếu có text, hãy trích xuất toàn bộ text.' );
            if ( ! empty( $result['text'] ) ) {
                $header = "[Image: {$ext}";
                if ( $dims ) $header .= ", {$dims}";
                $header .= "]\n\n";
                return [
                    'text'     => $header . $result['text'],
                    'metadata' => [ 'type' => 'image', 'dimensions' => $dims, 'vision_analyzed' => true ],
                    'error'    => null,
                ];
            }
        }

        // Fallback.
        $header = "[Image: {$ext}";
        if ( $dims ) $header .= ", {$dims}";
        $header .= ", size: " . size_format( $size ) . "]";
        $header .= "\n\n(Phân tích hình ảnh không khả dụng — cần cấu hình Vision API)";

        return [
            'text'     => $header,
            'metadata' => [ 'type' => 'image', 'dimensions' => $dims, 'vision_analyzed' => false ],
            'error'    => null,
        ];
    }

    private function can_exec() {
        return function_exists( 'shell_exec' ) && ! in_array( 'shell_exec', array_map( 'trim', explode( ',', ini_get( 'disable_functions' ) ) ), true );
    }
}
