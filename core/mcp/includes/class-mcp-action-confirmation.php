<?php
/**
 * BizCity_MCP_Action_Confirmation — one-time confirmation tokens for MCP writes.
 *
 * Tokens are short-lived and bound to the authenticated MCP client, user, action
 * type, and target ID. WordPress transients are used for this first action wave;
 * a durable table can be introduced later through R-DCL/R-CR if required.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\MCP
 * @since      2026-07-28 (PHASE-0.54-MCP Wave J)
 */

defined( 'ABSPATH' ) || exit;

// [2026-07-28 Johnny Chu] PHASE-0.54-MCP — one-time publish confirmation boundary.
final class BizCity_MCP_Action_Confirmation {

	const TTL = 900;

	/**
	 * @param string $args_hash [2026-10-01 Claude Opus 5.5] PHASE-0.88 L1-7 — sha256 of the canonical arguments (Q88-1
	 *                          "xem trước → cam kết"); '' keeps the PHASE-0.54 page.publish behaviour (no argument binding).
	 */
	public static function issue( $action, $target_id, array $ctx, $args_hash = '' ) {
		// [2026-07-28 Johnny Chu] PHASE-0.54-MCP — bind token to MCP identity and target.
		$token = 'mcp_confirm_' . wp_generate_password( 48, false, false );
		$key   = self::key( $token );
		set_transient( $key, array(
			'action'    => sanitize_key( $action ),
			'target_id' => (int) $target_id,
			'client_id' => (string) ( $ctx['client_id'] ?? '' ),
			'user_id'   => (int) ( $ctx['user_id'] ?? 0 ),
			'expires'   => time() + self::TTL,
			'args_hash' => (string) $args_hash, // [2026-10-01 Claude Opus 5.5] PHASE-0.88 L1-7 — one token = one set of arguments.
		), self::TTL );
		return array(
			'confirmation_token' => $token,
			'expires_at'         => gmdate( 'c', time() + self::TTL ),
		);
	}

	/**
	 * @param string|null $args_hash [2026-10-01 Claude Opus 5.5] PHASE-0.88 L1-7 — null = no argument check and the legacy
	 *        MCP_ACTION_CONFIRMATION_REQUIRED error (page.publish stays compatible). A string = the registry confirm wrapper:
	 *        invalid / used / expired / other identity ⇒ MCP_CONFIRM_INVALID; same token but other arguments ⇒
	 *        MCP_CONFIRM_ARGS_CHANGED and the token is NOT consumed (the user may still confirm the original preview).
	 * @return true|WP_Error
	 */
	public static function consume( $token, $action, $target_id, array $ctx, $args_hash = null ) {
		$bound   = null !== $args_hash;
		$invalid = $bound ? 'MCP_CONFIRM_INVALID' : 'MCP_ACTION_CONFIRMATION_REQUIRED';
		// [2026-07-28 Johnny Chu] PHASE-0.54-MCP — reject missing, expired, or cross-identity tokens before publish.
		if ( ! is_string( $token ) || $token === '' ) {
			return new WP_Error( $invalid, 'Cần xác nhận lại bản nháp trước khi thực hiện thao tác này.', array( 'status' => 409 ) );
		}
		$key   = self::key( $token );
		$state = get_transient( $key );
		if ( ! is_array( $state )
			|| (int) ( $state['target_id'] ?? 0 ) !== (int) $target_id
			|| (string) ( $state['action'] ?? '' ) !== sanitize_key( $action )
			|| (string) ( $state['client_id'] ?? '' ) !== (string) ( $ctx['client_id'] ?? '' )
			|| (int) ( $state['user_id'] ?? 0 ) !== (int) ( $ctx['user_id'] ?? 0 )
			|| (int) ( $state['expires'] ?? 0 ) < time() ) {
			return new WP_Error( $invalid, 'Xác nhận không hợp lệ hoặc đã hết hạn. Hãy xem lại preview rồi xác nhận lại.', array( 'status' => 409 ) );
		}
		// [2026-10-01 Claude Opus 5.5] PHASE-0.88 L1-7 — identity first (no hint about someone else's arguments), then the arguments.
		if ( $bound && ! hash_equals( (string) ( $state['args_hash'] ?? '' ), (string) $args_hash ) ) {
			return new WP_Error( 'MCP_CONFIRM_ARGS_CHANGED', 'Tham số khác với bản xem trước đã xác nhận.', array( 'status' => 409 ) );
		}
		delete_transient( $key );
		return true;
	}

	/**
	 * [2026-10-01 Claude Opus 5.5] PHASE-0.88 L1-7 — sha256 of the canonical JSON of the arguments without confirm_token:
	 * object keys sorted recursively, lists kept in order, no whitespace, unescaped unicode and slashes (confirm.flow.json).
	 */
	public static function args_hash( array $args ) {
		unset( $args['confirm_token'] );
		return hash( 'sha256', (string) json_encode( self::canonical( $args ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	}

	private static function canonical( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$is_list = array() === $value || array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( ! $is_list ) {
			ksort( $value, SORT_STRING );
		}
		foreach ( $value as $k => $v ) {
			$value[ $k ] = self::canonical( $v );
		}
		return $value;
	}

	private static function key( $token ) {
		return 'token_' . hash( 'sha256', (string) $token );
	}
}
