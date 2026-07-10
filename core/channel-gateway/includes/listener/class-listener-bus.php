<?php
/**
 * Listener Bus — in-memory ring buffer for live channel/automation tail
 *
 * SHIPPED 2026-05-29 (Phase CG-Listener S1).
 *
 * Purpose:
 *   Provide a single pub/sub surface so admins can watch incoming/outgoing
 *   messages across ALL Zalo bots, FB pages, WebChat sessions in real-time
 *   from a single Listener UI. Also shared with `core/automation` debug mode
 *   (runner emits per-node events when workflow meta has `debug:1`).
 *
 * Storage:
 *   - WP option `bizcity_listener_ring` (autoload=no) — JSON array capped at
 *     RING_MAX events. Monotonic id from option `bizcity_listener_next_id`.
 *   - Acceptable to lose 1 event under high concurrency (this is a live
 *     monitor, not an audit log — the canonical audit lives in
 *     `wp-content/hook-logs/` and `bizcity-cg-logs/`).
 *
 * Public API:
 *   BizCity_Listener_Bus::init() : void
 *   BizCity_Listener_Bus::emit( array $event ) : int       — monotonic id
 *   BizCity_Listener_Bus::tail( int $since_id, array $filters = [], int $limit = 100 ) : array
 *   BizCity_Listener_Bus::head_id() : int
 *   BizCity_Listener_Bus::clear() : void
 *
 * Event envelope (canonical):
 *   {
 *     id:           int,            // monotonic
 *     ts:           float,          // microtime(true)
 *     kind:         'inbound'|'outbound'|'automation'|'system',
 *     platform:     'ZALO_BOT'|'FB_MESS'|'FB_FEED'|'WEBCHAT'|'AUTOMATION'|...,
 *     account_id:   string,         // bot id / page id / site id / workflow_id
 *     user_id:      string,         // psid / zalo_user_id / session_id / run_id
 *     chat_id:      string,         // composite (UCL convention)
 *     event_type:   string,         // 'message'|'image'|'comment'|'node.ok'|...
 *     direction:    'in'|'out',
 *     message:      string,         // text preview (truncated 1000)
 *     character_id: int|null,       // Guru bound
 *     workflow_id:  int|null,       // when kind=automation
 *     run_id:       string|null,    // automation run id
 *     node_id:      string|null,    // automation node
 *     status:       'ok'|'fail'|'skip'|null,
 *     meta:         array,          // any extra
 *   }
 *
 * R-CH-NS compliance: REST routes use `bizcity-channel/v1/listener/*`
 * (see class-listener-rest.php).
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway
 * @since 1.6.0 (Phase CG-Listener S1)
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Listener_Bus {

	const OPTION_RING    = 'bizcity_listener_ring';
	const OPTION_NEXT_ID = 'bizcity_listener_next_id';
	const RING_MAX       = 200;
	const MESSAGE_CAP    = 1000;
	const RAW_CAP        = 4000;

	private static $booted = false;

	// [2026-06-11 Johnny Chu] R-PERF — in-request cache cho OPTION_RING (đọc 3-4x/webhook request)
	// Shape: [ $key => mixed ]  (key = OPTION_RING hoặc OPTION_NEXT_ID)
	private static $option_cache = array();

	/**
	 * Read a ring-storage option network-wide on multisite, blog-scoped on single site.
	 *
	 * Webhooks land on the bot's home subsite (e.g. blog 1258) but the admin SPA
	 * polls /listener/feed on the main site (blog 1). Without network scoping the
	 * two sides read different option rows and the live tail stays empty even
	 * though webhooks fire. See bizcity-zalo-bot/class-webhook-handler::handle_zalohook.
	 *
	 * [2026-06-11 Johnny Chu] R-PERF — added in-request static cache layer on top.
	 */
	private static function ring_get_option( string $key, $default ) {
		if ( array_key_exists( $key, self::$option_cache ) ) {
			return self::$option_cache[ $key ];
		}
		if ( is_multisite() ) {
			$val = get_network_option( null, $key, $default );
		} else {
			$val = get_option( $key, $default );
		}
		self::$option_cache[ $key ] = $val;
		return $val;
	}

	private static function ring_update_option( string $key, $value ): bool {
		// [2026-06-11 Johnny Chu] R-PERF — update cache khi write
		self::$option_cache[ $key ] = $value;
		if ( is_multisite() ) {
			return (bool) update_network_option( null, $key, $value );
		}
		return (bool) update_option( $key, $value, false );
	}

	public static function init(): void {
		if ( self::$booted ) { return; }
		self::$booted = true;

		// Subscribe to canonical hooks fired by Universal Listener + Gateway Sender.
		add_action( 'bizcity_channel_normalized',       array( __CLASS__, 'on_inbound' ),        20, 2 );
		add_action( 'bizcity_channel_outbound_logged',  array( __CLASS__, 'on_outbound' ),       20, 1 );

		// Direct emit hook for ad-hoc callers (Automation runner debug mode).
		add_action( 'bizcity_listener_emit',            array( __CLASS__, 'on_direct_emit' ),    10, 1 );

		// Facebook publisher events (best-effort — these may or may not exist).
		add_action( 'bizcity_fb_publish_ok',            array( __CLASS__, 'on_fb_publish_ok' ),  10, 1 );
		add_action( 'bizcity_fb_publish_failed',        array( __CLASS__, 'on_fb_publish_fail' ),10, 1 );

		// Zalo Bot direct taps — UCL `bizcity_channel_normalized` only fires when
		// `waic_twf_process_flow` is invoked with a registered trigger_key string.
		// The new Zalo Bot webhook handler (plugins/bizcity-zalo-bot) calls
		// `bizcity_gateway_fire_trigger($trigger, $data)` which passes the
		// trigger array as first arg → UCL early-returns → listener never sees it.
		// We subscribe directly at both intake + message level so the live tail
		// works regardless of trigger-routing health.
		add_action( 'bizcity_zalo_webhook_intake',      array( __CLASS__, 'on_zalo_intake' ),    10, 3 );
		add_action( 'bizcity_zalo_message_received',    array( __CLASS__, 'on_zalo_message' ),   10, 1 );
	}

	/* ─────────────────────────── Hook handlers ─────────────────────────── */

	/**
	 * @param array  $envelope Universal listener normalized payload.
	 * @param string $trigger_key Original WAIC trigger key.
	 */
	public static function on_inbound( $envelope, $trigger_key = '' ): void {
		if ( ! is_array( $envelope ) ) { return; }
		self::emit( array(
			'kind'         => 'inbound',
			'platform'     => (string) ( $envelope['platform']     ?? '' ),
			'account_id'   => (string) ( $envelope['account_id']   ?? '' ),
			'user_id'      => (string) ( $envelope['user_id']      ?? '' ),
			'chat_id'      => (string) ( $envelope['chat_id']      ?? '' ),
			'event_type'   => (string) ( $envelope['event_type']   ?? 'message' ),
			'direction'    => 'in',
			'message'      => (string) ( $envelope['message']      ?? '' ),
			'character_id' => isset( $envelope['character_id'] ) ? (int) $envelope['character_id'] : null,
			'meta'         => array(
				'trigger_key'        => (string) $trigger_key,
				'webhook_log_id'     => $envelope['webhook_log_id']   ?? null,
				'webhook_log_date'   => $envelope['webhook_log_date'] ?? null,
				'channel_message_id' => $envelope['channel_message_id'] ?? null,
				'binding_mode'       => $envelope['binding_mode']     ?? null,
				'responder_kind'     => $envelope['responder_kind']   ?? null,
			),
		) );
	}

	/**
	 * @param array $args Outbound payload (chat_id, message, platform, sent, ...).
	 */
	public static function on_outbound( $args ): void {
		if ( ! is_array( $args ) ) { return; }
		$platform   = (string) ( $args['platform'] ?? '' );
		$chat_id    = (string) ( $args['chat_id']  ?? '' );
		$message    = (string) ( $args['message']  ?? '' );
		$sent       = ! empty( $args['sent'] );
		list( $account_id, $user_id ) = self::split_chat_id( $platform, $chat_id );

		self::emit( array(
			'kind'         => 'outbound',
			'platform'     => $platform,
			'account_id'   => $account_id,
			'user_id'      => $user_id,
			'chat_id'      => $chat_id,
			'event_type'   => 'message',
			'direction'    => 'out',
			'message'      => $message,
			'status'       => $sent ? 'ok' : 'fail',
			'character_id' => isset( $args['character_id'] ) ? (int) $args['character_id'] : null,
			'meta'         => array(
				'responder_kind' => $args['responder_kind'] ?? null,
				'source'         => $args['source']         ?? null,
				'error'          => $args['error']          ?? null,
			),
		) );
	}

	/**
	 * Direct emit from arbitrary callers (Automation runner debug).
	 *
	 * @param array $event Partial event envelope; missing fields filled by emit().
	 */
	public static function on_direct_emit( $event ): void {
		if ( ! is_array( $event ) ) { return; }
		self::emit( $event );
	}

	public static function on_fb_publish_ok( $args ): void {
		if ( ! is_array( $args ) ) { return; }
		self::emit( array(
			'kind'       => 'outbound',
			'platform'   => 'FB_FEED',
			'account_id' => (string) ( $args['page_id'] ?? '' ),
			'user_id'    => '',
			'chat_id'    => 'fb_' . (string) ( $args['page_id'] ?? '' ) . '_publish',
			'event_type' => 'publish',
			'direction'  => 'out',
			'message'    => (string) ( $args['message'] ?? $args['title'] ?? '' ),
			'status'     => 'ok',
			'meta'       => array(
				'post_id'    => $args['post_id']    ?? null,
				'event_id'   => $args['event_id']   ?? null,
				'fb_post_id' => $args['fb_post_id'] ?? null,
			),
		) );
	}

	public static function on_fb_publish_fail( $args ): void {
		if ( ! is_array( $args ) ) { return; }
		self::emit( array(
			'kind'       => 'outbound',
			'platform'   => 'FB_FEED',
			'account_id' => (string) ( $args['page_id'] ?? '' ),
			'user_id'    => '',
			'chat_id'    => 'fb_' . (string) ( $args['page_id'] ?? '' ) . '_publish',
			'event_type' => 'publish',
			'direction'  => 'out',
			'message'    => (string) ( $args['message'] ?? '' ),
			'status'     => 'fail',
			'meta'       => array(
				'reason'   => $args['reason']   ?? null,
				'fb_code'  => $args['fb_code']  ?? null,
				'error'    => $args['error']    ?? null,
				'event_id' => $args['event_id'] ?? null,
			),
		) );
	}

	/**
	 * Zalo Bot intake — fired at the very top of /zalohook before any routing.
	 *
	 * Signature: ($data, $secret_token, $intake_bot)
	 *   $data         = decoded JSON payload (new or legacy format)
	 *   $secret_token = HTTP_X_BOT_API_SECRET_TOKEN value
	 *   $intake_bot   = resolved bot row {id, bot_name} or null
	 *
	 * Captures BOTH new format (`event_name` + `message.*`) and legacy
	 * (`event` + `conversation`/`client_id`).
	 */
	public static function on_zalo_intake( $data, $secret_token = '', $intake_bot = null ): void {
		if ( ! is_array( $data ) ) { return; }

		// New Zalo Bot API format
		$event_name = isset( $data['event_name'] ) ? (string) $data['event_name'] : '';
		$message    = isset( $data['message'] ) && is_array( $data['message'] ) ? $data['message'] : array();

		$bot_id   = $intake_bot && isset( $intake_bot->id )       ? (string) $intake_bot->id       : '';
		$bot_name = $intake_bot && isset( $intake_bot->bot_name ) ? (string) $intake_bot->bot_name : '';

		$user_id      = '';
		$display_name = '';
		$message_id   = '';
		$text         = '';
		$msg_kind     = 'message';

		if ( $event_name && $message ) {
			$user_id      = (string) ( $message['from']['id']           ?? '' );
			$display_name = (string) ( $message['from']['display_name'] ?? '' );
			$message_id   = (string) ( $message['message_id']           ?? '' );
			$text         = (string) ( $message['text']                 ?? ( $message['caption'] ?? '' ) );

			// event_name patterns: message.text.received / message.image.received / message.file.received / follow / unfollow
			$parts    = explode( '.', $event_name );
			$msg_kind = isset( $parts[1] ) ? (string) $parts[1] : $event_name;
		} else {
			// Legacy encrypted format
			$conv         = isset( $data['conversation'] ) && is_array( $data['conversation'] ) ? $data['conversation'] : array();
			$user_id      = (string) ( $data['client_id']    ?? '' );
			$display_name = (string) ( $data['client_name']  ?? '' );
			$message_id   = (string) ( $data['message']['message_id'] ?? '' );
			$text         = (string) ( $conv['last_message'] ?? '' );
			$msg_kind     = (string) ( $data['event']        ?? 'webhook' );
		}

		$chat_id = $bot_id !== '' && $user_id !== ''
			? 'zalobot_' . $bot_id . '_' . $user_id
			: '';

		self::emit( array(
			'kind'       => 'inbound',
			'platform'   => 'ZALO_BOT',
			'account_id' => $bot_id,
			'user_id'    => $user_id,
			'chat_id'    => $chat_id,
			'event_type' => $msg_kind,
			'direction'  => 'in',
			'message'    => $text !== '' ? $text : ( '[' . ( $event_name ?: 'webhook' ) . ']' ),
			'meta'       => array(
				'source'       => 'zalo_intake',
				'event_name'   => $event_name,
				'message_id'   => $message_id,
				'display_name' => $display_name,
				'bot_name'     => $bot_name,
				'has_secret'   => ! empty( $secret_token ),
			),
		) );
	}

	/**
	 * Zalo Bot message-received — fired after bot resolution + DB log.
	 * Mostly redundant with intake but captures the legacy path explicitly.
	 *
	 * Signature: ($message_data) where $message_data is the trigger array.
	 */
	public static function on_zalo_message( $message_data ): void {
		if ( ! is_array( $message_data ) ) { return; }
		$bot_id  = (string) ( $message_data['bot_id']       ?? '' );
		$user_id = (string) ( $message_data['from_user_id'] ?? '' );
		$chat_id = $bot_id !== '' && $user_id !== ''
			? 'zalobot_' . $bot_id . '_' . $user_id
			: '';

		self::emit( array(
			'kind'       => 'inbound',
			'platform'   => 'ZALO_BOT',
			'account_id' => $bot_id,
			'user_id'    => $user_id,
			'chat_id'    => $chat_id,
			'event_type' => (string) ( $message_data['message_type'] ?? 'message' ),
			'direction'  => 'in',
			'message'    => (string) ( $message_data['message_text'] ?? '' ),
			'meta'       => array(
				'source'       => 'zalo_message_received',
				'event_name'   => (string) ( $message_data['event_name']    ?? '' ),
				'message_id'   => (string) ( $message_data['message_id']    ?? '' ),
				'display_name' => (string) ( $message_data['from_user_name'] ?? '' ),
				'bot_name'     => (string) ( $message_data['bot_name']       ?? '' ),
			),
		) );
	}

	/* ─────────────────────────── Core: emit ─────────────────────────── */

	/**
	 * Push an event onto the ring buffer.
	 *
	 * @return int Monotonic id (0 on failure).
	 */
	public static function emit( array $event ): int {
		// Allow filters to mutate or veto.
		$event = apply_filters( 'bizcity_listener_event_pre', $event );
		if ( empty( $event ) || ! is_array( $event ) ) { return 0; }

		$id = self::next_id();
		if ( $id <= 0 ) { return 0; }

		$normalized = array(
			'id'           => $id,
			'ts'           => microtime( true ),
			// PHASE CG-Log-Paths Rule LOG-3: every event carries blog_id for per-site filtering.
			'blog_id'      => (int) get_current_blog_id(),
			'kind'         => self::sanitize_kind( $event['kind'] ?? 'system' ),
			'platform'     => isset( $event['platform']   ) ? (string) $event['platform']   : '',
			'account_id'   => isset( $event['account_id'] ) ? (string) $event['account_id'] : '',
			'user_id'      => isset( $event['user_id']    ) ? (string) $event['user_id']    : '',
			'chat_id'      => isset( $event['chat_id']    ) ? (string) $event['chat_id']    : '',
			'event_type'   => isset( $event['event_type'] ) ? (string) $event['event_type'] : 'message',
			'direction'    => self::sanitize_direction( $event['direction'] ?? '' ),
			'message'      => self::truncate( (string) ( $event['message'] ?? '' ), self::MESSAGE_CAP ),
			'character_id' => isset( $event['character_id'] ) && $event['character_id'] !== null ? (int) $event['character_id'] : null,
			'workflow_id'  => isset( $event['workflow_id'] )  && $event['workflow_id']  !== null ? (int) $event['workflow_id']  : null,
			'run_id'       => isset( $event['run_id'] )       ? (string) $event['run_id'] : null,
			'node_id'      => isset( $event['node_id'] )      ? (string) $event['node_id'] : null,
			'status'       => isset( $event['status'] )       ? (string) $event['status']  : null,
			'meta'         => isset( $event['meta'] ) && is_array( $event['meta'] ) ? $event['meta'] : array(),
		);

		// Push to ring with trim.
		$ring   = self::ring_get_option( self::OPTION_RING, array() );
		if ( ! is_array( $ring ) ) { $ring = array(); }
		$ring[] = $normalized;
		if ( count( $ring ) > self::RING_MAX ) {
			$ring = array_slice( $ring, -self::RING_MAX );
		}
		self::ring_update_option( self::OPTION_RING, $ring );

		do_action( 'bizcity_listener_event_emitted', $normalized );
		return $id;
	}

	/**
	 * Tail events newer than `$since_id`, optionally filtered.
	 *
	 * Filters supported:
	 *   - platform      : string | string[]
	 *   - account_id    : string
	 *   - user_id       : string
	 *   - kind          : 'inbound'|'outbound'|'automation'|'system'
	 *   - workflow_id   : int
	 *   - run_id        : string
	 *   - chat_id       : string
	 *   - q             : string substring on `message`
	 *
	 * @return array{events: array, head_id: int}
	 */
	public static function tail( int $since_id = 0, array $filters = array(), int $limit = 100 ): array {
		$ring = self::ring_get_option( self::OPTION_RING, array() );
		if ( ! is_array( $ring ) ) { $ring = array(); }
		$head = self::head_id();
		if ( ! $ring ) {
			return array( 'events' => array(), 'head_id' => $head );
		}

		$platforms = array();
		if ( isset( $filters['platform'] ) ) {
			$platforms = is_array( $filters['platform'] )
				? array_filter( array_map( 'strval', $filters['platform'] ) )
				: array_filter( array_map( 'trim', explode( ',', (string) $filters['platform'] ) ) );
		}
		$account_id  = isset( $filters['account_id'] )  ? (string) $filters['account_id']  : '';
		$user_id     = isset( $filters['user_id'] )     ? (string) $filters['user_id']     : '';
		$kinds = array();
		if ( isset( $filters['kind'] ) ) {
			$kinds = is_array( $filters['kind'] )
				? array_filter( array_map( 'strval', $filters['kind'] ) )
				: array_filter( array_map( 'trim', explode( ',', (string) $filters['kind'] ) ) );
		}
		$workflow_id = isset( $filters['workflow_id'] ) ? (int) $filters['workflow_id']    : 0;
		$run_id      = isset( $filters['run_id'] )      ? (string) $filters['run_id']      : '';
		$chat_id     = isset( $filters['chat_id'] )     ? (string) $filters['chat_id']     : '';
		$q           = isset( $filters['q'] )           ? mb_strtolower( (string) $filters['q'] ) : '';
		// PHASE CG-Log-Paths Rule LOG-3: filter by blog_id when provided.
		$blog_id_filter = isset( $filters['blog_id'] ) ? (int) $filters['blog_id'] : 0;

		$out = array();
		foreach ( $ring as $ev ) {
			if ( ! is_array( $ev ) || empty( $ev['id'] ) ) { continue; }
			if ( $since_id > 0 && (int) $ev['id'] <= $since_id ) { continue; }
			// Blog-scope filter: skip events from other sites.
			if ( $blog_id_filter > 0 && (int) ( $ev['blog_id'] ?? 0 ) !== $blog_id_filter ) { continue; }
			$ev_kind = (string) ( $ev['kind'] ?? '' );

			// kind filter is the canonical channel selector; if the caller
			// explicitly wants `twin` it must be in the kind list.
			if ( $kinds && ! in_array( $ev_kind, $kinds, true ) ) { continue; }

			// Twin/automation events are SEMI-scope-less: platform is synthetic
			// (TWIN/AUTOMATION) so platform filter is irrelevant, but chat_id /
			// account_id / user_id ARE populated when the source pipeline knows
			// them (twin tap inherits từ bridge_ctx, automation logger từ run).
			// [2026-06-02 Johnny Chu] PG-MULTISITE-LEAK — fix cross-conversation
			// rò: chỉ bypass conversation filter NẾU event không có scope field
			// tương ứng. Nếu có chat_id=X mà caller hỏi chat_id=Y → skip.
			$is_synthetic = ( $ev_kind === 'twin' || $ev_kind === 'automation' );

			if ( ! $is_synthetic ) {
				if ( $platforms && ! in_array( (string) ( $ev['platform'] ?? '' ), $platforms, true ) ) { continue; }
				if ( $account_id  !== '' && (string) ( $ev['account_id']  ?? '' ) !== $account_id  ) { continue; }
				if ( $user_id     !== '' && (string) ( $ev['user_id']     ?? '' ) !== $user_id     ) { continue; }
				if ( $chat_id     !== '' && (string) ( $ev['chat_id']     ?? '' ) !== $chat_id     ) { continue; }
			} else {
				$ev_acct = (string) ( $ev['account_id'] ?? '' );
				$ev_user = (string) ( $ev['user_id']    ?? '' );
				$ev_chat = (string) ( $ev['chat_id']    ?? '' );
				if ( $account_id !== '' && $ev_acct !== '' && $ev_acct !== $account_id ) { continue; }
				if ( $user_id    !== '' && $ev_user !== '' && $ev_user !== $user_id    ) { continue; }
				if ( $chat_id    !== '' && $ev_chat !== '' && $ev_chat !== $chat_id    ) { continue; }
			}
			if ( $workflow_id  >  0 && (int)    ( $ev['workflow_id']  ?? 0 )  !== $workflow_id ) { continue; }
			if ( $run_id      !== '' && (string) ( $ev['run_id']      ?? '' ) !== $run_id      ) { continue; }
			if ( $q !== '' && mb_strpos( mb_strtolower( (string) ( $ev['message'] ?? '' ) ), $q ) === false ) { continue; }
			$out[] = $ev;
			if ( count( $out ) >= max( 1, min( 500, $limit ) ) ) { break; }
		}
		return array( 'events' => $out, 'head_id' => $head );
	}

	public static function head_id(): int {
		$ring = self::ring_get_option( self::OPTION_RING, array() );
		if ( ! is_array( $ring ) || ! $ring ) { return 0; }
		$last = end( $ring );
		return is_array( $last ) ? (int) ( $last['id'] ?? 0 ) : 0;
	}

	public static function clear(): void {
		self::ring_update_option( self::OPTION_RING,    array() );
		self::ring_update_option( self::OPTION_NEXT_ID, 0       );
	}

	/* ─────────────────────────── Internals ─────────────────────────── */

	private static function next_id(): int {
		$id = (int) self::ring_get_option( self::OPTION_NEXT_ID, 0 ) + 1;
		if ( $id < 1 ) { $id = 1; }
		self::ring_update_option( self::OPTION_NEXT_ID, $id );
		return $id;
	}

	private static function sanitize_kind( string $kind ): string {
		$allowed = array( 'inbound', 'outbound', 'automation', 'system', 'twin' );
		return in_array( $kind, $allowed, true ) ? $kind : 'system';
	}

	private static function sanitize_direction( string $dir ): string {
		return ( $dir === 'in' || $dir === 'out' ) ? $dir : '';
	}

	private static function truncate( string $s, int $cap ): string {
		if ( $cap <= 0 || mb_strlen( $s ) <= $cap ) { return $s; }
		return mb_substr( $s, 0, $cap - 1 ) . '…';
	}

	/**
	 * Reverse-derive (account_id, user_id) from the composite chat_id produced
	 * by `BizCity_Universal_Channel_Listener::compose_chat_id()`. Best-effort.
	 *
	 * @return array{0:string,1:string}
	 */
	private static function split_chat_id( string $platform, string $chat_id ): array {
		$prefix_map = array(
			'FB_MESS'  => 'fb_',
			'FB_FEED'  => 'fb_',
			'ZALO_BOT' => 'zalobot_',
			'WEBCHAT'  => 'webchat_',
		);
		$prefix = $prefix_map[ $platform ] ?? '';
		if ( $prefix && strpos( $chat_id, $prefix ) === 0 ) {
			$tail = substr( $chat_id, strlen( $prefix ) );
			$parts = explode( '_', $tail, 2 );
			if ( $platform === 'WEBCHAT' ) {
				return array( '', $parts[0] ?? '' );
			}
			return array( $parts[0] ?? '', $parts[1] ?? '' );
		}
		return array( '', '' );
	}
}
