<?php
/**
 * Generic one-time confirmation boundary for governed Twin mutations.
 *
 * Tokens are transient-backed, tenant/user/action/resource/request-hash bound,
 * and intentionally do not store order, payment or provider payloads.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Twin_Core
 * @since 2026-09-13 (PHASE-0.41-W8.4)
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BizCity_Twin_Action_Confirmation' ) ) {
	final class BizCity_Twin_Action_Confirmation {

		const TTL = 900;
		const PREFIX = 'bizcity_twin_confirm_';

		public static function issue( string $action, string $resource, string $request_hash, array $context ): array {
			// [2026-09-13 10:10 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.41-W8.4 — issue a C-bound confirmation token without creating a business side effect.
			$token = self::PREFIX . strtolower( str_replace( '-', '', wp_generate_uuid4() ) ) . wp_generate_password( 24, false, false );
			$state = array(
				'action'       => self::normalize_action( $action ),
				'resource'     => sanitize_text_field( $resource ),
				'request_hash'  => strtolower( preg_replace( '/[^a-f0-9]/i', '', $request_hash ) ),
				'blog_id'       => (int) ( $context['blog_id'] ?? ( function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 0 ) ),
				'user_id'       => (int) ( $context['user_id'] ?? 0 ),
				'issued_at'     => time(),
				'expires_at'    => time() + self::TTL,
			);
			set_transient( self::key( $token ), $state, self::TTL );
			return array(
				'confirmation_token' => $token,
				'expires_at'         => gmdate( 'c', time() + self::TTL ),
				'action'             => self::normalize_action( $action ),
				'resource'           => sanitize_text_field( $resource ),
				'request_hash'       => $state['request_hash'],
			);
		}

		public static function consume( string $token, string $action, string $resource, string $request_hash, array $context ) {
			// [2026-09-13 10:10 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.41-W8.4 — consume exactly once and fail closed on scope/hash mismatch.
			if ( $token === '' ) { return new WP_Error( 'confirmation_required', 'Cần xác nhận thao tác trước khi tiếp tục.', array( 'status' => 409 ) ); }
			$state = get_transient( self::key( $token ) );
			$expected_blog = (int) ( $context['blog_id'] ?? ( function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 0 ) );
			$expected_user = (int) ( $context['user_id'] ?? 0 );
			$expected_hash = strtolower( preg_replace( '/[^a-f0-9]/i', '', $request_hash ) );
			$valid = is_array( $state )
				&& (string) ( $state['action'] ?? '' ) === self::normalize_action( $action )
				&& (string) ( $state['resource'] ?? '' ) === sanitize_text_field( $resource )
				&& (string) ( $state['request_hash'] ?? '' ) === $expected_hash
				&& (int) ( $state['blog_id'] ?? 0 ) === $expected_blog
				&& (int) ( $state['user_id'] ?? 0 ) === $expected_user
				&& (int) ( $state['expires_at'] ?? 0 ) >= time();
			if ( ! $valid ) { return new WP_Error( 'confirmation_invalid', 'Xác nhận không hợp lệ hoặc đã hết hạn.', array( 'status' => 409 ) ); }
			delete_transient( self::key( $token ) );
			return true;
		}

		private static function key( string $token ): string {
			return 'bzcc_confirm_' . substr( hash( 'sha256', $token ), 0, 40 );
		}

		private static function normalize_action( string $action ): string {
			return strtolower( preg_replace( '/[^a-z0-9._-]+/i', '', trim( $action ) ) );
		}
	}
}
