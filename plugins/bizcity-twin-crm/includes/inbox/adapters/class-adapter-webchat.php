<?php
/**
 * BizCity CRM — WebChat (local widget) channel adapter.
 *
 * Bridges the LOCAL `modules/webchat/` float widget into the unified CRM Inbox:
 *
 *   Inbound  (visitor → CRM):
 *     widget POST → BizCity_WebChat_Trigger → do_action('bizcity_webchat_message_received', $twf, $raw)
 *       → BizCity_CRM_WebChat_Ingestor::on_inbound() (bootstrap.php)
 *       → adapter->normalize_inbound() → Repository::insert_message(incoming)
 *
 *   Outbound (CRM agent → visitor):
 *     CRM REST POST /conversations/{id}/messages
 *       → adapter->send() → BizCity_Gateway_Sender::send('webchat_<sid>')
 *       → BizCity_WebChat_Adapter::send_outbound() (channel-gateway, Phase 0.36 W3)
 *       → wp_bizcity_webchat_messages row(from='bot')
 *       → widget polling picks it up ≤ 4s
 *
 * Topology (single-site, no wizard):
 *   inbox_ref  = blog_id (1 inbox per site)
 *   source_id  = webchat session_id (1 contact per visitor session)
 *   chat_id    = 'webchat_' . session_id (gateway routes by `webchat_` prefix)
 *
 * @package BizCity_Twin_CRM
 * @since   PHASE 0.37
 */

defined( 'ABSPATH' ) || exit;

class BizCity_CRM_Adapter_WebChat extends BizCity_CRM_Adapter_Base {

	public function code(): string  { return 'webchat'; }
	public function label(): string { return 'Web Chat (local widget)'; }

	public function capabilities(): array {
		return array( 'text', 'image', 'file' );
	}

	/**
	 * Normalize an inbound payload into the generic CRM inbound shape.
	 *
	 * Accepts THREE shapes:
	 *   1. `bizcity_channel_normalized` envelope (preferred, unified path)
	 *      → { platform=WEBCHAT, account_id=site_id, user_id=session_id,
	 *          chat_id=webchat_<sid>, message_id, message, raw=<wu_payload> }
	 *   2. `bizcity_webchat_message_received` twf trigger
	 *      → { session_id, chat_id, client_id, user_id, client_name, text,
	 *          message_id, attachment_type, image_url, audio_url }
	 *   3. Flat shape `{session_id, message, ...}` (push hook fallback).
	 */
	public function normalize_inbound( array $raw ): ?array {
		// Detect shape 1 (normalized envelope) and unwrap to twf-like fields.
		if ( ! empty( $raw['platform'] ) && strtoupper( (string) $raw['platform'] ) === 'WEBCHAT' ) {
			$inner = is_array( $raw['raw'] ?? null ) ? $raw['raw'] : array();
			$raw = array_merge( array(
				'session_id'  => (string) ( $raw['user_id'] ?? '' ),
				'message_id'  => (string) ( $raw['message_id'] ?? '' ),
				'text'        => (string) ( $raw['message'] ?? '' ),
				'client_name' => (string) ( $inner['client_name'] ?? '' ),
				'user_id'     => (int) ( $inner['user_id'] ?? 0 ),
				'image_url'   => (string) ( $inner['image_url'] ?? '' ),
				'blog_id'     => (int) ( $raw['account_id'] ?? 0 ),
			), $raw );
		}

		$session_id = (string) ( $raw['session_id'] ?? $raw['chat_id'] ?? $raw['client_id'] ?? '' );
		$text       = (string) ( $raw['text'] ?? $raw['message'] ?? $raw['message_text'] ?? '' );
		if ( $session_id === '' ) { return null; }

		$blog_id   = (int) ( $raw['blog_id'] ?? get_current_blog_id() );
		$blog_name = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : '';

		$attachments = array();
		$image_url   = (string) ( $raw['image_url'] ?? '' );
		if ( $image_url !== '' ) {
			$attachments[] = array( 'type' => 'image', 'data_url' => $image_url );
		}
		$audio_url   = (string) ( $raw['audio_url'] ?? '' );
		if ( $audio_url !== '' ) {
			$attachments[] = array( 'type' => 'audio', 'data_url' => $audio_url );
		}

		$content_type = 'text';
		if ( $text === '' && $image_url !== '' ) { $content_type = 'image'; }
		elseif ( $text === '' && $audio_url !== '' ) { $content_type = 'audio'; }

		$client_name = trim( (string) ( $raw['client_name'] ?? '' ) );
		if ( $client_name === '' || strcasecmp( $client_name, 'Guest' ) === 0 ) {
			$client_name = 'Visitor ' . substr( $session_id, -6 );
		}

		$ext_id = (string) ( $raw['message_id'] ?? '' );
		if ( $ext_id === '' ) {
			$ext_id = 'wc:' . substr( md5( $session_id . '|' . $text . '|' . microtime( true ) ), 0, 16 );
		}

		return array(
			'inbox_ref'          => (string) $blog_id,
			'inbox_name'         => $blog_name !== '' ? ( 'Web Chat — ' . $blog_name ) : ( 'Web Chat (site ' . $blog_id . ')' ),
			'source_id'          => $session_id,
			'contact_name'       => $client_name,
			'contact_avatar'     => null,
			'content'            => $text !== '' ? $text : ( $image_url !== '' ? '[image]' : ( $audio_url !== '' ? '[audio]' : '' ) ),
			'content_type'       => $content_type,
			'attachments'        => $attachments,
			'external_source_id' => $ext_id,
			'received_at'        => current_time( 'mysql' ),
		);
	}

	/**
	 * Dispatch an outbound CRM message back to the visitor widget by routing
	 * through Channel Gateway → BizCity_WebChat_Adapter::send_outbound().
	 *
	 * The Channel Gateway hook `bizcity_channel_outbound_logged` fires after
	 * the gateway writes its ledger row, so the existing CRM listener
	 * (`BizCity_CRM_Facebook_Ingestor::on_gateway_outbound`) does NOT need to
	 * mirror this back — the REST controller already inserted the outgoing
	 * `crm_messages` row before calling us.
	 */
	public function send( array $conversation, array $message ): array {
		$session_id = $this->resolve_session_from_conversation( $conversation );
		if ( $session_id === '' ) {
			return array( 'success' => false, 'external_source_id' => null, 'error' => 'cannot resolve webchat session_id' );
		}

		$text         = (string) ( $message['content'] ?? '' );
		$content_type = (string) ( $message['content_type'] ?? 'text' );
		$attachments  = is_array( $message['attachments'] ?? null ) ? $message['attachments'] : array();

		if ( $text === '' && empty( $attachments ) ) {
			return array( 'success' => false, 'external_source_id' => null, 'error' => 'empty message' );
		}

		// Prefer the unified Channel Gateway sender (it triggers the WebChat
		// adapter's send_outbound() + emits the ledger hook).
		if ( class_exists( 'BizCity_Gateway_Sender' ) ) {
			$chat_id = 'webchat_' . $session_id;
			$type    = $content_type === 'image' ? 'image' : 'text';
			$payload = $text;
			if ( $type === 'image' && empty( $payload ) && ! empty( $attachments[0]['data_url'] ) ) {
				$payload = (string) $attachments[0]['data_url'];
			}
			$res = BizCity_Gateway_Sender::instance()->send( $chat_id, $payload, $type );
			$ok  = is_array( $res ) ? ! empty( $res['sent'] ) : false;
			return array(
				'success'            => $ok,
				'external_source_id' => is_array( $res ) ? (string) ( $res['extra']['message_id'] ?? ( $res['extra']['mid'] ?? '' ) ) : '',
				'error'              => $ok ? null : ( is_array( $res ) ? (string) ( $res['error'] ?? 'gateway_send_failed' ) : 'gateway_send_failed' ),
			);
		}

		// Fallback (Channel Gateway missing): write directly to the webchat DB
		// so the float widget polling still picks the row up.
		if ( class_exists( 'BizCity_WebChat_Database' ) ) {
			$db  = BizCity_WebChat_Database::instance();
			$mid = uniqid( 'crm_', true );
			$res = $db->log_message( array(
				'session_id'    => $session_id,
				'user_id'       => 0,
				'client_name'   => 'BizChat',
				'message_id'    => $mid,
				'message_text'  => $text,
				'message_from'  => 'bot',
				'message_type'  => $content_type,
				'attachments'   => $attachments,
				'platform_type' => 'WEBCHAT',
			) );
			$ok = ( $res !== false );
			do_action( 'bizcity_webchat_push_message', $session_id, $text, array(
				'type'        => $content_type,
				'message_id'  => $mid,
				'attachments' => $attachments,
			) );
			return array(
				'success'            => $ok,
				'external_source_id' => $mid,
				'error'              => $ok ? null : 'webchat_db_insert_failed',
			);
		}

		return array( 'success' => false, 'external_source_id' => null, 'error' => 'no transport available (Gateway + WebChat_Database both missing)' );
	}

	/**
	 * Zero-config: webchat has no wizard. The single per-site inbox is
	 * auto-created on first inbound via Repository::upsert_inbox(). Admins
	 * configure Guru binding via the Channel Gateway SPA (`/p/webchat`).
	 */
	public function setup_form_schema(): array {
		return array(
			'fields'   => array(),
			'webhook'  => array(
				'method' => 'LOCAL',
				'url'    => '',
				'note'   => 'WebChat là widget chạy trực tiếp trên site (modules/webchat). Không cần webhook hay wizard — inbox tự sinh lần đầu visitor nhắn. Gán Guru tại Channel Gateway → Web Chat → 🤖 Guru & Chế độ.',
			),
			'docs_url' => '',
		);
	}

	public function verify( array $config ): array {
		return array(
			'ok'             => true,
			'channel_ref_id' => (string) get_current_blog_id(),
			'name'           => 'Web Chat — ' . (string) get_bloginfo( 'name' ),
		);
	}

	/* ----- helpers ----- */

	private function resolve_session_from_conversation( array $conversation ): string {
		// Prefer source_id from contact_inbox (set on inbound ingest).
		global $wpdb;
		$ci_id = (int) ( $conversation['contact_inbox_id'] ?? 0 );
		if ( $ci_id > 0 && class_exists( 'BizCity_CRM_DB_Installer_V2' ) ) {
			$tbl = BizCity_CRM_DB_Installer_V2::tbl_contact_inboxes();
			$src = $wpdb->get_var( $wpdb->prepare( "SELECT source_id FROM {$tbl} WHERE id = %d", $ci_id ) );
			if ( $src ) { return (string) $src; }
		}
		return '';
	}
}
