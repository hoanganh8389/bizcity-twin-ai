<?php
/**
 * TwinSearch — Persona Tool Provider (Wave 0.18.1.7)
 *
 * Bridges Notebook Persona system with the Tavily-backed research pipeline.
 * Family: `retrieval` (R-IP-1).
 *
 * Owned source kinds (R-PP-3, R-IP-4):
 *   • research_studio   — primary research session output (markdown report)
 *   • research_extract  — single URL extracted (markdown body)
 *   • research_crawl    — crawled domain page (markdown body)
 *
 * Tools are thin façades; heavy lifting lives in BizCity_Research_Agent and
 * BizCity_Research_Ingest_Service (already shipped Waves 0.18.1.1..0.18.1.6).
 *
 * @package Bizcity_Twin_AI\Modules\TwinSearch
 * @see PHASE-0-RULE-INPUT-PROVIDER.md
 * @see PHASE-0-RULE-PERSONA-PROVIDER.md
 * @since 0.18.1.7
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_TwinSearch_Persona_Provider' ) ) {
	return;
}

class BizCity_TwinSearch_Persona_Provider extends BizCity_Persona_Tool_Provider {

	const KIND_STUDIO  = 'research_studio';
	const KIND_EXTRACT = 'research_extract';
	const KIND_CRAWL   = 'research_crawl';

	/* ─────────────── R-PP-1 — Identity ─────────────── */

	public function id(): string {
		return 'twinsearch';
	}

	public function label(): string {
		return __( 'TwinSearch — Nghiên cứu Web (Tavily)', 'bizcity-twinsearch' );
	}

	public function version(): string {
		return '0.1.0';
	}

	/* ─────────────── R-PP-3 — Owned source kinds ─────────────── */

	public function get_source_kinds(): array {
		return [ self::KIND_STUDIO, self::KIND_EXTRACT, self::KIND_CRAWL ];
	}

	/* ─────────────── R-PP-5 — Tool definitions ─────────────── */

	public function get_tool_definitions(): array {
		return [
			[
				'name'          => 'twinsearch_research',
				'label'         => __( 'Deep Research', 'bizcity-twinsearch' ),
				'description'   => __( 'Mở dialog Tavily research (search / extract / crawl) để thu thập nguồn chuẩn markdown.', 'bizcity-twinsearch' ),
				'slot_schema'   => [
					'query' => 'text',
					'mode'  => 'choice', // fast|deep
				],
				'side_effect'   => 'external',
				'cost_class'    => 'high',
				'callback'      => [ $this, 'tool_open_dialog' ],
				'required_caps' => [ 'read' ],
			],
		];
	}

	/* ─────────────── R-PP — Smart source chips ─────────────── */

	public function get_smart_source_chips(): array {
		return [
			[
				'id'    => 'twinsearch_open',
				'label' => '🔬 ' . __( 'Deep Research', 'bizcity-twinsearch' ),
				'icon'  => 'search',
				'mount' => 'twinsearch-dialog',
				'kind'  => self::KIND_STUDIO,
			],
		];
	}

	/* ─────────────── R-PP-6 — System prompt enrich ─────────────── */

	public function enrich_system_prompt( int $user_id, int $character_id, array $ctx ): string {
		try {
			return "## Nguồn nghiên cứu (TwinSearch)\n"
				. "Bạn có thể trích dẫn các nguồn web đã được TwinSearch lọc và lưu thành markdown chuẩn. "
				. "Khi cần dữ kiện thực, ưu tiên trích dẫn sources kind=research_studio/research_extract/research_crawl.\n";
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/* ─────────────── R-PP-7 — Citation resolver ─────────────── */

	public function resolve_citation( int $source_id ): array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, title, source_url, content_md FROM {$wpdb->prefix}bizcity_kg_sources WHERE id=%d",
			$source_id
		), ARRAY_A );
		if ( ! $row ) {
			return [ 'id' => $source_id, 'title' => '', 'body' => '', 'url' => '' ];
		}
		return [
			'id'    => (int) $row['id'],
			'title' => (string) $row['title'],
			'url'   => (string) $row['source_url'],
			'body'  => (string) $row['content_md'],
		];
	}

	/* ─────────────── R-PP — Render to passages ─────────────── */

	public function render_to_passages( string $kind, array $artifact ): array {
		$body  = (string) ( $artifact['content_md'] ?? '' );
		$title = (string) ( $artifact['title'] ?? '' );
		if ( $body === '' ) {
			return [];
		}
		return [ [
			'title'    => $title,
			'body'     => $body,
			'metadata' => [
				'source_kind' => $kind,
				'provider'    => $this->id(),
				'url'         => (string) ( $artifact['source_url'] ?? '' ),
			],
		] ];
	}

	/* ─────────────── R-IP-2 — Research capability override ─────────────── */

	public function get_research_capability(): ?array {
		return [
			'enabled'            => true,
			'modes'              => [ 'fast', 'deep' ],
			'allowed_tools'      => [ 'search', 'extract', 'crawl' ],
			'rate_limit_per_day' => 50,
			'starter_queries'    => [],
			'topic_tags'         => [],
			'ui_label'           => '🔬 ' . __( 'Deep Research', 'bizcity-twinsearch' ),
		];
	}

	/* ─────────────── Tool callback (no-op — UI driven) ─────────────── */

	/**
	 * Opening the dialog is a UI action; the actual work happens in the
	 * React component via REST routes under `bizcity/research/v1`.
	 * This callback exists so the tool registry has a consistent shape.
	 */
	public function tool_open_dialog( array $slots, array $ctx = [] ): array {
		return [
			'mount'      => 'twinsearch-dialog',
			'scope_type' => isset( $ctx['scope_type'] ) ? (string) $ctx['scope_type'] : 'notebook',
			'scope_id'   => isset( $ctx['scope_id'] ) ? (int) $ctx['scope_id'] : 0,
		];
	}
}
