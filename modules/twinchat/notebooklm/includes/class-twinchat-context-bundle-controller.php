<?php
/**
 * BizCity TwinChat — Notebook Context Bundle Controller
 *
 * PHASE-6.4-KGHub-IMAGE Wave B — backend for the embedded Doc Studio + Image
 * Studio tabs in TwinChat. Surfaces a single bundle endpoint that the FE calls
 * when the user opens either tab; the response feeds the suggestion chips
 * ("Gợi ý từ notebook") and seeds prompts with pinned memory notes so output
 * follows the conversation context end-to-end.
 *
 * Endpoint:
 *   GET /wp-json/bizcity-twinchat/v1/notebooks/{notebook_id}/context-bundle
 *       ?for=image|doc&session_id=...&limit_notes=10
 *
 * Response shape (stable contract — FE in
 * `modules/twinchat/ui/src/components/embed/types.ts`):
 *
 *   {
 *     "ok": true,
 *     "notebook_id": 123,
 *     "title": "Chế độ ăn cho người Gout",
 *     "summary_text": "Tóm tắt 12 nguồn …",
 *     "keywords": ["gout","purin","rau xanh"],
 *     "suggested_topics": ["Infographic …", "Poster …", "Sơ đồ …"],
 *     "source_image_refs": [{"url":"https://…","label":"…"}],
 *     "citation_anchors": [{"id":"ent_42","label":"Acid uric"}],
 *     "source_count": 12,
 *     "pinned_notes": [
 *       {"id":1,"title":"…","content":"…","note_type":"chat_pinned","is_starred":1}
 *     ],
 *     "generated_at": 1714980000
 *   }
 *
 * Performance contract:
 *   • Single-blog query budget ≤ 6 SQL roundtrips.
 *   • Cached per (user, notebook, for) for 60s via transient — invalidated on
 *     `twin_message_appended` (PHASE-6.4-KGHub-IMAGE.md §11 Q5).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Modules\TwinChat\NotebookLM
 * @since      Phase 6.4 (Wave B)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_TwinChat_Context_Bundle_Controller {

	const TRANSIENT_PREFIX = 'tc_ctxbundle_';
	const TRANSIENT_TTL    = 60; // seconds
	const PROJECT_PREFIX   = 'tc_';

	/** @var self|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register_routes() {
		$ns = defined( 'BIZCITY_TWINCHAT_REST_NS' ) ? BIZCITY_TWINCHAT_REST_NS : 'bizcity-twinchat/v1';

		register_rest_route( $ns, '/notebooks/(?P<notebook_id>\d+)/context-bundle', [
			'methods'             => WP_REST_Server::READABLE,
			'permission_callback' => [ $this, 'check_logged_in' ],
			'callback'            => [ $this, 'get_bundle' ],
			'args'                => [
				'notebook_id' => [ 'type' => 'integer', 'required' => true ],
				'for'         => [
					'type'              => 'string',
					'default'           => 'image',
					'enum'              => [ 'image', 'doc' ],
					'sanitize_callback' => 'sanitize_key',
				],
				'session_id'  => [ 'type' => 'string' ],
				'limit_notes' => [ 'type' => 'integer', 'default' => 10 ],
				'no_cache'    => [ 'type' => 'boolean', 'default' => false ],
			],
		] );
	}

	/* ── Permission ────────────────────────────────────────────────────── */

	public function check_logged_in() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', 'Login required.', [ 'status' => 401 ] );
		}
		return true;
	}

	private function check_notebook_access( int $notebook_id ) {
		if ( $notebook_id <= 0 ) {
			return new WP_Error( 'invalid_notebook', 'Invalid notebook_id.', [ 'status' => 400 ] );
		}
		if ( ! class_exists( 'BizCity_KG_Notebook_Service' ) ) {
			return [ 'id' => $notebook_id, 'name' => '', 'description' => '', 'stats' => [] ];
		}
		$nb = BizCity_KG_Notebook_Service::instance()->get( $notebook_id );
		if ( ! $nb ) {
			return new WP_Error( 'not_found', 'Notebook not found.', [ 'status' => 404 ] );
		}
		$owner = (int) ( $nb['owner_id'] ?? $nb['user_id'] ?? 0 );
		if ( $owner !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'forbidden', 'Notebook not accessible.', [ 'status' => 403 ] );
		}
		return $nb;
	}

	/* ── Handler ───────────────────────────────────────────────────────── */

	public function get_bundle( WP_REST_Request $req ) {
		$notebook_id = (int) $req->get_param( 'notebook_id' );
		$for         = (string) $req->get_param( 'for' ) ?: 'image';
		$session_id  = (string) $req->get_param( 'session_id' );
		$limit_notes = max( 1, min( 50, (int) $req->get_param( 'limit_notes' ) ?: 10 ) );
		$no_cache    = (bool) $req->get_param( 'no_cache' );

		$nb = $this->check_notebook_access( $notebook_id );
		if ( is_wp_error( $nb ) ) return $nb;

		$cache_key = self::TRANSIENT_PREFIX . get_current_user_id() . '_' . $notebook_id . '_' . $for;
		if ( ! $no_cache ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				$cached['from_cache'] = true;
				return rest_ensure_response( $cached );
			}
		}

		$bundle = $this->build_bundle( $notebook_id, (array) $nb, $for, $session_id, $limit_notes );

		set_transient( $cache_key, $bundle, self::TRANSIENT_TTL );

		return rest_ensure_response( $bundle );
	}

	/* ── Builder ───────────────────────────────────────────────────────── */

	private function build_bundle( int $notebook_id, array $nb, string $for, string $session_id, int $limit_notes ): array {
		$title       = (string) ( $nb['name'] ?? '' );
		$description = (string) ( $nb['description'] ?? '' );

		// Source counts come from KG-Hub stats (already computed in hydrate()).
		// NOTE: $nb['stats']['sources'] counts bizcity_kg_notebook_sources (junction table)
		// which is only populated when attach_source() runs (graph build pipeline).
		// Before graph build completes, that junction is empty → count = 0 even though
		// sources are already in bizcity_kg_sources (written on upload via _upsert_kg_source_row).
		// [2026-06-05 Johnny Chu] SKEL-FUSION — fall back to bizcity_kg_sources
		// (scope_type='notebook', scope_id=notebook_id) which is written immediately on upload.
		$source_count = (int) ( $nb['stats']['sources'] ?? 0 );
		if ( $source_count === 0 ) {
			$source_count = $this->count_twinchat_sources( $notebook_id );
		}

		$keywords         = $this->collect_top_entities( $notebook_id, 8 );
		$citation_anchors = array_map( static function ( $row ) {
			return [
				'id'    => 'ent_' . (int) $row['id'],
				'label' => (string) $row['name'],
			];
		}, $keywords );
		$keyword_labels   = array_values( array_map( static fn( $row ) => (string) $row['name'], $keywords ) );

		$pinned_notes  = $this->load_pinned_notes( $notebook_id, $limit_notes );
		$source_images = $this->collect_source_image_refs( $notebook_id, 6 );

		// PHASE-6.6-SKELETON-DOC (2026-05-28) — when the notebook has a ready
		// skeleton, drive `summary_text` from its nucleus.thesis and seed
		// `suggested_topics` from skeleton.skeleton[].label so the chips
		// reflect the EXACT outline that the LLM will receive in the prompt
		// (R-SK-DOC contract). Falls back to keyword heuristic when skeleton
		// is missing/building. Wave C may still LLM-rewrite but this removes
		// the stale "Tổng hợp 0 nguồn" + generic chip bug.
		$skeleton = $this->load_skeleton_payload( $notebook_id );

		$summary_text = '';
		if ( ! empty( $skeleton['nucleus']['thesis'] ) ) {
			$summary_text = (string) $skeleton['nucleus']['thesis'];
		}
		if ( $summary_text === '' ) {
			$summary_text = $this->build_summary_text( $notebook_id, $description, $source_count, $keyword_labels );
		}

		$suggested_topics = $this->build_suggested_topics_from_skeleton( $for, $skeleton );
		if ( empty( $suggested_topics ) ) {
			$suggested_topics = $this->build_suggested_topics( $for, $title, $keyword_labels, $pinned_notes );
		}

		return [
			'ok'                 => true,
			'notebook_id'        => $notebook_id,
			'title'              => $title !== '' ? $title : sprintf( __( 'Notebook #%d', 'bizcity-twin-ai' ), $notebook_id ),
			'summary_text'       => $summary_text,
			'keywords'           => $keyword_labels,
			'suggested_topics'   => $suggested_topics,
			'source_image_refs'  => $source_images,
			'citation_anchors'   => $citation_anchors,
			'source_count'       => $source_count,
			'pinned_notes'       => $pinned_notes,
			'for'                => $for,
			'generated_at'       => time(),
			'from_cache'         => false,
		];
	}

	/**
	 * Top-N approved entities scoped to this notebook (proxy for "keywords").
	 *
	 * @return array<int, array{id:int,name:string,type:string}>
	 */
	private function collect_top_entities( int $notebook_id, int $limit ): array {
		if ( ! class_exists( 'BizCity_KG_Database' ) ) return [];
		global $wpdb;
		$tbl = BizCity_KG_Database::instance()->tbl_entities();
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, name, type
			 FROM {$tbl}
			 WHERE notebook_id = %d AND status = 'approved' AND deleted_at IS NULL
			 ORDER BY weight DESC, updated_at DESC, id DESC
			 LIMIT %d",
			$notebook_id, $limit
		), ARRAY_A );
		if ( ! is_array( $rows ) ) return [];
		return array_map( static function ( $r ) {
			return [
				'id'   => (int) $r['id'],
				'name' => (string) $r['name'],
				'type' => (string) ( $r['type'] ?? '' ),
			];
		}, $rows );
	}

	/**
	 * Count sources in bizcity_kg_sources for this notebook.
	 * Used as fallback when bizcity_kg_notebook_sources is still empty (graph not attached yet).
	 *
	 * bizcity_kg_sources is written IMMEDIATELY on upload via _upsert_kg_source_row() in
	 * BizCity_TwinChat_Sources_Service::ingest_materialized(), with scope_type='notebook'
	 * and scope_id=notebook_id. bizcity_kg_notebook_sources (the junction) is only populated
	 * later when BizCity_KG_Source_Service::attach_source() runs during graph build.
	 *
	 * [2026-06-05 Johnny Chu] SKEL-FUSION — avoids "0 nguồn" / "Chưa có nguồn" while graph
	 * is being built even though sources are already uploaded and visible to the user.
	 *
	 * @param int $notebook_id
	 * @return int
	 */
	private function count_twinchat_sources( int $notebook_id ): int {
		if ( $notebook_id <= 0 ) return 0;
		if ( ! class_exists( 'BizCity_KG_Database' ) ) return 0;
		global $wpdb;
		$tbl = BizCity_KG_Database::instance()->tbl_sources();
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$tbl} WHERE scope_type = 'notebook' AND scope_id = %s AND status = 'active'",
			(string) $notebook_id
		) );
	}

	/**
	 * Build a human-readable summary. Strategy (Wave B — heuristic, no LLM):
	 *   1. Use notebook description if non-empty.
	 *   2. Otherwise compose from N sources + top keywords.
	 */
	private function build_summary_text( int $notebook_id, string $description, int $source_count, array $keyword_labels ): string {
		$desc = trim( wp_strip_all_tags( $description ) );
		if ( $desc !== '' ) {
			return mb_substr( $desc, 0, 500 );
		}
		if ( $source_count <= 0 && empty( $keyword_labels ) ) {
			return __( 'Notebook chưa có nguồn nào — hãy upload tài liệu để tạo gợi ý.', 'bizcity-twin-ai' );
		}
		$kw = $keyword_labels ? implode( ', ', array_slice( $keyword_labels, 0, 5 ) ) : '';
		return sprintf(
			/* translators: 1: source count 2: top keywords */
			__( 'Tổng hợp %1$d nguồn trong notebook — chủ đề chính: %2$s.', 'bizcity-twin-ai' ),
			$source_count,
			$kw !== '' ? $kw : __( 'chưa xác định', 'bizcity-twin-ai' )
		);
	}

	/**
	 * Pinned + starred memory notes for this notebook scope (project_id = tc_<id>).
	 * Filters out empty content. Sorted: starred first, then most recent.
	 *
	 * @return array<int, array<string,mixed>>
	 */
	private function load_pinned_notes( int $notebook_id, int $limit ): array {
		global $wpdb;
		$tbl = $wpdb->prefix . 'bizcity_memory_notes';

		// Detect table existence — older deploys may not have BCN active yet.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) );
		if ( $exists !== $tbl ) return [];

		$pid = self::PROJECT_PREFIX . $notebook_id;

		// Pull notes owned by this notebook scope. Prefer:
		//   • is_starred = 1   → user explicitly starred / pinned
		//   • note_type IN ('chat_pinned','manual','insight')
		// then fall back to any other note for the same project.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, title, content, note_type, is_starred, message_id, created_at
			 FROM {$tbl}
			 WHERE project_id = %s
			   AND content <> ''
			 ORDER BY is_starred DESC, updated_at DESC, id DESC
			 LIMIT %d",
			$pid, $limit
		), ARRAY_A );

		if ( ! is_array( $rows ) ) return [];

		return array_values( array_map( static function ( $r ) {
			$content = trim( wp_strip_all_tags( (string) ( $r['content'] ?? '' ) ) );
			return [
				'id'         => (int) $r['id'],
				'title'      => (string) ( $r['title'] ?? '' ),
				'content'    => mb_substr( $content, 0, 800 ),
				'note_type'  => (string) ( $r['note_type'] ?? 'manual' ),
				'is_starred' => (int) ( $r['is_starred'] ?? 0 ),
				'message_id' => (int) ( $r['message_id'] ?? 0 ),
				'created_at' => (string) ( $r['created_at'] ?? '' ),
			];
		}, $rows ) );
	}

	/**
	 * Collect WP-attached image references the user has uploaded as references
	 * for this notebook (Wave 1 image agent persists them as bizcity_doc_images
	 * postmeta + media). For Wave B we keep this minimal — just return media
	 * items tagged with the notebook id when available; otherwise empty.
	 *
	 * @return array<int, array{url:string,label:string}>
	 */
	private function collect_source_image_refs( int $notebook_id, int $limit ): array {
		// Defensive: scan WP attachments tagged via meta `_bizcity_notebook_id`.
		// Most deploys don't tag attachments yet — return [] gracefully.
		$q = new WP_Query( [
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => $limit,
			'meta_query'     => [
				[ 'key' => '_bizcity_notebook_id', 'value' => $notebook_id, 'compare' => '=' ],
			],
			'fields'         => 'ids',
			'no_found_rows'  => true,
		] );

		$out = [];
		foreach ( (array) $q->posts as $att_id ) {
			$url = wp_get_attachment_url( (int) $att_id );
			if ( ! $url ) continue;
			$out[] = [
				'url'   => esc_url_raw( $url ),
				'label' => (string) get_the_title( (int) $att_id ),
			];
		}
		return $out;
	}

	/**
	 * Build 3 suggestion chips. Heuristic — combines `for` (image vs doc),
	 * top keywords, and any pinned-note titles into ready-to-click prompts.
	 * Wave C will replace with LLM-generated suggestions.
	 *
	 * @return string[]
	 */
	private function build_suggested_topics( string $for, string $title, array $keywords, array $pinned_notes ): array {
		$topic_hint = $title !== '' ? $title : ( $keywords[0] ?? __( 'chủ đề notebook', 'bizcity-twin-ai' ) );

		// Pull up to 2 pinned-note titles to seed personalised suggestions.
		$pin_titles = [];
		foreach ( $pinned_notes as $n ) {
			$t = trim( (string) ( $n['title'] ?? '' ) );
			if ( $t === '' ) continue;
			$pin_titles[] = mb_substr( $t, 0, 60 );
			if ( count( $pin_titles ) >= 2 ) break;
		}

		if ( $for === 'doc' ) {
			$base = [
				sprintf( __( 'Tài liệu tổng hợp về %s', 'bizcity-twin-ai' ), $topic_hint ),
				sprintf( __( 'Slide thuyết trình giới thiệu %s', 'bizcity-twin-ai' ), $topic_hint ),
				sprintf( __( 'Sơ đồ tư duy chuỗi nguyên nhân — %s', 'bizcity-twin-ai' ), $topic_hint ),
			];
		} else {
			$base = [
				sprintf( __( 'Infographic về %s', 'bizcity-twin-ai' ), $topic_hint ),
				sprintf( __( 'Poster hướng dẫn liên quan đến %s', 'bizcity-twin-ai' ), $topic_hint ),
				sprintf( __( 'Hero banner minh hoạ %s', 'bizcity-twin-ai' ), $topic_hint ),
			];
		}

		// Personalise the first slot with a pinned-note title when available.
		if ( ! empty( $pin_titles[0] ) ) {
			$base[0] = $for === 'doc'
				? sprintf( __( 'Tài liệu mở rộng từ ghi chú: "%s"', 'bizcity-twin-ai' ), $pin_titles[0] )
				: sprintf( __( 'Hình ảnh minh hoạ ghi chú: "%s"', 'bizcity-twin-ai' ), $pin_titles[0] );
		}
		if ( ! empty( $pin_titles[1] ) ) {
			$base[1] = $for === 'doc'
				? sprintf( __( 'Slide từ ghi chú: "%s"', 'bizcity-twin-ai' ), $pin_titles[1] )
				: sprintf( __( 'Poster minh hoạ ghi chú: "%s"', 'bizcity-twin-ai' ), $pin_titles[1] );
		}

		return $base;
	}

	/**
	 * PHASE-6.6-SKELETON-DOC — load the latest notebook skeleton payload
	 * via the canonical adapter. Returns decoded array {nucleus, skeleton[],
	 * key_points[]} or empty array when not ready.
	 *
	 * @return array<string,mixed>
	 */
	private function load_skeleton_payload( int $notebook_id ): array {
		if ( ! class_exists( 'BizCity_KG_Skeleton_Adapter' ) ) return [];
		try {
			$payload = BizCity_KG_Skeleton_Adapter::get_skeleton( $notebook_id );
			if ( is_array( $payload ) ) return $payload;
		} catch ( \Throwable $e ) { /* graceful fallback */ }
		// Direct DB read as last resort — adapter may not expose get_skeleton().
		global $wpdb;
		$tbl = $wpdb->prefix . 'bizcity_kg_notebooks';
		$json = $wpdb->get_var( $wpdb->prepare(
			"SELECT skeleton_json FROM {$tbl} WHERE id = %d AND skeleton_status = 'ready' LIMIT 1",
			$notebook_id
		) );
		if ( ! $json ) return [];
		$decoded = json_decode( (string) $json, true );
		return is_array( $decoded ) ? $decoded : [];
	}

	/**
	 * PHASE-6.6-SKELETON-DOC — build 3-5 suggestion chips directly from the
	 * skeleton outline so chips match the EXACT structure injected into the
	 * prompt. Returns [] when skeleton missing → caller falls back to the
	 * legacy keyword/pinned-note heuristic.
	 *
	 * @return string[]
	 */
	private function build_suggested_topics_from_skeleton( string $for, array $skeleton ): array {
		$outline = isset( $skeleton['skeleton'] ) && is_array( $skeleton['skeleton'] ) ? $skeleton['skeleton'] : [];
		if ( empty( $outline ) ) return [];

		$labels = [];
		foreach ( $outline as $node ) {
			if ( ! is_array( $node ) ) continue;
			$lab = trim( (string) ( $node['label'] ?? '' ) );
			if ( $lab === '' ) continue;
			$labels[] = mb_substr( $lab, 0, 80 );
			if ( count( $labels ) >= 5 ) break;
		}
		if ( empty( $labels ) ) return [];

		// Prefix with tool-specific verb so chips read as actions.
		$verb = ( $for === 'doc' )
			? __( 'Triển khai section', 'bizcity-twin-ai' )
			: __( 'Minh hoạ section', 'bizcity-twin-ai' );

		return array_map(
			static fn( string $lab ): string => $verb . ': ' . $lab,
			$labels
		);
	}

	/**
	 * Invalidate cached bundles for a notebook. Call sites:
	 *   • `twin_message_appended` action → fresh chat turn means new context.
	 *   • Pin/unpin note REST handlers (Wave C optional).
	 */
	public static function invalidate( int $notebook_id ): void {
		if ( $notebook_id <= 0 ) return;
		// Best-effort — we don't track which user(s) cached, so wildcard delete.
		global $wpdb;
		$like = $wpdb->esc_like( '_transient_' . self::TRANSIENT_PREFIX ) . '%_' . $notebook_id . '_%';
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
			$like
		) );
		$like_t = $wpdb->esc_like( '_transient_timeout_' . self::TRANSIENT_PREFIX ) . '%_' . $notebook_id . '_%';
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
			$like_t
		) );
	}
}

// PHASE-6.4-KGHub-IMAGE Wave B — invalidate cache on every new TwinChat message
// so the next bundle fetch reflects the latest conversation. Decision §11 Q5.
add_action( 'twin_message_appended', static function ( $notebook_id ) {
	BizCity_TwinChat_Context_Bundle_Controller::invalidate( (int) $notebook_id );
}, 10, 1 );
