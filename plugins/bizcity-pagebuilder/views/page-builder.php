<?php
/**
 * Template: Page Builder Studio
 * Route: /tool-pagebuilder/
 *
 * Standalone SPA — React mounts into #pagebuilder-app.
 * Theme isolation is handled by BZPB_Frontend::isolate_theme().
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Disable Query Monitor and other debug toolbars from injecting into the builder
add_filter( 'qm/dispatch/html', '__return_false', 0 );
add_filter( 'qm/process', '__return_false', 0 );
remove_action( 'wp_footer', 'debug_bar_footer' );
add_filter( 'debug_bar_enable', '__return_false' );
show_admin_bar( false );

// Theme isolation
add_action( 'wp_enqueue_scripts', [ 'BZPB_Frontend', 'isolate_theme' ], 9999 );

$current_uid  = get_current_user_id();
$project_id   = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

// Config for React app
$bzpb_config = [
	'rest_url'   => untrailingslashit( rest_url( 'bzpb/v1' ) ),
	'nonce'      => wp_create_nonce( 'wp_rest' ),
	'ajax_url'   => admin_url( 'admin-ajax.php' ),
	'bzpb_nonce' => wp_create_nonce( 'bzpb_nonce' ),
	'user_id'    => $current_uid,
	'project_id' => $project_id,
	'home_url'   => home_url( '/' ),
	'version'    => BZPB_VERSION,
];

// Load WP media library for image picker
wp_enqueue_media();
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Page Builder — BizCity</title>
<?php wp_head(); ?>
<style>
  /* Reset theme pollution */
  html, body { margin: 0; padding: 0; }
  body.bzpb-body {
    font-family: 'Inter', system-ui, -apple-system, sans-serif;
    background: #0a0a0a;
    color: #fafafa;
    overflow: hidden;
  }
  #pagebuilder-app {
    width: 100vw;
    height: 100vh;
    position: relative;
  }
  /* Placeholder UI while React loads */
  .bzpb-loading {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    height: 100vh;
    color: #a1a1aa;
    gap: 16px;
  }
  .bzpb-loading-spinner {
    width: 40px;
    height: 40px;
    border: 3px solid #27272a;
    border-top-color: #2563eb;
    border-radius: 50%;
    animation: bzpb-spin 0.8s linear infinite;
  }
  @keyframes bzpb-spin { to { transform: rotate(360deg); } }

  /* Hide debug toolbars from breaking the builder layout */
  #qm-wrapper, #qm-title, #debug-bar, .debug-bar { display: none !important; }

  /* Hide WordPress theme floating contact/phone/Zalo widgets injected by plugins */
  #button-contact-vr,
  #gom-all-in-one,
  #zalo-vr,
  #phone-vr,
  .button-contact,
  .button-vr,
  [id$="-vr"].button-contact,
  .phone-vr,
  .zalo-vr,
  #wp-admin-bar { display: none !important; }

  /* ─── WP Media Modal: force light mode (ngăn màu dark từ studio tràn vào) ─── */
  .media-modal,
  .media-modal *,
  .wp-core-ui .media-modal,
  .wp-core-ui .media-modal * {
    color: #1d2327;
    border-color: #c3c4c7;
    box-sizing: border-box;
  }
  .media-modal {
    background: #fff;
  }
  .media-modal-backdrop {
    background: rgba(0,0,0,0.7);
  }
  /* Frame layout */
  .media-frame, .media-frame-menu, .media-frame-content,
  .media-frame-toolbar, .media-frame-router {
    background: #fff;
    color: #1d2327;
  }
  /* Sidebar / menu tabs */
  .media-frame-menu .media-menu-item {
    color: #1d2327;
    background: #f6f7f7;
  }
  .media-frame-menu .media-menu-item.active,
  .media-frame-menu .media-menu-item:hover {
    color: #000;
    background: #fff;
  }
  /* Search input */
  .media-toolbar input[type="search"],
  .attachments-browser .media-toolbar input[type="search"] {
    color: #1d2327;
    background: #fff;
    border: 1px solid #c3c4c7;
  }
  /* Attachment grid items */
  .attachment .thumbnail, .attachment .centered {
    background: #f6f7f7;
  }
  .attachment-details, .attachment .filename {
    color: #1d2327;
    background: #fff;
  }
  /* Sidebar details panel */
  .media-sidebar, .attachment-details .setting label,
  .attachment-details .setting span, .attachment-details .setting input,
  .attachment-details .setting textarea {
    color: #1d2327;
    background: #fff;
  }
  /* Buttons inside modal */
  .media-modal .button,
  .media-modal .button-primary,
  .media-modal .button-secondary {
    color: #1d2327;
    background: #f6f7f7;
    border-color: #c3c4c7;
  }
  .media-modal .button-primary {
    color: #fff;
    background: #2271b1;
    border-color: #2271b1;
  }
  /* Router tabs (Tải file / Media) */
  .media-router .media-menu-item {
    color: #1d2327;
  }
  /* Uploader drag area */
  .uploader-window, .uploader-inline {
    background: #f6f7f7;
    color: #1d2327;
  }
  .uploader-window .uploader-window-content {
    color: #1d2327;
  }
  /* Filter dropdown */
  .media-toolbar select, .media-toolbar .attachment-filters {
    color: #1d2327;
    background: #fff;
  }
</style>
</head>
<body class="bzpb-body">

<div id="pagebuilder-app">
  <div class="bzpb-loading">
    <div class="bzpb-loading-spinner"></div>
    <div>Đang tải Page Builder...</div>
  </div>
</div>

<!-- Inject config for React app -->
<script>
  window.bzpbConfig = <?php echo wp_json_encode( $bzpb_config ); ?>;
</script>

<?php
// Load React bundle + CSS (Vite build output with cache busting)
$dist_js  = BZPB_DIR . 'assets/dist/pagebuilder-app.js';
$dist_css = BZPB_DIR . 'assets/dist/pagebuilder-app.css';

if ( file_exists( $dist_js ) && file_exists( $dist_css ) ) :
  $js_version  = filemtime( $dist_js );
  $css_version = filemtime( $dist_css );
?>
  <link rel="stylesheet" href="<?php echo esc_url( BZPB_URL . 'assets/dist/pagebuilder-app.css?ver=' . $css_version ); ?>">
  <script type="module" src="<?php echo esc_url( BZPB_URL . 'assets/dist/pagebuilder-app.js?ver=' . $js_version ); ?>"></script>
<?php else : ?>
  <script>
    document.getElementById('pagebuilder-app').innerHTML =
      '<div class="bzpb-loading">' +
        '<div style="font-size:48px;margin-bottom:16px;">🌐</div>' +
        '<h2 style="color:#fafafa;margin:0 0 8px;">BizCity Page Builder</h2>' +
        '<p style="color:#71717a;max-width:400px;text-align:center;">' +
          'React app chưa build. Chạy <code style="background:#27272a;padding:2px 8px;border-radius:4px;">cd app && npm install && npm run build</code> để tạo assets/dist/' +
        '</p>' +
      '</div>';
  </script>
<?php endif; ?>

<?php wp_footer(); ?>
</body>
</html>
