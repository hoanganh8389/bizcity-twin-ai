<?php
/**
 * Persistent metadata-only SLO evidence store.
 *
 * One JSONL file is written per site and UTC day. The store deliberately keeps
 * only operational metrics, never tool arguments, response bodies, or secrets.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Twin_Core
 * @since 1.3.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BizCity_Twin_SLO_Store' ) ) {
	final class BizCity_Twin_SLO_Store {

		/**
		 * Persist one execution outcome as metadata-only evidence.
		 *
		 * @param string              $name
		 * @param array<string,mixed> $data
		 * @return bool
		 */
		public static function record( $name, array $data = array() ) {
			// [2026-07-30 Johnny Chu] PHASE-1.22-RUNTIME — persist SLO evidence outside volatile object cache.
			try {
				if ( ! function_exists( 'wp_upload_dir' ) ) {
					return false;
				}
				$upload = wp_upload_dir();
				$base   = isset( $upload['basedir'] ) ? (string) $upload['basedir'] : '';
				if ( '' === $base ) {
					return false;
				}
				$directory = trailingslashit( $base ) . 'bizcity-runtime-slo';
				if ( ! is_dir( $directory ) && function_exists( 'wp_mkdir_p' ) ) {
					wp_mkdir_p( $directory );
				}
				if ( ! is_dir( $directory ) || ! is_writable( $directory ) ) {
					return false;
				}

				$record = array(
					'name'       => self::clean_name( $name ),
					'created_at' => gmdate( 'c' ),
					'blog_id'    => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0,
					'ok'         => ! empty( $data['ok'] ),
					'code'       => self::clean_name( $data['code'] ?? '' ),
					'bucket'     => self::clean_name( $data['bucket'] ?? '' ),
					'attempts'   => max( 1, (int) ( $data['attempts'] ?? 1 ) ),
					'latency_ms' => max( 0, (int) ( $data['latency_ms'] ?? 0 ) ),
				);
				$filename = $directory . DIRECTORY_SEPARATOR . gmdate( 'Y-m-d' ) . '.jsonl';
				$payload  = function_exists( 'wp_json_encode' ) ? wp_json_encode( $record ) : json_encode( $record );
				return false !== file_put_contents( $filename, $payload . PHP_EOL, FILE_APPEND | LOCK_EX );
			} catch ( \Throwable $e ) {
				return false;
			}
		}

		/**
		 * Summarize persisted outcomes for one tool over recent UTC days.
		 *
		 * @param string $name
		 * @param int    $days
		 * @return array<string,mixed>
		 */
		public static function summary( $name, $days = 7 ) {
			$rows = self::read_rows( $name, $days );
			$total = count( $rows );
			$success = 0;
			$latencies = array();
			foreach ( $rows as $row ) {
				$success += ! empty( $row['ok'] ) ? 1 : 0;
				$latencies[] = max( 0, (int) ( $row['latency_ms'] ?? 0 ) );
			}
			sort( $latencies, SORT_NUMERIC );
			$p95_index = $total > 0 ? min( $total - 1, (int) ceil( $total * 0.95 ) - 1 ) : 0;
			return array(
				'name'          => self::clean_name( $name ),
				'days'          => max( 1, (int) $days ),
				'total'         => $total,
				'success'       => $success,
				'errors'        => max( 0, $total - $success ),
				'success_rate'  => $total > 0 ? round( $success / $total, 4 ) : null,
				'error_rate'    => $total > 0 ? round( ( $total - $success ) / $total, 4 ) : null,
				'p95_latency_ms'=> $total > 0 ? $latencies[ $p95_index ] : null,
			);
		}

		private static function read_rows( $name, $days ) {
			$rows = array();
			if ( ! function_exists( 'wp_upload_dir' ) ) {
				return $rows;
			}
			$upload = wp_upload_dir();
			$base   = isset( $upload['basedir'] ) ? (string) $upload['basedir'] : '';
			if ( '' === $base ) {
				return $rows;
			}
			$days = min( 31, max( 1, (int) $days ) );
			for ( $offset = 0; $offset < $days; $offset++ ) {
				$filename = trailingslashit( $base ) . 'bizcity-runtime-slo/' . gmdate( 'Y-m-d', time() - ( $offset * DAY_IN_SECONDS ) ) . '.jsonl';
				if ( ! is_readable( $filename ) ) {
					continue;
				}
				$lines = file( $filename, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
				if ( ! is_array( $lines ) ) {
					continue;
				}
				foreach ( $lines as $line ) {
					$row = json_decode( (string) $line, true );
					if ( is_array( $row ) && self::clean_name( $row['name'] ?? '' ) === self::clean_name( $name ) ) {
						$rows[] = $row;
					}
				}
			}
			return $rows;
		}

		private static function clean_name( $value ) {
			return preg_replace( '/[^A-Za-z0-9_.:-]/', '', (string) $value );
		}
	}
}
