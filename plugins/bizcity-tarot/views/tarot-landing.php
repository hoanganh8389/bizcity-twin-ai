<?php
/**
 * BizCity Tarot – Frontend Landing Page
 *
 * Provides:
 *   [bizcity_tarot_landing] — Trang landing bói bài Tarot (guest + member)
 *
 * Includes:
 *   - Giới thiệu + hướng dẫn
 *   - Shortcode [bizcity_tarot] nhúng trực tiếp
 *   - Sau khi xem bài: CTA tạo AI Agent chiêm tinh cá nhân
 *   - Đăng nhập: chạy qua Chat Gateway để luận giải chuyên sâu
 *
 * Template page: "Bói Bài Tarot (BizCity)" — có thể chọn trong Page Attributes.
 *
 * @package BizCity_Tarot
 * @since   1.0.1
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/* =====================================================================
 * 1. SHORTCODE: [bizcity_tarot_landing]
 * =====================================================================*/
add_shortcode( 'bizcity_tarot_landing', 'bct_landing_shortcode' );

function bct_landing_shortcode( $atts ) {
    $atts = shortcode_atts( [
        'title'          => 'Bói Bài Tarot Online',
        'subtitle'       => 'Nhận thông điệp từ vũ trụ dành riêng cho bạn',
        'cards'          => get_option( 'bct_cards_to_pick', 3 ),
        'show_steps'     => 1,
    ], $atts, 'bizcity_tarot_landing' );

    // Resolve create-agent URL (same logic as frontend-astro-landing)
    $create_agent_url = get_option( 'bizcity_create_agent_url', '' );
    if ( empty( $create_agent_url ) ) {
        global $wpdb;
        $page_id = $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_status='publish' AND post_type='page' AND post_content LIKE '%shortcode_create_site%' LIMIT 1" );
        $create_agent_url = $page_id ? get_permalink( $page_id ) : home_url( '/dung-thu-mien-phi/' );
    }
    $create_agent_url_astro = add_query_arg( 'astro', '1', $create_agent_url );

    $is_logged_in = is_user_logged_in();

    // Enqueue landing CSS (registered in bct_register_assets)
    wp_enqueue_style( 'bct-tarot-landing' );

    ob_start();
    ?>
    <div class="bct-lp-page">

        <!-- ── STARFIELD BACKGROUND ── -->
        <div class="bct-lp-starfield"></div>

        <div class="bct-lp-container">

            <!-- ── HERO ── -->
            <div class="bct-lp-hero">
                <div class="bct-lp-hero-icon">🔮</div>
                <h1 class="bct-lp-title"><?php echo esc_html( $atts['title'] ); ?></h1>
                <p class="bct-lp-subtitle"><?php echo esc_html( $atts['subtitle'] ); ?></p>

                <?php if ( $is_logged_in ) : ?>
                    <div class="bct-lp-logged-badge">
                        ✅ Đã đăng nhập — AI sẽ luận giải theo hồ sơ chiêm tinh cá nhân của bạn
                    </div>
                <?php else : ?>
                    <div class="bct-lp-guest-badge">
                        👤 Đang xem với tư cách khách — <a href="<?php echo esc_url( $create_agent_url ); ?>">Tạo tài khoản miễn phí</a> để nhận luận giải cá nhân hoá
                    </div>
                <?php endif; ?>
            </div>

            <?php if ( $atts['show_steps'] ) : ?>
            <!-- ── HOW IT WORKS ── -->
            <div class="bct-lp-steps">
                <div class="bct-lp-step">
                    <div class="bct-lp-step-icon">🌟</div>
                    <div class="bct-lp-step-text">
                        <strong>Chọn chủ đề & câu hỏi</strong>
                        <span>Tài chính, tình yêu, công việc…</span>
                    </div>
                </div>
                <div class="bct-lp-step-arrow">→</div>
                <div class="bct-lp-step">
                    <div class="bct-lp-step-icon">🃏</div>
                    <div class="bct-lp-step-text">
                        <strong>Xào bài &amp; chọn <?php echo (int) $atts['cards']; ?> lá</strong>
                        <span>Tập trung tâm trí khi chọn</span>
                    </div>
                </div>
                <div class="bct-lp-step-arrow">→</div>
                <div class="bct-lp-step">
                    <div class="bct-lp-step-icon">🤖</div>
                    <div class="bct-lp-step-text">
                        <strong>AI luận giải</strong>
                        <span>Phân tích chuyên sâu theo bài bốc</span>
                    </div>
                </div>
                <?php if ( ! $is_logged_in ) : ?>
                <div class="bct-lp-step-arrow">→</div>
                <div class="bct-lp-step">
                    <div class="bct-lp-step-icon">🌙</div>
                    <div class="bct-lp-step-text">
                        <strong>Tạo AI Agent cá nhân</strong>
                        <span>Luận giải theo transit chòm sao &amp; bản đồ sao</span>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- ── TAROT SHORTCODE ── -->
            <div class="bct-lp-tarot-wrap">
                <?php echo do_shortcode( '[bizcity_tarot cards="' . (int) $atts['cards'] . '"]' ); ?>
            </div>

            <!-- ── GROWTH CTA (for guest users) ── -->
            <?php if ( ! $is_logged_in ) : ?>
            <div class="bct-lp-growth-cta">
                <div class="bct-lp-cta-icon">🌟</div>
                <h2 class="bct-lp-cta-title">Nhận luận giải chính xác hơn với AI cá nhân hoá</h2>
                <p class="bct-lp-cta-sub">
                    Tôi là AI theo từng cá nhân. Để luận giải chính xác, tôi cần
                    <strong>hồ sơ chiêm tinh của bạn</strong> để đo vị trí transit chòm sao
                    vào thời điểm bạn bốc bài, và thông tin cá nhân để ra kết quả thật sự dành riêng cho bạn.
                </p>
                <div class="bct-lp-cta-buttons">
                    <a href="<?php echo esc_url( $create_agent_url_astro ); ?>" class="bct-lp-btn-primary">
                        🌙 Tạo Bản Đồ Sao &amp; AI Agent
                        <span class="bct-lp-btn-badge">Miễn phí</span>
                    </a>
                    <a href="<?php echo esc_url( $create_agent_url ); ?>" class="bct-lp-btn-secondary">
                        🤖 Tạo AI Agent nhanh
                    </a>
                </div>

                <div class="bct-lp-features">
                    <div class="bct-lp-feature">
                        <span class="bct-lp-feature-icon">🔮</span>
                        <strong>Tarot + Chiêm tinh</strong>
                        <span>Kết hợp bài bốc với vị trí sao thực tế</span>
                    </div>
                    <div class="bct-lp-feature">
                        <span class="bct-lp-feature-icon">💜</span>
                        <strong>AI cá nhân hoá</strong>
                        <span>Nhớ lịch sử và hiểu bạn theo thời gian</span>
                    </div>
                    <div class="bct-lp-feature">
                        <span class="bct-lp-feature-icon">📊</span>
                        <strong>Transit thời gian thực</strong>
                        <span>Sao đang ở đâu khi bạn bốc bài hôm nay</span>
                    </div>
                    <div class="bct-lp-feature">
                        <span class="bct-lp-feature-icon">🚀</span>
                        <strong>Thiết lập trong vài giây</strong>
                        <span>Hoàn toàn miễn phí, không cần thẻ</span>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- ── FOOTER ── -->
            <div class="bct-lp-footer">
                <p>💜 Powered by <strong>BizCity Tarot</strong> × <strong>BizCity AI</strong> — Bói bài Tarot kết hợp chiêm tinh học</p>
            </div>

        </div><!-- /.bct-lp-container -->
    </div><!-- /.bct-lp-page -->
    <?php
    return ob_get_clean();
}

/* =====================================================================
 * 2. PAGE TEMPLATE REGISTRATION
 * =====================================================================*/
add_filter( 'theme_page_templates', function ( $templates ) {
    $templates['bct-tarot-landing'] = 'Bói Bài Tarot (BizCity)';
    return $templates;
} );

add_filter( 'template_include', function ( $template ) {
    if ( is_page() ) {
        $page_template = get_post_meta( get_the_ID(), '_wp_page_template', true );
        if ( 'bct-tarot-landing' === $page_template ) {
            $custom = BCT_DIR . 'views/page-tarot-full.php';
            if ( file_exists( $custom ) ) {
                return $custom;
            }
        }
    }
    return $template;
} );
