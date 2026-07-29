<?php
/**
 * BizCity_MCP_HTTP_Controller — Streamable-HTTP-style REST transport for
 * the MCP gateway.
 *
 * Namespace `bizcity-mcp/v1` (deliberately NOT `bizcity-channel/v1`: MCP is
 * not a "channel" per R-CH-NS, and NOT `bizcity/v1`: that namespace is
 * reserved for bizcity-llm-router on the Hub — R-GW-8 forbids a client
 * plugin from colliding with it).
 *
 * Transport: POST accepts a JSON-RPC 2.0 envelope for `initialize`,
 * `tools/list`, and `tools/call`; authenticated GET supports bounded SSE event
 * replay for an MCP session. Stateless POST remains supported for compatibility.
 *
 * Auth: Bearer <mcp-api-key> handled inside handle() via BizCity_MCP_Auth,
 * NOT via WP REST's permission_callback, so that malformed/missing auth
 * produces a JSON-RPC error body instead of WordPress's generic REST 401.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\MCP
 * @since      2026-07-27 (PHASE-0.53-MCP Wave A)
 */

defined( 'ABSPATH' ) || exit;

// [2026-07-27 Johnny Chu] PHASE-0.53-MCP — new file, MCP HTTP transport (JSON-RPC over REST).
final class BizCity_MCP_HTTP_Controller {

	const NS = 'bizcity-mcp/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		// [2026-07-28 Johnny Chu] PHASE-0.53-MCP-OAUTH — serve /mcp directly before REST dispatch when global POST guards are active.
		add_action( 'parse_request', array( __CLASS__, 'serve_transport_direct' ), 0 );
		add_filter( 'rest_pre_serve_request', array( __CLASS__, 'serve_sse' ), 10, 4 );
	}

	public static function serve_transport_direct( $wp ) {
		// [2026-07-28 Johnny Chu] PHASE-0.53-MCP-OAUTH — parse_request fallback keeps MCP JSON-RPC reachable without weakening module auth checks.
		unset( $wp );
		$path = isset( $_SERVER['REQUEST_URI'] ) ? (string) parse_url( (string) $_SERVER['REQUEST_URI'], PHP_URL_PATH ) : '';
		$path = '/' . trim( $path, '/' );
		$mcp_path = '/wp-json/' . self::NS . '/mcp';
		$rest_route_q = isset( $_GET['rest_route'] ) ? (string) $_GET['rest_route'] : '';
		$rest_route = $rest_route_q !== '' ? '/' . ltrim( rawurldecode( $rest_route_q ), '/' ) : '';
		if ( $path !== $mcp_path && $rest_route !== '/' . self::NS . '/mcp' ) {
			return;
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET';
		if ( $method !== 'POST' && $method !== 'GET' && $method !== 'DELETE' ) {
			return;
		}

		$request = new WP_REST_Request( $method, '/' . self::NS . '/mcp' );
		$raw_body = file_get_contents( 'php://input' );
		$request->set_body( is_string( $raw_body ) ? $raw_body : '' );

		if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$request->set_header( 'authorization', (string) $_SERVER['HTTP_AUTHORIZATION'] );
		}
		if ( isset( $_SERVER['HTTP_MCP_SESSION_ID'] ) ) {
			$request->set_header( 'mcp-session-id', (string) $_SERVER['HTTP_MCP_SESSION_ID'] );
		}
		if ( isset( $_SERVER['HTTP_LAST_EVENT_ID'] ) ) {
			$request->set_header( 'last-event-id', (string) $_SERVER['HTTP_LAST_EVENT_ID'] );
		}
		if ( isset( $_SERVER['HTTP_ACCEPT'] ) ) {
			$request->set_header( 'accept', (string) $_SERVER['HTTP_ACCEPT'] );
		}
		if ( isset( $_SERVER['CONTENT_TYPE'] ) ) {
			$request->set_header( 'content-type', (string) $_SERVER['CONTENT_TYPE'] );
		}

		if ( $method === 'POST' ) {
			$response = self::handle( $request );
		} elseif ( $method === 'GET' ) {
			$response = self::handle_get( $request );
		} else {
			$response = self::handle_delete( $request );
		}

		self::emit_direct_response( $response );
	}

	private static function emit_direct_response( $response ) {
		// [2026-07-28 Johnny Chu] PHASE-0.53-MCP-OAUTH — emit a plain HTTP response from parse_request fallback while preserving MCP response headers/status.
		if ( is_wp_error( $response ) ) {
			$data = $response->get_error_data();
			$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 500;
			if ( $status <= 0 ) {
				$status = 500;
			}
			$response = new WP_REST_Response( array(
				'code'    => (string) $response->get_error_code(),
				'message' => (string) $response->get_error_message(),
			), $status );
		}
		if ( ! $response instanceof WP_REST_Response ) {
			$response = new WP_REST_Response( $response, 200 );
		}

		nocache_headers();
		$status = (int) $response->get_status();
		if ( $status > 0 ) {
			status_header( $status );
		}

		$headers = $response->get_headers();
		if ( empty( $headers['Content-Type'] ) ) {
			header( 'Content-Type: application/json; charset=utf-8' );
		}
		foreach ( $headers as $name => $value ) {
			header( $name . ': ' . $value );
		}

		$body = $response->get_data();
		echo is_string( $body ) ? $body : wp_json_encode( $body );
		exit;
	}

	public static function serve_sse( $served, $result, $request, $server ) {
		// [2026-07-28 Johnny Chu] PHASE-0.53-MCP — preserve raw SSE framing for the authenticated session stream.
		if ( ! $result instanceof WP_REST_Response ) {
			return $served;
		}
		$headers = $result->get_headers();
		$content_type = isset( $headers['Content-Type'] ) ? (string) $headers['Content-Type'] : '';
		if ( stripos( $content_type, 'text/event-stream' ) === false ) {
			return $served;
		}
		foreach ( $headers as $name => $value ) {
			header( $name . ': ' . $value );
		}
		$status = (int) $result->get_status();
		if ( $status >= 400 ) {
			status_header( $status );
		}
		echo (string) $result->get_data();
		return true;
	}

	public static function register_routes() {
		register_rest_route( self::NS, '/mcp', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'handle' ),
			'permission_callback' => '__return_true', // Auth is Bearer-token based, enforced inside handle().
		) );
		register_rest_route( self::NS, '/mcp', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'handle_get' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( self::NS, '/mcp', array(
			'methods'             => 'DELETE',
			'callback'            => array( __CLASS__, 'handle_delete' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( self::NS, '/health', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'health' ),
			'permission_callback' => '__return_true',
		) );
	}

	public static function health( WP_REST_Request $request ) {
		return new WP_REST_Response( array(
			'status'  => 'ok',
			'service' => 'bizcity-mcp',
			'version' => '1.0.0',
		), 200 );
	}

	/**
	 * GET discovery endpoint or an authenticated Streamable HTTP event stream.
	 */
	public static function handle_get( WP_REST_Request $request ) {
		// [2026-07-28 Johnny Chu] PHASE-0.53-MCP — authenticate every GET before reading a session or emitting events.
		// [2026-07-28 Johnny Chu] PHASE-0.53-MCP-OAUTH — resolve native API key or OAuth Bearer token at the transport boundary.
		$auth_ctx = BizCity_MCP_Auth::authenticate_request( $request );
		if ( is_wp_error( $auth_ctx ) ) {
			return self::auth_error_response( $auth_ctx );
		}
		$rate = BizCity_MCP_Auth::check_rate_limit( $auth_ctx );
		if ( is_wp_error( $rate ) ) {
			return self::auth_error_response( $rate );
		}
		$session_id = (string) $request->get_header( 'mcp-session-id' );
		if ( $session_id !== '' ) {
			$owned = BizCity_MCP_Session_Store::assert_owned( $session_id, $auth_ctx );
			if ( is_wp_error( $owned ) ) {
				return self::auth_error_response( $owned );
			}
			$last_event_id = (int) $request->get_header( 'last-event-id' );
			$events = BizCity_MCP_Session_Store::events_after( $session_id, $last_event_id );
			$body = "retry: 15000\n\n";
			foreach ( $events as $event ) {
				$body .= 'id: ' . (int) $event['id'] . "\n";
				$body .= 'event: ' . sanitize_key( $event['event'] ) . "\n";
				$body .= 'data: ' . wp_json_encode( $event['data'] ) . "\n\n";
			}
			$response = new WP_REST_Response( $body, 200 );
			$response->header( 'Content-Type', 'text/event-stream; charset=utf-8' );
			$response->header( 'Cache-Control', 'no-cache, no-store, must-revalidate' );
			$response->header( 'X-Accel-Buffering', 'no' );
			$response->header( 'MCP-Session-Id', $session_id );
			return $response;
		}
		// [2026-07-28 Johnny Chu] PHASE-0.53-MCP — Streamable HTTP discovery is POST initialize/tools-list; GET without a session is not a custom manifest endpoint.
		$response = new WP_REST_Response( null, 405 );
		$response->header( 'Allow', 'POST, GET, DELETE' );
		return $response;
	}

	public static function handle( WP_REST_Request $request ) {
		// [2026-07-28 Johnny Chu] PHASE-0.53-MCP — enforce auth/rate-limit/session ownership before JSON-RPC dispatch.
		// [2026-07-28 Johnny Chu] PHASE-0.53-MCP-OAUTH — resolve native API key or OAuth Bearer token at the transport boundary.
		$auth_ctx = BizCity_MCP_Auth::authenticate_request( $request );
		if ( is_wp_error( $auth_ctx ) ) {
			return self::auth_error_response( $auth_ctx );
		}
		$rate = BizCity_MCP_Auth::check_rate_limit( $auth_ctx );
		if ( is_wp_error( $rate ) ) {
			return self::auth_error_response( $rate );
		}
		$session_id = (string) $request->get_header( 'mcp-session-id' );
		if ( $session_id !== '' ) {
			$owned = BizCity_MCP_Session_Store::assert_owned( $session_id, $auth_ctx );
			if ( is_wp_error( $owned ) ) {
				return self::auth_error_response( $owned );
			}
		}

		$body = json_decode( (string) $request->get_body(), true );
		if ( ! is_array( $body ) || isset( $body[0] ) ) {
			return new WP_REST_Response( array(
				'jsonrpc' => '2.0',
				'error'   => array( 'code' => -32700, 'message' => 'Parse error: request body không phải JSON object hợp lệ.' ),
				'id'      => null,
			), 400 );
		}
		if ( ! isset( $body['jsonrpc'] ) || $body['jsonrpc'] !== '2.0' ) {
			return self::jsonrpc_error_response( isset( $body['id'] ) ? $body['id'] : null, -32600, 'Request phải dùng jsonrpc=2.0.' );
		}

		$method = isset( $body['method'] ) ? (string) $body['method'] : '';
		$id     = isset( $body['id'] ) ? $body['id'] : null;
		if ( isset( $body['params'] ) && ! is_array( $body['params'] ) ) {
			return self::jsonrpc_error_response( $id, -32602, 'params phải là JSON object.' );
		}
		$params = isset( $body['params'] ) ? $body['params'] : array();
		if ( $method === '' ) {
			return self::jsonrpc_error_response( $id, -32600, 'Request thiếu method.' );
		}

		// [2026-07-28 Johnny Chu] PHASE-0.53-MCP — accept MCP lifecycle notifications without returning a JSON-RPC error response.
		if ( ! array_key_exists( 'id', $body ) ) {
			return new WP_REST_Response( null, 202 );
		}

		if ( $method === 'initialize' ) {
			// [2026-07-28 Johnny Chu] PHASE-0.53-MCP — create a resumable session only after Bearer auth succeeds.
			$session_id = $session_id !== '' ? $session_id : BizCity_MCP_Session_Store::create( $auth_ctx );
			$result = array(
				'protocolVersion' => '2025-06-18',
				'serverInfo'      => array( 'name' => 'bizcity-twin-brain-mcp', 'version' => '1.0.0' ),
				'capabilities'    => array( 'tools' => array( 'listChanged' => false ) ),
			);
		} elseif ( $method === 'tools/list' ) {
			$result = array( 'tools' => BizCity_MCP_Tool_Registry::list_descriptors() );
		} elseif ( $method === 'tools/call' ) {
			$tool_name = isset( $params['name'] ) ? (string) $params['name'] : '';
			$tool_args = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();
			if ( $tool_name === '' ) {
				return self::jsonrpc_error_response( $id, -32602, 'Thiếu params.name.' );
			}
			$envelope = BizCity_MCP_Tool_Registry::call( $tool_name, $tool_args, $auth_ctx );
			$result   = array(
				'content'           => array(
					array( 'type' => 'text', 'text' => wp_json_encode( $envelope ) ),
				),
				'isError'           => empty( $envelope['success'] ),
				'structuredContent' => $envelope,
			);
		} else {
			return self::jsonrpc_error_response( $id, -32601, sprintf( 'Method không hỗ trợ: %s', $method ) );
		}

		if ( $session_id !== '' ) {
			BizCity_MCP_Session_Store::append( $session_id, 'message', array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'result'  => $result,
			) );
		}

		$response = new WP_REST_Response( array(
			'jsonrpc' => '2.0',
			'result'  => $result,
			'id'      => $id,
		), 200 );
		if ( $session_id !== '' ) {
			$response->header( 'MCP-Session-Id', $session_id );
		}
		return $response;
	}

	public static function handle_delete( WP_REST_Request $request ) {
		// [2026-07-28 Johnny Chu] PHASE-0.53-MCP — authenticate and own-check before deleting a session transient.
		// [2026-07-28 Johnny Chu] PHASE-0.53-MCP-OAUTH — resolve native API key or OAuth Bearer token at the transport boundary.
		$auth_ctx = BizCity_MCP_Auth::authenticate_request( $request );
		if ( is_wp_error( $auth_ctx ) ) {
			return self::auth_error_response( $auth_ctx );
		}
		$rate = BizCity_MCP_Auth::check_rate_limit( $auth_ctx );
		if ( is_wp_error( $rate ) ) {
			return self::auth_error_response( $rate );
		}
		$session_id = (string) $request->get_header( 'mcp-session-id' );
		if ( $session_id === '' ) {
			return self::auth_error_response( new WP_Error( BizCity_MCP_Error::AUTH_INVALID, 'Thiếu MCP-Session-Id.', array( 'status' => 400 ) ) );
		}
		$owned = BizCity_MCP_Session_Store::assert_owned( $session_id, $auth_ctx );
		if ( is_wp_error( $owned ) ) {
			return self::auth_error_response( $owned );
		}
		BizCity_MCP_Session_Store::delete( $session_id );
		return new WP_REST_Response( null, 204 );
	}

	private static function jsonrpc_error_response( $id, $code, $message ) {
		// JSON-RPC error objects travel inside a 200 HTTP response per the
		// JSON-RPC 2.0 spec; only transport-level failures (parse error,
		// auth) use a non-200 HTTP status.
		return new WP_REST_Response( array(
			'jsonrpc' => '2.0',
			'error'   => array( 'code' => $code, 'message' => $message ),
			'id'      => $id,
		), 200 );
	}

	private static function auth_error_response( WP_Error $err ) {
		$data   = $err->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 401;
		$envelope = BizCity_MCP_Error::from_wp_error( 'mcp.transport', $err );
		$response = new WP_REST_Response( array(
			'jsonrpc' => '2.0',
			'error'   => array(
				'code'    => -32000,
				'message' => $envelope['message'],
				'data'    => array(
					'mcp_code'  => $envelope['error']['code'],
					'hint'      => $envelope['hint'],
					'help_code' => $envelope['help_code'],
				),
			),
			'id'      => null,
		), $status );
		if ( $status === 401 && class_exists( 'BizCity_MCP_OAuth' ) ) {
			// [2026-07-28 Johnny Chu] PHASE-0.53-MCP-OAUTH — tell OAuth-only MCP hosts where protected-resource metadata lives.
			$response->header( 'WWW-Authenticate', 'Bearer resource_metadata="' . esc_url_raw( BizCity_MCP_OAuth::protected_resource_metadata_url() ) . '"' );
		}
		return $response;
	}
}
