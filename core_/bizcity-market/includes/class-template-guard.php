<?php
/**
 * BizCity Market — Template Guard
 *
 * Khi user truy cập page slug (vd: /note/) hoặc admin page (vd: ?page=bizcity-workspace)
 * mà plugin cung cấp template/admin page chưa active:
 * - Admin (manage_options) → hiện card mô tả plugin + nút kích hoạt
 * - User thường → hiện thông báo "chưa khả dụng"
 *
 * Supports:
 * 1. Frontend template pages (Template Page header) — via template_include filter
 * 2. Admin pages (Admin Slug header) — via admin_menu fallback registration
 *
 * @package BizCity_Market
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class BizCity_Market_Template_Guard {

    public static function boot(): void {
        // Frontend template pages
        add_filter( 'template_include', [ __CLASS__, 'guard_template' ], 99999 );

        // Frontend 404 pages — intercept when WP page doesn't exist yet
        add_action( 'template_redirect', [ __CLASS__, 'guard_404' ], 5 );

        // Admin pages — register fallback for unregistered pages
        add_action( 'admin_menu', [ __CLASS__, 'guard_admin_page' ], 99999 );

        // Clear cache khi plugin activate / deactivate
        add_action( 'activated_plugin',   [ __CLASS__, 'clear_cache' ] );
        add_action( 'deactivated_plugin', [ __CLASS__, 'clear_cache' ] );
    }

    /* ══════════════════════════════════════════════════════════
     *  GUARD 1: Frontend Template Pages (/note/, /automation/, etc.)
     * ══════════════════════════════════════════════════════════ */

    /**
     * Intercept pages whose plugin template provider is inactive.
     */
    public static function guard_template( string $template ): string {
        if ( ! is_page() ) return $template;

        $page_id = get_the_ID();
        if ( ! $page_id ) return $template;

        $page_template = get_post_meta( $page_id, '_wp_page_template', true );

        // Không có custom template hoặc dùng default → bỏ qua
        if ( ! $page_template || $page_template === 'default' ) return $template;

        // Nếu template đã được plugin load thành công (nằm ngoài theme) → OK
        $theme_dir      = wp_normalize_path( get_template_directory() );
        $stylesheet_dir = wp_normalize_path( get_stylesheet_directory() );
        $norm_template  = wp_normalize_path( $template );

        $is_theme_fallback = ( strpos( $norm_template, $theme_dir ) === 0
                            || strpos( $norm_template, $stylesheet_dir ) === 0 );

        if ( ! $is_theme_fallback ) return $template; // plugin template loaded OK

        // Template là theme fallback → plugin cung cấp template có thể chưa active
        $page_slug   = get_post_field( 'post_name', $page_id );
        $plugin_info = self::find_plugin_by_key( 'template_page', $page_slug );

        if ( ! $plugin_info ) return $template; // Không phải trang plugin agent

        // Kiểm tra plugin thực sự inactive
        if ( ! function_exists( 'is_plugin_active' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if ( is_plugin_active( $plugin_info['plugin_file'] ) ) return $template;

        // Plugin inactive → show fallback UI
        if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
            self::render_admin_activation_page( $plugin_info );
        } else {
            self::render_access_denied();
        }
        exit;
    }

    /* ══════════════════════════════════════════════════════════
     *  GUARD 1b: 404 Pages for known Template Page slugs
     *  When plugin was never activated, the WP page doesn't exist → 404.
     *  Intercept and show guard card instead.
     * ══════════════════════════════════════════════════════════ */

    public static function guard_404(): void {
        if ( ! is_404() ) return;

        // Extract first URL segment as potential page slug
        $path = trim( wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ), '/' );
        // Strip leading path from home_url (for subdirectory installs)
        $home_path = trim( wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
        if ( $home_path && strpos( $path, $home_path ) === 0 ) {
            $path = trim( substr( $path, strlen( $home_path ) ), '/' );
        }
        // Take first segment only (e.g., /note/project/123 → note)
        $slug = explode( '/', $path )[0];
        if ( ! $slug ) return;

        $plugin_info = self::find_plugin_by_key( 'template_page', $slug );
        if ( ! $plugin_info ) return;

        // Verify plugin is indeed inactive
        if ( ! function_exists( 'is_plugin_active' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if ( is_plugin_active( $plugin_info['plugin_file'] ) ) return;

        // Plugin inactive → show fallback UI
        if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
            self::render_admin_activation_page( $plugin_info );
        } else {
            self::render_access_denied();
        }
        exit;
    }

    /* ══════════════════════════════════════════════════════════
     *  GUARD 2: Admin Pages (?page=bizcity-workspace, etc.)
     * ══════════════════════════════════════════════════════════ */

    /**
     * At admin_menu priority 99999, check if the requested ?page= exists.
     * If not, find matching plugin → register a fallback admin page with guard UI.
     */
    public static function guard_admin_page(): void {
        $page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
        if ( ! $page ) return;

        // Check if page is already registered
        global $_registered_pages;
        $hookname = get_plugin_page_hookname( $page, '' );
        if ( ! empty( $_registered_pages[ $hookname ] ) ) return;

        // Also check as submenu under various parents
        $common_parents = [ 'index.php', 'tools.php', 'options-general.php', 'admin.php' ];
        foreach ( $common_parents as $parent ) {
            $sub_hookname = get_plugin_page_hookname( $page, $parent );
            if ( ! empty( $_registered_pages[ $sub_hookname ] ) ) return;
        }

        // Page not registered — find the plugin that owns this admin slug
        $plugin_info = self::find_plugin_by_key( 'admin_slug', $page );
        if ( ! $plugin_info ) return;

        // Verify plugin is inactive
        if ( ! function_exists( 'is_plugin_active' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if ( is_plugin_active( $plugin_info['plugin_file'] ) ) return;

        // User must have manage_options
        if ( ! current_user_can( 'manage_options' ) ) return;

        // Store plugin info for the render callback
        self::$admin_guard_info = $plugin_info;

        // Register a temporary admin page so WordPress doesn't 403
        add_menu_page(
            $plugin_info['name'],
            $plugin_info['name'],
            'manage_options',
            $page,
            [ __CLASS__, 'render_admin_guard_page' ],
            'dashicons-warning',
            999
        );
    }

    /** @var array|null Temp storage for admin guard render */
    private static $admin_guard_info = null;

    /**
     * Render the guard card inside WP admin frame.
     */
    public static function render_admin_guard_page(): void {
        if ( ! self::$admin_guard_info ) {
            echo '<div class="wrap"><h1>Plugin không khả dụng</h1></div>';
            return;
        }

        $info = self::$admin_guard_info;
        self::render_admin_guard_card( $info );
    }

    /* ── Lookup ──────────────────────────────────────────────── */

    /**
     * Tìm plugin info theo key type (template_page hoặc admin_slug).
     * Cache bằng transient (1h), tự clear khi activate/deactivate plugin.
     */
    private static function find_plugin_by_key( string $key_type, string $value ): ?array {
        $cache_key = 'bizcity_plugin_guard_map';
        $map       = get_transient( $cache_key );

        if ( false === $map ) {
            $map = self::build_plugin_map();
            set_transient( $cache_key, $map, HOUR_IN_SECONDS );
        }

        return $map[ $key_type ][ $value ] ?? null;
    }

    /**
     * Quét tất cả plugin (cả active lẫn inactive) để build map:
     *   template_page slug → plugin info
     *   admin_slug → plugin info
     */
    private static function build_plugin_map(): array {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $map = [
            'template_page' => [],
            'admin_slug'    => [],
        ];

        foreach ( get_plugins() as $plugin_file => $data ) {
            $full_path = WP_PLUGIN_DIR . '/' . $plugin_file;
            if ( ! file_exists( $full_path ) ) continue;

            $custom = get_file_data( $full_path, [
                'Template Page' => 'Template Page',
                'Admin Slug'    => 'Admin Slug',
                'Role'          => 'Role',
                'Icon Path'     => 'Icon Path',
                'Cover URI'     => 'Cover URI',
                'Credit'        => 'Credit',
                'Category'      => 'Category',
            ] );

            $template_page = sanitize_title( trim( $custom['Template Page'] ?? '' ) );
            $admin_slug    = sanitize_text_field( trim( $custom['Admin Slug'] ?? '' ) );

            // Nếu không có Template Page cũng không có Admin Slug → skip
            if ( ! $template_page && ! $admin_slug ) continue;

            $slug = sanitize_key( dirname( $plugin_file ) );
            if ( $slug === '.' ) $slug = sanitize_key( basename( $plugin_file, '.php' ) );

            $icon_path = ltrim( trim( $custom['Icon Path'] ?? '' ), '/' );
            $icon_url  = '';
            if ( $icon_path ) {
                $icon_url = plugins_url( $icon_path, $full_path );
            }

            $info = [
                'plugin_file'  => $plugin_file,
                'plugin_slug'  => $slug,
                'name'         => $data['Name'] ?? $slug,
                'description'  => $data['Description'] ?? '',
                'author'       => $data['Author'] ?? 'BizCity',
                'version'      => $data['Version'] ?? '',
                'icon_url'     => $icon_url,
                'cover_url'    => trim( $custom['Cover URI'] ?? '' ),
                'credit'       => (int) ( $custom['Credit'] ?? 0 ),
                'category'     => trim( $custom['Category'] ?? '' ),
            ];

            if ( $template_page ) {
                $map['template_page'][ $template_page ] = $info;
            }
            if ( $admin_slug ) {
                $map['admin_slug'][ $admin_slug ] = $info;
            }
        }

        return $map;
    }

    /* ── Render: Admin Guard Card (inside WP admin frame) ──── */

    private static function render_admin_guard_card( array $info ): void {
        $name      = esc_html( $info['name'] );
        $desc      = esc_html( $info['description'] );
        $author    = esc_html( $info['author'] );
        $icon      = esc_url( $info['icon_url'] );
        $cover     = esc_url( $info['cover_url'] ?: $info['icon_url'] );
        $slug      = esc_attr( $info['plugin_slug'] );
        $credit    = (int) $info['credit'];
        $category  = esc_html( $info['category'] );
        $nonce     = wp_create_nonce( 'bizcity_market_nonce' );

        // Lấy thêm thông tin từ catalog DB (nếu có)
        $catalog_info = null;
        if ( class_exists( 'BizCity_Market_Catalog' ) ) {
            $catalog_info = BizCity_Market_Catalog::get( $info['plugin_slug'] );
        }
        if ( $catalog_info ) {
            if ( ! empty( $catalog_info->image_url ) && ! $cover ) {
                $cover = esc_url( $catalog_info->image_url );
            }
            if ( ! empty( $catalog_info->quickview ) ) {
                $desc = esc_html( $catalog_info->quickview );
            }
        }
        ?>
        <div class="wrap">
        <style>
        .bc-guard-card{max-width:520px;margin:40px auto;background:#fff;border-radius:18px;box-shadow:0 4px 28px rgba(0,0,0,.08);overflow:hidden}
        .bc-guard-cover{width:100%;height:200px;background-size:cover;background-position:center;background-color:#e1e5ea;position:relative}
        .bc-guard-icon{width:60px;height:60px;border-radius:14px;position:absolute;bottom:-30px;left:24px;border:3px solid #fff;background:#fff;box-shadow:0 2px 8px rgba(0,0,0,.1);object-fit:cover}
        .bc-guard-content{padding:42px 24px 28px}
        .bc-guard-title{font-size:20px;font-weight:800;color:#1a1a1a;margin-bottom:4px}
        .bc-guard-meta{font-size:13px;color:#64748b;margin-bottom:14px;display:flex;flex-wrap:wrap;gap:4px}
        .bc-guard-meta span+span::before{content:' · '}
        .bc-guard-desc{font-size:14px;color:#334155;line-height:1.65;margin-bottom:18px}
        .bc-guard-status{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:10px;background:#fff3cd;color:#856404;font-size:13px;font-weight:600;margin-bottom:18px}
        .bc-guard-actions{display:flex;gap:10px;flex-wrap:wrap}
        .bc-guard-btn{padding:10px 22px;border-radius:10px;font-size:14px;font-weight:700;cursor:pointer;border:1px solid transparent;transition:all .2s;text-decoration:none;display:inline-flex;align-items:center;gap:6px}
        .bc-guard-btn-primary{background:#2271b1;color:#fff}
        .bc-guard-btn-primary:hover{background:#135e96}
        .bc-guard-btn-primary:disabled{opacity:.6;cursor:wait}
        .bc-guard-msg{margin-top:14px;padding:10px 14px;border-radius:10px;font-size:13px;display:none}
        .bc-guard-msg.is-success{display:block;background:#d4edda;color:#155724}
        .bc-guard-msg.is-error{display:block;background:#f8d7da;color:#721c24}
        </style>

        <div class="bc-guard-card">
            <?php if ( $cover ) : ?>
            <div class="bc-guard-cover" style="background-image:url('<?php echo $cover; ?>')">
                <?php if ( $icon ) : ?>
                <img class="bc-guard-icon" src="<?php echo $icon; ?>" alt="<?php echo $name; ?>">
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="bc-guard-content">
                <div class="bc-guard-title"><?php echo $name; ?></div>
                <div class="bc-guard-meta">
                    <span><?php echo $author; ?></span>
                    <?php if ( $category ) : ?><span><?php echo $category; ?></span><?php endif; ?>
                    <?php if ( $credit > 0 ) : ?>
                        <span><?php echo $credit; ?> credit/lần</span>
                    <?php else : ?>
                        <span>Miễn phí</span>
                    <?php endif; ?>
                </div>

                <div class="bc-guard-desc"><?php echo $desc; ?></div>

                <div class="bc-guard-status">⚠️ Ứng dụng này chưa được kích hoạt</div>

                <div class="bc-guard-actions">
                    <button type="button" class="bc-guard-btn bc-guard-btn-primary" id="bc-guard-activate"
                            data-slug="<?php echo $slug; ?>">
                        ⚡ Kích hoạt ngay
                    </button>
                </div>

                <div class="bc-guard-msg" id="bc-guard-msg"></div>
            </div>
        </div>
        </div>

        <script>
        (function(){
            var btn=document.getElementById('bc-guard-activate');
            var msg=document.getElementById('bc-guard-msg');
            if(!btn)return;
            btn.addEventListener('click',function(){
                btn.disabled=true;
                btn.textContent='Đang kích hoạt...';
                msg.className='bc-guard-msg';
                msg.style.display='none';
                var fd=new FormData();
                fd.append('action','bizcity_market_activate_plugin');
                fd.append('nonce',<?php echo wp_json_encode( $nonce ); ?>);
                fd.append('plugin_slug',<?php echo wp_json_encode( $info['plugin_slug'] ); ?>);
                fetch(<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,{
                    method:'POST',credentials:'same-origin',body:fd
                })
                .then(function(r){return r.json();})
                .then(function(res){
                    if(res&&res.ok){
                        msg.className='bc-guard-msg is-success';
                        msg.textContent='✅ '+(res.msg||'Kích hoạt thành công!');
                        msg.style.display='block';
                        setTimeout(function(){location.reload();},1200);
                    }else{
                        msg.className='bc-guard-msg is-error';
                        msg.textContent='❌ '+(res&&res.msg?res.msg:'Kích hoạt thất bại');
                        msg.style.display='block';
                        btn.disabled=false;btn.textContent='⚡ Kích hoạt ngay';
                    }
                })
                .catch(function(){
                    msg.className='bc-guard-msg is-error';
                    msg.textContent='❌ Lỗi kết nối. Vui lòng thử lại.';
                    msg.style.display='block';
                    btn.disabled=false;btn.textContent='⚡ Kích hoạt ngay';
                });
            });
        })();
        </script>
        <?php
    }

    /* ── Render: Frontend Admin Activation (full page) ──────── */

    private static function render_admin_activation_page( array $info ): void {
        status_header( 200 );

        $name      = esc_html( $info['name'] );
        $desc      = esc_html( $info['description'] );
        $author    = esc_html( $info['author'] );
        $icon      = esc_url( $info['icon_url'] );
        $cover     = esc_url( $info['cover_url'] ?: $info['icon_url'] );
        $slug      = esc_attr( $info['plugin_slug'] );
        $credit    = (int) $info['credit'];
        $category  = esc_html( $info['category'] );
        $blog_name = esc_html( get_bloginfo( 'name' ) );
        $nonce     = wp_create_nonce( 'bizcity_market_nonce' );

        // Lấy thêm thông tin từ catalog DB (nếu có)
        $catalog_info = null;
        if ( class_exists( 'BizCity_Market_Catalog' ) ) {
            $catalog_info = BizCity_Market_Catalog::get( $info['plugin_slug'] );
        }
        if ( $catalog_info ) {
            if ( ! empty( $catalog_info->image_url ) && ! $cover ) {
                $cover = esc_url( $catalog_info->image_url );
            }
            if ( ! empty( $catalog_info->quickview ) ) {
                $desc = esc_html( $catalog_info->quickview );
            }
        }
        ?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo $name; ?> — <?php echo $blog_name; ?></title>
<?php wp_head(); ?>
<style>
body.bc-guard-body{margin:0;padding:0;background:#f0f2f5;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh}
body.bc-guard-body>*:not(.bc-guard-wrap):not(script):not(style):not(link):not(noscript){display:none!important}
.bc-guard-wrap{max-width:460px;width:92%;background:#fff;border-radius:18px;box-shadow:0 4px 28px rgba(0,0,0,.08);overflow:hidden}
.bc-guard-cover{width:100%;height:200px;background-size:cover;background-position:center;background-color:#e1e5ea;position:relative}
.bc-guard-icon{width:60px;height:60px;border-radius:14px;position:absolute;bottom:-30px;left:24px;border:3px solid #fff;background:#fff;box-shadow:0 2px 8px rgba(0,0,0,.1);object-fit:cover}
.bc-guard-content{padding:42px 24px 28px}
.bc-guard-title{font-size:20px;font-weight:800;color:#1a1a1a;margin-bottom:4px}
.bc-guard-meta{font-size:13px;color:#64748b;margin-bottom:14px;display:flex;flex-wrap:wrap;gap:4px}
.bc-guard-meta span+span::before{content:' · '}
.bc-guard-desc{font-size:14px;color:#334155;line-height:1.65;margin-bottom:18px}
.bc-guard-status{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:10px;background:#fff3cd;color:#856404;font-size:13px;font-weight:600;margin-bottom:18px}
.bc-guard-actions{display:flex;gap:10px;flex-wrap:wrap}
.bc-guard-btn{padding:10px 22px;border-radius:10px;font-size:14px;font-weight:700;cursor:pointer;border:1px solid transparent;transition:all .2s;text-decoration:none;display:inline-flex;align-items:center;gap:6px}
.bc-guard-btn-primary{background:#2271b1;color:#fff}
.bc-guard-btn-primary:hover{background:#135e96}
.bc-guard-btn-primary:disabled{opacity:.6;cursor:wait}
.bc-guard-btn-secondary{background:#f0f0f1;color:#2c3338;border-color:#c3c4c7}
.bc-guard-btn-secondary:hover{background:#e0e0e1}
.bc-guard-msg{margin-top:14px;padding:10px 14px;border-radius:10px;font-size:13px;display:none}
.bc-guard-msg.is-success{display:block;background:#d4edda;color:#155724}
.bc-guard-msg.is-error{display:block;background:#f8d7da;color:#721c24}
@media(max-width:480px){.bc-guard-cover{height:150px}.bc-guard-content{padding:38px 18px 22px}.bc-guard-title{font-size:18px}}
</style>
</head>
<body class="bc-guard-body">
<div class="bc-guard-wrap">
    <?php if ( $cover ) : ?>
    <div class="bc-guard-cover" style="background-image:url('<?php echo $cover; ?>')">
        <?php if ( $icon ) : ?>
        <img class="bc-guard-icon" src="<?php echo $icon; ?>" alt="<?php echo $name; ?>">
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="bc-guard-content">
        <div class="bc-guard-title"><?php echo $name; ?></div>
        <div class="bc-guard-meta">
            <span><?php echo $author; ?></span>
            <?php if ( $category ) : ?><span><?php echo $category; ?></span><?php endif; ?>
            <?php if ( $credit > 0 ) : ?>
                <span><?php echo $credit; ?> credit/lần</span>
            <?php else : ?>
                <span>Miễn phí</span>
            <?php endif; ?>
        </div>

        <div class="bc-guard-desc"><?php echo $desc; ?></div>

        <div class="bc-guard-status">⚠️ Ứng dụng này chưa được kích hoạt</div>

        <div class="bc-guard-actions">
            <button type="button" class="bc-guard-btn bc-guard-btn-primary" id="bc-guard-activate"
                    data-slug="<?php echo $slug; ?>">
                ⚡ Kích hoạt ngay
            </button>
            <a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="bc-guard-btn bc-guard-btn-secondary">
                ← Quay lại
            </a>
        </div>

        <div class="bc-guard-msg" id="bc-guard-msg"></div>
    </div>
</div>

<script>
(function(){
    var btn=document.getElementById('bc-guard-activate');
    var msg=document.getElementById('bc-guard-msg');
    if(!btn)return;
    btn.addEventListener('click',function(){
        btn.disabled=true;
        btn.textContent='Đang kích hoạt...';
        msg.className='bc-guard-msg';
        msg.style.display='none';
        var fd=new FormData();
        fd.append('action','bizcity_market_activate_plugin');
        fd.append('nonce',<?php echo wp_json_encode( $nonce ); ?>);
        fd.append('plugin_slug',<?php echo wp_json_encode( $info['plugin_slug'] ); ?>);
        fetch(<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,{
            method:'POST',credentials:'same-origin',body:fd
        })
        .then(function(r){return r.json();})
        .then(function(res){
            if(res&&res.ok){
                msg.className='bc-guard-msg is-success';
                msg.textContent='✅ '+(res.msg||'Kích hoạt thành công!');
                msg.style.display='block';
                setTimeout(function(){location.reload();},1200);
            }else{
                msg.className='bc-guard-msg is-error';
                msg.textContent='❌ '+(res&&res.msg?res.msg:'Kích hoạt thất bại');
                msg.style.display='block';
                btn.disabled=false;btn.textContent='⚡ Kích hoạt ngay';
            }
        })
        .catch(function(){
            msg.className='bc-guard-msg is-error';
            msg.textContent='❌ Lỗi kết nối. Vui lòng thử lại.';
            msg.style.display='block';
            btn.disabled=false;btn.textContent='⚡ Kích hoạt ngay';
        });
    });
})();
</script>
<?php wp_footer(); ?>
</body>
</html>
        <?php
    }

    /* ── Render: Access Denied ───────────────────────────────── */

    private static function render_access_denied(): void {
        status_header( 403 );
        $blog_name = esc_html( get_bloginfo( 'name' ) );
        ?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Không khả dụng — <?php echo $blog_name; ?></title>
<style>
body{margin:0;padding:40px 20px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;background:#f0f2f5}
.bc-denied{max-width:400px;text-align:center;background:#fff;padding:40px 30px;border-radius:18px;box-shadow:0 4px 24px rgba(0,0,0,.08)}
.bc-denied h2{font-size:20px;color:#1a1a1a;margin:0 0 8px}
.bc-denied p{font-size:14px;color:#64748b;margin:0 0 22px;line-height:1.6}
.bc-denied a{display:inline-block;padding:10px 22px;background:#2271b1;color:#fff;text-decoration:none;border-radius:10px;font-weight:700;font-size:14px}
.bc-denied a:hover{background:#135e96}
</style>
</head>
<body>
<div class="bc-denied">
    <h2>Tính năng chưa khả dụng</h2>
    <p>Ứng dụng này hiện không hoạt động. Vui lòng liên hệ quản trị viên để được hỗ trợ.</p>
    <a href="<?php echo esc_url( home_url( '/' ) ); ?>">← Quay lại trang chủ</a>
</div>
</body>
</html>
        <?php
    }

    /* ── Cache ───────────────────────────────────────────────── */

    public static function clear_cache(): void {
        delete_transient( 'bizcity_plugin_guard_map' );
    }
}
