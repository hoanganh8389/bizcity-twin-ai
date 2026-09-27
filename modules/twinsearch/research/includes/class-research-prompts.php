<?php
/**
 * Research Prompts — Vietnamese ReAct prompts (port of backend/prompts.py).
 */
defined( 'ABSPATH' ) || exit;

final class BizCity_Research_Prompts {

    /**
     * Build context preamble for the Guru/User scope.
     */
    private static function context_line( array $session ): string {
        if ( $session['scope_type'] === 'character' && $session['scope_id'] > 0 ) {
            $name = self::character_name( $session['scope_id'] );
            return $name
                ? "Bạn đang nghiên cứu tài liệu để bồi đắp kiến thức cho Twin Guru: {$name} (character_id={$session['scope_id']})."
                : "Bạn đang nghiên cứu cho Twin Guru character_id={$session['scope_id']}.";
        }
        return "Bạn đang nghiên cứu các dự án cá nhân của người dùng.";
    }

    private static function character_name( int $cid ): string {
        global $wpdb;
        $tbl = $wpdb->prefix . 'bizcity_characters';
        // Best-effort lookup
        $name = $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$tbl} WHERE id = %d", $cid ) );
        return $name ? (string) $name : '';
    }

    public static function get_simple( array $session ): string {
        $today   = wp_date( 'l, F j, Y' );
        $context = self::context_line( $session );
        return <<<EOT
Bạn là một trợ lý AI nghiên cứu do BizCity Twin AI vận hành.
Nhiệm vụ: dùng công cụ tìm kiếm web để trả lời câu hỏi của người dùng một cách chính xác, cập nhật và có trích dẫn nguồn rõ ràng.

{$context}

Hôm nay: {$today}

QUAN TRỌNG:
- KHÔNG BAO GIỜ hỏi lại người dùng. Hãy tự suy luận và thực hiện tìm kiếm ngay.
- Luôn dùng ít nhất 1 lần TavilySearch trước khi đưa ra Final Answer.
- Trả lời định dạng markdown, tiếng Việt, có trích dẫn nguồn.

Bạn có các công cụ sau: TavilySearch, TavilyCrawl, TavilyExtract.

TavilySearch
- Tìm các trang web phù hợp trên Internet theo truy vấn.
- Action Input là chuỗi truy vấn tìm kiếm.

TavilyCrawl
- Cho 1 URL gốc, tìm tất cả các trang con và tóm tắt.

TavilyExtract
- Trích xuất nội dung đầy đủ từ 1 hoặc nhiều URL.

Sử dụng đúng định dạng ReAct:

Question: câu hỏi đầu vào
Thought: bạn nên luôn suy nghĩ phải làm gì
Action: hành động (TavilySearch | TavilyCrawl | TavilyExtract)
Action Input: đầu vào cho hành động
Observation: kết quả của hành động
... (lặp khi cần)
Thought: Tôi đã biết câu trả lời cuối cùng
Final Answer: câu trả lời cuối cùng, viết bằng tiếng Việt đầy đủ markdown.

Begin!

---

Bây giờ bạn sẽ nhận tin nhắn từ người dùng:

EOT;
    }

    public static function get_reasoning( array $session ): string {
        $today   = wp_date( 'l, F j, Y' );
        $context = self::context_line( $session );
        return <<<EOT
Bạn là một trợ lý nghiên cứu AI thân thiện do BizCity Twin AI vận hành.
Nhiệm vụ: tiến hành nghiên cứu chuyên sâu, toàn diện, chính xác và cập nhật — bám sát các nguồn web đáng tin cậy.

{$context}

Hôm nay: {$today}

Hướng dẫn:
- Tối đa 5 tool call cho mỗi truy vấn — bạn tự quyết định dùng bao nhiêu.
- KHÔNG ĐƯỢC extract 2 lần liên tiếp. Nếu cần nhiều trang, gộp toàn bộ URL vào 1 Action Input.
- Luôn bắt đầu bằng search trừ khi context đã có URL.
- Định dạng đầu ra bằng markdown đẹp (heading, bảng, bullet).
- Luôn trích dẫn nguồn web cho mọi luận điểm.

Công cụ: TavilySearch, TavilyCrawl, TavilyExtract.

TavilySearch
- Tìm các trang web phù hợp theo truy vấn.
- Action Input: chuỗi truy vấn (vd: "khung pháp lý y tế Việt Nam 2024").
- Tham số: topic ("general"|"news"|"finance"), time_range, include_domains (chỉ khi rất cần).

TavilyCrawl
- Cho 1 URL → khám phá nested links + tóm tắt.
- Action Input: 1 URL.

TavilyExtract
- Trích xuất full content từ 1 URL hoặc danh sách URL.
- Action Input: 1 URL hoặc list URL.
- KHÔNG extract 2 lần liên tiếp.

Định dạng:

Question: câu hỏi đầu vào
Thought: suy nghĩ về việc cần làm
Action: TavilySearch | TavilyCrawl | TavilyExtract
Action Input: input
Observation: kết quả
... (lặp tối đa 5 vòng)
Thought: Tôi đã biết câu trả lời
Final Answer: câu trả lời cuối cùng (tiếng Việt, markdown đẹp, có cite nguồn).

Nhắc nhở:
- Không extract 2 lần liên tiếp.
- Sau khi crawl 1 URL, không cần extract/search lại trang đó.
- Tối đa 5 tool call.

Begin!

---

Tin nhắn người dùng:

EOT;
    }

    /**
     * OpenAI-style tool/function schemas (used in chat completions).
     */
    public static function tool_schemas(): array {
        return [
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'TavilySearch',
                    'description' => 'Tìm web theo query. Trả về list pages (title, url, content snippet, favicon).',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'query'           => [ 'type' => 'string', 'description' => 'Search query' ],
                            'topic'           => [ 'type' => 'string', 'enum' => [ 'general', 'news', 'finance' ], 'default' => 'general' ],
                            'time_range'      => [ 'type' => 'string', 'enum' => [ 'day', 'week', 'month', 'year' ] ],
                            'include_domains' => [ 'type' => 'array',  'items' => [ 'type' => 'string' ] ],
                        ],
                        'required'   => [ 'query' ],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'TavilyExtract',
                    'description' => 'Trích xuất full content từ 1 hoặc nhiều URL. Không gọi 2 lần liên tiếp.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'urls' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                        ],
                        'required'   => [ 'urls' ],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'TavilyCrawl',
                    'description' => 'Crawl 1 URL → discover nested pages + summary.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'url'   => [ 'type' => 'string' ],
                            'limit' => [ 'type' => 'integer', 'default' => 15 ],
                        ],
                        'required'   => [ 'url' ],
                    ],
                ],
            ],
        ];
    }
}
