<?php
/**
 * Page: Doc Studio — Full-page React app container.
 *
 * Rendered via rewrite rule /tool-doc/
 * React app mounts on #doc-app.
 *
 * Theme isolation: dequeue all theme/plugin assets, whitelist only bzdoc-app.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! is_user_logged_in() ) {
	wp_redirect( wp_login_url( home_url( '/tool-doc/' ) ) );
	exit;
}

// Disable admin bar — prevents WP from injecting margin-top:32px on <html>
show_admin_bar( false );

// Force enqueue our assets (template_redirect fires before wp_head)
BZDoc_Frontend::maybe_enqueue();

// ── Theme Isolation: strip ALL theme/plugin styles & scripts ──
add_action( 'wp_enqueue_scripts', function () {
	global $wp_styles, $wp_scripts;

	// Whitelist: only our CSS (JS is output manually with type="module")
	$keep_styles  = [ 'bzdoc-app' ];
	$keep_scripts = [];

	// Also remove WP block styles that pollute Tailwind
	$nuke_styles = [ 'global-styles', 'wp-block-library', 'wp-block-library-theme', 'classic-theme-styles' ];

	if ( isset( $wp_styles->registered ) ) {
		foreach ( array_keys( $wp_styles->registered ) as $handle ) {
			if ( ! in_array( $handle, $keep_styles, true ) ) {
				wp_dequeue_style( $handle );
				wp_deregister_style( $handle );
			}
		}
	}

	// Force nuke block styles even if re-registered after our dequeue
	foreach ( $nuke_styles as $ns ) {
		wp_dequeue_style( $ns );
		wp_deregister_style( $ns );
	}

	if ( isset( $wp_scripts->registered ) ) {
		foreach ( array_keys( $wp_scripts->registered ) as $handle ) {
			if ( ! in_array( $handle, $keep_scripts, true ) ) {
				wp_dequeue_script( $handle );
			}
		}
	}
}, 9999 );
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<title>Doc Studio — BizCity</title>
	<style>
		/* Reset: prevent theme pollution on body */
		html, body {
			margin: 0;
			padding: 0;
			background: #f8fafc;
			color: #1f2937;
			font-family: -apple-system, BlinkMacSystemFont, 'Inter', 'Segoe UI', Roboto, sans-serif;
			font-size: 100%;
			line-height: 1.6;
			-webkit-font-smoothing: antialiased;
		}
		/* Kill WP global-styles and block-library that pollute Tailwind */
		#global-styles-inline-css,
		#wp-block-library-css,
		#wp-block-library-theme-css,
		#classic-theme-styles-inline-css { display: none !important; }
		/* Override any leftover theme variables on our app root */
		.bzdoc-studio-wrap, .bzdoc-studio-wrap * {
			--wp-preset-color--black: initial;
			--wp-preset-color--white: initial;
		}
		/* Hide WP admin bar & Query Monitor on this page */
		#wpadminbar, #query-monitor-main, .qm-icon-container { display: none !important; }
		html { margin-top: 0 !important; }
	</style>
	<?php
	// Strip ALL theme hooks that output HTML into the page (nav, sidebars, etc.)
	remove_all_actions( 'wp_body_open' );
	remove_all_actions( 'wp_footer' );
	?>
	<?php wp_head(); ?>
</head>
<body class="bzdoc-body">
	<div id="doc-app" class="bzdoc-studio-wrap"></div>
	<script>
		var bzdocConfig = <?php echo wp_json_encode( [
			'restUrl'   => esc_url_raw( rest_url( 'bzdoc/v1' ) ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'userId'    => get_current_user_id(),
			'pluginUrl' => BZDOC_URL,
		] ); ?>;
		// Shim wpApiSettings so primitive callers + SourceSidebar binding fetch work
		// (this template dequeues all WP scripts including wp-api).
		window.wpApiSettings = window.wpApiSettings || {
			root:  <?php echo wp_json_encode( esc_url_raw( rest_url() ) ); ?>,
			nonce: bzdocConfig.nonce
		};
	</script>
	<?php
	// ── Phase 0.13 W3 — TwinShell primitives bundle (picker + source upload).
	// Required because this template dequeues all WP scripts; SourceSidebar
	// calls window.BizcityTwin.openSourceUpload() which lives here.
	if ( class_exists( 'BizCity_Twin_Shell_Primitives' )
		&& BizCity_Twin_Shell_Primitives::is_enabled()
		&& defined( 'BIZCITY_TWIN_SHELL_DIR' ) ) {

		$prim_files = BizCity_Twin_Shell_Primitives::asset_files();
		$prim_urls  = BizCity_Twin_Shell_Primitives::asset_urls();
		$ts_ver     = defined( 'BIZCITY_TWIN_SHELL_VERSION' ) ? BIZCITY_TWIN_SHELL_VERSION : '0.13.0';

		$css_v   = file_exists( $prim_files['css'] )    ? filemtime( $prim_files['css'] )    : $ts_ver;
		$js_v    = file_exists( $prim_files['js'] )     ? filemtime( $prim_files['js'] )     : $ts_ver;
		$up_v    = file_exists( $prim_files['upload'] ) ? filemtime( $prim_files['upload'] ) : $ts_ver;

		$prim_cfg = wp_json_encode( [
			'restRoot' => esc_url_raw( rest_url( BizCity_Twin_Shell_Primitives::NS . '/' ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'userId'   => get_current_user_id(),
			'plugin'   => 'bizdoc',
		] );
		?>
		<link rel="stylesheet" href="<?php echo esc_url( $prim_urls['css'] . '?ver=' . $css_v ); ?>">
		<script>window.BIZCITY_TWIN_PRIMITIVES_CFG = <?php echo $prim_cfg; ?>;</script>
		<script src="<?php echo esc_url( $prim_urls['js']     . '?ver=' . $js_v ); ?>"></script>
		<script src="<?php echo esc_url( $prim_urls['upload'] . '?ver=' . $up_v ); ?>"></script>
		<?php
	}

	$js_file = BZDOC_DIR . 'assets/dist/doc-app.js';
	$js_ver  = file_exists( $js_file ) ? filemtime( $js_file ) : BZDOC_VERSION;
	?>
	<script type="module" src="<?php echo esc_url( BZDOC_URL . 'assets/dist/doc-app.js?ver=' . $js_ver ); ?>"></script>
	<?php /* wp_footer intentionally omitted — full-page app, theme hooks removed above */ ?>
</body>
</html>
