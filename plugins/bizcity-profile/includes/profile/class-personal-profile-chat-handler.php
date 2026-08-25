<?php
/**
 * Profile public chat adapter to canonical TwinBrain WebChat policy.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Plugins\BizCityProfile
 */
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Personal_Profile_Chat_Handler' ) ) { return; }

final class BizCity_Personal_Profile_Chat_Handler {

	public static function public_context_prompt( $card_id ) {
		// [2026-08-25 Johnny Chu] PHASE-PROFILE-PUBLIC-SSE — give the shared stream canonical public Profile context without exposing private notebook data.
		$card_id = absint( $card_id );
		$card = class_exists( 'BizCity_Personal_Profile_Card_Manager' ) ? BizCity_Personal_Profile_Card_Manager::get_published( $card_id ) : null;
		if ( ! is_array( $card ) ) { return ''; }
		global $wpdb;
		$table = $wpdb->prefix . 'bzpb_projects';
		if ( ! function_exists( 'bizcity_tbl_exists' ) || ! bizcity_tbl_exists( $table ) ) { return ''; }
		$site_config = $wpdb->get_var( $wpdb->prepare( 'SELECT site_config FROM `' . $table . '` WHERE id = %d LIMIT 1', (int) $card['bzpb_project_id'] ) );
		$config = json_decode( (string) $site_config, true );
		$props = array();
		foreach ( is_array( $config['blocks'] ?? null ) ? $config['blocks'] : array() as $block ) {
			if ( 'profile-card' === (string) ( $block['type'] ?? '' ) ) { $props = is_array( $block['props'] ?? null ) ? $block['props'] : array(); break; }
		}
		if ( empty( $props ) ) { return ''; }
		$lines = array();
		foreach ( array( 'name' => 'Tên', 'jobTitle' => 'Chức danh', 'company' => 'Công ty', 'bio' => 'Giới thiệu' ) as $key => $label ) {
			// [2026-08-25 Johnny Chu] HOTFIX — close the public Profile context expression so PHP can load the chat handler.
			$value = trim( wp_strip_all_tags( (string) ( $props[ $key ] ?? '' ) ) );
			if ( '' !== $value ) {
				$value = function_exists( 'mb_substr' ) ? mb_substr( $value, 0, 500, 'UTF-8' ) : substr( $value, 0, 500 );
				$lines[] = $label . ': ' . $value;
			}
		}
		$capabilities = array();
		foreach ( is_array( $props['publicCapabilities'] ?? null ) ? $props['publicCapabilities'] : array() as $capability ) {
			if ( is_array( $capability ) && '' !== trim( (string) ( $capability['label'] ?? '' ) ) ) { $capabilities[] = sanitize_text_field( (string) $capability['label'] ); }
		}
		if ( ! empty( $capabilities ) ) { $lines[] = 'Năng lực công khai: ' . implode( ', ', array_slice( $capabilities, 0, 8 ) ); }
		return empty( $lines ) ? '' : "Ngữ cảnh Profile Public canonical (chỉ dùng dữ liệu đã công khai):\n" . implode( "\n", $lines ) . "\n\nHãy trả lời như quản gia của Profile này, bám sát thông tin trên và nói rõ khi dữ liệu không đủ.\n";
	}

	public static function begin_stream_turn( $card_id, $session_id, $message ) {
		// [2026-08-25 Johnny Chu] PHASE-PROFILE-PUBLIC-SSE — reuse the existing Profile CRM/WebChat projection before a streamed Brain turn.
		$card_id = absint( $card_id );
		$session_id = sanitize_key( (string) $session_id );
		$message = sanitize_textarea_field( (string) $message );
		$message_id = 'profile_user_' . substr( md5( $session_id . '|' . $message ), 0, 24 );
		self::persist_message( $session_id, $card_id, $message, 'user', $message_id );
		return array( 'message_id' => $message_id, 'crm_message_id' => self::ingest_crm_message( $card_id, $session_id, $message, $message_id ) );
	}

	public static function finish_stream_turn( array $turn, $card_id, $session_id, $answer, $trace_id ) {
		// [2026-08-25 Johnny Chu] PHASE-PROFILE-PUBLIC-SSE — mirror the completed shared-stream answer into Profile transcript and CRM once.
		$answer = sanitize_textarea_field( (string) $answer );
		if ( '' === $answer ) { return; }
		if ( class_exists( 'BizCity_Personal_Profile_Analytics' ) ) { BizCity_Personal_Profile_Analytics::record( absint( $card_id ), 'chat_message', array( 'funnel' => 'share' ) ); }
		$bot_message_id = 'profile_bot_' . substr( md5( sanitize_key( (string) $session_id ) . '|' . (string) $trace_id ), 0, 24 );
		self::persist_message( $session_id, $card_id, $answer, 'bot', $bot_message_id );
		self::mirror_crm_answer( (int) ( $turn['crm_message_id'] ?? 0 ), $answer, $bot_message_id );
	}

	public static function handle( $card_id, $context_token, $channel_code, $presentation, $message, $session_id = '' ) {
		// [2026-08-20 Johnny Chu] PHASE-PROFILE-QR — public Profile chat enters the canonical WebChat TwinBrain adapter only.
		$claims = BizCity_Personal_Profile_Channel_Resolver::verify_context( $context_token, $card_id, $channel_code, $presentation );
		if ( is_wp_error( $claims ) ) { return $claims; }
		if ( 'webchat' !== sanitize_key( (string) $channel_code ) ) { return new WP_Error( 'invalid_param', 'Profile Chat chỉ hỗ trợ channel WebChat.', array( 'status' => 400 ) ); }
		$message = sanitize_textarea_field( (string) $message );
		if ( '' === $message ) { return new WP_Error( 'invalid_param', 'Tin nhắn không được để trống.', array( 'status' => 400 ) ); }
		$session_id = sanitize_key( (string) $session_id );
		$prefix = 'profile_webchat_' . (int) $card_id . '_';
		if ( 0 !== strpos( $session_id, $prefix ) ) { $session_id = $prefix . substr( md5( wp_generate_uuid4() ), 0, 24 ); }
		$payload = array(
			'platform'        => 'WEBCHAT',
			'channel_class'   => 'guest_channel',
			'account_id'      => (string) get_current_blog_id(),
			'chat_id'         => $session_id,
			'external_user_id' => $session_id,
			'text'            => $message,
			'surface'         => 'profile',
			'presentation'    => sanitize_key( (string) $presentation ),
			'profile_card_id' => (int) $card_id,
		);
		// [2026-08-23 Johnny Chu] PHASE-TBP-6.4 — persist the visitor question in canonical WebChat storage for owner transcript review.
		$message_id = 'profile_user_' . substr( md5( $session_id . '|' . $message ), 0, 24 );
		self::persist_message( $session_id, $card_id, $message, 'user', $message_id );
		// [2026-08-23 Johnny Chu] PHASE-TBP-6.2 — send the same inbound turn through the canonical CRM WebChat adapter; failures remain fail-open for Brain response.
		$crm_message_id = self::ingest_crm_message( (int) $card_id, $session_id, $message, $message_id );
		if ( ! class_exists( 'BizCity_TwinBrain_Adapter_WebChat' ) ) { return new WP_Error( 'module_not_loaded', 'TwinBrain WebChat adapter chưa sẵn sàng.', array( 'status' => 503 ) ); }
		$result = ( new BizCity_TwinBrain_Adapter_WebChat() )->handle( $payload );
		if ( empty( $result['ok'] ) ) { return new WP_Error( 'twin_agent_exception', 'Twin Brain chưa thể trả lời lúc này.', array( 'status' => 503 ) ); }
		if ( class_exists( 'BizCity_Personal_Profile_Analytics' ) ) {
			// [2026-08-21 Johnny Chu] PHASE-TWIN-BRAIN-PROFILE — count a chat funnel step only after the canonical TwinBrain adapter succeeds.
			BizCity_Personal_Profile_Analytics::record( (int) $card_id, 'chat_message', array( 'funnel' => 'share' ) );
		}
		$result['session_id'] = $session_id;
		$result['presentation'] = sanitize_key( (string) $presentation );
		$answer = (string) ( $result['answer'] ?? '' );
		$bot_message_id = 'profile_bot_' . substr( md5( $session_id . '|' . (string) ( $result['trace_id'] ?? '' ) ), 0, 24 );
		self::persist_message( $session_id, $card_id, $answer, 'bot', $bot_message_id );
		self::mirror_crm_answer( $crm_message_id, $answer, $bot_message_id );
		return $result;
	}

	private static function persist_message( $session_id, $card_id, $content, $from, $message_id ) {
		if ( '' === trim( (string) $content ) || ! class_exists( 'BizCity_WebChat_Database' ) || ! method_exists( 'BizCity_WebChat_Database', 'instance' ) ) { return; }
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_webchat_messages';
		if ( ! function_exists( 'bizcity_tbl_exists' ) || ! bizcity_tbl_exists( $table ) ) { return; }
		if ( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM `' . $table . '` WHERE message_id = %s LIMIT 1', $message_id ) ) ) { return; }
		BizCity_WebChat_Database::instance()->log_message( array(
			'session_id'    => (string) $session_id,
			'user_id'       => 0,
			'message_id'    => (string) $message_id,
			'message_text'  => sanitize_textarea_field( (string) $content ),
			'message_from'  => 'bot' === $from ? 'bot' : 'user',
			'message_type'  => 'text',
			'platform_type' => 'WEBCHAT',
			'plugin_slug'   => 'bizcity-profile',
			'meta'          => array( 'profile_card_id' => (int) $card_id, 'source' => 'profile_public' ),
		) );
	}

	private static function build_crm_inbound_payload( $card_id, $session_id, $message, $message_id ) {
		// [2026-08-24 Johnny Chu] PHASE-TBP-6.2 — build a deterministic Profile CRM envelope without side effects for runtime and DDV reuse.
		return array(
			'session_id'      => (string) $session_id,
			'message_id'      => (string) $message_id,
			'text'            => (string) $message,
			'client_name'     => 'Profile visitor',
			'blog_id'         => (int) get_current_blog_id(),
			'profile_card_id' => (int) $card_id,
			'profile_source'  => 'profile_public',
		);
	}

	private static function ingest_crm_message( $card_id, $session_id, $message, $message_id ) {
		// [2026-08-23 Johnny Chu] PHASE-TBP-6.2 — reuse CRM WebChat adapter/ingest path without creating a Profile CRM table.
		if ( ! class_exists( 'BizCity_CRM_Adapter_WebChat' ) || ! class_exists( 'BizCity_CRM_Facebook_Ingestor' ) || ! class_exists( 'BizCity_CRM_Channel_Registry' ) ) { return 0; }
		$adapter = BizCity_CRM_Channel_Registry::get( 'webchat' );
		if ( ! $adapter ) { return 0; }
		$norm = $adapter->normalize_inbound( self::build_crm_inbound_payload( $card_id, $session_id, $message, $message_id ) );
		if ( ! is_array( $norm ) ) { return 0; }
		$skip_crm_autoreply = static function ( $should_run ) { return false; };
		add_filter( 'bizcity_crm_ai_autoreply_should_run', $skip_crm_autoreply, 1, 3 );
		try {
			$crm_message_id = BizCity_CRM_Facebook_Ingestor::instance()->ingest( $adapter, $norm );
		} finally {
			remove_filter( 'bizcity_crm_ai_autoreply_should_run', $skip_crm_autoreply, 1 );
		}
		if ( class_exists( 'BizCity_Cache' ) && class_exists( 'BizCity_Personal_Profile_Contacts_Bridge' ) ) {
			BizCity_Cache::flush_group( BizCity_Personal_Profile_Contacts_Bridge::CACHE_GROUP );
			BizCity_Cache::flush_group( BizCity_Personal_Profile_Contacts_Bridge::CONVERSATIONS_CACHE_GROUP );
		}
		return (int) $crm_message_id;
	}

	private static function mirror_crm_answer( $crm_message_id, $answer, $external_id ) {
		// [2026-08-23 Johnny Chu] PHASE-TBP-6.2 — mirror the already-generated Brain answer into CRM without dispatching it a second time.
		if ( (int) $crm_message_id <= 0 || '' === trim( (string) $answer ) || ! class_exists( 'BizCity_CRM_Repository' ) ) { return; }
		$inbound = BizCity_CRM_Repository::get_message( (int) $crm_message_id );
		if ( ! is_array( $inbound ) ) { return; }
		BizCity_CRM_Repository::insert_message( array(
			'conversation_id'    => (int) ( $inbound['conversation_id'] ?? 0 ),
			'inbox_id'           => (int) ( $inbound['inbox_id'] ?? 0 ),
			'external_source_id' => (string) $external_id,
			'content'            => (string) $answer,
			'content_type'       => 'text',
			'message_type'       => 'outgoing',
			'sender_type'        => 'agent_bot',
			'status'             => 'sent',
			'ai_metadata'        => array( 'source' => 'profile_public', 'profile_webchat' => true ),
		) );
	}
}