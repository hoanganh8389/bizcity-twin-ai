<?php
/**
 * D8 rollback drill probe for the Context Bank / One Brain kill switches.
 *
 * PHASE-0.41D §8.3. Proves the per-capability rollback boundary: every
 * documented kill switch maps to a real owner, turning switches off degrades
 * instead of breaking, CRM truth and the pointer ledger are untouched by a
 * rollback, an MCP tool disappears when its policy is off, and re-enabling
 * creates no duplicate state.
 *
 * Boundary: this probe does NOT prove replay-from-receipt (PHASE-0.41D §8.4).
 * That row needs a Context Bank admission fixture and stays open as `D8.4`.
 * Nothing here is production rollback evidence either; the production drill is
 * part of the D7 canary.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since 2026-09-16 (PHASE-0.41D-CLOSURE / D8)
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
if ( class_exists( 'BizCity_Probe_Release_Rollback_Drill', false ) ) {
	return;
}

final class BizCity_Probe_Release_Rollback_Drill implements BizCity_Diagnostics_Probe {

	/** Sentinel for an option that did not exist before the drill. */
	const ABSENT = '__bzdiag_option_absent__';

	/** Kill switches exactly as documented in PHASE-0.41D §8.2. */
	const SWITCHES = array(
		'bizcity_context_bank_channel_capture_enabled',
		'bizcity_context_bank_capture_enabled',
		'bizcity_context_bank_rollups_enabled',
		'bizcity_context_bank_kg_bridge_enabled',
		'bizcity_context_bank_mpr_enabled',
		'bizcity_context_bank_ui_enabled',
	);

	/** MCP tools that must stay hidden until their release gate passes. */
	const GATED_TOOLS = array( 'brain.context.search', 'brain.context.evidence', 'brain.order.summary' );

	/** @var array<string,mixed> Original option values, keyed by option name. */
	private $original_switches = array();

	/** @var mixed Original MCP policy map, or self::ABSENT. */
	private $original_policy = self::ABSENT;

	/** @var string */
	private $cleanup_token = '';

	public function id(): string { return 'core.release.rollback_drill'; }
	public function label(): string { return 'Context Bank / One Brain rollback drill'; }
	public function description(): string { return 'Kiem tra tung kill switch degrade an toan, CRM truth va pointer ledger khong doi, MCP tool bi an dung policy va bat lai khong tao state trung.'; }
	public function severity(): string { return 'critical'; }
	public function order(): int { return 82; }
	public function icon(): string { return 'undo-2'; }
	public function estimate_ms(): int { return 1400; }

	public function precondition() {
		// [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-0.41D-D8 — the drill needs real option writes; a read-only context cannot prove rollback.
		if ( ! function_exists( 'update_option' ) || ! function_exists( 'delete_option' ) ) {
			return 'WordPress option API is unavailable, so the rollback drill cannot toggle a kill switch.';
		}
		if ( ! class_exists( 'BizCity_Context_Bank_Retrieval_Pack' ) ) {
			return 'Context Bank retrieval pack owner is not loaded (D3 artifact).';
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-0.41D-D8 — one drill: snapshot, switch off, prove degradation, switch on, prove no duplicate, restore exactly.
		$steps = array();
		$root  = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) ) . '/';

		// Step 1 — Disk.
		$files = array(
			'pack'     => $root . 'core/context-bank/includes/class-context-bank-retrieval-pack.php',
			'archive'  => $root . 'core/context-bank/includes/class-context-bank-channel-archive-adapter.php',
			'kg'       => $root . 'core/context-bank/includes/class-context-bank-kg-bridge.php',
			'rollup'   => $root . 'core/context-bank/includes/class-context-bank-rollup-worker.php',
			'ledger'   => $root . 'core/context-bank/includes/class-context-bank-ledger.php',
		);
		$missing_files = array();
		foreach ( $files as $key => $path ) {
			if ( ! is_readable( $path ) ) {
				$missing_files[] = $key;
			}
		}
		$disk_ok = empty( $missing_files );
		$this->emit( $ctx, $steps, 'Disk - every rollback owner artifact is readable', $disk_ok, $disk_ok ? 'Retrieval pack, archive adapter, KG bridge, rollup worker and ledger are present.' : 'Missing owner artifact(s): ' . implode( ', ', $missing_files ) );
		if ( ! $disk_ok ) {
			return $this->fail( $steps, 'Rollback owner artifacts are missing on disk.', 'rollback_owner_missing', 'Restore the Context Bank owners before running the rollback drill.' );
		}

		// Step 2 — the documented kill-switch matrix must match the deployed owners.
		$matrix = array();
		$matrix['bizcity_context_bank_channel_capture_enabled'] = class_exists( 'BizCity_Context_Bank_Channel_Archive_Adapter' )
			&& defined( 'BizCity_Context_Bank_Channel_Archive_Adapter::FEATURE_FLAG' )
			&& 'bizcity_context_bank_channel_capture_enabled' === BizCity_Context_Bank_Channel_Archive_Adapter::FEATURE_FLAG;
		$matrix['bizcity_context_bank_capture_enabled'] = class_exists( 'BizCity_Context_Bank_Event_Stream_Adapter' )
			&& defined( 'BizCity_Context_Bank_Event_Stream_Adapter::FEATURE_FLAG' )
			&& 'bizcity_context_bank_capture_enabled' === BizCity_Context_Bank_Event_Stream_Adapter::FEATURE_FLAG;
		$matrix['bizcity_context_bank_kg_bridge_enabled'] = class_exists( 'BizCity_Context_Bank_KG_Bridge' )
			&& defined( 'BizCity_Context_Bank_KG_Bridge::FEATURE_FLAG' )
			&& 'bizcity_context_bank_kg_bridge_enabled' === BizCity_Context_Bank_KG_Bridge::FEATURE_FLAG;
		$matrix['bizcity_context_bank_mpr_enabled'] = defined( 'BizCity_Context_Bank_Retrieval_Pack::FEATURE_FLAG' )
			&& 'bizcity_context_bank_mpr_enabled' === BizCity_Context_Bank_Retrieval_Pack::FEATURE_FLAG;
		// The rollup worker reads its option inline; assert the deployed source, not a constant that does not exist.
		$matrix['bizcity_context_bank_rollups_enabled'] = false !== strpos( (string) file_get_contents( $files['rollup'] ), 'bizcity_context_bank_rollups_enabled' );
		$broken = array();
		foreach ( $matrix as $option => $ok ) {
			if ( ! $ok ) {
				$broken[] = $option;
			}
		}
		$matrix_ok = empty( $broken );
		$this->emit( $ctx, $steps, 'Contract - documented kill switches map to a deployed owner', $matrix_ok, $matrix_ok ? 'All five capability switches resolve to their real owner flag.' : 'Documented switch(es) without a deployed owner: ' . implode( ', ', $broken ) );

		// Step 3 — snapshot everything the drill is allowed to touch.
		foreach ( self::SWITCHES as $option ) {
			$this->original_switches[ $option ] = get_option( $option, self::ABSENT );
		}
		if ( class_exists( 'BizCity_MCP_Tool_Policy' ) && defined( 'BizCity_MCP_Tool_Policy::OPTION' ) ) {
			$this->original_policy = get_option( BizCity_MCP_Tool_Policy::OPTION, self::ABSENT );
		}
		$fixture = class_exists( 'BizCity_CRM_Inbox_Fixture_Factory' )
			? BizCity_CRM_Inbox_Fixture_Factory::build( array(
				'channel'           => 'facebook',
				'users'             => 1,
				'accounts_per_user' => 1,
				'with_conversation' => true,
				'with_messages'     => 2,
			) )
			: array( 'ok' => false, 'reason' => 'fixture_factory_unavailable' );
		if ( empty( $fixture['ok'] ) ) {
			$this->emit( $ctx, $steps, 'Runtime - disposable CRM truth fixture', false, sprintf( 'Fixture build failed: %s', (string) ( $fixture['reason'] ?? 'unknown' ) ) );
			return $this->fail( $steps, 'Không dựng được fixture CRM để chứng minh truth không đổi.', 'rollback_fixture_unavailable', 'Kiểm tra CRM repository/schema trên blog mục tiêu rồi rerun probe.' );
		}
		$this->cleanup_token = (string) $fixture['cleanup_token'];
		$expect        = BizCity_CRM_Inbox_Fixture_Factory::expectations( $this->cleanup_token );
		$target        = isset( $expect['business_inboxes'][0] ) ? $expect['business_inboxes'][0] : array();
		$conversation  = (int) ( $target['conversation_id'] ?? 0 );
		$crm_before    = $this->crm_counts( $conversation );
		$ledger_before = $this->ledger_rows();
		$this->emit( $ctx, $steps, 'Runtime - disposable CRM truth fixture and baseline snapshot', true, sprintf( 'messages=%d conversations=%d ledger_rows=%d', $crm_before['messages'], $crm_before['conversations'], $ledger_before ) );

		// Step 4 — every switch off: degrade, never break.
		foreach ( self::SWITCHES as $option ) {
			update_option( $option, 0, false );
		}
		$off_pack = BizCity_Context_Bank_Retrieval_Pack::build( array( 'mode' => 'context_bank', 'channel' => 'twin_gpt' ) );
		$off_pack = is_array( $off_pack ) ? $off_pack : array();
		$required = array( 'contract', 'version', 'query_id', 'scope', 'authorization', 'budget', 'records', 'rollups', 'evidence_refs', 'degraded', 'incomplete', 'reason_bucket' );
		$missing_fields = array();
		foreach ( $required as $field ) {
			if ( ! array_key_exists( $field, $off_pack ) ) {
				$missing_fields[] = $field;
			}
		}
		$degraded_ok = empty( $missing_fields )
			&& true === ( $off_pack['degraded'] ?? null )
			&& array() === ( $off_pack['records'] ?? null )
			&& '' !== (string) ( $off_pack['reason_bucket'] ?? '' );
		$this->emit( $ctx, $steps, 'Rollback - retrieval pack degrades into a valid empty pack instead of failing', $degraded_ok, $degraded_ok ? sprintf( 'degraded=true records=0 reason_bucket=%s', (string) $off_pack['reason_bucket'] ) : ( empty( $missing_fields ) ? 'Pack did not degrade as required.' : 'Missing pack field(s): ' . implode( ', ', $missing_fields ) ) );

		// Step 5 — CRM truth and the pointer ledger are untouched by the rollback.
		$crm_after_off    = $this->crm_counts( $conversation );
		$ledger_after_off = $this->ledger_rows();
		$truth_ok = $crm_after_off === $crm_before && $ledger_after_off === $ledger_before;
		$this->emit( $ctx, $steps, 'Rollback - CRM truth and pointer ledger are unchanged while every switch is off', $truth_ok, sprintf( 'messages %d->%d conversations %d->%d ledger %d->%d', $crm_before['messages'], $crm_after_off['messages'], $crm_before['conversations'], $crm_after_off['conversations'], $ledger_before, $ledger_after_off ) );

		// Step 6 — the C read path still answers from CRM while Context Bank is off.
		$read_ok = false;
		$read_detail = 'TwinWeb REST owner is not loaded; C read path was not exercised.';
		if ( class_exists( 'BizCity_TwinWeb_REST' ) && ! empty( $target ) ) {
			$previous_user = (int) get_current_user_id();
			wp_set_current_user( (int) $target['member_user_id'] );
			$request = new WP_REST_Request( 'GET', '/bizcity-twinweb/v1/crm/inbox' );
			$request->set_param( 'channel', (string) $target['channel'] );
			$request->set_param( 'ref', (string) $target['ref'] );
			$response = BizCity_TwinWeb_REST::instance()->get_crm_exact_inbox( $request );
			$data = ( is_object( $response ) && method_exists( $response, 'get_data' ) ) ? (array) $response->get_data() : array();
			wp_set_current_user( $previous_user );
			$read_ok = ! empty( $data['success'] ) && (int) ( $data['inbox']['id'] ?? 0 ) === (int) $target['inbox_id'];
			$read_detail = $read_ok ? 'Employee console still reads CRM conversations with Context Bank disabled.' : 'C read path did not return the exact inbox while switches were off.';
		}
		$this->emit( $ctx, $steps, 'Rollback - the employee read path keeps working from CRM truth', $read_ok, $read_detail );

		// Step 7 — MCP gated tools stay hidden by policy and appear only when explicitly enabled.
		$mcp_ok = false;
		$mcp_detail = 'MCP tool registry/policy is not loaded; the tool gate was not exercised.';
		if ( class_exists( 'BizCity_MCP_Tool_Registry' ) && class_exists( 'BizCity_MCP_Tool_Policy' ) ) {
			$hidden_names = $this->tool_names( BizCity_MCP_Tool_Registry::list_descriptors( true ) );
			$leaked = array_values( array_intersect( self::GATED_TOOLS, $hidden_names ) );
			$all_names = $this->tool_names( BizCity_MCP_Tool_Registry::list_descriptors( false ) );
			$registered = array_values( array_intersect( self::GATED_TOOLS, $all_names ) );
			$enabled_names = array();
			if ( ! empty( $registered ) && method_exists( 'BizCity_MCP_Tool_Policy', 'save' ) ) {
				BizCity_MCP_Tool_Policy::save( $registered, $all_names );
				$enabled_names = array_values( array_intersect( self::GATED_TOOLS, $this->tool_names( BizCity_MCP_Tool_Registry::list_descriptors( true ) ) ) );
			}
			$mcp_ok = empty( $leaked ) && ! empty( $registered ) && count( $enabled_names ) === count( $registered );
			$mcp_detail = sprintf( 'registered=%d advertised_while_off=%d advertised_after_enable=%d', count( $registered ), count( $leaked ), count( $enabled_names ) );
		}
		$this->emit( $ctx, $steps, 'Rollback - gated MCP tools are advertised only when their policy is on', $mcp_ok, $mcp_detail );

		// Step 8 — re-enable: no duplicate state, deterministic pack.
		foreach ( self::SWITCHES as $option ) {
			update_option( $option, 1, false );
		}
		$on_pack_one = BizCity_Context_Bank_Retrieval_Pack::build( array( 'mode' => 'context_bank', 'channel' => 'twin_gpt' ) );
		$on_pack_two = BizCity_Context_Bank_Retrieval_Pack::build( array( 'mode' => 'context_bank', 'channel' => 'twin_gpt' ) );
		$ledger_after_on = $this->ledger_rows();
		$crm_after_on    = $this->crm_counts( $conversation );
		$reenable_ok = is_array( $on_pack_one ) && is_array( $on_pack_two )
			&& (string) ( $on_pack_one['query_id'] ?? 'a' ) === (string) ( $on_pack_two['query_id'] ?? 'b' )
			&& $ledger_after_on === $ledger_before
			&& $crm_after_on === $crm_before;
		$this->emit( $ctx, $steps, 'Recovery - re-enabling creates no duplicate pointer, rollup or CRM row', $reenable_ok, sprintf( 'query_id_stable=%s ledger %d->%d messages %d->%d', ( (string) ( $on_pack_one['query_id'] ?? '' ) === (string) ( $on_pack_two['query_id'] ?? '' ) ) ? 'true' : 'false', $ledger_before, $ledger_after_on, $crm_before['messages'], $crm_after_on['messages'] ) );

		// Step 9 — restore every switch to its exact pre-drill value.
		$restore = $this->restore_state();
		$this->emit( $ctx, $steps, 'Restore - every switch and the MCP policy return to their pre-drill value', $restore['ok'], $restore['detail'] );

		$passed = true;
		foreach ( $steps as $step ) {
			if ( 'fail' === (string) ( $step['status'] ?? '' ) ) {
				$passed = false;
				break;
			}
		}
		return array(
			'status'   => $passed ? 'pass' : 'fail',
			'summary'  => $passed ? 'Per-capability rollback drill passed; replay-from-receipt (D8.4) and the production drill (D7) remain open.' : 'Rollback drill failed.',
			'error'    => $passed ? '' : 'rollback_drill_failed',
			'fix_hint' => $passed ? '' : 'Inspect the kill-switch matrix, degraded pack contract, CRM/ledger invariants and the MCP tool policy gate.',
			'steps'    => $steps,
		);
	}

	public function cleanup(): void {
		// [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-0.41D-D8 — a drill that cannot restore its own switches is worse than no drill.
		$this->restore_state();
	}

	/**
	 * Restore switches, MCP policy and fixtures to their pre-drill state.
	 *
	 * @return array{ok:bool,detail:string}
	 */
	private function restore_state(): array {
		$failed = array();
		foreach ( $this->original_switches as $option => $value ) {
			if ( self::ABSENT === $value ) {
				delete_option( $option );
				if ( self::ABSENT !== get_option( $option, self::ABSENT ) ) {
					$failed[] = $option;
				}
				continue;
			}
			update_option( $option, $value, false );
			if ( get_option( $option, self::ABSENT ) != $value ) { // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison -- option storage may normalise scalars.
				$failed[] = $option;
			}
		}
		$this->original_switches = array();

		if ( self::ABSENT !== $this->original_policy && class_exists( 'BizCity_MCP_Tool_Policy' ) && defined( 'BizCity_MCP_Tool_Policy::OPTION' ) ) {
			update_option( BizCity_MCP_Tool_Policy::OPTION, $this->original_policy, false );
		} elseif ( self::ABSENT === $this->original_policy && class_exists( 'BizCity_MCP_Tool_Policy' ) && defined( 'BizCity_MCP_Tool_Policy::OPTION' ) ) {
			delete_option( BizCity_MCP_Tool_Policy::OPTION );
		}
		$this->original_policy = self::ABSENT;

		$remaining = 0;
		if ( '' !== $this->cleanup_token && class_exists( 'BizCity_CRM_Inbox_Fixture_Factory' ) ) {
			$result = BizCity_CRM_Inbox_Fixture_Factory::destroy( $this->cleanup_token );
			$this->cleanup_token = '';
			$remaining = (int) ( $result['remaining'] ?? 0 );
		}

		$ok = empty( $failed ) && 0 === $remaining;
		return array(
			'ok'     => $ok,
			'detail' => $ok ? 'All kill switches, the MCP policy and every fixture row were restored.' : sprintf( 'unrestored=%s fixture_remaining=%d', empty( $failed ) ? 'none' : implode( ',', $failed ), $remaining ),
		);
	}

	/**
	 * Count CRM truth rows the drill must never change.
	 *
	 * @param int $conversation_id Fixture conversation.
	 * @return array{messages:int,conversations:int}
	 */
	private function crm_counts( int $conversation_id ): array {
		$messages = 0;
		$conversations = 0;
		if ( class_exists( 'BizCity_CRM_Repository' ) && $conversation_id > 0 ) {
			$rows = BizCity_CRM_Repository::list_messages( $conversation_id, 200, 0 );
			$messages = is_array( $rows ) ? count( $rows ) : 0;
			$conversation = BizCity_CRM_Repository::get_conversation( $conversation_id );
			$conversations = is_array( $conversation ) ? 1 : 0;
		}
		return array( 'messages' => $messages, 'conversations' => $conversations );
	}

	/** Count pointer ledger rows for the current tenant. */
	private function ledger_rows(): int {
		if ( ! class_exists( 'BizCity_Context_Bank_Ledger' ) || ! method_exists( 'BizCity_Context_Bank_Ledger', 'table' ) ) {
			return -1;
		}
		global $wpdb;
		$table = (string) BizCity_Context_Bank_Ledger::table();
		if ( '' === $table || ! function_exists( 'bizcity_tbl_exists' ) || ! bizcity_tbl_exists( $table ) ) {
			return -1;
		}
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name resolved by the ledger owner.
	}

	/**
	 * Extract tool names from a descriptor list.
	 *
	 * @param array $descriptors Tool descriptors.
	 * @return array<int,string>
	 */
	private function tool_names( $descriptors ): array {
		$names = array();
		foreach ( (array) $descriptors as $descriptor ) {
			if ( is_array( $descriptor ) && ! empty( $descriptor['name'] ) ) {
				$names[] = (string) $descriptor['name'];
			}
		}
		return $names;
	}

	/**
	 * Build a failing probe result after restoring state.
	 *
	 * @param array  $steps    Steps collected so far.
	 * @param string $summary  Vietnamese summary.
	 * @param string $error    Machine error code.
	 * @param string $fix_hint Actionable hint.
	 * @return array
	 */
	private function fail( array $steps, string $summary, string $error, string $fix_hint ): array {
		$this->restore_state();
		return array( 'status' => 'fail', 'summary' => $summary, 'error' => $error, 'fix_hint' => $fix_hint, 'steps' => $steps );
	}

	/**
	 * Append and stream one step.
	 *
	 * @param mixed  $ctx    Probe context.
	 * @param array  $steps  Step accumulator.
	 * @param string $label  Step label.
	 * @param bool   $ok     Pass flag.
	 * @param string $detail Step detail.
	 * @return void
	 */
	private function emit( $ctx, array &$steps, string $label, bool $ok, string $detail ): void {
		$step = array( 'label' => $label, 'status' => $ok ? 'pass' : 'fail', 'detail' => $detail );
		$steps[] = $step;
		if ( is_object( $ctx ) && method_exists( $ctx, 'emit_step' ) ) {
			$ctx->emit_step( $step );
		}
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $probes ) {
	$probes[] = 'BizCity_Probe_Release_Rollback_Drill';
	return $probes;
} );
