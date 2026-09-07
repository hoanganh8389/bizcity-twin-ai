<?php
/**
 * H4 probe for the cumulative exact-key entitlement projector.
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false ) ) {
	return;
}

if ( class_exists( 'BizCity_Probe_B2B2C_Entitlement_Projector', false ) ) {
	return;
}

final class BizCity_Probe_B2B2C_Entitlement_Projector implements BizCity_Diagnostics_Probe {

	const FIXTURE_OPTION = 'bizcity_diag_b2b2c_entitlement_projector_fixture';
	const FIXTURE_SOURCE = 'diagnostics_h4_projector';

	public function id(): string {
		// [2026-09-03 10:35 AM Johnny Chu - Chu Hoàng Anh] B2C-H4 - identify the cumulative exact-key projector probe.
		return 'b2b2c.checkout.entitlement_projector';
	}

	public function label(): string {
		return 'B2B2C cumulative exact-key entitlement projector';
	}

	public function description(): string {
		return 'Creates two disposable exact-key ledger grants, verifies cumulative projection and replay idempotency, then restores the original key state.';
	}

	public function severity(): string {
		return 'critical';
	}

	public function order(): int {
		return 25;
	}

	public function icon(): string {
		return 'layers-3';
	}

	public function estimate_ms(): int {
		return 180;
	}

	public function precondition() {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( preg_replace( '/:\d+$/', '', (string) $_SERVER['HTTP_HOST'] ) ) : '';
		if ( ! in_array( $host, array( 'bizcity.vn', 'www.bizcity.vn' ), true ) ) {
			return 'not_applicable_b2_client: cumulative Hub license projection is owned by bizcity.vn.';
		}
		if ( ! class_exists( 'BizCity_Router_License_Service' ) || ! class_exists( 'BizCity_Router_License_Ledger' ) ) {
			return new WP_Error( 'entitlement_projector_loader_missing', 'Cumulative entitlement projector classes are not loaded.' );
		}
		if ( ! function_exists( 'bizcity_tbl_exists' ) || ! bizcity_tbl_exists( BizCity_Router_License_Ledger::table_name() ) ) {
			return 'ledger_runtime_missing: H4 fixture requires the Global license ledger table.';
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-09-03 10:35 AM Johnny Chu - Chu Hoàng Anh] B2C-H4 - verify cumulative projector with a recoverable disposable two-grant exact-key fixture.
		$this->load_persisted_state();
		if ( ! $this->cleanup_fixture() ) {
			return array( 'status' => 'fail', 'summary' => 'A previous H4 projector fixture could not be cleaned safely.', 'error' => 'stale_fixture_cleanup_failed', 'fix_hint' => 'Resolve the persisted disposable H4 fixture before rerunning this probe.' );
		}
		$this->state = array();
		$this->persist_state();

		try {
			$source_ok = method_exists( 'BizCity_Router_License_Service', 'project_from_ledger' )
				&& method_exists( 'BizCity_Router_License_Ledger', 'get_grants_for_key' )
				&& method_exists( 'BizCity_Router_License_Ledger', 'acquire_key_lock' )
				&& method_exists( 'BizCity_Router_License_Ledger', 'release_key_lock' );
			$ctx->emit_step( array(
				'label'  => 'Disk/Loader - cumulative projector owner',
				'status' => $source_ok ? 'pass' : 'fail',
				'detail' => $source_ok ? 'License Service projector and exact-key ledger serialization methods are loaded.' : 'Cumulative projector or ledger serialization methods are missing.',
			) );
			if ( ! $source_ok ) {
				return array( 'status' => 'fail', 'summary' => 'Cumulative exact-key projector methods are missing.', 'error' => 'entitlement_projector_methods_missing', 'fix_hint' => 'Deploy the projector and ledger serialization methods, then rerun the focused H4 probe.' );
			}

			global $wpdb;
			$key = $wpdb->get_row( "SELECT k.id, k.user_id, k.master_level FROM {$wpdb->base_prefix}bizcity_llm_api_keys k LEFT JOIN " . BizCity_Router_License_Ledger::table_name() . " l ON l.key_id = k.id AND l.event_type = 'grant' AND l.event_status = 'applied' WHERE k.is_active = 1 AND l.id IS NULL ORDER BY k.id ASC LIMIT 1", ARRAY_A );
			if ( ! is_array( $key ) || absint( $key['id'] ?? 0 ) <= 0 || absint( $key['user_id'] ?? 0 ) <= 0 ) {
				return array( 'status' => 'warn', 'summary' => 'No active exact key without existing ledger grants is available for the disposable H4 fixture.', 'error' => 'fixture_key_missing', 'fix_hint' => 'Use a disposable B1 key with no existing ledger grant, then rerun the focused H4 probe.' );
			}

			$key_id = absint( $key['id'] );
			$owner_id = absint( $key['user_id'] );
			$entitlement_key = 'key:' . $key_id;
			$all_entitlements = (array) get_site_option( BizCity_Router_License_Service::OPTION_ENTITLEMENTS, array() );
			$this->state = array(
				'key_id'            => $key_id,
				'owner_user_id'     => $owner_id,
				'original_level'    => sanitize_key( (string) ( $key['master_level'] ?? 'free' ) ),
				'entitlement_key'   => $entitlement_key,
				'prior_entitlement' => isset( $all_entitlements[ $entitlement_key ] ) && is_array( $all_entitlements[ $entitlement_key ] ) ? $all_entitlements[ $entitlement_key ] : null,
				'order_id_a'        => 980000000 + mt_rand( 1000, 399999 ),
				'order_id_b'        => 980000000 + mt_rand( 400000, 799999 ),
				'idempotency_prefix' => 'diag_h4:' . strtolower( str_replace( '-', '', wp_generate_uuid4() ) ),
				'concurrency_prefix' => 'diag_h4_concurrent:' . strtolower( str_replace( '-', '', wp_generate_uuid4() ) ),
			);
			$this->persist_state();

			$start_a = gmdate( 'Y-m-d H:i:s', time() - 60 );
			$end_a   = gmdate( 'Y-m-d H:i:s', strtotime( '+30 days', strtotime( $start_a ) ) );
			$end_b   = gmdate( 'Y-m-d H:i:s', strtotime( '+30 days', strtotime( $end_a ) ) );
			$common = array(
				'event_uuid'              => wp_generate_uuid4(),
				'issuer_hub_id'           => 'bizcity',
				'commerce_hub_id'         => 'bizcity',
				'woo_site_id'             => function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 1,
				'key_id'                  => $key_id,
				'owner_user_id'           => $owner_id,
				'allowed_domain_snapshot' => 'diagnostics-h4.example.com',
				'plan_code'               => 'master_pro',
				'offer_product_id'        => 999991,
				'offer_variation_id'      => 0,
				'duration_days'           => 30,
				'quantity'                => 1,
				'currency'                => 'USD',
				'gross_amount'            => 19,
				'event_type'              => 'grant',
				'event_status'            => 'applied',
				'source'                  => 'diagnostics_h4_projector',
				'applied_at'              => gmdate( 'Y-m-d H:i:s' ),
			);
			$record_a = array_merge( $common, array( 'woo_order_id' => (int) $this->state['order_id_a'], 'woo_order_item_id' => 1, 'period_start_at' => $start_a, 'period_end_at' => $end_a, 'idempotency_key' => $this->state['idempotency_prefix'] . ':a' ) );
			$record_b = array_merge( $common, array( 'event_uuid' => wp_generate_uuid4(), 'woo_order_id' => (int) $this->state['order_id_b'], 'woo_order_item_id' => 1, 'period_start_at' => $end_a, 'period_end_at' => $end_b, 'idempotency_key' => $this->state['idempotency_prefix'] . ':b' ) );
			$end_c = gmdate( 'Y-m-d H:i:s', strtotime( '+30 days', strtotime( $end_b ) ) );
			$end_d = gmdate( 'Y-m-d H:i:s', strtotime( '+30 days', strtotime( $end_c ) ) );
			$record_c = array_merge( $common, array( 'event_uuid' => wp_generate_uuid4(), 'woo_order_id' => (int) $this->state['order_id_b'] + 1, 'woo_order_item_id' => 1, 'plan_code' => 'master_premium', 'period_start_at' => $start_a, 'period_end_at' => $end_c, 'idempotency_key' => $this->state['idempotency_prefix'] . ':c' ) );
			$record_d = array_merge( $common, array( 'event_uuid' => wp_generate_uuid4(), 'woo_order_id' => (int) $this->state['order_id_b'] + 2, 'woo_order_item_id' => 1, 'period_start_at' => $end_c, 'period_end_at' => $end_d, 'idempotency_key' => $this->state['idempotency_prefix'] . ':d' ) );
			$record_e = array_merge( $common, array( 'event_uuid' => wp_generate_uuid4(), 'woo_order_id' => (int) $this->state['order_id_b'] + 3, 'woo_order_item_id' => 2, 'period_start_at' => gmdate( 'Y-m-d H:i:s' ), 'period_end_at' => '', 'idempotency_key' => $this->state['concurrency_prefix'] . ':e' ) );
			$record_f = array_merge( $common, array( 'event_uuid' => wp_generate_uuid4(), 'woo_order_id' => (int) $this->state['order_id_b'] + 4, 'woo_order_item_id' => 2, 'period_start_at' => gmdate( 'Y-m-d H:i:s' ), 'period_end_at' => '', 'idempotency_key' => $this->state['concurrency_prefix'] . ':f' ) );

			$lock_ok = BizCity_Router_License_Ledger::acquire_key_lock( $key_id );
			if ( $lock_ok ) {
				BizCity_Router_License_Ledger::release_key_lock( $key_id );
			}
			$ctx->emit_step( array( 'label' => 'Runtime · exact-key serialization lock', 'status' => $lock_ok ? 'pass' : 'fail', 'detail' => $lock_ok ? 'The per-key MySQL named lock can be acquired and released.' : 'The per-key serialization lock could not be acquired.' ) );
			if ( ! $lock_ok ) {
				return array( 'status' => 'fail', 'summary' => 'H4 exact-key serialization lock failed.', 'error' => 'key_lock_failed', 'fix_hint' => 'Verify Global DB GET_LOCK support and rerun the H4 probe.' );
			}

			$append_a = BizCity_Router_License_Ledger::append_grant( $record_a );
			$append_b = BizCity_Router_License_Ledger::append_grant( $record_b );
			$append_ok = ! empty( $append_a['success'] ) && ! empty( $append_b['success'] );
			$ctx->emit_step( array( 'label' => 'Runtime · two cumulative ledger grants', 'status' => $append_ok ? 'pass' : 'fail', 'detail' => $append_ok ? 'Two 30-day grants were appended for one exact key with contiguous periods.' : 'The disposable cumulative grant rows could not be appended.' ) );
			if ( ! $append_ok ) {
				return array( 'status' => 'fail', 'summary' => 'H4 cumulative ledger fixture could not append two grants.', 'error' => 'grant_append_failed', 'fix_hint' => 'Inspect the Global ledger insert and idempotency constraints.' );
			}

			$projection = BizCity_Router_License_Service::project_from_ledger( $key_id, array( 'client_id' => 'diag_h4_projector', 'user_id' => $owner_id, 'owner_user_id' => $owner_id ) );
			$projection_ok = is_array( $projection ) && ! empty( $projection['success'] ) && absint( $projection['stacked_duration_days'] ?? 0 ) >= 60 && 'active' === (string) ( $projection['license_status'] ?? '' ) && (int) ( $projection['license_ledger_cursor'] ?? 0 ) >= max( absint( $append_a['id'] ?? 0 ), absint( $append_b['id'] ?? 0 ) );
			$ctx->emit_step( array( 'label' => 'Runtime · cumulative projection', 'status' => $projection_ok ? 'pass' : 'fail', 'detail' => $projection_ok ? 'The exact-key projection reports active status, at least 60 stacked days and the latest ledger cursor.' : 'The projection did not reflect both cumulative grants.' ) );
			if ( ! $projection_ok ) {
				return array( 'status' => 'fail', 'summary' => 'H4 cumulative exact-key projection failed.', 'error' => 'projection_stack_failed', 'fix_hint' => 'Verify ledger period ordering, projection cursor and exact-key entitlement persistence.' );
			}

			$replay = BizCity_Router_License_Ledger::append_grant( $record_a );
			$projection_after_replay = BizCity_Router_License_Service::project_from_ledger( $key_id, array( 'client_id' => 'diag_h4_projector', 'user_id' => $owner_id, 'owner_user_id' => $owner_id ) );
			$replay_ok = ! empty( $replay['success'] ) && ! empty( $replay['replayed'] ) && is_array( $projection_after_replay ) && absint( $projection_after_replay['stacked_duration_days'] ?? 0 ) === absint( $projection['stacked_duration_days'] ?? 0 ) && (string) ( $projection_after_replay['expires_at'] ?? '' ) === (string) ( $projection['expires_at'] ?? '' );
			$ctx->emit_step( array( 'label' => 'Runtime · duplicate grant replay', 'status' => $replay_ok ? 'pass' : 'fail', 'detail' => $replay_ok ? 'Replaying the first grant returned the existing row and added no duration.' : 'Replay changed the cumulative projection or was not recognized as idempotent.' ) );
			if ( ! $replay_ok ) {
				return array( 'status' => 'fail', 'summary' => 'H4 duplicate grant replay was not idempotent.', 'error' => 'grant_replay_not_idempotent', 'fix_hint' => 'Keep ledger uniqueness and rebuild projection from immutable rows without adding replay duration.' );
			}

			$append_c = BizCity_Router_License_Ledger::append_grant( $record_c );
			$append_d = BizCity_Router_License_Ledger::append_grant( $record_d );
			$transition_append_ok = ! empty( $append_c['success'] ) && ! empty( $append_d['success'] );
			$ctx->emit_step( array( 'label' => 'Runtime · upgrade and downgrade schedule grants', 'status' => $transition_append_ok ? 'pass' : 'fail', 'detail' => $transition_append_ok ? 'Higher-rank and lower-rank transition grants were appended for the same exact key.' : 'The transition grant rows could not be appended.' ) );
			if ( ! $transition_append_ok ) {
				return array( 'status' => 'fail', 'summary' => 'H4 upgrade/downgrade fixture grants could not be appended.', 'error' => 'transition_grant_append_failed', 'fix_hint' => 'Verify exact-key ledger inserts before testing plan transition projection.' );
			}
			$transition_projection = BizCity_Router_License_Service::project_from_ledger( $key_id, array( 'client_id' => 'diag_h4_projector', 'user_id' => $owner_id, 'owner_user_id' => $owner_id ) );
			$transition_ok = is_array( $transition_projection ) && ! empty( $transition_projection['success'] ) && 'active' === (string) ( $transition_projection['license_status'] ?? '' ) && 'master_premium' === sanitize_key( (string) ( $transition_projection['plan_code'] ?? '' ) ) && 'master_pro' === sanitize_key( (string) ( $transition_projection['scheduled_plan_code'] ?? '' ) ) && ! empty( $transition_projection['scheduled_started_at'] );
			$ctx->emit_step( array( 'label' => 'Runtime · upgrade activation and downgrade scheduling', 'status' => $transition_ok ? 'pass' : 'fail', 'detail' => $transition_ok ? 'Higher-rank Premium is active immediately and lower-rank Pro is scheduled after the paid period.' : 'The projection did not preserve higher-rank activation and lower-rank scheduling.' ) );
			if ( ! $transition_ok ) {
				return array( 'status' => 'fail', 'summary' => 'H4 upgrade/downgrade projection semantics failed.', 'error' => 'plan_transition_projection_failed', 'fix_hint' => 'Select the highest active plan now and retain the lower-rank future period as scheduled metadata.' );
			}

			$concurrency_ok = false;
			$concurrency_worker = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR . 'bin/license-ledger-concurrency-worker.php' : '';
			if ( $concurrency_worker !== '' && is_file( $concurrency_worker ) && is_readable( $concurrency_worker ) && function_exists( 'proc_open' ) && function_exists( 'proc_close' ) ) {
				$worker_host = sanitize_text_field( (string) ( $_SERVER['HTTP_HOST'] ?? 'bizcity.vn' ) );
				$worker_records = array( $record_e, $record_f );
				$children = array();
				$stderr_sink = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
				foreach ( $worker_records as $worker_record ) {
					$parts = array( PHP_BINARY, $concurrency_worker, ABSPATH . 'wp-load.php', base64_encode( wp_json_encode( $worker_record ) ), $worker_host );
					$command = implode( ' ', array_map( array( __CLASS__, 'quote_process_arg' ), $parts ) );
					// [2026-09-04 08:20 AM Johnny Chu - Chu Hoàng Anh] B2C-H4 — discard child bootstrap diagnostics so stderr cannot fill and deadlock the parent fixture reader.
					$process = proc_open( $command, array( 1 => array( 'pipe', 'w' ), 2 => array( 'file', $stderr_sink, 'a' ) ), $pipes, ABSPATH );
					if ( is_resource( $process ) ) {
						$children[] = array( 'process' => $process, 'pipes' => $pipes );
					}
				}
				$receipts = array();
				$child_exit_codes = array();
				foreach ( $children as $child ) {
					$output = stream_get_contents( $child['pipes'][1] );
					fclose( $child['pipes'][1] );
					$child_exit_codes[] = (int) proc_close( $child['process'] );
					if ( preg_match( '/__BIZCITY_H4__([A-Za-z0-9+\/=]+)/', $output, $matches ) ) {
						$decoded = base64_decode( $matches[1], true );
						$receipt = is_string( $decoded ) ? json_decode( $decoded, true ) : null;
						if ( is_array( $receipt ) ) {
							$receipts[] = $receipt;
						}
					}
				}
				if ( class_exists( 'BizCity_Cache' ) ) {
					// [2026-09-04 08:45 AM Johnny Chu - Chu Hoàng Anh] B2C-H4 — invalidate the parent snapshot after child commits before verifying concurrent periods.
					BizCity_Cache::flush_group( BizCity_Router_License_Ledger::CACHE_GROUP );
				}
				$rows_e = BizCity_Router_License_Ledger::get_grants_for_key( $key_id, true );
				$fixture_prefix = (string) $this->state['concurrency_prefix'];
				$parallel_rows = array_values( array_filter( $rows_e, function ( $row ) use ( $fixture_prefix ) {
					return strpos( (string) ( $row['source'] ?? '' ), 'woo_order_paid' ) === false && strpos( (string) ( $row['idempotency_key'] ?? '' ), $fixture_prefix . ':' ) === 0;
				} ) );
				usort( $parallel_rows, function ( $left, $right ) { return (int) $left['id'] <=> (int) $right['id']; } );
				$concurrency_ok = count( $receipts ) === 2 && count( array_filter( $receipts, function ( $receipt ) { return ! empty( $receipt['success'] ); } ) ) === 2 && count( $parallel_rows ) === 2 && absint( $parallel_rows[0]['duration_days'] ?? 0 ) + absint( $parallel_rows[1]['duration_days'] ?? 0 ) === 60 && (string) $parallel_rows[1]['period_start_at'] === (string) $parallel_rows[0]['period_end_at'];
				$concurrency_detail = $concurrency_ok
					? 'Two independent PHP workers serialized period allocation for one exact key without lost duration.'
					: 'receipts=' . count( $receipts ) . ', exit_codes=' . wp_json_encode( $child_exit_codes ) . ', rows=' . count( $parallel_rows ) . ', periods=' . wp_json_encode( array_map( function ( $row ) { return array( 'id' => absint( $row['id'] ?? 0 ), 'duration_days' => absint( $row['duration_days'] ?? 0 ), 'start' => (string) ( $row['period_start_at'] ?? '' ), 'end' => (string) ( $row['period_end_at'] ?? '' ) ); }, $parallel_rows ) ) . ', receipts_ok=' . count( array_filter( $receipts, function ( $receipt ) { return ! empty( $receipt['success'] ); } ) );
			} else {
				$concurrency_detail = 'worker_unavailable';
			}
			$ctx->emit_step( array( 'label' => 'Runtime · concurrent paid callbacks', 'status' => $concurrency_ok ? 'pass' : 'fail', 'detail' => $concurrency_detail ) );
			if ( ! $concurrency_ok ) {
				return array( 'status' => 'fail', 'summary' => 'H4 concurrent paid callbacks were not serialized correctly.', 'error' => 'concurrent_period_allocation_failed', 'fix_hint' => 'Ensure two independent PHP workers use the shared exact-key period lock and leave two contiguous ledger periods.' );
			}

			$reversal_a = BizCity_Router_License_Ledger::append_grant( array_merge( $record_a, array( 'event_uuid' => wp_generate_uuid4(), 'event_type' => 'reversal', 'idempotency_key' => $this->state['idempotency_prefix'] . ':reversal_a' ) ) );
			$reversal_b = BizCity_Router_License_Ledger::append_grant( array_merge( $record_b, array( 'event_uuid' => wp_generate_uuid4(), 'event_type' => 'reversal', 'idempotency_key' => $this->state['idempotency_prefix'] . ':reversal_b' ) ) );
			$reversal_c = BizCity_Router_License_Ledger::append_grant( array_merge( $record_c, array( 'event_uuid' => wp_generate_uuid4(), 'event_type' => 'reversal', 'idempotency_key' => $this->state['idempotency_prefix'] . ':reversal_c' ) ) );
			$reversal_d = BizCity_Router_License_Ledger::append_grant( array_merge( $record_d, array( 'event_uuid' => wp_generate_uuid4(), 'event_type' => 'reversal', 'idempotency_key' => $this->state['idempotency_prefix'] . ':reversal_d' ) ) );
			$reversal_e = BizCity_Router_License_Ledger::append_grant( array_merge( $record_e, array( 'event_uuid' => wp_generate_uuid4(), 'event_type' => 'reversal', 'idempotency_key' => $this->state['concurrency_prefix'] . ':reversal_e' ) ) );
			$reversal_f = BizCity_Router_License_Ledger::append_grant( array_merge( $record_f, array( 'event_uuid' => wp_generate_uuid4(), 'event_type' => 'reversal', 'idempotency_key' => $this->state['concurrency_prefix'] . ':reversal_f' ) ) );
			$reversal_append_ok = ! empty( $reversal_a['success'] ) && ! empty( $reversal_b['success'] ) && ! empty( $reversal_c['success'] ) && ! empty( $reversal_d['success'] ) && ! empty( $reversal_e['success'] ) && ! empty( $reversal_f['success'] );
			$ctx->emit_step( array( 'label' => 'Runtime · append-only refund reversals', 'status' => $reversal_append_ok ? 'pass' : 'fail', 'detail' => $reversal_append_ok ? 'Six compensating reversal rows were appended without changing the original grants.' : 'The disposable reversal rows could not be appended.' ) );
			if ( ! $reversal_append_ok ) {
				return array( 'status' => 'fail', 'summary' => 'H4 refund reversal fixture could not append compensating events.', 'error' => 'reversal_append_failed', 'fix_hint' => 'Verify append-only reversal event uniqueness and Global ledger writes.' );
			}

			$projection_after_refund = BizCity_Router_License_Service::project_from_ledger( $key_id, array( 'client_id' => 'diag_h4_projector', 'user_id' => $owner_id, 'owner_user_id' => $owner_id, 'order_id' => (int) $this->state['order_id_b'] ) );
			$refund_ok = is_array( $projection_after_refund ) && ! empty( $projection_after_refund['success'] ) && 'expired' === (string) ( $projection_after_refund['license_status'] ?? '' ) && 'free' === sanitize_key( (string) ( $projection_after_refund['plan_code'] ?? '' ) ) && 0 === absint( $projection_after_refund['stacked_duration_days'] ?? 0 );
			$ctx->emit_step( array( 'label' => 'Runtime · refund projection rollback', 'status' => $refund_ok ? 'pass' : 'fail', 'detail' => $refund_ok ? 'Reversed grants no longer contribute duration and the exact key projects to effective Free.' : 'The projection retained effective paid access after all grants were reversed.' ) );
			if ( ! $refund_ok ) {
				return array( 'status' => 'fail', 'summary' => 'H4 refund reversal did not rebuild the exact key to Free.', 'error' => 'refund_projection_failed', 'fix_hint' => 'Exclude applied reversal-matched grants from projection and persist the exact-key Free state.' );
			}

			return array( 'status' => 'pass', 'summary' => 'Two 30-day exact-key grants stacked to at least 60 days, duplicate replay added no duration, and append-only refund reversals rolled the exact key back to Free.' );
		} catch ( Throwable $e ) {
			return array( 'status' => 'fail', 'summary' => 'H4 projector fixture threw an exception.', 'error' => 'fixture_exception', 'fix_hint' => 'Inspect the redacted H4 diagnostics path and rerun after resolving the local contract failure.', 'exception_class' => get_class( $e ) );
		} finally {
			$cleanup_ok = $this->cleanup_fixture();
			$ctx->emit_step( array( 'label' => 'Fixture · full cleanup', 'status' => $cleanup_ok ? 'pass' : 'fail', 'detail' => $cleanup_ok ? 'Temporary ledger rows and projection state were removed and the original key plan was restored.' : 'One or more temporary H4 artifacts remain and will be retried on the next probe run.' ) );
			if ( ! $cleanup_ok ) {
				throw new RuntimeException( 'fixture_cleanup_failed' );
			}
		}
	}

	public function cleanup(): void {
		// [2026-09-03 10:35 AM Johnny Chu - Chu Hoàng Anh] B2C-H4 - retry persisted fixture cleanup after pass, fail or interruption.
		$this->load_persisted_state();
		$this->cleanup_fixture();
	}

	private static function quote_process_arg( $value ) {
		// [2026-09-03 12:20 PM Johnny Chu - Chu Hoàng Anh] B2C-H4 — keep the disposable worker command portable across Windows local CLI and POSIX B1 CLI.
		$value = (string) $value;
		if ( DIRECTORY_SEPARATOR === '\\' ) {
			return preg_match( '/[\\s"]/', $value ) ? '"' . str_replace( '"', '\\"', $value ) . '"' : $value;
		}
		return escapeshellarg( $value );
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
		if ( $key_id > 0 && $owner_id > 0 ) {
			$deleted = $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . BizCity_Router_License_Ledger::table_name() . ' WHERE source = %s AND key_id = %d AND owner_user_id = %d', 'diagnostics_h4_projector', $key_id, $owner_id ) );
			if ( false === $deleted ) {
				$cleanup_ok = false;
			}
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
	$list[] = 'BizCity_Probe_B2B2C_Entitlement_Projector';
	return $list;
} );
