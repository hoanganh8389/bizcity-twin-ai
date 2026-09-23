<?php
/**
 * BizCity CRM — process role resolution (PHASE-0.63A WP-2.7). **LANE A OWNS THIS FILE.**
 *
 * A definition says `role: R07`; an escalation has to reach a real person. The role table lives in the
 * definition itself (0.63 §3.5) because `team_members.member_role` only knows agent/lead/supervisor/
 * observer — not R01..R09. Resolution happens at fire time, never at save time, so replacing a person
 * does not mean editing every pipeline.
 *
 * @package BizCity_Twin_CRM
 * @since 2026-09-21 (PHASE-0.63A WP-2.7, stub)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Pipeline_Roles', false ) ) {
	return;
}

final class BizCity_CRM_Pipeline_Roles {

	/**
	 * @param array  $definition Pinned definition of the run.
	 * @param string $role       Role code, e.g. `R07` or `lead`.
	 * @param array  $ctx        `['run_id'=>, 'stage_key'=>, 'assignee_id'=>, 'owner_id'=>]`.
	 * @return int[]|WP_Error WordPress user ids.
	 */
	public static function resolve_role( array $definition, string $role, array $ctx = array() ) {
		// [2026-09-21 07:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.63A WP-2.7 — resolve definition roles at fire time without persisting recipients.
		$role = self::clean_token( $role );
		$roles = isset( $definition['roles'] ) && is_array( $definition['roles'] ) ? $definition['roles'] : array();
		$entry = isset( $roles[ $role ] ) && is_array( $roles[ $role ] ) ? $roles[ $role ] : null;
		if ( null === $entry ) {
			foreach ( $roles as $declared_role => $declared_entry ) {
				if ( self::clean_token( (string) $declared_role ) === $role && is_array( $declared_entry ) ) {
					$entry = $declared_entry;
					break;
				}
			}
		}
		if ( null === $entry ) {
			return self::error( 'role_not_found', 'Không tìm thấy vai trò pipeline.', array( 'role' => $role ) );
		}
		$resolve = isset( $entry['resolve'] ) && is_array( $entry['resolve'] ) ? $entry['resolve'] : array();
		if ( isset( $resolve['users'] ) && is_array( $resolve['users'] ) ) {
			return self::valid_user_ids( $resolve['users'] );
		}
		if ( isset( $resolve['team'] ) ) {
			$team_id = (int) $resolve['team'];
			if ( $team_id <= 0 || ! class_exists( 'BizCity_CRM_Team_Manager' ) ) { return array(); }
			$out = array();
			foreach ( (array) BizCity_CRM_Team_Manager::list_team_members( $team_id ) as $member ) {
				$user_id = (int) ( $member['user_id'] ?? 0 );
				if ( $user_id > 0 ) { $out[] = $user_id; }
			}
			return array_values( array_unique( $out ) );
		}
		if ( 'team_lead_of_assignee' === (string) ( $resolve['relation'] ?? '' ) ) {
			$assignee_id = (int) ( $ctx['assignee_id'] ?? $ctx['owner_id'] ?? 0 );
			if ( $assignee_id <= 0 || ! class_exists( 'BizCity_CRM_Team_Manager' ) ) { return array(); }
			foreach ( (array) BizCity_CRM_Team_Manager::list_user_memberships( $assignee_id ) as $membership ) {
				$team_id = (int) ( $membership['team_id'] ?? 0 );
				if ( $team_id <= 0 ) { continue; }
				$members = (array) BizCity_CRM_Team_Manager::list_team_members( $team_id );
				$supervisors = array();
				$leads = array();
				foreach ( $members as $member ) {
					$user_id = (int) ( $member['user_id'] ?? 0 );
					$member_role = self::clean_token( (string) ( $member['member_role'] ?? '' ) );
					if ( $user_id <= 0 || $user_id === $assignee_id ) { continue; }
					if ( 'supervisor' === $member_role ) { $supervisors[] = $user_id; }
					if ( 'lead' === $member_role ) { $leads[] = $user_id; }
				}
				if ( ! empty( $supervisors ) ) { return array_values( array_unique( $supervisors ) ); }
				if ( ! empty( $leads ) ) { return array_values( array_unique( $leads ) ); }
			}
			return array();
		}
		// [2026-09-23] `owner_of_run`/`creator_of_run`/`assignee_of_stage` pass definition VALIDATION
		// (registry check_role() has always accepted all four relation names) but were never resolved here —
		// every shipped template (purchase/request/production) declares roles with these three, so every
		// notify()/escalate() using them was silently failing with `role_resolver_invalid` at fire time.
		// `$ctx` is built by `Pipeline_Run_Service::role_context()`, which resolves `assignee_id` to
		// whoever actually worked the stage (`custom_json.stages[key].by`), falling back to the run owner.
		if ( 'owner_of_run' === (string) ( $resolve['relation'] ?? '' ) ) {
			$owner_id = (int) ( $ctx['owner_id'] ?? 0 );
			return $owner_id > 0 ? array( $owner_id ) : array();
		}
		if ( 'creator_of_run' === (string) ( $resolve['relation'] ?? '' ) ) {
			$creator_id = (int) ( $ctx['creator_id'] ?? 0 );
			return $creator_id > 0 ? array( $creator_id ) : array();
		}
		if ( 'assignee_of_stage' === (string) ( $resolve['relation'] ?? '' ) ) {
			$assignee_id = (int) ( $ctx['assignee_id'] ?? $ctx['owner_id'] ?? 0 );
			return $assignee_id > 0 ? array( $assignee_id ) : array();
		}
		return self::error( 'role_resolver_invalid', 'Cấu hình phân giải vai trò không hợp lệ.', array( 'role' => $role ) );
	}

	/** Recipients of one `notify(...)` / `escalate(...)` argument. @return int[]|WP_Error */
	public static function resolve_recipients( array $definition, string $expression, array $ctx = array() ) {
		// [2026-09-21 07:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.63A WP-2.7 — keep on_miss recipient parsing code-owned and fail closed.
		$expression = trim( (string) $expression );
		if ( preg_match( '/^(?:notify|escalate)\(role:([a-zA-Z0-9._-]+)\)$/', $expression, $match ) ) {
			return self::resolve_role( $definition, $match[1], $ctx );
		}
		if ( preg_match( '/^role:([a-zA-Z0-9._-]+)$/', $expression, $match ) ) {
			return self::resolve_role( $definition, $match[1], $ctx );
		}
		return self::error( 'recipient_expression_invalid', 'Biểu thức người nhận không hợp lệ.', array( 'expression' => $expression ) );
	}

	/** Recipients with no Zalo Bot binding — D63-9 wants these named, not silently dropped. @return int[]|WP_Error */
	public static function unbound_recipients( array $user_ids ) {
		// [2026-09-21 07:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.63A WP-2.7 — expose recipients without a Zalo Bot binding instead of silently dropping them.
		$out = array();
		foreach ( self::valid_user_ids( $user_ids ) as $user_id ) {
			$target = class_exists( 'BizCity_Channel_User_Linker' ) && method_exists( 'BizCity_Channel_User_Linker', 'zalo_bot_target_for_user' )
				? BizCity_Channel_User_Linker::zalo_bot_target_for_user( $user_id )
				: array();
			if ( empty( $target ) ) { $out[] = $user_id; }
		}
		return $out;
	}

	private static function valid_user_ids( array $user_ids ): array {
		$out = array();
		foreach ( $user_ids as $user_id ) {
			$user_id = (int) $user_id;
			if ( $user_id > 0 && ! in_array( $user_id, $out, true ) ) { $out[] = $user_id; }
		}
		return $out;
	}

	private static function clean_token( string $value ): string {
		return strtolower( preg_replace( '/[^a-zA-Z0-9._-]+/', '', trim( $value ) ) );
	}

	private static function error( string $code, string $message, array $data = array() ) {
		return new WP_Error( $code, $message, array_merge( array( 'status' => 422, 'hint' => 'Kiểm tra lại cấu hình vai trò pipeline.', 'help_code' => 'pipeline_role_invalid' ), $data ) );
	}

}
