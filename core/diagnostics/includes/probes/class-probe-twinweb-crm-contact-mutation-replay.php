<?php
/**
 * Focused C-surface probe for contact-facts and add-customer mutation replay.
 *
 * Uses one disposable member-scoped WebChat Inbox and never calls a provider.
 * The fixture is removed by cleanup() after every result.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since 2026-09-12 (PHASE-0.48C-RC6-RC9)
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
if ( class_exists( 'BizCity_Probe_TwinWeb_CRM_Contact_Mutation_Replay', false ) ) {
	return;
}

final class BizCity_Probe_TwinWeb_CRM_Contact_Mutation_Replay implements BizCity_Diagnostics_Probe {

	private $fixture_inbox_id = 0;
	private $fixture_contact_id = 0;
	private $fixture_conversation_id = 0;
	private $created_contact_ids = array();

	public function id(): string { return 'modules.twin_gpt.crm_contact_mutation_replay'; }
	public function label(): string { return 'Twin GPT contact mutation replay'; }
	public function description(): string { return 'Verifies C contact-facts replay/conflict and exact-scope add-customer replay without provider transport.'; }
	public function severity(): string { return 'critical'; }
	public function order(): int { return 73; }
	public function icon(): string { return 'user-plus'; }
	public function estimate_ms(): int { return 500; }
	public function precondition() {
		if ( ! class_exists( 'BizCity_TwinWeb_REST' ) || ! class_exists( 'BizCity_CRM_Repository' ) || ! class_exists( 'BizCity_CRM_Team_Manager' ) || ! class_exists( 'BizCity_CRM_Inbox_Access' ) || ! class_exists( 'BizCity_Twin_Mutation_Store' ) ) {
			return 'TwinWeb, CRM Repository, Team Manager, Inbox Access or mutation store is not loaded.';
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-09-12 12:10 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.48C-RC6-RC9 — prove contact-facts and add-customer replay with one disposable C fixture.
		$steps = array();
		$user_id = (int) get_current_user_id();
		if ( $user_id <= 0 ) {
			return array( 'status' => 'skip', 'summary' => 'An authenticated C operator is required for the contact mutation fixture.', 'fix_hint' => 'Run the focused probe with an authenticated diagnostics user.', 'steps' => array() );
		}
		$fixture = $this->create_fixture( $user_id );
		if ( ! is_array( $fixture ) ) {
			$this->emit( $ctx, $steps, 'Runtime - disposable contact mutation fixture', false, 'Could not create the scoped Inbox/contact/conversation fixture.' );
			return array( 'status' => 'fail', 'summary' => 'Contact mutation fixture creation failed.', 'error' => 'fixture_create_failed', 'fix_hint' => 'Verify CRM Inbox schema, membership owner and repository fixture APIs.', 'steps' => $steps );
		}
		$this->emit( $ctx, $steps, 'Runtime - disposable contact mutation fixture', true, 'Created a diagnostic WebChat Inbox, member row, contact and conversation; cleanup runs after the probe.' );

		if ( function_exists( 'did_action' ) && function_exists( 'do_action' ) && ! did_action( 'rest_api_init' ) ) {
			// [2026-09-12 12:10 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.48C-RC6-RC9 — complete the nested REST lifecycle before delegating to CRM owners.
			do_action( 'rest_api_init' );
		}

		$facts_key = 'diag-contact-facts-' . strtolower( str_replace( '-', '', wp_generate_uuid4() ) );
		$facts_body = array( 'phone' => '0901234567', 'email' => 'facts-' . strtolower( str_replace( '-', '', wp_generate_uuid4() ) ) . '@example.test', 'idempotency_key' => $facts_key );
		$first_facts = $this->call_contact_facts( $facts_body );
		$second_facts = $this->call_contact_facts( $facts_body );
		$changed_facts = $facts_body;
		$changed_facts['email'] = 'changed-' . strtolower( str_replace( '-', '', wp_generate_uuid4() ) ) . '@example.test';
		$conflict_facts = $this->call_contact_facts( $changed_facts );
		$first_data = $this->response_data( $first_facts );
		$second_data = $this->response_data( $second_facts );
		$conflict_data = $this->response_data( $conflict_facts );
		$facts_ok = ! empty( $first_data['success'] )
			&& empty( $first_data['idempotency_replayed'] )
			&& ! empty( $second_data['success'] )
			&& ! empty( $second_data['idempotency_replayed'] )
			&& empty( $conflict_data['success'] )
			&& 'invalid_param' === (string) ( $conflict_data['code'] ?? '' );
		$this->emit( $ctx, $steps, 'Runtime - contact facts replay and changed-payload conflict', $facts_ok, sprintf( 'first=%s; replay=%s; conflict=%s', $this->result_code( $first_data ), $this->result_code( $second_data ), $this->result_code( $conflict_data ) ) );

		$create_key = 'diag-contact-create-' . strtolower( str_replace( '-', '', wp_generate_uuid4() ) );
		$create_body = array( 'channel' => 'webchat', 'ref' => $fixture['ref'], 'name' => 'Diagnostics created contact', 'email' => 'created-' . strtolower( str_replace( '-', '', wp_generate_uuid4() ) ) . '@example.test', 'idempotency_key' => $create_key );
		$first_create = $this->call_create_contact( $create_body );
		$second_create = $this->call_create_contact( $create_body );
		$foreign_body = $create_body;
		$foreign_body['ref'] = 'foreign-' . strtolower( str_replace( '-', '', wp_generate_uuid4() ) );
		$foreign_body['idempotency_key'] = 'diag-contact-foreign-' . strtolower( str_replace( '-', '', wp_generate_uuid4() ) );
		$foreign_create = $this->call_create_contact( $foreign_body );
		$first_create_data = $this->response_data( $first_create );
		$second_create_data = $this->response_data( $second_create );
		$foreign_create_data = $this->response_data( $foreign_create );
		if ( ! empty( $first_create_data['contact_id'] ) ) {
			$this->created_contact_ids[] = (int) $first_create_data['contact_id'];
		}
		$create_ok = ! empty( $first_create_data['success'] )
			&& empty( $first_create_data['idempotency_replayed'] )
			&& ! empty( $second_create_data['success'] )
			&& ! empty( $second_create_data['idempotency_replayed'] )
			&& empty( $foreign_create_data['success'] )
			&& in_array( (string) ( $foreign_create_data['code'] ?? '' ), array( 'permission_denied', 'not_found' ), true );
		$this->emit( $ctx, $steps, 'Runtime - add-customer exact scope and replay', $create_ok, sprintf( 'first=%s; replay=%s; foreign=%s', $this->result_code( $first_create_data ), $this->result_code( $second_create_data ), $this->result_code( $foreign_create_data ) ) );

		$passed = true;
		foreach ( $steps as $step ) {
			if ( 'fail' === (string) ( $step['status'] ?? '' ) ) { $passed = false; break; }
		}
		return array(
			'status' => $passed ? 'pass' : 'fail',
			'summary' => $passed ? 'Twin GPT contact facts and add-customer replay/scope passed.' : 'Twin GPT contact mutation replay or scope failed.',
			'error' => $passed ? '' : 'crm_contact_mutation_contract_failed',
			'fix_hint' => $passed ? '' : 'Inspect contact-facts mutation store, CRM contact owner and exact channel/ref scope.',
			'steps' => $steps,
		);
	}

	public function cleanup(): void {
		// [2026-09-12 12:10 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.48C-RC6-RC9 — remove only contacts and Inbox rows created by this probe.
		global $wpdb;
		foreach ( array_values( array_unique( array_filter( array_merge( $this->created_contact_ids, array( $this->fixture_contact_id ) ) ) ) ) as $contact_id ) {
			if ( class_exists( 'BizCity_CRM_DB_Installer_V2' ) ) {
				$wpdb->delete( BizCity_CRM_DB_Installer_V2::tbl_contacts(), array( 'id' => (int) $contact_id ), array( '%d' ) );
			}
		}
		if ( $this->fixture_inbox_id > 0 && class_exists( 'BizCity_CRM_Repository' ) ) {
			BizCity_CRM_Repository::delete_inbox( $this->fixture_inbox_id );
		}
		$this->fixture_inbox_id = 0;
		$this->fixture_contact_id = 0;
		$this->fixture_conversation_id = 0;
		$this->created_contact_ids = array();
	}

	private function create_fixture( int $user_id ): ?array {
		$ref = '__diag_contact_mutation_' . strtolower( wp_generate_uuid4() );
		$this->fixture_inbox_id = BizCity_CRM_Repository::upsert_inbox( 'webchat', $ref, array( 'name' => 'Diagnostics contact mutation fixture' ) );
		if ( $this->fixture_inbox_id <= 0 || ! BizCity_CRM_Team_Manager::add_inbox_member( $this->fixture_inbox_id, $user_id, 'agent', false ) ) { return null; }
		$contact = BizCity_CRM_Repository::upsert_contact( $this->fixture_inbox_id, '__diag_contact_mutation_peer_' . strtolower( wp_generate_uuid4() ), array( 'name' => 'Diagnostics contact facts' ) );
		$this->fixture_contact_id = (int) ( $contact['contact_id'] ?? 0 );
		$contact_inbox_id = (int) ( $contact['contact_inbox_id'] ?? 0 );
		if ( $this->fixture_contact_id <= 0 || $contact_inbox_id <= 0 ) { return null; }
		$this->fixture_conversation_id = BizCity_CRM_Repository::open_or_get_conversation( $this->fixture_inbox_id, $contact_inbox_id );
		if ( $this->fixture_conversation_id <= 0 ) { return null; }
		global $wpdb;
		$wpdb->update( BizCity_CRM_DB_Installer_V2::tbl_conversations(), array( 'contact_id' => $this->fixture_contact_id, 'platform' => 'webchat', 'account_id' => $ref, 'blog_id' => (int) get_current_blog_id() ), array( 'id' => $this->fixture_conversation_id ), array( '%d', '%s', '%s', '%d' ), array( '%d' ) );
		return array( 'ref' => $ref );
	}

	private function call_contact_facts( array $body ) {
		$request = new WP_REST_Request( 'PUT', '/bizcity-twinweb/v1/crm/inbox/conversations/' . $this->fixture_conversation_id . '/contact' );
		$request->set_url_params( array( 'id' => $this->fixture_conversation_id ) );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );
		return BizCity_TwinWeb_REST::instance()->update_crm_member_contact_facts( $request );
	}

	private function call_create_contact( array $body ) {
		$request = new WP_REST_Request( 'POST', '/bizcity-twinweb/v1/crm/inbox/contacts' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );
		return BizCity_TwinWeb_REST::instance()->post_crm_member_contact( $request );
	}

	private function response_data( $response ): array {
		if ( is_wp_error( $response ) ) { return array( 'success' => false, 'code' => $response->get_error_code() ); }
		return is_object( $response ) && method_exists( $response, 'get_data' ) ? (array) $response->get_data() : array();
	}

	private function result_code( array $data ): string {
		if ( ! empty( $data['idempotency_replayed'] ) ) { return 'replay'; }
		if ( ! empty( $data['success'] ) ) { return 'success'; }
		return (string) ( $data['code'] ?? 'failed' );
	}

	private function emit( $ctx, array &$steps, string $label, bool $ok, string $detail ): void {
		$step = array( 'label' => $label, 'status' => $ok ? 'pass' : 'fail', 'detail' => $detail );
		$steps[] = $step;
		$ctx->emit_step( $step );
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $probes ) {
	$probes[] = 'BizCity_Probe_TwinWeb_CRM_Contact_Mutation_Replay';
	return $probes;
} );
