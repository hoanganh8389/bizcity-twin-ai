<?php
/**
 * Server-side Context Bank read authorization.
 *
 * Admins can inspect the current tenant. Other users are restricted to
 * pointer rows owned by the authenticated WordPress user; request filters
 * never establish ownership.
 *
 * @package BizCity_Twin_AI
 * @subpackage Context_Bank
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Context_Bank_Access', false ) ) {
	return;
}

final class BizCity_Context_Bank_Access {

	const READ_CAPABILITY = 'read';

	/**
	 * Constrain a ledger filter set to the authenticated server-side owner.
	 *
	 * @param array<string,mixed> $filters Posted or internal filters.
	 * @return array<string,mixed>
	 */
	public static function scope_filters( array $filters ) {
		// [2026-09-01 Johnny Chu] PHASE-CB-MVP — derive owner scope from the authenticated request, never from posted IDs.
		if ( self::is_admin() ) {
			return array( 'ok' => true, 'filters' => $filters, 'scope' => 'tenant_admin' );
		}
		// [2026-09-24 Claude Sonnet 5] PHASE-0.60H D-H1 (option A) — server-issued runtime identity read. See
		// with_runtime_read(). Only reachable while server PHP holds an open grant; never from request input.
		$runtime = self::runtime_scope_filters( $filters );
		if ( null !== $runtime ) {
			return $runtime;
		}
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( $user_id <= 0 || ! self::can_read() ) {
			return array( 'ok' => false, 'reason' => 'context_bank_read_denied' );
		}
		if ( isset( $filters['wp_user_id'] ) && (int) $filters['wp_user_id'] > 0 && (int) $filters['wp_user_id'] !== $user_id ) {
			return array( 'ok' => false, 'reason' => 'context_bank_owner_scope_denied' );
		}
		if ( isset( $filters['user_id'] ) && (int) $filters['user_id'] > 0 && (int) $filters['user_id'] !== $user_id ) {
			return array( 'ok' => false, 'reason' => 'context_bank_owner_scope_denied' );
		}
		$channel_scope = self::channel_scope_from_filters( $filters, $user_id );
		if ( ! empty( $channel_scope['requested'] ) ) {
			if ( empty( $channel_scope['ok'] ) ) {
				return $channel_scope;
			}
			unset( $filters['wp_user_id'], $filters['user_id'] );
			return array( 'ok' => true, 'filters' => $filters, 'scope' => 'channel_grant', 'channel' => $channel_scope['channel'], 'account_key' => $channel_scope['account_key'] );
		}
		$filters['wp_user_id'] = $user_id;
		unset( $filters['user_id'] );
		return array( 'ok' => true, 'filters' => $filters, 'scope' => 'user' );
	}

	/**
	 * Authorize one pointer after it has been loaded from the current tenant.
	 *
	 * @param array<string,mixed> $pointer Ledger pointer row.
	 * @return array<string,mixed>
	 */
	public static function authorize_pointer( array $pointer ) {
		// [2026-09-01 Johnny Chu] PHASE-CB-MVP — recheck tenant, capability and pointer owner immediately before file follow.
		$current_blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		if ( $current_blog_id <= 0 || (int) ( $pointer['blog_id'] ?? 0 ) !== $current_blog_id ) {
			return array( 'ok' => false, 'reason' => 'context_bank_tenant_scope_denied' );
		}
		if ( self::is_admin() ) {
			return array( 'ok' => true, 'scope' => 'tenant_admin' );
		}
		// [2026-09-24 Claude Sonnet 5] PHASE-0.60H D-H1 — the per-pointer half of the runtime grant: the pointer must
		// be exactly the granted identity AND the granted contract AND a memory record.
		$grant = self::active_runtime_grant();
		if ( null !== $grant ) {
			$ok = strtolower( trim( (string) ( $pointer['identity_uuid'] ?? '' ) ) ) === $grant['identity_uuid']
				&& (string) ( $pointer['source_contract_id'] ?? '' ) === $grant['contract_id']
				&& 'memory' === (string) ( $pointer['record_kind'] ?? '' );
			return $ok
				? array( 'ok' => true, 'scope' => 'runtime_identity' )
				: array( 'ok' => false, 'reason' => 'context_bank_runtime_scope_denied' );
		}
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( $user_id <= 0 || ! self::can_read() ) {
			return array( 'ok' => false, 'reason' => 'context_bank_read_denied' );
		}
		if ( (int) ( $pointer['wp_user_id'] ?? 0 ) !== $user_id ) {
			$channel_scope = self::channel_scope_from_pointer( $pointer, $user_id );
			if ( empty( $channel_scope['ok'] ) ) {
				return array( 'ok' => false, 'reason' => 'context_bank_owner_scope_denied' );
			}
			return array( 'ok' => true, 'scope' => 'channel_grant', 'channel' => $channel_scope['channel'], 'account_key' => $channel_scope['account_key'] );
		}
		return array( 'ok' => true, 'scope' => 'user' );
	}

	/* ── Runtime identity read (PHASE-0.60H D-H1, option A — user-approved 2026-09-24) ──────────────────────────
	 *
	 * Why: an unattended server runtime (the Bot Studio turn, which runs in WP-cron as user 0) must be able to
	 * read the memory of the ONE channel customer it is answering. Every other path here requires a logged-in
	 * WordPress owner, and a Zalo customer has none (wp_user_id 0), so without this the customer's memory is
	 * write-only.
	 *
	 * Boundaries — each is enforced, not just documented:
	 *  1. Opened only by server PHP via with_runtime_read(); nothing reads request input to open it.
	 *  2. Refused inside a REST request and whenever a user is logged in: a browser/REST caller can never hold
	 *     it, and a logged-in user keeps exactly their normal scope.
	 *  3. Exactly one identity_uuid (well-formed UUID) and one contract; filters asking for any other identity,
	 *     or for a WordPress user's rows (wp_user_id/user_id > 0), are refused.
	 *  4. Per pointer (authorize_pointer): same identity, same contract, record_kind 'memory'.
	 *  5. Scoped to the callable: popped in `finally`, so it cannot leak past the read even on an exception.
	 *  6. The CALLER decides whether the read is legitimate (Bot Studio checks an active bot binding for the
	 *     conversation first); `reason` is required for audit.
	 */

	/** @var array<int,array{identity_uuid:string,contract_id:string,reason:string}> */
	private static $runtime_grants = array();

	/**
	 * Run `$fn` with a read grant for one identity's records of one contract.
	 *
	 * @param array    $grant { identity_uuid, contract_id, reason }
	 * @param callable $fn
	 * @return mixed `$fn()`'s return, or null when the grant is refused.
	 */
	public static function with_runtime_read( array $grant, callable $fn ) {
		$normalized = self::normalize_runtime_grant( $grant );
		if ( null === $normalized ) {
			return null;
		}
		self::$runtime_grants[] = $normalized;
		try {
			return $fn();
		} finally {
			array_pop( self::$runtime_grants );
		}
	}

	/** @return array{identity_uuid:string,contract_id:string,reason:string}|null */
	private static function normalize_runtime_grant( array $grant ) {
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return null;
		}
		if ( function_exists( 'get_current_user_id' ) && (int) get_current_user_id() > 0 ) {
			return null;
		}
		$uuid     = strtolower( trim( (string) ( $grant['identity_uuid'] ?? '' ) ) );
		$contract = trim( (string) ( $grant['contract_id'] ?? '' ) );
		$reason   = sanitize_key( (string) ( $grant['reason'] ?? '' ) );
		if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid ) || '' === $contract || '' === $reason ) {
			return null;
		}
		return array( 'identity_uuid' => $uuid, 'contract_id' => $contract, 'reason' => $reason );
	}

	/** The innermost open grant, re-validated against the current request context, or null. */
	private static function active_runtime_grant() {
		if ( empty( self::$runtime_grants ) ) {
			return null;
		}
		$grant = end( self::$runtime_grants );
		return null === self::normalize_runtime_grant( $grant ) ? null : $grant;
	}

	/** scope_filters() branch for an open grant; null ⇒ no grant, fall through to the normal rules. */
	private static function runtime_scope_filters( array $filters ) {
		$grant = self::active_runtime_grant();
		if ( null === $grant ) {
			return null;
		}
		$asked = strtolower( trim( (string) ( $filters['identity_uuid'] ?? '' ) ) );
		if ( $asked !== $grant['identity_uuid'] ) {
			return array( 'ok' => false, 'reason' => 'context_bank_runtime_scope_denied' );
		}
		if ( (int) ( $filters['wp_user_id'] ?? 0 ) > 0 || (int) ( $filters['user_id'] ?? 0 ) > 0 ) {
			return array( 'ok' => false, 'reason' => 'context_bank_runtime_scope_denied' );
		}
		$filters['identity_uuid'] = $grant['identity_uuid'];
		unset( $filters['wp_user_id'], $filters['user_id'] );
		return array( 'ok' => true, 'filters' => $filters, 'scope' => 'runtime_identity' );
	}

	public static function can_read() {
		// [2026-09-01 Johnny Chu] PHASE-CB-MVP — require the authenticated WordPress read capability for non-admin Context Bank access.
		return function_exists( 'current_user_can' ) && current_user_can( self::READ_CAPABILITY );
	}

	public static function is_allowed_request() {
		// [2026-09-01 Johnny Chu] PHASE-CB-MVP — expose only authenticated owner/admin requests to the REST permission callback.
		return self::is_admin() || self::can_read();
	}

	public static function is_admin_request() {
		// [2026-09-01 Johnny Chu] PHASE-CB-MVP — keep destructive reconcile operations restricted to tenant administrators.
		return self::is_admin();
	}

	private static function is_admin() {
		// [2026-09-01 Johnny Chu] PHASE-CB-MVP — resolve administrative authority from the authenticated capability set.
		if ( function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ) ) {
			return true;
		}
		$trusted_cli_context = ( defined( 'BIZCITY_DIAGNOSTICS_CLI' ) && BIZCITY_DIAGNOSTICS_CLI )
			|| ( defined( 'WP_CLI' ) && WP_CLI );
		if ( $trusted_cli_context ) {
			// [2026-09-21 03:50 PM Johnny Chu - Chu Hoàng Anh] R-MSDB/R-DDV — permit explicit network administration authority for disposable pointer follow in trusted CLI runs when the mapped tenant omits site-scoped manage_options; web permission callbacks remain unchanged.
			return ( function_exists( 'current_user_can' ) && current_user_can( 'manage_network_options' ) )
				|| ( function_exists( 'is_super_admin' ) && is_super_admin() );
		}
		return false;
	}

	private static function channel_scope_from_filters( array $filters, $user_id ) {
		$entity_type = sanitize_key( (string) ( $filters['entity_type'] ?? '' ) );
		$entity_key = trim( (string) ( $filters['entity_key'] ?? '' ) );
		if ( $entity_type !== 'channel_account' && $entity_key === '' ) {
			return array( 'requested' => false );
		}
		if ( $entity_type !== 'channel_account' || ! preg_match( '/^([a-z0-9_]+):(a_[a-f0-9]{64})$/i', $entity_key, $matches ) ) {
			return array( 'requested' => true, 'ok' => false, 'reason' => 'context_bank_channel_scope_invalid' );
		}
		$authorized = self::authorize_channel_scope( strtolower( $matches[1] ), strtolower( $matches[2] ), $user_id );
		$authorized['requested'] = true;
		return $authorized;
	}

	private static function channel_scope_from_pointer( array $pointer, $user_id ) {
		if ( (string) ( $pointer['entity_type'] ?? '' ) !== 'channel_account' ) {
			return array( 'ok' => false, 'reason' => 'context_bank_owner_scope_denied' );
		}
		$entity_key = trim( (string) ( $pointer['entity_key'] ?? '' ) );
		if ( ! preg_match( '/^([a-z0-9_]+):(a_[a-f0-9]{64})$/i', $entity_key, $matches ) ) {
			return array( 'ok' => false, 'reason' => 'context_bank_channel_scope_invalid' );
		}
		return self::authorize_channel_scope( strtolower( $matches[1] ), strtolower( $matches[2] ), $user_id );
	}

	private static function authorize_channel_scope( $channel, $account_key, $user_id ) {
		// [2026-09-05 Johnny Chu - Chu Hoàng Anh] PHASE-1.33A — bridge exact Context Bank account scope to the Channel Gateway grant owner immediately before follow.
		if ( ! class_exists( 'BizCity_Channel_User_Grant' ) || ! method_exists( 'BizCity_Channel_User_Grant', 'authorize_account_key' ) ) {
			return array( 'ok' => false, 'reason' => 'channel_grant_owner_unavailable' );
		}
		$authorized = BizCity_Channel_User_Grant::authorize_account_key( $channel, $account_key, (int) $user_id, 'view_context' );
		if ( empty( $authorized['ok'] ) ) {
			return array( 'ok' => false, 'reason' => (string) ( $authorized['reason'] ?? 'context_bank_channel_scope_denied' ) );
		}
		return array( 'ok' => true, 'channel' => $channel, 'account_key' => $account_key, 'grant' => $authorized );
	}
}