<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Agents\Bootstrap
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 *
 * Phase 0.15 / Vòng 2 — Mindmap agent.
 *
 * Demo agent that combines:
 *   • Prompt-based tool calling (Vòng 2 runner)
 *   • HIL approval gate (build_mindmap tool requires approval)
 *
 * Flow:
 *   1. User: "vẽ mindmap về dinh dưỡng"
 *   2. LLM emits tool call: {"tool":"build_mindmap","args":{"topic":"dinh dưỡng"}}
 *   3. Runner pauses with interruption → FE shows Approve/Reject card
 *   4. User approves → tool runs → second LLM turn formats Mermaid → final_output
 *
 * @since 1.13.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tool: build_mindmap
 *
 * Generates a Mermaid `mindmap` block from a topic + optional sub-topics.
 * Vòng 2 MVP — uses a second LLM call internally to expand the topic into
 * a hierarchy. Returns the raw Mermaid source so the FE can render it.
 */
$build_mindmap_tool = new BizCity_TwinShell_Tool(
	'build_mindmap',
	'Generate a Mermaid mindmap diagram for a given topic. Use this whenever the user asks to visualize, draw, sketch, or build a mindmap / mind map / sơ đồ tư duy. Argument "topic" is required.',
	[
		'type'       => 'object',
		'properties' => [
			'topic' => [
				'type'        => 'string',
				'description' => 'The central topic of the mindmap (Vietnamese or English).',
			],
			'depth' => [
				'type'        => 'integer',
				'description' => 'Optional max depth (1-3). Default 2.',
				'minimum'     => 1,
				'maximum'     => 3,
				'default'     => 2,
			],
		],
		'required'   => [ 'topic' ],
	],
	// execute_callback — Vòng 4.5.5d v2: use BZDoc_Notebook_Bridge so we get the
	// INSTANT "blank doc + autogen URL" handoff. The bzdoc FE iframe runs the
	// LLM internally — TwinChat tool returns in <1s with a real iframe URL,
	// not a 60-180s sync LLM round-trip that risks Cloudflare 524.
	function ( array $args, array $ctx ) {
		$topic = isset( $args['topic'] ) ? trim( (string) $args['topic'] ) : '';
		if ( $topic === '' ) {
			return [ 'ok' => false, 'error' => 'missing_topic' ];
		}

		if ( ! class_exists( 'BZDoc_Notebook_Bridge' ) || ! method_exists( 'BZDoc_Notebook_Bridge', 'generate_from_skeleton_public' ) ) {
			return [ 'ok' => false, 'error' => 'bzdoc_bridge_unavailable', 'message' => 'Plugin BizCity Doc Studio chưa sẵn sàng (notebook bridge missing).' ];
		}

		// PHASE-6.4 Wave C3 (May 2026) — honour FE Auto-start checkbox like
		// the doc & image agents. Default ON → instant kickstart in iframe.
		$doc_opts  = isset( $ctx['doc_opts'] ) && is_array( $ctx['doc_opts'] ) ? $ctx['doc_opts'] : [];
		$kickstart = array_key_exists( 'kickstart', $doc_opts ) ? (bool) $doc_opts['kickstart'] : true;

		$nb_id = isset( $ctx['notebook_id'] ) ? (int) $ctx['notebook_id'] : 0;
		$skeleton = [
			'nucleus' => [
				'title'  => $topic,
				'thesis' => '',
				'domain' => '',
			],
			'project_id' => $nb_id > 0 ? ( 'tc_' . $nb_id ) : '',
			'_raw_text'  => $topic,
			'_kickstart' => $kickstart,
		];

		$result = BZDoc_Notebook_Bridge::generate_from_skeleton_public( $skeleton, 'mindmap' );
		if ( is_wp_error( $result ) ) {
			return [ 'ok' => false, 'error' => $result->get_error_code(), 'message' => $result->get_error_message() ];
		}
		if ( ! is_array( $result ) || empty( $result['data']['doc_id'] ) ) {
			return [ 'ok' => false, 'error' => 'bridge_failed', 'raw' => $result ];
		}

		$doc_id   = (int) $result['data']['doc_id'];
		$edit_url = (string) ( $result['data']['url'] ?? home_url( '/tool-doc/?id=' . $doc_id . '&autogen=1' ) );
		$title    = (string) ( $result['title'] ?? ( 'Mindmap: ' . $topic ) );

		if ( $nb_id > 0 && class_exists( 'BizCity_Artifact_Source_Federation' ) ) {
			BizCity_Artifact_Source_Federation::stamp( 'bizcity-doc', $doc_id, $nb_id, $title, $edit_url );
		}

		return [
			'ok'        => true,
			'doc_id'    => $doc_id,
			'doc_type'  => 'mindmap',
			'topic'     => $topic,
			'title'     => $title,
			'edit_url'  => $edit_url,
			// Rule 8g F4 — FE auto-opens iframe in Canvas.
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
	null,        // is_enabled — always
	// PHASE-6.4 Wave C3 (May 2026) — conditional HIL, parity with doc/image.
	function ( array $args, array $ctx ): bool {
		$opts = isset( $ctx['doc_opts'] ) && is_array( $ctx['doc_opts'] ) ? $ctx['doc_opts'] : [];
		$kickstart = array_key_exists( 'kickstart', $opts ) ? (bool) $opts['kickstart'] : true;
		return ! $kickstart;
	}
);

/**
 * Mindmap Agent
 *
 * Instructions tell the LLM:
 *   • Detect mindmap requests → call build_mindmap tool
 *   • After tool returns → format the final response as a Mermaid fenced block
 */
add_filter( 'bizcity_register_agent', function ( array $agents ) use ( $build_mindmap_tool ): array {
	$agents['mindmap'] = new BizCity_TwinShell_Agent(
		'mindmap',
		// System instructions
		"You are the Mindmap Agent. Your job is to create a real mindmap document via the build_mindmap tool.\n\n"
		. "RULES:\n"
		. "1. When the user asks for a mindmap on ANY topic (\"vẽ mindmap\", \"draw mindmap\", \"sơ đồ tư duy\"), call build_mindmap with the topic.\n"
		. "2. After the tool returns successfully, reply ONE short Vietnamese sentence telling the user the mindmap is ready in the Canvas (e.g. \"Đã tạo mindmap về <topic> — bạn xem ở khung Canvas bên phải.\"). Do NOT include any mermaid code or JSON.\n"
		. "3. If the tool fails, briefly apologise and explain.\n"
		. "4. NEVER write Mermaid code yourself.",
		[ $build_mindmap_tool ],
		[],   // no handoffs
		null  // default model
	);
	return $agents;
} );
