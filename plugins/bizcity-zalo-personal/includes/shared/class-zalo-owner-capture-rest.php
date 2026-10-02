<?php
/**
 * BizCity_Zalo_Owner_Capture_REST — the one write path from a zalo-hub Twin Agent turn into this site's knowledge
 * (PHASE-0.87 CL-D1, R-TAA-15, contract `owner-capture@1`).
 *
 *   POST bizcity-channel/v1/zalo-bridge/owner-capture
 *
 * Cell outbox → Hub (`POST {HUB}/zalo-hub/owner-capture`, HB-5) → here, same per-account callback Bearer as /inbound
 * (BizCity_Zalo_Bridge_Client::expected_inbound_token). Asynchronous by contract: this handler only verifies, dedupes
 * and hands off to BizCity_Zalo_Personal_Knowledge_Capture::from_owner_capture(), which schedules a cron job — never a
 * synchronous KG/LLM call inside the request. The cell already proved the sender is the number's UID chủ in a 1-1 chat
 * before it ever sent this request; this route does not re-derive that.
 *
 * @package BizCity_Zalo_Personal
 * @since   PHASE-0.87 (2026-09-30)
 */

// [2026-09-30 Claude Opus 5.5] PHASE-0.87 CL-D1.
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Zalo_Owner_Capture_REST', false ) ) {
	return;
}

final class BizCity_Zalo_Owner_Capture_REST {

	const NS       = 'bizcity-channel/v1';
	const CONTRACT_PREFIX = 'owner-capture@1';

	private static $initialized = false;

	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route( self::NS, '/zalo-bridge/owner-capture', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'handle_capture' ),
			'permission_callback' => '__return_true', // Bearer verified in handler, same boundary as /inbound.
		) );
	}

	public static function handle_capture( WP_REST_Request $request ): WP_REST_Response {
		$body = $request->get_json_params();
		$body = is_array( $body ) ? $body : array();
		$bridge_id = sanitize_text_field( (string) ( $body['account_id'] ?? '' ) );

		if ( ! class_exists( 'BizCity_Zalo_Bridge_Client' ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'site_unsupported' ), 503 );
		}
		$stored_token = '' !== $bridge_id ? BizCity_Zalo_Bridge_Client::instance()->expected_inbound_token( $bridge_id ) : '';
		$header = (string) $request->get_header( 'authorization' );
		$bearer = stripos( $header, 'Bearer ' ) === 0 ? trim( substr( $header, 7 ) ) : '';
		if ( '' === $stored_token || '' === $bearer || ! hash_equals( $stored_token, $bearer ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'unauthorized' ), 401 );
		}

		$contract = (string) ( $body['contract'] ?? '' );
		if ( 0 !== strpos( $contract, self::CONTRACT_PREFIX ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'unsupported_contract' ), 400 );
		}
		$kind            = sanitize_key( (string) ( $body['kind'] ?? '' ) );
		$idempotency_key = sanitize_text_field( (string) ( $body['idempotency_key'] ?? '' ) );
		$items           = is_array( $body['items'] ?? null ) ? $body['items'] : array();
		if ( ! in_array( $kind, array( 'file', 'remember' ), true ) || '' === $idempotency_key || empty( $items ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'invalid_param' ), 400 );
		}

		if ( ! class_exists( 'BizCity_Zalo_Personal_Knowledge_Capture' ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'capture_unsupported' ), 503 );
		}
		$ts  = strtotime( (string) ( $body['sent_at'] ?? '' ) );
		// [2026-10-01 Claude Opus 5.5] PHASE-0.87 W2-4 — owner-capture@1.1 `role` + `user_hash` pick the recipient (doc 50 §5.4);
		// a 1.0 payload has no `role` and keeps meaning "the owner".
		$principal = array(
			'user_hash' => sanitize_text_field( (string) ( $body['user_hash'] ?? '' ) ),
			'role'      => sanitize_key( (string) ( $body['role'] ?? '' ) ),
			'kind'      => $kind,
		);
		$res = BizCity_Zalo_Personal_Knowledge_Capture::from_owner_capture( $bridge_id, $items, $idempotency_key, $ts > 0 ? $ts : 0, $principal );

		if ( empty( $res['ok'] ) ) {
			$code   = (string) ( $res['code'] ?? '' );
			$status = 'account_not_found' === $code ? 404 : ( 'capture_principal_unknown' === $code ? 403 : 400 );
			return new WP_REST_Response( array( 'ok' => false, 'code' => (string) ( $res['code'] ?? 'rejected' ) ), $status );
		}
		return new WP_REST_Response( array(
			'ok'        => true,
			'duplicate' => (bool) ( $res['duplicate'] ?? false ),
			// notebook_id is not known synchronously: the notebook is resolved/created inside the async ingest job.
			'notebook_id' => null,
		), 200 );
	}
}
