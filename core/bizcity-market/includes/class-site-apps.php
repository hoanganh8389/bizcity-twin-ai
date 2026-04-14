<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\BizCity_Market
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

if (!defined('ABSPATH')) exit;

class BizCity_Market_Site_Apps {

    const PAGE_SLUG = 'bizcity-site-apps';
    const DASH_WIDGET_ID = 'bizcity_apps_dashboard';
    const NONCE_ACTION = 'bizcity_site_apps_save_action';

    public static function boot() {
        // Menu registration moved to BizCity_Admin_Menu (centralized).
        add_action('admin_init', [__CLASS__, 'assets_register']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'assets_enqueue'], 20);

        add_action('wp_dashboard_setup', [__CLASS__, 'dashboard_widget_register']);
        add_action('admin_post_bizcity_save_site_apps', [__CLASS__, 'handle_save_site_apps']);

        // Notice sau khi lưu từ dashboard
        add_action('admin_notices', [__CLASS__, 'admin_notice_saved']);
    }

    /** Optional: menu page (anh đang comment trước đó, em để ON để đồng nhất) */
    public static function menu() {
        if (!current_user_can('manage_options')) return;

        // submenu dưới Dashboard (index.php)
        add_submenu_page(
            'index.php',
            'Ứng dụng mặc định',
            'Ứng dụng mặc định',
            'manage_options',
            self::PAGE_SLUG,
            [__CLASS__, 'render_site_apps_page'],
            61
        );
    }

    // ====== ASSETS ======

    public static function assets_register() {
        wp_register_style('bizcity-apps-ui', false, [], '1.0.0');
        wp_register_script('bizcity-apps-ui', false, ['jquery', 'jquery-ui-sortable'], '1.0.0', true);
    }

    public static function assets_enqueue($hook) {
        $is_dashboard = ($hook === 'index.php');
        $is_site_apps_page = (!empty($_GET['page']) && $_GET['page'] === self::PAGE_SLUG);

        if (!$is_dashboard && !$is_site_apps_page) return;

        wp_enqueue_style('bizcity-apps-ui');
        wp_enqueue_script('bizcity-apps-ui');

        // CSS y nguyên đoạn anh đưa (để không vỡ UI)
        $css = self::get_css_fullwidth();
        wp_add_inline_style('bizcity-apps-ui', $css);

        // JS y nguyên đoạn anh đưa (sync hidden JSON + stopPropagation link)
        $js = self::get_js_fullwidth();
        wp_add_inline_script('bizcity-apps-ui', $js, 'after');
    }

    protected static function get_css_fullwidth() : string {
        return <<<CSS
/* ===== BizCity Apps UI (Fullwidth) ===== */
/* ===== Fix Dashicons bị mất khi override font ===== */
.bzod .dashicons,
.bzod .dashicons:before,
.bzod .dashicons:after{
  font-family: dashicons !important;
  font-weight: 400 !important;
  font-style: normal !important;
  speak: never;
  text-transform: none;
  line-height: 1;
}

/* safer font override (trừ dashicons) */
.bzod :not(.dashicons):not(.dashicons-before):not(.dashicons-after),
.bzod :not(.dashicons):not(.dashicons-before):not(.dashicons-after)::before,
.bzod :not(.dashicons):not(.dashicons-before):not(.dashicons-after)::after{
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto,
               Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", Arial,
               "Noto Sans", "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol",
               sans-serif !important;
}
.bizcity-apps-wrap{max-width:none;width:100%}
.bzod.bzod-full{max-width:none;;border-radius:14px;padding:18px;background:#fff;border:1px solid rgba(0,0,0,.07)}
.bzod-full .bzod-head{margin-bottom:10px}
.bzod-full .bzod-title{font-size:20px;font-weight:900;margin:0}
.bzod-full .bzod-sub{margin-top:4px;color:#667085}

.bzod-full .bzod-section{margin:14px 0 18px}
.bzod-full .bzod-section-title{font-size:14px;font-weight:900;margin:0 0 10px;color:#888}

/* ===== Grid 5 cột ===== */
.bzod-full .bzod-grid{
  display:grid;
  grid-template-columns:repeat(5,minmax(0,1fr));
  gap:12px;
}
@media(max-width:1100px){
  .bzod-full .bzod-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
}
@media(max-width:720px){
  .bzod-full .bzod-grid{grid-template-columns:1fr}
}

.bzod-full .bzod-card{display:block}
.bzod-full .bzod-card input{display:none}
.bzod-full .bzod-card-inner{
  border:1px solid rgba(0,0,0,.08);
  border-radius:12px;
  padding:12px;
  background:#fff;
  cursor:pointer;
  transition:.12s;
  display:flex;
  align-items:center;
  gap:12px;
  min-height:64px;
}

.bzod-full .bzod-meta{min-width:0;flex:1}
.bzod-full .bzod-name{font-weight:500;line-height:1.5}
.bzod-full .bzod-desc{display:none;}
.bzod-full .bzod-file{margin-top:4px;font-size:11px;color:#667085}
.bzod-full code{font-size:11px}

.bzod-full .bzod-right{display:flex;align-items:center;gap:8px;flex:0 0 auto;}
.bzod-full .bzod-open{display:none;}
.bzod-full .bzod-card-inner:hover{transform:translateY(-1px);box-shadow:0 10px 18px rgba(0,0,0,.06)}
.bzod-full .bzod-ico{
  width:38px;height:38px;border-radius:10px;background:#F2F4F7;
  display:flex;align-items:center;justify-content:center;flex:0 0 auto;
}
.bzod-full .bzod-status{font-size:11px;font-weight:800;opacity:.6}
.bzod-full .bzod-desc{margin-top:4px;color:#667085;font-size:12px;line-height:1.25}

.bzod-full .bzod-tick{display:none;color:#12B76A}
.bzod-full .bzod-card input:checked + .bzod-card-inner{border-color:#7F56D9;box-shadow:0 0 0 3px rgba(127,86,217,.12)}
.bzod-full .bzod-card input:checked + .bzod-card-inner .bzod-tick{display:inline-block}
.bzod-full .bzod-card input:disabled + .bzod-card-inner{opacity:.85;cursor:not-allowed}

/* Launch link on name/icon */
.bzod-full .bzod-launch{display:flex;align-items:center;gap:12px;text-decoration:none;color:inherit}
.bzod-full .bzod-launch:hover{color:inherit}
.bzod-full .bzod-pill{
  font-size:11px;border:1px solid rgba(0,0,0,.12);
  border-radius:999px;padding:3px 8px;opacity:.7
}
a.bzod-launch .bzod-name{ text-decoration: underline;}
a.bzod-launch .dashicons-admin-links{ color: #ccc; font-size: 14px; vertical-align: middle; margin-left: 4px; text-decoration: none}

/* Dashboard full width forcing */
body.index-php #dashboard-widgets .postbox-container{width:100%!important;float:none!important}
body.index-php #dashboard-widgets .meta-box-sortables{min-height:auto}
CSS;
    }

    protected static function get_js_fullwidth() : string {
        return <<<JS
(function($){
  function initBizCityApps(root){
    if(!root) return;
    var \$root = $(root);
    var \$checks = \$root.find('.bzod-check');
    var \$hidden = \$root.closest('form').find('input[name="bizcity_selected_apps_json"], #bizcity_selected_apps_json');

    // stop click label toggle when clicking link
    \$root.on('click', 'a.bzod-launch, a.bzod-open', function(e){
      e.preventDefault();
      e.stopPropagation();
      window.location.href = this.href;
    });

    function selectedKeys(){
      var keys = [];
      \$checks.each(function(){
        if(this.checked) keys.push(this.value);
      });
      return keys;
    }
    function sync(){
      var keys = selectedKeys();
      if(\$hidden.length) \$hidden.val(JSON.stringify(keys));
    }
    \$checks.on('change', sync);
    sync();
  }

  $(function(){
    $('.bzod.bzod-full').each(function(){ initBizCityApps(this); });
  });
})(jQuery);
JS;
    }

    // ====== PAGE / DASHBOARD ======

    public static function render_site_apps_page() {
        if (!current_user_can('manage_options')) wp_die('No permission');

        self::ensure_catalog_seeded();

        $catalog = BizCity_Market_Network_Admin::get_catalog();
        $groups  = BizCity_Market_Network_Admin::group_by_category($catalog);

        // SAVE trực tiếp từ page
        if (isset($_POST['bizcity_site_apps_save']) && check_admin_referer(self::NONCE_ACTION)) {
            $arr = self::parse_selected_apps_from_post($_POST['bizcity_selected_apps'] ?? '[]');

            $map = self::catalog_map_by_key($catalog);
            $result = self::apply_selection_to_site($arr, $map);

            if (!empty($result['skipped'])) {
                $msg = 'Đã lưu. Một số app bị bỏ qua (chưa được cấp quyền hoặc thiếu file).';
                add_settings_error('bizcity_site_apps', 'saved_partial', $msg, 'warning');
            } else {
                add_settings_error('bizcity_site_apps', 'saved_ok', 'Đã lưu cấu hình Apps cho site.', 'updated');
            }
        }

        [$selected, $active_plugins, $catalog_map] = self::get_apps_state($catalog);

        echo '<div class="wrap">';
        echo '<h1>Danh sách các ứng dụng mặc định có sẵn</h1>';

        settings_errors('bizcity_site_apps');

        echo '<form method="post">';
        wp_nonce_field(self::NONCE_ACTION);

        self::render_picker_fullwidth($groups, $selected, $catalog_map, $active_plugins, $catalog);

        echo '<input type="hidden" name="bizcity_selected_apps" id="bizcity_selected_apps_json" value="'.esc_attr(wp_json_encode($selected)).'">';
        echo '<p style="margin-top:12px"><button type="submit" class="button button-primary" name="bizcity_site_apps_save" value="1">Lưu</button></p>';

        echo '</form></div>';
    }

    public static function dashboard_widget_register() {
        if (!current_user_can('manage_options')) return;

        wp_add_dashboard_widget(
            self::DASH_WIDGET_ID,
            'Danh sách các Ứng dụng mặc định có sẵn',
            [__CLASS__, 'dashboard_widget_render']
        );
    }

    public static function dashboard_widget_render() {
        self::ensure_catalog_seeded();

        $catalog = BizCity_Market_Network_Admin::get_catalog();
        $groups  = BizCity_Market_Network_Admin::group_by_category($catalog);

        [$selected, $active_plugins, $catalog_map] = self::get_apps_state($catalog);

        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
          <?php wp_nonce_field(self::NONCE_ACTION); ?>
          <input type="hidden" name="action" value="bizcity_save_site_apps">
          <input type="hidden" name="bizcity_selected_apps_json" id="bizcity_selected_apps_json" value="<?php echo esc_attr(wp_json_encode($selected)); ?>">

          <p style="margin-top:12px">
            <button type="submit" class="button button-primary">Lưu lựa chọn</button>
          </p>

          <?php self::render_picker_fullwidth($groups, $selected, $catalog_map, $active_plugins, $catalog); ?>

          <p style="margin-top:12px">
            <button type="submit" class="button button-primary">Lưu lựa chọn</button>
          </p>
        </form>
        <?php
    }

    public static function handle_save_site_apps() {
        if (!current_user_can('manage_options')) wp_die('No permission');
        check_admin_referer(self::NONCE_ACTION);

        self::ensure_catalog_seeded();

        $catalog = BizCity_Market_Network_Admin::get_catalog();
        $map = self::catalog_map_by_key($catalog);

        $arr = self::parse_selected_apps_from_post($_POST['bizcity_selected_apps_json'] ?? '[]');

        $result = self::apply_selection_to_site($arr, $map);

        // redirect with flags
        $qs = [
            'bizcity_apps_updated' => 1,
            'bizcity_apps_skipped' => !empty($result['skipped']) ? count($result['skipped']) : 0,
        ];
        wp_safe_redirect(add_query_arg($qs, admin_url('index.php')));
        exit;
    }

    public static function admin_notice_saved() {
        if (empty($_GET['bizcity_apps_updated'])) return;
        if (!current_user_can('manage_options')) return;

        $skipped = isset($_GET['bizcity_apps_skipped']) ? (int)$_GET['bizcity_apps_skipped'] : 0;

        if ($skipped > 0) {
            echo '<div class="notice notice-warning is-dismissible"><p><strong>Đã lưu lựa chọn Apps.</strong> Có '.$skipped.' app bị bỏ qua (chưa được cấp quyền hoặc thiếu file).</p></div>';
        } else {
            echo '<div class="notice notice-success is-dismissible"><p><strong>Đã lưu lựa chọn Apps.</strong></p></div>';
        }
    }

    // ====== CORE LOGIC ======

    protected static function ensure_catalog_seeded() {
        // dùng seed của network admin
        if (class_exists('BizCity_Market_Network_Admin')) {
            // nếu anh seed bằng method khác thì gọi vào đây
            BizCity_Market_Network_Admin::seed_if_empty();
        }
    }

    protected static function parse_selected_apps_from_post($raw) : array {
        if (is_string($raw)) {
            $raw = wp_unslash($raw);
            $raw = trim($raw);
        }

        if (is_array($raw)) {
            $arr = $raw;
        } else {
            $arr = json_decode($raw ?: '[]', true);
            if (!is_array($arr)) $arr = [];
        }

        $arr = array_values(array_unique(array_filter(array_map(function($k){
            $k = is_string($k) ? sanitize_key($k) : '';
            return $k;
        }, $arr))));

        if (!in_array('website', $arr, true)) $arr[] = 'website';
        return $arr;
    }

    protected static function catalog_map_by_key(array $catalog) : array {
        $map = [];
        foreach ($catalog as $a) {
            if (!empty($a['key'])) $map[$a['key']] = $a;
        }
        return $map;
    }

    /** merge state: site option + active_plugins + catalog */
    public static function get_apps_state(array $catalog) : array {
        $stored = get_option('bizcity_selected_apps', []);
        $stored = is_array($stored) ? $stored : [];

        $active_plugins = (array)get_option('active_plugins', []);

        $map = self::catalog_map_by_key($catalog);

        $selected = $stored;

        // auto tick theo active plugin
        foreach ($map as $key => $app) {
            $pf = $app['plugin_file'] ?? '';
            if ($pf && in_array($pf, $active_plugins, true)) {
                $selected[] = $key;
            }
        }

        $selected = array_values(array_unique(array_filter(array_map('sanitize_key', $selected))));
        if (!in_array('website', $selected, true)) $selected[] = 'website';

        return [$selected, $active_plugins, $map];
    }

    /**
     * Apply selection: chỉ quản plugin thuộc catalog, không đụng plugin khác
     * + Check entitlement (nếu có class Entitlements)
     */
    public static function apply_selection_to_site(array $selected_keys, array $catalog_map) : array {
        $selected_keys = array_values(array_unique(array_filter(array_map('sanitize_key', (array)$selected_keys))));
        if (!in_array('website', $selected_keys, true)) $selected_keys[] = 'website';

        $active = (array)get_option('active_plugins', []);

        // managed files
        $managed_files = [];
        foreach ($catalog_map as $app) {
            $pf = $app['plugin_file'] ?? '';
            if ($pf) $managed_files[] = $pf;
        }
        $managed_files = array_values(array_unique($managed_files));

        // target files by selected keys
        $target_files = [];
        foreach ($selected_keys as $k) {
            $app = $catalog_map[$k] ?? null;
            if (!$app) continue;
            $pf = $app['plugin_file'] ?? '';
            if ($pf) $target_files[] = $pf;
        }
        $target_files = array_values(array_unique($target_files));

        // 1) remove managed but not in target
        $to_remove = array_diff($managed_files, $target_files);
        if (!empty($to_remove)) {
            $active = array_values(array_diff($active, $to_remove));
        }

        // 2) add target (validate file + entitlement)
        $skipped = [];
        $blog_id = function_exists('get_current_blog_id') ? (int)get_current_blog_id() : 1;

        foreach ($target_files as $pf) {
            $full = WP_PLUGIN_DIR . '/' . $pf;
            if (!file_exists($full)) {
                $skipped[] = ['plugin_file' => $pf, 'reason' => 'missing_file'];
                error_log('[BizCity][Market][SiteApps] missing plugin file: ' . $pf . ' blog_id=' . $blog_id);
                continue;
            }

            // entitlement check (nếu gate đang bật)
            if (class_exists('BizCity_Market_Entitlements')) {
                $slug = BizCity_Market_Entitlements::plugin_slug_from_file($pf);
                if (!BizCity_Market_Entitlements::has($blog_id, $slug, 'plugin')) {
                    $skipped[] = ['plugin_file' => $pf, 'reason' => 'no_entitlement'];
                    continue;
                }
            }

            if (!in_array($pf, $active, true)) $active[] = $pf;
        }

        $active = array_values(array_unique($active));
        update_option('active_plugins', $active);

        // save selected
        update_option('bizcity_selected_apps', $selected_keys, true);
        update_option('bizcity_selected_apps_updated', time(), true);

        wp_cache_flush();

        return [
            'ok' => true,
            'skipped' => $skipped,
        ];
    }

    // ====== UI RENDER (full width) ======

    public static function render_picker_fullwidth($groups, $selected, $catalog_map, $active_plugins, $catalog = []) {
        $selected = array_values(array_unique(array_filter((array)$selected)));
        if (!in_array('website', $selected, true)) $selected[] = 'website';

        // sort groups theo thứ tự category xuất hiện trong catalog (động)
        $groups = self::sort_groups_by_catalog_order($catalog, (array)$groups);

        ?>
        <div class="bizcity-apps-wrap">
          <div class="bzod bzod-full">
            <div class="bzod-head">
              <div class="bzod-sub">Tick để bật/tắt. Các plugin ngoài catalog sẽ không bị ảnh hưởng.</div>
            </div>

            <?php foreach ($groups as $cat => $apps): ?>
              <div class="bzod-section" data-cat="<?php echo esc_attr($cat); ?>">
                <div class="bzod-section-title"><?php echo esc_html($cat); ?></div>

                <div class="bzod-grid">
                  <?php foreach ((array)$apps as $app):
                      $key = $app['key'] ?? '';
                      if (!$key) continue;

                      $name  = $app['name'] ?? $key;
                      $icon  = $app['icon'] ?? 'dashicons-admin-plugins';
                      $link  = $app['link'] ?? '';
                      $pf    = $app['plugin_file'] ?? '';

                      $is_core  = !empty($app['is_core']) || $key === 'website';
                      $checked  = in_array($key, $selected, true);

                      $is_active = ($pf && in_array($pf, (array)$active_plugins, true));
                      $status = $is_core ? 'Core' : ($is_active ? 'Đang bật' : 'Đang tắt');

                      // build open url (chỉ cho mở khi active)
                      $open_url = '#';
                      if (!empty($link) && $is_active) {
                          if (preg_match('#^https?://#i', $link)) $open_url = $link;
                          else $open_url = admin_url(ltrim($link, '/'));
                      }
                  ?>
                    <label class="bzod-card" data-key="<?php echo esc_attr($key); ?>">
                      <input class="bzod-check" type="checkbox"
                             value="<?php echo esc_attr($key); ?>"
                             <?php echo $checked ? 'checked' : ''; ?>
                             <?php echo $is_core ? 'disabled' : ''; ?> />

                      <div class="bzod-card-inner">
                        <?php if ($open_url !== '#'): ?>
                            <a class="bzod-launch" href="<?php echo esc_url($open_url); ?>" title="Mở ứng dụng">
                              <div class="bzod-ico"><span class="dashicons <?php echo esc_attr($icon); ?>"></span></div>
                            </a>
                            <div class="bzod-meta">
                              <a class="bzod-launch" href="<?php echo esc_url($open_url); ?>" title="Mở ứng dụng">
                                <div class="bzod-name"><?php echo esc_html($name); ?><span class="dashicons dashicons-admin-links"></span></div>
                              </a>
                              <div class="bzod-file"><?php echo esc_html($status); ?></div>
                            </div>
                        <?php else: ?>
                            <div class="bzod-ico"><span class="dashicons <?php echo esc_attr($icon); ?>"></span></div>
                            <div class="bzod-meta">
                              <div class="bzod-name"><?php echo esc_html($name); ?></div>
                              <div class="bzod-file"><?php echo esc_html($status); ?></div>
                            </div>
                        <?php endif; ?>

                        <div class="bzod-right">
                          <span class="bzod-tick dashicons dashicons-yes-alt"></span>
                        </div>
                      </div>
                    </label>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endforeach; ?>

          </div>
        </div>
        <?php
    }

    protected static function sort_groups_by_catalog_order(array $catalog, array $groups) : array {
        if (empty($catalog)) return $groups;

        $cat_order = [];
        foreach ($catalog as $a) {
            $cat = $a['category'] ?? 'Khác';
            if (!isset($cat_order[$cat])) $cat_order[$cat] = count($cat_order);
        }

        uksort($groups, function($a,$b) use ($cat_order){
            $ia = $cat_order[$a] ?? 9999;
            $ib = $cat_order[$b] ?? 9999;
            return $ia <=> $ib;
        });

        return $groups;
    }
}
