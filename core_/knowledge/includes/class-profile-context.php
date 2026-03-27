<?php
/**
 * Bizcity Twin AI — Nền tảng AI Companion cá nhân hóa
 * Bizcity Twin AI — Personalized AI Companion Platform
 *
 * Profile Context — User Identity Layer for AI Agent
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 *
 * User identity context layer above knowledge layer.
 * Đảm bảo AI Agent luôn hiểu về chủ nhân (user) đang trò chuyện,
 * dựa trên dữ liệu chính xác từ các bảng BizCoach Map:
 *
 *   • bccm_coachees   — hồ sơ cá nhân, doanh nghiệp, tóm tắt AI
 *   • bccm_answers    — câu trả lời coaching
 *   • bccm_astro      — chiêm tinh, cung hoàng đạo, report LLM
 *   • bccm_gen_results — kết quả generator (thần số học, SWOT, v.v.)
 *
 * Cách hoạt động:
 *   1. Tra cứu profile theo user_id (hoặc session_id cho WEBCHAT guest)
 *   2. Thu thập dữ liệu bổ sung từ answers, astro, gen_results
 *   3. Xây dựng context string có cấu trúc → inject vào system prompt
 *
 * Context này được inject VÀO TRƯỚC knowledge context, vì dữ liệu profile
 * là thông tin chuẩn xác (factual), ưu tiên cao hơn kiến thức tổng quát.
 *
 * @package BizCity_Knowledge
 * @since   1.3.1
 */

defined('ABSPATH') or die('OOPS...');

class BizCity_Profile_Context {

    /* ─── Singleton ─── */
    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /** @var \wpdb */
    private $wpdb;

    /** @var array In-memory cache keyed by user_id */
    private $cache = [];

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
    }

    /* ================================================================
     * PUBLIC: Build user context string for AI system prompt
     *
     * @param int    $user_id       WordPress user ID (0 = guest)
     * @param string $session_id    Session ID (for WEBCHAT guests without user_id)
     * @param string $platform_type ADMINCHAT | WEBCHAT
     * @param array  $options       Optional: [
     *   'max_gen_results' => 10,   // limit gen_results rows
     *   'include_astro'   => true,
     *   'include_answers' => true,
     *   'include_gen'     => true,
     *   'coach_type'      => '',   // filter by specific coach_type
     * ]
     *
     * @return string  Formatted context string (empty if no profile found)
     * ================================================================ */
    public function build_user_context($user_id = 0, $session_id = '', $platform_type = 'ADMINCHAT', $options = []) {
        // Start timing
        $profile_start = microtime( true );

        // Defaults
        $opts = wp_parse_args($options, [
            'max_gen_results' => 10,
            'include_astro'   => true,
            'include_answers' => true,
            'include_gen'     => true,
            'coach_type'      => '',
        ]);

        // Resolve user_id from session if needed
        if (!$user_id && $platform_type === 'ADMINCHAT') {
            $user_id = get_current_user_id();
        }

        // Cache key
        $cache_key = $user_id . '_' . $session_id . '_' . $platform_type;
        if (isset($this->cache[$cache_key])) {
            return $this->cache[$cache_key];
        }

        // ── Get profile ──
        $profile = $this->get_profile($user_id, $session_id, $platform_type, $opts['coach_type']);

        if (!$profile) {
            $this->cache[$cache_key] = '';
            return '';
        }

        $coachee_id = (int) $profile->id;
        $sections   = [];

        // ── Section 1: Thông tin cá nhân ──
        $sections[] = $this->format_profile_section($profile);

        // ── Section 2: Tóm tắt AI ──
        if (!empty($profile->ai_summary)) {
            $sections[] = "### Tóm tắt AI về người dùng:\n" . $profile->ai_summary;
        }

        // ── Section 3: Dữ liệu phân tích từ profile JSON fields ──
        $analysis = $this->format_analysis_sections($profile);
        if ($analysis) {
            $sections[] = $analysis;
        }

        // ── Twin Focus Gate: skip astro/coaching sections when mode doesn't need them ──
        $twin_skip_astro    = class_exists( 'BizCity_Focus_Gate' ) && ! BizCity_Focus_Gate::should_inject( 'astro' );
        $twin_skip_coaching = class_exists( 'BizCity_Focus_Gate' ) && ! BizCity_Focus_Gate::should_inject( 'coaching' );

        // ── Section 4: Astro / Chiêm tinh ──
        $t_astro = microtime( true );
        if ($opts['include_astro'] && ! $twin_skip_astro) {
            $astro_ctx = $this->build_astro_context($coachee_id, $user_id);
            if ($astro_ctx) {
                $sections[] = $astro_ctx;
            }
        }
        if ( class_exists( 'BizCity_Twin_Trace' ) ) {
            BizCity_Twin_Trace::layer( 'astro', ! $twin_skip_astro && ! empty( $astro_ctx ), round( ( microtime( true ) - $t_astro ) * 1000, 2 ) );
        }

        // ── Section 5: Answers (câu trả lời phỏng vấn coaching) ──
        $t_coaching = microtime( true );
        if ($opts['include_answers'] && ! $twin_skip_coaching) {
            $answers_ctx = $this->build_answers_context($coachee_id, $profile);
            if ($answers_ctx) {
                $sections[] = $answers_ctx;
            }
        }

        // ── Section 6: Generator results ──
        if ($opts['include_gen'] && ! $twin_skip_coaching) {
            $gen_ctx = $this->build_gen_results_context($coachee_id, $user_id, $opts['max_gen_results']);
            if ($gen_ctx) {
                $sections[] = $gen_ctx;
            }
        }
        if ( class_exists( 'BizCity_Twin_Trace' ) ) {
            BizCity_Twin_Trace::layer( 'coaching', ! $twin_skip_coaching && ( ! empty( $answers_ctx ) || ! empty( $gen_ctx ) ), round( ( microtime( true ) - $t_coaching ) * 1000, 2 ) );
        }

        // ── Fallback: WordPress user info (khi không có bccm profile) ──
        if (empty($sections) && $user_id) {
            $wp_user = get_userdata($user_id);
            if ($wp_user) {
                $wp_lines = ["### Thông tin tài khoản:"];
                $wp_lines[] = "- **Họ tên:** " . ($wp_user->display_name ?: $wp_user->user_login);
                if (!empty($wp_user->user_email)) {
                    $wp_lines[] = "- **Email:** {$wp_user->user_email}";
                }
                $first = get_user_meta($user_id, 'first_name', true);
                $last  = get_user_meta($user_id, 'last_name', true);
                if ($first || $last) {
                    $wp_lines[] = "- **Tên đầy đủ:** " . trim($first . ' ' . $last);
                }
                $desc = get_user_meta($user_id, 'description', true);
                if ($desc) {
                    $wp_lines[] = "- **Giới thiệu:** {$desc}";
                }
                $sections[] = implode("\n", $wp_lines);
            }
        }

        // Assemble
        if (empty($sections)) {
            $this->cache[$cache_key] = '';
            return '';
        }

        $context  = "## 📋 HỒ SƠ CHỦ NHÂN (User Profile — Dữ liệu chính xác)\n\n";
        $context .= "🔴 ĐÂY LÀ THÔNG TIN CÁ NHÂN CHÍNH XÁC về người đang trò chuyện với bạn.\n";
        $context .= "BẠN PHẢI sử dụng thông tin này khi:\n";
        $context .= "- Người dùng hỏi \"tôi là ai\", \"bạn biết tôi không\", \"tên tôi là gì\"\n";
        $context .= "- Người dùng hỏi về thông tin cá nhân, công ty, ngày sinh, cung hoàng đạo của họ\n";
        $context .= "- Bất kỳ câu hỏi nào liên quan đến danh tính hoặc hồ sơ người dùng\n";
        $context .= "- Cần cá nhân hóa câu trả lời (gọi đúng tên, nhắc đến công ty, ngành nghề...)\n\n";
        $context .= "⚠️ KHÔNG được nói \"tôi không biết thông tin về bạn\" khi đã có hồ sơ bên dưới.\n";
        $context .= "⚠️ KHÔNG bịa thêm thông tin cá nhân ngoài những gì được cung cấp.\n\n";
        $context .= implode("\n\n---\n\n", array_filter($sections));

        $this->cache[$cache_key] = $context;

        // ── Log for admin AJAX Console ──
        if ( class_exists( 'BizCity_User_Memory' ) ) {
            BizCity_User_Memory::log_router_event( [
                'step'             => 'profile_build',
                'message'          => 'build_user_context()',
                'mode'             => 'profile',
                'functions_called' => 'BizCity_Profile_Context::build_user_context()',
                'pipeline'         => [ 'get_profile', 'format_profile', 'astro_context', 'answers_context', 'gen_results' ],
                'file_line'        => 'class-profile-context.php:~L175',
                'user_id'          => $user_id,
                'platform_type'    => $platform_type,
                'sections_count'   => count( $sections ),
                'has_astro'        => $opts['include_astro'],
                'has_answers'      => $opts['include_answers'],
                'has_gen'          => $opts['include_gen'],
                'context_length'   => mb_strlen( $context, 'UTF-8' ),
                'preview'          => mb_substr( $context, 0, 200, 'UTF-8' ),
                'profile_ms'       => round( ( microtime( true ) - $profile_start ) * 1000, 2 ),
            ], $session_id );
        }

        return $context;
    }

    /* ================================================================
     * Get profile from bccm_coachees
     * ================================================================ */
    private function get_profile($user_id, $session_id = '', $platform_type = '', $coach_type = '') {
        $table = $this->wpdb->prefix . 'bccm_coachees';

        // Check table exists
        if ($this->wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            return null;
        }

        // Strategy 1: by user_id (most reliable)
        if ($user_id) {
            $where = "user_id = %d";
            $params = [$user_id];

            if ($coach_type) {
                $where .= " AND coach_type = %s";
                $params[] = $coach_type;
            }

            // Get the most recently updated profile
            $sql = $this->wpdb->prepare(
                "SELECT * FROM {$table} WHERE {$where} ORDER BY updated_at DESC LIMIT 1",
                ...$params
            );
            $profile = $this->wpdb->get_row($sql);
            if ($profile) return $profile;
        }

        // Strategy 2: by session_id match in webchat conversations
        // (Useful for WEBCHAT guests who don't have a user_id but were matched to a coachee)
        if ($session_id && !$user_id) {
            $conv_table = $this->wpdb->prefix . 'bizcity_webchat_conversations';
            if ($this->wpdb->get_var("SHOW TABLES LIKE '{$conv_table}'") === $conv_table) {
                // Check if conversation has a linked coachee_id
                $coachee_id = $this->wpdb->get_var($this->wpdb->prepare(
                    "SELECT coachee_id FROM {$conv_table} WHERE session_id = %s AND coachee_id IS NOT NULL AND coachee_id > 0 ORDER BY id DESC LIMIT 1",
                    $session_id
                ));
                if ($coachee_id) {
                    return $this->wpdb->get_row($this->wpdb->prepare(
                        "SELECT * FROM {$table} WHERE id = %d LIMIT 1",
                        $coachee_id
                    ));
                }
            }
        }

        return null;
    }

    /* ================================================================
     * Format profile basic info
     * ================================================================ */
    private function format_profile_section($profile) {
        $lines = ["### Thông tin cá nhân:"];

        // Tên
        if (!empty($profile->full_name)) {
            $lines[] = "- **Họ tên:** {$profile->full_name}";
        }

        // SĐT
        if (!empty($profile->phone)) {
            $lines[] = "- **Số điện thoại:** {$profile->phone}";
        }

        // Địa chỉ
        if (!empty($profile->address)) {
            $lines[] = "- **Địa chỉ:** {$profile->address}";
        }

        // Ngày sinh
        if (!empty($profile->dob) && $profile->dob !== '0000-00-00') {
            $lines[] = "- **Ngày sinh:** {$profile->dob}";
        }

        // Cung hoàng đạo
        if (!empty($profile->zodiac_sign)) {
            $lines[] = "- **Cung hoàng đạo:** {$profile->zodiac_sign}";
        }

        // Loại coaching
        if (!empty($profile->coach_type)) {
            $lines[] = "- **Loại coaching:** {$profile->coach_type}";
        }

        // Công ty
        if (!empty($profile->company_name)) {
            $lines[] = "\n### Thông tin doanh nghiệp:";
            $lines[] = "- **Tên công ty:** {$profile->company_name}";

            if (!empty($profile->company_industry)) {
                $lines[] = "- **Ngành nghề:** {$profile->company_industry}";
            }
            if (!empty($profile->company_product)) {
                $lines[] = "- **Sản phẩm/dịch vụ:** {$profile->company_product}";
            }
            if (!empty($profile->company_founded_date) && $profile->company_founded_date !== '0000-00-00') {
                $lines[] = "- **Ngày thành lập:** {$profile->company_founded_date}";
            }
        }

        // Baby info (for mom/baby coaching)
        if (!empty($profile->baby_name)) {
            $lines[] = "\n### Thông tin em bé:";
            $lines[] = "- **Tên bé:** {$profile->baby_name}";
            if (!empty($profile->baby_gender)) {
                $lines[] = "- **Giới tính:** {$profile->baby_gender}";
            }
            if (!empty($profile->baby_gestational_weeks)) {
                $lines[] = "- **Tuần thai:** {$profile->baby_gestational_weeks}";
            }
            if (!empty($profile->baby_weight_kg)) {
                $lines[] = "- **Cân nặng:** {$profile->baby_weight_kg} kg";
            }
            if (!empty($profile->baby_height_cm)) {
                $lines[] = "- **Chiều cao:** {$profile->baby_height_cm} cm";
            }
        }

        // Extra fields (type-specific: career_coach, health_coach, tarot_coach, ...)
        if (!empty($profile->extra_fields_json)) {
            $extra = json_decode($profile->extra_fields_json, true);
            if (is_array($extra) && !empty($extra)) {
                // Lấy label từ registry nếu có
                $field_labels = [];
                if (function_exists('bccm_fields_for_type') && !empty($profile->coach_type)) {
                    $type_fields = bccm_fields_for_type($profile->coach_type);
                    foreach ($type_fields as $fk => $fcfg) {
                        $field_labels[$fk] = $fcfg['label'] ?? $fk;
                    }
                }
                $lines[] = "\n### Thông tin bổ sung:";
                foreach ($extra as $ek => $ev) {
                    if ($ev === '' || $ev === null) continue;
                    $label = $field_labels[$ek] ?? ucfirst(str_replace('_', ' ', $ek));
                    $lines[] = "- **{$label}:** {$ev}";
                }
            }
        }

        return implode("\n", $lines);
    }

    /* ================================================================
     * Format analysis JSON fields from profile
     * ================================================================ */
    private function format_analysis_sections($profile) {
        $parts = [];

        // Map of JSON field → label
        $json_fields = [
            'numeric_json'  => 'Phân tích Thần số học',
            'vision_json'   => 'Tầm nhìn & Mục tiêu',
            'swot_json'     => 'Phân tích SWOT',
            'customer_json' => 'Phân tích Khách hàng',
            'winning_json'  => 'Chiến lược Chiến thắng',
            'value_json'    => 'Giá trị Cốt lõi',
            'bizcoach_json' => 'Coaching Doanh nghiệp',
            'baby_json'     => 'Phân tích Em bé',
            'iqmap_json'    => 'Bản đồ IQ / Trí tuệ',
            'health_json'   => 'Phân tích Sức khỏe',
            'mental_json'   => 'Phân tích Tâm lý',
        ];

        foreach ($json_fields as $field => $label) {
            if (empty($profile->$field)) continue;

            $data = json_decode($profile->$field, true);
            if (empty($data)) continue;

            $summary = $this->extract_json_summary($data, $label);
            if ($summary) {
                $parts[] = "### {$label}:\n{$summary}";
            }
        }

        return $parts ? implode("\n\n", $parts) : '';
    }

    /* ================================================================
     * Extract readable summary from a JSON data array
     *
     * Handles multiple common structures:
     *   - { "summary": "..." }
     *   - { "key": "value", ... } (flat key-value)
     *   - { "sections": [ { "title", "content" }, ... ] }
     * ================================================================ */
    private function extract_json_summary($data, $label = '') {
        if (!is_array($data)) return '';

        // If has a "summary" or "ai_summary" key, use it directly
        foreach (['summary', 'ai_summary', 'result', 'output', 'report'] as $key) {
            if (!empty($data[$key]) && is_string($data[$key])) {
                return $data[$key];
            }
        }

        // If has "sections" array, format each
        if (!empty($data['sections']) && is_array($data['sections'])) {
            $lines = [];
            foreach ($data['sections'] as $sec) {
                $title   = $sec['title'] ?? $sec['label'] ?? '';
                $content = $sec['content'] ?? $sec['text'] ?? $sec['value'] ?? '';
                if ($title && $content) {
                    $lines[] = "- **{$title}:** {$content}";
                }
            }
            return $lines ? implode("\n", $lines) : '';
        }

        // Flat key-value: format as bullet list (limit to avoid huge context)
        $lines = [];
        $count = 0;
        foreach ($data as $key => $value) {
            if ($count >= 15) {
                $lines[] = "- _(còn " . (count($data) - $count) . " mục nữa...)_";
                break;
            }
            if (is_string($value) || is_numeric($value)) {
                $readable_key = ucfirst(str_replace(['_', '-'], ' ', $key));
                $lines[] = "- **{$readable_key}:** {$value}";
                $count++;
            } elseif (is_array($value) && !empty($value)) {
                // Nested: show as JSON snippet if small
                $json_str = wp_json_encode($value, JSON_UNESCAPED_UNICODE);
                if (strlen($json_str) <= 300) {
                    $readable_key = ucfirst(str_replace(['_', '-'], ' ', $key));
                    $lines[] = "- **{$readable_key}:** {$json_str}";
                    $count++;
                }
            }
        }

        return $lines ? implode("\n", $lines) : '';
    }

    /* ================================================================
     * Build astro context from bccm_astro
     * ================================================================ */
    private function build_astro_context($coachee_id, $user_id = 0) {
        $table = $this->wpdb->prefix . 'bccm_astro';

        if ($this->wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            return '';
        }

        // Get all chart types for this coachee
        $where_clause = "coachee_id = %d";
        $params = [$coachee_id];

        // Also check by user_id as fallback
        if ($user_id) {
            $where_clause = "(coachee_id = %d OR user_id = %d)";
            $params = [$coachee_id, $user_id];
        }

        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT chart_type, birth_place, birth_time, timezone, summary, traits, llm_report
             FROM {$table}
             WHERE {$where_clause}
             ORDER BY updated_at DESC",
            ...$params
        ));

        if (empty($rows)) return '';

        $parts = ["### Chiêm tinh / Astrology:"];

        foreach ($rows as $row) {
            $chart_label = $row->chart_type === 'western' ? 'Chiêm tinh phương Tây' : 
                          ($row->chart_type === 'vedic' ? 'Chiêm tinh Vedic' : ucfirst($row->chart_type));

            $parts[] = "\n**{$chart_label}:**";

            if (!empty($row->birth_place)) {
                $parts[] = "- Nơi sinh: {$row->birth_place}";
            }
            if (!empty($row->birth_time)) {
                $parts[] = "- Giờ sinh: {$row->birth_time}";
            }
            if (!empty($row->timezone)) {
                $parts[] = "- Múi giờ: GMT+{$row->timezone}";
            }

            // Summary
            if (!empty($row->summary)) {
                $parts[] = "\n**Tóm tắt:** {$row->summary}";
            }

            // Traits — hiển thị vị trí sao natal đầy đủ (không tóm tắt)
            if (!empty($row->traits)) {
                $traits_data = json_decode($row->traits, true);
                if (is_array($traits_data)) {
                    // Planets positions
                    $positions = $traits_data['positions'] ?? [];
                    if (!empty($positions)) {
                        $planet_lines = [];
                        $planet_order = ['Sun','Moon','Mercury','Venus','Mars','Jupiter','Saturn','Uranus','Neptune','Pluto','Ascendant','MC'];
                        $planet_vi    = [
                            'Sun' => 'Mặt Trời', 'Moon' => 'Mặt Trăng', 'Mercury' => 'Sao Thủy',
                            'Venus' => 'Sao Kim', 'Mars' => 'Sao Hỏa', 'Jupiter' => 'Sao Mộc',
                            'Saturn' => 'Sao Thổ', 'Uranus' => 'Sao Thiên Vương',
                            'Neptune' => 'Sao Hải Vương', 'Pluto' => 'Sao Diêm Vương',
                            'Ascendant' => 'Mọc (ASC)', 'MC' => 'Thiên Đỉnh (MC)',
                        ];
                        // Display ordered planets first, then any remaining
                        $shown = [];
                        foreach ($planet_order as $pname) {
                            if (empty($positions[$pname])) continue;
                            $p        = $positions[$pname];
                            $vi       = $planet_vi[$pname] ?? $pname;
                            $sign_vi  = $p['sign_vi'] ?? ($p['sign_en'] ?? '');
                            $sign_en  = $p['sign_en'] ?? '';
                            $deg      = isset($p['norm_degree']) ? round((float)$p['norm_degree'], 1) : '';
                            $retro    = !empty($p['is_retro']) ? ' ℞' : '';
                            $label    = $sign_vi ?: $sign_en;
                            $planet_lines[] = "- **{$vi}** ({$pname}): cung **{$label}**" . ($deg !== '' ? " {$deg}°" : '') . $retro;
                            $shown[$pname] = true;
                        }
                        // Remaining planets not in the ordered list
                        foreach ($positions as $pname => $p) {
                            if (isset($shown[$pname])) continue;
                            $vi      = $planet_vi[$pname] ?? $pname;
                            $sign_vi = $p['sign_vi'] ?? ($p['sign_en'] ?? '');
                            $deg     = isset($p['norm_degree']) ? round((float)$p['norm_degree'], 1) : '';
                            $retro   = !empty($p['is_retro']) ? ' ℞' : '';
                            $planet_lines[] = "- **{$vi}** ({$pname}): cung **{$sign_vi}**" . ($deg !== '' ? " {$deg}°" : '') . $retro;
                        }
                        if (!empty($planet_lines)) {
                            $parts[] = "\n**Vị trí các hành tinh natal:**\n" . implode("\n", $planet_lines);
                        }
                    }

                    // Sun/Moon/ASC summary line if positions empty (fallback)
                    if (empty($positions)) {
                        foreach (['sun_sign', 'moon_sign', 'ascendant_sign'] as $k) {
                            if (!empty($traits_data[$k])) {
                                $parts[] = "- **" . ucfirst(str_replace('_', ' ', $k)) . ":** {$traits_data[$k]}";
                            }
                        }
                    }
                }
            }

            // LLM Report (could be long, take first 1500 chars)
            if (!empty($row->llm_report)) {
                $report = $row->llm_report;
                if (mb_strlen($report) > 1500) {
                    $report = mb_substr($report, 0, 1500) . '... _(báo cáo đã rút gọn)_';
                }
                $parts[] = "\n**Báo cáo chi tiết:**\n{$report}";
            }
        }

        return implode("\n", $parts);
    }

    /* ================================================================
     * Build answers context from profile answer_json + template questions
     *
     * Câu trả lời phỏng vấn coaching được lưu trong cột answer_json
     * (bảng bccm_coachees), paired với câu hỏi từ bccm_templates.
     * Đây là thông tin quan trọng giúp AI hiểu vấn đề hiện tại,
     * mục tiêu, và hoàn cảnh thực tế của user.
     * ================================================================ */
    private function build_answers_context($coachee_id, $profile = null) {
        // ── Source 1: answer_json từ profile (primary — Step 2 lưu ở đây) ──
        $from_profile = $this->build_answers_from_profile($profile);

        // ── Source 2: bccm_answers table (legacy / secondary) ──
        $from_table = $this->build_answers_from_table($coachee_id);

        $all = trim($from_profile . "\n" . $from_table);
        return $all ?: '';
    }

    /**
     * Đọc answer_json từ profile, ghép với câu hỏi template.
     */
    private function build_answers_from_profile($profile) {
        if (!$profile || empty($profile->answer_json)) {
            return '';
        }

        $answers = json_decode($profile->answer_json, true);
        if (!is_array($answers)) return '';

        // Kiểm tra có ít nhất 1 câu trả lời không rỗng
        $has_answer = false;
        foreach ($answers as $a) {
            if (is_string($a) && trim($a) !== '') { $has_answer = true; break; }
        }
        if (!$has_answer) return '';

        // Lấy câu hỏi tương ứng từ template
        $questions = [];
        $coach_type = $profile->coach_type ?? '';
        if ($coach_type && function_exists('bccm_get_questions_for')) {
            $questions = bccm_get_questions_for($coach_type);
        }

        $parts = ["### Câu trả lời phỏng vấn Coaching:"];
        $parts[] = "_(Đây là câu hỏi khảo sát để hiểu vấn đề hiện tại, mong muốn và hoàn cảnh thực tế của người dùng)_";

        $count = 0;
        foreach ($answers as $i => $answer) {
            if (!is_string($answer) || trim($answer) === '') continue;
            if ($count >= 20) break;

            $question = '';
            if (isset($questions[$i]) && is_string($questions[$i])) {
                $question = $questions[$i];
            }

            if ($question) {
                $parts[] = "- **Hỏi:** {$question}\n  **Trả lời:** {$answer}";
            } else {
                $parts[] = "- **Câu " . ($i + 1) . ":** {$answer}";
            }
            $count++;
        }

        return $count > 0 ? implode("\n", $parts) : '';
    }

    /**
     * Đọc từ bảng bccm_answers (legacy, backward-compatible).
     */
    private function build_answers_from_table($coachee_id) {
        $table = $this->wpdb->prefix . 'bccm_answers';

        if ($this->wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            return '';
        }

        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT a.answers, t.title as template_title, t.coach_type
             FROM {$table} a
             LEFT JOIN {$this->wpdb->prefix}bccm_templates t ON a.template_id = t.id
             WHERE a.coachee_id = %d
             ORDER BY a.updated_at DESC
             LIMIT 5",
            $coachee_id
        ));

        if (empty($rows)) return '';

        $parts = [];

        foreach ($rows as $row) {
            if (empty($row->answers)) continue;

            $answers_data = json_decode($row->answers, true);
            if (!is_array($answers_data)) continue;

            $title = $row->template_title ?: ($row->coach_type ?: 'Coaching');
            $parts[] = "\n**{$title}:**";

            $count = 0;
            foreach ($answers_data as $qa) {
                if ($count >= 10) {
                    $parts[] = "- _(còn thêm câu trả lời...)_";
                    break;
                }

                $question = $qa['question'] ?? $qa['q'] ?? $qa['label'] ?? '';
                $answer   = $qa['answer']   ?? $qa['a'] ?? $qa['value'] ?? '';

                if (is_array($answer)) {
                    $answer = wp_json_encode($answer, JSON_UNESCAPED_UNICODE);
                }

                if ($question && $answer) {
                    $parts[] = "- **{$question}:** {$answer}";
                    $count++;
                } elseif ($answer) {
                    $parts[] = "- {$answer}";
                    $count++;
                }
            }
        }

        return !empty($parts) ? implode("\n", $parts) : '';
    }

    /* ================================================================
     * Build generator results context from bccm_gen_results
     * ================================================================ */
    private function build_gen_results_context($coachee_id, $user_id = 0, $limit = 10) {
        $table = $this->wpdb->prefix . 'bccm_gen_results';

        if ($this->wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            return '';
        }

        // Build WHERE
        $where_clause = "coachee_id = %d";
        $params = [$coachee_id];

        if ($user_id) {
            $where_clause = "(coachee_id = %d OR user_id = %d)";
            $params = [$coachee_id, $user_id];
        }

        $params[] = $limit;

        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT gen_key, gen_label, coach_type, result_json, status, updated_at
             FROM {$table}
             WHERE {$where_clause} AND status = 'success'
             ORDER BY updated_at DESC
             LIMIT %d",
            ...$params
        ));

        if (empty($rows)) return '';

        $parts = ["### Kết quả phân tích (Generator Results):"];

        foreach ($rows as $row) {
            $label = $row->gen_label ?: $row->gen_key;
            $parts[] = "\n**{$label}** _{$row->coach_type}_:";

            if (!empty($row->result_json)) {
                $result_data = json_decode($row->result_json, true);
                if (is_array($result_data)) {
                    $summary = $this->extract_json_summary($result_data, $label);
                    if ($summary) {
                        // Limit each result to avoid overwhelming the context
                        if (mb_strlen($summary) > 800) {
                            $summary = mb_substr($summary, 0, 800) . '... _(đã rút gọn)_';
                        }
                        $parts[] = $summary;
                    }
                }
            }
        }

        return count($parts) > 1 ? implode("\n", $parts) : '';
    }

    /* ================================================================
     * Utility: Get all profiles for a user (for multi-coach scenarios)
     *
     * @param int    $user_id
     * @param string $coach_type  Optional filter
     * @return array  Array of profile objects
     * ================================================================ */
    public function get_user_profiles($user_id, $coach_type = '') {
        $table = $this->wpdb->prefix . 'bccm_coachees';

        if ($this->wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            return [];
        }

        $where = "user_id = %d";
        $params = [$user_id];

        if ($coach_type) {
            $where .= " AND coach_type = %s";
            $params[] = $coach_type;
        }

        return $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT * FROM {$table} WHERE {$where} ORDER BY updated_at DESC",
            ...$params
        ));
    }

    /* ================================================================
     * Utility: Clear cache (e.g., after profile update)
     * ================================================================ */
    public function clear_cache($user_id = 0) {
        if ($user_id) {
            // Clear specific user
            foreach ($this->cache as $key => $val) {
                if (strpos($key, $user_id . '_') === 0) {
                    unset($this->cache[$key]);
                }
            }
        } else {
            $this->cache = [];
        }
    }

    /* ================================================================
     * PUBLIC: Build transit context when user asks about future/astrology
     *
     * Called from chat gateway when transit intent is detected.
     *
     * @param string $message        User message (for intent detection)
     * @param int    $user_id        WordPress user ID
     * @param string $session_id     Session ID (for guest fallback)
     * @param string $platform_type  ADMINCHAT | WEBCHAT
     * @return string  Transit context string (empty if no intent or no data)
     * ================================================================ */
    public function build_transit_context($message, $user_id = 0, $session_id = '', $platform_type = 'ADMINCHAT', $active_goal = '') {
        // Start timing
        $transit_start = microtime( true );

        // 1. Detect transit intent from message
        if (!function_exists('bccm_transit_detect_intent')) {
            $this->log_transit_debug($session_id, $message, 'no_function', null, 0, '', $transit_start);
            return '';
        }

        $intent = bccm_transit_detect_intent($message);

        // If we're already inside an astro forecast goal but the raw message
        // didn't trigger (e.g., user simply answered "ngày mai" without
        // repeating "chiêm tinh"), retry with a synthetic astro-prefixed
        // message so the time-pattern detection still fires.
        if ( ! $intent && in_array( $active_goal, [ 'astro_forecast', 'daily_outlook' ], true ) ) {
            $intent = bccm_transit_detect_intent( 'chiêm tinh ' . $message );
        }

        if (!$intent) {
            $this->log_transit_debug($session_id, $message, 'no_intent', null, 0, '', $transit_start);
            return '';
        }

        // 2. Get coachee_id
        $coachee_id = $this->resolve_coachee_id($user_id, $session_id, $platform_type);
        if (!$coachee_id) {
            $this->log_transit_debug($session_id, $message, 'no_coachee', $intent, 0, '', $transit_start);
            return '';
        }

        // 3. Build transit context via bizcoach-map function
        if (!function_exists('bccm_transit_build_context')) {
            $this->log_transit_debug($session_id, $message, 'no_build_func', $intent, $coachee_id, '', $transit_start);
            return '';
        }

        $context = bccm_transit_build_context($coachee_id, $user_id, $intent);
        $this->log_transit_debug($session_id, $message, 'success', $intent, $coachee_id, $context, $transit_start);
        return $context;
    }

    /**
     * Log transit build debug info to Router Console.
     */
    private function log_transit_debug($session_id, $message, $status, $intent, $coachee_id, $context, $start_time = 0) {
        if (!class_exists('BizCity_User_Memory')) return;
        
        BizCity_User_Memory::log_router_event([
            'step'             => 'transit_build',
            'message'          => mb_substr($message, 0, 80, 'UTF-8'),
            'mode'             => 'transit',
            'functions_called' => 'build_transit_context()',
            'file_line'        => 'class-profile-context.php::build_transit_context()',
            'status'           => $status,
            'intent_type'      => $intent['type'] ?? '',
            'intent_period'    => $intent['period'] ?? '',
            'intent_topic'     => $intent['topic'] ?? '',
            'coachee_id'       => $coachee_id,
            'context_length'   => mb_strlen($context, 'UTF-8'),
            'context_preview'  => mb_substr($context, 0, 200, 'UTF-8'),
            'transit_ms'       => $start_time ? round( ( microtime( true ) - $start_time ) * 1000, 2 ) : 0,
        ], $session_id);
    }

    /* ================================================================
     * Helper: Resolve coachee_id from user_id or session
     * ================================================================ */
    private function resolve_coachee_id($user_id, $session_id = '', $platform_type = '') {
        $profile = $this->get_profile($user_id, $session_id, $platform_type, '');
        return $profile ? (int) $profile->id : 0;
    }
}
