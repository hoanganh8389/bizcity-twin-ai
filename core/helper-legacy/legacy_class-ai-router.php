<?php
/**
 * Legacy AI Router — Message type detection via LLM prompt.
 *
 * Migrated from: mu-plugins/bizcity-admin-hook/lib/class-bizcity-adminhook-ai.php
 * Date: 2026-03-30
 *
 * @package BizCity_Twin_AI
 * @subpackage Helper_Legacy
 */
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'BizCity_AdminHook_AI' ) ) :
class BizCity_AdminHook_AI {
	public static function detectMessageTypePrompt($user_text) {
		$now = date('H:i:s d/m/Y');
        $neworder = get_transient('twf_neworder_' . ($GLOBALS['twf_current_chat_id'] ?? ''));
        $sdt = $neworder['sdt']??'';
        return "
        Thời gian hiện tại tại Việt Nam là $now (GMT+7)
        Phân tích câu lệnh sau, trả về duy nhất định dạng JSON có dạng 
        {\"type\": \"một trong các giá trị: tao_san_pham, viet_bai, hdsd, tao_don_hang, tao_don_moi, them_san_pham, sua_san_pham, len_lich, video, facebook, bao_cao, thong_ke, yeu_cau_quan_tri, nhac_viec, tai_lieu,, reply_web, reply_zalo, reply_messenger, tim_khach_hang, khac\"},
        {\"info\": \"info\"}. 
        
        nếu sửa sản phẩm, sửa hàng thì trả về 'sua_san_pham', 
        Nếu là lệnh đăng/lên lịch viết bài, blog thì trả về 'len_lich', 
        Nếu là hỏi bot hoặc bạn đang ở, quản trị blog, web, trang nào trả về 'blog_nao', 
        nếu câu lệnh nói về Facebook page thì trả về 'facebook', 
        nếu từ khóa thống kê, báo cáo, doanh số thì trả về 'bao_cao', 
        nếu từ khóa, câu lệnh liên quan đến danh sách đơn hàng thì
            JSON bắt buộc gồm:
            {
            \"type\": \"danh_sach_don_hang\",
            \"status\": [\"pending\",...],
            \"from_date\": \"YYYY-MM-DD\",
            \"to_date\": \"YYYY-MM-DD\"
            }
            Nếu là đơn tháng... ('tháng 3/2024'), trả về \"month\":3, \"year\":2024.
            Nếu là \"2 tuần gần đây\": trả về \"so_tuan\":2, v.v.
            Chỉ trả về 1 JSON, không giải thích.,
            
        nếu từ khóa thống kê hàng hóa, sản phẩm, bán chạy thì trả về 'thong_ke_hang_hoa',
        nếu từ khóa thống kê khách hàng thì trả về 'thong_ke_khach_hang', 
        nếu từ khóa xuất nhập tồn, xnt, kho hàng thì trả về 'xnt', 
        nếu từ khóa nhập kho, phiếu nhập, tạo nhập kho thì trả về 'nhap_kho', 
        nếu từ khóa nhật ký xuất nhập, danh sách, log xuất nhập thì trả về 'nhat_ky_xnt', 
        
        Nếu là viết bài lên facebook hoặc đăng fb, đăng bài fanpage thì trả về:
        {
        \"type\": \"dang_facebook\",
        \"info\": {
            \"title\": \"tên hoặc chủ đề bài viết yêu cầu\",
            \"content\": \"Nội dung mô tả\",
            \"image_url\": \"URL của ảnh nếu có\"
        }
        }, 
        Nếu là viết bài lên tất cả facebook đang quản lý thì trả về:
        {
        \"type\": \"dang_facebook_tat_ca\",
        \"info\": {
            \"title\": \"Tiêu đề bài viết hay, chuẩn SEO, không quá 30 chữ\",
            \"content\": \"Nội dung mô tả\",
            \"image_url\": \"URL của ảnh nếu có\"
        }
        }, 
        Nếu là viết bài hoặc tạo bài viết, đăng bài lên web, đăng bài viết, viết content thì trả về:
        {
        \"type\": \"viet_bai\",
        \"info\": {
            \"title\": \"Tiêu đề bài viết hay, chuẩn SEO, không quá 30 chữ\",
            \"content\": \"Nội dung mô tả\",
            \"image_url\": \"URL của ảnh nếu có\"
        }
        }, 
        Nếu là dạng lệnh tạo sản phẩm, thêm sản phẩm mới, đăng sản phẩm thì trả về :
        {
        \"type\": \"tao_san_pham\",
        \"info\": {
            \"title\": \"tóm tắt tiêu đề\",
            \"description\": \"Mô tả sản phẩm nếu có\",
            \"image_url\": \"URL của ảnh nếu có\"
        }
        }, 
        Nếu là lệnh hỏi về hướng dẫn sử dụng/một nhóm lệnh thì trả về:
        {
        \"type\": \"hdsd\",
        \"topic\": \"[bán_hàng|bai_viet|bao_cao|san_pham|video|khach_hang...]\"
        },
        Nếu là lệnh tạo đơn hàng thì trả về 'tao_don_hang', 
        Nếu là lệnh tìm khách hàng thì trả về 'tim_khach_hang' và Số điện thoại khách hàng tìm được sẽ trả về 'info',
        Nếu là câu ghi chú, ghi nhớ, nhắc việc thì trả về:
            {
            \"type\": \"nhac_viec\",
            \"info\": {
                \"title\": \"Tóm tắt tiêu đề\",
                \"content\": \"Nội dung mô tả\",
                \"category\": \"gia_dinh | van_phong | du_an | khac\",
                \"remind_at\": \"YYYY-MM-DD HH:MM\"
            }
            },
        Nếu là câu lệnh yêu cầu quản trị, đăng nhập một website cụ thể thì trả về JSON sau:
        {
        \"type\": \"yeu_cau_quan_tri\",
        \"info\": {
            \"domain\": \"chaychualanh.com\"
        }
        },
        Nếu là câu lệnh quên mật khẩu sẽ trả về:
        {
        \"type\": \"quen_mat_khau\",
        \"info\": {
            \"domain\": \"chaychualanh.com\"
        }
        },
        Nếu là câu lệnh đăng ký thành viên, tạo tài khoản:
        {
        \"type\": \"tao_tai_khoan\",
        \"info\": {
            \"domain\": \"chaychualanh.com\"
        }
        },
        Nếu là lệnh lên lịch đăng bài facebook tự động:
            {
            \"type\": \"len_lich_facebook_ai\",
            \"info\": {
                \"chu_de\": \"AI marketing\",
                \"hours\": [\"8:00\", \"11:00\", \"14:00\", \"16:00\"],
                \"weekdays\": [\"mon\",\"tue\",\"wed\",\"thu\",\"fri\",\"sat\",\"sun\"]
            }
            },
        còn lại trả về 'khac'. Không giải thích, không thêm bất kỳ nội dung nào khác ngoài 1 kết quả JSON duy nhất.

    Câu lệnh: \"{$user_text}\"
    ";
	}

	public static function teleAiResponse($api_key, $prompt) {
		if (!function_exists('chatbot_chatgpt_call_omni_tele')) {
			return '';
		}
		return chatbot_chatgpt_call_omni_tele($api_key, $prompt);
	}

	public static function bizgptChatbotTeleResponse($api_key, $question) {
        $system = 'Bạn là trợ lý AI trực tuyến cho khách hàng mua sắm. Hãy tư vấn tiếng Việt thân thiện, rõ ràng, ngắn gọn (tối đa 5 câu cho mỗi câu hỏi), không dùng markdown/headings, luôn hướng khách hàng quay lại đặt câu hỏi về sản phẩm, mua hàng tại shop. Không đề cập mình là AI, chỉ trả lời chuyên nghiệp.';
        $fallback = 'Xin lỗi, em đang tạm thời không kết nối được AI. Sếp vui lòng thử lại sau!';

        if ( function_exists( 'bizcity_openrouter_chat' ) ) {
            $messages = [
                [ 'role' => 'system', 'content' => $system ],
                [ 'role' => 'user',   'content' => (string) $question ],
            ];
            $result = bizcity_openrouter_chat( $messages, [
                'purpose'     => 'chat',
                'max_tokens'  => 400,
                'temperature' => 0.7,
            ] );
            return ! empty( $result['success'] ) ? trim( $result['message'] ) : $fallback;
        }

        $api_key = get_option('twf_openai_api_key');
        if (empty($api_key)) return $fallback;

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode([
                'model'       => 'gpt-4o-mini',
                'messages'    => [
                    [ 'role' => 'system', 'content' => $system ],
                    [ 'role' => 'user',   'content' => (string) $question ],
                ],
                'max_tokens'  => 400,
                'temperature' => 0.7,
            ]),
            'timeout' => 40,
        ]);

        if (is_wp_error($response)) return $fallback;
        $data = json_decode(wp_remote_retrieve_body($response), true);
        return ! empty($data['choices'][0]['message']['content'])
            ? trim($data['choices'][0]['message']['content'])
            : $fallback;
	}
}endif; // class_exists guard