<?php
/**
 * BizCity Tarot – Topics & Questions Data
 * Danh sách chủ đề và câu hỏi gợi ý
 *
 * @package BizCity_Tarot
 */

if ( ! defined( 'ABSPATH' ) ) exit;

function bct_get_topics(): array {
    return [
        [
            'value'    => 'Tình cảm của người yêu cũ',
            'label'    => 'Tình cảm của người yêu cũ',
            'icon'     => '💔',
            'category' => 'tinh_yeu',
            'questions' => [
                'Tình cảm quá khứ của người yêu cũ dành cho tôi?',
                'Tình cảm hiện tại của người yêu cũ dành cho tôi?',
            ],
        ],
        [
            'value'    => 'Người yêu cũ',
            'label'    => 'Người yêu cũ',
            'icon'     => '💘',
            'category' => 'tinh_yeu',
            'questions' => [
                'Suy nghĩ của người yêu cũ về việc quay lại?',
                'Kết quả tốt nhất cho mối quan hệ giữa tôi và người yêu cũ tôi?',
                'Lời khuyên cho mối quan hệ của tôi và người yêu cũ trong thời gian tới?',
                'Có nên đi chơi với người yêu cũ không?',
                'Bói tarot người cũ còn yêu bạn không?',
                'Tôi có nên liên lạc thường xuyên với người yêu cũ không?',
            ],
        ],
        [
            'value'    => 'Mối quan hệ hiện tại',
            'label'    => 'Mối quan hệ hiện tại',
            'icon'     => '❤️',
            'category' => 'tinh_yeu',
            'questions' => [
                'Tình cảm của người yêu tôi dành cho tôi như thế nào?',
                'Kỳ vọng của người yêu tôi dành cho mối quan hệ của chúng tôi như thế nào?',
                'Suy nghĩ của người yêu tôi trong mối quan hệ của chúng tôi là gì?',
                'Trở ngại trong mối quan hệ giữa tôi và người yêu tôi là gì?',
                'Lời khuyên cho mối quan hệ của tôi và người yêu tôi trong thời gian tới?',
            ],
        ],
        [
            'value'    => 'Người yêu hiện tại',
            'label'    => 'Người yêu hiện tại',
            'icon'     => '💑',
            'category' => 'tinh_yeu',
            'questions' => [
                'Xem bói bài tarot chàng yêu bạn như thế nào?',
                'Có điều gì trong quá khứ của chúng tôi cần giải quyết để phát triển mối quan hệ tích cực?',
                'Xem bói bài tarot người yêu có chung thủy không?',
                'Người yêu hiện tại có phù hợp và đáng tin cậy để xây dựng một tương lai lâu dài không?',
                'Làm thế nào để tăng cường gắn kết và sự hiểu biết với người yêu hiện tại?',
                'Có những khó khăn hoặc vấn đề tiềm ẩn nào trong mối quan hệ của chúng tôi?',
                'Tôi có thể tin tưởng và cảm nhận sự trung thành từ người yêu hiện tại không?',
            ],
        ],
        [
            'value'    => 'Mối quan hệ mập mờ',
            'label'    => 'Mối quan hệ mập mờ',
            'icon'     => '🌫️',
            'category' => 'tinh_yeu',
            'questions' => [
                'Tình cảm của người có mối quan hệ mập mờ với tôi như thế nào?',
                'Kỳ vọng của người có mối quan hệ mập mờ với tôi như thế nào?',
                'Suy nghĩ của người có mối quan hệ mập mờ với tôi như thế nào?',
                'Kết quả tốt nhất cho mối quan hệ giữa tôi và người có mối quan hệ mập mờ với tôi là gì?',
                'Lời khuyên cho mối quan hệ giữa tôi và người có mối quan hệ mập mờ với tôi là gì?',
            ],
        ],
        [
            'value'    => 'Crush',
            'label'    => 'Crush',
            'icon'     => '🥰',
            'category' => 'tinh_yeu',
            'questions' => [
                'Crush của tôi là người như thế nào?',
                'Người tôi đang thích có đang chú ý đến tôi không?',
                'Tương lai giữa tôi và crush của tôi sẽ như thế nào?',
                'Crush của tôi có đang để ý đến 1 người khác không?',
                'Lời khuyên để phát triển mối quan hệ giữa tôi và crush?',
                'Bói bài tarot ai đang yêu thầm bạn?',
                'Xem tarot có bao nhiêu người thích bạn?',
            ],
        ],
        [
            'value'    => 'Người ấy',
            'label'    => 'Người ấy',
            'icon'     => '🫶',
            'category' => 'tinh_yeu',
            'questions' => [
                'Bói tarot người ấy có sợ mất bạn không?',
                'Bói tarot người ấy có quay lại không?',
                'Bói tarot người ấy có nhớ bạn không?',
                'Bói Tarot người ấy có ghen bạn không?',
                'Bói bài tarot bạn và người ấy có happy ending?',
                'Bói bài tarot người ấy có bí mật gì?',
            ],
        ],
        [
            'value'    => 'Độc thân',
            'label'    => 'Độc thân',
            'icon'     => '🌸',
            'category' => 'tinh_yeu',
            'questions' => [
                'Tương lai con đường tình yêu của tôi sẽ ra sao?',
                'Tôi cần phải làm gì để thu hút mối quan hệ tình yêu tích cực?',
                'Những người tiềm năng trong tương lai sẽ có những đặc điểm gì?',
                'Tôi nên tập trung vào những gì trong cuộc sống để tạo dựng một mối quan hệ hạnh phúc?',
                'Có những khó khăn hay trở ngại gì đang cản trở tôi trong việc tìm kiếm tình yêu?',
                'Tôi cần làm gì để chuẩn bị bản thân tốt nhất cho mối quan hệ tình yêu trong tương lai?',
            ],
        ],
        [
            'value'    => 'Người yêu tương lai',
            'label'    => 'Người yêu tương lai',
            'icon'     => '🔮',
            'category' => 'tinh_yeu',
            'questions' => [
                'Tôi có thể gặp người mình thích trong khoảng thời gian nào?',
                'Người yêu tương lai của tôi có ngoại hình như thế nào?',
                'Người yêu tương lai của tôi sẽ đối xử với tôi như thế nào?',
                'Mối quan hệ của tôi và người yêu tương lai sẽ đem đến cho tôi bài học gì?',
                'Tổng quan mối quan hệ của tôi và người yêu tương lai sẽ như thế nào?',
                'Xem bói tarot chồng tương lai làm nghề gì?',
            ],
        ],
        [
            'value'    => 'Hôn nhân',
            'label'    => 'Hôn nhân',
            'icon'     => '💍',
            'category' => 'tinh_yeu',
            'questions' => [
                'Tại sao cuộc hôn nhân của tôi lại khổ đau như vậy?',
                'Điều gì ảnh hưởng đến mối quan hệ của chúng tôi?',
                'Làm cách nào để tôi có thể vượt qua những thói quen tiêu cực của nửa kia?',
                'Tôi có thể làm gì để có thể cứu vãn cuộc hôn nhân của mình?',
                'Có điều gì mờ ám trong mối quan hệ hiện tại mà tôi không nhìn thấy không?',
                'Nửa kia của tôi mong muốn điều gì ở tôi?',
                'Tại sao mối quan hệ của tôi lại trở nên chênh vênh?',
                'Nửa kia của tôi cần tôi hỗ trợ nhiều hơn trong những lĩnh vực nào?',
            ],
        ],
        [
            'value'    => 'Chia tay',
            'label'    => 'Chia tay',
            'icon'     => '💔',
            'category' => 'tinh_yeu',
            'questions' => [
                'Tại sao tôi không thể buông tay người yêu cũ?',
                'Làm thế nào tôi có thể tiếp tục sau cuộc ly hôn?',
                'Tôi phải làm sao để có thể đối phó với sự mất mát khi rời xa người yêu?',
                'Tôi nên học điều gì từ cuộc chia tay gần đây của mình?',
                'Tôi có nên quay lại với người yêu cũ/chồng cũ/vợ cũ không?',
                'Điều gì đang ảnh hưởng đến quá trình làm lành của tôi sau khi chia tay?',
                'Tôi cần hiểu gì về bản thân để có thể tiến về phía trước?',
                'Điều gì đang ngăn tôi khỏi việc tiến lên và làm mới cuộc sống sau khi chia tay?',
            ],
        ],
        [
            'value'    => 'Mối quan hệ cũ',
            'label'    => 'Mối quan hệ cũ',
            'icon'     => '⏳',
            'category' => 'tinh_yeu',
            'questions' => [
                'Người trong mối quan hệ cũ đang cảm thấy như thế nào ở thời điểm hiện tại?',
                'Người trong mối quan hệ cũ suy nghĩ gì về tôi?',
                'Người trong mối quan hệ cũ suy nghĩ gì về mối quan hệ của chúng tôi?',
                'Bản chất của mối quan hệ cũ trong quá khứ là như thế nào?',
                'Lời khuyên dành cho mối quan hệ giữa tôi và người có mối quan hệ cũ với tôi là gì?',
            ],
        ],
        [
            'value'    => 'Quay lại với mối quan hệ cũ',
            'label'    => 'Quay lại với mối quan hệ cũ',
            'icon'     => '🔄',
            'category' => 'tinh_yeu',
            'questions' => [
                'Tình cảm của mối quan hệ cũ dành cho tôi?',
                'Mối quan hệ cũ có mong muốn gắn kết lại mối quan hệ giữa chúng tôi không?',
                'Có khả năng quay lại của mối quan hệ giữa tôi và người có mối quan hệ cũ với tôi không?',
                'Sau khi quay lại, tôi và người có mối quan hệ cũ này sẽ như thế nào?',
            ],
        ],
        [
            'value'    => 'Giải quyết xung đột',
            'label'    => 'Giải quyết xung đột',
            'icon'     => '⚖️',
            'category' => 'tinh_yeu',
            'questions' => [
                'Tôi có thể chủ động giải quyết xung đột trước không?',
                'Điều gì giúp tôi hiểu rằng tôi không thể thay đổi được người xung đột với tôi?',
                'Tôi sẽ thấy gì khi ở góc nhìn của người xung đột với tôi?',
                'Tôi có nên tha thứ cho người xung đột với tôi không?',
                'Tôi và người xung đột với tôi có thể chấp nhận những bất đồng không?',
                'Kết quả của cuộc xung đột gần đây của tôi và lời khuyên dành cho tôi?',
            ],
        ],

        // ---- TÀI CHÍNH ----
        [
            'value'    => 'Tài chính',
            'label'    => 'Tài chính',
            'icon'     => '💰',
            'category' => 'tai_chinh',
            'questions' => [
                'Cơ hội tài chính của tôi trong khoảng thời gian tới?',
                'Lời khuyên cho tôi trong quản lý tài chính khoảng thời gian sắp tới?',
                'Tình hình tài chính của tôi trong 3 tháng tới?',
                'Mục tiêu tài chính mà tôi đề ra có đạt kết quả như mong muốn?',
                'Tôi có người giúp đỡ về tài chính hay không?',
                'Điều gì đang là trở ngại tài chính lớn nhất của tôi?',
                'Tôi nên làm gì để có thể chi tiêu một cách hợp lí?',
            ],
        ],

        // ---- CÔNG VIỆC / ĐỐI TÁC ----
        [
            'value'    => 'Công việc',
            'label'    => 'Công việc',
            'icon'     => '💼',
            'category' => 'cong_viec',
            'questions' => [
                'Trong thời gian 3 tháng tới, tôi có kiếm được công việc nào không, lời khuyên khi đi xin việc?',
                'Công việc tôi đang xin có những khó khăn gì?',
                'Điểm mạnh của tôi trong lần xin việc này?',
                'Điểm yếu của tôi trong lần xin việc này?',
                'Môi trường làm việc mới sẽ như thế nào?',
                'Lời khuyên cho tôi về công việc mới sắp tới?',
            ],
        ],
        [
            'value'    => 'Môi trường làm việc mới',
            'label'    => 'Môi trường làm việc mới',
            'icon'     => '🏢',
            'category' => 'cong_viec',
            'questions' => [
                'Đồng nghiệp của tôi ở môi trường làm việc mới như thế nào?',
                'Ở trong môi trường làm việc mới sẽ đem lại cho tôi những điều gì?',
                'Khó khăn khi ở trong môi trường làm việc mới của tôi là gì?',
                'Tôi có làm việc lâu dài tại môi trường làm việc mới không?',
            ],
        ],
        [
            'value'    => 'Định hướng công việc',
            'label'    => 'Định hướng công việc',
            'icon'     => '🧭',
            'category' => 'cong_viec',
            'questions' => [
                'Thực trạng tình hình công việc ở hiện tại có tốt không?',
                'Công việc trong tương lai của tôi 3 tháng tới sẽ như thế nào?',
                'Lưu ý để phát triển công việc của tôi trong tương lai là gì?',
                'Lời khuyên để phát triển công việc của tôi trong tương lai?',
                'Tôi có nên chuyển chỗ làm không, lời khuyên dành cho tôi về việc này?',
            ],
        ],
        [
            'value'    => 'Đối tác kinh doanh',
            'label'    => 'Đối tác kinh doanh',
            'icon'     => '🤝',
            'category' => 'cong_viec',
            'questions' => [
                'Đối tác kinh doanh của tôi có đáng tin cậy không?',
                'Mối quan hệ kinh doanh giữa tôi và đối tác sẽ như thế nào?',
                'Tôi và đối tác có phù hợp để hợp tác lâu dài không?',
                'Điều gì cần lưu ý trong mối quan hệ đối tác kinh doanh của tôi?',
                'Kết quả dự án hợp tác của tôi và đối tác sẽ ra sao?',
            ],
        ],
        [
            'value'    => 'Khởi nghiệp',
            'label'    => 'Khởi nghiệp',
            'icon'     => '🚀',
            'category' => 'cong_viec',
            'questions' => [
                'Tôi có phù hợp để khởi nghiệp không?',
                'Ý tưởng kinh doanh của tôi có tiềm năng không?',
                'Những thách thức lớn nhất khi khởi nghiệp của tôi là gì?',
                'Tôi nên khởi nghiệp vào thời điểm nào?',
                'Lời khuyên cho hành trình khởi nghiệp của tôi?',
            ],
        ],

        // ---- HỌC TẬP ----
        [
            'value'    => 'Học tập',
            'label'    => 'Học tập',
            'icon'     => '📚',
            'category' => 'hoc_tap',
            'questions' => [
                'Ưu điểm và khuyết điểm của tôi trong học tập?',
                'Tôi cần khắc phục và phát triển bản thân như thế nào để học tập tốt hơn?',
                'Cần lưu ý gì về việc học tập trong khoảng thời gian sắp tới?',
                'Tổng quan việc học tập của tôi ở thời điểm hiện tại?',
                'Tổng quan việc học tập trong 3 tháng tới?',
                'Tôi sẽ có kết quả cao trong kì thi/ bài thi sắp tới không?',
                'Tôi có đạt được nguyện vọng học tập như tôi mong muốn không?',
            ],
        ],
        [
            'value'    => 'Du học',
            'label'    => 'Du học',
            'icon'     => '✈️',
            'category' => 'hoc_tap',
            'questions' => [
                'Tôi có phù hợp với đi du học không?',
                'Cuộc sống khi đi du học của tôi sẽ như thế nào?',
                'Tôi cần lưu ý những gì trong quá trình khi đi du học?',
                'Tôi có gặp khó khăn gì trong quá trình du học không?',
                'Lời khuyên dành cho tôi khi đi du học?',
            ],
        ],

        // ---- SỨC KHỎE ----
        [
            'value'    => 'Sức khỏe',
            'label'    => 'Sức khỏe',
            'icon'     => '🌿',
            'category' => 'suc_khoe',
            'questions' => [
                'Tình hình sức khỏe của tôi trong thời gian tới như thế nào?',
                'Điều gì đang là cản trở đối với sức khoẻ của tôi?',
                'Tôi nên thực hiện những thay đổi gì đối với lối sống hiện tại của mình?',
                'Tôi đang bỏ qua những vấn đề hiện tại nào liên quan đến sức khỏe của mình?',
                'Tôi có đang bảo vệ sức khoẻ của mình sai cách không?',
                'Tôi nên làm gì để tăng năng lượng của mình?',
            ],
        ],

        // ---- GIA ĐÌNH ----
        [
            'value'    => 'Gia đình',
            'label'    => 'Gia đình',
            'icon'     => '🏡',
            'category' => 'gia_dinh',
            'questions' => [
                'Thời gian tới gia đình tôi có gặp khó khăn gì không?',
                'Tài chính của gia đình tôi có vấn đề gì không?',
                'Các mối quan hệ trong gia đình tôi thời gian sắp tới như thế nào?',
                'Sức khỏe của các thành viên trong gia đình tôi sắp tới như thế nào?',
                'Tôi nên làm gì để cải thiện mối quan hệ của tôi với gia đình tôi?',
                'Lời khuyên dành cho gia đình của tôi?',
            ],
        ],

        // ---- ĐỊNH HƯỚNG BẢN THÂN ----
        [
            'value'    => 'Định hướng bản thân',
            'label'    => 'Định hướng bản thân',
            'icon'     => '🌟',
            'category' => 'ban_than',
            'questions' => [
                'Mô tả về bản thân tôi trong thời điểm hiện tại như thế nào?',
                'Hình ảnh của tôi trong mắt bạn bè xung quanh như thế nào?',
                'Tôi cần lưu ý gì để phát triển bản thân?',
                'Xu hướng của tôi trong mối quan hệ tình cảm như thế nào?',
            ],
        ],
        [
            'value'    => 'Về bạn',
            'label'    => 'Về bạn',
            'icon'     => '🪞',
            'category' => 'ban_than',
            'questions' => [
                'Xem bói tarot bao giờ lấy chồng?',
                'Bói tarot mọi người nghĩ gì về bạn?',
                'Xem bói bài tarot bạn có đào hoa không?',
                'Xem bói bài tarot người bạn ghét là ai?',
                'Xem bói bài tarot bạn kiểu người thế nào?',
                'Bói bài tarot có may mắn trong tình yêu ko?',
                'Bói bài tarot xem ngã rẽ cuộc đời của bạn như thế nào?',
                'Xem bói bài tarot bao giờ có người yêu?',
            ],
        ],
        [
            'value'    => 'Chính xác không',
            'label'    => 'Tarot có chính xác không?',
            'icon'     => '❓',
            'category' => 'ban_than',
            'questions' => [
                'Xem bói bài tarot có chính xác không?',
                'Kết quả tarot có thay đổi được không?',
                'Bói bài tarot online có chính xác không?',
                'Tarot đúng trong bao lâu?',
                'Bói tarot có nguy hiểm không?',
                'Xem bói bài tarot có đúng không?',
            ],
        ],
    ];
}

/**
 * Get topic categories for display
 */
function bct_get_topic_categories(): array {
    return [
        'tinh_yeu'  => ['label' => 'Tình yêu', 'icon' => '❤️'],
        'tai_chinh' => ['label' => 'Tài chính', 'icon' => '💰'],
        'cong_viec' => ['label' => 'Công việc & Đối tác', 'icon' => '💼'],
        'hoc_tap'   => ['label' => 'Học tập', 'icon' => '📚'],
        'suc_khoe'  => ['label' => 'Sức khỏe', 'icon' => '🌿'],
        'gia_dinh'  => ['label' => 'Gia đình', 'icon' => '🏡'],
        'ban_than'  => ['label' => 'Bản thân', 'icon' => '🌟'],
    ];
}
