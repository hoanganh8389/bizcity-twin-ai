<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Intent
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * BizCity Tool Run — Unified Tool Execution Service
 *
 * Phase 1.1e: Single execution path for ALL tool calls.
 * Replaces the dual-path problem where Intent Engine (sync) and
 * WAIC it_call_tool (async) had separate execution logic.
 *
 * Both callers now invoke BizCity_Tool_Run::execute() which provides:
 *   1. Skill Resolution  — find matching skill via BizCity_Skill_Manager
 *   2. Context Assembly   — build _meta (6-layer context + skill + manifest)
 *   3. Execution          — BizCity_Intent_Tools::execute()
 *   4. Verification       — post-execution result checks
 *   5. Logging            — [TOOL-RUN] prefix for unified tracing
 *
 * Callers add their own layer:
 *   - Intent Engine: confirm flow, auto-execute, emotional smoothing, goal switch
 *   - it_call_tool:  HIL slot-fill, pipeline todo, BFS orchestration
 *
 * @package BizCity_Intent
 * @since   4.1.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Tool_Run {

	/**
	 * Execute a tool through the unified pipeline.
	 *
	 * @param string $tool_id   Tool identifier (e.g. 'write_article').
	 * @param array  $params    Input parameters for the tool.
	 * @param array  $context   Execution context {
	 *   @type string $session_id    Chat session ID.
	 *   @type int    $user_id       WordPress user ID.
	 *   @type string $channel       webchat|adminchat|telegram|zalo.
	 *   @type string $conv_id       Intent conversation UUID.
	 *   @type string $goal          Goal identifier (e.g. 'create_product').
	 *   @type string $goal_label    Human-readable goal label.
	 *   @type string $character_id  AI character binding.
	 *   @type string $message_id    Original user message ID.
	 *   @type string $caller        'intent_engine' | 'it_call_tool' — for log tracing.
	 * }
	 * @return array {
	 *   @type bool        $success        Whether tool execution succeeded.
	 *   @type string      $message        Human-readable result message.
	 *   @type array       $data           Tool output data.
	 *   @type array       $missing_fields Fields the tool reports as still missing.
	 *   @type array|null  $skill          Matched skill info { title, content, path } or null.
	 *   @type float       $duration_ms    Execution time in milliseconds.
	 *   @type bool        $verified       Whether post-execution verification passed.
	 *   @type string      $invoke_id      Execution Logger invoke ID.
	 * }
	 */
	public static function execute( string $tool_id, array $params, array $context = [] ): array {
		$caller     = $context['caller'] ?? 'unknown';
		$session_id = $context['session_id'] ?? '';
		$user_id    = (int) ( $context['user_id'] ?? get_current_user_id() );
		$channel    = $context['channel'] ?? '';
		$conv_id    = $context['conv_id'] ?? '';
		$goal       = $context['goal'] ?? '';
		$goal_label = $context['goal_label'] ?? '';
		$char_id    = $context['character_id'] ?? '';
		$message_id = $context['message_id'] ?? '';

		$run_start = microtime( true );

		error_log( "[TOOL-RUN] ═══ START tool={$tool_id} caller={$caller} goal={$goal} session={$session_id} user={$user_id}" );

		// ── 0. Tool registry check ──
		if ( ! class_exists( 'BizCity_Intent_Tools' ) ) {
			error_log( '[TOOL-RUN] ABORT: BizCity_Intent_Tools not available' );
			return self::make_result( false, 'Intent Tools not available.', [], $tool_id, $run_start );
		}

		$tools = BizCity_Intent_Tools::instance();
		if ( ! $tools->has( $tool_id ) ) {
			error_log( "[TOOL-RUN] ABORT: tool '{$tool_id}' not found in registry" );
			return self::make_result( false, "Tool '{$tool_id}' không được tìm thấy.", [], $tool_id, $run_start );
		}

		// ── 1. Skill Resolution (via Resource Resolver if available) ──
		// ── 2. Context Assembly (_meta) — Phase 1.9: Resource Resolver injects full bundle ──

		$resource_bundle = null;
		$skill_info      = null;

		// Check for skill override from upstream pipeline node (e.g. it_todos_planner → it_call_content)
		if ( ! empty( $context['skill_override'] ) && is_array( $context['skill_override'] ) ) {
			$skill_info = $context['skill_override'];
			error_log( '[TOOL-RUN] E1_skill tool=' . $tool_id . ' found=' . ( $skill_info['title'] ?? 'override' ) . ' (upstream override)' );
		} elseif ( class_exists( 'BizCity_Resource_Resolver' ) ) {
			// Phase 1.9: Unified resource resolution.
			$resource_bundle = BizCity_Resource_Resolver::resolve( $tool_id, [
				'session_id'   => $session_id,
				'user_id'      => $user_id,
				'message'      => $params['topic'] ?? $params['message'] ?? '',
				'project_id'   => $context['project_id'] ?? '',
				'character_id' => $char_id,
			] );
			$skill_info = $resource_bundle['skill'];
		} else {
			// Fallback: legacy skill resolution.
			$skill_info = self::resolve_skill( $tool_id, $user_id );
		}

		error_log( '[TOOL-RUN] E1_skill tool=' . $tool_id . ' found=' . ( $skill_info ? $skill_info['title'] : 'NONE' )
			. ( $resource_bundle ? ' profile=' . $resource_bundle['profile'] : ' (legacy)' ) );

		$tool_context_str = '';
		if ( class_exists( 'BizCity_Context_Builder' ) ) {
			$tool_context_str = BizCity_Context_Builder::instance()->build_tool_context();
		}

		$tool_manifest_str = '';
		if ( class_exists( 'BizCity_Intent_Tool_Index' ) ) {
			$tool_manifest_str = BizCity_Intent_Tool_Index::instance()->build_tools_context( 800 );
		}

		$tool_user = wp_get_current_user();
		$meta = [
			'_context'        => $tool_context_str,
			'_tools_manifest' => $tool_manifest_str,
			'conv_id'         => $conv_id,
			'goal'            => $goal,
			'goal_label'      => $goal_label,
			'character_id'    => $char_id,
			'channel'         => $channel,
			'message_id'      => $message_id,
			'user_display'    => $tool_user->ID ? ( $tool_user->display_name ?: $tool_user->user_login ) : 'Guest',
			'blog_id'         => get_current_blog_id(),
		];

		// Inject skill content into _meta so tool callbacks and LLM can use it.
		if ( $skill_info ) {
			$meta['_skill'] = [
				'title'   => $skill_info['title'],
				'content' => $skill_info['content'],
				'path'    => $skill_info['path'] ?? '',
			];
		}

		// Phase 1.9: Inject resource bundle layers into _meta.
		if ( $resource_bundle ) {
			$meta['_session_spec'] = $resource_bundle['session_spec'];
			$meta['_notes']        = $resource_bundle['notes'];
			$meta['_sources']      = $resource_bundle['sources'];
			$meta['_knowledge']    = $resource_bundle['knowledge'];
			$meta['_resource_profile'] = $resource_bundle['profile'];
		}

		$params['_meta'] = $meta;

		// Ensure basic context fields exist in params
		if ( ! isset( $params['session_id'] ) && $session_id ) {
			$params['session_id'] = $session_id;
		}
		if ( ! isset( $params['user_id'] ) && $user_id ) {
			$params['user_id'] = $user_id;
		}
		if ( ! isset( $params['platform'] ) && $channel ) {
			$params['platform'] = strtoupper( $channel );
		}

		$context_len = strlen( $tool_context_str );
		$manifest_len = strlen( $tool_manifest_str );
		error_log( "[TOOL-RUN] E2_context context_len={$context_len} manifest_len={$manifest_len} skill=" . ( $skill_info ? 'yes' : 'no' ) );

		// ── 3. Execute via Execution Logger + Intent Tools ──
		$invoke_id = '';
		if ( class_exists( 'BizCity_Execution_Logger' ) ) {
			$invoke_id = BizCity_Execution_Logger::tool_invoke(
				$tool_id,
				$params,
				$tools->get_tool_source( $tool_id )
			);
		}

		$tool_start  = microtime( true );
		$tool_result = $tools->execute( $tool_id, $params );
		$tool_duration = round( ( microtime( true ) - $tool_start ) * 1000, 2 );

		if ( class_exists( 'BizCity_Execution_Logger' ) ) {
			BizCity_Execution_Logger::tool_result( $invoke_id, $tool_id, $tool_result, $tool_duration );
		}

		$success = ! empty( $tool_result['success'] );
		$message = $tool_result['message'] ?? '';
		$data    = $tool_result['data'] ?? [];
		$missing = $tool_result['missing_fields'] ?? [];

		error_log( "[TOOL-RUN] E3_execute tool={$tool_id} success=" . ( $success ? 'YES' : 'NO' )
			. " duration={$tool_duration}ms missing=" . ( $missing ? implode( ',', $missing ) : 'none' ) );

		// ── 4. Post-execution Verification ──
		$verified = true;
		if ( $success && empty( $missing ) ) {
			$verified = self::verify_result( $tool_id, $tool_result );
			if ( ! $verified ) {
				error_log( "[TOOL-RUN] E4_verify FAILED tool={$tool_id} — overriding success to false" );
				$success = false;
				$message = $message ?: 'Tool result failed post-execution verification.';
			} else {
				error_log( "[TOOL-RUN] E4_verify PASSED tool={$tool_id}" );
			}
		}

		// ── 5. Summary log + execution event ──
		$total_ms = round( ( microtime( true ) - $run_start ) * 1000, 2 );
		error_log( "[TOOL-RUN] ═══ END tool={$tool_id} success=" . ( $success ? 'YES' : 'NO' )
			. " verified=" . ( $verified ? 'YES' : 'NO' )
			. " total={$total_ms}ms caller={$caller}" );

		// Phase 1.9: Emit domain event for listeners (evidence, artifact, trace).
		do_action( 'bizcity_tool_execution_completed', [
			'tool_id'     => $tool_id,
			'success'     => $success,
			'verified'    => $verified,
			'data'        => $data,
			'message'     => $message,
			'skill'       => $skill_info,
			'caller'      => $caller,
			'session_id'  => $session_id,
			'user_id'     => $user_id,
			'channel'     => $channel,
			'duration_ms' => $total_ms,
			'invoke_id'   => $invoke_id,
			'resource_bundle' => $resource_bundle,
		] );

		// Build result — merge raw tool result keys (e.g. switch_goal, complete)
		// so callers can access tool-specific response fields transparently.
		$run_result = $tool_result; // preserve all raw keys from tool callback
		$run_result['success']        = $success;   // may be overridden by verify
		$run_result['message']        = $message;
		$run_result['data']           = $data;
		$run_result['missing_fields'] = $missing;
		$run_result['skill']          = $skill_info;
		$run_result['skill_used']     = $skill_info ? $skill_info['title'] : 'none';
		$run_result['skill_resolve']  = $skill_info
			? ( $skill_info['_resolve_method'] ?? 'unknown' ) . ':' . ( $skill_info['title'] ?? '' )
			: 'not_found:tool=' . $tool_id . ',user=' . $user_id;
		$run_result['duration_ms']    = $tool_duration;
		$run_result['verified']       = $verified;
		$run_result['invoke_id']      = $invoke_id;

		return $run_result;
	}

	/* ================================================================
	 *  Skill Resolution
	 * ================================================================ */

	/**
	 * Find matching skill for a tool.
	 *
	 * Phase 1.4b: Checks accepts_skill flag before querying.
	 * Phase 1.4e: Uses skill_tool_map for direct mapping when available.
	 *
	 * @param string $tool_id Tool name.
	 * @param int    $user_id User ID for personalized skill resolution (0=global).
	 * @return array|null { title, content, path } or null.
	 */
	public static function resolve_skill( string $tool_id, int $user_id = 0 ): ?array {
		// ── Phase 1.4b: Check accepts_skill BEFORE finding skill ──
		if ( class_exists( 'BizCity_Intent_Tool_Index' ) ) {
			$tool_meta = BizCity_Intent_Tool_Index::instance()->get_tool_by_name( $tool_id );
			if ( $tool_meta && empty( $tool_meta->accepts_skill ) ) {
				error_log( "[TOOL-RUN] resolve_skill: tool={$tool_id} accepts_skill=false, SKIP" );
				return null;
			}
		}

		// ── Phase 1.4e: Try skill_tool_map first (direct mapping) ──
		if ( class_exists( 'BizCity_Skill_Tool_Map' ) ) {
			$map   = BizCity_Skill_Tool_Map::instance();
			$match = $map->resolve_skill_for_tool( $tool_id, $user_id );
			if ( $match ) {
				error_log( "[TOOL-RUN] resolve_skill: tool={$tool_id} → map hit: {$match['title']}" );
				return [
					'id'             => $match['id'],
					'title'          => $match['title'],
					'content'        => $match['content'],
					'path'           => 'map://' . $tool_id,
					'_resolve_method' => 'skill_tool_map',
				];
			}
		}

		// ── Fallback: BizCity_Skill_Manager text-based matching ──
		if ( ! class_exists( 'BizCity_Skill_Manager' ) ) {
			error_log( '[TOOL-RUN] skill_resolve: BizCity_Skill_Manager not available' );
			return null;
		}

		$mgr     = BizCity_Skill_Manager::instance();
		$matches = $mgr->find_matching( [
			'tool'          => $tool_id,
			'slash_command' => '/' . $tool_id,
			'user_id'       => $user_id,
			'limit'         => 1,
		] );

		if ( empty( $matches ) ) {
			return null;
		}

		$m     = $matches[0];
		$title = $m['frontmatter']['title'] ?? basename( $m['path'] ?? '', '.md' );

		return [
			'title'          => $title,
			'content'        => $m['content'] ?? '',
			'path'           => $m['path'] ?? '',
			'_resolve_method' => 'text_matching',
		];
	}

	/* ================================================================
	 *  Post-Execution Verification
	 * ================================================================ */

	/**
	 * Verify tool result after execution.
	 *
	 * Checks:
	 *   - Resource existence (if numeric ID returned)
	 *   - URL validity (if URL returned)
	 *
	 * @param string $tool_id     Tool identifier.
	 * @param array  $result_data Tool execution result.
	 * @return bool True if verification passed.
	 */
	public static function verify_result( string $tool_id, array $result_data ): bool {
		if ( empty( $result_data['success'] ) ) {
			return false;
		}

		$data = $result_data['data'] ?? [];

		// Verify resource exists if numeric ID is provided
		if ( ! empty( $data['id'] ) && is_numeric( $data['id'] ) ) {
			$post = get_post( (int) $data['id'] );
			if ( ! $post || $post->post_status === 'trash' ) {
				error_log( '[TOOL-RUN] verify_FAIL: tool=' . $tool_id . ' resource id=' . $data['id'] . ' not found or trashed' );
				return false;
			}
		}

		// Verify URL format if provided
		if ( ! empty( $data['url'] ) && is_string( $data['url'] ) ) {
			if ( ! filter_var( $data['url'], FILTER_VALIDATE_URL ) ) {
				error_log( '[TOOL-RUN] verify_FAIL: tool=' . $tool_id . ' invalid URL=' . $data['url'] );
				return false;
			}
		}

		return true;
	}

	/* ================================================================
	 *  Internal Helpers
	 * ================================================================ */

	/**
	 * Build a standardized result array (for early-exit cases).
	 */
	private static function make_result( bool $success, string $message, array $data, string $tool_id, float $start_time ): array {
		return [
			'success'        => $success,
			'message'        => $message,
			'data'           => $data,
			'missing_fields' => [],
			'skill'          => null,
			'duration_ms'    => round( ( microtime( true ) - $start_time ) * 1000, 2 ),
			'verified'       => false,
			'invoke_id'      => '',
		];
	}
}
