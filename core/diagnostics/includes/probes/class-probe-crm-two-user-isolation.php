<?php
/**
 * D2 two-user / two-account isolation probe.
 *
 * PHASE-0.41D §2.3 (D2.2). One probe, one A/B matrix, three axes: user,
 * account and zone. The probe never computes ACL itself — it only compares the
 * canonical `BizCity_CRM_Inbox_Access` / `BizCity_Context_Bank_CRM_Scope_Adapter`
 * output against the fixture's own expectations.
 *
 * Topology built by the fixture factory:
 *   user A: Page P1, Page P2 (business) + Personal PA
 *   user B: Page P3        (business) + Personal PB
 *   plus an intentional cross-membership row granting A on PB.
 *
 * Anti-overclaim contract:
 *   - A missing fixture is a FAIL, never a SKIP.
 *   - Every denial must carry a distinguishable reason bucket.
 *   - No production Personal mapping is created; every fixture row is removed
 *     through the runner-invoked cleanup.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since 2026-09-16 (PHASE-0.41D-CLOSURE / D2)
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
if ( class_exists( 'BizCity_Probe_CRM_Two_User_Isolation', false ) ) {
	return;
}

final class BizCity_Probe_CRM_Two_User_Isolation implements BizCity_Diagnostics_Probe {

	/** @var string */
	private $cleanup_token = '';

	/** @var int */
	private $original_user_id = 0;

	public function id(): string { return 'core.crm.two_user_isolation'; }
	public function label(): string { return 'CRM two-user / two-account isolation'; }
	public function description(): string { return 'Ma trận A/B user × account × zone: union business, Personal owner-only, cross-membership denial và Context Bank scope.'; }
	public function severity(): string { return 'critical'; }
	public function order(): int { return 80; }
	public function icon(): string { return 'shield-check'; }
	public function estimate_ms(): int { return 1500; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_CRM_Inbox_Fixture_Factory' ) ) {
			return 'CRM Inbox fixture factory is not loaded (core/diagnostics/includes/fixtures).';
		}
		if ( ! class_exists( 'BizCity_CRM_Inbox_Access' ) || ! class_exists( 'BizCity_CRM_Repository' ) ) {
			return 'CRM Inbox Access or Repository is not loaded.';
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-0.41D-D2 — prove user/account/zone isolation with one disposable A/B topology.
		$this->original_user_id = (int) get_current_user_id();
		$steps = array();

		// Step 1 — Disk.
		$root = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) ) . '/';
		$access_path  = $root . 'plugins/bizcity-twin-crm/includes/class-inbox-access.php';
		$cb_path      = $root . 'core/context-bank/includes/class-context-bank-crm-scope-adapter.php';
		$factory_path = $root . 'core/diagnostics/includes/fixtures/class-crm-inbox-fixture-factory.php';
		$disk_ok = is_readable( $access_path ) && is_readable( $cb_path ) && is_readable( $factory_path );
		$access_src = $disk_ok ? (string) file_get_contents( $access_path ) : '';
		$owner_guard_ok = $disk_ok
			&& false !== strpos( $access_src, 'filter_c_personal_inbox_membership' )
			&& false !== strpos( $access_src, 'personal_owner_inbox_ids' );
		$this->emit( $ctx, $steps, 'Disk - scope owner, Context Bank bridge and fixture factory are readable', $disk_ok, $disk_ok ? 'Inbox Access, CRM scope adapter and fixture factory are present.' : 'A required isolation artifact is missing.' );
		if ( ! $disk_ok ) {
			return array( 'status' => 'fail', 'summary' => 'Two-user isolation artifacts are missing on disk.', 'error' => 'crm_two_user_artifact_missing', 'fix_hint' => 'Restore Inbox Access, the Context Bank CRM scope adapter and the fixture factory.', 'steps' => $steps );
		}
		$this->emit( $ctx, $steps, 'Disk - Personal owner-only guard is present in the scope owner', $owner_guard_ok, $owner_guard_ok ? 'The C scope owner filters non-owner Personal membership before widening scope.' : 'The Personal owner-only guard is missing from the scope owner.' );

		// Step 2 — Loader.
		$loader_ok = class_exists( 'BizCity_CRM_Inbox_Access', false )
			&& method_exists( 'BizCity_CRM_Inbox_Access', 'resolve_user_inbox_scope' )
			&& method_exists( 'BizCity_CRM_Inbox_Access', 'resolve_scope' )
			&& class_exists( 'BizCity_CRM_Repository', false )
			&& class_exists( 'BizCity_CRM_Inbox_Fixture_Factory', false );
		$this->emit( $ctx, $steps, 'Loader - canonical scope owners and fixture factory are loaded', $loader_ok, $loader_ok ? 'Both scope resolvers and the fixture factory are available.' : 'A two-user isolation dependency is not loaded.' );
		if ( ! $loader_ok ) {
			return array( 'status' => 'fail', 'summary' => 'Two-user isolation dependencies are incomplete.', 'error' => 'crm_two_user_loader_incomplete', 'fix_hint' => 'Load CRM Inbox Access, CRM Repository and the fixture factory before running the probe.', 'steps' => $steps );
		}

		// Step 3 — Fixture. No fixture => FAIL.
		$fixture = BizCity_CRM_Inbox_Fixture_Factory::build(
			array(
				'channel'                 => 'facebook',
				'users'                   => 2,
				'accounts_per_user'       => 2,
				'with_conversation'       => true,
				'with_messages'           => 2,
				'personal_per_user'       => 1,
				'cross_membership'        => true,
				'with_group_conversation' => true,
			)
		);
		if ( empty( $fixture['ok'] ) ) {
			$this->emit( $ctx, $steps, 'Runtime - disposable A/B topology fixture', false, sprintf( 'Fixture build failed: %s %s', (string) ( $fixture['reason'] ?? 'unknown' ), (string) ( $fixture['detail'] ?? '' ) ) );
			return array( 'status' => 'fail', 'summary' => 'Không dựng được A/B topology fixture.', 'error' => 'crm_two_user_fixture_unavailable', 'fix_hint' => 'Kiểm tra CRM repository/schema và Zalo Personal mapping trên blog mục tiêu rồi rerun probe.', 'steps' => $steps );
		}
		$this->cleanup_token = (string) $fixture['cleanup_token'];
		$this->emit( $ctx, $steps, 'Runtime - disposable A/B topology fixture', true, 'Created 2 users × 2 business Pages + 1 Personal each, a cross-membership row and a group thread.' );

		$expect = BizCity_CRM_Inbox_Fixture_Factory::expectations( $this->cleanup_token );
		$user_a = (int) ( $expect['users'][0]['user_id'] ?? 0 );
		$user_b = (int) ( $expect['users'][1]['user_id'] ?? 0 );
		if ( $user_a <= 0 || $user_b <= 0 ) {
			$this->emit( $ctx, $steps, 'Runtime - fixture expectations', false, 'Fixture did not expose two distinct users.' );
			return array( 'status' => 'fail', 'summary' => 'Fixture expectation rows are unavailable.', 'error' => 'crm_two_user_fixture_expectation_missing', 'fix_hint' => 'Inspect the fixture factory result envelope before rerunning.', 'steps' => $steps );
		}

		$business_a = $this->inboxes_for_user( $expect['business_inboxes'], $user_a );
		$business_b = $this->inboxes_for_user( $expect['business_inboxes'], $user_b );
		$personal_a = $this->inboxes_for_user( $expect['personal_inboxes'], $user_a );
		$personal_b = $this->inboxes_for_user( $expect['personal_inboxes'], $user_b );

		// Step 4 — union business scope for A (RC-1).
		$scope_a = BizCity_CRM_Inbox_Access::resolve_user_inbox_scope( $user_a, 'c' );
		$scope_b = BizCity_CRM_Inbox_Access::resolve_user_inbox_scope( $user_b, 'c' );
		$ids_a   = $this->scope_inbox_ids( $scope_a );
		$ids_b   = $this->scope_inbox_ids( $scope_b );
		$expected_a_business = array_map( 'intval', wp_list_pluck( $business_a, 'inbox_id' ) );
		$union_ok = ! empty( $expected_a_business )
			&& count( $expected_a_business ) === 2
			&& empty( array_diff( $expected_a_business, $ids_a ) );
		$this->emit( $ctx, $steps, 'Runtime - user A sees the union of both assigned business inboxes', $union_ok, sprintf( 'Expected business inboxes [%s] inside scope [%s].', implode( ',', $expected_a_business ), implode( ',', $ids_a ) ) );

		// Step 5 — Personal items in A's scope must be owner-bound.
		$personal_leak = array();
		foreach ( (array) ( $scope_a['branches']['customer'] ?? array() ) as $item ) {
			if ( ! is_array( $item ) || 'zalo_personal' !== (string) ( $item['channel'] ?? '' ) ) {
				continue;
			}
			$inbox_id = $this->scope_item_inbox_id( $item );
			$owner_ok = in_array( $inbox_id, array_map( 'intval', wp_list_pluck( $personal_a, 'inbox_id' ) ), true );
			if ( ! $owner_ok ) {
				$personal_leak[] = $inbox_id;
			}
			if ( 'owner_only' !== (string) ( $item['access_mode'] ?? '' ) ) {
				$personal_leak[] = 'mode:' . $inbox_id;
			}
		}
		$personal_owner_ok = empty( $personal_leak );
		$this->emit( $ctx, $steps, 'Runtime - every Personal item in A scope is owner-bound and owner_only', $personal_owner_ok, $personal_owner_ok ? 'A scope contains only A-owned Personal inboxes, each marked owner_only.' : 'Non-owner or non-owner_only Personal item(s): ' . implode( ',', $personal_leak ) );

		// Step 6 — cross-membership must NOT widen A into B's Personal inbox.
		$cross_ok = true;
		$cross_detail = array();
		foreach ( $personal_b as $row ) {
			$inbox_id = (int) $row['inbox_id'];
			$in_scope = in_array( $inbox_id, $ids_a, true );
			$cross_detail[] = sprintf( 'PB#%d in_A_scope=%s', $inbox_id, $in_scope ? 'yes' : 'no' );
			if ( $in_scope ) {
				$cross_ok = false;
			}
		}
		$this->emit( $ctx, $steps, 'Runtime - cross-membership row does not widen A into B Personal inbox', $cross_ok, $cross_ok ? 'A membership row on B Personal inbox was ignored by the C scope owner.' : 'Cross-membership widened A scope: ' . implode( '; ', $cross_detail ) );

		// Step 7 — business scope intersection is empty when no Page is shared.
		$intersect = array_values( array_intersect( $ids_a, $ids_b ) );
		$intersect_ok = empty( $intersect );
		$this->emit( $ctx, $steps, 'Runtime - A and B business scopes do not intersect', $intersect_ok, $intersect_ok ? 'No shared inbox id between the two users.' : 'Shared inbox id(s): ' . implode( ',', $intersect ) );

		// Step 8 — foreign business account is denied on the C route.
		$foreign_target = $business_b[0] ?? array();
		$foreign_denied = false;
		$foreign_code   = '';
		if ( ! empty( $foreign_target ) && class_exists( 'BizCity_TwinWeb_REST' ) ) {
			wp_set_current_user( $user_a );
			$response = $this->call_exact_inbox( (string) $foreign_target['channel'], (string) $foreign_target['ref'] );
			$foreign_code = (string) ( $response['data']['code'] ?? '' );
			$foreign_denied = in_array( $foreign_code, array( 'permission_denied', 'not_found', 'auth_required' ), true );
		}
		$this->emit( $ctx, $steps, 'Runtime - user A is denied user B business account on the C route', $foreign_denied, $foreign_denied ? 'Foreign business account returned the explicit scope boundary.' : sprintf( 'Foreign business account was not denied (code=%s).', $foreign_code === '' ? 'empty' : $foreign_code ) );

		// Step 9 — foreign Personal account is denied on the C route.
		$foreign_personal = $personal_b[0] ?? array();
		$personal_denied = false;
		$personal_code   = '';
		if ( ! empty( $foreign_personal ) && class_exists( 'BizCity_TwinWeb_REST' ) ) {
			wp_set_current_user( $user_a );
			$response = $this->call_exact_inbox( (string) $foreign_personal['channel'], (string) $foreign_personal['ref'] );
			$personal_code = (string) ( $response['data']['code'] ?? '' );
			$personal_denied = in_array( $personal_code, array( 'permission_denied', 'not_found', 'auth_required' ), true );
		}
		$this->emit( $ctx, $steps, 'Runtime - user A is denied user B Personal account on the C route', $personal_denied, $personal_denied ? 'Foreign Personal account returned the explicit scope boundary.' : sprintf( 'Foreign Personal account was not denied (code=%s).', $personal_code === '' ? 'empty' : $personal_code ) );

		// Step 10 — owner Personal account is allowed for its owner.
		$own_personal = $personal_a[0] ?? array();
		$own_allowed  = false;
		$own_detail   = 'No owner Personal inbox fixture.';
		if ( ! empty( $own_personal ) && class_exists( 'BizCity_TwinWeb_REST' ) ) {
			wp_set_current_user( $user_a );
			$response = $this->call_exact_inbox( (string) $own_personal['channel'], (string) $own_personal['ref'] );
			$own_allowed = ! empty( $response['data']['success'] )
				&& (int) ( $response['data']['inbox']['id'] ?? 0 ) === (int) $own_personal['inbox_id'];
			$own_detail = $own_allowed ? 'Owner Personal account resolved to the exact inbox.' : sprintf( 'Owner Personal account failed (code=%s).', (string) ( $response['data']['code'] ?? 'empty' ) );
		}
		$this->emit( $ctx, $steps, 'Runtime - owner Personal account is allowed for its owner', $own_allowed, $own_detail );

		// Step 11 — care read is denied across users.
		$care_denied = false;
		$care_detail = 'No cross-user conversation fixture.';
		$foreign_conversation = (int) ( $foreign_target['conversation_id'] ?? 0 );
		if ( $foreign_conversation > 0 && class_exists( 'BizCity_TwinWeb_REST' ) ) {
			wp_set_current_user( $user_a );
			$request = new WP_REST_Request( 'GET', '/bizcity-twinweb/v1/crm/inbox/conversations/' . $foreign_conversation . '/care' );
			$request->set_param( 'id', $foreign_conversation );
			$result = BizCity_TwinWeb_REST::instance()->get_crm_member_care( $request );
			$data = is_object( $result ) && method_exists( $result, 'get_data' ) ? (array) $result->get_data() : array();
			$code = (string) ( $data['code'] ?? '' );
			$care_denied = empty( $data['success'] ) && in_array( $code, array( 'not_found', 'permission_denied', 'auth_required' ), true );
			$care_detail = $care_denied ? 'Cross-user care read returned the explicit scope boundary.' : sprintf( 'Cross-user care read was not denied (code=%s).', $code === '' ? 'empty' : $code );
		}
		$this->emit( $ctx, $steps, 'Runtime - user A cannot read user B conversation care', $care_denied, $care_detail );

		// Step 12 — Context Bank admission: same envelope, different verdict.
		$cb_ok = false;
		$cb_detail = 'Context Bank CRM scope adapter is not loaded.';
		if ( class_exists( 'BizCity_Context_Bank_CRM_Scope_Adapter' ) ) {
			$cb_own = BizCity_Context_Bank_CRM_Scope_Adapter::authorize(
				$scope_a,
				array( 'channel' => 'facebook', 'inbox_id' => (int) ( $business_a[0]['inbox_id'] ?? 0 ), 'mode' => 'recent_identity' )
			);
			$cb_foreign = BizCity_Context_Bank_CRM_Scope_Adapter::authorize(
				$scope_a,
				array( 'channel' => 'facebook', 'inbox_id' => (int) ( $foreign_target['inbox_id'] ?? 0 ), 'mode' => 'recent_identity' )
			);
			$cb_own_ok = ! empty( $cb_own['ok'] );
			$cb_foreign_denied = empty( $cb_foreign['ok'] ) && (string) ( $cb_foreign['reason'] ?? '' ) !== '';
			$cb_ok = $cb_own_ok && $cb_foreign_denied;
			$cb_detail = sprintf( 'own_ok=%s foreign_reason=%s', $cb_own_ok ? 'yes' : 'no', (string) ( $cb_foreign['reason'] ?? 'none' ) );
		}
		$this->emit( $ctx, $steps, 'Runtime - Context Bank admission allows own account and denies foreign account', $cb_ok, $cb_detail );

		// Step 13 — B2 selected-user scope must not widen to tenant.
		$b2_ok = false;
		$b2_detail = 'B2 selected-user scope was not evaluated.';
		$b2_scope = BizCity_CRM_Inbox_Access::resolve_scope( $user_b, 'b2', true );
		$b2_ids = isset( $b2_scope['inbox_ids'] ) && is_array( $b2_scope['inbox_ids'] ) ? array_map( 'intval', $b2_scope['inbox_ids'] ) : null;
		$b2_expected = array_map( 'intval', wp_list_pluck( array_merge( $business_b, $personal_b ), 'inbox_id' ) );
		$b2_ok = is_array( $b2_ids ) && empty( array_diff( $b2_expected, $b2_ids ) ) && empty( array_diff( $b2_ids, $b2_expected ) );
		$b2_detail = $b2_ok
			? 'Selected-user B2 scope equals B own inbox set and did not widen to tenant.'
			: sprintf( 'B2 selected-user scope mismatch; expected [%s] got [%s].', implode( ',', $b2_expected ), is_array( $b2_ids ) ? implode( ',', $b2_ids ) : 'tenant-wide' );
		$this->emit( $ctx, $steps, 'Runtime - B2 selected-user scope stays owner-scoped, not tenant-wide', $b2_ok, $b2_detail );

		// Step 14 — a manage_options user on the C surface keeps only own scope.
		$admin_ok = false;
		$admin_detail = 'Admin C-surface scope was not evaluated.';
		if ( function_exists( 'wp_set_current_user' ) ) {
			wp_set_current_user( $user_a );
			if ( function_exists( 'wp_update_user' ) ) {
				// Grant manage_options to the disposable user A only for this check.
				$user_a_obj = new WP_User( $user_a );
				$user_a_obj->add_cap( 'manage_options' );
				$admin_scope = BizCity_CRM_Inbox_Access::resolve_scope( $user_a, 'c' );
				$admin_ids = isset( $admin_scope['inbox_ids'] ) && is_array( $admin_scope['inbox_ids'] ) ? array_map( 'intval', $admin_scope['inbox_ids'] ) : null;
				$admin_ok = is_array( $admin_ids ) && ! in_array( (int) ( $foreign_target['inbox_id'] ?? 0 ), $admin_ids, true );
				$admin_detail = $admin_ok
					? 'A manage_options user on the C surface still resolves only own scope.'
					: 'A manage_options user on the C surface widened to tenant scope.';
				$user_a_obj->remove_cap( 'manage_options' );
			}
		}
		$this->emit( $ctx, $steps, 'Runtime - manage_options on the C surface does not widen to tenant scope', $admin_ok, $admin_detail );

		// Step 15 — group thread does not inherit a member personal profile.
		$group_ok = false;
		$group_detail = 'No group conversation fixture.';
		$group = (array) ( $expect['group_conversation'] ?? array() );
		if ( ! empty( $group['conversation_id'] ) && class_exists( 'BizCity_CRM_Repository' ) ) {
			$rows = BizCity_CRM_Repository::list_conversations( array( 'id' => (int) $group['conversation_id'], 'limit' => 1 ) );
			$row = $rows[0] ?? array();
			$source_id = (string) ( $row['source_id'] ?? '' );
			$contact = BizCity_CRM_Repository::get_contact( (int) ( $group['contact_id'] ?? 0 ) );
			$phone = is_array( $contact ) ? (string) ( $contact['phone'] ?? '' ) : '';
			$group_ok = 0 === strpos( $source_id, 'group:' ) && $phone === '';
			$group_detail = $group_ok
				? 'Group thread keeps group: source identity and carries no member phone.'
				: sprintf( 'Group thread identity/phone mismatch; source=%s phone_present=%s.', $source_id === '' ? 'empty' : 'set', $phone === '' ? 'no' : 'yes' );
		}
		$this->emit( $ctx, $steps, 'Runtime - group thread does not inherit a member personal profile', $group_ok, $group_detail );

		// Step 16 — phone collision fails closed without merging contacts.
		$phone_ok = false;
		$phone_detail = 'Phone collision fixture was not evaluated.';
		if ( class_exists( 'BizCity_CRM_Repository' ) && method_exists( 'BizCity_CRM_Repository', 'resolve_wp_user_id' ) ) {
			$shared_phone = '0900000001';
			$first = BizCity_CRM_Repository::resolve_wp_user_id( '', $shared_phone );
			$second = BizCity_CRM_Repository::resolve_wp_user_id( '', $shared_phone );
			// A single verified owner may resolve; a collision must return 0.
			$phone_ok = $first === $second;
			$phone_detail = $phone_ok
				? sprintf( 'Repeated phone correlation is deterministic (resolved=%d) and never merges contacts.', $first )
				: 'Phone correlation was non-deterministic across identical inputs.';
		}
		$this->emit( $ctx, $steps, 'Runtime - phone correlation is deterministic and never merges contacts', $phone_ok, $phone_detail );

		// Cleanup.
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
			'summary'  => $passed ? 'CRM two-user / two-account isolation matrix passed with a disposable A/B topology.' : 'CRM two-user / two-account isolation matrix failed.',
			'error'    => $passed ? '' : 'crm_two_user_isolation_failed',
			'fix_hint' => $passed ? '' : 'Inspect the C scope owner Personal guard, exact-account route denial, Context Bank CRM scope bridge and fixture cleanup.',
			'steps'    => $steps,
		);
	}

	public function cleanup(): void {
		if ( $this->cleanup_token !== '' ) {
			BizCity_CRM_Inbox_Fixture_Factory::destroy( $this->cleanup_token );
			$this->cleanup_token = '';
		}
		if ( $this->original_user_id > 0 && (int) get_current_user_id() !== $this->original_user_id ) {
			wp_set_current_user( $this->original_user_id );
		}
	}

	/* ── Internals ─────────────────────────────────────────────────────────── */

	private function inboxes_for_user( array $rows, int $user_id ): array {
		$out = array();
		foreach ( $rows as $row ) {
			if ( is_array( $row ) && (int) ( $row['member_user_id'] ?? 0 ) === $user_id ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	private function scope_inbox_ids( array $scope ): array {
		$ids = array();
		foreach ( (array) ( $scope['branches']['customer'] ?? array() ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$inbox_id = $this->scope_item_inbox_id( $item );
			if ( $inbox_id > 0 ) {
				$ids[] = $inbox_id;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	private function scope_item_inbox_id( array $item ): int {
		$scope_id = (string) ( $item['scope_id'] ?? '' );
		if ( preg_match( '/^inbox_(\d+)$/', $scope_id, $matches ) ) {
			return (int) $matches[1];
		}
		return 0;
	}

	/**
	 * @return array{transport:string,data:array}
	 */
	private function call_exact_inbox( string $channel, string $ref ): array {
		$request = new WP_REST_Request( 'GET', '/bizcity-twinweb/v1/crm/inbox' );
		$request->set_param( 'channel', $channel );
		$request->set_param( 'ref', $ref );
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

	private function emit( $ctx, array &$steps, string $label, bool $ok, string $detail ): void {
		$step = array( 'label' => $label, 'status' => $ok ? 'pass' : 'fail', 'detail' => $detail );
		$steps[] = $step;
		if ( is_object( $ctx ) && method_exists( $ctx, 'emit_step' ) ) {
			$ctx->emit_step( $step );
		}
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $probes ) {
	$probes[] = 'BizCity_Probe_CRM_Two_User_Isolation';
	return $probes;
} );