<?php
/**
 * BizCity Diagnostics - Twin GPT tool registry probe.
 *
 * R-DDV 3 layers evidence:
 * - Disk: TwinWeb tool catalog, effective tools route, artifact canvas markers.
 * - Loader: catalog/REST methods and /tools/effective route registered.
 * - Runtime: keyword planner maps prompts to tool metadata without execution.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-07-19
 */

// [2026-07-19 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — DDV probe for metadata-only tool registry and artifact canvas contract.
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

if ( class_exists( 'BizCity_Probe_TwinWeb_Tool_Registry', false ) ) {
	return;
}

final class BizCity_Probe_TwinWeb_Tool_Registry implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'modules.twin_gpt.tool_registry'; }
	public function label(): string { return 'Twin GPT Tool Registry (/tools/effective)'; }
	public function description(): string {
		return 'Verifies the Twin GPT tool catalog, same-origin /tools/effective route, keyword planner, canonical registry adapters and artifact canvas contract without executing tools.';
	}
	public function severity(): string { return 'warning'; }
	public function order(): int { return 89; }
	public function icon(): string { return 'WandSparkles'; }
	public function estimate_ms(): int { return 140; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_TwinWeb_REST' ) ) {
			return new WP_Error( 'no_twinweb_rest', 'BizCity_TwinWeb_REST is not loaded.' );
		}
		if ( ! class_exists( 'BizCity_TwinWeb_Agent_Tool_Catalog' ) ) {
			return new WP_Error( 'no_tool_catalog', 'BizCity_TwinWeb_Agent_Tool_Catalog is not loaded.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — read-only probe; verifies MVP adapters without dispatching real provider calls.
		$steps = array();
		$pass  = true;

		$root = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( dirname( dirname( __DIR__ ) ) ) . '/';
		$catalog_file = $this->class_file_or_fallback( 'BizCity_TwinWeb_Agent_Tool_Catalog', $root . 'modules/twinweb/includes/class-twinweb-agent-tool-catalog.php' );
		$adapter_file = $root . 'modules/twinweb/includes/class-twinweb-agent-tool-adapters.php';
		$rest_file    = $this->class_file_or_fallback( 'BizCity_TwinWeb_REST', $root . 'modules/twinweb/includes/class-twinweb-rest.php' );
		$bootstrap_file = $root . 'modules/twinweb/bootstrap.php';
		$artifact_jobs_file = $root . 'modules/twinweb/includes/class-twinweb-artifact-jobs.php';
		$runtime_file = $root . 'core/twinbrain/includes/class-twinbrain-runtime.php';
		$changelog_file = $root . 'core/diagnostics/changelog/modules.twinweb.json';
		$artifact_api_file = $root . 'modules/twinweb/ui/src/api/artifacts.ts';
		$pane_file    = $root . 'modules/twinweb/ui/src/components/ArtifactsPane.tsx';
		$chat_file    = $root . 'modules/twinweb/ui/src/pages/ChatPage.tsx';
		$account_file = $root . 'modules/twinweb/ui/src/pages/MyAccountPage.tsx';
		$work_summary_file = $root . 'modules/twinweb/ui/src/api/workSummary.ts';
		$manifest_file = $root . 'modules/twinweb/ui/dist/.vite/manifest.json';
		// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — verify Doc Studio XLSX formula escaping as disk evidence.
		$xlsx_builder_file = $root . 'plugins/bizcity-doc/app/src/lib/spreadsheet-builder.ts';
		$xlsx_dist_file = $root . 'plugins/bizcity-doc/assets/dist/doc-spreadsheet-builder.js';
		$pptx_builder_file = $root . 'plugins/bizcity-doc/app/src/lib/presentation-builder.ts';
		$pptx_dist_file = $root . 'plugins/bizcity-doc/assets/dist/doc-presentation-builder.js';
		$bzdoc_app_file = $root . 'plugins/bizcity-doc/app/src/DocApp.tsx';
		$bzdoc_template_file = $root . 'plugins/bizcity-doc/views/page-doc-studio.php';
		$canva_wp_editor_file = $root . 'plugins/bizcity-tool-image/canva-editor_v1.0.69/libs/canva-editor/wp-app/WPEditor.tsx';

		$catalog_src = is_readable( $catalog_file ) ? file_get_contents( $catalog_file ) : '';
		$adapter_src = is_readable( $adapter_file ) ? file_get_contents( $adapter_file ) : '';
		$rest_src    = is_readable( $rest_file ) ? file_get_contents( $rest_file ) : '';
		$bootstrap_src = is_readable( $bootstrap_file ) ? (string) file_get_contents( $bootstrap_file ) : '';
		$artifact_jobs_src = is_readable( $artifact_jobs_file ) ? (string) file_get_contents( $artifact_jobs_file ) : '';
		$runtime_src = is_readable( $runtime_file ) ? (string) file_get_contents( $runtime_file ) : '';
		$changelog_src = is_readable( $changelog_file ) ? (string) file_get_contents( $changelog_file ) : '';
		$artifact_api_src = is_readable( $artifact_api_file ) ? (string) file_get_contents( $artifact_api_file ) : '';
		$pane_src = is_readable( $pane_file ) ? (string) file_get_contents( $pane_file ) : '';
		$chat_src = is_readable( $chat_file ) ? (string) file_get_contents( $chat_file ) : '';
		$bzdoc_app_src = is_readable( $bzdoc_app_file ) ? (string) file_get_contents( $bzdoc_app_file ) : '';
		$bzdoc_template_src = is_readable( $bzdoc_template_file ) ? (string) file_get_contents( $bzdoc_template_file ) : '';
		$canva_wp_editor_src = is_readable( $canva_wp_editor_file ) ? (string) file_get_contents( $canva_wp_editor_file ) : '';

		$catalog_ok = is_string( $catalog_src )
			&& strpos( $catalog_src, 'generate_image' ) !== false
			&& strpos( $catalog_src, 'render_html' ) !== false
			&& strpos( $catalog_src, 'generate_chart' ) !== false
			&& strpos( $catalog_src, 'create_doc' ) !== false
			&& strpos( $catalog_src, 'create_xlsx' ) !== false
			&& strpos( $catalog_src, 'create_pptx' ) !== false
			&& strpos( $catalog_src, 'create_mindmap' ) !== false
			&& strpos( $catalog_src, 'match_prompt' ) !== false
			&& strpos( $catalog_src, 'bizcity_twinweb_agent_tool_catalog' ) !== false;
		$this->emit( $ctx, $steps, $pass, 'Disk - built-in tool catalog markers', $catalog_ok, $catalog_ok ? 'Tool catalog contains image/html/chart/doc/xlsx/pptx/mindmap built-ins and keyword planner markers.' : 'Missing built-in tool catalog markers.' );

		$adapter_ok = is_string( $adapter_src )
			&& strpos( $adapter_src, 'BizCity_TwinWeb_Doc_Artifact_Tool' ) !== false
			&& strpos( $adapter_src, 'BizCity_TwinWeb_Image_Artifact_Tool' ) !== false
			&& strpos( $adapter_src, 'BizCity_TwinWeb_HTML_Artifact_Tool' ) !== false
			&& strpos( $adapter_src, 'BizCity_TwinWeb_Chart_Artifact_Tool' ) !== false
			&& strpos( $adapter_src, 'BZDoc_Notebook_Bridge::generate_from_skeleton_public' ) !== false
			&& strpos( $adapter_src, 'create_image_doc_handoff' ) !== false
			&& strpos( $adapter_src, 'build_spec_source_text' ) !== false
			&& strpos( $adapter_src, 'build_spec_file_context_text' ) !== false
			&& strpos( $adapter_src, 'build_spec_trace_payload' ) !== false
			&& strpos( $adapter_src, 'bizcity.twinweb.spec_trace.v1' ) !== false
			&& strpos( $adapter_src, 'tool_spec' ) !== false
			&& strpos( $adapter_src, 'twin_canvas' ) !== false
			&& strpos( $adapter_src, 'start_payload' ) !== false
			&& strpos( $adapter_src, 'BizCity_LLM_Client::instance' ) !== false
			&& strpos( $adapter_src, "'render_html'" ) !== false
			&& strpos( $adapter_src, "'generate_chart'" ) !== false
			&& strpos( $adapter_src, "'generate_image'" ) !== false;
		$this->emit( $ctx, $steps, $pass, 'Disk - canonical registry adapter markers', $adapter_ok, $adapter_ok ? 'Doc Studio, rich tool_spec skeleton, compact Canvas URL, image Canvas handoff, HTML preview and chart adapters are present without video code.' : 'Missing TwinWeb tool adapter markers.' );

		$rest_ok = is_string( $rest_src )
			&& strpos( $rest_src, "'/tools/effective'" ) !== false
			&& strpos( $rest_src, "'/artifacts/status'" ) !== false
			&& strpos( $rest_src, "'/artifacts/image/start'" ) !== false
			&& strpos( $rest_src, 'list_tools_effective' ) !== false
			&& strpos( $rest_src, 'get_artifact_status' ) !== false
			&& strpos( $rest_src, 'start_image_artifact' ) !== false
			&& strpos( $rest_src, 'bzdoc_rest_namespace' ) !== false
			&& strpos( $rest_src, 'metadata_only_no_execution' ) !== false
			&& strpos( $rest_src, 'tool_plan_min_rank' ) !== false;
		$this->emit( $ctx, $steps, $pass, 'Disk - tool catalog + artifact status route markers', $rest_ok, $rest_ok ? 'Effective tools route, artifact status proxy, image start proxy and no-execution marker found in TwinWeb REST.' : 'Missing /tools/effective, /artifacts/status, /artifacts/image/start route or no-execution marker.' );

		// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — image/poster MVP: BZDoc result plus Canva editor new-tab layer-1 handoff.
		$image_canva_handoff_ok = is_string( $rest_src )
			&& strpos( $rest_src, 'build_canva_editor_url' ) !== false
			&& strpos( $rest_src, "'canva_edit_url'" ) !== false
			&& strpos( $rest_src, "'imageUrl'" ) !== false
			&& strpos( $rest_src, "'source'   => 'bzdoc'" ) !== false
			&& strpos( $adapter_src, 'build_canva_editor_url' ) !== false
			&& strpos( $adapter_src, "'canva_edit_url'" ) !== false
			&& ( $pane_src === '' || ( strpos( $pane_src, 'artifactEditorUrl' ) !== false && strpos( $pane_src, 'Editor <ExternalLink' ) !== false ) )
			&& ( $chat_src === '' || ( strpos( $chat_src, 'artifactEditorUrl' ) !== false && strpos( $chat_src, 'Chỉnh sửa: [Editor]' ) !== false ) )
			&& ( $canva_wp_editor_src === '' || ( strpos( $canva_wp_editor_src, "params.get('src')" ) !== false && strpos( $canva_wp_editor_src, 'standaloneConfig(), imageUrl' ) !== false ) );
		$this->emit( $ctx, $steps, $pass, 'Disk - image Canva Editor layer-1 handoff markers', $image_canva_handoff_ok, $image_canva_handoff_ok ? 'Image artifact status/start expose /canva/?imageUrl=... and TwinWeb/Canva editor preserve an explicit Editor layer-1 handoff.' : 'Missing image Canva edit_url, TwinWeb Editor action, or WPEditor imageUrl/src standalone handoff marker.' );

		// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — rich spec + compact owner iframe shell contract.
		$spec_canvas_ok = strpos( $runtime_src, 'bizcity.twinweb.tool_spec.v1' ) !== false
			&& strpos( $runtime_src, 'build_tool_spec_pack' ) !== false
			&& strpos( $runtime_src, 'build_tool_files_ingest_summary' ) !== false
			&& strpos( $runtime_src, 'multimodal_ingest_pack' ) !== false
			&& strpos( $runtime_src, 'context_md' ) !== false
			&& strpos( $adapter_src, 'build_spec_file_context_text' ) !== false
			&& strpos( $adapter_src, "['spec_trace']" ) !== false
			&& strpos( $runtime_src, 'visible_prompt' ) !== false
			&& strpos( $rest_src, 'visible_prompt' ) !== false
			&& strpos( $bzdoc_template_src, 'twinCanvas' ) !== false
			&& strpos( $bzdoc_template_src, 'bzdoc-twin-canvas' ) !== false
			&& strpos( $bzdoc_app_src, 'isTwinCanvasMode' ) !== false
			&& strpos( $bzdoc_app_src, 'getFullStudioUrl' ) !== false;
		$this->emit( $ctx, $steps, $pass, 'Disk - rich tool spec + compact Canvas shell', $spec_canvas_ok, $spec_canvas_ok ? 'Runtime builds prioritized tool_spec with multimodal intake facts and BZDoc exposes compact twin_canvas artifact shell.' : 'Missing rich tool_spec, multimodal intake handoff, or compact BZDoc Canvas shell markers.' );

		// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — AT-7 durable job contract markers; FE source may be absent on production deploy.
		$at7_fe_ok = ( $artifact_api_src === '' && $pane_src === '' && $chat_src === '' )
			|| ( strpos( $artifact_api_src, 'jobStatus' ) !== false
				&& strpos( $artifact_api_src, '/artifacts/jobs/' ) !== false
				&& strpos( $pane_src, 'artifactsApi.jobStatus' ) !== false
				&& strpos( $pane_src, 'jobId' ) !== false
				&& strpos( $chat_src, 'jobId' ) !== false );
		$at7_ok = $artifact_jobs_src !== ''
			&& strpos( $artifact_jobs_src, 'class BizCity_TwinWeb_Artifact_Jobs' ) !== false
			&& strpos( $artifact_jobs_src, 'CRON_HOOK' ) !== false
			&& strpos( $artifact_jobs_src, 'poll_due_jobs' ) !== false
			&& strpos( $artifact_jobs_src, 'run_cron' ) !== false
			&& strpos( $artifact_jobs_src, 'BizCity_Cron_Manager::instance()->register' ) !== false
			&& strpos( $artifact_jobs_src, 'note_event' ) !== false
			&& strpos( $artifact_jobs_src, 'owner_user_id' ) !== false
			&& strpos( $artifact_jobs_src, 'guest_sid' ) !== false
			&& strpos( $artifact_jobs_src, 'information_schema.TABLES' ) !== false
			&& strpos( $bootstrap_src, 'BizCity_TwinWeb_Artifact_Jobs::init_cron' ) !== false
			&& strpos( $rest_src, "'/artifacts/jobs/(?P<job_id>[A-Za-z0-9_-]+)'" ) !== false
			&& strpos( $rest_src, 'get_artifact_job_status' ) !== false
			&& strpos( $runtime_src, 'attach_artifact_job_state' ) !== false
			&& strpos( $runtime_src, "['job_id']" ) !== false
			&& strpos( $changelog_src, 'bizcity_twinweb_artifact_jobs' ) !== false
			&& $at7_fe_ok;
		$this->emit( $ctx, $steps, $pass, 'Disk - AT-7 durable artifact jobs markers', $at7_ok, $at7_ok ? 'TwinWeb has R-DCL contract, owner-scoped job store, REST status route, runtime job_id producer, cron poller and Canvas job polling markers.' : 'Missing AT-7 durable artifact job contract/store/route/runtime/worker/UI marker.' );

		$dist_ok = is_readable( $manifest_file );
		$step = array(
			'label'  => 'Disk - FE deploy artifact policy',
			'status' => $dist_ok ? 'pass' : 'skip',
			'detail' => $dist_ok ? 'TwinWeb Vite manifest present; React src artifact canvas markers below are optional dev evidence.' : 'dist manifest missing; production may still provide assets through another deploy path.',
		);
		$steps[] = $step;
		$ctx->emit_step( $step );


		$account_src = is_readable( $account_file ) ? (string) file_get_contents( $account_file ) : '';
		$work_summary_src = is_readable( $work_summary_file ) ? (string) file_get_contents( $work_summary_file ) : '';
		// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — verify Phase-A artifact replay uses existing history/work-summary payloads, not AT-7 DDL.
		$chat_replay_marker_ok = $chat_src === ''
			|| ( strpos( (string) $chat_src, 'row.artifact' ) !== false
				&& strpos( (string) $chat_src, 'setActiveArtifact( replayArtifact )' ) !== false );
		$replay_ok = is_string( $rest_src ) && $rest_src !== ''
			&& strpos( $rest_src, 'extract_artifact_from_message_meta' ) !== false
			&& strpos( $rest_src, 'collect_member_chat_artifact_summary' ) !== false
			&& strpos( $rest_src, "'chat_total'" ) !== false
			&& $chat_replay_marker_ok
			&& ( $account_src === '' || strpos( $account_src, 'Generated artifacts' ) !== false )
			&& ( $work_summary_src === '' || strpos( $work_summary_src, 'chat_total' ) !== false );
		$this->emit( $ctx, $steps, $pass, 'Disk - artifact replay + MyAccount generated summary markers', $replay_ok, $replay_ok ? 'TwinWeb rehydrates Artifact Canvas from assistant history meta and includes generated chat artifacts in MyAccount work summary without opening AT-7 DDL.' : 'Missing artifact replay or MyAccount generated artifact markers.' );
		// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — accept source or built dist marker because production may deploy only assets.
		$xlsx_src = is_readable( $xlsx_builder_file ) ? (string) file_get_contents( $xlsx_builder_file ) : '';
		$xlsx_dist = is_readable( $xlsx_dist_file ) ? (string) file_get_contents( $xlsx_dist_file ) : '';
		$xlsx_formula_ok = ( $xlsx_src !== '' && strpos( $xlsx_src, 'escapeFormulaInjection' ) !== false && strpos( $xlsx_src, '/^\\s*[=+\\-@]/' ) !== false )
			|| ( $xlsx_dist !== '' && strpos( $xlsx_dist, '/^\\s*[=+\\-@]/' ) !== false && strpos( $xlsx_dist, "`'=\${" ) !== false );
		if ( $xlsx_src !== '' || $xlsx_dist !== '' ) {
			$this->emit( $ctx, $steps, $pass, 'Disk - XLSX formula injection escape', $xlsx_formula_ok, $xlsx_formula_ok ? 'Doc Studio spreadsheet builder escapes formula-like cells before XLSX export.' : 'Missing formula injection escape markers in spreadsheet builder.' );
		} else {
			$step = array(
				'label'  => 'Disk - XLSX formula injection escape',
				'status' => 'skip',
				'detail' => 'BizCity Doc spreadsheet builder source/dist not deployed in this environment.',
			);
			$steps[] = $step;
			$ctx->emit_step( $step );
		}

		// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — accept source or built dist marker for PPTX owned image slide support.
		$pptx_src = is_readable( $pptx_builder_file ) ? (string) file_get_contents( $pptx_builder_file ) : '';
		$pptx_dist = is_readable( $pptx_dist_file ) ? (string) file_get_contents( $pptx_dist_file ) : '';
		$pptx_image_ok = ( $pptx_src !== '' && strpos( $pptx_src, 'renderImageSlide' ) !== false && strpos( $pptx_src, 'normalizePptxImageSource' ) !== false && strpos( $pptx_src, 'window.location.origin' ) !== false )
			|| ( $pptx_dist !== '' && strpos( $pptx_dist, 'window.location.origin' ) !== false && strpos( $pptx_dist, 'data:image\\/' ) !== false );
		if ( $pptx_src !== '' || $pptx_dist !== '' ) {
			$this->emit( $ctx, $steps, $pass, 'Disk - PPTX safe image-slide assets', $pptx_image_ok, $pptx_image_ok ? 'Doc Studio presentation builder embeds only data, relative or same-origin image slide assets.' : 'Missing PPTX safe image-slide markers in presentation builder.' );
		} else {
			$step = array(
				'label'  => 'Disk - PPTX safe image-slide assets',
				'status' => 'skip',
				'detail' => 'BizCity Doc presentation builder source/dist not deployed in this environment.',
			);
			$steps[] = $step;
			$ctx->emit_step( $step );
		}

		if ( $pane_src !== '' && $chat_src !== '' ) {
			// [2026-07-19 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — require Agent Tool evaluation markers in dev source when source is deployed.
			$canvas_ok = strpos( $pane_src, "'image'" ) !== false
				&& strpos( $pane_src, "'chart'" ) !== false
				&& strpos( $pane_src, "'xlsx'" ) !== false
				&& strpos( $pane_src, "'pptx'" ) !== false
				&& strpos( $pane_src, "'mindmap'" ) !== false
				&& strpos( $pane_src, 'artifactsApi.status' ) !== false
				&& strpos( $pane_src, 'artifactsApi.startImage' ) !== false
				&& strpos( $pane_src, 'startPayload' ) !== false
				&& strpos( $pane_src, 'trusted_iframe' ) !== false
				&& strpos( $pane_src, 'getSpecTrace' ) !== false
				&& strpos( $pane_src, 'SpecTraceView' ) !== false
				&& strpos( $pane_src, "'spec'" ) !== false
				&& strpos( $pane_src, 'sandbox="allow-scripts allow-forms allow-modals"' ) !== false
				&& strpos( $chat_src, "et === 'artifact'" ) !== false
				&& strpos( $chat_src, "et === 'artifact_created'" ) !== false
				&& strpos( $chat_src, 'artifactFromToolEvent' ) !== false
				&& strpos( $chat_src, 'appendArtifactReceiptContent' ) !== false
				&& strpos( $chat_src, 'buildArtifactReceiptMessage' ) !== false
				&& strpos( $chat_src, 'ArtifactReceiptCard' ) !== false
				&& strpos( $chat_src, 'Đã tạo file:' ) !== false
				&& strpos( $chat_src, 'Mở lại artifact:' ) !== false
				&& strpos( $chat_src, 'statusUrl' ) !== false
				&& strpos( $chat_src, 'startUrl' ) !== false
				&& strpos( $chat_src, 'brain_tool_intent' ) !== false
				&& strpos( $chat_src, 'tool_done' ) !== false
				&& strpos( $chat_src, 'downloadUrl' ) !== false
				&& strpos( $chat_src, 'mimeType' ) !== false;
			$this->emit( $ctx, $steps, $pass, 'Disk - optional artifact canvas + MPR tool event dev-source markers', $canvas_ok, $canvas_ok ? 'ArtifactsPane supports rich artifact types and ChatPage maps artifact/tool evaluation events to canvas/timeline metadata.' : 'Artifact canvas or MPR tool event source markers are missing or drifted.' );
		} else {
			$step = array(
				'label'  => 'Disk - optional artifact canvas + MPR tool event dev-source markers',
				'status' => 'skip',
				'detail' => 'React src is absent; this is valid for production dist-only deploys.',
			);
			$steps[] = $step;
			$ctx->emit_step( $step );
		}

		$method_ok = method_exists( 'BizCity_TwinWeb_Agent_Tool_Catalog', 'all' )
			&& method_exists( 'BizCity_TwinWeb_Agent_Tool_Catalog', 'get' )
			&& method_exists( 'BizCity_TwinWeb_Agent_Tool_Catalog', 'match_prompt' )
			&& method_exists( 'BizCity_TwinWeb_REST', 'list_tools_effective' );
		$this->emit( $ctx, $steps, $pass, 'Loader - catalog and REST methods loaded', $method_ok, $method_ok ? 'Catalog all/get/match_prompt and REST list_tools_effective methods are loaded.' : 'Missing catalog or REST method.' );

		$routes = rest_get_server()->get_routes();
		$route_ok = $this->route_has_method( $routes, '/bizcity-twinweb/v1/tools/effective', 'GET' );
		$this->emit( $ctx, $steps, $pass, 'Loader - /tools/effective route registered', $route_ok, $route_ok ? 'GET /bizcity-twinweb/v1/tools/effective is registered.' : 'Missing GET /tools/effective route.' );

		$status_route_ok = $this->route_has_method( $routes, '/bizcity-twinweb/v1/artifacts/status', 'GET' );
		$this->emit( $ctx, $steps, $pass, 'Loader - /artifacts/status route registered', $status_route_ok, $status_route_ok ? 'GET /bizcity-twinweb/v1/artifacts/status is registered.' : 'Missing GET /artifacts/status route.' );

		$image_start_route_ok = $this->route_has_method( $routes, '/bizcity-twinweb/v1/artifacts/image/start', 'POST' );
		$this->emit( $ctx, $steps, $pass, 'Loader - /artifacts/image/start route registered', $image_start_route_ok, $image_start_route_ok ? 'POST /bizcity-twinweb/v1/artifacts/image/start is registered.' : 'Missing POST /artifacts/image/start route.' );

		$job_route_ok = $this->route_has_method( $routes, '/bizcity-twinweb/v1/artifacts/jobs/(?P<job_id>[A-Za-z0-9_-]+)', 'GET' );
		$this->emit( $ctx, $steps, $pass, 'Loader - /artifacts/jobs/{job_id} route registered', $job_route_ok, $job_route_ok ? 'GET /bizcity-twinweb/v1/artifacts/jobs/{job_id} is registered.' : 'Missing GET /artifacts/jobs/{job_id} route.' );

		$catalog = BizCity_TwinWeb_Agent_Tool_Catalog::instance();
		$tools = $catalog->all( array( 'surface' => 'twinweb', 'plan_slug' => 'free', 'plan_rank' => 0 ) );
		$required = array( 'generate_image', 'render_html', 'generate_chart', 'create_doc', 'create_xlsx', 'create_pptx', 'create_mindmap' );
		$missing = array();
		foreach ( $required as $slug ) {
			if ( empty( $tools[ $slug ] ) || empty( $tools[ $slug ]['artifact_type'] ) || empty( $tools[ $slug ]['execution'] ) || empty( $tools[ $slug ]['capability'] ) ) {
				$missing[] = $slug;
			}
		}
		$direct_ok = empty( $missing )
			&& (string) $tools['generate_image']['artifact_type'] === 'image'
			&& (string) $tools['generate_image']['execution'] === 'async'
			&& (string) $tools['render_html']['execution'] === 'sync_preview';
		$this->emit( $ctx, $steps, $pass, 'Runtime - direct catalog exposes required tool contracts', $direct_ok, sprintf( 'tools=%d; missing=%s', count( $tools ), empty( $missing ) ? 'none' : implode( ',', $missing ) ) );

		// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — document-like owner tools must advertise structured spec handoff in metadata catalog.
		$tool_spec_missing = array();
		foreach ( array( 'create_doc', 'create_xlsx', 'create_pptx', 'create_mindmap' ) as $spec_slug ) {
			$schema = isset( $tools[ $spec_slug ]['parameters_schema'] ) && is_array( $tools[ $spec_slug ]['parameters_schema'] ) ? $tools[ $spec_slug ]['parameters_schema'] : array();
			$properties = isset( $schema['properties'] ) && is_array( $schema['properties'] ) ? $schema['properties'] : array();
			if ( empty( $properties['tool_spec'] ) || ! is_array( $properties['tool_spec'] ) ) {
				$tool_spec_missing[] = $spec_slug;
			}
		}
		$this->emit( $ctx, $steps, $pass, 'Runtime - document artifact schemas expose tool_spec handoff', empty( $tool_spec_missing ), empty( $tool_spec_missing ) ? 'create_doc/create_xlsx/create_pptx/create_mindmap schemas expose tool_spec.' : 'Missing tool_spec in: ' . implode( ',', $tool_spec_missing ) );

		// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — call the private args builder with synthetic multimodal context; no provider/tool dispatch.
		$spec_builder_ok = false;
		$spec_builder_detail = 'BizCity_TwinBrain_Runtime::build_tool_args not available.';
		if ( class_exists( 'BizCity_TwinBrain_Runtime' ) && method_exists( 'BizCity_TwinBrain_Runtime', 'instance' ) && method_exists( 'BizCity_TwinBrain_Runtime', 'build_tool_args' ) ) {
			try {
				$runtime = BizCity_TwinBrain_Runtime::instance();
				$method = new ReflectionMethod( 'BizCity_TwinBrain_Runtime', 'build_tool_args' );
				$method->setAccessible( true );
				$synthetic_args = $method->invoke( $runtime, 'create_doc', (array) $tools['create_doc'], array(
					'prompt'       => 'Tạo tài liệu từ file đính kèm',
					'user_prompt'  => 'Tạo tài liệu từ file đính kèm',
					'visible_prompt' => 'Tạo tài liệu từ file đính kèm',
					'tool_prompt'  => "Tạo tài liệu từ file đính kèm\nĐiểm chính từ file",
					'surface'      => 'twinweb',
					'session_id'   => 'diag_thread',
					'history'      => array(
						array( 'role' => 'user', 'content' => 'Hãy đọc file và làm tài liệu.' ),
						array( 'role' => 'assistant', 'content' => 'Mình sẽ tạo canvas tài liệu.' ),
					),
					'attachment_ids' => array( 123 ),
					'attachments'    => array(
						array( 'id' => 123, 'filename' => 'brief.md', 'mime_type' => 'text/markdown', 'size' => 128, 'url' => 'https://example.test/brief.md' ),
					),
					'multimodal_context_md' => "## MULTIMODAL INTAKE CONTEXT\n### File Extract: brief.md\nNội dung brief đã được trích xuất.",
					'multimodal_ingest_pack' => array(
						'schema'          => 'bizcity.twinbrain.multimodal_ingest.v1',
						'intent'          => 'answer_with_attachments',
						'confidence'      => 'medium',
						'degraded'        => false,
						'reason_bucket'   => '',
						'attachments'     => array(
							array( 'id' => 123, 'filename' => 'brief.md', 'mime_type' => 'text/markdown', 'size' => 128, 'url' => 'https://example.test/brief.md', 'kind' => 'document' ),
						),
						'vision'          => array(),
						'ocr'             => array( 'OCR diag text' ),
						'entities'        => array( 'Twin GPT', 'Canvas' ),
						'query_expansion' => array( 'Nội dung brief đã được trích xuất.' ),
						'file_text'       => array(
							array( 'attachment_id' => 123, 'filename' => 'brief.md', 'text' => 'Nội dung brief đã được trích xuất.', 'char_count' => 34 ),
						),
						'audio_transcript'=> array(),
					),
				) );
				$tool_spec = isset( $synthetic_args['tool_spec'] ) && is_array( $synthetic_args['tool_spec'] ) ? $synthetic_args['tool_spec'] : array();
				$file_item = isset( $tool_spec['files']['items'][0] ) && is_array( $tool_spec['files']['items'][0] ) ? $tool_spec['files']['items'][0] : array();
				$intake = isset( $file_item['intake'] ) && is_array( $file_item['intake'] ) ? $file_item['intake'] : array();
				$spec_builder_ok = (string) ( $tool_spec['schema'] ?? '' ) === 'bizcity.twinweb.tool_spec.v1'
					&& (string) ( $tool_spec['files']['ingest']['schema'] ?? '' ) === 'bizcity.twinbrain.multimodal_ingest.v1'
					&& false !== strpos( (string) ( $tool_spec['files']['context_md'] ?? '' ), 'MULTIMODAL INTAKE CONTEXT' )
					&& false !== strpos( (string) ( $intake['text_excerpt'] ?? '' ), 'Nội dung brief' )
					&& false !== strpos( (string) ( $tool_spec['thread']['summary'] ?? '' ), 'Hãy đọc file' );
				$spec_builder_detail = sprintf(
					'schema=%s; ingest=%s; item_intake=%s; thread=%s',
					(string) ( $tool_spec['schema'] ?? 'MISSING' ),
					(string) ( $tool_spec['files']['ingest']['schema'] ?? 'MISSING' ),
					! empty( $intake['text_excerpt'] ) ? 'yes' : 'no',
					! empty( $tool_spec['thread']['summary'] ) ? 'yes' : 'no'
				);
			} catch ( \Throwable $e ) {
				$spec_builder_detail = 'Exception: ' . $e->getMessage();
			}
		}
		$this->emit( $ctx, $steps, $pass, 'Runtime - TwinBrain tool args builder emits rich tool_spec', $spec_builder_ok, $spec_builder_detail );

		$registry_ok = false;
		$registry_detail = 'BizCity_Twin_Tool_Registry not loaded.';
		if ( class_exists( 'BizCity_Twin_Tool_Registry' ) ) {
			$registry = BizCity_Twin_Tool_Registry::instance();
			$doc_tool = $registry->get( 'create_doc' );
			$xlsx_tool = $registry->get( 'create_xlsx' );
			$pptx_tool = $registry->get( 'create_pptx' );
			$mindmap_tool = $registry->get( 'create_mindmap' );
			$image_tool = $registry->get( 'generate_image' );
			$html_tool = $registry->get( 'render_html' );
			$chart_tool = $registry->get( 'generate_chart' );
			$registry_ok = $doc_tool instanceof BizCity_Twin_Tool
				&& $xlsx_tool instanceof BizCity_Twin_Tool
				&& $pptx_tool instanceof BizCity_Twin_Tool
				&& $mindmap_tool instanceof BizCity_Twin_Tool
				&& $image_tool instanceof BizCity_Twin_Tool
				&& $html_tool instanceof BizCity_Twin_Tool
				&& $chart_tool instanceof BizCity_Twin_Tool;
			$registry_detail = sprintf(
				'create_doc=%s; create_xlsx=%s; create_pptx=%s; create_mindmap=%s; generate_image=%s; render_html=%s; generate_chart=%s',
				$doc_tool instanceof BizCity_Twin_Tool ? 'yes' : 'no',
				$xlsx_tool instanceof BizCity_Twin_Tool ? 'yes' : 'no',
				$pptx_tool instanceof BizCity_Twin_Tool ? 'yes' : 'no',
				$mindmap_tool instanceof BizCity_Twin_Tool ? 'yes' : 'no',
				$image_tool instanceof BizCity_Twin_Tool ? 'yes' : 'no',
				$html_tool instanceof BizCity_Twin_Tool ? 'yes' : 'no',
				$chart_tool instanceof BizCity_Twin_Tool ? 'yes' : 'no'
			);
		}
		$this->emit( $ctx, $steps, $pass, 'Runtime - canonical tool registry has MVP artifact adapters', $registry_ok, $registry_detail );

		$matches = $catalog->match_prompt( 'tạo ảnh poster sản phẩm đẹp', array( 'surface' => 'twinweb', 'plan_slug' => 'free', 'plan_rank' => 0 ) );
		$match_ok = ! empty( $matches )
			&& isset( $matches[0]['tool_slug'] )
			&& (string) $matches[0]['tool_slug'] === 'generate_image'
			&& (string) ( $matches[0]['artifact_type'] ?? '' ) === 'image'
			&& (float) ( $matches[0]['score'] ?? 0 ) > 0;
		$this->emit( $ctx, $steps, $pass, 'Runtime - prompt planner maps image request to generate_image', $match_ok, sprintf( 'top=%s; score=%s; matches=%d', isset( $matches[0]['tool_slug'] ) ? (string) $matches[0]['tool_slug'] : 'MISSING', isset( $matches[0]['score'] ) ? (string) $matches[0]['score'] : 'MISSING', count( $matches ) ) );

		// [2026-07-19 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — TwinBrain normal MPR must consume the TwinWeb catalog planner, not only the REST preview route.
		$matcher_ok = false;
		$matcher_detail = 'BizCity_TwinBrain_Tool_Intent_Matcher not loaded.';
		if ( class_exists( 'BizCity_TwinBrain_Tool_Intent_Matcher' ) ) {
			$brain_matches = BizCity_TwinBrain_Tool_Intent_Matcher::instance()->match( 'tạo ảnh poster sản phẩm đẹp', 0, array( 'surface' => 'twinweb' ) );
			$matcher_top = isset( $brain_matches[0] ) && is_array( $brain_matches[0] ) ? $brain_matches[0] : array();
			$matcher_ok = ! empty( $brain_matches )
				&& (string) ( $matcher_top['skill_slug'] ?? '' ) === 'generate_image'
				&& false !== strpos( (string) ( $matcher_top['reason'] ?? '' ), 'twinweb_catalog' )
				&& (string) ( $matcher_top['artifact_type'] ?? '' ) === 'image';
			$matcher_detail = sprintf(
				'top=%s; reason=%s; artifact=%s; matches=%d',
				(string) ( $matcher_top['skill_slug'] ?? 'MISSING' ),
				(string) ( $matcher_top['reason'] ?? 'MISSING' ),
				(string) ( $matcher_top['artifact_type'] ?? 'MISSING' ),
				count( $brain_matches )
			);
		}
		$this->emit( $ctx, $steps, $pass, 'Runtime - TwinBrain matcher consumes TwinWeb catalog candidates', $matcher_ok, $matcher_detail );

		$original_uid = get_current_user_id();
		wp_set_current_user( 0 );
		try {
			$req = new WP_REST_Request( 'GET', '/bizcity-twinweb/v1/tools/effective' );
			$req->set_query_params( array( 'q' => 'tạo ảnh poster sản phẩm đẹp' ) );
			$res = rest_do_request( $req );
			$data = is_wp_error( $res ) ? array() : (array) rest_ensure_response( $res )->get_data();
			$items = isset( $data['items'] ) && is_array( $data['items'] ) ? $data['items'] : array();
			$candidates = isset( $data['candidates'] ) && is_array( $data['candidates'] ) ? $data['candidates'] : array();
			$image_row = $this->find_tool_row( $items, 'generate_image' );
			$html_row = $this->find_tool_row( $items, 'render_html' );
			$route_runtime_ok = ! empty( $data['success'] )
				&& empty( $data['_degraded'] )
				&& count( $items ) >= 5
				&& is_array( $image_row )
				&& is_array( $html_row )
				&& ! empty( $html_row['available'] )
				&& isset( $image_row['locked'], $image_row['upsell'] )
				&& (string) ( $data['execution_note'] ?? '' ) === 'metadata_only_no_execution'
				&& ! empty( $candidates )
				&& (string) ( $candidates[0]['tool_slug'] ?? '' ) === 'generate_image';
			$this->emit( $ctx, $steps, $pass, 'Runtime - /tools/effective returns metadata-only catalog and candidates', $route_runtime_ok, sprintf( 'success=%s; degraded=%s; items=%d; top=%s; note=%s', ! empty( $data['success'] ) ? 'yes' : 'no', ! empty( $data['_degraded'] ) ? 'yes' : 'no', count( $items ), (string) ( $candidates[0]['tool_slug'] ?? 'MISSING' ), (string) ( $data['execution_note'] ?? 'MISSING' ) ) );

			// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — runtime route smoke catches production rest_no_route for Canvas job polling.
			$job_req = new WP_REST_Request( 'GET', '/bizcity-twinweb/v1/artifacts/jobs/twaj_diag_missing' );
			$job_res = rest_do_request( $job_req );
			$job_data = is_wp_error( $job_res ) ? array( 'code' => $job_res->get_error_code() ) : (array) rest_ensure_response( $job_res )->get_data();
			$job_code = (string) ( $job_data['code'] ?? '' );
			$job_status = (string) ( $job_data['job_status'] ?? $job_data['status'] ?? '' );
			$job_route_runtime_ok = ! is_wp_error( $job_res )
				&& isset( $job_data['job_id'] )
				&& (string) $job_data['job_id'] === 'twaj_diag_missing'
				&& in_array( $job_code, array( 'not_found', 'module_not_loaded' ), true )
				&& in_array( $job_status, array( 'missing', 'unknown' ), true );
			$this->emit( $ctx, $steps, $pass, 'Runtime - /artifacts/jobs/{job_id} returns structured missing-job payload', $job_route_runtime_ok, sprintf( 'code=%s; job_status=%s; success=%s; degraded=%s', $job_code !== '' ? $job_code : 'MISSING', $job_status !== '' ? $job_status : 'MISSING', ! empty( $job_data['success'] ) ? 'yes' : 'no', ! empty( $job_data['_degraded'] ) ? 'yes' : 'no' ) );
		} finally {
			wp_set_current_user( $original_uid );
		}

		return array(
			'status'   => $pass ? 'pass' : 'fail',
			'summary'  => $pass
				? 'Twin GPT tool registry metadata, planner, MVP adapters and artifact canvas contract PASS without executing real tools.'
				: 'Twin GPT tool registry contract failed one or more checks.',
			'error'    => $pass ? '' : 'twinweb_tool_registry_contract_failed',
			'fix_hint' => $pass ? '' : 'Check class-twinweb-agent-tool-catalog.php, /tools/effective route, and artifact canvas event markers. This probe must not execute real tools.',
			'steps'    => $steps,
		);
	}

	public function cleanup(): void {
		// Read-only probe; no real provider/tool execution or artifacts created.
	}

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

	private function find_tool_row( $items, $slug ) {
		if ( ! is_array( $items ) ) {
			return null;
		}
		foreach ( $items as $item ) {
			if ( is_array( $item ) && isset( $item['slug'] ) && (string) $item['slug'] === (string) $slug ) {
				return $item;
			}
		}
		return null;
	}

	private function class_file_or_fallback( $class_name, $fallback ) {
		if ( class_exists( 'ReflectionClass' ) && class_exists( (string) $class_name ) ) {
			try {
				$ref = new ReflectionClass( (string) $class_name );
				$file = (string) $ref->getFileName();
				if ( $file !== '' && is_readable( $file ) ) {
					return $file;
				}
			} catch ( Throwable $e ) {
				// Use fallback below.
			}
		}
		return $fallback;
	}

	private function route_has_method( $routes, $route, $method ) {
		if ( ! isset( $routes[ $route ] ) || ! is_array( $routes[ $route ] ) ) {
			return false;
		}
		$want = strtoupper( (string) $method );
		foreach ( $routes[ $route ] as $ep ) {
			if ( ! is_array( $ep ) || empty( $ep['methods'] ) ) {
				continue;
			}
			if ( is_string( $ep['methods'] ) && false !== strpos( strtoupper( (string) $ep['methods'] ), $want ) ) {
				return true;
			}
			if ( is_array( $ep['methods'] ) ) {
				foreach ( $ep['methods'] as $registered => $enabled ) {
					if ( $enabled && strtoupper( (string) $registered ) === $want ) {
						return true;
					}
				}
			}
		}
		return false;
	}
}

// [2026-07-19 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — register Twin GPT tool registry probe.
add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_TwinWeb_Tool_Registry';
	return $list;
} );
