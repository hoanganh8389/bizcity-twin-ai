<?php
/**
 * [2026-06-07 Johnny Chu] PHASE-0.40 G7.2 — Action block: Notify Discord webhook.
 *
 * Sends a message to a Discord channel via an Incoming Webhook URL.
 * Follows R-GW-8 (standalone — NO bizcity-llm-router needed; direct HTTPS POST).
 * Follows R-CRON-META: note_event on failure.
 *
 * Schema fields:
 *   webhook_url (string, required) — Discord Incoming Webhook URL.
 *   content     (string, required) — Message text (supports {name}, {phone}, {userId} vars).
 *   username    (string, optional) — Display name override.
 *   avatar_url  (string, optional) — Avatar URL override.
 *
 * @package BizCity_Automation
 * @since   1.21.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BizCity_Automation_Action_Notify_Discord
 */
final class BizCity_Automation_Action_Notify_Discord extends BizCity_Automation_Block_Base {

	public function id(): string   { return 'action.notify_discord'; }
	public function kind(): string { return 'action'; }

	public function meta(): array {
		return array(
			'label'    => 'Thông báo Discord',
			'short'    => 'notify_discord',
			'category' => 'notification',
			'color'    => '#5865F2',
			'icon'     => 'discord',
			'defaults' => array(
				'label'       => 'Thông báo Discord',
				'webhook_url' => '',
				'content'     => 'Có sự kiện mới: {name}',
				'username'    => 'BizCity',
				'avatar_url'  => '',
			),
			'fields'   => array(
				array( 'name' => 'webhook_url', 'label' => 'Discord Webhook URL', 'type' => 'text',     'required' => true ),
				array( 'name' => 'content',     'label' => 'Nội dung',            'type' => 'textarea', 'required' => true ),
				array( 'name' => 'username',    'label' => 'Tên bot hiển thị',    'type' => 'text' ),
				array( 'name' => 'avatar_url',  'label' => 'Avatar URL',          'type' => 'text' ),
			),
		);
	}

	/**
	 * Execute the block.
	 *
	 * @param array $ctx   Runtime context (includes trigger payload).
	 * @param array $data  Block params from workflow definition.
	 * @return array|WP_Error
	 */
	public function execute( array $ctx, array $data ) {
		// [2026-06-07 Johnny Chu] PHASE-0.40 G7.2 — execute Discord notify
		$webhook_url = trim( (string) ( $data['webhook_url'] ?? '' ) );
		$content     = trim( (string) ( $data['content']     ?? '' ) );

		if ( $webhook_url === '' || $content === '' ) {
			return new WP_Error( 'invalid_param', 'notify_discord: webhook_url và content là bắt buộc.' );
		}
		// Validate Discord webhook URL (must be discord.com/api/webhooks/...).
		if ( strpos( $webhook_url, 'discord.com/api/webhooks/' ) === false ) {
			return new WP_Error( 'invalid_param', 'notify_discord: webhook_url phải là Discord webhook URL.' );
		}
		// Substitute context variables {name}, {phone}, {userId}.
		$content = $this->substitute_vars( $content, $ctx );

		$body = array( 'content' => $content );
		if ( ! empty( $data['username'] ) ) {
			$body['username'] = sanitize_text_field( (string) $data['username'] );
		}
		if ( ! empty( $data['avatar_url'] ) ) {
			$body['avatar_url'] = esc_url_raw( (string) $data['avatar_url'] );
		}

		$response = wp_remote_post( $webhook_url, array(
			'headers'     => array( 'Content-Type' => 'application/json' ),
			'body'        => wp_json_encode( $body ),
			'timeout'     => 10,
			'redirection' => 2,
		) );

		if ( is_wp_error( $response ) ) {
			$msg = $response->get_error_message();
			$this->maybe_cron_note_event( 'discord_notify_failed', array(
				'reason'    => 'http_error',
				'error'     => $msg,
				'block_run' => $ctx['run_id'] ?? '',
			) );
			return new WP_Error( 'http_error', $msg );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			$msg = 'Discord returned HTTP ' . $code;
			$this->maybe_cron_note_event( 'discord_notify_failed', array(
				'reason'    => 'http_error',
				'http_code' => $code,
				'block_run' => $ctx['run_id'] ?? '',
			) );
			return new WP_Error( 'http_error', $msg );
		}

		return array( 'success' => true, 'http_code' => $code );
	}

	/* ------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Substitute {name}, {phone}, {userId} from context trigger payload.
	 *
	 * @param string $text Template text.
	 * @param array  $ctx  Runtime context.
	 * @return string
	 */
	private function substitute_vars( $text, array $ctx ) {
		// [2026-06-07 Johnny Chu] PHASE-0.40 G7.2 — variable substitution
		if ( strpos( $text, '{' ) === false ) {
			return $text;
		}
		$trigger = isset( $ctx['trigger'] ) ? (array) $ctx['trigger'] : array();
		$payload = isset( $trigger['payload'] ) ? (array) $trigger['payload'] : array();
		$name    = (string) ( $payload['display_name'] ?? $payload['name'] ?? $trigger['display_name'] ?? '' );
		$phone   = (string) ( $payload['phone'] ?? $trigger['phone'] ?? '' );
		$uid     = (string) ( $payload['user_id'] ?? $payload['contact_id'] ?? $trigger['user_id'] ?? '' );
		$text = str_replace( '{name}',   $name,  $text );
		$text = str_replace( '{phone}',  $phone, $text );
		$text = str_replace( '{userId}', $uid,   $text );
		return $text;
	}

	/**
	 * If BizCity_Cron_Manager is available, call note_event.
	 *
	 * @param string $event_type R-CRON-META event type.
	 * @param array  $data       Event data.
	 */
	private function maybe_cron_note_event( $event_type, array $data ) {
		// [2026-06-07 Johnny Chu] PHASE-0.40 G7.2 — R-CRON-META
		if ( class_exists( 'BizCity_Cron_Manager' ) ) {
			BizCity_Cron_Manager::instance()->note_event( $event_type, $data );
		}
	}
}
