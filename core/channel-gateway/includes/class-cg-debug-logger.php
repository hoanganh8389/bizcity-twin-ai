<?php
/**
 * Channel Gateway — Debug Logger
 *
 * JSON-Lines logger để truy vết pipeline:
 *   Webhook in → waic_twf_process_flow → Campaign_Tracker (visit) →
 *   Scenario_Dispatcher (dispatch) → Outbound message → FB Publisher.
 *
 * Storage:
 *   wp-content/uploads/[sites/{blog_id}/]bizcity-cg-logs/YYYY-MM-DD.jsonl
 *
 * (Đa site: wp_upload_dir() đã tự chèn /sites/{blog_id}/ nên multisite hoạt
 * động không cần code thêm.)
 *
 * UI:
 *   - Tools → "BizCity CG Debug Logs" (admin page render JSON pretty)
 *   - REST GET /bizcity/cg/v1/debug-logs?date=YYYY-MM-DD&channel=...&q=...&limit=500
 *
 * Bảo mật:
 *   - Folder log đẻ kèm `.htaccess deny from all` + `index.html` empty.
 *   - REST yêu cầu `manage_options`.
 *   - Body payload có thể chứa token / PII → mask qua mask_sensitive().
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway
 * @since 2026-05-25
 */

defined( 'ABSPATH' ) || exit;

class BizCity_CG_Debug_Logger {

	const NAMESPACE_V1 = 'bizcity-channel/v1';
	const LOG_DIRNAME  = 'bizcity-cg-logs';
	const OPTION_FLAG  = 'bizcity_cg_debug_logger_enabled';

	/** @var string Cached log dir for current request. */
	private static $cached_dir = '';

	/* ---------- Boot ---------- */

	public static function init(): void {
		// REST routes.
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );

		// Admin page.
		if ( is_admin() ) {
			add_action( 'admin_menu', array( __CLASS__, 'register_admin_page' ) );
		}

		// Skip auto-hook if disabled (set bizcity_cg_debug_logger_enabled = '0' via wp option).
		if ( get_option( self::OPTION_FLAG, '1' ) !== '1' ) {
			return;
		}

		// Tap pipeline hooks. Priority 1 → run before consumers / catch even nếu consumer fatal.
		add_action( 'waic_twf_process_flow', array( __CLASS__, 'on_twf_flow' ), 1, 2 );

		// FB Messenger referral-only events (handle_referral() fires this, NOT waic_twf_process_flow).
		add_action( 'bizcity_facebook_referral_received', array( __CLASS__, 'on_fb_referral_received' ), 1, 1 );

		// Zalo Hotline / BizCity ZNS outbound (bizhook) — channel = 'bizhook'.
		add_action( 'bizcity_zalo_hotline_sent',   array( __CLASS__, 'on_bizhook_sent' ),  1, 1 );
		add_action( 'bizcity_zalo_zns_sent',       array( __CLASS__, 'on_bizhook_sent' ),  1, 1 );
		add_action( 'bizcity_bizhook_intake',      array( __CLASS__, 'on_bizhook_intake' ), 1, 2 );

		// Webhook Router intake — logs raw FB/Zalo/Webchat POST before REST (catches ?fbhook=1, /bizfbhook/, etc.).
		add_action( 'bizcity_webhook_router_intake', array( __CLASS__, 'on_webhook_router_intake' ), 1, 3 );

		// Zalo Bot — raw webhook intake (fired by class-webhook-handler::handle_zalohook),
		// inbound message normalized event, generic webhook event router.
		add_action( 'bizcity_zalo_webhook_intake',    array( __CLASS__, 'on_zalo_webhook_intake' ),  1, 3 );
		add_action( 'bizcity_zalo_message_received',  array( __CLASS__, 'on_zalo_message_received' ), 1, 1 );
		add_action( 'bizcity_zalo_bot_webhook_event', array( __CLASS__, 'on_zalo_bot_event' ),       1, 3 );

		// [2026-06-19 Johnny Chu] PHASE-CG-CF7-LOG — CF7 form submission → cf7 channel file log
		add_action( 'wpcf7_mail_sent',   array( __CLASS__, 'on_cf7_mail_sent' ),   1, 1 );
		add_action( 'wpcf7_mail_failed', array( __CLASS__, 'on_cf7_mail_failed' ), 1, 1 );

		// CRM event emitter dispatches actions: bizcity_crm_event_{name}.
		add_action( 'bizcity_crm_event_crm_campaign_visit_recorded',     array( __CLASS__, 'on_campaign_visit' ),    1 );
		add_action( 'bizcity_crm_event_crm_campaign_scenario_dispatched',array( __CLASS__, 'on_scenario_dispatch' ), 1 );
		add_action( 'bizcity_crm_event_crm_campaign_reminder_sent',      array( __CLASS__, 'on_reminder_sent' ),     1 );
		add_action( 'bizcity_crm_event_crm_message_received',            array( __CLASS__, 'on_message_received' ),  1 );
		add_action( 'bizcity_crm_event_crm_message_sent',                array( __CLASS__, 'on_message_sent' ),      1 );

		// Scheduler reminder (FB Publisher trigger).
		add_action( 'bizcity_scheduler_reminder_fire', array( __CLASS__, 'on_reminder_fire' ), 1, 1 );

		// REST-level capture of inbound webhook (FB / Zalo) via rest_pre_dispatch.
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'on_rest_dispatch' ), 1, 3 );
	}

	/* ---------- Public log API ---------- */

	/**
	 * Ghi 1 row JSON.
	 *
	 * @param string $channel  Bucket: 'webhook'|'twf_flow'|'campaign_visit'|'scenario_dispatch'|...
	 * @param string $event    Event subtype (free text).
	 * @param array  $data     Arbitrary structured data (sẽ mask token).
	 * @param string $level    'debug'|'info'|'warn'|'error'.
	 */
	public static function log( string $channel, string $event, array $data = array(), string $level = 'info' ): void {
		$dir = self::ensure_dir();
		if ( $dir === '' ) { return; }

		$row = array(
			'ts'      => gmdate( 'c' ),
			'ts_unix' => microtime( true ),
			'blog_id' => is_multisite() ? get_current_blog_id() : 1,
			'level'   => $level,
			'channel' => $channel,
			'event'   => $event,
			'pid'     => function_exists( 'getmypid' ) ? getmypid() : 0,
			'data'    => self::mask_sensitive( $data ),
		);

		$line = wp_json_encode( $row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n";
		if ( $line === false || $line === "\n" ) { return; }

		$file = $dir . '/' . gmdate( 'Y-m-d' ) . '.jsonl';
		// LOCK_EX để tránh interleave khi 2 request đồng thời.
		@file_put_contents( $file, $line, FILE_APPEND | LOCK_EX );

		// [2026-06-19 Johnny Chu] PHASE-CG-CF7-LOG — R-CH-FILE-LOG: also write to per-channel file.
		// This single call covers ALL channels (facebook/zalo_bot/zalo_oa/messenger/telegram/webchat/cf7).
		// Requires class-channel-file-logger.php to be loaded before this file (bootstrap order).
		if ( class_exists( 'BizCity_Channel_File_Logger', false ) ) {
			$physical_ch = self::map_to_physical_channel( $channel, $data );
			BizCity_Channel_File_Logger::write( $physical_ch, $level, $event, '', $row['data'] );
		}
	}

	/**
	 * Map a CG logger internal channel bucket to a canonical physical channel name.
	 *
	 * CG logger channel = an event-category string ('twf_flow.facebook', 'zalo_message_in', …).
	 * Physical channel  = the platform folder name ('facebook', 'zalo_bot', 'zalo_oa', …).
	 *
	 * [2026-06-19 Johnny Chu] PHASE-CG-CF7-LOG — R-CH-FILE-LOG
	 *
	 * @param string $channel  CG logger internal channel string.
	 * @param array  $data     Masked event data — may contain 'platform' hint.
	 * @return string          One of BizCity_Channel_File_Logger::CH_* values.
	 */
	private static function map_to_physical_channel( string $channel, array $data ): string {
		// Exact / prefix matches (fast path).
		$exact_map = array(
			'fb_webhook_raw'   => 'facebook',
			'fb_referral'      => 'facebook',
			'zalo_webhook_raw' => 'zalo_bot',
			'zalo_message_in'  => 'zalo_bot',
			'zalo_event'       => 'zalo_bot',
			'bizhook'          => 'zalo_oa',
			// [2026-06-25 Johnny Chu] PHASE-CG-CF7-ZNS — ZNS dedicated channel
			'zalo_zns'         => 'zalo_zns',
			'email'            => 'email',
			'cf7'              => 'cf7',
			'telegram'         => 'telegram',
		);
		if ( isset( $exact_map[ $channel ] ) ) {
			return $exact_map[ $channel ];
		}

		// Substring matches for compound channel strings (twf_flow.*, twinchat.*).
		if ( strpos( $channel, 'messenger' ) !== false )   { return 'messenger'; }
		if ( strpos( $channel, 'twinchat' ) !== false )    { return 'webchat'; }
		if ( strpos( $channel, 'webchat' ) !== false )     { return 'webchat'; }
		if ( strpos( $channel, 'telegram' ) !== false )    { return 'telegram'; }
		if ( strpos( $channel, 'facebook' ) !== false )    { return 'facebook'; }
		if ( strpos( $channel, 'fb_' ) !== false )         { return 'facebook'; }
		if ( strpos( $channel, 'zalo_oa' ) !== false )     { return 'zalo_oa'; }
		// [2026-06-25 Johnny Chu] PHASE-CG-CF7-ZNS — match zalo_zns BEFORE generic zalo
		if ( strpos( $channel, 'zalo_zns' ) !== false )    { return 'zalo_zns'; }
		if ( strpos( $channel, 'zalo' ) !== false )        { return 'zalo_bot'; }
		if ( strpos( $channel, 'email' ) !== false )       { return 'email'; }
		if ( strpos( $channel, 'cf7' ) !== false )         { return 'cf7'; }

		// Fallback: try platform hint in data
		$platform = strtolower( (string) (
			$data['platform']
			?? ( is_array( $data['envelope'] ?? null ) ? ( $data['envelope']['platform'] ?? '' ) : '' )
			?? ( is_array( $data['payload'] ?? null )  ? ( $data['payload']['platform']  ?? '' ) : '' )
			?? ''
		) );
		if ( strpos( $platform, 'messenger' ) !== false )  { return 'messenger'; }
		if ( strpos( $platform, 'facebook' ) !== false || strpos( $platform, 'fb_' ) !== false ) {
			return 'facebook';
		}
		if ( strpos( $platform, 'zalo_oa' ) !== false )    { return 'zalo_oa'; }
		if ( strpos( $platform, 'zalo' ) !== false )       { return 'zalo_bot'; }
		if ( strpos( $platform, 'telegram' ) !== false )   { return 'telegram'; }
		if ( strpos( $platform, 'webchat' ) !== false )    { return 'webchat'; }

		return BizCity_Channel_File_Logger::CH_CHANNEL_GATEWAY;
	}

	/* ---------- Hook handlers ---------- */

	public static function on_twf_flow( $key, $payload = array() ): void {
		// Derive source channel from trigger key for per-channel filtering.
		$key_lc  = strtolower( (string) $key );
		if ( str_contains( $key_lc, 'webchat' ) || str_contains( $key_lc, 'twinchat' ) ) {
			$src = 'twinchat';
		} elseif ( str_contains( $key_lc, 'facebook' ) || str_contains( $key_lc, '_fb_' ) ) {
			$src = 'twf_flow.facebook';
		} elseif ( str_contains( $key_lc, 'zalo' ) ) {
			$src = 'twf_flow.zalo';
		} else {
			$src = 'twf_flow';
		}
		self::log( $src, (string) $key, array(
			'payload_keys' => is_array( $payload ) ? array_keys( $payload ) : array(),
			'payload'      => self::trim_for_log( $payload ),
		) );
	}

	/**
	 * Zalo Hotline / ZNS outbound (bizhook channel).
	 */
	public static function on_bizhook_sent( $payload ): void {
		self::log( 'bizhook', 'zalo_hotline_sent', is_array( $payload ) ? self::trim_for_log( $payload ) : array( 'raw' => $payload ) );
	}

	/**
	 * Zalo Hotline / bizhook raw intake.
	 */
	public static function on_bizhook_intake( $data, $meta = array() ): void {
		$arr = is_array( $data ) ? self::trim_for_log( $data ) : array( 'raw' => $data );
		self::log( 'bizhook', 'bizhook_intake', array_merge( $arr, is_array( $meta ) ? $meta : array() ) );
	}

	public static function on_campaign_visit( $payload ): void {
		self::log( 'campaign_visit', 'crm_campaign_visit_recorded', is_array( $payload ) ? $payload : array( 'raw' => $payload ) );
	}

	public static function on_scenario_dispatch( $payload ): void {
		$arr   = is_array( $payload ) ? $payload : array();
		$level = ! empty( $arr['ok'] ) ? 'info' : 'warn';
		self::log( 'scenario_dispatch', 'crm_campaign_scenario_dispatched', $arr, $level );
	}

	public static function on_reminder_sent( $payload ): void {
		self::log( 'reminder', 'crm_campaign_reminder_sent', is_array( $payload ) ? $payload : array() );
	}

	public static function on_message_received( $payload ): void {
		self::log( 'message_in', 'crm_message_received', is_array( $payload ) ? self::trim_for_log( $payload ) : array() );
	}

	public static function on_message_sent( $payload ): void {
		self::log( 'message_out', 'crm_message_sent', is_array( $payload ) ? self::trim_for_log( $payload ) : array() );
	}

	public static function on_reminder_fire( $event ): void {
		self::log( 'scheduler', 'bizcity_scheduler_reminder_fire', is_array( $event ) ? $event : array( 'raw' => $event ) );
	}

	/**
	 * FB Messenger referral-only event (fired by bizcity-facebook-bot handle_referral()).
	 * Payload: { page_id, client_id (PSID), ref, ref_decrypted, input_data }.
	 */
	public static function on_fb_referral_received( $payload ): void {
		$arr = is_array( $payload ) ? $payload : array( 'raw' => $payload );
		self::log( 'fb_referral', 'bizcity_facebook_referral_received', $arr );
	}

	/**
	 * Raw webhook intake — fired by BizCity_Webhook_Router before adapter processes.
	 * $log_meta = { date, id }; $platform = 'FB_MESS'|...; $body = raw string.
	 */
	public static function on_webhook_router_intake( $log_meta, $platform, $body ): void {
		// [2026-06-19 Johnny Chu] R-CH-FILE-LOG — map Router platform key to the correct
		// internal channel bucket so map_to_physical_channel() routes JSONL to the right
		// per-channel folder (facebook/, zalo_bot/, zalo_oa/, webchat/, telegram/).
		// Before this fix every platform was logged as 'fb_webhook_raw' → all went to facebook/.
		$platform_to_channel = array(
			'FB_MESS'      => 'fb_webhook_raw',   // → facebook/
			'ZALO_BOT'     => 'zalo_webhook_raw', // → zalo_bot/
			'ZALO_HOTLINE' => 'bizhook',          // → zalo_oa/
			'WEBCHAT'      => 'twinchat',         // → webchat/
			'TELEGRAM'     => 'telegram',         // → telegram/
		);
		$channel = isset( $platform_to_channel[ $platform ] ) ? $platform_to_channel[ $platform ] : 'fb_webhook_raw';

		$parsed = array();
		if ( is_string( $body ) && $body !== '' ) {
			$decoded = json_decode( $body, true );
			$parsed  = is_array( $decoded ) ? self::trim_for_log( $decoded ) : array( '_raw_snippet' => substr( $body, 0, 500 ) );
		}
		self::log( $channel, 'webhook_router_intake', array(
			'platform'    => $platform,
			'log_row_id'  => is_array( $log_meta ) ? ( $log_meta['id'] ?? null ) : null,
			// [2026-07-08 Johnny Chu] HOTFIX — keep request telemetry so we can
			// distinguish empty-body webhook pings from real inbound payloads.
			'body_len'    => is_string( $body ) ? strlen( $body ) : 0,
			'method'      => isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : '',
			'content_len' => isset( $_SERVER['CONTENT_LENGTH'] ) ? (string) $_SERVER['CONTENT_LENGTH'] : '',
			'content_type'=> isset( $_SERVER['CONTENT_TYPE'] ) ? (string) $_SERVER['CONTENT_TYPE'] : '',
			'user_agent'  => isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( (string) $_SERVER['HTTP_USER_AGENT'], 0, 255 ) : '',
			'body_parsed' => $parsed,
		) );
	}

	/**
	 * Zalo Bot — raw `/zalohook/` POST intake.
	 *
	 * @param array       $data         Decoded JSON payload from Zalo.
	 * @param string|null $secret_token Value of `X-Bot-Api-Secret-Token` header (may be empty).
	 */
	public static function on_zalo_webhook_intake( $data, $secret_token = '', $bot = null ): void {
		$arr     = is_array( $data ) ? self::trim_for_log( $data ) : array( 'raw' => $data );
		$from    = is_array( $data ) && isset( $data['message']['from'] ) && is_array( $data['message']['from'] )
			? $data['message']['from'] : array();
		self::log( 'zalo_webhook_raw', 'zalohook_intake', array(
			'bot_id'         => is_object( $bot ) ? (int) ( $bot->id ?? 0 ) : 0,
			'bot_name'       => is_object( $bot ) ? ( $bot->bot_name ?? null ) : null,
			'has_secret'     => $secret_token !== '' && $secret_token !== null,
			'secret_matched' => is_object( $bot ),
			'event_name'     => is_array( $data ) ? ( $data['event_name'] ?? null ) : null,
			'from_user_id'   => isset( $from['id'] ) ? (string) $from['id'] : null,
			'from_name'      => isset( $from['display_name'] ) ? (string) $from['display_name'] : null,
			'payload'        => $arr,
		) );
	}

	/**
	 * Zalo inbound message (normalized) — fired by webhook handler after dispatch.
	 */
	public static function on_zalo_message_received( $message_data ): void {
		$arr = is_array( $message_data ) ? self::trim_for_log( $message_data ) : array( 'raw' => $message_data );
		self::log( 'zalo_message_in', 'bizcity_zalo_message_received', $arr );
	}

	/**
	 * CF7 form submission sent successfully.
	 * [2026-06-19 Johnny Chu] PHASE-CG-CF7-LOG — cf7 channel
	 */
	public static function on_cf7_mail_sent( $cf7 ): void {
		$form_id    = is_object( $cf7 ) && method_exists( $cf7, 'id' )    ? (int)    $cf7->id()    : 0;
		$form_title = is_object( $cf7 ) && method_exists( $cf7, 'title' ) ? (string) $cf7->title() : '';
		$sub        = class_exists( 'WPCF7_Submission' ) ? WPCF7_Submission::get_instance() : null;
		$posted     = $sub ? self::trim_for_log( (array) $sub->get_posted_data() ) : array();
		self::log( 'cf7', 'wpcf7_mail_sent', array(
			'form_id'    => $form_id,
			'form_title' => $form_title,
			'posted'     => $posted,
		), 'info' );
	}

	/**
	 * CF7 form submission failed (mail send error).
	 * [2026-06-19 Johnny Chu] PHASE-CG-CF7-LOG — cf7 channel
	 */
	public static function on_cf7_mail_failed( $cf7 ): void {
		$form_id    = is_object( $cf7 ) && method_exists( $cf7, 'id' )    ? (int)    $cf7->id()    : 0;
		$form_title = is_object( $cf7 ) && method_exists( $cf7, 'title' ) ? (string) $cf7->title() : '';
		self::log( 'cf7', 'wpcf7_mail_failed', array(
			'form_id'    => $form_id,
			'form_title' => $form_title,
		), 'error' );
	}

	/**
	 * Generic Zalo webhook event router (follow / unfollow / submit_action ...).
	 */
	public static function on_zalo_bot_event( $bot, $event_name, $data ): void {
		self::log( 'zalo_event', (string) $event_name, array(
			'bot_id'    => is_object( $bot ) ? ( $bot->id ?? null ) : null,
			'bot_name'  => is_object( $bot ) ? ( $bot->bot_name ?? null ) : null,
			'data'      => is_array( $data ) ? self::trim_for_log( $data ) : array( 'raw' => $data ),
		) );
	}

	/**
	 * Capture REST inbound — chỉ ghi route quan tâm để tránh log toàn bộ wp-json.
	 */
	public static function on_rest_dispatch( $result, $server, $request ) {
		$route = is_object( $request ) && method_exists( $request, 'get_route' ) ? (string) $request->get_route() : '';
		if ( $route === '' ) { return $result; }

		// Chỉ log webhook channels + bizcity-crm/v1 + bizcity-channel/v1 (loại trừ debug-logs endpoint để khỏi vòng lặp).
		$is_interesting = (
			strpos( $route, '/bizcity-channel/' ) === 0 ||
			strpos( $route, '/bizcity-crm/' ) === 0 ||
			strpos( $route, '/bizcity-webhook/' ) === 0
		);
		if ( ! $is_interesting ) { return $result; }

		// [2026-06-19 Johnny Chu] R-CH-FILE-LOG — map REST webhook route to correct
		// physical channel so JSONL goes to the right folder.
		// Also write BizCity_Webhook_Log for inbound webhook POSTs so admin SPA shows them.
		$method      = $request->get_method();
		$is_inbound  = ( $method === 'POST' ) && ( strpos( $route, '/webhook/' ) !== false );

		// Derive channel from route segments.
		if ( strpos( $route, '/webhook/zalo_oa' ) !== false ) {
			$channel  = 'zalo_oa_webhook';
			$platform = 'ZALO_OA';
		} elseif ( strpos( $route, '/webhook/telegram' ) !== false ) {
			$channel  = 'telegram';
			$platform = 'TELEGRAM';
		} elseif ( strpos( $route, '/webhook/facebook' ) !== false || strpos( $route, '/webhook/fb_' ) !== false ) {
			$channel  = 'fb_webhook_raw';
			$platform = 'FB_MESS';
		} elseif ( strpos( $route, '/webhook/webchat' ) !== false || strpos( $route, '/webhook/twinchat' ) !== false ) {
			$channel  = 'twinchat';
			$platform = 'WEBCHAT';
		} elseif ( strpos( $route, '/webhook/zalo_bot' ) !== false ) {
			$channel  = 'zalo_webhook_raw';
			$platform = 'ZALO_BOT';
		} else {
			$channel  = 'webhook';
			$platform = '';
		}

		self::log( $channel, $method . ' ' . $route, array(
			'method'   => $method,
			'route'    => $route,
			'platform' => $platform,
			'params'   => self::trim_for_log( $request->get_params() ),
			'headers'  => self::pick_headers( $request->get_headers() ),
		) );

		// For genuine inbound webhook POSTs (not admin SPA calls), also write
		// BizCity_Webhook_Log so the admin SPA (GET /logs?platform=...) shows them.
		if ( $is_inbound && $platform !== '' && class_exists( 'BizCity_Webhook_Log', false ) ) {
			BizCity_Webhook_Log::log( array(
				'platform'      => $platform,
				'endpoint'      => $route,
				'method'        => 'POST',
				'http_status'   => 200,
				'verify_status' => 'pending',
				'remote_ip'     => $_SERVER['REMOTE_ADDR'] ?? '',
				'user_agent'    => substr( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ), 0, 200 ),
				'body_raw'      => wp_json_encode( $request->get_params() ),
			) );
		}

		return $result;
	}

	/* ---------- REST routes ---------- */

	public static function register_routes(): void {
		register_rest_route( self::NAMESPACE_V1, '/debug-logs', array(
			'methods'             => 'GET',
			'permission_callback' => function () { return current_user_can( 'manage_options' ); },
			'callback'            => array( __CLASS__, 'rest_list' ),
			'args'                => array(
				'date'    => array( 'type' => 'string', 'required' => false ),
				'channel'     => array( 'type' => 'string',  'required' => false ),
				'level'       => array( 'type' => 'string',  'required' => false ),
				'q'           => array( 'type' => 'string',  'required' => false ),
				'campaign_id' => array( 'type' => 'integer', 'required' => false, 'default' => 0,
					'description' => 'Filter rows where data.campaign_id equals this OR webhook route contains /campaigns/{id}/.' ),
				'limit'       => array( 'type' => 'integer', 'default' => 500 ),
				'tail'        => array( 'type' => 'boolean', 'default' => true, 'description' => 'true = lấy N row cuối, false = N row đầu' ),
			),
		) );

		register_rest_route( self::NAMESPACE_V1, '/debug-logs/dates', array(
			'methods'             => 'GET',
			'permission_callback' => function () { return current_user_can( 'manage_options' ); },
			'callback'            => array( __CLASS__, 'rest_list_dates' ),
		) );

		register_rest_route( self::NAMESPACE_V1, '/debug-logs/clear', array(
			'methods'             => 'POST',
			'permission_callback' => function () { return current_user_can( 'manage_options' ); },
			'callback'            => array( __CLASS__, 'rest_clear' ),
			'args'                => array(
				'date' => array( 'type' => 'string', 'required' => true ),
			),
		) );

		register_rest_route( self::NAMESPACE_V1, '/debug-logs/test-emit', array(
			'methods'             => 'POST',
			'permission_callback' => function () { return current_user_can( 'manage_options' ); },
			'callback'            => function () {
				self::log( 'manual', 'test_emit', array( 'now' => time(), 'note' => 'admin clicked Test Emit' ) );
				return array( 'ok' => true );
			},
		) );

		// P1-Q3 (2026-05-26) — Threads view: same file, grouped by best-effort
		// thread key so each row in the UI = one inbound→outbound message turn.
		register_rest_route( self::NAMESPACE_V1, '/debug-logs/threads', array(
			'methods'             => 'GET',
			'permission_callback' => function () { return current_user_can( 'manage_options' ); },
			'callback'            => array( __CLASS__, 'rest_list_threads' ),
			'args'                => array(
				'date'        => array( 'type' => 'string',  'required' => false ),
				'channel'     => array( 'type' => 'string',  'required' => false ),
				'level'       => array( 'type' => 'string',  'required' => false ),
				'q'           => array( 'type' => 'string',  'required' => false ),
				'campaign_id' => array( 'type' => 'integer', 'required' => false, 'default' => 0 ),
				'limit'       => array( 'type' => 'integer', 'default' => 5000 ),
			),
		) );
	}

	public static function rest_list( WP_REST_Request $req ) {
		$date    = (string) ( $req->get_param( 'date' ) ?: gmdate( 'Y-m-d' ) );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return new WP_Error( 'bad_date', 'date must be YYYY-MM-DD', array( 'status' => 400 ) );
		}
		$channel     = (string) $req->get_param( 'channel' );
		$level       = (string) $req->get_param( 'level' );
		$q           = (string) $req->get_param( 'q' );
		$campaign_id = (int) $req->get_param( 'campaign_id' );
		$limit       = max( 1, min( 5000, (int) $req->get_param( 'limit' ) ) );
		$tail        = (bool) $req->get_param( 'tail' );

		// Pre-build campaign_id match strings for OR-logic:
		//   (a) data.campaign_id field in campaign_visit / scenario_dispatch events
		//   (b) REST route pattern  /campaigns/{id}/ in webhook events
		$cid_data_str  = $campaign_id > 0 ? ('"campaign_id":' . $campaign_id) : '';
		$cid_route_str = $campaign_id > 0 ? ('/campaigns/' . $campaign_id . '/') : '';

		$file = self::ensure_dir() . '/' . $date . '.jsonl';
		$rows = array();
		if ( ! file_exists( $file ) ) {
			return array( 'date' => $date, 'count' => 0, 'rows' => array(), 'file' => $file, 'exists' => false );
		}

		$fp = @fopen( $file, 'rb' );
		if ( ! $fp ) {
			return new WP_Error( 'io_error', 'cannot open log file', array( 'status' => 500 ) );
		}
		while ( ( $line = fgets( $fp ) ) !== false ) {
			$line = trim( $line );
			if ( $line === '' ) { continue; }
			$dec = json_decode( $line, true );
			if ( ! is_array( $dec ) ) { continue; }
			// Multi-channel support: comma-separated values (e.g. 'zalo_webhook_raw,zalo_bot_event').
			if ( $channel !== '' ) {
				$ch_list = array_filter( array_map( 'trim', explode( ',', $channel ) ) );
				if ( $ch_list && ! in_array( $dec['channel'] ?? '', $ch_list, true ) ) { continue; }
			}
			if ( $level !== ''   && ( $dec['level']   ?? '' ) !== $level )   { continue; }
			if ( $q !== '' && stripos( $line, $q ) === false ) { continue; }
			// campaign_id OR-filter: match data.campaign_id value OR webhook route path
			if ( $campaign_id > 0 ) {
				$ok = stripos( $line, $cid_data_str ) !== false
					|| stripos( $line, $cid_route_str ) !== false;
				if ( ! $ok ) { continue; }
			}
			$rows[] = $dec;
		}
		fclose( $fp );

		$total = count( $rows );
		if ( $tail ) {
			$rows = array_slice( $rows, -$limit );
		} else {
			$rows = array_slice( $rows, 0, $limit );
		}

		return array(
			'date'   => $date,
			'count'  => count( $rows ),
			'total'  => $total,
			'tail'   => $tail,
			'rows'   => array_values( $rows ),
			'file'   => self::relative_path( $file ),
			'exists' => true,
		);
	}

	public static function rest_list_dates() {
		$dir = self::ensure_dir();
		$out = array();
		if ( $dir && is_dir( $dir ) ) {
			foreach ( (array) glob( $dir . '/*.jsonl' ) as $f ) {
				$name = basename( $f, '.jsonl' );
				if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $name ) ) {
					$out[] = array(
						'date'  => $name,
						'bytes' => (int) @filesize( $f ),
					);
				}
			}
			usort( $out, function ( $a, $b ) { return strcmp( $b['date'], $a['date'] ); } );
		}
		return array( 'dir' => self::relative_path( $dir ), 'dates' => $out );
	}

	public static function rest_clear( WP_REST_Request $req ) {
		$date = (string) $req->get_param( 'date' );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return new WP_Error( 'bad_date', 'date required', array( 'status' => 400 ) );
		}
		$file = self::ensure_dir() . '/' . $date . '.jsonl';
		$ok   = file_exists( $file ) ? @unlink( $file ) : true;
		return array( 'ok' => (bool) $ok, 'date' => $date );
	}

	/**
	 * P1-Q3 — Group rows by best-effort "thread key" so the UI can render
	 * one row per message turn (instead of a flat console stream).
	 *
	 * Thread key resolution priority (first non-empty wins):
	 *   1. data.trace_id                     (preferred — set by replier/dispatcher when emitting)
	 *   2. data.conversation_id              (CRM message events)
	 *   3. data.payload.conversation_id      (nested in some wrappers)
	 *   4. data.client_id                    (FB PSID / Zalo user id)
	 *   5. data.payload.client_id
	 *   6. data.log_row_id                   (webhook intake)
	 *   7. data.body_parsed.entry[0].messaging[0].sender.id  (FB raw webhook)
	 *   8. '_misc' bucket
	 *
	 * Returns: { date, file, exists, total, group_count, threads:[
	 *     { key, kind, started_at, ended_at, channels, count, summary, rows[] }
	 * ] } sorted by started_at DESC (latest thread first).
	 */
	public static function rest_list_threads( WP_REST_Request $req ) {
		$date    = (string) ( $req->get_param( 'date' ) ?: gmdate( 'Y-m-d' ) );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return new WP_Error( 'bad_date', 'date must be YYYY-MM-DD', array( 'status' => 400 ) );
		}
		$channel     = (string) $req->get_param( 'channel' );
		$level       = (string) $req->get_param( 'level' );
		$q           = (string) $req->get_param( 'q' );
		$campaign_id = (int) $req->get_param( 'campaign_id' );
		$limit       = max( 100, min( 20000, (int) $req->get_param( 'limit' ) ) );

		$file = self::ensure_dir() . '/' . $date . '.jsonl';
		if ( ! file_exists( $file ) ) {
			return array( 'date' => $date, 'file' => self::relative_path( $file ), 'exists' => false, 'total' => 0, 'group_count' => 0, 'threads' => array() );
		}

		$cid_data_str  = $campaign_id > 0 ? ('"campaign_id":' . $campaign_id) : '';
		$cid_route_str = $campaign_id > 0 ? ('/campaigns/' . $campaign_id . '/') : '';

		$fp = @fopen( $file, 'rb' );
		if ( ! $fp ) {
			return new WP_Error( 'io_error', 'cannot open log file', array( 'status' => 500 ) );
		}
		$threads = array();
		$total   = 0;
		while ( ( $line = fgets( $fp ) ) !== false ) {
			$line = trim( $line );
			if ( $line === '' ) { continue; }
			$dec = json_decode( $line, true );
			if ( ! is_array( $dec ) ) { continue; }
			if ( $channel !== '' && ( $dec['channel'] ?? '' ) !== $channel ) { continue; }
			if ( $level   !== '' && ( $dec['level']   ?? '' ) !== $level   ) { continue; }
			if ( $q !== '' && stripos( $line, $q ) === false ) { continue; }
			if ( $campaign_id > 0 ) {
				if ( stripos( $line, $cid_data_str ) === false && stripos( $line, $cid_route_str ) === false ) { continue; }
			}
			$total++;
			$key  = self::resolve_thread_key( $dec );
			$kind = self::resolve_thread_kind( $key, $dec );
			if ( ! isset( $threads[ $key ] ) ) {
				$threads[ $key ] = array(
					'key'        => $key,
					'kind'       => $kind,
					'started_at' => $dec['ts'] ?? null,
					'ended_at'   => $dec['ts'] ?? null,
					'channels'   => array(),
					'count'      => 0,
					'summary'    => '',
					'rows'       => array(),
				);
			}
			$threads[ $key ]['ended_at'] = $dec['ts'] ?? $threads[ $key ]['ended_at'];
			$threads[ $key ]['count']++;
			$ch = (string) ( $dec['channel'] ?? '' );
			if ( $ch !== '' && ! in_array( $ch, $threads[ $key ]['channels'], true ) ) {
				$threads[ $key ]['channels'][] = $ch;
			}
			$threads[ $key ]['rows'][] = $dec;
		}
		fclose( $fp );

		// Build summary + sort.
		foreach ( $threads as $k => &$t ) {
			$t['summary'] = self::summarize_thread( $t );
		}
		unset( $t );
		usort( $threads, function ( $a, $b ) {
			return strcmp( (string) $b['started_at'], (string) $a['started_at'] );
		} );
		if ( count( $threads ) > $limit ) {
			$threads = array_slice( $threads, 0, $limit );
		}
		return array(
			'date'        => $date,
			'file'        => self::relative_path( $file ),
			'exists'      => true,
			'total'       => $total,
			'group_count' => count( $threads ),
			'threads'     => array_values( $threads ),
		);
	}

	/** @internal */
	private static function resolve_thread_key( array $row ): string {
		$d = $row['data'] ?? array();
		if ( ! is_array( $d ) ) { return '_misc'; }
		$candidates = array(
			$d['trace_id']                      ?? null,
			$d['conversation_id']               ?? null,
			$d['payload']['conversation_id']    ?? null,
			$d['envelope']['conversation_id']   ?? null,
			$d['client_id']                     ?? null,
			$d['payload']['client_id']          ?? null,
			$d['from_user_id']                  ?? null,
			$d['log_row_id']                    ?? null,
		);
		foreach ( $candidates as $c ) {
			if ( $c !== null && $c !== '' && $c !== 0 && $c !== '0' ) {
				return (string) $c;
			}
		}
		// FB raw nested.
		$fb = $d['body_parsed']['entry'][0]['messaging'][0]['sender']['id'] ?? null;
		if ( $fb ) { return 'fb_' . (string) $fb; }
		return '_misc';
	}

	/** @internal */
	private static function resolve_thread_kind( string $key, array $row ): string {
		if ( $key === '_misc' ) { return 'misc'; }
		if ( strpos( $key, 'fb_' ) === 0 ) { return 'messenger_raw'; }
		$d = $row['data'] ?? array();
		if ( is_array( $d ) ) {
			if ( isset( $d['conversation_id'] ) || isset( $d['payload']['conversation_id'] ) ) {
				return 'conversation';
			}
			if ( isset( $d['trace_id'] ) ) { return 'trace'; }
			if ( isset( $d['client_id'] ) || isset( $d['payload']['client_id'] ) ) {
				return 'client';
			}
		}
		return 'other';
	}

	/** @internal — one-line digest for the threads list. */
	private static function summarize_thread( array $t ): string {
		$last = end( $t['rows'] );
		$ev   = is_array( $last ) ? (string) ( $last['event'] ?? '' ) : '';
		$lvl  = is_array( $last ) ? (string) ( $last['level'] ?? '' ) : '';
		return trim( sprintf(
			'%dx · %s · last=%s%s',
			(int) $t['count'],
			implode( '+', (array) $t['channels'] ),
			$ev,
			$lvl && $lvl !== 'info' ? "[$lvl]" : ''
		) );
	}

	/* ---------- Admin page ---------- */

	public static function register_admin_page(): void {
		// [2026-06-10 Johnny Chu] HOTFIX — renamed 'BizCity Logs · CG' → 'BizCity Hook'.
		add_management_page(
			'BizCity Hook — Channel Gateway Logs',
			'BizCity Hook',
			'manage_options',
			'bizcity-cg-debug-logs',
			array( __CLASS__, 'render_admin_page' )
		);
	}

	public static function render_admin_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Forbidden' ); }
		$rest_root = esc_url_raw( rest_url( self::NAMESPACE_V1 ) );
		$nonce     = wp_create_nonce( 'wp_rest' );
		$today     = gmdate( 'Y-m-d' );
		?>
		<div class="wrap" id="bizcity-cg-debug-logs">
			<h1>BizCity CG · Debug Logs</h1>
			<p class="description">
				JSON-Lines logger. File: <code><?php echo esc_html( self::relative_path( self::ensure_dir() ) ); ?>/YYYY-MM-DD.jsonl</code>
				· Blog ID: <code><?php echo esc_html( is_multisite() ? get_current_blog_id() : 1 ); ?></code>
			</p>

			<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin:14px 0;">
				<label>Date <input type="date" id="cg-log-date" value="<?php echo esc_attr( $today ); ?>"></label>
				<label>Channel
					<select id="cg-log-channel">
						<option value="">(all)</option>
						<option>webhook</option>
						<option>twf_flow</option>
						<option>campaign_visit</option>
						<option>scenario_dispatch</option>
						<option>reminder</option>
						<option>scheduler</option>
						<option>message_in</option>
						<option>message_out</option>
						<option>manual</option>
					</select>
				</label>
				<label>Level
					<select id="cg-log-level">
						<option value="">(all)</option>
						<option>debug</option><option>info</option><option>warn</option><option>error</option>
					</select>
				</label>
				<label>Search <input type="text" id="cg-log-q" placeholder="full-text trên dòng JSON"></label>
				<label>Limit <input type="number" id="cg-log-limit" value="500" min="1" max="5000" style="width:80px"></label>
				<label><input type="checkbox" id="cg-log-tail" checked> Tail (cuối file)</label>
				<label><input type="checkbox" id="cg-log-auto"> Auto-reload 5s</label>
				<button class="button button-primary" id="cg-log-refresh">Reload</button>
				<button class="button" id="cg-log-test">Test emit</button>
				<button class="button" id="cg-log-clear">Clear date</button>
				<button class="button" id="cg-log-format-toggle">Toggle pretty</button>
			</div>

			<div id="cg-log-meta" style="margin-bottom:8px;color:#475569;font-size:12px;">—</div>

			<!-- P1-Q3: Tabs (Threads / Console) ───────────────────────────── -->
			<nav class="nav-tab-wrapper" style="margin-bottom:10px;">
				<a href="#" class="nav-tab nav-tab-active" data-cg-tab="threads">🧵 Threads (sheet)</a>
				<a href="#" class="nav-tab" data-cg-tab="console">📜 Console</a>
			</nav>

			<div id="cg-tab-threads" class="cg-tab-pane">
				<table class="widefat fixed striped" id="cg-thread-table" style="background:#fff;">
					<thead>
						<tr>
							<th style="width:32px;"></th>
							<th style="width:180px;">Started (UTC)</th>
							<th style="width:140px;">Kind</th>
							<th style="width:200px;">Key (conv / client / trace)</th>
							<th style="width:60px;">Rows</th>
							<th>Summary</th>
							<th style="width:90px;">Copy</th>
						</tr>
					</thead>
					<tbody><tr><td colspan="7" style="padding:14px;color:#64748b;">— loading —</td></tr></tbody>
				</table>
			</div>

			<div id="cg-tab-console" class="cg-tab-pane" style="display:none;">
				<pre id="cg-log-output" style="background:#0f172a;color:#e2e8f0;padding:14px;border-radius:6px;max-height:65vh;overflow:auto;font-family:Menlo,Consolas,monospace;font-size:12px;line-height:1.5;white-space:pre-wrap;word-break:break-word;"></pre>
			</div>

			<details style="margin-top:14px;">
				<summary>Lịch sử file (theo ngày)</summary>
				<div id="cg-log-dates" style="margin-top:8px;font-family:monospace;font-size:12px;">—</div>
			</details>
		</div>
		<script>
		(function () {
			const REST  = <?php echo wp_json_encode( $rest_root ); ?>;
			const NONCE = <?php echo wp_json_encode( $nonce ); ?>;
			let pretty   = true;
			let timer    = null;
			let activeTab = 'threads'; // P1-Q3: default Threads view

			const $ = (id) => document.getElementById(id);
			const get = (path, params) => {
				const url = new URL(REST + path, window.location.origin);
				Object.entries(params || {}).forEach(([k,v]) => { if (v !== '' && v !== false && v !== null && v !== undefined) url.searchParams.set(k, v); });
				return fetch(url.toString(), { headers: { 'X-WP-Nonce': NONCE } }).then(r => r.json());
			};
			const post = (path, body) => fetch(REST + path, {
				method: 'POST', headers: { 'X-WP-Nonce': NONCE, 'Content-Type': 'application/json' },
				body: JSON.stringify(body || {})
			}).then(r => r.json());

			function renderConsole(resp) {
				const meta = $('cg-log-meta');
				const out  = $('cg-log-output');
				if (resp && resp.code) {
					meta.textContent = 'ERROR: ' + resp.message;
					out.textContent = JSON.stringify(resp, null, 2);
					return;
				}
				const rows = (resp && resp.rows) || [];
				meta.textContent = `[console] date=${resp.date} · shown=${resp.count}/${resp.total} · file=${resp.file}`;
				out.textContent = pretty
					? rows.map(r => JSON.stringify(r, null, 2)).join('\n---\n')
					: rows.map(r => JSON.stringify(r)).join('\n');
				out.scrollTop = out.scrollHeight;
			}

			function escapeHtml(s) {
				return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
			}

			function renderThreads(resp) {
				const meta  = $('cg-log-meta');
				const tbody = $('cg-thread-table').querySelector('tbody');
				if (resp && resp.code) {
					meta.textContent = 'ERROR: ' + resp.message;
					tbody.innerHTML = `<tr><td colspan="7" style="padding:14px;color:#dc2626;">${escapeHtml(resp.message)}</td></tr>`;
					return;
				}
				const threads = (resp && resp.threads) || [];
				meta.textContent = `[threads] date=${resp.date} · groups=${resp.group_count} · rows=${resp.total} · file=${resp.file}`;
				if (!threads.length) {
					tbody.innerHTML = `<tr><td colspan="7" style="padding:14px;color:#64748b;">(no rows for filter)</td></tr>`;
					return;
				}
				const html = threads.map((t, idx) => {
					const channelsHtml = (t.channels || []).map(c => `<span style="display:inline-block;padding:1px 6px;margin-right:3px;background:#e0f2fe;color:#0369a1;border-radius:3px;font-size:11px;">${escapeHtml(c)}</span>`).join('');
					const keyShort = String(t.key).length > 28 ? String(t.key).slice(0, 28) + '…' : t.key;
					return `
						<tr class="cg-thread-row" data-idx="${idx}">
							<td><a href="#" class="cg-thread-toggle" data-idx="${idx}" title="Expand">▶</a></td>
							<td style="font-family:monospace;font-size:11px;">${escapeHtml(t.started_at || '')}</td>
							<td><code style="font-size:11px;">${escapeHtml(t.kind)}</code></td>
							<td title="${escapeHtml(t.key)}"><code style="font-size:11px;">${escapeHtml(keyShort)}</code></td>
							<td>${t.count}</td>
							<td>${channelsHtml} <span style="color:#475569;font-size:11px;">${escapeHtml(t.summary)}</span></td>
							<td><button class="button button-small cg-thread-copy" data-idx="${idx}">Copy</button></td>
						</tr>
						<tr class="cg-thread-detail" data-idx="${idx}" style="display:none;">
							<td colspan="7" style="padding:0;background:#0f172a;">
								<pre style="margin:0;padding:12px;color:#e2e8f0;font-family:Menlo,Consolas,monospace;font-size:11.5px;line-height:1.5;white-space:pre-wrap;word-break:break-word;max-height:50vh;overflow:auto;">${escapeHtml(t.rows.map(r => JSON.stringify(r, null, 2)).join('\n---\n'))}</pre>
							</td>
						</tr>
					`;
				}).join('');
				tbody.innerHTML = html;

				// Expand toggle.
				tbody.querySelectorAll('.cg-thread-toggle').forEach(a => {
					a.addEventListener('click', (e) => {
						e.preventDefault();
						const idx = a.getAttribute('data-idx');
						const det = tbody.querySelector(`tr.cg-thread-detail[data-idx="${idx}"]`);
						const open = det.style.display !== 'none';
						det.style.display = open ? 'none' : 'table-row';
						a.textContent = open ? '▶' : '▼';
					});
				});
				// Copy.
				tbody.querySelectorAll('.cg-thread-copy').forEach(btn => {
					btn.addEventListener('click', () => {
						const idx = +btn.getAttribute('data-idx');
						const text = (threads[idx].rows || []).map(r => JSON.stringify(r, null, 2)).join('\n---\n');
						navigator.clipboard.writeText(text).then(() => {
							btn.textContent = '✓ Copied';
							setTimeout(() => { btn.textContent = 'Copy'; }, 1500);
						});
					});
				});
			}

			function reload() {
				const params = {
					date:    $('cg-log-date').value,
					channel: $('cg-log-channel').value,
					level:   $('cg-log-level').value,
					q:       $('cg-log-q').value,
					limit:   $('cg-log-limit').value,
				};
				if (activeTab === 'threads') {
					get('/debug-logs/threads', params).then(renderThreads)
						.catch(e => { $('cg-log-meta').textContent = 'fetch error: ' + e; });
				} else {
					get('/debug-logs', Object.assign({ tail: $('cg-log-tail').checked }, params)).then(renderConsole)
						.catch(e => { $('cg-log-meta').textContent = 'fetch error: ' + e; });
				}
				get('/debug-logs/dates').then(r => {
					$('cg-log-dates').innerHTML = (r.dates || []).map(d =>
						`<div>${d.date} — ${(d.bytes/1024).toFixed(1)} KB <a href="#" data-date="${d.date}" class="cg-pick-date">[load]</a></div>`
					).join('') || '<em>(no logs yet)</em>';
					document.querySelectorAll('.cg-pick-date').forEach(a => {
						a.addEventListener('click', (e) => {
							e.preventDefault();
							$('cg-log-date').value = a.getAttribute('data-date');
							reload();
						});
					});
				});
			}

			// Tab switch.
			document.querySelectorAll('a.nav-tab[data-cg-tab]').forEach(a => {
				a.addEventListener('click', (e) => {
					e.preventDefault();
					activeTab = a.getAttribute('data-cg-tab');
					document.querySelectorAll('a.nav-tab[data-cg-tab]').forEach(x => x.classList.toggle('nav-tab-active', x === a));
					document.querySelectorAll('.cg-tab-pane').forEach(p => p.style.display = 'none');
					$('cg-tab-' + activeTab).style.display = '';
					reload();
				});
			});

			$('cg-log-refresh').addEventListener('click', reload);
			$('cg-log-test').addEventListener('click', () => post('/debug-logs/test-emit').then(reload));
			$('cg-log-clear').addEventListener('click', () => {
				if (!confirm('Xoá log của ngày ' + $('cg-log-date').value + '?')) return;
				post('/debug-logs/clear', { date: $('cg-log-date').value }).then(reload);
			});
			$('cg-log-format-toggle').addEventListener('click', () => { pretty = !pretty; reload(); });
			$('cg-log-auto').addEventListener('change', (e) => {
				if (timer) { clearInterval(timer); timer = null; }
				if (e.target.checked) { timer = setInterval(reload, 5000); }
			});

			reload();
		})();
		</script>
		<?php
	}

	/* ---------- Helpers ---------- */

	/**
	 * Đảm bảo log dir tồn tại + protect bằng .htaccess + index.html.
	 * Đa site: wp_upload_dir trả /wp-content/uploads/sites/{blog_id}/ trên subsite.
	 */
	private static function ensure_dir(): string {
		if ( self::$cached_dir !== '' ) { return self::$cached_dir; }

		$up = wp_upload_dir( null, false );
		if ( empty( $up['basedir'] ) ) { return ''; }
		$dir = trailingslashit( $up['basedir'] ) . self::LOG_DIRNAME;

		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		// Protect.
		if ( is_dir( $dir ) ) {
			$ht = $dir . '/.htaccess';
			if ( ! file_exists( $ht ) ) {
				@file_put_contents( $ht, "Order allow,deny\nDeny from all\n" );
			}
			$idx = $dir . '/index.html';
			if ( ! file_exists( $idx ) ) {
				@file_put_contents( $idx, '' );
			}
		}
		self::$cached_dir = $dir;
		return $dir;
	}

	private static function relative_path( string $abs ): string {
		if ( $abs === '' ) { return ''; }
		$rel = str_replace( ABSPATH, '', $abs );
		return '/' . ltrim( str_replace( '\\', '/', $rel ), '/' );
	}

	/**
	 * Recursive cap depth + length so 1 log row không nuốt 200 KB.
	 */
	private static function trim_for_log( $v, int $depth = 0 ) {
		if ( $depth > 6 ) { return '[depth-cap]'; }
		if ( is_array( $v ) ) {
			$out = array();
			$i   = 0;
			foreach ( $v as $k => $vv ) {
				if ( $i++ >= 50 ) { $out['__truncated_keys'] = count( $v ) - 50; break; }
				$out[ $k ] = self::trim_for_log( $vv, $depth + 1 );
			}
			return $out;
		}
		if ( is_object( $v ) ) {
			return self::trim_for_log( get_object_vars( $v ), $depth + 1 );
		}
		if ( is_string( $v ) && strlen( $v ) > 1024 ) {
			return substr( $v, 0, 1024 ) . '…[+' . ( strlen( $v ) - 1024 ) . ' bytes]';
		}
		return $v;
	}

	/**
	 * Mask token / access_token / secret / authorization fields.
	 */
	private static function mask_sensitive( array $data ): array {
		$re_keys = '/(token|access_token|secret|password|authorization|api_key|app_secret|page_token)/i';
		array_walk_recursive( $data, function ( &$v, $k ) use ( $re_keys ) {
			if ( is_string( $k ) && preg_match( $re_keys, $k ) && is_scalar( $v ) ) {
				$s = (string) $v;
				$v = strlen( $s ) > 8 ? substr( $s, 0, 4 ) . '…' . substr( $s, -4 ) : '***';
			}
		} );
		return $data;
	}

	/**
	 * Chỉ giữ headers hữu ích — bỏ cookie / auth.
	 */
	private static function pick_headers( array $headers ): array {
		$keep = array( 'content_type', 'content_length', 'user_agent', 'x_hub_signature', 'x_hub_signature_256', 'x_forwarded_for' );
		$out  = array();
		foreach ( $keep as $k ) {
			if ( isset( $headers[ $k ] ) ) {
				$out[ $k ] = is_array( $headers[ $k ] ) ? implode( ',', $headers[ $k ] ) : $headers[ $k ];
			}
		}
		return $out;
	}
}
