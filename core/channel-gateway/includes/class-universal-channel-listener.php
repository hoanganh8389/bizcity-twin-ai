<?php
/**
 * Universal Channel Trigger Listener (PHASE 0.33 M2 essential)
 *
 * Tap into `waic_twf_process_flow` for every known channel trigger key,
 * normalize the payload into a tiny envelope, then:
 *   1. Resolve channel binding → character_id (Guru)
 *   2. Mirror inbound into wp_bizcity_channel_messages (idempotent)
 *   3. If Router caught the request, patch wp_{date}_webhook_log row with
 *      channel_message_id + character_id (so Inspector links 2-way)
 *
 * Runs at priority 5 — BEFORE any business listener (CRM ingestor at 9,
 * Automation triggers at 10). This guarantees character_id is attached
 * to a *new* envelope `bizcity_channel_normalized` re-fired at priority 6
 * for downstream consumers that want guru context.
 *
 * NOTE: This file does NOT replace any existing handler. It is purely
 * additive observability. Adapter refactor (full M2) comes later.
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway
 * @since 1.5.0 (PHASE 0.33 M2 essential)
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Universal_Channel_Listener {

	/**
	 * Trigger key → { platform, account_field, message_field, msgid_field, event_type }
	 *
	 * @var array<string,array<string,string>>
	 */
	private static $map = array(
		'bizcity_facebook_message_received' => array(
			'platform'      => 'FB_MESS',
			'account_field' => 'page_id',
			'user_field'    => 'user_id',
			'message_field' => 'message',
			'msgid_field'   => 'mid',
			'event_type'    => 'message',
		),
		'bizcity_facebook_image_received'   => array(
			'platform'      => 'FB_MESS',
			'account_field' => 'page_id',
			'user_field'    => 'user_id',
			'message_field' => 'image_url',
			'msgid_field'   => 'mid',
			'event_type'    => 'image',
		),
		'bizcity_facebook_comment_received' => array(
			'platform'      => 'FB_FEED',
			'account_field' => 'page_id',
			'user_field'    => 'from_id',
			'message_field' => 'message',
			'msgid_field'   => 'comment_id',
			'event_type'    => 'comment',
		),
		'bizcity_zalo_message_received'     => array(
			'platform'      => 'ZALO_BOT',
			'account_field' => 'conversation_id', // bizcity-zalo-bot uses this for OA id (was wrongly 'oa_id')
			'user_field'    => 'from_user_id',    // was wrongly 'user_id'
			'message_field' => 'message_text',    // was wrongly 'message'
			'msgid_field'   => 'message_id',      // was wrongly 'msg_id'
			'event_type'    => 'message',
		),
		'wu_webchat_message_received'       => array(
			'platform'      => 'WEBCHAT',
			'account_field' => 'site_id',
			'user_field'    => 'session_id',
			'message_field' => 'message',
			'msgid_field'   => 'message_id',
			'event_type'    => 'message',
		),
	);

	public static function init(): void {
		add_action( 'waic_twf_process_flow', array( __CLASS__, 'on_trigger' ), 5, 2 );

		// Bridge Zalo Bot's direct action → workflow trigger.
		// Zalo Bot still fires `do_action('bizcity_zalo_message_received', ...)` directly
		// (BUG-B). We re-emit through `waic_twf_process_flow` so this listener +
		// CRM ingestor + Automation all catch it without forking the Zalo plugin.
		add_action( 'bizcity_zalo_message_received', array( __CLASS__, 'bridge_zalo' ), 5, 1 );
	}

	public static function bridge_zalo( $message_data ): void {
		// Avoid double-fire if Zalo plugin ever switches to workflow trigger.
		static $seen = array();
		$mid = is_array( $message_data ) ? (string) ( $message_data['msg_id'] ?? '' ) : '';
		if ( $mid !== '' && isset( $seen[ $mid ] ) ) {
			return;
		}
		if ( $mid !== '' ) {
			$seen[ $mid ] = true;
		}
		do_action( 'waic_twf_process_flow', 'bizcity_zalo_message_received', (array) $message_data );
	}

	/**
	 * @param string $trigger_key
	 * @param mixed  $payload
	 */
	public static function on_trigger( $trigger_key, $payload = array() ): void {
		if ( ! is_string( $trigger_key ) || ! isset( self::$map[ $trigger_key ] ) ) {
			return;
		}
		if ( ! is_array( $payload ) ) {
			return;
		}
		$spec = self::$map[ $trigger_key ];

		$platform   = $spec['platform'];
		$account_id = (string) ( $payload[ $spec['account_field'] ] ?? '' );
		$user_id    = (string) ( $payload[ $spec['user_field'] ]    ?? '' );
		$message    = (string) ( $payload[ $spec['message_field'] ] ?? '' );
		$message_id = (string) ( $payload[ $spec['msgid_field'] ]   ?? '' );

		if ( $account_id === '' || $user_id === '' ) {
			return;
		}

		// Build a stable chat_id. Pattern: <prefix><account>_<user>
		$chat_id = self::compose_chat_id( $platform, $account_id, $user_id );

		// Resolve binding → character_id (Guru). Null is fine — means no Guru bound yet.
		$character_id   = null;
		$binding_mode   = null;
		$picked         = null;
		$binding_row    = null;
		if ( class_exists( 'BizCity_Channel_Binding' ) ) {
			$binding_row = BizCity_Channel_Binding::resolve( $platform, $account_id );
			if ( $binding_row && ! empty( $binding_row['character_id'] ) ) {
				$character_id = (int) $binding_row['character_id'];
				$binding_mode = isset( $binding_row['mode'] ) ? (string) $binding_row['mode'] : 'auto';
				if ( method_exists( 'BizCity_Channel_Binding', 'pick_responder' ) ) {
					$picked = BizCity_Channel_Binding::pick_responder( $binding_row );
				}
			}
		}

		// Tie back to today's webhook_log row if Router captured it.
		$log_id   = null;
		$log_date = null;
		if ( class_exists( 'BizCity_Webhook_Router' ) ) {
			$current = BizCity_Webhook_Router::current();
			if ( $current && ! empty( $current['id'] ) ) {
				$log_id   = (int) $current['id'];
				$log_date = (string) $current['date'];
			}
		}

		$msg_row_id = 0;
		if ( class_exists( 'BizCity_Channel_Messages' ) ) {
			$msg_row_id = BizCity_Channel_Messages::log_inbound( array(
				'platform'         => $platform,
				'chat_id'          => $chat_id,
				'user_psid'        => $user_id,
				'message_id'       => $message_id,
				'event_type'       => $spec['event_type'],
				'body'             => $message,
				'payload'          => $payload,
				'webhook_log_id'   => $log_id,
				'webhook_log_date' => $log_date,
				'character_id'     => $character_id,
			) );
		}

		// Patch the webhook_log row with foreign keys for trace linking.
		if ( $log_id && $log_date && class_exists( 'BizCity_Webhook_Log' ) ) {
			$patch = array(
				'verify_status' => 'verified',
			);
			if ( $msg_row_id ) {
				$patch['channel_message_id'] = $msg_row_id;
			}
			if ( $character_id ) {
				$patch['character_id'] = $character_id;
			}
			BizCity_Webhook_Log::update( $log_date, $log_id, $patch );
		}

		/**
		 * Re-emit a normalized envelope at priority 6 for downstream listeners
		 * that want guru context without re-deriving it.
		 *
		 * Consumers that subscribed to the original `waic_twf_process_flow`
		 * key still get the raw payload — this is purely additive.
		 *
		 * @param array $envelope
		 */
		$envelope = array(
			'platform'           => $platform,
			'account_id'         => $account_id,
			'user_id'            => $user_id,
			'chat_id'            => $chat_id,
			'message_id'         => $message_id,
			'message'            => $message,
			'event_type'         => $spec['event_type'],
			'character_id'       => $character_id,
			'binding_mode'       => $binding_mode,                                              // PHASE 0.34 trace
			'responder_kind'     => $binding_mode ? ( $binding_mode === 'manual' ? 'manual' : ( $binding_mode === 'hybrid' ? 'hybrid' : 'auto' ) ) : null,
			'responder_user_id'  => $picked['user_id']      ?? null,                            // round-robin or manual fallback
			'responder_character_id' => $picked['character_id'] ?? $character_id,
			'channel_message_id' => $msg_row_id,
			'webhook_log_id'     => $log_id,
			'webhook_log_date'   => $log_date,
			'raw'                => $payload,
		);
		do_action( 'bizcity_channel_normalized', $envelope, $trigger_key );

		/**
		 * Push the resolved responder context onto the Stamper stack so that
		 * any outbound dispatched by downstream automation (within this same
		 * request) is automatically tagged with character_id + responder_kind.
		 *
		 * Manual mode short-circuits: nothing is pushed → outbound (if any)
		 * will fall through to the logged-in-user heuristic.
		 */
		if ( class_exists( 'BizCity_Responder_Stamper' )
			&& $binding_mode
			&& $binding_mode !== 'manual'
			&& ( $character_id || ! empty( $picked['user_id'] ) ) ) {
			BizCity_Responder_Stamper::push( array(
				'kind'         => $binding_mode === 'hybrid' ? 'hybrid' : 'auto',
				'character_id' => $picked['character_id'] ?? $character_id,
				'user_id'      => $picked['user_id']      ?? null,
				'mode'         => $binding_mode,
				'source'       => 'universal-listener',
			) );
			// Schedule a defensive pop on shutdown so we never leak context across requests.
			add_action( 'shutdown', array( 'BizCity_Responder_Stamper', 'clear' ), 99 );
		}
	}

	private static function compose_chat_id( string $platform, string $account_id, string $user_id ): string {
		switch ( $platform ) {
			case 'FB_MESS':
			case 'FB_FEED':
				return 'fb_' . $account_id . '_' . $user_id;
			case 'ZALO_BOT':
				return 'zalobot_' . $account_id . '_' . $user_id;
			case 'WEBCHAT':
				return 'webchat_' . $user_id;
			default:
				return strtolower( $platform ) . '_' . $account_id . '_' . $user_id;
		}
	}

	/* ─── Introspection (for diagnostic page) ─── */

	public static function trigger_keys(): array {
		return array_keys( self::$map );
	}

	public static function spec_for( string $key ): ?array {
		return self::$map[ $key ] ?? null;
	}
}
