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

	public static function issue( $action, $target_id, array $ctx ) {
		// [2026-07-28 Johnny Chu] PHASE-0.54-MCP — bind token to MCP identity and target.
		$token = 'mcp_confirm_' . wp_generate_password( 48, false, false );
		$key   = self::key( $token );
		set_transient( $key, array(
			'action'    => sanitize_key( $action ),
			'target_id' => (int) $target_id,
			'client_id' => (string) ( $ctx['client_id'] ?? '' ),
			'user_id'   => (int) ( $ctx['user_id'] ?? 0 ),
			'expires'   => time() + self::TTL,
		), self::TTL );
		return array(
			'confirmation_token' => $token,
			'expires_at'         => gmdate( 'c', time() + self::TTL ),
		);
	}

	public static function consume( $token, $action, $target_id, array $ctx ) {
		// [2026-07-28 Johnny Chu] PHASE-0.54-MCP — reject missing, expired, or cross-identity tokens before publish.
		if ( ! is_string( $token ) || $token === '' ) {
			return new WP_Error( 'MCP_ACTION_CONFIRMATION_REQUIRED', 'Cần xác nhận lại bản nháp trước khi thực hiện thao tác này.', array( 'status' => 409 ) );
		}
		$key   = self::key( $token );
		$state = get_transient( $key );
		if ( ! is_array( $state )
			|| (int) ( $state['target_id'] ?? 0 ) !== (int) $target_id
			|| (string) ( $state['action'] ?? '' ) !== sanitize_key( $action )
			|| (string) ( $state['client_id'] ?? '' ) !== (string) ( $ctx['client_id'] ?? '' )
			|| (int) ( $state['user_id'] ?? 0 ) !== (int) ( $ctx['user_id'] ?? 0 )
			|| (int) ( $state['expires'] ?? 0 ) < time() ) {
			return new WP_Error( 'MCP_ACTION_CONFIRMATION_REQUIRED', 'Xác nhận không hợp lệ hoặc đã hết hạn. Hãy xem lại preview rồi xác nhận lại.', array( 'status' => 409 ) );
		}
		delete_transient( $key );
		return true;
	}

	private static function key( $token ) {
		return 'token_' . hash( 'sha256', (string) $token );
	}
}
