<?php
/**
 * Bizcity Twin AI — Nền tảng AI Companion cá nhân hóa
 * Bizcity Twin AI — Personalized AI Companion Platform
 *
 * Main Orchestrator — Boot sequence: load core → discover modules → fire loaded action.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 *
 * This file is part of Bizcity Twin AI.
 * Unauthorized copying, modification, or distribution is prohibited.
 * Sao chép, chỉnh sửa hoặc phân phối trái phép bị nghiêm cấm.
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Twin_AI {

    /** @var bool */
    private static $booted = false;

    /** @var array Diagnostic info for admin notice */
    private static $diag = [
        'boot'    => false,
        'modules' => [],
        'errors'  => [],
    ];

    /**
     * Boot the Twin AI platform.
     * Called at plugins_loaded @0.
     * Core components already loaded at file scope (bizcity-twin-ai.php).
     */
    public static function boot(): void {
        if ( self::$booted ) return;
        self::$booted   = true;
        self::$diag['boot'] = true;

        BizCity_Connection_Gate::instance();

        self::load_modules();
        self::register_loaded_modules();
        self::run_deferred_activation();

        do_action( 'bizcity_twin_ai_loaded' );
    }

    /**
     * Load all modules — explicit require_once.
     * Each bootstrap has its own class_exists/defined guard to prevent double-loading.
     * Note: automation & notebook đã chuyển sang plugins/ (standalone plugin).
     * Note: identity tách ra thành extension plugin (wp-content/plugins/bizcoach-map/)
     */
    private static function load_modules(): void {
        $mod_dir = BIZCITY_TWIN_AI_DIR . 'modules/';

        $modules = [
            'webchat'    => 'webchat/bootstrap.php',
        ];

        foreach ( $modules as $name => $mod ) {
            $file = $mod_dir . $mod;
            if ( ! file_exists( $file ) ) {
                self::$diag['modules'][ $name ] = 'FILE_NOT_FOUND';
                continue;
            }

            try {
                require_once $file;
                self::$diag['modules'][ $name ] = 'OK';
            } catch ( \Throwable $e ) {
                $msg = $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
                self::$diag['modules'][ $name ] = 'EXCEPTION: ' . $msg;
                self::$diag['errors'][] = $name . ': ' . $msg;
            }
        }
    }

    /**
     * Register loaded modules into BizCity_Module_Loader for API compatibility.
     * This allows has_module(), get_module(), get_active_modules() to work
     * without module.json discovery.
     */
    private static function register_loaded_modules(): void {
        $registry = [
            'webchat'    => [ 'guard' => 'BizCity_WebChat_Database', 'type' => 'class' ],
        ];

        foreach ( $registry as $name => $check ) {
            $loaded = ( $check['type'] === 'class' )
                ? class_exists( $check['guard'], false )
                : defined( $check['guard'] );

            if ( $loaded ) {
                BizCity_Module_Loader::register( $name, [
                    'name'    => $name,
                    'bundled' => true,
                    'license' => 'lite',
                    '_dir'    => BIZCITY_TWIN_AI_DIR . 'modules/' . $name,
                ] );
            }
        }
    }

    /**
     * Plugin activation hook — install DB tables, set defaults.
     */
    public static function activate(): void {
        // DB table installation is deferred to first boot() on plugins_loaded.
        // We cannot call boot() here because mu-plugins haven't loaded yet
        // during activation, causing class redeclaration conflicts.
        set_transient( 'bizcity_twin_ai_activated', 1, 60 );
        // Reset TwinChat public-page rewrite flush flag so /twinchat/ rule is registered fresh.
        delete_option( 'bizcity_twinchat_rewrite_flushed_v1' );
        flush_rewrite_rules();

        // Install Phase 0.13 runtime DB tables immediately on activation.
        if ( class_exists( 'BizCity_Twin_DB_Installer' ) ) {
            BizCity_Twin_DB_Installer::install();
        }

        // Auto-copy (or overwrite) mu-plugin compat loader
        self::sync_compat_loader();
    }

    /**
     * Sync mu-plugin/bizcity-twin-compat.php → mu-plugins/ when the bundle changes.
     *
     * @return bool True when the destination is already current or was updated.
     */
    public static function sync_compat_loader(): bool {
        // [2026-08-07 Johnny Chu] R-AUTO-MU — self-heal the deployed compat loader after a client pulls a newer plugin bundle.
        if ( ! defined( 'WPMU_PLUGIN_DIR' ) || '' === WPMU_PLUGIN_DIR ) {
            return false;
        }

        $src  = BIZCITY_TWIN_AI_DIR . 'mu-plugin/bizcity-twin-compat.php';
        $dest_dir = rtrim( WPMU_PLUGIN_DIR, '/\\' );
        $dest = $dest_dir . '/bizcity-twin-compat.php';

        // [2026-08-26 Johnny Chu] R-AUTO-MU — the bundle source is the only authority; a missing or unreadable source cannot overwrite the client MU loader.
        if ( ! is_file( $src ) || ! is_readable( $src ) ) {
            return false;
        }

        if ( ! is_dir( $dest_dir ) && ! wp_mkdir_p( $dest_dir ) ) {
            return false;
        }

        if ( ! is_writable( $dest_dir ) ) {
            return false;
        }

        $src_hash = md5_file( $src );
        if ( false === $src_hash ) {
            return false;
        }

        $compat_status = self::compat_loader_status();
        $src_version   = $compat_status['source_version'];
        $dest_version  = $compat_status['client_version'];
        $version_drift = ! $compat_status['version_match'];
        $hash_drift    = ! is_file( $dest ) || ! is_readable( $dest ) || $src_hash !== md5_file( $dest );
        // [2026-08-26 Johnny Chu] R-AUTO-MU — version drift is authoritative; hash is only a fallback when the source has no version marker.
        if ( ! $version_drift && ( '' !== $src_version || ! $hash_drift ) ) {
            return true;
        }

        // [2026-08-07 Johnny Chu] R-AUTO-MU — stage beside the destination so a failed copy never truncates the active loader.
        $tmp = tempnam( $dest_dir, '.bizcity-twin-compat-' );
        if ( false !== $tmp ) {
            if ( @copy( $src, $tmp ) && @rename( $tmp, $dest ) ) {
                return true;
            }
            @unlink( $tmp );
        }

        // Windows hosts may reject rename() over an existing file; retain a direct-copy fallback.
        return @copy( $src, $dest );
    }

    /**
     * Report canonical and deployed twin-compat loader state without executing either file.
     *
     * @return array{source_exists:bool,client_exists:bool,source_version:string,client_version:string,version_match:bool,current:bool}
     */
    public static function compat_loader_status(): array {
        $source = defined( 'BIZCITY_TWIN_AI_DIR' )
            ? BIZCITY_TWIN_AI_DIR . 'mu-plugin/bizcity-twin-compat.php'
            : '';
        $client = defined( 'WPMU_PLUGIN_DIR' ) && '' !== WPMU_PLUGIN_DIR
            ? rtrim( WPMU_PLUGIN_DIR, '/\\' ) . '/bizcity-twin-compat.php'
            : '';
        $source_exists  = '' !== $source && is_file( $source ) && is_readable( $source );
        $client_exists  = '' !== $client && is_file( $client ) && is_readable( $client );
        $source_version = $source_exists ? self::compat_loader_version( $source ) : '';
        $client_version = $client_exists ? self::compat_loader_version( $client ) : '';
        $version_match  = $source_version !== '' && $source_version === $client_version;

        return array(
            'source_exists'  => $source_exists,
            'client_exists'  => $client_exists,
            'source_version' => $source_version,
            'client_version' => $client_version,
            'version_match'  => $version_match,
            'current'        => $source_exists && $client_exists && $version_match,
        );
    }

    /**
     * Read the compat version marker without executing a client MU artifact.
     *
     * @param string $path Absolute PHP file path.
     * @return string
     */
    private static function compat_loader_version( string $path ): string {
        if ( ! is_file( $path ) || ! is_readable( $path ) ) {
            return '';
        }
        $contents = @file_get_contents( $path );
        if ( false === $contents || ! preg_match( '/@version\s+([0-9]+\.[0-9]+\.[0-9]+)/', $contents, $match ) ) {
            return '';
        }
        return (string) $match[1];
    }

    /**
     * Run deferred activation tasks on first boot after plugin activation.
     */
    private static function run_deferred_activation(): void {
        if ( ! get_transient( 'bizcity_twin_ai_activated' ) ) {
            return;
        }
        delete_transient( 'bizcity_twin_ai_activated' );

        if ( class_exists( 'BizCity_Knowledge_Database' ) ) {
            BizCity_Knowledge_Database::maybe_create_tables();
        }
        if ( class_exists( 'BizCity_Intent_Database' ) ) {
            BizCity_Intent_Database::instance()->maybe_create_tables();
        }
        if ( class_exists( 'BCN_Plugin' ) ) {
            BCN_Plugin::instance()->activate();
        }
        if ( class_exists( 'BizCity_Channel_Role' ) ) {
            BizCity_Channel_Role::seed_defaults();
        }
    }

    /* ── Public API ─────────────────────────────────────────── */

    public static function has_module( string $name ): bool {
        return BizCity_Module_Loader::has( $name );
    }

    public static function get_module( string $name ): ?array {
        return BizCity_Module_Loader::get( $name );
    }

    public static function get_active_modules(): array {
        return BizCity_Module_Loader::get_all_loaded();
    }

    public static function get_agent_plugins(): array {
        if ( class_exists( 'BizCity_Market_Catalog' ) ) {
            return BizCity_Market_Catalog::get_agent_plugins();
        }
        return [];
    }

    public static function is_pro(): bool {
        return BizCity_Connection_Gate::instance()->has_tier( 'pro' );
    }

    public static function is_enterprise(): bool {
        return BizCity_Connection_Gate::instance()->has_tier( 'enterprise' );
    }

    public static function module_dir( string $name ): string {
        return BizCity_Module_Loader::module_dir( $name );
    }

    public static function module_url( string $name ): string {
        return BizCity_Module_Loader::module_url( $name );
    }

    /** Diagnostic data for admin notice */
    public static function get_diag(): array {
        return self::$diag;
    }
}
