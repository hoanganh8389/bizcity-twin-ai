<?php
/**
 * Bizcity Twin AI — JSONL Stream (Phase 0.7 Wave F2).
 *
 * Minimal append-only writer + line iterator for JSONL files (one JSON
 * object per line, UTF-8, LF-terminated).
 *
 * Used by BizCity_KG_Entity_File_Store and BizCity_KG_Relation_File_Store
 * to persist graph nodes/edges as `entities.jsonl` / `relations.jsonl`
 * under each notebook folder.
 *
 * Line indices map 1:1 with vector rows in the companion `.embed.bin` file:
 *   line 0 ↔ vec row 0
 * Tombstoned lines stay in place to preserve alignment; compaction (Wave F4)
 * rewrites both jsonl + bin in lockstep.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge\KG_Hub\Filestore
 * @since      2026-05-20  (PHASE-0.7-LEARN-VECTOR-FILE Wave F2)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_KG_JSONL_Stream {

	/**
	 * Append one record. Returns the 0-based line index of the appended line.
	 *
	 * @param string $path  Absolute file path. Created if missing.
	 * @param array  $row   JSON-encodable map. Will be wp_json_encode'd.
	 * @return int|WP_Error line index
	 */
	public static function append( $path, array $row ) {
		$line = wp_json_encode( $row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( false === $line ) {
			return new WP_Error( 'kg_jsonl_encode', 'json_encode failed' );
		}
		$dir = dirname( $path );
		if ( ! file_exists( $dir ) ) { wp_mkdir_p( $dir ); }

		$fh = @fopen( $path, 'cb' );
		if ( ! $fh ) { return new WP_Error( 'kg_jsonl_open', 'cannot open ' . $path ); }
		if ( ! flock( $fh, LOCK_EX ) ) {
			@fclose( $fh );
			return new WP_Error( 'kg_jsonl_lock', 'flock failed on ' . $path );
		}
		fseek( $fh, 0, SEEK_END );
		$pos = ftell( $fh );

		// Compute line index = number of \n already in file. For an empty file the
		// new line gets index 0. We count by reading the whole buffer once; this is
		// O(file_size) on append — acceptable for the steady-state (~11K entities).
		$line_index = self::count_lines_via_fh( $fh, $pos );

		$bytes = fwrite( $fh, $line . "\n" );
		fflush( $fh );
		flock( $fh, LOCK_UN );
		fclose( $fh );
		if ( false === $bytes ) {
			return new WP_Error( 'kg_jsonl_write', 'fwrite failed' );
		}
		return $line_index;
	}

	/**
	 * Iterate every non-tombstoned line.
	 *
	 * @return Generator yields ['line_index'=>int, 'row'=>array]
	 */
	public static function iterate( $path ) {
		if ( ! file_exists( $path ) ) { return; }
		$fh = @fopen( $path, 'rb' );
		if ( ! $fh ) { return; }
		$idx = 0;
		while ( ! feof( $fh ) ) {
			$ln = fgets( $fh );
			if ( false === $ln ) { break; }
			$ln = rtrim( $ln, "\r\n" );
			if ( '' === $ln ) { $idx++; continue; }
			$row = json_decode( $ln, true );
			if ( is_array( $row ) ) {
				yield [ 'line_index' => $idx, 'row' => $row ];
			}
			$idx++;
		}
		fclose( $fh );
	}

	/**
	 * Read line at specific 0-based index (linear scan; for hot paths the
	 * file-store classes cache an id→line map on disk).
	 *
	 * @return array|null
	 */
	public static function read_line( $path, $line_index ) {
		foreach ( self::iterate( $path ) as $entry ) {
			if ( $entry['line_index'] === $line_index ) {
				return $entry['row'];
			}
		}
		return null;
	}

	private static function count_lines_via_fh( $fh, $end_pos ) {
		if ( $end_pos <= 0 ) { return 0; }
		fseek( $fh, 0, SEEK_SET );
		$count = 0;
		$buf_size = 65536;
		while ( ! feof( $fh ) ) {
			$buf = fread( $fh, $buf_size );
			if ( false === $buf ) { break; }
			$count += substr_count( $buf, "\n" );
		}
		fseek( $fh, $end_pos, SEEK_SET );
		return $count;
	}
}
