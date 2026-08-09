<?php
/**
 * BizCity CRM — whitelist CSV export helper.
 *
 * @package BizCity_Twin_CRM
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Export', false ) ) {
	return;
}

final class BizCity_CRM_Export {

	const MAX_ROWS = 20000;

	/**
	 * Build a CSV REST response without exposing raw database rows.
	 *
	 * @param string $filename Download filename.
	 * @param array  $columns  Ordered column map: key => heading.
	 * @param array  $rows     Whitelisted row values.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function response( string $filename, array $columns, array $rows ) {
		// [2026-08-04 Johnny Chu] PHASE-0.48-H6 — stream bounded, whitelisted CRM CSV output.
		if ( count( $rows ) > self::MAX_ROWS ) {
			return new WP_Error(
				'export_limit_exceeded',
				'Danh sách quá lớn để xuất một lần.',
				array( 'status' => 422, 'max_rows' => self::MAX_ROWS )
			);
		}

		$handle = fopen( 'php://temp', 'w+' );
		if ( ! $handle ) {
			return new WP_Error( 'export_stream_unavailable', 'Không thể tạo file xuất dữ liệu.', array( 'status' => 500 ) );
		}
		fwrite( $handle, "\xEF\xBB\xBF" );
		fputcsv( $handle, array_values( $columns ) );
		foreach ( $rows as $row ) {
			$line = array();
			foreach ( array_keys( $columns ) as $key ) {
				$value = $row[ $key ] ?? '';
				$line[] = is_scalar( $value ) || null === $value ? (string) $value : wp_json_encode( $value );
			}
			fputcsv( $handle, $line );
		}
		rewind( $handle );
		$csv = stream_get_contents( $handle );
		fclose( $handle );

		$response = new WP_REST_Response( $csv, 200 );
		$response->header( 'Content-Type', 'text/csv; charset=utf-8' );
		$response->header( 'Content-Disposition', 'attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		$response->header( 'X-BizCity-Export-Rows', (string) count( $rows ) );
		return $response;
	}
}
