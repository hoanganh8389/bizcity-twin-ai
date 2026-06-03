<?php
/**
 * Bizcity Twin AI — KG Relation File Store (Phase 0.7 Wave F2).
 *
 * Same pattern as BizCity_KG_Entity_File_Store but for graph edges. Persists
 * kg_relations rows into `notebooks/{uuid}/relations.jsonl`.
 *
 * Line schema (compact JSON):
 *   {"id":17893,"head":4521,"tail":4522,"predicate":"sells_to",
 *    "predicate_normalized":"sells_to","relation_text":"FS 369I sells_to Anh Tuấn",
 *    "weight":3,"confidence":0.92,"status":"approved","meta":{...},
 *    "deleted_at":null}
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge\KG_Hub\Filestore
 * @since      2026-05-20  (PHASE-0.7-LEARN-VECTOR-FILE Wave F2)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_KG_Relation_File_Store {

	private static $instance = null;
	private $cache = [];

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function path( $notebook_uuid ) {
		return BizCity_KG_Notebook_Folder::instance()->relations_jsonl( 'notebooks', $notebook_uuid );
	}

	public function append( $relation_id, $notebook_uuid, array $row ) {
		$path = $this->path( $notebook_uuid );
		if ( is_wp_error( $path ) ) { return $path; }
		$row['id'] = (int) $relation_id;
		$line = BizCity_KG_JSONL_Stream::append( $path, $row );
		if ( is_wp_error( $line ) ) { return $line; }
		unset( $this->cache[ $notebook_uuid ] );
		return $line;
	}

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

	public function read( $relation_id, $notebook_uuid, $jsonl_line = null ) {
		if ( null !== $jsonl_line ) {
			$path = $this->path( $notebook_uuid );
			if ( is_wp_error( $path ) ) { return null; }
			$row = BizCity_KG_JSONL_Stream::read_line( $path, (int) $jsonl_line );
			if ( is_array( $row ) && isset( $row['id'] ) && (int) $row['id'] === (int) $relation_id ) {
				return $row;
			}
		}
		$all = $this->read_all( $notebook_uuid );
		return $all[ (int) $relation_id ] ?? null;
	}

	public function tombstone( $relation_id, $notebook_uuid ) {
		return $this->append( $relation_id, $notebook_uuid, [
			'_tombstone' => 1,
			'deleted_at' => gmdate( 'c' ),
		] );
	}

	public function flush_cache( $notebook_uuid = null ) {
		if ( null === $notebook_uuid ) { $this->cache = []; }
		else { unset( $this->cache[ $notebook_uuid ] ); }
	}
}
