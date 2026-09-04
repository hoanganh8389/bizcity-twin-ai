<?php
/**
 * Bizcity TwinChat — Notes Service (self-contained).
 *
 * Port of the archived `BCN_Notes` (Companion Notebook) so TwinChat no longer
 * depends on `plugins/_archived/bizcity-companion-notebook/`. Reads/writes the
 * encrypted business filestore. The former `bizcity_twinchat_notes` and
 * `bizcity_memory_notes` SQL projections are not runtime note sources.
 *
 * Public surface intentionally mirrors what `class-twinchat-notes-controller.php`
 * calls on `BCN_Notes`:
 *   - create( array $data ) : int|WP_Error
 *   - update( int $id, array $data ) : bool
 *   - delete( int $id ) : bool
 *   - get_by_project( string $project_id ) : array
 *   - search_by_keyword( string $project_id, string $keyword, int $limit = 10 ) : array
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Modules\TwinChat\Notes
 * @since      Phase 0.7 — Wave Note-Port-In-Module
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_TwinChat_Notes_Service {

	// [2026-08-28 Johnny Chu] R-FILESTORE-BUSINESS — canonical encrypted business-record contract for TwinChat notes.
	const BUSINESS_CONTRACT_ID = 'modules.twinchat.memory_notes';
	const ALLOWED_TYPES = [ 'manual', 'chat_pinned', 'auto_pinned', 'studio_generated', 'research_auto' ];

	// ── CRUD ───────────────────────────────────────────────────────────

	public function create( array $data ) {
		$project_id = sanitize_text_field( $data['project_id'] ?? '' );
		$title      = sanitize_text_field( $data['title'] ?? '' );
		$content    = wp_kses_post( $data['content'] ?? '' );
		$note_type  = sanitize_text_field( $data['note_type'] ?? 'manual' );

		if ( ! in_array( $note_type, self::ALLOWED_TYPES, true ) ) {
			$note_type = 'manual';
		}
		if ( ! $title ) {
			$title = mb_substr( wp_strip_all_tags( $content ), 0, 80 ) ?: 'Ghi chú';
		}

		$session_id = sanitize_text_field( $data['session_id'] ?? '' );
		$user_id    = get_current_user_id();
		// [2026-08-28 Johnny Chu] R-FILESTORE-BUSINESS — create notes in encrypted JSONL and return a stable integer-compatible virtual ID.
		if ( self::is_filestore_available() ) {
			$record_id = self::new_record_id();
			$note_id   = self::virtual_note_id( $record_id );
			$record    = array(
				'record_id'  => $record_id,
				'id'         => $note_id,
				'legacy_id'  => $note_id,
				'blog_id'    => get_current_blog_id(),
				'user_id'    => $user_id,
				'project_id' => $project_id,
				'session_id' => $session_id,
				'message_id' => absint( $data['message_id'] ?? 0 ) ?: 0,
				'title'      => $title,
				'content'    => $content,
				'source_excerpt' => sanitize_textarea_field( $data['source_excerpt'] ?? '' ),
				'tags'       => wp_json_encode( $data['tags'] ?? array() ),
				'created_by' => sanitize_key( $data['created_by'] ?? 'user' ),
				'note_type'  => $note_type,
				'is_starred' => ! empty( $data['is_starred'] ) ? 1 : 0,
				'metadata'   => wp_json_encode( $data['metadata'] ?? array() ),
				'created_at' => current_time( 'mysql' ),
				'updated_at' => current_time( 'mysql' ),
			);
			$receipt = BizCity_Business_JSONL_File_Store::write_with_receipt( self::BUSINESS_CONTRACT_ID, $record, 'upsert' );
			if ( ! is_array( $receipt ) ) {
				return new WP_Error( 'filestore_error', 'Could not create note in the business filestore.' );
			}
			do_action( 'bcn_note_created', $note_id, $project_id );
			$record['filestore_receipt'] = $receipt;
			do_action( 'bizcity_memory_mirror_write', 'note', $record, 'insert' );
			return $note_id;
		}

		// [2026-09-01 Johnny Chu] PHASE-CB4.4 — Context Bank filestore is the only new note payload writer; SQL fallback is disabled.
		return new WP_Error( 'context_bank_filestore_required', 'Note filestore is unavailable; SQL payload fallback is disabled.' );
	}

	public function update( $id, array $data ): bool {
		$user_id = get_current_user_id();

		// [2026-08-28 Johnny Chu] R-FILESTORE-BUSINESS — update the folded note record without a legacy SQL payload fallback.
		if ( self::is_filestore_available() ) {
			$persisted = self::find_filestore_note( (int) $id, $user_id );
			if ( empty( $persisted ) ) {
				return false;
			}
			$record = self::normalize_filestore_note( $persisted );
			if ( isset( $data['title'] ) ) {
				$record['title'] = sanitize_text_field( $data['title'] );
			}
			if ( isset( $data['content'] ) ) {
				$record['content'] = wp_kses_post( $data['content'] );
			}
			if ( isset( $data['is_starred'] ) ) {
				$record['is_starred'] = (int) $data['is_starred'];
			}
			$record['updated_at'] = current_time( 'mysql' );
			$receipt = BizCity_Business_JSONL_File_Store::write_with_receipt( self::BUSINESS_CONTRACT_ID, $record, 'upsert' );
			if ( ! is_array( $receipt ) ) {
				return false;
			}
			$project_id = (string) ( $record['project_id'] ?? '' );
			if ( $project_id !== '' ) {
				do_action( 'bcn_note_updated', (int) $id, $project_id );
			}
			$record['filestore_receipt'] = $receipt;
			do_action( 'bizcity_memory_mirror_write', 'note', $record, 'update' );
			return true;
		}

		// [2026-09-01 Johnny Chu] PHASE-CB4.4 — do not update a legacy SQL note when the canonical filestore is unavailable.
		return false;
	}

	public function delete( $id ): bool {
		$user_id = get_current_user_id();

		// [2026-08-28 Johnny Chu] R-FILESTORE-BUSINESS — delete the canonical note with an append-only tombstone.
		if ( self::is_filestore_available() ) {
			$persisted = self::find_filestore_note( (int) $id, $user_id );
			if ( empty( $persisted ) ) {
				return false;
			}
			$project_id = (string) ( $persisted['project_id'] ?? '' );
			$record_id  = (string) ( $persisted['record_id'] ?? '' );
			$delete_receipt = $record_id === '' ? false : BizCity_Business_JSONL_File_Store::delete_with_receipt( self::BUSINESS_CONTRACT_ID, $record_id, array( 'blog_id' => get_current_blog_id(), 'user_id' => $user_id ) );
			if ( ! is_array( $delete_receipt ) ) {
				return false;
			}
			if ( $project_id !== '' ) {
				do_action( 'bcn_note_deleted', (int) $id, $project_id );
			}
			do_action( 'bizcity_memory_mirror_delete', 'note', (int) $id, array( 'blog_id' => get_current_blog_id(), 'user_id' => $user_id, 'record_id' => $record_id, 'filestore_receipt' => $delete_receipt ) );
			return true;
		}

		// [2026-09-01 Johnny Chu] PHASE-CB4.4 — do not delete a legacy SQL note when the canonical filestore is unavailable.
		return false;
	}

	public function get_by_project( $project_id ): array {
		// [2026-08-28 Johnny Chu] R-FILESTORE-BUSINESS — project reader uses the folded business filestore as source of truth.
		if ( self::is_filestore_available() ) {
			return self::filestore_notes( array( 'project_id' => (string) $project_id ) );
		}
		// [2026-09-01 Johnny Chu] PHASE-CB-CH-LOG-RETIRE — no SQL note projection fallback is allowed.
		return array();
	}

	public function search_by_keyword( $project_id, $keyword, $limit = 10 ): array {
		// [2026-08-28 Johnny Chu] R-FILESTORE-BUSINESS — keyword search filters title/content/tags in the folded file read model.
		if ( self::is_filestore_available() ) {
			$keyword = trim( (string) $keyword );
			$rows = self::filestore_notes( array( 'project_id' => (string) $project_id, 'limit' => max( 1, (int) $limit ), 'keyword' => $keyword ) );
			return $rows;
		}
		// [2026-09-01 Johnny Chu] PHASE-CB-LOG-RETIRE — search is unavailable rather than reopening SQL payload storage.
		return array();
	}

	/**
	 * Fetch ALL notes for a given user (home / Ask Brain context — no notebook filter).
	 * Returns newest-first, capped at $limit.
	 */
	public function get_all_by_user( int $user_id, int $limit = 200 ): array {
		// [2026-08-28 Johnny Chu] R-FILESTORE-BUSINESS — user note context is read from canonical folded records.
		if ( self::is_filestore_available() ) {
			return self::filestore_notes( array( 'user_id' => $user_id, 'limit' => max( 1, min( 500, $limit ) ) ) );
		}
		// [2026-09-01 Johnny Chu] PHASE-CB-LOG-RETIRE — user note context never falls back to SQL.
		return array();
	}

	private function get_project_id( int $note_id ): string {
		if ( self::is_filestore_available() ) {
			$row = self::find_filestore_note( $note_id, get_current_user_id() );
			return (string) ( $row['project_id'] ?? '' );
		}
		// [2026-09-01 Johnny Chu] PHASE-CB-LOG-RETIRE — note linkage is unavailable without the canonical filestore.
		return '';
	}

	// [2026-08-28 Johnny Chu] R-FILESTORE-BUSINESS — resolve the canonical contract availability without loading or creating SQL state.
	private static function is_filestore_available(): bool {
		return class_exists( 'BizCity_File_Contract_Registry' )
			&& class_exists( 'BizCity_Business_JSONL_File_Store' )
			&& BizCity_File_Contract_Registry::has( self::BUSINESS_CONTRACT_ID );
	}


	// [2026-08-28 Johnny Chu] R-FILESTORE-BUSINESS — note IDs stay integer-compatible while canonical identity remains opaque and HMAC-derived.
	private static function new_record_id(): string {
		$seed = (string) get_current_blog_id() . '|' . (string) get_current_user_id() . '|' . (string) microtime( true ) . '|' . wp_rand();
		$key  = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : '';
		if ( class_exists( 'BizCity_Codec' ) && $key !== '' ) {
			return 'nt_' . BizCity_Codec::hmac_sha256( $seed, $key, false );
		}
		return 'nt_' . hash( 'sha256', $seed );
	}

	// [2026-08-28 Johnny Chu] R-FILESTORE-BUSINESS — provide a bounded positive ID for legacy controller contracts.
	private static function virtual_note_id( $record_id ): int {
		$id = abs( (int) crc32( (string) $record_id ) );
		return $id > 0 ? $id : 1;
	}

	// [2026-08-28 Johnny Chu] R-FILESTORE-BUSINESS — normalize notes to the object fields consumed by existing TwinChat controllers.
	private static function normalize_filestore_note( array $row ): array {
		$row = wp_parse_args( $row, array(
			'record_id'      => '',
			'legacy_id'      => 0,
			'blog_id'        => get_current_blog_id(),
			'user_id'        => 0,
			'project_id'     => '',
			'session_id'     => '',
			'message_id'     => 0,
			'title'          => '',
			'content'        => '',
			'source_excerpt' => '',
			'tags'           => '[]',
			'created_by'     => 'user',
			'note_type'      => 'manual',
			'is_starred'     => 0,
			'metadata'       => '{}',
			'created_at'     => '',
			'updated_at'     => '',
		) );
		$row['id']         = (int) ( $row['legacy_id'] ?? 0 );
		$row['user_id']    = (int) $row['user_id'];
		$row['message_id'] = (int) $row['message_id'];
		$row['is_starred'] = (int) $row['is_starred'];
		return $row;
	}

	// [2026-08-28 Johnny Chu] R-FILESTORE-BUSINESS — find by virtual legacy ID and owner without querying the quarantined SQL table.
	private static function find_filestore_note( int $note_id, int $user_id ): array {
		$query = array(
			'blog_id' => get_current_blog_id(),
			'user_id' => $user_id,
			'limit'   => 1000,
			'days'    => 3650,
			'filter'  => function ( $row ) use ( $note_id ) {
				return (int) ( $row['legacy_id'] ?? 0 ) === $note_id;
			},
		);
		// [2026-09-01 Johnny Chu] PHASE-CB4.5 — note ownership lookup follows Context Bank pointers and verified receipts.
		if ( function_exists( 'bizcity_context_bank_load_memory_runtime' ) ) {
			bizcity_context_bank_load_memory_runtime();
		}
		$rows = class_exists( 'BizCity_Context_Bank_Memory_Adapter' )
			? BizCity_Context_Bank_Memory_Adapter::query( self::BUSINESS_CONTRACT_ID, $query )
			: array();
		return isset( $rows[0] ) && is_array( $rows[0] ) ? self::normalize_filestore_note( $rows[0] ) : array();
	}

	// [2026-08-28 Johnny Chu] R-FILESTORE-BUSINESS — shared folded note reader for project, keyword, and user views.
	private static function filestore_notes( array $args = array() ): array {
		$query = array(
			'blog_id' => get_current_blog_id(),
			'limit'   => max( 1, (int) ( $args['limit'] ?? 200 ) * 8 ),
			'days'    => 3650,
		);
		if ( isset( $args['user_id'] ) ) {
			$query['user_id'] = (int) $args['user_id'];
		}
		$query['filter'] = function ( $row ) use ( $args ) {
			if ( isset( $args['project_id'] ) && (string) ( $row['project_id'] ?? '' ) !== (string) $args['project_id'] ) {
				return false;
			}
			if ( ! empty( $args['keyword'] ) ) {
				$keyword = mb_strtolower( (string) $args['keyword'], 'UTF-8' );
				$haystack = mb_strtolower(
					(string) ( $row['title'] ?? '' ) . ' ' . (string) ( $row['content'] ?? '' ) . ' ' . (string) ( $row['tags'] ?? '' ),
					'UTF-8'
				);
				if ( mb_strpos( $haystack, $keyword, 0, 'UTF-8' ) === false ) {
					return false;
				}
			}
			return true;
		};
		// [2026-09-01 Johnny Chu] PHASE-CB4.5 — notes list/search is ledger-pointer scoped; encrypted filestore remains payload source only.
		if ( function_exists( 'bizcity_context_bank_load_memory_runtime' ) ) {
			bizcity_context_bank_load_memory_runtime();
		}
		$rows = class_exists( 'BizCity_Context_Bank_Memory_Adapter' )
			? BizCity_Context_Bank_Memory_Adapter::query( self::BUSINESS_CONTRACT_ID, $query )
			: array();
		$rows = array_map( array( __CLASS__, 'normalize_filestore_note' ), $rows );
		usort( $rows, function ( $a, $b ) {
			return strcmp( (string) ( $b['created_at'] ?? '' ), (string) ( $a['created_at'] ?? '' ) );
		} );
		$rows = array_slice( $rows, 0, max( 1, (int) ( $args['limit'] ?? 200 ) ) );
		return array_map( function ( $row ) { return (object) $row; }, $rows );
	}
}
