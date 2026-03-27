<?php
/**
 * BizCity Companion Notebook — Frontend /note/ page template
 *
 * Template Name: Notebook SPA (BizCity)
 *
 * Full-page Notebook SPA for public-facing /note/ URL.
 * Logged-in users → full notebook SPA.
 * Guests → redirect to login.
 *
 * @package BizCity_Companion_Notebook
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// Must be logged in.
if ( ! is_user_logged_in() ) {
    wp_safe_redirect( wp_login_url( home_url( '/note/' ) ) );
    exit;
}

$blog_name = get_bloginfo( 'name' );

// ── Enqueue React SPA assets BEFORE wp_head() so they appear in <head> ──
BCN_Admin_Page::enqueue_note_assets();

// Force type="module" for Vite ESM output.
add_filter( 'script_loader_tag', function( $tag, $handle ) {
    if ( $handle === 'bcn-react-app' ) {
        $tag = preg_replace( '/\s+type\s*=\s*["\'][^"\']*["\']/', '', $tag );
        $tag = str_replace( '<script ', '<script type="module" ', $tag );
    }
    return $tag;
}, 99999, 2 );

// ── Remove theme/plugin interference — whitelist approach ──
add_action( 'wp_enqueue_scripts', function() {
	global $wp_styles, $wp_scripts;

	$keep_styles  = [ 'bcn-react-styles', 'bizcity-webchat-react-css' ];
	$keep_scripts = [ 'jquery', 'jquery-core', 'jquery-migrate', 'bcn-react-app' ];

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
}, 999 );
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Notebook — <?php echo esc_html( $blog_name ); ?></title>
    <?php
    ob_start();
    wp_head();
    $head_html = ob_get_clean();
    $head_html = preg_replace_callback(
        '/<style\b[^>]*>(.*?)<\/style>/si',
        function( $m ) {
            if ( stripos( $m[0], 'bcn-' ) !== false ) return $m[0];
            if ( stripos( $m[1], '#bcn-app' ) !== false ) return $m[0];
            if ( stripos( $m[1], '.bcn-' ) !== false ) return $m[0];
            return '';
        },
        $head_html
    );
    echo $head_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    ?>
    <style>
    body.bcn-note-body > *:not(#bcn-app):not(script):not(style):not(link):not(noscript) {
        display: none !important;
    }
    #bizchat-float-btn,
    #button-contact-vr,
    .bizcity-float-widget,
    #bizcity-float-widget,
    #footer, .footer, .footer-wrapper,
    #footer-wrapper, .absolute-footer, #absolute-footer {
        display: none !important;
    }
    .bcn-bot-avatar {display: none !important;  }
    body.bcn-note-body { margin: 0; padding: 0; overflow: hidden; font-size: 80%; }
    #bcn-app { width: 100vw; height: 100vh; }
    .bcn-markdown p, #bcn-app p { line-height: 2.2 !important}
    .text-lg { margin-bottom:0px}
    .border {border:1px solid #eee !important}
    .absolute button { text-align:left !important; }
    #bcn-app button { font:inherit !important; }
    .border-t { border-top: 1px solid #eee !important; }
    #bcn-app input[type=text] { border: 1px solid #eee !important; }
    </style>
</head>
<body class="bcn-note-body">

<div id="bcn-app"></div>

<?php
ob_start();
wp_footer();
$footer_html = ob_get_clean();
$footer_html = preg_replace_callback(
    '/<style\b[^>]*>(.*?)<\/style>/si',
    function( $m ) {
        if ( stripos( $m[0], 'bcn-' ) !== false ) return $m[0];
        if ( stripos( $m[1], '.bcn-' ) !== false ) return $m[0];
        return '';
    },
    $footer_html
);
echo $footer_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
?>
</body>
</html>
