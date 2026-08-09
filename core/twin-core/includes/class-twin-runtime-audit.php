<?php
/**
 * Runtime audit sink for public tool and mutation execution contracts.
 *
 * Audit records intentionally contain metadata only. Arguments, response bodies,
 * secrets, tokens, and tenant content must never be persisted here.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Twin_Core
 * @since 1.2.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BizCity_Twin_Runtime_Audit' ) ) {
	final class BizCity_Twin_Runtime_Audit {

		/**
		 * Write a metadata-only JSONL audit record.
		 *
		 * @param string              $event  Event name.
		 * @param array<string,mixed> $data   Sanitized metadata.
		 * @return void
		 */
		public static function record( $event, array $data = array() ) {
			// [2026-07-30 Johnny Chu] PHASE-1.22-AUDIT — fail-safe metadata audit sink.
			try {
				if ( ! function_exists( 'wp_upload_dir' ) ) {
					return;
				}

				$upload = wp_upload_dir();
				$base   = isset( $upload['basedir'] ) ? (string) $upload['basedir'] : '';
				if ( '' === $base || ! is_dir( $base ) ) {
					return;
				}

				$directory = trailingslashit( $base ) . 'bizcity-runtime-audit';
				if ( ! is_dir( $directory ) && function_exists( 'wp_mkdir_p' ) ) {
					wp_mkdir_p( $directory );
				}
				if ( ! is_dir( $directory ) || ! is_writable( $directory ) ) {
					return;
				}

				$record = array(
					'event'      => preg_replace( '/[^A-Za-z0-9_.-]/', '', (string) $event ),
					'created_at' => function_exists( 'current_time' ) ? current_time( 'mysql', true ) : gmdate( 'c' ),
					'blog_id'    => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0,
				);
				foreach ( $data as $key => $value ) {
					if ( is_scalar( $value ) || null === $value ) {
						$record[ preg_replace( '/[^A-Za-z0-9_.-]/', '', (string) $key ) ] = $value;
					}
				}

				$filename = $directory . DIRECTORY_SEPARATOR . gmdate( 'Y-m-d' ) . '.jsonl';
				file_put_contents( $filename, wp_json_encode( $record ) . PHP_EOL, FILE_APPEND | LOCK_EX );
			} catch ( \Throwable $e ) {
				// Audit must never break the user-facing execution path.
			return;
			}
		}
	}
}
