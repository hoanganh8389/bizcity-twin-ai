<?php
/**
 * CRM Inbox Bridge — Zone 1 Channel → CRM Conversations
 *
 * Bridges inbound messages from Zone 1 channels (Facebook, Messenger, Zalo OA,
 * Zalo Personal, WebChat, Email) into the CRM layer (bizcity_crm_contacts,
 * bizcity_crm_conversations, bizcity_crm_messages).
 *
 * Zone 2 channels (ZALO_BOT, TELEGRAM, ADMINCHAT, TWINCHAT_BE) are EXCLUDED —
 * those are admin/command channels that go to automation, not CRM inbox.
 *
 * R-ZONE compliance:
 *   - Zone 1 → bizcity_crm_* (CRM Inbox)
 *   - Zone 2 → bail early
 *
 * R-UNIFY compliance:
 *   - Uses BizCity_CRM_Contact_Identity::resolve_or_create() for dedup
 *   - Links channel message to a crm_conversation (by channel_thread_id / chat_id)
 *
 * Graceful degradation: skips silently if CRM tables don't exist yet.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Scheduler
 * @since      2026-06-15 (R-UNIFY GAP-B)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( class_exists( 'BizCity_CRM_Inbox_Bridge' ) ) {
	return;
}

final class BizCity_CRM_Inbox_Bridge {

	/**
	 * Zone 2 platform codes (uppercase) — MUST NOT go to CRM Inbox.
	 * See R-ZONE: bizcity-twin-ai/.github/copilot-instructions.md
	 */
	const ZONE2_PLATFORMS = array(
		'ZALO_BOT',
		'TELEGRAM',
		'ADMINCHAT',
		'TWINCHAT_BE',
	);

	/**
	 * Attach the inbound message listener.
	 *
	 * Called from scheduler/bootstrap.php.
	 */
	public static function init() {
		// [2026-06-15 Johnny Chu] R-UNIFY GAP-B — bridge Zone 1 channel messages to CRM.
		// Priority 20: after platform detection (10), before intent routing (30).
		add_action( 'bizcity_channel_message_received', array( __CLASS__, 'on_message' ), 20, 1 );
	}

	/**
	 * Handle an inbound channel message.
	 *
	 * @param array $payload  Normalized payload from BizCity_Gateway_Bridge::handle_inbound().
	 *                        Fields: platform, chat_id, user_psid?, message?, text?, sender_id?,
	 *                               message_id?, thread_id?, code?, bot_id?, wp_user_id?
	 */
	public static function on_message( array $payload ) {
		// [2026-06-15 Johnny Chu] R-UNIFY GAP-B — Zone 2 bail.
		$platform_raw = strtoupper( (string) ( $payload['platform'] ?? '' ) );
		if ( in_array( $platform_raw, self::ZONE2_PLATFORMS, true ) ) {
			return; // Zone 2 — admin/bot command channels, not CRM inbox.
		}

		if ( $platform_raw === '' ) {
			return;
		}

		$chat_id    = (string) ( $payload['chat_id'] ?? '' );
		$message_id = (string) ( $payload['message_id'] ?? '' );
		$text       = (string) ( $payload['message'] ?? $payload['text'] ?? '' );
		$sender_id  = (string) ( $payload['user_psid'] ?? $payload['sender_id'] ?? '' );
		$thread_id  = (string) ( $payload['thread_id'] ?? '' );
		$account_id = (string) ( $payload['bot_id'] ?? $payload['account_id'] ?? '' );

		if ( $chat_id === '' ) {
			return;
		}

		// Resolve or create CRM contact.
		$contact_id = 0;
		if ( class_exists( 'BizCity_CRM_Contact_Identity' ) ) {
			$platform_uid    = $sender_id !== '' ? $sender_id : $chat_id;
			$create_data     = array(
				'wp_user_id' => isset( $payload['wp_user_id'] ) ? (int) $payload['wp_user_id'] : 0,
				'display_name' => '',
			);
			$contact_id = BizCity_CRM_Contact_Identity::resolve_or_create(
				$platform_raw,
				$platform_uid,
				$account_id,
				$create_data
			);
		}

		// Find or create conversation.
		$conversation_id = self::upsert_conversation( $platform_raw, $chat_id, $thread_id, $contact_id, $account_id );

		// Log the message.
		if ( $conversation_id > 0 ) {
			self::insert_message( $conversation_id, $platform_raw, $message_id, $chat_id, $sender_id, $text, $payload );
		}
	}

	/* ──────────────────────────────────────────────────────────────
	 *  Private Helpers
	 * ────────────────────────────────────────────────────────────── */

	/**
	 * Find or create a CRM conversation for this chat session.
	 * Uses (platform, channel_thread_id) or (platform, chat_id) as unique key.
	 *
	 * Gracefully returns 0 if table does not exist.
	 *
	 * @param string $platform
	 * @param string $chat_id
	 * @param string $thread_id
	 * @param int    $contact_id
	 * @param string $account_id
	 * @return int  conversation_id, 0 on failure.
	 */
	private static function upsert_conversation( $platform, $chat_id, $thread_id, $contact_id, $account_id ) {
		global $wpdb;
		$tbl = $wpdb->prefix . 'bizcity_crm_conversations';

		// Check table exists (graceful degrade).
		if ( ! self::table_exists( $tbl ) ) {
			return 0;
		}

		$lookup_key = $thread_id !== '' ? $thread_id : $chat_id;

		// Try find existing.
		$existing = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$tbl} WHERE platform = %s AND channel_thread_id = %s LIMIT 1",
			$platform,
			$lookup_key
		) );
		if ( $existing > 0 ) {
			// Update contact_id if newly resolved.
			if ( $contact_id > 0 ) {
				$wpdb->query( $wpdb->prepare(
					"UPDATE {$tbl} SET contact_id = %d, updated_at = %s WHERE id = %d AND contact_id = 0",
					$contact_id,
					current_time( 'mysql' ),
					$existing
				) );
			}
			return $existing;
		}

		// Create new.
		$row = array(
			'platform'          => $platform,
			'channel_thread_id' => $lookup_key,
			'chat_id'           => $chat_id,
			'contact_id'        => $contact_id > 0 ? $contact_id : null,
			'account_id'        => $account_id !== '' ? $account_id : null,
			'status'            => 'open',
			'blog_id'           => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1,
			'created_at'        => current_time( 'mysql' ),
			'updated_at'        => current_time( 'mysql' ),
		);
		$ok = $wpdb->insert( $tbl, $row );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Insert a message into bizcity_crm_messages, dedup by (conversation_id, platform_msg_id).
	 *
	 * Gracefully returns 0 if table does not exist.
	 *
	 * @param int    $conversation_id
	 * @param string $platform
	 * @param string $message_id
	 * @param string $chat_id
	 * @param string $sender_id
	 * @param string $text
	 * @param array  $payload  Full normalized payload (for extra fields).
	 * @return int  message row id, 0 on failure.
	 */
	private static function insert_message( $conversation_id, $platform, $message_id, $chat_id, $sender_id, $text, array $payload ) {
		global $wpdb;
		$tbl = $wpdb->prefix . 'bizcity_crm_messages';

		if ( ! self::table_exists( $tbl ) ) {
			return 0;
		}

		// Dedup by platform_msg_id.
		if ( $message_id !== '' ) {
			$dup = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$tbl} WHERE conversation_id = %d AND platform_msg_id = %s LIMIT 1",
				$conversation_id,
				$message_id
			) );
			if ( $dup > 0 ) {
				return $dup;
			}
		}

		// [2026-07-05 Johnny Chu] R-UNIFY GAP-B — inbox_id=0 for bridge messages (no structured inbox required)
		$row = array(
			'conversation_id' => $conversation_id,
			'inbox_id'        => 0,
			'platform'        => $platform,
			'platform_msg_id' => $message_id !== '' ? $message_id : null,
			'sender_type'     => 'customer',
			'sender_id'       => $sender_id !== '' ? $sender_id : $chat_id,
			'body'            => $text,
			'payload_json'    => wp_json_encode( $payload ),
			'created_at'      => current_time( 'mysql' ),
		);
		$ok = $wpdb->insert( $tbl, $row );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Check if a table exists in the DB.
	 * Cached in a static variable to avoid repeated SHOW TABLES calls.
	 *
	 * @param string $table  Full table name.
	 * @return bool
	 */
	private static function table_exists( $table ) {
		static $cache = array();
		if ( isset( $cache[ $table ] ) ) {
			return $cache[ $table ];
		}
		global $wpdb;
		$exists = (bool) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s',
			$table
		) );
		$cache[ $table ] = $exists;
		return $exists;
	}
}
