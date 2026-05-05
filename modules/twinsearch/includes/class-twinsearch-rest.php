<?php
/**
 * TwinSearch — REST extensions (Wave 0.18.1.6 + 0.18.1.8)
 *
 * Adds endpoints that are NOT covered by core/research/class-research-rest.php:
 *
 *   GET    /twinsearch/v1/input-gate/{character_id}
 *   POST   /twinsearch/v1/input-gate/{character_id}    body: {required, providers, min_sources, block_message}
 *   POST   /twinsearch/v1/input-gate/check             body: {character_id, scope_type, scope_id}
 *   DELETE /twinsearch/v1/turns/{turn_id}/cancel       (Wave 0.18.1.8)
 *
 * Permission model: character editing requires `edit_others_posts`; cancel
 * requires session ownership (same rule as core/research).
 *
 * @package Bizcity_Twin_AI\Modules\TwinSearch
 * @since 0.18.1.6
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_TwinSearch_REST' ) ) {
	return;
}

class BizCity_TwinSearch_REST {

	const NS = 'twinsearch/v1';

	public static function init(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
	}

	public static function register(): void {

		register_rest_route( self::NS, '/input-gate/(?P<character_id>\d+)', [
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'get_gate' ],
				'permission_callback' => [ __CLASS__, 'auth_logged_in' ],
			],
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'set_gate' ],
				'permission_callback' => [ __CLASS__, 'auth_can_edit' ],
			],
		] );

		register_rest_route( self::NS, '/input-gate/check', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'check_gate' ],
			'permission_callback' => [ __CLASS__, 'auth_logged_in' ],
		] );

		register_rest_route( self::NS, '/turns/(?P<turn_id>\d+)/cancel', [
			'methods'             => 'DELETE',
			'callback'            => [ __CLASS__, 'cancel_turn' ],
			'permission_callback' => [ __CLASS__, 'auth_can_cancel_turn' ],
		] );
	}

	/* ─────── Permission ─────── */

	public static function auth_logged_in(): bool {
		return is_user_logged_in();
	}

	public static function auth_can_edit(): bool {
		return current_user_can( 'edit_others_posts' );
	}

	public static function auth_can_cancel_turn( WP_REST_Request $req ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', 'Login required', [ 'status' => 401 ] );
		}
		if ( ! class_exists( 'BizCity_Research_Store' ) ) {
			return new WP_Error( 'rest_unavailable', 'Research store missing', [ 'status' => 503 ] );
		}
		$turn = BizCity_Research_Store::get_turn( (int) $req['turn_id'] );
		if ( ! $turn || ! BizCity_Research_Store::user_can_session( (int) $turn['session_id'] ) ) {
			return new WP_Error( 'rest_forbidden', 'Forbidden', [ 'status' => 403 ] );
		}
		return true;
	}

	/* ─────── Handlers ─────── */

	public static function get_gate( WP_REST_Request $req ) {
		$cid = (int) $req['character_id'];
		return rest_ensure_response( BizCity_Input_Gate::get_config( $cid ) );
	}

	public static function set_gate( WP_REST_Request $req ) {
		$cid  = (int) $req['character_id'];
		$body = $req->get_json_params() ?: [];
		$ok   = BizCity_Input_Gate::set_config( $cid, is_array( $body ) ? $body : [] );
		if ( ! $ok ) {
			return new WP_Error( 'rest_update_failed', 'Could not update input gate', [ 'status' => 500 ] );
		}
		return rest_ensure_response( BizCity_Input_Gate::get_config( $cid ) );
	}

	public static function check_gate( WP_REST_Request $req ) {
		$body = $req->get_json_params() ?: [];
		$cid  = (int) ( $body['character_id'] ?? 0 );
		$st   = (string) ( $body['scope_type'] ?? 'character' );
		$sid  = (int) ( $body['scope_id'] ?? $cid );
		return rest_ensure_response( BizCity_Input_Gate::should_block( $cid, $st, $sid ) );
	}

	public static function cancel_turn( WP_REST_Request $req ) {
		$turn_id = (int) $req['turn_id'];
		$turn    = BizCity_Research_Store::get_turn( $turn_id );
		if ( ! $turn ) {
			return new WP_Error( 'rest_not_found', 'Turn not found', [ 'status' => 404 ] );
		}
		if ( $turn['status'] === 'done' || $turn['status'] === 'cancelled' ) {
			return rest_ensure_response( [ 'cancelled' => true, 'already' => true ] );
		}
		BizCity_Research_Store::finalize_turn( $turn_id, [
			'status'        => 'cancelled',
			'error_message' => 'cancelled by user',
		] );
		if ( class_exists( 'BizCity_Research_Event_Emitter' ) ) {
			BizCity_Research_Event_Emitter::emit( 'research_turn_cancelled', [
				'turn_id'    => $turn_id,
				'session_id' => (int) $turn['session_id'],
				'user_id'    => get_current_user_id(),
			] );
		}
		return rest_ensure_response( [ 'cancelled' => true ] );
	}
}
