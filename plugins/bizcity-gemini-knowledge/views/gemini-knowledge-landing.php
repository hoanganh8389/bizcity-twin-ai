<?php
/**
 * Landing page template — registered via add_shortcode('bizcity_knowledge_landing').
 *
 * @package BizCity_Gemini_Knowledge
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_shortcode( 'bizcity_knowledge_landing', 'bzgk_render_landing' );

function bzgk_render_landing( $atts = [] ) {
    wp_enqueue_style( 'bzgk-public' );

    $topics = bzgk_get_topics();

    ob_start();
    ?>
    <div class="bzgk-landing">
        <div class="bzgk-landing-hero">
            <div class="bzgk-landing-icon">🧠</div>
            <h1>Gemini Knowledge</h1>
            <p class="bzgk-landing-desc">
                Trợ lý kiến thức AI chuyên sâu — Powered by Google Gemini.<br>
                Trả lời chi tiết, đầy đủ, chính xác như ChatGPT.
            </p>
        </div>

        <div class="bzgk-landing-features">
            <div class="bzgk-feature">
                <span>💡</span>
                <h3>Kiến thức đa lĩnh vực</h3>
                <p>Công nghệ, kinh doanh, sức khỏe, giáo dục, pháp luật & nhiều hơn</p>
            </div>
            <div class="bzgk-feature">
                <span>📝</span>
                <h3>Trả lời chuyên sâu</h3>
                <p>Chi tiết, có cấu trúc, ví dụ cụ thể — không vắn tắt</p>
            </div>
            <div class="bzgk-feature">
                <span>🔍</span>
                <h3>Phân tích thông minh</h3>
                <p>So sánh, đánh giá, tư vấn chiến lược dựa trên dữ liệu</p>
            </div>
            <div class="bzgk-feature">
                <span>🎯</span>
                <h3>Cá nhân hóa</h3>
                <p>Nhớ sở thích, điều chỉnh câu trả lời phù hợp với bạn</p>
            </div>
        </div>

        <div class="bzgk-landing-topics">
            <h2>📚 Chủ đề phổ biến</h2>
            <div class="bzgk-topics-grid">
                <?php foreach ( $topics as $t ) : ?>
                    <div class="bzgk-topic-card">
                        <span class="bzgk-topic-icon"><?php echo $t['icon']; ?></span>
                        <strong><?php echo esc_html( $t['label'] ); ?></strong>
                        <ul>
                            <?php foreach ( array_slice( $t['questions'], 0, 2 ) as $q ) : ?>
                                <li><?php echo esc_html( $q ); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="bzgk-landing-cta">
            <p>Bắt đầu hỏi ngay — Chat với AI hoặc dùng trang Hỏi đáp</p>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
