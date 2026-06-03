<?php
/**
 * BizCoach Map – Agent Profile Page: /chiem-tinh-profile/
 *
 * Frontend profile chuyên nghiệp cho người dùng:
 * - Khai báo hồ sơ chiêm tinh (ngày sinh, giờ sinh, nơi sinh)
 * - Tạo bản đồ sao Western + Vedic
 * - Xem Big 3 (Sun, Moon, ASC)
 * - Bảng hành tinh, nhà, góc chiếu, Vedic graha, Navamsa
 * - Chart Patterns, Special Features
 * - AI Report links, Transit reports, Natal Chart links
 *
 * Được load trong Touch Bar iframe hoặc trực tiếp trên frontend.
 *
 * @package BizCoach_Map
 */
if ( ! defined( 'ABSPATH' ) ) exit;

#get_header();

$user_id      = get_current_user_id();
$is_logged_in = is_user_logged_in();
?>

<div id="bccm-profile-wrap" style="max-width:780px;margin:30px auto;padding:20px;font-family:Inter,system-ui,-apple-system,sans-serif;">

<?php if ( ! $is_logged_in ): ?>
    <div style="text-align:center;padding:60px 20px;">
        <div style="font-size:48px;margin-bottom:16px;">🔐</div>
        <h2 style="color:#1a1a2e;font-size:22px;">Đăng nhập để tiếp tục</h2>
        <p style="color:#6b7280;margin-bottom:20px;">Bạn cần đăng nhập để khai báo hồ sơ chiêm tinh và sử dụng AI Agent.</p>
        <a href="<?php echo wp_login_url( get_permalink() ); ?>" style="display:inline-block;padding:12px 28px;background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;border-radius:12px;text-decoration:none;font-weight:600;">
            Đăng nhập
        </a>
    </div>
<?php else:
    global $wpdb;
    $t       = bccm_tables();
    $t_astro = $wpdb->prefix . 'bccm_astro';

    // Lấy hoặc tạo hồ sơ
    $coachee    = bccm_get_or_create_user_coachee( $user_id, 'WEBCHAT', 'mental_coach' );
    $coachee_id = $coachee ? (int) $coachee['id'] : 0;

    // Astro data
    $astro_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t_astro WHERE user_id=%d AND chart_type='western'", $user_id ), ARRAY_A );
    $vedic_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t_astro WHERE user_id=%d AND chart_type='vedic'", $user_id ), ARRAY_A );
    if ( ! $astro_row ) $astro_row = $vedic_row;

    $has_profile = ! empty( $coachee['full_name'] ) && ! empty( $coachee['dob'] );
    $has_astro   = ! empty( $astro_row['summary'] ) || ! empty( $astro_row['traits'] );
    $has_vedic   = ! empty( $vedic_row['summary'] ) || ! empty( $vedic_row['traits'] );

    /* ==================== HANDLE POST ACTIONS ==================== */
    $saved_msg = '';
    $error_msg = '';
    if ( ! empty( $_POST['bccm_profile_action'] ) && wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'bccm_frontend_profile' ) ) {
        $data = [
            'full_name'     => sanitize_text_field( $_POST['full_name'] ?? '' ),
            'phone'         => sanitize_text_field( $_POST['phone'] ?? '' ),
            'dob'           => sanitize_text_field( $_POST['dob'] ?? '' ),
            'user_id'       => $user_id,
            'platform_type' => 'WEBCHAT',
        ];

        if ( function_exists( 'bccm_upsert_profile' ) ) {
            $coachee_id = bccm_upsert_profile( $data, $coachee_id );
        }

        // Save birth data
        $birth_place = sanitize_text_field( $_POST['birth_place'] ?? '' );
        $birth_time  = sanitize_text_field( $_POST['birth_time'] ?? '' );
        $latitude    = floatval( $_POST['astro_latitude'] ?? 0 );
        $longitude   = floatval( $_POST['astro_longitude'] ?? 0 );
        $timezone    = floatval( $_POST['astro_timezone'] ?? 7 );

        if ( $birth_place || $birth_time ) {
            $astro_data = [
                'birth_place' => $birth_place,
                'birth_time'  => $birth_time,
                'latitude'    => $latitude,
                'longitude'   => $longitude,
                'timezone'    => $timezone,
                'updated_at'  => current_time( 'mysql' ),
            ];

            $existing = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM $t_astro WHERE user_id=%d AND chart_type='western'", $user_id
            ) );

            if ( $existing ) {
                $wpdb->update( $t_astro, $astro_data, [ 'user_id' => $user_id, 'chart_type' => 'western' ] );
            } else {
                $astro_data['user_id']    = $user_id;
                $astro_data['coachee_id'] = $coachee_id;
                $astro_data['chart_type'] = 'western';
                $astro_data['created_at'] = current_time( 'mysql' );
                $wpdb->insert( $t_astro, $astro_data );
            }
            // Also update vedic row birth data if exists
            $existing_vedic = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM $t_astro WHERE user_id=%d AND chart_type='vedic'", $user_id
            ) );
            if ( $existing_vedic ) {
                $wpdb->update( $t_astro, $astro_data, [ 'user_id' => $user_id, 'chart_type' => 'vedic' ] );
            }
        }

        // Zodiac sign fallback from DOB
        $dob_val = sanitize_text_field( $_POST['dob'] ?? '' );
        if ( $dob_val && empty( $coachee['zodiac_sign'] ) && function_exists( 'bccm_astro_sun_sign_from_dob' ) ) {
            $sun = bccm_astro_sun_sign_from_dob( $dob_val );
            if ( ! empty( $sun['en'] ) ) {
                $wpdb->update( $t['profiles'], [ 'zodiac_sign' => strtolower( $sun['en'] ) ], [ 'id' => $coachee_id ] );
            }
        }

        // Run generator based on action
        $action = sanitize_text_field( $_POST['bccm_profile_action'] );

        // Build birth_data
        $dob_parts = explode( '-', $dob_val );
        $birth_data_ready = false;
        $birth_data = [];
        if ( count( $dob_parts ) === 3 && $birth_time ) {
            $time_parts = explode( ':', $birth_time );
            $birth_data = [
                'year'      => intval( $dob_parts[0] ),
                'month'     => intval( $dob_parts[1] ),
                'day'       => intval( $dob_parts[2] ),
                'hour'      => intval( $time_parts[0] ?? 12 ),
                'minute'    => intval( $time_parts[1] ?? 0 ),
                'second'    => 0,
                'latitude'  => $latitude ?: 21.0285,
                'longitude' => $longitude ?: 105.8542,
                'timezone'  => $timezone ?: 7,
            ];
            $birth_data_ready = true;
        }

        if ( $action === 'gen_western' || $action === 'gen_free_chart' ) {
            if ( $birth_data_ready && function_exists( 'bccm_astro_fetch_full_chart' ) ) {
                $chart_result = bccm_astro_fetch_full_chart( $birth_data );
                if ( is_wp_error( $chart_result ) ) {
                    $error_msg = '❌ Lỗi Western API: ' . $chart_result->get_error_message();
                } else {
                    $birth_input = array_merge( $birth_data, [ 'birth_place' => $birth_place, 'birth_time' => $birth_time ] );
                    bccm_astro_save_chart( $coachee_id, $chart_result, $birth_input, $user_id );
                    $saved_msg = '✅ Đã tạo bản đồ Western Astrology thành công!';
                }
            } else {
                $error_msg = '⚠️ Cần nhập đầy đủ Ngày sinh và Giờ sinh để tạo bản đồ.';
            }

        } elseif ( $action === 'gen_vedic' || $action === 'gen_vedic_chart' ) {
            if ( $birth_data_ready && function_exists( 'bccm_vedic_fetch_full_chart' ) ) {
                $vedic_result = bccm_vedic_fetch_full_chart( $birth_data );
                if ( is_wp_error( $vedic_result ) ) {
                    $error_msg = '❌ Lỗi Vedic API: ' . $vedic_result->get_error_message();
                } else {
                    $birth_input = array_merge( $birth_data, [ 'birth_place' => $birth_place, 'birth_time' => $birth_time ] );
                    bccm_vedic_save_chart( $coachee_id, $vedic_result, $birth_input, $user_id );
                    $saved_msg = '✅ Đã tạo bản đồ Vedic Astrology thành công!';
                }
            } else {
                $error_msg = '⚠️ Cần nhập đầy đủ Ngày sinh và Giờ sinh để tạo bản đồ.';
            }

        } elseif ( $action === 'gen_both' || $action === 'gen_both_charts' ) {
            if ( $birth_data_ready ) {
                $birth_input = array_merge( $birth_data, [ 'birth_place' => $birth_place, 'birth_time' => $birth_time ] );
                $errors  = [];
                $success = [];

                if ( function_exists( 'bccm_astro_fetch_full_chart' ) ) {
                    $chart_result = bccm_astro_fetch_full_chart( $birth_data );
                    if ( is_wp_error( $chart_result ) ) {
                        $errors[] = 'Western: ' . $chart_result->get_error_message();
                    } else {
                        bccm_astro_save_chart( $coachee_id, $chart_result, $birth_input, $user_id );
                        $success[] = 'Western Astrology';
                    }
                }

                if ( function_exists( 'bccm_vedic_fetch_full_chart' ) ) {
                    $vedic_result = bccm_vedic_fetch_full_chart( $birth_data );
                    if ( is_wp_error( $vedic_result ) ) {
                        $errors[] = 'Vedic: ' . $vedic_result->get_error_message();
                    } else {
                        bccm_vedic_save_chart( $coachee_id, $vedic_result, $birth_input, $user_id );
                        $success[] = 'Vedic Astrology';
                    }
                }

                if ( ! empty( $success ) ) {
                    $saved_msg = '✅ Đã tạo bản đồ: ' . implode( ', ', $success );
                }
                if ( ! empty( $errors ) ) {
                    $error_msg = '⚠️ Lỗi: ' . implode( ' | ', $errors );
                }
            } else {
                $error_msg = '⚠️ Cần nhập đầy đủ Ngày sinh và Giờ sinh để tạo bản đồ.';
            }

        } else {
            $saved_msg = '✅ Đã lưu hồ sơ thành công!';
        }

        // Refresh data after actions
        $coachee   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['profiles']} WHERE id=%d", $coachee_id ), ARRAY_A );
        $astro_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t_astro WHERE user_id=%d AND chart_type='western'", $user_id ), ARRAY_A );
        $vedic_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t_astro WHERE user_id=%d AND chart_type='vedic'", $user_id ), ARRAY_A );
        if ( ! $astro_row ) $astro_row = $vedic_row;
        $has_profile = ! empty( $coachee['full_name'] ) && ! empty( $coachee['dob'] );
        $has_astro   = ! empty( $astro_row['summary'] ) || ! empty( $astro_row['traits'] );
        $has_vedic   = ! empty( $vedic_row['summary'] ) || ! empty( $vedic_row['traits'] );
    }

    $v = function( $k ) use ( $coachee ) { return esc_attr( $coachee[ $k ] ?? '' ); };
?>

<style>
#bccm-profile-wrap h2 { font-size:20px; font-weight:700; color:#1a1a2e; margin:0 0 6px; }
.bccm-pf-section { background:#fff; border:1px solid #e5e7eb; border-radius:16px; padding:20px; margin-bottom:16px; }
.bccm-pf-section h3 { margin:0 0 12px; font-size:16px; font-weight:700; }
#bccm-profile-wrap label { display:block; font-size:13px; font-weight:600; color:#374151; margin-bottom:4px; }
#bccm-profile-wrap input[type="text"],
#bccm-profile-wrap input[type="date"],
#bccm-profile-wrap input[type="number"],
#bccm-profile-wrap select { width:100%; padding:10px 12px; border:1px solid #d1d5db; border-radius:10px; font-size:14px; box-sizing:border-box; }
.bccm-pf-row { margin-bottom:12px; }
.bccm-pf-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
.bccm-pf-btn { display:inline-flex; align-items:center; gap:6px; padding:10px 20px; border-radius:10px; border:none; font-size:14px; font-weight:600; cursor:pointer; text-decoration:none; line-height:1.4; }
.bccm-pf-btn-primary { background:linear-gradient(135deg,#6366f1,#8b5cf6); color:#fff; }
.bccm-pf-btn-western { background:#3b82f6; color:#fff; }
.bccm-pf-btn-vedic { background:#7c3aed; color:#fff; }
.bccm-pf-btn-both { background:#059669; color:#fff; }
.bccm-pf-btn-secondary { background:#f1f5f9; color:#1a1a2e; border:1px solid #e5e7eb; }
.bccm-pf-btn:hover { opacity:0.9; }
.bccm-pf-status { display:inline-flex; align-items:center; gap:4px; padding:4px 10px; border-radius:999px; font-size:12px; font-weight:700; }
.bccm-pf-ok { background:#dcfce7; color:#166534; }
.bccm-pf-warn { background:#fef3c7; color:#92400e; }
.bccm-pf-notice { padding:12px 16px; border-radius:10px; font-size:13px; margin-bottom:16px; }
.bccm-pf-notice-ok { background:#dcfce7; color:#166534; }
.bccm-pf-notice-err { background:#fef2f2; color:#991b1b; }
.bccm-pf-notice-warn { background:#fef3c7; color:#92400e; }

/* Natal report tables */
.bccm-natal-table { width:100%; border-collapse:collapse; font-size:13px; }
.bccm-natal-table th { background:#f8fafc; border-bottom:2px solid #e5e7eb; padding:8px 10px; text-align:left; font-weight:600; color:#374151; font-size:12px; text-transform:uppercase; letter-spacing:0.5px; }
.bccm-natal-table td { padding:8px 10px; border-bottom:1px solid #f1f5f9; }
.bccm-row-even { background:#fff; }
.bccm-row-odd { background:#f9fafb; }
.bccm-table-separator td { background:#e5e7eb !important; height:2px !important; padding:0 !important; }
.bccm-aspect-group-header td { background:#f1f5f9; padding:8px 10px !important; border-bottom:1px solid #e5e7eb; }

/* Cards / postbox */
.bccm-postbox { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:20px; margin-top:16px; }
.bccm-postbox h3 { margin:0 0 12px; font-size:16px; }

/* Patterns & Special Features */
.bccm-patterns-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(260px,1fr)); gap:12px; }
.bccm-pattern-card { background:#f9fafb; border:1px solid #e5e7eb; border-radius:12px; padding:16px; }
.bccm-pattern-header { display:flex; align-items:center; gap:8px; margin-bottom:8px; }
.bccm-pattern-icon { font-size:24px; }
.bccm-pattern-type { font-weight:700; color:#1e293b; font-size:15px; }
.bccm-pattern-planets { margin-bottom:8px; }
.bccm-pattern-planet { font-size:12px; color:#6b7280; padding:2px 0; }
.bccm-pattern-desc { font-size:12px; color:#4b5563; line-height:1.5; }
.bccm-special-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(260px,1fr)); gap:10px; }
.bccm-special-card { display:flex; gap:10px; padding:12px; background:#f9fafb; border-radius:10px; border:1px solid #e5e7eb; }
.bccm-special-icon { font-size:24px; flex-shrink:0; }
.bccm-special-main { font-size:13px; color:#1e293b; margin:0 0 4px; }
.bccm-special-sub { font-size:12px; color:#6b7280; margin:0; }

/* Toolbar buttons (smaller) */
.bccm-toolbar-btn { display:inline-flex; align-items:center; gap:4px; padding:7px 12px; border-radius:8px; border:none; font-size:12px; font-weight:600; cursor:pointer; text-decoration:none; color:#fff; line-height:1.3; }
.bccm-toolbar-btn:hover { opacity:0.9; color:#fff; text-decoration:none; }

/* Bottom Nav Bar */
.bccm-nav { position:sticky; top:0; z-index:100; display:flex; background:#fff; border-bottom:1px solid #e5e7eb; box-shadow:0 2px 8px rgba(0,0,0,.06); margin:-20px -20px 20px; }
.bccm-nav-item { flex:1; display:flex; flex-direction:column; align-items:center; padding:10px 4px 8px; text-decoration:none; color:#9ca3af; font-size:11px; font-weight:600; cursor:pointer; transition:color .2s; border:none; background:none; border-bottom:3px solid transparent; }
.bccm-nav-item:hover { color:#6366f1; text-decoration:none; }
.bccm-nav-item.active { color:#6366f1; border-bottom-color:#6366f1; }
.bccm-nav-icon { font-size:20px; line-height:1; margin-bottom:2px; }
.bccm-tab-panel { display:none; }
.bccm-tab-panel.active { display:block; }

/* AI Consult */
.bccm-consult-input { width:100%; padding:14px; border:1px solid #d1d5db; border-radius:12px; font-size:14px; font-family:inherit; resize:vertical; min-height:100px; box-sizing:border-box; }
.bccm-consult-input:focus { outline:none; border-color:#6366f1; box-shadow:0 0 0 3px rgba(99,102,241,.15); }
.bccm-consult-topics { display:flex; gap:8px; flex-wrap:wrap; margin:12px 0; }
.bccm-topic-chip { padding:6px 14px; border-radius:999px; border:1px solid #e5e7eb; background:#f9fafb; font-size:13px; cursor:pointer; transition:all .2s; }
.bccm-topic-chip:hover, .bccm-topic-chip.active { background:#6366f1; color:#fff; border-color:#6366f1; }
.bccm-consult-result { margin-top:16px; padding:20px; background:#f9fafb; border:1px solid #e5e7eb; border-radius:12px; font-size:14px; line-height:1.7; white-space:pre-wrap; word-break:break-word; }
.bccm-consult-history { margin-top:20px; }
.bccm-consult-msg { padding:14px 16px; border-radius:12px; margin-bottom:10px; }
.bccm-consult-msg-user { background:#ede9fe; border:1px solid #c4b5fd; }
.bccm-consult-msg-ai { background:#f0fdf4; border:1px solid #86efac; }
.bccm-consult-msg-label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; margin-bottom:4px; }
.bccm-consult-msg-body { font-size:14px; line-height:1.7; white-space:pre-wrap; word-break:break-word; }

/* Responsive */
@media (max-width:640px) {
    .bccm-pf-grid { grid-template-columns:1fr; }
    .bccm-patterns-grid, .bccm-special-grid { grid-template-columns:1fr; }
    .bccm-dual-grid { grid-template-columns:1fr !important; }
    .bccm-big3-grid { grid-template-columns:1fr !important; }
    .bccm-nav-item { font-size:10px; padding:8px 2px 6px; }
    .bccm-nav-icon { font-size:18px; }
}
</style>

    <!-- Navigation Bar -->
    <nav class="bccm-nav">
        <button class="bccm-nav-item active" data-tab="profile">
            <span class="bccm-nav-icon">🌟</span><span>Hồ sơ</span>
        </button>
        <button class="bccm-nav-item" data-tab="consult">
            <span class="bccm-nav-icon">🔮</span><span>Hỏi chuyên gia</span>
        </button>
    </nav>

    <!-- ══════════════ TAB 1: PROFILE ══════════════ -->
    <div class="bccm-tab-panel active" id="bccm-tab-profile">

    <!-- Status overview -->
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;flex-wrap:wrap;">
        <h2>🌟 Hồ sơ Chiêm tinh</h2>
        <span class="bccm-pf-status <?php echo $has_profile ? 'bccm-pf-ok' : 'bccm-pf-warn'; ?>">
            <?php echo $has_profile ? '✅ Đã khai báo' : '⚠️ Chưa khai báo'; ?>
        </span>
        <?php if ( $has_astro ): ?>
            <span class="bccm-pf-status bccm-pf-ok">🌟 Western</span>
        <?php endif; ?>
        <?php if ( $has_vedic ): ?>
            <span class="bccm-pf-status bccm-pf-ok">🕉️ Vedic</span>
        <?php endif; ?>
    </div>

    <?php if ( $saved_msg ): ?>
        <div class="bccm-pf-notice bccm-pf-notice-ok"><?php echo esc_html( $saved_msg ); ?></div>
    <?php endif; ?>
    <?php if ( $error_msg ): ?>
        <div class="bccm-pf-notice bccm-pf-notice-err"><?php echo esc_html( $error_msg ); ?></div>
    <?php endif; ?>

    <?php if ( ! $has_profile ): ?>
        <div class="bccm-pf-notice bccm-pf-notice-warn">
            ⚠️ <strong>Hồ sơ chưa đầy đủ.</strong> AI Agent cần thông tin ngày sinh, giờ sinh &amp; nơi sinh để phân tích chiêm tinh chính xác.
        </div>
    <?php endif; ?>

    <form method="post">
        <?php wp_nonce_field( 'bccm_frontend_profile' ); ?>

        <!-- Thông tin cá nhân -->
        <div class="bccm-pf-section">
            <h3>👤 Thông tin cá nhân</h3>
            <div class="bccm-pf-row">
                <label>Họ tên</label>
                <input type="text" name="full_name" value="<?php echo $v( 'full_name' ); ?>" placeholder="Nhập họ tên..." />
            </div>
            <div class="bccm-pf-grid">
                <div class="bccm-pf-row">
                    <label>Số điện thoại</label>
                    <input type="text" name="phone" value="<?php echo $v( 'phone' ); ?>" placeholder="0xxx..." />
                </div>
                <div class="bccm-pf-row">
                    <label>Ngày sinh</label>
                    <input type="date" name="dob" value="<?php echo $v( 'dob' ); ?>" />
                </div>
            </div>
            <?php
            // Zodiac sign (read-only)
            $zodiac = $coachee['zodiac_sign'] ?? '';
            if ( $zodiac && function_exists( 'bccm_zodiac_signs' ) ) {
                $signs = bccm_zodiac_signs();
                $sign_info = null;
                foreach ( $signs as $s ) {
                    if ( strtolower( $s['en'] ?? '' ) === strtolower( $zodiac ) ) { $sign_info = $s; break; }
                }
                $zodiac_display = $sign_info ? ( $sign_info['symbol'] . ' ' . $sign_info['vi'] . ' (' . $sign_info['en'] . ')' ) : $zodiac;
                echo '<div class="bccm-pf-row"><label>Cung hoàng đạo</label>';
                echo '<div style="padding:10px 12px;background:#f9fafb;border-radius:10px;font-weight:600;color:#6366f1">' . esc_html( $zodiac_display ) . ' <span style="color:#9ca3af;font-weight:400;font-size:12px">(tự nhận diện từ ngày sinh)</span></div>';
                echo '</div>';
            }
            ?>
        </div>

        <!-- Dữ liệu chiêm tinh -->
        <div class="bccm-pf-section">
            <h3>🌟 Dữ liệu chiêm tinh</h3>
            <p style="color:#6b7280;font-size:13px;margin:0 0 12px;">Thông tin ngày giờ và nơi sinh giúp AI tạo bản đồ sao chính xác.</p>

            <div class="bccm-pf-row">
                <label>Nơi sinh</label>
                <div style="display:flex;gap:8px;">
                    <input type="text" name="birth_place" id="bccm_birth_place" value="<?php echo esc_attr( $astro_row['birth_place'] ?? '' ); ?>" placeholder="VD: Hà Nội, Việt Nam" style="flex:1" />
                    <button type="button" id="bccm_geo_lookup_btn" class="bccm-pf-btn bccm-pf-btn-secondary" style="padding:10px 14px;white-space:nowrap;">📍 Tìm tọa độ</button>
                </div>
                <span id="bccm_geo_status" style="font-size:12px;color:#6b7280;margin-top:4px;display:block;"></span>
            </div>

            <div class="bccm-pf-grid">
                <div class="bccm-pf-row">
                    <label>Giờ sinh (24h)</label>
                    <input type="text" name="birth_time" value="<?php echo esc_attr( $astro_row['birth_time'] ?? '' ); ?>" placeholder="VD: 14:30" />
                </div>
                <div class="bccm-pf-row">
                    <label>Múi giờ</label>
                    <select name="astro_timezone" id="bccm_astro_tz">
                        <?php
                        $current_tz = $astro_row['timezone'] ?? 7;
                        for ( $tz = -12; $tz <= 14; $tz++ ) {
                            $label = ( $tz >= 0 ? '+' : '' ) . $tz;
                            printf( '<option value="%s"%s>UTC%s</option>', $tz, selected( $current_tz, $tz, false ), $label );
                        }
                        ?>
                    </select>
                </div>
            </div>

            <div class="bccm-pf-grid">
                <div class="bccm-pf-row">
                    <label>Vĩ độ (Latitude)</label>
                    <input type="number" step="any" name="astro_latitude" id="bccm_astro_lat" value="<?php echo esc_attr( $astro_row['latitude'] ?? '' ); ?>" placeholder="VD: 21.0285" />
                </div>
                <div class="bccm-pf-row">
                    <label>Kinh độ (Longitude)</label>
                    <input type="number" step="any" name="astro_longitude" id="bccm_astro_lng" value="<?php echo esc_attr( $astro_row['longitude'] ?? '' ); ?>" placeholder="VD: 105.8542" />
                </div>
            </div>
        </div>

        <!-- Action buttons -->
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px;">
            <button type="submit" name="bccm_profile_action" value="save_only" class="bccm-pf-btn bccm-pf-btn-primary">💾 Lưu hồ sơ</button>
            <span style="border-left:2px solid #e5e7eb;height:28px;margin:4px 2px"></span>
            <button type="submit" name="bccm_profile_action" value="gen_western" class="bccm-pf-btn bccm-pf-btn-western">🌟 Tạo Western</button>
            <button type="submit" name="bccm_profile_action" value="gen_vedic" class="bccm-pf-btn bccm-pf-btn-vedic">🕉️ Tạo Vedic</button>
            <button type="submit" name="bccm_profile_action" value="gen_both" class="bccm-pf-btn bccm-pf-btn-both">⚡ Tạo cả 2</button>
        </div>
        <p style="font-size:12px;color:#6b7280;margin:0 0 20px;">💡 Tạo 2 bộ bản đồ (Western + Vedic) để AI Agent hiểu bạn toàn diện hơn.</p>
    </form>

    <?php
    /* ==================== ASTRO RESULTS ==================== */
    $astro_summary = ! empty( $astro_row['summary'] ) ? json_decode( $astro_row['summary'], true ) : [];
    $astro_traits  = ! empty( $astro_row['traits'] )  ? json_decode( $astro_row['traits'], true ) : [];
    $vedic_summary = ! empty( $vedic_row['summary'] ) ? json_decode( $vedic_row['summary'], true ) : [];
    $vedic_traits  = ! empty( $vedic_row['traits'] )  ? json_decode( $vedic_row['traits'], true ) : [];
    $has_western   = ! empty( $astro_summary ) || ! empty( $astro_traits );
    $has_vedic_r   = ! empty( $vedic_summary ) || ! empty( $vedic_traits );

    if ( $has_western || $has_vedic_r ):
    ?>

    <!-- ══════════════ HEADER + TOOLBAR ══════════════ -->
    <div class="bccm-natal-header" style="padding:16px 0;">
        <h2 style="margin:0 0 6px;font-size:22px;color:#1a1a2e">
            🌟 Bản Đồ Sao Cá Nhân
            <small style="font-weight:400;color:#6b7280;font-size:13px">(Natal Chart — Western + Vedic)</small>
        </h2>
        <?php
        $birth_data_display = $astro_traits['birth_data'] ?? $vedic_traits['birth_data'] ?? [];
        if ( ! empty( $birth_data_display ) || ! empty( $astro_row['birth_place'] ) ):
        ?>
        <div style="display:flex;gap:16px;flex-wrap:wrap;font-size:13px;color:#4b5563;margin-top:4px">
            <?php if ( ! empty( $coachee['full_name'] ) ) echo '<span>👤 ' . esc_html( $coachee['full_name'] ) . '</span>'; ?>
            <?php
            $dob_display = '';
            if ( ! empty( $birth_data_display['day'] ) && ! empty( $birth_data_display['month'] ) && ! empty( $birth_data_display['year'] ) ) {
                $dob_display = sprintf( '%02d/%02d/%04d', $birth_data_display['day'], $birth_data_display['month'], $birth_data_display['year'] );
            } elseif ( ! empty( $coachee['dob'] ) ) {
                $dob_display = date( 'd/m/Y', strtotime( $coachee['dob'] ) );
            }
            if ( $dob_display ) echo '<span>📅 ' . esc_html( $dob_display ) . '</span>';
            if ( ! empty( $astro_row['birth_time'] ) ) echo '<span>🕐 ' . esc_html( $astro_row['birth_time'] ) . '</span>';
            if ( ! empty( $astro_row['birth_place'] ) ) echo '<span>📍 ' . esc_html( $astro_row['birth_place'] ) . '</span>';
            ?>
        </div>
        <?php endif; ?>

        <!-- Toolbar -->
        <div style="margin-top:10px;display:flex;gap:6px;flex-wrap:wrap;align-items:center">
            <?php
            // Public Natal Chart link
            $natal_url = '';
            if ( $has_western || $has_vedic_r ) {
                $natal_url = function_exists( 'bccm_get_natal_chart_url_by_user' ) ? bccm_get_natal_chart_url_by_user( $user_id ) : '';
                if ( ! $natal_url && function_exists( 'bccm_get_natal_chart_public_url' ) ) {
                    $natal_url = bccm_get_natal_chart_public_url( $coachee_id );
                }
                if ( $natal_url ) {
                    echo '<a href="' . esc_url( $natal_url ) . '" target="_blank" class="bccm-toolbar-btn" style="background:#10b981;">🌟 Xem Bản Đồ Sao</a>';
                    echo '<span style="border-left:2px solid #e5e7eb;height:22px;margin:0 2px"></span>';
                }
            }

            // AI Report links
            if ( $has_western ) {
                echo '<a href="' . esc_url( admin_url( 'admin-ajax.php?action=bccm_natal_report_full&coachee_id=' . $coachee_id . '&chart_type=western&_wpnonce=' . wp_create_nonce( 'bccm_natal_report_full' ) ) ) . '" target="_blank" class="bccm-toolbar-btn" style="background:#3b82f6;">🤖 Luận Giải Western</a>';
                echo '<a href="' . esc_url( admin_url( 'admin-ajax.php?action=bccm_natal_report_full&coachee_id=' . $coachee_id . '&chart_type=western&regenerate=1&_wpnonce=' . wp_create_nonce( 'bccm_natal_report_full' ) ) ) . '" target="_blank" class="bccm-toolbar-btn" style="background:#f59e0b;" title="Tạo lại báo cáo">🔄 Tạo lại</a>';
            }
            if ( $has_vedic_r ) {
                echo '<a href="' . esc_url( admin_url( 'admin-ajax.php?action=bccm_natal_report_full&coachee_id=' . $coachee_id . '&chart_type=vedic&_wpnonce=' . wp_create_nonce( 'bccm_natal_report_full' ) ) ) . '" target="_blank" class="bccm-toolbar-btn" style="background:#7c3aed;">🕉️ Luận Giải Vedic</a>';
            }

            // Transit buttons
            if ( $has_western ) {
                $transit_nonce = wp_create_nonce( 'bccm_transit_report' );
                echo '<span style="border-left:2px solid #e5e7eb;height:22px;margin:0 2px"></span>';
                echo '<a href="' . esc_url( admin_url( 'admin-ajax.php?action=bccm_transit_report&coachee_id=' . $coachee_id . '&period=week&_wpnonce=' . $transit_nonce ) ) . '" target="_blank" class="bccm-toolbar-btn" style="background:#0ea5e9;" title="Transit 7 ngày tới">🔮 Tuần</a>';
                echo '<a href="' . esc_url( admin_url( 'admin-ajax.php?action=bccm_transit_report&coachee_id=' . $coachee_id . '&period=month&_wpnonce=' . $transit_nonce ) ) . '" target="_blank" class="bccm-toolbar-btn" style="background:#8b5cf6;" title="Transit 30 ngày tới">🔮 Tháng</a>';
                echo '<a href="' . esc_url( admin_url( 'admin-ajax.php?action=bccm_transit_report&coachee_id=' . $coachee_id . '&period=year&_wpnonce=' . $transit_nonce ) ) . '" target="_blank" class="bccm-toolbar-btn" style="background:#059669;" title="Transit 12 tháng tới">🔮 Năm</a>';
            }
            ?>
        </div>

        <!-- Sources -->
        <div style="font-size:11px;color:#9ca3af;margin-top:8px;">
            Sources:
            <?php if ( $has_western ) echo '<span style="color:#3b82f6">● Western</span> '; ?>
            <?php if ( $has_vedic_r ) echo '<span style="color:#7c3aed">● Vedic</span> '; ?>
            <span style="color:#f59e0b">● AI Report (GPT)</span>
            <?php if ( $has_western ) echo ' <span style="color:#0ea5e9">● Transit</span>'; ?>
        </div>

        <?php if ( ! empty( $natal_url ) ): ?>
        <div style="margin-top:12px;padding:12px;background:#f0fdf4;border:1px solid #86efac;border-radius:8px">
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                <strong style="color:#166534;font-size:12px">🌐 Bản đồ sao công khai:</strong>
                <input type="text" readonly value="<?php echo esc_attr( $natal_url ); ?>" style="flex:1;min-width:180px;padding:6px 10px;border:1px solid #86efac;border-radius:4px;font-size:11px;font-family:monospace;background:#fff" onclick="this.select()"/>
                <a href="<?php echo esc_url( $natal_url ); ?>" target="_blank" class="bccm-toolbar-btn" style="background:#10b981;">🔗 Xem</a>
                <button type="button" class="bccm-toolbar-btn" style="background:#6b7280;" onclick="navigator.clipboard.writeText('<?php echo esc_js( $natal_url ); ?>'); this.textContent='✅ Đã copy!'">📋 Copy</button>
            </div>
            <p style="margin:6px 0 0;font-size:11px;color:#15803d">💡 Link này có thể chia sẻ công khai, không cần đăng nhập.</p>
        </div>
        <?php endif; ?>
    </div>

    <!-- ══════════════ BIG 3 CARDS ══════════════ -->
    <?php
    $sun_free  = $astro_summary['sun_sign'] ?? '';
    $moon_free = $astro_summary['moon_sign'] ?? '';
    $asc_free  = $astro_summary['ascendant_sign'] ?? '';
    $sun_vedic_s  = $vedic_summary['sun_sign'] ?? '';
    $moon_vedic_s = $vedic_summary['moon_sign'] ?? '';
    $asc_vedic_s  = $vedic_summary['ascendant_sign'] ?? '';

    $sun  = $sun_free ?: $sun_vedic_s;
    $moon = $moon_free ?: $moon_vedic_s;
    $asc  = $asc_free ?: $asc_vedic_s;

    if ( $sun || $moon || $asc ):
        $signs_all = function_exists( 'bccm_zodiac_signs' ) ? bccm_zodiac_signs() : [];
        $find_sign = function( $name ) use ( $signs_all ) {
            foreach ( $signs_all as $s ) {
                if ( strtolower( $s['en'] ?? '' ) === strtolower( $name ) ) return $s;
            }
            return [ 'vi' => $name, 'symbol' => '?', 'en' => $name, 'element' => '' ];
        };
    ?>
    <div class="bccm-big3-grid" style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin:16px 0 20px">
        <?php
        $big3_items = [
            [ '☀️ Mặt Trời (Sun)',   $sun,  'Bản ngã, ý chí, mục đích sống',         [ '#1a1a2e', '#2d1b69' ] ],
            [ '🌙 Mặt Trăng (Moon)', $moon, 'Cảm xúc, nhu cầu nội tâm, bản năng',    [ '#1a2e2e', '#1b4d69' ] ],
            [ '⬆️ Cung Mọc (ASC)',   $asc,  'Ấn tượng đầu tiên, vẻ ngoài, tiếp cận', [ '#2e1a2e', '#691b4d' ] ],
        ];
        foreach ( $big3_items as $item ):
            $info = $find_sign( $item[1] );
        ?>
        <div style="background:linear-gradient(135deg,<?php echo $item[3][0]; ?>,<?php echo $item[3][1]; ?>);color:#fff;padding:18px;border-radius:12px;text-align:center;box-shadow:0 4px 12px rgba(0,0,0,0.15)">
            <div style="font-size:11px;color:#94a3b8;text-transform:uppercase;letter-spacing:1px"><?php echo $item[0]; ?></div>
            <div style="font-size:32px;margin:8px 0"><?php echo esc_html( $info['symbol'] ?? '?' ); ?></div>
            <div style="font-size:18px;font-weight:700;color:#fbbf24"><?php echo esc_html( $info['vi'] ?? $item[1] ); ?></div>
            <div style="font-size:12px;color:#94a3b8;margin-top:2px"><?php echo esc_html( $info['en'] ?? '' ); ?></div>
            <div style="font-size:11px;color:#6ee7b7;margin-top:6px"><?php echo esc_html( $item[2] ); ?></div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ══════════════ DUAL CHART DISPLAY ══════════════ -->
    <div class="bccm-dual-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin:20px 0">

        <!-- LEFT: Western Chart -->
        <div class="bccm-postbox">
            <h3 style="color:#3b82f6">🌟 Western Astrology <small style="font-weight:400;color:#888;font-size:12px">(Tropical — Placidus)</small></h3>
            <?php if ( $has_western ):
                $chart_url = $astro_row['chart_svg'] ?? $astro_summary['chart_url'] ?? '';
                if ( $chart_url ): ?>
                <div style="text-align:center;margin:12px 0">
                    <img src="<?php echo esc_url( $chart_url ); ?>" alt="Western Natal Wheel" style="max-width:100%;border-radius:12px;box-shadow:0 4px 16px rgba(0,0,0,0.15)"/>
                    <p style="font-size:11px;color:#6b7280;margin-top:6px">Natal Wheel — Placidus</p>
                </div>
                <?php endif; ?>
                <p style="color:#22c55e;font-weight:600;margin:8px 0 4px;">✅ Dữ liệu đã có</p>
                <p style="font-size:12px;color:#6b7280;margin:0 0 4px;">Fetched: <?php echo esc_html( $astro_summary['fetched_at'] ?? '—' ); ?></p>
                <?php
                $has_llm_western = ! empty( $astro_row['llm_report'] );
                if ( $has_llm_western ) {
                    $llm_data  = json_decode( $astro_row['llm_report'], true );
                    $gen_count = is_array( $llm_data['sections'] ?? null ) ? count( array_filter( $llm_data['sections'] ) ) : 0;
                    echo '<p style="color:#6366f1;font-size:11px;margin:0;">🤖 AI Report: ' . $gen_count . '/10 chương | ' . esc_html( $llm_data['generated'] ?? '—' ) . '</p>';
                } else {
                    echo '<p style="color:#9ca3af;font-size:11px;margin:0;">🤖 AI Report: Chưa tạo</p>';
                }
            else: ?>
                <div style="padding:30px;text-align:center;color:#9ca3af;background:#f8fafc;border-radius:8px;margin:12px 0">
                    <p style="font-size:32px;margin:0">🌟</p>
                    <p style="margin:8px 0 0">Chưa tạo bản đồ Western Astrology</p>
                    <p style="font-size:12px;color:#9ca3af">Bấm "🌟 Tạo Western" để tạo</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- RIGHT: Vedic / AstroViet Wheel -->
        <div class="bccm-postbox">
            <h3 style="color:#7c3aed">🕉️ Natal Wheel <small style="font-weight:400;color:#888;font-size:12px">(Sidereal — Indian)</small></h3>
            <?php
            $positions   = $astro_traits['positions'] ?? [];
            $houses_data = $astro_traits['houses'] ?? [];
            $houses_raw  = [];
            if ( ! empty( $houses_data ) ) {
                if ( isset( $houses_data[0]['House'] ) || isset( $houses_data[0]['house'] ) ) {
                    $houses_raw = $houses_data;
                } elseif ( isset( $houses_data['Houses'] ) ) {
                    $houses_raw = $houses_data['Houses'];
                }
            }
            $coachee_name   = $coachee['full_name'] ?? '';
            $birth_data_chart = $astro_traits['birth_data'] ?? [];

            if ( ! empty( $positions ) && function_exists( 'bccm_build_astroviet_wheel_url' ) ) {
                $astroviet_wheel_url = bccm_build_astroviet_wheel_url( $positions, $houses_raw, $coachee_name, array_merge( $birth_data_chart, [
                    'birth_place' => $astro_row['birth_place'] ?? '',
                    'latitude'    => $astro_row['latitude'] ?? ( $birth_data_chart['latitude'] ?? 0 ),
                    'longitude'   => $astro_row['longitude'] ?? ( $birth_data_chart['longitude'] ?? 0 ),
                ] ) );
                if ( ! empty( $astroviet_wheel_url ) ): ?>
                <div style="text-align:center;margin:12px 0">
                    <img src="<?php echo esc_url( $astroviet_wheel_url ); ?>" alt="AstroViet Natal Wheel" style="max-width:100%;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.1)" loading="lazy"/>
                </div>
                <?php endif;
            }

            if ( $has_vedic_r ): ?>
                <p style="color:#22c55e;font-weight:600;margin:8px 0 4px;">✅ Dữ liệu đã có</p>
                <p style="font-size:12px;color:#6b7280;margin:0 0 4px;">Fetched: <?php echo esc_html( $vedic_summary['fetched_at'] ?? '—' ); ?></p>
                <?php
                $has_llm_vedic = ! empty( $vedic_row['llm_report'] );
                if ( $has_llm_vedic ) {
                    $llm_data  = json_decode( $vedic_row['llm_report'], true );
                    $gen_count = is_array( $llm_data['sections'] ?? null ) ? count( array_filter( $llm_data['sections'] ) ) : 0;
                    echo '<p style="color:#6366f1;font-size:11px;margin:0;">🕉️ AI Report: ' . $gen_count . '/10 chương | ' . esc_html( $llm_data['generated'] ?? '—' ) . '</p>';
                } else {
                    echo '<p style="color:#9ca3af;font-size:11px;margin:0;">🕉️ AI Report: Chưa tạo</p>';
                }
            else: ?>
                <div style="padding:30px;text-align:center;color:#9ca3af;background:#f8fafc;border-radius:8px;margin:12px 0">
                    <p style="font-size:32px;margin:0">🕉️</p>
                    <p style="margin:8px 0 0">Chưa tạo bản đồ Vedic Astrology</p>
                    <p style="font-size:12px;color:#9ca3af">Bấm "🕉️ Tạo Vedic" để tạo</p>
                </div>
            <?php endif; ?>
        </div>

    </div><!-- /dual grid -->

    <!-- ══════════════ ASPECT GRID ══════════════ -->
    <?php
    $aspects_raw = $astro_traits['aspects'] ?? [];
    if ( ! empty( $positions ) && ! empty( $houses_raw ) ) {
        $astroviet_grid_url = function_exists( 'bccm_build_astroviet_aspect_grid_url' )
            ? bccm_build_astroviet_aspect_grid_url( $positions, $houses_raw, $birth_data_chart )
            : '';

        if ( $astroviet_grid_url ):
    ?>
    <div class="bccm-postbox">
        <h3>🗺️ Lưới Góc Chiếu (Aspect Grid)</h3>
        <div style="text-align:center">
            <img src="<?php echo esc_url( $astroviet_grid_url ); ?>" alt="Aspect Grid" style="max-width:100%;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.1)" loading="lazy"/>
        </div>
    </div>
    <?php endif; } ?>

    <!-- ══════════════ PLANET POSITIONS TABLE ══════════════ -->
    <?php if ( ! empty( $positions ) ): ?>
    <div class="bccm-postbox">
        <h3>🪐 Vị Trí Các Hành Tinh</h3>
        <div style="overflow-x:auto;">
        <table class="bccm-natal-table"><thead><tr>
            <th style="width:28%">Hành tinh</th>
            <th style="width:18%">Cung</th>
            <th style="width:22%">Vị trí</th>
            <th style="width:12%">Nhà</th>
            <th style="width:10%">Nghịch hành</th>
        </tr></thead><tbody>
        <?php
        $planet_order = [ 'Sun','Moon','Mercury','Venus','Mars','Jupiter','Saturn','Uranus','Neptune','Pluto','Chiron','Lilith','True Node','Mean Node','Ascendant','Descendant','MC','IC','Ceres','Vesta','Juno','Pallas' ];
        $planet_symbols = [
            'Sun' => '☉', 'Moon' => '☽', 'Mercury' => '☿', 'Venus' => '♀', 'Mars' => '♂',
            'Jupiter' => '♃', 'Saturn' => '♄', 'Uranus' => '♅', 'Neptune' => '♆', 'Pluto' => '♇',
            'Chiron' => '⚷', 'Lilith' => '⚸', 'True Node' => '☊', 'Mean Node' => '☊',
            'Ascendant' => 'ASC', 'Descendant' => 'DSC', 'MC' => 'MC', 'IC' => 'IC',
            'Ceres' => '⚳', 'Vesta' => '⚶', 'Juno' => '⚵', 'Pallas' => '⚴',
        ];

        $row_idx = 0;
        foreach ( $planet_order as $pname ) {
            if ( ! isset( $positions[ $pname ] ) ) continue;
            $p       = $positions[ $pname ];
            $symbol  = $planet_symbols[ $pname ] ?? '';
            $dms     = function_exists( 'bccm_astro_decimal_to_dms' ) ? bccm_astro_decimal_to_dms( $p['norm_degree'] ?? 0 ) : '';
            $house_num = '';
            if ( ! empty( $houses_raw ) && ! in_array( $pname, [ 'Ascendant','Descendant','MC','IC' ] ) && function_exists( 'bccm_astro_planet_in_house' ) ) {
                $h = bccm_astro_planet_in_house( $p['full_degree'] ?? 0, $houses_raw );
                $house_num = $h > 0 ? $h : '';
            }
            $row_class = ( $row_idx % 2 === 0 ) ? 'bccm-row-even' : 'bccm-row-odd';

            if ( $pname === 'Uranus' && $row_idx > 0 )    echo '<tr class="bccm-table-separator"><td colspan="5"></td></tr>';
            if ( $pname === 'Chiron' && $row_idx > 0 )     echo '<tr class="bccm-table-separator"><td colspan="5"></td></tr>';
            if ( $pname === 'Ascendant' && $row_idx > 0 )  echo '<tr class="bccm-table-separator"><td colspan="5"></td></tr>';

            echo '<tr class="' . $row_class . '">';
            echo '<td><span style="font-size:16px;margin-right:6px;vertical-align:middle">' . $symbol . '</span><strong>' . esc_html( $p['planet_vi'] ?? $pname ) . '</strong></td>';
            echo '<td><span style="font-size:16px;vertical-align:middle">' . esc_html( $p['sign_symbol'] ?? '' ) . '</span> ' . esc_html( $p['sign_vi'] ?? '' ) . '</td>';
            echo '<td style="font-family:\'Courier New\',monospace;font-size:13px">' . esc_html( $dms ) . '</td>';
            echo '<td style="text-align:center;font-weight:600;color:#6366f1">' . ( $house_num ? esc_html( $house_num ) : '—' ) . '</td>';
            echo '<td style="text-align:center">' . ( ! empty( $p['is_retro'] ) ? '<span style="color:#ef4444;font-weight:700" title="Nghịch hành">℞</span>' : '<span style="color:#d1d5db">—</span>' ) . '</td>';
            echo '</tr>';
            $row_idx++;
        }
        ?>
        </tbody></table>
        </div>
        <p style="margin-top:4px;font-size:11px;color:#3b82f6">Nguồn: Freeze Astrology API — freeastrologyapi.com</p>
    </div>
    <?php endif; ?>

    <!-- ══════════════ HOUSES TABLE ══════════════ -->
    <?php if ( ! empty( $houses_raw ) ):
        $signs_list    = function_exists( 'bccm_zodiac_signs' ) ? bccm_zodiac_signs() : [];
        $house_meanings = function_exists( 'bccm_house_meanings_vi' ) ? bccm_house_meanings_vi() : [];
    ?>
    <div class="bccm-postbox">
        <h3>🏛️ Vị Trí 12 Cung Nhà <small style="font-weight:400;color:#888;font-size:12px">(Placidus)</small></h3>
        <div style="overflow-x:auto;">
        <table class="bccm-natal-table"><thead><tr>
            <th style="width:12%">Nhà</th>
            <th style="width:18%">Cung</th>
            <th style="width:22%">Đỉnh cung</th>
            <th>Ý nghĩa</th>
        </tr></thead><tbody>
        <?php
        foreach ( $houses_raw as $h ) {
            $num = $h['House'] ?? ( $h['house'] ?? 0 );
            if ( $num < 1 ) continue;
            $sign_num = $h['zodiac_sign']['number'] ?? 0;
            $sign_vi  = $signs_list[ $sign_num ]['vi'] ?? '';
            $symbol_h = $signs_list[ $sign_num ]['symbol'] ?? '';
            $norm_deg = $h['normDegree'] ?? ( $h['degree'] ?? 0 );
            $dms      = function_exists( 'bccm_astro_decimal_to_dms' ) ? bccm_astro_decimal_to_dms( $norm_deg ) : '';
            $meaning  = $house_meanings[ $num ] ?? '';
            $angular  = in_array( $num, [ 1, 4, 7, 10 ] );
            $row_style = $angular ? 'background:#f0f4ff;font-weight:500' : '';

            echo '<tr style="' . $row_style . '">';
            echo '<td style="text-align:center"><strong style="color:#6366f1;font-size:15px">' . intval( $num ) . '</strong></td>';
            echo '<td><span style="font-size:16px;vertical-align:middle">' . esc_html( $symbol_h ) . '</span> ' . esc_html( $sign_vi ) . '</td>';
            echo '<td style="font-family:\'Courier New\',monospace;font-size:13px">' . esc_html( $dms ) . '</td>';
            echo '<td style="color:#6b7280;font-size:12px">' . esc_html( $meaning ) . '</td>';
            echo '</tr>';
        }
        ?>
        </tbody></table>
        </div>
        <p style="margin-top:4px;font-size:11px;color:#3b82f6">Nguồn: Freeze Astrology API</p>
    </div>
    <?php endif; ?>

    <!-- ══════════════ ASPECTS TABLE ══════════════ -->
    <?php
    $aspects = $astro_traits['aspects'] ?? [];
    if ( ! empty( $aspects ) && ! empty( $positions ) && function_exists( 'bccm_astro_enrich_aspects' ) ):
        $enriched       = bccm_astro_enrich_aspects( $aspects, $positions );
        $grouped        = function_exists( 'bccm_astro_group_aspects_by_planet' ) ? bccm_astro_group_aspects_by_planet( $enriched ) : [];
        $planet_vi      = function_exists( 'bccm_planet_names_vi' ) ? bccm_planet_names_vi() : [];
        $aspect_vi      = function_exists( 'bccm_aspect_names_vi' ) ? bccm_aspect_names_vi() : [];
        $aspect_symbols = function_exists( 'bccm_aspect_symbols' ) ? bccm_aspect_symbols() : [];
        $aspect_colors  = function_exists( 'bccm_aspect_colors' ) ? bccm_aspect_colors() : [];
    ?>
    <div class="bccm-postbox">
        <h3>🔗 Góc Chiếu Giữa Các Hành Tinh <small style="font-weight:400;color:#888;font-size:12px">(Aspects)</small></h3>
        <p style="font-size:12px;color:#6b7280;margin:0 0 12px;">Tổng cộng <?php echo count( $enriched ); ?> góc chiếu, nhóm theo hành tinh.</p>

        <!-- Aspect legend -->
        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px;padding:10px;background:#f9fafb;border-radius:8px;font-size:11px">
            <?php foreach ( $aspect_vi as $aen => $avi ):
                $c = $aspect_colors[ $aen ] ?? '#888';
                $s = $aspect_symbols[ $aen ] ?? '';
            ?>
            <span style="display:inline-flex;align-items:center;gap:3px"><span style="color:<?php echo $c; ?>;font-weight:700"><?php echo $s; ?></span> <?php echo esc_html( $avi ); ?></span>
            <?php endforeach; ?>
        </div>

        <div style="overflow-x:auto;">
        <table class="bccm-natal-table"><thead><tr>
            <th style="width:22%">Hành tinh 1</th>
            <th style="width:8%;text-align:center"></th>
            <th style="width:22%">Góc chiếu</th>
            <th style="width:22%">Hành tinh 2</th>
            <th style="width:16%">Orb</th>
        </tr></thead><tbody>
        <?php
        foreach ( $grouped as $planet_key => $planet_aspects ) {
            $pvi = $planet_vi[ $planet_key ] ?? $planet_key;
            echo '<tr class="bccm-aspect-group-header"><td colspan="5"><strong style="color:#1e293b">' . esc_html( $pvi ) . '</strong> <span style="color:#9ca3af;font-weight:400">(' . count( $planet_aspects ) . ')</span></td></tr>';

            foreach ( $planet_aspects as $asp ) {
                $type_en = $asp['aspect_en'];
                $type_vi = $aspect_vi[ $type_en ] ?? $type_en;
                $p2_vi   = $planet_vi[ $asp['planet_2_en'] ] ?? $asp['planet_2_en'];
                $sym     = $aspect_symbols[ $type_en ] ?? '';
                $color   = $aspect_colors[ $type_en ] ?? '#888';
                $orb_val = $asp['orb'];
                $orb_display = $orb_val !== null && function_exists( 'bccm_astro_decimal_to_dms' ) ? bccm_astro_decimal_to_dms( $orb_val, true ) : '—';
                $orb_style = '';
                if ( $orb_val !== null && $orb_val < 1 ) {
                    $orb_style = 'color:#059669;font-weight:700';
                } elseif ( $orb_val !== null && $orb_val < 3 ) {
                    $orb_style = 'color:#2563eb';
                }

                echo '<tr>';
                echo '<td style="padding-left:24px;color:#6b7280">' . esc_html( $planet_vi[ $asp['planet_1_en'] ] ?? $asp['planet_1_en'] ) . '</td>';
                echo '<td style="text-align:center;font-size:16px;color:' . $color . '" title="' . esc_attr( $type_en ) . '">' . $sym . '</td>';
                echo '<td style="color:' . $color . ';font-weight:500">' . esc_html( $type_vi ) . '</td>';
                echo '<td>' . esc_html( $p2_vi ) . '</td>';
                echo '<td style="font-family:\'Courier New\',monospace;font-size:12px;' . $orb_style . '">' . esc_html( $orb_display ) . '</td>';
                echo '</tr>';
            }
        }
        ?>
        </tbody></table>
        </div>

        <!-- Aspect stats -->
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px">
            <?php
            $stats = [];
            foreach ( $enriched as $asp ) {
                $type = $asp['aspect_en'];
                if ( ! isset( $stats[ $type ] ) ) $stats[ $type ] = 0;
                $stats[ $type ]++;
            }
            foreach ( $stats as $type => $count ):
                $c = $aspect_colors[ $type ] ?? '#888';
                $s = $aspect_symbols[ $type ] ?? '';
            ?>
            <span style="display:inline-flex;align-items:center;gap:3px;padding:3px 10px;background:#f9fafb;border-radius:99px;font-size:12px;border:1px solid #e5e7eb">
                <span style="color:<?php echo $c; ?>;font-weight:700"><?php echo $s; ?></span>
                <span style="color:#6b7280"><?php echo esc_html( $aspect_vi[ $type ] ?? $type ); ?></span>
                <strong><?php echo $count; ?></strong>
            </span>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ══════════════ VEDIC PLANET TABLE (Graha) ══════════════ -->
    <?php
    $vedic_positions = $vedic_traits['positions'] ?? [];
    $vedic_navamsa   = $vedic_traits['navamsa'] ?? [];

    if ( ! empty( $vedic_positions ) && $has_vedic_r ):
        $vedic_planet_order = [ 'Sun','Moon','Mars','Mercury','Jupiter','Venus','Saturn','Rahu','Ketu','Ascendant' ];
        $vedic_planet_symbols = [
            'Sun' => '☉', 'Moon' => '☽', 'Mars' => '♂', 'Mercury' => '☿', 'Jupiter' => '♃',
            'Venus' => '♀', 'Saturn' => '♄', 'Rahu' => '☊', 'Ketu' => '☋', 'Ascendant' => 'ASC',
        ];
    ?>
    <div class="bccm-postbox" style="border-left:4px solid #7c3aed">
        <h3 style="color:#7c3aed">🕉️ Vị Trí Các Hành Tinh (Vedic) <small style="font-weight:400;color:#888;font-size:12px">(Grahas in Rashi)</small></h3>
        <div style="overflow-x:auto;">
        <table class="bccm-natal-table"><thead><tr>
            <th style="width:28%">Graha</th>
            <th style="width:22%">Rashi</th>
            <th style="width:20%">Vị trí</th>
            <th style="width:16%">Chúa cung</th>
            <th style="width:14%">Nghịch hành</th>
        </tr></thead><tbody>
        <?php
        $row_idx = 0;
        foreach ( $vedic_planet_order as $pname ) {
            if ( ! isset( $vedic_positions[ $pname ] ) ) continue;
            $p       = $vedic_positions[ $pname ];
            $symbol  = $vedic_planet_symbols[ $pname ] ?? '';
            $dms     = function_exists( 'bccm_astro_decimal_to_dms' ) ? bccm_astro_decimal_to_dms( $p['norm_degree'] ?? 0 ) : '';
            $sign_lord = $p['sign_lord'] ?? '';
            $row_class = ( $row_idx % 2 === 0 ) ? 'bccm-row-even' : 'bccm-row-odd';

            if ( $pname === 'Rahu' && $row_idx > 0 )     echo '<tr class="bccm-table-separator"><td colspan="5"></td></tr>';
            if ( $pname === 'Ascendant' && $row_idx > 0 ) echo '<tr class="bccm-table-separator"><td colspan="5"></td></tr>';

            echo '<tr class="' . $row_class . '">';
            echo '<td><span style="font-size:16px;margin-right:6px;vertical-align:middle">' . $symbol . '</span><strong>' . esc_html( $p['planet_vi'] ?? $pname ) . '</strong>';
            if ( ! empty( $p['sign_sanskrit'] ) && $pname !== 'Ascendant' ) echo ' <small style="color:#9ca3af">(' . esc_html( $p['sign_sanskrit'] ) . ')</small>';
            echo '</td>';
            echo '<td><span style="font-size:16px;vertical-align:middle">' . esc_html( $p['sign_symbol'] ?? '' ) . '</span> ' . esc_html( $p['sign_vi'] ?? '' );
            if ( ! empty( $p['sign_sanskrit'] ) ) echo ' <small style="color:#7c3aed;font-weight:500">(' . esc_html( $p['sign_sanskrit'] ) . ')</small>';
            echo '</td>';
            echo '<td style="font-family:\'Courier New\',monospace;font-size:13px">' . esc_html( $dms ) . '</td>';
            echo '<td style="color:#6366f1;font-size:12px">' . ( $sign_lord ? esc_html( $sign_lord ) : '—' ) . '</td>';
            echo '<td style="text-align:center">' . ( ! empty( $p['is_retro'] ) ? '<span style="color:#ef4444;font-weight:700" title="Nghịch hành">℞</span>' : '<span style="color:#d1d5db">—</span>' ) . '</td>';
            echo '</tr>';
            $row_idx++;
        }
        ?>
        </tbody></table>
        </div>
        <p style="margin-top:4px;font-size:11px;color:#7c3aed">Nguồn: Freeze Astrology API (Vedic/Jyotish) — Lahiri Ayanamsha</p>
    </div>
    <?php endif; ?>

    <!-- ══════════════ NAVAMSA CHART (D9) ══════════════ -->
    <?php
    $navamsa_chart_url = $vedic_summary['navamsa_chart_url'] ?? '';
    if ( $navamsa_chart_url ):
    ?>
    <div class="bccm-postbox" style="border-left:4px solid #7c3aed">
        <h3 style="color:#7c3aed">💍 Navamsa Chart (D9) <small style="font-weight:400;color:#888;font-size:12px">(Hôn nhân &amp; Dharma)</small></h3>
        <div style="text-align:center;margin:12px 0">
            <img src="<?php echo esc_url( $navamsa_chart_url ); ?>" alt="Navamsa Chart (D9)" style="max-width:100%;border-radius:12px;box-shadow:0 4px 16px rgba(124,58,237,0.2)" loading="lazy"/>
            <p style="font-size:11px;color:#6b7280;margin-top:8px">Navamsa (D9) — Marriage, relationships &amp; dharma</p>
        </div>

        <?php if ( ! empty( $vedic_navamsa ) && is_array( $vedic_navamsa ) ): ?>
        <h4 style="margin:16px 0 8px;color:#6b7280;font-size:14px">📊 Vị Trí Trong Navamsa</h4>
        <div style="overflow-x:auto;">
        <table class="bccm-natal-table" style="font-size:12px"><thead><tr>
            <th>Graha</th>
            <th>Rashi (D9)</th>
            <th>Độ</th>
        </tr></thead><tbody>
        <?php
        $rashi_signs       = function_exists( 'bccm_vedic_rashi_signs' ) ? bccm_vedic_rashi_signs() : [];
        $planet_vi_names_n = function_exists( 'bccm_vedic_planet_names_vi' ) ? bccm_vedic_planet_names_vi() : [];

        $row_idx = 0;
        foreach ( $vedic_planet_order as $pname ) {
            if ( ! isset( $vedic_navamsa[ $pname ] ) ) continue;
            $np       = $vedic_navamsa[ $pname ];
            $sign_num = intval( $np['current_sign'] ?? 0 );
            $sign_inf = $rashi_signs[ $sign_num ] ?? [ 'vi' => '?', 'symbol' => '?', 'sanskrit' => '?' ];
            $norm_deg = floatval( $np['normDegree'] ?? 0 );
            $dms      = function_exists( 'bccm_astro_decimal_to_dms' ) ? bccm_astro_decimal_to_dms( $norm_deg ) : '';
            $symbol   = $vedic_planet_symbols[ $pname ] ?? '';
            $pvi      = $planet_vi_names_n[ $pname ] ?? $pname;
            $row_class = ( $row_idx % 2 === 0 ) ? 'bccm-row-even' : 'bccm-row-odd';

            echo '<tr class="' . $row_class . '">';
            echo '<td><span style="font-size:14px;margin-right:4px">' . $symbol . '</span>' . esc_html( $pvi ) . '</td>';
            echo '<td><span style="font-size:14px">' . esc_html( $sign_inf['symbol'] ) . '</span> ' . esc_html( $sign_inf['vi'] ) . ' <small style="color:#7c3aed">(' . esc_html( $sign_inf['sanskrit'] ) . ')</small></td>';
            echo '<td style="font-family:\'Courier New\',monospace;font-size:11px">' . esc_html( $dms ) . '</td>';
            echo '</tr>';
            $row_idx++;
        }
        ?>
        </tbody></table>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ══════════════ CHART PATTERNS ══════════════ -->
    <?php
    if ( ! empty( $aspects ) && ! empty( $positions ) && function_exists( 'bccm_detect_chart_patterns' ) ):
        $chart_patterns = bccm_detect_chart_patterns( $positions, $aspects );
        if ( ! empty( $chart_patterns ) ):
    ?>
    <div class="bccm-postbox">
        <h3>🔷 Mô Hình Bản Đồ <small style="font-weight:400;color:#888;font-size:12px">(Chart Patterns)</small></h3>
        <p style="font-size:12px;color:#6b7280;margin:0 0 14px;">Các mô hình hình học đặc biệt giữa các hành tinh.</p>
        <div class="bccm-patterns-grid">
            <?php
            $planet_vi_names_p = function_exists( 'bccm_planet_names_vi' ) ? bccm_planet_names_vi() : [];
            foreach ( $chart_patterns as $pattern ):
                $planet_list = [];
                foreach ( $pattern['planets'] as $pn ) {
                    $pvi     = $planet_vi_names_p[ $pn ] ?? $pn;
                    $sign_vp = $positions[ $pn ]['sign_vi'] ?? '';
                    $ndeg    = $positions[ $pn ]['norm_degree'] ?? 0;
                    $deg_str = floor( $ndeg ) . '°';
                    $planet_list[] = "$pvi trong $deg_str $sign_vp";
                }
            ?>
            <div class="bccm-pattern-card">
                <div class="bccm-pattern-header">
                    <span class="bccm-pattern-icon"><?php echo $pattern['icon'] ?? '🔷'; ?></span>
                    <span class="bccm-pattern-type"><?php echo esc_html( $pattern['type_vi'] ); ?></span>
                </div>
                <div class="bccm-pattern-planets">
                    <?php foreach ( $planet_list as $pl ): ?>
                    <div class="bccm-pattern-planet"><?php echo esc_html( $pl ); ?></div>
                    <?php endforeach; ?>
                </div>
                <div class="bccm-pattern-desc"><?php echo esc_html( $pattern['description'] ); ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; endif; ?>

    <!-- ══════════════ SPECIAL FEATURES ══════════════ -->
    <?php
    if ( ! empty( $positions ) && function_exists( 'bccm_analyze_special_features' ) ):
        $special_features = bccm_analyze_special_features(
            $positions,
            $aspects ?? [],
            $houses_raw ?? [],
            $astro_traits['birth_data'] ?? []
        );
        if ( ! empty( $special_features ) ):
    ?>
    <div class="bccm-postbox">
        <h3>✨ Đặc Điểm Nổi Bật <small style="font-weight:400;color:#888;font-size:12px">(Special Features)</small></h3>
        <p style="font-size:12px;color:#6b7280;margin:0 0 14px;">Phân tích tổng quan về các đặc điểm nổi bật trong bản đồ sao.</p>
        <div class="bccm-special-grid">
            <?php foreach ( $special_features as $feature ): ?>
            <div class="bccm-special-card">
                <div class="bccm-special-icon"><?php echo $feature['icon'] ?? '✨'; ?></div>
                <div class="bccm-special-text">
                    <p class="bccm-special-main"><?php echo esc_html( $feature['text'] ); ?></p>
                    <?php if ( ! empty( $feature['text_vi'] ) && $feature['text_vi'] !== $feature['text'] ): ?>
                    <p class="bccm-special-sub"><?php echo esc_html( $feature['text_vi'] ); ?></p>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; endif; ?>

    <!-- Footer -->
    <p style="margin-top:16px;font-size:11px;color:#9ca3af;">
        <?php
        $w_fetched = $astro_summary['fetched_at'] ?? '—';
        $v_fetched = ! empty( $vedic_summary['fetched_at'] ) ? ' | Vedic: ' . esc_html( $vedic_summary['fetched_at'] ) : '';
        echo 'Western: ' . esc_html( $w_fetched ) . $v_fetched . ' | Powered by Freeze Astrology API';
        ?>
    </p>

    <?php endif; // has_western || has_vedic_r ?>

    </div><!-- /bccm-tab-profile -->

    <!-- ══════════════ TAB 2: AI CONSULTATION ══════════════ -->
    <div class="bccm-tab-panel" id="bccm-tab-consult">

        <div class="bccm-pf-section">
            <h3>🔮 Hỏi Chuyên Gia Chiêm Tinh AI</h3>
            <p style="color:#6b7280;font-size:13px;margin:0 0 14px;">Đặt câu hỏi cho chuyên gia chiêm tinh AI — với hơn 20 năm kinh nghiệm đọc bản đồ sao. AI sẽ phân tích dựa trên bản đồ sao Western + Vedic và vận hành transit của bạn.</p>

            <?php if ( ! $has_profile ): ?>
            <div class="bccm-pf-notice bccm-pf-notice-warn">
                ⚠️ <strong>Bạn chưa khai báo hồ sơ chiêm tinh.</strong> Hãy chuyển sang tab "Hồ sơ Chiêm tinh" để nhập ngày sinh, giờ sinh, nơi sinh và tạo bản đồ sao trước khi hỏi chuyên gia.
            </div>
            <?php endif; ?>

            <!-- Topic chips -->
            <label style="font-size:13px;font-weight:600;color:#374151;margin-bottom:6px;display:block;">Chủ đề quan tâm <span style="color:#9ca3af;font-weight:400">(tùy chọn)</span></label>
            <div class="bccm-consult-topics" id="bccm-consult-topics">
                <span class="bccm-topic-chip" data-topic="tổng quan">🌟 Tổng quan</span>
                <span class="bccm-topic-chip" data-topic="tình yêu">💕 Tình yêu</span>
                <span class="bccm-topic-chip" data-topic="sự nghiệp">💼 Sự nghiệp</span>
                <span class="bccm-topic-chip" data-topic="tài chính">💰 Tài chính</span>
                <span class="bccm-topic-chip" data-topic="sức khỏe">🏥 Sức khỏe</span>
                <span class="bccm-topic-chip" data-topic="transit">🔮 Vận hành</span>
                <span class="bccm-topic-chip" data-topic="tương thích">🤝 Tương thích</span>
            </div>

            <!-- Question input -->
            <label style="font-size:13px;font-weight:600;color:#374151;margin-bottom:6px;display:block;">Câu hỏi của bạn</label>
            <textarea class="bccm-consult-input" id="bccm-consult-question" placeholder="VD: Hôm nay tôi thế nào? / Phân tích Big 3 của tôi / Năm nay sự nghiệp tôi ra sao? / Tôi và người yêu cung Sư Tử có hợp không?"></textarea>

            <div style="display:flex;align-items:center;gap:12px;margin-top:12px;">
                <button type="button" id="bccm-consult-submit" class="bccm-pf-btn bccm-pf-btn-primary" style="padding:12px 28px;">
                    🔮 Hỏi chuyên gia
                </button>
                <span id="bccm-consult-status" style="font-size:13px;color:#6b7280;"></span>
            </div>
        </div>

        <!-- Conversation history -->
        <div class="bccm-consult-history" id="bccm-consult-history"></div>

    </div><!-- /bccm-tab-consult -->

    <!-- ── Geo Lookup JS + Tab switching + AI Consult JS ── -->
    <script>
    (function(){
        /* ── Nav switching ── */
        document.querySelectorAll('.bccm-nav-item').forEach(function(btn){
            btn.addEventListener('click', function(){
                document.querySelectorAll('.bccm-nav-item').forEach(function(b){ b.classList.remove('active'); });
                document.querySelectorAll('.bccm-tab-panel').forEach(function(p){ p.classList.remove('active'); });
                btn.classList.add('active');
                var panel = document.getElementById('bccm-tab-' + btn.getAttribute('data-tab'));
                if (panel) panel.classList.add('active');
            });
        });

        /* ── Geo Lookup ── */
        var geoBtn = document.getElementById('bccm_geo_lookup_btn');
        if (geoBtn) {
            geoBtn.addEventListener('click', function(){
                var place = document.getElementById('bccm_birth_place').value.trim();
                if (!place) { alert('Nhập nơi sinh trước'); return; }
                var status = document.getElementById('bccm_geo_status');
                status.textContent = 'Đang tìm…';
                fetch('https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' + encodeURIComponent(place))
                    .then(function(r){ return r.json(); })
                    .then(function(data){
                        if (data && data[0]) {
                            document.getElementById('bccm_astro_lat').value = parseFloat(data[0].lat).toFixed(7);
                            document.getElementById('bccm_astro_lng').value = parseFloat(data[0].lon).toFixed(7);
                            status.textContent = '✅ ' + data[0].display_name;
                        } else {
                            status.textContent = '❌ Không tìm thấy';
                        }
                    })
                    .catch(function(){ status.textContent = '❌ Lỗi kết nối'; });
            });
        }

        /* ── AI Consult ── */
        var selectedTopic = '';
        var consultHistory = [];
        var ajaxUrl = '<?php echo esc_js( admin_url( "admin-ajax.php" ) ); ?>';
        var sessionId = 'astro-consult-<?php echo esc_js( $user_id ); ?>-' + Date.now();

        // Topic chip selection
        document.querySelectorAll('.bccm-topic-chip').forEach(function(chip){
            chip.addEventListener('click', function(){
                if (chip.classList.contains('active')) {
                    chip.classList.remove('active');
                    selectedTopic = '';
                } else {
                    document.querySelectorAll('.bccm-topic-chip').forEach(function(c){ c.classList.remove('active'); });
                    chip.classList.add('active');
                    selectedTopic = chip.getAttribute('data-topic');
                }
            });
        });

        // Submit handler
        var submitBtn = document.getElementById('bccm-consult-submit');
        var statusEl  = document.getElementById('bccm-consult-status');
        var historyEl = document.getElementById('bccm-consult-history');
        var questionEl = document.getElementById('bccm-consult-question');

        if (submitBtn) {
            submitBtn.addEventListener('click', function(){
                var question = questionEl.value.trim();
                if (!question) { questionEl.focus(); return; }

                // Build message with topic prefix
                var message = question;
                if (selectedTopic) {
                    message = '[Chủ đề: ' + selectedTopic + '] ' + question;
                }

                submitBtn.disabled = true;
                statusEl.textContent = '🔄 Đang phân tích bản đồ sao…';

                // Append user message to history
                appendMessage('user', question, selectedTopic);
                questionEl.value = '';

                // AJAX call
                var formData = new FormData();
                formData.append('action', 'bizcity_webchat_send');
                formData.append('message', message);
                formData.append('character_id', '0');
                formData.append('session_id', sessionId);
                formData.append('platform_type', 'WEBCHAT');
                formData.append('plugin_slug', 'bizcoach');
                formData.append('routing_mode', 'manual');
                formData.append('provider_hint', 'bizcoach');

                fetch(ajaxUrl, { method: 'POST', body: formData })
                    .then(function(r){ return r.json(); })
                    .then(function(resp){
                        submitBtn.disabled = false;
                        statusEl.textContent = '';
                        if (resp.success && resp.data) {
                            var reply = resp.data.reply || resp.data.message || 'Không có phản hồi.';
                            appendMessage('ai', reply);
                        } else {
                            var errMsg = (resp.data && resp.data.message) ? resp.data.message : 'Đã xảy ra lỗi, vui lòng thử lại.';
                            appendMessage('ai', '❌ ' + errMsg);
                        }
                    })
                    .catch(function(err){
                        submitBtn.disabled = false;
                        statusEl.textContent = '';
                        appendMessage('ai', '❌ Lỗi kết nối: ' + err.message);
                    });
            });
        }

        // Enter key submit (Ctrl+Enter)
        if (questionEl) {
            questionEl.addEventListener('keydown', function(e){
                if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
                    e.preventDefault();
                    submitBtn.click();
                }
            });
        }

        function appendMessage(role, text, topic) {
            var div = document.createElement('div');
            div.className = 'bccm-consult-msg bccm-consult-msg-' + role;

            var label = document.createElement('div');
            label.className = 'bccm-consult-msg-label';
            if (role === 'user') {
                label.style.color = '#7c3aed';
                label.textContent = '🙋 Bạn hỏi' + (topic ? ' [' + topic + ']' : '');
            } else {
                label.style.color = '#059669';
                label.textContent = '🔮 Chuyên gia chiêm tinh';
            }

            var body = document.createElement('div');
            body.className = 'bccm-consult-msg-body';
            // Basic markdown: bold, italic, headers
            var html = text
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
                .replace(/\*(.+?)\*/g, '<em>$1</em>')
                .replace(/^### (.+)$/gm, '<h4 style="margin:12px 0 4px;color:#1a1a2e">$1</h4>')
                .replace(/^## (.+)$/gm, '<h3 style="margin:14px 0 6px;color:#1a1a2e">$1</h3>')
                .replace(/^# (.+)$/gm, '<h2 style="margin:16px 0 8px;color:#1a1a2e">$1</h2>')
                .replace(/\n/g, '<br>');
            body.innerHTML = html;

            div.appendChild(label);
            div.appendChild(body);
            historyEl.appendChild(div);
            div.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    })();
    </script>

<?php endif; // is_logged_in ?>

</div>

<?php #get_footer(); ?>
