<?php
/**
 * Owner/parity gate for every active quarantine table family.
 *
 * A missing owner-specific parity probe is reported as a failure so a table
 * cannot be advanced to draining or ready_to_drop by catalog metadata alone.
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
if ( class_exists( 'BizCity_Probe_Legacy_Table_Owner_Parity', false ) ) {
    return;
}

final class BizCity_Probe_Legacy_Table_Owner_Parity implements BizCity_Diagnostics_Probe {
    public function id(): string { return 'core.legacy_table.owner_parity'; }
    public function label(): string { return 'Legacy tables - quarantine owner parity'; }
    public function description(): string { return 'Requires an owner-specific replacement/parity probe for every active or retired legacy table before draining or DROP.'; }
    public function severity(): string { return 'critical'; }
    public function order(): int { return 24; }
    public function icon(): string { return 'link-2'; }
    public function estimate_ms(): int { return 150; }
    public function precondition() {
        return class_exists( 'BizCity_Diagnostics_Table_Registry' ) ? true : new WP_Error( 'table_registry_missing', 'Diagnostics table registry is not loaded.' );
    }

    public function run( $ctx ): array {
        // [2026-08-27 Johnny Chu] PHASE-1.30-DDV — require explicit owner evidence for every quarantine-only catalog row.
        $steps = array();
        $missing = array();
        $checked = 0;
        $root = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( __DIR__, 4 ) . '/';
        // [2026-08-29 Johnny Chu] PHASE-1.30-DDV — owner parity map uses focused memory, log, WebChat, and CRUD-stop evidence.
        // [2026-09-01 Johnny Chu] PHASE-1.30-ZALO-MEMORY-REMOVE — the deleted Zalo memory owner is retire-only, so it is excluded from quarantine parity maps.
        $probe_map = array(
            'bizcity_intent_logs' => 'class-probe-legacy-jsonl-source-parity.php',
            'bizcity_intent_prompt_logs' => 'class-probe-legacy-jsonl-source-parity.php',
            'bizcity_memory_logs' => 'class-probe-legacy-jsonl-source-parity.php',
            'bizcity_mcp_audit_log' => 'class-probe-legacy-jsonl-source-parity.php',
            'bizcity_kg_source_progress_log' => 'class-probe-kg-source-progress-parity.php',
            'bizcity_kg_usage_log' => 'class-probe-legacy-table-crud-stop.php',
            'bizcity_llm_usage_clients' => 'class-probe-legacy-table-crud-stop.php',
            'bizcity_automation_logs' => 'class-probe-legacy-table-crud-stop.php',
            'bizcity_facebook_bot_logs' => 'class-probe-channel-log-ownership.php',
            'bizcity_zalo_bot_logs' => 'class-probe-channel-log-ownership.php',
            'bizcity_google_usage_logs' => 'class-probe-channel-log-ownership.php',
            'bizcity_kg_cleanup_log' => 'class-probe-legacy-table-crud-stop.php',
            'bizcity_twin_context_logs' => 'class-probe-legacy-table-crud-stop.php',
            'bizcity_skill_logs' => 'class-probe-legacy-table-crud-stop.php',
            'bizcity_memory_users' => 'class-probe-memory-filestore-parity.php',
            'bizcity_memory_episodic' => 'class-probe-memory-intent-filestore-parity.php',
            'bizcity_memory_rolling' => 'class-probe-memory-intent-filestore-parity.php',
            'bizcity_memory_session' => 'class-probe-memory-filestore-parity.php',
            'bizcity_memory_notes' => 'class-probe-memory-notes-filestore-parity.php',
            'bizcity_cg_flows' => 'class-probe-legacy-table-crud-stop.php',
            'bizcity_webchat_projects' => 'class-probe-legacy-table-crud-stop.php',
            'bizcity_webchat_tasks' => 'class-probe-legacy-table-crud-stop.php',
            'bizcity_webchat_task_steps' => 'class-probe-legacy-table-crud-stop.php',
            'bizcity_kg_mentions' => 'class-probe-legacy-table-callers.php',
            'bizcity_webchat_tools' => 'class-probe-webchat-tool-registry-parity.php',
            'bizcity_llm_usage' => 'class-probe-llm-usage-filestore-parity.php',
        );
        // [2026-08-29 Johnny Chu] PHASE-1.30-DDV — replacement markers identify the canonical owner contract, not legacy probe assumptions.
        $replacement_markers = array(
            'bizcity_intent_logs' => array( 'core.legacy_table.jsonl_source_parity' ),
            'bizcity_intent_prompt_logs' => array( 'core.legacy_table.jsonl_source_parity' ),
            'bizcity_memory_logs' => array( 'core.legacy_table.jsonl_source_parity' ),
            'bizcity_mcp_audit_log' => array( 'core.legacy_table.jsonl_source_parity' ),
            'bizcity_kg_source_progress_log' => array( 'core.knowledge.kg_source_progress_parity' ),
            'bizcity_llm_usage_clients' => array( 'core.legacy_table.crud_stop', 'runtime_mutations_zero', 'static_writer_refs' ),
            'bizcity_automation_logs' => array( 'core.legacy_table.crud_stop', 'runtime_mutations_zero', 'static_writer_refs' ),
            'bizcity_facebook_bot_logs' => array( 'core.legacy_table.channel_log_ownership' ),
            'bizcity_zalo_bot_logs' => array( 'core.legacy_table.channel_log_ownership' ),
            'bizcity_google_usage_logs' => array( 'core.legacy_table.channel_log_ownership' ),
            'bizcity_kg_cleanup_log' => array( 'core.legacy_table.crud_stop', 'runtime_mutations_zero', 'static_writer_refs' ),
            'bizcity_kg_usage_log' => array( 'core.legacy_table.crud_stop', 'runtime_mutations_zero', 'static_writer_refs' ),
            'bizcity_twin_context_logs' => array( 'core.legacy_table.crud_stop', 'runtime_mutations_zero', 'static_writer_refs' ),
            'bizcity_skill_logs' => array( 'core.legacy_table.crud_stop', 'runtime_mutations_zero', 'static_writer_refs' ),
            'bizcity_memory_users' => array( 'core.memory.filestore_parity' ),
            'bizcity_memory_episodic' => array( 'core.memory.intent_filestore_parity' ),
            'bizcity_memory_rolling' => array( 'core.memory.intent_filestore_parity' ),
            'bizcity_memory_session' => array( 'core.memory.filestore_parity' ),
            'bizcity_memory_notes' => array( 'core.memory.notes_filestore_parity' ),
            'bizcity_cg_flows' => array( 'core.legacy_table.crud_stop', 'runtime_mutations_zero', 'static_writer_refs' ),
            'bizcity_webchat_projects' => array( 'core.legacy_table.crud_stop', 'reader_zero', 'runtime_mutations_zero' ),
            'bizcity_webchat_tasks' => array( 'core.legacy_table.crud_stop', 'fallback_blocked', 'runtime_mutations_zero' ),
            'bizcity_webchat_task_steps' => array( 'core.legacy_table.crud_stop', 'fallback_blocked', 'runtime_mutations_zero' ),
            'bizcity_kg_mentions' => array( 'core.legacy_table.callers' ),
            'bizcity_webchat_tools' => array( 'core.webchat.tool_registry_parity' ),
            'bizcity_llm_usage' => array( 'core.bizcity_llm.usage_filestore_parity' ),
        );
		// [2026-09-01 Johnny Chu] PHASE-1.30-DEAD-SQL-COHORT — retired tables need owner evidence even after quarantine_only is removed from catalog rows.
		// [2026-09-01 Johnny Chu] R-LLM-USAGE-FILESTORE — include the retired client usage projection in owner-parity coverage.
        $retired_cohort = array( 'bizcity_webchat_projects', 'bizcity_webchat_tasks', 'bizcity_webchat_task_steps', 'bizcity_cg_flows', 'bizcity_automation_logs', 'bizcity_kg_cleanup_log', 'bizcity_twin_context_logs', 'bizcity_skill_logs', 'bizcity_facebook_bot_logs', 'bizcity_zalo_bot_logs', 'bizcity_google_usage_logs', 'bizcity_llm_usage' );
        foreach ( BizCity_Diagnostics_Table_Registry::deprecated_tables() as $row ) {
            if ( ! is_array( $row ) || empty( $row['name'] ) || ( empty( $row['quarantine_only'] ) && ! in_array( (string) $row['name'], $retired_cohort, true ) ) ) {
                continue;
            }
            $name = (string) $row['name'];
            $checked++;
            $file = isset( $probe_map[ $name ] ) ? $probe_map[ $name ] : '';
            $probe_path = $root . 'core/diagnostics/includes/probes/' . $file;
            $probe_source = $file !== '' && is_readable( $probe_path ) ? (string) file_get_contents( $probe_path ) : '';
            $markers = isset( $replacement_markers[ $name ] ) ? $replacement_markers[ $name ] : array( $name );
            $marker_found = false;
            foreach ( $markers as $marker ) {
                if ( $marker !== '' && stripos( $probe_source, $marker ) !== false ) {
                    $marker_found = true;
                    break;
                }
            }
            // [2026-08-27 Johnny Chu] PHASE-1.30-DDV — an unrelated probe file is not parity evidence.
            $exists = $probe_source !== '' && $marker_found;
            $status = $exists ? 'pass' : 'fail';
            $detail = $exists ? 'Owner/parity probe artifact: ' . $file . ' with replacement marker.' : 'Missing owner-specific parity evidence or replacement marker; table cannot advance beyond quarantine.';
            $step = array( 'label' => 'Owner parity: ' . $name, 'status' => $status, 'detail' => $detail );
            $steps[] = $step;
            $ctx->emit_step( $step );
            if ( ! $exists ) {
                $missing[] = $name;
            }
        }
        return array( 'status' => empty( $missing ) && $checked > 0 ? 'pass' : 'fail', 'summary' => empty( $missing ) ? $checked . ' legacy rows have an owner/parity probe artifact.' : count( $missing ) . ' legacy rows still lack owner-specific parity evidence.', 'error' => implode( ', ', $missing ), 'steps' => $steps, 'checked' => $checked, 'missing' => $missing );
    }

    public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
    $list[] = 'BizCity_Probe_Legacy_Table_Owner_Parity';
    return $list;
} );
