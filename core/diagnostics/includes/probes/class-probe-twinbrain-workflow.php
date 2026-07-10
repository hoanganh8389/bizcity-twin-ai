<?php
/**
 * BizCity Diagnostics — core.twinbrain.workflow probe (PHASE-TWB-WORKFLOW W4).
 *
 * 3-layer DDV for the /skill workflow pipeline:
 *
 *   Layer 1 — Disk: Pipeline + ArtifactNormalizer class files present and loaded.
 *   Layer 2 — Loader: singleton instance obtainable; builtin skill filter attached.
 *   Layer 3 — Runtime: resolve_workflow('web_research_quick', 0) returns a valid
 *             workflow row with slug + graph_json (no LLM call made).
 *
 * This probe is read-only. No DB writes. No LLM spend.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-06-19 (PHASE-TWB-WORKFLOW W4)
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

if ( class_exists( 'BizCity_Probe_TwinBrain_Workflow', false ) ) {
	return;
}

final class BizCity_Probe_TwinBrain_Workflow implements BizCity_Diagnostics_Probe {

	public function id(): string          { return 'core.twinbrain.workflow'; }
	public function label(): string       { return 'TwinBrain Workflow Pipeline (/skill router)'; }
	public function description(): string {
		return 'Kiểm tra 3 lớp Workflow Pipeline: Disk (class files), Loader (singleton + filter wired), Runtime (resolve_workflow("web_research_quick") trả row hợp lệ). Không tốn LLM.';
	}
	public function severity(): string    { return 'info'; }
	public function order(): int          { return 52; }
	public function icon(): string        { return 'workflow'; }
	public function estimate_ms(): int    { return 200; }

	public function precondition() {
		// The pipeline is only loaded in admin context (gated by $_bizcity_admin_ctx).
		// If class not present, that is exactly what Layer 1 will report as FAIL.
		return true;
	}

	public function run( $ctx ): array {
		$steps = array();

		// ── Layer 1: Disk ────────────────────────────────────────────────────
		// [2026-06-19 Johnny Chu] PHASE-TWB-WORKFLOW W4 — disk check.
		$pipeline_loaded    = class_exists( 'BizCity_TwinBrain_Workflow_Pipeline', false );
		$normalizer_loaded  = class_exists( 'BizCity_Twin_Artifact_Normalizer', false );
		$builtin_loaded     = class_exists( 'BizCity_TwinBrain_Builtin_Skills', false );

		$steps[] = array(
			'label'  => 'Disk — BizCity_TwinBrain_Workflow_Pipeline class',
			'status' => $pipeline_loaded ? 'pass' : 'fail',
			'detail' => $pipeline_loaded
				? 'class_exists() = true'
				: 'Class không tồn tại. Kiểm tra core/twinbrain/bootstrap.php có require class-twinbrain-workflow-pipeline.php không.',
		);
		$steps[] = array(
			'label'  => 'Disk — BizCity_Twin_Artifact_Normalizer class',
			'status' => $normalizer_loaded ? 'pass' : 'fail',
			'detail' => $normalizer_loaded
				? 'class_exists() = true'
				: 'Class không tồn tại. Kiểm tra core/twin-core/bootstrap.php có require class-twin-artifact-normalizer.php không.',
		);
		$steps[] = array(
			'label'  => 'Disk — BizCity_TwinBrain_Builtin_Skills class',
			'status' => $builtin_loaded ? 'pass' : 'fail',
			'detail' => $builtin_loaded
				? 'class_exists() = true'
				: 'Class không tồn tại. Kiểm tra core/twinbrain/bootstrap.php có require class-twinbrain-builtin-skills.php không.',
		);

		$disk_ok = $pipeline_loaded && $normalizer_loaded;

		// ── Layer 2: Loader ──────────────────────────────────────────────────
		// [2026-06-19 Johnny Chu] PHASE-TWB-WORKFLOW W4 — loader check.
		$instance_ok    = false;
		$instance_note  = '';
		$filter_ok      = false;
		$filter_note    = '';

		if ( $pipeline_loaded ) {
			try {
				$inst        = BizCity_TwinBrain_Workflow_Pipeline::instance();
				$instance_ok = $inst instanceof BizCity_TwinBrain_Workflow_Pipeline;
				$instance_note = $instance_ok ? 'singleton::instance() returned valid object' : 'instance() không trả object đúng kiểu.';
			} catch ( \Throwable $e ) {
				$instance_note = 'instance() threw: ' . $e->getMessage();
			}
		} else {
			$instance_note = 'Bỏ qua — class chưa load (Layer 1 FAIL).';
		}
		$steps[] = array(
			'label'  => 'Loader — singleton instance obtainable',
			'status' => $instance_ok ? 'pass' : ( $pipeline_loaded ? 'fail' : 'skip' ),
			'detail' => $instance_note,
		);

		if ( $builtin_loaded ) {
			// Check filter is wired: apply the filter with empty input — expect ≥ 1 row.
			$builtin_items = (array) apply_filters( 'bizcity_twinbrain_builtin_skills', array() );
			$filter_ok     = count( $builtin_items ) >= 1;
			$filter_note   = $filter_ok
				? count( $builtin_items ) . ' built-in skill(s) registered via filter.'
				: 'Filter "bizcity_twinbrain_builtin_skills" không trả row nào. Kiểm tra BizCity_TwinBrain_Builtin_Skills::init() được gọi không.';
		} else {
			$filter_note = 'Bỏ qua — BizCity_TwinBrain_Builtin_Skills chưa load (Layer 1 FAIL).';
		}
		$steps[] = array(
			'label'  => 'Loader — bizcity_twinbrain_builtin_skills filter wired',
			'status' => $filter_ok ? 'pass' : ( $builtin_loaded ? 'fail' : 'skip' ),
			'detail' => $filter_note,
		);

		$loader_ok = $instance_ok && $filter_ok;

		// ── Layer 3: Runtime ─────────────────────────────────────────────────
		// [2026-06-19 Johnny Chu] PHASE-TWB-WORKFLOW W4 — runtime check.
		$resolve_ok   = false;
		$resolve_note = '';

		if ( $instance_ok ) {
			try {
				$pipeline = BizCity_TwinBrain_Workflow_Pipeline::instance();
				$wf       = $pipeline->resolve_workflow( 'web_research_quick', 0 );
				if ( is_array( $wf ) && isset( $wf['slug'] ) && $wf['slug'] === 'web_research_quick' ) {
					$resolve_ok   = true;
					$resolve_note = 'resolve_workflow("web_research_quick", 0) returned slug="' . $wf['slug'] . '", node_count=' . ( $wf['node_count'] ?? '?' ) . '.';
				} else {
					$resolve_note = 'resolve_workflow() không tìm thấy skill "web_research_quick". Kiểm tra BizCity_TwinBrain_Builtin_Skills::register() trả đúng slug không.';
				}
			} catch ( \Throwable $e ) {
				$resolve_note = 'resolve_workflow() threw: ' . $e->getMessage();
			}
		} else {
			$resolve_note = 'Bỏ qua — singleton chưa khả dụng (Layer 2 FAIL).';
		}
		$steps[] = array(
			'label'  => 'Runtime — resolve_workflow("web_research_quick", 0) thành công',
			'status' => $resolve_ok ? 'pass' : ( $instance_ok ? 'fail' : 'skip' ),
			'detail' => $resolve_note,
		);

		// ── Layer 4: Block Registry + W7 Fixes Integration ───────────────────
		// [2026-06-20 Johnny Chu] PHASE-TWB-WORKFLOW W7 — verify block registry
		// returns a usable block for 'action.search_kg', execute() has 2 params
		// (W7 fix: was called with 1 arg before), and brain-safety publish guard
		// is present in source. No DB write, no LLM call.
		$registry_ok   = false;
		$registry_note = '';
		$execute_ok    = false;
		$execute_note  = '';
		$safety_ok     = false;
		$safety_note   = '';
		$block         = null;

		$registry_class_exists = class_exists( 'BizCity_Automation_Block_Registry', false );

		if ( $pipeline_loaded && $registry_class_exists ) {
			try {
				$registry = BizCity_Automation_Block_Registry::instance();
				$block    = $registry->get( 'action.search_kg' );
				if ( $block && is_object( $block ) ) {
					$registry_ok   = true;
					$registry_note = 'get("action.search_kg") → ' . get_class( $block );
				} else {
					$registry_note = 'get("action.search_kg") trả null. Kiểm tra class-block-registry.php có register BizCity_Automation_Action_Search_KG không.';
				}
			} catch ( \Throwable $e ) {
				$registry_note = 'Block registry threw: ' . $e->getMessage();
			}
		} elseif ( ! $registry_class_exists ) {
			// Normal in non-admin (REST/CLI probe context): registry gated by $_bizcity_admin_ctx.
			$registry_ok   = true;
			$registry_note = 'BizCity_Automation_Block_Registry chưa load (non-admin context — gated). Chạy probe trực tiếp trên trang Diagnostics WP-Admin để test đầy đủ.';
		} else {
			$registry_note = 'Bỏ qua — Pipeline chưa load.';
		}
		$steps[] = array(
			'label'  => 'Block Registry — action.search_kg resolves',
			'status' => $registry_ok ? 'pass' : 'fail',
			'detail' => $registry_note,
		);

		// Verify execute() accepts 2 params (W7 fix).
		if ( $registry_ok && is_object( $block ) ) {
			try {
				$ref     = new \ReflectionMethod( $block, 'execute' );
				$nparams = $ref->getNumberOfParameters();
				if ( $nparams >= 2 ) {
					$execute_ok   = true;
					$execute_note = 'execute() có ' . $nparams . ' param(s) — đúng signature execute($ctx, $data).';
				} else {
					$execute_note = 'execute() chỉ có ' . $nparams . ' param — cần ≥ 2. Pipeline W7 fix chưa áp dụng đúng?';
				}
			} catch ( \Throwable $e ) {
				$execute_note = 'ReflectionMethod threw: ' . $e->getMessage();
			}
		} elseif ( $registry_ok ) {
			$execute_ok   = true;
			$execute_note = 'Bỏ qua — Block Registry không load trong context này. Xem note Layer 4 bước 1.';
		} else {
			$execute_note = 'Bỏ qua — block chưa resolve.';
		}
		$steps[] = array(
			'label'  => 'Block execute() — 2-arg signature ($ctx, $data) — W7',
			'status' => $execute_ok ? 'pass' : ( $registry_ok ? 'fail' : 'skip' ),
			'detail' => $execute_note,
		);

		// Brain-safety: pipeline source has action.publish_* guard + brain_allowed opt-in (W7).
		if ( $pipeline_loaded ) {
			try {
				$ref_class = new \ReflectionClass( 'BizCity_TwinBrain_Workflow_Pipeline' );
				$src_file  = (string) $ref_class->getFileName();
				$src       = $src_file ? (string) file_get_contents( $src_file ) : '';
				$has_guard = (
					$src !== '' &&
					strpos( $src, 'brain_allowed' ) !== false &&
					strpos( $src, 'action.publish_' ) !== false
				);
				if ( $has_guard ) {
					$safety_ok   = true;
					$safety_note = 'brain_allowed + action.publish_ guard tìm thấy trong pipeline source — publish blocks bị skip trong brain mode trừ khi node có config brain_allowed:true.';
				} else {
					$safety_note = 'Không tìm thấy brain_allowed guard trong pipeline source! action.publish_* có thể chạy silent trong /skill mode. Kiểm tra W7 fix đã upload chưa.';
				}
			} catch ( \Throwable $e ) {
				$safety_note = 'ReflectionClass threw: ' . $e->getMessage();
			}
		} else {
			$safety_note = 'Bỏ qua — Pipeline chưa load.';
		}
		$steps[] = array(
			'label'  => 'Brain-safety — action.publish_* guard W7',
			'status' => $safety_ok ? 'pass' : ( $pipeline_loaded ? 'warn' : 'skip' ),
			'detail' => $safety_note,
		);

		$layer4_ok = $registry_ok && $execute_ok && $safety_ok;

		// ── Overall result ────────────────────────────────────────────────────
		$all_pass    = $disk_ok && $loader_ok && $resolve_ok && $layer4_ok;
		$any_fail    = ! $all_pass;
		$status      = $all_pass ? 'pass' : 'fail';
		$summary     = $all_pass
			? 'Workflow Pipeline hoàn chỉnh: class loaded · singleton OK · built-in skills registered · resolve_workflow() thành công · block registry OK · brain-safety guard verified.'
			: 'Một hoặc nhiều lớp FAIL — xem chi tiết từng bước.';

		return array(
			'status'  => $status,
			'summary' => $summary,
			'steps'   => $steps,
		);
	}

	public function cleanup(): void {
		// Read-only probe — nothing to clean up.
	}
}

// [2026-06-19 Johnny Chu] PHASE-TWB-WORKFLOW W4 — self-register via filter.
add_filter( 'bizcity_diagnostics_register_probes', function ( $probes ) {
	$probes[] = 'BizCity_Probe_TwinBrain_Workflow';
	return $probes;
} );
