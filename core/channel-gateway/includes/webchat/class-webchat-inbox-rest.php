<?php
/**
 * WebChat Inbox REST — admin SPA bubble UI backend (PHASE 0.36 W4)
 *
 * Read-only endpoints scoped to the current blog. Powers
 * `routes/platform/webchat/WebChatInbox.jsx` (Channel Gateway SPA admin view).
 *
 * Routes (namespace: bizcity/cg/v1):
 *   GET /webchat/sessions?status=active|all&limit=50&search=<q>
 *     → [{ session_id, title, client_name, character_id, message_count,
 *          last_message_at, last_message_preview, status }]
 *   GET /webchat/messages?session_id=<sid>&limit=200
 *     → [{ id, message_id, from, body, type, attachments, created_at }]
 *
 * Reply path: FE posts to existing /bizcity/cg/v1/inbox/send with
 *   chat_id='webchat_<session_id>', platform='WEBCHAT'.
 *   (Inbox Send REST already short-circuits binding lookup for WEBCHAT.)
 *
 * Permission: `bizcity_inbox_reply_cap` filter (default `edit_posts`) —
 *   matches the composer route so anyone who can reply can read threads.
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway\WebChat
 * @since PHASE 0.36 W4
 */

defined( 'ABSPATH' ) || exit;

class BizCity_WebChat_Inbox_REST {

	const NAMESPACE_V1 = 'bizcity-channel/v1';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route( self::NAMESPACE_V1, '/webchat/sessions', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_sessions' ),
			'permission_callback' => array( __CLASS__, 'can_read' ),
		) );
		register_rest_route( self::NAMESPACE_V1, '/webchat/messages', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_messages' ),
			'permission_callback' => array( __CLASS__, 'can_read' ),
		) );
	}

	public static function can_read(): bool {
		$cap = (string) apply_filters( 'bizcity_inbox_reply_cap', 'edit_posts' );
		return current_user_can( $cap );
	}

	/* ─────────────────────────── /webchat/sessions ─────────────────────────── */

	public static function rest_sessions( WP_REST_Request $req ) {
		global $wpdb;

		$status = (string) $req->get_param( 'status' );
		$limit  = (int)    $req->get_param( 'limit' );
		$search = trim( (string) $req->get_param( 'search' ) );

		if ( $limit <= 0 || $limit > 200 ) { $limit = 50; }
		$status = in_array( $status, array( 'active', 'closed', 'archived', 'all' ), true ) ? $status : 'active';

		$table_s = $wpdb->prefix . 'bizcity_webchat_sessions';
		$table_m = $wpdb->prefix . 'bizcity_webchat_messages';

		$has_sessions = ( bizcity_tbl_exists( $table_s ) ); // [2026-06-21 Johnny Chu] R-SHOW-TABLES

		$rows = array();

		if ( $has_sessions ) {
			$where  = array( "platform_type = 'WEBCHAT'" );
			$params = array();
			if ( $status !== 'all' ) {
				$where[] = 'status = %s';
				$params[] = $status;
			}
			if ( $search !== '' ) {
				$where[]  = '(title LIKE %s OR client_name LIKE %s OR session_id LIKE %s OR last_message_preview LIKE %s)';
				$like     = '%' . $wpdb->esc_like( $search ) . '%';
				$params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
			}
			$where_sql = implode( ' AND ', $where );

			$sql = "SELECT id, session_id, title, client_name, character_id,
			               message_count, last_message_at, last_message_preview, status,
			               UNIX_TIMESTAMP(last_message_at) AS last_ts
			        FROM {$table_s}
			        WHERE {$where_sql}
			        ORDER BY (last_message_at IS NULL), last_message_at DESC
			        LIMIT %d";
			$params[] = $limit;

			$prepared = $wpdb->prepare( $sql, $params );
			$rows     = $wpdb->get_results( $prepared, ARRAY_A ) ?: array();
		} else {
			// Fallback: derive sessions from messages table when v3 sessions table absent.
			if ( ! bizcity_tbl_exists( $table_m ) ) { // [2026-06-21 Johnny Chu] R-SHOW-TABLES
				return new WP_REST_Response( array( 'ok' => true, 'data' => array() ), 200 );
			}
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT session_id,
				        MAX(client_name)    AS client_name,
				        COUNT(*)            AS message_count,
				        MAX(created_at)     AS last_message_at,
				        SUBSTRING(MAX(CONCAT(LPAD(id, 12, '0'), message_text)), 13, 200) AS last_message_preview,
				        UNIX_TIMESTAMP(MAX(created_at)) AS last_ts
				 FROM {$table_m}
				 WHERE platform_type = 'WEBCHAT'
				 GROUP BY session_id
				 ORDER BY last_message_at DESC
				 LIMIT %d",
				$limit
			), ARRAY_A ) ?: array();

			foreach ( $rows as &$r ) {
				$r['id']           = 0;
				$r['title']        = '';
				$r['character_id'] = 0;
				$r['status']       = 'active';
			}
			unset( $r );
		}

		// Decorate with binding character_id when row lacks one (binding is per site).
		$blog_id = (string) get_current_blog_id();
		$binding_cid = 0;
		if ( class_exists( 'BizCity_Channel_Binding' ) ) {
			$b = BizCity_Channel_Binding::resolve( 'WEBCHAT', $blog_id );
			if ( $b && ! empty( $b['character_id'] ) ) {
				$binding_cid = (int) $b['character_id'];
			}
		}

		$out = array();
		foreach ( $rows as $r ) {
			$cid = (int) ( $r['character_id'] ?? 0 );
			if ( $cid <= 0 ) { $cid = $binding_cid; }

			$out[] = array(
				'session_id'           => (string) $r['session_id'],
				'title'                => (string) ( $r['title'] ?? '' ),
				'client_name'          => (string) ( $r['client_name'] ?? '' ),
				'character_id'         => $cid,
				'message_count'        => (int) ( $r['message_count'] ?? 0 ),
				'last_message_at'      => (string) ( $r['last_message_at'] ?? '' ),
				'last_ts'              => (int) ( $r['last_ts'] ?? 0 ),
				'last_message_preview' => (string) ( $r['last_message_preview'] ?? '' ),
				'status'               => (string) ( $r['status'] ?? 'active' ),
			);
		}

		return new WP_REST_Response( array( 'ok' => true, 'data' => $out ), 200 );
	}

	/* ─────────────────────────── /webchat/messages ─────────────────────────── */

	public static function rest_messages( WP_REST_Request $req ) {
		global $wpdb;

		$session_id = trim( (string) $req->get_param( 'session_id' ) );
		$limit      = (int) $req->get_param( 'limit' );
		if ( $limit <= 0 || $limit > 500 ) { $limit = 200; }

		if ( $session_id === '' ) {
			return new WP_REST_Response( array( 'ok' => false, 'message' => 'session_id required' ), 400 );
		}

		$table = $wpdb->prefix . 'bizcity_webchat_messages';
		if ( ! bizcity_tbl_exists( $table ) ) { // [2026-06-21 Johnny Chu] R-SHOW-TABLES
			return new WP_REST_Response( array( 'ok' => true, 'data' => array() ), 200 );
		}

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, message_id, message_text, message_from, message_type,
			        client_name, attachments, created_at,
			        UNIX_TIMESTAMP(created_at) AS created_ts
			 FROM {$table}
			 WHERE session_id = %s AND platform_type = 'WEBCHAT'
			 ORDER BY id ASC
			 LIMIT %d",
			$session_id,
			$limit
		), ARRAY_A ) ?: array();

		$out = array();
		foreach ( $rows as $r ) {
			$atts = array();
			if ( ! empty( $r['attachments'] ) ) {
				$decoded = json_decode( (string) $r['attachments'], true );
				if ( is_array( $decoded ) ) { $atts = $decoded; }
			}
			$out[] = array(
				'id'         => (int) $r['id'],
				'message_id' => (string) $r['message_id'],
				'from'       => (string) $r['message_from'],
				'body'       => (string) $r['message_text'],
				'type'       => (string) ( $r['message_type'] ?: 'text' ),
				'client'     => (string) $r['client_name'],
				'attachments'=> $atts,
				'created_at' => (string) $r['created_at'],
				'created_ts' => (int) $r['created_ts'],
			);
		}

		return new WP_REST_Response( array( 'ok' => true, 'data' => $out ), 200 );
	}
}

BizCity_WebChat_Inbox_REST::init();
