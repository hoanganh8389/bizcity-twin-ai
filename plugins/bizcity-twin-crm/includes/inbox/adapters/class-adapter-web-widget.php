<?php
/**
 * BizCity CRM — Web Widget channel adapter (skeleton).
 *
 * @package BizCity_Twin_CRM
 * @since   PHASE 0.35 (M7.W3)
 */

defined( 'ABSPATH' ) || exit;

class BizCity_CRM_Adapter_Web_Widget extends BizCity_CRM_Adapter_Base {

	public function code(): string  { return 'web_widget'; }
	public function label(): string { return 'Web Chat Widget'; }

	public function capabilities(): array {
		return array( 'text', 'image', 'file', 'typing' );
	}

	public function normalize_inbound( array $raw ): ?array {
		$widget_key = (string) ( $raw['widget_key'] ?? '' );
		$visitor    = (string) ( $raw['visitor_id'] ?? '' );
		$text       = (string) ( $raw['content']    ?? '' );
		if ( $widget_key === '' || $visitor === '' ) { return null; }

		return array(
			'inbox_ref'          => $widget_key,
			'inbox_name'         => 'Web ' . $widget_key,
			'source_id'          => $visitor,
			'contact_name'       => (string) ( $raw['visitor_name'] ?? ( 'Guest ' . substr( $visitor, -6 ) ) ),
			'contact_avatar'     => null,
			'content'            => $text,
			'content_type'       => 'text',
			'attachments'        => is_array( $raw['attachments'] ?? null ) ? $raw['attachments'] : array(),
			'external_source_id' => 'web:' . wp_generate_uuid4(),
			'received_at'        => current_time( 'mysql' ),
		);
	}

	public function send( array $conversation, array $message ): array {
		// Outbound = stash in DB; widget polls via REST. Channel Gateway not needed.
		return array(
			'success'            => true,
			'external_source_id' => 'web:out:' . wp_generate_uuid4(),
			'error'              => null,
		);
	}

	public function setup_form_schema(): array {
		$widget_key = strtolower( wp_generate_password( 16, false ) );
		return array(
			'fields'   => array(
				array(
					'name'     => 'widget_name',
					'label'    => __( 'Tên widget (chỉ admin thấy)', 'bizcity-twin-crm' ),
					'type'     => 'text',
					'required' => true,
					'placeholder' => 'Website chính',
				),
				array(
					'name'     => 'allowed_origins',
					'label'    => __( 'Allowed origins (CORS)', 'bizcity-twin-crm' ),
					'type'     => 'textarea',
					'required' => false,
					'help'     => __( 'Một origin một dòng — vd https://shop.example.com.', 'bizcity-twin-crm' ),
				),
				array(
					'name'     => 'widget_key',
					'label'    => __( 'Widget key (auto-generated)', 'bizcity-twin-crm' ),
					'type'     => 'text',
					'required' => true,
					'help'     => __( 'Khoá public — nhúng vào script tag.', 'bizcity-twin-crm' ),
					'default'  => $widget_key,
				),
			),
			'webhook'  => array(
				'method' => 'POST',
				'url'    => rest_url( BIZCITY_CRM_REST_NS . '/webhooks/web-widget' ),
				'note'   => __( 'Snippet HTML sẽ generate ở bước cuối wizard sau khi widget_key được lưu.', 'bizcity-twin-crm' ),
			),
			'docs_url' => '',
		);
	}

	public function verify( array $config ): array {
		$key = trim( (string) ( $config['widget_key'] ?? '' ) );
		if ( $key === '' || strlen( $key ) < 12 ) {
			return array( 'ok' => false, 'error' => 'widget_key phải dài ≥ 12 ký tự.' );
		}
		$name = trim( (string) ( $config['widget_name'] ?? '' ) );
		return array(
			'ok'             => true,
			'channel_ref_id' => $key,
			'name'           => $name !== '' ? $name : ( 'Web widget ' . substr( $key, 0, 8 ) ),
		);
	}

	/**
	 * Generate the embed snippet for an inbox (used by admin Channels page).
	 */
	public static function snippet_for( array $inbox ): string {
		$key  = (string) ( $inbox['channel_ref_id'] ?? '' );
		$base = esc_url_raw( rest_url( BIZCITY_CRM_REST_NS . '/webhooks/web-widget' ) );
		return sprintf(
			"<!-- BizCity Web Widget -->\n<script>(function(){var s=document.createElement('script');s.async=1;s.src=%s;s.dataset.bizcityKey=%s;document.head.appendChild(s);})();</script>",
			wp_json_encode( esc_url_raw( BIZCITY_CRM_URL . '/assets/widget.js' ) ),
			wp_json_encode( $key )
		);
	}
}
