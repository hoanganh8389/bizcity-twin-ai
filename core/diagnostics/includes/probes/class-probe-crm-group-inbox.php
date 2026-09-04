<?php
/**
 * Read-only DDV probe for Zalo Personal group Inbox continuity.
 *
 * Verifies that multiple senders in one group normalize to one group source
 * key, while sender identity remains message metadata. No CRM rows are written.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since 2026-08-25
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

if ( class_exists( 'BizCity_Probe_CRM_Group_Inbox', false ) ) {
	return;
}

final class BizCity_Probe_CRM_Group_Inbox implements BizCity_Diagnostics_Probe {

	public static function register( $probes ) {
		if ( ! in_array( 'BizCity_Probe_CRM_Group_Inbox', $probes, true ) ) {
			$probes[] = 'BizCity_Probe_CRM_Group_Inbox';
		}
		return $probes;
	}

	public function id(): string { return 'modules.crm.group_inbox'; }
	public function label(): string { return 'CRM group Inbox continuity'; }
	public function description(): string { return 'Kiểm tra nhiều sender trong cùng Zalo group dùng một CRM conversation key và không bị tách thành từng contact.'; }
	public function severity(): string { return 'critical'; }
	public function order(): int { return 65; }
	public function icon(): string { return 'users-round'; }
	public function estimate_ms(): int { return 250; }
	public function precondition() { return true; }
	public function cleanup(): void {}

	public function run( $ctx ): array {
		// [2026-08-25 Johnny Chu] PHASE-0.39F-GROUP-INBOX-DDV — verify group source identity and sender metadata without CRM mutation.
		$steps = array();
		$root = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( __DIR__, 4 ) . '/';
		$files = array(
			'plugins/bizcity-zalo-personal/includes/shared/class-zalo-inbound-emitter.php',
			'plugins/bizcity-twin-crm/includes/inbox/adapters/class-adapter-zalo-personal.php',
			'plugins/bizcity-twin-crm/includes/inbox/class-fb-ingestor.php',
			'plugins/bizcity-twin-crm/frontend/src/components/ConversationList.jsx',
		);
		$missing = array();
		foreach ( $files as $relative ) {
			if ( ! is_readable( $root . $relative ) ) { $missing[] = $relative; }
		}
		$steps[] = array(
			'layer' => 'Disk',
			'label' => 'Group Inbox source artifacts exist',
			'status' => empty( $missing ) ? 'pass' : 'fail',
			'detail' => empty( $missing ) ? implode( ', ', $files ) : implode( ', ', $missing ),
		);
		if ( ! empty( $missing ) ) {
			return array( 'status' => 'fail', 'summary' => 'Group Inbox artifacts are incomplete.', 'steps' => $steps );
		}

		$loaded = class_exists( 'BizCity_Zalo_Inbound_Emitter' )
			&& class_exists( 'BizCity_CRM_Adapter_ZaloPersonal' )
			&& class_exists( 'BizCity_CRM_Facebook_Ingestor' )
			&& class_exists( 'BizCity_CRM_Channel_Contract' );
		$steps[] = array(
			'layer' => 'Loader',
			'label' => 'Zalo emitter, Personal adapter and CRM ingestor are loaded',
			'status' => $loaded ? 'pass' : 'fail',
			'detail' => $loaded ? 'Group fields can cross the emitter/adapter/ingestor boundary.' : 'One or more group Inbox classes are not loaded.',
		);
		if ( ! $loaded ) {
			return array( 'status' => 'fail', 'summary' => 'Group Inbox classes are not loaded.', 'steps' => $steps );
		}
		if ( 'GROUP_INBOX' === strtoupper( trim( (string) $ctx->option( 'confirm', '' ) ) ) ) {
			return $this->run_runtime_fixture( $steps, $ctx );
		}

		$adapter = new BizCity_CRM_Adapter_ZaloPersonal();
		$base = array(
			'conversation_id' => 'account-healthtest',
			'account_id' => 'account-healthtest',
			'account_name' => 'Health test',
			'thread_kind' => 'group',
			'thread_id' => 'group-healthtest-1',
			'group_name' => 'Health test group',
			'message_text' => 'synthetic group message',
			'message_type' => 'text',
			'image_url' => '',
			'file_url' => '',
			'message_time' => '2026-08-25 00:00:00',
		);
		$first = $adapter->normalize_inbound( array_merge( $base, array( 'from_user_id' => 'sender-a', 'from_user_name' => 'Sender A', 'message_id' => 'group-healthtest-msg-a' ) ) );
		$second = $adapter->normalize_inbound( array_merge( $base, array( 'from_user_id' => 'sender-b', 'from_user_name' => 'Sender B', 'message_id' => 'group-healthtest-msg-b' ) ) );
		$one_group_key = is_array( $first ) && is_array( $second )
			&& 'group:group-healthtest-1' === (string) ( $first['source_id'] ?? '' )
			&& (string) ( $first['source_id'] ?? '' ) === (string) ( $second['source_id'] ?? '' )
			&& 'group' === (string) ( $first['thread_kind'] ?? '' )
			&& 'group' === (string) ( $second['thread_kind'] ?? '' );
		$sender_metadata = $one_group_key
			&& 'sender-a' === (string) ( $first['sender_user_id'] ?? '' )
			&& 'sender-b' === (string) ( $second['sender_user_id'] ?? '' )
			&& 'sender-a' === (string) ( $first['ai_metadata']['sender_user_id'] ?? '' )
			&& 'sender-b' === (string) ( $second['ai_metadata']['sender_user_id'] ?? '' );
		$steps[] = array(
			'layer' => 'Runtime',
			'label' => 'Two senders resolve to one group CRM source key',
			'status' => $one_group_key ? 'pass' : 'fail',
			'detail' => $one_group_key ? 'Both synthetic messages normalize to group:group-healthtest-1.' : 'Group messages still resolve to different or private source keys.',
		);
		$steps[] = array(
			'layer' => 'Runtime',
			'label' => 'Sender identity remains message metadata',
			'status' => $sender_metadata ? 'pass' : 'fail',
			'detail' => $sender_metadata ? 'sender-a and sender-b remain distinct metadata on one group thread.' : 'Sender metadata was lost or used as the group source key.',
		);
		$contract_payload = is_array( $first ) ? BizCity_CRM_Channel_Contract::normalize_inbound( 'zalo_personal', $first ) : null;
		$contract_group_ok = is_array( $contract_payload )
			&& ! is_wp_error( $contract_payload )
			&& 'group' === (string) ( $contract_payload['thread_kind'] ?? '' )
			&& 'group:group-healthtest-1' === (string) ( $contract_payload['source_id'] ?? '' )
			&& 'group' === (string) ( $contract_payload['ai_metadata']['thread_kind'] ?? '' );
		$steps[] = array(
			'layer' => 'Runtime',
			'label' => 'Shared CRM contract preserves group identity before SQL',
			'status' => $contract_group_ok ? 'pass' : 'fail',
			'detail' => $contract_group_ok ? 'thread_kind and group source key survive the pre-SQL contract.' : 'Shared CRM contract dropped or changed group identity.',
		);

		$missing_thread = $adapter->normalize_inbound( array_merge( $base, array( 'thread_id' => '', 'from_user_id' => 'sender-c', 'from_user_name' => 'Sender C', 'message_id' => 'group-healthtest-msg-c' ) ) );
		$missing_id_ok = null === $missing_thread;
		$steps[] = array(
			'layer' => 'Runtime',
			'label' => 'Group without stable thread ID is rejected',
			'status' => $missing_id_ok ? 'pass' : 'fail',
			'detail' => $missing_id_ok ? 'No group ID means no CRM ingest.' : 'Adapter accepted a group without a stable thread ID.',
		);

		$status = $one_group_key && $sender_metadata && $contract_group_ok && $missing_id_ok ? 'pass' : 'fail';
		return array(
			'status' => $status,
			'summary' => $status === 'pass' ? 'CRM group Inbox continuity contract passed without mutation.' : 'CRM group Inbox continuity has runtime gaps.',
			'fix_hint' => 'Forward thread_kind/thread_id from the Personal emitter and use group:<thread_id> as the CRM source key.',
			'steps' => $steps,
		);
	}

	private function run_runtime_fixture( array $steps, $ctx ): array {
		// [2026-08-26 Johnny Chu] PHASE-0.39F-GROUP-INBOX-RUNTIME — run only on explicit admin confirmation and roll back all synthetic CRM writes.
		if ( ! current_user_can( 'manage_options' ) ) {
			$steps[] = array( 'layer' => 'Runtime', 'label' => 'Group Inbox fixture authorization', 'status' => 'fail', 'detail' => 'manage_options is required for the mutating fixture.' );
			return array( 'status' => 'fail', 'summary' => 'Group Inbox fixture requires an administrator.', 'steps' => $steps );
		}
		if ( ! class_exists( 'BizCity_CRM_Repository' ) || ! class_exists( 'BizCity_CRM_DB_Installer_V2' ) || ! class_exists( 'BizCity_CRM_Facebook_Ingestor' ) ) {
			$steps[] = array( 'layer' => 'Runtime', 'label' => 'Group Inbox fixture dependencies', 'status' => 'fail', 'detail' => 'CRM Repository, DB installer or ingestor is not loaded.' );
			return array( 'status' => 'fail', 'summary' => 'Group Inbox fixture dependencies are unavailable.', 'steps' => $steps );
		}
		global $wpdb;
		$group_id = '__healthtest_group_' . strtolower( wp_generate_uuid4() );
		$inbox_ref = '__healthtest_group_account_' . strtolower( wp_generate_uuid4() );
		$source_id = 'group:' . $group_id;
		$wpdb->query( 'START TRANSACTION' );
		$rollback = true;
		try {
			$adapter = new BizCity_CRM_Adapter_ZaloPersonal();
			$base = array(
				'conversation_id' => $inbox_ref,
				'account_id' => $inbox_ref,
				'account_name' => 'Health test',
				'thread_kind' => 'group',
				'thread_id' => $group_id,
				'group_name' => 'Health test group',
				'message_type' => 'text',
				'image_url' => '',
				'file_url' => '',
				'message_time' => time(),
			);
			$first = $adapter->normalize_inbound( array_merge( $base, array( 'from_user_id' => 'sender-a', 'from_user_name' => 'Sender A', 'message_id' => $group_id . '_a', 'message_text' => 'synthetic-group-a' ) ) );
			$second = $adapter->normalize_inbound( array_merge( $base, array( 'from_user_id' => 'sender-b', 'from_user_name' => 'Sender B', 'message_id' => $group_id . '_b', 'message_text' => 'synthetic-group-b' ) ) );
			$first_message_id = $first ? BizCity_CRM_Facebook_Ingestor::instance()->ingest( $adapter, $first ) : 0;
			$second_message_id = $second ? BizCity_CRM_Facebook_Ingestor::instance()->ingest( $adapter, $second ) : 0;
			$inbox_table = BizCity_CRM_DB_Installer_V2::tbl_inboxes();
			$contact_inboxes = BizCity_CRM_DB_Installer_V2::tbl_contact_inboxes();
			$conversations = BizCity_CRM_DB_Installer_V2::tbl_conversations();
			$inbox_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$inbox_table}` WHERE channel_type = 'zalo_personal' AND channel_ref_id = %s LIMIT 1", $inbox_ref ) );
			$contact_count = $inbox_id > 0 ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$contact_inboxes}` WHERE inbox_id = %d AND source_id = %s", $inbox_id, $source_id ) ) : 0;
			$conversation_count = $inbox_id > 0 ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$conversations}` WHERE inbox_id = %d AND contact_inbox_id IN (SELECT id FROM `{$contact_inboxes}` WHERE inbox_id = %d AND source_id = %s)", $inbox_id, $inbox_id, $source_id ) ) : 0;
			$private_contacts = $inbox_id > 0 ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$contact_inboxes}` WHERE inbox_id = %d AND source_id IN ('sender-a','sender-b')", $inbox_id ) ) : 0;
			$group_ingest_ok = $first_message_id > 0 && $second_message_id > 0 && 1 === $contact_count && 1 === $conversation_count && 0 === $private_contacts;
			$steps[] = array(
				'layer' => 'Runtime',
				'label' => 'Two senders create one group contact/conversation',
				'status' => $group_ingest_ok ? 'pass' : 'fail',
				'detail' => sprintf( 'message_ids=%d,%d; group_contacts=%d; group_conversations=%d; private_contacts=%d.', $first_message_id, $second_message_id, $contact_count, $conversation_count, $private_contacts ),
			);

			$native_mirror_id = BizCity_CRM_Facebook_Ingestor::instance()->ingest_outbound( 'zalo_personal', array(
				'inbox_ref' => $inbox_ref,
				'inbox_name' => 'Zalo Cá nhân Health test',
				'source_id' => $source_id,
				'contact_name' => '',
				'content' => 'synthetic-native-group-self-echo',
				'content_type' => 'text',
				'external_source_id' => 'zalo:self:' . $group_id . '_native',
				'sender_type' => 'agent',
				'thread_kind' => 'group',
				'group_id' => $group_id,
				'ai_metadata' => array( 'thread_kind' => 'group', 'group_id' => $group_id, 'origin' => 'native_zalo' ),
			) );
			$contact_count_after_echo = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$contact_inboxes}` WHERE inbox_id = %d AND source_id = %s", $inbox_id, $source_id ) );
			$conversation_count_after_echo = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$conversations}` WHERE inbox_id = %d AND contact_inbox_id IN (SELECT id FROM `{$contact_inboxes}` WHERE inbox_id = %d AND source_id = %s)", $inbox_id, $inbox_id, $source_id ) );
			$self_echo_ok = $native_mirror_id > 0 && 1 === $contact_count_after_echo && 1 === $conversation_count_after_echo;
			$steps[] = array(
				'layer' => 'Runtime',
				'label' => 'Native group self-echo stays on the same CRM thread',
				'status' => $self_echo_ok ? 'pass' : 'fail',
				'detail' => sprintf( 'native_mirror_message_id=%d; group_contacts=%d; group_conversations=%d.', $native_mirror_id, $contact_count_after_echo, $conversation_count_after_echo ),
			);

			$bridge_account_id = sanitize_text_field( (string) $ctx->option( 'bridge_account_id', '' ) );
			$emitter_status = 'skip';
			$emitter_detail = 'No bridge_account_id supplied; native emitter requires an existing mapped test account, so only the repository native-mirror path was exercised.';
			if ( $bridge_account_id !== '' && class_exists( 'BizCity_Zalo_Inbound_Emitter' ) ) {
				$emitter_result = BizCity_Zalo_Inbound_Emitter::instance()->emit( array(
					'kind' => 'personal',
					'account_id' => $bridge_account_id,
					'message_id' => $group_id . '_emitter',
					'thread_kind' => 'group',
					'thread_id' => $group_id,
					'is_group' => true,
					'from_user_id' => 'sender-native',
					'from_user_name' => 'Native sender',
					'message_text' => '',
					'message_type' => 'text',
					'origin' => 'native_zalo',
					'is_self' => 1,
				) );
				$emitter_status = $emitter_result > 0 ? 'pass' : 'fail';
				$emitter_detail = 'native emitter result=' . (int) $emitter_result . '; mapped test account was supplied.';
			}
			$steps[] = array( 'layer' => 'Runtime', 'label' => 'Native emitter group fixture', 'status' => $emitter_status, 'detail' => $emitter_detail );
			$wpdb->query( 'ROLLBACK' );
			$rollback = false;
			$fixture_ok = $group_ingest_ok && $self_echo_ok;
			$full_runtime_ok = $fixture_ok && 'pass' === $emitter_status;
			$fixture_status = $full_runtime_ok ? 'pass' : ( 'skip' === $emitter_status && $fixture_ok ? 'skip' : 'fail' );
			$summary = $full_runtime_ok
				? 'Group Inbox Runtime fixture passed, including the native emitter branch, and rolled back synthetic CRM state.'
				: ( 'skip' === $fixture_status
					? 'Group normalization and native-mirror checks passed, but the actual emitter branch was skipped because no mapped diagnostic account was supplied.'
					: 'Group Inbox Runtime fixture failed; synthetic state was rolled back.' );
			return array( 'status' => $fixture_status, 'summary' => $summary, 'steps' => $steps );
		} catch ( \Throwable $e ) {
			$steps[] = array( 'layer' => 'Runtime', 'label' => 'Group Inbox fixture exception', 'status' => 'fail', 'detail' => get_class( $e ) . ': ' . $e->getMessage() );
			return array( 'status' => 'fail', 'summary' => 'Group Inbox Runtime fixture threw; synthetic state will be rolled back.', 'steps' => $steps );
		} finally {
			if ( $rollback ) { $wpdb->query( 'ROLLBACK' ); }
		}
	}
}

add_filter( 'bizcity_diagnostics_register_probes', array( 'BizCity_Probe_CRM_Group_Inbox', 'register' ), 10, 1 );
