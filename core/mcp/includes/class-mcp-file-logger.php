<?php
/**
 * File-backed MCP request evidence logger.
 *
 * Keeps request evidence available when the database is degraded. The logger
 * stores metadata and evaluation counters only; never raw keys, prompts,
 * draft bodies, SQL, or response content.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\MCP
 */

defined( 'ABSPATH' ) || exit;

// [2026-07-28 Johnny Chu] PHASE-0.53-MCP — persist per-request MCP evidence in upload JSONL outside the database path.
final class BizCity_MCP_File_Logger {

	const DIRECTORY = 'bizcity-mcp-logs';

	public static function write( array $entry ) {
		// [2026-08-01 Johnny Chu] PHASE-1.24-LOG-JSONL — use the shared logger as the canonical MCP file store; retain this wrapper API for callers.
		if ( class_exists( 'BizCity_JSONL_File_Logger' ) && method_exists( 'BizCity_JSONL_File_Logger', 'write_contract' ) ) {
			$tool_name = preg_replace( '/[^A-Za-z0-9_.-]/', '', (string) ( $entry['tool_name'] ?? 'mcp_call' ) );
			// [2026-08-27 Johnny Chu] R-LOG-HYBRID — MCP audit evidence resolves through the registered contract.
			return BizCity_JSONL_File_Logger::write_contract(
				'core.mcp.audit',
				( (string) ( $entry['status'] ?? '' ) === 'error' ) ? 'error' : 'info',
				'mcp_call',
				$tool_name,
				$entry
			);
		}

		// [2026-08-01 Johnny Chu] PHASE-1.24-LOG-JSONL — legacy fallback for deployments where the shared helper is not loaded yet.
		try {
			$upload = wp_upload_dir();
			$base   = isset( $upload['basedir'] ) ? (string) $upload['basedir'] : '';
			if ( $base === '' ) {
				return false;
			}
			$dir = trailingslashit( $base ) . self::DIRECTORY;
			if ( ! wp_mkdir_p( $dir ) ) {
				return false;
			}
			$file = trailingslashit( $dir ) . gmdate( 'Y-m-d' ) . '.jsonl';
			$line = wp_json_encode( self::sanitize_entry( $entry ) ) . PHP_EOL;
			// [2026-08-01 Johnny Chu] PHASE-1.24-LOG-JSONL — FILE_APPEND is required; LOCK_EX alone overwrote the day's audit file on every call.
			return false !== file_put_contents( $file, $line, FILE_APPEND | LOCK_EX );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	public static function read_recent( $user_id = 0, $key_id = 0, $client_id = '', $limit = 100 ) {
		// [2026-08-01 Johnny Chu] PHASE-1.24-LOG-JSONL — read canonical shared JSONL rows first, then legacy flat files during migration.
		$limit = max( 1, min( 500, (int) $limit ) );
		if ( class_exists( 'BizCity_JSONL_File_Logger' ) && method_exists( 'BizCity_JSONL_File_Logger', 'query_contract' ) ) {
			$rows = BizCity_JSONL_File_Logger::query_contract(
				'core.mcp.audit',
				array(
					'days'   => 7,
					'limit'  => $limit,
					'filter' => static function ( $row ) use ( $user_id, $key_id, $client_id ) {
						$ctx = is_array( $row['ctx'] ?? null ) ? $row['ctx'] : array();
						if ( $user_id > 0 && (int) ( $ctx['user_id'] ?? 0 ) !== (int) $user_id ) {
							return false;
						}
						if ( $key_id > 0 && (int) ( $ctx['key_id'] ?? 0 ) !== (int) $key_id ) {
							return false;
						}
						if ( $client_id !== '' && (string) ( $ctx['client_id'] ?? '' ) !== (string) $client_id ) {
							return false;
						}
						return true;
					},
				)
			);
			$out = array();
			foreach ( (array) $rows as $row ) {
				$ctx = is_array( $row['ctx'] ?? null ) ? $row['ctx'] : array();
				$out[] = array_merge( $ctx, array(
					'timestamp' => (string) ( $row['ts'] ?? gmdate( 'c' ) ),
					'tool_name' => (string) ( $ctx['tool_name'] ?? $row['msg'] ?? '' ),
					'status'    => (string) ( $ctx['status'] ?? ( ( $row['level'] ?? '' ) === 'error' ? 'error' : 'success' ) ),
					'event'     => (string) ( $row['event'] ?? 'mcp_call' ),
				) );
			}
			// [2026-08-01 Johnny Chu] PHASE-1.24-LOG-JSONL — include pre-cutover flat files so migration does not hide valid recent evidence.
			$out = array_merge( $out, self::read_legacy_recent( $user_id, $key_id, $client_id, $limit ) );
			usort( $out, static function ( $left, $right ) {
				return strcmp( (string) ( $right['timestamp'] ?? '' ), (string) ( $left['timestamp'] ?? '' ) );
			} );
			return array_slice( $out, 0, $limit );
		}

		return self::read_legacy_recent( $user_id, $key_id, $client_id, $limit );
	}

	/**
	 * Read the pre-PHASE-1.24 flat JSONL layout during migration.
	 */
	private static function read_legacy_recent( $user_id, $key_id, $client_id, $limit ) {
		// [2026-08-01 Johnny Chu] PHASE-1.24-LOG-JSONL — legacy flat evidence remains readable until its retention window expires.
		$upload = wp_upload_dir();
		$base   = isset( $upload['basedir'] ) ? (string) $upload['basedir'] : '';
		if ( $base === '' ) {
			return array();
		}
		$files  = array();
		for ( $offset = 0; $offset < 7; $offset++ ) {
			$files[] = trailingslashit( $base ) . self::DIRECTORY . '/' . gmdate( 'Y-m-d', time() - ( DAY_IN_SECONDS * $offset ) ) . '.jsonl';
		}
		$out = array();
		foreach ( $files as $file ) {
			if ( ! is_readable( $file ) ) {
				continue;
			}
			$lines = file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
			if ( ! is_array( $lines ) ) {
				continue;
			}
			foreach ( array_reverse( $lines ) as $line ) {
				$row = json_decode( (string) $line, true );
				if ( ! is_array( $row ) ) {
					continue;
				}
				if ( $user_id > 0 && (int) ( $row['user_id'] ?? 0 ) !== (int) $user_id ) {
					continue;
				}
				if ( $key_id > 0 && (int) ( $row['key_id'] ?? 0 ) !== (int) $key_id ) {
					continue;
				}
				if ( $client_id !== '' && (string) ( $row['client_id'] ?? '' ) !== (string) $client_id ) {
					continue;
				}
				$out[] = $row;
				if ( count( $out ) >= $limit ) {
					break 2;
				}
			}
		}
		return $out;
	}

	private static function sanitize_entry( array $entry ) {
		$allowed = array( 'timestamp', 'trace_id', 'blog_id', 'user_id', 'key_id', 'client_id', 'client_name', 'tool_name', 'status', 'error_code', 'duration_ms', 'request_hash', 'evaluation', 'scores' );
		$out = array();
		foreach ( $allowed as $key ) {
			if ( array_key_exists( $key, $entry ) ) {
				$out[ $key ] = $entry[ $key ];
			}
		}
		$out['timestamp'] = isset( $out['timestamp'] ) ? (string) $out['timestamp'] : gmdate( 'c' );
		$out['blog_id']   = (int) ( $out['blog_id'] ?? get_current_blog_id() );
		$out['user_id']   = (int) ( $out['user_id'] ?? 0 );
		$out['key_id']    = (int) ( $out['key_id'] ?? 0 );
		$out['client_id'] = sanitize_key( (string) ( $out['client_id'] ?? '' ) );
		$out['client_name'] = sanitize_text_field( (string) ( $out['client_name'] ?? '' ) );
		$out['tool_name'] = preg_replace( '/[^A-Za-z0-9_.-]/', '', (string) ( $out['tool_name'] ?? '' ) );
		return $out;
	}
}
