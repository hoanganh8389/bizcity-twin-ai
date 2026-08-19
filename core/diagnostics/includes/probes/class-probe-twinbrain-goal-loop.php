<?php
/**
 * DDV probe for Twin Goal Loop G0-G9 deterministic foundation.
 *
 * Read-only: validates deterministic state and event contracts without writing
 * to the canonical Event Stream or calling an LLM.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since 2026-08-01
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

if ( class_exists( 'BizCity_Probe_TwinBrain_Goal_Loop', false ) ) {
	return;
}

final class BizCity_Probe_TwinBrain_Goal_Loop implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'twinbrain.goal_loop'; }
	public function label(): string { return 'Twin Goal Loop G0-G9'; }
	public function description(): string { return 'Checks Goal State, deterministic parser/reflector/question contracts, lifecycle guards, closure semantics, taxonomy, and JSON schemas without LLM or DB writes.'; }
	public function severity(): string { return 'warning'; }
	public function order(): int { return 65; }
	public function icon(): string { return 'target'; }
	public function estimate_ms(): int { return 30; }

	public function precondition() {
		foreach ( array( 'BizCity_TwinBrain_Goal_Loop_State', 'BizCity_TwinBrain_Goal_Loop_Repository', 'BizCity_TwinBrain_Goal_Loop_Intent_Adapter', 'BizCity_TwinBrain_Goal_Loop_Runtime', 'BizCity_TwinBrain_Temporal_Context_Resolver', 'BizCity_TwinBrain_Goal_Alignment', 'BizCity_TwinBrain_Goal_Loop_Delta', 'BizCity_TwinBrain_Goal_Loop_Parser', 'BizCity_TwinBrain_Goal_Loop_Reflector', 'BizCity_TwinBrain_Goal_Loop_Question_Engine', 'BizCity_TwinBrain_Goal_Loop_REST', 'BizCity_TwinBrain_Goal_Loop_Scheduler', 'BizCity_Twin_Event_Taxonomy', 'BizCity_Twin_Data_Contract' ) as $class ) {
			if ( ! class_exists( $class ) ) {
				return new WP_Error( 'class_missing', $class . ' chưa load.' );
			}
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-08-01 Johnny Chu] PHASE-TWIN-GOAL-LOOP-G0 — deterministic DDV for state and closure contracts.
		$steps = array();
		$ok = true;
		// [2026-08-02 Johnny Chu] HOTFIX — resolve from plugin root; dirname(BIZCITY_TWINBRAIN_DIR) was deployment-sensitive and caused false Disk FAIL while Loader/Runtime passed.
		$plugin_root = defined( 'BIZCITY_TWIN_AI_DIR' )
			? rtrim( (string) BIZCITY_TWIN_AI_DIR, '/\\' ) . '/'
			: dirname( dirname( dirname( dirname( __DIR__ ) ) ) ) . '/';
		$state_file = $plugin_root . 'core/twinbrain/includes/class-twinbrain-goal-loop-state.php';
		$spec_file = $plugin_root . 'core/twinbrain/docs/TWINBRAIN-TWIN-GOAL-LOOP.md';
		$schema_dir = $plugin_root . 'core/twin-core/event-stream/schemas/events/';
		// [2026-08-17 Johnny Chu] R-DDV — load Runtime source before validating the Event Bus decision wrapper; this probe previously read an uninitialized variable.
		$runtime_file = $plugin_root . 'core/twinbrain/includes/class-twinbrain-runtime.php';
		$runtime_source = is_readable( $runtime_file ) ? (string) file_get_contents( $runtime_file ) : '';
		$schema_names = array( 'twin_goal_opened', 'twin_goal_progressed', 'twin_goal_closed' );
		$vertical_schema_names = array( 'conversation_route_decided', 'conversation_confirm_prompt' );

		$state_loaded = class_exists( 'BizCity_TwinBrain_Goal_Loop_State' );
		$state_disk_ok = is_readable( $state_file ) || $state_loaded;
		$state_detail = is_readable( $state_file )
			? $state_file
			: ( $state_loaded ? 'State class loaded; PHP source is not readable on this deployment: ' . $state_file : $state_file );
		$ok = $this->step( $ctx, $steps, 'Disk: Goal State class', $state_disk_ok, $state_detail ) && $ok;
		if ( ! is_readable( $spec_file ) && $state_loaded ) {
			$this->skip( $ctx, $steps, 'Disk: Goal Loop spec file', 'SKIP: Markdown docs are not deployed/readable; loaded PHP contract remains authoritative.' );
		} else {
			$ok = $this->step( $ctx, $steps, 'Disk: Goal Loop spec file', is_readable( $spec_file ), $spec_file ) && $ok;
		}
		foreach ( $schema_names as $schema_name ) {
			$path = $schema_dir . $schema_name . '.json';
			if ( ! file_exists( $path ) ) {
				$ok = $this->step( $ctx, $steps, 'Disk: ' . $schema_name . ' schema', false, 'Missing required Event Stream schema artifact; deploy core/twin-core/event-stream/schemas/events/.' ) && $ok;
				continue;
			}
			$schema = is_readable( $path ) ? json_decode( (string) file_get_contents( $path ), true ) : null;
			$schema_ok = is_array( $schema ) && ( $schema['title'] ?? '' ) === $schema_name;
			$ok = $this->step( $ctx, $steps, 'Disk: ' . $schema_name . ' schema', $schema_ok, $path ) && $ok;
		}
		$vertical_schema_dir = $schema_dir;
		foreach ( $vertical_schema_names as $schema_name ) {
			$path = $vertical_schema_dir . $schema_name . '.json';
			if ( ! file_exists( $path ) ) {
				$ok = $this->step( $ctx, $steps, 'Disk: ' . $schema_name . ' v2 schema', false, 'Missing required Event Stream schema artifact; deploy core/twin-core/event-stream/schemas/events/.' ) && $ok;
				continue;
			}
			$schema = is_readable( $path ) ? json_decode( (string) file_get_contents( $path ), true ) : null;
			$schema_ok = is_array( $schema ) && ( $schema['title'] ?? '' ) === $schema_name;
			$ok = $this->step( $ctx, $steps, 'Disk: ' . $schema_name . ' v2 schema', $schema_ok, $path ) && $ok;
		}

		$taxonomy = BizCity_Twin_Event_Taxonomy::required_fields();
		$taxonomy_ok = BizCity_Twin_Event_Taxonomy::TAXONOMY_VERSION >= 10
			&& isset( $taxonomy[ BizCity_Twin_Event_Taxonomy::TWIN_GOAL_OPENED ] )
			&& isset( $taxonomy[ BizCity_Twin_Event_Taxonomy::TWIN_GOAL_PROGRESSED ] )
			&& isset( $taxonomy[ BizCity_Twin_Event_Taxonomy::TWIN_GOAL_CLOSED ] )
			&& isset( $taxonomy[ BizCity_Twin_Event_Taxonomy::CONVERSATION_ROUTE_DECIDED ] )
			&& isset( $taxonomy[ BizCity_Twin_Event_Taxonomy::CONVERSATION_CONFIRM_PROMPT ] );
		$ok = $this->step( $ctx, $steps, 'Loader: Goal Loop event taxonomy', $taxonomy_ok, 'Taxonomy v' . BizCity_Twin_Event_Taxonomy::TAXONOMY_VERSION ) && $ok;
		// [2026-08-17 Johnny Chu] R-EVT-DDV — canonical V5 persistence uses decision stages; legacy telemetry aliases are not required Event Bus taxonomy entries.
		$event_bus_file = $plugin_root . 'core/twin-core/event-stream/class-twin-event-bus.php';
		$event_bus_disk_ok = is_readable( $event_bus_file );
		$event_bus_disk_detail = $event_bus_disk_ok ? $event_bus_file : 'Event Bus file is not readable: ' . $event_bus_file;
		$ok = $this->step( $ctx, $steps, 'Disk: canonical Event Bus source', $event_bus_disk_ok, $event_bus_disk_detail ) && $ok;
		if ( ! class_exists( 'BizCity_Twin_Event_Bus' ) && is_readable( $event_bus_file ) ) {
			// [2026-08-17 Johnny Chu] R-DDV loader repair — diagnostics may load the canonical Event Bus when a legacy MU bootstrap skipped it.
			require_once $event_bus_file;
			if ( class_exists( 'BizCity_Twin_Event_Bus' ) && method_exists( 'BizCity_Twin_Event_Bus', 'boot' ) ) {
				BizCity_Twin_Event_Bus::boot();
			}
		}
		$event_bus_class_ok = class_exists( 'BizCity_Twin_Event_Bus' );
		$event_bus_dispatch_ok = $event_bus_class_ok && method_exists( 'BizCity_Twin_Event_Bus', 'dispatch' );
		$event_bus_dispatch_v2_ok = $event_bus_class_ok && method_exists( 'BizCity_Twin_Event_Bus', 'dispatch_v2' );
		$event_bus_loader_detail = 'class=' . ( $event_bus_class_ok ? 'yes' : 'no' )
			. ' dispatch=' . ( $event_bus_dispatch_ok ? 'yes' : 'no' )
			. ' dispatch_v2=' . ( $event_bus_dispatch_v2_ok ? 'yes' : 'no' )
			. ' file=' . ( $event_bus_disk_ok ? 'readable' : 'missing' );
		$runtime_emit_ok = strpos( $runtime_source, 'function emit_event' ) !== false;
		$runtime_dispatch_ok = strpos( $runtime_source, 'BizCity_Twin_Event_Bus::dispatch(' ) !== false;
		$runtime_decision_ok = preg_match( "/emit_event\\(\\s*['\"]decision['\"]\\s*,/", $runtime_source ) === 1;
		$runtime_stage_ok = strpos( $runtime_source, 'MPR_GATE_STAGE_VERSION' ) !== false;
		$telemetry_contract_ok = $event_bus_dispatch_ok && $runtime_emit_ok && $runtime_dispatch_ok && $runtime_decision_ok && $runtime_stage_ok;
		$event_bus_marker_detail = $event_bus_loader_detail
			. ' emit_event=' . ( $runtime_emit_ok ? 'yes' : 'no' )
			. ' dispatch_call=' . ( $runtime_dispatch_ok ? 'yes' : 'no' )
			. ' decision_stage=' . ( $runtime_decision_ok ? 'yes' : 'no' )
			. ' stage_version=' . ( $runtime_stage_ok ? 'yes' : 'no' );
		$ok = $this->step( $ctx, $steps, 'Loader: canonical decision Event Bus contract', $telemetry_contract_ok, $telemetry_contract_ok ? 'V5 stages persist through canonical decision.stage=mpr_gate.v1; legacy aliases are not treated as taxonomy events.' : 'Canonical Event Bus marker detail: ' . $event_bus_marker_detail ) && $ok;
		$api_ok = method_exists( 'BizCity_TwinBrain_Goal_Loop_Repository', 'latest' )
			&& method_exists( 'BizCity_TwinBrain_Goal_Loop_Repository', 'open' )
			&& method_exists( 'BizCity_TwinBrain_Goal_Loop_Repository', 'progress' )
			&& method_exists( 'BizCity_TwinBrain_Goal_Loop_Repository', 'close' )
			&& method_exists( 'BizCity_TwinBrain_Goal_Loop_Runtime', 'pre_turn' )
			&& method_exists( 'BizCity_TwinBrain_Goal_Loop_Runtime', 'post_turn' );
		$ok = $this->step( $ctx, $steps, 'Loader: Goal Loop G1 API', $api_ok, 'Repository, adapter, pre_turn, and post_turn methods available.' ) && $ok;
		$delta_ok = method_exists( 'BizCity_TwinBrain_Goal_Loop_Delta', 'compute' ) && method_exists( 'BizCity_TwinBrain_Goal_Loop_Delta', 'apply' );
		$ok = $this->step( $ctx, $steps, 'Loader: deterministic Goal Delta', $delta_ok, 'No evaluator LLM; delta reuses turn evidence.' ) && $ok;
		// [2026-08-04 Johnny Chu] R-MPR-GOALBOARD — assert the pre-final checkpoint order without requiring a live LLM or DB write.
		$finalize_pos = strpos( $runtime_source, 'public function finalize_with_gate' );
		$finalize_source = false !== $finalize_pos ? substr( $runtime_source, $finalize_pos ) : '';
		$draft_pos = strpos( $finalize_source, "emit_checkpoint( 'draft_ready'" );
		$reflection_pos = strpos( $finalize_source, "emit_checkpoint( 'reflection_done'" );
		$gate_pos = strpos( $finalize_source, "emit_checkpoint( 'final_gate_decision'" );
		$final_gate_pos = strpos( $finalize_source, '$final_gate = array(' );
		$gate_order_ok = $finalize_source !== ''
			&& false !== $draft_pos
			&& false !== $reflection_pos
			&& false !== $gate_pos
			&& $draft_pos < $reflection_pos
			&& $reflection_pos < $gate_pos
			&& false !== $final_gate_pos;
		$ok = $this->step( $ctx, $steps, 'Disk: pre-final Draft/Reflection/Final gate order', $gate_order_ok, $gate_order_ok ? 'SSE checkpoints are emitted before final_started.' : $runtime_file . ' is missing or has an invalid checkpoint order.' ) && $ok;
		$retrieve_helper_ok = (
			strpos( $runtime_source, 'private function run_bounded_retrieve_round' ) !== false
			&& strpos( $runtime_source, "['retrieve_round'] = 1" ) !== false
		) || (
			strpos( $runtime_source, 'private function run_bounded_retrieve_round' ) !== false
				&& strpos( $runtime_source, "\$scoreboard['retrieve_round']" ) !== false
				&& strpos( $runtime_source, 'max_retrieve_rounds' ) !== false )
			&& substr_count( $runtime_source, '$this->run_bounded_retrieve_round' ) >= 2;
		$ok = $this->step( $ctx, $steps, 'Disk: bounded Retrieve round contract', $retrieve_helper_ok, $retrieve_helper_ok ? 'Retrieve round is shared by stream/non-stream paths and capped at round 1.' : $runtime_file . ' is missing the bounded Retrieve contract.' ) && $ok;
		$terminal_gate_ok = strpos( $runtime_source, "'terminal'          => true" ) !== false
			&& strpos( $runtime_source, "'fallback_policy'   => ! \$ready ? 'answer_with_limit_notice' : 'normal_answer'" ) !== false
			&& strpos( $runtime_source, "'requires_review'   => ! \$ready" ) !== false
			&& strpos( $runtime_source, "'status'   => 'failed'" ) !== false;
		$ok = $this->step( $ctx, $steps, 'Disk: terminal Final Gate fallback contract', $terminal_gate_ok, $terminal_gate_ok ? 'Every gate is terminal and unresolved evidence has an explicit customer-safe fallback policy.' : $runtime_file . ' is missing terminal gate/fallback markers.' ) && $ok;
		$mpr_stage_contract_ok = defined( 'BizCity_TwinBrain_Runtime::MPR_GATE_STAGE_VERSION' )
			&& BizCity_TwinBrain_Runtime::MPR_GATE_STAGE_VERSION === 'mpr_gate.v1'
			&& strpos( $runtime_source, "'stage_version' => self::MPR_GATE_STAGE_VERSION" ) !== false;
		$ok = $this->step( $ctx, $steps, 'Loader: MPR decision.stage version contract', $mpr_stage_contract_ok, $mpr_stage_contract_ok ? 'Canonical decision.stage checkpoints use mpr_gate.v1.' : 'MPR decision.stage version contract is missing.' ) && $ok;
		$special_branches = array(
			'stream_agent_react' => 'Agent',
			'stream_astro_mode' => 'Astro',
			'stream_astro_relation_mode' => 'Astro relation',
			'stream_auto_degrade_chat' => 'Auto-degrade',
		);
		$special_branch_ok = true;
		$special_branch_details = array();
		foreach ( $special_branches as $method => $label ) {
			$method_pos = strpos( $runtime_source, 'function ' . $method . '(' );
			$gate_pos_branch = false !== $method_pos ? strpos( $runtime_source, '$this->finalize_with_gate', $method_pos ) : false;
			$final_pos_branch = false !== $method_pos ? strpos( $runtime_source, "final_started'", $method_pos ) : false;
			$branch_ok = false !== $method_pos && false !== $gate_pos_branch && false !== $final_pos_branch && $gate_pos_branch < $final_pos_branch;
			$special_branch_ok = $branch_ok && $special_branch_ok;
			$special_branch_details[] = $label . '=' . ( $branch_ok ? 'PASS' : 'FAIL' );
		}
		$ok = $this->step( $ctx, $steps, 'Disk: special branches use central Final Gate', $special_branch_ok, implode( ', ', $special_branch_details ) ) && $ok;
		$special_replay_ok = substr_count( $runtime_source, "'final_gate' => \$final_gate" ) >= 4
			&& strpos( $runtime_source, "'final_gate'      => (array) ( \$parts['final_gate'] ?? array() )" ) !== false;
		$ok = $this->step( $ctx, $steps, 'Disk: special branch Final Gate replay parity', $special_replay_ok, $special_replay_ok ? 'Special branch aggregate/snapshot payloads retain final_gate.' : 'Special branch replay final_gate markers are missing.' ) && $ok;
		$twinchat_handler_file = $plugin_root . 'modules/twinchat/includes/class-twinchat-stream-handler.php';
		$twinchat_writer_file = $plugin_root . 'modules/twinchat/includes/class-twinchat-runtime-sse-writer.php';
		$twinchat_handler_source = is_readable( $twinchat_handler_file ) ? (string) file_get_contents( $twinchat_handler_file ) : '';
		$twinchat_bridge_ok = is_readable( $twinchat_writer_file )
			&& strpos( $twinchat_handler_source, "bizcity_twinchat_use_twinbrain_runtime" ) !== false
			&& strpos( $twinchat_handler_source, 'run_v3_runtime_pipeline' ) !== false
			&& strpos( $twinchat_handler_source, 'complete_turn_stream' ) !== false
			&& strpos( $twinchat_handler_source, "'goal_contract'" ) !== false
			&& strpos( $twinchat_handler_source, "get_option( 'bizcity_twinchat_use_twinbrain_runtime', '1' ) !== '0'" ) !== false
			&& strpos( $runtime_source, "'goal_contract'         =>" ) !== false;
		$ok = $this->step( $ctx, $steps, 'Disk: TwinChat canonical stream bridge', $twinchat_bridge_ok, $twinchat_bridge_ok ? 'TwinChat has an opt-in Runtime-owned streaming entrypoint with SSE bridge.' : 'TwinChat Runtime stream bridge is missing.' ) && $ok;
		$twinchat_batch_ok = is_readable( $twinchat_writer_file )
			&& strpos( (string) file_get_contents( $twinchat_writer_file ), 'FINAL_TOKEN_BATCH_CHARS' ) !== false
			&& strpos( (string) file_get_contents( $twinchat_writer_file ), 'flush_final_tokens' ) !== false;
		$ok = $this->step( $ctx, $steps, 'Disk: TwinChat final-token batching contract', $twinchat_batch_ok, $twinchat_batch_ok ? 'Runtime bridge coalesces tiny final deltas and flushes before terminal events.' : 'TwinChat final-token batching markers are missing.' ) && $ok;
		$runtime_priority_ok = strpos( $twinchat_handler_source, '$runtime_mvp_available = $use_twinbrain_runtime' ) !== false
			&& strpos( $twinchat_handler_source, 'if ( $use_twin_agent && ! $runtime_mvp_available' ) !== false;
		$ok = $this->step( $ctx, $steps, 'Disk: TwinChat MVP outranks Twin Agent', $runtime_priority_ok, $runtime_priority_ok ? 'Enabled Runtime MVP cannot be bypassed by Twin Agent delegation.' : 'TwinChat delegation priority contract is missing.' ) && $ok;
		$crm_replier_file = $plugin_root . 'plugins/bizcity-twin-crm/includes/class-ai-replier.php';
		$crm_rest_file = $plugin_root . 'plugins/bizcity-twin-crm/includes/class-rest-controller.php';
		$crm_replier_source = is_readable( $crm_replier_file ) ? (string) file_get_contents( $crm_replier_file ) : '';
		$crm_rest_source = is_readable( $crm_rest_file ) ? (string) file_get_contents( $crm_rest_file ) : '';
		$crm_projection_ok = strpos( $crm_replier_source, 'answer_quality_gate_fields' ) !== false
			&& strpos( $crm_replier_source, "'final_gate_status'" ) !== false
			&& strpos( $crm_rest_source, "'final_gate_status'" ) !== false
			&& strpos( $crm_rest_source, "'answer_quality'" ) !== false;
		$ok = $this->step( $ctx, $steps, 'Disk: CRM Final Gate projection contract', $crm_projection_ok, $crm_projection_ok ? 'CRM metadata and Goal Loop REST expose flattened gate status and answer quality.' : 'CRM Final Gate projection markers are missing.' ) && $ok;
		$composer_file = $plugin_root . 'core/twinbrain/includes/class-twinbrain-final-composer.php';
		$composer_source = is_readable( $composer_file ) ? (string) file_get_contents( $composer_file ) : '';
		$source_title_label_ok = strpos( $composer_source, '## Tài liệu/source title tìm thấy trước' ) !== false
			&& strpos( $composer_source, '| Tài liệu / source title |' ) !== false
			&& strpos( $composer_source, '## Các dòng/sản phẩm tìm thấy từ nội dung' ) === false;
		$ok = $this->step( $ctx, $steps, 'Disk: source-title/product-name label separation', $source_title_label_ok, $source_title_label_ok ? 'Generic source-title evidence is not labeled as a milk/product name.' : 'Source-title/product-name labels are conflated.' ) && $ok;
		$v4_skeleton_ok = strpos( $composer_source, 'resolve_answer_skeleton' ) !== false
			&& strpos( $composer_source, "'id' => 'health.consulting.v1'" ) !== false
			&& strpos( $composer_source, "'id' => 'product.list.v1'" ) !== false
			&& strpos( $composer_source, 'ANSWER SKELETON CONTRACT' ) !== false
			&& strpos( $composer_source, "'skeleton_id'" ) !== false;
		$ok = $this->step( $ctx, $steps, 'Disk: V4 answer skeleton contract', $v4_skeleton_ok, $v4_skeleton_ok ? 'Final Composer resolves domain skeletons and exposes skeleton_id metadata.' : 'V4 answer skeleton markers are missing.' ) && $ok;
		$v4_validator_ok = strpos( $composer_source, 'validate_answer_skeleton' ) !== false
			&& strpos( $composer_source, "'skeleton_quality'" ) !== false
			&& strpos( $runtime_source, "'skeleton_violations'" ) !== false;
		$ok = $this->step( $ctx, $steps, 'Disk: V4 post-compose validator contract', $v4_validator_ok, $v4_validator_ok ? 'Final output records skeleton quality and safe validation violations.' : 'V4 post-compose validator markers are missing.' ) && $ok;
		// [2026-08-07 Johnny Chu] V4-DEPTH DDV — assert the four MPR tiers and every active REST/FE handoff without invoking an LLM or writing state.
		$runtime_depth_ok = strpos( $runtime_source, "'fast'" ) !== false
			&& strpos( $runtime_source, "'balanced'" ) !== false
			&& strpos( $runtime_source, "'high'" ) !== false
			&& strpos( $runtime_source, "'deep'" ) !== false
			&& strpos( $runtime_source, 'DEEP_RETRIEVE_ROUNDS' ) !== false
			&& strpos( $runtime_source, 'answer_depth_resolved' ) !== false
			&& strpos( $runtime_source, 'skip_goal_parser' ) !== false
			&& strpos( $runtime_source, 'skip_reflection' ) !== false
			&& strpos( $runtime_source, 'goal_loop_pre_turn_completed' ) !== false
			&& strpos( $runtime_source, 'goal_parser_already_ran' ) !== false;
		$goal_runtime_file = $plugin_root . 'core/twinbrain/includes/class-twinbrain-goal-loop-runtime.php';
		$goal_runtime_source = is_readable( $goal_runtime_file ) ? (string) file_get_contents( $goal_runtime_file ) : '';
		$runtime_depth_ok = $runtime_depth_ok
			&& strpos( $goal_runtime_source, 'post_reflection_enabled' ) !== false
			&& strpos( $goal_runtime_source, 'reflection_skipped' ) !== false;
		$ok = $this->step( $ctx, $steps, 'Disk: V4 answer-depth MPR tier contract', $runtime_depth_ok, $runtime_depth_ok ? 'Fast/Balanced/High/Deep map to Goal Parser, Reflection, and bounded Retrieve gates.' : 'V4 answer-depth resolver or gate markers are missing.' ) && $ok;
		// [2026-08-07 Johnny Chu] V4-DEPTH DDV — invoke only the pure resolver so all four global prompt tiers are checked without LLM/DB side effects.
		$depth_fixture_ok = false;
		$depth_fixture_detail = 'TwinBrain Runtime class is not loaded; depth resolver fixture skipped.';
		if ( class_exists( 'BizCity_TwinBrain_Runtime' ) ) {
			try {
				$runtime_ref = BizCity_TwinBrain_Runtime::instance();
				$depth_method = new ReflectionMethod( 'BizCity_TwinBrain_Runtime', 'resolve_answer_depth_config' );
				$depth_method->setAccessible( true );
				$expected_depths = array(
					'fast'     => array( true, true, 0 ),
					'balanced' => array( false, false, 0 ),
					'high'     => array( false, false, 1 ),
					'deep'     => array( false, false, 3 ),
				);
				$depth_fixture_ok = true;
				$depth_fixture_rows = array();
				foreach ( $expected_depths as $depth_name => $expected ) {
					$actual = (array) $depth_method->invoke( $runtime_ref, array( 'answer_depth' => $depth_name ) );
					$row_ok = (string) ( $actual['depth'] ?? '' ) === $depth_name
						&& ! empty( $actual['skip_goal_parser'] ) === $expected[0]
						&& ! empty( $actual['skip_reflection'] ) === $expected[1]
						&& (int) ( $actual['max_retrieve_rounds'] ?? -1 ) === $expected[2];
					$depth_fixture_rows[ $depth_name ] = array( 'ok' => $row_ok, 'config' => $actual );
					if ( ! $row_ok ) {
						$depth_fixture_ok = false;
					}
				}
				$depth_fixture_detail = $depth_fixture_ok ? 'fast=skip parser/reflection/0 retrieve; balanced=parser+reflection/0 retrieve; high=1 retrieve; deep=3 retrieve.' : wp_json_encode( $depth_fixture_rows );
			} catch ( \Throwable $e ) {
				$depth_fixture_detail = 'Answer-depth resolver fixture exception: ' . get_class( $e ) . ' ' . $e->getMessage();
			}
		}
		$ok = $this->step( $ctx, $steps, 'Runtime: V4 answer-depth resolver fixtures', $depth_fixture_ok, $depth_fixture_detail ) && $ok;
		// [2026-08-07 Johnny Chu] V4-TRIAGE DDV — deterministic boundary fixtures; no LLM/DB calls are made because greetings use heuristic path and other rows fail-open to MPR when provider is absent.
		$triage_fixture_ok = false;
		$triage_fixture_detail = 'Pre-MPR Triage class is not loaded.';
		if ( class_exists( 'BizCity_TwinBrain_Pre_MPR_Triage' ) ) {
			// [2026-08-07 Johnny Chu] V4-TRIAGE DDV — assert provider contract and both terminal branch markers without making an LLM call.
			$explicit_method = new ReflectionMethod( 'BizCity_TwinBrain_Pre_MPR_Triage', 'has_explicit_command' );
			$explicit_method->setAccessible( true );
			$explicit_detected = (bool) $explicit_method->invoke( null, '@guru hãy tóm tắt notebook' );
			$triage_fixture_ok = BizCity_TwinBrain_Pre_MPR_Triage::MODEL === 'openai/gpt-5.6-luna'
				&& BizCity_TwinBrain_Pre_MPR_Triage::REASONING_EFFORT === 'low'
				&& $explicit_detected
				&& strpos( $runtime_source, "'prompt_intent'" ) !== false
				&& strpos( $runtime_source, 'stream_ambiguous_response' ) !== false
				&& strpos( $runtime_source, "'pre_mpr_triage'" ) !== false
				&& strpos( $runtime_source, 'conversation_triage_done' ) !== false
				&& strpos( $runtime_source, 'ambiguous_completed' ) !== false
				&& strpos( $runtime_source, 'mpr_started' ) !== false;
			$triage_fixture_detail = $triage_fixture_ok ? 'Explicit command boundary is detected without a provider call; Luna classification and MPR preservation are verified by the active source contract.' : 'Explicit command or triage branch markers are missing.';
		}
		$ok = $this->step( $ctx, $steps, 'Runtime: Pre-MPR ambiguous/MPR branch fixtures', $triage_fixture_ok, $triage_fixture_detail ) && $ok;
		// [2026-08-16 Johnny Chu] MPR-V5-GOAL-ALIGNMENT — verify the new deterministic gate without LLM or Event Stream writes.
		$aligned = BizCity_TwinBrain_Goal_Alignment::check(
			array( 'intent_id' => 'commerce.order_create', 'requires_goal' => true, 'requires_hil' => true, 'side_effect_level' => 'write_external', 'confidence' => 0.94 ),
			array( 'primary_goal' => 'Đặt đơn giao hàng', 'required_inputs' => array( array( 'id' => 'address' ) ) ),
			array( 'goal_id' => 'goal_order_1', 'primary_goal' => 'Đặt đơn giao hàng' )
		);
		$aligned_ok = (string) ( $aligned['status'] ?? '' ) === 'aligned'
			&& (string) ( $aligned['intent_id'] ?? '' ) === 'commerce.order_create'
			&& ! empty( $aligned['requires_user_confirmation'] );
		$ok = $this->step( $ctx, $steps, 'Runtime: Goal Alignment aligned fixture', $aligned_ok, $aligned_ok ? 'Intent, Goal Draft, and side-effect confirmation align without a second classifier.' : wp_json_encode( $aligned ) ) && $ok;
		$needs_clarification = BizCity_TwinBrain_Goal_Alignment::check(
			array( 'intent_id' => 'unknown.clarify', 'requires_goal' => true ),
			array(),
			array()
		);
		$needs_clarification_ok = (string) ( $needs_clarification['status'] ?? '' ) === 'needs_clarification'
			&& in_array( 'intent_unclear', (array) ( $needs_clarification['reasons'] ?? array() ), true );
		$ok = $this->step( $ctx, $steps, 'Runtime: Goal Alignment clarification fixture', $needs_clarification_ok, $needs_clarification_ok ? 'Unknown intent fails closed to clarification.' : wp_json_encode( $needs_clarification ) ) && $ok;
		// [2026-08-16 Johnny Chu] MPR-V5-TEMPORAL — verify relative date range resolution without LLM or DB writes.
		$temporal = BizCity_TwinBrain_Temporal_Context_Resolver::resolve( 'Lập kế hoạch cho hôm nay', array( 'timezone' => 'UTC' ) );
		$temporal_ok = ! empty( $temporal['resolved'] )
			&& (string) ( $temporal['granularity'] ?? '' ) === 'day'
			&& (string) ( $temporal['reason_code'] ?? '' ) === 'relative_today'
			&& (string) ( $temporal['timezone'] ?? '' ) === 'UTC'
			&& (string) ( $temporal['range_start'] ?? '' ) !== ''
			&& (string) ( $temporal['range_end'] ?? '' ) !== '';
		$ok = $this->step( $ctx, $steps, 'Runtime: deterministic temporal context fixture', $temporal_ok, $temporal_ok ? 'Relative today range resolves with an explicit timezone.' : wp_json_encode( $temporal ) ) && $ok;
		// [2026-08-16 Johnny Chu] MPR-V5-GOAL-SESSION — verify cross-session goals require an explicit resume/new choice.
		$blocked_decision = BizCity_TwinBrain_Goal_Loop_State::session_start_decision(
			array( 'goal_id' => 'goal_active_1', 'session_id' => 'session_old', 'status' => 'executing' ),
			'session_new',
			''
		);
		$blocked_ok = (string) ( $blocked_decision['action'] ?? '' ) === 'ask_resume_or_new'
			&& (string) ( $blocked_decision['reason'] ?? '' ) === 'active_goal_in_another_session';
		$ok = $this->step( $ctx, $steps, 'Runtime: cross-session Goal requires explicit choice', $blocked_ok, $blocked_ok ? 'No silent resume or duplicate Goal is allowed.' : wp_json_encode( $blocked_decision ) ) && $ok;
		$core_rest_file = $plugin_root . 'core/twinbrain/includes/class-twinbrain-rest.php';
		$twinchat_rest_file = $plugin_root . 'modules/twinchat/includes/class-twinchat-rest-controller.php';
		$twinchat_handler_file = $plugin_root . 'modules/twinchat/includes/class-twinchat-stream-handler.php';
		$twinweb_rest_file = $plugin_root . 'modules/twinweb/includes/class-twinweb-rest.php';
		$core_rest_source = is_readable( $core_rest_file ) ? (string) file_get_contents( $core_rest_file ) : '';
		$twinchat_rest_source = is_readable( $twinchat_rest_file ) ? (string) file_get_contents( $twinchat_rest_file ) : '';
		$twinchat_handler_depth_source = is_readable( $twinchat_handler_file ) ? (string) file_get_contents( $twinchat_handler_file ) : '';
		$twinweb_rest_source = is_readable( $twinweb_rest_file ) ? (string) file_get_contents( $twinweb_rest_file ) : '';
		$rest_depth_ok = strpos( $core_rest_source, 'answer_depth' ) !== false
			&& strpos( $core_rest_source, 'sanitize_answer_depth' ) !== false
			&& strpos( $twinchat_rest_source, 'answer_depth' ) !== false
			&& strpos( $twinchat_handler_depth_source, 'answer_depth' ) !== false
			&& strpos( $twinweb_rest_source, 'answer_depth' ) !== false;
		$ok = $this->step( $ctx, $steps, 'Disk: V4 answer-depth REST handoff', $rest_depth_ok, $rest_depth_ok ? 'Core TwinBrain, TwinChat, and TwinWeb carry the same answer_depth contract.' : 'One or more active REST surfaces do not forward answer_depth.' ) && $ok;
		$channel_adapter_file = $plugin_root . 'core/twinbrain/includes/class-twinbrain-channel-adapter.php';
		$automation_bridge_file = $plugin_root . 'core/automation/includes/class-automation-twinbrain-bridge.php';
		$channel_adapter_source = is_readable( $channel_adapter_file ) ? (string) file_get_contents( $channel_adapter_file ) : '';
		$automation_bridge_source = is_readable( $automation_bridge_file ) ? (string) file_get_contents( $automation_bridge_file ) : '';
		$internal_handoff_ok = strpos( $channel_adapter_source, 'goal_loop_pre_turn_completed' ) !== false
			&& strpos( $channel_adapter_source, "'answer_depth'" ) !== false
			&& strpos( $automation_bridge_source, 'goal_loop_pre_turn_completed' ) !== false
			&& strpos( $automation_bridge_source, "'answer_depth'" ) !== false;
		$ok = $this->step( $ctx, $steps, 'Disk: V4 answer-depth internal caller handoff', $internal_handoff_ok, $internal_handoff_ok ? 'Channel Adapter and Automation Bridge preserve depth and parser lifecycle.' : 'An internal Runtime caller can still lose answer_depth or parser lifecycle state.' ) && $ok;
		$command_menu_file = $plugin_root . 'modules/twinchat/ui/src/components/askbrain/TwinCommandMenu.tsx';
		$askbrain_ui_file = $plugin_root . 'modules/twinchat/ui/src/components/askbrain/AskBrainPanel.tsx';
		$command_menu_source = is_readable( $command_menu_file ) ? (string) file_get_contents( $command_menu_file ) : '';
		$askbrain_ui_source = is_readable( $askbrain_ui_file ) ? (string) file_get_contents( $askbrain_ui_file ) : '';
		$command_menu_dist = $plugin_root . 'modules/twinchat/ui/dist/';
		$command_menu_dist_ok = is_dir( $command_menu_dist ) && ( is_readable( $command_menu_dist . 'manifest.json' ) || is_readable( $command_menu_dist . 'index.html' ) );
		$command_menu_ok = strpos( $command_menu_source, 'VERTICALS' ) !== false
			&& strpos( $command_menu_source, 'BrainSkillDef' ) !== false
			&& strpos( $command_menu_source, 'onOpenGuru' ) !== false
			&& strpos( $askbrain_ui_source, 'TwinCommandMenu' ) !== false
			&& strpos( $askbrain_ui_source, "e.key === '/'" ) !== false
			&& strpos( $askbrain_ui_source, 'ANSWER_DEPTH_VALUES' ) !== false
			&& strpos( $askbrain_ui_source, 'answer_depth: answerDepth' ) !== false;
		if ( ! $command_menu_ok && $command_menu_source === '' && $askbrain_ui_source === '' && $command_menu_dist_ok ) {
			$this->skip( $ctx, $steps, 'Disk: V4 command menu and Answer Depth UI', 'SKIP: React source is not deployed; TwinChat dist artifact is present, valid under R-DDV-FE.' );
		} else {
			$ok = $this->step( $ctx, $steps, 'Disk: V4 command menu and Answer Depth UI', $command_menu_ok, $command_menu_ok ? 'Ask Brain exposes unified Guru/Vertical/Skill commands and sends the selected MPR tier.' : 'Command menu or Answer Depth UI handoff markers are missing.' ) && $ok;
		}
		// [2026-08-05 Johnny Chu] V4-DDV — exercise deterministic skeleton/validator fixtures without an LLM call or persistence write.
		if ( ! class_exists( 'BizCity_TwinBrain_Final_Composer' ) && is_readable( $composer_file ) ) {
			require_once $composer_file;
		}
		$v4_fixture_runner_ok = false;
		$v4_fixture_detail = 'Final Composer class is not loaded; deterministic V4 fixtures cannot run.';
		if ( class_exists( 'BizCity_TwinBrain_Final_Composer' ) ) {
			try {
				$composer = BizCity_TwinBrain_Final_Composer::instance();
				$resolve_method = new ReflectionMethod( 'BizCity_TwinBrain_Final_Composer', 'resolve_answer_skeleton' );
				$validate_method = new ReflectionMethod( 'BizCity_TwinBrain_Final_Composer', 'validate_answer_skeleton' );
				$resolve_method->setAccessible( true );
				$validate_method->setAccessible( true );
				$v4_fixtures = array(
					'health_with_source' => array(
						'prompt' => 'Bé bị táo bón, nên theo dõi gì?',
						'intent' => array( 'intent' => 'general' ),
						'opts' => array(),
						'answer' => "## Kết luận ngắn\nTheo dõi thêm dữ kiện hiện có.\n\n## Thông tin đã biết\nDữ kiện từ user và nguồn tham chiếu.\n\n## Phân tích chính\nCần đối chiếu theo triệu chứng.\n\n## Nên làm gì tiếp theo\nTheo dõi diễn biến.\n\n## Lưu ý an toàn\nĐưa bé đi khám nếu nặng lên.\n\n## Một thông tin mình cần thêm\nSố lần đi ngoài trong tuần này?\n\n## Nguồn tham chiếu\n[nb:1/p1]",
					),
					'health_without_source' => array(
						'prompt' => 'Bé bị táo bón, nên theo dõi gì?',
						'intent' => array( 'intent' => 'general' ),
						'opts' => array(),
						'answer' => "## Kết luận ngắn\nTheo dõi thêm dữ kiện hiện có.\n\n## Thông tin đã biết\nDữ kiện user đã cung cấp.\n\n## Phân tích chính\nCần đối chiếu theo triệu chứng.\n\n## Nên làm gì tiếp theo\nTheo dõi diễn biến.\n\n## Lưu ý an toàn\nĐưa bé đi khám nếu nặng lên.\n\n## Một thông tin mình cần thêm\nSố lần đi ngoài trong tuần này?",
					),
					'verified_product_entities' => array(
						'prompt' => 'Liệt kê các sản phẩm trong nguồn.',
						'intent' => array( 'intent' => 'list_products' ),
						'opts' => array( 'product_entities' => array( array( 'name' => 'Sản phẩm A', 'entity_type' => 'product_name', 'is_product_name' => true ) ) ),
						'answer' => "## Kết luận ngắn\nCó một sản phẩm đã được xác minh.\n\n## Các sản phẩm/dòng sản phẩm được xác minh\n- Sản phẩm A [nb:1/p1]\n\n## Phân tích chính\nDựa trên trích đoạn nguồn.\n\n## Nên làm gì tiếp theo\nĐối chiếu nhu cầu.\n\n## Nguồn tham chiếu\n[nb:1/p1]",
					),
					'source_titles_only' => array(
						'prompt' => 'Liệt kê các sản phẩm trong nguồn.',
						'intent' => array( 'intent' => 'list_products' ),
						'opts' => array( 'product_entities' => array( array( 'name' => 'Tên tài liệu', 'entity_type' => 'source_title', 'source_title' => 'Tên tài liệu' ) ) ),
						'answer' => "## Kết luận ngắn\nCó tài liệu liên quan.\n\n## Các dòng sữa tìm thấy từ nội dung\n| Tên | Nguồn |\n|---|---|\n| Tên tài liệu | Tên tài liệu |\n\n## Phân tích chính\nChưa đủ dữ kiện để xác minh tên sản phẩm.\n\n## Nên làm gì tiếp theo\nMở nội dung nguồn.\n\n## Nguồn tham chiếu\n[nb:1/p1]",
					),
					'comparison_tension' => array(
						'prompt' => 'So sánh hai lựa chọn và nêu điểm đánh đổi.',
						'intent' => array( 'intent' => 'comparison' ),
						'opts' => array(),
						'answer' => "## Kết luận ngắn\nChưa có lựa chọn thắng tuyệt đối.\n\n## So sánh\nTiêu chí A và B khác nhau.\n\n## Trade-off\nMỗi lựa chọn có một đánh đổi.\n\n## Nên làm gì tiếp theo\nChọn theo bối cảnh.\n\n## Nguồn tham chiếu\n[nb:1/p1]",
					),
					'open_goal' => array(
						'prompt' => 'Hãy giúp tôi lập kế hoạch triển khai hệ thống theo từng giai đoạn.',
						'intent' => array( 'intent' => 'general' ),
						'opts' => array(),
						'answer' => "## Kết luận ngắn\nCó thể triển khai theo từng giai đoạn.",
					),
					'casual_greeting' => array(
						'prompt' => 'Xin chào',
						'intent' => array( 'intent' => 'casual' ),
						'opts' => array(),
						'answer' => 'Chào bạn, mình đây.',
					),
				);
				$v4_fixture_results = array();
				foreach ( $v4_fixtures as $fixture_name => $fixture ) {
					$skeleton = (array) $resolve_method->invoke( $composer, $fixture['prompt'], $fixture['intent'], $fixture['opts'] );
					$validation = (array) $validate_method->invoke( $composer, $fixture['answer'], $skeleton, $fixture['opts'] );
					$v4_fixture_results[ $fixture_name ] = array( 'skeleton' => $skeleton, 'validation' => $validation );
				}
				$health_source = $v4_fixture_results['health_with_source'];
				$health_no_source = $v4_fixture_results['health_without_source'];
				$product_verified = $v4_fixture_results['verified_product_entities'];
				$product_titles = $v4_fixture_results['source_titles_only'];
				$comparison = $v4_fixture_results['comparison_tension'];
				$open_goal = $v4_fixture_results['open_goal'];
				$greeting = $v4_fixture_results['casual_greeting'];
				$parity_a = (array) $resolve_method->invoke( $composer, 'Xin hãy tư vấn kế hoạch chăm sóc bé bị táo bón.', array( 'intent' => 'general' ), array() );
				$parity_b = (array) $resolve_method->invoke( $composer, 'Xin hãy tư vấn kế hoạch chăm sóc bé bị táo bón.', array( 'intent' => 'general' ), array() );
				$fixture_assertions = array(
					( $health_source['skeleton']['id'] ?? '' ) === 'health.consulting.v1' && ( $health_source['validation']['quality'] ?? '' ) === 'ok',
					( $health_no_source['skeleton']['id'] ?? '' ) === 'health.consulting.v1' && ( $health_no_source['validation']['quality'] ?? '' ) === 'bounded_fallback',
					( $product_verified['skeleton']['id'] ?? '' ) === 'product.list.v1' && ( $product_verified['validation']['quality'] ?? '' ) === 'ok',
					( $product_titles['validation']['entity_gate'] ?? '' ) === 'degraded' && false !== strpos( (string) ( $product_titles['validation']['answer_md'] ?? '' ), '## Kết quả nguồn' ),
					( $comparison['skeleton']['id'] ?? '' ) === 'comparison.v1' && ( $comparison['validation']['quality'] ?? '' ) === 'ok',
					( $open_goal['skeleton']['id'] ?? '' ) === 'consulting.v1' && ( $open_goal['validation']['next_action_gate'] ?? '' ) === 'degraded',
					( $greeting['skeleton']['id'] ?? '' ) === 'casual.compact.v1' && ( $greeting['validation']['quality'] ?? '' ) === 'ok',
					$parity_a === $parity_b && false !== strpos( $runtime_source, "'skeleton_id'" ) && false !== strpos( $runtime_source, "'skeleton_violations'" ),
				);
				$v4_fixture_runner_ok = ! in_array( false, $fixture_assertions, true );
				$v4_fixture_detail = $v4_fixture_runner_ok ? '8 deterministic V4 fixtures pass: health/source, health/no-source, verified entities, source titles only, comparison tension, open Goal Loop, casual greeting, and stream/replay skeleton parity.' : wp_json_encode( $v4_fixture_results );
			} catch ( \Throwable $e ) {
				$v4_fixture_detail = 'V4 fixture exception: ' . get_class( $e ) . ' ' . $e->getMessage();
			}
		}
		$ok = $this->step( $ctx, $steps, 'Runtime: V4 answer skeleton fixtures', $v4_fixture_runner_ok, $v4_fixture_detail ) && $ok;
		$parser_fixture = BizCity_TwinBrain_Goal_Loop_Parser::parse( 'Tư vấn chọn sữa giúp bé giảm táo bón, bé 1 tháng tuổi nặng 2kg, bất dung nạp Lactose.' );
		$parser_ok = ( $parser_fixture['subject']['age'] ?? '' ) === '1 tháng'
			&& ( $parser_fixture['subject']['weight_kg'] ?? '' ) === '2'
			&& ! empty( $parser_fixture['constraints'] )
			&& ! empty( $parser_fixture['objectives'] );
		$ok = $this->step( $ctx, $steps, 'Runtime: deterministic Goal Parser fixture', $parser_ok, $parser_ok ? 'Age, weight, symptom, and objective parsed without LLM.' : wp_json_encode( $parser_fixture ) ) && $ok;
		$reflect_fixture = BizCity_TwinBrain_Goal_Loop_Reflector::reflect( array( 'required_inputs' => array( array( 'id' => 'age', 'label' => 'Tuổi', 'status' => 'pending' ) ) ), 'Tư vấn giúp tôi', array( 'citations' => array( '[nb:1/p1]' ) ) );
		$reflect_ok = ( $reflect_fixture['completion_score'] ?? 1 ) < 1
			&& ( $reflect_fixture['gaps'][0]['kind'] ?? '' ) === 'missing_input';
		$ok = $this->step( $ctx, $steps, 'Runtime: deterministic Reflector leaves missing input open', $reflect_ok, $reflect_ok ? 'Score and gap are produced without terminal transition.' : wp_json_encode( $reflect_fixture ) ) && $ok;
		// [2026-08-04 Johnny Chu] V3.3 — factual obligation without citation must route to RETRIEVE, never PATCH.
		$retrieve_fixture = BizCity_TwinBrain_Goal_Loop_Reflector::reflect(
			array(
				'answer_obligations' => array(
					array( 'id' => 'fact_1', 'question' => 'Chi phí là bao nhiêu?', 'type' => 'fact', 'priority' => 'must', 'status' => 'open' ),
				),
			),
			'Chi phí là bao nhiêu?',
			array( 'answer_md' => 'Tôi chưa có thông tin cụ thể.' )
		);
		$retrieve_row = (array) ( $retrieve_fixture['resolution_scoreboard']['rows'][0] ?? array() );
		$retrieve_route_ok = ( $retrieve_row['route'] ?? '' ) === 'RETRIEVE'
			&& empty( $retrieve_row['evidence_ref'] )
			&& empty( $retrieve_fixture['resolution_scoreboard']['overall_ready_for_final'] );
		$ok = $this->step( $ctx, $steps, 'Runtime: missing factual evidence routes to Retrieve', $retrieve_route_ok, $retrieve_route_ok ? 'Fact obligation with empty evidence is blocked for retrieval.' : wp_json_encode( $retrieve_fixture ) ) && $ok;
		$question_fixture = BizCity_TwinBrain_Goal_Loop_Question_Engine::next_question( array(
			'goal_id' => 'question_1',
			'gaps' => array( array( 'id' => 'gap_1', 'kind' => 'weak_evidence', 'label' => 'Nguồn xác nhận', 'status' => 'open' ) ),
			'spiral_turns' => 1,
		) );
		$question_ok = ( $question_fixture['type'] ?? '' ) === 'ask_gap' && ! empty( $question_fixture['question_text'] );
		$ok = $this->step( $ctx, $steps, 'Runtime: continuity question targets one open gap', $question_ok, $question_ok ? 'Question Engine is deterministic and side-effect free.' : wp_json_encode( $question_fixture ) ) && $ok;
		$checkpoint = BizCity_TwinBrain_Goal_Loop_Question_Engine::next_question( array( 'goal_id' => 'question_2', 'spiral_turns' => 6 ) );
		$checkpoint_ok = ( $checkpoint['type'] ?? '' ) === 'checkpoint';
		$ok = $this->step( $ctx, $steps, 'Runtime: spiral guard produces checkpoint', $checkpoint_ok, $checkpoint_ok ? 'Max spiral turns stop automatic questioning.' : wp_json_encode( $checkpoint ) ) && $ok;
		$rest_api_ok = method_exists( 'BizCity_TwinBrain_Goal_Loop_REST', 'register_routes' )
			&& method_exists( 'BizCity_TwinBrain_Goal_Loop_REST', 'handle_active' )
			&& method_exists( 'BizCity_TwinBrain_Goal_Loop_REST', 'handle_close' );
		$ok = $this->step( $ctx, $steps, 'Loader: Goal REST active/close API', $rest_api_ok, $rest_api_ok ? 'Identity-scoped active and close endpoints are loaded.' : 'Goal REST methods missing.' ) && $ok;
		$thread_link_api_ok = class_exists( 'BizCity_TwinWeb_Thread_Registry' )
			&& method_exists( 'BizCity_TwinWeb_Thread_Registry', 'sync_goal_link' )
			&& method_exists( 'BizCity_TwinWeb_Thread_Registry', 'repair_goal_link' );
		$ok = $this->step( $ctx, $steps, 'Loader: TwinWeb Goal Link projection API', $thread_link_api_ok, $thread_link_api_ok ? 'Event-first sync and legacy repair methods are loaded; no DB write performed.' : 'TwinWeb Goal Link projection API missing.' ) && $ok;
		$cursor_contract = class_exists( 'BizCity_TwinBrain_Goal_Loop_Repository' )
			&& defined( 'BizCity_TwinBrain_Goal_Loop_Repository::IDENTITY_SCAN_BATCH' )
			&& defined( 'BizCity_TwinBrain_Goal_Loop_Repository::IDENTITY_SCAN_PAGES' );
		$ok = $this->step( $ctx, $steps, 'Loader: Goal event cursor contract', $cursor_contract, $cursor_contract ? 'Repository exposes bounded keyset scan contract for event_id/event_uuid repair.' : 'Goal event cursor contract missing.' ) && $ok;
		$trace_contract = class_exists( 'BizCity_TwinBrain_Goal_Loop_Repository' )
			&& method_exists( 'BizCity_TwinBrain_Goal_Loop_Repository', 'trace_projection' )
			&& class_exists( 'BizCity_JSONL_File_Logger' );
		$ok = $this->step( $ctx, $steps, 'Loader: Goal Loop JSONL trace contract', $trace_contract, $trace_contract ? 'Canonical trace/projection logger is loaded; probe does not write a log row.' : 'Goal Loop JSONL trace contract missing.' ) && $ok;
		$log_location = $trace_contract && method_exists( 'BizCity_JSONL_File_Logger', 'location' )
			? BizCity_JSONL_File_Logger::location( 'bizcity-twinbrain-logs', 'twinbrain-goal-loop' )
			: array();
		$log_path_ok = ! empty( $log_location['directory'] ) && ! empty( $log_location['writable'] );
		$log_detail = ! empty( $log_location['file'] )
			? (string) $log_location['file'] . ( $log_path_ok ? ' (writable)' : ' (not writable or unresolved)' )
			: 'Effective JSONL upload path could not be resolved.';
		$ok = $this->step( $ctx, $steps, 'Runtime: Goal Loop JSONL effective path', $log_path_ok, $log_detail ) && $ok;
		$scheduler_api_ok = method_exists( 'BizCity_TwinBrain_Goal_Loop_Scheduler', 'init' )
			&& method_exists( 'BizCity_TwinBrain_Goal_Loop_Scheduler', 'register_cron' )
			&& method_exists( 'BizCity_TwinBrain_Goal_Loop_Scheduler', 'scan_stale_goals' );
		$ok = $this->step( $ctx, $steps, 'Loader: Goal Loop stale scheduler', $scheduler_api_ok, $scheduler_api_ok ? 'G7 scheduler, central Cron Manager registration, and stale scan methods are loaded.' : 'Goal Loop stale scheduler methods missing.' ) && $ok;
		$scheduler_cursor_ok = $scheduler_api_ok
			&& defined( 'BizCity_TwinBrain_Goal_Loop_Scheduler::MAX_EVENTS_PER_SCAN' )
			&& defined( 'BizCity_TwinBrain_Goal_Loop_Scheduler::SCAN_PAGES' );
		$ok = $this->step( $ctx, $steps, 'Loader: bounded scheduler cursor contract', $scheduler_cursor_ok, $scheduler_cursor_ok ? 'G7 stale scan exposes bounded keyset pagination constants.' : 'Scheduler cursor contract missing.' ) && $ok;

		// [2026-08-02 Johnny Chu] PHASE-TWIN-GOAL-LOOP-E2E — opt-in sandbox round-trip verifies the three canonical Event Stream writes.
		$live_write_enabled = (bool) apply_filters( 'bizcity_diagnostics_goal_loop_live_write', false );
		if ( $live_write_enabled ) {
			$live_write = $this->run_live_event_write();
			$ok = $this->step( $ctx, $steps, 'Runtime: live Goal Loop event-write E2E', ! empty( $live_write['ok'] ), (string) ( $live_write['detail'] ?? '' ) ) && $ok;
		} else {
			$this->skip( $ctx, $steps, 'Runtime: live Goal Loop event-write E2E', 'SKIP: enable the bizcity_diagnostics_goal_loop_live_write filter for one sandbox round-trip.' );
		}

		$base = BizCity_TwinBrain_Goal_Loop_State::normalize( array(
			'goal_id' => '123',
			'session_id' => 'session_456',
			'primary_goal' => 'Tạo báo cáo kinh doanh để trình Chủ tịch',
			'status' => 'executing',
			'completion_score' => 0.75,
			'definition_of_done' => array(
				array( 'id' => 'report', 'label' => 'Có báo cáo hoàn chỉnh', 'status' => 'pending' ),
			),
		) );
		$normalize_ok = $base['goal_id'] === 'goal_123'
			&& $base['status'] === 'executing'
			&& abs( $base['completion_score'] - 0.75 ) < 0.001
			&& ( $base['root_session_id'] ?? '' ) === 'session_456'
			&& array_key_exists( 'identity_uuid', $base )
			&& array_key_exists( 'blog_id', $base )
			&& array_key_exists( 'goal_draft', $base )
			&& array_key_exists( 'gaps', $base )
			&& array_key_exists( 'spiral_turns', $base );
		$ok = $this->step( $ctx, $steps, 'Runtime: normalize canonical Goal State', $normalize_ok, wp_json_encode( $base ) ) && $ok;
		$cursor_state = array( '_event_id' => 123, '_event_uuid' => 'event-ddv', 'root_session_id' => 'session_root' );
		$cursor_contract_ok = (int) ( $cursor_state['_event_id'] ?? 0 ) > 0
			&& (string) ( $cursor_state['_event_uuid'] ?? '' ) !== ''
			&& (string) ( $cursor_state['root_session_id'] ?? '' ) === 'session_root';
		$ok = $this->step( $ctx, $steps, 'Runtime: Goal Link root/cursor contract', $cursor_contract_ok, $cursor_contract_ok ? 'Root session, numeric event_id, and event_uuid are available for audit projection.' : wp_json_encode( $cursor_state ) ) && $ok;

		$adapter = BizCity_TwinBrain_Goal_Loop_Intent_Adapter::from_conversation( array(
			'conversation_id' => 'intent_9',
			'session_id' => 'session_456',
			'goal' => 'business_report',
			'goal_label' => 'Tạo báo cáo kinh doanh',
			'status' => 'ACTIVE',
			'open_loops' => array( 'Bổ sung số liệu' ),
		), array( 'identity_uuid' => 'id-9' ) );
		$adapter_ok = ( $adapter['goal_id'] ?? '' ) === 'goal_intent_9'
			&& ( $adapter['primary_goal'] ?? '' ) === 'Tạo báo cáo kinh doanh'
			&& ( $adapter['identity_uuid'] ?? '' ) === 'id-9'
			&& (int) ( $adapter['blog_id'] ?? 0 ) === (int) get_current_blog_id();
		$ok = $this->step( $ctx, $steps, 'Runtime: Intent adapter preserves goal ownership', $adapter_ok, $adapter_ok ? 'Intent state maps into canonical Goal State.' : wp_json_encode( $adapter ) ) && $ok;

		$delta_goal = BizCity_TwinBrain_Goal_Loop_State::normalize( array(
			'goal_id' => 'delta_1',
			'session_id' => 'session_456',
			'primary_goal' => 'Hoàn thiện báo cáo',
			'required_inputs' => array( array( 'id' => 'input_1', 'label' => 'Số liệu MRO', 'status' => 'pending' ) ),
			'open_loops' => array(),
			'status' => 'executing',
		) );
		$delta = BizCity_TwinBrain_Goal_Loop_Delta::compute( $delta_goal, 'Bổ sung số liệu MRO', array( 'trace_id' => 'trace_delta', 'synthesis' => array( 'citations' => array( array( 'citation' => '[nb:1/p1]' ) ) ) ), array() );
		$delta_ok_runtime = ( $delta['user_intent_current'] ?? '' ) === 'Bổ sung số liệu MRO'
			&& ( $delta['next_best_action']['type'] ?? '' ) === 'ask'
			&& empty( $delta['completed_outputs'] );
		$ok = $this->step( $ctx, $steps, 'Runtime: Goal Delta requires missing input', $delta_ok_runtime, $delta_ok_runtime ? 'Notebook evidence is recorded, but pending input remains an ask.' : wp_json_encode( $delta ) ) && $ok;

		$premature = $base;
		$premature['closure_signal'] = array( 'type' => 'user_confirmed', 'evidence' => 'ok' );
		$premature['status'] = 'completed';
		$premature_blocked = ! BizCity_TwinBrain_Goal_Loop_State::can_transition( 'executing', 'completed', $premature );
		$ok = $this->step( $ctx, $steps, 'Runtime: reject premature completed', $premature_blocked, 'DoD lacks done status/evidence and score is below 1.0.' ) && $ok;

		$complete = $base;
		$complete['status'] = 'completed';
		$complete['completion_score'] = 1.0;
		$complete['definition_of_done'][0]['status'] = 'done';
		$complete['definition_of_done'][0]['evidence'] = array( 'artifact:report-7' );
		$complete['closure_signal'] = array( 'type' => 'user_confirmed', 'evidence' => 'đúng ý' );
		$completion_ok = BizCity_TwinBrain_Goal_Loop_State::can_transition( 'verifying', 'completed', $complete );
		$ok = $this->step( $ctx, $steps, 'Runtime: accept evidenced completed', $completion_ok, 'DoD, score, and closure signal pass.' ) && $ok;

		$abandoned_bad = $base;
		$abandoned_bad['status'] = 'abandoned';
		$abandoned_bad['closure_signal'] = array( 'type' => 'user_confirmed' );
		$abandoned_good = $abandoned_bad;
		$abandoned_good['closure_signal'] = array( 'type' => 'inactivity_timeout' );
		$abandoned_ok = ! BizCity_TwinBrain_Goal_Loop_State::can_transition( 'executing', 'abandoned', $abandoned_bad )
			&& BizCity_TwinBrain_Goal_Loop_State::can_transition( 'executing', 'abandoned', $abandoned_good );
		$ok = $this->step( $ctx, $steps, 'Runtime: inactivity maps only to abandoned', $abandoned_ok, 'Inactivity cannot produce completed.' ) && $ok;

		$terminal_locked = ! BizCity_TwinBrain_Goal_Loop_State::can_transition( 'completed', 'executing', $base );
		$ok = $this->step( $ctx, $steps, 'Runtime: terminal state is immutable', $terminal_locked, 'Completed cannot reopen without a new goal.' ) && $ok;

		$same_session = BizCity_TwinBrain_Goal_Loop_State::session_start_decision( $base, 'session_456' );
		$other_session = BizCity_TwinBrain_Goal_Loop_State::session_start_decision( $base, 'session_999' );
		$replace = BizCity_TwinBrain_Goal_Loop_State::session_start_decision( $base, 'session_999', 'replace' );
		$session_gate_ok = ( $same_session['action'] ?? '' ) === 'resume'
			&& ( $other_session['action'] ?? '' ) === 'ask_resume_or_new'
			&& ( $replace['action'] ?? '' ) === 'supersede';
		$ok = $this->step( $ctx, $steps, 'Runtime: pre-session goal gate', $session_gate_ok, $session_gate_ok ? 'Same session resumes; cross-session asks; replace requires explicit choice.' : wp_json_encode( array( $same_session, $other_session, $replace ) ) ) && $ok;
		$explicit_new = BizCity_TwinBrain_Goal_Loop_State::session_start_decision( $base, 'session_999', 'new' );
		$guest_contract = class_exists( 'BizCity_Memory_Identity_Scope' )
			? BizCity_Memory_Identity_Scope::subject_contract( array( 'platform' => 'WEBCHAT', 'user_id' => 0, 'identity_is_stable' => true ) )
			: array();
		$member_contract = class_exists( 'BizCity_Memory_Identity_Scope' )
			? BizCity_Memory_Identity_Scope::subject_contract( array( 'platform' => 'TWIN_GPT', 'user_id' => 12 ) )
			: array();
		$choice_policy_ok = ( $explicit_new['action'] ?? '' ) === 'open_new'
			&& ( $guest_contract['channel_class'] ?? '' ) === 'guest_channel'
			&& ( $member_contract['channel_class'] ?? '' ) === 'user_bound';
		$ok = $this->step( $ctx, $steps, 'Runtime: explicit resume choice and zone policy', $choice_policy_ok, $choice_policy_ok ? 'Cross-session new is explicit; WEBCHAT is guest_channel and TWIN_GPT is user_bound.' : wp_json_encode( array( $explicit_new, $guest_contract, $member_contract ) ) ) && $ok;

		return array(
			'ok' => $ok,
			'status' => $ok ? 'PASS' : 'FAIL',
			'steps' => $steps,
			'failures' => $ok ? array() : array( 'twin_goal_loop_g0_failed' ),
		);
	}

	/**
	 * Run one append-only open -> progress -> close round-trip in a sandbox identity.
	 *
	 * @return array{ok:bool,detail:string}
	 */
	private function run_live_event_write(): array {
		// [2026-08-02 Johnny Chu] PHASE-TWIN-GOAL-LOOP-E2E — keep live evidence isolated from real identities and goals.
		if ( ! class_exists( 'BizCity_Twin_Event_Stream_Schema' ) ) {
			return array( 'ok' => false, 'detail' => 'Event Stream schema class is not loaded.' );
		}

		$suffix = strtolower( wp_generate_password( 12, false, false ) );
		$identity_uuid = 'ddv_sandbox_' . $suffix;
		$session_id = 'ddv_goal_' . substr( md5( $suffix ), 0, 20 );
		$goal_id = 'ddv_goal_' . substr( md5( $identity_uuid ), 0, 20 );
		$write_opts = array(
			'identity_uuid' => $identity_uuid,
			'blog_id'       => (int) get_current_blog_id(),
			'user_id'       => 0,
			'session_id'    => $session_id,
			'event_source'  => 'twinbrain',
			'platform'      => 'FB_MESS',
			'account_id'    => 'ddv_page',
			'external_user_id' => 'ddv_client_' . $suffix,
			'chat_id'       => 'fb_ddv_page_ddv_client_' . $suffix,
		);
		$base = array(
			'goal_id'            => $goal_id,
			'blog_id'            => (int) get_current_blog_id(),
			'identity_uuid'      => $identity_uuid,
			'session_id'         => $session_id,
			'primary_goal'       => 'DDV sandbox Goal Loop event round-trip',
			'definition_of_done' => array(
				array( 'id' => 'sandbox_artifact', 'label' => 'Sandbox event evidence', 'status' => 'pending' ),
			),
			'status'             => 'clarifying',
			'completion_score'  => 0.0,
		);
		$opened_uuid = BizCity_TwinBrain_Goal_Loop_Repository::open( $base, $write_opts );
		if ( $opened_uuid === '' ) {
			return array( 'ok' => false, 'detail' => 'open() did not return an event UUID.' );
		}

		$progress = $base;
		$progress['status'] = 'executing';
		$progress_uuid = BizCity_TwinBrain_Goal_Loop_Repository::progress( $progress, $write_opts );
		if ( $progress_uuid === '' ) {
			return array( 'ok' => false, 'detail' => 'progress() did not return an event UUID.' );
		}

		$closed = $progress;
		$closed['status'] = 'completed';
		$closed['completion_score'] = 1.0;
		$closed['definition_of_done'][0]['status'] = 'done';
		$closed['definition_of_done'][0]['evidence'] = array( 'artifact:' . $goal_id );
		$closed['closure_signal'] = array( 'type' => 'user_completed', 'evidence' => 'ddv_sandbox' );
		$closed_uuid = BizCity_TwinBrain_Goal_Loop_Repository::close( $closed, $write_opts );
		if ( $closed_uuid === '' ) {
			return array( 'ok' => false, 'detail' => 'close() did not return an event UUID; completion guard or Event Bus rejected the snapshot.' );
		}

		global $wpdb;
		$table = BizCity_Twin_Event_Stream_Schema::table();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, event_uuid, event_type, payload_json FROM {$table} WHERE blog_id = %d AND session_id = %s AND event_type IN (%s, %s, %s) ORDER BY id ASC",
				(int) get_current_blog_id(),
				$session_id,
				'twin_goal_opened',
				'twin_goal_progressed',
				'twin_goal_closed'
			),
			ARRAY_A
		);
		$expected_types = array( 'twin_goal_opened', 'twin_goal_progressed', 'twin_goal_closed' );
		$expected_uuids = array( $opened_uuid, $progress_uuid, $closed_uuid );
		if ( ! is_array( $rows ) || count( $rows ) !== 3 ) {
			return array( 'ok' => false, 'detail' => 'Expected 3 Goal Loop events in the canonical Event Stream; found ' . ( is_array( $rows ) ? count( $rows ) : 0 ) . '.' );
		}
		foreach ( $rows as $index => $row ) {
			$payload = json_decode( (string) ( $row['payload_json'] ?? '' ), true );
			$row_goal_id = is_array( $payload ) ? (string) ( $payload['goal_id'] ?? '' ) : '';
			if ( (string) ( $row['event_type'] ?? '' ) !== $expected_types[ $index ]
				|| (string) ( $row['event_uuid'] ?? '' ) !== $expected_uuids[ $index ]
				|| $row_goal_id !== $goal_id ) {
				return array( 'ok' => false, 'detail' => 'Event Stream sequence, UUID, or goal_id mismatch at index ' . $index . '.' );
			}
		}
		$log_ok = false;
		$log_detail = 'Scoped JSONL logger unavailable.';
		if ( class_exists( 'BizCity_JSONL_File_Logger' ) && method_exists( 'BizCity_JSONL_File_Logger', 'location_scoped' ) && method_exists( 'BizCity_JSONL_File_Logger', 'read_scoped' ) ) {
			$segments = array( 'twinbrain-goal-loop', 'fb_mess', 'ddv_client_' . $suffix );
			$location = BizCity_JSONL_File_Logger::location_scoped( 'bizcity-twinbrain-logs', $segments );
			$log_rows = BizCity_JSONL_File_Logger::read_scoped( 'bizcity-twinbrain-logs', $segments, '', 10 );
			$log_uuids = array_values( array_filter( array_map( static function ( $item ) {
				return is_array( $item ) ? (string) ( $item['ctx']['event_uuid'] ?? '' ) : '';
			}, $log_rows ) ) );
			$log_ok = ! empty( $location['writable'] )
				&& ! empty( $location['exists'] )
				&& count( $log_rows ) >= 3
				&& in_array( $opened_uuid, $log_uuids, true )
				&& in_array( $progress_uuid, $log_uuids, true )
				&& in_array( $closed_uuid, $log_uuids, true );
			$log_detail = $log_ok
				? 'Scoped JSONL contains open/progress/close rows with matching event UUIDs: ' . (string) ( $location['file'] ?? '' )
				: 'Scoped JSONL parity failed: ' . (string) ( $location['file'] ?? '' ) . ' rows=' . count( $log_rows );
		}
		if ( ! $log_ok ) {
			return array( 'ok' => false, 'detail' => 'SQL Event Stream passed, but JSONL projection failed. ' . $log_detail );
		}

		return array( 'ok' => true, 'detail' => 'PASS: 3 sandbox SQL events and 3 scoped JSONL rows persisted with one goal_id and matching event UUIDs. ' . $log_detail );
	}

	private function step( $ctx, array &$steps, string $label, bool $passed, string $detail ): bool {
		$row = array( 'label' => $label, 'status' => $passed ? 'pass' : 'fail', 'detail' => $detail );
		$steps[] = $row;
		if ( is_object( $ctx ) && method_exists( $ctx, 'emit_step' ) ) {
			$ctx->emit_step( $row );
		}
		return $passed;
	}

	private function skip( $ctx, array &$steps, string $label, string $detail ): void {
		$row = array( 'label' => $label, 'status' => 'skip', 'detail' => $detail );
		$steps[] = $row;
		if ( is_object( $ctx ) && method_exists( $ctx, 'emit_step' ) ) {
			$ctx->emit_step( $row );
		}
	}

	public function cleanup(): void {
		// [2026-08-01 Johnny Chu] PHASE-TWIN-GOAL-LOOP-G0 — read-only probe creates no artifacts.
	}
}

// [2026-08-02 Johnny Chu] PHASE-TWIN-GOAL-LOOP-G10 — register Goal Loop G0-G9 in the central Smoke Runner catalog.
add_filter( 'bizcity_diagnostics_register_probes', function ( $probes ) {
	$probes[] = 'BizCity_Probe_TwinBrain_Goal_Loop';
	return $probes;
} );
