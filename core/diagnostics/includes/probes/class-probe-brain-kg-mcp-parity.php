<?php
/**
 * D4 KG/MCP parity probe.
 *
 * PHASE-0.41D §4.3. Twin GPT and MCP must consume one Brain retrieval facade;
 * MCP must not read Context Bank ledger/archive/CRM/Woo directly.
 *
 * The probe uses no provider and does not enable the three new MCP tools. Their
 * default policy remains OFF until this parity evidence and the production
 * canary pass.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since 2026-09-16 (PHASE-0.41D-CLOSURE / D4)
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BizCity_Safe_Loader', false ) ) {
	$_bizcity_safe_loader = dirname( __DIR__, 4 ) . '/core/helper/class-bizcity-safe-loader.php';
	if ( is_file( $_bizcity_safe_loader ) && is_readable( $_bizcity_safe_loader ) ) {
		require_once $_bizcity_safe_loader;
	}
	unset( $_bizcity_safe_loader );
}
if ( ! class_exists( 'BizCity_Safe_Loader' ) ) {
	return;
}
if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false )
	&& ! BizCity_Safe_Loader::require_file( dirname( __DIR__ ) . '/interface-diagnostics-probe.php', 'diagnostics.probe_interface' ) ) {
	return;
}
if ( class_exists( 'BizCity_Probe_Brain_KG_MCP_Parity', false ) ) {
	return;
}

final class BizCity_Probe_Brain_KG_MCP_Parity implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'core.brain.kg_mcp_parity'; }
	public function label(): string { return 'One Brain KG/MCP parity'; }
	public function description(): string { return 'Kiểm tra Twin GPT/MCP dùng cùng retrieval facade, cùng pack evidence, redaction, scope denial và KG boundary.'; }
	public function severity(): string { return 'critical'; }
	public function order(): int { return 80; }
	public function icon(): string { return 'git-compare-arrows'; }
	public function estimate_ms(): int { return 450; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_Brain_Retrieval_Facade' ) ) {
			return 'Brain retrieval facade is not loaded.';
		}
		if ( ! class_exists( 'BizCity_MCP_Tool_Registry' ) || ! class_exists( 'BizCity_Brain_MCP_Service' ) ) {
			return 'MCP registry or Brain MCP service is not loaded.';
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-0.41D-D4 — parity must be observable at the pack identity/evidence boundary, not inferred from similar UI output.
		$steps = array();
		$root  = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) ) . '/';
		$facade_file = $root . 'core/twinbrain/includes/class-brain-retrieval-facade.php';
		$registry_file = $root . 'core/mcp/includes/class-mcp-tool-registry.php';
		$service_file = $root . 'core/mcp/includes/class-brain-mcp-service.php';
		$policy_file = $root . 'core/mcp/includes/class-mcp-tool-policy.php';

		// Disk/loader layer.
		$disk_ok = is_readable( $facade_file ) && is_readable( $registry_file ) && is_readable( $service_file ) && is_readable( $policy_file );
		$this->emit( $ctx, $steps, 'Disk - facade, MCP registry/service and policy are readable', $disk_ok, $disk_ok ? 'D4 artifacts are present.' : 'A D4 artifact is missing.' );
		if ( ! $disk_ok ) {
			return array( 'status' => 'fail', 'summary' => 'D4 artifacts are incomplete.', 'error' => 'kg_mcp_parity_artifact_missing', 'fix_hint' => 'Restore the shared facade, MCP registry/service and policy owner.', 'steps' => $steps );
		}
		$loader_ok = class_exists( 'BizCity_Brain_Retrieval_Facade', false )
			&& method_exists( 'BizCity_Brain_Retrieval_Facade', 'pack' )
			&& class_exists( 'BizCity_MCP_Tool_Registry', false )
			&& class_exists( 'BizCity_Brain_MCP_Service', false );
		$this->emit( $ctx, $steps, 'Loader - shared Brain facade and MCP owners are loaded', $loader_ok, $loader_ok ? 'Facade, registry and MCP service are available.' : 'A D4 class is unavailable.' );
		if ( ! $loader_ok ) {
			return array( 'status' => 'fail', 'summary' => 'D4 loader contract is incomplete.', 'error' => 'kg_mcp_parity_loader_incomplete', 'fix_hint' => 'Load the facade before MCP tool registration and dispatch.', 'steps' => $steps );
		}

		$input = array( 'mode' => 'context_bank', 'channel' => 'twin_gpt', 'filters' => array( 'record_kind' => 'rollup' ), 'budget' => array( 'max_records' => 10 ) );
		$twin = BizCity_Brain_Retrieval_Facade::pack( 'twin_gpt', $input );
		$mcp  = BizCity_Brain_Retrieval_Facade::pack( 'mcp', $input );

		// Check 1 — same query identity.
		$query_ok = (string) ( $twin['query_id'] ?? '' ) !== '' && (string) $twin['query_id'] === (string) ( $mcp['query_id'] ?? '' );
		$this->emit( $ctx, $steps, 'Check 1 - Twin GPT and MCP have the same query_id', $query_ok, sprintf( 'twin=%s mcp=%s', (string) ( $twin['query_id'] ?? '' ), (string) ( $mcp['query_id'] ?? '' ) ) );

		// Check 2 — same evidence set. Incomplete packs are not silently called PASS.
		$twin_refs = array_values( array_unique( (array) ( $twin['evidence_refs'] ?? array() ) ) );
		$mcp_refs  = array_values( array_unique( (array) ( $mcp['evidence_refs'] ?? array() ) ) );
		sort( $twin_refs );
		sort( $mcp_refs );
		$both_complete = empty( $twin['incomplete'] ) && empty( $mcp['incomplete'] );
		$evidence_ok = $both_complete ? $twin_refs === $mcp_refs : (bool) ( $twin['incomplete'] ?? false ) && (bool) ( $mcp['incomplete'] ?? false );
		$this->emit( $ctx, $steps, 'Check 2 - same evidence refs set, or both packs explicitly incomplete', $evidence_ok, sprintf( 'complete=%s twin_refs=%d mcp_refs=%d', $both_complete ? 'yes' : 'no', count( $twin_refs ), count( $mcp_refs ) ) );

		// Check 3 — MCP surface is only a shaping boundary; no path/raw body fields.
		$mcp_raw = (string) wp_json_encode( $mcp );
		$redaction_ok = ! preg_match( '/"(?:path|file_path|physical_path|jsonl|ciphertext|raw_body|token|access_token)"\s*:/i', $mcp_raw )
			&& ! preg_match( '/\/home\/|wp-content\/uploads|BEGIN (?:RSA|AES|PRIVATE)/i', $mcp_raw );
		$this->emit( $ctx, $steps, 'Check 3 - MCP payload has no filesystem path, token, ciphertext or raw body field', $redaction_ok, $redaction_ok ? 'MCP payload is bounded and redacted.' : 'MCP payload contains a forbidden internal field/pattern.' );

		// Check 4 — missing scope is a hard permission failure at the MCP registry boundary.
		$descriptors = BizCity_MCP_Tool_Registry::list_descriptors( false );
		$scope_descriptor_ok = false;
		foreach ( $descriptors as $descriptor ) {
			if ( 'brain.context.search' === (string) ( $descriptor['name'] ?? '' ) && 'brain.read' === (string) ( $descriptor['required_scope'] ?? 'brain.read' ) ) {
				$scope_descriptor_ok = true;
				break;
			}
		}
		$this->emit( $ctx, $steps, 'Check 4 - brain.context.search declares brain.read scope', $scope_descriptor_ok, $scope_descriptor_ok ? 'MCP descriptor requires brain.read.' : 'MCP descriptor is missing or has the wrong scope.' );

		// Check 5 — all three new tools are read-only and default OFF.
		$tool_names = array( 'brain.context.search', 'brain.context.evidence', 'brain.order.summary' );
		$tool_catalog = array();
		foreach ( $descriptors as $descriptor ) {
			if ( in_array( (string) ( $descriptor['name'] ?? '' ), $tool_names, true ) ) {
				$tool_catalog[ (string) $descriptor['name'] ] = $descriptor;
			}
		}
		$tools_ok = count( $tool_catalog ) === 3;
		foreach ( $tool_names as $name ) {
			$tools_ok = $tools_ok
				&& ! empty( $tool_catalog[ $name ]['annotations']['readOnlyHint'] )
				&& empty( $tool_catalog[ $name ]['annotations']['destructiveHint'] )
				&& class_exists( 'BizCity_MCP_Tool_Policy' )
				&& ! BizCity_MCP_Tool_Policy::default_enabled_for( $name );
		}
		$this->emit( $ctx, $steps, 'Check 5 - three context tools are read-only and default OFF', $tools_ok, $tools_ok ? 'All three tools are registered read-only and remain disabled by default.' : 'Tool registration/read-only/default policy mismatch.' );

		// Check 6 — degraded parity when the Context Bank flag is explicitly off.
		$degraded_ok = false;
		$previous = function_exists( 'get_option' ) ? get_option( 'bizcity_context_bank_mpr_enabled', '__d4_missing__' ) : '__d4_missing__';
		try {
			if ( function_exists( 'update_option' ) ) {
				update_option( 'bizcity_context_bank_mpr_enabled', 0, false );
			}
			$twin_off = BizCity_Brain_Retrieval_Facade::pack( 'twin_gpt', $input );
			$mcp_off  = BizCity_Brain_Retrieval_Facade::pack( 'mcp', $input );
			$degraded_ok = ! empty( $twin_off['degraded'] ) && ! empty( $mcp_off['degraded'] )
				&& (string) $twin_off['reason_bucket'] === (string) $mcp_off['reason_bucket']
				&& (string) $twin_off['query_id'] === (string) $mcp_off['query_id'];
		} catch ( \Throwable $e ) {
			$degraded_ok = false;
		} finally {
			if ( function_exists( 'update_option' ) && function_exists( 'delete_option' ) ) {
				if ( '__d4_missing__' === $previous ) { delete_option( 'bizcity_context_bank_mpr_enabled' ); }
				else { update_option( 'bizcity_context_bank_mpr_enabled', $previous, false ); }
			}
		}
		$this->emit( $ctx, $steps, 'Check 6 - feature-off gives identical degraded parity', $degraded_ok, $degraded_ok ? 'Twin GPT/MCP share degraded state, reason bucket and query_id.' : 'Feature-off parity drifted between surfaces.' );

		// Check 7 — KG candidate boundary is stable-rollup-only.
		$kg_ok = false;
		if ( class_exists( 'BizCity_Context_Bank_KG_Candidate_Policy' ) ) {
			$raw_decision = BizCity_Context_Bank_KG_Candidate_Policy::evaluate(
				array( 'record_id' => 'raw_message', 'record_kind' => 'event', 'source_contract_id' => 'core.channel_gateway.context_corpus', 'lifecycle_status' => 'active' ),
				array( 'authorized' => true, 'pointer_verified' => true )
			);
			// The policy returns `ok=true` for a valid decision envelope; the
			// candidate boolean is the actual promotion verdict.
			$kg_ok = ! empty( $raw_decision['ok'] ) && empty( $raw_decision['candidate'] );
		}
		$this->emit( $ctx, $steps, 'Check 7 - raw message/event cannot become a KG candidate', $kg_ok, $kg_ok ? 'KG candidate policy rejected a raw event record.' : 'KG policy accepted a raw event record.' );

		// Check 8 — OAuth identity metadata boundary is source-visible and redacted.
		$oauth_source = (string) file_get_contents( $root . 'core/mcp/includes/class-mcp-tool-registry.php' );
		$oauth_ok = false !== strpos( $oauth_source, "'key_id'" ) && false !== strpos( $oauth_source, "'blog_id'" ) && false !== strpos( $oauth_source, 'request_hash' ) && false === strpos( $oauth_source, "'full_token'" );
		$this->emit( $ctx, $steps, 'Check 8 - MCP audit boundary records identity metadata without full token', $oauth_ok, $oauth_ok ? 'Audit source contains key_id/blog_id/request hash and no full-token field.' : 'MCP audit identity/redaction boundary is incomplete.' );

		$passed = true;
		foreach ( $steps as $step ) {
			if ( 'fail' === (string) ( $step['status'] ?? '' ) ) { $passed = false; break; }
		}
		return array(
			'status' => $passed ? 'pass' : 'fail',
			'summary' => $passed ? 'KG/MCP parity passed.' : 'KG/MCP parity failed.',
			'error' => $passed ? '' : 'kg_mcp_parity_failed',
			'fix_hint' => $passed ? '' : 'Keep Twin GPT and MCP behind one facade; inspect pack completeness, tool policy and KG boundary.',
			'steps' => $steps,
		);
	}

	public function cleanup(): void {}

	private function emit( $ctx, array &$steps, string $label, bool $ok, string $detail ): void {
		$step = array( 'label' => $label, 'status' => $ok ? 'pass' : 'fail', 'detail' => $detail );
		$steps[] = $step;
		if ( is_object( $ctx ) && method_exists( $ctx, 'emit_step' ) ) { $ctx->emit_step( $step ); }
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $probes ) {
	$probes[] = 'BizCity_Probe_Brain_KG_MCP_Parity';
	return $probes;
} );
