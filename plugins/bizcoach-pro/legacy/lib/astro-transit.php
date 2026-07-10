<?php
/**
 * BizCoach Map – Astrology Transit Engine
 *
 * Tính toán dịch chuyển sao (transit) bằng cách gọi API với ngày hiện tại
 * hoặc ngày tương lai, rồi so sánh với bản đồ sao gốc (natal chart).
 *
 * Dùng cho AI Agent: khi user hỏi về tương lai (tuần tới, tháng tới, năm tới...),
 * hệ thống sẽ tự động:
 *   1. Detect intent transit từ message
 *   2. Fetch vị trí sao transit cho khoảng thời gian tương ứng
 *   3. So sánh transit vs natal → tìm aspect quan trọng
 *   4. Build context string → inject vào system prompt cho AI
 *
 * @package BizCoach_Map
 * @since   0.1.0.20
 * @see     https://freeastrologyapi.com/api-docs/western-astrology-api-docs
 */
if (!defined('ABSPATH')) exit;

/* =====================================================================
 * INTENT DETECTION: Phát hiện user hỏi về transit/tương lai
 * =====================================================================*/

/**
 * Extract life-topic slugs from a lowercase message string.
 * [2026-07-04 Johnny Chu] PHASE-ASTRO-WORKFLOW — used by bccm_transit_detect_intent + action.run_astro.
 *
 * @param  string  $msg  mb_strtolower()'d message text
 * @return string[]  Subset of: finance, career, love, health, study, family
 */
if ( ! function_exists( '_bccm_detect_astro_topics' ) ) {
    function _bccm_detect_astro_topics( string $msg ): array {
        $map = array(
            'finance' => array( 'tài chính', 'tiền', 'thu nhập', 'đầu tư', 'kinh tế', 'lương', 'kinh doanh', 'tài sản' ),
            'career'  => array( 'sự nghiệp', 'công việc', 'nghề nghiệp', 'thăng tiến', 'career', 'công ty', 'dự án' ),
            'love'    => array( 'tình cảm', 'tình yêu', 'hôn nhân', 'quan hệ', 'bạn đời', 'crush', 'tình duyên', 'yêu đương' ),
            'health'  => array( 'sức khỏe', 'bệnh', 'thể chất', 'tinh thần', 'health', 'năng lượng cơ thể' ),
            'study'   => array( 'học tập', 'thi cử', 'du học', 'bằng cấp', 'học hành', 'thi' ),
            'family'  => array( 'gia đình', 'con cái', 'bố mẹ', 'anh chị em', 'family' ),
        );
        $found = array();
        foreach ( $map as $slug => $keywords ) {
            foreach ( $keywords as $kw ) {
                if ( mb_strpos( $msg, $kw ) !== false ) {
                    $found[] = $slug;
                    break;
                }
            }
        }
        return $found;
    }
}

/**
 * Detect transit-related intent from user message
 *
 * Returns time range if detected, null otherwise.
 *
 * @param string $message  User message text
 * @return array|null  ['period' => 'week|month|year|5year|custom', 'days' => int, 'label' => string,
 *                         'start_offset' => int, 'topics' => string[]]
 */
function bccm_transit_detect_intent($message) {
    $msg = mb_strtolower(trim($message));

    // ── Patterns: tuần tới / tuần này / 7 ngày tới ──
    $week_patterns = [
        'tuần tới', 'tuần này', 'tuần sau', '7 ngày tới', 'bảy ngày tới',
        'week ahead', 'next week', 'this week', 'coming week',
        'trong tuần', 'cuối tuần', 'tuần kế',
    ];

    // ── Patterns: tháng tới / tháng này / 30 ngày tới ──
    $month_patterns = [
        'tháng tới', 'tháng này', 'tháng sau', '30 ngày tới', 'ba mươi ngày',
        'next month', 'this month', 'coming month', 'trong tháng',
    ];

    // ── Patterns: năm tới / năm nay / 1 năm tới ──
    $year_patterns = [
        'năm tới', 'năm nay', 'năm sau', '1 năm tới', 'một năm tới',
        'next year', 'this year', 'coming year', 'trong năm',
        '12 tháng tới', 'mười hai tháng',
    ];

    // ── Patterns: 5 năm tới ──
    $five_year_patterns = [
        '5 năm tới', 'năm năm tới', '5 năm', 'five years', 'next 5 years',
        '3 năm tới', 'ba năm tới', '10 năm tới', 'mười năm tới',
    ];

    // ── Patterns: ngày mai / hôm nay / ngày tới ──
    $day_patterns = [
        'ngày mai', 'hôm nay', 'ngày tới', 'ngày kế', 'tomorrow', 'today',
        '24 giờ tới', 'sáng mai', 'chiều mai', 'tối mai', 'đêm nay',
    ];

    // ── Astrology-specific triggers (chỉ trigger khi kết hợp với time) ──
    $astro_triggers = [
        'vận mệnh', 'vận hạn', 'transit', 'dịch chuyển sao', 'vận thế',
        'sao chiếu', 'cung hoàng đạo', 'chiêm tinh', 'horoscope',
        'dự báo', 'dự đoán', 'forecast', 'prediction',
        'tử vi', 'bói', 'xem sao', 'xem vận',
        'năng lượng', 'energy', 'ảnh hưởng sao', 'planetary',
        'thuận lợi', 'bất lợi', 'may mắn', 'thách thức',
        'thời điểm tốt', 'thời điểm xấu', 'timing',
        'tương lai', 'sắp tới', 'upcoming', 'ahead',
        'xu hướng', 'trend', 'tendance',
        // Tarot / oracle card triggers
        'tarot', 'lá bài', 'bài tarot', 'oracle', 'rút bài', 'bói bài',
        'ý nghĩa bài', 'ý nghĩa lá', 'giải bài', 'đọc bài',
    ];

    // ── Life topic triggers: khi kết hợp với time → cũng trigger transit ──
    // (user hỏi "tài chính tuần tới" = muốn dự báo chiêm tinh)
    $life_topic_triggers = [
        'tài chính', 'tiền', 'thu nhập', 'đầu tư', 'kinh doanh', 'lương',
        'sự nghiệp', 'công việc', 'nghề nghiệp', 'thăng tiến', 'career',
        'tình cảm', 'tình yêu', 'hôn nhân', 'quan hệ', 'bạn đời', 'crush',
        'sức khỏe', 'bệnh', 'thể chất', 'tinh thần', 'health',
        'học tập', 'thi cử', 'du học', 'bằng cấp',
        'gia đình', 'con cái', 'bố mẹ', 'anh chị em',
        'vận', 'mệnh', 'may mắn', 'xui', 'hên', 'số phận',
        'thế nào', 'ra sao', 'có tốt', 'có nên', 'có thuận',
    ];

    // Check if message contains astro trigger
    $has_astro_trigger = false;
    foreach ($astro_triggers as $trigger) {
        if (mb_strpos($msg, $trigger) !== false) {
            $has_astro_trigger = true;
            break;
        }
    }

    // Check if message contains life topic (these trigger transit when combined with time)
    $has_life_topic = false;
    if (!$has_astro_trigger) {
        foreach ($life_topic_triggers as $trigger) {
            if (mb_strpos($msg, $trigger) !== false) {
                $has_life_topic = true;
                break;
            }
        }
    }

    // If no astro trigger AND no life topic at all, skip
    if (!$has_astro_trigger && !$has_life_topic) return null;

    // [2026-07-04 Johnny Chu] PHASE-ASTRO-WORKFLOW — detect topics once for all time-period branches
    $topics = function_exists( '_bccm_detect_astro_topics' ) ? _bccm_detect_astro_topics( $msg ) : array();

    // Now check time period — differentiate "hôm nay" (days=0) vs "ngày mai" (days=1)
    $today_patterns = [ 'hôm nay', 'today', 'đêm nay', 'sáng nay', 'chiều nay', 'tối nay' ];
    foreach ( $today_patterns as $p ) {
        if ( mb_strpos( $msg, $p ) !== false ) {
            return [ 'period' => 'day', 'days' => 0, 'num_days' => 1,
                     'label' => 'Hôm nay', 'render_mode' => 'daily',
                     'start_offset' => 0, 'topics' => $topics ];
        }
    }

    // [2026-07-04 Johnny Chu] PHASE-ASTRO-WORKFLOW — "ngày kia" / "ngày kìa" = 2 ngày nữa (day after tomorrow)
    $dat_patterns = array( 'ngày kia', 'ngày kìa' );
    foreach ( $dat_patterns as $p ) {
        if ( mb_strpos( $msg, $p ) !== false ) {
            return array( 'period' => 'day', 'days' => 2, 'num_days' => 1,
                     'label' => 'Ngày kia (2 ngày nữa)', 'render_mode' => 'daily',
                     'start_offset' => 2, 'topics' => $topics );
        }
    }

    $tomorrow_patterns = [ 'ngày mai', 'tomorrow', 'sáng mai', 'chiều mai', 'tối mai', 'ngày tới', 'ngày kế', '24 giờ tới' ];
    foreach ( $tomorrow_patterns as $p ) {
        if ( mb_strpos( $msg, $p ) !== false ) {
            return [ 'period' => 'day', 'days' => 1, 'num_days' => 1,
                     'label' => 'Ngày mai', 'render_mode' => 'daily',
                     'start_offset' => 1, 'topics' => $topics ];
        }
    }

    // [2026-07-03 Johnny Chu] PHASE-ASTRO-MIGRATE — N-week pattern BEFORE week_patterns
    // "2 tuần tới", "hai tuần", "3 tuần" → N * 7 days, render_mode=daily nếu ≤30
    // Must come BEFORE week_patterns loop because "2 tuần tới" contains "tuần tới"
    if ( preg_match( '/(\d+|hai|ba|bốn)\s*tuần/u', $msg, $m ) ) {
        $wmap  = [ 'hai' => 2, 'ba' => 3, 'bốn' => 4 ];
        $weeks = is_numeric( $m[1] ) ? (int) $m[1] : ( $wmap[ $m[1] ] ?? 1 );
        $days  = $weeks * 7;
        if ( $weeks >= 1 && $weeks <= 4 ) {
            return [
                'period'       => $days <= 7  ? 'week' : 'month',
                'days'         => $days,
                'num_days'     => $days,
                'label'        => $weeks . ' tuần tới (' . $days . ' ngày)',
                'render_mode'  => $days <= 30 ? 'daily' : 'overview',
                'start_offset' => 0,
                'topics'       => $topics,
            ];
        }
    }

    foreach ($week_patterns as $p) {
        if (mb_strpos($msg, $p) !== false) {
            return ['period' => 'week', 'days' => 7, 'num_days' => 7,
                    'label' => '7 ngày tới', 'render_mode' => 'daily',
                    'start_offset' => 0, 'topics' => $topics];
        }
    }

    foreach ($month_patterns as $p) {
        if (mb_strpos($msg, $p) !== false) {
            return ['period' => 'month', 'days' => 30, 'num_days' => 30,
                    'label' => '1 tháng tới', 'render_mode' => 'overview',
                    'start_offset' => 0, 'topics' => $topics];
        }
    }

    foreach ($five_year_patterns as $p) {
        if (mb_strpos($msg, $p) !== false) {
            if (preg_match('/(\d+)\s*năm/', $msg, $m)) {
                $years = intval($m[1]);
                return ['period' => 'custom_year', 'days' => $years * 365, 'num_days' => $years * 365,
                        'label' => $years . ' năm tới', 'render_mode' => 'overview',
                        'start_offset' => 0, 'topics' => $topics];
            }
            return ['period' => '5year', 'days' => 1825, 'num_days' => 1825,
                    'label' => '5 năm tới', 'render_mode' => 'overview',
                    'start_offset' => 0, 'topics' => $topics];
        }
    }

    foreach ($year_patterns as $p) {
        if (mb_strpos($msg, $p) !== false) {
            return ['period' => 'year', 'days' => 365, 'label' => '1 năm tới',
                    'num_days' => 365, 'render_mode' => 'overview',
                    'start_offset' => 0, 'topics' => $topics];
        }
    }

    // [2026-07-03 Johnny Chu] PHASE-ASTRO-MIGRATE — explicit N-day detection
    // Matches: "5 ngày tới", "5 hôm tới", "10 ngày", "14 ngày tiếp", v.v.
    // Also: "đến cuối năm", "hết năm", "cuối năm".
    // render_mode:
    //   'daily'    → num_days ≤ 30 → transit_range() day-by-day
    //   'overview' → num_days > 30 → summary / yearly overview
    //   'fallback' → num_days > 30 nhưng user muốn cụ thể → thông báo giới hạn 30

    // Pattern: "đến cuối năm" / "hết năm" / "từ giờ đến cuối năm"
    if ( preg_match( '/cuối năm|hết năm|đến cuối năm|tới cuối năm/', $msg ) ) {
        $remaining = (int) ( strtotime( date( 'Y' ) . '-12-31' ) - strtotime( current_time( 'Y-m-d' ) ) ) / 86400;
        $remaining = max( 1, $remaining );
        return [
            'period'      => $remaining <= 30 ? 'month' : 'year',
            'days'        => $remaining,
            'num_days'    => $remaining,
            'label'       => 'Từ hôm nay đến cuối năm (' . $remaining . ' ngày)',
            'render_mode' => 'overview',
        ];
    }

    // Pattern: "tháng sau" / "next month" → overview, không day-by-day
    if ( preg_match( '/tháng sau|next month/', $msg ) ) {
        return [ 'period' => 'month', 'days' => 30, 'num_days' => 30,
                 'label' => 'Tháng sau', 'render_mode' => 'overview' ];
    }

    // Pattern: "N ngày tới" / "N hôm tới" / "N ngày tiếp" / "N ngày kế"
    if ( preg_match( '/(\d+)\s*(?:ngày|hôm)\s*(?:tới|tiếp|kế|sau|nữa|next)?/u', $msg, $m ) ) {
        $n = (int) $m[1];
        if ( $n >= 1 && $n <= 90 ) {
            if ( $n <= 30 ) {
                return [
                    'period'      => $n <= 7 ? 'week' : 'month',
                    'days'        => $n,
                    'num_days'    => $n,
                    'label'       => $n . ' ngày tới',
                    'render_mode'  => 'daily',
                    'start_offset' => 0,
                    'topics'       => $topics,
                ];
            } else {
                // > 30 ngày → fallback: thông báo chỉ lấy được 30 ngày cụ thể
                return [
                    'period'         => 'month',
                    'days'           => 30,
                    'num_days'       => 30,
                    'label'          => $n . ' ngày tới (giới hạn 30 ngày)',
                    'render_mode'    => 'fallback',
                    'requested_days' => $n,
                    'start_offset'   => 0,
                    'topics'         => $topics,
                ];
            }
        }
    }

    // Has astro trigger but no specific time → default to 1 month
    // Life topic WITHOUT specific time → don't trigger (too vague)
    if ($has_life_topic && !$has_astro_trigger) {
        return null; // "tài chính" alone without time context → skip
    }
    return ['period' => 'month', 'days' => 30, 'num_days' => 30,
            'label' => '1 tháng tới (mặc định)', 'render_mode' => 'overview',
            'start_offset' => 0, 'topics' => $topics];
}

/* =====================================================================
 * TRANSIT DATA FETCHER: Gọi API lấy vị trí sao tại thời điểm transit
 * =====================================================================*/

/**
 * Fetch transit planets for a given date + location
 *
 * @param string $date       Date string (Y-m-d), default = today
 * @param float  $latitude   Observer latitude (default = Hanoi)
 * @param float  $longitude  Observer longitude (default = Hanoi)
 * @param float  $timezone   Timezone offset (default = 7 for VN)
 * @return array|WP_Error    ['planets' => [...], 'parsed' => [...]]
 */
function bccm_transit_fetch_planets($date = '', $latitude = 21.0285, $longitude = 105.8542, $timezone = 7.0) {
    if (empty($date)) {
        $date = current_time('Y-m-d');
    }

    $ts = strtotime($date);
    if (!$ts) {
        return new WP_Error('invalid_date', "Ngày không hợp lệ: $date");
    }

    // Build birth_data-like array for the transit date (noon for stability)
    $transit_data = [
        'year'      => intval(date('Y', $ts)),
        'month'     => intval(date('n', $ts)),
        'day'       => intval(date('j', $ts)),
        'hour'      => 12,
        'minute'    => 0,
        'second'    => 0,
        'latitude'  => $latitude,
        'longitude' => $longitude,
        'timezone'  => $timezone,
    ];

    // [2026-06-08 Johnny Chu] HOTFIX R-GW-8 — route transit prefetch through
    // BizCoach_Pro_Astro_Client (client wrapper → gateway → api.freeastroapi.com)
    // INSTEAD of the broken legacy path which hit deprecated
    // json.freeastrologyapi.com directly (30/day → 429 storm).
    // The old condition `class_exists('BizCity_Astro_Router')` checked a
    // SERVER-SIDE class that NEVER exists on client sites (R-GW-8 standalone
    // topology), so it always fell through to legacy direct-HTTP.
    if ( class_exists( 'BizCoach_Pro_Astro_Client' ) ) {
        $iso_date = sprintf( '%sT12:00', $date );
        $tx_payload = array(
            'transit_date' => $iso_date,
            'tz_str'       => 'Asia/Ho_Chi_Minh',
            'current_city' => 'Hanoi',
            'current_lat'  => (float) $latitude,
            'current_lng'  => (float) $longitude,
            // Provide synthetic natal so the FAA transit endpoint has a
            // reference frame (some providers require both blocks).
            'natal' => array(
                'year'         => (int) date( 'Y', $ts ),
                'month'        => (int) date( 'n', $ts ),
                'day'          => (int) date( 'j', $ts ),
                'hour'         => 12,
                'minute'       => 0,
                'lat'          => (float) $latitude,
                'lng'          => (float) $longitude,
                'tz_str'       => 'Asia/Ho_Chi_Minh',
                'house_system' => 'placidus',
                'zodiac_type'  => 'tropical',
                'city'         => 'Hanoi',
            ),
        );
        $tx_res = BizCoach_Pro_Astro_Client::transits_western( $tx_payload, array( 'timeout' => 25 ) );

        if ( ! empty( $tx_res['success'] ) ) {
            $env = is_array( $tx_res['envelope'] ?? null ) ? $tx_res['envelope'] : array();
            // V2 normalizer puts transit planets at envelope.transits (per
            // BizCity_Astro_Normalizer_V2::normalize_western_transits).
            $planets_raw = (array) ( $env['transits'] ?? $env['planets'] ?? array() );

            $positions_norm = array();
            foreach ( $planets_raw as $p ) {
                $name = (string) ( $p['name_en'] ?? $p['id'] ?? '' );
                if ( $name === '' ) continue;
                $name = ucfirst( strtolower( trim( preg_replace( '/\s*\([NTnt]\)\s*$/', '', $name ) ) ) );
                $abs  = (float) ( $p['absolute_degree'] ?? $p['fullDegree'] ?? 0 );
                $sd   = (float) ( $p['sign_degree']     ?? $p['normDegree'] ?? 0 );
                $sign = function_exists( '_bcpro_astro_v2_normalize_sign' )
                    ? _bcpro_astro_v2_normalize_sign( (string) ( $p['sign_en'] ?? '' ) )
                    : (string) ( $p['sign_en'] ?? '' );
                $positions_norm[ $name ] = array(
                    'sign'        => $sign,
                    'sign_en'     => $sign,
                    'sign_vi'     => (string) ( $p['sign_vi'] ?? '' ),
                    'full_degree' => $abs,
                    'fullDegree'  => $abs,
                    'norm_degree' => $sd,
                    'normDegree'  => $sd,
                    'house'       => (int) ( $p['house'] ?? 0 ),
                    'is_retro'    => (bool) ( $p['retrograde'] ?? false ),
                    'isRetro'     => ! empty( $p['retrograde'] ) ? 'true' : 'false',
                );
            }

            if ( ! empty( $positions_norm ) ) {
                return array(
                    'date'    => $date,
                    'planets' => $planets_raw,
                    'parsed'  => array( 'positions' => $positions_norm ),
                    '_source' => 'gateway_transits_western',
                );
            }
        }
        // Gateway failed/empty → log so admins know, then refuse the legacy path
        // (the deprecated json.freeastrologyapi.com host is dead per FAA notice).
        // [2026-06-28 Johnny Chu] HOTFIX — log full response context to diagnose 'unknown'
        $err_msg = 'unknown';
        if ( is_array( $tx_res ) ) {
            if ( ! empty( $tx_res['error'] ) ) {
                $err_msg = (string) $tx_res['error'];
            } elseif ( ! empty( $tx_res['message'] ) ) {
                $err_msg = (string) $tx_res['message'];
            } elseif ( ! empty( $tx_res['_degraded'] ) ) {
                $err_msg = 'gateway_degraded:' . (string) $tx_res['_degraded'];
            } else {
                // Dump top-level keys to help diagnose
                $err_msg = 'unknown (keys=' . implode( ',', array_keys( $tx_res ) ) . ')';
            }
        } else {
            $err_msg = 'no_response (type=' . gettype( $tx_res ) . ')';
        }
        error_log( '[bccm_transit] Gateway transit fetch failed for date=' . $date . ': ' . $err_msg
            . ' blog_id=' . (int) get_current_blog_id() );
        return new WP_Error(
            'transit_gateway_failed',
            'Không thể lấy transit qua gateway: ' . $err_msg
        );
    }

    // Legacy V2 router path (kept as last-resort fallback for in-process server use only).
    if ( function_exists( 'bcpro_astro_v2_available' ) && bcpro_astro_v2_available( 'western' )
        && function_exists( 'bcpro_astro_birth_to_v2_input' )
        && class_exists( 'BizCity_Astro_Router' ) ) {

        $provider = BizCity_Astro_Router::get_provider( 'faa_western' );
        $input    = bcpro_astro_birth_to_v2_input( $transit_data );
        // For transits provider expects an ISO transit date; pass noon Asia/HCM
        // so the snapshot is comparable to natal.
        $input['transit_date'] = sprintf( '%sT12:00', $date );
        $input['current_city'] = 'Hanoi';

        $env = method_exists( $provider, 'transits' ) ? $provider->transits( $input ) : null;
        if ( $env && ! empty( $env['success'] ) ) {
            $planets_raw    = (array) ( $env['planets'] ?? array() );
            $positions_norm = array();
            foreach ( $planets_raw as $p ) {
                $name = (string) ( $p['name_en'] ?? $p['id'] ?? '' );
                if ( $name === '' ) continue;
                // Strip optional "(N)"/"(T)" suffix that some transit envelopes attach.
                $name = ucfirst( strtolower( trim( preg_replace( '/\s*\([NTnt]\)\s*$/', '', $name ) ) ) );
                $abs  = (float) ( $p['absolute_degree'] ?? $p['fullDegree'] ?? 0 );
                $sd   = (float) ( $p['sign_degree']     ?? $p['normDegree'] ?? 0 );
                $sign = function_exists( '_bcpro_astro_v2_normalize_sign' )
                    ? _bcpro_astro_v2_normalize_sign( (string) ( $p['sign_en'] ?? '' ) )
                    : (string) ( $p['sign_en'] ?? '' );
                $positions_norm[ $name ] = array(
                    'sign'        => $sign,
                    'sign_en'     => $sign,
                    'sign_vi'     => (string) ( $p['sign_vi'] ?? '' ),
                    'full_degree' => $abs,
                    'fullDegree'  => $abs,
                    'norm_degree' => $sd,
                    'normDegree'  => $sd,
                    'house'       => (int) ( $p['house'] ?? 0 ),
                    'is_retro'    => (bool) ( $p['retrograde'] ?? false ),
                    'isRetro'     => ! empty( $p['retrograde'] ) ? 'true' : 'false',
                );
            }
            return array(
                'date'    => $date,
                'planets' => $planets_raw,
                'parsed'  => array( 'positions' => $positions_norm ),
                '_source' => 'faa_western_transits_v2',
            );
        }
        // V2 failed → fall through to legacy path below (still better than aborting).
    }

    // Use existing API call function (legacy path).
    if (!function_exists('bccm_astro_get_planets')) {
        return new WP_Error('no_api', 'Hàm bccm_astro_get_planets chưa được load.');
    }

    $result = bccm_astro_get_planets($transit_data);
    if (is_wp_error($result)) return $result;

    // Parse planets
    $parsed = bccm_astro_parse_planets($result['planets'] ?? []);

    return [
        'date'    => $date,
        'planets' => $result['planets'] ?? [],
        'parsed'  => $parsed,
    ];
}

/* =====================================================================
 * TRANSIT vs NATAL: So sánh aspect giữa transit planets và natal chart
 * =====================================================================*/

/**
 * Compare transit planets against natal positions to find active aspects
 *
 * @param array $transit_positions  From bccm_transit_fetch_planets()['parsed']['positions']
 * @param array $natal_positions    From saved natal chart positions
 * @param float $orb_tolerance      Max orb degrees (default 5°)
 * @return array  List of active transit aspects
 */
function bccm_transit_calc_aspects($transit_positions, $natal_positions, $orb_tolerance = 5.0) {
    $aspects = [];
    $aspect_angles = function_exists('bccm_aspect_angles') ? bccm_aspect_angles() : [
        'Conjunction' => 0, 'Opposition' => 180, 'Trine' => 120,
        'Square' => 90, 'Sextile' => 60, 'Quincunx' => 150,
    ];

    // Only check major transit planets (not Ascendant/MC which are location-dependent)
    $transit_planets = ['Sun', 'Moon', 'Mercury', 'Venus', 'Mars', 'Jupiter', 'Saturn', 'Uranus', 'Neptune', 'Pluto'];
    $natal_planets   = ['Sun', 'Moon', 'Mercury', 'Venus', 'Mars', 'Jupiter', 'Saturn', 'Ascendant', 'MC'];

    // Only major aspects for transit
    $major_aspects = ['Conjunction', 'Opposition', 'Trine', 'Square', 'Sextile'];

    foreach ($transit_planets as $t_name) {
        if (empty($transit_positions[$t_name])) continue;
        $t_deg = $transit_positions[$t_name]['full_degree'];

        foreach ($natal_planets as $n_name) {
            if (empty($natal_positions[$n_name])) continue;
            $n_deg = $natal_positions[$n_name]['full_degree'];

            foreach ($major_aspects as $aspect_name) {
                if (!isset($aspect_angles[$aspect_name])) continue;
                $target_angle = $aspect_angles[$aspect_name];

                // Calculate angular difference
                $diff = abs($t_deg - $n_deg);
                if ($diff > 180) $diff = 360 - $diff;

                $orb = abs($diff - $target_angle);
                if ($orb <= $orb_tolerance) {
                    $aspects[] = [
                        'transit_planet'    => $t_name,
                        'transit_sign'      => $transit_positions[$t_name]['sign_en'] ?? '',
                        'transit_degree'    => $t_deg,
                        'transit_retro'     => $transit_positions[$t_name]['is_retro'] ?? false,
                        'natal_planet'      => $n_name,
                        'natal_sign'        => $natal_positions[$n_name]['sign_en'] ?? '',
                        'natal_degree'      => $n_deg,
                        'aspect'            => $aspect_name,
                        'orb'               => round($orb, 2),
                        'is_exact'          => $orb <= 1.0,
                        'nature'            => bccm_transit_aspect_nature($aspect_name),
                    ];
                }
            }
        }
    }

    // Sort: exact aspects first, then by orb
    usort($aspects, function ($a, $b) {
        if ($a['is_exact'] !== $b['is_exact']) return $a['is_exact'] ? -1 : 1;
        return $a['orb'] <=> $b['orb'];
    });

    return $aspects;
}

/**
 * Classify aspect nature for transit interpretation
 */
function bccm_transit_aspect_nature($aspect) {
    $harmonious = ['Trine', 'Sextile', 'Conjunction'];
    $challenging = ['Square', 'Opposition'];
    if (in_array($aspect, $harmonious)) return 'harmonious';
    if (in_array($aspect, $challenging)) return 'challenging';
    return 'neutral';
}

/* =====================================================================
 * TRANSIT CONTEXT BUILDER: Tạo context string cho AI Agent
 * =====================================================================*/

/**
 * Build a complete transit context for AI, comparing transit against natal chart
 *
 * This is THE main function used by BizCity_Profile_Context.
 *
 * @param int    $coachee_id  Coachee profile ID
 * @param int    $user_id     WordPress user ID (fallback)
 * @param array  $time_range  From bccm_transit_detect_intent() ['period', 'days', 'label']
 * @return string  Formatted transit context for system prompt (empty if no data)
 */
function bccm_transit_build_context($coachee_id, $user_id = 0, $time_range = []) {
    global $wpdb;

    if (empty($time_range)) {
        $time_range = ['period' => 'month', 'days' => 30, 'label' => '1 tháng tới'];
    }

    // ── Get natal chart data ──
    $t_astro = $wpdb->prefix . 'bccm_astro';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$t_astro}'") !== $t_astro) return '';

    // [2026-07-07 Johnny Chu] PHASE-FAA2-TL-PROFILE — prefer per-profile natal row.
    $natal_row = null;
    if ($coachee_id) {
        $natal_row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$t_astro} WHERE coachee_id = %d AND chart_type = 'western' ORDER BY updated_at DESC LIMIT 1",
            $coachee_id
        ));
    }
    if (!$natal_row && $user_id) {
        $natal_row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$t_astro} WHERE user_id = %d AND chart_type = 'western' AND (summary IS NOT NULL OR traits IS NOT NULL) ORDER BY updated_at DESC LIMIT 1",
            $user_id
        ));
    }
    if (!$natal_row || empty($natal_row->traits)) return '';

    $natal_traits    = json_decode($natal_row->traits, true);
    $natal_positions = $natal_traits['positions'] ?? [];
    if (empty($natal_positions)) return '';

    // ── Transient cache ──
    // [2026-07-07 Johnny Chu] PHASE-FAA2-TL-PROFILE — cache key must be profile-scoped,
    // not account-scoped, to prevent bleed between multiple coachees under same user.
    $cache_id  = $coachee_id ?: $user_id;
    $cache_key = 'bccm_transit_' . $cache_id . '_' . $time_range['period'] . '_' . current_time('Y-m-d');
    $cached    = get_transient($cache_key);
    if ($cached !== false) return $cached;

    // ── Read pre-fetched transit data from DB (no live API calls at chat time) ──
    $transit_snapshots = [];
    $all_aspects       = [];
    $t_snap            = $wpdb->prefix . 'bccm_transit_snapshots';
    $snap_table_exists = ($wpdb->get_var("SHOW TABLES LIKE '{$t_snap}'") === $t_snap);

    if ($snap_table_exists) {
        $needed_dates = bccm_transit_get_check_dates($time_range);

        // Load all snapshots for coachee in range today-7d to today+400d
        // [2026-07-07 Johnny Chu] PHASE-FAA2-TL-PROFILE — timeline context must track selected coachee.
        $rows = $wpdb->get_results(
            "SELECT target_date, label, planets_json, aspects_json, fetched_at "
            . "FROM {$t_snap} "
            . $wpdb->prepare('WHERE coachee_id = %d ', $coachee_id)
            . "  AND target_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) "
            . "  AND target_date <= DATE_ADD(CURDATE(), INTERVAL 400 DAY) "
            . 'ORDER BY target_date ASC'
        );

        if ( empty( $rows ) && $user_id ) {
            // [2026-07-07 Johnny Chu] PHASE-FAA2-TL-PROFILE — legacy fallback only when
            // coachee-scoped snapshots do not exist.
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT target_date, label, planets_json, aspects_json, fetched_at "
                . "FROM {$t_snap} "
                . "WHERE user_id = %d "
                . "  AND target_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) "
                . "  AND target_date <= DATE_ADD(CURDATE(), INTERVAL 400 DAY) "
                . 'ORDER BY target_date ASC',
                $user_id
            ) );
        }

        // Map snapshots by date for nearest-match lookup
        $snap_by_date = [];
        foreach ($rows as $row) {
            $snap_by_date[$row->target_date] = $row;
        }

        // Tolerance theo period: sao nhanh dịch chuyển nhanh → cần data gần hơn
        $tolerance_days = 45; // default
        switch ($time_range['period']) {
            case 'day':   $tolerance_days = 0;  break; // ngày mai: phải đúng ngày, không fallback
            case 'week':  $tolerance_days = 5;  break; // tuần tới: ±5 ngày
            case 'month': $tolerance_days = 15; break; // tháng tới: ±15 ngày
            // year, 5year, custom_year: giữ nguyên 45 ngày
        }

        $used_dates = [];
        foreach ($needed_dates as $date_info) {
            $target_ts = strtotime($date_info['date']);
            $best_date = null;
            $best_diff = PHP_INT_MAX;

            foreach ($snap_by_date as $snap_date => $row) {
                $diff = abs(strtotime($snap_date) - $target_ts);
                if ($diff < $best_diff) { $best_diff = $diff; $best_date = $snap_date; }
            }

            // Accept snapshot within tolerance window
            if ($best_date && $best_diff <= $tolerance_days * DAY_IN_SECONDS && !isset($used_dates[$best_date])) {
                $used_dates[$best_date] = true;
                $row       = $snap_by_date[$best_date];
                $positions = json_decode($row->planets_json, true) ?: [];
                $aspects   = json_decode($row->aspects_json, true) ?: [];

                foreach ($aspects as &$a) {
                    $a['transit_date']  = $best_date;
                    $a['transit_label'] = $date_info['label'];
                }
                unset($a);

                $transit_snapshots[] = [
                    'date'       => $best_date,
                    'label'      => $date_info['label'],
                    'positions'  => $positions,
                    'fetched_at' => $row->fetched_at,
                ];
                $all_aspects = array_merge($all_aspects, $aspects);
            }
        }
    }

    // ── No data in DB → return graceful guidance (legacy prefetch scheduler disabled) ──
    if (empty($transit_snapshots)) {
        // [2026-07-06 Johnny Chu] HOTFIX — Transit writer unification:
        // do not schedule legacy bccm_transit_prefetch_cron anymore.
        // [2026-07-06 Johnny Chu] HOTFIX — default-disabled fallback; schedule only when explicitly enabled.
        if ( ! defined( 'BCPRO_LEGACY_TRANSIT_PREFETCH_ENABLED' ) || ! BCPRO_LEGACY_TRANSIT_PREFETCH_ENABLED ) {
            error_log("[bccm_transit] No DB snapshots for coachee_id={$coachee_id}, user_id={$user_id}. Legacy prefetch disabled.");
        } else {
            error_log("[bccm_transit] No DB snapshots for coachee_id={$coachee_id}, user_id={$user_id}. Scheduling prefetch.");
            if (!wp_next_scheduled('bccm_transit_prefetch_cron', [$coachee_id, $user_id])) {
                wp_schedule_single_event(time() + 5, 'bccm_transit_prefetch_cron', [$coachee_id, $user_id]);
            }
        }
        return implode("\n", [
            '## ℹ️ Chế độ: Đang cập nhật dữ liệu vị trí sao transit (tạm thời dùng hồ sơ natal)',
            '',
            'Hệ thống đang tải dữ liệu dịch chuyển sao cho giai đoạn này (chạy trong nền, vài phút xong).',
            '',
            '**📌 HƯỚNG DẪN AI TRONG LÚC CHỜ:**',
            'Trong khi chờ dữ liệu transit được cập nhật, hãy TRẢ LỜI DỰA TRÊN HỒ SƠ CÁ NHÂN của user đã có trong phần Hồ Sơ Chủ Nhân phía trên:',
            '- Bản đồ sao NATAL (lá số gốc) — vị trí cố định các hành tinh lúc sinh',
            '- Kết quả phân tích từ gen_results (SWOT, thần số học, ngũ hành, tính cách)',
            '- Câu trả lời coaching (answer_json) — thông tin tự khai',
            '',
            '**💬 MẪU TRẢ LỜI ĐƯỢC GỢI Ý (điều chỉnh cho tự nhiên):**',
            '"Dạ, em đang cập nhật dữ liệu vị trí sao thực tế cho giai đoạn bạn hỏi — sẽ sẵn sàng sau vài phút nữa.',
            'Trong lúc này, em sẽ phân tích dựa trên bản đồ sao natal (lá số gốc) và hồ sơ cá nhân của bạn nhé —',
            'đây vẫn là nền tảng chính xác và cá nhân hóa nhất!"',
            '',
            '⚠️ Sau đó hãy TIẾP TỤC phân tích từ natal chart và gen_results ngay bên dưới.',
            '❌ TUYỆT ĐỐI KHÔNG dùng Tarot, bói bài hay bất kỳ phương pháp thay thế.',
        ]);
    }

    // ── Build context from stored data ──
    $context = bccm_transit_format_context($natal_positions, $transit_snapshots, $all_aspects, $time_range);

    // Add staleness note if data is older than 30 days
    if (!empty($transit_snapshots[0]['fetched_at'])) {
        $age_days = intval((time() - strtotime($transit_snapshots[0]['fetched_at'])) / DAY_IN_SECONDS);
        if ($age_days > 30) {
            $context .= "\n\n> ⚠️ **Lưu ý**: Dữ liệu transit được tính từ {$age_days} ngày trước."
                . ' Sao chậm (Jupiter, Saturn, Uranus, Neptune, Pluto) vẫn còn tương đối chính xác;'
                . ' sao nhanh (Mặt Trời, Mặt Trăng, Sao Thủy) có thể đã dịch chuyển đáng kể.';
        }
    }

    set_transient($cache_key, $context, 6 * HOUR_IN_SECONDS);
    return $context;
}

/**
 * Determine which dates to check based on time range
 */
function bccm_transit_get_check_dates($time_range, $base_date = '') {
    // [2026-07-06 Johnny Chu] HOTFIX — timezone-safe date math + optional exact base date.
    $dates = [];

    if ( function_exists('wp_timezone') ) {
        $tz = wp_timezone();
    } else {
        $tz_str = function_exists('wp_timezone_string') ? (string) wp_timezone_string() : '';
        $tz = new DateTimeZone( $tz_str !== '' ? $tz_str : 'UTC' );
    }

    $base_date = trim((string) $base_date);
    if ($base_date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $base_date)) {
        try {
            $cursor = new DateTimeImmutable($base_date, $tz);
        } catch (Exception $e) {
            $cursor = new DateTimeImmutable('now', $tz);
        }
    } else {
        $cursor = new DateTimeImmutable('now', $tz);
    }
    $cursor = $cursor->setTime(0, 0, 0);
    $today = $cursor->format('Y-m-d');

    $plus_days = static function($dt, $days) {
        return $dt->modify('+' . (int) $days . ' day')->format('Y-m-d');
    };

    // Always include base day.
    $dates[] = ['date' => $today, 'label' => 'Hôm nay'];

    switch ($time_range['period']) {
        case 'day':
            // days=0 → just selected/base day; days>=1 → include next day.
            if ( ( $time_range['days'] ?? 1 ) >= 1 ) {
                $dates[] = ['date' => $plus_days($cursor, 1), 'label' => 'Ngày mai'];
            }
            break;

        case 'week':
            $dates[] = ['date' => $plus_days($cursor, 3), 'label' => '+3 ngày'];
            $dates[] = ['date' => $plus_days($cursor, 7), 'label' => '+7 ngày'];
            break;

        case 'month':
            $dates[] = ['date' => $plus_days($cursor, 7),  'label' => '+1 tuần'];
            $dates[] = ['date' => $plus_days($cursor, 15), 'label' => '+15 ngày'];
            $dates[] = ['date' => $plus_days($cursor, 30), 'label' => '+1 tháng'];
            break;

        case 'year':
            $dates[] = ['date' => $cursor->modify('+1 month')->format('Y-m-d'),  'label' => '+1 tháng'];
            $dates[] = ['date' => $cursor->modify('+3 months')->format('Y-m-d'), 'label' => '+3 tháng'];
            $dates[] = ['date' => $cursor->modify('+6 months')->format('Y-m-d'), 'label' => '+6 tháng'];
            $dates[] = ['date' => $cursor->modify('+12 months')->format('Y-m-d'), 'label' => '+12 tháng'];
            break;

        case '5year':
        case 'custom_year':
            $days = $time_range['days'] ?? 1825;
            $years = max(1, intval($days / 365));
            $dates[] = ['date' => $cursor->modify('+6 months')->format('Y-m-d'), 'label' => '+6 tháng'];
            for ($y = 1; $y <= min($years, 5); $y++) {
                $dates[] = ['date' => $cursor->modify('+' . (int) $y . ' year')->format('Y-m-d'), 'label' => '+' . (int) $y . ' năm'];
            }
            break;

        default:
            $dates[] = ['date' => $plus_days($cursor, 7), 'label' => '+7 ngày'];
            break;
    }

    return $dates;
}

/* =====================================================================
 * FORMAT CONTEXT: Tạo text có cấu trúc cho AI prompt
 * =====================================================================*/

/**
 * Format transit data into readable context for AI system prompt
 */
function bccm_transit_format_context($natal_positions, $snapshots, $aspects, $time_range) {
    $planet_vi = function_exists('bccm_planet_names_vi') ? bccm_planet_names_vi() : [];
    $aspect_vi = function_exists('bccm_aspect_names_vi') ? bccm_aspect_names_vi() : [];
    $signs     = function_exists('bccm_zodiac_signs')    ? bccm_zodiac_signs()    : [];

    // Build EN→VI sign lookup
    $sign_en_to_vi = [];
    foreach ($signs as $s) {
        $sign_en_to_vi[strtolower($s['en'])] = $s['vi'];
    }
    $to_vi = function($sign_en) use ($sign_en_to_vi) {
        return $sign_en_to_vi[strtolower($sign_en)] ?? $sign_en;
    };

    $parts = [];

    // ═══════════════════════════════════════════════
    // STRICT BEHAVIORAL HEADER — forces AI to use transit data
    // ═══════════════════════════════════════════════
    $today_display  = current_time('d/m/Y');
    $now_ts_fmt     = current_time('timestamp');
    // Compute target date display based on time range
    $target_days    = $time_range['days'] ?? 30;
    $target_display = date('d/m/Y', strtotime('+' . $target_days . ' days', $now_ts_fmt));

    // Build target date display: for 'day' use actual days offset
    $actual_target_date = ($time_range['period'] === 'day')
        ? date('d/m/Y', strtotime('+' . intval($target_days) . ' day', $now_ts_fmt))
        : $target_display;

    $parts[] = "## 🔮 DỮ LIỆU TRANSIT CHIÊM TINH THỰC TẾ — {$time_range['label']}";
    $parts[] = "_📅 **Hôm nay: {$today_display}** | 📌 **Dự báo cho: {$time_range['label']}** = ngày **{$actual_target_date}**_";
    $parts[] = "";
    $parts[] = "🗓️ **XÁC NHẬN NGÀY THÁNG:**";
    $parts[] = "- Hôm nay (ngày thực tế): **{$today_display}**";
    $parts[] = "- Ngày được dự báo cho: **{$actual_target_date}** ({$time_range['label']})";
    $parts[] = "- ⛔ DỮ LIỆU HÀNH TINH BÊN DƯỚI LÀ CHO NGÀY **{$actual_target_date}** — không phải ngày {$today_display}.";
    $parts[] = "";
    $parts[] = "🔴 **QUY TẮC BẮT BUỘC KHI CÓ DỮ LIỆU TRANSIT NÀY:**";
    $parts[] = "1. BẠN PHẢI trả lời DỰA TRÊN dữ liệu dịch chuyển sao (transit) bên dưới — đây là vị trí THỰC TẾ của các hành tinh trên bầu trời.";
    $parts[] = "2. KHÔNG ĐƯỢC sử dụng Tarot, bói bài, rút lá, hay bất kỳ phương pháp bói toán nào khác khi đã có dữ liệu transit.";
    $parts[] = "3. KHÔNG ĐƯỢC bịa đặt vị trí sao hoặc góc chiếu. Chỉ sử dụng ĐÚNG dữ liệu được cung cấp bên dưới.";
    $parts[] = "4. Hãy giải thích SỰ DỊCH CHUYỂN của các vì sao: sao nào đang ở cung nào, tạo góc chiếu gì với bản đồ natal của user, và ảnh hưởng cụ thể ra sao.";
    $parts[] = "5. Liên hệ transit với BẢN ĐỒ NATAL của user (trong Hồ Sơ Chủ Nhân phía trên) để cá nhân hóa dự báo.";
    $parts[] = "6. Phân tích theo ngữ cảnh câu hỏi của user (tài chính, sự nghiệp, tình cảm, sức khỏe...).";
    $parts[] = "";

    // ── User's natal chart positions ──
    $key_natal = ['Sun', 'Moon', 'Mercury', 'Venus', 'Mars', 'Jupiter', 'Saturn', 'Uranus', 'Neptune', 'Pluto', 'Ascendant', 'MC'];
    $natal_lines = [];
    foreach ($key_natal as $pname) {
        if (empty($natal_positions[$pname])) continue;
        $np = $natal_positions[$pname];
        $vi = $planet_vi[$pname] ?? $pname;
        $sign_vi_n = $np['sign_vi'] ?? $to_vi($np['sign_en'] ?? '');
        $deg = round($np['norm_degree'] ?? 0, 1);
        $retro = !empty($np['is_retro']) ? ' ℞' : '';
        $natal_lines[] = "- **{$vi}** natal: cung **{$sign_vi_n}** tại {$deg}°{$retro}";
    }
    if (!empty($natal_lines)) {
        $parts[] = "### 🗺️ BẢN ĐỒ SAO NATAL (lá số gốc) của user:";
        $parts[] = "_(Đây là vị trí CỐ ĐỊNH các hành tinh lúc user sinh ra — dùng làm cơ sở so sánh với transit)_";
        $parts = array_merge($parts, $natal_lines);
        $parts[] = "";
    }

    // ── Primary snapshot (may be today, tomorrow, or any target date) ──
    if (!empty($snapshots[0])) {
        $today_pos = $snapshots[0]['positions'];
        $key_planets = ['Sun', 'Moon', 'Mercury', 'Venus', 'Mars', 'Jupiter', 'Saturn', 'Uranus', 'Neptune', 'Pluto'];
        $snap0_date_label = $snapshots[0]['label'] ?? 'Hôm nay';
        $snap0_date_display = date('d/m/Y', strtotime($snapshots[0]['date']));
        $parts[] = "### 🌌 Vị trí thực tế các hành tinh — **{$snap0_date_label}** ({$snap0_date_display}):";  
        foreach ($key_planets as $pname) {
            if (empty($today_pos[$pname])) continue;
            $p = $today_pos[$pname];
            $vi = $planet_vi[$pname] ?? $pname;
            $sign_vi = $p['sign_vi'] ?? $p['sign_en'];
            $retro = $p['is_retro'] ? ' ℞ (NGHỊCH HÀNH — năng lượng hướng nội, xem xét lại)' : '';
            $deg = round($p['norm_degree'], 1);
            $parts[] = "- **{$vi}** đang ở cung **{$sign_vi}** tại {$deg}°{$retro}";
        }
    }

    // ── Future snapshots (show planet movement) ──
    if (count($snapshots) > 1) {
        $parts[] = "";
        $parts[] = "### 🔭 Sự dịch chuyển sao trong {$time_range['label']}:";
        for ($i = 1; $i < count($snapshots); $i++) {
            $snap = $snapshots[$i];
            $snap_date = date('d/m/Y', strtotime($snap['date']));
            $parts[] = "";
            $parts[] = "**{$snap['label']}** — ngày thực tế: **{$snap_date}**:";
            // Show only planets that moved to a different sign or turned retro
            $prev_pos = $snapshots[$i-1]['positions'];
            $movements = [];
            foreach (['Sun','Moon','Mercury','Venus','Mars','Jupiter','Saturn','Uranus','Neptune','Pluto'] as $pname) {
                if (empty($snap['positions'][$pname])) continue;
                $curr = $snap['positions'][$pname];
                $prev = $prev_pos[$pname] ?? null;
                $vi = $planet_vi[$pname] ?? $pname;
                $sign_vi = $curr['sign_vi'] ?? ($curr['sign_en'] ?? '');
                $deg = round($curr['norm_degree'] ?? 0, 1);
                $retro_now = !empty($curr['is_retro']);
                $retro_before = $prev ? !empty($prev['is_retro']) : false;
                $sign_changed = $prev && (strtolower($curr['sign_en'] ?? '') !== strtolower($prev['sign_en'] ?? ''));
                $retro_changed = ($retro_now !== $retro_before);

                if ($sign_changed) {
                    $prev_sign = $prev['sign_vi'] ?? ($prev['sign_en'] ?? '?');
                    $movements[] = "- **{$vi}** CHUYỂN CUNG: {$prev_sign} → **{$sign_vi}** tại {$deg}°" . ($retro_now ? ' ℞' : '');
                } elseif ($retro_changed) {
                    $movements[] = "- **{$vi}** " . ($retro_now ? 'BẮT ĐẦU NGHỊCH HÀNH ℞' : 'THUẬN HÀNH TRỞ LẠI') . " tại **{$sign_vi}** {$deg}°";
                } else {
                    $movements[] = "- {$vi}: **{$sign_vi}** {$deg}°" . ($retro_now ? ' ℞' : '');
                }
            }
            $parts = array_merge($parts, $movements);
        }
    }

    // ── Retrograde alerts ──
    $retro_planets = [];
    foreach ($snapshots as $snap) {
        foreach ($snap['positions'] as $name => $pos) {
            if (!empty($pos['is_retro']) && !in_array($name, ['Ascendant', 'MC', 'IC', 'Descendant', 'Mean Node', 'True Node'])) {
                $retro_planets[$name] = [
                    'name_vi' => $planet_vi[$name] ?? $name,
                    'sign'    => $pos['sign_vi'] ?? $pos['sign_en'],
                    'date'    => $snap['label'],
                ];
            }
        }
    }
    if (!empty($retro_planets)) {
        $parts[] = "";
        $parts[] = "### ⚠️ CẢNH BÁO: Sao đang nghịch hành (Retrograde):";
        foreach ($retro_planets as $name => $info) {
            $parts[] = "- **{$info['name_vi']}** đang nghịch hành ℞ trong cung {$info['sign']} — năng lượng hướng nội, nên xem xét lại thay vì khởi đầu mới ({$info['date']})";
        }
    }

    // ── Key transit aspects (transit vs natal) ──
    if (!empty($aspects)) {
        // Deduplicate: same transit-natal planet pair → keep closest orb
        $unique = [];
        foreach ($aspects as $a) {
            $key = $a['transit_planet'] . '_' . $a['natal_planet'] . '_' . $a['aspect'];
            if (!isset($unique[$key]) || $a['orb'] < $unique[$key]['orb']) {
                $unique[$key] = $a;
            }
        }
        $aspects = array_values($unique);

        // Separate harmonious vs challenging
        $harmonious = array_filter($aspects, function($a) { return $a['nature'] === 'harmonious'; });
        $challenging = array_filter($aspects, function($a) { return $a['nature'] === 'challenging'; });

        if (!empty($harmonious)) {
            $parts[] = "";
            $parts[] = "### ✅ Góc chiếu THUẬN LỢI giữa sao transit và bản đồ natal của user:";
            $parts[] = "_(Đây là những ảnh hưởng tích cực — cơ hội, hỗ trợ, dòng chảy dễ dàng)_";
            $count = 0;
            foreach ($harmonious as $a) {
                if ($count >= 10) { $parts[] = "- _(và thêm " . (count($harmonious) - 10) . " góc chiếu nữa)_"; break; }
                $t_vi = $planet_vi[$a['transit_planet']] ?? $a['transit_planet'];
                $n_vi = $planet_vi[$a['natal_planet']] ?? $a['natal_planet'];
                $asp_vi = $aspect_vi[$a['aspect']] ?? $a['aspect'];
                $exact = $a['is_exact'] ? ' ⭐ GÓC CHIẾU CHÍNH XÁC (rất mạnh)' : '';
                $retro = $a['transit_retro'] ? ' ℞' : '';
                $t_sign_vi = $to_vi($a['transit_sign']);
                $n_sign_vi = $to_vi($a['natal_sign']);
                $n_deg = round($a['natal_degree'] ?? 0, 1);
                $parts[] = "- Sao **{$t_vi}** transit{$retro} (đang ở **{$t_sign_vi}**) tạo góc **{$asp_vi}** với **{$n_vi}** natal (ở **{$n_sign_vi}** {$n_deg}°) — orb {$a['orb']}°{$exact}";
                $count++;
            }
        }

        if (!empty($challenging)) {
            $parts[] = "";
            $parts[] = "### ⚡ Góc chiếu THÁCH THỨC giữa sao transit và bản đồ natal của user:";
            $parts[] = "_(Đây là những ảnh hưởng căng thẳng — thúc đẩy thay đổi, cần chú ý)_";
            $count = 0;
            foreach ($challenging as $a) {
                if ($count >= 10) { $parts[] = "- _(và thêm " . (count($challenging) - 10) . " góc chiếu nữa)_"; break; }
                $t_vi = $planet_vi[$a['transit_planet']] ?? $a['transit_planet'];
                $n_vi = $planet_vi[$a['natal_planet']] ?? $a['natal_planet'];
                $asp_vi = $aspect_vi[$a['aspect']] ?? $a['aspect'];
                $exact = $a['is_exact'] ? ' ⭐ GÓC CHIẾU CHÍNH XÁC (rất mạnh)' : '';
                $retro = $a['transit_retro'] ? ' ℞' : '';
                $t_sign_vi = $to_vi($a['transit_sign']);
                $n_sign_vi = $to_vi($a['natal_sign']);
                $n_deg = round($a['natal_degree'] ?? 0, 1);
                $parts[] = "- Sao **{$t_vi}** transit{$retro} (đang ở **{$t_sign_vi}**) tạo góc **{$asp_vi}** với **{$n_vi}** natal (ở **{$n_sign_vi}** {$n_deg}°) — orb {$a['orb']}°{$exact}";
                $count++;
            }
        }
    }

    // ── Slow planet transit highlights (most impactful long-term) ──
    $slow_planets = ['Jupiter', 'Saturn', 'Uranus', 'Neptune', 'Pluto'];
    $slow_transits = array_filter($aspects, function($a) use ($slow_planets) { return in_array($a['transit_planet'], $slow_planets); });
    if (!empty($slow_transits) && count($snapshots) > 1) {
        $parts[] = "";
        $parts[] = "### 🌍 Sao chậm đang dịch chuyển (ảnh hưởng SÂU và DÀI HẠN):";
        $shown = [];
        foreach ($slow_transits as $a) {
            $key = $a['transit_planet'];
            if (isset($shown[$key])) continue;
            $shown[$key] = true;
            $t_vi = $planet_vi[$key] ?? $key;
            $n_vi = $planet_vi[$a['natal_planet']] ?? $a['natal_planet'];
            $asp_vi = $aspect_vi[$a['aspect']] ?? $a['aspect'];
            $nature_label = $a['nature'] === 'harmonious' ? 'thuận lợi' : 'thách thức';
            $t_sign_vi = $to_vi($a['transit_sign']);
            $n_sign_vi = $to_vi($a['natal_sign']);
            $n_deg = round($a['natal_degree'] ?? 0, 1);
            $parts[] = "- **{$t_vi}** đang transit qua **{$t_sign_vi}** → tạo góc {$asp_vi} ({$nature_label}) với **{$n_vi}** natal (ở **{$n_sign_vi}** {$n_deg}°) — ảnh hưởng kéo dài nhiều tháng";
        }
    }

    // ── Interpretation guide for AI — STRICT ──
    $parts[] = "";
    $parts[] = "### 📝 CÁCH DIỄN GIẢI (CHO AI):";
    $parts[] = "Bạn PHẢI diễn giải DỰA TRÊN dữ liệu các sao và góc chiếu ở trên. Cấu trúc trả lời:";
    $parts[] = "1. **Mở đầu**: Nêu bối cảnh bầu trời — sao nào đang ở cung nào, sự dịch chuyển nổi bật nhất.";
    $parts[] = "2. **Phân tích**: Với mỗi góc chiếu quan trọng (transit → natal), nêu RÕ vị trí natal của user (ví dụ: 'Mặt Trăng natal của bạn ở Song Ngư 15°') rồi so sánh với sao transit.";
    $parts[] = "   - Conjunction (Hợp 0°): năng lượng hợp nhất, khởi đầu mới";
    $parts[] = "   - Trine (Tam hợp 120°) & Sextile (Lục hợp 60°): thuận lợi, cơ hội tự nhiên";
    $parts[] = "   - Square (Vuông 90°): căng thẳng, thách thức nhưng thúc đẩy hành động";
    $parts[] = "   - Opposition (Đối 180°): đối lập, cần cân bằng hai mặt";
    $parts[] = "3. **Lời khuyên**: Dựa trên tổng hợp các transit, đưa ra gợi ý cụ thể và thực tế.";
    $parts[] = "";
    $parts[] = "⛔ TUYỆT ĐỐI KHÔNG dùng Tarot, bói bài, rút lá, hay bất kỳ phương pháp nào ngoài Transit Astrology.";
    $parts[] = "⛔ TUYỆT ĐỐI KHÔNG bịa vị trí sao hay góc chiếu — chỉ dùng dữ liệu ĐÃ CUNG CẤP ở trên.";
    $parts[] = "✅ Luôn NÊU CỤ THỂ vị trí natal (cung + độ) và vị trí transit khi phân tích — KHÔNG dùng cách nói chung chung 'natal của bạn'.";
    $parts[] = "✅ Ví dụ ĐÚNG: 'Sao Diêm Vương transit ở Bảo Bình đang tạo Lục hợp 60° với Mặt Trăng natal của bạn ở Song Ngư 15°'.";
    $parts[] = "✅ Ví dụ SAI: 'Sao Diêm Vương tạo góc tốt với Mặt Trăng natal của bạn' (thiếu cung, thiếu độ).";

    return implode("\n", $parts);
}

/* =====================================================================
 * CACHING & CLEANUP
 * =====================================================================*/

/**
 * Clear transit cache for a user (e.g., when natal chart changes)
 */
function bccm_transit_clear_cache($coachee_id) {
    $periods = ['day', 'week', 'month', 'year', '5year', 'custom_year'];
    $today = current_time('Y-m-d');
    foreach ($periods as $p) {
        delete_transient('bccm_transit_' . $coachee_id . '_' . $p . '_' . $today);
    }
}

/* =====================================================================
 * TRANSIT DB STORAGE: Lưu snapshot vào DB — gọi khi tạo bản đồ sao
 * =====================================================================*/

/**
 * Save one transit snapshot (planet positions + aspects) to bccm_transit_snapshots.
 * Insert on first call for a (coachee_id, target_date), update on subsequent calls.
 */
function bccm_transit_save_snapshot($coachee_id, $user_id, $target_date, $label, $positions, $aspects) {
    // [2026-07-06 Johnny Chu] HOTFIX — Transit writer unification: default-disabled unless explicitly enabled.
    if ( ! defined( 'BCPRO_LEGACY_TRANSIT_PREFETCH_ENABLED' ) || ! BCPRO_LEGACY_TRANSIT_PREFETCH_ENABLED ) {
        return false;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'bccm_transit_snapshots';

    // [2026-06-28 Johnny Chu] HOTFIX R-SHOW-TABLES — use information_schema instead of SHOW TABLES.
    if ( ! $wpdb->get_var( $wpdb->prepare(
        'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
        $table
    ) ) ) {
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            coachee_id   BIGINT UNSIGNED NOT NULL,
            user_id      BIGINT UNSIGNED NULL DEFAULT NULL,
            target_date  DATE NOT NULL,
            label        VARCHAR(64) NOT NULL DEFAULT '',
            source_marker VARCHAR(32) NOT NULL DEFAULT '',
            planets_json LONGTEXT NULL,
            aspects_json LONGTEXT NULL,
            fetched_at   DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_coachee_date (coachee_id, target_date),
            KEY idx_user_id (user_id),
            KEY idx_source_marker (source_marker),
            KEY idx_target_date (target_date)
        ) {$charset_collate};";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        // If still not created, abort — use information_schema check.
        if ( ! $wpdb->get_var( $wpdb->prepare(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
            $table
        ) ) ) {
            error_log("[bccm_transit] Failed to auto-create table {$table}");
            return false;
        }
        error_log("[bccm_transit] Auto-created table {$table}");
    }

    $data = [
        'coachee_id'   => (int) $coachee_id,
        'user_id'      => $user_id ? (int) $user_id : null,
        'target_date'  => $target_date,
        'label'        => $label,
        // [2026-07-06 Johnny Chu] PHASE-FAA2-TWINBRAIN A10 — explicit `_v2` writer marker for audit trail.
        'source_marker'=> 'legacy_prefetch_v2',
        'planets_json' => wp_json_encode($positions, JSON_UNESCAPED_UNICODE),
        'aspects_json' => wp_json_encode($aspects,   JSON_UNESCAPED_UNICODE),
        'fetched_at'   => current_time('mysql'),
    ];

    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$table} WHERE coachee_id = %d AND target_date = %s",
        $coachee_id, $target_date
    ));

    if ($existing) {
        $wpdb->update($table, $data, ['id' => (int) $existing]);
    } else {
        $wpdb->insert($table, $data);
    }

    // [2026-06-04 Johnny Chu] PHASE-A C.3b — fire cache invalidation so the
    // public /my-transit/ router + legacy build_context transients refresh
    // with the new snapshot. Listener: BizCoach_Pro_Transit_Public_Router::invalidate_for_coachee().
    do_action('bccm_transit_snapshot_saved', (int) $coachee_id, $user_id ? (int) $user_id : 0, $target_date);

    return true;
}

/**
 * Pre-fetch transit snapshots for today, +7, +30, +90, +365 days and persist to DB.
 * Called once when a natal chart is created/updated — replaces live API calls at chat time.
 *
 * @param int $coachee_id
 * @param int $user_id
 */
function bccm_transit_prefetch_for_coachee($coachee_id, $user_id = 0) {
    // [2026-07-06 Johnny Chu] HOTFIX — Transit writer unification: default-disabled unless explicitly enabled.
    if ( ! defined( 'BCPRO_LEGACY_TRANSIT_PREFETCH_ENABLED' ) || ! BCPRO_LEGACY_TRANSIT_PREFETCH_ENABLED ) {
        error_log('[bccm_transit] Legacy prefetch runner skipped (BCPRO_LEGACY_TRANSIT_PREFETCH_ENABLED=false).');
        return;
    }

    global $wpdb;

    // Load natal chart row (needs traits/positions to compute aspects)
    $t_astro   = $wpdb->prefix . 'bccm_astro';
    $natal_row = null;

    // Schema-aware guards — một số subsite cũ chưa có cột user_id / chart_type.
    $has_user_id    = function_exists('bccm_astro_supports_user_id')    ? bccm_astro_supports_user_id()    : true;
    $has_chart_type = function_exists('bccm_astro_supports_chart_type') ? bccm_astro_supports_chart_type() : true;
    $chart_type_clause = $has_chart_type ? "AND chart_type = 'western'" : '';

    if ($user_id && $has_user_id) {
        $natal_row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$t_astro} WHERE user_id = %d {$chart_type_clause} AND traits IS NOT NULL ORDER BY updated_at DESC LIMIT 1",
            $user_id
        ));
    }
    if (!$natal_row && $coachee_id) {
        $natal_row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$t_astro} WHERE coachee_id = %d {$chart_type_clause} AND traits IS NOT NULL ORDER BY updated_at DESC LIMIT 1",
            $coachee_id
        ));
    }

    if (!$natal_row || empty($natal_row->traits)) {
        error_log("[bccm_transit] Prefetch aborted: no natal chart for coachee_id={$coachee_id}");
        return;
    }

    $natal_traits    = json_decode($natal_row->traits, true);
    $natal_positions = $natal_traits['positions'] ?? [];
    if (empty($natal_positions)) {
        error_log("[bccm_transit] Prefetch aborted: no positions in traits for coachee_id={$coachee_id}");
        return;
    }

    $latitude  = floatval($natal_row->latitude  ?: 21.0285);
    $longitude = floatval($natal_row->longitude ?: 105.8542);
    $timezone  = floatval($natal_row->timezone  ?: 7.0);
    $now_ts    = current_time('timestamp');

    $prefetch = [
        ['date' => date('Y-m-d', $now_ts),                        'label' => 'Hôm nay'],
        ['date' => date('Y-m-d', strtotime('+1 day',    $now_ts)), 'label' => 'Ngày mai (+1 ngày)'],
        ['date' => date('Y-m-d', strtotime('+7 days',   $now_ts)), 'label' => '+7 ngày'],
        ['date' => date('Y-m-d', strtotime('+30 days',  $now_ts)), 'label' => '+30 ngày (1 tháng)'],
        ['date' => date('Y-m-d', strtotime('+90 days',  $now_ts)), 'label' => '+90 ngày (3 tháng)'],
        ['date' => date('Y-m-d', strtotime('+365 days', $now_ts)), 'label' => '+365 ngày (1 năm)'],
    ];

    $saved = $failed = 0;
    foreach ($prefetch as $item) {
        $result = bccm_transit_fetch_planets($item['date'], $latitude, $longitude, $timezone);
        if (is_wp_error($result)) {
            error_log("[bccm_transit] API error for date={$item['date']}: " . $result->get_error_message());
            $failed++;
            continue;
        }

        $positions = $result['parsed']['positions'] ?? [];
        if (empty($positions)) { $failed++; continue; }

        $aspects = bccm_transit_calc_aspects($positions, $natal_positions);
        bccm_transit_save_snapshot($coachee_id, $user_id, $item['date'], $item['label'], $positions, $aspects);
        $saved++;
    }

    error_log( "[bccm_transit] Prefetch done for coachee_id={$coachee_id}: saved={$saved}, failed={$failed}"
        . ' blog_id=' . (int) get_current_blog_id() );

    // [2026-06-28 Johnny Chu] HOTFIX — set/refresh cooldown transient after cron run
    // (even if all failed) so the report page won't re-schedule immediately on next visit.
    // This is the server-side twin of the anti-spam guard in astro-transit-report.php.
    $cd_key = 'bcpro_tr_pf_cd_' . (int) get_current_blog_id() . '_' . $coachee_id;
    // If saved>0: cache for 1 hour (data exists, no rush). If saved=0: 30 min retry window.
    $cd_ttl = $saved > 0 ? HOUR_IN_SECONDS : 30 * MINUTE_IN_SECONDS;
    set_transient( $cd_key, 1, $cd_ttl );
}

/* =====================================================================
 * WP CRON: Background prefetch callback
 * =====================================================================*/
add_action('bccm_transit_prefetch_cron', function ($coachee_id, $user_id = 0) {
    // [2026-07-06 Johnny Chu] HOTFIX — hard guard: default-disabled, only run when explicitly enabled.
    if ( ! defined( 'BCPRO_LEGACY_TRANSIT_PREFETCH_ENABLED' ) || ! BCPRO_LEGACY_TRANSIT_PREFETCH_ENABLED ) {
        return;
    }
    bccm_transit_prefetch_for_coachee((int) $coachee_id, (int) $user_id);
}, 10, 2);
