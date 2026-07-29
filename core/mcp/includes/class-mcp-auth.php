<?php
/**
 * BizCity_MCP_Auth — Bearer API key authentication for the MCP gateway.
 *
 * Deliberately NOT reusing BizCity_LLM_Client credentials (R-1API-AUTH is
 * about the Twin Client -> bizcity.vn Hub boundary; MCP is the reverse
 * direction — an external LLM client calling INTO this Twin Client). MCP
 * keys are minted locally (bizcity_mcp_api_keys) and never touch the Hub.
 *
 * Fail-closed: missing/invalid/revoked key -> WP_Error, never a silent
 * fallback to "no restriction" or blog_id=1 (same spirit as R-MSDB.2,
 * applied here at the credential layer instead of the DB-shard layer).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\MCP
 * @since      2026-07-27 (PHASE-0.53-MCP Wave A)
 */

defined( 'ABSPATH' ) || exit;

// [2026-07-27 Johnny Chu] PHASE-0.53-MCP — new file, Bearer auth for MCP gateway.
final class BizCity_MCP_Auth {

	const RATE_LIMIT_GROUP = 'bizcity_mcp_rate';
	const RATE_LIMIT_WINDOW = 60;
	const RATE_LIMIT_MAX = 60;

	public static function tbl() {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_mcp_api_keys';
	}

	/**
	 * @param WP_REST_Request $request
	 * @return array|WP_Error { client_id, client_name, user_id, scopes[], allowed_notebook_ids[] }
	 */
	public static function authenticate( WP_REST_Request $request ) {
		// [2026-07-28 Johnny Chu] PHASE-0.53-MCP-OAUTH — accept static MCP keys and OAuth access tokens through one fail-closed boundary.
		return self::authenticate_request( $request );
	}

	/**
	 * Resolve the inbound MCP Bearer credential.
	 *
	 * @param WP_REST_Request $request MCP transport request.
	 * @return array|WP_Error
	 */
	public static function authenticate_request( WP_REST_Request $request ) {
		$header = (string) $request->get_header( 'authorization' );
		if ( $header === '' || stripos( $header, 'bearer ' ) !== 0 ) {
			return new WP_Error(
				BizCity_MCP_Error::AUTH_REQUIRED,
				'Thiếu Authorization: Bearer <mcp-api-key>.',
				array( 'status' => 401 )
			);
		}
		$token = trim( substr( $header, 7 ) );
		if ( $token === '' ) {
			return new WP_Error( BizCity_MCP_Error::AUTH_REQUIRED, 'Bearer token rỗng.', array( 'status' => 401 ) );
		}

		// [2026-07-28 Johnny Chu] PHASE-0.53-MCP-OAUTH — try the native key first, then resolve a short-lived OAuth token to its key policy.
		$native = self::find_active_key_by_token( $token );
		if ( $native ) {
			return self::context_from_row( $native );
		}
		if ( class_exists( 'BizCity_MCP_OAuth' ) ) {
			$oauth_ctx = BizCity_MCP_OAuth::authenticate_token( $token );
			if ( is_array( $oauth_ctx ) && ! empty( $oauth_ctx['key_id'] ) ) {
				$oauth_row = self::find_active_key_by_id( (int) $oauth_ctx['key_id'] );
				if ( $oauth_row && (int) $oauth_row['user_id'] === (int) $oauth_ctx['user_id'] ) {
					$context = self::context_from_row( $oauth_row );
					// [2026-07-29 Johnny Chu] PHASE-0.54-MCP — OAuth grant scopes are the runtime authority for OAuth tokens.
					// The MCP key row is kept as ownership/auth evidence only; intersecting again with historical key scopes
					// can silently downgrade newly approved scopes and break OAuth connector handshakes.
					$context['scopes'] = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', (array) ( $oauth_ctx['scopes'] ?? array() ) ) ) ) );
					if ( empty( $context['scopes'] ) ) {
						return new WP_Error(
							BizCity_MCP_Error::AUTH_INVALID,
							'OAuth token không chứa scope hợp lệ.',
							array( 'status' => 401 )
						);
					}
					$context['client_id'] = (string) ( $oauth_ctx['client_id'] ?? $context['client_id'] );
					$context['client_name'] = (string) ( $oauth_ctx['client_name'] ?? $context['client_name'] );
					$context['oauth_client_id'] = (string) ( $oauth_ctx['oauth_client_id'] ?? '' );
					$context['auth_method'] = 'oauth';
					return $context;
				}
			}
		}

		return new WP_Error(
			BizCity_MCP_Error::AUTH_INVALID,
			'API key hoặc OAuth token không hợp lệ hoặc đã bị thu hồi.',
			array( 'status' => 401 )
		);
	}

	/**
	 * Return the newest active MCP key owned by a user for OAuth policy binding.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return array|false
	 */
	public static function get_active_key_for_user( $user_id ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM " . self::tbl() . " WHERE user_id = %d AND status = 'active' ORDER BY id DESC LIMIT 1",
				(int) $user_id
			),
			ARRAY_A
		);
		return $row ? $row : false;
	}

	private static function find_active_key_by_token( $token ) {
		global $wpdb;
		$hash = hash( 'sha256', (string) $token );
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM " . self::tbl() . " WHERE key_hash = %s AND status = 'active' LIMIT 1",
				$hash
			),
			ARRAY_A
		);
	}

	private static function find_active_key_by_id( $key_id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM " . self::tbl() . " WHERE id = %d AND status = 'active' LIMIT 1",
				(int) $key_id
			),
			ARRAY_A
		);
	}

	public static function context_from_row( array $row ) {
		// [2026-07-28 Johnny Chu] PHASE-0.53-MCP-OAUTH — rebuild policy from the current active key row so revocation and policy changes take effect immediately.
		global $wpdb;
		$wpdb->update( self::tbl(), array( 'last_used_at' => current_time( 'mysql' ) ), array( 'id' => (int) $row['id'] ) );

		return array(
			// [2026-07-28 Johnny Chu] PHASE-0.53-MCP-TWINWEB — expose row identity for per-key file evidence without exposing credentials.
			'key_id'              => (int) $row['id'],
			'client_id'            => (string) $row['client_id'],
			'client_name'          => (string) $row['client_name'],
			'user_id'              => (int) $row['user_id'],
			'scopes'               => self::decode_list( $row['scopes'] ),
			'allowed_notebook_ids' => array_map( 'intval', self::decode_list( $row['allowed_notebook_ids'] ) ),
			'auth_method'          => 'api_key',
		);
	}

	public static function has_scope( array $auth_ctx, $scope ) {
		$scopes = isset( $auth_ctx['scopes'] ) ? (array) $auth_ctx['scopes'] : array();
		return in_array( '*', $scopes, true ) || in_array( (string) $scope, $scopes, true );
	}

	/**
	 * Enforce a bounded request budget after authentication and before dispatch.
	 * The key contains blog_id + client_id only; no token or PII is persisted.
	 *
	 * @return true|WP_Error
	 */
	public static function check_rate_limit( array $auth_ctx ) {
		// [2026-07-28 Johnny Chu] PHASE-0.53-MCP REFLECT — per-blog/client rate limit without token or PII persistence.
		$limit = max( 1, (int) apply_filters( 'bizcity_mcp_rate_limit_max', self::RATE_LIMIT_MAX, $auth_ctx ) );
		$key   = 'client_' . (int) get_current_blog_id() . '_' . substr( hash( 'sha256', (string) ( isset( $auth_ctx['client_id'] ) ? $auth_ctx['client_id'] : '' ) ), 0, 24 );
		$now   = time();
		$state = wp_cache_get( $key, self::RATE_LIMIT_GROUP );

		if ( ! is_array( $state ) || empty( $state['reset_at'] ) || (int) $state['reset_at'] <= $now ) {
			$state = array( 'count' => 1, 'reset_at' => $now + self::RATE_LIMIT_WINDOW );
			wp_cache_set( $key, $state, self::RATE_LIMIT_GROUP, self::RATE_LIMIT_WINDOW );
			return true;
		}

		if ( (int) $state['count'] >= $limit ) {
			return new WP_Error(
				BizCity_MCP_Error::RATE_LIMITED,
				'Client MCP đã vượt giới hạn request trong một phút.',
				array( 'status' => 429, 'retry_after' => max( 1, (int) $state['reset_at'] - $now ) )
			);
		}

		$state['count'] = (int) $state['count'] + 1;
		wp_cache_set( $key, $state, self::RATE_LIMIT_GROUP, max( 1, (int) $state['reset_at'] - $now ) );
		return true;
	}

	/**
	 * Admin/customer-issued key generation. Plaintext key is returned ONCE and
	 * never persisted/logged — only its sha256 hash is stored.
	 *
	 * @return string|WP_Error Plaintext key once, or a safe error when persistence fails.
	 */
	public static function issue_key( $client_id, $client_name, $user_id, array $scopes = array( 'brain.read' ), array $allowed_notebook_ids = array() ) {
		// [2026-07-28 Johnny Chu] PHASE-0.53-MCP REFLECT — return WP_Error when key persistence fails; never return unusable plaintext.
		global $wpdb;
		$plain = 'mcp_' . wp_generate_password( 40, false, false );
		$inserted = $wpdb->insert(
			self::tbl(),
			array(
				'key_hash'             => hash( 'sha256', $plain ),
				'client_id'            => sanitize_key( $client_id ),
				'client_name'          => sanitize_text_field( $client_name ),
				'user_id'              => (int) $user_id,
				'scopes'               => wp_json_encode( array_values( $scopes ) ),
				'allowed_notebook_ids' => wp_json_encode( array_values( array_map( 'intval', $allowed_notebook_ids ) ) ),
				'status'               => 'active',
				'created_at'           => current_time( 'mysql' ),
			)
		);
		if ( ! $inserted ) {
			return new WP_Error( BizCity_MCP_Error::INTERNAL_ERROR, 'Không thể tạo MCP API key.', array( 'status' => 500 ) );
		}
		return $plain;
	}

	public static function revoke_key( $client_id ) {
		global $wpdb;
		return $wpdb->update(
			self::tbl(),
			array( 'status' => 'revoked', 'revoked_at' => current_time( 'mysql' ) ),
			array( 'client_id' => sanitize_key( $client_id ) )
		);
	}

	private static function decode_list( $json ) {
		$decoded = json_decode( (string) $json, true );
		return is_array( $decoded ) ? $decoded : array();
	}
}
