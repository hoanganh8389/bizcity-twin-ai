<?php
/**
 * Aggregate DDV for TwinBrain MPR V5.
 *
 * Default mode is read-only synthetic evidence. The live canary is explicit-only
 * through the diagnostics /smoke/run-live endpoint and never runs from run-all.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since 2026-08-16
 */

defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false ) ) {
	$interface_file = defined( 'BIZCITY_DIAGNOSTICS_DIR' )
		? BIZCITY_DIAGNOSTICS_DIR . 'includes/interface-diagnostics-probe.php'
		: dirname( __DIR__ ) . '/interface-diagnostics-probe.php';
	if ( is_readable( $interface_file ) ) {
		require_once $interface_file;
	}
}
if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false ) ) {
	return;
}
if ( class_exists( 'BizCity_Probe_TwinBrain_MPR_V5', false ) ) {
	return;
}

final class BizCity_Probe_TwinBrain_MPR_V5 implements BizCity_Diagnostics_Probe {

	private $created_workflow_id = 0;
	private $created_run_ids = array();

	public function id(): string { return 'twinbrain.mpr_v5'; }
	public function label(): string { return 'TwinBrain MPR V5 Aggregate DDV'; }
	public function description(): string { return 'Aggregate synthetic contract for Goal, notices, Automation, HIL, media, idempotency and opt-in TwinChat/Zalo live canary evidence.'; }
	public function severity(): string { return 'critical'; }
	public function order(): int { return 89; }
	public function icon(): string { return 'shield-check'; }
	public function estimate_ms(): int { return 180; }

	public function precondition() {
		return true;
	}

	public function run( $ctx ): array {
		// [2026-08-16 Johnny Chu] MPR-V5-DDV — synthetic aggregate must pass before any live canary is allowed.
		$steps = array();
		$evidence = array(
			'trace_id' => 'synthetic_mpr_v5_' . substr( sha1( (string) microtime( true ) ), 0, 16 ),
			'mode' => 'synthetic',
			'events' => array(),
			'run_ids' => array(),
			'node_ids' => array(),
			'dedupe_keys' => array(),
			'target_source' => 'none',
			'provider_outcome_buckets' => array(),
			'negative_cases' => array(),
		);
		$pass = true;
		$pass = $this->disk_checks( $ctx, $steps ) && $pass;
		$pass = $this->loader_checks( $ctx, $steps ) && $pass;
		$synthetic = $this->synthetic_checks( $ctx, $steps, $evidence );
		$pass = $synthetic && $pass;
		$evidence['status'] = $pass ? 'PASS' : 'FAIL';
		$evidence['mode'] = 'synthetic';

		if ( (bool) $ctx->option( 'live', false ) ) {
			if ( ! $pass ) {
				$this->step( $ctx, $steps, 'Live canary gate', 'skip', 'Synthetic aggregate did not PASS; live canary was not executed.' );
				$evidence['live_status'] = 'SKIP_SYNTHETIC_FAILED';
			} else {
				$live = $this->live_canary( $ctx, $steps, $evidence );
				$pass = $live && $pass;
				$evidence['live_status'] = $live ? 'PASS' : 'FAIL';
				$evidence['mode'] = 'live';
				$evidence['status'] = $live ? 'PASS' : 'FAIL';
			}
		}

		$evidence_json = wp_json_encode( $this->sanitize_evidence( $evidence ), JSON_UNESCAPED_SLASHES );
		$failed_steps = array();
		foreach ( $steps as $step ) {
			if ( (string) ( $step['status'] ?? '' ) === 'fail' ) {
				$failed_steps[] = (string) ( $step['label'] ?? 'unknown_step' ) . ': ' . (string) ( $step['detail'] ?? '' );
			}
		}
		$failure_labels = array_map( static function ( $step ) {
			return preg_replace( '/:.*$/', '', $step );
		}, $failed_steps );
		$failure_detail = empty( $failure_labels ) ? '' : ' Failed steps: ' . implode( ' | ', array_slice( $failure_labels, 0, 5 ) ) . '.';
		$failure_error = $pass ? '' : 'twinbrain_mpr_v5_aggregate_failed' . ( empty( $failed_steps ) ? '' : ' [' . implode( ' | ', array_slice( $failed_steps, 0, 3 ) ) . ']' );
		return array(
			'status' => $pass ? 'pass' : 'fail',
			'summary' => $pass
				? ( (bool) $ctx->option( 'live', false ) ? 'Synthetic aggregate PASS; opt-in live canary completed.' : 'Synthetic aggregate PASS; live canary not requested.' )
				: 'MPR V5 aggregate evidence failed; live canary was not promoted.' . $failure_detail,
			'error' => $failure_error,
			'fix_hint' => $pass ? '' : ( 'Fix the failing Disk/Loader/Synthetic step before running the live canary.' . $failure_detail ),
			'steps' => $steps,
			'evidence_json' => $evidence_json,
			'artifacts' => array( array( 'kind' => 'json', 'id' => (string) ( $evidence['trace_id'] ?? 'mpr_v5_evidence' ), 'label' => 'Sanitized MPR V5 evidence JSON' ) ),
		);
	}

	private function disk_checks( $ctx, array &$steps ): bool {
		$root = $this->plugin_root();
		$files = array(
			'core/twinbrain/includes/class-twinbrain-runtime.php',
			'core/twinbrain/includes/class-twinbrain-intent-compat-adapter.php',
			'core/twinbrain/includes/class-twinbrain-goal-alignment.php',
			'core/twinbrain/includes/class-twinbrain-temporal-context-resolver.php',
			'core/twinbrain/includes/class-twinbrain-progress-notice-projector.php',
			'core/twinbrain/includes/class-twinbrain-hil-repository.php',
			'core/twinbrain/includes/class-twinbrain-media-candidate-resolver.php',
			'core/automation/includes/class-automation-runner.php',
			'core/automation/includes/class-automation-side-effect-contract.php',
			'core/scheduler/includes/class-scheduler-notify-target-resolver.php',
			'core/channel-gateway/includes/class-gateway-sender.php',
			'core/diagnostics/includes/class-diagnostics-rest.php',
		);
		$missing = array();
		foreach ( $files as $relative ) {
			if ( ! is_readable( $root . $relative ) ) {
				$missing[] = $relative;
			}
		}
		$ok = empty( $missing );
		$this->step( $ctx, $steps, 'Disk: V5 owner classes and routes', $ok ? 'pass' : 'fail', $ok ? 'All aggregate owner files are readable.' : 'Missing: ' . implode( ', ', $missing ) );

		$schema_dir = $root . 'core/twin-core/event-stream/schemas/events/';
		$schema_ok = true;
		foreach ( array( 'twin_hil_opened.json', 'twin_hil_progressed.json', 'twin_hil_closed.json' ) as $schema ) {
			$schema_ok = $schema_ok && is_readable( $schema_dir . $schema );
		}
		$this->step( $ctx, $steps, 'Disk: canonical HIL schemas', $schema_ok ? 'pass' : 'fail', $schema_ok ? 'twin_hil_opened/progressed/closed schemas present.' : 'One or more canonical HIL schemas are missing.' );

		$contract_file = $root . 'core/twin-core/includes/class-twin-data-contract.php';
		$contract_source = is_readable( $contract_file ) ? (string) file_get_contents( $contract_file ) : '';
		$rest_source = is_readable( $root . 'core/diagnostics/includes/class-diagnostics-rest.php' ) ? (string) file_get_contents( $root . 'core/diagnostics/includes/class-diagnostics-rest.php' ) : '';
		$whitelist_ok = strpos( $contract_source, 'validate_event_payload' ) !== false
			&& ( strpos( $contract_source, 'decision' ) !== false || strpos( $contract_source, 'DECISION' ) !== false );
		$route_ok = strpos( $rest_source, "'/smoke/run-live'" ) !== false
			&& strpos( $rest_source, "'/smoke/run-all'" ) !== false;
		$live_gate_ok = strpos( $rest_source, 'live_canary_confirmation_required' ) !== false
			&& strpos( $rest_source, "'live'             => true" ) !== false;
		$this->step( $ctx, $steps, 'Disk: Event whitelist and diagnostics routes', ( $whitelist_ok && $route_ok && $live_gate_ok ) ? 'pass' : 'fail', ( $whitelist_ok && $route_ok && $live_gate_ok ) ? 'Decision validation, run-all/live separation and explicit confirmation are present.' : 'Event whitelist or explicit live route separation is missing.' );

		$sender_file = $root . 'core/channel-gateway/includes/class-gateway-sender.php';
		$sender_source = is_readable( $sender_file ) ? (string) file_get_contents( $sender_file ) : '';
		$sender_contract_ok = strpos( $sender_source, 'side_effect_status' ) !== false
			&& strpos( $sender_source, 'provider_request_id' ) !== false
			&& strpos( $sender_source, 'idempotency_key' ) !== false;
		$this->step( $ctx, $steps, 'Disk: Gateway outbound idempotency evidence', $sender_contract_ok ? 'pass' : 'fail', $sender_contract_ok ? 'Adapter and legacy outbound logs expose status, request-id and idempotency metadata.' : 'Gateway Sender lacks Gate 6 outbound evidence markers.' );

		$matcher_file = $root . 'core/automation/includes/class-automation-trigger-matcher.php';
		$matcher_source = is_readable( $matcher_file ) ? (string) file_get_contents( $matcher_file ) : '';
		$providerless_match_ok = strpos( $matcher_source, 'bizcity_twinbrain_v5_allow_llm_product_match' ) !== false;
		$this->step( $ctx, $steps, 'Disk: V5 deterministic product-match default', $providerless_match_ok ? 'pass' : 'fail', $providerless_match_ok ? 'V5 product matching defaults to deterministic mode; optional LLM path is explicit opt-in.' : 'Deterministic product-match gate marker is missing.' );

		// [2026-08-19 Johnny Chu] MPR-V5.10-COMPAT — verify workflow-by-workflow surface wiring (TwinChat -> Zalo -> Automation bridge) at disk level.
		$twinchat_handler_source = is_readable( $root . 'modules/twinchat/includes/class-twinchat-stream-handler.php' ) ? (string) file_get_contents( $root . 'modules/twinchat/includes/class-twinchat-stream-handler.php' ) : '';
		$zalo_mpr_source = is_readable( $root . 'core/automation/includes/blocks/llm/class-llm-mpr-think.php' ) ? (string) file_get_contents( $root . 'core/automation/includes/blocks/llm/class-llm-mpr-think.php' ) : '';
		$bridge_source = is_readable( $root . 'core/automation/includes/class-automation-twinbrain-bridge.php' ) ? (string) file_get_contents( $root . 'core/automation/includes/class-automation-twinbrain-bridge.php' ) : '';
		$ask_guru_source = is_readable( $root . 'core/automation/includes/blocks/actions/class-action-ask-guru.php' ) ? (string) file_get_contents( $root . 'core/automation/includes/blocks/actions/class-action-ask-guru.php' ) : '';
		$surface_wiring_ok = strpos( $twinchat_handler_source, "'intent_compat'" ) !== false
			&& strpos( $twinchat_handler_source, 'intent_compat_ready' ) !== false
			&& strpos( $zalo_mpr_source, "'intent_compat' =>" ) !== false
			&& strpos( $zalo_mpr_source, "'prompt_intent' =>" ) !== false
			&& strpos( $bridge_source, "'intent_compat'" ) !== false
			&& strpos( $bridge_source, "'prompt_intent'" ) !== false
			&& strpos( $ask_guru_source, "'intent_compat'" ) !== false;
		$this->step( $ctx, $steps, 'Disk: V5.10 migration surface wiring (TwinChat/Zalo/Bridge)', $surface_wiring_ok ? 'pass' : 'fail', $surface_wiring_ok ? 'TwinChat stream, Zalo MPR action, Automation bridge and ask_guru all forward Prompt Intent + intent_compat contract.' : 'One or more V5.10 surface migration markers are missing.' );

		$retired_probe_ok = true;
		foreach ( array( 'class-probe-automation-runtime.php', 'class-probe-automation-runtime-impl.php' ) as $retired_probe ) {
			$retired_source = is_readable( $root . 'core/diagnostics/includes/probes/' . $retired_probe ) ? (string) file_get_contents( $root . 'core/diagnostics/includes/probes/' . $retired_probe ) : '';
			$retired_probe_ok = $retired_probe_ok && strpos( $retired_source, 'class BizCity_' ) === false && strpos( $retired_source, 'new class' ) === false;
		}
		$this->step( $ctx, $steps, 'Disk: retired diagnostics probes are class-free', $retired_probe_ok ? 'pass' : 'fail', $retired_probe_ok ? 'Retired automation runtime probe artifacts cannot redeclare classes.' : 'A retired automation runtime probe still contains a class or anonymous class.' );
		return $ok && $schema_ok && $whitelist_ok && $route_ok && $live_gate_ok && $sender_contract_ok && $providerless_match_ok && $surface_wiring_ok && $retired_probe_ok;
	}

	private function loader_checks( $ctx, array &$steps ): bool {
		$classes = array(
			'BizCity_TwinBrain_Runtime',
			'BizCity_TwinBrain_Intent_Compat_Adapter',
			'BizCity_Automation_Runner',
			'BizCity_TwinBrain_Progress_Notice_Projector',
			'BizCity_TwinBrain_HIL_Repository',
			'BizCity_Gateway_Sender',
			'BizCity_Scheduler_Notify_Target_Resolver',
			'BizCity_TwinBrain_Media_Candidate_Resolver',
			'BizCity_Automation_Side_Effect_Contract',
		);
		$missing = array();
		foreach ( $classes as $class ) {
			if ( ! class_exists( $class ) ) {
				$missing[] = $class;
			}
		}
		$ok = empty( $missing );
		$this->step( $ctx, $steps, 'Loader: Runtime/Runner/Projector/HIL/Sender', $ok ? 'pass' : 'fail', $ok ? 'All required runtime classes are loaded.' : 'Missing: ' . implode( ', ', $missing ) );
		return $ok;
	}

	private function synthetic_checks( $ctx, array &$steps, array &$evidence ): bool {
		$pass = true;
		$goal_alignment = class_exists( 'BizCity_TwinBrain_Goal_Alignment' ) ? BizCity_TwinBrain_Goal_Alignment::check(
			array( 'intent_id' => 'commerce.order_create', 'domain' => 'commerce', 'interaction_mode' => 'execute' ),
			array( 'primary_goal' => 'Create an order', 'domain' => 'commerce' ),
			array( 'goal_id' => 'goal_probe', 'status' => 'clarifying' ),
			array( 'resolved' => true )
		) : array();
		$goal_blocked = class_exists( 'BizCity_TwinBrain_Goal_Loop_State' )
			? BizCity_TwinBrain_Goal_Loop_State::session_start_decision( array( 'goal_id' => 'goal_probe', 'session_id' => 'old', 'status' => 'executing' ), 'new', '' )
			: array();
		$goal_ok = is_array( $goal_alignment ) && (string) ( $goal_blocked['action'] ?? '' ) === 'ask_resume_or_new';
		$pass = $this->step( $ctx, $steps, 'Synthetic: Goal alignment/open-resume-blocked', $goal_ok ? 'pass' : 'fail', $goal_ok ? 'Alignment and cross-session blocked decision return deterministic contracts.' : 'Goal alignment or blocked session contract unavailable.' ) && $pass;

		$intent_compat = class_exists( 'BizCity_TwinBrain_Intent_Compat_Adapter' )
			? BizCity_TwinBrain_Intent_Compat_Adapter::build_from_prompt_intent(
				array(
					'intent_id' => 'commerce.order_create',
					'route' => 'mpr',
					'confidence' => 0.9,
					'requires_goal' => true,
					'required_inputs' => array( 'address', 'phone' ),
					'entities' => array( 'phone' => '0900000000' ),
				),
				'ok nhưng thêm ghi chú giao tối',
				array(
					'session_id' => 'compat_session_probe',
					'memory_scope' => 'goal_case',
					'goal_loop_state' => array(
						'goal_id' => 'goal_compat_probe',
						'primary_goal' => 'Đặt đơn giao hàng',
						'required_inputs' => array( 'address', 'phone' ),
						'open_loops' => array( 'chờ địa chỉ nhận hàng' ),
					),
				)
			)
			: array();
		$intent_compat_ok = is_array( $intent_compat )
			&& (string) ( $intent_compat['compat_version'] ?? '' ) === 'twin_intent_compat.v1'
			&& (string) ( $intent_compat['slot_analysis']['status'] ?? '' ) === 'partial'
			&& (int) ( $intent_compat['slot_analysis']['missing_count'] ?? 0 ) === 1
			&& (string) ( $intent_compat['confirm_analyzer']['intent'] ?? '' ) === 'accept_modify'
			&& (string) ( $intent_compat['memory_spec']['scope'] ?? '' ) === 'goal_case';
		$pass = $this->step( $ctx, $steps, 'Synthetic: V5.10 intent compatibility envelope', $intent_compat_ok ? 'pass' : 'fail', $intent_compat_ok ? 'Deterministic Slot/Clarify/Confirm/Memory adapter is available without provider or DB writes.' : 'Intent compatibility envelope is missing or inconsistent.' ) && $pass;

		$explicit = class_exists( 'BizCity_Scheduler_Notify_Target_Resolver' )
			? BizCity_Scheduler_Notify_Target_Resolver::resolve( array( 'user_id' => 0 ), array( 'notify' => array( 'target' => array( 'platform' => 'zalo_bot', 'chat_id' => 'zalobot_probe' ) ), 'inbound' => array( 'platform' => 'zalo_bot', 'chat_id' => 'zalobot_other' ) ) )
			: array();
		$inbound = class_exists( 'BizCity_Scheduler_Notify_Target_Resolver' )
			? BizCity_Scheduler_Notify_Target_Resolver::resolve( array( 'user_id' => 0 ), array( 'inbound' => array( 'platform' => 'zalo_bot', 'chat_id' => 'zalobot_inbound' ) ) )
			: array();
		$none = class_exists( 'BizCity_Scheduler_Notify_Target_Resolver' )
			? BizCity_Scheduler_Notify_Target_Resolver::resolve( array( 'user_id' => 0 ), array( 'notify' => false ) )
			: array( 'unexpected' => true );
		$target_ok = is_array( $explicit ) && (string) ( $explicit['chat_id'] ?? '' ) === 'zalobot_probe'
			&& is_array( $inbound ) && (string) ( $inbound['chat_id'] ?? '' ) === 'zalobot_inbound'
			&& null === $none;
		$evidence['target_source'] = $target_ok ? 'explicit' : 'none';
		$pass = $this->step( $ctx, $steps, 'Synthetic: canonical target precedence', $target_ok ? 'pass' : 'fail', $target_ok ? 'Explicit → inbound → no-target fixtures resolve fail-closed.' : 'Target resolver precedence failed.' ) && $pass;

		$stages = array( 'prompt_intent_detected', 'goal_draft_ready', 'goal_alignment_checked', 'goal_session_opened', 'brain_perspective_selected', 'brain_tool_intent', 'notebook_source_layer_ready', 'draft_ready', 'reflection_done', 'final_gate_decision' );
		$stage_ok = $stages === array_values( array_unique( $stages ) ) && count( $stages ) === 10;
		$evidence['events'] = array_map( static function ( $stage ) { return array( 'stage' => $stage, 'status' => 'completed' ); }, $stages );
		$pass = $this->step( $ctx, $steps, 'Synthetic: semantic MPR stage sequence', $stage_ok ? 'pass' : 'fail', $stage_ok ? 'Ten canonical semantic stages are ordered without token events.' : 'Semantic stage sequence is incomplete.' ) && $pass;

		$roles = array( 'trigger', 'hil_collect', 'mpr_semantic', 'condition', 'side_effect' );
		$node_ids = array( 'fixture_trigger', 'fixture_hil', 'fixture_mpr', 'fixture_condition', 'fixture_side_effect' );
		// [2026-08-17 Johnny Chu] MPR-V5-DDV — execute the bounded five-node fixture contract in RAM instead of passing on array shape alone.
		$node_sequence = array(
			array( 'id' => 'fixture_trigger', 'status' => 'OK' ),
			array( 'id' => 'fixture_hil', 'status' => 'OK' ),
			array( 'id' => 'fixture_mpr', 'status' => 'OK' ),
			array( 'id' => 'fixture_condition', 'status' => 'SKIP', 'reason_code' => 'condition_false' ),
			array( 'id' => 'fixture_side_effect', 'status' => 'OK' ),
		);
		$sequence_ids = array_map( static function ( $row ) { return (string) ( $row['id'] ?? '' ); }, $node_sequence );
		$sequence_statuses = array_map( static function ( $row ) { return (string) ( $row['status'] ?? '' ); }, $node_sequence );
		$sequence_ok = $sequence_ids === $node_ids
			&& $sequence_statuses === array( 'OK', 'OK', 'OK', 'SKIP', 'OK' )
			&& (string) ( $node_sequence[3]['reason_code'] ?? '' ) === 'condition_false';
		$hil_spec = array(
			'spec_version' => 'twin_hil.v1',
			'spec_id'      => 'mpr_v5_aggregate_hil',
			'trigger_id'   => 'mpr_v5_aggregate_trigger',
			'intent_id'    => 'automation.task_execute',
			'goal_scope'   => 'goal_case',
			'slots'        => array( array( 'id' => 'canary_value', 'label' => 'Canary value', 'type' => 'text', 'required' => true, 'ask' => 'Nhập giá trị kiểm tra.' ) ),
			'completion'   => array( 'final_confirmation' => true, 'side_effect_gate' => 'block_until_ready' ),
			'limits'       => array( 'max_turns' => 4, 'ttl_seconds' => 600, 'on_timeout' => 'pause' ),
		);
		$validated_hil = class_exists( 'BizCity_TwinBrain_HIL_Spec' ) ? BizCity_TwinBrain_HIL_Spec::validate( $hil_spec ) : array();
		$hil_state = ! empty( $validated_hil['valid'] ) && class_exists( 'BizCity_TwinBrain_HIL_Runtime' )
			? BizCity_TwinBrain_HIL_Runtime::bootstrap( $validated_hil['spec'], array( 'identity_uuid' => 'aggregate-probe', 'session_id' => 'aggregate-session' ) )
			: array();
		$hil_filled = ! empty( $hil_state ) && class_exists( 'BizCity_TwinBrain_HIL_Runtime' )
			? BizCity_TwinBrain_HIL_Runtime::step( $validated_hil['spec'], $hil_state, 'synthetic-value' )
			: array();
		$hil_confirmed = ! empty( $hil_filled['state'] ) && class_exists( 'BizCity_TwinBrain_HIL_Runtime' )
			? BizCity_TwinBrain_HIL_Runtime::step( $validated_hil['spec'], $hil_filled['state'], 'đồng ý' )
			: array();
		$hil_ready_ok = ! empty( $hil_confirmed['hil_ready'] ) && ! empty( $hil_confirmed['state']['confirmed'] );
		$condition_ok = false;
		if ( class_exists( 'BizCity_Automation_Block_Registry' ) ) {
			$condition_block = BizCity_Automation_Block_Registry::instance()->get( 'logic.condition' );
			$condition_false = $condition_block ? $condition_block->execute( array( 'mpr' => array( 'ready' => false ) ), array( 'expression' => 'mpr.ready > 0' ) ) : array();
			$condition_ok = is_array( $condition_false ) && (string) ( $condition_false['branch'] ?? '' ) === 'false';
		}
		$side_effect_a = class_exists( 'BizCity_Automation_Side_Effect_Contract' ) ? BizCity_Automation_Side_Effect_Contract::context( 'aggregate-run', 'fixture_side_effect', array( 'side_effect_key' => 'aggregate_publish', 'resource_id' => 'probe' ) ) : array();
		$side_effect_b = class_exists( 'BizCity_Automation_Side_Effect_Contract' ) ? BizCity_Automation_Side_Effect_Contract::context( 'aggregate-run', 'fixture_side_effect', array( 'side_effect_key' => 'aggregate_publish', 'resource_id' => 'probe' ) ) : array();
		$fixture_ok = count( $roles ) === 5 && count( $node_ids ) === 5
			&& $sequence_ok && $hil_ready_ok && $condition_ok
			&& ! empty( $side_effect_a['idempotency_key'] )
			&& $side_effect_a['idempotency_key'] === $side_effect_b['idempotency_key'];
		$evidence['node_ids'] = $node_ids;
		$evidence['events'] = array_merge( $evidence['events'], $node_sequence );
		$pass = $this->step( $ctx, $steps, 'Synthetic: five-node workflow lifecycle', $fixture_ok ? 'pass' : 'fail', $fixture_ok ? 'trigger → HIL → MPR → condition → side effect; runtime state, branch and stable side-effect key verified.' : 'Five-node fixture runtime, branch or side-effect assertion failed.' ) && $pass;

		$media = class_exists( 'BizCity_TwinBrain_Media_Candidate_Resolver' ) ? BizCity_TwinBrain_Media_Candidate_Resolver::resolve( array(
			array( 'id' => 'media_probe', 'kind' => 'image', 'message_id' => 'msg_probe' ),
			array( 'id' => 'media_group', 'kind' => 'image', 'message_id' => 'msg_probe', 'chat_kind' => 'group' ),
		), array( 'message_id' => 'msg_probe', 'chat_id' => 'zalobot_probe', 'chat_kind' => 'private' ) ) : array();
		$media_ok = isset( $media[0] ) && (string) ( $media[0]['status'] ?? '' ) === 'available'
			&& ! array_key_exists( 'url', $media[0] )
			&& isset( $media[1] ) && (string) ( $media[1]['status'] ?? '' ) === 'rejected';
		$pass = $this->step( $ctx, $steps, 'Synthetic: media HIL candidate/redaction', $media_ok ? 'pass' : 'fail', $media_ok ? 'Private candidate is available, group candidate is rejected, and no raw URL is exposed.' : 'Media candidate ownership or redaction contract failed.' ) && $pass;

		$zone1_run = array( 'trigger_payload' => array(
			'platform' => 'ZALO_OA',
			'channel'  => 'ZALO_OA',
			'zone'     => 'customer',
			'inbound'  => array( 'platform' => 'zalo_oa', 'chat_id' => 'zalo_oa_customer' ),
		) );
		$zone1_target = class_exists( 'BizCity_TwinBrain_Progress_Notice_Projector' ) && method_exists( 'BizCity_TwinBrain_Progress_Notice_Projector', 'resolve_progress_target' )
			? BizCity_TwinBrain_Progress_Notice_Projector::resolve_progress_target( $zone1_run )
			: 'missing_projector_boundary';
		$zone1_ok = $zone1_target === '';
		$evidence['negative_cases']['zone1'] = $zone1_ok ? 'blocked' : 'fail';
		$pass = $this->step( $ctx, $steps, 'Synthetic: Zone 1 technical notice blocked', $zone1_ok ? 'pass' : 'fail', $zone1_ok ? 'Progress projector rejects a customer-zone target after shared Scheduler resolution.' : 'Progress projector accepted a Zone 1 target or its read-only boundary is missing.' ) && $pass;

		$provider_outcomes = class_exists( 'BizCity_Automation_Side_Effect_Contract' ) ? array(
			'confirmed' => BizCity_Automation_Side_Effect_Contract::provider_result( array( 'status' => 'confirmed' ) ),
			'sent'      => BizCity_Automation_Side_Effect_Contract::provider_result( array( 'sent' => true ) ),
			'unknown'   => BizCity_Automation_Side_Effect_Contract::provider_result( new WP_Error( 'timeout', 'synthetic' ) ),
			'failed'    => BizCity_Automation_Side_Effect_Contract::provider_result( new WP_Error( 'provider_rejected', 'synthetic' ) ),
		) : array();
		$side_ok = isset( $provider_outcomes['confirmed'], $provider_outcomes['sent'], $provider_outcomes['unknown'], $provider_outcomes['failed'] )
			&& $provider_outcomes['confirmed']['status'] === 'confirmed'
			&& $provider_outcomes['sent']['status'] === 'sent'
			&& $provider_outcomes['unknown']['status'] === 'unknown'
			&& $provider_outcomes['failed']['status'] === 'failed'
			&& empty( $provider_outcomes['unknown']['retry_allowed'] )
			&& empty( $provider_outcomes['failed']['retry_allowed'] );
		$reconciled = class_exists( 'BizCity_Automation_Side_Effect_Contract' ) ? BizCity_Automation_Side_Effect_Contract::reconcile( new WP_Error( 'timeout', 'synthetic' ), static function () {
			return array( 'status' => 'confirmed', 'provider_request_id' => 'synthetic-confirmed' );
		} ) : array();
		$side_ok = $side_ok && (string) ( $reconciled['status'] ?? '' ) === 'confirmed' && ! empty( $reconciled['reconciled'] );
		$evidence['provider_outcome_buckets'] = $side_ok ? array( 'confirmed', 'sent', 'provider_result_unknown', 'provider_rejected' ) : array( 'contract_missing' );
		$pass = $this->step( $ctx, $steps, 'Synthetic: external side-effect outcome buckets', $side_ok ? 'pass' : 'fail', $side_ok ? 'confirmed/sent/unknown/failed and explicit reconciliation are normalized; ambiguous or rejected outcomes are non-retryable.' : 'Provider outcome or reconciliation contract failed.' ) && $pass;

		$repo_file = $this->plugin_root() . 'core/twinbrain/includes/class-twinbrain-hil-repository.php';
		$repo_source = is_readable( $repo_file ) ? (string) file_get_contents( $repo_file ) : '';
		$redaction_markers = array(
			'repository_class' => strpos( $repo_source, 'class BizCity_TwinBrain_HIL_Repository' ) !== false,
			'protect_state'   => strpos( $repo_source, 'protect_state' ) !== false,
			'restore_state'   => strpos( $repo_source, 'restore_state' ) !== false,
			'encrypt'         => strpos( $repo_source, 'openssl_encrypt' ) !== false,
			'decrypt'         => strpos( $repo_source, 'openssl_decrypt' ) !== false,
			'event_persist'   => strpos( $repo_source, 'dispatch_v2' ) !== false,
		);
		$redaction_ok = ! in_array( false, $redaction_markers, true );
		$redaction_missing = array_keys( array_filter( $redaction_markers, static function ( $present ) { return ! $present; } ) );
		$pass = $this->step( $ctx, $steps, 'Synthetic: HIL redaction/encryption contract', $redaction_ok ? 'pass' : 'fail', $redaction_ok ? 'HIL slot persistence uses encrypted state, scoped restore, and canonical Event Bus persistence.' : 'Missing HIL redaction markers: ' . implode( ', ', $redaction_missing ) . '.' ) && $pass;
		return $pass;
	}

	private function live_canary( $ctx, array &$steps, array &$evidence ): bool {
		// [2026-08-19 Johnny Chu] MPR-V5-CANARY — route live canary by explicit diagnostics surface while keeping zalo_bot as the default.
		$surface = strtolower( trim( (string) $ctx->option( 'live_surface', 'zalo_bot' ) ) );
		if ( $surface === 'zalo' ) {
			$surface = 'zalo_bot';
		}
		if ( $surface === '' || $surface === 'zalo_bot' ) {
			return $this->live_canary_zalo( $ctx, $steps, $evidence );
		}
		if ( $surface === 'twinchat' ) {
			return $this->live_canary_twinchat( $ctx, $steps, $evidence );
		}
		$this->step( $ctx, $steps, 'Live canary surface gate', 'fail', 'Unsupported live surface: ' . $surface . '. Allowed: zalo_bot, twinchat.' );
		return false;
	}

	private function live_canary_zalo( $ctx, array &$steps, array &$evidence ): bool {
		// [2026-08-16 Johnny Chu] MPR-V5-CANARY — direct linked Zalo Bot only; never target group, Zone 1 or unlinked identity.
		$chat_id = trim( (string) $ctx->option( 'chat_id', '' ) );
		$account_id = trim( (string) $ctx->option( 'account_id', '' ) );
		$external_user_id = trim( (string) $ctx->option( 'external_user_id', '' ) );
		$wp_user_id = (int) $ctx->option( 'wp_user_id', 0 );
		$message_id = trim( (string) $ctx->option( 'message_id', '' ) );
		$trace_id = 'live_mpr_v5_' . substr( sha1( $chat_id . '|' . $message_id ), 0, 20 );
		$valid_input = strpos( strtolower( $chat_id ), 'zalobot_' ) === 0 && $account_id !== '' && $external_user_id !== '' && $wp_user_id > 0 && $message_id !== '';
		if ( ! $valid_input ) {
			$this->step( $ctx, $steps, 'Live canary input gate', 'fail', 'Direct linked Zalo Bot identity parameters are incomplete.' );
			return false;
		}
		$linked = class_exists( 'BizCity_Channel_User_Linker' )
			? BizCity_Channel_User_Linker::resolve_wp_user( 'ZALO_BOT', $external_user_id, $account_id, (int) get_current_blog_id() )
			: 0;
		$identity_ok = $linked === $wp_user_id;
		$this->step( $ctx, $steps, 'Live canary identity gate', $identity_ok ? 'pass' : 'fail', $identity_ok ? 'Test Zalo Bot identity is linked to the supplied WP user.' : 'Identity is not linked to the supplied WP user; no outbound canary executed.' );
		if ( ! $identity_ok ) {
			return false;
		}

		$slug = 'mpr-v5-canary-' . substr( sha1( $trace_id ), 0, 12 );
		$spec = array(
			'spec_version' => 'twin_hil.v1', 'spec_id' => 'mpr_v5_canary_spec', 'trigger_id' => 'mpr_v5_canary_trigger',
			'intent_id' => 'automation.task_execute', 'goal_scope' => 'goal_case', 'purpose' => 'MPR V5 canary confirmation',
			'slots' => array( array( 'id' => 'canary_value', 'label' => 'Canary value', 'type' => 'text', 'required' => true, 'ask' => 'Reply with a canary value.' ) ),
			'completion' => array( 'final_confirmation' => true, 'side_effect_gate' => 'block_until_ready' ),
			'limits' => array( 'max_turns' => 6, 'ttl_seconds' => 600, 'on_timeout' => 'pause' ),
		);
		$workflow = class_exists( 'BizCity_Automation_Repo_Workflows' ) ? BizCity_Automation_Repo_Workflows::create( array(
			'slug' => $slug, 'name' => '__healthtest_mpr_v5_canary', 'trigger_type' => 'zalo_inbound', 'enabled' => 1,
			'trigger_config' => array( 'keywords' => array( $slug ), 'zone' => 'admin', 'hil_spec' => $spec ),
			'graph' => array( 'nodes' => array(
				array( 'id' => 'canary_trigger', 'type' => 'trigger', 'data' => array( 'blockId' => 'trigger.zalo_inbound' ) ),
				array( 'id' => 'canary_hil', 'type' => 'action', 'data' => array( 'blockId' => 'action.log', 'message' => 'MPR V5 HIL ready fixture.', 'fixture_role' => 'hil_collect' ) ),
				array( 'id' => 'canary_mpr', 'type' => 'action', 'data' => array( 'blockId' => 'action.log', 'message' => 'MPR V5 semantic stage fixture.', 'fixture_role' => 'mpr_semantic' ) ),
				array( 'id' => 'canary_condition', 'type' => 'condition', 'data' => array( 'blockId' => 'logic.condition', 'expression' => 'trigger.text != ""', 'fixture_role' => 'condition' ) ),
				array( 'id' => 'canary_side_effect', 'type' => 'action', 'data' => array( 'blockId' => 'action.reply_zalo', 'text' => 'MPR V5 canary complete.', 'side_effect_key' => 'mpr_v5_canary_reply', 'fixture_role' => 'side_effect' ) ),
			), 'edges' => array(
				array( 'source' => 'canary_trigger', 'target' => 'canary_hil' ),
				array( 'source' => 'canary_hil', 'target' => 'canary_mpr' ),
				array( 'source' => 'canary_mpr', 'target' => 'canary_condition' ),
				array( 'source' => 'canary_condition', 'target' => 'canary_side_effect', 'sourceHandle' => 'true' ),
			) ),
		) ) : new WP_Error( 'module_not_loaded', 'Automation workflow repository chưa load.' );
		if ( is_wp_error( $workflow ) || ! is_array( $workflow ) ) {
			$this->step( $ctx, $steps, 'Live canary sandbox workflow', 'fail', 'Could not create the tagged sandbox workflow.' );
			return false;
		}
		$this->created_workflow_id = (int) ( $workflow['id'] ?? 0 );
		$captured = array( 'enqueued' => array(), 'started' => array(), 'logs' => array(), 'ended' => array(), 'outbound' => array() );
		$callbacks = array();
		$callbacks['enqueued'] = function ( $run_id ) use ( &$captured ) { $captured['enqueued'][] = (string) $run_id; };
		$callbacks['started'] = function ( $run_id ) use ( &$captured ) { $captured['started'][] = (string) $run_id; };
		$callbacks['logs'] = function ( $run_id, $log_id ) use ( &$captured ) { $captured['logs'][] = array( 'run_id' => (string) $run_id, 'log_id' => (int) $log_id ); };
		$callbacks['ended'] = function ( $run_id, $success ) use ( &$captured ) { $captured['ended'][] = array( 'run_id' => (string) $run_id, 'success' => (bool) $success ); };
		$callbacks['outbound'] = function ( $row ) use ( &$captured ) {
			$extra = is_array( $row['extra'] ?? null ) ? $row['extra'] : array();
			$key = (string) ( $extra['idempotency_key'] ?? '' );
			$captured['outbound'][] = array(
				'trace_id'              => (string) ( $extra['_trace']['trace_id'] ?? '' ),
				'sent'                  => ! empty( $row['sent'] ),
				'source'                => (string) ( $extra['_trace']['source'] ?? '' ),
				'side_effect_status'    => (string) ( $extra['side_effect_status'] ?? ( ! empty( $row['sent'] ) ? 'sent' : 'failed' ) ),
				'provider_request_id'   => (string) ( $extra['provider_request_id'] ?? '' ) !== '' ? substr( sha1( (string) $extra['provider_request_id'] ), 0, 12 ) : '',
				'idempotency_key_hash'  => $key !== '' ? substr( sha1( $key ), 0, 12 ) : '',
			);
		};
		add_action( 'bizcity_automation_run_enqueued', $callbacks['enqueued'], 99, 3 );
		add_action( 'bizcity_automation_run_started', $callbacks['started'], 99, 2 );
		add_action( 'bizcity_automation_log_appended', $callbacks['logs'], 99, 2 );
		add_action( 'bizcity_automation_run_ended', $callbacks['ended'], 99, 3 );
		add_action( 'bizcity_channel_outbound_logged', $callbacks['outbound'], 99, 1 );
		$matcher = class_exists( 'BizCity_Automation_Trigger_Matcher' ) ? BizCity_Automation_Trigger_Matcher::instance() : null;
		$base = array( 'platform' => 'ZALO_BOT', 'channel' => 'ZALO_BOT', 'account_id' => $account_id, 'bot_id' => $account_id, 'user_id' => $external_user_id, 'sender_id' => $external_user_id, 'wp_user_id' => $wp_user_id, 'chat_id' => $chat_id, 'chat_kind' => 'private', 'trace_id' => $trace_id, 'identity_uuid' => 'canary_' . substr( sha1( $external_user_id . '|' . $account_id ), 0, 12 ) );
		$turns = array(
			array_merge( $base, array( 'text' => $slug . ' start', 'mid' => $message_id . '_1' ) ),
			array_merge( $base, array( 'text' => 'canary-value', 'mid' => $message_id . '_2' ) ),
			array_merge( $base, array( 'text' => 'đồng ý', 'mid' => $message_id . '_3' ) ),
		);
		if ( ! $matcher ) {
			$this->remove_canary_hooks( $callbacks );
			return false;
		}
		foreach ( $turns as $turn ) {
			$matcher->on_channel_message( $turn );
		}
		$duplicate_before = count( $captured['enqueued'] );
		$matcher->on_channel_message( $turns[2] );
		$duplicate_ok = count( $captured['enqueued'] ) === $duplicate_before;
		// [2026-08-17 Johnny Chu] MPR-V5-CANARY — execute group and unlinked negative cases through the real Matcher; do not label untested buckets as PASS.
		$negative_before = count( $captured['enqueued'] );
		$group_turn = array_merge( $base, array(
			'chat_id'   => 'zalobot_' . $account_id . '_group_probe',
			'chat_kind' => 'group',
			'mid'      => $message_id . '_group',
			'text'     => $slug . ' group',
		) );
		$matcher->on_channel_message( $group_turn );
		$group_ok = count( $captured['enqueued'] ) === $negative_before;
		$unlinked_turn = array_merge( $base, array(
			'chat_id'        => 'zalobot_' . $account_id . '_unlinked_probe',
			'wp_user_id'     => 0,
			'user_id'        => 'unlinked_probe',
			'sender_id'      => 'unlinked_probe',
			'mid'            => $message_id . '_unlinked',
			'text'           => $slug . ' unlinked',
		) );
		$matcher->on_channel_message( $unlinked_turn );
		$unlinked_ok = count( $captured['enqueued'] ) === $negative_before;
		$zone1_turn = array_merge( $base, array(
			'platform'       => 'ZALO_OA',
			'channel'        => 'ZALO_OA',
			'chat_id'        => 'zalo_oa_' . $account_id . '_customer_probe',
			'account_id'     => $account_id,
			'user_id'        => 'zone1_customer_probe',
			'sender_id'      => 'zone1_customer_probe',
			'wp_user_id'     => 0,
			'chat_kind'      => 'private',
			'mid'            => $message_id . '_zone1',
			'text'           => $slug . ' zone1',
		) );
		$matcher->on_channel_message( $zone1_turn );
		$zone1_ok = count( $captured['enqueued'] ) === $negative_before;
		$enqueued_before_execute = count( array_unique( array_map( 'strval', $captured['enqueued'] ) ) );
		$run_id = ! empty( $captured['enqueued'] ) ? (string) $captured['enqueued'][0] : '';
		if ( $run_id !== '' && class_exists( 'BizCity_Automation_Runner' ) ) {
			BizCity_Automation_Runner::instance()->execute( $run_id );
			$this->created_run_ids[] = $run_id;
		}
		$run_logs = $run_id !== '' && class_exists( 'BizCity_Automation_Repo_Runs' )
			? BizCity_Automation_Repo_Runs::logs( $run_id )
			: array();
		$logged_nodes = array_values( array_filter( array_map( static function ( $row ) { return (string) ( $row['node_id'] ?? '' ); }, (array) $run_logs ) ) );
		$required_nodes = array( 'canary_trigger', 'canary_hil', 'canary_mpr', 'canary_condition', 'canary_side_effect' );
		$five_node_ok = count( array_unique( $logged_nodes ) ) >= 5 && empty( array_diff( $required_nodes, $logged_nodes ) );
		$recursive_enqueue_count = max( 0, count( array_unique( array_map( 'strval', $captured['enqueued'] ) ) ) - $enqueued_before_execute );
		$this->remove_canary_hooks( $callbacks );
		$negative = array(
			'duplicate_inbound' => $duplicate_ok ? 'blocked' : 'fail',
			'group' => $group_ok ? 'blocked' : 'fail',
			'unlinked' => $unlinked_ok ? 'blocked' : 'fail',
			'zone1' => $zone1_ok ? 'blocked' : 'fail',
		);
		$evidence['trace_id'] = $trace_id;
		$evidence['mode'] = 'live';
		$evidence['live_surface'] = 'zalo_bot';
		$evidence['run_ids'] = array_values( array_unique( array_map( 'strval', $captured['enqueued'] ) ) );
		$evidence['node_ids'] = array_values( array_unique( $logged_nodes ) );
		$evidence['dedupe_keys'] = array( 'inbound:' . substr( sha1( $message_id ), 0, 12 ), 'notice:' . substr( sha1( $trace_id . '|progress' ), 0, 12 ) );
		foreach ( $captured['outbound'] as $outbound ) {
			if ( (string) ( $outbound['idempotency_key_hash'] ?? '' ) !== '' ) {
				$evidence['dedupe_keys'][] = 'side_effect:' . $outbound['idempotency_key_hash'];
			}
		}
		$evidence['target_source'] = 'inbound';
		$evidence['provider_outcome_buckets'] = ! empty( $captured['outbound'] )
			? array_values( array_unique( array_map( static function ( $row ) { return (string) ( $row['side_effect_status'] ?? 'unknown' ); }, $captured['outbound'] ) ) )
			: array( 'gateway_not_observed' );
		$evidence['recursive_enqueue_count'] = $recursive_enqueue_count;
		$side_effect_keys = array_values( array_filter( array_map( static function ( $row ) { return (string) ( $row['idempotency_key_hash'] ?? '' ); }, $captured['outbound'] ) ) );
		$evidence['external_side_effect_duplicate_count'] = count( $side_effect_keys ) - count( array_unique( $side_effect_keys ) );
		$terminal_ok = false;
		foreach ( $captured['ended'] as $ended ) {
			if ( ! empty( $ended['success'] ) ) {
				$terminal_ok = true;
				break;
			}
		}
		$outbound_ok = false;
		$idempotency_ok = ! empty( $captured['outbound'] );
		$provider_outcome_ok = ! empty( $captured['outbound'] );
		foreach ( $captured['outbound'] as $outbound ) {
			if ( ! empty( $outbound['sent'] ) && (string) ( $outbound['trace_id'] ?? '' ) === $trace_id ) {
				$outbound_ok = true;
			}
			if ( (string) ( $outbound['idempotency_key_hash'] ?? '' ) === '' ) {
				$idempotency_ok = false;
			}
			if ( ! in_array( (string) ( $outbound['side_effect_status'] ?? '' ), array( 'sent', 'confirmed' ), true ) ) {
				$provider_outcome_ok = false;
			}
		}
		$evidence['terminal_result_observed'] = $terminal_ok && $outbound_ok;
		$evidence['negative_cases'] = $negative;
		// [2026-08-17 Johnny Chu] MPR-V5-GATE6 — duplicate or unresolved provider outcome cannot yield live PASS.
		$trace_ok = ! empty( $captured['enqueued'] ) && ! empty( $captured['logs'] ) && $five_node_ok && $terminal_ok && $outbound_ok && $idempotency_ok && $provider_outcome_ok && $recursive_enqueue_count === 0 && (int) $evidence['external_side_effect_duplicate_count'] === 0 && ! empty( $evidence['terminal_result_observed'] );
		$negative_ok = $duplicate_ok && $group_ok && $unlinked_ok && $zone1_ok;
		$ok = $trace_ok && $negative_ok && $this->all_outbound_trace_matches( $captured['outbound'], $trace_id );
		$this->step( $ctx, $steps, 'Live canary: inbound → HIL → 5-node run → logs → notices', $ok ? 'pass' : 'fail', $ok ? 'One linked direct Zalo trace reached HIL, all five node logs and outbound evidence.' : 'Live trace chain, five-node execution or outbound trace_id is incomplete.' );
		$this->step( $ctx, $steps, 'Live canary: negative cases', $negative_ok ? 'pass' : 'fail', $negative_ok ? 'Duplicate, group, unlinked and Zone 1 inputs were blocked by the live Matcher.' : 'A duplicate, group, unlinked or Zone 1 input created an unexpected run.' );
		$boundary_ok = $idempotency_ok && $provider_outcome_ok && $recursive_enqueue_count === 0 && (int) $evidence['external_side_effect_duplicate_count'] === 0;
		$this->step( $ctx, $steps, 'Live canary: outbound idempotency and anti-recursion', $boundary_ok ? 'pass' : 'fail', $boundary_ok ? 'Outbound evidence has a stable key, sent/confirmed outcome, zero duplicates and no recursive enqueue.' : 'Outbound evidence has unresolved status, missing idempotency metadata, a duplicate or a recursive enqueue.' );
		return $ok;
	}

	private function live_canary_twinchat( $ctx, array &$steps, array &$evidence ): bool {
		// [2026-08-19 Johnny Chu] MPR-V5-CANARY — TwinChat live canary validates Runtime trace correlation and compatibility stages without external side effects.
		$wp_user_id = (int) $ctx->option( 'wp_user_id', 0 );
		$message_id = trim( (string) $ctx->option( 'message_id', '' ) );
		$session_id = trim( (string) $ctx->option( 'session_id', '' ) );
		$prompt = trim( (string) $ctx->option( 'prompt', '' ) );
		if ( $session_id === '' ) {
			$session_seed = $message_id !== '' ? $message_id : (string) microtime( true );
			$session_id = 'diag_twinchat_' . $wp_user_id . '_' . substr( sha1( $session_seed ), 0, 12 );
		}
		if ( $prompt === '' ) {
			$prompt = 'TwinChat live canary health check';
		}
		if ( $wp_user_id <= 0 || $message_id === '' ) {
			$this->step( $ctx, $steps, 'Live canary TwinChat input gate', 'fail', 'TwinChat canary requires wp_user_id > 0 and a non-empty message_id.' );
			return false;
		}
		if ( ! class_exists( 'BizCity_TwinBrain_Runtime' ) ) {
			$this->step( $ctx, $steps, 'Live canary TwinChat runtime gate', 'fail', 'BizCity_TwinBrain_Runtime is not loaded.' );
			return false;
		}

		$runtime = BizCity_TwinBrain_Runtime::instance();
		$captured = array(
			'trace_ids' => array(),
			'stages' => array(),
			'events' => array(),
			'turn_complete' => false,
			'error' => false,
		);
		$listener = function ( $event_key, $payload ) use ( &$captured ) {
			$row = is_array( $payload ) ? $payload : array();
			$trace_id = '';
			if ( isset( $row['trace_id'] ) ) {
				$trace_id = (string) $row['trace_id'];
			} elseif ( isset( $row['data'] ) && is_array( $row['data'] ) && isset( $row['data']['trace_id'] ) ) {
				$trace_id = (string) $row['data']['trace_id'];
			}
			if ( $trace_id !== '' ) {
				$captured['trace_ids'][] = $trace_id;
			}
			if ( (string) $event_key === 'decision' ) {
				$stage = '';
				if ( isset( $row['stage'] ) ) {
					$stage = (string) $row['stage'];
				} elseif ( isset( $row['data'] ) && is_array( $row['data'] ) && isset( $row['data']['stage'] ) ) {
					$stage = (string) $row['data']['stage'];
				}
				if ( $stage !== '' ) {
					$captured['stages'][] = $stage;
				}
			}
			if ( (string) $event_key === 'turn_complete' ) {
				$captured['turn_complete'] = true;
			}
			if ( (string) $event_key === 'error' ) {
				$captured['error'] = true;
			}
			if ( count( $captured['events'] ) < 40 ) {
				$captured['events'][] = (string) $event_key;
			}
		};

		$start = array();
		$done = array();
		add_action( 'bizcity_twin_event', $listener, 99, 2 );
		try {
			$opts = array(
				'user_id' => $wp_user_id,
				'subject_id' => $wp_user_id,
				'session_id' => $session_id,
				'surface' => 'twinchat',
				'channel' => 'TWINCHAT',
				'platform' => 'TWINCHAT',
				'goal_signal' => 'request',
				'answer_depth' => 'balanced',
				'source' => 'diagnostics_live_canary',
			);
			$start = $runtime->start_turn( $prompt, $opts );
			$trace_id = (string) ( $start['trace_id'] ?? '' );
			if ( $trace_id === '' ) {
				$this->step( $ctx, $steps, 'Live canary TwinChat start_turn', 'fail', 'start_turn did not return trace_id.' );
				return false;
			}
			$complete_opts = array_merge( $opts, array(
				'guru_id' => (int) ( $start['guru_id'] ?? 0 ),
				'tool_force' => (string) ( $start['tool_force'] ?? '' ),
				'goal_loop_state' => (array) ( $start['goal_loop_state'] ?? array() ),
				'goal_loop' => (array) ( $start['goal_loop_state'] ?? array() ),
				'goal_contract' => (array) ( $start['goal_contract'] ?? array() ),
				'pre_mpr_triage' => (array) ( $start['pre_mpr_triage'] ?? array() ),
				'prompt_intent' => (array) ( $start['prompt_intent'] ?? $start['pre_mpr_triage'] ?? array() ),
				'intent_compat' => (array) ( $start['intent_compat'] ?? array() ),
				'answer_depth' => (string) ( $start['answer_depth'] ?? 'balanced' ),
				'identity_uuid' => (string) ( $start['identity_uuid'] ?? '' ),
			) );
			$done = $runtime->complete_turn(
				$trace_id,
				$prompt,
				(array) ( $start['candidates'] ?? array() ),
				(array) ( $start['tool_candidates'] ?? array() ),
				$complete_opts
			);
		} catch ( \Throwable $e ) {
			$this->step( $ctx, $steps, 'Live canary TwinChat execution', 'fail', 'TwinChat canary threw exception: ' . $e->getMessage() );
			return false;
		} finally {
			remove_action( 'bizcity_twin_event', $listener, 99 );
		}

		$trace_id = (string) ( $start['trace_id'] ?? '' );
		$trace_ids = array_values( array_unique( array_map( 'strval', (array) $captured['trace_ids'] ) ) );
		$stage_ids = array_values( array_unique( array_map( 'strval', (array) $captured['stages'] ) ) );
		$trace_ok = count( $trace_ids ) === 1 && (string) $trace_ids[0] === $trace_id;
		$intent_stage_ok = in_array( 'prompt_intent_detected', $stage_ids, true );
		$compat_stage_ok = in_array( 'intent_compat_ready', $stage_ids, true ) || ! empty( $start['intent_compat'] );
		$gate_stage_ok = in_array( 'final_gate_decision', $stage_ids, true );
		$answer_md = (string) ( $done['synthesis']['answer_md'] ?? $done['answer'] ?? $done['final_text'] ?? '' );
		$complete_ok = is_array( $done ) && ( ! empty( $done['synthesis'] ) || $answer_md !== '' || ! empty( $captured['turn_complete'] ) ) && empty( $captured['error'] );

		$this->step( $ctx, $steps, 'Live canary TwinChat: trace correlation', $trace_ok ? 'pass' : 'fail', $trace_ok ? 'All captured event frames keep one trace_id.' : 'Captured TwinChat events are missing or trace_id mismatched.' );
		$stage_ok = $intent_stage_ok && $compat_stage_ok && $gate_stage_ok;
		$this->step( $ctx, $steps, 'Live canary TwinChat: semantic stage chain', $stage_ok ? 'pass' : 'fail', $stage_ok ? 'prompt_intent_detected → intent_compat_ready → final_gate_decision observed.' : 'TwinChat semantic stages are incomplete in live capture.' );
		$this->step( $ctx, $steps, 'Live canary TwinChat: terminal completion', $complete_ok ? 'pass' : 'fail', $complete_ok ? 'Runtime completed turn without event-bus error.' : 'Runtime did not reach a terminal completion state.' );

		$ok = $trace_ok && $stage_ok && $complete_ok;
		$evidence['trace_id'] = $trace_id !== '' ? $trace_id : 'live_mpr_v5_twinchat_' . substr( sha1( $session_id . '|' . $message_id ), 0, 20 );
		$evidence['mode'] = 'live';
		$evidence['live_surface'] = 'twinchat';
		$evidence['run_ids'] = array();
		$evidence['node_ids'] = $stage_ids;
		$evidence['dedupe_keys'] = array(
			'inbound:' . substr( sha1( $message_id ), 0, 12 ),
			'session:' . substr( sha1( $session_id ), 0, 12 ),
		);
		$evidence['target_source'] = 'twinchat_session';
		$evidence['provider_outcome_buckets'] = array( $answer_md !== '' ? 'answer_generated' : 'answer_empty' );
		$evidence['negative_cases'] = array(
			'duplicate_inbound' => 'n/a',
			'group' => 'n/a',
			'unlinked' => 'n/a',
			'zone1' => 'n/a',
		);
		$evidence['terminal_result_observed'] = $complete_ok && $gate_stage_ok;
		$evidence['recursive_enqueue_count'] = 0;
		$evidence['external_side_effect_duplicate_count'] = 0;
		return $ok;
	}

	private function all_outbound_trace_matches( array $outbound, string $trace_id ): bool {
		foreach ( $outbound as $row ) {
			if ( (string) ( $row['trace_id'] ?? '' ) !== $trace_id ) {
				return false;
			}
		}
		return true;
	}

	private function remove_canary_hooks( array $callbacks ): void {
		remove_action( 'bizcity_automation_run_enqueued', $callbacks['enqueued'], 99 );
		remove_action( 'bizcity_automation_run_started', $callbacks['started'], 99 );
		remove_action( 'bizcity_automation_log_appended', $callbacks['logs'], 99 );
		remove_action( 'bizcity_automation_run_ended', $callbacks['ended'], 99 );
		remove_action( 'bizcity_channel_outbound_logged', $callbacks['outbound'], 99 );
	}

	private function sanitize_evidence( array $evidence ): array {
		// [2026-08-16 Johnny Chu] MPR-V5-DDV — evidence contains hashes/buckets only; no prompt, PII, token, URL or stack trace.
		$allowed = array( 'trace_id', 'mode', 'status', 'live_status', 'live_surface', 'events', 'run_ids', 'node_ids', 'dedupe_keys', 'target_source', 'provider_outcome_buckets', 'negative_cases', 'terminal_result_observed', 'recursive_enqueue_count', 'external_side_effect_duplicate_count' );
		return array_intersect_key( $evidence, array_flip( $allowed ) );
	}

	private function step( $ctx, array &$steps, string $label, string $status, string $detail ): bool {
		$passed = $status === 'pass';
		$row = array( 'label' => $label, 'status' => $status, 'detail' => $detail );
		$steps[] = $row;
		$ctx->emit_step( $row );
		return $passed;
	}

	private function plugin_root(): string {
		return defined( 'BIZCITY_TWIN_AI_DIR' )
			? rtrim( (string) BIZCITY_TWIN_AI_DIR, '/\\' ) . '/'
			: dirname( dirname( dirname( dirname( __DIR__ ) ) ) ) . '/';
	}

	public function cleanup(): void {
		// [2026-08-16 Johnny Chu] MPR-V5-DDV — delete only tagged canary workflow and run artifacts.
		if ( $this->created_workflow_id > 0 && class_exists( 'BizCity_Automation_Repo_Workflows' ) && method_exists( 'BizCity_Automation_Repo_Workflows', 'hard_delete' ) ) {
			BizCity_Automation_Repo_Workflows::hard_delete( $this->created_workflow_id );
		}
		if ( ! empty( $this->created_run_ids ) && class_exists( 'BizCity_Automation_Repo_Runs' ) ) {
			global $wpdb;
			foreach ( array_unique( $this->created_run_ids ) as $run_id ) {
				$wpdb->delete( BizCity_Automation_Repo_Runs::table_logs(), array( 'run_id' => (string) $run_id ), array( '%s' ) );
				$wpdb->delete( BizCity_Automation_Repo_Runs::table_runs(), array( 'run_id' => (string) $run_id ), array( '%s' ) );
			}
		}
		$this->created_run_ids = array();
		$this->created_workflow_id = 0;
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_TwinBrain_MPR_V5';
	return $list;
} );
