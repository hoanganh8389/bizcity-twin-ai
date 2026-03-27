<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/* ================================================================
 * Dashboard — My Stats (all users see their own)
 * ================================================================ */
function bzcalo_page_dashboard() {
    $user_id = get_current_user_id();
    $profile = bzcalo_get_or_create_profile( $user_id );
    $target  = (int) ( $profile['daily_calo_target'] ?? 2000 );

    global $wpdb;
    $t    = bzcalo_tables();
    $date = current_time( 'Y-m-d' );

    $today = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$t['daily_stats']} WHERE user_id = %d AND stat_date = %s",
        $user_id, $date
    ), ARRAY_A );

    $cal   = (float) ( $today['total_calories'] ?? 0 );
    $pct   = $target > 0 ? min( 100, round( $cal / $target * 100 ) ) : 0;
    $meals_today = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$t['meals']} WHERE user_id = %d AND meal_date = %s ORDER BY meal_time ASC",
        $user_id, $date
    ), ARRAY_A );

    // 7-day trend
    $week = $wpdb->get_results( $wpdb->prepare(
        "SELECT stat_date, total_calories FROM {$t['daily_stats']}
         WHERE user_id = %d AND stat_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
         ORDER BY stat_date ASC",
        $user_id
    ), ARRAY_A );

    wp_enqueue_style( 'bzcalo-admin' );
    wp_enqueue_script( 'bzcalo-admin' );
    ?>
    <div class="wrap bzcalo-wrap">
        <h1>🍽️ Nhật ký Calo — Hôm nay</h1>

        <!-- Stat Cards -->
        <div class="bzcalo-stats-grid">
            <div class="bzcalo-stat-card bzcalo-stat-primary">
                <div class="bzcalo-stat-ring" data-pct="<?php echo $pct; ?>">
                    <svg viewBox="0 0 36 36"><path d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" fill="none" stroke="#e5e7eb" stroke-width="3"/><path d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" fill="none" stroke="<?php echo $pct > 100 ? '#ef4444' : '#6366f1'; ?>" stroke-width="3" stroke-dasharray="<?php echo $pct; ?>, 100"/></svg>
                    <span class="bzcalo-ring-text"><?php echo $pct; ?>%</span>
                </div>
                <h3><?php echo round( $cal ); ?> / <?php echo $target; ?></h3>
                <p>kcal hôm nay</p>
            </div>
            <div class="bzcalo-stat-card">
                <h3>🥩 <?php echo round( $today['total_protein'] ?? 0 ); ?>g</h3>
                <p>Protein</p>
            </div>
            <div class="bzcalo-stat-card">
                <h3>🍞 <?php echo round( $today['total_carbs'] ?? 0 ); ?>g</h3>
                <p>Carbs</p>
            </div>
            <div class="bzcalo-stat-card">
                <h3>🧈 <?php echo round( $today['total_fat'] ?? 0 ); ?>g</h3>
                <p>Fat</p>
            </div>
        </div>

        <!-- Today Meals -->
        <div class="postbox" style="margin-top:20px">
            <h2 class="hndle" style="padding:12px 16px">📝 Bữa ăn hôm nay (<?php echo count( $meals_today ); ?> bữa)</h2>
            <div class="inside">
                <?php if ( empty( $meals_today ) ) : ?>
                    <p style="color:#6b7280;text-align:center;padding:20px">Chưa ghi bữa ăn nào. <a href="<?php echo admin_url('admin.php?page=' . BZCALO_SLUG . '-log'); ?>">Ghi bữa ăn →</a></p>
                <?php else : ?>
                    <table class="widefat striped">
                        <thead><tr><th>Giờ</th><th>Bữa</th><th>Mô tả</th><th>Calo</th><th>P/C/F</th><th></th></tr></thead>
                        <tbody>
                        <?php
                        $type_labels = array( 'breakfast' => '🌅 Sáng', 'lunch' => '☀️ Trưa', 'dinner' => '🌙 Tối', 'snack' => '🍪 Vặt' );
                        foreach ( $meals_today as $m ) :
                        ?>
                            <tr>
                                <td><?php echo esc_html( substr( $m['meal_time'], 0, 5 ) ); ?></td>
                                <td><?php echo $type_labels[ $m['meal_type'] ] ?? $m['meal_type']; ?></td>
                                <td>
                                    <?php if ( $m['photo_url'] ) : ?>
                                        <img src="<?php echo esc_url( $m['photo_url'] ); ?>" style="width:40px;height:40px;object-fit:cover;border-radius:6px;vertical-align:middle;margin-right:8px">
                                    <?php endif; ?>
                                    <?php echo esc_html( mb_substr( $m['description'], 0, 60 ) ); ?>
                                </td>
                                <td><strong><?php echo round( $m['total_calories'] ); ?></strong> kcal</td>
                                <td style="font-size:12px;color:#6b7280"><?php echo round( $m['total_protein'] ); ?>g / <?php echo round( $m['total_carbs'] ); ?>g / <?php echo round( $m['total_fat'] ); ?>g</td>
                                <td><button class="button button-small bzcalo-del-meal" data-id="<?php echo $m['id']; ?>">🗑️</button></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- 7-day trend -->
        <div class="postbox" style="margin-top:20px">
            <h2 class="hndle" style="padding:12px 16px">📈 Xu hướng 7 ngày</h2>
            <div class="inside">
                <div id="bzcalo-week-chart" style="display:flex;align-items:flex-end;gap:8px;height:180px;padding:10px 0">
                    <?php foreach ( $week as $d ) :
                        $h = $target > 0 ? min( 100, round( (float) $d['total_calories'] / $target * 100 ) ) : 0;
                        $color = $h > 100 ? '#ef4444' : ( $h > 80 ? '#f59e0b' : '#6366f1' );
                    ?>
                        <div style="flex:1;text-align:center">
                            <div style="background:<?php echo $color; ?>;height:<?php echo max( 4, $h * 1.5 ); ?>px;border-radius:4px 4px 0 0;margin-bottom:4px" title="<?php echo round( $d['total_calories'] ); ?> kcal"></div>
                            <div style="font-size:10px;color:#6b7280"><?php echo substr( $d['stat_date'], 5 ); ?></div>
                            <div style="font-size:11px;font-weight:600"><?php echo round( $d['total_calories'] ); ?></div>
                        </div>
                    <?php endforeach; ?>
                    <?php if ( empty( $week ) ) : ?>
                        <p style="color:#9ca3af;text-align:center;width:100%">Chưa có dữ liệu</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Profile quick view -->
        <div class="postbox" style="margin-top:20px">
            <h2 class="hndle" style="padding:12px 16px">👤 Hồ sơ dinh dưỡng</h2>
            <div class="inside">
                <table class="form-table">
                    <tr><th>Họ tên</th><td><?php echo esc_html( $profile['full_name'] ); ?></td></tr>
                    <tr><th>Chiều cao / Cân nặng</th><td><?php echo esc_html( $profile['height_cm'] ); ?> cm / <?php echo esc_html( $profile['weight_kg'] ); ?> kg</td></tr>
                    <tr><th>Mục tiêu</th><td><?php echo esc_html( $profile['goal'] ?? 'maintain' ); ?> — <?php echo $target; ?> kcal/ngày</td></tr>
                    <tr><th>Mức vận động</th><td><?php echo esc_html( $profile['activity_level'] ?? 'moderate' ); ?></td></tr>
                </table>
                <p><a href="<?php echo admin_url('admin.php?page=' . BZCALO_SLUG . '-settings'); ?>" class="button">✏️ Chỉnh sửa hồ sơ</a></p>
            </div>
        </div>
    </div>
    <?php
}

/* ================================================================
 * Log Meal Page (admin UI for manual logging)
 * ================================================================ */
function bzcalo_page_log_meal() {
    $user_id = get_current_user_id();
    $profile = bzcalo_get_or_create_profile( $user_id );

    wp_enqueue_style( 'bzcalo-admin' );
    wp_enqueue_script( 'bzcalo-admin' );
    ?>
    <div class="wrap bzcalo-wrap">
        <h1>🍽️ Ghi bữa ăn</h1>

        <div class="postbox" style="max-width:600px;margin:20px auto">
            <div class="inside" style="padding:20px">
                <form id="bzcalo-log-form">
                    <h3>📸 Chụp ảnh hoặc mô tả</h3>

                    <!-- Photo upload -->
                    <div id="bzcalo-photo-zone" style="border:2px dashed #d1d5db;border-radius:12px;padding:30px;text-align:center;margin-bottom:16px;cursor:pointer;background:#fafafa">
                        <input type="file" id="bzcalo-photo-input" accept="image/*" capture="environment" style="display:none">
                        <div id="bzcalo-photo-preview" style="display:none;margin-bottom:10px">
                            <img id="bzcalo-photo-img" style="max-width:100%;max-height:200px;border-radius:8px">
                        </div>
                        <p style="margin:0;font-size:14px;color:#6b7280">📷 Nhấn để chụp ảnh hoặc chọn ảnh bữa ăn</p>
                        <p style="margin:4px 0 0;font-size:12px;color:#9ca3af">AI sẽ tự nhận diện món ăn & tính calo</p>
                    </div>

                    <input type="hidden" id="bzcalo-photo-url" value="">

                    <!-- Description -->
                    <div style="margin-bottom:12px">
                        <label style="font-weight:600;display:block;margin-bottom:4px">Mô tả bữa ăn:</label>
                        <textarea id="bzcalo-desc" rows="2" style="width:100%;border-radius:8px" placeholder="VD: 1 tô phở bò, 1 ly trà đá..."></textarea>
                    </div>

                    <!-- Meal type -->
                    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:12px">
                        <label class="bzcalo-meal-type-btn"><input type="radio" name="meal_type" value="breakfast"> 🌅 Sáng</label>
                        <label class="bzcalo-meal-type-btn"><input type="radio" name="meal_type" value="lunch" checked> ☀️ Trưa</label>
                        <label class="bzcalo-meal-type-btn"><input type="radio" name="meal_type" value="dinner"> 🌙 Tối</label>
                        <label class="bzcalo-meal-type-btn"><input type="radio" name="meal_type" value="snack"> 🍪 Vặt</label>
                    </div>

                    <!-- AI Result preview -->
                    <div id="bzcalo-ai-result" style="display:none;background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:16px;margin-bottom:12px">
                        <h4 style="margin:0 0 8px">🤖 AI Phân tích:</h4>
                        <div id="bzcalo-ai-items"></div>
                        <div id="bzcalo-ai-total" style="font-weight:700;margin-top:8px"></div>
                        <div id="bzcalo-ai-note" style="font-size:12px;color:#6b7280;margin-top:4px"></div>
                    </div>

                    <div style="display:flex;gap:8px">
                        <button type="button" id="bzcalo-btn-analyze" class="button" style="flex:1">🤖 AI Phân tích</button>
                        <button type="button" id="bzcalo-btn-save" class="button button-primary" style="flex:1">💾 Lưu bữa ăn</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php
}

/* ================================================================
 * History Page
 * ================================================================ */
function bzcalo_page_history() {
    $user_id = get_current_user_id();
    global $wpdb;
    $t = bzcalo_tables();

    $page  = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
    $limit = 30;
    $offset = ( $page - 1 ) * $limit;

    $total = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$t['meals']} WHERE user_id = %d", $user_id
    ) );

    $meals = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$t['meals']} WHERE user_id = %d ORDER BY meal_date DESC, meal_time DESC LIMIT %d OFFSET %d",
        $user_id, $limit, $offset
    ), ARRAY_A );

    wp_enqueue_style( 'bzcalo-admin' );
    ?>
    <div class="wrap bzcalo-wrap">
        <h1>📜 Lịch sử bữa ăn (<?php echo $total; ?> bản ghi)</h1>
        <table class="widefat striped" style="margin-top:16px">
            <thead><tr><th>Ngày</th><th>Giờ</th><th>Bữa</th><th>Mô tả</th><th>Calo</th><th>Nguồn</th></tr></thead>
            <tbody>
            <?php
            $type_labels = array( 'breakfast' => '🌅 Sáng', 'lunch' => '☀️ Trưa', 'dinner' => '🌙 Tối', 'snack' => '🍪 Vặt' );
            foreach ( $meals as $m ) :
            ?>
                <tr>
                    <td><?php echo esc_html( $m['meal_date'] ); ?></td>
                    <td><?php echo esc_html( substr( $m['meal_time'] ?? '', 0, 5 ) ); ?></td>
                    <td><?php echo $type_labels[ $m['meal_type'] ] ?? $m['meal_type']; ?></td>
                    <td>
                        <?php if ( $m['photo_url'] ) : ?>
                            <img src="<?php echo esc_url( $m['photo_url'] ); ?>" style="width:32px;height:32px;object-fit:cover;border-radius:4px;vertical-align:middle;margin-right:6px">
                        <?php endif; ?>
                        <?php echo esc_html( mb_substr( $m['description'], 0, 50 ) ); ?>
                    </td>
                    <td><strong><?php echo round( $m['total_calories'] ); ?></strong></td>
                    <td><span style="font-size:11px;color:#6b7280"><?php echo esc_html( $m['source'] ); ?>/<?php echo esc_html( $m['platform'] ); ?></span></td>
                </tr>
            <?php endforeach; ?>
            <?php if ( empty( $meals ) ) : ?>
                <tr><td colspan="6" style="text-align:center;color:#9ca3af;padding:30px">Chưa có dữ liệu</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        <?php if ( $total > $limit ) : ?>
            <div class="tablenav bottom"><div class="tablenav-pages">
                <?php echo paginate_links( array(
                    'base'    => add_query_arg( 'paged', '%#%' ),
                    'format'  => '', 'current' => $page, 'total' => ceil( $total / $limit ),
                ) ); ?>
            </div></div>
        <?php endif; ?>
    </div>
    <?php
}

/* ================================================================
 * Admin Users Page — manage_options only
 * ================================================================ */
function bzcalo_page_admin_users() {
    if ( ! current_user_can( 'manage_options' ) ) { echo '<p>Không có quyền.</p>'; return; }

    global $wpdb;
    $t = bzcalo_tables();

    $users = $wpdb->get_results(
        "SELECT p.*,
                (SELECT COUNT(*) FROM {$t['meals']} m WHERE m.user_id = p.user_id) as total_meals,
                (SELECT MAX(meal_date) FROM {$t['meals']} m2 WHERE m2.user_id = p.user_id) as last_meal_date,
                (SELECT COALESCE(SUM(total_calories),0) FROM {$t['daily_stats']} d WHERE d.user_id = p.user_id AND d.stat_date = CURDATE()) as today_cal
         FROM {$t['profiles']} p ORDER BY p.updated_at DESC",
        ARRAY_A
    );

    wp_enqueue_style( 'bzcalo-admin' );
    ?>
    <div class="wrap bzcalo-wrap">
        <h1>👥 Quản lý Users (<?php echo count( $users ); ?> người dùng)</h1>
        <table class="widefat striped" style="margin-top:16px">
            <thead><tr><th>User</th><th>Chiều cao/Cân</th><th>Mục tiêu</th><th>Calo/ngày</th><th>Hôm nay</th><th>Tổng bữa</th><th>Bữa gần nhất</th></tr></thead>
            <tbody>
            <?php foreach ( $users as $u ) : $wp_user = get_userdata( $u['user_id'] ); ?>
                <tr>
                    <td>
                        <?php echo get_avatar( $u['user_id'], 32, '', '', array( 'style' => 'border-radius:50%;vertical-align:middle;margin-right:8px' ) ); ?>
                        <strong><?php echo esc_html( $u['full_name'] ?: ( $wp_user->display_name ?? "User #{$u['user_id']}" ) ); ?></strong>
                        <br><span style="font-size:11px;color:#6b7280"><?php echo esc_html( $wp_user->user_email ?? '' ); ?></span>
                    </td>
                    <td><?php echo esc_html( $u['height_cm'] ); ?>cm / <?php echo esc_html( $u['weight_kg'] ); ?>kg</td>
                    <td><?php echo esc_html( $u['goal'] ); ?></td>
                    <td><?php echo (int) $u['daily_calo_target']; ?></td>
                    <td><strong><?php echo round( $u['today_cal'] ); ?></strong> kcal</td>
                    <td><?php echo (int) $u['total_meals']; ?></td>
                    <td><?php echo esc_html( $u['last_meal_date'] ?: '—' ); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}

/* ================================================================
 * Foods Management
 * ================================================================ */
function bzcalo_page_foods() {
    if ( ! current_user_can( 'manage_options' ) ) { echo '<p>Không có quyền.</p>'; return; }

    global $wpdb;
    $t = bzcalo_tables();
    $foods = $wpdb->get_results( "SELECT * FROM {$t['foods']} ORDER BY category, name_vi", ARRAY_A );
    $categories = array( 'mon_chinh' => '🍲 Món chính', 'tinh_bot' => '🍚 Tinh bột', 'protein' => '🥩 Protein', 'rau_cu' => '🥬 Rau củ', 'trai_cay' => '🍎 Trái cây', 'do_uong' => '🥤 Đồ uống' );

    wp_enqueue_style( 'bzcalo-admin' );
    ?>
    <div class="wrap bzcalo-wrap">
        <h1>🥗 Cơ sở dữ liệu Thực phẩm (<?php echo count( $foods ); ?>)</h1>
        <table class="widefat striped" style="margin-top:16px">
            <thead><tr><th>Tên</th><th>English</th><th>Loại</th><th>Khẩu phần</th><th>Calo</th><th>P</th><th>C</th><th>F</th></tr></thead>
            <tbody>
            <?php foreach ( $foods as $f ) : ?>
                <tr>
                    <td><strong><?php echo esc_html( $f['name_vi'] ); ?></strong></td>
                    <td><?php echo esc_html( $f['name_en'] ); ?></td>
                    <td><?php echo $categories[ $f['category'] ] ?? $f['category']; ?></td>
                    <td><?php echo esc_html( $f['serving_size'] ); ?></td>
                    <td><strong><?php echo round( $f['calories'] ); ?></strong></td>
                    <td><?php echo $f['protein_g']; ?>g</td>
                    <td><?php echo $f['carbs_g']; ?>g</td>
                    <td><?php echo $f['fat_g']; ?>g</td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}

/* ================================================================
 * Settings — Profile editor
 * ================================================================ */
function bzcalo_page_settings() {
    $user_id = get_current_user_id();
    $profile = bzcalo_get_or_create_profile( $user_id );

    // Handle save
    if ( ! empty( $_POST['bzcalo_save_profile'] ) && check_admin_referer( 'bzcalo_settings' ) ) {
        global $wpdb;
        $t = bzcalo_tables();
        $data = array(
            'full_name'      => sanitize_text_field( $_POST['full_name'] ?? '' ),
            'gender'         => in_array( $_POST['gender'] ?? '', array('male','female','other') ) ? $_POST['gender'] : 'other',
            'dob'            => sanitize_text_field( $_POST['dob'] ?? '' ),
            'height_cm'      => (float) ( $_POST['height_cm'] ?? 0 ),
            'weight_kg'      => (float) ( $_POST['weight_kg'] ?? 0 ),
            'target_weight'  => (float) ( $_POST['target_weight'] ?? 0 ),
            'activity_level' => sanitize_text_field( $_POST['activity_level'] ?? 'moderate' ),
            'goal'           => sanitize_text_field( $_POST['goal'] ?? 'maintain' ),
            'allergies'      => sanitize_textarea_field( $_POST['allergies'] ?? '' ),
            'medical_notes'  => sanitize_textarea_field( $_POST['medical_notes'] ?? '' ),
            'updated_at'     => current_time( 'mysql' ),
        );
        $temp = array_merge( $profile, $data );
        $data['daily_calo_target'] = bzcalo_calc_bmr( $temp );
        if ( $data['goal'] === 'lose' ) $data['daily_calo_target'] -= 300;
        if ( $data['goal'] === 'gain' ) $data['daily_calo_target'] += 300;

        $wpdb->update( $t['profiles'], $data, array( 'user_id' => $user_id ) );
        $profile = array_merge( $profile, $data );
        echo '<div class="updated"><p>✅ Đã lưu hồ sơ! Mục tiêu: ' . $data['daily_calo_target'] . ' kcal/ngày</p></div>';
    }

    wp_enqueue_style( 'bzcalo-admin' );
    $p = $profile;
    ?>
    <div class="wrap bzcalo-wrap">
        <h1>⚙️ Hồ sơ Dinh dưỡng</h1>
        <form method="post" style="max-width:600px">
            <?php wp_nonce_field( 'bzcalo_settings' ); ?>
            <table class="form-table">
                <tr><th>Họ tên</th><td><input type="text" name="full_name" value="<?php echo esc_attr( $p['full_name'] ); ?>" class="regular-text"></td></tr>
                <tr><th>Giới tính</th><td>
                    <select name="gender">
                        <option value="male" <?php selected( $p['gender'], 'male' ); ?>>Nam</option>
                        <option value="female" <?php selected( $p['gender'], 'female' ); ?>>Nữ</option>
                        <option value="other" <?php selected( $p['gender'], 'other' ); ?>>Khác</option>
                    </select>
                </td></tr>
                <tr><th>Ngày sinh</th><td><input type="date" name="dob" value="<?php echo esc_attr( $p['dob'] ); ?>"></td></tr>
                <tr><th>Chiều cao (cm)</th><td><input type="number" name="height_cm" step="0.1" value="<?php echo esc_attr( $p['height_cm'] ); ?>"></td></tr>
                <tr><th>Cân nặng (kg)</th><td><input type="number" name="weight_kg" step="0.1" value="<?php echo esc_attr( $p['weight_kg'] ); ?>"></td></tr>
                <tr><th>Cân nặng mục tiêu (kg)</th><td><input type="number" name="target_weight" step="0.1" value="<?php echo esc_attr( $p['target_weight'] ); ?>"></td></tr>
                <tr><th>Mức vận động</th><td>
                    <select name="activity_level">
                        <option value="sedentary" <?php selected( $p['activity_level'], 'sedentary' ); ?>>Ít vận động (ngồi văn phòng)</option>
                        <option value="light" <?php selected( $p['activity_level'], 'light' ); ?>>Nhẹ (tập 1-3 ngày/tuần)</option>
                        <option value="moderate" <?php selected( $p['activity_level'], 'moderate' ); ?>>Trung bình (tập 3-5 ngày/tuần)</option>
                        <option value="active" <?php selected( $p['activity_level'], 'active' ); ?>>Năng động (tập 6-7 ngày/tuần)</option>
                        <option value="very_active" <?php selected( $p['activity_level'], 'very_active' ); ?>>Rất năng động (2 buổi/ngày)</option>
                    </select>
                </td></tr>
                <tr><th>Mục tiêu</th><td>
                    <select name="goal">
                        <option value="lose" <?php selected( $p['goal'], 'lose' ); ?>>🔽 Giảm cân</option>
                        <option value="maintain" <?php selected( $p['goal'], 'maintain' ); ?>>⚖️ Duy trì</option>
                        <option value="gain" <?php selected( $p['goal'], 'gain' ); ?>>🔼 Tăng cân</option>
                    </select>
                </td></tr>
                <tr><th>Dị ứng thực phẩm</th><td><textarea name="allergies" rows="2" class="large-text"><?php echo esc_textarea( $p['allergies'] ); ?></textarea></td></tr>
                <tr><th>Ghi chú y tế</th><td><textarea name="medical_notes" rows="2" class="large-text"><?php echo esc_textarea( $p['medical_notes'] ); ?></textarea></td></tr>
            </table>
            <p class="submit">
                <button type="submit" name="bzcalo_save_profile" value="1" class="button button-primary button-hero">💾 Lưu hồ sơ</button>
            </p>
            <p class="description">Mục tiêu calo hàng ngày: <strong><?php echo (int) $p['daily_calo_target']; ?> kcal</strong> (tự tính theo BMR + mức vận động + mục tiêu)</p>
        </form>
    </div>
    <?php
}
