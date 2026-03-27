<?php
/**
 * Bizcity Twin AI — Nền tảng AI Companion cá nhân hóa
 * Bizcity Twin AI — Personalized AI Companion Platform
 *
 * File Processor — Xử lý CSV, Excel, PDF, JSON thành kiến thức
 * File Processor — Process CSV, Excel, PDF, JSON into knowledge
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

defined('ABSPATH') or die('OOPS...');

class BizCity_File_Processor {
    
    private static $instance = null;
    
    /**
     * Supported file types
     */
    const SUPPORTED_TYPES = ['csv', 'xlsx', 'xls', 'json', 'pdf', 'txt', 'md'];
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Process uploaded file
     */
    public function process_file($file_path, $character_id, $file_type = null) {
        if (!file_exists($file_path)) {
            return new WP_Error('file_not_found', 'File not found: ' . $file_path);
        }
        
        // Detect file type
        if (empty($file_type)) {
            $file_type = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        }
        
        if (!in_array($file_type, self::SUPPORTED_TYPES)) {
            return new WP_Error('unsupported_type', 'Unsupported file type: ' . $file_type);
        }
        
        // Process by type
        switch ($file_type) {
            case 'csv':
                return $this->process_csv($file_path, $character_id);
                
            case 'xlsx':
            case 'xls':
                return $this->process_excel($file_path, $character_id);
                
            case 'json':
                return $this->process_json($file_path, $character_id);
                
            case 'pdf':
                return $this->process_pdf($file_path, $character_id);
                
            case 'txt':
            case 'md':
                return $this->process_text($file_path, $character_id);
                
            default:
                return new WP_Error('unknown_type', 'Unknown file type');
        }
    }
    
    /**
     * Process CSV file
     */
    public function process_csv($file_path, $character_id) {
        $handle = fopen($file_path, 'r');
        
        if (!$handle) {
            return new WP_Error('cannot_open', 'Cannot open CSV file');
        }
        
        $rows = [];
        $headers = null;
        $row_count = 0;
        
        // Detect encoding and BOM
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            fseek($handle, 0);
        }
        
        while (($data = fgetcsv($handle, 0, ',')) !== false) {
            $row_count++;
            
            // First row as headers
            if ($headers === null) {
                $headers = array_map('trim', $data);
                continue;
            }
            
            // Combine with headers
            $row = [];
            foreach ($headers as $i => $header) {
                $row[$header] = isset($data[$i]) ? trim($data[$i]) : '';
            }
            
            $rows[] = $row;
        }
        
        fclose($handle);
        
        if (empty($rows)) {
            return new WP_Error('empty_csv', 'CSV file is empty');
        }
        
        // Convert to knowledge content
        $content = $this->rows_to_knowledge($rows, $headers);
        
        // Save
        return $this->save_file_knowledge($content, $file_path, $character_id, [
            'type' => 'csv',
            'headers' => $headers,
            'row_count' => count($rows),
        ]);
    }
    
    /**
     * Process Excel file (simplified - use CSV export or PHPSpreadsheet if available)
     */
    public function process_excel($file_path, $character_id) {
        // Check if PhpSpreadsheet is available
        if (class_exists('PhpOffice\PhpSpreadsheet\IOFactory')) {
            return $this->process_excel_phpspreadsheet($file_path, $character_id);
        }
        
        // Fallback: Try converting to CSV first
        // In a real implementation, you'd integrate a library like PhpSpreadsheet
        return new WP_Error(
            'excel_not_supported', 
            'Excel processing requires PHPSpreadsheet library. Please convert to CSV first.'
        );
    }
    
    /**
     * Process Excel using PhpSpreadsheet
     */
    private function process_excel_phpspreadsheet($file_path, $character_id) {
        try {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file_path);
            $worksheet = $spreadsheet->getActiveSheet();
            
            $rows = [];
            $headers = null;
            
            foreach ($worksheet->getRowIterator() as $row) {
                $cellIterator = $row->getCellIterator();
                $cellIterator->setIterateOnlyExistingCells(false);
                
                $rowData = [];
                foreach ($cellIterator as $cell) {
                    $rowData[] = $cell->getValue();
                }
                
                if ($headers === null) {
                    $headers = array_map('trim', $rowData);
                    continue;
                }
                
                $row = [];
                foreach ($headers as $i => $header) {
                    $row[$header] = isset($rowData[$i]) ? trim($rowData[$i]) : '';
                }
                
                $rows[] = $row;
            }
            
            $content = $this->rows_to_knowledge($rows, $headers);
            
            return $this->save_file_knowledge($content, $file_path, $character_id, [
                'type' => 'excel',
                'headers' => $headers,
                'row_count' => count($rows),
            ]);
            
        } catch (Exception $e) {
            return new WP_Error('excel_error', $e->getMessage());
        }
    }
    
    /**
     * Process JSON file
     */
    public function process_json($file_path, $character_id) {
        $json_content = file_get_contents($file_path);
        
        if (empty($json_content)) {
            return new WP_Error('empty_json', 'JSON file is empty');
        }
        
        $data = json_decode($json_content, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('invalid_json', 'Invalid JSON: ' . json_last_error_msg());
        }
        
        // Convert JSON to readable knowledge
        $content = $this->json_to_knowledge($data);
        
        return $this->save_file_knowledge($content, $file_path, $character_id, [
            'type' => 'json',
            'structure' => is_array($data) ? (isset($data[0]) ? 'array' : 'object') : 'scalar',
        ]);
    }
    
    /**
     * Process PDF file (basic text extraction)
     */
    public function process_pdf($file_path, $character_id) {
        // Check if Smalot\PdfParser is available
        if (class_exists('Smalot\PdfParser\Parser')) {
            return $this->process_pdf_smalot($file_path, $character_id);
        }
        
        // Try pdftotext command (if available on server)
        $pdftotext = $this->pdf_to_text_command($file_path);
        
        if (!is_wp_error($pdftotext) && !empty($pdftotext)) {
            return $this->save_file_knowledge($pdftotext, $file_path, $character_id, [
                'type' => 'pdf',
                'method' => 'pdftotext',
            ]);
        }
        
        return new WP_Error(
            'pdf_not_supported',
            'PDF processing requires Smalot\PdfParser library or pdftotext command. Please convert to text first.'
        );
    }
    
    /**
     * Process PDF using Smalot\PdfParser
     */
    private function process_pdf_smalot($file_path, $character_id) {
        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($file_path);
            
            $text = $pdf->getText();
            
            if (empty($text)) {
                return new WP_Error('empty_pdf', 'Could not extract text from PDF');
            }
            
            // Clean up text
            $text = preg_replace('/\s+/', ' ', $text);
            $text = preg_replace('/\n{3,}/', "\n\n", $text);
            
            return $this->save_file_knowledge($text, $file_path, $character_id, [
                'type' => 'pdf',
                'method' => 'smalot_parser',
                'pages' => count($pdf->getPages()),
            ]);
            
        } catch (Exception $e) {
            return new WP_Error('pdf_error', $e->getMessage());
        }
    }
    
    /**
     * PDF to text using command line
     */
    private function pdf_to_text_command($file_path) {
        // Check if pdftotext is available
        $check = shell_exec('which pdftotext 2>/dev/null');
        
        if (empty($check)) {
            return new WP_Error('pdftotext_not_found', 'pdftotext command not found');
        }
        
        $output_file = sys_get_temp_dir() . '/' . uniqid('pdf_') . '.txt';
        
        exec("pdftotext -layout " . escapeshellarg($file_path) . " " . escapeshellarg($output_file), $output, $return);
        
        if ($return !== 0 || !file_exists($output_file)) {
            return new WP_Error('pdftotext_failed', 'Failed to convert PDF to text');
        }
        
        $text = file_get_contents($output_file);
        unlink($output_file);
        
        return $text;
    }
    
    /**
     * Process plain text or markdown
     */
    public function process_text($file_path, $character_id) {
        $content = file_get_contents($file_path);
        
        if (empty($content)) {
            return new WP_Error('empty_file', 'Text file is empty');
        }
        
        // Detect and convert encoding if needed
        $encoding = mb_detect_encoding($content, ['UTF-8', 'ISO-8859-1', 'Windows-1252']);
        if ($encoding && $encoding !== 'UTF-8') {
            $content = mb_convert_encoding($content, 'UTF-8', $encoding);
        }
        
        return $this->save_file_knowledge($content, $file_path, $character_id, [
            'type' => pathinfo($file_path, PATHINFO_EXTENSION),
            'encoding' => $encoding,
        ]);
    }
    
    /**
     * Convert rows (from CSV/Excel) to knowledge content
     */
    private function rows_to_knowledge($rows, $headers) {
        $content = "Dữ liệu từ file:\n\n";
        $content .= "Cột dữ liệu: " . implode(', ', $headers) . "\n\n";
        
        // Check if this looks like Q&A or FAQ data
        $qa_headers = ['question', 'answer', 'câu_hỏi', 'câu hỏi', 'trả_lời', 'trả lời', 'hỏi', 'đáp'];
        $is_qa = false;
        $q_col = null;
        $a_col = null;
        
        foreach ($headers as $header) {
            $lower = mb_strtolower($header, 'UTF-8');
            if (in_array($lower, ['question', 'câu_hỏi', 'câu hỏi', 'hỏi', 'q'])) {
                $q_col = $header;
            }
            if (in_array($lower, ['answer', 'trả_lời', 'trả lời', 'đáp', 'a'])) {
                $a_col = $header;
            }
        }
        
        if ($q_col && $a_col) {
            // Format as Q&A
            $content = "Danh sách câu hỏi và trả lời:\n\n";
            foreach ($rows as $row) {
                if (!empty($row[$q_col])) {
                    $content .= "Q: {$row[$q_col]}\n";
                    $content .= "A: {$row[$a_col]}\n\n";
                }
            }
        } else {
            // Format as records
            foreach ($rows as $i => $row) {
                $content .= "### Bản ghi " . ($i + 1) . "\n";
                foreach ($row as $key => $value) {
                    if (!empty($value)) {
                        $content .= "- {$key}: {$value}\n";
                    }
                }
                $content .= "\n";
            }
        }
        
        return $content;
    }
    
    /**
     * Convert JSON data to knowledge content
     */
    private function json_to_knowledge($data, $depth = 0, $prefix = '') {
        $content = '';
        $indent = str_repeat('  ', $depth);
        
        if (is_array($data)) {
            // Check if it's a list or object
            if (isset($data[0])) {
                // List
                foreach ($data as $i => $item) {
                    $content .= $prefix . "Item " . ($i + 1) . ":\n";
                    $content .= $this->json_to_knowledge($item, $depth + 1);
                    $content .= "\n";
                }
            } else {
                // Object
                foreach ($data as $key => $value) {
                    if (is_scalar($value) || is_null($value)) {
                        $content .= $indent . "- {$key}: " . (string)$value . "\n";
                    } else {
                        $content .= $indent . "{$key}:\n";
                        $content .= $this->json_to_knowledge($value, $depth + 1);
                    }
                }
            }
        } else {
            $content .= $indent . (string)$data . "\n";
        }
        
        return $content;
    }
    
    /**
     * Save file content as knowledge source
     */
    private function save_file_knowledge($content, $file_path, $character_id, $metadata = []) {
        $db = BizCity_Knowledge_Database::instance();
        
        $filename = basename($file_path);
        
        $source_id = $db->create_knowledge_source([
            'character_id' => $character_id,
            'source_type' => 'file',
            'source_name' => $filename,
            'source_url' => '',
            'content' => $content,
            'content_hash' => md5($content),
            'status' => 'ready',
            'last_synced_at' => current_time('mysql'),
            'settings' => json_encode(array_merge($metadata, [
                'original_file' => $filename,
                'file_size' => filesize($file_path),
            ])),
        ]);
        
        if (is_wp_error($source_id)) {
            return $source_id;
        }
        
        // Create chunks
        $chunks = BizCity_Knowledge_Source::chunk_content($content);
        
        foreach ($chunks as $index => $chunk_content) {
            $db->create_chunk([
                'source_id' => $source_id,
                'character_id' => $character_id,
                'chunk_index' => $index,
                'content' => $chunk_content,
                'token_count' => BizCity_Knowledge_Source::count_tokens($chunk_content),
                'metadata' => $metadata,
            ]);
        }
        
        // Update chunks count
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'bizcity_knowledge_sources',
            ['chunks_count' => count($chunks)],
            ['id' => $source_id]
        );
        
        return [
            'source_id' => $source_id,
            'filename' => $filename,
            'content_length' => strlen($content),
            'chunks_count' => count($chunks),
            'metadata' => $metadata,
        ];
    }
    
    /**
     * Process WordPress media attachment
     */
    public function process_attachment($attachment_id, $character_id) {
        $file_path = get_attached_file($attachment_id);
        
        if (!$file_path || !file_exists($file_path)) {
            return new WP_Error('attachment_not_found', 'Attachment file not found');
        }
        
        return $this->process_file($file_path, $character_id);
    }
}
