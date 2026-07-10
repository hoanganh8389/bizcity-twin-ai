<?php
/**
 * BizCity CRM — Zalo OA (Official Account) Channel Adapter.
 *
 * Thin subclass of BizCity_CRM_Adapter_Zalo that:
 *  - Returns code() = 'zalo_oa' so the CRM inbox channel_type = 'zalo_oa'
 *    and BizCity_CRM_Guru_Resolver::resolve_for_inbox() correctly resolves the
 *    binding via UPPER('zalo_oa') = 'ZALO_OA' in bizcity_channel_bindings.
 *  - Overrides send() to use chat_id = 'zalooa_{oa_id}_{uid}' (not 'zalobot_…')
 *    so BizCity_Gateway_Sender routes via the ZALO_OA delivery path.
 *
 * normalize_inbound() is inherited from parent — the $_legacy_payload shape
 * that handle_webhook() builds for ZALO_OA is identical to what the parent expects.
 *
 * @package BizCity_Twin_CRM
 * @since   PHASE-0.39 GURU-BIND 2026-06-21
 */

// [2026-06-21 Johnny Chu] PHASE-0.39 GURU-BIND — new adapter enabling ZALO_OA auto-reply.

defined( 'ABSPATH' ) || exit;

class BizCity_CRM_Adapter_ZaloOA extends BizCity_CRM_Adapter_Zalo {

	public function code(): string  { return 'zalo_oa'; }
	public function label(): string { return 'Zalo OA (Kênh khách)'; }

	/**
	 * Send outbound reply via ZALO_OA.
	 *
	 * Uses BizCity_Integration_Registry to look up the Zalo OA channel integration
	 * and find the account matching channel_ref_id (OA numeric ID), then calls
	 * send_outbound(array $msg, array $account) directly — the same pattern used by
	 * send_legacy() in BizCity_Gateway_Sender for Zalo Bot.
	 *
	 * NOTE: We do NOT go through BizCity_Gateway_Sender::send() here because that
	 * method calls $adapter->send_outbound($chat_id, $message, $options) with 3
	 * positional string/string/array args, which mismatches the BizCity_Channel_Integration
	 * signature send_outbound(array $msg, array $account) → TypeError.
	 *
	 * @param array $conversation CRM conversation row (must contain inbox_id, contact_inbox_id).
	 * @param array $message      { content: string, content_type: string, attachments?: array }.
	 * @return array { success: bool, external_source_id: string|null, error: string|null }
	 */
	public function send( array $conversation, array $message ): array {
		// [2026-06-21 Johnny Chu] PHASE-0.39 GURU-BIND — ZALO_OA send via Integration_Registry.
		$inbox = BizCity_CRM_Repository::get_inbox( (int) ( $conversation['inbox_id'] ?? 0 ) );
		if ( ! $inbox ) {
			error_log( '[bizcity-crm-trace] P12 ZaloOA send FAIL: inbox not found inbox_id=' . ( $conversation['inbox_id'] ?? 'NULL' ) );
			return array( 'success' => false, 'external_source_id' => null, 'error' => 'inbox not found' );
		}

		$ref = (string) $inbox['channel_ref_id'];  // OA numeric ID e.g. '402129037615218619'
		$uid = $this->resolve_uid_from_conversation( $conversation );
		error_log( '[bizcity-crm-trace] P12 ZaloOA send START ref=' . $ref . ' uid=' . $uid );
		if ( $uid === '' ) {
			error_log( '[bizcity-crm-trace] P12 ZaloOA send FAIL: cannot resolve uid conv=' . ( $conversation['id'] ?? 'NULL' ) );
			return array( 'success' => false, 'external_source_id' => null, 'error' => 'cannot resolve Zalo user_id from conversation' );
		}

		$text         = (string) ( $message['content'] ?? '' );
		$content_type = (string) ( $message['content_type'] ?? 'text' );
		$attachments  = is_array( $message['attachments'] ?? null ) ? $message['attachments'] : array();
		$first_att    = $attachments[0] ?? null;
		$att_url      = is_array( $first_att ) ? (string) ( $first_att['data_url'] ?? '' ) : '';
		$type         = ( $content_type === 'image' && $att_url !== '' ) ? 'image' : 'text';
		$payload_text = ( $type === 'image' ) ? $att_url : $text;

		// Path 1 — BizCity_Integration_Registry: find zalo_oa channel + account by oa_id.
		// Pattern mirrors send_legacy(zalo_bot) in BizCity_Gateway_Sender.
		if ( class_exists( 'BizCity_Integration_Registry' ) && class_exists( 'BizCity_Channel_Integration' ) ) {
			$registry = BizCity_Integration_Registry::instance();
			$channel  = $registry->get( 'zalo_oa' );
			error_log( '[bizcity-crm-trace] P12 ZaloOA path1 channel=' . ( $channel ? get_class( $channel ) : 'NULL' ) );

			if ( $channel instanceof BizCity_Channel_Integration ) {
				$raw_accounts = $registry->get_accounts( 'zalo_oa' );  // raw (encrypted)
				$account_raw  = array();

				foreach ( $raw_accounts as $acc ) {
					// Match by OA numeric ID ('oa_id') or account slug ('_uid').
					if (
						(string) ( $acc['oa_id'] ?? '' ) === $ref ||
						(string) ( $acc['_uid']  ?? '' ) === $ref
					) {
						$account_raw = $acc;
						break;
					}
				}

				// Single-account fallback — if only one OA configured, use it.
				if ( empty( $account_raw ) && count( $raw_accounts ) === 1 ) {
					$account_raw = $raw_accounts[0];
				}

				error_log( '[bizcity-crm-trace] P12 ZaloOA path1 accounts=' . count( $raw_accounts ) . ' matched=' . ( ! empty( $account_raw ) ? 'yes' : 'no' ) );

				if ( ! empty( $account_raw ) ) {
					// [2026-06-21 Johnny Chu] PHASE-0.39 GURU-BIND — prefer BizCity_CG_Zalo_OA_Integration
					// (new direct PHP, no bridge). The registry may still return the old
					// BizCity_Zalo_OA_Integration (bridge-based) which fails with 'bridge_account_id missing'.
					// Instantiate the new class directly when available.
					if ( class_exists( 'BizCity_CG_Zalo_OA_Integration' ) ) {
						$sender = new BizCity_CG_Zalo_OA_Integration();
					} else {
						$sender = clone $channel;
					}
					$sender->set_account( $account_raw );
					$decrypted = $sender->get_decrypted_params();  // includes access_token

					$msg_payload = array(
						'recipient' => $uid,
						'text'      => $payload_text,
						'type'      => $type,
					);

					error_log( '[bizcity-crm-trace] P12 ZaloOA path1 sender=' . get_class( $sender ) . ' token_len=' . strlen( $decrypted['access_token'] ?? '' ) );
					$result = $sender->send_outbound( $msg_payload, $decrypted );

					if ( is_wp_error( $result ) ) {
						error_log( '[bizcity-crm-trace] P12 ZaloOA path1 FAIL WP_Error=' . $result->get_error_message() );
						return array( 'success' => false, 'external_source_id' => null, 'error' => $result->get_error_message() );
					}
					if ( is_array( $result ) ) {
						$ok = ! empty( $result['sent'] );
						error_log( '[bizcity-crm-trace] P12 ZaloOA path1 result sent=' . ( $ok ? 'YES' : 'NO' ) . ' error=' . ( $result['error'] ?? '' ) );
						return array(
							'success'            => $ok,
							'external_source_id' => (string) ( $result['mid'] ?? '' ),
							'error'              => $ok ? null : (string) ( $result['error'] ?? 'zalo_oa_send_failed' ),
						);
					}
				}
			}
		}

		// Path 2 — BizCity_CRM_Bridge_Zalo fallback (legacy access_token lookup).
		if ( class_exists( 'BizCity_CRM_Bridge_Zalo' ) ) {
			$token = BizCity_CRM_Bridge_Zalo::lookup_access_token( $ref );
			error_log( '[bizcity-crm-trace] P12 ZaloOA path2 token=' . ( $token !== '' ? 'found' : 'NOT_FOUND' ) );
			if ( $token !== '' ) {
				if ( $type === 'image' && $att_url !== '' ) {
					return BizCity_CRM_Bridge_Zalo::send_image( $token, $uid, $att_url );
				}
				return BizCity_CRM_Bridge_Zalo::send_text( $token, $uid, $text );
			}
		}

		error_log( '[bizcity-crm-trace] P12 ZaloOA FAIL no_send_path ref=' . $ref . ' uid=' . $uid );
		return array( 'success' => false, 'external_source_id' => null, 'error' => 'no_send_path_available' );
	}
}
