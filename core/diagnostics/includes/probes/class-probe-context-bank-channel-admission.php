<?php
/**
 * DDV probe for the CB4.2 channel archive admission boundary.
 *
 * This probe validates fail-closed admission and loader wiring without creating
 * CRM messages or archive rows. End-to-end receipt admission remains a gated
 * follow-up until a disposable archive/ledger fixture has a tombstone cleanup.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since 2026-09-02 (PHASE-0.41-CRM-ONE-BRAIN)
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
if ( class_exists( 'BizCity_Probe_Context_Bank_Channel_Admission', false ) ) {
	return;
}

final class BizCity_Probe_Context_Bank_Channel_Admission implements BizCity_Diagnostics_Probe {

	const FLAG = 'bizcity_context_bank_channel_capture_enabled';

	public function id(): string { return 'core.context_bank.channel_admission'; }
	public function label(): string { return 'Context Bank channel archive admission'; }
	public function description(): string { return 'Kiểm tra feature gate, exact manifest/identity boundary và fail-closed admission cho archive hội thoại CRM.'; }
	public function severity(): string { return 'critical'; }
	public function order(): int { return 71; }
	public function icon(): string { return 'archive-restore'; }
	public function estimate_ms(): int { return 250; }
	public function precondition() { return true; }

	public function run( $ctx ): array {
		// [2026-09-02 Johnny Chu] PHASE-0.41-CRM-ONE-BRAIN — prove channel admission refuses unsafe inputs before archive/ledger mutation.
		$steps = array();
		$fixture = array();
		$root = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) ) . '/';
		$adapter_file = $root . 'core/context-bank/includes/class-context-bank-channel-archive-adapter.php';
		$archive_file = $root . 'core/channel-gateway/includes/class-channel-conversation-archive.php';
		$ledger_file = $root . 'core/context-bank/includes/class-context-bank-ledger.php';
		$disk_ok = is_readable( $adapter_file ) && is_readable( $archive_file ) && is_readable( $ledger_file );
		$steps[] = array(
			'label'  => 'Disk - channel archive adapter and pointer owners are readable',
			'status' => $disk_ok ? 'pass' : 'fail',
			'detail' => $disk_ok ? 'Archive, receipt and ledger artifacts are readable.' : 'A required CB4.2 artifact is missing.',
		);
		if ( ! $disk_ok ) {
			return array( 'status' => 'fail', 'summary' => 'Channel admission artifacts are incomplete.', 'fix_hint' => 'Restore the canonical archive, receipt and ledger artifacts, then rerun this probe.', 'steps' => $steps );
		}

		$loader_ok = class_exists( 'BizCity_Context_Bank_Channel_Archive_Adapter' )
			&& class_exists( 'BizCity_Channel_Conversation_Archive' )
			&& class_exists( 'BizCity_Context_Bank_Ledger' );
		$steps[] = array(
			'label'  => 'Loader - archive adapter, archive reader and ledger are loaded',
			'status' => $loader_ok ? 'pass' : 'fail',
			'detail' => $loader_ok ? 'CB4.2 dependencies are loaded through their owning bootstraps.' : 'CB4.2 dependency loading is incomplete.',
		);
		if ( ! $loader_ok ) {
			return array( 'status' => 'fail', 'summary' => 'Channel admission dependencies are not loaded.', 'fix_hint' => 'Load the Context Bank archive adapter after the canonical archive owner and rerun this probe.', 'steps' => $steps );
		}

		$missing_flag = '__cb_channel_admission_flag_missing__';
		$previous_flag = get_option( self::FLAG, $missing_flag );
		try {
			delete_option( self::FLAG );
			$disabled = BizCity_Context_Bank_Channel_Archive_Adapter::project( array( 'entry' => array(), 'receipt' => array() ) );
			$disabled_ok = is_array( $disabled ) && empty( $disabled['projected'] ) && 'capture_disabled_or_shape_invalid' === (string) ( $disabled['reason'] ?? '' );
			$steps[] = array(
				'label'  => 'Runtime - capture feature flag is fail-safe by default',
				'status' => $disabled_ok ? 'pass' : 'fail',
				'detail' => $disabled_ok ? 'Disabled capture does not write archive or ledger state.' : 'Disabled capture returned an unexpected admission result.',
			);

			update_option( self::FLAG, true, false );
			$malformed = BizCity_Context_Bank_Channel_Archive_Adapter::project( array(
				'entry' => array(
					'channel' => 'facebook',
					'account_key' => 'not-an-hmac-account',
					'peer_key' => 'not-an-hmac-peer',
					'conversation_id' => 0,
				),
				'receipt' => array(
					'record_id' => '',
					'event_uuid' => '',
				),
			) );
			$malformed_ok = is_array( $malformed ) && empty( $malformed['projected'] ) && 'channel_archive_identity_missing' === (string) ( $malformed['reason'] ?? '' );
			$steps[] = array(
				'label'  => 'Runtime - missing account/peer/event identity is rejected before ledger admission',
				'status' => $malformed_ok ? 'pass' : 'fail',
				'detail' => $malformed_ok ? 'Malformed archive identity cannot create a Context Bank pointer.' : 'Malformed archive identity was not rejected with the stable reason bucket.',
			);

			$unregistered = BizCity_Context_Bank_Channel_Archive_Adapter::project( array(
				'entry' => array(
					'channel' => 'unknown_channel',
					'account_key' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
					'peer_key' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
					'conversation_id' => 1,
				),
				'receipt' => array(
					'record_id' => 'cb_admission_probe_record',
					'event_uuid' => 'cb-admission-probe-event',
				),
			) );
			$unregistered_ok = is_array( $unregistered ) && empty( $unregistered['projected'] ) && 'channel_zone_not_supported' === (string) ( $unregistered['reason'] ?? '' );
			$steps[] = array(
				'label'  => 'Runtime - unknown channel is rejected before archive reader/ledger work',
				'status' => $unregistered_ok ? 'pass' : 'fail',
				'detail' => $unregistered_ok ? 'Unknown channel cannot enter the Context Bank corpus path.' : 'Unknown channel was not rejected at the admission boundary.',
			);

			// [2026-09-02 Johnny Chu] PHASE-0.41-CRM-ONE-BRAIN — exercise the disposable archive receipt -> ledger -> follow -> tombstone chain without creating a CRM message.
			$archive_reflection = new ReflectionClass( 'BizCity_Channel_Conversation_Archive' );
			$key_method = $archive_reflection->getMethod( 'archive_key' );
			$key_method->setAccessible( true );
			$hash_method = $archive_reflection->getMethod( 'hash_identifier' );
			$hash_method->setAccessible( true );
			$append_method = $archive_reflection->getMethod( 'append_with_receipt' );
			$append_method->setAccessible( true );
			$archive_key = (string) $key_method->invoke( null );
			$account_id = 'cb_admission_account_' . (int) get_current_blog_id();
			$peer_uid = 'cb_admission_peer_' . wp_rand( 1000, 9999 );
			$conversation_id = 910000000 + wp_rand( 1000, 9999 );
			$record_id = 'cb_admission_' . strtolower( str_replace( '-', '', wp_generate_uuid4() ) );
			$event_uuid = wp_generate_uuid4();
			$fixture_entry = array(
				'schema_version' => 1,
				'event_type' => 'message',
				'event_uuid' => $event_uuid,
				'trace_id' => 'trace_' . $record_id,
				'blog_id' => (int) get_current_blog_id(),
				'channel' => 'zalo_personal',
				'platform' => 'ZALO_PERSONAL',
				'account_key' => 'a_' . $hash_method->invoke( null, $account_id, $archive_key ),
				'peer_key' => 'p_' . $hash_method->invoke( null, $peer_uid, $archive_key ),
				'conversation_id' => $conversation_id,
				'inbox_id' => 910000001,
				'crm_message_id' => 910000002,
				'provider_message_id_hash' => $hash_method->invoke( null, 'cb-admission-message', $archive_key ),
				'direction' => 'inbound',
				'actor_type' => 'customer',
				'actor_user_id' => 0,
				'content_ciphertext' => 'diagnostics-ciphertext-only',
				'attachment_refs' => array(),
				'delivery_status' => 'received',
				'occurred_at' => gmdate( 'Y-m-d H:i:s' ),
				'record_id' => $record_id,
			);
			if ( $archive_key === '' ) {
				$steps[] = array( 'label' => 'Runtime - disposable archive receipt chain', 'status' => 'skip', 'detail' => 'Archive encryption key is unavailable on this tenant; no fixture was written.' );
				return array( 'status' => 'skip', 'summary' => 'Channel admission fixture deferred because the archive key is unavailable.', 'fix_hint' => 'Configure the canonical channel archive key, then rerun core.context_bank.channel_admission.', 'steps' => $steps );
			}
			$fixture['channel'] = 'zalo_personal';
			$fixture['account_id'] = $account_id;
			$fixture['peer_uid'] = $peer_uid;
			$fixture['conversation_id'] = $conversation_id;
			$fixture['record_id'] = $record_id;
			$fixture['entry'] = $fixture_entry;
			$receipt = $append_method->invoke( null, $fixture_entry, 'zalo_personal', $account_id, $peer_uid );
			$receipt_ok = is_array( $receipt ) && (string) ( $receipt['record_id'] ?? '' ) === $record_id && (int) ( $receipt['blog_id'] ?? 0 ) === (int) get_current_blog_id();
			$steps[] = array( 'label' => 'Runtime - disposable archive receipt captured', 'status' => $receipt_ok ? 'pass' : 'fail', 'detail' => $receipt_ok ? 'Archive append returned a lock-captured receipt for the current tenant.' : 'Archive append did not return a valid receipt.' );
			if ( ! $receipt_ok ) {
				return array( 'status' => 'fail', 'summary' => 'Disposable archive receipt was not captured.', 'fix_hint' => 'Inspect the canonical archive append receipt path and current tenant upload/key configuration.', 'steps' => $steps );
			}
			$admission = BizCity_Context_Bank_Channel_Archive_Adapter::project( array( 'entry' => $fixture_entry, 'receipt' => $receipt ) );
			$admission_ok = is_array( $admission ) && ! empty( $admission['ok'] ) && ! empty( $admission['projected'] );
			$steps[] = array( 'label' => 'Runtime - archive receipt admitted to Context Bank ledger', 'status' => $admission_ok ? 'pass' : 'fail', 'detail' => $admission_ok ? 'Only archive pointer/correlation metadata was admitted; ciphertext was not copied.' : 'Archive receipt was not admitted: ' . (string) ( $admission['reason'] ?? 'unknown' ) );
			$follow = $admission_ok ? BizCity_Context_Bank_Ledger::instance()->follow( $record_id, array( 'source_contract_id' => BizCity_Context_Bank_Channel_Archive_Adapter::CONTRACT_ID, 'blog_id' => (int) get_current_blog_id() ) ) : array();
			$follow_ok = is_array( $follow ) && ! empty( $follow['ok'] ) && ! empty( $follow['verified'] );
			$follow_detail = $follow_ok ? 'Archive line, tenant, event and record identity verified through the bounded reader.' : 'Archive pointer follow failed: ' . (string) ( $follow['reason'] ?? 'unknown' );
			if ( ! $follow_ok ) {
				$pointer_rows = BizCity_Context_Bank_Ledger::instance()->find( array( 'source_contract_id' => BizCity_Context_Bank_Channel_Archive_Adapter::CONTRACT_ID, 'record_id' => $record_id, 'blog_id' => (int) get_current_blog_id(), 'limit' => 1 ) );
				$pointer = is_array( $pointer_rows ) && isset( $pointer_rows[0] ) && is_array( $pointer_rows[0] ) ? $pointer_rows[0] : array();
				$pointer_verify = ! empty( $pointer ) ? BizCity_Context_Bank_Ledger::instance()->verify_pointer( $pointer ) : array( 'reason' => 'pointer_not_found' );
				$follow_detail .= ' Persisted pointer verification: ' . (string) ( $pointer_verify['reason'] ?? ( ! empty( $pointer_verify['ok'] ) ? 'ok' : 'unknown' ) ) . '.';
				if ( ! empty( $pointer ) ) {
					$follow_detail .= sprintf( ' Shape conditions: contract=%s, blog=%s, offset=%s, path=%s, row_hash=%s.', ( (string) ( $pointer['source_contract_id'] ?? '' ) === BizCity_Context_Bank_Channel_Archive_Adapter::CONTRACT_ID ) ? 'yes' : 'no', ( (int) ( $pointer['blog_id'] ?? 0 ) === (int) get_current_blog_id() ) ? 'yes' : 'no', ( (int) ( $pointer['byte_offset'] ?? -1 ) >= 0 ) ? 'yes' : 'no', preg_match( '#^(facebook|messenger|zalo_oa|zalo_personal|webchat|email|instagram|whatsapp)/a_[a-f0-9]{64}/p_[a-f0-9]{64}/\d{4}-\d{2}\.jsonl$#i', (string) ( $pointer['relative_file'] ?? '' ) ) ? 'yes' : 'no', preg_match( '/^[a-f0-9]{64}$/i', (string) ( $pointer['row_hash'] ?? '' ) ) ? 'yes' : 'no' );
			}
			if ( ! $follow_ok && ! empty( $pointer ) ) {
				$follow_detail .= sprintf( ' Stored receipt shape: relative_file=%d bytes, byte_offset=%d, row_hash_hex=%s, content_hash_hex=%s.', strlen( (string) ( $pointer['relative_file'] ?? '' ) ), (int) ( $pointer['byte_offset'] ?? -1 ), preg_match( '/^[a-f0-9]{64}$/i', (string) ( $pointer['row_hash'] ?? '' ) ) ? 'yes' : 'no', preg_match( '/^[a-f0-9]{64}$/i', (string) ( $pointer['content_hash'] ?? '' ) ) ? 'yes' : 'no' );
			}
			}
			$steps[] = array( 'label' => 'Runtime - verified archive pointer follow', 'status' => $follow_ok ? 'pass' : 'fail', 'detail' => $follow_detail );

			$tombstone_entry = $fixture_entry;
			$tombstone_entry['event_type'] = 'delete';
			$tombstone_entry['event_uuid'] = wp_generate_uuid4();
			$tombstone_entry['operation'] = 'delete';
			$tombstone_entry['content_ciphertext'] = '';
			$tombstone_receipt = $append_method->invoke( null, $tombstone_entry, 'zalo_personal', $account_id, $peer_uid );
			$tombstone = is_array( $tombstone_receipt ) ? BizCity_Context_Bank_Channel_Archive_Adapter::project( array( 'entry' => $tombstone_entry, 'receipt' => $tombstone_receipt ) ) : array();
			$tombstone_ok = is_array( $tombstone ) && ! empty( $tombstone['ok'] ) && ! empty( $tombstone['tombstone'] );
			$steps[] = array( 'label' => 'Runtime - receipt-bearing archive tombstone', 'status' => $tombstone_ok ? 'pass' : 'fail', 'detail' => $tombstone_ok ? 'Derived pointer tombstone was admitted without deleting the canonical archive owner.' : 'Archive tombstone was not admitted.' );
			$fixture['tombstone_receipt'] = $tombstone_receipt;
			$cleanup = is_array( $tombstone_receipt ) && method_exists( 'BizCity_Context_Bank_Ledger', 'instance' )
				? BizCity_Context_Bank_Ledger::instance()->remove_tombstoned_pointer( array_merge( $tombstone_entry, $tombstone_receipt, array( 'source_contract_id' => BizCity_Context_Bank_Channel_Archive_Adapter::CONTRACT_ID, 'record_id' => $record_id, 'operation' => 'delete', 'lifecycle_status' => 'deleted' ) ), 'diagnostics_fixture_cleanup' )
				: array( 'ok' => false );
			$cleanup_ok = is_array( $cleanup ) && ! empty( $cleanup['ok'] );
			$steps[] = array( 'label' => 'Runtime - disposable pointer cleanup', 'status' => $cleanup_ok ? 'pass' : 'fail', 'detail' => $cleanup_ok ? 'Only the derived tombstoned pointer was removed; canonical archive state was retained.' : 'Derived pointer cleanup was not completed.' );
			$chain_ok = $admission_ok && $follow_ok && $tombstone_ok && $cleanup_ok;
			$steps[] = array( 'label' => 'Runtime - archive receipt continuity chain', 'status' => $chain_ok ? 'pass' : 'fail', 'detail' => $chain_ok ? 'archive receipt -> Context Bank pointer -> verified follow -> tombstone completed without CRM/provider side effects.' : 'The disposable archive continuity chain is incomplete.' );
		} finally {
			if ( ! empty( $fixture['conversation_id'] ) && method_exists( 'BizCity_Channel_Conversation_Archive', 'erase_conversation' ) ) {
				// [2026-09-02 Johnny Chu] PHASE-0.41-CRM-ONE-BRAIN — remove only the synthetic archive partition row through the canonical authorized archive owner.
				BizCity_Channel_Conversation_Archive::erase_conversation( $fixture['channel'], $fixture['account_id'], $fixture['peer_uid'], (int) $fixture['conversation_id'], array( 'BizCity_Channel_Conversation_Archive', 'rest_authorize_tenant_admin' ) );
			}
			if ( $previous_flag === $missing_flag ) {
				delete_option( self::FLAG );
			} else {
				update_option( self::FLAG, $previous_flag, false );
			}
		}

		$pass = $disabled_ok && $malformed_ok && $unregistered_ok && $chain_ok;
		return array(
			'status'  => $pass ? 'pass' : 'fail',
			'summary' => $pass ? 'Channel admission fail-closed boundary passed.' : 'Channel admission boundary has validation failures.',
			'fix_hint'=> $pass ? '' : 'Keep capture disabled by default and reject unsupported channels or incomplete HMAC/event/conversation identity before ledger admission.',
			'steps'   => $steps,
		);
	}

	public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $probes ) {
	$probes[] = 'BizCity_Probe_Context_Bank_Channel_Admission';
	return $probes;
} );
