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
	 * Classify the subject contract before MPR, memory, or profile lookup.
	 *
	 * Guest channels use a durable channel identity first. Admin/member surfaces
	 * use a WordPress user first and must not silently become guest identities.
	 *
	 * @param array $context Runtime identity context.
	 * @return array<string,mixed>
	 */
	public static function subject_contract( array $context = array() ): array {
		$platform = class_exists( 'BizCity_Identity_Hub' )
			? BizCity_Identity_Hub::normalize_platform( (string) ( $context['platform'] ?? $context['channel'] ?? '' ) )
			: strtoupper( trim( (string) ( $context['platform'] ?? $context['channel'] ?? '' ) ) );
		$user_id = (int) ( $context['subject_id'] ?? $context['wp_user_id'] ?? $context['user_id'] ?? 0 );
		$guest_channels = array( 'FB_MESS', 'FACEBOOK', 'MESSENGER', 'ZALO_OA', 'WEBCHAT', 'LANDING_PAGE', 'LANDING' );
		$user_channels  = array( 'ZALO_BOT', 'TELEGRAM', 'TWINCHAT', 'TWINCHAT_BE', 'TWIN_GPT', 'TWINGPT' );
		$stable_identity = ! empty( $context['identity_is_stable'] ) || ! in_array( $platform, array( 'WEBCHAT', 'LANDING_PAGE', 'LANDING' ), true );
		if ( in_array( $platform, $guest_channels, true ) ) {
			$channel_class = 'guest_channel';
		} elseif ( in_array( $platform, $user_channels, true ) ) {
			$channel_class = 'user_bound';
		} elseif ( $user_id > 0 ) {
			$channel_class = 'user_bound';
		} else {
			$channel_class = 'unknown';
		}
		return array(
			'channel'          => $platform,
			'channel_class'    => $channel_class,
			'subject_kind'     => $user_id > 0 ? 'wp_user' : ( 'guest_channel' === $channel_class ? 'channel_identity' : 'unresolved' ),
			'subject_source'   => $user_id > 0 ? 'wp_user_id' : ( 'guest_channel' === $channel_class ? 'third_party_channel' : 'none' ),
			'subject_id'       => $user_id,
			'wp_user_required' => 'user_bound' === $channel_class,
			'identity_first'   => 'guest_channel' === $channel_class,
			'identity_temporary' => 'guest_channel' === $channel_class && ! $stable_identity,
		);
	}

	/**
	 * Resolve the canonical subject contract and identity in one boundary.
	 *
	 * @param array $context Runtime identity context.
	 * @return array<string,mixed>
	 */
	public static function resolve_subject( array $context = array() ): array {
		$contract = self::subject_contract( $context );
		$group = (string) ( $context['chat_kind'] ?? '' ) === 'group'
			|| strtoupper( (string) ( $context['provider_chat_type'] ?? '' ) ) === 'GROUP';
		if ( ! empty( $contract['identity_first'] ) && empty( $contract['identity_temporary'] ) && ! $group ) {
			$context['identity_guest_bind'] = true;
		}
		$scope = self::resolve( $context );
		return array_merge( $contract, array(
			'blog_id'          => (int) ( $scope['blog_id'] ?? get_current_blog_id() ),
			'identity_uuid'    => (string) ( $scope['identity_uuid'] ?? '' ),
			'identity_verified'=> ! empty( $scope['identity_verified'] ),
			'identity_stable'  => ! empty( $scope['identity_is_stable'] ),
			'wp_user_id'       => (int) ( $scope['user_id'] ?? 0 ),
			'can_write'        => ! empty( $scope['can_write'] ),
		) );
	}

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
		$contract = self::subject_contract( $context );
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

			// [2026-08-02 Johnny Chu] TWINBRAIN-EXT-VERTICAL-GUEST-CARE — guest-first bind for third-party
			// channel customers (Zalo/Messenger/WebChat) who never logged into WordPress. Opt-in only:
			// the caller must explicitly assert `identity_guest_bind=true` for a verified 1:1 conversation
			// (never for group chat_id) so a durable identity_uuid can be created without wp_user_id.
			// [2026-08-01 Johnny Chu] PHASE-TWIN-MPR-SUBJECT-G0 — user-bound
			// channels (Zalo Bot, Telegram, Twin GPT, Twin Chat) must never be
			// converted into guest identities, even when a caller supplies a flag.
			if ( ! $verified && $user_id <= 0
				&& 'guest_channel' === ( $contract['channel_class'] ?? '' )
				&& ! empty( $context['identity_guest_bind'] ) ) {
				$g_platform = BizCity_Identity_Hub::normalize_platform( (string) ( $context['platform'] ?? $context['channel'] ?? '' ) );
				$g_account  = trim( (string) ( $context['account_id'] ?? '' ) );
				$g_external = trim( (string) ( $context['external_user_id'] ?? $context['sender_user_id'] ?? '' ) );
				if ( $g_platform === '' || $g_external === '' ) {
					$parsed = BizCity_Identity_Hub::parse_chat_id( (string) ( $context['chat_id'] ?? '' ) );
					if ( is_array( $parsed ) ) {
						$g_platform = $g_platform !== '' ? $g_platform : $parsed['platform'];
						$g_account  = $g_account !== '' ? $g_account : $parsed['account_id'];
						$g_external = $g_external !== '' ? $g_external : $parsed['external_ref'];
					}
				}
				$g_stable = ! empty( $context['identity_is_stable'] ) || ! in_array( $g_platform, array( 'WEBCHAT', 'LANDING_PAGE', 'LANDING' ), true );
				if ( $g_platform !== '' && $g_external !== '' && $g_stable ) {
					$identity = BizCity_Identity_Hub::bind( $g_platform, $g_account, $g_external, 0, $blog_id, true );
					if ( is_array( $identity ) && ! empty( $identity['identity_uuid'] ) ) {
						$identity_uuid = strtolower( trim( (string) $identity['identity_uuid'] ) );
						$verified = true;
						$stable = true;
					}
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
			'channel'            => $contract['channel'],
			'channel_class'      => $contract['channel_class'],
			'subject_kind'       => $identity_uuid !== '' ? ( $user_id > 0 ? 'wp_user' : ( ! empty( $contract['identity_temporary'] ) ? 'temporary_channel_identity' : 'channel_identity' ) ) : $contract['subject_kind'],
			'subject_source'     => $identity_uuid !== '' ? ( $user_id > 0 ? 'wp_user_id' : 'third_party_channel' ) : $contract['subject_source'],
			'wp_user_required'   => (bool) $contract['wp_user_required'],
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
