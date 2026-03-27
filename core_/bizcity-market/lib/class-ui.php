<?php
if (!defined('ABSPATH')) exit;

class BizCity_Market_UI {
    public static function boot_admin_style() {
        add_action('admin_footer', function () {
            // reuse đúng “BC Admin Style” anh lưu (em giữ minimal ở skeleton; anh có thể paste full CSS chuẩn của anh vào đây)
            echo '<style id="bizcity-admin-ui">
                #wpbody{width:99%} #wpwrap{overflow-x:hidden}
                body.wp-admin{background:#f8fafc}
                .bc-card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;box-shadow:0 1px 2px rgba(0,0,0,.04);padding:16px}
                .bc-header{display:flex;align-items:center;justify-content:space-between;margin:10px 0 16px}
                .bc-title{font-size:20px;margin:0}
            </style>';
        }, 99);
    }
}
