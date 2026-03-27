<?php
/**
 * BizCity Tool Image — Intent Provider
 *
 * Two-mode image creation flow:
 *   Mode 1 — From reference images (img2img): user provides 1+ inspiration images
 *   Mode 2 — From text prompt (txt2img): user describes the idea in detail
 *
 * Auto-detect purpose (product / portrait / landscape / social / food / general)
 * from user prompt → enhance with professional photography style prefixes.
 * Model always from settings default → never ask user to pick model.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class BizCity_Tool_Image_Intent_Provider extends BizCity_Intent_Provider {

    /**
     * Creation modes.
     */
    const MODE_REFERENCE = 'reference'; // From inspiration images
    const MODE_TEXT      = 'text';      // From text prompt/idea

    /**
     * Purpose → professional style prefix map.
     */
    const PURPOSE_STYLES = [
        'product'   => 'High-end commercial product photography, place the product in a beautifully styled environment with complementary props and textures, professional studio lighting with soft shadows, cinematic depth of field, luxury brand aesthetic, magazine-quality editorial composition, elegant surface reflections, curated color palette that enhances the product, ',
        'portrait'  => 'Professional portrait photography, soft natural lighting, shallow depth of field, bokeh background, elegant pose, studio quality, ',
        'landscape' => 'Professional landscape photography, golden hour lighting, vivid colors, wide angle, dramatic sky, high dynamic range, ',
        'social'    => 'Eye-catching social media visual, bold colors, modern typography space, trending aesthetic, high contrast, ',
        'food'      => 'Professional food photography, top-down flat lay, warm tones, natural light, appetizing styling, editorial quality, ',
        'general'   => '',
    ];

    /**
     * Regex patterns to auto-detect purpose from prompt text.
     */
    const PURPOSE_PATTERNS = [
        'product'   => '/sản phẩm|product|bán hàng|shop|ecommerce|e-commerce|hàng hóa|catalogue|catalog|thương mại|túi|giày|nước hoa|mỹ phẩm|đồng hồ|quần áo|phụ kiện/ui',
        'portrait'  => '/chân dung|portrait|khuôn mặt|face|headshot|selfie|người mẫu|model chụp|nhân vật|cô gái|chàng trai|avatar|ảnh đại diện|profile photo/ui',
        'landscape' => '/phong cảnh|landscape|thiên nhiên|nature|núi|biển|rừng|hoàng hôn|sunset|bình minh|cityscape|thành phố|ruộng bậc thang|bãi biển/ui',
        'social'    => '/thumbnail|banner|cover|bìa|social|facebook|instagram|youtube|story|post|quảng cáo|ads|marketing/ui',
        'food'      => '/món ăn|food|ẩm thực|đồ ăn|thức uống|drink|coffee|cà phê|bánh|cake|nhà hàng|restaurant|flat lay.*food|food.*flat/ui',
    ];

    /**
     * Regex patterns to detect creation mode from user response.
     */
    const MODE_PATTERNS = [
        self::MODE_REFERENCE => '/từ ảnh|ảnh mẫu|ảnh gốc|ảnh tham chiếu|reference|inspiration|img2img|ảnh cảm hứng|có ảnh|theo ảnh|dựa.*ảnh|upload.*ảnh|gửi.*ảnh|lấy.*ảnh|trường hợp 1|cách 1|option 1|chọn 1/ui',
        self::MODE_TEXT      => '/từ ý tưởng|từ text|prompt|mô tả|ý tưởng|viết|miêu tả|describe|text.*prompt|txt2img|không có ảnh|chưa có ảnh|trường hợp 2|cách 2|option 2|chọn 2/ui',
    ];

    public function get_id()   { return 'tool-image'; }
    public function get_name() { return 'BizCity Tool — Image AI'; }

    public function get_goal_patterns() {
        return [
            /* List images */
            '/danh sách ảnh|list.*image|ảnh.*gần đây|ảnh.*của tôi|my.*image|xem.*ảnh.*đã tạo/ui' => [
                'goal'    => 'list_my_images',
                'label'   => 'Xem danh sách ảnh đã tạo',
                'extract' => [ 'limit' ],
            ],
            /* Primary: Generate image */
            '/tạo ảnh|vẽ ảnh|tạo hình|generate image|tạo hình ảnh|vẽ|minh họa|sinh ảnh|image.*ai|ảnh.*ai|tao anh|ve anh|ảnh.*flux|ảnh.*gemini|dall.?e|gpt.*image|render.*ảnh|seedream/ui' => [
                'goal'    => 'generate_image',
                'label'   => 'Tạo ảnh AI',
                'extract' => [ 'creation_mode', 'prompt', 'image_url', 'purpose', 'size', 'style' ],
            ],
        ];
    }

    public function get_plans() {
        return [
            'generate_image' => [
                'required_slots' => [
                    'creation_mode' => [
                        'type'        => 'text',
                        'prompt'      => "Bạn muốn tạo ảnh theo cách nào?\n1️⃣ **Từ ảnh cảm hứng** — Gửi ảnh mẫu, AI tạo ảnh mới dựa trên phong cách.\n2️⃣ **Từ ý tưởng** — Mô tả chi tiết bằng text, AI tạo ảnh từ đầu.",
                        'no_auto_map' => true,
                    ],
                ],
                'optional_slots' => [
                    'prompt'    => [ 'type' => 'text',  'prompt' => 'Mô tả chi tiết ý tưởng bức ảnh:' ],
                    'image_url' => [ 'type' => 'image', 'prompt' => 'Gửi ảnh cảm hứng (URL hoặc upload):' ],
                    'purpose'   => [ 'type' => 'text' ],
                    'size'      => [ 'type' => 'text' ],
                    'style'     => [ 'type' => 'text' ],
                ],
                'tool'           => 'generate_image',
                'ai_compose'     => false,
                'slot_order'     => [ 'creation_mode', 'image_url', 'prompt', 'purpose' ],
            ],
            'list_my_images' => [
                'required_slots' => [],
                'optional_slots' => [ 'limit' ],
                'tool'           => 'list_my_images',
                'ai_compose'     => false,
                'slot_order'     => [],
            ],
        ];
    }

    public function get_tools() {
        return [
            'generate_image' => [ 'label' => 'Tạo ảnh AI', 'callback' => [ 'BizCity_Tool_Image', 'generate_image' ] ],
            'list_my_images' => [ 'label' => 'Xem ảnh đã tạo', 'callback' => [ 'BizCity_Tool_Image', 'list_my_images' ] ],
        ];
    }

    public function get_mode_patterns() {
        return [
            '/tạo ảnh|vẽ ảnh|generate image|dall.e|image ai|ảnh minh họa|flux|gemini.*image|seedream/ui' => 0.92,
        ];
    }

    /**
     * Detect creation mode from user response text.
     *
     * @return string|null  'reference' or 'text', or null if unclear.
     */
    public static function detect_creation_mode( string $text ): ?string {
        foreach ( self::MODE_PATTERNS as $mode => $regex ) {
            if ( preg_match( $regex, $text ) ) {
                return $mode;
            }
        }
        return null;
    }

    /**
     * Auto-detect purpose from prompt text.
     *
     * @return string|null  Detected purpose key, or null if unclear.
     */
    public static function detect_purpose( string $prompt ): ?string {
        foreach ( self::PURPOSE_PATTERNS as $purpose => $regex ) {
            if ( preg_match( $regex, $prompt ) ) {
                return $purpose;
            }
        }
        return null;
    }

    /**
     * Get style prefix for a given purpose.
     */
    public static function get_purpose_prefix( string $purpose ): string {
        return self::PURPOSE_STYLES[ $purpose ] ?? '';
    }

    public function build_context( $goal, $slots, $user_id, $conversation ) {
        $model = get_option( 'bztimg_default_model', 'flux-pro' );

        $ctx  = "Plugin: BizCity Image AI (OpenRouter)\n";
        $ctx .= "Model mặc định: {$model} (không cần hỏi user chọn model).\n\n";

        $ctx .= "=== QUY TRÌNH TẠO ẢNH (BẮT BUỘC TUÂN THỦ) ===\n\n";

        $ctx .= "BƯỚC 1 — Hỏi chế độ tạo ảnh:\n";
        $ctx .= "Khi user yêu cầu tạo ảnh, LUÔN hỏi trước:\n";
        $ctx .= "\"Bạn muốn tạo ảnh theo cách nào?\n";
        $ctx .= "  1️⃣ **Từ ảnh cảm hứng** — Gửi 1 hoặc nhiều ảnh mẫu, AI sẽ tạo ảnh mới dựa trên phong cách/nội dung ảnh đó.\n";
        $ctx .= "  2️⃣ **Từ ý tưởng** — Mô tả chi tiết ý tưởng bằng text, AI sẽ tạo ảnh từ đầu.\"\n\n";

        $ctx .= "BƯỚC 2 — Thu thập thông tin theo chế độ:\n\n";

        $ctx .= "Nếu chọn 1️⃣ (Từ ảnh cảm hứng):\n";
        $ctx .= "  - BẮT BUỘC yêu cầu link ảnh (URL hoặc upload). Không có ảnh = không tiếp tục.\n";
        $ctx .= "  - Hỏi thêm: 'Bạn muốn giữ nguyên phong cách hay thay đổi gì? (ví dụ: đổi màu, thêm chi tiết, thay background...)'\n";
        $ctx .= "  - Nếu user gửi nhiều ảnh → tổng hợp phong cách từ tất cả ảnh.\n";
        $ctx .= "  - Set slot: creation_mode=reference, image_url=<link ảnh>\n\n";

        $ctx .= "Nếu chọn 2️⃣ (Từ ý tưởng):\n";
        $ctx .= "  - Hỏi kỹ về chủ đề, càng chi tiết càng tốt:\n";
        $ctx .= "    • Chủ đề chính là gì? (người, vật, cảnh...)\n";
        $ctx .= "    • Bối cảnh/không gian? (trong nhà, ngoài trời, studio...)\n";
        $ctx .= "    • Tông màu/ánh sáng? (ấm, lạnh, tự nhiên, dramatic...)\n";
        $ctx .= "    • Phong cách? (chụp film, cinematic, minimalist, retro...)\n";
        $ctx .= "    • Mục đích sử dụng? (sản phẩm, social media, avatar...)\n";
        $ctx .= "  - Sau khi có đủ thông tin → tổng hợp thành prompt chi tiết.\n";
        $ctx .= "  - Set slot: creation_mode=text, prompt=<mô tả chi tiết>\n\n";

        $ctx .= "BƯỚC 3 — Xác nhận & tạo ảnh:\n";
        $ctx .= "  - Tóm tắt lại thông tin đã thu thập.\n";
        $ctx .= "  - Hỏi: 'Mình tạo ảnh ngay nhé?'\n";
        $ctx .= "  - Khi user confirm → gọi tool generate_image.\n\n";

        $ctx .= "=== AUTO-DETECT MỤC ĐÍCH ===\n";
        $ctx .= "  - product → ảnh sản phẩm (lifestyle, warm lighting, editorial)\n";
        $ctx .= "  - portrait → chân dung (soft light, bokeh, studio quality)\n";
        $ctx .= "  - landscape → phong cảnh (golden hour, vivid, wide angle)\n";
        $ctx .= "  - social → social media (bold colors, trending aesthetic)\n";
        $ctx .= "  - food → ẩm thực (flat lay, warm tones, appetizing)\n\n";

        $ctx .= "Output image_url dùng trong pipeline: write_article, post_facebook, create_product.\n";
        return $ctx;
    }

    public function get_system_instructions( $goal ) {
        if ( $goal !== 'generate_image' ) {
            return '';
        }

        return <<<INST
Bạn là trợ lý tạo ảnh AI chuyên nghiệp. Tuân thủ quy trình 3 bước:

1. LUÔN hỏi chế độ tạo ảnh trước (từ ảnh cảm hứng hoặc từ ý tưởng text).
2. Thu thập đầy đủ thông tin theo chế độ đã chọn.
3. Xác nhận rồi mới tạo ảnh.

QUAN TRỌNG:
- Nếu user chọn "từ ảnh" → PHẢI có link ảnh mới được tạo.
- Nếu user chọn "từ ý tưởng" → hỏi kỹ 3-5 câu về chủ đề, bối cảnh, phong cách, tông màu trước khi tạo.
- KHÔNG tự ý chọn model. Luôn dùng model mặc định.
- KHÔNG bỏ qua bước hỏi chế độ. Ngay cả khi user nói "tạo ảnh con mèo" → vẫn hỏi chế độ trước.
INST;
    }
}
