<?php
/**
 * Bizcity Twin AI — KG Backfill Driver (Abstract Base)
 *
 * Phase 0.6.5 Wave B — driver pattern for legacy → unified `kg_sources` +
 * `kg_source_chunks` migration. Each plugin (webchat / BCN / BizDoc /
 * Knowledge) ships a concrete subclass declaring its source/chunk table names
 * and how to map plugin-specific fields onto the unified schema.
 *
 * Idempotent: rows already present in `kg_xref` are skipped via LEFT JOIN.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge\KG_Hub
 * @since      2026-04-28 — Phase 0.6.5 Wave B
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

abstract class BizCity_KG_Backfill_Driver {

	// ─── Subclass hooks: declare table suffixes + identity ────────────────────

	/** Driver key used in status array + cortex column, e.g. 'webchat'. */
	protected $cortex_key = '';

	/** Plugin slug recorded in kg_sources.origin_plugin / plugin_name. */
	protected $plugin_name = '';

	/** Source table suffix (without $wpdb->prefix), e.g. 'bizcity_webchat_sources'. */
	protected $source_table = '';

	/** Chunk table suffix (without $wpdb->prefix). Empty = no chunk table. */
	protected $chunks_table = '';

	// ─── Subclass hooks: per-row mappers (override to customise) ──────────────

	/**
	 * Compose the unified `project_id` (VARCHAR(250)) for a legacy source row.
	 *
	 * Implementations must namespace integer keys to keep cross-site uniqueness
	 * (e.g. wrap `doc_42`, `agent_99`). UUIDs/strings can be returned as-is.
	 */
	abstract protected function project_id( array $src ): string;

	/**
	 * Resolve scope_type / scope_id / notebook_id from a legacy row.
	 *
	 * @return array{scope_type:string, scope_id:string, notebook_id:int}
	 */
	abstract protected function scope( array $src ): array;

	/** Title for kg_sources.title — default falls back to URL or "<plugin> #<id>". */
	protected function title( array $src ): string {
		$id = (int) ( $src['id'] ?? 0 );
		return (string) (
			$src['title']
			?? $src['source_name']
			?? $src['source_url']
			?? $src['url']
			?? ( ucfirst( $this->plugin_name ) . ' source #' . $id )
		);
	}

	/** Public URL (or empty). */
	protected function origin_url( array $src ): string {
		return (string) ( $src['source_url'] ?? $src['url'] ?? '' );
	}

	/** kg_sources.origin_kind — default sniffs URL vs file. */
	protected function origin_kind( array $src ): string {
		$kind = (string) ( $src['source_type'] ?? '' );
		if ( $kind !== '' ) {
			return $kind;
		}
		return $this->origin_url( $src ) !== '' ? 'url' : 'file';
	}

	/** Owner user id (0 = system). */
	protected function user_id( array $src ): int {
		return isset( $src['user_id'] ) ? (int) $src['user_id'] : 0;
	}

	/** Comma-list of chunk columns to fetch. Subclass may override (e.g. add content_hash). */
	protected function chunk_columns(): string {
		return 'id, chunk_index, content, token_count, embedding';
	}

	// ─── Public API ──────────────────────────────────────────────────────────

	/**
	 * Process up to $limit pending rows on the CURRENT blog.
	 *
	 * @return array{inserted:int, skipped:int, errors:int}
	 */
	public function run_batch( bool $dry_run = false, int $limit = 100 ): array {
		global $wpdb;

		$tbl_src   = $wpdb->prefix . $this->source_table;
		$tbl_chk   = $this->chunks_table ? ( $wpdb->prefix . $this->chunks_table ) : '';
		$kg_src    = $wpdb->prefix . 'bizcity_kg_sources';
		$kg_chunks = $wpdb->prefix . 'bizcity_kg_source_chunks'; // Phase 0.6.5 — replaces kg_passages.
		$kg_xref   = $wpdb->prefix . 'bizcity_kg_xref';

		// Guard: legacy table must exist on this blog.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl_src ) ) !== $tbl_src ) {
			return [ 'inserted' => 0, 'skipped' => 0, 'errors' => 0, 'no_table' => true ];
		}
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $kg_src ) ) !== $kg_src ) {
			return [ 'inserted' => 0, 'skipped' => 0, 'errors' => 0, 'no_table' => true ];
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.*
				   FROM {$tbl_src} s
				   LEFT JOIN {$kg_xref} kx
				          ON  kx.cortex          = %s
				          AND kx.cortex_table     = %s
				          AND kx.cortex_ref_id    = s.id
				          AND kx.kg_ref_type      = 'source'
				  WHERE kx.id IS NULL
				  ORDER BY s.id ASC
				  LIMIT %d",
				$this->cortex_key,
				$tbl_src,
				$limit
			),
			ARRAY_A
		);

		$inserted = 0;
		$skipped  = 0;
		$errors   = 0;

		foreach ( $rows as $src ) {
			$src_id = (int) $src['id'];

			$project_id = $this->project_id( $src );
			$scope      = $this->scope( $src );
			$title      = $this->title( $src );
			$origin_url = $this->origin_url( $src );
			$kind       = $this->origin_kind( $src );
			$user_id    = $this->user_id( $src );

			if ( $dry_run ) {
				$inserted++;
				continue;
			}

			// Fetch chunks (if this driver has a chunk table).
			$chunks = [];
			if ( $tbl_chk ) {
				$chunks = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT {$this->chunk_columns()}
						   FROM {$tbl_chk}
						  WHERE source_id = %d
						  ORDER BY chunk_index ASC
						  LIMIT 500",
						$src_id
					),
					ARRAY_A
				) ?: [];
			}

			// Reconstruct full text. Prefer legacy content_text/content column if no chunks exist.
			if ( $chunks ) {
				$full_text = implode( "\n\n", array_column( $chunks, 'content' ) );
			} else {
				$full_text = (string) ( $src['content_text'] ?? $src['content'] ?? '' );
			}

			$content_hash = (string) ( $src['content_hash'] ?? ( $full_text !== '' ? hash( 'sha256', $full_text ) : '' ) );
			$blog_id      = (int) get_current_blog_id();
			$embed_model  = (string) ( $src['embedding_model'] ?? '' );

			$ok = $wpdb->insert( $kg_src, [
				'uuid'          => wp_generate_uuid4(),
				'blog_id'       => $blog_id,
				'project_id'    => $project_id,
				'plugin_name'   => $this->plugin_name,
				'origin_plugin' => $this->plugin_name,
				'origin_kind'   => $kind,
				'origin_id'     => $src_id,
				'origin_table'  => $tbl_src,
				'title'         => $title,
				'origin_url'    => $origin_url ?: null,
				'content_text'  => $full_text ?: null,
				'content_hash'  => $content_hash ?: null,
				'status'        => 'active',
				'scope_type'    => $scope['scope_type'],
				'scope_id'      => $scope['scope_id'],
				'user_id'       => $user_id ?: null,
				'passage_count' => count( $chunks ),
				'embed_model'   => $embed_model ?: null,
				'embed_status'  => 'ready', // chunks already had embeddings
				'attachment_id' => isset( $src['attachment_id'] ) ? (int) $src['attachment_id'] : 0,
				'meta'          => wp_json_encode( [
					'backfill_at'  => current_time( 'mysql' ),
					'legacy_id'    => $src_id,
					'legacy_table' => $tbl_src,
				] ),
			] );

			if ( ! $ok ) {
				$errors++;
				continue;
			}

			$kg_src_id = (int) $wpdb->insert_id;

			// Copy chunks → kg_source_chunks.
			$pass_count = 0;
			foreach ( $chunks as $ch ) {
				$embedding = isset( $ch['embedding'] ) && $ch['embedding'] !== '' && $ch['embedding'] !== null
					? $ch['embedding'] : null;

				$content   = (string) ( $ch['content'] ?? '' );
				$ch_hash   = (string) ( $ch['content_hash'] ?? ( $content !== '' ? hash( 'sha256', $content ) : '' ) );

				$wpdb->insert( $kg_chunks, [
					'uuid'         => wp_generate_uuid4(),
					'source_id'    => $kg_src_id,
					'blog_id'      => $blog_id,
					'project_id'   => $project_id,
					'plugin_name'  => $this->plugin_name,
					'user_id'      => $user_id ?: null,
					'notebook_id'  => $scope['notebook_id'],
					'chunk_index'  => isset( $ch['chunk_index'] ) ? (int) $ch['chunk_index'] : 0,
					'content'      => $content,
					'content_hash' => $ch_hash ?: null,
					'token_count'  => isset( $ch['token_count'] ) ? (int) $ch['token_count'] : 0,
					'embedding'    => $embedding,
					'embed_model'  => $embed_model ?: null,
					'embed_status' => $embedding ? 'ready' : 'pending',
					'origin'       => $this->plugin_name . '_backfill',
					'scope_type'   => $scope['scope_type'],
					'scope_id'     => $scope['scope_id'],
					'source_table' => $tbl_src,
					'metadata'     => wp_json_encode( [
						'plugin'       => $this->plugin_name,
						'scope_type'   => $scope['scope_type'],
						'scope_id'     => $scope['scope_id'],
						'source_table' => $tbl_src,
						'legacy_id'    => $src_id,
						'chunk_index'  => isset( $ch['chunk_index'] ) ? (int) $ch['chunk_index'] : 0,
					] ),
				] );
				if ( $wpdb->insert_id ) $pass_count++;
			}

			$wpdb->insert( $kg_xref, [
				'cortex'        => $this->cortex_key,
				'cortex_table'  => $tbl_src,
				'cortex_ref_id' => $src_id,
				'kg_ref_type'   => 'source',
				'kg_ref_id'     => $kg_src_id,
				'relation'      => 'backfill',
				'meta'          => wp_json_encode( [
					'plugin'      => $this->plugin_name,
					'scope_type'  => $scope['scope_type'],
					'scope_id'    => $scope['scope_id'],
					'project_id'  => $project_id,
					'passages'    => $pass_count,
					'backfill_at' => current_time( 'mysql' ),
				] ),
			] );

			$inserted++;
		}

		return compact( 'inserted', 'skipped', 'errors' );
	}

	/** Driver key getter (used by orchestrator status map). */
	public function key(): string {
		return $this->cortex_key;
	}
}
