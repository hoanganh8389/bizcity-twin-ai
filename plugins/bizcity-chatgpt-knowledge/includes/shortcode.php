<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/* ================================================================
 * Shortcode: [bizcity_chatgpt_knowledge]
 * Embeddable ChatGPT Knowledge chat / Q&A widget
 * ================================================================ */
add_shortcode( 'bizcity_chatgpt_knowledge', 'bzck_render_shortcode' );

function bzck_render_shortcode( $atts = [] ) {
    $atts = shortcode_atts( [
        'theme'       => 'light',
        'placeholder' => 'Hỏi bất cứ điều gì...',
        'show_topics' => 'yes',
    ], $atts, 'bizcity_chatgpt_knowledge' );

    wp_enqueue_style( 'bzck-public' );
    wp_enqueue_script( 'bzck-public' );

    wp_localize_script( 'bzck-public', 'BZCK_PUB', [
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'bzck_pub_nonce' ),
        'user_id'  => get_current_user_id(),
    ] );

    $topics = bzck_get_topics();

    ob_start();
    ?>
    <div id="bzck-app" class="bzck-app bzck-theme-<?php echo esc_attr( $atts['theme'] ); ?>">
        <div class="bzck-header">
            <span class="bzck-header-icon">🧠</span>
            <h2>ChatGPT Knowledge</h2>
            <p>Trợ lý kiến thức AI — Powered by OpenAI</p>
        </div>

        <?php if ( $atts['show_topics'] === 'yes' ) : ?>
            <div class="bzck-topics" id="bzck-topics">
                <?php foreach ( array_slice( $topics, 0, 6 ) as $t ) : ?>
                    <div class="bzck-topic-chip" data-questions='<?php echo esc_attr( wp_json_encode( $t['questions'] ) ); ?>'>
                        <span class="bzck-chip-icon"><?php echo $t['icon']; ?></span>
                        <?php echo esc_html( $t['label'] ); ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="bzck-answer-area" id="bzck-answer-area" style="display:none">
            <div class="bzck-answer-content" id="bzck-answer-content"></div>
            <div class="bzck-answer-meta" id="bzck-answer-meta"></div>
        </div>

        <div class="bzck-input-area">
            <textarea id="bzck-input" rows="2" placeholder="<?php echo esc_attr( $atts['placeholder'] ); ?>"></textarea>
            <button id="bzck-send-btn" class="bzck-send-btn" title="Gửi">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4z"/></svg>
            </button>
        </div>

        <div class="bzck-loading" id="bzck-loading" style="display:none">
            <div class="bzck-spinner"></div>
            <span>ChatGPT đang suy nghĩ...</span>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
