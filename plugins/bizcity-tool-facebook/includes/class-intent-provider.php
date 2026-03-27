<?php
/**
 * BizCity Tool Facebook — Intent Provider
 *
 * Handles intent detection for Facebook posting goals.
 * Tone auto-detection from user input for optimal content generation.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class BizCity_Tool_Facebook_Intent_Provider extends BizCity_Intent_Provider {

    /** Tone detection patterns */
    const TONE_PATTERNS = [
        'promotional'  => '/khuyến mãi|sale|giảm giá|ưu đãi|flash sale|voucher|coupon|discount|mua ngay|đặt hàng/ui',
        'storytelling' => '/kể chuyện|story|câu chuyện|chia sẻ kinh nghiệm|trải nghiệm|hành trình|journey/ui',
        'professional' => '/chuyên nghiệp|professional|B2B|doanh nghiệp|báo cáo|report|phân tích|analysis/ui',
        'friendly'     => '/thân thiện|friendly|casual|vui vẻ|nhẹ nhàng|gần gũi/ui',
    ];

    public function get_id()   { return 'tool-facebook'; }
    public function get_name() { return 'BizCity Tool — Facebook AI'; }

    public function get_goal_patterns() {
        return [
            /* List posts */
            '/danh sách.*facebook|list.*facebook.*post|bài.*facebook.*gần đây|xem.*bài.*fb|bài đã đăng fb/ui' => [
                'goal'    => 'list_facebook_posts',
                'label'   => 'Xem danh sách bài FB đã đăng',
                'extract' => [ 'limit' ],
            ],
            /* Primary: Create & post */
            '/đăng facebook|đăng fb|post facebook|tạo bài facebook|viết bài fb|đăng lên facebook|chia sẻ.*fb|chia sẻ.*facebook|share.*facebook|đăng fanpage|post.*fanpage|bài.*facebook|nội dung.*facebook|content.*facebook/ui' => [
                'goal'    => 'create_facebook_post',
                'label'   => 'Tạo & đăng bài Facebook',
                'extract' => [ 'topic', 'image_url', 'page_id', 'tone' ],
            ],
        ];
    }

    public function get_plans() {
        return [
            'create_facebook_post' => [
                'required_slots' => [
                    'topic' => [
                        'type'        => 'text',
                        'prompt'      => '📝 Bạn muốn đăng bài về chủ đề gì? Mô tả càng chi tiết (tone, đối tượng, CTA) thì bài càng hay!',
                        'no_auto_map' => true,
                    ],
                ],
                'optional_slots' => [
                    'image_url' => [ 'type' => 'image', 'prompt' => '🖼️ Gửi URL ảnh hoặc upload ảnh (bỏ qua để AI tự tạo):' ],
                    'tone'      => [ 'type' => 'text' ],
                    'page_id'   => [ 'type' => 'text' ],
                ],
                'tool'           => 'create_facebook_post',
                'ai_compose'     => false,
                'slot_order'     => [ 'topic', 'image_url', 'tone' ],
            ],
            'list_facebook_posts' => [
                'required_slots' => [],
                'optional_slots' => [ 'limit' ],
                'tool'           => 'list_facebook_posts',
                'ai_compose'     => false,
                'slot_order'     => [],
            ],
        ];
    }

    public function get_tools() {
        return [
            'create_facebook_post' => [ 'label' => 'Tạo & đăng bài Facebook', 'callback' => [ 'BizCity_Tool_Facebook', 'create_facebook_post' ] ],
            'post_facebook'        => [ 'label' => 'Đăng bài lên Facebook (pipeline)', 'callback' => [ 'BizCity_Tool_Facebook', 'post_facebook' ] ],
            'list_facebook_posts'  => [ 'label' => 'Xem bài FB đã đăng', 'callback' => [ 'BizCity_Tool_Facebook', 'list_facebook_posts' ] ],
        ];
    }

    public function get_mode_patterns() {
        return [
            '/facebook|đăng fb|post fb|fanpage|facebook page|đăng bài fb/ui' => 0.92,
        ];
    }

    /**
     * Auto-detect tone from text.
     */
    public static function detect_tone( string $text ): string {
        foreach ( self::TONE_PATTERNS as $tone => $regex ) {
            if ( preg_match( $regex, $text ) ) {
                return $tone;
            }
        }
        return 'engaging'; // default
    }

    public function build_context( $goal, $slots, $user_id, $conversation ) {
        $model = get_option( 'bztfb_ai_model', 'gpt-4o' );
        $pages = get_option( 'fb_pages_connected', array() );
        $page_names = array();
        if ( is_array( $pages ) ) {
            foreach ( $pages as $p ) {
                $page_names[] = ( $p['name'] ?? '' ) . ' (' . ( $p['id'] ?? '' ) . ')';
            }
        }

        $ctx  = "Plugin: BizCity Tool Facebook (AI Đăng bài)\n";
        $ctx .= "Model AI: {$model}\n";
        $ctx .= "Pages đã kết nối: " . ( $page_names ? implode( ', ', $page_names ) : 'Chưa có' ) . "\n\n";

        $ctx .= "=== QUY TRÌNH ĐĂNG BÀI FACEBOOK ===\n\n";
        $ctx .= "BƯỚC 1: Hỏi chủ đề/nội dung — user mô tả ý tưởng bài viết\n";
        $ctx .= "BƯỚC 2: Hỏi có ảnh kèm không (gửi URL hoặc bỏ qua để AI tự tạo)\n";
        $ctx .= "BƯỚC 3: Xác nhận rồi gọi tool create_facebook_post\n\n";

        $ctx .= "Tones: engaging (viral), professional, friendly, promotional (sale), storytelling\n";
        $ctx .= "Auto-detect tone từ chủ đề nếu user không chỉ rõ.\n\n";

        $ctx .= "Output: wp_post_id + fb_post_ids → pipeline chain.\n";
        $ctx .= "Tool post_facebook: dùng khi đã có content sẵn từ bước trước.\n";
        return $ctx;
    }
}
