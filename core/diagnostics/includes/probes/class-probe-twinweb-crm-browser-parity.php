<?php
/**
 * D6 browser-acceptance precondition probe for the Twin GPT CRM console.
 *
 * PHASE-0.41D §6.2. Browser acceptance is NOT a PHP probe. This probe only
 * proves the server-side preconditions a human browser run depends on:
 * the C surface owner exists, the exact-account payload carries the fields the
 * UI renders, provider state is never fabricated from CRM SQL, an empty
 * history is explicit, errors satisfy R-ERROR-UX, and no `/gpt/` route accepts
 * a client-supplied owner/inbox/account as ACL.
 *
 * Per R-DDV-FE the probe never fails because a React source or a built bundle
 * is absent on a server-only deployment; those steps report their real state
 * without blocking the contract checks.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since 2026-09-16 (PHASE-0.41D-CLOSURE / D6)
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
if ( class_exists( 'BizCity_Probe_TwinWeb_CRM_Browser_Parity', false ) ) {
	return;
}

final class BizCity_Probe_TwinWeb_CRM_Browser_Parity implements BizCity_Diagnostics_Probe {

	/** @var string */
	private $cleanup_token = '';

	/** @var int */
	private $original_user_id = 0;

	public function id(): string { return 'modules.twin_gpt.crm_browser_parity'; }
	public function label(): string { return 'Twin GPT CRM browser-acceptance preconditions'; }
	public function description(): string { return 'Kiem tra surface owner, payload field UI can, provider state khong bi bia tu SQL, empty history tuong minh va error payload R-ERROR-UX truoc khi chay browser runbook.'; }
	public function severity(): string { return 'major'; }
	public function order(): int { return 81; }
	public function icon(): string { return 'monitor-check'; }
	public function estimate_ms(): int { return 900; }

	public function precondition() {
		// [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-0.41D-D6 — the fixture and the C route are contract owners; a missing owner is a failure, not a skip.
		if ( ! class_exists( 'BizCity_CRM_Inbox_Fixture_Factory' ) ) {
			return 'CRM Inbox fixture factory is not loaded (core/diagnostics/includes/fixtures).';
		}
		if ( ! class_exists( 'BizCity_TwinWeb_REST' ) ) {
			return 'TwinWeb REST owner is not loaded.';
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-0.41D-D6 — server preconditions only; the 13-scenario browser runbook stays a human evidence row.
		$this->original_user_id = (int) get_current_user_id();
		$steps = array();
		$root  = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) ) . '/';
		$rest_path = $root . 'modules/twinweb/includes/class-twinweb-rest.php';
		$page_path = $root . 'modules/twinweb/includes/class-twinweb-page.php';

		// Step 1 — Disk: the C surface owners.
		$disk_ok = is_readable( $rest_path ) && is_readable( $page_path );
		$this->emit( $ctx, $steps, 'Disk - C surface REST and page owners are readable', $disk_ok, $disk_ok ? 'TwinWeb REST and page owner are present.' : 'A TwinWeb surface owner is missing.' );
		if ( ! $disk_ok ) {
			return $this->fail( $steps, 'Twin GPT surface owners are missing on disk.', 'crm_browser_surface_missing', 'Restore modules/twinweb REST and page owners.' );
		}
		$rest_source = (string) file_get_contents( $rest_path );

		// Step 2 — Loader.
		$loader_ok = class_exists( 'BizCity_TwinWeb_REST', false )
			&& method_exists( 'BizCity_TwinWeb_REST', 'get_crm_exact_inbox' )
			&& class_exists( 'BizCity_TwinWeb_Identity', false );
		$this->emit( $ctx, $steps, 'Loader - exact-account route callback and identity owner are loaded', $loader_ok, $loader_ok ? 'Route callback and identity resolver are available.' : 'A C surface dependency is not loaded.' );
		if ( ! $loader_ok ) {
			return $this->fail( $steps, 'Twin GPT surface dependencies are incomplete.', 'crm_browser_loader_incomplete', 'Load TwinWeb REST and identity owners before running this probe.' );
		}

		// Step 3 — built artifact state (R-DDV-FE: never a hard gate on a server topology).
		$dist_dir  = $root . 'modules/twinweb/ui/dist/assets/';
		$dist_js   = is_dir( $dist_dir ) ? glob( $dist_dir . 'index-*.js' ) : array();
		$dist_note = ( is_array( $dist_js ) && ! empty( $dist_js ) )
			? sprintf( 'Built bundle present (%d asset file[s]); browser run may use the deployed artifact.', count( $dist_js ) )
			: 'No built bundle on this host; this is a valid server-only topology and is reported, not failed. Deploy the Vite artifact before the browser runbook.';
		$this->emit( $ctx, $steps, 'Disk - built C bundle state is reported without gating the contract', true, $dist_note );

		// Step 4 — no client-supplied ACL parameter on the exact-account route.
		$route_block = '';
		$start = strpos( $rest_source, "register_rest_route( \$ns, '/crm/inbox'," );
		if ( false !== $start ) {
			$route_block = substr( $rest_source, $start, 900 );
		}
		$acl_leak = array();
		foreach ( array( 'owner_id', 'owner_user_id', 'inbox_id', 'account_id' ) as $param ) {
			if ( '' !== $route_block && false !== strpos( $route_block, "'" . $param . "'" ) ) {
				$acl_leak[] = $param;
			}
		}
		$acl_ok = '' !== $route_block && empty( $acl_leak )
			&& false !== strpos( $rest_source, 'BizCity_CRM_Inbox_Access::resolve_scope' );
		$this->emit( $ctx, $steps, 'Contract - the exact-account route accepts no client owner/inbox/account as ACL', $acl_ok, $acl_ok ? 'Route args expose only channel/ref/limit/filter/conversation_id and scope is resolved server-side.' : ( '' === $route_block ? 'Route registration block was not found for inspection.' : 'Client-supplied ACL parameter(s) found: ' . implode( ', ', $acl_leak ) ) );

		// Step 5 — fixture for the payload contract.
		$fixture = BizCity_CRM_Inbox_Fixture_Factory::build( array(
			'channel'           => 'facebook',
			'users'             => 1,
			'accounts_per_user' => 1,
			'with_conversation' => true,
			'with_messages'     => 2,
		) );
		if ( empty( $fixture['ok'] ) ) {
			$this->emit( $ctx, $steps, 'Runtime - disposable C payload fixture', false, sprintf( 'Fixture build failed: %s %s', (string) ( $fixture['reason'] ?? 'unknown' ), (string) ( $fixture['detail'] ?? '' ) ) );
			return $this->fail( $steps, 'Không dựng được fixture để kiểm tra payload C.', 'crm_browser_fixture_unavailable', 'Kiểm tra CRM repository/schema trên blog mục tiêu rồi rerun probe.' );
		}
		$this->cleanup_token = (string) $fixture['cleanup_token'];
		$expect = BizCity_CRM_Inbox_Fixture_Factory::expectations( $this->cleanup_token );
		$target = isset( $expect['business_inboxes'][0] ) ? $expect['business_inboxes'][0] : array();
		if ( empty( $target ) ) {
			$this->emit( $ctx, $steps, 'Runtime - fixture expectations', false, 'Fixture has no business inbox row.' );
			return $this->fail( $steps, 'Fixture không có inbox hợp lệ.', 'crm_browser_fixture_expectation_missing', 'Kiểm tra fixture factory envelope rồi rerun probe.' );
		}
		$channel = (string) $target['channel'];
		$ref     = (string) $target['ref'];
		$user_id = (int) $target['member_user_id'];
		$this->emit( $ctx, $steps, 'Runtime - disposable C payload fixture', true, sprintf( 'Created inbox #%d with one conversation for payload inspection.', (int) $target['inbox_id'] ) );
		wp_set_current_user( $user_id );

		// Step 6 — list payload carries the freshness contract.
		$list = $this->call( $channel, $ref, 0, 'all' );
		$freshness = isset( $list['data']['freshness'] ) && is_array( $list['data']['freshness'] ) ? $list['data']['freshness'] : array();
		$required_keys = array( 'contract', 'source', 'snapshot_at', 'snapshot_age_seconds', 'last_inbound_at', 'last_outbound_at', 'queued_outbound', 'account_mapping', 'bridge_health', 'session_status', 'queue_status', 'last_callback_at' );
		$missing = array();
		foreach ( $required_keys as $key ) {
			if ( ! array_key_exists( $key, $freshness ) ) {
				$missing[] = $key;
			}
		}
		$freshness_ok = empty( $missing ) && '200' === $list['transport'];
		$this->emit( $ctx, $steps, 'Runtime - list payload exposes the full freshness contract', $freshness_ok, $freshness_ok ? 'Snapshot age, mapping, bridge, session, queue and callback fields are all present.' : 'Missing freshness field(s): ' . ( empty( $missing ) ? 'transport ' . $list['transport'] : implode( ', ', $missing ) ) );

		// Step 7 — provider-owned state is never fabricated from CRM SQL.
		$honest_ok = 'crm_sql_only' === (string) ( $freshness['source'] ?? '' )
			&& 'not_evaluated' === (string) ( $freshness['bridge_health'] ?? '' )
			&& 'not_evaluated' === (string) ( $freshness['session_status'] ?? '' )
			&& 'not_evaluated' === (string) ( $freshness['queue_status'] ?? '' )
			// [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-0.41D-D6 — `??` treats an explicit null as missing, so assert the key and its null value separately.
			&& array_key_exists( 'last_callback_at', $freshness )
			&& null === $freshness['last_callback_at'];
		$this->emit( $ctx, $steps, 'Contract - bridge, session, queue and callback state are not inferred from a CRM read', $honest_ok, $honest_ok ? 'Provider-owned facts are reported as not_evaluated instead of being derived from SQL.' : sprintf( 'source=%s bridge=%s session=%s queue=%s', (string) ( $freshness['source'] ?? '' ), (string) ( $freshness['bridge_health'] ?? '' ), (string) ( $freshness['session_status'] ?? '' ), (string) ( $freshness['queue_status'] ?? '' ) ) );

		// Step 8 — conversation payload carries the UI thread fields.
		$conversation_id = (int) $target['conversation_id'];
		$thread = $conversation_id > 0 ? $this->call( $channel, $ref, $conversation_id, 'all' ) : array( 'transport' => 'skipped', 'data' => array() );
		$conversation = isset( $thread['data']['conversation'] ) && is_array( $thread['data']['conversation'] ) ? $thread['data']['conversation'] : array();
		$contact = isset( $conversation['contact'] ) && is_array( $conversation['contact'] ) ? $conversation['contact'] : array();
		$thread_ok = '200' === $thread['transport']
			&& array_key_exists( 'history_unavailable', $thread['data'] )
			&& false === $thread['data']['history_unavailable']
			&& ( array_key_exists( 'thread_kind', $conversation ) || array_key_exists( 'is_group', $contact ) );
		$this->emit( $ctx, $steps, 'Runtime - thread payload exposes history state and thread kind for the UI', $thread_ok, $thread_ok ? 'Thread returns explicit history_unavailable=false plus thread/group metadata.' : sprintf( 'transport=%s history_key=%s', $thread['transport'], array_key_exists( 'history_unavailable', (array) $thread['data'] ) ? 'present' : 'missing' ) );

		// Step 9 — an empty result is explicit, never a silent blank.
		$empty_list = $this->call( $channel, $ref, 0, 'mine' );
		$empty_items = isset( $empty_list['data']['items'] ) && is_array( $empty_list['data']['items'] ) ? $empty_list['data']['items'] : array();
		$empty_ok = '200' === $empty_list['transport']
			&& array_key_exists( 'history_unavailable', $empty_list['data'] )
			&& ( empty( $empty_items ) ? true === $empty_list['data']['history_unavailable'] : false === $empty_list['data']['history_unavailable'] )
			&& ( empty( $empty_items ) ? 'no_local_conversations' === (string) ( $empty_list['data']['history_reason'] ?? '' ) : true );
		$this->emit( $ctx, $steps, 'Runtime - an empty authorized list states history_unavailable explicitly', $empty_ok, sprintf( 'items=%d history_unavailable=%s reason=%s', count( $empty_items ), isset( $empty_list['data']['history_unavailable'] ) ? var_export( $empty_list['data']['history_unavailable'], true ) : 'missing', (string) ( $empty_list['data']['history_reason'] ?? '' ) ) );

		// Step 10 — error payload satisfies R-ERROR-UX for the banner the browser run inspects.
		$denied = $this->call( $channel, '__diag_fixture_foreign_' . substr( md5( $ref ), 0, 10 ), 0, 'all' );
		$error_payload = is_array( $denied['data'] ) ? $denied['data'] : array();
		$error_ok = ! empty( $error_payload['code'] )
			&& ! empty( $error_payload['message'] )
			&& ! empty( $error_payload['hint'] )
			&& ! empty( $error_payload['help_code'] )
			&& empty( $error_payload['items'] );
		$this->emit( $ctx, $steps, 'Contract - a denied selector returns code, message, hint and help_code without leaking rows', $error_ok, $error_ok ? sprintf( 'code=%s help_code=%s', (string) $error_payload['code'], (string) $error_payload['help_code'] ) : 'Error payload is missing an R-ERROR-UX field or leaked data.' );

		// Step 11 — cleanup.
		wp_set_current_user( $this->original_user_id );
		$cleanup = BizCity_CRM_Inbox_Fixture_Factory::destroy( $this->cleanup_token );
		$this->cleanup_token = '';
		$cleanup_ok = ! empty( $cleanup['ok'] ) && 0 === (int) ( $cleanup['remaining'] ?? 0 );
		$this->emit( $ctx, $steps, 'Cleanup - every marker-scoped row is removed', $cleanup_ok, $cleanup_ok ? 'All fixture rows were removed and none remain.' : sprintf( 'cleanup remaining=%d', (int) ( $cleanup['remaining'] ?? -1 ) ) );

		$passed = true;
		foreach ( $steps as $step ) {
			if ( 'fail' === (string) ( $step['status'] ?? '' ) ) {
				$passed = false;
				break;
			}
		}
		return array(
			'status'   => $passed ? 'pass' : 'fail',
			'summary'  => $passed ? 'Twin GPT CRM browser preconditions passed; the 13-scenario human browser runbook remains required evidence.' : 'Twin GPT CRM browser preconditions failed.',
			'error'    => $passed ? '' : 'crm_browser_parity_failed',
			'fix_hint' => $passed ? '' : 'Inspect the C payload freshness block, explicit history state, error payload fields and route ACL parameters.',
			'steps'    => $steps,
		);
	}

	public function cleanup(): void {
		// [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-0.41D-D6 — release fixtures even when run() throws.
		if ( '' !== $this->cleanup_token ) {
			BizCity_CRM_Inbox_Fixture_Factory::destroy( $this->cleanup_token );
			$this->cleanup_token = '';
		}
		if ( $this->original_user_id > 0 && (int) get_current_user_id() !== $this->original_user_id ) {
			wp_set_current_user( $this->original_user_id );
		}
	}

	/**
	 * Call the real C route callback and normalize the transport result.
	 *
	 * @param string $channel         Channel code.
	 * @param string $ref             Account ref.
	 * @param int    $conversation_id Conversation id or 0.
	 * @param string $filter          Server-owned filter key.
	 * @return array{transport:string,data:array}
	 */
	private function call( string $channel, string $ref, int $conversation_id, string $filter ): array {
		$request = new WP_REST_Request( 'GET', '/bizcity-twinweb/v1/crm/inbox' );
		$request->set_param( 'channel', $channel );
		$request->set_param( 'ref', $ref );
		$request->set_param( 'filter', $filter );
		if ( $conversation_id > 0 ) {
			$request->set_param( 'conversation_id', $conversation_id );
		}
		$result = BizCity_TwinWeb_REST::instance()->get_crm_exact_inbox( $request );
		if ( is_wp_error( $result ) ) {
			return array( 'transport' => 'wp_error', 'data' => array( 'code' => $result->get_error_code() ) );
		}
		if ( is_object( $result ) && method_exists( $result, 'get_data' ) ) {
			$status = method_exists( $result, 'get_status' ) ? (int) $result->get_status() : 200;
			return array( 'transport' => (string) $status, 'data' => (array) $result->get_data() );
		}
		return array( 'transport' => 'unknown', 'data' => array() );
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
		$this->cleanup();
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
	$probes[] = 'BizCity_Probe_TwinWeb_CRM_Browser_Parity';
	return $probes;
} );
