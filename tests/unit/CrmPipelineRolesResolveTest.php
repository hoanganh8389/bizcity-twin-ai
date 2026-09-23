<?php
/**
 * PHASE-0.63A WP-2.7 — `BizCity_CRM_Pipeline_Roles::resolve_role()`.
 *
 * Regression test for a real bug found and fixed 2026-09-23: the registry's definition VALIDATOR
 * (`class-pipeline-registry.php::check_role()`) has always accepted all four `resolve.relation` values
 * (`team_lead_of_assignee`, `owner_of_run`, `creator_of_run`, `assignee_of_stage`), but the RESOLVER only
 * ever implemented the first one — every shipped template (`purchase.json`, `request.json`,
 * `production.json`) declares roles using the other three, so every `notify(role:...)`/`escalate(role:...)`
 * using them was silently returning `role_resolver_invalid` at fire time. This test locks in the fix and
 * must never go back to only covering `team_lead_of_assignee`.
 */

use PHPUnit\Framework\TestCase;

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code; private $message; private $data;
		public function __construct( $code = '', $message = '', $data = array() ) { $this->code = $code; $this->message = $message; $this->data = $data; }
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
		public function get_error_data() { return $this->data; }
	}
}

require_once dirname( __DIR__, 2 ) . '/plugins/bizcity-twin-crm/includes/pipeline/class-pipeline-roles.php';

final class CrmPipelineRolesResolveTest extends TestCase {

	private function definition( array $roles ): array {
		return array( 'kind' => 'test', 'roles' => $roles );
	}

	public function test_owner_of_run_resolves_to_the_run_owner_from_context(): void {
		$definition = $this->definition( array( 'R01' => array( 'label' => 'BGĐ', 'resolve' => array( 'relation' => 'owner_of_run' ) ) ) );
		$result = BizCity_CRM_Pipeline_Roles::resolve_role( $definition, 'R01', array( 'owner_id' => 42, 'creator_id' => 7, 'assignee_id' => 99 ) );
		$this->assertSame( array( 42 ), $result );
	}

	public function test_creator_of_run_resolves_to_the_creator_not_the_owner(): void {
		$definition = $this->definition( array( 'requester' => array( 'label' => 'Người yêu cầu', 'resolve' => array( 'relation' => 'creator_of_run' ) ) ) );
		$result = BizCity_CRM_Pipeline_Roles::resolve_role( $definition, 'requester', array( 'owner_id' => 42, 'creator_id' => 7, 'assignee_id' => 99 ) );
		$this->assertSame( array( 7 ), $result );
	}

	public function test_assignee_of_stage_resolves_to_the_stage_assignee_not_the_owner(): void {
		$definition = $this->definition( array( 'staff' => array( 'label' => 'Nhân viên', 'resolve' => array( 'relation' => 'assignee_of_stage' ) ) ) );
		$result = BizCity_CRM_Pipeline_Roles::resolve_role( $definition, 'staff', array( 'owner_id' => 42, 'creator_id' => 7, 'assignee_id' => 99 ) );
		$this->assertSame( array( 99 ), $result );
	}

	public function test_assignee_of_stage_falls_back_to_owner_when_no_stage_assignee_yet(): void {
		// A rung firing before anyone has worked the stage — `role_context()` falls back to owner_id in
		// that case, and the resolver must accept that fallback rather than returning nobody.
		$definition = $this->definition( array( 'staff' => array( 'label' => 'Nhân viên', 'resolve' => array( 'relation' => 'assignee_of_stage' ) ) ) );
		$result = BizCity_CRM_Pipeline_Roles::resolve_role( $definition, 'staff', array( 'owner_id' => 42 ) );
		$this->assertSame( array( 42 ), $result );
	}

	public function test_unknown_id_in_context_resolves_to_empty_not_zero_as_a_user_id(): void {
		$definition = $this->definition( array( 'requester' => array( 'label' => 'Người yêu cầu', 'resolve' => array( 'relation' => 'creator_of_run' ) ) ) );
		$this->assertSame( array(), BizCity_CRM_Pipeline_Roles::resolve_role( $definition, 'requester', array( 'creator_id' => 0 ) ) );
	}

	public function test_users_list_still_works( ): void {
		$definition = $this->definition( array( 'R09' => array( 'label' => 'QAQC', 'resolve' => array( 'users' => array( 12, 18, 12 ) ) ) ) );
		$this->assertSame( array( 12, 18 ), BizCity_CRM_Pipeline_Roles::resolve_role( $definition, 'R09', array() ) );
	}

	public function test_unknown_role_is_a_fail_closed_error(): void {
		$definition = $this->definition( array() );
		$result = BizCity_CRM_Pipeline_Roles::resolve_role( $definition, 'nope', array() );
		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_resolve_recipients_only_accepts_role_expressions(): void {
		$definition = $this->definition( array( 'R01' => array( 'label' => 'BGĐ', 'resolve' => array( 'relation' => 'owner_of_run' ) ) ) );
		$this->assertSame( array( 42 ), BizCity_CRM_Pipeline_Roles::resolve_recipients( $definition, 'notify(role:R01)', array( 'owner_id' => 42 ) ) );
		$this->assertSame( array( 42 ), BizCity_CRM_Pipeline_Roles::resolve_recipients( $definition, 'escalate(role:R01)', array( 'owner_id' => 42 ) ) );
		$this->assertInstanceOf( WP_Error::class, BizCity_CRM_Pipeline_Roles::resolve_recipients( $definition, 'notify(assignee)', array( 'owner_id' => 42 ) ) );
	}
}
