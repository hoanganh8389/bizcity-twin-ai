<?php
/**
 * BizCity Zalo Inbound Emitter (PHASE-0.39)
 *
 * Converts raw sidecar inbound payload → canonical bizcity_zalo_message_received action.
 * Guarantees idempotency via bizcity_zalo_message_map (dedup by account_id + zalo_msg_id).
 *
 * Zone discriminator (R-ZP-4.1):
 *   kind='personal' → platform='ZALO_PERSONAL'
 *   kind='oa'       → platform='ZALO_OA'
 *   NEVER 'ZALO_BOT' (that zone belongs to bizcity-zalo-bot plugin).
 *
 * @package BizCity_Zalo_Personal
 * @since   1.0.0
 */

// [2026-06-07 Johnny Chu] PHASE-0.39 — inbound emitter with dedup + zone discriminator R-ZP-4.1
defined( 'ABSPATH' ) || exit;

class BizCity_Zalo_Inbound_Emitter {

	/** @var self|null */
	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Emit inbound event from bridge body.
	 * Returns CRM message_id, 0 for retryable ingest failure, or -1 for intentional drop.
	 *
	 * @param array $body Raw JSON body from zca-bridge sidecar.
	 * @return int CRM message_id, 0 for retryable failure, or -1 for self/unbound drop.
	 */
	public function emit( array $body ): int {
		$kind       = sanitize_key( (string) ( $body['kind']       ?? 'personal' ) );
		$account_id = (string) ( $body['account_id'] ?? '' );
		$zalo_msg_id = (string) ( $body['message_id'] ?? '' );

		if ( $account_id === '' || $zalo_msg_id === '' ) {
			return 0;
		}
		// [2026-08-23 Johnny Chu] PHASE-0.39D — this plugin owns Personal only; OA must use its own plugin boundary.
		if ( 'personal' !== $kind ) {
			return -1;
		}
		$thread_kind = sanitize_key( (string) ( $body['thread_kind'] ?? '' ) );
		$is_group = 'group' === $thread_kind || ! empty( $body['is_group'] );
		$thread_id = (string) ( $body['thread_id'] ?? $body['conversation_id'] ?? '' );
		// [2026-08-25 Johnny Chu] PHASE-0.39F-GROUP-INBOX — accept groups only with a stable group thread ID; never infer it from the sender.
		if ( $is_group && $thread_id === '' ) { return -1; }
		// [2026-08-23 Johnny Chu] PHASE-0.39D — CRM-originated echoes are deduped; native app messages are mirrored as outgoing CRM rows.
		if ( ! empty( $body['is_self'] ) ) {
			if ( 'native_zalo' !== sanitize_key( (string) ( $body['origin'] ?? '' ) ) ) {
				return -1;
			}
			$db_account = BizCity_Zalo_Mapping_Repo::find_account_by_bridge_id( 'personal', $account_id );
			$local_account_id = $db_account ? (int) $db_account['id'] : 0;
			if ( $local_account_id <= 0 || (int) ( $db_account['owner_user_id'] ?? 0 ) <= 0 || (int) ( $db_account['crm_inbox_id'] ?? 0 ) <= 0 ) {
				return -1;
			}
			$peer_id = $is_group ? $thread_id : (string) ( $body['from_user_id'] ?? '' );
			if ( $peer_id === '' || '0' === $peer_id || ! class_exists( 'BizCity_CRM_Facebook_Ingestor' ) ) {
				return 0;
			}
			$existing = BizCity_Zalo_Mapping_Repo::find_by_zalo_msg_id( $local_account_id, $zalo_msg_id );
			if ( $existing !== null && (int) ( $existing['crm_message_id'] ?? 0 ) > 0 ) {
				return (int) $existing['crm_message_id'];
			}
			if ( null === $existing ) {
				BizCity_Zalo_Mapping_Repo::save_map( array(
					'account_id'     => $local_account_id,
					'zalo_msg_id'    => $zalo_msg_id,
					'zalo_thread_id' => $peer_id,
					// [2026-08-26 Johnny Chu] PHASE-0.39F-GROUP-INBOX — keep native group self-echo mappings on the group thread.
					'thread_kind'    => $is_group ? 'group' : 'personal',
					'crm_message_id' => 0,
					'direction'      => 'out',
					'quote_src_json' => '',
				) );
			}
			$content_type = sanitize_key( (string) ( $body['message_type'] ?? 'text' ) );
			$content_type = in_array( $content_type, array( 'image', 'file' ), true ) ? $content_type : 'text';
			$attachments = array();
			if ( 'image' === $content_type && ! empty( $body['image_url'] ) ) {
				$attachments[] = array( 'file_type' => 'image', 'data_url' => (string) $body['image_url'], 'meta' => array( 'origin' => 'native_zalo' ) );
			} elseif ( 'file' === $content_type && ! empty( $body['file_url'] ) ) {
				$attachments[] = array( 'file_type' => 'file', 'data_url' => (string) $body['file_url'], 'meta' => array( 'file_name' => (string) ( $body['file_name'] ?? '' ), 'origin' => 'native_zalo' ) );
			}
			$created_at = isset( $body['message_time'] ) && (int) $body['message_time'] > 0
				? gmdate( 'Y-m-d H:i:s', (int) $body['message_time'] )
				: current_time( 'mysql' );
			$msg_id = BizCity_CRM_Facebook_Ingestor::instance()->ingest_outbound( 'zalo_personal', array(
				'inbox_ref'          => $account_id,
				'inbox_name'         => 'Zalo Cá nhân ' . (string) ( $body['account_name'] ?? $account_id ),
				'source_id'          => $is_group ? 'group:' . $peer_id : $peer_id, // [2026-08-25 Johnny Chu] PHASE-0.39F-GROUP-INBOX — keep self-echo on the group source key.
				// [2026-08-23 Johnny Chu] PHASE-0.39D — native self sender name belongs to the agent, never seed the peer contact name.
				'contact_name'       => '',
				'content'            => (string) ( $body['message_text'] ?? '' ),
				'content_type'       => $content_type,
				'external_source_id' => 'zalo:self:' . $zalo_msg_id,
				'received_at'        => $created_at,
				'sender_type'        => 'agent',
				'contact_name_source' => 'self_echo_unreliable',
				'ai_metadata'        => array(
					'zalo_personal_origin' => 'native_zalo',
					'contact_name_source'  => 'self_echo_unreliable',
					'thread_kind'         => $is_group ? 'group' : 'personal',
					'group_id'            => $is_group ? $peer_id : '',
					'trace_id'             => sanitize_text_field( (string) ( $body['trace_id'] ?? '' ) ),
				),
				'thread_kind'        => $is_group ? 'group' : 'personal',
				'group_id'           => $is_group ? $peer_id : '',
				'attachments'        => $attachments,
			) );
			if ( $msg_id > 0 ) {
				BizCity_Zalo_Mapping_Repo::link_crm_message( $local_account_id, $zalo_msg_id, $msg_id );
			}
			return $msg_id > 0 ? $msg_id : 0;
		}

		// Resolve local DB account record.
		$db_account = BizCity_Zalo_Mapping_Repo::find_account_by_bridge_id( $kind, $account_id );
		$local_account_id = $db_account ? (int) $db_account['id'] : 0;
		// [2026-08-21 Johnny Chu] PHASE-0.39B — refuse unbound accounts before any CRM hook or write.
		if ( 'personal' === $kind && ( $local_account_id <= 0 || (int) ( $db_account['owner_user_id'] ?? 0 ) <= 0 || (int) ( $db_account['crm_inbox_id'] ?? 0 ) <= 0 ) ) {
			return -1;
		}

		// [2026-08-21 Johnny Chu] PHASE-0.39B — claim before hooks; rows with crm_message_id=0 remain retryable.
		$existing = $local_account_id > 0
			? BizCity_Zalo_Mapping_Repo::find_by_zalo_msg_id( $local_account_id, $zalo_msg_id )
			: null;
		if ( $existing !== null && (int) ( $existing['crm_message_id'] ?? 0 ) > 0 ) {
			return (int) $existing['crm_message_id'];
		}
		if ( $local_account_id > 0 && null === $existing ) {
			$claimed = BizCity_Zalo_Mapping_Repo::save_map( array(
				'account_id'     => $local_account_id,
				'zalo_msg_id'    => $zalo_msg_id,
				'zalo_thread_id' => $is_group ? $thread_id : (string) ( $body['from_user_id'] ?? '' ),
				// [2026-08-25 Johnny Chu] PHASE-0.39F-GROUP-INBOX — mapping follows the canonical group/private thread kind.
				'thread_kind'    => $is_group ? 'group' : 'personal',
				'crm_message_id' => 0,
				'direction'      => 'in',
				'quote_src_json' => '',
			) );
			if ( $claimed <= 0 ) {
				$existing = BizCity_Zalo_Mapping_Repo::find_by_zalo_msg_id( $local_account_id, $zalo_msg_id );
				if ( (int) ( $existing['crm_message_id'] ?? 0 ) > 0 ) {
					return (int) $existing['crm_message_id'];
				}
			}
		}

		// [2026-06-07 Johnny Chu] PHASE-0.39 R-ZP-4.1 — Personal-only zone discriminator (never ZALO_BOT or ZALO_OA).
		$platform = 'ZALO_PERSONAL';
		// [2026-06-13 Johnny Chu] PHASE-0.40 G0.3 — add 'code' discriminator so G0.2 guards can bail.
		$adapter_code = 'zalo_personal';

		$from_user_id   = (string) ( $body['from_user_id'] ?? '' );
		$from_user_name = (string) ( $body['from_user_name'] ?? '' );

		// Build canonical trigger_data for CRM Adapter.
		$trigger_data = array(
			'platform'         => $platform,
			'code'             => $adapter_code,
			'conversation_id'  => $account_id, // bridge account_id acts as inbox ref key.
			'account_id'       => $account_id,
			'account_name'     => (string) ( $body['account_name'] ?? '' ),
			'from_user_id'     => $from_user_id,
			'from_user_name'   => $from_user_name,
			// [2026-08-25 Johnny Chu] PHASE-0.39F-GROUP-INBOX — preserve group thread identity; sender remains message-level metadata.
			'thread_kind'      => $is_group ? 'group' : 'personal',
			'thread_id'        => $is_group ? $thread_id : $from_user_id,
			'is_group'         => $is_group,
			'group_id'         => $is_group ? $thread_id : '',
			'group_name'       => $is_group ? (string) ( $body['group_name'] ?? '' ) : '',
			'message_id'       => $zalo_msg_id,
			'message_text'     => (string) ( $body['message_text'] ?? '' ),
			'message_type'     => (string) ( $body['message_type'] ?? 'text' ),
			'message_time'     => isset( $body['message_time'] ) ? (int) $body['message_time'] : time(),
			'trace_id'         => sanitize_text_field( (string) ( $body['trace_id'] ?? '' ) ),
			'image_url'        => (string) ( $body['image_url'] ?? '' ),
			'file_url'         => (string) ( $body['file_url'] ?? '' ),
			'file_name'        => (string) ( $body['file_name'] ?? '' ),
			'_zalo_local_account_id' => $local_account_id,
		);

		// Fire WP action → Universal Listener routes Personal to its own CRM trigger.
		do_action( 'bizcity_zalo_message_received', $trigger_data );

		$mapped = BizCity_Zalo_Mapping_Repo::find_by_zalo_msg_id( $local_account_id, $zalo_msg_id );
		return (int) ( $mapped['crm_message_id'] ?? 0 );
	}
}
