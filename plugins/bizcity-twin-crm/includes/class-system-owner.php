<?php
/**
 * BizCity_CRM_System_Owner — the one WordPress user that "owns" media a Bot Studio auto-reply sends.
 *
 * Why it exists (PHASE-0.60H D-H2 / D-H2b): the outbound dispatcher checks that every attachment's
 * `post_author` equals the authorised sender. A fully automated bot conversation has no human assignee, so
 * until now it could never send an image/audio/video (`inbox_capability` anchors stay text-only). This user
 * gives those sends a stable, auditable owner WITHOUT weakening the ownership rule: an `ai_autoreply` send can
 * only ever carry files authored by THIS user, i.e. files the bot itself generated — never a staff member's
 * or another tenant's media.
 *
 * Deliberately powerless:
 *  - no role, no capability — `inspect()` refuses (fail closed) if the account is ever made privileged;
 *  - cannot log in (random password that is never stored, plus `wp_authenticate_user` hard block);
 *  - excluded from the CRM "assignable users" list so a conversation cannot be assigned to it;
 *  - NEVER written to `conversations.assignee_id` / `responder_user_id` (that would skew staff reports).
 *
 * Created lazily, on the first time the bot actually needs to send media — not in an activation hook
 * (CRM loads as a sub-plugin, so that hook practically never runs) and not in `maybe_upgrade()` (it returns
 * early once the DB version matches). `ensure()` is idempotent and race-safe.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Twin_CRM
 * @since      PHASE-0.60H (2026-09-24)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_CRM_System_Owner {

	const OPTION = 'bizcity_crm_system_owner_id';
	const LOGIN  = 'bizcity-system-bot';
	/** `.invalid` is a reserved TLD (RFC 2606): the address can never receive or send real mail. */
	const EMAIL  = 'bizcity-system-bot@invalid.invalid';

	/** Capabilities a system owner must NOT hold. Any of them ⇒ `privileged` ⇒ the account is refused. */
	const FORBIDDEN_CAPS = array( 'manage_options', 'manage_network', 'edit_users', 'promote_users', 'create_users', 'unfiltered_upload', 'edit_others_posts' );

	public static function init(): void {
		add_filter( 'wp_authenticate_user', array( __CLASS__, 'block_login' ), 1, 2 );
	}

	/**
	 * The system owner's user id, or 0 when there is none (or it is unsafe).
	 *
	 * @param bool $create When true and no account exists yet, create it (bot side only). The dispatcher
	 *                     passes false: it never creates users on the send path.
	 */
	public static function resolve( bool $create = false ): int {
		$id = (int) apply_filters( 'bizcity_crm_system_owner_id', (int) get_option( self::OPTION, 0 ) );
		if ( $id > 0 ) {
			$state = self::inspect( $id );
			if ( 'ok' === $state ) {
				return $id;
			}
			if ( 'privileged' === $state ) {
				return 0; // fail closed — never silently swap in another account.
			}
			// 'missing' (deleted user): fall through so `ensure()` can recreate it.
		}
		return $create ? self::ensure() : 0;
	}

	/**
	 * Get-or-create the account. Idempotent and safe under concurrent bot turns.
	 *
	 * @return int User id, or 0 when it could not be created safely.
	 */
	public static function ensure(): int {
		$user = get_user_by( 'login', self::LOGIN );
		if ( ! $user ) {
			$created = wp_insert_user( array(
				'user_login'   => self::LOGIN,
				'user_pass'    => wp_generate_password( 64, true, true ), // never stored or shown anywhere.
				'user_email'   => self::EMAIL,
				'display_name' => 'BizCity Bot (hệ thống)',
				'role'         => '',
			) );
			$user = is_wp_error( $created ) ? get_user_by( 'login', self::LOGIN ) : get_userdata( (int) $created );
		}
		if ( ! $user || 'ok' !== self::inspect( (int) $user->ID ) ) {
			return 0;
		}
		update_option( self::OPTION, (int) $user->ID, false );
		return (int) $user->ID;
	}

	/**
	 * @return string 'ok' | 'missing' | 'privileged'
	 */
	public static function inspect( int $user_id ): string {
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;
		if ( ! $user ) {
			return 'missing';
		}
		if ( function_exists( 'is_super_admin' ) && is_super_admin( $user_id ) ) {
			return 'privileged';
		}
		foreach ( self::FORBIDDEN_CAPS as $cap ) {
			if ( user_can( $user_id, $cap ) ) {
				return 'privileged';
			}
		}
		return 'ok';
	}

	public static function is_system_owner( int $user_id ): bool {
		return $user_id > 0 && $user_id === (int) get_option( self::OPTION, 0 );
	}

	/** User ids that must never appear in a staff/assignee picker. */
	public static function exclude_ids(): array {
		$id = (int) get_option( self::OPTION, 0 );
		return $id > 0 ? array( $id ) : array();
	}

	/**
	 * `wp_authenticate_user` — the account has no usable password, but block it explicitly too so a
	 * future password reset or an SSO plugin can never turn it into a login.
	 *
	 * @param mixed $user     WP_User|WP_Error
	 * @param mixed $password Unused.
	 * @return mixed
	 */
	public static function block_login( $user, $password = '' ) {
		if ( $user instanceof WP_User && ( self::LOGIN === $user->user_login || self::is_system_owner( (int) $user->ID ) ) ) {
			return new WP_Error( 'bizcity_system_user', 'Tài khoản hệ thống không đăng nhập được.' );
		}
		return $user;
	}
}
