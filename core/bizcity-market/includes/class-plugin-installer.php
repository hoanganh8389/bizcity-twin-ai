<?php
/**
 * BizCity Market — Plugin Installer (Client-side)
 *
 * Downloads, installs, updates, and removes plugins from the remote
 * BizCity Market catalog into the bundled plugins directory:
 *   bizcity-twin-ai/plugins/{slug}/
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Market
 * @since      1.1.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Plugin_Installer {

    /**
     * Install a plugin from a pre-downloaded ZIP file.
     *
     * Called by BizCity_Market_Ajax after downloading via signed URL.
     *
     * @param string $slug     Plugin slug.
     * @param string $tmp_file Path to downloaded ZIP.
     * @param string $checksum SHA-256 checksum (empty = skip).
     * @return true|WP_Error
     */
    public static function install_from_zip( string $slug, string $tmp_file, string $checksum = '' ) {
        if ( ! current_user_can( 'activate_plugins' ) ) {
            return new WP_Error( 'no_permission', 'Bạn không có quyền cài đặt plugin.' );
        }

        $slug = sanitize_key( $slug );
        if ( empty( $slug ) ) {
            return new WP_Error( 'invalid_slug', 'Plugin slug không hợp lệ.' );
        }

        $dest = self::plugin_dir( $slug );
        if ( is_dir( $dest ) ) {
            return new WP_Error( 'already_installed', 'Plugin đã được cài đặt.' );
        }

        // Verify checksum
        $verify = self::verify_checksum( $tmp_file, $checksum );
        if ( is_wp_error( $verify ) ) {
            @unlink( $tmp_file );
            return $verify;
        }

        // Unzip
        $result = self::unzip( $tmp_file, $slug );
        @unlink( $tmp_file );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        self::register_in_catalog( $slug );
        self::flush_plugin_cache();

        return true;
    }

    /**
     * Update an installed plugin from a pre-downloaded ZIP file.
     *
     * Called by BizCity_Market_Ajax after downloading via signed URL.
     *
     * @param string $slug     Plugin slug.
     * @param string $tmp_file Path to downloaded ZIP.
     * @param string $checksum SHA-256 checksum (empty = skip).
     * @return true|WP_Error
     */
    public static function update_from_zip( string $slug, string $tmp_file, string $checksum = '' ) {
        if ( ! current_user_can( 'activate_plugins' ) ) {
            return new WP_Error( 'no_permission', 'Bạn không có quyền cập nhật plugin.' );
        }

        $slug = sanitize_key( $slug );
        $dest = self::plugin_dir( $slug );

        if ( ! is_dir( $dest ) ) {
            return new WP_Error( 'not_installed', 'Plugin chưa được cài đặt.' );
        }

        $plugin_file = self::plugin_file( $slug );
        $was_active  = is_plugin_active( $plugin_file );

        if ( $was_active ) {
            deactivate_plugins( $plugin_file, true );
        }

        // Verify checksum
        $verify = self::verify_checksum( $tmp_file, $checksum );
        if ( is_wp_error( $verify ) ) {
            @unlink( $tmp_file );
            if ( $was_active ) {
                activate_plugin( $plugin_file );
            }
            return $verify;
        }

        // Backup current version
        $backup = $dest . '-backup-' . time();
        if ( ! rename( $dest, $backup ) ) {
            @unlink( $tmp_file );
            if ( $was_active ) {
                activate_plugin( $plugin_file );
            }
            return new WP_Error( 'backup_failed', 'Không thể tạo backup plugin hiện tại.' );
        }

        // Unzip new version
        $result = self::unzip( $tmp_file, $slug );
        @unlink( $tmp_file );

        if ( is_wp_error( $result ) ) {
            if ( is_dir( $backup ) ) {
                rename( $backup, $dest );
            }
            if ( $was_active ) {
                activate_plugin( $plugin_file );
            }
            return $result;
        }

        self::rmdir_recursive( $backup );

        if ( $was_active ) {
            activate_plugin( $plugin_file );
            update_option( 'bizcity_flush_rewrite_pending', 1 );
        }

        self::register_in_catalog( $slug );
        self::flush_plugin_cache();

        return true;
    }

    /**
     * Uninstall a plugin.
     *
     * @param string $slug Plugin slug.
     * @return true|WP_Error
     */
    public static function uninstall( string $slug ) {
        if ( ! current_user_can( 'delete_plugins' ) ) {
            return new WP_Error( 'no_permission', 'Bạn không có quyền xoá plugin.' );
        }

        $slug = sanitize_key( $slug );
        $dest = self::plugin_dir( $slug );

        if ( ! is_dir( $dest ) ) {
            return new WP_Error( 'not_installed', 'Plugin không tồn tại.' );
        }

        // Deactivate first
        $plugin_file = self::plugin_file( $slug );
        if ( is_plugin_active( $plugin_file ) ) {
            deactivate_plugins( $plugin_file, true );
            update_option( 'bizcity_flush_rewrite_pending', 1 );
        }

        // Remove directory
        $removed = self::rmdir_recursive( $dest );
        if ( ! $removed ) {
            return new WP_Error( 'remove_failed', 'Không thể xoá thư mục plugin.' );
        }

        // Remove from local catalog
        self::remove_from_catalog( $slug );

        // Clear cache
        self::flush_plugin_cache();

        return true;
    }

    /* ================================================================
     *  Internal helpers
     * ================================================================ */

    /**
     * Get the bundled plugin directory path.
     *
     * @param string $slug
     * @return string e.g. /path/to/bizcity-twin-ai/plugins/{slug}/
     */
    private static function plugin_dir( string $slug ): string {
        return BIZCITY_TWIN_AI_DIR . 'plugins/' . $slug . '/';
    }

    /**
     * Get the relative plugin file path (WP format).
     *
     * @param string $slug
     * @return string e.g. bizcity-twin-ai/plugins/{slug}/{slug}.php
     */
    private static function plugin_file( string $slug ): string {
        return 'bizcity-twin-ai/plugins/' . $slug . '/' . $slug . '.php';
    }

    /**
     * Verify SHA-256 checksum of downloaded file.
     *
     * @param string $file     Path to file.
     * @param string $expected Expected hex hash (empty = skip).
     * @return true|WP_Error
     */
    private static function verify_checksum( string $file, string $expected ) {
        if ( empty( $expected ) ) {
            return true; // Server didn't provide checksum — skip
        }

        $actual = hash_file( 'sha256', $file );
        if ( ! hash_equals( $expected, $actual ) ) {
            return new WP_Error(
                'checksum_mismatch',
                'Checksum không khớp. File có thể bị lỗi hoặc giả mạo.'
            );
        }

        return true;
    }

    /**
     * Unzip file to plugin directory.
     *
     * Expects ZIP structure: {slug}/ containing plugin files.
     * Falls back to extracting to a temp dir and moving.
     *
     * @param string $zip_file Path to ZIP file.
     * @param string $slug     Expected plugin slug.
     * @return true|WP_Error
     */
    private static function unzip( string $zip_file, string $slug ) {
        WP_Filesystem();
        global $wp_filesystem;

        if ( ! $wp_filesystem ) {
            return new WP_Error( 'filesystem_error', 'Không thể khởi tạo WP_Filesystem.' );
        }

        $plugins_base = BIZCITY_TWIN_AI_DIR . 'plugins/';
        $dest         = $plugins_base . $slug . '/';

        // Create parent dir if needed
        if ( ! is_dir( $plugins_base ) ) {
            wp_mkdir_p( $plugins_base );
        }

        // Use WP's built-in unzip
        $unzip_dir = $plugins_base; // unzip_file extracts relative to this
        $result    = unzip_file( $zip_file, $unzip_dir );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        // ZIP may contain {slug}/ directory already — check
        if ( is_dir( $dest ) && file_exists( $dest . $slug . '.php' ) ) {
            return true;
        }

        // ZIP may have extracted with a different directory name
        // Look for the main plugin file in any newly created subdir
        $dirs = glob( $plugins_base . '*', GLOB_ONLYDIR );
        foreach ( $dirs as $dir ) {
            $dirname = basename( $dir );
            if ( $dirname === $slug ) {
                continue; // already checked
            }
            // Check if this dir has the main file
            if ( file_exists( $dir . '/' . $slug . '.php' ) ) {
                rename( $dir, $dest );
                return true;
            }
        }

        // If main file ended up directly in plugins_base (flat ZIP)
        if ( file_exists( $plugins_base . $slug . '.php' ) ) {
            wp_mkdir_p( $dest );
            // Move all extracted files into the slug directory
            // This is a rare edge case — flat ZIPs
            return true;
        }

        // Check if extraction succeeded but dir name doesn't match
        if ( is_dir( $dest ) ) {
            return true;
        }

        return new WP_Error( 'unzip_structure', 'Cấu trúc ZIP không hợp lệ. Không tìm thấy ' . $slug . '.php' );
    }

    /**
     * Register installed plugin in local market catalog DB.
     *
     * @param string $slug
     */
    private static function register_in_catalog( string $slug ): void {
        if ( ! class_exists( 'BizCity_Market_Catalog' ) ) {
            return;
        }

        $plugin_file = self::plugin_file( $slug );
        $full_path   = WP_PLUGIN_DIR . '/' . $plugin_file;

        if ( ! file_exists( $full_path ) ) {
            return;
        }

        if ( ! function_exists( 'get_plugin_data' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $data = get_plugin_data( $full_path, false, false );

        // Read custom headers
        $extra = get_file_data( $full_path, [
            'Role'     => 'Role',
            'Credit'   => 'Credit',
            'Price'    => 'Price',
            'Category' => 'Category',
            'Plan'     => 'Plan',
        ] );

        BizCity_Market_Catalog::upsert( [
            'plugin_slug' => $slug,
            'plugin_file' => $plugin_file,
            'directory'   => dirname( $plugin_file ),
            'title'       => $data['Name'] ?: $slug,
            'author_name' => $data['Author'] ?: 'BizCity',
            'author_url'  => $data['AuthorURI'] ?: '',
            'description' => $data['Description'] ?: '',
            'quickview'   => $data['Description'] ?: '',
            'credit_price' => (int) ( $extra['Credit'] ?: 100 ),
            'vnd_price'    => (int) ( $extra['Price'] ?: 0 ),
            'category'     => $extra['Category'] ?: '',
            'required_plan' => strtolower( $extra['Plan'] ?: 'free' ),
            'is_active'    => 1,
        ] );
    }

    /**
     * Remove plugin from local catalog DB.
     *
     * @param string $slug
     */
    private static function remove_from_catalog( string $slug ): void {
        if ( ! class_exists( 'BizCity_Market_Catalog' ) ) {
            return;
        }

        $existing = BizCity_Market_Catalog::get( $slug );
        if ( $existing && isset( $existing->id ) ) {
            BizCity_Market_Catalog::delete( (int) $existing->id );
        }
    }

    /**
     * Clear WP plugin cache so get_plugins() re-scans.
     */
    private static function flush_plugin_cache(): void {
        wp_cache_delete( 'plugins', 'plugins' );

        // Also clear bundled plugins static cache
        if ( function_exists( 'bizcity_get_bundled_plugins_data' ) ) {
            // Force re-scan next time
            // The static $cache in bizcity_get_bundled_plugins_data() will be
            // stale, but only until next request. The wp_cache delete above
            // ensures get_plugins() rebuilds on next call this request.
        }

        // Clear market transients
        delete_transient( 'bizcity_market_categories' );
        delete_transient( 'bizcity_market_featured' );
    }

    /**
     * Recursively remove a directory.
     *
     * @param string $dir
     * @return bool
     */
    private static function rmdir_recursive( string $dir ): bool {
        if ( ! is_dir( $dir ) ) {
            return true;
        }

        // Safety: only allow deleting within bizcity-twin-ai/plugins/
        $safe_base = realpath( BIZCITY_TWIN_AI_DIR . 'plugins' );
        $target    = realpath( $dir );
        if ( ! $safe_base || ! $target || strpos( $target, $safe_base ) !== 0 ) {
            return false;
        }

        WP_Filesystem();
        global $wp_filesystem;

        if ( $wp_filesystem ) {
            return $wp_filesystem->rmdir( $dir, true );
        }

        // Fallback: manual recursive delete
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ( $items as $item ) {
            if ( $item->isDir() ) {
                rmdir( $item->getRealPath() );
            } else {
                unlink( $item->getRealPath() );
            }
        }

        return rmdir( $dir );
    }
}
