<?php
/** Remote Zalo Hub cursor, dedupe ring and lock store — LC-12/B5. */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Remote_Zalo_Cursor_Store', false ) ) { return; }

// [2026-09-29 12:00 PM GitHub Copilot] PHASE-0.82-B5 — persist cursor only after handled events and serialize poll ownership with an option lock.
final class BizCity_Remote_Zalo_Cursor_Store {
	const CURSOR_OPTION = 'bizcity_zalo_remote_cursor';
	const SEEN_OPTION = 'bizcity_zalo_remote_seen';
	const LOCK_OPTION = 'bizcity_zalo_remote_lock';
	const SEEN_MAX = 2000;

	public static function get(): array {
		$value = get_option( self::CURSOR_OPTION, array() );
		return is_array( $value ) ? $value + array( 'after' => 'latest', 'state' => 'ok', 'last_ok_at' => '', 'last_error_code' => '', 'last_error_at' => '', 'gap_since' => null ) : array( 'after' => 'latest', 'state' => 'ok', 'last_ok_at' => '', 'last_error_code' => '', 'last_error_at' => '', 'gap_since' => null );
	}
	public static function seen( string $event_id ): bool { return '' !== $event_id && in_array( $event_id, (array) get_option( self::SEEN_OPTION, array() ), true ); }
	public static function mark_handled( string $event_id, $cursor ): bool {
		$seen = array_values( array_unique( array_merge( (array) get_option( self::SEEN_OPTION, array() ), array( $event_id ) ) ) );
		if ( count( $seen ) > self::SEEN_MAX ) { $seen = array_slice( $seen, -self::SEEN_MAX ); }
		update_option( self::SEEN_OPTION, $seen, false );
		$value = self::get();
		$value['after'] = is_int( $cursor ) || is_string( $cursor ) ? (string) $cursor : $value['after'];
		return false !== update_option( self::CURSOR_OPTION, $value, false );
	}
	public static function set_state( string $state, string $code = '' ): bool {
		$value = self::get();
		$previous = $value['state'];
		$value['state'] = $state;
		$value['last_error_code'] = $code;
		$value['last_error_at'] = gmdate( 'c' );
		if ( 'expired_gap' === $state && 'expired_gap' !== $previous ) { $value['gap_since'] = $value['last_ok_at'] ?: gmdate( 'c' ); }
		return false !== update_option( self::CURSOR_OPTION, $value, false );
	}
	public static function mark_ok(): bool {
		$value = self::get();
		$value['last_ok_at'] = gmdate( 'c' );
		$value['last_error_code'] = '';
		return false !== update_option( self::CURSOR_OPTION, $value, false );
	}
	public static function reset(): bool {
		return false !== update_option( self::CURSOR_OPTION, array( 'after' => 'latest', 'state' => 'ok', 'last_ok_at' => '', 'last_error_code' => '', 'last_error_at' => '', 'gap_since' => null ), false );
	}
	public static function ack_gap(): bool {
		$value = self::get();
		$value['state'] = 'ok';
		return false !== update_option( self::CURSOR_OPTION, $value, false );
	}
	public static function acquire_lock( int $ttl = 90 ): bool {
		$now = time();
		$until = (int) get_option( self::LOCK_OPTION, 0 );
		if ( $until > $now ) { return false; }
		if ( function_exists( 'add_option' ) && add_option( self::LOCK_OPTION, $now + max( 1, $ttl ), '', 'no' ) ) { return true; }
		$until = (int) get_option( self::LOCK_OPTION, 0 );
		if ( $until > $now ) { return false; }
		return false !== update_option( self::LOCK_OPTION, $now + max( 1, $ttl ), false );
	}
	public static function release_lock(): bool { return false !== delete_option( self::LOCK_OPTION ); }
}
