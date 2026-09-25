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

        // [2026-09-25 Claude Opus 5.5] CORE-REDUCTION WP-11 C1b — webchat archived (modules/_archived/webchat);
        // its shared message store is core/conversation. No bundled module is loaded from here any more.
        $modules = [];

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
        $registry = []; // WP-11 C1b — webchat archived

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

        // Retire a deployed mu-plugin compat loader (WP-11 C1c — no longer needed).
        self::sync_compat_loader();
    }

    /**
     * Retire the deployed mu-plugins/bizcity-twin-compat.php.
     *
     * [2026-09-25 Claude Opus 5.5] CORE-REDUCTION WP-11 C1c — the early compat loader is no longer needed: this
     * plugin loads everything it used to preload (LLM client, knowledge, intent, twin-core, market, connection
     * gate, loader registry, cron schedules, feature flags), the three duties only it had (magic-link admin
     * context, translation-notice filter, bundled activation cleanup) now live in bizcity-twin-ai.php, and the
     * webchat module it loaded is archived. The source copy under mu-plugin/ was removed, so this method no longer
     * copies anything: it deletes a deployed copy left behind by older bundles. Only our own file is touched
     * (recognised by the BIZCITY_TWIN_COMPAT_VERSION marker). Name kept: the activation hook and the
     * plugins_loaded@-100 hook already call it.
     *
     * @return bool True when no compat loader remains in mu-plugins/.
     */
    public static function sync_compat_loader(): bool {
        if ( ! defined( 'WPMU_PLUGIN_DIR' ) || '' === WPMU_PLUGIN_DIR ) {
            return true;
        }
        $dest = rtrim( WPMU_PLUGIN_DIR, '/\\' ) . '/bizcity-twin-compat.php';
        if ( ! is_file( $dest ) ) {
            return true;
        }
        $head = (string) @file_get_contents( $dest, false, null, 0, 4096 );
        if ( false === strpos( $head, 'BIZCITY_TWIN_COMPAT_VERSION' ) ) {
            return false; // Not our file — never delete someone else's mu-plugin.
        }
        if ( @unlink( $dest ) ) {
            if ( function_exists( 'opcache_invalidate' ) ) {
                @opcache_invalidate( $dest, true );
            }
            return true;
        }
        error_log( '[bizcity] compat_loader_retire_failed: mu-plugins/bizcity-twin-compat.php is not writable — delete it manually' );
        return false;
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
        // [2026-09-25 Claude Opus 5.5] FATAL-SWEEP — the catalog only exposes get_agent_plugins_with_headers().
        if ( method_exists( 'BizCity_Market_Catalog', 'get_agent_plugins_with_headers' ) ) {
            return (array) BizCity_Market_Catalog::get_agent_plugins_with_headers();
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
