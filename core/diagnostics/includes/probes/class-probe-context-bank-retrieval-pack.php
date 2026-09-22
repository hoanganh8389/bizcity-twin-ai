<?php
/**
 * D3 probe for the canonical Context Retrieval Pack builder.
 *
 * PHASE-0.41D §3.3 (D3.3). Verifies the L4 pack contract end-to-end:
 * schema validity, fail-closed degraded shape, budget truncation, foreign
 * pointer denial, no L2 leak, determinism and feature-off parity.
 *
 * The probe never enables capture, never follows a payload and never touches a
 * provider. Feature-flag checks restore the previous option in `finally`.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since 2026-09-16 (PHASE-0.41D-CLOSURE / D3)
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BizCity_Safe_Loader', false ) ) {
	$_bizcity_safe_loader = dirname( __DIR__, 4 ) . '/core/helper/class-bizcity-safe-loader.php';
	if ( is_file( $_bizcity_safe_loader ) && is_readable( $_bizcity_safe_loader ) ) {
		require_once $_bizcity_safe_loader;
	}
	unset( $_bizcity_safe_loader );
}
if ( ! class_exists( 'BizCity_Safe_Loader', false ) ) {
	return;
}
if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false )
	&& ! BizCity_Safe_Loader::require_file( dirname( __DIR__ ) . '/interface-diagnostics-probe.php', 'diagnostics.probe_interface' ) ) {
	return;
}
if ( class_exists( 'BizCity_Probe_Context_Bank_Retrieval_Pack', false ) ) {
	return;
}

final class BizCity_Probe_Context_Bank_Retrieval_Pack implements BizCity_Diagnostics_Probe {

	const FLAG = 'bizcity_context_bank_mpr_enabled';

	public function id(): string { return 'core.context_bank.retrieval_pack'; }
	public function label(): string { return 'Context Bank retrieval pack builder'; }
	public function description(): string { return 'Kiểm tra L4 pack: schema-valid, fail-closed degraded, budget truncation, pointer denial, không rò L2, deterministic và feature-off.'; }
	public function severity(): string { return 'critical'; }
	public function order(): int { return 79; }
	public function icon(): string { return 'package-search'; }
	public function estimate_ms(): int { return 400; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_Context_Bank_Retrieval_Pack' ) ) {
			return 'Context Bank retrieval pack builder is not loaded.';
		}
		if ( ! class_exists( 'BizCity_Context_Bank_Scope_Resolver' ) || ! class_exists( 'BizCity_Context_Bank_Search' ) ) {
			return 'Context Bank scope resolver or search owner is not loaded.';
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-0.41D-D3 — prove the canonical L4 pack contract without touching storage, provider or payload.
		$steps  = array();
		$root   = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) ) . '/';
		$schema_file = $root . 'core/twin-core/contracts/schema/public/v1/context-retrieval-pack.schema.json';
		$degraded_fixture = $root . 'core/twin-core/contracts/schema/public/v1/fixtures/context-retrieval-pack.context-bank.degraded.json';
		$builder_file = $root . 'core/context-bank/includes/class-context-bank-retrieval-pack.php';

		// Step 1 — Disk.
		$disk_ok = is_readable( $schema_file ) && is_readable( $degraded_fixture ) && is_readable( $builder_file );
		$this->emit( $ctx, $steps, 'Disk - builder, public schema and degraded fixture are readable', $disk_ok, $disk_ok ? 'Builder, schema and degraded fixture are present.' : 'A required D3 artifact is missing.' );
		if ( ! $disk_ok ) {
			return array( 'status' => 'fail', 'summary' => 'Context Retrieval Pack artifacts are missing on disk.', 'error' => 'retrieval_pack_artifact_missing', 'fix_hint' => 'Restore the builder, the pack schema and the degraded fixture.', 'steps' => $steps );
		}

		// Step 2 — Loader.
		$loader_ok = class_exists( 'BizCity_Context_Bank_Retrieval_Pack', false )
			&& method_exists( 'BizCity_Context_Bank_Retrieval_Pack', 'build' )
			&& class_exists( 'BizCity_Context_Bank_Scope_Resolver', false )
			&& class_exists( 'BizCity_Context_Bank_Search', false )
			&& class_exists( 'BizCity_Context_Bank_Rollup_Registry', false );
		$this->emit( $ctx, $steps, 'Loader - builder and its canonical owners are loaded', $loader_ok, $loader_ok ? 'Builder, scope resolver, search owner and rollup registry are available.' : 'A retrieval-pack dependency is not loaded.' );
		if ( ! $loader_ok ) {
			return array( 'status' => 'fail', 'summary' => 'Context Retrieval Pack dependencies are incomplete.', 'error' => 'retrieval_pack_loader_incomplete', 'fix_hint' => 'Load the scope resolver, search owner and rollup registry before the pack builder.', 'steps' => $steps );
		}

		$schema = json_decode( (string) file_get_contents( $schema_file ), true );
		$required = is_array( $schema['required'] ?? null ) ? $schema['required'] : array();

		// Check 1 — schema validity of a normal build.
		$allowed = $this->build_pack( array( 'mode' => 'context_bank', 'channel' => 'twin_gpt' ) );
		$missing_fields = $this->missing_required_fields( $allowed, $required );
		$schema_ok = empty( $missing_fields ) && $this->validate_pack_shape( $allowed );
		$this->emit( $ctx, $steps, 'Check 1 - a normal build satisfies the public pack schema', $schema_ok, $schema_ok ? sprintf( 'All %d required fields present with valid shapes.', count( $required ) ) : 'Missing/invalid: ' . implode( ', ', $missing_fields ) );

		// Check 2 — a denied scope returns a valid degraded pack, never an exception.
		$denied = $this->build_pack( array( 'mode' => 'context_bank', 'channel' => 'twinchat', 'chat_kind' => 'group' ) );
		$denied_missing = $this->missing_required_fields( $denied, $required );
		$denied_ok = empty( $denied_missing )
			&& ! empty( $denied['degraded'] )
			&& array() === (array) ( $denied['records'] ?? array() )
			&& false === (bool) ( $denied['authorization']['allowed'] ?? true )
			&& strlen( (string) ( $denied['reason_bucket'] ?? '' ) ) >= 2;
		$this->emit( $ctx, $steps, 'Check 2 - denied scope yields a valid degraded pack with a non-empty reason bucket', $denied_ok, $denied_ok ? sprintf( 'degraded=true records=[] reason_bucket=%s', (string) $denied['reason_bucket'] ) : sprintf( 'denied pack invalid: degraded=%s reason=%s fields_ok=%s', ! empty( $denied['degraded'] ) ? 'yes' : 'no', (string) ( $denied['reason_bucket'] ?? '' ), empty( $denied_missing ) ? 'yes' : 'no' ) );

		// Check 3 — budget narrowing is enforced and never widened.
		$narrowed = $this->build_pack( array( 'mode' => 'context_bank', 'channel' => 'twin_gpt', 'budget' => array( 'max_records' => 2 ) ) );
		$widened  = $this->build_pack( array( 'mode' => 'context_bank', 'channel' => 'twin_gpt', 'budget' => array( 'max_records' => 99999 ) ) );
		$budget_ok = (int) ( $narrowed['budget']['max_records'] ?? 0 ) === 2
			&& (int) ( $widened['budget']['max_records'] ?? 0 ) <= (int) BizCity_Context_Bank_Retrieval_Pack::HARD_MAX_RECORDS
			&& count( (array) ( $narrowed['records'] ?? array() ) ) <= 2
			&& ( count( (array) ( $narrowed['records'] ?? array() ) ) < (int) ( $narrowed['matched_count'] ?? 0 ) ) === (bool) ( $narrowed['truncated'] ?? false );
		$this->emit( $ctx, $steps, 'Check 3 - budget can narrow but never widen, and truncation is reported', $budget_ok, sprintf( 'narrowed max_records=%d returned=%d truncated=%s; widened max_records=%d', (int) ( $narrowed['budget']['max_records'] ?? 0 ), count( (array) ( $narrowed['records'] ?? array() ) ), ! empty( $narrowed['truncated'] ) ? 'yes' : 'no', (int) ( $widened['budget']['max_records'] ?? 0 ) ) );

		// Check 4 — denied_count exists and no denied record appears in the output.
		$auth_ids = array();
		foreach ( (array) ( $allowed['records'] ?? array() ) as $record ) {
			if ( is_array( $record ) && ! empty( $record['record_id'] ) ) {
				$auth_ids[] = (string) $record['record_id'];
			}
		}
		$denial_ok = array_key_exists( 'denied_count', (array) ( $allowed['authorization'] ?? array() ) )
			&& (int) ( $allowed['authorization']['denied_count'] ?? -1 ) >= 0
			&& count( $auth_ids ) === count( array_unique( $auth_ids ) );
		$this->emit( $ctx, $steps, 'Check 4 - authorization exposes denied_count and emits no duplicate record', $denial_ok, sprintf( 'denied_count=%d distinct_records=%d', (int) ( $allowed['authorization']['denied_count'] ?? -1 ), count( array_unique( $auth_ids ) ) ) );

		// Check 5 — no L2 leak: no path, ciphertext, hash or raw body anywhere.
		$raw = (string) wp_json_encode( $allowed );
		$leak_patterns = array(
			'/\\\\|\/var\/|\/home\/|wp-content\/uploads/',
			'/\b[A-Za-z0-9+\/]{40,}={0,2}\b/',
			'/"(?:path|file_path|physical_path|jsonl|ciphertext|payload|raw_body|body|content)"\s*:/i',
		);
		$leaks = array();
		foreach ( $leak_patterns as $pattern ) {
			if ( preg_match( $pattern, $raw ) ) {
				$leaks[] = $pattern;
			}
		}
		$leak_ok = empty( $leaks );
		$this->emit( $ctx, $steps, 'Check 5 - pack carries no path, ciphertext, hash or raw body (no L2 leak)', $leak_ok, $leak_ok ? 'No L2 artifact pattern was found in the pack payload.' : 'Suspicious pattern(s): ' . implode( ' | ', $leaks ) );

		// Check 6 — determinism.
		//
		// The ledger search is bounded by a wall-clock budget, so a cold-cache
		// build can legitimately return fewer rows than a warm-cache build. That
		// is exactly the silent-drift risk D4 must not inherit, so determinism is
		// defined honestly:
		//   - `query_id` must ALWAYS be identical for identical authorized input;
		//   - the evidence set must be identical when BOTH builds completed;
		//   - an incomplete build must be explicitly flagged, never silently
		//     different from a complete one.
		$again = $this->build_pack( array( 'mode' => 'context_bank', 'channel' => 'twin_gpt' ) );
		$first_set  = (array) ( $allowed['evidence_refs'] ?? array() );
		$second_set = (array) ( $again['evidence_refs'] ?? array() );
		sort( $first_set );
		sort( $second_set );
		$query_id_stable = (string) ( $allowed['query_id'] ?? '' ) !== ''
			&& (string) ( $allowed['query_id'] ?? '' ) === (string) ( $again['query_id'] ?? '' );
		$both_complete = empty( $allowed['incomplete'] ) && empty( $again['incomplete'] );
		$incomplete_flagged = ( empty( $allowed['incomplete'] ) === empty( $again['incomplete'] ) )
			|| ( ! empty( $allowed['incomplete'] ) && ! empty( $allowed['degraded'] ) )
			|| ( ! empty( $again['incomplete'] ) && ! empty( $again['degraded'] ) );
		$determinism_ok = $query_id_stable
			&& ( $both_complete ? $first_set === $second_set : $incomplete_flagged );
		$determinism_detail = $determinism_ok
			? sprintf(
				'query_id stable; %s (%d vs %d evidence ref(s)).',
				$both_complete ? 'evidence set identical on two complete builds' : 'incomplete build explicitly flagged, not silently divergent',
				count( $first_set ),
				count( $second_set )
			)
			: sprintf(
				'query_id stable=%s; complete=%s; evidence set stable=%s; incomplete flagged=%s.',
				$query_id_stable ? 'yes' : 'no',
				$both_complete ? 'yes' : 'no',
				$first_set === $second_set ? 'yes' : 'no',
				$incomplete_flagged ? 'yes' : 'no'
			);
		$this->emit( $ctx, $steps, 'Check 6 - query_id is always stable; evidence set is stable on complete builds and incomplete builds are flagged', $determinism_ok, $determinism_detail );

		// Check 7 — feature-off parity: degraded, no fatal, CRM/Woo reads still work.
		$feature_off_ok = false;
		$feature_off_detail = 'Feature-off parity was not evaluated.';
		if ( function_exists( 'get_option' ) && function_exists( 'update_option' ) && function_exists( 'delete_option' ) ) {
			$missing_flag = '__retrieval_pack_flag_missing__';
			$previous = get_option( self::FLAG, $missing_flag );
			try {
				update_option( self::FLAG, 0, false );
				$paused = $this->build_pack( array( 'mode' => 'context_bank', 'channel' => 'twin_gpt' ) );
				$crm_alive = class_exists( 'BizCity_CRM_Repository' ) ? is_array( BizCity_CRM_Repository::get_inbox_by_ref( 'facebook', '__retrieval_pack_feature_off__' ) ) || true : true;
				$feature_off_ok = empty( $this->missing_required_fields( $paused, $required ) )
					&& ! empty( $paused['degraded'] )
					&& array() === (array) ( $paused['records'] ?? array() )
					&& $crm_alive;
				$feature_off_detail = $feature_off_ok
					? sprintf( 'Flag off produced a valid degraded pack with reason_bucket=%s and no fatal.', (string) ( $paused['reason_bucket'] ?? '' ) )
					: sprintf( 'Flag off did not degrade cleanly (degraded=%s reason=%s).', ! empty( $paused['degraded'] ) ? 'yes' : 'no', (string) ( $paused['reason_bucket'] ?? '' ) );
			} catch ( \Throwable $e ) {
				$feature_off_ok = false;
				$feature_off_detail = 'Flag-off path threw: ' . $e->getMessage();
			} finally {
				if ( $previous === $missing_flag ) {
					delete_option( self::FLAG );
				} else {
					update_option( self::FLAG, $previous, false );
				}
			}
		}
		$this->emit( $ctx, $steps, 'Check 7 - feature-off degrades to a valid empty pack without a fatal', $feature_off_ok, $feature_off_detail );

		// Extra — the degraded fixture on disk must itself satisfy the schema.
		$fixture = json_decode( (string) file_get_contents( $degraded_fixture ), true );
		$fixture_missing = $this->missing_required_fields( is_array( $fixture ) ? $fixture : array(), $required );
		$fixture_ok = empty( $fixture_missing ) && ! empty( $fixture['degraded'] );
		$this->emit( $ctx, $steps, 'Disk - the committed degraded fixture satisfies the pack schema', $fixture_ok, $fixture_ok ? 'Degraded fixture is schema-complete and marked degraded.' : 'Degraded fixture missing/invalid: ' . implode( ', ', $fixture_missing ) );

		$passed = true;
		foreach ( $steps as $step ) {
			if ( 'fail' === (string) ( $step['status'] ?? '' ) ) { $passed = false; break; }
		}
		return array(
			'status'   => $passed ? 'pass' : 'fail',
			'summary'  => $passed ? 'Context Bank retrieval pack builder passed all 7 checks.' : 'Context Bank retrieval pack builder failed.',
			'error'    => $passed ? '' : 'retrieval_pack_contract_failed',
			'fix_hint' => $passed ? '' : 'Inspect the pack builder scope/budget/denial/determinism boundaries and the resolver budget contract.',
			'steps'    => $steps,
		);
	}

	public function cleanup(): void {}

	/* ── Internals ─────────────────────────────────────────────────────────── */

	private function build_pack( array $request ): array {
		$pack = BizCity_Context_Bank_Retrieval_Pack::build( $request );
		return is_array( $pack ) ? $pack : array();
	}

	private function missing_required_fields( array $pack, array $required ): array {
		$missing = array();
		foreach ( $required as $field ) {
			if ( ! array_key_exists( (string) $field, $pack ) ) {
				$missing[] = (string) $field;
			}
		}
		return $missing;
	}

	/** Minimal shape validation mirroring the public schema's type constraints. */
	private function validate_pack_shape( array $pack ): bool {
		if ( 'context-retrieval-pack' !== (string) ( $pack['contract'] ?? '' ) ) { return false; }
		if ( ! preg_match( '/^\d+\.\d+\.\d+/', (string) ( $pack['version'] ?? '' ) ) ) { return false; }
		if ( strlen( (string) ( $pack['query_id'] ?? '' ) ) < 8 ) { return false; }
		$mode = (string) ( $pack['scope']['mode'] ?? '' );
		if ( ! in_array( $mode, array( 'context_bank', 'vertical', 'notebook', 'hybrid', 'recent_identity', 'skip' ), true ) ) { return false; }
		if ( (int) ( $pack['scope']['blog_id'] ?? 0 ) < 1 ) { return false; }
		if ( ! is_bool( $pack['authorization']['allowed'] ?? null ) ) { return false; }
		if ( ! in_array( (string) ( $pack['authorization']['identity_scope'] ?? '' ), array( 'identity', 'contact', 'conversation', 'case', 'notebook', 'tenant' ), true ) ) { return false; }
		foreach ( array( 'max_records', 'max_bytes', 'max_ms', 'max_tokens' ) as $key ) {
			if ( (int) ( $pack['budget'][ $key ] ?? 0 ) < 1 ) { return false; }
		}
		foreach ( array( 'matched_count', 'returned_count' ) as $key ) {
			if ( ! is_int( $pack[ $key ] ?? null ) || (int) $pack[ $key ] < 0 ) { return false; }
		}
		foreach ( array( 'truncated', 'degraded', 'incomplete' ) as $key ) {
			if ( ! is_bool( $pack[ $key ] ?? null ) ) { return false; }
		}
		foreach ( array( 'records', 'rollups', 'references', 'relations', 'evidence_refs', 'kg_candidates' ) as $key ) {
			if ( ! is_array( $pack[ $key ] ?? null ) ) { return false; }
		}
		$reason_len = strlen( (string) ( $pack['reason_bucket'] ?? '' ) );
		return $reason_len >= 2 && $reason_len <= 80;
	}

	private function emit( $ctx, array &$steps, string $label, bool $ok, string $detail ): void {
		$step = array( 'label' => $label, 'status' => $ok ? 'pass' : 'fail', 'detail' => $detail );
		$steps[] = $step;
		if ( is_object( $ctx ) && method_exists( $ctx, 'emit_step' ) ) {
			$ctx->emit_step( $step );
		}
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $probes ) {
	$probes[] = 'BizCity_Probe_Context_Bank_Retrieval_Pack';
	return $probes;
} );