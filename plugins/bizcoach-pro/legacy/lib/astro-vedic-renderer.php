<?php
/**
 * BizCoach Pro — Vedic (Jyotish) chart renderer + LLM helpers.
 *
 * Renders the full North-Indian-style natal report page for
 * `chart_type = 'vedic'`.  Data comes from `bccm_vedic_save_chart()` which
 * stores in `wp_bccm_astro.traits` the V2-reshaped envelope:
 *   positions / planets — by-name map (sign_en, nakshatra, dignity, house…)
 *   houses              — array of {House, sign_en, sign_vi, degree}
 *   navamsa             — D9 planets by name
 *   lagna               — Ascendant details
 *   dasha               — Vimshottari dasha sequence
 *   yogas               — detected yogas
 *   panchang            — tithi / vara / nakshatra / yoga / karana
 *   vargas              — all divisional charts (D1, D9, D10…)
 *
 * Exposed API:
 *   bccm_vedic_render_natal_report( $astro_row, $coachee )
 *   bccm_vedic_llm_system_prompt()   → string
 *   bccm_vedic_llm_get_sections( $chart_ctx, $name )  → array
 *
 * @package BizCoach_Pro
 * @since   0.37.0
 */

defined( 'ABSPATH' ) || exit;

/* =====================================================================
 * DATA HELPERS
 * =====================================================================*/

if ( ! function_exists( 'bccm_vedic_sign_number_map' ) ) :
/**
 * Map sign English name → 1-based sign number (Rashi number).
 * Handles minor spelling variants from V2 API.
 */
function bccm_vedic_sign_number_map() {
    return array(
        'aries'       => 1,  'mesha'      => 1,
        'taurus'      => 2,  'vrishabha'  => 2,  'vrishaba'  => 2,
        'gemini'      => 3,  'mithuna'    => 3,
        'cancer'      => 4,  'karka'      => 4,  'karkata'   => 4,
        'leo'         => 5,  'simha'      => 5,
        'virgo'       => 6,  'kanya'      => 6,
        'libra'       => 7,  'tula'       => 7,
        'scorpio'     => 8,  'vrishchika' => 8,  'scorpius'  => 8,
        'sagittarius' => 9,  'dhanu'      => 9,  'sagitarius'=> 9,
        'capricorn'   => 10, 'makara'     => 10, 'capricornus'=> 10,
        'aquarius'    => 11, 'kumbha'     => 11,
        'pisces'      => 12, 'meena'      => 12, 'mina'      => 12,
    );
}
endif;

if ( ! function_exists( 'bccm_vedic_sign_en_to_num' ) ) :
function bccm_vedic_sign_en_to_num( $sign_en ) {
    $map = bccm_vedic_sign_number_map();
    return $map[ strtolower( trim( (string) $sign_en ) ) ] ?? 0;
}
endif;

if ( ! function_exists( 'bccm_vedic_enrich_positions' ) ) :
/**
 * Inject `sign_number`, `sign_symbol`, `sign_vi`, `sign_sanskrit` into each
 * planet record so the existing SVG renderer + context builder work correctly.
 */
function bccm_vedic_enrich_positions( array $positions ) {
    $rashi = function_exists( 'bccm_vedic_rashi_signs' ) ? bccm_vedic_rashi_signs() : array();
    foreach ( $positions as $name => &$p ) {
        if ( empty( $p['sign_number'] ) ) {
            $num = bccm_vedic_sign_en_to_num( $p['sign_en'] ?? $p['sign'] ?? '' );
            $p['sign_number'] = $num;
        }
        $num = (int) $p['sign_number'];
        if ( $num >= 1 && $num <= 12 && isset( $rashi[ $num ] ) ) {
            $p['sign_vi']      = $p['sign_vi']      ?: ( $rashi[ $num ]['vi']       ?? '' );
            $p['sign_symbol']  = $p['sign_symbol']  ?: ( $rashi[ $num ]['symbol']   ?? '' );
            $p['sign_sanskrit']= $p['sign_sanskrit']?? ( $rashi[ $num ]['sanskrit'] ?? '' );
        }
    }
    unset( $p );
    return $positions;
}
endif;

if ( ! function_exists( 'bccm_vedic_map_positions_by_name' ) ) :
/**
 * [2026-07-11 Johnny Chu] PHASE-VEDIC-FAA2 — normalize list/map planets to a
 * keyed-by-name map (Ascendant/Sun/Moon/...) for legacy render loops.
 */
function bccm_vedic_map_positions_by_name( array $rows ) {
  $mapped = array();
  foreach ( $rows as $row_key => $row_val ) {
    if ( ! is_array( $row_val ) ) {
      continue;
    }
    $name = (string) ( $row_val['name'] ?? $row_val['planet'] ?? ( is_string( $row_key ) ? $row_key : '' ) );
    if ( $name === '' ) {
      continue;
    }
    $sign_num = (int) ( $row_val['sign_number'] ?? $row_val['sign_num'] ?? $row_val['current_sign'] ?? 0 );
    $norm_deg = (float) ( $row_val['norm_degree'] ?? $row_val['sign_degree'] ?? $row_val['normDegree'] ?? 0 );
    $full_deg = (float) ( $row_val['full_degree'] ?? $row_val['absolute_degree'] ?? $row_val['fullDegree'] ?? 0 );
    $is_retro_raw = $row_val['is_retro'] ?? $row_val['retrograde'] ?? $row_val['isRetro'] ?? false;
    $is_retro = is_string( $is_retro_raw )
      ? in_array( strtolower( trim( $is_retro_raw ) ), array( '1', 'true', 'yes' ), true )
      : ! empty( $is_retro_raw );

    $mapped[ $name ] = array(
      'planet_en'       => $name,
      'sign_en'         => (string) ( $row_val['sign_en'] ?? $row_val['sign'] ?? '' ),
      'sign'            => (string) ( $row_val['sign_en'] ?? $row_val['sign'] ?? '' ),
      'sign_number'     => $sign_num,
      'norm_degree'     => $norm_deg,
      'sign_degree'     => $norm_deg,
      'full_degree'     => $full_deg,
      'absolute_degree' => $full_deg,
      'house'           => (int) ( $row_val['house'] ?? $row_val['house_number'] ?? 0 ),
      'house_number'    => (int) ( $row_val['house'] ?? $row_val['house_number'] ?? 0 ),
      'is_retro'        => $is_retro,
      'retrograde'      => $is_retro,
      'nakshatra'       => (string) ( $row_val['nakshatra'] ?? $row_val['nakshatra_name'] ?? '' ),
      'nakshatra_pada'  => (int) ( $row_val['nakshatra_pada'] ?? 0 ),
      'sign_lord'       => (string) ( $row_val['sign_lord'] ?? $row_val['zodiac_sign_lord'] ?? '' ),
      'dignity'         => (string) ( $row_val['dignity'] ?? '' ),
    );
  }
  return $mapped;
}
endif;

if ( ! function_exists( 'bccm_vedic_resolve_chart_url' ) ) :
/**
 * [2026-07-11 Johnny Chu] PHASE-VEDIC-FAA2 — avoid wrong Western chart image
 * on Vedic page; prefer explicit Vedic chart URL or local _vedic.svg.
 */
function bccm_vedic_resolve_chart_url( array $traits, array $summary, array $astro_row ) {
  $candidates = array(
    (string) ( $traits['chart_url'] ?? '' ),
    (string) ( $summary['chart_url'] ?? '' ),
  );
  foreach ( $candidates as $url ) {
    if ( $url === '' || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
      continue;
    }
    $lower = strtolower( $url );
    if ( strpos( $lower, '_vedic.svg' ) !== false || strpos( $lower, '/vedic' ) !== false ) {
      return $url;
    }
  }

  $coachee_id = (int) ( $astro_row['coachee_id'] ?? 0 );
  if ( $coachee_id > 0 && function_exists( 'wp_upload_dir' ) ) {
    $uploads   = wp_upload_dir();
    $base_dir  = (string) ( $uploads['basedir'] ?? '' );
    $base_url  = (string) ( $uploads['baseurl'] ?? '' );
    $file_name = $coachee_id . '_vedic.svg';
    if ( $base_dir !== '' && $base_url !== '' ) {
      $file_path = trailingslashit( $base_dir ) . 'bizcoach-astro-charts/' . $file_name;
      if ( file_exists( $file_path ) ) {
        return trailingslashit( $base_url ) . 'bizcoach-astro-charts/' . $file_name;
      }
    }
  }

  return '';
}
endif;

if ( ! function_exists( 'bccm_vedic_extract_traits' ) ) :
function bccm_vedic_extract_traits( $astro_row ) {
    $summary = is_array( $astro_row['summary'] ?? null )
        ? $astro_row['summary']
        : ( json_decode( $astro_row['summary'] ?? '{}', true ) ?: array() );
    $traits  = is_array( $astro_row['traits'] ?? null )
        ? $astro_row['traits']
        : ( json_decode( $astro_row['traits']  ?? '{}', true ) ?: array() );

    // [2026-07-11 Johnny Chu] PHASE-VEDIC-FAA2 — accept both list and map shapes.
    $planets = bccm_vedic_map_positions_by_name( (array) ( $traits['planets'] ?? $traits['positions'] ?? array() ) );
    // If the row was saved via v2-bridge, traits['positions'] == traits['planets'].
    // Always enrich with sign_number so SVG + context work.
    $planets = bccm_vedic_enrich_positions( $planets );
    $navamsa = bccm_vedic_enrich_positions( bccm_vedic_map_positions_by_name( (array) ( $traits['navamsa'] ?? array() ) ) );

    return array(
        'summary'       => $summary,
        'traits'        => $traits,
        'positions'     => $planets,   // enriched, by-name map
        'houses'        => (array) ( $traits['houses']       ?? array() ),
      'navamsa'       => $navamsa,
        'vargas'        => (array) ( $traits['vargas']       ?? array() ),
        'lagna'         => (array) ( $traits['lagna']        ?? array() ),
        'dasha'         => (array) ( $traits['dasha']        ?? array() ),
        'yogas'         => (array) ( $traits['yogas']        ?? array() ),
        'panchang'      => (array) ( $traits['panchang']     ?? array() ),
        'shadbala'      => (array) ( $traits['shadbala']     ?? array() ),
        'ashtakavarga'  => (array) ( $traits['ashtakavarga'] ?? array() ),
        'birth'         => (array) ( $traits['birth_data']   ?? array() ),
      'chart_url'     => (string) bccm_vedic_resolve_chart_url( $traits, $summary, is_array( $astro_row ) ? $astro_row : array() ),
        'navamsa_url'   => (string) ( $summary['navamsa_chart_url'] ?? '' ),
    );
}
endif;

/* =====================================================================
 * LLM SYSTEM PROMPT — Vedic / Jyotish expert
 * =====================================================================*/

if ( ! function_exists( 'bccm_vedic_llm_system_prompt' ) ) :
function bccm_vedic_llm_system_prompt() {
    return <<<'PROMPT'
Bạn là một nhà chiêm tinh học Jyotish (chiêm tinh Ấn Độ / Vedic Astrology) chuyên nghiệp với hơn 30 năm kinh nghiệm,
chuyên luận giải lá số Vedic bằng tiếng Việt cho người Việt.

PHẠM VI CHUYÊN MÔN:
- Lagna (Cung Mọc / Ascendant) theo Sidereal Zodiac (Lahiri Ayanamsha)
- 9 Graha: Sun, Moon, Mars, Mercury, Jupiter, Venus, Saturn, Rahu, Ketu
- 12 Rashi (cung hoàng đạo theo sidereal): Mesha → Meena
- 27 Nakshatra (sao Mặt Trăng): Ashwini → Revati
- 12 Bhava (nhà): Tanu, Dhana, Sahaja, Sukha, Putra, Ripu, Kalatra, Ayu, Dharma, Karma, Labha, Vyaya
- Navamsa D9 (biểu đồ hôn nhân & dharma)
- Vimshottari Dasha (chu kỳ vận mệnh 120 năm)
- Yogas (Gajakesari, Pancha Mahapurusha, Dhana Yoga, Raja Yoga, Kemadruma…)
- Shadbala, Ashtakavarga, Panchang
- Rahu-Ketu Axis (karma, nghiệp lực)

PHONG CÁCH VIẾT:
- Chuyên nghiệp, sâu sắc, dùng đúng thuật ngữ Sanskrit/Jyotish nhưng giải thích rõ cho người không chuyên
- Kết hợp triết học Vedanta và quan điểm thực tiễn hiện đại
- Đưa lời khuyên về sự nghiệp, tình duyên, sức khỏe, tâm linh, karma
- Văn phong ấm áp, đồng cảm, truyền cảm hứng

QUY TẮC:
- Viết HOÀN TOÀN bằng tiếng Việt (giữ thuật ngữ Sanskrit trong ngoặc khi cần)
- Sử dụng Markdown: ## tiêu đề, ### phụ, #### nhỏ, **đậm**, *nghiêng*, - danh sách
- KHÔNG lẫn lộn với chiêm tinh phương Tây (Tropical zodiac, Sun signs theo báo chí)
- Lagna (cung Mọc) là trung tâm, KHÔNG phải Sun sign
- Rahu/Ketu là Bắc Giao/Nam Giao của Mặt Trăng — quan trọng về karma
- Viết CHI TIẾT, ĐẦY ĐỦ — KHÔNG tóm tắt, KHÔNG rút gọn
PROMPT;
}
endif;

/* =====================================================================
 * SECTION DEFINITIONS — 9 chương Jyotish
 * =====================================================================*/

if ( ! function_exists( 'bccm_vedic_llm_get_sections' ) ) :
function bccm_vedic_llm_get_sections( $chart_ctx, $name ) {
    $D = "\n\nDỮ LIỆU LÁ SỐ VEDIC:\n" . $chart_ctx;
    return array(
        array(
            'title' => 'Giới Thiệu Jyotish & Tổng Quan Lá Số',
            'icon'  => '🕉️',
            'prompt' => "Viết CHƯƠNG MỞ ĐẦU lá số Vedic/Jyotish của **$name**:\n\n## 🕉️ Jyotish — Ánh Sáng Tri Thức (Vedic Astrology)\n\n### 1. Jyotish là gì?\n- Nguồn gốc từ Vedas, vai trò trong cuộc sống\n- Sự khác biệt với chiêm tinh phương Tây (Tropical vs Sidereal, Lagna vs Sun sign)\n- Lahiri Ayanamsha và tại sao dùng Sidereal\n\n### 2. Tổng quan lá số của $name\n- Lagna (Cung Mọc) và ý nghĩa cốt lõi\n- Chandra (Mặt Trăng) và tâm lý nội tâm\n- Rashi (cung hoàng đạo thực sự theo Vedic)\n- Điểm nổi bật: yogas, hành tinh mạnh, Rahu-Ketu axis\n\n### 3. Tam Quan Trọng: Lagna - Chandra - Surya\n- Ý nghĩa của 3 điểm này trong Jyotish\n\n### 4. Lưu ý\n- Jyotish là hướng dẫn, không phải định mệnh tuyệt đối\n\nViết tối thiểu 2000 từ.$D",
        ),
        array(
            'title' => 'Lagna (Cung Mọc) — Bản Thân & Tính Cách',
            'icon'  => '🌅',
            'prompt' => "Phân tích LAGNA (CUNG MỌC) của **$name**:\n\n## 🌅 Lagna (Ascendant / Cung Mọc)\n\n### 1. Rashi của Lagna — đặc điểm nổi bật\n- Yếu tố (Lửa/Đất/Khí/Nước), chế độ (Cardinal/Fixed/Mutable)\n- Năng lượng, bản ngã, diện mạo của Lagna Rashi này\n\n### 2. Lagna Lord (Hành tinh chủ Lagna)\n- Lagna Lord là ai? Đặt tại Rashi nào? Nhà mấy?\n- Ý nghĩa vị trí Lagna Lord — định hướng cuộc đời\n\n### 3. Hành tinh trong Lagna (Nhà 1)\n- Nếu có hành tinh trong Nhà 1: ảnh hưởng đến ngoại hình, tính cách\n\n### 4. Tính cách, thế mạnh, thách thức của Lagna này\n\n### 5. Atmakaraka (nếu nhận diện được)\n\nViết tối thiểu 2500 từ.$D",
        ),
        array(
            'title' => 'Chandra (Mặt Trăng) & Tâm Lý',
            'icon'  => '🌙',
            'prompt' => "Phân tích CHANDRA (MẶT TRĂNG) và tâm lý của **$name**:\n\n## 🌙 Chandra (Moon / Mặt Trăng)\n\n### 1. Chandra Rashi — tâm lý và cảm xúc\n- Cung Mặt Trăng (Rashi) theo Vedic\n- Tính cách nội tâm, cảm xúc, mẹ, quê hương\n\n### 2. Nakshatra của Chandra (Janma Nakshatra)\n- Tên Nakshatra, ý nghĩa, lord, Pada\n- Ảnh hưởng đến tính cách và Dasha khởi đầu\n\n### 3. Chandra Lagna (Moon as Lagna)\n- Nhà mấy theo Chandra Lagna?\n\n### 4. Kemadruma Yoga / Gajakesari Yoga (nếu có)\n\n### 5. Mối quan hệ với mẹ, gia đình, nhà cửa\n\n### 6. Hướng dẫn tu tập tinh thần cho Chandra Rashi này\n\nViết tối thiểu 2500 từ.$D",
        ),
        array(
            'title' => 'Graha & Bhava — Các Hành Tinh Trong Các Nhà',
            'icon'  => '🪐',
            'prompt' => "Phân tích VỊ TRÍ CÁC HÀNH TINH (Graha) trong 12 NHÀ (Bhava) của **$name**:\n\n## 🪐 Graha trong Bhava (Planets in Houses)\n\nDựa trên dữ liệu vị trí hành tinh trong các nhà (house number).\n\n### Lần lượt phân tích từng hành tinh:\n- **Surya (Sun)** tại Nhà mấy? Rashi nào? Dignity? Retrograde? Ý nghĩa?\n- **Chandra (Moon)** — tương tự\n- **Mangal (Mars)** — cần chú ý Mangal Dosha không?\n- **Budha (Mercury)** \n- **Guru (Jupiter)**\n- **Shukra (Venus)**\n- **Shani (Saturn)** — Sade Sati?\n- **Rahu** — desires, karma, phát triển\n- **Ketu** — moksha, nghiệp quá khứ\n\n### Đặc biệt lưu ý:\n- Hành tinh kết hợp (conjunction) trong cùng nhà\n- Hành tinh debilitated / exalted\n- Hành tinh retrograde (nghịch hành / Vakri)\n\nViết tối thiểu 3000 từ.$D",
        ),
        array(
            'title' => 'Nakshatra — 27 Ngôi Sao Mệnh',
            'icon'  => '⭐',
            'prompt' => "Phân tích NAKSHATRA của **$name**:\n\n## ⭐ Nakshatra (27 Lunar Mansions)\n\n### 1. Janma Nakshatra (Nakshatra Mặt Trăng)\n- Tên đầy đủ, deity, lord, Pada\n- Đặc điểm tính cách, sứ mệnh, con đường tâm linh\n\n### 2. Lagna Nakshatra\n- Nakshatra của Cung Mọc và ý nghĩa\n\n### 3. Surya Nakshatra (Nakshatra Mặt Trời)\n\n### 4. Phân tích Nakshatra của các hành tinh quan trọng khác\n- Liệt kê Nakshatra của các Graha từ dữ liệu\n\n### 5. Nakshatra Dosha / Shanti\n- Có Nakshatra nào cần thực hành Shanti không?\n\n### 6. Phương thuốc và thực hành theo Nakshatra\n- Deity thờ phụng, mantra, ngày thuận\n\nViết tối thiểu 2500 từ.$D",
        ),
        array(
            'title' => 'Vimshottari Dasha — Chu Kỳ Vận Mệnh',
            'icon'  => '⏳',
            'prompt' => "Phân tích VIMSHOTTARI DASHA của **$name**:\n\n## ⏳ Vimshottari Dasha (120-năm Cycle)\n\n### 1. Nguyên lý Dasha\n- Dasha là gì? Tại sao quan trọng trong Jyotish?\n- 9 Graha x thời hạn: Ketu 7 năm → Venus 20 năm → Sun 6 năm…\n\n### 2. Dasha khởi đầu (từ Janma Nakshatra)\n- Dasha đầu tiên của $name bắt đầu từ khi nào?\n\n### 3. Phân tích TỪNG Dasha period theo thứ tự:\n- Dasha lord, thời gian, nhà cai quản, hành tinh trạng thái\n- Thuận hay khó? Dự báo chủ đề lớn của giai đoạn\n\n### 4. Dasha HIỆN TẠI — chi tiết:\n- Mahadasha và Antardasha hiện tại\n- Chủ đề cuộc sống trong giai đoạn này\n- Lời khuyên cụ thể\n\n### 5. Dasha sắp tới — chuẩn bị gì\n\nViết tối thiểu 3000 từ.$D",
        ),
        array(
            'title' => 'Yogas — Các Kết Hợp Đặc Biệt',
            'icon'  => '✨',
            'prompt' => "Phân tích YOGAS của **$name**:\n\n## ✨ Yogas (Kết Hợp Đặc Biệt)\n\n### 1. Yogas là gì trong Jyotish?\n\n### 2. Các Yoga hiện diện trong lá số:\nDựa trên dữ liệu yogas và vị trí hành tinh.\n\n**Raja Yoga** (nếu có) — quyền lực, danh vọng, địa vị xã hội\n**Dhana Yoga** — tài lộc, giàu có\n**Gajakesari Yoga** — Jupiter-Moon: trí tuệ, danh tiếng\n**Pancha Mahapurusha Yoga** (Ruchaka/Bhadra/Hamsa/Malavya/Shasha nếu có)\n**Kemadruma Yoga** — cô đơn, thách thức\n**Kala Sarpa Yoga** — nếu tất cả hành tinh giữa Rahu-Ketu\n**Mangal Dosha** — nếu Mars ở 1/4/7/8/12\n**Neecha Bhanga Raja Yoga** — yếu tố trung hòa debilitation\n\n### 3. Mức độ mạnh yếu của từng Yoga\n\n### 4. Cách phát huy Yogas tốt / Hóa giải Yogas khó\n\nViết tối thiểu 2500 từ.$D",
        ),
        array(
            'title' => 'Sự Nghiệp, Tài Lộc & Tình Duyên theo Jyotish',
            'icon'  => '💼💕',
            'prompt' => "Phân tích SỰ NGHIỆP, TÀI LỘC và TÌNH DUYÊN của **$name** theo Jyotish:\n\n## 💼 Sự Nghiệp & Karma Nghề Nghiệp\n### 1. Nhà 10 (Karma Bhava) — hành tinh, sign, lord\n### 2. Dasamsha D10 (biểu đồ sự nghiệp) nếu có dữ liệu\n### 3. Shani và Guru ảnh hưởng đến sự nghiệp\n### 4. Ngành nghề phù hợp theo Lagna + Nhà 10 + Nhà 6\n\n## 💰 Tài Lộc\n### 5. Nhà 2 (Dhana) và Nhà 11 (Labha) — tài sản và thu nhập\n### 6. Shukra và Guru trong tài lộc\n### 7. Dasha nào thuận cho tài lộc?\n\n## 💕 Tình Duyên & Hôn Nhân\n### 8. Nhà 7 (Kalatra Bhava) — hôn nhân và đối tác\n### 9. Navamsa D9 — lá số hôn nhân\n### 10. Shukra (Venus) và Moon trong tình duyên\n### 11. Thời điểm hôn nhân theo Dasha\n\nViết tối thiểu 3000 từ.$D",
        ),
        array(
            'title' => 'Tổng Kết & Hướng Dẫn Tâm Linh',
            'icon'  => '🕊️',
            'prompt' => "Viết CHƯƠNG KẾT cho **$name**:\n\n## 🕊️ Tổng Kết Lá Số Vedic\n\n### 1. Chân dung Karma & Dharma\n- Karma từ kiếp trước (Ketu placement) và hướng phát triển (Rahu)\n- Dharma cốt lõi của Lagna này\n\n### 2. Điểm mạnh tự nhiên\n- 3-5 ân sủng từ lá số\n\n### 3. Thách thức & Bài học\n- Các Dosha hoặc vị trí khó cần chú ý\n\n### 4. Hướng dẫn tâm linh & tu tập\n- Mantra phù hợp theo Lagna và Graha\n- Deity thờ phụng\n- Gemstone (đá quý) theo Jyotish (với lưu ý cẩn thận)\n- Sewa (phục vụ) và karma yoga\n\n### 5. Thông điệp Jyotish cuối cùng\n- Cuộc đời này để học gì?\n- Cách sống thuận với Dharma\n\nViết tối thiểu 2500 từ.$D",
        ),
    );
}
endif;

/* =====================================================================
 * HTML RENDERER — Vedic natal report page
 * =====================================================================*/

if ( ! function_exists( 'bccm_vedic_render_natal_report' ) ) :
function bccm_vedic_render_natal_report( $astro_row, $coachee ) {
    $d = bccm_vedic_extract_traits( $astro_row );
    $positions  = $d['positions'];
    $houses     = $d['houses'];
    $navamsa    = $d['navamsa'];
    $dasha      = $d['dasha'];
    $yogas      = $d['yogas'];
    $panchang   = $d['panchang'];
    $lagna      = $d['lagna'];
    $vargas     = $d['vargas'];
    $birth      = $d['birth'];
    $chart_url  = $d['chart_url'];

    $rashi       = function_exists( 'bccm_vedic_rashi_signs'    ) ? bccm_vedic_rashi_signs()    : array();
    $planet_vi_map = function_exists( 'bccm_vedic_planet_names_vi' ) ? bccm_vedic_planet_names_vi() : array();
    $house_means = function_exists( 'bccm_vedic_house_meanings_vi') ? bccm_vedic_house_meanings_vi() : array();

    $coachee_id     = (int) ( $coachee['id'] ?? 0 );
    $name_esc       = esc_html( $coachee['full_name'] ?? 'Vedic Report' );

    $dob = '';
    if ( ! empty( $birth['day'] ) && ! empty( $birth['month'] ) && ! empty( $birth['year'] ) ) {
        $dob = sprintf( '%02d/%02d/%04d', $birth['day'], $birth['month'], $birth['year'] );
    } elseif ( ! empty( $coachee['dob'] ) ) {
        $dob = date( 'd/m/Y', strtotime( $coachee['dob'] ) );
    }

    // LLM section scaffolding
    $chart_ctx = function_exists( 'bccm_vedic_build_chart_context' )
        ? bccm_vedic_build_chart_context( $astro_row, $coachee )
        : '';
    $sections  = bccm_vedic_llm_get_sections( $chart_ctx, $coachee['full_name'] ?? 'Người dùng' );
    $section_nonce = wp_create_nonce( 'bccm_llm_section' );
    $public_hash   = (string) ( $GLOBALS['bcpro_public_astro_ctx']['hash'] ?? '' );
    $regenerate    = ! empty( $_GET['regenerate'] ) ? 'true' : 'false';

    $existing_sections = array();
    if ( ! empty( $astro_row['llm_report'] ) ) {
        $rep = json_decode( $astro_row['llm_report'], true );
        if ( is_array( $rep ) && isset( $rep['sections'] ) ) $existing_sections = (array) $rep['sections'];
    }

    // Summary values
    $asc_sign  = $positions['Ascendant']['sign_vi']  ?? $positions['Ascendant']['sign_en']  ?? '';
    $moon_sign = $positions['Moon']['sign_vi']        ?? $positions['Moon']['sign_en']        ?? '';
    $sun_sign  = $positions['Sun']['sign_vi']         ?? $positions['Sun']['sign_en']         ?? '';

    $planet_order_main = array( 'Sun','Moon','Mars','Mercury','Jupiter','Venus','Saturn','Rahu','Ketu' );
    $planet_colors = array(
        'Sun'=>'#dc2626','Moon'=>'#6b7280','Mars'=>'#ef4444','Mercury'=>'#10b981',
        'Jupiter'=>'#f97316','Venus'=>'#ec4899','Saturn'=>'#475569',
        'Rahu'=>'#6366f1','Ketu'=>'#8b5cf6','Ascendant'=>'#7c3aed',
    );

    while ( ob_get_level() > 0 ) @ob_end_clean();
    header( 'Content-Type: text/html; charset=UTF-8' );
    ?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<title>Lá Số Vedic — <?php echo $name_esc; ?></title>
<style>
@page { size: A4; margin: 12mm 10mm; }
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: 'Segoe UI', Tahoma, Arial, sans-serif; font-size: 11px; color: #1a1a2e; line-height: 1.55; background: #f5f3ff; }
.page { max-width: 210mm; margin: 0 auto; background: #fff; }

/* Toolbar */
.toolbar { text-align: center; padding: 12px; background: linear-gradient(135deg,#4c1d95,#5b21b6); border-bottom: 2px solid #7c3aed; }
.toolbar button, .toolbar a { padding: 10px 24px; background: #7c3aed; color: #fff; border: none; border-radius: 8px; font-size: 13px; cursor: pointer; font-weight: 600; text-decoration: none; display: inline-block; margin: 0 4px; }
.toolbar button:hover, .toolbar a:hover { background: #6d28d9; }
.toolbar .btn-regen { background: #f59e0b; }
.toolbar .hint { color: #ddd6fe; font-size: 11px; margin-top: 6px; }

/* Cover */
.cover { background: linear-gradient(135deg,#4c1d95,#1e1b4b); color:#fff; padding: 50px 36px; text-align: center; page-break-after: always; }
.cover h1 { font-size: 30px; font-weight: 800; color: #c4b5fd; margin-bottom: 6px; }
.cover .csub { font-size: 12px; color: #a5b4fc; letter-spacing: 3px; text-transform: uppercase; margin-bottom: 28px; }
.cover .cname { font-size: 26px; font-weight: 700; color: #fff; }
.cover .cmeta { font-size: 13px; color: #c4b5fd; margin: 5px 0; }
.cover .cbig3 { margin: 24px auto; max-width: 400px; background: rgba(255,255,255,.07); border-radius: 14px; padding: 16px; }
.cover .cbig3 table { width: 100%; }
.cover .cbig3 td { padding: 5px 10px; font-size: 12px; color: #e0d7ff; }
.cover .cbig3 td:first-child { color: #c4b5fd; font-weight: 700; text-align: left; }
.cover .cbig3 td:last-child { color: #fff; font-weight: 800; font-size: 14px; }
.cover .cbrand { margin-top: 24px; color: #a5b4fc; font-size: 10px; border-top: 1px solid rgba(255,255,255,.1); padding-top: 12px; }

/* Data sections */
.data-section { padding: 10px 20px; }
.section { margin-top: 14px; page-break-inside: avoid; }
.section h2 { font-size: 14px; font-weight: 700; color: #5b21b6; border-bottom: 2px solid #ede9fe; padding-bottom: 4px; margin-bottom: 8px; }
table { width: 100%; border-collapse: collapse; margin-bottom: 6px; font-size: 10.5px; }
th { background: #f5f3ff; color: #5b21b6; font-weight: 600; text-align: center; padding: 5px 6px; border: 1px solid #ddd6fe; }
td { padding: 6px; border: 1px solid #ede9fe; vertical-align: middle; }

/* Chart image */
.chart-img { width: 100%; max-width: 600px; display: block; margin: 10px auto; border-radius: 12px; box-shadow: 0 4px 20px rgba(124,58,237,.15); }
.chart-svg-wrap { overflow: hidden; border-radius: 12px; border: 1px solid #ddd6fe; background: #faf5ff; padding: 8px; margin: 10px 0; }
.chart-svg-wrap svg { max-width: 100%; display: block; margin: auto; }

/* Planet rows */
.planet-retro { color: #dc2626; font-weight: 700; font-size: 9px; }
.dignity-badge { display: inline-block; padding: 1px 5px; border-radius: 4px; font-size: 9px; font-weight: 600; }
.dignity-exalted    { background: #d1fae5; color: #065f46; }
.dignity-debilitated{ background: #fee2e2; color: #991b1b; }
.dignity-own        { background: #dbeafe; color: #1e40af; }
.dignity-mfr        { background: #fef3c7; color: #92400e; }

/* Dasha table */
.dasha-current { background: #f5f3ff !important; font-weight: 700; }
.dasha-past    { opacity: .55; }

/* Yoga chips */
.yoga-chip { display: inline-block; padding: 3px 9px; border-radius: 10px; font-size: 10px; font-weight: 600; margin: 2px; }
.yoga-good  { background: #d1fae5; color: #065f46; }
.yoga-caution { background: #fee2e2; color: #991b1b; }
.yoga-neutral { background: #f3f4f6; color: #374151; }

/* Panchang grid */
.panchang-grid { display: grid; grid-template-columns: repeat(3,1fr); gap: 6px; margin: 8px 0; }
.panch-cell { background: #f5f3ff; border-radius: 8px; padding: 8px; text-align: center; }
.panch-cell .pk { font-size: 9px; color: #7c3aed; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; }
.panch-cell .pv { font-size: 12px; font-weight: 700; color: #1a1a2e; margin-top: 3px; }

/* Progress bar */
.progress-bar-wrap { margin: 10px 20px; background: #e5e7eb; border-radius: 6px; height: 8px; overflow: hidden; }
.progress-bar-fill  { height: 100%; background: linear-gradient(90deg,#7c3aed,#c4b5fd); width: 0%; transition: width .5s ease; border-radius: 6px; }
.progress-status { text-align: center; font-size: 12px; color: #7c3aed; padding: 6px 0 0; font-weight: 600; }

/* AI Chapters */
.ai-chapter { padding: 20px; page-break-before: always; border-top: 3px solid #7c3aed; margin-top: 20px; }
.ai-ch-header { display: flex; align-items: center; gap: 10px; margin-bottom: 14px; }
.ai-ch-header .ch-icon  { font-size: 26px; }
.ai-ch-header .ch-num   { font-size: 10px; color: #7c3aed; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; }
.ai-ch-header .ch-title { font-size: 18px; font-weight: 700; color: #4c1d95; }
.ai-content { font-size: 12px; line-height: 1.8; color: #334155; }
.ai-content h4.ai-h4 { font-size: 15px; font-weight: 700; color: #5b21b6; margin: 20px 0 8px; padding-bottom: 4px; border-bottom: 2px solid #ede9fe; }
.ai-content h5.ai-h5 { font-size: 13px; font-weight: 600; color: #7c3aed; margin: 14px 0 6px; }
.ai-content h6.ai-h6 { font-size: 12px; font-weight: 600; color: #6d28d9; margin: 10px 0 4px; }
.ai-content p  { margin: 0 0 10px; text-align: justify; }
.ai-content ul.ai-list, .ai-content ol.ai-list { margin: 8px 0 12px 18px; }
.ai-content li { margin-bottom: 4px; }
.ai-content strong { color: #4c1d95; }
.ai-content em     { color: #7c3aed; font-style: italic; }
.ai-content blockquote { margin: 12px 0; padding: 10px 16px; background: #f5f3ff; border-left: 4px solid #7c3aed; border-radius: 0 8px 8px 0; color: #4c1d95; font-style: italic; }
.ai-loading { text-align: center; padding: 30px; color: #7c3aed; }
.ai-loading .spinner { display: inline-block; width: 28px; height: 28px; border: 3px solid #ede9fe; border-top-color: #7c3aed; border-radius: 50%; animation: spin .7s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
.ai-error { background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 12px 16px; margin: 10px 0; color: #991b1b; font-size: 11px; }

.footer { margin-top: 16px; text-align: center; color: #9ca3af; font-size: 9px; border-top: 1px solid #e5e7eb; padding-top: 8px; }
@media print {
  body { background: #fff; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
  .toolbar, .no-print, .progress-bar-wrap, .progress-status { display: none !important; }
  .ai-loading { display: none !important; }
  .page { max-width: none; box-shadow: none; }
}
</style>
</head>
<body>

<div class="toolbar no-print">
  <button onclick="window.print()" id="btn-print" <?php echo ( count( $existing_sections ) === count( $sections ) && empty( $_GET['regenerate'] ) ) ? '' : 'disabled'; ?>>🖨️ In / Lưu PDF</button>
  <?php
  if ( $public_hash !== '' && class_exists( 'BizCoach_Pro_Astro_Public_Router' ) ) {
      $regen_url = BizCoach_Pro_Astro_Public_Router::get_public_url( $coachee_id, 'vedic', true );
  } else {
      $regen_url = admin_url( 'admin-ajax.php?action=bccm_natal_report_full&coachee_id=' . $coachee_id . '&chart_type=vedic&regenerate=1&_wpnonce=' . wp_create_nonce( 'bccm_natal_report_full' ) );
  }
  ?>
  <a href="<?php echo esc_url( $regen_url ); ?>" class="btn-regen">🔄 Tạo lại AI</a>
  <div class="hint">Lá số Vedic/Jyotish (Sidereal — Lahiri Ayanamsha) — Luận giải bằng AI Jyotish.</div>
</div>

<div class="progress-bar-wrap no-print" id="ai-progress-wrap" <?php echo ( count( $existing_sections ) === count( $sections ) && empty( $_GET['regenerate'] ) ) ? 'style="display:none"' : ''; ?>>
  <div class="progress-bar-fill" id="ai-progress-fill"></div>
</div>
<div class="progress-status no-print" id="ai-progress-text" <?php echo ( count( $existing_sections ) === count( $sections ) && empty( $_GET['regenerate'] ) ) ? 'style="display:none"' : ''; ?>>
  ⏳ Đang chuẩn bị luận giải Jyotish…
</div>

<div class="page">

<!-- ═══════════ COVER ═══════════ -->
<div class="cover">
  <div style="font-size:48px;margin-bottom:10px">🕉️</div>
  <h1>Lá Số Vedic / Jyotish</h1>
  <div class="csub">Vedic Natal Chart — Lahiri Ayanamsha (Sidereal)</div>
  <div class="cname"><?php echo $name_esc; ?></div>
  <div class="cmeta">📅 <?php echo esc_html( $dob ); ?></div>
  <div class="cmeta">🕐 <?php echo esc_html( $astro_row['birth_time'] ?? '' ); ?> — 📍 <?php echo esc_html( $astro_row['birth_place'] ?? '' ); ?></div>

  <div class="cbig3">
    <table>
      <tr><td>🌅 Lagna (Cung Mọc)</td><td><?php echo esc_html( $asc_sign ?: '—' ); ?></td></tr>
      <tr><td>☽ Chandra Rashi</td><td><?php echo esc_html( $moon_sign ?: '—' ); ?></td></tr>
      <tr><td>☉ Surya Rashi</td><td><?php echo esc_html( $sun_sign  ?: '—' ); ?></td></tr>
    </table>
  </div>

  <div class="cbrand">BizCoach Pro — Vedic AI Report · Jyotish | <?php echo esc_html( current_time( 'Y-m-d' ) ); ?></div>
</div>

<!-- ═══════════ DATA SECTIONS ═══════════ -->
<div class="data-section">

  <!-- ── Chart Image ── -->
  <?php if ( $chart_url !== '' ): ?>
  <div class="section">
    <h2>🗺️ Vedic Natal Chart (North Indian Style)</h2>
    <?php
    // Prefer inline SVG stored in traits; fallback to chart_url image.
    $inline_svg = (string) ( $d['traits']['chart_svg'] ?? '' );
    if ( $inline_svg !== '' && strpos( $inline_svg, '<svg' ) !== false ) {
        echo '<div class="chart-svg-wrap">' . $inline_svg . '</div>';
    } elseif ( filter_var( $chart_url, FILTER_VALIDATE_URL ) ) {
        $ext = strtolower( pathinfo( $chart_url, PATHINFO_EXTENSION ) );
        if ( $ext === 'svg' ) {
            echo '<div class="chart-svg-wrap"><img src="' . esc_url( $chart_url ) . '" style="max-width:100%" /></div>';
        } else {
            echo '<img src="' . esc_url( $chart_url ) . '" class="chart-img" alt="Vedic Chart" />';
        }
    }
    ?>
  </div>
  <?php endif; ?>

  <!-- ── Fallback: PHP North Indian SVG if no saved chart ── -->
  <?php if ( $chart_url === '' && ! empty( $positions ) && function_exists( 'bccm_build_vedic_north_indian_chart_svg' ) ): ?>
  <div class="section">
    <h2>🗺️ Vedic Natal Chart (North Indian Style)</h2>
    <div class="chart-svg-wrap">
      <?php
      echo bccm_build_vedic_north_indian_chart_svg( $positions, $coachee['full_name'] ?? '', array(
          'dob'   => $dob,
          'time'  => $astro_row['birth_time']  ?? '',
          'place' => $astro_row['birth_place'] ?? '',
      ) );
      ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── Graha Positions Table ── -->
  <?php if ( ! empty( $positions ) ): ?>
  <div class="section">
    <h2>🪐 Vị Trí Các Hành Tinh (Graha) — Rashi Chart (Sidereal)</h2>
    <table>
      <thead>
        <tr>
          <th>Hành tinh (Graha)</th>
          <th>Rashi (Cung)</th>
          <th>Độ trong cung</th>
          <th>Nhà (Bhava)</th>
          <th>Nakshatra</th>
          <th>Pada</th>
          <th>Chủ cung</th>
          <th>Dignity</th>
          <th>Nghịch</th>
        </tr>
      </thead>
      <tbody>
      <?php
      $planet_order_all = array( 'Ascendant', 'Sun', 'Moon', 'Mars', 'Mercury', 'Jupiter', 'Venus', 'Saturn', 'Rahu', 'Ketu' );
      foreach ( $planet_order_all as $pn ):
          if ( ! isset( $positions[ $pn ] ) ) continue;
          $p     = $positions[ $pn ];
          $color = $planet_colors[ $pn ] ?? '#1a1a2e';
          $sign_vi_text = $p['sign_vi'] ?: ( $rashi[ $p['sign_number'] ?? 0 ]['vi'] ?? $p['sign_en'] );
          $sign_sym     = $p['sign_symbol'] ?? ( $rashi[ $p['sign_number'] ?? 0 ]['symbol'] ?? '' );
          $deg = (float) ( $p['norm_degree'] ?? $p['sign_degree'] ?? 0 );
          $deg_fmt = sprintf( '%d°%02d\'', floor( $deg ), floor( ( $deg - floor( $deg ) ) * 60 ) );
          $retro   = ! empty( $p['is_retro'] ) || $p['retrograde'] ?? false;
          $dignity = (string) ( $p['dignity'] ?? '' );
          $dign_class = '';
          if ( stripos( $dignity, 'exalted' ) !== false )     $dign_class = 'dignity-exalted';
          elseif ( stripos( $dignity, 'debilitated' ) !== false ) $dign_class = 'dignity-debilitated';
          elseif ( stripos( $dignity, 'own' ) !== false || stripos( $dignity, 'domicile' ) !== false ) $dign_class = 'dignity-own';
          elseif ( $dignity !== '' )                            $dign_class = 'dignity-mfr';
          $house_num = (int) ( $p['house'] ?? $p['house_number'] ?? 0 );
          $pvi = $planet_vi_map[ $pn ] ?? $pn;
      ?>
      <tr>
        <td><strong style="color:<?php echo esc_attr( $color ); ?>"><?php echo esc_html( $pvi ); ?></strong></td>
        <td><?php echo esc_html( "$sign_sym $sign_vi_text" ); ?></td>
        <td><?php echo esc_html( $deg_fmt ); ?></td>
        <td><?php echo $house_num > 0 ? "Nhà $house_num" : '—'; ?></td>
        <td><?php echo esc_html( $p['nakshatra'] ?? '—' ); ?></td>
        <td><?php echo (int) ( $p['nakshatra_pada'] ?? 0 ) ?: '—'; ?></td>
        <td><?php echo esc_html( $p['sign_lord'] ?? '—' ); ?></td>
        <td><?php if ( $dign_class && $dignity ) echo '<span class="dignity-badge ' . $dign_class . '">' . esc_html( $dignity ) . '</span>'; else echo '—'; ?></td>
        <td><?php echo $retro ? '<span class="planet-retro">℞</span>' : '—'; ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- ── Houses (Bhava) ── -->
  <?php if ( ! empty( $houses ) ): ?>
  <div class="section">
    <h2>🏠 12 Nhà (Bhava)</h2>
    <table>
      <thead><tr><th>Nhà</th><th>Ý nghĩa</th><th>Rashi trong nhà</th><th>Chủ nhà</th><th>Độ</th></tr></thead>
      <tbody>
      <?php foreach ( $houses as $h ):
          $hn  = (int) ( $h['House'] ?? $h['house'] ?? 0 );
          if ( $hn < 1 || $hn > 12 ) continue;
          $sv  = (string) ( $h['sign_vi']  ?? '' );
          $se  = (string) ( $h['sign_en']  ?? $h['sign'] ?? '' );
          $sl  = (string) ( $h['sign_lord'] ?? '' );
          $deg = (float)  ( $h['degree'] ?? $h['norm_degree'] ?? 0 );
          $deg_f = sprintf( '%d°%02d\'', floor( $deg ), floor( ( $deg - floor( $deg ) ) * 60 ) );
          $meaning = $house_means[ $hn ] ?? "Nhà $hn";
      ?>
      <tr>
        <td style="font-weight:700;text-align:center;color:#5b21b6">H<?php echo $hn; ?></td>
        <td style="font-size:9.5px"><?php echo esc_html( $meaning ); ?></td>
        <td><?php echo esc_html( $sv ?: $se ); ?></td>
        <td><?php echo esc_html( $sl ?: '—' ); ?></td>
        <td><?php echo esc_html( $deg_f ); ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- ── Navamsa D9 ── -->
  <?php if ( ! empty( $navamsa ) ): ?>
  <div class="section">
    <h2>💍 Navamsa D9 — Biểu Đồ Hôn Nhân & Dharma</h2>
    <table>
      <thead><tr><th>Hành tinh</th><th>Rashi D9</th><th>Độ</th></tr></thead>
      <tbody>
      <?php
      $nav_enriched = bccm_vedic_enrich_positions( (array) $navamsa );
      foreach ( $planet_order_all as $pn ):
          if ( ! isset( $nav_enriched[ $pn ] ) ) continue;
          $np = $nav_enriched[ $pn ];
          $sign_text = ( $np['sign_vi'] ?: ( $rashi[ $np['sign_number'] ?? 0 ]['vi'] ?? $np['sign_en'] ?? '' ) );
          $sign_s    = $np['sign_symbol'] ?? ( $rashi[ $np['sign_number'] ?? 0 ]['symbol'] ?? '' );
          $deg_f = '';
          if ( ! empty( $np['norm_degree'] ?? $np['sign_degree'] ) ) {
              $deg = (float) ( $np['norm_degree'] ?? $np['sign_degree'] );
              $deg_f = sprintf( '%d°%02d\'', floor( $deg ), floor( ( $deg - floor( $deg ) ) * 60 ) );
          }
      ?>
      <tr>
        <td><strong style="color:<?php echo esc_attr( $planet_colors[$pn] ?? '#1a1a2e' ); ?>"><?php echo esc_html( $planet_vi_map[$pn] ?? $pn ); ?></strong></td>
        <td><?php echo esc_html( "$sign_s $sign_text" ); ?></td>
        <td><?php echo esc_html( $deg_f ?: '—' ); ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- ── Vimshottari Dasha ── -->
  <?php if ( ! empty( $dasha ) ):
    // Support multiple shapes: array of periods, or keyed object.
    $dasha_seq = array();
    if ( isset( $dasha['dasha_sequence'] ) ) {
        $dasha_seq = (array) $dasha['dasha_sequence'];
    } elseif ( isset( $dasha['maha_dasha'] ) ) {
        $dasha_seq = is_array( $dasha['maha_dasha'] ) ? $dasha['maha_dasha'] : array();
    } elseif ( isset( $dasha['vimshottari'] ) && is_array( $dasha['vimshottari'] ) ) {
      $_vim = (array) $dasha['vimshottari'];
      if ( isset( $_vim['maha'] ) && is_array( $_vim['maha'] ) ) {
        $dasha_seq = (array) $_vim['maha'];
      } elseif ( isset( $_vim['mahadasha'] ) && is_array( $_vim['mahadasha'] ) ) {
        $dasha_seq = (array) $_vim['mahadasha'];
      } elseif ( isset( $_vim['maha_dasha'] ) && is_array( $_vim['maha_dasha'] ) ) {
        $dasha_seq = (array) $_vim['maha_dasha'];
      }
    } elseif ( isset( $dasha['maha'] ) && is_array( $dasha['maha'] ) ) {
      $dasha_seq = (array) $dasha['maha'];
    } elseif ( isset( $dasha[0] ) ) {
        $dasha_seq = $dasha;
    }
    // Some APIs return the list in a nested wrapper.
    if ( empty( $dasha_seq ) && ! empty( $dasha ) ) {
        foreach ( $dasha as $v ) { if ( is_array( $v ) && isset( $v['name'] ) ) { $dasha_seq[] = $v; } }
    }
    if ( ! empty( $dasha_seq ) ):
    $today_year = (int) date( 'Y' );
    $active_maha  = (string) ( $dasha['active_periods']['Mahadasha']['lord']
        ?? $dasha['active_periods']['mahadasha']['lord']
        ?? $dasha['active_periods']['mahadasha']['name']
        ?? $dasha['current']['maha']['lord']
        ?? '' );
    $active_antar = (string) ( $dasha['active_periods']['Antardasha']['lord']
        ?? $dasha['active_periods']['antardasha']['lord']
        ?? $dasha['active_periods']['antardasha']['name']
        ?? $dasha['current']['antar']['lord']
        ?? '' );
    $active_start = (string) ( $dasha['active_periods']['Mahadasha']['start_date']
        ?? $dasha['active_periods']['mahadasha']['start_date']
        ?? '' );
    $active_end   = (string) ( $dasha['active_periods']['Mahadasha']['end_date']
        ?? $dasha['active_periods']['mahadasha']['end_date']
        ?? '' );
  ?>
  <div class="section">
    <h2>⏳ Vimshottari Dasha — Chu Kỳ Vận Mệnh</h2>
    <?php if ( $active_maha !== '' || $active_antar !== '' ): ?>
    <p style="font-size:10.5px;margin:0 0 8px;color:#5b21b6">
      Hiện tại: <strong><?php echo esc_html( $active_maha !== '' ? $active_maha : '—' ); ?></strong>
      <?php if ( $active_antar !== '' ): ?> → <strong><?php echo esc_html( $active_antar ); ?></strong><?php endif; ?>
      <?php if ( $active_start !== '' || $active_end !== '' ): ?>
        (<?php echo esc_html( $active_start ?: '?' ); ?> → <?php echo esc_html( $active_end ?: '?' ); ?>)
      <?php endif; ?>
    </p>
    <?php endif; ?>
    <table>
      <thead><tr><th>#</th><th>Mahadasha</th><th>Bắt đầu</th><th>Kết thúc</th><th>Năm</th><th>Ghi chú</th></tr></thead>
      <tbody>
      <?php foreach ( $dasha_seq as $i => $dp ):
          $lord  = (string) ( $dp['name'] ?? $dp['lord'] ?? $dp['dasha_lord'] ?? "D" . ($i+1) );
          $start = (string) ( $dp['start_date'] ?? $dp['start'] ?? '' );
          $end   = (string) ( $dp['end_date']   ?? $dp['end']   ?? '' );
          $years = (int)    ( $dp['years']       ?? $dp['duration'] ?? 0 );
          $start_y = (int) substr( $start, 0, 4 );
          $end_y   = (int) substr( $end,   0, 4 );
          $is_cur  = $start_y > 0 && $end_y > 0 && $today_year >= $start_y && $today_year <= $end_y;
          $is_past = $end_y > 0 && $today_year > $end_y;
          $row_class = $is_cur ? 'dasha-current' : ( $is_past ? 'dasha-past' : '' );
          $color = $planet_colors[ $lord ] ?? '#1a1a2e';
      ?>
      <tr class="<?php echo $row_class; ?>">
        <td style="text-align:center"><?php echo $i + 1; ?></td>
        <td style="font-weight:700;color:<?php echo esc_attr($color); ?>"><?php echo esc_html( $planet_vi_map[$lord] ?? $lord ); ?></td>
        <td><?php echo esc_html( $start ?: '—' ); ?></td>
        <td><?php echo esc_html( $end   ?: '—' ); ?></td>
        <td><?php echo $years > 0 ? $years . ' năm' : '—'; ?></td>
        <td><?php echo $is_cur ? '⬅ Hiện tại' : ( $is_past ? '✓ Đã qua' : '' ); ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; endif; ?>

  <!-- ── Yogas ── -->
  <?php if ( ! empty( $yogas ) ): ?>
  <div class="section">
    <h2>✨ Yogas (Kết Hợp Đặc Biệt)</h2>
    <div style="margin:6px 0">
    <?php foreach ( $yogas as $y ):
        $yname = (string) ( $y['name'] ?? $y['yoga'] ?? (is_string($y) ? $y : '') );
        $ytype = strtolower( (string) ( $y['type'] ?? 'neutral' ) );
        $chip_class = 'yoga-neutral';
        if ( in_array( $ytype, array('good','benefic','raja','dhana'), true ) ) $chip_class = 'yoga-good';
        elseif ( in_array( $ytype, array('bad','malefic','dosha','caution'), true ) ) $chip_class = 'yoga-caution';
        if ( $yname === '' ) continue;
    ?>
    <span class="yoga-chip <?php echo $chip_class; ?>"><?php echo esc_html( $yname ); ?></span>
    <?php endforeach; ?>
    </div>
    <?php
    // Show descriptions if available.
    foreach ( $yogas as $y ):
        $yname = (string) ( $y['name'] ?? $y['yoga'] ?? '' );
        $desc  = (string) ( $y['description'] ?? $y['desc'] ?? '' );
        if ( $yname !== '' && $desc !== '' ):
    ?>
    <p style="font-size:10.5px;margin:4px 0"><strong><?php echo esc_html( $yname ); ?></strong> — <?php echo esc_html( $desc ); ?></p>
    <?php endif; endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- ── Panchang ── -->
  <?php if ( ! empty( $panchang ) ): ?>
  <div class="section">
    <h2>📅 Panchang (Lịch Vedic ngày sinh)</h2>
    <div class="panchang-grid">
    <?php
    $panchang_labels = array(
        'tithi'      => 'Tithi',    'vara'      => 'Vara (Ngày)',
        'nakshatra'  => 'Nakshatra','yoga'      => 'Yoga',
        'karana'     => 'Karana',   'paksha'    => 'Paksha',
        'sunrise'    => 'Bình minh','sunset'    => 'Hoàng hôn',
        'moon_sign'  => 'Chandra',  'sun_sign'  => 'Surya',
        'ritu'       => 'Ritu',     'samvat'    => 'Samvat',
    );
    foreach ( $panchang_labels as $k => $lbl ):
        if ( ! isset( $panchang[ $k ] ) ) continue;
        $val = (string) ( is_array( $panchang[$k] ) ? ( $panchang[$k]['name'] ?? wp_json_encode($panchang[$k]) ) : $panchang[$k] );
    ?>
    <div class="panch-cell">
      <div class="pk"><?php echo esc_html( $lbl ); ?></div>
      <div class="pv"><?php echo esc_html( $val ); ?></div>
    </div>
    <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

</div><!-- end .data-section -->

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

<div class="footer">© BizCoach Pro — Vedic AI Report · Jyotish | Lahiri Ayanamsha | Generated <?php echo esc_html( current_time( 'Y-m-d H:i' ) ); ?></div>

</div><!-- end .page -->

<script>
(function(){
  const totalSections = <?php echo (int) count( $sections ); ?>;
  const coacheeId     = <?php echo (int) $coachee_id; ?>;
  const chartType     = 'vedic';
  const sectionNonce  = <?php echo wp_json_encode( $section_nonce ); ?>;
  const publicHash    = <?php echo wp_json_encode( $public_hash ); ?>;
  const regenerate    = <?php echo $regenerate; ?>;
  const existing      = <?php echo wp_json_encode( array_keys( array_filter( $existing_sections, 'strlen' ) ) ); ?>;
  const ajaxUrl       = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;

  const fill = document.getElementById('ai-progress-fill');
  const txt  = document.getElementById('ai-progress-text');
  let done   = regenerate ? 0 : existing.length;

  function progress(){
    const pct = Math.round( (done / totalSections) * 100 );
    if ( fill ) fill.style.width = pct + '%';
    if ( txt  ) txt.textContent = '⏳ Đã hoàn thành ' + done + '/' + totalSections + ' chương (' + pct + '%)';
    if ( done >= totalSections ) {
      if ( txt  ) txt.textContent = '✅ Báo cáo Jyotish đã sẵn sàng!';
      const btn = document.getElementById('btn-print');
      if ( btn ) btn.disabled = false;
    }
  }
  progress();
  async function loadOne(idx){
    const el = document.getElementById('ai-content-' + idx);
    if (!el) return;
    const params = new URLSearchParams({
      action:     'bccm_llm_section',
      coachee_id: coacheeId,
      section:    idx,
      chart_type: chartType,
      regenerate: regenerate ? '1' : '0',
    });
    if (publicHash) params.set('hash', publicHash);
    else            params.set('_wpnonce', sectionNonce);
    try {
      const res = await fetch(ajaxUrl + '?' + params.toString(), {credentials:'same-origin'});
      const j   = await res.json();
      if (j && j.success && j.data && j.data.html) {
        el.innerHTML = j.data.html;
      } else {
        el.innerHTML = '<div class="ai-error">❌ Lỗi chương ' + (idx+1) + ': ' + ((j&&j.data&&j.data.error)||'unknown') + '</div>';
      }
    } catch(e) {
      el.innerHTML = '<div class="ai-error">❌ Network error: ' + e.message + '</div>';
    }
    done++;
    progress();
  }
  (async function run(){
    for (let i = 0; i < totalSections; i++){
      if (!regenerate && (existing.indexOf(String(i)) !== -1 || existing.indexOf(i) !== -1)) continue;
      await loadOne(i);
    }
  })();
})();
</script>

</body>
</html>
    <?php
}
endif;
