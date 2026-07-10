<?php
/**
 * BizCity CRM — Capability registrar (PHASE 0.35 M1.W2).
 *
 * 3 new caps mapped onto WP roles. Idempotent: ensure() can be called on every
 * plugins_loaded; only writes when delta detected (signature option).
 *
 * @package BizCity_Twin_CRM
 * @since   PHASE 0.35 M1.W2
 */

defined( 'ABSPATH' ) || exit;

class BizCity_CRM_Capabilities {

	const CAP_HANDLE_INBOX  = 'bizcity_crm_handle_inbox';
	const CAP_MANAGE_RULES  = 'bizcity_crm_manage_rules';
	const CAP_VIEW_REPORTS  = 'bizcity_crm_view_reports';

	const SIGNATURE_OPTION  = 'bizcity_crm_caps_signature';
	const SIGNATURE_VERSION = '0.35.1';

	/**
	 * Mapping role => caps. administrator gets all; editor handles inbox + reports.
	 *
	 * @return array<string, array<int,string>>
	 */
	public static function map(): array {
		return array(
			'administrator' => array( self::CAP_HANDLE_INBOX, self::CAP_MANAGE_RULES, self::CAP_VIEW_REPORTS ),
			'editor'        => array( self::CAP_HANDLE_INBOX, self::CAP_VIEW_REPORTS ),
		);
	}

	/**
	 * Ensure caps are granted. Idempotent — uses signature to short-circuit.
	 */
	public static function ensure(): void {
		if ( get_option( self::SIGNATURE_OPTION ) === self::SIGNATURE_VERSION ) {
			return;
		}
		self::grant_all();
		update_option( self::SIGNATURE_OPTION, self::SIGNATURE_VERSION );
	}

	/**
	 * Force-grant caps regardless of signature (used on activation + diag re-apply).
	 */
	public static function grant_all(): void {
		foreach ( self::map() as $role_slug => $caps ) {
			$role = get_role( $role_slug );
			if ( ! $role ) { continue; }
			foreach ( $caps as $cap ) {
				if ( ! $role->has_cap( $cap ) ) {
					$role->add_cap( $cap );
				}
			}
		}
	}

	/**
	 * Remove caps (called on plugin deactivation if desired — currently unused).
	 */
	public static function revoke_all(): void {
		$all_caps = array( self::CAP_HANDLE_INBOX, self::CAP_MANAGE_RULES, self::CAP_VIEW_REPORTS );
		foreach ( wp_roles()->roles as $role_slug => $_ ) {
			$role = get_role( $role_slug );
			if ( ! $role ) { continue; }
			foreach ( $all_caps as $cap ) {
				if ( $role->has_cap( $cap ) ) {
					$role->remove_cap( $cap );
				}
			}
		}
		delete_option( self::SIGNATURE_OPTION );
	}

	/**
	 * Diagnostic helper — return per-role + per-cap snapshot.
	 *
	 * @return array<string,array<string,bool>>
	 */
	public static function snapshot(): array {
		$out  = array();
		$caps = array( self::CAP_HANDLE_INBOX, self::CAP_MANAGE_RULES, self::CAP_VIEW_REPORTS );
		foreach ( array( 'administrator', 'editor', 'author', 'subscriber' ) as $role_slug ) {
			$role = get_role( $role_slug );
			if ( ! $role ) { continue; }
			$row = array();
			foreach ( $caps as $cap ) {
				$row[ $cap ] = (bool) $role->has_cap( $cap );
			}
			$out[ $role_slug ] = $row;
		}
		return $out;
	}
}
