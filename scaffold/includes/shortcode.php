<?php
/**
 * Shortcode [bizcity_{slug}] — renders the main frontend UI.
 *
 * @package BizCity_{Name}
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_shortcode( 'bizcity_{slug}', 'bz{prefix}_shortcode' );
function bz{prefix}_shortcode( $atts ) {
    $atts = shortcode_atts( array(
        'items_count'    => get_option( 'bz{prefix}_items_count', 3 ),
        'show_topics'    => 1,
        'show_questions' => 1,
    ), $atts, 'bizcity_{slug}' );

    // 1. Enqueue assets
    wp_enqueue_style( 'bz{prefix}-public' );
    wp_enqueue_script( 'bz{prefix}-public' );

    // 2. Localize JS
    wp_localize_script( 'bz{prefix}-public', 'BZ{PREFIX}_PUB', array(
        'ajax_url'     => admin_url( 'admin-ajax.php' ),
        'nonce'        => wp_create_nonce( 'bz{prefix}_pub_nonce' ),
        'items_count'  => (int) $atts['items_count'],
        'is_logged'    => is_user_logged_in() ? 1 : 0,
        'site_url'     => get_site_url(),
        'token'        => sanitize_text_field( isset( $_GET['bz{prefix}_token'] ) ? $_GET['bz{prefix}_token'] : '' ),
        'has_chat_ctx' => ! empty( $_GET['bz{prefix}_token'] ) ? 1 : 0,
    ) );

    // 3. Query items
    global $wpdb;
    $t     = bz{prefix}_tables();
    $items = $wpdb->get_results( "SELECT * FROM {$t['items']} ORDER BY sort_order", ARRAY_A );

    // 4. Render
    ob_start();
    ?>
    <div class="bz{prefix}-app"
         data-count="<?php echo esc_attr( $atts['items_count'] ); ?>"
         data-show-topics="<?php echo esc_attr( $atts['show_topics'] ); ?>">

        <?php if ( $atts['show_topics'] ) : ?>
        <!-- Topic Selector -->
        <div class="bz{prefix}-topics">
            <h3>Chọn chủ đề</h3>
            <div class="bz{prefix}-topic-grid" id="bz{prefix}-topics"></div>
        </div>
        <?php endif; ?>

        <?php if ( $atts['show_questions'] ) : ?>
        <!-- Suggested Questions -->
        <div class="bz{prefix}-questions" id="bz{prefix}-questions" style="display:none;"></div>
        <?php endif; ?>

        <!-- Items Grid -->
        <div class="bz{prefix}-items">
            <h3>Chọn <?php echo esc_html( $atts['items_count'] ); ?> mục</h3>
            <div class="bz{prefix}-item-grid" id="bz{prefix}-items">
                <?php foreach ( $items as $item ) : ?>
                <div class="bz{prefix}-item" data-slug="<?php echo esc_attr( $item['slug'] ); ?>">
                    <?php if ( $item['image_url'] ) : ?>
                    <img src="<?php echo esc_url( $item['image_url'] ); ?>"
                         alt="<?php echo esc_attr( $item['name_vi'] ); ?>"
                         loading="lazy" />
                    <?php endif; ?>
                    <span><?php echo esc_html( $item['name_vi'] ); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Result Panel -->
        <div class="bz{prefix}-result" id="bz{prefix}-result" style="display:none;">
            <h3>Kết quả</h3>
            <div id="bz{prefix}-result-content"></div>
        </div>

        <!-- AI Interpretation Panel -->
        <div class="bz{prefix}-ai" id="bz{prefix}-ai" style="display:none;">
            <h3>🤖 Phân tích AI</h3>
            <div id="bz{prefix}-ai-content"></div>
            <button id="bz{prefix}-ai-btn" class="bz{prefix}-btn">Yêu cầu phân tích AI</button>
        </div>
    </div>

    <!-- Data for JS -->
    <script type="application/json" id="bz{prefix}-topics-data">
        <?php echo wp_json_encode( bz{prefix}_get_topics() ); ?>
    </script>
    <?php
    return ob_get_clean();
}
