<?php
/**
 * Who may use the Owner Agent on one zalo-hub number: the owner + the staff list (PHASE-0.87 wave 2, doc 50).
 *
 * One source = Bot Studio: the owner is the number's `owner_user_id` + policy `owner_uid`; staff are stored in the same
 * binding policy as `staff_principals[]` (D-W2-1, no table). Everything else about a person is derived when needed:
 * modes (agent-mode-access@1), the pseudonymous `user_hash`, CRM suspension (user meta `bizcity_crm_staff_status`).
 * Every server-side consumer (bundle, packs, capture, turn-complete, CRM tagging, REST) resolves people through here —
 * never from a browser- or cell-supplied user id.
 *
 * // @axis twin-agent-axis@1 block owner_agent
 *
 * @package BizCity_Zalo_Personal
 * @since   PHASE-0.87 wave 2 (2026-10-01)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Zalo_Agent_Principals', false ) ) {
	return;
}

final class BizCity_Zalo_Agent_Principals {

	const MAX          = 20;
	const POLICY_KEY   = 'staff_principals';
	const PLATFORM     = 'ZALO_PERSONAL';
	const SUSPEND_META = 'bizcity_crm_staff_status';

	/**
	 * Test seams: policy(bridge_id): array · owner_user_id(bridge_id): int · user_hash(user_id): string ·
	 * modes(user_id): string[] · suspended(user_id): bool · user(user_id): ?{display_name,email} · accounts(): list<{bridge_id,label}>
	 *
	 * @var array<string,callable>
	 */
	public static $readers = array();

	public static function boot(): void {
		// CRM suspend / reactivate is a user-meta flip: re-project the bundle so the person stops (or resumes) within 60 s (D-W2-6).
		foreach ( array( 'added_user_meta', 'updated_user_meta', 'deleted_user_meta' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'on_user_meta' ), 10, 3 );
		}
	}

	public static function on_user_meta( $meta_id, $user_id, $meta_key ): void {
		if ( self::SUSPEND_META === $meta_key ) {
			do_action( 'bizcity_agent_modes_changed' );
		}
	}

	/* ── stored list ──────────────────────────────────────────────── */

	/** Clean a stored/posted list: valid rows only, unique user and UID, ≤ MAX. @return list<array> */
	public static function normalize( $list ): array {
		$out   = array();
		$users = array();
		$uids  = array();
		foreach ( is_array( $list ) ? $list : array() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$user = (int) ( $row['user_id'] ?? 0 );
			$uid  = self::clean_uid( (string) ( $row['zalo_uid'] ?? '' ) );
			if ( $user <= 0 || '' === $uid || isset( $users[ $user ] ) || isset( $uids[ $uid ] ) ) {
				continue;
			}
			$users[ $user ] = true;
			$uids[ $uid ]   = true;
			$out[] = array(
				'user_id'          => $user,
				'zalo_uid'         => $uid,
				'zalo_name'        => self::clip( (string) ( $row['zalo_name'] ?? '' ), 100 ),
				'enabled'          => ! isset( $row['enabled'] ) || (bool) $row['enabled'],
				'capture_files'    => ! isset( $row['capture_files'] ) || (bool) $row['capture_files'],
				'capture_remember' => ! isset( $row['capture_remember'] ) || (bool) $row['capture_remember'],
				'added_by'         => (int) ( $row['added_by'] ?? 0 ),
				'added_at'         => self::clip( (string) ( $row['added_at'] ?? '' ), 40 ),
				'verified_at'      => isset( $row['verified_at'] ) && '' !== (string) $row['verified_at'] ? self::clip( (string) $row['verified_at'], 40 ) : null,
			);
			if ( count( $out ) >= self::MAX ) {
				break;
			}
		}
		return $out;
	}

	/** The staff list as stored in a policy array. */
	public static function from_policy( array $policy ): array {
		return self::normalize( $policy[ self::POLICY_KEY ] ?? array() );
	}

	/**
	 * '' when the row may be saved, else the R-ERROR-UX code of doc 50 §4.3. `$editing` = the user being edited (0 = new).
	 *
	 * @param list<array> $existing normalized list
	 */
	public static function validate( array $existing, string $owner_uid, int $user_id, string $uid, int $editing = 0 ): string {
		$uid = self::clean_uid( $uid );
		if ( '' === $uid ) {
			return 'staff_uid_required';
		}
		if ( '' !== trim( $owner_uid ) && hash_equals( self::clean_uid( $owner_uid ), $uid ) ) {
			return 'staff_uid_is_owner';
		}
		foreach ( $existing as $row ) {
			if ( (int) $row['user_id'] === $editing ) {
				continue;
			}
			if ( hash_equals( (string) $row['zalo_uid'], $uid ) ) {
				return 'staff_uid_duplicate';
			}
			if ( 0 === $editing && (int) $row['user_id'] === $user_id ) {
				return 'staff_user_duplicate';
			}
		}
		if ( 0 === $editing && count( $existing ) >= self::MAX ) {
			return 'staff_limit';
		}
		if ( $user_id <= 0 || null === self::user( $user_id ) ) {
			return 'staff_not_member';
		}
		return '';
	}

	/* ── resolution ───────────────────────────────────────────────── */

	/**
	 * Owner + staff of a number, each `{role, user_id, uid, user_hash, modes, capture{files,remember}, active}`.
	 * Staff are `active` when enabled here, not suspended in CRM and still a user of the site.
	 *
	 * @return list<array>
	 */
	public static function principals( string $bridge_id ): array {
		$policy = self::policy( $bridge_id );
		$out    = array();
		$owner  = self::owner_user_id( $bridge_id );
		$ouid   = self::clean_uid( (string) ( $policy['owner_uid'] ?? '' ) );
		if ( $owner > 0 && '' !== $ouid ) {
			$on    = static function ( string $k ) use ( $policy ): bool { return ! isset( $policy[ $k ] ) || (bool) $policy[ $k ]; };
			$modes = self::modes( $owner );
			$nb    = in_array( 'notebook', $modes, true );
			$out[] = array( 'role' => 'owner', 'user_id' => $owner, 'uid' => $ouid, 'user_hash' => self::user_hash( $owner ), 'modes' => $modes, 'capture' => array( 'files' => $nb && $on( 'owner_capture_files' ), 'remember' => $nb && $on( 'owner_capture_remember' ) ), 'active' => $on( 'owner_agent_enabled' ) );
		}
		foreach ( self::from_policy( $policy ) as $row ) {
			$out[] = self::staff_principal( $row );
		}
		return $out;
	}

	/** One stored staff row ⇒ principal (derived fields filled in). */
	public static function staff_principal( array $row ): array {
		$user   = (int) $row['user_id'];
		$exists = null !== self::user( $user );
		$modes  = $exists ? self::modes( $user ) : array();
		$nb     = in_array( 'notebook', $modes, true );
		return array(
			'role'      => 'staff',
			'user_id'   => $user,
			'uid'       => (string) $row['zalo_uid'],
			'user_hash' => self::user_hash( $user ),
			'modes'     => $modes,
			'capture'   => array( 'files' => $nb && ! empty( $row['capture_files'] ), 'remember' => $nb && ! empty( $row['capture_remember'] ) ),
			'active'    => ! empty( $row['enabled'] ) && $exists && ! self::suspended( $user ),
			'suspended' => self::suspended( $user ),
			'row'       => $row,
		);
	}

	/** The active principal whose pseudonymous hash this is, or null (never trust a hash that is not on this number). */
	public static function by_hash( string $bridge_id, string $hash ): ?array {
		$hash = strtolower( trim( $hash ) );
		if ( '' === $hash ) {
			return null;
		}
		foreach ( self::principals( $bridge_id ) as $p ) {
			if ( $p['active'] && '' !== $p['user_hash'] && hash_equals( $p['user_hash'], $hash ) ) {
				return $p;
			}
		}
		return null;
	}

	/** The active principal with this Zalo UID (owner first), or null. */
	public static function by_uid( string $bridge_id, string $uid ): ?array {
		$uid = self::clean_uid( $uid );
		if ( '' === $uid ) {
			return null;
		}
		foreach ( self::principals( $bridge_id ) as $p ) {
			if ( $p['active'] && hash_equals( $p['uid'], $uid ) ) {
				return $p;
			}
		}
		return null;
	}

	/**
	 * `owner_agent.staff[]` of the bundle (owner-agent-block@1.3, doc 50 §5.1): active people only; raw UID goes to the cell
	 * and nowhere else. A person with no mode is still sent (`modes: []` ⇒ the cell treats them as a customer).
	 *
	 * @return list<array>
	 */
	public static function bundle_staff( array $policy ): array {
		$out = array();
		foreach ( self::from_policy( $policy ) as $row ) {
			$p = self::staff_principal( $row );
			if ( ! $p['active'] ) {
				continue;
			}
			$out[] = array( 'uid' => $p['uid'], 'user_hash' => $p['user_hash'], 'modes' => array_values( $p['modes'] ), 'capture' => $p['capture'], 'uid_verified_at' => $row['verified_at'] );
		}
		return $out;
	}

	/** Every zalo-hub number where this user is on the staff list (CRM column "Agent Zalo", doc 50 §6.4). */
	public static function numbers_for_user( int $user_id ): array {
		$out = array();
		foreach ( self::accounts() as $acc ) {
			foreach ( self::from_policy( self::policy( (string) $acc['bridge_id'] ) ) as $row ) {
				if ( (int) $row['user_id'] !== $user_id ) {
					continue;
				}
				$p     = self::staff_principal( $row );
				$out[] = array(
					'account_id' => (string) $acc['bridge_id'],
					'label'      => self::mask_label( (string) $acc['label'], (string) $acc['bridge_id'] ),
					'status'     => $p['suspended'] ? 'suspended' : ( empty( $row['enabled'] ) ? 'paused' : ( $p['modes'] ? 'active' : 'no_modes' ) ),
				);
			}
		}
		return $out;
	}

	/* ── helpers ──────────────────────────────────────────────────── */

	/** Zalo UIDs are digit strings; anything else is dropped (never stored, never compared). */
	public static function clean_uid( string $uid ): string {
		$uid = trim( $uid );
		return preg_match( '/^\d{5,32}$/', $uid ) ? $uid : '';
	}

	/** "…" + last 4 — the only form a UID takes on any screen. */
	public static function mask_uid( string $uid ): string {
		return '' === $uid ? '' : '…' . substr( $uid, -4 );
	}

	private static function mask_label( string $label, string $bridge ): string {
		$label = '' !== trim( $label ) ? trim( $label ) : $bridge;
		if ( class_exists( 'BizCity_Zalo_Personal_Knowledge_Capture' ) ) {
			return BizCity_Zalo_Personal_Knowledge_Capture::mask_label( $label );
		}
		return $label;
	}

	public static function policy( string $bridge_id ): array {
		if ( isset( self::$readers['policy'] ) ) {
			return (array) call_user_func( self::$readers['policy'], $bridge_id );
		}
		$b   = class_exists( 'BizCity_Channel_Binding' ) ? BizCity_Channel_Binding::resolve( self::PLATFORM, $bridge_id ) : null;
		$raw = is_array( $b ) ? ( $b['policy_json'] ?? '' ) : '';
		$d   = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
		return is_array( $d ) ? $d : array();
	}

	public static function owner_user_id( string $bridge_id ): int {
		if ( isset( self::$readers['owner_user_id'] ) ) {
			return (int) call_user_func( self::$readers['owner_user_id'], $bridge_id );
		}
		$acc = class_exists( 'BizCity_Zalo_Mapping_Repo' ) ? BizCity_Zalo_Mapping_Repo::find_account_by_bridge_id( 'personal', $bridge_id ) : null;
		return is_array( $acc ) ? (int) ( $acc['owner_user_id'] ?? 0 ) : 0;
	}

	public static function user_hash( int $user_id ): string {
		if ( isset( self::$readers['user_hash'] ) ) {
			return (string) call_user_func( self::$readers['user_hash'], $user_id );
		}
		return class_exists( 'BizCity_Agent_Mode_Access' ) ? BizCity_Agent_Mode_Access::user_hash( $user_id ) : '';
	}

	/** @return string[] */
	public static function modes( int $user_id ): array {
		if ( isset( self::$readers['modes'] ) ) {
			return array_values( array_map( 'strval', (array) call_user_func( self::$readers['modes'], $user_id ) ) );
		}
		return class_exists( 'BizCity_Agent_Mode_Access' ) ? BizCity_Agent_Mode_Access::modes_for_user( $user_id ) : array();
	}

	public static function suspended( int $user_id ): bool {
		if ( isset( self::$readers['suspended'] ) ) {
			return (bool) call_user_func( self::$readers['suspended'], $user_id );
		}
		return function_exists( 'get_user_meta' ) && 'suspended' === (string) get_user_meta( $user_id, self::SUSPEND_META, true );
	}

	/** @return array{display_name:string,email:string}|null */
	public static function user( int $user_id ): ?array {
		if ( isset( self::$readers['user'] ) ) {
			$u = call_user_func( self::$readers['user'], $user_id );
			return is_array( $u ) ? $u : null;
		}
		if ( $user_id <= 0 || ! function_exists( 'get_userdata' ) ) {
			return null;
		}
		$u = get_userdata( $user_id );
		if ( ! $u ) {
			return null;
		}
		// A user of another blog on a multisite is not "a member of the site" (doc 50 §4.3 staff_not_member).
		if ( function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'is_user_member_of_blog' ) && ! is_user_member_of_blog( $user_id ) ) {
			return null;
		}
		return array( 'display_name' => (string) $u->display_name, 'email' => (string) $u->user_email );
	}

	/** @return list<array{bridge_id:string,label:string}> */
	private static function accounts(): array {
		if ( isset( self::$readers['accounts'] ) ) {
			return (array) call_user_func( self::$readers['accounts'] );
		}
		return class_exists( 'BizCity_Zalo_Hub_Config_Sync' ) ? BizCity_Zalo_Hub_Config_Sync::accounts() : array();
	}

	private static function clip( string $s, int $max ): string {
		$s = trim( function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $s ) : strip_tags( $s ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $max ) : substr( $s, 0, $max );
	}
}
