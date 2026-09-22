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
	const CAP_MANAGE_TEAMS  = 'bizcity_crm_manage_teams';
	const CAP_ASSIGN_CONVERSATIONS = 'bizcity_crm_assign_conversations';
	const CAP_MANAGE_ASSIGNMENT_POLICY = 'bizcity_crm_manage_assignment_policy';

	// [2026-09-17 Johnny Chu - Chu Hoàng Anh] PHASE-0.48F §4B.3/R-CRMF-4 — a
	// staff account created through `/crm-staff` always gets exactly this
	// WordPress role: enough to log in and use the Inbox, never enough to edit
	// the site. Supervisor/lead never receive `create_users`/`promote_users`/
	// `edit_users`, so nobody using this role tree can escalate to admin.
	const ROLE_STAFF = 'bizcity_crm_staff';

	const SIGNATURE_OPTION  = 'bizcity_crm_caps_signature';
	const SIGNATURE_VERSION = '0.48F.1';

	/**
	 * Mapping role => caps. administrator gets all; editor handles inbox + reports.
	 *
	 * [2026-09-17 Johnny Chu - Chu Hoàng Anh] PHASE-0.48F-D6 — `CAP_MANAGE_TEAMS`
	 * (create/edit team, add/remove inbox member) is removed from `editor`.
	 * Team management now flows through `BizCity_CRM_Staff_Policy`, which grants
	 * a `supervisor`/`lead` team-role holder scoped rights over their own team
	 * without a WordPress-role-wide capability. Only `administrator` keeps this
	 * cap (super admin/network context is out of scope here — this is per-blog).
	 * See docs/PHASE-0.48F-CRM-MANAGER-INBOX-TEAM-COMMAND-CENTER-RESEARCH.md §4B.
	 *
	 * @return array<string, array<int,string>>
	 */
	public static function map(): array {
		return array(
			'administrator' => array( self::CAP_HANDLE_INBOX, self::CAP_MANAGE_RULES, self::CAP_VIEW_REPORTS, self::CAP_MANAGE_TEAMS, self::CAP_ASSIGN_CONVERSATIONS, self::CAP_MANAGE_ASSIGNMENT_POLICY ),
			'editor'        => array( self::CAP_HANDLE_INBOX, self::CAP_VIEW_REPORTS, self::CAP_ASSIGN_CONVERSATIONS, self::CAP_MANAGE_ASSIGNMENT_POLICY ),
		);
	}

	/**
	 * Caps that a previous signature version granted to a role but the current
	 * version no longer wants there. `ensure()`/`grant_all()` only ever ADD caps
	 * (`add_cap`), so a cap removed from `map()` stays stuck on roles that already
	 * received it unless explicitly revoked here. Keyed by the version that
	 * introduced the removal so `ensure()` only runs each entry once.
	 *
	 * @return array<string, array<string, array<int,string>>> version => role => caps
	 */
	private static function removals(): array {
		return array(
			'0.48F.1' => array(
				'editor' => array( self::CAP_MANAGE_TEAMS ),
			),
		);
	}

	/**
	 * Whether a user may send/resolve/assign inside Inbox resources they can see.
	 */
	public static function user_can_handle_inbox( int $user_id = 0 ): bool {
		// [2026-09-17 Johnny Chu - Chu Hoàng Anh] PHASE-0.49-E2 — reading a conversation never implies write/send; handle capability is required first.
		$user_id = $user_id > 0 ? $user_id : (int) get_current_user_id();
		if ( $user_id <= 0 ) { return false; }
		// [2026-09-19] PHASE-0.60 C60-A06 — one of several duplicate "is admin"
		// checks (§C2); adds explicit Super Admin coverage (was `manage_options`
		// only here, `is_super_admin()||manage_options` elsewhere).
		if ( function_exists( 'is_super_admin' ) && is_super_admin( $user_id ) ) { return true; }
		if ( user_can( $user_id, 'manage_options' ) ) { return true; }
		$cap = (string) apply_filters( 'bizcity_crm_handle_inbox_cap', self::CAP_HANDLE_INBOX );
		return '' !== $cap && user_can( $user_id, $cap );
	}

	/**
	 * Capability string for the employee Inbox admin menu entry.
	 *
	 * Admins keep `manage_options`. Employees with the handle capability keep the
	 * menu entry **regardless of current Inbox scope** — an empty scope is the
	 * "chưa được gán" (not yet assigned) state, not a reason to hide the page;
	 * the page itself shows the self-connect Zalo Cá nhân flow instead of an
	 * Inbox list (PHASE-0.60 §8-Q3, C60-A04b).
	 */
	public static function inbox_menu_cap( int $user_id = 0 ): string {
		// [2026-09-17 Johnny Chu - Chu Hoàng Anh] PHASE-0.49-E1 — employee Inbox menu follows handle capability.
		// [2026-09-20 Johnny Chu] PHASE-0.60 C60-A04b — dropped the "empty scope -> manage_options" fallback
		// (§8-Q3): a staff member with no inbox yet must still reach the page to self-connect Zalo Cá nhân.
		$user_id = $user_id > 0 ? $user_id : (int) get_current_user_id();
		if ( $user_id <= 0 || user_can( $user_id, 'manage_options' ) ) { return 'manage_options'; }
		if ( ! self::user_can_handle_inbox( $user_id ) ) { return 'manage_options'; }
		return (string) apply_filters( 'bizcity_crm_handle_inbox_cap', self::CAP_HANDLE_INBOX );
	}

	/**
	 * Ensure caps are granted. Idempotent — uses signature to short-circuit.
	 */
	public static function ensure(): void {
		$previous = (string) get_option( self::SIGNATURE_OPTION );
		if ( $previous === self::SIGNATURE_VERSION ) {
			return;
		}
		self::grant_all();
		self::apply_removals( $previous );
		update_option( self::SIGNATURE_OPTION, self::SIGNATURE_VERSION );
	}

	/**
	 * Revoke caps listed in {@see removals()} for every version between the
	 * previously-applied signature (exclusive) and the current one (inclusive).
	 * Called once per upgrade from `ensure()`; safe to call repeatedly since
	 * `remove_cap()` on a role that never had the cap is a no-op.
	 */
	private static function apply_removals( string $from_version ): void {
		foreach ( self::removals() as $version => $role_caps ) {
			// [2026-09-17 Johnny Chu - Chu Hoàng Anh] PHASE-0.48F-D6 — a fresh
			// site (empty $from_version) never had the old cap granted, so it
			// has nothing to revoke; still safe to run (remove_cap is a no-op).
			foreach ( $role_caps as $role_slug => $caps ) {
				$role = get_role( $role_slug );
				if ( ! $role ) { continue; }
				foreach ( $caps as $cap ) {
					if ( $role->has_cap( $cap ) ) {
						$role->remove_cap( $cap );
					}
				}
			}
		}
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
		self::ensure_staff_role();
	}

	/**
	 * Register (or repair) `bizcity_crm_staff`: `read` (log in, use the
	 * TwinShell/CRM admin menu, which itself gates capability `read` — see
	 * `class-twinchat-admin-menu.php`) plus `bizcity_crm_handle_inbox`
	 * (work the Inbox). Nothing else — no post/page/menu/user-management
	 * capability, so a supervisor/lead creating this role's users can never
	 * hand out admin-equivalent access.
	 */
	public static function ensure_staff_role(): void {
		$role = get_role( self::ROLE_STAFF );
		if ( ! $role ) {
			add_role( self::ROLE_STAFF, 'Nhân viên CRM', array( 'read' => true, self::CAP_HANDLE_INBOX => true ) );
			return;
		}
		if ( ! $role->has_cap( 'read' ) ) { $role->add_cap( 'read' ); }
		if ( ! $role->has_cap( self::CAP_HANDLE_INBOX ) ) { $role->add_cap( self::CAP_HANDLE_INBOX ); }
	}

	/**
	 * Remove caps (called on plugin deactivation if desired — currently unused).
	 */
	public static function revoke_all(): void {
		$all_caps = array( self::CAP_HANDLE_INBOX, self::CAP_MANAGE_RULES, self::CAP_VIEW_REPORTS, self::CAP_MANAGE_TEAMS, self::CAP_ASSIGN_CONVERSATIONS, self::CAP_MANAGE_ASSIGNMENT_POLICY );
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
		$caps = array( self::CAP_HANDLE_INBOX, self::CAP_MANAGE_RULES, self::CAP_VIEW_REPORTS, self::CAP_MANAGE_TEAMS, self::CAP_ASSIGN_CONVERSATIONS, self::CAP_MANAGE_ASSIGNMENT_POLICY );
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

	/**
	 * [2026-09-20 Johnny Chu] HOTFIX — the Inbox "AI Chat" panel is the twinweb
	 * module's SPA (`bizcity-twinweb/v1`), which gates every request through its
	 * own access policy keyed on WordPress role. That policy's role allow-list
	 * only ships with core WP roles (subscriber…administrator) by default, so a
	 * `bizcity_crm_staff`-only account — exactly what `/crm-staff` creates, see
	 * `ensure_staff_role()` above — gets `permission_denied` from AI Chat even
	 * though Inbox itself works fine. Hooked unconditionally below so any site
	 * still on the default policy (no custom access matrix saved yet) picks
	 * this role up for free.
	 *
	 * @param array $policy Default access policy being assembled.
	 * @return array
	 */
	public static function filter_default_access_policy( $policy ) {
		if ( ! is_array( $policy ) || ! isset( $policy['member']['allowed_roles'] ) || ! is_array( $policy['member']['allowed_roles'] ) ) {
			return $policy;
		}
		$policy['member']['allowed_roles'][] = self::ROLE_STAFF;
		$policy['member']['allowed_roles']   = array_values( array_unique( $policy['member']['allowed_roles'] ) );
		return $policy;
	}

	/**
	 * [2026-09-20 Johnny Chu] HOTFIX — the role allow-list approach above only
	 * covers accounts that literally hold the `bizcity_crm_staff` WordPress role.
	 * A CRM team member can just as easily be some other role (e.g. a
	 * WooCommerce `customer` who was added to a team without changing their
	 * underlying WP role) — what actually grants them the Inbox is the
	 * `bizcity_crm_handle_inbox` capability, not a specific role string. Hooks
	 * twinweb's capability-based bypass so anyone who can work the Inbox can
	 * also reach its embedded "AI Chat" panel, regardless of role.
	 *
	 * @param bool $bypass Current bypass decision from earlier filters.
	 * @param int  $user_id User being evaluated.
	 * @return bool
	 */
	public static function bypass_twinweb_access_for_inbox_staff( $bypass, $user_id ) {
		if ( $bypass ) {
			return $bypass;
		}
		return self::user_can_handle_inbox( (int) $user_id );
	}
}

add_filter( 'bizcity_twinweb_default_access_policy', array( 'BizCity_CRM_Capabilities', 'filter_default_access_policy' ) );
add_filter( 'bizcity_twinweb_access_bypass', array( 'BizCity_CRM_Capabilities', 'bypass_twinweb_access_for_inbox_staff' ), 10, 2 );
