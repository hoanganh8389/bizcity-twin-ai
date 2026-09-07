<?php
/**
 * Exact channel-account user grants for bounded member access.
 *
 * User meta stores only current grant projections. Provider credentials,
 * external identities, CRM assignment and Context Bank payloads remain owned by
 * their canonical modules.
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Channel_User_Grant', false ) ) {
	return;
}

final class BizCity_Channel_User_Grant {

	const CONTRACT = 'core.channel_gateway.channel_user_grant';
	const VERSION  = '1.0';
	const META_PREFIX = '_bizcity_chgrant_v1_';
	const MAX_USERS_PER_ACCOUNT = 4;
	const PERMISSIONS = array(
		'view_connection',
		'view_context',
		'view_conversations',
		'reply',
		'publish',
		'manage_grants',
		'transfer_primary',
	);
	const CHANNELS = array(
		'facebook',
		'messenger',
		'zalo_oa',
		'zalo_personal',
		'webchat',
		'email',
		'zalo_bot',
		'telegram',
	);

	/**
	 * Build the tenant-bound opaque account identity used in user meta keys.
	 */
	public static function account_key( $channel, $account_id, $blog_id = 0 ) {
		// [2026-09-05 Johnny Chu - Chu Hoàng Anh] PHASE-1.33A — derive a tenant/channel/account HMAC without exposing provider identifiers in user meta.
		$channel   = sanitize_key( (string) $channel );
		$account_id = trim( (string) $account_id );
		$blog_id   = $blog_id > 0 ? (int) $blog_id : (int) get_current_blog_id();
		if ( ! in_array( $channel, self::CHANNELS, true ) || $account_id === '' || $blog_id <= 0 ) {
			return '';
		}
		$body = $blog_id . '|' . $channel . '|' . $account_id;
		$secret = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' );
		if ( $secret === '' ) {
			return '';
		}
		return 'a_' . hash_hmac( 'sha256', $body, $secret );
	}

	public static function meta_key( $channel, $account_id, $blog_id = 0 ) {
		// [2026-09-05 Johnny Chu - Chu Hoàng Anh] PHASE-1.33A — keep one exact user-meta key per tenant/channel/account grant.
		$blog_id = $blog_id > 0 ? (int) $blog_id : (int) get_current_blog_id();
		$channel = sanitize_key( (string) $channel );
		$account_key = self::account_key( $channel, $account_id, $blog_id );
		return $account_key === '' ? '' : self::META_PREFIX . $blog_id . '_' . $channel . '_' . $account_key;
	}

	/**
	 * Bind the current /gpt/ user as primary after the connection owner verified
	 * the exact provider account. Browser user IDs are intentionally ignored.
	 */
	public static function bind_primary_from_current( $channel, $account_id, array $context = array() ) {
		// [2026-09-05 Johnny Chu - Chu Hoàng Anh] PHASE-1.33A — bind only the server-resolved current user after the provider owner verifies the connection.
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( $user_id <= 0 ) {
			return self::failure( 'auth_required' );
		}
		if ( empty( $context['connection_verified'] ) ) {
			return self::failure( 'account_verification_required' );
		}
		// [2026-09-05 Johnny Chu - Chu Hoàng Anh] PHASE-1.33A — never infer the provider owner from an account id or browser payload.
		if ( (int) ( $context['connection_owner_user_id'] ?? 0 ) !== $user_id ) {
			return self::failure( 'connection_owner_mismatch' );
		}
		$meta_key = self::meta_key( $channel, $account_id );
		if ( $meta_key === '' ) {
			return self::failure( 'invalid_channel_account' );
		}
		$users = self::users_for_account( $channel, $account_id );
		$primary_ids = array();
		foreach ( $users as $grant ) {
			if ( (string) ( $grant['relation'] ?? '' ) === 'primary' && (string) ( $grant['status'] ?? '' ) === 'active' ) {
				$primary_ids[] = (int) $grant['user_id'];
			}
		}
		$primary_ids = array_values( array_unique( $primary_ids ) );
		if ( count( $primary_ids ) > 1 ) {
			return self::failure( 'channel_primary_conflict', array( 'primary_user_ids' => $primary_ids ) );
		}
		if ( ! empty( $primary_ids ) && (int) $primary_ids[0] !== $user_id ) {
			return self::failure( 'channel_primary_exists', array( 'primary_user_id' => (int) $primary_ids[0] ) );
		}
		$personal_primary = $channel === 'zalo_personal' ? self::has_other_personal_primary( $user_id, $account_id ) : false;
		if ( $personal_primary === null ) {
			return self::failure( 'personal_primary_check_failed' );
		}
		if ( $personal_primary ) {
			return self::failure( 'personal_primary_limit_reached' );
		}
		return self::write_grant( $user_id, $channel, $account_id, 'primary', self::default_primary_permissions(), $user_id, $context );
	}

	/**
	 * Grant exact-account access to another existing WP user.
	 */
	public static function grant( $channel, $account_id, $delegate_user_id, array $permissions = array(), $actor_user_id = 0, array $context = array() ) {
		// [2026-09-05 Johnny Chu - Chu Hoàng Anh] PHASE-1.33A — grant bounded exact-account permissions without changing the primary owner.
		$actor_user_id = $actor_user_id > 0 ? (int) $actor_user_id : (int) get_current_user_id();
		$delegate_user_id = (int) $delegate_user_id;
		if ( $actor_user_id <= 0 || $delegate_user_id <= 0 ) {
			return self::failure( 'auth_required' );
		}
		$primary = self::primary_for_account( $channel, $account_id );
		if ( ! empty( $primary['conflict'] ) ) {
			return self::failure( 'channel_primary_conflict' );
		}
		if ( empty( $primary['user_id'] ) ) {
			return self::failure( 'channel_primary_missing' );
		}
		$actor = self::authorize( $channel, $account_id, $actor_user_id, 'manage_grants' );
		if ( empty( $actor['ok'] ) && ! ( function_exists( 'user_can' ) && user_can( $actor_user_id, 'manage_options' ) ) ) {
			return self::failure( 'grant_manage_denied' );
		}
		if ( $delegate_user_id === (int) $primary['user_id'] ) {
			return self::failure( 'primary_is_not_delegate' );
		}
		if ( ! function_exists( 'get_userdata' ) || ! get_userdata( $delegate_user_id ) ) {
			return self::failure( 'delegate_user_not_found' );
		}
		if ( ! self::is_current_blog_member( $delegate_user_id ) ) {
			return self::failure( 'delegate_not_member' );
		}
		$users = self::users_for_account( $channel, $account_id );
		$active_count = 0;
		foreach ( $users as $grant ) {
			if ( (string) ( $grant['status'] ?? '' ) === 'active' ) {
				$active_count++;
			}
		}
		if ( $active_count >= self::MAX_USERS_PER_ACCOUNT && ! self::authorize( $channel, $account_id, $delegate_user_id, 'view_connection' )['ok'] ) {
			return self::failure( 'delegate_limit_reached' );
		}
		return self::write_grant( $delegate_user_id, $channel, $account_id, 'agent', $permissions, $actor_user_id, $context );
	}

	/**
	 * Transfer the singular primary relation to an existing active delegate.
	 */
	public static function transfer_primary( $channel, $account_id, $new_primary_user_id, $actor_user_id = 0, array $context = array() ) {
		// [2026-09-05 Johnny Chu - Chu Hoàng Anh] PHASE-1.33A — transfer primary ownership only through an explicit server-side operation.
		$new_primary_user_id = (int) $new_primary_user_id;
		$actor_user_id = $actor_user_id > 0 ? (int) $actor_user_id : (int) get_current_user_id();
		$primary = self::primary_for_account( $channel, $account_id );
		if ( ! empty( $primary['conflict'] ) ) {
			return self::failure( 'channel_primary_conflict' );
		}
		if ( empty( $primary['user_id'] ) ) {
			return self::failure( 'channel_primary_missing' );
		}
		$actor = self::authorize( $channel, $account_id, $actor_user_id, 'transfer_primary' );
		if ( empty( $actor['ok'] ) && ! ( function_exists( 'user_can' ) && user_can( $actor_user_id, 'manage_options' ) ) ) {
			return self::failure( 'primary_transfer_denied' );
		}
		if ( $new_primary_user_id === (int) $primary['user_id'] ) {
			return array( 'ok' => true, 'status' => 'unchanged', 'user_id' => $new_primary_user_id, 'account_key' => self::account_key( $channel, $account_id ) );
		}
		if ( ! function_exists( 'get_userdata' ) || ! get_userdata( $new_primary_user_id ) || ! self::is_current_blog_member( $new_primary_user_id ) ) {
			return self::failure( 'primary_target_not_member' );
		}
		$target = self::read_grant( $new_primary_user_id, self::meta_key( $channel, $account_id ), array() );
		if ( ! self::grant_matches_scope( $target, $channel, self::account_key( $channel, $account_id ), (int) get_current_blog_id() ) || (string) ( $target['status'] ?? '' ) !== 'active' || (string) ( $target['relation'] ?? '' ) !== 'agent' ) {
			return self::failure( 'primary_target_delegate_required' );
		}
		$old_meta_key = self::meta_key( $channel, $account_id );
		$old_grant = self::read_grant( (int) $primary['user_id'], $old_meta_key, array() );
		$target['relation'] = 'primary';
		$target['permissions'] = self::default_primary_permissions();
		$target['updated_at'] = gmdate( 'c' );
		$target['granted_by'] = $actor_user_id;
		$old_grant['relation'] = 'agent';
		$old_grant['permissions'] = array_values( array_diff( (array) ( $old_grant['permissions'] ?? array() ), array( 'manage_grants', 'transfer_primary' ) ) );
		$old_grant['updated_at'] = gmdate( 'c' );
		self::audit( $channel, 'primary_transfer_attempt', array( 'actor_user_id' => $actor_user_id, 'from_user_id' => (int) $primary['user_id'], 'to_user_id' => $new_primary_user_id ) );
		// [2026-09-05 Johnny Chu - Chu Hoàng Anh] PHASE-1.33A — demote first, then promote; rollback the old primary if promotion fails.
		if ( ! self::write_meta( (int) $primary['user_id'], $old_meta_key, $old_grant ) ) {
			self::audit( $channel, 'primary_transfer_incomplete', array( 'actor_user_id' => $actor_user_id, 'from_user_id' => (int) $primary['user_id'], 'to_user_id' => $new_primary_user_id ) );
			return self::failure( 'primary_transfer_incomplete' );
		}
		if ( ! self::write_meta( $new_primary_user_id, $old_meta_key, $target ) ) {
			$old_grant['relation'] = 'primary';
			$old_grant['permissions'] = self::default_primary_permissions();
			$old_grant['updated_at'] = gmdate( 'c' );
			$rollback_ok = self::write_meta( (int) $primary['user_id'], $old_meta_key, $old_grant );
			self::audit( $channel, 'primary_transfer_incomplete', array( 'actor_user_id' => $actor_user_id, 'from_user_id' => (int) $primary['user_id'], 'to_user_id' => $new_primary_user_id ) );
			return self::failure( $rollback_ok ? 'primary_transfer_incomplete' : 'primary_transfer_rollback_failed' );
		}
		self::invalidate_authorization_cache();
		self::audit( $channel, 'primary_transfer_ok', array( 'actor_user_id' => $actor_user_id, 'from_user_id' => (int) $primary['user_id'], 'to_user_id' => $new_primary_user_id ) );
		return array( 'ok' => true, 'status' => 'transferred', 'user_id' => $new_primary_user_id, 'account_key' => self::account_key( $channel, $account_id ) );
	}

	public static function revoke( $channel, $account_id, $user_id, $actor_user_id = 0, array $context = array() ) {
		// [2026-09-05 Johnny Chu - Chu Hoàng Anh] PHASE-1.33A — revoke one delegate immediately while refusing implicit primary replacement.
		$user_id = (int) $user_id;
		$actor_user_id = $actor_user_id > 0 ? (int) $actor_user_id : (int) get_current_user_id();
		$primary = self::primary_for_account( $channel, $account_id );
		if ( ! empty( $primary['conflict'] ) ) {
			return self::failure( 'channel_primary_conflict' );
		}
		if ( empty( $primary['user_id'] ) ) {
			return self::failure( 'channel_primary_missing' );
		}
		if ( $user_id === (int) $primary['user_id'] ) {
			return self::failure( 'primary_transfer_required' );
		}
		$actor = self::authorize( $channel, $account_id, $actor_user_id, 'manage_grants' );
		if ( empty( $actor['ok'] ) && ! ( function_exists( 'user_can' ) && user_can( $actor_user_id, 'manage_options' ) ) ) {
			return self::failure( 'grant_manage_denied' );
		}
		$meta_key = self::meta_key( $channel, $account_id );
		$grant = self::read_grant( $user_id, $meta_key, array() );
		if ( ! self::grant_matches_scope( $grant, $channel, self::account_key( $channel, $account_id ), (int) get_current_blog_id() ) || (string) ( $grant['status'] ?? '' ) !== 'active' ) {
			return self::failure( 'grant_not_found' );
		}
		$grant['status'] = 'revoked';
		$grant['updated_at'] = gmdate( 'c' );
		$grant['revoked_by'] = $actor_user_id;
		self::audit( $channel, 'grant_revoked', array( 'actor_user_id' => $actor_user_id, 'target_user_id' => $user_id ) );
		if ( ! self::write_meta( $user_id, $meta_key, $grant ) ) {
			return self::failure( 'grant_revoke_failed' );
		}
		self::invalidate_authorization_cache();
		return array( 'ok' => true, 'status' => 'revoked', 'user_id' => $user_id, 'account_key' => self::account_key( $channel, $account_id ) );
	}

	public static function authorize( $channel, $account_id, $user_id, $permission = 'view_context' ) {
		// [2026-09-05 Johnny Chu - Chu Hoàng Anh] PHASE-1.33A — authorize an exact user/account permission from the current grant projection.
		$user_id = (int) $user_id;
		$meta_key = self::meta_key( $channel, $account_id );
		if ( $user_id <= 0 || $meta_key === '' || ! in_array( (string) $permission, self::PERMISSIONS, true ) ) {
			return array( 'ok' => false, 'reason' => 'grant_scope_invalid' );
		}
		$grant = self::read_grant( $user_id, $meta_key, array() );
		if ( ! is_array( $grant ) ) {
			return array( 'ok' => false, 'reason' => 'grant_not_found' );
		}
		$expected_account_key = self::account_key( $channel, $account_id );
		if ( ! self::grant_matches_scope( $grant, $channel, $expected_account_key, (int) get_current_blog_id() ) ) {
			return array( 'ok' => false, 'reason' => 'grant_scope_mismatch' );
		}
		if ( (string) ( $grant['status'] ?? '' ) !== 'active' ) {
			return array( 'ok' => false, 'reason' => 'grant_not_found' );
		}
		$permissions = array_map( 'sanitize_key', (array) ( $grant['permissions'] ?? array() ) );
		if ( ! in_array( (string) $permission, $permissions, true ) ) {
			return array( 'ok' => false, 'reason' => 'grant_permission_denied' );
		}
		return array( 'ok' => true, 'user_id' => $user_id, 'relation' => (string) ( $grant['relation'] ?? 'agent' ), 'permissions' => $permissions, 'account_key' => self::account_key( $channel, $account_id ) );
	}

	/**
	 * Authorize a pointer that carries only the canonical HMAC account key.
	 */
	public static function authorize_account_key( $channel, $account_key, $user_id, $permission = 'view_context' ) {
		// [2026-09-05 Johnny Chu - Chu Hoàng Anh] PHASE-1.33A — authorize Context Bank pointers without reconstructing or exposing the raw provider account ID.
		$channel = sanitize_key( (string) $channel );
		$account_key = strtolower( trim( (string) $account_key ) );
		$user_id = (int) $user_id;
		$blog_id = (int) get_current_blog_id();
		if ( ! in_array( $channel, self::CHANNELS, true ) || ! preg_match( '/^a_[a-f0-9]{64}$/', $account_key ) || $user_id <= 0 || $blog_id <= 0 ) {
			return array( 'ok' => false, 'reason' => 'grant_scope_invalid' );
		}
		$meta_key = self::META_PREFIX . $blog_id . '_' . $channel . '_' . $account_key;
		$grant = self::read_grant( $user_id, $meta_key, array() );
		if ( ! is_array( $grant ) ) {
			return array( 'ok' => false, 'reason' => 'grant_not_found' );
		}
		if ( ! self::grant_matches_scope( $grant, $channel, $account_key, $blog_id ) ) {
			return array( 'ok' => false, 'reason' => 'grant_scope_mismatch' );
		}
		if ( (string) ( $grant['status'] ?? '' ) !== 'active' ) {
			return array( 'ok' => false, 'reason' => 'grant_not_found' );
		}
		$permissions = array_map( 'sanitize_key', (array) ( $grant['permissions'] ?? array() ) );
		if ( ! in_array( (string) $permission, $permissions, true ) ) {
			return array( 'ok' => false, 'reason' => 'grant_permission_denied' );
		}
		return array( 'ok' => true, 'user_id' => $user_id, 'relation' => (string) ( $grant['relation'] ?? 'agent' ), 'permissions' => $permissions, 'account_key' => $account_key );
	}

	public static function primary_for_account( $channel, $account_id ) {
		// [2026-09-05 Johnny Chu - Chu Hoàng Anh] PHASE-1.33A — return one primary only; multiple primaries fail closed.
		$primary = array();
		foreach ( self::users_for_account( $channel, $account_id ) as $grant ) {
			if ( (string) ( $grant['status'] ?? '' ) === 'active' && (string) ( $grant['relation'] ?? '' ) === 'primary' ) {
				if ( ! empty( $primary ) ) {
					return array( 'conflict' => true, 'user_id' => 0 );
				}
				$primary = $grant;
			}
		}
		return $primary;
	}

	public static function users_for_account( $channel, $account_id ) {
		// [2026-09-05 Johnny Chu - Chu Hoàng Anh] PHASE-1.33A — reverse-resolve a bounded roster through the exact meta key.
		$meta_key = self::meta_key( $channel, $account_id );
		if ( $meta_key === '' || ! function_exists( 'get_users' ) ) {
			return array();
		}
		$users = get_users( array( 'meta_key' => $meta_key, 'fields' => array( 'ID' ), 'number' => self::MAX_USERS_PER_ACCOUNT + 1 ) );
		$out = array();
		foreach ( (array) $users as $user ) {
			$user_id = is_object( $user ) ? (int) $user->ID : (int) ( $user['ID'] ?? 0 );
			$grant = self::read_grant( $user_id, $meta_key, array() );
			if ( $user_id > 0 && self::grant_matches_scope( $grant, $channel, self::account_key( $channel, $account_id ), (int) get_current_blog_id() ) && (string) ( $grant['status'] ?? '' ) === 'active' ) {
				$grant['user_id'] = $user_id;
				$out[] = $grant;
			}
		}
		return $out;
	}

	private static function write_grant( $user_id, $channel, $account_id, $relation, array $permissions, $granted_by, array $context ) {
		// [2026-09-05 Johnny Chu - Chu Hoàng Anh] PHASE-1.33A — persist only bounded grant metadata, never credentials or provider identity.
		$meta_key = self::meta_key( $channel, $account_id );
		if ( $meta_key === '' ) {
			return self::failure( 'invalid_channel_account' );
		}
		$allowed = array_values( array_intersect( self::PERMISSIONS, array_map( 'sanitize_key', $permissions ) ) );
		if ( $relation === 'primary' ) {
			$allowed = array_values( array_unique( array_merge( self::default_primary_permissions(), $allowed ) ) );
		} elseif ( empty( $allowed ) ) {
			$allowed = self::default_delegate_permissions();
		}
		$grant = array(
			'contract' => self::CONTRACT,
			'version' => self::VERSION,
			'blog_id' => (int) get_current_blog_id(),
			'channel' => sanitize_key( (string) $channel ),
			'account_key' => self::account_key( $channel, $account_id ),
			'relation' => $relation,
			'permissions' => $allowed,
			'status' => 'active',
			'granted_by' => (int) $granted_by,
			'source' => sanitize_key( (string) ( $context['source'] ?? 'channel_gateway' ) ),
			'issued_at' => gmdate( 'c' ),
			'updated_at' => gmdate( 'c' ),
		);
		self::audit( $channel, $relation === 'primary' ? 'primary_bound' : 'delegate_granted', array( 'actor_user_id' => (int) $granted_by, 'target_user_id' => (int) $user_id, 'permission_count' => count( $allowed ) ) );
		if ( ! self::write_meta( (int) $user_id, $meta_key, $grant ) ) {
			return self::failure( 'grant_write_failed' );
		}
		self::invalidate_authorization_cache();
		return array( 'ok' => true, 'user_id' => (int) $user_id, 'relation' => $relation, 'account_key' => $grant['account_key'], 'meta_key' => $meta_key );
	}

	private static function write_meta( $user_id, $meta_key, array $grant ) {
		// [2026-09-05 Johnny Chu - Chu Hoàng Anh] PHASE-1.33A — use the shared user-meta cache facade when available.
		if ( class_exists( 'BizCity_User_Meta_Cache' ) && method_exists( 'BizCity_User_Meta_Cache', 'set' ) ) {
			$written = (bool) BizCity_User_Meta_Cache::set( (int) $user_id, $meta_key, $grant );
			return $written || self::grant_equals( self::read_grant( $user_id, $meta_key, array() ), $grant );
		}
		$written = (bool) update_user_meta( (int) $user_id, $meta_key, $grant );
		return $written || self::grant_equals( get_user_meta( (int) $user_id, $meta_key, true ), $grant );
	}

	private static function grant_matches_scope( $grant, $channel, $account_key, $blog_id ) {
		// [2026-09-05 Johnny Chu - Chu Hoàng Anh] PHASE-1.33A — validate the signed scope inside usermeta, not only the derived meta key.
		return is_array( $grant )
			&& (string) ( $grant['contract'] ?? '' ) === self::CONTRACT
			&& (string) ( $grant['version'] ?? '' ) === self::VERSION
			&& (int) ( $grant['blog_id'] ?? 0 ) === (int) $blog_id
			&& sanitize_key( (string) ( $grant['channel'] ?? '' ) ) === sanitize_key( (string) $channel )
			&& strtolower( (string) ( $grant['account_key'] ?? '' ) ) === strtolower( (string) $account_key );
	}

	private static function grant_equals( $stored, array $expected ) {
		// [2026-09-05 Johnny Chu - Chu Hoàng Anh] PHASE-1.33A — treat an unchanged idempotent projection as a successful persistence outcome.
		return is_array( $stored ) && wp_json_encode( $stored ) === wp_json_encode( $expected );
	}

	private static function invalidate_authorization_cache() {
		// [2026-09-06 Johnny Chu - Chu Hoàng Anh] PHASE-1.33A — invalidate only the canonical Context Bank ledger/search cache after an effective grant mutation.
		if ( class_exists( 'BizCity_Context_Bank_Ledger' ) && method_exists( 'BizCity_Context_Bank_Ledger', 'invalidate_authorization_cache' ) ) {
			BizCity_Context_Bank_Ledger::invalidate_authorization_cache();
		}
	}

	private static function default_primary_permissions() {
		// [2026-09-05 Johnny Chu - Chu Hoàng Anh] PHASE-1.33A — define the primary's bounded permission set.
		return array( 'view_connection', 'view_context', 'view_conversations', 'reply', 'publish', 'manage_grants', 'transfer_primary' );
	}

	private static function default_delegate_permissions() {
		// [2026-09-05 Johnny Chu - Chu Hoàng Anh] PHASE-1.33A — give an unspecified delegate the minimum read-context grant.
		return array( 'view_context' );
	}

	private static function read_grant( $user_id, $meta_key, $default = array() ) {
		// [2026-09-05 Johnny Chu - Chu Hoàng Anh] PHASE-1.33A — keep grant reads coherent with the shared user-meta request cache.
		if ( class_exists( 'BizCity_User_Meta_Cache' ) && method_exists( 'BizCity_User_Meta_Cache', 'get' ) ) {
			return BizCity_User_Meta_Cache::get( (int) $user_id, $meta_key, $default );
		}
		return get_user_meta( (int) $user_id, $meta_key, true );
	}

	private static function is_current_blog_member( $user_id ) {
		// [2026-09-05 Johnny Chu - Chu Hoàng Anh] PHASE-1.33A — delegate and transfer targets must belong to the current physical tenant.
		$blog_id = (int) get_current_blog_id();
		if ( function_exists( 'is_user_member_of_blog' ) && is_user_member_of_blog( (int) $user_id, $blog_id ) ) {
			return true;
		}
		// [2026-09-06 Johnny Chu - Chu Hoàng Anh] PHASE-1.33A — use the current blog capability meta as the multisite membership source when WordPress membership cache is stale.
		if ( $blog_id > 0 && function_exists( 'get_user_meta' ) ) {
			$capabilities = get_user_meta( (int) $user_id, $blog_id . '_capabilities', true );
			return is_array( $capabilities ) && ! empty( $capabilities );
		}
		return false;
	}

	private static function has_other_personal_primary( $user_id, $account_id ) {
		// [2026-09-05 Johnny Chu - Chu Hoàng Anh] PHASE-1.33A — enforce one active Zalo Personal primary account per user and blog.
		global $wpdb;
		if ( ! isset( $wpdb->usermeta ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_results' ) ) {
			return false;
		}
		$prefix = self::META_PREFIX . (int) get_current_blog_id() . '_zalo_personal_';
		$like = method_exists( $wpdb, 'esc_like' ) ? $wpdb->esc_like( $prefix ) . '%' : $prefix . '%';
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT meta_key, meta_value FROM ' . $wpdb->usermeta . ' WHERE user_id = %d AND meta_key LIKE %s', (int) $user_id, $like ), ARRAY_A );
		if ( false === $rows ) {
			return null;
		}
		$current_key = self::meta_key( 'zalo_personal', $account_id );
		foreach ( (array) $rows as $row ) {
			if ( (string) ( $row['meta_key'] ?? '' ) === $current_key ) {
				continue;
			}
			$grant = maybe_unserialize( $row['meta_value'] ?? '' );
			if ( is_array( $grant ) && (string) ( $grant['relation'] ?? '' ) === 'primary' && (string) ( $grant['status'] ?? '' ) === 'active' ) {
				return true;
			}
		}
		return false;
	}

	private static function audit( $channel, $event, array $context ) {
		// [2026-09-05 Johnny Chu - Chu Hoàng Anh] PHASE-1.33A — record grant decisions without provider IDs, credentials, payload or PII.
		if ( class_exists( 'BizCity_Channel_File_Logger' ) && method_exists( 'BizCity_Channel_File_Logger', 'write' ) ) {
			try {
				BizCity_Channel_File_Logger::write( sanitize_key( (string) $channel ), BizCity_Channel_File_Logger::LEVEL_INFO, sanitize_key( (string) $event ), 'Channel account grant decision.', $context );
			} catch ( Throwable $e ) {
				return;
			}
		}
	}

	private static function failure( $reason, array $extra = array() ) {
		// [2026-09-05 Johnny Chu - Chu Hoàng Anh] PHASE-1.33A — return stable reason buckets for diagnostics and API callers.
		return array_merge( array( 'ok' => false, 'reason' => sanitize_key( (string) $reason ) ), $extra );
	}
}
