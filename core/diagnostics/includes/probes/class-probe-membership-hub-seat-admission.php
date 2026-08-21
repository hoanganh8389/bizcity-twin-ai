<?php
/**
 * BizCity Diagnostics - core.membership.hub_seat_admission probe (SPRINT-11 PGM-3).
 *
 * R-DDV 3 layers:
 * - Disk: manager counter + projector capacity gate + hub seat-limit option markers.
 * - Loader: manager/projector methods loaded.
 * - Runtime: seat counter excludes admin role; capacity gate blocks net-new seat when used=1, limit=1.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-07-17
 */

// [2026-07-17 Johnny Chu] SPRINT-11 PGM-3 - DDV probe for hub-seat admission contract.
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

if ( class_exists( 'BizCity_Probe_Membership_Hub_Seat_Admission', false ) ) {
	return;
}

final class BizCity_Probe_Membership_Hub_Seat_Admission implements BizCity_Diagnostics_Probe {

	const SYNTH_PREFIX = 'diag_hub_seat_';

	public function id(): string          { return 'core.membership.hub_seat_admission'; }
	public function label(): string       { return 'Membership - Hub Seat Admission (SPRINT-11)'; }
	public function description(): string {
		return 'Validates canonical seat counter + hub seat-limit sync + fail-closed capacity gate.';
	}
	public function severity(): string    { return 'warning'; }
	public function order(): int          { return 69; }
	public function icon(): string        { return 'ShieldCheck'; }
	public function estimate_ms(): int    { return 140; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_Membership_Manager' ) ) {
			return new WP_Error( 'no_manager', 'BizCity_Membership_Manager is not loaded.' );
		}
		if ( ! class_exists( 'BizCity_Membership_Woo_Projector' ) ) {
			return new WP_Error( 'no_projector', 'BizCity_Membership_Woo_Projector is not loaded.' );
		}
		if ( ! class_exists( 'BizCity_Membership_Plan_Registry' ) ) {
			return new WP_Error( 'no_registry', 'BizCity_Membership_Plan_Registry is not loaded.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		$steps = array();

		$manager_file   = $this->resolve_plugin_file( 'core/membership/includes/class-membership-manager.php' );
		$projector_file = $this->resolve_plugin_file( 'core/membership/includes/class-membership-woo-projector.php' );

		$manager_src   = ( $manager_file !== '' && is_readable( $manager_file ) ) ? (string) file_get_contents( $manager_file ) : '';
		$projector_src = ( $projector_file !== '' && is_readable( $projector_file ) ) ? (string) file_get_contents( $projector_file ) : '';

		$disk_ok = ( $manager_src !== '' )
			&& strpos( $manager_src, 'count_seat_used' ) !== false
			&& ( $projector_src !== '' )
			&& strpos( $projector_src, 'resolve_seat_limit' ) !== false
			&& strpos( $projector_src, 'count_seat_used' ) !== false
			// [2026-08-21 Johnny Chu] SPRINT-11 DDV-FIX — seat-limit option belongs to the Woo projector, not the LLM client.
			&& strpos( $projector_src, 'bizcity_hub_member_seat_limit' ) !== false;

		$step = array(
			'label'  => 'Disk - seat counter + seat-limit marker contracts',
			'status' => $disk_ok ? 'pass' : 'fail',
			'detail' => sprintf(
				'manager=%s projector=%s seat_limit_owner=projector',
				$manager_src !== '' ? 'ok' : 'missing',
				$projector_src !== '' ? 'ok' : 'missing'
			),
		);
		$steps[] = $step;
		$ctx->emit_step( $step );

		$loader_ok = method_exists( 'BizCity_Membership_Manager', 'count_seat_used' )
			&& method_exists( 'BizCity_Membership_Woo_Projector', 'get_capacity_snapshot' );
		$evaluate_exists = method_exists( 'BizCity_Membership_Woo_Projector', 'evaluate_capacity' );

		$step = array(
			'label'  => 'Loader - manager/projector methods loaded',
			'status' => ( $loader_ok && $evaluate_exists ) ? 'pass' : 'fail',
			'detail' => sprintf(
				'manager.count_seat_used=%s projector.get_capacity_snapshot=%s projector.evaluate_capacity=%s',
				method_exists( 'BizCity_Membership_Manager', 'count_seat_used' ) ? 'ok' : 'missing',
				method_exists( 'BizCity_Membership_Woo_Projector', 'get_capacity_snapshot' ) ? 'ok' : 'missing',
				$evaluate_exists ? 'ok' : 'missing'
			),
		);
		$steps[] = $step;
		$ctx->emit_step( $step );

		$runtime_ok = true;

		$manager = BizCity_Membership_Manager::instance();
		$baseline = (int) $manager->count_seat_used( true );
		$step = array(
			'label'  => 'Runtime - count_seat_used returns non-negative int',
			'status' => $baseline >= 0 ? 'pass' : 'fail',
			'detail' => 'baseline=' . $baseline,
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( $baseline < 0 ) {
			$runtime_ok = false;
		}

		$plan_slug = $this->pick_paid_seat_plan_slug();
		if ( $plan_slug === '' ) {
			$step = array(
				'label'  => 'Runtime - paid seat-consuming plan available',
				'status' => 'skip',
				'detail' => 'No paid plan with consumes_seat=true found in local registry.',
			);
			$steps[] = $step;
			$ctx->emit_step( $step );
		} else {
			$uid = 0;
			try {
				$uid = $this->create_temp_user( 'subscriber' );
				if ( $uid <= 0 ) {
					throw new Exception( 'Cannot create synthetic subscriber user.' );
				}
				if ( ! $this->insert_active_subscription_row( $uid, $plan_slug ) ) {
					throw new Exception( 'Cannot insert synthetic active subscription row.' );
				}

				$after_member = (int) $manager->count_seat_used( true );
				$member_delta_ok = $after_member >= ( $baseline + 1 );

				wp_update_user( array( 'ID' => $uid, 'role' => 'administrator' ) );
				$after_promote = (int) $manager->count_seat_used( true );
				$admin_excluded_ok = $after_promote <= ( $after_member - 1 );

				$step = array(
					'label'  => 'Runtime - seat counter excludes admin role',
					'status' => ( $member_delta_ok && $admin_excluded_ok ) ? 'pass' : 'fail',
					'detail' => sprintf(
						'baseline=%d after_member=%d after_promote_admin=%d',
						$baseline,
						$after_member,
						$after_promote
					),
				);
				$steps[] = $step;
				$ctx->emit_step( $step );
				if ( ! $member_delta_ok || ! $admin_excluded_ok ) {
					$runtime_ok = false;
				}
			} catch ( Exception $e ) {
				$runtime_ok = false;
				$step = array(
					'label'  => 'Runtime - seat counter excludes admin role',
					'status' => 'fail',
					'detail' => 'Exception: ' . $e->getMessage(),
				);
				$steps[] = $step;
				$ctx->emit_step( $step );
			} finally {
				if ( $uid > 0 ) {
					$this->cleanup_temp_user_data( $uid );
				}
				$manager->count_seat_used( true );
			}
		}

		$capacity_step_ok = false;
		$forced = null;
		try {
			$order_id = 999991;
			$user_id  = 999992;
			$forced = function ( $snapshot, $oid, $uid ) use ( $order_id, $user_id ) {
				if ( (int) $oid !== (int) $order_id || (int) $uid !== (int) $user_id ) {
					return $snapshot;
				}
				return array(
					'available' => true,
					'bucket'    => 'capacity_available',
					'used'      => 1,
					'limit'     => 1,
					'remaining' => 0,
				);
			};
			add_filter( 'bizcity_membership_woo_capacity_snapshot', $forced, 10, 3 );

			$method = new ReflectionMethod( 'BizCity_Membership_Woo_Projector', 'evaluate_capacity' );
			if ( method_exists( $method, 'setAccessible' ) ) {
				$method->setAccessible( true );
			}
			$result = $method->invoke( null, $order_id, $user_id, array( 'offer_code' => self::SYNTH_PREFIX . 'x', 'plan_slug' => $plan_slug ), 1 );

			$capacity_step_ok = is_array( $result )
				&& isset( $result['available'] )
				&& ( $result['available'] === false )
				&& in_array( (string) ( $result['bucket'] ?? '' ), array( 'capacity_blocked', 'capacity_unavailable' ), true );

			$step = array(
				'label'  => 'Runtime - net-new seat blocked when used=1 and limit=1',
				'status' => $capacity_step_ok ? 'pass' : 'fail',
				'detail' => is_array( $result )
					? sprintf( 'available=%s bucket=%s limit=%s used=%s',
						! empty( $result['available'] ) ? '1' : '0',
						(string) ( $result['bucket'] ?? '' ),
						isset( $result['limit'] ) ? (string) $result['limit'] : 'null',
						isset( $result['used'] ) ? (string) $result['used'] : 'null'
					)
					: 'evaluate_capacity did not return array',
			);
			$steps[] = $step;
			$ctx->emit_step( $step );
		} catch ( Exception $e ) {
			$runtime_ok = false;
			$step = array(
				'label'  => 'Runtime - net-new seat blocked when used=1 and limit=1',
				'status' => 'fail',
				'detail' => 'Exception: ' . $e->getMessage(),
			);
			$steps[] = $step;
			$ctx->emit_step( $step );
		} finally {
			if ( is_callable( $forced ) ) {
				remove_filter( 'bizcity_membership_woo_capacity_snapshot', $forced, 10 );
			}
		}

		if ( ! $capacity_step_ok ) {
			$runtime_ok = false;
		}

		$pass = $disk_ok && $loader_ok && $evaluate_exists && $runtime_ok;

		return array(
			'status'  => $pass ? 'pass' : 'fail',
			'summary' => $pass
				? 'Hub seat admission contract OK (counter + seat-limit sync + fail-closed gate).'
				: 'Hub seat admission contract failed in one or more Disk/Loader/Runtime checks.',
			'steps'   => $steps,
		);
	}

	public function cleanup(): void {
		$this->cleanup_synthetic_users();
	}

	/**
	 * @return string
	 */
	private function pick_paid_seat_plan_slug() {
		$plans = BizCity_Membership_Plan_Registry::instance()->all();
		if ( ! is_array( $plans ) ) {
			return '';
		}
		foreach ( $plans as $slug => $plan ) {
			$slug = sanitize_key( (string) $slug );
			if ( $slug === '' || $slug === 'free' ) {
				continue;
			}
			if ( ! empty( $plan['consumes_seat'] ) ) {
				return $slug;
			}
		}
		return '';
	}

	/**
	 * @param string $role
	 * @return int
	 */
	private function create_temp_user( $role ) {
		$role = sanitize_key( (string) $role );
		$rand = wp_rand( 10000, 99999 );
		$login = self::SYNTH_PREFIX . $rand;
		$email = $login . '@example.invalid';

		$user_id = wp_insert_user( array(
			'user_login' => $login,
			'user_pass'  => wp_generate_password( 24, true, true ),
			'user_email' => $email,
			'role'       => $role !== '' ? $role : 'subscriber',
		) );
		if ( is_wp_error( $user_id ) ) {
			return 0;
		}
		return (int) $user_id;
	}

	/**
	 * @param int    $user_id
	 * @param string $plan_slug
	 * @return bool
	 */
	private function insert_active_subscription_row( $user_id, $plan_slug ) {
		$user_id = (int) $user_id;
		$plan_slug = sanitize_key( (string) $plan_slug );
		if ( $user_id <= 0 || $plan_slug === '' ) {
			return false;
		}
		$manager = BizCity_Membership_Manager::instance();
		$table = $manager->table();
		if ( ! $this->table_exists( $table ) ) {
			return false;
		}

		global $wpdb;
		$now = current_time( 'mysql' );
		$exp = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS * 30 );
		$ok = $wpdb->insert(
			$table,
			array(
				'user_id'                => $user_id,
				'plan_slug'              => $plan_slug,
				'status'                 => BizCity_Membership_Manager::STATUS_ACTIVE,
				'start_date'             => $now,
				'expiration_date'        => $exp,
				'paypal_subscription_id' => '',
				'source'                 => 'diag',
				'created_at'             => $now,
				'updated_at'             => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( ! $ok ) {
			return false;
		}

		update_user_meta( $user_id, BizCity_Membership_Manager::META_PLAN, $plan_slug );
		update_user_meta( $user_id, BizCity_Membership_Manager::META_VALID_UNTIL, $exp );
		update_user_meta( $user_id, BizCity_Membership_Manager::META_SOURCE, 'diag' );
		return true;
	}

	/**
	 * @param int $user_id
	 * @return void
	 */
	private function cleanup_temp_user_data( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return;
		}
		$manager = BizCity_Membership_Manager::instance();
		$table = $manager->table();
		global $wpdb;
		if ( $this->table_exists( $table ) ) {
			$wpdb->delete( $table, array( 'user_id' => $user_id ), array( '%d' ) );
		}
		delete_user_meta( $user_id, BizCity_Membership_Manager::META_PLAN );
		delete_user_meta( $user_id, BizCity_Membership_Manager::META_VALID_UNTIL );
		delete_user_meta( $user_id, BizCity_Membership_Manager::META_SOURCE );

		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		if ( function_exists( 'wp_delete_user' ) ) {
			wp_delete_user( $user_id );
		}
	}

	/**
	 * @return void
	 */
	private function cleanup_synthetic_users() {
		$users = get_users( array(
			'fields'  => array( 'ID', 'user_login' ),
			'orderby' => 'ID',
			'order'   => 'DESC',
			'number'  => 50,
		) );
		if ( ! is_array( $users ) ) {
			return;
		}
		foreach ( $users as $user ) {
			$uid = isset( $user->ID ) ? (int) $user->ID : 0;
			$login = isset( $user->user_login ) ? (string) $user->user_login : '';
			if ( $uid <= 0 || strpos( $login, self::SYNTH_PREFIX ) !== 0 ) {
				continue;
			}
			$this->cleanup_temp_user_data( $uid );
		}
	}

	/**
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
	$list[] = new BizCity_Probe_Membership_Hub_Seat_Admission();
	return $list;
} );
