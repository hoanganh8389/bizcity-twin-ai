<?php
/**
 * BizCoach Map – AI Natal Chart Report (Extended)
 *
 * Phiên bản mở rộng từ bccm_natal_pdf:
 * - Trang load NGAY LẬP TỨC (chart data + tables)
 * - AI luận giải load TỪNG CHƯƠNG qua JS (không timeout)
 * - View HTML + In PDF đều OK
 *
 * 2 AJAX actions:
 *   - bccm_natal_report_full  → Full HTML page (instant)
 *   - bccm_llm_section        → Generate 1 chapter (JSON)
 *
 * @package BizCoach_Map
 * @since   0.1.0.16
 */
if (!defined('ABSPATH')) exit;

/* =====================================================================
 * OPENAI API CALLER
 * =====================================================================*/

function bccm_llm_call_openai($system, $user, $opts = []) {
    if ( ! function_exists( 'bizcity_openrouter_chat' ) ) {
        return new WP_Error( 'no_openrouter', 'bizcity_openrouter_chat() chưa sẵn sàng.' );
    }

    $max_tokens  = $opts['max_tokens']  ?? 8000;
    $temperature = $opts['temperature'] ?? 0.75;
    $timeout     = $opts['timeout']     ?? 120;

    $messages = [
        [ 'role' => 'system', 'content' => $system ],
        [ 'role' => 'user',   'content' => $user ],
    ];

    $result = bizcity_openrouter_chat( $messages, [
        'purpose'     => 'astro_report',
        'max_tokens'  => $max_tokens,
        'temperature' => $temperature,
        'timeout'     => $timeout,
    ] );

    if ( empty( $result['success'] ) ) {
        $err = $result['error'] ?? 'Unknown error';
        error_log( '[BCCM LLM] OpenRouter error: ' . $err );
        return new WP_Error( 'openrouter_error', $err );
    }

    return trim( $result['message'] );
}

/* =====================================================================
 * MARKDOWN → HTML
 * =====================================================================*/

function bccm_llm_md_to_html($md) {
    $html = $md;
    $html = preg_replace('/^---+$/m', '<hr class="ai-divider">', $html);
    $html = preg_replace('/^#### (.+)$/m', '<h6 class="ai-h6">$1</h6>', $html);
    $html = preg_replace('/^### (.+)$/m', '<h5 class="ai-h5">$1</h5>', $html);
    $html = preg_replace('/^## (.+)$/m',  '<h4 class="ai-h4">$1</h4>', $html);
    $html = preg_replace('/\*\*\*(.+?)\*\*\*/', '<strong><em>$1</em></strong>', $html);
    $html = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html);
    $html = preg_replace('/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/', '<em>$1</em>', $html);
    $html = preg_replace('/^> (.+)$/m', '<blockquote>$1</blockquote>', $html);
    // Unordered list
    $html = preg_replace_callback('/(?:^- .+\n?)+/m', function ($m) {
        $items = preg_replace('/^- (.+)$/m', '<li>$1</li>', trim($m[0]));
        return "<ul class=\"ai-list\">\n$items\n</ul>\n";
    }, $html);
    // Ordered list
    $html = preg_replace_callback('/(?:^\d+\. .+\n?)+/m', function ($m) {
        $items = preg_replace('/^\d+\. (.+)$/m', '<li>$1</li>', trim($m[0]));
        return "<ol class=\"ai-list\">\n$items\n</ol>\n";
    }, $html);
    // Wrap plain paragraphs
    $parts = preg_split('/\n{2,}/', $html);
    $out = '';
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p === '') continue;
        if (preg_match('/^<(h[2-6]|ul|ol|blockquote|hr|div|table|figure)/', $p)) {
            $out .= $p . "\n";
        } else {
            $out .= '<p>' . nl2br($p) . "</p>\n";
        }
    }
    return $out;
}

/* =====================================================================
 * CHART DATA → TEXT CONTEXT FOR LLM
 * =====================================================================*/

function bccm_llm_build_chart_context($astro_row, $coachee) {
    $summary   = json_decode($astro_row['summary']  ?? '{}', true) ?: [];
    $traits    = json_decode($astro_row['traits']    ?? '{}', true) ?: [];
    $positions = $traits['positions'] ?? [];
    $houses    = $traits['houses']    ?? [];
    $aspects   = $traits['aspects']   ?? [];
    $birth     = $traits['birth_data'] ?? [];
    $signs     = bccm_zodiac_signs();
    $planet_vi = bccm_planet_names_vi();
    $aspect_vi = bccm_aspect_names_vi();
    $house_meanings = bccm_house_meanings_vi();

    $name = $coachee['full_name'] ?? 'Người dùng';
    $dob  = '';
    if (!empty($birth['day']) && !empty($birth['month']) && !empty($birth['year'])) {
        $dob = sprintf('%02d/%02d/%04d', $birth['day'], $birth['month'], $birth['year']);
    } elseif (!empty($coachee['dob'])) {
        $dob = date('d/m/Y', strtotime($coachee['dob']));
    }

    $ctx  = "=== THÔNG TIN CÁ NHÂN ===\n";
    $ctx .= "Họ tên: $name\nNgày sinh: $dob\nGiờ sinh: " . ($astro_row['birth_time'] ?? '') . "\nNơi sinh: " . ($astro_row['birth_place'] ?? '') . "\n\n";

    $planet_order = ['Sun','Moon','Ascendant','Mercury','Venus','Mars','Jupiter','Saturn','Uranus','Neptune','Pluto','Chiron','Lilith','True Node','Mean Node'];
    $planet_syms  = ['Sun'=>'☉','Moon'=>'☽','Ascendant'=>'ASC','Mercury'=>'☿','Venus'=>'♀','Mars'=>'♂','Jupiter'=>'♃','Saturn'=>'♄','Uranus'=>'♅','Neptune'=>'♆','Pluto'=>'♇','Chiron'=>'⚷','Lilith'=>'⚸','True Node'=>'☊','Mean Node'=>'☊'];

    $houses_raw = [];
    if (!empty($houses)) {
        if (isset($houses[0]['House']) || isset($houses[0]['house'])) $houses_raw = $houses;
        elseif (isset($houses['Houses'])) $houses_raw = $houses['Houses'];
    }

    $ctx .= "=== VỊ TRÍ CÁC HÀNH TINH ===\n";
    foreach ($planet_order as $pn) {
        if (!isset($positions[$pn])) continue;
        $p = $positions[$pn];
        $vi = $p['planet_vi'] ?? ($planet_vi[$pn] ?? $pn);
        $dms = bccm_astro_decimal_to_dms($p['norm_degree'] ?? 0);
        $retro = ($p['is_retro'] ?? false) ? ' [NGHỊCH HÀNH ℞]' : '';
        $house = '';
        if (!empty($houses_raw) && !in_array($pn, ['Ascendant','Descendant','MC','IC'])) {
            $h = bccm_astro_planet_in_house($p['full_degree'] ?? 0, $houses_raw);
            if ($h > 0) $house = " — Nhà $h";
        }
        $ctx .= "- {$planet_syms[$pn]} $vi: {$p['sign_symbol']} {$p['sign_vi']} ($dms)$house$retro\n";
    }
    $ctx .= "\n";

    if (!empty($houses_raw)) {
        $ctx .= "=== 12 CUNG NHÀ (Placidus) ===\n";
        foreach ($houses_raw as $h) {
            $num = $h['House'] ?? ($h['house'] ?? 0);
            if ($num < 1) continue;
            $sn = $h['zodiac_sign']['number'] ?? 0;
            $ctx .= "- Nhà $num: " . ($signs[$sn]['symbol'] ?? '') . ' ' . ($signs[$sn]['vi'] ?? '') . " (" . bccm_astro_decimal_to_dms($h['normDegree'] ?? ($h['degree'] ?? 0)) . ") — " . ($house_meanings[$num] ?? '') . "\n";
        }
        $ctx .= "\n";
    }

    $enriched = bccm_astro_enrich_aspects($aspects, $positions);
    if (!empty($enriched)) {
        $ctx .= "=== GÓC CHIẾU (ASPECTS) ===\n";
        foreach ($enriched as $asp) {
            $ctx .= "- " . ($planet_vi[$asp['planet_1_en']] ?? $asp['planet_1_en']) . ' ' . ($aspect_vi[$asp['aspect_en']] ?? $asp['aspect_en']) . ' ' . ($planet_vi[$asp['planet_2_en']] ?? $asp['planet_2_en']) . " (orb: " . ($asp['orb'] !== null ? number_format($asp['orb'], 2) . '°' : '') . ")\n";
        }
        $ctx .= "\n";
    }

    $patterns = bccm_detect_chart_patterns($positions, $aspects);
    if (!empty($patterns)) {
        $ctx .= "=== MÔ HÌNH BẢN ĐỒ ===\n";
        foreach ($patterns as $pt) {
            $pl = implode(', ', array_map(fn($n) => $planet_vi[$n] ?? $n, $pt['planets']));
            $ctx .= "- {$pt['type_vi']}: $pl — {$pt['description']}\n";
        }
        $ctx .= "\n";
    }

    $specials = bccm_analyze_special_features($positions, $aspects, $houses_raw, $birth);
    if (!empty($specials)) {
        $ctx .= "=== ĐẶC ĐIỂM NỔI BẬT ===\n";
        foreach ($specials as $f) $ctx .= "- {$f['icon']} {$f['text']}\n";
        $ctx .= "\n";
    }

    $elements  = ['Lửa' => 0, 'Đất' => 0, 'Khí' => 0, 'Nước' => 0];
    $modalities = ['Cardinal' => 0, 'Fixed' => 0, 'Mutable' => 0];
    foreach (['Sun','Moon','Mercury','Venus','Mars','Jupiter','Saturn','Uranus','Neptune','Pluto'] as $pn) {
        if (!isset($positions[$pn])) continue;
        $sn = $positions[$pn]['sign_number'] ?? 0;
        if (isset($signs[$sn])) {
            if (isset($elements[$signs[$sn]['element'] ?? ''])) $elements[$signs[$sn]['element']]++;
            if (isset($modalities[$signs[$sn]['modality'] ?? ''])) $modalities[$signs[$sn]['modality']]++;
        }
    }
    $ctx .= "=== CÂN BẰNG NGUYÊN TỐ & DẠNG THỨC ===\n";
    foreach ($elements as $el => $c)  $ctx .= "- $el: $c hành tinh\n";
    foreach ($modalities as $mo => $c) $ctx .= "- $mo: $c hành tinh\n";

    return $ctx;
}

/* =====================================================================
 * SYSTEM PROMPT
 * =====================================================================*/

function bccm_llm_system_prompt() {
    return <<<'PROMPT'
Bạn là một nhà chiêm tinh phương Tây (Western Astrology) chuyên nghiệp với hơn 20 năm kinh nghiệm, chuyên viết báo cáo luận giải bản đồ sao cá nhân (Natal Chart Report) bằng tiếng Việt.

PHONG CÁCH VIẾT:
- Chuyên nghiệp, sâu sắc, giàu cảm xúc nhưng mang tính khoa học chiêm tinh
- Giải thích dễ hiểu cho người không chuyên, tránh jargon phức tạp
- Kết nối ý nghĩa chiêm tinh với thực tế cuộc sống hàng ngày
- Đưa lời khuyên cụ thể, hữu ích và có thể hành động
- Sử dụng ví dụ minh họa sinh động, so sánh ẩn dụ đẹp
- Giọng văn ấm áp, đồng cảm, truyền cảm hứng nhưng không mê tín

QUY TẮC VIẾT:
- Viết hoàn toàn bằng tiếng Việt (trừ thuật ngữ chiêm tinh giữ nguyên tiếng Anh trong ngoặc)
- Sử dụng Markdown: ## cho tiêu đề chính, ### cho tiêu đề phụ, #### cho tiêu đề nhỏ, **bold**, *italic*, - cho danh sách
- Viết CHI TIẾT, ĐẦY ĐỦ — KHÔNG tóm tắt, KHÔNG rút gọn
- Mỗi phần phải phân tích sâu với nhiều góc nhìn
- Luôn cá nhân hóa phân tích dựa trên dữ liệu bản đồ sao thực tế
- Kết hợp phân tích hành tinh trong cung VÀ trong nhà VÀ các góc chiếu liên quan
PROMPT;
}

/* =====================================================================
 * 10 CHAPTER DEFINITIONS
 * =====================================================================*/

function bccm_llm_get_sections($chart_ctx, $name) {
    return [
        ['title'=>'Giới Thiệu & Tổng Quan Bản Đồ Sao','icon'=>'📖','prompt'=>"Dựa trên bản đồ sao của **$name**, hãy viết CHƯƠNG MỞ ĐẦU:\n\n## 📖 Giới Thiệu Bản Đồ Sao Cá Nhân\n\n### 1. Bản Đồ Sao Là Gì?\n- Giải thích natal chart, ý nghĩa đọc bản đồ sao\n\n### 2. Tổng Quan Bản Đồ Sao Của $name\n- Big 3 (Sun, Moon, ASC), năng lượng chủ đạo, phân bố nguyên tố, mô hình đặc biệt\n\n### 3. Cách Đọc Báo Cáo\n\nViết tối thiểu 2000 từ.\n\nDỮ LIỆU:\n$chart_ctx"],
        ['title'=>'Mặt Trời — Bản Ngã & Mục Đích Sống','icon'=>'☀️','prompt'=>"Phân tích MẶT TRỜI của **$name**:\n\n## ☀️ Mặt Trời\n\n### 1. Mặt Trời trong Cung — bản ngã cốt lõi, điểm mạnh/yếu\n### 2. Mặt Trời trong Nhà — lĩnh vực tỏa sáng\n### 3. Góc chiếu của Mặt Trời — ảnh hưởng tích cực/thách thức\n### 4. Ứng dụng: sự nghiệp, lãnh đạo, sức khỏe, lời khuyên\n\nViết tối thiểu 2500 từ.\n\nDỮ LIỆU:\n$chart_ctx"],
        ['title'=>'Mặt Trăng & Cung Mọc — Cảm Xúc & Nội Tâm','icon'=>'🌙','prompt'=>"Phân tích MẶT TRĂNG và CUNG MỌC của **$name**:\n\n## 🌙 Mặt Trăng — Cảm Xúc & Nội Tâm\n\n### 1. Moon trong Cung — bản chất cảm xúc, ký ức tuổi thơ\n### 2. Moon trong Nhà + Góc chiếu\n### 3. Sun vs Moon — mâu thuẫn hay hài hòa?\n\n## ⬆️ Cung Mọc (Ascendant)\n### 4. Ấn tượng đầu tiên, phong cách, ngoại hình\n### 5. ASC vs Moon — con người ngoài vs trong\n### 6. Ứng dụng: tình cảm, tâm lý, môi trường sống\n\nViết tối thiểu 2500 từ.\n\nDỮ LIỆU:\n$chart_ctx"],
        ['title'=>'Sao Thủy & Sao Kim — Tư Duy và Tình Yêu','icon'=>'💬💕','prompt'=>"Phân tích SAO THỦY và SAO KIM của **$name**:\n\n## 💬 Sao Thủy (Mercury)\n### 1. Cách suy nghĩ, giao tiếp, học hỏi\n### 2. Mercury trong Nhà + Góc chiếu\n### 3. Lời khuyên phát triển trí tuệ\n\n## 💕 Sao Kim (Venus)\n### 4. Phong cách yêu, giá trị, thẩm mỹ\n### 5. Venus trong Nhà + Góc chiếu\n### 6. Kiểu bạn đời lý tưởng, lời khuyên tình cảm\n\nViết tối thiểu 2500 từ.\n\nDỮ LIỆU:\n$chart_ctx"],
        ['title'=>'Sao Hỏa — Năng Lượng & Hành Động','icon'=>'🔥','prompt'=>"Phân tích SAO HỎA của **$name**:\n\n## 🔥 Sao Hỏa (Mars)\n### 1. Mars trong Cung — động lực, đam mê, cạnh tranh\n### 2. Mars trong Nhà + Góc chiếu\n### 3. Ứng dụng: sự nghiệp, thể thao, sức khỏe, quản lý năng lượng\n\nViết tối thiểu 2000 từ.\n\nDỮ LIỆU:\n$chart_ctx"],
        ['title'=>'Sao Mộc & Sao Thổ — Phát Triển và Kỷ Luật','icon'=>'⚖️','prompt'=>"Phân tích SAO MỘC và SAO THỔ của **$name**:\n\n## ♃ Sao Mộc — May Mắn & Phát Triển\n### 1. Jupiter trong Cung + Nhà + Góc chiếu\n### 2. Con đường thịnh vượng\n\n## ♄ Sao Thổ — Kỷ Luật & Bài Học Karma\n### 3. Saturn trong Cung + Nhà + Góc chiếu\n### 4. Saturn Return\n### 5. Jupiter vs Saturn — cân bằng\n### 6. Lời khuyên tài chính, sự nghiệp dài hạn\n\nViết tối thiểu 2500 từ.\n\nDỮ LIỆU:\n$chart_ctx"],
        ['title'=>'Hành Tinh Ngoại — Chuyển Hóa Tâm Linh','icon'=>'🌌','prompt'=>"Phân tích CÁC HÀNH TINH NGOẠI của **$name**:\n\n## 🌌 Hành Tinh Ngoại\n\n### ♅ Uranus — Đổi Mới & Tự Do\n### ♆ Neptune — Mơ Mộng & Tâm Linh\n### ♇ Pluto — Quyền Lực & Tái Sinh\n### ⚷ Chiron — Vết Thương & Chữa Lành (nếu có)\n### ☊ Nút Trăng — Sứ Mệnh (nếu có)\n### Tổng hợp: con đường tâm linh\n\nViết tối thiểu 2000 từ.\n\nDỮ LIỆU:\n$chart_ctx"],
        ['title'=>'12 Cung Nhà — Các Lĩnh Vực Cuộc Sống','icon'=>'🏛️','prompt'=>"Phân tích 12 CUNG NHÀ của **$name**:\n\n## 🏛️ 12 Cung Nhà\n\nMỗi nhà: cung cai quản, hành tinh trong nhà, ý nghĩa thực tế, lời khuyên.\n\n### Nhà 1–12\n(Nhà 1: Bản Thân, 2: Tài Chính, 3: Giao Tiếp, 4: Gia Đình, 5: Sáng Tạo, 6: Sức Khỏe, 7: Đối Tác, 8: Chuyển Hóa, 9: Triết Lý, 10: Sự Nghiệp, 11: Bạn Bè, 12: Tâm Linh)\n\nMỗi nhà ít nhất 200 từ. Tổng tối thiểu 2500 từ.\n\nDỮ LIỆU:\n$chart_ctx"],
        ['title'=>'Góc Chiếu — Năng Lượng Tương Tác','icon'=>'🔗','prompt'=>"Phân tích GÓC CHIẾU của **$name**:\n\n## 🔗 Góc Chiếu\n\n### 1. Giải thích về Aspects\n### 2. Phân tích từng góc chiếu quan trọng (ý nghĩa + ảnh hưởng cụ thể)\n### 3. Top 5 aspects quan trọng nhất\n### 4. Lời khuyên sống hài hòa\n\nPhân tích CỤ THỂ từng aspect. Viết tối thiểu 2500 từ.\n\nDỮ LIỆU:\n$chart_ctx"],
        ['title'=>'Tổng Kết & Lời Khuyên Cuộc Sống','icon'=>'🌟','prompt'=>"Viết CHƯƠNG KẾT THÚC cho **$name**:\n\n## 🔷 Mô Hình & Cân Bằng\n### 1. Chart Patterns (Grand Trine, T-Square, Yod, Stellium...)\n### 2. Cân bằng Nguyên tố (Lửa/Đất/Khí/Nước)\n### 3. Cân bằng Dạng thức (Cardinal/Fixed/Mutable)\n\n## 🌟 Tổng Kết\n### 4. 5-7 điểm nổi bật, sứ mệnh cuộc đời\n### 5. Lời khuyên SỰ NGHIỆP & TÀI CHÍNH\n### 6. Lời khuyên TÌNH YÊU & GIA ĐÌNH\n### 7. Lời khuyên SỨC KHỎE\n### 8. Lời khuyên PHÁT TRIỂN BẢN THÂN & TÂM LINH\n### 9. Thông điệp cuối cùng\n\nViết tối thiểu 3000 từ.\n\nDỮ LIỆU:\n$chart_ctx"],
    ];
}

/* =====================================================================
 * AJAX 1: FULL REPORT PAGE (instant HTML + JS lazy-load AI)
 * action = bccm_natal_report_full
 * =====================================================================*/
add_action('wp_ajax_bccm_natal_report_full', 'bccm_natal_report_full_handler');

function bccm_natal_report_full_handler() {
    if (!current_user_can('edit_posts')) wp_die('Unauthorized');
    check_ajax_referer('bccm_natal_report_full', '_wpnonce');

    $coachee_id = intval($_GET['coachee_id'] ?? 0);
    if (!$coachee_id) wp_die('Missing coachee_id');

    $chart_type = sanitize_text_field($_GET['chart_type'] ?? 'western');

    global $wpdb;
    $t = bccm_tables();
    $coachee = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['profiles']} WHERE id=%d", $coachee_id), ARRAY_A);
    if (!$coachee) wp_die('Coachee not found');

    // Query astro by user_id first, fallback to coachee_id
    $user_id = $coachee['user_id'] ?? 0;
    $astro_row = null;
    if ($user_id) {
        $astro_row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bccm_astro WHERE user_id=%d AND chart_type=%s AND (summary IS NOT NULL OR traits IS NOT NULL)", $user_id, $chart_type
        ), ARRAY_A);
    }
    if (!$astro_row) {
        $astro_row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bccm_astro WHERE coachee_id=%d AND chart_type=%s", $coachee_id, $chart_type
        ), ARRAY_A);
    }
    if (!$astro_row) wp_die('No astro data for chart type: ' . esc_html($chart_type) . '. Hãy tạo bản đồ sao trước.');

    /* ── Parse astro data ── */
    $summary    = json_decode($astro_row['summary'] ?? '{}', true) ?: [];
    $traits     = json_decode($astro_row['traits'] ?? '{}', true) ?: [];
    $positions  = $traits['positions'] ?? [];
    $houses_data= $traits['houses'] ?? [];
    $aspects    = $traits['aspects'] ?? [];
    $birth_data = $traits['birth_data'] ?? [];
    $signs      = bccm_zodiac_signs();
    $planet_vi  = bccm_planet_names_vi();
    $aspect_vi  = bccm_aspect_names_vi();
    $aspect_symbols_map = bccm_aspect_symbols();
    $aspect_colors_map  = bccm_aspect_colors();
    $house_meanings     = bccm_house_meanings_vi();

    $houses_raw = [];
    if (!empty($houses_data)) {
        if (isset($houses_data[0]['House']) || isset($houses_data[0]['house'])) $houses_raw = $houses_data;
        elseif (isset($houses_data['Houses'])) $houses_raw = $houses_data['Houses'];
    }

    $enriched = bccm_astro_enrich_aspects($aspects, $positions);
    $grouped  = bccm_astro_group_aspects_by_planet($enriched);

    $find_sign = function($name) use ($signs) {
        foreach ($signs as $s) { if (strtolower($s['en'] ?? '') === strtolower($name)) return $s; }
        return ['vi' => $name, 'symbol' => '?', 'en' => $name, 'element' => ''];
    };

    $planet_symbols = [
        'Sun'=>'☉','Moon'=>'☽','Mercury'=>'☿','Venus'=>'♀','Mars'=>'♂',
        'Jupiter'=>'♃','Saturn'=>'♄','Uranus'=>'♅','Neptune'=>'♆','Pluto'=>'♇',
        'Chiron'=>'⚷','Lilith'=>'⚸','True Node'=>'☊','Mean Node'=>'☊',
        'Ascendant'=>'ASC','Descendant'=>'DSC','MC'=>'MC','IC'=>'IC',
        'Ceres'=>'⚳','Vesta'=>'⚶','Juno'=>'⚵','Pallas'=>'⚴',
    ];
    $planet_order = ['Sun','Moon','Mercury','Venus','Mars','Jupiter','Saturn','Uranus','Neptune','Pluto',
        'Chiron','Lilith','True Node','Mean Node','Ascendant','Descendant','MC','IC','Ceres','Vesta','Juno','Pallas'];

    $chart_url       = $astro_row['chart_svg'] ?? $summary['chart_url'] ?? '';

    $dob_display = '';
    if (!empty($birth_data['day']) && !empty($birth_data['month']) && !empty($birth_data['year'])) {
        $dob_display = sprintf('%02d/%02d/%04d', $birth_data['day'], $birth_data['month'], $birth_data['year']);
    } elseif (!empty($coachee['dob'])) {
        $dob_display = date('d/m/Y', strtotime($coachee['dob']));
    }

    $name_esc      = esc_html($coachee['full_name'] ?? 'Natal Chart');
    $chart_type_esc = esc_attr($chart_type);
    $sections      = bccm_llm_get_sections('', $coachee['full_name'] ?? 'Người dùng');
    $section_nonce = wp_create_nonce('bccm_llm_section');
    $regenerate    = !empty($_GET['regenerate']) ? 'true' : 'false';
    
    // ── Load existing LLM report from DB ──
    $existing_report = [];
    $existing_sections = [];
    if (!empty($astro_row['llm_report'])) {
        $existing_report = json_decode($astro_row['llm_report'], true);
        if (is_array($existing_report) && isset($existing_report['sections'])) {
            $existing_sections = $existing_report['sections'];
        }
    }

    while (ob_get_level() > 0) @ob_end_clean();
    header('Content-Type: text/html; charset=UTF-8');

    // ═══════════════════════════════════════════════════════════
    // BEGIN HTML OUTPUT
    // ═══════════════════════════════════════════════════════════
    ?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<title>Báo Cáo Chiêm Tinh — <?php echo $name_esc; ?></title>
<style>
@page { size: A4; margin: 15mm 12mm; }
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: 'Segoe UI', Tahoma, Arial, sans-serif; font-size: 11px; color: #1a1a2e; line-height: 1.55; background: #f8fafc; }
.page { max-width: 210mm; margin: 0 auto; background: #fff; }

/* Toolbar */
.toolbar { text-align: center; padding: 12px; background: linear-gradient(135deg,#1a1a2e,#2d1b69); border-bottom: 2px solid #6366f1; }
.toolbar button,.toolbar a { padding: 10px 24px; background: #6366f1; color: #fff; border: none; border-radius: 8px; font-size: 13px; cursor: pointer; font-weight: 600; text-decoration: none; display: inline-block; margin: 0 4px; }
.toolbar button:hover,.toolbar a:hover { background: #4f46e5; }
.toolbar .btn-regen { background: #f59e0b; }
.toolbar .btn-regen:hover { background: #d97706; }
.toolbar .hint { color: #94a3b8; font-size: 11px; margin-top: 6px; }

/* Cover */
.cover { background: linear-gradient(135deg,#0f0c29,#302b63,#24243e); color:#fff; padding: 50px 36px; text-align: center; page-break-after: always; }
.cover h1 { font-size: 32px; font-weight: 800; color: #fbbf24; margin-bottom: 6px; }
.cover .csub { font-size: 13px; color: #94a3b8; letter-spacing: 2px; text-transform: uppercase; margin-bottom: 30px; }
.cover .cname { font-size: 24px; font-weight: 700; color: #e0e7ff; }
.cover .cmeta { font-size: 13px; color: #818cf8; margin: 4px 0; }
.big3-cover { display: flex; gap: 12px; margin-top: 30px; justify-content: center; flex-wrap: wrap; }
.big3-cover .b3 { background: rgba(255,255,255,.08); border: 1px solid rgba(255,255,255,.12); border-radius: 14px; padding: 16px 20px; min-width: 120px; }
.big3-cover .b3 .lbl { font-size: 9px; color: #94a3b8; text-transform: uppercase; letter-spacing: 1.5px; }
.big3-cover .b3 .sym { font-size: 30px; margin: 4px 0; }
.big3-cover .b3 .sgn { font-size: 15px; font-weight: 700; color: #fbbf24; }
.big3-cover .b3 .sen { font-size: 10px; color: #818cf8; }
.cover .cbrand { margin-top: 30px; color: #64748b; font-size: 10px; border-top: 1px solid rgba(255,255,255,.1); padding-top: 12px; }

/* Data sections */
.data-section { padding: 10px 20px; }
.chart-img { text-align: center; margin: 14px 0; }
.chart-img img { max-width: 380px; border-radius: 10px; }
.section { margin-top: 14px; page-break-inside: avoid; }
.section h2 { font-size: 14px; font-weight: 700; color: #1e293b; border-bottom: 2px solid #e5e7eb; padding-bottom: 4px; margin-bottom: 8px; }
table { width: 100%; border-collapse: collapse; margin-bottom: 6px; font-size: 10.5px; }
th { background: #f1f5f9; color: #334155; font-weight: 600; text-align: left; padding: 5px 6px; border-bottom: 2px solid #e2e8f0; }
td { padding: 4px 6px; border-bottom: 1px solid #f1f5f9; }
tr:nth-child(even) td { background: #fafbfc; }
.mono { font-family: 'Courier New', monospace; font-size: 10px; }
.retro { color: #ef4444; font-weight: 700; }
.house-angular td { background: #f0f4ff !important; font-weight: 500; }
.aspect-group-header td { background: #f1f5f9 !important; font-weight: 700; padding-top: 8px; border-top: 2px solid #e2e8f0; }
.aspect-legend { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 10px; font-size: 9px; }
.aspect-legend span { display: inline-flex; align-items: center; gap: 2px; }
.orb-exact { color: #059669; font-weight: 700; }
.orb-close { color: #2563eb; }
.stats { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 8px; }
.stats span { display: inline-flex; align-items: center; gap: 2px; padding: 2px 8px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 99px; font-size: 9px; }
.pdf-patterns-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 10px; }
.pdf-pattern-card { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px 12px; }
.pdf-pattern-header { display: flex; align-items: center; gap: 8px; margin-bottom: 6px; }
.pdf-pattern-icon { font-size: 22px; }
.pdf-pattern-type { font-weight: 700; font-size: 12px; color: #1e293b; }
.pdf-pattern-planet { font-size: 10px; color: #475569; padding: 1px 6px; background: #eef2ff; border-radius: 4px; display: inline-block; margin: 1px 0; }
.pdf-pattern-desc { font-size: 9.5px; color: #64748b; border-top: 1px solid #e2e8f0; padding-top: 6px; margin-top: 4px; line-height: 1.4; }
.pdf-special-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top: 10px; }
.pdf-special-card { display: flex; align-items: flex-start; gap: 8px; padding: 8px 10px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; }
.pdf-special-icon { font-size: 20px; flex-shrink: 0; }
.pdf-special-main { margin: 0; font-weight: 600; font-size: 11px; color: #1e293b; }
.pdf-special-sub { margin: 2px 0 0; font-size: 10px; color: #64748b; }
.bccm-aspect-grid-wrap { overflow-x: auto; margin: 0 auto; }
.bccm-aspect-grid { border-collapse: collapse; margin: 0 auto; font-size: 12px; }
.bccm-aspect-grid th { width: 26px; height: 26px; text-align: center; font-size: 13px; font-weight: 600; color: #334155; background: #f8fafc; border: 1px solid #e2e8f0; padding: 2px; }
.bccm-aspect-grid td { width: 26px; height: 26px; text-align: center; font-size: 12px; font-weight: 700; border: 1px solid #e2e8f0; padding: 1px; }
.bccm-aspect-grid td.bccm-grid-empty { background: #f1f5f9; }
.bccm-aspect-grid td.bccm-grid-none::after { content: '·'; color: #d1d5db; }

/* AI Chapters */
.ai-chapter { padding: 20px; page-break-before: always; border-top: 3px solid #6366f1; margin-top: 20px; }
.ai-ch-header { display: flex; align-items: center; gap: 10px; margin-bottom: 14px; }
.ai-ch-header .ch-icon { font-size: 26px; }
.ai-ch-header .ch-num { font-size: 10px; color: #6366f1; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; }
.ai-ch-header .ch-title { font-size: 18px; font-weight: 700; color: #1e293b; }
.ai-content { font-size: 12px; line-height: 1.8; color: #334155; }
.ai-content h4.ai-h4 { font-size: 15px; font-weight: 700; color: #1e293b; margin: 20px 0 8px; padding-bottom: 4px; border-bottom: 2px solid #e2e8f0; }
.ai-content h5.ai-h5 { font-size: 13px; font-weight: 600; color: #4338ca; margin: 14px 0 6px; }
.ai-content h6.ai-h6 { font-size: 12px; font-weight: 600; color: #6366f1; margin: 10px 0 4px; }
.ai-content p { margin: 0 0 10px; text-align: justify; }
.ai-content ul.ai-list,.ai-content ol.ai-list { margin: 8px 0 12px 18px; }
.ai-content li { margin-bottom: 4px; }
.ai-content strong { color: #1e293b; }
.ai-content em { color: #6366f1; font-style: italic; }
.ai-content blockquote { margin: 12px 0; padding: 10px 16px; background: #f0f4ff; border-left: 4px solid #818cf8; border-radius: 0 8px 8px 0; color: #4338ca; font-style: italic; }
.ai-content hr.ai-divider { border: none; border-top: 2px dashed #e5e7eb; margin: 24px 0; }

/* Loading */
.ai-loading { text-align: center; padding: 30px; color: #6366f1; }
.ai-loading .spinner { display: inline-block; width: 28px; height: 28px; border: 3px solid #e2e8f0; border-top-color: #6366f1; border-radius: 50%; animation: spin .7s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
.ai-error { background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 12px 16px; margin: 10px 0; color: #991b1b; font-size: 11px; }

.footer { margin-top: 16px; text-align: center; color: #9ca3af; font-size: 9px; border-top: 1px solid #e5e7eb; padding-top: 8px; }
.progress-bar-wrap { margin: 10px 20px; background: #e5e7eb; border-radius: 6px; height: 8px; overflow: hidden; }
.progress-bar-fill { height: 100%; background: linear-gradient(90deg,#6366f1,#818cf8); width: 0%; transition: width .5s ease; border-radius: 6px; }
.progress-status { text-align: center; font-size: 12px; color: #6366f1; padding: 6px 0 0; font-weight: 600; }

@media print {
  body { background: #fff; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
  .toolbar,.no-print,.progress-bar-wrap,.progress-status { display: none !important; }
  .ai-loading { display: none !important; }
  .page { max-width: none; box-shadow: none; }
}
</style>
</head>
<body>

<!-- TOOLBAR -->
<div class="toolbar no-print">
  <button onclick="window.print()" id="btn-print" <?php echo (count($existing_sections) === count($sections) && empty($_GET['regenerate'])) ? '' : 'disabled'; ?>>🖨️ In / Lưu PDF (Ctrl+P)</button>
  <a href="<?php echo esc_url(admin_url('admin-ajax.php?action=bccm_natal_report_full&coachee_id=' . $coachee_id . '&chart_type=' . $chart_type_esc . '&regenerate=1&_wpnonce=' . wp_create_nonce('bccm_natal_report_full'))); ?>" class="btn-regen">🔄 Tạo lại AI</a>
  <a href="<?php echo esc_url(admin_url('admin-ajax.php?action=bccm_natal_pdf&coachee_id=' . $coachee_id . '&chart_type=' . $chart_type_esc . '&_wpnonce=' . wp_create_nonce('bccm_natal_pdf'))); ?>">📄 PDF Data Only</a>
  <div class="hint">
    <?php if (count($existing_sections) === count($sections) && empty($_GET['regenerate'])): ?>
      ✅ Báo cáo AI đã sẵn sàng. Bấm In để lưu PDF hoặc Tạo lại AI để generate mới.
    <?php else: ?>
      Trang data load ngay — AI luận giải load từng chương. Chờ hoàn tất rồi hẵng in PDF.
    <?php endif; ?>
  </div>
</div>

<!-- PROGRESS BAR -->
<div class="progress-bar-wrap no-print" id="ai-progress-wrap" <?php echo (count($existing_sections) === count($sections) && empty($_GET['regenerate'])) ? 'style="display:none"' : ''; ?>>
  <div class="progress-bar-fill" id="ai-progress-fill"></div>
</div>
<div class="progress-status no-print" id="ai-progress-text" <?php echo (count($existing_sections) === count($sections) && empty($_GET['regenerate'])) ? 'style="display:none"' : ''; ?>>
  <?php if (count($existing_sections) === count($sections) && empty($_GET['regenerate'])): ?>
    ✅ Báo cáo AI đã sẵn sàng!
  <?php else: ?>
    ⏳ Đang chuẩn bị luận giải AI...
  <?php endif; ?>
</div>

<div class="page">

<!-- ═══════════ COVER ═══════════ -->
<div class="cover">
  <div style="font-size:50px;margin-bottom:12px">🌟</div>
  <h1>Báo Cáo Chiêm Tinh Cá Nhân</h1>
  <div class="csub">Natal Chart Report — Luận Giải AI</div>
  <div class="cname"><?php echo $name_esc; ?></div>
  <div class="cmeta">📅 <?php echo esc_html($dob_display); ?></div>
  <div class="cmeta">🕐 <?php echo esc_html($astro_row['birth_time'] ?? ''); ?> — 📍 <?php echo esc_html($astro_row['birth_place'] ?? ''); ?></div>
  <?php
  $sun_i  = $find_sign($summary['sun_sign'] ?? '');
  $moon_i = $find_sign($summary['moon_sign'] ?? '');
  $asc_i  = $find_sign($summary['ascendant_sign'] ?? '');
  ?>
  <div class="big3-cover">
    <div class="b3"><div class="lbl">☀️ Mặt Trời</div><div class="sym"><?php echo esc_html($sun_i['symbol']); ?></div><div class="sgn"><?php echo esc_html($sun_i['vi']); ?></div><div class="sen"><?php echo esc_html($sun_i['en']); ?></div></div>
    <div class="b3"><div class="lbl">🌙 Mặt Trăng</div><div class="sym"><?php echo esc_html($moon_i['symbol']); ?></div><div class="sgn"><?php echo esc_html($moon_i['vi']); ?></div><div class="sen"><?php echo esc_html($moon_i['en']); ?></div></div>
    <div class="b3"><div class="lbl">⬆️ Cung Mọc</div><div class="sym"><?php echo esc_html($asc_i['symbol']); ?></div><div class="sgn"><?php echo esc_html($asc_i['vi']); ?></div><div class="sen"><?php echo esc_html($asc_i['en']); ?></div></div>
  </div>
  <div class="cbrand">BizCoach Map — AI Astrology Report | Free Astrology API &amp; GPT-4o | <?php echo $chart_type === 'vedic' ? 'Sidereal — Lahiri Ayanamsha (Vedic/Jyotish)' : 'Placidus — Tropical'; ?></div>
</div>

<!-- ═══════════ DATA SECTIONS (instant) ═══════════ -->
<div class="data-section">

  <?php if ($chart_url): ?>
  <div class="chart-img">
    <img src="<?php echo esc_url($chart_url); ?>" alt="Natal Wheel Chart"/>
    <div style="font-size:9px;color:#9ca3af;margin-top:4px"><?php echo $chart_type === 'vedic' ? 'Rashi Chart — Vedic / Sidereal (Free Astrology API)' : 'Natal Wheel Chart — Hệ thống Placidus (Free Astrology API)'; ?></div>
  </div>
  <?php endif; ?>

  <?php if ($chart_type === 'vedic'): ?>
  <!-- ═══ VEDIC DATA SECTIONS ═══ -->
  <?php
  $vedic_positions = $positions;
  $vedic_rashi = function_exists('bccm_vedic_rashi_signs') ? bccm_vedic_rashi_signs() : [];
  $vedic_planet_vi = function_exists('bccm_vedic_planet_names_vi') ? bccm_vedic_planet_names_vi() : [];
  $vedic_planet_order = ['Ascendant','Sun','Moon','Mars','Mercury','Jupiter','Venus','Saturn','Rahu','Ketu','Uranus','Neptune','Pluto'];
  $vedic_planet_syms  = ['Sun'=>'☉','Moon'=>'☽','Ascendant'=>'Lagna','Mars'=>'♂','Mercury'=>'☿','Jupiter'=>'♃','Venus'=>'♀','Saturn'=>'♄','Rahu'=>'☊','Ketu'=>'☋','Uranus'=>'♅','Neptune'=>'♆','Pluto'=>'♇'];
  ?>
  <?php if (!empty($vedic_positions)): ?>
  <div class="section">
    <h2>🕉️ Vị Trí Các Hành Tinh (Graha) — Vedic / Sidereal</h2>
    <table>
      <thead><tr><th>Hành tinh (Graha)</th><th>Rashi (Cung)</th><th>Vị trí</th><th>℞</th></tr></thead>
      <tbody>
      <?php foreach ($vedic_planet_order as $pname):
        if (!isset($vedic_positions[$pname])) continue;
        $p = $vedic_positions[$pname];
        $dms = bccm_astro_decimal_to_dms($p['norm_degree'] ?? 0);
        $sign_vi = $p['sign_vi'] ?? '';
        $sign_sym = $p['sign_symbol'] ?? '';
        $sanskrit = $p['sign_sanskrit'] ?? '';
        $lord = $p['sign_lord'] ?? '';
      ?>
      <tr>
        <td><?php echo ($vedic_planet_syms[$pname] ?? ''); ?> <strong><?php echo esc_html($vedic_planet_vi[$pname] ?? ($p['planet_vi'] ?? $pname)); ?></strong></td>
        <td><?php echo esc_html("$sign_sym $sign_vi ($sanskrit)"); ?><?php if ($lord): ?> <span style="color:#6b7280;font-size:9px">Chủ: <?php echo esc_html($lord); ?></span><?php endif; ?></td>
        <td class="mono"><?php echo esc_html($dms); ?></td>
        <td style="text-align:center"><?php echo (!empty($p['is_retro']) ? '<span class="retro">℞ Vakri</span>' : '—'); ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <?php
  // Show Navamsa chart URL if available
  $navamsa_url = $traits['navamsa_chart_url'] ?? ($summary['navamsa_chart_url'] ?? '');
  if ($navamsa_url): ?>
  <div class="chart-img">
    <img src="<?php echo esc_url($navamsa_url); ?>" alt="Navamsa D9 Chart"/>
    <div style="font-size:9px;color:#9ca3af;margin-top:4px">Navamsa Chart (D9) — Bản đồ Hôn nhân &amp; Dharma</div>
  </div>
  <?php endif; ?>

  <?php else: ?>
  <!-- ═══ WESTERN DATA SECTIONS ═══ -->

  <!-- AstroViet -->
  <?php
  $av_wheel = bccm_build_astroviet_wheel_url($positions, $houses_raw, $coachee['full_name'] ?? '', array_merge($birth_data, [
      'birth_place'=>$astro_row['birth_place'] ?? '',
      'latitude'=>$astro_row['latitude'] ?? ($birth_data['latitude'] ?? 0),
      'longitude'=>$astro_row['longitude'] ?? ($birth_data['longitude'] ?? 0),
  ]));
  $av_grid = bccm_build_astroviet_aspect_grid_url($positions, $houses_raw, $birth_data);
  $native_grid = bccm_render_aspect_grid_html($positions, $aspects);
  if ($av_wheel || $av_grid): ?>
  <div class="section" style="text-align:center">
    <h2>🗺️ Bản Đồ AstroViet</h2>
    <div style="display:flex;gap:12px;flex-wrap:wrap;justify-content:center">
    <?php if ($av_wheel): ?>
    <div style="text-align:center"><img src="<?php echo esc_url($av_wheel); ?>" alt="AstroViet" style="max-width:100%;border-radius:8px"/><div style="font-size:9px;color:#9ca3af;margin-top:4px">Natal Wheel — AstroViet</div></div>
    <?php endif; ?>
    <?php if ($av_grid): ?>
    <div style="text-align:center"><img src="<?php echo esc_url($av_grid); ?>" alt="AstroViet Grid" style="max-width:100%;border-radius:8px"/><div style="font-size:9px;color:#9ca3af;margin-top:4px">Aspect Grid — AstroViet</div></div>
    <?php endif; ?>
    </div>
    <?php if ($native_grid): ?>
    <div style="margin-top:14px"><h3 style="font-size:12px;margin-bottom:6px;color:#334155">📊 Lưới Góc Chiếu</h3><?php echo $native_grid; ?></div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- PLANETS TABLE -->
  <?php if (!empty($positions)): ?>
  <div class="section">
    <h2>🪐 Vị Trí Các Hành Tinh</h2>
    <table>
      <thead><tr><th>Hành tinh</th><th>Cung</th><th>Vị trí</th><th>Nhà</th><th>℞</th></tr></thead>
      <tbody>
      <?php foreach ($planet_order as $pname):
        if (!isset($positions[$pname])) continue;
        $p = $positions[$pname];
        $dms = bccm_astro_decimal_to_dms($p['norm_degree'] ?? 0);
        $hnum = '';
        if (!empty($houses_raw) && !in_array($pname, ['Ascendant','Descendant','MC','IC'])) {
          $hh = bccm_astro_planet_in_house($p['full_degree'] ?? 0, $houses_raw);
          $hnum = $hh > 0 ? $hh : '';
        }
      ?>
      <tr>
        <td><?php echo ($planet_symbols[$pname] ?? ''); ?> <strong><?php echo esc_html($p['planet_vi'] ?? $pname); ?></strong></td>
        <td><?php echo esc_html(($p['sign_symbol'] ?? '').' '.($p['sign_vi'] ?? '')); ?></td>
        <td class="mono"><?php echo esc_html($dms); ?></td>
        <td style="text-align:center;color:#6366f1;font-weight:600"><?php echo $hnum ?: '—'; ?></td>
        <td style="text-align:center"><?php echo (!empty($p['is_retro']) ? '<span class="retro">℞</span>' : '—'); ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- HOUSES TABLE -->
  <?php if (!empty($houses_raw)): ?>
  <div class="section">
    <h2>🏛️ 12 Cung Nhà (Placidus)</h2>
    <table>
      <thead><tr><th style="width:10%">Nhà</th><th style="width:20%">Cung</th><th style="width:25%">Đỉnh cung</th><th>Ý nghĩa</th></tr></thead>
      <tbody>
      <?php foreach ($houses_raw as $h):
        $num = $h['House'] ?? ($h['house'] ?? 0); if ($num < 1) continue;
        $sn = $h['zodiac_sign']['number'] ?? 0;
        $angular = in_array($num, [1,4,7,10]);
      ?>
      <tr<?php echo $angular ? ' class="house-angular"' : ''; ?>>
        <td style="text-align:center;font-weight:700;color:#6366f1"><?php echo intval($num); ?></td>
        <td><?php echo esc_html(($signs[$sn]['symbol'] ?? '').' '.($signs[$sn]['vi'] ?? '')); ?></td>
        <td class="mono"><?php echo esc_html(bccm_astro_decimal_to_dms($h['normDegree'] ?? ($h['degree'] ?? 0))); ?></td>
        <td style="color:#6b7280;font-size:10px"><?php echo esc_html($house_meanings[$num] ?? ''); ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- ASPECTS TABLE -->
  <?php if (!empty($enriched)): ?>
  <div class="section">
    <h2>🔗 Góc Chiếu (<?php echo count($enriched); ?> aspects)</h2>
    <div class="aspect-legend">
      <?php foreach ($aspect_vi as $aen => $avi):
        $c = $aspect_colors_map[$aen] ?? '#888';
        $s = $aspect_symbols_map[$aen] ?? '';
      ?>
      <span><span style="color:<?php echo $c; ?>;font-weight:700"><?php echo $s; ?></span> <?php echo esc_html($avi); ?></span>
      <?php endforeach; ?>
    </div>
    <table>
      <thead><tr><th>Hành tinh 1</th><th style="width:6%"></th><th>Góc chiếu</th><th>Hành tinh 2</th><th>Orb</th></tr></thead>
      <tbody>
      <?php foreach ($grouped as $pk => $pa):
        echo '<tr class="aspect-group-header"><td colspan="5">' . esc_html($planet_vi[$pk] ?? $pk) . ' (' . count($pa) . ')</td></tr>';
        foreach ($pa as $asp):
          $te = $asp['aspect_en'];
          $sym_a = $aspect_symbols_map[$te] ?? '';
          $col_a = $aspect_colors_map[$te] ?? '#888';
          $ov = $asp['orb'];
          $od = $ov !== null ? bccm_astro_decimal_to_dms($ov, true) : '—';
          $oc = '';
          if ($ov !== null && $ov < 1) $oc = 'orb-exact';
          elseif ($ov !== null && $ov < 3) $oc = 'orb-close';
      ?>
      <tr>
        <td style="padding-left:16px;color:#6b7280"><?php echo esc_html($planet_vi[$asp['planet_1_en']] ?? $asp['planet_1_en']); ?></td>
        <td style="text-align:center;color:<?php echo $col_a; ?>"><?php echo $sym_a; ?></td>
        <td style="color:<?php echo $col_a; ?>;font-weight:500"><?php echo esc_html($aspect_vi[$te] ?? $te); ?></td>
        <td><?php echo esc_html($planet_vi[$asp['planet_2_en']] ?? $asp['planet_2_en']); ?></td>
        <td class="mono <?php echo $oc; ?>"><?php echo esc_html($od); ?></td>
      </tr>
      <?php endforeach; endforeach; ?>
      </tbody>
    </table>
    <div class="stats">
      <?php
      $st = [];
      foreach ($enriched as $a) $st[$a['aspect_en']] = ($st[$a['aspect_en']] ?? 0) + 1;
      foreach ($st as $ts => $cs):
        $c = $aspect_colors_map[$ts] ?? '#888';
        $s = $aspect_symbols_map[$ts] ?? '';
      ?>
      <span><span style="color:<?php echo $c; ?>;font-weight:700"><?php echo $s; ?></span> <?php echo esc_html($aspect_vi[$ts] ?? $ts); ?> <strong><?php echo $cs; ?></strong></span>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- CHART PATTERNS -->
  <?php if (!empty($aspects) && !empty($positions)):
    $cpat = bccm_detect_chart_patterns($positions, $aspects);
    if (!empty($cpat)): ?>
  <div class="section">
    <h2>🔷 Mô Hình Bản Đồ</h2>
    <div class="pdf-patterns-grid">
    <?php foreach ($cpat as $pat):
      $plist = [];
      foreach ($pat['planets'] as $pn) {
          $plist[] = ($planet_vi[$pn] ?? $pn) . ' ' . floor($positions[$pn]['norm_degree'] ?? 0) . '° ' . ($positions[$pn]['sign_vi'] ?? '');
      }
    ?>
    <div class="pdf-pattern-card">
      <div class="pdf-pattern-header"><span class="pdf-pattern-icon"><?php echo $pat['icon'] ?? '🔷'; ?></span><span class="pdf-pattern-type"><?php echo esc_html($pat['type_vi']); ?></span></div>
      <div><?php foreach ($plist as $pl) echo '<div class="pdf-pattern-planet">' . esc_html($pl) . '</div>'; ?></div>
      <div class="pdf-pattern-desc"><?php echo esc_html($pat['description']); ?></div>
    </div>
    <?php endforeach; ?>
    </div>
  </div>
  <?php endif; endif; ?>

  <!-- SPECIAL FEATURES -->
  <?php if (!empty($positions)):
    $sf = bccm_analyze_special_features($positions, $aspects ?? [], $houses_raw, $birth_data);
    if (!empty($sf)): ?>
  <div class="section">
    <h2>✨ Đặc Điểm Nổi Bật</h2>
    <div class="pdf-special-grid">
    <?php foreach ($sf as $f): ?>
    <div class="pdf-special-card">
      <div class="pdf-special-icon"><?php echo $f['icon'] ?? '✨'; ?></div>
      <div>
        <p class="pdf-special-main"><?php echo esc_html($f['text']); ?></p>
        <?php if (!empty($f['text_vi']) && $f['text_vi'] !== $f['text']): ?>
        <p class="pdf-special-sub"><?php echo esc_html($f['text_vi']); ?></p>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
    </div>
  </div>
  <?php endif; endif; ?>

  <?php endif; /* end western/vedic conditional */ ?>

</div><!-- .data-section -->

<!-- ═══════════ AI CHAPTERS (lazy-loaded via JS) ═══════════ -->
<div id="ai-chapters">
<?php foreach ($sections as $i => $sec): 
    $num = $i + 1; 
    $has_existing = !empty($existing_sections[$i]) && $_GET['regenerate'] != 1;
?>
<div class="ai-chapter" id="ai-ch-<?php echo $num; ?>">
  <div class="ai-ch-header">
    <div class="ch-icon"><?php echo $sec['icon']; ?></div>
    <div>
      <div class="ch-num">Chương <?php echo $num; ?></div>
      <div class="ch-title"><?php echo esc_html($sec['title']); ?></div>
    </div>
  </div>
  <div class="ai-content" id="ai-content-<?php echo $num; ?>">
    <?php if ($has_existing): ?>
      <?php echo bccm_llm_md_to_html($existing_sections[$i]); ?>
    <?php else: ?>
    <div class="ai-loading" id="ai-load-<?php echo $num; ?>">
      <div class="spinner"></div>
      <div style="margin-top:8px">Đang tạo luận giải AI...</div>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>
</div>

<!-- FOOTER -->
<div class="footer">
  Báo Cáo Chiêm Tinh — BizCoach Map | Dữ liệu: Astrology API | Luận giải: BizCity - BizGPT | Placidus — Tropical<br/>
  Ngày tạo: <?php echo esc_html(current_time('d/m/Y H:i')); ?> | &copy; <?php echo date('Y'); ?> BizCoach Map
</div>

</div><!-- .page -->

<!-- ═══════════ JS: Load AI sections one-by-one ═══════════ -->
<script>
(function(){
  var TOTAL   = <?php echo count($sections); ?>;
  var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
  var nonce   = <?php echo wp_json_encode($section_nonce); ?>;
  var cid     = <?php echo intval($coachee_id); ?>;
  var ctype   = <?php echo wp_json_encode($chart_type); ?>;
  var regen   = <?php echo $regenerate; ?>;
  var existingSections = <?php echo wp_json_encode(array_keys($existing_sections)); ?>;
  var done    = 0;

  function pct(n){ return Math.round((n / TOTAL) * 100); }

  function updateProgress(i, title) {
    var fill = document.getElementById('ai-progress-fill');
    var txt  = document.getElementById('ai-progress-text');
    if (fill) fill.style.width = pct(i) + '%';
    if (txt)  txt.textContent  = '\uD83E\uDD16 Chương ' + i + '/' + TOTAL + ' — ' + title + ' (' + pct(i) + '%)';
  }

  function finishAll() {
    var fill = document.getElementById('ai-progress-fill');
    var txt  = document.getElementById('ai-progress-text');
    var wrap = document.getElementById('ai-progress-wrap');
    var btn  = document.getElementById('btn-print');
    if (fill) fill.style.width = '100%';
    if (txt) { txt.textContent = '\u2705 Hoàn tất! Bạn có thể in PDF (Ctrl+P).'; txt.style.color = '#059669'; }
    if (btn)  btn.disabled = false;
    setTimeout(function(){ if (wrap) wrap.style.display = 'none'; }, 4000);
  }

  function loadSection(idx) {
    if (idx >= TOTAL) { finishAll(); return; }
    var num = idx + 1;
    var container = document.getElementById('ai-content-' + num);
    var loader    = document.getElementById('ai-load-' + num);
    var title     = container.closest('.ai-chapter').querySelector('.ch-title').textContent;
    
    // Skip if already exists and not regenerating
    if (!regen && existingSections.indexOf(idx) !== -1) {
      done++;
      updateProgress(done, title);
      loadSection(idx + 1);
      return;
    }
    
    updateProgress(num, title);

    var url = ajaxUrl + '?action=bccm_llm_section&coachee_id=' + cid + '&chart_type=' + encodeURIComponent(ctype) + '&section=' + idx + '&_wpnonce=' + nonce;
    if (regen) url += '&regenerate=1';

    fetch(url)
      .then(function(r){ return r.json(); })
      .then(function(d){
        if (loader) loader.style.display = 'none';
        if (d.success && d.data && d.data.html) {
          container.innerHTML = d.data.html;
        } else {
          container.innerHTML = '<div class="ai-error">\u26A0\uFE0F ' + (d.data && d.data.error ? d.data.error : 'Lỗi không xác định') + retryBtn(idx) + '</div>';
        }
        done++;
        loadSection(idx + 1);
      })
      .catch(function(e){
        if (loader) loader.style.display = 'none';
        container.innerHTML = '<div class="ai-error">\u26A0\uFE0F Lỗi kết nối: ' + e.message + retryBtn(idx) + '</div>';
        done++;
        loadSection(idx + 1);
      });
  }

  function retryBtn(idx) {
    return ' <button onclick="retrySection(' + idx + ')" style="margin-left:8px;padding:4px 12px;background:#6366f1;color:#fff;border:none;border-radius:4px;cursor:pointer;font-size:11px">\uD83D\uDD04 Thử lại</button>';
  }

  window.retrySection = function(idx) {
    var num = idx + 1;
    var container = document.getElementById('ai-content-' + num);
    container.innerHTML = '<div class="ai-loading"><div class="spinner"></div><div style="margin-top:8px">Đang tạo lại...</div></div>';
    var url = ajaxUrl + '?action=bccm_llm_section&coachee_id=' + cid + '&chart_type=' + encodeURIComponent(ctype) + '&section=' + idx + '&regenerate=1&_wpnonce=' + nonce;
    fetch(url).then(function(r){return r.json()}).then(function(d){
      if (d.success && d.data && d.data.html) container.innerHTML = d.data.html;
      else container.innerHTML = '<div class="ai-error">\u26A0\uFE0F ' + (d.data && d.data.error ? d.data.error : 'Lỗi') + retryBtn(idx) + '</div>';
    }).catch(function(e){ container.innerHTML = '<div class="ai-error">\u26A0\uFE0F ' + e.message + retryBtn(idx) + '</div>'; });
  };

  // Start — Check if all sections already exist
  if (!regen && existingSections.length === TOTAL) {
    // All sections already exist, no need to load
    finishAll();
  } else {
    // Need to load missing sections
    loadSection(0);
  }
})();
</script>

</body>
</html>
    <?php
    exit;
}

/* =====================================================================
 * AJAX 2: SINGLE SECTION GENERATOR (returns JSON)
 * action = bccm_llm_section
 * =====================================================================*/
add_action('wp_ajax_bccm_llm_section', 'bccm_llm_section_handler');

function bccm_llm_section_handler() {
    @set_time_limit(180);

    // Clean any stray output (BOM, whitespace, PHP notices) before JSON
    while (ob_get_level() > 0) @ob_end_clean();

    // Helper: clean buffer + send JSON
    $send_ok = function($data) {
        while (ob_get_level() > 0) @ob_end_clean();
        wp_send_json_success($data);
    };
    $send_err = function($msg) {
        while (ob_get_level() > 0) @ob_end_clean();
        wp_send_json_error(['error' => $msg]);
    };

    if (!current_user_can('edit_posts')) $send_err('Unauthorized');
    check_ajax_referer('bccm_llm_section', '_wpnonce');

    $coachee_id  = intval($_GET['coachee_id'] ?? 0);
    $section_idx = intval($_GET['section'] ?? -1);
    $regenerate  = !empty($_GET['regenerate']);
    $chart_type  = sanitize_text_field($_GET['chart_type'] ?? 'western');

    if (!$coachee_id) $send_err('Missing coachee_id');

    global $wpdb;
    $t = bccm_tables();
    $coachee = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$t['profiles']} WHERE id=%d", $coachee_id
    ), ARRAY_A);
    if (!$coachee) $send_err('Coachee not found');

    // Query astro by user_id first, fallback to coachee_id
    $user_id = $coachee['user_id'] ?? 0;
    $astro_row = null;
    if ($user_id) {
        $astro_row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bccm_astro WHERE user_id=%d AND chart_type=%s AND (summary IS NOT NULL OR traits IS NOT NULL)", $user_id, $chart_type
        ), ARRAY_A);
    }
    if (!$astro_row) {
        $astro_row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bccm_astro WHERE coachee_id=%d AND chart_type=%s", $coachee_id, $chart_type
        ), ARRAY_A);
    }
    if (!$astro_row) $send_err('No astro data for chart type: ' . $chart_type);

    // Use Vedic or Western context builder depending on chart_type
    if ($chart_type === 'vedic' && function_exists('bccm_vedic_build_chart_context')) {
        $chart_ctx = bccm_vedic_build_chart_context($astro_row, $coachee);
    } else {
        $chart_ctx = bccm_llm_build_chart_context($astro_row, $coachee);
    }
    $name      = $coachee['full_name'] ?? 'Người dùng';
    $sections  = bccm_llm_get_sections($chart_ctx, $name);

    if ($section_idx < 0 || $section_idx >= count($sections)) {
        $send_err('Invalid section index');
    }

    $chart_type   = sanitize_text_field($_GET['chart_type'] ?? 'western');
    $chart_hash   = md5($astro_row['updated_at'] ?? '');

    // ── Read cache from bccm_astro.llm_report column ──
    $cached_raw = !empty($astro_row['llm_report']) ? json_decode($astro_row['llm_report'], true) : null;

    if (!$regenerate && is_array($cached_raw) && ($cached_raw['chart_hash'] ?? '') === $chart_hash) {
        $cached_section = $cached_raw['sections'][$section_idx] ?? null;
        if (!empty($cached_section) && is_string($cached_section)) {
            $send_ok([
                'html'   => bccm_llm_md_to_html($cached_section),
                'cached' => true,
            ]);
        }
    }

    // ── Generate via OpenAI ──
    $system = bccm_llm_system_prompt();
    $sec    = $sections[$section_idx];

    $result = bccm_llm_call_openai($system, $sec['prompt'], [
        'max_tokens'  => 10000,
        'temperature' => 0.75,
        'timeout'     => 150,
    ]);

    if (is_wp_error($result)) {
        $send_err($result->get_error_message());
    }

    // ── Save to bccm_astro.llm_report column ──
    if (!is_array($cached_raw) || ($cached_raw['chart_hash'] ?? '') !== $chart_hash) {
        $cached_raw = ['sections' => [], 'generated' => '', 'chart_hash' => $chart_hash];
    }
    $cached_raw['sections'][$section_idx] = $result;
    $cached_raw['generated'] = current_time('mysql');

    // Update by id (from astro_row) for reliability
    $wpdb->update(
        $wpdb->prefix . 'bccm_astro',
        ['llm_report' => wp_json_encode($cached_raw, JSON_UNESCAPED_UNICODE)],
        ['id' => $astro_row['id']]
    );

    $send_ok([
        'html'   => bccm_llm_md_to_html($result),
        'cached' => false,
    ]);
}
