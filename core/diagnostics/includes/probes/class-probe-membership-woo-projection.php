<?php
/**
 * BizCity Diagnostics — core.membership.woo_projection probe (SPRINT-9 WC-1/WC-2).
 *
 * R-DDV 3 layers:
 * - Disk: projector file readable + projection markers + Woo hooks.
 * - Loader: projector class loaded and hooks registered.
 * - Runtime: paid order applied once, second call idempotent, unpaid order rejected,
 *            capacity-blocked path does not activate plan.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-07-17
 */

// [2026-07-17 Johnny Chu] SPRINT-9 WC-2 — Woo projection DDV probe.
defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false ) ) {
	$_iface_path = defined( 'BIZCITY_DIAGNOSTICS_DIR' )
		? BIZCITY_DIAGNOSTICS_DIR . 'includes/interface-diagnostics-probe.php'
		: dirname( __DIR__ ) . '/interface-diagnostics-probe.php';
	if ( is_readable( $_iface_path ) ) {
		require_once $_iface_path;
	}
}
if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false ) ) {
	return;
}

if ( class_exists( 'BizCity_Probe_Membership_Woo_Projection', false ) ) {
	return;
}

final class BizCity_Probe_Membership_Woo_Projection implements BizCity_Diagnostics_Probe {

	const SYNTH_PREFIX = 'diag_woo_projection_';

	public function id(): string          { return 'core.membership.woo_projection'; }
	public function label(): string       { return 'Membership · Woo Projection (SPRINT-9)'; }
	public function description(): string {
		return 'WC-1/WC-2 DDV: idempotent Woo paid-order projector + capacity-blocked fail-closed path.';
	}
	public function severity(): string    { return 'warning'; }
	public function order(): int          { return 67; }
	public function icon(): string        { return 'ShoppingCart'; }
	public function estimate_ms(): int    { return 180; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_Membership_Woo_Projector' ) ) {
			return new WP_Error( 'no_projector', 'BizCity_Membership_Woo_Projector chưa load — kiểm tra core/membership/bootstrap.php.' );
		}
		if ( ! class_exists( 'BizCity_Membership_Woo_Mapper' ) ) {
			return new WP_Error( 'no_mapper', 'BizCity_Membership_Woo_Mapper chưa load.' );
		}
		if ( ! class_exists( 'BizCity_Membership_Manager' ) ) {
			return new WP_Error( 'no_manager', 'BizCity_Membership_Manager chưa load.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		$steps = array();

		$projector_file = $this->resolve_plugin_file( 'core/membership/includes/class-membership-woo-projector.php' );
		$disk_readable  = ( $projector_file !== '' && is_readable( $projector_file ) );
		$src            = $disk_readable ? (string) file_get_contents( $projector_file ) : '';
		$disk_ok        = $src !== ''
			&& strpos( $src, 'woocommerce_payment_complete' ) !== false
			&& strpos( $src, 'woocommerce_order_refunded' ) !== false
			&& strpos( $src, 'reverse_projection' ) !== false
			&& strpos( $src, '_bizcity_membership_projection_status' ) !== false
			&& strpos( $src, 'project_order' ) !== false
			&& strpos( $src, 'capacity_blocked' ) !== false;

		$step = array(
			'label'  => 'Disk · projector file + hook + marker contract',
			'status' => ( $disk_readable && $disk_ok ) ? 'pass' : 'fail',
			'detail' => $disk_readable
				? ( $disk_ok ? 'markers found (hooks, projection status, capacity bucket)' : 'marker missing in projector source' )
				: 'projector file not readable',
		);
		$steps[] = $step;
		$ctx->emit_step( $step );

		$woo_active = class_exists( 'WooCommerce' ) && function_exists( 'wc_get_order' ) && function_exists( 'wc_create_order' );
		$loader_ok  = class_exists( 'BizCity_Membership_Woo_Projector' );
		$hook_payment    = (int) has_action( 'woocommerce_payment_complete', array( 'BizCity_Membership_Woo_Projector', 'on_payment_complete' ) );
		$hook_processing = (int) has_action( 'woocommerce_order_status_processing', array( 'BizCity_Membership_Woo_Projector', 'on_order_status_processing' ) );
		$hook_completed  = (int) has_action( 'woocommerce_order_status_completed', array( 'BizCity_Membership_Woo_Projector', 'on_order_status_completed' ) );
		$hook_refunded   = (int) has_action( 'woocommerce_order_refunded', array( 'BizCity_Membership_Woo_Projector', 'on_order_refunded' ) );
		$hook_cancelled  = (int) has_action( 'woocommerce_order_status_cancelled', array( 'BizCity_Membership_Woo_Projector', 'on_order_status_cancelled' ) );
		$hooks_ok = $hook_payment > 0 && $hook_processing > 0 && $hook_completed > 0 && $hook_refunded > 0 && $hook_cancelled > 0;

		$step = array(
			'label'  => 'Loader · projector class + Woo hooks registered',
			'status' => ( $loader_ok && ( ! $woo_active || $hooks_ok ) ) ? 'pass' : 'fail',
			'detail' => sprintf(
				'class=%s woo_active=%s hooks(payment=%d processing=%d completed=%d refunded=%d cancelled=%d)',
				$loader_ok ? 'ok' : 'MISSING',
				$woo_active ? '1' : '0',
				$hook_payment,
				$hook_processing,
				$hook_completed,
				$hook_refunded,
				$hook_cancelled
			),
		);
		$steps[] = $step;
		$ctx->emit_step( $step );

		if ( ! $loader_ok ) {
			return array(
				'status'   => 'fail',
				'summary'  => 'Membership Woo projector loader FAIL — class missing.',
				'fix_hint' => 'Check projector require/init in core/membership/bootstrap.php.',
				'steps'    => $steps,
			);
		}

		if ( ! $woo_active ) {
			$step = array(
				'label'  => 'Runtime · synthetic Woo projection checks',
				'status' => 'skip',
				'detail' => 'WooCommerce inactive — runtime skipped.',
			);
			$steps[] = $step;
			$ctx->emit_step( $step );

			return array(
				'status'  => ( $disk_readable && $disk_ok && $loader_ok ) ? 'skip' : 'fail',
				'summary' => 'Woo projection disk/loader OK; runtime skipped because WooCommerce is inactive.',
				'steps'   => $steps,
			);
		}

		$runtime_pass = true;
		$old_map      = get_option( BizCity_Membership_Woo_Mapper::OPT_MAP, null );
		$created_users    = array();
		$created_products = array();
		$created_orders   = array();
		$capacity_filter  = null;
		$hooks_muted      = false;

		try {
			$this->mute_projector_hooks();
			$hooks_muted = true;

			$plan_slug = $this->pick_paid_seat_plan_slug();
			if ( $plan_slug === '' ) {
				$runtime_pass = false;
				$step = array(
					'label'  => 'Runtime · prerequisite paid seat plan',
					'status' => 'fail',
					'detail' => 'No paid seat-consuming plan available in registry.',
				);
				$steps[] = $step;
				$ctx->emit_step( $step );
			} else {
				$step = array(
					'label'  => 'Runtime · prerequisite paid seat plan',
					'status' => 'pass',
					'detail' => 'Using plan_slug=' . $plan_slug,
				);
				$steps[] = $step;
				$ctx->emit_step( $step );
			}

			if ( $runtime_pass ) {
				// Scenario A: eligible paid order applies once, second call idempotent.
				$user_a = $this->create_synthetic_user( 'apply' );
				$created_users[] = $user_a;
				$offer_a = self::SYNTH_PREFIX . 'apply_' . wp_rand( 1000, 9999 );
				$product_a = $this->create_synthetic_product( $plan_slug, $offer_a, 1, 'month' );
				$created_products[] = $product_a;
				BizCity_Membership_Woo_Mapper::instance()->rebuild_index();

				$order_a = $this->create_synthetic_order( $user_a, $product_a, true );
				$created_orders[] = $order_a;
				$result_a1 = BizCity_Membership_Woo_Projector::project_order( $order_a, 'probe_apply_first' );
				$result_a2 = BizCity_Membership_Woo_Projector::project_order( $order_a, 'probe_apply_second' );
				$order_a_obj = wc_get_order( $order_a );
				$meta_a_status = $order_a_obj ? sanitize_key( (string) $order_a_obj->get_meta( BizCity_Membership_Woo_Projector::META_STATUS, true ) ) : '';
				$rows_a = $this->count_projected_rows_for_user( $user_a );

				$applied_once_ok = is_array( $result_a1 )
					&& is_array( $result_a2 )
					&& in_array( (string) ( $result_a1['status'] ?? '' ), array( 'applied', 'already_applied' ), true )
					&& (string) ( $result_a2['status'] ?? '' ) === 'already_applied'
					&& $meta_a_status === 'applied'
					&& $rows_a === 1;

				$step = array(
					'label'  => 'Runtime · paid order applies once; second call idempotent',
					'status' => $applied_once_ok ? 'pass' : 'fail',
					'detail' => sprintf(
						'r1=%s r2=%s order_status=%s source_rows=%d',
						(string) ( $result_a1['status'] ?? 'n/a' ),
						(string) ( $result_a2['status'] ?? 'n/a' ),
						$meta_a_status,
						(int) $rows_a
					),
				);
				$steps[] = $step;
				$ctx->emit_step( $step );
				if ( ! $applied_once_ok ) {
					$runtime_pass = false;
				}

				// Scenario A2: refunded order reverses only the matching current Woo grant.
				$result_a3 = BizCity_Membership_Woo_Projector::reverse_projection( $order_a, 'refunded', 'probe_refunded', 0 );
				$order_a_refunded = wc_get_order( $order_a );
				$meta_a_refund_status = $order_a_refunded ? sanitize_key( (string) $order_a_refunded->get_meta( BizCity_Membership_Woo_Projector::META_STATUS, true ) ) : '';
				$rows_a_after_refund = $this->count_projected_rows_for_user( $user_a );
				$current_offer_order = (int) get_user_meta( $user_a, BizCity_Membership_Woo_Projector::USER_META_OFFER_ORDER_ID, true );

				$refund_reverse_ok = is_array( $result_a3 )
					&& (string) ( $result_a3['status'] ?? '' ) === 'refunded'
					&& ! empty( $result_a3['cleared'] )
					&& $meta_a_refund_status === 'refunded'
					&& (int) $rows_a_after_refund === 0
					&& $current_offer_order === 0;

				$step = array(
					'label'  => 'Runtime · refunded order reverses matching Woo grant',
					'status' => $refund_reverse_ok ? 'pass' : 'fail',
					'detail' => sprintf(
						'r=%s cleared=%s order_status=%s active_rows=%d current_offer_order=%d',
						(string) ( $result_a3['status'] ?? 'n/a' ),
						! empty( $result_a3['cleared'] ) ? '1' : '0',
						$meta_a_refund_status,
						(int) $rows_a_after_refund,
						$current_offer_order
					),
				);
				$steps[] = $step;
				$ctx->emit_step( $step );
				if ( ! $refund_reverse_ok ) {
					$runtime_pass = false;
				}

				// Scenario B: unpaid order must not activate.
				$user_b = $this->create_synthetic_user( 'unpaid' );
				$created_users[] = $user_b;
				$offer_b = self::SYNTH_PREFIX . 'unpaid_' . wp_rand( 1000, 9999 );
				$product_b = $this->create_synthetic_product( $plan_slug, $offer_b, 1, 'month' );
				$created_products[] = $product_b;
				BizCity_Membership_Woo_Mapper::instance()->rebuild_index();

				$order_b = $this->create_synthetic_order( $user_b, $product_b, false );
				$created_orders[] = $order_b;
				$result_b = BizCity_Membership_Woo_Projector::project_order( $order_b, 'probe_unpaid' );
				$rows_b = $this->count_projected_rows_for_user( $user_b );

				$unpaid_ok = is_array( $result_b )
					&& in_array( (string) ( $result_b['status'] ?? '' ), array( 'pending', 'failed' ), true )
					&& (int) $rows_b === 0;

				$step = array(
					'label'  => 'Runtime · unpaid order is rejected and does not activate plan',
					'status' => $unpaid_ok ? 'pass' : 'fail',
					'detail' => sprintf(
						'r=%s reason=%s source_rows=%d',
						(string) ( $result_b['status'] ?? 'n/a' ),
						(string) ( $result_b['reason'] ?? 'n/a' ),
						(int) $rows_b
					),
				);
				$steps[] = $step;
				$ctx->emit_step( $step );
				if ( ! $unpaid_ok ) {
					$runtime_pass = false;
				}

				// Scenario C: capacity blocked should fail closed (no activation).
				$user_c = $this->create_synthetic_user( 'capacity' );
				$created_users[] = $user_c;
				$offer_c = self::SYNTH_PREFIX . 'cap_' . wp_rand( 1000, 9999 );
				$product_c = $this->create_synthetic_product( $plan_slug, $offer_c, 1, 'month' );
				$created_products[] = $product_c;
				BizCity_Membership_Woo_Mapper::instance()->rebuild_index();

				$order_c = $this->create_synthetic_order( $user_c, $product_c, true );
				$created_orders[] = $order_c;

				$capacity_filter = function ( $snapshot, $order_id, $uid ) use ( $order_c, $user_c ) {
					if ( (int) $order_id !== (int) $order_c || (int) $uid !== (int) $user_c ) {
						return $snapshot;
					}
					return array(
						'available' => false,
						'bucket'    => 'capacity_blocked',
						'used'      => 1,
						'limit'     => 1,
						'remaining' => 0,
					);
				};
				add_filter( 'bizcity_membership_woo_capacity_snapshot', $capacity_filter, 10, 3 );

				$result_c = BizCity_Membership_Woo_Projector::project_order( $order_c, 'probe_capacity' );
				remove_filter( 'bizcity_membership_woo_capacity_snapshot', $capacity_filter, 10 );
				$capacity_filter = null;

				$order_c_obj = wc_get_order( $order_c );
				$meta_c_status = $order_c_obj ? sanitize_key( (string) $order_c_obj->get_meta( BizCity_Membership_Woo_Projector::META_STATUS, true ) ) : '';
				$rows_c = $this->count_projected_rows_for_user( $user_c );

				$capacity_ok = is_array( $result_c )
					&& in_array( (string) ( $result_c['status'] ?? '' ), array( 'capacity_blocked', 'failed' ), true )
					&& in_array( (string) ( $result_c['reason'] ?? '' ), array( 'capacity_blocked', 'capacity_unavailable' ), true )
					&& $meta_c_status === 'capacity_blocked'
					&& (int) $rows_c === 0;

				$step = array(
					'label'  => 'Runtime · capacity_blocked path does not activate plan',
					'status' => $capacity_ok ? 'pass' : 'fail',
					'detail' => sprintf(
						'r=%s reason=%s order_status=%s source_rows=%d',
						(string) ( $result_c['status'] ?? 'n/a' ),
						(string) ( $result_c['reason'] ?? 'n/a' ),
						$meta_c_status,
						(int) $rows_c
					),
				);
				$steps[] = $step;
				$ctx->emit_step( $step );
				if ( ! $capacity_ok ) {
					$runtime_pass = false;
				}
			}
		} catch ( Exception $e ) {
			$runtime_pass = false;
			$step = array(
				'label'  => 'Runtime · synthetic Woo projection checks',
				'status' => 'fail',
				'detail' => 'Exception: ' . $e->getMessage(),
			);
			$steps[] = $step;
			$ctx->emit_step( $step );
		} finally {
			if ( is_callable( $capacity_filter ) ) {
				remove_filter( 'bizcity_membership_woo_capacity_snapshot', $capacity_filter, 10 );
			}
			if ( $hooks_muted ) {
				$this->restore_projector_hooks();
			}

			foreach ( $created_orders as $order_id ) {
				$this->cleanup_order( (int) $order_id );
			}
			foreach ( $created_products as $product_id ) {
				$this->cleanup_product( (int) $product_id );
			}
			foreach ( $created_users as $user_id ) {
				$this->cleanup_user_membership_rows( (int) $user_id );
				$this->cleanup_user_offer_meta( (int) $user_id );
				$this->cleanup_user( (int) $user_id );
			}

			if ( null === $old_map ) {
				delete_option( BizCity_Membership_Woo_Mapper::OPT_MAP );
			} else {
				update_option( BizCity_Membership_Woo_Mapper::OPT_MAP, $old_map, false );
			}
		}

		$pass = $disk_readable && $disk_ok && $loader_ok && ( ! $woo_active || $hooks_ok ) && $runtime_pass;

		return array(
			'status'  => $pass ? 'pass' : 'fail',
			'summary' => $pass
				? 'Woo projector contract OK (idempotent apply + reject unpaid + capacity blocked fail-closed).'
				: 'Woo projector contract failed in one or more Disk/Loader/Runtime checks.',
			'steps'   => $steps,
		);
	}

	public function cleanup(): void {
		$this->cleanup_synthetic_orders();
		$this->cleanup_synthetic_products();
		$this->cleanup_synthetic_users();
	}

	/**
	 * @return string
	 */
	private function pick_paid_seat_plan_slug() {
		$plans = class_exists( 'BizCity_Membership_Plan_Registry' )
			? BizCity_Membership_Plan_Registry::instance()->all()
			: array();
		if ( ! is_array( $plans ) ) {
			return '';
		}

		foreach ( $plans as $slug => $plan ) {
			$slug = sanitize_key( (string) $slug );
			if ( $slug === '' || $slug === 'free' ) {
				continue;
			}
			if ( is_array( $plan ) && ! empty( $plan['consumes_seat'] ) ) {
				return $slug;
			}
		}

		foreach ( $plans as $slug => $plan ) {
			$slug = sanitize_key( (string) $slug );
			if ( $slug !== '' && $slug !== 'free' ) {
				return $slug;
			}
		}

		return '';
	}

	/**
	 * @param string $suffix
	 * @return int
	 */
	private function create_synthetic_user( $suffix ) {
		$suffix = sanitize_key( (string) $suffix );
		$seed   = strtolower( wp_generate_password( 8, false, false ) );
		$login  = self::SYNTH_PREFIX . $suffix . '_' . $seed;
		$email  = $login . '@example.com';

		$user_id = wp_insert_user( array(
			'user_login'   => $login,
			'user_pass'    => wp_generate_password( 20, true, true ),
			'user_email'   => $email,
			'display_name' => 'Diag Woo Projection ' . strtoupper( $suffix ),
			'role'         => 'subscriber',
		) );
		if ( is_wp_error( $user_id ) || (int) $user_id <= 0 ) {
			throw new Exception( 'Cannot create synthetic user for probe.' );
		}
		return (int) $user_id;
	}

	/**
	 * @param string $plan_slug
	 * @param string $offer_code
	 * @param int    $duration_count
	 * @param string $duration_unit
	 * @return int
	 */
	private function create_synthetic_product( $plan_slug, $offer_code, $duration_count, $duration_unit ) {
		if ( ! class_exists( 'WC_Product_Simple' ) ) {
			throw new Exception( 'WC_Product_Simple class missing.' );
		}

		$product = new WC_Product_Simple();
		$product->set_name( '[diag] Woo projection ' . $offer_code );
		$product->set_status( 'publish' );
		$product->set_regular_price( '9' );
		$product->set_catalog_visibility( 'hidden' );
		$product_id = (int) $product->save();
		if ( $product_id <= 0 ) {
			throw new Exception( 'Cannot create synthetic Woo product.' );
		}

		update_post_meta( $product_id, BizCity_Membership_Woo_Mapper::META_OFFER_CODE, sanitize_key( (string) $offer_code ) );
		update_post_meta( $product_id, BizCity_Membership_Woo_Mapper::META_PLAN_SLUG, sanitize_key( (string) $plan_slug ) );
		update_post_meta( $product_id, BizCity_Membership_Woo_Mapper::META_DURATION_COUNT, max( 1, (int) $duration_count ) );
		update_post_meta( $product_id, BizCity_Membership_Woo_Mapper::META_DURATION_UNIT, sanitize_key( (string) $duration_unit ) );
		update_post_meta( $product_id, BizCity_Membership_Woo_Mapper::META_GRANT_MODE, 'replace' );

		return $product_id;
	}

	/**
	 * @param int  $user_id
	 * @param int  $product_id
	 * @param bool $paid
	 * @return int
	 */
	private function create_synthetic_order( $user_id, $product_id, $paid ) {
		$order = wc_create_order( array( 'customer_id' => (int) $user_id ) );
		if ( ! $order || ! is_object( $order ) ) {
			throw new Exception( 'Cannot create synthetic order.' );
		}

		$product = wc_get_product( (int) $product_id );
		if ( ! $product ) {
			throw new Exception( 'Cannot load synthetic product object.' );
		}

		$order->add_product( $product, 1 );
		$order->calculate_totals();

		if ( $paid ) {
			$order->set_transaction_id( self::SYNTH_PREFIX . 'txn_' . wp_rand( 10000, 99999 ) );
			$order->set_date_paid( time() );
			$order->set_status( 'processing' );
		} else {
			$order->set_status( 'pending' );
		}
		$order->save();

		return (int) $order->get_id();
	}

	/**
	 * @param int $user_id
	 * @return int
	 */
	private function count_projected_rows_for_user( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 || ! class_exists( 'BizCity_Membership_Manager' ) ) {
			return 0;
		}

		global $wpdb;
		$table = BizCity_Membership_Manager::instance()->table();
		if ( ! $this->table_exists( $table ) ) {
			return 0;
		}

		$sql = $wpdb->prepare(
			// [2026-07-19 Johnny Chu] SPRINT-9 WC-2 — clear_plan() preserves cancelled history rows; probe must count only active Woo grants.
			"SELECT COUNT(1) FROM {$table} WHERE user_id = %d AND source = %s AND status = %s",
			$user_id,
			BizCity_Membership_Woo_Projector::SOURCE,
			BizCity_Membership_Manager::STATUS_ACTIVE
		);
		return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * @param int $user_id
	 * @return void
	 */
	private function cleanup_user_membership_rows( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 || ! class_exists( 'BizCity_Membership_Manager' ) ) {
			return;
		}
		global $wpdb;
		$table = BizCity_Membership_Manager::instance()->table();
		if ( ! $this->table_exists( $table ) ) {
			return;
		}
		$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			"DELETE FROM {$table} WHERE user_id = %d AND source = %s",
			$user_id,
			BizCity_Membership_Woo_Projector::SOURCE
		) );
	}

	/**
	 * @param int $user_id
	 * @return void
	 */
	private function cleanup_user_offer_meta( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return;
		}
		delete_user_meta( $user_id, BizCity_Membership_Woo_Projector::USER_META_OFFER_CODE );
		delete_user_meta( $user_id, BizCity_Membership_Woo_Projector::USER_META_OFFER_PLAN );
		delete_user_meta( $user_id, BizCity_Membership_Woo_Projector::USER_META_OFFER_ORDER_ID );
		delete_user_meta( $user_id, BizCity_Membership_Woo_Projector::USER_META_OFFER_APPLIED_AT );
	}

	/**
	 * @param int $order_id
	 * @return void
	 */
	private function cleanup_order( $order_id ) {
		$order_id = (int) $order_id;
		if ( $order_id <= 0 ) {
			return;
		}
		wp_delete_post( $order_id, true );
	}

	/**
	 * @param int $product_id
	 * @return void
	 */
	private function cleanup_product( $product_id ) {
		$product_id = (int) $product_id;
		if ( $product_id <= 0 ) {
			return;
		}
		wp_delete_post( $product_id, true );
	}

	/**
	 * @param int $user_id
	 * @return void
	 */
	private function cleanup_user( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return;
		}
		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		if ( function_exists( 'wp_delete_user' ) ) {
			wp_delete_user( $user_id );
		}
	}

	private function cleanup_synthetic_orders() {
		if ( ! post_type_exists( 'shop_order' ) ) {
			return;
		}
		$ids = get_posts( array(
			'post_type'              => 'shop_order',
			'post_status'            => array_keys( wc_get_order_statuses() ),
			'posts_per_page'         => -1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'meta_query'             => array(
				array(
					'key'     => BizCity_Membership_Woo_Projector::META_OFFER_CODE,
					'value'   => self::SYNTH_PREFIX,
					'compare' => 'LIKE',
				),
			),
		) );
		foreach ( (array) $ids as $id ) {
			wp_delete_post( (int) $id, true );
		}
	}

	private function cleanup_synthetic_products() {
		if ( ! post_type_exists( 'product' ) ) {
			return;
		}
		$ids = get_posts( array(
			'post_type'              => 'product',
			'post_status'            => array( 'publish', 'private', 'draft', 'pending', 'future', 'trash' ),
			'posts_per_page'         => -1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'meta_query'             => array(
				array(
					'key'     => BizCity_Membership_Woo_Mapper::META_OFFER_CODE,
					'value'   => self::SYNTH_PREFIX,
					'compare' => 'LIKE',
				),
			),
		) );
		foreach ( (array) $ids as $id ) {
			wp_delete_post( (int) $id, true );
		}
	}

	private function cleanup_synthetic_users() {
		$users = get_users( array(
			'number'  => 200,
			'orderby' => 'ID',
			'order'   => 'DESC',
			'search'  => self::SYNTH_PREFIX . '*',
			'fields'  => array( 'ID', 'user_login' ),
		) );
		foreach ( (array) $users as $user ) {
			if ( ! is_object( $user ) || empty( $user->ID ) ) {
				continue;
			}
			$this->cleanup_user_membership_rows( (int) $user->ID );
			$this->cleanup_user_offer_meta( (int) $user->ID );
			$this->cleanup_user( (int) $user->ID );
		}
	}

	/**
	 * Temporarily remove projector hooks so synthetic order mutations do not auto-project.
	 *
	 * @return void
	 */
	private function mute_projector_hooks() {
		remove_action( 'woocommerce_payment_complete', array( 'BizCity_Membership_Woo_Projector', 'on_payment_complete' ), 30 );
		remove_action( 'woocommerce_order_status_processing', array( 'BizCity_Membership_Woo_Projector', 'on_order_status_processing' ), 30 );
		remove_action( 'woocommerce_order_status_completed', array( 'BizCity_Membership_Woo_Projector', 'on_order_status_completed' ), 30 );
	}

	/**
	 * Re-register projector hooks after runtime synthetic checks.
	 *
	 * @return void
	 */
	private function restore_projector_hooks() {
		add_action( 'woocommerce_payment_complete', array( 'BizCity_Membership_Woo_Projector', 'on_payment_complete' ), 30, 1 );
		add_action( 'woocommerce_order_status_processing', array( 'BizCity_Membership_Woo_Projector', 'on_order_status_processing' ), 30, 2 );
		add_action( 'woocommerce_order_status_completed', array( 'BizCity_Membership_Woo_Projector', 'on_order_status_completed' ), 30, 2 );
	}

	/**
	 * Resolve plugin-relative file with safe fallbacks.
	 *
	 * @param string $relative_path
	 * @return string
	 */
	private function resolve_plugin_file( $relative_path ) {
		$relative_path = ltrim( (string) $relative_path, '/\\' );
		$candidates = array();
		if ( defined( 'BIZCITY_TWIN_AI_DIR' ) ) {
			$candidates[] = BIZCITY_TWIN_AI_DIR . $relative_path;
		}
		if ( defined( 'WP_PLUGIN_DIR' ) ) {
			$candidates[] = WP_PLUGIN_DIR . '/bizcity-twin-ai/' . $relative_path;
		}
		$candidates[] = dirname( __DIR__, 4 ) . '/' . $relative_path;
		$candidates[] = dirname( __DIR__, 5 ) . '/bizcity-twin-ai/' . $relative_path;

		foreach ( $candidates as $candidate ) {
			if ( is_string( $candidate ) && $candidate !== '' && is_readable( $candidate ) ) {
				return $candidate;
			}
		}
		return '';
	}

	/**
	 * @param string $table_name
	 * @return bool
	 */
	private function table_exists( $table_name ) {
		$table_name = (string) $table_name;
		if ( $table_name === '' ) {
			return false;
		}
		if ( function_exists( 'bizcity_tbl_exists' ) ) {
			return (bool) bizcity_tbl_exists( $table_name );
		}
		global $wpdb;
		$present = (int) (bool) $wpdb->get_var( $wpdb->prepare(
			'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
			$table_name
		) );
		return $present === 1;
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = new BizCity_Probe_Membership_Woo_Projection();
	return $list;
} );
