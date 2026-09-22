<?php
/**
 * D1 positive-authorization probe for the exact-account Twin GPT CRM console.
 *
 * PHASE-0.41D §1.3 (D1.2). Proves "cho đúng", not only "chặn đúng": a
 * diagnostics-owned assigned Zone 1 inbox must resolve to a bounded CRM
 * projection through the real C route.
 *
 * Anti-overclaim contract (PHASE-0.41D §1.3):
 *   - A missing fixture is a FAIL, never a SKIP. An empty inbox is not
 *     "authorized-empty PASS" when the probe itself built the fixture.
 *   - The probe never computes ACL itself. It only compares the canonical
 *     `BizCity_CRM_Inbox_Access` / `BizCity_CRM_Repository` output against the
 *     fixture's own expectations.
 *   - Every fixture row is removed through `finally`-equivalent cleanup that
 *     the runner always invokes.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since 2026-09-16 (PHASE-0.41D-CLOSURE / D1)
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
if ( class_exists( 'BizCity_Probe_TwinWeb_CRM_Inbox_Positive_Projection', false ) ) {
	return;
}

final class BizCity_Probe_TwinWeb_CRM_Inbox_Positive_Projection implements BizCity_Diagnostics_Probe {

	/** @var string */
	private $cleanup_token = '';

	/** @var int */
	private $original_user_id = 0;

	public function id(): string { return 'modules.twin_gpt.crm_inbox_positive_projection'; }
	public function label(): string { return 'Twin GPT positive CRM inbox projection'; }
	public function description(): string { return 'Chứng minh exact account được assign trả projection CRM bounded qua route C, không chỉ chặn đúng.'; }
	public function severity(): string { return 'critical'; }
	public function order(): int { return 79; }
	public function icon(): string { return 'inbox'; }
	public function estimate_ms(): int { return 900; }

	public function precondition() {
		// [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-0.41D-D1 — the fixture factory is part of the probe contract, so a missing factory is a real failure not a skip.
		if ( ! class_exists( 'BizCity_CRM_Inbox_Fixture_Factory' ) ) {
			return 'CRM Inbox fixture factory is not loaded (core/diagnostics/includes/fixtures).';
		}
		if ( ! class_exists( 'BizCity_TwinWeb_REST' ) || ! class_exists( 'BizCity_CRM_Repository' ) || ! class_exists( 'BizCity_CRM_Inbox_Access' ) ) {
			return 'TwinWeb, CRM Repository or Inbox Access is not loaded.';
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-0.41D-D1 — prove the positive exact-account read path with a disposable assigned Zone 1 inbox.
		$this->original_user_id = (int) get_current_user_id();
		$steps = array();

		// Step 1 — Disk.
		$root = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) ) . '/';
		$rest_path     = $root . 'modules/twinweb/includes/class-twinweb-rest.php';
		$access_path   = $root . 'plugins/bizcity-twin-crm/includes/class-inbox-access.php';
		$repo_path     = $root . 'plugins/bizcity-twin-crm/includes/class-repository.php';
		$factory_path  = $root . 'core/diagnostics/includes/fixtures/class-crm-inbox-fixture-factory.php';
		$disk_ok = is_readable( $rest_path ) && is_readable( $access_path ) && is_readable( $repo_path ) && is_readable( $factory_path )
			&& false !== strpos( (string) file_get_contents( $rest_path ), "'/crm/inbox'" )
			&& false !== strpos( (string) file_get_contents( $rest_path ), 'get_crm_exact_inbox' );
		$this->emit( $ctx, $steps, 'Disk - C route, scope owner, repository and fixture factory are readable', $disk_ok, $disk_ok ? 'The exact-account route and its canonical owners are present on disk.' : 'A required artifact is missing or unreadable.' );
		if ( ! $disk_ok ) {
			return array( 'status' => 'fail', 'summary' => 'Positive CRM projection artifacts are missing on disk.', 'error' => 'crm_positive_projection_artifact_missing', 'fix_hint' => 'Restore the exact-account route, Inbox Access, CRM Repository and the diagnostics fixture factory.', 'steps' => $steps );
		}

		// Step 2 — Loader.
		$loader_ok = class_exists( 'BizCity_TwinWeb_REST', false )
			&& method_exists( 'BizCity_TwinWeb_REST', 'get_crm_exact_inbox' )
			&& class_exists( 'BizCity_CRM_Repository', false )
			&& method_exists( 'BizCity_CRM_Repository', 'get_inbox_by_ref' )
			&& method_exists( 'BizCity_CRM_Repository', 'list_conversations' )
			&& method_exists( 'BizCity_CRM_Repository', 'list_messages' )
			&& class_exists( 'BizCity_CRM_Inbox_Access', false )
			&& method_exists( 'BizCity_CRM_Inbox_Access', 'resolve_scope' )
			&& class_exists( 'BizCity_CRM_Inbox_Fixture_Factory', false );
		$this->emit( $ctx, $steps, 'Loader - route callback, exact reader and scope owner are loaded', $loader_ok, $loader_ok ? 'Route callback, exact repository reader and C-surface scope resolver are available.' : 'A positive-projection dependency is not loaded.' );
		if ( ! $loader_ok ) {
			return array( 'status' => 'fail', 'summary' => 'Positive CRM projection dependencies are incomplete.', 'error' => 'crm_positive_projection_loader_incomplete', 'fix_hint' => 'Load TwinWeb, CRM Repository, Inbox Access and the fixture factory before running the probe.', 'steps' => $steps );
		}

		// Step 3 — Fixture. No fixture => FAIL (never SKIP).
		$fixture = BizCity_CRM_Inbox_Fixture_Factory::build(
			array(
				'channel'           => 'facebook',
				'users'             => 1,
				'accounts_per_user' => 1,
				'with_conversation' => true,
				'with_messages'     => 2,
			)
		);
		if ( empty( $fixture['ok'] ) ) {
			$this->emit( $ctx, $steps, 'Runtime - disposable assigned Zone 1 fixture', false, sprintf( 'Fixture build failed: %s %s', (string) ( $fixture['reason'] ?? 'unknown' ), (string) ( $fixture['detail'] ?? '' ) ) );
			// [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-0.41D-D1 — a missing fixture must not degrade into AUTHORIZED-EMPTY PASS.
			return array( 'status' => 'fail', 'summary' => 'Không dựng được assigned Zone 1 inbox fixture.', 'error' => 'crm_positive_fixture_unavailable', 'fix_hint' => 'Kiểm tra CRM repository/schema trên blog mục tiêu rồi rerun probe.', 'steps' => $steps );
		}
		$this->cleanup_token = (string) $fixture['cleanup_token'];
		$this->emit( $ctx, $steps, 'Runtime - disposable assigned Zone 1 fixture', true, 'Created a marker-scoped Facebook Inbox, membership, contact, conversation and 2 messages.' );

		$expect = BizCity_CRM_Inbox_Fixture_Factory::expectations( $this->cleanup_token );
		if ( empty( $expect['business_inboxes'][0] ) ) {
			$this->emit( $ctx, $steps, 'Runtime - fixture expectations', false, 'Fixture built without a business inbox expectation row.' );
			return array( 'status' => 'fail', 'summary' => 'Fixture expectation row is unavailable.', 'error' => 'crm_positive_fixture_expectation_missing', 'fix_hint' => 'Inspect the fixture factory result envelope before rerunning.', 'steps' => $steps );
		}
		$target    = $expect['business_inboxes'][0];
		$inbox_id  = (int) $target['inbox_id'];
		$channel   = (string) $target['channel'];
		$ref       = (string) $target['ref'];
		$user_id   = (int) $target['member_user_id'];

		// Acting as the assigned member is what the route does through identity.
		// The member was created as a subscriber; a Diagnostics operator is an
		// admin, so impersonate the fixture user to prove the member-scoped read.
		wp_set_current_user( $user_id );

		// Step 4 — positive exact account read.
		$list_response = $this->call_exact_inbox( $channel, $ref, 0 );
		$list_data     = $list_response['data'];
		$positive_ok = '200' === $list_response['transport']
			&& ! empty( $list_data['success'] )
			&& empty( $list_data['_degraded'] )
			&& (int) ( $list_data['inbox']['id'] ?? 0 ) === $inbox_id
			&& (string) ( $list_data['inbox']['channel'] ?? '' ) === $channel
			&& (string) ( $list_data['inbox']['ref'] ?? '' ) === $ref
			&& is_array( $list_data['items'] ?? null );
		$this->emit( $ctx, $steps, 'Runtime - assigned exact account returns HTTP 200 with the exact inbox', $positive_ok, $positive_ok ? sprintf( 'Route returned inbox #%d channel=%s with a bounded item list.', $inbox_id, $channel ) : sprintf( 'Expected inbox #%d (%s) not returned; transport=%s code=%s.', $inbox_id, $channel, $list_response['transport'], (string) ( $list_data['code'] ?? '' ) ) );

		// Step 5 — conversation list bounded to the exact inbox.
		$items = is_array( $list_data['items'] ?? null ) ? $list_data['items'] : array();
		$list_ok = count( $items ) === (int) $expect['conversation_count'];
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || (int) ( $item['inbox_id'] ?? 0 ) !== $inbox_id ) {
				$list_ok = false;
				break;
			}
		}
		$this->emit( $ctx, $steps, 'Runtime - conversation list count and inbox_id match the fixture', $list_ok, sprintf( 'Expected %d conversation(s) in inbox #%d, route returned %d.', (int) $expect['conversation_count'], $inbox_id, count( $items ) ) );

		// Step 6 — thread read via conversation_id.
		$conversation_id = (int) $target['conversation_id'];
		$thread_response = $conversation_id > 0 ? $this->call_exact_inbox( $channel, $ref, $conversation_id ) : array( 'transport' => 'skipped', 'data' => array() );
		$thread_data     = $thread_response['data'];
		$messages        = is_array( $thread_data['messages'] ?? null ) ? $thread_data['messages'] : array();
		$thread_ok = '200' === $thread_response['transport']
			&& ! empty( $thread_data['success'] )
			&& (int) ( $thread_data['conversation']['id'] ?? 0 ) === $conversation_id
			&& (int) ( $thread_data['conversation']['inbox_id'] ?? 0 ) === $inbox_id
			&& count( $messages ) === (int) $expect['message_count'];
		// Ordering must be stable across two reads of the same conversation.
		$second_thread = $conversation_id > 0 ? $this->call_exact_inbox( $channel, $ref, $conversation_id ) : array( 'transport' => 'skipped', 'data' => array() );
		$second_ids    = array();
		foreach ( (array) ( $second_thread['data']['messages'] ?? array() ) as $message ) {
			if ( is_array( $message ) ) {
				$second_ids[] = (int) ( $message['id'] ?? 0 );
			}
		}
		$first_ids = array();
		foreach ( $messages as $message ) {
			if ( is_array( $message ) ) {
				$first_ids[] = (int) ( $message['id'] ?? 0 );
			}
		}
		$thread_ok = $thread_ok && $first_ids === $second_ids && ! empty( $first_ids );
		$this->emit( $ctx, $steps, 'Runtime - conversation thread returns the fixture messages with stable order', $thread_ok, sprintf( 'Expected %d message(s) in conversation #%d; first read=%d second read=%d.', (int) $expect['message_count'], $conversation_id, count( $first_ids ), count( $second_ids ) ) );

		// Step 7 — redaction: no raw provider identity, token or phone.
		$raw = (string) wp_json_encode( $list_data ) . (string) wp_json_encode( $thread_data );
		$redaction_violations = array();
		foreach ( array( 'psid', 'provider_contact_ref', 'zalo_uid', 'page_access_token', 'access_token' ) as $needle ) {
			if ( false !== stripos( $raw, $needle ) ) {
				$redaction_violations[] = $needle;
			}
		}
		if ( preg_match( '/\b0\d{9,10}\b/', $raw, $phone_match ) ) {
			$redaction_violations[] = 'raw_phone:' . strlen( $phone_match[0] );
		}
		$redaction_ok = empty( $redaction_violations );
		$this->emit( $ctx, $steps, 'Runtime - C payload exposes no raw provider identifier, token or phone', $redaction_ok, $redaction_ok ? 'No raw PSID/UID/token/phone field was found in the bounded C payload.' : 'Sensitive field(s) detected: ' . implode( ', ', $redaction_violations ) );

		// Step 8 — negative control on the same surface, still acting as the member.
		$foreign_ref      = '__diag_fixture_foreign_' . strtolower( substr( md5( (string) get_current_blog_id() . '|' . $ref ), 0, 10 ) );
		$foreign_response = $this->call_exact_inbox( $channel, $foreign_ref, 0 );
		$foreign_code     = (string) ( $foreign_response['data']['code'] ?? '' );
		$foreign_ok = in_array( $foreign_code, array( 'permission_denied', 'not_found', 'auth_required', 'invalid_param' ), true );
		$this->emit( $ctx, $steps, 'Runtime - an out-of-scope ref is rejected for the assigned member', $foreign_ok, $foreign_ok ? 'The same member who owns the fixture inbox still receives the explicit scope/not-found boundary for a foreign ref.' : sprintf( 'Foreign selector returned code=%s instead of an explicit denial.', $foreign_code === '' ? 'empty' : $foreign_code ) );

		// Step 9 — cleanup.
		wp_set_current_user( $this->original_user_id );
		$cleanup = BizCity_CRM_Inbox_Fixture_Factory::destroy( $this->cleanup_token );
		$this->cleanup_token = '';
		$cleanup_ok = ! empty( $cleanup['ok'] ) && (int) ( $cleanup['remaining'] ?? 0 ) === 0;
		$this->emit( $ctx, $steps, 'Cleanup - every marker-scoped row is removed', $cleanup_ok, $cleanup_ok ? 'All fixture rows were removed and none remain.' : sprintf( 'cleanup remaining=%d skipped=%s', (int) ( $cleanup['remaining'] ?? -1 ), implode( ',', (array) ( $cleanup['skipped'] ?? array() ) ) ) );

		$passed = true;
		foreach ( $steps as $step ) {
			if ( 'fail' === (string) ( $step['status'] ?? '' ) ) { $passed = false; break; }
		}
		return array(
			'status'   => $passed ? 'pass' : 'fail',
			'summary'  => $passed ? 'Twin GPT positive exact-account CRM projection passed with a disposable assigned Zone 1 inbox.' : 'Twin GPT positive exact-account CRM projection failed.',
			'error'    => $passed ? '' : 'crm_positive_projection_failed',
			'fix_hint' => $passed ? '' : 'Inspect C Inbox membership resolution, exact inbox reader, message shaping and the fixture cleanup boundary.',
			'steps'    => $steps,
		);
	}

	public function cleanup(): void {
		// [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-0.41D-D1 — the runner calls cleanup even when run() throws, so the fixture must be released here too.
		if ( $this->cleanup_token !== '' ) {
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
	 * @return array{transport:string,data:array}
	 */
	private function call_exact_inbox( string $channel, string $ref, int $conversation_id ): array {
		$request = new WP_REST_Request( 'GET', '/bizcity-twinweb/v1/crm/inbox' );
		$request->set_param( 'channel', $channel );
		$request->set_param( 'ref', $ref );
		if ( $conversation_id > 0 ) {
			$request->set_param( 'conversation_id', $conversation_id );
		}
		$result = BizCity_TwinWeb_REST::instance()->get_crm_exact_inbox( $request );
		if ( is_wp_error( $result ) ) {
			return array( 'transport' => 'wp_error', 'data' => array( 'code' => $result->get_error_code() ) );
		}
		if ( is_object( $result ) && method_exists( $result, 'get_data' ) ) {
			$data = (array) $result->get_data();
			$status = method_exists( $result, 'get_status' ) ? (int) $result->get_status() : 200;
			return array( 'transport' => (string) $status, 'data' => $data );
		}
		return array( 'transport' => 'unknown', 'data' => array() );
	}

	private function emit( $ctx, array &$steps, string $label, bool $ok, string $detail ): void {
		$step = array( 'label' => $label, 'status' => $ok ? 'pass' : 'fail', 'detail' => $detail );
		$steps[] = $step;
		if ( is_object( $ctx ) && method_exists( $ctx, 'emit_step' ) ) {
			$ctx->emit_step( $step );
		}
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $probes ) {
	$probes[] = 'BizCity_Probe_TwinWeb_CRM_Inbox_Positive_Projection';
	return $probes;
} );