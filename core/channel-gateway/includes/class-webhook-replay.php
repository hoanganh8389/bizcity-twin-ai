<?php
/**
 * Webhook Replay
 *
 * PHASE 0.34 M5 — Re-fire a previously-captured webhook log row through the
 * Channel Gateway pipeline so adapters / observers can re-process it without
 * round-tripping the external provider (FB, Zalo, …).
 *
 * Replay strategy (idempotent, side-effect-friendly):
 *   1. Load the original log row via BizCity_Webhook_Log::find().
 *   2. Refuse to replay rows that are themselves replays (no chain reactions).
 *   3. Stamp a system responder context so any outbound sent during replay is
 *      attributed to `responder_kind=system`, `source=replay`.
 *   4. Write a NEW log row with `is_replay=1`, `parent_log_date`, `parent_log_id`.
 *   5. Emit `bizcity_channel_replay` carrying the full original envelope so any
 *      registered adapter / observer may re-parse and re-dispatch.
 *
 * REST: POST /bizcity/cg/v1/inspector/replay/{date}/{id}
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway
 * @since 1.6.0 (PHASE 0.34 M5)
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BizCity_Webhook_Replay' ) ) :

class BizCity_Webhook_Replay {

	const NAMESPACE_V1 = 'bizcity-channel/v1';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE_V1,
			'/inspector/replay/(?P<date>\d{4}_\d{2}_\d{2})/(?P<id>\d+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_replay' ),
				'permission_callback' => array( __CLASS__, 'can' ),
			)
		);
	}

	public static function can(): bool {
		return current_user_can(
			(string) apply_filters( 'bizcity_webhook_replay_cap', 'manage_options' )
		);
	}

	/**
	 * REST callback.
	 *
	 * @param WP_REST_Request $req
	 * @return WP_REST_Response
	 */
	public static function rest_replay( $req ) {
		if ( ! class_exists( 'BizCity_Webhook_Log' ) ) {
			return new WP_REST_Response( array(
				'ok'      => false,
				'code'    => 'log_class_missing',
				'message' => 'Webhook log class missing.',
			), 500 );
		}

		$date = (string) $req->get_param( 'date' );
		$id   = (int) $req->get_param( 'id' );

		$row = BizCity_Webhook_Log::find( $date, $id );
		if ( ! is_array( $row ) ) {
			return new WP_REST_Response( array(
				'ok'      => false,
				'code'    => 'not_found',
				'message' => 'Original log row not found.',
			), 404 );
		}

		if ( ! empty( $row['is_replay'] ) ) {
			return new WP_REST_Response( array(
				'ok'      => false,
				'code'    => 'already_replay',
				'message' => 'Refusing to replay a replay (avoid loops).',
			), 409 );
		}

		$result = self::replay_row( $row );

		return new WP_REST_Response( $result, $result['ok'] ? 200 : 500 );
	}

	/**
	 * Public entrypoint — usable from diag or programmatic call.
	 *
	 * @param array $row Log envelope as returned by BizCity_Webhook_Log::find().
	 * @return array{ok:bool,replay:array,parent:array,observers_fired:bool,code?:string,message?:string}
	 */
	public static function replay_row( array $row ): array {
		$platform = (string) ( $row['platform'] ?? '' );
		if ( $platform === '' ) {
			return array(
				'ok'      => false,
				'code'    => 'platform_missing',
				'message' => 'Original row has no platform.',
			);
		}

		$parent = array(
			'date' => (string) ( $row['date'] ?? '' ),
			'id'   => (int) ( $row['id'] ?? 0 ),
		);

		// Stamp system replay context so any outbound dispatched downstream is
		// attributed to the replay path, not to a real responder.
		$pushed = false;
		if ( class_exists( 'BizCity_Responder_Stamper' ) ) {
			BizCity_Responder_Stamper::push( array(
				'kind'   => 'system',
				'source' => 'replay',
				'mode'   => 'replay',
				'parent' => $parent,
			) );
			$pushed = true;
		}

		// Persist a fresh log row marked as a replay so the inspector grid
		// shows a clear lineage.
		$log = BizCity_Webhook_Log::log( array(
			'platform'         => $platform,
			'endpoint'         => (string) ( $row['endpoint'] ?? '' ),
			'method'           => (string) ( $row['method'] ?? 'POST' ),
			'http_status'      => 200,
			'verify_status'    => 'replayed',
			'remote_ip'        => '',
			'user_agent'       => 'BizCity-Replay/1.0',
			'headers'          => is_array( $row['headers'] ?? null ) ? $row['headers'] : array(),
			'body_raw'         => (string) ( $row['body'] ?? '' ),
			'character_id'     => isset( $row['character_id'] ) ? (int) $row['character_id'] : null,
			'is_replay'        => 1,
			'parent_log_date'  => $parent['date'],
			'parent_log_id'    => $parent['id'],
		) );

		$observers_fired = false;
		try {
			/**
			 * Re-fire the original intake action so observers can re-process.
			 *
			 * @param array  $log      ['date'=>..., 'id'=>...] (the replay row)
			 * @param string $platform e.g. 'FB_MESS', 'ZALO_BOT'
			 * @param string $body     Original raw request body
			 */
			do_action( 'bizcity_webhook_router_intake', $log, $platform, (string) ( $row['body'] ?? '' ) );

			/**
			 * Dedicated replay channel — adapters / observers can subscribe
			 * here to re-parse without triggering verification / signature
			 * checks (since the row is already trusted history).
			 *
			 * @param array $row    Original full envelope.
			 * @param array $log    Replay log row pointer.
			 * @param array $parent Pointer to the parent (original) row.
			 */
			do_action( 'bizcity_channel_replay', $row, $log, $parent );

			$observers_fired = true;
		} finally {
			if ( $pushed && class_exists( 'BizCity_Responder_Stamper' ) ) {
				BizCity_Responder_Stamper::pop();
			}
		}

		return array(
			'ok'              => true,
			'replay'          => $log,
			'parent'          => $parent,
			'observers_fired' => $observers_fired,
		);
	}
}

endif;
