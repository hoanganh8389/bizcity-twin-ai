<?php
/**
 * PHASE-0.85 §K1 (C85-7) — which Zalo Cá Nhân numbers a WP user may see AI-cost
 * reports for. `scope=all` needs `bizcity_crm_view_reports` (or `manage_options`);
 * `scope=mine` is any logged-in user, restricted to numbers they OWN
 * (`bizcity_zalo_accounts.owner_user_id`) — `manage_options` does NOT widen
 * `mine` (R-TWIN-GPT-FIRST §"quyền xem việc của người khác không tự có từ vai trò").
 *
 * `account_ref` = `bridge_account_id` (falls back to the row `id` when that
 * column is empty) — the same identifier zalo-hub's `/wp/brain/*` and Hub's
 * `usage/by-account` use, so callers can pass it straight through without a
 * second lookup.
 *
 * @package BizCity_Twin_CRM
 * @since   PHASE-0.85 2026-09-30
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_AI_Usage_Scope', false ) ) {
	return;
}

final class BizCity_CRM_AI_Usage_Scope {

	/**
	 * @param int    $user_id   WP user id (already authenticated by the REST permission callback).
	 * @param string $requested 'all' or 'mine' (anything else falls back to 'mine' — fail closed, never widen).
	 * @return array{mode:string,account_refs:string[]}|WP_Error
	 */
	public static function resolve( int $user_id, string $requested ) {
		$mode = ( 'all' === $requested ) ? 'all' : 'mine';

		if ( 'all' === $mode ) {
			if ( ! self::can_view_all( $user_id ) ) {
				return new WP_Error(
					'crm_forbidden_reports',
					'Bạn chưa có quyền xem báo cáo chi phí AI',
					array(
						'status'    => 403,
						'hint'      => 'Liên hệ quản trị viên để được cấp quyền xem báo cáo.',
						'help_code' => 'S85-C7-403',
					)
				);
			}
			return array( 'mode' => 'all', 'account_refs' => self::all_account_refs() );
		}

		return array( 'mode' => 'mine', 'account_refs' => self::owned_account_refs( $user_id ) );
	}

	/**
	 * Same tier as every other CRM report route (`can_view_reports()`'s `CAP_VIEW_REPORTS` or
	 * `manage_options`) — `user_can()` not `current_user_can()` because `$user_id` here is an
	 * explicit parameter, not necessarily the request's current user (keeps this testable
	 * without switching the global current user, and correct if ever called for another user).
	 */
	private static function can_view_all( int $user_id ): bool {
		$cap = class_exists( 'BizCity_CRM_Capabilities' ) ? BizCity_CRM_Capabilities::CAP_VIEW_REPORTS : 'bizcity_crm_view_reports';
		return user_can( $user_id, 'manage_options' ) || user_can( $user_id, $cap );
	}

	/** @return string[] */
	private static function owned_account_refs( int $user_id ): array {
		if ( $user_id <= 0 || ! class_exists( 'BizCity_Zalo_Mapping_Repo' ) ) {
			return array();
		}
		$rows = BizCity_Zalo_Mapping_Repo::list_personal_accounts_for_owner( $user_id );
		return self::refs_of( $rows );
	}

	/** @return string[] */
	private static function all_account_refs(): array {
		if ( ! class_exists( 'BizCity_Zalo_Mapping_Repo' ) ) {
			return array();
		}
		// 200 = the repo's own hard cap (`list_personal_accounts()`); a site with more numbers
		// than that needs pagination on this whole feature, not just this one lookup — out of
		// scope for K1, tracked as a follow-up if it ever comes up in practice.
		$rows = BizCity_Zalo_Mapping_Repo::list_personal_accounts( array( 'limit' => 200 ) );
		return self::refs_of( $rows );
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 * @return string[]
	 */
	private static function refs_of( array $rows ): array {
		$refs = array();
		foreach ( $rows as $row ) {
			$ref = isset( $row['bridge_account_id'] ) ? (string) $row['bridge_account_id'] : '';
			if ( '' === $ref && isset( $row['id'] ) ) {
				$ref = (string) $row['id'];
			}
			if ( '' !== $ref ) {
				$refs[] = $ref;
			}
		}
		return array_values( array_unique( $refs ) );
	}
}
