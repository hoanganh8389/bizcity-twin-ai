<?php
/**
 * Bizcity Twin AI — Nền tảng AI Companion cá nhân hóa
 * Bizcity Twin AI — Personalized AI Companion Platform
 *
 * File Parser — Extract text from TXT, CSV, PDF, DOCX, HTML
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

defined('ABSPATH') or die('OOPS...');

class BizCity_Knowledge_FileParser {
    
    private static $instance = null;
    
    /**
     * Supported file types
     */
    const SUPPORTED_TYPES = [
        'text/plain' => 'txt',
        'text/csv' => 'csv',
        'application/pdf' => 'pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/msword' => 'doc',
        'text/html' => 'html',
        'application/json' => 'json'
    ];
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Parse file and extract text content
     * 
     * @param string $file_path Full path to file
     * @return string|WP_Error Extracted text or error
     */
    public function parse($file_path) {
        if (!file_exists($file_path)) {
            return new WP_Error('file_not_found', 'File not found: ' . $file_path);
        }
        
        $extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        $mime_type = mime_content_type($file_path);
        
        switch ($extension) {
            case 'txt':
                return $this->parse_txt($file_path);
                
            case 'csv':
                return $this->parse_csv($file_path);
                
            case 'pdf':
                return $this->parse_pdf($file_path);
                
            case 'docx':
                return $this->parse_docx($file_path);
                
            case 'doc':
                return $this->parse_doc($file_path);
                
            case 'html':
            case 'htm':
                return $this->parse_html($file_path);
                
            case 'json':
                return $this->parse_json($file_path);
                
            case 'md':
            case 'markdown':
                return $this->parse_txt($file_path); // Markdown is just text
                
            default:
                return new WP_Error('unsupported_type', 'Unsupported file type: ' . $extension);
        }
    }
    
    /**
     * Parse from WordPress attachment ID
     * 
     * @param int $attachment_id WordPress attachment ID
     * @return string|WP_Error Extracted text or error
     */
    public function parse_attachment($attachment_id) {
        $file_path = get_attached_file($attachment_id);
        
        // Check if local file exists
        if ($file_path && file_exists($file_path)) {
            return $this->parse($file_path);
        }
        
        // If not, try to get from URL (for R2/CDN storage)
        $file_url = wp_get_attachment_url($attachment_id);
        
        if ($file_url) {
            return $this->parse_url($file_url);
        }
        
        return new WP_Error('attachment_not_found', 'Attachment not found');
    }
    
    /**
     * Parse file from URL (for R2/CDN storage)
     * 
     * @param string $url File URL
     * @return string|WP_Error Extracted text or error
     */
    public function parse_url($url) {
        // Get file extension from URL
        $parsed_url = parse_url($url);
        $path = $parsed_url['path'] ?? '';
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        
        if (!$this->is_extension_supported($extension)) {
            return new WP_Error('unsupported_type', 'Unsupported file type: ' . $extension);
        }
        
        // Download file to temp location
        $temp_file = $this->download_to_temp($url, $extension);
        
        if (is_wp_error($temp_file)) {
            return $temp_file;
        }
        
        // Parse the temp file
        $result = $this->parse($temp_file);
        
        // Clean up temp file
        @unlink($temp_file);
        
        return $result;
    }
    
    /**
     * Download file from URL to temp location
     * 
     * @param string $url File URL
     * @param string $extension File extension
     * @return string|WP_Error Temp file path or error
     */
    private function download_to_temp($url, $extension) {
        $response = wp_remote_get($url, [
            'timeout' => 120, // 2 minutes for large files
            'sslverify' => false,
            'headers' => [
                'User-Agent' => 'BizCity-Knowledge/1.0'
            ]
        ]);
        
        if (is_wp_error($response)) {
            return new WP_Error('download_error', 'Could not download file: ' . $response->get_error_message());
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            return new WP_Error('download_error', 'Download failed with status: ' . $status_code);
        }
        
        $body = wp_remote_retrieve_body($response);
        
        if (empty($body)) {
            return new WP_Error('download_error', 'Downloaded file is empty');
        }
        
        // Save to temp file
        $temp_file = wp_tempnam('bk_parse_') . '.' . $extension;
        $written = file_put_contents($temp_file, $body);
        
        if ($written === false) {
            return new WP_Error('write_error', 'Could not write temp file');
        }
        
        return $temp_file;
    }
    
    /**
     * Check if extension is supported
     */
    private function is_extension_supported($extension) {
        return in_array($extension, [
            'txt', 'csv', 'pdf', 'docx', 'doc', 'html', 'htm', 'json', 'md', 'markdown'
        ]);
    }

    /**
     * Parse plain text file
     */
    private function parse_txt($file_path) {
        $content = file_get_contents($file_path);
        
        if ($content === false) {
            return new WP_Error('read_error', 'Could not read file');
        }
        
        // Detect and convert encoding to UTF-8
        $encoding = mb_detect_encoding($content, ['UTF-8', 'ISO-8859-1', 'Windows-1252', 'ASCII'], true);
        
        if ($encoding && $encoding !== 'UTF-8') {
            $content = mb_convert_encoding($content, 'UTF-8', $encoding);
        }
        
        return trim($content);
    }
    
    /**
     * Parse CSV file
     */
    private function parse_csv($file_path) {
        $content = [];
        $handle = fopen($file_path, 'r');
        
        if (!$handle) {
            return new WP_Error('read_error', 'Could not read CSV file');
        }
        
        // Try to detect delimiter
        $first_line = fgets($handle);
        rewind($handle);
        
        $delimiter = ',';
        if (substr_count($first_line, ';') > substr_count($first_line, ',')) {
            $delimiter = ';';
        } elseif (substr_count($first_line, "\t") > substr_count($first_line, ',')) {
            $delimiter = "\t";
        }
        
        $headers = fgetcsv($handle, 0, $delimiter);
        
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            if (count($row) === count($headers)) {
                $row_data = array_combine($headers, $row);
                $content[] = implode(' | ', array_filter($row_data));
            } else {
                $content[] = implode(' | ', array_filter($row));
            }
        }
        
        fclose($handle);
        
        return implode("\n", $content);
    }
    
    /**
     * Parse PDF file (basic extraction without external libraries)
     * For better PDF parsing, consider using libraries like TCPDF, FPDI, or Smalot/PdfParser
     */
    private function parse_pdf($file_path) {
        $content = file_get_contents($file_path);
        
        if ($content === false) {
            return new WP_Error('read_error', 'Could not read PDF file');
        }
        
        // First, try pdftotext tool (best quality)
        $pdftotext = $this->extract_pdf_with_tool($file_path);
        if (!is_wp_error($pdftotext) && !empty(trim($pdftotext))) {
            return $pdftotext;
        }
        
        // Fallback: Try to extract text using regex (works for simple PDFs)
        $text = '';
        
        // Method 1: Extract text between stream...endstream
        preg_match_all('/stream\s*(.+?)\s*endstream/s', $content, $matches);
        
        foreach ($matches[1] as $stream) {
            // Try multiple decompression methods (PDF uses FlateDecode = raw deflate)
            $decoded = $this->decompress_stream($stream);
            if ($decoded !== false) {
                $stream = $decoded;
            }
            
            // Extract text from BT...ET blocks (text objects)
            if (preg_match_all('/BT\s*(.*?)\s*ET/s', $stream, $bt_matches)) {
                foreach ($bt_matches[1] as $text_block) {
                    $text .= $this->extract_text_from_block($text_block) . ' ';
                }
            }
            
            // Also try direct TJ/Tj extraction
            $text .= $this->extract_text_operators($stream) . ' ';
        }
        
        // Method 2: Try to find ToUnicode CMap for better decoding
        $text .= $this->extract_unicode_text($content);
        
        // Clean up text
        $text = $this->clean_pdf_text($text);
        
        // If extraction failed, return warning
        if (empty($text) || strlen($text) < 50) {
            return new WP_Error(
                'pdf_extraction_limited', 
                'Không thể trích xuất văn bản từ PDF. File có thể là ảnh scan hoặc được mã hóa. Vui lòng sử dụng file PDF dạng văn bản hoặc chuyển sang DOCX/TXT.'
            );
        }
        
        return $text;
    }
    
    /**
     * Try multiple decompression methods for PDF streams
     */
    private function decompress_stream($stream) {
        // Method 1: Raw deflate (most common - FlateDecode)
        $decoded = @gzinflate($stream);
        if ($decoded !== false) {
            return $decoded;
        }
        
        // Method 2: gzuncompress (zlib with header)
        $decoded = @gzuncompress($stream);
        if ($decoded !== false) {
            return $decoded;
        }
        
        // Method 3: gzdecode (gzip)
        $decoded = @gzdecode($stream);
        if ($decoded !== false) {
            return $decoded;
        }
        
        // Method 4: Try removing potential header bytes and retry
        if (strlen($stream) > 2) {
            $decoded = @gzinflate(substr($stream, 2));
            if ($decoded !== false) {
                return $decoded;
            }
        }
        
        return false;
    }
    
    /**
     * Extract text from PDF text block (BT...ET)
     */
    private function extract_text_from_block($block) {
        $text = '';
        
        // TJ operator - array of strings with positioning
        if (preg_match_all('/\[(.*?)\]\s*TJ/s', $block, $tj_matches)) {
            foreach ($tj_matches[1] as $tj) {
                preg_match_all('/\((.*?)\)/s', $tj, $strings);
                $text .= implode('', $strings[1]) . ' ';
            }
        }
        
        // Tj operator - single string
        if (preg_match_all('/\((.*?)\)\s*Tj/s', $block, $tj_matches)) {
            $text .= implode(' ', $tj_matches[1]) . ' ';
        }
        
        // ' and " operators
        if (preg_match_all("/\((.*?)\)\s*['\"]\\s/s", $block, $matches)) {
            $text .= implode(' ', $matches[1]) . ' ';
        }
        
        return $text;
    }
    
    /**
     * Extract text using TJ/Tj operators
     */
    private function extract_text_operators($stream) {
        $text = '';
        
        // TJ operator - array
        if (preg_match_all('/\[(.*?)\]\s*TJ/s', $stream, $tj_matches)) {
            foreach ($tj_matches[1] as $tj) {
                preg_match_all('/\((.*?)\)/s', $tj, $strings);
                $text .= implode('', $strings[1]) . ' ';
            }
        }
        
        // Tj operator - single
        if (preg_match_all('/\((.*?)\)\s*Tj/s', $stream, $tj_matches)) {
            $text .= implode(' ', $tj_matches[1]) . ' ';
        }
        
        return $text;
    }
    
    /**
     * Try to extract text using ToUnicode CMap
     */
    private function extract_unicode_text($content) {
        $text = '';
        
        // Look for Unicode hex strings
        if (preg_match_all('/<([0-9A-Fa-f]+)>/s', $content, $hex_matches)) {
            foreach ($hex_matches[1] as $hex) {
                // Convert hex pairs to characters (UTF-16BE)
                if (strlen($hex) >= 4 && strlen($hex) % 4 === 0) {
                    $decoded = '';
                    for ($i = 0; $i < strlen($hex); $i += 4) {
                        $codepoint = hexdec(substr($hex, $i, 4));
                        if ($codepoint >= 0x20 && $codepoint <= 0xFFFF) {
                            $decoded .= mb_chr($codepoint, 'UTF-8');
                        }
                    }
                    if (!empty($decoded) && preg_match('/[\p{L}\p{N}]/u', $decoded)) {
                        $text .= $decoded . ' ';
                    }
                }
            }
        }
        
        return $text;
    }
    
    /**
     * Clean extracted PDF text
     */
    private function clean_pdf_text($text) {
        // Decode PDF escape sequences
        $text = preg_replace_callback('/\\\\([0-7]{3})/', function($m) {
            return chr(octdec($m[1]));
        }, $text);
        $text = str_replace(['\\(', '\\)', '\\\\', '\\n', '\\r', '\\t'], ['(', ')', '\\', "\n", "\r", "\t"], $text);
        
        // Remove non-printable characters but keep Vietnamese
        $text = preg_replace('/[^\x20-\x7E\x{0080}-\x{FFFF}\n\r\t]/u', ' ', $text);
        
        // Normalize whitespace
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        
        return trim($text);
    }
    
    /**
     * Try to extract PDF using system tool
     */
    private function extract_pdf_with_tool($file_path) {
        // Check which exec functions are available
        $disabled_functions = array_map('trim', explode(',', ini_get('disable_functions')));
        $exec_available = !in_array('exec', $disabled_functions);
        $shell_exec_available = !in_array('shell_exec', $disabled_functions);
        $proc_open_available = !in_array('proc_open', $disabled_functions);
        
        // If all exec functions disabled, skip
        if (!$exec_available && !$shell_exec_available && !$proc_open_available) {
            return new WP_Error('exec_disabled', 'All exec functions are disabled');
        }
        
        // Check if pdftotext is available (poppler-utils)
        $check = $this->safe_exec('which pdftotext 2>/dev/null');
        if (empty($check)) {
            $check = $this->safe_exec('where pdftotext 2>nul');
        }
        
        if (empty($check)) {
            return new WP_Error('no_pdftotext', 'pdftotext not available');
        }
        
        $output_file = tempnam(sys_get_temp_dir(), 'pdf_');
        $command = sprintf('pdftotext -enc UTF-8 %s %s 2>&1', 
            escapeshellarg($file_path), 
            escapeshellarg($output_file)
        );
        
        $result = $this->safe_exec($command, true);
        
        if ($result === false || $result === null) {
            @unlink($output_file);
            return new WP_Error('pdftotext_error', 'PDF extraction failed');
        }
        
        $text = file_get_contents($output_file);
        @unlink($output_file);
        
        return trim($text);
    }
    
    /**
     * Parse DOCX file (Office Open XML)
     */
    private function parse_docx($file_path) {
        // DOCX is a ZIP file containing XML
        $zip = new ZipArchive();
        
        if ($zip->open($file_path) !== true) {
            return new WP_Error('zip_error', 'Could not open DOCX file');
        }
        
        // Read document.xml
        $content = $zip->getFromName('word/document.xml');
        $zip->close();
        
        if ($content === false) {
            return new WP_Error('docx_error', 'Could not read DOCX content');
        }
        
        // Parse XML and extract text
        $xml = simplexml_load_string($content, 'SimpleXMLElement', LIBXML_NOERROR);
        
        if ($xml === false) {
            return new WP_Error('xml_error', 'Could not parse DOCX XML');
        }
        
        // Register namespace
        $xml->registerXPathNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        
        // Extract all text nodes
        $text_nodes = $xml->xpath('//w:t');
        $text = '';
        
        foreach ($text_nodes as $node) {
            $text .= (string) $node;
        }
        
        // Also try to preserve paragraph breaks
        $paragraphs = $xml->xpath('//w:p');
        $formatted_text = '';
        
        foreach ($paragraphs as $p) {
            $p->registerXPathNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
            $p_texts = $p->xpath('.//w:t');
            $p_text = '';
            
            foreach ($p_texts as $t) {
                $p_text .= (string) $t;
            }
            
            if (!empty(trim($p_text))) {
                $formatted_text .= trim($p_text) . "\n\n";
            }
        }
        
        return trim($formatted_text ?: $text);
    }
    
    /**
     * Parse DOC file (older Word format)
     * Basic extraction - may not work for all DOC files
     */
    private function parse_doc($file_path) {
        $content = file_get_contents($file_path);
        
        if ($content === false) {
            return new WP_Error('read_error', 'Could not read DOC file');
        }
        
        // Try to extract text using regex (works for simple DOC files)
        // DOC files store text in various ways, this is a basic approach
        
        // Remove binary data markers
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $content);
        
        // Extract readable text
        preg_match_all('/[\x20-\x7E\x{0080}-\x{FFFF}]+/u', $text, $matches);
        $text = implode(' ', $matches[0]);
        
        // Clean up
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);
        
        if (strlen($text) < 50) {
            return new WP_Error(
                'doc_extraction_limited',
                'DOC extraction limited. Please convert to DOCX format for better results.'
            );
        }
        
        return $text;
    }
    
    /**
     * Parse HTML file
     */
    private function parse_html($file_path) {
        $content = file_get_contents($file_path);
        
        if ($content === false) {
            return new WP_Error('read_error', 'Could not read HTML file');
        }
        
        // Remove script and style tags
        $content = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $content);
        $content = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $content);
        
        // Convert some tags to newlines for better formatting
        $content = preg_replace('/<(br|p|div|h[1-6]|li|tr)[^>]*>/i', "\n", $content);
        
        // Remove remaining tags
        $text = strip_tags($content);
        
        // Decode HTML entities
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        // Clean up whitespace
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n\s*\n/', "\n\n", $text);
        
        return trim($text);
    }
    
    /**
     * Parse JSON file
     */
    private function parse_json($file_path) {
        $content = file_get_contents($file_path);
        
        if ($content === false) {
            return new WP_Error('read_error', 'Could not read JSON file');
        }
        
        $data = json_decode($content, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('json_error', 'Invalid JSON: ' . json_last_error_msg());
        }
        
        // Flatten JSON to text
        return $this->flatten_json($data);
    }
    
    /**
     * Recursively flatten JSON to text
     */
    private function flatten_json($data, $prefix = '') {
        $text = '';
        
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                if (is_numeric($key)) {
                    $text .= $this->flatten_json($value, $prefix);
                } else {
                    $new_prefix = $prefix ? "{$prefix} > {$key}" : $key;
                    $text .= $this->flatten_json($value, $new_prefix);
                }
            }
        } else {
            $text .= ($prefix ? "{$prefix}: " : '') . $data . "\n";
        }
        
        return $text;
    }
    
    /**
     * Check if file type is supported
     */
    public function is_supported($file_path) {
        $extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        
        return in_array($extension, [
            'txt', 'csv', 'pdf', 'docx', 'doc', 'html', 'htm', 'json', 'md', 'markdown'
        ]);
    }
    
    /**
     * Get supported file extensions
     */
    public function get_supported_extensions() {
        return ['txt', 'csv', 'pdf', 'docx', 'doc', 'html', 'htm', 'json', 'md'];
    }
    
    /**
     * Safely execute a command using available exec function
     * 
     * @param string $command Command to execute
     * @param bool $use_exec_with_return Use exec() and check return code
     * @return string|null|false Output or null/false on failure
     */
    private function safe_exec($command, $use_exec_with_return = false) {
        $disabled_functions = array_map('trim', explode(',', ini_get('disable_functions')));
        $exec_available = !in_array('exec', $disabled_functions);
        $shell_exec_available = !in_array('shell_exec', $disabled_functions);
        $proc_open_available = !in_array('proc_open', $disabled_functions);
        
        // Try exec() first if we need return code
        if ($use_exec_with_return && $exec_available) {
            $output = [];
            $return_code = 0;
            @exec($command, $output, $return_code);
            
            if ($return_code === 0) {
                return implode("\n", $output);
            }
            return false;
        }
        
        // Try shell_exec
        if ($shell_exec_available) {
            $result = @shell_exec($command);
            if ($result !== null) {
                return $result;
            }
        }
        
        // Try exec
        if ($exec_available) {
            $output = [];
            @exec($command, $output);
            return implode("\n", $output);
        }
        
        // Try proc_open
        if ($proc_open_available) {
            $descriptorspec = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w']
            ];
            
            $process = @proc_open($command, $descriptorspec, $pipes);
            
            if (is_resource($process)) {
                fclose($pipes[0]);
                $stdout = stream_get_contents($pipes[1]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                return $stdout;
            }
        }
        
        return null;
    }
}
