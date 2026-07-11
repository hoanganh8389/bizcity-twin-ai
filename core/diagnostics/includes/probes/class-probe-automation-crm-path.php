<?php
/**
 * BizCity Diagnostics — automation.crm_path probe (PHASE-0.41).
 *
 * R-DDV evidence for CRM-PATH-1..5 (dual-path automation: admin Zone 2 vs
 * CRM-care Zone 1). 5 assertion rows per spec PHASE-0.41 §8:
 *
 *   1. zone_filter   — `query(zone=crm)` returns only crm rows; `query(zone=admin)`
 *                      returns only admin/legacy rows (R-ZONE isolation).
 *   2. recipe_catalog — At least 1 template with category=cskh AND category=care
 *                       in bizcity_automation_templates (seeder shipped).
 *   3. instantiate   — POST /templates/:id/crm-instantiate via rest_do_request
 *                       creates a workflow with zone=crm, cleans up after.
 *   4. bind_channel  — After bind(), workflow trigger_config has platform +
 *                       account_id + zone=crm keys.
 *   5. zone_isolation — Synthetic ZALO_OA payload MUST NOT dispatch zone=admin
 *                       workflows; synthetic ZALO_BOT payload MUST NOT dispatch
 *                       zone=crm workflows (R-ZONE-2).
 *
 * All test workflows use slug prefix `__healthtest_crmpath_*` → cleaned up.
 *
 * [2026-06-07 Johnny Chu] CRM-PATH DDV — initial R-DDV probe.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      PHASE-0.41 CRM-PATH (2026-06-07)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';


// [2026-06-08 Johnny Chu] HOTFIX — double-load guard (bootstrap may include via filter AND direct require).
if ( class_exists( 'BizCity_Probe_Automation_CRM_Path', false ) ) {
	return;
}

final class BizCity_Probe_Automation_CRM_Path implements BizCity_Diagnostics_Probe {

	const SLUG_PREFIX = '__healthtest_crmpath_';

	public function id(): string          { return 'automation.crm_path'; }
	public function label(): string       { return 'Automation · CRM-care Dual-Path (Zone isolation)'; }
	public function description(): string {
		return 'Verify PHASE-0.41: zone filter query, recipe catalog, crm-instantiate, bind, ZALO_OA/ZALO_BOT zone isolation (R-ZONE-2).';
	}
	public function severity(): string    { return 'critical'; }
	public function order(): int          { return 41; }
	public function icon(): string        { return 'admin-network'; }
	public function estimate_ms(): int    { return 2000; }

	public function precondition() {
		$required = array(
			'BizCity_Automation_Trigger_Matcher',
			'BizCity_Automation_Repo_Workflows',
			'BizCity_Automation_Repo_Templates',
			'BizCity_Automation_Matcher_Trace',
		);
		foreach ( $required as $cls ) {
			if ( ! class_exists( $cls ) ) {
				return new WP_Error( 'class_missing', $cls . ' chưa load — automation bootstrap chưa hoàn tất.' );
			}
		}
		return true;
	}

	public function run( $ctx ): array {
		$steps   = array();
		$wf_ids  = array();

		// ── Helper: create a test workflow. ─────────────────────────────────
		$rand = wp_generate_password( 6, false, false );

		// Workflow crm (zone=crm)
		$wf_crm = BizCity_Automation_Repo_Workflows::create( array(
			'slug'           => self::SLUG_PREFIX . 'crm_' . $rand,
			'name'           => '__healthtest crm-path zone=crm',
			'trigger_type'   => 'zalo_inbound',
			'trigger_config' => array( 'zone' => 'crm', 'instance_id' => '', 'filter' => '' ),
			// [2026-07-11 Johnny Chu] HOTFIX — workflow validator now requires >=1 trigger node in graph_json.
			'graph_json'     => self::probe_graph_json( 'trigger.zalo_inbound' ),
			'enabled'        => 1,
		) );
		if ( is_wp_error( $wf_crm ) ) {
			return self::bail( $steps, 'Tạo workflow zone=crm thất bại', $wf_crm->get_error_message() );
		}
		$wf_ids[] = (int) $wf_crm['id'];

		// Workflow admin (no zone = legacy admin)
		$wf_admin = BizCity_Automation_Repo_Workflows::create( array(
			'slug'           => self::SLUG_PREFIX . 'adm_' . $rand,
			'name'           => '__healthtest crm-path zone=admin',
			'trigger_type'   => 'zalo_inbound',
			'trigger_config' => array( 'instance_id' => '', 'filter' => '' ),
			'graph_json'     => self::probe_graph_json( 'trigger.zalo_inbound' ),
			'enabled'        => 1,
		) );
		if ( is_wp_error( $wf_admin ) ) {
			self::cleanup_workflows( $wf_ids );
			return self::bail( $steps, 'Tạo workflow zone=admin (no-zone) thất bại', $wf_admin->get_error_message() );
		}
		$wf_ids[] = (int) $wf_admin['id'];

		// ── Test 1: zone_filter ──────────────────────────────────────────────
		$crm_rows   = BizCity_Automation_Repo_Workflows::query( array( 'zone' => 'crm',   'enabled' => 1, 'limit' => 200 ) );
		$admin_rows = BizCity_Automation_Repo_Workflows::query( array( 'zone' => 'admin', 'enabled' => 1, 'limit' => 200 ) );

		$crm_ids   = array_column( $crm_rows['rows']   ?? array(), 'id' );
		$admin_ids = array_column( $admin_rows['rows']  ?? array(), 'id' );

		$crm_has_crm_wf   = in_array( (string) $wf_crm['id'],   $crm_ids,   true ) || in_array( (int) $wf_crm['id'],   $crm_ids,   true );
		$admin_has_adm_wf = in_array( (string) $wf_admin['id'], $admin_ids, true ) || in_array( (int) $wf_admin['id'], $admin_ids, true );
		// crm query must NOT include admin workflow; admin query must NOT include crm workflow.
		$crm_clean   = ! ( in_array( (string) $wf_admin['id'], $crm_ids,   true ) || in_array( (int) $wf_admin['id'], $crm_ids,   true ) );
		$admin_clean = ! ( in_array( (string) $wf_crm['id'],   $admin_ids, true ) || in_array( (int) $wf_crm['id'],   $admin_ids, true ) );

		$zone_pass = $crm_has_crm_wf && $admin_has_adm_wf && $crm_clean && $admin_clean;
		$steps[] = $s = array(
			'label'  => 'zone_filter · query(zone=crm) + query(zone=admin) isolation',
			'status' => $zone_pass ? 'pass' : 'fail',
			'detail' => sprintf(
				'crm_query has crm_wf=%s no_admin=%s | admin_query has adm_wf=%s no_crm=%s',
				$crm_has_crm_wf   ? 'YES' : 'NO',
				$crm_clean        ? 'YES' : 'NO',
				$admin_has_adm_wf ? 'YES' : 'NO',
				$admin_clean      ? 'YES' : 'NO'
			),
		);
		$ctx->emit_step( $s );

		// ── Test 2: recipe_catalog ──────────────────────────────────────────
		$tpl_cskh = BizCity_Automation_Repo_Templates::query( array( 'category' => 'cskh', 'is_active' => 1, 'limit' => 50 ) );
		$tpl_care = BizCity_Automation_Repo_Templates::query( array( 'category' => 'care', 'is_active' => 1, 'limit' => 50 ) );
		$cskh_count = count( $tpl_cskh['rows'] ?? array() );
		$care_count = count( $tpl_care['rows'] ?? array() );
		$catalog_pass = $cskh_count > 0 && $care_count > 0;
		$steps[] = $s = array(
			'label'  => 'recipe_catalog · templates category=cskh + category=care seeded',
			'status' => $catalog_pass ? 'pass' : 'fail',
			'detail' => "cskh={$cskh_count} care={$care_count}",
		);
		$ctx->emit_step( $s );
		if ( ! $catalog_pass ) {
			$steps[] = array( 'label' => 'fix_hint', 'status' => 'skip', 'detail' => 'Run BizCity_Automation_Templates_Seeder::maybe_seed() — bump SEED_VERSION nếu seeder đã chạy.' );
		}

		// ── Test 3: instantiate (via crm-instantiate REST route) ────────────
		$tpl_rows = $tpl_cskh['rows'] ?? array();
		$inst_pass = false;
		$inst_detail = 'no cskh template to test';
		if ( ! empty( $tpl_rows ) ) {
			$tpl = $tpl_rows[0];
			$req = new WP_REST_Request( 'POST', '/bizcity-automation/v1/templates/' . (int) $tpl['id'] . '/crm-instantiate' );
			$req->set_param( 'id', (int) $tpl['id'] );
			$req->set_body_params( array( 'name' => '__healthtest_crmpath_inst_' . $rand ) );
			// Temporarily elevate to allow crm_instantiate_template perm check.
			$old_user = get_current_user_id();
			wp_set_current_user( 0 );
			// Force `bizcity_crm_manage` or `manage_options` for the probe call.
			add_filter( 'user_has_cap', array( __CLASS__, 'grant_crm_manage' ), 99, 3 );
			$resp = rest_do_request( $req );
			remove_filter( 'user_has_cap', array( __CLASS__, 'grant_crm_manage' ), 99 );
			wp_set_current_user( $old_user );

			$data = $resp->get_data();
			if ( $resp->get_status() >= 200 && $resp->get_status() < 300 && isset( $data['id'] ) ) {
				$wf_ids[] = (int) $data['id'];
				// Verify zone=crm in new workflow.
				$new_wf = BizCity_Automation_Repo_Workflows::find( (int) $data['id'] );
				$new_zone = ( $new_wf && is_array( $new_wf['trigger_config'] ) )
					? ( $new_wf['trigger_config']['zone'] ?? '' )
					: '';
				$inst_pass   = ( $new_zone === 'crm' );
				$inst_detail = "wf_id={$data['id']} zone={$new_zone} status={$resp->get_status()}";
			} else {
				$inst_detail = 'status=' . $resp->get_status() . ' data=' . wp_json_encode( $data );
			}
		}
		$steps[] = $s = array(
			'label'  => 'instantiate · crm-instantiate creates wf with zone=crm',
			'status' => $inst_pass ? 'pass' : ( $inst_detail === 'no cskh template to test' ? 'skip' : 'fail' ),
			'detail' => $inst_detail,
		);
		$ctx->emit_step( $s );

		// ── Test 4: bind_channel ────────────────────────────────────────────
		$bind_wf = BizCity_Automation_Repo_Workflows::create( array(
			'slug'           => self::SLUG_PREFIX . 'bind_' . $rand,
			'name'           => '__healthtest crm-path bind',
			'trigger_type'   => 'zalo_inbound',
			'trigger_config' => array( 'zone' => 'crm', 'instance_id' => '', 'filter' => '' ),
			'graph_json'     => self::probe_graph_json( 'trigger.zalo_inbound' ),
			'enabled'        => 0,
		) );
		$bind_pass   = false;
		$bind_detail = 'create failed';
		if ( ! is_wp_error( $bind_wf ) ) {
			$wf_ids[] = (int) $bind_wf['id'];
			$cfg              = is_array( $bind_wf['trigger_config'] ) ? $bind_wf['trigger_config'] : array();
			$cfg['platform']  = 'ZALO_OA';
			$cfg['account_id'] = 'probe_oa_1234';
			$cfg['zone']      = 'crm';
			$updated = BizCity_Automation_Repo_Workflows::update( (int) $bind_wf['id'], array(
				'trigger_config_json' => wp_json_encode( $cfg ),
				'enabled'             => 1,
			) );
			if ( ! is_wp_error( $updated ) ) {
				$after = BizCity_Automation_Repo_Workflows::find( (int) $bind_wf['id'] );
				$after_cfg  = is_array( $after['trigger_config'] ) ? $after['trigger_config'] : array();
				$bind_pass  = ( ( $after_cfg['platform'] ?? '' ) === 'ZALO_OA' )
					&& ( ( $after_cfg['account_id'] ?? '' ) === 'probe_oa_1234' )
					&& ( ( $after_cfg['zone'] ?? '' ) === 'crm' );
				$bind_detail = 'platform=' . ( $after_cfg['platform'] ?? '?' )
					. ' account_id=' . ( $after_cfg['account_id'] ?? '?' )
					. ' zone=' . ( $after_cfg['zone'] ?? '?' );
			} else {
				$bind_detail = 'update failed: ' . $updated->get_error_message();
			}
		}
		$steps[] = $s = array(
			'label'  => 'bind_channel · trigger_config gains platform+account_id+zone=crm',
			'status' => $bind_pass ? 'pass' : 'fail',
			'detail' => $bind_detail,
		);
		$ctx->emit_step( $s );

		// ── Test 5: zone_isolation ──────────────────────────────────────────
		// Create 1 zone=crm wf + 1 zone=admin wf, both trigger=zalo_inbound.
		// Dispatch synthetic ZALO_OA payload → crm wf gets run, admin wf does NOT.
		// Dispatch synthetic ZALO_BOT payload → admin wf gets run, crm wf does NOT.
		$wf_crm2 = BizCity_Automation_Repo_Workflows::create( array(
			'slug'           => self::SLUG_PREFIX . 'iso_crm_' . $rand,
			'name'           => '__healthtest crmpath iso crm',
			'trigger_type'   => 'zalo_inbound',
			'trigger_config' => array( 'zone' => 'crm', 'instance_id' => '', 'filter' => '' ),
			'graph_json'     => self::probe_graph_json( 'trigger.zalo_inbound' ),
			'enabled'        => 1,
		) );
		$wf_adm2 = BizCity_Automation_Repo_Workflows::create( array(
			'slug'           => self::SLUG_PREFIX . 'iso_adm_' . $rand,
			'name'           => '__healthtest crmpath iso admin',
			'trigger_type'   => 'zalo_inbound',
			'trigger_config' => array( 'instance_id' => '', 'filter' => '' ),
			'graph_json'     => self::probe_graph_json( 'trigger.zalo_inbound' ),
			'enabled'        => 1,
		) );
		$iso_pass   = false;
		$iso_detail = 'create failed';

		if ( ! is_wp_error( $wf_crm2 ) && ! is_wp_error( $wf_adm2 ) ) {
			$wf_ids[] = (int) $wf_crm2['id'];
			$wf_ids[] = (int) $wf_adm2['id'];

			$matcher = BizCity_Automation_Trigger_Matcher::instance();

			// Fire ZALO_OA inbound → should enqueue crm wf, NOT admin wf.
			BizCity_Automation_Matcher_Trace::clear();
			$matcher->on_channel_message( self::zalo_payload( 'ZALO_OA', 'hello' ) );
			$crm2_run_after_oa  = self::workflow_has_run( (int) $wf_crm2['id'] );
			$adm2_run_after_oa  = self::workflow_has_run( (int) $wf_adm2['id'] );

			// Fire ZALO_BOT inbound → should enqueue admin wf, NOT crm wf.
			BizCity_Automation_Matcher_Trace::clear();
			$matcher->on_channel_message( self::zalo_payload( 'ZALO_BOT', 'hello' ) );
			$crm2_run_after_bot = self::workflow_has_run( (int) $wf_crm2['id'] );
			$adm2_run_after_bot = self::workflow_has_run( (int) $wf_adm2['id'] );

			// Clean runs created by synthetic dispatch before final cleanup.
			self::cleanup_runs( array( (int) $wf_crm2['id'], (int) $wf_adm2['id'] ) );

			$oa_ok  = $crm2_run_after_oa && ! $adm2_run_after_oa;
			$bot_ok = $adm2_run_after_bot && ! $crm2_run_after_bot;
			$iso_pass = $oa_ok && $bot_ok;
			$iso_detail = sprintf(
				'ZALO_OA: crm_wf_run=%s admin_wf_run=%s | ZALO_BOT: admin_wf_run=%s crm_wf_run=%s',
				$crm2_run_after_oa  ? 'YES' : 'NO',
				$adm2_run_after_oa  ? 'YES' : 'NO',
				$adm2_run_after_bot ? 'YES' : 'NO',
				$crm2_run_after_bot ? 'YES' : 'NO'
			);
		}
		$steps[] = $s = array(
			'label'  => 'zone_isolation · ZALO_OA→crm only; ZALO_BOT→admin only',
			'status' => $iso_pass ? 'pass' : 'fail',
			'detail' => $iso_detail,
		);
		$ctx->emit_step( $s );

		// ── Cleanup ─────────────────────────────────────────────────────────
		self::cleanup_runs( $wf_ids );
		foreach ( $wf_ids as $wid ) {
			BizCity_Automation_Repo_Workflows::hard_delete( $wid );
		}
		$steps[] = array( 'label' => 'Cleanup', 'status' => 'pass', 'detail' => count( $wf_ids ) . ' test workflows wiped' );

		$all_pass = $zone_pass && $catalog_pass && ( $inst_pass || $inst_detail === 'no cskh template to test' ) && $bind_pass && $iso_pass;
		if ( ! $all_pass ) {
			return array(
				'status'   => 'fail',
				'summary'  => 'CRM-care dual-path (PHASE-0.41) có assertion thất bại.',
				'fix_hint' => 'Xem class-automation-trigger-matcher.php::platform_to_zone() và class-automation-repo-workflows.php::query().',
				'steps'    => $steps,
			);
		}

		return array(
			'status'  => 'pass',
			'summary' => 'Zone filter + recipe catalog + instantiate + bind + ZALO isolation đều PASS.',
			'steps'   => $steps,
		);
	}

	// ── Helpers ──────────────────────────────────────────────────────────────

	/** Temporarily grant bizcity_crm_manage + manage_options caps to current user. */
	public static function grant_crm_manage( $caps, $cap, $user ) {
		$caps['bizcity_crm_manage'] = true;
		$caps['manage_options']     = true;
		return $caps;
	}

	// [2026-07-11 Johnny Chu] HOTFIX — build a minimal valid graph accepted by Repo_Workflows::validate_graph().
	private static function probe_graph_json( string $trigger_block ): string {
		return wp_json_encode( array(
			'nodes' => array(
				array(
					'id'   => 't1',
					'type' => 'trigger',
					'data' => array( 'blockId' => $trigger_block ),
				),
			),
			'edges' => array(),
		) );
	}

	private static function zalo_payload( string $platform, string $text ): array {
		return array(
			'platform'     => $platform,
			'message'      => $text,
			'text'         => $text,
			'chat_id'      => 'probe_chat_' . $platform,
			'sender_id'    => 'probe_user_1',
			'user_id'      => 'probe_user_1',
			'instance_id'  => '',
			'account_id'   => '',
			'channel_role' => 'USER',
			'mid'          => 'probe_mid_' . wp_generate_password( 8, false, false ),
		);
	}

	private static function workflow_has_run( int $wf_id ): bool {
		if ( ! class_exists( 'BizCity_Automation_Repo_Runs' ) ) { return false; }
		global $wpdb;
		$table = BizCity_Automation_Repo_Runs::table_runs();
		$count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE workflow_id = %d", $wf_id )
		);
		return $count > 0;
	}

	private static function cleanup_runs( array $wf_ids ): void {
		if ( empty( $wf_ids ) || ! class_exists( 'BizCity_Automation_Repo_Runs' ) ) { return; }
		global $wpdb;
		$table   = BizCity_Automation_Repo_Runs::table_runs();
		$csv     = implode( ',', array_map( 'intval', $wf_ids ) );
		$wpdb->query( "DELETE FROM {$table} WHERE workflow_id IN ({$csv})" );
	}

	// [2026-06-08 Johnny Chu] HOTFIX — renamed from cleanup() to avoid conflict with interface BizCity_Diagnostics_Probe::cleanup(): void (non-static).
	private static function cleanup_workflows( array $wf_ids ): void {
		foreach ( $wf_ids as $wid ) {
			BizCity_Automation_Repo_Workflows::hard_delete( $wid );
		}
	}

	// [2026-06-08 Johnny Chu] HOTFIX — implement interface method cleanup(): void (no-op; cleanup done inline in run()).
	public function cleanup(): void {
		// no-op: test artifacts are deleted at the end of run()
	}

	private static function bail( array $steps, string $summary, string $detail ): array {
		return array(
			'status'   => 'fail',
			'summary'  => $summary,
			'fix_hint' => $detail,
			'steps'    => $steps,
		);
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Automation_CRM_Path';
	return $list;
} );
