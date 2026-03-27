<?php
/**
 * View: Script Form (Add/Edit)
 * 
 * @var int    $script_id Script ID (0 for new)
 * @var object $script    Script object (null for new)
 * @var bool   $is_edit   Whether editing existing script
 * @var array  $defaults  Default values for form fields
 * @var array  $metadata  Script metadata array
 * @var string $nonce     Security nonce
 * 
 * @package BizCity_Video_Kling
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="wrap bizcity-kling-wrap">
    <h1>
        <?php echo $is_edit ? __( 'Edit Script', 'bizcity-video-kling' ) : __( 'New Script', 'bizcity-video-kling' ); ?>
    </h1>
    
    <?php BizCity_Video_Kling_Admin_Menu::render_workflow_steps( 'scripts', $script_id ); ?>
    
    <form id="script-form" class="bizcity-kling-form">
        <input type="hidden" name="script_id" value="<?php echo $script_id; ?>">
        
        <!-- AI Suggestion Section -->
        <div class="bizcity-kling-card ai-suggest-card" style="background: linear-gradient(135deg, #667eea11 0%, #764ba211 100%); border: 1px solid #667eea33;">
            <h2 style="display: flex; align-items: center; gap: 8px;">
                <span style="font-size: 24px;">🤖</span>
                <?php _e( 'AI Script Generator', 'bizcity-video-kling' ); ?>
                <span class="badge" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600;">BETA</span>
            </h2>
            <p class="description" style="margin-top: -10px; margin-bottom: 15px;">
                <?php _e( 'Describe your video idea and let AI generate the script, visual prompt, and voiceover text for you.', 'bizcity-video-kling' ); ?>
            </p>
            
            <table class="form-table" style="margin-top: 0;">
                <tr>
                    <th scope="row">
                        <label for="ai_idea"><?php _e( 'Video Idea', 'bizcity-video-kling' ); ?></label>
                    </th>
                    <td>
                        <textarea name="ai_idea" id="ai_idea" rows="3" class="large-text" 
                            placeholder="<?php _e( 'E.g., A baby dancing happily in a flower garden, cute and joyful vibes', 'bizcity-video-kling' ); ?>"></textarea>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="ai_style"><?php _e( 'Style', 'bizcity-video-kling' ); ?></label>
                    </th>
                    <td>
                        <select name="ai_style" id="ai_style" style="min-width: 200px;">
                            <option value="engaging"><?php _e( 'Engaging & Fun (Social Media)', 'bizcity-video-kling' ); ?></option>
                            <option value="professional"><?php _e( 'Professional & Clean', 'bizcity-video-kling' ); ?></option>
                            <option value="storytelling"><?php _e( 'Storytelling & Emotional', 'bizcity-video-kling' ); ?></option>
                            <option value="educational"><?php _e( 'Educational & Informative', 'bizcity-video-kling' ); ?></option>
                            <option value="cinematic"><?php _e( 'Cinematic & Dramatic', 'bizcity-video-kling' ); ?></option>
                            <option value="minimalist"><?php _e( 'Minimalist & Calm', 'bizcity-video-kling' ); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th></th>
                    <td>
                        <button type="button" class="button button-primary" id="ai-suggest-btn" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border: none;">
                            <span class="dashicons dashicons-admin-customizer" style="margin-right: 5px;"></span>
                            <?php _e( 'Generate Script & Voiceover', 'bizcity-video-kling' ); ?>
                        </button>
                        <span id="ai-suggest-loading" style="display: none; margin-left: 10px; color: #666;">
                            <span class="spinner is-active" style="float: none; margin: 0 5px 0 0;"></span>
                            <?php _e( 'AI is thinking...', 'bizcity-video-kling' ); ?>
                        </span>
                        <?php 
                        $has_openai_key = ! empty( get_option( 'twf_openai_api_key', '' ) ) || ! empty( get_option( 'bizcity_video_kling_openai_api_key', '' ) );
                        if ( ! $has_openai_key ): 
                        ?>
                            <p class="description" style="color: #d63638; margin-top: 10px;">
                                <span class="dashicons dashicons-warning"></span>
                                <?php _e( 'OpenAI API key not configured. Please set twf_openai_api_key option.', 'bizcity-video-kling' ); ?>
                            </p>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
        </div>
        
        <div class="bizcity-kling-card">
            <h2><?php _e( 'Script Details', 'bizcity-video-kling' ); ?></h2>
            
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="title"><?php _e( 'Title', 'bizcity-video-kling' ); ?></label>
                    </th>
                    <td>
                        <input type="text" name="title" id="title" class="regular-text" 
                               value="<?php echo esc_attr( $defaults['title'] ); ?>" required>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="content"><?php _e( 'Prompt / Content', 'bizcity-video-kling' ); ?></label>
                    </th>
                    <td>
                        <textarea name="content" id="content" rows="6" class="large-text" required
                            placeholder="<?php _e( 'Enter prompt for video generation...', 'bizcity-video-kling' ); ?>"><?php echo esc_textarea( $defaults['content'] ); ?></textarea>
                        <p class="description"><?php _e( 'Describe the video you want to generate.', 'bizcity-video-kling' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="image_url"><?php _e( 'Source Image', 'bizcity-video-kling' ); ?></label>
                    </th>
                    <td>
                        <div class="image-upload-field">
                            <div id="image-preview" class="image-preview" style="margin-bottom: 10px; <?php echo empty( $metadata['image_url'] ?? '' ) ? 'display:none;' : ''; ?>">
                                <img src="<?php echo esc_url( $metadata['image_url'] ?? '' ); ?>" style="max-width: 200px; max-height: 200px; border-radius: 4px; border: 1px solid #ddd;">
                                <button type="button" class="button button-link-delete remove-image" style="display: block; margin-top: 5px; color: #a00;">
                                    <?php _e( 'Remove Image', 'bizcity-video-kling' ); ?>
                                </button>
                            </div>
                            
                            <input type="hidden" name="image_url" id="image_url" 
                                   value="<?php echo esc_url( $metadata['image_url'] ?? '' ); ?>">
                            <input type="hidden" name="image_attachment_id" id="image_attachment_id" 
                                   value="<?php echo esc_attr( $metadata['image_attachment_id'] ?? '' ); ?>">
                            
                            <button type="button" class="button" id="upload-image-btn">
                                <span class="dashicons dashicons-upload" style="vertical-align: middle;"></span>
                                <?php _e( 'Upload / Select Image', 'bizcity-video-kling' ); ?>
                            </button>
                            
                            <span style="margin: 0 10px; color: #666;"><?php _e( 'or', 'bizcity-video-kling' ); ?></span>
                            
                            <input type="url" id="image_url_input" class="regular-text" 
                                   placeholder="https://... (paste image URL)"
                                   style="width: 300px;">
                            <button type="button" class="button" id="apply-url-btn">
                                <?php _e( 'Apply URL', 'bizcity-video-kling' ); ?>
                            </button>
                        </div>
                        <p class="description"><?php _e( 'Upload an image or enter URL for Image-to-Video generation. Recommended: 9:16 aspect ratio for social media.', 'bizcity-video-kling' ); ?></p>
                    </td>
                </tr>
            </table>
        </div>
        
        <div class="bizcity-kling-card">
            <h2><?php _e( 'Video Settings', 'bizcity-video-kling' ); ?></h2>
            
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="duration"><?php _e( 'Duration (seconds)', 'bizcity-video-kling' ); ?></label>
                    </th>
                    <td>
                        <select name="duration" id="duration">
                            <option value="5" <?php selected( $defaults['duration'], 5 ); ?>>5s</option>
                            <option value="10" <?php selected( $defaults['duration'], 10 ); ?>>10s</option>
                            <option value="20" <?php selected( $defaults['duration'], 20 ); ?>>20s</option>
                            <option value="25" <?php selected( $defaults['duration'], 25 ); ?>>25s</option>
                            <option value="30" <?php selected( $defaults['duration'], 30 ); ?>>30s</option>
                            <option value="35" <?php selected( $defaults['duration'], 35 ); ?>>35s</option>
                            <option value="45" <?php selected( $defaults['duration'], 45 ); ?>>45s</option>
                            <option value="60" <?php selected( $defaults['duration'], 60 ); ?>>60s</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="aspect_ratio"><?php _e( 'Aspect Ratio', 'bizcity-video-kling' ); ?></label>
                    </th>
                    <td>
                        <select name="aspect_ratio" id="aspect_ratio">
                            <option value="9:16" <?php selected( $defaults['aspect_ratio'], '9:16' ); ?>>9:16 (Portrait - TikTok/Reels)</option>
                            <option value="16:9" <?php selected( $defaults['aspect_ratio'], '16:9' ); ?>>16:9 (Landscape - YouTube)</option>
                            <option value="1:1" <?php selected( $defaults['aspect_ratio'], '1:1' ); ?>>1:1 (Square)</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="model"><?php _e( 'Model', 'bizcity-video-kling' ); ?></label>
                    </th>
                    <td>
                        <select name="model" id="model">
                            <?php 
                            $models = waic_kling_get_models();
                            foreach ( $models as $value => $label ): 
                            ?>
                                <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $defaults['model'], $value ); ?>>
                                    <?php echo esc_html( $label ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php _e( 'Pro models produce higher quality but cost more.', 'bizcity-video-kling' ); ?></p>
                        <div id="model-warning" class="notice notice-warning inline" style="display: none; margin-top: 10px; padding: 10px;">
                            <strong><?php _e( 'Note:', 'bizcity-video-kling' ); ?></strong>
                            <?php _e( 'Kling 2.x models do NOT support video extension. Videos longer than 10s require v1.5 or v1.6.', 'bizcity-video-kling' ); ?>
                        </div>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="with_audio"><?php _e( 'Sound Effects', 'bizcity-video-kling' ); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="with_audio" id="with_audio" value="1" <?php checked( $defaults['with_audio'], true ); ?>>
                            <?php _e( 'Auto-generate sound effects (Kling 2.0+)', 'bizcity-video-kling' ); ?>
                        </label>
                        <p class="description"><?php _e( 'AI will generate appropriate sounds based on video content (e.g., nature sounds, music, ambient noise).', 'bizcity-video-kling' ); ?></p>
                        <p class="description" style="color: #d63638;"><strong><?php _e( 'Note:', 'bizcity-video-kling' ); ?></strong> <?php _e( 'Sound effects may not work with all PiAPI configurations.', 'bizcity-video-kling' ); ?></p>
                    </td>
                </tr>
            </table>
        </div>
        
        <!-- TTS / Voiceover Section -->
        <div class="bizcity-kling-card">
            <h2>🎙️ <?php _e( 'TTS / Voiceover', 'bizcity-video-kling' ); ?></h2>
            
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="tts_enabled"><?php _e( 'Enable TTS', 'bizcity-video-kling' ); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="tts_enabled" id="tts_enabled" value="1" <?php checked( $metadata['tts_enabled'] ?? false, true ); ?>>
                            <?php _e( 'Add AI voiceover to video (OpenAI TTS)', 'bizcity-video-kling' ); ?>
                        </label>
                        <p class="description"><?php _e( 'Generate speech from text and merge with video using FFmpeg.', 'bizcity-video-kling' ); ?></p>
                        <?php 
                        $tts_configured = class_exists( 'BizCity_Video_Kling_OpenAI_TTS' ) && BizCity_Video_Kling_OpenAI_TTS::is_configured();
                        if ( ! $tts_configured ): 
                        ?>
                            <p class="description" style="color: #d63638;"><strong><?php _e( 'Warning:', 'bizcity-video-kling' ); ?></strong> <?php _e( 'TTS API key not configured (twf_openai_api_key).', 'bizcity-video-kling' ); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr class="tts-options" style="<?php echo empty( $metadata['tts_enabled'] ) ? 'display:none;' : ''; ?>">
                    <th scope="row">
                        <label for="tts_text"><?php _e( 'Voiceover Text', 'bizcity-video-kling' ); ?></label>
                    </th>
                    <td>
                        <textarea name="tts_text" id="tts_text" rows="4" class="large-text" 
                            placeholder="<?php _e( 'Leave empty to use the prompt above as voiceover text', 'bizcity-video-kling' ); ?>"><?php echo esc_textarea( $metadata['tts_text'] ?? '' ); ?></textarea>
                        <p class="description"><?php _e( 'Custom text for voiceover. If empty, will use the Prompt/Content field.', 'bizcity-video-kling' ); ?></p>
                    </td>
                </tr>
                <tr class="tts-options" style="<?php echo empty( $metadata['tts_enabled'] ) ? 'display:none;' : ''; ?>">
                    <th scope="row">
                        <label><?php _e( 'Voice Gender', 'bizcity-video-kling' ); ?></label>
                    </th>
                    <td>
                        <fieldset id="tts_gender_selector" style="display: flex; gap: 20px; margin-bottom: 10px;">
                            <?php 
                            $current_voice = $metadata['tts_voice'] ?? 'nova';
                            $is_female = in_array( $current_voice, array( 'nova', 'shimmer' ) );
                            $is_male = in_array( $current_voice, array( 'onyx', 'echo', 'fable' ) );
                            ?>
                            <label style="display: flex; align-items: center; gap: 8px; padding: 10px 15px; border: 2px solid #ddd; border-radius: 8px; cursor: pointer; transition: all 0.2s;" class="gender-option <?php echo $is_female ? 'active' : ''; ?>">
                                <input type="radio" name="tts_gender" value="female" <?php checked( $is_female || ! $is_male, true ); ?> style="margin: 0;">
                                <span style="font-size: 20px;">👩</span>
                                <span><?php _e( 'Female', 'bizcity-video-kling' ); ?></span>
                            </label>
                            <label style="display: flex; align-items: center; gap: 8px; padding: 10px 15px; border: 2px solid #ddd; border-radius: 8px; cursor: pointer; transition: all 0.2s;" class="gender-option <?php echo $is_male ? 'active' : ''; ?>">
                                <input type="radio" name="tts_gender" value="male" <?php checked( $is_male, true ); ?> style="margin: 0;">
                                <span style="font-size: 20px;">👨</span>
                                <span><?php _e( 'Male', 'bizcity-video-kling' ); ?></span>
                            </label>
                            <label style="display: flex; align-items: center; gap: 8px; padding: 10px 15px; border: 2px solid #ddd; border-radius: 8px; cursor: pointer; transition: all 0.2s;" class="gender-option <?php echo $current_voice === 'alloy' ? 'active' : ''; ?>">
                                <input type="radio" name="tts_gender" value="neutral" <?php checked( $current_voice, 'alloy' ); ?> style="margin: 0;">
                                <span style="font-size: 20px;">⚖️</span>
                                <span><?php _e( 'Neutral', 'bizcity-video-kling' ); ?></span>
                            </label>
                        </fieldset>
                        <style>
                            .gender-option:hover { border-color: #2271b1; }
                            .gender-option.active { border-color: #2271b1; background: #f0f6fc; }
                            .gender-option input:checked ~ span { font-weight: 600; }
                            /* AI filled highlight animation */
                            @keyframes ai-highlight {
                                0% { box-shadow: 0 0 0 0 rgba(102, 126, 234, 0.7); background-color: rgba(102, 126, 234, 0.1); }
                                50% { box-shadow: 0 0 15px 5px rgba(102, 126, 234, 0.3); background-color: rgba(102, 126, 234, 0.15); }
                                100% { box-shadow: 0 0 0 0 rgba(102, 126, 234, 0); background-color: transparent; }
                            }
                            .ai-filled { animation: ai-highlight 2s ease-out; }
                            .ai-suggest-card h2 .badge { vertical-align: middle; }
                        </style>
                    </td>
                </tr>
                <tr class="tts-options" style="<?php echo empty( $metadata['tts_enabled'] ) ? 'display:none;' : ''; ?>">
                    <th scope="row">
                        <label for="tts_voice"><?php _e( 'Voice', 'bizcity-video-kling' ); ?></label>
                    </th>
                    <td>
                        <select name="tts_voice" id="tts_voice">
                            <optgroup label="👩 Nữ (Female)" class="voice-group-female">
                                <option value="nova" <?php selected( $metadata['tts_voice'] ?? 'nova', 'nova' ); ?>>Nova - Ấm áp, tự nhiên ⭐ đề xuất</option>
                                <option value="shimmer" <?php selected( $metadata['tts_voice'] ?? '', 'shimmer' ); ?>>Shimmer - Rõ ràng, chuyên nghiệp</option>
                            </optgroup>
                            <optgroup label="👨 Nam (Male)" class="voice-group-male">
                                <option value="onyx" <?php selected( $metadata['tts_voice'] ?? '', 'onyx' ); ?>>Onyx - Trầm ấm, uy tín ⭐ đề xuất</option>
                                <option value="echo" <?php selected( $metadata['tts_voice'] ?? '', 'echo' ); ?>>Echo - Mượt mà, êm ái</option>
                                <option value="fable" <?php selected( $metadata['tts_voice'] ?? '', 'fable' ); ?>>Fable - Giọng Anh quốc</option>
                            </optgroup>
                            <optgroup label="⚖️ Trung tính (Neutral)" class="voice-group-neutral">
                                <option value="alloy" <?php selected( $metadata['tts_voice'] ?? '', 'alloy' ); ?>>Alloy - Cân bằng, đa dụng</option>
                            </optgroup>
                        </select>
                    </td>
                </tr>
                <tr class="tts-options" style="<?php echo empty( $metadata['tts_enabled'] ) ? 'display:none;' : ''; ?>">
                    <th scope="row">
                        <label for="tts_model"><?php _e( 'TTS Model', 'bizcity-video-kling' ); ?></label>
                    </th>
                    <td>
                        <select name="tts_model" id="tts_model">
                            <option value="tts-1-hd" <?php selected( $metadata['tts_model'] ?? 'tts-1-hd', 'tts-1-hd' ); ?>><?php _e( 'TTS-1 HD (High Quality)', 'bizcity-video-kling' ); ?></option>
                            <option value="tts-1" <?php selected( $metadata['tts_model'] ?? '', 'tts-1' ); ?>><?php _e( 'TTS-1 (Standard - faster)', 'bizcity-video-kling' ); ?></option>
                        </select>
                    </td>
                </tr>
                <tr class="tts-options" style="<?php echo empty( $metadata['tts_enabled'] ) ? 'display:none;' : ''; ?>">
                    <th scope="row">
                        <label for="tts_speed"><?php _e( 'Speed', 'bizcity-video-kling' ); ?></label>
                    </th>
                    <td>
                        <input type="range" name="tts_speed" id="tts_speed" min="0.5" max="2.0" step="0.1" 
                               value="<?php echo esc_attr( $metadata['tts_speed'] ?? '1.0' ); ?>" style="width: 200px; vertical-align: middle;">
                        <span id="tts_speed_value" style="margin-left: 10px; font-weight: 600; color: #2271b1;"><?php echo esc_html( $metadata['tts_speed'] ?? '1.0' ); ?>x</span>
                    </td>
                </tr>
                <tr class="tts-options" style="<?php echo empty( $metadata['tts_enabled'] ) ? 'display:none;' : ''; ?>">
                    <th scope="row">
                        <label for="ffmpeg_preset"><?php _e( 'FFmpeg Preset', 'bizcity-video-kling' ); ?></label>
                    </th>
                    <td>
                        <select name="ffmpeg_preset" id="ffmpeg_preset">
                            <option value="" <?php selected( $metadata['ffmpeg_preset'] ?? '', '' ); ?>><?php _e( '-- None --', 'bizcity-video-kling' ); ?></option>
                            <optgroup label="<?php _e( 'Combined Presets', 'bizcity-video-kling' ); ?>">
                                <option value="cinematic" <?php selected( $metadata['ffmpeg_preset'] ?? '', 'cinematic' ); ?>><?php _e( 'Cinematic - Điện ảnh chuyên nghiệp', 'bizcity-video-kling' ); ?></option>
                                <option value="vintage" <?php selected( $metadata['ffmpeg_preset'] ?? '', 'vintage' ); ?>><?php _e( 'Vintage - Phong cách cổ điển', 'bizcity-video-kling' ); ?></option>
                                <option value="modern" <?php selected( $metadata['ffmpeg_preset'] ?? '', 'modern' ); ?>><?php _e( 'Modern - Hiện đại rực rỡ', 'bizcity-video-kling' ); ?></option>
                                <option value="minimal" <?php selected( $metadata['ffmpeg_preset'] ?? '', 'minimal' ); ?>><?php _e( 'Minimal - Tối giản sạch sẽ', 'bizcity-video-kling' ); ?></option>
                            </optgroup>
                            <optgroup label="<?php _e( 'Color Grading', 'bizcity-video-kling' ); ?>">
                                <option value="warm" <?php selected( $metadata['ffmpeg_preset'] ?? '', 'warm' ); ?>><?php _e( 'Warm - Ấm áp hoàng hôn', 'bizcity-video-kling' ); ?></option>
                                <option value="cool" <?php selected( $metadata['ffmpeg_preset'] ?? '', 'cool' ); ?>><?php _e( 'Cool - Lạnh hiện đại', 'bizcity-video-kling' ); ?></option>
                                <option value="dramatic" <?php selected( $metadata['ffmpeg_preset'] ?? '', 'dramatic' ); ?>><?php _e( 'Dramatic - Kịch tính cao', 'bizcity-video-kling' ); ?></option>
                                <option value="golden_hour" <?php selected( $metadata['ffmpeg_preset'] ?? '', 'golden_hour' ); ?>><?php _e( 'Golden Hour - Giờ vàng', 'bizcity-video-kling' ); ?></option>
                            </optgroup>
                            <optgroup label="<?php _e( 'Effects', 'bizcity-video-kling' ); ?>">
                                <option value="zoom_gentle" <?php selected( $metadata['ffmpeg_preset'] ?? '', 'zoom_gentle' ); ?>><?php _e( 'Zoom Gentle (Ken Burns)', 'bizcity-video-kling' ); ?></option>
                                <option value="lower_third" <?php selected( $metadata['ffmpeg_preset'] ?? '', 'lower_third' ); ?>><?php _e( 'Lower Third (Title Bar)', 'bizcity-video-kling' ); ?></option>
                                <option value="vignette" <?php selected( $metadata['ffmpeg_preset'] ?? '', 'vignette' ); ?>><?php _e( 'Vignette - Tối góc', 'bizcity-video-kling' ); ?></option>
                            </optgroup>
                        </select>
                        <p class="description"><?php _e( 'Apply video filter effect when merging with audio.', 'bizcity-video-kling' ); ?></p>
                    </td>
                </tr>
            </table>
        </div>
        
        <!-- Post-production Audio Section -->
        <div class="bizcity-kling-card">
            <h2>🎵 <?php _e( 'Post-production Audio', 'bizcity-video-kling' ); ?></h2>
            <p class="description" style="margin-top: -10px; margin-bottom: 15px;">
                <?php _e( 'Configure audio tracks for post-production: voiceover (TTS or custom) and background music.', 'bizcity-video-kling' ); ?>
            </p>
            
            <table class="form-table">
                <!-- Audio Mode -->
                <tr>
                    <th scope="row">
                        <label><?php _e( 'Voiceover Mode', 'bizcity-video-kling' ); ?></label>
                    </th>
                    <td>
                        <fieldset id="audio_mode_selector" style="display: flex; gap: 15px; flex-wrap: wrap;">
                            <?php $audio_mode = $metadata['audio_mode'] ?? 'tts'; ?>
                            <label style="display: flex; align-items: center; gap: 8px; padding: 10px 15px; border: 2px solid #ddd; border-radius: 8px; cursor: pointer;" class="audio-mode-option <?php echo $audio_mode === 'none' ? 'active' : ''; ?>">
                                <input type="radio" name="audio_mode" value="none" <?php checked( $audio_mode, 'none' ); ?>>
                                <span>🔇</span>
                                <span><?php _e( 'No Voiceover', 'bizcity-video-kling' ); ?></span>
                            </label>
                            <label style="display: flex; align-items: center; gap: 8px; padding: 10px 15px; border: 2px solid #ddd; border-radius: 8px; cursor: pointer;" class="audio-mode-option <?php echo $audio_mode === 'tts' ? 'active' : ''; ?>">
                                <input type="radio" name="audio_mode" value="tts" <?php checked( $audio_mode, 'tts' ); ?>>
                                <span>🤖</span>
                                <span><?php _e( 'AI TTS (OpenAI)', 'bizcity-video-kling' ); ?></span>
                            </label>
                            <label style="display: flex; align-items: center; gap: 8px; padding: 10px 15px; border: 2px solid #ddd; border-radius: 8px; cursor: pointer;" class="audio-mode-option <?php echo $audio_mode === 'custom' ? 'active' : ''; ?>">
                                <input type="radio" name="audio_mode" value="custom" <?php checked( $audio_mode, 'custom' ); ?>>
                                <span>🎤</span>
                                <span><?php _e( 'Custom Audio Upload', 'bizcity-video-kling' ); ?></span>
                            </label>
                        </fieldset>
                        <style>
                            .audio-mode-option:hover { border-color: #2271b1; }
                            .audio-mode-option.active { border-color: #2271b1; background: #f0f6fc; }
                        </style>
                    </td>
                </tr>
                
                <!-- Custom Audio Upload (shown when audio_mode = custom) -->
                <tr class="custom-audio-options" style="<?php echo ( $metadata['audio_mode'] ?? 'tts' ) !== 'custom' ? 'display:none;' : ''; ?>">
                    <th scope="row">
                        <label for="custom_audio_url"><?php _e( 'Custom Voiceover Audio', 'bizcity-video-kling' ); ?></label>
                    </th>
                    <td>
                        <div class="audio-upload-field">
                            <div id="custom-audio-preview" class="audio-preview" style="margin-bottom: 10px; <?php echo empty( $metadata['custom_audio_url'] ?? '' ) ? 'display:none;' : ''; ?>">
                                <audio controls style="max-width: 400px;">
                                    <source src="<?php echo esc_url( $metadata['custom_audio_url'] ?? '' ); ?>" type="audio/mpeg">
                                </audio>
                                <button type="button" class="button button-link-delete remove-custom-audio" style="display: block; margin-top: 5px; color: #a00;">
                                    <?php _e( 'Remove Audio', 'bizcity-video-kling' ); ?>
                                </button>
                            </div>
                            
                            <input type="hidden" name="custom_audio_url" id="custom_audio_url" 
                                   value="<?php echo esc_url( $metadata['custom_audio_url'] ?? '' ); ?>">
                            <input type="hidden" name="custom_audio_attachment_id" id="custom_audio_attachment_id" 
                                   value="<?php echo esc_attr( $metadata['custom_audio_attachment_id'] ?? '' ); ?>">
                            
                            <button type="button" class="button" id="upload-custom-audio-btn">
                                <span class="dashicons dashicons-upload" style="vertical-align: middle;"></span>
                                <?php _e( 'Upload Voiceover Audio', 'bizcity-video-kling' ); ?>
                            </button>
                            <p class="description"><?php _e( 'Upload MP3, WAV, or audio file for voiceover instead of AI TTS.', 'bizcity-video-kling' ); ?></p>
                        </div>
                    </td>
                </tr>
                
                <!-- Custom Audio Volume -->
                <tr class="custom-audio-options" style="<?php echo ( $metadata['audio_mode'] ?? 'tts' ) !== 'custom' ? 'display:none;' : ''; ?>">
                    <th scope="row">
                        <label for="custom_audio_volume"><?php _e( 'Voiceover Volume', 'bizcity-video-kling' ); ?></label>
                    </th>
                    <td>
                        <input type="range" name="custom_audio_volume" id="custom_audio_volume" min="0" max="200" step="5" 
                               value="<?php echo esc_attr( $metadata['custom_audio_volume'] ?? '100' ); ?>" style="width: 200px; vertical-align: middle;">
                        <span id="custom_audio_volume_value" style="margin-left: 10px; font-weight: 600; color: #2271b1;"><?php echo esc_html( $metadata['custom_audio_volume'] ?? '100' ); ?>%</span>
                    </td>
                </tr>
                
                <!-- Background Music Section -->
                <tr>
                    <th scope="row" colspan="2" style="padding: 15px 0 5px 0;">
                        <h3 style="margin: 0; font-size: 14px; border-bottom: 1px solid #ddd; padding-bottom: 8px;">
                            🎶 <?php _e( 'Background Music', 'bizcity-video-kling' ); ?>
                        </h3>
                    </th>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="bgm_preset"><?php _e( 'Music Preset', 'bizcity-video-kling' ); ?></label>
                    </th>
                    <td>
                        <select name="bgm_preset" id="bgm_preset" style="min-width: 300px;">
                            <option value="" <?php selected( $metadata['bgm_preset'] ?? '', '' ); ?>><?php _e( '-- No Background Music --', 'bizcity-video-kling' ); ?></option>
                            <optgroup label="🎵 <?php _e( 'Upbeat / Energetic', 'bizcity-video-kling' ); ?>">
                                <option value="upbeat_pop" <?php selected( $metadata['bgm_preset'] ?? '', 'upbeat_pop' ); ?>><?php _e( 'Upbeat Pop - Vui tươi, năng động', 'bizcity-video-kling' ); ?></option>
                                <option value="electronic_dance" <?php selected( $metadata['bgm_preset'] ?? '', 'electronic_dance' ); ?>><?php _e( 'Electronic Dance - EDM sôi động', 'bizcity-video-kling' ); ?></option>
                                <option value="happy_acoustic" <?php selected( $metadata['bgm_preset'] ?? '', 'happy_acoustic' ); ?>><?php _e( 'Happy Acoustic - Guitar vui vẻ', 'bizcity-video-kling' ); ?></option>
                            </optgroup>
                            <optgroup label="🎹 <?php _e( 'Calm / Relaxing', 'bizcity-video-kling' ); ?>">
                                <option value="ambient_chill" <?php selected( $metadata['bgm_preset'] ?? '', 'ambient_chill' ); ?>><?php _e( 'Ambient Chill - Thư giãn', 'bizcity-video-kling' ); ?></option>
                                <option value="piano_soft" <?php selected( $metadata['bgm_preset'] ?? '', 'piano_soft' ); ?>><?php _e( 'Piano Soft - Piano nhẹ nhàng', 'bizcity-video-kling' ); ?></option>
                                <option value="lo_fi" <?php selected( $metadata['bgm_preset'] ?? '', 'lo_fi' ); ?>><?php _e( 'Lo-Fi Beats - Lo-fi chill', 'bizcity-video-kling' ); ?></option>
                            </optgroup>
                            <optgroup label="🎬 <?php _e( 'Cinematic / Dramatic', 'bizcity-video-kling' ); ?>">
                                <option value="cinematic_epic" <?php selected( $metadata['bgm_preset'] ?? '', 'cinematic_epic' ); ?>><?php _e( 'Cinematic Epic - Sử thi hoành tráng', 'bizcity-video-kling' ); ?></option>
                                <option value="emotional_strings" <?php selected( $metadata['bgm_preset'] ?? '', 'emotional_strings' ); ?>><?php _e( 'Emotional Strings - Dây xúc động', 'bizcity-video-kling' ); ?></option>
                                <option value="inspirational" <?php selected( $metadata['bgm_preset'] ?? '', 'inspirational' ); ?>><?php _e( 'Inspirational - Truyền cảm hứng', 'bizcity-video-kling' ); ?></option>
                            </optgroup>
                            <optgroup label="🎸 <?php _e( 'Other Styles', 'bizcity-video-kling' ); ?>">
                                <option value="corporate" <?php selected( $metadata['bgm_preset'] ?? '', 'corporate' ); ?>><?php _e( 'Corporate - Doanh nghiệp', 'bizcity-video-kling' ); ?></option>
                                <option value="jazz_smooth" <?php selected( $metadata['bgm_preset'] ?? '', 'jazz_smooth' ); ?>><?php _e( 'Jazz Smooth - Jazz êm ái', 'bizcity-video-kling' ); ?></option>
                                <option value="nature_sounds" <?php selected( $metadata['bgm_preset'] ?? '', 'nature_sounds' ); ?>><?php _e( 'Nature Sounds - Âm thanh thiên nhiên', 'bizcity-video-kling' ); ?></option>
                            </optgroup>
                            <optgroup label="📁 <?php _e( 'Custom', 'bizcity-video-kling' ); ?>">
                                <option value="custom" <?php selected( $metadata['bgm_preset'] ?? '', 'custom' ); ?>><?php _e( 'Custom Upload - Tự upload nhạc', 'bizcity-video-kling' ); ?></option>
                            </optgroup>
                        </select>
                    </td>
                </tr>
                
                <!-- Custom BGM Upload -->
                <tr class="bgm-custom-upload" style="<?php echo ( $metadata['bgm_preset'] ?? '' ) !== 'custom' ? 'display:none;' : ''; ?>">
                    <th scope="row">
                        <label for="bgm_url"><?php _e( 'Custom BGM File', 'bizcity-video-kling' ); ?></label>
                    </th>
                    <td>
                        <div class="audio-upload-field">
                            <div id="bgm-preview" class="audio-preview" style="margin-bottom: 10px; <?php echo empty( $metadata['bgm_url'] ?? '' ) ? 'display:none;' : ''; ?>">
                                <audio controls style="max-width: 400px;">
                                    <source src="<?php echo esc_url( $metadata['bgm_url'] ?? '' ); ?>" type="audio/mpeg">
                                </audio>
                                <button type="button" class="button button-link-delete remove-bgm" style="display: block; margin-top: 5px; color: #a00;">
                                    <?php _e( 'Remove BGM', 'bizcity-video-kling' ); ?>
                                </button>
                            </div>
                            
                            <input type="hidden" name="bgm_url" id="bgm_url" 
                                   value="<?php echo esc_url( $metadata['bgm_url'] ?? '' ); ?>">
                            <input type="hidden" name="bgm_attachment_id" id="bgm_attachment_id" 
                                   value="<?php echo esc_attr( $metadata['bgm_attachment_id'] ?? '' ); ?>">
                            
                            <button type="button" class="button" id="upload-bgm-btn">
                                <span class="dashicons dashicons-upload" style="vertical-align: middle;"></span>
                                <?php _e( 'Upload Background Music', 'bizcity-video-kling' ); ?>
                            </button>
                        </div>
                    </td>
                </tr>
                
                <!-- BGM Volume -->
                <tr class="bgm-volume-row" style="<?php echo empty( $metadata['bgm_preset'] ?? '' ) ? 'display:none;' : ''; ?>">
                    <th scope="row">
                        <label for="bgm_volume"><?php _e( 'BGM Volume', 'bizcity-video-kling' ); ?></label>
                    </th>
                    <td>
                        <input type="range" name="bgm_volume" id="bgm_volume" min="0" max="100" step="5" 
                               value="<?php echo esc_attr( $metadata['bgm_volume'] ?? '30' ); ?>" style="width: 200px; vertical-align: middle;">
                        <span id="bgm_volume_value" style="margin-left: 10px; font-weight: 600; color: #2271b1;"><?php echo esc_html( $metadata['bgm_volume'] ?? '30' ); ?>%</span>
                        <p class="description"><?php _e( 'Lower volume recommended (20-40%) to not overpower voiceover.', 'bizcity-video-kling' ); ?></p>
                    </td>
                </tr>
                
                <!-- Post-production Preview -->
                <tr>
                    <th scope="row" colspan="2" style="padding: 15px 0 5px 0;">
                        <h3 style="margin: 0; font-size: 14px; border-bottom: 1px solid #ddd; padding-bottom: 8px;">
                            📋 <?php _e( 'Post-production Steps Preview', 'bizcity-video-kling' ); ?>
                        </h3>
                    </th>
                </tr>
                <tr>
                    <td colspan="2">
                        <div id="postprod-steps-preview" style="background: #f9f9f9; padding: 15px; border-radius: 8px; font-size: 13px;">
                            <div class="step-item" style="display: flex; align-items: center; padding: 8px 0; border-bottom: 1px dashed #ddd;">
                                <span class="step-icon" style="width: 30px; text-align: center;">1️⃣</span>
                                <span class="step-text"><?php _e( 'Generate Video with Kling AI', 'bizcity-video-kling' ); ?></span>
                            </div>
                            <div class="step-item step-voiceover" style="display: flex; align-items: center; padding: 8px 0; border-bottom: 1px dashed #ddd;">
                                <span class="step-icon" style="width: 30px; text-align: center;">2️⃣</span>
                                <span class="step-text" id="voiceover-step-text"><?php _e( 'Add Voiceover (TTS)', 'bizcity-video-kling' ); ?></span>
                            </div>
                            <div class="step-item step-bgm" style="display: flex; align-items: center; padding: 8px 0; border-bottom: 1px dashed #ddd;">
                                <span class="step-icon" style="width: 30px; text-align: center;">3️⃣</span>
                                <span class="step-text" id="bgm-step-text"><?php _e( 'Add Background Music', 'bizcity-video-kling' ); ?></span>
                            </div>
                            <div class="step-item step-effects" style="display: flex; align-items: center; padding: 8px 0; border-bottom: 1px dashed #ddd;">
                                <span class="step-icon" style="width: 30px; text-align: center;">4️⃣</span>
                                <span class="step-text" id="effects-step-text"><?php _e( 'Apply Video Effects', 'bizcity-video-kling' ); ?></span>
                            </div>
                            <div class="step-item" style="display: flex; align-items: center; padding: 8px 0;">
                                <span class="step-icon" style="width: 30px; text-align: center;">5️⃣</span>
                                <span class="step-text"><?php _e( 'Final Video → Upload to Media Library', 'bizcity-video-kling' ); ?></span>
                            </div>
                        </div>
                    </td>
                </tr>
            </table>
        </div>
        
        <p class="submit">
            <button type="submit" class="button button-primary button-large" id="save-btn">
                <?php _e( 'Save Script', 'bizcity-video-kling' ); ?>
            </button>
            
            <?php if ( $is_edit ): ?>
                <button type="button" class="button button-primary button-large" id="save-generate-btn">
                    <?php _e( 'Save & Generate Video', 'bizcity-video-kling' ); ?>
                </button>
            <?php endif; ?>
            
            <a href="<?php echo admin_url( 'admin.php?page=bizcity-kling-scripts' ); ?>" class="button button-large">
                <?php _e( 'Cancel', 'bizcity-video-kling' ); ?>
            </a>
        </p>
    </form>
</div>

<script>
jQuery(document).ready(function($) {
    var nonce = '<?php echo esc_js( $nonce ); ?>';
    
    // AI Suggest - Generate script and voiceover from idea
    $('#ai-suggest-btn').on('click', function() {
        var $btn = $(this);
        var idea = $('#ai_idea').val().trim();
        var style = $('#ai_style').val();
        var duration = $('#duration').val();
        
        if (!idea) {
            alert('<?php echo esc_js( __( 'Please enter your video idea first', 'bizcity-video-kling' ) ); ?>');
            $('#ai_idea').focus();
            return;
        }
        
        $btn.prop('disabled', true);
        $('#ai-suggest-loading').show();
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'bizcity_kling_ai_suggest',
                nonce: nonce,
                idea: idea,
                duration: duration,
                style: style
            },
            success: function(response) {
                if (response.success) {
                    // Fill in the form fields
                    if (response.data.title) {
                        $('#title').val(response.data.title);
                    }
                    if (response.data.prompt) {
                        $('#content').val(response.data.prompt);
                    }
                    if (response.data.voiceover) {
                        $('#tts_text').val(response.data.voiceover);
                        // Auto-enable TTS if voiceover is generated
                        $('#tts_enabled').prop('checked', true).trigger('change');
                    }
                    
                    // Scroll to title field
                    $('html, body').animate({
                        scrollTop: $('#title').offset().top - 100
                    }, 500);
                    
                    // Highlight filled fields
                    $('#title, #content, #tts_text').addClass('ai-filled');
                    setTimeout(function() {
                        $('#title, #content, #tts_text').removeClass('ai-filled');
                    }, 2000);
                    
                } else {
                    alert(response.data.message || 'Error generating content');
                    if (response.data.raw) {
                        console.log('AI Raw Response:', response.data.raw);
                    }
                }
            },
            error: function() {
                alert('<?php echo esc_js( __( 'Request failed. Please try again.', 'bizcity-video-kling' ) ); ?>');
            },
            complete: function() {
                $btn.prop('disabled', false);
                $('#ai-suggest-loading').hide();
            }
        });
    });
    
    // Model warning for long durations
    function checkModelWarning() {
        var duration = parseInt($('#duration').val());
        
        if (duration > 10) {
            $('#model-warning').html('<span class="dashicons dashicons-info"></span> Video dài hơn 10s sẽ được tạo thành nhiều segment và ghép lại bằng FFmpeg.');
            $('#model-warning').show();
        } else {
            $('#model-warning').hide();
        }
    }
    
    $('#model, #duration').on('change', checkModelWarning);
    checkModelWarning();
    
    // TTS options toggle
    $('#tts_enabled').on('change', function() {
        if ($(this).is(':checked')) {
            $('.tts-options').show();
        } else {
            $('.tts-options').hide();
        }
    });
    
    // TTS speed slider
    $('#tts_speed').on('input', function() {
        $('#tts_speed_value').text($(this).val() + 'x');
    });
    
    // Voice Gender selector
    $('input[name="tts_gender"]').on('change', function() {
        var gender = $(this).val();
        var $select = $('#tts_voice');
        
        $('.gender-option').removeClass('active');
        $(this).closest('.gender-option').addClass('active');
        
        var voiceMap = {
            'female': 'nova',
            'male': 'onyx',
            'neutral': 'alloy'
        };
        
        if (voiceMap[gender]) {
            $select.val(voiceMap[gender]);
        }
    });
    
    // Update gender when voice changes
    $('#tts_voice').on('change', function() {
        var voice = $(this).val();
        var femaleVoices = ['nova', 'shimmer'];
        var maleVoices = ['onyx', 'echo', 'fable'];
        
        var gender = 'neutral';
        if (femaleVoices.indexOf(voice) !== -1) {
            gender = 'female';
        } else if (maleVoices.indexOf(voice) !== -1) {
            gender = 'male';
        }
        
        $('input[name="tts_gender"][value="' + gender + '"]').prop('checked', true);
        $('.gender-option').removeClass('active');
        $('input[name="tts_gender"][value="' + gender + '"]').closest('.gender-option').addClass('active');
    });

    // Save script function
    function saveScript(callback) {
        var $form = $('#script-form');
        var data = {
            action: 'bizcity_kling_save_script',
            nonce: nonce,
            script_id: $form.find('[name="script_id"]').val(),
            title: $form.find('[name="title"]').val(),
            content: $form.find('[name="content"]').val(),
            duration: $form.find('[name="duration"]').val(),
            aspect_ratio: $form.find('[name="aspect_ratio"]').val(),
            model: $form.find('[name="model"]').val(),
            with_audio: $form.find('[name="with_audio"]').is(':checked') ? 1 : 0,
            image_url: $form.find('[name="image_url"]').val(),
            image_attachment_id: $form.find('[name="image_attachment_id"]').val(),
            tts_enabled: $form.find('[name="tts_enabled"]').is(':checked') ? 1 : 0,
            tts_text: $form.find('[name="tts_text"]').val(),
            tts_voice: $form.find('[name="tts_voice"]').val(),
            tts_model: $form.find('[name="tts_model"]').val(),
            tts_speed: $form.find('[name="tts_speed"]').val(),
            ffmpeg_preset: $form.find('[name="ffmpeg_preset"]').val(),
            // Post-production audio
            audio_mode: $form.find('[name="audio_mode"]:checked').val(),
            custom_audio_url: $form.find('[name="custom_audio_url"]').val(),
            custom_audio_attachment_id: $form.find('[name="custom_audio_attachment_id"]').val(),
            custom_audio_volume: $form.find('[name="custom_audio_volume"]').val(),
            bgm_preset: $form.find('[name="bgm_preset"]').val(),
            bgm_url: $form.find('[name="bgm_url"]').val(),
            bgm_attachment_id: $form.find('[name="bgm_attachment_id"]').val(),
            bgm_volume: $form.find('[name="bgm_volume"]').val()
        };
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: data,
            success: function(response) {
                if (response.success) {
                    if (callback) {
                        callback(response.data.script_id);
                    } else {
                        window.location.href = '<?php echo admin_url( 'admin.php?page=bizcity-kling-scripts' ); ?>';
                    }
                } else {
                    alert(response.data.message || 'Error saving script');
                }
            },
            error: function() {
                alert('Request failed');
            }
        });
    }
    
    // Save form
    $('#script-form').on('submit', function(e) {
        e.preventDefault();
        $('#save-btn').prop('disabled', true).text('Saving...');
        saveScript();
    });
    
    // Save & Generate
    $('#save-generate-btn').on('click', function() {
        var $btn = $(this);
        $btn.prop('disabled', true).text('Saving...');
        
        saveScript(function(scriptId) {
            $btn.text('Generating...');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'bizcity_kling_generate_video',
                    script_id: scriptId,
                    nonce: nonce
                },
                success: function(response) {
                    if (response.success) {
                        window.location.href = '<?php echo admin_url( 'admin.php?page=bizcity-kling-monitor' ); ?>';
                    } else {
                        alert(response.data.message || 'Error');
                        $btn.prop('disabled', false).text('Save & Generate Video');
                    }
                },
                error: function() {
                    alert('Request failed');
                    $btn.prop('disabled', false).text('Save & Generate Video');
                }
            });
        });
    });
    
    // WordPress Media Uploader
    var mediaUploader;
    
    $('#upload-image-btn').on('click', function(e) {
        e.preventDefault();
        
        if (mediaUploader) {
            mediaUploader.open();
            return;
        }
        
        mediaUploader = wp.media({
            title: '<?php echo esc_js( __( 'Select Image for Video', 'bizcity-video-kling' ) ); ?>',
            button: {
                text: '<?php echo esc_js( __( 'Use This Image', 'bizcity-video-kling' ) ); ?>'
            },
            library: {
                type: 'image'
            },
            multiple: false
        });
        
        mediaUploader.on('select', function() {
            var attachment = mediaUploader.state().get('selection').first().toJSON();
            
            $('#image_url').val(attachment.url);
            $('#image_attachment_id').val(attachment.id);
            $('#image-preview').show().find('img').attr('src', attachment.url);
        });
        
        mediaUploader.open();
    });
    
    // Apply URL manually
    $('#apply-url-btn').on('click', function() {
        var url = $('#image_url_input').val().trim();
        if (url) {
            $('#image_url').val(url);
            $('#image_attachment_id').val('');
            $('#image-preview').show().find('img').attr('src', url);
            $('#image_url_input').val('');
        }
    });
    
    // Remove image
    $('.remove-image').on('click', function() {
        $('#image_url').val('');
        $('#image_attachment_id').val('');
        $('#image-preview').hide();
    });
    
    // ===== POST-PRODUCTION AUDIO HANDLERS =====
    
    // Audio mode toggle
    $('input[name="audio_mode"]').on('change', function() {
        var mode = $(this).val();
        $('.audio-mode-option').removeClass('active');
        $(this).closest('.audio-mode-option').addClass('active');
        
        // Toggle custom audio options
        if (mode === 'custom') {
            $('.custom-audio-options').show();
            $('.tts-options').hide();
        } else if (mode === 'tts') {
            $('.custom-audio-options').hide();
            if ($('#tts_enabled').is(':checked')) {
                $('.tts-options').show();
            }
        } else {
            $('.custom-audio-options').hide();
            $('.tts-options').hide();
        }
        
        updateStepsPreview();
    });
    
    // BGM preset toggle
    $('#bgm_preset').on('change', function() {
        var preset = $(this).val();
        
        if (preset === 'custom') {
            $('.bgm-custom-upload').show();
        } else {
            $('.bgm-custom-upload').hide();
        }
        
        if (preset) {
            $('.bgm-volume-row').show();
        } else {
            $('.bgm-volume-row').hide();
        }
        
        updateStepsPreview();
    });
    
    // Volume sliders
    $('#custom_audio_volume').on('input', function() {
        $('#custom_audio_volume_value').text($(this).val() + '%');
    });
    
    $('#bgm_volume').on('input', function() {
        $('#bgm_volume_value').text($(this).val() + '%');
    });
    
    // Custom Audio Upload
    var customAudioUploader;
    $('#upload-custom-audio-btn').on('click', function(e) {
        e.preventDefault();
        
        if (customAudioUploader) {
            customAudioUploader.open();
            return;
        }
        
        customAudioUploader = wp.media({
            title: '<?php echo esc_js( __( 'Select Voiceover Audio', 'bizcity-video-kling' ) ); ?>',
            button: { text: '<?php echo esc_js( __( 'Use This Audio', 'bizcity-video-kling' ) ); ?>' },
            library: { type: 'audio' },
            multiple: false
        });
        
        customAudioUploader.on('select', function() {
            var attachment = customAudioUploader.state().get('selection').first().toJSON();
            $('#custom_audio_url').val(attachment.url);
            $('#custom_audio_attachment_id').val(attachment.id);
            $('#custom-audio-preview').show().find('audio source').attr('src', attachment.url);
            $('#custom-audio-preview audio')[0].load();
        });
        
        customAudioUploader.open();
    });
    
    // Remove custom audio
    $('.remove-custom-audio').on('click', function() {
        $('#custom_audio_url').val('');
        $('#custom_audio_attachment_id').val('');
        $('#custom-audio-preview').hide();
    });
    
    // BGM Upload
    var bgmUploader;
    $('#upload-bgm-btn').on('click', function(e) {
        e.preventDefault();
        
        if (bgmUploader) {
            bgmUploader.open();
            return;
        }
        
        bgmUploader = wp.media({
            title: '<?php echo esc_js( __( 'Select Background Music', 'bizcity-video-kling' ) ); ?>',
            button: { text: '<?php echo esc_js( __( 'Use This Music', 'bizcity-video-kling' ) ); ?>' },
            library: { type: 'audio' },
            multiple: false
        });
        
        bgmUploader.on('select', function() {
            var attachment = bgmUploader.state().get('selection').first().toJSON();
            $('#bgm_url').val(attachment.url);
            $('#bgm_attachment_id').val(attachment.id);
            $('#bgm-preview').show().find('audio source').attr('src', attachment.url);
            $('#bgm-preview audio')[0].load();
        });
        
        bgmUploader.open();
    });
    
    // Remove BGM
    $('.remove-bgm').on('click', function() {
        $('#bgm_url').val('');
        $('#bgm_attachment_id').val('');
        $('#bgm-preview').hide();
    });
    
    // Update steps preview based on selections
    function updateStepsPreview() {
        var audioMode = $('input[name="audio_mode"]:checked').val();
        var bgmPreset = $('#bgm_preset').val();
        var ffmpegPreset = $('#ffmpeg_preset').val();
        
        // Voiceover step
        if (audioMode === 'none') {
            $('.step-voiceover').css('opacity', '0.4').find('.step-text').html('<s><?php echo esc_js( __( 'Skip Voiceover', 'bizcity-video-kling' ) ); ?></s>');
        } else if (audioMode === 'custom') {
            $('.step-voiceover').css('opacity', '1').find('.step-text').text('<?php echo esc_js( __( 'Add Voiceover (Custom Audio)', 'bizcity-video-kling' ) ); ?>');
        } else {
            $('.step-voiceover').css('opacity', '1').find('.step-text').text('<?php echo esc_js( __( 'Add Voiceover (AI TTS)', 'bizcity-video-kling' ) ); ?>');
        }
        
        // BGM step
        if (!bgmPreset) {
            $('.step-bgm').css('opacity', '0.4').find('.step-text').html('<s><?php echo esc_js( __( 'Skip Background Music', 'bizcity-video-kling' ) ); ?></s>');
        } else {
            var bgmName = $('#bgm_preset option:selected').text();
            $('.step-bgm').css('opacity', '1').find('.step-text').text('<?php echo esc_js( __( 'Add BGM:', 'bizcity-video-kling' ) ); ?> ' + bgmName);
        }
        
        // Effects step
        if (!ffmpegPreset) {
            $('.step-effects').css('opacity', '0.4').find('.step-text').html('<s><?php echo esc_js( __( 'Skip Video Effects', 'bizcity-video-kling' ) ); ?></s>');
        } else {
            var effectName = $('#ffmpeg_preset option:selected').text();
            $('.step-effects').css('opacity', '1').find('.step-text').text('<?php echo esc_js( __( 'Apply Effect:', 'bizcity-video-kling' ) ); ?> ' + effectName);
        }
    }
    
    // Initial steps preview update
    updateStepsPreview();
    
    // Update on relevant changes
    $('#ffmpeg_preset').on('change', updateStepsPreview);
});
</script>
