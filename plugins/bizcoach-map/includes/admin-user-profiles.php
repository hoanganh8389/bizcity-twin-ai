<?php
/**
 * BizCoach Map — Admin: User Profiles List & Detail
 *
 * Full admin page listing all users (including admin).
 * Detail view mirrors admin-self-profile.php with chart generation tools.
 *
 * @package BizCoach_Map
 * @since   0.1.0.40
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ── Register submenu ── */
add_action( 'admin_menu', function() {
    add_submenu_page(
        'bccm_root',
        'Hồ sơ người dùng',
        '👥 Hồ sơ người dùng',
        'manage_options',
        'bccm_user_profiles',
        'bccm_admin_user_profiles_page',
        35
    );
}, 20 );

/**
 * Admin page: User Profiles List
 */
function bccm_admin_user_profiles_page() {
    global $wpdb;
    $t       = bccm_tables();
    $t_astro = $wpdb->prefix . 'bccm_astro';

    wp_enqueue_style( 'bccm-admin' );

    // ── Detail view redirect ──
    if ( ! empty( $_GET['action'] ) && $_GET['action'] === 'view' && ! empty( $_GET['user_id'] ) ) {
        bccm_admin_user_profile_detail( (int) $_GET['user_id'] );
        return;
    }

    // ── Add new user + chart ──
    if ( ! empty( $_GET['action'] ) && $_GET['action'] === 'add_new' ) {
        bccm_admin_user_profile_add_new();
        return;
    }

    // Pagination
    $per_page = 30;
    $paged    = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
    $offset   = ( $paged - 1 ) * $per_page;

    // Filter
    $search   = sanitize_text_field( $_GET['s'] ?? '' );
    $platform = sanitize_text_field( $_GET['platform_filter'] ?? '' );
    $where    = 'WHERE 1=1';
    if ( $search ) {
        $like   = '%' . $wpdb->esc_like( $search ) . '%';
        $where .= $wpdb->prepare( " AND (p.full_name LIKE %s OR p.phone LIKE %s)", $like, $like );
    }
    if ( $platform && bccm_profiles_support_platform_type() ) {
        $where .= $wpdb->prepare( " AND p.platform_type = %s", $platform );
    }

    // Count & fetch — GROUP BY user_id to de-duplicate (one user may have multiple coachees)
    $count_sql = "
        SELECT COUNT(DISTINCT p.user_id) FROM {$t['profiles']} p {$where} AND p.user_id IS NOT NULL AND p.user_id > 0
    ";
    $total = (int) $wpdb->get_var( $count_sql );
    $pages = $total ? ceil( $total / $per_page ) : 1;

    $profiles = $wpdb->get_results( "
        SELECT p.*,
            a_w.id          AS astro_w_id,
            a_w.birth_time  AS western_birth_time,
            a_w.birth_place AS western_birth_place,
            a_w.summary     AS western_summary,
            a_w.traits      AS western_traits,
            a_v.summary     AS vedic_summary,
            (SELECT COUNT(*) FROM {$t['profiles']} p2 WHERE p2.user_id = p.user_id) AS profile_count
        FROM {$t['profiles']} p
        LEFT JOIN {$t_astro} a_w ON a_w.user_id = p.user_id AND a_w.chart_type = 'western'
        LEFT JOIN {$t_astro} a_v ON a_v.user_id = p.user_id AND a_v.chart_type = 'vedic'
        {$where}
        AND p.user_id IS NOT NULL AND p.user_id > 0
        AND p.id = (
            SELECT MAX(p3.id) FROM {$t['profiles']} p3
            WHERE p3.user_id = p.user_id
        )
        ORDER BY p.updated_at DESC
        LIMIT {$per_page} OFFSET {$offset}
    ", ARRAY_A );

    // Distinct platform values for filter
    $platforms = bccm_profiles_support_platform_type()
        ? $wpdb->get_col( "SELECT DISTINCT platform_type FROM {$t['profiles']} WHERE platform_type IS NOT NULL AND platform_type != '' ORDER BY platform_type" )
        : [];

    ?>
    <div class="wrap">
        <h1 style="display:flex;align-items:center;gap:10px;">
            👥 Hồ sơ người dùng
            <span style="background:#e0e7ff;color:#3730a3;padding:4px 12px;border-radius:20px;font-size:13px;font-weight:600;"><?php echo $total; ?> người dùng</span>
            <a href="<?php echo admin_url( 'admin.php?page=bccm_user_profiles&action=add_new' ); ?>" class="button button-primary" style="margin-left:auto;background:#059669;border-color:#047857;border-radius:8px;padding:6px 16px;font-size:13px;">➕ Tạo mới khách hàng</a>
        </h1>

        <form method="get" style="margin:16px 0;">
            <input type="hidden" name="page" value="bccm_user_profiles" />
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <input type="text" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Tìm theo tên, SĐT..." style="padding:8px 12px;border:1px solid #d1d5db;border-radius:8px;width:260px;" />
                <select name="platform_filter" style="padding:8px 12px;border:1px solid #d1d5db;border-radius:8px;">
                    <option value="">Tất cả Platform</option>
                    <?php foreach ( $platforms as $pf ): ?>
                        <option value="<?php echo esc_attr( $pf ); ?>" <?php selected( $platform, $pf ); ?>><?php echo esc_html( $pf ); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="button">🔍 Tìm kiếm</button>
                <?php if ( $search || $platform ): ?>
                    <a href="<?php echo admin_url( 'admin.php?page=bccm_user_profiles' ); ?>" class="button" style="color:#dc2626;">✕ Xóa lọc</a>
                <?php endif; ?>
            </div>
        </form>

        <table class="wp-list-table widefat fixed striped" style="border-radius:12px;overflow:hidden;">
            <thead>
                <tr>
                    <th style="width:36px;">#</th>
                    <th>Họ tên</th>
                    <th style="width:95px;">Ngày sinh</th>
                    <th style="width:90px;">Platform</th>
                    <th style="width:110px;">Giờ/Nơi sinh</th>
                    <th style="width:70px;">Western</th>
                    <th style="width:70px;">Vedic</th>
                    <th style="width:120px;">Cập nhật</th>
                    <th style="width:200px;">Thao tác</th>
                </tr>
            </thead>
            <tbody>
            <?php if ( empty( $profiles ) ): ?>
                <tr><td colspan="9" style="text-align:center;color:#9ca3af;padding:40px;">Chưa có hồ sơ nào.</td></tr>
            <?php else: foreach ( $profiles as $i => $p ):
                $idx           = $offset + $i + 1;
                $has_birth     = ! empty( $p['western_birth_time'] );
                $has_western   = ! empty( $p['western_summary'] );
                $has_vedic     = ! empty( $p['vedic_summary'] );
                $user          = $p['user_id'] ? get_userdata( $p['user_id'] ) : null;
                $display_name  = $p['full_name'] ?: ( $user ? $user->display_name : '—' );
                $platform_type = $p['platform_type'] ?? 'WEB';
                $is_admin      = $user && in_array( 'administrator', $user->roles ?? [] );
                $detail_url    = admin_url( 'admin.php?page=bccm_user_profiles&action=view&user_id=' . $p['user_id'] );
                $profile_count = (int) ( $p['profile_count'] ?? 1 );

                // Quick-gen URL for chart generation
                $gen_url = admin_url( 'admin.php?page=bccm_user_profiles&action=view&user_id=' . $p['user_id'] );

                // Platform badge colors
                $plt_colors = [
                    'ADMINCHAT' => 'background:#fef3c7;color:#92400e;',
                    'WEBCHAT'   => 'background:#dbeafe;color:#1e40af;',
                    'ZALO'      => 'background:#dcfce7;color:#166534;',
                ];
                $plt_style = $plt_colors[ $platform_type ] ?? 'background:#f3f4f6;color:#374151;';
            ?>
                <tr>
                    <td><?php echo $idx; ?></td>
                    <td>
                        <a href="<?php echo esc_url( $detail_url ); ?>" style="text-decoration:none;color:#1e40af;">
                            <strong><?php echo esc_html( $display_name ); ?></strong>
                        </a>
                        <?php if ( $is_admin ): ?>
                            <span style="background:#fbbf24;color:#78350f;padding:1px 6px;border-radius:4px;font-size:10px;font-weight:700;margin-left:4px;">ADMIN</span>
                        <?php endif; ?>
                        <?php if ( $profile_count > 1 ): ?>
                            <span style="color:#6b7280;font-size:11px;margin-left:4px;">(<?php echo $profile_count; ?> hồ sơ)</span>
                        <?php endif; ?>
                        <?php if ( $p['phone'] ): ?>
                            <br><small style="color:#9ca3af;"><?php echo esc_html( $p['phone'] ); ?></small>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html( $p['dob'] ?: '—' ); ?></td>
                    <td>
                        <span style="padding:2px 8px;border-radius:6px;font-size:11px;font-weight:700;<?php echo $plt_style; ?>">
                            <?php echo esc_html( $platform_type ); ?>
                        </span>
                    </td>
                    <td>
                        <?php if ( $has_birth ): ?>
                            <span title="<?php echo esc_attr( $p['western_birth_place'] ?? '' ); ?>">✅ <?php echo esc_html( $p['western_birth_time'] ); ?></span>
                        <?php else: ?>
                            <span style="color:#d97706;">⚠️ Chưa có</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo $has_western ? '<span style="color:#22c55e;font-weight:600;">🌟 Có</span>' : '<span style="color:#9ca3af;">—</span>'; ?></td>
                    <td><?php echo $has_vedic ? '<span style="color:#7c3aed;font-weight:600;">🕉️ Có</span>' : '<span style="color:#9ca3af;">—</span>'; ?></td>
                    <td style="font-size:12px;color:#6b7280;"><?php echo esc_html( $p['updated_at'] ?? $p['created_at'] ?? '—' ); ?></td>
                    <td>
                        <div style="display:flex;gap:4px;flex-wrap:wrap;">
                            <a href="<?php echo esc_url( $detail_url ); ?>" class="button button-small button-primary" title="Xem chi tiết">👁️ Chi tiết</a>
                            <?php if ( $has_birth && ! $has_western ): ?>
                                <a href="<?php echo esc_url( $gen_url . '&auto_gen=western' ); ?>" class="button button-small" style="background:#3b82f6;color:#fff;border-color:#2563eb;" title="Tạo Western chart">🌟</a>
                            <?php endif; ?>
                            <?php if ( $has_birth && ! $has_vedic ): ?>
                                <a href="<?php echo esc_url( $gen_url . '&auto_gen=vedic' ); ?>" class="button button-small" style="background:#7c3aed;color:#fff;border-color:#6d28d9;" title="Tạo Vedic chart">🕉️</a>
                            <?php endif; ?>
                            <?php if ( $has_western ): ?>
                                <?php
                                $natal_url = function_exists( 'bccm_get_natal_chart_url_by_user' ) ? bccm_get_natal_chart_url_by_user( $p['user_id'] ) : '';
                                if ( $natal_url ):
                                ?>
                                    <a href="<?php echo esc_url( $natal_url ); ?>" target="_blank" class="button button-small" style="background:#10b981;color:#fff;border-color:#059669;" title="Xem bản đồ sao công khai">🔗</a>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>

        <?php if ( $pages > 1 ): ?>
        <div style="margin-top:16px;display:flex;gap:4px;justify-content:center;">
            <?php for ( $pg = 1; $pg <= $pages; $pg++ ):
                $is_current = $pg === $paged;
                $url = add_query_arg( [ 'page' => 'bccm_user_profiles', 'paged' => $pg, 's' => $search, 'platform_filter' => $platform ], admin_url( 'admin.php' ) );
            ?>
                <a href="<?php echo esc_url( $url ); ?>" style="padding:6px 12px;border-radius:8px;text-decoration:none;font-size:13px;font-weight:600;
                    <?php echo $is_current ? 'background:#6366f1;color:#fff;' : 'background:#f3f4f6;color:#374151;'; ?>">
                    <?php echo $pg; ?>
                </a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Full detail view for a user — mirrors admin-self-profile.php
 * with chart generation, AI reports, transit tools.
 */
function bccm_admin_user_profile_detail( $view_uid ) {
    global $wpdb;
    $t         = bccm_tables();
    $t_astro   = $wpdb->prefix . 'bccm_astro';
    $t_plans   = $wpdb->prefix . 'bccm_action_plans';
    $t_gen     = $wpdb->prefix . 'bccm_gen_results';
    $t_transit = $wpdb->prefix . 'bccm_transit_snapshots';

    $user = get_userdata( $view_uid );

    // Get ALL coachee profiles for this user
    $coachees = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$t['profiles']} WHERE user_id = %d ORDER BY updated_at DESC",
        $view_uid
    ), ARRAY_A );

    // Auto-create coachee if user exists but has no profile
    if ( empty( $coachees ) && $user ) {
        if ( function_exists( 'bccm_get_or_create_user_coachee' ) ) {
            $new_coachee = bccm_get_or_create_user_coachee( $view_uid, 'WEBCHAT', 'astro_coach' );
            if ( $new_coachee ) {
                $coachees = [ $new_coachee ];
            }
        }
    }

    if ( empty( $coachees ) ) {
        echo '<div class="wrap"><div class="notice notice-warning"><p>Không tìm thấy hồ sơ cho user ID ' . esc_html( $view_uid ) . '</p></div>';
        echo '<p><a href="' . admin_url( 'admin.php?page=bccm_user_profiles' ) . '" class="button">← Quay lại danh sách</a></p></div>';
        return;
    }

    // Primary coachee
    $coachee    = $coachees[0];
    $coachee_id = (int) $coachee['id'];
    $coachee_ids = array_column( $coachees, 'id' );

    // Astro data
    $astro_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t_astro WHERE user_id=%d AND chart_type='western'", $view_uid ), ARRAY_A );
    $vedic_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t_astro WHERE user_id=%d AND chart_type='vedic'", $view_uid ), ARRAY_A );
    if ( ! $astro_row ) $astro_row = $vedic_row;

    /* ==================== HANDLE POST / AUTO-GEN ACTIONS ==================== */
    $auto_gen = sanitize_text_field( $_GET['auto_gen'] ?? '' );
    $action   = '';

    if ( ! empty( $_POST['bccm_action'] ) && check_admin_referer( 'bccm_user_detail_' . $view_uid ) ) {
        $action = sanitize_text_field( $_POST['bccm_action'] );
    } elseif ( $auto_gen ) {
        $action = $auto_gen === 'vedic' ? 'gen_vedic_chart' : ( $auto_gen === 'both' ? 'gen_both_charts' : 'gen_free_chart' );
    }

    if ( $action && in_array( $action, [ 'gen_free_chart', 'gen_vedic_chart', 'gen_both_charts' ], true ) ) {
        // Build birth_data from DB
        $birth_time_val = $astro_row['birth_time'] ?? '';
        $dob_val        = $coachee['dob'] ?? '';
        $latitude       = floatval( $astro_row['latitude'] ?? 0 );
        $longitude      = floatval( $astro_row['longitude'] ?? 0 );
        $timezone       = floatval( $astro_row['timezone'] ?? 7 );
        $birth_place    = $astro_row['birth_place'] ?? '';

        $dob_parts        = explode( '-', $dob_val );
        $birth_data_ready = false;
        $birth_data       = [];

        if ( count( $dob_parts ) === 3 && $birth_time_val ) {
            $time_parts = explode( ':', $birth_time_val );
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

        if ( $birth_data_ready ) {
            $birth_input = array_merge( $birth_data, [
                'birth_place' => $birth_place,
                'birth_time'  => $birth_time_val,
            ] );

            if ( $action === 'gen_free_chart' || $action === 'gen_both_charts' ) {
                if ( function_exists( 'bccm_astro_fetch_full_chart' ) ) {
                    $chart_result = bccm_astro_fetch_full_chart( $birth_data );
                    if ( is_wp_error( $chart_result ) ) {
                        echo '<div class="error"><p>❌ Western API: ' . esc_html( $chart_result->get_error_message() ) . '</p></div>';
                    } else {
                        bccm_astro_save_chart( $coachee_id, $chart_result, $birth_input, $view_uid );
                        echo '<div class="updated"><p>✅ Đã tạo bản đồ Western Astrology!</p></div>';
                    }
                }
            }
            if ( $action === 'gen_vedic_chart' || $action === 'gen_both_charts' ) {
                if ( function_exists( 'bccm_vedic_fetch_full_chart' ) ) {
                    $vedic_result = bccm_vedic_fetch_full_chart( $birth_data );
                    if ( is_wp_error( $vedic_result ) ) {
                        echo '<div class="error"><p>❌ Vedic API: ' . esc_html( $vedic_result->get_error_message() ) . '</p></div>';
                    } else {
                        bccm_vedic_save_chart( $coachee_id, $vedic_result, $birth_input, $view_uid );
                        echo '<div class="updated"><p>✅ Đã tạo bản đồ Vedic Astrology!</p></div>';
                    }
                }
            }

            // Refresh data after chart generation
            $astro_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t_astro WHERE user_id=%d AND chart_type='western'", $view_uid ), ARRAY_A );
            $vedic_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t_astro WHERE user_id=%d AND chart_type='vedic'", $view_uid ), ARRAY_A );
            $coachee   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['profiles']} WHERE id=%d", $coachee_id ), ARRAY_A );
        } else {
            echo '<div class="error"><p>⚠️ Cần có Ngày sinh và Giờ sinh để tạo bản đồ.</p></div>';
        }
    }

    // Data for display
    $astro_summary = ! empty( $astro_row['summary'] ) ? json_decode( $astro_row['summary'], true ) : [];
    $astro_traits  = ! empty( $astro_row['traits'] )  ? json_decode( $astro_row['traits'], true )  : [];
    $vedic_summary = ! empty( $vedic_row['summary'] ) ? json_decode( $vedic_row['summary'], true ) : [];
    $vedic_traits  = ! empty( $vedic_row['traits'] )  ? json_decode( $vedic_row['traits'], true )  : [];
    $has_western   = ! empty( $astro_summary ) || ! empty( $astro_traits );
    $has_vedic     = ! empty( $vedic_summary ) || ! empty( $vedic_traits );

    // Plans/maps
    $plans = [];
    if ( ! empty( $coachee_ids ) ) {
        $ids_in = implode( ',', array_map( 'intval', $coachee_ids ) );
        $plans  = $wpdb->get_results(
            "SELECT ap.*, c.coach_type, c.full_name AS coachee_name
             FROM {$t_plans} ap
             LEFT JOIN {$t['profiles']} c ON c.id = ap.coachee_id
             WHERE ap.coachee_id IN ({$ids_in})
             ORDER BY ap.created_at DESC",
            ARRAY_A
        );
    }

    // Gen results & transit snapshots
    $gen_results = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$t_gen} WHERE user_id = %d ORDER BY created_at DESC", $view_uid
    ), ARRAY_A );

    $transits = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$t_transit} WHERE user_id = %d ORDER BY target_date DESC LIMIT 10", $view_uid
    ), ARRAY_A );

    // Chat message count
    $chat_table = $wpdb->prefix . 'bizcity_webchat_messages';
    $msg_count  = 0;
    if ( $wpdb->get_var( "SHOW TABLES LIKE '{$chat_table}'" ) === $chat_table ) {
        $msg_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$chat_table} WHERE user_id=%d", $view_uid ) );
    }

    $types_meta = function_exists( 'bccm_coach_types' ) ? bccm_coach_types() : [];
    $display_name = $coachee['full_name'] ?: ( $user ? $user->display_name : 'User #' . $view_uid );

    /* ==================== RENDER ==================== */
    ?>
    <div class="wrap">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
        <h1 style="margin:0;">
            👤 <?php echo esc_html( $display_name ); ?>
            <small style="color:#6b7280;font-size:14px;font-weight:400;margin-left:8px;">user_id: <?php echo $view_uid; ?></small>
        </h1>
        <a href="<?php echo admin_url( 'admin.php?page=bccm_user_profiles' ); ?>" class="button">← Quay lại danh sách</a>
    </div>

    <!-- ══════════ TOP GRID: Info + Astro + Actions ══════════ -->
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;margin-bottom:20px;">
        <!-- Personal Info -->
        <div style="background:#f8fafc;padding:16px;border-radius:12px;border:1px solid #e5e7eb;">
            <h3 style="margin:0 0 10px;font-size:15px;">📋 Thông tin cá nhân</h3>
            <table style="width:100%;font-size:13px;">
                <tr><td style="color:#6b7280;width:100px;">Họ tên</td><td><strong><?php echo esc_html( $coachee['full_name'] ?: '—' ); ?></strong></td></tr>
                <tr><td style="color:#6b7280;">SĐT</td><td><?php echo esc_html( $coachee['phone'] ?: '—' ); ?></td></tr>
                <tr><td style="color:#6b7280;">Ngày sinh</td><td><?php echo esc_html( $coachee['dob'] ?: '—' ); ?></td></tr>
                <tr><td style="color:#6b7280;">Địa chỉ</td><td><?php echo esc_html( $coachee['address'] ?: '—' ); ?></td></tr>
                <tr><td style="color:#6b7280;">Platform</td><td><?php echo esc_html( $coachee['platform_type'] ?? 'WEB' ); ?></td></tr>
                <tr><td style="color:#6b7280;">Tin nhắn AI</td><td><?php echo $msg_count; ?> tin</td></tr>
                <tr><td style="color:#6b7280;">Hồ sơ</td><td><?php echo count( $coachees ); ?> profile</td></tr>
            </table>
        </div>

        <!-- Astro Data -->
        <div style="background:#f8fafc;padding:16px;border-radius:12px;border:1px solid #e5e7eb;">
            <h3 style="margin:0 0 10px;font-size:15px;">🌟 Dữ liệu chiêm tinh</h3>
            <?php if ( $astro_row ): ?>
                <table style="width:100%;font-size:13px;">
                    <tr><td style="color:#6b7280;width:100px;">Giờ sinh</td><td><strong><?php echo esc_html( $astro_row['birth_time'] ?: '—' ); ?></strong></td></tr>
                    <tr><td style="color:#6b7280;">Nơi sinh</td><td><?php echo esc_html( $astro_row['birth_place'] ?: '—' ); ?></td></tr>
                    <tr><td style="color:#6b7280;">Tọa độ</td><td><?php echo esc_html( ( $astro_row['latitude'] ?? '?' ) . ', ' . ( $astro_row['longitude'] ?? '?' ) ); ?></td></tr>
                    <tr><td style="color:#6b7280;">Timezone</td><td>UTC<?php echo ( $astro_row['timezone'] >= 0 ? '+' : '' ) . $astro_row['timezone']; ?></td></tr>
                    <tr><td style="color:#6b7280;">Western</td><td><?php echo $has_western ? '<span style="color:#22c55e;font-weight:600;">✅ Có dữ liệu</span>' : '<span style="color:#d97706;">⚠️ Chưa tạo</span>'; ?></td></tr>
                    <tr><td style="color:#6b7280;">Vedic</td><td><?php echo $has_vedic ? '<span style="color:#7c3aed;font-weight:600;">✅ Có dữ liệu</span>' : '<span style="color:#d97706;">⚠️ Chưa tạo</span>'; ?></td></tr>
                </table>
            <?php else: ?>
                <p style="color:#d97706;margin:0;font-size:13px;">⚠️ Chưa có dữ liệu chiêm tinh.</p>
                <p style="color:#9ca3af;font-size:12px;margin:4px 0 0;">User chưa khai báo giờ sinh/nơi sinh.</p>
            <?php endif; ?>
        </div>

        <!-- Quick Actions -->
        <div style="background:#eff6ff;padding:16px;border-radius:12px;border:1px solid #bfdbfe;">
            <h3 style="margin:0 0 10px;font-size:15px;">⚡ Công cụ nhanh</h3>
            <form method="post" style="display:flex;flex-direction:column;gap:8px;">
                <?php wp_nonce_field( 'bccm_user_detail_' . $view_uid ); ?>
                <button type="submit" name="bccm_action" value="gen_free_chart" class="button" style="background:#3b82f6;color:#fff;border-color:#2563eb;text-align:left;padding:8px 14px;" <?php echo ( ! $astro_row || ! $astro_row['birth_time'] ) ? 'disabled title="Cần giờ sinh"' : ''; ?>>
                    🌟 Tạo Western Astrology
                </button>
                <button type="submit" name="bccm_action" value="gen_vedic_chart" class="button" style="background:#7c3aed;color:#fff;border-color:#6d28d9;text-align:left;padding:8px 14px;" <?php echo ( ! $astro_row || ! $astro_row['birth_time'] ) ? 'disabled title="Cần giờ sinh"' : ''; ?>>
                    🕉️ Tạo Vedic Astrology
                </button>
                <button type="submit" name="bccm_action" value="gen_both_charts" class="button" style="background:#059669;color:#fff;border-color:#047857;text-align:left;padding:8px 14px;" <?php echo ( ! $astro_row || ! $astro_row['birth_time'] ) ? 'disabled title="Cần giờ sinh"' : ''; ?>>
                    ⚡ Tạo cả 2 bản đồ
                </button>
                <?php if ( ! $astro_row || ! $astro_row['birth_time'] ): ?>
                    <p style="color:#dc2626;font-size:11px;margin:0;">⚠️ User chưa khai báo giờ sinh. Không thể tạo bản đồ.</p>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <!-- ══════════ TOOLBAR: AI Reports + Transit + Public Links ══════════ -->
    <?php if ( $has_western || $has_vedic ): ?>
    <div style="margin-bottom:20px;padding:14px 18px;background:#fff;border:1px solid #e5e7eb;border-radius:12px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        <?php
        // Public Natal Chart link
        $natal_url = function_exists( 'bccm_get_natal_chart_url_by_user' ) ? bccm_get_natal_chart_url_by_user( $view_uid ) : '';
        if ( $natal_url ):
        ?>
            <a href="<?php echo esc_url( $natal_url ); ?>" target="_blank" class="button" style="background:#10b981;color:#fff;border-color:#059669;">🌟 Xem Bản Đồ Sao</a>
            <span style="border-left:2px solid #e5e7eb;height:24px;"></span>
        <?php endif; ?>

        <?php if ( $has_western ): ?>
            <a href="<?php echo esc_url( admin_url( 'admin-ajax.php?action=bccm_natal_report_full&coachee_id=' . $coachee_id . '&chart_type=western&_wpnonce=' . wp_create_nonce( 'bccm_natal_report_full' ) ) ); ?>" target="_blank" class="button" style="background:#3b82f6;color:#fff;border-color:#2563eb;">🤖 Luận Giải AI — Western</a>
            <a href="<?php echo esc_url( admin_url( 'admin-ajax.php?action=bccm_natal_report_full&coachee_id=' . $coachee_id . '&chart_type=western&regenerate=1&_wpnonce=' . wp_create_nonce( 'bccm_natal_report_full' ) ) ); ?>" target="_blank" class="button" style="background:#f59e0b;color:#fff;border-color:#d97706;" title="Tạo lại báo cáo Western (xóa cache)">🔄 Tạo lại</a>
        <?php endif; ?>

        <?php if ( $has_vedic ): ?>
            <a href="<?php echo esc_url( admin_url( 'admin-ajax.php?action=bccm_natal_report_full&coachee_id=' . $coachee_id . '&chart_type=vedic&_wpnonce=' . wp_create_nonce( 'bccm_natal_report_full' ) ) ); ?>" target="_blank" class="button" style="background:#7c3aed;color:#fff;border-color:#6d28d9;">🕉️ Luận Giải AI — Vedic</a>
        <?php endif; ?>

        <?php if ( $has_western ):
            $transit_nonce = wp_create_nonce( 'bccm_transit_report' );
        ?>
            <span style="border-left:2px solid #e5e7eb;height:24px;"></span>
            <a href="<?php echo esc_url( admin_url( 'admin-ajax.php?action=bccm_transit_report&coachee_id=' . $coachee_id . '&period=week&_wpnonce=' . $transit_nonce ) ); ?>" target="_blank" class="button" style="background:#0ea5e9;color:#fff;border-color:#0284c7;">🔮 Transit Tuần tới</a>
            <a href="<?php echo esc_url( admin_url( 'admin-ajax.php?action=bccm_transit_report&coachee_id=' . $coachee_id . '&period=month&_wpnonce=' . $transit_nonce ) ); ?>" target="_blank" class="button" style="background:#8b5cf6;color:#fff;border-color:#7c3aed;">🔮 Transit Tháng tới</a>
            <a href="<?php echo esc_url( admin_url( 'admin-ajax.php?action=bccm_transit_report&coachee_id=' . $coachee_id . '&period=year&_wpnonce=' . $transit_nonce ) ); ?>" target="_blank" class="button" style="background:#059669;color:#fff;border-color:#047857;">🔮 Transit Năm tới</a>
        <?php endif; ?>
    </div>

    <!-- ══════════ Natal Chart Public Link ══════════ -->
    <?php if ( $natal_url ): ?>
    <div style="margin-bottom:20px;padding:12px;background:#f0fdf4;border:1px solid #86efac;border-radius:8px;">
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
            <strong style="color:#166534;font-size:13px;">🌐 Bản đồ sao độc lập:</strong>
            <input type="text" readonly value="<?php echo esc_attr( $natal_url ); ?>" style="flex:1;min-width:300px;padding:6px 10px;border:1px solid #86efac;border-radius:4px;font-size:12px;font-family:monospace;background:#fff" />
            <a href="<?php echo esc_url( $natal_url ); ?>" target="_blank" class="button" style="background:#10b981;color:#fff;border-color:#059669;">🔗 Mở</a>
            <button type="button" class="button" onclick="navigator.clipboard.writeText('<?php echo esc_js( $natal_url ); ?>'); this.textContent='✅ Đã copy!'">📋 Copy</button>
        </div>
    </div>
    <?php endif; endif; ?>

    <!-- ══════════ BIG 3 (Sun, Moon, Ascendant) ══════════ -->
    <?php
    $sun_sign = $astro_summary['sun_sign'] ?? $vedic_summary['sun_sign'] ?? '';
    $moon_sign = $astro_summary['moon_sign'] ?? $vedic_summary['moon_sign'] ?? '';
    $asc_sign = $astro_summary['ascendant_sign'] ?? $vedic_summary['ascendant_sign'] ?? '';

    if ( $sun_sign || $moon_sign || $asc_sign ):
        $signs = function_exists( 'bccm_zodiac_signs' ) ? bccm_zodiac_signs() : [];
        $find_sign = function( $name ) use ( $signs ) {
            foreach ( $signs as $s ) {
                if ( strcasecmp( $s['en'] ?? '', $name ) === 0 ) return $s;
            }
            return null;
        };
    ?>
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:20px;">
        <?php
        $big3 = [
            [ 'label' => 'Mặt Trời (Sun)', 'sign' => $sun_sign, 'icon' => '☀️', 'desc' => 'Bản ngã, tính cách cốt lõi', 'color' => '#f59e0b' ],
            [ 'label' => 'Mặt Trăng (Moon)', 'sign' => $moon_sign, 'icon' => '🌙', 'desc' => 'Cảm xúc, thế giới nội tâm', 'color' => '#8b5cf6' ],
            [ 'label' => 'AC (Ascendant)', 'sign' => $asc_sign, 'icon' => '⬆️', 'desc' => 'Ấn tượng đầu tiên, vẻ ngoài', 'color' => '#3b82f6' ],
        ];
        foreach ( $big3 as $item ):
            if ( ! $item['sign'] ) continue;
            $si = $find_sign( $item['sign'] );
        ?>
            <div style="text-align:center;padding:16px;background:linear-gradient(135deg,<?php echo $item['color']; ?>11,<?php echo $item['color']; ?>22);border:1px solid <?php echo $item['color']; ?>44;border-radius:12px;">
                <div style="font-size:28px;"><?php echo $si ? $si['symbol'] : $item['icon']; ?></div>
                <div style="font-weight:700;font-size:15px;margin:4px 0;color:<?php echo $item['color']; ?>;"><?php echo esc_html( $si ? $si['vi'] : $item['sign'] ); ?></div>
                <div style="font-size:12px;color:#6b7280;margin-bottom:2px;"><?php echo esc_html( $item['sign'] ); ?></div>
                <div style="font-size:11px;color:#9ca3af;"><?php echo $item['label']; ?></div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ══════════ PLANET POSITIONS TABLE ══════════ -->
    <?php
    $positions = $astro_traits['positions'] ?? [];
    if ( ! empty( $positions ) ):
        $planet_vi = function_exists( 'bccm_planet_names_vi' ) ? bccm_planet_names_vi() : [];
        $planet_order = [ 'Sun','Moon','Mercury','Venus','Mars','Jupiter','Saturn','Uranus','Neptune','Pluto','Chiron','Lilith','True Node','Mean Node','Ascendant','Descendant','MC','IC' ];
        $planet_symbols = [ 'Sun' => '☉','Moon' => '☽','Mercury' => '☿','Venus' => '♀','Mars' => '♂','Jupiter' => '♃','Saturn' => '♄','Uranus' => '♅','Neptune' => '♆','Pluto' => '♇','Chiron' => '⚷','Lilith' => '⚸','True Node' => '☊','Mean Node' => '☊','Ascendant' => 'AC','Descendant' => 'DC','MC' => 'MC','IC' => 'IC' ];
    ?>
    <div class="postbox" style="margin-bottom:16px;"><div class="inside">
        <h3 style="margin-top:0;">🪐 Vị Trí Các Hành Tinh</h3>
        <table class="widefat" style="font-size:13px;"><thead><tr>
            <th style="width:28%;">Hành tinh</th>
            <th style="width:18%;">Cung</th>
            <th style="width:22%;">Vị trí</th>
            <th style="width:12%;">Nhà</th>
            <th style="width:10%;">Nghịch hành</th>
        </tr></thead><tbody>
        <?php
        $row_idx = 0;
        foreach ( $planet_order as $pname ):
            $pdata = $positions[ $pname ] ?? null;
            if ( ! $pdata ) continue;
            $sign      = $pdata['sign'] ?? $pdata['zodiac'] ?? '?';
            $deg       = $pdata['normDegree'] ?? $pdata['degree'] ?? '?';
            $full_deg  = $pdata['fullDegree'] ?? $pdata['full_degree'] ?? '';
            $house     = $pdata['house'] ?? '—';
            $retro     = ! empty( $pdata['isRetro'] ) || ! empty( $pdata['is_retro'] );
            $sym       = $planet_symbols[ $pname ] ?? '';
            $vi_name   = $planet_vi[ $pname ] ?? $pname;

            $signs_data = function_exists( 'bccm_zodiac_signs' ) ? bccm_zodiac_signs() : [];
            $sign_sym = '';
            foreach ( $signs_data as $sd ) {
                if ( strcasecmp( $sd['en'] ?? '', $sign ) === 0 ) { $sign_sym = $sd['symbol'] ?? ''; break; }
            }

            $bg = $row_idx % 2 ? '#f9fafb' : '#fff';
        ?>
            <tr style="background:<?php echo $bg; ?>;">
                <td><strong><?php echo $sym . ' ' . esc_html( $vi_name ); ?></strong> <span style="color:#9ca3af;font-size:11px;">(<?php echo esc_html( $pname ); ?>)</span></td>
                <td><?php echo $sign_sym . ' ' . esc_html( $sign ); ?></td>
                <td><?php echo is_numeric( $deg ) ? number_format( (float) $deg, 4 ) . '°' : esc_html( $deg ); ?></td>
                <td style="text-align:center;"><?php echo esc_html( $house ); ?></td>
                <td style="text-align:center;"><?php echo $retro ? '<span style="color:#dc2626;font-weight:700;">℞ Retro</span>' : '—'; ?></td>
            </tr>
        <?php $row_idx++; endforeach; ?>
        </tbody></table>
        <p style="font-size:11px;color:#3b82f6;margin-top:4px;">Nguồn: Freeze Astrology API — freeastrologyapi.com</p>
    </div></div>
    <?php endif; ?>

    <!-- ══════════ HOUSES TABLE ══════════ -->
    <?php
    $houses_data = $astro_traits['houses'] ?? [];
    $houses_raw = [];
    if ( ! empty( $houses_data ) ) {
        if ( isset( $houses_data[0]['House'] ) || isset( $houses_data[0]['house'] ) ) {
            $houses_raw = $houses_data;
        } elseif ( isset( $houses_data['Houses'] ) ) {
            $houses_raw = $houses_data['Houses'];
        }
    }
    if ( ! empty( $houses_raw ) ):
        $house_meanings = function_exists( 'bccm_house_meanings_vi' ) ? bccm_house_meanings_vi() : [];
        $signs_all = function_exists( 'bccm_zodiac_signs' ) ? bccm_zodiac_signs() : [];
    ?>
    <div class="postbox" style="margin-bottom:16px;"><div class="inside">
        <h3 style="margin-top:0;">🏛️ Vị Trí 12 Cung Nhà</h3>
        <table class="widefat" style="font-size:13px;"><thead><tr>
            <th style="width:12%;">Nhà</th>
            <th style="width:18%;">Cung</th>
            <th style="width:22%;">Đỉnh cung</th>
            <th>Ý nghĩa</th>
        </tr></thead><tbody>
        <?php foreach ( $houses_raw as $h ):
            $house_num = $h['House'] ?? $h['house'] ?? '?';
            $h_sign    = $h['sign'] ?? '?';
            $h_deg     = $h['degree'] ?? $h['normDegree'] ?? '?';
            $meaning   = $house_meanings[ (int) $house_num ] ?? '';
            $h_sym     = '';
            foreach ( $signs_all as $sd ) {
                if ( strcasecmp( $sd['en'] ?? '', $h_sign ) === 0 ) { $h_sym = $sd['symbol'] ?? ''; break; }
            }
        ?>
            <tr>
                <td style="font-weight:600;">Nhà <?php echo esc_html( $house_num ); ?></td>
                <td><?php echo $h_sym . ' ' . esc_html( $h_sign ); ?></td>
                <td><?php echo is_numeric( $h_deg ) ? number_format( (float) $h_deg, 4 ) . '°' : esc_html( $h_deg ); ?></td>
                <td style="color:#6b7280;font-size:12px;"><?php echo esc_html( $meaning ); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table>
    </div></div>
    <?php endif; ?>

    <!-- ══════════ ASPECTS TABLE ══════════ -->
    <?php
    $aspects = $astro_traits['aspects'] ?? [];
    if ( ! empty( $aspects ) && ! empty( $positions ) ):
        $enriched   = function_exists( 'bccm_astro_enrich_aspects' )         ? bccm_astro_enrich_aspects( $aspects, $positions )     : $aspects;
        $grouped    = function_exists( 'bccm_astro_group_aspects_by_planet' ) ? bccm_astro_group_aspects_by_planet( $enriched )       : [];
        $planet_vi2 = function_exists( 'bccm_planet_names_vi' )   ? bccm_planet_names_vi()   : [];
        $aspect_vi  = function_exists( 'bccm_aspect_names_vi' )   ? bccm_aspect_names_vi()   : [];
        $aspect_sym = function_exists( 'bccm_aspect_symbols' )    ? bccm_aspect_symbols()    : [];
        $aspect_clr = function_exists( 'bccm_aspect_colors' )     ? bccm_aspect_colors()     : [];
    ?>
    <div class="postbox" style="margin-bottom:16px;"><div class="inside">
        <h3 style="margin-top:0;">🔗 Góc Chiếu (<?php echo count( $enriched ); ?> aspects)</h3>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px;padding:8px;background:#f9fafb;border-radius:8px;font-size:11px;">
        <?php foreach ( $aspect_vi as $aen => $avi ):
            $asym = $aspect_sym[ $aen ] ?? '';
            $aclr = $aspect_clr[ $aen ] ?? '#666';
        ?>
            <span style="color:<?php echo $aclr; ?>"><?php echo $asym . ' ' . esc_html( $avi ); ?></span>
        <?php endforeach; ?>
        </div>
        <table class="widefat" style="font-size:13px;"><thead><tr>
            <th style="width:22%;">Hành tinh 1</th>
            <th style="width:8%;text-align:center;"></th>
            <th style="width:22%;">Góc chiếu</th>
            <th style="width:22%;">Hành tinh 2</th>
            <th style="width:16%;">Orb</th>
        </tr></thead><tbody>
        <?php
        if ( ! empty( $grouped ) ):
            foreach ( $grouped as $planet_key => $planet_aspects ):
                $p1_vi = $planet_vi2[ $planet_key ] ?? $planet_key;
                foreach ( $planet_aspects as $asp ):
                    $p2       = $asp['aspecting_planet'] ?? $asp['planet2'] ?? '?';
                    $p2_vi    = $planet_vi2[ $p2 ] ?? $p2;
                    $asp_type = $asp['aspect_type'] ?? $asp['type'] ?? '?';
                    $orb_val  = $asp['orb'] ?? '?';
                    $sym      = $aspect_sym[ $asp_type ] ?? '';
                    $clr      = $aspect_clr[ $asp_type ] ?? '#666';
                    $a_vi     = $aspect_vi[ $asp_type ] ?? $asp_type;
                ?>
                    <tr>
                        <td><strong><?php echo esc_html( $p1_vi ); ?></strong></td>
                        <td style="text-align:center;color:<?php echo $clr; ?>;font-size:16px;"><?php echo $sym; ?></td>
                        <td style="color:<?php echo $clr; ?>;font-weight:600;"><?php echo esc_html( $a_vi ); ?></td>
                        <td><strong><?php echo esc_html( $p2_vi ); ?></strong></td>
                        <td><?php echo is_numeric( $orb_val ) ? number_format( (float) $orb_val, 2 ) . '°' : esc_html( $orb_val ); ?></td>
                    </tr>
                <?php endforeach;
            endforeach;
        else:
            foreach ( $enriched as $asp ):
                $p1       = $asp['aspecting_planet_en'] ?? $asp['planet1'] ?? '?';
                $p2       = $asp['aspected_planet_en'] ?? $asp['planet2'] ?? '?';
                $asp_type = $asp['type'] ?? '?';
                $orb_val  = $asp['orb'] ?? '?';
            ?>
                <tr>
                    <td><strong><?php echo esc_html( $planet_vi2[ $p1 ] ?? $p1 ); ?></strong></td>
                    <td style="text-align:center;"><?php echo $aspect_sym[ $asp_type ] ?? ''; ?></td>
                    <td><?php echo esc_html( $aspect_vi[ $asp_type ] ?? $asp_type ); ?></td>
                    <td><strong><?php echo esc_html( $planet_vi2[ $p2 ] ?? $p2 ); ?></strong></td>
                    <td><?php echo is_numeric( $orb_val ) ? number_format( (float) $orb_val, 2 ) . '°' : esc_html( $orb_val ); ?></td>
                </tr>
            <?php endforeach;
        endif; ?>
        </tbody></table>
    </div></div>
    <?php endif; ?>

    <!-- ══════════ Maps / Plans ══════════ -->
    <?php if ( ! empty( $plans ) ): ?>
    <div style="margin-bottom:16px;padding:16px;background:#fefce8;border:1px solid #fde68a;border-radius:12px;">
        <h3 style="margin:0 0 12px;font-size:15px;">🗺️ Bản đồ & Kế hoạch (<?php echo count( $plans ); ?>)</h3>
        <table style="width:100%;font-size:13px;border-collapse:collapse;">
            <thead><tr style="background:#fef3c7;">
                <th style="padding:8px 10px;text-align:left;border-bottom:1px solid #fde68a;">#</th>
                <th style="padding:8px 10px;text-align:left;border-bottom:1px solid #fde68a;">Coach Type</th>
                <th style="padding:8px 10px;text-align:left;border-bottom:1px solid #fde68a;">Status</th>
                <th style="padding:8px 10px;text-align:left;border-bottom:1px solid #fde68a;">Public Key</th>
                <th style="padding:8px 10px;text-align:left;border-bottom:1px solid #fde68a;">Tạo lúc</th>
                <th style="padding:8px 10px;text-align:left;border-bottom:1px solid #fde68a;">Thao tác</th>
            </tr></thead>
            <tbody>
            <?php foreach ( $plans as $i => $plan ):
                $type_label = $types_meta[ $plan['coach_type'] ?? '' ]['label'] ?? ( $plan['coach_type'] ?? '—' );
                $pub_key    = $plan['public_key'] ?? '';
                $map_url    = $pub_key && function_exists( 'bccm_public_map_url' ) ? bccm_public_map_url( $pub_key ) : '';
            ?>
                <tr style="border-bottom:1px solid #fef3c7;">
                    <td style="padding:8px 10px;"><?php echo $i + 1; ?></td>
                    <td style="padding:8px 10px;"><?php echo esc_html( $type_label ); ?></td>
                    <td style="padding:8px 10px;">
                        <span style="padding:2px 8px;border-radius:6px;font-size:11px;font-weight:700;
                            <?php echo ( $plan['status'] ?? '' ) === 'active' ? 'background:#dcfce7;color:#166534;' : 'background:#f1f5f9;color:#6b7280;'; ?>">
                            <?php echo esc_html( $plan['status'] ?? 'unknown' ); ?>
                        </span>
                    </td>
                    <td style="padding:8px 10px;font-family:monospace;font-size:11px;"><?php echo esc_html( mb_strimwidth( $pub_key, 0, 20, '...' ) ); ?></td>
                    <td style="padding:8px 10px;color:#6b7280;"><?php echo esc_html( $plan['created_at'] ?? '—' ); ?></td>
                    <td style="padding:8px 10px;">
                        <?php if ( $map_url ): ?>
                            <a href="<?php echo esc_url( $map_url ); ?>" target="_blank" class="button button-small">🔗 Xem</a>
                        <?php else: echo '—'; endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- ══════════ Multiple Coachee Profiles ══════════ -->
    <?php if ( count( $coachees ) > 1 ): ?>
    <div style="margin-bottom:16px;padding:16px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px;">
        <h3 style="margin:0 0 12px;font-size:15px;">📂 Tất cả hồ sơ (<?php echo count( $coachees ); ?>)</h3>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:10px;">
        <?php foreach ( $coachees as $c ):
            $c_type_label = $types_meta[ $c['coach_type'] ?? '' ]['label'] ?? ( $c['coach_type'] ?? '—' );
        ?>
            <div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:12px;font-size:13px;">
                <div style="font-weight:600;margin-bottom:4px;">#<?php echo $c['id']; ?> — <?php echo esc_html( $c_type_label ); ?></div>
                <div style="color:#6b7280;">Platform: <?php echo esc_html( $c['platform_type'] ?? 'WEB' ); ?></div>
                <div style="color:#6b7280;">Cập nhật: <?php echo esc_html( $c['updated_at'] ?? '—' ); ?></div>
            </div>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ══════════ Gen Results ══════════ -->
    <?php if ( ! empty( $gen_results ) ): ?>
    <div style="margin-bottom:16px;padding:16px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:12px;">
        <h3 style="margin:0 0 12px;font-size:15px;">📊 Kết quả phân tích (<?php echo count( $gen_results ); ?>)</h3>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:8px;">
        <?php foreach ( $gen_results as $gr ): ?>
            <div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:10px;font-size:12px;">
                <div style="font-weight:600;color:#1e40af;margin-bottom:4px;"><?php echo esc_html( $gr['gen_key'] ?? $gr['gen_fn'] ?? '—' ); ?></div>
                <div style="color:#6b7280;">
                    Status: <span style="font-weight:600;color:<?php echo ( $gr['status'] ?? '' ) === 'done' ? '#16a34a' : '#d97706'; ?>;"><?php echo esc_html( $gr['status'] ?? 'pending' ); ?></span>
                </div>
                <div style="color:#9ca3af;font-size:11px;"><?php echo esc_html( $gr['created_at'] ?? '—' ); ?></div>
            </div>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ══════════ Transit Snapshots ══════════ -->
    <?php if ( ! empty( $transits ) ): ?>
    <div style="margin-bottom:16px;padding:16px;background:#faf5ff;border:1px solid #e9d5ff;border-radius:12px;">
        <h3 style="margin:0 0 12px;font-size:15px;">🔄 Transit Snapshots (<?php echo count( $transits ); ?> gần nhất)</h3>
        <div style="display:flex;flex-wrap:wrap;gap:8px;">
        <?php foreach ( $transits as $ts ): ?>
            <span style="display:inline-block;padding:6px 12px;background:#fff;border:1px solid #e9d5ff;border-radius:8px;font-size:12px;font-weight:600;color:#7c3aed;">
                📅 <?php echo esc_html( $ts['target_date'] ?? '—' ); ?>
            </span>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ══════════ Chart Summaries ══════════ -->
    <?php if ( ! empty( $astro_row['summary'] ) ):
        $w_sum = json_decode( $astro_row['summary'], true );
    ?>
    <div style="margin-bottom:12px;padding:16px;background:#eff6ff;border-radius:12px;">
        <h3 style="margin:0 0 8px;font-size:15px;">🔮 Tóm tắt Western Chart</h3>
        <?php if ( ! empty( $w_sum['personality'] ) ): ?>
            <p style="font-size:13px;color:#374151;line-height:1.6;"><?php echo esc_html( $w_sum['personality'] ); ?></p>
        <?php else: ?>
            <pre style="font-size:12px;max-height:200px;overflow:auto;background:#fff;padding:12px;border-radius:8px;"><?php echo esc_html( $astro_row['summary'] ); ?></pre>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ( ! empty( $vedic_row['summary'] ) ):
        $v_sum = json_decode( $vedic_row['summary'], true );
    ?>
    <div style="margin-bottom:12px;padding:16px;background:#f5f3ff;border-radius:12px;">
        <h3 style="margin:0 0 8px;font-size:15px;">🕉️ Tóm tắt Vedic Chart</h3>
        <?php if ( ! empty( $v_sum['personality'] ) ): ?>
            <p style="font-size:13px;color:#374151;line-height:1.6;"><?php echo esc_html( $v_sum['personality'] ); ?></p>
        <?php else: ?>
            <pre style="font-size:12px;max-height:200px;overflow:auto;background:#fff;padding:12px;border-radius:8px;"><?php echo esc_html( $vedic_row['summary'] ); ?></pre>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    </div><!-- .wrap -->
    <?php
}

/* ══════════════════════════════════════════════════════════════
 * Simple geocode helper (Vietnamese cities → lat/lng/tz)
 * ════════════════════════════════════════════════════════════ */
function bccm_admin_simple_geocode( $place ) {
    $map = [
        'hà nội'        => [ 21.0278, 105.8342 ],
        'hanoi'         => [ 21.0278, 105.8342 ],
        'hồ chí minh'   => [ 10.8231, 106.6297 ],
        'sài gòn'       => [ 10.8231, 106.6297 ],
        'saigon'        => [ 10.8231, 106.6297 ],
        'đà nẵng'       => [ 16.0544, 108.2022 ],
        'hải phòng'     => [ 20.8449, 106.6881 ],
        'cần thơ'       => [ 10.0452, 105.7469 ],
        'huế'           => [ 16.4637, 107.5909 ],
        'nha trang'     => [ 12.2388, 109.1967 ],
        'đà lạt'        => [ 11.9404, 108.4583 ],
        'vũng tàu'      => [ 10.346,  107.0843 ],
        'biên hòa'      => [ 10.9478, 106.8246 ],
        'buôn ma thuột'  => [ 12.6667, 108.05   ],
        'thái nguyên'   => [ 21.5928, 105.8442 ],
        'nam định'      => [ 20.4388, 106.1621 ],
        'vinh'          => [ 18.6796, 105.6813 ],
        'quy nhơn'      => [ 13.776,  109.2237 ],
        'hải dương'     => [ 20.9373, 106.3206 ],
        'bắc ninh'      => [ 21.186,  106.0763 ],
        'thanh hóa'     => [ 19.8067, 105.7852 ],
    ];
    $lower = mb_strtolower( trim( (string) $place ) );
    foreach ( $map as $k => $v ) {
        if ( mb_strpos( $lower, $k ) !== false ) {
            return [ 'lat' => $v[0], 'lon' => $v[1], 'tz' => 7.0 ];
        }
    }
    return [ 'lat' => 21.0278, 'lon' => 105.8342, 'tz' => 7.0 ]; // default: Hà Nội
}

/* ══════════════════════════════════════════════════════════════
 * ADD NEW: Create WP user (phone=username) + coachee + astro
 * ════════════════════════════════════════════════════════════ */
function bccm_admin_user_profile_add_new() {
    global $wpdb;
    $t       = bccm_tables();
    $t_astro = $wpdb->prefix . 'bccm_astro';

    wp_enqueue_style( 'bccm-admin' );

    $msg      = '';
    $msg_type = '';

    // ── Sticky form values ──
    $f = [
        'phone'       => '',
        'password'    => '',
        'full_name'   => '',
        'dob'         => '',
        'birth_time'  => '',
        'birth_place' => '',
        'coach_type'  => 'astro_coach',
        'auto_chart'  => 'both',
    ];

    /* ==================== HANDLE POST ==================== */
    if ( ! empty( $_POST['bccm_add_new_user'] ) && check_admin_referer( 'bccm_add_new_user' ) ) {

        // Sanitize
        $f['phone']       = preg_replace( '/[^0-9]/', '', sanitize_text_field( $_POST['phone'] ?? '' ) );
        $f['password']    = $_POST['password'] ?? '';
        $f['full_name']   = sanitize_text_field( $_POST['full_name'] ?? '' );
        $f['dob']         = sanitize_text_field( $_POST['dob'] ?? '' );
        $f['birth_time']  = sanitize_text_field( $_POST['birth_time'] ?? '' );
        $f['birth_place'] = sanitize_text_field( $_POST['birth_place'] ?? '' );
        $f['coach_type']  = sanitize_text_field( $_POST['coach_type'] ?? 'astro_coach' );
        $f['auto_chart']  = sanitize_text_field( $_POST['auto_chart'] ?? '' );

        // Normalize phone: +84 / 84 → 0
        $phone = $f['phone'];
        if ( substr( $phone, 0, 2 ) === '84' && strlen( $phone ) > 9 ) {
            $phone = '0' . substr( $phone, 2 );
            $f['phone'] = $phone;
        }

        // Validate
        if ( strlen( $phone ) < 10 ) {
            $msg = '⚠️ Số điện thoại không hợp lệ (tối thiểu 10 số).';
            $msg_type = 'error';
        } elseif ( strlen( $f['password'] ) < 6 ) {
            $msg = '⚠️ Mật khẩu tối thiểu 6 ký tự.';
            $msg_type = 'error';
        } else {
            // Check if phone (username) already exists
            $existing_uid = username_exists( $phone );
            if ( $existing_uid ) {
                $detail_url = admin_url( 'admin.php?page=bccm_user_profiles&action=view&user_id=' . $existing_uid );
                $msg = '⚠️ Số điện thoại <strong>' . esc_html( $phone ) . '</strong> đã có tài khoản (user #' . $existing_uid . '). <a href="' . esc_url( $detail_url ) . '">👉 Xem hồ sơ</a>';
                $msg_type = 'error';
            } else {
                // ── Create WP user ──
                $email   = $phone . '@bizcity.vn';
                $user_id = wp_create_user( $phone, $f['password'], $email );

                if ( is_wp_error( $user_id ) ) {
                    $msg = '❌ Lỗi tạo tài khoản: ' . $user_id->get_error_message();
                    $msg_type = 'error';
                } else {
                    // Update display name
                    wp_update_user( [
                        'ID'           => $user_id,
                        'display_name' => $f['full_name'] ?: $phone,
                        'first_name'   => $f['full_name'],
                    ] );
                    // Save phone to billing meta
                    update_user_meta( $user_id, 'billing_phone', $phone );

                    // ── Create coachee profile ──
                    $coachee_id = 0;
                    if ( function_exists( 'bccm_upsert_profile' ) ) {
                        $coachee_id = bccm_upsert_profile( [
                            'coach_type'    => $f['coach_type'],
                            'full_name'     => $f['full_name'],
                            'phone'         => $phone,
                            'dob'           => $f['dob'],
                            'user_id'       => $user_id,
                            'platform_type' => 'WEBCHAT',
                        ] );
                    }

                    // ── Save astro birth data ──
                    if ( $f['birth_time'] && $f['dob'] && $coachee_id ) {
                        $geo = bccm_admin_simple_geocode( $f['birth_place'] );
                        $wpdb->insert( $t_astro, [
                            'coachee_id'  => $coachee_id,
                            'user_id'     => $user_id,
                            'chart_type'  => 'western',
                            'birth_time'  => $f['birth_time'],
                            'birth_place' => $f['birth_place'],
                            'latitude'    => $geo['lat'],
                            'longitude'   => $geo['lon'],
                            'timezone'    => $geo['tz'],
                            'created_at'  => current_time( 'mysql' ),
                            'updated_at'  => current_time( 'mysql' ),
                        ] );
                    }

                    // ── Redirect to detail view (optionally auto-generate chart) ──
                    $redirect = admin_url( 'admin.php?page=bccm_user_profiles&action=view&user_id=' . $user_id . '&created=1' );
                    if ( $f['auto_chart'] && $f['birth_time'] && $f['dob'] ) {
                        $redirect .= '&auto_gen=' . $f['auto_chart'];
                    }
                    wp_safe_redirect( $redirect );
                    exit;
                }
            }
        }
    }

    // ── Coach types for dropdown ──
    $types_meta = function_exists( 'bccm_coach_types' ) ? bccm_coach_types() : [];
    $types_extra = (array) get_option( 'bccm_coach_types_extra', [] );
    $all_types = $types_meta + $types_extra;

    /* ==================== RENDER FORM ==================== */
    ?>
    <div class="wrap">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
        <h1 style="margin:0;">➕ Tạo mới khách hàng & Bản đồ sao</h1>
        <a href="<?php echo admin_url( 'admin.php?page=bccm_user_profiles' ); ?>" class="button">← Quay lại danh sách</a>
    </div>

    <?php if ( $msg ): ?>
        <div class="notice notice-<?php echo $msg_type === 'error' ? 'error' : 'success'; ?>" style="border-radius:8px;padding:12px 16px;">
            <p style="margin:0;"><?php echo $msg; ?></p>
        </div>
    <?php endif; ?>

    <form method="post" style="max-width:900px;">
        <?php wp_nonce_field( 'bccm_add_new_user' ); ?>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;">

            <!-- ═══ Column 1: Tài khoản ═══ -->
            <div style="background:#f8fafc;padding:20px;border-radius:12px;border:1px solid #e5e7eb;">
                <h3 style="margin:0 0 16px;font-size:15px;color:#1e40af;">📱 Tài khoản đăng nhập</h3>

                <p style="margin:0 0 12px;">
                    <label style="display:block;font-weight:700;margin-bottom:4px;">Số điện thoại <span style="color:#dc2626;">*</span></label>
                    <input type="text" name="phone" value="<?php echo esc_attr( $f['phone'] ); ?>" placeholder="VD: 0901234567" required
                           style="width:100%;padding:10px 14px;border:1px solid #d1d5db;border-radius:10px;font-size:14px;" inputmode="numeric" />
                    <small style="color:#6b7280;">Số điện thoại sẽ là tên đăng nhập (username)</small>
                </p>

                <p style="margin:0 0 12px;">
                    <label style="display:block;font-weight:700;margin-bottom:4px;">Mật khẩu <span style="color:#dc2626;">*</span></label>
                    <input type="text" name="password" value="<?php echo esc_attr( $f['password'] ); ?>" placeholder="Tối thiểu 6 ký tự" required
                           style="width:100%;padding:10px 14px;border:1px solid #d1d5db;border-radius:10px;font-size:14px;" />
                    <small style="color:#6b7280;">Hiện rõ để admin ghi nhớ / gửi cho khách</small>
                </p>

                <p style="margin:0 0 12px;">
                    <label style="display:block;font-weight:700;margin-bottom:4px;">Họ tên khách hàng</label>
                    <input type="text" name="full_name" value="<?php echo esc_attr( $f['full_name'] ); ?>" placeholder="VD: Nguyễn Văn A"
                           style="width:100%;padding:10px 14px;border:1px solid #d1d5db;border-radius:10px;font-size:14px;" />
                </p>

                <p style="margin:0;">
                    <label style="display:block;font-weight:700;margin-bottom:4px;">Loại Coach</label>
                    <select name="coach_type" style="width:100%;padding:10px 14px;border:1px solid #d1d5db;border-radius:10px;font-size:14px;">
                        <?php foreach ( $all_types as $slug => $meta ): ?>
                            <option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $f['coach_type'], $slug ); ?>>
                                <?php echo esc_html( $meta['label'] ?? $slug ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </p>
            </div>

            <!-- ═══ Column 2: Dữ liệu sinh ═══ -->
            <div style="background:#eff6ff;padding:20px;border-radius:12px;border:1px solid #bfdbfe;">
                <h3 style="margin:0 0 16px;font-size:15px;color:#7c3aed;">🌟 Dữ liệu chiêm tinh</h3>

                <p style="margin:0 0 12px;">
                    <label style="display:block;font-weight:700;margin-bottom:4px;">Ngày sinh</label>
                    <input type="date" name="dob" value="<?php echo esc_attr( $f['dob'] ); ?>"
                           style="width:100%;padding:10px 14px;border:1px solid #d1d5db;border-radius:10px;font-size:14px;" />
                </p>

                <p style="margin:0 0 12px;">
                    <label style="display:block;font-weight:700;margin-bottom:4px;">Giờ sinh</label>
                    <input type="time" name="birth_time" value="<?php echo esc_attr( $f['birth_time'] ); ?>" step="60"
                           style="width:100%;padding:10px 14px;border:1px solid #d1d5db;border-radius:10px;font-size:14px;" />
                    <small style="color:#6b7280;">Bắt buộc để tạo bản đồ sao chiêm tinh</small>
                </p>

                <p style="margin:0 0 12px;">
                    <label style="display:block;font-weight:700;margin-bottom:4px;">Nơi sinh</label>
                    <input type="text" name="birth_place" value="<?php echo esc_attr( $f['birth_place'] ); ?>" placeholder="VD: Hà Nội, Hồ Chí Minh..."
                           style="width:100%;padding:10px 14px;border:1px solid #d1d5db;border-radius:10px;font-size:14px;" />
                    <small style="color:#6b7280;">Hỗ trợ 20+ thành phố VN tự nhận tọa độ</small>
                </p>

                <p style="margin:0;">
                    <label style="display:block;font-weight:700;margin-bottom:4px;">Tự động tạo bản đồ sao</label>
                    <select name="auto_chart" style="width:100%;padding:10px 14px;border:1px solid #d1d5db;border-radius:10px;font-size:14px;">
                        <option value="both" <?php selected( $f['auto_chart'], 'both' ); ?>>⚡ Tạo cả Western + Vedic</option>
                        <option value="western" <?php selected( $f['auto_chart'], 'western' ); ?>>🌟 Chỉ Western</option>
                        <option value="vedic" <?php selected( $f['auto_chart'], 'vedic' ); ?>>🕉️ Chỉ Vedic</option>
                        <option value="" <?php selected( $f['auto_chart'], '' ); ?>>❌ Không tạo ngay (tạo sau)</option>
                    </select>
                </p>
            </div>

        </div>

        <!-- ═══ Info box ═══ -->
        <div style="background:#fefce8;border:1px solid #fde68a;border-radius:10px;padding:14px 18px;margin-bottom:20px;font-size:13px;color:#92400e;">
            <strong>ℹ️ Quy trình:</strong>
            Tạo tài khoản WP (username = SĐT, email = SĐT@bizcity.vn) → Tạo hồ sơ coachee → Lưu giờ/nơi sinh → Tự động gọi API tạo bản đồ sao → Chuyển sang trang chi tiết.
        </div>

        <!-- ═══ Submit ═══ -->
        <div style="display:flex;gap:10px;">
            <button type="submit" name="bccm_add_new_user" value="1" class="button button-primary" style="background:#059669;border-color:#047857;border-radius:10px;padding:10px 28px;font-size:14px;font-weight:700;">
                ✨ Tạo tài khoản & Bản đồ sao
            </button>
            <a href="<?php echo admin_url( 'admin.php?page=bccm_user_profiles' ); ?>" class="button" style="border-radius:10px;padding:10px 20px;font-size:14px;">Hủy</a>
        </div>
    </form>
    </div>
    <?php
}
