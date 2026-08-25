<?php
/**
 * Universal Channel Trigger Listener (PHASE 0.33 M2 essential)
 *
 * Tap into `waic_twf_process_flow` for every known channel trigger key,
 * normalize the payload into a tiny envelope, then:
 *   1. Resolve channel binding → character_id (Guru)
 *   2. Mirror inbound into wp_bizcity_channel_messages (idempotent)
 *   3. If Router caught the request, patch wp_{date}_webhook_log row with
 *      channel_message_id + character_id (so Inspector links 2-way)
 *
 * Runs at priority 5 — BEFORE any business listener (CRM ingestor at 9,
 * Automation triggers at 10). This guarantees character_id is attached
 * to a *new* envelope `bizcity_channel_normalized` re-fired at priority 6
 * for downstream consumers that want guru context.
 *
 * NOTE: This file does NOT replace any existing handler. It is purely
 * additive observability. Adapter refactor (full M2) comes later.
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway
 * @since 1.5.0 (PHASE 0.33 M2 essential)
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Universal_Channel_Listener {

	/**
	 * Trigger key → { platform, account_field, message_field, msgid_field, event_type }
	 *
	 * @var array<string,array<string,string>>
	 */
	private static $map = array(
		'bizcity_facebook_message_received' => array(
			'platform'      => 'FB_MESS',
			'account_field' => 'page_id',
			'user_field'    => 'user_id',
			'message_field' => 'message',
			'msgid_field'   => 'mid',
			'event_type'    => 'message',
		),
		'bizcity_facebook_image_received'   => array(
			'platform'      => 'FB_MESS',
			'account_field' => 'page_id',
			'user_field'    => 'user_id',
			'message_field' => 'image_url',
			'msgid_field'   => 'mid',
			'event_type'    => 'image',
		),
		'bizcity_facebook_comment_received' => array(
			'platform'      => 'FB_FEED',
			'account_field' => 'page_id',
			'user_field'    => 'from_id',
			'message_field' => 'message',
			'msgid_field'   => 'comment_id',
			'event_type'    => 'comment',
		),
		// [2026-07-27 Johnny Chu] PHASE-0.52 W1 — normalize Zone 1 Zalo OA identity events alongside Messenger.
		'bizcity_zalo_oa_message_received' => array(
			'platform'      => 'ZALO_OA',
			'account_field' => 'account_id',
			'user_field'    => 'from_user_id',
			'message_field' => 'message_text',
			'msgid_field'   => 'message_id',
			'event_type'    => 'message',
		),
		// [2026-08-21 Johnny Chu] PHASE-0.39B — dedicated Zone 1 Personal CRM trigger.
		'bizcity_zalo_personal_message_received' => array(
			'platform'      => 'ZALO_PERSONAL',
			'account_field' => 'account_id',
			'user_field'    => 'from_user_id',
			'message_field' => 'message_text',
			'msgid_field'   => 'message_id',
			'event_type'    => 'message',
		),
		'bizcity_zalo_message_received'     => array(
			'platform'      => 'ZALO_BOT',
			'account_field' => 'conversation_id', // bizcity-zalo-bot uses this for OA id (was wrongly 'oa_id')
			'user_field'    => 'from_user_id',    // was wrongly 'user_id'
			'message_field' => 'message_text',    // was wrongly 'message'
			'msgid_field'   => 'message_id',      // was wrongly 'msg_id'
			'event_type'    => 'message',
		),
		'wu_webchat_message_received'       => array(
			'platform'      => 'WEBCHAT',
			'account_field' => 'site_id',
			'user_field'    => 'session_id',
			'message_field' => 'message',
			'msgid_field'   => 'message_id',
			'event_type'    => 'message',
		),
	);

	public static function init(): void {
		add_action( 'waic_twf_process_flow', array( __CLASS__, 'on_trigger' ), 5, 2 );
		// [2026-07-27 Johnny Chu] PHASE-0.52 W5 — handle explicit Facebook memory commands before legacy AI fallback.
		add_filter( 'bizcity_facebook_workflow_handle_message', array( __CLASS__, 'handle_facebook_memory_command' ), 4, 3 );

		// Bridge Zalo Bot's direct action → workflow trigger.
		// Zalo Bot still fires `do_action('bizcity_zalo_message_received', ...)` directly
		// (BUG-B). We re-emit through `waic_twf_process_flow` so this listener +
		// CRM ingestor + Automation all catch it without forking the Zalo plugin.
		add_action( 'bizcity_zalo_message_received', array( __CLASS__, 'bridge_zalo' ), 5, 1 );
	}

	public static function bridge_zalo( $message_data ): void {
		// [2026-06-07 Johnny Chu] PHASE-0.40 G0.1 R-ZONE-2 — discriminator: ZALO_BOT (Zone 2 admin)
		// must NOT enter CRM Inbox (Zone 1). Bot messages go to automation/twinbrain ONLY.
		$platform = is_array( $message_data ) ? (string) ( $message_data['platform'] ?? '' ) : '';
		if ( $platform === 'ZALO_BOT' ) {
			return;
		}
		if ( $platform === 'ZALO_PERSONAL' || ( is_array( $message_data ) && (string) ( $message_data['code'] ?? '' ) === 'zalo_personal' ) ) {
			do_action( 'waic_twf_process_flow', 'bizcity_zalo_personal_message_received', (array) $message_data );
			return;
		}

		// Avoid double-fire if Zalo plugin ever switches to workflow trigger.
		static $seen = array();
		$mid = is_array( $message_data ) ? (string) ( $message_data['message_id'] ?? ( $message_data['msg_id'] ?? '' ) ) : '';
		if ( $mid !== '' && isset( $seen[ $mid ] ) ) {
			return;
		}
		if ( $mid !== '' ) {
			$seen[ $mid ] = true;
		}
		do_action( 'waic_twf_process_flow', 'bizcity_zalo_message_received', (array) $message_data );
	}

	/**
	 * Handle an explicit Facebook memory command before the legacy chatbot path.
	 *
	 * @param bool  $handled
	 * @param array $trigger_data
	 * @param array $input_data
	 * @return bool
	 */
	public static function handle_facebook_memory_command( $handled, $trigger_data, $input_data = array() ): bool {
		// [2026-07-27 Johnny Chu] PHASE-0.52 W5 — keep existing workflow claims authoritative.
		if ( $handled || ! is_array( $trigger_data ) ) {
			return (bool) $handled;
		}

		$text = trim( (string) ( $trigger_data['message'] ?? '' ) );
		if ( ! self::is_facebook_memory_command( $text ) ) {
			return (bool) $handled;
		}

		$account_id       = trim( (string) ( $trigger_data['page_id'] ?? '' ) );
		$external_user_id = trim( (string) ( $trigger_data['user_id'] ?? '' ) );
		if ( $account_id === '' || $external_user_id === '' ) {
			return (bool) $handled;
		}

		// [2026-07-27 Johnny Chu] R-CH-FILE-LOG — evidence must precede identity and memory DB reads.
		if ( class_exists( 'BizCity_CG_Debug_Logger' ) ) {
			BizCity_CG_Debug_Logger::log( 'facebook', 'memory_list_attempt', array(
				'account_hash'       => substr( md5( $account_id ), 0, 10 ),
				'external_user_hash' => substr( md5( $external_user_id ), 0, 10 ),
				'command'            => 'memory',
			) );
		}

		$wp_user_id = class_exists( 'BizCity_Channel_User_Linker' )
			? BizCity_Channel_User_Linker::resolve_wp_user( 'FB_MESS', $external_user_id, $account_id )
			: 0;

		// [2026-07-28 Johnny Chu] R-CH-IDMEM — an unlinked Messenger identity may read its own durable UUID memory; linking is only needed for WP-owned profile features.
		$identity_context = class_exists( 'BizCity_Identity_Hub' )
			? BizCity_Identity_Hub::resolve_from_opts( array(
				'platform'     => 'FB_MESS',
				'account_id'   => $account_id,
				'external_ref' => $external_user_id,
			), (int) get_current_blog_id() )
			: null;
		if ( ! is_array( $identity_context ) && class_exists( 'BizCity_Identity_Hub' ) && method_exists( 'BizCity_Identity_Hub', 'bind' ) ) {
			$identity_context = BizCity_Identity_Hub::bind(
				'FB_MESS',
				$account_id,
				$external_user_id,
				$wp_user_id,
				(int) get_current_blog_id(),
				true
			);
		}
		if ( is_wp_error( $identity_context ) ) {
			$identity_context = null;
		}
		$identity_uuid = is_array( $identity_context ) ? (string) ( $identity_context['identity_uuid'] ?? '' ) : '';
		$memory_user_id = is_array( $identity_context )
			? (int) ( $identity_context['primary_wp_user_id'] ?? $identity_context['wp_user_id'] ?? $wp_user_id )
			: $wp_user_id;

		if ( $memory_user_id <= 0 && $identity_uuid === '' ) {
			$link_message = 'ℹ️ Bạn chưa liên kết tài khoản nên chưa thể xem trí nhớ.\n'
				. 'Hãy mở liên kết kết nối tài khoản mà bot gửi, rồi thử lại.';
			if ( class_exists( 'BizCity_Channel_User_Linker' ) ) {
				$issued = BizCity_Channel_User_Linker::issue_link( 'FB_MESS', $external_user_id, $account_id );
				if ( is_array( $issued ) && ! empty( $issued['url'] ) ) {
					$link_message = '🔗 Hãy mở liên kết này để kết nối tài khoản WordPress:\n' . (string) $issued['url'];
				}
			}
			self::send_facebook_command( $account_id, $external_user_id, $link_message );
			return true;
		}

		if ( ! class_exists( 'BizCity_User_Memory' ) ) {
			self::send_facebook_command( $account_id, $external_user_id, '❌ Bộ nhớ chưa sẵn sàng. Vui lòng thử lại sau.' );
			return true;
		}

		$rows = BizCity_User_Memory::instance()->get_memories( array(
			'user_id'       => $memory_user_id,
			'identity_uuid' => $identity_uuid,
			'session_id'    => '',
			'limit'         => 10,
			'order_by'      => 'updated_at',
		) );
		$rows = is_array( $rows ) ? $rows : array();

		if ( empty( $rows ) ) {
			self::send_facebook_command(
				$account_id,
				$external_user_id,
				"🧠 Bạn chưa có memory nào.\nHãy nói \"hãy nhớ ...\" để Twin GPT ghi nhớ điều quan trọng."
			);
			return true;
		}

		$lines = array( '🧠 Trí nhớ của bạn (mới nhất):' );
		foreach ( $rows as $index => $row ) {
			$memory_text = trim( wp_strip_all_tags( (string) ( $row->memory_text ?? '' ) ) );
			if ( $memory_text === '' ) {
				continue;
			}
			if ( function_exists( 'mb_substr' ) ) {
				$memory_text = mb_substr( $memory_text, 0, 220, 'UTF-8' );
			} else {
				$memory_text = substr( $memory_text, 0, 220 );
			}
			$lines[] = ( (int) $index + 1 ) . '. ' . $memory_text;
		}
		$lines[] = '';
		$lines[] = 'Mở Twin GPT để chỉnh sửa hoặc xoá memory.';
		self::send_facebook_command( $account_id, $external_user_id, implode( "\n", $lines ) );
		return true;
	}

	private static function is_facebook_memory_command( string $text ): bool {
		$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( $text ), 'UTF-8' ) : strtolower( trim( $text ) );
		return in_array( $text, array( 'xem trí nhớ', 'xem tri nho', 'trí nhớ', 'tri nho', 'memory', 'my memory' ), true );
	}

	private static function send_facebook_command( string $account_id, string $external_user_id, string $message ): void {
		if ( ! class_exists( 'BizCity_Gateway_Sender' ) ) {
			return;
		}
		BizCity_Gateway_Sender::instance()->send_envelope( array(
			'platform'    => 'FACEBOOK',
			'instance_id' => $account_id,
			'recipient'   => $external_user_id,
			'message'     => $message,
			'type'        => 'text',
			'meta'        => array( 'source' => 'facebook_memory_command' ),
		) );
	}

	/**
	 * @param string $trigger_key
	 * @param mixed  $payload
	 */
	public static function on_trigger( $trigger_key, $payload = array() ): void {
		if ( ! is_string( $trigger_key ) || ! isset( self::$map[ $trigger_key ] ) ) {
			return;
		}
		if ( ! is_array( $payload ) ) {
			return;
		}
		$spec = self::$map[ $trigger_key ];

		$platform   = $spec['platform'];
		$account_id = (string) ( $payload[ $spec['account_field'] ] ?? '' );
		$user_id    = (string) ( $payload[ $spec['user_field'] ]    ?? '' );
		$message    = (string) ( $payload['message_text_clean'] ?? $payload[ $spec['message_field'] ] ?? '' );
		$message_id = (string) ( $payload[ $spec['msgid_field'] ]   ?? '' );

		if ( $account_id === '' || $user_id === '' ) {
			return;
		}

		// Build a stable chat_id. Pattern: <prefix><account>_<user>
		$chat_id = self::compose_chat_id( $platform, $account_id, $user_id );
		$wp_user_id = 0;
		if ( class_exists( 'BizCity_User_Resolver' ) ) {
			$wp_user_id = (int) BizCity_User_Resolver::instance()->resolve( $chat_id, (int) get_current_blog_id() );
		}
		$identity_context = null;
		$subject_contract = class_exists( 'BizCity_Memory_Identity_Scope' )
			? BizCity_Memory_Identity_Scope::subject_contract( array(
				'platform'           => $platform,
				'user_id'            => $wp_user_id,
				'chat_kind'          => (string) ( $payload['chat_kind'] ?? '' ),
				'provider_chat_type'  => (string) ( $payload['provider_chat_type'] ?? '' ),
				'identity_is_stable'  => ! empty( $payload['identity_is_stable'] ),
			) )
			: array();
		// [2026-07-28 Johnny Chu] R-CH-IDMEM — create/refresh the canonical identity before downstream subject, memory, KG, or automation consumers run.
		if ( class_exists( 'BizCity_Identity_Hub' )
			&& method_exists( 'BizCity_Identity_Hub', 'bind' )
			&& ( 'guest_channel' === ( $subject_contract['channel_class'] ?? '' )
				|| $wp_user_id > 0 )
			&& empty( $subject_contract['identity_temporary'] )
			&& ! self::is_group_payload( $payload ) ) {
			$identity_is_stable = $platform !== 'WEBCHAT' || ! empty( $payload['identity_is_stable'] );
			$identity_context   = BizCity_Identity_Hub::bind(
				$platform,
				$account_id,
				$user_id,
				$wp_user_id,
				(int) get_current_blog_id(),
				$identity_is_stable,
				array(
					'chat_kind'          => (string) ( $payload['chat_kind'] ?? '' ),
					'provider_chat_type' => (string) ( $payload['provider_chat_type'] ?? '' ),
				),
			);
			if ( is_wp_error( $identity_context ) ) {
				$identity_context = null;
			}
		}
		$identity_link_required = ( 'user_bound' === ( $subject_contract['channel_class'] ?? '' ) ) && $wp_user_id <= 0;
		// [2026-07-21 Johnny Chu] PHASE-ZALOBOT-GROUP W6 — preserve explicit private/group ZaloBot conversation targets.
		if ( $platform === 'ZALO_BOT' ) {
			$explicit_chat_id = (string) ( $payload['conversation_chat_id'] ?? $payload['chat_id'] ?? '' );
			if ( $explicit_chat_id !== '' && strpos( $explicit_chat_id, 'zalobot_' ) === 0 ) {
				$chat_id = $explicit_chat_id;
			}
		}

		// Resolve binding → character_id (Guru). Null is fine — means no Guru bound yet.
		$character_id   = null;
		$binding_mode   = null;
		$picked         = null;
		$binding_row    = null;
		if ( class_exists( 'BizCity_Channel_Binding' ) ) {
			$binding_row = BizCity_Channel_Binding::resolve( $platform, $account_id );
			if ( $binding_row && ! empty( $binding_row['character_id'] ) ) {
				$character_id = (int) $binding_row['character_id'];
				$binding_mode = isset( $binding_row['mode'] ) ? (string) $binding_row['mode'] : 'auto';
				if ( method_exists( 'BizCity_Channel_Binding', 'pick_responder' ) ) {
					$picked = BizCity_Channel_Binding::pick_responder( $binding_row );
				}
			}
		}

		// Tie back to today's webhook_log row if Router captured it.
		$log_id   = null;
		$log_date = null;
		if ( class_exists( 'BizCity_Webhook_Router' ) ) {
			$current = BizCity_Webhook_Router::current();
			if ( $current && ! empty( $current['id'] ) ) {
				$log_id   = (int) $current['id'];
				$log_date = (string) $current['date'];
			}
		}

		$msg_row_id = 0;
		if ( class_exists( 'BizCity_Channel_Messages' ) ) {
			$msg_row_id = BizCity_Channel_Messages::log_inbound( array(
				'platform'         => $platform,
				'chat_id'          => $chat_id,
				'user_psid'        => $user_id,
				'message_id'       => $message_id,
				'event_type'       => $spec['event_type'],
				'body'             => $message,
				'payload'          => $payload,
				'webhook_log_id'   => $log_id,
				'webhook_log_date' => $log_date,
				'character_id'     => $character_id,
			) );
		}

		// Patch the webhook_log row with foreign keys for trace linking.
		if ( $log_id && $log_date && class_exists( 'BizCity_Webhook_Log' ) ) {
			$patch = array(
				'verify_status' => 'verified',
			);
			if ( $msg_row_id ) {
				$patch['channel_message_id'] = $msg_row_id;
			}
			if ( $character_id ) {
				$patch['character_id'] = $character_id;
			}
			BizCity_Webhook_Log::update( $log_date, $log_id, $patch );
		}

		/**
		 * Re-emit a normalized envelope at priority 6 for downstream listeners
		 * that want guru context without re-deriving it.
		 *
		 * Consumers that subscribed to the original `waic_twf_process_flow`
		 * key still get the raw payload — this is purely additive.
		 *
		 * @param array $envelope
		 */
		$envelope = array(
			'platform'           => $platform,
			'account_id'         => $account_id,
			'user_id'            => $user_id,
			'wp_user_id'         => $wp_user_id,
			// [2026-07-28 Johnny Chu] R-CH-IDMEM — preserve canonical customer context alongside legacy WP-user fields.
			'identity_uuid'      => is_array( $identity_context ) ? (string) ( $identity_context['identity_uuid'] ?? '' ) : '',
			'contact_id'         => is_array( $identity_context ) ? (int) ( $identity_context['contact_id'] ?? 0 ) : 0,
			'identity_state'     => is_array( $identity_context ) ? ( $identity_is_stable ? 'stable' : 'soft' ) : 'unknown',
			'identity_is_stable' => is_array( $identity_context ) ? (bool) $identity_is_stable : false,
			'identity_link_required' => $identity_link_required,
			'identity_guest_bind' => ( 'guest_channel' === ( $subject_contract['channel_class'] ?? '' ) )
				&& empty( $subject_contract['identity_temporary'] )
				&& ! self::is_group_payload( $payload ),
			'identity_temporary' => ! empty( $subject_contract['identity_temporary'] ),
			'channel_class'      => (string) ( $subject_contract['channel_class'] ?? 'unknown' ),
			'subject_kind'       => (string) ( $subject_contract['subject_kind'] ?? 'unresolved' ),
			'subject_source'     => (string) ( $subject_contract['subject_source'] ?? 'none' ),
			'wp_user_required'   => ! empty( $subject_contract['wp_user_required'] ),
			'identity_first'     => ! empty( $subject_contract['identity_first'] ),
			'chat_id'            => $chat_id,
			// [2026-07-21 Johnny Chu] PHASE-ZALOBOT-GROUP W6 — downstream automation/test UI needs sender vs conversation split.
			'conversation_chat_id' => (string) ( $payload['conversation_chat_id'] ?? $chat_id ),
			'provider_chat_id'   => (string) ( $payload['provider_chat_id'] ?? '' ),
			'provider_chat_type' => (string) ( $payload['provider_chat_type'] ?? '' ),
			'chat_kind'          => (string) ( $payload['chat_kind'] ?? '' ),
			'sender_user_id'     => (string) ( $payload['sender_user_id'] ?? $user_id ),
			'raw_text'           => (string) ( $payload['raw_text'] ?? $payload[ $spec['message_field'] ] ?? $message ),
			'message_text_clean' => $message,
			'mention_detected'   => ! empty( $payload['mention_detected'] ),
			'reply_to_bot_message' => ! empty( $payload['reply_to_bot_message'] ),
			'message_id'         => $message_id,
			'message'            => $message,
			'event_type'         => $spec['event_type'],
			'character_id'       => $character_id,
			'binding_mode'       => $binding_mode,                                              // PHASE 0.34 trace
			'responder_kind'     => $binding_mode ? ( $binding_mode === 'manual' ? 'manual' : ( $binding_mode === 'hybrid' ? 'hybrid' : 'auto' ) ) : null,
			'responder_user_id'  => $picked['user_id']      ?? null,                            // round-robin or manual fallback
			'responder_character_id' => $picked['character_id'] ?? $character_id,
			'channel_message_id' => $msg_row_id,
			'webhook_log_id'     => $log_id,
			'webhook_log_date'   => $log_date,
			'raw'                => $payload,
		);
		do_action( 'bizcity_channel_normalized', $envelope, $trigger_key );

		/**
		 * Push the resolved responder context onto the Stamper stack so that
		 * any outbound dispatched by downstream automation (within this same
		 * request) is automatically tagged with character_id + responder_kind.
		 *
		 * Manual mode short-circuits: nothing is pushed → outbound (if any)
		 * will fall through to the logged-in-user heuristic.
		 */
		if ( class_exists( 'BizCity_Responder_Stamper' )
			&& $binding_mode
			&& $binding_mode !== 'manual'
			&& ( $character_id || ! empty( $picked['user_id'] ) ) ) {
			BizCity_Responder_Stamper::push( array(
				'kind'         => $binding_mode === 'hybrid' ? 'hybrid' : 'auto',
				'character_id' => $picked['character_id'] ?? $character_id,
				'user_id'      => $picked['user_id']      ?? null,
				'mode'         => $binding_mode,
				'source'       => 'universal-listener',
			) );
			// Schedule a defensive pop on shutdown so we never leak context across requests.
			add_action( 'shutdown', array( 'BizCity_Responder_Stamper', 'clear' ), 99 );
		}
	}

	private static function compose_chat_id( string $platform, string $account_id, string $user_id ): string {
		switch ( $platform ) {
			case 'FB_MESS':
			case 'FB_FEED':
				return 'fb_' . $account_id . '_' . $user_id;
			case 'ZALO_BOT':
				return 'zalobot_' . $account_id . '_' . $user_id;
			// [2026-07-27 Johnny Chu] PHASE-0.52 W1 — keep OA identity separate from Zone 2 Zalo Bot.
			case 'ZALO_OA':
				return 'zalooa_' . $account_id . '_' . $user_id;
			case 'ZALO_PERSONAL':
				return 'zalop_' . $account_id . '_' . $user_id;
			case 'WEBCHAT':
				return 'webchat_' . $user_id;
			default:
				return strtolower( $platform ) . '_' . $account_id . '_' . $user_id;
		}
	}

	// [2026-08-02 Johnny Chu] R-ZONE-6 — avoid personal identity binding for explicitly grouped channel payloads.
	private static function is_group_payload( array $payload ): bool {
		$chat_kind = strtolower( trim( (string) ( $payload['chat_kind'] ?? '' ) ) );
		if ( $chat_kind === 'group' ) {
			return true;
		}

		$provider_chat_type = strtolower( trim( (string) ( $payload['provider_chat_type'] ?? '' ) ) );
		return in_array( $provider_chat_type, array( 'group', 'supergroup', 'channel' ), true );
	}

	private static function is_zone1_platform( string $platform ): bool {
		return in_array( $platform, array( 'FB_MESS', 'ZALO_OA' ), true );
	}

	/* ─── Introspection (for diagnostic page) ─── */

	public static function trigger_keys(): array {
		return array_keys( self::$map );
	}

	public static function spec_for( string $key ): ?array {
		return self::$map[ $key ] ?? null;
	}
}
