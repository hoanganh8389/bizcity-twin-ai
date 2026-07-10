<?php
/**
 * BizCity CRM — Email IMAP adapter (skeleton).
 *
 * Inbound: WP-Cron `bizcity_crm_email_poll` (registered in M7.W3.task-2)
 * polls IMAP every 5 minutes; threading by Message-ID + In-Reply-To.
 * Outbound: wp_mail() / SMTP (host plugin) — outbound bridge in W3.task-2.
 *
 * @package BizCity_Twin_CRM
 * @since   PHASE 0.35 (M7.W3)
 */

defined( 'ABSPATH' ) || exit;

class BizCity_CRM_Adapter_Email_IMAP extends BizCity_CRM_Adapter_Base {

	public function code(): string  { return 'email_imap'; }
	public function label(): string { return 'Email (IMAP/SMTP)'; }

	public function capabilities(): array {
		return array( 'text', 'file' );
	}

	public function normalize_inbound( array $raw ): ?array {
		// Expected $raw shape produced by the IMAP poller (W3.task-2):
		//   { mailbox, from_email, from_name, subject, body_text, body_html,
		//     message_id, in_reply_to, attachments[], received_at }
		$mailbox = (string) ( $raw['mailbox'] ?? '' );
		$from    = (string) ( $raw['from_email'] ?? '' );
		$mid     = (string) ( $raw['message_id'] ?? '' );
		if ( $mailbox === '' || $from === '' || $mid === '' ) { return null; }

		return array(
			'inbox_ref'          => $mailbox,
			'inbox_name'         => 'Email ' . $mailbox,
			'source_id'          => strtolower( $from ),
			'contact_name'       => (string) ( $raw['from_name'] ?? $from ),
			'contact_avatar'     => null,
			'content'            => (string) ( $raw['body_text'] ?? wp_strip_all_tags( $raw['body_html'] ?? '' ) ),
			'content_type'       => 'text',
			'attachments'        => is_array( $raw['attachments'] ?? null ) ? $raw['attachments'] : array(),
			'external_source_id' => 'email:' . $mid,
			'received_at'        => (string) ( $raw['received_at'] ?? current_time( 'mysql' ) ),
		);
	}

	public function send( array $conversation, array $message ): array {
		$inbox = BizCity_CRM_Repository::get_inbox( (int) ( $conversation['inbox_id'] ?? 0 ) );
		if ( ! $inbox ) {
			return array( 'success' => false, 'external_source_id' => null, 'error' => 'inbox not found' );
		}
		$settings = $inbox['settings_json'] ? json_decode( $inbox['settings_json'], true ) : array();
		if ( ! is_array( $settings ) ) { $settings = array(); }

		$to = $this->resolve_email_from_conversation( $conversation );
		if ( $to === '' ) {
			return array( 'success' => false, 'external_source_id' => null, 'error' => 'cannot resolve recipient email' );
		}
		$subject = (string) ( $message['subject'] ?? ( '[Re] ' . ( $settings['default_subject'] ?? 'Conversation' ) ) );
		$body    = (string) ( $message['content'] ?? '' );

		// Provider routing: gmail_oauth uses bizgpt-tool-google; default falls back to wp_mail.
		$provider = (string) ( $settings['provider'] ?? 'generic_imap' );
		if ( $provider === 'gmail_oauth' && BizCity_CRM_Bridge_Google::is_available() ) {
			$google_user_id = (int) ( $settings['google_account_user_id'] ?? get_current_user_id() );
			$google_email   = (string) ( $settings['google_email'] ?? ( $settings['mailbox_address'] ?? '' ) );
			return BizCity_CRM_Bridge_Google::gmail_send( $google_user_id, $google_email, array(
				'to'      => $to,
				'subject' => $subject,
				'body'    => $body,
			) );
		}

		$headers = array(
			'From: ' . ( $settings['from_name'] ?? get_bloginfo( 'name' ) ) . ' <' . ( $settings['from_email'] ?? get_option( 'admin_email' ) ) . '>',
		);
		$ok = wp_mail( $to, $subject, $body, $headers );
		return array(
			'success'            => (bool) $ok,
			'external_source_id' => 'email:out:' . wp_generate_uuid4(),
			'error'              => $ok ? null : 'wp_mail returned false',
		);
	}

	public function setup_form_schema(): array {
		// Build provider options dynamically — gmail_oauth only offered when the
		// Google plugin is loaded so the wizard never lets users pick a dead path.
		$provider_options = array(
			'generic_imap' => __( 'Generic IMAP / SMTP', 'bizcity-twin-crm' ),
		);
		$gmail_accounts = array();
		if ( BizCity_CRM_Bridge_Google::is_available() ) {
			$provider_options['gmail_oauth'] = __( 'Gmail (OAuth qua bizgpt-tool-google)', 'bizcity-twin-crm' );
			foreach ( BizCity_CRM_Bridge_Google::list_oauth_accounts() as $acc ) {
				if ( ( $acc['status'] ?? '' ) !== 'active' ) { continue; }
				$gmail_accounts[ (string) $acc['google_email'] ] = (string) $acc['google_email'];
			}
		}

		$fields = array(
			array(
				'name'     => 'provider',
				'label'    => __( 'Provider', 'bizcity-twin-crm' ),
				'type'     => 'select',
				'required' => true,
				'options'  => $provider_options,
				'default'  => 'generic_imap',
				'help'     => BizCity_CRM_Bridge_Google::is_available()
					? __( 'Chọn Gmail OAuth nếu đã kết nối Google qua bizgpt-tool-google.', 'bizcity-twin-crm' )
					: __( 'Cài và kích hoạt plugin bizgpt-tool-google để dùng Gmail OAuth.', 'bizcity-twin-crm' ),
			),
			array(
				'name'        => 'mailbox_address',
				'label'       => __( 'Mailbox address', 'bizcity-twin-crm' ),
				'type'        => 'text',
				'required'    => true,
				'placeholder' => 'support@example.com',
			),
		);

		if ( $gmail_accounts ) {
			$fields[] = array(
				'name'        => 'google_email',
				'label'       => __( 'Google account (chi gmail_oauth)', 'bizcity-twin-crm' ),
				'type'        => 'select',
				'required'    => false,
				'options'     => $gmail_accounts,
				'visible_if'  => array( 'provider' => 'gmail_oauth' ),
				'help'        => __( 'Chọn 1 tài khoản Google đã connect.', 'bizcity-twin-crm' ),
			);
		} elseif ( BizCity_CRM_Bridge_Google::is_available() ) {
			$fields[] = array(
				'name'       => 'google_email',
				'label'      => __( 'Google account', 'bizcity-twin-crm' ),
				'type'       => 'note',
				'visible_if' => array( 'provider' => 'gmail_oauth' ),
				'help'       => sprintf(
					/* translators: %s = connect URL */
					__( 'Chưa có tài khoản Google nào connect. Mở %s để connect.', 'bizcity-twin-crm' ),
					BizCity_CRM_Bridge_Google::connect_url()
				),
			);
		}

		// IMAP fields — conditionally rendered when provider=generic_imap.
		$imap_fields = array(
			array(
				'name'     => 'imap_host',
				'label'    => __( 'IMAP host', 'bizcity-twin-crm' ),
				'type'     => 'text',
				'required' => false,
				'placeholder' => 'imap.example.com',
				'visible_if'  => array( 'provider' => 'generic_imap' ),
			),
			array(
				'name'     => 'imap_port',
				'label'    => __( 'IMAP port', 'bizcity-twin-crm' ),
				'type'     => 'text',
				'required' => false,
				'placeholder' => '993',
				'visible_if'  => array( 'provider' => 'generic_imap' ),
			),
			array(
				'name'     => 'imap_username',
				'label'    => __( 'IMAP username', 'bizcity-twin-crm' ),
				'type'     => 'text',
				'required' => false,
				'visible_if' => array( 'provider' => 'generic_imap' ),
			),
			array(
				'name'     => 'imap_password',
				'label'    => __( 'IMAP password / app password', 'bizcity-twin-crm' ),
				'type'     => 'password',
				'required' => false,
				'visible_if' => array( 'provider' => 'generic_imap' ),
			),
			array(
				'name'     => 'imap_encryption',
				'label'    => __( 'Encryption', 'bizcity-twin-crm' ),
				'type'     => 'select',
				'required' => false,
				'options'  => array( 'ssl' => 'SSL', 'tls' => 'TLS', 'none' => 'None' ),
				'visible_if' => array( 'provider' => 'generic_imap' ),
			),
		);
		$fields = array_merge( $fields, $imap_fields );

		$fields[] = array(
			'name'     => 'from_name',
			'label'    => __( 'From name (outgoing)', 'bizcity-twin-crm' ),
			'type'     => 'text',
			'required' => false,
		);

		return array(
			'fields'   => $fields,
			'webhook'  => null, // pull-mode (cron poll), no inbound URL
			'docs_url' => BizCity_CRM_Bridge_Google::is_available() ? BizCity_CRM_Bridge_Google::admin_page_url() : '',
		);
	}

	public function verify( array $config ): array {
		$mailbox = trim( (string) ( $config['mailbox_address'] ?? '' ) );
		if ( $mailbox === '' || ! is_email( $mailbox ) ) {
			return array( 'ok' => false, 'error' => 'mailbox_address phải là email hợp lệ.' );
		}

		$provider = (string) ( $config['provider'] ?? 'generic_imap' );

		if ( $provider === 'gmail_oauth' ) {
			if ( ! BizCity_CRM_Bridge_Google::is_available() ) {
				return array(
					'ok'    => false,
					'error' => 'Plugin bizgpt-tool-google chưa active.',
				);
			}
			$user_id      = get_current_user_id();
			$google_email = trim( (string) ( $config['google_email'] ?? '' ) );
			$probe = BizCity_CRM_Bridge_Google::test_token( $user_id, $google_email );
			if ( empty( $probe['ok'] ) ) {
				return array(
					'ok'    => false,
					'error' => sprintf( 'Gmail token không sẵn sàng: %s', (string) ( $probe['error'] ?? 'unknown' ) ),
					'hints' => array( sprintf( 'Re-authorize tại %s', BizCity_CRM_Bridge_Google::connect_url() ) ),
				);
			}
			return array(
				'ok'             => true,
				'channel_ref_id' => strtolower( $mailbox ),
				'name'           => 'Gmail ' . $mailbox,
				'hints'          => array( __( 'Token Gmail OK — cron poll sẽ chạy trong 5 phút đầu.', 'bizcity-twin-crm' ) ),
			);
		}

		if ( ! function_exists( 'imap_open' ) ) {
			return array(
				'ok'    => false,
				'error' => 'PHP IMAP extension chưa enable trên server. Cài php-imap rồi thử lại.',
				'hints' => array( 'Ubuntu: sudo apt install php-imap && sudo service php8.x-fpm restart' ),
			);
		}
		// Sanity-only verify — don't actually open IMAP here (slow, blocks wizard).
		return array(
			'ok'             => true,
			'channel_ref_id' => strtolower( $mailbox ),
			'name'           => 'Email ' . $mailbox,
			'hints'          => array( __( 'Connection sẽ test trong cron run đầu tiên (5 phút sau).', 'bizcity-twin-crm' ) ),
		);
	}

	private function resolve_email_from_conversation( array $conversation ): string {
		global $wpdb;
		$ci_id = (int) ( $conversation['contact_inbox_id'] ?? 0 );
		if ( ! $ci_id ) { return ''; }
		$tbl = $wpdb->prefix . 'bizcity_crm_contact_inboxes';
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT source_id FROM {$tbl} WHERE id = %d", $ci_id ) );
	}
}
