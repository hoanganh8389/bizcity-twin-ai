<?php
/**
 * Bizcity Twin AI — KG Passage File Store (Phase 0.7 Wave F1).
 *
 * Append-oriented store for passage text content + frontmatter, sharded into
 * 1000-passage Markdown files under `notebooks/{uuid}/passages/`. Pair with
 * the kg_passages MySQL thin index (file_shard / file_offset / file_length
 * added by `migrate_v024_filestore_columns`) for O(1) random access by id.
 *
 * Concurrency: `flock(LOCK_EX)` per shard file. Two writers targeting
 * different shards do not block each other.
 *
 * Write path:
 *   $store->write($passage_id, $uuid, $frontmatter, $body)
 *     1. Compute shard_idx = floor(id / SHARD_SIZE)
 *     2. Open shard file in append mode under LOCK_EX
 *     3. Record current size = base_offset
 *     4. Encode + fwrite the record
 *     5. Return {shard, file_offset = base_offset + body_offset, file_length}
 *
 * The caller (BizCity_KG_Source_Service / BizCity_KG_Embedding_Writer hook) is
 * responsible for UPDATE-ing kg_passages.file_shard/offset/length/storage_ver=2
 * AFTER fwrite returns success.
 *
 * Read path:
 *   $store->read($passage_id, $uuid, $file_shard, $file_offset, $file_length)
 *     uses the index from MySQL → fseek + fread → no parse cost.
 *
 *   $store->read_with_scan($passage_id, $uuid)
 *     fallback when offset map is missing (cold migration / corruption recovery).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge\KG_Hub\Filestore
 * @since      2026-05-20  (PHASE-0.7-LEARN-VECTOR-FILE Wave F1)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_KG_Passage_File_Store {

	const SHARD_SIZE = 1000;

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Shard index for a given passage id (deterministic).
	 */
	public static function shard_for( $passage_id ) {
		return (int) floor( max( 0, (int) $passage_id ) / self::SHARD_SIZE );
	}

	/**
	 * Format shard filename: 00000-00999.md, 01000-01999.md, ...
	 */
	public static function shard_filename( $shard_idx ) {
		$shard_idx = max( 0, (int) $shard_idx );
		$start = $shard_idx * self::SHARD_SIZE;
		$end   = $start + self::SHARD_SIZE - 1;
		return sprintf( '%05d-%05d.md', $start, $end );
	}

	/**
	 * Absolute path to the shard file (creates parent dir on first call).
	 *
	 * @return string|WP_Error
	 */
	public function shard_path( $notebook_uuid, $shard_idx ) {
		$folder = BizCity_KG_Notebook_Folder::instance()->passages_dir( 'notebooks', $notebook_uuid );
		if ( is_wp_error( $folder ) ) { return $folder; }
		return $folder . self::shard_filename( $shard_idx );
	}

	// -------------------------------------------------------------------
	// Write
	// -------------------------------------------------------------------

	/**
	 * Append a passage record to its shard. Returns the index entry for MySQL.
	 *
	 * @param int    $passage_id
	 * @param string $notebook_uuid
	 * @param array  $frontmatter
	 * @param string $body
	 * @return array|WP_Error  { shard, file_offset, file_length, bytes_written }
	 */
	public function write( $passage_id, $notebook_uuid, array $frontmatter, $body ) {
		$shard = self::shard_for( $passage_id );
		$path  = $this->shard_path( $notebook_uuid, $shard );
		if ( is_wp_error( $path ) ) { return $path; }

		// Inject id into frontmatter if caller omitted (defensive — readers expect it).
		if ( ! isset( $frontmatter['id'] ) ) {
			$frontmatter = array_merge( [ 'id' => (int) $passage_id ], $frontmatter );
		}

		$enc = BizCity_KG_MD_Parser::encode( $frontmatter, (string) $body );
		$fh  = @fopen( $path, 'cb' ); // 'c' = create-or-open, no truncate.
		if ( ! $fh ) {
			return new WP_Error( 'kg_passage_open', 'cannot open shard ' . $path );
		}
		if ( ! flock( $fh, LOCK_EX ) ) {
			@fclose( $fh );
			return new WP_Error( 'kg_passage_lock', 'flock failed on ' . $path );
		}
		// Seek to end (append) — `c` mode does not auto-seek.
		fseek( $fh, 0, SEEK_END );
		$base_offset = ftell( $fh );
		$bytes_written = fwrite( $fh, $enc['bytes'] );
		fflush( $fh );
		flock( $fh, LOCK_UN );
		fclose( $fh );

		if ( false === $bytes_written ) {
			return new WP_Error( 'kg_passage_write', 'fwrite failed' );
		}

		return [
			'shard'        => $shard,
			'file_offset'  => (int) ( $base_offset + $enc['body_offset'] ),
			'file_length'  => (int) $enc['body_length'],
			'bytes_written' => (int) $bytes_written,
		];
	}

	// -------------------------------------------------------------------
	// Read
	// -------------------------------------------------------------------

	/**
	 * O(1) body read using the MySQL offset index.
	 *
	 * @param int    $shard
	 * @param int    $offset    file_offset column value
	 * @param int    $length    file_length column value
	 * @return string|WP_Error  body bytes (NOT frontmatter)
	 */
	public function read_body( $notebook_uuid, $shard, $offset, $length ) {
		$path = $this->shard_path( $notebook_uuid, $shard );
		if ( is_wp_error( $path ) ) { return $path; }
		if ( ! file_exists( $path ) ) {
			return new WP_Error( 'kg_passage_missing', 'shard file missing: ' . $path );
		}
		$fh = @fopen( $path, 'rb' );
		if ( ! $fh ) { return new WP_Error( 'kg_passage_open', 'cannot open ' . $path ); }
		fseek( $fh, (int) $offset, SEEK_SET );
		$body = fread( $fh, (int) $length );
		fclose( $fh );
		return ( false === $body ) ? new WP_Error( 'kg_passage_read', 'fread failed' ) : $body;
	}

	/**
	 * Sequential scan fallback — find a record by id without the offset index.
	 * Used during migration verification + corruption recovery. O(shard_size).
	 *
	 * @return array|WP_Error  { frontmatter, body, file_offset, file_length }
	 */
	public function read_with_scan( $passage_id, $notebook_uuid ) {
		$shard = self::shard_for( $passage_id );
		$path  = $this->shard_path( $notebook_uuid, $shard );
		if ( is_wp_error( $path ) ) { return $path; }
		if ( ! file_exists( $path ) ) {
			return new WP_Error( 'kg_passage_missing', 'shard file missing: ' . $path );
		}
		$bytes = @file_get_contents( $path );
		if ( false === $bytes ) {
			return new WP_Error( 'kg_passage_read', 'cannot read shard' );
		}
		$pos = 0;
		while ( null !== ( $rec = BizCity_KG_MD_Parser::decode_at( $bytes, $pos ) ) ) {
			if ( isset( $rec['frontmatter']['id'] ) && (int) $rec['frontmatter']['id'] === (int) $passage_id ) {
				return [
					'frontmatter' => $rec['frontmatter'],
					'body'        => $rec['body'],
					'file_offset' => $rec['body_offset'],
					'file_length' => $rec['body_length'],
				];
			}
			$pos = $rec['end_pos'];
		}
		return new WP_Error( 'kg_passage_not_found', 'passage ' . $passage_id . ' not in shard ' . $shard );
	}

	/**
	 * Mark a passage as superseded by appending a tombstone record. Compaction
	 * (Wave F4) will rewrite the shard skipping tombstoned ids.
	 */
	public function tombstone( $passage_id, $notebook_uuid ) {
		return $this->write( $passage_id, $notebook_uuid,
			[ '_tombstone' => 1, 'id' => (int) $passage_id ],
			''
		);
	}
}
