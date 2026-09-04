<?php
/**
 * DDV probe for normalized CRM message to Context Bank continuity.
 *
 * The fixture uses the canonical Facebook CRM adapter and ingestor, then
 * removes its test inbox and archive partition after pointer cleanup.
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
if ( class_exists( 'BizCity_Probe_Context_Bank_Channel_CRM_Continuity', false ) ) {
	return;
}

final class BizCity_Probe_Context_Bank_Channel_CRM_Continuity implements BizCity_Diagnostics_Probe {

	const FLAG = 'bizcity_context_bank_channel_capture_enabled';
	const CONTRACT_ID = 'core.channel_gateway.context_corpus';

	public function id(): string { return 'core.context_bank.channel_crm_continuity'; }
	public function label(): string { return 'Context Bank CRM message continuity'; }
	public function description(): string { return 'Kiem tra normalized CRM message di qua archive owner vao Context Bank pointer, follow va tombstone voi cleanup fixture.'; }
	public function severity(): string { return 'critical'; }
	public function order(): int { return 72; }
	public function icon(): string { return 'message-square-check'; }
	public function estimate_ms(): int { return 1200; }
	public function precondition() { return true; }

	public function run( $ctx ): array {
		// [2026-09-02 Johnny Chu] PHASE-0.41-CRM-ONE-BRAIN - prove normalized CRM message continuity through the canonical archive and Context Bank owners.
		$steps = array();
		$fixture = array();
		$previous_flag = '__cb_channel_continuity_flag_missing__';
		$pointer_removed = false;
		$chain_ok = false;
		$root = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) ) . '/';
		$files = array(
			'archive'  => $root . 'core/channel-gateway/includes/class-channel-conversation-archive.php',
			'adapter'  => $root . 'plugins/bizcity-twin-crm/includes/inbox/adapters/class-adapter-facebook.php',
			'ingestor' => $root . 'plugins/bizcity-twin-crm/includes/inbox/class-fb-ingestor.php',
			'repo'     => $root . 'plugins/bizcity-twin-crm/includes/class-repository.php',
			'ledger'   => $root . 'core/context-bank/includes/class-context-bank-ledger.php',
		);
		$disk_ok = true;
		foreach ( $files as $file ) {
			if ( ! is_readable( $file ) ) {
				$disk_ok = false;
				break;
			}
		}
		$steps[] = array(
			'label'  => 'Disk - CRM normalizer, ingestor, archive and ledger owners are readable',
			'status' => $disk_ok ? 'pass' : 'fail',
			'detail' => $disk_ok ? 'Canonical CRM continuity artifacts are readable.' : 'A canonical CRM continuity artifact is missing.',
		);
		if ( ! $disk_ok ) {
			return array( 'status' => 'fail', 'summary' => 'CRM continuity artifacts are incomplete.', 'fix_hint' => 'Restore the canonical CRM adapter, ingestor, archive and ledger artifacts, then rerun this probe.', 'steps' => $steps );
		}

		$loader_ok = class_exists( 'BizCity_CRM_Channel_Registry' )
			&& class_exists( 'BizCity_CRM_Facebook_Ingestor' )
			&& class_exists( 'BizCity_CRM_Repository' )
			&& class_exists( 'BizCity_Channel_Conversation_Archive' )
			&& class_exists( 'BizCity_Context_Bank_Channel_Archive_Adapter' )
			&& class_exists( 'BizCity_Context_Bank_Ledger' );
		$steps[] = array(
			'label'  => 'Loader - CRM normalized ingest and Context Bank owners are loaded',
			'status' => $loader_ok ? 'pass' : 'fail',
			'detail' => $loader_ok ? 'CRM repository/event, archive adapter and pointer ledger are loaded.' : 'A required CRM or Context Bank owner is not loaded.',
		);
		if ( ! $loader_ok ) {
			return array( 'status' => 'fail', 'summary' => 'CRM continuity owners are not loaded.', 'fix_hint' => 'Load the CRM channel registry, Facebook ingestor, archive adapter and Context Bank ledger through their canonical bootstraps.', 'steps' => $steps );
		}

		$previous_flag = get_option( self::FLAG, $previous_flag );
		$archive_listener = null;
		try {
			update_option( self::FLAG, true, false );
			$adapter = BizCity_CRM_Channel_Registry::get( 'facebook' );
			$adapter_ok = is_object( $adapter ) && method_exists( $adapter, 'normalize_inbound' ) && method_exists( $adapter, 'code' );
			$steps[] = array( 'label' => 'Runtime - canonical Facebook CRM adapter is registered', 'status' => $adapter_ok ? 'pass' : 'fail', 'detail' => $adapter_ok ? 'The fixture uses the registered facebook adapter, not a direct repository write.' : 'The facebook adapter is unavailable.' );
			if ( ! $adapter_ok ) {
				return array( 'status' => 'fail', 'summary' => 'Facebook CRM adapter is unavailable.', 'fix_hint' => 'Register the canonical facebook CRM adapter before enabling CRM Context Bank continuity.', 'steps' => $steps );
			}

			$page_id = 'diag_page_cb42_' . (int) get_current_blog_id() . '_' . wp_rand( 1000, 9999 );
			$psid = 'diag_psid_cb42_' . wp_rand( 1000, 9999 );
			$comment_id = 'diag.cb42.' . strtolower( str_replace( '-', '', wp_generate_uuid4() ) );
			$fixture['account_id'] = 'fb_feed_' . $page_id;
			$fixture['peer_uid'] = $psid;
			$raw = array(
				'page_id' => $page_id,
				'user_id' => $psid,
				'user_name' => 'Diagnostics Fixture',
				'message' => 'Context Bank CRM continuity fixture',
				'comment_id' => $comment_id,
				'post_id' => 'diag.cb42.post',
				'timestamp' => (int) round( microtime( true ) * 1000 ),
				'platform' => 'FB_FEED',
			);
			$norm = $adapter->normalize_inbound( $raw );
			$normalized_ok = is_array( $norm ) && (string) ( $norm['inbox_ref'] ?? '' ) === $fixture['account_id'] && (string) ( $norm['external_source_id'] ?? '' ) === 'fbcmt:' . $comment_id;
			$steps[] = array( 'label' => 'Runtime - Facebook payload normalizes to the CRM contract', 'status' => $normalized_ok ? 'pass' : 'fail', 'detail' => $normalized_ok ? 'The fixture has stable inbox, source and external event identity before ingest.' : 'Facebook payload normalization did not produce the expected CRM identity contract.' );
			if ( ! $normalized_ok ) {
				return array( 'status' => 'fail', 'summary' => 'Normalized CRM fixture is invalid.', 'fix_hint' => 'Keep the fixture on the canonical Facebook adapter and restore inbox/source/external identity normalization.', 'steps' => $steps );
			}

			$fixture['inbox_id'] = BizCity_CRM_Repository::upsert_inbox( 'facebook', $fixture['account_id'], array( 'name' => 'Diagnostics __diag CB42 ' . $page_id ) );
			if ( ! $fixture['inbox_id'] ) {
				$steps[] = array( 'label' => 'Runtime - disposable CRM test inbox is available', 'status' => 'fail', 'detail' => 'The canonical CRM repository could not create the marked diagnostic inbox.' );
				return array( 'status' => 'fail', 'summary' => 'Disposable CRM test inbox is unavailable.', 'fix_hint' => 'Provision the CRM inbox schema and rerun the normalized continuity probe.', 'steps' => $steps );
			}
			$steps[] = array( 'label' => 'Runtime - disposable CRM test inbox is available', 'status' => 'pass', 'detail' => 'The fixture uses a marked __diag inbox eligible for transactional cleanup.' );

			$archive_event = array();
			$archive_listener = function ( $payload ) use ( &$archive_event ) {
				if ( is_array( $payload ) && empty( $archive_event ) ) {
					$archive_event = $payload;
				}
			};
			add_action( 'bizcity_channel_archive_written', $archive_listener, 99, 1 );
			$message_id = BizCity_CRM_Facebook_Ingestor::instance()->ingest( $adapter, $norm );
			$fixture['message_id'] = (int) $message_id;
			$message_ok = $message_id > 0;
			$steps[] = array( 'label' => 'Runtime - normalized CRM message persisted through the ingestor', 'status' => $message_ok ? 'pass' : 'fail', 'detail' => $message_ok ? 'The canonical ingestor created one disposable CRM message and emitted its lifecycle event.' : 'The canonical ingestor refused or failed to persist the fixture.' );
			if ( ! $message_ok ) {
				return array( 'status' => 'fail', 'summary' => 'Normalized CRM message was not persisted.', 'fix_hint' => 'Inspect the CRM channel contract, test inbox provisioning and repository write gate before enabling Context Bank capture.', 'steps' => $steps );
			}

			$archive_ok = is_array( $archive_event['entry'] ?? null ) && is_array( $archive_event['receipt'] ?? null );
			$entry = $archive_ok ? $archive_event['entry'] : array();
			$receipt = $archive_ok ? $archive_event['receipt'] : array();
			$fixture['conversation_id'] = (int) ( $entry['conversation_id'] ?? 0 );
			$fixture['record_id'] = (string) ( $receipt['record_id'] ?? '' );
			$steps[] = array( 'label' => 'Runtime - CRM event reached the encrypted archive owner', 'status' => $archive_ok ? 'pass' : 'fail', 'detail' => $archive_ok ? 'Archive owner emitted a lock-captured receipt without exposing message content.' : 'No archive receipt was emitted for the persisted CRM event.' );
			if ( ! $archive_ok ) {
				return array( 'status' => 'fail', 'summary' => 'CRM message did not reach the archive owner.', 'fix_hint' => 'Restore the crm_message_received archive hook and keep archive write ownership in BizCity_Channel_Conversation_Archive.', 'steps' => $steps );
			}

			$pointer_rows = BizCity_Context_Bank_Ledger::instance()->find( array( 'source_contract_id' => self::CONTRACT_ID, 'record_id' => $fixture['record_id'], 'blog_id' => (int) get_current_blog_id(), 'limit' => 1 ) );
			$pointer = is_array( $pointer_rows ) && isset( $pointer_rows[0] ) && is_array( $pointer_rows[0] ) ? $pointer_rows[0] : array();
			$payload_fields = array_intersect( array( 'content', 'body', 'content_ciphertext', 'payload', 'plaintext' ), array_keys( $pointer ) );
			$pointer_ok = ! empty( $pointer ) && empty( $payload_fields ) && (int) ( $pointer['blog_id'] ?? 0 ) === (int) get_current_blog_id();
			$steps[] = array( 'label' => 'Runtime - CRM archive receipt projected as pointer-only Context Bank metadata', 'status' => $pointer_ok ? 'pass' : 'fail', 'detail' => $pointer_ok ? 'The ledger contains tenant/correlation/receipt metadata and no message payload fields.' : 'The CRM archive pointer is missing, foreign-tenant scoped or contains forbidden payload fields.' );
			if ( ! $pointer_ok ) {
				return array( 'status' => 'fail', 'summary' => 'CRM archive pointer admission failed.', 'fix_hint' => 'Keep Context Bank pointer-only and ensure the archive adapter projects the verified receipt through the current tenant ledger.', 'steps' => $steps );
			}

			$follow = BizCity_Context_Bank_Ledger::instance()->follow( $fixture['record_id'], array( 'source_contract_id' => self::CONTRACT_ID, 'blog_id' => (int) get_current_blog_id() ) );
			$follow_ok = is_array( $follow ) && ! empty( $follow['ok'] ) && ! empty( $follow['verified'] );
			$steps[] = array( 'label' => 'Runtime - CRM archive pointer follows through the verified reader', 'status' => $follow_ok ? 'pass' : 'fail', 'detail' => $follow_ok ? 'Archive line, tenant, event and record identity verified through the bounded reader.' : 'CRM archive pointer follow failed: ' . (string) ( $follow['reason'] ?? 'unknown' ) );
			if ( ! $follow_ok ) {
				return array( 'status' => 'fail', 'summary' => 'CRM archive pointer follow failed.', 'fix_hint' => 'Verify the archive receipt contract mapping and current tenant route before enabling capture.', 'steps' => $steps );
			}

			$reflection = new ReflectionClass( 'BizCity_Channel_Conversation_Archive' );
			$append_method = $reflection->getMethod( 'append_with_receipt' );
			$append_method->setAccessible( true );
			$tombstone_entry = $entry;
			$tombstone_entry['event_type'] = 'delete';
			$tombstone_entry['event_uuid'] = wp_generate_uuid4();
			$tombstone_entry['operation'] = 'delete';
			$tombstone_entry['content_ciphertext'] = '';
			$tombstone_receipt = $append_method->invoke( null, $tombstone_entry, 'facebook', $fixture['account_id'], $fixture['peer_uid'] );
			$tombstone = is_array( $tombstone_receipt ) ? BizCity_Context_Bank_Channel_Archive_Adapter::project( array( 'entry' => $tombstone_entry, 'receipt' => $tombstone_receipt ) ) : array();
			$tombstone_ok = is_array( $tombstone ) && ! empty( $tombstone['ok'] ) && ! empty( $tombstone['tombstone'] );
			$steps[] = array( 'label' => 'Runtime - CRM archive deletion emits a Context Bank tombstone', 'status' => $tombstone_ok ? 'pass' : 'fail', 'detail' => $tombstone_ok ? 'The derived Context Bank pointer was tombstoned without changing the archive owner contract.' : 'CRM archive tombstone admission failed.' );
			if ( $tombstone_ok ) {
				$cleanup_pointer = array_merge( $tombstone_entry, $tombstone_receipt, array( 'source_contract_id' => self::CONTRACT_ID, 'record_id' => $fixture['record_id'], 'operation' => 'delete', 'lifecycle_status' => 'deleted' ) );
				$cleanup_result = BizCity_Context_Bank_Ledger::instance()->remove_tombstoned_pointer( $cleanup_pointer, 'diagnostics_fixture_cleanup' );
				$pointer_removed = is_array( $cleanup_result ) && ! empty( $cleanup_result['ok'] );
			}
			$steps[] = array( 'label' => 'Runtime - CRM continuity fixture removes only its derived pointer', 'status' => $pointer_removed ? 'pass' : 'fail', 'detail' => $pointer_removed ? 'Tombstoned Context Bank metadata was removed before fixture purge; canonical CRM/archive owners remained authoritative.' : 'Derived pointer cleanup did not complete.' );
			$chain_ok = $message_ok && $archive_ok && $pointer_ok && $follow_ok && $tombstone_ok && $pointer_removed;
			$steps[] = array( 'label' => 'Runtime - normalized CRM to Context Bank continuity', 'status' => $chain_ok ? 'pass' : 'fail', 'detail' => $chain_ok ? 'normalize -> ingest -> CRM event -> encrypted archive receipt -> pointer -> verified follow -> tombstone completed without provider transport.' : 'The normalized CRM continuity chain is incomplete.' );
		} catch ( \Throwable $e ) {
			$steps[] = array( 'label' => 'Runtime - normalized CRM to Context Bank continuity', 'status' => 'fail', 'detail' => 'Fixture execution failed: ' . sanitize_key( (string) $e->getMessage() ) );
		} finally {
			if ( $archive_listener !== null ) {
				remove_action( 'bizcity_channel_archive_written', $archive_listener, 99 );
			}
			if ( ! empty( $fixture['conversation_id'] ) && method_exists( 'BizCity_Channel_Conversation_Archive', 'erase_conversation' ) ) {
				// [2026-09-02 Johnny Chu] PHASE-0.41-CRM-ONE-BRAIN - purge only the synthetic archive partition through the canonical authorized archive owner.
				BizCity_Channel_Conversation_Archive::erase_conversation( 'facebook', $fixture['account_id'], $fixture['peer_uid'], (int) $fixture['conversation_id'], array( 'BizCity_Channel_Conversation_Archive', 'rest_authorize_tenant_admin' ) );
			}
			if ( ! empty( $fixture['inbox_id'] ) && method_exists( 'BizCity_CRM_Repository', 'delete_inbox' ) ) {
				BizCity_CRM_Repository::delete_inbox( (int) $fixture['inbox_id'] );
			}
			if ( $previous_flag === '__cb_channel_continuity_flag_missing__' ) {
				delete_option( self::FLAG );
			} else {
				update_option( self::FLAG, $previous_flag, false );
			}
		}

		$pass = $chain_ok;
		return array(
			'status'   => $pass ? 'pass' : 'fail',
			'summary'  => $pass ? 'Normalized CRM to Context Bank continuity passed.' : 'Normalized CRM to Context Bank continuity has validation failures.',
			'fix_hint' => $pass ? '' : 'Keep channel capture gated and repair the first failed normalized CRM, archive, pointer, follow or cleanup step before enabling production capture.',
			'steps'    => $steps,
		);
	}

	public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $probes ) {
	$probes[] = 'BizCity_Probe_Context_Bank_Channel_CRM_Continuity';
	return $probes;
} );