<?php
/**
 * Bizcity Twin AI — KG Entity File Store (Phase 0.7 Wave F2).
 *
 * Persists graph nodes (kg_entities) into `notebooks/{uuid}/entities.jsonl`.
 * Pair with kg_entities.jsonl_line (added by `migrate_v024_filestore_columns`)
 * to map entity_id → line index without a scan.
 *
 * Storage layout per line (compact JSON, UTF-8):
 *   {"id":4521,"name":"FS 369I","name_normalized":"fs 369i","type":"Product",
 *    "desc":"...","aliases":["FS-369I"],"weight":12,"status":"approved",
 *    "cuuid":null,"meta":{...},"deleted_at":null}
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge\KG_Hub\Filestore
 * @since      2026-05-20  (PHASE-0.7-LEARN-VECTOR-FILE Wave F2)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_KG_Entity_File_Store {

	private static $instance = null;
	/** Per-request memo: notebook_uuid => [entity_id => row]. */
	private $cache = [];

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function path( $notebook_uuid ) {
		return BizCity_KG_Notebook_Folder::instance()->entities_jsonl( 'notebooks', $notebook_uuid );
	}

	/**
	 * Append one entity record. Returns the assigned 0-based line index.
	 */
	public function append( $entity_id, $notebook_uuid, array $row ) {
		$path = $this->path( $notebook_uuid );
		if ( is_wp_error( $path ) ) { return $path; }
		$row['id'] = (int) $entity_id;
		$line = BizCity_KG_JSONL_Stream::append( $path, $row );
		if ( is_wp_error( $line ) ) { return $line; }
		// Invalidate read cache for this notebook.
		unset( $this->cache[ $notebook_uuid ] );
		return $line;
	}

	/**
	 * Read all entities for a notebook (cached per-request). Tombstoned entries
	 * (rows with `_tombstone:1` or non-null `deleted_at`) are filtered out.
	 *
	 * @return array<int,array> indexed by entity id
	 */
	public function read_all( $notebook_uuid ) {
		if ( isset( $this->cache[ $notebook_uuid ] ) ) {
			return $this->cache[ $notebook_uuid ];
		}
		$path = $this->path( $notebook_uuid );
		if ( is_wp_error( $path ) || ! file_exists( $path ) ) {
			return $this->cache[ $notebook_uuid ] = [];
		}
		$out = [];
		foreach ( BizCity_KG_JSONL_Stream::iterate( $path ) as $entry ) {
			$row = $entry['row'];
			if ( ! empty( $row['_tombstone'] ) || ! empty( $row['deleted_at'] ) ) { continue; }
			if ( isset( $row['id'] ) ) {
				$row['_line_index'] = $entry['line_index'];
				$out[ (int) $row['id'] ] = $row;
			}
		}
		return $this->cache[ $notebook_uuid ] = $out;
	}

	/**
	 * Single-entity read. Uses `jsonl_line` index when provided (O(file/64KB)
	 * read until that line), else falls back to full-cache load.
	 */
	public function read( $entity_id, $notebook_uuid, $jsonl_line = null ) {
		if ( null !== $jsonl_line ) {
			$path = $this->path( $notebook_uuid );
			if ( is_wp_error( $path ) ) { return null; }
			$row = BizCity_KG_JSONL_Stream::read_line( $path, (int) $jsonl_line );
			if ( is_array( $row ) && isset( $row['id'] ) && (int) $row['id'] === (int) $entity_id ) {
				return $row;
			}
		}
		$all = $this->read_all( $notebook_uuid );
		return $all[ (int) $entity_id ] ?? null;
	}

	/**
	 * Soft delete — append tombstone marker. Compaction rewrites the file.
	 */
	public function tombstone( $entity_id, $notebook_uuid ) {
		return $this->append( $entity_id, $notebook_uuid, [
			'_tombstone' => 1,
			'deleted_at' => gmdate( 'c' ),
		] );
	}

	public function flush_cache( $notebook_uuid = null ) {
		if ( null === $notebook_uuid ) { $this->cache = []; }
		else { unset( $this->cache[ $notebook_uuid ] ); }
	}
}
