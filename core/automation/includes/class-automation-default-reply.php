<?php
/**
 * BizCity_Automation_Default_Reply — built-in safety net.
 *
 * PHASE-0-RULE-CHANNEL-UNIFY (R-CH-UNI 1.2) — khi `Trigger_Matcher` không
 * tìm được workflow nào match (matched=[] AND fallbacks=[]), handler này
 * chạy TwinBrain MPR Think + gửi reply qua `bizcity_channel_send()` để
 * người dùng KHÔNG bao giờ nhận im lặng / login link tự động.
 *
 * Filter:
 *   - `bizcity_automation_default_reply_enabled` (default true) — tắt toàn bộ.
 *   - `bizcity_automation_default_reply_prompt` ($prompt, $payload) — sửa prompt.
 *   - `bizcity_automation_default_reply_text` ($answer, $payload, $tb_result)
 *     — chỉnh nội dung trước khi send (vd thêm CTA login).
 *
 * KHÔNG insert row vào `bizcity_automation_runs` để tránh phình bảng — handler
 * này coi như "channel response", không phải "workflow run". Nếu cần audit,
 * row vào `bizcity_channel_messages` (direction=out) là đủ.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Automation
 * @since      2026-05-30 (Phase 1 ship cùng PHASE-0-RULE-CHANNEL-UNIFY)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Automation_Default_Reply {

	/**
	 * Handle a normalized inbound that matched no workflow.
	 *
	 * @param array $run_payload Same shape as Trigger_Matcher run_payload.
	 *                           Required keys: `text` / `chat_id`.
	 */
	public static function handle( array $run_payload ): void {
		$chat_id = (string) ( $run_payload['chat_id'] ?? '' );
		$text    = trim( (string) ( $run_payload['text'] ?? $run_payload['message'] ?? '' ) );

		if ( $chat_id === '' || $text === '' ) {
			// Không có chat_id hoặc text → không trả lời được. Phase 2 sẽ thay
			// bằng STT/vision khi `media_kind` set.
			return;
		}

		$prompt = (string) apply_filters(
			'bizcity_automation_default_reply_prompt',
			$text,
			$run_payload
		);
		// [2026-08-02 Johnny Chu] PHASE-ZALO-VISION — preserve inbound Zalo image/file URLs for the TwinBrain fallback instead of reducing the turn to text-only.
		$media_urls = array();
		foreach ( array( $run_payload['media_url'] ?? '', $run_payload['_resume']['attachment_urls'] ?? array(), $run_payload['_resume']['attachment_url'] ?? '' ) as $candidate ) {
			$candidates = is_array( $candidate ) ? $candidate : array( $candidate );
			foreach ( $candidates as $item ) {
				$url = is_array( $item ) ? (string) ( $item['url'] ?? $item['source_url'] ?? '' ) : (string) $item;
				if ( $url !== '' && filter_var( $url, FILTER_VALIDATE_URL ) && ! in_array( $url, $media_urls, true ) ) {
					$media_urls[] = $url;
				}
			}
		}
		if ( ! empty( $media_urls ) ) {
			error_log( sprintf( '[automation][default-reply] media_context chat_id=%s count=%d kind=%s', $chat_id, count( $media_urls ), (string) ( $run_payload['media_kind'] ?? 'image_or_file' ) ) );
		}

		if ( ! empty( $run_payload['_fuzzy_suggestion']['term'] ) && function_exists( 'bizcity_channel_send' ) ) {
			$suggestion = $run_payload['_fuzzy_suggestion'];
			$hint = sprintf(
				'Sếp có phải muốn gọi kịch bản "%s" không? Nhắn "%s" để chạy đúng kịch bản.',
				(string) ( $suggestion['workflow_name'] ?? 'tự động hoá' ),
				(string) $suggestion['term']
			);
			bizcity_channel_send( $chat_id, $hint, 'text', array( 'detail' => 'fuzzy_trigger_suggestion' ) );
			return;
		}

		$router_decision = array();
		$confirmed_route = false;
		$confirmation_result = class_exists( 'BizCity_TwinBrain_Conversation_Confirmation' )
			&& class_exists( 'BizCity_TwinBrain_Conversation_Router' )
			&& BizCity_TwinBrain_Conversation_Router::SPECIALIZED_ROUTING_ENABLED
			? BizCity_TwinBrain_Conversation_Confirmation::consume( $chat_id, $text )
			: array( 'status' => 'none' );
		if ( ! class_exists( 'BizCity_TwinBrain_Conversation_Confirmation' ) && class_exists( 'BizCity_Automation_Pending_State' ) ) {
			// [2026-08-01 Johnny Chu] PHASE-TBR-CHAT-DEFAULT — preserve the pre-helper pending contract during unusual bootstrap order.
			$legacy_pending = BizCity_Automation_Pending_State::get( $chat_id );
			if ( ( $legacy_pending['intent'] ?? '' ) === 'conversation_router_confirm' ) {
				$answer = strtolower( remove_accents( trim( $text ) ) );
				$yes = in_array( $answer, array( 'co', 'u', 'ok', 'oke', 'yes', 'duoc', 'dung' ), true );
				$no  = in_array( $answer, array( 'khong', 'ko', 'k', 'thoi', 'no', 'bo qua' ), true );
				if ( ! $yes && ! $no ) {
					$confirmation_result = array( 'status' => 'invalid' );
				} else {
					$legacy_decision = (array) ( $legacy_pending['slots']['route_decision'] ?? array() );
					if ( $no ) {
						$legacy_decision = array(
							'route'      => 'casual',
							'reason'     => 'confirmed_generic',
							'web_mode'   => 'off',
							'companion_mode' => true,
							'confidence' => 1.0,
						);
					}
					BizCity_Automation_Pending_State::clear( $chat_id );
					$confirmation_result = array(
						'status'   => 'confirmed',
						'prompt'   => (string) ( $legacy_pending['slots']['original_prompt'] ?? $prompt ),
						'decision' => $legacy_decision,
					);
				}
			}
		}
		if ( ( $confirmation_result['status'] ?? '' ) === 'invalid' ) {
			if ( function_exists( 'bizcity_channel_send' ) ) {
				bizcity_channel_send( $chat_id, 'Sếp trả lời "có" để dùng nguồn chuyên gia, hoặc "không" để em trả lời chung nhé.' );
			}
			return;
		}
		if ( ( $confirmation_result['status'] ?? '' ) === 'confirmed' ) {
			// [2026-08-01 Johnny Chu] PHASE-TBR-CHAT-DEFAULT — restore the shared confirmation decision before routing text channels.
			$router_decision = (array) ( $confirmation_result['decision'] ?? array() );
			$prompt = (string) ( $confirmation_result['prompt'] ?? $prompt );
			$confirmed_route = true;
		}
		// [2026-08-01 Johnny Chu] HOTFIX — keep the Router guard as one balanced block; production previously received an intermediate brace-broken version.
		if ( ! $confirmed_route && class_exists( 'BizCity_TwinBrain_Conversation_Router' ) ) {
			try {
				$router_decision = BizCity_TwinBrain_Conversation_Router::route(
					$prompt,
					(int) ( $run_payload['wp_user_id'] ?? 0 ),
					array(
						'guru_id' => (int) ( $run_payload['character_id'] ?? 0 ),
						'surface' => 'zalobot',
						'session_id' => self::resolve_zalobot_session_id( $run_payload, $chat_id ),
						'trace_id' => (string) ( $run_payload['trace_id'] ?? '' ),
					)
				);
			} catch ( \Throwable $e ) {
				$router_decision = array( 'route' => 'casual', 'reason' => 'router_error' );
			}
		}
		if ( ! $confirmed_route && ! empty( $router_decision['needs_confirm'] ) && function_exists( 'bizcity_channel_send' ) ) {
			$label = '';
			if ( ! empty( $router_decision['candidate_vertical'] ) ) {
				$label = (string) ( BizCity_TwinBrain_Conversation_Router::VERTICAL_CATALOG[ $router_decision['candidate_vertical'] ]['label'] ?? $router_decision['candidate_vertical'] );
			} elseif ( ! empty( $router_decision['candidate_notebook_titles'][0] ) ) {
				$label = 'Notebook "' . (string) $router_decision['candidate_notebook_titles'][0] . '"';
			}
			if ( $label !== '' && class_exists( 'BizCity_TwinBrain_Conversation_Confirmation' ) ) {
				// [2026-08-01 Johnny Chu] PHASE-TBR-CHAT-DEFAULT — use the same pending contract as TwinChat and TwinWeb.
				BizCity_TwinBrain_Conversation_Confirmation::begin( $chat_id, $prompt, $router_decision );
				bizcity_channel_send( $chat_id, 'Câu hỏi này có vẻ liên quan tới ' . $label . '. Sếp muốn em dùng nguồn đó để trả lời không? Nhắn "có" hoặc "không" nhé.' );
				return;
			} elseif ( $label !== '' && class_exists( 'BizCity_Automation_Pending_State' ) ) {
				// [2026-08-01 Johnny Chu] PHASE-TBR-CHAT-DEFAULT — legacy fallback if TwinBrain loads after Automation.
				BizCity_Automation_Pending_State::set( $chat_id, array(
					'intent'      => 'conversation_router_confirm',
					'workflow_id' => 0,
					'slots'       => array(
						'route_decision' => $router_decision,
						'original_prompt' => $prompt,
					),
				), 600 );
				bizcity_channel_send( $chat_id, 'Câu hỏi này có vẻ liên quan tới ' . $label . '. Sếp muốn em dùng nguồn đó để trả lời không? Nhắn "có" hoặc "không" nhé.' );
				return;
			}
		}
		// [2026-08-01 Johnny Chu] PHASE-TBR-CHAT-DEFAULT — inject factual workflow help instead of asking the LLM to invent triggers.
		if ( ! empty( $router_decision['automation_help'] ) && class_exists( 'BizCity_Automation_Workflow_Catalog' ) ) {
			$zone  = ( strtoupper( (string) ( $run_payload['platform'] ?? '' ) ) === 'ZALO_BOT' ) ? 'admin' : 'crm';
			$guide = BizCity_Automation_Workflow_Catalog::render_guide_md(
				BizCity_Automation_Workflow_Catalog::list_for_scope( $zone, (int) ( $run_payload['character_id'] ?? 0 ) )
			);
			$prompt .= "\n\nDANH SÁCH AUTOMATION THẬT CỦA SITE:\n" . $guide;
		}

		// TwinBrain bridge bắt buộc — Phase 1 không hỗ trợ fallback nào khác.
		if ( ! class_exists( 'BizCity_Automation_TwinBrain_Bridge' ) ) {
			return;
		}

		$opts = array(
			'user_id' => (int) ( $run_payload['wp_user_id']   ?? 0 ),
			'wp_user_id' => (int) ( $run_payload['wp_user_id'] ?? 0 ),
			'guru_id' => (int) ( $run_payload['character_id'] ?? 0 ),
			// [2026-08-01 Johnny Chu] R-CH-IDMEM — preserve one Zalo conversation thread across Default Reply turns.
			'session_id' => self::resolve_zalobot_session_id( $run_payload, $chat_id ),
			'k'       => 8,
			// [2026-07-27 Johnny Chu] PHASE-0.52 W4 — no vertical selection uses the generic Brain path; tool intent stays off until an explicit command/whitelist exists.
			'web_mode'         => 'off',
			'mode'             => 'brain',
			'skip_tool_intent' => true,
			// [2026-07-27 Johnny Chu] PHASE-0.52 W2 — carry channel identity context without granting anonymous memory ownership.
			'platform'          => (string) ( $run_payload['platform'] ?? 'ZALO_BOT' ),
			'channel'           => (string) ( $run_payload['channel'] ?? 'zalo_bot' ),
			'channel_class'     => self::resolve_channel_class( $run_payload ),
			'_channel_adapter_class' => self::resolve_channel_adapter_class( $run_payload ),
			'surface'           => 'zalobot',
			'source_marker'     => 'zalobot_chat',
			'account_id'        => (string) ( $run_payload['account_id'] ?? $run_payload['instance_id'] ?? '' ),
			'external_user_id'  => ( (string) ( $run_payload['chat_kind'] ?? 'private' ) === 'group' ) ? '' : (string) ( $run_payload['sender_user_id'] ?? $run_payload['sender_id'] ?? $run_payload['user_id'] ?? '' ),
			'chat_id'           => $chat_id,
			'chat_kind'         => sanitize_key( (string) ( $run_payload['chat_kind'] ?? 'private' ) ),
			'provider_chat_type'=> (string) ( $run_payload['provider_chat_type'] ?? '' ),
			'images'            => $media_urls,
			'media_urls'        => $media_urls,
			'attachments'       => ! empty( $media_urls ) ? array_map( static function ( $url ) { return array( 'kind' => 'image', 'url' => $url, 'source_url' => $url ); }, $media_urls ) : array(),
			'is_group'          => (string) ( $run_payload['chat_kind'] ?? 'private' ) === 'group',
			'identity_guest_bind' => self::resolve_channel_class( $run_payload ) === 'guest_channel'
				&& (string) ( $run_payload['chat_kind'] ?? 'private' ) !== 'group',
			// [2026-08-01 Johnny Chu] PHASE-TBR-CHAT-DEFAULT — keep visible and enriched prompts distinct like /gpt/.
			'visible_prompt'    => $text,
			'user_prompt'       => $text,
		);
		// [2026-08-01 Johnny Chu] PHASE-TBR-CHAT-DEFAULT — use the lightweight chat path for greetings and the verified Luna chat purpose.
		if ( ( $router_decision['route'] ?? '' ) === 'casual' && ( (float) ( $router_decision['confidence'] ?? 0 ) >= 0.9 || in_array( ( $router_decision['reason'] ?? '' ), array( 'casual_fast_path', 'confirmed_generic' ), true ) ) ) {
			$opts['web_mode']             = 'off';
			$opts['companion_mode']       = true;
			$opts['k']                    = 1;
			$opts['final_compose_purpose'] = 'chat';
		}
		if ( ( $router_decision['route'] ?? '' ) === 'automation_help' ) {
			$opts['web_mode']             = 'off';
			$opts['companion_mode']       = true;
			$opts['k']                    = 1;
			$opts['final_compose_purpose'] = 'chat';
		}
		// [2026-08-01 Johnny Chu] PHASE-TBR-CHAT-DEFAULT — open only high-confidence specialized routes; ambiguous requests remain fail-safe Brain turns.
		if ( ! empty( $router_decision['web_mode'] ) && empty( $router_decision['needs_confirm'] ) ) {
			$opts['web_mode'] = sanitize_key( (string) $router_decision['web_mode'] );
		}
		if ( ! empty( $router_decision['force_notebooks'] ) && empty( $router_decision['needs_confirm'] ) ) {
			$opts['force_notebooks'] = array_map( 'intval', (array) $router_decision['force_notebooks'] );
		}
		$opts['conversation_route'] = (string) ( $router_decision['route'] ?? 'casual' );

		$result = BizCity_Automation_TwinBrain_Bridge::run_with_capture(
			$prompt,
			$opts,
			static function ( $event_key, $payload ) use ( $chat_id ) {
				do_action( 'bizcity_automation_default_reply_event', $chat_id, $event_key, $payload );
			},
			array(
				'complete' => true,
				'chat_id' => $chat_id,
			)
		);

		if ( is_wp_error( $result ) || ! is_array( $result ) ) {
			return;
		}

		$answer = (string) (
			$result['final_text']  ?? $result['answer']
			?? $result['answer_md'] ?? $result['message']
			?? $result['decision']  ?? ''
		);
		$answer = trim( $answer );
		if ( $answer === '' ) { return; }

		$answer = (string) apply_filters(
			'bizcity_automation_default_reply_text',
			$answer,
			$run_payload,
			$result
		);
		if ( trim( $answer ) === '' ) { return; }

		if ( ! function_exists( 'bizcity_channel_send' ) ) { return; }

		// [2026-07-07 Johnny Chu] HOTFIX — stamp no-match default reply for outbound trace.
		$trace_id = 'auto-def-' . substr( sha1( $chat_id . '|' . $prompt . '|' . microtime( true ) ), 0, 12 );
		error_log( sprintf(
			'[automation][default-reply] send trace=%s chat_id=%s chars=%d reason=no_keyword_no_fallback',
			$trace_id,
			$chat_id,
			mb_strlen( (string) $answer )
		) );

		// [2026-08-01 Johnny Chu] PHASE-TBR-CHAT-DEFAULT — keep the visible Zalo reply natural; audit identity remains in metadata below.
		bizcity_channel_send( $chat_id, $answer, 'text', array(
			'_trace_source' => 'automation.default_reply',
			'_trace_id'     => $trace_id,
			'detail'        => 'no_keyword_no_fallback',
		) );
	}

	private static function resolve_zalobot_session_id( array $run_payload, string $chat_id ): string {
		// [2026-08-01 Johnny Chu] R-CH-IDMEM — private Zalo memory follows bot+provider user; groups stay conversation-scoped.
		$chat_kind = sanitize_key( (string) ( $run_payload['chat_kind'] ?? 'private' ) );
		if ( $chat_kind === 'group' ) {
			return $chat_id;
		}
		$bot_id = preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) ( $run_payload['bot_id'] ?? $run_payload['account_id'] ?? '' ) );
		$external_user_id = preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) ( $run_payload['sender_user_id'] ?? $run_payload['user_id'] ?? '' ) );
		if ( $bot_id !== '' && $external_user_id !== '' ) {
			return 'zalobot_' . $bot_id . '_' . $external_user_id;
		}
		return $chat_id;
	}

	private static function resolve_channel_class( array $run_payload ): string {
		$platform = strtoupper( (string) ( $run_payload['platform'] ?? $run_payload['channel'] ?? 'ZALO_BOT' ) );
		return in_array( $platform, array( 'FB_MESS', 'FACEBOOK', 'MESSENGER', 'ZALO_OA', 'WEBCHAT', 'LANDING_PAGE' ), true )
			? 'guest_channel'
			: 'user_bound';
	}

	private static function resolve_channel_adapter_class( array $run_payload ): string {
		$platform = strtoupper( (string) ( $run_payload['platform'] ?? $run_payload['channel'] ?? 'ZALO_BOT' ) );
		$map = array(
			'FB_MESS' => 'BizCity_TwinBrain_Adapter_Messenger',
			'FACEBOOK' => 'BizCity_TwinBrain_Adapter_Messenger',
			'MESSENGER' => 'BizCity_TwinBrain_Adapter_Messenger',
			'ZALO_OA' => 'BizCity_TwinBrain_Adapter_ZaloOA',
			'WEBCHAT' => 'BizCity_TwinBrain_Adapter_WebChat',
			'ZALO_BOT' => 'BizCity_TwinBrain_Adapter_ZaloBot',
			'TELEGRAM' => 'BizCity_TwinBrain_Adapter_Telegram',
		);
		return (string) ( $map[ $platform ] ?? '' );
	}
}
