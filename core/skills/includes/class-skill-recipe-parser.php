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
 * BizCity Skill Recipe Parser — Phase 1.11 S4
 *
 * Analyzes skill markdown body + frontmatter to detect strategy:
 *   - simple:   0 @tool_ref AND < 2 numbered steps → inject prompt only
 *   - guided:   1+ @tool_ref OR 2+ numbered steps   → auto-generate pipeline
 *   - explicit: frontmatter has steps:[]             → Pipeline Bridge (Archetype D)
 *
 * Extracts:
 *   - @tool_refs   from body (e.g. "@deep_research", "@generate_blog_content")
 *   - steps        numbered top-level lines (1. 2. 3.) — full text preserved
 *   - guardrails   bullet list from "❗ Guardrails" section
 *
 * Used by BizCity_Skill_Context to upgrade A/B archetypes that have
 * workflow signals in their body to full pipeline execution (guided strategy).
 *
 * Performance: regex-only, ~1-2ms. Zero LLM calls.
 *
 * @package  BizCity_Skills
 * @since    2026-04-06
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Skill_Recipe_Parser {

	/** @var self|null */
	private static $instance = null;

	/** @var string */
	private const LOG = '[RecipeParser]';

	public static function instance(): self {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/* ================================================================
	 *  Main entry point
	 * ================================================================ */

	/**
	 * Full parse — analyze body + frontmatter and return recipe data.
	 *
	 * @param string $body        Skill markdown body (without frontmatter block).
	 * @param array  $frontmatter Parsed YAML frontmatter array.
	 * @return array {
	 *   strategy:   'simple'|'guided'|'explicit',
	 *   tool_refs:  string[]  Tool names without @ prefix.
	 *   steps:      string[]  Full numbered step texts (as-is from body).
	 *   guardrails: string[]  Guardrail bullet texts.
	 * }
	 */
	public function parse( string $body, array $frontmatter ): array {
		$tool_refs  = $this->extract_tool_refs( $body );

		// Merge frontmatter 'tools' declaration (authoritative for DB-synced virtual skills)
		$fm_tools = (array) ( $frontmatter['tools'] ?? [] );
		if ( ! empty( $fm_tools ) ) {
			error_log( self::LOG . ' tool_refs merge: body=[' . implode( ',', $tool_refs ) . '] + fm_tools=[' . implode( ',', $fm_tools ) . ']' );
			$tool_refs = array_values( array_unique( array_merge( $tool_refs, $fm_tools ) ) );
		}

		$steps      = $this->extract_numbered_steps( $body );
		$guardrails = $this->extract_guardrails( $body );
		$strategy   = $this->detect_strategy( $frontmatter, $tool_refs, $steps );

		error_log( self::LOG . ' parse: strategy=' . $strategy
			. ' tool_refs=[' . implode( ',', $tool_refs ) . ']'
			. ' steps=' . count( $steps )
			. ' guardrails=' . count( $guardrails ) );

		return compact( 'strategy', 'tool_refs', 'steps', 'guardrails' );
	}

	/* ================================================================
	 *  Extraction methods
	 * ================================================================ */

	/**
	 * Extract @tool_reference names from body.
	 * Pattern: @tool_name (starts with letter, alphanumeric + underscore).
	 *
	 * @param string $body
	 * @return string[] Deduplicated tool names without @ prefix.
	 */
	public function extract_tool_refs( string $body ): array {
		preg_match_all( '/@([a-z][a-z0-9_]+)/i', $body, $matches );
		$refs = array_values( array_unique( $matches[1] ?? [] ) );

		// Exclude common markdown/email false positives
		$exclude = [ 'gmail', 'email', 'hotmail', 'yahoo', 'outlook' ];
		return array_values( array_filter( $refs, function ( $r ) use ( $exclude ) {
			return ! in_array( strtolower( $r ), $exclude, true );
		} ) );
	}

	/**
	 * Extract top-level numbered steps from body.
	 * Matches "1. Text", "2. Text" at line start (up to 3 spaces indent allowed).
	 * Preserves full text including @tool_ref mentions.
	 * Stops at the next markdown heading (##).
	 *
	 * @param string $body
	 * @return string[] Step texts.
	 */
	public function extract_numbered_steps( string $body ): array {
		$lines = explode( "\n", $body );
		$steps = [];
		$in_step_section = false;

		foreach ( $lines as $line ) {
			// Stop at a new heading that is NOT the steps/process section
			if ( preg_match( '/^#{1,3}\s+(.+)$/', $line, $hm ) ) {
				$heading_lower = mb_strtolower( trim( $hm[1] ) );
				// Stop collecting if we move to a new non-process section
				if ( $in_step_section && ! preg_match( '/quy trình|bước|step|process|flow/u', $heading_lower ) ) {
					$in_step_section = false;
				}
				// Detect step/process section heading
				if ( preg_match( '/quy trình|bước|step|process|flow|hướng dẫn/u', $heading_lower ) ) {
					$in_step_section = true;
				}
				continue;
			}

			// Match numbered list items: "1. text" or "  2. text"
			if ( preg_match( '/^\s{0,3}(\d+)\.\s+(.+)$/', $line, $m ) ) {
				$text = trim( $m[2] );
				// Skip trivially short steps
				if ( mb_strlen( $text ) >= 8 ) {
					$steps[] = $text;
					$in_step_section = true;
				}
			}
		}

		return $steps;
	}

	/**
	 * Extract guardrail bullet points from "Guardrails" or "❗" sections.
	 *
	 * @param string $body
	 * @return string[]
	 */
	public function extract_guardrails( string $body ): array {
		$guardrails = [];

		// Find the guardrails section
		if ( ! preg_match(
			'/^#{1,3}\s*(?:❗\s*)?(?:Guardrails?|Lưu ý|Ràng buộc|Quy tắc)[^\n]*\n((?:.|\n)*?)(?=^#{1,3}\s|\z)/imu',
			$body,
			$section
		) ) {
			return $guardrails;
		}

		$lines = explode( "\n", $section[1] ?? '' );
		foreach ( $lines as $line ) {
			// Match bullet items: "* text", "- text"
			if ( preg_match( '/^\s*[*\-]\s+(.+)$/', $line, $m ) ) {
				$text = trim( $m[1] );
				if ( $text ) {
					$guardrails[] = $text;
				}
			}
			// Stop at next heading
			if ( preg_match( '/^#{1,3}\s/', $line ) ) {
				break;
			}
		}

		return $guardrails;
	}

	/* ================================================================
	 *  Strategy detection
	 * ================================================================ */

	/**
	 * Detect skill strategy from frontmatter + body analysis.
	 *
	 * Priority order (first match wins):
	 *   1. explicit — frontmatter steps:[] has 1+ entries
	 *   2. guided   — body has 1+ @tool_ref OR 2+ numbered steps
	 *   3. simple   — fallback (prompt injection only)
	 *
	 * @param array    $frontmatter
	 * @param string[] $tool_refs
	 * @param string[] $steps
	 * @return string 'explicit'|'guided'|'simple'
	 */
	public function detect_strategy( array $frontmatter, array $tool_refs, array $steps ): string {
		// Explicit override from frontmatter (e.g. strategy: simple)
		$fm_strategy = $frontmatter['strategy'] ?? '';
		if ( in_array( $fm_strategy, [ 'simple', 'guided', 'explicit' ], true ) ) {
			return $fm_strategy;
		}

		// Explicit: frontmatter steps[] wins regardless of body
		if ( ! empty( $frontmatter['steps'] ) && is_array( $frontmatter['steps'] ) ) {
			return 'explicit';
		}

		// Guided: body has 2+ numbered steps (actual workflow), or @tool_ref in body WITH steps
		// tool_refs alone (from frontmatter['tools']) = routing metadata, NOT a workflow
		if ( count( $steps ) >= 2 ) {
			return 'guided';
		}

		// tool_refs from body @mentions + at least 1 step = guided
		if ( ! empty( $tool_refs ) && count( $steps ) >= 1 ) {
			return 'guided';
		}

		return 'simple';
	}

	/* ================================================================
	 *  Pipeline config extraction (Phase 1.12)
	 * ================================================================ */

	/**
	 * Parse pipeline config from YAML frontmatter.
	 * Extracts chain:, blocks:, and skip_* flags for structured pipeline building.
	 *
	 * @param array $frontmatter Parsed YAML frontmatter.
	 * @return array { chain, blocks, skip_research, skip_planner, skip_memory, skip_reflection }
	 */
	public function parse_pipeline_config( array $frontmatter ): array {
		return [
			'chain'           => (array) ( $frontmatter['chain'] ?? [] ),
			'blocks'          => (array) ( $frontmatter['blocks'] ?? [] ),
			'skip_research'   => ! empty( $frontmatter['skip_research'] ),
			'skip_planner'    => ! empty( $frontmatter['skip_planner'] ),
			'skip_memory'     => ! empty( $frontmatter['skip_memory'] ),
			'skip_reflection' => ! empty( $frontmatter['skip_reflection'] ),
		];
	}

	/* ================================================================
	 *  Workflow MD extraction (WF-AUTO W2 — Wave A)
	 *  [2026-06-03 Johnny Chu] WF-AUTO W2 — extract block-graph steps
	 *  from `.workflow.md` body + G1 archetype guard. Consumed by
	 *  BizCity_Workflow_MD_Compiler (core/automation/includes/).
	 *  KHÔNG dùng cho `.skill.md` (A/B/C — Twin Runner DUY NHẤT).
	 * ================================================================ */

	/**
	 * Validate archetype frontmatter discriminator (Guardrail G1).
	 *
	 * Parser tier MUST reject `.workflow.md` compilation when frontmatter does
	 * not declare `archetype: workflow`. Extension alone is a naming hint, not
	 * a single source of truth. Same rule applies for `.skill.md`
	 * (archetype must be `skill`).
	 *
	 * @param array  $frontmatter Parsed YAML frontmatter.
	 * @param string $expected    'workflow' | 'skill'.
	 * @return true|WP_Error true if archetype matches; WP_Error otherwise.
	 */
	public function require_archetype( array $frontmatter, string $expected ) {
		$raw = isset( $frontmatter['archetype'] ) ? strtolower( trim( (string) $frontmatter['archetype'] ) ) : '';
		if ( $raw === '' ) {
			return new WP_Error(
				'archetype_missing',
				sprintf( 'Frontmatter thiếu field `archetype: %s` (Guardrail G1).', $expected )
			);
		}
		if ( $raw !== strtolower( $expected ) ) {
			return new WP_Error(
				'archetype_mismatch',
				sprintf( 'Frontmatter `archetype: %s` không khớp expected `%s` (Guardrail G1).', $raw, $expected )
			);
		}
		return true;
	}

	/**
	 * Extract block-graph steps from a `.workflow.md` body.
	 *
	 * Grammar (canonical, round-trippable with BizCity_Workflow_MD_Compiler):
	 *
	 *     ## Steps
	 *
	 *     ### 1. `trigger.manual` — Bắt đầu thủ công
	 *
	 *     ```yaml
	 *     label: Bắt đầu
	 *     ```
	 *
	 *     ### 2. `llm.compose_reply` — Soạn câu trả lời
	 *
	 *     ```yaml
	 *     model: gpt-4o-mini
	 *     prompt: |
	 *       Trả lời ngắn gọn theo guru context.
	 *     ```
	 *
	 * Heading pattern: `### <N>. \`<block_id>\` — <label>`
	 *
	 * @param string $body Markdown body WITHOUT frontmatter block.
	 * @return array<int, array{id:string, block_id:string, label:string, config:array}>
	 */
	public function extract_workflow_steps( string $body ): array {
		$out = array();

		// Isolate "## Steps" section (case-insensitive, accept VI variants) — bail if absent.
		if ( ! preg_match(
			'/^##\s+(?:Steps|Quy\s*trình|B[ưu]ớc)[^\n]*\n((?:.|\n)*?)(?=^##\s|\z)/imu',
			$body,
			$section
		) ) {
			return $out;
		}
		$inner = (string) ( $section[1] ?? '' );

		$re = '/^###\s+(\d+)\.\s+`([a-z0-9_.-]+)`\s*(?:[—\-:]\s*)?(.*)$/imu';
		if ( ! preg_match_all( $re, $inner, $matches, PREG_OFFSET_CAPTURE ) ) {
			return $out;
		}

		$count = count( $matches[0] );
		for ( $i = 0; $i < $count; $i++ ) {
			$heading_off = (int) $matches[0][ $i ][1];
			$heading_len = strlen( $matches[0][ $i ][0] );
			$next_off    = ( $i + 1 < $count ) ? (int) $matches[0][ $i + 1 ][1] : strlen( $inner );
			$chunk       = substr( $inner, $heading_off + $heading_len, $next_off - ( $heading_off + $heading_len ) );

			$step_no  = (int) $matches[1][ $i ][0];
			$block_id = trim( (string) $matches[2][ $i ][0] );
			$label    = trim( (string) $matches[3][ $i ][0] );

			$config = $this->extract_yaml_block( $chunk );

			$out[] = array(
				'id'       => 'n' . $step_no,
				'block_id' => $block_id,
				'label'    => $label !== '' ? $label : $block_id,
				'config'   => $config,
			);
		}

		return $out;
	}

	/**
	 * Naive YAML-subset extractor — grabs first ```yaml fenced block.
	 *
	 * Supported subset (PHP 7.4 floor, no `yaml` ext on shared hosting):
	 *   - `key: scalar`
	 *   - `key: |` followed by indented continuation lines (joined with \n).
	 *   - `# comment` lines skipped.
	 *
	 * Anything more complex (nested objects/arrays/anchors) is dropped — the
	 * compiler caller can pre-process with a real YAML lib if needed.
	 *
	 * @param string $chunk Step body chunk after the heading.
	 * @return array<string, mixed>
	 */
	private function extract_yaml_block( string $chunk ): array {
		if ( ! preg_match( '/```ya?ml\s*\n((?:.|\n)*?)\n```/i', $chunk, $m ) ) {
			return array();
		}
		$lines = explode( "\n", (string) $m[1] );
		$out   = array();

		$pending_key   = '';
		$pending_lines = array();

		foreach ( $lines as $raw ) {
			$line = rtrim( $raw, "\r" );

			// Block-scalar continuation: indented line (≥2 spaces) while a pending key is open.
			if ( $pending_key !== '' && $line !== '' && preg_match( '/^(\s{2,})(.*)$/', $line, $cm ) ) {
				$pending_lines[] = $cm[2];
				continue;
			}

			// Flush pending block scalar.
			if ( $pending_key !== '' ) {
				$out[ $pending_key ] = rtrim( implode( "\n", $pending_lines ), "\n" );
				$pending_key   = '';
				$pending_lines = array();
			}

			if ( $line === '' || strpos( ltrim( $line ), '#' ) === 0 ) {
				continue;
			}

			if ( preg_match( '/^([A-Za-z_][A-Za-z0-9_]*)\s*:\s*(.*)$/', $line, $kv ) ) {
				$k = $kv[1];
				$v = $kv[2];

				if ( $v === '|' || $v === '|-' || $v === '>' ) {
					$pending_key   = $k;
					$pending_lines = array();
					continue;
				}

				// Strip surrounding quotes when present.
				if ( strlen( $v ) >= 2
					&& ( ( $v[0] === '"' && substr( $v, -1 ) === '"' )
						|| ( $v[0] === "'" && substr( $v, -1 ) === "'" ) ) ) {
					$v = substr( $v, 1, -1 );
				}

				// Cast trivial scalars.
				$lower = strtolower( $v );
				if ( $lower === 'true' ) {
					$v = true;
				} elseif ( $lower === 'false' ) {
					$v = false;
				} elseif ( $lower === 'null' || $v === '~' ) {
					$v = null;
				} elseif ( preg_match( '/^-?\d+$/', $v ) ) {
					$v = (int) $v;
				}

				$out[ $k ] = $v;
			}
		}

		// Final flush.
		if ( $pending_key !== '' ) {
			$out[ $pending_key ] = rtrim( implode( "\n", $pending_lines ), "\n" );
		}

		return $out;
	}

	/* ================================================================
	 *  Skill context builder (for Data Contract v1 payload)
	 * ================================================================ */

	/**
	 * Build the skill_context field for the server Data Contract payload.
	 * Used by BizCity_Context_Collector when building the request payload.
	 *
	 * @param array $skill  { path, frontmatter, content, score, archetype }
	 * @param array $parsed Output from parse().
	 * @return array skill_context JSON-safe array.
	 */
	public function to_skill_context( array $skill, array $parsed ): array {
		$fm = $skill['frontmatter'] ?? [];
		return [
			'slug'        => $fm['name'] ?? basename( $skill['path'] ?? '', '.md' ),
			'title'       => $fm['title'] ?? '',
			'strategy'    => $parsed['strategy'],
			'modes'       => (array) ( $fm['modes'] ?? [] ),
			'tool_refs'   => $parsed['tool_refs'],
			'guardrails'  => array_slice( $parsed['guardrails'], 0, 10 ), // cap at 10
			'body_excerpt' => mb_substr( $skill['content'] ?? '', 0, 2000 ),
		];
	}
}
