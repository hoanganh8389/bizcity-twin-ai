<?php
/**
 * BizCity CRM - Zalo Bot Admin Operations adapter.
 *
 * Zalo Bot shares CRM storage with Customer Care, but remains a Zone 2
 * channel with Automation as the automatic reply owner.
 *
 * @package BizCity_Twin_CRM
 * @since   R-CRM-ZALOBOT-ADMIN-ZONE 2026-08-30
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Adapter_ZaloBot' ) ) {
	return;
}

class BizCity_CRM_Adapter_ZaloBot extends BizCity_CRM_Adapter_Zalo {

	public function code(): string {
		// [2026-08-30 Johnny Chu] R-CRM-ZALOBOT-ADMIN-ZONE - keep Bot distinct from legacy Zalo/OA codes.
		return 'zalo_bot';
	}

	public function label(): string {
		// [2026-08-30 Johnny Chu] R-CRM-ZALOBOT-ADMIN-ZONE - expose the Admin Operations label.
		return 'Zalo Bot (Internal Operations)';
	}

	public function normalize_inbound( array $raw ): ?array {
		// [2026-08-30 Johnny Chu] R-CRM-ZALOBOT-ADMIN-ZONE - normalize Bot identity before the shared CRM gate.
		$normalized = parent::normalize_inbound( $raw );
		if ( ! is_array( $normalized ) ) {
			return null;
		}

		$chat = self::parse_chat_id( (string) ( $raw['chat_id'] ?? '' ) );
		$bot_id = (string) ( $raw['bot_id'] ?? ( $chat['bot_id'] ?? $normalized['inbox_ref'] ?? '' ) );
		if ( $bot_id === '' ) {
			return null;
		}

		$thread_kind = (string) ( $chat['thread_kind'] ?? ( $raw['thread_kind'] ?? '' ) );
		$thread_kind = $thread_kind === 'group' ? 'group' : 'personal';
		$source_id = (string) ( $chat['source_id'] ?? $raw['from_user_id'] ?? '' );
		$group_id = (string) ( $chat['group_id'] ?? $raw['group_id'] ?? $raw['thread_id'] ?? '' );
		if ( $thread_kind === 'group' ) {
			if ( $group_id === '' ) {
				return null;
			}
			$source_id = 'group:' . $group_id;
			$normalized['contact_name'] = (string) ( $raw['group_name'] ?? '' );
			if ( $normalized['contact_name'] === '' ) {
				$normalized['contact_name'] = 'Zalo Bot group ' . substr( $group_id, -8 );
			}
			$normalized['group_id'] = $group_id;
			$normalized['sender_user_id'] = (string) ( $raw['from_user_id'] ?? '' );
			$normalized['sender_name'] = (string) ( $raw['from_user_name'] ?? '' );
		} elseif ( $source_id === '' ) {
			return null;
		}

		$normalized['inbox_ref'] = $bot_id;
		$normalized['inbox_name'] = 'Zalo Bot ' . (string) ( $raw['bot_name'] ?? $bot_id );
		$normalized['source_id'] = $source_id;
		$normalized['thread_kind'] = $thread_kind;
		$normalized['ai_metadata'] = array_merge( (array) ( $normalized['ai_metadata'] ?? array() ), array(
			'thread_kind' => $thread_kind,
		) );
		if ( $group_id !== '' ) {
			$normalized['ai_metadata']['group_id'] = $group_id;
		}
		if ( ! empty( $normalized['sender_user_id'] ) ) {
			$normalized['ai_metadata']['sender_user_id'] = (string) $normalized['sender_user_id'];
		}
		if ( ! empty( $normalized['sender_name'] ) ) {
			$normalized['ai_metadata']['sender_name'] = (string) $normalized['sender_name'];
		}
		return $normalized;
	}

	public function send( array $conversation, array $message ): array {
		// [2026-08-30 Johnny Chu] R-CRM-ZALOBOT-ADMIN-ZONE - send Bot replies through the canonical Gateway sender.
		if ( class_exists( 'BizCity_Channel_File_Logger' ) ) {
			BizCity_Channel_File_Logger::write(
				BizCity_Channel_File_Logger::CH_ZALO_BOT,
				BizCity_Channel_File_Logger::LEVEL_INFO,
				'outbound_attempt',
				'Zalo Bot outbound requested.',
				array(
					'conversation_id' => (int) ( $conversation['id'] ?? 0 ),
					'inbox_id'        => (int) ( $conversation['inbox_id'] ?? 0 ),
					'content_type'    => sanitize_key( (string) ( $message['content_type'] ?? 'text' ) ),
				)
			);
		}

		$inbox = BizCity_CRM_Repository::get_inbox( (int) ( $conversation['inbox_id'] ?? 0 ) );
		if ( ! is_array( $inbox ) || (string) ( $inbox['channel_type'] ?? '' ) !== 'zalo_bot' ) {
			return array( 'success' => false, 'external_source_id' => null, 'error' => 'zalo_bot_inbox_not_found' );
		}
		$bot_id = (string) ( $inbox['channel_ref_id'] ?? '' );
		$source_id = $this->resolve_uid_from_conversation( $conversation );
		if ( $bot_id === '' || $source_id === '' || ! class_exists( 'BizCity_Gateway_Sender' ) ) {
			return array( 'success' => false, 'external_source_id' => null, 'error' => 'zalo_bot_gateway_unavailable' );
		}

		$thread_kind = 'personal';
		$chat_kind = 'private';
		if ( strpos( $source_id, 'group:' ) === 0 ) {
			$thread_kind = 'group';
			$chat_kind = 'group';
			$source_id = substr( $source_id, 6 );
		}
		$chat_id = 'zalobot_' . $bot_id . '_' . $chat_kind . '_' . $source_id;
		$text = (string) ( $message['content'] ?? '' );
		$content_type = (string) ( $message['content_type'] ?? 'text' );
		$attachments = is_array( $message['attachments'] ?? null ) ? $message['attachments'] : array();
		$first_attachment = $attachments[0] ?? array();
		$attachment_url = is_array( $first_attachment ) ? (string) ( $first_attachment['data_url'] ?? '' ) : '';
		$payload = $content_type === 'image' && $attachment_url !== '' ? $attachment_url : $text;
		$result = BizCity_Gateway_Sender::instance()->send(
			$chat_id,
			$payload,
			$content_type === 'image' ? 'image' : 'text',
			array(
				'_trace_source' => 'crm.adapter.zalo_bot',
				'thread_kind'  => $thread_kind,
			)
		);
		$sent = is_array( $result ) && ! empty( $result['sent'] );
		return array(
			'success'            => $sent,
			'external_source_id' => $sent ? (string) ( $result['extra']['message_id'] ?? ( $result['extra']['mid'] ?? '' ) ) : '',
			'error'              => $sent ? null : ( is_array( $result ) ? (string) ( $result['error'] ?? 'zalo_bot_send_failed' ) : 'zalo_bot_send_failed' ),
		);
	}

	/**
	 * Parse the canonical Bot chat id shared by inbound and outbound paths.
	 *
	 * @return array|null
	 */
	public static function parse_chat_id( string $chat_id ): ?array {
		// [2026-08-30 Johnny Chu] R-CRM-ZALOBOT-ADMIN-ZONE - provide one parser for private/group identity normalization.
		$prefix = 'zalobot_';
		if ( strpos( $chat_id, $prefix ) !== 0 ) {
			return null;
		}
		$parts = explode( '_', substr( $chat_id, strlen( $prefix ) ), 3 );
		if ( count( $parts ) === 3 && in_array( $parts[1], array( 'private', 'group' ), true ) && $parts[2] !== '' ) {
			return array(
				'bot_id'      => $parts[0],
				'thread_kind' => $parts[1] === 'group' ? 'group' : 'personal',
				'source_id'   => $parts[1] === 'group' ? 'group:' . $parts[2] : $parts[2],
				'group_id'    => $parts[1] === 'group' ? $parts[2] : '',
			);
		}
		if ( count( $parts ) === 2 && $parts[0] !== '' && $parts[1] !== '' ) {
			return array(
				'bot_id'      => $parts[0],
				'thread_kind' => 'personal',
				'source_id'   => $parts[1],
				'group_id'    => '',
			);
		}
		return null;
	}
}
