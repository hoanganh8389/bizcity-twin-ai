<?php
/**
 * BizCity Diagnostics — crm.unification probe (R-UNIFY Wave 1+2+3+5, 2026-06-15).
 *
 * DDV (R-DDV) — 3-layer evidence for Omni-Channel Unification schema migrations:
 *
 *   Wave 1: bizcity_crm_contact_identities table exists + BizCity_CRM_Contact_Identity class loaded.
 *   Wave 2: bizcity_crm_events has contact_id + conversation_id + campaign_id columns.
 *   Wave 3: bizcity_crm_campaigns has automation_workflow_id column.
 *   Wave 5: bizcity_automation_runs has contact_id + conversation_id columns.
 *   Backfill: identity rows backfilled from bizcity_crm_contacts.
 *
 * Severity: critical — failing schema = CRM_Inbox_Bridge cannot route inbound
 * messages to contacts → silent data loss in CRM inbox.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-06-15 (R-UNIFY Wave 1-5)
 */

defined( 'ABSPATH' ) || die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

// [2026-06-15 Johnny Chu] R-UNIFY — double-load guard.
if ( class_exists( 'BizCity_Probe_CRM_Unification', false ) ) {
	return;
}

final class BizCity_Probe_CRM_Unification implements BizCity_Diagnostics_Probe {

	public function id(): string          { return 'crm.unification'; }
	public function label(): string       { return 'CRM Unification Schema (R-UNIFY Waves 1-5)'; }
	public function description(): string {
		return 'Verify Omni-Channel Unification schema: contact_identities table, crm_events FK columns, campaigns workflow link, automation_runs FK columns, identity backfill.';
	}
	public function severity(): string    { return 'critical'; }
	public function order(): int          { return 55; }
	public function icon(): string        { return 'groups'; }
	public function estimate_ms(): int    { return 800; }

	// [2026-06-17 Johnny Chu] HOTFIX — implement cleanup() required by BizCity_Diagnostics_Probe interface.
	public function cleanup(): void {}

	public function precondition() {
		global $wpdb;
		// Only run when the scheduler table exists.
		$t = $wpdb->prefix . 'bizcity_crm_events';
		$exists = $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
			$t
		) );
		if ( ! $exists ) {
			return 'bizcity_crm_events chưa tồn tại — scheduler chưa được cài.';
		}
		return true;
	}

	public function run( $ctx ): array {
		global $wpdb;
		$steps  = array();
		$passed = true;

		/* ------------------------------------------------------------------
		 * Disk layer — classes defined
		 * ------------------------------------------------------------------ */
		$class_loaded = class_exists( 'BizCity_CRM_Contact_Identity' );
		$steps[] = array(
			'layer'  => 'Disk',
			'label'  => 'BizCity_CRM_Contact_Identity class loaded',
			'status' => $class_loaded ? 'pass' : 'fail',
			'detail' => $class_loaded
				? 'class-crm-contact-identity.php loaded via scheduler bootstrap.'
				: 'Class not found — check core/scheduler/bootstrap.php require_once.',
		);
		if ( ! $class_loaded ) {
			$passed = false;
		}

		/* ------------------------------------------------------------------
		 * Loader layer — BizCity_Automation_Repo_Runs class accessible
		 * ------------------------------------------------------------------ */
		$runs_loaded = class_exists( 'BizCity_Automation_Repo_Runs' );
		$steps[] = array(
			'layer'  => 'Loader',
			'label'  => 'BizCity_Automation_Repo_Runs class loaded',
			'status' => $runs_loaded ? 'pass' : 'warn',
			'detail' => $runs_loaded
				? 'Automation repo loaded — contact_id / conversation_id columns available via enqueue($extra).'
				: 'Class not found — core/automation may not be active on this request.',
		);

		/* ------------------------------------------------------------------
		 * Runtime — Wave 1: bizcity_crm_contact_identities exists
		 * ------------------------------------------------------------------ */
		$id_table  = $wpdb->prefix . 'bizcity_crm_contact_identities';
		$tbl_ok    = (bool) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
			$id_table
		) );
		$steps[] = array(
			'layer'     => 'Runtime',
			'label'     => 'Wave 1: bizcity_crm_contact_identities exists',
			'status'    => $tbl_ok ? 'pass' : 'fail',
			'detail'    => $tbl_ok
				? 'Table created by BizCity_CRM_Contact_Identity::ensure().'
				: 'Table missing — call BizCity_CRM_Contact_Identity::ensure() or trigger scheduler install.',
			'fix_hint'  => $tbl_ok ? '' : 'Navs: Tools → BizCity Diagnostics → Site Provisioner → Run installer scheduler.',
		);
		if ( ! $tbl_ok ) {
			$passed = false;
		}

		/* ------------------------------------------------------------------
		 * Runtime — Wave 2: crm_events new FK columns
		 * ------------------------------------------------------------------ */
		$events_table = $wpdb->prefix . 'bizcity_crm_events';
		foreach ( array( 'contact_id', 'conversation_id', 'campaign_id' ) as $col ) {
			$has_col = (bool) $wpdb->get_var( $wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
				$events_table,
				$col
			) );
			$steps[] = array(
				'layer'    => 'Runtime',
				'label'    => "Wave 2: bizcity_crm_events.{$col} exists",
				'status'   => $has_col ? 'pass' : 'fail',
				'detail'   => $has_col
					? "Column {$col} present (SCHEMA_VERSION=4)."
					: "Column {$col} missing — run BizCity_Scheduler_Manager::ensure_schema() (migrate_to_4).",
				'fix_hint' => $has_col ? '' : 'Navs: Tools → BizCity Diagnostics → Site Provisioner → Run installer scheduler.',
			);
			if ( ! $has_col ) {
				$passed = false;
			}
		}

		/* ------------------------------------------------------------------
		 * Runtime — Wave 3: bizcity_crm_campaigns.automation_workflow_id
		 * ------------------------------------------------------------------ */
		$campaigns_table = $wpdb->prefix . 'bizcity_crm_campaigns';
		$has_campaigns   = (bool) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
			$campaigns_table
		) );
		if ( $has_campaigns ) {
			$has_wf_col = (bool) $wpdb->get_var( $wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
				$campaigns_table,
				'automation_workflow_id'
			) );
			$steps[] = array(
				'layer'    => 'Runtime',
				'label'    => 'Wave 3: bizcity_crm_campaigns.automation_workflow_id exists',
				'status'   => $has_wf_col ? 'pass' : 'fail',
				'detail'   => $has_wf_col
					? 'Column present — campaigns can link to automation workflows (R-UNIFY Wave 3).'
					: 'Column missing — CRM installer needs to run migrate_phase_053() ADD-only.',
				'fix_hint' => $has_wf_col ? '' : 'Navs: Tools → BizCity Diagnostics → Site Provisioner → Run installer twin_crm.',
			);
			if ( ! $has_wf_col ) {
				$passed = false;
			}
		} else {
			$steps[] = array(
				'layer'  => 'Runtime',
				'label'  => 'Wave 3: bizcity_crm_campaigns table',
				'status' => 'skip',
				'detail' => 'bizcity_crm_campaigns table does not exist — CRM module not installed on this site.',
			);
		}

		/* ------------------------------------------------------------------
		 * Runtime — Wave 5: bizcity_automation_runs new FK columns
		 * ------------------------------------------------------------------ */
		$runs_table = $wpdb->prefix . 'bizcity_automation_runs';
		$has_runs   = (bool) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
			$runs_table
		) );
		if ( $has_runs ) {
			foreach ( array( 'contact_id', 'conversation_id' ) as $col ) {
				$has_col = (bool) $wpdb->get_var( $wpdb->prepare(
					'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
					$runs_table,
					$col
				) );
				$steps[] = array(
					'layer'    => 'Runtime',
					'label'    => "Wave 5: bizcity_automation_runs.{$col} exists",
					'status'   => $has_col ? 'pass' : 'fail',
					'detail'   => $has_col
						? "Column {$col} present — automation runs can be linked to CRM contacts."
						: "Column {$col} missing — trigger BizCity_Automation_Installer::ensure() (v1.9.0 DDL).",
					'fix_hint' => $has_col ? '' : 'Navs: Tools → BizCity Diagnostics → Site Provisioner → Run installer automation.',
				);
				if ( ! $has_col ) {
					$passed = false;
				}
			}
		} else {
			$steps[] = array(
				'layer'  => 'Runtime',
				'label'  => 'Wave 5: bizcity_automation_runs table',
				'status' => 'skip',
				'detail' => 'bizcity_automation_runs does not exist — automation module not installed.',
			);
		}

		/* ------------------------------------------------------------------
		 * Runtime — Backfill: identities populated from contacts
		 * ------------------------------------------------------------------ */
		if ( $tbl_ok && $class_loaded ) {
			$id_count      = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $id_table );
			$contact_count = (bool) $wpdb->get_var( $wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
				$wpdb->prefix . 'bizcity_crm_contacts'
			) ) ? (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bizcity_crm_contacts WHERE platform_uid IS NOT NULL AND platform_uid <> ''"
			) : 0;
			$backfill_ok = ( $contact_count === 0 || $id_count > 0 );
			$steps[] = array(
				'layer'    => 'Runtime',
				'label'    => 'Backfill: contact identities populated',
				'status'   => $backfill_ok ? 'pass' : 'warn',
				'detail'   => $backfill_ok
					? "contact_identities={$id_count} rows, crm_contacts with platform_uid={$contact_count}."
					: "contact_identities={$id_count} but crm_contacts has {$contact_count} rows with platform_uid — run BizCity_CRM_Contact_Identity::backfill_from_contacts().",
				'fix_hint' => $backfill_ok ? '' : 'Navs: Tools → BizCity Diagnostics → click 🔧 Fix next to this step.',
			);
		}

		$ctx->emit_steps( $steps );
		return array(
			'passed' => $passed,
			'note'   => $passed
				? 'All R-UNIFY schema checks passed.'
				: 'One or more R-UNIFY schema migrations are incomplete — see steps above.',
		);
	}
}

// Register probe via filter.
add_filter( 'bizcity_diagnostics_register_probes', function ( array $probes ): array {
	$probes[] = new BizCity_Probe_CRM_Unification();
	return $probes;
} );
