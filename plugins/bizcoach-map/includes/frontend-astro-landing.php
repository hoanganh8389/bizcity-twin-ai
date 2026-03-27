<?php
/**
 * BizCoach Map – Frontend Astrology Landing Page
 *
 * Cung cấp:
 *   [bccm_astro_landing] — Trang landing chiêm tinh (guest + member)
 *
 * Quy trình 4 bước:
 *   Step 1: Tạo bản đồ sao (guest, lưu transient)
 *   Step 2: Đăng ký thành viên (WooCommerce form)
 *   Step 3: Tạo nhân bản AI Agent (after login)
 *   Step 4: Tạo / gán Character trong bizcoach
 *
 * @package BizCoach_Map
 * @since   0.1.0.21
 */
if (!defined('ABSPATH')) exit;

/* =====================================================================
 * 1. SHORTCODE: [bccm_astro_landing]
 * =====================================================================*/
add_shortcode('bccm_astro_landing', 'bccm_astro_landing_shortcode');

function bccm_astro_landing_shortcode($atts) {
  $atts = shortcode_atts([
    'title' => 'Khám Phá Bản Đồ Sao Của Bạn',
  ], $atts, 'bccm_astro_landing');

  // Enqueue assets
  wp_enqueue_style('bccm-astro-landing', BCCM_URL . 'assets/css/astro-landing.css', [], BCCM_VERSION);
  wp_enqueue_script('bccm-astro-landing', BCCM_URL . 'assets/js/astro-landing.js', [], BCCM_VERSION, true);
  wp_localize_script('bccm-astro-landing', 'bccmAstroLanding', [
    'ajaxUrl' => admin_url('admin-ajax.php'),
    'nonce'   => wp_create_nonce('bccm_astro_landing'),
    'isLoggedIn' => is_user_logged_in() ? 1 : 0,
    'myAccountUrl' => function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : wp_login_url(),
  ]);

  $is_logged_in = is_user_logged_in();
  $user_id = get_current_user_id();

  // Check progress for logged-in users
  $progress = bccm_get_user_onboarding_progress($user_id);

  ob_start();
  ?>
  <div class="astro-lp-page">
    <!-- Starfield background -->
    <div class="astro-lp-starfield"></div>

    <div class="astro-lp-container">

      <!-- ============== HERO ============== -->
      <div class="astro-lp-hero">
        <?php
        $logo_url = BCCM_URL . 'assets/icon/nobi.png';
        if (file_exists(BCCM_DIR . 'assets/icon/nobi.png')): ?>
          <img src="<?php echo esc_url($logo_url); ?>" alt="Astro" class="astro-lp-hero-logo">
        <?php endif; ?>
        <h1><?php echo esc_html($atts['title']); ?></h1>
        <p class="astro-lp-tagline">Giải mã bản đồ sao cá nhân – Khám phá bí mật cuộc đời bạn từ vũ trụ</p>
        <p class="astro-lp-description">
          Từ thưở mới sinh ra, mỗi con người có cho riêng mình một và duy nhất một chòm sao thiên mệnh.
          Hãy khám phá thông điệp cuộc đời mà chòm sao đó mang đến cho bạn.
        </p>

        <!-- Zodiac Ring -->
        <div class="astro-lp-zodiac-ring">
          <?php
          $signs = [
            '♈','♉','♊','♋','♌','♍',
            '♎','♏','♐','♑','♒','♓'
          ];
          foreach ($signs as $s):
          ?>
            <div class="zodiac-icon"><?php echo $s; ?></div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- ============== PROGRESS STEPS ============== -->
      <div class="astro-lp-steps">
        <h2 class="astro-lp-steps-title">🎯 Quy trình xây dựng bản đồ cá nhân</h2>
        <div class="astro-lp-steps-grid">
          <?php
          $steps = [
            1 => ['icon' => '🌟', 'name' => 'Tạo Bản Đồ Sao', 'desc' => 'Nhập thông tin sinh → tạo bản đồ chiêm tinh'],
            2 => ['icon' => '📋', 'name' => 'Đăng Ký Thành Viên', 'desc' => 'Tạo tài khoản để lưu & xem chi tiết'],
            3 => ['icon' => '🤖', 'name' => 'Tạo AI Agent', 'desc' => 'Tạo trợ lý AI dựa trên chiêm tinh'],
            4 => ['icon' => '🎯', 'name' => 'Gán Character', 'desc' => 'Gán trợ lý AI làm bạn đồng hành'],
          ];
          foreach ($steps as $num => $step):
            $status_class = 'pending';
            $status_text = 'Chưa bắt đầu';
            if ($is_logged_in && !empty($progress)) {
              if (!empty($progress['step' . $num])) {
                $status_class = 'done';
                $status_text = '✅ Hoàn thành';
              } elseif ($num === $progress['current_step']) {
                $status_class = 'active';
                $status_text = '⏳ Đang thực hiện';
              }
            } elseif ($num === 1) {
              $status_class = 'active';
              $status_text = '⏳ Hãy bắt đầu';
            }
          ?>
          <div class="astro-lp-step <?php echo $status_class; ?>" data-step="<?php echo $num; ?>">
            <span class="astro-lp-step-num"><?php echo $status_class === 'done' ? '✓' : $num; ?></span>
            <span class="astro-lp-step-icon"><?php echo $step['icon']; ?></span>
            <span class="astro-lp-step-name"><?php echo esc_html($step['name']); ?></span>
            <span class="astro-lp-step-desc"><?php echo esc_html($step['desc']); ?></span>
            <span class="astro-lp-step-status"><?php echo $status_text; ?></span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- ============== SERVICES CARDS ============== -->
      <div class="astro-lp-services">
        <div class="astro-lp-service-card">
          <span class="service-icon">🌌</span>
          <h3>Bản Đồ Sao Cá Nhân</h3>
          <p>Natal Chart – Khám phá tính cách, tiềm năng và thử thách trong cuộc đời bạn.</p>
        </div>
        <div class="astro-lp-service-card">
          <span class="service-icon">💑</span>
          <h3>Bản Đồ Mối Quan Hệ</h3>
          <p>Synastry Chart – Tìm kiếm sự hòa hợp giữa hai người trong mối quan hệ.</p>
        </div>
        <div class="astro-lp-service-card">
          <span class="service-icon">🔮</span>
          <h3>Bản Đồ Dự Báo</h3>
          <p>Forecasting – Nắm bắt tương lai, cơ hội và thách thức hàng ngày/tuần/tháng/năm.</p>
        </div>
      </div>

      <!-- ============== BIRTH CHART FORM / RESULT ============== -->
      <?php
      // Check if user already has chart data
      $user_astro_data = null;
      $user_id = get_current_user_id();
      if ($is_logged_in) {
        $user_astro_data = bccm_get_user_astro_display_data($user_id);
      }
      
      if ($user_astro_data && !empty($user_astro_data['planets'])):
        // Show existing chart result for logged-in user
        $zodiac_signs = function_exists('bccm_zodiac_signs') ? bccm_zodiac_signs() : [];
        ?>
        <div class="astro-lp-result" style="display:block">
          <div class="astro-lp-result-header">
            <h2>✨ Bản Đồ Sao Của Bạn</h2>
            <?php if (!empty($user_astro_data['zodiac_vi'])): ?>
              <div class="astro-lp-zodiac-badge"><?php echo esc_html($user_astro_data['zodiac_vi']); ?></div>
            <?php endif; ?>
          </div>
          <div class="astro-lp-card">
            <div class="astro-lp-user-info">
              <p><strong><?php echo esc_html($user_astro_data['full_name']); ?></strong></p>
              <?php if (!empty($user_astro_data['dob'])): 
                $dob_parts = explode('-', $user_astro_data['dob']);
                $dob_formatted = count($dob_parts) === 3 ? $dob_parts[2].'/'.$dob_parts[1].'/'.$dob_parts[0] : $user_astro_data['dob'];
              ?>
                <p style="color:#9ca3af;font-size:14px">Sinh ngày <?php echo esc_html($dob_formatted); ?> lúc <?php echo esc_html($user_astro_data['birth_time'] ?: '12:00'); ?></p>
              <?php endif; ?>
              <?php if (!empty($user_astro_data['birth_place'])): ?>
                <p style="color:#9ca3af;font-size:14px">Tại: <?php echo esc_html($user_astro_data['birth_place']); ?></p>
              <?php endif; ?>
            </div>

            <?php 
            // Big 3
            $sun_vi = '';
            $moon_vi = '';
            $asc_vi = '';
            foreach ($zodiac_signs as $zs) {
              if (!empty($user_astro_data['sun_sign']) && strtolower($zs['en']) === strtolower($user_astro_data['sun_sign'])) $sun_vi = $zs['vi'];
              if (!empty($user_astro_data['moon_sign']) && strtolower($zs['en']) === strtolower($user_astro_data['moon_sign'])) $moon_vi = $zs['vi'];
              if (!empty($user_astro_data['asc_sign']) && strtolower($zs['en']) === strtolower($user_astro_data['asc_sign'])) $asc_vi = $zs['vi'];
            }
            if ($sun_vi || $moon_vi || $asc_vi): ?>
              <div class="astro-lp-big3">
                <?php if ($sun_vi): ?>
                  <div class="astro-lp-big3-card">
                    <div class="big3-icon">☀️</div>
                    <div class="big3-label">Mặt Trời</div>
                    <div class="big3-sign"><?php echo esc_html($sun_vi); ?></div>
                  </div>
                <?php endif; ?>
                <?php if ($moon_vi): ?>
                  <div class="astro-lp-big3-card">
                    <div class="big3-icon">🌙</div>
                    <div class="big3-label">Mặt Trăng</div>
                    <div class="big3-sign"><?php echo esc_html($moon_vi); ?></div>
                  </div>
                <?php endif; ?>
                <?php if ($asc_vi): ?>
                  <div class="astro-lp-big3-card">
                    <div class="big3-icon">⬆️</div>
                    <div class="big3-label">Cung Mọc</div>
                    <div class="big3-sign"><?php echo esc_html($asc_vi); ?></div>
                  </div>
                <?php endif; ?>
              </div>
            <?php endif; ?>

            <?php if (!empty($user_astro_data['chart_url'])): ?>
              <div class="astro-lp-chart-wheel">
                <h3 style="margin-bottom:12px">🔮 Bản Đồ Sao Natal</h3>
                <img src="<?php echo esc_url($user_astro_data['chart_url']); ?>" alt="Natal Wheel Chart" style="max-width:100%;border-radius:12px;box-shadow:0 4px 20px rgba(0,0,0,0.3)" loading="lazy" />
              </div>
            <?php endif; ?>

            <?php if (!empty($user_astro_data['planets'])): ?>
              <h3 style="margin:24px 0 12px">🪐 Vị Trí Các Hành Tinh</h3>
              <div class="astro-lp-planet-grid">
                <?php foreach (array_slice($user_astro_data['planets'], 0, 6) as $p): ?>
                  <div class="astro-lp-planet-tile">
                    <div class="planet-name"><?php echo esc_html($p['name_vi'] ?: $p['name']); ?><?php if ($p['is_retro']): ?> <span style="color:#ef4444" title="Nghịch hành">℞</span><?php endif; ?></div>
                    <div class="planet-sign"><?php echo esc_html($p['sign_symbol'] . ' ' . ($p['sign_vi'] ?: $p['sign'])); ?></div>
                    <div class="planet-degree"><?php echo esc_html($p['degree']); ?></div>
                  </div>
                <?php endforeach; ?>
              </div>

              <?php if (count($user_astro_data['planets']) > 6): ?>
                <div class="astro-lp-preview-blur">
                  <div class="blur-content">
                    <div class="astro-lp-planet-grid">
                      <?php foreach (array_slice($user_astro_data['planets'], 6) as $p): ?>
                        <div class="astro-lp-planet-tile">
                          <div class="planet-name"><?php echo esc_html($p['name_vi'] ?: $p['name']); ?></div>
                          <div class="planet-sign"><?php echo esc_html($p['sign_vi'] ?: $p['sign']); ?></div>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  </div>
                  <div class="blur-overlay">
                    <div class="blur-overlay-text">✨ Bản đồ sao đầy đủ đã lưu trong tài khoản của bạn</div>
                    <a href="<?php echo esc_url(home_url('/dung-thu-mien-phi/')); ?>" class="astro-lp-btn-primary">🗺️ Xem Bản Đồ Sao & Tạo AI Agent</a>
                  </div>
                </div>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>

      <?php else: 
        // Pre-fill form with existing data if available
        $prefill = [
          'full_name'   => '',
          'dob'         => '',
          'birth_time'  => '12:00',
          'birth_place' => '',
          'latitude'    => 21.0285,
          'longitude'   => 105.8542,
          'timezone'    => 7,
        ];
        if ($user_astro_data) {
          $prefill['full_name'] = $user_astro_data['full_name'] ?? '';
          $prefill['dob'] = $user_astro_data['dob'] ?? '';
          $prefill['birth_time'] = $user_astro_data['birth_time'] ?: '12:00';
          $prefill['birth_place'] = $user_astro_data['birth_place'] ?? '';
          $prefill['latitude'] = $user_astro_data['latitude'] ?? 21.0285;
          $prefill['longitude'] = $user_astro_data['longitude'] ?? 105.8542;
          $prefill['timezone'] = $user_astro_data['timezone'] ?? 7;
        }
        $needs_regenerate = $is_logged_in && $user_astro_data && empty($user_astro_data['planets']);
        ?>
        <!-- Show form for new users or guests -->
        <div class="astro-lp-form-section" id="alp-form-section">
        <div class="astro-lp-card">
          <?php if ($needs_regenerate): ?>
            <div style="background:#7c3aed22;border:1px solid #7c3aed;padding:12px 16px;border-radius:8px;margin-bottom:16px;">
              <p style="margin:0;color:#d1d5db;">⚠️ Bản đồ sao của bạn chưa hoàn chỉnh. Vui lòng nhấn <strong>Tạo Bản Đồ Sao</strong> để cập nhật.</p>
            </div>
          <?php endif; ?>
          <h2 class="astro-lp-card-title">✨ Tạo Bản Đồ Sao</h2>
          <p class="astro-lp-card-subtitle">Nhập thông tin sinh để giải mã bản đồ chiêm tinh cá nhân của bạn</p>

          <form id="alp-chart-form" method="post">
            <div class="astro-lp-form-grid">

              <div class="astro-lp-field full-width">
                <label>Họ tên *</label>
                <input type="text" name="full_name" placeholder="Nhập họ và tên đầy đủ" required value="<?php echo esc_attr($prefill['full_name']); ?>">
              </div>

              <div class="astro-lp-field">
                <label>Giới tính</label>
                <select name="gender">
                  <option value="male">Nam</option>
                  <option value="female">Nữ</option>
                  <option value="other">Khác</option>
                </select>
              </div>

              <div class="astro-lp-field">
                <label>Múi giờ</label>
                <select name="timezone" id="alp-timezone">
                  <?php for ($tz = -12; $tz <= 14; $tz++): ?>
                    <option value="<?php echo $tz; ?>" <?php selected($tz, (int)$prefill['timezone']); ?>>
                      UTC<?php echo ($tz >= 0 ? '+' : '') . $tz; ?>
                      <?php if ($tz === 7) echo ' (Việt Nam)'; ?>
                    </option>
                  <?php endfor; ?>
                </select>
              </div>

              <div class="astro-lp-field">
                <label>Ngày sinh *</label>
                <input type="date" name="dob" required value="<?php echo esc_attr($prefill['dob']); ?>">
              </div>

              <div class="astro-lp-field">
                <label>Giờ sinh</label>
                <input type="time" name="birth_time" placeholder="HH:MM" value="<?php echo esc_attr($prefill['birth_time']); ?>">
              </div>

              <div class="astro-lp-field full-width">
                <label>Nơi sinh</label>
                <div class="astro-lp-place-wrap">
                  <input type="text" name="birth_place" id="alp-birth-place"
                         placeholder="Nhập và chọn nơi sinh (ví dụ: Hà Nội)" autocomplete="off" value="<?php echo esc_attr($prefill['birth_place']); ?>">
                  <div id="alp-place-results" class="astro-lp-place-results"></div>
                </div>
                <input type="hidden" name="latitude" id="alp-latitude" value="<?php echo esc_attr($prefill['latitude']); ?>">
                <input type="hidden" name="longitude" id="alp-longitude" value="<?php echo esc_attr($prefill['longitude']); ?>">
              </div>

            </div>

            <div class="astro-lp-submit-wrap">
              <button type="submit" class="astro-lp-btn-primary">
                🌟 Tạo Bản Đồ Sao
              </button>
            </div>
          </form>

          <!-- Loading -->
          <div id="alp-loading" class="astro-lp-loading">
            <div class="astro-lp-spinner"></div>
            <div class="astro-lp-loading-text">Đang tạo bản đồ sao của bạn...</div>
          </div>
        </div>
      </div>
      <?php endif; ?>


      <!-- ============== CTA SECTION ============== -->
      <div id="alp-cta-section" class="astro-lp-cta-section" style="display:none">
        <div class="astro-lp-cta-card">
          <h2>🎉 Bản đồ sao đã tạo thành công!</h2>
          <p>Đăng ký thành viên để xem bản đồ luận giải chi tiết, bản đồ hàng ngày, tuần, tháng, năm và nhận trợ lý AI cá nhân.</p>

          <div class="astro-lp-cta-features">
            <div class="astro-lp-cta-feature">
              <span class="check-icon">✅</span>
              <span>Bản đồ chiêm tinh chi tiết (Western + Vedic)</span>
            </div>
            <div class="astro-lp-cta-feature">
              <span class="check-icon">✅</span>
              <span>AI phân tích & luận giải chuyên sâu</span>
            </div>
            <div class="astro-lp-cta-feature">
              <span class="check-icon">✅</span>
              <span>Dự báo hàng ngày / tuần / tháng / năm</span>
            </div>
            <div class="astro-lp-cta-feature">
              <span class="check-icon">✅</span>
              <span>AI Agent trợ lý cá nhân 24/7</span>
            </div>
            <div class="astro-lp-cta-feature">
              <span class="check-icon">✅</span>
              <span>Bản đồ cuộc đời & Success Plan</span>
            </div>
            <div class="astro-lp-cta-feature">
              <span class="check-icon">✅</span>
              <span>Thần số học & Numerology đầy đủ</span>
            </div>
          </div>

          <?php if (!$is_logged_in): ?>
            <button class="astro-lp-btn-primary" data-action="show-register">
              ✨ Đăng Ký Thành Viên Ngay
            </button>
          <?php else: ?>
            <a href="<?php echo esc_url(wc_get_page_permalink('myaccount') . 'life-map/'); ?>" class="astro-lp-btn-primary">
              🗺️ Xem Bản Đồ Trong My Account
            </a>
          <?php endif; ?>
        </div>
      </div>

      <!-- ============== LOGIN/REGISTER FORM (WooCommerce) ============== -->
      <?php if (!$is_logged_in): ?>
      <div id="alp-register-wrap" class="astro-lp-register-wrap">
        <div class="astro-lp-card">
          <p class="astro-lp-card-subtitle" style="margin-bottom:16px;">
            Tạo tài khoản để lưu bản đồ sao và truy cập tất cả tính năng chi tiết.
            Dữ liệu bản đồ sao bạn vừa tạo sẽ được tự động liên kết với tài khoản mới.
          </p>
          <?php
          // Add hidden field for transient token to register form
          add_action('woocommerce_register_form', function () {
            $token = isset($_COOKIE['bccm_astro_token']) ? sanitize_text_field($_COOKIE['bccm_astro_token']) : '';
            echo '<input type="hidden" name="bccm_astro_token" value="' . esc_attr($token) . '">';
          });
          
          // Load form-login.php which has both login and register tabs
          if (function_exists('wc_get_template')) {
            echo '<div class="woocommerce astro-lp-auth-form">';
            wc_get_template('myaccount/form-login.php');
            echo '</div>';
          } else {
            echo '<p style="text-align:center"><a href="' . esc_url(wp_login_url()) . '?action=register" class="astro-lp-btn-primary">Đăng Ký</a></p>';
          }
          ?>
        </div>
      </div>
      <?php endif; ?>
      
      <!-- ============== CHART RESULT ============== -->
      <div id="alp-result" class="astro-lp-result">
        <div class="astro-lp-result-header">
          <h2>✨ Bản Đồ Sao Của Bạn</h2>
          <div id="alp-zodiac-badge" class="astro-lp-zodiac-badge" style="display:none"></div>
        </div>
        <div class="astro-lp-card">
          <div id="alp-result-content">
            <!-- Filled by JS -->
          </div>
        </div>
      </div>  
      <!-- ============== FEATURES SECTION ============== -->
      <div class="astro-lp-features">
        <h2 class="astro-lp-features-title">🔮 Kiến Thức Chiêm Tinh</h2>
        <div class="astro-lp-features-grid">
          <div class="astro-lp-feature-card">
            <span class="feature-icon">🏠</span>
            <h4>Cung Địa Bàn</h4>
            <p>Các khía cạnh có ảnh hưởng quan trọng trong cuộc đời bạn – 12 nhà chiêm tinh.</p>
          </div>
          <div class="astro-lp-feature-card">
            <span class="feature-icon">🔥</span>
            <h4>4 Nguyên Tố</h4>
            <p>Lửa, Đất, Khí, Nước – Các nguồn năng lượng cấu thành nên vạn vật.</p>
          </div>
          <div class="astro-lp-feature-card">
            <span class="feature-icon">⭐</span>
            <h4>Hành Tinh</h4>
            <p>Mặt Trời, Mặt Trăng, Sao Thủy, Sao Kim... và ảnh hưởng của chúng.</p>
          </div>
          <div class="astro-lp-feature-card">
            <span class="feature-icon">🔄</span>
            <h4>Góc Chiếu (Aspects)</h4>
            <p>Hợp, Đối, Tam hợp, Vuông góc – Mối quan hệ giữa các hành tinh.</p>
          </div>
          <div class="astro-lp-feature-card">
            <span class="feature-icon">📊</span>
            <h4>Thần Số Học</h4>
            <p>Giải mã con số cuộc đời – Life Path, Soul Number, Personality.</p>
          </div>
          <div class="astro-lp-feature-card">
            <span class="feature-icon">🤖</span>
            <h4>AI Coach Cá Nhân</h4>
            <p>Trợ lý AI được xây dựng từ bản đồ chiêm tinh riêng của bạn.</p>
          </div>
        </div>
      </div>

      <!-- ============== ZODIAC GRID ============== -->
      <div class="astro-lp-zodiac-grid">
        <?php
        $zodiac_data = function_exists('bccm_zodiac_signs') ? bccm_zodiac_signs() : [];
        foreach ($zodiac_data as $zd):
        ?>
        <div class="astro-lp-zodiac-card">
          <span class="z-symbol"><?php echo esc_html($zd['symbol']); ?></span>
          <span class="z-name"><?php echo esc_html($zd['vi']); ?></span>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- ============== FOOTER ============== -->
      <div class="astro-lp-footer">
        <p>✨ Bản Đồ Chiêm Tinh – Powered by BizCoach AI</p>
        <p>© <?php echo date('Y'); ?> – Tất cả quyền được bảo lưu</p>
      </div>

    </div><!-- /.astro-lp-container -->
  </div><!-- /.astro-lp-page -->
  <?php

  return ob_get_clean();
}

/* =====================================================================
 * 2. AJAX: Create Guest Chart (lưu transient)
 * =====================================================================*/
add_action('wp_ajax_bccm_create_guest_chart', 'bccm_ajax_create_guest_chart');
add_action('wp_ajax_nopriv_bccm_create_guest_chart', 'bccm_ajax_create_guest_chart');

function bccm_ajax_create_guest_chart() {
  check_ajax_referer('bccm_astro_landing', 'nonce');

  $full_name  = sanitize_text_field($_POST['full_name'] ?? '');
  $gender     = sanitize_text_field($_POST['gender'] ?? 'male');
  $dob        = sanitize_text_field($_POST['dob'] ?? '');
  $birth_time = sanitize_text_field($_POST['birth_time'] ?? '12:00');
  $birth_place = sanitize_text_field($_POST['birth_place'] ?? '');
  $latitude   = floatval($_POST['latitude'] ?? 21.0285);
  $longitude  = floatval($_POST['longitude'] ?? 105.8542);
  $timezone   = floatval($_POST['timezone'] ?? 7);

  if (empty($full_name) || empty($dob)) {
    wp_send_json_error('Vui lòng nhập Họ tên và Ngày sinh.');
  }

  // Parse DOB
  $dob_parts = explode('-', $dob);
  if (count($dob_parts) !== 3) {
    wp_send_json_error('Ngày sinh không hợp lệ.');
  }

  $time_parts = explode(':', $birth_time);
  $birth_data = [
    'year'      => intval($dob_parts[0]),
    'month'     => intval($dob_parts[1]),
    'day'       => intval($dob_parts[2]),
    'hour'      => intval($time_parts[0] ?? 12),
    'minute'    => intval($time_parts[1] ?? 0),
    'second'    => 0,
    'latitude'  => $latitude,
    'longitude' => $longitude,
    'timezone'  => $timezone,
  ];

  // Determine zodiac sign from DOB
  $zodiac_sign = '';
  $zodiac_vi = '';
  if (function_exists('bccm_astro_sun_sign_from_dob')) {
    $sun = bccm_astro_sun_sign_from_dob($dob);
    $zodiac_sign = strtolower($sun['en'] ?? '');
    $zodiac_vi = $sun['vi'] ?? '';
  }

  // Try to call Astrology API for chart data
  $chart_result = null;
  $planets = [];
  $chart_url = '';
  $parsed = [];

  if (function_exists('bccm_astro_fetch_full_chart')) {
    $chart_result = bccm_astro_fetch_full_chart($birth_data);
    if (!is_wp_error($chart_result)) {
      // Extract planet positions for preview
      $planet_names_vi = function_exists('bccm_planet_names_vi') ? bccm_planet_names_vi() : [];
      $zodiac_signs = function_exists('bccm_zodiac_signs') ? bccm_zodiac_signs() : [];

      // Get parsed positions (already extracted by API)
      $parsed = $chart_result['parsed'] ?? [];

      if (!empty($chart_result['planets'])) {
        foreach ($chart_result['planets'] as $p) {
          // API returns nested structure
          $name = $p['planet']['en'] ?? ($p['name'] ?? '');
          $sign_num = intval($p['zodiac_sign']['number'] ?? ($p['sign'] ?? 0));
          $sign_en = $zodiac_signs[$sign_num]['en'] ?? '';
          $sign_vi = $zodiac_signs[$sign_num]['vi'] ?? '';
          $sign_symbol = $zodiac_signs[$sign_num]['symbol'] ?? '';
          $full_degree = floatval($p['fullDegree'] ?? ($p['full_degree'] ?? 0));
          $norm_degree = floatval($p['normDegree'] ?? 0);
          $is_retro = strtolower($p['isRetro'] ?? 'false') === 'true';
          
          $degree = '';
          if ($norm_degree > 0) {
            $degree = round($norm_degree, 1) . '° ' . ($sign_vi ?: $sign_en);
          } elseif ($full_degree > 0) {
            $in_sign = fmod($full_degree, 30);
            $degree = round($in_sign, 1) . '° ' . ($sign_vi ?: $sign_en);
          }

          $planets[] = [
            'name'        => $name,
            'name_vi'     => $planet_names_vi[$name] ?? $name,
            'sign'        => $sign_en,
            'sign_vi'     => $sign_vi,
            'sign_symbol' => $sign_symbol,
            'degree'      => $degree,
            'is_retro'    => $is_retro,
          ];
        }
      }

      // Get chart wheel URL (not SVG)
      if (!empty($chart_result['chart_url'])) {
        $chart_url = $chart_result['chart_url'];
      }
    }
  }

  // Generate session token for transient storage
  $session_token = '';
  if (is_user_logged_in()) {
    $session_token = 'user_' . get_current_user_id();
  } else {
    $session_token = isset($_COOKIE['bccm_astro_token']) ? sanitize_text_field($_COOKIE['bccm_astro_token']) : '';
    if (empty($session_token)) {
      $session_token = 'guest_' . wp_generate_password(24, false);
    }
  }

  // Store data in transient (24 hours)
  $transient_data = [
    'full_name'   => $full_name,
    'gender'      => $gender,
    'dob'         => $dob,
    'birth_time'  => $birth_time,
    'birth_place' => $birth_place,
    'latitude'    => $latitude,
    'longitude'   => $longitude,
    'timezone'    => $timezone,
    'zodiac_sign' => $zodiac_sign,
    'zodiac_vi'   => $zodiac_vi,
    'chart_result' => (!is_wp_error($chart_result) && $chart_result) ? $chart_result : null,
    'created_at'  => current_time('mysql'),
  ];

  set_transient('bccm_guest_chart_' . $session_token, $transient_data, DAY_IN_SECONDS);

  // Set cookie for session tracking (30 days)
  if (!is_user_logged_in()) {
    setcookie('bccm_astro_token', $session_token, time() + (30 * DAY_IN_SECONDS), COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
  }

  // If user is logged in, also save directly to database
  if (is_user_logged_in()) {
    $user_id = get_current_user_id();
    
    // Get or create coachee profile
    if (function_exists('bccm_get_or_create_user_coachee')) {
      $coachee = bccm_get_or_create_user_coachee($user_id, 'WEBCHAT', 'astro_coach');
      if ($coachee) {
        $coachee_id = (int)$coachee['id'];
        
        // Build birth_input for bccm_astro_save_chart
        $birth_input = [
          'year'        => intval($dob_parts[0]),
          'month'       => intval($dob_parts[1]),
          'day'         => intval($dob_parts[2]),
          'hour'        => intval($time_parts[0] ?? 12),
          'minute'      => intval($time_parts[1] ?? 0),
          'latitude'    => $latitude,
          'longitude'   => $longitude,
          'timezone'    => $timezone,
          'birth_place' => $birth_place,
          'birth_time'  => $birth_time,
        ];
        
        // Save chart data directly using the proper function (Western only)
        if (!is_wp_error($chart_result) && $chart_result && function_exists('bccm_astro_save_chart')) {
          bccm_astro_save_chart($coachee_id, $chart_result, $birth_input);
        } else {
          // Fallback: save basic birth data even if API failed
          bccm_save_guest_chart_to_user($user_id, $transient_data);
        }
      }
    }
  }

  wp_send_json_success([
    'session_token' => $session_token,
    'zodiac_sign'   => $zodiac_sign,
    'zodiac_vi'     => $zodiac_vi,
    'full_name'     => $full_name,
    'dob'           => $dob,
    'birth_time'    => $birth_time,
    'birth_place'   => $birth_place,
    'sun_sign'      => $parsed['sun_sign'] ?? '',
    'moon_sign'     => $parsed['moon_sign'] ?? '',
    'asc_sign'      => $parsed['ascendant_sign'] ?? '',
    'planets'       => $planets,
    'chart_url'     => $chart_url,
    'message'       => 'Đã tạo bản đồ sao thành công!',
  ]);
}

/* =====================================================================
 * 3. SAVE GUEST CHART DATA TO USER (after registration)
 * =====================================================================*/
function bccm_save_guest_chart_to_user($user_id, $chart_data) {
  if (!function_exists('bccm_tables') || !function_exists('bccm_get_or_create_user_coachee')) {
    return false;
  }

  global $wpdb;
  $t = bccm_tables();

  // Get or create coachee
  $coachee = bccm_get_or_create_user_coachee($user_id, 'WEBCHAT', 'astro_coach');
  if (!$coachee) return false;

  $coachee_id = (int) $coachee['id'];

  // Update profile with birth data
  $wpdb->update($t['profiles'], [
    'full_name'    => $chart_data['full_name'] ?? $coachee['full_name'],
    'dob'          => $chart_data['dob'] ?? '',
    'zodiac_sign'  => $chart_data['zodiac_sign'] ?? '',
    'coach_type'   => 'astro_coach',
    'updated_at'   => current_time('mysql'),
  ], ['id' => $coachee_id]);

  // Update/create astro record - USE user_id for cross-platform consistency
  $t_astro = $wpdb->prefix . 'bccm_astro';
  
  // Check by user_id first
  $existing = $wpdb->get_var($wpdb->prepare(
    "SELECT id FROM $t_astro WHERE user_id=%d AND chart_type='western'", $user_id
  ));
  // Fallback to coachee_id
  if (!$existing) {
    $existing = $wpdb->get_var($wpdb->prepare(
      "SELECT id FROM $t_astro WHERE coachee_id=%d AND chart_type='western'", $coachee_id
    ));
  }

  $astro_data = [
    'birth_place' => $chart_data['birth_place'] ?? '',
    'birth_time'  => $chart_data['birth_time'] ?? '',
    'latitude'    => floatval($chart_data['latitude'] ?? 0),
    'longitude'   => floatval($chart_data['longitude'] ?? 0),
    'timezone'    => floatval($chart_data['timezone'] ?? 7),
    'updated_at'  => current_time('mysql'),
  ];

  // Save chart result if available
  if (!empty($chart_data['chart_result'])) {
    if (function_exists('bccm_astro_save_chart')) {
      $birth_input = array_merge([
        'year'      => intval(date('Y', strtotime($chart_data['dob']))),
        'month'     => intval(date('m', strtotime($chart_data['dob']))),
        'day'       => intval(date('d', strtotime($chart_data['dob']))),
        'hour'      => intval(explode(':', $chart_data['birth_time'] ?? '12:00')[0]),
        'minute'    => intval(explode(':', $chart_data['birth_time'] ?? '12:00')[1] ?? 0),
        'latitude'  => $chart_data['latitude'],
        'longitude' => $chart_data['longitude'],
        'timezone'  => $chart_data['timezone'],
      ], [
        'birth_place' => $chart_data['birth_place'],
        'birth_time'  => $chart_data['birth_time'],
      ]);
      bccm_astro_save_chart($coachee_id, $chart_data['chart_result'], $birth_input, $user_id);
      return true; // bccm_astro_save_chart handles the astro record
    }
  }

  // Fallback: just save birth data - update by id instead of coachee_id
  if ($existing) {
    $astro_data['user_id'] = $user_id;
    $wpdb->update($t_astro, $astro_data, ['id' => $existing]);
  } else {
    $astro_data['coachee_id'] = $coachee_id;
    $astro_data['user_id']    = $user_id;
    $astro_data['chart_type'] = 'western';
    $astro_data['created_at'] = current_time('mysql');
    $wpdb->insert($t_astro, $astro_data);
  }

  return true;
}

/* =====================================================================
 * 4. HOOK: After WooCommerce Registration – Migrate Transient Data
 * =====================================================================*/
add_action('woocommerce_created_customer', 'bccm_migrate_guest_chart_on_register', 10, 3);
add_action('user_register', 'bccm_migrate_guest_chart_on_register_wp', 10, 1);

function bccm_migrate_guest_chart_on_register($customer_id, $new_customer_data, $password_generated) {
  bccm_do_migrate_guest_chart($customer_id);
}

function bccm_migrate_guest_chart_on_register_wp($user_id) {
  bccm_do_migrate_guest_chart($user_id);
}

function bccm_do_migrate_guest_chart($user_id) {
  // Check for token from POST (hidden field) or cookie
  $token = '';
  if (!empty($_POST['bccm_astro_token'])) {
    $token = sanitize_text_field($_POST['bccm_astro_token']);
  } elseif (!empty($_COOKIE['bccm_astro_token'])) {
    $token = sanitize_text_field($_COOKIE['bccm_astro_token']);
  }

  if (empty($token)) return;

  $transient_data = get_transient('bccm_guest_chart_' . $token);
  if (empty($transient_data)) return;

  // Get or create coachee profile
  if (function_exists('bccm_get_or_create_user_coachee')) {
    $coachee = bccm_get_or_create_user_coachee($user_id, 'WEBCHAT', 'astro_coach');
    if ($coachee && !empty($transient_data['chart_result'])) {
      $coachee_id = (int)$coachee['id'];
      
      // Build birth_input from transient data
      $dob = $transient_data['dob'] ?? '';
      $dob_parts = explode('-', $dob);
      $birth_time = $transient_data['birth_time'] ?? '12:00';
      $time_parts = explode(':', $birth_time);
      
      $birth_input = [
        'year'        => intval($dob_parts[0] ?? 2000),
        'month'       => intval($dob_parts[1] ?? 1),
        'day'         => intval($dob_parts[2] ?? 1),
        'hour'        => intval($time_parts[0] ?? 12),
        'minute'      => intval($time_parts[1] ?? 0),
        'latitude'    => floatval($transient_data['latitude'] ?? 21.0285),
        'longitude'   => floatval($transient_data['longitude'] ?? 105.8542),
        'timezone'    => floatval($transient_data['timezone'] ?? 7),
        'birth_place' => $transient_data['birth_place'] ?? '',
        'birth_time'  => $birth_time,
      ];
      
      // Save Western chart
      if (function_exists('bccm_astro_save_chart')) {
        bccm_astro_save_chart($coachee_id, $transient_data['chart_result'], $birth_input);
      }
      
      // Also update profile name
      global $wpdb;
      $t = bccm_tables();
      $wpdb->update($t['profiles'], [
        'full_name'   => sanitize_text_field($transient_data['full_name'] ?? ''),
        'dob'         => $dob,
        'zodiac_sign' => $transient_data['zodiac_sign'] ?? '',
        'updated_at'  => current_time('mysql'),
      ], ['id' => $coachee_id]);
      
      // Mark steps as done
      update_user_meta($user_id, 'bccm_onboarding_step1', 1);
      update_user_meta($user_id, 'bccm_onboarding_step2', 1);
      
      // Clean up transient
      delete_transient('bccm_guest_chart_' . $token);
      
      // Clean up cookie
      setcookie('bccm_astro_token', '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN);
      
      return;
    }
  }

  // Fallback: use old method
  $result = bccm_save_guest_chart_to_user($user_id, $transient_data);

  if ($result) {
    // Mark step 1 as done
    update_user_meta($user_id, 'bccm_onboarding_step1', 1);
    update_user_meta($user_id, 'bccm_onboarding_step2', 1); // Registration = step 2

    // Clean up transient
    delete_transient('bccm_guest_chart_' . $token);

    // Clean up cookie
    setcookie('bccm_astro_token', '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN);
  }
}

/* =====================================================================
 * 4b. GET USER ASTRO DATA FOR DISPLAY
 * =====================================================================*/
function bccm_get_user_astro_display_data($user_id = 0) {
  if (!$user_id) $user_id = get_current_user_id();
  if (!$user_id) return null;

  global $wpdb;
  $t = bccm_tables();

  // Get coachee profile
  $coachee = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$t['profiles']} WHERE user_id=%d ORDER BY id DESC LIMIT 1", $user_id
  ), ARRAY_A);
  if (!$coachee) return null;

  $coachee_id = (int) $coachee['id'];

  // Get astro record - USE user_id for cross-platform consistency
  $astro = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}bccm_astro WHERE user_id=%d AND chart_type='western' AND (summary IS NOT NULL OR traits IS NOT NULL) ORDER BY id DESC LIMIT 1", $user_id
  ), ARRAY_A);
  
  // Fallback to coachee_id
  if (!$astro) {
    $astro = $wpdb->get_row($wpdb->prepare(
      "SELECT * FROM {$wpdb->prefix}bccm_astro WHERE coachee_id=%d AND chart_type='western' LIMIT 1", $coachee_id
    ), ARRAY_A);
  }
  if (!$astro) return null;

  $zodiac_signs = function_exists('bccm_zodiac_signs') ? bccm_zodiac_signs() : [];
  $planet_names_vi = function_exists('bccm_planet_names_vi') ? bccm_planet_names_vi() : [];

  // Parse summary JSON (contains sun_sign, moon_sign, ascendant_sign, chart_url)
  $summary = json_decode($astro['summary'] ?? '{}', true);
  
  // Parse traits JSON (contains planets, houses, aspects)
  $traits = json_decode($astro['traits'] ?? '{}', true);
  
  // Get planets from traits (API raw data)
  $raw_planets = $traits['planets'] ?? [];
  $planets = [];
  
  if (!empty($raw_planets) && is_array($raw_planets)) {
    foreach ($raw_planets as $p) {
      // API returns nested structure: planet.en, zodiac_sign.number
      $name = $p['planet']['en'] ?? $p['name'] ?? '';
      $sign_num = intval($p['zodiac_sign']['number'] ?? $p['sign'] ?? 0);
      $sign_en = $zodiac_signs[$sign_num]['en'] ?? '';
      $sign_vi = $zodiac_signs[$sign_num]['vi'] ?? '';
      $sign_symbol = $zodiac_signs[$sign_num]['symbol'] ?? '';
      $norm_degree = floatval($p['normDegree'] ?? $p['norm_degree'] ?? 0);
      $full_degree = floatval($p['fullDegree'] ?? $p['full_degree'] ?? 0);
      $is_retro = ($p['isRetro'] ?? false) === true || strtolower($p['isRetro'] ?? '') === 'true';

      $degree = '';
      if ($norm_degree > 0) {
        $degree = round($norm_degree, 1) . '° ' . ($sign_vi ?: $sign_en);
      } elseif ($full_degree > 0) {
        $in_sign = fmod($full_degree, 30);
        $degree = round($in_sign, 1) . '° ' . ($sign_vi ?: $sign_en);
      }

      $planets[] = [
        'name'        => $name,
        'name_vi'     => $planet_names_vi[$name] ?? $name,
        'sign'        => $sign_en,
        'sign_vi'     => $sign_vi,
        'sign_symbol' => $sign_symbol,
        'degree'      => $degree,
        'is_retro'    => $is_retro,
      ];
    }
  }

  // Get sun/moon/asc from summary (these are sign names like "Pisces")
  $sun_sign = $summary['sun_sign'] ?? '';
  $moon_sign = $summary['moon_sign'] ?? '';
  $asc_sign = $summary['ascendant_sign'] ?? '';
  
  // Convert sign names to Vietnamese
  $sun_vi = '';
  $moon_vi = '';
  $asc_vi = '';
  foreach ($zodiac_signs as $zs) {
    if (!empty($sun_sign) && strcasecmp($zs['en'], $sun_sign) === 0) $sun_vi = $zs['vi'];
    if (!empty($moon_sign) && strcasecmp($zs['en'], $moon_sign) === 0) $moon_vi = $zs['vi'];
    if (!empty($asc_sign) && strcasecmp($zs['en'], $asc_sign) === 0) $asc_vi = $zs['vi'];
  }

  return [
    'full_name'   => $coachee['full_name'] ?? '',
    'dob'         => $coachee['dob'] ?? '',
    'birth_time'  => $astro['birth_time'] ?? '',
    'birth_place' => $astro['birth_place'] ?? '',
    'latitude'    => floatval($astro['latitude'] ?? 21.0285),
    'longitude'   => floatval($astro['longitude'] ?? 105.8542),
    'timezone'    => floatval($astro['timezone'] ?? 7),
    'zodiac_vi'   => $sun_vi,
    'sun_sign'    => $sun_sign,
    'moon_sign'   => $moon_sign,
    'asc_sign'    => $asc_sign,
    'chart_url'   => $astro['chart_svg'] ?? $summary['chart_url'] ?? '',
    'planets'     => $planets,
  ];
}

/* =====================================================================
 * 5. USER ONBOARDING PROGRESS
 * =====================================================================*/
function bccm_get_user_onboarding_progress($user_id = 0) {
  if (!$user_id) $user_id = get_current_user_id();
  if (!$user_id) return [];

  global $wpdb;

  // Step 1: Chart created (has astro data)
  $step1 = (bool) get_user_meta($user_id, 'bccm_onboarding_step1', true);
  if (!$step1 && function_exists('bccm_tables')) {
    $t = bccm_tables();
    $coachee = $wpdb->get_row($wpdb->prepare(
      "SELECT id FROM {$t['profiles']} WHERE user_id=%d ORDER BY id DESC LIMIT 1", $user_id
    ), ARRAY_A);
    // Check astro by user_id first for cross-platform consistency
    // (user_id column may not exist on older sites before migration 0.1.0.17)
    $astro_table = $wpdb->prefix . 'bccm_astro';
    $astro_cols  = $wpdb->get_col("SHOW COLUMNS FROM `{$astro_table}`", 0);
    $has_astro   = false;
    if (is_array($astro_cols) && in_array('user_id', $astro_cols, true)) {
      $has_astro = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$astro_table} WHERE user_id=%d AND (summary IS NOT NULL OR traits IS NOT NULL) LIMIT 1", $user_id
      ));
    }
    // Fallback to coachee_id
    if (!$has_astro && $coachee) {
      $has_astro = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}bccm_astro WHERE coachee_id=%d LIMIT 1", $coachee['id']
      ));
    }
    if ($has_astro) {
      $step1 = true;
      update_user_meta($user_id, 'bccm_onboarding_step1', 1);
    }
  }

  // Step 2: Registered (always true if logged in)
  $step2 = true;
  update_user_meta($user_id, 'bccm_onboarding_step2', 1);

  // Step 3: AI Agent created (has linked character)
  $step3 = (bool) get_user_meta($user_id, 'bccm_linked_character_id', true);

  // Step 4: Character assigned / site not being created
  $is_new_site = get_option('creating_new_site', false);
  $step4 = $step3 && !$is_new_site;

  // Determine current step
  $current_step = 1;
  if ($step1) $current_step = 2;
  if ($step2 && $step1) $current_step = 3;
  if ($step3) $current_step = 4;
  if ($step4) $current_step = 5; // All done

  $completed = ($step1 ? 1 : 0) + ($step2 ? 1 : 0) + ($step3 ? 1 : 0) + ($step4 ? 1 : 0);
  $percentage = round(($completed / 4) * 100);

  return [
    'step1'        => $step1,
    'step2'        => $step2,
    'step3'        => $step3,
    'step4'        => $step4,
    'current_step' => $current_step,
    'completed'    => $completed,
    'percentage'   => $percentage,
  ];
}

/* =====================================================================
 * 6. PAGE TEMPLATE REGISTRATION
 * =====================================================================*/
add_filter('theme_page_templates', function ($templates) {
  $templates['bccm-astro-landing'] = 'Trang Chiêm Tinh (BizCoach)';
  return $templates;
});

add_filter('template_include', function ($template) {
  if (is_page()) {
    $page_template = get_page_template_slug();
    if ($page_template === 'bccm-astro-landing') {
      $custom = BCCM_DIR . 'templates/page-astro-landing.php';
      if (file_exists($custom)) {
        return $custom;
      }
    }
  }
  return $template;
});
