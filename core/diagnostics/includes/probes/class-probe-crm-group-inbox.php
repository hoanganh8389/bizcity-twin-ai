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
			&& class_exists( 'BizCity_CRM_Facebook_Ingestor' );
		$steps[] = array(
			'layer' => 'Loader',
			'label' => 'Zalo emitter, Personal adapter and CRM ingestor are loaded',
			'status' => $loaded ? 'pass' : 'fail',
			'detail' => $loaded ? 'Group fields can cross the emitter/adapter/ingestor boundary.' : 'One or more group Inbox classes are not loaded.',
		);
		if ( ! $loaded ) {
			return array( 'status' => 'fail', 'summary' => 'Group Inbox classes are not loaded.', 'steps' => $steps );
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

		$missing_thread = $adapter->normalize_inbound( array_merge( $base, array( 'thread_id' => '', 'from_user_id' => 'sender-c', 'from_user_name' => 'Sender C', 'message_id' => 'group-healthtest-msg-c' ) ) );
		$missing_id_ok = null === $missing_thread;
		$steps[] = array(
			'layer' => 'Runtime',
			'label' => 'Group without stable thread ID is rejected',
			'status' => $missing_id_ok ? 'pass' : 'fail',
			'detail' => $missing_id_ok ? 'No group ID means no CRM ingest.' : 'Adapter accepted a group without a stable thread ID.',
		);

		$status = $one_group_key && $sender_metadata && $missing_id_ok ? 'pass' : 'fail';
		return array(
			'status' => $status,
			'summary' => $status === 'pass' ? 'CRM group Inbox continuity contract passed without mutation.' : 'CRM group Inbox continuity has runtime gaps.',
			'fix_hint' => 'Forward thread_kind/thread_id from the Personal emitter and use group:<thread_id> as the CRM source key.',
			'steps' => $steps,
		);
	}
}

add_filter( 'bizcity_diagnostics_register_probes', array( 'BizCity_Probe_CRM_Group_Inbox', 'register' ), 10, 1 );
