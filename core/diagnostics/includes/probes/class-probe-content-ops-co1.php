<?php
/**
 * BizCity Diagnostics — content_ops.co1 probe (Consolidation M8).
 *
 * R-DDV — wrap Sprint CO-1 Foundation smoke (T-CO-1.1 … T-CO-1.7) into the
 * canonical probe framework. Replaces standalone admin page
 * `tools.php?page=bizcity-content-ops-sprint-diag`
 * (per DIAGNOSTIC-CONSOLIDATION-PLAN.md §3.2 / Migration roadmap M8).
 *
 * Tasks mirrored:
 *   T-CO-1.1  Schema installed (tables + version option)
 *   T-CO-1.2  REST namespace bizcity-content/v1 registered (>= 10 routes)
 *   T-CO-1.3  CPT bizcity_doc + taxonomy bizcity_channel_target registered
 *   T-CO-1.4  SPA bundle artifacts present (js >= 50KB, css >= 2KB)
 *   T-CO-1.5  Scheduler heartbeat < 300s (WARN < 900s · FAIL >= 900s)
 *   T-CO-1.6  LLM gateway reachable (BizCity_Content_LLM_Proxy::is_ready())
 *   T-CO-1.7  Channel readiness matrix returns >= 1 ready platform
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      Consolidation M8 (2026-06-03)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';


// [2026-06-08 Johnny Chu] HOTFIX — double-load guard (bootstrap may include via filter AND direct require).
if ( class_exists( 'BizCity_Probe_Content_Ops_CO1', false ) ) {
	return;
}

final class BizCity_Probe_Content_Ops_CO1 implements BizCity_Diagnostics_Probe {

	public function id(): string          { return 'content_ops.co1'; }
	public function label(): string       { return 'Content Ops · Sprint CO-1 Foundation'; }
	public function description(): string {
		return 'Aggregate Sprint CO-1 smoke: schema, REST, CPT, SPA bundle, scheduler heartbeat, LLM proxy, channel readiness matrix. PASS = no FAIL row.';
	}
	public function severity(): string    { return 'warning'; }
	public function order(): int          { return 78; }
	public function icon(): string        { return 'edit-page'; }
	public function estimate_ms(): int    { return 800; }

	public function precondition() {
		if ( ! defined( 'BIZCITY_CONTENT_OPS_LOADED' ) ) {
			return new WP_Error( 'module_missing', 'core/content-ops module chưa load.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		$steps  = array();
		$fails  = array();
		$warns  = 0;

		$emit = static function ( $ctx, array $step ) use ( &$steps ) {
			$steps[] = $step;
			if ( is_object( $ctx ) && method_exists( $ctx, 'emit_step' ) ) {
				$ctx->emit_step( $step );
			}
		};

		// ---------- T-CO-1.1 schema ----------
		global $wpdb;
		$schema_ok = false;
		$schema_detail = 'BizCity_Content_Ops_Schema missing';
		if ( class_exists( 'BizCity_Content_Ops_Schema' ) ) {
			$expected   = (array) BizCity_Content_Ops_Schema::tables();
			$expected_v = BizCity_Content_Ops_Schema::VERSION;
			$missing    = array();
			foreach ( $expected as $t ) {
				if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) ) {
					$missing[] = $t;
				}
			}
			$installed     = (string) get_option( 'bizcity_content_ops_db_version', '' );
			$schema_ok     = empty( $missing ) && $installed === (string) $expected_v;
			$schema_detail = sprintf(
				'expected=%d · missing=%s · db_version=%s (want %s)',
				count( $expected ),
				$missing ? implode( ',', $missing ) : '0',
				$installed ?: '—',
				$expected_v
			);
		}
		$emit( $ctx, array(
			'label'  => 'T-CO-1.1 · schema installed',
			'status' => $schema_ok ? 'pass' : 'fail',
			'detail' => $schema_detail,
		) );
		if ( ! $schema_ok ) { $fails[] = 'T-CO-1.1 schema'; }

		// ---------- T-CO-1.2 REST namespace ----------
		$count = 0;
		if ( function_exists( 'rest_get_server' ) ) {
			foreach ( rest_get_server()->get_routes() as $path => $_ ) {
				if ( strpos( $path, '/bizcity-content/v1' ) === 0 ) { ++$count; }
			}
		}
		$rest_status = $count >= 10 ? 'pass' : ( $count > 0 ? 'warn' : 'fail' );
		$emit( $ctx, array(
			'label'  => 'T-CO-1.2 · REST bizcity-content/v1',
			'status' => $rest_status,
			'detail' => sprintf( 'routes=%d (expected >= 10)', $count ),
		) );
		if ( $rest_status === 'fail' ) { $fails[] = 'T-CO-1.2 REST'; }
		elseif ( $rest_status === 'warn' ) { ++$warns; }

		// ---------- T-CO-1.3 CPT + taxonomy ----------
		$cpt = false; $tax = false; $cpt_name = ''; $tax_name = '';
		if ( class_exists( 'BizCity_Content_CPT_Bridge' ) ) {
			$cpt_name = BizCity_Content_CPT_Bridge::CPT;
			$tax_name = BizCity_Content_CPT_Bridge::TAXONOMY;
			$cpt = post_type_exists( $cpt_name );
			$tax = taxonomy_exists( $tax_name );
		}
		$cpt_ok = $cpt && $tax;
		$emit( $ctx, array(
			'label'  => 'T-CO-1.3 · CPT + taxonomy',
			'status' => $cpt_ok ? 'pass' : 'fail',
			'detail' => sprintf( 'cpt %s=%s · taxonomy %s=%s', $cpt_name, $cpt ? 'yes' : 'no', $tax_name, $tax ? 'yes' : 'no' ),
		) );
		if ( ! $cpt_ok ) { $fails[] = 'T-CO-1.3 CPT'; }

		// ---------- T-CO-1.4 SPA bundle ----------
		$base = defined( 'BIZCITY_CONTENT_OPS_DIR' ) ? BIZCITY_CONTENT_OPS_DIR . '/assets/dist/' : '';
		$js   = $base . 'content-ops-app.js';
		$css  = $base . 'content-ops-app.css';
		$jsok  = is_file( $js ) && filesize( $js ) > 50 * 1024;
		$cssok = is_file( $css ) && filesize( $css ) > 2 * 1024;
		$bundle_status = ( $jsok && $cssok ) ? 'pass' : ( ( is_file( $js ) || is_file( $css ) ) ? 'warn' : 'fail' );
		$emit( $ctx, array(
			'label'  => 'T-CO-1.4 · SPA bundle',
			'status' => $bundle_status,
			'detail' => sprintf(
				'js=%s (%s) · css=%s (%s)',
				is_file( $js ) ? 'OK' : 'MISSING', is_file( $js ) ? (int) filesize( $js ) : 0,
				is_file( $css ) ? 'OK' : 'MISSING', is_file( $css ) ? (int) filesize( $css ) : 0
			),
		) );
		if ( $bundle_status === 'fail' ) { $fails[] = 'T-CO-1.4 SPA bundle'; }
		elseif ( $bundle_status === 'warn' ) { ++$warns; }

		// ---------- T-CO-1.5 scheduler heartbeat ----------
		$hb_status = 'warn';
		$hb_msg    = 'scheduler class missing';
		$next      = 0;
		if ( class_exists( 'BizCity_Content_Scheduler' ) ) {
			$hb  = (int) get_option( BizCity_Content_Scheduler::HEARTBEAT_OPTION, 0 );
			$age = $hb ? ( time() - $hb ) : null;
			if ( $age === null ) {
				$hb_status = 'warn'; $hb_msg = 'never_ticked';
			} elseif ( $age < 300 ) {
				$hb_status = 'pass'; $hb_msg = $age . 's ago';
			} elseif ( $age < 900 ) {
				$hb_status = 'warn'; $hb_msg = $age . 's ago (>5min)';
			} else {
				$hb_status = 'fail'; $hb_msg = $age . 's ago (>15min)';
			}
			$next = (int) wp_next_scheduled( BizCity_Content_Scheduler::CRON_HOOK );
		}
		$emit( $ctx, array(
			'label'  => 'T-CO-1.5 · scheduler heartbeat',
			'status' => $hb_status,
			'detail' => sprintf( 'heartbeat=%s · next_cron=%s', $hb_msg, $next ? wp_date( 'Y-m-d H:i:s', $next ) : 'none' ),
		) );
		if ( $hb_status === 'fail' ) { $fails[] = 'T-CO-1.5 scheduler'; }
		elseif ( $hb_status === 'warn' ) { ++$warns; }

		// ---------- T-CO-1.6 LLM proxy ready ----------
		$ready  = class_exists( 'BizCity_Content_LLM_Proxy' ) && BizCity_Content_LLM_Proxy::is_ready();
		$mode   = '?';
		if ( class_exists( 'BizCity_LLM_Client' ) && method_exists( 'BizCity_LLM_Client', 'instance' ) ) {
			$inst = BizCity_LLM_Client::instance();
			if ( is_object( $inst ) && method_exists( $inst, 'get_mode' ) ) {
				$mode = (string) ( $inst->get_mode() ?? '?' );
			}
		}
		$emit( $ctx, array(
			'label'  => 'T-CO-1.6 · LLM gateway',
			'status' => $ready ? 'pass' : 'fail',
			'detail' => sprintf( 'is_ready=%s · mode=%s', $ready ? 'true' : 'false', $mode ),
		) );
		if ( ! $ready ) { $fails[] = 'T-CO-1.6 LLM'; }

		// ---------- T-CO-1.7 channel readiness ----------
		$total = 0; $ready_count = 0;
		if ( class_exists( 'BizCity_Content_Channel_Readiness' ) ) {
			$m     = (array) BizCity_Content_Channel_Readiness::matrix();
			$total = count( $m );
			foreach ( $m as $r ) {
				if ( ! empty( $r['ready'] ) ) { ++$ready_count; }
			}
		}
		if ( $total === 0 ) {
			$cr_status = 'skip';
		} elseif ( $ready_count > 0 ) {
			$cr_status = 'pass';
		} else {
			$cr_status = 'warn';
		}
		$emit( $ctx, array(
			'label'  => 'T-CO-1.7 · channel readiness',
			'status' => $cr_status,
			'detail' => sprintf( 'total=%d · ready=%d', $total, $ready_count ),
		) );
		if ( $cr_status === 'warn' ) { ++$warns; }

		// ---------- summary ----------
		if ( $fails ) {
			return array(
				'status'   => 'fail',
				'summary'  => sprintf( 'Sprint CO-1 FAIL — %d failing task(s): %s', count( $fails ), implode( ', ', $fails ) ),
				'error'    => 'rows_failed',
				'fix_hint' => 'Mở Console SPA / module bootstrap để xem chi tiết. Mỗi task map 1:1 row T-CO-1.x trong PHASE-CO-1-FOUNDATION.md.',
				'steps'    => $steps,
			);
		}
		return array(
			'status'  => 'pass',
			'summary' => sprintf( 'Sprint CO-1: 7 tasks OK%s.', $warns > 0 ? sprintf( ' (%d WARN)', $warns ) : '' ),
			'steps'   => $steps,
		);
	}

	public function cleanup(): void { /* read-only probe */ }
}

add_filter( 'bizcity_diagnostics_register_probes', static function ( $list ) {
	$list[] = 'BizCity_Probe_Content_Ops_CO1';
	return $list;
} );
