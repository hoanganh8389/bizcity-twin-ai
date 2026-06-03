<?php
/**
 * Bizcity Twin AI — KG_Embedding_Writer (Phase 0.21 Wave 2)
 *
 * Single chokepoint for persisting embeddings into KG storage.
 *
 * Per PHASE-0-RULE-VECTOR-FILE-STORE.md §3:
 *   "Mọi nơi sinh embedding PHẢI đi qua BizCity_KG_Embedding_Writer::store()"
 *
 * MVP scope (Wave 2): only chunk embeddings produce a `.bin` side-effect.
 * Entity / relation embeddings flow through pass-through methods so we have
 * a single migration point later (Wave 3+ if entity/relation .bin needed).
 *
 * v2.0 semantics (FILESTORE-ONLY, 2026-05-10):
 *   1) `.bin` file is the SINGLE source of truth. Caller MUST insert row
 *      into `kg_passages` with `'embedding' => null` (column slated for DROP).
 *   2) After insert, callers invoke register_chunk() which appends the vector to
 *      {scope}/{uuid}.bin and updates the companion .idx.json.
 *
 * Failures in .bin path are LOGGED + RETURNED as WP_Error — caller decides whether
 * to surface as user-facing error or fail soft. There is NO JSON fallback.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge\KG_Hub
 * @since      2026-05-06
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_KG_Embedding_Writer {

	private static $instance = null;

	/** Per-request resolution cache: notebook_id → uuid (avoid repeat SELECT). */
	private static $notebook_uuid_cache = [];

	/** Emit failure to BOTH error_log and TwinDebug (visible even with WP_DEBUG_LOG=false). */
	private function fail( $code, $message, array $ctx = [] ) {
		$line = '[BizCity_KG_Embedding_Writer] ' . $code . ': ' . $message;
		if ( ! empty( $ctx ) ) {
			$line .= ' ' . wp_json_encode( $ctx );
		}
		error_log( $line );
		if ( class_exists( 'BizCity_Twin_Debug' ) ) {
			BizCity_Twin_Debug::trace( 'kg', 'bin_append_failed', array_merge( [ 'code' => $code, 'message' => $message ], $ctx ) );
		}
		return new WP_Error( $code, $message );
	}

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	// -------------------------------------------------------------------
	// Chunk embedding — appends to .bin
	// -------------------------------------------------------------------

	/**
	 * Register a chunk vector AFTER caller has inserted the row into kg_passages.
	 *
	 * @param int        $notebook_id     0 if scope is character-only
	 * @param int        $chunk_id        kg_passages.id (auto-increment) — required
	 * @param float[]    $vector
	 * @param string|null $character_uuid NULL for in-house notebook scope
	 * @param int|null   $source_id       optional; recorded in idx.json payload
	 * @return true|WP_Error
	 */
	public function register_chunk( $notebook_id, $chunk_id, $vector, $character_uuid = null, $source_id = null ) {
		$chunk_id = (int) $chunk_id;
		if ( $chunk_id <= 0 ) {
			return $this->fail( 'kg_writer_chunk_id', 'invalid chunk_id', [ 'chunk_id' => $chunk_id ] );
		}
		if ( ! is_array( $vector ) || empty( $vector ) ) {
			return $this->fail( 'kg_writer_vec', 'invalid vector', [ 'chunk_id' => $chunk_id ] );
		}
		if ( ! function_exists( 'bizcity_kg_vector_bin_path' ) ) {
			return $this->fail( 'kg_writer_no_helper', 'bizcity_kg_vector_bin_path() unavailable' );
		}
		if ( ! class_exists( 'BizCity_KG_Vector_File_Store' ) ) {
			return $this->fail( 'kg_writer_no_store', 'BizCity_KG_Vector_File_Store not loaded' );
		}

		// Resolve scope → uuid → path.
		if ( $character_uuid && preg_match( '/^[0-9a-f-]{36}$/i', $character_uuid ) ) {
			$kind = 'gurus';
			$uuid = strtolower( $character_uuid );
		} else {
			$nb = (int) $notebook_id;
			if ( $nb <= 0 ) {
				return $this->fail( 'kg_writer_scope', 'need notebook_id or character_uuid', [ 'chunk_id' => $chunk_id ] );
			}
			$uuid = $this->resolve_notebook_uuid( $nb );
			if ( is_wp_error( $uuid ) ) {
				return $this->fail( $uuid->get_error_code(), $uuid->get_error_message(), [ 'chunk_id' => $chunk_id, 'notebook_id' => $nb ] );
			}
			$kind = 'notebooks';
		}

		$abs = bizcity_kg_vector_bin_path( $kind, $uuid );
		if ( ! $abs ) {
			return $this->fail( 'kg_writer_path', 'cannot resolve .bin path', [ 'kind' => $kind, 'uuid' => $uuid, 'chunk_id' => $chunk_id ] );
		}

		$idx_row = [ 'chunk_id' => $chunk_id ];
		if ( null !== $source_id ) { $idx_row['source_id'] = (int) $source_id; }

		$store = BizCity_KG_Vector_File_Store::instance();
		$res   = $store->append( $abs, [ array_map( 'floatval', $vector ) ], [ $idx_row ] );
		if ( is_wp_error( $res ) ) {
			return $this->fail(
				$res->get_error_code(),
				'append FAILED: ' . $res->get_error_message(),
				[ 'kind' => $kind, 'uuid' => $uuid, 'chunk_id' => $chunk_id, 'path' => $abs ]
			);
		}

		// Trace log so we can confirm dual-write is firing in production logs.
		if ( class_exists( 'BizCity_Twin_Debug' ) ) {
			BizCity_Twin_Debug::trace( 'kg', 'bin_appended', [
				'scope'     => $kind,
				'uuid'      => $uuid,
				'chunk_id'  => $chunk_id,
				'source_id' => null === $source_id ? 0 : (int) $source_id,
				'dim'       => count( $vector ),
				'path'      => str_replace( ABSPATH, '', $abs ),
			] );
		}
		return true;
	}

	// -------------------------------------------------------------------
	// Entity / Relation — pass-through (Wave 2 MVP: JSON only, no .bin)
	// -------------------------------------------------------------------

	/**
	 * Pass-through entry point for entity vectors. Currently a no-op .bin-side
	 * (entities stay in JSON column). Kept so call sites have ONE writer to
	 * migrate later without code churn.
	 *
	 * @return true
	 */
	public function register_entity( $notebook_id, $entity_id, $vector, $character_uuid = null ) {
		// Reserved for Wave 3+ if we move entities/relations to .bin.
		// Today: JSON column is sole storage.
		unset( $notebook_id, $entity_id, $vector, $character_uuid );
		return true;
	}

	/**
	 * Pass-through entry point for relation vectors. See register_entity().
	 * @return true
	 */
	public function register_relation( $notebook_id, $relation_id, $vector, $character_uuid = null ) {
		unset( $notebook_id, $relation_id, $vector, $character_uuid );
		return true;
	}

	// -------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------

	/**
	 * Look up notebook UUID by id (per-request cached).
	 * @return string|WP_Error
	 */
	private function resolve_notebook_uuid( $notebook_id ) {
		$nb_id = (int) $notebook_id;
		if ( isset( self::$notebook_uuid_cache[ $nb_id ] ) ) {
			return self::$notebook_uuid_cache[ $nb_id ];
		}
		if ( ! class_exists( 'BizCity_KG_Database' ) ) {
			return new WP_Error( 'kg_writer_no_db', 'BizCity_KG_Database not loaded' );
		}
		global $wpdb;
		$tbl  = BizCity_KG_Database::instance()->tbl_notebooks();
		$uuid = $wpdb->get_var( $wpdb->prepare( "SELECT uuid FROM {$tbl} WHERE id = %d", $nb_id ) );
		if ( ! $uuid ) {
			return new WP_Error( 'kg_writer_no_uuid', 'notebook ' . $nb_id . ' has no uuid (run schema migration v0.21)' );
		}
		$uuid = strtolower( (string) $uuid );
		self::$notebook_uuid_cache[ $nb_id ] = $uuid;
		return $uuid;
	}

	/** Test/CLI use. */
	public function flush_uuid_cache() {
		self::$notebook_uuid_cache = [];
	}
}
