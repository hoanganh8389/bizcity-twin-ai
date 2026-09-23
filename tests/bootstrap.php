<?php
/**
 * PHPUnit bootstrap for bizcity-twin-ai unit suite.
 *
 * Pure-helper unit tests do NOT need a WordPress runtime. We only autoload
 * Composer + selected helper files that are framework-pure (no WP core deps
 * outside of stub functions defined here).
 *
 * For integration testing (REST, hooks, schema), use `php bin/diagnostics-run.php`
 * which boots a real WP install — that path is wired into CI separately.
 *
 * @package BizCity_Twin_AI
 */

if ( PHP_VERSION_ID < 70400 ) {
    fwrite( STDERR, "PHPUnit suite requires PHP 7.4+. Got " . PHP_VERSION . PHP_EOL );
    exit( 1 );
}

$root = dirname( __DIR__ );

// [2026-08-28 Johnny Chu] PHASE-1.31-N2 — define the standalone WordPress root before Composer autoload files run; helpers-deprecation.php otherwise exits the PHPUnit process silently.
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', $root . '/' );
}

// Composer autoload (PSR-4 + classmap for contracts).
$autoload = $root . '/vendor/autoload.php';
if ( ! file_exists( $autoload ) ) {
    fwrite( STDERR, "vendor/autoload.php missing — run `composer install` first.\n" );
    exit( 1 );
}
require $autoload;

// Minimal WP function stubs so framework-pure helpers can be exercised
// without booting WordPress. Extend ONLY when a stubbed function is
// genuinely required by a unit under test.
// [2026-09-23 Claude Sonnet 5] PHASE-0.60A W3-test — upgraded from a fixed-arity no-op to a
// real minimal hook registry (global, priority-ordered) so tests can assert actual filter
// behavior (e.g. "claiming a turn flips bizcity_automation_default_reply_enabled to false
// for this request"), not just that the functions are callable. No existing test in this
// suite calls add_filter/apply_filters, so this is purely additive.
if ( ! isset( $GLOBALS['__bzc_hooks'] ) ) {
    $GLOBALS['__bzc_hooks'] = array();
}
if ( ! function_exists( 'add_filter' ) ) {
    function add_filter( $tag, $cb, $priority = 10, $accepted_args = 1 ) {
        $GLOBALS['__bzc_hooks'][ $tag ][ $priority ][] = array( 'cb' => $cb, 'args' => $accepted_args );
        return true;
    }
}
if ( ! function_exists( 'add_action' ) ) {
    function add_action( $tag, $cb, $priority = 10, $accepted_args = 1 ) {
        return add_filter( $tag, $cb, $priority, $accepted_args );
    }
}
if ( ! function_exists( 'remove_all_filters' ) ) {
    function remove_all_filters( $tag = null ) {
        if ( null === $tag ) {
            $GLOBALS['__bzc_hooks'] = array();
        } else {
            unset( $GLOBALS['__bzc_hooks'][ $tag ] );
        }
        return true;
    }
}
if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( $tag, $value, ...$more ) {
        if ( empty( $GLOBALS['__bzc_hooks'][ $tag ] ) ) {
            return $value;
        }
        $by_priority = $GLOBALS['__bzc_hooks'][ $tag ];
        ksort( $by_priority );
        foreach ( $by_priority as $hooks ) {
            foreach ( $hooks as $hook ) {
                $args  = array_merge( array( $value ), $more );
                $args  = array_slice( $args, 0, max( 1, (int) $hook['args'] ) );
                $value = call_user_func_array( $hook['cb'], $args );
            }
        }
        return $value;
    }
}
if ( ! function_exists( 'do_action' ) ) {
    function do_action( $tag, ...$more ) {
        if ( empty( $GLOBALS['__bzc_hooks'][ $tag ] ) ) {
            return;
        }
        $by_priority = $GLOBALS['__bzc_hooks'][ $tag ];
        ksort( $by_priority );
        foreach ( $by_priority as $hooks ) {
            foreach ( $hooks as $hook ) {
                call_user_func_array( $hook['cb'], array_slice( $more, 0, max( 1, (int) $hook['args'] ) ) );
            }
        }
    }
}
if ( ! function_exists( 'esc_html' ) ) {
    function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'sanitize_key' ) ) {
    function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $s ) ); }
}
if ( ! function_exists( '__return_false' ) ) {
    function __return_false() { return false; }
}
if ( ! function_exists( '__return_true' ) ) {
    function __return_true() { return true; }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
    function wp_json_encode( $data, $options = 0, $depth = 512 ) {
        return json_encode( $data, $options, $depth );
    }
}
if ( ! defined( 'WP_DEBUG' ) ) {
    define( 'WP_DEBUG', false );
}
// [2026-09-01 Johnny Chu] PHPUNIT-COMPAT - provide the WordPress row-shape constant used by the pure Context Bank tests.
if ( ! defined( 'ARRAY_A' ) ) {
    define( 'ARRAY_A', 'ARRAY_A' );
}
