<?php
/**
 * BizCity CRM — Conversation Identity Resolver.
 *
 * Resolves canonical identity/session keys from CRM conversation data:
 * inbox.channel_type + inbox.channel_ref_id + contact_inboxes.source_id.
 *
 * @package BizCity_Twin_CRM
 */

defined( 'ABSPATH' ) || exit;

class BizCity_CRM_Conversation_Identity_Resolver {

	/**
	 * Resolve identity/session context for a CRM conversation.
	 *
	 * @param int $conv_id Conversation ID.
	 * @return array|null
	 */
	public static function resolve_for_conversation( int $conv_id ): ?array {
		// [2026-07-06 Johnny Chu] PHASE-0.48 ID-MEM — canonicalize CRM conversation identity for memory/session continuity.
		if ( $conv_id <= 0 ) {
			return null;
		}
		if ( ! class_exists( 'BizCity_CRM_Repository' ) || ! class_exists( 'BizCity_CRM_DB_Installer_V2' ) ) {
			return null;
		}

		$conv = BizCity_CRM_Repository::get_conversation( $conv_id );
		if ( ! is_array( $conv ) ) {
			return null;
		}

		$inbox = BizCity_CRM_Repository::get_inbox( (int) ( $conv['inbox_id'] ?? 0 ) );
		if ( ! is_array( $inbox ) ) {
			return null;
		}

		global $wpdb;
		$ci_tbl = BizCity_CRM_DB_Installer_V2::tbl_contact_inboxes();
		$ci_row = $wpdb->get_row( $wpdb->prepare(
			"SELECT source_id FROM {$ci_tbl} WHERE id = %d LIMIT 1",
			(int) ( $conv['contact_inbox_id'] ?? 0 )
		), ARRAY_A );

		$source_id = (string) ( $ci_row['source_id'] ?? '' );
		if ( $source_id === '' ) {
			return null;
		}

		$channel_type = strtolower( (string) ( $inbox['channel_type'] ?? '' ) );
		$account_id   = (string) ( $inbox['channel_ref_id'] ?? '' );

		$keys = self::compose_session_keys( $channel_type, $account_id, $source_id );
		if ( ! is_array( $keys ) ) {
			return null;
		}

		return array(
			'conversation_id'         => $conv_id,
			'inbox_id'                => (int) ( $conv['inbox_id'] ?? 0 ),
			'contact_inbox_id'        => (int) ( $conv['contact_inbox_id'] ?? 0 ),
			'channel_type'            => $channel_type,
			'account_id'              => $account_id,
			'client_id'               => $source_id,
			'canonical_identity_key'  => $channel_type . ':' . $account_id . ':' . $source_id,
			'canonical_session_key'   => (string) $keys['canonical_session_key'],
			'canonical_chat_id'       => (string) $keys['canonical_chat_id'],
			'llm_session_id'          => (string) $keys['llm_session_id'],
			'platform_type_hint'      => (string) $keys['platform_type_hint'],
			'legacy_session_key'      => 'crm_' . $conv_id,
		);
	}

	/**
	 * Compose canonical keys from channel/account/client tuple.
	 *
	 * @param string $channel_type CRM inbox channel type.
	 * @param string $account_id   CRM inbox channel_ref_id.
	 * @param string $client_id    CRM contact_inboxes.source_id.
	 * @return array
	 */
	private static function compose_session_keys( string $channel_type, string $account_id, string $client_id ): array {
		$channel_type = strtolower( $channel_type );

		switch ( $channel_type ) {
			case 'facebook':
				// [2026-07-06 Johnny Chu] PHASE-0.48 ID-MEM — normalize fb_feed_{page_id} to canonical page_id.
				if ( strpos( $account_id, 'fb_feed_' ) === 0 ) {
					$account_id = substr( $account_id, 8 );
				}
				$chat_id = 'fb_' . $account_id . '_' . $client_id;
				return array(
					'platform_type_hint'    => 'FB_MESS',
					'canonical_chat_id'     => $chat_id,
					'canonical_session_key' => $chat_id,
					'llm_session_id'        => $chat_id,
				);

			case 'zalo':
			case 'zalo_bot':
				$chat_id = 'zalobot_' . $account_id . '_' . $client_id;
				return array(
					'platform_type_hint'    => 'ZALO_BOT',
					'canonical_chat_id'     => $chat_id,
					'canonical_session_key' => $chat_id,
					'llm_session_id'        => $chat_id,
				);

			case 'zalo_oa':
				$chat_id = 'zalooa_' . $account_id . '_' . $client_id;
				return array(
					'platform_type_hint'    => 'ZALO_OA',
					'canonical_chat_id'     => $chat_id,
					'canonical_session_key' => $chat_id,
					'llm_session_id'        => $chat_id,
				);

			case 'webchat':
				$chat_id = 'webchat_' . $client_id;
				return array(
					'platform_type_hint'    => 'WEBCHAT',
					'canonical_chat_id'     => $chat_id,
					'canonical_session_key' => $chat_id,
					// Keep raw session id so existing webchat session/context stores still resolve.
					'llm_session_id'        => $client_id,
				);

			case 'telegram':
				$chat_id = 'tg_' . $account_id . '_' . $client_id;
				return array(
					'platform_type_hint'    => 'TELEGRAM',
					'canonical_chat_id'     => $chat_id,
					'canonical_session_key' => $chat_id,
					'llm_session_id'        => $chat_id,
				);

			default:
				$platform = strtoupper( $channel_type );
				$chat_id  = $channel_type . '_' . $account_id . '_' . $client_id;
				return array(
					'platform_type_hint'    => $platform,
					'canonical_chat_id'     => $chat_id,
					'canonical_session_key' => $chat_id,
					'llm_session_id'        => $chat_id,
				);
		}
	}
}
