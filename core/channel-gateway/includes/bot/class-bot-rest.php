<?php
/**
 * Bot Studio REST — bizcity-channel/v1/bot/* (PHASE-0.60A W2, R-CH-NS).
 *
 * Owns exactly the "chạy trên kênh chat" scope (settings.bot + site tuning +
 * read-only tool/provider/queue/context projections).
 * Does NOT write Character fields (persona/model/notebook_policy) — that stays
 * owned by bizcity-knowledge/v1/characters/{id}/quick-edit — and does NOT add
 * a second binding-write route — that stays owned by POST /inspector/bindings
 * (class-webhook-inspector.php). See doc §4/§5.
 *
 * Every error carries the four fields code · message · hint · help_code (B8.4).
 * No route returns a key, an absolute path or another tenant's data (B8.6).
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway\Bot
 * @since 1.0.0 (PHASE-0.60A W2)
 */

// [2026-09-23 03:55 PM Claude Fable 5.1] PHASE-0.60A W2/W5/B-06/B-07/B-08 — routes for tools, provider, tuning registry, queue, context preview; 4-field errors.
defined( 'ABSPATH' ) || exit;

final class BizCity_Bot_REST {

	const NAMESPACE_V1 = 'bizcity-channel/v1';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function can(): bool {
		// [2026-09-23 Claude Sonnet 5] PHASE-0.60A W2 — same trust boundary as the sibling
		// /inspector/bindings route (class-webhook-inspector.php::can()). The comment already
		// claimed parity but the code didn't: this was still `manage_options` alone, missing
		// the BizCity_Network_Admin_Capability fallback — a Network Super Admin with no local
		// administrator row on the mapped blog got 403 here even though every sibling
		// bizcity-channel/v1 route (inspector, channel-rest-api) already accepts them.
		return class_exists( 'BizCity_Network_Admin_Capability' )
			? BizCity_Network_Admin_Capability::can_manage()
			: current_user_can( 'manage_options' );
	}

	public static function can_or_error() {
		return self::can() ? true : self::err( 'permission_denied', 'Bạn không có quyền cấu hình trợ lý.', 403, 'Cần quyền quản trị site (manage_options).', 'bot_capability_required' );
	}

	public static function register_routes(): void {
		register_rest_route( self::NAMESPACE_V1, '/bot/runtime/(?P<character_id>\d+)', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_get_runtime' ), 'permission_callback' => array( __CLASS__, 'can_or_error' ) ),
			array( 'methods' => 'PUT', 'callback' => array( __CLASS__, 'rest_save_runtime' ), 'permission_callback' => array( __CLASS__, 'can_or_error' ) ),
		) );
		register_rest_route( self::NAMESPACE_V1, '/bot/runtime/(?P<character_id>\d+)/test', array(
			'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_test_runtime' ), 'permission_callback' => array( __CLASS__, 'can_or_error' ),
		) );
		register_rest_route( self::NAMESPACE_V1, '/bot/tuning', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_get_tuning' ), 'permission_callback' => array( __CLASS__, 'can_or_error' ) ),
			array( 'methods' => 'PUT', 'callback' => array( __CLASS__, 'rest_save_tuning' ), 'permission_callback' => array( __CLASS__, 'can_or_error' ) ),
		) );
		register_rest_route( self::NAMESPACE_V1, '/bot/tools', array(
			'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_get_tools' ), 'permission_callback' => array( __CLASS__, 'can_or_error' ),
			'args' => array( 'character_id' => array( 'type' => 'integer', 'default' => 0 ) ),
		) );
		register_rest_route( self::NAMESPACE_V1, '/bot/provider', array(
			'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_get_provider' ), 'permission_callback' => array( __CLASS__, 'can_or_error' ),
		) );
		register_rest_route( self::NAMESPACE_V1, '/bot/queue/status', array(
			'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_queue_status' ), 'permission_callback' => array( __CLASS__, 'can_or_error' ),
		) );
		register_rest_route( self::NAMESPACE_V1, '/bot/context/preview', array(
			'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_context_preview' ), 'permission_callback' => array( __CLASS__, 'can_or_error' ),
			'args' => array( 'conversation_id' => array( 'type' => 'integer', 'default' => 0 ), 'limit' => array( 'type' => 'integer', 'default' => 20 ) ),
		) );
		// [2026-09-23 Claude Sonnet 5] PHASE-0.60E EA-1 (doc §5) — per-binding behavior policy
		// (allowlist for now). Deliberately its own route, not folded into POST /inspector/bindings
		// (class-webhook-inspector.php owns binding identity/routing; this owns bot behavior on it).
		register_rest_route( self::NAMESPACE_V1, '/bot/policy/(?P<binding_id>\d+)', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_get_policy' ), 'permission_callback' => array( __CLASS__, 'can_or_error' ) ),
			array( 'methods' => 'PUT', 'callback' => array( __CLASS__, 'rest_save_policy' ), 'permission_callback' => array( __CLASS__, 'can_or_error' ) ),
		) );
		// [2026-09-23 Claude Sonnet 5] PHASE-0.60E D-E1 (user-approved) — TTS/STT/tạo nhạc/Apify/
		// Tavily. Non-secret config lives in BizCity_Bot_Config_Repo (settings.bot.media); keys
		// live in BizCity_Bot_Secrets_Repo and are NEVER returned in plaintext by any route here.
		register_rest_route( self::NAMESPACE_V1, '/bot/media/(?P<character_id>\d+)', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_get_media' ), 'permission_callback' => array( __CLASS__, 'can_or_error' ) ),
			array( 'methods' => 'PUT', 'callback' => array( __CLASS__, 'rest_save_media' ), 'permission_callback' => array( __CLASS__, 'can_or_error' ) ),
		) );
		register_rest_route( self::NAMESPACE_V1, '/bot/media/(?P<character_id>\d+)/keys', array(
			array( 'methods' => 'POST',   'callback' => array( __CLASS__, 'rest_add_media_key' ),    'permission_callback' => array( __CLASS__, 'can_or_error' ) ),
			array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'rest_clear_media_keys' ), 'permission_callback' => array( __CLASS__, 'can_or_error' ) ),
		) );
		register_rest_route( self::NAMESPACE_V1, '/bot/media/(?P<character_id>\d+)/keys/(?P<index>\d+)', array(
			'methods' => 'DELETE', 'callback' => array( __CLASS__, 'rest_remove_media_key' ), 'permission_callback' => array( __CLASS__, 'can_or_error' ),
		) );
		// [2026-09-23 Claude Sonnet 5] PHASE-0.60F OW-4A (doc §2.2 G-12) — added 'apify' so the Test
		// button in GuruBotMediaPanel has somewhere real to call; the executor (BizCity_Bot_Apify_Client)
		// already existed but this route only accepted tts|stt|music, so any FE apify test 404'd.
		// 'video'/'image' added the same pass (doc §2.2 G-05/G-04) — video submits a REAL job via
		// BizCity_Video_Client (async, never waits for completion); image calls the SAME
		// BizCity_LLM_Client::generate_image() the bot would use and returns a real image.
		// 'tavily' (doc §2.2A "tavily_api_key chỉ khi tool owner dùng thật") — calls Tavily's own
		// search API directly with the operator's key so the key is genuinely exercised, not just
		// stored; still NOT wired into the live web_search turn tool (class-bot-tools.php keeps using
		// the site-wide gateway Search_Client) — that is a turn-behavior change needing its own owner
		// sign-off, same idiom as tts/stt/music/video/image staying `unconfigured` for the turn.
		register_rest_route( self::NAMESPACE_V1, '/bot/media/(?P<character_id>\d+)/test/(?P<kind>tts|stt|music|apify|video|image|tavily)', array(
			'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_test_media' ), 'permission_callback' => array( __CLASS__, 'can_or_error' ),
		) );
	}

	/* ── /bot/runtime/{character_id} ─────────────────────────────────── */

	public static function rest_get_runtime( WP_REST_Request $req ) {
		if ( ! class_exists( 'BizCity_Bot_Config_Repo' ) ) {
			return self::not_loaded();
		}
		$character_id = (int) $req['character_id'];
		$data = BizCity_Bot_Config_Repo::get( $character_id );
		$data['provider'] = class_exists( 'BizCity_Bot_Provider' ) ? BizCity_Bot_Provider::site_status() : null;
		return self::ok( $data );
	}

	public static function rest_save_runtime( WP_REST_Request $req ) {
		if ( ! class_exists( 'BizCity_Bot_Config_Repo' ) ) {
			return self::not_loaded();
		}
		$character_id = (int) $req['character_id'];
		$body         = $req->get_json_params();
		$body         = is_array( $body ) ? $body : array();
		$result       = BizCity_Bot_Config_Repo::save( $character_id, $body );
		if ( is_wp_error( $result ) ) {
			return self::err_from( $result );
		}
		return self::ok( $result );
	}

	/** A REAL minimal call through the same path the bot uses (never a mock — D4.4). */
	public static function rest_test_runtime( WP_REST_Request $req ) {
		$character_id = (int) $req['character_id'];
		if ( $character_id <= 0 || ! class_exists( 'BizCity_Knowledge_Database' ) || ! class_exists( 'BizCity_LLM_Client' ) ) {
			return self::not_loaded();
		}
		$character = BizCity_Knowledge_Database::instance()->get_character( $character_id );
		if ( ! $character ) {
			return self::err( 'not_found', 'Character không tồn tại.', 404, 'Chọn lại Guru rồi thử lại.', 'bot_character_missing' );
		}
		$test_message = array(
			array( 'role' => 'system', 'content' => (string) ( $character->system_prompt ?? '' ) ),
			array( 'role' => 'user', 'content' => 'Xin chào, bạn có thể giới thiệu ngắn gọn về bản thân không?' ),
		);
		$started = microtime( true );
		try {
			$result = BizCity_LLM_Client::instance()->chat_with_character( $character, $test_message );
		} catch ( \Throwable $e ) {
			return self::err( 'provider_error', 'Không gọi được nguồn AI.', 502, 'Kiểm tra API key và chế độ nguồn AI ở Cài đặt BizCity LLM.', 'bot_provider_error' );
		}
		if ( empty( $result['success'] ) ) {
			return self::err( 'provider_error', (string) ( $result['error'] ?? 'Nguồn AI không phản hồi.' ), 502, 'Kiểm tra API key và chế độ nguồn AI ở Cài đặt BizCity LLM.', 'bot_provider_error' );
		}
		return self::ok( array(
			'reply'      => (string) ( $result['message'] ?? '' ),
			'model'      => (string) ( $result['model'] ?? '' ),
			'latency_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ),
			'provider'   => class_exists( 'BizCity_Bot_Provider' ) ? BizCity_Bot_Provider::effective()['mode'] : '',
		) );
	}

	/* ── /bot/tuning ──────────────────────────────────────────────────── */

	public static function rest_get_tuning() {
		if ( ! class_exists( 'BizCity_Bot_Config_Repo' ) ) {
			return self::not_loaded();
		}
		$tuning = BizCity_Bot_Config_Repo::get_tuning();
		return self::ok( array(
			'values'    => $tuning,
			'registry'  => BizCity_Bot_Config_Repo::tuning_registry(),
			'overrides' => BizCity_Bot_Config_Repo::tuning_overrides( $tuning ),
		) );
	}

	public static function rest_save_tuning( WP_REST_Request $req ) {
		if ( ! class_exists( 'BizCity_Bot_Config_Repo' ) ) {
			return self::not_loaded();
		}
		$body   = $req->get_json_params();
		$body   = is_array( $body ) ? $body : array();
		// "Về mặc định": {reset: [keys]} restores registry defaults for those keys (A3.2).
		if ( ! empty( $body['reset'] ) && is_array( $body['reset'] ) ) {
			$defaults = BizCity_Bot_Config_Repo::tuning_defaults();
			foreach ( $body['reset'] as $key ) {
				if ( isset( $defaults[ $key ] ) ) {
					$body[ $key ] = $defaults[ $key ];
				}
			}
			unset( $body['reset'] );
		}
		$result = BizCity_Bot_Config_Repo::save_tuning( $body );
		if ( is_wp_error( $result ) ) {
			return self::err_from( $result );
		}
		return self::ok( array(
			'values'    => $result,
			'registry'  => BizCity_Bot_Config_Repo::tuning_registry(),
			'overrides' => BizCity_Bot_Config_Repo::tuning_overrides( $result ),
		) );
	}

	/* ── /bot/tools · /bot/provider · /bot/queue/status · /bot/context/preview ── */

	public static function rest_get_tools( WP_REST_Request $req ) {
		if ( ! class_exists( 'BizCity_Bot_Tool_Registry' ) ) {
			return self::not_loaded();
		}
		$character_id = (int) $req->get_param( 'character_id' );
		$character    = $character_id > 0 && class_exists( 'BizCity_Knowledge_Database' ) ? BizCity_Knowledge_Database::instance()->get_character( $character_id ) : null;
		$rows         = BizCity_Bot_Tool_Registry::rows( $character );
		$disabled     = $character_id > 0 && class_exists( 'BizCity_Bot_Config_Repo' ) ? BizCity_Bot_Config_Repo::get( $character_id )['disabled_tools'] : array();
		return self::ok( array(
			'tools'          => $rows,
			'disabled_tools' => $disabled,
			'gateway_gaps'   => class_exists( 'BizCity_Bot_Provider' ) ? BizCity_Bot_Provider::GATEWAY_GAPS : array(),
			'counts'         => array(
				'available'    => count( array_filter( $rows, static function ( $r ) { return 'available' === $r['status']; } ) ),
				'unconfigured' => count( array_filter( $rows, static function ( $r ) { return 'unconfigured' === $r['status']; } ) ),
				'needs_bridge' => count( array_filter( $rows, static function ( $r ) { return 'needs_bridge' === $r['status']; } ) ),
			),
		) );
	}

	public static function rest_get_provider() {
		if ( ! class_exists( 'BizCity_Bot_Provider' ) ) {
			return self::not_loaded();
		}
		return self::ok( BizCity_Bot_Provider::site_status() );
	}

	public static function rest_queue_status() {
		if ( ! class_exists( 'BizCity_Bot_Turn_Claim' ) || ! class_exists( 'BizCity_Bot_Config_Repo' ) ) {
			return self::not_loaded();
		}
		$rows = array();
		foreach ( BizCity_Bot_Turn_Claim::active_contacts() as $contact_id => $row ) {
			$rows[] = array(
				'contact_id'      => (int) $contact_id,
				'conversation_id' => (int) ( $row['conversation_id'] ?? 0 ),
				'state'           => (string) ( $row['state'] ?? '' ),
				'pending'         => (int) ( $row['pending'] ?? 0 ),
				'parks'           => (int) ( $row['parks'] ?? 0 ),
				'age_seconds'     => max( 0, time() - (int) ( $row['at'] ?? time() ) ),
			);
		}
		$tuning = BizCity_Bot_Config_Repo::get_tuning();
		return self::ok( array(
			'active' => $rows,
			'tuning' => array(
				'debounce_seconds'   => (int) $tuning['debounce_seconds'],
				'max_batch_messages' => (int) $tuning['max_batch_messages'],
				'send_delay_min_ms'  => (int) $tuning['send_delay_min_ms'],
				'send_delay_max_ms'  => (int) $tuning['send_delay_max_ms'],
				'daily_message_cap'  => (int) $tuning['daily_message_cap'],
			),
		) );
	}

	public static function rest_context_preview( WP_REST_Request $req ) {
		if ( ! class_exists( 'BizCity_Bot_Context_Builder' ) || ! class_exists( 'BizCity_CRM_Repository' ) ) {
			return self::not_loaded();
		}
		$conversation_id = (int) $req->get_param( 'conversation_id' );
		$limit           = max( 1, min( 200, (int) $req->get_param( 'limit' ) ) );
		if ( $conversation_id <= 0 ) {
			return self::err( 'invalid_param', 'Thiếu conversation_id.', 422, 'Chọn một hội thoại trong Inbox rồi thử lại.', 'bot_preview_conversation_required' );
		}
		$conversation = BizCity_CRM_Repository::get_conversation( $conversation_id );
		if ( ! is_array( $conversation ) ) {
			return self::err( 'not_found', 'Hội thoại không tồn tại trên site này.', 404, 'Kiểm tra lại ID hội thoại.', 'bot_preview_not_found' );
		}
		$contact_id = (int) ( $conversation['contact_id'] ?? 0 );
		return self::ok( array(
			'conversation_id' => $conversation_id,
			'rows'            => BizCity_Bot_Context_Builder::preview( $conversation_id, $limit ),
			'contact_block'   => BizCity_Bot_Context_Builder::contact_block( $contact_id ),
		) );
	}

	/* ── /bot/policy/{binding_id} (PHASE-0.60E EA-1) ─────────────────── */

	const ALLOWLIST_MODES  = array( 'all', 'contacts_only', 'list' );
	const ALLOWLIST_MAX_UIDS = 500;

	public static function rest_get_policy( WP_REST_Request $req ) {
		if ( ! class_exists( 'BizCity_Channel_Binding' ) ) {
			return self::not_loaded();
		}
		$binding = BizCity_Channel_Binding::find( (int) $req['binding_id'] );
		if ( ! $binding ) {
			return self::err( 'not_found', 'Kênh không tồn tại.', 404, 'Chọn lại kênh Zalo rồi thử lại.', 'bot_binding_missing' );
		}
		return self::ok( self::policy_defaults_merged( $binding['policy_json'] ?? '' ) );
	}

	public static function rest_save_policy( WP_REST_Request $req ) {
		if ( ! class_exists( 'BizCity_Channel_Binding' ) ) {
			return self::not_loaded();
		}
		$binding_id = (int) $req['binding_id'];
		$binding    = BizCity_Channel_Binding::find( $binding_id );
		if ( ! $binding ) {
			return self::err( 'not_found', 'Kênh không tồn tại.', 404, 'Chọn lại kênh Zalo rồi thử lại.', 'bot_binding_missing' );
		}
		$body = $req->get_json_params();
		$body = is_array( $body ) ? $body : array();

		$policy = self::policy_defaults_merged( $binding['policy_json'] ?? '' );
		if ( array_key_exists( 'allowlist_mode', $body ) ) {
			$mode = sanitize_key( (string) $body['allowlist_mode'] );
			if ( ! in_array( $mode, self::ALLOWLIST_MODES, true ) ) {
				return self::err( 'invalid_param', 'Chế độ allowlist không hợp lệ.', 422, 'Chọn all, contacts_only hoặc list.', 'bot_policy_allowlist_mode' );
			}
			$policy['allowlist_mode'] = $mode;
		}
		if ( array_key_exists( 'allowlist_uids', $body ) ) {
			if ( ! is_array( $body['allowlist_uids'] ) ) {
				return self::err( 'invalid_param', 'allowlist_uids phải là danh sách.', 422, 'Gửi một mảng UID dạng chuỗi.', 'bot_policy_allowlist_uids_shape' );
			}
			$policy['allowlist_uids'] = self::sanitize_uid_list( $body['allowlist_uids'] );
		}
		// [2026-09-23 Claude Sonnet 5] PHASE-0.60E EA-2/EA-3 (doc §6) — same route, two more boolean
		// keys in the same policy_json blob; no new endpoint (doc §5 table).
		if ( array_key_exists( 'reply_in_group', $body ) ) {
			$policy['reply_in_group'] = (bool) $body['reply_in_group'];
		}
		if ( array_key_exists( 'passive_listen_in_group', $body ) ) {
			$policy['passive_listen_in_group'] = (bool) $body['passive_listen_in_group'];
		}
		// [2026-09-23 Claude Sonnet 5] PHASE-0.60E EA-7 (D-E2, user-approved) — blank clears it,
		// which is also how the feature is turned fully off (EA-7.2).
		if ( array_key_exists( 'owner_uid', $body ) ) {
			$policy['owner_uid'] = sanitize_text_field( trim( (string) $body['owner_uid'] ) );
		}

		if ( ! BizCity_Channel_Binding::save_policy( $binding_id, $policy ) ) {
			return self::err( 'save_failed', 'Không lưu được cấu hình.', 500, 'Thử lại; nếu vẫn lỗi hãy kiểm tra log.', 'bot_policy_save_failed' );
		}
		return self::ok( $policy );
	}

	/**
	 * [2026-09-23 Claude Sonnet 5] PHASE-0.60F OW-1 — bumped from private to public so the
	 * read-only `/bot-studio/accounts` projection (class-bot-studio-rest.php) can reuse the exact
	 * same policy-default rules instead of re-implementing them and risking drift (doc §4.1).
	 */
	public static function policy_defaults_merged( $raw ): array {
		$decoded = array();
		if ( is_array( $raw ) ) {
			$decoded = $raw;
		} elseif ( is_string( $raw ) && $raw !== '' ) {
			$tmp = json_decode( $raw, true );
			$decoded = is_array( $tmp ) ? $tmp : array();
		}
		$mode = isset( $decoded['allowlist_mode'] ) && in_array( $decoded['allowlist_mode'], self::ALLOWLIST_MODES, true )
			? (string) $decoded['allowlist_mode']
			: 'all';
		$uids = isset( $decoded['allowlist_uids'] ) && is_array( $decoded['allowlist_uids'] ) ? $decoded['allowlist_uids'] : array();
		return array(
			'allowlist_mode' => $mode,
			'allowlist_uids' => array_values( $uids ),
			// [2026-09-23 Claude Sonnet 5] PHASE-0.60E EA-2.1/EA-3.1 — both default true: a binding
			// saved before this feature existed (key absent) must see no behavior change.
			'reply_in_group'           => ! isset( $decoded['reply_in_group'] ) || (bool) $decoded['reply_in_group'],
			'passive_listen_in_group'  => ! isset( $decoded['passive_listen_in_group'] ) || (bool) $decoded['passive_listen_in_group'],
			// [2026-09-23 Claude Sonnet 5] PHASE-0.60E EA-7.2 — empty string = feature fully off (default).
			'owner_uid' => isset( $decoded['owner_uid'] ) ? (string) $decoded['owner_uid'] : '',
		);
	}

	private static function sanitize_uid_list( array $list ): array {
		$out = array();
		foreach ( $list as $uid ) {
			$uid = sanitize_text_field( (string) $uid );
			if ( $uid !== '' && ! in_array( $uid, $out, true ) ) {
				$out[] = $uid;
			}
			if ( count( $out ) >= self::ALLOWLIST_MAX_UIDS ) {
				break;
			}
		}
		return $out;
	}

	/* ── /bot/media/{character_id} + /keys (PHASE-0.60E D-E1) ────────── */

	public static function rest_get_media( WP_REST_Request $req ) {
		if ( ! class_exists( 'BizCity_Bot_Config_Repo' ) || ! class_exists( 'BizCity_Bot_Secrets_Repo' ) ) {
			return self::not_loaded();
		}
		$character_id = (int) $req['character_id'];
		$media        = BizCity_Bot_Config_Repo::get( $character_id )['media'];
		return self::ok( array(
			'config'  => $media,
			'secrets' => self::media_secret_status( $character_id ),
		) );
	}

	public static function rest_save_media( WP_REST_Request $req ) {
		if ( ! class_exists( 'BizCity_Bot_Config_Repo' ) ) {
			return self::not_loaded();
		}
		$character_id = (int) $req['character_id'];
		$body         = $req->get_json_params();
		$body         = is_array( $body ) ? $body : array();
		$result       = BizCity_Bot_Config_Repo::save( $character_id, array( 'media' => $body ) );
		if ( is_wp_error( $result ) ) {
			return self::err_from( $result );
		}
		return self::ok( array(
			'config'  => $result['media'],
			'secrets' => self::media_secret_status( $character_id ),
		) );
	}

	/** EB-6: `field` = one of BizCity_Bot_Secrets_Repo::FIELDS. Multi fields append; single fields replace. */
	public static function rest_add_media_key( WP_REST_Request $req ) {
		if ( ! class_exists( 'BizCity_Bot_Secrets_Repo' ) ) {
			return self::not_loaded();
		}
		$character_id = (int) $req['character_id'];
		$body         = $req->get_json_params();
		$body         = is_array( $body ) ? $body : array();
		$field        = sanitize_key( (string) ( $body['field'] ?? '' ) );
		$value        = (string) ( $body['value'] ?? '' );
		if ( ! BizCity_Bot_Secrets_Repo::is_known_field( $field ) ) {
			return self::err( 'invalid_param', 'Trường khóa không hợp lệ.', 422, 'field phải là một trong: ' . implode( ', ', array_keys( BizCity_Bot_Secrets_Repo::FIELDS ) ) . '.', 'bot_secret_field_unknown' );
		}
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( BizCity_Bot_Secrets_Repo::is_multi( $field ) ) {
			$result = BizCity_Bot_Secrets_Repo::add_key( $character_id, $field, $value, $user_id );
			if ( is_wp_error( $result ) ) {
				return self::err_from( $result );
			}
			return self::ok( array( 'field' => $field, 'masked_keys' => $result ) );
		}
		if ( '' === trim( $value ) ) {
			return self::err( 'invalid_param', 'Khóa không được để trống.', 422, '', 'bot_secret_empty' );
		}
		if ( ! BizCity_Bot_Secrets_Repo::set_value( $character_id, $field, $value, $user_id ) ) {
			return self::err( 'save_failed', 'Không lưu được khóa.', 500, '', 'bot_secret_save_failed' );
		}
		return self::ok( array( 'field' => $field, 'masked_keys' => BizCity_Bot_Secrets_Repo::masked_keys( $character_id, $field ) ) );
	}

	/** EB-6.3 "xoá từng khóa" — multi fields only; index is the position in the ordered list. */
	public static function rest_remove_media_key( WP_REST_Request $req ) {
		if ( ! class_exists( 'BizCity_Bot_Secrets_Repo' ) ) {
			return self::not_loaded();
		}
		$character_id = (int) $req['character_id'];
		$index        = (int) $req['index'];
		$field        = sanitize_key( (string) $req->get_param( 'field' ) );
		if ( ! BizCity_Bot_Secrets_Repo::is_known_field( $field ) || ! BizCity_Bot_Secrets_Repo::is_multi( $field ) ) {
			return self::err( 'invalid_param', 'Trường khóa không hợp lệ hoặc không hỗ trợ xoá từng khóa.', 422, '', 'bot_secret_field_unknown' );
		}
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( ! BizCity_Bot_Secrets_Repo::remove_key( $character_id, $field, $index, $user_id ) ) {
			return self::err( 'not_found', 'Không tìm thấy khóa ở vị trí này.', 404, '', 'bot_secret_index_missing' );
		}
		return self::ok( array( 'field' => $field, 'masked_keys' => BizCity_Bot_Secrets_Repo::masked_keys( $character_id, $field ) ) );
	}

	/** EB-6.3 "xoá toàn bộ" — also how a single-value field is cleared back to unset. */
	public static function rest_clear_media_keys( WP_REST_Request $req ) {
		if ( ! class_exists( 'BizCity_Bot_Secrets_Repo' ) ) {
			return self::not_loaded();
		}
		$character_id = (int) $req['character_id'];
		$field        = sanitize_key( (string) $req->get_param( 'field' ) );
		if ( ! BizCity_Bot_Secrets_Repo::is_known_field( $field ) ) {
			return self::err( 'invalid_param', 'Trường khóa không hợp lệ.', 422, '', 'bot_secret_field_unknown' );
		}
		BizCity_Bot_Secrets_Repo::clear( $character_id, $field );
		return self::ok( array( 'field' => $field, 'masked_keys' => array() ) );
	}

	/** { has_*, *_masked } for every media field — the ONLY shape a secret field is ever returned in. */
	private static function media_secret_status( int $character_id ): array {
		$out = array();
		foreach ( BizCity_Bot_Secrets_Repo::FIELDS as $field => $spec ) {
			$masked = BizCity_Bot_Secrets_Repo::masked_keys( $character_id, $field );
			$out[ $field ] = array(
				'has'    => array() !== $masked,
				'masked' => ! empty( $spec['multi'] ) ? $masked : ( $masked[0] ?? '' ),
			);
		}
		return $out;
	}

	/**
	 * Real minimal call through the same provider path the bot would use — never a mock (D4.4).
	 * Costs real money for `music` (doc EB-3.1) — the FE must show a cost warning before calling this.
	 */
	public static function rest_test_media( WP_REST_Request $req ) {
		if ( ! class_exists( 'BizCity_Bot_Media_Client' ) ) {
			return self::not_loaded();
		}
		$character_id = (int) $req['character_id'];
		$kind         = sanitize_key( (string) $req['kind'] );
		// [2026-09-23 Claude Sonnet 5] PHASE-0.60E D-E1 — 'stt' test uploads a recording as
		// multipart/form-data ("giữ để ghi âm test", B-03/6), so it has no JSON body; every other
		// kind sends JSON. Read whichever one is actually present rather than assuming.
		$body = $req->get_json_params();
		$body = is_array( $body ) ? $body : array();
		if ( 'stt' === $kind ) {
			$files = $req->get_file_params();
			$file  = is_array( $files['file'] ?? null ) ? $files['file'] : array();
			if ( empty( $file['error'] ) && ! empty( $file['tmp_name'] ) ) {
				$body['file_path'] = (string) $file['tmp_name'];
			}
			foreach ( $req->get_body_params() as $k => $v ) {
				$body[ $k ] = $v; // e.g. confirm_cost sent alongside the multipart file.
			}
		}
		$result = BizCity_Bot_Media_Client::test( $character_id, $kind, $body );
		if ( is_wp_error( $result ) ) {
			return self::err_from( $result );
		}
		return self::ok( $result );
	}

	/* ── envelope helpers (4-field errors, B8.4) ─────────────────────── */

	private static function ok( array $data ): WP_REST_Response {
		return new WP_REST_Response( array( 'ok' => true, 'data' => $data ), 200 );
	}

	private static function not_loaded(): WP_REST_Response {
		return self::err( 'module_not_loaded', 'Bot Studio chưa sẵn sàng.', 503, 'Kiểm tra bootstrap core/channel-gateway đã nạp includes/bot/.', 'module_not_loaded' );
	}

	private static function err( string $code, string $message, int $status = 400, string $hint = '', string $help_code = '' ): WP_REST_Response {
		return new WP_REST_Response( array(
			'ok'        => false,
			'code'      => $code,
			'message'   => $message,
			'hint'      => $hint !== '' ? $hint : 'Thử lại; nếu vẫn lỗi hãy liên hệ quản trị viên.',
			'help_code' => $help_code !== '' ? $help_code : 'bot_' . $code,
		), $status );
	}

	private static function err_from( WP_Error $error ): WP_REST_Response {
		$data   = $error->get_error_data();
		$data   = is_array( $data ) ? $data : array();
		$status = isset( $data['status'] ) ? (int) $data['status'] : 400;
		return self::err( (string) $error->get_error_code(), (string) $error->get_error_message(), $status, (string) ( $data['hint'] ?? '' ), (string) ( $data['help_code'] ?? '' ) );
	}
}
