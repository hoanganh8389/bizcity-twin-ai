<?php
/**
 * FFmpeg Presets Library
 * 
 * Pre-built FFmpeg filter presets for video processing:
 * - Lower-third overlay
 * - TikTok-style subtitles
 * - Zoom effects (zoompan)
 * - Scale & crop utilities
 * 
 * Build once → use for 1,000+ videos
 * 
 * @package BizCity_Video_Kling
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BizCity_Video_Kling_FFmpeg_Presets {
    
    /**
     * Default font for drawtext
     * Windows: C:/Windows/Fonts/arial.ttf
     * Linux: /usr/share/fonts/truetype/dejavu/DejaVuSans.ttf
     */
    const DEFAULT_FONT_WIN = 'C\\:/Windows/Fonts/arial.ttf';
    const DEFAULT_FONT_LINUX = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
    
    /**
     * Get FFmpeg binary path
     * 
     * @return string Path to ffmpeg executable
     */
    public static function get_ffmpeg_path() {
        // Check option first
        $path = get_option( 'bizcity_video_kling_ffmpeg_path', '' );
        
        if ( ! empty( $path ) && file_exists( $path ) ) {
            return $path;
        }
        
        // Try common paths
        $common_paths = array(
            '/bin/ffmpeg',
            '/usr/bin/ffmpeg',
            '/usr/local/bin/ffmpeg',
            '/opt/ffmpeg/bin/ffmpeg',
            'C:\\ffmpeg\\bin\\ffmpeg.exe',
            'C:\\Program Files\\ffmpeg\\bin\\ffmpeg.exe',
            'ffmpeg', // System PATH
        );
        
        foreach ( $common_paths as $try_path ) {
            if ( @is_executable( $try_path ) || self::command_exists( $try_path ) ) {
                return $try_path;
            }
        }
        
        return 'ffmpeg'; // Fallback to PATH
    }
    
    /**
     * Check if command exists in PATH
     */
    private static function command_exists( $command ) {
        $check = strtoupper( substr( PHP_OS, 0, 3 ) ) === 'WIN'
            ? 'where ' . escapeshellarg( $command ) . ' 2>nul'
            : 'which ' . escapeshellarg( $command ) . ' 2>/dev/null';
        
        return ! empty( shell_exec( $check ) );
    }
    
    /**
     * Check FFmpeg availability
     * 
     * @return array {
     *     @type bool   $available Whether FFmpeg is available
     *     @type string $version   FFmpeg version string
     *     @type string $path      Path to FFmpeg binary
     *     @type string $output    Full output from -version
     *     @type string $error     Error message if not available
     * }
     */
    public static function check_availability() {
        $path = self::get_ffmpeg_path();
        
        $result = array(
            'available' => false,
            'version'   => '',
            'path'      => $path,
            'output'    => '',
            'error'     => '',
        );
        
        // Check if exec functions are disabled
        $disabled_functions = array_map( 'trim', explode( ',', ini_get( 'disable_functions' ) ) );
        $exec_disabled = in_array( 'exec', $disabled_functions ) && 
                         in_array( 'shell_exec', $disabled_functions ) && 
                         in_array( 'proc_open', $disabled_functions );
        
        if ( $exec_disabled ) {
            $result['error'] = 'PHP exec functions are disabled. Please enable exec, shell_exec, or proc_open in php.ini';
            return $result;
        }
        
        // Check if file exists (for absolute paths)
        if ( strpos( $path, '/' ) !== false || strpos( $path, '\\' ) !== false ) {
            if ( ! file_exists( $path ) ) {
                $result['error'] = 'FFmpeg binary not found at: ' . $path;
                return $result;
            }
        }
        
        // Try to execute ffmpeg -version
        $command = escapeshellarg( $path ) . ' -version 2>&1';
        $output = array();
        $return_code = 0;
        
        // Check which exec function is available
        $exec_available = ! in_array( 'exec', $disabled_functions );
        $shell_exec_available = ! in_array( 'shell_exec', $disabled_functions );
        $proc_open_available = ! in_array( 'proc_open', $disabled_functions );
        
        // Try exec() first
        if ( $exec_available ) {
            @exec( $command, $output, $return_code );
            
            if ( $return_code === 0 && ! empty( $output ) ) {
                $full_output = implode( "\n", $output );
                $result['output'] = $full_output;
                $result['available'] = true;
                
                // Extract version
                if ( preg_match( '/ffmpeg version ([^\s]+)/i', $full_output, $matches ) ) {
                    $result['version'] = $matches[1];
                } else {
                    $result['version'] = 'Unknown version';
                }
                return $result;
            }
        }
        
        // Try shell_exec() as second option  
        if ( $shell_exec_available ) {
            $shell_output = @shell_exec( $command );
            
            if ( ! empty( $shell_output ) && stripos( $shell_output, 'ffmpeg version' ) !== false ) {
                $result['output'] = $shell_output;
                $result['available'] = true;
                
                if ( preg_match( '/ffmpeg version ([^\s]+)/i', $shell_output, $matches ) ) {
                    $result['version'] = $matches[1];
                } else {
                    $result['version'] = 'Unknown version';
                }
                return $result;
            }
        }
        
        // Try proc_open() as last resort
        if ( $proc_open_available ) {
            $alt_result = self::try_proc_open( $path );
            if ( $alt_result['success'] ) {
                $result['available'] = true;
                $result['output'] = $alt_result['output'];
                $result['version'] = $alt_result['version'];
                return $result;
            }
        }
        
        // All methods failed
        $result['error'] = ! empty( $output ) 
            ? 'FFmpeg execution failed: ' . implode( "\n", $output )
            : 'FFmpeg not executable or not found. Tried: ' . 
              ( $exec_available ? 'exec, ' : '' ) . 
              ( $shell_exec_available ? 'shell_exec, ' : '' ) . 
              ( $proc_open_available ? 'proc_open' : '' );
        
        return $result;
    }
    
    /**
     * Try using proc_open as alternative execution method
     */
    private static function try_proc_open( $path ) {
        $result = array(
            'success' => false,
            'output'  => '',
            'version' => '',
        );
        
        $descriptorspec = array(
            0 => array( 'pipe', 'r' ),  // stdin
            1 => array( 'pipe', 'w' ),  // stdout
            2 => array( 'pipe', 'w' ),  // stderr
        );
        
        $process = @proc_open( $path . ' -version', $descriptorspec, $pipes );
        
        if ( is_resource( $process ) ) {
            fclose( $pipes[0] );
            
            $stdout = stream_get_contents( $pipes[1] );
            $stderr = stream_get_contents( $pipes[2] );
            
            fclose( $pipes[1] );
            fclose( $pipes[2] );
            
            $return_code = proc_close( $process );
            
            $output = ! empty( $stdout ) ? $stdout : $stderr;
            
            if ( stripos( $output, 'ffmpeg version' ) !== false ) {
                $result['success'] = true;
                $result['output'] = $output;
                
                if ( preg_match( '/ffmpeg version ([^\s]+)/i', $output, $matches ) ) {
                    $result['version'] = $matches[1];
                }
            }
        }
        
        return $result;
    }
    
    /**
     * Get default font path based on OS
     */
    public static function get_default_font() {
        if ( strtoupper( substr( PHP_OS, 0, 3 ) ) === 'WIN' ) {
            return self::DEFAULT_FONT_WIN;
        }
        return self::DEFAULT_FONT_LINUX;
    }
    
    /**
     * Escape text for FFmpeg drawtext filter
     * 
     * @param string $text Text to escape
     * @return string Escaped text
     */
    public static function escape_text( $text ) {
        // FFmpeg drawtext special characters: ' \ : % 
        $text = str_replace( '\\', '\\\\', $text );
        $text = str_replace( "'", "'\\''", $text );
        $text = str_replace( ':', '\\:', $text );
        $text = str_replace( '%', '%%', $text );
        return $text;
    }
    
    /**
     * Parse hex color to FFmpeg format
     * 
     * @param string $hex Color in #RRGGBB or #RRGGBBAA format
     * @return string FFmpeg color (0xRRGGBBAA)
     */
    public static function parse_color( $hex ) {
        $hex = ltrim( $hex, '#' );
        if ( strlen( $hex ) === 6 ) {
            $hex .= 'FF'; // Add full opacity
        }
        return '0x' . strtoupper( $hex );
    }
    
    // =========================================================================
    // PRESET: Lower Third
    // =========================================================================
    
    /**
     * Generate Lower Third overlay filter
     * 
     * Creates a professional lower-third title bar with text
     * 
     * @param array $options {
     *     @type string $title       Main title text
     *     @type string $subtitle    Subtitle text (optional)
     *     @type string $bg_color    Background color (default: #000000CC)
     *     @type string $text_color  Text color (default: #FFFFFF)
     *     @type int    $height      Bar height in pixels (default: 120)
     *     @type int    $margin      Bottom margin (default: 50)
     *     @type int    $font_size   Title font size (default: 32)
     *     @type int    $padding     Text padding (default: 20)
     *     @type float  $fade_in     Fade in duration (default: 0.5)
     *     @type float  $fade_out    Fade out duration (default: 0.5)
     *     @type float  $start_time  Start time in seconds (default: 0)
     *     @type float  $duration    Display duration (default: 5)
     * }
     * @return string FFmpeg filter_complex value
     */
    public static function preset_lower_third( $options = array() ) {
        $defaults = array(
            'title'       => '',
            'subtitle'    => '',
            'bg_color'    => '#000000CC',
            'text_color'  => '#FFFFFF',
            'height'      => 120,
            'margin'      => 50,
            'font_size'   => 32,
            'padding'     => 20,
            'fade_in'     => 0.5,
            'fade_out'    => 0.5,
            'start_time'  => 0,
            'duration'    => 5,
            'width'       => 1080, // Video width
            'video_height'=> 1920, // Video height
        );
        
        $opts = wp_parse_args( $options, $defaults );
        $font = self::get_default_font();
        
        // Calculate positions
        $bar_y = $opts['video_height'] - $opts['height'] - $opts['margin'];
        $title_y = $bar_y + $opts['padding'];
        $subtitle_y = $title_y + $opts['font_size'] + 8;
        $end_time = $opts['start_time'] + $opts['duration'];
        
        // Build filter
        $filters = array();
        
        // Background bar with fade
        $filters[] = sprintf(
            "drawbox=x=0:y=%d:w=%d:h=%d:color=%s:t=fill:enable='between(t,%s,%s)'",
            $bar_y,
            $opts['width'],
            $opts['height'],
            self::parse_color( $opts['bg_color'] ),
            $opts['start_time'],
            $end_time
        );
        
        // Title text
        if ( ! empty( $opts['title'] ) ) {
            $filters[] = sprintf(
                "drawtext=fontfile='%s':text='%s':fontsize=%d:fontcolor=%s:x=%d:y=%d:enable='between(t,%s,%s)'",
                $font,
                self::escape_text( $opts['title'] ),
                $opts['font_size'],
                self::parse_color( $opts['text_color'] ),
                $opts['padding'],
                $title_y,
                $opts['start_time'],
                $end_time
            );
        }
        
        // Subtitle text
        if ( ! empty( $opts['subtitle'] ) ) {
            $filters[] = sprintf(
                "drawtext=fontfile='%s':text='%s':fontsize=%d:fontcolor=%s:x=%d:y=%d:enable='between(t,%s,%s)'",
                $font,
                self::escape_text( $opts['subtitle'] ),
                (int) ( $opts['font_size'] * 0.7 ),
                self::parse_color( $opts['text_color'] . 'CC' ),
                $opts['padding'],
                $subtitle_y,
                $opts['start_time'],
                $end_time
            );
        }
        
        return implode( ',', $filters );
    }
    
    // =========================================================================
    // PRESET: TikTok-Style Subtitles
    // =========================================================================
    
    /**
     * Generate TikTok-style subtitle filter
     * 
     * Centered text with shadow/outline, animated word highlighting
     * 
     * @param array $options {
     *     @type string $text          Subtitle text
     *     @type string $text_color    Text color (default: #FFFFFF)
     *     @type string $shadow_color  Shadow/stroke color (default: #000000)
     *     @type int    $font_size     Font size (default: 48)
     *     @type int    $shadow_x      Shadow X offset (default: 3)
     *     @type int    $shadow_y      Shadow Y offset (default: 3)
     *     @type string $position      'center', 'top', 'bottom' (default: center)
     *     @type float  $start_time    Start time in seconds
     *     @type float  $end_time      End time in seconds
     *     @type int    $width         Video width (default: 1080)
     *     @type int    $height        Video height (default: 1920)
     *     @type bool   $word_highlight Enable word-by-word highlight (default: false)
     * }
     * @return string FFmpeg filter_complex value
     */
    public static function preset_tiktok_subtitle( $options = array() ) {
        $defaults = array(
            'text'           => '',
            'text_color'     => '#FFFFFF',
            'shadow_color'   => '#000000',
            'highlight_color'=> '#FFFF00',
            'font_size'      => 48,
            'shadow_x'       => 3,
            'shadow_y'       => 3,
            'position'       => 'center',
            'start_time'     => 0,
            'end_time'       => 5,
            'width'          => 1080,
            'height'         => 1920,
            'box'            => true,
            'box_color'      => '#00000080',
            'box_padding'    => 10,
        );
        
        $opts = wp_parse_args( $options, $defaults );
        $font = self::get_default_font();
        
        // Calculate Y position
        switch ( $opts['position'] ) {
            case 'top':
                $y = 150;
                break;
            case 'bottom':
                $y = $opts['height'] - 200;
                break;
            case 'center':
            default:
                $y = (int) ( $opts['height'] / 2 );
                break;
        }
        
        $filters = array();
        
        // Shadow layer (draw slightly offset)
        $filters[] = sprintf(
            "drawtext=fontfile='%s':text='%s':fontsize=%d:fontcolor=%s:x=(w-text_w)/2+%d:y=%d+%d:enable='between(t,%s,%s)'",
            $font,
            self::escape_text( $opts['text'] ),
            $opts['font_size'],
            self::parse_color( $opts['shadow_color'] ),
            $opts['shadow_x'],
            $y,
            $opts['shadow_y'],
            $opts['start_time'],
            $opts['end_time']
        );
        
        // Main text layer
        $box_params = '';
        if ( $opts['box'] ) {
            $box_params = sprintf(
                ':box=1:boxcolor=%s:boxborderw=%d',
                self::parse_color( $opts['box_color'] ),
                $opts['box_padding']
            );
        }
        
        $filters[] = sprintf(
            "drawtext=fontfile='%s':text='%s':fontsize=%d:fontcolor=%s:x=(w-text_w)/2:y=%d%s:enable='between(t,%s,%s)'",
            $font,
            self::escape_text( $opts['text'] ),
            $opts['font_size'],
            self::parse_color( $opts['text_color'] ),
            $y,
            $box_params,
            $opts['start_time'],
            $opts['end_time']
        );
        
        return implode( ',', $filters );
    }
    
    /**
     * Generate multiple TikTok subtitles from SRT-like array
     * 
     * @param array $subtitles Array of ['text' => '', 'start' => 0, 'end' => 5]
     * @param array $options   Common options for all subtitles
     * @return string Combined FFmpeg filter
     */
    public static function preset_tiktok_subtitles_batch( $subtitles, $options = array() ) {
        $filters = array();
        
        foreach ( $subtitles as $sub ) {
            $sub_options = array_merge( $options, array(
                'text'       => $sub['text'],
                'start_time' => $sub['start'],
                'end_time'   => $sub['end'],
            ) );
            $filters[] = self::preset_tiktok_subtitle( $sub_options );
        }
        
        return implode( ',', $filters );
    }
    
    // =========================================================================
    // PRESET: Zoom Effects (Ken Burns)
    // =========================================================================
    
    /**
     * Generate smooth zoom effect using zoompan filter
     * 
     * @param array $options {
     *     @type string $direction   'in', 'out', 'in_out' (default: in)
     *     @type float  $zoom_start  Starting zoom level (default: 1.0)
     *     @type float  $zoom_end    Ending zoom level (default: 1.1)
     *     @type int    $fps         Output FPS (default: 30)
     *     @type float  $duration    Effect duration in seconds (default: 5)
     *     @type int    $width       Output width (default: 1080)
     *     @type int    $height      Output height (default: 1920)
     *     @type string $focus       'center', 'top', 'bottom' (default: center)
     * }
     * @return string FFmpeg zoompan filter
     */
    public static function preset_zoom( $options = array() ) {
        $defaults = array(
            'direction'  => 'in',
            'zoom_start' => 1.0,
            'zoom_end'   => 1.1,
            'fps'        => 30,
            'duration'   => 5,
            'width'      => 1080,
            'height'     => 1920,
            'focus'      => 'center',
        );
        
        $opts = wp_parse_args( $options, $defaults );
        
        // Calculate frames
        $total_frames = $opts['fps'] * $opts['duration'];
        
        // Determine zoom direction
        switch ( $opts['direction'] ) {
            case 'out':
                $z_start = $opts['zoom_end'];
                $z_end = $opts['zoom_start'];
                break;
            case 'in_out':
                // Zoom in first half, zoom out second half
                $z_expr = sprintf(
                    "if(lt(on,%d),%.3f+on*%.6f,%.3f-(on-%d)*%.6f)",
                    $total_frames / 2,
                    $opts['zoom_start'],
                    ( $opts['zoom_end'] - $opts['zoom_start'] ) / ( $total_frames / 2 ),
                    $opts['zoom_end'],
                    $total_frames / 2,
                    ( $opts['zoom_end'] - $opts['zoom_start'] ) / ( $total_frames / 2 )
                );
                break;
            case 'in':
            default:
                $z_start = $opts['zoom_start'];
                $z_end = $opts['zoom_end'];
                break;
        }
        
        // Linear zoom expression (if not in_out)
        if ( $opts['direction'] !== 'in_out' ) {
            $zoom_per_frame = ( $z_end - $z_start ) / $total_frames;
            $z_expr = sprintf( "%.4f+on*%.8f", $z_start, $zoom_per_frame );
        }
        
        // Focus point (x, y expressions)
        switch ( $opts['focus'] ) {
            case 'top':
                $x_expr = 'iw/2-(iw/zoom/2)';
                $y_expr = '0';
                break;
            case 'bottom':
                $x_expr = 'iw/2-(iw/zoom/2)';
                $y_expr = 'ih-(ih/zoom)';
                break;
            case 'center':
            default:
                $x_expr = 'iw/2-(iw/zoom/2)';
                $y_expr = 'ih/2-(ih/zoom/2)';
                break;
        }
        
        return sprintf(
            "zoompan=z='%s':x='%s':y='%s':d=%d:fps=%d:s=%dx%d",
            $z_expr,
            $x_expr,
            $y_expr,
            $total_frames,
            $opts['fps'],
            $opts['width'],
            $opts['height']
        );
    }
    
    /**
     * Generate gentle zoom effect (subtle Ken Burns)
     * 
     * @param float $duration Video duration in seconds
     * @param array $options  Additional options
     * @return string FFmpeg filter
     */
    public static function preset_zoom_gentle( $duration, $options = array() ) {
        return self::preset_zoom( array_merge( array(
            'direction'  => 'in',
            'zoom_start' => 1.0,
            'zoom_end'   => 1.05, // Only 5% zoom
            'duration'   => $duration,
        ), $options ) );
    }
    
    // =========================================================================
    // PRESET: Visual Effects - Vignette
    // =========================================================================
    
    /**
     * Generate vignette effect (darkened edges)
     * 
     * @param array $options {
     *     @type string $angle    Vignette angle in radians (default: PI/5 = ~36°)
     *     @type float  $x0       Center X ratio 0-1 (default: 0.5)
     *     @type float  $y0       Center Y ratio 0-1 (default: 0.5)
     *     @type string $mode     'forward' or 'backward' (default: forward)
     * }
     * @return string FFmpeg vignette filter
     */
    public static function preset_vignette( $options = array() ) {
        $defaults = array(
            'angle' => 'PI/5',
            'x0'    => 0.5,
            'y0'    => 0.5,
            'mode'  => 'forward',
        );
        
        $opts = wp_parse_args( $options, $defaults );
        
        return sprintf(
            "vignette=a=%s:x0=%.2f:y0=%.2f:mode=%s",
            $opts['angle'],
            $opts['x0'],
            $opts['y0'],
            $opts['mode']
        );
    }
    
    /**
     * Subtle vignette for cinematic look
     */
    public static function preset_vignette_subtle() {
        return self::preset_vignette( array( 'angle' => 'PI/6' ) );
    }
    
    /**
     * Strong vignette for dramatic effect
     */
    public static function preset_vignette_dramatic() {
        return self::preset_vignette( array( 'angle' => 'PI/4' ) );
    }
    
    // =========================================================================
    // PRESET: Visual Effects - Color Grading
    // =========================================================================
    
    /**
     * Color grading presets using eq and colorbalance filters
     * 
     * @param string $style Style name: warm, cool, vintage, dramatic, vibrant, desaturated, cinematic
     * @return string FFmpeg filter chain
     */
    public static function preset_color_grade( $style = 'cinematic' ) {
        $presets = array(
            'warm' => array(
                'description' => 'Warm golden tones, sunset feel',
                'filter' => "eq=saturation=1.1:brightness=0.02,colorbalance=rs=0.1:gs=0.05:bs=-0.1:rm=0.1:gm=0.05:bm=-0.05:rh=0.05:gh=0.02:bh=-0.05",
            ),
            'cool' => array(
                'description' => 'Cool blue tones, modern/techy',
                'filter' => "eq=saturation=1.05:brightness=0.01,colorbalance=rs=-0.1:gs=0:bs=0.15:rm=-0.05:gm=0.02:bm=0.1:rh=-0.05:gh=0.02:bh=0.08",
            ),
            'vintage' => array(
                'description' => 'Faded retro look with lifted blacks',
                'filter' => "eq=saturation=0.8:contrast=0.9:brightness=0.05,curves=m='0/0.1 1/0.9',colorbalance=rs=0.1:gs=0.05:bs=-0.05",
            ),
            'dramatic' => array(
                'description' => 'High contrast, deep shadows',
                'filter' => "eq=saturation=1.15:contrast=1.2:brightness=-0.02,curves=m='0/0 0.25/0.15 0.75/0.85 1/1'",
            ),
            'vibrant' => array(
                'description' => 'Punchy colors, Instagram-style',
                'filter' => "eq=saturation=1.3:contrast=1.1:brightness=0.02,unsharp=5:5:0.5",
            ),
            'desaturated' => array(
                'description' => 'Muted colors, documentary style',
                'filter' => "eq=saturation=0.6:contrast=1.05,colorbalance=rs=0.02:gs=0.02:bs=0.02",
            ),
            'cinematic' => array(
                'description' => 'Film-like with teal shadows, orange highlights',
                'filter' => "eq=saturation=1.05:contrast=1.08:brightness=-0.01,colorbalance=rs=-0.05:gs=0:bs=0.08:rm=0:gm=0:bm=0:rh=0.1:gh=0.05:bh=-0.02",
            ),
            'noir' => array(
                'description' => 'Black & white with high contrast',
                'filter' => "eq=saturation=0:contrast=1.3,curves=m='0/0 0.3/0.15 0.7/0.85 1/1'",
            ),
            'golden_hour' => array(
                'description' => 'Beautiful warm sunlight feel',
                'filter' => "eq=saturation=1.15:brightness=0.03,colorbalance=rs=0.15:gs=0.08:bs=-0.12:rm=0.08:gm=0.05:bm=-0.06",
            ),
            'moonlight' => array(
                'description' => 'Cool nighttime/moonlit atmosphere',
                'filter' => "eq=saturation=0.7:brightness=-0.05:contrast=1.1,colorbalance=rs=-0.1:gs=-0.02:bs=0.15:rm=-0.05:gm=0:bm=0.08",
            ),
        );
        
        if ( isset( $presets[ $style ] ) ) {
            return $presets[ $style ]['filter'];
        }
        
        return $presets['cinematic']['filter'];
    }
    
    /**
     * Get all available color grade styles with descriptions
     */
    public static function get_color_grade_styles() {
        return array(
            'warm'        => 'Warm / Ấm áp - Tông vàng cam hoàng hôn',
            'cool'        => 'Cool / Lạnh - Tông xanh hiện đại',
            'vintage'     => 'Vintage / Cổ điển - Màu cũ phong cách retro',
            'dramatic'    => 'Dramatic / Kịch tính - Tương phản cao, bóng tối sâu',
            'vibrant'     => 'Vibrant / Rực rỡ - Màu sắc nổi bật',
            'desaturated' => 'Desaturated / Nhạt màu - Phong cách phim tài liệu',
            'cinematic'   => 'Cinematic / Điện ảnh - Teal & Orange chuyên nghiệp',
            'noir'        => 'Noir / Đen trắng - Tương phản cao nghệ thuật',
            'golden_hour' => 'Golden Hour / Giờ vàng - Ánh nắng ấm đẹp',
            'moonlight'   => 'Moonlight / Ánh trăng - Đêm yên bình',
        );
    }
    
    // =========================================================================
    // PRESET: Visual Effects - Fade Transitions
    // =========================================================================
    
    /**
     * Generate fade in effect at video start
     * 
     * @param float  $duration Fade duration in seconds (default: 1)
     * @param string $color    Fade from color (default: black)
     * @return string FFmpeg fade filter
     */
    public static function preset_fade_in( $duration = 1.0, $color = 'black' ) {
        return sprintf( "fade=t=in:st=0:d=%.2f:c=%s", $duration, $color );
    }
    
    /**
     * Generate fade out effect at video end
     * 
     * @param float  $video_duration Total video duration
     * @param float  $fade_duration  Fade duration in seconds (default: 1)
     * @param string $color          Fade to color (default: black)
     * @return string FFmpeg fade filter
     */
    public static function preset_fade_out( $video_duration, $fade_duration = 1.0, $color = 'black' ) {
        $start = $video_duration - $fade_duration;
        return sprintf( "fade=t=out:st=%.2f:d=%.2f:c=%s", $start, $fade_duration, $color );
    }
    
    /**
     * Generate both fade in and fade out
     * 
     * @param float $video_duration Total video duration
     * @param float $fade_duration  Duration for each fade (default: 1)
     * @return string FFmpeg fade filters
     */
    public static function preset_fade_in_out( $video_duration, $fade_duration = 1.0 ) {
        $fade_in = self::preset_fade_in( $fade_duration );
        $fade_out = self::preset_fade_out( $video_duration, $fade_duration );
        return $fade_in . ',' . $fade_out;
    }
    
    // =========================================================================
    // PRESET: Visual Effects - Film Grain & Texture
    // =========================================================================
    
    /**
     * Add film grain/noise for vintage or cinematic look
     * 
     * @param string $intensity 'light', 'medium', 'heavy' (default: light)
     * @return string FFmpeg noise filter
     */
    public static function preset_film_grain( $intensity = 'light' ) {
        $settings = array(
            'light'  => 'alls=5:allf=t',      // Subtle grain
            'medium' => 'alls=15:allf=t',     // Noticeable grain
            'heavy'  => 'alls=30:allf=t',     // Strong vintage grain
        );
        
        $setting = $settings[ $intensity ] ?? $settings['light'];
        return "noise=$setting";
    }
    
    /**
     * Add film scratch lines for vintage effect
     * 
     * @return string FFmpeg filter for scratch lines
     */
    public static function preset_film_scratches() {
        // Simulates old film scratches using geq
        return "geq=lum='lum(X,Y)+random(1)*15':cb='cb(X,Y)':cr='cr(X,Y)'";
    }
    
    // =========================================================================
    // PRESET: Visual Effects - Blur & Sharpen
    // =========================================================================
    
    /**
     * Apply gaussian blur
     * 
     * @param float $sigma Blur strength (default: 2)
     * @return string FFmpeg gblur filter
     */
    public static function preset_blur( $sigma = 2.0 ) {
        return sprintf( "gblur=sigma=%.1f", $sigma );
    }
    
    /**
     * Apply unsharp mask for sharpening
     * 
     * @param string $intensity 'light', 'medium', 'strong' (default: light)
     * @return string FFmpeg unsharp filter
     */
    public static function preset_sharpen( $intensity = 'light' ) {
        $settings = array(
            'light'  => '5:5:0.5:5:5:0',
            'medium' => '5:5:1.0:5:5:0',
            'strong' => '5:5:1.5:5:5:0',
        );
        
        $setting = $settings[ $intensity ] ?? $settings['light'];
        return "unsharp=$setting";
    }
    
    // =========================================================================
    // PRESET: Visual Effects - Speed
    // =========================================================================
    
    /**
     * Change video speed (slow motion or time-lapse)
     * 
     * @param float $speed Speed multiplier: 0.5 = half speed, 2.0 = double speed
     * @return string FFmpeg setpts filter
     */
    public static function preset_speed( $speed = 1.0 ) {
        if ( $speed <= 0 ) {
            $speed = 1.0;
        }
        
        $pts = 1.0 / $speed;
        return sprintf( "setpts=%.4f*PTS", $pts );
    }
    
    /**
     * Slow motion preset
     */
    public static function preset_slow_motion( $factor = 2 ) {
        return self::preset_speed( 1 / $factor );
    }
    
    /**
     * Time-lapse preset
     */
    public static function preset_timelapse( $factor = 4 ) {
        return self::preset_speed( $factor );
    }
    
    // =========================================================================
    // PRESET: Visual Effects - Overlay & Blend
    // =========================================================================
    
    /**
     * Add letterbox (cinematic black bars)
     * 
     * @param int   $width        Video width
     * @param int   $height       Video height
     * @param float $aspect_ratio Target aspect ratio (default: 2.35 for cinemascope)
     * @return string FFmpeg pad filter
     */
    public static function preset_letterbox( $width, $height, $aspect_ratio = 2.35 ) {
        $new_height = (int) ( $width / $aspect_ratio );
        $padding = (int) ( ( $height - $new_height ) / 2 );
        
        if ( $padding <= 0 ) {
            return ''; // Already within ratio
        }
        
        return sprintf(
            "drawbox=x=0:y=0:w=%d:h=%d:c=black:t=fill,drawbox=x=0:y=%d:w=%d:h=%d:c=black:t=fill",
            $width,
            $padding,
            $height - $padding,
            $width,
            $padding + 1
        );
    }
    
    /**
     * Combined preset for professional video look
     * 
     * @param string $style       'minimal', 'cinematic', 'vintage', 'modern' (default: cinematic)
     * @param float  $duration    Video duration for fades
     * @return string Combined FFmpeg filters
     */
    public static function preset_professional( $style = 'cinematic', $duration = 10 ) {
        $filters = array();
        
        switch ( $style ) {
            case 'minimal':
                // Clean, simple look
                $filters[] = self::preset_color_grade( 'desaturated' );
                $filters[] = self::preset_fade_in( 0.5 );
                if ( $duration > 2 ) {
                    $filters[] = self::preset_fade_out( $duration, 0.5 );
                }
                break;
                
            case 'vintage':
                // Retro film look
                $filters[] = self::preset_color_grade( 'vintage' );
                $filters[] = self::preset_vignette_subtle();
                $filters[] = self::preset_film_grain( 'medium' );
                $filters[] = self::preset_fade_in( 1 );
                if ( $duration > 3 ) {
                    $filters[] = self::preset_fade_out( $duration, 1 );
                }
                break;
                
            case 'modern':
                // Clean, vibrant, social media ready
                $filters[] = self::preset_color_grade( 'vibrant' );
                $filters[] = self::preset_sharpen( 'light' );
                $filters[] = self::preset_fade_in( 0.3 );
                if ( $duration > 2 ) {
                    $filters[] = self::preset_fade_out( $duration, 0.3 );
                }
                break;
                
            case 'cinematic':
            default:
                // Film-like professional look
                $filters[] = self::preset_color_grade( 'cinematic' );
                $filters[] = self::preset_vignette_subtle();
                $filters[] = self::preset_fade_in( 1 );
                if ( $duration > 3 ) {
                    $filters[] = self::preset_fade_out( $duration, 1 );
                }
                break;
        }
        
        return implode( ',', array_filter( $filters ) );
    }
    
    /**
     * Get all available professional styles
     */
    public static function get_professional_styles() {
        return array(
            'minimal'   => 'Minimal / Tối giản - Sạch sẽ, đơn giản',
            'cinematic' => 'Cinematic / Điện ảnh - Chuyên nghiệp kiểu phim',
            'vintage'   => 'Vintage / Cổ điển - Phong cách phim cũ',
            'modern'    => 'Modern / Hiện đại - Rực rỡ cho mạng xã hội',
        );
    }
    
    // =========================================================================
    // PRESET: Scale & Crop
    // =========================================================================
    
    /**
     * Generate scale filter to target dimensions
     * 
     * @param int    $width    Target width
     * @param int    $height   Target height
     * @param string $mode     'fit', 'fill', 'stretch' (default: fill)
     * @return string FFmpeg scale filter
     */
    public static function preset_scale( $width, $height, $mode = 'fill' ) {
        switch ( $mode ) {
            case 'fit':
                // Fit inside dimensions, may have letterboxing
                return sprintf(
                    "scale=%d:%d:force_original_aspect_ratio=decrease,pad=%d:%d:(ow-iw)/2:(oh-ih)/2",
                    $width,
                    $height,
                    $width,
                    $height
                );
            
            case 'stretch':
                // Stretch to exact dimensions
                return sprintf( "scale=%d:%d", $width, $height );
            
            case 'fill':
            default:
                // Fill dimensions, crop excess
                return sprintf(
                    "scale=%d:%d:force_original_aspect_ratio=increase,crop=%d:%d",
                    $width,
                    $height,
                    $width,
                    $height
                );
        }
    }
    
    /**
     * Standard aspect ratio presets
     */
    public static function preset_scale_9_16( $base_width = 1080 ) {
        $height = (int) ( $base_width * 16 / 9 );
        return self::preset_scale( $base_width, $height, 'fill' );
    }
    
    public static function preset_scale_16_9( $base_width = 1920 ) {
        $height = (int) ( $base_width * 9 / 16 );
        return self::preset_scale( $base_width, $height, 'fill' );
    }
    
    public static function preset_scale_1_1( $size = 1080 ) {
        return self::preset_scale( $size, $size, 'fill' );
    }
    
    // =========================================================================
    // AUDIO MERGE
    // =========================================================================
    
    /**
     * Build FFmpeg command to merge video with audio
     * 
     * @param string $video_path   Input video path
     * @param string $audio_path   Input audio path
     * @param string $output_path  Output video path
     * @param array  $options      Additional options
     * @return string FFmpeg command
     */
    public static function build_merge_audio_command( $video_path, $audio_path, $output_path, $options = array() ) {
        $defaults = array(
            'audio_volume'     => 1.0,
            'video_volume'     => 0.0, // Mute original video audio by default
            'shortest'         => true,
            'audio_codec'      => 'aac',
            'video_codec'      => 'copy', // Copy video stream by default
            'additional_filters' => '',
        );
        
        $opts = wp_parse_args( $options, $defaults );
        $ffmpeg = self::get_ffmpeg_path();
        
        // Build filter complex for audio mixing
        $filter_parts = array();
        
        // Audio stream processing
        if ( $opts['video_volume'] > 0 ) {
            // Mix original video audio with new audio
            $filter_parts[] = sprintf(
                "[0:a]volume=%.2f[va];[1:a]volume=%.2f[aa];[va][aa]amix=inputs=2:duration=shortest[aout]",
                $opts['video_volume'],
                $opts['audio_volume']
            );
            $audio_map = '-map "[aout]"';
        } else {
            // Replace original audio entirely
            $filter_parts[] = sprintf(
                "[1:a]volume=%.2f[aout]",
                $opts['audio_volume']
            );
            $audio_map = '-map "[aout]"';
        }
        
        // Add any additional video filters
        if ( ! empty( $opts['additional_filters'] ) ) {
            $filter_parts[] = sprintf( "[0:v]%s[vout]", $opts['additional_filters'] );
            $video_map = '-map "[vout]"';
            $video_codec = '-c:v libx264 -preset fast';
        } else {
            $video_map = '-map 0:v';
            $video_codec = '-c:v ' . $opts['video_codec'];
        }
        
        $filter_complex = implode( ';', $filter_parts );
        $shortest = $opts['shortest'] ? '-shortest' : '';
        
        $cmd = sprintf(
            '%s -y -i %s -i %s -filter_complex "%s" %s %s %s -c:a %s %s %s',
            $ffmpeg,
            escapeshellarg( $video_path ),
            escapeshellarg( $audio_path ),
            $filter_complex,
            $video_map,
            $audio_map,
            $video_codec,
            $opts['audio_codec'],
            $shortest,
            escapeshellarg( $output_path )
        );
        
        return $cmd;
    }
    
    /**
     * Build complete FFmpeg command with all presets
     * 
     * @param string $video_path   Input video path
     * @param string $output_path  Output video path
     * @param array  $presets      Array of preset configurations
     * @return string FFmpeg command
     */
    public static function build_command_with_presets( $video_path, $output_path, $presets = array() ) {
        $ffmpeg = self::get_ffmpeg_path();
        $filters = array();
        $inputs = array( escapeshellarg( $video_path ) );
        
        // Audio input if provided
        $audio_path = $presets['audio_path'] ?? null;
        if ( $audio_path ) {
            $inputs[] = escapeshellarg( $audio_path );
        }
        
        // Collect filters from presets
        if ( ! empty( $presets['scale'] ) ) {
            $filters[] = $presets['scale'];
        }
        
        if ( ! empty( $presets['zoom'] ) ) {
            $filters[] = $presets['zoom'];
        }
        
        if ( ! empty( $presets['lower_third'] ) ) {
            $filters[] = $presets['lower_third'];
        }
        
        if ( ! empty( $presets['subtitles'] ) ) {
            $filters[] = $presets['subtitles'];
        }
        
        if ( ! empty( $presets['custom_filters'] ) ) {
            $filters[] = $presets['custom_filters'];
        }
        
        // Build command
        $input_args = implode( ' -i ', $inputs );
        $filter_arg = ! empty( $filters ) ? '-vf "' . implode( ',', $filters ) . '"' : '';
        
        // Audio handling
        $audio_args = '';
        if ( $audio_path ) {
            $audio_args = '-map 0:v -map 1:a -c:a aac -shortest';
        }
        
        $cmd = sprintf(
            '%s -y -i %s %s %s -c:v libx264 -preset fast -crf 23 %s',
            $ffmpeg,
            $input_args,
            $filter_arg,
            $audio_args,
            escapeshellarg( $output_path )
        );
        
        return $cmd;
    }
    
    // =========================================================================
    // EXECUTION HELPERS
    // =========================================================================
    
    /**
     * Execute FFmpeg command
     * 
     * @param string $command FFmpeg command
     * @param bool   $async   Run asynchronously (default: false)
     * @return array Result with success, output, error
     */
    public static function execute( $command, $async = false ) {
        // Log command
        error_log( '[BizCity-FFmpeg] Executing: ' . $command );
        
        // Check which exec function is available
        $disabled_functions = array_map( 'trim', explode( ',', ini_get( 'disable_functions' ) ) );
        $exec_available = ! in_array( 'exec', $disabled_functions );
        $shell_exec_available = ! in_array( 'shell_exec', $disabled_functions );
        $proc_open_available = ! in_array( 'proc_open', $disabled_functions );
        
        if ( $async ) {
            // Run in background
            if ( strtoupper( substr( PHP_OS, 0, 3 ) ) === 'WIN' ) {
                pclose( popen( 'start /B ' . $command, 'r' ) );
            } else {
                if ( $shell_exec_available ) {
                    @shell_exec( $command . ' > /dev/null 2>&1 &' );
                } elseif ( $exec_available ) {
                    @exec( $command . ' > /dev/null 2>&1 &' );
                }
            }
            return array( 'success' => true, 'async' => true );
        }
        
        // Run synchronously - try shell_exec first (most compatible)
        if ( $shell_exec_available ) {
            $output_str = @shell_exec( $command . ' 2>&1' );
            
            if ( $output_str !== null ) {
                // Check for common FFmpeg success indicators
                $is_error = stripos( $output_str, 'error' ) !== false && 
                           stripos( $output_str, 'muxing overhead' ) === false;
                
                if ( $is_error && stripos( $output_str, 'No such file' ) !== false ) {
                    error_log( '[BizCity-FFmpeg] Error: ' . $output_str );
                    return array(
                        'success' => false,
                        'error'   => $output_str,
                        'code'    => 1,
                    );
                }
                
                return array(
                    'success' => true,
                    'output'  => $output_str,
                );
            }
        }
        
        // Fallback to proc_open
        if ( $proc_open_available ) {
            $result = self::execute_proc_open( $command );
            if ( $result !== null ) {
                return $result;
            }
        }
        
        // Last resort: exec
        if ( $exec_available ) {
            $output = array();
            $return_var = 0;
            
            @exec( $command . ' 2>&1', $output, $return_var );
            
            $output_str = implode( "\n", $output );
            
            if ( $return_var !== 0 ) {
                error_log( '[BizCity-FFmpeg] Error: ' . $output_str );
                return array(
                    'success' => false,
                    'error'   => $output_str,
                    'code'    => $return_var,
                );
            }
            
            return array(
                'success' => true,
                'output'  => $output_str,
            );
        }
        
        return array(
            'success' => false,
            'error'   => 'No PHP execution functions available (exec, shell_exec, proc_open all disabled)',
            'code'    => -1,
        );
    }
    
    /**
     * Execute command using proc_open
     */
    private static function execute_proc_open( $command ) {
        $descriptorspec = array(
            0 => array( 'pipe', 'r' ),  // stdin
            1 => array( 'pipe', 'w' ),  // stdout
            2 => array( 'pipe', 'w' ),  // stderr
        );
        
        $process = @proc_open( $command, $descriptorspec, $pipes );
        
        if ( is_resource( $process ) ) {
            fclose( $pipes[0] );
            
            $stdout = stream_get_contents( $pipes[1] );
            $stderr = stream_get_contents( $pipes[2] );
            
            fclose( $pipes[1] );
            fclose( $pipes[2] );
            
            $return_code = proc_close( $process );
            
            $output = ! empty( $stdout ) ? $stdout : $stderr;
            
            if ( $return_code !== 0 ) {
                error_log( '[BizCity-FFmpeg] Error: ' . $output );
                return array(
                    'success' => false,
                    'error'   => $output,
                    'code'    => $return_code,
                );
            }
            
            return array(
                'success' => true,
                'output'  => $output,
            );
        }
        
        return null;
    }
    
    /**
     * Merge video with audio (simplified helper)
     * 
     * @param string $video_path  Input video file path
     * @param string $audio_path  Input audio file path  
     * @param string $output_path Output video file path
     * @param array  $options     Additional options (filters, etc.)
     * @return array Result
     */
    public static function merge_video_audio( $video_path, $audio_path, $output_path, $options = array() ) {
        // Verify input files exist
        if ( ! file_exists( $video_path ) ) {
            return array( 'success' => false, 'error' => 'Video file not found: ' . $video_path );
        }
        
        if ( ! file_exists( $audio_path ) ) {
            return array( 'success' => false, 'error' => 'Audio file not found: ' . $audio_path );
        }
        
        // Build and execute command
        $cmd = self::build_merge_audio_command( $video_path, $audio_path, $output_path, $options );
        
        return self::execute( $cmd );
    }
    
    /**
     * Get video duration using ffprobe
     * 
     * @param string $video_path Path to video file
     * @return float Duration in seconds, 0 on error
     */
    public static function get_video_duration( $video_path ) {
        $ffprobe = str_replace( 'ffmpeg', 'ffprobe', self::get_ffmpeg_path() );
        
        $cmd = sprintf(
            '%s -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 %s',
            $ffprobe,
            escapeshellarg( $video_path )
        );
        
        $output = trim( shell_exec( $cmd ) );
        
        return is_numeric( $output ) ? (float) $output : 0;
    }
    
    /**
     * Concatenate multiple videos into one
     * 
     * Uses FFmpeg concat demuxer for lossless concatenation
     * Videos must have same resolution, codec, frame rate
     * 
     * @param array  $video_paths Array of video file paths (in order)
     * @param string $output_path Output video path
     * @param array  $options     Options:
     *                            - 'reencode' => bool (true = re-encode for compatibility)
     *                            - 'scale' => '1080:1920' (scale all to same resolution)
     *                            - 'fps' => 30 (normalize frame rate)
     * @return array Result with success status
     */
    public static function concat_videos( $video_paths, $output_path, $options = array() ) {
        if ( empty( $video_paths ) || count( $video_paths ) < 2 ) {
            return array( 'success' => false, 'error' => 'Cần ít nhất 2 video để ghép' );
        }
        
        // Verify all input files exist
        foreach ( $video_paths as $path ) {
            if ( ! file_exists( $path ) ) {
                return array( 'success' => false, 'error' => 'File không tồn tại: ' . $path );
            }
        }
        
        $reencode = $options['reencode'] ?? false;
        $scale = $options['scale'] ?? '';
        $fps = $options['fps'] ?? 0;
        
        // Force re-encode if scale or fps specified
        if ( ! empty( $scale ) || $fps > 0 ) {
            $reencode = true;
        }
        
        $ffmpeg = self::get_ffmpeg_path();
        
        if ( $reencode ) {
            // Re-encode method: more compatible but slower
            $cmd = self::build_concat_reencode_command( $video_paths, $output_path, $options );
        } else {
            // Concat demuxer: fast but requires same codec/resolution
            $cmd = self::build_concat_demux_command( $video_paths, $output_path );
        }
        
        return self::execute( $cmd );
    }
    
    /**
     * Build concat command using re-encode (filter_complex)
     * More compatible, handles different resolutions/codecs
     * 
     * @param array  $video_paths Video paths
     * @param string $output_path Output path
     * @param array  $options     Scale, fps options
     * @return string FFmpeg command
     */
    private static function build_concat_reencode_command( $video_paths, $output_path, $options = array() ) {
        $ffmpeg = self::get_ffmpeg_path();
        $scale = $options['scale'] ?? '1080:1920';
        $fps = $options['fps'] ?? 30;
        
        $inputs = '';
        $filter = '';
        $count = count( $video_paths );
        
        // Build inputs
        foreach ( $video_paths as $i => $path ) {
            $inputs .= ' -i ' . escapeshellarg( $path );
            // Scale and set fps for each input
            $filter .= sprintf(
                "[%d:v]scale=%s:force_original_aspect_ratio=decrease,pad=%s:(ow-iw)/2:(oh-ih)/2,fps=%d,setsar=1[v%d];",
                $i, $scale, $scale, $fps, $i
            );
        }
        
        // Concat all streams
        for ( $i = 0; $i < $count; $i++ ) {
            $filter .= "[v{$i}]";
        }
        $filter .= "concat=n={$count}:v=1:a=0[outv]";
        
        return sprintf(
            '%s -y%s -filter_complex "%s" -map "[outv]" -c:v libx264 -preset medium -crf 23 %s',
            $ffmpeg,
            $inputs,
            $filter,
            escapeshellarg( $output_path )
        );
    }
    
    /**
     * Build concat command using demuxer (lossless, same codec required)
     * 
     * @param array  $video_paths Video paths
     * @param string $output_path Output path
     * @return string FFmpeg command
     */
    private static function build_concat_demux_command( $video_paths, $output_path ) {
        $ffmpeg = self::get_ffmpeg_path();
        
        // Create temporary concat file
        $concat_file = sys_get_temp_dir() . '/ffmpeg_concat_' . uniqid() . '.txt';
        $content = '';
        
        foreach ( $video_paths as $path ) {
            // Escape single quotes and backslashes
            $escaped = str_replace( "'", "'\\''", $path );
            $escaped = str_replace( '\\', '/', $escaped );
            $content .= "file '" . $escaped . "'\n";
        }
        
        file_put_contents( $concat_file, $content );
        
        $cmd = sprintf(
            '%s -y -f concat -safe 0 -i %s -c copy %s',
            $ffmpeg,
            escapeshellarg( $concat_file ),
            escapeshellarg( $output_path )
        );
        
        // Note: concat file will be left in temp dir, cleaned by OS
        return $cmd;
    }
    
    /**
     * Concatenate videos and add audio track
     * 
     * Perfect for: Ghép nhiều video Kling + thêm TTS audio
     * 
     * @param array  $video_paths Array of video paths to concat
     * @param string $audio_path  Audio file to add
     * @param string $output_path Final output path
     * @param array  $options     Options: scale, fps, audio_volume, etc.
     * @return array Result with success status
     */
    public static function concat_videos_with_audio( $video_paths, $audio_path, $output_path, $options = array() ) {
        // First concat videos
        $temp_video = sys_get_temp_dir() . '/concat_temp_' . uniqid() . '.mp4';
        
        $concat_result = self::concat_videos( $video_paths, $temp_video, array(
            'reencode' => true,
            'scale'    => $options['scale'] ?? '1080:1920',
            'fps'      => $options['fps'] ?? 30,
        ) );
        
        if ( ! $concat_result['success'] ) {
            return $concat_result;
        }
        
        // Then add audio
        $merge_result = self::merge_video_audio( $temp_video, $audio_path, $output_path, array(
            'loop_video'    => $options['loop_video'] ?? false,
            'audio_volume'  => $options['audio_volume'] ?? 1.0,
            'preset'        => $options['preset'] ?? '',
            'preset_config' => $options['preset_config'] ?? array(),
        ) );
        
        // Clean up temp file
        @unlink( $temp_video );
        
        return $merge_result;
    }
    
    /**
     * Calculate number of videos needed for target duration
     * 
     * @param int $target_duration Target duration in seconds
     * @param int $segment_duration Duration per segment (default 10s for Kling standard)
     * @return array Array of durations for each video segment
     */
    public static function calculate_segments( $target_duration, $segment_duration = 10 ) {
        if ( $target_duration <= $segment_duration ) {
            return array( $target_duration );
        }
        
        $segments = array();
        $remaining = $target_duration;
        
        while ( $remaining > 0 ) {
            if ( $remaining > $segment_duration ) {
                $segments[] = $segment_duration;
                $remaining -= $segment_duration;
            } else {
                // Last segment: use 5s if <= 5s, otherwise use the exact remaining
                // Kling supports 5s and 10s standard durations
                $segments[] = $remaining <= 5 ? 5 : 10;
                $remaining = 0;
            }
        }
        
        return $segments;
    }
}
