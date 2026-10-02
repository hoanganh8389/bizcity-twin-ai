<?php
/**
 * Remote Zalo Hub — Guru → remote agent sync owner (XS6, doc 51 §4.3-4.5).
 *
 * One state option per site, keyed by bridge id (`rzh:<ref>`). Declares nothing about which Guru answers —
 * that stays Bot Studio's binding — this only pushes the CURRENT projection (class-remote-zalo-agent-projector)
 * onto the remote agent record through GET→PATCH with If-Match, and records what happened for the UI chip.
 * Never stores persona/FAQ text, only counts, field NAMES and machine codes.
 *
 * @package BizCity_Zalo_Personal
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Remote_Zalo_Agent_Sync', false ) ) {
	return;
}

// [2026-09-30 Claude Sonnet 5] PHASE-0.82 XS6 (51 §4.3-4.5) — declare -> apply -> verify, fail closed, bounded repush.
final class BizCity_Remote_Zalo_Agent_Sync {

	const OPTION = 'bizcity_zalo_remote_agent_sync';
	const MAX_REPUSH_PER_HOUR = 3;
	const OWN_VERSIONS_KEEP = 5;

	/** @var callable|null */
	public static $client = null;
	/** @var callable|null () => list<array{bridge_id:string,character_id:int}> */
	public static $accounts = null;
	/** @var array test seam forwarded to the projector (profile/tuning readers) */
	public static $projector_deps = array();
	/** @var callable|null () => int */
	public static $clock = null;

	public static function reset_seams(): void { self::$client = self::$accounts = self::$clock = null; self::$projector_deps = array(); }

	/**
	 * [2026-09-30 Claude Sonnet 5] PHASE-0.82 XS6 follow-up — declare -> apply, automatically, at the three
	 * moments the projection can actually change, instead of only on a manual "Đồng bộ ngay":
	 *   1. a nick just got linked (bizcity_remote_zalo_account_linked, fired by the ONE link service both
	 *      Bot Studio and CRM go through) — pushes the default Guru's projection immediately;
	 *   2. this account's binding was created/changed (bizcity_channel_binding_upserted, the ONE binding
	 *      owner for every platform) — pushes the newly chosen Guru;
	 *   3. the bound Guru itself was edited (bizcity_knowledge_character_saved, already the exact hook
	 *      BizCity_Zalo_Hub_Config_Sync listens to for zalo_hub numbers) — pushes every remote nick using it.
	 * Each listener is a thin dispatch to `sync_one()`/`sync_by_character()`; none of it runs unless the
	 * remote feature is loaded (this class only exists behind that flag), so there is no cost when it is off.
	 */
	public static function boot(): void {
		if ( ! function_exists( 'add_action' ) ) { return; }
		add_action( 'bizcity_remote_zalo_account_linked', array( __CLASS__, 'on_account_linked' ), 10, 1 );
		add_action( 'bizcity_channel_binding_upserted', array( __CLASS__, 'on_binding_upserted' ), 10, 3 );
		add_action( 'bizcity_knowledge_character_saved', array( __CLASS__, 'on_character_saved' ), 10, 1 );
	}

	public static function on_account_linked( $bridge_id ): void {
		self::sync_one( (string) $bridge_id, 0 ); // guru:0 (default) — no binding has been chosen yet at link time.
	}

	public static function on_binding_upserted( $platform, $account_id, $character_id ): void {
		if ( 'ZALO_PERSONAL' !== strtoupper( (string) $platform ) ) { return; }
		$bridge_id = (string) $account_id;
		if ( 0 !== strpos( $bridge_id, 'rzh:' ) ) { return; } // not a Remote Zalo Hub account — nothing for this class to do.
		self::sync_one( $bridge_id, (int) $character_id );
	}

	public static function on_character_saved( $character_id ): void {
		self::sync_by_character( (int) $character_id );
	}

	/** Run one sync pass over every linked, non-disconnected `remote_zalo_hub` account. */
	public static function sync_all(): array {
		$results = array();
		foreach ( self::account_list() as $row ) {
			$bridge_id = (string) ( $row['bridge_id'] ?? '' );
			if ( '' === $bridge_id ) { continue; }
			$results[ $bridge_id ] = self::sync_one( $bridge_id, (int) ( $row['character_id'] ?? 0 ) );
		}
		return $results;
	}

	/** Every remote nick currently bound to this one Guru — the character-saved re-entry point. */
	public static function sync_by_character( int $character_id ): array {
		$results = array();
		foreach ( self::account_list() as $row ) {
			if ( (int) ( $row['character_id'] ?? 0 ) !== $character_id ) { continue; }
			$bridge_id = (string) ( $row['bridge_id'] ?? '' );
			if ( '' === $bridge_id ) { continue; }
			$results[ $bridge_id ] = self::sync_one( $bridge_id, $character_id );
		}
		return $results;
	}

	/** One nick: GET → project → diff → PATCH, with the two documented one-shot retries (412, field_not_editable). */
	public static function sync_one( string $bridge_id, int $character_id ): array {
		$client = self::client();
		if ( ! $client ) { return self::record( $bridge_id, array( 'state' => 'error', 'code' => 'remote_client_unavailable' ) ); }

		$get = $client->get_agent( $bridge_id );
		if ( empty( $get['ok'] ) ) {
			$code = (string) ( $get['error']['code'] ?? 'remote_error' );
			return self::record( $bridge_id, array( 'state' => 'remote_scope_missing' === $code ? 'scope_missing' : 'error', 'code' => $code ) );
		}
		$data = is_array( $get['data'] ?? null ) ? $get['data'] : array();
		$proj = self::project( $character_id, self::editable( $data ) );
		$diff = self::diff( $proj['fields'], $data );
		if ( ! $diff ) {
			return self::record( $bridge_id, array( 'state' => 'in_sync', 'code' => '', 'changed' => array(), 'not_applied' => $proj['not_applied'], 'warnings' => $proj['warnings'], 'remote_version' => (string) ( $data['version'] ?? '' ), 'fingerprint' => $proj['fingerprint'] ) );
		}

		$patch = $client->patch_agent( $bridge_id, $diff, (string) ( $data['version'] ?? '' ) );
		if ( ! empty( $patch['ok'] ) ) {
			return self::record_success( $bridge_id, $diff, $patch, $proj );
		}

		$code = (string) ( $patch['error']['code'] ?? '' );
		if ( 'remote_agent_shared' === $code ) {
			return self::record( $bridge_id, array( 'state' => 'shared', 'code' => $code ) );
		}
		if ( in_array( $code, array( 'remote_agent_field_locked', 'remote_agent_version_conflict' ), true ) ) {
			// One re-read, one retry — never loop: a provider that keeps refusing needs a human, not a busy loop.
			$get2 = $client->get_agent( $bridge_id );
			if ( empty( $get2['ok'] ) ) {
				return self::record( $bridge_id, array( 'state' => 'error', 'code' => (string) ( $get2['error']['code'] ?? 'remote_error' ) ) );
			}
			$data2 = is_array( $get2['data'] ?? null ) ? $get2['data'] : array();
			$proj2 = self::project( $character_id, self::editable( $data2 ) );
			$diff2 = self::diff( $proj2['fields'], $data2 );
			if ( ! $diff2 ) {
				return self::record( $bridge_id, array( 'state' => 'in_sync', 'code' => '', 'changed' => array(), 'not_applied' => $proj2['not_applied'], 'warnings' => $proj2['warnings'], 'remote_version' => (string) ( $data2['version'] ?? '' ), 'fingerprint' => $proj2['fingerprint'] ) );
			}
			$patch2 = $client->patch_agent( $bridge_id, $diff2, (string) ( $data2['version'] ?? '' ) );
			if ( ! empty( $patch2['ok'] ) ) {
				return self::record_success( $bridge_id, $diff2, $patch2, $proj2 );
			}
			$state = 'remote_agent_version_conflict' === $code ? 'conflict' : 'error';
			return self::record( $bridge_id, array( 'state' => $state, 'code' => (string) ( $patch2['error']['code'] ?? $code ) ) );
		}

		$retryable = ! empty( $patch['error']['retryable'] );
		return self::record( $bridge_id, array( 'state' => $retryable ? 'pending' : 'error', 'code' => $code ) );
	}

	/**
	 * G5 — the provider's dashboard changed the agent outside our PATCH. Guru wins: mark drift, then re-sync
	 * immediately UNLESS this nick already repushed `MAX_REPUSH_PER_HOUR` times in the last hour, in which case
	 * we stop overwriting and surface it instead of fighting the dashboard forever (04 §6.2/§0.6, 51 §4.5).
	 */
	public static function on_dashboard_drift( string $bridge_id, int $character_id ): array {
		$row = self::status_for( $bridge_id ) ?? array();
		$hour = defined( 'HOUR_IN_SECONDS' ) ? HOUR_IN_SECONDS : 3600;
		$log = array_values( array_filter( (array) ( $row['repush_log'] ?? array() ), static function ( $ts ) use ( $hour ) { return $ts > self::now() - $hour; } ) );
		if ( count( $log ) >= self::MAX_REPUSH_PER_HOUR ) {
			return self::record( $bridge_id, array( 'state' => 'drift_dashboard', 'code' => 'remote_repush_throttled', 'repush_log' => $log ) );
		}
		$result = self::sync_one( $bridge_id, $character_id );
		$log[] = self::now();
		return self::record( $bridge_id, array_merge( $result, array( 'repush_log' => $log ) ) );
	}

	public static function status_for( string $bridge_id ): ?array {
		$all = self::all();
		return isset( $all[ $bridge_id ] ) && is_array( $all[ $bridge_id ] ) ? $all[ $bridge_id ] : null;
	}

	/** Does the given remote PATCH `version` match one WE wrote (own echo, for the normalizer's G5 guard)? */
	public static function is_own_version( string $bridge_id, string $version ): bool {
		$row = self::status_for( $bridge_id );
		return is_array( $row ) && in_array( $version, (array) ( $row['own_versions'] ?? array() ), true );
	}

	/* ---------------- internals ---------------- */

	private static function record_success( string $bridge_id, array $diff, array $patch, array $proj ): array {
		$version = (string) ( $patch['data']['version'] ?? $patch['etag'] ?? '' );
		$prior = self::status_for( $bridge_id );
		$own = is_array( $prior ) ? (array) ( $prior['own_versions'] ?? array() ) : array();
		if ( '' !== $version ) { $own[] = $version; }
		$own = array_slice( array_values( array_unique( $own ) ), -self::OWN_VERSIONS_KEEP );
		return self::record( $bridge_id, array(
			'state' => 'in_sync', 'code' => '', 'changed' => array_keys( $diff ), 'not_applied' => $proj['not_applied'],
			'warnings' => $proj['warnings'], 'remote_version' => $version, 'fingerprint' => $proj['fingerprint'], 'own_versions' => $own,
		) );
	}

	private static function record( string $bridge_id, array $fields ): array {
		$row = array(
			'state'         => in_array( $fields['state'] ?? '', array( 'in_sync', 'pending', 'conflict', 'shared', 'scope_missing', 'error', 'drift_dashboard' ), true ) ? $fields['state'] : 'error',
			'code'          => sanitize_key( (string) ( $fields['code'] ?? '' ) ),
			'changed'       => array_values( array_map( 'strval', (array) ( $fields['changed'] ?? array() ) ) ),
			'not_applied'   => is_array( $fields['not_applied'] ?? null ) ? $fields['not_applied'] : array(),
			'warnings'      => array_values( array_map( 'strval', (array) ( $fields['warnings'] ?? array() ) ) ),
			'remote_version'=> (string) ( $fields['remote_version'] ?? '' ),
			'fingerprint'   => (string) ( $fields['fingerprint'] ?? '' ),
			'own_versions'  => array_values( array_map( 'strval', (array) ( $fields['own_versions'] ?? ( self::status_for( $bridge_id )['own_versions'] ?? array() ) ) ) ),
			'repush_log'    => array_values( array_map( 'intval', (array) ( $fields['repush_log'] ?? ( self::status_for( $bridge_id )['repush_log'] ?? array() ) ) ) ),
			'synced_at'     => self::now(),
		);
		$all = self::all();
		$all[ $bridge_id ] = $row;
		update_option( self::OPTION, $all, false );
		return $row;
	}

	private static function all(): array {
		$raw = get_option( self::OPTION, array() );
		return is_array( $raw ) ? $raw : array();
	}

	private static function editable( array $data ): array {
		return array_map( 'strval', (array) ( $data['editableFields'] ?? array() ) );
	}

	private static function project( int $character_id, array $editable ): array {
		if ( class_exists( 'BizCity_Remote_Zalo_Agent_Projector' ) ) {
			return BizCity_Remote_Zalo_Agent_Projector::project( $character_id, $editable, self::$projector_deps );
		}
		return array( 'fields' => array(), 'not_applied' => array(), 'warnings' => array(), 'fingerprint' => '' );
	}

	private static function diff( array $fields, array $current ): array {
		$out = array();
		foreach ( $fields as $key => $value ) {
			if ( ! array_key_exists( $key, $current ) || $current[ $key ] !== $value ) { $out[ $key ] = $value; }
		}
		return $out;
	}

	private static function client() {
		if ( is_object( self::$client ) ) { return self::$client; }
		return class_exists( 'BizCity_Remote_Zalo_Hub_Client' ) ? new BizCity_Remote_Zalo_Hub_Client() : null;
	}

	private static function account_list(): array {
		if ( is_callable( self::$accounts ) ) { return (array) call_user_func( self::$accounts ); }
		if ( ! class_exists( 'BizCity_Zalo_Account_Flags' ) || ! class_exists( 'BizCity_Zalo_Mapping_Repo' ) || ! class_exists( 'BizCity_Channel_Binding' ) ) { return array(); }
		$out = array();
		foreach ( array_keys( BizCity_Zalo_Account_Flags::all() ) as $bridge_id ) {
			$bridge_id = (string) $bridge_id;
			if ( BizCity_Zalo_Account_Flags::PROVIDER_REMOTE_ZALO_HUB !== BizCity_Zalo_Account_Flags::provider( $bridge_id ) ) { continue; }
			$account = method_exists( 'BizCity_Zalo_Mapping_Repo', 'find_account_by_bridge_id' ) ? BizCity_Zalo_Mapping_Repo::find_account_by_bridge_id( 'personal', $bridge_id ) : null;
			if ( is_array( $account ) && 'disconnected' === (string) ( $account['status'] ?? '' ) ) { continue; }
			$binding = method_exists( 'BizCity_Channel_Binding', 'resolve' ) ? BizCity_Channel_Binding::resolve( 'ZALO_PERSONAL', $bridge_id ) : null;
			$out[] = array( 'bridge_id' => $bridge_id, 'character_id' => is_array( $binding ) ? (int) ( $binding['character_id'] ?? 0 ) : 0 );
		}
		return $out;
	}

	private static function now(): int { return is_callable( self::$clock ) ? (int) call_user_func( self::$clock ) : time(); }
}
