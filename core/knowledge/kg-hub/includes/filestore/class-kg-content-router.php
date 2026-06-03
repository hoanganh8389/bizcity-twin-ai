<?php
/**
 * Bizcity Twin AI — KG Content Router (Phase 0.7 Wave F3).
 *
 * Single read-path entry for code that wants passage body / entity description
 * / relation text. Picks file (storage_ver=2) or MySQL inline (storage_ver=1)
 * transparently so the caller stops caring about migration phase.
 *
 * Designed to be added to existing SELECTs with minimal change:
 *
 *   $sql = "SELECT p.id, p.content, p.storage_ver, p.file_shard,
 *                  p.file_offset, p.file_length, p.notebook_id, ...
 *             FROM {$tp} p WHERE ...";
 *   $rows = $wpdb->get_results( $sql, ARRAY_A );
 *   BizCity_KG_Content_Router::instance()->hydrate_passages( $rows );
 *   // now $rows[$i]['content'] is guaranteed populated from file when v2.
 *
 * The router NEVER writes — that's the dispatcher's job. It also keeps a
 * per-request memoisation so two retrievers asking for the same passage in
 * one request only hit disk once.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge\KG_Hub\Filestore
 * @since      2026-05-20  (PHASE-0.7-LEARN-VECTOR-FILE Wave F3)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_KG_Content_Router {

	private static $instance = null;

	/** Per-request memo: passage_id => body string. */
	private $passage_cache = [];
	/** notebook_id => uuid (avoid hammering wp_options inside loops). */
	private $uuid_cache = [];

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	// ─────────────────────────────────────────────────────────────────
	// Passage
	// ─────────────────────────────────────────────────────────────────

	/**
	 * Resolve body for a single passage row. Mutates and returns the body string.
	 *
	 * Required keys on $row when storage_ver=2: id, notebook_id, file_shard,
	 * file_offset, file_length. Falls back to inline `content` column otherwise.
	 *
	 * @param array $row  ARRAY_A row from kg_passages
	 * @return string     body text (never null — empty string on hard failure)
	 */
	public function passage_body( array $row ) {
		$id = isset( $row['id'] )         ? (int) $row['id']         : 0;
		$sv = isset( $row['storage_ver'] ) ? (int) $row['storage_ver'] : 1;

		if ( $id > 0 && isset( $this->passage_cache[ $id ] ) ) {
			return $this->passage_cache[ $id ];
		}

		// V2 path — read from shard file using offset map.
		if ( $sv === 2 && isset( $row['file_shard'], $row['file_offset'], $row['file_length'], $row['notebook_id'] ) ) {
			$uuid = $this->notebook_uuid( (int) $row['notebook_id'] );
			if ( $uuid ) {
				$body = BizCity_KG_Passage_File_Store::instance()->read_body(
					$uuid,
					(int) $row['file_shard'],
					(int) $row['file_offset'],
					(int) $row['file_length']
				);
				if ( is_string( $body ) ) {
					if ( $id > 0 ) { $this->passage_cache[ $id ] = $body; }
					return $body;
				}
				// File read failed → fall through to inline if present, else log.
				error_log( '[KG Router] passage ' . $id . ' file read failed: ' . ( is_wp_error( $body ) ? $body->get_error_message() : 'unknown' ) );
			}
		}

		// V1 fallback — inline content column.
		$inline = isset( $row['content'] ) ? (string) $row['content'] : '';
		if ( $id > 0 ) { $this->passage_cache[ $id ] = $inline; }
		return $inline;
	}

	/**
	 * Bulk-hydrate an array of passage rows (in-place). After this call every
	 * row's `content` key is the canonical body — file when v2, inline when v1.
	 *
	 * @param array $rows  Modified in place.
	 * @param string $key  Output key name. Default 'content'.
	 */
	public function hydrate_passages( array &$rows, $key = 'content' ) {
		if ( empty( $rows ) ) { return; }
		foreach ( $rows as $i => $r ) {
			if ( ! is_array( $r ) ) { continue; }
			$rows[ $i ][ $key ] = $this->passage_body( $r );
		}
	}

	// ─────────────────────────────────────────────────────────────────
	// Entity / Relation — single-row read by id (used by admin UI).
	// ─────────────────────────────────────────────────────────────────

	public function entity_description( $entity_id, $notebook_id, $storage_ver = 1, $jsonl_line = null, $inline_fallback = '' ) {
		if ( (int) $storage_ver === 2 && $notebook_id > 0 ) {
			$uuid = $this->notebook_uuid( (int) $notebook_id );
			if ( $uuid ) {
				$row = BizCity_KG_Entity_File_Store::instance()->read( (int) $entity_id, $uuid, $jsonl_line );
				if ( is_array( $row ) && isset( $row['desc'] ) ) {
					return (string) $row['desc'];
				}
			}
		}
		return (string) $inline_fallback;
	}

	/**
	 * Bulk-hydrate entity rows. Replaces `description` (and optionally `aliases`)
	 * with the canonical value from the JSONL file when storage_ver=2.
	 *
	 * Required keys per row: id, notebook_id, storage_ver. Optional: jsonl_line.
	 *
	 * @param array $rows           Modified in place.
	 * @param bool  $with_aliases   When true, also overwrite aliases (JSON-encoded string).
	 */
	public function hydrate_entities( array &$rows, $with_aliases = false ) {
		if ( empty( $rows ) ) { return; }
		foreach ( $rows as $i => $r ) {
			if ( ! is_array( $r ) ) { continue; }
			$sv = isset( $r['storage_ver'] ) ? (int) $r['storage_ver'] : 1;
			if ( $sv !== 2 ) { continue; }
			$nb = isset( $r['notebook_id'] ) ? (int) $r['notebook_id'] : 0;
			$id = isset( $r['id'] ) ? (int) $r['id'] : 0;
			if ( $nb <= 0 || $id <= 0 ) { continue; }
			$uuid = $this->notebook_uuid( $nb );
			if ( ! $uuid ) { continue; }
			$line = isset( $r['jsonl_line'] ) ? (int) $r['jsonl_line'] : null;
			$file_row = BizCity_KG_Entity_File_Store::instance()->read( $id, $uuid, $line );
			if ( ! is_array( $file_row ) ) { continue; }
			if ( array_key_exists( 'description', $rows[ $i ] ) || isset( $file_row['desc'] ) ) {
				$rows[ $i ]['description'] = (string) ( $file_row['desc'] ?? '' );
			}
			if ( $with_aliases ) {
				$rows[ $i ]['aliases'] = wp_json_encode(
					isset( $file_row['aliases'] ) && is_array( $file_row['aliases'] ) ? $file_row['aliases'] : []
				);
			}
		}
	}

	public function relation_text( $relation_id, $notebook_id, $storage_ver = 1, $jsonl_line = null, $inline_fallback = '' ) {
		if ( (int) $storage_ver === 2 && $notebook_id > 0 ) {
			$uuid = $this->notebook_uuid( (int) $notebook_id );
			if ( $uuid ) {
				$row = BizCity_KG_Relation_File_Store::instance()->read( (int) $relation_id, $uuid, $jsonl_line );
				if ( is_array( $row ) && isset( $row['relation_text'] ) ) {
					return (string) $row['relation_text'];
				}
			}
		}
		return (string) $inline_fallback;
	}

	/**
	 * Bulk-hydrate relation rows. Replaces `relation_text` with file value when v2.
	 * Required keys: id, notebook_id, storage_ver. Optional: jsonl_line.
	 */
	public function hydrate_relations( array &$rows ) {
		if ( empty( $rows ) ) { return; }
		foreach ( $rows as $i => $r ) {
			if ( ! is_array( $r ) ) { continue; }
			$sv = isset( $r['storage_ver'] ) ? (int) $r['storage_ver'] : 1;
			if ( $sv !== 2 ) { continue; }
			$nb = isset( $r['notebook_id'] ) ? (int) $r['notebook_id'] : 0;
			$id = isset( $r['id'] ) ? (int) $r['id'] : 0;
			if ( $nb <= 0 || $id <= 0 ) { continue; }
			$uuid = $this->notebook_uuid( $nb );
			if ( ! $uuid ) { continue; }
			$line = isset( $r['jsonl_line'] ) ? (int) $r['jsonl_line'] : null;
			$file_row = BizCity_KG_Relation_File_Store::instance()->read( $id, $uuid, $line );
			if ( ! is_array( $file_row ) ) { continue; }
			$rows[ $i ]['relation_text'] = (string) ( $file_row['relation_text'] ?? '' );
		}
	}

	// ─────────────────────────────────────────────────────────────────
	// Internals
	// ─────────────────────────────────────────────────────────────────

	private function notebook_uuid( $notebook_id ) {
		if ( $notebook_id <= 0 ) { return null; }
		if ( isset( $this->uuid_cache[ $notebook_id ] ) ) {
			return $this->uuid_cache[ $notebook_id ];
		}
		$uuid = BizCity_KG_Notebook_Folder::instance()->notebook_uuid( $notebook_id );
		if ( is_wp_error( $uuid ) ) {
			$this->uuid_cache[ $notebook_id ] = null;
			return null;
		}
		return $this->uuid_cache[ $notebook_id ] = (string) $uuid;
	}

	public function flush_cache() {
		$this->passage_cache = [];
		$this->uuid_cache    = [];
	}
}
