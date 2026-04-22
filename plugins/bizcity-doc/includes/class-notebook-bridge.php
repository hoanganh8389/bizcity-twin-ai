<?php
/**
 * Notebook Bridge — Register Doc Studio tools with Companion Notebook.
 *
 * Registers 3 tools into BCN_Notebook_Tool_Registry:
 *   doc_document      → DOCX/PDF document
 *   doc_presentation  → PPTX presentation
 *   doc_spreadsheet   → XLSX spreadsheet
 *
 * Each tool receives the standard skeleton JSON from Notebook Studio,
 * converts it into a topic string, and delegates to the Doc Studio
 * generation pipeline (same quality as standalone mode).
 *
 * @package    BizCity_Doc
 * @since      0.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BZDoc_Notebook_Bridge {

	/**
	 * Tool definitions.
	 */
	private static $tools = [
		'doc_document' => [
			'label'       => 'Tài liệu Word',
			'description' => 'Tạo tài liệu Word/PDF chuyên nghiệp từ nguồn dự án',
			'icon'        => '📄',
			'color'       => 'blue',
			'doc_type'    => 'document',
		],
		'doc_presentation' => [
			'label'       => 'Slide trình bày',
			'description' => 'Tạo bài thuyết trình PowerPoint từ nguồn dự án',
			'icon'        => '📊',
			'color'       => 'purple',
			'doc_type'    => 'presentation',
		],
		'doc_spreadsheet' => [
			'label'       => 'Bảng tính Excel',
			'description' => 'Tạo bảng tính Excel từ nguồn dự án',
			'icon'        => '📋',
			'color'       => 'green',
			'doc_type'    => 'spreadsheet',
		],
	];

	/**
	 * Register all Doc Studio tools with the Notebook Tool Registry.
	 */
	public static function register( $registry ) {
		foreach ( self::$tools as $type => $def ) {
			$doc_type = $def['doc_type'];

			$registry->add( [
				'type'        => $type,
				'label'       => $def['label'],
				'description' => $def['description'],
				'icon'        => $def['icon'],
				'color'       => $def['color'],
				'category'    => 'document',
				'mode'        => 'delegate',
				'available'   => true,
				'callback'    => function ( array $skeleton ) use ( $doc_type ) {
					return self::generate_from_skeleton( $skeleton, $doc_type );
				},
			] );
		}
	}

	/**
	 * Hybrid Bridge: Notebook → Doc Studio.
	 *
	 * Flow (3 steps, user feels 1):
	 *   1. Create a Doc Studio project + transfer sources (fast, no embed)
	 *   2. Generate document via LLM pipeline (with source_context_override)
	 *   3. Return output with edit link → /tool-doc/?id={doc_id}&gen={gen_id}
	 *
	 * Benefits:
	 *   - Immediate generation (no waiting for embeddings)
	 *   - Sources preserved in Doc Studio for future RAG edits
	 *   - User can continue editing in Doc Studio after generation
	 *   - Generation history tracked (gen_id for undo/restore)
	 *
	 * @param array  $skeleton  Standard skeleton JSON from BCN_Studio_Input_Builder.
	 * @param string $doc_type  document | presentation | spreadsheet
	 * @return array|WP_Error
	 */
	private static function generate_from_skeleton( array $skeleton, string $doc_type ) {
		$topic_parts  = self::skeleton_to_topic( $skeleton, $doc_type );
		$topic        = $topic_parts['topic'] ?? '';
		$source_text  = $topic_parts['source_text'] ?? '';

		if ( empty( trim( $topic ) ) && empty( trim( $source_text ) ) ) {
			return new \WP_Error( 'no_content', 'Không có nội dung để tạo tài liệu.' );
		}

		/* ── Step 1: Create Doc Studio project + transfer sources ── */
		$doc_id  = self::create_doc_project( $skeleton, $doc_type );
		$raw_text = $skeleton['_raw_text'] ?? '';

		if ( $doc_id && ! is_wp_error( $doc_id ) ) {
			self::transfer_sources( $doc_id, $skeleton, $raw_text );
		}

		/* ── Step 2: Generate document via full LLM pipeline ── */
		$request = new \WP_REST_Request( 'POST' );
		$request->set_param( 'doc_type', $doc_type );
		$request->set_param( 'topic', $topic );
		$request->set_param( 'template_name', self::guess_template( $skeleton, $doc_type ) );
		$request->set_param( 'theme_name', 'modern' );

		// Pass the long source text separately so it goes into the source context
		// instead of the topic (which has a 2000-char limit)
		if ( ! empty( $source_text ) ) {
			$request->set_param( 'source_context_override', $source_text );
		}

		if ( $doc_id && ! is_wp_error( $doc_id ) ) {
			$request->set_param( 'doc_id', $doc_id );
		}

		if ( $doc_type === 'presentation' ) {
			$request->set_param( 'slide_count', 0 );
		}

		// Pass full skeleton for sectional generation (documents only)
		if ( $doc_type === 'document' && ! empty( $skeleton['skeleton'] ) ) {
			$request->set_param( 'skeleton_json', $skeleton );
		}

		$result = BZDoc_Rest_API::handle_generate( $request );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		/* ── Step 3: Build output with edit link ── */
		$response_data = $result->get_data();
		$schema        = $response_data['data'] ?? $response_data;
		$final_doc_id  = $response_data['doc_id'] ?? $doc_id;
		$gen_id        = $response_data['gen_id'] ?? 0;

		$title = self::extract_title( $schema, $doc_type );

		// Build Doc Studio URL with doc_id + gen_id + preview mode for iframe display.
		$url = '';
		if ( $final_doc_id ) {
			$url = home_url( "/tool-doc/?id={$final_doc_id}" );
			if ( $gen_id ) {
				$url .= "&gen={$gen_id}";
			}
			$url .= '&preview=true';
		}

		return [
			'content'        => wp_json_encode( $schema ),
			'content_format' => 'json',
			'title'          => $title,
			'data'           => [
				'doc_id'   => $final_doc_id,
				'gen_id'   => $gen_id,
				'doc_type' => $doc_type,
				'url'      => $url,
				'schema'   => $schema,
			],
		];
	}

	/**
	 * Create a blank Doc Studio project to hold the generated document.
	 *
	 * @param array  $skeleton  Skeleton JSON (for title extraction).
	 * @param string $doc_type  document | presentation | spreadsheet
	 * @return int|WP_Error  Doc ID on success.
	 */
	private static function create_doc_project( array $skeleton, string $doc_type ) {
		$title = $skeleton['nucleus']['title'] ?? '';
		if ( empty( $title ) ) {
			$type_labels = [
				'document'     => 'Tài liệu',
				'presentation' => 'Thuyết trình',
				'spreadsheet'  => 'Bảng tính',
			];
			$title = ( $type_labels[ $doc_type ] ?? 'Tài liệu' ) . ' từ Notebook';
		}

		$request = new \WP_REST_Request( 'POST' );
		$request->set_param( 'doc_type', $doc_type );
		$request->set_param( 'title', $title );

		$result = BZDoc_Rest_API::handle_project_create( $request );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$data = $result->get_data();
		return $data['doc_id'] ?? 0;
	}

	/**
	 * Transfer Notebook sources into the Doc Studio project.
	 *
	 * Strategy: Clone existing sources + embeddings from notebook tables into Doc Studio tables.
	 * This avoids re-embedding — user gets immediate RAG support in Doc Studio.
	 *
	 * Clone order:
	 *   1. bizcity_rces (BCN canonical) → bzdoc_project_sources + bzdoc_project_source_chunks
	 *   2. bizcity_webchat_sources (research-imported) → bzdoc_project_sources + bzdoc_project_source_chunks
	 *   3. Skeleton JSON → bzdoc_project_sources as a "package" text source
	 *
	 * @param int    $doc_id    Doc Studio project ID.
	 * @param array  $skeleton  Skeleton JSON.
	 * @param string $raw_text  Raw concatenated source text (legacy fallback).
	 */
	private static function transfer_sources( int $doc_id, array $skeleton, string $raw_text ): void {
		global $wpdb;

		$project_id = $skeleton['project_id'] ?? '';
		$user_id    = get_current_user_id();
		$doc_table  = $wpdb->prefix . 'bzdoc_project_sources';
		$doc_chunks = $wpdb->prefix . 'bzdoc_project_source_chunks';
		$cloned     = 0;
		$chunks_cloned = 0;

		/* ── Step 1: Clone from bizcity_rces (BCN canonical sources with embeddings) ── */
		if ( $project_id && class_exists( 'BCN_Schema_Extend' ) ) {
			$bcn_table  = BCN_Schema_Extend::table_sources();
			$bcn_chunks = BCN_Schema_Extend::table_source_chunks();

			$bcn_sources = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, title, source_type, source_url, content_text, content_hash,
				        char_count, token_estimate, chunk_count, embedding_model, embedding_status
				 FROM {$bcn_table}
				 WHERE project_id = %s AND status = 'ready'
				 ORDER BY created_at ASC",
				$project_id
			) );

			foreach ( $bcn_sources ?: [] as $src ) {
				$wpdb->insert( $doc_table, [
					'doc_id'           => $doc_id,
					'user_id'          => $user_id,
					'title'            => $src->title,
					'source_type'      => $src->source_type ?: 'text',
					'source_url'       => $src->source_url ?: '',
					'content_text'     => $src->content_text,
					'content_hash'     => $src->content_hash ?: '',
					'char_count'       => (int) $src->char_count,
					'token_estimate'   => (int) $src->token_estimate,
					'chunk_count'      => (int) $src->chunk_count,
					'embedding_model'  => $src->embedding_model ?: '',
					'embedding_status' => $src->embedding_status === 'done' ? 'done' : 'pending',
					'status'           => 'ready',
					'created_at'       => current_time( 'mysql' ),
				] );
				$new_source_id = (int) $wpdb->insert_id;
				$cloned++;

				// Clone chunks (with embeddings!) if source was embedded
				if ( $new_source_id && (int) $src->chunk_count > 0 ) {
					$old_chunks = $wpdb->get_results( $wpdb->prepare(
						"SELECT chunk_index, content, token_count, embedding, embedding_model
						 FROM {$bcn_chunks}
						 WHERE source_id = %d
						 ORDER BY chunk_index ASC",
						(int) $src->id
					) );

					foreach ( $old_chunks ?: [] as $chunk ) {
						$wpdb->insert( $doc_chunks, [
							'source_id'       => $new_source_id,
							'doc_id'          => $doc_id,
							'chunk_index'     => (int) $chunk->chunk_index,
							'content'         => $chunk->content,
							'token_count'     => (int) $chunk->token_count,
							'embedding'       => $chunk->embedding,
							'embedding_model' => $chunk->embedding_model ?: '',
							'created_at'      => current_time( 'mysql' ),
						] );
						$chunks_cloned++;
					}
				}
			}
		}

		/* ── Step 2: Clone from bizcity_webchat_sources (research-imported, no chunks) ── */
		if ( $project_id ) {
			$wcs_table = $wpdb->prefix . 'bizcity_webchat_sources';
			$wcs_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$wcs_table}'" );

			if ( $wcs_exists ) {
				// Check if project_id column exists
				$has_proj = $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM information_schema.COLUMNS
					 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'project_id'",
					$wcs_table
				) );

				if ( $has_proj ) {
					$url_col = $wpdb->get_var( $wpdb->prepare(
						"SELECT COUNT(*) FROM information_schema.COLUMNS
						 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'source_url'",
						$wcs_table
					) ) ? 'source_url' : 'url';

					$content_col = $wpdb->get_var( $wpdb->prepare(
						"SELECT COUNT(*) FROM information_schema.COLUMNS
						 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'content_text'",
						$wcs_table
					) ) ? 'content_text' : 'content';

					// Check for webchat_source_chunks table
					$wcs_chunks_table = $wpdb->prefix . 'bizcity_webchat_source_chunks';
					$wcs_has_chunks = $wpdb->get_var( "SHOW TABLES LIKE '{$wcs_chunks_table}'" );

					$wcs_sources = $wpdb->get_results( $wpdb->prepare(
						"SELECT id, title, source_type, {$url_col} AS source_url,
						        {$content_col} AS content_text,
						        IFNULL(embedding_status, 'pending') AS embedding_status,
						        IFNULL(embedding_model, '') AS embedding_model,
						        IFNULL(chunk_count, 0) AS chunk_count
						 FROM {$wcs_table}
						 WHERE project_id = %s
						 ORDER BY created_at ASC",
						$project_id
					) );

					foreach ( $wcs_sources ?: [] as $src ) {
						$content = $src->content_text ?: '';
						if ( mb_strlen( $content ) < 10 ) continue; // Skip empty

						$src_chunk_count  = (int) $src->chunk_count;
						$src_embed_model  = $src->embedding_model ?: '';
						$src_embed_status = $src->embedding_status;

						$wpdb->insert( $doc_table, [
							'doc_id'           => $doc_id,
							'user_id'          => $user_id,
							'title'            => $src->title ?: 'Webchat Source',
							'source_type'      => $src->source_type ?: 'text',
							'source_url'       => $src->source_url ?: '',
							'content_text'     => $content,
							'content_hash'     => hash( 'sha256', $content ),
							'char_count'       => mb_strlen( $content ),
							'token_estimate'   => (int) ( mb_strlen( $content ) / 4 ),
							'chunk_count'      => $src_chunk_count,
							'embedding_model'  => $src_embed_model,
							'embedding_status' => $src_embed_status === 'done' ? 'done' : 'pending',
							'status'           => 'ready',
							'created_at'       => current_time( 'mysql' ),
						] );
						$new_source_id = (int) $wpdb->insert_id;
						$cloned++;

						// Clone chunks from bizcity_webchat_source_chunks (with embeddings!)
						if ( $new_source_id && $wcs_has_chunks && $src_chunk_count > 0 ) {
							$old_chunks = $wpdb->get_results( $wpdb->prepare(
								"SELECT chunk_index, content, token_count, embedding, embedding_model
								 FROM {$wcs_chunks_table}
								 WHERE source_id = %d
								 ORDER BY chunk_index ASC",
								(int) $src->id
							) );

							foreach ( $old_chunks ?: [] as $chunk ) {
								$wpdb->insert( $doc_chunks, [
									'source_id'       => $new_source_id,
									'doc_id'          => $doc_id,
									'chunk_index'     => (int) $chunk->chunk_index,
									'content'         => $chunk->content,
									'token_count'     => (int) $chunk->token_count,
									'embedding'       => $chunk->embedding,
									'embedding_model' => $chunk->embedding_model ?: '',
									'created_at'      => current_time( 'mysql' ),
								] );
								$chunks_cloned++;
							}
						}
					}
				}
			}
		}

		/* ── Step 3: Skeleton JSON as package source ── */
		$structured = self::skeleton_to_structured_source( $skeleton );
		if ( mb_strlen( $structured ) >= 10 ) {
			$wpdb->insert( $doc_table, [
				'doc_id'           => $doc_id,
				'user_id'          => $user_id,
				'title'            => 'Notebook — Dàn ý & Ghi nhớ',
				'source_type'      => 'text',
				'source_url'       => '',
				'content_text'     => $structured,
				'content_hash'     => hash( 'sha256', $structured ),
				'char_count'       => mb_strlen( $structured ),
				'token_estimate'   => (int) ( mb_strlen( $structured ) / 4 ),
				'chunk_count'      => 0,
				'embedding_model'  => '',
				'embedding_status' => 'pending',
				'status'           => 'ready',
				'created_at'       => current_time( 'mysql' ),
			] );
			$cloned++;
		}

		/* ── Legacy fallback: raw text if no sources cloned ── */
		if ( $cloned === 0 && $raw_text && mb_strlen( $raw_text ) >= 10 ) {
			$content = mb_substr( $raw_text, 0, 500000 );
			$wpdb->insert( $doc_table, [
				'doc_id'           => $doc_id,
				'user_id'          => $user_id,
				'title'            => 'Notebook — Nguồn tài liệu gốc',
				'source_type'      => 'text',
				'source_url'       => '',
				'content_text'     => $content,
				'content_hash'     => hash( 'sha256', $content ),
				'char_count'       => mb_strlen( $content ),
				'token_estimate'   => (int) ( mb_strlen( $content ) / 4 ),
				'chunk_count'      => 0,
				'embedding_model'  => '',
				'embedding_status' => 'pending',
				'status'           => 'ready',
				'created_at'       => current_time( 'mysql' ),
			] );
			$cloned++;
		}

		error_log( "[BZDoc] transfer_sources: doc_id={$doc_id}, project_id={$project_id}, "
			. "sources_cloned={$cloned}, chunks_cloned={$chunks_cloned}" );
	}

	/**
	 * Convert skeleton into a readable structured text for source storage.
	 * This preserves notes, key points, entities, decisions as a reference
	 * document that Doc Studio can use for future RAG-based edits.
	 */
	private static function skeleton_to_structured_source( array $skeleton ): string {
		$parts = [];

		$nucleus = $skeleton['nucleus'] ?? [];
		if ( ! empty( $nucleus['title'] ) ) {
			$parts[] = '# ' . $nucleus['title'];
		}
		if ( ! empty( $nucleus['thesis'] ) ) {
			$parts[] = $nucleus['thesis'];
		}

		if ( ! empty( $skeleton['key_points'] ) ) {
			$parts[] = "\n## Điểm chính";
			foreach ( $skeleton['key_points'] as $kp ) {
				$text = is_array( $kp ) ? ( $kp['text'] ?? $kp['point'] ?? '' ) : (string) $kp;
				if ( $text ) {
					$parts[] = '- ' . $text;
				}
			}
		}

		if ( ! empty( $skeleton['skeleton'] ) ) {
			$parts[] = "\n## Dàn ý";
			foreach ( $skeleton['skeleton'] as $node ) {
				$label   = is_array( $node ) ? ( $node['label'] ?? $node['heading'] ?? '' ) : (string) $node;
				$summary = is_array( $node ) ? ( $node['summary'] ?? '' ) : '';
				$parts[] = '### ' . $label;
				if ( $summary ) {
					$parts[] = $summary;
				}
				foreach ( ( is_array( $node ) ? ( $node['children'] ?? [] ) : [] ) as $child ) {
					$clabel = is_array( $child ) ? ( $child['label'] ?? $child['text'] ?? '' ) : (string) $child;
					if ( $clabel ) {
						$parts[] = '- ' . $clabel;
					}
				}
			}
		}

		if ( ! empty( $skeleton['entities'] ) ) {
			$parts[] = "\n## Thực thể";
			foreach ( array_slice( $skeleton['entities'], 0, 15 ) as $e ) {
				$name = is_array( $e ) ? ( $e['name'] ?? '' ) : (string) $e;
				$role = is_array( $e ) ? ( $e['role'] ?? '' ) : '';
				if ( $name ) {
					$parts[] = '- ' . $name . ( $role ? " ({$role})" : '' );
				}
			}
		}

		if ( ! empty( $skeleton['decisions'] ) ) {
			$parts[] = "\n## Quyết định";
			foreach ( array_slice( $skeleton['decisions'], 0, 10 ) as $d ) {
				$text = is_array( $d ) ? ( $d['text'] ?? $d['decision'] ?? '' ) : (string) $d;
				if ( $text ) {
					$parts[] = '- ' . $text;
				}
			}
		}

		return implode( "\n", $parts );
	}

	/**
	 * Convert skeleton JSON to a topic string + separate source text.
	 *
	 * Returns ['topic' => short structured outline, 'source_text' => long raw content].
	 * The topic stays under MAX_TOPIC (2000), source text goes into source_context_override.
	 */
	private static function skeleton_to_topic( array $skeleton, string $doc_type ): array {
		$parts = [];

		// Nucleus — title + thesis.
		$nucleus = $skeleton['nucleus'] ?? [];
		if ( ! empty( $nucleus['title'] ) ) {
			$parts[] = 'Chủ đề: ' . $nucleus['title'];
		}
		if ( ! empty( $nucleus['thesis'] ) ) {
			$parts[] = 'Luận điểm chính: ' . $nucleus['thesis'];
		}
		if ( ! empty( $nucleus['domain'] ) ) {
			$parts[] = 'Lĩnh vực: ' . $nucleus['domain'];
		}

		// Skeleton tree → document structure / outline.
		if ( ! empty( $skeleton['skeleton'] ) ) {
			$outline_label = $doc_type === 'presentation' ? 'Dàn ý slide' : 'Dàn ý tài liệu';
			$parts[] = "\n{$outline_label}:";
			foreach ( $skeleton['skeleton'] as $node ) {
				$label   = is_array( $node ) ? ( $node['label'] ?? $node['heading'] ?? '' ) : (string) $node;
				$summary = is_array( $node ) ? ( $node['summary'] ?? '' ) : '';
				$parts[] = '- ' . $label . ( $summary ? ': ' . $summary : '' );

				foreach ( ( is_array( $node ) ? ( $node['children'] ?? [] ) : [] ) as $child ) {
					$clabel   = is_array( $child ) ? ( $child['label'] ?? $child['text'] ?? '' ) : (string) $child;
					$csummary = is_array( $child ) ? ( $child['summary'] ?? '' ) : '';
					if ( $clabel ) {
						$parts[] = '  - ' . $clabel . ( $csummary ? ': ' . $csummary : '' );
					}
				}
			}
		}

		// Key points.
		if ( ! empty( $skeleton['key_points'] ) ) {
			$parts[] = "\nĐiểm chính:";
			foreach ( $skeleton['key_points'] as $kp ) {
				$text = is_array( $kp ) ? ( $kp['text'] ?? $kp['point'] ?? '' ) : (string) $kp;
				if ( $text ) {
					$parts[] = '- ' . $text;
				}
			}
		}

		// Entities.
		if ( ! empty( $skeleton['entities'] ) ) {
			$names = array_map( function ( $e ) {
				return is_array( $e ) ? ( $e['name'] ?? '' ) : (string) $e;
			}, $skeleton['entities'] );
			$names = array_filter( $names );
			if ( $names ) {
				$parts[] = "\nThực thể liên quan: " . implode( ', ', array_slice( $names, 0, 10 ) );
			}
		}

		// Decisions (useful for documents).
		if ( ! empty( $skeleton['decisions'] ) && $doc_type === 'document' ) {
			$parts[] = "\nQuyết định:";
			foreach ( array_slice( $skeleton['decisions'], 0, 8 ) as $d ) {
				$text = is_array( $d ) ? ( $d['text'] ?? $d['decision'] ?? '' ) : (string) $d;
				if ( $text ) {
					$parts[] = '- ' . $text;
				}
			}
		}

		// Timeline (useful for presentations).
		if ( ! empty( $skeleton['timeline'] ) && $doc_type === 'presentation' ) {
			$parts[] = "\nTimeline:";
			foreach ( $skeleton['timeline'] as $t ) {
				$label = is_array( $t ) ? ( $t['label'] ?? '' ) : (string) $t;
				$desc  = is_array( $t ) ? ( $t['description'] ?? '' ) : '';
				if ( $label ) {
					$parts[] = '- ' . $label . ( $desc ? ': ' . $desc : '' );
				}
			}
		}

		$topic = implode( "\n", $parts );

		// Raw source text goes into source_context_override (separate from topic).
		$raw_text = $skeleton['_raw_text'] ?? '';
		if ( empty( $raw_text ) && class_exists( 'BCN_Studio_Input_Builder' ) ) {
			$raw_text = BCN_Studio_Input_Builder::to_text( $skeleton );
		}
		$source_text = '';
		if ( $raw_text ) {
			$max_raw = $doc_type === 'spreadsheet' ? 8000 : 16000;
			$source_text = mb_substr( $raw_text, 0, $max_raw );
		}

		// Fallback: if nothing structured, use raw text as topic (truncated to fit).
		if ( empty( trim( $topic ) ) ) {
			$topic = mb_substr( $raw_text ?: '', 0, 1800 );
		}

		return [
			'topic'       => $topic,
			'source_text' => $source_text,
		];
	}

	/**
	 * Guess the best template based on skeleton content.
	 */
	private static function guess_template( array $skeleton, string $doc_type ): string {
		if ( $doc_type !== 'document' ) {
			return 'blank';
		}

		$domain = strtolower( $skeleton['nucleus']['domain'] ?? '' );
		$title  = strtolower( $skeleton['nucleus']['title'] ?? '' );

		// Simple heuristic — map domain/title keywords to templates.
		if ( strpos( $title, 'proposal' ) !== false || strpos( $title, 'đề xuất' ) !== false ) {
			return 'proposal';
		}
		if ( strpos( $title, 'report' ) !== false || strpos( $title, 'báo cáo' ) !== false ) {
			return 'report';
		}
		if ( strpos( $title, 'contract' ) !== false || strpos( $title, 'hợp đồng' ) !== false ) {
			return 'contract';
		}

		return 'blank';
	}

	/**
	 * Extract a display title from the generated schema.
	 */
	private static function extract_title( $schema, string $doc_type ): string {
		if ( ! is_array( $schema ) ) {
			return 'Untitled';
		}

		switch ( $doc_type ) {
			case 'presentation':
				return $schema['presentation_title'] ?? 'Presentation';
			case 'spreadsheet':
				return $schema['metadata']['title'] ?? 'Spreadsheet';
			default:
				return $schema['metadata']['title'] ?? 'Document';
		}
	}
}
