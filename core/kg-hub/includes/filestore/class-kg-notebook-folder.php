<?php
/**
 * Bizcity Twin AI — KG Notebook Folder helper (Phase 0.7 Wave F0).
 *
 * Owns the on-disk folder layout for the content filestore:
 *
 *   wp-content/uploads/sites/{blog_id}/bizcity-kg/notebooks/{notebook_uuid}/
 *     ├── _meta.json                ← manifest (this class)
 *     ├── passages/{NNNN-NNNN}.md   ← BizCity_KG_Passage_File_Store (F1)
 *     ├── entities.jsonl            ← BizCity_KG_Entity_File_Store   (F2)
 *     ├── relations.jsonl           ← BizCity_KG_Relation_File_Store (F2)
 *     └── triplet_queue/YYYY-MM.jsonl
 *
 * Path resolution piggy-backs on the existing R-VFS path helper
 * (`bizcity_kg_vector_bin_path`) so override / multisite uploads dir / S3
 * mounts all keep working without duplication.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge\KG_Hub\Filestore
 * @since      2026-05-20  (PHASE-0.7-LEARN-VECTOR-FILE Wave F0)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_KG_Notebook_Folder {

	const MANIFEST_VERSION = 1;
	const MANIFEST_FILE    = '_meta.json';

	private static $instance = null;
	/** Per-request memo: "{scope_type}/{uuid}" => absolute folder path. */
	private static $dir_cache = [];

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	// -------------------------------------------------------------------
	// Path resolution
	// -------------------------------------------------------------------

	/**
	 * Absolute path to the notebook/guru/source folder. Creates it on first call.
	 *
	 * @param string $scope_type 'notebooks' | 'gurus' | 'sources'
	 * @param string $uuid       lowercase UUIDv4
	 * @return string|WP_Error   Absolute dir path with trailing slash.
	 */
	public function path( $scope_type, $uuid ) {
		$scope_type = preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $scope_type ) );
		$uuid       = strtolower( (string) $uuid );
		if ( '' === $scope_type || ! preg_match( '/^[0-9a-f-]{36}$/', $uuid ) ) {
			return new WP_Error( 'kg_folder_bad_input', 'scope_type/uuid invalid' );
		}

		$key = $scope_type . '/' . $uuid;
		if ( isset( self::$dir_cache[ $key ] ) ) {
			return self::$dir_cache[ $key ];
		}

		// Reuse R-VFS helper to derive the .bin sibling path, then go up one ext.
		if ( ! function_exists( 'bizcity_kg_vector_bin_path' ) ) {
			return new WP_Error( 'kg_folder_no_helper', 'bizcity_kg_vector_bin_path() unavailable' );
		}
		$bin = bizcity_kg_vector_bin_path( $scope_type, $uuid );
		if ( ! is_string( $bin ) || '' === $bin ) {
			return new WP_Error( 'kg_folder_no_path', 'failed to resolve bin path for ' . $key );
		}

		// Convention: {storage}/{scope_type}/{uuid}.bin → {storage}/{scope_type}/{uuid}/
		// Strip the .bin extension and trailing nothing; create a sibling directory.
		$dir = preg_replace( '/\.bin$/', '', $bin );
		$dir = rtrim( $dir, '/\\' ) . '/';

		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		self::$dir_cache[ $key ] = $dir;
		return $dir;
	}

	/**
	 * Subfolder helpers — auto-create.
	 */
	public function passages_dir( $scope_type, $uuid ) {
		$root = $this->path( $scope_type, $uuid );
		if ( is_wp_error( $root ) ) { return $root; }
		$dir = $root . 'passages/';
		if ( ! file_exists( $dir ) ) { wp_mkdir_p( $dir ); }
		return $dir;
	}

	public function triplet_queue_dir( $scope_type, $uuid ) {
		$root = $this->path( $scope_type, $uuid );
		if ( is_wp_error( $root ) ) { return $root; }
		$dir = $root . 'triplet_queue/';
		if ( ! file_exists( $dir ) ) { wp_mkdir_p( $dir ); }
		return $dir;
	}

	public function entities_jsonl( $scope_type, $uuid ) {
		$root = $this->path( $scope_type, $uuid );
		if ( is_wp_error( $root ) ) { return $root; }
		return $root . 'entities.jsonl';
	}

	public function relations_jsonl( $scope_type, $uuid ) {
		$root = $this->path( $scope_type, $uuid );
		if ( is_wp_error( $root ) ) { return $root; }
		return $root . 'relations.jsonl';
	}

	/**
	 * Resolve UUID for a notebook id (delegates to R-VFS helper).
	 */
	public function notebook_uuid( $notebook_id ) {
		if ( ! class_exists( 'BizCity_KG_Vector_File_Store' ) ) {
			return new WP_Error( 'kg_folder_no_vfs', 'KG_Vector_File_Store not loaded' );
		}
		return BizCity_KG_Vector_File_Store::instance()->resolve_scope_uuid( 'notebook', (int) $notebook_id );
	}

	// -------------------------------------------------------------------
	// Manifest
	// -------------------------------------------------------------------

	/**
	 * Read `_meta.json` manifest. Auto-init with a defaults shell on first access.
	 *
	 * @return array|WP_Error
	 */
	public function manifest( $scope_type, $uuid ) {
		$dir = $this->path( $scope_type, $uuid );
		if ( is_wp_error( $dir ) ) { return $dir; }

		$file = $dir . self::MANIFEST_FILE;
		if ( ! file_exists( $file ) ) {
			$default = $this->default_manifest( $scope_type, $uuid );
			$this->write_manifest( $scope_type, $uuid, $default );
			return $default;
		}
		$raw = @file_get_contents( $file );
		if ( false === $raw ) {
			return new WP_Error( 'kg_folder_read', 'cannot read manifest ' . $file );
		}
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'kg_folder_parse', 'manifest not JSON: ' . $file );
		}
		return $data;
	}

	/**
	 * Atomic manifest write — write to .tmp then rename. No locks needed because
	 * rename is atomic on the same filesystem; callers wanting consistency with
	 * concurrent updates should wrap in their own flock if needed.
	 *
	 * @return bool|WP_Error
	 */
	public function write_manifest( $scope_type, $uuid, array $data ) {
		$dir = $this->path( $scope_type, $uuid );
		if ( is_wp_error( $dir ) ) { return $dir; }

		$data['version']      = self::MANIFEST_VERSION;
		$data['scope_type']   = $scope_type;
		$data['uuid']         = $uuid;
		$data['updated_at']   = gmdate( 'c' );

		$file = $dir . self::MANIFEST_FILE;
		$tmp  = $file . '.tmp';
		$json = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( false === @file_put_contents( $tmp, $json, LOCK_EX ) ) {
			return new WP_Error( 'kg_folder_write', 'failed to write tmp manifest' );
		}
		if ( ! @rename( $tmp, $file ) ) {
			@unlink( $tmp );
			return new WP_Error( 'kg_folder_rename', 'atomic rename failed' );
		}
		return true;
	}

	/**
	 * Patch specific keys without overwriting the whole manifest.
	 */
	public function update_manifest( $scope_type, $uuid, array $patch ) {
		$current = $this->manifest( $scope_type, $uuid );
		if ( is_wp_error( $current ) ) { return $current; }
		return $this->write_manifest( $scope_type, $uuid, array_merge( $current, $patch ) );
	}

	private function default_manifest( $scope_type, $uuid ) {
		return [
			'version'           => self::MANIFEST_VERSION,
			'scope_type'        => $scope_type,
			'uuid'              => $uuid,
			'passages_count'    => 0,
			'passages_shards'   => 0,
			'entities_count'    => 0,
			'relations_count'   => 0,
			'embedding_model'   => 'text-embedding-3-small',
			'embedding_dim'     => 1536,
			'storage_ver'       => 2,
			'last_compacted_at' => null,
			'created_at'        => gmdate( 'c' ),
			'updated_at'        => gmdate( 'c' ),
		];
	}

	// -------------------------------------------------------------------
	// Maintenance — checksum / clone / purge
	// -------------------------------------------------------------------

	/**
	 * Recursive sha256 digest of all files in the folder (sorted by relative path).
	 * Used for portability + rsync verification.
	 */
	public function checksum( $scope_type, $uuid ) {
		$dir = $this->path( $scope_type, $uuid );
		if ( is_wp_error( $dir ) ) { return $dir; }
		$files = [];
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			if ( $f->isFile() && $f->getFilename() !== self::MANIFEST_FILE . '.tmp' ) {
				$rel = ltrim( str_replace( $dir, '', $f->getPathname() ), '/\\' );
				$files[ $rel ] = $f->getPathname();
			}
		}
		ksort( $files );
		$ctx = hash_init( 'sha256' );
		foreach ( $files as $rel => $abs ) {
			hash_update( $ctx, $rel . "\n" );
			hash_update_file( $ctx, $abs );
		}
		return hash_final( $ctx );
	}

	/**
	 * Recursive folder copy — Wave F5 export / clone notebook.
	 */
	public function clone_to( $src_scope_type, $src_uuid, $dst_scope_type, $dst_uuid ) {
		$src = $this->path( $src_scope_type, $src_uuid );
		$dst = $this->path( $dst_scope_type, $dst_uuid );
		if ( is_wp_error( $src ) ) { return $src; }
		if ( is_wp_error( $dst ) ) { return $dst; }
		return $this->recursive_copy( $src, $dst );
	}

	private function recursive_copy( $src, $dst ) {
		if ( ! file_exists( $dst ) ) { wp_mkdir_p( $dst ); }
		$dir = opendir( $src );
		if ( false === $dir ) { return new WP_Error( 'kg_folder_opendir', 'cannot open ' . $src ); }
		while ( false !== ( $f = readdir( $dir ) ) ) {
			if ( '.' === $f || '..' === $f ) { continue; }
			$s = $src . $f;
			$d = $dst . $f;
			if ( is_dir( $s ) ) {
				$this->recursive_copy( trailingslashit( $s ), trailingslashit( $d ) );
			} else {
				@copy( $s, $d );
			}
		}
		closedir( $dir );
		return true;
	}

	/**
	 * Recursive purge — Wave F5 uninstall.
	 */
	public function purge( $scope_type, $uuid ) {
		$dir = $this->path( $scope_type, $uuid );
		if ( is_wp_error( $dir ) ) { return $dir; }
		return $this->recursive_rm( $dir );
	}

	private function recursive_rm( $dir ) {
		if ( ! is_dir( $dir ) ) { return true; }
		$it = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $it as $f ) {
			if ( $f->isDir() ) { @rmdir( $f->getPathname() ); }
			else               { @unlink( $f->getPathname() ); }
		}
		@rmdir( $dir );
		return true;
	}
}
