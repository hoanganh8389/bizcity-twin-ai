<?php
/**
 * Opt-in user-meta semantic trace for PHASE-1.23.
 *
 * Registers late, observational WordPress metadata filters. Every callback
 * returns the incoming value unchanged and records no metadata value.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics
 * @since 2026-08-10
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Diagnostics_User_Meta_Trace', false ) ) {
	return;
}

final class BizCity_Diagnostics_User_Meta_Trace {

	private static $registered = false;

	public static function init(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;
		// [2026-08-10 Johnny Chu] PHASE-1.23-CANONICAL-W5 - observe metadata
		// filters without modifying short-circuit values or user-meta payloads.
		add_filter( 'get_user_metadata', array( __CLASS__, 'on_get' ), PHP_INT_MAX, 5 );
		add_filter( 'update_user_metadata', array( __CLASS__, 'on_update' ), PHP_INT_MAX, 5 );
		add_filter( 'add_user_metadata', array( __CLASS__, 'on_add' ), PHP_INT_MAX, 5 );
		add_filter( 'delete_user_metadata', array( __CLASS__, 'on_delete' ), PHP_INT_MAX, 5 );
	}

	public static function on_get( $check, $object_id, $meta_key, $single, $meta_type ) {
		self::record( 'get', $object_id, $meta_key, array(
			'meta_type' => (string) $meta_type,
			'single'    => (bool) $single,
			'result'    => null === $check ? 'continue' : 'short_circuit',
		) );
		return $check;
	}

	public static function on_update( $check, $object_id, $meta_key, $meta_value, $prev_value ) {
		self::record( 'update', $object_id, $meta_key, array(
			'prev_value_present' => null !== $prev_value && '' !== $prev_value,
			'result'              => null === $check ? 'continue' : 'short_circuit',
		) );
		return $check;
	}

	public static function on_add( $check, $object_id, $meta_key, $meta_value, $unique ) {
		self::record( 'add', $object_id, $meta_key, array(
			'unique' => (bool) $unique,
			'result' => null === $check ? 'continue' : 'short_circuit',
		) );
		return $check;
	}

	public static function on_delete( $check, $object_id, $meta_key, $meta_value, $delete_all ) {
		self::record( 'delete', $object_id, $meta_key, array(
			'delete_all' => (bool) $delete_all,
			'result'     => null === $check ? 'continue' : 'short_circuit',
		) );
		return $check;
	}

	private static function record( string $operation, $object_id, $meta_key, array $data ): void {
		if ( ! class_exists( 'BizCity_Twin_Trace' ) || ! method_exists( 'BizCity_Twin_Trace', 'runtime_enter' ) ) {
			return;
		}
		$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		$object_id = (int) $object_id;
		$caller = self::caller();
		$span_id = BizCity_Twin_Trace::runtime_enter( 'user_meta', 'user_meta_' . $operation, array(
			'user_scope_hash' => substr( hash( 'sha256', $blog_id . '|' . $object_id ), 0, 16 ),
			'meta_key_family' => self::key_family( (string) $meta_key ),
			'meta_key_hash'   => substr( hash( 'sha256', (string) $meta_key ), 0, 16 ),
			'blog_id'         => $blog_id,
			'caller_file'     => (string) ( $caller['file'] ?? '' ),
			'caller_line'     => (int) ( $caller['line'] ?? 0 ),
		) );
		if ( $span_id !== '' ) {
			BizCity_Twin_Trace::runtime_exit( $span_id, 'pass', $data );
		}
	}

	private static function key_family( string $meta_key ): string {
		$key = strtolower( $meta_key );
		if ( false !== strpos( $key, 'oauth' ) || false !== strpos( $key, 'token' ) || false !== strpos( $key, 'nonce' ) || false !== strpos( $key, 'password' ) ) {
			return 'auth';
		}
		if ( false !== strpos( $key, 'phone' ) || false !== strpos( $key, 'mobile' ) ) {
			return 'contact';
		}
		if ( false !== strpos( $key, 'plan' ) || false !== strpos( $key, 'entitlement' ) || false !== strpos( $key, 'quota' ) ) {
			return 'plan';
		}
		if ( false !== strpos( $key, 'memory' ) || false !== strpos( $key, 'note' ) ) {
			return 'memory';
		}
		if ( false !== strpos( $key, 'profile' ) || false !== strpos( $key, 'name' ) || false !== strpos( $key, 'avatar' ) ) {
			return 'profile';
		}
		return 'other';
	}

	private static function caller(): array {
		$trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 6 );
		foreach ( $trace as $frame ) {
			$file = isset( $frame['file'] ) ? (string) $frame['file'] : '';
			if ( $file === '' || false !== strpos( $file, 'class-diagnostics-user-meta-trace.php' ) ) {
				continue;
			}
			return array(
				'file' => self::relative_file( $file ),
				'line' => (int) ( $frame['line'] ?? 0 ),
			);
		}
		return array( 'file' => '', 'line' => 0 );
	}

	private static function relative_file( string $file ): string {
		$file = '/' . ltrim( str_replace( '\\', '/', $file ), '/' );
		$lower = strtolower( $file );
		$marker = strpos( $lower, '/wp-content/' );
		if ( false !== $marker ) {
			return 'wp-content/' . ltrim( substr( $file, $marker + 12 ), '/' );
		}
		return 'external/' . substr( sha1( $file ), 0, 12 );
	}
}
