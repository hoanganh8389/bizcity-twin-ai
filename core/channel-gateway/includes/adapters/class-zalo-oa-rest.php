<?php
/**
 * Zalo OA — Admin CRM REST Endpoints (Sprint ZA-3)
 *
 * Surface (namespace bizcity-channel/v1, all require manage_options):
 *   GET  /zalo/recent-users   — grouped recent Zalo users for one OA inbox
 *   GET  /zalo/conversation   — timeline for (oa_id, user_id)
 *   POST /zalo/admin-send     — admin chat-back to a Zalo user + log to ledger
 *
 * Reads from `bizcity_channel_messages` (unified ledger written by UCL).
 * Sends via BizCity_Zalo_Bot_OA_Integration::send_outbound() through
 * BizCity_Gateway_Sender — identical path to automation replies.
 *
 * Mirrors pattern from class-facebook-page-rest.php (FB inbox tabs).
 *
 * [2026-06-13 Johnny Chu] ZA-3 — Zalo OA admin CRM inbox REST API.
 *
 * @package    BizCity_Twin_AI
 * @subpackage Channel_Gateway\Adapters
 * @since      PHASE 0.37 ZA-3
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Zalo_OA_REST' ) ) {
	return;
}

class BizCity_Zalo_OA_REST {

	const NS = 'bizcity-channel/v1';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		$perm = array( __CLASS__, 'perm_admin' );

		// Grouped recent users for one OA.
		register_rest_route( self::NS, '/zalo/recent-users', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get_recent_users' ),
			'permission_callback' => $perm,
			'args'                => array(
				'oa_id' => array( 'required' => true,  'type' => 'string' ),
				'limit' => array( 'required' => false, 'type' => 'integer', 'default' => 50 ),
			),
		) );

		// Conversation timeline for (oa_id, user_id).
		register_rest_route( self::NS, '/zalo/conversation', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get_conversation' ),
			'permission_callback' => $perm,
			'args'                => array(
				'oa_id'   => array( 'required' => true,  'type' => 'string' ),
				'user_id' => array( 'required' => true,  'type' => 'string' ),
				'limit'   => array( 'required' => false, 'type' => 'integer', 'default' => 200 ),
			),
		) );

		// Admin send — sends a message to a Zalo user and logs it.
		register_rest_route( self::NS, '/zalo/admin-send', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'admin_send' ),
			'permission_callback' => $perm,
			'args'                => array(
				'oa_id'   => array( 'required' => true, 'type' => 'string' ),
				'user_id' => array( 'required' => true, 'type' => 'string' ),
				'text'    => array( 'required' => true, 'type' => 'string' ),
			),
		) );
	}

	/* ──────────────────────────────────────────
	 * GET /zalo/recent-users
	 * ────────────────────────────────────────── */

	/**
	 * Return latest Zalo OA contacts grouped by user, newest first.
	 *
	 * @param WP_REST_Request $req {oa_id, limit?}
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_recent_users( WP_REST_Request $req ) {
		global $wpdb;

		$oa_id = trim( (string) $req->get_param( 'oa_id' ) );
		$limit = (int) $req->get_param( 'limit' );
		if ( $limit <= 0 || $limit > 200 ) {
			$limit = 50;
		}
		if ( $oa_id === '' ) {
			return new WP_Error( 'invalid_input', 'oa_id required', array( 'status' => 400 ) );
		}
		if ( ! class_exists( 'BizCity_Channel_Messages' ) ) {
			return rest_ensure_response( array( 'users' => array(), 'oa_id' => $oa_id ) );
		}

		$tbl        = BizCity_Channel_Messages::table();
		$chat_like  = $wpdb->esc_like( 'zalobot_' . $oa_id . '_' ) . '%';
		$scan_limit = max( 500, $limit * 20 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT id, chat_id, user_psid, direction, body, event_type, status, created_at
			 FROM {$tbl}
			 WHERE platform=%s AND chat_id LIKE %s
			 ORDER BY id DESC LIMIT %d",
			'ZALO_BOT', $chat_like, $scan_limit
		), ARRAY_A );

		$users = array();
		foreach ( $rows as $r ) {
			// Extract user_id from chat_id: 'zalobot_{oa_id}_{user_id}'.
			$uid = self::extract_user_id_from_chat_id( (string) $r['chat_id'], $oa_id );
			if ( $uid === '' ) {
				continue;
			}
			if ( ! isset( $users[ $uid ] ) ) {
				$dir          = (int) $r['direction'];
				$is_inbound   = ( $dir !== (int) BizCity_Channel_Messages::DIR_OUTBOUND );
				$display_name = self::get_contact_display_name( $uid, $oa_id );
				$users[ $uid ] = array(
					'user_id'       => $uid,
					'display_name'  => $display_name ?: ( 'Zalo ' . $uid ),
					'msg_count'     => 0,
					'last_text'     => (string) ( $r['body'] ?? '' ),
					'last_at'       => (string) ( $r['created_at'] ?? '' ),
					'last_inbound'  => $is_inbound,
					'chat_id'       => (string) ( $r['chat_id'] ?? '' ),
				);
			}
			$users[ $uid ]['msg_count']++;
			if ( (int) $r['id'] >= (int) ( $users[ $uid ]['_max_id'] ?? 0 ) ) {
				$users[ $uid ]['_max_id']    = (int) $r['id'];
				$dir_row                      = (int) $r['direction'];
				$users[ $uid ]['last_inbound'] = ( $dir_row !== (int) BizCity_Channel_Messages::DIR_OUTBOUND );
			}
		}

		// Remove private fields, reindex.
		$out = array();
		foreach ( $users as $u ) {
			unset( $u['_max_id'] );
			$out[] = $u;
			if ( count( $out ) >= $limit ) {
				break;
			}
		}

		return rest_ensure_response( array( 'users' => $out, 'oa_id' => $oa_id ) );
	}

	/* ──────────────────────────────────────────
	 * GET /zalo/conversation
	 * ────────────────────────────────────────── */

	/**
	 * Timeline for (oa_id, user_id) — ASC order for chat bubbles.
	 *
	 * @param WP_REST_Request $req {oa_id, user_id, limit?}
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_conversation( WP_REST_Request $req ) {
		global $wpdb;

		$oa_id   = trim( (string) $req->get_param( 'oa_id' ) );
		$user_id = trim( (string) $req->get_param( 'user_id' ) );
		$limit   = (int) $req->get_param( 'limit' );
		if ( $limit <= 0 || $limit > 500 ) {
			$limit = 200;
		}
		if ( $oa_id === '' || $user_id === '' ) {
			return new WP_Error( 'invalid_input', 'oa_id + user_id required', array( 'status' => 400 ) );
		}
		if ( ! class_exists( 'BizCity_Channel_Messages' ) ) {
			return rest_ensure_response( array( 'messages' => array(), 'oa_id' => $oa_id, 'user_id' => $user_id ) );
		}

		$tbl     = BizCity_Channel_Messages::table();
		$chat_id = 'zalobot_' . $oa_id . '_' . $user_id;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT id, direction, body, message_id, event_type, status, error,
			        payload_json, responder_kind, responder_user_id, created_at
			 FROM {$tbl}
			 WHERE platform=%s AND chat_id=%s
			 ORDER BY id DESC LIMIT %d",
			'ZALO_BOT', $chat_id, $limit
		), ARRAY_A );

		$rows = array_reverse( $rows ); // ASC for timeline render.

		$out = array();
		foreach ( $rows as $r ) {
			$dir      = (int) $r['direction'];
			$is_out   = ( $dir === (int) BizCity_Channel_Messages::DIR_OUTBOUND );
			$sender   = (int) ( $r['responder_user_id'] ?? 0 );
			$display  = '';
			if ( $sender > 0 ) {
				$u = get_userdata( $sender );
				if ( $u ) {
					$display = $u->display_name ?: $u->user_login;
				}
			}
			if ( ! $display ) {
				$display = $is_out ? 'Bot' : 'User';
			}
			$out[] = array(
				'id'           => (int) $r['id'],
				'role'         => $is_out ? 'assistant' : 'user',
				'event_name'   => (string) $r['event_type'],
				'text'         => (string) $r['body'],
				'display_name' => $display,
				'message_id'   => (string) $r['message_id'],
				'created_at'   => (string) $r['created_at'],
				'status'       => (string) $r['status'],
				'error'        => (string) $r['error'],
			);
		}

		return rest_ensure_response( array(
			'messages' => $out,
			'oa_id'    => $oa_id,
			'user_id'  => $user_id,
		) );
	}

	/* ──────────────────────────────────────────
	 * POST /zalo/admin-send
	 * ────────────────────────────────────────── */

	/**
	 * Admin sends a reply to a Zalo user.
	 *
	 * Logs to bizcity_channel_messages with responder_kind='manual'.
	 * Fires bizcity_listener_emit for live-tail in Channel Gateway monitor.
	 * FE ConversationPanel can optimistic-append using the returned message object.
	 *
	 * @param WP_REST_Request $req {oa_id, user_id, text}
	 * @return WP_REST_Response|WP_Error
	 */
	public static function admin_send( WP_REST_Request $req ) {
		$oa_id   = trim( (string) $req->get_param( 'oa_id' ) );
		$user_id = trim( (string) $req->get_param( 'user_id' ) );
		$text    = trim( (string) $req->get_param( 'text' ) );

		if ( $oa_id === '' || $user_id === '' || $text === '' ) {
			return new WP_Error( 'invalid_input', 'oa_id + user_id + text required', array( 'status' => 400 ) );
		}

		// Send via unified Gateway Sender (tries OA integration registry first — ZA-2).
		if ( ! class_exists( 'BizCity_Gateway_Sender' ) ) {
			return new WP_Error( 'gateway_unavailable', 'BizCity_Gateway_Sender not loaded.', array( 'status' => 503 ) );
		}

		$chat_id = 'zalobot_' . $oa_id . '_' . $user_id;
		$result  = BizCity_Gateway_Sender::instance()->send( $chat_id, $text, 'text', array() );

		if ( ! ( $result['sent'] ?? false ) ) {
			$err_msg = (string) ( $result['error'] ?? 'Gửi tin thất bại.' );
			return new WP_Error( 'send_failed', $err_msg, array( 'status' => 400 ) );
		}

		// Log to unified ledger.
		$wp_user_id  = get_current_user_id();
		$display     = $wp_user_id ? ( wp_get_current_user()->display_name ?: 'admin' ) : 'admin';
		$message_id  = (string) ( $result['mid'] ?? ( 'admin-' . wp_generate_uuid4() ) );
		$row_id      = 0;

		if ( class_exists( 'BizCity_Channel_Messages' ) ) {
			$row_id = (int) BizCity_Channel_Messages::log_outbound( array(
				'platform'          => 'ZALO_BOT',
				'chat_id'           => $chat_id,
				'user_psid'         => $user_id,
				'event_type'        => 'admin_send',
				'body'              => $text,
				'message_id'        => $message_id,
				'responder_kind'    => 'manual',
				'responder_user_id' => $wp_user_id,
				'payload'           => array(
					'oa_id'      => $oa_id,
					'from'       => 'admin',
					'wp_user_id' => $wp_user_id,
					'sent_at'    => current_time( 'mysql', true ),
				),
				'status' => 'sent',
			) );
		}

		// Emit for live-tail in Channel Gateway monitor.
		do_action( 'bizcity_listener_emit', array(
			'kind'       => 'outbound',
			'platform'   => 'ZALO_BOT',
			'account_id' => $oa_id,
			'user_id'    => $user_id,
			'chat_id'    => $chat_id,
			'event_type' => 'admin_send',
			'direction'  => 'out',
			'message'    => $text,
			'status'     => 'ok',
			'meta'       => array(
				'source'       => 'zalo_admin_send',
				'message_id'   => $message_id,
				'display_name' => $display,
				'wp_user_id'   => $wp_user_id,
			),
		) );

		return rest_ensure_response( array(
			'success'    => true,
			'message_id' => $message_id,
			'log_id'     => $row_id,
			'message'    => array(
				'id'           => $row_id ?: $message_id,
				'role'         => 'assistant',
				'event_name'   => 'admin_send',
				'text'         => $text,
				'display_name' => $display,
				'message_id'   => $message_id,
				'created_at'   => current_time( 'mysql' ),
			),
		) );
	}

	/* ──────────────────────────────────────────
	 * Helpers
	 * ────────────────────────────────────────── */

	/**
	 * Extract user_id from chat_id 'zalobot_{oa_id}_{user_id}'.
	 */
	private static function extract_user_id_from_chat_id( string $chat_id, string $oa_id ): string {
		$prefix = 'zalobot_' . $oa_id . '_';
		if ( strpos( $chat_id, $prefix ) === 0 ) {
			return substr( $chat_id, strlen( $prefix ) );
		}
		return '';
	}

	/**
	 * Try to get display_name from bizcity_crm_contacts by zalo_user_id.
	 * Returns empty string if CRM not available.
	 */
	private static function get_contact_display_name( string $zalo_user_id, string $oa_id ): string {
		if ( ! class_exists( 'BizCity_CRM_DB_Installer' ) ) {
			return '';
		}
		if ( ! method_exists( 'BizCity_CRM_DB_Installer', 'tbl_contacts' ) ) {
			return '';
		}
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer::tbl_contacts();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$name = $wpdb->get_var( $wpdb->prepare(
			"SELECT name FROM `{$tbl}`
			 WHERE JSON_UNQUOTE(JSON_EXTRACT(additional_attributes,'$.zalo_user_id')) = %s
			   AND deleted_at IS NULL
			 LIMIT 1",
			$zalo_user_id
		) );
		return $name ? (string) $name : '';
	}

	public static function perm_admin(): bool {
		return current_user_can( 'manage_options' );
	}
}
