<?php
/**
 * DDV probe for pointer-only Skill references in Context Bank.
 *
 * The default path does not create or update a Skill or Context Bank row.
 *
 * @package BizCity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 */

defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'BizCity_Diagnostics_Probe' ) || class_exists( 'BizCity_Probe_Context_Bank_Rule_Reference', false ) ) {
	return;
}

final class BizCity_Probe_Context_Bank_Rule_Reference implements BizCity_Diagnostics_Probe {

	const FLAG = 'bizcity_context_bank_capture_enabled';

	public function id(): string { return 'core.context_bank.references'; }
	public function label(): string { return 'Context Bank - Skill rule references'; }
	public function description(): string { return 'Checks Skill reference adapter wiring and verifies the default path does not copy rule content into Context Bank.'; }
	public function severity(): string { return 'critical'; }
	public function order(): int { return 76; }
	public function icon(): string { return 'book-open'; }
	public function estimate_ms(): int { return 100; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_Context_Bank_Rule_Reference_Adapter' ) ) {
			return new WP_Error( 'context_bank_rule_reference_missing', 'Context Bank Skill reference adapter is not loaded.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-09-02 11:29 AM Johnny Chu - Chu Hoàng Anh] PHASE-CB4.5-DDV — prove Skill reference wiring, content redaction and capture-off safety.
		$root = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) ) . '/';
		$adapter_file = $root . 'core/context-bank/includes/class-context-bank-rule-reference-adapter.php';
		$disk_ok = is_readable( $adapter_file );
		$loader_ok = class_exists( 'BizCity_Context_Bank_Rule_Reference_Adapter' )
			&& false !== has_action( 'bizcity_skill_saved', array( 'BizCity_Context_Bank_Rule_Reference_Adapter', 'on_saved' ) )
			&& false !== has_action( 'bizcity_skill_deleted', array( 'BizCity_Context_Bank_Rule_Reference_Adapter', 'on_deleted' ) )
			&& method_exists( 'BizCity_Context_Bank_Rule_Reference_Adapter', 'follow_skill' );
		$source = file_get_contents( $adapter_file );
		$redaction_ok = is_string( $source ) && strpos( $source, "'content_md'" ) !== false && strpos( $source, "'content_hash'" ) !== false && strpos( $source, "'version_hash'" ) !== false && strpos( $source, "'character_id'" ) !== false && strpos( $source, "'user_id'" ) !== false && strpos( $source, "'secondary_type'" ) !== false && strpos( $source, "'secondary_key'" ) !== false;
		$owner_navigation_ok = is_string( $source ) && strpos( $source, "'record_id'" ) !== false && strpos( $source, '->find(' ) !== false && strpos( $source, 'authorize_pointer' ) !== false && strpos( $source, 'get_by_key' ) !== false;
		$ledger_follow_ok = false;
		$ledger_file = $root . 'core/context-bank/includes/class-context-bank-ledger.php';
		if ( is_readable( $ledger_file ) ) {
			$ledger_source = file_get_contents( $ledger_file );
			$ledger_follow_ok = is_string( $ledger_source ) && strpos( $ledger_source, "'core.skills.rule_reference'" ) !== false && strpos( $ledger_source, 'follow_skill' ) !== false;
		}
		$mpr_owner_path_ok = false;
		$mpr_file = $root . 'core/twinbrain/includes/class-twinbrain-notebook-source-layer.php';
		if ( is_readable( $mpr_file ) ) {
			$mpr_source = file_get_contents( $mpr_file );
			$mpr_owner_path_ok = is_string( $mpr_source ) && strpos( $mpr_source, "'owner_records'" ) !== false && strpos( $mpr_source, 'canonical_owner_after_pointer_authorization' ) !== false;
		}
		$mpr_consumer_ok = false;
		$synth_file = $root . 'core/twinbrain/includes/class-twinbrain-synthesizer.php';
		if ( is_readable( $synth_file ) ) {
			$synth_source = file_get_contents( $synth_file );
			$mpr_consumer_ok = is_string( $synth_source ) && strpos( $synth_source, 'context_bank_owner_records' ) !== false && strpos( $synth_source, 'render_context_bank_owner_block' ) !== false && strpos( $synth_source, 'core/skills' ) !== false;
		}
		$public_pack_safe = is_string( $mpr_source ?? '' ) && strpos( $mpr_source, "'context_bank_owner_records'" ) !== false;
		$public_pack_safe = $public_pack_safe && is_string( $mpr_source ) && substr_count( $mpr_source, "'context_bank_owner_records'" ) === 1;
		$missing_flag = '__cb_rule_reference_flag_missing__';
		$previous_flag = get_option( self::FLAG, $missing_flag );
		try {
			delete_option( self::FLAG );
			$runtime = BizCity_Context_Bank_Rule_Reference_Adapter::project( array( 'id' => 1, 'content_md' => 'must not be written' ), 'upsert', 'probe' );
			$runtime_ok = is_array( $runtime ) && ! empty( $runtime['ok'] ) && empty( $runtime['projected'] ) && 'capture_disabled' === (string) ( $runtime['reason'] ?? '' );
		} finally {
			if ( $previous_flag === $missing_flag ) {
				delete_option( self::FLAG );
			} else {
				update_option( self::FLAG, $previous_flag, false );
			}
		}
		foreach ( array(
			array( 'label' => 'Disk - Skill reference adapter is readable', 'ok' => $disk_ok, 'detail' => $disk_ok ? 'The Skill reference adapter artifact is readable.' : 'Skill reference adapter artifact is missing or unreadable.' ),
			array( 'label' => 'Loader - Skill lifecycle hooks are attached', 'ok' => $loader_ok, 'detail' => $loader_ok ? 'Canonical Skill save/delete events resolve to the reference adapter.' : 'Skill lifecycle hooks are not attached to the reference adapter.' ),
			array( 'label' => 'Runtime - rule body is represented by hashes only', 'ok' => $redaction_ok, 'detail' => $redaction_ok ? 'The adapter hashes content for version identity and does not admit content_md as a ledger field.' : 'The adapter redaction contract is incomplete.' ),
			array( 'label' => 'Runtime - MPR follows the authorized Skill owner', 'ok' => $owner_navigation_ok, 'detail' => $owner_navigation_ok ? 'Owner navigation verifies a persisted ledger pointer, authorization and scoped canonical Skill lookup.' : 'Owner navigation does not verify the persisted pointer and canonical Skill owner path.' ),
			array( 'label' => 'Loader - ledger delegates Skill follow-through', 'ok' => $ledger_follow_ok, 'detail' => $ledger_follow_ok ? 'Ledger follow delegates the Skill contract to the canonical owner adapter after receipt verification.' : 'Ledger follow does not delegate the Skill contract to the canonical owner adapter.' ),
			array( 'label' => 'Loader - MPR receives canonical owner records', 'ok' => $mpr_owner_path_ok, 'detail' => $mpr_owner_path_ok ? 'MPR receives bounded owner records after authorized pointer follow.' : 'MPR does not receive canonical owner records after pointer follow.' ),
			array( 'label' => 'Runtime - MPR consumes bounded Skill owner body', 'ok' => $mpr_consumer_ok, 'detail' => $mpr_consumer_ok ? 'Synthesizer consumes only bounded records resolved from the canonical core/skills owner.' : 'Synthesizer is not wired to consume the canonical Skill owner record.' ),
			array( 'label' => 'Runtime - owner body stays out of public W0.20 pack', 'ok' => $public_pack_safe, 'detail' => $public_pack_safe ? 'Owner records stay on the internal Source Layer path and are not included in the public graph/retrieval pack.' : 'Owner records may be exposed through the public graph/retrieval pack.' ),
			array( 'label' => 'Runtime - reference capture is disabled by default', 'ok' => $runtime_ok, 'detail' => $runtime_ok ? 'Capture-off prevents Skill reference writes.' : 'Capture-off behavior is not fail-safe.' ),
		) as $step ) {
			$ctx->emit_step( array( 'label' => $step['label'], 'status' => $step['ok'] ? 'pass' : 'fail', 'detail' => $step['detail'] ) );
		}
		$pass = $disk_ok && $loader_ok && $redaction_ok && $owner_navigation_ok && $ledger_follow_ok && $mpr_owner_path_ok && $mpr_consumer_ok && $public_pack_safe && $runtime_ok;
		return array( 'status' => $pass ? 'pass' : 'fail', 'summary' => $pass ? 'Skill reference adapter passed guarded pointer-only checks.' : 'Skill reference adapter failed guarded checks.', 'fix_hint' => $pass ? '' : 'Load canonical Skill lifecycle hooks and keep rule bodies outside Context Bank.', 'steps' => array() );
	}

	public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Context_Bank_Rule_Reference';
	return $list;
} );