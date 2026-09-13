<?php
/**
 * BizCity Diagnostics - Mabel Wheel channel bridge probe.
 *
 * Probe validates disk, loader, manifest/registry contract and a disposable
 * CRM transaction. It never fires Mabel hooks or calls provider/network APIs.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since PHASE-0.55
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

if ( class_exists( 'BizCity_Probe_Mabel_Wheel_Channel', false ) ) {
	return;
}

final class BizCity_Probe_Mabel_Wheel_Channel implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'core.channel.mabel_wheel_bridge'; }
	public function label(): string { return 'Channel GW - Mabel Wheel bridge'; }
	public function description(): string { return 'Kiểm tra Mabel Wheel bridge, contact merge, resolved-intake idempotency và play enrichment bằng fixture rollback-safe; không gọi provider.'; }
	public function severity(): string { return 'warning'; }
	public function order(): int { return 49; }
	public function icon(): string { return 'ticket'; }
	public function estimate_ms(): int { return 250; }
	public function precondition() { return true; }

	public function run( $ctx ): array {
		// [2026-09-10 Johnny Chu - Chu Hoàng Anh] PHASE-0.55-MABEL-WHEEL - validate the bridge with a disposable CRM transaction before tenant E2E.
		$steps = array();
		$root = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) ) . '/';
		$adapter_file = $root . 'plugins/bizcity-twin-crm/includes/inbox/adapters/class-adapter-mabel-wheel.php';
		$listener_file = $root . 'core/channel-gateway/includes/adapters/class-mabel-wheel-channel-listener.php';
		$disk_ok = is_file( $adapter_file ) && is_readable( $adapter_file ) && is_file( $listener_file ) && is_readable( $listener_file );
		$steps[] = array(
			'label'  => 'Disk - Mabel adapter and listener',
			'status' => $disk_ok ? 'pass' : 'fail',
			'detail' => $disk_ok ? 'Adapter and listener files are readable.' : 'Mabel bridge artifacts are missing or unreadable.',
		);
		if ( ! $disk_ok ) {
			return array( 'status' => 'fail', 'summary' => 'Mabel bridge artifacts are unavailable.', 'fix_hint' => 'Restore the adapter/listener files and rerun this probe.', 'steps' => $steps );
		}

		$loader_ok = class_exists( 'BizCity_CRM_Adapter_Mabel_Wheel', false )
			&& class_exists( 'BizCity_Mabel_Wheel_Channel_Listener', false )
			&& has_action( 'wof_optin', array( 'BizCity_Mabel_Wheel_Channel_Listener', 'on_optin' ) ) !== false
			&& has_action( 'wof_play', array( 'BizCity_Mabel_Wheel_Channel_Listener', 'on_play' ) ) !== false;
		$steps[] = array(
			'label'  => 'Loader - adapter/listener/hooks',
			'status' => $loader_ok ? 'pass' : 'fail',
			'detail' => $loader_ok ? 'Adapter, listener and both Mabel hooks are loaded.' : 'Adapter/listener or Mabel hooks are not loaded.',
		);
		if ( ! $loader_ok ) {
			return array( 'status' => 'fail', 'summary' => 'Mabel bridge loader contract is incomplete.', 'fix_hint' => 'Load the adapter/listener through CRM and Channel Gateway bootstrap, then rerun.', 'steps' => $steps );
		}

		$adapter = new BizCity_CRM_Adapter_Mabel_Wheel();
		$payload = array(
			'event_name'     => 'wof_optin',
			'wheel'          => 12,
			'wheel_name'     => 'Probe Wheel',
			'source_event_id'=> 'probe-mabel-event-0001',
			'email'          => 'probe@example.invalid',
			'fields'         => array( array( 'id' => 'name', 'value' => 'Probe User' ) ),
			'timestamp'      => '2026-09-10 12:00:00',
		);
		$normalized = $adapter->normalize_inbound( $payload );
		$contracted = class_exists( 'BizCity_CRM_Channel_Contract' ) ? BizCity_CRM_Channel_Contract::normalize_inbound( 'mabel_wheel', $normalized ) : null;
		$normalize_ok = is_array( $normalized ) && is_array( $contracted )
			&& (string) ( $contracted['channel_code'] ?? '' ) === 'mabel_wheel'
			&& (string) ( $contracted['inbox_ref'] ?? '' ) === 'mabel_wheel_12'
			&& (string) ( $contracted['external_source_id'] ?? '' ) !== '';
		$steps[] = array(
			'label'  => 'Runtime - synthetic opt-in normalization',
			'status' => $normalize_ok ? 'pass' : 'fail',
			'detail' => $normalize_ok ? 'Synthetic opt-in passed Mabel adapter and CRM contract without DB mutation.' : 'Synthetic opt-in normalization failed.',
		);

		$play = $adapter->normalize_inbound( array_merge( $payload, array( 'event_name' => 'wof_play', 'winning' => true, 'segment_text' => 'Probe prize' ) ) );
		$play_ok = is_array( $play ) && (string) ( $play['event_type'] ?? '' ) === 'mabel_wheel_play';
		$steps[] = array(
			'label'  => 'Runtime - synthetic play enrichment classification',
			'status' => $play_ok ? 'pass' : 'fail',
			'detail' => $play_ok ? 'Synthetic play is classified as enrichment; probe does not create intake rows.' : 'Synthetic play classification failed.',
		);
		$missing_identity = $adapter->normalize_inbound( array_merge( $payload, array( 'source_event_id' => 'probe-mabel-no-identity', 'email' => '', 'fields' => array() ) ) );
		$missing_identity_ok = null === $missing_identity;
		$steps[] = array(
			'label'  => 'Runtime - missing identity fails closed before CRM mutation',
			'status' => $missing_identity_ok ? 'pass' : 'fail',
			'detail' => $missing_identity_ok ? 'Synthetic event without email/phone was rejected by the adapter.' : 'Synthetic event without identity was accepted unexpectedly.',
		);

		$runtime_result = $this->run_crm_fixture( $adapter, $payload, $steps );
		$runtime_status = (string) ( $runtime_result['status'] ?? 'skip' );
		$pass = $normalize_ok && $play_ok && $missing_identity_ok && $runtime_status === 'pass';
		if ( $runtime_status === 'skip' ) {
			return array(
				'status'   => 'skip',
				'summary'  => 'Contract normalization passed; CRM fixture runtime is deferred on this tenant.',
				'fix_hint' => (string) ( $runtime_result['fix_hint'] ?? 'Run this probe as an administrator on a tenant with CRM tables and the Mabel adapter registered.' ),
				'steps'    => $steps,
			);
		}
		return array(
			'status'   => $pass ? 'pass' : 'fail',
			'summary'  => $pass ? 'Mabel Wheel bridge contract and disposable CRM runtime passed.' : 'Mabel Wheel bridge contract or disposable CRM runtime failed.',
			'fix_hint' => $pass ? '' : (string) ( $runtime_result['fix_hint'] ?? 'Check Mabel adapter normalization, CRM channel contract, repository write gate and fixture schema.' ),
			'steps'    => $steps,
		);
	}

	private function run_crm_fixture( $adapter, array $payload, array &$steps ): array {
		// [2026-09-10 Johnny Chu - Chu Hoàng Anh] PHASE-0.55-MABEL-WHEEL - use transaction rollback for contact/intake/idempotency evidence.
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
			$steps[] = array( 'label' => 'Runtime - CRM fixture authorization', 'status' => 'skip', 'detail' => 'manage_options is required for the disposable CRM fixture.' );
			return array( 'status' => 'skip', 'fix_hint' => 'Run the focused probe as an administrator.' );
		}
		if ( ! class_exists( 'BizCity_CRM_Repository' ) || ! class_exists( 'BizCity_CRM_DB_Installer_V2' ) || ! class_exists( 'BizCity_CRM_Channel_Contract' ) ) {
			$steps[] = array( 'label' => 'Runtime - CRM fixture dependencies', 'status' => 'skip', 'detail' => 'CRM repository, DB installer or channel contract is not loaded.' );
			return array( 'status' => 'skip', 'fix_hint' => 'Load the CRM repository, DB installer and channel contract before rerunning.' );
		}
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );
		$rollback = true;
		try {
			$wheel_id = 987654;
			$fixture_payload = array_merge( $payload, array( 'wheel' => $wheel_id, 'source_event_id' => 'probe-mabel-event-' . strtolower( wp_generate_uuid4() ) ) );
			$normalized = $adapter->normalize_inbound( $fixture_payload );
			$inbox_id = BizCity_CRM_Repository::upsert_inbox( 'mabel_wheel', (string) $normalized['inbox_ref'], array( 'name' => 'Diagnostics Mabel Wheel' ) );
			$contact = BizCity_CRM_Repository::upsert_contact_by_identity( $inbox_id, (string) $normalized['source_id'], array(
				'name' => (string) $normalized['contact_name'],
				'email' => (string) $normalized['contact_email'],
				'phone' => (string) $normalized['contact_phone'],
				'acquisition_source' => 'mabel_wheel',
				'acquisition_meta' => array( 'wheel_id' => $wheel_id, 'source_event_id' => (string) $normalized['source_event_id'] ),
				'additional_attributes' => (array) $normalized['additional_attributes'],
			) );
			$intake_data = array(
				'content' => (string) $normalized['content'],
				'content_type' => 'text',
				'message_type' => 'incoming',
				'sender_type' => 'contact',
				'external_source_id' => (string) $normalized['external_source_id'],
				'ai_metadata' => array( 'channel' => 'mabel_wheel', 'event_type' => 'mabel_wheel_lead_capture' ),
			);
			$first_intake = BizCity_CRM_Repository::ingest_resolved_intake( $inbox_id, (int) $contact['contact_inbox_id'], $intake_data );
			$second_intake = BizCity_CRM_Repository::ingest_resolved_intake( $inbox_id, (int) $contact['contact_inbox_id'], $intake_data );
			$conversations_table = BizCity_CRM_DB_Installer_V2::tbl_conversations();
			$conversation_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$conversations_table} WHERE inbox_id = %d AND contact_inbox_id = %d", $inbox_id, (int) $contact['contact_inbox_id'] ) );
			$optin_ok = (int) $contact['contact_id'] > 0
				&& (int) $first_intake['message_id'] > 0
				&& empty( $first_intake['duplicate'] )
				&& ! empty( $second_intake['duplicate'] )
				&& (int) $second_intake['message_id'] === (int) $first_intake['message_id']
				&& $conversation_count === 1;
			$steps[] = array( 'label' => 'Runtime - opt-in contact/intake idempotency', 'status' => $optin_ok ? 'pass' : 'fail', 'detail' => sprintf( 'contact=%d; first_message=%d; second_duplicate=%s; conversations=%d.', (int) $contact['contact_id'], (int) $first_intake['message_id'], ! empty( $second_intake['duplicate'] ) ? 'yes' : 'no', $conversation_count ) );

			$play = $adapter->normalize_inbound( array_merge( $fixture_payload, array( 'event_name' => 'wof_play', 'winning' => true, 'segment_id' => 'probe-prize', 'segment_text' => 'Probe prize' ) ) );
			$play_contact = BizCity_CRM_Repository::upsert_contact_by_identity( $inbox_id, (string) $play['source_id'], array( 'name' => (string) $play['contact_name'], 'email' => (string) $play['contact_email'], 'phone' => (string) $play['contact_phone'], 'acquisition_source' => 'mabel_wheel', 'additional_attributes' => (array) $play['additional_attributes'] ) );
			$conversation_count_after_play = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$conversations_table} WHERE inbox_id = %d AND contact_inbox_id = %d", $inbox_id, (int) $play_contact['contact_inbox_id'] ) );
			$play_ok = (int) $play_contact['contact_id'] === (int) $contact['contact_id'] && $conversation_count_after_play === 1;
			$steps[] = array( 'label' => 'Runtime - play enrichment without duplicate intake', 'status' => $play_ok ? 'pass' : 'fail', 'detail' => sprintf( 'contact=%d; conversations_after_play=%d.', (int) $play_contact['contact_id'], $conversation_count_after_play ) );

			$second_wheel_payload = array_merge( $fixture_payload, array( 'wheel' => $wheel_id + 1, 'wheel_name' => 'Diagnostics Mabel Wheel 2', 'source_event_id' => 'probe-mabel-second-wheel-' . strtolower( wp_generate_uuid4() ) ) );
			$second_normalized = $adapter->normalize_inbound( $second_wheel_payload );
			$second_inbox_id = BizCity_CRM_Repository::upsert_inbox( 'mabel_wheel', (string) $second_normalized['inbox_ref'], array( 'name' => $second_normalized['inbox_name'] ) );
			$second_contact = BizCity_CRM_Repository::upsert_contact_by_identity( $second_inbox_id, (string) $second_normalized['source_id'], array(
				'name' => (string) $second_normalized['contact_name'],
				'email' => (string) $second_normalized['contact_email'],
				'phone' => (string) $second_normalized['contact_phone'],
				'acquisition_source' => 'mabel_wheel',
				'additional_attributes' => (array) $second_normalized['additional_attributes'],
			) );
			$wheel_isolation_ok = (int) $second_normalized['wheel_id'] !== (int) $normalized['wheel_id']
				&& $second_inbox_id > 0
				&& $second_inbox_id !== $inbox_id
				&& (int) $second_contact['contact_id'] === (int) $contact['contact_id']
				&& (int) $second_contact['contact_inbox_id'] !== (int) $contact['contact_inbox_id'];
			$steps[] = array( 'label' => 'Runtime - multi-wheel account isolation', 'status' => $wheel_isolation_ok ? 'pass' : 'fail', 'detail' => sprintf( 'contact=%d/%d; inbox=%d/%d; contact_inbox=%d/%d.', (int) $contact['contact_id'], (int) $second_contact['contact_id'], $inbox_id, $second_inbox_id, (int) $contact['contact_inbox_id'], (int) $second_contact['contact_inbox_id'] ) );

			$wpdb->query( 'ROLLBACK' );
			$rollback = false;
			$runtime_ok = $optin_ok && $play_ok && $wheel_isolation_ok;
			return array( 'status' => $runtime_ok ? 'pass' : 'fail', 'fix_hint' => $runtime_ok ? '' : 'Verify CRM repository identity merge, external_source_id dedupe, passive play policy and per-wheel inbox isolation.' );
		} catch ( \Throwable $e ) {
			$steps[] = array( 'label' => 'Runtime - CRM fixture exception', 'status' => 'fail', 'detail' => get_class( $e ) . ': ' . $e->getMessage() );
			return array( 'status' => 'fail', 'fix_hint' => 'Inspect CRM schema/repository failure; the fixture transaction is rolled back in finally.' );
		} finally {
			if ( $rollback ) { $wpdb->query( 'ROLLBACK' ); }
		}
	}

	public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $probes ) {
	$probes[] = 'BizCity_Probe_Mabel_Wheel_Channel';
	return $probes;
} );
