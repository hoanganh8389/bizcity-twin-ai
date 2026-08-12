<?php
/**
 * Bounded mutation idempotency result store.
 *
 * Storage-neutral helper for mutation controllers. It keeps only public result
 * metadata and never stores request content, secrets, or tenant payloads.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Twin_Core
 * @since 1.24.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BizCity_Twin_Mutation_Store' ) ) {
	final class BizCity_Twin_Mutation_Store {

		const TTL = 86400;
		const GROUP = 'bizcity_twin_mutations';

		public static function begin( array $mutation, array $context, string $request_hash ): array {
			$key = self::key( $mutation, $context );
			$existing = self::get( $key );
			if ( is_array( $existing ) ) {
				if ( (string) ( $existing['request_hash'] ?? '' ) !== $request_hash ) {
					return array( 'status' => 'conflict', 'key' => $key );
				}
				if ( 'completed' === (string) ( $existing['state'] ?? '' ) ) {
					return array( 'status' => 'replay', 'key' => $key, 'response' => (array) ( $existing['response'] ?? array() ) );
				}
				return array( 'status' => 'pending', 'key' => $key );
			}

			$value = array(
				'state'        => 'pending',
				'request_hash' => $request_hash,
				'action'       => (string) ( $mutation['action'] ?? '' ),
				'resource'     => is_array( $mutation['resource'] ?? null ) ? (string) ( $mutation['resource']['scope'] ?? '' ) : '',
				'trace_id'     => (string) ( $mutation['trace_id'] ?? '' ),
				'created_at'   => time(),
				'blog_id'      => (int) ( $context['blog_id'] ?? ( function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 0 ) ),
				'user_id'      => (int) ( $context['user_id'] ?? 0 ),
				'expires_at'   => time() + self::TTL,
			);
			// [2026-08-10 Johnny Chu] PHASE-1.24-RUNTIME — prefer DB-atomic claim; request-local cache cannot protect multi-worker concurrency.
			$has_atomic_store = function_exists( 'add_option' );
			$claimed = $has_atomic_store
				? add_option( self::option_key( $key ), $value, '', false )
				: ( function_exists( 'wp_cache_add' ) ? wp_cache_add( $key, $value, self::GROUP, self::TTL ) : false );
		if ( ! $claimed ) {
			$existing = self::get( $key );
			if ( is_array( $existing ) ) {
				if ( (string) ( $existing['request_hash'] ?? '' ) !== $request_hash ) {
					return array( 'status' => 'conflict', 'key' => $key );
				}
				if ( 'completed' === (string) ( $existing['state'] ?? '' ) ) {
					return array( 'status' => 'replay', 'key' => $key, 'response' => (array) ( $existing['response'] ?? array() ) );
				}
			}
			// No atomic claim means fail closed; do not risk duplicate side effects.
			return array( 'status' => 'pending', 'key' => $key );
		}
		wp_cache_set( $key, $value, self::GROUP, self::TTL );
		if ( function_exists( 'set_transient' ) ) {
			set_transient( self::transient_key( $key ), $value, self::TTL );
		}
		return array( 'status' => 'new', 'key' => $key );
	}

	public static function complete( string $key, string $request_hash, array $response ): void {
		// [2026-08-10 Johnny Chu] PHASE-1.23-RUNTIME — completed replay records must expire with the bounded claim TTL.
		self::set( $key, array(
			'state'        => 'completed',
			'request_hash' => $request_hash,
			'response'     => $response,
			'completed_at' => time(),
			'expires_at'   => time() + self::TTL,
		) );
	}

	public static function release( string $key ): void {
		wp_cache_delete( $key, self::GROUP );
		if ( function_exists( 'delete_transient' ) ) {
			delete_transient( self::transient_key( $key ) );
		}
		if ( function_exists( 'delete_option' ) ) {
			delete_option( self::option_key( $key ) );
		}
	}

	private static function key( array $mutation, array $context ): string {
		$blog_id = (int) ( $context['blog_id'] ?? ( function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 0 ) );
		$user_id = (int) ( $context['user_id'] ?? 0 );
		$resource = is_array( $mutation['resource'] ?? null ) ? (string) ( $mutation['resource']['scope'] ?? '' ) : '';
		return 'mutation_' . md5( $blog_id . '|' . $user_id . '|' . (string) ( $mutation['action'] ?? '' ) . '|' . $resource . '|' . (string) ( $mutation['idempotency_key'] ?? '' ) );
	}

	private static function transient_key( string $key ): string {
		return 'bzcc_' . substr( md5( $key ), 0, 32 );
	}

	private static function option_key( string $key ): string {
		return 'bzcc_mut_' . substr( md5( $key ), 0, 32 );
	}

	private static function get( string $key ) {
		$cached = wp_cache_get( $key, self::GROUP );
		if ( false !== $cached ) {
			return $cached;
		}
		$value = function_exists( 'get_transient' ) ? get_transient( self::transient_key( $key ) ) : false;
		if ( false === $value && function_exists( 'get_option' ) ) {
			$value = get_option( self::option_key( $key ), false );
		}
		if ( is_array( $value ) && ! empty( $value['expires_at'] ) && (int) $value['expires_at'] < time() ) {
			self::release( $key );
			return false;
		}
		return $value;
	}

	private static function set( string $key, array $value ): void {
		wp_cache_set( $key, $value, self::GROUP, self::TTL );
		if ( function_exists( 'set_transient' ) ) {
			set_transient( self::transient_key( $key ), $value, self::TTL );
		}
		self::persist( $key, $value );
	}

	private static function persist( string $key, array $value ): void {
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
			return;
		}
		if ( false === get_option( self::option_key( $key ), false ) && function_exists( 'add_option' ) ) {
			add_option( self::option_key( $key ), $value, '', false );
			return;
		}
		update_option( self::option_key( $key ), $value, false );
	}
	}
}
