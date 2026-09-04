<?php
/**
 * Rollback-safe provider and CRM side-effect matrix for Zalo Bot.
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Probe_Zalobot_CRM_Provider_Matrix', false ) ) {
	return;
}

final class BizCity_Probe_Zalobot_CRM_Provider_Matrix implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'core.channel.zalobot_crm_provider_matrix'; }
	public function label(): string { return 'Zalo Bot CRM provider side-effect matrix'; }
	public function description(): string { return 'Rollback-safe mock provider proof for Bot inbound dedupe, outbound routing, self-echo mirror and two-Bot isolation.'; }
	public function severity(): string { return 'critical'; }
	public function order(): int { return 46; }
	public function icon(): string { return 'shield-check'; }
	public function estimate_ms(): int { return 400; }

	public function precondition() {
		$required = array(
			'BizCity_CRM_Adapter_ZaloBot',
			'BizCity_CRM_Facebook_Ingestor',
			'BizCity_CRM_Repository',
			'BizCity_CRM_DB_Installer_V2',
			'BizCity_CRM_Channel_Contract',
			'BizCity_Zalo_Bot_Channel_Adapter',
			'BizCity_Gateway_Sender',
		);
		foreach ( $required as $class ) {
			if ( ! class_exists( $class ) ) {
				return $class . ' is not loaded.';
			}
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-09-01 Johnny Chu] PHASE-0.45-PROVIDER-DDV - run inbound, outbound and isolation checks in one rollback-safe mock fixture.
		$steps = array();
		$root = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( __DIR__, 4 ) . '/';
		$files = array(
			'crm_adapter' => $root . 'plugins/bizcity-twin-crm/includes/inbox/adapters/class-adapter-zalo-bot.php',
			'crm_ingestor' => $root . 'plugins/bizcity-twin-crm/includes/inbox/class-fb-ingestor.php',
			'contract' => $root . 'plugins/bizcity-twin-crm/includes/inbox/class-channel-contract.php',
			'gateway_adapter' => $root . 'plugins/bizcity-zalo-bot/includes/class-channel-adapter.php',
			'gateway_sender' => $root . 'core/channel-gateway/includes/class-gateway-sender.php',
			'provider_api' => $root . 'plugins/bizcity-zalo-bot/lib/class-zalo-bot-api.php',
		);
		$disk_ok = true;
		foreach ( $files as $key => $file ) {
			$ok = is_file( $file ) && is_readable( $file );
			$steps[] = array( 'layer' => 'Disk', 'label' => 'Artifact: ' . $key, 'status' => $ok ? 'pass' : 'fail', 'detail' => $ok ? 'Readable canonical artifact.' : 'Artifact missing or unreadable.' );
			if ( ! $ok ) { $disk_ok = false; }
		}
		if ( ! $disk_ok ) {
			return array( 'status' => 'fail', 'summary' => 'Zalo Bot provider matrix artifacts are incomplete.', 'steps' => $steps, 'fix_hint' => 'Restore the canonical CRM/Gateway/Provider artifacts and rerun this probe.' );
		}

		if ( ! defined( 'BIZCITY_DIAGNOSTICS_MOCK' ) || ! BIZCITY_DIAGNOSTICS_MOCK ) {
			$steps[] = array( 'layer' => 'Runtime', 'label' => 'Mock transport safety gate', 'status' => 'skip', 'detail' => 'This matrix never calls a live provider; rerun from diagnostics mock context.' );
			return array( 'status' => 'skip', 'summary' => 'Provider matrix requires diagnostics mock context.', 'steps' => $steps );
		}

		$loader_ok = class_exists( 'BizCity_CRM_Adapter_ZaloBot', false )
			&& class_exists( 'BizCity_CRM_Facebook_Ingestor', false )
			&& class_exists( 'BizCity_Zalo_Bot_Channel_Adapter', false )
			&& class_exists( 'BizCity_Gateway_Sender', false );
		$steps[] = array( 'layer' => 'Loader', 'label' => 'CRM and Gateway Bot classes', 'status' => $loader_ok ? 'pass' : 'fail', 'detail' => $loader_ok ? 'CRM adapter, ingestor, Gateway adapter and sender are loaded.' : 'A required Bot runtime class is unavailable.' );
		if ( ! $loader_ok ) {
			return array( 'status' => 'fail', 'summary' => 'Zalo Bot provider matrix dependencies are not loaded.', 'steps' => $steps, 'fix_hint' => 'Load the CRM and Channel Gateway bootstraps before running the focused probe.' );
		}

		global $wpdb;
		$inboxes = BizCity_CRM_DB_Installer_V2::tbl_inboxes();
		$conversations = BizCity_CRM_DB_Installer_V2::tbl_conversations();
		$messages = BizCity_CRM_DB_Installer_V2::tbl_messages();
		$transaction_started = false;
		$provider_calls = array();
		$mock = static function ( $result, $chat_id, $message, $options ) use ( &$provider_calls ) {
			$provider_calls[] = array(
				'chat_id' => (string) $chat_id,
				'message_hash' => substr( sha1( (string) $message ), 0, 12 ),
				'type' => (string) ( $options['type'] ?? 'text' ),
			);
			return array( 'sent' => true );
		};
		add_filter( 'bizcity_zalobot_send_outbound_result', $mock, 10, 4 );
		try {
			$wpdb->query( 'START TRANSACTION' );
			$transaction_started = true;
			$adapter = new BizCity_CRM_Adapter_ZaloBot();
			$ingestor = BizCity_CRM_Facebook_Ingestor::instance();
			$bot_a = '990001';
			$bot_b = '990002';
			$user_a = 'probe-user-a';
			$user_b = 'probe-user-b';
			$base = array(
				'account_name' => 'Diagnostics Bot',
				'from_user_name' => 'Diagnostics User',
				'message_text' => 'provider matrix inbound',
				'message_time' => '2026-09-01 00:00:00',
				'thread_kind' => 'personal',
			);
			$raw_a = array_merge( $base, array(
				'conversation_id' => $bot_a,
				'bot_id' => $bot_a,
				'from_user_id' => $user_a,
				'chat_id' => 'zalobot_' . $bot_a . '_private_' . $user_a,
				'message_id' => 'probe-zb-in-a',
			) );
			$raw_b = array_merge( $base, array(
				'conversation_id' => $bot_b,
				'bot_id' => $bot_b,
				'from_user_id' => $user_b,
				'chat_id' => 'zalobot_' . $bot_b . '_private_' . $user_b,
				'message_id' => 'probe-zb-in-b',
			) );
			$norm_a = $adapter->normalize_inbound( $raw_a );
			$norm_b = $adapter->normalize_inbound( $raw_b );
			$first_a = is_array( $norm_a ) ? $ingestor->ingest( $adapter, $norm_a ) : 0;
			$retry_a = is_array( $norm_a ) ? $ingestor->ingest( $adapter, $norm_a ) : 0;
			$first_b = is_array( $norm_b ) ? $ingestor->ingest( $adapter, $norm_b ) : 0;
			$inbox_a = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$inboxes} WHERE channel_type = 'zalo_bot' AND channel_ref_id = %s LIMIT 1", $bot_a ) );
			$inbox_b = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$inboxes} WHERE channel_type = 'zalo_bot' AND channel_ref_id = %s LIMIT 1", $bot_b ) );
			$conversation_a = $inbox_a > 0 ? (array) $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$conversations} WHERE inbox_id = %d LIMIT 1", $inbox_a ), ARRAY_A ) : array();
			$conversation_b = $inbox_b > 0 ? (array) $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$conversations} WHERE inbox_id = %d LIMIT 1", $inbox_b ), ARRAY_A ) : array();
			$inbound_ok = $first_a > 0 && $retry_a === 0 && $first_b > 0 && $inbox_a > 0 && $inbox_b > 0 && $inbox_a !== $inbox_b && (int) ( $conversation_a['id'] ?? 0 ) !== (int) ( $conversation_b['id'] ?? 0 );
			$steps[] = array( 'layer' => 'Runtime', 'label' => 'Inbound retry dedupe and two-Bot inbox isolation', 'status' => $inbound_ok ? 'pass' : 'fail', 'detail' => sprintf( 'botA message=%d retry=%d inbox=%d; botB message=%d inbox=%d.', $first_a, $retry_a, $inbox_a, $first_b, $inbox_b ) );

			$send_result = ! empty( $conversation_a ) ? $adapter->send( $conversation_a, array( 'content' => 'provider matrix outbound', 'content_type' => 'text', 'attachments' => array() ) ) : array();
			$send_ok = is_array( $send_result ) && ! empty( $send_result['success'] ) && count( $provider_calls ) === 1 && (string) $provider_calls[0]['chat_id'] === 'zalobot_' . $bot_a . '_private_' . $user_a;
			$steps[] = array( 'layer' => 'Runtime', 'label' => 'Outbound Gateway routes to exact Bot/private target', 'status' => $send_ok ? 'pass' : 'fail', 'detail' => $send_ok ? 'Mock provider received exactly one Bot A private target; no live request was made.' : wp_json_encode( array( 'send' => $send_result, 'calls' => $provider_calls ) ) );

			$outgoing_count = $inbox_a > 0 ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$messages} WHERE inbox_id = %d AND message_type = 'outgoing'", $inbox_a ) ) : 0;
			$mirror_ok = $outgoing_count === 1;
			$steps[] = array( 'layer' => 'Runtime', 'label' => 'Successful outbound creates one CRM mirror', 'status' => $mirror_ok ? 'pass' : 'fail', 'detail' => 'Bot A outgoing CRM mirror count=' . $outgoing_count . '.' );
			$outgoing_id = $inbox_a > 0 ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$messages} WHERE inbox_id = %d AND message_type = 'outgoing' AND content = %s ORDER BY id DESC LIMIT 1", $inbox_a, 'provider matrix outbound' ) ) : 0;
			$delivery_success = $outgoing_id > 0 && BizCity_CRM_Repository::update_message_delivery( $outgoing_id, array( 'sent' => true, 'platform' => 'ZALO_BOT', 'error' => '' ) );
			$delivery_sent_row = $outgoing_id > 0 ? BizCity_CRM_Repository::get_message( $outgoing_id ) : array();
			$delivery_sent_ok = $delivery_success && is_array( $delivery_sent_row ) && (string) ( $delivery_sent_row['status'] ?? '' ) === 'sent';
			$delivery_failure = $outgoing_id > 0 && BizCity_CRM_Repository::update_message_delivery( $outgoing_id, array( 'sent' => false, 'platform' => 'ZALO_BOT', 'error' => 'timeout' ) );
			$delivery_failed_row = $outgoing_id > 0 ? BizCity_CRM_Repository::get_message( $outgoing_id ) : array();
			$delivery_payload = is_array( $delivery_failed_row ) && ! empty( $delivery_failed_row['payload_json'] ) ? json_decode( (string) $delivery_failed_row['payload_json'], true ) : array();
			$delivery_failed_ok = $delivery_failure && is_array( $delivery_failed_row ) && (string) ( $delivery_failed_row['status'] ?? '' ) === 'failed' && (string) ( $delivery_payload['delivery']['reason_code'] ?? '' ) === 'provider_error';
			$retry_result = BizCity_CRM_Channel_Contract::normalize_send_result( 'zalo_bot', array( 'success' => false, 'error' => 'timeout', 'retryable' => true ) );
			$retry_contract_ok = (string) ( $retry_result['outcome'] ?? '' ) === 'failed' && ! empty( $retry_result['retryable'] ) && (string) ( $retry_result['channel_code'] ?? '' ) === 'zalo_bot';
			$delivery_ok = $delivery_sent_ok && $delivery_failed_ok && $retry_contract_ok;
			$steps[] = array( 'layer' => 'Runtime', 'label' => 'Delivery status and retryable outcome contract', 'status' => $delivery_ok ? 'pass' : 'fail', 'detail' => sprintf( 'message=%d; sent=%s; failed=%s; retryable=%s.', $outgoing_id, $delivery_sent_ok ? 'sent' : 'bad', $delivery_failed_ok ? 'failed/provider_error' : 'bad', $retry_contract_ok ? 'true' : 'bad' ) );

			$cross_bot_ok = $inbox_b > 0 ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$messages} WHERE inbox_id = %d AND content = %s", $inbox_b, 'provider matrix outbound' ) ) === 0 : false;
			$steps[] = array( 'layer' => 'Runtime', 'label' => 'Outbound does not cross into Bot B', 'status' => $cross_bot_ok ? 'pass' : 'fail', 'detail' => $cross_bot_ok ? 'Bot B has no Bot A outbound content.' : 'Bot A outbound content crossed the Bot B inbox.' );

			$status = $inbound_ok && $send_ok && $mirror_ok && $delivery_ok && $cross_bot_ok ? 'pass' : 'fail';
			return array( 'status' => $status, 'summary' => $status === 'pass' ? 'Mock provider matrix passed: inbound dedupe, exact outbound target, CRM mirror and two-Bot isolation.' : 'Zalo Bot provider/CRM side-effect matrix failed.', 'steps' => $steps, 'fix_hint' => 'Keep Bot identity as bot_id plus private/group target, preserve external_source_id dedupe, and mirror only the exact outbound conversation.' );
		} catch ( \Throwable $e ) {
			$steps[] = array( 'layer' => 'Runtime', 'label' => 'Provider matrix exception', 'status' => 'fail', 'detail' => get_class( $e ) . ': ' . $e->getMessage() );
			return array( 'status' => 'fail', 'summary' => 'Provider matrix threw; fixture must be rolled back.', 'steps' => $steps, 'fix_hint' => 'Inspect the focused exception and rerun the same probe after the local fix.' );
		} finally {
			remove_filter( 'bizcity_zalobot_send_outbound_result', $mock, 10 );
			if ( $transaction_started ) {
				$wpdb->query( 'ROLLBACK' );
			}
		}
	}

	public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Zalobot_CRM_Provider_Matrix';
	return $list;
} );
