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
