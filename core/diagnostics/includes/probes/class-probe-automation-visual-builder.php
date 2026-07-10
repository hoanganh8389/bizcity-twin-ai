<?php
/**
 * BizCity Diagnostics — core.automation.visual_builder probe (PHASE-0.40 G2.8).
 *
 * R-DDV: Kiểm tra Workflow Visual Builder đã ship đầy đủ:
 *   - BE: GET /bizcity-automation/v1/blocks route
 *   - BE: GET /bizcity-automation/v1/templates route
 *   - FE: WorkflowBuilderRoute.jsx (ReactFlow canvas)
 *   - FE: Palette.jsx (node palette với drag)
 *   - FE: Inspector.jsx (node config panel)
 *   - FE: TemplateGallery.jsx (template store)
 *   - FE: RunTimeline.jsx (run history per-node)
 *
 * DDV rows (7 Disk layers + 2 Loader):
 *   G2.be.blocks_route        — automation REST has /blocks handler
 *   G2.be.templates_route     — automation REST has /templates handler
 *   G2.fe.builder_route       — WorkflowBuilderRoute.jsx exists + has ReactFlow
 *   G2.fe.palette             — Palette.jsx exists
 *   G2.fe.inspector           — Inspector.jsx exists
 *   G2.fe.template_gallery    — TemplateGallery.jsx exists
 *   G2.fe.run_timeline        — RunTimeline.jsx exists
 *   G2.loader.automation_rest — BizCity_Automation_REST class loaded
 *   G2.loader.block_registry  — BizCity_Automation_Block_Registry class loaded
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-06-13 (PHASE-0.40 G2.8 / R-DDV)
 */

// [2026-06-13 Johnny Chu] PHASE-0.40 G2.8 — DDV probe visual builder (R-DDV bắt buộc)
defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

if ( class_exists( 'BizCity_Probe_Automation_Visual_Builder', false ) ) {
	return;
}

final class BizCity_Probe_Automation_Visual_Builder implements BizCity_Diagnostics_Probe {

	public function id(): string          { return 'core.automation.visual_builder'; }
	public function label(): string       { return 'Workflow Visual Builder: ReactFlow canvas + Palette + Templates'; }
	public function description(): string {
		return '7 Disk + 2 Loader layers — BE blocks/templates routes + FE WorkflowBuilder ReactFlow canvas, NodePalette, Inspector, TemplateGallery, RunTimeline (PHASE-0.40 G2.8 R-DDV).';
	}
	public function severity(): string    { return 'warning'; }
	public function order(): int          { return 46; }
	public function icon(): string        { return 'git-branch'; }
	public function estimate_ms(): int    { return 120; }

	public function precondition() {
		return true;
	}

	// [2026-06-14 Johnny Chu] HOTFIX — add missing $ctx param to match BizCity_Diagnostics_Probe::run($ctx):array
	public function run( $ctx ): array {
		$steps = array();
		$pass  = true;

		$auto_dir = WP_PLUGIN_DIR . '/bizcity-twin-ai/core/automation/';
		$fe_dir   = $auto_dir . 'frontend/src/';

		// ── G2.be — BE REST routes ────────────────────────────────────────────
		$rest_file = $auto_dir . 'includes/class-automation-rest.php';
		$rest_ok   = file_exists( $rest_file );
		$rest_text = $rest_ok ? file_get_contents( $rest_file ) : '';

		$has_blocks_route = $rest_ok && ( false !== strpos( $rest_text, '/blocks' ) );
		$steps[] = array(
			'id' => 'G2.be.blocks_route', 'label' => 'Disk: BE — GET /bizcity-automation/v1/blocks route',
			'pass' => $has_blocks_route,
			'detail' => $has_blocks_route ? 'OK — /blocks route handler found in class-automation-rest.php' : 'MISSING — add GET /blocks route to class-automation-rest.php',
		);
		if ( ! $has_blocks_route ) { $pass = false; }

		$has_tpl_route = $rest_ok && ( false !== strpos( $rest_text, '/templates' ) );
		$steps[] = array(
			'id' => 'G2.be.templates_route', 'label' => 'Disk: BE — GET /bizcity-automation/v1/templates route',
			'pass' => $has_tpl_route,
			'detail' => $has_tpl_route ? 'OK — /templates route handler found in class-automation-rest.php' : 'MISSING — add GET /templates route to class-automation-rest.php',
		);
		if ( ! $has_tpl_route ) { $pass = false; }

		// ── G2.fe — FE files ─────────────────────────────────────────────────
		$fe_checks = array(
			array(
				'id'    => 'G2.fe.builder_route',
				'label' => 'Disk: FE — WorkflowBuilderRoute.jsx (ReactFlow canvas)',
				'file'  => $fe_dir . 'routes/WorkflowBuilderRoute.jsx',
				'must_contain' => 'ReactFlow',
			),
			array(
				'id'    => 'G2.fe.palette',
				'label' => 'Disk: FE — Palette.jsx (node drag palette)',
				'file'  => $fe_dir . 'components/Palette.jsx',
				'must_contain' => null,
			),
			array(
				'id'    => 'G2.fe.inspector',
				'label' => 'Disk: FE — Inspector.jsx (node config panel)',
				'file'  => $fe_dir . 'components/Inspector.jsx',
				'must_contain' => null,
			),
			array(
				'id'    => 'G2.fe.template_gallery',
				'label' => 'Disk: FE — TemplateGallery.jsx (template store)',
				'file'  => $fe_dir . 'components/TemplateGallery.jsx',
				'must_contain' => 'templatesApi',
			),
			array(
				'id'    => 'G2.fe.run_timeline',
				'label' => 'Disk: FE — RunTimeline.jsx (run history per-node)',
				'file'  => $fe_dir . 'components/RunTimeline.jsx',
				'must_contain' => null,
			),
		);

		foreach ( $fe_checks as $check ) {
			$exists = file_exists( $check['file'] );
			$ok     = $exists;
			if ( $exists && ! empty( $check['must_contain'] ) ) {
				$ok = false !== strpos( file_get_contents( $check['file'] ), $check['must_contain'] );
			}
			$steps[] = array(
				'id'     => $check['id'],
				'label'  => $check['label'],
				'pass'   => $ok,
				'detail' => $ok
					? 'OK — file present' . ( ! empty( $check['must_contain'] ) ? ' + expected import found' : '' )
					: ( ! $exists ? 'MISSING — file not found: ' . basename( $check['file'] ) : 'MISSING expected content: ' . $check['must_contain'] ),
			);
			if ( ! $ok ) { $pass = false; }
		}

		// ── G2.loader — classes loaded ────────────────────────────────────────
		$rest_loaded     = class_exists( 'BizCity_Automation_REST' );
		$registry_loaded = class_exists( 'BizCity_Automation_Block_Registry' );

		$steps[] = array(
			'id' => 'G2.loader.automation_rest', 'label' => 'Loader: BizCity_Automation_REST class',
			'pass' => $rest_loaded,
			'detail' => $rest_loaded ? 'OK — BizCity_Automation_REST loaded' : 'MISSING — core/automation not loaded (check admin context)',
		);
		if ( ! $rest_loaded ) { $pass = false; }

		$steps[] = array(
			'id' => 'G2.loader.block_registry', 'label' => 'Loader: BizCity_Automation_Block_Registry class',
			'pass' => $registry_loaded,
			'detail' => $registry_loaded ? 'OK — block registry loaded, ' . ( class_exists( 'BizCity_Automation_Block_Registry' ) ? 'ready' : '' ) : 'MISSING — block registry not loaded',
		);
		if ( ! $registry_loaded ) { $pass = false; }

		return array( 'pass' => $pass, 'steps' => $steps );
	}

	// [2026-06-14 Johnny Chu] HOTFIX — required by BizCity_Diagnostics_Probe interface
	public function cleanup(): void {}
}

// Self-register through the standard filter.
add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = new BizCity_Probe_Automation_Visual_Builder();
	return $list;
} );
