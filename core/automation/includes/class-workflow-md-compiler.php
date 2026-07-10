<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Automation
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 *
 * BizCity Workflow MD Compiler — Wave A WF-AUTO W3
 *
 * Round-trip compiler between `.workflow.md` (Markdown + YAML frontmatter +
 * fenced YAML config blocks) and the xyflow `graph_json` shape consumed by
 * `bizcity_automation_workflows.graph_json`.
 *
 * Canonical MD grammar:
 *
 *     ---
 *     archetype: workflow         # Guardrail G1 — REQUIRED
 *     name: Zalo CSKH
 *     slug: tpl_zalo_cskh_v1
 *     description: Khách nhắn Zalo → KG → LLM → reply
 *     trigger_type: zalo_inbound
 *     icon: MessageCircle
 *     tags: zalo,cskh,kg,llm
 *     enabled: false
 *     ---
 *
 *     # <Name>
 *
 *     <Description prose…>
 *
 *     ## Steps
 *
 *     ### 1. `trigger.zalo_inbound` — Zalo · tin nhắn
 *
 *     ```yaml
 *     instance_id: ""
 *     filter: ""
 *     ```
 *
 *     ### 2. `action.search_kg` — Tra cứu KG
 *
 *     ```yaml
 *     query: "{{trigger.text}}"
 *     top_k: 5
 *     ```
 *
 *     ## Edges
 *
 *     - n1 -> n2
 *     - n2 -> n3
 *     - n3 -> n4 [via: yes]
 *
 *     ## Layout
 *
 *     - n1: x=0 y=80
 *     - n2: x=320 y=80
 *
 * Edges section is OPTIONAL — when missing, the compiler stitches a linear
 * chain `n1 → n2 → … → nN`. Layout section is OPTIONAL — when missing,
 * positions default to (i*320, 80).
 *
 * Designed for PHP 7.4 — no union types / match / nullsafe.
 *
 * @since 2026-06-03
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Workflow_MD_Compiler {

	/** @var self|null */
	private static $instance = null;

	/** @var string */
	private const LOG = '[WFCompiler]';

	public static function instance(): self {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/* ================================================================
	 *  Public API — md → workflow
	 * ================================================================ */

	/**
	 * Compile a `.workflow.md` raw string into a workflow row payload
	 * suitable for `BizCity_Automation_Repo_Workflows::create()` /
	 * `update()`.
	 *
	 * @param string $md Raw markdown including frontmatter block.
	 * @return array|WP_Error {
	 *     slug, name, description, trigger_type, tags, icon, enabled,
	 *     trigger_config (array), graph (nodes+edges+meta)
	 * }
	 */
	public function md_to_workflow( string $md ) {
		// [2026-06-03 Johnny Chu] WF-AUTO W3 — md → workflow payload.
		if ( ! class_exists( 'BizCity_Skill_Manager' ) || ! class_exists( 'BizCity_Skill_Recipe_Parser' ) ) {
			return new WP_Error( 'parser_missing', 'BizCity_Skill_Manager / Recipe_Parser chưa load.' );
		}

		$mgr    = BizCity_Skill_Manager::instance();
		$parser = BizCity_Skill_Recipe_Parser::instance();

		$parsed = $mgr->parse_frontmatter( $md );
		$fm     = isset( $parsed['frontmatter'] ) && is_array( $parsed['frontmatter'] ) ? $parsed['frontmatter'] : array();
		$body   = isset( $parsed['content'] ) ? (string) $parsed['content'] : '';

		// Guardrail G1 — archetype discriminator (delegated to parser).
		$gate = $parser->require_archetype( $fm, 'workflow' );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}

		$steps = $parser->extract_workflow_steps( $body );
		if ( empty( $steps ) ) {
			return new WP_Error( 'no_steps', 'Không tìm thấy section `## Steps` hoặc bước nào trong file.' );
		}

		$layout = $this->parse_layout( $body );
		$edges  = $this->parse_edges( $body, $steps );

		// Build xyflow nodes.
		$nodes = array();
		$idx   = 0;
		foreach ( $steps as $step ) {
			$node_id  = (string) $step['id'];
			$block_id = (string) $step['block_id'];
			$label    = (string) $step['label'];
			$config   = is_array( $step['config'] ) ? $step['config'] : array();

			$pos = isset( $layout[ $node_id ] )
				? $layout[ $node_id ]
				: array( 'x' => $idx * 320, 'y' => 80 );

			$data = array_merge( array( 'label' => $label ), $config );
			$data['blockId'] = $block_id;

			$nodes[] = array(
				'id'       => $node_id,
				'type'     => $this->infer_node_type( $block_id ),
				'position' => $pos,
				'data'     => $data,
			);
			$idx++;
		}

		// Linear default edges when none provided.
		if ( empty( $edges ) ) {
			for ( $i = 0; $i < count( $nodes ) - 1; $i++ ) {
				$edges[] = $this->mk_edge( $nodes[ $i ]['id'], $nodes[ $i + 1 ]['id'], '' );
			}
		}

		$trigger_config = isset( $fm['trigger_config'] ) && is_array( $fm['trigger_config'] ) ? $fm['trigger_config'] : array();

		return array(
			'slug'           => isset( $fm['slug'] ) ? (string) $fm['slug'] : '',
			'name'           => isset( $fm['name'] ) ? (string) $fm['name'] : '',
			'description'    => isset( $fm['description'] ) ? (string) $fm['description'] : '',
			'trigger_type'   => isset( $fm['trigger_type'] ) ? (string) $fm['trigger_type'] : 'manual',
			'tags'           => isset( $fm['tags'] ) ? (string) ( is_array( $fm['tags'] ) ? implode( ',', $fm['tags'] ) : $fm['tags'] ) : '',
			'icon'           => isset( $fm['icon'] ) ? (string) $fm['icon'] : 'FileText',
			'enabled'        => ! empty( $fm['enabled'] ) ? 1 : 0,
			'trigger_config' => $trigger_config,
			'graph'          => array(
				'nodes' => $nodes,
				'edges' => $edges,
				'meta'  => array( 'source' => 'workflow_md', 'version' => 1 ),
			),
		);
	}

	/* ================================================================
	 *  Public API — workflow → md (round-trip)
	 * ================================================================ */

	/**
	 * Serialize a workflow row (or compiled payload from md_to_workflow)
	 * back to canonical `.workflow.md` text.
	 *
	 * @param array $wf Workflow array with keys: slug, name, description,
	 *                  trigger_type, tags, icon, enabled, trigger_config,
	 *                  graph{nodes,edges}.
	 * @return string
	 */
	public function workflow_to_md( array $wf ): string {
		// [2026-06-03 Johnny Chu] WF-AUTO W3 — workflow → md round-trip.
		$graph = isset( $wf['graph'] ) && is_array( $wf['graph'] ) ? $wf['graph'] : array();
		$nodes = isset( $graph['nodes'] ) && is_array( $graph['nodes'] ) ? array_values( $graph['nodes'] ) : array();
		$edges = isset( $graph['edges'] ) && is_array( $graph['edges'] ) ? array_values( $graph['edges'] ) : array();

		// Frontmatter (G1 archetype always declared).
		$fm_lines = array( '---', 'archetype: workflow' );
		foreach ( array( 'name', 'slug', 'description', 'trigger_type', 'icon' ) as $k ) {
			if ( isset( $wf[ $k ] ) && $wf[ $k ] !== '' ) {
				$fm_lines[] = $k . ': ' . $this->yaml_inline_scalar( (string) $wf[ $k ] );
			}
		}
		$fm_lines[] = 'tags: ' . $this->yaml_inline_scalar( isset( $wf['tags'] ) ? (string) $wf['tags'] : '' );
		$fm_lines[] = 'enabled: ' . ( ! empty( $wf['enabled'] ) ? 'true' : 'false' );
		$fm_lines[] = '---';

		$out  = implode( "\n", $fm_lines ) . "\n\n";
		$out .= '# ' . ( isset( $wf['name'] ) ? (string) $wf['name'] : 'Workflow' ) . "\n\n";
		if ( ! empty( $wf['description'] ) ) {
			$out .= (string) $wf['description'] . "\n\n";
		}

		// Steps section.
		$out .= "## Steps\n\n";
		$id_to_step = array(); // node_id -> step_no (1-indexed) for edges section.
		$i = 0;
		foreach ( $nodes as $node ) {
			$i++;
			$node_id  = isset( $node['id'] ) ? (string) $node['id'] : ( 'n' . $i );
			$data     = isset( $node['data'] ) && is_array( $node['data'] ) ? $node['data'] : array();
			$block_id = isset( $data['blockId'] ) ? (string) $data['blockId'] : '';
			$label    = isset( $data['label'] ) ? (string) $data['label'] : $block_id;

			$id_to_step[ $node_id ] = $i;

			$out .= '### ' . $i . '. `' . $block_id . '` — ' . $label . "\n\n";

			$config = $data;
			unset( $config['blockId'], $config['label'] );
			$out .= "```yaml\n" . $this->yaml_dump( $config ) . "```\n\n";
		}

		// Edges section — only emit when graph is non-linear.
		$is_linear = $this->is_linear_chain( $nodes, $edges );
		if ( ! $is_linear && ! empty( $edges ) ) {
			$out .= "## Edges\n\n";
			foreach ( $edges as $edge ) {
				$src = isset( $edge['source'] ) ? (string) $edge['source'] : '';
				$tgt = isset( $edge['target'] ) ? (string) $edge['target'] : '';
				$h   = isset( $edge['sourceHandle'] ) ? (string) $edge['sourceHandle'] : '';
				$line = '- ' . $src . ' -> ' . $tgt;
				if ( $h !== '' ) {
					$line .= ' [via: ' . $h . ']';
				}
				$out .= $line . "\n";
			}
			$out .= "\n";
		}

		// Layout section — always emit (cheap, makes round-trip lossless).
		$out .= "## Layout\n\n";
		foreach ( $nodes as $node ) {
			$node_id = isset( $node['id'] ) ? (string) $node['id'] : '';
			$pos     = isset( $node['position'] ) && is_array( $node['position'] ) ? $node['position'] : array( 'x' => 0, 'y' => 0 );
			$out .= '- ' . $node_id . ': x=' . (int) ( $pos['x'] ?? 0 ) . ' y=' . (int) ( $pos['y'] ?? 0 ) . "\n";
		}

		return $out;
	}

	/* ================================================================
	 *  Public API — validate
	 * ================================================================ */

	/**
	 * Validate a `.workflow.md` without committing to DB.
	 *
	 * @param string $md
	 * @return true|WP_Error
	 */
	public function validate_md( string $md ) {
		$compiled = $this->md_to_workflow( $md );
		if ( is_wp_error( $compiled ) ) {
			return $compiled;
		}
		if ( empty( $compiled['slug'] ) ) {
			return new WP_Error( 'slug_missing', 'Frontmatter thiếu `slug`.' );
		}
		if ( empty( $compiled['name'] ) ) {
			return new WP_Error( 'name_missing', 'Frontmatter thiếu `name`.' );
		}
		$graph = isset( $compiled['graph'] ) && is_array( $compiled['graph'] ) ? $compiled['graph'] : array();
		$nodes = isset( $graph['nodes'] ) ? $graph['nodes'] : array();
		if ( empty( $nodes ) ) {
			return new WP_Error( 'no_nodes', 'Graph rỗng sau khi compile.' );
		}
		// Sanity: every edge source/target must reference an existing node id.
		$ids = array();
		foreach ( $nodes as $n ) {
			if ( isset( $n['id'] ) ) {
				$ids[ (string) $n['id'] ] = true;
			}
		}
		$edges = isset( $graph['edges'] ) ? $graph['edges'] : array();
		foreach ( $edges as $e ) {
			$src = isset( $e['source'] ) ? (string) $e['source'] : '';
			$tgt = isset( $e['target'] ) ? (string) $e['target'] : '';
			if ( ! isset( $ids[ $src ] ) || ! isset( $ids[ $tgt ] ) ) {
				return new WP_Error(
					'edge_orphan',
					sprintf( 'Edge `%s -> %s` tham chiếu node không tồn tại.', $src, $tgt )
				);
			}
		}
		return true;
	}

	/* ================================================================
	 *  Internal — parsing helpers
	 * ================================================================ */

	/**
	 * Parse `## Layout` section into id → {x,y}.
	 *
	 * @return array<string, array{x:int,y:int}>
	 */
	private function parse_layout( string $body ): array {
		$out = array();
		if ( ! preg_match( '/^##\s+Layout[^\n]*\n((?:.|\n)*?)(?=^##\s|\z)/imu', $body, $m ) ) {
			return $out;
		}
		$lines = explode( "\n", (string) $m[1] );
		foreach ( $lines as $line ) {
			if ( preg_match( '/^\s*-\s+([A-Za-z0-9_\-]+)\s*:\s*x\s*=\s*(-?\d+)\s+y\s*=\s*(-?\d+)/i', $line, $hit ) ) {
				$out[ $hit[1] ] = array( 'x' => (int) $hit[2], 'y' => (int) $hit[3] );
			}
		}
		return $out;
	}

	/**
	 * Parse `## Edges` section. Returns [] when absent (caller falls back
	 * to linear chain).
	 *
	 * @param array $steps Output of extract_workflow_steps() for id validation.
	 * @return array
	 */
	private function parse_edges( string $body, array $steps ): array {
		$out = array();
		if ( ! preg_match( '/^##\s+Edges[^\n]*\n((?:.|\n)*?)(?=^##\s|\z)/imu', $body, $m ) ) {
			return $out;
		}
		$valid_ids = array();
		foreach ( $steps as $s ) {
			$valid_ids[ (string) $s['id'] ] = true;
		}
		$lines = explode( "\n", (string) $m[1] );
		foreach ( $lines as $line ) {
			if ( preg_match( '/^\s*-\s+([A-Za-z0-9_\-]+)\s*->\s*([A-Za-z0-9_\-]+)(?:\s*\[via:\s*([A-Za-z0-9_\-]+)\s*\])?/i', $line, $hit ) ) {
				$src = $hit[1];
				$tgt = $hit[2];
				$h   = isset( $hit[3] ) ? $hit[3] : '';
				if ( isset( $valid_ids[ $src ] ) && isset( $valid_ids[ $tgt ] ) ) {
					$out[] = $this->mk_edge( $src, $tgt, $h );
				}
			}
		}
		return $out;
	}

	/**
	 * Edge factory matching the xyflow shape used in seeder.
	 */
	private function mk_edge( string $src, string $tgt, string $handle = '' ): array {
		$edge = array(
			'id'     => 'e_' . $src . '_' . $tgt . ( $handle !== '' ? '_' . $handle : '' ),
			'source' => $src,
			'target' => $tgt,
		);
		if ( $handle !== '' ) {
			$edge['sourceHandle'] = $handle;
		}
		return $edge;
	}

	/**
	 * Detect linear chain n1→n2→…→nN (one outgoing edge per non-leaf,
	 * sequential order, no handle). Used to skip emitting `## Edges`.
	 */
	private function is_linear_chain( array $nodes, array $edges ): bool {
		$n = count( $nodes );
		if ( $n === 0 ) {
			return true;
		}
		if ( count( $edges ) !== $n - 1 ) {
			return false;
		}
		for ( $i = 0; $i < $n - 1; $i++ ) {
			$src_expected = isset( $nodes[ $i ]['id'] ) ? (string) $nodes[ $i ]['id'] : '';
			$tgt_expected = isset( $nodes[ $i + 1 ]['id'] ) ? (string) $nodes[ $i + 1 ]['id'] : '';
			$edge         = $edges[ $i ];
			$src          = isset( $edge['source'] ) ? (string) $edge['source'] : '';
			$tgt          = isset( $edge['target'] ) ? (string) $edge['target'] : '';
			$has_handle   = ! empty( $edge['sourceHandle'] );
			if ( $src !== $src_expected || $tgt !== $tgt_expected || $has_handle ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Infer xyflow `type` from block_id prefix.
	 *  trigger.*  → trigger | llm.* → llm | logic.* → logic | else → action
	 */
	private function infer_node_type( string $block_id ): string {
		if ( strpos( $block_id, 'trigger.' ) === 0 ) { return 'trigger'; }
		if ( strpos( $block_id, 'llm.' ) === 0 )     { return 'llm'; }
		if ( strpos( $block_id, 'logic.' ) === 0 )   { return 'logic'; }
		return 'action';
	}

	/* ================================================================
	 *  Internal — YAML emitter (subset matching parser)
	 * ================================================================ */

	/**
	 * Serialize a flat assoc array to YAML-subset text (matches parser).
	 *
	 * @param array $data
	 * @return string
	 */
	private function yaml_dump( array $data ): string {
		if ( empty( $data ) ) {
			return "";
		}
		$out = '';
		foreach ( $data as $k => $v ) {
			if ( ! preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $k ) ) {
				continue; // skip keys parser would not recognise
			}
			if ( is_bool( $v ) ) {
				$out .= $k . ': ' . ( $v ? 'true' : 'false' ) . "\n";
				continue;
			}
			if ( $v === null ) {
				$out .= $k . ": null\n";
				continue;
			}
			if ( is_int( $v ) || is_float( $v ) ) {
				$out .= $k . ': ' . $v . "\n";
				continue;
			}
			if ( is_array( $v ) ) {
				// Nested array — JSON-encode as inline string (parser will
				// receive scalar; caller can re-decode if needed).
				$out .= $k . ': ' . $this->yaml_inline_scalar( wp_json_encode( $v ) ) . "\n";
				continue;
			}
			$s = (string) $v;
			if ( strpos( $s, "\n" ) !== false ) {
				$out .= $k . ": |\n";
				foreach ( explode( "\n", $s ) as $ln ) {
					$out .= '  ' . $ln . "\n";
				}
				continue;
			}
			$out .= $k . ': ' . $this->yaml_inline_scalar( $s ) . "\n";
		}
		return $out;
	}

	/**
	 * Quote scalar when ambiguous (contains : # leading-dash etc).
	 */
	private function yaml_inline_scalar( string $s ): string {
		if ( $s === '' ) {
			return '""';
		}
		// Quote if contains YAML-significant chars or could be parsed as bool/null/int.
		$lower = strtolower( $s );
		$needs_quote = (bool) preg_match( '/[:#\[\]\{\},&*!|>\'"%@`]/', $s )
			|| $lower === 'true' || $lower === 'false' || $lower === 'null' || $lower === '~'
			|| preg_match( '/^-?\d+$/', $s )
			|| $s[0] === ' ' || substr( $s, -1 ) === ' '
			|| $s[0] === '-';
		if ( $needs_quote ) {
			return '"' . str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), $s ) . '"';
		}
		return $s;
	}
}
