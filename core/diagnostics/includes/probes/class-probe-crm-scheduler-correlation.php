<?php
/**
 * Runtime DDV for C CRM Scheduler metadata and reminder idempotency.
 *
 * Uses a disposable CRM inbox/conversation and the canonical Scheduler
 * adapter. No provider request or new storage owner is introduced.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since 2026-09-11 (PHASE-0.41-W7-C)
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BizCity_Safe_Loader', false ) ) {
	$_bizcity_safe_loader = dirname( __DIR__, 4 ) . '/core/helper/class-bizcity-safe-loader.php';
	if ( is_file( $_bizcity_safe_loader ) && is_readable( $_bizcity_safe_loader ) ) {
		require_once $_bizcity_safe_loader;
	}
	unset( $_bizcity_safe_loader );
}
if ( ! class_exists( 'BizCity_Safe_Loader', false ) ) {
	return;
}
if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false )
	&& ! BizCity_Safe_Loader::require_file( dirname( __DIR__ ) . '/interface-diagnostics-probe.php', 'diagnostics.probe_interface' ) ) {
	return;
}
if ( class_exists( 'BizCity_Probe_CRM_Scheduler_Correlation', false ) ) {
	return;
}

final class BizCity_Probe_CRM_Scheduler_Correlation implements BizCity_Diagnostics_Probe {

	private $inbox_id = 0;
	private $contact_id = 0;
	private $event_id = 0;

	public function id(): string { return 'modules.twin_gpt.crm_scheduler_correlation'; }
	public function label(): string { return 'C CRM Scheduler correlation'; }
	public function description(): string { return 'Kiểm tra metadata contact/conversation/inbound và reminder idempotency qua canonical CRM Scheduler owner.'; }
	public function severity(): string { return 'critical'; }
	public function order(): int { return 73; }
	public function icon(): string { return 'calendar-check'; }
	public function estimate_ms(): int { return 400; }
	public function precondition() {
		if ( ! class_exists( 'BizCity_CRM_Scheduler_Adapter' ) || ! class_exists( 'BizCity_Scheduler_Manager' ) || ! class_exists( 'BizCity_CRM_Repository' ) || ! class_exists( 'BizCity_CRM_Team_Manager' ) ) {
			return 'CRM Scheduler adapter, manager, repository or team owner is not loaded.';
		}
		if ( (int) get_current_user_id() <= 0 ) {
			return 'An authenticated diagnostics user is required.';
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-09-11 10:40 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.41-W7-C — prove CRM Scheduler event correlation and exactly-once reminder note behavior on a disposable fixture.
		$steps = array();
		$root = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) ) . '/';
		$adapter_path = $root . 'plugins/bizcity-twin-crm/includes/class-scheduler-adapter.php';
		$source = is_readable( $adapter_path ) ? (string) file_get_contents( $adapter_path ) : '';
		$disk_ok = $source !== ''
			&& strpos( $source, 'create_from_conversation' ) !== false
			&& strpos( $source, "'conversation_id'" ) !== false
			&& strpos( $source, "'contact_id'" ) !== false
			&& strpos( $source, 'scheduler:' ) !== false;
		$steps[] = array( 'label' => 'Disk - CRM Scheduler adapter owns correlation and dedupe', 'status' => $disk_ok ? 'pass' : 'fail', 'detail' => $disk_ok ? 'Adapter contains conversation/contact metadata and scheduler external-source dedupe markers.' : 'CRM Scheduler adapter contract markers are missing.' );
		if ( ! $disk_ok ) {
			return array( 'status' => 'fail', 'summary' => 'CRM Scheduler correlation owner is incomplete.', 'fix_hint' => 'Keep appointment creation and reminder dedupe in the canonical CRM Scheduler adapter.', 'steps' => $steps );
		}

		$loader_ok = class_exists( 'BizCity_CRM_Scheduler_Adapter' ) && method_exists( 'BizCity_CRM_Scheduler_Adapter', 'create_from_conversation' ) && class_exists( 'BizCity_Scheduler_Manager' ) && method_exists( BizCity_Scheduler_Manager::instance(), 'claim_due_reminders' );
		$steps[] = array( 'label' => 'Loader - CRM Scheduler adapter and reminder claim owner loaded', 'status' => $loader_ok ? 'pass' : 'fail', 'detail' => $loader_ok ? 'Canonical adapter and Scheduler reminder claim path are available.' : 'Scheduler correlation dependency is unavailable.' );
		if ( ! $loader_ok ) {
			return array( 'status' => 'fail', 'summary' => 'CRM Scheduler dependencies are incomplete.', 'fix_hint' => 'Load the canonical CRM Scheduler adapter and manager before running the correlation probe.', 'steps' => $steps );
		}

		$fixture = $this->create_fixture();
		if ( ! is_array( $fixture ) ) {
			$steps[] = array( 'label' => 'Runtime - disposable CRM conversation fixture', 'status' => 'fail', 'detail' => 'Could not create the disposable inbox/contact/conversation fixture.' );
			return array( 'status' => 'fail', 'summary' => 'CRM Scheduler fixture creation failed.', 'fix_hint' => 'Verify CRM inbox, contact and conversation owners on the current tenant.', 'steps' => $steps );
		}
		$steps[] = array( 'label' => 'Runtime - disposable CRM conversation fixture', 'status' => 'pass', 'detail' => 'Created a diagnostic-only inbox, membership, contact and conversation; cleanup runs after the probe.' );

		// [2026-09-11 11:05 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.41-W7-C — format a due fixture from the UTC epoch once; current_time('timestamp') is already timezone-adjusted.
		$now = time();
		$event_id = BizCity_CRM_Scheduler_Adapter::create_from_conversation( (int) $fixture['conversation_id'], array(
			'title'        => 'Diagnostics CRM Scheduler fixture',
			'start_at'     => wp_date( 'Y-m-d H:i:s', $now - 60 ),
			'end_at'       => wp_date( 'Y-m-d H:i:s', $now + 900 ),
			'event_type'   => 'meeting',
			'reminder_min' => 0,
			'user_id'      => (int) get_current_user_id(),
			'metadata'     => array(
				'inbound' => array(
					'platform'   => 'ADMIN',
					'chat_id'    => '',
					'user_id'    => (string) get_current_user_id(),
					'account_id' => (string) $fixture['inbox_id'],
					'message_id' => '',
					'intent_tag' => 'crm_care_event',
				),
			),
		) );
		$this->event_id = is_wp_error( $event_id ) ? 0 : (int) $event_id;
		$event = $this->event_id > 0 ? BizCity_Scheduler_Manager::instance()->get_event( $this->event_id ) : null;
		$metadata = is_object( $event ) ? json_decode( (string) ( $event->metadata ?? '' ), true ) : array();
		$metadata_ok = is_object( $event )
			&& (string) ( $event->source ?? '' ) === BizCity_CRM_Scheduler_Adapter::SOURCE_TAG
			&& (int) ( $metadata['conversation_id'] ?? 0 ) === (int) $fixture['conversation_id']
			&& (int) ( $metadata['contact_id'] ?? 0 ) === (int) $fixture['contact_id']
			&& is_array( $metadata['inbound'] ?? null )
			&& (string) ( $metadata['inbound']['account_id'] ?? '' ) === (string) $fixture['inbox_id'];
		$steps[] = array( 'label' => 'Runtime - event preserves contact/conversation/inbound metadata', 'status' => $metadata_ok ? 'pass' : 'fail', 'detail' => $metadata_ok ? 'Scheduler row retains the CRM source tag, contact_id, conversation_id and inbound account correlation.' : 'Scheduler metadata did not preserve the required CRM correlation tuple.' );

		$manager = BizCity_Scheduler_Manager::instance();
		$first_claim = $manager->claim_due_reminders( 50 );
		$second_claim = $manager->claim_due_reminders( 50 );
		$first_claim_ids = array_map( 'intval', array_column( $first_claim, 'id' ) );
		$second_claim_ids = array_map( 'intval', array_column( $second_claim, 'id' ) );
		$claim_ok = in_array( $this->event_id, $first_claim_ids, true ) && ! in_array( $this->event_id, $second_claim_ids, true );
		$claim_detail = $claim_ok
			? 'The due CRM event was claimed on the first pass and excluded while its claim was active.'
			: sprintf( 'Reminder claim was missing or duplicated; event=%d status=%s start_at=%s reminder_min=%s reminder_sent=%s first_ids=%s second_ids=%s.', $this->event_id, is_object( $event ) ? (string) ( $event->status ?? '' ) : '', is_object( $event ) ? (string) ( $event->start_at ?? '' ) : '', is_object( $event ) ? (string) ( $event->reminder_min ?? '' ) : '', is_object( $event ) ? (string) ( $event->reminder_sent ?? '' ) : '', implode( ',', $first_claim_ids ), implode( ',', $second_claim_ids ) );
		$steps[] = array( 'label' => 'Runtime - due reminder is claimed once', 'status' => $claim_ok ? 'pass' : 'fail', 'detail' => $claim_detail );

		if ( $claim_ok ) {
			BizCity_CRM_Scheduler_Adapter::on_reminder_fire( $event );
			BizCity_CRM_Scheduler_Adapter::on_reminder_fire( $event );
		}
		$messages = BizCity_CRM_Repository::list_messages( (int) $fixture['conversation_id'], 100, 0 );
		$reminder_external = 'scheduler:' . $this->event_id . ':appointment_reminder';
		$reminder_count = 0;
		foreach ( $messages as $message ) {
			if ( is_array( $message ) && (string) ( $message['external_source_id'] ?? '' ) === $reminder_external ) { $reminder_count++; }
		}
		$dedupe_ok = $claim_ok && $reminder_count === 1;
		$steps[] = array( 'label' => 'Runtime - reminder handler is idempotent', 'status' => $dedupe_ok ? 'pass' : 'fail', 'detail' => $dedupe_ok ? 'Repeated reminder handling produced exactly one canonical system note.' : 'Reminder handling produced an unexpected duplicate count: ' . $reminder_count . '.' );

		return array( 'status' => $metadata_ok && $claim_ok && $dedupe_ok ? 'pass' : 'fail', 'summary' => $metadata_ok && $claim_ok && $dedupe_ok ? 'CRM Scheduler correlation and reminder idempotency passed.' : 'CRM Scheduler correlation or reminder idempotency failed.', 'fix_hint' => $metadata_ok && $claim_ok && $dedupe_ok ? '' : 'Preserve CRM metadata through Scheduler creation and keep reminder claim plus external_source_id dedupe atomic.', 'steps' => $steps );
	}

	private function create_fixture(): ?array {
		$ref = '__diag_sched_' . strtolower( wp_generate_uuid4() );
		$this->inbox_id = BizCity_CRM_Repository::upsert_inbox( 'webchat', $ref, array( 'name' => 'Diagnostics scheduler fixture' ) );
		if ( $this->inbox_id <= 0 || ! BizCity_CRM_Team_Manager::add_inbox_member( $this->inbox_id, (int) get_current_user_id(), 'agent', false ) ) { return null; }
		$contact = BizCity_CRM_Repository::upsert_contact( $this->inbox_id, '__diag_sched_contact_' . strtolower( wp_generate_uuid4() ), array( 'name' => 'Diagnostics scheduler contact' ) );
		$this->contact_id = (int) ( $contact['contact_id'] ?? 0 );
		$contact_inbox_id = (int) ( $contact['contact_inbox_id'] ?? 0 );
		if ( $this->contact_id <= 0 || $contact_inbox_id <= 0 ) { return null; }
		$conversation_id = BizCity_CRM_Repository::open_or_get_conversation( $this->inbox_id, $contact_inbox_id );
		if ( $conversation_id <= 0 ) { return null; }
		global $wpdb;
		$wpdb->update( BizCity_CRM_DB_Installer_V2::tbl_conversations(), array( 'contact_id' => $this->contact_id, 'platform' => 'webchat', 'account_id' => $ref, 'blog_id' => (int) get_current_blog_id() ), array( 'id' => $conversation_id ), array( '%d', '%s', '%s', '%d' ), array( '%d' ) );
		return array( 'inbox_id' => $this->inbox_id, 'contact_id' => $this->contact_id, 'conversation_id' => $conversation_id );
	}

	public function cleanup(): void {
		if ( $this->event_id > 0 && class_exists( 'BizCity_Scheduler_Manager' ) ) { BizCity_Scheduler_Manager::instance()->delete_event( $this->event_id ); }
		if ( $this->inbox_id > 0 && class_exists( 'BizCity_CRM_Repository' ) ) { BizCity_CRM_Repository::delete_inbox( $this->inbox_id ); }
		if ( $this->contact_id > 0 && class_exists( 'BizCity_CRM_DB_Installer_V2' ) ) { global $wpdb; $wpdb->delete( BizCity_CRM_DB_Installer_V2::tbl_contacts(), array( 'id' => $this->contact_id ), array( '%d' ) ); }
		$this->event_id = 0;
		$this->inbox_id = 0;
		$this->contact_id = 0;
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $probes ) {
	$probes[] = 'BizCity_Probe_CRM_Scheduler_Correlation';
	return $probes;
} );