<?php
/**
 * BizCity Diagnostics - explicit #workflow_slug and Zone 2 boundary probe.
 *
 * Verifies the shared command resolver used by TwinChat and TwinWeb, plus the
 * progress projector's Zone 2 Zalo Bot target boundary. Temporary workflows
 * are deleted in finally; no LLM call or channel message is sent.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-08-16 (CCG-1/CCG-7)
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

if ( class_exists( 'BizCity_Probe_TwinBrain_Command_Zone', false ) ) {
	return;
}

final class BizCity_Probe_TwinBrain_Command_Zone implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'core.twinbrain.command_zone'; }
	public function label(): string { return 'TwinBrain - Explicit Workflow and Zone 2 Boundary'; }
	public function description(): string {
		return 'Kiểm tra exact #workflow_slug dùng chung TwinChat/TwinWeb, CRM workflow bị từ chối trong Zone 2, và progress projector chỉ nhận Zalo Bot.';
	}
	public function severity(): string { return 'critical'; }
	public function order(): int { return 54; }
	public function icon(): string { return 'shield-check'; }
	public function estimate_ms(): int { return 300; }

	public function precondition() {
		// [2026-08-16 Johnny Chu] CCG-1/CCG-7 - require the canonical resolver, workflow repository, and projector boundary.
		foreach ( array( 'BizCity_Automation_Command_Resolver', 'BizCity_Automation_Repo_Workflows', 'BizCity_TwinBrain_Progress_Notice_Projector' ) as $class_name ) {
			if ( ! class_exists( $class_name ) ) {
				return new WP_Error( 'class_missing', $class_name . ' chưa load.' );
			}
		}
		return true;
	}

	public function run( $ctx ): array {
		$steps = array();
		$ids   = array();
		$admin_slug = '__healthtest_command_admin_' . strtolower( wp_generate_password( 6, false, false ) );
		$crm_slug   = '__healthtest_command_crm_' . strtolower( wp_generate_password( 6, false, false ) );

		$admin_workflow = BizCity_Automation_Repo_Workflows::create( self::workflow_input( $admin_slug, 'admin' ) );
		if ( is_wp_error( $admin_workflow ) ) {
			return array( 'status' => 'fail', 'steps' => array( array( 'label' => 'Runtime - create admin healthtest workflow', 'status' => 'fail', 'detail' => $admin_workflow->get_error_message() ) ) );
		}
		$ids[] = (int) $admin_workflow['id'];

		$crm_workflow = BizCity_Automation_Repo_Workflows::create( self::workflow_input( $crm_slug, 'crm' ) );
		if ( is_wp_error( $crm_workflow ) ) {
			self::cleanup_workflows( $ids );
			return array( 'status' => 'fail', 'steps' => array( array( 'label' => 'Runtime - create CRM healthtest workflow', 'status' => 'fail', 'detail' => $crm_workflow->get_error_message() ) ) );
		}
		$ids[] = (int) $crm_workflow['id'];

		try {
			// [2026-08-16 Johnny Chu] CCG-1 - exact parser must preserve one slug and trailing command args.
			$parsed = BizCity_Automation_Command_Resolver::extract( '  #' . $admin_slug . ' tao noi dung' );
			self::add_step( $steps, $ctx, 'Runtime - exact #workflow_slug parser', is_array( $parsed ) && $parsed['slug'] === $admin_slug && $parsed['args'] === 'tao noi dung', 'slug=' . (string) ( $parsed['slug'] ?? '' ) . ' args preserved' );

			$admin_resolved = BizCity_Automation_Command_Resolver::resolve(
				'#' . $admin_slug . ' tao noi dung',
				array( 'user_id' => 0, 'is_admin' => true, 'zone' => 'admin' ),
				array( 'zone' => 'admin' )
			);
			self::add_step( $steps, $ctx, 'Runtime - admin workflow resolves in Zone 2', ! empty( $admin_resolved['matched'] ), 'reason=' . (string) ( $admin_resolved['reason'] ?? '' ) );

			$crm_resolved = BizCity_Automation_Command_Resolver::resolve(
				'#' . $crm_slug,
				array( 'user_id' => 0, 'is_admin' => true, 'zone' => 'admin' ),
				array( 'zone' => 'admin' )
			);
			self::add_step( $steps, $ctx, 'Runtime - CRM workflow denied in Zone 2', ( $crm_resolved['reason'] ?? '' ) === 'workflow_zone_denied', 'reason=' . (string) ( $crm_resolved['reason'] ?? '' ) );

			$projector_method = new ReflectionMethod( 'BizCity_TwinBrain_Progress_Notice_Projector', 'target_from_run' );
			$projector_method->setAccessible( true );
			$zalo_target = $projector_method->invoke( null, array( 'trigger_payload' => array( 'platform' => 'ZALO_BOT', 'code' => 'zalo_bot', 'zone' => 'admin', 'chat_id' => 'zalobot_healthtest_direct' ) ) );
			$crm_target = $projector_method->invoke( null, array( 'trigger_payload' => array( 'platform' => 'ZALO_OA', 'code' => 'zalo_oa', 'zone' => 'crm', 'chat_id' => 'zalobot_healthtest_customer' ) ) );
			self::add_step( $steps, $ctx, 'Runtime - progress target stays Zone 2', $zalo_target === 'zalobot_healthtest_direct' && $crm_target === '', 'zalo_bot=' . (string) $zalo_target . ' crm=' . (string) $crm_target );
		} catch ( Throwable $error ) {
			self::add_step( $steps, $ctx, 'Runtime - explicit command and Zone 2 assertions', false, 'Exception class=' . get_class( $error ) );
		} finally {
			self::cleanup_workflows( $ids );
		}

		$failed = false;
		foreach ( $steps as $step ) {
			if ( ( $step['status'] ?? '' ) !== 'pass' ) {
				$failed = true;
				break;
			}
		}
		return array( 'status' => $failed ? 'fail' : 'pass', 'steps' => $steps );
	}

	private static function workflow_input( string $slug, string $zone ): array {
		// [2026-08-16 Johnny Chu] CCG-1 - healthtest rows use the same command and zone contract as production workflows.
		return array(
			'slug'           => $slug,
			'name'           => '__healthtest explicit command ' . $zone,
			'trigger_type'   => 'manual',
			'trigger_config' => array( 'command_invokable' => 1, 'visibility' => 'global', 'zone' => $zone ),
			'graph_json'     => wp_json_encode( array( 'nodes' => array(), 'edges' => array() ) ),
			'enabled'        => 1,
		);
	}

	private static function add_step( array &$steps, $ctx, string $label, bool $passed, string $detail ): void {
		$step = array( 'label' => $label, 'status' => $passed ? 'pass' : 'fail', 'detail' => $detail );
		$steps[] = $step;
		if ( is_object( $ctx ) && method_exists( $ctx, 'emit_step' ) ) {
			$ctx->emit_step( $step );
		}
	}

	public function cleanup(): void {
		// [2026-08-16 Johnny Chu] DIAGNOSTICS-PROBE-CONTRACT-FIX - satisfy the non-static probe cleanup interface; run-scoped workflow cleanup is handled below.
	}

	private static function cleanup_workflows( array $ids ): void {
		// [2026-08-16 Johnny Chu] CCG-1 - never leave temporary command probe workflows in the tenant table.
		foreach ( $ids as $id ) {
			if ( (int) $id > 0 ) {
				BizCity_Automation_Repo_Workflows::hard_delete( (int) $id );
			}
		}
	}
}
