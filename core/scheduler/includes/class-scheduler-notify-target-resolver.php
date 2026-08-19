<?php
/**
 * Canonical notification target resolver shared by Scheduler and projections.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Scheduler
 * @since 2026-08-16
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( class_exists( 'BizCity_Scheduler_Notify_Target_Resolver' ) ) {
	return;
}

final class BizCity_Scheduler_Notify_Target_Resolver {

	/**
	 * Resolve one notification target using the Scheduler canonical precedence.
	 *
	 * @param array $row Event/run owner row.
	 * @param array $meta Decoded metadata containing notify/inbound blocks.
	 * @return array|null {platform:string,chat_id:string}
	 */
	public static function resolve( array $row, array $meta ) {
		// [2026-08-16 Johnny Chu] R-SCH-TARGET — one fail-closed target precedence for Scheduler and progress projections.
		if ( isset( $meta['notify'] ) && false === $meta['notify'] ) {
			return null;
		}
		if ( isset( $meta['notify']['target'] ) && is_array( $meta['notify']['target'] ) ) {
			$target = self::normalize( $meta['notify']['target'] );
			if ( $target ) {
				return $target;
			}
		}
		if ( isset( $meta['inbound'] ) && is_array( $meta['inbound'] ) ) {
			$target = self::normalize( $meta['inbound'] );
			if ( $target ) {
				return $target;
			}
		}
		$user_id = isset( $row['user_id'] ) ? (int) $row['user_id'] : 0;
		if ( $user_id > 0 ) {
			$preference = class_exists( 'BizCity_User_Meta_Cache' )
				? BizCity_User_Meta_Cache::get( $user_id, 'bizcity_default_notify_channel', array() )
				: get_user_meta( $user_id, 'bizcity_default_notify_channel', true );
			$target = self::normalize( $preference );
			if ( $target ) {
				return $target;
			}
		}
		$target = self::normalize( get_option( 'bizcity_default_notify_channel', array() ) );
		if ( $target ) {
			return $target;
		}
		$filtered = apply_filters( 'bizcity_scheduler_resolve_default_channel', null, $row, $meta );
		return self::normalize( $filtered );
	}

	private static function normalize( $target ) {
		if ( ! is_array( $target ) ) {
			return null;
		}
		$platform = sanitize_key( (string) ( $target['platform'] ?? $target['channel'] ?? '' ) );
		$chat_id = trim( (string) ( $target['chat_id'] ?? $target['conversation_chat_id'] ?? '' ) );
		if ( $platform === '' || $chat_id === '' ) {
			return null;
		}
		return array(
			'platform' => $platform,
			'chat_id'  => $chat_id,
		);
	}
}
