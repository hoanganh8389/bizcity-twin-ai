<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Intent
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * BizCity Intent — Mode Pipeline (Abstract Base + Concrete Pipelines)
 *
 * Mỗi mode có 1 pipeline riêng xử lý tin nhắn theo cách phù hợp:
 *
 *   - EmotionPipeline:    Empathy response, no tools, safety guard
 *   - ReflectionPipeline: Mirror + clarify, gentle advice
 *   - KnowledgePipeline:  RAG + Web Search, structured answer
 *   - ExecutionPipeline:  Intent → variables → tool calls (handled by Intent Engine)
 *
 * 4 active modes: Emotion, Reflection, Knowledge (via pipelines) + Execution (via Intent Engine).
 *
 * Mỗi pipeline nhận context và trả về response array.
 *
 * @package BizCity_Intent
 * @since   2.0.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

/* ================================================================
 * Abstract Base Pipeline
 * ================================================================ */

abstract class BizCity_Mode_Pipeline {

    /**
     * Process a message through this pipeline.
     *
     * @param array $ctx {
     *   @type string $message        User message text.
     *   @type string $session_id     Session identifier.
     *   @type int    $user_id        WordPress user ID (0 = guest).
     *   @type string $channel        'webchat' | 'adminchat' | 'zalo' | 'telegram'
     *   @type int    $character_id   AI character ID.
     *   @type array  $images         Attached images.
     *   @type array  $conversation   Active conversation data.
     *   @type array  $mode_result    Result from Mode Classifier.
     *   @type array  $extra          Any extra context.
     * }
     * @return array {
     *   @type string $reply          Bot reply text. Empty = needs AI compose.
     *   @type string $action         'reply' | 'compose' (reply = complete, compose = send to AI)
     *   @type array  $system_prompt_parts  Additional system prompt parts.
     *   @type array  $memory         Items to save to user memory.
     *   @type array  $meta           Extra metadata.
     * }
     */
    abstract public function process( array $ctx );

    /**
     * Get the mode this pipeline handles.
     *
     * @return string  One of BizCity_Mode_Classifier::MODE_* constants.
     */
    abstract public function get_mode();

    /**
     * Get human label for this pipeline.
     *
     * @return string
     */
    abstract public function get_label();

    /**
     * Build system prompt for AI compose.
     *
     * @param array $ctx Pipeline context.
     * @return string System prompt text.
     */
    protected function build_system_prompt( array $ctx ) {
        return '';
    }

    /**
     * Get knowledge context for the current character.
     * Delegates to bizcity-knowledge Context API.
     *
     * @param int    $character_id
     * @param string $query
     * @param array  $options
     * @return string Knowledge context text.
     */
    protected function get_knowledge_context( $character_id, $query, $options = [] ) {
        if ( ! $character_id || ! class_exists( 'BizCity_Knowledge_Context_API' ) ) {
            return '';
        }

        $result = BizCity_Knowledge_Context_API::instance()->build_context(
            $character_id,
            $query,
            array_merge( [ 'max_tokens' => 2000 ], $options )
        );

        return ! empty( $result['context'] ) ? $result['context'] : '';
    }

    /**
     * Get multi-scope knowledge context (Knowledge Fabric v3.0).
     *
     * Searches across session > project > user > agent scopes
     * with token budget allocation per scope.
     *
     * Falls back to single-character build_context() if Fabric unavailable.
     *
     * @param array  $scope_params { user_id, character_id, project_id, session_id }
     * @param string $query
     * @param array  $options
     * @return string Knowledge context text.
     */
    protected function get_multi_scope_knowledge_context( $scope_params, $query, $options = [] ) {
        if ( ! class_exists( 'BizCity_Knowledge_Context_API' ) ) {
            return '';
        }

        $api = BizCity_Knowledge_Context_API::instance();

        // Use multi-scope if method exists (Knowledge Fabric v3.0+)
        if ( method_exists( $api, 'build_multi_scope_context' ) ) {
            $result = $api->build_multi_scope_context(
                $scope_params,
                $query,
                array_merge( [ 'max_tokens' => 3000 ], $options )
            );
            return ! empty( $result['context'] ) ? $result['context'] : '';
        }

        // Fallback to single-character
        $character_id = isset( $scope_params['character_id'] ) ? (int) $scope_params['character_id'] : 0;
        return $this->get_knowledge_context( $character_id, $query, $options );
    }

    /**
     * Get user profile context.
     * Delegates to BizCity_Profile_Context.
     *
     * @param int    $user_id
     * @param string $session_id
     * @param string $platform_type
     * @return string Profile context text.
     */
    protected function get_profile_context( $user_id, $session_id = '', $platform_type = 'ADMINCHAT' ) {
        if ( ! class_exists( 'BizCity_Profile_Context' ) ) {
            return '';
        }

        return BizCity_Profile_Context::instance()->build_user_context(
            $user_id, $session_id, $platform_type
        );
    }
}

/* ================================================================
 * Emotion Pipeline — Empathy Mode
 *
 * Không dùng tool, không nhảy automation, không JSON.
 * Chỉ phản hồi cảm xúc + hỏi sâu.
 * Safety guard: detect self-harm signals.
 * ================================================================ */

class BizCity_Emotion_Pipeline extends BizCity_Mode_Pipeline {

    public function get_mode() {
        return BizCity_Mode_Classifier::MODE_EMOTION;
    }

    public function get_label() {
        return 'Empathy Mode — Tâm sự & cảm xúc';
    }

    /**
     * Process emotion/empathy messages.
     * Always delegates to AI compose with empathy-focused system prompt.
     * Never uses tools or structured output.
     */
    public function process( array $ctx ) {
        $message      = $ctx['message'] ?? '';
        $user_id      = intval( $ctx['user_id'] ?? 0 );
        $session_id   = $ctx['session_id'] ?? '';
        $character_id = intval( $ctx['character_id'] ?? 0 );
        $channel      = $ctx['channel'] ?? 'webchat';

        // Safety guard: detect self-harm signals
        $safety = $this->check_safety( $message );

        $system_parts = [];
        $system_parts[] = $this->build_system_prompt( $ctx );

        if ( $safety['is_critical'] ) {
            $system_parts[] = $safety['safety_prompt'];
        }

        // Add profile context (know who we're talking to)
        $platform_type = ( $channel === 'adminchat' ) ? 'ADMINCHAT' : 'WEBCHAT';
        $profile_ctx   = $this->get_profile_context( $user_id, $session_id, $platform_type );
        if ( $profile_ctx ) {
            $system_parts[] = $profile_ctx;
        }

        return [
            'reply'              => '', // Needs AI compose
            'action'             => 'compose',
            'system_prompt_parts' => $system_parts,
            'memory'             => [
                'type'    => 'feeling',
                'content' => mb_substr( $message, 0, 500 ),
            ],
            'meta'               => [
                'pipeline'    => 'emotion',
                'safety'      => $safety,
                'no_tools'    => true,
                'no_json'     => true,
                'temperature' => 0.8, // Higher creativity for empathetic responses
            ],
        ];
    }

    protected function build_system_prompt( array $ctx ) {
        return <<<PROMPT
## CHẾ ĐỘ: EMPATHY MODE — TÂM SỰ & CẢM XÚC

Bạn đang ở chế độ ĐỒNG CẢM. Người dùng đang chia sẻ cảm xúc, tâm sự.

### Nguyên tắc TUYỆT ĐỐI:
1. **KHÔNG** đề xuất hành động, tool, hay automation
2. **KHÔNG** trả lời bằng JSON hay cấu trúc kỹ thuật  
3. **KHÔNG** chuyển sang chế độ thực thi trừ khi người dùng YÊU CẦU RÕ RÀNG
4. **KHÔNG** nói "tôi là AI" hay "tôi không có cảm xúc"
5. **KHÔNG** vội vàng đưa ra giải pháp

### Cách phản hồi:
- Lắng nghe và PHẢN CHIẾU cảm xúc: "Mình hiểu bạn đang cảm thấy..."
- Thể hiện sự đồng cảm chân thành
- Hỏi sâu hơn để hiểu rõ: "Bạn có thể kể thêm không?"
- Sử dụng ngôn từ ấm áp, gần gũi
- Cách xưng hô: theo ký ức user (nếu có), hoặc xưng hô tự nhiên phù hợp văn hóa Việt
- Giữ câu trả lời ngắn gọn, chân thành, không dài dòng

### Ví dụ phản hồi tốt:
- "Mình nghe bạn nè. Cảm giác mệt mỏi đó rất nặng nề, bạn muốn kể thêm không?"
- "Mình hiểu. Khi có quá nhiều áp lực mà không ai chia sẻ thì thật khó chịu..."
PROMPT;
    }

    /**
     * Safety check for self-harm / crisis signals.
     *
     * @param string $message
     * @return array { is_critical, level, safety_prompt }
     */
    private function check_safety( $message ) {
        $result = [
            'is_critical'   => false,
            'level'         => 'normal',
            'safety_prompt' => '',
        ];

        $msg_lower = mb_strtolower( $message, 'UTF-8' );

        // Critical signals
        $critical_patterns = [
            '/muốn\s+(chết|tự\s+tử|tự\s+sát|kết\s+thúc\s+cuộc\s+đời)/ui',
            '/không\s+muốn\s+sống/ui',
            '/tự\s+(tử|sát|hủy|hại)/ui',
            '/(suicide|kill\s+myself|end\s+my\s+life|want\s+to\s+die)/i',
        ];

        foreach ( $critical_patterns as $pattern ) {
            if ( preg_match( $pattern, $msg_lower ) ) {
                $result['is_critical']   = true;
                $result['level']         = 'critical';
                $result['safety_prompt'] = <<<SAFETY
⚠️ CẢNH BÁO AN TOÀN: Người dùng có dấu hiệu suy nghĩ tự hại/tự tử.

PHẢN HỒI BẮT BUỘC:
1. Thể hiện sự quan tâm chân thành, KHÔNG hoảng hốt
2. KHÔNG hỏi chi tiết về cách thức  
3. Khuyến khích gọi đường dây nóng: 1800 599 911 (miễn phí, 24/7)
4. Nhắc: "Bạn không đơn độc, luôn có người sẵn sàng lắng nghe"
5. Nếu phù hợp, gợi ý nói chuyện với người thân tin cậy
SAFETY;
                return $result;
            }
        }

        return $result;
    }
}

/* ================================================================
 * Reflection Pipeline — Reflective Mode
 *
 * Phản chiếu lại, hỏi sâu, gợi ý nhẹ nhàng.
 * Không ép hành động.
 * ================================================================ */

class BizCity_Reflection_Pipeline extends BizCity_Mode_Pipeline {

    public function get_mode() {
        return BizCity_Mode_Classifier::MODE_REFLECTION;
    }

    public function get_label() {
        return 'Reflective Mode — Kể chuyện & chia sẻ';
    }

    /**
     * Process reflection/story messages.
     * Always delegates to AI compose with reflective system prompt.
     */
    public function process( array $ctx ) {
        $message      = $ctx['message'] ?? '';
        $user_id      = intval( $ctx['user_id'] ?? 0 );
        $session_id   = $ctx['session_id'] ?? '';
        $character_id = intval( $ctx['character_id'] ?? 0 );
        $channel      = $ctx['channel'] ?? 'webchat';

        $system_parts = [];
        $system_parts[] = $this->build_system_prompt( $ctx );

        // Add profile context
        $platform_type = ( $channel === 'adminchat' ) ? 'ADMINCHAT' : 'WEBCHAT';
        $profile_ctx   = $this->get_profile_context( $user_id, $session_id, $platform_type );
        if ( $profile_ctx ) {
            $system_parts[] = $profile_ctx;
        }

        return [
            'reply'               => '', // Needs AI compose
            'action'              => 'compose',
            'system_prompt_parts' => $system_parts,
            'memory'              => [
                'type'    => 'story',
                'content' => mb_substr( $message, 0, 500 ),
            ],
            'meta'                => [
                'pipeline'    => 'reflection',
                'no_tools'    => true,
                'no_json'     => true,
                'temperature' => 0.7,
            ],
        ];
    }

    protected function build_system_prompt( array $ctx ) {
        return <<<PROMPT
## CHẾ ĐỘ: REFLECTIVE MODE — KỂ CHUYỆN & CHIA SẺ

Người dùng đang kể chuyện, chia sẻ trải nghiệm hoặc sự kiện.

### Nguyên tắc:
1. **Lắng nghe tích cực** — phản chiếu lại những gì người dùng kể
2. **Hỏi sâu** — đặt câu hỏi mở để hiểu thêm
3. **Gợi ý nhẹ nhàng** — nếu phù hợp, gợi ý góc nhìn mới hoặc bài học
4. **KHÔNG ép hành động** — không đề xuất automation hay tool
5. **KHÔNG đánh giá** — không phán xét đúng sai

### Cách phản hồi:
- Phản chiếu: "Mình hiểu, bạn đã trải qua [tóm tắt]..."
- Hỏi mở: "Điều đó khiến bạn cảm thấy thế nào?"
- Góc nhìn: "Mình thấy trong câu chuyện đó có điểm thú vị là..."
- Bài học nhẹ: "Có thể từ trải nghiệm này, bạn nhận ra..."
- Khuyến khích: "Cảm ơn bạn đã chia sẻ, điều đó cần can đảm"

### Ví dụ phản hồi tốt:
- "Câu chuyện của bạn rất ý nghĩa. Mình tò mò — sau chuyện đó bạn có thay đổi gì không?"
- "Nghe có vẻ đó là một trải nghiệm đáng nhớ. Bạn rút ra được điều gì từ đó?"
PROMPT;
    }
}

/* ================================================================
 * Knowledge Pipeline — Knowledge Mode
 *
 * RAG + Web Search, structured answer with sources.
 * Có thể gọi tool (search), trả lời có cấu trúc.
 * ================================================================ */

class BizCity_Knowledge_Pipeline extends BizCity_Mode_Pipeline {

    public function get_mode() {
        return BizCity_Mode_Classifier::MODE_KNOWLEDGE;
    }

    public function get_label() {
        return 'Knowledge Mode — Hỏi đáp & kiến thức';
    }

    /**
     * Process knowledge/question messages.
     * Builds knowledge context from RAG then delegates to AI compose.
     */
    public function process( array $ctx ) {
        $message      = $ctx['message'] ?? '';
        $user_id      = intval( $ctx['user_id'] ?? 0 );
        $session_id   = $ctx['session_id'] ?? '';
        $character_id = intval( $ctx['character_id'] ?? 0 );
        $channel      = $ctx['channel'] ?? 'webchat';

        $system_parts = [];
        $system_parts[] = $this->build_system_prompt( $ctx );

        // Add profile context (for personalized answers)
        $platform_type = ( $channel === 'adminchat' ) ? 'ADMINCHAT' : 'WEBCHAT';
        $profile_ctx   = $this->get_profile_context( $user_id, $session_id, $platform_type );
        if ( $profile_ctx ) {
            $system_parts[] = $profile_ctx;
        }

        // Add knowledge context via RAG (character-specific)
        if ( $character_id ) {
            $knowledge_ctx = $this->get_knowledge_context( $character_id, $message, [
                'max_tokens' => 3000, // More generous for knowledge mode
            ] );
            if ( $knowledge_ctx ) {
                $system_parts[] = "## 📚 KIẾN THỨC LIÊN QUAN (từ Knowledge Base)\n\n" . $knowledge_ctx;
            }
        }

        // Check if any active plugin agents can provide supplementary context
        $agent_context = $this->get_agent_knowledge_context( $message );
        if ( $agent_context ) {
            $system_parts[] = $agent_context;
        }

        // Sprint 1C: Post-knowledge tool suggestion
        // If the user's question relates to available tools, hint the LLM to suggest them.
        $tool_suggestions = $this->find_relevant_tools( $message );
        if ( ! empty( $tool_suggestions ) ) {
            $system_parts[] = $this->build_tool_suggestion_hint( $tool_suggestions );
        }

        // Tune parameters by knowledge sub-mode
        $knowledge_type = $this->get_knowledge_type( $ctx );
        switch ( $knowledge_type ) {
            case 'advisor':
                $temperature = 0.55;
                $max_tokens  = 3000;
                break;
            case 'lookup':
                $temperature = 0.3;
                $max_tokens  = 1000;
                break;
            default: // research
                $temperature = 0.5;
                $max_tokens  = 4000;
                break;
        }

        return [
            'reply'               => '', // Needs AI compose
            'action'              => 'compose',
            'system_prompt_parts' => $system_parts,
            'memory'              => [
                'type'    => 'fact',
                'content' => mb_substr( $message, 0, 300 ),
            ],
            'meta'                => [
                'pipeline'       => 'knowledge',
                'knowledge_type' => $knowledge_type,
                'temperature'    => $temperature,
                'max_tokens'     => $max_tokens,
            ],
        ];
    }

    /**
     * Determine knowledge sub-mode from mode_result.
     */
    protected function get_knowledge_type( array $ctx ) {
        return $ctx['mode_result']['meta']['knowledge_type'] ?? 'research';
    }

    protected function build_system_prompt( array $ctx ) {
        $type = $this->get_knowledge_type( $ctx );

        switch ( $type ) {
            case 'advisor':
                return $this->prompt_advisor();
            case 'lookup':
                return $this->prompt_lookup();
            default: // research
                return $this->prompt_research();
        }
    }

    private function prompt_research() {
        return <<<PROMPT
## CHẾ ĐỘ: KNOWLEDGE — NGHIÊN CỨU & PHÂN TÍCH SÂU

Người dùng muốn tìm hiểu, nghiên cứu, phân tích 1 chủ đề.

### Nguyên tắc:
1. Trả lời **chi tiết, đầy đủ, có cấu trúc rõ ràng**
2. Nếu có dữ liệu từ Knowledge Base → ưu tiên dùng, ghi nguồn
3. Nếu không chắc → nói rõ "Thông tin này có thể chưa chính xác"
4. Dùng format: headings (##), bullet points, bảng, ví dụ cụ thể
5. Câu hỏi phức tạp → chia thành các phần nhỏ rõ ràng
6. Độ dài: 500-2000 từ tùy độ phức tạp

### Phong cách:
- Thân thiện, chuyên nghiệp, dễ hiểu
- Emoji nhẹ nhàng (📌 💡 ⚠️ ✅)
- Ví dụ cụ thể, minh họa sinh động
- Tiếng Việt trừ khi user dùng tiếng Anh
- Kết thúc bằng tóm tắt ngắn hoặc gợi ý tìm hiểu thêm
PROMPT;
    }

    private function prompt_advisor() {
        return <<<PROMPT
## CHẾ ĐỘ: KNOWLEDGE — TƯ VẤN & KHUYẾN NGHỊ

Người dùng cần lời khuyên, tư vấn, so sánh phương án.

### Nguyên tắc:
1. Trả lời HÀNH ĐỘNG — tập trung vào **nên làm gì, tại sao**
2. Nếu có nhiều phương án → trình bày ưu/nhược của từng phương án
3. Đưa ra khuyến nghị rõ ràng: "Mình gợi ý bạn nên..."
4. Kết thúc bằng **bước tiếp theo cụ thể** mà user có thể thực hiện
5. Nếu có dữ liệu từ Knowledge Base → dùng để hỗ trợ lập luận
6. Độ dài: 300-1000 từ, súc tích hơn research

### Phong cách:
- Như một team leader tư vấn cho đồng nghiệp
- Tự tin nhưng không áp đặt
- Dùng cấu trúc: Tình huống → Phân tích → Khuyến nghị → Bước tiếp theo
- Emoji: 💡 ✅ ⚡ 🎯
- Tiếng Việt trừ khi user dùng tiếng Anh
PROMPT;
    }

    private function prompt_lookup() {
        return <<<PROMPT
## CHẾ ĐỘ: KNOWLEDGE — TRA CỨU NHANH

Người dùng muốn tra cứu dữ liệu, định nghĩa, con số cụ thể.

### Nguyên tắc:
1. Trả lời NGẮN GỌN, ĐI THẲNG VÀO CÂU TRẢ LỜI
2. Dữ liệu cụ thể lên đầu tiên — không giải thích dài dòng
3. Nếu có dữ liệu từ Knowledge Base → trích dẫn chính xác
4. Nếu không biết → nói ngay, không vòng vo
5. Độ dài: 50-300 từ, càng ngắn càng tốt

### Phong cách:
- Nhanh, chính xác, data-first
- Dùng format bảng hoặc bullet nếu cần
- Chỉ thêm context ngắn nếu cần thiết
- Emoji: ít hoặc không dùng
- Tiếng Việt trừ khi user dùng tiếng Anh
PROMPT;
    }

    /**
     * Sprint 1C: Find relevant tools for the user's knowledge question.
     *
     * Lightweight keyword matching against the tool registry (no LLM call).
     * Returns top matches for post-knowledge tool suggestion.
     *
     * @param string $message User message.
     * @return array [ [ 'name' => '...', 'label' => '...' ], ... ]
     */
    private function find_relevant_tools( $message ) {
        if ( ! class_exists( 'BizCity_Intent_Tool_Index' ) ) {
            return [];
        }

        $tools   = BizCity_Intent_Tool_Index::instance()->get_all_active();
        $msg_low = mb_strtolower( $message, 'UTF-8' );
        $matches = [];

        foreach ( $tools as $tool ) {
            $score = 0;
            $name  = $tool['tool_name'] ?? '';
            $desc  = mb_strtolower( $tool['description'] ?? '', 'UTF-8' );
            $label = $tool['goal_label'] ?? $tool['title'] ?? $name;
            $hints = mb_strtolower( $tool['custom_hints'] ?? '', 'UTF-8' );

            // Match tool description keywords against user message
            $desc_words = array_filter( preg_split( '/[\s,\/|]+/u', $desc ), fn( $w ) => mb_strlen( $w ) >= 3 );
            foreach ( $desc_words as $word ) {
                if ( mb_strpos( $msg_low, $word ) !== false ) {
                    $score++;
                }
            }

            // Match custom hints
            if ( $hints ) {
                $hint_words = array_filter( preg_split( '/[\s,\/|]+/u', $hints ), fn( $w ) => mb_strlen( $w ) >= 2 );
                foreach ( $hint_words as $word ) {
                    if ( mb_strpos( $msg_low, $word ) !== false ) {
                        $score += 2;
                    }
                }
            }

            if ( $score >= 2 ) {
                $matches[] = [
                    'name'  => $name,
                    'label' => $label,
                    'score' => $score,
                ];
            }
        }

        // Sort by score descending, return top 3
        usort( $matches, fn( $a, $b ) => $b['score'] - $a['score'] );
        return array_slice( $matches, 0, 3 );
    }

    /**
     * Sprint 1C: Build LLM instruction to naturally suggest relevant tools.
     *
     * @param array $tools [ [ 'name' => '...', 'label' => '...' ], ... ]
     * @return string System prompt instruction.
     */
    private function build_tool_suggestion_hint( array $tools ) {
        $lines = [];
        foreach ( $tools as $t ) {
            $lines[] = "- {$t['name']}: {$t['label']}";
        }
        $tool_list = implode( "\n", $lines );

        return <<<HINT
## 💡 GỢI Ý HÀNH ĐỘNG (Post-Knowledge)

Nếu câu trả lời knowledge liên quan đến công cụ sau, hãy gợi ý TỰ NHIÊN ở cuối câu trả lời:
{$tool_list}

Quy tắc gợi ý:
- Gợi ý ngắn 1 dòng ở cuối, dùng emoji 💡
- Không ép buộc, chỉ gợi mở: "💡 Mình có thể giúp bạn [hành động] ngay — bạn muốn thử không?"
- Nếu KHÔNG liên quan → KHÔNG gợi ý (đừng ép)
- KHÔNG bao giờ thêm lệnh hay syntax kỹ thuật, chỉ gợi ý tự nhiên
HINT;
    }

    /**
     * Get supplementary knowledge from active plugin agents.
     *
     * Queries all registered providers that have knowledge_character_id
     * and returns relevant context for the user's question.
     * This is Selective Context Loading — only relevant agents contribute.
     *
     * @param string $message User's question.
     * @return string  Combined agent knowledge context. Empty if none.
     */
    private function get_agent_knowledge_context( $message ) {
        if ( ! class_exists( 'BizCity_Intent_Provider_Registry' ) ) {
            return '';
        }

        $registry  = BizCity_Intent_Provider_Registry::instance();
        $providers = $registry->get_all();
        $contexts  = [];

        foreach ( $providers as $provider ) {
            $char_id = $provider->get_knowledge_character_id();
            if ( ! $char_id ) {
                continue;
            }

            // Lightweight relevance check: does the message relate to this agent?
            $goals    = $provider->get_goal_patterns();
            $relevant = false;
            foreach ( $goals as $pattern => $config ) {
                if ( preg_match( $pattern, $message ) ) {
                    $relevant = true;
                    break;
                }
            }

            if ( ! $relevant ) {
                continue;
            }

            // Get knowledge from this agent's character
            $ctx = $this->get_knowledge_context( $char_id, $message, [
                'max_tokens' => 1000, // Smaller per agent
            ] );

            if ( $ctx ) {
                $contexts[] = "### 🤖 Kiến thức từ " . $provider->get_name() . ":\n" . $ctx;
            }
        }

        return implode( "\n\n", $contexts );
    }
}

/* ================================================================
 * Ambiguous Pipeline — Ambiguous / Unclear Messages
 *
 * Handles vague, short, social greetings, and unclear intent messages.
 * Responds naturally and may gently ask what the user needs.
 * Lightweight — no RAG, no heavy context loading.
 * ================================================================ */

class BizCity_Ambiguous_Pipeline extends BizCity_Mode_Pipeline {

    public function get_mode() {
        return BizCity_Mode_Classifier::MODE_AMBIGUOUS;
    }

    public function get_label() {
        return 'Ambiguous Mode — Chưa rõ ý định';
    }

    /**
     * Process ambiguous/unclear messages.
     * Returns a compose action with lightweight system prompt.
     * No RAG, no agent knowledge — just a natural, short response.
     */
    public function process( array $ctx ) {
        $message    = $ctx['message'] ?? '';
        $user_id    = intval( $ctx['user_id'] ?? 0 );
        $session_id = $ctx['session_id'] ?? '';
        $channel    = $ctx['channel'] ?? 'webchat';

        $system_parts   = [];
        $system_parts[] = $this->build_system_prompt( $ctx );

        // Only add profile context (xưng hô, preferences) — no RAG
        $platform_type = ( $channel === 'adminchat' ) ? 'ADMINCHAT' : 'WEBCHAT';
        $profile_ctx   = $this->get_profile_context( $user_id, $session_id, $platform_type );
        if ( $profile_ctx ) {
            $system_parts[] = $profile_ctx;
        }

        return [
            'reply'               => '',
            'action'              => 'compose',
            'system_prompt_parts' => $system_parts,
            'memory'              => null, // Don't store ambiguous messages as memory
            'meta'                => [
                'pipeline'    => 'ambiguous',
                'no_tools'    => true,
                'no_json'     => true,
                'temperature' => 0.7,
                'max_tokens'  => 300, // Keep responses short
            ],
        ];
    }

    protected function build_system_prompt( array $ctx ) {
        $message      = $ctx['message'] ?? '';
        $conversation = $ctx['conversation'] ?? null;

        // Build gentle context about what's available
        $available_hint = '';
        if ( class_exists( 'BizCity_Intent_Tool_Index' ) ) {
            $tools = BizCity_Intent_Tool_Index::instance()->get_all_active();
            if ( ! empty( $tools ) ) {
                // Pick 3–4 diverse tool labels for suggestion
                $labels = [];
                foreach ( array_slice( $tools, 0, 4 ) as $t ) {
                    $labels[] = $t['title'] ?? $t['tool_name'];
                }
                $available_hint = "\n\nCông cụ mình có thể giúp: " . implode( ', ', $labels ) . '...';
            }
        }

        // Check conversation context for smarter follow-up
        $context_hint = '';
        if ( ! empty( $conversation ) && is_array( $conversation ) ) {
            $recent_count = count( $conversation );
            if ( $recent_count >= 2 ) {
                $context_hint = "\n\n📎 Lưu ý: Đã có {$recent_count} tin nhắn trước đó trong hội thoại. Hãy dựa vào ngữ cảnh để trả lời thông minh hơn — nếu tin nhắn ngắn mà liên quan đến chủ đề trước thì trả lời theo ngữ cảnh, đừng coi là mơ hồ.";
            }
        }

        return <<<PROMPT
## CHẾ ĐỘ: AMBIGUOUS — TIN NHẮN CHƯA RÕ Ý ĐỊNH (chỉ ~10% traffic)

Tin nhắn cực ngắn (1-3 từ), mơ hồ thuần xã giao: "hi", "ok", "ừ", "hmm".

### Nguyên tắc:
1. Phản hồi TỰ NHIÊN, thân thiện — giống team leader chat với đồng nghiệp
2. NGẮN GỌN — tối đa 2-3 câu
3. **Luôn chuyển hướng nhanh** — mục tiêu là giúp user đi vào knowledge hoặc execution trong lượt tiếp theo
4. Nếu là lời chào → chào lại + **gợi ý 1-2 việc cụ thể** user có thể làm ngay
5. Nếu là phản hồi xã giao (ok, ừ) → ack nhẹ + gợi ý bước tiếp theo rõ ràng
6. KHÔNG để hội thoại chìm — phải có call-to-action
7. KHÔNG trả lời dài dòng, KHÔNG liệt kê tất cả tool

### Ví dụ phản hồi (luôn kèm gợi ý hành động):
- "hi" → "Chào bạn! 👋 Bạn đang cần tìm hiểu gì hay muốn mình hỗ trợ công việc gì nè?"
- "hmm" → "Bạn đang suy nghĩ gì vậy? Kể mình nghe, biết đâu có cách giúp 😊"
- "ok" → "👍 Muốn tiếp tục hay chuyển sang việc khác?"
- "thế à" → "Bạn muốn mình phân tích thêm hay thử cách khác?"
{$available_hint}{$context_hint}
PROMPT;
    }
}

/* ================================================================
 * Mode Pipeline Registry — Manages all pipeline instances
 * ================================================================ */

class BizCity_Mode_Pipeline_Registry {

    /** @var self|null */
    private static $instance = null;

    /** @var BizCity_Mode_Pipeline[] keyed by mode */
    private $pipelines = [];

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        // Register built-in pipelines
        $this->register( new BizCity_Emotion_Pipeline() );
        $this->register( new BizCity_Reflection_Pipeline() );
        $this->register( new BizCity_Knowledge_Pipeline() );
        $this->register( new BizCity_Ambiguous_Pipeline() );

        // Allow plugins to register custom pipelines
        do_action( 'bizcity_mode_register_pipelines', $this );
    }

    /**
     * Register a pipeline.
     *
     * @param BizCity_Mode_Pipeline $pipeline
     */
    public function register( BizCity_Mode_Pipeline $pipeline ) {
        $this->pipelines[ $pipeline->get_mode() ] = $pipeline;
    }

    /**
     * Get pipeline for a given mode.
     *
     * @param string $mode
     * @return BizCity_Mode_Pipeline|null
     */
    public function get( $mode ) {
        return $this->pipelines[ $mode ] ?? null;
    }

    /**
     * Get all registered pipelines.
     *
     * @return BizCity_Mode_Pipeline[]
     */
    public function get_all() {
        return $this->pipelines;
    }

    /**
     * Check if a pipeline exists for a given mode.
     *
     * @param string $mode
     * @return bool
     */
    public function has( $mode ) {
        return isset( $this->pipelines[ $mode ] );
    }
}
