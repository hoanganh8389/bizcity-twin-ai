<?php
/**
 * Read-only DDV for legacy-table multisite and shard cleanup scope.
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
if ( class_exists( 'BizCity_Probe_Legacy_Table_Multisite_Scope', false ) ) {
    return;
}

final class BizCity_Probe_Legacy_Table_Multisite_Scope implements BizCity_Diagnostics_Probe {
    public function id(): string { return 'core.legacy_table.multisite_scope'; }
    public function label(): string { return 'Legacy tables - multisite/shard cleanup scope'; }
    public function description(): string { return 'Proves per-blog state, prefix resolution, base-prefix refusal and restore/fail-closed cleanup markers.'; }
    public function severity(): string { return 'critical'; }
    public function order(): int { return 23; }
    public function icon(): string { return 'network'; }
    public function estimate_ms(): int { return 100; }
    public function precondition() {
        return class_exists( 'BizCity_Legacy_Table_Policy' ) ? true : new WP_Error( 'legacy_policy_missing', 'Legacy table policy is not loaded.' );
    }

    public function run( $ctx ): array {
        // [2026-08-27 Johnny Chu] PHASE-1.30-DDV — verify scope contracts without switching blogs or issuing cleanup SQL.
        $steps = array();
        $pass = true;
        $emit = function ( $label, $ok, $detail ) use ( $ctx, &$steps, &$pass ) {
            $step = array( 'label' => $label, 'status' => $ok ? 'pass' : 'fail', 'detail' => $detail );
            $steps[] = $step;
            $ctx->emit_step( $step );
            $pass = $pass && $ok;
        };
        global $wpdb;
        $policy_file = ( defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( __DIR__, 4 ) . '/' ) . 'core/helper/class-bizcity-legacy-table-policy.php';
        $source = is_readable( $policy_file ) ? (string) file_get_contents( $policy_file ) : '';
        $scope_source_ok = strpos( $source, 'get_current_blog_id' ) !== false && strpos( $source, 'self::$states[ $blog_id ]' ) !== false && strpos( $source, 'base_prefix' ) !== false;
        $emit( 'Policy state is dimensioned by current blog', $scope_source_ok, $scope_source_ok ? 'State and physical-name logic contain blog/base-prefix scope markers.' : 'Blog or base-prefix scope marker is missing.' );

        $base_name_ok = isset( $wpdb->base_prefix ) && BizCity_Legacy_Table_Policy::physical_name( 'bizcity_google_usage_logs' ) === $wpdb->base_prefix . 'bizcity_google_usage_logs';
        $blog_name_ok = isset( $wpdb->prefix ) && BizCity_Legacy_Table_Policy::physical_name( 'bizcity_automation_logs' ) === $wpdb->prefix . 'bizcity_automation_logs';
        $emit( 'Physical table names resolve to the correct scope', $base_name_ok && $blog_name_ok, $base_name_ok && $blog_name_ok ? 'Base-prefix and blog-prefix names resolve correctly.' : 'Physical table scope does not match the catalog.' );

        $base_drop_refused = ! BizCity_Legacy_Table_Policy::can_drop( 'bizcity_google_usage_logs' );
        $emit( 'Global/base-prefix cleanup is refused by per-blog path', $base_drop_refused, $base_drop_refused ? 'Network owner is required for base-prefix cleanup.' : 'Per-blog path can drop a global table.' );

        $kg_file = ( defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( __DIR__, 4 ) . '/' ) . 'core/knowledge/kg-hub/includes/class-kg-source-progress-log.php';
        $kg_source = is_readable( $kg_file ) ? (string) file_get_contents( $kg_file ) : '';
        $restore_ok = strpos( $kg_source, 'switch_to_blog' ) !== false && strpos( $kg_source, 'finally' ) !== false && strpos( $kg_source, 'restore_current_blog' ) !== false;
        $emit( 'Legacy migration restores the original blog in finally', $restore_ok, $restore_ok ? 'KG cleanup has switch/restore exception safety.' : 'Switch/restore finally contract is missing.' );

        return array( 'status' => $pass ? 'pass' : 'fail', 'summary' => $pass ? 'Legacy cleanup scope is explicit for blog and base-prefix tables.' : 'Legacy cleanup scope has a multisite/shard gap.', 'steps' => $steps );
    }

    public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
    $list[] = 'BizCity_Probe_Legacy_Table_Multisite_Scope';
    return $list;
} );
