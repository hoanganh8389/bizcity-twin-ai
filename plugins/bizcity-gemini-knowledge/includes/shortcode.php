<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/* ================================================================
 * Shortcode: [bizcity_knowledge]
 * Embeddable Gemini Knowledge chat / Q&A widget
 * ================================================================ */
add_shortcode( 'bizcity_knowledge', 'bzgk_render_shortcode' );

function bzgk_render_shortcode( $atts = [] ) {
    $atts = shortcode_atts( [
        'theme'       => 'light',
        'placeholder' => 'Hỏi bất cứ điều gì...',
        'show_topics' => 'yes',
    ], $atts, 'bizcity_knowledge' );

    wp_enqueue_style( 'bzgk-public' );
    wp_enqueue_script( 'bzgk-public' );

    wp_localize_script( 'bzgk-public', 'BZGK_PUB', [
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'bzgk_pub_nonce' ),
        'user_id'  => get_current_user_id(),
    ] );

    $topics = bzgk_get_topics();

    ob_start();
    ?>
    <div id="bzgk-app" class="bzgk-app bzgk-theme-<?php echo esc_attr( $atts['theme'] ); ?>">
        <!-- Header -->
        <div class="bzgk-header">
            <span class="bzgk-header-icon">🧠</span>
            <h2>Gemini Knowledge</h2>
            <p>Trợ lý kiến thức AI — Hỏi bất cứ điều gì</p>
        </div>

        <!-- Suggested Topics -->
        <?php if ( $atts['show_topics'] === 'yes' ) : ?>
            <div class="bzgk-topics" id="bzgk-topics">
                <?php foreach ( array_slice( $topics, 0, 6 ) as $t ) : ?>
                    <div class="bzgk-topic-chip" data-questions='<?php echo esc_attr( wp_json_encode( $t['questions'] ) ); ?>'>
                        <span class="bzgk-chip-icon"><?php echo $t['icon']; ?></span>
                        <?php echo esc_html( $t['label'] ); ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Answer Area -->
        <div class="bzgk-answer-area" id="bzgk-answer-area" style="display:none">
            <div class="bzgk-answer-content" id="bzgk-answer-content"></div>
            <div class="bzgk-answer-meta" id="bzgk-answer-meta"></div>
        </div>

        <!-- Input Area -->
        <div class="bzgk-input-area">
            <textarea id="bzgk-input" rows="2" placeholder="<?php echo esc_attr( $atts['placeholder'] ); ?>"></textarea>
            <button id="bzgk-send-btn" class="bzgk-send-btn" title="Gửi">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4z"/></svg>
            </button>
        </div>

        <!-- Loading -->
        <div class="bzgk-loading" id="bzgk-loading" style="display:none">
            <div class="bzgk-spinner"></div>
            <span>Gemini đang suy nghĩ...</span>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
