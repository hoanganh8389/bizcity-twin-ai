<?php
/**
 * BizCity Video Kling - R2 Uploader
 * 
 * Upload videos to Cloudflare R2 storage using existing BizCity R2 MU plugin config.
 * Uses same AWS SDK & constants: BIZCITY_R2_*, BIZCITY_MEDIA_CDN
 * 
 * Key format: uploads/kling-videos/{type}/{date}/{filename}
 * 
 * @package BizCity_Video_Kling
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BizCity_Video_Kling_R2_Uploader {
    
    /**
     * @var \Aws\S3\S3Client|null
     */
    private $s3 = null;
    
    /**
     * Check if R2 is available (config + SDK)
     * 
     * @return bool
     */
    public function is_available() {
        return $this->config_ok() && $this->load_sdk();
    }
    
    /**
     * Check if config constants are defined (same as bizcity-r2.php)
     * 
     * @return bool
     */
    private function config_ok() {
        return defined( 'BIZCITY_R2_ACCESS_KEY' )
            && defined( 'BIZCITY_R2_SECRET_KEY' )
            && defined( 'BIZCITY_R2_BUCKET' )
            && defined( 'BIZCITY_R2_ENDPOINT' )
            && defined( 'BIZCITY_MEDIA_CDN' );
    }
    
    /**
     * Load AWS SDK (same candidates as bizcity-r2.php)
     * 
     * @return bool
     */
    private function load_sdk() {
        if ( class_exists( '\Aws\S3\S3Client' ) ) {
            return true;
        }
        
        $candidates = array(
            WP_CONTENT_DIR . '/mu-plugins/aws-sdk/aws-autoloader.php',
            __DIR__ . '/aws-sdk/aws-autoloader.php',
            WP_CONTENT_DIR . '/mu-plugins/vendor/autoload.php',
            WP_CONTENT_DIR . '/vendor/autoload.php',
            ABSPATH . 'vendor/autoload.php',
        );
        
        foreach ( $candidates as $file ) {
            if ( ! is_readable( $file ) ) {
                continue;
            }
            require_once $file;
            if ( class_exists( '\Aws\S3\S3Client' ) ) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Get S3 client (lazy init)
     * 
     * @return \Aws\S3\S3Client|null
     */
    private function s3() {
        if ( $this->s3 ) {
            return $this->s3;
        }
        
        if ( ! $this->load_sdk() ) {
            return null;
        }
        
        $this->s3 = new \Aws\S3\S3Client( array(
            'version'  => 'latest',
            'region'   => 'auto',
            'endpoint' => BIZCITY_R2_ENDPOINT,
            'credentials' => array(
                'key'    => BIZCITY_R2_ACCESS_KEY,
                'secret' => BIZCITY_R2_SECRET_KEY,
            ),
            'use_path_style_endpoint' => true,
            'http' => array(
                'connect_timeout' => 10,
                'timeout'         => 300, // 5 min for large videos
            ),
        ) );
        
        return $this->s3;
    }
    
    /**
     * Upload a local file to R2
     * 
     * @param string $file_path    Local file path
     * @param string $content_type MIME type (default: video/mp4)
     * @param array  $options      Options: job_id, type, chain_id, segment_index
     * @return array ['success' => bool, 'url' => string, 'key' => string, 'error' => string]
     */
    public function upload_file( $file_path, $content_type = 'video/mp4', $options = array() ) {
        if ( ! $this->is_available() ) {
            return array(
                'success' => false,
                'error'   => 'R2 not configured or SDK not available',
            );
        }
        
        if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
            return array(
                'success' => false,
                'error'   => 'File not found or not readable: ' . $file_path,
            );
        }
        
        $key = $this->generate_key( $file_path, $options );
        
        try {
            $cache_control = defined( 'BIZCITY_R2_CACHE_CONTROL' ) 
                ? BIZCITY_R2_CACHE_CONTROL 
                : 'public, max-age=31536000, immutable';
            
            $this->s3()->putObject( array(
                'Bucket'       => BIZCITY_R2_BUCKET,
                'Key'          => $key,
                'SourceFile'   => $file_path,
                'ContentType'  => $content_type,
                'CacheControl' => $cache_control,
            ) );
            
            $cdn = rtrim( BIZCITY_MEDIA_CDN, '/' );
            $url = $cdn . '/' . $key;
            
            return array(
                'success' => true,
                'url'     => $url,
                'key'     => $key,
            );
            
        } catch ( \Throwable $e ) {
            return array(
                'success' => false,
                'error'   => $e->getMessage(),
            );
        }
    }
    
    /**
     * Upload a shot (segment) video to R2
     * 
     * @param string $file_path Local file path
     * @param string $chain_id  Chain ID
     * @param int    $segment   Segment index
     * @return array
     */
    public function upload_shot( $file_path, $chain_id, $segment ) {
        return $this->upload_file( $file_path, 'video/mp4', array(
            'type'          => 'shots',
            'chain_id'      => $chain_id,
            'segment_index' => $segment,
        ) );
    }
    
    /**
     * Upload final concatenated video to R2
     * 
     * @param string $file_path Local file path
     * @param string $chain_id  Chain ID
     * @return array
     */
    public function upload_final( $file_path, $chain_id ) {
        return $this->upload_file( $file_path, 'video/mp4', array(
            'type'     => 'final',
            'chain_id' => $chain_id,
        ) );
    }
    
    /**
     * Upload single video (non-chain) to R2
     * 
     * @param string $file_path Local file path
     * @param int    $job_id    Job ID
     * @return array
     */
    public function upload_single( $file_path, $job_id ) {
        return $this->upload_file( $file_path, 'video/mp4', array(
            'type'   => 'single',
            'job_id' => $job_id,
        ) );
    }
    
    /**
     * Generate R2 key for video
     * Format: uploads/kling-videos/{type}/{date}/{filename}
     * 
     * @param string $file_path Local file path  
     * @param array  $options   Options
     * @return string R2 key
     */
    private function generate_key( $file_path, $options = array() ) {
        $type      = $options['type'] ?? 'single';
        $timestamp = time();
        $date_path = date( 'Y/m' );
        $ext       = pathinfo( $file_path, PATHINFO_EXTENSION ) ?: 'mp4';
        
        switch ( $type ) {
            case 'shots':
                $chain_id = sanitize_file_name( $options['chain_id'] ?? 'unknown' );
                $segment  = (int) ( $options['segment_index'] ?? 0 );
                $filename = "chain-{$chain_id}-seg-{$segment}-{$timestamp}.{$ext}";
                break;
                
            case 'final':
                $chain_id = sanitize_file_name( $options['chain_id'] ?? 'unknown' );
                $filename = "chain-{$chain_id}-final-{$timestamp}.{$ext}";
                break;
                
            default: // single
                $job_id   = (int) ( $options['job_id'] ?? 0 );
                $filename = "video-{$job_id}-{$timestamp}.{$ext}";
                break;
        }
        
        return "uploads/kling-videos/{$type}/{$date_path}/{$filename}";
    }
}
