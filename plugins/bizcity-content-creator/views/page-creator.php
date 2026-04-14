<?php
/**
 * Content Creator — Standalone page template for /creator/ and /creator/{id}/.
 *
 * Fully isolated: no wp_head() / wp_footer() — avoids theme CSS conflicts,
 * Query Monitor output, bizchat widget, and other plugin injections.
 * Same pattern as /tasks/ page.
 *
 * @package Bizcity_Content_Creator
 * @since   0.1.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$is_iframe   = isset( $_GET['bizcity_iframe'] );
$template_id = absint( get_query_var( 'bzcc_template_id' ) );
$page_title  = '✨ Content Creator — BizCity';

if ( $template_id ) {
	$tpl = BZCC_Template_Manager::get_by_id( $template_id );
	if ( $tpl ) {
		$page_title = esc_html( $tpl->title ) . ' — Content Creator';
	}
}

$css_ver = BZCC_VERSION;
$js_ver  = BZCC_VERSION;
$css_url = BZCC_URL . 'assets/frontend.css';
$js_url  = BZCC_URL . 'assets/frontend.js';

/* Build localized JS data (same as enqueue_assets) */
$bzcc_front_data = wp_json_encode( [
	'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
	'restUrl'  => untrailingslashit( rest_url( 'bzcc/v1' ) ),
	'nonce'    => wp_create_nonce( 'wp_rest' ),
	'baseUrl'  => home_url( 'creator/' ),
] );
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo $page_title; ?></title>
<link rel="stylesheet" href="<?php echo esc_url( $css_url ); ?>?ver=<?php echo esc_attr( $css_ver ); ?>">
<style>
*, *::before, *::after { box-sizing: border-box; }
html, body { margin: 0; padding: 0; background: #f8fafc; color: #1e293b; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; line-height: 1.5; -webkit-font-smoothing: antialiased; }
<?php if ( $is_iframe ) : ?>
body { background: transparent; }
<?php endif; ?>
</style>
</head>
<body>

<?php
/* Manually render shortcode content (enqueue_assets skipped — we load CSS/JS directly) */
echo BZCC_Frontend::render_shortcode( [] );
?>

<script>var bzccFront = <?php echo $bzcc_front_data; ?>;</script>
<script src="<?php echo esc_url( $js_url ); ?>?ver=<?php echo esc_attr( $js_ver ); ?>"></script>
</body>
</html>
