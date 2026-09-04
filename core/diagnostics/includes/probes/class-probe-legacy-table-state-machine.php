<?php
/**
 * Read-only DDV for the legacy table state machine and operation gates.
 *
 * @package Bizcity_Twin_AI
    // [2026-08-27 Johnny Chu] HOTFIX — keep the cleanup method on a valid PHP class boundary.
 * @subpackage Core\Diagnostics\Probes
 * @since 2026-08-27
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! interface_exists( 'BizCity_Diagnostics_Probe' ) ) {
    return;
}
if ( class_exists( 'BizCity_Probe_Legacy_Table_State_Machine', false ) ) {
    return;
}

final class BizCity_Probe_Legacy_Table_State_Machine implements BizCity_Diagnostics_Probe {
    public function id(): string { return 'core.legacy_table.state_machine'; }
    public function label(): string { return 'Legacy tables - quarantine/draining state machine'; }
    public function description(): string { return 'Proves the quarantine to draining to ready_to_drop contract and operation-specific SQL gates without changing policy state.'; }
    public function severity(): string { return 'critical'; }
    public function order(): int { return 21; }
    public function icon(): string { return 'git-branch'; }
    public function estimate_ms(): int { return 100; }
    public function precondition() {
        return class_exists( 'BizCity_Legacy_Table_Policy' ) ? true : new WP_Error( 'legacy_policy_missing', 'Legacy table policy is not loaded.' );
    }

    public function run( $ctx ): array {
        // [2026-08-27 Johnny Chu] PHASE-1.30-DDV — prove state and operation gates without mutating an existing policy record.
        $steps = array();
        $pass = true;
        $emit = function ( $label, $status, $detail ) use ( $ctx, &$steps, &$pass ) {
            $step = array( 'label' => $label, 'status' => $status, 'detail' => $detail );
            $steps[] = $step;
            $ctx->emit_step( $step );
            $pass = $pass && $status !== 'fail';
        };
        $retired = 'bizcity_intent_logs';
        $active = 'bizcity_facebook_bot_logs';
        $operations = array( 'create', 'read', 'insert', 'update', 'delete', 'drop' );
        $retired_matrix = array();
        foreach ( $operations as $operation ) {
            $retired_matrix[ $operation ] = ! BizCity_Legacy_Table_Policy::allow_sql( $retired, $operation );
        }
        $retired_ok = ! in_array( false, $retired_matrix, true ) && BizCity_Legacy_Table_Policy::install_blocked( $retired );
        $emit( 'Retired table blocks every SQL operation', $retired_ok ? 'pass' : 'fail', $retired_ok ? wp_json_encode( $retired_matrix ) : 'At least one retired operation is still allowed.' );

        $writer_stop_tables = BizCity_Legacy_Table_Policy::writer_stop_tables();
        $active = ! empty( $writer_stop_tables ) ? (string) reset( $writer_stop_tables ) : 'bizcity_kg_usage_log';
        $active_state = BizCity_Legacy_Table_Policy::get_state( $active );
        $active_matrix = array();
        foreach ( $operations as $operation ) {
            $active_matrix[ $operation ] = BizCity_Legacy_Table_Policy::allow_sql( $active, $operation );
        }
        // [2026-09-02 Johnny Chu] PHASE-1.30-STATE-MACHINE — retired SQL cohorts are fail-closed; do not recreate a draining read fallback merely to satisfy this probe.
        $active_default_ok = ! empty( $writer_stop_tables )
            ? ( $active_state === BizCity_Legacy_Table_Policy::STATE_DRAINING
                && $active_matrix['read']
                && ! $active_matrix['create']
                && ! $active_matrix['insert']
                && ! $active_matrix['update']
                && ! $active_matrix['delete']
                && ! $active_matrix['drop'] )
            : ( $active_state === BizCity_Legacy_Table_Policy::STATE_QUARANTINE && ! in_array( false, $active_matrix, true ) );
        $active_detail = ! empty( $writer_stop_tables )
            ? ( $active_state === BizCity_Legacy_Table_Policy::STATE_DRAINING ? wp_json_encode( $active_matrix ) : 'Expected the writer-stop cohort to resolve to draining.' )
            : ( $active_default_ok ? 'No active draining writer-stop cohort; quarantine-only SQL remains available until its owner cutover.' : 'Quarantine-only lifecycle fixture did not resolve to quarantine.' );
        $emit( ! empty( $writer_stop_tables ) ? 'Writer-stop cohort has explicit draining matrix' : 'No active writer-stop cohort remains after SQL retirement', $active_default_ok ? 'pass' : 'fail', $active_detail );

        $draining_source_ok = false;
        $policy_file = dirname( __DIR__, 3 ) . '/helper/class-bizcity-legacy-table-policy.php';
        if ( is_readable( $policy_file ) ) {
            $source = (string) file_get_contents( $policy_file );
            $draining_source_ok = strpos( $source, 'STATE_DRAINING' ) !== false
                && strpos( $source, "'insert', 'update', 'delete'" ) !== false
                && strpos( $source, 'mark_draining' ) !== false
                && strpos( $source, 'mark_ready_to_drop' ) !== false;
        }
        $emit( 'Draining blocks writes while preserving read/export', $draining_source_ok ? 'pass' : 'fail', $draining_source_ok ? 'Policy source contains the explicit draining transition and write deny list.' : 'State transition or operation deny list is incomplete.' );

        $invalid_transition_ok = ! BizCity_Legacy_Table_Policy::mark_draining( $retired, '' ) && ! BizCity_Legacy_Table_Policy::mark_ready_to_drop( $active, '' ) && ! BizCity_Legacy_Table_Policy::can_drop( $retired );
        $emit( 'Invalid or unapproved transitions are refused', $invalid_transition_ok ? 'pass' : 'fail', $invalid_transition_ok ? 'Empty evidence cannot mutate state and unapproved DROP is refused.' : 'An invalid transition was accepted.' );

        return array(
            'status' => $pass ? 'pass' : 'fail',
            'summary' => $pass ? 'Legacy quarantine/draining state machine and operation gates are explicit.' : 'Legacy state machine has an operation or transition gap.',
            'steps' => $steps,
            'matrices' => array( 'retired' => $retired_matrix, 'active' => $active_matrix ),
        );
    }
        public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
    $list[] = 'BizCity_Probe_Legacy_Table_State_Machine';
    return $list;
} );
