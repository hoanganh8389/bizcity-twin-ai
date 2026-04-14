<?php
/**
 * CRUD for bizcity_creator_chunk_meta table.
 *
 * Each chunk links to a studio_output row (actual content).
 * chunk_meta holds presentation metadata + node_status for the pipeline.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BZCC_Chunk_Meta_Manager {

	/* ── Read ── */

	public static function get_by_id( int $id ): ?object {
		global $wpdb;
		$t = BZCC_Installer::table_chunk_meta();

		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ) );
	}

	public static function get_by_file( int $file_id ): array {
		global $wpdb;
		$t = BZCC_Installer::table_chunk_meta();

		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$t} WHERE file_id = %d ORDER BY chunk_index ASC",
			$file_id
		) );
	}

	/**
	 * Get chunks with their studio output content (JOIN).
	 */
	public static function get_by_file_with_content( int $file_id ): array {
		global $wpdb;
		$t_chunk  = BZCC_Installer::table_chunk_meta();
		$t_studio = $wpdb->prefix . 'bizcity_webchat_studio_outputs';

		return $wpdb->get_results( $wpdb->prepare(
			"SELECT c.*, s.content, s.content_format AS format, s.token_count AS word_count, s.status AS studio_status
			 FROM {$t_chunk} c
			 LEFT JOIN {$t_studio} s ON s.id = c.studio_output_id
			 WHERE c.file_id = %d
			 ORDER BY c.chunk_index ASC",
			$file_id
		) );
	}

	/**
	 * Get chunks by node_status.
	 */
	public static function get_by_status( string $node_status, int $limit = 50 ): array {
		global $wpdb;
		$t = BZCC_Installer::table_chunk_meta();

		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$t} WHERE node_status = %s ORDER BY id ASC LIMIT %d",
			$node_status,
			$limit
		) );
	}

	/* ── Write ── */

	public static function insert( array $data ): int {
		global $wpdb;
		$t = BZCC_Installer::table_chunk_meta();

		$data = self::sanitize( $data );
		$data['created_at'] = current_time( 'mysql', true );
		$data['updated_at'] = $data['created_at'];

		/* Truncate fields to fit column limits */
		if ( isset( $data['stage_label'] ) ) {
			$data['stage_label'] = mb_substr( $data['stage_label'], 0, 100 );
		}
		if ( isset( $data['stage_emoji'] ) ) {
			$data['stage_emoji'] = mb_substr( $data['stage_emoji'], 0, 10 );
		}
		if ( isset( $data['platform'] ) ) {
			$data['platform'] = mb_substr( $data['platform'], 0, 50 );
		}
		if ( isset( $data['cta_text'] ) ) {
			$data['cta_text'] = mb_substr( $data['cta_text'], 0, 500 );
		}

		$result = $wpdb->insert( $t, $data );
		if ( false === $result ) {
			error_log( '[BZCC] chunk_meta INSERT failed: ' . $wpdb->last_error . ' | chunk_index=' . ( $data['chunk_index'] ?? '?' ) );
		}
		return (int) $wpdb->insert_id;
	}

	public static function update( int $id, array $data ): bool {
		global $wpdb;
		$t = BZCC_Installer::table_chunk_meta();

		$data = self::sanitize( $data );
		$data['updated_at'] = current_time( 'mysql', true );

		return (bool) $wpdb->update( $t, $data, [ 'id' => $id ] );
	}

	public static function delete( int $id ): bool {
		global $wpdb;
		$t = BZCC_Installer::table_chunk_meta();

		return (bool) $wpdb->delete( $t, [ 'id' => $id ] );
	}

	public static function delete_by_file( int $file_id ): int {
		global $wpdb;
		$t = BZCC_Installer::table_chunk_meta();

		return (int) $wpdb->query( $wpdb->prepare(
			"DELETE FROM {$t} WHERE file_id = %d",
			$file_id
		) );
	}

	/* ── Node status transitions ── */

	/**
	 * Transition node_status with validation.
	 *
	 * Valid transitions:
	 *   pending → generating
	 *   generating → completed | needs_clarify | failed
	 *   needs_clarify → waiting_user
	 *   waiting_user → generating
	 *   completed → edited | regenerating | published
	 *   edited → regenerating | published
	 *   regenerating → completed | failed
	 *   failed → generating (retry)
	 */
	public static function transition_status( int $id, string $new_status ): bool {
		$chunk = self::get_by_id( $id );
		if ( ! $chunk ) {
			return false;
		}

		$allowed = self::allowed_transitions();
		$current = $chunk->node_status;

		if ( ! isset( $allowed[ $current ] ) || ! in_array( $new_status, $allowed[ $current ], true ) ) {
			return false;
		}

		return self::update( $id, [ 'node_status' => $new_status ] );
	}

	/**
	 * Batch-create chunk_meta rows for a file from outline sections.
	 *
	 * @param int   $file_id   The file ID.
	 * @param array $sections  Array of outline sections with index, platform, stage, stage_label, stage_emoji.
	 * @return int Number of rows created.
	 */
	public static function bulk_create_from_outline( int $file_id, array $sections ): int {
		$count = 0;
		foreach ( $sections as $section ) {
			$id = self::insert( [
				'file_id'     => $file_id,
				'chunk_index' => (int) ( $section['index'] ?? $count ),
				'node_status' => 'pending',
				'platform'    => sanitize_text_field( $section['platform'] ?? '' ),
				'stage_label' => sanitize_text_field( $section['stage_label'] ?? '' ),
				'stage_emoji' => sanitize_text_field( $section['stage_emoji'] ?? '' ),
				'hashtags'    => '',
				'cta_text'    => '',
				'notes'       => '',
				'last_prompt' => '',
			] );
			if ( $id ) {
				$count++;
			}
		}
		return $count;
	}

	/* ── Helpers ── */

	private static function allowed_transitions(): array {
		return [
			'pending'       => [ 'generating' ],
			'generating'    => [ 'generating', 'completed', 'needs_clarify', 'failed' ],
			'needs_clarify' => [ 'waiting_user' ],
			'waiting_user'  => [ 'generating' ],
			'completed'     => [ 'edited', 'regenerating', 'published' ],
			'edited'        => [ 'regenerating', 'published' ],
			'regenerating'  => [ 'completed', 'failed' ],
			'failed'        => [ 'generating' ],
		];
	}

	private static function sanitize( array $data ): array {
		$allowed = [
			'file_id', 'studio_output_id', 'chunk_index', 'node_status',
			'platform', 'stage_label', 'stage_emoji',
			'hashtags', 'cta_text', 'image_url', 'image_id',
			'video_url', 'video_id', 'notes', 'edit_count', 'last_prompt',
			'created_at', 'updated_at',
		];

		return array_intersect_key( $data, array_flip( $allowed ) );
	}
}
