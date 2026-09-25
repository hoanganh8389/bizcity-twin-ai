<?php
/**
 * BizCity CRM — Staff management policy (PHASE-0.48F R-CRMF-2).
 *
 * Single ACL surface for every "manage another employee" action introduced by
 * PHASE-0.48F: creating staff, changing a team role, suspending/reactivating,
 * assigning a Zalo Personal phone, logging a phone back in via QR, adding or
 * removing an inbox member, and opening another employee's read-only
 * workspace. Every REST callback that touches another `user_id` MUST call
 * {@see BizCity_CRM_Staff_Policy::can()} before doing anything else — no
 * endpoint re-implements this rank/team comparison inline (R-CRMF-2).
 *
 * Rank model (D5/D6, 2026-09-17 decision):
 *   administrator (site capability `manage_options`) — unrestricted, tenant-wide.
 *   supervisor — `bizcity_crm_team_members.member_role = 'supervisor'`.
 *   lead       — `member_role = 'lead'`.
 *   agent      — `member_role = 'agent'`, or any user with only
 *                `bizcity_crm_handle_inbox` and no team row yet.
 *
 * A supervisor manages user accounts (a user may own several Zalo Personal
 * phones), leads and agents in their own team. A lead manages only agents in
 * their own team. Nobody manages a peer or a higher rank, and nobody performs
 * a role/status-changing action on themselves through this policy (R-CRMF-3).
 *
 * This class does not create a new table; it reads `bizcity_crm_team_members`
 * (already installed by {@see BizCity_CRM_DB_Installer_V2}) through
 * {@see BizCity_CRM_Team_Manager}. See
 * docs/PHASE-0.48F-CRM-MANAGER-INBOX-TEAM-COMMAND-CENTER-RESEARCH.md §4B.
 *
 * @package BizCity_Twin_CRM
 * @since   PHASE-0.48F 2026-09-17
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Staff_Policy', false ) ) {
	return;
}

final class BizCity_CRM_Staff_Policy {

	const ROLE_ADMIN      = 'admin';
	const ROLE_SUPERVISOR = 'supervisor';
	const ROLE_LEAD       = 'lead';
	const ROLE_AGENT      = 'agent';
	const ROLE_NONE       = 'none';

	/** @var array<string,int> */
	const RANK = array(
		self::ROLE_ADMIN      => 4,
		self::ROLE_SUPERVISOR => 3,
		self::ROLE_LEAD       => 2,
		self::ROLE_AGENT       => 1,
		self::ROLE_NONE        => 0,
	);

	/**
	 * Minimum actor rank required for each action. An action not listed here
	 * defaults to "admin only" (rank 9, unreachable by any team role) so a new
	 * action is fail-closed until someone deliberately widens it.
	 *
	 * @var array<string,int>
	 */
	const MIN_RANK = array(
		'staff.create'         => 3, // supervisor+
		'staff.update_role'    => 3,
		'staff.suspend'        => 3,
		'phone.assign'         => 3, // managing SOMEONE ELSE's phone; see SELF_MIN_RANK for your own.
		'phone.qr'             => 3, // logging SOMEONE ELSE's phone back in; see SELF_MIN_RANK for your own.
		'inbox.member'         => 2, // lead+
		'staff.view_workspace' => 2,
		'conv.transfer'        => 2,
		'team.dashboard'       => 2,
		'task.assign'          => 2, // PHASE-0.50 G5 — giving someone else a task. Self-assign is refused here (SELF_FORBIDDEN); "việc của tôi" goes through the /crm-tasks route's own self branch.
		'contact.view_by_owner' => 2, // PHASE-0.50 W1/W2 — Contacts / Customer 360 filtered by another staff member.
		'staff.set_goal'       => 2, // PHASE-0.52 R-PIPE-7 — the leader sets a member's monthly goal; members only read it.
		// [2026-09-18 Johnny Chu - Chu Hoàng Anh] PHASE-0.53 N5 (S6/G6, doc §3.3 access matrix) — re-provisioning
		// a dead ("account_not_owned") Zalo Personal account is supervisor+ EVEN for your own number (doc: nhân
		// viên ❌ → "Báo quản lý", no self-service escape hatch). Deliberately has NO `SELF_MIN_RANK` entry, unlike
		// `phone.assign`/`phone.qr` above — a self-target action with no `SELF_MIN_RANK` entry falls back to this
		// same floor (see `can()`), so an agent/lead can't recover even their own dead number; only rank 3+ can.
		'phone.recover'        => 3,
		// [2026-09-19 Johnny Chu] PHASE-0.56 C-1 (D56-1, user-chosen 2026-09-19) — `phone.add_for_other`
		// (creating a NEW Zalo Personal number for a DIFFERENT employee, `class-staff-rest.php::create_phone_for_owner()`)
		// was administrator-only through 0.53 (this array had no entry, falling back to the rank-9 fail-closed
		// default). D56-1 widens it to supervisor+ WITHIN THE SAME TEAM, matching `phone.assign`'s floor above —
		// a supervisor may now add a phone for an agent/lead on their own team, never cross-team, never for a
		// peer or higher rank (the usual team/rank comparison below still runs unchanged). Adding a number for
		// YOURSELF still never calls `can()` at all (open to any assignable user, see `create_phone_for_owner()`).
		'phone.add_for_other'  => 3,
		// [2026-09-19 Johnny Chu] PHASE-0.56 C-2 (D56-2, user-chosen 2026-09-19) — generating a Zalo Bot link
		// code addressed to a NAMED different employee (`class-staff-rest.php`, `POST /crm-staff/{id}/zalo-bot-link`).
		// Same floor as `phone.add_for_other`: supervisor+ within the same team. Self-linking never goes through
		// this policy at all — that is the existing `/gpt/` `POST /twin-gpt/zalo-bot/link` self-service flow,
		// which stays untouched (R-LM-8 §16.3 N-01).
		'staff.link_bot'       => 3,
		// [2026-09-19] PHASE-0.56 G-1 (11.B2 "Hướng dẫn 2 lớp") — reading the leader's 7-step onboarding
		// checklist (`GET /crm-staff/onboarding`) has no single subject; same supervisor+ floor as the
		// other team-wide reads it aggregates (`phone.add_for_other`, `staff.link_bot`).
		'staff.onboarding'     => 3,
	);

	/**
	 * Lower rank floor that applies instead of {@see MIN_RANK} when the actor
	 * targets THEMSELVES — e.g. any agent may add their own new Zalo Personal
	 * phone or re-log in their own dead session (§4B.2: "chỉ SĐT của mình");
	 * that has never required supervisor rank. An action not listed here falls
	 * back to its normal `MIN_RANK` even for a self-target (e.g. `staff.
	 * update_role` on yourself is blocked outright by `SELF_FORBIDDEN` below,
	 * never reaches this map).
	 *
	 * @var array<string,int>
	 */
	const SELF_MIN_RANK = array(
		'phone.assign' => 1, // agent+
		'phone.qr'     => 1, // agent+
		'contact.view_by_owner' => 1,
	);

	/**
	 * Actions a user may never perform on themselves, even as administrator —
	 * they change what the target IS allowed to do or whether they can work at
	 * all, and self-service for those belongs to ordinary account settings, not
	 * a manager mutation (R-CRMF-3).
	 *
	 * @var array<int,string>
	 */
	const SELF_FORBIDDEN = array( 'staff.update_role', 'staff.suspend', 'staff.view_workspace', 'inbox.member', 'task.assign', 'staff.set_goal', 'staff.link_bot' );

	/** @var array<string,string> */
	const ROLE_LABEL = array(
		self::ROLE_ADMIN      => 'Admin',
		self::ROLE_SUPERVISOR => 'Supervisor',
		self::ROLE_LEAD       => 'Lead',
		self::ROLE_AGENT      => 'Agent',
		self::ROLE_NONE       => '—',
	);

	/** @var array<int,string> per-request memo, keyed by user_id */
	private static $role_memo = array();

	/**
	 * Resolve one user's highest team rank in the current blog.
	 *
	 * `manage_options` always wins (site administrator, super admin acting on
	 * this blog). Otherwise the highest `member_role` across the user's active
	 * `bizcity_crm_team_members` rows. A user with `bizcity_crm_handle_inbox`
	 * but no team row yet is treated as an un-teamed `agent` — they can work
	 * their own Inbox but cannot manage anyone (every MIN_RANK above 1 excludes
	 * them, and `primary_team()` returns null so team-scoped checks fail).
	 */
	public static function role( int $user_id ): string {
		if ( $user_id <= 0 ) { return self::ROLE_NONE; }
		if ( isset( self::$role_memo[ $user_id ] ) ) { return self::$role_memo[ $user_id ]; }
		// [2026-09-19 Johnny Chu - Chu Hoàng Anh] HOTFIX-MULTISITE-SUPER-ADMIN — Network Super Admin may have no local blog role/capability, but remains the tenant-wide CRM administrator.
		if ( ( function_exists( 'is_super_admin' ) && is_super_admin( $user_id ) ) || user_can( $user_id, 'manage_options' ) ) {
			return self::$role_memo[ $user_id ] = self::ROLE_ADMIN;
		}
		$best = self::ROLE_NONE;
		if ( class_exists( 'BizCity_CRM_Team_Manager' ) ) {
			foreach ( BizCity_CRM_Team_Manager::list_user_memberships( $user_id ) as $row ) {
				$role = sanitize_key( (string) ( $row['member_role'] ?? '' ) );
				if ( isset( self::RANK[ $role ] ) && self::RANK[ $role ] > self::RANK[ $best ] ) {
					$best = $role;
				}
			}
		}
		if ( self::ROLE_NONE === $best && class_exists( 'BizCity_CRM_Capabilities' ) && BizCity_CRM_Capabilities::user_can_handle_inbox( $user_id ) ) {
			$best = self::ROLE_AGENT;
		}
		return self::$role_memo[ $user_id ] = $best;
	}

	public static function rank( string $role ): int {
		return self::RANK[ $role ] ?? 0;
	}

	public static function label( string $role ): string {
		return self::ROLE_LABEL[ $role ] ?? self::ROLE_LABEL[ self::ROLE_NONE ];
	}

	/**
	 * The team a supervisor/lead's rank applies to — the team where they hold
	 * their highest-ranked active membership. Null for admin (unrestricted, no
	 * single team applies) and for a user with no active team row.
	 *
	 * Known simplification: a user active in more than one team is scoped to
	 * only the team backing their highest rank. Multi-team supervisors are not
	 * in this first slice — see doc §4B "known simplification" note before
	 * widening this.
	 */
	public static function primary_team( int $user_id ): ?int {
		if ( $user_id <= 0 || ! class_exists( 'BizCity_CRM_Team_Manager' ) ) { return null; }
		if ( user_can( $user_id, 'manage_options' ) ) { return null; }
		$best_team = null;
		$best_rank = -1;
		foreach ( BizCity_CRM_Team_Manager::list_user_memberships( $user_id ) as $row ) {
			$role = sanitize_key( (string) ( $row['member_role'] ?? '' ) );
			$rank = self::rank( $role );
			if ( $rank > $best_rank ) {
				$best_rank = $rank;
				$best_team = (int) ( $row['team_id'] ?? 0 ) ?: null;
			}
		}
		return $best_team;
	}

	/**
	 * The single ACL check every staff-management endpoint must call before
	 * reading or mutating anything about `$subject_id`.
	 *
	 * @return array{ok:bool,code:string,why:string,actor_role:string,subject_role:?string}
	 *   `code` is a stable machine key (never localized) for REST/audit use;
	 *   `why` is a short Vietnamese sentence safe to show as the R-ERROR-UX hint.
	 */
	public static function can( int $actor_id, string $action, int $subject_id = 0 ): array {
		$actor_role = self::role( $actor_id );
		$base = array( 'actor_role' => $actor_role, 'subject_role' => null );

		if ( self::ROLE_ADMIN === $actor_role ) {
			// Administrator still cannot target themselves for a role/status action.
			if ( $subject_id > 0 && $subject_id === $actor_id && in_array( $action, self::SELF_FORBIDDEN, true ) ) {
				return array_merge( $base, array( 'ok' => false, 'code' => 'self_not_allowed', 'why' => 'Không thể thao tác này trên chính tài khoản của bạn.' ) );
			}
			return array_merge( $base, array( 'ok' => true, 'code' => 'ok', 'why' => '' ) );
		}

		// [2026-09-17 Johnny Chu - Chu Hoàng Anh] PHASE-0.48F — self-targeting
		// is checked BEFORE the general `MIN_RANK` gate below, because some
		// actions (adding/re-logging-in your OWN phone) use a lower
		// `SELF_MIN_RANK` floor than managing someone else's — an agent must
		// pass here even though `MIN_RANK['phone.qr']` (3) would otherwise
		// reject them first. `SELF_FORBIDDEN` actions never reach a rank check
		// at all: they are refused for every non-admin regardless of rank.
		if ( $subject_id > 0 && $subject_id === $actor_id ) {
			if ( in_array( $action, self::SELF_FORBIDDEN, true ) ) {
				return array_merge( $base, array( 'ok' => false, 'code' => 'self_not_allowed', 'why' => 'Không thao tác trên chính mình.' ) );
			}
			$self_min_rank = self::SELF_MIN_RANK[ $action ] ?? ( self::MIN_RANK[ $action ] ?? 9 );
			if ( self::rank( $actor_role ) < $self_min_rank ) {
				return array_merge( $base, array( 'ok' => false, 'code' => 'rank_insufficient', 'why' => self::label( $actor_role ) . ' không có quyền thao tác này.' ) );
			}
			return array_merge( $base, array( 'ok' => true, 'code' => 'ok', 'why' => '' ) );
		}

		// Team-scoped action with no single subject (e.g. `staff.create`,
		// `team.dashboard`) or managing a DIFFERENT user — both use the normal
		// `MIN_RANK` floor for the action.
		$min_rank = self::MIN_RANK[ $action ] ?? 9; // unknown action → admin-only, fail closed.
		if ( self::rank( $actor_role ) < $min_rank ) {
			return array_merge( $base, array( 'ok' => false, 'code' => 'rank_insufficient', 'why' => self::label( $actor_role ) . ' không có quyền thao tác này.' ) );
		}

		if ( $subject_id <= 0 ) {
			// No single subject — the caller still must confine the result set
			// with `manageable_user_ids()`.
			return array_merge( $base, array( 'ok' => true, 'code' => 'ok', 'why' => '' ) );
		}

		$subject_role = self::role( $subject_id );
		$base['subject_role'] = $subject_role;

		$actor_team   = self::primary_team( $actor_id );
		$subject_team = self::primary_team( $subject_id );
		if ( null === $actor_team || $actor_team !== $subject_team ) {
			return array_merge( $base, array( 'ok' => false, 'code' => 'different_team', 'why' => 'Nhân viên này không thuộc team bạn quản lý.' ) );
		}

		if ( self::rank( $subject_role ) >= self::rank( $actor_role ) ) {
			return array_merge( $base, array( 'ok' => false, 'code' => 'subject_not_lower_rank', 'why' => self::label( $subject_role ) . ' cùng cấp hoặc cao hơn bạn.' ) );
		}

		return array_merge( $base, array( 'ok' => true, 'code' => 'ok', 'why' => '' ) );
	}

	/**
	 * User IDs `$actor_id` may perform mutating staff actions on — active team
	 * members of the actor's own team whose rank is strictly lower. Does NOT
	 * include the actor. Null means "administrator, no restriction" (tenant-wide
	 * — the caller queries every assignable user, see
	 * `is_crm_assignable_user()` / `crm-settings/assignable-users`).
	 *
	 * @return array<int,int>|null
	 */
	public static function manageable_user_ids( int $actor_id ): ?array {
		$actor_role = self::role( $actor_id );
		if ( self::ROLE_ADMIN === $actor_role ) { return null; }
		if ( self::rank( $actor_role ) < 2 ) { return array(); } // below lead: manages nobody.
		$team_id = self::primary_team( $actor_id );
		if ( null === $team_id || ! class_exists( 'BizCity_CRM_Team_Manager' ) ) { return array(); }
		$out = array();
		foreach ( BizCity_CRM_Team_Manager::list_team_members( $team_id ) as $row ) {
			$user_id = (int) ( $row['user_id'] ?? 0 );
			$role    = sanitize_key( (string) ( $row['member_role'] ?? '' ) );
			if ( $user_id > 0 && $user_id !== $actor_id && self::rank( $role ) < self::rank( $actor_role ) ) {
				$out[] = $user_id;
			}
		}
		return $out;
	}

	/**
	 * `manageable_user_ids()` plus the actor themselves — the read-only roster
	 * an actor's staff list may show (R-CRMF-8). Null still means tenant-wide.
	 *
	 * @return array<int,int>|null
	 */
	public static function visible_user_ids( int $actor_id ): ?array {
		$manageable = self::manageable_user_ids( $actor_id );
		if ( null === $manageable ) { return null; }
		if ( $actor_id > 0 ) { $manageable[] = $actor_id; }
		return array_values( array_unique( $manageable ) );
	}

	/**
	 * Whether `$user_id` is eligible at all to appear in a CRM staff catalog or
	 * be targeted by a staff-management mutation: a real, non-subscriber user
	 * of the current blog. Mirrors the eligibility rule
	 * `BizCity_CRM_REST_Controller::is_crm_assignable_user()` already applies
	 * to `crm-settings/assignable-users`, kept here as the shared public copy
	 * so `class-staff-rest.php` does not duplicate the private one.
	 */
	public static function is_assignable_user( int $user_id ): bool {
		if ( $user_id <= 0 || ! function_exists( 'get_userdata' ) ) { return false; }
		$user = get_userdata( $user_id );
		if ( ! $user || in_array( 'subscriber', (array) $user->roles, true ) ) { return false; }
		// [2026-09-25 10:31 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.60H — fail closed when the multisite blog-membership API is unavailable.
		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			return function_exists( 'is_user_member_of_blog' ) && is_user_member_of_blog( $user_id, get_current_blog_id() );
		}
		return ! function_exists( 'is_user_member_of_blog' ) || is_user_member_of_blog( $user_id, get_current_blog_id() );
	}

	/**
	 * Build the standard R-ERROR-UX-shaped `WP_REST_Response` for a failed
	 * {@see can()} result, so every staff-management endpoint returns the same
	 * denial envelope instead of hand-rolling one. `code` from `can()` becomes
	 * the REST `code`; `403` matches the other capability-gated CRM routes.
	 */
	public static function denied_response( array $decision, int $status = 403 ): WP_REST_Response {
		return new WP_REST_Response( array(
			'ok'        => false,
			'code'      => (string) ( $decision['code'] ?? 'permission_denied' ),
			'message'   => (string) ( $decision['why'] ?? 'Bạn không có quyền thực hiện thao tác này.' ),
			'hint'      => 'Nhờ Supervisor hoặc Admin thực hiện thao tác này.',
			'help_code' => 'member_not_manageable',
		), $status );
	}
}
