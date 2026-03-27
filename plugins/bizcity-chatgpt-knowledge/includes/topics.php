<?php
/**
 * Topics & suggested questions for ChatGPT Knowledge.
 *
 * @package BizCity_ChatGPT_Knowledge
 */

if ( ! defined( 'ABSPATH' ) ) exit;

function bzck_get_topics() {
    return [
        [
            'value'     => 'Kiến thức chung',
            'label'     => 'Kiến thức chung',
            'icon'      => '📚',
            'category'  => 'general',
            'questions' => [
                'Blockchain là gì? Giải thích chi tiết',
                'So sánh AI và Machine Learning',
                'Tại sao bầu trời có màu xanh?',
            ],
        ],
        [
            'value'     => 'Công nghệ',
            'label'     => 'Công nghệ',
            'icon'      => '💻',
            'category'  => 'tech',
            'questions' => [
                'So sánh React vs Vue vs Angular',
                'Giải thích kiến trúc microservices',
                'Cách tối ưu SEO cho website',
            ],
        ],
        [
            'value'     => 'Kinh doanh',
            'label'     => 'Kinh doanh',
            'icon'      => '💼',
            'category'  => 'business',
            'questions' => [
                'Các bước khởi nghiệp cho người mới',
                'Phân tích SWOT là gì?',
                'Chiến lược marketing digital hiệu quả',
            ],
        ],
        [
            'value'     => 'Sức khỏe',
            'label'     => 'Sức khỏe',
            'icon'      => '🏥',
            'category'  => 'health',
            'questions' => [
                'Cách cải thiện giấc ngủ',
                'Lợi ích của thiền định',
                'Chế độ ăn healthy cho người bận rộn',
            ],
        ],
        [
            'value'     => 'Giáo dục',
            'label'     => 'Giáo dục',
            'icon'      => '🎓',
            'category'  => 'education',
            'questions' => [
                'Phương pháp học tập hiệu quả',
                'Cách ghi nhớ kiến thức lâu dài',
                'Lộ trình tự học lập trình',
            ],
        ],
        [
            'value'     => 'Pháp luật',
            'label'     => 'Pháp luật',
            'icon'      => '⚖️',
            'category'  => 'legal',
            'questions' => [
                'Quy trình thành lập công ty tại Việt Nam',
                'Quyền lợi người lao động theo luật',
                'Thủ tục đăng ký bản quyền',
            ],
        ],
        [
            'value'     => 'Văn hóa & Lịch sử',
            'label'     => 'Văn hóa & Lịch sử',
            'icon'      => '🏛️',
            'category'  => 'culture',
            'questions' => [
                'Lịch sử phát triển của Internet',
                'Các nền văn minh cổ đại',
                'Nguồn gốc Tết Nguyên Đán',
            ],
        ],
        [
            'value'     => 'Khoa học',
            'label'     => 'Khoa học',
            'icon'      => '🔬',
            'category'  => 'science',
            'questions' => [
                'Lỗ đen vũ trụ hoạt động như thế nào?',
                'DNA và gene hoạt động ra sao?',
                'Hiệu ứng nhà kính là gì?',
            ],
        ],
        [
            'value'     => 'Tài chính cá nhân',
            'label'     => 'Tài chính cá nhân',
            'icon'      => '💰',
            'category'  => 'finance',
            'questions' => [
                'Cách quản lý tài chính cá nhân hiệu quả',
                'Nên đầu tư gì với 50 triệu?',
                'Tiết kiệm vs Đầu tư — nên chọn gì?',
            ],
        ],
        [
            'value'     => 'Phân tích chuyên sâu',
            'label'     => 'Phân tích chuyên sâu',
            'icon'      => '🔍',
            'category'  => 'analysis',
            'questions' => [
                'Phân tích xu hướng AI năm 2025',
                'So sánh các mô hình kinh doanh SaaS',
                'Đánh giá thị trường bất động sản Việt Nam',
            ],
        ],
    ];
}

function bzck_get_topics_grouped() {
    $topics = bzck_get_topics();
    $grouped = [];
    foreach ( $topics as $t ) {
        $grouped[ $t['category'] ] = $t;
    }
    return $grouped;
}
