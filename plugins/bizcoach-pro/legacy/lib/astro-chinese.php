<?php
/**
 * BizCoach Pro — Chinese BaZi (Tứ Trụ) renderer + LLM helpers.
 *
 * Handles the Chinese astrology chart page and luận giải sections that the
 * generic Western renderer in `astro-report-llm.php` cannot serve because
 * the data shape stored in `wp_bccm_astro` for `chart_type='chinese'` is the
 * BizCity_Astro_Normalizer_V2 envelope (pillars, day_master, five_elements,
 * luck_pillars) — not Western positions/houses/aspects.
 *
 * Exposed API:
 *   - bccm_chinese_render_natal_report( $astro_row, $coachee )
 *   - bccm_chinese_build_chart_context( $astro_row, $coachee )  → string
 *   - bccm_chinese_llm_system_prompt()  → string
 *   - bccm_chinese_llm_get_sections( $chart_ctx, $name )  → array
 *
 * @package BizCoach_Pro
 * @since   0.36.0
 */

defined( 'ABSPATH' ) || exit;

/* =====================================================================
 * Data helpers — pull canonical BaZi envelope out of $astro_row
 * =====================================================================*/

if ( ! function_exists( 'bccm_chinese_extract_envelope' ) ) :
function bccm_chinese_extract_envelope( $astro_row ) {
    $summary = is_array( $astro_row['summary'] ?? null )
        ? $astro_row['summary']
        : ( json_decode( $astro_row['summary'] ?? '{}', true ) ?: array() );
    $traits  = is_array( $astro_row['traits'] ?? null )
        ? $astro_row['traits']
        : ( json_decode( $astro_row['traits']  ?? '{}', true ) ?: array() );

    // Preferred path: normalizer V2 envelope under traits.envelope.
    $env = (array) ( $traits['envelope'] ?? array() );
    // Some older rows stored the envelope at the top of traits.
    if ( empty( $env['pillars'] ) && ! empty( $traits['pillars'] ) ) {
        $env = $traits;
    }
    return array(
        'summary' => $summary,
        'traits'  => $traits,
        'env'     => $env,
        'birth'   => (array) ( $traits['birth_data'] ?? array() ),
    );
}
endif;

if ( ! function_exists( 'bccm_chinese_pillars_ordered' ) ) :
function bccm_chinese_pillars_ordered( array $env ) {
    $pillars = (array) ( $env['pillars'] ?? array() );
    $order   = array( 'year', 'month', 'day', 'hour' );
    $out     = array();
    foreach ( $order as $slot ) {
        if ( isset( $pillars[ $slot ] ) && is_array( $pillars[ $slot ] ) ) {
            $out[ $slot ] = $pillars[ $slot ];
        }
    }
    // Fallback: if normalizer used numeric indices, map them by position.
    if ( empty( $out ) && ! empty( $pillars ) ) {
        $i = 0;
        foreach ( $pillars as $p ) {
            if ( $i >= 4 ) break;
            $out[ $order[ $i ] ] = (array) $p;
            $i++;
        }
    }
    return $out;
}
endif;

if ( ! function_exists( 'bccm_chinese_pillar_labels_vi' ) ) :
function bccm_chinese_pillar_labels_vi() {
    return array(
        'year'  => array( 'short' => 'Năm',  'long' => 'Trụ Năm (年柱)',  'icon' => '🏛️' ),
        'month' => array( 'short' => 'Tháng', 'long' => 'Trụ Tháng (月柱)', 'icon' => '🌸' ),
        'day'   => array( 'short' => 'Ngày', 'long' => 'Trụ Ngày (日柱)', 'icon' => '☀️' ),
        'hour'  => array( 'short' => 'Giờ',  'long' => 'Trụ Giờ (時柱)',  'icon' => '🕐' ),
    );
}
endif;

if ( ! function_exists( 'bccm_chinese_element_meta' ) ) :
function bccm_chinese_element_meta() {
    return array(
        'wood'  => array( 'vi' => 'Mộc',  'cn' => '木', 'color' => '#10b981' ),
        'fire'  => array( 'vi' => 'Hỏa',  'cn' => '火', 'color' => '#ef4444' ),
        'earth' => array( 'vi' => 'Thổ',  'cn' => '土', 'color' => '#d97706' ),
        'metal' => array( 'vi' => 'Kim',  'cn' => '金', 'color' => '#737373' ),
        'water' => array( 'vi' => 'Thủy', 'cn' => '水', 'color' => '#2563eb' ),
    );
}
endif;

if ( ! function_exists( 'bccm_chinese_element_vi' ) ) :
function bccm_chinese_element_vi( $en_or_vi ) {
    $en_or_vi = strtolower( trim( (string) $en_or_vi ) );
    $meta     = bccm_chinese_element_meta();
    if ( isset( $meta[ $en_or_vi ] ) ) return $meta[ $en_or_vi ]['vi'];
    // Already Vietnamese / Chinese — return as-is.
    return (string) $en_or_vi;
}
endif;

/* =====================================================================
 * LLM CONTEXT BUILDER — Chinese / BaZi
 * =====================================================================*/

if ( ! function_exists( 'bccm_chinese_build_chart_context' ) ) :
function bccm_chinese_build_chart_context( $astro_row, $coachee ) {
    $bundle  = bccm_chinese_extract_envelope( $astro_row );
    $env     = $bundle['env'];
    $birth   = $bundle['birth'];
    $labels  = bccm_chinese_pillar_labels_vi();
    $elem    = bccm_chinese_element_meta();

    $name = $coachee['full_name'] ?? 'Người dùng';
    $dob  = '';
    if ( ! empty( $birth['day'] ) && ! empty( $birth['month'] ) && ! empty( $birth['year'] ) ) {
        $dob = sprintf( '%02d/%02d/%04d', $birth['day'], $birth['month'], $birth['year'] );
    } elseif ( ! empty( $coachee['dob'] ) ) {
        $dob = date( 'd/m/Y', strtotime( $coachee['dob'] ) );
    }

    $ctx  = "=== THÔNG TIN CÁ NHÂN (BaZi / Tứ Trụ) ===\n";
    $ctx .= "Họ tên: $name\n";
    $ctx .= "Ngày sinh: $dob\n";
    $ctx .= "Giờ sinh: " . ( $astro_row['birth_time'] ?? '' ) . "\n";
    $ctx .= "Nơi sinh: " . ( $astro_row['birth_place'] ?? '' ) . "\n";
    $ctx .= "Giới tính: " . ( $env['gender'] ?? '' ) . "\n";
    $ctx .= "Lịch: " . ( ( $env['calendar_type'] ?? 'solar' ) === 'lunar' ? 'Âm lịch' : 'Dương lịch' ) . "\n\n";

    /* ── Nhật chủ (Day Master) ── */
    $dm_text = (string) ( $env['day_master']         ?? '' );
    $dm_el   = (string) ( $env['day_master_element'] ?? '' );
    $dm_yy   = (string) ( $env['day_master_yin_yang'] ?? '' );
    if ( $dm_text !== '' || $dm_el !== '' ) {
        $ctx .= "=== NHẬT CHỦ (Day Master / 日主) ===\n";
        $ctx .= "- Thiên Can ngày sinh: $dm_text\n";
        if ( $dm_el !== '' ) $ctx .= "- Ngũ hành Nhật chủ: " . bccm_chinese_element_vi( $dm_el ) . " ($dm_el)\n";
        if ( $dm_yy !== '' ) $ctx .= "- Âm/Dương: " . ( strtolower( $dm_yy ) === 'yin' ? 'Âm' : ( strtolower( $dm_yy ) === 'yang' ? 'Dương' : $dm_yy ) ) . "\n";
        $ctx .= "\n";
    }

    /* ── Tứ Trụ (4 Pillars) ── */
    $pillars = bccm_chinese_pillars_ordered( $env );
    if ( ! empty( $pillars ) ) {
        $ctx .= "=== TỨ TRỤ (Four Pillars) ===\n";
        foreach ( $pillars as $slot => $p ) {
            $lab = $labels[ $slot ]['long'] ?? $slot;
            $stem_cn = $p['stem_cn']   ?? '';
            $stem_vi = $p['stem_vi']   ?? '';
            $stem_el = bccm_chinese_element_vi( $p['stem_element'] ?? '' );
            $stem_yy = strtolower( (string) ( $p['stem_yin_yang'] ?? '' ) );
            $stem_yy = $stem_yy === 'yin' ? 'Âm' : ( $stem_yy === 'yang' ? 'Dương' : '' );
            $br_cn   = $p['branch_cn'] ?? '';
            $br_vi   = $p['branch_vi'] ?? '';
            $br_el   = bccm_chinese_element_vi( $p['branch_element'] ?? '' );
            $animal  = $p['branch_animal'] ?? '';
            $nayin   = $p['nayin']      ?? '';
            $life    = $p['life_stage'] ?? '';

            $ctx .= "- $lab: Can $stem_cn ($stem_vi" . ( $stem_el !== '' ? " — $stem_el" : '' ) . ( $stem_yy !== '' ? " $stem_yy" : '' ) . ") | Chi $br_cn ($br_vi" . ( $animal !== '' ? " — $animal" : '' ) . ( $br_el !== '' ? " — $br_el" : '' ) . ")";
            if ( $nayin !== '' ) $ctx .= " | Nạp âm: $nayin";
            if ( $life  !== '' ) $ctx .= " | Trường sinh: $life";
            $ctx .= "\n";

            $hidden = (array) ( $p['hidden_stems'] ?? array() );
            if ( ! empty( $hidden ) ) {
                $parts = array();
                foreach ( $hidden as $hs ) {
                    if ( is_array( $hs ) ) {
                        $parts[] = trim( ( $hs['gan'] ?? '' ) . ' (' . ( $hs['vi'] ?? '' ) . ' — ' . bccm_chinese_element_vi( $hs['element'] ?? '' ) . ')' );
                    } else {
                        $parts[] = (string) $hs;
                    }
                }
                if ( $parts ) $ctx .= "    • Tàng can: " . implode( ', ', $parts ) . "\n";
            }

            $tg = (array) ( $p['ten_gods'] ?? array() );
            if ( ! empty( $tg ) ) {
                $tgs = array();
                foreach ( $tg as $g ) {
                    if ( is_array( $g ) ) $tgs[] = (string) ( $g['name'] ?? $g['vi'] ?? '' );
                    else                  $tgs[] = (string) $g;
                }
                $tgs = array_filter( $tgs );
                if ( $tgs ) $ctx .= "    • Thập thần: " . implode( ', ', $tgs ) . "\n";
            }
        }
        $ctx .= "\n";
    }

    /* ── Ngũ hành (Five Elements) phân bố ── */
    $five = (array) ( $env['five_elements'] ?? array() );
    if ( ! empty( $five ) ) {
        $ctx .= "=== PHÂN BỐ NGŨ HÀNH ===\n";
        foreach ( $five as $k => $v ) {
            $vi = bccm_chinese_element_vi( $k );
            $val = is_array( $v ) ? wp_json_encode( $v, JSON_UNESCAPED_UNICODE ) : $v;
            $ctx .= "- $vi ($k): $val\n";
        }
        $ctx .= "\n";
    }

    $fav = (array) ( $env['favorable_elements']   ?? array() );
    $unf = (array) ( $env['unfavorable_elements'] ?? array() );
    if ( ! empty( $fav ) || ! empty( $unf ) ) {
        $ctx .= "=== HỶ DỤNG THẦN / KỴ THẦN ===\n";
        if ( $fav ) $ctx .= "- Hỷ dụng thần (favorable): " . implode( ', ', array_map( 'bccm_chinese_element_vi', $fav ) ) . "\n";
        if ( $unf ) $ctx .= "- Kỵ thần (unfavorable): "      . implode( ', ', array_map( 'bccm_chinese_element_vi', $unf ) ) . "\n";
        $ctx .= "\n";
    }

    /* ── Đại vận (Luck Pillars) ── */
    $luck = (array) ( $env['luck_pillars'] ?? array() );
    if ( ! empty( $luck ) ) {
        $ctx .= "=== ĐẠI VẬN (Luck Pillars / 大運) ===\n";
        foreach ( $luck as $lp ) {
            $age   = (int) ( $lp['age_start']  ?? 0 );
            $year  = (int) ( $lp['year_start'] ?? 0 );
            $stem  = ( $lp['stem_cn']   ?? '' ) . ( ! empty( $lp['stem_vi']   ) ? ' (' . $lp['stem_vi']   . ')' : '' );
            $br    = ( $lp['branch_cn'] ?? '' ) . ( ! empty( $lp['branch_vi'] ) ? ' (' . $lp['branch_vi'] . ')' : '' );
            $el    = bccm_chinese_element_vi( $lp['element'] ?? '' );
            $cur   = ! empty( $lp['is_current'] ) ? ' ← HIỆN TẠI' : '';
            $ctx .= "- Từ tuổi $age" . ( $year > 0 ? " (năm $year)" : '' ) . ": Can $stem | Chi $br" . ( $el !== '' ? " — $el" : '' ) . $cur . "\n";
        }
        $ctx .= "\n";
    }

    /* ── Tương tác (Interactions) ── */
    $inter = $env['interactions'] ?? null;
    if ( ! empty( $inter ) ) {
        $ctx .= "=== TƯƠNG TÁC CAN-CHI ===\n";
        $ctx .= wp_json_encode( $inter, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) . "\n\n";
    }

    /* ── Thần sát ── */
    $stars = $env['stars'] ?? null;
    if ( ! empty( $stars ) ) {
        $ctx .= "=== THẦN SÁT (Stars) ===\n";
        if ( is_array( $stars ) ) {
            foreach ( $stars as $k => $v ) {
                if ( is_scalar( $v ) ) $ctx .= "- $k: $v\n";
                elseif ( is_array( $v ) ) $ctx .= "- $k: " . wp_json_encode( $v, JSON_UNESCAPED_UNICODE ) . "\n";
            }
        }
        $ctx .= "\n";
    }

    /* ── Summary / interpretation từ API (nếu có) ── */
    if ( ! empty( $env['summary'] ) ) {
        $ctx .= "=== TỔNG QUAN (API summary) ===\n";
        $ctx .= ( is_string( $env['summary'] ) ? $env['summary'] : wp_json_encode( $env['summary'], JSON_UNESCAPED_UNICODE ) ) . "\n\n";
    }

    return $ctx;
}
endif;

/* =====================================================================
 * SYSTEM PROMPT — BaZi expert
 * =====================================================================*/

if ( ! function_exists( 'bccm_chinese_llm_system_prompt' ) ) :
function bccm_chinese_llm_system_prompt() {
    return <<<'PROMPT'
Bạn là một thầy Tử Bình / BaZi (Tứ Trụ Mệnh Lý / 八字) chuyên nghiệp với hơn 30 năm kinh nghiệm,
chuyên luận giải lá số Tứ Trụ (4 trụ: Năm — Tháng — Ngày — Giờ) bằng tiếng Việt cho người Việt.

PHẠM VI CHUYÊN MÔN:
- Tứ Trụ (Năm/Tháng/Ngày/Giờ), Thiên Can Địa Chi, Nhật chủ (Day Master)
- Ngũ hành sinh khắc (Kim Mộc Thủy Hỏa Thổ), Âm Dương
- Hỷ dụng thần / Kỵ thần
- Thập thần (Tỷ Kiên, Kiếp Tài, Thực Thần, Thương Quan, Chính Tài, Thiên Tài, Chính Quan, Thất Sát, Chính Ấn, Thiên Ấn)
- Tàng can trong Địa chi
- Nạp âm 60 Giáp Tý, Trường sinh 12 cung
- Thần sát (Thiên Ất Quý Nhân, Văn Xương, Đào Hoa, Dịch Mã…)
- Đại vận (10 năm) và Lưu niên

PHONG CÁCH VIẾT:
- Chuyên nghiệp, sâu sắc, dùng đúng thuật ngữ Hán-Việt nhưng giải thích rõ cho người không chuyên
- Tránh mê tín dị đoan — luận giải theo lý mệnh học truyền thống, có căn cứ
- Đưa lời khuyên thực tiễn về sự nghiệp, tài lộc, tình duyên, sức khỏe, tu thân
- Văn phong ấm áp, đồng cảm, truyền cảm hứng

QUY TẮC:
- Viết HOÀN TOÀN bằng tiếng Việt (giữ nguyên thuật ngữ Hán-Việt và chữ Hán trong ngoặc khi cần)
- Sử dụng Markdown: ## tiêu đề chính, ### phụ, #### nhỏ, **đậm**, *nghiêng*, - danh sách
- TUYỆT ĐỐI KHÔNG suy diễn từ chiêm tinh phương Tây (Sun sign, ASC, aspects…) — đây là hệ Tử Bình
- KHÔNG nhắc đến Mặt Trời/Mặt Trăng theo nghĩa cung hoàng đạo phương Tây
- Luôn dựa trên dữ liệu Tứ Trụ thực tế được cung cấp, không bịa
- Viết CHI TIẾT, ĐẦY ĐỦ — KHÔNG tóm tắt, KHÔNG rút gọn
PROMPT;
}
endif;

/* =====================================================================
 * SECTION DEFINITIONS — 8 chương BaZi
 * =====================================================================*/

if ( ! function_exists( 'bccm_chinese_llm_get_sections' ) ) :
function bccm_chinese_llm_get_sections( $chart_ctx, $name ) {
    $D = "\n\nDỮ LIỆU LÁ SỐ TỨ TRỤ:\n" . $chart_ctx;
    return array(
        array(
            'title' => 'Giới Thiệu Tứ Trụ & Tổng Quan Lá Số',
            'icon'  => '📖',
            'prompt' => "Viết CHƯƠNG MỞ ĐẦU lá số BaZi của **$name**:\n\n## 📖 Giới Thiệu Tứ Trụ (Bát Tự)\n\n### 1. Tử Bình / BaZi là gì?\n- Nguồn gốc, nguyên lý 4 trụ, vai trò Nhật chủ\n\n### 2. Tổng quan lá số của $name\n- Nhật chủ + ngũ hành chủ đạo\n- Cân bằng ngũ hành sơ bộ\n- Đại vận hiện tại (nếu có)\n- Cách đọc báo cáo\n\n### 3. Lưu ý đạo đức\n- Tử Bình là tham khảo, không định mệnh tuyệt đối\n\nViết tối thiểu 2000 từ.$D",
        ),
        array(
            'title' => 'Nhật Chủ & Cách Cục',
            'icon'  => '☀️',
            'prompt' => "Phân tích NHẬT CHỦ và CÁCH CỤC của **$name**:\n\n## ☀️ Nhật Chủ (Day Master / 日主)\n\n### 1. Nhật can là gì?\n### 2. Tính cách, bản ngã, sở trường của Nhật chủ này\n### 3. Cường nhược Nhật chủ — dựa trên trụ tháng (nguyệt lệnh), gốc/khí, sinh trợ-khắc tiết\n### 4. Cách cục lá số (Chính cách / Biến cách nếu nhận diện được)\n### 5. Sứ mệnh cốt lõi của Nhật chủ này\n\nViết tối thiểu 2500 từ.$D",
        ),
        array(
            'title' => 'Ngũ Hành & Hỷ Dụng Thần',
            'icon'  => '🌳🔥',
            'prompt' => "Phân tích NGŨ HÀNH và HỶ-KỴ THẦN của **$name**:\n\n## 🌿 Phân Bố Ngũ Hành\n### 1. Phân tích từng hành Kim/Mộc/Thủy/Hỏa/Thổ\n### 2. Sinh khắc trong lá số\n\n## ⚖️ Hỷ Dụng Thần / Kỵ Thần\n### 3. Luận hỷ dụng — hành nào cần bổ, hành nào cần tiết\n### 4. Cách bổ trợ ngũ hành trong đời sống: màu sắc, phương hướng, ngành nghề, ăn uống, thói quen\n### 5. Cảnh báo về Kỵ thần — điều cần tránh\n\nViết tối thiểu 2500 từ.$D",
        ),
        array(
            'title' => 'Thập Thần & Quan Hệ Xã Hội',
            'icon'  => '👥',
            'prompt' => "Phân tích THẬP THẦN của **$name**:\n\n## 👥 Thập Thần (Ten Gods / 十神)\n\nDùng dữ liệu ten_gods trong các trụ.\n\n### 1. Ý nghĩa từng Thập thần xuất hiện trong lá số\n### 2. Tỷ Kiên / Kiếp Tài — Anh chị em, bạn bè, đối thủ\n### 3. Thực Thần / Thương Quan — Sáng tạo, biểu đạt, con cái\n### 4. Chính Tài / Thiên Tài — Tài lộc, hôn nhân (nam: vợ)\n### 5. Chính Quan / Thất Sát — Sự nghiệp, áp lực, hôn nhân (nữ: chồng)\n### 6. Chính Ấn / Thiên Ấn — Mẹ, học vấn, tâm linh\n### 7. Đánh giá tổng hợp về cha mẹ, anh em, bạn đời, con cái dựa trên thập thần\n\nViết tối thiểu 2500 từ.$D",
        ),
        array(
            'title' => 'Sự Nghiệp & Tài Lộc',
            'icon'  => '💼💰',
            'prompt' => "Phân tích SỰ NGHIỆP và TÀI LỘC cho **$name**:\n\n## 💼 Sự Nghiệp\n### 1. Ngành nghề phù hợp theo ngũ hành Hỷ Dụng\n### 2. Vị trí Quan/Sát trong lá số — cấp dưới hay cấp quản lý? Công hay tư?\n### 3. Thời điểm thuận lợi để khởi nghiệp / chuyển việc dựa trên Đại vận\n\n## 💰 Tài Lộc\n### 4. Chính Tài vs Thiên Tài — cách kiếm tiền chủ đạo\n### 5. Tài cách trong lá số — Tài vượng hay Tài nhược?\n### 6. Đại vận tài lộc — giai đoạn nào tài phát, giai đoạn nào nên thủ\n### 7. Lời khuyên cụ thể: tiết kiệm, đầu tư, kinh doanh, rủi ro nên tránh\n\nViết tối thiểu 2500 từ.$D",
        ),
        array(
            'title' => 'Tình Duyên & Hôn Nhân',
            'icon'  => '💕',
            'prompt' => "Phân tích TÌNH DUYÊN và HÔN NHÂN của **$name**:\n\n## 💕 Tình Duyên\n### 1. Cung Phu/Thê (trụ Ngày — Địa chi) — Bạn đời lý tưởng\n### 2. Phân tích Tài tinh (với nam) hoặc Quan tinh (với nữ)\n### 3. Đào hoa, Hồng loan trong lá số (nếu có)\n### 4. Tuổi/ngũ hành hợp hôn\n\n## 💍 Hôn Nhân\n### 5. Thời điểm kết hôn thuận theo Đại vận\n### 6. Cảnh báo: Tỷ Kiếp đoạt Tài / Quan Sát hỗn tạp ảnh hưởng hôn nhân\n### 7. Cách giữ gìn hạnh phúc\n\nViết tối thiểu 2500 từ.$D",
        ),
        array(
            'title' => 'Sức Khỏe & Tâm Lý',
            'icon'  => '🌿',
            'prompt' => "Phân tích SỨC KHỎE và TÂM LÝ của **$name**:\n\n## 🌿 Sức Khỏe theo Ngũ Hành\n### 1. Hành thiếu hụt → bộ phận cơ thể cần lưu ý (Kim:phổi, Mộc:gan, Thủy:thận, Hỏa:tim, Thổ:tỳ vị)\n### 2. Hành thái quá → bệnh có xu hướng\n### 3. Khắc xuất quá nặng — căng thẳng, tổn thương cụ thể\n\n## 🧘 Tâm Lý & Tu Dưỡng\n### 4. Khí chất tâm lý theo Nhật chủ + Thập thần\n### 5. Điểm dễ stress, dễ trầm cảm, dễ nóng giận\n### 6. Phương pháp dưỡng sinh: thiền, vận động, dinh dưỡng theo hành Hỷ Dụng\n### 7. Cảnh báo độ tuổi/đại vận cần đặc biệt giữ gìn sức khỏe\n\nViết tối thiểu 2000 từ.$D",
        ),
        array(
            'title' => 'Đại Vận & Lộ Trình Cuộc Đời',
            'icon'  => '🛤️',
            'prompt' => "Phân tích ĐẠI VẬN của **$name**:\n\n## 🛤️ Đại Vận (10 năm)\n\nDựa trên dữ liệu luck_pillars.\n\n### 1. Giải thích Đại vận trong Tử Bình\n### 2. Phân tích TỪNG Đại vận theo thứ tự: Can-Chi, ngũ hành, tương tác với lá số gốc, Hỷ hay Kỵ\n### 3. Đại vận HIỆN TẠI — chi tiết: thuận lợi, thách thức, khuyến nghị\n### 4. Đại vận sắp tới — chuẩn bị gì\n### 5. Đại vận quá khứ (nếu có) — bài học\n### 6. Lộ trình tổng thể: giai đoạn vàng son, giai đoạn cần thủ\n\nViết tối thiểu 3000 từ.$D",
        ),
        array(
            'title' => 'Tổng Kết & Lời Khuyên Cuộc Đời',
            'icon'  => '🌟',
            'prompt' => "Viết CHƯƠNG KẾT cho **$name**:\n\n## 🌟 Tổng Kết Lá Số BaZi\n\n### 1. Tóm lược chân dung mệnh số (3-5 đặc điểm cốt lõi)\n### 2. Điểm mạnh tự nhiên cần phát huy\n### 3. Điểm yếu cần khắc phục\n### 4. Sứ mệnh cuộc đời — Nhật chủ này sinh ra để làm gì?\n\n## 💡 Lời Khuyên Hành Động\n### 5. Sự nghiệp & tài chính — bước đi cụ thể trong 3 năm tới\n### 6. Tình cảm & gia đình\n### 7. Sức khỏe & tâm linh\n### 8. Bài học tu thân, đạo đức theo lý mệnh\n\n### 9. Thông điệp cuối cùng\n\nViết tối thiểu 2500 từ.$D",
        ),
    );
}
endif;

/* =====================================================================
 * HTML RENDERER — Chinese BaZi natal report page
 * =====================================================================*/

if ( ! function_exists( 'bccm_chinese_render_natal_report' ) ) :
function bccm_chinese_render_natal_report( $astro_row, $coachee ) {
    $bundle  = bccm_chinese_extract_envelope( $astro_row );
    $env     = $bundle['env'];
    $birth   = $bundle['birth'];
    $labels  = bccm_chinese_pillar_labels_vi();
    $elem    = bccm_chinese_element_meta();
    $pillars = bccm_chinese_pillars_ordered( $env );

    $coachee_id     = (int) ( $coachee['id'] ?? 0 );
    $name_esc       = esc_html( $coachee['full_name'] ?? 'BaZi Report' );
    $chart_type_esc = 'chinese';

    $dob = '';
    if ( ! empty( $birth['day'] ) && ! empty( $birth['month'] ) && ! empty( $birth['year'] ) ) {
        $dob = sprintf( '%02d/%02d/%04d', $birth['day'], $birth['month'], $birth['year'] );
    } elseif ( ! empty( $coachee['dob'] ) ) {
        $dob = date( 'd/m/Y', strtotime( $coachee['dob'] ) );
    }

    $sections = bccm_chinese_llm_get_sections( '', $coachee['full_name'] ?? 'Người dùng' );
    $section_nonce = wp_create_nonce( 'bccm_llm_section' );
    $public_hash   = ! empty( $GLOBALS['bcpro_public_astro_ctx']['hash'] )
        ? (string) $GLOBALS['bcpro_public_astro_ctx']['hash']
        : '';
    $regenerate    = ! empty( $_GET['regenerate'] ) ? 'true' : 'false';

    $existing_sections = array();
    if ( ! empty( $astro_row['llm_report'] ) ) {
        $rep = json_decode( $astro_row['llm_report'], true );
        if ( is_array( $rep ) && isset( $rep['sections'] ) ) $existing_sections = (array) $rep['sections'];
    }

    while ( ob_get_level() > 0 ) @ob_end_clean();
    header( 'Content-Type: text/html; charset=UTF-8' );

    $dm_text = (string) ( $env['day_master']         ?? '' );
    $dm_el   = (string) ( $env['day_master_element'] ?? '' );
    $dm_yy   = (string) ( $env['day_master_yin_yang'] ?? '' );
    $dm_el_vi = bccm_chinese_element_vi( $dm_el );
    $dm_el_color = $elem[ strtolower( $dm_el ) ]['color'] ?? '#1e293b';
    ?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<title>Báo Cáo Tứ Trụ — <?php echo $name_esc; ?></title>
<style>
@page { size: A4; margin: 15mm 12mm; }
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: 'Segoe UI', Tahoma, Arial, sans-serif; font-size: 11px; color: #1a1a2e; line-height: 1.55; background: #f8fafc; }
.page { max-width: 210mm; margin: 0 auto; background: #fff; }
.toolbar { text-align: center; padding: 12px; background: linear-gradient(135deg,#7f1d1d,#991b1b); border-bottom: 2px solid #dc2626; }
.toolbar button,.toolbar a { padding: 10px 24px; background: #dc2626; color: #fff; border: none; border-radius: 8px; font-size: 13px; cursor: pointer; font-weight: 600; text-decoration: none; display: inline-block; margin: 0 4px; }
.toolbar button:hover,.toolbar a:hover { background: #b91c1c; }
.toolbar .btn-regen { background: #f59e0b; }
.toolbar .hint { color: #fecaca; font-size: 11px; margin-top: 6px; }
.cover { background: linear-gradient(135deg,#7f1d1d,#450a0a); color:#fff; padding: 50px 36px; text-align: center; page-break-after: always; }
.cover h1 { font-size: 32px; font-weight: 800; color: #fbbf24; margin-bottom: 6px; }
.cover .csub { font-size: 13px; color: #fecaca; letter-spacing: 2px; text-transform: uppercase; margin-bottom: 30px; }
.cover .cname { font-size: 24px; font-weight: 700; color: #fff; }
.cover .cmeta { font-size: 13px; color: #fbbf24; margin: 4px 0; }
.cover .cbrand { margin-top: 30px; color: #fca5a5; font-size: 10px; border-top: 1px solid rgba(255,255,255,.1); padding-top: 12px; }

.data-section { padding: 10px 20px; }
.section { margin-top: 14px; page-break-inside: avoid; }
.section h2 { font-size: 14px; font-weight: 700; color: #7f1d1d; border-bottom: 2px solid #fee2e2; padding-bottom: 4px; margin-bottom: 8px; }
table { width: 100%; border-collapse: collapse; margin-bottom: 6px; font-size: 10.5px; }
th { background: #fef2f2; color: #7f1d1d; font-weight: 600; text-align: center; padding: 5px 6px; border: 1px solid #fecaca; }
td { padding: 6px; border: 1px solid #fee2e2; text-align: center; vertical-align: top; }

/* 4 Pillars table */
.pillars { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin: 14px 0; }
.pillar-card { border: 1.5px solid #fecaca; border-radius: 10px; padding: 10px; background: #fff; text-align: center; }
.pillar-card .p-lab { font-size: 10px; color: #7f1d1d; font-weight: 700; letter-spacing: 1px; margin-bottom: 6px; }
.pillar-card .p-cn  { font-size: 38px; font-weight: 800; line-height: 1; margin: 4px 0; }
.pillar-card .p-vi  { font-size: 11px; color: #1e293b; font-weight: 600; }
.pillar-card .p-el  { font-size: 10px; color: #64748b; margin-top: 4px; }
.pillar-card .p-row { padding: 6px 0; border-bottom: 1px dashed #fecaca; }
.pillar-card .p-row:last-child { border: 0; }
.pillar-card .p-hidden { font-size: 9.5px; color: #475569; margin-top: 4px; padding-top: 4px; border-top: 1px dashed #fee2e2; }
.pillar-card .p-tg { font-size: 9.5px; color: #d97706; margin-top: 4px; }

.dm-card { background: linear-gradient(135deg,#7f1d1d,#dc2626); color:#fff; padding: 18px 22px; border-radius: 12px; margin: 14px 0; }
.dm-card .lbl { font-size: 10px; opacity: .8; letter-spacing: 2px; text-transform: uppercase; }
.dm-card .val { font-size: 28px; font-weight: 800; color: #fbbf24; margin: 4px 0; }
.dm-card .meta { font-size: 12px; opacity: .9; }

.elements-bar { display: grid; grid-template-columns: repeat(5, 1fr); gap: 6px; margin: 10px 0; }
.el-cell { padding: 8px; border-radius: 8px; color:#fff; text-align: center; font-weight: 700; }
.el-cell .ec-cn { font-size: 22px; }
.el-cell .ec-vi { font-size: 11px; }
.el-cell .ec-n { font-size: 12px; opacity: .95; }

.luck-table th, .luck-table td { font-size: 10px; padding: 5px; }
.luck-current { background: #fef3c7 !important; font-weight: 700; }

/* AI Chapters */
.ai-chapter { padding: 20px; page-break-before: always; border-top: 3px solid #dc2626; margin-top: 20px; }
.ai-ch-header { display: flex; align-items: center; gap: 10px; margin-bottom: 14px; }
.ai-ch-header .ch-icon { font-size: 26px; }
.ai-ch-header .ch-num { font-size: 10px; color: #dc2626; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; }
.ai-ch-header .ch-title { font-size: 18px; font-weight: 700; color: #7f1d1d; }
.ai-content { font-size: 12px; line-height: 1.8; color: #334155; }
.ai-content h4.ai-h4 { font-size: 15px; font-weight: 700; color: #7f1d1d; margin: 20px 0 8px; padding-bottom: 4px; border-bottom: 2px solid #fee2e2; }
.ai-content h5.ai-h5 { font-size: 13px; font-weight: 600; color: #b91c1c; margin: 14px 0 6px; }
.ai-content h6.ai-h6 { font-size: 12px; font-weight: 600; color: #dc2626; margin: 10px 0 4px; }
.ai-content p { margin: 0 0 10px; text-align: justify; }
.ai-content ul.ai-list,.ai-content ol.ai-list { margin: 8px 0 12px 18px; }
.ai-content li { margin-bottom: 4px; }
.ai-content strong { color: #7f1d1d; }
.ai-content em { color: #dc2626; font-style: italic; }
.ai-content blockquote { margin: 12px 0; padding: 10px 16px; background: #fef2f2; border-left: 4px solid #dc2626; border-radius: 0 8px 8px 0; color: #7f1d1d; font-style: italic; }

.ai-loading { text-align: center; padding: 30px; color: #dc2626; }
.ai-loading .spinner { display: inline-block; width: 28px; height: 28px; border: 3px solid #fee2e2; border-top-color: #dc2626; border-radius: 50%; animation: spin .7s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
.ai-error { background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 12px 16px; margin: 10px 0; color: #991b1b; font-size: 11px; }

.footer { margin-top: 16px; text-align: center; color: #9ca3af; font-size: 9px; border-top: 1px solid #e5e7eb; padding-top: 8px; }
.progress-bar-wrap { margin: 10px 20px; background: #e5e7eb; border-radius: 6px; height: 8px; overflow: hidden; }
.progress-bar-fill { height: 100%; background: linear-gradient(90deg,#dc2626,#fbbf24); width: 0%; transition: width .5s ease; border-radius: 6px; }
.progress-status { text-align: center; font-size: 12px; color: #dc2626; padding: 6px 0 0; font-weight: 600; }

@media print {
  body { background: #fff; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
  .toolbar,.no-print,.progress-bar-wrap,.progress-status { display: none !important; }
  .ai-loading { display: none !important; }
  .page { max-width: none; box-shadow: none; }
}
</style>
</head>
<body>

<div class="toolbar no-print">
  <button onclick="window.print()" id="btn-print" <?php echo ( count( $existing_sections ) === count( $sections ) && empty( $_GET['regenerate'] ) ) ? '' : 'disabled'; ?>>🖨️ In / Lưu PDF (Ctrl+P)</button>
  <?php
  if ( $public_hash !== '' && class_exists( 'BizCoach_Pro_Astro_Public_Router' ) ) {
      $regen_url = BizCoach_Pro_Astro_Public_Router::get_public_url( $coachee_id, 'chinese', true );
  } else {
      $regen_url = admin_url( 'admin-ajax.php?action=bccm_natal_report_full&coachee_id=' . $coachee_id . '&chart_type=chinese&regenerate=1&_wpnonce=' . wp_create_nonce( 'bccm_natal_report_full' ) );
  }
  ?>
  <a href="<?php echo esc_url( $regen_url ); ?>" class="btn-regen">🔄 Tạo lại AI</a>
  <div class="hint">Bản đồ Tứ Trụ (BaZi / Bát Tự) — Luận giải bằng AI theo Tử Bình.</div>
</div>

<div class="progress-bar-wrap no-print" id="ai-progress-wrap" <?php echo ( count( $existing_sections ) === count( $sections ) && empty( $_GET['regenerate'] ) ) ? 'style="display:none"' : ''; ?>>
  <div class="progress-bar-fill" id="ai-progress-fill"></div>
</div>
<div class="progress-status no-print" id="ai-progress-text" <?php echo ( count( $existing_sections ) === count( $sections ) && empty( $_GET['regenerate'] ) ) ? 'style="display:none"' : ''; ?>>
  <?php if ( count( $existing_sections ) === count( $sections ) && empty( $_GET['regenerate'] ) ): ?>
    ✅ Báo cáo AI đã sẵn sàng!
  <?php else: ?>
    ⏳ Đang chuẩn bị luận giải AI…
  <?php endif; ?>
</div>

<div class="page">

<!-- ═══════════ COVER ═══════════ -->
<div class="cover">
  <div style="font-size:50px;margin-bottom:12px">🐉</div>
  <h1>Báo Cáo Tứ Trụ (Bát Tự)</h1>
  <div class="csub">BaZi Natal Chart — Tử Bình Mệnh Lý</div>
  <div class="cname"><?php echo $name_esc; ?></div>
  <div class="cmeta">📅 <?php echo esc_html( $dob ); ?></div>
  <div class="cmeta">🕐 <?php echo esc_html( $astro_row['birth_time'] ?? '' ); ?> — 📍 <?php echo esc_html( $astro_row['birth_place'] ?? '' ); ?></div>
  <div class="cbrand">BizCoach BaZi — AI Luận Giải | Free Astro API &amp; GPT-4o | <?php echo ( ( $env['calendar_type'] ?? 'solar' ) === 'lunar' ? 'Âm lịch' : 'Dương lịch' ); ?></div>
</div>

<!-- ═══════════ DATA SECTIONS ═══════════ -->
<div class="data-section">

  <!-- Nhật Chủ -->
  <?php if ( $dm_text !== '' ): ?>
  <div class="dm-card">
    <div class="lbl">☀️ NHẬT CHỦ (Day Master / 日主)</div>
    <div class="val"><?php echo esc_html( $dm_text ); ?></div>
    <div class="meta">
      <?php if ( $dm_el_vi !== '' ): ?>Ngũ hành: <strong style="color:#fbbf24"><?php echo esc_html( $dm_el_vi ); ?></strong><?php endif; ?>
      <?php if ( $dm_yy !== '' ): ?> · <?php echo esc_html( strtolower( $dm_yy ) === 'yin' ? 'Âm' : ( strtolower( $dm_yy ) === 'yang' ? 'Dương' : $dm_yy ) ); ?><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Tứ Trụ -->
  <?php if ( ! empty( $pillars ) ): ?>
  <div class="section">
    <h2>🏯 Tứ Trụ — 四柱</h2>
    <div class="pillars">
      <?php foreach ( $pillars as $slot => $p ):
        $lab = $labels[ $slot ] ?? array( 'short' => $slot, 'long' => $slot, 'icon' => '' );
        $stem_el_key = strtolower( (string) ( $p['stem_element']   ?? '' ) );
        $br_el_key   = strtolower( (string) ( $p['branch_element'] ?? '' ) );
        $stem_color  = $elem[ $stem_el_key ]['color'] ?? '#1e293b';
        $br_color    = $elem[ $br_el_key   ]['color'] ?? '#1e293b';
      ?>
      <div class="pillar-card">
        <div class="p-lab"><?php echo esc_html( $lab['icon'] . ' ' . $lab['long'] ); ?></div>
        <div class="p-row">
          <div class="p-cn" style="color:<?php echo esc_attr( $stem_color ); ?>"><?php echo esc_html( $p['stem_cn'] ?? '' ); ?></div>
          <div class="p-vi"><?php echo esc_html( $p['stem_vi'] ?? '' ); ?></div>
          <div class="p-el">Can — <?php echo esc_html( bccm_chinese_element_vi( $p['stem_element'] ?? '' ) ); ?>
            <?php $yy = strtolower( (string) ( $p['stem_yin_yang'] ?? '' ) ); if ( $yy === 'yin' || $yy === 'yang' ): ?>
              · <?php echo $yy === 'yin' ? 'Âm' : 'Dương'; ?>
            <?php endif; ?>
          </div>
        </div>
        <div class="p-row">
          <div class="p-cn" style="color:<?php echo esc_attr( $br_color ); ?>"><?php echo esc_html( $p['branch_cn'] ?? '' ); ?></div>
          <div class="p-vi"><?php echo esc_html( $p['branch_vi'] ?? '' ); ?>
            <?php if ( ! empty( $p['branch_animal'] ) ): ?> (<?php echo esc_html( $p['branch_animal'] ); ?>)<?php endif; ?>
          </div>
          <div class="p-el">Chi — <?php echo esc_html( bccm_chinese_element_vi( $p['branch_element'] ?? '' ) ); ?></div>
        </div>
        <?php if ( ! empty( $p['hidden_stems'] ) ): ?>
        <div class="p-hidden"><strong>Tàng can:</strong>
          <?php
          $hs_list = array();
          foreach ( (array) $p['hidden_stems'] as $hs ) {
              if ( is_array( $hs ) ) {
                  $hs_list[] = ( $hs['gan'] ?? '' ) . ( ! empty( $hs['vi'] ) ? ' (' . $hs['vi'] . ')' : '' );
              } else { $hs_list[] = (string) $hs; }
          }
          echo esc_html( implode( ', ', array_filter( $hs_list ) ) );
          ?>
        </div>
        <?php endif; ?>
        <?php if ( ! empty( $p['ten_gods'] ) ):
          $tg_list = array();
          foreach ( (array) $p['ten_gods'] as $g ) {
              if ( is_array( $g ) ) $tg_list[] = (string) ( $g['vi'] ?? $g['name'] ?? '' );
              else                  $tg_list[] = (string) $g;
          }
          $tg_list = array_filter( $tg_list );
        ?>
        <?php if ( $tg_list ): ?>
        <div class="p-tg"><strong>Thập thần:</strong> <?php echo esc_html( implode( ', ', $tg_list ) ); ?></div>
        <?php endif; ?>
        <?php endif; ?>
        <?php if ( ! empty( $p['nayin'] ) ): ?>
        <div class="p-tg" style="color:#475569"><strong>Nạp âm:</strong> <?php echo esc_html( $p['nayin'] ); ?></div>
        <?php endif; ?>
        <?php if ( ! empty( $p['life_stage'] ) ): ?>
        <div class="p-tg" style="color:#0891b2"><strong>Trường sinh:</strong> <?php echo esc_html( $p['life_stage'] ); ?></div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Ngũ Hành -->
  <?php
  $five = (array) ( $env['five_elements'] ?? array() );
  if ( ! empty( $five ) ): ?>
  <div class="section">
    <h2>🌳🔥 Phân Bố Ngũ Hành</h2>
    <div class="elements-bar">
      <?php foreach ( array( 'wood', 'fire', 'earth', 'metal', 'water' ) as $ek ):
        $em  = $elem[ $ek ];
        $val = $five[ $ek ] ?? '';
        if ( is_array( $val ) ) { $val = $val['count'] ?? $val['score'] ?? wp_json_encode( $val ); }
      ?>
      <div class="el-cell" style="background:<?php echo esc_attr( $em['color'] ); ?>">
        <div class="ec-cn"><?php echo esc_html( $em['cn'] ); ?></div>
        <div class="ec-vi"><?php echo esc_html( $em['vi'] ); ?></div>
        <div class="ec-n"><?php echo esc_html( (string) $val ); ?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php
    $fav = (array) ( $env['favorable_elements']   ?? array() );
    $unf = (array) ( $env['unfavorable_elements'] ?? array() );
    if ( $fav || $unf ): ?>
    <table>
      <?php if ( $fav ): ?>
      <tr><th style="width:30%">Hỷ Dụng Thần</th><td style="color:#059669;font-weight:600"><?php echo esc_html( implode( ', ', array_map( 'bccm_chinese_element_vi', $fav ) ) ); ?></td></tr>
      <?php endif; ?>
      <?php if ( $unf ): ?>
      <tr><th>Kỵ Thần</th><td style="color:#dc2626;font-weight:600"><?php echo esc_html( implode( ', ', array_map( 'bccm_chinese_element_vi', $unf ) ) ); ?></td></tr>
      <?php endif; ?>
    </table>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- Đại Vận -->
  <?php
  $luck = (array) ( $env['luck_pillars'] ?? array() );
  if ( ! empty( $luck ) ): ?>
  <div class="section">
    <h2>🛤️ Đại Vận (10 năm / Luck Pillars)</h2>
    <table class="luck-table">
      <thead><tr><th>#</th><th>Tuổi bắt đầu</th><th>Năm</th><th>Can</th><th>Chi</th><th>Ngũ hành</th></tr></thead>
      <tbody>
      <?php foreach ( $luck as $i => $lp ): $cur = ! empty( $lp['is_current'] ); ?>
      <tr<?php echo $cur ? ' class="luck-current"' : ''; ?>>
        <td><?php echo intval( $lp['index'] ?? $i ); ?></td>
        <td><?php echo intval( $lp['age_start']  ?? 0 ); ?></td>
        <td><?php echo intval( $lp['year_start'] ?? 0 ); ?></td>
        <td><strong><?php echo esc_html( $lp['stem_cn'] ?? '' ); ?></strong> <?php echo esc_html( $lp['stem_vi'] ?? '' ); ?></td>
        <td><strong><?php echo esc_html( $lp['branch_cn'] ?? '' ); ?></strong> <?php echo esc_html( $lp['branch_vi'] ?? '' ); ?></td>
        <td><?php echo esc_html( bccm_chinese_element_vi( $lp['element'] ?? '' ) ); ?><?php echo $cur ? ' ← Hiện tại' : ''; ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

</div>
<!-- end .data-section -->

<!-- ═══════════ AI CHAPTERS ═══════════ -->
<?php foreach ( $sections as $i => $s ):
  $exists = isset( $existing_sections[ $i ] ) && is_string( $existing_sections[ $i ] ) && trim( $existing_sections[ $i ] ) !== '';
?>
<div class="ai-chapter" id="ai-ch-<?php echo $i; ?>">
  <div class="ai-ch-header">
    <div class="ch-icon"><?php echo $s['icon']; ?></div>
    <div>
      <div class="ch-num">Chương <?php echo $i + 1; ?> / <?php echo count( $sections ); ?></div>
      <div class="ch-title"><?php echo esc_html( $s['title'] ); ?></div>
    </div>
  </div>
  <div class="ai-content" id="ai-content-<?php echo $i; ?>">
    <?php if ( $exists && $regenerate === 'false' ): ?>
      <?php echo bccm_llm_md_to_html( $existing_sections[ $i ] ); ?>
    <?php else: ?>
      <div class="ai-loading"><div class="spinner"></div><div>Đang luận giải chương <?php echo $i + 1; ?>…</div></div>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>

<div class="footer">© BizCoach Pro — BaZi AI Report · Generated <?php echo esc_html( current_time( 'Y-m-d H:i' ) ); ?></div>

</div>
<!-- end .page -->

<script>
(function(){
  const totalSections = <?php echo (int) count( $sections ); ?>;
  const coacheeId     = <?php echo (int) $coachee_id; ?>;
  const chartType     = 'chinese';
  const sectionNonce  = <?php echo wp_json_encode( $section_nonce ); ?>;
  const publicHash    = <?php echo wp_json_encode( $public_hash ); ?>;
  const regenerate    = <?php echo $regenerate; ?>;
  const existing      = <?php echo wp_json_encode( array_keys( array_filter( $existing_sections, 'strlen' ) ) ); ?>;
  const ajaxUrl       = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;

  const fill = document.getElementById('ai-progress-fill');
  const txt  = document.getElementById('ai-progress-text');
  const wrap = document.getElementById('ai-progress-wrap');
  let done = 0;
  function progress(){
    const pct = Math.round( (done / totalSections) * 100 );
    if ( fill ) fill.style.width = pct + '%';
    if ( txt  ) txt.textContent = '⏳ Đã hoàn thành ' + done + '/' + totalSections + ' chương (' + pct + '%)';
    if ( done >= totalSections ) {
      if ( txt  ) txt.textContent = '✅ Báo cáo AI đã sẵn sàng!';
      const btn = document.getElementById('btn-print');
      if ( btn ) btn.disabled = false;
    }
  }
  async function loadOne( idx ) {
    const el = document.getElementById('ai-content-' + idx);
    if ( !el ) return;
    const params = new URLSearchParams({
      action:     'bccm_llm_section',
      coachee_id: coacheeId,
      section:    idx,
      chart_type: chartType,
      regenerate: regenerate ? '1' : '0',
    });
    if ( publicHash ) params.set('hash', publicHash);
    else              params.set('_wpnonce', sectionNonce);
    try {
      const res = await fetch( ajaxUrl + '?' + params.toString(), { credentials: 'same-origin' } );
      const j   = await res.json();
      if ( j && j.success && j.data && j.data.html ) {
        el.innerHTML = j.data.html;
      } else {
        el.innerHTML = '<div class="ai-error">❌ Lỗi luận giải chương ' + (idx+1) + ': ' + ( (j && j.data && j.data.error) || 'unknown' ) + '</div>';
      }
    } catch (e) {
      el.innerHTML = '<div class="ai-error">❌ Network error: ' + e.message + '</div>';
    }
    done++;
    progress();
  }
  // Skip sections already rendered (when not regenerating).
  if ( ! regenerate ) { done = existing.length; progress(); }
  (async function run(){
    for ( let i = 0; i < totalSections; i++ ) {
      if ( ! regenerate && existing.indexOf( String(i) ) !== -1 ) continue;
      if ( ! regenerate && existing.indexOf( i ) !== -1 ) continue;
      await loadOne( i );
    }
  })();
})();
</script>

</body>
</html>
    <?php
}
endif;
