<?php
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
	 * Detect skill archetype from frontmatter.
	 *
	 * A = Knowledge-only (no tools)
	 * B = Single-tool
	 * C = Multi-tool workflow
	 *
	 * @param array $frontmatter Parsed YAML frontmatter.
	 * @return string 'A'|'B'|'C'
	 */
	public static function detect_archetype( array $frontmatter ): string {
		// Explicit frontmatter declaration takes priority
		$explicit = strtoupper( trim( $frontmatter['archetype'] ?? '' ) );
		if ( in_array( $explicit, [ 'A', 'B', 'C' ], true ) ) {
			return $explicit;
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
			return $prompt;
		}

		// Separate matches by archetype
		$inject_matches   = []; // A + B → inject into prompt
		$pipeline_matches = []; // C → fire pipeline action

		foreach ( $matches as $m ) {
			$archetype = self::detect_archetype( $m['frontmatter'] );
			$m['archetype'] = $archetype;

			if ( $archetype === 'C' ) {
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
		$block .= "Dưới đây là hướng dẫn kỹ năng phù hợp với ngữ cảnh hiện tại. Hãy tuân thủ các bước và guardrails.\n\n";

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
		}

		return $prompt . $block;
	}
}
