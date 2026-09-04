<?php
/**
 * DDV probe for Context Bank bounded retrieval scope and source-layer policy.
 *
 * The probe does not enable capture, follow a payload or call a provider.
 *
 * @package BizCity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 */

defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'BizCity_Diagnostics_Probe' ) || class_exists( 'BizCity_Probe_Context_Bank_Retrieval', false ) ) {
	return;
}

final class BizCity_Probe_Context_Bank_Retrieval implements BizCity_Diagnostics_Probe {

	public function id(): string {
		// [2026-09-02 11:29 AM Johnny Chu - Chu Hoàng Anh] PHASE-CB7-DDV — expose the bounded retrieval probe ID.
		return 'core.context_bank.retrieval';
	}

	public function label(): string {
		// [2026-09-02 11:29 AM Johnny Chu - Chu Hoàng Anh] PHASE-CB7-DDV — label the retrieval scope probe.
		return 'Context Bank - bounded retrieval integration';
	}

	public function description(): string {
		// [2026-09-02 11:29 AM Johnny Chu - Chu Hoàng Anh] PHASE-CB7-DDV — describe server-owned mode and budget coverage.
		return 'Checks server-owned vertical/notebook/hybrid scope policy, group denial, bounded budgets and source-layer contract filtering without payload or provider access.';
	}

	public function severity(): string { return 'critical'; }
	public function order(): int { return 74; }
	public function icon(): string { return 'search'; }
	public function estimate_ms(): int { return 100; }

	public function precondition() {
		// [2026-09-02 11:29 AM Johnny Chu - Chu Hoàng Anh] PHASE-CB7-DDV — require the canonical scope resolver before retrieval assertions.
		if ( ! class_exists( 'BizCity_Context_Bank_Scope_Resolver' ) ) {
			return new WP_Error( 'context_bank_retrieval_scope_missing', 'Context Bank scope resolver is not loaded.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-09-02 11:29 AM Johnny Chu - Chu Hoàng Anh] PHASE-CB7-DDV — verify server-owned retrieval modes and bounded source-layer policy without storage side effects.
		$current_user = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$group = BizCity_Context_Bank_Scope_Resolver::resolve( array( 'mode' => 'hybrid', 'channel' => 'twinchat', 'chat_kind' => 'group', 'user_id' => 999999, 'blog_id' => 999999 ) );
		$group_ok = (string) ( $group['effective_mode'] ?? '' ) === 'skip' && (string) ( $group['reason_bucket'] ?? '' ) === 'group_private_scope_denied' && (int) ( $group['owner_user_id'] ?? 0 ) === 0;
		$unknown_vertical = BizCity_Context_Bank_Scope_Resolver::resolve( array( 'mode' => 'vertical', 'channel' => 'twin_gpt', 'vertical_id' => '__not_registered__' ) );
		$unknown_vertical_ok = $current_user > 0
			? (string) ( $unknown_vertical['effective_mode'] ?? '' ) === 'skip' && (string) ( $unknown_vertical['reason_bucket'] ?? '' ) === 'vertical_not_registered'
			: (string) ( $unknown_vertical['effective_mode'] ?? '' ) === 'skip';
		$vertical = BizCity_Context_Bank_Scope_Resolver::resolve( array( 'mode' => 'vertical', 'channel' => 'twin_gpt', 'vertical_id' => 'woo_bizops' ) );
		$vertical_ok = $current_user > 0
			? (string) ( $vertical['effective_mode'] ?? '' ) === 'vertical' && (string) ( $vertical['vertical_id'] ?? '' ) === 'woo_bizops' && ! empty( $vertical['policy_contracts'] )
			: (string) ( $vertical['effective_mode'] ?? '' ) === 'skip';
		$hybrid = BizCity_Context_Bank_Scope_Resolver::resolve( array( 'mode' => 'hybrid', 'channel' => 'twin_gpt', 'vertical_id' => 'woo_bizops' ) );
		$hybrid_ok = $current_user > 0
			? (string) ( $hybrid['effective_mode'] ?? '' ) === 'hybrid' && count( (array) ( $hybrid['policy_contracts'] ?? array() ) ) > 0
			: (string) ( $hybrid['effective_mode'] ?? '' ) === 'skip';
		$budget_ok = (int) ( $vertical['budgets']['max_rows'] ?? 0 ) === 50
			&& (int) ( $vertical['budgets']['max_pointer_follows'] ?? 0 ) === 10
			&& (int) ( $vertical['budgets']['max_decrypted_bytes'] ?? 0 ) === 262144
			&& (int) ( $vertical['budgets']['max_time_ms'] ?? 0 ) === 250;
		$source_file = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR . 'core/twinbrain/includes/class-twinbrain-notebook-source-layer.php' : dirname( dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) ) . '/core/twinbrain/includes/class-twinbrain-notebook-source-layer.php';
		$source = is_readable( $source_file ) ? file_get_contents( $source_file ) : '';
		$source_policy_ok = is_string( $source )
			&& strpos( $source, "'source_contract_ids'" ) !== false
			&& strpos( $source, 'policy_contracts' ) !== false
			&& strpos( $source, 'seen_provenance' ) !== false
			&& strpos( $source, "'vertical_id'" ) !== false
			&& strpos( $source, "'notebook_id'" ) !== false
			&& strpos( $source, 'w020_collect_context_bank_candidates' ) !== false
			&& strpos( $source, "'context_bank_owner_excerpt'" ) !== false;
		$owner_candidates_ok = false;
		if ( class_exists( 'BizCity_TwinBrain_Notebook_Source_Layer' ) ) {
			// [2026-09-02 11:29 AM Johnny Chu - Chu Hoàng Anh] PHASE-CB7 — prove protected owner bodies require explicit retrieval-safe permission.
			$layer = BizCity_TwinBrain_Notebook_Source_Layer::instance();
			$method = new ReflectionMethod( $layer, 'w020_collect_context_bank_candidates' );
			$method->setAccessible( true );
			$candidates = $method->invoke( $layer, array(
				array( 'owner' => 'pointer_only', 'record_id' => 'pointer-only', 'record' => array() ),
				array( 'owner' => 'core/skills', 'source_contract_id' => 'core.skills.rule_reference', 'record_id' => 'skill_1_v1', 'record' => array( 'skill_key' => 'skill.one', 'content_md' => 'Protected owner body', 'provenance_ref' => 'skill:1:v1' ) ),
				array( 'owner' => 'core/skills', 'retrieval_safe' => true, 'source_contract_id' => 'core.skills.rule_reference', 'record_id' => 'safe_1_v1', 'record' => array( 'skill_key' => 'skill.one', 'public_excerpt' => 'Bounded owner excerpt', 'provenance_ref' => 'skill:safe:v1' ) ),
				array( 'owner' => 'core/skills', 'retrieval_safe' => true, 'source_contract_id' => 'core.skills.rule_reference', 'record_id' => 'safe_1_v1', 'record' => array( 'skill_key' => 'skill.one', 'public_excerpt' => 'Duplicate owner excerpt', 'provenance_ref' => 'skill:safe:v1' ) ),
			), 30 );
			$owner_candidates_ok = count( $candidates ) === 1
				&& (string) ( $candidates[0]['source'] ?? '' ) === 'context_bank_owner'
				&& (string) ( $candidates[0]['evidence_type'] ?? '' ) === 'context_bank_owner_excerpt'
				&& empty( $candidates[0]['citation'] )
				&& strpos( (string) ( $candidates[0]['excerpt'] ?? '' ), 'Bounded owner excerpt' ) !== false;
		}
		$checks = array(
			array( 'label' => 'Group private scope denied', 'ok' => $group_ok, 'detail' => $group_ok ? 'Group retrieval resolves to skip with no personal owner.' : 'Group retrieval can inherit private owner scope.' ),
			array( 'label' => 'Unknown vertical denied', 'ok' => $unknown_vertical_ok, 'detail' => $unknown_vertical_ok ? 'Unknown vertical does not expand retrieval scope.' : 'Unknown vertical was accepted.' ),
			array( 'label' => 'Vertical mode is server-owned', 'ok' => $vertical_ok, 'detail' => $vertical_ok ? 'Registered vertical policy resolves from the canonical bridge registry.' : 'Vertical mode is not resolved through the canonical owner.' ),
			array( 'label' => 'Hybrid mode is server-owned', 'ok' => $hybrid_ok, 'detail' => $hybrid_ok ? 'Hybrid mode retains server-owned policy contracts and mode metadata.' : 'Hybrid mode is not resolved through the canonical owner.' ),
			array( 'label' => 'Retrieval budgets bounded', 'ok' => $budget_ok, 'detail' => $budget_ok ? 'Rows, follows, decrypted bytes and elapsed time are capped.' : 'Retrieval budget contract is incomplete.' ),
			array( 'label' => 'Source layer filters before pointer follow', 'ok' => $source_policy_ok, 'detail' => $source_policy_ok ? 'Mode policy contracts are passed to typed search and provenance dedupe remains bounded.' : 'Source layer does not expose the typed policy filter boundary.' ),
			array( 'label' => 'Owner excerpts blend without pointer leakage', 'ok' => $owner_candidates_ok, 'detail' => $owner_candidates_ok ? 'Pointer-only rows are excluded; one verified owner excerpt is deduplicated into the existing W0.20 candidate shape.' : 'Context Bank owner excerpt adapter is missing, leaks pointer-only metadata or duplicates provenance.' ),
		);
		$pass = true;
		foreach ( $checks as $check ) {
			$ctx->emit_step( array( 'label' => $check['label'], 'status' => $check['ok'] ? 'pass' : 'fail', 'detail' => $check['detail'] ) );
			$pass = $pass && $check['ok'];
		}
		return array( 'status' => $pass ? 'pass' : 'fail', 'summary' => $pass ? 'Bounded Context Bank retrieval scope passed mode, group, budget and source-layer checks.' : 'Bounded Context Bank retrieval scope failed.', 'fix_hint' => $pass ? '' : 'Resolve scope through canonical owners and filter contracts before pointer follow.', 'steps' => array() );
	}

	public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Context_Bank_Retrieval';
	return $list;
} );
