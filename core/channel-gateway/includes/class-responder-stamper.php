<?php
/**
 * Responder Stamper (PHASE 0.34 — trace manifesto)
 *
 * Single source of truth for *who* answered each outbound message.
 * Maintains a request-scoped context stack so that any code path emitting
 * outbound (auto pipeline, manual composer, hybrid override, round-robin)
 * can simply push their responder identity, and the gateway will stamp the
 * `_bizcity_channel_messages` row automatically via
 * `bizcity_channel_after_send`.
 *
 * Public API:
 *   BizCity_Responder_Stamper::push(['kind'=>'auto','character_id'=>5])
 *   BizCity_Responder_Stamper::pop()
 *   BizCity_Responder_Stamper::current(): ?array
 *   BizCity_Responder_Stamper::record_outbound(array $args): int  // direct write
 *   BizCity_Responder_Stamper::init()  // wires after_send hook
 *
 * Args for record_outbound() merge with current() context:
 *   chat_id, message, platform, status, message_id?, body?, payload?, error?,
 *   responder_kind?, responder_character_id?, responder_user_id?, character_id?
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway
 * @since 1.5.3 (PHASE 0.34 M4.2)
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Responder_Stamper {

	/** @var array<int,array> request-scoped LIFO stack */
	private static $stack = array();

	/** @var bool */
	private static $booted = false;

	public static function init(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		// Sender fires this rich action after every outbound. Capture & stamp.
		add_action( 'bizcity_channel_outbound_logged', array( __CLASS__, 'on_outbound_logged' ), 10, 1 );
	}

	/* ─────────────────────────── Stack ─────────────────────────── */

	/**
	 * Push a responder context.
	 *
	 * @param array $ctx { kind, character_id?, user_id?, mode?, source? }
	 *                   kind ∈ auto|manual|hybrid|system
	 */
	public static function push( array $ctx ): void {
		$ctx = array_merge( array(
			'kind'         => 'auto',
			'character_id' => null,
			'user_id'      => null,
			'mode'         => null,
			'source'       => '',
		), $ctx );
		if ( ! in_array( $ctx['kind'], array( 'auto', 'manual', 'hybrid', 'system' ), true ) ) {
			$ctx['kind'] = 'auto';
		}
		array_push( self::$stack, $ctx );
	}

	public static function pop(): ?array {
		return self::$stack ? array_pop( self::$stack ) : null;
	}

	public static function current(): ?array {
		return self::$stack ? end( self::$stack ) : null;
	}

	public static function clear(): void {
		self::$stack = array();
	}

	/* ───────────────────────── Stamp & log ───────────────────────── */

	/**
	 * Hooked to bizcity_channel_outbound_logged. Writes a stamped outbound
	 * row into `_bizcity_channel_messages`.
	 *
	 * @param array $args { chat_id, platform, message, type, extra, sent, error }
	 */
	public static function on_outbound_logged( $args ): void {
		if ( ! is_array( $args ) || ! class_exists( 'BizCity_Channel_Messages' ) ) {
			return;
		}
		$ctx = self::current() ?: array(
			'kind'         => 'auto',
			'character_id' => null,
			'user_id'      => null,
			'mode'         => null,
			'source'       => '',
		);
		// If stack is empty but a logged-in user triggered this, classify as manual.
		if ( empty( self::$stack ) && function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) {
			$uid = (int) get_current_user_id();
			if ( $uid > 0 ) {
				$ctx['kind']    = 'manual';
				$ctx['user_id'] = $uid;
			}
		}

		BizCity_Channel_Messages::log_outbound( array(
			'platform'             => strtoupper( (string) ( $args['platform'] ?? '' ) ),
			'chat_id'              => (string) ( $args['chat_id']  ?? '' ),
			'user_psid'            => self::psid_from_chat_id( (string) ( $args['chat_id'] ?? '' ) ),
			'message_id'           => isset( $args['extra']['message_id'] ) ? (string) $args['extra']['message_id']
									 : ( isset( $args['extra']['mid'] ) ? (string) $args['extra']['mid'] : '' ),
			'body'                 => (string) ( $args['message']  ?? '' ),
			'payload'              => isset( $args['extra'] ) ? $args['extra'] : null,
			'character_id'         => $ctx['character_id'],
			'responder_kind'       => $ctx['kind'],
			'responder_user_id'    => $ctx['user_id'],
			'status'               => ! empty( $args['sent'] ) ? 'sent' : 'failed',
			'error'                => isset( $args['error'] ) ? (string) $args['error'] : '',
		) );
	}

	/**
	 * Extract recipient PSID/UID from a canonical gateway chat_id.
	 * Patterns: fb_{page}_{psid}, zalobot_{bot}_{uid}, hotline_{oa}_{uid},
	 * web_{site}_{session}, tg_{chat}_{user}. Last segment wins.
	 */
	private static function psid_from_chat_id( string $chat_id ): string {
		if ( $chat_id === '' ) { return ''; }
		$parts = explode( '_', $chat_id );
		if ( count( $parts ) < 3 ) { return end( $parts ) ?: ''; }
		return (string) end( $parts );
	}

	/**
	 * Convenience: write an outbound row directly (skips Sender).
	 * Use when you already sent through some adapter and just need to record.
	 *
	 * @return int message id (0 on failure)
	 */
	public static function record_outbound( array $args ): int {
		if ( ! class_exists( 'BizCity_Channel_Messages' ) ) {
			return 0;
		}
		$ctx = self::current() ?: array();
		$row = array(
			'platform'           => strtoupper( (string) ( $args['platform'] ?? '' ) ),
			'chat_id'            => (string) ( $args['chat_id'] ?? '' ),
			'message_id'         => (string) ( $args['message_id'] ?? '' ),
			'user_psid'          => (string) ( $args['user_psid'] ?? '' ),
			'event_type'         => (string) ( $args['event_type'] ?? 'message' ),
			'body'               => (string) ( $args['body'] ?? $args['message'] ?? '' ),
			'payload'            => $args['payload']            ?? null,
			'character_id'       => isset( $args['character_id'] )       ? (int) $args['character_id']
								  : ( isset( $args['responder_character_id'] ) ? (int) $args['responder_character_id']
								  : ( $ctx['character_id'] ?? null ) ),
			'responder_kind'     => isset( $args['responder_kind'] )    ? (string) $args['responder_kind']
								  : ( $ctx['kind']    ?? 'auto' ),
			'responder_user_id'  => isset( $args['responder_user_id'] ) ? (int) $args['responder_user_id']
								  : ( $ctx['user_id'] ?? null ),
			'status'             => (string) ( $args['status'] ?? 'sent' ),
			'error'              => (string) ( $args['error']  ?? '' ),
		);
		return BizCity_Channel_Messages::log_outbound( $row );
	}
}
