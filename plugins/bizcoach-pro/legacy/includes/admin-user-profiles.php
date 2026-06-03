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
        'bccm_user_profiles',
        "Astrology's list",
        "Astrology's list",
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

    // Schema-aware: subsite chưa migrate cột chart_type → bỏ JOIN astro,
    // các cột western_*/vedic_* sẽ NULL nhưng list vẫn render OK.
    if ( bccm_astro_supports_chart_type() ) {
        $astro_join  = "LEFT JOIN {$t_astro} a_w ON a_w.coachee_id = p.id AND a_w.chart_type = 'western'\n";
        $astro_join .= "        LEFT JOIN {$t_astro} a_v ON a_v.coachee_id = p.id AND a_v.chart_type = 'vedic'";
    } else {
        $astro_join  = "LEFT JOIN {$t_astro} a_w ON a_w.coachee_id = p.id AND 1=0\n";
        $astro_join .= "        LEFT JOIN {$t_astro} a_v ON a_v.coachee_id = p.id AND 1=0";
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
        {$astro_join}
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
        <?php
        // Flash message from BizCoach_Pro_Sprint_Diagnostic::handle_backfill_post()
        // when redirected back here via _redirect_to=bccm_user_profiles.
        $bcpro_msg = get_transient( 'bcpro_diag_backfill_msg' );
        if ( is_array( $bcpro_msg ) && ! empty( $bcpro_msg['text'] ) ) {
            delete_transient( 'bcpro_diag_backfill_msg' );
            $cls = $bcpro_msg['type'] === 'error' ? 'notice-error'
                : ( $bcpro_msg['type'] === 'warning' ? 'notice-warning' : 'notice-success' );
            echo '<div class="notice ' . esc_attr( $cls ) . ' is-dismissible"><p>'
                . esc_html( $bcpro_msg['text'] ) . '</p></div>';
        }
        $bcpro_nonce_all = wp_create_nonce( 'bcpro_diag_backfill_all' );
        ?>
        <h1 style="display:flex;align-items:center;gap:10px;">
            Hồ sơ thành viên
            <span style="background:#e0e7ff;color:#3730a3;padding:4px 12px;border-radius:20px;font-size:13px;font-weight:600;"><?php echo $total; ?> người dùng</span>
            <span style="margin-left:auto;display:inline-flex;gap:8px;align-items:center;">
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                      style="margin:0;display:inline"
                      onsubmit="return confirm('Backfill public URLs cho TẤT CẢ coachee còn thiếu key?\n→ gọi bccm_ensure_action_plan() · cần thiết để twinchat ingest.');">
                    <input type="hidden" name="action" value="bcpro_diag_backfill" />
                    <input type="hidden" name="slug"   value="all" />
                    <input type="hidden" name="_redirect_to" value="bccm_user_profiles" />
                    <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $bcpro_nonce_all ); ?>" />
                    <button type="submit" class="button"
                            title="Gọi bccm_ensure_action_plan() cho mọi coachee còn thiếu public_key. Cần thiết để twinchat ingest qua /coachee-map/{key}/."
                            style="background:#1d4ed8;color:#fff;border-color:#1e40af;border-radius:8px;padding:6px 14px;font-size:13px;">
                        ▶ Backfill ALL public URLs
                    </button>
                </form>
                <a href="<?php echo admin_url( 'admin.php?page=bccm_user_profiles&action=add_new' ); ?>" class="button button-primary" style="background:#059669;border-color:#047857;border-radius:8px;padding:6px 16px;font-size:13px;">➕ Tạo mới khách hàng</a>
            </span>
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

    // Resolve coachee. Prefer the coachee_id passed in the URL (set by the
    // POST handler right after bccm_upsert_profile) so we never depend on a
    // user_id→profile SELECT that may hit a read replica before it has
    // replicated. Fall back to user_id lookup only when coachee_id is absent.
    $url_coachee_id = isset( $_GET['coachee_id'] ) ? (int) $_GET['coachee_id'] : 0;
    $coachees = [];
    if ( $url_coachee_id > 0 ) {
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$t['profiles']} WHERE id = %d",
            $url_coachee_id
        ), ARRAY_A );
        if ( $row ) {
            $coachees = [ $row ];
        } else {
            // Replica hasn't replicated the new row yet — build a minimal
            // in-memory placeholder from the URL + WP user so the page can
            // still render the chart-generation form for $view_uid.
            $coachees = [ [
                'id'            => $url_coachee_id,
                'user_id'       => $view_uid,
                'platform_type' => 'WEBCHAT',
                'coach_type'    => 'astro_coach',
                'full_name'     => $user ? ( $user->display_name ?: $user->user_login ) : ( 'User #' . $view_uid ),
                'phone'         => $user ? get_user_meta( $view_uid, 'billing_phone', true ) : '',
                'dob'           => '',
                'address'       => '',
                'zodiac_sign'   => '',
            ] ];
        }
    } else {
        $coachees = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$t['profiles']} WHERE user_id = %d ORDER BY updated_at DESC",
            $view_uid
        ), ARRAY_A );
    }

    if ( empty( $coachees ) ) {
        echo '<div class="wrap"><div class="notice notice-warning"><p>Không tìm thấy hồ sơ cho user ID ' . esc_html( $view_uid ) . '.</p></div>';
        echo '<p><a href="' . admin_url( 'admin.php?page=bccm_user_profiles' ) . '" class="button">← Quay lại danh sách</a></p></div>';
        return;
    }

    // Primary coachee
    $coachee    = $coachees[0];
    $coachee_id = (int) $coachee['id'];
    $coachee_ids = array_column( $coachees, 'id' );

    // Astro data — schema-aware (subsite có thể thiếu user_id hoặc chart_type)
    $astro_row   = bccm_astro_fetch_by_user_chart( $view_uid, $coachee_id, 'western' );
    $vedic_row   = bccm_astro_fetch_by_user_chart( $view_uid, $coachee_id, 'vedic' );
    $chinese_row = bccm_astro_fetch_by_user_chart( $view_uid, $coachee_id, 'chinese' );
    if ( ! $astro_row ) $astro_row = $vedic_row;
    if ( ! $astro_row ) $astro_row = $chinese_row;

    /* ==================== HANDLE POST / AUTO-GEN ACTIONS ==================== */
    $auto_gen = sanitize_text_field( $_GET['auto_gen'] ?? '' );
    $action   = '';

    if ( ! empty( $_POST['bccm_action'] ) && check_admin_referer( 'bccm_user_detail_' . $view_uid ) ) {
        $action = sanitize_text_field( $_POST['bccm_action'] );
    } elseif ( $auto_gen ) {
        // Idempotency guard: only auto-trigger chart generation when the target
        // chart is missing. Without this guard, every page reload (incl. browser
        // preload, double-click, devtools refresh) re-fires the upstream API,
        // burning quota and triggering 429 Too Many Requests.
        $w_has = ! empty( $astro_row['traits'] ) || ! empty( $astro_row['summary'] );
        $v_has = ! empty( $vedic_row['traits'] ) || ! empty( $vedic_row['summary'] );
        $need_western = ( $auto_gen === 'western' || $auto_gen === 'both' ) && ! $w_has;
        $need_vedic   = ( $auto_gen === 'vedic'   || $auto_gen === 'both' ) && ! $v_has;
        if ( $need_western && $need_vedic ) {
            $action = 'gen_both_charts';
        } elseif ( $need_vedic ) {
            $action = 'gen_vedic_chart';
        } elseif ( $need_western ) {
            $action = 'gen_free_chart';
        } else {
            // Already has the requested chart(s) — strip auto_gen and redirect
            // server-side to keep the URL clean (so F5 doesn't re-trigger).
            $clean = remove_query_arg( array( 'auto_gen', 'created' ) );
            if ( ! headers_sent() ) {
                wp_safe_redirect( $clean );
                exit;
            }
        }
    }

    /* ── Update profile (personal info + birth data) ── */
    if ( $action === 'update_profile' ) {
        $up_full   = sanitize_text_field( wp_unslash( $_POST['full_name']   ?? '' ) );
        $up_phone  = sanitize_text_field( wp_unslash( $_POST['phone']       ?? '' ) );
        $up_dob    = sanitize_text_field( wp_unslash( $_POST['dob']         ?? '' ) );
        $up_addr   = sanitize_text_field( wp_unslash( $_POST['address']     ?? '' ) );
        $up_btime  = sanitize_text_field( wp_unslash( $_POST['birth_time']  ?? '' ) );
        $up_bplace = sanitize_text_field( wp_unslash( $_POST['birth_place'] ?? '' ) );
        $up_lat    = isset( $_POST['latitude']  ) && $_POST['latitude']  !== '' ? floatval( $_POST['latitude']  ) : null;
        $up_lng    = isset( $_POST['longitude'] ) && $_POST['longitude'] !== '' ? floatval( $_POST['longitude'] ) : null;
        $up_tz     = isset( $_POST['timezone']  ) && $_POST['timezone']  !== '' ? floatval( $_POST['timezone']  ) : 7.0;
        $regen     = ! empty( $_POST['regen_after_save'] );

        // Update coachee profile
        $wpdb->update(
            $t['profiles'],
            [
                'full_name'  => $up_full,
                'phone'      => $up_phone,
                'dob'        => $up_dob ?: null,
                'address'    => $up_addr,
                'updated_at' => current_time( 'mysql' ),
            ],
            [ 'id' => $coachee_id ]
        );

        // Upsert astro birth data for both chart_types so Western & Vedic stay in sync
        $now = current_time( 'mysql' );
        $has_chart_type = function_exists('bccm_astro_supports_chart_type') ? bccm_astro_supports_chart_type() : true;
        $has_user_id    = function_exists('bccm_astro_supports_user_id') ? bccm_astro_supports_user_id() : true;
        $chart_types_to_save = $has_chart_type ? [ 'western', 'vedic' ] : [ null ];
        foreach ( $chart_types_to_save as $_ct ) {
            if ( $has_chart_type ) {
                $existing_id = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT id FROM $t_astro WHERE coachee_id=%d AND chart_type=%s",
                    $coachee_id, $_ct
                ) );
            } else {
                $existing_id = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT id FROM $t_astro WHERE coachee_id=%d",
                    $coachee_id
                ) );
            }
            $row_data = [
                'birth_place' => $up_bplace,
                'birth_time'  => $up_btime,
                'latitude'    => $up_lat,
                'longitude'   => $up_lng,
                'timezone'    => $up_tz,
                'updated_at'  => $now,
            ];
            if ( $existing_id ) {
                $wpdb->update( $t_astro, bccm_astro_filter_row_to_existing_columns( $row_data ), [ 'id' => $existing_id ] );
            } else {
                $insert_row = array_merge( $row_data, [
                    'coachee_id' => $coachee_id,
                    'created_at' => $now,
                ] );
                if ( $has_user_id )    $insert_row['user_id']    = $view_uid;
                if ( $has_chart_type ) $insert_row['chart_type'] = $_ct;
                $wpdb->insert( $t_astro, bccm_astro_filter_row_to_existing_columns( $insert_row ) );
            }
        }

        echo '<div class="updated notice is-dismissible"><p>✅ Đã cập nhật thông tin cá nhân và dữ liệu sinh.</p></div>';

        // Refresh in-memory rows
        $coachee   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['profiles']} WHERE id=%d", $coachee_id ), ARRAY_A );
        $astro_row = bccm_astro_fetch_by_user_chart( $view_uid, $coachee_id, 'western' );
        $vedic_row = bccm_astro_fetch_by_user_chart( $view_uid, $coachee_id, 'vedic' );
        if ( ! $astro_row ) $astro_row = $vedic_row;

        // Optionally chain to chart regeneration
        if ( $regen ) {
            $action = 'gen_both_charts';
        } else {
            $action = ''; // prevent fall-through into chart-gen branch
        }
    }

    if ( $action && in_array( $action, [ 'gen_free_chart', 'gen_vedic_chart', 'gen_chinese_chart', 'gen_both_charts' ], true ) ) {
        $chart_gen_success = false;
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
                // Sprint H (2026-05-16): ONLY allow FAA V2 (api.freeastroapi.com).
                // Legacy json.freeastrologyapi.com path is BLOCKED here so we
                // never burn quota on the deprecated 30/day host. If V2 isn't
                // ready, surface a clear error instead of silently degrading.
                $chart_result = null;
                if ( function_exists( 'bccm_astro_fetch_full_chart_v2' ) && function_exists( 'bcpro_astro_v2_available' ) && bcpro_astro_v2_available( 'western' ) ) {
                    // Main site: FAA providers loaded directly.
                    $birth_data['coachee_id'] = $coachee_id;
                    $chart_result = bccm_astro_fetch_full_chart_v2( $birth_data );
                } elseif ( function_exists( 'bccm_astro_fetch_full_chart_via_gateway_v2' ) && class_exists( 'BizCoach_Pro_Astro_Client' ) ) {
                    // Subsite: FAA providers not loaded locally — proxy through gateway on main site.
                    $birth_data['coachee_id'] = $coachee_id;
                    $chart_result = bccm_astro_fetch_full_chart_via_gateway_v2( $birth_data );
                } else {
                    $chart_result = new WP_Error( 'faa_v2_unavailable', 'FAA V2 (api.freeastroapi.com) chưa sẵn sàng — kiểm tra API key trong Network Admin → Astrology Gateway.' );
                }
                if ( $chart_result === null ) {
                    echo '<div class="error"><p>❌ Không có provider Astrology nào khả dụng.</p></div>';
                } elseif ( is_wp_error( $chart_result ) ) {
                    echo '<div class="error"><p>❌ Western API: ' . esc_html( $chart_result->get_error_message() ) . '</p></div>';
                } else {
                    bccm_astro_save_chart( $coachee_id, $chart_result, $birth_input, $view_uid );
                    echo '<div class="updated"><p>✅ Đã tạo bản đồ Western Astrology'
                        . ( ! empty( $chart_result['_source'] ) ? ' <small style="color:#6b7280;">(via ' . esc_html( $chart_result['_source'] ) . ')</small>' : '' )
                        . '!</p></div>';
                    $chart_gen_success = true;
                }
            }
            if ( $action === 'gen_vedic_chart' || $action === 'gen_both_charts' ) {
                $vedic_result = null;
                if ( function_exists( 'bccm_vedic_fetch_full_chart_v2' ) && function_exists( 'bcpro_astro_v2_available' ) && bcpro_astro_v2_available( 'vedic' ) ) {
                    $vedic_result = bccm_vedic_fetch_full_chart_v2( $birth_data );
                } elseif ( function_exists( 'bccm_vedic_fetch_full_chart' ) ) {
                    $vedic_result = bccm_vedic_fetch_full_chart( $birth_data );
                }
                if ( $vedic_result === null ) {
                    echo '<div class="error"><p>❌ Không có provider Vedic nào khả dụng.</p></div>';
                } elseif ( is_wp_error( $vedic_result ) ) {
                    echo '<div class="error"><p>❌ Vedic API: ' . esc_html( $vedic_result->get_error_message() ) . '</p></div>';
                } else {
                    bccm_vedic_save_chart( $coachee_id, $vedic_result, $birth_input, $view_uid );
                    echo '<div class="updated"><p>✅ Đã tạo bản đồ Vedic Astrology'
                        . ( ! empty( $vedic_result['_source'] ) ? ' <small style="color:#6b7280;">(via ' . esc_html( $vedic_result['_source'] ) . ')</small>' : '' )
                        . '!</p></div>';
                    $chart_gen_success = true;
                }
            }
            if ( $action === 'gen_chinese_chart' ) {
                // PHASE-0.3 H — Chinese / BaZi (Tứ Trụ). Persists raw envelope
                // via direct $wpdb upsert with chart_type='chinese'. A dedicated
                // bccm_chinese_save_chart()/_fetch_full_chart_v2() pair will be
                // introduced in sprint H.4 (renderer + LLM context) — keep this
                // minimal so the admin button can already trigger the API call
                // and store the envelope for downstream RAG / luận giải work.
                $chinese_result = null;
                if ( class_exists( 'BizCoach_Pro_Astro_Client' ) ) {
                    try {
                        $payload        = $birth_data;
                        $payload['coachee_id'] = $coachee_id;
                        $chinese_result = BizCoach_Pro_Astro_Client::bazi_chinese( $payload );
                    } catch ( \Throwable $e ) {
                        $chinese_result = new WP_Error( 'bazi_exception', $e->getMessage() );
                    }
                } else {
                    $chinese_result = new WP_Error( 'no_astro_client', 'BizCoach_Pro_Astro_Client chưa load — kiểm tra bootstrap.' );
                }
                if ( is_wp_error( $chinese_result ) ) {
                    echo '<div class="error"><p>❌ Chinese BaZi API: ' . esc_html( $chinese_result->get_error_message() ) . '</p></div>';
                } elseif ( is_array( $chinese_result ) ) {
                    $t_astro = $wpdb->prefix . 'bccm_astro';
                    $summary_min = [
                        'system'     => 'Chinese BaZi (Tứ Trụ)',
                        'pillars'    => isset( $chinese_result['pillars'] ) ? $chinese_result['pillars'] : ( isset( $chinese_result['four_pillars'] ) ? $chinese_result['four_pillars'] : [] ),
                        'day_master' => isset( $chinese_result['day_master'] ) ? $chinese_result['day_master'] : '',
                        'fetched_at' => current_time( 'mysql' ),
                        '_source'    => isset( $chinese_result['_source'] ) ? $chinese_result['_source'] : 'bazi_chinese',
                    ];
                    $traits_full = [
                        'envelope'   => $chinese_result,
                        'birth_data' => $birth_input,
                    ];
                    $existing_id = $wpdb->get_var( $wpdb->prepare(
                        "SELECT id FROM $t_astro WHERE coachee_id=%d AND chart_type=%s",
                        $coachee_id, 'chinese'
                    ) );
                    $row_data = [
                        'user_id'     => $view_uid ?: null,
                        'coachee_id'  => $coachee_id,
                        'chart_type'  => 'chinese',
                        'birth_place' => sanitize_text_field( $birth_input['birth_place'] ?? '' ),
                        'birth_time'  => sanitize_text_field( $birth_input['birth_time'] ?? '' ),
                        'latitude'    => floatval( $birth_input['latitude'] ?? 0 ),
                        'longitude'   => floatval( $birth_input['longitude'] ?? 0 ),
                        'timezone'    => floatval( $birth_input['timezone'] ?? 7 ),
                        'summary'     => wp_json_encode( $summary_min, JSON_UNESCAPED_UNICODE ),
                        'traits'      => wp_json_encode( $traits_full, JSON_UNESCAPED_UNICODE ),
                        'updated_at'  => current_time( 'mysql' ),
                    ];
                    if ( $existing_id ) {
                        $wpdb->update( $t_astro, $row_data, [ 'id' => $existing_id ] );
                    } else {
                        $row_data['created_at'] = current_time( 'mysql' );
                        $wpdb->insert( $t_astro, $row_data );
                    }
                    echo '<div class="updated"><p>✅ Đã tạo Tứ Trụ (Bát Tự) Chinese Astrology'
                        . ( ! empty( $summary_min['_source'] ) ? ' <small style="color:#6b7280;">(via ' . esc_html( $summary_min['_source'] ) . ')</small>' : '' )
                        . '!</p></div>';
                    $chart_gen_success = true;
                } else {
                    echo '<div class="error"><p>❌ Chinese BaZi: response không hợp lệ.</p></div>';
                }
            }

            // Refresh data after chart generation
            $astro_row   = bccm_astro_fetch_by_user_chart( $view_uid, $coachee_id, 'western' );
            $vedic_row   = bccm_astro_fetch_by_user_chart( $view_uid, $coachee_id, 'vedic' );
            $chinese_row = bccm_astro_fetch_by_user_chart( $view_uid, $coachee_id, 'chinese' );
            $coachee     = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['profiles']} WHERE id=%d", $coachee_id ), ARRAY_A );
        } else {
            echo '<div class="error"><p>⚠️ Cần có Ngày sinh và Giờ sinh để tạo bản đồ.</p></div>';
        }

        // Auto-redirect to detail view (drop &edit=1, &auto_gen, action) so user sees the rendered chart
        if ( ! empty( $chart_gen_success ) ) {
            $redirect_url = remove_query_arg( [ 'edit', 'auto_gen', 'action' ] );
            // Re-add action=view to ensure detail page renders
            $redirect_url = add_query_arg( [ 'action' => 'view', 'chart_saved' => 1 ], $redirect_url );
            echo '<div class="updated"><p>⏳ Đang chuyển sang trang xem bản đồ sao…</p></div>';
            echo '<script>setTimeout(function(){ window.location.href = ' . wp_json_encode( esc_url_raw( $redirect_url ) ) . '; }, 1200);</script>';
            echo '<noscript><p><a href="' . esc_url( $redirect_url ) . '">Bấm vào đây để xem bản đồ sao</a></p></noscript>';
        } elseif ( $auto_gen ) {
            // API failed (429 / network) — strip auto_gen so a manual F5 doesn't
            // immediately re-fire and burn more quota. User must click the
            // explicit "Tạo …" button to retry.
            $clean = remove_query_arg( [ 'auto_gen', 'action' ] );
            echo '<div class="error"><p>⚠️ Gọi API thất bại — đã tạm dừng tự động retry. Bấm nút <strong>Tạo Western/Vedic</strong> để thử lại.</p></div>';
            echo '<script>setTimeout(function(){ window.location.replace(' . wp_json_encode( esc_url_raw( $clean ) ) . '); }, 2000);</script>';
        }
    }

    // Data for display
    $astro_summary   = ! empty( $astro_row['summary'] )   ? json_decode( $astro_row['summary'], true )   : [];
    $astro_traits    = ! empty( $astro_row['traits'] )    ? json_decode( $astro_row['traits'], true )    : [];
    $vedic_summary   = ! empty( $vedic_row['summary'] )   ? json_decode( $vedic_row['summary'], true )   : [];
    $vedic_traits    = ! empty( $vedic_row['traits'] )    ? json_decode( $vedic_row['traits'], true )    : [];
    $chinese_summary = ! empty( $chinese_row['summary'] ) ? json_decode( $chinese_row['summary'], true ) : [];
    $chinese_traits  = ! empty( $chinese_row['traits'] )  ? json_decode( $chinese_row['traits'], true )  : [];
    $has_western     = ! empty( $astro_summary )   || ! empty( $astro_traits );
    $has_vedic       = ! empty( $vedic_summary )   || ! empty( $vedic_traits );
    $has_chinese     = ! empty( $chinese_summary ) || ! empty( $chinese_traits );

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

    <?php if ( ! empty( $_GET['chart_saved'] ) ) : ?>
        <div class="notice notice-success is-dismissible"><p>✅ Đã tạo &amp; lưu bản đồ sao thành công. Cuộn xuống để xem.</p></div>
    <?php endif; ?>

    <!-- ══════════ TOP GRID: Info + Astro + Actions ══════════ -->
    <?php
    $edit_mode  = ! empty( $_GET['edit'] );
    $edit_url   = esc_url( add_query_arg( [ 'edit' => 1 ] ) );
    $cancel_url = esc_url( remove_query_arg( 'edit' ) );
    ?>
    <?php if ( $edit_mode ): ?>
    <form method="post" style="margin-bottom:20px;">
        <?php wp_nonce_field( 'bccm_user_detail_' . $view_uid ); ?>
        <input type="hidden" name="bccm_action" value="update_profile">
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;">
            <!-- Personal Info — EDIT -->
            <div style="background:#fffbeb;padding:16px;border-radius:12px;border:1px solid #fcd34d;">
                <h3 style="margin:0 0 10px;font-size:15px;">✏️ Sửa thông tin cá nhân</h3>
                <table style="width:100%;font-size:13px;">
                    <tr><td style="color:#6b7280;width:100px;padding:4px 0;">Họ tên</td><td><input type="text" name="full_name" value="<?php echo esc_attr( $coachee['full_name'] ?? '' ); ?>" class="regular-text" style="width:100%;"></td></tr>
                    <tr><td style="color:#6b7280;padding:4px 0;">SĐT</td><td><input type="text" name="phone" value="<?php echo esc_attr( $coachee['phone'] ?? '' ); ?>" style="width:100%;"></td></tr>
                    <tr><td style="color:#6b7280;padding:4px 0;">Ngày sinh</td><td><input type="date" name="dob" value="<?php echo esc_attr( $coachee['dob'] ?? '' ); ?>" style="width:100%;"></td></tr>
                    <tr><td style="color:#6b7280;padding:4px 0;">Địa chỉ</td><td><input type="text" name="address" value="<?php echo esc_attr( $coachee['address'] ?? '' ); ?>" style="width:100%;"></td></tr>
                </table>
            </div>

            <!-- Astro Data — EDIT -->
            <div style="background:#fffbeb;padding:16px;border-radius:12px;border:1px solid #fcd34d;">
                <h3 style="margin:0 0 10px;font-size:15px;">✏️ Sửa dữ liệu chiêm tinh</h3>
                <table style="width:100%;font-size:13px;">
                    <tr><td style="color:#6b7280;width:100px;padding:4px 0;">Giờ sinh</td><td><input type="time" name="birth_time" value="<?php echo esc_attr( $astro_row['birth_time'] ?? '' ); ?>" style="width:100%;" required></td></tr>
                    <tr><td style="color:#6b7280;padding:4px 0;">Nơi sinh</td><td>
                        <div style="display:flex;gap:6px;">
                            <input type="text" id="bccm_edit_birth_place" name="birth_place" value="<?php echo esc_attr( $astro_row['birth_place'] ?? '' ); ?>" style="flex:1;" placeholder="VD: Hà Nội, Việt Nam">
                            <button type="button" id="bccm_edit_geo_lookup" class="button" title="Tìm toạ độ từ tên nơi sinh (Nominatim/OpenStreetMap)">📍 Tìm toạ độ</button>
                            <button type="button" id="bccm_edit_geo_current" class="button" title="Dùng vị trí hiện tại (trình duyệt)">🛰️ Vị trí</button>
                        </div>
                        <div id="bccm_edit_geo_status" style="font-size:11px;color:#6b7280;margin-top:4px;"></div>
                    </td></tr>
                    <tr><td style="color:#6b7280;padding:4px 0;">Vĩ độ (lat)</td><td><input type="number" id="bccm_edit_lat" step="0.0000001" name="latitude" value="<?php echo esc_attr( $astro_row['latitude'] ?? '' ); ?>" style="width:100%;" placeholder="21.0285"></td></tr>
                    <tr><td style="color:#6b7280;padding:4px 0;">Kinh độ (lng)</td><td><input type="number" id="bccm_edit_lng" step="0.0000001" name="longitude" value="<?php echo esc_attr( $astro_row['longitude'] ?? '' ); ?>" style="width:100%;" placeholder="105.8542"></td></tr>
                    <tr><td style="color:#6b7280;padding:4px 0;">Timezone</td><td><input type="number" id="bccm_edit_tz" step="0.5" name="timezone" value="<?php echo esc_attr( $astro_row['timezone'] ?? 7 ); ?>" style="width:100%;" placeholder="7"></td></tr>
                </table>
                <p style="margin:8px 0 0;font-size:11px;color:#92400e;">⚠️ Lat/Lng sai → bản đồ sao sai. Có thể tra Google Maps → chuột phải → copy tọa độ.</p>
            </div>

            <!-- Save actions -->
            <div style="background:#ecfdf5;padding:16px;border-radius:12px;border:1px solid #6ee7b7;display:flex;flex-direction:column;gap:8px;">
                <h3 style="margin:0 0 10px;font-size:15px;">💾 Lưu thay đổi</h3>
                <button type="submit" class="button button-primary" style="text-align:left;padding:8px 14px;">
                    💾 Lưu thông tin
                </button>
                <label style="display:flex;align-items:center;gap:6px;font-size:13px;background:#fff;padding:8px 10px;border-radius:8px;border:1px solid #d1fae5;cursor:pointer;">
                    <input type="checkbox" name="regen_after_save" value="1" checked>
                    <span>Tạo lại cả 2 bản đồ sao sau khi lưu</span>
                </label>
                <a href="<?php echo $cancel_url; ?>" class="button" style="text-align:center;">Huỷ</a>
                <p style="margin:4px 0 0;font-size:11px;color:#065f46;">Sau khi lưu, các bản luận giải AI cũ vẫn còn cache — bấm "🔄 Tạo lại" trong toolbar để buộc viết lại theo dữ liệu mới.</p>
            </div>
        </div>
    </form>
    <script>
    (function(){
        var btnGeo = document.getElementById('bccm_edit_geo_lookup');
        var btnCur = document.getElementById('bccm_edit_geo_current');
        var status = document.getElementById('bccm_edit_geo_status');
        var elPlace = document.getElementById('bccm_edit_birth_place');
        var elLat = document.getElementById('bccm_edit_lat');
        var elLng = document.getElementById('bccm_edit_lng');
        var elTz  = document.getElementById('bccm_edit_tz');
        if (btnGeo) {
            btnGeo.addEventListener('click', function(){
                var place = (elPlace.value || '').trim();
                if (!place) { status.textContent = '⚠️ Nhập "Nơi sinh" trước'; status.style.color='#b91c1c'; return; }
                status.textContent = '⏳ Đang tra Nominatim/OpenStreetMap…'; status.style.color='#6b7280';
                fetch('https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' + encodeURIComponent(place))
                    .then(function(r){ return r.json(); })
                    .then(function(data){
                        if (data && data[0]) {
                            elLat.value = parseFloat(data[0].lat).toFixed(7);
                            elLng.value = parseFloat(data[0].lon).toFixed(7);
                            status.textContent = '✅ ' + data[0].display_name;
                            status.style.color = '#047857';
                        } else {
                            status.textContent = '❌ Không tìm thấy nơi này';
                            status.style.color = '#b91c1c';
                        }
                    })
                    .catch(function(){ status.textContent = '❌ Lỗi kết nối'; status.style.color='#b91c1c'; });
            });
        }
        if (btnCur && navigator.geolocation) {
            btnCur.addEventListener('click', function(){
                status.textContent = '⏳ Đang lấy vị trí từ trình duyệt…'; status.style.color='#6b7280';
                navigator.geolocation.getCurrentPosition(function(pos){
                    elLat.value = pos.coords.latitude.toFixed(7);
                    elLng.value = pos.coords.longitude.toFixed(7);
                    // Best-effort timezone from browser
                    try {
                        var offsetMin = -(new Date()).getTimezoneOffset();
                        elTz.value = (offsetMin / 60).toFixed(1);
                    } catch(e) {}
                    status.textContent = '✅ Đã lấy vị trí hiện tại (lưu ý: nơi này có thể khác nơi sinh thực tế!)';
                    status.style.color = '#047857';
                }, function(err){
                    status.textContent = '❌ Không lấy được vị trí: ' + (err.message || err.code);
                    status.style.color = '#b91c1c';
                }, { enableHighAccuracy: false, timeout: 10000, maximumAge: 60000 });
            });
        } else if (btnCur) {
            btnCur.disabled = true;
            btnCur.title = 'Trình duyệt không hỗ trợ Geolocation';
        }
    })();
    </script>
    <?php else: ?>
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;margin-bottom:20px;">
        <!-- Personal Info -->
        <div style="background:#f8fafc;padding:16px;border-radius:12px;border:1px solid #e5e7eb;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin:0 0 10px;">
                <h3 style="margin:0;font-size:15px;">📋 Thông tin cá nhân</h3>
                <a href="<?php echo $edit_url; ?>" class="button button-small" title="Sửa thông tin">✏️ Sửa</a>
            </div>
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
            <div style="display:flex;justify-content:space-between;align-items:center;margin:0 0 10px;">
                <h3 style="margin:0;font-size:15px;">🌟 Dữ liệu chiêm tinh</h3>
                <a href="<?php echo $edit_url; ?>" class="button button-small" title="Sửa dữ liệu sinh">✏️ Sửa</a>
            </div>
            <?php if ( $astro_row ): ?>
                <table style="width:100%;font-size:13px;">
                    <tr><td style="color:#6b7280;width:100px;">Giờ sinh</td><td><strong><?php echo esc_html( $astro_row['birth_time'] ?: '—' ); ?></strong></td></tr>
                    <tr><td style="color:#6b7280;">Nơi sinh</td><td><?php echo esc_html( $astro_row['birth_place'] ?: '—' ); ?></td></tr>
                    <tr><td style="color:#6b7280;">Tọa độ</td><td><?php echo esc_html( ( $astro_row['latitude'] ?? '?' ) . ', ' . ( $astro_row['longitude'] ?? '?' ) ); ?></td></tr>
                    <tr><td style="color:#6b7280;">Timezone</td><td>UTC<?php echo ( $astro_row['timezone'] >= 0 ? '+' : '' ) . $astro_row['timezone']; ?></td></tr>
                    <tr><td style="color:#6b7280;">Western</td><td><?php echo $has_western ? '<span style="color:#22c55e;font-weight:600;">✅ Có dữ liệu</span>' : '<span style="color:#d97706;">⚠️ Chưa tạo</span>'; ?></td></tr>
                    <tr><td style="color:#6b7280;">Vedic</td><td><?php echo $has_vedic ? '<span style="color:#7c3aed;font-weight:600;">✅ Có dữ liệu</span>' : '<span style="color:#d97706;">⚠️ Chưa tạo</span>'; ?></td></tr>
                    <tr><td style="color:#6b7280;">Chinese (BaZi)</td><td><?php echo $has_chinese ? '<span style="color:#dc2626;font-weight:600;">✅ Có dữ liệu</span>' : '<span style="color:#d97706;">⚠️ Chưa tạo</span>'; ?></td></tr>
                </table>
            <?php else: ?>
                <p style="color:#d97706;margin:0;font-size:13px;">⚠️ Chưa có dữ liệu chiêm tinh.</p>
                <p style="color:#9ca3af;font-size:12px;margin:4px 0 0;">Bấm "✏️ Sửa" để khai báo giờ sinh / nơi sinh / toạ độ.</p>
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
                <button type="submit" name="bccm_action" value="gen_chinese_chart" class="button" style="background:#dc2626;color:#fff;border-color:#b91c1c;text-align:left;padding:8px 14px;" <?php echo ( ! $astro_row || ! $astro_row['birth_time'] ) ? 'disabled title="Cần giờ sinh"' : ''; ?>>
                    🐲 Tạo Chinese Astrology (BaZi / Tứ Trụ)
                </button>
                <button type="submit" name="bccm_action" value="gen_both_charts" class="button" style="background:#059669;color:#fff;border-color:#047857;text-align:left;padding:8px 14px;" <?php echo ( ! $astro_row || ! $astro_row['birth_time'] ) ? 'disabled title="Cần giờ sinh"' : ''; ?>>
                    ⚡ Tạo cả 2 (Western + Vedic)
                </button>
                <?php if ( ! $astro_row || ! $astro_row['birth_time'] ): ?>
                    <p style="color:#dc2626;font-size:11px;margin:0;">⚠️ User chưa khai báo giờ sinh. Bấm "✏️ Sửa" ở thẻ bên cạnh để bổ sung.</p>
                <?php endif; ?>
            </form>
        </div>
    </div>
    <?php endif; ?>

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

        <?php
        // Sprint H.6 — prefer hash public URLs (shareable) over admin-ajax (admin-only).
        $bcpro_router_ok = class_exists( 'BizCoach_Pro_Astro_Public_Router' );
        $western_url = $bcpro_router_ok
            ? BizCoach_Pro_Astro_Public_Router::get_public_url( $coachee_id, 'western' )
            : admin_url( 'admin-ajax.php?action=bccm_natal_report_full&coachee_id=' . $coachee_id . '&chart_type=western&_wpnonce=' . wp_create_nonce( 'bccm_natal_report_full' ) );
        $western_regen = $bcpro_router_ok
            ? BizCoach_Pro_Astro_Public_Router::get_public_url( $coachee_id, 'western', true )
            : admin_url( 'admin-ajax.php?action=bccm_natal_report_full&coachee_id=' . $coachee_id . '&chart_type=western&regenerate=1&_wpnonce=' . wp_create_nonce( 'bccm_natal_report_full' ) );
        $vedic_url = $bcpro_router_ok
            ? BizCoach_Pro_Astro_Public_Router::get_public_url( $coachee_id, 'vedic' )
            : admin_url( 'admin-ajax.php?action=bccm_natal_report_full&coachee_id=' . $coachee_id . '&chart_type=vedic&_wpnonce=' . wp_create_nonce( 'bccm_natal_report_full' ) );
        $chinese_url = $bcpro_router_ok
            ? BizCoach_Pro_Astro_Public_Router::get_public_url( $coachee_id, 'chinese' )
            : admin_url( 'admin-ajax.php?action=bccm_natal_report_full&coachee_id=' . $coachee_id . '&chart_type=chinese&_wpnonce=' . wp_create_nonce( 'bccm_natal_report_full' ) );
        ?>
        <?php if ( $has_western ): ?>
            <a href="<?php echo esc_url( $western_url ); ?>" target="_blank" class="button" style="background:#3b82f6;color:#fff;border-color:#2563eb;">🤖 Luận Giải AI — Western</a>
            <a href="<?php echo esc_url( $western_regen ); ?>" target="_blank" class="button" style="background:#f59e0b;color:#fff;border-color:#d97706;" title="Tạo lại báo cáo Western (xóa cache)">🔄 Tạo lại</a>
        <?php endif; ?>

        <?php if ( $has_vedic ): ?>
            <a href="<?php echo esc_url( $vedic_url ); ?>" target="_blank" class="button" style="background:#7c3aed;color:#fff;border-color:#6d28d9;">🕉️ Luận Giải AI — Vedic</a>
        <?php endif; ?>

        <?php if ( ! empty( $chinese_row ) ): ?>
            <a href="<?php echo esc_url( $chinese_url ); ?>" target="_blank" class="button" style="background:#dc2626;color:#fff;border-color:#b91c1c;">☯️ Luận Giải AI — Chinese (Tứ Trụ)</a>
        <?php endif; ?>

        <?php if ( $has_western ):
            $transit_nonce = wp_create_nonce( 'bccm_transit_report' );
            // New share-link for public transit page.
            $transit_share_url = function_exists( 'bcpro_get_transit_public_url' )
                ? bcpro_get_transit_public_url( $coachee_id, 'month' )
                : '';
        ?>
            <span style="border-left:2px solid #e5e7eb;height:24px;"></span>
            <a href="<?php echo esc_url( admin_url( 'admin-ajax.php?action=bccm_transit_report&coachee_id=' . $coachee_id . '&period=day&_wpnonce=' . $transit_nonce ) ); ?>" target="_blank" class="button" style="background:#06b6d4;color:#fff;border-color:#0891b2;">🌅 Transit Hôm nay</a>
            <a href="<?php echo esc_url( admin_url( 'admin-ajax.php?action=bccm_transit_report&coachee_id=' . $coachee_id . '&period=week&_wpnonce=' . $transit_nonce ) ); ?>" target="_blank" class="button" style="background:#0ea5e9;color:#fff;border-color:#0284c7;">🔮 Transit Tuần tới</a>
            <a href="<?php echo esc_url( admin_url( 'admin-ajax.php?action=bccm_transit_report&coachee_id=' . $coachee_id . '&period=month&_wpnonce=' . $transit_nonce ) ); ?>" target="_blank" class="button" style="background:#8b5cf6;color:#fff;border-color:#7c3aed;">📅 Transit Tháng (Gantt)</a>
            <a href="<?php echo esc_url( admin_url( 'admin-ajax.php?action=bccm_transit_report&coachee_id=' . $coachee_id . '&period=year&_wpnonce=' . $transit_nonce ) ); ?>" target="_blank" class="button" style="background:#059669;color:#fff;border-color:#047857;">📊 Transit 12 Tháng</a>
        <?php endif; ?>
    </div>

    <!-- ══════════ Transit Public Share Link ══════════ -->
    <?php if ( $has_western && ! empty( $transit_share_url ) ): ?>
    <div style="margin-bottom:20px;padding:12px;background:#fef3c7;border:1px solid #fbbf24;border-radius:8px;">
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
            <strong style="color:#92400e;font-size:13px;">🔮 Link Transit chia sẻ cho coachee:</strong>
            <input type="text" readonly value="<?php echo esc_attr( $transit_share_url ); ?>" style="flex:1;min-width:300px;padding:6px 10px;border:1px solid #fbbf24;border-radius:4px;font-size:12px;font-family:monospace;background:#fff" />
            <a href="<?php echo esc_url( $transit_share_url ); ?>" target="_blank" class="button" style="background:#f59e0b;color:#fff;border-color:#d97706;">🔗 Mở</a>
            <button type="button" class="button" onclick="navigator.clipboard.writeText('<?php echo esc_js( $transit_share_url ); ?>'); this.textContent='✅ Đã copy!'">📋 Copy</button>
        </div>
        <div style="margin-top:6px;font-size:11px;color:#92400e;">Hỗ trợ <code>?period=day|week|month|year</code> hoặc <code>&start=YYYY-MM-DD&end=YYYY-MM-DD</code> (custom).</div>
    </div>
    <?php endif; ?>

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

    <!-- ══════════ NATAL CHART SVG (V2 enrichment) ══════════ -->
    <?php
    $natal_chart_url = $astro_summary['chart_url']
        ?? $astro_traits['chart_url']
        ?? ( $astro_row['chart_svg'] ?? '' );
    $natal_source    = $astro_summary['_source'] ?? $astro_traits['_source'] ?? '—';
    $natal_log_file  = trailingslashit( wp_upload_dir()['baseurl'] ) . 'bcr-astro-debug.log';
    ?>
    <div class="postbox" style="margin-bottom:16px;"><div class="inside" style="text-align:center;">
        <h3 style="margin-top:0;">🪐 Bản đồ sao Natal (Western)</h3>
        <p style="font-size:11px;color:#6b7280;margin:0 0 8px;">Nguồn: <code><?php echo esc_html( $natal_source ); ?></code> — api.freeastroapi.com · <a href="<?php echo esc_url( $natal_log_file ); ?>" target="_blank">debug log</a></p>
        <?php if ( ! empty( $natal_chart_url ) ): ?>
        <img src="<?php echo esc_url( $natal_chart_url ); ?>"
             alt="Natal Wheel Chart"
             loading="lazy"
             style="max-width:100%;height:auto;border-radius:12px;box-shadow:0 4px 16px rgba(0,0,0,0.12);" />
        <p style="font-size:11px;color:#10b981;margin-top:6px;">✅ chart_url: <code><?php echo esc_html( $natal_chart_url ); ?></code></p>
        <?php else: ?>
        <div style="padding:24px;background:#fef3c7;border:1px dashed #f59e0b;border-radius:8px;color:#92400e;">
            <strong>⚠️ Chưa có bản đồ SVG.</strong><br>
            Ô dữ liệu <code>chart_url</code> rỗng. Có thể do: (1) chưa flush OpCache nên code enrichment chưa chạy, (2) endpoint <code>/api/v1/natal/chart/</code> bị 429/timeout. Mở <a href="<?php echo esc_url( $natal_log_file ); ?>" target="_blank">debug log</a> để kiểm tra <code>bridge.chart_svg</code>.
        </div>
        <?php endif; ?>
    </div></div>

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
        $planets_raw = $astro_traits['planets'] ?? [];
        $signs_data  = function_exists( 'bccm_zodiac_signs' ) ? bccm_zodiac_signs() : [];
        foreach ( $planet_order as $pname ):
            $pdata = $positions[ $pname ] ?? null;
            if ( ! $pdata ) continue;
            // Schema fallbacks: Western API (camelCase, `sign`) vs Vedic API (snake_case, `sign_en`).
            $sign      = $pdata['sign'] ?? $pdata['zodiac'] ?? $pdata['sign_en'] ?? '?';
            $deg       = $pdata['normDegree'] ?? $pdata['norm_degree'] ?? $pdata['degree'] ?? '?';
            $full_deg  = $pdata['fullDegree'] ?? $pdata['full_degree'] ?? '';
            $house     = $pdata['house'] ?? $pdata['house_number'] ?? ( $planets_raw[ $pname ]['house_number'] ?? '—' );
            $retro     = ! empty( $pdata['isRetro'] ) || ! empty( $pdata['is_retro'] );
            $sym       = $planet_symbols[ $pname ] ?? '';
            $vi_name   = $planet_vi[ $pname ] ?? $pname;

            // Resolve Vietnamese sign label + symbol from local map (DB sign_vi may be mojibake).
            $sign_sym   = $pdata['sign_symbol'] ?? '';
            $sign_label = $sign;
            foreach ( $signs_data as $sd ) {
                if ( strcasecmp( $sd['en'] ?? '', $sign ) === 0 ) {
                    if ( $sign_sym === '' ) { $sign_sym = $sd['symbol'] ?? ''; }
                    $sign_label = $sd['vi'] ?? $sign;
                    break;
                }
            }

            $bg = $row_idx % 2 ? '#f9fafb' : '#fff';
        ?>
            <tr style="background:<?php echo $bg; ?>;">
                <td><strong><?php echo $sym . ' ' . esc_html( $vi_name ); ?></strong> <span style="color:#9ca3af;font-size:11px;">(<?php echo esc_html( $pname ); ?>)</span></td>
                <td><?php echo $sign_sym . ' ' . esc_html( $sign_label ); ?> <small style="color:#9ca3af">(<?php echo esc_html( $sign ); ?>)</small></td>
                <td><?php echo is_numeric( $deg ) ? number_format( (float) $deg, 4 ) . '°' : esc_html( $deg ); ?></td>
                <td style="text-align:center;"><?php echo esc_html( $house ); ?></td>
                <td style="text-align:center;"><?php echo $retro ? '<span style="color:#dc2626;font-weight:700;">℞ Retro</span>' : '—'; ?></td>
            </tr>
        <?php $row_idx++; endforeach; ?>
        </tbody></table>
        <p style="font-size:11px;color:#3b82f6;margin-top:4px;">Nguồn: <?php echo esc_html( $astro_summary['_source'] ?? $astro_traits['_source'] ?? 'faa_western_v2' ); ?> — api.freeastroapi.com</p>
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
            $h_sign    = $h['sign'] ?? $h['sign_en'] ?? $h['zodiac'] ?? '?';
            $h_deg     = $h['degree'] ?? $h['normDegree'] ?? $h['norm_degree'] ?? $h['full_degree'] ?? '?';
            $meaning   = $house_meanings[ (int) $house_num ] ?? '';
            $h_sym     = $h['sign_symbol'] ?? '';
            $h_label   = $h_sign;
            foreach ( $signs_all as $sd ) {
                if ( strcasecmp( $sd['en'] ?? '', $h_sign ) === 0 ) {
                    if ( $h_sym === '' ) { $h_sym = $sd['symbol'] ?? ''; }
                    $h_label = $sd['vi'] ?? $h_sign;
                    break;
                }
            }
        ?>
            <tr>
                <td style="font-weight:600;">Nhà <?php echo esc_html( $house_num ); ?></td>
                <td><?php echo $h_sym . ' ' . esc_html( $h_label ); ?></td>
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
                    $p2       = $asp['planet_2_en'] ?? $asp['aspecting_planet'] ?? $asp['aspected_planet_en'] ?? $asp['planet2'] ?? '?';
                    $p2_vi    = $planet_vi2[ $p2 ] ?? $p2;
                    $asp_type = $asp['aspect_en'] ?? $asp['aspect_type'] ?? $asp['type'] ?? '?';
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

    <!-- ══════════ VEDIC (Jyotish) — Bản đồ + Luận giải Vedic riêng ══════════ -->
    <?php
    $vedic_chart_url = (string) ( $vedic_summary['chart_url'] ?? $vedic_traits['chart_url'] ?? '' );
    $vedic_planets   = (array)  ( $vedic_traits['planets']    ?? [] );
    $vedic_houses    = (array)  ( $vedic_traits['houses']     ?? [] );
    $vedic_lagna     = (array)  ( $vedic_traits['lagna']      ?? [] );
    $vedic_dasha     = (array)  ( $vedic_traits['dasha']      ?? [] );
    $vedic_yogas     = (array)  ( $vedic_traits['yogas']      ?? [] );
    $vedic_panchang  = (array)  ( $vedic_traits['panchang']   ?? [] );
    $vedic_vargas    = (array)  ( $vedic_traits['vargas']     ?? [] );
    if ( $vedic_chart_url || ! empty( $vedic_planets ) || ! empty( $vedic_lagna ) ):
    ?>
    <div style="margin-top:32px;border:2px solid #7c3aed;border-radius:12px;background:linear-gradient(135deg,#faf5ff 0%,#f5f3ff 100%);padding:20px;box-shadow:0 4px 16px rgba(124,58,237,0.08);">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;border-bottom:2px solid #ddd6fe;padding-bottom:12px;">
            <span style="font-size:32px;">🕉️</span>
            <div>
                <h2 style="margin:0;color:#6d28d9;font-size:22px;">Bản đồ Vedic (Jyotish)</h2>
                <p style="margin:2px 0 0;color:#7c3aed;font-size:13px;">
                    Hệ thống: <?php echo esc_html( $vedic_summary['system'] ?? 'Vedic (Lahiri Ayanamsha)' ); ?>
                    · Nguồn: <code style="background:#ede9fe;padding:1px 6px;border-radius:4px;"><?php echo esc_html( $vedic_summary['_source'] ?? 'faa_vedic_v2' ); ?></code>
                    <?php if ( ! empty( $vedic_summary['fetched_at'] ) ): ?>
                    · Cập nhật: <?php echo esc_html( $vedic_summary['fetched_at'] ); ?>
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <div style="display:grid;grid-template-columns: 1fr 1fr;gap:20px;">
            <!-- LEFT: Chart SVG -->
            <div style="background:#fff;border:1px solid #ddd6fe;border-radius:8px;padding:12px;text-align:center;">
                <?php if ( $vedic_chart_url ): ?>
                    <img src="<?php echo esc_url( $vedic_chart_url ); ?>" alt="Vedic Chart" style="max-width:100%;height:auto;" />
                    <p style="margin:8px 0 0;font-size:12px;color:#6b7280;">D1 Rasi Chart · Sidereal (Lahiri) · Whole-sign</p>
                <?php else: ?>
                    <p style="color:#9ca3af;padding:60px 0;">Chưa có bản đồ. Bấm nút 🕉️ ở danh sách để tạo.</p>
                <?php endif; ?>
            </div>

            <!-- RIGHT: BIG-3 Vedic + Lagna nakshatra -->
            <div>
                <div style="display:grid;grid-template-columns: repeat(3,1fr);gap:8px;margin-bottom:12px;">
                    <div style="background:#fff;border:1px solid #ddd6fe;border-radius:6px;padding:10px;text-align:center;">
                        <div style="font-size:11px;color:#7c3aed;font-weight:600;">SURYA (Mặt trời)</div>
                        <div style="font-size:18px;font-weight:700;color:#1f2937;margin-top:4px;"><?php echo esc_html( $vedic_summary['sun_sign'] ?? '?' ); ?></div>
                    </div>
                    <div style="background:#fff;border:1px solid #ddd6fe;border-radius:6px;padding:10px;text-align:center;">
                        <div style="font-size:11px;color:#7c3aed;font-weight:600;">CHANDRA (Mặt trăng)</div>
                        <div style="font-size:18px;font-weight:700;color:#1f2937;margin-top:4px;"><?php echo esc_html( $vedic_summary['moon_sign'] ?? '?' ); ?></div>
                    </div>
                    <div style="background:#fff;border:1px solid #ddd6fe;border-radius:6px;padding:10px;text-align:center;">
                        <div style="font-size:11px;color:#7c3aed;font-weight:600;">LAGNA (Cung mọc)</div>
                        <div style="font-size:18px;font-weight:700;color:#1f2937;margin-top:4px;"><?php echo esc_html( $vedic_summary['ascendant_sign'] ?? '?' ); ?></div>
                    </div>
                </div>

                <?php if ( ! empty( $vedic_lagna ) ): ?>
                <div style="background:#fff;border:1px solid #ddd6fe;border-radius:6px;padding:12px;">
                    <h4 style="margin:0 0 8px;color:#6d28d9;font-size:14px;">📍 Lagna Details</h4>
                    <table style="width:100%;font-size:13px;">
                        <tr><td style="padding:3px 0;color:#6b7280;">Sign:</td><td><strong><?php echo esc_html( $vedic_lagna['sign_en'] ?? $vedic_lagna['sign'] ?? '' ); ?></strong></td></tr>
                        <tr><td style="padding:3px 0;color:#6b7280;">Degree:</td><td><?php echo isset( $vedic_lagna['degree_in_sign'] ) ? number_format( (float) $vedic_lagna['degree_in_sign'], 2 ) . '°' : ( isset( $vedic_lagna['absolute_degree'] ) ? number_format( (float) $vedic_lagna['absolute_degree'], 2 ) . '°' : '—' ); ?></td></tr>
                        <tr><td style="padding:3px 0;color:#6b7280;">Nakshatra:</td><td><strong><?php echo esc_html( is_array( $vedic_lagna['nakshatra'] ?? null ) ? ( $vedic_lagna['nakshatra']['name'] ?? '' ) : ( $vedic_lagna['nakshatra'] ?? '' ) ); ?></strong>
                            <?php $n = $vedic_lagna['nakshatra'] ?? []; if ( is_array( $n ) && isset( $n['pada'] ) ): ?> · Pada <?php echo (int) $n['pada']; endif; ?>
                        </td></tr>
                        <tr><td style="padding:3px 0;color:#6b7280;">Lord:</td><td><?php echo esc_html( $vedic_lagna['nakshatra_lord'] ?? ( is_array( $vedic_lagna['nakshatra'] ?? null ) ? ( $vedic_lagna['nakshatra']['lord'] ?? '' ) : '' ) ); ?></td></tr>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- VEDIC PLANETS (sidereal) -->
        <?php if ( ! empty( $vedic_planets ) ): ?>
        <h3 style="margin:24px 0 8px;color:#6d28d9;font-size:16px;">🪐 Vị trí các hành tinh (Sidereal)</h3>
        <div style="overflow-x:auto;background:#fff;border:1px solid #ddd6fe;border-radius:8px;">
        <table class="widefat striped" style="margin:0;">
            <thead><tr style="background:#ede9fe;">
                <th>Graha</th><th>Rasi (Sign)</th><th>Độ</th><th>Bhava (Nhà)</th><th>Nakshatra</th><th>Pada</th><th>Lord</th><th>Retrograde</th>
            </tr></thead>
            <tbody>
            <?php foreach ( $vedic_planets as $pname => $pdata ): if ( ! is_array( $pdata ) ) continue; ?>
                <?php
                    $nak = $pdata['nakshatra'] ?? '';
                    $nak_name = is_array( $nak ) ? ( $nak['name'] ?? '' ) : (string) $nak;
                    $pada     = is_array( $nak ) ? ( $nak['pada'] ?? '' ) : ( $pdata['pada'] ?? '' );
                    $nak_lord = is_array( $nak ) ? ( $nak['lord'] ?? '' ) : ( $pdata['nakshatra_lord'] ?? '' );
                    $deg      = $pdata['sign_degree'] ?? $pdata['degree_in_sign'] ?? $pdata['norm_degree'] ?? 0;
                    $is_retro = ! empty( $pdata['is_retro'] ) || ! empty( $pdata['retrograde'] ) || ( ( $pdata['isRetro'] ?? 'false' ) === 'true' );
                ?>
                <tr>
                    <td><strong><?php echo esc_html( $pname ); ?></strong></td>
                    <td><?php echo esc_html( $pdata['sign_en'] ?? $pdata['sign'] ?? '' ); ?>
                        <?php if ( ! empty( $pdata['sign_vi'] ) ): ?><span style="color:#9ca3af;font-size:11px;">(<?php echo esc_html( $pdata['sign_vi'] ); ?>)</span><?php endif; ?>
                    </td>
                    <td><?php echo number_format( (float) $deg, 2 ); ?>°</td>
                    <td><?php echo esc_html( (string) ( $pdata['house'] ?? $pdata['house_number'] ?? '' ) ); ?></td>
                    <td><?php echo esc_html( $nak_name ); ?></td>
                    <td><?php echo esc_html( (string) $pada ); ?></td>
                    <td><?php echo esc_html( (string) $nak_lord ); ?></td>
                    <td><?php echo $is_retro ? '<span style="color:#dc2626;">℞</span>' : '—'; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>

        <!-- VEDIC HOUSES (Whole-sign) -->
        <?php if ( ! empty( $vedic_houses ) ): ?>
        <h3 style="margin:24px 0 8px;color:#6d28d9;font-size:16px;">🏛️ 12 Bhava (Whole-sign)</h3>
        <div style="display:grid;grid-template-columns: repeat(4, 1fr);gap:6px;">
            <?php foreach ( $vedic_houses as $h ): if ( ! is_array( $h ) ) continue; ?>
            <div style="background:#fff;border:1px solid #ddd6fe;border-radius:6px;padding:8px;font-size:12px;">
                <div style="font-weight:700;color:#6d28d9;">Bhava <?php echo (int) ( $h['house'] ?? $h['House'] ?? 0 ); ?></div>
                <div><?php echo esc_html( $h['sign_en'] ?? $h['sign'] ?? $h['Sign'] ?? '' ); ?></div>
                <?php if ( isset( $h['degree_cusp'] ) ): ?><div style="color:#6b7280;font-size:11px;"><?php echo number_format( (float) $h['degree_cusp'], 2 ); ?>°</div><?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- VIMSHOTTARI DASHA -->
        <?php
        $dasha_timeline = (array) ( $vedic_dasha['timeline'] ?? [] );
        $dasha_active   = (array) ( $vedic_dasha['active_periods'] ?? [] );
        if ( ! empty( $dasha_timeline ) || ! empty( $dasha_active ) ):
        ?>
        <h3 style="margin:24px 0 8px;color:#6d28d9;font-size:16px;">⏳ Vimshottari Dasha (đang vận hành)</h3>
        <div style="background:#fff;border:1px solid #ddd6fe;border-radius:8px;padding:12px;">
            <?php if ( ! empty( $dasha_active ) ): ?>
            <table class="widefat striped" style="margin:0;font-size:13px;">
                <thead><tr style="background:#ede9fe;"><th>Cấp</th><th>Lord</th><th>Bắt đầu</th><th>Kết thúc</th><th>Thời lượng</th></tr></thead>
                <tbody>
                <?php foreach ( $dasha_active as $period ): if ( ! is_array( $period ) ) continue; ?>
                    <tr>
                        <td><strong>L<?php echo (int) ( $period['level'] ?? 0 ); ?></strong>
                            <?php $lvl = (int) ( $period['level'] ?? 0 ); $lvl_name = [ 1 => 'Maha', 2 => 'Antar', 3 => 'Pratyantar' ][ $lvl ] ?? ''; ?>
                            <span style="color:#7c3aed;font-size:11px;"><?php echo esc_html( $lvl_name ); ?></span>
                        </td>
                        <td><strong><?php echo esc_html( $period['lord'] ?? '' ); ?></strong></td>
                        <td><?php echo esc_html( substr( (string) ( $period['start'] ?? '' ), 0, 10 ) ); ?></td>
                        <td><?php echo esc_html( substr( (string) ( $period['end'] ?? '' ), 0, 10 ) ); ?></td>
                        <td><?php echo isset( $period['duration_years'] ) ? number_format( (float) $period['duration_years'], 2 ) . ' năm' : '—'; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <p style="color:#6b7280;margin:0;">Có <?php echo count( $dasha_timeline ); ?> Mahadasha periods trong dữ liệu.</p>
            <?php endif; ?>
            <?php if ( ! empty( $vedic_dasha['moon_nakshatra'] ) ): ?>
                <p style="margin:8px 0 0;color:#6b7280;font-size:12px;">Birth Moon Nakshatra: <strong><?php echo esc_html( $vedic_dasha['moon_nakshatra'] ); ?></strong>
                <?php if ( isset( $vedic_dasha['birth_balance'] ) ): ?> · Balance: <?php echo esc_html( (string) $vedic_dasha['birth_balance'] ); ?><?php endif; ?>
                </p>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- YOGAS -->
        <?php
        $yogas_list = (array) ( $vedic_yogas['yogas'] ?? $vedic_yogas );
        $active_yogas = array_values( array_filter( $yogas_list, function( $y ) { return is_array( $y ) && ! empty( $y['active'] ); } ) );
        if ( ! empty( $active_yogas ) ):
        ?>
        <h3 style="margin:24px 0 8px;color:#6d28d9;font-size:16px;">✨ Yogas đang kích hoạt (<?php echo count( $active_yogas ); ?>)</h3>
        <div style="display:grid;grid-template-columns: 1fr 1fr;gap:10px;">
            <?php foreach ( $active_yogas as $y ): ?>
            <div style="background:#fff;border-left:3px solid #7c3aed;border-radius:4px;padding:10px;font-size:13px;">
                <div style="font-weight:700;color:#1f2937;"><?php echo esc_html( $y['name'] ?? $y['id'] ?? '' ); ?>
                    <?php if ( ! empty( $y['strength'] ) ): ?><span style="color:#7c3aed;font-size:11px;">· strength: <?php echo esc_html( (string) $y['strength'] ); ?></span><?php endif; ?>
                </div>
                <?php if ( ! empty( $y['category'] ) || ! empty( $y['type'] ) ): ?>
                <div style="color:#7c3aed;font-size:11px;margin-top:2px;">
                    <?php echo esc_html( $y['type'] ?? '' ); ?><?php if ( ! empty( $y['type'] ) && ! empty( $y['category'] ) ) echo ' · '; ?><?php echo esc_html( $y['category'] ?? '' ); ?>
                </div>
                <?php endif; ?>
                <?php if ( ! empty( $y['description'] ) ): ?>
                <div style="color:#6b7280;font-size:12px;margin-top:4px;"><?php echo esc_html( $y['description'] ); ?></div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- PANCHANG -->
        <?php if ( ! empty( $vedic_panchang ) ):
            $tithi    = $vedic_panchang['tithi']     ?? [];
            $nak_p    = $vedic_panchang['nakshatra'] ?? [];
            $yoga_p   = $vedic_panchang['yoga']      ?? [];
            $kar_p    = $vedic_panchang['karanas']   ?? ( $vedic_panchang['karana'] ?? [] );
        ?>
        <h3 style="margin:24px 0 8px;color:#6d28d9;font-size:16px;">📅 Panchang (Lịch ngày sinh)</h3>
        <div style="background:#fff;border:1px solid #ddd6fe;border-radius:8px;padding:12px;display:grid;grid-template-columns: repeat(4, 1fr);gap:10px;font-size:13px;">
            <div><strong style="color:#6d28d9;">Tithi:</strong><br><?php echo esc_html( is_array( $tithi ) ? ( $tithi['name'] ?? '' ) : (string) $tithi ); ?></div>
            <div><strong style="color:#6d28d9;">Nakshatra:</strong><br><?php echo esc_html( is_array( $nak_p ) ? ( $nak_p['name'] ?? '' ) : (string) $nak_p ); ?></div>
            <div><strong style="color:#6d28d9;">Yoga:</strong><br><?php echo esc_html( is_array( $yoga_p ) ? ( $yoga_p['name'] ?? '' ) : (string) $yoga_p ); ?></div>
            <div><strong style="color:#6d28d9;">Karana:</strong><br>
                <?php
                if ( is_array( $kar_p ) && isset( $kar_p[0] ) && is_array( $kar_p[0] ) ) {
                    echo esc_html( $kar_p[0]['name'] ?? '' );
                } elseif ( is_array( $kar_p ) ) {
                    echo esc_html( $kar_p['name'] ?? '' );
                } else {
                    echo esc_html( (string) $kar_p );
                }
                ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- AI BUTTON -->
        <div style="margin-top:20px;text-align:center;">
            <a href="<?php echo esc_url( class_exists( 'BizCoach_Pro_Astro_Public_Router' )
                ? BizCoach_Pro_Astro_Public_Router::get_public_url( $coachee_id, 'vedic' )
                : admin_url( 'admin-ajax.php?action=bccm_natal_report_full&coachee_id=' . $coachee_id . '&chart_type=vedic&_wpnonce=' . wp_create_nonce( 'bccm_natal_report_full' ) ) ); ?>"
               target="_blank" class="button button-large"
               style="background:#7c3aed;color:#fff;border-color:#6d28d9;padding:8px 24px;font-size:14px;">
                🕉️ Tạo bản luận giải Vedic (AI)
            </a>
        </div>
    </div>
    <?php endif; ?>

    <!-- ══════════ TRANSITS (V2 enrichment: bi-wheel SVG + transit aspects) ══════════ -->
    <?php
    $transits_pack    = $astro_traits['transits']          ?? [];
    $transit_chart_url= $astro_summary['transit_chart_url']
                       ?? $astro_traits['transit_chart_url'] ?? '';
    if ( ! empty( $transit_chart_url ) || ! empty( $transits_pack ) ):
        $tr_date  = (string) ( $transits_pack['transit_date'] ?? '' );
        $tr_aspects = (array) ( $transits_pack['aspects'] ?? [] );
        $tr_planets = (array) ( $transits_pack['planets'] ?? [] );
        $planet_vi3 = function_exists( 'bccm_planet_names_vi' ) ? bccm_planet_names_vi() : [];
        $aspect_vi3 = function_exists( 'bccm_aspect_names_vi' ) ? bccm_aspect_names_vi() : [];
        $aspect_sym3= function_exists( 'bccm_aspect_symbols' )  ? bccm_aspect_symbols()  : [];
        $aspect_clr3= function_exists( 'bccm_aspect_colors' )   ? bccm_aspect_colors()   : [];
    ?>
    <div class="postbox" style="margin-bottom:16px;"><div class="inside">
        <h3 style="margin-top:0;">🌠 Transit hiện tại
            <?php if ( $tr_date ): ?>
                <small style="color:#6b7280;font-weight:normal;">— <?php echo esc_html( $tr_date ); ?></small>
            <?php endif; ?>
        </h3>

        <?php if ( ! empty( $transit_chart_url ) ): ?>
        <div style="text-align:center;margin-bottom:16px;">
            <img src="<?php echo esc_url( $transit_chart_url ); ?>"
                 alt="Bi-wheel Transit Chart"
                 loading="lazy"
                 style="max-width:100%;height:auto;border-radius:12px;box-shadow:0 4px 16px rgba(0,0,0,0.12);" />
            <div style="font-size:11px;color:#6b7280;margin-top:6px;">
                Vòng trong = Natal (xanh) · Vòng ngoài = Transit (đỏ)
            </div>
        </div>
        <?php endif; ?>

        <?php if ( ! empty( $tr_aspects ) ): ?>
        <table class="widefat" style="font-size:13px;"><thead><tr>
            <th style="width:28%;">Transit</th>
            <th style="width:10%;text-align:center;"></th>
            <th style="width:22%;">Góc chiếu</th>
            <th style="width:28%;">Natal</th>
            <th style="width:12%;">Orb</th>
        </tr></thead><tbody>
        <?php foreach ( $tr_aspects as $a ):
            $p_t   = (string) ( $a['transit_planet_en'] ?? $a['p1'] ?? $a['planet1'] ?? '?' );
            $p_n   = (string) ( $a['natal_planet_en']   ?? $a['p2'] ?? $a['planet2'] ?? '?' );
            $p_t_n = ucfirst( strtolower( preg_replace( '/\s*\([NTnt]\)\s*$/', '', $p_t ) ) );
            $p_n_n = ucfirst( strtolower( preg_replace( '/\s*\([NTnt]\)\s*$/', '', $p_n ) ) );
            $atype = ucfirst( strtolower( (string) ( $a['type_en'] ?? $a['type'] ?? $a['aspect'] ?? '?' ) ) );
            $orb_t = $a['orb'] ?? $a['orbit'] ?? '?';
            $sym3  = $aspect_sym3[ $atype ] ?? '';
            $clr3  = $aspect_clr3[ $atype ] ?? '#666';
            $avi3  = $aspect_vi3[ $atype ] ?? $atype;
        ?>
            <tr>
                <td><strong><?php echo esc_html( $planet_vi3[ $p_t_n ] ?? $p_t_n ); ?></strong></td>
                <td style="text-align:center;color:<?php echo $clr3; ?>;font-size:16px;"><?php echo $sym3; ?></td>
                <td style="color:<?php echo $clr3; ?>;font-weight:600;"><?php echo esc_html( $avi3 ); ?></td>
                <td><strong><?php echo esc_html( $planet_vi3[ $p_n_n ] ?? $p_n_n ); ?></strong></td>
                <td><?php echo is_numeric( $orb_t ) ? number_format( (float) $orb_t, 2 ) . '°' : esc_html( $orb_t ); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table>
        <?php elseif ( empty( $transit_chart_url ) ): ?>
        <p style="color:#9ca3af;font-style:italic;margin:0;">Chưa có dữ liệu transit. Bấm "Tạo bản đồ sao" để cập nhật.</p>
        <?php endif; ?>

        <?php if ( ! empty( $transits_pack['interpretation'] ) ): ?>
        <details style="margin-top:12px;background:#f9fafb;padding:10px;border-radius:8px;">
            <summary style="cursor:pointer;font-weight:600;">Phân tích chi tiết</summary>
            <pre style="white-space:pre-wrap;font-size:12px;margin:8px 0 0;"><?php echo esc_html( wp_json_encode( $transits_pack['interpretation'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ); ?></pre>
        </details>
        <?php endif; ?>
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
                        $astro_row = [
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
                        ];
                        if ( function_exists( 'bccm_astro_filter_row_to_existing_columns' ) ) {
                            $astro_row = bccm_astro_filter_row_to_existing_columns( $astro_row );
                        }
                        $wpdb->insert( $t_astro, $astro_row );
                    }

                    // ── Redirect to detail view (optionally auto-generate chart) ──
                    // Pass coachee_id along so the detail page never has to look
                    // up user_id→profile (avoids HyperDB read-replica lag).
                    $redirect = admin_url( 'admin.php?page=bccm_user_profiles&action=view&user_id=' . $user_id . '&coachee_id=' . (int) $coachee_id . '&created=1' );
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
