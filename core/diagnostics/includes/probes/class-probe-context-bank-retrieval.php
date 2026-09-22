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
		return 'Checks server-owned context_bank/vertical/notebook/hybrid scope policy, group denial, bounded budgets, the default-ON MPR flag owner and source-layer contract filtering without payload or provider access.';
	}

	public function severity(): string { return 'critical'; }
	public function order(): int { return 74; }
	public function icon(): string { return 'search'; }
	public function estimate_ms(): int { return 100; }

	public function precondition() {
		// [2026-09-02 11:29 AM Johnny Chu - Chu Hoàng Anh] PHASE-CB7-DDV — require the canonical scope resolver before retrieval assertions.
		if ( ! class_exists( 'BizCity_Context_Bank_Scope_Resolver' ) || ! class_exists( 'BizCity_Context_Bank_Mode_Policy' ) ) {
			return new WP_Error( 'context_bank_retrieval_scope_missing', 'Context Bank scope resolver is not loaded.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-09-02 11:29 AM Johnny Chu - Chu Hoàng Anh] PHASE-CB7-DDV — verify server-owned retrieval modes and bounded source-layer policy without storage side effects.
		$current_user = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		// [2026-09-13 Johnny Chu - Chu Hoàng Anh] PHASE-1.33B-B6 — prove the mode registry is server-owned and bounded before resolving a request.
		$mode_policy = BizCity_Context_Bank_Mode_Policy::describe();
		$mode_policy_ok = (string) ( $mode_policy['mode_id'] ?? '' ) === 'context_bank'
			&& (string) ( $mode_policy['scope_kind'] ?? '' ) === 'horizontal_context'
			&& (string) ( $mode_policy['group_private_scope'] ?? '' ) === 'deny'
			&& in_array( 'core.channel_gateway.context_corpus', (array) ( $mode_policy['allowed_contracts'] ?? array() ), true )
			&& ! in_array( 'core.knowledge.user_memory', (array) ( $mode_policy['allowed_contracts'] ?? array() ), true );
		$group = BizCity_Context_Bank_Scope_Resolver::resolve( array( 'mode' => 'hybrid', 'channel' => 'twinchat', 'chat_kind' => 'group', 'user_id' => 999999, 'blog_id' => 999999 ) );
		$group_ok = (string) ( $group['effective_mode'] ?? '' ) === 'skip' && (string) ( $group['reason_bucket'] ?? '' ) === 'group_private_scope_denied' && (int) ( $group['owner_user_id'] ?? 0 ) === 0;
		$unknown_vertical = BizCity_Context_Bank_Scope_Resolver::resolve( array( 'mode' => 'vertical', 'channel' => 'twin_gpt', 'vertical_id' => '__not_registered__' ) );
		$unknown_vertical_ok = $current_user > 0
			? (string) ( $unknown_vertical['effective_mode'] ?? '' ) === 'skip' && (string) ( $unknown_vertical['reason_bucket'] ?? '' ) === 'vertical_not_registered'
			: (string) ( $unknown_vertical['effective_mode'] ?? '' ) === 'skip';
		$unknown_mode = BizCity_Context_Bank_Scope_Resolver::resolve( array( 'mode' => 'unregistered_mode', 'channel' => 'twin_gpt' ) );
		$unknown_mode_ok = (string) ( $unknown_mode['effective_mode'] ?? '' ) === 'skip'
			&& (string) ( $unknown_mode['reason_bucket'] ?? '' ) === 'mode_unknown';
		$vertical = BizCity_Context_Bank_Scope_Resolver::resolve( array( 'mode' => 'vertical', 'channel' => 'twin_gpt', 'vertical_id' => 'woo_bizops' ) );
		$vertical_ok = $current_user > 0
			? (string) ( $vertical['effective_mode'] ?? '' ) === 'vertical' && (string) ( $vertical['vertical_id'] ?? '' ) === 'woo_bizops' && ! empty( $vertical['policy_contracts'] )
			: (string) ( $vertical['effective_mode'] ?? '' ) === 'skip';
		$hybrid = BizCity_Context_Bank_Scope_Resolver::resolve( array( 'mode' => 'hybrid', 'channel' => 'twin_gpt', 'vertical_id' => 'woo_bizops' ) );
		$hybrid_ok = $current_user > 0
			? (string) ( $hybrid['effective_mode'] ?? '' ) === 'hybrid' && count( (array) ( $hybrid['policy_contracts'] ?? array() ) ) > 0
			: (string) ( $hybrid['effective_mode'] ?? '' ) === 'skip';
		// [2026-09-13 Johnny Chu - Chu Hoàng Anh] PHASE-1.33B-B6 — prove Context Bank Brain resolves without a Notebook and keeps the business allowlist bounded.
		$context_bank = BizCity_Context_Bank_Scope_Resolver::resolve( array( 'mode' => 'context_bank', 'channel' => 'twin_gpt', 'identity_uuid' => 'diagnostics_context_bank_identity' ) );
		$context_bank_contracts = (array) ( $context_bank['policy_contracts'] ?? array() );
		$context_bank_ok = $current_user > 0
			? (string) ( $context_bank['effective_mode'] ?? '' ) === 'context_bank'
				&& (int) ( $context_bank['notebook_id'] ?? 0 ) === 0
				&& in_array( 'core.channel_gateway.context_corpus', $context_bank_contracts, true )
				&& in_array( 'core.context_bank.rollup', $context_bank_contracts, true )
				&& ! in_array( 'core.knowledge.user_memory', $context_bank_contracts, true )
			: (string) ( $context_bank['effective_mode'] ?? '' ) === 'skip';
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
		$feature_off_ok = false;
		if ( class_exists( 'BizCity_TwinBrain_Notebook_Source_Layer' ) && function_exists( 'get_option' ) && function_exists( 'delete_option' ) ) {
			// [2026-09-13 Johnny Chu - Chu Hoàng Anh] PHASE-1.33B-B6 — prove feature-off parity at the Context Bank source boundary without provider, ledger or payload side effects.
			// [2026-09-15 Johnny Chu - Chu Hoàng Anh] PHASE-1.33C — the flag is now default-ON, so this check must pass an explicit `context_bank_enabled=false` to prove the disabled path.
			$missing_flag = '__context_bank_mpr_flag_missing__';
			$previous_flag = get_option( 'bizcity_context_bank_mpr_enabled', $missing_flag );
			try {
				delete_option( 'bizcity_context_bank_mpr_enabled' );
				$layer = BizCity_TwinBrain_Notebook_Source_Layer::instance();
				$method = new ReflectionMethod( $layer, 'collect_context_bank_refs' );
				$method->setAccessible( true );
				$feature_off = $method->invoke( $layer, array( 'context_bank_enabled' => false, 'context_bank_mode' => 'context_bank' ) );
				$feature_off_ok = is_array( $feature_off ) && 0 === (int) ( $feature_off['count'] ?? -1 ) && empty( $feature_off['refs'] ) && 'context_bank_disabled_or_unavailable' === (string) ( $feature_off['meta']['reason'] ?? '' );
			} catch ( \Throwable $e ) {
				$feature_off_ok = false;
			} finally {
				if ( $previous_flag === $missing_flag ) {
					delete_option( 'bizcity_context_bank_mpr_enabled' );
				} else {
					update_option( 'bizcity_context_bank_mpr_enabled', $previous_flag, false );
				}
			}
		}
		// [2026-09-15 Johnny Chu - Chu Hoàng Anh] PHASE-1.33C — prove the Context Bank MPR flag defaults ON and that a stored `0` still disables it.
		$flag_default_ok = false;
		$flag_owner_ok = false;
		if ( class_exists( 'BizCity_Context_Bank_Mode_Policy' ) && method_exists( 'BizCity_Context_Bank_Mode_Policy', 'is_enabled' ) && method_exists( 'BizCity_Context_Bank_Mode_Policy', 'is_enabled_default' ) && function_exists( 'update_option' ) && function_exists( 'delete_option' ) ) {
			$flag_owner_ok = true;
			$previous = get_option( 'bizcity_context_bank_mpr_enabled', '__missing__' );
			try {
				delete_option( 'bizcity_context_bank_mpr_enabled' );
				$default_on = (bool) BizCity_Context_Bank_Mode_Policy::is_enabled();
				update_option( 'bizcity_context_bank_mpr_enabled', 0, false );
				$explicit_off = (bool) BizCity_Context_Bank_Mode_Policy::is_enabled();
				$flag_default_ok = $default_on && ! $explicit_off;
			} catch ( \Throwable $e ) {
				$flag_default_ok = false;
			} finally {
				if ( $previous === '__missing__' ) {
					delete_option( 'bizcity_context_bank_mpr_enabled' );
				} else {
					update_option( 'bizcity_context_bank_mpr_enabled', $previous, false );
				}
			}
		}
		// [2026-09-15 Johnny Chu - Chu Hoàng Anh] PHASE-1.33C — prove the Context Bank MPR footlog contract and writer boundary are present.
		$footlog_ok = false;
		if ( class_exists( 'BizCity_Log_Contract_Registry' ) && class_exists( 'BizCity_TwinBrain_Notebook_Source_Layer' ) ) {
			$contract = BizCity_Log_Contract_Registry::get( 'core.context_bank.mpr_trace' );
			$source_file_footlog = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR . 'core/twinbrain/includes/class-twinbrain-notebook-source-layer.php' : dirname( dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) ) . '/core/twinbrain/includes/class-twinbrain-notebook-source-layer.php';
			$source_footlog = is_readable( $source_file_footlog ) ? file_get_contents( $source_file_footlog ) : '';
			$footlog_ok = is_array( $contract )
				&& (string) ( $contract['jsonl_folder'] ?? '' ) === 'bizcity-twinbrain-logs'
				&& (string) ( $contract['jsonl_module'] ?? '' ) === 'context-bank-mpr'
				&& (string) ( $contract['owner_module'] ?? '' ) === 'core/context-bank'
				&& is_string( $source_footlog )
				&& strpos( $source_footlog, 'write_context_bank_footlog' ) !== false
				&& strpos( $source_footlog, "'core.context_bank.mpr_trace'" ) !== false
				&& strpos( $source_footlog, "context_bank_mpr_skipped" ) !== false;
		}
		// [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-1.33D-D8 — assert the D1/D2/D3/5A.3/C11 closure at the contract boundary: duration forwarding, the phase event pair, vertical narrowing that can only narrow, one footlog per turn, and feature-off parity. No ledger, provider, payload or browser trace is touched.
		$runtime_file = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR . 'core/twinbrain/includes/class-twinbrain-runtime.php' : dirname( dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) ) . '/core/twinbrain/includes/class-twinbrain-runtime.php';
		$runtime_source = is_readable( $runtime_file ) ? (string) file_get_contents( $runtime_file ) : '';

		// D1 — the measured phase duration and the bounded buckets must survive into the timeline payload.
		$duration_forward_ok = false;
		if ( class_exists( 'BizCity_TwinBrain_Notebook_Source_Layer' ) ) {
			try {
				$layer = BizCity_TwinBrain_Notebook_Source_Layer::instance();
				$method = new ReflectionMethod( $layer, 'build_context_bank_timeline_payload' );
				$method->setAccessible( true );
				$payload = $method->invoke( $layer, array(
					'refs'          => array( array( 'record_id' => 'diagnostics_ref' ) ),
					'owner_records' => array( array( 'record_id' => 'diagnostics_ref' ) ),
					'meta'          => array(
						'enabled' => true, 'duration_ms' => 137, 'budget_ms' => 250, 'reason_bucket' => '',
						'matched_count' => 9, 'returned_count' => 4, 'truncated' => false,
						'vertical_id' => 'woo_bizops', 'binding_source' => 'web_mode', 'mode_hint_applied' => 'hybrid',
						'dimension_filters' => array( 'entity_type' ), 'contract_count' => 2,
						'contracts_before_narrowing' => 4, 'contracts_after_narrowing' => 2, 'rounds' => 2,
						'pointer_follows' => 3, 'retrieval_policy' => array( 'mode' => 'hybrid' ),
					),
				) );
				$skipped = $method->invoke( $layer, array( 'refs' => array(), 'count' => 0, 'meta' => array( 'enabled' => false, 'reason' => 'context_bank_disabled_or_unavailable' ) ) );
				$duration_forward_ok = (int) ( $payload['duration_ms'] ?? -1 ) === 137
					&& (int) ( $payload['budget_ms'] ?? -1 ) === 250
					&& (int) ( $payload['matched_count'] ?? -1 ) === 9
					&& (int) ( $payload['returned_count'] ?? -1 ) === 4
					&& (string) ( $payload['status'] ?? '' ) === 'ran'
					&& (string) ( $payload['vertical_id'] ?? '' ) === 'woo_bizops'
					&& (string) ( $payload['mode_hint_applied'] ?? '' ) === 'hybrid'
					&& (int) ( $payload['contracts_before_narrowing'] ?? -1 ) === 4
					&& (int) ( $payload['contracts_after_narrowing'] ?? -1 ) === 2
					&& (int) ( $payload['rounds'] ?? -1 ) === 2
					// §2.2 rule 2 — status and enabled must agree on the disabled path too.
					&& (string) ( $skipped['status'] ?? '' ) === 'skipped'
					&& empty( $skipped['enabled'] );
			} catch ( \Throwable $e ) {
				$duration_forward_ok = false;
			}
		}

		// D2 — the phase pair must be registered in the canonical taxonomy with schemas, and emitted on both paths.
		$phase_event_ok = false;
		if ( class_exists( 'BizCity_Twin_Event_Taxonomy' ) ) {
			$started_ok = defined( 'BizCity_Twin_Event_Taxonomy::CONTEXT_BANK_STARTED' ) && BizCity_Twin_Event_Taxonomy::CONTEXT_BANK_STARTED === 'context_bank_started';
			$done_ok    = defined( 'BizCity_Twin_Event_Taxonomy::CONTEXT_BANK_DONE' ) && BizCity_Twin_Event_Taxonomy::CONTEXT_BANK_DONE === 'context_bank_done';
			$all_types  = (array) BizCity_Twin_Event_Taxonomy::all();
			$in_all     = in_array( 'context_bank_started', $all_types, true ) && in_array( 'context_bank_done', $all_types, true );
			$required_all = (array) BizCity_Twin_Event_Taxonomy::required_fields();
			$required     = (array) ( $required_all['context_bank_done'] ?? array() );
			$required_ok = in_array( 'duration_ms', $required, true ) && in_array( 'status', $required, true ) && in_array( 'reason_bucket', $required, true ) && in_array( 'phase', $required, true );
			$schema_dir = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR . 'core/twin-core/event-stream/schemas/events/' : dirname( dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) ) . '/core/twin-core/event-stream/schemas/events/';
			$schemas_ok = true;
			foreach ( array( 'context_bank_started.json', 'context_bank_done.json' ) as $schema_name ) {
				$schema_path = $schema_dir . $schema_name;
				if ( ! is_readable( $schema_path ) ) {
					$schemas_ok = false;
					break;
				}
				$decoded = json_decode( (string) file_get_contents( $schema_path ), true );
				if ( ! is_array( $decoded ) ) {
					$schemas_ok = false;
					break;
				}
			}
			$emitter_ok = is_string( $runtime_source )
				&& strpos( $runtime_source, 'function emit_context_bank_phase_events' ) !== false
				&& substr_count( $runtime_source, '$this->emit_context_bank_phase_events(' ) >= 2;
			$phase_event_ok = $started_ok && $done_ok && $in_all && $required_ok && $schemas_ok && $emitter_ok;
		}

		// D3 / §5A.3 Direction A — the vertical may only narrow, and a widening attempt must fail closed.
		$vertical_narrow_ok = false;
		if ( class_exists( 'BizCity_TwinBrain_Notebook_Source_Layer' ) && method_exists( 'BizCity_TwinBrain_Notebook_Source_Layer', 'narrow_contracts_for_binding' ) ) {
			$allowlist = array( 'core.context_bank.commerce_order', 'core.context_bank.rollup', 'core.channel_gateway.context_corpus' );
			$narrowed  = BizCity_TwinBrain_Notebook_Source_Layer::narrow_contracts_for_binding( $allowlist, array( 'contracts' => array( 'core.context_bank.commerce_order', 'core.context_bank.rollup' ) ) );
			$unbound   = BizCity_TwinBrain_Notebook_Source_Layer::narrow_contracts_for_binding( $allowlist, array() );
			$widening  = BizCity_TwinBrain_Notebook_Source_Layer::narrow_contracts_for_binding( $allowlist, array( 'contracts' => array( 'core.knowledge.user_memory' ) ) );
			$vertical_narrow_ok = $narrowed['contracts'] === array( 'core.context_bank.commerce_order', 'core.context_bank.rollup' )
				&& empty( $narrowed['denied'] )
				&& $unbound['contracts'] === $allowlist
				&& empty( $unbound['denied'] )
				&& empty( $widening['contracts'] )
				&& ! empty( $widening['denied'] );
		}

		// §5A.5 — only a STATIC entity_type is in scope; dynamic entity_key must stay out.
		$dimension_filter_ok = false;
		if ( class_exists( 'BizCity_TwinBrain_Notebook_Source_Layer' ) && method_exists( 'BizCity_TwinBrain_Notebook_Source_Layer', 'dimension_filters_for_binding' ) ) {
			$static = BizCity_TwinBrain_Notebook_Source_Layer::dimension_filters_for_binding( array( 'dimension_source' => array( 'entity_type' ), 'static_entity_type' => 'order' ) );
			$dynamic = BizCity_TwinBrain_Notebook_Source_Layer::dimension_filters_for_binding( array( 'dimension_source' => array( 'entity_key' ), 'static_entity_type' => 'order' ) );
			$none = BizCity_TwinBrain_Notebook_Source_Layer::dimension_filters_for_binding( array() );
			$dimension_filter_ok = ( $static['entity_type'] ?? '' ) === 'order' && empty( $dynamic ) && empty( $none );
		}

		// C11 — one footlog row per turn: the phase is memoized per trace_id and the round counter is carried, not re-run.
		$one_footlog_ok = is_string( $source )
			&& strpos( $source, 'function resolve_context_bank_phase' ) !== false
			&& strpos( $source, 'static $memo = array();' ) !== false
			&& strpos( $source, "'rounds' => (int) ( \$opts['_context_bank_round'] ?? 1 )" ) !== false
			&& is_string( $runtime_source )
			&& strpos( $runtime_source, "\$round_opts['_context_bank_round'] = \$round_number;" ) !== false;

		// D8 — feature-off parity: with no binding, the contract set and the dimension filters must be byte-identical to today's shape.
		$feature_off_parity_ok = false;
		if ( class_exists( 'BizCity_TwinBrain_Notebook_Source_Layer' ) && method_exists( 'BizCity_TwinBrain_Notebook_Source_Layer', 'narrow_contracts_for_binding' ) ) {
			$allowlist = array( 'core.context_bank.commerce_order', 'core.context_bank.rollup' );
			$unbound   = BizCity_TwinBrain_Notebook_Source_Layer::narrow_contracts_for_binding( $allowlist, array() );
			$feature_off_parity_ok = $unbound['contracts'] === $allowlist
				&& empty( $unbound['denied'] )
				&& empty( BizCity_TwinBrain_Notebook_Source_Layer::dimension_filters_for_binding( array() ) );
		}

		// D2/D5 — the `deadline` state is a published contract value even though the hoisted lane itself is out of MVP scope (§5A.5).
		$deadline_contract_ok = false;
		$done_schema_path = ( defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR . 'core/twin-core/event-stream/schemas/events/' : dirname( dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) ) . '/core/twin-core/event-stream/schemas/events/' ) . 'context_bank_done.json';
		if ( is_readable( $done_schema_path ) ) {
			$done_schema = json_decode( (string) file_get_contents( $done_schema_path ), true );
			$status_enum = is_array( $done_schema ) ? (array) ( $done_schema['properties']['status']['enum'] ?? array() ) : array();
			$deadline_contract_ok = in_array( 'deadline', $status_enum, true ) && in_array( 'ran', $status_enum, true ) && in_array( 'skipped', $status_enum, true ) && in_array( 'degraded', $status_enum, true );
		}

		// D4 — the W0.20 admission funnel must be recorded so precision is measurable per trace.
		$admission_funnel_ok = false;
		if ( class_exists( 'BizCity_TwinBrain_Notebook_Source_Layer' ) ) {
			try {
				$layer = BizCity_TwinBrain_Notebook_Source_Layer::instance();
				$method = new ReflectionMethod( $layer, 'build_graph_vector_rerank_pack' );
				$method->setAccessible( true );
				$pack = $method->invoke(
					$layer,
					array(),
					array( 'query' => 'diagnostics admission funnel', 'results' => array(), 'tokens' => array() ),
					array(),
					array(),
					array(),
					array(
						'w020_skip_vector_retriever' => true,
						'_context_bank_payload' => array(
							'refs'          => array(),
							'count'         => 0,
							'owner_records' => array(
								array( 'owner' => 'core/skills', 'retrieval_safe' => true, 'source_contract_id' => 'core.skills.rule_reference', 'record_id' => 'funnel_1_v1', 'record' => array( 'skill_key' => 'skill.funnel', 'public_excerpt' => 'Admission funnel excerpt', 'provenance_ref' => 'skill:funnel:v1' ) ),
							),
							'meta'          => array( 'enabled' => true, 'retrieval_policy' => array( 'mode' => 'context_bank' ) ),
						),
					)
				);
				// The funnel must be present, integral, and internally consistent:
				// admitted >= final, and zero-score count can never exceed the final count.
				$admitted = $pack['context_bank_admitted_count'] ?? null;
				$final    = $pack['context_bank_final_count'] ?? null;
				$zero     = $pack['zero_score_final_count'] ?? null;
				$admission_funnel_ok = is_int( $admitted ) && is_int( $final ) && is_int( $zero )
					&& $admitted >= 1
					&& $final >= 0 && $final <= $admitted
					&& $zero >= 0 && $zero <= (int) ( $pack['final_context_count'] ?? 0 );
			} catch ( \Throwable $e ) {
				$admission_funnel_ok = false;
			}
		}

		// [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-1.33D §5A.1/§5A.3 — the pure
		// helper assertions above prove the RULE. They do not prove the rule is
		// actually wired to the real registry row, which is the difference between
		// "the narrowing function works" and "the vertical axis has an effect".
		// This reads the canonical registry and the real binding block, so a
		// regression that deletes the `context_bank` key, widens the contract set,
		// or binds a second vertical is caught here rather than in a browser trace.
		$binding_wired_ok = false;
		if ( class_exists( 'BizCity_TwinBrain_Vertical_Bridge_Registry' )
			&& class_exists( 'BizCity_Context_Bank_Mode_Policy' )
			&& class_exists( 'BizCity_TwinBrain_Notebook_Source_Layer' ) ) {
			$pilot        = null;
			$others_bound = 0;
			foreach ( (array) BizCity_TwinBrain_Vertical_Bridge_Registry::all() as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				if ( (string) ( $row['id'] ?? '' ) === 'woo_bizops' ) {
					$pilot = $row;
					continue;
				}
				if ( isset( $row['context_bank'] ) ) {
					$others_bound++;
				}
			}
			$binding   = ( is_array( $pilot ) && isset( $pilot['context_bank'] ) && is_array( $pilot['context_bank'] ) ) ? $pilot['context_bank'] : array();
			$allowlist = (array) BizCity_Context_Bank_Mode_Policy::contracts_for( 'context_bank' );
			$narrowed  = BizCity_TwinBrain_Notebook_Source_Layer::narrow_contracts_for_binding( $allowlist, $binding );
			$dimension = BizCity_TwinBrain_Notebook_Source_Layer::dimension_filters_for_binding( $binding );
			// Compare as SETS: array_intersect preserves the allowlist order, so a
			// positional comparison would fail for a correct binding whose contracts
			// are declared in a different order than the policy list.
			$narrowed_set = array_values( (array) ( $narrowed['contracts'] ?? array() ) );
			$binding_set  = array_values( (array) ( $binding['contracts'] ?? array() ) );
			sort( $narrowed_set );
			sort( $binding_set );
			$binding_wired_ok = $others_bound === 0
				&& (string) ( $binding['mode_hint'] ?? '' ) === 'hybrid'
				&& ! empty( $binding_set )
				&& count( $binding_set ) < count( $allowlist )
				&& empty( $narrowed['denied'] )
				&& $narrowed_set === $binding_set
				&& (string) ( $dimension['entity_type'] ?? '' ) === 'order'
				&& is_string( $runtime_source )
				&& substr_count( $runtime_source, '$this->resolve_vertical_binding( $opts )' ) >= 2
				&& strpos( $runtime_source, "\$opts['_vertical_binding']" ) !== false
				&& strpos( $runtime_source, "'_vertical_binding_source'] = 'web_mode'" ) !== false;
		}

		// [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-1.33D §5A.4/§5A.3 Direction B —
		// the binding must be observable per vertical without a browser trace, and the
		// vertical must be able to RECEIVE Context Bank data (not only narrow it).
		$binding_evidence_ok = is_string( $source )
			&& strpos( $source, "'contracts_before_narrowing'" ) !== false
			&& strpos( $source, "'contracts_after_narrowing'" ) !== false
			&& strpos( $source, "'mode_hint_applied'" ) !== false
			&& strpos( $source, "'binding_source'" ) !== false
			&& strpos( $source, "'dimension_filters'" ) !== false
			&& strpos( $source, "'vertical_scope_widening_denied'" ) !== false
			&& is_string( $runtime_source )
			&& strpos( $runtime_source, "'context_bank_owner_records' => (array) ( \$opts['context_bank_owner_records'] ?? array() )" ) !== false;

		$checks = array(
			array( 'label' => 'Context Bank mode policy is registered', 'ok' => $mode_policy_ok, 'detail' => $mode_policy_ok ? 'The server-owned mode policy declares horizontal scope, group denial and a bounded business allowlist.' : 'Context Bank mode policy is missing or over-broad.' ),
			array( 'label' => 'Group private scope denied', 'ok' => $group_ok, 'detail' => $group_ok ? 'Group retrieval resolves to skip with no personal owner.' : 'Group retrieval can inherit private owner scope.' ),
			array( 'label' => 'Unknown vertical denied', 'ok' => $unknown_vertical_ok, 'detail' => $unknown_vertical_ok ? 'Unknown vertical does not expand retrieval scope.' : 'Unknown vertical was accepted.' ),
			array( 'label' => 'Unknown mode denied', 'ok' => $unknown_mode_ok, 'detail' => $unknown_mode_ok ? 'Unknown mode fails closed before retrieval policy expansion.' : 'Unknown mode silently fell back to another retrieval mode.' ),
			array( 'label' => 'Vertical mode is server-owned', 'ok' => $vertical_ok, 'detail' => $vertical_ok ? 'Registered vertical policy resolves from the canonical bridge registry.' : 'Vertical mode is not resolved through the canonical owner.' ),
			array( 'label' => 'Hybrid mode is server-owned', 'ok' => $hybrid_ok, 'detail' => $hybrid_ok ? 'Hybrid mode retains server-owned policy contracts and mode metadata.' : 'Hybrid mode is not resolved through the canonical owner.' ),
			array( 'label' => 'Context Bank Brain mode is bounded', 'ok' => $context_bank_ok, 'detail' => $context_bank_ok ? 'Context Bank mode resolves without a Notebook and excludes private memory contracts by default.' : 'Context Bank mode is missing, requires an unexpected Notebook, or has an over-broad contract allowlist.' ),
			array( 'label' => 'Retrieval budgets bounded', 'ok' => $budget_ok, 'detail' => $budget_ok ? 'Rows, follows, decrypted bytes and elapsed time are capped.' : 'Retrieval budget contract is incomplete.' ),
			array( 'label' => 'Source layer filters before pointer follow', 'ok' => $source_policy_ok, 'detail' => $source_policy_ok ? 'Mode policy contracts are passed to typed search and provenance dedupe remains bounded.' : 'Source layer does not expose the typed policy filter boundary.' ),
			array( 'label' => 'Owner excerpts blend without pointer leakage', 'ok' => $owner_candidates_ok, 'detail' => $owner_candidates_ok ? 'Pointer-only rows are excluded; one verified owner excerpt is deduplicated into the existing W0.20 candidate shape.' : 'Context Bank owner excerpt adapter is missing, leaks pointer-only metadata or duplicates provenance.' ),
			array( 'label' => 'Feature-off parity at Context Bank boundary', 'ok' => $feature_off_ok, 'detail' => $feature_off_ok ? 'Context Bank source lookup returns no refs and performs no source-layer work when the feature flag is off.' : 'Context Bank feature-off boundary did not return the expected empty/degraded result.' ),
			array( 'label' => 'Context Bank MPR flag defaults ON', 'ok' => $flag_default_ok, 'detail' => $flag_default_ok ? 'The flag owner resolves enabled when the option is absent and disabled when the site stores an explicit 0.' : 'The Context Bank MPR flag owner is missing or it does not honour the canonical default/opt-out.' ),
			array( 'label' => 'Context Bank MPR footlog contract', 'ok' => $footlog_ok, 'detail' => $footlog_ok ? 'A bounded filestore contract and writer cover both the retrieved and skipped paths.' : 'The Context Bank MPR footlog contract or writer boundary is missing.' ),
			// [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-1.33D-D8 — new assertions for the D1/D2/D3/5A.3/C11 closure.
			array( 'label' => 'Phase duration and buckets are forwarded', 'ok' => $duration_forward_ok, 'detail' => $duration_forward_ok ? 'The measured phase duration, budget and bounded counters reach the timeline payload, and the skipped path reports status=skipped.' : 'The Context Bank phase duration or its bounded counters are dropped before the timeline payload.' ),
			array( 'label' => 'Phase event pair is registered and emitted', 'ok' => $phase_event_ok, 'detail' => $phase_event_ok ? 'context_bank_started/context_bank_done are declared in the canonical taxonomy with schemas and emitted on both turn paths.' : 'The Context Bank phase event pair is not fully registered in the canonical taxonomy, lacks a schema, or is not emitted on both paths.' ),
			array( 'label' => 'Vertical binding can only narrow', 'ok' => $vertical_narrow_ok, 'detail' => $vertical_narrow_ok ? 'A bound vertical intersects the mode allowlist, an unbound vertical inherits it unchanged, and a widening attempt fails closed.' : 'Vertical contract narrowing is missing, can widen the allowlist, or does not fail closed on a misconfigured binding.' ),
			array( 'label' => 'Dimension filter is static entity_type only', 'ok' => $dimension_filter_ok, 'detail' => $dimension_filter_ok ? 'Only a static entity_type is derived; entity_key stays out of the MVP scope.' : 'Dimension filter derivation accepted an out-of-scope dimension or missed the static entity_type.' ),
			array( 'label' => 'Context Bank phase runs once per turn', 'ok' => $one_footlog_ok, 'detail' => $one_footlog_ok ? 'The phase is memoized per trace_id, the round counter is carried and the retrieve round supplies its round number.' : 'The phase is not memoized per trace_id or the round counter is not wired, so the footlog can repeat per retrieve round.' ),
			array( 'label' => 'Unbound vertical stays byte-identical', 'ok' => $feature_off_parity_ok, 'detail' => $feature_off_parity_ok ? 'With no binding the contract set and dimension filters match the pre-closure shape exactly.' : 'An unbound vertical changed the contract set or added a dimension filter.' ),
			array( 'label' => 'Deadline state is a published contract value', 'ok' => $deadline_contract_ok, 'detail' => $deadline_contract_ok ? 'The done schema publishes ran/skipped/degraded/deadline so a late join stays attributable.' : 'The Context Bank done schema does not publish the full status enum.' ),
			// [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-1.33D-D4 — precision must be measurable, not inferred.
			array( 'label' => 'W0.20 admission funnel is recorded', 'ok' => $admission_funnel_ok, 'detail' => $admission_funnel_ok ? 'The pack reports how many Context Bank owner excerpts entered W0.20, how many survived into the final top5-8, and how many final chunks still score 0.' : 'The Context Bank admission funnel counters are missing or internally inconsistent (admitted < final, or zero-score count above the final count).' ),
			// [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-1.33D §5A.1/§5A.3 — the vertical axis must have a real effect, not just a working helper.
			array( 'label' => 'Vertical binding is wired to the real registry row', 'ok' => $binding_wired_ok, 'detail' => $binding_wired_ok ? 'Exactly one vertical (woo_bizops) carries a hybrid binding, its contracts are a strict subset of the mode allowlist, its static entity_type is derived, and the runtime resolves the binding on both turn paths.' : 'The vertical binding is missing from the registry, bound to more than one vertical, not a strict subset of the mode allowlist, or not resolved on both turn paths.' ),
			array( 'label' => 'Vertical binding is observable and bidirectional', 'ok' => $binding_evidence_ok, 'detail' => $binding_evidence_ok ? 'Before/after contract counts, mode hint, binding source and dimension filters are published per vertical, the widening denial is named, and Context Bank owner records are forwarded to the vertical.' : 'The vertical binding evidence fields are missing, the widening denial is unnamed, or Context Bank data is not forwarded to the vertical.' ),
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
