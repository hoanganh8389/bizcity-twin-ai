<?php
/**
 * D5 outbound provider-delivery probe for the canonical CRM dispatcher.
 *
 * PHASE-0.41D §5.3. Proves the outbound state machine end to end against a
 * diagnostics-owned mock adapter: idempotent replay, changed-payload conflict,
 * retryable release, monotonic callback ladder, attachment policy and
 * server-derived notification account.
 *
 * Boundary (PHASE-0.41D §5.4): this probe uses a MOCK provider. A PASS here is
 * never production delivery evidence; production delivery is a separate row
 * closed by the D7 canary.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since 2026-09-16 (PHASE-0.41D-CLOSURE / D5)
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

if ( class_exists( 'BizCity_Probe_TwinWeb_CRM_Outbound_Delivery', false ) ) {
	return;
}

final class BizCity_Probe_TwinWeb_CRM_Outbound_Delivery implements BizCity_Diagnostics_Probe {

	/** @var string */
	private $cleanup_token = '';

	/** @var int */
	private $original_user_id = 0;

	/** @var array<int> Attachment ids created by this probe. */
	private $attachment_ids = array();

	/** @var string Last media fixture failure reason, kept for the step detail. */
	private $attachment_error = '';

	/** @var array<int,array> Mutation claims to release during cleanup. */
	private $claims = array();

	/** @var callable|null */
	private $adapter_filter = null;

	/** @var callable|null */
	private $max_bytes_filter = null;

	public function id(): string { return 'modules.twin_gpt.crm_outbound_delivery'; }
	public function label(): string { return 'CRM outbound delivery state machine (mock provider)'; }
	public function description(): string { return 'Kiem tra idempotency, replay, conflict, retryable release, callback ladder, attachment policy va notify account cua outbound dispatcher.'; }
	public function severity(): string { return 'critical'; }
	public function order(): int { return 80; }
	public function icon(): string { return 'send'; }
	public function estimate_ms(): int { return 1600; }

	public function precondition() {
		// [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-0.41D-D5 — the dispatcher, fixture factory and mock adapter are all part of the contract; a missing owner is a failure, not a skip.
		if ( ! class_exists( 'BizCity_CRM_Inbox_Fixture_Factory' ) ) {
			return 'CRM Inbox fixture factory is not loaded (core/diagnostics/includes/fixtures).';
		}
		if ( ! class_exists( 'BizCity_CRM_Outbound_Dispatcher' ) ) {
			return 'CRM outbound dispatcher is not loaded (plugins/bizcity-twin-crm/includes/inbox).';
		}
		if ( ! $this->ensure_mock_adapter() ) {
			return 'CRM adapter base is not loaded, so the mock provider cannot be registered.';
		}
		return true;
	}

	/**
	 * Load the diagnostics mock adapter lazily.
	 *
	 * The CRM adapter base belongs to the bundled CRM plugin and is not
	 * guaranteed to exist when the probe graph is first required, so the mock
	 * class is declared in its own fixture file at run time instead.
	 *
	 * @return bool
	 */
	private function ensure_mock_adapter(): bool {
		// [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-0.41D-D5 — declare the mock only after the CRM adapter base exists.
		if ( class_exists( 'BizCity_Probe_Outbound_Mock_Adapter', false ) ) {
			return true;
		}
		if ( ! class_exists( 'BizCity_CRM_Adapter_Base' ) ) {
			return false;
		}
		$fixture = dirname( __DIR__ ) . '/fixtures/class-outbound-mock-adapter.php';
		if ( is_file( $fixture ) && is_readable( $fixture ) ) {
			BizCity_Safe_Loader::require_file( $fixture, 'diagnostics.fixture.outbound_mock_adapter' );
		}
		return class_exists( 'BizCity_Probe_Outbound_Mock_Adapter', false );
	}

	public function run( $ctx ): array {
		// [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-0.41D-D5 — one disposable conversation drives the whole outbound ladder with a mock provider.
		$this->original_user_id = (int) get_current_user_id();
		$steps = array();
		$root  = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) ) . '/';

		// Step 1 — Disk.
		$dispatcher_path = $root . 'plugins/bizcity-twin-crm/includes/inbox/class-outbound-dispatcher.php';
		$repo_path       = $root . 'plugins/bizcity-twin-crm/includes/class-repository.php';
		$store_path      = $root . 'core/twin-core/includes/class-twin-mutation-store.php';
		$disk_ok = is_readable( $dispatcher_path ) && is_readable( $repo_path ) && is_readable( $store_path );
		$this->emit( $ctx, $steps, 'Disk - dispatcher, repository and mutation store are readable', $disk_ok, $disk_ok ? 'Outbound owner artifacts are present.' : 'An outbound owner artifact is missing.' );
		if ( ! $disk_ok ) {
			return $this->fail( $steps, 'Outbound delivery artifacts are missing on disk.', 'crm_outbound_artifact_missing', 'Restore the CRM outbound dispatcher, repository and mutation store.' );
		}

		// Step 2 — Loader.
		$loader_ok = method_exists( 'BizCity_CRM_Outbound_Dispatcher', 'dispatch' )
			&& method_exists( 'BizCity_CRM_Outbound_Dispatcher', 'confirm' )
			&& method_exists( 'BizCity_CRM_Repository', 'set_message_external_source_id' )
			&& method_exists( 'BizCity_CRM_Repository', 'update_message_delivery' )
			&& class_exists( 'BizCity_Twin_Mutation_Store' )
			&& class_exists( 'BizCity_CRM_Channel_Registry' );
		$this->emit( $ctx, $steps, 'Loader - dispatch/confirm, write-once provider id and idempotency owner are loaded', $loader_ok, $loader_ok ? 'Dispatcher, repository writers and mutation store are available.' : 'An outbound dependency is not loaded.' );
		if ( ! $loader_ok ) {
			return $this->fail( $steps, 'Outbound delivery dependencies are incomplete.', 'crm_outbound_loader_incomplete', 'Load the CRM outbound dispatcher and its canonical owners before running this probe.' );
		}

		// Step 3 — Fixture. No fixture is a FAIL, never a SKIP.
		$fixture = BizCity_CRM_Inbox_Fixture_Factory::build( array(
			'channel'           => 'facebook',
			'users'             => 2,
			'accounts_per_user' => 2,
			'with_conversation' => true,
			'with_messages'     => 1,
		) );
		if ( empty( $fixture['ok'] ) ) {
			$this->emit( $ctx, $steps, 'Runtime - disposable outbound fixture', false, sprintf( 'Fixture build failed: %s %s', (string) ( $fixture['reason'] ?? 'unknown' ), (string) ( $fixture['detail'] ?? '' ) ) );
			return $this->fail( $steps, 'Không dựng được conversation fixture cho outbound.', 'crm_outbound_fixture_unavailable', 'Kiểm tra CRM repository/schema trên blog mục tiêu rồi rerun probe.' );
		}
		$this->cleanup_token = (string) $fixture['cleanup_token'];
		$expect = BizCity_CRM_Inbox_Fixture_Factory::expectations( $this->cleanup_token );
		// [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-0.41D-D5 — the factory returns {index,user_id} rows, not bare ids.
		$users  = isset( $expect['users'] ) && is_array( $expect['users'] ) ? array_values( $expect['users'] ) : array();
		$user_a = isset( $users[0]['user_id'] ) ? (int) $users[0]['user_id'] : 0;
		$user_b = isset( $users[1]['user_id'] ) ? (int) $users[1]['user_id'] : 0;
		$target = array();
		$other  = array();
		foreach ( (array) ( $expect['business_inboxes'] ?? array() ) as $row ) {
			if ( (int) $row['member_user_id'] !== $user_a ) {
				continue;
			}
			if ( empty( $target ) && (int) $row['conversation_id'] > 0 ) {
				$target = $row;
				continue;
			}
			if ( empty( $other ) ) {
				$other = $row;
			}
		}
		if ( empty( $target ) || $user_a <= 0 ) {
			$this->emit( $ctx, $steps, 'Runtime - fixture expectations', false, 'Fixture has no owned conversation for the first user.' );
			return $this->fail( $steps, 'Fixture không có conversation hợp lệ.', 'crm_outbound_fixture_expectation_missing', 'Kiểm tra fixture factory envelope rồi rerun probe.' );
		}
		$conversation_id = (int) $target['conversation_id'];
		$this->emit( $ctx, $steps, 'Runtime - disposable outbound fixture', true, sprintf( 'Created conversation #%d in inbox #%d for the fixture member.', $conversation_id, (int) $target['inbox_id'] ) );

		// Register the mock provider through the canonical registry filter.
		if ( ! $this->ensure_mock_adapter() ) {
			$this->emit( $ctx, $steps, 'Runtime - diagnostics mock provider', false, 'CRM adapter base is not loaded, so no provider transport could be exercised.' );
			return $this->fail( $steps, 'Không nạp được mock provider cho outbound.', 'crm_outbound_mock_unavailable', 'Bảo đảm plugin CRM đã nạp adapter base trước khi chạy probe.' );
		}
		$this->install_mock_adapter();
		wp_set_current_user( $user_a );
		$baseline_messages = $this->message_count( $conversation_id );

		// Row 1 — first send with key K1.
		BizCity_Probe_Outbound_Mock_Adapter::$mode = 'success';
		$key1  = 'bzdiag_outbound_k1_' . md5( (string) $conversation_id . '|1' );
		$hash1 = md5( 'content-one|' . $conversation_id );
		$this->claims[] = array( 'key' => $key1, 'hash' => $hash1, 'conversation_id' => $conversation_id, 'user_id' => $user_a );
		$attempts_before = BizCity_Probe_Outbound_Mock_Adapter::$attempts;
		$first = BizCity_CRM_Outbound_Dispatcher::dispatch( array(
			'conversation_id' => $conversation_id,
			'content'         => 'Diagnostics outbound one',
			'content_type'    => 'text',
			'idempotency_key' => $key1,
			'request_hash'    => $hash1,
			'user_id'         => $user_a,
			// Posted account hints must be ignored by the dispatcher.
			'inbox_id'        => (int) ( $other['inbox_id'] ?? 0 ),
			'account_ref'     => 'posted-should-be-ignored',
		) );
		$first_ok = 'queued' === (string) ( $first['outcome'] ?? '' )
			&& (int) ( $first['message_id'] ?? 0 ) > 0
			&& ( BizCity_Probe_Outbound_Mock_Adapter::$attempts - $attempts_before ) === 1
			&& ( $this->message_count( $conversation_id ) - $baseline_messages ) === 1
			&& '' !== (string) ( $first['job_id'] ?? '' );
		$this->emit( $ctx, $steps, 'Row 1 - first send creates one CRM message and one provider attempt with outcome=queued', $first_ok, sprintf( 'outcome=%s message_id=%d attempts_delta=%d job_id=%s', (string) ( $first['outcome'] ?? '' ), (int) ( $first['message_id'] ?? 0 ), BizCity_Probe_Outbound_Mock_Adapter::$attempts - $attempts_before, '' !== (string) ( $first['job_id'] ?? '' ) ? 'present' : 'missing' ) );
		$message_id = (int) ( $first['message_id'] ?? 0 );

		// Row 2 — identical replay.
		$attempts_before = BizCity_Probe_Outbound_Mock_Adapter::$attempts;
		$messages_before = $this->message_count( $conversation_id );
		$replay = BizCity_CRM_Outbound_Dispatcher::dispatch( array(
			'conversation_id' => $conversation_id,
			'content'         => 'Diagnostics outbound one',
			'content_type'    => 'text',
			'idempotency_key' => $key1,
			'request_hash'    => $hash1,
			'user_id'         => $user_a,
		) );
		$replay_ok = ! empty( $replay['replayed'] )
			&& (int) ( $replay['message_id'] ?? 0 ) === $message_id
			&& BizCity_Probe_Outbound_Mock_Adapter::$attempts === $attempts_before
			&& $this->message_count( $conversation_id ) === $messages_before;
		$this->emit( $ctx, $steps, 'Row 2 - same key and payload replays with zero new message and zero provider attempt', $replay_ok, sprintf( 'replayed=%s attempts_delta=%d message_delta=%d', ! empty( $replay['replayed'] ) ? 'true' : 'false', BizCity_Probe_Outbound_Mock_Adapter::$attempts - $attempts_before, $this->message_count( $conversation_id ) - $messages_before ) );

		// Row 3 — same key, changed payload.
		$attempts_before = BizCity_Probe_Outbound_Mock_Adapter::$attempts;
		$messages_before = $this->message_count( $conversation_id );
		$conflict = BizCity_CRM_Outbound_Dispatcher::dispatch( array(
			'conversation_id' => $conversation_id,
			'content'         => 'Diagnostics outbound one CHANGED',
			'content_type'    => 'text',
			'idempotency_key' => $key1,
			'request_hash'    => md5( 'content-one-changed|' . $conversation_id ),
			'user_id'         => $user_a,
		) );
		$conflict_ok = 'conflict' === (string) ( $conflict['code'] ?? '' )
			&& 'failed' === (string) ( $conflict['outcome'] ?? '' )
			&& BizCity_Probe_Outbound_Mock_Adapter::$attempts === $attempts_before
			&& $this->message_count( $conversation_id ) === $messages_before;
		$this->emit( $ctx, $steps, 'Row 3 - same key with a changed payload is a conflict that never sends', $conflict_ok, sprintf( 'code=%s attempts_delta=%d message_delta=%d', (string) ( $conflict['code'] ?? '' ), BizCity_Probe_Outbound_Mock_Adapter::$attempts - $attempts_before, $this->message_count( $conversation_id ) - $messages_before ) );

		// Row 4 — retryable provider failure releases the claim and stays queued.
		BizCity_Probe_Outbound_Mock_Adapter::$mode = 'retryable';
		$key2  = 'bzdiag_outbound_k2_' . md5( (string) $conversation_id . '|2' );
		$hash2 = md5( 'content-two|' . $conversation_id );
		$this->claims[] = array( 'key' => $key2, 'hash' => $hash2, 'conversation_id' => $conversation_id, 'user_id' => $user_a );
		$retry_first = BizCity_CRM_Outbound_Dispatcher::dispatch( array(
			'conversation_id' => $conversation_id,
			'content'         => 'Diagnostics outbound retryable',
			'content_type'    => 'text',
			'idempotency_key' => $key2,
			'request_hash'    => $hash2,
			'user_id'         => $user_a,
		) );
		$attempts_before = BizCity_Probe_Outbound_Mock_Adapter::$attempts;
		BizCity_Probe_Outbound_Mock_Adapter::$mode = 'success';
		$retry_second = BizCity_CRM_Outbound_Dispatcher::dispatch( array(
			'conversation_id' => $conversation_id,
			'content'         => 'Diagnostics outbound retryable',
			'content_type'    => 'text',
			'idempotency_key' => $key2,
			'request_hash'    => $hash2,
			'user_id'         => $user_a,
		) );
		$retry_row = $this->message_row( (int) ( $retry_first['message_id'] ?? 0 ) );
		$retry_ok = 'queued' === (string) ( $retry_first['outcome'] ?? '' )
			&& ! empty( $retry_first['retryable'] )
			&& 'timeout' === (string) ( $retry_first['reason_bucket'] ?? '' )
			&& 'queued' === (string) ( $retry_row['status'] ?? '' )
			&& ( BizCity_Probe_Outbound_Mock_Adapter::$attempts - $attempts_before ) === 1
			&& 'queued' === (string) ( $retry_second['outcome'] ?? '' )
			&& empty( $retry_second['retryable'] );
		$this->emit( $ctx, $steps, 'Row 4 - retryable failure releases the claim, keeps status queued and never claims sent', $retry_ok, sprintf( 'first_outcome=%s retryable=%s bucket=%s row_status=%s retry_attempt_delta=%d', (string) ( $retry_first['outcome'] ?? '' ), ! empty( $retry_first['retryable'] ) ? 'true' : 'false', (string) ( $retry_first['reason_bucket'] ?? '' ), (string) ( $retry_row['status'] ?? '' ), BizCity_Probe_Outbound_Mock_Adapter::$attempts - $attempts_before ) );

		// Row 5 — callback sent.
		$messages_before = $this->message_count( $conversation_id );
		$sent_cb = BizCity_CRM_Outbound_Dispatcher::confirm( array(
			'message_id'         => $message_id,
			'outcome'            => 'sent',
			'external_source_id' => 'callback_should_not_override',
			'platform'           => 'facebook',
		) );
		$row_after_sent = $this->message_row( $message_id );
		$sent_ok = ! empty( $sent_cb['applied'] )
			&& 'sent' === (string) ( $sent_cb['outcome'] ?? '' )
			&& 'queued' === (string) ( $sent_cb['previous_outcome'] ?? '' )
			&& 'sent' === (string) ( $row_after_sent['status'] ?? '' )
			&& 'mock_ext_1' === (string) ( $row_after_sent['external_source_id'] ?? '' )
			&& $this->message_count( $conversation_id ) === $messages_before;
		$this->emit( $ctx, $steps, 'Row 5 - callback moves queued to sent, preserves the first provider id and creates no message', $sent_ok, sprintf( 'applied=%s outcome=%s status=%s external=%s', ! empty( $sent_cb['applied'] ) ? 'true' : 'false', (string) ( $sent_cb['outcome'] ?? '' ), (string) ( $row_after_sent['status'] ?? '' ), (string) ( $row_after_sent['external_source_id'] ?? '' ) ) );

		// Row 6 — callback delivered, then a regression attempt.
		$delivered_cb = BizCity_CRM_Outbound_Dispatcher::confirm( array( 'message_id' => $message_id, 'outcome' => 'delivered', 'platform' => 'facebook' ) );
		$regression_cb = BizCity_CRM_Outbound_Dispatcher::confirm( array( 'message_id' => $message_id, 'outcome' => 'sent', 'platform' => 'facebook' ) );
		$failed_regression = BizCity_CRM_Outbound_Dispatcher::confirm( array( 'message_id' => $message_id, 'outcome' => 'failed', 'platform' => 'facebook' ) );
		$delivered_ok = ! empty( $delivered_cb['applied'] )
			&& 'delivered' === (string) ( $delivered_cb['outcome'] ?? '' )
			&& empty( $regression_cb['applied'] )
			&& 'ignored_regression' === (string) ( $regression_cb['code'] ?? '' )
			&& empty( $failed_regression['applied'] )
			&& $this->message_count( $conversation_id ) === $messages_before;
		$this->emit( $ctx, $steps, 'Row 6 - delivered is monotonic; a late sent or failed callback is ignored without a new message', $delivered_ok, sprintf( 'delivered_applied=%s regression_code=%s failed_applied=%s', ! empty( $delivered_cb['applied'] ) ? 'true' : 'false', (string) ( $regression_cb['code'] ?? '' ), ! empty( $failed_regression['applied'] ) ? 'true' : 'false' ) );

		// Rows 7-9 — attachment policy.
		$attachment_steps = $this->run_attachment_rows( $ctx, $steps, $conversation_id, $user_a, $user_b );

		// Row 10 — notification account is server-derived.
		$notify = isset( $first['notify'] ) && is_array( $first['notify'] ) ? $first['notify'] : array();
		$notify_ok = (int) ( $notify['inbox_id'] ?? 0 ) === (int) $target['inbox_id']
			&& ( empty( $other ) || (int) ( $notify['inbox_id'] ?? 0 ) !== (int) $other['inbox_id'] )
			&& (string) ( $notify['ref'] ?? '' ) === (string) $target['ref'];
		$this->emit( $ctx, $steps, 'Row 10 - notification account is derived from the conversation inbox, not from the request', $notify_ok, sprintf( 'notify_inbox=%d expected=%d other_inbox=%d', (int) ( $notify['inbox_id'] ?? 0 ), (int) $target['inbox_id'], (int) ( $other['inbox_id'] ?? 0 ) ) );

		// Row 12 — a system actor without owner continuity is refused (R-TWEB-17).
		$attempts_before = BizCity_Probe_Outbound_Mock_Adapter::$attempts;
		$messages_before = $this->message_count( $conversation_id );
		$key_sys_deny  = 'bzdiag_outbound_sys_deny_' . md5( (string) $conversation_id );
		$hash_sys_deny = md5( 'system-no-owner|' . $conversation_id );
		$this->claims[] = array( 'key' => $key_sys_deny, 'hash' => $hash_sys_deny, 'conversation_id' => $conversation_id, 'user_id' => 0 );
		$anonymous_system = BizCity_CRM_Outbound_Dispatcher::dispatch( array(
			'conversation_id' => $conversation_id,
			'content'         => 'Diagnostics system send without owner',
			'content_type'    => 'text',
			'idempotency_key' => $key_sys_deny,
			'request_hash'    => $hash_sys_deny,
			'actor'           => 'system',
			'user_id'         => $user_a,
		) );
		// A system caller must not inherit scope from a foreign owner either.
		$key_sys_foreign  = 'bzdiag_outbound_sys_foreign_' . md5( (string) $conversation_id );
		$hash_sys_foreign = md5( 'system-foreign-owner|' . $conversation_id );
		$this->claims[] = array( 'key' => $key_sys_foreign, 'hash' => $hash_sys_foreign, 'conversation_id' => $conversation_id, 'user_id' => $user_b );
		$foreign_system = $user_b > 0 ? BizCity_CRM_Outbound_Dispatcher::dispatch( array(
			'conversation_id'      => $conversation_id,
			'content'              => 'Diagnostics system send for a foreign owner',
			'content_type'         => 'text',
			'idempotency_key'      => $key_sys_foreign,
			'request_hash'         => $hash_sys_foreign,
			'actor'                => 'system',
			'on_behalf_of_user_id' => $user_b,
		) ) : array( 'code' => 'permission_denied', 'outcome' => 'failed' );
		$system_deny_ok = 'system_owner_required' === (string) ( $anonymous_system['code'] ?? '' )
			&& 'failed' === (string) ( $anonymous_system['outcome'] ?? '' )
			&& 'permission_denied' === (string) ( $foreign_system['code'] ?? '' )
			&& BizCity_Probe_Outbound_Mock_Adapter::$attempts === $attempts_before
			&& $this->message_count( $conversation_id ) === $messages_before;
		$this->emit( $ctx, $steps, 'Row 12 - a system actor without owner, or with a foreign owner, is refused before the provider', $system_deny_ok, sprintf( 'anonymous_code=%s foreign_code=%s attempts_delta=%d message_delta=%d', (string) ( $anonymous_system['code'] ?? '' ), (string) ( $foreign_system['code'] ?? '' ), BizCity_Probe_Outbound_Mock_Adapter::$attempts - $attempts_before, $this->message_count( $conversation_id ) - $messages_before ) );
		// Row 14 — a registered inbound source with no human owner anchors on the inbox capability.
		$key_cap  = 'bzdiag_outbound_capability_' . md5( (string) $conversation_id );
		$hash_cap = md5( 'system-inbox-capability|' . $conversation_id );
		$this->claims[] = array( 'key' => $key_cap, 'hash' => $hash_cap, 'conversation_id' => $conversation_id, 'user_id' => 0 );
		$capability_payload = array(
			'conversation_id' => $conversation_id,
			'content'         => 'Diagnostics inbound-triggered bot reply',
			'content_type'    => 'text',
			'idempotency_key' => $key_cap,
			'request_hash'    => $hash_cap,
			'actor'           => 'system',
			'system_source'   => 'kg_reply',
		);
		$capability_attempts_before = BizCity_Probe_Outbound_Mock_Adapter::$attempts;
		$capability_first  = BizCity_CRM_Outbound_Dispatcher::dispatch( $capability_payload );
		$capability_media  = BizCity_CRM_Outbound_Dispatcher::dispatch( array_merge( $capability_payload, array(
			'idempotency_key' => $key_cap . '_media',
			'request_hash'    => md5( 'system-capability-media|' . $conversation_id ),
			'content_type'    => 'file',
			'attachments'     => array( 1 ),
		) ) );
		$this->claims[] = array( 'key' => $key_cap . '_media', 'hash' => md5( 'system-capability-media|' . $conversation_id ), 'conversation_id' => $conversation_id, 'user_id' => 0 );
		$capability_row = $this->message_row( (int) ( $capability_first['message_id'] ?? 0 ) );
		$capability_ok = 'queued' === (string) ( $capability_first['outcome'] ?? '' )
			&& 'inbox_capability' === (string) ( $capability_first['owner_source'] ?? '' )
			&& 0 === (int) ( $capability_first['on_behalf_of_user_id'] ?? -1 )
			&& 'bot' === (string) ( $capability_row['sender_type'] ?? '' )
			&& 'kg_reply' === (string) ( $capability_row['responder_kind'] ?? '' )
			&& ( BizCity_Probe_Outbound_Mock_Adapter::$attempts - $capability_attempts_before ) === 1
			&& 'permission_denied' === (string) ( $capability_media['code'] ?? '' );
		$this->emit( $ctx, $steps, 'Row 14 - an inbound source with no human owner anchors on the inbox capability and stays text-only', $capability_ok, sprintf( 'outcome=%s owner_source=%s sender_type=%s responder_kind=%s media_code=%s attempts_delta=%d', (string) ( $capability_first['outcome'] ?? '' ), (string) ( $capability_first['owner_source'] ?? '' ), (string) ( $capability_row['sender_type'] ?? '' ), (string) ( $capability_row['responder_kind'] ?? '' ), (string) ( $capability_media['code'] ?? '' ), BizCity_Probe_Outbound_Mock_Adapter::$attempts - $capability_attempts_before ) );


		// Row 13 — a system actor carrying the real owner sends once and replays.
		$attempts_before = BizCity_Probe_Outbound_Mock_Adapter::$attempts;
		$messages_before = $this->message_count( $conversation_id );
		$key_sys  = 'bzdiag_outbound_sys_ok_' . md5( (string) $conversation_id );
		$hash_sys = md5( 'system-with-owner|' . $conversation_id );
		$this->claims[] = array( 'key' => $key_sys, 'hash' => $hash_sys, 'conversation_id' => $conversation_id, 'user_id' => $user_a );
		$system_payload = array(
			'conversation_id'      => $conversation_id,
			'content'              => 'Diagnostics system send with owner continuity',
			'content_type'         => 'text',
			'idempotency_key'      => $key_sys,
			'request_hash'         => $hash_sys,
			'actor'                => 'system',
			'on_behalf_of_user_id' => $user_a,
		);
		$system_first  = BizCity_CRM_Outbound_Dispatcher::dispatch( $system_payload );
		$system_replay = BizCity_CRM_Outbound_Dispatcher::dispatch( $system_payload );
		$system_ok = 'queued' === (string) ( $system_first['outcome'] ?? '' )
			&& 'system' === (string) ( $system_first['actor'] ?? '' )
			&& (int) ( $system_first['on_behalf_of_user_id'] ?? 0 ) === $user_a
			&& ( BizCity_Probe_Outbound_Mock_Adapter::$attempts - $attempts_before ) === 1
			&& ( $this->message_count( $conversation_id ) - $messages_before ) === 1
			&& ! empty( $system_replay['replayed'] );
		$this->emit( $ctx, $steps, 'Row 13 - a system actor with owner continuity sends once, records the owner and replays', $system_ok, sprintf( 'outcome=%s actor=%s owner=%d attempts_delta=%d message_delta=%d replayed=%s', (string) ( $system_first['outcome'] ?? '' ), (string) ( $system_first['actor'] ?? '' ), (int) ( $system_first['on_behalf_of_user_id'] ?? 0 ), BizCity_Probe_Outbound_Mock_Adapter::$attempts - $attempts_before, $this->message_count( $conversation_id ) - $messages_before, ! empty( $system_replay['replayed'] ) ? 'true' : 'false' ) );

		// Row 15 — a synchronous provider send is `sent`; only a job handle stays `queued`.
		BizCity_Probe_Outbound_Mock_Adapter::$mode = 'synchronous';
		$key_sync  = 'bzdiag_outbound_sync_' . md5( (string) $conversation_id );
		$hash_sync = md5( 'synchronous-send|' . $conversation_id );
		$this->claims[] = array( 'key' => $key_sync, 'hash' => $hash_sync, 'conversation_id' => $conversation_id, 'user_id' => $user_a );
		$sync_result = BizCity_CRM_Outbound_Dispatcher::dispatch( array(
			'conversation_id' => $conversation_id,
			'content'         => 'Diagnostics synchronous provider send',
			'content_type'    => 'text',
			'idempotency_key' => $key_sync,
			'request_hash'    => $hash_sync,
			'user_id'         => $user_a,
		) );
		$sync_row = $this->message_row( (int) ( $sync_result['message_id'] ?? 0 ) );
		BizCity_Probe_Outbound_Mock_Adapter::$mode = 'success';
		$sync_ok = 'sent' === (string) ( $sync_result['outcome'] ?? '' )
			&& 'provider_synchronous' === (string) ( $sync_result['delivery_mode'] ?? '' )
			&& '' === (string) ( $sync_result['job_id'] ?? 'x' )
			&& 'sent' === (string) ( $sync_row['status'] ?? '' )
			&& 'queued' === (string) ( $first['outcome'] ?? '' );
		$this->emit( $ctx, $steps, 'Row 15 - a provider message id with no job handle is sent, while a job handle stays queued', $sync_ok, sprintf( 'sync_outcome=%s delivery_mode=%s row_status=%s job_outcome=%s', (string) ( $sync_result['outcome'] ?? '' ), (string) ( $sync_result['delivery_mode'] ?? '' ), (string) ( $sync_row['status'] ?? '' ), (string) ( $first['outcome'] ?? '' ) ) );

		// Row 11 — mock boundary is explicit.
		$adapter_now = BizCity_CRM_Channel_Registry::get( 'facebook' );
		$mock_ok = $adapter_now instanceof BizCity_Probe_Outbound_Mock_Adapter && BizCity_Probe_Outbound_Mock_Adapter::$attempts > 0;
		$this->emit( $ctx, $steps, 'Row 11 - every attempt was served by the diagnostics mock provider (not production evidence)', $mock_ok, sprintf( 'mock_attempts=%d provider_mode=mock; production delivery remains a separate D7 row.', BizCity_Probe_Outbound_Mock_Adapter::$attempts ) );

		// Cleanup.
		$this->restore_mock_adapter();
		wp_set_current_user( $this->original_user_id );
		$cleanup = $this->cleanup_fixtures();
		$this->emit( $ctx, $steps, 'Cleanup - fixture rows, attachments and idempotency claims are released', $cleanup['ok'], $cleanup['detail'] );

		unset( $attachment_steps );
		$passed = true;
		foreach ( $steps as $step ) {
			if ( 'fail' === (string) ( $step['status'] ?? '' ) ) {
				$passed = false;
				break;
			}
		}
		return array(
			'status'   => $passed ? 'pass' : 'fail',
			'summary'  => $passed ? 'CRM outbound delivery state machine passed against the diagnostics mock provider; production delivery remains pending (D7).' : 'CRM outbound delivery state machine failed.',
			'error'    => $passed ? '' : 'crm_outbound_delivery_failed',
			'fix_hint' => $passed ? '' : 'Inspect the outbound dispatcher idempotency claim, delivery ladder, attachment policy and notify resolution.',
			'steps'    => $steps,
		);
	}

	public function cleanup(): void {
		// [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-0.41D-D5 — the runner calls cleanup even when run() throws.
		$this->restore_mock_adapter();
		if ( $this->original_user_id > 0 && (int) get_current_user_id() !== $this->original_user_id ) {
			wp_set_current_user( $this->original_user_id );
		}
		$this->cleanup_fixtures();
	}

	/**
	 * Attachment ownership, MIME and size rows.
	 *
	 * @param mixed  $ctx             Probe context.
	 * @param array  $steps           Step accumulator.
	 * @param int    $conversation_id Conversation under test.
	 * @param int    $user_a          Authorized sender.
	 * @param int    $user_b          Foreign user.
	 * @return bool
	 */
	private function run_attachment_rows( $ctx, array &$steps, int $conversation_id, int $user_a, int $user_b ): bool {
		// [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-0.41D-D5 — use a PNG because a site may restrict plain-text uploads; the denied row varies the STORED mime, not the extension.
		$owned   = $this->create_attachment( $user_a, 'image/png', 'bzdiag-outbound-owned.png' );
		$foreign = $user_b > 0 ? $this->create_attachment( $user_b, 'image/png', 'bzdiag-outbound-foreign.png' ) : 0;
		$denied  = $this->create_attachment( $user_a, 'application/x-msdownload', 'bzdiag-outbound-denied.png' );
		if ( $owned <= 0 || $denied <= 0 || ( $user_b > 0 && $foreign <= 0 ) ) {
			$this->emit( $ctx, $steps, 'Rows 7-9 - attachment fixtures', false, sprintf( 'Không tạo được media fixture cho outbound attachment (owned=%d foreign=%d denied=%d reason=%s).', $owned, $foreign, $denied, '' !== $this->attachment_error ? $this->attachment_error : 'unknown' ) );
			return false;
		}

		BizCity_Probe_Outbound_Mock_Adapter::$mode = 'success';

		// Row 7 — foreign owner is denied before the provider.
		$attempts_before = BizCity_Probe_Outbound_Mock_Adapter::$attempts;
		$key  = 'bzdiag_outbound_att_foreign_' . md5( (string) $conversation_id );
		$hash = md5( 'attachment-foreign|' . $conversation_id );
		$this->claims[] = array( 'key' => $key, 'hash' => $hash, 'conversation_id' => $conversation_id, 'user_id' => $user_a );
		$foreign_result = BizCity_CRM_Outbound_Dispatcher::dispatch( array(
			'conversation_id' => $conversation_id,
			'content'         => '',
			'content_type'    => 'file',
			'attachments'     => array( $foreign ),
			'idempotency_key' => $key,
			'request_hash'    => $hash,
			'user_id'         => $user_a,
		) );
		$foreign_ok = 'failed' === (string) ( $foreign_result['outcome'] ?? '' )
			&& 'permission_denied' === (string) ( $foreign_result['code'] ?? '' )
			&& BizCity_Probe_Outbound_Mock_Adapter::$attempts === $attempts_before
			&& empty( $foreign_result['attachment']['owner_ok'] );
		$this->emit( $ctx, $steps, 'Row 7 - a foreign attachment is denied before any provider attempt', $foreign_ok, sprintf( 'code=%s attempts_delta=%d owner_ok=%s', (string) ( $foreign_result['code'] ?? '' ), BizCity_Probe_Outbound_Mock_Adapter::$attempts - $attempts_before, ! empty( $foreign_result['attachment']['owner_ok'] ) ? 'true' : 'false' ) );

		// Row 8 — MIME and size policy.
		$attempts_before = BizCity_Probe_Outbound_Mock_Adapter::$attempts;
		$key_mime  = 'bzdiag_outbound_att_mime_' . md5( (string) $conversation_id );
		$hash_mime = md5( 'attachment-mime|' . $conversation_id );
		$this->claims[] = array( 'key' => $key_mime, 'hash' => $hash_mime, 'conversation_id' => $conversation_id, 'user_id' => $user_a );
		$mime_result = BizCity_CRM_Outbound_Dispatcher::dispatch( array(
			'conversation_id' => $conversation_id,
			'content'         => '',
			'content_type'    => 'file',
			'attachments'     => array( $denied ),
			'idempotency_key' => $key_mime,
			'request_hash'    => $hash_mime,
			'user_id'         => $user_a,
		) );
		$this->max_bytes_filter = static function () {
			return 1;
		};
		add_filter( 'bizcity_twinweb_attachment_max_bytes', $this->max_bytes_filter, 99 );
		$key_size  = 'bzdiag_outbound_att_size_' . md5( (string) $conversation_id );
		$hash_size = md5( 'attachment-size|' . $conversation_id );
		$this->claims[] = array( 'key' => $key_size, 'hash' => $hash_size, 'conversation_id' => $conversation_id, 'user_id' => $user_a );
		$size_result = BizCity_CRM_Outbound_Dispatcher::dispatch( array(
			'conversation_id' => $conversation_id,
			'content'         => '',
			'content_type'    => 'file',
			'attachments'     => array( $owned ),
			'idempotency_key' => $key_size,
			'request_hash'    => $hash_size,
			'user_id'         => $user_a,
		) );
		remove_filter( 'bizcity_twinweb_attachment_max_bytes', $this->max_bytes_filter, 99 );
		$this->max_bytes_filter = null;
		$policy_ok = 'attachment_mime_denied' === (string) ( $mime_result['code'] ?? '' )
			&& 'attachment_too_large' === (string) ( $size_result['code'] ?? '' )
			&& BizCity_Probe_Outbound_Mock_Adapter::$attempts === $attempts_before;
		$this->emit( $ctx, $steps, 'Row 8 - MIME and size violations fail closed with zero provider attempts', $policy_ok, sprintf( 'mime_code=%s size_code=%s attempts_delta=%d', (string) ( $mime_result['code'] ?? '' ), (string) ( $size_result['code'] ?? '' ), BizCity_Probe_Outbound_Mock_Adapter::$attempts - $attempts_before ) );

		// Row 9 — valid attachment sends once and replays.
		$attempts_before = BizCity_Probe_Outbound_Mock_Adapter::$attempts;
		$messages_before = $this->message_count( $conversation_id );
		$key_ok  = 'bzdiag_outbound_att_ok_' . md5( (string) $conversation_id );
		$hash_ok = md5( 'attachment-ok|' . $conversation_id );
		$this->claims[] = array( 'key' => $key_ok, 'hash' => $hash_ok, 'conversation_id' => $conversation_id, 'user_id' => $user_a );
		$payload = array(
			'conversation_id' => $conversation_id,
			'content'         => '',
			'content_type'    => 'file',
			'attachments'     => array( $owned ),
			'idempotency_key' => $key_ok,
			'request_hash'    => $hash_ok,
			'user_id'         => $user_a,
		);
		$attach_first  = BizCity_CRM_Outbound_Dispatcher::dispatch( $payload );
		$attach_second = BizCity_CRM_Outbound_Dispatcher::dispatch( $payload );
		$attach_ok = 'queued' === (string) ( $attach_first['outcome'] ?? '' )
			&& ( BizCity_Probe_Outbound_Mock_Adapter::$attempts - $attempts_before ) === 1
			&& ( $this->message_count( $conversation_id ) - $messages_before ) === 1
			&& ! empty( $attach_second['replayed'] )
			&& 1 === (int) ( $attach_first['attachment']['count'] ?? 0 );
		$this->emit( $ctx, $steps, 'Row 9 - a valid attachment sends exactly once and replays on the same key', $attach_ok, sprintf( 'outcome=%s attempts_delta=%d message_delta=%d replayed=%s', (string) ( $attach_first['outcome'] ?? '' ), BizCity_Probe_Outbound_Mock_Adapter::$attempts - $attempts_before, $this->message_count( $conversation_id ) - $messages_before, ! empty( $attach_second['replayed'] ) ? 'true' : 'false' ) );

		return $foreign_ok && $policy_ok && $attach_ok;
	}

	/** Register the mock adapter through the canonical filter and flush the registry cache. */
	private function install_mock_adapter(): void {
		$this->adapter_filter = static function ( $adapters ) {
			if ( ! is_array( $adapters ) ) {
				$adapters = array();
			}
			$adapters['facebook'] = new BizCity_Probe_Outbound_Mock_Adapter();
			return $adapters;
		};
		BizCity_Probe_Outbound_Mock_Adapter::$attempts = 0;
		BizCity_Probe_Outbound_Mock_Adapter::$mode = 'success';
		add_filter( 'bizcity_crm_register_adapters', $this->adapter_filter, 99 );
		if ( method_exists( 'BizCity_CRM_Channel_Registry', 'flush_cache' ) ) {
			BizCity_CRM_Channel_Registry::flush_cache();
		}
	}

	/** Remove the mock adapter and restore the production registry. */
	private function restore_mock_adapter(): void {
		if ( null !== $this->adapter_filter ) {
			remove_filter( 'bizcity_crm_register_adapters', $this->adapter_filter, 99 );
			$this->adapter_filter = null;
			if ( method_exists( 'BizCity_CRM_Channel_Registry', 'flush_cache' ) ) {
				BizCity_CRM_Channel_Registry::flush_cache();
			}
		}
		if ( null !== $this->max_bytes_filter ) {
			remove_filter( 'bizcity_twinweb_attachment_max_bytes', $this->max_bytes_filter, 99 );
			$this->max_bytes_filter = null;
		}
	}

	/**
	 * Create one disposable media attachment owned by a fixture user.
	 *
	 * @param int    $user_id Owner.
	 * @param string $mime    Stored MIME type.
	 * @param string $name    File name.
	 * @return int Attachment id or 0.
	 */
	private function create_attachment( int $user_id, string $mime, string $name ): int {
		$bytes  = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' );
		$upload = wp_upload_bits( $name, null, false !== $bytes ? $bytes : 'bzdiag_fixture outbound attachment' );
		if ( ! is_array( $upload ) || ! empty( $upload['error'] ) || empty( $upload['file'] ) ) {
			// [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-0.41D-D5 — keep the real upload reason so a media failure is diagnosable, not anonymous.
			$this->attachment_error = 'upload_failed:' . ( is_array( $upload ) ? (string) ( $upload['error'] ?? 'unknown' ) : 'no_result' );
			return 0;
		}
		$attachment_id = (int) wp_insert_attachment( array(
			'post_mime_type' => $mime,
			'post_title'     => 'bzdiag_fixture ' . $name,
			'post_content'   => '',
			'post_status'    => 'inherit',
			'post_author'    => $user_id,
		), $upload['file'] );
		if ( $attachment_id <= 0 ) {
			$this->attachment_error = 'insert_attachment_failed';
			return 0;
		}
		$this->attachment_ids[] = $attachment_id;
		return $attachment_id;
	}

	/** Count messages currently stored for the fixture conversation. */
	private function message_count( int $conversation_id ): int {
		$rows = BizCity_CRM_Repository::list_messages( $conversation_id, 200, 0 );
		return is_array( $rows ) ? count( $rows ) : 0;
	}

	/** Read one message row. */
	private function message_row( int $message_id ): array {
		if ( $message_id <= 0 ) {
			return array();
		}
		$row = BizCity_CRM_Repository::get_message( $message_id );
		return is_array( $row ) ? $row : array();
	}

	/**
	 * Release fixtures, attachments and idempotency claims.
	 *
	 * @return array{ok:bool,detail:string}
	 */
	private function cleanup_fixtures(): array {
		$detail = array();

		foreach ( $this->claims as $claim ) {
			// [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-0.41D-D5 — recover each claim key through the public store API, then release it so the option store stays clean.
			$handle = BizCity_Twin_Mutation_Store::begin(
				array(
					'action'          => BizCity_CRM_Outbound_Dispatcher::ACTION,
					'resource'        => array( 'scope' => 'conversation:' . (int) $claim['conversation_id'] ),
					'idempotency_key' => (string) $claim['key'],
				),
				array( 'blog_id' => (int) get_current_blog_id(), 'user_id' => (int) $claim['user_id'] ),
				(string) $claim['hash']
			);
			if ( ! empty( $handle['key'] ) ) {
				BizCity_Twin_Mutation_Store::release( (string) $handle['key'] );
			}
		}
		$claim_count  = count( $this->claims );
		$this->claims = array();

		$attachments_removed = 0;
		foreach ( $this->attachment_ids as $attachment_id ) {
			if ( wp_delete_attachment( (int) $attachment_id, true ) ) {
				$attachments_removed++;
			}
		}
		$attachment_total      = count( $this->attachment_ids );
		$this->attachment_ids  = array();

		$remaining = 0;
		if ( '' !== $this->cleanup_token ) {
			$result = BizCity_CRM_Inbox_Fixture_Factory::destroy( $this->cleanup_token );
			$this->cleanup_token = '';
			$remaining = (int) ( $result['remaining'] ?? 0 );
			if ( empty( $result['ok'] ) ) {
				$detail[] = 'fixture_destroy_failed';
			}
		}

		$ok = 0 === $remaining && $attachments_removed === $attachment_total && empty( $detail );
		return array(
			'ok'     => $ok,
			'detail' => sprintf( 'claims_released=%d attachments=%d/%d fixture_remaining=%d %s', $claim_count, $attachments_removed, $attachment_total, $remaining, implode( ',', $detail ) ),
		);
	}

	/**
	 * Build a failing probe result after releasing fixtures.
	 *
	 * @param array  $steps    Steps collected so far.
	 * @param string $summary  Vietnamese summary.
	 * @param string $error    Machine error code.
	 * @param string $fix_hint Actionable hint.
	 * @return array
	 */
	private function fail( array $steps, string $summary, string $error, string $fix_hint ): array {
		$this->restore_mock_adapter();
		if ( $this->original_user_id > 0 ) {
			wp_set_current_user( $this->original_user_id );
		}
		$this->cleanup_fixtures();
		return array( 'status' => 'fail', 'summary' => $summary, 'error' => $error, 'fix_hint' => $fix_hint, 'steps' => $steps );
	}

	/**
	 * Append and stream one step.
	 *
	 * @param mixed  $ctx    Probe context.
	 * @param array  $steps  Step accumulator.
	 * @param string $label  Step label.
	 * @param bool   $ok     Pass flag.
	 * @param string $detail Step detail.
	 * @return void
	 */
	private function emit( $ctx, array &$steps, string $label, bool $ok, string $detail ): void {
		$step = array( 'label' => $label, 'status' => $ok ? 'pass' : 'fail', 'detail' => $detail );
		$steps[] = $step;
		if ( is_object( $ctx ) && method_exists( $ctx, 'emit_step' ) ) {
			$ctx->emit_step( $step );
		}
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $probes ) {
	$probes[] = 'BizCity_Probe_TwinWeb_CRM_Outbound_Delivery';
	return $probes;
} );
