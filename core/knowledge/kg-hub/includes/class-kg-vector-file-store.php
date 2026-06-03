<?php
/**
 * Bizcity Twin AI — KG_Vector_File_Store (Phase 0.21 Wave 2)
 *
 * Binary file-backed vector store for Twin Guru `.bin` bundles.
 *
 * File layout (little-endian):
 *   [0..7]   char[8]  magic = "BZKGVEC1"
 *   [8..11]  uint32   version (currently 1)
 *   [12..15] uint32   dim
 *   [16..23] uint64   count
 *   [24..63] char[40] model_id (null-padded ASCII, e.g. "text-embedding-3-small")
 *   [64..]   float32[count][dim]   row-major vectors
 *
 * Companion file `<path>.idx.json` maps row index → uid (and optional payload):
 *   { "version": 1, "rows": [ { "uid": "...", "chunk_id": 12, "source_id": 3 }, ... ] }
 *
 * Dual-read fallback: callers SHOULD attempt this store first; on missing file
 * or `header_validate()` failure they MUST fall back to legacy JSON `embedding`
 * column on `bizcity_kg_source_chunks` (kept intact during 2-week migration window).
 *
 * SIMD ladder (search()): Tensor ext → FFI binary → Rust shell-out → pure PHP loop.
 * Only the PHP loop is implemented here as the always-available baseline; the
 * accelerated paths plug in via filter `bizcity_kg_vector_search_backend`.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge\KG_Hub
 * @since      2026-05-06
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_KG_Vector_File_Store {

	const MAGIC          = 'BZKGVEC1';
	const VERSION        = 1;
	const HEADER_SIZE    = 64;
	const MODEL_ID_SIZE  = 40;
	const FLOAT_SIZE     = 4;
	const DEFAULT_MODEL  = 'text-embedding-3-small';
	const DEFAULT_DIM    = 1536;

	/** Files larger than this auto-route to search_streaming() to avoid OOM. */
	const STREAMING_THRESHOLD_BYTES = 5242880; // 5 MB
	const STREAMING_BATCH_ROWS      = 1024;

	/** Per-request memo cache keyed by absolute path. */
	private static $cache = [];

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	// -------------------------------------------------------------------
	// Path helpers
	// -------------------------------------------------------------------

	/**
	 * Resolve a relative storage path (DB-portable) to an absolute filesystem path.
	 * Accepts either an already-absolute path or a relative path under the KG storage dir.
	 */
	public function resolve_path( $path ) {
		if ( '' === $path || ! is_string( $path ) ) {
			return new WP_Error( 'kg_bin_bad_path', 'Empty or non-string path' );
		}
		// Already absolute? Trust caller (still routed through helper for traversal check when relative).
		if ( preg_match( '#^([A-Za-z]:[\\\\/]|/)#', $path ) ) {
			return $path;
		}
		if ( ! function_exists( 'bizcity_kg_resolve_path' ) ) {
			return new WP_Error( 'kg_bin_no_helper', 'bizcity_kg_resolve_path() unavailable' );
		}
		return bizcity_kg_resolve_path( $path );
	}

	/**
	 * Companion idx.json path for a vectors.bin path.
	 */
	public function idx_path( $bin_abs_path ) {
		return $bin_abs_path . '.idx.json';
	}

	// -------------------------------------------------------------------
	// Header pack / unpack
	// -------------------------------------------------------------------

	/**
	 * Pack a 64-byte header.
	 *
	 * @param int    $dim
	 * @param int    $count
	 * @param string $model_id
	 * @return string|WP_Error
	 */
	public function pack_header( $dim, $count, $model_id = self::DEFAULT_MODEL ) {
		$dim   = (int) $dim;
		$count = (int) $count;
		if ( $dim <= 0 || $dim > 65536 ) {
			return new WP_Error( 'kg_bin_bad_dim', 'Invalid dim: ' . $dim );
		}
		if ( $count < 0 ) {
			return new WP_Error( 'kg_bin_bad_count', 'Invalid count: ' . $count );
		}
		$model = (string) $model_id;
		if ( strlen( $model ) > self::MODEL_ID_SIZE ) {
			$model = substr( $model, 0, self::MODEL_ID_SIZE );
		}
		$model = str_pad( $model, self::MODEL_ID_SIZE, "\0", STR_PAD_RIGHT );

		// pack: A8 magic, V version (uint32 LE), V dim (uint32 LE), P count (uint64 LE), a40 model
		$header = pack( 'a8VVP', self::MAGIC, self::VERSION, $dim, $count ) . $model;
		if ( strlen( $header ) !== self::HEADER_SIZE ) {
			return new WP_Error( 'kg_bin_header_size', 'Packed header wrong size: ' . strlen( $header ) );
		}
		return $header;
	}

	/**
	 * Read & validate the 64-byte header from $fh (file pointer at offset 0).
	 * Returns assoc array { magic, version, dim, count, model_id } or WP_Error.
	 */
	public function read_header( $fh ) {
		if ( ! is_resource( $fh ) ) {
			return new WP_Error( 'kg_bin_no_handle', 'Invalid file handle' );
		}
		fseek( $fh, 0 );
		$bytes = fread( $fh, self::HEADER_SIZE );
		if ( false === $bytes || strlen( $bytes ) !== self::HEADER_SIZE ) {
			return new WP_Error( 'kg_bin_short_header', 'Could not read 64-byte header' );
		}
		$parts = unpack( 'a8magic/Vversion/Vdim/Pcount', substr( $bytes, 0, 24 ) );
		if ( ! is_array( $parts ) || $parts['magic'] !== self::MAGIC ) {
			return new WP_Error( 'kg_bin_bad_magic', 'Magic mismatch (file is not a BZKGVEC1 vector bin)' );
		}
		if ( (int) $parts['version'] !== self::VERSION ) {
			return new WP_Error( 'kg_bin_bad_version', 'Unsupported version ' . $parts['version'] );
		}
		$model = rtrim( substr( $bytes, 24, self::MODEL_ID_SIZE ), "\0" );
		return [
			'magic'    => $parts['magic'],
			'version'  => (int) $parts['version'],
			'dim'      => (int) $parts['dim'],
			'count'    => (int) $parts['count'],
			'model_id' => $model,
		];
	}

	/**
	 * Open + validate header without reading vectors. Cheap probe used by retrievers.
	 */
	public function header_validate( $path ) {
		$abs = $this->resolve_path( $path );
		if ( is_wp_error( $abs ) ) { return $abs; }
		if ( ! file_exists( $abs ) || ! is_readable( $abs ) ) {
			return new WP_Error( 'kg_bin_missing', 'File missing or unreadable: ' . $abs );
		}
		$size = filesize( $abs );
		if ( $size === false || $size < self::HEADER_SIZE ) {
			return new WP_Error( 'kg_bin_too_small', 'File smaller than header' );
		}
		$fh = fopen( $abs, 'rb' );
		if ( ! $fh ) {
			return new WP_Error( 'kg_bin_open_failed', 'fopen failed' );
		}
		$hdr = $this->read_header( $fh );
		fclose( $fh );
		if ( is_wp_error( $hdr ) ) { return $hdr; }
		// Cross-check expected file size.
		$expected = self::HEADER_SIZE + ( $hdr['count'] * $hdr['dim'] * self::FLOAT_SIZE );
		if ( $size !== $expected ) {
			return new WP_Error(
				'kg_bin_size_mismatch',
				sprintf( 'Size mismatch: file=%d expected=%d (count=%d dim=%d)', $size, $expected, $hdr['count'], $hdr['dim'] )
			);
		}
		$hdr['_path'] = $abs;
		$hdr['_size'] = $size;
		return $hdr;
	}

	// -------------------------------------------------------------------
	// Write / append
	// -------------------------------------------------------------------

	/**
	 * Atomic write: rebuild a .bin from an array of rows.
	 *
	 * @param string $path     relative or absolute target path
	 * @param array  $vectors  list of float[] (each length === $dim)
	 * @param array  $idx_rows list parallel to $vectors: [ ['uid'=>'...','chunk_id'=>123, ...], ... ]
	 * @param array  $meta     { dim, model_id }
	 * @return true|WP_Error
	 */
	public function write( $path, array $vectors, array $idx_rows, array $meta = [] ) {
		$dim      = isset( $meta['dim'] ) ? (int) $meta['dim'] : self::DEFAULT_DIM;
		$model_id = isset( $meta['model_id'] ) ? (string) $meta['model_id'] : self::DEFAULT_MODEL;
		$count    = count( $vectors );

		if ( count( $idx_rows ) !== $count ) {
			return new WP_Error( 'kg_bin_idx_mismatch', 'idx_rows count != vectors count' );
		}

		$abs = $this->resolve_path( $path );
		if ( is_wp_error( $abs ) ) { return $abs; }

		// Ensure parent dir exists.
		$dir = dirname( $abs );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'kg_bin_mkdir_failed', 'Cannot create dir ' . $dir );
		}

		$header = $this->pack_header( $dim, $count, $model_id );
		if ( is_wp_error( $header ) ) { return $header; }

		$tmp    = $abs . '.tmp-' . wp_generate_password( 8, false, false );
		$fh     = fopen( $tmp, 'wb' );
		if ( ! $fh ) {
			return new WP_Error( 'kg_bin_open_write', 'fopen tmp failed: ' . $tmp );
		}
		$wrote = fwrite( $fh, $header );
		if ( $wrote !== self::HEADER_SIZE ) {
			fclose( $fh ); @unlink( $tmp );
			return new WP_Error( 'kg_bin_write_header', sprintf(
				'header write short: wrote=%s expected=%d tmp=%s err=%s',
				var_export( $wrote, true ), self::HEADER_SIZE, $tmp, ( error_get_last()['message'] ?? '' )
			) );
		}
		foreach ( $vectors as $i => $vec ) {
			$packed = $this->pack_vector( $vec, $dim );
			if ( is_wp_error( $packed ) ) {
				fclose( $fh ); @unlink( $tmp );
				return new WP_Error( $packed->get_error_code(), 'row ' . $i . ': ' . $packed->get_error_message() );
			}
			$expected = $dim * self::FLOAT_SIZE;
			$plen     = strlen( $packed );
			$wrote    = fwrite( $fh, $packed );
			if ( $wrote !== $expected ) {
				fclose( $fh ); @unlink( $tmp );
				return new WP_Error( 'kg_bin_write_short', sprintf(
					'short write at row %d: dim=%d expected_bytes=%d packed_bytes=%d fwrite_returned=%s tmp=%s disk_free=%s err=%s',
					$i, $dim, $expected, $plen, var_export( $wrote, true ), $tmp,
					size_format( @disk_free_space( dirname( $tmp ) ) ?: 0 ),
					( error_get_last()['message'] ?? '' )
				) );
			}
		}
		fflush( $fh );
		fclose( $fh );

		// Atomic move.
		if ( ! @rename( $tmp, $abs ) ) {
			@unlink( $tmp );
			return new WP_Error( 'kg_bin_rename_failed', 'rename to ' . $abs );
		}

		// Idx companion.
		$idx_payload = [
			'version'   => 1,
			'count'     => $count,
			'dim'       => $dim,
			'model_id'  => $model_id,
			'generated' => gmdate( 'c' ),
			'rows'      => array_values( $idx_rows ),
		];
		$idx_abs = $this->idx_path( $abs );
		$idx_tmp = $idx_abs . '.tmp-' . wp_generate_password( 8, false, false );
		if ( false === file_put_contents( $idx_tmp, wp_json_encode( $idx_payload, JSON_UNESCAPED_UNICODE ) ) ) {
			return new WP_Error( 'kg_bin_idx_write', 'cannot write idx tmp' );
		}
		if ( ! @rename( $idx_tmp, $idx_abs ) ) {
			@unlink( $idx_tmp );
			return new WP_Error( 'kg_bin_idx_rename', 'cannot rename idx' );
		}

		unset( self::$cache[ $abs ] );
		return true;
	}

	/**
	 * Append vectors to an existing .bin (rewrites header count + appends idx rows).
	 * Falls back to full rewrite if file does not yet exist.
	 *
	 * @return true|WP_Error
	 */
	public function append( $path, array $vectors, array $idx_rows, array $meta = [] ) {
		$abs = $this->resolve_path( $path );
		if ( is_wp_error( $abs ) ) { return $abs; }

		if ( ! file_exists( $abs ) ) {
			return $this->write( $path, $vectors, $idx_rows, $meta );
		}
		if ( count( $vectors ) !== count( $idx_rows ) ) {
			return new WP_Error( 'kg_bin_idx_mismatch', 'idx_rows count != vectors count' );
		}
		if ( empty( $vectors ) ) { return true; }

		$hdr = $this->header_validate( $abs );
		if ( is_wp_error( $hdr ) ) { return $hdr; }
		$dim = $hdr['dim'];

		$fh = fopen( $abs, 'r+b' );
		if ( ! $fh ) { return new WP_Error( 'kg_bin_open_rw', 'fopen r+b failed' ); }
		// Seek to EOF and append rows.
		fseek( $fh, 0, SEEK_END );
		foreach ( $vectors as $i => $vec ) {
			$packed = $this->pack_vector( $vec, $dim );
			if ( is_wp_error( $packed ) ) {
				fclose( $fh );
				return new WP_Error( $packed->get_error_code(), 'append row ' . $i . ': ' . $packed->get_error_message() );
			}
			if ( fwrite( $fh, $packed ) !== ( $dim * self::FLOAT_SIZE ) ) {
				fclose( $fh );
				return new WP_Error( 'kg_bin_write_short', 'short append at row ' . $i );
			}
		}
		// Update count in header (offset 16, uint64 LE).
		$new_count = $hdr['count'] + count( $vectors );
		fseek( $fh, 16 );
		fwrite( $fh, pack( 'P', $new_count ) );
		fflush( $fh );
		fclose( $fh );

		// Update idx.json.
		$idx_abs = $this->idx_path( $abs );
		$idx     = $this->load_idx( $idx_abs );
		if ( is_wp_error( $idx ) ) {
			$idx = [ 'version' => 1, 'count' => 0, 'dim' => $dim, 'model_id' => $hdr['model_id'], 'rows' => [] ];
		}
		$idx['rows']      = array_merge( $idx['rows'], array_values( $idx_rows ) );
		$idx['count']     = $new_count;
		$idx['generated'] = gmdate( 'c' );
		$idx_tmp = $idx_abs . '.tmp-' . wp_generate_password( 8, false, false );
		if ( false === file_put_contents( $idx_tmp, wp_json_encode( $idx, JSON_UNESCAPED_UNICODE ) ) ) {
			return new WP_Error( 'kg_bin_idx_write', 'cannot write idx tmp' );
		}
		if ( ! @rename( $idx_tmp, $idx_abs ) ) {
			@unlink( $idx_tmp );
			return new WP_Error( 'kg_bin_idx_rename', 'cannot rename idx' );
		}

		unset( self::$cache[ $abs ] );
		return true;
	}

	/**
	 * Pack a single vector to little-endian float32 binary.
	 * @return string|WP_Error
	 */
	private function pack_vector( $vec, $dim ) {
		if ( ! is_array( $vec ) ) {
			return new WP_Error( 'kg_bin_vec_type', 'vector not array' );
		}
		if ( count( $vec ) !== $dim ) {
			return new WP_Error( 'kg_bin_vec_dim', 'vector dim ' . count( $vec ) . ' != expected ' . $dim );
		}
		// pack format 'g' = float (machine dependent endian) — use 'e' for little-endian float32 (PHP 7.0.15+).
		// Fallback to 'f' if 'e' not available (very old PHP) — modern WP requires PHP 7.4+ so 'e' is safe.
		$packed = @pack( 'e' . $dim, ...array_map( 'floatval', $vec ) );
		if ( false === $packed || strlen( $packed ) !== ( $dim * self::FLOAT_SIZE ) ) {
			// Try fallback 'g' (LE float, alias) then 'f' (machine endian).
			$packed = @pack( 'g' . $dim, ...array_map( 'floatval', $vec ) );
			if ( false === $packed || strlen( $packed ) !== ( $dim * self::FLOAT_SIZE ) ) {
				$packed = @pack( 'f' . $dim, ...array_map( 'floatval', $vec ) );
			}
			if ( false === $packed || strlen( $packed ) !== ( $dim * self::FLOAT_SIZE ) ) {
				return new WP_Error( 'kg_bin_pack_failed', sprintf(
					'pack(e/g/f%d) failed: got=%s expected=%d php=%s',
					$dim,
					( false === $packed ? 'false' : (string) strlen( $packed ) ),
					$dim * self::FLOAT_SIZE,
					PHP_VERSION
				) );
			}
		}
		return $packed;
	}

	// -------------------------------------------------------------------
	// Read
	// -------------------------------------------------------------------

	/**
	 * Load entire .bin into a typed-array-like PHP structure (per-request memoized).
	 * For huge files (>50MB) caller should prefer streaming search instead.
	 *
	 * @return array{header: array, vectors: array<int, float[]>, idx: array}|WP_Error
	 */
	public function load( $path ) {
		$abs = $this->resolve_path( $path );
		if ( is_wp_error( $abs ) ) { return $abs; }
		if ( isset( self::$cache[ $abs ] ) ) {
			return self::$cache[ $abs ];
		}
		$hdr = $this->header_validate( $abs );
		if ( is_wp_error( $hdr ) ) { return $hdr; }

		$fh = fopen( $abs, 'rb' );
		if ( ! $fh ) { return new WP_Error( 'kg_bin_open_failed', 'fopen failed' ); }
		fseek( $fh, self::HEADER_SIZE );

		$dim     = $hdr['dim'];
		$count   = $hdr['count'];
		$row_len = $dim * self::FLOAT_SIZE;
		$vectors = [];
		for ( $i = 0; $i < $count; $i++ ) {
			$buf = fread( $fh, $row_len );
			if ( false === $buf || strlen( $buf ) !== $row_len ) {
				fclose( $fh );
				return new WP_Error( 'kg_bin_short_row', 'short read at row ' . $i );
			}
			// 2026-05-14 — use 'g' (float32 LE, 4 bytes) to match writer fallback.
			// 'e' on some PHP builds is interpreted as double (8 bytes) → unpack fails silently.
			$unp = self::unpack_vec( $buf, $dim );
			if ( null === $unp ) {
				fclose( $fh );
				return new WP_Error( 'kg_bin_unpack_failed', 'unpack failed at row ' . $i );
			}
			$vectors[] = $unp;
		}
		fclose( $fh );

		$idx = $this->load_idx( $this->idx_path( $abs ) );
		if ( is_wp_error( $idx ) ) {
			$idx = [ 'version' => 1, 'count' => $count, 'dim' => $dim, 'model_id' => $hdr['model_id'], 'rows' => [] ];
		}

		$payload = [ 'header' => $hdr, 'vectors' => $vectors, 'idx' => $idx ];
		self::$cache[ $abs ] = $payload;
		return $payload;
	}

	/**
	 * Read a single row by index.
	 * @return float[]|WP_Error
	 */
	public function read_row( $path, $row_index ) {
		$abs = $this->resolve_path( $path );
		if ( is_wp_error( $abs ) ) { return $abs; }
		$hdr = $this->header_validate( $abs );
		if ( is_wp_error( $hdr ) ) { return $hdr; }
		$row_index = (int) $row_index;
		if ( $row_index < 0 || $row_index >= $hdr['count'] ) {
			return new WP_Error( 'kg_bin_row_oob', 'row ' . $row_index . ' out of range (count=' . $hdr['count'] . ')' );
		}
		$row_len = $hdr['dim'] * self::FLOAT_SIZE;
		$offset  = self::HEADER_SIZE + ( $row_index * $row_len );
		$fh = fopen( $abs, 'rb' );
		if ( ! $fh ) { return new WP_Error( 'kg_bin_open_failed', 'fopen failed' ); }
		fseek( $fh, $offset );
		$buf = fread( $fh, $row_len );
		fclose( $fh );
		if ( false === $buf || strlen( $buf ) !== $row_len ) {
			return new WP_Error( 'kg_bin_short_row', 'short read at row ' . $row_index );
		}
		$unp = self::unpack_vec( $buf, (int) $hdr['dim'] );
		if ( null === $unp ) {
			return new WP_Error( 'kg_bin_unpack_failed', 'unpack failed at row ' . $row_index );
		}
		return $unp;
	}

	/**
	 * Robust unpack — try 'g' (float32 LE, canonical), fallback to 'e' (some PHP
	 * builds treat 'e' as float, others as double), final fallback to 'f' (machine
	 * endian). Returns 0-indexed float array, or null on total failure.
	 *
	 * MUST mirror pack_vector() write fallback chain.
	 *
	 * @param string $buf
	 * @param int    $dim
	 * @return float[]|null
	 */
	private static function unpack_vec( $buf, $dim ) {
		$unp = @unpack( 'g' . $dim, $buf );
		if ( is_array( $unp ) && count( $unp ) === $dim ) {
			return array_values( $unp );
		}
		$unp = @unpack( 'e' . $dim, $buf );
		if ( is_array( $unp ) && count( $unp ) === $dim ) {
			return array_values( $unp );
		}
		$unp = @unpack( 'f' . $dim, $buf );
		if ( is_array( $unp ) && count( $unp ) === $dim ) {
			return array_values( $unp );
		}
		return null;
	}

	/**
	 * Load idx.json companion.
	 * @return array|WP_Error
	 */
	public function load_idx( $idx_abs ) {
		if ( ! file_exists( $idx_abs ) ) {
			return new WP_Error( 'kg_bin_idx_missing', 'idx.json missing: ' . $idx_abs );
		}
		$raw = file_get_contents( $idx_abs );
		if ( false === $raw ) {
			return new WP_Error( 'kg_bin_idx_read', 'cannot read idx.json' );
		}
		$dec = json_decode( $raw, true );
		if ( ! is_array( $dec ) || empty( $dec['rows'] ) || ! is_array( $dec['rows'] ) ) {
			return new WP_Error( 'kg_bin_idx_bad', 'idx.json malformed' );
		}
		return $dec;
	}

	// -------------------------------------------------------------------
	// Search (SIMD ladder)
	// -------------------------------------------------------------------

	/**
	 * Top-K cosine search.
	 *
	 * Resolution order (filterable via `bizcity_kg_vector_search_backend`):
	 *   1. tensor    — ext-tensor (compiled PHP extension), if loaded
	 *   2. ffi       — `vec-search` shared lib via FFI, if available
	 *   3. rust_cli  — shell out to bin/vec-search binary
	 *   4. php       — pure PHP loop (always available)
	 *
	 * @param string  $path
	 * @param float[] $query_vec
	 * @param int     $top_k
	 * @param float   $threshold cosine floor (default 0.0)
	 * @return array<int, array{row:int, uid:?string, score:float, payload:array}>|WP_Error
	 */
	public function search( $path, array $query_vec, $top_k = 10, $threshold = 0.0 ) {
		$top_k = max( 1, (int) $top_k );

		// Cheap probe BEFORE load() — large files route to streaming to avoid OOM.
		$hdr = $this->header_validate( $path );
		if ( is_wp_error( $hdr ) ) { return $hdr; }
		if ( count( $query_vec ) !== $hdr['dim'] ) {
			return new WP_Error( 'kg_bin_query_dim', 'query dim ' . count( $query_vec ) . ' != bin dim ' . $hdr['dim'] );
		}
		if ( $hdr['_size'] > self::STREAMING_THRESHOLD_BYTES ) {
			return $this->search_streaming( $path, $query_vec, $top_k, $threshold );
		}

		$loaded = $this->load( $path );
		if ( is_wp_error( $loaded ) ) { return $loaded; }

		$backend = apply_filters( 'bizcity_kg_vector_search_backend', $this->detect_backend(), $loaded, $query_vec );
		switch ( $backend ) {
			case 'tensor':
			case 'ffi':
			case 'rust_cli':
				/**
				 * Allow accelerated backends to take over. Hook should return a sorted
				 * array of [ ['row'=>i,'score'=>f], ... ] (full list, will be top-k'd here).
				 * Returning null/WP_Error falls through to PHP path.
				 */
				$accel = apply_filters( 'bizcity_kg_vector_search_' . $backend, null, $loaded, $query_vec );
				if ( is_array( $accel ) ) {
					return $this->finalize( $accel, $loaded['idx'], $top_k, $threshold );
				}
				// fallthrough to PHP
		}
		return $this->finalize( $this->search_php( $loaded['vectors'], $query_vec ), $loaded['idx'], $top_k, $threshold );
	}

	/** Detect best available backend at runtime. */
	private function detect_backend() {
		if ( extension_loaded( 'tensor' ) ) { return 'tensor'; }
		if ( extension_loaded( 'ffi' ) && defined( 'BIZCITY_KG_VEC_FFI_LIB' ) ) { return 'ffi'; }
		if ( defined( 'BIZCITY_KG_VEC_RUST_BIN' ) && file_exists( BIZCITY_KG_VEC_RUST_BIN ) ) { return 'rust_cli'; }
		return 'php';
	}

	/**
	 * Pure-PHP cosine ranking. Pre-computes query norm; per-row dot+norm.
	 * @return array<int, array{row:int, score:float}>
	 */
	private function search_php( array $vectors, array $query ) {
		$qn = 0.0;
		foreach ( $query as $v ) { $qn += $v * $v; }
		$qn = sqrt( $qn );
		if ( $qn === 0.0 ) { return []; }

		$out = [];
		foreach ( $vectors as $i => $vec ) {
			$dot = 0.0; $rn = 0.0;
			$len = count( $vec );
			for ( $j = 0; $j < $len; $j++ ) {
				$x = $vec[ $j ];
				$dot += $x * $query[ $j ];
				$rn  += $x * $x;
			}
			if ( $rn === 0.0 ) { continue; }
			$out[] = [ 'row' => $i, 'score' => $dot / ( $qn * sqrt( $rn ) ) ];
		}
		return $out;
	}

	/** Sort + threshold + top-K + attach idx payload. */
	private function finalize( array $scored, array $idx, $top_k, $threshold ) {
		if ( $threshold > 0.0 ) {
			$scored = array_filter( $scored, function( $r ) use ( $threshold ) { return $r['score'] >= $threshold; } );
		}
		usort( $scored, function( $a, $b ) {
			if ( $a['score'] === $b['score'] ) { return 0; }
			return ( $a['score'] < $b['score'] ) ? 1 : -1;
		} );
		$scored = array_slice( $scored, 0, $top_k );
		$rows   = isset( $idx['rows'] ) ? $idx['rows'] : [];
		$out    = [];
		foreach ( $scored as $r ) {
			$payload = isset( $rows[ $r['row'] ] ) ? $rows[ $r['row'] ] : [];
			$out[] = [
				'row'     => $r['row'],
				'uid'     => isset( $payload['uid'] ) ? $payload['uid'] : null,
				'score'   => (float) $r['score'],
				'payload' => $payload,
			];
		}
		return $out;
	}

	// -------------------------------------------------------------------
	// Migration helper (Wave 2 — job B)
	// -------------------------------------------------------------------

	/**
	 * Rebuild a guru's .bin from the legacy JSON `embedding` column on
	 * `bizcity_kg_source_chunks` for one character_uuid.
	 *
	 * Does NOT delete the JSON column — Wave 6 will drop after dual-read OK.
	 *
	 * @param string $character_uuid
	 * @return array|WP_Error  { count, dim, path, model_id }
	 */
	public function rebuild_from_db( $character_uuid ) {
		global $wpdb;
		if ( ! preg_match( '/^[0-9a-f-]{36}$/i', $character_uuid ) ) {
			return new WP_Error( 'kg_bin_uuid_bad', 'invalid character_uuid' );
		}
		if ( ! function_exists( 'bizcity_kg_storage_path' ) ) {
			return new WP_Error( 'kg_bin_no_helper', 'bizcity_kg_storage_path() unavailable' );
		}
		// HOTFIX 2026-05-06: helper resolves to bizcity_kg_passages on this install.
		$table = class_exists( 'BizCity_KG_Database' )
			? BizCity_KG_Database::instance()->tbl_source_chunks()
			: ( $wpdb->prefix . 'bizcity_kg_passages' );
		$rows  = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, source_id, chunk_uid, embedding FROM {$table} WHERE character_uuid = %s AND embedding IS NOT NULL ORDER BY id ASC",
			$character_uuid
		), ARRAY_A );
		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return new WP_Error( 'kg_bin_no_rows', 'no rows with embedding for ' . $character_uuid );
		}
		$vectors = [];
		$idx     = [];
		$dim     = 0;
		foreach ( $rows as $r ) {
			$vec = json_decode( $r['embedding'], true );
			if ( ! is_array( $vec ) || empty( $vec ) ) { continue; }
			if ( $dim === 0 ) { $dim = count( $vec ); }
			if ( count( $vec ) !== $dim ) {
				return new WP_Error( 'kg_bin_dim_mixed', 'mixed dims in DB rows for ' . $character_uuid );
			}
			$vectors[] = array_map( 'floatval', $vec );
			$idx[]     = [
				'uid'       => isset( $r['chunk_uid'] ) ? $r['chunk_uid'] : null,
				'chunk_id'  => (int) $r['id'],
				'source_id' => isset( $r['source_id'] ) ? (int) $r['source_id'] : null,
			];
		}
		if ( empty( $vectors ) ) {
			return new WP_Error( 'kg_bin_no_vectors', 'all embeddings unparseable for ' . $character_uuid );
		}
		$rel = bizcity_kg_storage_path( 'gurus', $character_uuid, 'bin' );
		$res = $this->write( $rel, $vectors, $idx, [ 'dim' => $dim, 'model_id' => self::DEFAULT_MODEL ] );
		if ( is_wp_error( $res ) ) { return $res; }
		return [
			'count'    => count( $vectors ),
			'dim'      => $dim,
			'path'     => $rel,
			'model_id' => self::DEFAULT_MODEL,
		];
	}

	/**
	 * Verify .bin matches DB JSON for given character_uuid (cosine ≥ 0.9999 sample).
	 *
	 * @param string $character_uuid
	 * @param int    $sample_size
	 * @return array|WP_Error  { sampled, mismatches, max_drift }
	 */
	public function verify_bin_integrity( $character_uuid, $sample_size = 50 ) {
		global $wpdb;
		if ( ! function_exists( 'bizcity_kg_storage_path' ) ) {
			return new WP_Error( 'kg_bin_no_helper', 'bizcity_kg_storage_path() unavailable' );
		}
		$rel    = bizcity_kg_storage_path( 'gurus', $character_uuid, 'bin' );
		$loaded = $this->load( $rel );
		if ( is_wp_error( $loaded ) ) { return $loaded; }

		$idx_rows = $loaded['idx']['rows'];
		$total    = count( $idx_rows );
		if ( $total === 0 ) { return new WP_Error( 'kg_bin_empty_idx', 'idx empty' ); }
		$sample_size = min( (int) $sample_size, $total );

		$keys = (array) array_rand( $idx_rows, $sample_size );
		$mismatches = 0;
		$max_drift  = 0.0;
		// HOTFIX 2026-05-06: helper resolves to bizcity_kg_passages on this install.
		$table = class_exists( 'BizCity_KG_Database' )
			? BizCity_KG_Database::instance()->tbl_source_chunks()
			: ( $wpdb->prefix . 'bizcity_kg_passages' );
		foreach ( $keys as $k ) {
			$row     = $idx_rows[ $k ];
			$chunk_id = isset( $row['chunk_id'] ) ? (int) $row['chunk_id'] : 0;
			if ( ! $chunk_id ) { $mismatches++; continue; }
			$db_json = $wpdb->get_var( $wpdb->prepare(
				"SELECT embedding FROM {$table} WHERE id = %d", $chunk_id
			) );
			$db_vec = $db_json ? json_decode( $db_json, true ) : null;
			if ( ! is_array( $db_vec ) ) { $mismatches++; continue; }
			$bin_vec = $loaded['vectors'][ $k ];
			$drift = 0.0;
			$len   = min( count( $db_vec ), count( $bin_vec ) );
			for ( $i = 0; $i < $len; $i++ ) {
				$d = abs( ( (float) $db_vec[ $i ] ) - $bin_vec[ $i ] );
				if ( $d > $drift ) { $drift = $d; }
			}
			if ( $drift > 1e-4 ) { $mismatches++; }
			if ( $drift > $max_drift ) { $max_drift = $drift; }
		}
		return [
			'sampled'    => $sample_size,
			'mismatches' => $mismatches,
			'max_drift'  => $max_drift,
		];
	}

	/** Clear per-request cache (test/CLI use). */
	public function flush_cache( $path = null ) {
		if ( null === $path ) {
			self::$cache = [];
			return;
		}
		$abs = $this->resolve_path( $path );
		if ( is_wp_error( $abs ) ) { return; }
		unset( self::$cache[ $abs ] );
	}

	// -------------------------------------------------------------------
	// Streaming search (Wave 2 — OOM safety)
	// -------------------------------------------------------------------

	/**
	 * Top-K cosine search WITHOUT loading the whole file into memory.
	 * Reads STREAMING_BATCH_ROWS at a time, maintains a min-heap of size top_k.
	 *
	 * Used automatically by search() for files > STREAMING_THRESHOLD_BYTES.
	 * Skips the per-request memo cache to avoid creep.
	 *
	 * @param string  $path
	 * @param float[] $query_vec
	 * @param int     $top_k
	 * @param float   $threshold
	 * @return array|WP_Error  same shape as search()
	 */
	public function search_streaming( $path, array $query_vec, $top_k = 10, $threshold = 0.0 ) {
		$top_k = max( 1, (int) $top_k );
		$hdr   = $this->header_validate( $path );
		if ( is_wp_error( $hdr ) ) { return $hdr; }
		$dim = $hdr['dim'];
		if ( count( $query_vec ) !== $dim ) {
			return new WP_Error( 'kg_bin_query_dim', 'query dim ' . count( $query_vec ) . ' != bin dim ' . $dim );
		}

		$abs = $hdr['_path'];
		$fh  = fopen( $abs, 'rb' );
		if ( ! $fh ) { return new WP_Error( 'kg_bin_open_failed', 'fopen failed' ); }
		fseek( $fh, self::HEADER_SIZE );

		// Pre-compute query norm.
		$qn = 0.0;
		foreach ( $query_vec as $v ) { $qn += $v * $v; }
		$qn = sqrt( $qn );
		if ( $qn === 0.0 ) { fclose( $fh ); return []; }

		$row_len   = $dim * self::FLOAT_SIZE;
		$batch_len = $row_len * self::STREAMING_BATCH_ROWS;
		$count     = $hdr['count'];
		$row_index = 0;

		// Min-heap of [score, row] using SplPriorityQueue with negated priority.
		// Simpler: keep a sorted array of size $top_k.
		$top  = [];
		$min  = -INF;

		while ( $row_index < $count ) {
			$want = min( self::STREAMING_BATCH_ROWS, $count - $row_index );
			$buf  = fread( $fh, $row_len * $want );
			if ( false === $buf || strlen( $buf ) !== $row_len * $want ) {
				fclose( $fh );
				return new WP_Error( 'kg_bin_short_batch', 'short read at row ' . $row_index );
			}
			for ( $b = 0; $b < $want; $b++ ) {
				$slice = substr( $buf, $b * $row_len, $row_len );
				// 2026-05-14 — guard PHP warning "unpack(): Type e: not enough inp"
				// from corrupted/partial .bin (header count > on-disk vectors,
				// or trailing torn write before atomic-rename was added). Skip
				// short row instead of letting unpack() emit warning + return
				// undef-index results that nuke the cosine math.
				if ( strlen( $slice ) !== $row_len ) {
					$row_index++;
					continue;
				}
				// 2026-05-14 — try 'g' first (float32 LE, matches writer fallback), then 'e'
				// (4-byte on builds where it works), then 'f' (machine endian) as last resort.
				// Previously 'e' alone failed silently on this PHP build (treated as 8-byte
				// double) → search returned 0 results across all callers.
				$vec = @unpack( 'g' . $dim, $slice );
				if ( ! is_array( $vec ) || count( $vec ) !== $dim ) {
					$vec = @unpack( 'e' . $dim, $slice );
				}
				if ( ! is_array( $vec ) || count( $vec ) !== $dim ) {
					$vec = @unpack( 'f' . $dim, $slice );
				}
				if ( ! is_array( $vec ) || count( $vec ) !== $dim ) { $row_index++; continue; }
				// Compute cosine inline (avoid array_values overhead).
				$dot = 0.0; $rn = 0.0;
				for ( $j = 1; $j <= $dim; $j++ ) {
					$x   = $vec[ $j ];
					$dot += $x * $query_vec[ $j - 1 ];
					$rn  += $x * $x;
				}
				if ( $rn === 0.0 ) { $row_index++; continue; }
				$score = $dot / ( $qn * sqrt( $rn ) );
				if ( $score < $threshold ) { $row_index++; continue; }

				if ( count( $top ) < $top_k ) {
					$top[] = [ 'row' => $row_index, 'score' => $score ];
					if ( count( $top ) === $top_k ) {
						usort( $top, function( $a, $b ) { return $a['score'] <=> $b['score']; } );
						$min = $top[0]['score'];
					}
				} elseif ( $score > $min ) {
					// Drop current min, insert new, re-sort tiny array.
					$top[0] = [ 'row' => $row_index, 'score' => $score ];
					usort( $top, function( $a, $b ) { return $a['score'] <=> $b['score']; } );
					$min = $top[0]['score'];
				}
				$row_index++;
			}
		}
		fclose( $fh );

		// Load idx.json (small file, ~few KB per 1k rows).
		$idx = $this->load_idx( $this->idx_path( $abs ) );
		if ( is_wp_error( $idx ) ) {
			$idx = [ 'rows' => [] ];
		}
		return $this->finalize( $top, $idx, $top_k, 0.0 );
	}

	// -------------------------------------------------------------------
	// Scope-based rebuild (Wave 2 — user data migration to .bin)
	// -------------------------------------------------------------------

	/**
	 * Resolve UUID for a given scope.
	 *   scope_type='notebook'  → bizcity_kg_notebooks.uuid for scope_id (int notebook_id)
	 *   scope_type='character' → scope_id is itself the character_uuid
	 *
	 * @return string|WP_Error
	 */
	public function resolve_scope_uuid( $scope_type, $scope_id ) {
		global $wpdb;
		$scope_type = (string) $scope_type;
		if ( 'character' === $scope_type ) {
			$uuid = strtolower( (string) $scope_id );
			if ( ! preg_match( '/^[0-9a-f-]{36}$/', $uuid ) ) {
				return new WP_Error( 'kg_bin_scope_uuid', 'character scope_id must be UUIDv4' );
			}
			return $uuid;
		}
		if ( 'notebook' === $scope_type ) {
			$nb_id = (int) $scope_id;
			if ( $nb_id <= 0 ) {
				return new WP_Error( 'kg_bin_scope_id', 'notebook scope_id must be positive int' );
			}
			if ( ! class_exists( 'BizCity_KG_Database' ) ) {
				return new WP_Error( 'kg_bin_no_db', 'BizCity_KG_Database not loaded' );
			}
			$tbl = BizCity_KG_Database::instance()->tbl_notebooks();
			$uuid = $wpdb->get_var( $wpdb->prepare( "SELECT uuid FROM {$tbl} WHERE id = %d", $nb_id ) );
			if ( ! $uuid ) {
				return new WP_Error( 'kg_bin_no_uuid', 'notebook ' . $nb_id . ' has no uuid (run schema migration)' );
			}
			return strtolower( (string) $uuid );
		}
		return new WP_Error( 'kg_bin_scope_type', 'unsupported scope_type: ' . $scope_type );
	}

	/**
	 * Rebuild a `.bin` for a user scope from the legacy JSON `embedding` column.
	 *
	 * Filter rule:
	 *   notebook scope  → chunks WHERE notebook_id = ? AND character_uuid IS NULL
	 *   character scope → chunks WHERE character_uuid = ?
	 *
	 * Does NOT delete the JSON column — Wave 6 will drop after dual-read OK.
	 *
	 * @param string $scope_type 'notebook'|'character'
	 * @param mixed  $scope_id   int (notebook_id) OR string (character_uuid)
	 * @return array|WP_Error  { count, dim, path, model_id, scope_type, scope_id, uuid }
	 */
	public function rebuild_from_scope( $scope_type, $scope_id ) {
		global $wpdb;
		$uuid = $this->resolve_scope_uuid( $scope_type, $scope_id );
		if ( is_wp_error( $uuid ) ) { return $uuid; }

		if ( ! function_exists( 'bizcity_kg_vector_bin_path' ) ) {
			return new WP_Error( 'kg_bin_no_helper', 'bizcity_kg_vector_bin_path() unavailable' );
		}
		if ( ! class_exists( 'BizCity_KG_Database' ) ) {
			return new WP_Error( 'kg_bin_no_db', 'BizCity_KG_Database not loaded' );
		}

		$db    = BizCity_KG_Database::instance();
		$table = $db->tbl_source_chunks();
		if ( 'character' === $scope_type ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, source_id, embedding FROM {$table} WHERE character_uuid = %s AND embedding IS NOT NULL ORDER BY id ASC",
				$uuid
			), ARRAY_A );
			$bin_kind = 'gurus';
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, source_id, embedding FROM {$table} WHERE notebook_id = %d AND character_uuid IS NULL AND embedding IS NOT NULL ORDER BY id ASC",
				(int) $scope_id
			), ARRAY_A );
			$bin_kind = 'notebooks';
		}

		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return new WP_Error( 'kg_bin_no_rows', 'no rows with embedding for ' . $scope_type . ':' . $scope_id );
		}

		$vectors = [];
		$idx     = [];
		$dim     = 0;
		foreach ( $rows as $r ) {
			$vec = json_decode( $r['embedding'], true );
			if ( ! is_array( $vec ) || empty( $vec ) ) { continue; }
			if ( $dim === 0 ) { $dim = count( $vec ); }
			if ( count( $vec ) !== $dim ) {
				return new WP_Error( 'kg_bin_dim_mixed', 'mixed dims in DB rows for ' . $scope_type . ':' . $scope_id );
			}
			$vectors[] = array_map( 'floatval', $vec );
			$idx[]     = [
				'chunk_id'  => (int) $r['id'],
				'source_id' => isset( $r['source_id'] ) ? (int) $r['source_id'] : null,
			];
		}
		if ( empty( $vectors ) ) {
			return new WP_Error( 'kg_bin_no_vectors', 'all embeddings unparseable for ' . $scope_type . ':' . $scope_id );
		}

		$abs = bizcity_kg_vector_bin_path( $bin_kind, $uuid );
		if ( ! $abs ) {
			return new WP_Error( 'kg_bin_path_failed', 'cannot resolve path for ' . $bin_kind . '/' . $uuid );
		}
		$res = $this->write( $abs, $vectors, $idx, [ 'dim' => $dim, 'model_id' => self::DEFAULT_MODEL ] );
		if ( is_wp_error( $res ) ) { return $res; }

		return [
			'count'      => count( $vectors ),
			'dim'        => $dim,
			'path'       => $abs,
			'model_id'   => self::DEFAULT_MODEL,
			'scope_type' => $scope_type,
			'scope_id'   => $scope_id,
			'uuid'       => $uuid,
		];
	}
}
