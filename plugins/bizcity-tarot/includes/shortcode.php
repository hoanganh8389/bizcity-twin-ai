<?php
/**
 * BizCity Tarot – Frontend Shortcode [bizcity_tarot]
 *
 * @package BizCity_Tarot
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_shortcode( 'bizcity_tarot', 'bct_shortcode' );

function bct_shortcode( $atts ): string {
    $atts = shortcode_atts( [
        'cards'          => get_option( 'bct_cards_to_pick', 3 ),
        'show_topics'    => 1,
        'show_questions' => 1,
        'show_reversed'  => get_option( 'bct_show_reversed', 1 ),
    ], $atts, 'bizcity_tarot' );

    // Enqueue assets
    wp_enqueue_style( 'bct-public' );
    wp_enqueue_script( 'bct-public' );

    // Resolve create-agent URL (same pattern as frontend-astro-landing)
    $create_agent_url = get_option( 'bizcity_create_agent_url', '' );
    if ( empty( $create_agent_url ) ) {
        global $wpdb;
        $page_id = $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_status='publish' AND post_type='page' AND post_content LIKE '%shortcode_create_site%' LIMIT 1" );
        $create_agent_url = $page_id ? get_permalink( $page_id ) : home_url( '/dung-thu-mien-phi/' );
    }

    // ── Chat context: nếu user đến từ link bốc bài do bot gửi ───────────
    $chat_ctx   = function_exists( 'bct_get_current_chat_context' ) ? bct_get_current_chat_context() : null;
    $bct_token  = sanitize_text_field( wp_unslash( $_GET['bct_token'] ?? '' ) );

    // Câu nhắn gốc user gõ trong chat (lưu trong token payload)
    $user_message       = '';
    $preselect_topic    = '';
    $preselect_question = '';
    if ( $chat_ctx && ! empty( $chat_ctx['user_message'] ) ) {
        $user_message = sanitize_text_field( $chat_ctx['user_message'] );
        if ( function_exists( 'bct_match_topic_question' ) ) {
            $match              = bct_match_topic_question( $user_message );
            $preselect_topic    = $match['topic']    ?? '';
            $preselect_question = $match['question'] ?? '';
        }
    }

    wp_localize_script( 'bct-public', 'BCT_PUB', [
        'ajax_url'         => admin_url( 'admin-ajax.php' ),
        'nonce'            => wp_create_nonce( 'bct_pub_nonce' ),
        'cards_to_pick'    => (int) $atts['cards'],
        'show_reversed'    => (int) $atts['show_reversed'],
        'is_logged'        => is_user_logged_in() ? 1 : 0,
        'create_agent_url' => esc_url( $create_agent_url ),
        'site_url'         => get_site_url(),
        // Chat integration
        'bct_token'          => $bct_token,
        'has_chat_ctx'       => $chat_ctx ? 1 : 0,
        'chat_id'            => $chat_ctx ? esc_attr( $chat_ctx['chat_id'] ?? '' ) : '',
        // Prompt gốc user gõ + gợi ý preselect
        'user_message'       => $user_message,
        'preselect_topic'    => $preselect_topic,
        'preselect_question' => $preselect_question,
    ] );

    // Get all card data from DB
    global $wpdb;
    $t     = bct_tables();
    $cards = $wpdb->get_results( "SELECT id, card_slug, card_name_en, card_name_vi, image_url FROM {$t['cards']} ORDER BY sort_order ASC" );

    $topics        = bct_get_topics();
    $cards_to_pick = (int) $atts['cards'];

    ob_start();
    ?>
    <div class="bct-tarot-wrap" id="bct-tarot-app">

        <!-- ===== CHAT MODE BANNER ===== -->
        <?php if ( $chat_ctx ) : ?>
        <div class="bct-chat-mode-banner">
            💬 Kết quả luận giải sẽ được gửi tự động về tin nhắn của bạn sau khi bốc bài xong.
        </div>
        <?php endif; ?>

        <!-- ===== HEADER ===== -->
        <div class="bct-header">
            <?php if ( $user_message ) :
                $phrase_display = function_exists( 'bct_extract_topic_phrase' ) ? bct_extract_topic_phrase( $user_message ) : $user_message;
            ?>
                <p class="bct-header-sub">Nhận được câu trả lời bạn cần về &#8220;<?php echo esc_html( $phrase_display ); ?>&#8221;:</p>
            <?php else : ?>
                <p class="bct-header-sub">Nhận được câu trả lời bạn cần với</p>
            <?php endif; ?>
            <p class="bct-header-count"><?php echo esc_html( $cards_to_pick ); ?></p>
            <p class="bct-header-label">lá bài Tarot</p>
        </div>

        <!-- ===== TOPIC / QUESTION / SHUFFLE ROW ===== -->
        <?php if ( $atts['show_topics'] ) : ?>
        <div class="bct-controls" id="bct-controls">

            <!-- Topic -->
            <div class="bct-control-box">
                <div class="bct-select-wrap">
                    <select name="bct_topic" id="bct-topic" onchange="bctUpdateQuestions()">
                        <option value="">🌟 Chọn chủ đề</option>
                        <?php foreach ( bct_get_topic_categories() as $cat_key => $cat ) : ?>
                            <optgroup label="<?php echo esc_attr( $cat['icon'] . ' ' . $cat['label'] ); ?>">
                                <?php foreach ( $topics as $topic ) : ?>
                                    <?php if ( $topic['category'] === $cat_key ) : ?>
                                        <option value="<?php echo esc_attr( $topic['value'] ); ?>">
                                            <?php echo esc_html( $topic['icon'] . ' ' . $topic['label'] ); ?>
                                        </option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Question -->
            <?php if ( $atts['show_questions'] ) : ?>
            <div class="bct-control-box">
                <div class="bct-select-wrap">
                    <select name="bct_question" id="bct-question">
                        <option value="">💬 Chọn câu hỏi</option>
                    </select>
                </div>
            </div>
            <?php endif; ?>

            <!-- Cards Count -->
            <div class="bct-control-box bct-count-picker-box">
                <p class="bct-count-label">🃏 Số lá bài:</p>
                <div class="bct-count-picker" id="bct-count-picker">
                    <?php for ( $n = 1; $n <= 7; $n++ ) : ?>
                        <button type="button"
                                class="bct-count-btn<?php echo $n === $cards_to_pick ? ' is-active' : ''; ?>"
                                data-count="<?php echo $n; ?>"
                                onclick="bctSetCardsCount(<?php echo $n; ?>)">
                            <?php echo $n; ?>
                        </button>
                    <?php endfor; ?>
                </div>
            </div>

            <!-- Shuffle Button -->
            <div class="bct-control-box">
                <button type="button" class="bct-btn-shuffle" id="bct-btn-shuffle"
                        onclick="bctShuffleAndDeal()" disabled>
                    🔀 Xào bài
                </button>
            </div>

        </div>
        <?php endif; ?>

        <!-- ===== TUTORIAL HINT ===== -->
        <div class="bct-tutorial" id="bct-tutorial">
            <div class="bct-tutorial-inner">
                <span class="bct-tutorial-step" id="bct-step-num">1</span>/
                <span id="bct-step-total">6</span>
                <span id="bct-tutorial-text" class="bct-tutorial-text">Hãy chọn chủ đề Tarot của bạn</span>
            </div>
        </div>

        <!-- ===== CARD DECK ===== -->
        <div id="bct-pack" class="bct-pack">
            <?php foreach ( $cards as $i => $card ) : ?>
                <div class="bct-card"
                     id="card-<?php echo (int) $card->id; ?>"
                     data-id="<?php echo (int) $card->id; ?>"
                     data-slug="<?php echo esc_attr( $card->card_slug ); ?>"
                     data-name="<?php echo esc_attr( $card->card_name_en ); ?>"
                     data-name-vi="<?php echo esc_attr( $card->card_name_vi ); ?>"
                     data-img="<?php echo esc_url( $card->image_url ); ?>"
                     style="z-index:<?php echo $i; ?>;--card-offset:<?php echo $i * 0.2; ?>px">
                    <div class="bct-card-inner">
                        <div class="bct-card-back"></div>
                        <div class="bct-card-front">
                            <?php if ( $card->image_url ) : ?>
                                <img src="<?php echo esc_url( $card->image_url ); ?>"
                                     alt="<?php echo esc_attr( $card->card_name_en ); ?>"
                                     loading="lazy">
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- ===== SELECTED CARDS DISPLAY ===== -->
        <div id="bct-selected-zone" class="bct-selected-zone" style="display:none">
            <p class="bct-selected-title">Lá bài bạn đã chọn</p>
            <div class="bct-selected-cards" id="bct-selected-cards">
                <!-- Filled by JS -->
            </div>
        </div>

        <!-- ===== REVEAL BUTTON ===== -->
        <div class="bct-reveal-wrap" id="bct-reveal-wrap" style="display:none">
            <button type="button" class="bct-btn-reveal" id="bct-btn-reveal" onclick="bctRevealMeaning()">
                ✨ Tiết lộ ý nghĩa
            </button>
        </div>

        <!-- ===== MEANING PANEL ===== -->
        <div id="bct-meaning-panel" class="bct-meaning-panel" style="display:none">
            <div class="bct-meaning-header">
                <h3 id="bct-meaning-topic"></h3>
                <p id="bct-meaning-question"></p>
            </div>
            <div id="bct-meaning-cards" class="bct-meaning-cards">
                <!-- Filled by JS -->
            </div>

            <!-- ===== AI INTERPRETATION PANEL ===== -->
            <div id="bct-ai-panel" class="bct-ai-panel" style="display:none">
                <div class="bct-ai-header">
                    <span class="bct-ai-icon">🤖</span>
                    <h4>Luận giải bởi AI</h4>
                </div>
                <div id="bct-ai-content" class="bct-ai-content">
                    <!-- Filled by JS -->
                </div>
                <div id="bct-ai-cta" class="bct-ai-cta" style="display:none">
                    <!-- CTA filled by JS for guest users -->
                </div>
            </div>

            <div class="bct-meaning-footer">
                <button type="button" class="bct-btn-reset" onclick="bctReset()">
                    🔄 Trải bài mới
                </button>
            </div>
        </div>

    </div><!-- .bct-tarot-wrap -->

    <!-- Topics/Questions JSON data for JS -->
    <script id="bct-topics-data" type="application/json">
    <?php echo wp_json_encode( $topics, JSON_UNESCAPED_UNICODE ); ?>
    </script>

    <script id="bct-labels-data" type="application/json">
    <?php echo wp_json_encode( [
        'pos_labels' => [
            1  => 'Lá bài',
            3  => [ 'Quá khứ', 'Hiện tại', 'Tương lai' ],
            5  => [ 'Quá khứ', 'Hiện tại', 'Tương lai', 'Lời khuyên', 'Kết quả' ],
            7  => [ 'Bản thân', 'Tình huống', 'Quá khứ', 'Tương lai', 'Thách thức', 'Kết quả', 'Lời khuyên' ],
            10 => [ 'Hiện tại', 'Thách thức', 'Quá khứ xa', 'Quá khứ gần', 'Tiềm thức', 'Tương lai gần', 'Bản thân', 'Môi trường', 'Hy vọng/Lo ngại', 'Kết quả' ],
        ],
    ], JSON_UNESCAPED_UNICODE ); ?>
    </script>
    <?php
    return ob_get_clean();
}
