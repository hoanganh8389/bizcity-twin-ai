<?php
/**
 * PHP 7.4 compatibility polyfills.
 *
 * Provides shims for PHP 8.0+ functions used throughout the plugin.
 * Safe to load on PHP 8.x — each function is guarded by function_exists().
 *
 * @package Bizcity_Twin_AI
 * @since   1.3.3
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'str_starts_with' ) ) {
    function str_starts_with( string $haystack, string $needle ): bool {
        return $needle === '' || strncmp( $haystack, $needle, strlen( $needle ) ) === 0;
    }
}

if ( ! function_exists( 'str_ends_with' ) ) {
    function str_ends_with( string $haystack, string $needle ): bool {
        return $needle === '' || substr( $haystack, -strlen( $needle ) ) === $needle;
    }
}

if ( ! function_exists( 'str_contains' ) ) {
    function str_contains( string $haystack, string $needle ): bool {
        return $needle === '' || strpos( $haystack, $needle ) !== false;
    }
}

if ( ! function_exists( 'array_is_list' ) ) {
    function array_is_list( array $array ): bool {
        if ( $array === [] ) {
            return true;
        }
        return array_keys( $array ) === range( 0, count( $array ) - 1 );
    }
}
