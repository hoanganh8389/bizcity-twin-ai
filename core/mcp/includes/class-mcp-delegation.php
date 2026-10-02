<?php
/**
 * BizCity_MCP_Delegation — delegated identity for the zalo-hub cell (R-MCP-OAUTH-ID.6, D-MCP-7, Q88-4, Q88-6).
 *
 * The cell (central brain) calls this site's ONE MCP server on behalf of an owner/staff principal of a zalo-hub number.
 * First version (PHASE-0.88 waves 1–5): the Hub forwards the JSON-RPC body to the site route `zalo-bridge/mcp` with the
 * number's callback Bearer token; the site maps `X-BizCity-Principal` (user_hash) back to a WordPress user through its
 * OWN binding (BizCity_Zalo_Agent_Principals::by_hash) — never trusting a user id from the cell or the Hub.
 *
 * This class only builds the context and gates tools by mode:
 *  - scopes = scopes_of(modes) ∩ supported scopes (mirror of docs/contracts/BIZCITY-MCP-STANDARD-v1.json, see SCOPES_BY_MODE);
 *  - a delegated principal reaches a tool only when the tool's `_meta.bizcity.mode` is one of its modes ('*business' = any
 *    business mode); tools without a mode are never offered to delegated principals; the admin allowlist for external
 *    clients (BizCity_MCP_Tool_Policy) does NOT apply here (Q88-6);
 *  - notebook reads are limited to notebooks the user OWNS (R-TAA-14: notebooks first by user_id); an empty list means
 *    "none", never "all".
 *
 * Evidence: one JSONL line `tool_name=mcp.delegation` per delegated request with a reason bucket; no token, no raw UID,
 * no body — only hash prefixes.
 *
 * // @mcp bizcity-mcp-standard@1 delegation L3-1
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\MCP
 * @since      PHASE-0.88 (2026-10-01)
 */

defined( 'ABSPATH' ) || exit;

// [2026-10-01 Claude Opus 5.5] PHASE-0.88 L3-1 — new file, delegated (cell) identity + mode gate for core/mcp.
final class BizCity_MCP_Delegation {

	const AUTH_METHOD  = 'delegated';
	const CLIENT_ID    = 'zalo-cell';
	const CLIENT_NAME  = 'Zalo Agent';
	const ANY_BUSINESS = '*business';

	/**
	 * Mirror of BIZCITY-MCP-STANDARD-v1.json → modes.<mode>.scopes (bin/validate-mcp-standard.mjs compares them).
	 */
	const SCOPES_BY_MODE = array(
		'notebook'      => array( 'brain.read' ),
		'sales'         => array( 'business.read', 'report.read' ),
		'orders'        => array( 'order.read', 'commerce.read', 'order.write', 'inventory.write' ),
		'customers'     => array( 'crm.read', 'commerce.read', 'crm.write', 'staff.write' ),
		'stock'         => array( 'business.read', 'commerce.read' ),
		'astro_self'    => array(),
		'deep_analysis' => array(),
		'booking'       => array( 'booking.read', 'booking.write' ),
		'automation'    => array( 'automation.run' ),
	);

	/** Mirror of BIZCITY-MCP-STANDARD-v1.json → any_business_mode_scopes. */
	const ANY_BUSINESS_MODE_SCOPES = array( 'staff.notify' );

	/** Mirror of BIZCITY-MCP-STANDARD-v1.json → business_modes. */
	const BUSINESS_MODES = array( 'sales', 'orders', 'customers', 'stock', 'booking', 'automation' );

	/**
	 * Scopes introduced by PHASE-0.88 (write tools of CL-B and the CRM/booking reads). They are made known to the OAuth /
	 * admin key scope lists so external clients CAN be granted them; nothing grants them by default.
	 */
	const STANDARD_SCOPES = array( 'order.read', 'commerce.read', 'crm.read', 'crm.write', 'order.write', 'inventory.write', 'staff.write', 'staff.notify', 'booking.read', 'booking.write', 'automation.run' );

	/** Reasons of R-MCP-OAUTH-ID.6 §7 (first version). */
	const REASONS = array( 'delegation_ok', 'delegation_principal_unbound', 'delegation_no_scope', 'delegation_bad_token' );

	/**
	 * Test seams: notebooks(user_id): list<{id, owner_id}> · supported(): string[]|null (null = no intersection) ·
	 * log(entry): void.
	 *
	 * @var array<string,callable>
	 */
	public static $readers = array();

	/**
	 * scopes_of(modes): union of every known mode's scopes (+ staff.notify when any business mode is present).
	 *
	 * @param string[] $modes
	 * @return string[]
	 */
	public static function scopes_for( array $modes ) {
		$out      = array();
		$business = false;
		foreach ( $modes as $mode ) {
			$mode = (string) $mode;
			if ( isset( self::SCOPES_BY_MODE[ $mode ] ) ) {
				$out = array_merge( $out, self::SCOPES_BY_MODE[ $mode ] );
			}
			if ( in_array( $mode, self::BUSINESS_MODES, true ) ) {
				$business = true;
			}
		}
		if ( $business ) {
			$out = array_merge( $out, self::ANY_BUSINESS_MODE_SCOPES );
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * effective_scopes = scopes_of(modes) ∩ supported scopes (R-MCP-OAUTH-ID.6 §3). Supported = what this deploy's MCP
	 * waves expose (OAuth list) plus the PHASE-0.88 standard scopes.
	 *
	 * @param string[] $modes
	 * @return string[]
	 */
	public static function effective_scopes( array $modes ) {
		$want      = self::scopes_for( $modes );
		$supported = self::supported_scopes();
		return null === $supported ? $want : array_values( array_intersect( $want, $supported ) );
	}

	/** @return string[]|null */
	private static function supported_scopes() {
		if ( isset( self::$readers['supported'] ) ) {
			$s = call_user_func( self::$readers['supported'] );
			return null === $s ? null : array_values( array_map( 'strval', (array) $s ) );
		}
		if ( class_exists( 'BizCity_MCP_OAuth' ) && method_exists( 'BizCity_MCP_OAuth', 'supported_scope_list' ) ) {
			return BizCity_MCP_OAuth::supported_scope_list();
		}
		return null;
	}

	/**
	 * Auth context for one delegated request. `$principal` comes from BizCity_Zalo_Agent_Principals::by_hash() (active
	 * owner or staff of THIS number) — never from the request.
	 *
	 * @param array  $principal {role, user_id, user_hash, modes[]}
	 * @param string $account_id zalo-hub number (bridge id)
	 * @param string $turn_id    sanitized X-BizCity-Turn-Id
	 * @return array
	 */
	public static function context( array $principal, $account_id, $turn_id ) {
		$user_id = (int) ( $principal['user_id'] ?? 0 );
		$modes   = array_values( array_unique( array_filter( array_map( 'strval', (array) ( $principal['modes'] ?? array() ) ) ) ) );
		return array(
			'auth_method'          => self::AUTH_METHOD,
			'user_id'              => $user_id,
			'client_id'            => self::CLIENT_ID,
			'client_name'          => self::CLIENT_NAME,
			'key_id'               => 0,
			'scopes'               => self::effective_scopes( $modes ),
			'modes'                => $modes,
			'role'                 => (string) ( $principal['role'] ?? '' ),
			'user_hash'            => strtolower( (string) ( $principal['user_hash'] ?? '' ) ),
			'account_id'           => (string) $account_id,
			'turn_id'              => (string) $turn_id,
			'allowed_notebook_ids' => in_array( 'notebook', $modes, true ) ? self::owned_notebook_ids( $user_id ) : array(),
		);
	}

	/** True for a context built by context(). */
	public static function is_delegated( array $ctx ) {
		return self::AUTH_METHOD === (string) ( $ctx['auth_method'] ?? '' );
	}

	/**
	 * Q88-6 gate for tools/call: the tool's mode must be one of the principal's modes ('*business' = any business mode).
	 * A tool without a mode is never available to a delegated principal.
	 *
	 * @param array $tool registry descriptor (internal shape: mode, alias_of …)
	 */
	public static function tool_allowed( array $tool, array $ctx ) {
		$mode = isset( $tool['mode'] ) ? (string) $tool['mode'] : '';
		if ( '' === $mode ) {
			return false;
		}
		$modes = array_map( 'strval', (array) ( $ctx['modes'] ?? array() ) );
		if ( self::ANY_BUSINESS === $mode ) {
			return count( array_intersect( $modes, self::BUSINESS_MODES ) ) > 0;
		}
		return in_array( $mode, $modes, true );
	}

	/** tools/list for a delegated principal: allowed by mode AND not a deprecated alias (the cell uses canonical names). */
	public static function tool_listed( array $tool, array $ctx ) {
		return empty( $tool['alias_of'] ) && self::tool_allowed( $tool, $ctx );
	}

	/**
	 * Notebooks the user OWNS (owner_id = user_id). Shared/admin notebooks are not included: the cell reads the shop's
	 * Guru knowledge from its own cache (C-4).
	 *
	 * @return int[]
	 */
	public static function owned_notebook_ids( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return array();
		}
		if ( isset( self::$readers['notebooks'] ) ) {
			$rows = (array) call_user_func( self::$readers['notebooks'], $user_id );
		} elseif ( class_exists( 'BizCity_KG_Notebook_Service' ) ) {
			$rows = (array) BizCity_KG_Notebook_Service::instance()->list_for_user( $user_id, array( 'limit' => 500 ) );
		} else {
			$rows = array();
		}
		$ids = array();
		foreach ( $rows as $row ) {
			$row   = (array) $row; // rows may be arrays or stdClass
			$id    = (int) ( $row['id'] ?? 0 );
			$owner = (int) ( $row['owner_id'] ?? 0 );
			if ( $id > 0 && $owner === $user_id ) {
				$ids[] = $id;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * One JSONL evidence line per delegated request (R-MCP-OAUTH-ID.6 §7). Only hash prefixes, never a token, raw UID
	 * or body.
	 *
	 * @param string $reason one of REASONS
	 * @param array  $info   {account_id, user_hash, user_id, role, modes, scopes, turn_id, method}
	 */
	public static function log( $reason, array $info = array() ) {
		$reason = in_array( (string) $reason, self::REASONS, true ) ? (string) $reason : 'delegation_bad_token';
		$prefix = static function ( $value, $len ) {
			$value = (string) $value;
			return '' === $value ? '' : substr( hash( 'sha256', $value ), 0, $len );
		};
		$entry = array(
			'trace_id'    => class_exists( 'BizCity_MCP_Error' ) ? BizCity_MCP_Error::trace_id() : '',
			'blog_id'     => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0,
			'user_id'     => (int) ( $info['user_id'] ?? 0 ),
			'key_id'      => 0,
			'client_id'   => self::CLIENT_ID,
			'client_name' => self::CLIENT_NAME,
			'tool_name'   => 'mcp.delegation',
			'status'      => 'delegation_ok' === $reason ? 'success' : 'error',
			'error_code'  => 'delegation_ok' === $reason ? '' : $reason,
			'duration_ms' => 0,
			'evaluation'  => array(
				'reason'           => $reason,
				'account_hash'     => $prefix( $info['account_id'] ?? '', 12 ),
				'principal_prefix' => substr( strtolower( (string) ( $info['user_hash'] ?? '' ) ), 0, 8 ),
				'role'             => (string) ( $info['role'] ?? '' ),
				'modes'            => array_values( array_map( 'strval', (array) ( $info['modes'] ?? array() ) ) ),
				'scope_count'      => count( (array) ( $info['scopes'] ?? array() ) ),
				'turn_hash'        => $prefix( $info['turn_id'] ?? '', 12 ),
				'method'           => preg_replace( '/[^a-z\/_]/', '', strtolower( (string) ( $info['method'] ?? '' ) ) ),
			),
		);
		if ( isset( self::$readers['log'] ) ) {
			call_user_func( self::$readers['log'], $entry );
			return;
		}
		if ( class_exists( 'BizCity_MCP_File_Logger' ) ) {
			BizCity_MCP_File_Logger::write( $entry );
		}
	}
}
