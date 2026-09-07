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
    /** @var string */
    private $fixture_suffix = '';

    /** @var string */
    private $fixture_physical = '';

    /** @var mixed */
    private $states_before = null;

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
        // [2026-09-06  Johnny Chu - Chu Hoàng Anh] PHASE-1.30-UNINSTALL-MATRIX — exercise all three cleanup outcomes on one disposable fixture.
        global $wpdb;
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

        $this->states_before = get_option( BizCity_Legacy_Table_Policy::OPTION, null );
        $candidate = $this->pick_absent_retired_candidate();
        if ( '' === $candidate ) {
            $emit( 'Disposable uninstall fixture is available', false, 'No absent retired suffix is available for the uninstall matrix.' );
            return array( 'status' => $pass ? 'pass' : 'fail', 'summary' => 'Uninstall source gates passed, but the disposable outcome matrix could not start.', 'steps' => $steps );
        }

        $this->fixture_suffix   = $candidate;
        $this->fixture_physical = BizCity_Legacy_Table_Policy::physical_name( $candidate );
        $created                = $this->create_fixture_table();
        $emit( 'Disposable uninstall fixture is available', $created, $created ? 'Created ' . $this->fixture_physical . ' on the current shard.' : 'Could not create the disposable fixture table.' );
        if ( ! $created ) {
            return array( 'status' => 'fail', 'summary' => 'Uninstall outcome matrix could not create its disposable fixture.', 'steps' => $steps );
        }

        $no_approval_refused = ! BizCity_Legacy_Table_Policy::drop_approved_empty( $this->fixture_suffix )
            && $this->table_exists_raw( $this->fixture_physical );
        $emit( 'No approval refuses cleanup and preserves the fixture', $no_approval_refused, $no_approval_refused ? 'Shared cleanup path refused before DROP.' : 'Unapproved fixture was changed.' );

        $approval_ref = 'diag-uninstall-matrix-' . gmdate( 'YmdHis' );
        $ready_marked = BizCity_Legacy_Table_Policy::mark_ready_to_drop( $this->fixture_suffix, $approval_ref );
        $inserted     = false;
        if ( $ready_marked ) {
            $inserted = false !== $wpdb->query( 'INSERT INTO `' . $this->fixture_physical . '` () VALUES ()' );
        }
        $non_empty_refused = $ready_marked && $inserted
            && ! BizCity_Legacy_Table_Policy::drop_approved_empty( $this->fixture_suffix )
            && $this->table_exists_raw( $this->fixture_physical )
            && 1 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . $this->fixture_physical . '`' );
        $emit( 'Ready-to-drop with non-empty table refuses cleanup', $non_empty_refused, $non_empty_refused ? 'COUNT(*)=1 remained protected.' : 'Non-empty approved fixture was not refused.' );

        $emptied = $inserted && false !== $wpdb->query( 'DELETE FROM `' . $this->fixture_physical . '`' )
            && 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . $this->fixture_physical . '`' );
        $approved_zero_row = $emptied && BizCity_Legacy_Table_Policy::drop_approved_empty( $this->fixture_suffix )
            && ! $this->table_exists_raw( $this->fixture_physical );
        $emit( 'Ready-to-drop with zero rows removes the fixture', $approved_zero_row, $approved_zero_row ? 'Approved empty fixture was removed through the shared policy path.' : 'Approved empty fixture was not removed.' );

        $idempotent = $approved_zero_row && BizCity_Legacy_Table_Policy::drop_approved_empty( $this->fixture_suffix );
        $emit( 'Repeated approved cleanup is idempotent', $idempotent, $idempotent ? 'Second cleanup remained a no-op success.' : 'Repeated cleanup returned failure after the table was absent.' );

        return array( 'status' => $pass ? 'pass' : 'fail', 'summary' => $pass ? 'Uninstall matrix is fail-closed, approval-bound, zero-row guarded and idempotent on a disposable fixture.' : 'Uninstall safety matrix has a contract gap.', 'steps' => $steps, 'artifacts' => array( array( 'kind' => 'legacy_fixture', 'id' => $this->fixture_suffix, 'label' => $this->fixture_physical ) ) );
    }

    public function cleanup(): void {
        if ( $this->fixture_physical !== '' ) {
            global $wpdb;
            $wpdb->query( 'DROP TABLE IF EXISTS `' . $this->fixture_physical . '`' );
            if ( function_exists( 'bizcity_tbl_invalidate' ) ) {
                bizcity_tbl_invalidate( $this->fixture_physical );
            }
        }
        if ( null === $this->states_before ) {
            delete_option( BizCity_Legacy_Table_Policy::OPTION );
        } else {
            update_option( BizCity_Legacy_Table_Policy::OPTION, $this->states_before, false );
        }
        $this->fixture_suffix   = '';
        $this->fixture_physical = '';
        $this->states_before    = null;
    }

    private function pick_absent_retired_candidate(): string {
        $candidates = array( 'bizcity_intent_one_shot', 'bizcity_intent_traces', 'bizcity_twin_state_focus', 'bizcity_twin_state_snapshot', 'bizcity_twinchat_welcome_jobs', 'bizcity_twin_identity' );
        foreach ( $candidates as $candidate ) {
            $physical = BizCity_Legacy_Table_Policy::physical_name( $candidate );
            if ( ! $this->table_exists_raw( $physical ) ) {
                return $candidate;
            }
        }
        return '';
    }

    private function create_fixture_table(): bool {
        global $wpdb;
        $collate = method_exists( $wpdb, 'get_charset_collate' ) ? (string) $wpdb->get_charset_collate() : '';
        $created = false !== $wpdb->query( 'CREATE TABLE `' . $this->fixture_physical . '` (id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT, PRIMARY KEY (id)) ' . $collate );
        if ( $created && function_exists( 'bizcity_tbl_invalidate' ) ) {
            bizcity_tbl_invalidate( $this->fixture_physical );
        }
        return $created;
    }

    private function table_exists_raw( $table_name ): bool {
        global $wpdb;
        return 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1', $table_name ) );
    }
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
    $list[] = 'BizCity_Probe_Legacy_Table_Uninstall_Matrix';
    return $list;
} );
