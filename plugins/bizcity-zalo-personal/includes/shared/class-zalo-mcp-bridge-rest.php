<?php
/**
 * BizCity Zalo Personal — MCP bridge of a zalo-hub number (PHASE-0.88 L3-1, seam B of bizcity-mcp-bridge@1.0.0).
 *
 *   POST bizcity-channel/v1/zalo-bridge/mcp?account_id=   body = one JSON-RPC object (initialize · tools/list · tools/call)
 *
 * Hub → site only: the same per-number callback Bearer as /inbound, packs, owner-capture and turn-complete (Q88-4). The
 * Hub is a pipe; it forwards the cell's JSON-RPC body and two headers unchanged:
 *   X-BizCity-Principal  user_hash (64 hex) of the owner/staff who is talking to the Agent;
 *   X-BizCity-Turn-Id    the cell's turn id (evidence only).
 * The site maps the hash through ITS OWN binding (BizCity_Zalo_Agent_Principals::by_hash — active owner or staff of this
 * number only; R-MCP-OAUTH-ID.6 §1), runs as that WordPress user and dispatches to core/mcp with a delegated context
 * (BizCity_MCP_Delegation). No MCP session (stateless POST, Q88-6); tools are gated by mode, not by the admin allowlist.
 *
 * Refusals (fixture zalo-hub/contracts/fixtures/mcp/bridge.denied.json): HTTP 401 + JSON-RPC error -32000 with data.mcp_code
 * MCP_AUTH_INVALID (bad token) · MCP_DELEGATION_PRINCIPAL_UNBOUND · MCP_SCOPE_DENIED (no mode maps to a scope). Each request
 * writes one JSONL line tool_name=mcp.delegation. Never logs the token, the raw UID or the body.
 *
 * // @mcp bizcity-mcp-standard@1 seam site-bridge
 *
 * @package BizCity_Zalo_Personal
 * @since   PHASE-0.88 (2026-10-01)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Zalo_MCP_Bridge_REST', false ) ) {
	return;
}

// [2026-10-01 Claude Opus 5.5] PHASE-0.88 L3-1 — new file, site end of the cell → Hub → site MCP pipe.
final class BizCity_Zalo_MCP_Bridge_REST {

	const NS              = 'bizcity-channel/v1';
	const ROUTE           = '/zalo-bridge/mcp';
	const PRINCIPAL_HDR   = 'x-bizcity-principal';
	const TURN_HDR        = 'x-bizcity-turn-id';
	const TURN_MAX        = 120;

	/**
	 * Test seams: token(account_id): string · principal(account_id, hash): ?array.
	 *
	 * @var array<string,callable>
	 */
	public static $readers = array();

	private static $initialized = false;

	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route( self::NS, self::ROUTE, array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'handle' ),
			'permission_callback' => '__return_true', // Bearer verified in handler, same boundary as /inbound and packs.
		) );
	}

	public static function handle( WP_REST_Request $request ): WP_REST_Response {
		$account = sanitize_text_field( (string) $request->get_param( 'account_id' ) );
		$turn    = self::turn_id( (string) $request->get_header( self::TURN_HDR ) );
		if ( ! class_exists( 'BizCity_MCP_HTTP_Controller' ) || ! class_exists( 'BizCity_MCP_Delegation' ) || ! class_exists( 'BizCity_MCP_Error' ) ) {
			// core/mcp disabled or not loaded on this request: the Hub answers site_mcp_unsupported / the cell uses its packs.
			return new WP_REST_Response( array( 'jsonrpc' => '2.0', 'error' => array( 'code' => -32000, 'message' => 'MCP chưa bật trên site này.' ), 'id' => null ), 503 );
		}
		if ( ! self::authorized( $request, $account ) ) {
			BizCity_MCP_Delegation::log( 'delegation_bad_token', array( 'account_id' => $account, 'turn_id' => $turn ) );
			return self::refuse( BizCity_MCP_Error::AUTH_INVALID, 'Token callback của số không hợp lệ.' );
		}
		$hash      = strtolower( trim( (string) $request->get_header( self::PRINCIPAL_HDR ) ) );
		$principal = preg_match( '/^[0-9a-f]{64}$/', $hash ) ? self::principal( $account, $hash ) : null;
		if ( null === $principal || ! in_array( (string) ( $principal['role'] ?? '' ), array( 'owner', 'staff' ), true ) || (int) ( $principal['user_id'] ?? 0 ) <= 0 ) {
			BizCity_MCP_Delegation::log( 'delegation_principal_unbound', array( 'account_id' => $account, 'user_hash' => $hash, 'turn_id' => $turn ) );
			return self::refuse( BizCity_MCP_Error::DELEGATION_PRINCIPAL_UNBOUND, 'Người này không thuộc danh sách dùng Agent của số.' );
		}
		$ctx  = BizCity_MCP_Delegation::context( $principal, $account, $turn );
		$info = array( 'account_id' => $account, 'user_hash' => $hash, 'user_id' => $ctx['user_id'], 'role' => $ctx['role'], 'modes' => $ctx['modes'], 'scopes' => $ctx['scopes'], 'turn_id' => $turn );
		if ( empty( $ctx['scopes'] ) ) {
			BizCity_MCP_Delegation::log( 'delegation_no_scope', $info );
			return self::refuse( BizCity_MCP_Error::SCOPE_DENIED, 'Người này chưa có mục nào được dùng qua Agent.' );
		}
		$body = BizCity_MCP_HTTP_Controller::decode_body( (string) $request->get_body() );
		if ( null === $body ) {
			return BizCity_MCP_HTTP_Controller::parse_error_response();
		}
		BizCity_MCP_Delegation::log( 'delegation_ok', $info + array( 'method' => (string) ( $body['method'] ?? '' ) ) );

		// Run as the bound WordPress user so CRM permissions apply naturally (AMA-1); restore whoever was current before.
		$previous = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( function_exists( 'wp_set_current_user' ) ) {
			wp_set_current_user( (int) $ctx['user_id'] );
		}
		try {
			$response = BizCity_MCP_HTTP_Controller::dispatch( $body, $ctx, '' );
		} finally {
			if ( function_exists( 'wp_set_current_user' ) ) {
				wp_set_current_user( $previous );
			}
		}
		return $response;
	}

	/** HTTP 401 + JSON-RPC error like core/mcp auth errors (bridge.denied.json). */
	private static function refuse( string $code, string $message ): WP_REST_Response {
		$env = BizCity_MCP_Error::fail( 'mcp.transport', $code, $message );
		return new WP_REST_Response( array(
			'jsonrpc' => '2.0',
			'error'   => array(
				'code'    => -32000,
				'message' => (string) $env['message'],
				'data'    => array( 'mcp_code' => (string) $env['code'], 'hint' => (string) $env['hint'], 'help_code' => (string) $env['help_code'] ),
			),
			'id'      => null,
		), 401 );
	}

	private static function authorized( WP_REST_Request $request, string $account ): bool {
		if ( '' === $account ) {
			return false;
		}
		$token  = isset( self::$readers['token'] ) ? (string) call_user_func( self::$readers['token'], $account ) : ( class_exists( 'BizCity_Zalo_Bridge_Client' ) ? BizCity_Zalo_Bridge_Client::instance()->expected_inbound_token( $account ) : '' );
		$header = (string) $request->get_header( 'authorization' );
		$bearer = stripos( $header, 'Bearer ' ) === 0 ? trim( substr( $header, 7 ) ) : '';
		return '' !== $token && '' !== $bearer && hash_equals( $token, $bearer );
	}

	/** Active owner/staff principal of THIS number with that hash, or null (never trusted from the request). */
	private static function principal( string $account, string $hash ): ?array {
		if ( isset( self::$readers['principal'] ) ) {
			$p = call_user_func( self::$readers['principal'], $account, $hash );
			return is_array( $p ) ? $p : null;
		}
		return class_exists( 'BizCity_Zalo_Agent_Principals' ) ? BizCity_Zalo_Agent_Principals::by_hash( $account, $hash ) : null;
	}

	private static function turn_id( string $raw ): string {
		return substr( (string) preg_replace( '/[^A-Za-z0-9_.:\-]/', '', $raw ), 0, self::TURN_MAX );
	}
}
