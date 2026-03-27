/**!
 * Bizcity Twin AI — Personalized AI Companion Platform
 * AI Agent Home — Guest overlay + AJAX auth
 * (c) 2024-2026 BizCity by Johnny Chu (Chu Hoàng Anh) — Made in Vietnam 🇻🇳
 * @package    Bizcity_Twin_AI
 * @subpackage Module\Webchat\Assets
 * @license    GPL-2.0-or-later | https://bizcity.vn
 * @since      2.2.0
 */
(function($) {
    'use strict';

    if (window.bizcityAgentAuthInit) return;
    window.bizcityAgentAuthInit = true;

    var cfg      = window.bizcityAgentAuth || {};
    var $overlay = $('#aiagent-auth-overlay');

    /* ──────────── helpers ──────────── */

    function showAuth(tab) {
        clearNotices();
        $overlay.addClass('active');
        if (tab === 'register') {
            setTimeout(function() {
                $overlay.find('.bizcity-auth-tab[data-tab="register"]').trigger('click');
            }, 30);
        }
        $('body').css('overflow', 'hidden');
    }

    function closeAuth() {
        $overlay.removeClass('active');
        $('body').css('overflow', '');
    }

    /** Insert notice above the form inside the active panel */
    function showNotice(panel, msg, type) {
        clearNotices();
        var cls = type === 'success' ? 'woocommerce-message' : 'woocommerce-error';
        var $wrap = panel.closest('.aiagent-wc-dialog').find('.woocommerce-notices-wrapper');
        if (!$wrap.length) $wrap = panel;
        $wrap.prepend(
            '<div class="' + cls + '" style="margin:10px 18px;padding:10px 14px;border-radius:8px;font-size:14px;">' +
            msg + '</div>'
        );
    }

    function clearNotices() {
        $overlay.find('.woocommerce-error, .woocommerce-message, .aiagent-auth-notice').remove();
    }

    function setLoading($btn, loading) {
        if (loading) {
            $btn.data('orig-text', $btn.text()).prop('disabled', true).css('opacity', 0.6).text('Đang xử lý…');
        } else {
            $btn.prop('disabled', false).css('opacity', '').text($btn.data('orig-text') || $btn.val());
        }
    }

    /* ──────────── overlay open / close ──────────── */

    window.aiagentShowAuth = showAuth;

    $(function() {
        // Header buttons
        $('#aiagent-btn-login-header').on('click', function(e) { e.preventDefault(); showAuth('login'); });
        $('#aiagent-btn-register-header').on('click', function(e) { e.preventDefault(); showAuth('register'); });

        // Guest input bar → open login
        $('#aiagent-guest-input-bar, #aiagent-guest-send-btn').on('click', function(e) { e.preventDefault(); showAuth('login'); });

        // Close: backdrop + close button + ESC
        $overlay.on('click', function(e) { if (e.target === this) closeAuth(); });
        $(document).on('click', '#aiagent-auth-close-btn', function() { closeAuth(); });
        $(document).on('keydown', function(e) { if (e.key === 'Escape' && $overlay.hasClass('active')) closeAuth(); });

        /* ──────────── AJAX LOGIN ──────────── */
        $overlay.on('submit', 'form.woocommerce-form-login', function(e) {
            e.preventDefault();
            var $form = $(this);
            var $btn  = $form.find('button[name="login"]');
            var user  = $form.find('#username').val() || '';
            var pass  = $form.find('#password').val() || '';

            if (!user || !pass) {
                showNotice($form, 'Vui lòng nhập tên đăng nhập và mật khẩu.', 'error');
                return;
            }

            setLoading($btn, true);
            $.post(cfg.ajaxUrl, {
                action:   'bizcity_aiagent_login',
                _wpnonce: cfg.nonce,
                username: user,
                password: pass
            }).done(function(res) {
                if (res.success) {
                    showNotice($form, '✅ ' + (res.data.message || 'Đăng nhập thành công!'), 'success');
                    setTimeout(function() { window.location.reload(true); }, 600);
                } else {
                    showNotice($form, res.data.message || 'Đăng nhập thất bại.', 'error');
                    setLoading($btn, false);
                }
            }).fail(function() {
                showNotice($form, 'Lỗi kết nối, vui lòng thử lại.', 'error');
                setLoading($btn, false);
            });
        });

        /* ──────────── AJAX REGISTER ──────────── */
        $overlay.on('submit', 'form.woocommerce-form-register', function(e) {
            e.preventDefault();
            var $form = $(this);
            var $btn  = $form.find('button[name="register"]');
            var phone = ($form.find('#bizcity_phone').val() || '').replace(/\D/g, '');
            var email = $form.find('#reg_email').val() || '';
            var pass  = $form.find('#reg_password').val() || '';
            var pass2 = $form.find('#reg_password2').val() || '';

            // Normalize phone: strip leading +84 / 84
            if (/^84\d{9,}$/.test(phone)) phone = '0' + phone.substring(2);
            if (/^0\d{9,}$/.test(phone)) { /* ok */ } else {
                showNotice($form, 'Số điện thoại không hợp lệ (cần tối thiểu 10 số).', 'error');
                return;
            }

            if (pass && pass2 && pass !== pass2) {
                showNotice($form, 'Mật khẩu xác nhận không khớp.', 'error');
                return;
            }
            if (!pass) {
                showNotice($form, 'Vui lòng nhập mật khẩu.', 'error');
                return;
            }

            setLoading($btn, true);
            $.post(cfg.ajaxUrl, {
                action:   'bizcity_aiagent_register',
                _wpnonce: cfg.nonce,
                phone:    phone,
                email:    email,
                password: pass
            }).done(function(res) {
                if (res.success) {
                    showNotice($form, '✅ ' + (res.data.message || 'Đăng ký thành công!'), 'success');
                    setTimeout(function() { window.location.reload(true); }, 800);
                } else {
                    showNotice($form, res.data.message || 'Đăng ký thất bại.', 'error');
                    setLoading($btn, false);
                }
            }).fail(function() {
                showNotice($form, 'Lỗi kết nối, vui lòng thử lại.', 'error');
                setLoading($btn, false);
            });
        });
    });

})(jQuery);
