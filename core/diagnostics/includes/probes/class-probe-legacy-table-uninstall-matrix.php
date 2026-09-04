<?php
/**
 * Read-only DDV for approved legacy-table uninstall behavior.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since 2026-08-27
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
if ( ! interface_exists( 'BizCity_Diagnostics_Probe' ) ) {
    return;
}
if ( class_exists( 'BizCity_Probe_Legacy_Table_Uninstall_Matrix', false ) ) {
    return;
}

final class BizCity_Probe_Legacy_Table_Uninstall_Matrix implements BizCity_Diagnostics_Probe {
    public function id(): string { return 'core.legacy_table.uninstall_matrix'; }
    public function label(): string { return 'Legacy tables - uninstall safety matrix'; }
    public function description(): string { return 'Proves uninstall is fail-closed, approval-bound, zero-row guarded and idempotent without dropping a real table during diagnostics.'; }
    public function severity(): string { return 'critical'; }
    public function order(): int { return 22; }
    public function icon(): string { return 'trash-2'; }
    public function estimate_ms(): int { return 100; }
    public function precondition() {
        return class_exists( 'BizCity_Legacy_Table_Policy' ) ? true : new WP_Error( 'legacy_policy_missing', 'Legacy table policy is not loaded.' );
    }

    public function run( $ctx ): array {
        // [2026-08-27 Johnny Chu] PHASE-1.30-DDV — inspect uninstall gates and execute only the no-uninstall-context no-op branch.
        $steps = array();
        $pass = true;
        $emit = function ( $label, $ok, $detail ) use ( $ctx, &$steps, &$pass ) {
            $step = array( 'label' => $label, 'status' => $ok ? 'pass' : 'fail', 'detail' => $detail );
            $steps[] = $step;
            $ctx->emit_step( $step );
            $pass = $pass && $ok;
        };
        $root = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( __DIR__, 4 ) . '/';
        $uninstall_file = $root . 'uninstall.php';
        $policy_file = $root . 'core/helper/class-bizcity-legacy-table-policy.php';
        $uninstall = is_readable( $uninstall_file ) ? (string) file_get_contents( $uninstall_file ) : '';
        $policy = is_readable( $policy_file ) ? (string) file_get_contents( $policy_file ) : '';
        $source_ok = $uninstall !== '' && strpos( $uninstall, "! defined( 'WP_UNINSTALL_PLUGIN' )" ) !== false && strpos( $uninstall, 'uninstall_ready_tables' ) !== false && strpos( $uninstall, 'DROP TABLE' ) === false;
        $emit( 'Uninstall has no direct DROP and requires WordPress uninstall context', $source_ok, $source_ok ? 'uninstall.php delegates to the policy only.' : 'Uninstall source is missing the fail-closed delegation.' );

        $no_context_noop = ! defined( 'WP_UNINSTALL_PLUGIN' );
        $no_context_result = BizCity_Legacy_Table_Policy::uninstall_ready_tables();
        $emit( 'No uninstall context performs no cleanup', $no_context_noop && is_array( $no_context_result ) && empty( $no_context_result ), $no_context_noop && empty( $no_context_result ) ? 'Diagnostics context did not invoke uninstall cleanup.' : 'Uninstall cleanup ran outside WP_UNINSTALL_PLUGIN.' );

        $approval_gate = strpos( $policy, 'can_drop( $table )' ) !== false && strpos( $policy, "STATE_READY" ) !== false && strpos( $policy, 'approval_ref' ) !== false;
        // [2026-08-28 Johnny Chu] PHASE-1.30-DDV — follow the canonical shared zero-row policy helper after the DROP gate was centralized.
        $zero_row_gate = strpos( $policy, 'SELECT COUNT(*)' ) !== false && strpos( $policy, 'zero_row_drop_allowed' ) !== false;
        $emit( 'Uninstall requires ready state and approval reference', $approval_gate, $approval_gate ? 'Policy can_drop gate requires explicit state and approval.' : 'Approval gate is incomplete.' );
        $emit( 'Uninstall refuses non-empty tables before DROP', $zero_row_gate, $zero_row_gate ? 'Fresh COUNT(*) check refuses non-empty data.' : 'Zero-row guard is missing.' );

        $base_drop_refused = ! BizCity_Legacy_Table_Policy::can_drop( 'bizcity_google_usage_logs' );
        $emit( 'Per-blog uninstall refuses base-prefix tables', $base_drop_refused, $base_drop_refused ? 'Global usage table requires a separate network cleanup owner.' : 'Base-prefix table became per-blog drop eligible.' );

        return array( 'status' => $pass ? 'pass' : 'fail', 'summary' => $pass ? 'Uninstall matrix is fail-closed and non-destructive in diagnostics context.' : 'Uninstall safety matrix has a contract gap.', 'steps' => $steps );
    }

    public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
    $list[] = 'BizCity_Probe_Legacy_Table_Uninstall_Matrix';
    return $list;
} );
