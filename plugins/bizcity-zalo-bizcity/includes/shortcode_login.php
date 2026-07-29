<?php

add_shortcode('zalo_login_form', function() {
    ob_start();
    $zid = $_GET['zid'] ?? '';
    $client_id = twf_decrypt_chat_id($zid, 'vietqr');
    if (!$client_id) return 'Link không hợp lệ hoặc đã hết hạn.';

    $blog_id = get_current_blog_id();
    $domain = get_home_url();
    
    // Detect WebView (embedded browsers in apps like Zalo, Facebook, Instagram...)
    // Google blocks OAuth from WebViews for security reasons
    

    // Store zid in transient for recovery after OAuth redirect
    // Key based on IP+UA to identify the same browser session
    $transient_key = 'zalo_login_zid_' . md5( $_SERVER['REMOTE_ADDR'] . $user_agent );
    if ( $zid ) {
        set_transient( $transient_key, $zid, 600 ); // 10 minutes
    }

    // Helper function để lưu/update global_user_admin
    $save_to_global_user_admin = function($user_id, $blog_id, $client_id, $domain) {
        global $globaldb;
        if (!isset($globaldb) || !$globaldb) {
            return false;
        }

        // Check if record already exists
        $existing = $globaldb->get_row($globaldb->prepare(
            "SELECT id FROM global_user_admin WHERE blog_id = %d AND client_id = %s",
            $blog_id, $client_id
        ));

        if ($existing) {
            // Update existing record
            $globaldb->update(
                'global_user_admin',
                [
                    'user_id' => $user_id,
                    'user_slave_id' => $user_id,
                    'domain' => $domain,
                    'updated_at' => current_time('mysql')
                ],
                ['id' => $existing->id]
            );
        } else {
            // Insert new record
            $globaldb->insert('global_user_admin', [
                'blog_id' => $blog_id,
                'client_id' => $client_id,
                'user_id' => $user_id,
                'user_slave_id' => $user_id,
                'domain' => $domain,
                'user_level' => 'administrator',
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ]);
        }

        // Also update user meta
        update_user_meta($user_id, 'zalo_client_id_' . $blog_id, $client_id);
        
        return true;
    };

    // CASE 1: User đã đăng nhập (sau Google SSO redirect)
    if (is_user_logged_in()) {
        $user_id = get_current_user_id();
        $saved = $save_to_global_user_admin($user_id, $blog_id, $client_id, $domain);
        
        // Clean up transient
        delete_transient( $transient_key );
        
        if ($saved) {
            echo "<div class='success' style='background:#d4edda;border:1px solid #c3e6cb;color:#155724;padding:15px;border-radius:8px;text-align:center;margin:20px 0;'>";
            echo "<strong>✅ Đã liên kết tài khoản thành công!</strong><br>";
            echo "Bạn có thể quay lại Zalo để tiếp tục sử dụng.";
            echo "</div>";
        } else {
            echo "<div class='success' style='background:#d4edda;border:1px solid #c3e6cb;color:#155724;padding:15px;border-radius:8px;text-align:center;margin:20px 0;'>";
            echo "<strong>✅ Đã đăng nhập thành công!</strong><br>";
            echo "Bạn có thể quay lại Zalo để tiếp tục sử dụng.";
            echo "</div>";
        }
        return ob_get_clean();
    }

    // CASE 2: Form đăng nhập truyền thống
    if (isset($_POST['user_login'])) {
        $user = wp_signon([
            'user_login' => $_POST['user_login'],
            'user_password' => $_POST['user_pass'],
            'remember' => true
        ], is_ssl());

        if (!is_wp_error($user)) {
            $user_id = $user->ID;
            $save_to_global_user_admin($user_id, $blog_id, $client_id, $domain);
            
            // Clean up transient
            delete_transient( $transient_key );

            echo "<div class='success' style='background:#d4edda;border:1px solid #c3e6cb;color:#155724;padding:15px;border-radius:8px;text-align:center;margin:20px 0;'>";
            echo "<strong>✅ Đã liên kết tài khoản Zalo thành công!</strong><br>";
            echo "Bạn có thể quay lại Zalo.";
            echo "</div>";
        } else {
            echo '<div class="error" style="background:#f8d7da;border:1px solid #f5c6cb;color:#721c24;padding:15px;border-radius:8px;text-align:center;margin:20px 0;">Đăng nhập thất bại: '.$user->get_error_message().'</div>';
        }
        return ob_get_clean();
    }

    // CASE 3: Hiển thị form đăng nhập (chưa đăng nhập)
    $current_url = (is_ssl() ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    $sso_url = site_url('?auth=sso&redirect_to=' . urlencode($current_url));
    ?>
    <div style="max-width:400px;margin:20px auto;">
        
        <?php if ($is_webview): ?>
        <!-- WebView detected - show warning for Google login -->
        <div style="background:#fff3cd;border:1px solid #ffc107;color:#856404;padding:15px;border-radius:8px;margin-bottom:20px;">
            <strong>⚠️ Đăng nhập Google</strong><br>
            <span style="font-size:13px;">Để đăng nhập bằng Google, vui lòng mở link này trong trình duyệt Safari/Chrome:</span>
            <div style="margin-top:10px;">
                <button onclick="copyToClipboard()" style="width:100%;padding:10px;background:#0066cc;color:#fff;border:none;border-radius:5px;cursor:pointer;font-size:14px;">
                    📋 Sao chép link
                </button>
            </div>
            <div id="copy-success" style="display:none;color:#155724;font-size:12px;margin-top:5px;text-align:center;">✓ Đã sao chép! Mở trình duyệt và dán link.</div>
        </div>
        <script>
        function copyToClipboard() {
            var url = '<?php echo esc_js($current_url); ?>';
            if (navigator.clipboard) {
                navigator.clipboard.writeText(url).then(function() {
                    document.getElementById('copy-success').style.display = 'block';
                });
            } else {
                var input = document.createElement('input');
                input.value = url;
                document.body.appendChild(input);
                input.select();
                document.execCommand('copy');
                document.body.removeChild(input);
                document.getElementById('copy-success').style.display = 'block';
            }
        }
        </script>
        <?php else: ?>
        <!-- Normal browser - show Google login button -->
        <div style="text-align:center;margin-bottom:25px;">
            <a href="<?php echo esc_url($sso_url); ?>" style="display:inline-flex;align-items:center;justify-content:center;gap:10px;width:100%;padding:12px 20px;background:#fff;border:1px solid #ddd;border-radius:8px;text-decoration:none;color:#333;font-size:15px;box-shadow:0 1px 3px rgba(0,0,0,0.1);transition:all 0.2s;">
                <?php if (function_exists('wposso_google_icon_svg')) echo wposso_google_icon_svg(22); ?>
                <strong>Đăng nhập bằng Google</strong>
            </a>
        </div>
        <?php endif; ?>
        
        <div style="text-align:center;margin:20px 0;color:#888;font-size:13px;">
            ─────── hoặc đăng nhập bằng tài khoản ───────
        </div>
        
        <form method="post">
            <p><label style="display:block;margin-bottom:5px;font-weight:500;">Tên đăng nhập:</label>
            <input type="text" name="user_login" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:5px;"></p>
            <p><label style="display:block;margin-bottom:5px;font-weight:500;">Mật khẩu:</label>
            <input type="password" name="user_pass" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:5px;"></p>
            <p><button type="submit" style="width:100%;padding:12px;background:#0066cc;color:#fff;border:none;border-radius:5px;cursor:pointer;font-size:15px;font-weight:500;">Liên kết với Zalo</button></p>
        </form>
        
    </div>
    
    <style>
    .zalo-login-google-btn:hover {
        background: #f8f9fa !important;
        border-color: #4285f4 !important;
    }
    </style>
    <?php

    return ob_get_clean();
});