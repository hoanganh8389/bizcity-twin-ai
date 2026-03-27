<?php
/**
 * Bizcity Twin AI — Nền tảng AI Companion cá nhân hóa
 * Bizcity Twin AI — Personalized AI Companion Platform
 *
 * Module Loader — JSON Manifest Discovery + Dependency Resolver.
 * Scans modules/ directory for module.json manifests,
 * verifies license tier via Connection Gate, resolves dependencies,
 * and loads each module's bootstrap file.
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

class BizCity_Module_Loader {

    /** @var array<string, array> Successfully loaded modules */
    private static array $modules = [];

    /** @var array<string, string> Skipped modules with reason */
    private static array $skipped = [];

    /**
     * Scan directory, read JSON manifests, verify license, resolve deps, load.
     */
    public static function discover_and_load( string $type = 'modules' ): void {
        $dir = BIZCITY_TWIN_AI_DIR . $type . '/';
        if ( ! is_dir( $dir ) ) return;

        $manifests = [];

        foreach ( glob( $dir . '*/module.json' ) as $file ) {
            $json = file_get_contents( $file );
            $m    = json_decode( $json, true );
            if ( ! is_array( $m ) || empty( $m['name'] ) ) continue;

            $m['_dir']  = dirname( $file );
            $m['_file'] = $file;
            $manifests[ $m['name'] ] = $m;
        }

        // Sort: priority ASC → topological by requires
        $sorted = self::topological_sort( $manifests );

        foreach ( $sorted as $name ) {
            $m = $manifests[ $name ];

            // 1. Connection Gate — tier check
            $gate = BizCity_Connection_Gate::instance();

            // 1a. Non-bundled modules need bizcity origin
            if ( self::is_marketplace_item( $m ) && ! $gate->is_bizcity() ) {
                self::$skipped[ $name ] = 'origin:standalone';
                continue;
            }

            // 1b. Tier check — tier gắn vào API key
            $required_tier = $m['license'] ?? 'lite';
            if ( ! $gate->has_tier( $required_tier ) ) {
                self::$skipped[ $name ] = 'tier:' . $required_tier;
                continue;
            }

            // 2. Dependency check
            if ( ! self::dependencies_met( $m['requires'] ?? [] ) ) {
                self::$skipped[ $name ] = 'missing_dep:' . implode( ',', $m['requires'] ?? [] );
                continue;
            }

            // 3. Define module constants if specified
            if ( ! empty( $m['constants'] ) && is_array( $m['constants'] ) ) {
                foreach ( $m['constants'] as $const_name => $const_tpl ) {
                    if ( ! defined( $const_name ) ) {
                        $value = str_replace(
                            [ '{DIR}', '{URL}' ],
                            [ $m['_dir'] . '/', plugin_dir_url( $m['_file'] ) ],
                            $const_tpl
                        );
                        define( $const_name, $value );
                    }
                }
            }

            // 4. Load bootstrap.php from module dir
            $bootstrap = $m['_dir'] . '/' . ( $m['bootstrap'] ?? 'bootstrap.php' );
            if ( file_exists( $bootstrap ) ) {
                require_once $bootstrap;
            }

            self::$modules[ $name ] = $m;
        }
    }

    /** Check if module is loaded */
    public static function has( string $name ): bool {
        return isset( self::$modules[ $name ] );
    }

    /** Register a module manually (used by direct-load boot instead of discovery) */
    public static function register( string $name, array $manifest ): void {
        if ( ! isset( self::$modules[ $name ] ) ) {
            self::$modules[ $name ] = $manifest;
        }
    }

    /** Get module manifest */
    public static function get( string $name ): ?array {
        return self::$modules[ $name ] ?? null;
    }

    /** Get all loaded modules */
    public static function get_all_loaded(): array {
        return self::$modules;
    }

    /** Get skipped modules with reasons */
    public static function get_skipped(): array {
        return self::$skipped;
    }

    /** Module directory path */
    public static function module_dir( string $name ): string {
        $m = self::get( $name );
        return $m ? $m['_dir'] . '/' : '';
    }

    /** Module URL */
    public static function module_url( string $name ): string {
        $m = self::get( $name );
        return $m ? plugin_dir_url( $m['_file'] ) : '';
    }

    /**
     * Topological sort: priority ASC, then by requires[] dependencies.
     *
     * @param array<string, array> $manifests
     * @return string[] Sorted module names
     */
    private static function topological_sort( array $manifests ): array {
        // Sort by priority first
        uasort( $manifests, function( $a, $b ) {
            return ( $a['priority'] ?? 50 ) <=> ( $b['priority'] ?? 50 );
        } );

        $sorted  = [];
        $visited = [];

        $visit = function( string $name ) use ( &$visit, &$sorted, &$visited, $manifests ) {
            if ( isset( $visited[ $name ] ) ) return;
            $visited[ $name ] = true;

            if ( isset( $manifests[ $name ] ) ) {
                foreach ( $manifests[ $name ]['requires'] ?? [] as $dep ) {
                    if ( isset( $manifests[ $dep ] ) ) {
                        $visit( $dep );
                    }
                }
            }
            $sorted[] = $name;
        };

        foreach ( array_keys( $manifests ) as $name ) {
            $visit( $name );
        }

        return $sorted;
    }

    /**
     * Check if all dependencies are met.
     * Dependencies can be core components (always available) or other modules.
     */
    private static function dependencies_met( array $requires ): bool {
        // Core components are always available (loaded before modules)
        $core = [ 'bizcity-llm', 'knowledge', 'intent', 'twin-core', 'bizcity-market' ];

        foreach ( $requires as $dep ) {
            if ( in_array( $dep, $core, true ) ) continue;
            if ( ! isset( self::$modules[ $dep ] ) ) return false;
        }
        return true;
    }

    /**
     * Check if a module is a marketplace item (non-bundled).
     * Bundled items always load regardless of connection state.
     */
    private static function is_marketplace_item( array $m ): bool {
        if ( ! empty( $m['bundled'] ) ) return false;
        $bundled = [ 'webchat', 'identity' ];
        return ! in_array( $m['name'], $bundled, true );
    }
}
