<?php
/** Remote Zalo Hub event normalizer — LC-8/B6. */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Remote_Zalo_Normalizer', false ) ) { return; }

// [2026-09-29 12:30 PM GitHub Copilot] PHASE-0.82-B6 — normalize remote events into existing Personal owner boundaries.
final class BizCity_Remote_Zalo_Normalizer {
	const THREADS_OPTION = 'bizcity_zalo_remote_threads';
	public static $emitter = null;
	public static $outbound = null;
	public static $link = null;

	public static function handle( array $event, array $ctx = array() ): array {
		$type = (string) ( $event['event_type'] ?? '' );
		$payload = is_array( $event['payload'] ?? null ) ? $event['payload'] : array();
		switch ( $type ) {
			case 'message.received': return self::received( $event, $payload );
			case 'message.sent': return self::sent( $event, $payload, $ctx );
			case 'account.status': return self::status( $event, $payload );
			case 'thread.bot_state': return self::bot_state( $event, $payload );
		}
		return self::result( 'ignored_unknown_type', 0 );
	}

	public static function reset_seams(): void { self::$emitter = self::$outbound = self::$link = null; }

	private static function received( array $event, array $payload ): array {
		$is_group = ! empty( $payload['isGroup'] ) || 'group' === (string) ( $payload['threadType'] ?? '' );
		$text = (string) ( $payload['text'] ?? '' );
		$message_type = 'text';
		foreach ( (array) ( $payload['attachments'] ?? array() ) as $attachment ) {
			if ( ! is_array( $attachment ) ) { continue; }
			$kind = (string) ( $attachment['kind'] ?? '' );
			if ( in_array( $kind, array( 'image', 'file' ), true ) ) { $message_type = $kind; }
			$label = self::attachment_label( $kind, (string) ( $attachment['name'] ?? '' ) );
			if ( '' !== $label ) { $text .= ( '' !== $text ? ' ' : '' ) . $label; }
		}
		$body = array(
			'kind' => 'personal', 'account_id' => 'rzh:' . (string) ( $event['account_id'] ?? '' ),
			'message_id' => (string) ( $payload['zaloMsgId'] ?? '' ), 'thread_kind' => $is_group ? 'group' : 'personal',
			'is_group' => $is_group ? 1 : 0, 'thread_id' => (string) ( $payload['threadId'] ?? '' ),
			'from_user_id' => (string) ( $payload['senderId'] ?? '' ), 'from_user_name' => (string) ( $payload['senderName'] ?? '' ),
			'message_text' => $text, 'message_type' => $message_type,
			'message_time' => strtotime( (string) ( $event['timestamp'] ?? '' ) ) ?: 0,
			'trace_id' => substr( 'rzh-' . (string) ( $event['event_id'] ?? '' ), 0, 64 ),
			'quote_src' => self::quote( $payload['quote'] ?? null ), 'mentions_me' => $is_group && ! empty( $payload['mentionsMe'] ) ? 1 : 0,
		);
		if ( is_callable( self::$emitter ) ) { $id = (int) call_user_func( self::$emitter, $body ); }
		elseif ( class_exists( 'BizCity_Zalo_Inbound_Emitter' ) ) { $id = (int) BizCity_Zalo_Inbound_Emitter::instance()->emit( $body ); }
		else { return self::result( 'dropped_unlinked', 0 ); }
		return self::result( -1 === $id ? 'dropped_unlinked' : ( 0 === $id ? 'retryable_failure' : 'emitted' ), $id );
	}

	private static function sent( array $event, array $payload, array $ctx ): array {
		$origin = (string) ( $payload['origin'] ?? '' );
		$ids = array_values( array_filter( array_map( 'strval', (array) ( $payload['zaloMsgIds'] ?? array() ) ) ) );
		$is_ours = 'api' === $origin && ( (string) ( $ctx['api_client_id'] ?? '' ) === (string) ( $payload['apiClientId'] ?? '' ) || array_intersect( $ids, array_map( 'strval', (array) ( $ctx['zalo_msg_ids'] ?? array() ) ) ) );
		if ( $is_ours ) { if ( is_callable( self::$link ) ) { call_user_func( self::$link, $ids, $event ); } return self::result( 'linked_own_echo', 0 ); }
		if ( ! in_array( $origin, array( 'bot', 'dashboard' ), true ) ) { return self::result( 'ignored_unknown_type', 0 ); }
		$body = array( 'account_id' => 'rzh:' . (string) ( $event['account_id'] ?? '' ), 'conversation_id' => (string) ( $payload['threadId'] ?? '' ), 'thread_kind' => 'group' === (string) ( $payload['threadType'] ?? '' ) ? 'group' : 'personal', 'message_id' => (string) ( $ids[0] ?? '' ), 'text' => (string) ( $payload['text'] ?? '' ), 'sent_at' => (string) ( $event['timestamp'] ?? '' ), 'transport' => 'remote_zalo_hub', 'trace_ref' => 'rzh-' . (string) ( $event['event_id'] ?? '' ) );
		if ( is_callable( self::$outbound ) ) { $id = (int) call_user_func( self::$outbound, array( 'sender_type' => 'bot' === $origin ? 'agent_bot' : 'agent', 'content' => $body['text'], 'external_source_id' => 'zalo:rzh:' . (string) ( $ids[0] ?? '' ), 'ai_metadata' => array( 'transport' => 'remote_zalo_hub', 'remote_origin' => $origin ) ) ); }
		elseif ( class_exists( 'BizCity_Zalo_Hub_Events' ) && class_exists( 'BizCity_CRM_Facebook_Ingestor' ) ) { $id = (int) BizCity_CRM_Facebook_Ingestor::instance()->ingest_outbound( 'zalo_personal', BizCity_Zalo_Hub_Events::build_outbound_echo( $body ) ); }
		else { return self::result( 'dropped_unlinked', 0 ); }
		return self::result( 'outbound_recorded', $id );
	}

	private static function status( array $event, array $payload ): array {
		if ( is_callable( self::$link ) ) { call_user_func( self::$link, array( 'account_id' => 'rzh:' . (string) ( $event['account_id'] ?? '' ), 'status' => 'running' === (string) ( $payload['status'] ?? '' ) ? 'connected' : 'disconnected' ), $event ); }
		return self::result( 'status_updated', 0 );
	}

	private static function bot_state( array $event, array $payload ): array {
		$states = get_option( self::THREADS_OPTION, array() ); $states = is_array( $states ) ? $states : array();
		$key = 'rzh:' . (string) ( $event['account_id'] ?? '' ) . '|' . (string) ( $payload['threadId'] ?? '' );
		$states[ $key ] = array( 'bot_enabled' => ! empty( $payload['botEnabled'] ), 'paused_until' => $payload['pausedUntil'] ?? null, 'seen_at' => gmdate( 'c' ) );
		update_option( self::THREADS_OPTION, $states, false );
		return self::result( 'bot_state_cached', 0 );
	}

	private static function attachment_label( string $kind, string $name ): string { switch ( $kind ) { case 'image': return '[Ảnh]'; case 'voice': return '[Tin nhắn thoại]'; case 'file': return '[Tệp: ' . $name . ']'; case 'sticker': return '[Nhãn dán]'; } return ''; }
	private static function quote( $quote ): array { if ( ! is_array( $quote ) ) { return array(); } return array( 'msgId' => (string) ( $quote['msgId'] ?? $quote['messageId'] ?? '' ), 'uid' => (string) ( $quote['uid'] ?? $quote['senderId'] ?? '' ), 'text' => (string) ( $quote['text'] ?? '' ) ); }
	private static function result( string $name, int $id ): array { return array( 'result' => $name, 'crm_message_id' => $id ); }
}
