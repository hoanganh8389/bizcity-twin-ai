<?php
/**
 * Inbox Send REST (PHASE 0.34 M4.2 — Composer FE-M4 backend)
 *
 * Provides authenticated endpoints for the CRM Inbox composer to:
 *   POST /bizcity/cg/v1/inbox/send   — push outbound + auto-stamp responder (manual/hybrid)
 *   POST /bizcity/cg/v1/inbox/note   — write a private internal note (no outbound dispatch)
 *
 * Both wrap BizCity_Responder_Stamper so the resulting `_bizcity_channel_messages`
 * row carries `responder_kind` + `responder_user_id` + `responder_character_id`.
 *
 * Permission: `edit_posts` (CSKH agents). Use cap filter to refine per project.
 *
 * Request body (JSON):
 *   send: { chat_id, platform, message, responder_kind?, character_id?, hybrid_source? }
 *         responder_kind ∈ 'manual' (default) | 'hybrid'
 *   note: { chat_id, platform, body }   — never dispatched, just persisted as system bubble
 *
 * Response:
 *   { ok:true, data:{ message_id, sent, responder_kind, responder_character_id, responder_user_id } }
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway
 * @since 1.5.3 (PHASE 0.34 M4.2)
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Inbox_Send_REST {

	const NAMESPACE_V1 = 'bizcity-channel/v1';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route( self::NAMESPACE_V1, '/inbox/send', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_send' ),
			'permission_callback' => array( __CLASS__, 'can_reply' ),
		) );
		register_rest_route( self::NAMESPACE_V1, '/inbox/note', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_note' ),
			'permission_callback' => array( __CLASS__, 'can_reply' ),
		) );
	}

	public static function can_reply(): bool {
		/**
		 * Filter the capability required to reply through the CRM composer.
		 *
		 * @param string $cap Default 'edit_posts'.
		 */
		$cap = (string) apply_filters( 'bizcity_inbox_reply_cap', 'edit_posts' );
		return current_user_can( $cap );
	}

	/* ─────────────────────────── /inbox/send ─────────────────────────── */

	public static function rest_send( WP_REST_Request $req ) {
		$body = $req->get_json_params();
		if ( ! is_array( $body ) ) { $body = array(); }

		$chat_id  = trim( (string) ( $body['chat_id']  ?? '' ) );
		$platform = strtoupper( trim( (string) ( $body['platform'] ?? '' ) ) );
		$message  = (string) ( $body['message']  ?? '' );
		$kind     = (string) ( $body['responder_kind'] ?? 'manual' );
		$cid      = isset( $body['character_id'] ) ? (int) $body['character_id'] : 0;

		if ( $chat_id === '' || $message === '' ) {
			return new WP_REST_Response( array( 'ok' => false, 'message' => 'chat_id and message are required' ), 400 );
		}
		if ( ! in_array( $kind, array( 'manual', 'hybrid', 'auto', 'system' ), true ) ) {
			$kind = 'manual';
		}

		// Resolve binding for context (character_id may come from binding).
		if ( $cid <= 0 && $platform !== '' && class_exists( 'BizCity_Channel_Binding' ) ) {
			// WEBCHAT is local — 1 binding per site, account_id = blog_id.
			// Don't rely on chat_id parsing because session_id may contain '_'.
			if ( $platform === 'WEBCHAT' ) {
				$account_id = (string) get_current_blog_id();
			} else {
				// account_id segment is the part after `<platform>_` before `_<user>` — best-effort.
				$account_id = self::extract_account_id( $chat_id, $platform );
			}
			if ( $account_id !== '' ) {
				$binding = BizCity_Channel_Binding::resolve( $platform, $account_id );
				if ( $binding && ! empty( $binding['character_id'] ) ) {
					$cid = (int) $binding['character_id'];
				}
			}
		}

		if ( ! class_exists( 'BizCity_Responder_Stamper' ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'message' => 'Responder Stamper missing' ), 500 );
		}

		BizCity_Responder_Stamper::push( array(
			'kind'         => $kind,
			'character_id' => $cid ?: null,
			'user_id'      => (int) get_current_user_id(),
			'source'       => 'inbox-rest',
		) );

		$result = array( 'sent' => false, 'error' => 'no-sender', 'platform' => $platform );
		if ( class_exists( 'BizCity_Gateway_Sender' ) ) {
			$result = BizCity_Gateway_Sender::instance()->send( $chat_id, $message );
		}

		BizCity_Responder_Stamper::pop();

		return new WP_REST_Response( array(
			'ok'   => true,
			'data' => array(
				'sent'                   => (bool) $result['sent'],
				'platform'               => $result['platform'],
				'error'                  => (string) $result['error'],
				'responder_kind'         => $kind,
				'responder_user_id'      => (int) get_current_user_id(),
				'responder_character_id' => $cid ?: null,
			),
		), 200 );
	}

	/* ─────────────────────────── /inbox/note ─────────────────────────── */

	public static function rest_note( WP_REST_Request $req ) {
		$body = $req->get_json_params();
		if ( ! is_array( $body ) ) { $body = array(); }

		$chat_id  = trim( (string) ( $body['chat_id']  ?? '' ) );
		$platform = strtoupper( trim( (string) ( $body['platform'] ?? '' ) ) );
		$note     = (string) ( $body['body']     ?? '' );

		if ( $chat_id === '' || $note === '' ) {
			return new WP_REST_Response( array( 'ok' => false, 'message' => 'chat_id and body are required' ), 400 );
		}
		if ( ! class_exists( 'BizCity_Responder_Stamper' ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'message' => 'Responder Stamper missing' ), 500 );
		}

		$id = BizCity_Responder_Stamper::record_outbound( array(
			'platform'          => $platform,
			'chat_id'           => $chat_id,
			'body'              => $note,
			'event_type'        => 'private_note',
			'status'            => 'note',
			'responder_kind'    => 'manual',
			'responder_user_id' => (int) get_current_user_id(),
		) );

		return new WP_REST_Response( array(
			'ok'   => true,
			'data' => array(
				'message_id'        => $id,
				'responder_kind'    => 'manual',
				'responder_user_id' => (int) get_current_user_id(),
			),
		), $id ? 200 : 500 );
	}

	/* ─────────────────────────── helpers ─────────────────────────── */

	/**
	 * Best-effort: pull the `account_id` segment from a composed chat_id.
	 * Universal Listener compose pattern: `<prefix><account>_<user>`.
	 */
	private static function extract_account_id( string $chat_id, string $platform ): string {
		// Strip a known leading prefix if present (e.g. "fb_", "zalo_").
		$prefixes = array(
			'FB_MESS'      => array( 'fb_', 'fbmess_' ),
			'FB_FEED'      => array( 'fbfeed_', 'fb_' ),
			'ZALO_BOT'     => array( 'zalobot_', 'zalo_' ),
			'ZALO_HOTLINE' => array( 'hotline_', 'zalo_' ),
			'WEBCHAT'      => array( 'web_', 'webchat_' ),
			'TELEGRAM'     => array( 'tg_', 'tele_' ),
		);
		$candidate = $chat_id;
		if ( isset( $prefixes[ $platform ] ) ) {
			foreach ( $prefixes[ $platform ] as $p ) {
				if ( strpos( $candidate, $p ) === 0 ) { $candidate = substr( $candidate, strlen( $p ) ); break; }
			}
		}
		// Take part before first `_` (account), drop user segment.
		$pos = strpos( $candidate, '_' );
		return $pos === false ? $candidate : substr( $candidate, 0, $pos );
	}
}
