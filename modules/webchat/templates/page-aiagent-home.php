<?php
/**
 * Bizcity Twin AI — AI Agent Home Frontend Template
 * Trang chủ AI Agent / AI Agent Home Page
 *
 * Template Name: Trang chủ AI Agent (BizCity) / AI Agent Home (BizCity)
 *
 * Full-page chat interface reusing the Admin Dashboard chat UI.
 * Logged-in users get the full dashboard. Guests see welcome + login.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Module\Webchat
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @since      2.0.0
 */
if (!defined('ABSPATH')) exit;

/* =====================================================================
 * 1. GATHER DATA
 * =====================================================================*/
$is_logged_in = is_user_logged_in();
$blog_name    = get_bloginfo('name');

// Character data (for page title)
$character    = null;
$character_id = 0;
if (class_exists('BizCity_Knowledge_Database')) {
    $db = BizCity_Knowledge_Database::instance();
    $character_id = intval(get_option('bizcity_webchat_default_character_id', 0));
    if (empty($character_id)) {
        $bot_setup = get_option('pmfacebook_options', []);
        $character_id = isset($bot_setup['default_character_id']) ? intval($bot_setup['default_character_id']) : 0;
    }
    $character = $character_id ? $db->get_character($character_id) : null;
    if (!$character) {
        $characters = $db->get_characters(['status' => 'active', 'limit' => 1]);
        if (!empty($characters)) {
            $character    = $characters[0];
            $character_id = $character->id;
        }
    }
}
$char_name = $character ? $character->name : ($blog_name ?: 'AI Assistant');

/* =====================================================================
 * 2. ASSETS — Always use React dashboard
 * =====================================================================*/

// Enqueue React dashboard assets BEFORE wp_head() so they appear in <head>
BizCity_WebChat_Admin_Dashboard::instance()->do_enqueue_react_assets();

// Force type="module" — replace any existing type attribute or add it
add_filter('script_loader_tag', function ($tag, $handle) {
    if ($handle === 'bizcity-dashboard-react') {
        // Remove any existing type attribute first (type='text/javascript' etc.)
        $tag = preg_replace('/\s+type\s*=\s*["\'][^"\']*["\']/', '', $tag);
        // Add type="module"
        $tag = str_replace('<script ', '<script type="module" ', $tag);
    }
    return $tag;
}, 99999, 2);

// ── Remove ALL non-dashboard styles/scripts — whitelist approach ──
add_action('wp_enqueue_scripts', function () {
	global $wp_styles, $wp_scripts;

	$keep_styles  = [ 'bizcity-dashboard-react' ];
	$keep_scripts = [ 'jquery', 'jquery-core', 'jquery-migrate', 'bizcity-dashboard-react' ];

	if ( $wp_styles instanceof WP_Styles ) {
		foreach ( $wp_styles->registered as $handle => $obj ) {
			if ( ! in_array( $handle, $keep_styles, true ) ) {
				wp_dequeue_style( $handle );
				wp_deregister_style( $handle );
			}
		}
	}
	if ( $wp_scripts instanceof WP_Scripts ) {
		foreach ( $wp_scripts->registered as $handle => $obj ) {
			if ( ! in_array( $handle, $keep_scripts, true ) ) {
				wp_dequeue_script( $handle );
				wp_deregister_script( $handle );
			}
		}
	}

	remove_action( 'wp_head', 'wp_custom_css_cb', 101 );
	remove_action( 'wp_enqueue_scripts', 'wp_enqueue_global_styles' );
	remove_action( 'wp_footer', 'wp_enqueue_global_styles', 1 );
}, 999);
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo esc_html($char_name); ?> — <?php echo esc_html($blog_name); ?></title>
    <?php
    ob_start();
    wp_head();
    $head_html = ob_get_clean();
    $head_html = preg_replace_callback(
        '/<style\b[^>]*>(.*?)<\/style>/si',
        function( $m ) {
            if ( stripos( $m[0], 'bizcity-dashboard' ) !== false ) return $m[0];
            if ( stripos( $m[0], 'bizc-' ) !== false ) return $m[0];
            if ( stripos( $m[1], '.bizc-' ) !== false ) return $m[0];
            if ( stripos( $m[1], '#root' ) !== false ) return $m[0];
            if ( stripos( $m[1], '.aiagent-' ) !== false ) return $m[0];
            return '';
        },
        $head_html
    );
    echo $head_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    ?>
    <style>
    /* Hide ALL theme elements that leak through wp_head() hooks */
    body.aiagent-home-body > *:not(#root):not(#aiagent-auth-overlay):not(script):not(style):not(link):not(noscript) {
        display: none !important;
    }
    #bizchat-float-btn,
    #button-contact-vr,
    .bizcity-float-widget,
    #bizcity-float-widget,
    #footer,
    .footer,
    .footer-wrapper,
    #footer-wrapper,
    .absolute-footer,
    #absolute-footer {
        display: none !important;
    }
    /* Auth overlay (guest login dialog) */
    .aiagent-auth-overlay {
        display: none;
        position: fixed;
        inset: 0;
        z-index: 99999;
        background: rgba(0,0,0,0.5);
        align-items: center;
        justify-content: center;
    }
    .aiagent-auth-overlay.active {
        display: flex;
    }
    .aiagent-wc-dialog {
        background: #fff;
        border-radius: 16px;
        padding: 32px 28px;
        max-width: 420px;
        width: 90%;
        position: relative;
        max-height: 90vh;
        overflow-y: auto;
        box-shadow: 0 25px 50px rgba(0,0,0,0.25);
    }
    .aiagent-auth-close {
        position: absolute;
        top: 12px;
        right: 16px;
        background: none;
        border: none;
        font-size: 24px;
        cursor: pointer;
        color: #6b7280;
        line-height: 1;
    }
    </style>
</head>
<body class="aiagent-home-body">

<!-- React Dashboard -->
<?php BizCity_WebChat_Admin_Dashboard::instance()->render_dashboard_react(); ?>

<?php if (!$is_logged_in): ?>
<!-- WC Login Dialog (shown when guest message limit reached) -->
<div class="aiagent-auth-overlay" id="aiagent-auth-overlay">
    <div class="aiagent-wc-dialog">
        <button class="aiagent-auth-close" id="aiagent-auth-close-btn" type="button" aria-label="Đóng">&times;</button>
        <?php
        if ( function_exists( 'wc_get_template' ) ) {
            wc_get_template( 'myaccount/form-login.php' );
        } elseif ( function_exists( 'wc_get_page_permalink' ) ) {
            echo '<p style="text-align:center;padding:24px;"><a href="' . esc_url( wc_get_page_permalink( 'myaccount' ) ) . '" class="button">Đăng nhập / Đăng ký</a></p>';
        }
        ?>
    </div>
</div>
<script>
/* Global function React can call to show login dialog */
window.bizcShowAuthOverlay = function() {
    var el = document.getElementById('aiagent-auth-overlay');
    if (el) el.classList.add('active');
};
(function(){
    var overlay = document.getElementById('aiagent-auth-overlay');
    var closeBtn = document.getElementById('aiagent-auth-close-btn');
    if (!overlay) return;
    if (closeBtn) closeBtn.addEventListener('click', function(){ overlay.classList.remove('active'); });
    overlay.addEventListener('click', function(e){ if (e.target === overlay) overlay.classList.remove('active'); });
})();
</script>
<?php endif; ?>

<?php
ob_start();
wp_footer();
$footer_html = ob_get_clean();
$footer_html = preg_replace_callback(
    '/<style\b[^>]*>(.*?)<\/style>/si',
    function( $m ) {
        if ( stripos( $m[0], 'bizcity-dashboard' ) !== false ) return $m[0];
        if ( stripos( $m[0], 'bizc-' ) !== false ) return $m[0];
        if ( stripos( $m[1], '.bizc-' ) !== false ) return $m[0];
        if ( stripos( $m[1], '.aiagent-' ) !== false ) return $m[0];
        return '';
    },
    $footer_html
);
echo $footer_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
?>
</body>
</html>
