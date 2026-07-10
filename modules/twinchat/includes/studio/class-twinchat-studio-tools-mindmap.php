<?php
/**
 * Wave 0.7.D — TwinChat Studio Mindmap Tool
 *
 * Đăng ký tool 'mindmap' vào BCN_Notebook_Tool_Registry. Render markmap-compatible
 * Markdown từ skeleton.tree + _kg_subgraph (Graph-RAG entities/relations).
 *
 * Output:
 *   - content_format = 'markdown' (markmap.js có thể render trực tiếp)
 *   - content        = Markdown headings cây
 *
 * Không phụ thuộc plugin ngoài. Tool callback "built-in" — output lưu vào
 * bizcity_webchat_studio_outputs (artifact dùng chung).
 */

defined( 'ABSPATH' ) || exit;

class BizCity_TwinChat_Studio_Tools_Mindmap {

	/** @var self */
	private static $instance;

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	private function boot() {
		// Hook chung của BCN_Notebook_Tool_Registry — register một lần, dùng cho cả
		// TwinChat Studio và companion-notebook Studio.
		add_action( 'bcn_register_notebook_tools', array( $this, 'register' ), 20 );
	}

	/**
	 * @param BCN_Notebook_Tool_Registry $registry
	 */
	public function register( $registry ) {
		if ( ! is_object( $registry ) || ! method_exists( $registry, 'add' ) ) {
			return;
		}

		// If bizcity-doc is active it registers 'mindmap' via BZDoc_Notebook_Bridge at
		// priority 10, producing a proper interactive JSON schema + /tool-doc/ URL.
		// Our built-in Markdown output is only needed as fallback when bzdoc is absent.
		if ( class_exists( 'BZDoc_Notebook_Bridge' ) ) {
			return;
		}

		$registry->add( array(
			'type'        => 'mindmap',
			'label'       => 'Sơ đồ tư duy',
			'description' => 'Markmap từ skeleton + Graph-RAG entities',
			'icon'        => '🧠',
			'color'       => 'green',
			'category'    => 'visual',
			'mode'        => 'built-in',
			'available'   => true,
			'callback'    => array( $this, 'generate' ),
		) );
	}

	/**
	 * Tool callback.
	 *
	 * @param array $skeleton Skeleton JSON (đã có _kg_subgraph nếu KG-Hub khả dụng)
	 * @return array { content, content_format, title, data:{id?,url?} }
	 */
	public function generate( $skeleton ) {
		$title = '';
		if ( isset( $skeleton['nucleus']['title'] ) ) {
			$title = (string) $skeleton['nucleus']['title'];
		}
		if ( $title === '' && isset( $skeleton['nucleus']['thesis'] ) ) {
			$title = wp_trim_words( (string) $skeleton['nucleus']['thesis'], 12, '…' );
		}
		if ( $title === '' ) {
			$title = 'Sơ đồ tư duy';
		}

		$markdown = $this->build_markdown( $skeleton, $title );

		return array(
			'content'        => $markdown,
			'content_format' => 'markdown',
			'title'          => $title,
			'data'           => array(
				'id'  => 0,
				'url' => '',
			),
		);
	}

	/**
	 * Build markmap-compatible Markdown từ skeleton + KG subgraph.
	 *
	 * Cấu trúc:
	 *   # <root title>
	 *   ## Luận điểm chính
	 *      - thesis
	 *   ## Cấu trúc nội dung    ← từ skeleton.tree (depth ≤ 3)
	 *   ## Khái niệm cốt lõi    ← từ _kg_subgraph.entities
	 *   ## Liên kết tri thức    ← từ _kg_subgraph.relations
	 *   ## Câu hỏi mở           ← skeleton.questions (nếu có)
	 */
	private function build_markdown( $skeleton, $title ) {
		$lines = array();
		$lines[] = '# ' . $this->clean( $title );

		// Thesis
		$thesis = isset( $skeleton['nucleus']['thesis'] ) ? (string) $skeleton['nucleus']['thesis'] : '';
		if ( $thesis !== '' ) {
			$lines[] = '';
			$lines[] = '## Luận điểm chính';
			$lines[] = '- ' . $this->clean( $thesis );
		}

		// Tree (skeleton.tree) — đệ quy ≤ depth 3.
		if ( ! empty( $skeleton['tree'] ) && is_array( $skeleton['tree'] ) ) {
			$lines[] = '';
			$lines[] = '## Cấu trúc nội dung';
			$this->render_tree( $skeleton['tree'], $lines, 0, 3 );
		}

		// Entities từ Graph-RAG
		$kg = isset( $skeleton['_kg_subgraph'] ) && is_array( $skeleton['_kg_subgraph'] )
			? $skeleton['_kg_subgraph']
			: array();
		$entities = ( ! empty( $kg['available'] ) && ! empty( $kg['entities'] ) && is_array( $kg['entities'] ) )
			? array_slice( $kg['entities'], 0, 12 )
			: array();
		if ( ! empty( $entities ) ) {
			$lines[] = '';
			$lines[] = '## Khái niệm cốt lõi';
			foreach ( $entities as $ent ) {
				$lines[] = '- ' . $this->clean( (string) $ent );
			}
		}

		// Relations
		$relations = ( ! empty( $kg['available'] ) && ! empty( $kg['relations'] ) && is_array( $kg['relations'] ) )
			? array_slice( $kg['relations'], 0, 16 )
			: array();
		if ( ! empty( $relations ) ) {
			$lines[] = '';
			$lines[] = '## Liên kết tri thức';
			foreach ( $relations as $rel ) {
				$lines[] = '- ' . $this->clean( (string) $rel );
			}
		}

		// Open questions
		if ( ! empty( $skeleton['questions'] ) && is_array( $skeleton['questions'] ) ) {
			$lines[] = '';
			$lines[] = '## Câu hỏi mở';
			foreach ( array_slice( $skeleton['questions'], 0, 8 ) as $q ) {
				if ( is_array( $q ) ) {
					$q = $q['text'] ?? $q['question'] ?? '';
				}
				$q = trim( (string) $q );
				if ( $q !== '' ) {
					$lines[] = '- ' . $this->clean( $q );
				}
			}
		}

		// Footer note
		$src_n   = isset( $skeleton['stats']['source_count'] ) ? (int) $skeleton['stats']['source_count'] : 0;
		$note_n  = isset( $skeleton['stats']['note_count'] )   ? (int) $skeleton['stats']['note_count']   : 0;
		$node_n  = isset( $kg['subgraph']['node_count'] )      ? (int) $kg['subgraph']['node_count']      : 0;
		$link_n  = isset( $kg['subgraph']['link_count'] )      ? (int) $kg['subgraph']['link_count']      : 0;
		$lines[] = '';
		$lines[] = sprintf(
			'<!-- generated by twinchat-studio-mindmap | sources=%d notes=%d kg_nodes=%d kg_links=%d -->',
			$src_n, $note_n, $node_n, $link_n
		);

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Recursive markdown headings/list.
	 *
	 * @param array $nodes
	 * @param array $lines  by-ref
	 * @param int   $depth  current depth (0 = top under "## Cấu trúc nội dung")
	 * @param int   $max
	 */
	private function render_tree( $nodes, &$lines, $depth, $max ) {
		if ( $depth >= $max ) {
			return;
		}
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$label = '';
			foreach ( array( 'title', 'label', 'name', 'text', 'heading' ) as $key ) {
				if ( ! empty( $node[ $key ] ) ) {
					$label = (string) $node[ $key ];
					break;
				}
			}
			if ( $label === '' ) {
				continue;
			}
			$indent = str_repeat( '  ', $depth );
			$lines[] = $indent . '- ' . $this->clean( $label );

			$children = null;
			foreach ( array( 'children', 'sections', 'nodes', 'sub' ) as $ck ) {
				if ( ! empty( $node[ $ck ] ) && is_array( $node[ $ck ] ) ) {
					$children = $node[ $ck ];
					break;
				}
			}
			if ( $children ) {
				$this->render_tree( $children, $lines, $depth + 1, $max );
			}
		}
	}

	private function clean( $s ) {
		$s = wp_strip_all_tags( (string) $s );
		$s = preg_replace( '/\s+/u', ' ', $s );
		return trim( $s );
	}
}
