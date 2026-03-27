<?php
/**
 * Landing page template.
 *
 * @package BizCity_ChatGPT_Knowledge
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_shortcode( 'bizcity_chatgpt_knowledge_landing', 'bzck_render_landing' );

function bzck_render_landing( $atts = [] ) {
    wp_enqueue_style( 'bzck-public' );

    $topics = bzck_get_topics();

    ob_start();
    ?>
    <div class="bzck-landing">
        <div class="bzck-landing-hero" style="text-align:center;padding:40px 20px">
            <div style="font-size:64px">🧠</div>
            <h1 style="margin:16px 0 8px">ChatGPT Knowledge</h1>
            <p style="color:#6b7280;font-size:16px">
                Trợ lý kiến thức AI chuyên sâu — Powered by OpenAI ChatGPT.<br>
                Trả lời chi tiết, đầy đủ, chính xác.
            </p>
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:20px;padding:20px">
            <div style="background:#f0fdf4;padding:20px;border-radius:12px;text-align:center">
                <span style="font-size:32px">💡</span>
                <h3>Kiến thức đa lĩnh vực</h3>
                <p style="color:#6b7280;font-size:13px">Công nghệ, kinh doanh, sức khỏe, giáo dục & nhiều hơn</p>
            </div>
            <div style="background:#f0fdf4;padding:20px;border-radius:12px;text-align:center">
                <span style="font-size:32px">📝</span>
                <h3>Trả lời chuyên sâu</h3>
                <p style="color:#6b7280;font-size:13px">Chi tiết, có cấu trúc, ví dụ cụ thể</p>
            </div>
            <div style="background:#f0fdf4;padding:20px;border-radius:12px;text-align:center">
                <span style="font-size:32px">🔍</span>
                <h3>Phân tích thông minh</h3>
                <p style="color:#6b7280;font-size:13px">So sánh, đánh giá, tư vấn chiến lược</p>
            </div>
            <div style="background:#f0fdf4;padding:20px;border-radius:12px;text-align:center">
                <span style="font-size:32px">🎯</span>
                <h3>Cá nhân hóa</h3>
                <p style="color:#6b7280;font-size:13px">Nhớ sở thích, điều chỉnh câu trả lời</p>
            </div>
        </div>

        <div style="padding:20px">
            <h2 style="text-align:center">📚 Chủ đề phổ biến</h2>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:16px">
                <?php foreach ( $topics as $t ) : ?>
                    <div style="background:#f9fafb;padding:16px;border-radius:10px;border:1px solid #e5e7eb">
                        <span style="font-size:24px"><?php echo $t['icon']; ?></span>
                        <strong style="display:block;margin:8px 0"><?php echo esc_html( $t['label'] ); ?></strong>
                        <ul style="margin:0;padding-left:16px;font-size:13px;color:#6b7280">
                            <?php foreach ( array_slice( $t['questions'], 0, 2 ) as $q ) : ?>
                                <li><?php echo esc_html( $q ); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div style="text-align:center;padding:20px;color:#9ca3af">
            <p>Bắt đầu hỏi ngay — Chat với AI hoặc dùng trang Hỏi đáp</p>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
