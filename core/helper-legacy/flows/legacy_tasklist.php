<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Helper_Legacy
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

if (!defined('ABSPATH')) exit;

/**
 * =========================================================
 * AI GAP TASKLIST + MISSING SPLITTER (for WaicLogic_un_branch)
 * - Use outside class, call inside WaicAction_ai_generate_content (hoặc node khác)
 * =========================================================
 */

/**
 * 1) Gọi AI để tạo "task list json"
 *    Output mong muốn:
 *    {
 *      "intent": "...",
 *      "entity": "...",
 *      "missing": ["...","..."],
 *      "tone": "..."
 *    }
 *
 * @param string $api_key
 * @param string $user_text
 * @param array  $context  thêm context nếu muốn (vd: product, plan...)
 * @return array {intent, entity, missing[], tone, _raw}
 */
function waic_ai_build_tasklist_json(string $api_key, string $user_text, array $context = []): array {
    $endpoint = 'https://api.openai.com/v1/chat/completions';

    // ====== SYSTEM PROMPT (cứng, ép missing) ======
    $sys = <<<SYS
Bạn là AI phân tích hội thoại bán hàng/CSKH.
Mục tiêu: xuất JSON gồm intent, entity, missing, tone.

QUY TẮC BẮT BUỘC:
1) missing KHÔNG BAO GIỜ được để rỗng nếu câu hỏi không đủ dữ kiện để trả lời CHẮC CHẮN.
2) TUYỆT ĐỐI KHÔNG được "đoán" giá/khuyến mãi/thời hạn. Nếu không có trong Context hoặc Input => phải đưa vào missing.
3) Với mỗi intent, có danh sách REQUIRED FIELDS tối thiểu để trả lời chính xác.
4) Nếu REQUIRED FIELD không xuất hiện trong Context hoặc Input, đưa vào missing.
5) missing phải là mảng string tiếng Việt, ngắn gọn, ưu tiên dạng "tên sản phẩm", "giá hiện tại", "so sánh với ngân sách".

REQUIRED FIELDS theo intent:
- inquire_price (hỏi giá/đắt rẻ): product_name_or_id, price, currency, timeframe (nếu là gói/plan), promotion (nếu có), baseline (so với gì: ngân sách/đối thủ/giá thị trường)
- inquire_feature (hỏi tính năng): product_name_or_id, feature_scope, use_case
- inquire_purchase (mua/đăng ký): product_name_or_id, plan, contact_or_channel
- complain (phàn nàn): issue, order_id, timeframe

CHỈ trả về JSON đúng schema, không thêm chữ thừa, không markdown, không giải thích.
SYS;

    // ====== Context stringify (nếu có) ======
    $ctxText = '';
    if (!empty($context)) {
        $ctxText = "Context (nếu có): " . wp_json_encode($context, JSON_UNESCAPED_UNICODE) . "\n";
    }

    // ====== USER PROMPT ======
    $user = $ctxText
        . "Hãy phân tích Input và trả về JSON theo schema đúng 4 keys:\n"
        . "{\n"
        . "  \"intent\": \"...\",\n"
        . "  \"entity\": \"...\",\n"
        . "  \"missing\": [\"...\"],\n"
        . "  \"tone\": \"...\"\n"
        . "}\n\n"
        . "Input người dùng: " . $user_text . "\n\n"
        . "RÀNG BUỘC:\n"
        . "- Nếu entity mơ hồ như \"sản phẩm này\", \"gói này\" => missing phải có \"tên sản phẩm/gói\".\n"
        . "- Nếu intent = inquire_price và câu hỏi mang nghĩa \"đắt/ rẻ/ giá\" mà KHÔNG có price => missing phải có \"giá hiện tại\".\n"
        . "- Nếu hỏi \"đắt không\" mà KHÔNG có baseline => missing phải có \"so sánh với ngân sách hoặc mức giá mong muốn\".\n"
        . "- Nếu là gói/plan mà KHÔNG rõ thời hạn => missing phải có \"thời hạn gói\".\n";

    $payload = [
        'model' => 'gpt-4.1-mini',
        'messages' => [
            ['role' => 'system', 'content' => $sys],
            ['role' => 'user', 'content' => $user],
        ],
        'max_tokens' => 220,
        'temperature' => 0.2,
    ];

    $res = wp_remote_post($endpoint, [
        'timeout' => 45,
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
        ],
        'body' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
    ]);

    if (is_wp_error($res)) {
        return [
            'intent' => '',
            'entity' => '',
            'missing' => [],
            'tone' => '',
            '_raw' => '',
            '_error' => $res->get_error_message(),
        ];
    }

    $body = json_decode(wp_remote_retrieve_body($res), true);
    $raw  = trim($body['choices'][0]['message']['content'] ?? '');

    // Parse JSON
    $data = waic_parse_json_object($raw);

    $intent  = isset($data['intent']) ? (string)$data['intent'] : '';
    $entity  = isset($data['entity']) ? (string)$data['entity'] : '';
    $tone    = isset($data['tone']) ? (string)$data['tone'] : '';
    $missing = $data['missing'] ?? [];

    // Ép missing về array string sạch
    if (is_string($missing)) {
        $missing = array_filter(array_map('trim', explode(',', $missing)));
    }
    if (!is_array($missing)) $missing = [];
    $missing = array_values(array_filter(array_map(function($x){
        return is_scalar($x) ? trim((string)$x) : '';
    }, $missing)));

    // ====== POST-CHECK (cưỡng bức missing nếu AI vẫn trả rỗng) ======
    $ctxJson = wp_json_encode($context, JSON_UNESCAPED_UNICODE);

    // Entity mơ hồ
    $entity_ambiguous = (!$entity || preg_match('/\b(sản phẩm này|gói này|dịch vụ này|cái này|mẫu này)\b/iu', $entity));

    // Context có price chưa?
    $ctx_has_price = false;
    if (is_array($context)) {
        // check "price" ở nhiều dạng
        $flat = strtolower($ctxJson);
        if (strpos($flat, '"price"') !== false || strpos($flat, 'price_vnd') !== false || strpos($flat, 'giá') !== false) {
            $ctx_has_price = true;
        }
    }

    // Nếu inquire_price mà missing rỗng => ép
    if ($intent === 'inquire_price' && empty($missing)) {
        if ($entity_ambiguous) {
            $missing[] = 'tên sản phẩm/gói';
        }
        if (!$ctx_has_price) {
            $missing[] = 'giá hiện tại';
        }
        // "đắt không" thường cần baseline
        if (preg_match('/đắt|rẻ|cao|thấp|giá|bao nhiêu/iu', $user_text)) {
            $missing[] = 'so sánh với ngân sách hoặc mức giá mong muốn';
        }
        // Nếu có từ gói/plan mà thiếu thời hạn
        if (preg_match('/\bgói\b|\bplan\b|\btháng\b|\bnăm\b/iu', $user_text) && stripos($ctxJson, 'duration') === false && stripos($ctxJson, 'thời hạn') === false) {
            $missing[] = 'thời hạn gói';
        }

        // unique
        $missing = array_values(array_unique(array_filter(array_map('trim', $missing))));
    }

    // (Tuỳ chọn) debug
    if (defined('WAIC_DEBUG') && WAIC_DEBUG) {
        error_log('waic_ai_build_tasklist_json raw: ' . $raw);
        error_log('waic_ai_build_tasklist_json parsed: ' . print_r([
            'intent' => $intent, 'entity' => $entity, 'missing' => $missing, 'tone' => $tone
        ], true));
    }

    return [
        'intent'  => $intent,
        'entity'  => $entity,
        'missing' => $missing,
        'tone'    => $tone,
        '_raw'    => $raw,
    ];
}


/**
 * 2) Split missing -> tạo biến để WaicLogic_un_branch dùng dễ
 *
 * Ý tưởng giống WaicLogic_un_branch:
 * - criteria (chuỗi/number) + operator (contains, equals, is_known...)
 * => nên ta tạo ra các "criteria strings" sẵn:
 *   - missing_csv: "thời hạn, ưu đãi"
 *   - missing_count: 2
 *   - missing_has__thoi_han: 1/0
 *   - missing_0, missing_1 ...
 *
 * @param array $tasklist output từ waic_ai_build_tasklist_json
 * @param array &$variables biến runtime của WAIC (để action/logic node dùng)
 * @param string $prefix
 * @return array $missing (array)
 */
function waic_split_missing_to_variables(array $tasklist, array &$variables, string $prefix = 'missing_'): array {
    $missing = $tasklist['missing'] ?? [];
    if (!is_array($missing)) $missing = [];

    // Chuẩn hoá
    $missing = array_values(array_filter(array_map(function($x){
        return is_scalar($x) ? trim((string)$x) : '';
    }, $missing)));

    // 2.1) Biến tổng hợp
    $variables[$prefix . 'count'] = count($missing);
    $variables[$prefix . 'csv']   = implode(', ', $missing);

    // 2.2) Biến theo index: missing_0, missing_1...
    foreach ($missing as $i => $val) {
        $variables[$prefix . $i] = $val;
    }

    // 2.3) Biến has__<slug> để IF/ELSE check "is_known" hoặc equals 1
    // slug hoá đơn giản, anh có thể đổi rule nếu muốn
    foreach ($missing as $val) {
        $slug = waic_slugify_vi($val); // vd "thời hạn" -> "thoi_han"
        $variables[$prefix . 'has__' . $slug] = 1;
    }

    // 2.4) Nếu không có missing nào, vẫn set vài key để IF ELSE dùng "is_unknown"
    if (empty($missing)) {
        $variables[$prefix . 'csv'] = '';
        // không set has__*
    }

    return $missing;
}

/**
 * 3) Helper: parse JSON object từ output AI (có thể dính ```json ...```)
 */
function waic_parse_json_object(string $raw): array {
    $clean = trim($raw);
    $clean = preg_replace('/^```(?:json)?\s*/i', '', $clean);
    $clean = preg_replace('/```$/', '', $clean);
    $clean = str_replace(['“','”'], '"', $clean);

    $parsed = json_decode($clean, true);
    if (is_array($parsed)) return $parsed;

    if (preg_match('/\{[\s\S]*\}/', $clean, $m)) {
        $parsed = json_decode($m[0], true);
        if (is_array($parsed)) return $parsed;
    }

    return [];
}

/**
 * 4) Helper: slugify tiếng Việt đơn giản cho key has__*
 */
function waic_slugify_vi(string $text): string {
    $text = mb_strtolower($text, 'UTF-8');

    // bỏ dấu tiếng Việt basic
    $map = [
        'à'=>'a','á'=>'a','ạ'=>'a','ả'=>'a','ã'=>'a',
        'â'=>'a','ầ'=>'a','ấ'=>'a','ậ'=>'a','ẩ'=>'a','ẫ'=>'a',
        'ă'=>'a','ằ'=>'a','ắ'=>'a','ặ'=>'a','ẳ'=>'a','ẵ'=>'a',
        'è'=>'e','é'=>'e','ẹ'=>'e','ẻ'=>'e','ẽ'=>'e',
        'ê'=>'e','ề'=>'e','ế'=>'e','ệ'=>'e','ể'=>'e','ễ'=>'e',
        'ì'=>'i','í'=>'i','ị'=>'i','ỉ'=>'i','ĩ'=>'i',
        'ò'=>'o','ó'=>'o','ọ'=>'o','ỏ'=>'o','õ'=>'o',
        'ô'=>'o','ồ'=>'o','ố'=>'o','ộ'=>'o','ổ'=>'o','ỗ'=>'o',
        'ơ'=>'o','ờ'=>'o','ớ'=>'o','ợ'=>'o','ở'=>'o','ỡ'=>'o',
        'ù'=>'u','ú'=>'u','ụ'=>'u','ủ'=>'u','ũ'=>'u',
        'ư'=>'u','ừ'=>'u','ứ'=>'u','ự'=>'u','ử'=>'u','ữ'=>'u',
        'ỳ'=>'y','ý'=>'y','ỵ'=>'y','ỷ'=>'y','ỹ'=>'y',
        'đ'=>'d',
    ];
    $text = strtr($text, $map);

    // thay ký tự lạ thành _
    $text = preg_replace('/[^a-z0-9]+/i', '_', $text);
    $text = trim($text, '_');
    return $text ?: 'x';
}

//=========================
// 5) Helper: Quản lý trạng thái hỏi missing (Hiện tại chỉ dùng cho WAIC)
//=========================
// Tạo key transient lưu trạng thái hỏi missing

// Khởi tạo trạng thái hỏi missing
function waic_hil_start(array $tasklist, string $chat_id, int $blog_id, array $continue = []): array {
    $missing = $tasklist['missing'] ?? [];
    if (!is_array($missing)) $missing = [];
    $missing = array_values(array_filter(array_map('trim', $missing)));

    $state = [
        'blog_id' => $blog_id,
        'chat_id' => $chat_id,
        'created_at' => time(),
        'step' => 0,

        'intent' => (string)($tasklist['intent'] ?? ''),
        'entity' => (string)($tasklist['entity'] ?? ''),
        'tone'   => (string)($tasklist['tone'] ?? ''),

        'missing_all' => $missing,
        'missing_left'=> $missing,

        'answers' => [],
        'continue' => is_array($continue) ? $continue : [],
    ];
    back_trace('NOTICE', 'waic_hil_start chat_id=' . $chat_id . ' state=' . print_r($state, true));
    $key = waic_hil_key($blog_id, $chat_id);
    set_transient($key, $state, 60 * 5); // 5 phút (tuỳ anh)

    // hỏi câu đầu tiên
    waic_hil_ask_next($blog_id, $chat_id);

    return $state;
}

/**
 * Hỏi missing kế tiếp (dùng ai_ask_missing style hoặc hỏi cứng theo field)
 */
function waic_hil_ask_next(int $blog_id, string $chat_id): bool {
    $key = waic_hil_key($blog_id, $chat_id);
    $state = get_transient($key);
    if (!is_array($state)) return false;

    $left = $state['missing_left'] ?? [];
    if (empty($left)) return false;

    $current_missing = (string)$left[0];

    // Có thể hỏi cứng theo mapping cho tự nhiên hơn:
    $q = waic_hil_question_template($state, $current_missing);
    back_trace('NOTICE', '[HIL] Asking question: ' . $q . ' for missing: ' . $current_missing);

    if (function_exists('twf_telegram_send_message')) {
        $sent = twf_telegram_send_message($chat_id, $q);
        back_trace('NOTICE', '[HIL] Sent via twf_telegram_send_message: ' . ($sent ? 'YES' : 'NO'));
    } else if (function_exists('send_zalo_botbanhang') && strpos($chat_id, 'zalo_') === 0) {
        $sent = send_zalo_botbanhang($q, substr($chat_id, 5));
        back_trace('NOTICE', '[HIL] Sent via send_zalo_botbanhang to client_id: ' . substr($chat_id, 5) . ' result: ' . ($sent ? 'YES' : 'NO'));
    } else {
        back_trace('WARNING', '[HIL] No send function available for chat_id: ' . $chat_id);
    }

    $state['step'] = (int)($state['step'] ?? 0) + 1;
    set_transient($key, $state, 60 * 5);
    return true;
}

/**
 * Template hỏi theo missing name (anh tuỳ biến thêm)
 */
function waic_hil_question_template(array $state, string $missing): string {
    $entity = $state['entity'] ?? 'sản phẩm';
    back_trace('NOTICE', '[HIL] Building question for missing: "' . $missing . '" entity: "' . $entity . '"');
    
    switch (mb_strtolower($missing, 'UTF-8')) {
        case 'tên sản phẩm/gói':
            return "Dạ sếp cho em xin chính xác tên gói/sản phẩm mà khách đang hỏi (ví dụ: Gói Pro / Gói Basic) ạ?";
        case 'giá hiện tại':
            return "Dạ mình cho em xin mức giá hiện tại của \"$entity\" (ví dụ: 299k/tháng hoặc 2.990.000/năm) để em báo chính xác ạ?";
        case 'thời hạn gói':
            return "Dạ gói \"$entity\" đang tính theo thời hạn bao lâu ạ (theo tháng hay theo năm)?";
        case 'feature_scope':
            return "Dạ em cần biết bạn muốn hỏi về tính năng gì cụ thể của \"$entity\" ạ?";
        case 'use_case':
            return "Dạ bạn muốn sử dụng \"$entity\" trong trường hợp nào ạ?";
        default:
            return "Dạ mình cho em xin thêm thông tin: \"$missing\" để em trả lời chính xác ạ?";
    }
}
if (!function_exists('waic_hil_key')):
function waic_hil_key(string $chat_id, string $prefix = 'gap_', int $blog_id = 0): string {
    $blog_id = $blog_id > 0 ? $blog_id : (int)get_current_blog_id();
    $chat_id = trim($chat_id);
    if ($chat_id === '') $chat_id = 'anonymous';

    $prefix = sanitize_key(str_replace('-', '_', $prefix));
    if ($prefix === '') $prefix = 'gap';

    // key must be stable + short enough
    $raw = "{$prefix}:{$blog_id}:{$chat_id}";
    $raw = preg_replace('/\s+/', '', $raw);
    // avoid extremely long chat_id
    if (strlen($raw) > 160) $raw = substr($raw, 0, 160);

    return 'waic_hil:' . $raw;
}
endif;

/**
 * Helper: Check HIL state và quyết định pause/continue
 * Dùng chung cho un_confirm và ai_plan_validator
 * 
 * @param string $chat_id Chat ID để check HIL state
 * @param int $timeout_seconds Timeout (seconds), default 1800 = 30 phút
 * @param int $blog_id Blog ID (default current blog)
 * @return array ['status' => 'waiting'|'completed'|'timeout'|'not_found', 'waiting' => timestamp|0, 'answers' => array, 'elapsed' => seconds]
 */
/**
 * Helper: Pause workflow và đặt callback resume
 * Dùng trực tiếp trong node (ai_plan_validator, un_confirm, etc.)
 * 
 * @param int $timeout_seconds Thời gian pause (seconds)
 * @param callable|null $resume_callback Callback để check khi resume (optional)
 * @param array $context Context data để lưu lại (optional)
 * @return int Timestamp khi sẽ resume (waiting until)
 */
if (!function_exists('waic_pause_workflow')):
function waic_pause_workflow(int $timeout_seconds = 1800, $resume_callback = null, array $context = []): int {
    $now = time();
    $waiting_until = $now + $timeout_seconds;
    
    // Lưu context nếu cần (cho resume callback)
    if (!empty($context)) {
        $context_key = 'waic_pause_context_' . md5(json_encode($context));
        set_transient($context_key, $context, $timeout_seconds + 300); // +5 phut buffer
        error_log('[waic_pause_workflow] Saved context: ' . $context_key);
    }
    
    // Đăng ký resume callback nếu có
    if (is_callable($resume_callback)) {
        $hook_name = 'waic_workflow_resume_check_' . $now;
        
        // Schedule periodic check (mỗi 10s) để kiểm tra điều kiện resume
        $check_time = $now + 10;
        while ($check_time < $waiting_until) {
            wp_schedule_single_event($check_time, $hook_name, array($context));
            $check_time += 10;
        }
        
        // Đăng ký callback
        add_action($hook_name, function($ctx) use ($resume_callback) {
            $should_resume = call_user_func($resume_callback, $ctx);
            if ($should_resume) {
                error_log('[waic_pause_workflow] Resume condition met, triggering resume');
                // Clear scheduled checks
                wp_clear_scheduled_hook($hook_name);
                // Fire resume trigger
                do_action('waic_workflow_resume', $ctx);
            }
        }, 10, 1);
        
        error_log('[waic_pause_workflow] Registered resume callback');
    }
    
    error_log('[waic_pause_workflow] Pausing until: ' . date('Y-m-d H:i:s', $waiting_until) . ' (timeout: ' . $timeout_seconds . 's)');
    
    return $waiting_until;
}
endif;

/**
 * Helper: Tạo pause signal cho HIL
 * Wrapper tiện lợi cho waic_pause_workflow() với HIL context
 * 
 * @param string $chat_id Chat ID đang chờ HIL
 * @param int $timeout_seconds Timeout (default 1800 = 30 phut)
 * @param int $blog_id Blog ID
 * @return int Waiting timestamp
 */
if (!function_exists('waic_pause_for_hil')):
function waic_pause_for_hil(string $chat_id, int $timeout_seconds = 1800, int $blog_id = 0): int {
    $blog_id = $blog_id > 0 ? $blog_id : (int)get_current_blog_id();
    
    // Context cho resume callback
    $context = array(
        'chat_id' => $chat_id,
        'blog_id' => $blog_id,
        'pause_type' => 'hil',
        'started_at' => time(),
    );
    
    // Resume callback: Check nếu HIL completed
    $resume_callback = function($ctx) {
        if (function_exists('waic_hil_wait_or_continue')) {
            $status = waic_hil_wait_or_continue($ctx['chat_id'], 0, $ctx['blog_id']);
            return $status['status'] === 'completed';
        }
        return false;
    };
    
    return waic_pause_workflow($timeout_seconds, $resume_callback, $context);
}
endif;

if (!function_exists('waic_hil_wait_or_continue')):
function waic_hil_wait_or_continue(string $chat_id, int $timeout_seconds = 1800, int $blog_id = 0): array {
    $blog_id = $blog_id > 0 ? $blog_id : (int)get_current_blog_id();
    $key = waic_hil_key($blog_id, $chat_id);
    $state = get_transient($key);
    
    // HIL không tồn tại
    if (!is_array($state)) {
        return array(
            'status' => 'not_found',
            'waiting' => 0,
            'answers' => array(),
            'elapsed' => 0,
        );
    }
    
    $created_at = (int)($state['created_at'] ?? 0);
    $completed = !empty($state['completed']);
    $missing_left = $state['missing_left'] ?? array();
    $elapsed = time() - $created_at;
    
    // HIL đã completed
    if ($completed || empty($missing_left)) {
        return array(
            'status' => 'completed',
            'waiting' => 0,
            'answers' => $state['answers'] ?? array(),
            'elapsed' => $elapsed,
        );
    }
    
    // Check timeout
    if ($elapsed >= $timeout_seconds) {
        return array(
            'status' => 'timeout',
            'waiting' => 0,
            'answers' => $state['answers'] ?? array(),
            'elapsed' => $elapsed,
        );
    }
    
    // Đang chờ user trả lời
    return array(
        'status' => 'waiting',
        'waiting' => $created_at + $timeout_seconds, // timestamp khi sẽ timeout
        'answers' => $state['answers'] ?? array(),
        'elapsed' => $elapsed,
        'missing_left' => $missing_left,
    );
}
endif;
/**
 * Xử lý input user trả lời missing (trả về true nếu có xử lý)
 */
function waic_hil_maybe_handle_incoming(string $chat_id, string $user_text, int $blog_id): bool {
    $key = waic_hil_key($blog_id, $chat_id);
    $state = get_transient($key);
    back_trace('NOTICE', 'waic_hil_maybe_handle_incoming chat_id=' . $chat_id . ' key=' . $key . ' state=' . print_r($state, true));
    if (!is_array($state)) return false; // không có HIL
    back_trace('NOTICE', 'waic_hil_maybe_handle_incoming found HIL state');        
    // Nếu đã completed, không handle nữa
    if (!empty($state['completed'])) return false;
    back_trace('NOTICE', 'waic_hil_maybe_handle_incoming HIL not completed yet');               
    $left = $state['missing_left'] ?? [];
    if (empty($left)) {
        delete_transient($key);
        return false;
    }
    back_trace('NOTICE', 'waic_hil_maybe_handle_incoming HIL has missing left: ' . print_r($left, true));               
    // Lấy missing hiện tại

    $current_missing = (string)$left[0];
    $slug = waic_slugify_vi($current_missing);

    // Lưu câu trả lời
    $state['answers'][$slug] = trim((string)$user_text);

    // Pop missing hiện tại
    array_shift($left);
    $state['missing_left'] = array_values($left);

    // Update transient
    set_transient($key, $state, 60 * 30);
    back_trace('NOTICE', 'waic_hil_maybe_handle_incoming saved answer for ' . $current_missing . ': ' . $user_text);        
    // Còn thiếu → hỏi tiếp
    if (!empty($left)) {
        waic_hil_ask_next($blog_id, $chat_id);
        return true;
    }
    back_trace('NOTICE', 'waic_hil_maybe_handle_incoming all missing answered, completing HIL');
    // Đủ rồi → complete
    $brief = build_brief($state, 'human'); 
    if (function_exists('twf_telegram_send_message')) {
        twf_telegram_send_message($chat_id, $brief);
    } else if (function_exists('send_zalo_botbanhang') && strpos($chat_id, 'zalo_') === 0) {
        send_zalo_botbanhang($brief, substr($chat_id, 5));
    }
    
    waic_hil_complete($state);

    // cleanup HIL state HOÀN TOÀN để workflow mới có thể bắt đầu HIL fresh
    $key = waic_hil_key($state['blog_id'], $state['chat_id']);
    delete_transient($key);
    back_trace('NOTICE', '[HIL] Cleaned up HIL state key: ' . $key);
    
    // CLEAR lastRun cho chat_id này để cho phép workflow chạy lại
    $client_id = str_replace('zalo_', '', $state['chat_id']);
    if (class_exists('WaicFrame') && function_exists('get_current_blog_id')) {
        try {
            $flowRunModel = WaicFrame::_()->getModule('workflow')->getModel('flowruns');
            if ($flowRunModel && method_exists($flowRunModel, 'clearLastRunForObj')) {
                $flowRunModel->clearLastRunForObj($client_id);
                back_trace('NOTICE', '[HIL] Cleared lastRun for obj_id: ' . $client_id);
            } else {
                // Manual clear via DELETE (not UPDATE to avoid column length issue)
                global $wpdb;
                $table = $wpdb->prefix . 'waic_flowruns';
                $deleted = $wpdb->delete(
                    $table,
                    ['obj_id' => $client_id],
                    ['%s']
                );
                back_trace('NOTICE', '[HIL] Deleted ' . $deleted . ' flowruns for obj_id: ' . $client_id);
            }
        } catch (\Exception $e) {
            back_trace('WARNING', '[HIL] Failed to clear lastRun: ' . $e->getMessage());
        }
    }
    return true;
}
function clear_hil_state(int $blog_id, string $chat_id): bool {
    if ($blog_id <= 0 || $chat_id === '') return false;
    $key = 'waic_hil_' . $blog_id . '_' . $chat_id;
    delete_transient($key);
    return true;
}

// Hoàn tất hỏi missing, gọi action tiếp theo
function waic_hil_complete(array $state): void {
    $key = waic_hil_key($state['blog_id'], $state['chat_id']);
    $state['completed'] = true;
    set_transient($key, $state, 60 * 30);
            
    $payload = [
        'hil' => [
            'intent' => $state['intent'] ?? '',
            'entity' => $state['entity'] ?? '',
            'tone'   => $state['tone'] ?? '',
            'answers'=> $state['answers'] ?? [],
            'missing_all' => $state['missing_all'] ?? [],
        ],
        'continue' => $state['continue'] ?? [],
        'blog_id' => (int)($state['blog_id'] ?? 0),
        'chat_id' => (string)($state['chat_id'] ?? ''),
    ];

    do_action('waic_hil_completed', $payload);

    // Trigger workflow lại với flag hil_completed để force resume và skip đến compose
    $twf_trigger = [
        'platform' => 'zalo',
        'text' => '[HIL_COMPLETED]', // dummy text
        'chat_id' => $state['chat_id'],
        'hil_completed' => true,
        'raw' => json_encode($payload, JSON_UNESCAPED_UNICODE),
        'hil_resume' => true,
    ];
    if (function_exists('bizcity_aiwu_fire_twf_process_flow')) {
        // Build trigger đơn giản để fire lại workflow với context HIL completed
        $twf_trigger = [
            'platform' => 'zalo',
            'client_id' => str_replace('zalo_', '', $state['chat_id']),
            'chat_id' => str_replace('zalo_', '', $state['chat_id']),
            'text' => 'hỏi hiện trạng HIL completed', // text có chứa "hỏi" để match workflow
            'raw' => $payload,
            'attachment_url' => '',
            'attachment_type' => '',
            'twf_platform' => 'zalo',
            'twf_client_id' => str_replace('zalo_', '', $state['chat_id']),
            'twf_chat_id' => str_replace('zalo_', '', $state['chat_id']),
            'twf_text' => 'hỏi hiện trạng HIL completed',
            'message_id' => 'hil_' . time(),
            'obj_id' => str_replace('zalo_', '', $state['chat_id']) . '_hil_complete_' . time(),
            'hil_completed' => true,
            'hil_resume' => true,
            'hil_result_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ];
        
        back_trace('NOTICE', '[HIL] Firing COMPLETED trigger: ' . print_r($twf_trigger, true));
        bizcity_aiwu_fire_twf_process_flow($twf_trigger, (array)($payload ?? []));
        
    }
}

function build_brief(array $state, string $mode = 'human'): string {
        $intent = (string)($state['intent'] ?? '');
        $entity = (string)($state['entity'] ?? '');
        $tone   = (string)($state['tone'] ?? '');

        $missing_all = $state['missing_all'] ?? [];
        if (!is_array($missing_all)) $missing_all = [];

        $answers = $state['answers'] ?? [];
        if (!is_array($answers)) $answers = [];

        if ($mode === 'json') {
            return wp_json_encode([
                'intent' => $intent,
                'entity' => $entity,
                'tone'   => $tone,
                'missing_all' => $missing_all,
                'answers' => $answers,
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }

        $lines[] = "✅ Em tổng kết lại thông tin mình vừa chốt:";
        if ($intent !== '') $lines[] = "• Ý định: {$intent}";
        if ($entity !== '') $lines[] = "• Đối tượng: {$entity}";
        if ($tone !== '')   $lines[] = "• Trạng thái: {$tone}";
        $lines[] = "";

        // ===== Normalize answers =====
        $answers = $answers ?? [];
        if (is_string($answers)) {
            $try = json_decode($answers, true);
            if (is_array($try)) $answers = $try;
        }
        if (!is_array($answers)) $answers = [];

        // trim key/value
        $answers_norm = [];
        foreach ($answers as $k0 => $v0) {
            $k = trim((string)$k0);
            $v = is_scalar($v0) ? trim((string)$v0) : '';
            if ($k !== '') $answers_norm[$k] = $v;
        }
        $answers = $answers_norm;

        // ===== Map từng missing -> answer =====
        if (!empty($missing_all) && is_array($missing_all)) {
            $lines[] = "📌 Thông tin đã bổ sung:";
            foreach ($missing_all as $q) {
                $q = trim((string)$q);
                if ($q === '') continue;

                $k = slugify_vi($q); // "tên sản phẩm/gói" -> "ten_san_pham_goi"

                // 1) direct match
                $a = $answers[$k] ?? '';

                // 2) fallback: nếu không có, thử match theo contains key (đề phòng slugify lệch)
                if ($a === '') {
                    foreach ($answers as $ak => $av) {
                        if ($ak === $k) { $a = $av; break; }
                        // ví dụ key có tiền tố/suffix
                        if (strpos($ak, $k) !== false || strpos($k, $ak) !== false) {
                            $a = $av;
                            break;
                        }
                    }
                }

                if ($a === '') $a = '(chưa trả lời)';
                $lines[] = "• {$q}: {$a}";
            }

            // OPTIONAL debug keys nếu anh bật WAIC_DEBUG
            if (defined('WAIC_DEBUG') && WAIC_DEBUG) {
                $lines[] = "";
                $lines[] = "🔎 Debug keys:";
                $lines[] = "• answers_keys: " . implode(', ', array_keys($answers));
                $lines[] = "• missing_keys: " . implode(', ', array_map([$this,'slugify_vi'], array_filter(array_map('strval',$missing_all))));
            }

        } else {
            $lines[] = "📌 Không có trường thông tin thiếu nào.";
        }


        return implode("\n", $lines);
    }
    function slugify_vi(string $text): string {
        if (function_exists('waic_slugify_vi')) {
            return waic_slugify_vi($text);
        }
        $text = mb_strtolower($text, 'UTF-8');
        $map = [
            'à'=>'a','á'=>'a','ạ'=>'a','ả'=>'a','ã'=>'a',
            'â'=>'a','ầ'=>'a','ấ'=>'a','ậ'=>'a','ẩ'=>'a','ẫ'=>'a',
            'ă'=>'a','ằ'=>'a','ắ'=>'a','ặ'=>'a','ẳ'=>'a','ẵ'=>'a',
            'è'=>'e','é'=>'e','ẹ'=>'e','ẻ'=>'e','ẽ'=>'e',
            'ê'=>'e','ề'=>'e','ế'=>'e','ệ'=>'e','ể'=>'e','ễ'=>'e',
            'ì'=>'i','í'=>'i','ị'=>'i','ỉ'=>'i','ĩ'=>'i',
            'ò'=>'o','ó'=>'o','ọ'=>'o','ỏ'=>'o','õ'=>'o',
            'ô'=>'o','ồ'=>'o','ố'=>'o','ộ'=>'o','ổ'=>'o','ỗ'=>'o',
            'ơ'=>'o','ờ'=>'o','ớ'=>'o','ợ'=>'o','ở'=>'o','ỡ'=>'o',
            'ù'=>'u','ú'=>'u','ụ'=>'u','ủ'=>'u','ũ'=>'u',
            'ư'=>'u','ừ'=>'u','ứ'=>'u','ự'=>'u','ử'=>'u','ữ'=>'u',
            'ỳ'=>'y','ý'=>'y','ỵ'=>'y','ỷ'=>'y','ỹ'=>'y',
            'đ'=>'d',
        ];
        $text = strtr($text, $map);
        $text = preg_replace('/[^a-z0-9]+/i', '_', $text);
        $text = trim($text, '_');
        return $text ?: 'x';
    }

// Thêm hook listener để delete transient sau khi workflow complete (tùy chọn, add vào bootstrap hoặc nơi khác)
add_action('waic_hil_completed', function($payload) {
    $blog_id = $payload['blog_id'];
    $chat_id = $payload['chat_id'];
    $key = waic_hil_key($blog_id, $chat_id);
    delete_transient($key); // Delete sau khi workflow xử lý xong
}, 10, 1);
