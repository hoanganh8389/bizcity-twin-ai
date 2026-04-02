<?php
if (!defined('ABSPATH')) exit;

/**
 * BizCity_Market_Catalog
 *
 * Lớp xử lý catalog plugin/theme trong hệ thống BizCity Market.
 * - Chịu trách nhiệm truy vấn danh sách, lấy chi tiết, upsert và xóa bản ghi.
 * - Tất cả input/output tương tác với DB đã được sanitize/validate cơ bản.
 *
 * Ghi chú chung:
 * - Không thực hiện echo/print trong class này, chỉ trả về dữ liệu để gọi ở controller/view.
 * - Các hàm trả về WP_Error khi gặp lỗi để caller xử lý thống nhất.
 */
class BizCity_Market_Catalog {

  /**
   * list($args = [])
   *
   * Mục đích:
   * - Trả về danh sách plugin theo điều kiện tìm kiếm/paging.
   *
   * Tham số (trong $args):
   * - q: từ khóa tìm kiếm (search trên title, plugin_slug, directory)
   * - page: trang (1-based)
   * - per: số bản ghi trên 1 trang (giới hạn 10..50)
   *
   * Trả về:
   * - ['rows' => array|null, 'total' => int, 'page' => int, 'per' => int]
   *
   * Chi tiết logic:
   * - Lấy đối tượng DB toàn cục thông qua BizCity_Market_DB::globaldb()
   * - Xây WHERE động khi có q; dùng esc_like + prepare để tránh SQL injection
   * - Tính tổng trước khi query dữ liệu (phục vụ paging)
   * - ORDER BY ưu tiên is_featured, sau đó sort_order, cuối cùng id DESC
   *
   * Lưu ý an toàn/hiệu năng:
   * - Giới hạn per tối đa 50 để tránh query quá nặng.
   * - Sử dụng prepared statements ($db->prepare) cho mọi tham số.
   */
  public static function list($args = []) {
    $db = BizCity_Market_DB::globaldb();
    if (!$db) {
        error_log( '[BizCity Market List] SKIP — globaldb() returned null' );
        return ['rows'=>[], 'total'=>0];
    }

    $t = BizCity_Market_DB::t_plugins();

    $q = trim((string)($args['q'] ?? ''));
    $page = max(1, (int)($args['page'] ?? 1));
    $per  = min(50, max(10, (int)($args['per'] ?? 20)));
    $off  = ($page - 1) * $per;
    $category = trim((string)($args['category'] ?? ''));

    $where = "WHERE is_active=1";
    $params = [];

    if ($q !== '') {
      // Sử dụng esc_like để escape wildcard và prepare để avoid SQL injection
      $where .= " AND (title LIKE %s OR plugin_slug LIKE %s OR directory LIKE %s)";
      $like = '%' . $db->esc_like($q) . '%';
      $params[] = $like; $params[] = $like; $params[] = $like;
    }

    if ($category !== '') {
      $where .= " AND category = %s";
      $params[] = $category;
    }

    // Lấy tổng số bản ghi phù hợp (dùng để paging)
    $sql_total = "SELECT COUNT(*) FROM {$t} {$where}";
    $total = (int)( $params ? $db->get_var( $db->prepare( $sql_total, $params ) ) : $db->get_var( $sql_total ) );

    // Lấy dữ liệu trang hiện tại
    $sql = "SELECT * FROM {$t} {$where} ORDER BY is_featured DESC, sort_order ASC, id DESC LIMIT %d OFFSET %d";
    $params2 = array_merge($params, [$per, $off]);
    $rows = $db->get_results($db->prepare($sql, $params2));

    return ['rows'=>$rows ?: [], 'total'=>$total, 'page'=>$page, 'per'=>$per];
  }

  /**
   * get($id_or_slug)
   *
   * Mục đích:
   * - Lấy chi tiết 1 bản ghi theo id (số) hoặc plugin_slug (chuỗi).
   *
   * Tham số:
   * - $id_or_slug: nếu numeric => tìm theo id, ngược lại tìm theo plugin_slug
   *
   * Trả về:
   * - WP row object nếu tìm thấy, null nếu không có hoặc DB chưa sẵn sàng.
   *
   * Ghi chú:
   * - sanitize_key cho plugin_slug để đảm bảo định dạng slug hợp lệ.
   */
  public static function get($id_or_slug) {
    $db = BizCity_Market_DB::globaldb();
    if (!$db) return null;
    $t = BizCity_Market_DB::t_plugins();

    if (is_numeric($id_or_slug)) {
      return $db->get_row($db->prepare("SELECT * FROM {$t} WHERE id=%d", (int)$id_or_slug));
    }
    // Trường hợp slug: sanitize_key để tránh ký tự không hợp lệ
    return $db->get_row($db->prepare("SELECT * FROM {$t} WHERE plugin_slug=%s", sanitize_key($id_or_slug)));
  }

  /**
   * upsert($data)
   *
   * Mục đích:
   * - Chèn mới hoặc cập nhật một bản ghi plugin.
   *
   * Tham số ($data) (các field chính):
   * - plugin_slug, plugin_file, directory, title, author_name, author_url, image_url, description
   * - credit_price, vnd_price, download_url, demo_url, after_active_url
   * - is_featured, is_active, sort_order
   * - id (nếu có => thực hiện update thay vì insert)
   *
   * Hành vi:
   * - Validate tối thiểu: plugin_slug, title, plugin_file bắt buộc
   * - sanitize/esc các trường để tránh lưu dữ liệu bẩn
   * - Nếu directory rỗng, sẽ derive từ plugin_file (dirname) hoặc fallback về plugin_slug
   * - Nếu id được truyền và >0 => update theo id, ngược lại insert mới và set created_at
   *
   * Trả về:
   * - id (int) khi thành công
   * - WP_Error khi lỗi (vd: globaldb_missing, invalid, db)
   *
   * Lưu ý bảo mật:
   * - Không cho phép SQL injection vì sử dụng WP DB methods ($db->insert/update)
   * - Không thực hiện phép kiểm tra business-logic khác (vd: duplicate slug) ở đây — caller có thể kiểm tra trước nếu cần.
   */
  public static function upsert($data) {
    $db = BizCity_Market_DB::globaldb();
    if (!$db) return new WP_Error('globaldb_missing', 'Global DB not ready');
    $t = BizCity_Market_DB::t_plugins();

    $row = [
        'plugin_slug' => sanitize_key($data['plugin_slug'] ?? ''),
        'plugin_file' => sanitize_text_field($data['plugin_file'] ?? ''),
        'directory'   => sanitize_text_field($data['directory'] ?? ''),
        'title'       => sanitize_text_field($data['title'] ?? ''),
        'author_name' => sanitize_text_field($data['author_name'] ?? ''),
        'author_url'  => esc_url_raw($data['author_url'] ?? ''),
        'image_url'   => esc_url_raw($data['image_url'] ?? ''),
        'icon_url'    => esc_url_raw($data['icon_url'] ?? ''),
        'quickview'    => sanitize_textarea_field($data['quickview'] ?? ''),

        'description' => wp_kses_post($data['description'] ?? ''),
        'credit_price'=> (int)($data['credit_price'] ?? 0),
        'vnd_price'   => (int)($data['vnd_price'] ?? 0),
        'download_url'=> esc_url_raw($data['download_url'] ?? ''),
        'demo_url'    => esc_url_raw($data['demo_url'] ?? ''),
        'after_active_url' => esc_url_raw($data['after_active_url'] ?? ''),
        'views'        => (int)($data['views'] ?? 0),
        'useful_score' => (float)($data['useful_score'] ?? 0),
        'useful_count' => (int)($data['useful_count'] ?? 0),
        'is_featured' => !empty($data['is_featured']) ? 1 : 0,
        'is_active'   => !empty($data['is_active']) ? 1 : 0,
        'sort_order'  => (int)($data['sort_order'] ?? 0),
        'category'    => sanitize_text_field($data['category'] ?? ''),
        'required_plan' => sanitize_text_field($data['required_plan'] ?? 'free'),
        'updated_at'  => current_time('mysql'),
    ];

    // Kiểm tra bắt buộc: nếu thiếu các trường chính
    if (!$row['plugin_slug'] || !$row['title'] || !$row['plugin_file']) {
      return new WP_Error('invalid', 'Thiếu plugin_slug / title / plugin_file');
    }

    // derive directory if missing
    if (!$row['directory']) {
      $dir = strtolower(trim(dirname($row['plugin_file']), '/'));
      $row['directory'] = ($dir === '.' ? $row['plugin_slug'] : $dir);
    }

    $id = isset($data['id']) ? (int)$data['id'] : 0;

    if ($id > 0) {
      $ok = (bool)$db->update($t, $row, ['id'=>$id]);
      return $ok ? $id : new WP_Error('db', 'Update failed');
    }

    $row['created_at'] = current_time('mysql');
    $ok = (bool)$db->insert($t, $row);
    return $ok ? (int)$db->insert_id : new WP_Error('db', 'Insert failed');
  }

  public static function delete($id) {
    $db = BizCity_Market_DB::globaldb();
    if (!$db) return false;
    $t = BizCity_Market_DB::t_plugins();
    return (bool)$db->delete($t, ['id'=>(int)$id]);
  }

  /**
   * sync_agent_plugins()
   *
   * Quét tất cả plugin đang active trên network, tìm các plugin có header
   * "Role: agent" hoặc "Role: tool" và tự động đăng ký vào bảng market_plugins.
   *
   * Headers được đọc:
   * - Role: agent|tool (bắt buộc — chỉ sync plugin có role hợp lệ)
   * - Icon Path: đường dẫn icon relative to plugin dir
   * - Credit: giá credit
   * - Price: giá VND
   * - Cover URI: ảnh bìa
   * - Template Page: đường dẫn template frontend relative to plugin dir
   *
   * Chỉ chạy 1 lần / ngày bằng transient cache.
   */
  public static function sync_agent_plugins( $force = false ) {
    // Throttle: chỉ chạy 1 lần / 24h (bump version to force re-sync)
    $sync_ver  = '3';  // ← tăng lên khi cần force re-sync
    $cache_key = 'bizcity_agent_plugins_synced_v' . $sync_ver;
    if ( ! $force && get_site_transient( $cache_key ) ) return;

    $db = BizCity_Market_DB::globaldb();
    if ( ! $db ) {
        error_log( '[BizCity Market Sync] SKIP — globaldb() returned null. $GLOBALS[globaldb] exists: '
            . ( isset( $GLOBALS['globaldb'] ) ? 'yes (class=' . get_class( $GLOBALS['globaldb'] ) . ')' : 'NO' ) );
        return;
    }

    if ( ! function_exists( 'get_plugins' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $all_plugins = get_plugins();
    error_log( '[BizCity Market Sync] Total plugins from get_plugins(): ' . count( $all_plugins ) );

    // Debug: list all plugin files to find agent candidates
    $agent_candidates = [];
    $extra_headers = [
        'Role'          => 'Role',
        'Icon Path'     => 'Icon Path',
        'Credit'        => 'Credit',
        'Price'         => 'Price',
        'Cover URI'     => 'Cover URI',
        'Template Page' => 'Template Page',
        'Category'      => 'Category',
        'Plan'          => 'Plan',
    ];

    $tP = BizCity_Market_DB::t_plugins();
    $tM = method_exists('BizCity_Market_DB', 't_plugins_meta') ? BizCity_Market_DB::t_plugins_meta() : '';
    $synced = 0;

    foreach ( $all_plugins as $plugin_file => $data ) {
        // Đọc custom headers từ file gốc
        $full_path = WP_PLUGIN_DIR . '/' . $plugin_file;
        if ( ! file_exists( $full_path ) ) {
            error_log( '[BizCity Market Sync] File not found on disk: ' . $full_path );
            continue;
        }

        $custom = get_file_data( $full_path, $extra_headers );
        $role = strtolower( trim( $custom['Role'] ?? '' ) );

        // Debug: log plugins that have Role header
        if ( $role ) {
            $agent_candidates[] = $plugin_file . ' (Role=' . $role . ')';
        }

        // Chỉ xử lý plugin có Role: agent hoặc tool
        if ( ! in_array( $role, [ 'agent', 'tool' ], true ) ) continue;

        $slug = sanitize_key( dirname( $plugin_file ) );
        if ( $slug === '.' ) $slug = sanitize_key( basename( $plugin_file, '.php' ) );

        // Kiểm tra đã tồn tại trong marketplace chưa
        $existing_id = $db->get_var( $db->prepare(
            "SELECT id FROM {$tP} WHERE plugin_slug = %s LIMIT 1",
            $slug
        ) );

        // Build icon URL
        $icon_path = trim( $custom['Icon Path'] ?? '' );
        $icon_path = ltrim( $icon_path, '/' );
        $icon_url = '';
        if ( $icon_path ) {
            $icon_url = plugins_url( $icon_path, $full_path );
        }

        // Build cover URL
        $cover = trim( $custom['Cover URI'] ?? '' );

        // Build data for upsert
        $category = sanitize_text_field( trim( $custom['Category'] ?? '' ) );

        $plugin_data = [
            'plugin_slug'  => $slug,
            'plugin_file'  => $plugin_file,
            'directory'    => sanitize_text_field( dirname( $plugin_file ) ),
            'title'        => sanitize_text_field( $data['Name'] ?? $slug ),
            'author_name'  => sanitize_text_field( $data['Author'] ?? 'BizCity' ),
            'author_url'   => esc_url_raw( $data['AuthorURI'] ?? '' ),
            'image_url'    => $cover ?: $icon_url,
            'icon_url'     => $icon_url,
            'quickview'    => sanitize_text_field( $data['Description'] ?? '' ),
            'description'  => wp_kses_post( $data['Description'] ?? '' ),
            'credit_price' => (int) ( $custom['Credit'] ?? 100 ),
            'vnd_price'    => (int) ( $custom['Price'] ?? 0 ),
            'is_active'    => 1,
            'sort_order'   => 0,
            'category'     => $category,
            'required_plan'=> sanitize_text_field( strtolower( trim( $custom['Plan'] ?? 'free' ) ) ),
        ];

        // Nếu đã tồn tại → update các field có thể thay đổi (cover, icon, giá, mô tả)
        if ( $existing_id ) {
            $plugin_data['id'] = (int) $existing_id;
        }

        self::upsert( $plugin_data );

        // ✅ Upsert global_plugins_meta (category sync)
        if ( ! $tM ) { $synced++; continue; } // skip meta if table helper missing
        $meta_exists = $db->get_var( $db->prepare(
            "SELECT id FROM {$tM} WHERE plugin_slug = %s LIMIT 1", $slug
        ) );
        if ( $meta_exists ) {
            $db->update( $tM, [
                'category'   => $category,
                'updated_at' => current_time('mysql'),
            ], [ 'plugin_slug' => $slug ] );
        } else {
            $db->insert( $tM, [
                'plugin_slug'    => $slug,
                'category'       => $category,
                'total_views'    => 0,
                'total_installs' => 0,
                'active_count'   => 0,
                'avg_rating'     => 0,
                'rating_count'   => 0,
                'updated_at'     => current_time('mysql'),
            ] );
        }

        $synced++;
    }

    /* ── Cleanup: xoá orphan records (plugin_file không còn tồn tại trên disk) ── */
    $all_db = $db->get_results( "SELECT id, plugin_slug, plugin_file FROM {$tP} WHERE is_active = 1" );
    $removed = 0;
    foreach ( $all_db as $entry ) {
        // Chỉ auto-cleanup plugin dạng thư mục (dir/file.php) — tức plugin từ sync_agent.
        // Bỏ qua single-file (sensei-lms.php, ...) vì đó là catalog thêm tay.
        if ( strpos( $entry->plugin_file, '/' ) === false ) continue;

        $file_path = WP_PLUGIN_DIR . '/' . $entry->plugin_file;
        if ( ! file_exists( $file_path ) ) {
            $db->delete( $tP, [ 'id' => (int) $entry->id ] );
            if ( $tM ) {
                $db->delete( $tM, [ 'plugin_slug' => $entry->plugin_slug ] );
            }
            $removed++;
            error_log( "[BizCity Market] Removed orphan catalog entry: {$entry->plugin_slug} ({$entry->plugin_file})" );
        }
    }

    // Cache 24h
    set_site_transient( $cache_key, time(), DAY_IN_SECONDS );

    error_log( '[BizCity Market Sync] Result — agents found: ' . count( $agent_candidates )
        . ', synced: ' . $synced . ', orphans removed: ' . $removed
        . ', table: ' . $tP . ', globaldb class: ' . get_class( $db ) );
    if ( $agent_candidates ) {
        error_log( '[BizCity Market Sync] Agent plugins: ' . implode( ', ', $agent_candidates ) );
    } else {
        error_log( '[BizCity Market Sync] WARNING — No plugins with Role header found! Check if get_plugins() includes bundled plugins.' );
        // Log first 10 plugin files for debugging
        $sample = array_slice( array_keys( $all_plugins ), 0, 10 );
        error_log( '[BizCity Market Sync] Sample plugin files: ' . implode( ', ', $sample ) );
    }
  }

  /**
   * get_agent_plugins_with_headers()
   *
   * Trả về danh sách các plugin active trên blog hiện tại có Role: agent,
   * kèm đầy đủ custom headers (Icon Path, Template Page, Credit, ...).
   *
   * Template Page chứa slug frontend (vd: "tarot", "chiem-tinh-astro").
   * template_url = home_url( '/' . slug . '/' ) — dùng cho Touch Bar iframe.
   *
   * @return array [ ['slug'=>..., 'name'=>..., 'icon_url'=>..., 'template_url'=>..., ...], ... ]
   */
  public static function get_agent_plugins_with_headers() {
    // ── Transient cache — disk I/O heavy, invalidated by bizcity_tool_registry_changed ──
    $cache_key = 'bizcity_agent_plugins_headers';
    $cached    = get_transient( $cache_key );
    if ( $cached !== false ) {
        return $cached;
    }

    if ( ! function_exists( 'get_plugins' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $active_plugins = (array) get_option( 'active_plugins', [] );

    // Also include network-activated plugins (multisite)
    if ( is_multisite() ) {
        $network_plugins = array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) );
        $active_plugins  = array_unique( array_merge( $active_plugins, $network_plugins ) );
    }

    $extra_headers  = [
        'Role'          => 'Role',
        'Icon Path'     => 'Icon Path',
        'Credit'        => 'Credit',
        'Price'         => 'Price',
        'Cover URI'     => 'Cover URI',
        'Template Page' => 'Template Page',
        'Category'      => 'Category',
    ];

    $agents = [];

    foreach ( $active_plugins as $plugin_file ) {
        $full_path = WP_PLUGIN_DIR . '/' . $plugin_file;
        if ( ! file_exists( $full_path ) ) continue;

        $custom = get_file_data( $full_path, $extra_headers );
        $role   = strtolower( trim( $custom['Role'] ?? '' ) );
        if ( ! in_array( $role, [ 'agent', 'tool' ], true ) ) continue;

        $std_headers = get_file_data( $full_path, [
            'Name'        => 'Plugin Name',
            'Description' => 'Description',
            'Version'     => 'Version',
            'Author'      => 'Author',
            'AuthorURI'   => 'Author URI',
            'TextDomain'  => 'Text Domain',
        ] );

        $slug = sanitize_key( dirname( $plugin_file ) );
        if ( $slug === '.' ) $slug = sanitize_key( basename( $plugin_file, '.php' ) );

        $icon_path = ltrim( trim( $custom['Icon Path'] ?? '' ), '/' );
        $icon_url  = '';
        if ( $icon_path && file_exists( WP_PLUGIN_DIR . '/' . dirname( $plugin_file ) . '/' . $icon_path ) ) {
            $icon_url = plugins_url( $icon_path, $full_path );
        }

        // Template Page = frontend slug (vd: "tarot", "chiem-tinh-astro", "kling-video")
        $template_slug = sanitize_title( trim( $custom['Template Page'] ?? '' ) );
        $template_url  = $template_slug ? home_url( '/' . $template_slug . '/' ) : '';

        $agents[] = [
            'slug'           => $slug,
            'plugin_file'    => $plugin_file,
            'name'           => $std_headers['Name'] ?: $slug,
            'description'    => $std_headers['Description'] ?? '',
            'version'        => $std_headers['Version'] ?? '1.0.0',
            'author'         => $std_headers['Author'] ?? 'BizCity',
            'icon_url'       => $icon_url,
            'icon_path'      => $icon_path,
            'credit'         => (int) ( $custom['Credit'] ?? 0 ),
            'cover_uri'      => trim( $custom['Cover URI'] ?? '' ),
            'template_slug'  => $template_slug,
            'template_url'   => $template_url,
            'category'       => sanitize_text_field( trim( $custom['Category'] ?? '' ) ),
        ];
    }

    // Persist for 12 hours — explicit invalidation via activated_plugin / deactivated_plugin
    set_transient( $cache_key, $agents, 12 * HOUR_IN_SECONDS );

    return $agents;
  }
}
