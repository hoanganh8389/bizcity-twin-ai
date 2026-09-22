<?php
/**
 * DDV probe for PHASE-0.50 C-06 — one user may own N Zalo Personal numbers (G1/R-LM-2), and
 * "chuyển phụ trách khách" never hands a colleague's personal thread over (§14, R-ZP-OWNER §E4.2-3).
 *
 * Side effects: one disposable subscriber user (deleted in cleanup) and one refused transfer call.
 * No Zalo account, conversation, contact or task row is created — the refusal path writes nothing.
 *
 * @package BizCity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 */

defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'BizCity_Diagnostics_Probe' ) || class_exists( 'BizCity_Probe_Channel_Personal_Multi_Account_Owner', false ) ) {
	return;
}

final class BizCity_Probe_Channel_Personal_Multi_Account_Owner implements BizCity_Diagnostics_Probe {

	/** @var array<int,int> */
	private $fixture_users = array();

	public function id(): string {
		return 'core.channel.personal_multi_account_owner';
	}

	public function label(): string {
		return 'Zalo Personal: N numbers per user + customer transfer guard';
	}

	public function description(): string {
		return 'Checks that the Personal primary limit is a plan quota (not a hard 1), that the account and inbox-scope layers agree on N numbers, and that transferring a customer refuses a receiver who does not own the inbox.';
	}

	public function severity(): string { return 'critical'; }
	public function order(): int { return 69; }
	public function icon(): string { return 'smartphone'; }
	public function estimate_ms(): int { return 300; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_Channel_User_Grant' ) ) {
			return new WP_Error( 'channel_user_grant_missing', 'Channel user grant service is not loaded.' );
		}
		if ( ! class_exists( 'BizCity_CRM_Contact_Transfer' ) ) {
			return new WP_Error( 'crm_contact_transfer_missing', 'CRM contact transfer service is not loaded (PHASE-0.50 §14).' );
		}
		if ( ! function_exists( 'get_current_user_id' ) || (int) get_current_user_id() <= 0 || ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'personal_multi_account_operator_missing', 'An authenticated tenant administrator is required.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		global $wpdb;
		$base = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( dirname( dirname( dirname( __DIR__ ) ) ) );

		// 1. G1 — the grant layer counts Personal primaries against a plan quota instead of forbidding a second one.
		$grant_source = is_readable( $base . '/core/channel-gateway/includes/class-channel-user-grant.php' )
			? (string) file_get_contents( $base . '/core/channel-gateway/includes/class-channel-user-grant.php' )
			: '';
		$quota_api_ok = method_exists( 'BizCity_Channel_User_Grant', 'personal_account_quota' )
			&& '' !== $grant_source
			&& false === strpos( $grant_source, 'personal_primary_limit_reached' )
			&& false !== strpos( $grant_source, 'personal_account_quota_reached' );

		// 2. The quota is plan-driven: with no filter it is unlimited (0), and a filter can bound it.
		$quota_default = (int) BizCity_Channel_User_Grant::personal_account_quota( (int) get_current_user_id() );
		$quota_filter = static function () { return 3; };
		add_filter( 'bizcity_channel_personal_accounts_per_user', $quota_filter, 99 );
		$quota_filtered = (int) BizCity_Channel_User_Grant::personal_account_quota( (int) get_current_user_id() );
		remove_filter( 'bizcity_channel_personal_accounts_per_user', $quota_filter, 99 );
		$quota_ok = 0 === $quota_default && 3 === $quota_filtered;

		// 3. Account layer: `owner_user_id` already allows N numbers per user; nobody is capped at one.
		$accounts_api_ok = class_exists( 'BizCity_Zalo_Mapping_Repo' ) && method_exists( 'BizCity_Zalo_Mapping_Repo', 'list_personal_accounts_for_owner' );
		$max_owned = 0;
		$owner_rows = 0;
		if ( isset( $wpdb->prefix ) ) {
			$table = $wpdb->prefix . 'bizcity_zalo_accounts';
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
				$rows = (array) $wpdb->get_results( "SELECT owner_user_id, COUNT(*) AS n FROM `{$table}` WHERE kind = 'personal' AND owner_user_id > 0 GROUP BY owner_user_id", ARRAY_A );
				$owner_rows = count( $rows );
				foreach ( $rows as $row ) { $max_owned = max( $max_owned, (int) $row['n'] ); }
			}
		}

		// 4. Inbox scope of a user with N numbers lists every one of those inboxes (R-LM-2 + user-inbox-scope).
		$scope_ok = true;
		$scope_detail = 'No employee owns a Personal number yet — nothing to compare.';
		if ( $accounts_api_ok && class_exists( 'BizCity_CRM_Inbox_Access' ) && $max_owned > 1 && isset( $wpdb->prefix ) ) {
			$table = $wpdb->prefix . 'bizcity_zalo_accounts';
			$owner_id = (int) $wpdb->get_var( "SELECT owner_user_id FROM `{$table}` WHERE kind = 'personal' AND owner_user_id > 0 GROUP BY owner_user_id ORDER BY COUNT(*) DESC LIMIT 1" );
			$account_inboxes = array_values( array_filter( array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT crm_inbox_id FROM `{$table}` WHERE kind = 'personal' AND owner_user_id = %d", $owner_id ) ) ) ) );
			$scope = BizCity_CRM_Inbox_Access::resolve_scope( $owner_id, 'be', true );
			$scope_inboxes = array_map( 'intval', (array) ( $scope['inbox_ids'] ?? array() ) );
			$missing = array_diff( $account_inboxes, $scope_inboxes );
			$scope_ok = empty( $missing );
			$scope_detail = $scope_ok
				? 'User #' . $owner_id . ' owns ' . count( $account_inboxes ) . ' Personal inboxes and the scope resolver returns all of them.'
				: 'User #' . $owner_id . ' owns inboxes the scope resolver drops: ' . implode( ', ', array_map( 'intval', $missing ) );
		}

		// 5. The transfer route exists (W1/W2 bulk action).
		$routes = function_exists( 'rest_get_server' ) ? (array) rest_get_server()->get_routes() : array();
		$transfer_route_ok = isset( $routes['/bizcity-crm/v1/crm-contacts/transfer-owner'] );

		// 6. Behaviour: a receiver who owns no inbox is refused, and nothing is reassigned.
		$stranger_id = $this->create_user( 'transfer_target' );
		$transfer = BizCity_CRM_Contact_Transfer::transfer( (int) get_current_user_id(), array(
			'to_user_id'  => $stranger_id,
			'contact_ids' => array( 999999999 ),
		) );
		$refusal_code = is_wp_error( $transfer ) ? (string) $transfer->get_error_code() : '';
		$refusal_ok = in_array( $refusal_code, array( 'transfer_target_out_of_scope', 'assignee_invalid', 'member_not_manageable' ), true );

		// 7. The service reads the receiver's OWN scope, never the actor's widened one (R-ZP-OWNER §E4.2-3).
		$transfer_source = is_readable( $base . '/plugins/bizcity-twin-crm/includes/class-contact-transfer.php' )
			? (string) file_get_contents( $base . '/plugins/bizcity-twin-crm/includes/class-contact-transfer.php' )
			: '';
		$own_scope_ok = '' !== $transfer_source
			&& false !== strpos( $transfer_source, 'BizCity_CRM_Task_Handoff::user_inbox_ids' )
			&& false !== strpos( $transfer_source, 'set_conversation_assignee' );

		$checks = array(
			array( 'label' => 'Personal primary limit is a plan quota, not a hard 1', 'ok' => $quota_api_ok, 'detail' => $quota_api_ok ? 'The grant service refuses with personal_account_quota_reached and no longer carries personal_primary_limit_reached.' : 'The old one-primary-per-user refusal is still in the grant service (G1 not closed).' ),
			array( 'label' => 'Quota is unlimited by default and bounded by the plan filter', 'ok' => $quota_ok, 'detail' => $quota_ok ? 'Default quota is 0 (unlimited); bizcity_channel_personal_accounts_per_user can bound it.' : 'Default quota=' . $quota_default . ', filtered quota=' . $quota_filtered . ' — expected 0 then 3.' ),
			array( 'label' => 'Account layer exposes N Personal numbers per owner', 'ok' => $accounts_api_ok, 'detail' => $accounts_api_ok ? 'list_personal_accounts_for_owner() exists; ' . $owner_rows . ' owner(s), max ' . $max_owned . ' number(s) on this site.' : 'BizCity_Zalo_Mapping_Repo::list_personal_accounts_for_owner() is missing.' ),
			array( 'label' => 'Inbox scope returns every number the user owns', 'ok' => $scope_ok, 'detail' => $scope_detail ),
			array( 'label' => 'Customer transfer route is registered', 'ok' => $transfer_route_ok, 'detail' => $transfer_route_ok ? 'POST /bizcity-crm/v1/crm-contacts/transfer-owner is served.' : 'The transfer-owner route is not registered.' ),
			array( 'label' => 'Transfer refuses a receiver without the inbox', 'ok' => $refusal_ok, 'detail' => $refusal_ok ? 'A user who owns no inbox cannot receive customers (code=' . $refusal_code . ').' : 'Unexpected transfer result: code=' . sanitize_key( $refusal_code ) ),
			array( 'label' => 'Transfer resolves the receiver own scope', 'ok' => $own_scope_ok, 'detail' => $own_scope_ok ? 'The service checks the receiver scope, then moves conversations through the repository.' : 'The transfer service does not read the receiver own inbox scope.' ),
		);

		$pass = true;
		foreach ( $checks as $check ) {
			$ctx->emit_step( array( 'label' => $check['label'], 'status' => $check['ok'] ? 'pass' : 'fail', 'detail' => $check['detail'] ) );
			$pass = $pass && $check['ok'];
		}

		return array(
			'status'   => $pass ? 'pass' : 'fail',
			'summary'  => $pass ? 'One user may own N Zalo Personal numbers, and customer transfer stays inside the receiver own inbox scope.' : 'Personal multi-account ownership or the customer transfer guard failed.',
			'fix_hint' => $pass ? '' : 'Keep the Personal limit plan-driven (R-LM-2) and never hand a colleague Personal thread over without a phone transfer (R-ZP-OWNER).',
			'steps'    => array(),
		);
	}

	public function cleanup(): void {
		foreach ( $this->fixture_users as $user_id ) {
			if ( function_exists( 'wp_delete_user' ) ) { wp_delete_user( (int) $user_id ); }
		}
		$this->fixture_users = array();
	}

	private function create_user( string $label ): int {
		$suffix = strtolower( substr( md5( (string) microtime( true ) . '|' . wp_generate_uuid4() ), 0, 12 ) );
		$user_id = wp_insert_user( array(
			'user_login' => 'bzc_probe_' . $label . '_' . $suffix,
			'user_email' => 'bzc_probe_' . $label . '_' . $suffix . '@example.invalid',
			'user_pass'  => wp_generate_password( 24, true, true ),
			'role'       => 'subscriber',
		) );
		if ( is_wp_error( $user_id ) ) { return 0; }
		$this->fixture_users[] = (int) $user_id;
		return (int) $user_id;
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Channel_Personal_Multi_Account_Owner';
	return $list;
} );
