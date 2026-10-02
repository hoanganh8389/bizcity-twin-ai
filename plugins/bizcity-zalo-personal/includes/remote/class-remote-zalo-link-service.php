<?php
/**
 * Remote Zalo Hub — the ONE place that links a provisioned remote nick into this site (XS1, 52 §5).
 *
 * Both REST owners call this: Bot Studio `POST zalo-remote/accounts/{ref}/link` and the CRM
 * `POST crm-phones` remote branch (XS3). It reuses the existing owners only — CRM inbox
 * (`BizCity_CRM_Repository::upsert_inbox`), mapping (`BizCity_Zalo_Mapping_Repo::save_account`),
 * channel grant (`BizCity_Channel_User_Grant`) and the provider flag — so no second store exists.
 * Identity is server-derived: the nick must be in the key's current `list_accounts()` (never trusted
 * from the browser), and the local bridge id is always `rzh:<accountId>` (D82-14).
 *
 * @package BizCity_Zalo_Personal
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Remote_Zalo_Link_Service', false ) ) {
	return;
}

// [2026-09-29 Claude Opus 5.5] PHASE-0.82 XS1 — extracted from BizCity_Remote_Zalo_Accounts_REST::link_account().
final class BizCity_Remote_Zalo_Link_Service {

	const PREFIX = 'rzh:';

	/**
	 * @param array $deps Test seams: client (object), lookup/save/inbox/grant/flag/owner (callables). Omitted ⇒ real owner.
	 * @return array{ok:bool,code?:string,account_ref?:string,bridge_id?:string,crm_inbox_id?:int,owner_user_id?:int,session?:string,label?:string}
	 */
	public static function link( string $ref, int $owner_user_id, int $actor_id = 0, array $deps = array() ): array {
		$ref = trim( $ref );
		if ( '' === $ref || $owner_user_id <= 0 || ! self::valid_owner( $owner_user_id, $deps ) ) {
			return array( 'ok' => false, 'code' => 'invalid_param' );
		}
		$remote = self::remote_account( $ref, $deps );
		if ( null === $remote ) {
			return array( 'ok' => false, 'code' => 'remote_not_found' );
		}
		$bridge_id = self::PREFIX . $ref;
		$label     = (string) ( $remote['label'] ?? $ref );
		$session   = 'running' === (string) ( $remote['status'] ?? '' ) ? 'connected' : 'stopped';
		$inbox_id  = self::upsert_inbox( $bridge_id, $label, $deps );
		if ( $inbox_id <= 0 ) {
			return array( 'ok' => false, 'code' => 'crm_inbox_create_failed' );
		}
		$local_id = self::save_account( array(
			'kind'              => 'personal',
			'owner_user_id'     => $owner_user_id,
			'label'             => $label,
			'bridge_account_id' => $bridge_id,
			'zalo_uid'          => '',
			'crm_inbox_id'      => $inbox_id,
			'status'            => 'connected' === $session ? 'connected' : 'disconnected',
		), $deps );
		if ( $local_id <= 0 ) {
			return array( 'ok' => false, 'code' => 'mapping_insert_failed' );
		}
		$grant = self::grant( $bridge_id, $owner_user_id, $actor_id, 'remote_link', $deps );
		if ( empty( $grant['ok'] ) ) {
			return array( 'ok' => false, 'code' => 'permission_denied' );
		}
		self::flag( $bridge_id, $deps );
		// [2026-09-30 Claude Sonnet 5] PHASE-0.82 XS6 follow-up — fire-and-forget: a freshly linked nick starts
		// answering with the default Guru's projection (guru:0) the moment it exists, not only after someone
		// later opens "Đồng bộ ngay". Decoupled via an action so Link_Service never has to know Agent_Sync exists.
		if ( function_exists( 'do_action' ) ) {
			do_action( 'bizcity_remote_zalo_account_linked', $bridge_id, $ref, $owner_user_id );
		}
		return array( 'ok' => true, 'account_ref' => $ref, 'bridge_id' => $bridge_id, 'crm_inbox_id' => $inbox_id, 'owner_user_id' => $owner_user_id, 'session' => $session, 'label' => $label );
	}

	/** Change the owner of an already-linked nick (mapping row + primary grant). */
	public static function set_owner( string $ref, int $owner_user_id, int $actor_id = 0, array $deps = array() ): array {
		$bridge_id = self::PREFIX . trim( $ref );
		$local = self::lookup( $bridge_id, $deps );
		if ( ! is_array( $local ) ) {
			return array( 'ok' => false, 'code' => 'remote_not_found' );
		}
		if ( $owner_user_id <= 0 || ! self::valid_owner( $owner_user_id, $deps ) ) {
			return array( 'ok' => false, 'code' => 'invalid_param' );
		}
		$grant = self::grant( $bridge_id, $owner_user_id, $actor_id, 'remote_owner_change', $deps );
		if ( empty( $grant['ok'] ) ) {
			return array( 'ok' => false, 'code' => 'permission_denied' );
		}
		self::save_account( array(
			'kind'              => 'personal',
			'owner_user_id'     => $owner_user_id,
			'label'             => (string) ( $local['label'] ?? '' ),
			'bridge_account_id' => $bridge_id,
			'crm_inbox_id'      => (int) ( $local['crm_inbox_id'] ?? 0 ),
			'status'            => (string) ( $local['status'] ?? 'connected' ),
		), $deps );
		return array( 'ok' => true, 'account_ref' => trim( $ref ), 'owner_user_id' => $owner_user_id );
	}

	/** The key's provisioned nick with this id, or null. Server-side only — the browser's id is never trusted. */
	public static function remote_account( string $ref, array $deps = array() ): ?array {
		$client = self::client( $deps );
		$result = $client ? $client->list_accounts() : array();
		foreach ( (array) ( $result['data']['items'] ?? array() ) as $item ) {
			if ( is_array( $item ) && (string) ( $item['id'] ?? '' ) === $ref ) {
				return $item;
			}
		}
		return null;
	}

	public static function client( array $deps = array() ) {
		if ( isset( $deps['client'] ) && is_object( $deps['client'] ) ) { return $deps['client']; }
		return class_exists( 'BizCity_Remote_Zalo_Hub_Client' ) ? new BizCity_Remote_Zalo_Hub_Client() : null;
	}

	public static function lookup( string $bridge_id, array $deps = array() ) {
		if ( isset( $deps['lookup'] ) && is_callable( $deps['lookup'] ) ) { return call_user_func( $deps['lookup'], $bridge_id ); }
		return class_exists( 'BizCity_Zalo_Mapping_Repo' ) ? BizCity_Zalo_Mapping_Repo::find_account_by_bridge_id( 'personal', $bridge_id ) : null;
	}

	private static function save_account( array $data, array $deps ): int {
		if ( isset( $deps['save'] ) && is_callable( $deps['save'] ) ) { return (int) call_user_func( $deps['save'], $data ); }
		return class_exists( 'BizCity_Zalo_Mapping_Repo' ) ? (int) BizCity_Zalo_Mapping_Repo::save_account( $data ) : 0;
	}

	private static function upsert_inbox( string $bridge_id, string $label, array $deps ): int {
		if ( isset( $deps['inbox'] ) && is_callable( $deps['inbox'] ) ) { return (int) call_user_func( $deps['inbox'], $bridge_id, $label ); }
		return class_exists( 'BizCity_CRM_Repository' ) ? (int) BizCity_CRM_Repository::upsert_inbox( 'zalo_personal', $bridge_id, array( 'name' => 'Zalo Cá nhân — ' . $label ) ) : 0;
	}

	private static function grant( string $bridge_id, int $owner, int $actor, string $source, array $deps ): array {
		if ( isset( $deps['grant'] ) && is_callable( $deps['grant'] ) ) { return (array) call_user_func( $deps['grant'], $bridge_id, $owner ); }
		if ( ! class_exists( 'BizCity_Channel_User_Grant' ) ) { return array( 'ok' => false ); }
		$actor = $actor > 0 ? $actor : ( function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0 );
		return (array) BizCity_Channel_User_Grant::bind_primary_for_owner( 'zalo_personal', $bridge_id, $owner, $actor, true, array( 'source' => $source ) );
	}

	private static function flag( string $bridge_id, array $deps ): void {
		if ( isset( $deps['flag'] ) && is_callable( $deps['flag'] ) ) { call_user_func( $deps['flag'], $bridge_id ); return; }
		if ( class_exists( 'BizCity_Zalo_Account_Flags' ) ) {
			BizCity_Zalo_Account_Flags::record( $bridge_id, array( 'provider' => 'remote_zalo_hub' ), 'remote_link' );
		}
	}

	private static function valid_owner( int $id, array $deps ): bool {
		if ( isset( $deps['owner'] ) && is_callable( $deps['owner'] ) ) { return (bool) call_user_func( $deps['owner'], $id ); }
		return function_exists( 'get_userdata' ) && (bool) get_userdata( $id );
	}
}
