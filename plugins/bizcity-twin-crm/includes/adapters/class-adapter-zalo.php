<?php
/**
 * BizCity CRM — Zalo OA Channel Adapter.
 *
 * Wraps the existing `bizcity-zalo-bot` plugin (no fork).
 * - normalize_inbound() consumes trigger_data of:
 *     do_action( 'waic_twf_process_flow', 'bizcity_zalo_message_received', $trigger_data )
 *   shape (post Universal-Listener bridge):
 *     { bot_id, bot_name, account_id, account_name, event_name,
 *       from_user_id, from_user_name, message_id, conversation_id (= OA id),
 *       message_type, message_text, message_time, image_url, file_url, file_name, raw }
 * - send() delegates to BizCity_Zalo_Bot_API::send_text / send_image when available,
 *   otherwise falls back to a direct openapi.zalo.me POST using the OA access_token.
 *
 * @package BizCity_Twin_CRM
 * @since   PHASE 0.34
 */

defined( 'ABSPATH' ) || exit;

class BizCity_CRM_Adapter_Zalo extends BizCity_CRM_Adapter_Base {

	public function code(): string  { return 'zalo'; }
	public function label(): string { return 'Zalo OA'; }

	public function capabilities(): array {
		return array( 'text', 'image', 'file' );
	}

	public function normalize_inbound( array $raw ): ?array {
		$oa_id  = (string) ( $raw['conversation_id'] ?? $raw['account_id'] ?? '' );
		$uid    = (string) ( $raw['from_user_id']    ?? '' );
		$text   = (string) ( $raw['message_text']    ?? '' );
		$mid    = (string) ( $raw['message_id']      ?? '' );
		if ( $oa_id === '' || $uid === '' ) {
			return null;
		}

		$attachments = array();
		if ( ! empty( $raw['image_url'] ) ) {
			$attachments[] = array(
				'file_type' => 'image',
				'data_url'  => (string) $raw['image_url'],
				'meta'      => array(),
			);
		}
		if ( ! empty( $raw['file_url'] ) ) {
			$attachments[] = array(
				'file_type' => 'file',
				'data_url'  => (string) $raw['file_url'],
				'meta'      => array( 'file_name' => (string) ( $raw['file_name'] ?? '' ) ),
			);
		}

		$content_type = 'text';
		if ( $attachments ) {
			$content_type = $attachments[0]['file_type'] === 'image' ? 'image' : 'file';
		}

		$contact_name = (string) ( $raw['from_user_name'] ?? '' );
		if ( $contact_name === '' ) {
			$contact_name = 'Zalo ' . substr( $uid, -6 );
		}

		$ext = $mid !== '' ? ( 'zalo:' . $mid ) : sprintf( 'zalosim:%s:%s:%d', $oa_id, $uid, time() );

		// message_time may be a MySQL string already (from the bot) or a unix ms; normalise.
		$received_at = (string) ( $raw['message_time'] ?? '' );
		if ( $received_at === '' ) {
			$received_at = current_time( 'mysql' );
		} elseif ( ctype_digit( $received_at ) ) {
			$ts_s = ( strlen( $received_at ) > 10 ) ? (int) ( $received_at / 1000 ) : (int) $received_at;
			$received_at = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d H:i:s', $ts_s ) : date( 'Y-m-d H:i:s', $ts_s );
		}

		return array(
			'inbox_ref'          => $oa_id,
			'inbox_name'         => 'Zalo OA ' . ( $raw['account_name'] ?? $oa_id ),
			'source_id'          => $uid,
			'contact_name'       => $contact_name,
			'contact_avatar'     => null,
			'content'            => $text,
			'content_type'       => $content_type,
			'attachments'        => $attachments,
			'external_source_id' => $ext,
			'received_at'        => $received_at,
		);
	}

	public function send( array $conversation, array $message ): array {
		$inbox = BizCity_CRM_Repository::get_inbox( (int) ( $conversation['inbox_id'] ?? 0 ) );
		if ( ! $inbox ) {
			return array( 'success' => false, 'external_source_id' => null, 'error' => 'inbox not found' );
		}
		$ref   = (string) $inbox['channel_ref_id'];
		$uid   = $this->resolve_uid_from_conversation( $conversation );
		if ( $uid === '' ) {
			return array( 'success' => false, 'external_source_id' => null, 'error' => 'cannot resolve Zalo user_id' );
		}
		$text         = (string) ( $message['content'] ?? '' );
		$content_type = (string) ( $message['content_type'] ?? 'text' );
		$attachments  = is_array( $message['attachments'] ?? null ) ? $message['attachments'] : array();
		$first_att    = $attachments[0] ?? null;
		$att_url      = is_array( $first_att ) ? (string) ( $first_att['data_url'] ?? '' ) : '';

		// Path 1: numeric bot_id ref — prefer the unified Channel Gateway sender
		// (it knows about bizcity-zalo-bot's send pipeline + ledger).
		if ( ctype_digit( $ref ) && class_exists( 'BizCity_Gateway_Sender' ) ) {
			$chat_id = 'zalobot_' . $ref . '_' . $uid;
			$payload = $content_type === 'image' && $att_url !== '' ? $att_url : $text;
			$res     = BizCity_Gateway_Sender::instance()->send( $chat_id, $payload, $content_type === 'image' ? 'image' : 'text' );
			$ok      = is_array( $res ) ? ! empty( $res['sent'] ) : false;
			return array(
				'success'            => $ok,
				'external_source_id' => is_array( $res ) ? (string) ( $res['extra']['message_id'] ?? ( $res['extra']['mid'] ?? '' ) ) : '',
				'error'              => $ok ? null : ( is_array( $res ) ? (string) ( $res['error'] ?? 'gateway_send_failed' ) : 'gateway_send_failed' ),
			);
		}

		// Path 2: Bridge handles both OA-id and bot-id refs and applies the
		// correct (1-arg) constructor + send_text_message / send_image_message.
		$token = BizCity_CRM_Bridge_Zalo::lookup_access_token( $ref );
		if ( $token === '' ) {
			return array( 'success' => false, 'external_source_id' => null, 'error' => 'no access token for Zalo ref ' . $ref );
		}

		if ( $content_type === 'image' && $att_url !== '' ) {
			return BizCity_CRM_Bridge_Zalo::send_image( $token, $uid, $att_url );
		}
		return BizCity_CRM_Bridge_Zalo::send_text( $token, $uid, $text );
	}

	public function mark_seen( array $conversation, string $external_source_id ): void {}
	public function set_typing( array $conversation, bool $on ): void {}

	public function setup_form_schema(): array {
		return array(
			'fields'   => array(
				array(
					'name'     => 'oa_id',
					'label'    => __( 'Zalo OA ID hoặc Bot ID', 'bizcity-twin-crm' ),
					'type'     => 'text',
					'required' => true,
					'help'     => __( 'OA ID lấy từ Zalo Official Account Manager. Bot ID lấy từ plugin bizcity-zalo-bot.', 'bizcity-twin-crm' ),
				),
				array(
					'name'     => 'oa_name',
					'label'    => __( 'Tên OA hiển thị', 'bizcity-twin-crm' ),
					'type'     => 'text',
					'required' => false,
				),
			),
			'webhook'  => array(
				'method' => 'POST',
				// bizcity-zalo-bot uses a top-level rewrite rule, NOT a REST namespace.
				'url'    => BizCity_CRM_Bridge_Zalo::webhook_url( '{bot_id}' ),
				'note'   => __( 'Webhook URL của bizcity-zalo-bot dùng rewrite rule /zalohook/{bot_id}. Thay {bot_id} bằng id bot thật.', 'bizcity-twin-crm' ),
			),
			'docs_url' => BizCity_CRM_Bridge_Zalo::admin_page_url(),
		);
	}

	public function verify( array $config ): array {
		$oa = trim( (string) ( $config['oa_id'] ?? '' ) );
		if ( $oa === '' ) {
			return array( 'ok' => false, 'error' => 'OA ID không được trống.' );
		}
		if ( ! BizCity_CRM_Bridge_Zalo::is_available() ) {
			return array(
				'ok'    => false,
				'error' => 'Plugin bizcity-zalo-bot chưa active. Kích hoạt trước khi tạo inbox.',
			);
		}
		// Resolve bot row — numeric ref => bot_id, otherwise treat as oa_id.
		$bot = ctype_digit( $oa ) ? BizCity_CRM_Bridge_Zalo::get_bot( (int) $oa ) : BizCity_CRM_Bridge_Zalo::lookup_bot_by_oa( $oa );
		if ( ! $bot ) {
			return array(
				'ok'    => false,
				'error' => sprintf( 'Không tìm thấy bot/OA "%s" trong bizcity-zalo-bot. Tạo bot trước khi tạo inbox.', $oa ),
			);
		}
		$name = trim( (string) ( $config['oa_name'] ?? '' ) );
		if ( $name === '' ) {
			$name = (string) ( $bot['bot_name'] ?? ( 'Zalo OA ' . $oa ) );
		}
		return array(
			'ok'             => true,
			'channel_ref_id' => $oa,
			'name'           => $name,
		);
	}

	/* ----- helpers ----- */

	private function resolve_uid_from_conversation( array $conversation ): string {
		global $wpdb;
		$ci_id = (int) ( $conversation['contact_inbox_id'] ?? 0 );
		if ( ! $ci_id ) { return ''; }
		$tbl = BizCity_CRM_DB_Installer::tbl_contact_inboxes();
		$src = $wpdb->get_var( $wpdb->prepare( "SELECT source_id FROM {$tbl} WHERE id = %d", $ci_id ) );
		return (string) ( $src ?: '' );
	}

	/**
	 * Look up the access token by ref (OA id or numeric bot id).
	 * Delegates to the bridge so signature drift in the bot plugin only breaks ONE file.
	 */
	private function lookup_oa_access_token( string $oa_id ): string {
		return BizCity_CRM_Bridge_Zalo::lookup_access_token( $oa_id );
	}
}
