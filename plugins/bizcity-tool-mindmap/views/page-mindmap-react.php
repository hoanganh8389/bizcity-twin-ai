<?php
/**
 * BizCity Tool Mindmap — React SPA (react-d3-tree Mind Map)
 *
 * Interactive mindmap editor powered by react-d3-tree + AI.
 * Served at /mindmap/ slug.
 *
 * @package BizCity_Tool_Mindmap
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$is_logged_in = is_user_logged_in();
$user_id      = get_current_user_id();

/* ── Collect config for the React app ── */
$raw_id  = isset( $_GET['id'] ) ? sanitize_text_field( $_GET['id'] ) : '';
$open_id = $raw_id ? BizCity_Tool_Mindmap::decode_id( $raw_id ) : 0;

$config = [
    'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
    'nonce'      => wp_create_nonce( 'bztool_mindmap' ),
    'userId'     => $user_id,
    'blogId'     => get_current_blog_id(),
    'isLoggedIn' => $is_logged_in,
    'blogName'   => get_bloginfo( 'name' ),
    'projectId'  => $open_id,
    'hashId'     => $raw_id,
];

/* ── Asset paths ── */
$base_dir = BZTOOL_MINDMAP_DIR;
$base_url = BZTOOL_MINDMAP_URL;
$js_file  = 'assets/react/js/bizcity-mindmap-app.js';
$css_file = 'assets/react/css/bizcity-mindmap-app.css';

?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Smart Mindmap — <?php bloginfo( 'name' ); ?></title>
<?php
/* Enqueue built CSS */
if ( file_exists( $base_dir . $css_file ) ) {
    echo '<link rel="stylesheet" crossorigin href="' . esc_url( $base_url . $css_file ) . '?v=' . filemtime( $base_dir . $css_file ) . '">' . "\n";
}
?>
<style>
/* Minimal loading state while React boots */
#mindmap-root { min-height: 100vh; background: #fafafa; }
.mindmap-loading {
    display: flex; align-items: center; justify-content: center;
    height: 100vh; flex-direction: column; gap: 12px;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    color: #666;
}
.mindmap-loading .spinner {
    width: 32px; height: 32px;
    border: 3px solid #e0e0e0; border-top-color: #1976d2;
    border-radius: 50%; animation: mm-spin .7s linear infinite;
}
@keyframes mm-spin { to { transform: rotate(360deg); } }
</style>
</head>
<body>
<div id="mindmap-root">
    <div class="mindmap-loading">
        <div class="spinner"></div>
        <span>Đang tải Smart Mindmap...</span>
    </div>
</div>

<script>
/* Pass config to React app */
window.bzMindmapConfig = <?php echo wp_json_encode( $config ); ?>;
</script>

<?php
/* Enqueue built JS as ESM module */
if ( file_exists( $base_dir . $js_file ) ) {
    echo '<script type="module" crossorigin src="' . esc_url( $base_url . $js_file ) . '?v=' . filemtime( $base_dir . $js_file ) . '"></script>' . "\n";
}
?>

</body>
</html>
