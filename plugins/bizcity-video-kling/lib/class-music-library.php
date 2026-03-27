<?php
/**
 * Music Library for Background Audio
 * 
 * Provides categorized background music tracks organized by:
 * - Duration: 10s, 15s, 20s, 30s, 45s
 * - Mood: happy, sad, energetic, calm, dramatic, inspirational, etc.
 * 
 * This helps AI/automation select appropriate music based on user prompts like:
 * "I want a 30s video with happy music"
 * 
 * @package BizCity_Video_Kling
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BizCity_Video_Kling_Music_Library {
    
    /**
     * Duration presets in seconds
     */
    const DURATIONS = array( 10, 15, 20, 30, 45 );
    
    /**
     * Available mood categories with descriptions for AI/automation
     */
    const MOODS = array(
        'happy' => array(
            'name'        => 'Happy / Vui vẻ',
            'description' => 'Upbeat, cheerful, positive vibes. Good for product showcases, celebrations, fun content.',
            'keywords'    => array( 'vui', 'happy', 'cheerful', 'positive', 'fun', 'celebrate' ),
        ),
        'energetic' => array(
            'name'        => 'Energetic / Năng động',
            'description' => 'Fast-paced, dynamic, exciting. Good for sports, action, fitness, technology.',
            'keywords'    => array( 'năng động', 'energetic', 'fast', 'action', 'sport', 'fitness', 'tech' ),
        ),
        'calm' => array(
            'name'        => 'Calm / Nhẹ nhàng',
            'description' => 'Peaceful, relaxing, gentle. Good for wellness, nature, meditation, spa.',
            'keywords'    => array( 'nhẹ nhàng', 'calm', 'peaceful', 'relax', 'gentle', 'nature', 'spa' ),
        ),
        'dramatic' => array(
            'name'        => 'Dramatic / Kịch tính',
            'description' => 'Intense, cinematic, powerful. Good for trailers, reveals, storytelling.',
            'keywords'    => array( 'kịch tính', 'dramatic', 'cinematic', 'epic', 'powerful', 'trailer' ),
        ),
        'inspirational' => array(
            'name'        => 'Inspirational / Truyền cảm hứng',
            'description' => 'Motivating, uplifting, hopeful. Good for testimonials, achievements, success stories.',
            'keywords'    => array( 'cảm hứng', 'inspirational', 'motivating', 'hopeful', 'success' ),
        ),
        'corporate' => array(
            'name'        => 'Corporate / Doanh nghiệp',
            'description' => 'Professional, clean, modern. Good for business, presentations, explainers.',
            'keywords'    => array( 'doanh nghiệp', 'corporate', 'business', 'professional', 'clean' ),
        ),
        'romantic' => array(
            'name'        => 'Romantic / Lãng mạn',
            'description' => 'Soft, emotional, tender. Good for weddings, love stories, beauty.',
            'keywords'    => array( 'lãng mạn', 'romantic', 'love', 'soft', 'emotional', 'wedding', 'beauty' ),
        ),
        'funny' => array(
            'name'        => 'Funny / Hài hước',
            'description' => 'Playful, quirky, comical. Good for memes, comedy, kids content.',
            'keywords'    => array( 'hài hước', 'funny', 'playful', 'quirky', 'comedy', 'kids' ),
        ),
    );
    
    /**
     * Music library - organized by duration then mood
     * Each entry has: file, title, description, bpm, genre
     * 
     * Files should be placed in: wp-content/uploads/bizcity-kling-music/{duration}s/{mood}/
     * Or use external URLs from free music sources
     */
    private static $library = null;
    
    /**
     * Get music library base directory
     */
    public static function get_base_dir() {
        $upload_dir = wp_upload_dir();
        return $upload_dir['basedir'] . '/bizcity-kling-music/';
    }
    
    /**
     * Get music library base URL
     */
    public static function get_base_url() {
        $upload_dir = wp_upload_dir();
        return $upload_dir['baseurl'] . '/bizcity-kling-music/';
    }
    
    /**
     * Initialize music library
     * Can be extended via filter: bizcity_kling_music_library
     */
    public static function get_library() {
        if ( self::$library !== null ) {
            return self::$library;
        }
        
        // Default library with free music from Pixabay (royalty-free)
        // These are example URLs - should be replaced with actual hosted files
        $default_library = array(
            // =====================================================
            // 10 SECONDS
            // =====================================================
            10 => array(
                'happy' => array(
                    array(
                        'id'          => 'happy-10-01',
                        'title'       => 'Quick Joy',
                        'description' => 'Short upbeat jingle, perfect for product reveals',
                        'bpm'         => 120,
                        'genre'       => 'Pop',
                    ),
                    array(
                        'id'          => 'happy-10-02',
                        'title'       => 'Bright Start',
                        'description' => 'Cheerful acoustic intro',
                        'bpm'         => 110,
                        'genre'       => 'Acoustic',
                    ),
                ),
                'energetic' => array(
                    array(
                        'id'          => 'energetic-10-01',
                        'title'       => 'Power Burst',
                        'description' => 'Dynamic electronic burst',
                        'bpm'         => 140,
                        'genre'       => 'Electronic',
                    ),
                ),
                'calm' => array(
                    array(
                        'id'          => 'calm-10-01',
                        'title'       => 'Gentle Wave',
                        'description' => 'Peaceful ambient intro',
                        'bpm'         => 70,
                        'genre'       => 'Ambient',
                    ),
                ),
                'dramatic' => array(
                    array(
                        'id'          => 'dramatic-10-01',
                        'title'       => 'Epic Reveal',
                        'description' => 'Cinematic impact hit',
                        'bpm'         => 90,
                        'genre'       => 'Cinematic',
                    ),
                ),
                'corporate' => array(
                    array(
                        'id'          => 'corporate-10-01',
                        'title'       => 'Tech Intro',
                        'description' => 'Clean modern sting',
                        'bpm'         => 100,
                        'genre'       => 'Corporate',
                    ),
                ),
            ),
            
            // =====================================================
            // 15 SECONDS
            // =====================================================
            15 => array(
                'happy' => array(
                    array(
                        'id'          => 'happy-15-01',
                        'title'       => 'Sunny Vibes',
                        'description' => 'Uplifting ukulele tune',
                        'bpm'         => 115,
                        'genre'       => 'Acoustic',
                    ),
                    array(
                        'id'          => 'happy-15-02',
                        'title'       => 'Feel Good Pop',
                        'description' => 'Catchy pop melody',
                        'bpm'         => 125,
                        'genre'       => 'Pop',
                    ),
                ),
                'energetic' => array(
                    array(
                        'id'          => 'energetic-15-01',
                        'title'       => 'Adrenaline Rush',
                        'description' => 'High energy EDM drop',
                        'bpm'         => 145,
                        'genre'       => 'EDM',
                    ),
                ),
                'calm' => array(
                    array(
                        'id'          => 'calm-15-01',
                        'title'       => 'Morning Dew',
                        'description' => 'Soft piano melody',
                        'bpm'         => 65,
                        'genre'       => 'Piano',
                    ),
                ),
                'inspirational' => array(
                    array(
                        'id'          => 'inspirational-15-01',
                        'title'       => 'Rising Up',
                        'description' => 'Motivational build-up',
                        'bpm'         => 95,
                        'genre'       => 'Orchestral',
                    ),
                ),
                'corporate' => array(
                    array(
                        'id'          => 'corporate-15-01',
                        'title'       => 'Business Motion',
                        'description' => 'Professional background',
                        'bpm'         => 105,
                        'genre'       => 'Corporate',
                    ),
                ),
            ),
            
            // =====================================================
            // 20 SECONDS
            // =====================================================
            20 => array(
                'happy' => array(
                    array(
                        'id'          => 'happy-20-01',
                        'title'       => 'Celebration Time',
                        'description' => 'Festive party music',
                        'bpm'         => 128,
                        'genre'       => 'Dance',
                    ),
                    array(
                        'id'          => 'happy-20-02',
                        'title'       => 'Summer Days',
                        'description' => 'Carefree tropical vibes',
                        'bpm'         => 110,
                        'genre'       => 'Tropical',
                    ),
                ),
                'energetic' => array(
                    array(
                        'id'          => 'energetic-20-01',
                        'title'       => 'Sports Montage',
                        'description' => 'Action-packed rock energy',
                        'bpm'         => 150,
                        'genre'       => 'Rock',
                    ),
                    array(
                        'id'          => 'energetic-20-02',
                        'title'       => 'Digital Rush',
                        'description' => 'Techy electronic beats',
                        'bpm'         => 138,
                        'genre'       => 'Electronic',
                    ),
                ),
                'calm' => array(
                    array(
                        'id'          => 'calm-20-01',
                        'title'       => 'Zen Garden',
                        'description' => 'Peaceful Asian-inspired',
                        'bpm'         => 60,
                        'genre'       => 'World',
                    ),
                ),
                'dramatic' => array(
                    array(
                        'id'          => 'dramatic-20-01',
                        'title'       => 'Epic Journey',
                        'description' => 'Cinematic orchestral build',
                        'bpm'         => 85,
                        'genre'       => 'Cinematic',
                    ),
                ),
                'romantic' => array(
                    array(
                        'id'          => 'romantic-20-01',
                        'title'       => 'First Love',
                        'description' => 'Soft emotional strings',
                        'bpm'         => 75,
                        'genre'       => 'Orchestral',
                    ),
                ),
                'corporate' => array(
                    array(
                        'id'          => 'corporate-20-01',
                        'title'       => 'Innovation',
                        'description' => 'Modern tech presentation',
                        'bpm'         => 100,
                        'genre'       => 'Corporate',
                    ),
                ),
            ),
            
            // =====================================================
            // 30 SECONDS
            // =====================================================
            30 => array(
                'happy' => array(
                    array(
                        'id'          => 'happy-30-01',
                        'title'       => 'Good Times Roll',
                        'description' => 'Feel-good indie pop anthem',
                        'bpm'         => 118,
                        'genre'       => 'Indie Pop',
                    ),
                    array(
                        'id'          => 'happy-30-02',
                        'title'       => 'Walking on Sunshine',
                        'description' => 'Bright and cheerful groove',
                        'bpm'         => 122,
                        'genre'       => 'Pop',
                    ),
                    array(
                        'id'          => 'happy-30-03',
                        'title'       => 'Beach Party',
                        'description' => 'Fun summer vibes',
                        'bpm'         => 115,
                        'genre'       => 'Tropical House',
                    ),
                ),
                'energetic' => array(
                    array(
                        'id'          => 'energetic-30-01',
                        'title'       => 'Unstoppable',
                        'description' => 'High-octane workout music',
                        'bpm'         => 155,
                        'genre'       => 'EDM',
                    ),
                    array(
                        'id'          => 'energetic-30-02',
                        'title'       => 'Racing Heart',
                        'description' => 'Fast-paced action track',
                        'bpm'         => 148,
                        'genre'       => 'Electronic',
                    ),
                ),
                'calm' => array(
                    array(
                        'id'          => 'calm-30-01',
                        'title'       => 'Ocean Breeze',
                        'description' => 'Relaxing nature sounds with soft melody',
                        'bpm'         => 55,
                        'genre'       => 'Ambient',
                    ),
                    array(
                        'id'          => 'calm-30-02',
                        'title'       => 'Meditation Flow',
                        'description' => 'Gentle healing tones',
                        'bpm'         => 50,
                        'genre'       => 'New Age',
                    ),
                ),
                'dramatic' => array(
                    array(
                        'id'          => 'dramatic-30-01',
                        'title'       => 'Rise of Heroes',
                        'description' => 'Epic orchestral with full build',
                        'bpm'         => 88,
                        'genre'       => 'Cinematic',
                    ),
                    array(
                        'id'          => 'dramatic-30-02',
                        'title'       => 'Dark Discovery',
                        'description' => 'Mysterious tension builder',
                        'bpm'         => 72,
                        'genre'       => 'Cinematic',
                    ),
                ),
                'inspirational' => array(
                    array(
                        'id'          => 'inspirational-30-01',
                        'title'       => 'Dream Achiever',
                        'description' => 'Uplifting motivational anthem',
                        'bpm'         => 100,
                        'genre'       => 'Orchestral Pop',
                    ),
                    array(
                        'id'          => 'inspirational-30-02',
                        'title'       => 'New Horizons',
                        'description' => 'Hopeful piano and strings',
                        'bpm'         => 92,
                        'genre'       => 'Orchestral',
                    ),
                ),
                'corporate' => array(
                    array(
                        'id'          => 'corporate-30-01',
                        'title'       => 'Business Success',
                        'description' => 'Clean and professional',
                        'bpm'         => 108,
                        'genre'       => 'Corporate',
                    ),
                    array(
                        'id'          => 'corporate-30-02',
                        'title'       => 'Tech Forward',
                        'description' => 'Modern technology feel',
                        'bpm'         => 112,
                        'genre'       => 'Electronic Corporate',
                    ),
                ),
                'romantic' => array(
                    array(
                        'id'          => 'romantic-30-01',
                        'title'       => 'Eternal Love',
                        'description' => 'Beautiful emotional piano',
                        'bpm'         => 68,
                        'genre'       => 'Piano',
                    ),
                ),
                'funny' => array(
                    array(
                        'id'          => 'funny-30-01',
                        'title'       => 'Silly Walk',
                        'description' => 'Playful cartoon-style music',
                        'bpm'         => 135,
                        'genre'       => 'Comedy',
                    ),
                ),
            ),
            
            // =====================================================
            // 45 SECONDS
            // =====================================================
            45 => array(
                'happy' => array(
                    array(
                        'id'          => 'happy-45-01',
                        'title'       => 'Life is Beautiful',
                        'description' => 'Extended feel-good journey',
                        'bpm'         => 120,
                        'genre'       => 'Pop',
                    ),
                    array(
                        'id'          => 'happy-45-02',
                        'title'       => 'Carnival Joy',
                        'description' => 'Festive celebration music',
                        'bpm'         => 130,
                        'genre'       => 'Latin Pop',
                    ),
                ),
                'energetic' => array(
                    array(
                        'id'          => 'energetic-45-01',
                        'title'       => 'Maximum Power',
                        'description' => 'Intense action sequence',
                        'bpm'         => 160,
                        'genre'       => 'Dubstep',
                    ),
                    array(
                        'id'          => 'energetic-45-02',
                        'title'       => 'Victory Lap',
                        'description' => 'Triumphant sports anthem',
                        'bpm'         => 145,
                        'genre'       => 'Epic Electronic',
                    ),
                ),
                'calm' => array(
                    array(
                        'id'          => 'calm-45-01',
                        'title'       => 'Forest Walk',
                        'description' => 'Peaceful nature ambient',
                        'bpm'         => 45,
                        'genre'       => 'Ambient',
                    ),
                    array(
                        'id'          => 'calm-45-02',
                        'title'       => 'Spa Retreat',
                        'description' => 'Soothing wellness music',
                        'bpm'         => 52,
                        'genre'       => 'New Age',
                    ),
                ),
                'dramatic' => array(
                    array(
                        'id'          => 'dramatic-45-01',
                        'title'       => 'Final Battle',
                        'description' => 'Epic cinematic orchestral',
                        'bpm'         => 95,
                        'genre'       => 'Cinematic',
                    ),
                    array(
                        'id'          => 'dramatic-45-02',
                        'title'       => 'The Revelation',
                        'description' => 'Intense dramatic build',
                        'bpm'         => 82,
                        'genre'       => 'Trailer',
                    ),
                ),
                'inspirational' => array(
                    array(
                        'id'          => 'inspirational-45-01',
                        'title'       => 'Against All Odds',
                        'description' => 'Full inspirational journey with climax',
                        'bpm'         => 98,
                        'genre'       => 'Orchestral',
                    ),
                    array(
                        'id'          => 'inspirational-45-02',
                        'title'       => 'Breakthrough',
                        'description' => 'Uplifting modern epic',
                        'bpm'         => 105,
                        'genre'       => 'Epic Pop',
                    ),
                ),
                'corporate' => array(
                    array(
                        'id'          => 'corporate-45-01',
                        'title'       => 'Company Vision',
                        'description' => 'Professional extended presentation',
                        'bpm'         => 100,
                        'genre'       => 'Corporate',
                    ),
                ),
                'romantic' => array(
                    array(
                        'id'          => 'romantic-45-01',
                        'title'       => 'Our Story',
                        'description' => 'Emotional love story music',
                        'bpm'         => 70,
                        'genre'       => 'Orchestral',
                    ),
                ),
            ),
        );
        
        // Allow extensions via filter
        self::$library = apply_filters( 'bizcity_kling_music_library', $default_library );
        
        return self::$library;
    }
    
    /**
     * Get available durations
     */
    public static function get_durations() {
        return self::DURATIONS;
    }
    
    /**
     * Get available moods with metadata
     */
    public static function get_moods() {
        return self::MOODS;
    }
    
    /**
     * Get music tracks by duration
     * 
     * @param int $duration Duration in seconds (10, 15, 20, 30, 45)
     * @return array Tracks grouped by mood
     */
    public static function get_by_duration( $duration ) {
        $library = self::get_library();
        
        // Find closest matching duration
        $closest = self::find_closest_duration( $duration );
        
        return $library[ $closest ] ?? array();
    }
    
    /**
     * Get music tracks by mood across all durations
     * 
     * @param string $mood Mood category
     * @return array Tracks grouped by duration
     */
    public static function get_by_mood( $mood ) {
        $library = self::get_library();
        $result = array();
        
        foreach ( $library as $duration => $moods ) {
            if ( isset( $moods[ $mood ] ) ) {
                $result[ $duration ] = $moods[ $mood ];
            }
        }
        
        return $result;
    }
    
    /**
     * Get music tracks by duration and mood
     * 
     * @param int    $duration Duration in seconds
     * @param string $mood     Mood category
     * @return array Matching tracks
     */
    public static function get_tracks( $duration, $mood ) {
        $library = self::get_library();
        $closest = self::find_closest_duration( $duration );
        
        return $library[ $closest ][ $mood ] ?? array();
    }
    
    /**
     * Get a specific track by ID
     * 
     * @param string $track_id Track ID (e.g., 'happy-30-01')
     * @return array|null Track data or null if not found
     */
    public static function get_track( $track_id ) {
        $library = self::get_library();
        
        foreach ( $library as $duration => $moods ) {
            foreach ( $moods as $mood => $tracks ) {
                foreach ( $tracks as $track ) {
                    if ( $track['id'] === $track_id ) {
                        return array_merge( $track, array(
                            'duration' => $duration,
                            'mood'     => $mood,
                        ) );
                    }
                }
            }
        }
        
        return null;
    }
    
    /**
     * Get file path for a track
     * 
     * @param string|array $track Track ID or track array
     * @return string|null File path or null if not found
     */
    public static function get_track_path( $track ) {
        if ( is_string( $track ) ) {
            $track = self::get_track( $track );
        }
        
        if ( ! $track || ! isset( $track['duration'], $track['mood'], $track['id'] ) ) {
            return null;
        }
        
        $base_dir = self::get_base_dir();
        $path = sprintf( '%s%ds/%s/%s.mp3', $base_dir, $track['duration'], $track['mood'], $track['id'] );
        
        if ( file_exists( $path ) ) {
            return $path;
        }
        
        // Try alternative location
        $alt_path = sprintf( '%s%ds/%s.mp3', $base_dir, $track['duration'], $track['id'] );
        if ( file_exists( $alt_path ) ) {
            return $alt_path;
        }
        
        return null;
    }
    
    /**
     * Get file URL for a track
     */
    public static function get_track_url( $track ) {
        if ( is_string( $track ) ) {
            $track = self::get_track( $track );
        }
        
        if ( ! $track || ! isset( $track['duration'], $track['mood'], $track['id'] ) ) {
            return null;
        }
        
        $base_url = self::get_base_url();
        return sprintf( '%s%ds/%s/%s.mp3', $base_url, $track['duration'], $track['mood'], $track['id'] );
    }
    
    /**
     * Find closest matching duration from available presets
     */
    public static function find_closest_duration( $duration ) {
        $duration = (int) $duration;
        $closest = 10;
        $min_diff = PHP_INT_MAX;
        
        foreach ( self::DURATIONS as $preset_duration ) {
            $diff = abs( $preset_duration - $duration );
            if ( $diff < $min_diff ) {
                $min_diff = $diff;
                $closest = $preset_duration;
            }
        }
        
        return $closest;
    }
    
    /**
     * Suggest music based on text prompt (for AI/automation)
     * 
     * @param string $prompt     User prompt text
     * @param int    $duration   Video duration
     * @return array Suggested tracks with scores
     */
    public static function suggest_from_prompt( $prompt, $duration = 30 ) {
        $prompt_lower = mb_strtolower( $prompt );
        $suggestions = array();
        
        $closest_duration = self::find_closest_duration( $duration );
        $tracks_by_duration = self::get_by_duration( $closest_duration );
        
        foreach ( self::MOODS as $mood_key => $mood_data ) {
            $score = 0;
            
            // Check keywords in prompt
            foreach ( $mood_data['keywords'] as $keyword ) {
                if ( mb_strpos( $prompt_lower, mb_strtolower( $keyword ) ) !== false ) {
                    $score += 10;
                }
            }
            
            // Add tracks with scores
            if ( isset( $tracks_by_duration[ $mood_key ] ) ) {
                foreach ( $tracks_by_duration[ $mood_key ] as $track ) {
                    $suggestions[] = array_merge( $track, array(
                        'mood'     => $mood_key,
                        'mood_name' => $mood_data['name'],
                        'duration' => $closest_duration,
                        'score'    => $score,
                    ) );
                }
            }
        }
        
        // Sort by score descending
        usort( $suggestions, function( $a, $b ) {
            return $b['score'] - $a['score'];
        } );
        
        return $suggestions;
    }
    
    /**
     * Get options for select dropdown
     * Returns grouped options by duration then mood
     */
    public static function get_select_options( $selected_track_id = '' ) {
        $library = self::get_library();
        $options = array();
        
        foreach ( $library as $duration => $moods ) {
            $duration_label = sprintf( '%ds', $duration );
            $options[ $duration_label ] = array();
            
            foreach ( $moods as $mood_key => $tracks ) {
                $mood_name = self::MOODS[ $mood_key ]['name'] ?? ucfirst( $mood_key );
                
                foreach ( $tracks as $track ) {
                    $options[ $duration_label ][] = array(
                        'value'    => $track['id'],
                        'label'    => sprintf( '[%s] %s', $mood_name, $track['title'] ),
                        'selected' => $track['id'] === $selected_track_id,
                        'data'     => array(
                            'mood'   => $mood_key,
                            'bpm'    => $track['bpm'],
                            'genre'  => $track['genre'],
                            'description' => $track['description'],
                        ),
                    );
                }
            }
        }
        
        return $options;
    }
    
    /**
     * Render select dropdown HTML
     */
    public static function render_select( $name = 'background_music', $selected = '', $attributes = array() ) {
        $options = self::get_select_options( $selected );
        
        $attrs = array_merge( array(
            'id'    => $name,
            'name'  => $name,
            'class' => 'widefat',
        ), $attributes );
        
        $attrs_str = '';
        foreach ( $attrs as $key => $value ) {
            $attrs_str .= sprintf( ' %s="%s"', esc_attr( $key ), esc_attr( $value ) );
        }
        
        $html = sprintf( '<select%s>', $attrs_str );
        $html .= '<option value="">' . __( '-- No background music --', 'bizcity-video-kling' ) . '</option>';
        
        foreach ( $options as $group_label => $group_options ) {
            $html .= sprintf( '<optgroup label="%s">', esc_attr( $group_label ) );
            
            foreach ( $group_options as $option ) {
                $data_attrs = '';
                if ( ! empty( $option['data'] ) ) {
                    foreach ( $option['data'] as $key => $value ) {
                        $data_attrs .= sprintf( ' data-%s="%s"', esc_attr( $key ), esc_attr( $value ) );
                    }
                }
                
                $html .= sprintf(
                    '<option value="%s"%s%s>%s</option>',
                    esc_attr( $option['value'] ),
                    $option['selected'] ? ' selected' : '',
                    $data_attrs,
                    esc_html( $option['label'] )
                );
            }
            
            $html .= '</optgroup>';
        }
        
        $html .= '</select>';
        
        return $html;
    }
    
    /**
     * Create directory structure for music library
     */
    public static function create_directories() {
        $base_dir = self::get_base_dir();
        
        if ( ! file_exists( $base_dir ) ) {
            wp_mkdir_p( $base_dir );
        }
        
        foreach ( self::DURATIONS as $duration ) {
            $duration_dir = $base_dir . $duration . 's/';
            
            if ( ! file_exists( $duration_dir ) ) {
                wp_mkdir_p( $duration_dir );
            }
            
            foreach ( array_keys( self::MOODS ) as $mood ) {
                $mood_dir = $duration_dir . $mood . '/';
                
                if ( ! file_exists( $mood_dir ) ) {
                    wp_mkdir_p( $mood_dir );
                }
            }
        }
        
        // Create index.php for security
        $index_content = "<?php // Silence is golden";
        file_put_contents( $base_dir . 'index.php', $index_content );
        
        return $base_dir;
    }
    
    /**
     * Get library statistics
     */
    public static function get_stats() {
        $library = self::get_library();
        $stats = array(
            'total_tracks' => 0,
            'by_duration'  => array(),
            'by_mood'      => array(),
        );
        
        foreach ( $library as $duration => $moods ) {
            $stats['by_duration'][ $duration ] = 0;
            
            foreach ( $moods as $mood => $tracks ) {
                $count = count( $tracks );
                $stats['total_tracks'] += $count;
                $stats['by_duration'][ $duration ] += $count;
                
                if ( ! isset( $stats['by_mood'][ $mood ] ) ) {
                    $stats['by_mood'][ $mood ] = 0;
                }
                $stats['by_mood'][ $mood ] += $count;
            }
        }
        
        return $stats;
    }
}
