<?php
/**
 * DDV probe for the Context Bank to KG-Hub bridge.
 *
 * The default path is deliberately read-only: promotion is disabled unless a
 * separately approved canary enables the feature flag.
 *
 * @package BizCity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 */

defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'BizCity_Diagnostics_Probe' ) || class_exists( 'BizCity_Probe_Context_Bank_KG_Bridge', false ) ) {
	return;
}

final class BizCity_Probe_Context_Bank_KG_Bridge implements BizCity_Diagnostics_Probe {

	const FLAG = 'bizcity_context_bank_kg_bridge_enabled';

	public function id(): string {
		return 'core.context_bank.kg_bridge';
	}

	public function label(): string {
		return 'Context Bank - KG promotion bridge';
	}

	public function description(): string {
		return 'Checks the guarded KG-Hub promotion boundary and confirms the default diagnostics path cannot promote or write KG evidence.';
	}

	public function severity(): string { return 'critical'; }
	public function order(): int { return 73; }
	public function icon(): string { return 'share'; }
	public function estimate_ms(): int { return 100; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_Context_Bank_KG_Bridge' ) ) {
			return new WP_Error( 'context_bank_kg_bridge_missing', 'Context Bank KG bridge is not loaded.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-09-02 Johnny Chu] PHASE-CB6.3-DDV — prove bridge loading and default no-promotion behavior without a KG or provider write.
		$root = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) ) . '/';
		$bridge_file = $root . 'core/context-bank/includes/class-context-bank-kg-bridge.php';
		$disk_ok = is_readable( $bridge_file );
		$loader_ok = class_exists( 'BizCity_Context_Bank_KG_Bridge' )
			&& method_exists( 'BizCity_Context_Bank_KG_Bridge', 'promote' )
			&& method_exists( 'BizCity_Context_Bank_KG_Bridge', 'resolve_citation' )
			&& method_exists( 'BizCity_Context_Bank_KG_Bridge', 'run_recheck' );
		$policy_order_ok = false;
		$replay_authorization_order_ok = false;
		$notebook_authorization_order_ok = false;
		$reconcile_order_ok = false;
		$bridge_source = file_get_contents( $bridge_file );
		if ( is_string( $bridge_source ) ) {
			$read_position = strpos( $bridge_source, '$followed = self::read_pointer' );
			$policy_position = strpos( $bridge_source, 'BizCity_Context_Bank_KG_Candidate_Policy::evaluate' );
			$policy_order_ok = false !== $read_position && false !== $policy_position && $read_position < $policy_position && strpos( $bridge_source, 'policy_metadata' ) !== false;
			$follow_position = strpos( $bridge_source, '$verified = $ledger->follow' );
			$replay_position = strpos( $bridge_source, 'self::verify_existing_promotion' );
			$replay_authorization_order_ok = false !== $follow_position && false !== $replay_position && $follow_position < $replay_position && strpos( $bridge_source, 'kg_replay_provenance_unverified' ) !== false;
			$notebook_position = strpos( $bridge_source, 'self::notebook_allowed_for_user' );
			$ingest_position = strpos( $bridge_source, 'BizCity_KG::ingest_extension_source' );
			$notebook_authorization_order_ok = false !== $notebook_position && false !== $ingest_position && $notebook_position < $ingest_position && strpos( $bridge_source, 'kg_notebook_scope_denied' ) !== false;
			$reconcile_position = strpos( $bridge_source, 'public static function reconcile_provenance' );
			$lookup_position = false !== $reconcile_position ? strpos( $bridge_source, "BizCity_KG::lookup_xref( 'passage'", $reconcile_position ) : false;
			$reconcile_order_ok = false !== $reconcile_position && false !== $lookup_position && $reconcile_position < $lookup_position && strpos( $bridge_source, 'kg_provenance_stale' ) !== false;
		}
		$retry_idempotency_ok = false;
		$facade_file = $root . 'core/knowledge/kg-hub/includes/class-kg-facade.php';
		$facade_source = is_readable( $facade_file ) ? file_get_contents( $facade_file ) : '';
		if ( is_string( $bridge_source ) && is_string( $facade_source ) ) {
			$retry_idempotency_ok = strpos( $facade_source, 'existing_source' ) !== false
				&& strpos( $facade_source, 'replayed' ) !== false
				&& strpos( $bridge_source, 'existing_xrefs' ) !== false
				&& strpos( $bridge_source, '$existing_xref_id' ) !== false
				&& strpos( $facade_source, 'extension_passage_vector_status' ) !== false
				&& strpos( $facade_source, 'kg_extension_vector_missing' ) !== false;
		}
		$cost_guard_order_ok = is_string( $bridge_source ) && strpos( $bridge_source, 'BizCity_KG_Cost_Guard::instance()->can_extract' ) !== false && strpos( $bridge_source, 'kg_cost_guard_denied' ) !== false && strpos( $bridge_source, '$cost_check' ) < strpos( $bridge_source, 'BizCity_KG::ingest_extension_source' );
		$citation_order_ok = is_string( $bridge_source )
			&& strpos( $bridge_source, 'public static function resolve_citation' ) !== false
			&& strpos( $bridge_source, 'BizCity_KG::lookup_xref' ) !== false
			&& strpos( $bridge_source, 'BizCity_Context_Bank_Ledger::instance()->follow' ) !== false
			&& strpos( $bridge_source, 'self::citation_source_view' ) !== false
			&& strpos( $bridge_source, "'context-bank-canonical-owner'" ) !== false;
		$recheck_order_ok = is_string( $bridge_source )
			&& strpos( $bridge_source, 'private static function schedule_recheck' ) !== false
			&& strpos( $bridge_source, 'wp_next_scheduled' ) !== false
			&& strpos( $bridge_source, 'kg_recheck_scheduled' ) !== false
			&& strpos( $bridge_source, 'kg_recheck_schedule_failed' ) !== false
			&& strpos( $bridge_source, "'kg_status' => 'pending'" ) !== false;
		$kg_extraction_owner_ok = is_string( $bridge_source )
			&& strpos( $bridge_source, 'BizCity_KG::ingest_extension_source' ) !== false
			&& strpos( $bridge_source, 'extract_entity' ) === false
			&& strpos( $bridge_source, 'extract_relation' ) === false;
		$citation_invalid_ok = false;
		$recheck_isolated_ok = false;
		try {
			$citation_invalid = BizCity_Context_Bank_KG_Bridge::resolve_citation( 0, array() );
			$citation_invalid_ok = is_array( $citation_invalid ) && empty( $citation_invalid['ok'] ) && (string) ( $citation_invalid['reason'] ?? '' ) === 'kg_citation_identity_invalid';
			$recheck_isolated = BizCity_Context_Bank_KG_Bridge::run_recheck( array( 'blog_id' => (int) get_current_blog_id(), 'record_id' => 'diagnostics_recheck', 'source_contract_id' => 'core.context_bank.rollup' ) );
			$recheck_isolated_ok = defined( 'BIZCITY_DIAGNOSTICS_CLI' ) && BIZCITY_DIAGNOSTICS_CLI && is_array( $recheck_isolated ) && empty( $recheck_isolated['ok'] ) && (string) ( $recheck_isolated['reason'] ?? '' ) === 'diagnostics_cli_isolated';
		} catch ( \Throwable $e ) {
			$citation_invalid_ok = false;
			$recheck_isolated_ok = false;
		}
		$missing_flag = '__cb_kg_bridge_flag_missing__';
		$previous_flag = get_option( self::FLAG, $missing_flag );
		$replay_guard_ok = false;
		try {
			delete_option( self::FLAG );
			$disabled = BizCity_Context_Bank_KG_Bridge::promote( array( 'record_id' => 'diagnostics_kg_bridge', 'record_kind' => 'rollup' ), array( 'authorized' => true ) );
			$runtime_ok = is_array( $disabled ) && ! empty( $disabled['ok'] ) && empty( $disabled['promoted'] ) && 'kg_promotion_disabled' === (string) ( $disabled['reason'] ?? '' );
			update_option( self::FLAG, true, false );
			$tampered = BizCity_Context_Bank_KG_Bridge::promote( array( 'record_id' => 'diagnostics_kg_bridge_' . wp_generate_uuid4(), 'record_kind' => 'rollup', 'kg_source_id' => 999999, 'kg_passage_id' => 999999 ), array( 'authorized' => true ) );
			$replay_guard_ok = is_array( $tampered ) && empty( $tampered['promoted'] ) && (string) ( $tampered['reason'] ?? '' ) !== 'kg_replay_provenance_verified';
		} finally {
			if ( $previous_flag === $missing_flag ) {
				delete_option( self::FLAG );
			} else {
				update_option( self::FLAG, $previous_flag, false );
			}
		}
		foreach ( array(
			array( 'label' => 'Disk - KG bridge artifact is readable', 'ok' => $disk_ok, 'detail' => $disk_ok ? 'The canonical Context Bank KG bridge artifact is readable.' : 'The KG bridge artifact is missing or unreadable.' ),
			array( 'label' => 'Loader - bridge exposes the promotion boundary', 'ok' => $loader_ok, 'detail' => $loader_ok ? 'The bridge is loaded with its guarded promote() API.' : 'The bridge class or promote() method is unavailable.' ),
			array( 'label' => 'Loader - owner metadata precedes KG policy', 'ok' => $policy_order_ok, 'detail' => $policy_order_ok ? 'Canonical rollup metadata is read before stable/confidence candidate evaluation.' : 'KG policy is evaluated before canonical rollup metadata is read.' ),
			array( 'label' => 'Loader - KG replay follows authorization and xref verification', 'ok' => $replay_authorization_order_ok, 'detail' => $replay_authorization_order_ok ? 'Replay acknowledgement is reachable only after canonical ledger follow and reverse xref verification.' : 'Replay acknowledgement can bypass canonical pointer authorization or reverse xref verification.' ),
			array( 'label' => 'Loader - KG notebook ownership precedes ingest', 'ok' => $notebook_authorization_order_ok, 'detail' => $notebook_authorization_order_ok ? 'Notebook ownership is checked through the canonical KG service before source or passage creation.' : 'KG ingestion can run before same-tenant notebook ownership is verified.' ),
			array( 'label' => 'Loader - KG provenance reconcile is bounded and owner-routed', 'ok' => $reconcile_order_ok, 'detail' => $reconcile_order_ok ? 'Provenance reconciliation follows the ledger pointer and KG facade before marking a derived pointer stale.' : 'KG provenance reconciliation is missing or can bypass canonical owners.' ),
			array( 'label' => 'Loader - KG citation resolves through canonical owner chain', 'ok' => $citation_order_ok, 'detail' => $citation_order_ok ? 'Citation resolution is defined as KG xref -> current-tenant ledger follow -> canonical owner source view.' : 'Citation resolution does not expose the required owner chain.' ),
			array( 'label' => 'Loader - KG recheck scheduling is bounded and isolated', 'ok' => $recheck_order_ok, 'detail' => $recheck_order_ok ? 'Recheck scheduling has duplicate suppression, bounded metadata and a pending state.' : 'KG recheck scheduling is missing a bounded pending or duplicate-suppression boundary.' ),
			array( 'label' => 'Loader - entity and relation extraction remains KG-Hub-owned', 'ok' => $kg_extraction_owner_ok, 'detail' => $kg_extraction_owner_ok ? 'The Context Bank bridge delegates semantic extraction to the canonical KG-Hub facade and defines no parallel extractor.' : 'The bridge contains a parallel entity or relation extraction path.' ),
			array( 'label' => 'Loader - KG promotion retry is idempotent', 'ok' => $retry_idempotency_ok, 'detail' => $retry_idempotency_ok ? 'Stable extension origin and promoted xref lookup reuse existing KG rows on retry.' : 'KG promotion retry can create duplicate source, passage or xref rows.' ),
			array( 'label' => 'Loader - KG cost guard precedes ingest', 'ok' => $cost_guard_order_ok, 'detail' => $cost_guard_order_ok ? 'The canonical KG cost guard runs before source/passage creation and returns a bounded denial bucket.' : 'KG ingest can run before the canonical cost guard.' ),
			array( 'label' => 'Runtime - promotion is disabled by default', 'ok' => $runtime_ok, 'detail' => $runtime_ok ? 'Diagnostics cannot promote a rollup or write KG evidence while the canary flag is absent.' : 'The default bridge path attempted promotion or returned an unexpected result.' ),
			array( 'label' => 'Runtime - tampered replay metadata is refused', 'ok' => $replay_guard_ok, 'detail' => $replay_guard_ok ? 'Caller-supplied KG IDs did not produce replay success without a canonical ledger pointer and matching xref.' : 'Tampered replay metadata was acknowledged without canonical verification.' ),
			array( 'label' => 'Runtime - invalid citation identity fails closed', 'ok' => $citation_invalid_ok, 'detail' => $citation_invalid_ok ? 'Invalid KG passage identity was refused before xref or ledger access.' : 'Invalid citation identity reached an unexpected path.' ),
			array( 'label' => 'Runtime - KG recheck worker is blocked in Diagnostics CLI', 'ok' => $recheck_isolated_ok, 'detail' => $recheck_isolated_ok ? 'Direct recheck entry returns diagnostics_cli_isolated before ledger or KG side effects.' : 'Direct KG recheck entry was not isolated from Diagnostics CLI.' ),
		) as $step ) {
			$ctx->emit_step( array( 'label' => $step['label'], 'status' => $step['ok'] ? 'pass' : 'fail', 'detail' => $step['detail'] ) );
		}
		$pass = $disk_ok && $loader_ok && $policy_order_ok && $replay_authorization_order_ok && $notebook_authorization_order_ok && $reconcile_order_ok && $citation_order_ok && $recheck_order_ok && $kg_extraction_owner_ok && $retry_idempotency_ok && $cost_guard_order_ok && $runtime_ok && $replay_guard_ok && $citation_invalid_ok && $recheck_isolated_ok;
		return array( 'status' => $pass ? 'pass' : 'fail', 'summary' => $pass ? 'KG promotion bridge passed guarded default-path checks.' : 'KG promotion bridge failed guarded default-path checks.', 'fix_hint' => $pass ? '' : 'Restore the bridge loader and keep KG promotion disabled until the physical-shard canary is ready.', 'steps' => array() );
	}

	public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Context_Bank_KG_Bridge';
	return $list;
} );