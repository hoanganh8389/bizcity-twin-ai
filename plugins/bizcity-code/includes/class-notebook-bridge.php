<?php
/**
 * Notebook Bridge — Register Code Builder with Companion Notebook.
 *
 * Registers 1 tool into BCN_Notebook_Tool_Registry:
 *   code_page → AI-generated web page (HTML/Tailwind)
 *
 * Receives skeleton JSON from Notebook Studio, converts to prompt,
 * and delegates to BZCode_Engine for code generation.
 *
 * @package    BizCity_Code
 * @since      0.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BZCode_Notebook_Bridge {

	/**
	 * Register Code Builder tool with the Notebook Tool Registry.
	 */
	public static function register( $registry ) {
		$registry->add( [
			'type'        => 'code_page',
			'label'       => 'Trang Web',
			'description' => 'Tạo trang web HTML/Tailwind từ nguồn dự án',
			'icon'        => '💻',
			'color'       => 'indigo',
			'category'    => 'code',
			'mode'        => 'delegate',
			'available'   => true,
			'callback'    => [ __CLASS__, 'generate_from_skeleton' ],
		] );
	}

	/**
	 * Convert skeleton JSON → Code Builder generation.
	 *
	 * @param array $skeleton Standard skeleton JSON from BCN_Studio_Input_Builder.
	 * @return array|WP_Error
	 */
	public static function generate_from_skeleton( array $skeleton ) {
		$prompt = self::skeleton_to_prompt( $skeleton );

		if ( empty( trim( $prompt ) ) ) {
			return new \WP_Error( 'no_content', 'Không có nội dung để tạo trang web.' );
		}

		$user_id  = get_current_user_id();
		$raw_text = $skeleton['_raw_text'] ?? '';
		if ( empty( $raw_text ) && class_exists( 'BCN_Studio_Input_Builder' ) ) {
			$raw_text = BCN_Studio_Input_Builder::to_text( $skeleton );
		}

		// Use the Canvas Bridge's synchronous generation pattern.
		$result_code  = '';
		$result_usage = [];

		$result = BZCode_Engine::create(
			[
				'mode'     => 'text',
				'prompt'   => $prompt,
				'images'   => [],
				'stack'    => 'html_tailwind',
				'variants' => 1,
				'user_id'  => $user_id,
				'caller'   => 'notebook',
			],
			// on_chunk
			function ( $vi, $token ) use ( &$result_code ) {
				$result_code .= $token;
			},
			// on_done
			function ( $vi, $code, $usage ) use ( &$result_code, &$result_usage ) {
				$result_code  = $code;
				$result_usage = $usage;
			},
			// on_error
			function ( $vi, $error ) {
				error_log( '[BZCode Notebook Bridge] Generation error: ' . $error );
			}
		);

		if ( empty( $result_code ) ) {
			return new \WP_Error( 'generation_failed', 'Code generation failed.' );
		}

		$project_id = $result['project_id'] ?? 0;

		// Transfer sources from notebook → code project
		if ( $project_id ) {
			self::transfer_sources( $project_id, $skeleton, $raw_text );
		}

		$editor_url = $project_id ? home_url( "/tool-code/project/{$project_id}/" ) : '';
		$title      = $skeleton['nucleus']['title'] ?? 'Web Page';

		return [
			'content'        => $result_code,
			'content_format' => 'html',
			'title'          => $title,
			'data'           => [
				'project_id' => $project_id,
				'url'        => $editor_url,
				'usage'      => $result_usage,
			],
		];
	}

	/**
	 * Transfer sources from Notebook canonical stores → bizcity_code_sources.
	 *
	 * Clones from:
	 *   1. BCN canonical sources (bizcity_rces) with embeddings
	 *   2. Webchat sources (bizcity_webchat_sources) with chunks
	 *   3. Skeleton JSON as structured text source
	 *   4. Fallback: raw_text if no other sources found
	 */
	private static function transfer_sources( int $project_id, array $skeleton, string $raw_text ): void {
		global $wpdb;

		$notebook_project = $skeleton['project_id'] ?? '';
		$user_id          = get_current_user_id();
		$src_table        = $wpdb->prefix . 'bizcity_code_sources';
		$src_chunks       = $wpdb->prefix . 'bizcity_code_source_chunks';
		$cloned           = 0;
		$chunks_cloned    = 0;

		/* ── Step 1: Clone from bizcity_rces (BCN canonical sources with embeddings) ── */
		if ( $notebook_project && class_exists( 'BCN_Schema_Extend' ) ) {
			$bcn_table  = BCN_Schema_Extend::table_sources();
			$bcn_chunks = BCN_Schema_Extend::table_source_chunks();

			$bcn_sources = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, title, source_type, source_url, content_text, content_hash,
				        char_count, token_estimate, chunk_count, embedding_model, embedding_status
				 FROM {$bcn_table}
				 WHERE project_id = %s AND status = 'ready'
				 ORDER BY created_at ASC",
				$notebook_project
			) );

			foreach ( $bcn_sources ?: [] as $src ) {
				$wpdb->insert( $src_table, [
					'project_id'       => $project_id,
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
				$new_id = (int) $wpdb->insert_id;
				$cloned++;

				if ( $new_id && (int) $src->chunk_count > 0 ) {
					$old_chunks = $wpdb->get_results( $wpdb->prepare(
						"SELECT chunk_index, content, token_count, embedding, embedding_model
						 FROM {$bcn_chunks}
						 WHERE source_id = %d
						 ORDER BY chunk_index ASC",
						(int) $src->id
					) );

					foreach ( $old_chunks ?: [] as $chunk ) {
						$wpdb->insert( $src_chunks, [
							'source_id'       => $new_id,
							'project_id'      => $project_id,
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

		/* ── Step 2: Clone from bizcity_webchat_sources ── */
		if ( $notebook_project ) {
			$wcs_table = $wpdb->prefix . 'bizcity_webchat_sources';
			$wcs_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$wcs_table}'" );

			if ( $wcs_exists ) {
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
						$notebook_project
					) );

					foreach ( $wcs_sources ?: [] as $src ) {
						$content = $src->content_text ?: '';
						if ( mb_strlen( $content ) < 10 ) continue;

						$wpdb->insert( $src_table, [
							'project_id'       => $project_id,
							'user_id'          => $user_id,
							'title'            => $src->title ?: 'Webchat Source',
							'source_type'      => $src->source_type ?: 'text',
							'source_url'       => $src->source_url ?: '',
							'content_text'     => $content,
							'content_hash'     => hash( 'sha256', $content ),
							'char_count'       => mb_strlen( $content ),
							'token_estimate'   => (int) ( mb_strlen( $content ) / 4 ),
							'chunk_count'      => (int) $src->chunk_count,
							'embedding_model'  => $src->embedding_model ?: '',
							'embedding_status' => $src->embedding_status === 'done' ? 'done' : 'pending',
							'status'           => 'ready',
							'created_at'       => current_time( 'mysql' ),
						] );
						$new_id = (int) $wpdb->insert_id;
						$cloned++;

						if ( $new_id && $wcs_has_chunks && (int) $src->chunk_count > 0 ) {
							$old_chunks = $wpdb->get_results( $wpdb->prepare(
								"SELECT chunk_index, content, token_count, embedding, embedding_model
								 FROM {$wcs_chunks_table}
								 WHERE source_id = %d
								 ORDER BY chunk_index ASC",
								(int) $src->id
							) );

							foreach ( $old_chunks ?: [] as $chunk ) {
								$wpdb->insert( $src_chunks, [
									'source_id'       => $new_id,
									'project_id'      => $project_id,
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

		/* ── Step 3: Skeleton JSON as structured source ── */
		$structured = self::skeleton_to_structured_source( $skeleton );
		if ( mb_strlen( $structured ) >= 10 ) {
			$wpdb->insert( $src_table, [
				'project_id'       => $project_id,
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

		/* ── Step 4: Fallback — raw text if no sources cloned ── */
		if ( $cloned === 0 && $raw_text && mb_strlen( $raw_text ) >= 10 ) {
			$content = mb_substr( $raw_text, 0, 500000 );
			$wpdb->insert( $src_table, [
				'project_id'       => $project_id,
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

		error_log( "[BZCode] transfer_sources: project_id={$project_id}, notebook_project={$notebook_project}, "
			. "sources_cloned={$cloned}, chunks_cloned={$chunks_cloned}" );
	}

	/**
	 * Convert skeleton to structured text source for storage.
	 */
	private static function skeleton_to_structured_source( array $skeleton ): string {
		$parts   = [];
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
				if ( $text ) $parts[] = '- ' . $text;
			}
		}

		if ( ! empty( $skeleton['skeleton'] ) ) {
			$parts[] = "\n## Cấu trúc";
			foreach ( $skeleton['skeleton'] as $node ) {
				$label = is_array( $node ) ? ( $node['label'] ?? '' ) : (string) $node;
				if ( $label ) $parts[] = '### ' . $label;
				$summary = is_array( $node ) ? ( $node['summary'] ?? '' ) : '';
				if ( $summary ) $parts[] = $summary;
			}
		}

		return implode( "\n", $parts );
	}

	/**
	 * Convert skeleton JSON to a detailed prompt for code generation.
	 */
	private static function skeleton_to_prompt( array $skeleton ): string {
		$parts = [];

		$nucleus = $skeleton['nucleus'] ?? [];
		if ( ! empty( $nucleus['title'] ) ) {
			$parts[] = 'Tạo trang web về: ' . $nucleus['title'];
		}
		if ( ! empty( $nucleus['thesis'] ) ) {
			$parts[] = 'Mô tả: ' . $nucleus['thesis'];
		}

		// Skeleton tree → page sections.
		if ( ! empty( $skeleton['skeleton'] ) ) {
			$parts[] = "\nCấu trúc trang:";
			foreach ( $skeleton['skeleton'] as $node ) {
				$label   = is_array( $node ) ? ( $node['label'] ?? '' ) : (string) $node;
				$summary = is_array( $node ) ? ( $node['summary'] ?? '' ) : '';
				$parts[] = '- ' . $label . ( $summary ? ': ' . $summary : '' );

				foreach ( ( is_array( $node ) ? ( $node['children'] ?? [] ) : [] ) as $child ) {
					$clabel = is_array( $child ) ? ( $child['label'] ?? '' ) : (string) $child;
					if ( $clabel ) {
						$parts[] = '  - ' . $clabel;
					}
				}
			}
		}

		// Key points → feature bullets.
		if ( ! empty( $skeleton['key_points'] ) ) {
			$parts[] = "\nĐiểm nổi bật cần hiển thị:";
			foreach ( $skeleton['key_points'] as $kp ) {
				$text = is_array( $kp ) ? ( $kp['text'] ?? $kp['point'] ?? '' ) : (string) $kp;
				if ( $text ) {
					$parts[] = '- ' . $text;
				}
			}
		}

		// Entities → logos, branding, mentions.
		if ( ! empty( $skeleton['entities'] ) ) {
			$names = array_map( function ( $e ) {
				return is_array( $e ) ? ( $e['name'] ?? '' ) : (string) $e;
			}, $skeleton['entities'] );
			$names = array_filter( $names );
			if ( $names ) {
				$parts[] = "\nThực thể/Thương hiệu: " . implode( ', ', array_slice( $names, 0, 8 ) );
			}
		}

		$prompt = implode( "\n", $parts );

		// Design instruction.
		$prompt .= "\n\nYêu cầu: Tạo trang web đẹp, hiện đại, responsive với HTML + Tailwind CSS. "
		         . "Sử dụng gradient, rounded corners, shadows, spacing hợp lý. "
		         . "Nội dung lấy từ nguồn tài liệu đã được cung cấp. "
		         . "Trang phải hoàn chỉnh, có header, content sections, và footer.";

		return $prompt;
	}
}
