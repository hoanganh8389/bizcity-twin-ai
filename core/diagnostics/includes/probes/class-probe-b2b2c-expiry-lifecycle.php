<?php
/**
 * Disposable H5 fixture for request-time expiry and bounded maintenance.
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false ) ) {
	return;
}

if ( class_exists( 'BizCity_Probe_B2B2C_Expiry_Lifecycle', false ) ) {
	return;
}

final class BizCity_Probe_B2B2C_Expiry_Lifecycle implements BizCity_Diagnostics_Probe {

	const FIXTURE_OPTION = 'bizcity_diag_b2b2c_expiry_lifecycle_fixture';
	const FIXTURE_SOURCE = 'diagnostics_h5_expiry';

	private $state = array();

	public function id(): string {
		// [2026-09-04 10:30 AM Johnny Chu - Chu Hoàng Anh] B2C-H5 - identify request-time expiry and maintenance probe.
		return 'b2b2c.checkout.expiry_lifecycle';
	}

	public function label(): string {
		return 'B2B2C expiry lifecycle';
	}

	public function description(): string {
		return 'Verifies request-time expiry enforcement, bounded checkpoint maintenance, lock release and disposable cleanup for one exact key.';
	}

	public function severity(): string {
		return 'critical';
	}

	public function order(): int {
		return 26;
	}

	public function icon(): string {
		return 'timer-off';
	}

	public function estimate_ms(): int {
		return 240;
	}

	public function precondition() {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( preg_replace( '/:\d+$/', '', (string) $_SERVER['HTTP_HOST'] ) ) : '';
		if ( ! in_array( $host, array( 'bizcity.vn', 'www.bizcity.vn' ), true ) ) {
			return 'not_applicable_b2_client: expiry lifecycle is owned by bizcity.vn.';
		}
		if ( ! class_exists( 'BizCity_Router_License_Service' ) || ! class_exists( 'BizCity_Router_License_Ledger' ) ) {
			return new WP_Error( 'expiry_lifecycle_loader_missing', 'Expiry lifecycle classes are not loaded.' );
		}
		if ( ! function_exists( 'bizcity_tbl_exists' ) || ! bizcity_tbl_exists( BizCity_Router_License_Ledger::table_name() ) ) {
			return 'ledger_runtime_missing: expiry lifecycle requires the Global license ledger table.';
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-09-04 10:30 AM Johnny Chu - Chu Hoàng Anh] B2C-H5 - exercise request-time expiry and bounded maintenance with disposable state.
		$this->load_persisted_state();
		if ( ! $this->cleanup_fixture() ) {
			return array( 'status' => 'fail', 'summary' => 'A previous H5 expiry fixture could not be cleaned safely.', 'error' => 'stale_fixture_cleanup_failed', 'fix_hint' => 'Resolve the persisted disposable H5 fixture before rerunning this probe.' );
		}
		$this->state = array();
		$this->persist_state();

		try {
			global $wpdb;
			$key = $wpdb->get_row( "SELECT k.id, k.user_id, k.master_level FROM {$wpdb->base_prefix}bizcity_llm_api_keys k LEFT JOIN " . BizCity_Router_License_Ledger::table_name() . " l ON l.key_id = k.id WHERE k.is_active = 1 AND l.id IS NULL ORDER BY k.id ASC LIMIT 1", ARRAY_A );
			if ( ! is_array( $key ) || absint( $key['id'] ?? 0 ) <= 0 || absint( $key['user_id'] ?? 0 ) <= 0 ) {
				return array( 'status' => 'warn', 'summary' => 'No active exact key without existing ledger rows is available for the disposable H5 fixture.', 'error' => 'fixture_key_missing', 'fix_hint' => 'Use a disposable B1 key without existing ledger rows, then rerun the focused H5 probe.' );
			}

			$key_id = absint( $key['id'] );
			$owner_id = absint( $key['user_id'] );
			$entitlement_key = 'key:' . $key_id;
			$all_entitlements = (array) get_site_option( BizCity_Router_License_Service::OPTION_ENTITLEMENTS, array() );
			$previous_checkpoint = get_option( BizCity_Router_License_Service::EXPIRY_CHECKPOINT_OPTION, array() );
			$this->state = array(
				'key_id'            => $key_id,
				'owner_user_id'     => $owner_id,
				'original_level'    => sanitize_key( (string) ( $key['master_level'] ?? 'free' ) ),
				'entitlement_key'   => $entitlement_key,
				'prior_entitlement' => isset( $all_entitlements[ $entitlement_key ] ) && is_array( $all_entitlements[ $entitlement_key ] ) ? $all_entitlements[ $entitlement_key ] : null,
				'prior_checkpoint'  => is_array( $previous_checkpoint ) ? $previous_checkpoint : array(),
				'order_id'          => 990000000 + mt_rand( 1000, 999999 ),
				'idempotency_key'   => 'diag_h5:' . strtolower( str_replace( '-', '', wp_generate_uuid4() ) ),
			);
			$this->persist_state();

			$expired_start = gmdate( 'Y-m-d H:i:s', time() - ( 2 * DAY_IN_SECONDS ) );
			$expired_end = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
			$append = BizCity_Router_License_Ledger::append_grant( array(
				'event_uuid'              => wp_generate_uuid4(),
				'issuer_hub_id'           => 'bizcity',
				'commerce_hub_id'         => 'bizcity',
				'woo_site_id'             => function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 1,
				'woo_order_id'            => (int) $this->state['order_id'],
				'woo_order_item_id'       => 1,
				'key_id'                  => $key_id,
				'owner_user_id'           => $owner_id,
				'allowed_domain_snapshot' => 'diagnostics-h5.example.com',
				'plan_code'               => 'master_pro',
				'offer_product_id'        => 999992,
				'offer_variation_id'      => 0,
				'duration_days'           => 30,
				'quantity'                => 1,
				'period_start_at'         => $expired_start,
				'period_end_at'           => $expired_end,
				'currency'                => 'USD',
				'gross_amount'            => 19,
				'event_type'              => 'grant',
				'event_status'            => 'applied',
				'idempotency_key'         => $this->state['idempotency_key'],
				'source'                  => self::FIXTURE_SOURCE,
				'applied_at'              => gmdate( 'Y-m-d H:i:s' ),
			) );
			$append_ok = is_array( $append ) && ! empty( $append['success'] );
			$ctx->emit_step( array( 'label' => 'Runtime · expired grant fixture', 'status' => $append_ok ? 'pass' : 'fail', 'detail' => $append_ok ? 'Created one disposable expired exact-key grant.' : 'Could not create the expired exact-key fixture grant.' ) );
			if ( ! $append_ok ) {
				return array( 'status' => 'fail', 'summary' => 'H5 expired grant fixture creation failed.', 'error' => 'expired_grant_append_failed', 'fix_hint' => 'Verify the Global ledger insert path and rerun the H5 probe.' );
			}

			$projection = BizCity_Router_License_Service::project_from_ledger( $key_id, array( 'client_id' => 'diag_h5_expiry', 'user_id' => $owner_id, 'owner_user_id' => $owner_id, 'order_id' => (int) $this->state['order_id'] ) );
			$expiry_gate_ok = is_array( $projection ) && ! empty( $projection['success'] ) && 'expired' === (string) ( $projection['license_status'] ?? '' ) && 'free' === sanitize_key( (string) ( $projection['plan_code'] ?? '' ) ) && ! BizCity_Router_License_Service::is_key_license_effectively_active( $key_id );
			$ctx->emit_step( array( 'label' => 'Runtime · request-time expiry gate', 'status' => $expiry_gate_ok ? 'pass' : 'fail', 'detail' => $expiry_gate_ok ? 'Expired paid projection is ineffective immediately and resolves to Free before maintenance.' : 'Expired paid projection remained effective at request time.' ) );
			if ( ! $expiry_gate_ok ) {
				return array( 'status' => 'fail', 'summary' => 'Request-time expiry enforcement failed.', 'error' => 'request_time_expiry_failed', 'fix_hint' => 'Check exact-key license_status/license_expires_at enforcement before cron.' );
			}

			$maintenance = method_exists( 'BizCity_Router_Schema', 'maintain_license_expiry_batch' )
				? BizCity_Router_Schema::maintain_license_expiry_batch( 10, array( 'diagnostics_fixture' => true ) )
				: array( 'status' => 'deferred', 'reason' => 'expiry_cron_wrapper_missing' );
			$checkpoint = get_option( BizCity_Router_License_Service::EXPIRY_CHECKPOINT_OPTION, array() );
			$maintenance_ok = is_array( $maintenance ) && 'pass' === (string) ( $maintenance['status'] ?? '' ) && absint( $maintenance['scanned'] ?? 0 ) >= 1 && absint( $maintenance['projection_rebuilt'] ?? 0 ) >= 1 && 0 === absint( $maintenance['failed'] ?? 0 ) && is_array( $checkpoint ) && '' !== (string) ( $checkpoint['completed_at'] ?? '' ) && 0 === absint( $checkpoint['last_id'] ?? 0 );
			$ctx->emit_step( array( 'label' => 'Runtime · bounded expiry callback/checkpoint', 'status' => $maintenance_ok ? 'pass' : 'fail', 'detail' => $maintenance_ok ? 'The named Router Schema expiry callback rebuilt the expired projection, persisted a completed checkpoint and reported no failures.' : 'Bounded expiry callback or checkpoint completion did not meet the contract.' ) );
			if ( ! $maintenance_ok ) {
				return array( 'status' => 'fail', 'summary' => 'Bounded expiry maintenance failed.', 'error' => 'expiry_maintenance_failed', 'fix_hint' => 'Verify expiry batch limit, ledger cursor checkpoint and projection rebuild result.' );
			}

			$cron_meta_ok = class_exists( 'BizCity_Cron_Manager' ) && method_exists( 'BizCity_Cron_Manager', 'instance' );
			$ctx->emit_step( array( 'label' => 'Loader · R-CRON-META owner', 'status' => $cron_meta_ok ? 'pass' : 'warn', 'detail' => $cron_meta_ok ? 'Cron Manager is available for per-run expiry counters/events.' : 'Cron Manager is not loaded in this isolated diagnostics runtime; production cron metadata remains pending.' ) );
			$cron_registered = function_exists( 'has_action' )
				&& false !== has_action( 'bizcity_llm_router_daily_aggregate', array( 'BizCity_Router_Schema', 'maintain_license_expiry_batch' ) )
				&& function_exists( 'wp_next_scheduled' )
				&& (bool) wp_next_scheduled( 'bizcity_llm_router_daily_aggregate' );
			$ctx->emit_step( array( 'label' => 'Loader/Runtime · expiry cron registration', 'status' => $cron_registered ? 'pass' : 'fail', 'detail' => $cron_registered ? 'Daily Router hook is scheduled and points to the isolated bounded license maintenance wrapper.' : 'Daily Router hook or bounded license maintenance wrapper is not registered.' ) );
			if ( ! $cron_registered ) {
				return array( 'status' => 'fail', 'summary' => 'Expiry maintenance fixture passed but the production cron registration boundary is incomplete.', 'error' => 'expiry_cron_registration_missing', 'fix_hint' => 'Register the isolated Router Schema expiry wrapper on bizcity_llm_router_daily_aggregate and schedule it before rerunning the focused H5 probe.' );
			}
			return array( 'status' => 'pass', 'summary' => 'Request-time expiry denied expired paid access, bounded maintenance rebuilt the projection and checkpointed completion, the lock path completed safely, and the daily Router expiry wrapper is registered.', 'cron_meta_available' => $cron_meta_ok, 'cron_callback_registered' => true, 'production_callback_execution' => 'pending' );
		} catch ( Throwable $e ) {
			return array( 'status' => 'fail', 'summary' => 'H5 expiry fixture threw an exception.', 'error' => 'fixture_exception', 'fix_hint' => 'Inspect the redacted H5 diagnostics path and rerun after resolving the local contract failure.', 'exception_class' => get_class( $e ) );
		} finally {
			$cleanup_ok = $this->cleanup_fixture();
			$ctx->emit_step( array( 'label' => 'Fixture · full cleanup', 'status' => $cleanup_ok ? 'pass' : 'fail', 'detail' => $cleanup_ok ? 'Temporary expiry rows, checkpoint and projection state were removed and the original key plan was restored.' : 'One or more H5 artifacts remain and will be retried on the next probe run.' ) );
			if ( ! $cleanup_ok ) {
				throw new RuntimeException( 'fixture_cleanup_failed' );
			}
		}
	}

	public function cleanup(): void {
		// [2026-09-04 10:30 AM Johnny Chu - Chu Hoàng Anh] B2C-H5 - retry persisted expiry fixture cleanup after interruption.
		$this->load_persisted_state();
		$this->cleanup_fixture();
	}

	private function load_persisted_state() {
		$state = get_site_option( self::FIXTURE_OPTION, array() );
		$this->state = is_array( $state ) ? $state : array();
	}

	private function persist_state() {
		if ( empty( $this->state ) ) {
			delete_site_option( self::FIXTURE_OPTION );
			return;
		}
		update_site_option( self::FIXTURE_OPTION, $this->state );
	}

	private function cleanup_fixture() {
		if ( empty( $this->state ) ) {
			return true;
		}
		$cleanup_ok = true;
		global $wpdb;
		$key_id = absint( $this->state['key_id'] ?? 0 );
		$owner_id = absint( $this->state['owner_user_id'] ?? 0 );
		if ( $key_id > 0 && $owner_id > 0 && class_exists( 'BizCity_Router_License_Ledger' ) ) {
			$deleted = $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . BizCity_Router_License_Ledger::table_name() . ' WHERE source = %s AND key_id = %d AND owner_user_id = %d', self::FIXTURE_SOURCE, $key_id, $owner_id ) );
			if ( false === $deleted ) {
				$cleanup_ok = false;
			}
		}
		$checkpoint = $this->state['prior_checkpoint'] ?? array();
		if ( is_array( $checkpoint ) && ! empty( $checkpoint ) ) {
			update_option( BizCity_Router_License_Service::EXPIRY_CHECKPOINT_OPTION, $checkpoint, false );
		} else {
			delete_option( BizCity_Router_License_Service::EXPIRY_CHECKPOINT_OPTION );
		}
		if ( $key_id > 0 && $owner_id > 0 && class_exists( 'BizCity_Router_Master_Schema' ) && method_exists( 'BizCity_Router_Master_Schema', 'set_key_level' ) ) {
			$cleanup_ok = BizCity_Router_Master_Schema::set_key_level( $key_id, sanitize_key( (string) ( $this->state['original_level'] ?? 'free' ) ) ) && $cleanup_ok;
		}
		$entitlement_key = (string) ( $this->state['entitlement_key'] ?? '' );
		if ( $entitlement_key !== '' ) {
			$all = (array) get_site_option( BizCity_Router_License_Service::OPTION_ENTITLEMENTS, array() );
			if ( is_array( $this->state['prior_entitlement'] ?? null ) ) {
				$all[ $entitlement_key ] = $this->state['prior_entitlement'];
			} else {
				unset( $all[ $entitlement_key ] );
			}
			update_site_option( BizCity_Router_License_Service::OPTION_ENTITLEMENTS, $all );
		}
		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::flush_group( BizCity_Router_License_Ledger::CACHE_GROUP );
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
	$list[] = 'BizCity_Probe_B2B2C_Expiry_Lifecycle';
	return $list;
} );
