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
 * Tool 1 — draft_document  (HIL: needs_approval=true)
 * ───────────────────────────────────────────────────────────── */
$draft_document_tool = new BizCity_TwinShell_Tool(
	'draft_document',
	'Create a real document/presentation/spreadsheet via BizCity Doc Studio. Persists to the user\'s document library and returns an editable link. Use whenever the user asks to write, draft, generate, or create a document, article, report, slide deck, or spreadsheet.',
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
	function ( array $args, array $ctx ) use ( $bzdoc_call ) {
		$topic = isset( $args['topic'] ) ? trim( (string) $args['topic'] ) : '';
		if ( $topic === '' ) {
			return [ 'ok' => false, 'error' => 'missing_topic' ];
		}
		$doc_type = isset( $args['doc_type'] ) ? (string) $args['doc_type'] : 'document';
		if ( ! in_array( $doc_type, [ 'document', 'presentation', 'spreadsheet' ], true ) ) {
			$doc_type = 'document';
		}
		$payload = [
			'topic'         => $topic,
			'doc_type'      => $doc_type,
			'template_name' => isset( $args['template_name'] ) ? (string) $args['template_name'] : 'blank',
			'theme_name'    => isset( $args['theme_name'] )    ? (string) $args['theme_name']    : 'modern',
		];
		if ( $doc_type === 'presentation' && isset( $args['slide_count'] ) ) {
			$payload['slide_count'] = (int) $args['slide_count'];
		}

		$result = $bzdoc_call( 'POST', '/bzdoc/v1/generate', $payload );
		if ( is_wp_error( $result ) ) {
			return [
				'ok'      => false,
				'error'   => $result->get_error_code(),
				'message' => $result->get_error_message(),
			];
		}
		if ( empty( $result['success'] ) || empty( $result['doc_id'] ) ) {
			return [ 'ok' => false, 'error' => 'bzdoc_failed', 'raw' => $result ];
		}

		$doc_id   = (int) $result['doc_id'];
		$schema   = isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : [];
		$title    = $schema['metadata']['title']
			?? $schema['presentation_title']
			?? $schema['title']
			?? $topic;
		$edit_url = home_url( '/tool-doc/?id=' . $doc_id );

		// Vòng 4.5.0.a — Universal Federation Contract (Rule 8g F1).
		// Stamp kg_sources nếu có notebook context — backstop cho Wave 7
		// (notebook-bridge đã stamp khi user bind notebook qua picker).
		$nb_id = isset( $ctx['notebook_id'] ) ? (int) $ctx['notebook_id'] : 0;
		if ( $nb_id > 0 && class_exists( 'BizCity_Artifact_Source_Federation' ) ) {
			BizCity_Artifact_Source_Federation::stamp( 'bizcity-doc', $doc_id, $nb_id, (string) $title, $edit_url );
		}

		return [
			'ok'        => true,
			'doc_id'    => $doc_id,
			'doc_type'  => $doc_type,
			'title'     => (string) $title,
			'edit_url'  => $edit_url,
			'gen_id'    => isset( $result['gen_id'] ) ? (int) $result['gen_id'] : 0,
			// Rule 8g F4 — FE auto-switch Knowledge → Canvas mode.
			'artifact_created' => class_exists( 'BizCity_Artifact_Source_Federation' )
				? BizCity_Artifact_Source_Federation::make_artifact_created( 'bizcity-doc', $doc_id, (string) $title, $edit_url, $nb_id )
				: [
					'plugin_name' => 'bizcity-doc',
					'studio_id'   => $doc_id,
					'title'       => (string) $title,
					'edit_url'    => $edit_url,
				],
		];
	},
	null,
	true /* needs_approval — writes to wp_1258_bzdoc_documents */
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
		. "4. After `draft_document` returns, present the result like:\n"
		. "   ✅ Đã tạo tài liệu \"<title>\". Mở để chỉnh sửa: <edit_url>\n"
		. "5. Tools that write to the database require human approval before executing — that is normal, do not retry.\n"
		. "6. If a tool returns `ok=false` with `error=bzdoc_not_installed`, tell the user that Doc Studio plugin is not active.",
		[ $draft_document_tool, $list_documents_tool ]
	);
	return $agents;
} );
