<?php
/**
 * CRM Context App registry — PHASE-0.63B WP-C05.
 *
 * Registrations are code-owned filter entries. REST/DB data never creates an
 * app, and the registry rejects aliases whose declared key does not match the
 * filter key.
 *
 * @package BizCity_Twin_CRM
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Context_App_Registry', false ) ) {
	return;
}

final class BizCity_CRM_Context_App_Registry {
	const FILTER = 'bizcity_crm_register_context_apps';
	private static $cache = array();

	public static function all(): array {
		global $wpdb;
		$blog_id = function_exists( 'get_current_blog_id' ) ? (string) get_current_blog_id() : '0';
		$database = isset( $wpdb->dbname ) ? (string) $wpdb->dbname : '';
		$cache_key = $blog_id . ':' . $database;
		if ( isset( self::$cache[ $cache_key ] ) ) {
			return self::$cache[ $cache_key ];
		}
		$raw = apply_filters( self::FILTER, array() );
		if ( ! is_array( $raw ) ) {
			self::$cache[ $cache_key ] = array();
			return self::$cache[ $cache_key ];
		}
		$out = array();
		foreach ( $raw as $registered_key => $app ) {
			$normalized = self::normalize( $registered_key, $app );
			if ( null !== $normalized ) {
				$out[ $normalized['key'] ] = $normalized;
			}
		}
		self::$cache[ $cache_key ] = $out;
		return $out;
	}

	public static function get( string $key ): ?array {
		$key = self::key( $key );
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	public static function keys(): array {
		return array_keys( self::all() );
	}

	public static function registration_issues(): array {
		$issues = array();
		$raw = apply_filters( self::FILTER, array() );
		if ( ! is_array( $raw ) ) {
			return array( 'context_app_filter_not_array' );
		}
		foreach ( $raw as $registered_key => $app ) {
			$registered = self::key( $registered_key );
			if ( ! is_array( $app ) ) {
				$issues[] = $registered . ':invalid_descriptor';
				continue;
			}
			$declared = self::key( isset( $app['key'] ) ? $app['key'] : '' );
			if ( '' === $registered || '' === $declared || $registered !== $declared ) {
				$issues[] = $registered . ':key_mismatch:' . $declared;
				continue;
			}
			if ( null === self::normalize( $registered_key, $app ) ) {
				$issues[] = $registered . ':invalid_descriptor';
			}
		}
		return $issues;
	}

	public static function flush_cache(): void {
		self::$cache = array();
	}

	private static function normalize( $registered_key, $app ): ?array {
		// [2026-09-21 04:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.63B C-05 — reject
		// incomplete manifests before they can become a silently partial catalog.
		if ( ! is_array( $app ) ) {
			return null;
		}
		$key = self::key( isset( $app['key'] ) ? $app['key'] : '' );
		if ( '' === $key || $key !== self::key( $registered_key ) ) {
			return null;
		}
		$applies = isset( $app['applies_to'] ) && is_array( $app['applies_to'] ) ? $app['applies_to'] : array();
		$render = isset( $app['render'] ) && is_array( $app['render'] ) ? $app['render'] : array();
		$surfaces = isset( $app['surfaces'] ) && is_array( $app['surfaces'] ) ? array_values( array_unique( array_map( array( __CLASS__, 'token' ), $app['surfaces'] ) ) ) : array();
		$rest = isset( $app['rest'] ) && is_array( $app['rest'] ) ? array_values( array_unique( array_map( 'sanitize_text_field', $app['rest'] ) ) ) : array();
		$required_apply_keys = array( 'subject_roles', 'pipeline_kinds', 'capability', 'channels' );
		$has_apply_shape = count( array_intersect( $required_apply_keys, array_keys( $applies ) ) ) === count( $required_apply_keys );
		$has_valid_surfaces = ! empty( $surfaces ) && empty( array_diff( $surfaces, array( 'b2', 'c' ) ) );
		$has_valid_render = in_array( (string) ( $render['type'] ?? '' ), array( 'component', 'route', 'embed' ), true )
			&& 1 === preg_match( '/^[A-Za-z][A-Za-z0-9._-]{1,100}$/', (string) ( $render['id'] ?? '' ) );
		$has_valid_lists = is_array( $applies['subject_roles'] ?? null ) && ! empty( $applies['subject_roles'] )
			&& is_array( $applies['pipeline_kinds'] ?? null ) && is_array( $applies['channels'] ?? null )
			&& ! empty( $applies['channels'] );
		$has_valid_rest = true;
		foreach ( $rest as $rest_path ) {
			if ( 1 !== preg_match( '#^/[A-Za-z0-9._{}?=&/-]*$#', (string) $rest_path ) ) {
				$has_valid_rest = false;
				break;
			}
		}
		$label = (string) ( $app['label'] ?? '' );
		$entity = (string) ( $app['entity'] ?? '' );
		$provider = (string) ( $app['provider'] ?? '' );
		$capability = isset( $applies['capability'] ) ? (string) $applies['capability'] : '';
		$has_valid_capability = 1 === preg_match( '/^[a-z][a-z0-9._-]{1,100}$/', $capability );
		$has_valid_provider = '' === $provider || 1 === preg_match( '/^[a-z][a-z0-9._-]{2,100}$/', $provider );
		if ( 'context-app' !== (string) ( $app['contract'] ?? '' ) || '1.0.0' !== (string) ( $app['version'] ?? '' ) || '' === $label || strlen( $label ) > 120 || strlen( $entity ) > 120 || ! $has_valid_provider || ! $has_valid_capability || ! $has_valid_rest || ! $has_valid_surfaces || ! $has_valid_render || ! $has_apply_shape || ! $has_valid_lists || ! array_key_exists( 'entity', $app ) || ! array_key_exists( 'provider', $app ) ) {
			return null;
		}
		return array(
			'contract' => 'context-app',
			'version' => '1.0.0',
			'key' => $key,
			'label' => (string) $app['label'],
			'icon' => isset( $app['icon'] ) ? self::token( $app['icon'] ) : '',
			'entity' => $entity,
			'provider' => self::token( $provider ),
			'applies_to' => array(
				'subject_roles' => self::list_values( $applies, 'subject_roles' ),
				'pipeline_kinds' => self::list_values( $applies, 'pipeline_kinds' ),
				'capability' => self::token( $capability ),
				'channels' => self::list_values( $applies, 'channels' ),
			),
			'surfaces' => $surfaces,
			'position' => isset( $app['position'] ) ? max( 0, (int) $app['position'] ) : 0,
			'render' => array( 'type' => self::token( $render['type'] ), 'id' => self::token( $render['id'] ) ),
			'rest' => $rest,
			'guide' => isset( $app['guide'] ) ? sanitize_text_field( $app['guide'] ) : '',
		);
	}

	private static function list_values( array $source, string $key ): array {
		return isset( $source[ $key ] ) && is_array( $source[ $key ] ) ? array_values( array_unique( array_map( array( __CLASS__, 'token' ), $source[ $key ] ) ) ) : array();
	}

	private static function token( $value ): string {
		return strtolower( preg_replace( '/[^a-z0-9._*:-]+/i', '', (string) $value ) );
	}

	private static function key( $value ): string {
		$value = strtolower( trim( (string) $value ) );
		return 1 === preg_match( '/^[a-z][a-z0-9._-]{2,63}$/', $value ) ? $value : '';
	}
}
