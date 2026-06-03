<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Agents\Bootstrap
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 *
 * Phase 0.13 / Vòng 4.5 — Task 4.5.1
 * Doc Agent — drafts a real document by calling BZDoc Studio (`bizcity-doc`).
 *
 * Vòng 4.5 Tier 3 cutover: replace stub LLM with REAL plugin wiring.
 *   • Tool `draft_document` → BZDoc_Rest_API::handle_generate (writes wp_1258_bzdoc_documents)
 *   • Tool `list_documents` → BZDoc_Rest_API::handle_list (read-only)
 *   • HIL: draft_document needs_approval=true (DB write).
 *
 * @since 4.13.5 (Vòng 4.5)
 */

defined( 'ABSPATH' ) || exit;

/* ─────────────────────────────────────────────────────────────
 * Internal helper — call BZDoc REST handler in-process (no HTTP).
 * Sets BZDoc_Rest_API::$force_direct_llm = true to avoid
 * self-referencing HTTP requests (Apache/PHP-FPM 503 trap).
 * ───────────────────────────────────────────────────────────── */
$bzdoc_call = static function ( string $method, string $route, array $params = [] ) {
	if ( ! class_exists( 'BZDoc_Rest_API' ) ) {
		return new WP_Error( 'bzdoc_not_installed', 'BizCity Doc Studio plugin is not active.' );
	}
	$req = new WP_REST_Request( $method, $route );
	foreach ( $params as $k => $v ) {
		$req->set_param( $k, $v );
	}
	BZDoc_Rest_API::$force_direct_llm = true;
	try {
		switch ( $route ) {
			case '/bzdoc/v1/generate': $resp = BZDoc_Rest_API::handle_generate( $req ); break;
			case '/bzdoc/v1/list':     $resp = BZDoc_Rest_API::handle_list( $req );     break;
			default:
				return new WP_Error( 'bzdoc_unknown_route', 'Unsupported BZDoc route: ' . $route );
		}
	} finally {
		BZDoc_Rest_API::$force_direct_llm = false;
	}
	if ( is_wp_error( $resp ) ) return $resp;
	if ( $resp instanceof WP_REST_Response ) return $resp->get_data();
	return $resp;
};

/* ─────────────────────────────────────────────────────────────
 * Tool 1 — draft_document  (instant handoff via BZDoc_Notebook_Bridge)
 *
 * PHASE-6.4 Wave C3 (May 2026) — mirror image / mindmap pattern:
 *   • Calls BZDoc_Notebook_Bridge::generate_from_skeleton_public() which
 *     returns in <1s with a blank doc + autogen URL. The bzdoc iframe runs
 *     the heavy LLM internally, so TwinChat doesn't risk a 524 timeout and
 *     the Canvas can swap to the iframe immediately.
 *   • Reads FE-supplied `doc_opts` from `$ctx` (template_slug, style,
 *     slide_count, kickstart) so user choices in StudioBuilderTab actually
 *     reach the doc app.
 *   • Returns artifact_created → FE auto-switches Knowledge → Canvas.
 *   • needs_approval is conditional: only TRUE when kickstart=false (user
 *     explicitly unticked Auto-start → wants to review the form before fire).
 *     Default (kickstart=true) → no HIL, instant handoff, parity with image.
 * ───────────────────────────────────────────────────────────── */
$draft_document_tool = new BizCity_TwinShell_Tool(
	'draft_document',
	'Create a real document/presentation/spreadsheet via BizCity Doc Studio. Returns an editable iframe URL that opens in the Canvas. Use whenever the user asks to write, draft, generate, or create a document, article, report, slide deck, or spreadsheet.',
	[
		'type'       => 'object',
		'properties' => [
			'topic'         => [
				'type'        => 'string',
				'description' => 'The main topic or prompt for the document.',
			],
			'doc_type'      => [
				'type'        => 'string',
				'enum'        => [ 'document', 'presentation', 'spreadsheet' ],
				'description' => 'Kind of artifact to produce. Default = document.',
			],
			'template_name' => [
				'type'        => 'string',
				'description' => 'Optional template slug (e.g. blank, invoice, resume, report, proposal, contract, meeting_notes). Default = blank.',
			],
			'theme_name'    => [
				'type'        => 'string',
				'description' => 'Optional visual theme (modern, classic, professional, creative, minimal). Default = modern.',
			],
			'slide_count'   => [
				'type'        => 'integer',
				'description' => 'Slide count for presentation doc_type (default 10).',
			],
		],
		'required'   => [ 'topic' ],
	],
	function ( array $args, array $ctx ) {
		$topic = isset( $args['topic'] ) ? trim( (string) $args['topic'] ) : '';
		if ( $topic === '' ) {
			return [ 'ok' => false, 'error' => 'missing_topic' ];
		}
		$doc_type = isset( $args['doc_type'] ) ? (string) $args['doc_type'] : 'document';
		if ( ! in_array( $doc_type, [ 'document', 'presentation', 'spreadsheet' ], true ) ) {
			$doc_type = 'document';
		}

		// PHASE-6.4 Wave C3 — FE truth wins over LLM args. StudioBuilderTab
		// forwards user selections via context_overrides.doc_opts.
		$doc_opts  = isset( $ctx['doc_opts'] ) && is_array( $ctx['doc_opts'] ) ? $ctx['doc_opts'] : [];
		$kickstart = array_key_exists( 'kickstart', $doc_opts ) ? (bool) $doc_opts['kickstart'] : true;

		$template = '';
		foreach ( [ 'template_slug', 'template' ] as $k ) {
			if ( ! empty( $doc_opts[ $k ] ) && is_string( $doc_opts[ $k ] ) ) { $template = $doc_opts[ $k ]; break; }
		}
		if ( $template === '' && ! empty( $args['template_name'] ) ) {
			$template = (string) $args['template_name'];
		}
		if ( $template === '' ) $template = 'blank';

		$theme = '';
		if ( ! empty( $doc_opts['style'] ) && is_string( $doc_opts['style'] ) ) {
			$theme = strtolower( $doc_opts['style'] );
		}
		if ( $theme === '' && ! empty( $args['theme_name'] ) ) {
			$theme = (string) $args['theme_name'];
		}
		if ( $theme === '' ) $theme = 'modern';

		$slide_count = 0;
		if ( $doc_type === 'presentation' ) {
			$slide_count = (int) ( $doc_opts['slide_count'] ?? $args['slide_count'] ?? 10 );
			if ( $slide_count < 3 )  $slide_count = 3;
			if ( $slide_count > 50 ) $slide_count = 50;
		}

		$nb_id = isset( $ctx['notebook_id'] ) ? (int) $ctx['notebook_id'] : 0;

		if ( ! class_exists( 'BZDoc_Notebook_Bridge' ) || ! method_exists( 'BZDoc_Notebook_Bridge', 'generate_from_skeleton_public' ) ) {
			return [ 'ok' => false, 'error' => 'bzdoc_bridge_unavailable', 'message' => 'BizCity Doc Studio notebook bridge is missing.' ];
		}

		$skeleton = [
			'nucleus' => [
				'title'  => $topic,
				'thesis' => '',
				'domain' => '',
			],
			'project_id' => $nb_id > 0 ? ( 'tc_' . $nb_id ) : '',
			'_raw_text'  => $topic,
			'_kickstart' => $kickstart,
			'doc_opts'   => [
				'template'         => $template,
				'theme'            => $theme,
				'slide_count'      => $slide_count,
				// PHASE-6.4 Wave C5 (May 2026) — split-two passthrough.
				'split_two'        => array_key_exists( 'split_two', $doc_opts ) ? (bool) $doc_opts['split_two'] : false,
				// PHASE-6.4 Wave C6 (May 2026) — parallel batches (2 or 3).
				'parallel_batches' => isset( $doc_opts['parallel_batches'] ) ? absint( $doc_opts['parallel_batches'] ) : 0,
			],
		];

		$result = BZDoc_Notebook_Bridge::generate_from_skeleton_public( $skeleton, $doc_type );
		if ( is_wp_error( $result ) ) {
			return [ 'ok' => false, 'error' => $result->get_error_code(), 'message' => $result->get_error_message() ];
		}
		if ( ! is_array( $result ) || empty( $result['data']['doc_id'] ) ) {
			return [ 'ok' => false, 'error' => 'bridge_failed', 'raw' => $result ];
		}

		$doc_id   = (int) $result['data']['doc_id'];
		$edit_url = (string) ( $result['data']['url'] ?? home_url( '/tool-doc/?id=' . $doc_id . '&autogen=1' . ( $kickstart ? '&kickstart=1' : '' ) ) );
		$title    = (string) ( $result['title'] ?? $topic );

		if ( $nb_id > 0 && class_exists( 'BizCity_Artifact_Source_Federation' ) ) {
			BizCity_Artifact_Source_Federation::stamp( 'bizcity-doc', $doc_id, $nb_id, $title, $edit_url );
		}

		return [
			'ok'        => true,
			'doc_id'    => $doc_id,
			'doc_type'  => $doc_type,
			'title'     => $title,
			'edit_url'  => $edit_url,
			// Rule 8g F4 — FE auto-switch Knowledge → Canvas mode.
			'artifact_created' => class_exists( 'BizCity_Artifact_Source_Federation' )
				? BizCity_Artifact_Source_Federation::make_artifact_created( 'bizcity-doc', $doc_id, $title, $edit_url, $nb_id )
				: [
					'plugin_name' => 'bizcity-doc',
					'studio_id'   => $doc_id,
					'title'       => $title,
					'edit_url'    => $edit_url,
				],
		];
	},
	null, // is_enabled — always
	// PHASE-6.4 Wave C3 — conditional HIL: only ask for approval when the
	// user UNticked Auto-start. The happy path (kickstart=true, default)
	// skips HIL so the Canvas opens immediately, parity with image agent.
	function ( array $args, array $ctx ): bool {
		$opts = isset( $ctx['doc_opts'] ) && is_array( $ctx['doc_opts'] ) ? $ctx['doc_opts'] : [];
		$kickstart = array_key_exists( 'kickstart', $opts ) ? (bool) $opts['kickstart'] : true;
		return ! $kickstart;
	}
);

/* ─────────────────────────────────────────────────────────────
 * Tool 2 — list_documents (read-only, no approval)
 * ───────────────────────────────────────────────────────────── */
$list_documents_tool = new BizCity_TwinShell_Tool(
	'list_documents',
	'List the current user\'s recent documents from BizCity Doc Studio. Use when the user asks "show my documents", "list reports", or wants to find/resume an existing document.',
	[
		'type'       => 'object',
		'properties' => [
			'doc_type' => [
				'type'        => 'string',
				'enum'        => [ '', 'document', 'presentation', 'spreadsheet' ],
				'description' => 'Optional filter by doc type. Empty = all.',
			],
		],
		'required'   => [],
	],
	function ( array $args, array $ctx ) use ( $bzdoc_call ) {
		$payload = [];
		if ( ! empty( $args['doc_type'] ) ) {
			$payload['doc_type'] = (string) $args['doc_type'];
		}
		$rows = $bzdoc_call( 'GET', '/bzdoc/v1/list', $payload );
		if ( is_wp_error( $rows ) ) {
			return [
				'ok'      => false,
				'error'   => $rows->get_error_code(),
				'message' => $rows->get_error_message(),
			];
		}
		$rows = is_array( $rows ) ? $rows : [];
		$out  = [];
		foreach ( $rows as $r ) {
			$rid = is_object( $r ) ? (int) ( $r->id ?? 0 ) : (int) ( $r['id'] ?? 0 );
			if ( $rid <= 0 ) continue;
			$out[] = [
				'doc_id'     => $rid,
				'doc_type'   => is_object( $r ) ? (string) ( $r->doc_type ?? '' )   : (string) ( $r['doc_type'] ?? '' ),
				'title'      => is_object( $r ) ? (string) ( $r->title ?? '' )      : (string) ( $r['title'] ?? '' ),
				'updated_at' => is_object( $r ) ? (string) ( $r->updated_at ?? '' ) : (string) ( $r['updated_at'] ?? '' ),
				'edit_url'   => home_url( '/tool-doc/?id=' . $rid ),
			];
		}
		return [ 'ok' => true, 'count' => count( $out ), 'documents' => $out ];
	}
);

/* ─────────────────────────────────────────────────────────────
 * Agent registration
 * ───────────────────────────────────────────────────────────── */
add_filter( 'bizcity_register_agent', function ( array $agents ) use ( $draft_document_tool, $list_documents_tool ): array {
	$agents['doc'] = new BizCity_TwinShell_Agent(
		'doc',
		"You are the Doc Agent. You help the user create and manage real documents in BizCity Doc Studio.\n\n"
		. "RULES:\n"
		. "1. To CREATE a new document, presentation, or spreadsheet, ALWAYS call the `draft_document` tool. Never write the content yourself in chat.\n"
		. "2. To LIST or FIND existing documents, call `list_documents`.\n"
		. "3. Pick `doc_type` from {document, presentation, spreadsheet} based on intent — slides/PowerPoint → presentation, table/Excel → spreadsheet, otherwise document.\n"
		. "4. After `draft_document` returns, reply with ONE short Vietnamese sentence telling the user the doc is ready in the Canvas (e.g. \"Đã tạo <doc_type> \"<title>\" — mở khung Canvas bên phải để xem.\"). Do NOT paste the URL or repeat the prompt.\n"
		. "5. If a tool returns `ok=false` with `error=bzdoc_bridge_unavailable` or `bzdoc_not_installed`, tell the user that Doc Studio plugin is not active.",
		[ $draft_document_tool, $list_documents_tool ]
	);
	return $agents;
} );
