<?php
/**
 * Video Kling TTS capability placeholder.
 *
 * Direct provider TTS is quarantined until an approved BizCity gateway wrapper
 * exists. No provider key or provider URL is read from the client plugin.
 * 
 * @package BizCity_Video_Kling
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BizCity_Video_Kling_OpenAI_TTS {
    
    /**
     * Available TTS models
     */
    const MODEL_TTS_1 = 'tts-1';           // Standard quality, faster
    const MODEL_TTS_1_HD = 'tts-1-hd';     // High quality, slower
    const MODEL_GPT_4O_MINI = 'gpt-4o-mini-tts'; // GPT-4o mini TTS
    
    /**
     * Available voices grouped by gender
     */
    const VOICES_FEMALE = array(
        'nova'    => 'Nữ: Nova - Ấm áp, tự nhiên (recommended)',
        'shimmer' => 'Nữ: Shimmer - Trong trẻo, rõ ràng',
        'alloy'   => 'Trung tính: Alloy - Cân bằng',
    );
    
    const VOICES_MALE = array(
        'onyx'    => 'Nam: Onyx - Trầm ấm (recommended)',
        'echo'    => 'Nam: Echo - Mượt mà',
        'fable'   => 'Nam: Fable - Giọng Anh',
    );
    
    const VOICES = array(
        // Female voices
        'nova'    => '👩 Nữ: Nova - Ấm áp, tự nhiên',
        'shimmer' => '👩 Nữ: Shimmer - Trong trẻo',
        // Neutral
        'alloy'   => '🔘 Trung tính: Alloy',
        // Male voices
        'onyx'    => '👨 Nam: Onyx - Trầm ấm',
        'echo'    => '👨 Nam: Echo - Mượt mà', 
        'fable'   => '👨 Nam: Fable - Giọng Anh',
    );
    
    /**
     * Supported audio formats
     */
    const FORMATS = array( 'mp3', 'opus', 'aac', 'flac', 'wav', 'pcm' );
    
    /**
     * Get API key
     * 
     * @return string API key
     */
    public static function get_api_key() {
        // [2026-08-10 Johnny Chu] PHASE-1.24-VIDEO-KLING — provider TTS credentials are quarantined on the client.
        return '';
    }
    
    /**
     * Generate TTS audio from text
     * 
     * @param string $text    Text to convert to speech
     * @param array  $options {
     *     @type string $voice          Voice name (default: nova)
     *     @type string $model          Model name (default: tts-1)
     *     @type float  $speed          Speech speed 0.25-4.0 (default: 1.0)
     *     @type string $response_format Audio format (default: mp3)
     *     @type string $save_path      Optional path to save file
     * }
     * @return array Result with audio_content, path, url
     */
    public static function generate( $text, $options = array() ) {
        $defaults = array(
            'voice'           => 'nova',
            'model'           => self::MODEL_TTS_1,
            'speed'           => 1.0,
            'response_format' => 'mp3',
            'save_path'       => null,
        );
        
        $opts = wp_parse_args( $options, $defaults );
        
        // Validate
        if ( empty( $text ) ) {
            return class_exists( 'BizCity_Error_Payload' )
                ? BizCity_Error_Payload::make( 'invalid_param', 'Thiếu nội dung cần đọc thành tiếng.', 'Nhập nội dung rồi thử lại.', 'invalid_param_generic' )
                : array( 'success' => false, 'code' => 'invalid_param', 'message' => 'Thiếu nội dung cần đọc thành tiếng.', 'hint' => 'Nhập nội dung rồi thử lại.', 'help_code' => 'invalid_param_generic' );
        }
        
        // [2026-08-10 Johnny Chu] PHASE-1.24-VIDEO-KLING — fail closed until a managed TTS capability is approved.
        return class_exists( 'BizCity_Error_Payload' )
            ? BizCity_Error_Payload::make( 'unsupported_operation', 'TTS video chưa được kết nối qua BizCity Gateway.', 'Tắt TTS hoặc chờ capability TTS được bật trên Gateway.', 'video_tts_unavailable' )
            : array(
                'success'   => false,
                'code'      => 'unsupported_operation',
                'message'   => 'TTS video chưa được kết nối qua BizCity Gateway.',
                'hint'      => 'Tắt TTS hoặc chờ capability TTS được bật trên Gateway.',
                'help_code' => 'video_tts_unavailable',
            );
        
    }
    
    /**
     * Generate TTS and save to WordPress uploads directory
     * 
     * @param string $text     Text to convert
     * @param string $filename Output filename (without extension)
     * @param array  $options  TTS options
     * @return array Result with path, url
     */
    public static function generate_and_save( $text, $filename = '', $options = array() ) {
        // Generate unique filename if not provided
        if ( empty( $filename ) ) {
            $filename = 'tts_' . time() . '_' . wp_rand( 1000, 9999 );
        }
        
        // Get upload directory
        $upload_dir = wp_upload_dir();
        $audio_dir = $upload_dir['basedir'] . '/bizcity-kling-audio/';
        
        // Create directory if not exists
        if ( ! file_exists( $audio_dir ) ) {
            wp_mkdir_p( $audio_dir );
        }
        
        // Determine file extension
        $format = $options['response_format'] ?? 'mp3';
        $file_path = $audio_dir . $filename . '.' . $format;
        
        // Generate TTS with save path
        $options['save_path'] = $file_path;
        $result = self::generate( $text, $options );
        
        if ( $result['success'] && isset( $result['path'] ) ) {
            // Add URL
            $result['url'] = $upload_dir['baseurl'] . '/bizcity-kling-audio/' . $filename . '.' . $format;
        }
        
        return $result;
    }
    
    /**
     * Save audio content to file
     * 
     * @param string $audio_content Binary audio content
     * @param string $file_path     Path to save file
     * @return array Result
     */
    private static function save_audio( $audio_content, $file_path ) {
        // Ensure directory exists
        $dir = dirname( $file_path );
        if ( ! file_exists( $dir ) ) {
            wp_mkdir_p( $dir );
        }
        
        // Write file
        $written = file_put_contents( $file_path, $audio_content );
        
        if ( $written === false ) {
            return array(
                'saved'  => false,
                'error'  => 'Failed to write audio file: ' . $file_path,
            );
        }
        
        return array(
            'saved' => true,
            'path'  => $file_path,
            'bytes' => $written,
        );
    }
    
    /**
     * Generate voiceover for video based on prompt/script
     * Optimized for video narration
     * 
     * @param string $text     Script/prompt text
     * @param int    $job_id   Job ID for filename
     * @param array  $options  Additional options
     * @return array Result
     */
    public static function generate_voiceover( $text, $job_id, $options = array() ) {
        // Default options optimized for video
        $voiceover_defaults = array(
            'voice'           => 'nova',     // Clear female voice, good for narration
            'model'           => self::MODEL_TTS_1_HD, // Higher quality for video
            'speed'           => 1.0,        // Normal speed
            'response_format' => 'mp3',      // Compatible with most video editors
        );
        
        $options = wp_parse_args( $options, $voiceover_defaults );
        
        // Generate filename
        $filename = sprintf( 'voiceover_job_%d_%d', $job_id, time() );
        
        return self::generate_and_save( $text, $filename, $options );
    }
    
    /**
     * Estimate audio duration from text
     * Rough estimate: ~150 words per minute at speed 1.0
     * 
     * @param string $text  Input text
     * @param float  $speed Speech speed
     * @return float Estimated duration in seconds
     */
    public static function estimate_duration( $text, $speed = 1.0 ) {
        $word_count = str_word_count( $text );
        $base_wpm = 150; // Words per minute at speed 1.0
        
        $wpm = $base_wpm * $speed;
        $minutes = $word_count / $wpm;
        
        return $minutes * 60; // Convert to seconds
    }
    
    /**
     * Get available voices with descriptions
     * 
     * @return array Voice list
     */
    public static function get_voices() {
        return self::VOICES;
    }
    
    /**
     * Check if API key is configured
     * 
     * @return bool
     */
    public static function is_configured() {
        return ! empty( self::get_api_key() );
    }
    
    /**
     * Test API connection
     * 
     * @return array Test result
     */
    public static function test_connection() {
        if ( ! self::is_configured() ) {
            return array(
                'success' => false,
                'error'   => 'API key not configured',
            );
        }
        
        // Generate short test audio
        $result = self::generate( 'Hello, this is a test.', array(
            'model' => self::MODEL_TTS_1,
            'voice' => 'alloy',
        ) );
        
        if ( $result['success'] ) {
            return array(
                'success'    => true,
                'message'    => 'TTS API connection successful',
                'audio_size' => $result['size'],
            );
        }
        
        return array(
            'success' => false,
            'error'   => $result['error'] ?? 'Unknown error',
        );
    }
    
    /**
     * Clean up old temporary audio files
     * 
     * @param int $max_age Maximum age in seconds (default: 1 day)
     * @return int Number of files deleted
     */
    public static function cleanup_temp_files( $max_age = 86400 ) {
        $upload_dir = wp_upload_dir();
        $audio_dir = $upload_dir['basedir'] . '/bizcity-kling-audio/';
        
        if ( ! is_dir( $audio_dir ) ) {
            return 0;
        }
        
        $deleted = 0;
        $now = time();
        
        foreach ( glob( $audio_dir . '*.{mp3,wav,aac,opus,flac}', GLOB_BRACE ) as $file ) {
            if ( ( $now - filemtime( $file ) ) > $max_age ) {
                if ( unlink( $file ) ) {
                    $deleted++;
                }
            }
        }
        
        return $deleted;
    }
    
    /**
     * Log helper
     */
    private static function log( $message, $data = null ) {
        $line = '[BizCity-TTS] ' . $message;
        if ( $data !== null ) {
            $line .= ' ' . wp_json_encode( $data, JSON_UNESCAPED_UNICODE );
        }
        error_log( $line );
    }
}
