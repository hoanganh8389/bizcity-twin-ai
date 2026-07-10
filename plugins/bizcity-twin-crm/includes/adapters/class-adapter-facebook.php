<?php
/**
 * BizCity CRM — Facebook Channel Adapter.
 *
 * Wraps the existing `bizcity-facebook-bot` plugin (no fork).
 * - normalize_inbound() consumes the trigger payload of:
 *     do_action( 'waic_twf_process_flow', 'bizcity_facebook_message_received', $trigger_data )
 *   shape: { bot_id, user_id (PSID), message, timestamp (ms), page_id, event, platform: 'FB_MESS' }
 * - send() reuses BizCity_Facebook_Bot_API::send_message().
 *
 * @package BizCity_Twin_CRM
 */

defined( 'ABSPATH' ) || exit;

class BizCity_CRM_Adapter_Facebook extends BizCity_CRM_Adapter_Base {

	public function code(): string { return 'facebook'; }
	public function label(): string { return 'Facebook Messenger'; }

	public function capabilities(): array {
		return array( 'text', 'image', 'file', 'mark_seen' );
	}

	/**
	 * Accepts trigger_data from the FB bot webhook handler.
	 */
	public function normalize_inbound( array $raw ): ?array {
		$page_id = (string) ( $raw['page_id'] ?? '' );
		$psid    = (string) ( $raw['user_id'] ?? '' );
		$text    = (string) ( $raw['message'] ?? '' );
		if ( $page_id === '' || $psid === '' ) {
			return null;
		}

		// Branch: comment vs DM (presence of comment_id signals a feed comment event).
		$is_comment = ! empty( $raw['comment_id'] );

		if ( $is_comment ) {
			$cmt_name = (string) ( $raw['user_name'] ?? '' );
			if ( $cmt_name === '' ) { $cmt_name = 'FB ' . substr( $psid, -6 ); }
			return array(
				'inbox_ref'          => 'fb_feed_' . $page_id,           // SEPARATE inbox vs DM page
				'inbox_name'         => 'FB Comments ' . $page_id,
				'source_id'          => $psid,
				'contact_name'       => $cmt_name,
				'contact_avatar'     => null,
				'content'            => $text,
				'content_type'       => 'text',
				'attachments'        => array(),
				'external_source_id' => 'fbcmt:' . (string) $raw['comment_id'],
				'received_at'        => current_time( 'mysql' ),
				'additional_attributes' => array(
					'fb_kind'     => 'comment',
					'comment_id'  => (string) $raw['comment_id'],
					'post_id'     => (string) ( $raw['post_id']   ?? '' ),
					'post_type'   => (string) ( $raw['post_type'] ?? 'feed' ),
					'ai_reply'    => (string) ( $raw['ai_reply']  ?? '' ),
				),
			);
		}

		// Build deterministic external_source_id — FB Messenger "mid" lives inside event.message.mid;
		// fall back to "{page_id}:{psid}:{ts_ms}" so dedupe still works on retries within the same ms.
		$mid = '';
		if ( ! empty( $raw['event']['message']['mid'] ) ) {
			$mid = (string) $raw['event']['message']['mid'];
		}
		$ts_ms = isset( $raw['timestamp'] ) ? (int) $raw['timestamp'] : (int) round( microtime( true ) * 1000 );
		$ts_s  = (int) ( $ts_ms / 1000 );
		$external_source_id = $mid !== '' ? $mid : sprintf( 'fbsim:%s:%s:%d', $page_id, $psid, $ts_ms );

		// Resolve contact name + avatar via FB bridge (no direct bot-plugin coupling).
		$contact_name   = '';
		$contact_avatar = null;
		$cust = BizCity_CRM_Bridge_FB::get_customer( $psid, $page_id );
		if ( $cust ) {
			$contact_name   = (string) ( $cust['name'] ?? '' );
			$contact_avatar = $cust['profile_pic'] ?? null;
		}

		// Graph API fallback — fetch profile directly if bot DB has no name.
		if ( $contact_name === '' || empty( $contact_avatar ) ) {
			$fetched = $this->fetch_profile_from_graph( $page_id, $psid );
			if ( $fetched ) {
				if ( $contact_name === '' && ! empty( $fetched['name'] ) ) {
					$contact_name = (string) $fetched['name'];
				}
				if ( empty( $contact_avatar ) && ! empty( $fetched['profile_pic'] ) ) {
					$contact_avatar = (string) $fetched['profile_pic'];
				}
				// Cache back into bot customer table via bridge.
				if ( $contact_name !== '' ) {
					BizCity_CRM_Bridge_FB::save_customer( array(
						'client_id'   => $psid,
						'page_id'     => $page_id,
						'name'        => $contact_name,
						'profile_pic' => $contact_avatar,
					) );
				}
			}
		}

		if ( $contact_name === '' ) {
			$contact_name = 'FB ' . substr( $psid, -6 );
		}

		// Detect attachments from the full event payload (image messages).
		$attachments = array();
		$event_atts  = $raw['event']['message']['attachments'] ?? array();
		if ( is_array( $event_atts ) ) {
			foreach ( $event_atts as $a ) {
				$type = (string) ( $a['type'] ?? 'file' );
				$url  = (string) ( $a['payload']['url'] ?? '' );
				if ( $url === '' ) { continue; }
				$attachments[] = array(
					'file_type' => in_array( $type, array( 'image', 'audio', 'video', 'file' ), true ) ? $type : 'file',
					'data_url'  => $url,
					'meta'      => array( 'fb_type' => $type ),
				);
			}
		}

		return array(
			'inbox_ref'          => $page_id,
			'inbox_name'         => 'FB Page ' . $page_id,
			'source_id'          => $psid,
			'contact_name'       => $contact_name,
			'contact_avatar'     => $contact_avatar,
			'content'            => $text,
			'content_type'       => $attachments ? 'image' : 'text',
			'attachments'        => $attachments,
			'external_source_id' => $external_source_id,
			// Store in WP site-local timezone to match current_time('mysql') used elsewhere.
			'received_at'        => function_exists( 'wp_date' ) ? wp_date( 'Y-m-d H:i:s', $ts_s ) : date( 'Y-m-d H:i:s', $ts_s ),
		);
	}

	/**
	 * Send outbound text/image/file via Facebook Graph API (through bridge).
	 */
	public function send( array $conversation, array $message ): array {
		if ( ! BizCity_CRM_Bridge_FB::is_available() ) {
			return array( 'success' => false, 'external_source_id' => null, 'error' => 'bizcity-facebook-bot plugin not active' );
		}

		$inbox = BizCity_CRM_Repository::get_inbox( (int) ( $conversation['inbox_id'] ?? 0 ) );
		if ( ! $inbox ) {
			return array( 'success' => false, 'external_source_id' => null, 'error' => 'inbox not found' );
		}
		$ref = (string) $inbox['channel_ref_id'];
		// Comment inbox uses ref "fb_feed_{page_id}"; strip prefix to recover page_id.
		$is_comment_inbox = ( strpos( $ref, 'fb_feed_' ) === 0 );
		$page_id = $is_comment_inbox ? substr( $ref, 8 ) : $ref;

		$psid = $this->resolve_psid_from_conversation( $conversation );
		if ( $psid === '' ) {
			return array( 'success' => false, 'external_source_id' => null, 'error' => 'cannot resolve PSID' );
		}

		$token = BizCity_CRM_Bridge_FB::lookup_page_access_token( $page_id );
		if ( $token === '' ) {
			return array( 'success' => false, 'external_source_id' => null, 'error' => 'no page access token for ' . $page_id );
		}

		$text = (string) ( $message['content'] ?? '' );

		// Branch: reply to a comment via /{comment_id}/comments instead of DM.
		if ( $is_comment_inbox ) {
			$cmt_id = $this->resolve_comment_id_from_conversation( $conversation );
			if ( $cmt_id === '' ) {
				return array( 'success' => false, 'external_source_id' => null, 'error' => 'no comment_id on conversation; cannot reply via /comments endpoint' );
			}
			$resp = wp_remote_post(
				'https://graph.facebook.com/v18.0/' . rawurlencode( $cmt_id ) . '/comments',
				array(
					'timeout' => 15,
					'body'    => array(
						'message'      => $text,
						'access_token' => $token,
					),
				)
			);
			if ( is_wp_error( $resp ) ) {
				return array( 'success' => false, 'external_source_id' => null, 'error' => $resp->get_error_message() );
			}
			$body = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
			if ( ! is_array( $body ) || ! empty( $body['error'] ) ) {
				return array( 'success' => false, 'external_source_id' => null, 'error' => isset( $body['error']['message'] ) ? (string) $body['error']['message'] : 'graph_comment_reply_failed' );
			}
			return array( 'success' => true, 'external_source_id' => 'fbcmt_reply:' . (string) ( $body['id'] ?? $cmt_id ), 'error' => null );
		}

		// Route by content_type so image/file attachments don't fall back to text.
		$content_type = (string) ( $message['content_type'] ?? 'text' );
		$attachments  = is_array( $message['attachments'] ?? null ) ? $message['attachments'] : array();
		$first_att    = $attachments[0] ?? null;
		$att_url      = is_array( $first_att ) ? (string) ( $first_att['data_url'] ?? '' ) : '';

		if ( $content_type === 'image' && $att_url !== '' ) {
			return BizCity_CRM_Bridge_FB::send_image( $page_id, $token, $psid, $att_url, $text );
		}
		if ( $content_type === 'file' && $att_url !== '' ) {
			return BizCity_CRM_Bridge_FB::send_file( $page_id, $token, $psid, $att_url );
		}

		return BizCity_CRM_Bridge_FB::send_text( $page_id, $token, $psid, $text );
	}

	public function mark_seen( array $conversation, string $external_source_id ): void {
		// FB Graph API supports sender_actions=mark_seen; omitted in M1 to avoid extra API calls.
	}

	public function set_typing( array $conversation, bool $on ): void {
		// FB Graph API: sender_actions=typing_on/typing_off; omitted in M1.
	}

	/* ----- M7.W1 wizard self-description ----- */

	public function setup_form_schema(): array {
		return array(
			'fields'   => array(
				array(
					'name'        => 'page_id',
					'label'       => __( 'Facebook Page ID', 'bizcity-twin-crm' ),
					'type'        => 'text',
					'required'    => true,
					'placeholder' => 'e.g. 1234567890',
					'help'        => __( 'Numeric Page ID — find under Page → About → Page ID.', 'bizcity-twin-crm' ),
				),
				array(
					'name'        => 'page_name',
					'label'       => __( 'Page name (label)', 'bizcity-twin-crm' ),
					'type'        => 'text',
					'required'    => false,
					'placeholder' => __( 'Optional friendly name', 'bizcity-twin-crm' ),
				),
			),
			'webhook'  => array(
				'method' => 'POST',
				'url'    => BizCity_CRM_Bridge_FB::webhook_url(),
				'note'   => __( 'Connect a Page via the existing BizCity Facebook Bot plugin first; this wizard only registers the inbox row.', 'bizcity-twin-crm' ),
			),
			'docs_url' => BizCity_CRM_Bridge_FB::admin_page_url(),
		);
	}

	public function verify( array $config ): array {
		$page_id = trim( (string) ( $config['page_id'] ?? '' ) );
		if ( $page_id === '' || ! ctype_digit( $page_id ) ) {
			return array( 'ok' => false, 'error' => 'Page ID phải là chuỗi số.' );
		}
		if ( ! BizCity_CRM_Bridge_FB::is_available() ) {
			return array(
				'ok'    => false,
				'error' => 'Plugin bizcity-facebook-bot chưa active. Kích hoạt trước khi tạo inbox.',
				'hints' => array( __( 'Việc giắi hảo chỉ vào khi bot plugin loaded.', 'bizcity-twin-crm' ) ),
			);
		}
		$bot = BizCity_CRM_Bridge_FB::get_bot_by_page_id( $page_id );
		if ( ! $bot ) {
			return array(
				'ok'    => false,
				'error' => sprintf( 'Page %s chưa được kết nối ở bizcity-facebook-bot. Kết nối Page trước khi tạo inbox.', $page_id ),
				'hints' => array( __( 'Mở trang quản lý FB Bots để kết nối Page.', 'bizcity-twin-crm' ) ),
			);
		}
		$name = trim( (string) ( $config['page_name'] ?? '' ) );
		if ( $name === '' ) {
			$name = (string) ( $bot['page_name'] ?? ( 'Facebook Page ' . $page_id ) );
		}
		return array(
			'ok'              => true,
			'channel_ref_id'  => $page_id,
			'name'            => $name,
			'hints'           => array(),
		);
	}

	/* ----- helpers ----- */
	private function resolve_psid_from_conversation( array $conversation ): string {
		global $wpdb;
		$ci_id = (int) ( $conversation['contact_inbox_id'] ?? 0 );
		if ( ! $ci_id ) {
			return '';
		}
		$tbl = BizCity_CRM_DB_Installer::tbl_contact_inboxes();
		$src = $wpdb->get_var( $wpdb->prepare( "SELECT source_id FROM {$tbl} WHERE id = %d", $ci_id ) );
		return (string) ( $src ?: '' );
	}

	private function lookup_page_access_token( string $page_id ): string {
		return BizCity_CRM_Bridge_FB::lookup_page_access_token( $page_id );
	}

	/**
	 * Look up the comment_id this conversation belongs to (for /comments reply branch).
	 * Strategy: most-recent incoming message in the conversation has external_source_id `fbcmt:{id}`.
	 */
	private function resolve_comment_id_from_conversation( array $conversation ): string {
		global $wpdb;
		$conv_id = (int) ( $conversation['id'] ?? 0 );
		if ( $conv_id <= 0 ) { return ''; }
		$tbl = BizCity_CRM_DB_Installer::tbl_messages();
		$ext = (string) $wpdb->get_var( $wpdb->prepare(
			"SELECT external_source_id FROM {$tbl}
			 WHERE conversation_id = %d
			   AND message_type = 'incoming'
			   AND external_source_id LIKE 'fbcmt:%%'
			 ORDER BY id DESC LIMIT 1",
			$conv_id
		) );
		if ( $ext === '' ) { return ''; }
		return (string) substr( $ext, 6 ); // strip 'fbcmt:' prefix
	}

	/**
	 * Fetch Facebook profile (first/last name + avatar) directly from Graph for a PSID.
	 * Used as fallback when the bot's customer table hasn't enriched the row yet.
	 */
	private function fetch_profile_from_graph( string $page_id, string $psid ): ?array {
		$token = $this->lookup_page_access_token( $page_id );
		if ( $token === '' ) { return null; }
		$resp = wp_remote_get(
			'https://graph.facebook.com/v18.0/' . rawurlencode( $psid )
				. '?fields=first_name,last_name,profile_pic&access_token=' . rawurlencode( $token ),
			array( 'timeout' => 8 )
		);
		if ( is_wp_error( $resp ) ) { return null; }
		if ( (int) wp_remote_retrieve_response_code( $resp ) !== 200 ) { return null; }
		$body = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		if ( ! is_array( $body ) || ! empty( $body['error'] ) ) { return null; }
		$name = trim( ( $body['first_name'] ?? '' ) . ' ' . ( $body['last_name'] ?? '' ) );
		return array(
			'name'        => $name,
			'profile_pic' => (string) ( $body['profile_pic'] ?? '' ),
		);
	}
}
