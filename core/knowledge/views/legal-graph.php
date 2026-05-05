<?php
/**
 * Bizcity Twin AI — Legal AI Module
 *
 * Admin View: Legal Knowledge Graph
 * URL: /wp-admin/admin.php?page=bizcity-knowledge-legal-graph
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge\Legal
 * @since      5.2.2 (2026-04-23)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( esc_html__( 'Không có quyền truy cập', 'bizcity-twin-ai' ) );
}

// Config is injected by class-admin-menu.php via wp_localize_script('bizCityLegal').
?>
<?php /* All UI is rendered by the React SPA. Data via REST bizcity/v1/legal-graph. */ ?>
<div id="bizcity-legal-knowledge-root">
    <div style="display:flex;align-items:center;justify-content:center;height:400px;color:#6b7280;font-size:14px;font-family:sans-serif;">
        &#x23F3; Đang tải Legal Knowledge App&hellip;
    </div>
</div>

