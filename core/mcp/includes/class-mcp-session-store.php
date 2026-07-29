<?php
/**
 * Small persistent session/event store for MCP Streamable HTTP transport.
 * Stores only client identity and protocol events, never Bearer credentials.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\MCP
 */

defined( 'ABSPATH' ) || exit;

// [2026-07-28 Johnny Chu] PHASE-0.53-MCP — bounded session store for authenticated SSE transport.
final class BizCity_MCP_Session_Store {

	const TTL = 1800;
	const MAX_EVENTS = 50;

	public static function create( array $auth_ctx ) {
		// [2026-07-28 Johnny Chu] PHASE-0.53-MCP — session identity is non-secret and bound to the authenticated client/user.
		$id = 'mcp_s_' . str_replace( '-', '', wp_generate_uuid4() );
		$session = array(
			'session_id' => $id,
			'client_id'  => (string) ( isset( $auth_ctx['client_id'] ) ? $auth_ctx['client_id'] : '' ),
			'user_id'    => (int) ( isset( $auth_ctx['user_id'] ) ? $auth_ctx['user_id'] : 0 ),
			'next_event' => 1,
			'events'     => array(),
			'created_at' => time(),
			'expires_at' => time() + self::TTL,
		);
		self::write( $id, $session );
		return $id;
	}

	public static function assert_owned( $session_id, array $auth_ctx ) {
		$session = self::read( $session_id );
		if ( ! $session ) {
			return new WP_Error( BizCity_MCP_Error::AUTH_INVALID, 'MCP session không tồn tại hoặc đã hết hạn.', array( 'status' => 401 ) );
		}
		if ( (string) $session['client_id'] !== (string) ( isset( $auth_ctx['client_id'] ) ? $auth_ctx['client_id'] : '' ) || (int) $session['user_id'] !== (int) ( isset( $auth_ctx['user_id'] ) ? $auth_ctx['user_id'] : 0 ) ) {
			return new WP_Error( BizCity_MCP_Error::AUTH_INVALID, 'MCP session không thuộc client hiện tại.', array( 'status' => 401 ) );
		}
		return $session;
	}

	public static function append( $session_id, $event_type, array $data ) {
		$session = self::read( $session_id );
		if ( ! $session ) {
			return false;
		}
		$session['events'][] = array(
			'id'   => (int) $session['next_event']++,
			'event'=> sanitize_key( $event_type ),
			'data' => $data,
		);
		if ( count( $session['events'] ) > self::MAX_EVENTS ) {
			$session['events'] = array_slice( $session['events'], -self::MAX_EVENTS );
		}
		self::write( $session_id, $session );
		return true;
	}

	public static function events_after( $session_id, $last_event_id = 0 ) {
		$session = self::read( $session_id );
		if ( ! $session ) {
			return array();
		}
		$out = array();
		foreach ( (array) $session['events'] as $event ) {
			if ( (int) $event['id'] > (int) $last_event_id ) {
				$out[] = $event;
			}
		}
		return $out;
	}

	public static function delete( $session_id ) {
		// [2026-07-28 Johnny Chu] PHASE-0.53-MCP — allow authenticated transport shutdown to release session state.
		if ( ! is_string( $session_id ) || ! preg_match( '/^mcp_s_[a-f0-9]{32}$/', $session_id ) ) {
			return false;
		}
		return delete_transient( self::cache_key( $session_id ) );
	}

	private static function cache_key( $session_id ) {
		return 'bizcity_mcp_session_' . substr( hash( 'sha256', (string) $session_id ), 0, 48 );
	}

	private static function read( $session_id ) {
		if ( ! is_string( $session_id ) || ! preg_match( '/^mcp_s_[a-f0-9]{32}$/', $session_id ) ) {
			return false;
		}
		$session = get_transient( self::cache_key( $session_id ) );
		if ( ! is_array( $session ) || empty( $session['expires_at'] ) || (int) $session['expires_at'] <= time() ) {
			return false;
		}
		return $session;
	}

	private static function write( $session_id, array $session ) {
		set_transient( self::cache_key( $session_id ), $session, self::TTL );
	}
}
