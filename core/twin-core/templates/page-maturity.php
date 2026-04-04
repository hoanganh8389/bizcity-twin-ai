<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Twin_Core
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * BizCity Twin AI Maturity — Frontend /maturity/ page template
 *
 * Template Name: Maturity Dashboard SPA (BizCity)
 *
 * Full-page Maturity Dashboard for frontend /maturity/ URL.
 * Strips admin bar, theme CSS/JS → clean iframe-ready page.
 *
 * @package BizCity_Twin_Core
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// Must be logged in.
if ( ! is_user_logged_in() ) {
	wp_safe_redirect( wp_login_url( home_url( '/maturity/' ) ) );
	exit;
}

$blog_name = get_bloginfo( 'name' );

// Enqueue maturity assets before wp_head()
BizCity_Maturity_Dashboard::do_enqueue_assets();

// Remove theme/plugin interference — dequeue ALL non-maturity styles/scripts.
add_action( 'wp_enqueue_scripts', function() {
	global $wp_styles, $wp_scripts;

	// Whitelist: only keep maturity-specific + core jQuery + Chart.js
	$keep_styles  = [ 'bizcity-maturity-dashboard' ];
	$keep_scripts = [ 'jquery', 'jquery-core', 'jquery-migrate', 'chartjs', 'bizcity-maturity-dashboard' ];

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

	// Remove Customizer Additional CSS output
	remove_action( 'wp_head', 'wp_custom_css_cb', 101 );
	// Remove global styles (Gutenberg block library)
	remove_action( 'wp_enqueue_scripts', 'wp_enqueue_global_styles' );
	remove_action( 'wp_footer', 'wp_enqueue_global_styles', 1 );
}, 999 );

// Remove remaining wp_head actions that output inline styles
add_action( 'wp_head', function() {
	// Remove global-styles-inline-css
	remove_action( 'wp_body_open', 'wp_global_styles_render_svg_filters' );
}, 0 );
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
	<title>Twin AI Maturity — <?php echo esc_html( $blog_name ); ?></title>
	<?php
	// Capture wp_head output and strip non-maturity inline <style> blocks
	ob_start();
	wp_head();
	$head_html = ob_get_clean();

	// Keep only <style> tags that belong to maturity dashboard or are essential
	$head_html = preg_replace_callback(
		'/<style\b[^>]*>(.*?)<\/style>/si',
		function( $m ) {
			$tag  = $m[0];
			$body = $m[1];
			// Keep if it has our maturity id
			if ( stripos( $tag, 'bizcity-maturity' ) !== false ) return $tag;
			// Keep if content mentions maturity classes
			if ( stripos( $body, '.maturity-' ) !== false ) return $tag;
			if ( stripos( $body, '.bizcity-maturity' ) !== false ) return $tag;
			// Strip everything else (theme custom CSS, Gutenberg global styles, etc.)
			return '';
		},
		$head_html
	);

	echo $head_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — wp_head() output
	?>
	<style>
	body.bizcity-maturity-body > *:not(.bizcity-maturity-wrap):not(script):not(style):not(link):not(noscript) {
		display: none !important;
	}
	#bizchat-float-btn,
	#button-contact-vr,
	.bizcity-float-widget,
	#bizcity-float-widget,
	#wpadminbar,
	#footer, .footer, .footer-wrapper,
	#footer-wrapper, .absolute-footer, #absolute-footer,
	#query-monitor-main, #qm {
		display: none !important;
	}
	html { margin-top: 0 !important; }
	body.bizcity-maturity-body {
		margin: 0; padding: 0;
		background: #f8fafc !important;
		font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;
	}
	.bizcity-maturity-wrap { min-height: 100vh; }
	</style>
</head>
<body class="bizcity-maturity-body">

<?php include BIZCITY_TWIN_CORE_DIR . '/templates/maturity-dashboard.php'; ?>

<?php
// Same filtering for wp_footer — strip leaked inline styles
ob_start();
wp_footer();
$footer_html = ob_get_clean();

$footer_html = preg_replace_callback(
	'/<style\b[^>]*>(.*?)<\/style>/si',
	function( $m ) {
		$body = $m[1];
		if ( stripos( $m[0], 'bizcity-maturity' ) !== false ) return $m[0];
		if ( stripos( $body, '.maturity-' ) !== false ) return $m[0];
		if ( stripos( $body, '.bizcity-maturity' ) !== false ) return $m[0];
		return '';
	},
	$footer_html
);

echo $footer_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
?>
</body>
</html>
