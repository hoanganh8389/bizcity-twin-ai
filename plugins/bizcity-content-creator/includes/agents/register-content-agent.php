<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Agents\Bootstrap
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 *
 * Phase 0.13 / Vòng 4.6 — Task 4.6.2
 * Content Agent — wire to REAL `bizcity-content-creator` plugin.
 *
 * Vòng 4.6 (Sprint 13.2) — replace stub `write_snippet` LLM with REAL plugin wiring.
 *   • Tool `content_creator_execute(template_slug, form_data)` → insert pending file +
 *       kick BZCC_Rest_API::generate_file (writes wp_1258_bzcc_files). HIL needs_approval=true.
 *   • Tool `list_templates(category?)` → BZCC_Template_Manager::get_all_active (read-only).
 *
 * Pattern mirrors register-doc-agent.php (Vòng 4.5.1):
 *   - Direct in-process REST call (no HTTP self-loop).
 *   - Federation stamp via Rule 8g helper after insert.
 *   - Return `artifact_created` payload so FE auto-switches to Canvas mode (Rule 8g F4).
 *
 * Skill injection (Rule 8h v2): `cc_*` skills synced from templates already provide
 * `tools: [content_creator_execute]` whitelist + `pipeline_json.tool_params.template_id`
 * — Twin Runner reads ctx.skill_instructions and content_agent calls this tool.
 *
 * @since 4.13.6 (Vòng 4.6)
 */

defined( 'ABSPATH' ) || exit;

/* ─────────────────────────────────────────────────────────────
 * Tool 1 — content_creator_execute  (HIL: needs_approval=true)
 * ───────────────────────────────────────────────────────────── */
$content_creator_execute_tool = new BizCity_TwinShell_Tool(
	'content_creator_execute',
	'Tạo nội dung (bài viết, bài bán hàng, kịch bản…) từ template Content Creator. '
	. 'Sử dụng khi user yêu cầu viết bài, soạn nội dung, hoặc khi skill `cc_*` được kích hoạt. '
	. 'Trả về edit_url để mở Canvas Studio xem/chỉnh.',
	[
		'type'       => 'object',
		'properties' => [
			'template_slug' => [
				'type'        => 'string',
				'description' => 'Slug của template (vd "sales_post", "blog_intro"). Dùng list_templates để xem danh sách.',
			],
			'template_id'   => [
				'type'        => 'integer',
				'description' => 'Hoặc dùng template_id thay cho template_slug. Ưu tiên template_slug nếu cả 2 có.',
			],
			'form_data'     => [
				'type'        => 'object',
				'description' => 'Map các slot/biến của template (vd {"product_name":"Samsung Tab S10","tone":"casual"}). Key tham chiếu placeholder {{...}} trong system_prompt của template.',
				'additionalProperties' => true,
			],
			'title'         => [
				'type'        => 'string',
				'description' => 'Tiêu đề tuỳ chọn cho file. Mặc định = template.title + ngày giờ.',
			],
		],
		'required'   => [ 'form_data' ],
	],
	function ( array $args, array $ctx ) {
		if ( ! class_exists( 'BZCC_Template_Manager' ) || ! class_exists( 'BZCC_File_Manager' ) ) {
			return [ 'ok' => false, 'error' => 'plugin_missing', 'message' => 'bizcity-content-creator chưa active.' ];
		}

		// ── Resolve template ──
		$template = null;
		$slug     = isset( $args['template_slug'] ) ? trim( (string) $args['template_slug'] ) : '';
		$tpl_id   = isset( $args['template_id'] )   ? (int) $args['template_id']             : 0;
		if ( $slug !== '' ) {
			$template = BZCC_Template_Manager::get_by_slug( $slug );
		}
		if ( ! $template && $tpl_id > 0 ) {
			$template = BZCC_Template_Manager::get_by_id( $tpl_id );
		}
		if ( ! $template ) {
			return [
				'ok'    => false,
				'error' => 'template_not_found',
				'hint'  => 'Gọi list_templates trước để xem template_slug khả dụng.',
			];
		}
		if ( ( $template->status ?? '' ) !== 'active' ) {
			return [ 'ok' => false, 'error' => 'template_inactive', 'slug' => $template->slug ];
		}

		// ── Sanitize form_data ──
		$form_data = isset( $args['form_data'] ) && is_array( $args['form_data'] ) ? $args['form_data'] : [];
		$clean     = [];
		foreach ( $form_data as $k => $v ) {
			$k = sanitize_key( (string) $k );
			if ( $k === '' || $k === 'template_id' ) continue;
			if ( is_array( $v ) ) {
				$clean[ $k ] = array_map( 'sanitize_text_field', $v );
			} else {
				$clean[ $k ] = sanitize_textarea_field( (string) $v );
			}
		}

		// ── Build title ──
		$title = isset( $args['title'] ) && trim( (string) $args['title'] ) !== ''
			? sanitize_text_field( (string) $args['title'] )
			: ( ( $template->title ?? $template->slug ) . ' — ' . wp_date( 'd/m/Y H:i' ) );

		// ── Insert pending file row ──
		$user_id = (int) ( $ctx['user_id'] ?? get_current_user_id() );
		$file_id = BZCC_File_Manager::insert( [
			'user_id'     => $user_id,
			'template_id' => (int) $template->id,
			'form_data'   => wp_json_encode( $clean ),
			'title'       => $title,
			'status'      => 'pending',
			'session_id'  => isset( $ctx['session_id'] ) ? (string) $ctx['session_id'] : '',
		] );
		if ( ! $file_id ) {
			return [ 'ok' => false, 'error' => 'insert_failed' ];
		}

		// ── Increment template use count ──
		if ( method_exists( 'BZCC_Template_Manager', 'increment_use_count' ) ) {
			BZCC_Template_Manager::increment_use_count( (int) $template->id );
		}

		// ── Federation stamp (Rule 8g v2 — JSON map on kg_notebooks) ──
		$nb_id    = isset( $ctx['notebook_id'] ) ? (int) $ctx['notebook_id'] : 0;
		$edit_url = home_url( '/creator/result/' . $file_id . '/' );
		if ( class_exists( 'BizCity_Artifact_Source_Federation' ) ) {
			BizCity_Artifact_Source_Federation::stamp(
				'bizcity-content-creator',
				(int) $file_id,
				$nb_id,
				(string) $title,
				$edit_url
			);
		}

		// ── Kick generation pipeline (in-process REST, no HTTP loop) ──
		// generate_file checks current_user_id() — must temporarily set if running in cron/queue.
		// In chat ctx we already have user via Twin Runner auth.
		$gen_status = 'queued';
		$gen_error  = '';
		if ( class_exists( 'BZCC_Rest_API' ) && method_exists( 'BZCC_Rest_API', 'generate_file' ) ) {
			$req = new WP_REST_Request( 'POST', '/bzcc/v1/file/generate' );
			$req->set_param( 'id', (int) $file_id );
			try {
				$resp = BZCC_Rest_API::generate_file( $req );
				$data = $resp instanceof WP_REST_Response ? $resp->get_data() : $resp;
				if ( is_array( $data ) && isset( $data['error'] ) ) {
					$gen_status = 'error';
					$gen_error  = (string) $data['error'];
				} else {
					$gen_status = 'generating';
				}
			} catch ( \Throwable $e ) {
				$gen_status = 'error';
				$gen_error  = $e->getMessage();
				error_log( '[content_agent] generate_file threw: ' . $e->getMessage() );
			}
		}

		// $edit_url already computed above for federation stamp.

		return [
			'ok'           => true,
			'file_id'      => (int) $file_id,
			'template'     => [
				'id'    => (int) $template->id,
				'slug'  => (string) $template->slug,
				'title' => (string) ( $template->title ?? $template->slug ),
			],
			'title'        => $title,
			'edit_url'     => $edit_url,
			'gen_status'   => $gen_status,
			'gen_error'    => $gen_error,
			'sse_endpoint' => rest_url( 'bzcc/v1/file/' . $file_id . '/stream' ),
			// Rule 8g F4 — FE auto Knowledge → Canvas mode.
			'artifact_created' => class_exists( 'BizCity_Artifact_Source_Federation' )
				? BizCity_Artifact_Source_Federation::make_artifact_created(
					'bizcity-content-creator', (int) $file_id, $title, $edit_url, $nb_id
				)
				: [
					'plugin_name' => 'bizcity-content-creator',
					'studio_id'   => (int) $file_id,
					'title'       => $title,
					'edit_url'    => $edit_url,
				],
		];
	},
	null,
	true, /* needs_approval — writes to wp_1258_bzcc_files + spends LLM credit */
	'producer' /* R-MPRT-12 — emits artifact_created + render_to_passages via BZCC_Persona_Provider */
);

/* ─────────────────────────────────────────────────────────────
 * Tool 2 — list_templates  (read-only, no approval)
 * ───────────────────────────────────────────────────────────── */
$list_templates_tool = new BizCity_TwinShell_Tool(
	'list_templates',
	'Liệt kê các Content Creator template đang active. Dùng khi user hỏi "có template gì?", "tôi có thể tạo loại nội dung nào?" hoặc trước khi gọi content_creator_execute mà chưa biết slug.',
	[
		'type'       => 'object',
		'properties' => [
			'category_id' => [
				'type'        => 'integer',
				'description' => 'Optional — filter theo category_id. 0 hoặc bỏ qua = tất cả.',
			],
			'limit'       => [
				'type'        => 'integer',
				'description' => 'Số lượng tối đa trả về. Mặc định 30, max 100.',
			],
		],
		'required'   => [],
	],
	function ( array $args, array $ctx ) {
		if ( ! class_exists( 'BZCC_Template_Manager' ) ) {
			return [ 'ok' => false, 'error' => 'plugin_missing' ];
		}
		$cat_id = isset( $args['category_id'] ) ? (int) $args['category_id'] : 0;
		$limit  = isset( $args['limit'] )       ? max( 1, min( 100, (int) $args['limit'] ) ) : 30;

		$rows = $cat_id > 0
			? BZCC_Template_Manager::get_by_category( $cat_id, 'active' )
			: BZCC_Template_Manager::get_all_active();

		$out = [];
		foreach ( $rows as $r ) {
			if ( count( $out ) >= $limit ) break;
			$out[] = [
				'template_id'   => (int)    ( $r->id ?? 0 ),
				'template_slug' => (string) ( $r->slug ?? '' ),
				'title'         => (string) ( $r->title ?? '' ),
				'description'   => mb_substr( (string) ( $r->description ?? '' ), 0, 200 ),
				'category_id'   => (int)    ( $r->category_id ?? 0 ),
				'tags'          => (string) ( $r->tags ?? '' ),
			];
		}
		return [ 'ok' => true, 'count' => count( $out ), 'templates' => $out ];
	}
);

/* ─────────────────────────────────────────────────────────────
 * Register agent
 * ───────────────────────────────────────────────────────────── */
add_filter( 'bizcity_register_agent', function ( array $agents ) use (
	$content_creator_execute_tool,
	$list_templates_tool
): array {
	$agents['content'] = new BizCity_TwinShell_Agent(
		'content',
		"You are the Content Agent. You produce real content artifacts using the BizCity Content Creator plugin (templates + LLM pipeline).\n\n"
		. "RULES:\n"
		. "1. NEVER write content prose yourself — ALWAYS go through `content_creator_execute`.\n"
		. "2. If the user did not specify a template, call `list_templates` first and either pick the best match or ask the user to choose.\n"
		. "3. Each template has a system_prompt with `{{slot_name}}` placeholders — fill `form_data` with values inferred from the user's request. Ask for missing required slots before calling.\n"
		. "4. After `content_creator_execute` returns, present the `edit_url` so the user can open Canvas Studio to view/edit. Mention that generation is async (SSE stream populates the result page).\n"
		. "5. If `gen_status='error'`, surface `gen_error` clearly and suggest retry.\n"
		. "6. When invoked via a `cc_*` skill (Rule 8h v2 injection), the skill body provides default `template_id` — honor it unless the user overrides.",
		[ $content_creator_execute_tool, $list_templates_tool ]
	);
	return $agents;
} );
