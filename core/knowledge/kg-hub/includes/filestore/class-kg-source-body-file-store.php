<?php
/**
 * Filestore for source and unified chunk bodies.
 *
 * Bodies use deterministic notebook-scoped paths so SQL can be scrubbed without
 * adding another pointer column to the shared WebChat tables.
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_KG_Source_Body_File_Store {

	public static function write_source( $notebook_id, $source_id, $body ) {
		// [2026-07-24 Johnny Chu] PHASE-0.46-FILE-BODY — persist source text before SQL inline scrub.
		return self::write( $notebook_id, 'sources', $source_id, $body );
	}

	public static function write_chunk( $notebook_id, $chunk_id, $body ) {
		// [2026-07-24 Johnny Chu] PHASE-0.46-FILE-BODY — persist unified chunk text before SQL inline scrub.
		return self::write( $notebook_id, 'chunks', $chunk_id, $body );
	}

	public static function read_source( $notebook_id, $source_id ) {
		return self::read( $notebook_id, 'sources', $source_id );
	}

	public static function read_chunk( $notebook_id, $chunk_id ) {
		return self::read( $notebook_id, 'chunks', $chunk_id );
	}

	public static function delete_source( $notebook_id, $source_id ) {
		return self::delete( $notebook_id, 'sources', $source_id );
	}

	public static function delete_chunk( $notebook_id, $chunk_id ) {
		return self::delete( $notebook_id, 'chunks', $chunk_id );
	}

	private static function path( $notebook_id, $kind, $id ) {
		$uuid = BizCity_KG_Notebook_Folder::instance()->notebook_uuid( (int) $notebook_id );
		if ( is_wp_error( $uuid ) ) {
			return $uuid;
		}
		$root = BizCity_KG_Notebook_Folder::instance()->path( 'notebooks', (string) $uuid );
		if ( is_wp_error( $root ) ) {
			return $root;
		}
		$kind = in_array( $kind, array( 'sources', 'chunks' ), true ) ? $kind : 'sources';
		$dir  = trailingslashit( $root ) . $kind . '-bodies/';
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'kg_source_body_mkdir', 'Cannot create body directory.' );
		}
		return $dir . absint( $id ) . '.txt';
	}

	private static function write( $notebook_id, $kind, $id, $body ) {
		// [2026-07-24 Johnny Chu] PHASE-0.46-FILE-BODY — verify the complete write before returning success.
		if ( (int) $notebook_id <= 0 || (int) $id <= 0 ) {
			return new WP_Error( 'kg_source_body_invalid', 'Invalid notebook or body id.' );
		}
		$path = self::path( $notebook_id, $kind, $id );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		$body  = (string) $body;
		$bytes = @file_put_contents( $path, $body, LOCK_EX );
		if ( false === $bytes || (int) $bytes !== strlen( $body ) ) {
			return new WP_Error( 'kg_source_body_write', 'Source body write was incomplete.' );
		}
		return array( 'path' => $path, 'bytes' => (int) $bytes, 'sha256' => hash( 'sha256', $body ) );
	}

	private static function read( $notebook_id, $kind, $id ) {
		$path = self::path( $notebook_id, $kind, $id );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		if ( ! is_readable( $path ) ) {
			return new WP_Error( 'kg_source_body_missing', 'Source body file is missing.' );
		}
		$body = @file_get_contents( $path );
		return false === $body ? new WP_Error( 'kg_source_body_read', 'Source body read failed.' ) : $body;
	}

	private static function delete( $notebook_id, $kind, $id ) {
		$path = self::path( $notebook_id, $kind, $id );
		if ( is_wp_error( $path ) || ! file_exists( $path ) ) {
			return true;
		}
		return @unlink( $path );
	}
}
