<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/* ================================================================
 * Shortcode: [bizcity_calo]
 * Mobile-first meal logging / dashboard UI
 * ================================================================ */
add_shortcode( 'bizcity_calo', 'bzcalo_render_shortcode' );

function bzcalo_render_shortcode( $atts = array() ) {
    if ( ! is_user_logged_in() ) {
        return '<div class="bzcalo-login-msg" style="text-align:center;padding:32px"><p>🔐 Vui lòng đăng nhập để sử dụng.</p><a href="' . wp_login_url( get_permalink() ) . '" class="button">Đăng nhập</a></div>';
    }

    $user_id = get_current_user_id();
    $profile = bzcalo_get_or_create_profile( $user_id );
    $target  = (int) ( $profile['daily_calo_target'] ?? 2000 );

    wp_enqueue_style( 'bzcalo-public' );
    wp_enqueue_script( 'bzcalo-public' );
    // Calculate macro targets (Carbs/Protein/Fat split by goal)
    $goal_type = $profile['goal'] ?? 'maintain';
    if ( $goal_type === 'lose' ) { $cpct = 0.40; $ppct = 0.30; $fpct = 0.30; }
    elseif ( $goal_type === 'gain' ) { $cpct = 0.50; $ppct = 0.25; $fpct = 0.25; }
    else { $cpct = 0.45; $ppct = 0.25; $fpct = 0.30; }
    $target_carbs   = round( $target * $cpct / 4 );
    $target_protein = round( $target * $ppct / 4 );
    $target_fat     = round( $target * $fpct / 9 );

    wp_localize_script( 'bzcalo-public', 'BZCALO', array(
        'ajax_url'       => admin_url( 'admin-ajax.php' ),
        'nonce'          => wp_create_nonce( 'bzcalo_pub_nonce' ),
        'target'         => $target,
        'target_protein' => $target_protein,
        'target_carbs'   => $target_carbs,
        'target_fat'     => $target_fat,
        'user_id'        => $user_id,
        'weight_kg'      => (float) ( $profile['weight_kg'] ?? 0 ),
        'height_cm'      => (float) ( $profile['height_cm'] ?? 0 ),
    ) );

    $tab = sanitize_text_field( $_GET['tab'] ?? 'dashboard' );

    ob_start();
    ?>
    <div id="bzcalo-app" class="bzcalo-app">
        <!-- Navigation Tabs (bottom bar on mobile) -->
        <nav class="bzcalo-nav">
            <a href="?tab=dashboard" class="bzcalo-nav-item <?php echo $tab === 'dashboard' ? 'active' : ''; ?>" data-tab="dashboard">
                <span class="bzcalo-nav-icon">📊</span><span>Hôm nay</span>
            </a>
            <a href="?tab=log" class="bzcalo-nav-item <?php echo $tab === 'log' ? 'active' : ''; ?>" data-tab="log">
                <span class="bzcalo-nav-icon">📸</span><span>Ghi bữa</span>
            </a>
            <a href="?tab=weight" class="bzcalo-nav-item <?php echo $tab === 'weight' ? 'active' : ''; ?>" data-tab="weight">
                <span class="bzcalo-nav-icon">⚖️</span><span>Cân nặng</span>
            </a>
            <a href="?tab=history" class="bzcalo-nav-item <?php echo $tab === 'history' ? 'active' : ''; ?>" data-tab="history">
                <span class="bzcalo-nav-icon">📜</span><span>Lịch sử</span>
            </a>
            <a href="?tab=profile" class="bzcalo-nav-item <?php echo $tab === 'profile' ? 'active' : ''; ?>" data-tab="profile">
                <span class="bzcalo-nav-icon">👤</span><span>Hồ sơ</span>
            </a>
        </nav>

        <!-- ═════ Tab: Dashboard ═════ -->
        <div class="bzcalo-tab-content" id="bzcalo-tab-dashboard" <?php echo $tab !== 'dashboard' ? 'style="display:none"' : ''; ?>>
            <!-- Mascot greeting -->
            <div class="bzcalo-mascot-area">
                <div class="bzcalo-mascot-avatar">🧑‍🍳</div>
                <div class="bzcalo-mascot-bubble">
                    <span id="bzcalo-mascot-msg">Chào bạn! Hôm nay bạn ăn gì rồi? 🍽️</span>
                </div>
            </div>

            <!-- Weekly calendar strip -->
            <div class="bzcalo-week-strip" id="bzcalo-week-strip"></div>

            <!-- Ring chart + calo -->
            <div class="bzcalo-ring-card">
                <div class="bzcalo-ring-wrap">
                    <svg class="bzcalo-ring-svg" viewBox="0 0 36 36">
                        <path class="bzcalo-ring-bg" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                        <path class="bzcalo-ring-fg" id="bzcalo-ring-progress" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" stroke-dasharray="0, 100" />
                    </svg>
                    <div class="bzcalo-ring-center">
                        <span class="bzcalo-ring-cal" id="bzcalo-today-cal">0</span>
                        <span class="bzcalo-ring-label">/ <?php echo $target; ?> kcal</span>
                    </div>
                </div>
            </div>

            <!-- Macro Progress Bars -->
            <div class="bzcalo-macro-bars">
                <div class="bzcalo-macro-bar-item">
                    <div class="bzcalo-macro-bar-header">
                        <span class="bzcalo-macro-bar-label">🍞 Tinh bột</span>
                        <span class="bzcalo-macro-bar-value"><span id="bzcalo-today-c">0</span> / <?php echo $target_carbs; ?>g</span>
                    </div>
                    <div class="bzcalo-macro-bar-track">
                        <div class="bzcalo-macro-bar-fill carbs" id="bzcalo-bar-carbs" style="width:0%"></div>
                    </div>
                </div>
                <div class="bzcalo-macro-bar-item">
                    <div class="bzcalo-macro-bar-header">
                        <span class="bzcalo-macro-bar-label">🥩 Đạm</span>
                        <span class="bzcalo-macro-bar-value"><span id="bzcalo-today-p">0</span> / <?php echo $target_protein; ?>g</span>
                    </div>
                    <div class="bzcalo-macro-bar-track">
                        <div class="bzcalo-macro-bar-fill protein" id="bzcalo-bar-protein" style="width:0%"></div>
                    </div>
                </div>
                <div class="bzcalo-macro-bar-item">
                    <div class="bzcalo-macro-bar-header">
                        <span class="bzcalo-macro-bar-label">🧈 Chất béo</span>
                        <span class="bzcalo-macro-bar-value"><span id="bzcalo-today-f">0</span> / <?php echo $target_fat; ?>g</span>
                    </div>
                    <div class="bzcalo-macro-bar-track">
                        <div class="bzcalo-macro-bar-fill fat" id="bzcalo-bar-fat" style="width:0%"></div>
                    </div>
                </div>
            </div>

            <!-- Today meals list -->
            <div class="bzcalo-section">
                <h3 class="bzcalo-section-title">🍽️ Bữa ăn hôm nay</h3>
                <div id="bzcalo-today-meals" class="bzcalo-meal-list">
                    <p class="bzcalo-empty">Đang tải...</p>
                </div>
            </div>

            <!-- Quick action -->
            <div class="bzcalo-fab-wrap">
                <button class="bzcalo-fab" onclick="document.querySelector('[data-tab=log]').click()">📸 Ghi bữa ăn</button>
            </div>
        </div>

        <!-- ═════ Tab: Log Meal ═════ -->
        <div class="bzcalo-tab-content" id="bzcalo-tab-log" <?php echo $tab !== 'log' ? 'style="display:none"' : ''; ?>>
            <div class="bzcalo-card">
                <h3>📸 Ghi nhận bữa ăn</h3>

                <!-- File input: visually hidden (display:none breaks .click() on some mobile browsers) -->
                <input type="file" id="bzcalo-pub-photo-input" accept="image/*"
                       style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);border:0">
                <!-- Camera zone — <label> ensures native file-picker trigger on all browsers -->
                <label class="bzcalo-photo-zone" id="bzcalo-pub-photo-zone" for="bzcalo-pub-photo-input">
                    <div id="bzcalo-pub-photo-preview" style="display:none">
                        <img id="bzcalo-pub-photo-img">
                    </div>
                    <div class="bzcalo-photo-placeholder" id="bzcalo-pub-photo-placeholder">
                        <span style="font-size:40px">📷</span>
                        <p>Chụp ảnh hoặc chọn ảnh bữa ăn</p>
                        <small>AI tự nhận diện &amp; tính calo</small>
                    </div>
                </label>

                <input type="hidden" id="bzcalo-pub-photo-url" value="">

                <!-- Description -->
                <div class="bzcalo-field">
                    <textarea id="bzcalo-pub-desc" rows="2" placeholder="hoặc mô tả: 1 tô phở bò, 1 ly trà đá..."></textarea>
                </div>

                <!-- Meal type pills -->
                <div class="bzcalo-meal-type-row">
                    <label class="bzcalo-pill"><input type="radio" name="bzcalo_pub_meal_type" value="breakfast"> 🌅 Sáng</label>
                    <label class="bzcalo-pill active"><input type="radio" name="bzcalo_pub_meal_type" value="lunch" checked> ☀️ Trưa</label>
                    <label class="bzcalo-pill"><input type="radio" name="bzcalo_pub_meal_type" value="dinner"> 🌙 Tối</label>
                    <label class="bzcalo-pill"><input type="radio" name="bzcalo_pub_meal_type" value="snack"> 🍪 Vặt</label>
                </div>

                <!-- AI Result -->
                <div id="bzcalo-pub-ai-result" class="bzcalo-ai-result" style="display:none">
                    <h4>🤖 AI Phân tích</h4>
                    <div id="bzcalo-pub-ai-items"></div>
                    <div id="bzcalo-pub-ai-total" class="bzcalo-ai-total"></div>
                </div>

                <div class="bzcalo-btn-row">
                    <button type="button" id="bzcalo-pub-btn-ai" class="bzcalo-btn bzcalo-btn-secondary">🤖 Phân tích</button>
                    <button type="button" id="bzcalo-pub-btn-save" class="bzcalo-btn bzcalo-btn-primary">💾 Lưu</button>
                </div>

                <div id="bzcalo-pub-status" class="bzcalo-status" style="display:none"></div>
            </div>
        </div>

        <!-- ═════ Tab: Weight ═════ -->
        <div class="bzcalo-tab-content" id="bzcalo-tab-weight" <?php echo $tab !== 'weight' ? 'style="display:none"' : ''; ?>>
            <!-- BMI Card -->
            <div class="bzcalo-bmi-card">
                <div class="bzcalo-bmi-circle" id="bzcalo-bmi-circle">
                    <span class="bzcalo-bmi-val" id="bzcalo-bmi-val">--</span>
                    <span class="bzcalo-bmi-label">BMI</span>
                </div>
                <div class="bzcalo-bmi-info">
                    <div class="bzcalo-bmi-status" id="bzcalo-bmi-status">Chưa có dữ liệu</div>
                    <div class="bzcalo-bmi-detail">
                        <span>Cân nặng: <strong id="bzcalo-w-current"><?php echo $profile['weight_kg'] ?: '--'; ?></strong> kg</span>
                        <span>Mục tiêu: <strong id="bzcalo-w-target"><?php echo $profile['target_weight'] ?: '--'; ?></strong> kg</span>
                    </div>
                </div>
            </div>

            <!-- BMI Scale -->
            <div class="bzcalo-bmi-scale">
                <div class="bzcalo-bmi-range under"><span>&lt;18.5</span></div>
                <div class="bzcalo-bmi-range normal"><span>18.5–25</span></div>
                <div class="bzcalo-bmi-range over"><span>25–30</span></div>
                <div class="bzcalo-bmi-range obese"><span>&gt;30</span></div>
                <div class="bzcalo-bmi-pointer" id="bzcalo-bmi-pointer"></div>
            </div>

            <!-- Log Weight Form -->
            <div class="bzcalo-card">
                <h3>⚖️ Ghi cân nặng hôm nay</h3>
                <div class="bzcalo-field-row">
                    <div class="bzcalo-field">
                        <label>Cân nặng (kg)</label>
                        <input type="number" id="bzcalo-w-input" step="0.1" min="20" max="300" value="<?php echo esc_attr( $profile['weight_kg'] ); ?>">
                    </div>
                    <div class="bzcalo-field">
                        <label>Ngày</label>
                        <input type="date" id="bzcalo-w-date" value="<?php echo current_time( 'Y-m-d' ); ?>">
                    </div>
                </div>
                <div class="bzcalo-field">
                    <textarea id="bzcalo-w-note" rows="1" placeholder="Ghi chú (tùy chọn)..."></textarea>
                </div>
                <button type="button" id="bzcalo-w-save" class="bzcalo-btn bzcalo-btn-primary" style="width:100%">💾 Lưu cân nặng</button>
                <div id="bzcalo-w-status" class="bzcalo-status" style="display:none"></div>
            </div>

            <!-- Weight Chart -->
            <div class="bzcalo-section">
                <h3 class="bzcalo-section-title">📈 Biểu đồ cân nặng (30 ngày)</h3>
                <div id="bzcalo-weight-chart" class="bzcalo-weight-chart"></div>
            </div>

            <!-- Weight History -->
            <div class="bzcalo-section">
                <h3 class="bzcalo-section-title">📜 Nhật ký cân nặng</h3>
                <div id="bzcalo-weight-list" class="bzcalo-meal-list">
                    <p class="bzcalo-empty">Đang tải...</p>
                </div>
            </div>
        </div>

        <!-- ═════ Tab: History ═════ -->
        <div class="bzcalo-tab-content" id="bzcalo-tab-history" <?php echo $tab !== 'history' ? 'style="display:none"' : ''; ?>>
            <div class="bzcalo-section">
                <h3 class="bzcalo-section-title">📈 Biểu đồ 7 ngày</h3>
                <div id="bzcalo-chart-7d" class="bzcalo-bar-chart"></div>
            </div>
            <div class="bzcalo-section">
                <h3 class="bzcalo-section-title">📜 Lịch sử bữa ăn</h3>
                <div id="bzcalo-history-list" class="bzcalo-meal-list">
                    <p class="bzcalo-empty">Đang tải...</p>
                </div>
            </div>
        </div>

        <!-- ═════ Tab: Profile ═════ -->
        <div class="bzcalo-tab-content" id="bzcalo-tab-profile" <?php echo $tab !== 'profile' ? 'style="display:none"' : ''; ?>>
            <div class="bzcalo-card">
                <h3>👤 Hồ sơ dinh dưỡng</h3>
                <form id="bzcalo-profile-form">
                    <div class="bzcalo-field">
                        <label>Họ tên</label>
                        <input type="text" id="bzcalo-p-name" value="<?php echo esc_attr( $profile['full_name'] ); ?>">
                    </div>
                    <div class="bzcalo-field-row">
                        <div class="bzcalo-field">
                            <label>Giới tính</label>
                            <select id="bzcalo-p-gender">
                                <option value="male" <?php selected( $profile['gender'], 'male' ); ?>>Nam</option>
                                <option value="female" <?php selected( $profile['gender'], 'female' ); ?>>Nữ</option>
                                <option value="other" <?php selected( $profile['gender'], 'other' ); ?>>Khác</option>
                            </select>
                        </div>
                        <div class="bzcalo-field">
                            <label>Ngày sinh</label>
                            <input type="date" id="bzcalo-p-dob" value="<?php echo esc_attr( $profile['dob'] ); ?>">
                        </div>
                    </div>
                    <div class="bzcalo-field-row">
                        <div class="bzcalo-field">
                            <label>Chiều cao (cm)</label>
                            <input type="number" id="bzcalo-p-height" step="0.1" value="<?php echo esc_attr( $profile['height_cm'] ); ?>">
                        </div>
                        <div class="bzcalo-field">
                            <label>Cân nặng (kg)</label>
                            <input type="number" id="bzcalo-p-weight" step="0.1" value="<?php echo esc_attr( $profile['weight_kg'] ); ?>">
                        </div>
                    </div>
                    <div class="bzcalo-field-row">
                        <div class="bzcalo-field">
                            <label>Mục tiêu</label>
                            <select id="bzcalo-p-goal">
                                <option value="lose" <?php selected( $profile['goal'], 'lose' ); ?>>🔽 Giảm cân</option>
                                <option value="maintain" <?php selected( $profile['goal'], 'maintain' ); ?>>⚖️ Duy trì</option>
                                <option value="gain" <?php selected( $profile['goal'], 'gain' ); ?>>🔼 Tăng cân</option>
                            </select>
                        </div>
                        <div class="bzcalo-field">
                            <label>Vận động</label>
                            <select id="bzcalo-p-activity">
                                <option value="sedentary" <?php selected( $profile['activity_level'], 'sedentary' ); ?>>Ít vận động</option>
                                <option value="light" <?php selected( $profile['activity_level'], 'light' ); ?>>Nhẹ</option>
                                <option value="moderate" <?php selected( $profile['activity_level'], 'moderate' ); ?>>TB</option>
                                <option value="active" <?php selected( $profile['activity_level'], 'active' ); ?>>Năng động</option>
                                <option value="very_active" <?php selected( $profile['activity_level'], 'very_active' ); ?>>Rất năng động</option>
                            </select>
                        </div>
                    </div>
                    <div class="bzcalo-field">
                        <label>Cân nặng mục tiêu (kg)</label>
                        <input type="number" id="bzcalo-p-target-w" step="0.1" value="<?php echo esc_attr( $profile['target_weight'] ); ?>">
                    </div>
                    <div class="bzcalo-field">
                        <label>Dị ứng thực phẩm</label>
                        <textarea id="bzcalo-p-allergies" rows="2"><?php echo esc_textarea( $profile['allergies'] ); ?></textarea>
                    </div>
                    <button type="button" id="bzcalo-profile-save" class="bzcalo-btn bzcalo-btn-primary" style="width:100%">💾 Lưu hồ sơ</button>
                    <p class="bzcalo-hint" id="bzcalo-profile-status">Mục tiêu: <?php echo $target; ?> kcal/ngày</p>
                </form>
            </div>
        </div>
        <!-- Success Dialog -->
        <div id="bzcalo-dialog-overlay" class="bzcalo-dialog-overlay" style="display:none">
            <div class="bzcalo-dialog">
                <div class="bzcalo-dialog-icon">✅</div>
                <h3 class="bzcalo-dialog-title">Hồ sơ đã được lưu!</h3>
                <p class="bzcalo-dialog-msg">Từ giờ trợ lý AI sẽ luôn <strong>ghi nhớ hồ sơ</strong> của bạn để:</p>
                <ul class="bzcalo-dialog-list">
                    <li>🤖 Tính calo chính xác theo thể trạng của bạn</li>
                    <li>📸 Lưu bữa ăn & nhận diện món từ ảnh chụp</li>
                    <li>📊 Theo dõi tổng calo bạn nạp mỗi ngày</li>
                    <li>📈 Báo cáo hành trình dinh dưỡng của bạn</li>
                </ul>
                <p class="bzcalo-dialog-target" id="bzcalo-dialog-target"></p>
                <button type="button" class="bzcalo-btn bzcalo-btn-primary" id="bzcalo-dialog-close" style="width:100%">👍 Tuyệt vời, bắt đầu thôi!</button>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
