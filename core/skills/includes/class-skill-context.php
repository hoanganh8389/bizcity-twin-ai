<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Skills
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * BizCity Skill Context — Injects matching skill instructions into system prompt
 *
 * Hooks at priority 93 on bizcity_chat_system_prompt
 * (after Context Builder at 90, before Companion at 97).
 *
 * @package  BizCity_Skills
 * @since    2026-03-31
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Skill_Context {

	private static $instance = null;

	public static function instance(): self {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct() {
		add_filter( 'bizcity_chat_system_prompt', [ $this, 'inject_skill_context' ], 93, 2 );
	}

	/**
	 * Phase 0.19.6.2 — Resolve the best skill for a single turn.
	 *
	 * Pure function for callers that need the matched skill BEFORE the system prompt
	 * is built (e.g. TwinChat stream handler injecting per-skill tools into the
	 * agent loop's allowed_tools). Does not mutate any global state.
	 *
	 * @param int         $notebook_id
	 * @param int         $character_id
	 * @param int         $user_id
	 * @param string      $message        Raw user message for this turn.
	 * @param string|null $slash_command  Optional explicit "/cmd" override.
	 * @return array|null  {
	 *     skill_id, skill_key, title, slash_command, archetype, score,
	 *     tools (string[]), frontmatter, content_excerpt
	 * }
	 */
	public function resolve_for_turn(
		int $notebook_id,
		int $character_id,
		int $user_id,
		string $message,
		?string $slash_command = null
	): ?array {
		if ( ! class_exists( 'BizCity_Skill_Database' ) ) {
			return null;
		}

		// Auto-detect slash from message if not provided
		if ( ! $slash_command && preg_match( '/^\s*\/([a-z0-9_]+)/i', $message, $sm ) ) {
			$slash_command = '/' . strtolower( $sm[1] );
		}

		$db    = BizCity_Skill_Database::instance();
		$match = $db->find_matching( [
			'character_id'  => $character_id,
			'user_id'       => $user_id,
			'slash_command' => (string) $slash_command,
			'message'       => $message,
			'limit'         => 1,
		] );

		if ( ! $match || empty( $match['id'] ) ) {
			return null;
		}

		$frontmatter = [
			'title'          => $match['title'] ?? '',
			'category'       => $match['category'] ?? 'general',
			'triggers'       => json_decode( $match['triggers_json'] ?? '[]', true ) ?: [],
			'tools'          => json_decode( $match['tools_json']    ?? '[]', true ) ?: [],
			'slash_commands' => array_filter( array_map( 'trim', explode( ',', $match['slash_commands'] ?? '' ) ) ),
			'modes'          => array_filter( array_map( 'trim', explode( ',', $match['modes'] ?? '' ) ) ),
			'priority'       => (int) ( $match['priority'] ?? 50 ),
			'output_format'  => $match['output_format'] ?? '',
		];

		// Merge frontmatter.tools + body @tool_refs (Phase 0.19 §6 contract)
		$tools = (array) $frontmatter['tools'];
		if ( class_exists( 'BizCity_Skill_Recipe_Parser' ) ) {
			$parsed = BizCity_Skill_Recipe_Parser::instance()->parse(
				$match['content'] ?? '',
				$frontmatter
			);
			if ( ! empty( $parsed['tool_refs'] ) ) {
				$tools = array_merge( $tools, (array) $parsed['tool_refs'] );
			}
		}
		$tools = array_values( array_unique( array_filter( array_map( 'strval', $tools ) ) ) );

		return [
			'skill_id'        => (int) $match['id'],
			'skill_key'       => (string) ( $match['skill_key'] ?? '' ),
			'title'           => (string) $frontmatter['title'],
			'slash_command'   => (string) $slash_command,
			'archetype'       => self::detect_archetype( $frontmatter ),
			'score'           => (float) ( $match['_score'] ?? 0 ),
			'tools'           => $tools,
			'frontmatter'     => $frontmatter,
			'content_excerpt' => mb_substr( (string) ( $match['content'] ?? '' ), 0, 240 ),
		];
	}

	/**
	 * Detect skill archetype from frontmatter.
	 *
	 * A = Knowledge-only (no tools)
	 * B = Single-tool
	 * C = Multi-tool workflow
	 * D = Agentic execution_plan (5-step pipeline with it_call_* blocks)
	 *
	 * @param array $frontmatter Parsed YAML frontmatter.
	 * @return string 'A'|'B'|'C'|'D'
	 */
	public static function detect_archetype( array $frontmatter ): string {
		// Explicit frontmatter declaration takes priority
		$explicit = strtoupper( trim( $frontmatter['archetype'] ?? '' ) );
		if ( in_array( $explicit, [ 'A', 'B', 'C', 'D' ], true ) ) {
			return $explicit;
		}

		// Auto-detect: steps[] in frontmatter → archetype D (multi-step agentic pipeline)
		if ( ! empty( $frontmatter['steps'] ) && is_array( $frontmatter['steps'] ) ) {
			return 'D';
		}

		// Auto-detect from tools[]
		$tools = array_merge(
			(array) ( $frontmatter['tools'] ?? [] ),
			(array) ( $frontmatter['related_tools'] ?? [] )
		);
		$tools = array_unique( array_filter( $tools ) );

		// B4 fix: output_format: json_workflow → always archetype C (pipeline)
		if ( ( $frontmatter['output_format'] ?? '' ) === 'json_workflow' ) {
			return 'C';
		}

		if ( empty( $tools ) ) {
			return 'A';
		}
		if ( count( $tools ) === 1 ) {
			return 'B';
		}
		return 'C';
	}

	/**
	 * Filter callback — append matched skill instructions to system prompt.
	 * Routes by archetype: A/B → inject prompt, C → fire pipeline action.
	 */
	public function inject_skill_context( string $prompt, array $args ): string {
		// Gate check
		if ( class_exists( 'BizCity_Focus_Gate' ) && ! BizCity_Focus_Gate::should_inject( 'skill' ) ) {
			return $prompt;
		}

		// Skip if Step 1.6E already activated this skill (avoids dual A→compose + C→pipeline)
		if ( ! empty( $GLOBALS['_bizcity_s16e_handled_skill'] ) ) {
			return $prompt;
		}

		$mgr = BizCity_Skill_Manager::instance();

		// Build match criteria
		$mode    = $args['mode'] ?? '';
		$message = $args['message'] ?? '';
		$engine  = $args['engine_result'] ?? [];
		$goal    = $engine['meta']['goal'] ?? $engine['goal'] ?? '';
		// B6 fix: $tool should be goal/intent_key (e.g. 'write_article'), NOT action
		// (action = 'ask_user'/'complete'/'passthrough' which never matches skill tools)
		$tool    = $goal ?: ( $engine['meta']['intent_key'] ?? '' );

		// Extract slash command from message or engine result
		$slash_command = '';
		if ( ! empty( $engine['meta']['slash_command'] ) ) {
			$slash_command = $engine['meta']['slash_command'];
		} elseif ( preg_match( '/^\s*\/([a-z_]+)/i', $message, $sm ) ) {
			$slash_command = '/' . strtolower( $sm[1] );
		}
		// If goal looks like a slash tool name, use it as slash_command too
		if ( ! $slash_command && $goal && preg_match( '/^[a-z_]+$/', $goal ) ) {
			$slash_command = '/' . $goal;
		}

		$matches = $mgr->find_matching( [
			'mode'          => $mode,
			'goal'          => $goal,
			'tool'          => $tool,
			'message'       => $message,
			'slash_command' => $slash_command,
			'limit'         => 3,
		] );

		if ( empty( $matches ) ) {
			error_log( '[SKILL_CONTEXT] inject_skill_context: mode=' . $mode . ' goal=' . $goal . ' tool=' . $tool . ' slash=' . $slash_command . ' → NO MATCH' );
			if ( class_exists( 'BizCity_Twin_Trace' ) ) {
				BizCity_Twin_Trace::gate( 'skill', false, 'no match for mode=' . $mode . ' goal=' . $goal . ' tool=' . $tool );
			}
			return $prompt;
		}

		// Separate matches by archetype
		$inject_matches   = []; // A + B → inject into prompt
		$pipeline_matches = []; // C + D → fire pipeline action

		foreach ( $matches as $m ) {
			$archetype = self::detect_archetype( $m['frontmatter'] );
			$m['archetype'] = $archetype;

			// Upgrade A/B to guided pipeline if body has @tool_refs or ≥2 numbered steps
			if ( in_array( $archetype, [ 'A', 'B' ], true ) && class_exists( 'BizCity_Skill_Recipe_Parser' ) ) {
				$parsed = BizCity_Skill_Recipe_Parser::instance()->parse(
					$m['content'] ?? '',
					$m['frontmatter'] ?? []
				);
				if ( $parsed['strategy'] === 'guided' ) {
					$m['archetype']       = 'D';
					$m['body_steps']      = $parsed['steps'];
					$m['body_tool_refs']  = $parsed['tool_refs'];
					$m['body_guardrails'] = $parsed['guardrails'];
					$pipeline_matches[]   = $m;
					continue;
				}
			}

			if ( $archetype === 'C' || $archetype === 'D' ) {
				$pipeline_matches[] = $m;
			} else {
				$inject_matches[] = $m;
			}
		}

		// Fire pipeline action for archetype C skills (highest score first, one at a time)
		if ( ! empty( $pipeline_matches ) ) {
			$top_c = $pipeline_matches[0];
			/**
			 * Fires when an archetype C skill is matched — triggers pipeline generation.
			 *
			 * @param array $skill     { path, frontmatter, content, score, reasons, archetype }
			 * @param array $args      Original filter args (mode, message, engine_result, etc.)
			 */
			do_action( 'bizcity_skill_trigger_pipeline', $top_c, $args );
		}

		// Inject archetype A/B content into prompt (same as before)
		if ( empty( $inject_matches ) ) {
			return $prompt;
		}

		// Build skill context block
		$block = "\n\n## 📘 Skill Instructions\n";
		$block .= "Dưới đây là hướng dẫn kỹ năng phù hợp với ngữ cảnh hiện tại. Hãy tuân thủ các bước và guardrails.\n";
		$block .= "**QUAN TRỌNG**: Khi trả lời, hãy nêu rõ bạn đang áp dụng skill nào (VD: \"Tôi đang dùng skill [tên skill] để thực hiện...\").\n\n";

		foreach ( $inject_matches as $m ) {
			$content = trim( $m['content'] ?? '' );
			if ( ! $content ) continue;

			$title = $m['frontmatter']['title'] ?? basename( $m['path'], '.md' );
			$block .= "### ⚡ " . esc_html( $title ) . "\n";
			$block .= $content . "\n\n";
		}

		// Trace
		if ( class_exists( 'BizCity_Twin_Trace' ) ) {
			$all = array_merge( $inject_matches, $pipeline_matches );
			$info = array_map( function ( $m ) {
				$a = $m['archetype'] ?? '?';
				return $m['path'] . ' [' . $a . '] (score:' . $m['score'] . ' ' . implode( '+', $m['reasons'] ) . ')';
			}, $all );
			BizCity_Twin_Trace::gate( 'skill', true, 'matched: ' . implode( ', ', $info ) );
			error_log( '[SKILL_CONTEXT] inject_skill_context: mode=' . $mode . ' goal=' . $goal . ' tool=' . $tool . ' matched=' . count( $all ) . ' → ' . implode( ', ', $info ) );
		}

		return $prompt . $block;
	}
}
