<?php
/**
 * Runtime proof that disabled CRM channels stop direct writers before SQL
 * message mutation or provider dispatch.
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Probe_CRM_Disabled_Writer', false ) ) {
	return;
}

final class BizCity_Probe_CRM_Disabled_Writer implements BizCity_Diagnostics_Probe {

	private $fixture_inbox_id = 0;
	private $fixture_conversation_id = 0;

	public function id(): string { return 'core.channel.crm_disabled_writer'; }
	public function label(): string { return 'CRM disabled-channel writer isolation'; }
	public function description(): string { return 'Synthetic disabled Telegram inbox proves repository and manual REST writers stop before CRM message SQL or provider dispatch.'; }
	public function severity(): string { return 'critical'; }
	public function order(): int { return 38; }
	public function icon(): string { return 'shield-off'; }
	public function estimate_ms(): int { return 300; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_CRM_Repository' ) || ! class_exists( 'BizCity_CRM_Channel_Contract' ) || ! class_exists( 'BizCity_CRM_REST_Controller' ) ) {
			return 'CRM repository, contract, or REST controller is not loaded.';
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-09-01 Johnny Chu] R-CRM-DISABLED-WRITER — exercise direct and REST writers against a disposable disabled-channel fixture.
		global $wpdb;
		$inboxes = BizCity_CRM_DB_Installer_V2::tbl_inboxes();
		$conversations = BizCity_CRM_DB_Installer_V2::tbl_conversations();
		$messages = BizCity_CRM_DB_Installer_V2::tbl_messages();
		$now = current_time( 'mysql' );
		$fixture_ref = '__probe_disabled_telegram_' . strtolower( wp_generate_uuid4() );
		$provider_calls = 0;
		$provider_tap = static function () use ( &$provider_calls ) { $provider_calls++; };
		add_action( 'bizcity_channel_outbound_logged', $provider_tap, 10, 1 );
		try {
			$inbox_ok = false !== $wpdb->insert( $inboxes, array(
				'name' => 'Diagnostics disabled Telegram',
				'channel_type' => 'telegram',
				'channel_ref_id' => $fixture_ref,
				'channel_id' => $fixture_ref,
				'settings_json' => '{}',
				'is_active' => 1,
				'created_at' => $now,
				'updated_at' => $now,
			) );
			$this->fixture_inbox_id = (int) $wpdb->insert_id;
			$ctx->emit_step( array(
				'label' => 'Fixture · disabled Telegram inbox',
				'status' => $inbox_ok && $this->fixture_inbox_id > 0 ? 'pass' : 'fail',
				'detail' => $inbox_ok && $this->fixture_inbox_id > 0 ? 'Disposable fixture created; it is removed in finally.' : 'Could not create disposable inbox fixture.',
			) );
			if ( ! $inbox_ok || $this->fixture_inbox_id <= 0 ) {
				return array( 'status' => 'fail', 'summary' => 'Disabled-channel fixture could not be created.', 'error' => 'fixture_create_failed', 'fix_hint' => 'Verify CRM inbox schema and rerun the focused probe.' );
			}

			$conversation_ok = false !== $wpdb->insert( $conversations, array(
				'inbox_id' => $this->fixture_inbox_id,
				'contact_inbox_id' => 0,
				'status' => 'open',
				'priority' => 0,
				'unread_count' => 0,
				'last_activity_at' => $now,
				'blog_id' => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0,
				'created_at' => $now,
				'updated_at' => $now,
			) );
			$this->fixture_conversation_id = (int) $wpdb->insert_id;
			if ( ! $conversation_ok || $this->fixture_conversation_id <= 0 ) {
				return array( 'status' => 'fail', 'summary' => 'Disabled-channel conversation fixture could not be created.', 'error' => 'conversation_fixture_create_failed', 'fix_hint' => 'Verify CRM conversation schema and rerun the focused probe.' );
			}

			$before = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$messages} WHERE conversation_id = %d", $this->fixture_conversation_id ) );
			$incoming_id = BizCity_CRM_Repository::insert_message( array(
				'conversation_id' => $this->fixture_conversation_id,
				'inbox_id' => $this->fixture_inbox_id,
				'content' => 'probe-incoming',
				'content_type' => 'text',
				'message_type' => 'incoming',
				'external_source_id' => 'probe-disabled-incoming',
			) );
			$outgoing_id = BizCity_CRM_Repository::insert_message( array(
				'conversation_id' => $this->fixture_conversation_id,
				'inbox_id' => $this->fixture_inbox_id,
				'content' => 'probe-outgoing',
				'content_type' => 'text',
				'message_type' => 'outgoing',
				'external_source_id' => 'probe-disabled-outgoing',
			) );
			$after_direct = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$messages} WHERE conversation_id = %d", $this->fixture_conversation_id ) );
			$direct_ok = $incoming_id === 0 && $outgoing_id === 0 && $before === $after_direct && $provider_calls === 0;
			$ctx->emit_step( array(
				'label' => 'Runtime · direct incoming/outgoing writer gate',
				'status' => $direct_ok ? 'pass' : 'fail',
				'detail' => sprintf( 'ids=%d/%d; messages=%d→%d; provider_events=%d', $incoming_id, $outgoing_id, $before, $after_direct, $provider_calls ),
			) );

			$request = new WP_REST_Request( 'POST', '/bizcity-crm/v1/conversations/' . $this->fixture_conversation_id . '/messages' );
			$request['id'] = $this->fixture_conversation_id;
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( array( 'content' => 'probe-rest-disabled' ) ) );
			$response = BizCity_CRM_REST_Controller::post_message( $request );
			$data = $response instanceof WP_REST_Response ? (array) $response->get_data() : array();
			$after_rest = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$messages} WHERE conversation_id = %d", $this->fixture_conversation_id ) );
			$rest_ok = $response instanceof WP_REST_Response
				&& (int) $response->get_status() === 400
				&& (string) ( $data['code'] ?? '' ) === 'channel_not_configured'
				&& (string) ( $data['hint'] ?? '' ) !== ''
				&& (string) ( $data['help_code'] ?? '' ) === 'channel_setup'
				&& $after_rest === $after_direct
				&& $provider_calls === 0;
			$ctx->emit_step( array(
				'label' => 'Runtime · manual REST writer gate',
				'status' => $rest_ok ? 'pass' : 'fail',
				'detail' => sprintf( 'HTTP=%d; code=%s; messages=%d→%d; provider_events=%d', $response instanceof WP_REST_Response ? (int) $response->get_status() : 0, (string) ( $data['code'] ?? '' ), $after_direct, $after_rest, $provider_calls ),
			) );

			if ( ! $direct_ok || ! $rest_ok ) {
				return array( 'status' => 'fail', 'summary' => 'Disabled CRM channel allowed a writer side effect.', 'error' => 'disabled_writer_side_effect', 'fix_hint' => 'Keep require_crm_enabled() before message insert and provider dispatch for every direct writer.' );
			}
			return array( 'status' => 'pass', 'summary' => 'Disabled Telegram blocked direct and manual REST writers before CRM message SQL or provider dispatch.' );
		} finally {
			remove_action( 'bizcity_channel_outbound_logged', $provider_tap, 10 );
			$this->cleanup();
		}
	}

	public function cleanup(): void {
		global $wpdb;
		if ( $this->fixture_conversation_id > 0 ) {
			$wpdb->delete( BizCity_CRM_DB_Installer_V2::tbl_messages(), array( 'conversation_id' => $this->fixture_conversation_id ), array( '%d' ) );
			$wpdb->delete( BizCity_CRM_DB_Installer_V2::tbl_conversations(), array( 'id' => $this->fixture_conversation_id ), array( '%d' ) );
			$this->fixture_conversation_id = 0;
		}
		if ( $this->fixture_inbox_id > 0 ) {
			$wpdb->delete( BizCity_CRM_DB_Installer_V2::tbl_inboxes(), array( 'id' => $this->fixture_inbox_id ), array( '%d' ) );
			$this->fixture_inbox_id = 0;
		}
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_CRM_Disabled_Writer';
	return $list;
} );