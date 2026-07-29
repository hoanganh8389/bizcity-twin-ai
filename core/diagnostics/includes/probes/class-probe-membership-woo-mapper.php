<?php
/**
 * BizCity Diagnostics — core.membership.woo_mapper probe (SPRINT-8 WC-0/WC-0A).
 *
 * R-DDV 3 layers:
 * - Disk: mapper file readable + canonical offer meta key + map option markers.
 * - Loader: mapper class loaded + map option accessible.
 * - Runtime: synthetic product + variation meta -> derived map; variation overrides parent.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-07-17
 */

// [2026-07-17 Johnny Chu] SPRINT-8 WC-0A — Woo mapper DDV probe.
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

if ( class_exists( 'BizCity_Probe_Membership_Woo_Mapper', false ) ) {
	return;
}

final class BizCity_Probe_Membership_Woo_Mapper implements BizCity_Diagnostics_Probe {

	const SYNTH_PREFIX = 'diag_woo_mapper_';

	public function id(): string          { return 'core.membership.woo_mapper'; }
	public function label(): string       { return 'Membership · Woo Mapper Foundation (SPRINT-8)'; }
	public function description(): string {
		return 'WC-0/WC-0A DDV: product offer meta + derived map option + variation override contract.';
	}
	public function severity(): string    { return 'warning'; }
	public function order(): int          { return 66; }
	public function icon(): string        { return 'Package'; }
	public function estimate_ms(): int    { return 120; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_Membership_Woo_Mapper' ) ) {
			return new WP_Error( 'no_mapper', 'BizCity_Membership_Woo_Mapper chưa load — kiểm tra core/membership/bootstrap.php.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		$steps = array();

		$mapper_file = $this->resolve_plugin_file( 'core/membership/includes/class-membership-woo-mapper.php' );
		$disk_readable = ( $mapper_file !== '' && is_readable( $mapper_file ) );
		$src = $disk_readable ? (string) file_get_contents( $mapper_file ) : '';
		$disk_meta_ok = $src !== ''
			&& strpos( $src, '_bizcity_membership_offer_code' ) !== false
			&& strpos( $src, 'bizcity_membership_woo_map' ) !== false
			&& strpos( $src, 'rebuild_index' ) !== false;

		$step = array(
			'label'  => 'Disk · Woo mapper file + canonical meta/map markers',
			'status' => ( $disk_readable && $disk_meta_ok ) ? 'pass' : 'fail',
			'detail' => $disk_readable
				? ( $disk_meta_ok ? 'markers found (_bizcity_membership_offer_code, bizcity_membership_woo_map, rebuild_index)' : 'marker missing in mapper source' )
				: 'mapper file not readable',
		);
		$steps[] = $step;
		$ctx->emit_step( $step );

		$woo_active = class_exists( 'WooCommerce' ) && post_type_exists( 'product' ) && post_type_exists( 'product_variation' );
		$loader_ok  = class_exists( 'BizCity_Membership_Woo_Mapper' );
		$map_opt    = get_option( BizCity_Membership_Woo_Mapper::OPT_MAP, array() );
		$map_shape  = is_array( $map_opt ) || $map_opt === null;

		$step = array(
			'label'  => 'Loader · mapper class + option access',
			'status' => $loader_ok ? 'pass' : 'fail',
			'detail' => sprintf(
				'class=%s · woo_active=%s · map_option_shape=%s',
				$loader_ok ? 'ok' : 'MISSING',
				$woo_active ? '1' : '0',
				$map_shape ? 'ok' : 'invalid'
			),
		);
		$steps[] = $step;
		$ctx->emit_step( $step );

		if ( ! $loader_ok ) {
			return array(
				'status'   => 'fail',
				'summary'  => 'Membership Woo mapper loader FAIL — class missing.',
				'fix_hint' => 'Check mapper require/init in core/membership/bootstrap.php.',
				'steps'    => $steps,
			);
		}

		if ( ! $woo_active ) {
			$step = array(
				'label'  => 'Runtime · synthetic product/variation mapping',
				'status' => 'skip',
				'detail' => 'WooCommerce chưa active hoặc product post types chưa available — runtime skipped.',
			);
			$steps[] = $step;
			$ctx->emit_step( $step );

			return array(
				'status'  => ( $disk_readable && $disk_meta_ok && $loader_ok ) ? 'skip' : 'fail',
				'summary' => 'Membership Woo mapper disk/loader OK; runtime skipped because WooCommerce is inactive.',
				'steps'   => $steps,
			);
		}

		$runtime_ok = false;
		$old_map = get_option( BizCity_Membership_Woo_Mapper::OPT_MAP, null );
		$parent_id = 0;
		$variation_id = 0;
		$offer_code = '';
		$variation_plan = '';

		try {
			$plans = class_exists( 'BizCity_Membership_Plan_Registry' )
				? BizCity_Membership_Plan_Registry::instance()->all()
				: array();
			$plan_slugs = is_array( $plans ) ? array_keys( $plans ) : array();
			$parent_plan = in_array( 'pro', $plan_slugs, true )
				? 'pro'
				: ( ! empty( $plan_slugs ) ? (string) $plan_slugs[0] : 'free' );
			$variation_plan = in_array( 'plus', $plan_slugs, true )
				? 'plus'
				: ( ! empty( $plan_slugs ) ? (string) end( $plan_slugs ) : $parent_plan );

			$offer_code = self::SYNTH_PREFIX . wp_rand( 10000, 99999 );

			$parent_id = (int) wp_insert_post( array(
				'post_type'   => 'product',
				'post_status' => 'publish',
				'post_title'  => '[diag] Membership Woo mapper parent ' . gmdate( 'YmdHis' ),
			), true );

			if ( $parent_id <= 0 ) {
				throw new Exception( 'Cannot create synthetic parent product.' );
			}

			$variation_id = (int) wp_insert_post( array(
				'post_type'   => 'product_variation',
				'post_status' => 'publish',
				'post_parent' => $parent_id,
				'post_title'  => '[diag] Membership Woo mapper variation ' . gmdate( 'YmdHis' ),
			), true );
			if ( $variation_id <= 0 ) {
				throw new Exception( 'Cannot create synthetic variation.' );
			}

			update_post_meta( $parent_id, BizCity_Membership_Woo_Mapper::META_OFFER_CODE, $offer_code );
			update_post_meta( $parent_id, BizCity_Membership_Woo_Mapper::META_PLAN_SLUG, $parent_plan );
			update_post_meta( $parent_id, BizCity_Membership_Woo_Mapper::META_DURATION_COUNT, 1 );
			update_post_meta( $parent_id, BizCity_Membership_Woo_Mapper::META_DURATION_UNIT, 'month' );
			update_post_meta( $parent_id, BizCity_Membership_Woo_Mapper::META_GRANT_MODE, 'replace' );

			// Variation uses the same offer_code and must override parent row in derived map.
			update_post_meta( $variation_id, BizCity_Membership_Woo_Mapper::META_OFFER_CODE, $offer_code );
			update_post_meta( $variation_id, BizCity_Membership_Woo_Mapper::META_PLAN_SLUG, $variation_plan );
			update_post_meta( $variation_id, BizCity_Membership_Woo_Mapper::META_DURATION_COUNT, 3 );
			update_post_meta( $variation_id, BizCity_Membership_Woo_Mapper::META_DURATION_UNIT, 'month' );
			update_post_meta( $variation_id, BizCity_Membership_Woo_Mapper::META_GRANT_MODE, 'extend' );

			$map = BizCity_Membership_Woo_Mapper::instance()->rebuild_index();
			$row = ( is_array( $map ) && ! empty( $map['items'][ $offer_code ] ) && is_array( $map['items'][ $offer_code ] ) )
				? $map['items'][ $offer_code ]
				: array();

			$runtime_ok = ! empty( $row )
				&& (int) ( $row['product_id'] ?? 0 ) === $parent_id
				&& (int) ( $row['variation_id'] ?? 0 ) === $variation_id
				&& (string) ( $row['plan_slug'] ?? '' ) === (string) $variation_plan;

			$step = array(
				'label'  => 'Runtime · synthetic product meta -> derived map + variation override',
				'status' => $runtime_ok ? 'pass' : 'fail',
				'detail' => $runtime_ok
					? sprintf( 'offer=%s mapped to variation=%d plan=%s', $offer_code, $variation_id, $variation_plan )
					: 'Derived map missing row or variation override contract failed',
			);
			$steps[] = $step;
			$ctx->emit_step( $step );
		} catch ( Exception $e ) {
			$step = array(
				'label'  => 'Runtime · synthetic product meta -> derived map + variation override',
				'status' => 'fail',
				'detail' => 'Exception: ' . $e->getMessage(),
			);
			$steps[] = $step;
			$ctx->emit_step( $step );
		} finally {
			if ( $variation_id > 0 ) {
				wp_delete_post( $variation_id, true );
			}
			if ( $parent_id > 0 ) {
				wp_delete_post( $parent_id, true );
			}

			if ( null === $old_map ) {
				delete_option( BizCity_Membership_Woo_Mapper::OPT_MAP );
			} else {
				update_option( BizCity_Membership_Woo_Mapper::OPT_MAP, $old_map, false );
			}
		}

		$pass = $disk_readable && $disk_meta_ok && $loader_ok && $runtime_ok;

		return array(
			'status'  => $pass ? 'pass' : 'fail',
			'summary' => $pass
				? 'Woo mapper foundation contract OK (meta keys + derived option + variation override).'
				: 'Woo mapper contract failed in one or more layers.',
			'steps'   => $steps,
		);
	}

	public function cleanup(): void {
		$this->cleanup_synthetic_posts();
	}

	private function cleanup_synthetic_posts() {
		if ( ! post_type_exists( 'product' ) ) {
			return;
		}
		$ids = get_posts( array(
			'post_type'              => array( 'product', 'product_variation' ),
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
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = new BizCity_Probe_Membership_Woo_Mapper();
	return $list;
} );
