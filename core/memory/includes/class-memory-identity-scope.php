<?php
/**
 * Shared identity-scoped ownership contract for all memory tiers.
 *
 * New rows require a durable identity_uuid. user_id is retained only as an
 * optional WordPress projection and as a read-only compatibility fallback for
 * legacy rows whose identity_uuid is empty.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Memory
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( class_exists( 'BizCity_Memory_Identity_Scope' ) ) {
	return;
}

final class BizCity_Memory_Identity_Scope {

	/**
	 * Resolve one memory owner from runtime context.
	 *
	 * @param array $context Runtime context. Supports subject_id, user_id,
	 *                       wp_user_id, identity_uuid and channel identity keys.
	 * @return array{blog_id:int,user_id:int,session_id:string,identity_uuid:string,identity_verified:bool,identity_is_stable:bool,can_write:bool}
	 */
	public static function resolve( array $context = array() ): array {
		// [2026-07-28 Johnny Chu] R-CH-IDMEM — resolve one canonical owner before every memory read/write.
		$blog_id = (int) ( $context['blog_id'] ?? get_current_blog_id() );
		$user_id = (int) ( $context['subject_id'] ?? $context['wp_user_id'] ?? $context['user_id'] ?? 0 );
		$session_id = trim( (string) ( $context['session_id'] ?? '' ) );
		$identity_uuid = strtolower( trim( (string) ( $context['identity_uuid'] ?? '' ) ) );
		$verified = false;
		$stable = $user_id > 0 || ! empty( $context['identity_is_stable'] );

		if ( class_exists( 'BizCity_Identity_Hub' ) ) {
			$identity = BizCity_Identity_Hub::resolve_from_opts(
				array_merge( $context, array( 'user_id' => $user_id, 'wp_user_id' => $user_id ) ),
				$blog_id
			);
			if ( is_array( $identity ) && ! empty( $identity['identity_uuid'] ) ) {
				$identity_uuid = strtolower( trim( (string) $identity['identity_uuid'] ) );
				$verified = true;
				// [2026-07-28 Johnny Chu] R-CH-IDMEM — a UUID resolved from the durable hub is stable unless a channel binding explicitly marks it soft.
				$stable = true;
				$user_id = (int) ( $identity['primary_wp_user_id'] ?? $identity['wp_user_id'] ?? $user_id );
				if ( isset( $identity['binding']['is_stable'] ) ) {
					$stable = ! empty( $identity['binding']['is_stable'] );
				}
			}

			if ( ! $verified && $user_id > 0 ) {
				$identity = BizCity_Identity_Hub::bind(
					BizCity_Identity_Hub::PLATFORM_WP,
					(string) $blog_id,
					(string) $user_id,
					$user_id,
					$blog_id,
					true
				);
				if ( is_array( $identity ) && ! empty( $identity['identity_uuid'] ) ) {
					$identity_uuid = strtolower( trim( (string) $identity['identity_uuid'] ) );
					$verified = true;
					$stable = true;
				}
			}
		}

		return array(
			'blog_id'            => $blog_id,
			'user_id'            => $user_id,
			'session_id'         => $session_id,
			'identity_uuid'      => $identity_uuid,
			'identity_verified'  => $verified,
			'identity_is_stable' => $stable,
			'can_write'          => $verified && $identity_uuid !== '' && $stable,
		);
	}

	/**
	 * Resolve an owner suitable for inserting a new memory row.
	 *
	 * @param array $context Runtime context.
	 * @return array|null
	 */
	public static function for_write( array $context = array() ) {
		// [2026-07-28 Johnny Chu] R-CH-IDMEM — no new memory row may use user_id or session_id as its owner key.
		$scope = self::resolve( $context );
		return ! empty( $scope['can_write'] ) ? $scope : null;
	}

	/**
	 * Append an identity-first predicate with a user_id-only legacy fallback.
	 *
	 * The fallback is deliberately restricted to rows with an empty UUID, so a
	 * migrated/new row can never be selected by a different user projection.
	 *
	 * @param array $where SQL fragments.
	 * @param array $params Prepared statement values.
	 * @param array $scope Resolved owner scope.
	 * @return bool Whether a usable predicate was appended.
	 */
	public static function append_read_scope( array &$where, array &$params, array $scope ): bool {
		// [2026-07-28 Johnny Chu] R-CH-IDMEM — identity_uuid is primary; user_id can only recover unmigrated rows.
		$uuid = trim( (string) ( $scope['identity_uuid'] ?? '' ) );
		$user_id = (int) ( $scope['user_id'] ?? 0 );
		if ( $uuid !== '' ) {
			if ( $user_id > 0 ) {
				$where[]  = '( identity_uuid = %s OR ( identity_uuid = %s AND user_id = %d ) )';
				$params[] = $uuid;
				$params[] = '';
				$params[] = $user_id;
			} else {
				$where[]  = 'identity_uuid = %s';
				$params[] = $uuid;
			}
			return true;
		}
		if ( $user_id > 0 ) {
			$where[]  = 'identity_uuid = %s AND user_id = %d';
			$params[] = '';
			$params[] = $user_id;
			return true;
		}
		return false;
	}

	/**
	 * Add owner fields to a new row payload.
	 *
	 * @param array $data Row payload.
	 * @param array $context Optional context override.
	 * @return array|null
	 */
	public static function prepare_write( array $data, array $context = array() ) {
		// [2026-07-28 Johnny Chu] R-CH-IDMEM — normalize every new row around the durable UUID owner.
		$scope = self::for_write( array_merge( $data, $context ) );
		if ( ! $scope ) {
			return null;
		}
		$data['blog_id']       = (int) $scope['blog_id'];
		$data['user_id']       = (int) $scope['user_id'];
		$data['identity_uuid'] = (string) $scope['identity_uuid'];
		return $data;
	}
}
