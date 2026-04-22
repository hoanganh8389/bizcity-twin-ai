<?php
/**
 * BZDoc Embedder — Chunk + embed + semantic search for project sources.
 *
 * Reuses bizcity_openrouter_embeddings() from the mu-plugin.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BZDoc_Embedder {

	private static function sources_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'bzdoc_project_sources';
	}

	private static function chunks_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'bzdoc_project_source_chunks';
	}

	/* ═══════════════════════════════════════════════
	   EMBED SOURCE — chunk + embed + store
	   ═══════════════════════════════════════════════ */
	public static function embed_source( int $source_id, string $model = '' ): array {
		global $wpdb;

		// Only load metadata — NOT content_text (saves memory on 102MB hosts)
		$source = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, doc_id, CHAR_LENGTH(content_text) AS content_len FROM " . self::sources_table() . " WHERE id = %d",
			$source_id
		) );
		if ( ! $source ) {
			return [ 'success' => false, 'error' => 'Source not found' ];
		}

		if ( ! function_exists( 'bizcity_openrouter_embeddings' ) ) {
			return [ 'success' => false, 'error' => 'bizcity_openrouter_embeddings() not available' ];
		}

		// Mark processing
		$wpdb->update( self::sources_table(), [ 'embedding_status' => 'processing' ], [ 'id' => $source_id ] );

		$doc_id      = (int) $source->doc_id;
		$content_len = (int) $source->content_len;
		unset( $source );

		if ( $content_len === 0 ) {
			$wpdb->update( self::sources_table(), [
				'embedding_status' => 'skipped',
				'chunk_count'      => 0,
			], [ 'id' => $source_id ] );
			return [ 'success' => true, 'chunks' => 0 ];
		}

		// Cap at 200K chars
		$max_chars = 200000;
		if ( $content_len > $max_chars ) {
			$content_len = $max_chars;
		}

		// Chunk content by reading slices from DB (avoids loading full text into PHP)
		$chunks = self::chunk_from_db( $source_id, $content_len, 800, 100 );
		if ( empty( $chunks ) ) {
			$wpdb->update( self::sources_table(), [
				'embedding_status' => 'skipped',
				'chunk_count'      => 0,
			], [ 'id' => $source_id ] );
			return [ 'success' => true, 'chunks' => 0 ];
		}

		// Delete old chunks
		$wpdb->delete( self::chunks_table(), [ 'source_id' => $source_id ] );

		// Batch embed (5 at a time — small batches to stay under 102MB)
		$batch_size    = 5;
		$total_stored  = 0;
		$embed_model   = '';
		$total_chunks  = count( $chunks );

		for ( $i = 0; $i < $total_chunks; $i += $batch_size ) {
			$batch = array_slice( $chunks, $i, $batch_size );
			$texts = array_column( $batch, 'text' );

			$result = bizcity_openrouter_embeddings( $texts );

			if ( empty( $result['success'] ) || empty( $result['embeddings'] ) ) {
				error_log( '[BZDoc] Embedding batch failed: ' . ( $result['error'] ?? 'unknown' ) );
				$wpdb->update( self::sources_table(), [ 'embedding_status' => 'failed' ], [ 'id' => $source_id ] );
				return [ 'success' => false, 'error' => $result['error'] ?? 'Embedding failed' ];
			}

			$embed_model = $result['model'] ?? $model;

			foreach ( $result['embeddings'] as $j => $embedding ) {
				$chunk_idx = $i + $j;
				$wpdb->insert( self::chunks_table(), [
					'source_id'       => $source_id,
					'doc_id'          => $doc_id,
					'chunk_index'     => $chunk_idx,
					'content'         => $batch[ $j ]['text'],
					'token_count'     => (int) ( strlen( $batch[ $j ]['text'] ) / 4 ),
					'embedding'       => wp_json_encode( $embedding ),
					'embedding_model' => $embed_model,
					'created_at'      => current_time( 'mysql' ),
				] );
				$total_stored++;
			}
			// Free batch memory
			unset( $batch, $texts, $result );
		}
		// Free all chunks
		unset( $chunks );

		// Update source
		$wpdb->update( self::sources_table(), [
			'embedding_status' => 'done',
			'embedding_model'  => $embed_model,
			'chunk_count'      => $total_stored,
		], [ 'id' => $source_id ] );

		return [ 'success' => true, 'chunks' => $total_stored, 'model' => $embed_model ];
	}

	/* ═══════════════════════════════════════════════
	   SEMANTIC SEARCH — cosine similarity
	   ═══════════════════════════════════════════════ */
	public static function search( string $query, int $doc_id, int $top_k = 5, float $threshold = 0.3 ): array {
		if ( ! function_exists( 'bizcity_openrouter_embeddings' ) ) {
			return [];
		}

		// Embed query
		$result = bizcity_openrouter_embeddings( [ $query ] );
		if ( empty( $result['success'] ) || empty( $result['embeddings'][0] ) ) {
			return [];
		}

		$query_vec = $result['embeddings'][0];

		// Load chunks in batches to avoid OOM on large documents
		global $wpdb;
		$total = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM " . self::chunks_table() . " WHERE doc_id = %d AND embedding IS NOT NULL",
			$doc_id
		) );

		if ( $total === 0 ) return [];

		$scored    = [];
		$page_size = 50; // Load 50 chunks at a time

		for ( $offset = 0; $offset < $total; $offset += $page_size ) {
			$chunks = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, source_id, chunk_index, content, embedding
				 FROM " . self::chunks_table() . "
				 WHERE doc_id = %d AND embedding IS NOT NULL
				 LIMIT %d OFFSET %d",
				$doc_id, $page_size, $offset
			) );

			if ( empty( $chunks ) ) break;

			foreach ( $chunks as $chunk ) {
				$vec = json_decode( $chunk->embedding, true );
				if ( ! is_array( $vec ) ) continue;

				$sim = self::cosine_similarity( $query_vec, $vec );
				if ( $sim >= $threshold ) {
					$scored[] = [
						'chunk_id'   => $chunk->id,
						'source_id'  => $chunk->source_id,
						'content'    => $chunk->content,
						'similarity' => round( $sim, 4 ),
					];
				}

				// Free memory
				unset( $vec );
			}

			// Free batch
			unset( $chunks );
		}

		// Sort by similarity DESC
		usort( $scored, fn( $a, $b ) => $b['similarity'] <=> $a['similarity'] );

		return array_slice( $scored, 0, $top_k );
	}

	/* ═══════════════════════════════════════════════
	   BUILD CONTEXT — for RAG injection into prompt
	   ═══════════════════════════════════════════════ */
	public static function build_context( string $query, int $doc_id, int $max_tokens = 4000 ): string {
		$results = self::search( $query, $doc_id, 10, 0.25 );
		if ( empty( $results ) ) {
			// Fallback: return all source content (no embedding search)
			return BZDoc_Sources::get_all_content( $doc_id, $max_tokens * 4 );
		}

		$context = '';
		$budget  = $max_tokens * 4; // approx chars

		foreach ( $results as $r ) {
			$addition = "[Relevance: {$r['similarity']}]\n{$r['content']}\n---\n";
			if ( strlen( $context ) + strlen( $addition ) > $budget ) break;
			$context .= $addition;
		}

		return $context;
	}

	/* ═══════════════════════════════════════════════
	   CHUNK FROM DB — read content in slices via SQL SUBSTRING
	   Never loads full content_text into PHP memory.
	   ═══════════════════════════════════════════════ */
	private static function chunk_from_db( int $source_id, int $content_len, int $chunk_size = 800, int $overlap = 100 ): array {
		global $wpdb;
		$table  = self::sources_table();
		$chunks = [];
		$pos    = 1; // SQL SUBSTRING is 1-based

		while ( $pos <= $content_len ) {
			// Read a slice from DB (chunk_size + extra for sentence boundary search)
			$read_size = min( $chunk_size + 200, $content_len - $pos + 1 );
			$slice = $wpdb->get_var( $wpdb->prepare(
				"SELECT SUBSTRING(content_text, %d, %d) FROM {$table} WHERE id = %d",
				$pos, $read_size, $source_id
			) );

			if ( $slice === null || $slice === '' ) break;

			$actual_len = strlen( $slice );

			// Use exactly chunk_size or find sentence boundary
			if ( $actual_len <= $chunk_size ) {
				$chunk = trim( $slice );
				$advance = $actual_len;
			} else {
				$chunk_part = substr( $slice, 0, $chunk_size );
				// Try to break at sentence boundary
				$last_period = max(
					strrpos( $chunk_part, '.' ) ?: 0,
					strrpos( $chunk_part, "\n\n" ) ?: 0
				);
				if ( $last_period > $chunk_size * 0.5 ) {
					$chunk = trim( substr( $slice, 0, $last_period + 1 ) );
					$advance = $last_period + 1;
				} else {
					$chunk = trim( $chunk_part );
					$advance = $chunk_size;
				}
			}

			if ( $chunk !== '' ) {
				$chunks[] = [ 'text' => $chunk, 'start' => $pos - 1 ];
			}
			unset( $slice, $chunk, $chunk_part );

			$pos += max( 1, $advance - $overlap );

			// Safety: max 500 chunks
			if ( count( $chunks ) >= 500 ) break;
		}

		return $chunks;
	}

	/* ═══════════════════════════════════════════════
	   CHUNKING — split text into overlapping chunks (in-memory, for small texts)
	   ═══════════════════════════════════════════════ */
	private static function chunk_text( string $text, int $chunk_size = 800, int $overlap = 100 ): array {
		$text = trim( $text );
		if ( $text === '' ) return [];

		// Cap content to prevent OOM on shared hosting (~102MB limit)
		$max_bytes = 200000;
		if ( strlen( $text ) > $max_bytes ) {
			$text = substr( $text, 0, $max_bytes );
		}

		$len = strlen( $text );

		// Short text: single chunk
		if ( $len <= $chunk_size ) {
			return [ [ 'text' => $text, 'start' => 0 ] ];
		}

		$chunks = [];
		$pos    = 0;

		while ( $pos < $len ) {
			$end   = min( $pos + $chunk_size, $len );
			$chunk = substr( $text, $pos, $end - $pos );

			// Try to break at sentence boundary
			if ( $end < $len ) {
				$last_period = max(
					strrpos( $chunk, '.' ) ?: 0,
					strrpos( $chunk, "\n\n" ) ?: 0
				);
				if ( $last_period > $chunk_size * 0.5 ) {
					$chunk = substr( $chunk, 0, $last_period + 1 );
					$end   = $pos + strlen( $chunk );
				}
			}

			$trimmed = trim( $chunk );
			if ( $trimmed !== '' ) {
				$chunks[] = [ 'text' => $trimmed, 'start' => $pos ];
			}
			unset( $chunk, $trimmed );

			$pos = $end - $overlap;
			if ( $pos >= $len ) break;
		}

		return $chunks;
	}

	/* ── Cosine Similarity ── */
	private static function cosine_similarity( array $a, array $b ): float {
		$dot    = 0.0;
		$norm_a = 0.0;
		$norm_b = 0.0;

		$len = min( count( $a ), count( $b ) );
		for ( $i = 0; $i < $len; $i++ ) {
			$dot    += $a[ $i ] * $b[ $i ];
			$norm_a += $a[ $i ] * $a[ $i ];
			$norm_b += $b[ $i ] * $b[ $i ];
		}

		$denom = sqrt( $norm_a ) * sqrt( $norm_b );
		return $denom > 0 ? $dot / $denom : 0.0;
	}
}
