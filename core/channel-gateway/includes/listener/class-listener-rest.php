<?php
/**
 * Listener REST — live tail feed + SSE stream
 *
 * SHIPPED 2026-05-29 (Phase CG-Listener S1).
 *
 * Namespace: `bizcity-channel/v1/listener/*` (R-CH-NS compliant).
 *
 * Routes:
 *   GET  /listener/feed?since=&platform=&account_id=&user_id=&kind=&workflow_id=&run_id=&chat_id=&q=&limit=
 *        → { ok:true, events:[…], head_id:int }
 *   GET  /listener/stream?…same filters…
 *        → text/event-stream, ticks every 1s for up to 30s; sends `data: {events,head_id}` per tick.
 *   POST /listener/test-emit  { kind, platform?, account_id?, user_id?, message? }
 *        → { ok:true, id:int }  (smoke test for UI wiring + Diagnostic probe).
 *   POST /listener/clear
 *        → { ok:true }  (purge ring buffer; admin tooling only).
 *
 * All routes require `manage_options`.
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway
 * @since 1.6.0 (Phase CG-Listener S1)
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Listener_REST {

	const NS = 'bizcity-channel/v1';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route( self::NS, '/listener/feed', array(
			'methods'             => 'GET',
			'permission_callback' => array( __CLASS__, 'can_admin' ),
			'callback'            => array( __CLASS__, 'route_feed' ),
		) );

		register_rest_route( self::NS, '/listener/stream', array(
			'methods'             => 'GET',
			'permission_callback' => array( __CLASS__, 'can_admin' ),
			'callback'            => array( __CLASS__, 'route_stream' ),
		) );

		register_rest_route( self::NS, '/listener/test-emit', array(
			'methods'             => 'POST',
			'permission_callback' => array( __CLASS__, 'can_admin' ),
			'callback'            => array( __CLASS__, 'route_test_emit' ),
		) );

		register_rest_route( self::NS, '/listener/clear', array(
			'methods'             => 'POST',
			'permission_callback' => array( __CLASS__, 'can_admin' ),
			'callback'            => array( __CLASS__, 'route_clear' ),
		) );
	}

	public static function can_admin( $request ): bool {
		return current_user_can( 'manage_options' );
	}

	/* ─────────────────────────── Handlers ─────────────────────────── */

	public static function route_feed( WP_REST_Request $req ) {
		$since   = (int) $req->get_param( 'since' );
		$limit   = (int) ( $req->get_param( 'limit' ) ?: 100 );
		$filters = self::build_filters( $req );
		$out     = BizCity_Listener_Bus::tail( $since, $filters, $limit );
		return rest_ensure_response( array(
			'ok'      => true,
			'events'  => $out['events'],
			'head_id' => $out['head_id'],
		) );
	}

	public static function route_stream( WP_REST_Request $req ) {
		$since   = (int) $req->get_param( 'since' );
		$filters = self::build_filters( $req );

		// SSE response — bypass normal REST encoding.
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/event-stream; charset=utf-8' );
			header( 'Cache-Control: no-cache, no-store, must-revalidate' );
			header( 'X-Accel-Buffering: no' );
		}
		// Disable WP/REST output buffering so flushes hit the wire.
		while ( ob_get_level() > 0 ) { @ob_end_flush(); }
		@ignore_user_abort( false );

		$started = microtime( true );
		$tick_ms = 1000;        // 1s polling on the ring
		$max_s   = 30;          // max 30s per request (FE will reconnect)
		$last_id = $since;

		// Initial hello so client knows the stream is live.
		echo "event: hello\n";
		echo 'data: ' . wp_json_encode( array(
			'ok'      => true,
			'since'   => $last_id,
			'head_id' => BizCity_Listener_Bus::head_id(),
			'ts'      => microtime( true ),
		) ) . "\n\n";
		@flush();

		while ( ( microtime( true ) - $started ) < $max_s ) {
			if ( connection_aborted() ) { break; }
			$tail = BizCity_Listener_Bus::tail( $last_id, $filters, 50 );
			if ( ! empty( $tail['events'] ) ) {
				foreach ( $tail['events'] as $ev ) {
					echo "event: message\n";
					echo 'data: ' . wp_json_encode( $ev ) . "\n\n";
					if ( ! empty( $ev['id'] ) ) { $last_id = (int) $ev['id']; }
				}
				@flush();
			} else {
				// Heartbeat so proxies don't kill idle connection.
				echo ": ping " . (int) ( microtime( true ) * 1000 ) . "\n\n";
				@flush();
			}
			usleep( $tick_ms * 1000 );
		}

		// Bye marker so client knows to reconnect with new `since`.
		echo "event: bye\n";
		echo 'data: ' . wp_json_encode( array( 'head_id' => $last_id ) ) . "\n\n";
		@flush();
		exit;
	}

	public static function route_test_emit( WP_REST_Request $req ) {
		$body = (array) $req->get_json_params();
		$ev   = array(
			'kind'       => isset( $body['kind'] )       ? (string) $body['kind']       : 'system',
			'platform'   => isset( $body['platform'] )   ? (string) $body['platform']   : 'AUTOMATION',
			'account_id' => isset( $body['account_id'] ) ? (string) $body['account_id'] : 'test',
			'user_id'    => isset( $body['user_id'] )    ? (string) $body['user_id']    : 'admin',
			'chat_id'    => isset( $body['chat_id'] )    ? (string) $body['chat_id']    : 'test_admin',
			'event_type' => isset( $body['event_type'] ) ? (string) $body['event_type'] : 'test',
			'direction'  => isset( $body['direction'] )  ? (string) $body['direction']  : '',
			'message'    => isset( $body['message'] )    ? (string) $body['message']    : 'Listener test emit @ ' . wp_date( 'H:i:s' ),
			'meta'       => array( 'source' => 'rest.test-emit', 'by' => get_current_user_id() ),
		);
		$id = BizCity_Listener_Bus::emit( $ev );
		return rest_ensure_response( array( 'ok' => $id > 0, 'id' => $id ) );
	}

	public static function route_clear( WP_REST_Request $req ) {
		BizCity_Listener_Bus::clear();
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/* ─────────────────────────── Internals ─────────────────────────── */

	private static function build_filters( WP_REST_Request $req ): array {
		$filters = array();
		foreach ( array( 'platform', 'account_id', 'user_id', 'kind', 'run_id', 'chat_id', 'q' ) as $k ) {
			$v = $req->get_param( $k );
			if ( $v !== null && $v !== '' ) { $filters[ $k ] = $v; }
		}
		$wid = (int) $req->get_param( 'workflow_id' );
		if ( $wid > 0 ) { $filters['workflow_id'] = $wid; }

		// [2026-06-02 Johnny Chu] R-CH-NS / multisite-listener — blog scoping.
		//
		// Channel webhooks (Zalo bot /zalohook, FB webhook…) land on whichever
		// subsite happens to own the rewrite route (often bot home blog, e.g.
		// 1258). `emit()` tags every event với `blog_id = get_current_blog_id()`.
		// Admin SPA lại poll `/listener/feed` từ blog admin đang mở (vd main
		// blog 1) → nếu auto-scope theo current blog thì 100% events Zalo bị
		// loại ra dù ring storage đã network-wide.
		//
		// Quy tắc mới (multisite-aware):
		//   • Caller explicit ?blog_id=<N>   → respect (nhưng vẫn check
		//     super-admin nếu N≠current để tránh peek site khác).
		//   • Caller pass channel scope (platform / account_id / chat_id)
		//     → KHÔNG auto blog scope. Channel scope đã đủ chặt vì account_id
		//     unique cross-network và permission_callback đã yêu cầu
		//     manage_options.
		//   • Caller request workflow_id / run_id → cũng KHÔNG auto blog scope
		//     (automation events synthetic, kind=automation/twin bypass scope
		//     trong tail() rồi).
		//   • Nếu không có scope nào → giữ behaviour cũ (chỉ events blog hiện
		//     tại) để admin general listener không lộ cross-site noise.
		$req_blog = $req->get_param( 'blog_id' );
		if ( $req_blog !== null && $req_blog !== '' ) {
			$want = (int) $req_blog;
			if ( $want === 0 ) {
				// Explicit cross-site: only super-admins.
				if ( ! is_super_admin() ) {
					$filters['blog_id'] = (int) get_current_blog_id();
				}
				// else: no blog_id filter → show all blogs.
			} else {
				if ( $want !== (int) get_current_blog_id() && ! is_super_admin() ) {
					$filters['blog_id'] = (int) get_current_blog_id();
				} else {
					$filters['blog_id'] = $want;
				}
			}
		} else {
			$has_channel_scope = ! empty( $filters['platform'] )
				|| ! empty( $filters['account_id'] )
				|| ! empty( $filters['chat_id'] )
				|| ! empty( $filters['workflow_id'] )
				|| ! empty( $filters['run_id'] );
			if ( ! $has_channel_scope ) {
				// Bare query → keep legacy per-blog default to avoid leaking
				// other sites' system/inbound noise into a general admin tail.
				$filters['blog_id'] = (int) get_current_blog_id();
			}
			// else: scoped query → cross-blog by design (Zalo bot blog ≠ admin blog).
		}
		return $filters;
	}
}
