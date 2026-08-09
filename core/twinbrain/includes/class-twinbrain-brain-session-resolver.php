<?php
/**
 * Resolve a Brain session for guest and WordPress-bound channel subjects.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\TwinBrain
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_TwinBrain_Brain_Session_Resolver' ) ) {
	return;
}

final class BizCity_TwinBrain_Brain_Session_Resolver {

	/**
	 * Resolve or mint the stable session id used by one inbound envelope.
	 *
	 * @return array{ok:bool,session_id:string,owner:string,error?:string}
	 */
	public static function resolve( array $envelope ): array {
		// [2026-08-01 Johnny Chu] PHASE-TWIN-GOAL-LOOP-G3 — resolve one Brain session for both identity owners.
		$user_id = (int) ( $envelope['wp_user_id'] ?? $envelope['user_id'] ?? 0 );
		$identity_uuid = strtolower( trim( (string) ( $envelope['identity_uuid'] ?? '' ) ) );
		$requested = trim( (string) ( $envelope['session_id'] ?? '' ) );
		$channel_class = (string) ( $envelope['channel_class'] ?? '' );

		if ( 'user_bound' === $channel_class && $user_id <= 0 ) {
			return array( 'ok' => false, 'session_id' => '', 'owner' => 'unresolved', 'error' => 'wp_user_id_required' );
		}

		if ( $user_id > 0 && class_exists( 'BizCity_TwinBrain_Sessions_Manager' ) ) {
			$manager = BizCity_TwinBrain_Sessions_Manager::instance();
			if ( $requested === '' ) {
				// [2026-08-02 Johnny Chu] PHASE-TWIN-GOAL-LOOP-G4 — derive one stable Brain session per linked channel identity.
				$requested = self::stable_user_session_id( $envelope, $user_id );
			}
			if ( $requested !== '' && BizCity_TwinBrain_Sessions_Manager::is_valid_session_id( $requested ) ) {
				$existing = $manager->get( $requested, $user_id );
				if ( ! empty( $existing ) ) {
					return array( 'ok' => true, 'session_id' => $requested, 'owner' => 'wp_user' );
				}
			}
			$created = $manager->create( array( 'user_id' => $user_id, 'session_id' => $requested, 'source' => 'channel' ) );
			$session_id = (string) ( $created['session_id'] ?? '' );
			if ( $session_id === '' ) {
				return array( 'ok' => false, 'session_id' => '', 'owner' => 'wp_user', 'error' => (string) ( $created['error'] ?? 'session_create_failed' ) );
			}
			return array( 'ok' => true, 'session_id' => $session_id, 'owner' => 'wp_user' );
		}

		if ( $identity_uuid === '' ) {
			return array( 'ok' => false, 'session_id' => '', 'owner' => 'unresolved', 'error' => 'identity_uuid_required' );
		}
		$guest_session = $requested !== '' ? $requested : trim( (string) ( $envelope['chat_id'] ?? '' ) );
		if ( $guest_session === '' ) {
			return array( 'ok' => false, 'session_id' => '', 'owner' => 'guest_identity', 'error' => 'chat_id_required' );
		}
		return array( 'ok' => true, 'session_id' => $guest_session, 'owner' => 'guest_identity' );
	}

	private static function stable_user_session_id( array $envelope, int $user_id ): string {
		$channel_key = implode( '|', array(
			(string) ( $envelope['platform'] ?? $envelope['channel'] ?? '' ),
			(string) ( $envelope['account_id'] ?? '' ),
			(string) ( $envelope['external_user_id'] ?? '' ),
			(string) ( $envelope['chat_id'] ?? '' ),
			(string) $user_id,
		) );
		$numeric = sprintf( '%u', crc32( $channel_key ) );
		$suffix = substr( md5( $channel_key ), 0, 4 );
		return 'brain_sess_' . $numeric . '_' . $user_id . '_' . $suffix;
	}

	/**
	 * Build the options consumed by TwinBrain Runtime from a canonical envelope.
	 *
	 * @return array<string,mixed>
	 */
	public static function build_opts( array $envelope ): array {
		// [2026-08-01 Johnny Chu] PHASE-TWIN-GOAL-LOOP-G3 — keep identity and channel policy in one runtime boundary.
		$subject = array();
		if ( class_exists( 'BizCity_Memory_Identity_Scope' ) ) {
			$subject = BizCity_Memory_Identity_Scope::resolve_subject( $envelope );
			if ( ! empty( $subject['identity_uuid'] ) ) {
				$envelope['identity_uuid'] = (string) $subject['identity_uuid'];
			}
			if ( ! empty( $subject['wp_user_id'] ) && empty( $envelope['wp_user_id'] ) ) {
				$envelope['wp_user_id'] = (int) $subject['wp_user_id'];
			}
		}
		$resolved = self::resolve( $envelope );
		if ( empty( $resolved['ok'] ) ) {
			return array( '_session_error' => (string) ( $resolved['error'] ?? 'session_unresolved' ) );
		}
		$opts = array(
			'user_id'          => (int) ( $envelope['wp_user_id'] ?? $envelope['user_id'] ?? 0 ),
			'wp_user_id'       => (int) ( $envelope['wp_user_id'] ?? $envelope['user_id'] ?? 0 ),
			'identity_uuid'    => strtolower( trim( (string) ( $envelope['identity_uuid'] ?? $subject['identity_uuid'] ?? '' ) ) ),
			'session_id'       => (string) $resolved['session_id'],
			'channel'          => (string) ( $envelope['platform'] ?? $envelope['channel'] ?? '' ),
			'platform'         => (string) ( $envelope['platform'] ?? $envelope['channel'] ?? '' ),
			'account_id'       => (string) ( $envelope['account_id'] ?? '' ),
			'external_user_id' => (string) ( $envelope['external_user_id'] ?? '' ),
			'chat_id'          => (string) ( $envelope['chat_id'] ?? '' ),
			'chat_kind'        => (string) ( $envelope['chat_kind'] ?? '' ),
			'provider_chat_type' => (string) ( $envelope['provider_chat_type'] ?? '' ),
			'identity_is_stable' => ! empty( $envelope['identity_is_stable'] ),
			'channel_class'    => (string) ( $envelope['channel_class'] ?? $subject['channel_class'] ?? '' ),
			'identity_guest_bind' => ! empty( $envelope['identity_guest_bind'] ),
			'surface'          => (string) ( $envelope['surface'] ?? 'channel_adapter' ),
		);
		// [2026-08-03 Johnny Chu] R-TGL-CS — forward Goal/Case scope through the canonical session boundary.
		foreach ( array( 'k', 'guru_id', 'notebook_id', 'mode', 'web_mode', 'memory_scope', 'case_id', 'subject_key', 'goal_id' ) as $key ) {
			if ( array_key_exists( $key, $envelope ) ) {
				$opts[ $key ] = $envelope[ $key ];
			}
		}
		// [2026-08-02 Johnny Chu] PHASE-ZALO-VISION — carry channel media into the canonical Brain runtime for vision/file-aware fallback replies.
		$images = array();
		foreach ( array( $envelope['images'] ?? array(), $envelope['attachment_urls'] ?? array(), $envelope['media_url'] ?? '' ) as $candidate ) {
			$candidates = is_array( $candidate ) ? $candidate : array( $candidate );
			foreach ( $candidates as $item ) {
				$url = is_array( $item ) ? (string) ( $item['url'] ?? $item['source_url'] ?? '' ) : (string) $item;
				if ( $url !== '' && filter_var( $url, FILTER_VALIDATE_URL ) && ! in_array( $url, $images, true ) ) {
					$images[] = $url;
				}
			}
		}
		if ( ! empty( $images ) ) {
			$opts['images'] = $images;
			$opts['media_urls'] = $images;
		}
		return $opts;
	}
}
