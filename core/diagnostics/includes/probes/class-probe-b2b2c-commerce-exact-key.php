<?php
/**
 * Disposable Hub commerce fixture for exact-key paid activation and replay.
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

if ( class_exists( 'BizCity_Probe_B2B2C_Commerce_Exact_Key', false ) ) {
	return;
}

final class BizCity_Probe_B2B2C_Commerce_Exact_Key implements BizCity_Diagnostics_Probe {

	const FIXTURE_OPTION = 'bizcity_diag_b2b2c_commerce_fixture';

	private $state = array();

	public function id(): string {
		// [2026-09-02 Johnny Chu] B2C-F5/D5-D8 — identify the disposable exact-key commerce probe.
		return 'b2b2c.account.commerce_exact_key';
	}

	public function label(): string {
		// [2026-09-02 Johnny Chu] B2C-F5/D5-D8 — expose the probe label in diagnostics.
		return 'B2B2C exact-key commerce';
	}

	public function description(): string {
		// [2026-09-02 Johnny Chu] B2C-F5/D5-D8 — describe the disposable paid-flow evidence boundary.
		return 'Creates a disposable paid Woo checkout, verifies exact-key activation and callback replay, then removes all fixture state.';
	}

	public function severity(): string {
		// [2026-09-02 Johnny Chu] B2C-F5/D5-D8 — classify exact-key commerce as a release-critical contract.
		return 'critical';
	}

	public function order(): int {
		// [2026-09-02 Johnny Chu] B2C-F5/D5-D8 — run after the read-only plan-context gate.
		return 21;
	}

	public function icon(): string {
		// [2026-09-02 Johnny Chu] B2C-F5/D5-D8 — use the commerce icon in diagnostics.
		return 'shopping-cart';
	}

	public function estimate_ms(): int {
		// [2026-09-02 Johnny Chu] B2C-F5/D5-D8 — estimate Woo fixture runtime for the runner.
		return 900;
	}

	public function precondition() {
		// [2026-09-02 Johnny Chu] B2C-F5/D5-D8 — gate the fixture to the B1 Hub and Woo runtime.
		$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( preg_replace( '/:\d+$/', '', (string) $_SERVER['HTTP_HOST'] ) ) : '';
		if ( ! in_array( $host, array( 'bizcity.vn', 'www.bizcity.vn' ), true ) ) {
			return 'not_applicable_b2_client: paid Hub commerce is owned by bizcity.vn.';
		}
		$router_dir = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR . '/bizcity-llm-router/includes/' : '';
		$load_map = array(
			'BizCity_Router_Account'          => 'class-router-account-rest.php',
			'BizCity_Router_Commerce_Service' => 'commerce/class-router-commerce-service.php',
			'BizCity_Router_License_Service'  => 'license/class-router-license-service.php',
			'BizCity_Router_Master_Schema'    => 'class-router-master-schema.php',
		);
		foreach ( $load_map as $class_name => $file_name ) {
			$file = $router_dir . $file_name;
			if ( ! class_exists( $class_name ) && $router_dir !== '' && is_file( $file ) && is_readable( $file ) && class_exists( 'BizCity_Safe_Loader' ) ) {
				BizCity_Safe_Loader::require_file( $file, 'diagnostics.b2b2c_commerce_exact_key.' . $class_name );
			}
		}
		if ( ! class_exists( 'BizCity_Router_Commerce_Service' )
			|| ! class_exists( 'BizCity_Router_License_Service' )
			|| ! class_exists( 'BizCity_Router_Master_Schema' )
			|| ! class_exists( 'BizCity_Router_Account' ) ) {
			return new WP_Error( 'commerce_exact_key_classes_missing', 'Exact-key commerce classes are not loaded.' );
		}
		if ( ! function_exists( 'wc_create_order' ) || ! function_exists( 'wc_get_order' ) || ! function_exists( 'wc_get_product' ) ) {
			return 'woo_runtime_missing: paid exact-key fixture requires WooCommerce order and product APIs.';
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-09-02 Johnny Chu] B2C-F5/D5-D8 — recover a previous interrupted fixture before creating new commerce state.
		$this->load_persisted_state();
		if ( ! $this->cleanup_fixture() ) {
			return array( 'status' => 'fail', 'summary' => 'A previous exact-key commerce fixture could not be cleaned safely.', 'error' => 'stale_fixture_cleanup_failed', 'fix_hint' => 'Resolve the persisted disposable Woo/key fixture before rerunning this probe.' );
		}
		$this->state = array();
		$this->persist_state();

		$previous_user_id = (int) get_current_user_id();
		$result = array(
			'status'  => 'fail',
			'summary' => 'Exact-key commerce fixture did not complete.',
			'error'   => 'fixture_not_started',
			'fix_hint' => 'Inspect the emitted fixture step and rerun on the B1 Hub after resolving the local contract failure.',
		);
		$cleanup_ok = false;

		try {
			global $wpdb;
			$user_id = (int) $wpdb->get_var(
				"SELECT u.ID
				 FROM {$wpdb->users} u
				 INNER JOIN {$wpdb->base_prefix}bizcity_llm_api_keys k ON k.user_id = u.ID AND k.is_active = 1
				 GROUP BY u.ID
				 ORDER BY COUNT(k.id) ASC, u.ID ASC
				 LIMIT 1"
			);
			if ( $user_id <= 0 ) {
				return array( 'status' => 'skip', 'summary' => 'No existing user with an active sibling key is available for the disposable commerce fixture.', 'reason' => 'fixture_sibling_missing' );
			}

			$paid_plan = $this->find_paid_plan();
			if ( ! is_array( $paid_plan ) ) {
				return array( 'status' => 'skip', 'summary' => 'No active paid Master Plan with a valid Woo product mapping is available.', 'reason' => 'paid_plan_mapping_missing' );
			}
			$plan_code = sanitize_key( (string) $paid_plan['level'] );
			$product_id = (int) $paid_plan['woo_product_id'];
			$ctx->emit_step( array(
				'label'  => 'Fixture · paid plan mapping',
				'status' => 'pass',
				'detail' => 'Selected active paid plan with mapped Woo product; plan code is kept in server-side context.',
			) );

			$sibling = $this->find_sibling_key( $user_id );
			if ( ! is_array( $sibling ) || (int) $sibling['id'] <= 0 ) {
				return array( 'status' => 'skip', 'summary' => 'No active sibling key is available for isolation assertion.', 'reason' => 'fixture_sibling_missing' );
			}
			$this->state = array(
				'user_id'         => $user_id,
				'domain'          => 'healthtest-commerce-' . strtolower( str_replace( '-', '', wp_generate_uuid4() ) ) . '.example.com',
				'label'           => 'Diagnostics commerce exact-key fixture',
				'client_id'       => 'diag_f5_commerce_fixture',
				'sibling_key_id'  => (int) $sibling['id'],
				'sibling_level'   => (string) $sibling['master_level'],
				'plan_code'       => $plan_code,
				'product_id'      => $product_id,
				'order_id'        => 0,
				'key_id'          => 0,
				'key_hash'        => '',
				'entitlement_key' => '',
			);
			$this->persist_state();

			wp_set_current_user( $user_id );
			$key_request = new WP_REST_Request( 'POST', '/bizcity/v1/account/api-keys' );
			$key_request->set_header( 'Content-Type', 'application/json' );
			$key_request->set_body( wp_json_encode( array( 'label' => $this->state['label'], 'domain' => $this->state['domain'] ) ) );
			$key_response = BizCity_Router_Account::handle_create_key( $key_request );
			$key_data = $key_response instanceof WP_REST_Response ? $key_response->get_data() : array();
			$plain_key = is_array( $key_data ) ? (string) ( $key_data['api_key'] ?? '' ) : '';
			$this->state['key_hash'] = $plain_key !== '' ? hash( 'sha256', $plain_key ) : '';
			$this->state['key_id'] = $this->find_key_id( $user_id, $this->state['key_hash'] );
			$this->state['entitlement_key'] = $this->state['key_id'] > 0 ? 'key:' . $this->state['key_id'] : '';
			$this->persist_state();
			$key_ok = $key_response instanceof WP_REST_Response
				&& 201 === (int) $key_response->get_status()
				&& $plain_key !== ''
				&& $this->state['key_id'] > 0;
			$ctx->emit_step( array(
				'label'  => 'Fixture · disposable Free key',
				'status' => $key_ok ? 'pass' : 'fail',
				'detail' => $key_ok ? 'Created a temporary production-domain key; plaintext is not persisted.' : 'Temporary key creation did not return a usable key row.',
			) );
			if ( ! $key_ok ) {
				$result = array( 'status' => 'fail', 'summary' => 'Disposable exact-key fixture could not create its temporary key.', 'error' => 'fixture_key_create_failed', 'fix_hint' => 'Inspect the Hub account key creation route and domain uniqueness state.' );
				return $result;
			}

			$checkout = BizCity_Router_Commerce_Service::create_checkout(
				array(
					'client_id'     => $this->state['client_id'],
					'user_id'       => $user_id,
					'plan_code'     => $plan_code,
					'billing_cycle' => 'month',
					'return_url'    => home_url( '/' ),
				),
				array( 'owner_user_id' => $user_id, 'key_id' => $this->state['key_id'] )
			);
			$checkout_ok = is_array( $checkout ) && ! empty( $checkout['success'] ) && (int) ( $checkout['order_id'] ?? 0 ) > 0 && 'woo' === (string) ( $checkout['_via'] ?? '' );
			$this->state['order_id'] = $checkout_ok ? (int) $checkout['order_id'] : 0;
			$this->persist_state();
			$ctx->emit_step( array(
				'label'  => 'Runtime · exact-key checkout creation',
				'status' => $checkout_ok ? 'pass' : 'fail',
				'detail' => $checkout_ok ? 'Woo checkout created through the selected exact key context.' : 'Checkout did not return a real Woo order; shadow/degraded checkout is not accepted for this fixture.',
			) );
			if ( ! $checkout_ok ) {
				$result = array( 'status' => 'fail', 'summary' => 'Paid exact-key checkout was not created through WooCommerce.', 'error' => 'woo_checkout_fixture_failed', 'fix_hint' => 'Verify the selected paid plan product mapping and WooCommerce order API.' );
				return $result;
			}

			$order = wc_get_order( $this->state['order_id'] );
			// [2026-09-02 07:05 AM Johnny Chu - Chu Hoàng Anh] B2C-F8 — assert unpaid Hub checkout remains payable before payment submission.
			$pending_status_ok = $order && method_exists( $order, 'get_status' ) && 'pending' === (string) $order->get_status();
			$ctx->emit_step( array(
				'label'  => 'Runtime · unpaid checkout remains payable',
				'status' => $pending_status_ok ? 'pass' : 'fail',
				'detail' => $pending_status_ok ? 'New Woo checkout remains pending until the customer submits payment.' : 'New Woo checkout was persisted in a non-payable status.',
			) );
			if ( ! $pending_status_ok ) {
				$result = array( 'status' => 'fail', 'summary' => 'New Woo checkout is not payable because its status is not pending.', 'error' => 'checkout_status_not_pending', 'fix_hint' => 'Persist Hub unpaid orders as pending; reserve on-hold for the TTCK payment submission result.' );
				return $result;
			}
			$metadata_ok = $this->verify_order_metadata( $order, $user_id, $plan_code, $product_id );
			$ctx->emit_step( array(
				'label'  => 'Runtime · immutable order metadata',
				'status' => $metadata_ok ? 'pass' : 'fail',
				'detail' => $metadata_ok ? '_bizcity_key_id, _bizcity_plan_code, owner and product snapshot match the selected key.' : 'Woo order metadata or product snapshot does not match the selected key context.',
			) );
			if ( ! $metadata_ok ) {
				$result = array( 'status' => 'fail', 'summary' => 'Checkout order metadata did not preserve the exact key context.', 'error' => 'order_metadata_mismatch', 'fix_hint' => 'Keep selected key_id and plan_code immutable from Account checkout into the Woo order.' );
				return $result;
			}

			$transaction_id = 'diag_f5_' . substr( md5( $this->state['order_id'] . '|' . $this->state['key_id'] ), 0, 16 );
			$paid_payload = array( 'order_id' => $this->state['order_id'], 'status' => 'processing', 'transaction_id' => $transaction_id, 'paid_at' => gmdate( 'c' ) );
			$first = BizCity_Router_Commerce_Service::handle_paid_event( $paid_payload );
			$first_level = BizCity_Router_Master_Schema::get_key_level( $this->state['key_id'] );
			$sibling_after_first = BizCity_Router_Master_Schema::get_key_level( $this->state['sibling_key_id'] );
			$first_ok = is_array( $first ) && ! empty( $first['success'] ) && 'issued' === (string) ( $first['entitlement_state'] ?? '' ) && empty( $first['idempotent_replay'] ) && $plan_code === sanitize_key( (string) $first_level ) && $this->state['sibling_level'] === (string) $sibling_after_first;
			$ctx->emit_step( array(
				'label'  => 'Runtime · paid event exact-key activation',
				'status' => $first_ok ? 'pass' : 'fail',
				'detail' => $first_ok ? 'First paid callback issued the selected plan and left the sibling key unchanged.' : 'Paid callback did not issue only the selected key.',
			) );
			if ( ! $first_ok ) {
				$result = array( 'status' => 'fail', 'summary' => 'Paid callback exact-key activation failed.', 'error' => 'exact_key_activation_failed', 'fix_hint' => 'Trace Commerce Service to License Service and verify key_id is forwarded without user-wide plan fallback.' );
				return $result;
			}

			$second = BizCity_Router_Commerce_Service::handle_paid_event( $paid_payload );
			$second_level = BizCity_Router_Master_Schema::get_key_level( $this->state['key_id'] );
			$sibling_after_second = BizCity_Router_Master_Schema::get_key_level( $this->state['sibling_key_id'] );
			$second_ok = is_array( $second ) && ! empty( $second['success'] ) && ! empty( $second['idempotent_replay'] ) && $plan_code === sanitize_key( (string) $second_level ) && $this->state['sibling_level'] === (string) $sibling_after_second;
			$ctx->emit_step( array(
				'label'  => 'Runtime · duplicate callback idempotency',
				'status' => $second_ok ? 'pass' : 'fail',
				'detail' => $second_ok ? 'Second identical paid callback replayed without a second entitlement/key-level mutation.' : 'Duplicate callback was not recognized as an exact-key replay or changed sibling state.',
			) );
			if ( ! $second_ok ) {
				$result = array( 'status' => 'fail', 'summary' => 'Duplicate paid callback was not idempotent.', 'error' => 'paid_callback_not_idempotent', 'fix_hint' => 'Keep the issued state and exact key plan check before entitlement re-issue.' );
				return $result;
			}

			$entitlement = BizCity_Router_License_Service::get_entitlement( $this->state['client_id'], $user_id, array( 'key_id' => $this->state['key_id'] ) );
			$entitlement_ok = is_array( $entitlement ) && $plan_code === sanitize_key( (string) ( $entitlement['plan_code'] ?? '' ) ) && (int) ( $entitlement['key_id'] ?? 0 ) === $this->state['key_id'];
			$ctx->emit_step( array(
				'label'  => 'Runtime · exact-key entitlement readback',
				'status' => $entitlement_ok ? 'pass' : 'fail',
				'detail' => $entitlement_ok ? 'License readback resolves the selected key namespace and paid plan.' : 'License readback did not resolve the selected key namespace.',
			) );
			if ( ! $entitlement_ok ) {
				$result = array( 'status' => 'fail', 'summary' => 'Exact-key entitlement readback failed.', 'error' => 'entitlement_key_readback_failed', 'fix_hint' => 'Verify key:{key_id} entitlement storage and read context.' );
				return $result;
			}

			$result = array(
				'status'  => 'pass',
				'summary' => 'Paid exact-key checkout, immutable order metadata, exact-key activation, sibling isolation and duplicate callback idempotency passed.',
			);
			return $result;
		} catch ( Throwable $e ) {
			$result = array( 'status' => 'fail', 'summary' => 'Exact-key commerce fixture threw an exception.', 'error' => 'fixture_exception', 'fix_hint' => 'Inspect the Hub commerce/entitlement error log without exposing order credentials.', 'exception_class' => get_class( $e ) );
			return $result;
		} finally {
			$this->load_persisted_state();
			$cleanup_ok = $this->cleanup_fixture();
			$ctx->emit_step( array(
				'label'  => 'Fixture · full cleanup',
				'status' => $cleanup_ok ? 'pass' : 'fail',
				'detail' => $cleanup_ok ? 'Temporary key, Woo order and exact-key entitlement state were removed.' : 'One or more temporary commerce artifacts remain and will be retried on the next probe run.',
			) );
			wp_set_current_user( $previous_user_id );
			if ( ! $cleanup_ok ) {
				throw new RuntimeException( 'fixture_cleanup_failed' );
			}
		}
		return $result;
	}

	public function cleanup(): void {
		// [2026-09-02 Johnny Chu] B2C-F5/D5-D8 — runner-level cleanup retries persisted fixture state after pass, fail or interruption.
		$this->load_persisted_state();
		$this->cleanup_fixture();
	}

	private function find_paid_plan() {
		// [2026-09-02 Johnny Chu] B2C-F5/D5-D8 — choose only an active paid plan with a real Woo product.
		$plans = BizCity_Router_Master_Schema::get_all_plans();
		foreach ( (array) $plans as $plan ) {
			if ( ! is_array( $plan ) || empty( $plan['is_active'] ) ) {
				continue;
			}
			if ( (float) ( $plan['price_usd'] ?? 0 ) <= 0 || (int) ( $plan['woo_product_id'] ?? 0 ) <= 0 ) {
				continue;
			}
			$plan_code = sanitize_key( (string) ( $plan['level'] ?? '' ) );
			if ( $plan_code !== '' && wc_get_product( (int) $plan['woo_product_id'] ) ) {
				return $plan;
			}
		}
		return null;
	}

	private function find_sibling_key( $user_id ) {
		// [2026-09-02 Johnny Chu] B2C-D8 — capture one existing active key for sibling isolation.
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT id, master_level FROM {$wpdb->base_prefix}bizcity_llm_api_keys WHERE user_id = %d AND is_active = 1 ORDER BY id ASC LIMIT 1",
			(int) $user_id
		), ARRAY_A );
	}

	private function find_key_id( $user_id, $key_hash ) {
		// [2026-09-02 Johnny Chu] B2C-F5 — resolve the temporary key by its non-reversible hash.
		if ( $key_hash === '' ) {
			return 0;
		}
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->base_prefix}bizcity_llm_api_keys WHERE user_id = %d AND key_hash = %s LIMIT 1",
			(int) $user_id,
			(string) $key_hash
		) );
	}

	private function verify_order_metadata( $order, $user_id, $plan_code, $product_id ) {
		// [2026-09-02 Johnny Chu] B2C-D5 — verify immutable order context before payment simulation.
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
			return false;
		}
		if ( (int) $order->get_meta( '_bizcity_key_id', true ) !== (int) $this->state['key_id']
			|| sanitize_key( (string) $order->get_meta( '_bizcity_plan_code', true ) ) !== $plan_code
			|| (int) $order->get_meta( '_bizcity_user_id', true ) !== (int) $user_id
			|| (int) $order->get_meta( '_bizcity_owner_user_id', true ) !== (int) $user_id
			|| sanitize_key( (string) $order->get_meta( '_bizcity_entitlement_state', true ) ) !== 'not_issued' ) {
			return false;
		}
		if ( ! method_exists( $order, 'get_items' ) ) {
			return false;
		}
		foreach ( (array) $order->get_items() as $item ) {
			if ( is_object( $item ) && method_exists( $item, 'get_product_id' ) && (int) $item->get_product_id() === (int) $product_id ) {
				return true;
			}
		}
		return false;
	}

	private function load_persisted_state() {
		// [2026-09-02 Johnny Chu] B2C-F5/D5-D8 — recover only the bounded disposable fixture marker.
		$state = get_site_option( self::FIXTURE_OPTION, array() );
		$this->state = is_array( $state ) ? $state : array();
	}

	private function persist_state() {
		// [2026-09-02 Johnny Chu] B2C-F5/D5-D8 — persist recoverable IDs/hashes without plaintext credentials.
		if ( empty( $this->state ) ) {
			delete_site_option( self::FIXTURE_OPTION );
			return;
		}
		update_site_option( self::FIXTURE_OPTION, $this->state );
	}

	private function cleanup_fixture() {
		// [2026-09-02 Johnny Chu] B2C-F5/D5-D8 — delete only artifacts identified by the persisted fixture marker.
		if ( empty( $this->state ) ) {
			return true;
		}
		$cleanup_ok = true;
		$order_id = (int) ( $this->state['order_id'] ?? 0 );
		if ( $order_id > 0 && class_exists( 'BizCity_Router_License_Ledger' ) && function_exists( 'bizcity_tbl_exists' ) ) {
			// [2026-09-02 09:50 PM Johnny Chu - Chu Hoàng Anh] B2C-H3 — remove only this disposable order's journal rows; production ledger history remains append-only.
			$ledger_table = BizCity_Router_License_Ledger::table_name();
			if ( bizcity_tbl_exists( $ledger_table ) ) {
				global $wpdb;
				$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$ledger_table} WHERE woo_order_id = %d AND source = %s", $order_id, 'woo_order_paid' ) );
				if ( false === $deleted ) {
					$cleanup_ok = false;
				}
			}
		}
		if ( $order_id > 0 && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $order_id );
			if ( $order && is_object( $order ) && method_exists( $order, 'delete' ) ) {
				try {
					$order->delete( true );
				} catch ( Throwable $e ) {
					$cleanup_ok = false;
				}
			}
			if ( wc_get_order( $order_id ) ) {
				$cleanup_ok = false;
			}
		}
		if ( $order_id > 0 ) {
			$shadow_orders = (array) get_site_option( BizCity_Router_Commerce_Service::OPTION_SHADOW_ORDERS, array() );
			$shadow_key = (string) $order_id;
			if ( isset( $shadow_orders[ $shadow_key ] ) ) {
				unset( $shadow_orders[ $shadow_key ] );
				if ( empty( $shadow_orders ) ) {
					delete_site_option( BizCity_Router_Commerce_Service::OPTION_SHADOW_ORDERS );
				} else {
					update_site_option( BizCity_Router_Commerce_Service::OPTION_SHADOW_ORDERS, $shadow_orders );
				}
			}
			$shadow_remaining = (array) get_site_option( BizCity_Router_Commerce_Service::OPTION_SHADOW_ORDERS, array() );
			if ( isset( $shadow_remaining[ $shadow_key ] ) ) {
				$cleanup_ok = false;
			}
		}

		$entitlement_key = (string) ( $this->state['entitlement_key'] ?? '' );
		if ( $entitlement_key !== '' ) {
			$all = (array) get_site_option( BizCity_Router_License_Service::OPTION_ENTITLEMENTS, array() );
			if ( isset( $all[ $entitlement_key ] ) ) {
				unset( $all[ $entitlement_key ] );
				update_site_option( BizCity_Router_License_Service::OPTION_ENTITLEMENTS, $all );
			}
		}

		$key_id = (int) ( $this->state['key_id'] ?? 0 );
		$user_id = (int) ( $this->state['user_id'] ?? 0 );
		$key_hash = (string) ( $this->state['key_hash'] ?? '' );
		$domain = (string) ( $this->state['domain'] ?? '' );
		$label = (string) ( $this->state['label'] ?? '' );
		global $wpdb;
		$table = $wpdb->base_prefix . 'bizcity_llm_api_keys';
		if ( $user_id > 0 && $key_hash === '' && $domain !== '' && $label !== '' ) {
			$recovery_row = $wpdb->get_row( $wpdb->prepare(
				"SELECT id, key_hash FROM {$table} WHERE user_id = %d AND allowed_domain = %s AND label = %s LIMIT 1",
				$user_id,
				$domain,
				$label
			), ARRAY_A );
			if ( is_array( $recovery_row ) ) {
				$key_id = (int) ( $recovery_row['id'] ?? 0 );
				$key_hash = (string) ( $recovery_row['key_hash'] ?? '' );
			}
		}
		if ( $key_id > 0 && $user_id > 0 && $key_hash !== '' ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id = %d AND user_id = %d AND key_hash = %s", $key_id, $user_id, $key_hash ) );
			$remaining = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE id = %d AND user_id = %d AND key_hash = %s", $key_id, $user_id, $key_hash ) );
			if ( $remaining > 0 ) {
				$cleanup_ok = false;
			}
		}

		if ( $cleanup_ok ) {
			$this->state = array();
			delete_site_option( self::FIXTURE_OPTION );
		} else {
			$this->persist_state();
		}
		return $cleanup_ok;
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_B2B2C_Commerce_Exact_Key';
	return $list;
} );
