<?php
/**
 * DDV probe for the canonical Scheduler notification target resolver.
 *
 * Read-only: exercises precedence with in-memory fixtures and verifies both
 * Scheduler completion and TwinBrain progress projection consume the helper.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since 2026-08-16
 */

defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false ) ) {
	require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';
}
if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false ) ) {
	return;
}
if ( class_exists( 'BizCity_Probe_SchTargetV2_20260816', false ) ) {
	return;
}

final class BizCity_Probe_SchTargetV2_20260816 implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'scheduler.target_resolver'; }
	public function label(): string { return 'Scheduler - Canonical Target Resolver'; }
	public function description(): string { return 'Checks shared target precedence and fail-closed projection wiring without DB writes or outbound delivery.'; }
	public function severity(): string { return 'warning'; }
	public function order(): int { return 66; }
	public function icon(): string { return 'plug'; }
	public function estimate_ms(): int { return 20; }

	public function precondition() {
		return class_exists( 'BizCity_Scheduler_Notify_Target_Resolver' )
			? true
			: new WP_Error( 'class_missing', 'BizCity_Scheduler_Notify_Target_Resolver chua load.' );
	}

	public function run( $ctx ): array {
		// [2026-08-16 Johnny Chu] R-SCH-TARGET — deterministic precedence and projection-consumer DDV.
		$steps = array();
		$ok = true;
		$explicit = BizCity_Scheduler_Notify_Target_Resolver::resolve(
			array( 'user_id' => 0 ),
			array(
				'notify' => array( 'target' => array( 'platform' => 'zalo_bot', 'chat_id' => 'zalobot_explicit' ) ),
				'inbound' => array( 'platform' => 'zalo_bot', 'chat_id' => 'zalobot_inbound' ),
			)
		);
		$explicit_ok = is_array( $explicit )
			&& (string) $explicit['platform'] === 'zalo_bot'
			&& (string) $explicit['chat_id'] === 'zalobot_explicit';
		$ok = $this->step( $ctx, $steps, 'Runtime: explicit target wins over inbound', $explicit_ok, wp_json_encode( $explicit ) ) && $ok;

		$inbound = BizCity_Scheduler_Notify_Target_Resolver::resolve(
			array( 'user_id' => 0 ),
			array( 'inbound' => array( 'platform' => 'zalo_bot', 'chat_id' => 'zalobot_inbound' ) )
		);
		$inbound_ok = is_array( $inbound ) && (string) $inbound['chat_id'] === 'zalobot_inbound';
		$ok = $this->step( $ctx, $steps, 'Runtime: inbound target resolves when no override exists', $inbound_ok, wp_json_encode( $inbound ) ) && $ok;

		$disabled = BizCity_Scheduler_Notify_Target_Resolver::resolve(
			array( 'user_id' => 0 ),
			array( 'notify' => false )
		);
		$disabled_ok = null === $disabled;
		$ok = $this->step( $ctx, $steps, 'Runtime: no target remains fail-closed', $disabled_ok, wp_json_encode( $disabled ) ) && $ok;

		$plugin_root = defined( 'BIZCITY_TWIN_AI_DIR' )
			? rtrim( (string) BIZCITY_TWIN_AI_DIR, '/\\' ) . '/'
			: dirname( dirname( dirname( dirname( __DIR__ ) ) ) ) . '/';
		$scheduler_file = $plugin_root . 'core/scheduler/includes/class-scheduler-completion-notifier.php';
		$projector_file = $plugin_root . 'core/twinbrain/includes/class-twinbrain-progress-notice-projector.php';
		$scheduler_source = is_readable( $scheduler_file ) ? (string) file_get_contents( $scheduler_file ) : '';
		$projector_source = is_readable( $projector_file ) ? (string) file_get_contents( $projector_file ) : '';
		$consumer_ok = strpos( $scheduler_source, 'BizCity_Scheduler_Notify_Target_Resolver::resolve' ) !== false
			&& strpos( $projector_source, 'BizCity_Scheduler_Notify_Target_Resolver::resolve' ) !== false
			&& strpos( $projector_source, "'zalobot_'" ) !== false;
		$ok = $this->step( $ctx, $steps, 'Disk: Scheduler and Projector share resolver', $consumer_ok, $consumer_ok ? 'Both consumers delegate before Zone 2 filtering.' : 'A consumer still contains an independent target resolver.' ) && $ok;

		return array(
			'status' => $ok ? 'pass' : 'fail',
			'summary' => $ok ? 'Canonical target precedence is shared and fail-closed.' : 'Canonical target resolver contract is incomplete.',
			'steps' => $steps,
		);
	}

	private function step( $ctx, array &$steps, string $label, bool $passed, string $detail ): bool {
		$row = array( 'label' => $label, 'status' => $passed ? 'pass' : 'fail', 'detail' => $detail );
		$steps[] = $row;
		$ctx->emit_step( $row );
		return $passed;
	}

	public function cleanup(): void {
		// [2026-08-16 Johnny Chu] R-DDV — satisfy the Diagnostics probe lifecycle; this read-only probe creates no artifacts.
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_SchTargetV2_20260816';
	return $list;
} );
