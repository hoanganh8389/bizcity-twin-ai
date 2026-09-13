<?php
/**
 * W8.1/W8.2 read-only order-draft/product-search/payment-options probe.
 *
 * The probe uses a disposable scoped CRM conversation and never calls
 * create_order(), payment or shipping owners.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since 2026-09-13 (PHASE-0.41-W8.1/W8.2)
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BizCity_Safe_Loader', false ) ) {
	$_bizcity_safe_loader = dirname( __DIR__, 4 ) . '/core/helper/class-bizcity-safe-loader.php';
	if ( is_file( $_bizcity_safe_loader ) && is_readable( $_bizcity_safe_loader ) ) {
		require_once $_bizcity_safe_loader;
	}
	unset( $_bizcity_safe_loader );
}
if ( ! class_exists( 'BizCity_Safe_Loader', false ) ) { return; }
if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false )
	&& ! BizCity_Safe_Loader::require_file( dirname( __DIR__ ) . '/interface-diagnostics-probe.php', 'diagnostics.probe_interface' ) ) { return; }
if ( class_exists( 'BizCity_Probe_TwinWeb_CRM_Order_Draft', false ) ) { return; }

final class BizCity_Probe_TwinWeb_CRM_Order_Draft implements BizCity_Diagnostics_Probe {

	private $fixture_inbox_id = 0;
	private $fixture_contact_id = 0;
	private $fixture_conversation_id = 0;

	public function id(): string { return 'modules.twin_gpt.crm_order_draft'; }
	public function label(): string { return 'Twin GPT order draft read path'; }
	public function description(): string { return 'Checks exact C scope, canonical product search, redacted payment options and read-only policies without Woo order/payment/shipping mutation.'; }
	public function severity(): string { return 'critical'; }
	public function order(): int { return 74; }
	public function icon(): string { return 'shopping-bag'; }
	public function estimate_ms(): int { return 350; }
	public function precondition() {
		if ( ! class_exists( 'BizCity_TwinWeb_REST' ) || ! class_exists( 'BizCity_CRM_Repository' ) || ! class_exists( 'BizCity_CRM_Team_Manager' ) || ! class_exists( 'BizCity_CRM_Order_Adapter_Registry' ) ) {
			return 'TwinWeb, CRM Repository, Team Manager or Order Adapter is not loaded.';
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-09-13 09:30 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.41-W8.1 — prove read-only product search and explicit draft-only policy before W8 mutations.
		$steps = array();
		$user_id = (int) get_current_user_id();
		$fixture = $this->create_fixture( $user_id );
		if ( ! is_array( $fixture ) ) {
			$this->emit( $ctx, $steps, 'Runtime - disposable scoped order fixture', false, 'Could not create a member-scoped CRM fixture.' );
			return array( 'status' => 'fail', 'summary' => 'W8.1 order-draft fixture failed.', 'error' => 'fixture_create_failed', 'fix_hint' => 'Verify CRM Inbox fixture APIs and current authenticated user.', 'steps' => $steps );
		}
		$this->emit( $ctx, $steps, 'Runtime - disposable scoped order fixture', true, 'Created a diagnostic CRM conversation; no Woo order was created.' );
		if ( function_exists( 'did_action' ) && function_exists( 'do_action' ) && ! did_action( 'rest_api_init' ) ) { do_action( 'rest_api_init' ); }

		$adapter = BizCity_CRM_Order_Adapter_Registry::default_adapter();
		$adapter_ok = $adapter && $adapter->is_available() && method_exists( $adapter, 'search_products' );
		$this->emit( $ctx, $steps, 'Loader - canonical CRM/Woo order adapter', (bool) $adapter_ok, $adapter_ok ? 'Default adapter is available and exposes read-only product search.' : 'No available order adapter/product search owner is loaded.' );
		if ( ! $adapter_ok ) {
			return array( 'status' => 'skip', 'summary' => 'W8.1 adapter is not available on this tenant.', 'fix_hint' => 'Enable the canonical Woo/CRM Order Adapter before rerunning W8.1.', 'steps' => $steps );
		}

		$request = new WP_REST_Request( 'GET', '/bizcity-twinweb/v1/crm/inbox/conversations/' . $this->fixture_conversation_id . '/order-draft/products' );
		$request->set_url_params( array( 'id' => $this->fixture_conversation_id ) );
		$request->set_param( 'q', '' );
		$request->set_param( 'limit', 5 );
		$response = BizCity_TwinWeb_REST::instance()->get_crm_member_order_draft_products( $request );
		$data = is_object( $response ) && method_exists( $response, 'get_data' ) ? (array) $response->get_data() : array();
		$draft_ok = ! empty( $data['success'] )
			&& is_array( $data['products'] ?? null )
			&& is_array( $data['order_policy'] ?? null )
			&& 'draft_only' === (string) ( $data['order_policy']['mode'] ?? '' )
			&& empty( $data['order_policy']['creates_order'] )
			&& empty( $data['order_policy']['payment'] )
			&& empty( $data['order_policy']['shipping'] )
			&& (int) ( $data['conversation_id'] ?? 0 ) === $this->fixture_conversation_id;
		$this->emit( $ctx, $steps, 'Runtime - scoped product search and draft-only policy', $draft_ok, $draft_ok ? sprintf( 'products=%d; creates_order=false; payment=false; shipping=false', count( $data['products'] ) ) : 'Product search or draft-only policy response is incomplete.' );

		$payment_request = new WP_REST_Request( 'GET', '/bizcity-twinweb/v1/crm/inbox/conversations/' . $this->fixture_conversation_id . '/order-draft/payment-options' );
		$payment_request->set_url_params( array( 'id' => $this->fixture_conversation_id ) );
		$payment_response = BizCity_TwinWeb_REST::instance()->get_crm_member_order_payment_options( $payment_request );
		$payment_data = is_object( $payment_response ) && method_exists( $payment_response, 'get_data' ) ? (array) $payment_response->get_data() : array();
		$payment_options = is_array( $payment_data['options'] ?? null ) ? $payment_data['options'] : array();
		$payment_safe = true;
		foreach ( $payment_options as $option ) {
			if ( ! is_array( $option ) || array_intersect( array( 'account_no', 'account_name', 'bin', 'qr_img_url', 'qr_pay_url', 'checkout_url' ), array_keys( $option ) ) ) {
				$payment_safe = false;
				break;
			}
		}
		$payment_ok = ! empty( $payment_data['success'] )
			&& (int) ( $payment_data['conversation_id'] ?? 0 ) === $this->fixture_conversation_id
			&& is_array( $payment_data['payment_policy'] ?? null )
			&& 'options_only' === (string) ( $payment_data['payment_policy']['mode'] ?? '' )
			&& empty( $payment_data['payment_policy']['can_issue_link'] )
			&& empty( $payment_data['payment_policy']['can_issue_qr'] )
			&& ! empty( $payment_data['payment_policy']['requires_confirmation'] )
			&& $payment_safe;
		$this->emit( $ctx, $steps, 'Runtime - redacted payment options and no link/QR issuance', $payment_ok, $payment_ok ? sprintf( 'options=%d; options_only=true; account credentials absent', count( $payment_options ) ) : 'Payment options are not redacted or the read-only policy is incomplete.' );

		$passed = true;
		foreach ( $steps as $step ) { if ( 'fail' === (string) ( $step['status'] ?? '' ) ) { $passed = false; break; } }
		return array( 'status' => $passed ? 'pass' : 'fail', 'summary' => $passed ? 'Twin GPT W8.1/W8.2 read-only order and payment-option paths passed.' : 'Twin GPT W8.1/W8.2 read-only contract failed.', 'error' => $passed ? '' : 'crm_order_payment_read_contract_failed', 'fix_hint' => $passed ? '' : 'Check exact C scope, canonical adapter availability, redaction and read-only policies.', 'steps' => $steps );
	}

	public function cleanup(): void {
		// [2026-09-13 09:30 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.41-W8.1 — remove only diagnostic CRM fixture rows.
		global $wpdb;
		if ( $this->fixture_contact_id > 0 && class_exists( 'BizCity_CRM_DB_Installer_V2' ) ) { $wpdb->delete( BizCity_CRM_DB_Installer_V2::tbl_contacts(), array( 'id' => $this->fixture_contact_id ), array( '%d' ) ); }
		if ( $this->fixture_inbox_id > 0 && class_exists( 'BizCity_CRM_Repository' ) ) { BizCity_CRM_Repository::delete_inbox( $this->fixture_inbox_id ); }
		$this->fixture_inbox_id = 0;
		$this->fixture_contact_id = 0;
		$this->fixture_conversation_id = 0;
	}

	private function create_fixture( int $user_id ): ?array {
		if ( $user_id <= 0 ) { return null; }
		$ref = '__diag_order_draft_' . strtolower( wp_generate_uuid4() );
		$this->fixture_inbox_id = BizCity_CRM_Repository::upsert_inbox( 'webchat', $ref, array( 'name' => 'Diagnostics order draft fixture' ) );
		if ( $this->fixture_inbox_id <= 0 || ! BizCity_CRM_Team_Manager::add_inbox_member( $this->fixture_inbox_id, $user_id, 'agent', false ) ) { return null; }
		$contact = BizCity_CRM_Repository::upsert_contact( $this->fixture_inbox_id, '__diag_order_draft_peer_' . strtolower( wp_generate_uuid4() ), array( 'name' => 'Diagnostics order draft contact' ) );
		$this->fixture_contact_id = (int) ( $contact['contact_id'] ?? 0 );
		$contact_inbox_id = (int) ( $contact['contact_inbox_id'] ?? 0 );
		if ( $this->fixture_contact_id <= 0 || $contact_inbox_id <= 0 ) { return null; }
		$this->fixture_conversation_id = BizCity_CRM_Repository::open_or_get_conversation( $this->fixture_inbox_id, $contact_inbox_id );
		if ( $this->fixture_conversation_id <= 0 ) { return null; }
		global $wpdb;
		$wpdb->update( BizCity_CRM_DB_Installer_V2::tbl_conversations(), array( 'contact_id' => $this->fixture_contact_id, 'platform' => 'webchat', 'account_id' => $ref, 'blog_id' => (int) get_current_blog_id() ), array( 'id' => $this->fixture_conversation_id ), array( '%d', '%s', '%s', '%d' ), array( '%d' ) );
		return array( 'ref' => $ref );
	}

	private function emit( $ctx, array &$steps, string $label, bool $ok, string $detail ): void {
		$step = array( 'label' => $label, 'status' => $ok ? 'pass' : 'fail', 'detail' => $detail );
		$steps[] = $step;
		$ctx->emit_step( $step );
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $probes ) {
	$probes[] = 'BizCity_Probe_TwinWeb_CRM_Order_Draft';
	return $probes;
} );
