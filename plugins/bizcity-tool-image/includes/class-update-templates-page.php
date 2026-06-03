<?php
/**
 * BizCity Tool Image — "Update Templates" admin page (Phase IT-4)
 *
 * 1-button UI for syncing templates + editor assets with bizcity.vn hub.
 *
 * @package BizCity_Tool_Image
 * @since   3.7.2
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Update_Templates_Page {

    const MENU_SLUG = 'bztimg-update-templates';
    const NONCE     = 'bztimg_update_templates';

    public static function init() {
        add_action( 'admin_menu',          array( __CLASS__, 'register_menu' ), 20 );
        add_action( 'rest_api_init',       array( __CLASS__, 'register_routes' ) );
        add_action( 'admin_post_bztimg_run_sync',         array( __CLASS__, 'handle_run_sync' ) );
        add_action( 'admin_post_bztimg_toggle_protected', array( __CLASS__, 'handle_toggle_protected' ) );
    }

    /* ============================================================
     *  MENU
     * ============================================================ */

    public static function register_menu() {
        add_submenu_page(
            'bztimg-dashboard',
            'Update Templates',
            '🔄 Update Templates',
            'manage_options',
            self::MENU_SLUG,
            array( __CLASS__, 'render' )
        );
    }

    /* ============================================================
     *  REST (for FE / AJAX-style call from page)
     * ============================================================ */

    public static function register_routes() {
        register_rest_route( 'bztool-image/v1', '/sync/run', array(
            'methods'             => 'POST',
            'permission_callback' => array( __CLASS__, 'permit_admin' ),
            'callback'            => array( __CLASS__, 'rest_run_sync' ),
            'args'                => array(
                'force' => array( 'type' => 'boolean', 'default' => false ),
            ),
        ) );
        register_rest_route( 'bztool-image/v1', '/sync/status', array(
            'methods'             => 'GET',
            'permission_callback' => array( __CLASS__, 'permit_admin' ),
            'callback'            => array( __CLASS__, 'rest_status' ),
        ) );
        register_rest_route( 'bztool-image/v1', '/sync/protect', array(
            'methods'             => 'POST',
            'permission_callback' => array( __CLASS__, 'permit_admin' ),
            'callback'            => array( __CLASS__, 'rest_set_protected' ),
            'args'                => array(
                'table'     => array( 'type' => 'string',  'required' => true ),
                'id'        => array( 'type' => 'integer', 'required' => true ),
                'protected' => array( 'type' => 'boolean', 'required' => true ),
            ),
        ) );
    }

    public static function permit_admin() {
        return current_user_can( 'manage_options' );
    }

    public static function rest_run_sync( $req ) {
        if ( ! class_exists( 'BizCity_Image_Template_Sync' ) ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => 'Sync engine chưa load.' ), 200 );
        }
        $force  = (bool) $req->get_param( 'force' );
        $report = BizCity_Image_Template_Sync::apply_sync( $force );
        if ( is_wp_error( $report ) ) {
            return new WP_REST_Response( array(
                'success' => false,
                'code'    => $report->get_error_code(),
                'message' => $report->get_error_message(),
            ), 200 );
        }
        return new WP_REST_Response( array( 'success' => true, 'data' => $report ), 200 );
    }

    public static function rest_status() {
        return new WP_REST_Response( array(
            'success'    => true,
            'data'       => array(
                'etag'         => BizCity_Image_Template_Sync::get_etag(),
                'last_sync_at' => BizCity_Image_Template_Sync::get_last_sync_at(),
                'last_report'  => BizCity_Image_Template_Sync::get_last_report(),
                'table_stats'  => BizCity_Image_Template_Sync::get_table_stats(),
            ),
        ), 200 );
    }

    public static function rest_set_protected( $req ) {
        $ok = BizCity_Image_Template_Sync::set_protected(
            (string) $req->get_param( 'table' ),
            (int) $req->get_param( 'id' ),
            (bool) $req->get_param( 'protected' )
        );
        if ( is_wp_error( $ok ) ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => $ok->get_error_message() ), 200 );
        }
        return new WP_REST_Response( array( 'success' => (bool) $ok ), 200 );
    }

    /* ============================================================
     *  admin-post.php form handlers
     * ============================================================ */

    public static function handle_run_sync() {
        check_admin_referer( self::NONCE );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden' );

        $force  = ! empty( $_POST['force'] );
        $report = BizCity_Image_Template_Sync::apply_sync( $force );

        if ( is_wp_error( $report ) ) {
            $msg = 'error&detail=' . rawurlencode( $report->get_error_message() );
        } else {
            $msg = 'ok&status=' . rawurlencode( isset( $report['status'] ) ? $report['status'] : 'synced' );
        }
        wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&msg=' . $msg ) );
        exit;
    }

    public static function handle_toggle_protected() {
        check_admin_referer( self::NONCE );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden' );

        $table = isset( $_POST['table'] ) ? sanitize_key( wp_unslash( $_POST['table'] ) ) : '';
        $id    = isset( $_POST['id'] )    ? (int) $_POST['id']        : 0;
        $val   = ! empty( $_POST['protected'] );
        BizCity_Image_Template_Sync::set_protected( $table, $id, $val );

        wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&msg=protect_ok' ) );
        exit;
    }

    /* ============================================================
     *  RENDER
     * ============================================================ */

    public static function render() {
        $etag       = BizCity_Image_Template_Sync::get_etag();
        $last_sync  = BizCity_Image_Template_Sync::get_last_sync_at();
        $report     = BizCity_Image_Template_Sync::get_last_report();
        $stats      = BizCity_Image_Template_Sync::get_table_stats();
        $gateway    = class_exists( 'BizCity_LLM_Client' )
            ? BizCity_LLM_Client::instance()->get_gateway_url()
            : '(BizCity_LLM_Client not loaded)';
        $ready      = class_exists( 'BizCity_LLM_Client' ) && BizCity_LLM_Client::instance()->is_ready();

        $msg = isset( $_GET['msg'] ) ? sanitize_text_field( wp_unslash( $_GET['msg'] ) ) : '';
        ?>
        <div class="wrap">
            <h1>🔄 Update Templates from BizCity Hub</h1>
            <p class="description">
                Đồng bộ <strong>1 chiều</strong> từ hub <code><?php echo esc_html( $gateway ); ?></code> về site này.
                Row do user tự tạo (<code>source='local'</code>) hoặc đã bật <strong>🔒 Protect</strong>
                sẽ KHÔNG bị ghi đè. Pattern R-GW-8.
            </p>

            <?php if ( ! $ready ) : ?>
                <div class="notice notice-error"><p>
                    ⚠️ <strong>BizCity_LLM_Client chưa sẵn sàng.</strong>
                    Cần cấu hình <code>bizcity_llm_gateway_url</code> + <code>bizcity_llm_api_key</code>
                    trong Bizcity Twin AI settings (Bearer <code>biz-xxx…</code>).
                </p></div>
            <?php endif; ?>

            <?php if ( $msg === 'ok' || strpos( $msg, 'ok&' ) === 0 ) : ?>
                <div class="notice notice-success is-dismissible"><p>✅ Đồng bộ xong.</p></div>
            <?php elseif ( strpos( $msg, 'error' ) === 0 ) : ?>
                <div class="notice notice-error is-dismissible"><p>❌ Lỗi đồng bộ. Xem error log.</p></div>
            <?php elseif ( $msg === 'protect_ok' ) : ?>
                <div class="notice notice-success is-dismissible"><p>✅ Đã cập nhật trạng thái bảo vệ.</p></div>
            <?php endif; ?>

            <div style="background:#fff;padding:20px;border:1px solid #e5e7eb;border-radius:8px;margin-top:16px;max-width:900px;">
                <h2 style="margin-top:0;">▶ Run Sync</h2>
                <table class="form-table" style="margin:0;">
                    <tr>
                        <th>Last sync</th>
                        <td><code><?php echo $last_sync ? esc_html( $last_sync ) : '— chưa chạy —'; ?></code></td>
                    </tr>
                    <tr>
                        <th>Manifest ETag</th>
                        <td><code><?php echo $etag ? esc_html( substr( $etag, 0, 16 ) ) . '…' : '—'; ?></code></td>
                    </tr>
                    <?php if ( ! empty( $report['router_version'] ) ) : ?>
                    <tr>
                        <th>Router version</th>
                        <td><code><?php echo esc_html( $report['router_version'] ); ?></code></td>
                    </tr>
                    <?php endif; ?>
                </table>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:16px;">
                    <input type="hidden" name="action" value="bztimg_run_sync" />
                    <?php wp_nonce_field( self::NONCE ); ?>
                    <button type="submit" class="button button-primary button-hero" <?php echo $ready ? '' : 'disabled'; ?>>
                        🔄 Update Now
                    </button>
                    <label style="margin-left:12px;">
                        <input type="checkbox" name="force" value="1" />
                        Force re-sync (override <code>protected_from_sync</code> + ignore ETag cache)
                    </label>
                </form>
            </div>

            <?php if ( ! empty( $report ) ) : ?>
            <div style="background:#fff;padding:20px;border:1px solid #e5e7eb;border-radius:8px;margin-top:16px;max-width:900px;">
                <h2 style="margin-top:0;">📊 Last Sync Report</h2>
                <p>
                    <strong>Status:</strong> <code><?php echo esc_html( isset( $report['status'] ) ? $report['status'] : '—' ); ?></code>
                    &nbsp;|&nbsp;
                    <strong>Saved at:</strong> <code><?php echo esc_html( isset( $report['saved_at'] ) ? $report['saved_at'] : '—' ); ?></code>
                </p>
                <?php if ( ! empty( $report['counts'] ) ) : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>Resource</th>
                            <th>Inserted</th>
                            <th>Updated</th>
                            <th>Unchanged</th>
                            <th>Local skipped</th>
                            <th>Protected skipped</th>
                            <th>Errors</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $report['counts'] as $type => $s ) :
                        if ( ! is_array( $s ) ) {
                            echo '<tr><td>' . esc_html( $type ) . '</td><td colspan="6"><code>' . esc_html( (string) $s ) . '</code></td></tr>';
                            continue;
                        }
                        ?>
                        <tr>
                            <td><code><?php echo esc_html( $type ); ?></code></td>
                            <td><?php echo (int) ( $s['inserted'] ?? 0 ); ?></td>
                            <td><?php echo (int) ( $s['updated'] ?? 0 ); ?></td>
                            <td><?php echo (int) ( $s['unchanged'] ?? 0 ); ?></td>
                            <td><?php echo (int) ( $s['skipped_local_conflict'] ?? 0 ); ?></td>
                            <td><?php echo (int) ( $s['skipped_protected'] ?? 0 ); ?></td>
                            <td><?php echo (int) ( $s['error'] ?? 0 ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div style="background:#fff;padding:20px;border:1px solid #e5e7eb;border-radius:8px;margin-top:16px;max-width:900px;">
                <h2 style="margin-top:0;">📦 Per-table Source Breakdown</h2>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>Table</th>
                            <th>From Hub</th>
                            <th>Local (user)</th>
                            <th>🔒 Protected</th>
                            <th>Deprecated</th>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $stats as $key => $s ) : ?>
                        <tr>
                            <td><code><?php echo esc_html( $key ); ?></code></td>
                            <td><?php echo (int) $s['hub']; ?></td>
                            <td><?php echo (int) $s['local']; ?></td>
                            <td><?php echo (int) $s['protected']; ?></td>
                            <td><?php echo (int) $s['deprecated']; ?></td>
                            <td><strong><?php echo (int) $s['total']; ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div style="background:#f8fafc;padding:16px;border:1px solid #cbd5e1;border-radius:8px;margin-top:16px;max-width:900px;">
                <h3 style="margin-top:0;">📖 How sync works (2 groups)</h3>
                <ul style="line-height:1.8;">
                    <li><strong><code>source='hub'</code></strong> — Row do hub <code>bizcity.vn</code> quản lý. Auto-update khi hub bump <code>version</code>. Có thể bật <code>protected_from_sync=1</code> để giữ phiên bản hiện tại bất chấp hub đổi.</li>
                    <li><strong><code>source='local'</code></strong> — Row do user tự tạo (admin UI / REST POST). Sync engine <strong>KHÔNG BAO GIỜ đụng đến</strong>. An toàn tuyệt đối với user content.</li>
                    <li><strong>Soft-delete</strong> — Khi hub xoá row (vắng trong manifest), client row hub-managed bị set <code>status='deprecated'</code> (FE có thể ẩn). Protected rows được giữ.</li>
                    <li><strong>ETag</strong> — Manifest có ETag; nếu unchanged, server trả <code>304</code> và client skip fetch bundle (tiết kiệm bandwidth).</li>
                    <li><strong>Force mode</strong> — Bỏ qua ETag cache + bỏ qua protected flag (dùng khi muốn rollback về phiên bản hub).</li>
                </ul>
            </div>

            <p style="margin-top:16px;">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=bztimg-templates' ) ); ?>" class="button">← Quản lý Templates</a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=bztimg-editor-assets' ) ); ?>" class="button">📦 Editor Assets</a>
            </p>
        </div>
        <?php
    }
}
