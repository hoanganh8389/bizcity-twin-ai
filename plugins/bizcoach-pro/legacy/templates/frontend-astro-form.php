<?php
/**
 * Template: Frontend Astro Form
 *
 * Form khai báo ngày/giờ/nơi sinh cho bản đồ chiêm tinh
 * Loaded via shortcode [bccm_astro_form]
 *
 * @var array $atts  Shortcode attributes
 * @package BizCoach_Map
 */
if (!defined('ABSPATH')) exit;

$ajax_url    = admin_url('admin-ajax.php');
$nonce       = wp_create_nonce('bccm_astro_form');
$redirect_url = $atts['redirect'] ?? '';
$show_register = ($atts['show_register'] ?? 'yes') === 'yes';
$is_logged_in  = is_user_logged_in();

// WooCommerce register URL
$register_url = '';
if (function_exists('wc_get_page_permalink')) {
    $register_url = wc_get_page_permalink('myaccount');
} else {
    $register_url = wp_registration_url();
}
?>

<div class="bccm-astro-form-wrap" id="bccm-astro-form-wrap">

    <!-- HERO -->
    <div class="bccm-astro-form-hero">
        <h1>🌟 Bản Đồ Chiêm Tinh Cuộc Đời</h1>
        <p>Khám phá tiềm năng ẩn giấu từ các vì sao. Nhập thông tin sinh để tạo bản đồ sao cá nhân của bạn.</p>
    </div>

    <!-- FORM -->
    <form id="bccm-astro-form" class="bccm-astro-form" novalidate>
        <input type="hidden" name="action" value="bccm_astro_generate" />
        <input type="hidden" name="_nonce" value="<?php echo esc_attr($nonce); ?>" />

        <!-- Row 1: Name + Gender -->
        <div class="bccm-form-row">
            <div class="bccm-form-group bccm-col-6">
                <label for="bccm_natal_name">Họ tên <span class="required">*</span></label>
                <input type="text" id="bccm_natal_name" name="natal_name" placeholder="Nhập họ tên" required maxlength="60" />
            </div>
            <div class="bccm-form-group bccm-col-6">
                <label>Giới tính</label>
                <div class="bccm-radio-group">
                    <label class="bccm-radio">
                        <input type="radio" name="natal_gender" value="Nam" checked />
                        <span>Nam</span>
                    </label>
                    <label class="bccm-radio">
                        <input type="radio" name="natal_gender" value="Nữ" />
                        <span>Nữ</span>
                    </label>
                </div>
            </div>
        </div>

        <!-- Row 2: Date of Birth -->
        <div class="bccm-form-row">
            <div class="bccm-form-group bccm-col-3">
                <label for="bccm_natal_day">Ngày <span class="required">*</span></label>
                <select id="bccm_natal_day" name="natal_day" required>
                    <option value="">Ngày</option>
                    <?php for ($i = 1; $i <= 31; $i++): ?>
                    <option value="<?php echo $i; ?>"><?php echo sprintf('%02d', $i); ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="bccm-form-group bccm-col-3">
                <label for="bccm_natal_month">Tháng <span class="required">*</span></label>
                <select id="bccm_natal_month" name="natal_month" required>
                    <option value="">Tháng</option>
                    <?php for ($i = 1; $i <= 12; $i++): ?>
                    <option value="<?php echo $i; ?>"><?php echo sprintf('%02d', $i); ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="bccm-form-group bccm-col-3">
                <label for="bccm_natal_year">Năm <span class="required">*</span></label>
                <select id="bccm_natal_year" name="natal_year" required>
                    <option value="">Năm</option>
                    <?php for ($i = intval(date('Y')); $i >= 1920; $i--): ?>
                    <option value="<?php echo $i; ?>"><?php echo $i; ?></option>
                    <?php endfor; ?>
                </select>
            </div>
        </div>

        <!-- Row 3: Time of Birth -->
        <div class="bccm-form-row">
            <div class="bccm-form-group bccm-col-3">
                <label for="bccm_natal_hour">Giờ sinh <span class="required">*</span></label>
                <select id="bccm_natal_hour" name="natal_hour" required>
                    <option value="">Giờ</option>
                    <?php for ($i = 1; $i <= 12; $i++): ?>
                    <option value="<?php echo $i; ?>"><?php echo sprintf('%02d', $i); ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="bccm-form-group bccm-col-3">
                <label for="bccm_natal_minute">Phút</label>
                <select id="bccm_natal_minute" name="natal_minute">
                    <option value="">Phút</option>
                    <?php for ($i = 0; $i <= 59; $i++): ?>
                    <option value="<?php echo $i; ?>"><?php echo sprintf('%02d', $i); ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="bccm-form-group bccm-col-3">
                <label for="bccm_amorpm">Buổi</label>
                <select id="bccm_amorpm" name="amorpm">
                    <option value="">Buổi</option>
                    <option value="AM">AM (sáng)</option>
                    <option value="PM">PM (chiều)</option>
                </select>
            </div>
            <div class="bccm-form-group bccm-col-3">
                <label for="bccm_timezone">Múi giờ</label>
                <select id="bccm_timezone" name="timezone">
                    <?php
                    $tzs = [
                        '11'  => 'GMT -11', '10' => 'GMT -10', '9' => 'GMT -9',
                        '8'   => 'GMT -8',  '7'  => 'GMT -7',  '6' => 'GMT -6',
                        '5'   => 'GMT -5',  '4'  => 'GMT -4',  '3' => 'GMT -3',
                        '2'   => 'GMT -2',  '1'  => 'GMT -1',  '0' => 'GMT +0',
                        '-1'  => 'GMT +1',  '-2' => 'GMT +2',  '-3' => 'GMT +3',
                        '-4'  => 'GMT +4',  '-5' => 'GMT +5',  '-6' => 'GMT +6',
                        '-7'  => 'GMT +7',  '-8' => 'GMT +8',  '-9' => 'GMT +9',
                        '-10' => 'GMT +10', '-11'=> 'GMT +11', '-12'=> 'GMT +12',
                        '-13' => 'GMT +13', '-14'=> 'GMT +14',
                    ];
                    foreach ($tzs as $val => $label):
                        $sel = ($val === '-7') ? ' selected' : '';
                    ?>
                    <option value="<?php echo esc_attr($val); ?>"<?php echo $sel; ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <!-- Row 4: Birth Place -->
        <div class="bccm-form-row">
            <div class="bccm-form-group bccm-col-12">
                <label for="bccm_address">Nơi sinh <span class="required">*</span></label>
                <div class="bccm-address-wrap">
                    <input type="text" id="bccm_address" name="address" placeholder="Nhập thành phố, quốc gia..." required />
                    <button type="button" id="bccm-geo-search" class="bccm-btn-geo">🔍 Tìm kiếm</button>
                </div>
                <input type="hidden" id="bccm_lat" name="lat" value="" />
                <input type="hidden" id="bccm_lon" name="lon" value="" />
                <div id="bccm-geo-results" class="bccm-geo-results" style="display:none;"></div>
                <div id="bccm-geo-status" class="bccm-geo-status"></div>
            </div>
        </div>

        <!-- Submit -->
        <div class="bccm-form-actions">
            <button type="submit" id="bccm-submit-btn" class="bccm-btn-submit">
                <span class="bccm-btn-text">🌟 Tạo Bản Đồ Sao</span>
                <span class="bccm-btn-loading" style="display:none;">⏳ Đang tạo bản đồ...</span>
            </button>
        </div>
    </form>

    <!-- RESULT AREA -->
    <div id="bccm-astro-result-area" style="display:none;"></div>

    <!-- REGISTER CTA (shown after result, if not logged in) -->
    <?php if ($show_register && !$is_logged_in): ?>
    <div id="bccm-astro-register-cta" class="bccm-register-cta" style="display:none;">
        <div class="bccm-cta-box">
            <h3>🚀 Bước tiếp theo: Đăng ký tài khoản</h3>
            <p>Đăng ký để lưu bản đồ chiêm tinh, nhận tư vấn AI cá nhân hóa và tạo website riêng.</p>
            <a href="<?php echo esc_url($register_url); ?>" class="bccm-btn-register" id="bccm-go-register">
                📱 Đăng ký bằng số điện thoại
            </a>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
jQuery(function($){
    'use strict';

    var ajaxUrl = '<?php echo esc_js($ajax_url); ?>';
    var $form = $('#bccm-astro-form');
    var $resultArea = $('#bccm-astro-result-area');
    var $registerCta = $('#bccm-astro-register-cta');
    var $submitBtn = $('#bccm-submit-btn');

    // ===== GEO LOOKUP =====
    $('#bccm-geo-search').on('click', function(){
        var address = $('#bccm_address').val().trim();
        if (!address) {
            $('#bccm-geo-status').html('<span style="color:red">Vui lòng nhập nơi sinh.</span>');
            return;
        }

        $('#bccm-geo-status').html('<span style="color:#3b82f6">🔍 Đang tìm kiếm...</span>');

        $.post(ajaxUrl, {
            action: 'bccm_geo_lookup',
            address: address
        }, function(res){
            if (res.success && res.data.places && res.data.places.length > 0) {
                var html = '<ul class="bccm-geo-list">';
                res.data.places.forEach(function(p){
                    html += '<li class="bccm-geo-item" data-lat="' + p.lat + '" data-lon="' + p.lon + '">';
                    html += '<span>' + escHtml(p.display_name) + '</span>';
                    html += '</li>';
                });
                html += '</ul>';
                $('#bccm-geo-results').html(html).show();
                $('#bccm-geo-status').html('<span style="color:#22c55e">✓ Chọn địa điểm bên dưới</span>');
            } else {
                $('#bccm-geo-status').html('<span style="color:red">Không tìm thấy. Thử nhập khác.</span>');
                $('#bccm-geo-results').hide();
            }
        }).fail(function(){
            $('#bccm-geo-status').html('<span style="color:red">Lỗi kết nối. Thử lại.</span>');
        });
    });

    // Select geo result
    $(document).on('click', '.bccm-geo-item', function(){
        var lat = $(this).data('lat');
        var lon = $(this).data('lon');
        var name = $(this).text().trim();

        $('#bccm_lat').val(lat);
        $('#bccm_lon').val(lon);
        $('#bccm_address').val(name);
        $('#bccm-geo-results').hide();
        $('#bccm-geo-status').html('<span style="color:#22c55e">✓ Đã chọn: ' + escHtml(name) + '</span>');
    });

    // Enter key on address
    $('#bccm_address').on('keypress', function(e){
        if (e.which === 13) {
            e.preventDefault();
            $('#bccm-geo-search').trigger('click');
        }
    });

    // ===== FORM SUBMIT =====
    $form.on('submit', function(e){
        e.preventDefault();

        // Validate
        var name = $('#bccm_natal_name').val().trim();
        var day = $('#bccm_natal_day').val();
        var month = $('#bccm_natal_month').val();
        var year = $('#bccm_natal_year').val();
        var hour = $('#bccm_natal_hour').val();
        var lat = $('#bccm_lat').val();

        if (!name || !day || !month || !year) {
            alert('Vui lòng nhập đầy đủ họ tên và ngày tháng năm sinh.');
            return;
        }
        if (!hour) {
            alert('Vui lòng chọn giờ sinh.');
            return;
        }
        if (!lat) {
            alert('Vui lòng tìm và chọn nơi sinh.');
            return;
        }

        // UI: loading
        $submitBtn.prop('disabled', true);
        $submitBtn.find('.bccm-btn-text').hide();
        $submitBtn.find('.bccm-btn-loading').show();

        $.ajax({
            url: ajaxUrl,
            method: 'POST',
            data: $form.serialize(),
            timeout: 90000
        }).done(function(res){
            if (res.success) {
                // Show result
                $resultArea.html(res.data.html).slideDown(400);
                $registerCta.slideDown(400);

                // Scroll to result
                $('html,body').animate({
                    scrollTop: $resultArea.offset().top - 60
                }, 600);

                // Hide form (optional – keep for re-generation)
                // $form.slideUp(300);
            } else {
                alert(res.data.message || 'Đã xảy ra lỗi khi tạo bản đồ chiêm tinh.');
            }
        }).fail(function(xhr){
            alert('Lỗi kết nối. Vui lòng thử lại sau.');
            console.error('BCCM Astro AJAX error:', xhr);
        }).always(function(){
            $submitBtn.prop('disabled', false);
            $submitBtn.find('.bccm-btn-text').show();
            $submitBtn.find('.bccm-btn-loading').hide();
        });
    });

    function escHtml(s) {
        return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
});
</script>
