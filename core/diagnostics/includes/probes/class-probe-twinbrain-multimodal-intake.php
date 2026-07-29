<?php
/**
 * BizCity Diagnostics - TwinBrain multimodal intake probe.
 *
 * @package Bizcity_Twin_AI
 */

// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MULTIMODAL — DDV probe for default attachment/vision/file intake layer.
defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false ) ) {
	$_iface_path = defined( 'BIZCITY_DIAGNOSTICS_DIR' )
		? BIZCITY_DIAGNOSTICS_DIR . 'includes/interface-diagnostics-probe.php'
		: dirname( __DIR__ ) . '/interface-diagnostics-probe.php';
	if ( is_readable( $_iface_path ) ) {
		require_once $_iface_path;
	}
}
if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false ) ) {
	return;
}

if ( class_exists( 'BizCity_Probe_TwinBrain_Multimodal_Intake', false ) ) {
	return;
}

final class BizCity_Probe_TwinBrain_Multimodal_Intake implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'core.twinbrain.multimodal_intake'; }
	public function label(): string { return 'TwinBrain Multimodal Intake'; }
	public function description(): string {
		return 'Verifies the default subject -> attachment manifest -> multimodal intake -> Notebook/RAG timeline contract.';
	}
	public function severity(): string { return 'warning'; }
	public function order(): int { return 91; }
	public function icon(): string { return 'FileSearch'; }
	public function estimate_ms(): int { return 120; }

	public function precondition() {
		$root = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( dirname( dirname( dirname( __DIR__ ) ) ) ) . '/';
		$intake = $root . 'core/twinbrain/includes/class-twinbrain-multimodal-intake-layer.php';
		if ( ! class_exists( 'BizCity_TwinBrain_Multimodal_Intake_Layer', false ) && is_readable( $intake ) ) {
			require_once $intake;
		}
		if ( ! class_exists( 'BizCity_TwinBrain_Multimodal_Intake_Layer', false ) ) {
			return new WP_Error( 'multimodal_intake_missing', 'BizCity_TwinBrain_Multimodal_Intake_Layer is not loaded.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MULTIMODAL — synthetic runtime validates event contract without real provider calls.
		$steps = array();
		$pass  = true;
		$root  = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( dirname( dirname( dirname( __DIR__ ) ) ) ) . '/';

		$intake_file  = $root . 'core/twinbrain/includes/class-twinbrain-multimodal-intake-layer.php';
		$runtime_file = $root . 'core/twinbrain/includes/class-twinbrain-runtime.php';
		$llm_file     = $root . 'core/bizcity-llm/includes/class-llm-client.php';
		$final_file   = $root . 'core/twinbrain/includes/class-twinbrain-final-composer.php';
		$ui_file      = $root . 'modules/twinweb/ui/src/components/ThinkingSteps.tsx';

		$intake_src  = is_readable( $intake_file ) ? (string) file_get_contents( $intake_file ) : '';
		$runtime_src = is_readable( $runtime_file ) ? (string) file_get_contents( $runtime_file ) : '';
		$llm_src     = is_readable( $llm_file ) ? (string) file_get_contents( $llm_file ) : '';
		$final_src   = is_readable( $final_file ) ? (string) file_get_contents( $final_file ) : '';
		$ui_src      = is_readable( $ui_file ) ? (string) file_get_contents( $ui_file ) : '';

		$disk_ok = strpos( $intake_src, 'class BizCity_TwinBrain_Multimodal_Intake_Layer' ) !== false
			&& strpos( $intake_src, 'attachment_manifest_ready' ) !== false
			&& strpos( $intake_src, 'vision_analysis_started' ) !== false
			&& strpos( $intake_src, 'intent_detected' ) !== false;
		$this->emit( $ctx, $steps, $pass, 'Disk - intake class and event markers', $disk_ok, $disk_ok ? 'Multimodal intake class and timeline event markers found.' : 'Missing intake class/event markers.' );

		$runtime_ok = strpos( $runtime_src, 'collect_multimodal_intake_context' ) !== false
			&& strpos( $runtime_src, 'prompt_for_reasoning' ) !== false
			&& strpos( $runtime_src, 'prompt_compiler_ready' ) !== false
			&& strpos( $runtime_src, 'rerank_done' ) !== false;
		$this->emit( $ctx, $steps, $pass, 'Disk - runtime handoff markers', $runtime_ok, $runtime_ok ? 'Runtime calls intake before Notebook/RAG and emits rerank/prompt compiler events.' : 'Missing runtime multimodal handoff markers.' );

		$wrapper_ok = strpos( $llm_src, 'function analyze_media' ) !== false
			&& strpos( $llm_src, '/tools/vision-analyze' ) !== false
			&& strpos( $llm_src, 'analyze_media_via_chat_fallback' ) !== false;
		$this->emit( $ctx, $steps, $pass, 'Disk - LLM vision wrapper markers', $wrapper_ok, $wrapper_ok ? 'BizCity_LLM_Client::analyze_media wrapper and chat fallback markers found.' : 'Missing LLM vision wrapper markers.' );

		$composer_ok = strpos( $final_src, 'MULTIMODAL INTAKE CONTRACT' ) !== false
			&& strpos( $final_src, 'multimodal_context_md' ) !== false;
		$this->emit( $ctx, $steps, $pass, 'Disk - Final Composer context contract', $composer_ok, $composer_ok ? 'Final Composer consumes multimodal context and degraded contract.' : 'Missing Final Composer multimodal context contract.' );

		$ui_ok = strpos( $ui_src, 'vision_analysis_done' ) !== false
			&& strpos( $ui_src, 'file_extract_done' ) !== false
			&& strpos( $ui_src, 'prompt_compiler_ready' ) !== false;
		$step = array(
			'label'  => 'Disk - optional MPR UI timeline mappings',
			'status' => $ui_ok ? 'pass' : 'skip',
			'detail' => $ui_ok ? 'ThinkingSteps maps multimodal intake events.' : 'React src missing/drifted; production dist-only deployments may still be valid.',
		);
		$steps[] = $step;
		$ctx->emit_step( $step );

		$loader_ok = class_exists( 'BizCity_TwinBrain_Multimodal_Intake_Layer' )
			&& method_exists( 'BizCity_TwinBrain_Multimodal_Intake_Layer', 'collect' )
			&& class_exists( 'BizCity_LLM_Client' )
			&& method_exists( 'BizCity_LLM_Client', 'analyze_media' );
		$this->emit( $ctx, $steps, $pass, 'Loader - intake and LLM wrapper loaded', $loader_ok, $loader_ok ? 'Multimodal intake and analyze_media are loaded.' : 'Missing loaded class/method.' );

		$events = array();
		$opts = BizCity_TwinBrain_Multimodal_Intake_Layer::instance()->collect(
			'ddv_multimodal_' . wp_generate_uuid4(),
			'Phân tích file này giúp tôi',
			array(
				'attachments' => array(
					array(
						'id'        => 999001,
						'filename'  => 'synthetic-reference-doc.txt',
						'mime_type' => 'text/plain',
						'size'      => 42,
						'url'       => 'https://example.invalid/synthetic-reference-doc.txt',
					),
				),
			),
			function ( $event_type, array $payload ) use ( &$events ) {
				$events[] = array( 'event_type' => (string) $event_type, 'payload' => $payload );
			}
		);

		$event_names = array_map( static function ( $event ) { return (string) $event['event_type']; }, $events );
		$runtime_ok = in_array( 'attachment_manifest_ready', $event_names, true )
			&& in_array( 'multimodal_ingest_started', $event_names, true )
			&& in_array( 'file_extract_degraded', $event_names, true )
			&& in_array( 'intent_detected', $event_names, true )
			&& ! empty( $opts['multimodal_ingest_pack'] )
			&& ! empty( $opts['multimodal_context_md'] )
			&& ! empty( $opts['multimodal_enriched_query'] );
		$this->emit( $ctx, $steps, $pass, 'Runtime - synthetic attachment intake events', $runtime_ok, sprintf( 'events=%s', implode( ',', $event_names ) ) );

		return array(
			'status'   => $pass ? 'pass' : 'fail',
			'summary'  => $pass ? 'TwinBrain multimodal intake contract PASS.' : 'TwinBrain multimodal intake contract failed.',
			'error'    => $pass ? '' : 'twinbrain_multimodal_intake_failed',
			'fix_hint' => $pass ? '' : 'Check intake class load, runtime handoff, LLM analyze_media wrapper and ThinkingSteps mappings.',
			'steps'    => $steps,
		);
	}

	public function cleanup(): void {}

	private function emit( $ctx, array &$steps, &$pass, $label, $ok, $detail ) {
		$step = array(
			'label'  => (string) $label,
			'status' => $ok ? 'pass' : 'fail',
			'detail' => (string) $detail,
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $ok ) {
			$pass = false;
		}
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = new BizCity_Probe_TwinBrain_Multimodal_Intake();
	return $list;
} );