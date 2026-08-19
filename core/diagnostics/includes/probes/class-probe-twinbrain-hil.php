<?php
/**
 * BizCity Diagnostics — twinbrain.hil probe.
 *
 * Synthetic-only HIL contract probe. It never calls the LLM gateway, writes
 * workflow/event state, or mutates a user record.
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

if ( class_exists( 'BizCity_Probe_TwinBrain_HIL', false ) ) {
	return;
}

final class BizCity_Probe_TwinBrain_HIL implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'twinbrain.hil'; }
	public function label(): string { return 'TwinBrain HIL Spec/Compiler/Runtime/Product Match'; }
	public function description(): string {
		return 'Checks HIL Spec normalization/validation, deterministic Instance runtime, Woo product suggestion wiring, and the gpt-4o-mini candidate matcher contract without provider calls, LLM credits, or DB writes.';
	}
	public function severity(): string { return 'warning'; }
	public function order(): int { return 78; }
	public function icon(): string { return 'shield-check'; }
	public function estimate_ms(): int { return 40; }

	public function precondition() {
		// [2026-08-16 Johnny Chu] MPR-V5-HIL-DDV — require validator, compiler, and the Instance runtime trio before synthetic runtime assertions.
		foreach ( array( 'BizCity_Twin_Event_Taxonomy', 'BizCity_TwinBrain_HIL_Spec', 'BizCity_TwinBrain_HIL_Compiler', 'BizCity_TwinBrain_HIL_State', 'BizCity_TwinBrain_HIL_Extractor', 'BizCity_TwinBrain_HIL_Runtime', 'BizCity_TwinBrain_HIL_Repository', 'BizCity_TwinBrain_Media_Candidate_Resolver' ) as $class ) {
			if ( ! class_exists( $class ) ) {
				return new WP_Error( 'class_missing', $class . ' chưa load.' );
			}
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-08-16 Johnny Chu] MPR-V5-HIL-DDV — verify Disk/Loader/Runtime with RAM-only fixtures and no LLM credit.
		$steps = array();
		$pass  = true;
		$root  = defined( 'BIZCITY_TWIN_AI_DIR' )
			? rtrim( (string) BIZCITY_TWIN_AI_DIR, '/\\' ) . '/'
			: dirname( dirname( dirname( dirname( __DIR__ ) ) ) ) . '/';
		$spec_file = $root . 'core/twinbrain/includes/class-twinbrain-hil-spec.php';
		$compiler_file = $root . 'core/twinbrain/includes/class-twinbrain-hil-compiler.php';
		$state_file = $root . 'core/twinbrain/includes/class-twinbrain-hil-state.php';
		$runtime_file = $root . 'core/twinbrain/includes/class-twinbrain-hil-runtime.php';
		$repository_file = $root . 'core/twinbrain/includes/class-twinbrain-hil-repository.php';
		$media_resolver_file = $root . 'core/twinbrain/includes/class-twinbrain-media-candidate-resolver.php';
		$product_provider_file = $root . 'core/twinbrain/includes/class-twinbrain-product-provider.php';
		$llm_client_file = $root . 'core/bizcity-llm/includes/class-llm-client.php';
		$matcher_file = $root . 'core/automation/includes/class-automation-trigger-matcher.php';
		$runner_file = $root . 'core/automation/includes/class-automation-runner.php';
		$projector_file = $root . 'core/twinbrain/includes/class-twinbrain-progress-notice-projector.php';
		$core_changelog_file = $root . 'core/diagnostics/changelog/core.twin-core.json';
		// [2026-08-17 Johnny Chu] MPR-V5-HIL-DDV — compile belongs to TwinBrain REST; read-only trace belongs to Automation REST.
		$compile_rest_file = $root . 'core/twinbrain/includes/class-twinbrain-rest.php';
		$trace_rest_file = $root . 'core/automation/includes/class-automation-rest.php';

		$spec_source = is_readable( $spec_file ) ? (string) file_get_contents( $spec_file ) : '';
		$compiler_source = is_readable( $compiler_file ) ? (string) file_get_contents( $compiler_file ) : '';
		$state_source = is_readable( $state_file ) ? (string) file_get_contents( $state_file ) : '';
		$runtime_source = is_readable( $runtime_file ) ? (string) file_get_contents( $runtime_file ) : '';
		$repository_source = is_readable( $repository_file ) ? (string) file_get_contents( $repository_file ) : '';
		$media_resolver_source = is_readable( $media_resolver_file ) ? (string) file_get_contents( $media_resolver_file ) : '';
		$product_provider_source = is_readable( $product_provider_file ) ? (string) file_get_contents( $product_provider_file ) : '';
		$llm_client_source = is_readable( $llm_client_file ) ? (string) file_get_contents( $llm_client_file ) : '';
		$matcher_source = is_readable( $matcher_file ) ? (string) file_get_contents( $matcher_file ) : '';
		$runner_source = is_readable( $runner_file ) ? (string) file_get_contents( $runner_file ) : '';
		$projector_source = is_readable( $projector_file ) ? (string) file_get_contents( $projector_file ) : '';
		$core_changelog = is_readable( $core_changelog_file ) ? json_decode( (string) file_get_contents( $core_changelog_file ), true ) : null;
		$compile_rest_source = is_readable( $compile_rest_file ) ? (string) file_get_contents( $compile_rest_file ) : '';
		$trace_rest_source = is_readable( $trace_rest_file ) ? (string) file_get_contents( $trace_rest_file ) : '';
		$disk_ok = $spec_source !== ''
			&& $compiler_source !== ''
			&& $state_source !== ''
			&& $runtime_source !== ''
			&& $repository_source !== ''
			&& $media_resolver_source !== ''
			&& $matcher_source !== ''
			&& $runner_source !== ''
			&& $projector_source !== ''
			&& strpos( $spec_source, 'class BizCity_TwinBrain_HIL_Spec' ) !== false
			&& strpos( $compiler_source, 'class BizCity_TwinBrain_HIL_Compiler' ) !== false
			&& strpos( $state_source, 'class BizCity_TwinBrain_HIL_State' ) !== false
			&& strpos( $runtime_source, 'class BizCity_TwinBrain_HIL_Runtime' ) !== false
			&& strpos( $repository_source, 'class BizCity_TwinBrain_HIL_Repository' ) !== false
			&& strpos( $repository_source, 'protect_state' ) !== false
			&& strpos( $repository_source, 'restore_state' ) !== false
			&& strpos( $matcher_source, 'prepare_hil_payload' ) !== false
			&& strpos( $runner_source, 'hil_not_ready' ) !== false
			&& strpos( $projector_source, 'on_hil_milestone' ) !== false
			&& strpos( $projector_source, 'on_hil_step' ) !== false
			&& strpos( $compile_rest_source, "'/hil/compile'" ) !== false;
		$schema_ok = true;
		foreach ( array( 'twin_hil_opened.json', 'twin_hil_progressed.json', 'twin_hil_closed.json' ) as $schema_name ) {
			$schema_path = $root . 'core/twin-core/event-stream/schemas/events/' . $schema_name;
			if ( ! is_readable( $schema_path ) ) {
				$schema_ok = false;
				break;
			}
		}
		$trace_disk_ok = strpos( $repository_source, 'public static function history' ) !== false
			&& strpos( $trace_rest_source, "'/hil-trace'" ) !== false
			&& strpos( $matcher_source, '_hil_identity_uuid' ) !== false
			&& strpos( $matcher_source, '_hil_session_id' ) !== false;
		$pass = $this->step( $ctx, $steps, 'Disk: HIL Spec/compiler/runtime integration', $disk_ok, $disk_ok ? 'HIL Spec/compiler/runtime/repository/Matcher/Runner/Projector markers found.' : 'One or more active HIL integration files or markers are missing.' ) && $pass;
		$media_notice_optional = strpos( $media_resolver_source, 'class BizCity_TwinBrain_Media_Candidate_Resolver' ) !== false
			&& strpos( $matcher_source, '_no_automation_reentry' ) !== false;
		if ( ! $media_notice_optional ) {
			$this->skip( $ctx, $steps, 'Loader: V5 media/anti-recursion optional extensions', 'SKIP: deployed artifact predates the optional media/anti-recursion extension; core HIL contract remains independently testable.' );
		} else {
			$pass = $this->step( $ctx, $steps, 'Loader: V5 media/anti-recursion optional extensions', true, 'Media candidate and anti-recursion extensions are loaded.' ) && $pass;
		}
		$pass = $this->step( $ctx, $steps, 'Disk: HIL step trace integration', $trace_disk_ok, $trace_disk_ok ? 'History projection, REST trace route, and scoped matcher hints are present.' : 'HIL step trace integration markers are missing.' ) && $pass;
		$product_disk_ok = strpos( $product_provider_source, 'class BizCity_TwinBrain_Product_Provider' ) !== false
			&& strpos( $product_provider_source, 'function suggestions' ) !== false
			&& strpos( $matcher_source, 'match_hil_product_reply' ) !== false
			&& strpos( $matcher_source, 'decorate_hil_product_question' ) !== false
			&& strpos( $matcher_source, "'automation_hil_product_match'" ) !== false
			&& strpos( $matcher_source, "'gpt-4o-mini'" ) !== false
			&& strpos( $llm_client_source, 'public function chat' ) !== false;
		$pass = $this->step( $ctx, $steps, 'Disk: HIL Woo product suggestion/matcher contract', $product_disk_ok, $product_disk_ok ? 'Woo suggestions, bounded matcher, small-model purpose, and LLM client markers are present.' : 'Product suggestion or small-model matcher markers are missing.' ) && $pass;
		$pass = $this->step( $ctx, $steps, 'Disk: HIL Event schemas', $schema_ok, $schema_ok ? 'All twin_hil_* JSON schemas are present.' : 'A twin_hil_* JSON schema is missing.' ) && $pass;
		$changelog_events = isset( $core_changelog['event_types']['added']['2.4.0'] ) && is_array( $core_changelog['event_types']['added']['2.4.0'] )
			? array_column( $core_changelog['event_types']['added']['2.4.0'], 'type' )
			: array();
		$changelog_ok = is_array( $core_changelog )
			&& (string) ( $core_changelog['module_id'] ?? '' ) === 'core.twin-core'
			&& (int) ( $core_changelog['event_types']['taxonomy_version'] ?? 0 ) >= 11
			&& count( array_intersect( array( 'twin_hil_opened', 'twin_hil_progressed', 'twin_hil_closed' ), $changelog_events ) ) === 3;
		$pass = $this->step( $ctx, $steps, 'Disk: canonical Event Taxonomy changelog', $changelog_ok, $changelog_ok ? 'core.twin-core changelog catalogs taxonomy v11 HIL events.' : 'Canonical core.twin-core changelog is stale or missing HIL events.' ) && $pass;

		$loader_ok = method_exists( 'BizCity_TwinBrain_HIL_Spec', 'normalize' )
			&& method_exists( 'BizCity_TwinBrain_HIL_Spec', 'validate' )
			&& method_exists( 'BizCity_TwinBrain_HIL_Compiler', 'compile' )
			&& method_exists( 'BizCity_TwinBrain_HIL_Runtime', 'bootstrap' )
			&& method_exists( 'BizCity_TwinBrain_HIL_Runtime', 'step' )
			&& method_exists( 'BizCity_TwinBrain_HIL_Repository', 'open' )
			&& method_exists( 'BizCity_TwinBrain_HIL_Repository', 'progress' )
			&& method_exists( 'BizCity_TwinBrain_HIL_Repository', 'close' )
			&& method_exists( 'BizCity_TwinBrain_HIL_Repository', 'history' )
			&& defined( 'BizCity_Twin_Event_Taxonomy::TWIN_HIL_OPENED' )
			&& defined( 'BizCity_Twin_Event_Taxonomy::TWIN_HIL_PROGRESSED' )
			&& defined( 'BizCity_Twin_Event_Taxonomy::TWIN_HIL_CLOSED' );
		$pass = $this->step( $ctx, $steps, 'Loader: HIL methods and taxonomy loaded', $loader_ok, $loader_ok ? 'Spec/compiler/runtime/repository/history methods and twin_hil_* taxonomy constants are loaded.' : 'HIL runtime/repository/taxonomy method or constant missing.' ) && $pass;

		$integration_loader_ok = class_exists( 'BizCity_Automation_Trigger_Matcher' )
			&& class_exists( 'BizCity_Automation_Runner' )
			&& method_exists( 'BizCity_TwinBrain_Progress_Notice_Projector', 'on_hil_milestone' )
			&& method_exists( 'BizCity_TwinBrain_Progress_Notice_Projector', 'on_hil_step' )
			&& class_exists( 'BizCity_Automation_REST' )
			&& method_exists( 'BizCity_Automation_REST', 'hil_trace' );
		$pass = $this->step( $ctx, $steps, 'Loader: Automation HIL integration loaded', $integration_loader_ok, $integration_loader_ok ? 'Matcher, Runner guard and HIL notice projector are loaded.' : 'Automation HIL integration class missing.' ) && $pass;
		$product_loader_ok = class_exists( 'BizCity_TwinBrain_Product_Provider' )
			&& method_exists( 'BizCity_TwinBrain_Product_Provider', 'suggestions' )
			&& class_exists( 'BizCity_LLM_Client' )
			&& method_exists( 'BizCity_LLM_Client', 'chat' )
			&& method_exists( 'BizCity_Automation_Trigger_Matcher', 'prepare_hil_for_external_enqueue' );
		$pass = $this->step( $ctx, $steps, 'Loader: HIL product matcher dependencies', $product_loader_ok, $product_loader_ok ? 'Product provider, LLM client, and shared matcher boundary are loaded.' : 'Product provider/LLM client/shared matcher boundary is unavailable.' ) && $pass;

		$required = BizCity_Twin_Event_Taxonomy::required_fields();
		$taxonomy_ok = (int) BizCity_Twin_Event_Taxonomy::TAXONOMY_VERSION >= 11
			&& isset( $required['twin_hil_opened'], $required['twin_hil_progressed'], $required['twin_hil_closed'] )
			&& in_array( 'state', $required['twin_hil_opened'], true )
			&& in_array( 'closure_reason', $required['twin_hil_closed'], true );
		$pass = $this->step( $ctx, $steps, 'Runtime: HIL taxonomy required fields', $taxonomy_ok, $taxonomy_ok ? 'taxonomy v11 exposes required fields for all twin_hil_* events.' : 'HIL taxonomy version or required fields drifted.' ) && $pass;

		$valid = BizCity_TwinBrain_HIL_Spec::validate( $this->valid_order_spec() );
		$valid_ok = ! empty( $valid['valid'] ) && count( $valid['spec']['slots'] ) === 5;
		$pass = $this->step( $ctx, $steps, 'Runtime: valid order spec', $valid_ok, $valid_ok ? 'Five bounded order slots pass validation.' : 'Valid order fixture did not pass as expected.' ) && $pass;
		$product_slot_spec = BizCity_TwinBrain_HIL_Spec::validate( array(
			'spec_version' => 'twin_hil.v1',
			'spec_id'      => 'probe_product_match',
			'trigger_id'   => 'trigger.zalo_inbound',
			'intent_id'    => 'commerce.order_execute',
			'slots'        => array( array( 'id' => 'product_name', 'label' => 'Tên sản phẩm', 'type' => 'entity', 'required' => true, 'ask' => 'Chọn sản phẩm.' ) ),
			'completion'   => array( 'final_confirmation' => true, 'side_effect_gate' => 'block_until_ready' ),
		) );
		$product_runtime_ok = ! empty( $product_slot_spec['valid'] )
			&& (string) ( $product_slot_spec['spec']['slots'][0]['type'] ?? '' ) === 'entity'
			&& strpos( $matcher_source, 'confidence >= 0.75' ) !== false
			&& strpos( $matcher_source, 'candidate_index' ) !== false;
		$pass = $this->step( $ctx, $steps, 'Runtime: product match confidence gate contract', $product_runtime_ok, $product_runtime_ok ? 'Product slot is validated and matcher requires bounded candidate_index with confidence >= 0.75.' : 'Product slot or confidence gate contract is missing.' ) && $pass;

		$duplicate = BizCity_TwinBrain_HIL_Spec::validate( $this->valid_order_spec( array( 'duplicate' => true ) ) );
		$duplicate_ok = empty( $duplicate['valid'] ) && in_array( 'slots.1.duplicate_id', (array) $duplicate['errors'], true );
		$pass = $this->step( $ctx, $steps, 'Runtime: duplicate slot rejected', $duplicate_ok, $duplicate_ok ? 'Duplicate slot ID is rejected.' : 'Duplicate slot fixture was accepted.' ) && $pass;

		$unsupported = BizCity_TwinBrain_HIL_Spec::validate( $this->valid_order_spec( array( 'unsupported_type' => true ) ) );
		$unsupported_ok = empty( $unsupported['valid'] ) && in_array( 'slots.0.type_unsupported', (array) $unsupported['errors'], true );
		$pass = $this->step( $ctx, $steps, 'Runtime: unsupported type rejected', $unsupported_ok, $unsupported_ok ? 'Unsupported slot type is rejected.' : 'Unsupported slot type fixture was accepted.' ) && $pass;

		$missing_ask = BizCity_TwinBrain_HIL_Spec::validate( $this->valid_order_spec( array( 'missing_ask' => true ) ) );
		$missing_ask_ok = empty( $missing_ask['valid'] ) && in_array( 'slots.0.ask_missing', (array) $missing_ask['errors'], true );
		$pass = $this->step( $ctx, $steps, 'Runtime: required question enforced', $missing_ask_ok, $missing_ask_ok ? 'Required slot without ask is rejected.' : 'Required slot without ask was accepted.' ) && $pass;

		$choice_missing = BizCity_TwinBrain_HIL_Spec::validate( $this->valid_order_spec( array( 'choice_missing' => true ) ) );
		$choice_missing_ok = empty( $choice_missing['valid'] ) && in_array( 'slots.2.choices_missing', (array) $choice_missing['errors'], true );
		$pass = $this->step( $ctx, $steps, 'Runtime: choice map required', $choice_missing_ok, $choice_missing_ok ? 'Choice slot without choices is rejected.' : 'Choice slot without choices was accepted.' ) && $pass;

		$side_effect = BizCity_TwinBrain_HIL_Spec::validate( $this->valid_order_spec( array( 'final_confirmation' => false ) ) );
		$side_effect_ok = empty( $side_effect['valid'] ) && in_array( 'side_effect_confirmation_required', (array) $side_effect['errors'], true );
		$pass = $this->step( $ctx, $steps, 'Runtime: side-effect confirmation enforced', $side_effect_ok, $side_effect_ok ? 'Side-effect spec without final confirmation is rejected.' : 'Side-effect confirmation fixture was accepted.' ) && $pass;

		$terminal_guard_ok = ! BizCity_TwinBrain_HIL_State::can_transition( 'ready', 'collecting' )
			&& ! BizCity_TwinBrain_HIL_State::can_transition( 'cancelled', 'ready' );
		$pass = $this->step( $ctx, $steps, 'Runtime: terminal transitions locked', $terminal_guard_ok, $terminal_guard_ok ? 'Ready/cancelled instances cannot reopen.' : 'A terminal HIL state can transition again.' ) && $pass;

		$incomplete_ready = BizCity_TwinBrain_HIL_State::normalize( array( 'status' => 'ready', 'slot_values' => array( 'customer_name' => 'A' ), 'confirmed' => true ) );
		$incomplete_gate_ok = ! BizCity_TwinBrain_HIL_State::is_side_effect_ready( $incomplete_ready, $this->valid_order_spec() );
		$pass = $this->step( $ctx, $steps, 'Runtime: ready state still requires required slots', $incomplete_gate_ok, $incomplete_gate_ok ? 'A forged/incomplete ready snapshot cannot pass the side-effect gate.' : 'Incomplete ready snapshot passed the side-effect gate.' ) && $pass;

		$bounded_value = BizCity_TwinBrain_HIL_Extractor::extract( 'integer', '0', array( 'validation' => array( 'min' => 1, 'max' => 999 ) ) );
		$bounded_ok = null === $bounded_value;
		$pass = $this->step( $ctx, $steps, 'Runtime: compiled numeric bounds enforced', $bounded_ok, $bounded_ok ? 'Quantity below validation.min is rejected.' : 'Extractor accepted a value below validation.min.' ) && $pass;

		$media_candidates = BizCity_TwinBrain_Media_Candidate_Resolver::resolve( array(
			array( 'id' => 'media_1', 'kind' => 'image', 'message_id' => 'msg_1' ),
			array( 'id' => 'media_group', 'kind' => 'image', 'message_id' => 'msg_1', 'chat_kind' => 'group' ),
		), array( 'message_id' => 'msg_1', 'chat_id' => 'zalobot_probe', 'chat_kind' => 'private' ) );
		$media_selected = BizCity_TwinBrain_Media_Candidate_Resolver::select( $media_candidates, 'media_1', true );
		$media_ok = (string) ( $media_selected[0]['status'] ?? '' ) === 'available'
			&& ! empty( $media_selected[0]['selected'] )
			&& ! empty( $media_selected[0]['confirmed'] )
			&& (string) ( $media_candidates[1]['status'] ?? '' ) === 'rejected'
			&& empty( $media_selected[1]['selected'] );
		$pass = $this->step( $ctx, $steps, 'Runtime: media candidate ownership and explicit confirmation', $media_ok, $media_ok ? 'Owned image can be explicitly confirmed; group candidate is rejected.' : 'Media resolver accepted an unsafe candidate or selected without confirmation.' ) && $pass;

		$pass = $this->run_instance_fixtures( $ctx, $steps ) && $pass;

		return array(
			'status'   => $pass ? 'pass' : 'fail',
			'summary'  => $pass ? 'TwinBrain HIL Spec/compiler/runtime contract PASS.' : 'TwinBrain HIL Spec/compiler/runtime contract failed.',
			'error'    => $pass ? '' : 'twinbrain_hil_contract_failed',
			'fix_hint' => $pass ? '' : 'Check HIL Spec normalization, compiler loading, Instance runtime step logic, and save-time validation contract.',
			'steps'    => $steps,
		);
	}

	private function run_instance_fixtures( $ctx, array &$steps ): bool {
		// [2026-08-16 Johnny Chu] MPR-V5-HIL-DDV — one-turn coordinator fixtures; entirely in RAM, no Repository/Event Bus write.
		$pass = true;
		$validated = BizCity_TwinBrain_HIL_Spec::validate( $this->valid_order_spec() );
		$spec = $validated['spec'];

		$state = BizCity_TwinBrain_HIL_Runtime::bootstrap( $spec, array( 'identity_uuid' => 'probe-identity', 'session_id' => 'probe-session' ) );
		$bootstrap_ok = $state['status'] === 'collecting' && $state['pending_slot_id'] === 'customer_name';
		$pass = $this->step( $ctx, $steps, 'Runtime: bootstrap asks first required slot', $bootstrap_ok, $bootstrap_ok ? 'Instance opens on customer_name.' : 'Bootstrap did not target the first required slot.' ) && $pass;

		$invalid = BizCity_TwinBrain_HIL_Runtime::step( $spec, $state, '' );
		$invalid_ok = $invalid['action'] === 'reask' && $invalid['state']['pending_slot_id'] === 'customer_name';
		$pass = $this->step( $ctx, $steps, 'Runtime: empty reply reasks same slot', $invalid_ok, $invalid_ok ? 'Empty reply keeps the pending slot unchanged.' : 'Empty reply advanced past an unfilled required slot.' ) && $pass;

		$state = $invalid['state'];
		$first_filled = BizCity_TwinBrain_HIL_Runtime::step( $spec, $state, 'Nguyễn Văn A' );
		$first_filled_ok = (string) ( $first_filled['slot_filled'] ?? '' ) === 'customer_name'
			&& (string) ( $first_filled['state']['slot_values']['customer_name'] ?? '' ) === 'Nguyễn Văn A';
		$pass = $this->step( $ctx, $steps, 'Runtime: accepted reply exposes slot id only', $first_filled_ok, $first_filled_ok ? 'Accepted slot is identified without changing the notice payload to include a secret value.' : 'Accepted slot marker or state transition is missing.' ) && $pass;
		$state = $first_filled['state'];
		$state = BizCity_TwinBrain_HIL_Runtime::step( $spec, $state, '0912345678' )['state'];
		$state = BizCity_TwinBrain_HIL_Runtime::step( $spec, $state, 'Nâng cao' )['state'];
		$state = BizCity_TwinBrain_HIL_Runtime::step( $spec, $state, '12 Nguyễn Trãi' )['state'];
		$filled = BizCity_TwinBrain_HIL_Runtime::step( $spec, $state, '3' );
		$confirm_ok = $filled['action'] === 'confirm'
			&& $filled['state']['status'] === 'confirming'
			&& (string) ( $filled['slot_filled'] ?? '' ) === 'quantity';
		$pass = $this->step( $ctx, $steps, 'Runtime: five filled slots move to confirmation', $confirm_ok, $confirm_ok ? 'All required slots filled; instance now confirming.' : 'Instance did not reach the confirmation stage after five valid replies.' ) && $pass;

		$no_reply = BizCity_TwinBrain_HIL_Runtime::step( $spec, $filled['state'], 'không đúng' );
		$reopen_ok = $no_reply['action'] === 'ask' && $no_reply['state']['status'] === 'collecting';
		$pass = $this->step( $ctx, $steps, 'Runtime: confirmation no reopens collection', $reopen_ok, $reopen_ok ? 'Explicit no reopens slot collection instead of guessing.' : 'Confirmation no-path did not reopen collection.' ) && $pass;

		$yes_reply = BizCity_TwinBrain_HIL_Runtime::step( $spec, $filled['state'], 'đồng ý' );
		$ready_ok = $yes_reply['action'] === 'ready' && $yes_reply['hil_ready'] === true;
		$pass = $this->step( $ctx, $steps, 'Runtime: confirmation yes is side-effect ready', $ready_ok, $ready_ok ? 'Confirmed instance reports hil_ready=true.' : 'Confirmed instance did not report side-effect ready.' ) && $pass;

		$tight_spec = $spec;
		$tight_spec['limits']['max_turns'] = 1;
		$tight_spec['limits']['on_timeout'] = 'expire';
		$tight_state = BizCity_TwinBrain_HIL_Runtime::bootstrap( $tight_spec, array( 'identity_uuid' => 'probe-identity', 'session_id' => 'probe-session' ) );
		$tight_state = BizCity_TwinBrain_HIL_Runtime::step( $tight_spec, $tight_state, 'Nguyễn Văn A' )['state'];
		$expired = BizCity_TwinBrain_HIL_Runtime::step( $tight_spec, $tight_state, '0912345678' );
		$expired_ok = $expired['action'] === 'expired' && $expired['state']['status'] === 'expired';
		$pass = $this->step( $ctx, $steps, 'Runtime: turn budget enforced (on_timeout=expire)', $expired_ok, $expired_ok ? 'Exceeding max_turns with on_timeout=expire closes the instance.' : 'Turn budget was not enforced as expired.' ) && $pass;

		$fail_spec = $tight_spec;
		$fail_spec['limits']['on_timeout'] = 'fail';
		$fail_state = BizCity_TwinBrain_HIL_Runtime::bootstrap( $fail_spec, array( 'identity_uuid' => 'probe-identity', 'session_id' => 'probe-session' ) );
		$fail_state = BizCity_TwinBrain_HIL_Runtime::step( $fail_spec, $fail_state, 'Nguyễn Văn A' )['state'];
		$failed = BizCity_TwinBrain_HIL_Runtime::step( $fail_spec, $fail_state, '0912345678' );
		$failed_ok = $failed['action'] === 'failed' && $failed['state']['status'] === 'failed';
		$pass = $this->step( $ctx, $steps, 'Runtime: turn budget enforced (on_timeout=fail)', $failed_ok, $failed_ok ? 'Exceeding max_turns with on_timeout=fail closes the instance as failed.' : 'Turn budget was not represented as failed.' ) && $pass;

		$gate_ok = ! BizCity_TwinBrain_HIL_State::is_side_effect_ready( $tight_state, $tight_spec )
			&& BizCity_TwinBrain_HIL_State::is_side_effect_ready( $yes_reply['state'], $spec );
		$pass = $this->step( $ctx, $steps, 'Runtime: side-effect gate matches instance status', $gate_ok, $gate_ok ? 'Gate is false before ready+confirmed and true after.' : 'Side-effect gate did not match expected instance status.' ) && $pass;

		$media_spec = array(
			'spec_version' => BizCity_TwinBrain_HIL_Spec::VERSION,
			'spec_id' => 'media_probe',
			'trigger_id' => 'media_probe',
			'intent_id' => 'media.select',
			'slots' => array( array( 'id' => 'source_image', 'label' => 'Ảnh nguồn', 'type' => 'image', 'required' => true, 'ask' => 'Chọn ảnh nguồn bằng số thứ tự.' ) ),
			'completion' => array( 'final_confirmation' => true, 'side_effect_gate' => 'block_until_ready' ),
			'limits' => array( 'max_turns' => 4, 'ttl_seconds' => 600, 'on_timeout' => 'pause' ),
		);
		$media_valid = BizCity_TwinBrain_HIL_Spec::validate( $media_spec );
		$media_state = BizCity_TwinBrain_HIL_Runtime::bootstrap( $media_valid['spec'], array( 'identity_uuid' => 'probe-media', 'session_id' => 'probe-media-session' ) );
		$media_candidates = BizCity_TwinBrain_Media_Candidate_Resolver::resolve( array( array( 'id' => 'media_probe_1', 'kind' => 'image', 'message_id' => 'media-msg' ) ), array( 'identity_uuid' => 'probe-media', 'session_id' => 'probe-media-session', 'message_id' => 'media-msg', 'chat_id' => 'zalobot_probe' ) );
		$media_selected = BizCity_TwinBrain_HIL_Runtime::step( $media_valid['spec'], $media_state, '1', $media_candidates );
		$media_confirm = BizCity_TwinBrain_HIL_Runtime::step( $media_valid['spec'], $media_selected['state'], 'đồng ý' );
		$media_ok = ! empty( $media_valid['valid'] )
			&& (string) ( $media_selected['slot_filled'] ?? '' ) === 'source_image'
			&& (string) ( $media_selected['state']['media_candidate_id'] ?? '' ) === 'media_probe_1'
			&& empty( $media_selected['hil_ready'] )
			&& ! empty( $media_confirm['hil_ready'] )
			&& ! empty( $media_confirm['state']['media_candidate_confirmed'] );
		$pass = $this->step( $ctx, $steps, 'Runtime: media candidate requires explicit confirmation', $media_ok, $media_ok ? 'Scoped image candidate is selected first and becomes ready only after confirmation.' : 'Media candidate reached ready without explicit confirmation or lost its scoped id.' ) && $pass;

		$media_choose_other = BizCity_TwinBrain_HIL_Runtime::step( $media_valid['spec'], $media_state, 'chọn ảnh khác', $media_candidates );
		$media_choose_other_ok = (string) ( $media_choose_other['action'] ?? '' ) === 'ask'
			&& (string) ( $media_choose_other['slot_filled'] ?? '' ) === ''
			&& (string) ( $media_choose_other['state']['pending_slot_id'] ?? '' ) === 'source_image';
		$pass = $this->step( $ctx, $steps, 'Runtime: media select-other keeps pending slot', $media_choose_other_ok, $media_choose_other_ok ? 'Explicit "chọn ảnh khác" keeps the same media slot pending and waits for a new upload.' : 'Select-other intent did not keep the media slot pending.' ) && $pass;

		$media_no_candidate = BizCity_TwinBrain_HIL_Runtime::step( $media_valid['spec'], $media_state, '' );
		$media_no_candidate_ok = (string) ( $media_no_candidate['action'] ?? '' ) === 'reask'
			&& strpos( (string) ( $media_no_candidate['question'] ?? '' ), 'ảnh/tệp' ) !== false;
		$pass = $this->step( $ctx, $steps, 'Runtime: media missing candidate asks for upload', $media_no_candidate_ok, $media_no_candidate_ok ? 'When no candidate exists, the runtime asks the user to upload/send a file before continuing.' : 'No-candidate media prompt did not ask for a new upload.' ) && $pass;

		return $pass;
	}

	private function valid_order_spec( array $flags = array() ): array {
		// [2026-08-16 Johnny Chu] MPR-V5-HIL-DDV — build a deterministic five-slot order fixture entirely in memory.
		$slots = array(
			array( 'id' => 'customer_name', 'label' => 'Tên khách hàng', 'type' => 'text', 'required' => true, 'ask' => 'Cho biết tên của bạn.' ),
			array( 'id' => 'phone', 'label' => 'Số điện thoại', 'type' => 'phone', 'required' => true, 'ask' => 'Cho xin số điện thoại.' ),
			array( 'id' => 'product', 'label' => 'Sản phẩm', 'type' => 'choice', 'required' => true, 'ask' => 'Bạn chọn sản phẩm nào?', 'choices' => array( 'basic' => 'Cơ bản', 'pro' => 'Nâng cao' ) ),
			array( 'id' => 'address', 'label' => 'Địa chỉ', 'type' => 'address', 'required' => true, 'ask' => 'Địa chỉ nhận hàng ở đâu?' ),
			array( 'id' => 'quantity', 'label' => 'Số lượng', 'type' => 'integer', 'required' => true, 'ask' => 'Bạn cần bao nhiêu sản phẩm?' ),
		);
		if ( ! empty( $flags['duplicate'] ) ) {
			$slots[1]['id'] = $slots[0]['id'];
		}
		if ( ! empty( $flags['unsupported_type'] ) ) {
			$slots[0]['type'] = 'secret';
		}
		if ( ! empty( $flags['missing_ask'] ) ) {
			$slots[0]['ask'] = '';
		}
		if ( ! empty( $flags['choice_missing'] ) ) {
			$slots[2]['choices'] = array();
		}
		return array(
			'spec_version' => 'twin_hil.v1',
			'spec_id'      => 'probe_order',
			'trigger_id'   => 'probe_order_trigger',
			'intent_id'    => 'commerce.order_create',
			'purpose'      => 'Probe order collection',
			'slots'        => $slots,
			'completion'   => array( 'final_confirmation' => array_key_exists( 'final_confirmation', $flags ) ? (bool) $flags['final_confirmation'] : true, 'side_effect_gate' => 'block_until_ready' ),
			'limits'       => array( 'max_turns' => 8, 'ttl_seconds' => 3600, 'on_timeout' => 'pause' ),
		);
	}

	private function step( $ctx, array &$steps, string $label, bool $passed, string $detail ): bool {
		// [2026-08-16 Johnny Chu] MPR-V5-HIL-DDV — emit granular evidence for the Diagnostics UI.
		$row = array( 'label' => $label, 'status' => $passed ? 'pass' : 'fail', 'detail' => $detail );
		$steps[] = $row;
		if ( is_object( $ctx ) && method_exists( $ctx, 'emit_step' ) ) {
			$ctx->emit_step( $row );
		}
		return $passed;
	}

	public function cleanup(): void {}
}

// [2026-08-16 Johnny Chu] MPR-V5-HIL-DDV — register the synthetic HIL probe in the central Smoke Runner catalog.
add_filter( 'bizcity_diagnostics_register_probes', function ( $probes ) {
	$probes[] = 'BizCity_Probe_TwinBrain_HIL';
	return $probes;
} );
