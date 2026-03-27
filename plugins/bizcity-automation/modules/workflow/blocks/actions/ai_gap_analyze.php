<?php
if (!defined('ABSPATH')) exit;

/**
 * =========================================================
 * WaicAction_ai_gap_analyze
 * - Node phân tích "intent/entity/missing/tone" từ input người dùng
 * - Split missing -> variables để WaicLogic_un_branch IF/ELSE dùng ngay
 * =========================================================
 *
 * Gợi ý đặt file:
 * /wp-content/plugins/ai-copilot-content-generator/includes/actions/class-waicaction-ai-gap-analyze.php
 *
 * Yêu cầu có các helper function (ngoài class) trong:
 * /wp-content/mu-plugins/bizcity-admin-hook/includes/flows/content.php
 * - waic_ai_build_tasklist_json()
 * - waic_split_missing_to_variables()
 * - waic_parse_json_object()
 * - waic_slugify_vi()
 */

class WaicAction_ai_gap_analyze extends WaicAction {
    protected $_code  = 'ai_gap_analyze';
    protected $_order = 98;

    public function __construct($block = null) {
        $this->_name = __('AI - Lắng nghe và đặt câu hỏi', 'ai-copilot-content-generator');
        $this->_desc = __('Phân tích câu hỏi và đặt câu hỏi tiếp theo nếu còn thiếu thông tin.', 'ai-copilot-content-generator');
        $this->_sublabel = array('name');
        $this->setBlock($block);
    }

    public function getSettings() {
        if (empty($this->_settings)) $this->setSettings();
        return $this->_settings;
    }

    public function setSettings() {
        $this->_settings = array(
            'name' => array(
                'type' => 'input',
                'label' => __('Node Name', 'ai-copilot-content-generator'),
                'default' => 'AI Gap Analyze',
            ),
            'chat_id' => array(
                'type' => 'input',
                'label' => __('Chat ID (optional)', 'ai-copilot-content-generator'),
                'default' => '{{node#1.twf_chat_id}}',
                'variables' => true,
            ),
            'user_text' => array(
                'type' => 'textarea',
                'label' => __('User Text / Input cần phân tích', 'ai-copilot-content-generator'),
                'default' => '{{node#1.twf_text}}',
                'variables' => true,
                'desc' => __('Ví dụ: {{node#1.twf_text}}', 'ai-copilot-content-generator'),
            ),
            'context_json' => array(
                'type' => 'textarea',
                'label' => __('Context JSON (optional)', 'ai-copilot-content-generator'),
                'default' => '',
                'variables' => true,
                'desc' => __('Ví dụ: {"catalog":["gói Pro","gói Basic"],"pricing":{"gói Pro":{"price_vnd":2990000,"duration":"12 tháng"}}}', 'ai-copilot-content-generator'),
            ),
            'prefix' => array(
                'type' => 'input',
                'label' => __('Prefix biến missing (gap_)', 'ai-copilot-content-generator'),
                'default' => 'gap_',
                'variables' => true,
                'desc' => __('Sẽ tạo: gap_count, gap_csv, gap_has__thoi_han...', 'ai-copilot-content-generator'),
            ),

            // ===== NEW: mơ hồ entity =====
            'ambiguous_phrases' => array(
                'type' => 'textarea',
                'label' => __('Ambiguous phrases (mỗi dòng 1 cụm)', 'ai-copilot-content-generator'),
                'default' => "sản phẩm này\ngói này\ndịch vụ này\ncái này\nmẫu này",
                'variables' => true,
                'desc' => __("Các cụm từ làm entity mơ hồ. Nếu entity match -> thêm missing \"tên sản phẩm/gói\" (hoặc label tuỳ chỉnh).", 'ai-copilot-content-generator'),
            ),

            // ===== NEW: keywords để hiểu “hỏi giá/đắt rẻ” =====
            'price_keywords' => array(
                'type' => 'input',
                'label' => __('Price keywords (regex simple)', 'ai-copilot-content-generator'),
                'default' => 'đắt|rẻ|cao|thấp|giá|bao nhiêu|cost|price',
                'variables' => true,
                'desc' => __('Node dùng regex này để quyết định có cần missing baseline/price không.', 'ai-copilot-content-generator'),
            ),

            // ===== NEW: required fields by intent (JSON) =====
            'required_by_intent' => array(
                'type' => 'textarea',
                'label' => __('Required fields by intent (JSON)', 'ai-copilot-content-generator'),
                'default' =>
    "{\n".
    "  \"inquire_price\": [\"product_name_or_id\",\"price\",\"baseline\",\"timeframe\"],\n".
    "  \"inquire_feature\": [\"product_name_or_id\",\"use_case\"],\n".
    "  \"inquire_purchase\": [\"product_name_or_id\",\"plan\",\"contact_or_channel\"],\n".
    "  \"complain\": [\"issue\",\"order_id\",\"timeframe\"]\n".
    "}",
                'variables' => true,
                'desc' => __("JSON map: intent => danh sách field bắt buộc. Nếu field chưa có trong context/input thì bơm vào missing.", 'ai-copilot-content-generator'),
            ),

            // ===== NEW: label map (field -> tiếng Việt hiển thị) =====
            'missing_label_map' => array(
                'type' => 'textarea',
                'label' => __('Missing label map (JSON)', 'ai-copilot-content-generator'),
                'default' =>
    "{\n".
    "  \"product_name_or_id\": \"tên sản phẩm/gói\",\n".
    "  \"price\": \"giá hiện tại\",\n".
    "  \"baseline\": \"so sánh với ngân sách hoặc mức giá mong muốn\",\n".
    "  \"timeframe\": \"thời hạn gói\",\n".
    "  \"promotion\": \"ưu đãi hiện có\"\n".
    "}",
                'variables' => true,
                'desc' => __('Map field kỹ thuật -> câu missing hiển thị cho khách/nhân viên.', 'ai-copilot-content-generator'),
            ),

            // ===== model/temperature để dự phòng =====
            'model' => array(
                'type' => 'input',
                'label' => __('Model (optional)', 'ai-copilot-content-generator'),
                'default' => 'gpt-4.1-mini',
                'variables' => true,
            ),
            'temperature' => array(
                'type' => 'input',
                'label' => __('Temperature (optional)', 'ai-copilot-content-generator'),
                'default' => '0.2',
                'variables' => true,
            ),
        );
    }


    public function getVariables() {
        if (empty($this->_variables)) $this->setVariables();
        return $this->_variables;
    }

    public function setVariables() {
        $this->_variables = array(
            'task_intent'  => __('Intent (ý định)', 'ai-copilot-content-generator'),
            'task_entity'  => __('Entity (đối tượng)', 'ai-copilot-content-generator'),
            'task_tone'    => __('Tone (tông/độ phân vân)', 'ai-copilot-content-generator'),
            'task_missing_csv'   => __('Missing CSV', 'ai-copilot-content-generator'),
            'task_missing_count' => __('Missing Count', 'ai-copilot-content-generator'),
            'task_answers_json'  => __('HIL Answers JSON', 'ai-copilot-content-generator'),
            'task_hil_completed' => __('HIL Completed (1/0)', 'ai-copilot-content-generator'),
            'task_missing_json'  => __('Missing JSON array', 'ai-copilot-content-generator'),
            'task_raw'     => __('Raw AI output', 'ai-copilot-content-generator'),
            'task_fill' => __('Fill (1=đã đủ info, 0=chưa đủ)', 'ai-copilot-content-generator'),
            'task_missing_left_count' => __('Số missing còn thiếu (từ HIL)', 'ai-copilot-content-generator'),

            
            // Ngoài ra còn tạo động: {prefix}count, {prefix}csv, {prefix}0..n, {prefix}has__*
        );
        return $this->_variables;
    }
    private function hil_key(string $tpl, int $blog_id, string $chat_id): string {
        $k = str_replace(['{blog_id}','{chat_id}'], [(string)$blog_id, (string)$chat_id], $tpl);
        return preg_replace('/\s+/', '', $k);
    }

    private function hil_is_filled(array $state): array {
        // return [filled(bool), left_count(int)]
        $missing_left = $state['missing_left'] ?? null;
        $missing_all  = $state['missing_all'] ?? null;
        $answers      = $state['answers'] ?? [];

        if (is_string($missing_left)) {
            $t = json_decode($missing_left, true);
            if (is_array($t)) $missing_left = $t;
        }
        if (is_string($missing_all)) {
            $t = json_decode($missing_all, true);
            if (is_array($t)) $missing_all = $t;
        }
        if (is_string($answers)) {
            $t = json_decode($answers, true);
            if (is_array($t)) $answers = $t;
        }

        if (!is_array($answers)) $answers = [];
        // normalize answers keys
        $ans = [];
        foreach ($answers as $k => $v) {
            $k = trim((string)$k);
            $v = is_scalar($v) ? trim((string)$v) : '';
            if ($k !== '') $ans[$k] = $v;
        }
        $answers = $ans;

        // ưu tiên missing_left (đúng nghĩa còn thiếu)
        $leftList = is_array($missing_left) ? $missing_left : [];
        if (empty($leftList) && is_array($missing_all)) {
            // fallback: tính lại left theo missing_all - answers
            foreach ($missing_all as $q) {
                $q = trim((string)$q);
                if ($q === '') continue;
                $slug = function_exists('waic_slugify_vi') ? waic_slugify_vi($q) : sanitize_key($q);
                $val  = $answers[$slug] ?? '';
                if ($val === '') $leftList[] = $q;
            }
        }

        $leftList = array_values(array_filter(array_map('trim', (array)$leftList)));
        $left_count = count($leftList);

        return [
            'filled' => ($left_count <= 0),
            'left_count' => $left_count,
            'left_list' => $leftList,
        ];
    }

    public function getResults($taskId, $variables, $step = 0) {
        $chat_id     = $this->replaceVariables($this->getParam('chat_id'), $variables);
        $user_text   = $this->replaceVariables($this->getParam('user_text'), $variables);
        $context_raw = $this->replaceVariables($this->getParam('context_json'), $variables);
        $prefix      = $this->replaceVariables($this->getParam('prefix'), $variables);
        $prefix      = $prefix ? sanitize_key(str_replace('-', '_', $prefix)) . '_' : 'gap_';

        // ===== HIL COMPLETED CHECK =====
        // If HIL completed, skip gap_analyze and return HIL result
        if (!empty($variables['hil_completed']) || !empty($variables['hil_result_json'])) {
            back_trace('NOTICE', '[AI_GAP_ANALYZE] HIL completed detected, returning HIL result');
            
            $hil_json = $variables['hil_result_json'] ?? '';
            if (!is_string($hil_json)) {
                $hil_json = wp_json_encode($variables['hil_result_json'] ?? [], JSON_UNESCAPED_UNICODE);
            }
            
            $hil_data = json_decode($hil_json, true);
            if (!is_array($hil_data)) $hil_data = [];
            
            $hil = $hil_data['hil'] ?? [];
            $answers = $hil['answers'] ?? [];
            
            return [
                'result' => [
                    'task_intent' => $hil['intent'] ?? '',
                    'task_entity' => $hil['entity'] ?? '',
                    'task_tone' => $hil['tone'] ?? '',
                    'task_missing_csv' => '', // HIL completed = no missing
                    'task_missing_count' => 0,
                    'task_missing_json' => '[]',
                    'task_answers_json' => wp_json_encode($answers, JSON_UNESCAPED_UNICODE),
                    'task_hil_completed' => '1',
                    'task_raw' => $hil_json,
                ],
                'error' => '',
                'status' => 3, // Success
            ];
        }

        error_log('gap step 1: '.print_r($user_text, true));
        // Ensure helper loaded
        if (!function_exists('waic_ai_build_tasklist_json') || !function_exists('waic_split_missing_to_variables')) {
            $helper = WP_CONTENT_DIR . '/mu-plugins/bizcity-admin-hook/includes/flows/tasklist.php';
            if (file_exists($helper)) {
                require_once $helper;
            }
        }

        $error = '';
        $status = 3;
    
        if (empty($user_text)) {
            $error = 'Thiếu user_text để phân tích.';
            $status = 7;
            $this->_results = array(
                'result' => array(
                    'task_intent' => '',
                    'task_entity' => '',
                    'task_tone' => '',
                    'task_missing_csv' => '',
                    'task_missing_count' => 0,
                    'task_missing_json' => '[]',
                    'task_raw' => '',
                ),
                'error' => $error,
                'status' => $status,
            );
            return $this->_results;
        }

        if (!function_exists('waic_ai_build_tasklist_json')) {
            $error = 'Helper waic_ai_build_tasklist_json() chưa được load. Kiểm tra file mu-plugin tasklist.php.';
            $status = 7;
            $this->_results = array(
                'result' => array(),
                'error' => $error,
                'status' => $status,
            );
            return $this->_results;
        }

        // Parse context json (optional)
        $context = array();
        if (is_string($context_raw) && trim($context_raw) !== '') {
            $decoded = json_decode($context_raw, true);
            if (is_array($decoded)) $context = $decoded;
        }

        // API key
        $api_key = get_option('twf_openai_api_key');
        if (empty($api_key)) {
            $error = 'Thiếu twf_openai_api_key trong options.';
            $status = 7;
            $this->_results = array(
                'result' => array(),
                'error' => $error,
                'status' => $status,
            );
            return $this->_results;
        }

        // 1) Build tasklist
        $tasklist = waic_ai_build_tasklist_json($api_key, $user_text, $context);
        error_log('waic_ai_build_tasklist_json: '.print_r($tasklist, true));
        // ===== Dynamic enforce missing (user-config) =====
        $enforce = true;

        if ($enforce) {
            $intent  = (string)($tasklist['intent'] ?? '');
            $entity  = (string)($tasklist['entity'] ?? '');
            $missing = is_array($tasklist['missing'] ?? null) ? $tasklist['missing'] : array();

            // Parse required_by_intent
            $reqRaw = $this->replaceVariables($this->getParam('required_by_intent'), $variables);
            $requiredByIntent = json_decode($reqRaw, true);
            if (!is_array($requiredByIntent)) $requiredByIntent = array();

            // Parse label map
            $mapRaw = $this->replaceVariables($this->getParam('missing_label_map'), $variables);
            $labelMap = json_decode($mapRaw, true);
            if (!is_array($labelMap)) $labelMap = array();

            // Ambiguous phrases (each line)
            $ambRaw = $this->replaceVariables($this->getParam('ambiguous_phrases'), $variables);
            $ambList = array_filter(array_map('trim', preg_split("/\r\n|\n|\r/", (string)$ambRaw)));

            // Price keyword regex
            $priceKw = (string)$this->replaceVariables($this->getParam('price_keywords'), $variables);
            $priceKw = trim($priceKw);
            if ($priceKw === '') $priceKw = 'đắt|rẻ|cao|thấp|giá|bao nhiêu|cost|price';

            // helper: check context has key
            $ctxJson = wp_json_encode($context, JSON_UNESCAPED_UNICODE);
            $ctxLower = strtolower($ctxJson);

            $ctxHas = function(string $needle) use ($ctxLower): bool {
                $needle = strtolower($needle);
                // check key presence as "needle" or fallback substring
                return (strpos($ctxLower, '"' . $needle . '"') !== false) || (strpos($ctxLower, $needle) !== false);
            };

            // 1) Entity ambiguous? (by entity OR by user_text)
            $entityAmbiguous = false;
            $hay = mb_strtolower(($entity ?: $user_text), 'UTF-8');
            foreach ($ambList as $ph) {
                if ($ph !== '' && mb_strpos($hay, mb_strtolower($ph, 'UTF-8')) !== false) {
                    $entityAmbiguous = true;
                    break;
                }
            }
            if ($entityAmbiguous) {
                $missing[] = $labelMap['product_name_or_id'] ?? 'tên sản phẩm/gói';
            }

            // 2) Required fields by intent -> missing if not in context and not in user_text
            $requiredFields = $requiredByIntent[$intent] ?? array();
            if (is_array($requiredFields)) {
                foreach ($requiredFields as $field) {
                    if (!is_string($field) || $field === '') continue;

                    $label = $labelMap[$field] ?? $field;

                    $hasInCtx = $ctxHas($field) || $ctxHas($label);
                    $hasInMsg = (mb_stripos($user_text, $field) !== false) || (mb_stripos($user_text, $label) !== false);

                    if (!$hasInCtx && !$hasInMsg) {
                        $missing[] = $label;
                    }
                }
            }

            // 3) Special: inquire_price + hỏi đắt/rẻ => baseline/price
            if ($intent === 'inquire_price' && preg_match('/' . $priceKw . '/iu', $user_text)) {
                // ensure price
                if (!$ctxHas('price') && !$ctxHas('price_vnd') && !$ctxHas('giá')) {
                    $missing[] = $labelMap['price'] ?? 'giá hiện tại';
                }
                // ensure baseline
                $missing[] = $labelMap['baseline'] ?? 'so sánh với ngân sách hoặc mức giá mong muốn';
            }

            // Unique + clean
            $missing = array_values(array_unique(array_filter(array_map('trim', $missing))));

            // Push back into tasklist for downstream split
            $tasklist['missing'] = $missing;
        }

        if (!empty($tasklist['_error'])) {
            $error = (string)$tasklist['_error'];
            $status = 7;
        }

        // 2) Split missing -> inject variables
        $missing = array();
        if (function_exists('waic_split_missing_to_variables')) {
            $missing = waic_split_missing_to_variables($tasklist, $variables, $prefix);
        } else {
            // fallback nhẹ: vẫn set csv/count
            $missing = is_array($tasklist['missing'] ?? null) ? $tasklist['missing'] : array();
            $variables[$prefix . 'count'] = is_array($missing) ? count($missing) : 0;
            $variables[$prefix . 'csv']   = is_array($missing) ? implode(', ', $missing) : '';
        }

        // 3) Export variables chính (để node sau dùng dễ)
        $variables['task_intent'] = $tasklist['intent'] ?? '';
        $variables['task_entity'] = $tasklist['entity'] ?? '';
        $variables['task_tone']   = $tasklist['tone'] ?? '';
        $variables['task_missing_csv']   = $variables[$prefix . 'csv'] ?? '';
        
        // =========================================================
        // HIL CHECK: nếu đang human-in-loop thì lấy transient để biết đã fill đủ chưa
        // task_fill: 1 = đủ info => đi nhánh ELSE (compose) hoặc nhánh DONE
        // task_fill: 0 = chưa đủ => đi nhánh THEN (ask missing) hoặc nhánh NEED_MORE
        // =========================================================
        $variables['task_fill'] = 0;
        $variables['task_missing_left_count'] = 0;

        $hilCheck = ($this->getParam('hil_check') !== 'no');
        if ($hilCheck) {
            $blog_id = function_exists('get_current_blog_id') ? (int)get_current_blog_id() : 0;

            // chuẩn hóa chat_id (ưu tiên node setting, fallback twf_chat_id)
            $cid = (string)$chat_id;
            if ($cid === '' && !empty($variables['twf_chat_id'])) $cid = (string)$variables['twf_chat_id'];

            if ($blog_id > 0 && $cid !== '') {
                $tpl = (string)$this->replaceVariables($this->getParam('hil_key_template'), $variables);
                if ($tpl === '') $tpl = 'waic_hil_{blog_id}_{chat_id}';

                // Ensure helper exists
                if (!function_exists('waic_hil_get_fill_info')) {
                    $helper = WP_CONTENT_DIR . '/mu-plugins/bizcity-admin-hook/includes/flows/tasklist.php';
                    if (file_exists($helper)) require_once $helper;
                }

                if (function_exists('waic_hil_get_fill_info')) {
                    $info = waic_hil_get_fill_info($tpl, $blog_id, $cid, $prefix);
                    $variables['task_fill'] = (int)($info['task_fill'] ?? 0);
                    $variables['task_missing_left_count'] = (int)($info['task_missing_left_count'] ?? 0);
                    $variables['task_missing_count'] = (int)($variables['task_fill']);
                    $variables[$prefix . 'left_csv'] = (string)($info['left_csv'] ?? '');
                }
            }
        }

        $variables['task_missing_json']  = wp_json_encode($missing, JSON_UNESCAPED_UNICODE);
        $variables['task_raw'] = $tasklist['_raw'] ?? '';

        // 4) START HIL nếu chưa có state
        $chat_id = 'zalo_'.bizgpt_get_client_id_from_transient(get_current_blog_id());
        $hil_key = waic_hil_key($blog_id, $chat_id);

        // Start HIL (tự hỏi câu đầu tiên qua waic_hil_start)
        // Lưu ý: waic_hil_start() mặc định set transient TTL 30p trong helper.
        // => để áp TTL theo settings, ta set lại transient sau khi start.
        $continue = array(
            // Optional: anh có thể nhét các info để chạy tiếp workflow ở hook waic_hil_completed
            // 'workflow_id' => $variables['workflow_id'] ?? '',
            // 'task_id' => (string)$taskId,
            // 'next_node_id' => '',
            // 'origin_vars' => $variables,
        );

        // Nếu auto_send = no: vẫn start HIL state nhưng không gửi câu hỏi
        // => ta start trước rồi nếu no thì không gọi ask_next
        $auto_send = 'yes'; // luôn auto send trong gap analyze
        if ($auto_send) {
            $state = waic_hil_start($tasklist, $chat_id, $blog_id, $continue);
        } else {
            // manual start: copy logic start nhưng không ask_next
            $state = array(
                'blog_id' => $blog_id,
                'chat_id' => $chat_id,
                'created_at' => time(),
                'step' => 0,
                'intent' => (string)($tasklist['intent'] ?? ''),
                'entity' => (string)($tasklist['entity'] ?? ''),
                'tone'   => (string)($tasklist['tone'] ?? ''),
                'missing_all' => $missing,
                'missing_left'=> $missing,
                'answers' => array(),
                'continue' => $continue,
            );
            set_transient($hil_key, $state, 60 * $ttl_minutes);
        }
        back_trace('NOTICE', 'HIL started with key ' . $hil_key . ' . ' . print_r($state, true));

        // Apply TTL theo settings (ghi đè TTL)
        if (is_array($state)) {
            set_transient($hil_key, $state, 60 * $ttl_minutes);
        }

        // Intro message (optional) - gửi trước khi hỏi missing đầu
        
        if ($auto_send && $intro) {
            if (function_exists('twf_telegram_send_message')) {
                twf_telegram_send_message($chat_id, $intro);
            } else if (function_exists('send_zalo_botbanhang') && strpos($chat_id, 'zalo_') === 0) {
                send_zalo_botbanhang($intro, substr($chat_id, 5));
            }
            // Sau intro, hỏi lại câu đầu (vì waic_hil_start đã hỏi 1 câu rồi).
            // => Để tránh hỏi 2 lần: chỉ gửi intro nếu anh bật nhưng không muốn auto-ask ở start.
            // Nếu anh muốn intro + 1 câu hỏi: khuyến nghị để intro_text trống, hoặc chuyển intro vào template hỏi.
        }
        if($task_fill===1) $status = 3;
        else $status = 2;
       
        $this->_results = array(
            'result' => array(
                'task_intent' => $variables['task_intent'],
                'task_entity' => $variables['task_entity'],
                'task_tone'   => $variables['task_tone'],
                'task_missing_csv' => $variables['task_missing_csv'],
                'task_missing_count' => $variables['task_missing_count'],
                'task_missing_json' => $variables['task_missing_json'],
                'task_raw' => $variables['task_raw'],

                // expose thêm cho debug
                'prefix' => $prefix,
                $prefix . 'count' => (int)($variables[$prefix . 'count'] ?? 0),
                $prefix . 'csv'   => (string)($variables[$prefix . 'csv'] ?? ''),
                'task_fill' => (int)($variables['task_fill'] ?? 0),
                'task_missing_left_count' => (int)($variables['task_missing_left_count'] ?? 0),
                $prefix . 'left_csv' => (string)($variables[$prefix . 'left_csv'] ?? ''),
            ),
            'error' => $error,
            'status' => $status,
        );
        
        back_trace('NOTICE', 'AI Gap Analyze results: ' . print_r($this->_results, true));

        return $this->_results;
    }
}
