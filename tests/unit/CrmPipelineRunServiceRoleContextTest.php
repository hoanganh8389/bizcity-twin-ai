<?php
/**
 * PHASE-0.69 M-02 — `BizCity_CRM_Pipeline_Run_Service::role_context()`.
 *
 * Regression test for the second half of the 2026-09-23 role-resolution fix: `assignee_of_stage` must
 * mean whoever actually worked THAT stage, not the run's owner — read from
 * `custom_json.stages[stage_key].assignee_id` (explicit, set via `stage_assignee_id` in `transition()`,
 * e.g. a dispatcher assigning a *different* person — 0.69's `service` kind needs this), falling back to
 * `.by` (whoever performed the transition, correct for every other kind), falling back to the run owner
 * when the stage has not been touched at all yet.
 */

use PHPUnit\Framework\TestCase;

/** Minimal `$wpdb` fake: one canned row, regardless of the query text (same style as CrmContactTransferTest.php). */
final class Bzc_RoleContext_Test_Wpdb {
	public $prefix = 'wp_';
	/** @var array<string,mixed>|null */
	public $row;
	public function prepare( $sql, ...$args ) { return $sql; }
	public function get_row( $sql, $output = ARRAY_A ) { return $this->row; }
}

if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { return trim( (string) $s ); } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $v, $o = 0 ) { return json_encode( $v, $o ); } }

require_once dirname( __DIR__, 2 ) . '/plugins/bizcity-twin-crm/includes/pipeline/class-pipeline-run-service.php';

final class CrmPipelineRunServiceRoleContextTest extends TestCase {

	private function withRow( ?array $row ): void {
		$GLOBALS['wpdb'] = new Bzc_RoleContext_Test_Wpdb();
		$GLOBALS['wpdb']->row = $row;
	}

	public function test_assignee_falls_back_to_owner_when_stage_never_touched(): void {
		$this->withRow( array( 'id' => 1, 'owner_id' => 42, 'created_by' => 7, 'custom_json' => wp_json_encode( array( 'stages' => array() ) ) ) );
		$ctx = BizCity_CRM_Pipeline_Run_Service::role_context( 1, 'assigned' );
		$this->assertSame( 42, $ctx['owner_id'] );
		$this->assertSame( 7, $ctx['creator_id'] );
		$this->assertSame( 42, $ctx['assignee_id'] );
	}

	public function test_assignee_prefers_stage_by_over_owner_when_stage_was_started(): void {
		$this->withRow( array( 'id' => 1, 'owner_id' => 42, 'created_by' => 7, 'custom_json' => wp_json_encode( array( 'stages' => array( 'assigned' => array( 'state' => 'doing', 'by' => 99 ) ) ) ) ) );
		$ctx = BizCity_CRM_Pipeline_Run_Service::role_context( 1, 'assigned' );
		$this->assertSame( 99, $ctx['assignee_id'] );
	}

	public function test_assignee_prefers_explicit_assignee_id_over_by(): void {
		// This is exactly the `service` dispatch case: the dispatcher (id 5) clicked "Assign", but staff
		// #123 is who the stage is actually about — `assignee_id` must win, not `by`.
		$this->withRow( array( 'id' => 1, 'owner_id' => 42, 'created_by' => 7, 'custom_json' => wp_json_encode( array( 'stages' => array( 'assigned' => array( 'state' => 'doing', 'by' => 5, 'assignee_id' => 123 ) ) ) ) ) );
		$ctx = BizCity_CRM_Pipeline_Run_Service::role_context( 1, 'assigned' );
		$this->assertSame( 123, $ctx['assignee_id'] );
		$this->assertNotSame( 5, $ctx['assignee_id'] );
	}

	public function test_role_context_for_a_different_stage_key_ignores_other_stages_assignee(): void {
		$this->withRow( array( 'id' => 1, 'owner_id' => 42, 'created_by' => 7, 'custom_json' => wp_json_encode( array( 'stages' => array( 'assigned' => array( 'state' => 'done', 'assignee_id' => 123 ) ) ) ) ) );
		// Asking about the 'checkin' stage, which nobody has touched — must fall back to owner, not leak
		// the 'assigned' stage's assignee.
		$ctx = BizCity_CRM_Pipeline_Run_Service::role_context( 1, 'checkin' );
		$this->assertSame( 42, $ctx['assignee_id'] );
	}

	public function test_missing_run_returns_zeroed_context_not_an_error(): void {
		$this->withRow( null );
		$ctx = BizCity_CRM_Pipeline_Run_Service::role_context( 999, 'assigned' );
		$this->assertSame( array( 'run_id' => 999, 'stage_key' => 'assigned', 'owner_id' => 0, 'creator_id' => 0, 'assignee_id' => 0 ), $ctx );
	}
}
