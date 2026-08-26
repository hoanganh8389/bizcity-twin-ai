<?php
/**
 * Bizcity Twin AI — Custom Login Page
 * Trang đăng nhập tùy chỉnh kiểu AIQuill / AIQuill-style wp-login.php override
 *
 * Completely replaces default WordPress login appearance.
 * Light mode by default, matching colors & layout.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Module\Webchat
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BizCity_Login_Page {

    public static function boot() {
        // Guard: don't load twice
        if ( function_exists( 'bizcity_aiquill_login_css' ) ) {
            return;
        }

        add_filter( 'login_headerurl', [ __CLASS__, 'header_url' ] );
        add_filter( 'login_headertext', [ __CLASS__, 'header_text' ] );
        add_action( 'login_enqueue_scripts', [ __CLASS__, 'enqueue' ], 999 );
        add_action( 'login_footer', [ __CLASS__, 'footer_js' ], 5 );
    }

    public static function header_url() {
        return home_url( '/' );
    }

    public static function header_text() {
        return get_bloginfo( 'name' );
    }

    /* ─── CSS ─────────────────────────────────────────────── */
    public static function enqueue() {
        wp_dequeue_style( 'login' );
        wp_deregister_style( 'login' );
        wp_enqueue_style( 'dashicons' );

        $blog_name = esc_attr( get_bloginfo( 'name' ) );
        $logo_url  = '';
        $site_icon = get_site_icon_url( 48 );
        if ( $site_icon ) {
            $logo_url = $site_icon;
        } elseif ( has_custom_logo() ) {
            $logo_id  = get_theme_mod( 'custom_logo' );
            $logo_url = $logo_id ? wp_get_attachment_image_url( $logo_id, 'thumbnail' ) : '';
        }

        // Hero image for right panel (filterable)
        $hero_img = defined( 'BIZCITY_WEBCHAT_URL' )
            ? BIZCITY_WEBCHAT_URL . 'assets/img/sign-in-page-img.webp'
            : '';
        $hero_img = apply_filters( 'bizcity_login_hero_image', $hero_img );
        ?>
        <style id="aiquill-login-style">
        /* ════════ Reset ════════ */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root, body.login { color-scheme: light dark; }

        /* Screen-reader heading */
        .screen-reader-text,
        .screen-reader-text span {
            position: absolute !important;
            width: 1px !important; height: 1px !important;
            padding: 0 !important; margin: -1px !important;
            overflow: hidden !important;
            clip: rect(0,0,0,0) !important;
            white-space: nowrap !important; border: 0 !important;
        }

        /* ════════ TWO-COLUMN LAYOUT (AIQuill: flex justify-between) ════════ */
        body.login {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif !important;
            background: #fff !important;
            color: #434956;                         /* n500 */
            display: flex !important;
            flex-direction: row !important;
            justify-content: flex-start !important;
            align-items: stretch !important;
            min-height: 100dvh;
            overflow-x: hidden;
            padding: 0 !important; margin: 0 !important;
        }

        /* ════════ Gradient blobs — left side only ════════ */
        body.login::before {
            content: '';
            position: fixed; top: 0; left: 0; bottom: 0; width: 50%;
            z-index: 0; pointer-events: none;
            opacity: .25;
            background:
                radial-gradient(227px circle at -56px -56px, rgb(77,107,254) 0%, transparent 70%),
                radial-gradient(227px circle at 50% 110%, rgb(77,107,254) 0%, transparent 70%),
                radial-gradient(227px circle at 30% -100px, rgb(0,184,217) 0%, transparent 70%),
                radial-gradient(227px circle at 50% 200px, rgb(255,171,0) 0%, transparent 70%),
                radial-gradient(227px circle at -100px 400px, rgb(255,86,48) 0%, transparent 70%),
                radial-gradient(227px circle at -150px 110%, rgb(34,197,94) 0%, transparent 70%),
                radial-gradient(227px circle at 20% 110%, rgb(255,171,0) 0%, transparent 70%);
        }

        /* ════════ RIGHT PANEL — Hero image (AIQuill: w-1/2) ════════ */
        body.login::after {
            content: '';
            display: block;
            width: 50%;
            flex-shrink: 0;
            min-height: 100dvh;
            <?php if ( $hero_img ) : ?>
            background: url('<?php echo esc_url( $hero_img ); ?>') center/cover no-repeat;
            <?php else : ?>
            background: linear-gradient(135deg, #4D6BFE 0%, #00B8D9 100%);
            <?php endif; ?>
        }

        /* ════════ LEFT COLUMN — Form (AIQuill: flex-1 py-6 px-4 xl:px-20) ════════ */
        #login {
            position: relative; z-index: 1;
            flex: 1 1 0%;
            max-width: none !important;
            width: auto !important;
            padding: 32px 60px 32px 0 !important;
            margin: 0 0 0 auto !important;
            display: flex; flex-direction: column;
            min-height: 100dvh;
            overflow-y: auto;
            max-width: 550px !important;
        }

        /* ════════ Logo / Brand ════════ */
        #login h1 { margin-bottom: 0; }
        #login h1 a {
            display: inline-flex !important;
            align-items: center; gap: 6px;
            background: none !important;
            width: auto !important; height: auto !important;
            text-indent: 0 !important;
            font-size: 24px !important; font-weight: 600 !important;
            color: #262D3B !important;              /* n700 */
            text-decoration: none;
            margin: 0 !important; padding: 0 !important;
        }
        <?php if ( $logo_url ) : ?>
        #login h1 a::before {
            content: '';
            display: inline-block;
            width: 27px; height: 32px;
            background: url('<?php echo esc_url( $logo_url ); ?>') center/contain no-repeat;
            border-radius: 6px; flex-shrink: 0;
        }
        <?php endif; ?>

        /* ════════ Welcome text (JS-injected) ════════ */
        .aiquill-welcome { margin: 24px 0 0; }
        .aiquill-welcome h2 {
            font-size: 24px; font-weight: 700;
            color: #262D3B; margin: 0;              /* n700 */
        }
        .aiquill-welcome p {
            font-size: 14px; color: #434956;        /* n500 */
            margin: 4px 0 0;
        }

        /* ════════ Form container ════════ */
        #loginform, #registerform, #lostpasswordform {
            background: transparent !important;
            border: none !important;
            box-shadow: none !important;
            padding: 0 !important;
            margin: 20px 0 0 !important;
            max-width: 480px;
        }

        /* ════════ Labels ════════ */
        #loginform label,
        #registerform label,
        #lostpasswordform label {
            display: block;
            font-size: 14px; font-weight: 500;
            color: #434956;                         /* n500 */
            margin-bottom: 8px;
        }
        #loginform label[for="rememberme"],
        #loginform .forgetmenot label {
            display: inline-flex; align-items: center; gap: 6px;
            font-size: 13px; font-weight: 400; color: #525763;
        }

        /* ════════ Inputs (AIQuill FormInput) ════════ */
        #loginform input[type="text"],
        #loginform input[type="password"],
        #registerform input[type="text"],
        #registerform input[type="email"],
        #lostpasswordform input[type="text"] {
            width: 100% !important;
            border: 1px solid #EBECED !important;           /* n30 */
            border-radius: 999px !important;
            padding: 14px 24px !important;
            font-size: 14px !important;
            background: rgba(77,107,254,.05) !important;    /* primaryColor/5 */
            color: #262D3B !important;                      /* n700 */
            outline: none !important;
            box-shadow: none !important;
            transition: border-color .2s;
            margin-bottom: 8px !important;
        }
        #loginform input[type="text"]:focus,
        #loginform input[type="password"]:focus,
        #registerform input[type="text"]:focus,
        #registerform input[type="email"]:focus,
        #lostpasswordform input[type="text"]:focus {
            border-color: rgb(77,107,254) !important;
        }
        #loginform input::placeholder,
        #registerform input::placeholder,
        #lostpasswordform input::placeholder {
            color: #525763 !important;
        }

        /* ════════ Password wrapper ════════ */
        .wp-pwd { position: relative; }

        /* Hide the WP show/hide password button entirely —
           dashicons font fails to load reliably after login CSS dequeue,
           causing a giant broken icon. Browser native reveal is sufficient. */
        .wp-hide-pw {
            display: none !important;
        }

        /* Caps lock + password meter — hide ALL variants */
        .wp-login-caps-lock-warning,
        .login .caps-lock-warning,
        [class*="caps-lock"],
        #caps-warning,
        .caps-warning,
        .caps-icon,
        .caps-warning-text,
        .indicator-hint, #pass-strength-result, .pw-weak,
        .wp-pwd + .wp-login-caps-lock-warning,
        .user-pass-wrap .wp-login-caps-lock-warning {
            display: none !important;
        }

        /* ════════ Remember me ════════ */
        .forgetmenot { margin-top: 4px !important; }

        /* ════════ Submit button ════════ */
        #wp-submit,
        #loginform .button-primary,
        #registerform .button-primary,
        #lostpasswordform .button-primary {
            width: 100% !important; display: block !important;
            border: none !important; border-radius: 999px !important;
            padding: 14px 24px !important;
            font-size: 14px !important; font-weight: 500 !important;
            background: rgb(77,107,254) !important;
            color: #fff !important;
            cursor: pointer; text-shadow: none !important;
            box-shadow: none !important;
            transition: opacity .2s;
            margin-top: 16px !important;
        }
        #wp-submit:hover { opacity: .9; }

        .submit {
            display: flex; flex-direction: column-reverse;
            gap: 10px; margin-top: 8px !important;
        }

        /* ════════ SSO buttons (wposso) ════════ */
        .wposso-login-buttons {
            margin: 24px 0 0 !important;;
            max-width: 480px;
        }
        .wposso-login-buttons__row {
            display: flex; gap: 10px;
        }
        .wposso-login-buttons__row > a {
            flex: 1 1 0%;
            display: flex; align-items: center; justify-content: center;
            padding: 10px 12px; border-radius: 999px;
            font-size: 13px; font-weight: 500;
            text-decoration: none;
            transition: background .2s, box-shadow .2s;
        }
        .wposso-google-login__button {
            background: #fff; border: 1px solid #EBECED; color: #434956;
        }
        .wposso-google-login__button:hover {
            background: #f9fafb; box-shadow: 0 1px 3px rgba(0,0,0,.08); color: #434956;
        }
        .wposso-google-login__button svg { margin-right: 8px; flex-shrink: 0; }
        .wposso-bizcity-login__button {
            background: #1a5276; border: 1px solid #154360; color: #fff;
        }
        .wposso-bizcity-login__button:hover {
            background: #1f6694; color: #fff;
        }
        .wposso-bizcity-login__icon {
            display: inline-flex; align-items: center; justify-content: center;
            width: 20px; height: 20px; margin-right: 8px;
            background: #fff; color: #1a5276;
            border-radius: 4px; font-size: 13px; font-weight: 700;
        }

        /* SSO separator (AIQuill "Or Continue" line) */
        .wposso-google-login__separator {
            position: relative; text-align: center;
            margin: 20px 0 0;
        }
        .wposso-google-login__separator::before {
            content: ''; position: absolute;
            top: 50%; left: 0; right: 0;
            height: 1px; background: #EBECED;
        }
        .wposso-google-login__separator span {
            position: relative; z-index: 1;
            background: #fff; padding: 0 12px;
            color: #7B8088; font-size: 12px; text-transform: uppercase;
        }

        /* ════════ Messages / Errors ════════ */
        #login_error, .login .message, .login .success {
            border: none !important; border-radius: 12px !important;
            padding: 12px 16px !important; font-size: 13px;
            margin: 12px 0 !important; box-shadow: none !important;
            max-width: 480px;
        }
        #login_error {
            background: rgba(255,86,48,.08) !important;
            color: rgb(255,86,48) !important;
        }
        .login .message {
            background: rgba(77,107,254,.08) !important;
            color: rgb(77,107,254) !important;
        }
        .login .success {
            background: rgba(34,197,94,.08) !important;
            color: rgb(34,197,94) !important;
        }

        /* ════════ Nav links ════════ */
        #nav, #backtoblog {
            text-align: left;
            margin: 12px 0 0 !important; padding: 0 !important;
        }
        #nav a, #backtoblog a {
            color: rgb(77,107,254) !important;       /* primaryColor */
            font-weight: 600; font-size: 13px; text-decoration: none;
        }
        #nav a:hover, #backtoblog a:hover { text-decoration: underline; }

        /* ════════ Footer ════════ */
        .privacy-policy-page-link,
        .language-switcher { display: none !important; }

        .aiquill-footer {
            text-align: center; margin-top: 32px;
            padding: 24px 0 16px; width: 100%;
            font-size: 14px; font-weight: 500; color: #434956;
        }
        .aiquill-footer span { color: rgb(77,107,254); }

        .login #wposso-onetap { display: none !important; }
        #reg_passmail { font-size: 13px; color: #7B8088; margin-top: 8px; }

        /* ════════ Dark mode support ════════ */
        @media (prefers-color-scheme: dark) {
            :root, body.login { color-scheme: dark !important; }
            body.login { background: #111827 !important; color: #d1d5db; }
            body.login::before { opacity: .15; }
            body.login::after { filter: brightness(.85); }
            #login h1 a { color: #f3f4f6 !important; }
            .aiquill-welcome h2 { color: #e5e7eb; }
            .aiquill-welcome p { color: #9ca3af; }
            #loginform label, #registerform label, #lostpasswordform label { color: #d1d5db; }
            #loginform label[for="rememberme"],
            #loginform .forgetmenot label { color: #9ca3af; }
            #loginform input[type="text"],
            #loginform input[type="password"],
            #registerform input[type="text"],
            #registerform input[type="email"],
            #lostpasswordform input[type="text"] {
                background: rgba(77,107,254,.08) !important;
                border-color: #374151 !important;
                color: #f3f4f6 !important;
            }
            .wposso-google-login__button { background: #1f2937; border-color: #374151; color: #e5e7eb; }
            .wposso-google-login__button:hover { background: #253046; color: #e5e7eb; }
            .wposso-google-login__separator::before { background: #374151; }
            .wposso-google-login__separator span { background: #111827; color: #6b7280; }
            #login_error { background: rgba(255,86,48,.12) !important; color: #fca5a5 !important; }
            .login .message { background: rgba(77,107,254,.12) !important; color: #93c5fd !important; }
            .login .success { background: rgba(34,197,94,.12) !important; color: #86efac !important; }
            #nav a, #backtoblog a { color: rgb(77,107,254) !important; }
            .aiquill-footer { color: #9ca3af; }
            .aiquill-switch-link, .aiquill-forgot-link { color: #9ca3af !important; }
        }

        /* ════════ Responsive ════════ */
        /* Hide right panel on screens < 1200px (AIQuill: max-xxl:hidden) */
        @media (max-width: 1200px) {
            body.login::after { display: none !important; }
            body.login::before {
                width: 100%; /* blobs span full width */
            }
            #login {
                padding: 32px 24px !important;
                margin: 0 auto !important;
                align-items: center;
            }
            #loginform, #registerform, #lostpasswordform,
            .wposso-login-buttons,
            #login_error, .login .message, .login .success {
                max-width: 480px; width: 100%;
            }
            .aiquill-welcome { max-width: 480px; width: 100%; }
            #nav, #backtoblog { text-align: center; }
        }
        @media (max-width: 480px) {
            #login { padding: 24px 16px !important; }
            .wposso-login-buttons__row { flex-direction: column; }
        }
        </style>
        <?php
    }

    /* ─── FOOTER JS ───────────────────────────────────────── */
    public static function footer_js() {
        $blog_name = esc_html( get_bloginfo( 'name' ) );
        $year      = gmdate( 'Y' );
        ?>
        <script>
        (function(){
            var loginEl = document.getElementById('login');
            if (!loginEl) return;
            var h1 = loginEl.querySelector('h1');

            var isRegister = !!document.getElementById('registerform');
            var isLostPass = !!document.getElementById('lostpasswordform');

            /* ── De-dup: remove any previously-injected elements ── */
            loginEl.querySelectorAll('.aiquill-welcome').forEach(function(el){ el.remove(); });
            loginEl.querySelectorAll('.aiquill-footer').forEach(function(el){ el.remove(); });
            loginEl.querySelectorAll('.aiquill-switch-link').forEach(function(el){ el.remove(); });
            loginEl.querySelectorAll('.aiquill-forgot-link').forEach(function(el){ el.remove(); });

            /* ── Remove duplicate style blocks (keep only ours — the first one) ── */
            var allStyles = document.querySelectorAll('style#aiquill-login-style');
            for (var si = 1; si < allStyles.length; si++) { allStyles[si].remove(); }

            /* ── Welcome text ── */
            var welcome = document.createElement('div');
            welcome.className = 'aiquill-welcome';
            if (isRegister) {
                welcome.innerHTML = '<h2>Tạo tài khoản mới!</h2><p>Nhập thông tin để bắt đầu sử dụng</p>';
            } else if (isLostPass) {
                welcome.innerHTML = '<h2>Quên mật khẩu?</h2><p>Nhập email để nhận link đặt lại mật khẩu</p>';
            } else {
                welcome.innerHTML = '<h2>Chào mừng trở lại!</h2><p>Đăng nhập vào tài khoản của bạn</p>';
            }
            if (h1 && h1.nextSibling) {
                h1.parentNode.insertBefore(welcome, h1.nextSibling);
            } else if (h1) {
                h1.parentNode.appendChild(welcome);
            }

            /* ── Forget password? link (after password field, right-aligned) ── */
            var nav = document.getElementById('nav');
            var form = loginEl.querySelector('#loginform, #registerform');
            if (nav && !isRegister && !isLostPass) {
                var lostLink = nav.querySelector('a[href*="action=lostpassword"]');
                if (lostLink && form) {
                    var fp = document.createElement('a');
                    fp.href = lostLink.href;
                    fp.textContent = 'Forget password?';
                    fp.className = 'aiquill-forgot-link';
                    fp.style.cssText = 'display:block;text-align:right;padding-top:8px;color:rgb(77,107,254);font-size:14px;text-decoration:none;max-width:480px;';
                    // Insert after .user-pass-wrap or after form
                    var passWrap = form.querySelector('.user-pass-wrap');
                    if (passWrap && passWrap.nextSibling) {
                        passWrap.parentNode.insertBefore(fp, passWrap.nextSibling);
                    }
                }
            }

            /* ── Don't have an account? Sign Up ── */
            if (nav && !isLostPass) {
                var sw = document.createElement('p');
                sw.className = 'aiquill-switch-link';
                sw.style.cssText = 'font-size:14px;color:#434956;margin-top:16px;max-width:480px;';
                if (isRegister) {
                    var loginHref = (nav.querySelector('a') || {}).href || '';
                    loginHref = loginHref.replace('action=register','').replace(/[?&]$/,'');
                    sw.innerHTML = 'Đã có tài khoản? <a href="'+loginHref+'" style="color:rgb(255,86,48);font-weight:600;text-decoration:none;">Đăng nhập</a>';
                } else {
                    var regLink = nav.querySelector('a[href*="action=register"]');
                    if (regLink) {
                        sw.innerHTML = 'Chưa có tài khoản? <a href="'+regLink.href+'" style="color:rgb(255,86,48);font-weight:600;text-decoration:none;">Đăng ký</a>';
                    }
                }
                if (sw.innerHTML) {
                    // Place before submit button
                    var submitWrap = form ? form.querySelector('.submit') : null;
                    if (submitWrap) {
                        submitWrap.parentNode.insertBefore(sw, submitWrap);
                    } else if (nav.parentNode) {
                        nav.parentNode.insertBefore(sw, nav);
                    }
                }
            }

            /* ── Hide original nav (we replaced its links above) ── */
            if (nav) nav.style.display = 'none';

            /* ── Footer copyright (pushed to bottom via flex) ── */
            var footer = document.createElement('div');
            footer.className = 'aiquill-footer';
            footer.innerHTML = 'Copyright \u00a9<?php echo $year; ?> <span><?php echo $blog_name; ?></span>. All Rights Reserved';
            loginEl.appendChild(footer);

            /* ── Hide back to blog link ── */
            var backLink = document.getElementById('backtoblog');
            if (backLink) backLink.style.display = 'none';

            /* ── Move SSO buttons BEFORE the form (above "HOẶC" separator + fields) ── */
            var ssoDiv = loginEl.querySelector('.wposso-login-buttons')
                      || document.querySelector('.wposso-login-buttons');
            if (ssoDiv && form) {
                // Place before the form
                form.parentNode.insertBefore(ssoDiv, form);
                ssoDiv.style.maxWidth = '480px';
            }

            /* ── Remove caps-lock / caps-warning elements from DOM entirely ── */
            document.querySelectorAll('#caps-warning, .caps-warning, .wp-login-caps-lock-warning, [class*="caps-lock"]').forEach(function(el){
                el.remove();
            });
            /* Observe for dynamically injected caps-warning + late duplicate aiquill elements */
            new MutationObserver(function(muts, obs){
                muts.forEach(function(m){
                    m.addedNodes.forEach(function(n){
                        if (n.nodeType !== 1) return;
                        /* Caps-lock warnings */
                        if (n.id === 'caps-warning' || n.classList.contains('caps-warning')) { n.remove(); return; }
                        if (n.querySelector) {
                            n.querySelectorAll('#caps-warning, .caps-warning').forEach(function(c){ c.remove(); });
                        }
                        /* Late-injected duplicate welcome/footer from other login customizer scripts */
                        if (n.classList.contains('aiquill-welcome') || n.classList.contains('aiquill-footer') || n.classList.contains('aiquill-switch-link')) {
                            var siblings = loginEl.querySelectorAll('.' + n.className.split(' ')[0]);
                            if (siblings.length > 1) { n.remove(); }
                        }
                    });
                });
            }).observe(document.body, { childList: true, subtree: true });
        })();
        </script>
        <?php
    }
}
