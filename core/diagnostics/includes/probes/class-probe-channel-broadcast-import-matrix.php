<?php
/**
 * BizCity Diagnostics — channel.broadcast_import_matrix probe.
 *
 * [2026-07-10 Johnny Chu] PHASE-0.47 — REST smoke matrix for
 * /bizcity-channel/v1/broadcasts/parse-file:
 *   - csv upload
 *   - xlsx upload
 *   - xls upload (skip if PhpSpreadsheet writer unavailable)
 *   - google_sheet_url import (mocked HTTP)
 *   - google_sheet_url invalid URL path
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

if ( class_exists( 'BizCity_Probe_Channel_Broadcast_Import_Matrix', false ) ) {
	return;
}

final class BizCity_Probe_Channel_Broadcast_Import_Matrix implements BizCity_Diagnostics_Probe {

	/** @var string[] */
	private $tmp_files = array();

	public function id(): string       { return 'channel.broadcast_import_matrix'; }
	public function label(): string    { return 'Channel Broadcast Import Matrix (csv/xlsx/xls/gsheet)'; }
	public function description(): string {
		return 'REST smoke cho /broadcasts/parse-file: csv, xlsx, xls, google sheet URL + invalid URL contract.';
	}
	public function severity(): string { return 'warning'; }
	public function order(): int       { return 47; }
	public function icon(): string     { return 'check-circle'; }
	public function estimate_ms(): int { return 1200; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_Broadcast_REST' ) ) {
			return new WP_Error( 'broadcast_rest_missing', 'BizCity_Broadcast_REST chưa load.' );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'insufficient_permission', 'Probe cần user có quyền manage_options để gọi route broadcast.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-07-10 Johnny Chu] PHASE-0.47 — execute import smoke matrix and aggregate failures/skips.
		$failures = array();
		$skips    = array();

		$rest_file = WP_PLUGIN_DIR . '/bizcity-twin-ai/core/channel-gateway/includes/broadcast/class-broadcast-rest.php';
		$disk_ok   = file_exists( $rest_file ) && is_readable( $rest_file );
		$ctx->emit_step( array(
			'label'  => 'Layer 1 · Disk · class-broadcast-rest.php',
			'status' => $disk_ok ? 'pass' : 'fail',
			'detail' => $disk_ok ? 'File present and readable.' : 'Missing/unreadable class-broadcast-rest.php',
		) );
		if ( ! $disk_ok ) {
			return array(
				'status'   => 'fail',
				'summary'  => 'Disk fail: missing broadcast REST controller file.',
				'error'    => 'disk_broadcast_rest_missing',
				'fix_hint' => 'Deploy core/channel-gateway/includes/broadcast/class-broadcast-rest.php lên site.',
			);
		}

		$disk_src       = (string) file_get_contents( $rest_file );
		$disk_tokens_ok = ( strpos( $disk_src, 'parse_google_sheet_url' ) !== false )
			&& ( strpos( $disk_src, 'build_template_xlsx' ) !== false )
			&& ( strpos( $disk_src, 'parse_xlsx' ) !== false );
		$ctx->emit_step( array(
			'label'  => 'Layer 1 · Disk · import tokens',
			'status' => $disk_tokens_ok ? 'pass' : 'fail',
			'detail' => $disk_tokens_ok
				? 'Found parser tokens for xlsx/xls/google sheet/template xlsx.'
				: 'Missing one or more expected parser/template tokens in class-broadcast-rest.php',
		) );
		if ( ! $disk_tokens_ok ) {
			$failures[] = 'disk_tokens_missing';
		}

		if ( is_callable( array( 'BizCity_Broadcast_REST', 'register_routes' ) ) ) {
			BizCity_Broadcast_REST::register_routes();
		}
		$routes   = rest_get_server()->get_routes();
		$route_ok = isset( $routes['/bizcity-channel/v1/broadcasts/parse-file'] );
		$ctx->emit_step( array(
			'label'  => 'Layer 2 · Loader · route /broadcasts/parse-file',
			'status' => $route_ok ? 'pass' : 'fail',
			'detail' => $route_ok ? 'Route registered in REST server.' : 'Route missing in REST registry.',
		) );
		if ( ! $route_ok ) {
			$failures[] = 'loader_route_missing';
			return array(
				'status'   => 'fail',
				'summary'  => 'Loader fail: parse-file route not registered.',
				'error'    => implode( '; ', $failures ),
				'fix_hint' => 'Check BizCity_Broadcast_REST::init/register_routes wiring trong bootstrap channel-gateway.',
			);
		}

		// Runtime 1: CSV smoke (UTF-8 BOM + semicolon + Vietnamese header aliases).
		$csv_fixture = $this->create_csv_fixture();
		$csv_res     = $this->dispatch_parse_file_upload( $csv_fixture, 'smoke.csv', 'csv' );
		$csv_ok      = ( $csv_res['http'] === 200 )
			&& ! empty( $csv_res['success'] )
			&& ( (int) $csv_res['count'] >= 1 );
		$ctx->emit_step( array(
			'label'  => 'Layer 3 · Runtime · csv upload',
			'status' => $csv_ok ? 'pass' : 'fail',
			'detail' => $csv_ok
				? 'success=true, count=' . (int) $csv_res['count']
				: 'http=' . (int) $csv_res['http'] . ', success=' . ( $csv_res['success'] ? 'true' : 'false' ) . ', error=' . (string) $csv_res['error'],
		) );
		if ( ! $csv_ok ) {
			$failures[] = 'runtime_csv_failed';
		}

		// Runtime 2: XLSX smoke.
		if ( ! class_exists( 'ZipArchive' ) ) {
			$ctx->emit_step( array(
				'label'  => 'Layer 3 · Runtime · xlsx upload',
				'status' => 'skip',
				'detail' => 'ZipArchive unavailable; cannot generate XLSX fixture.',
			) );
			$skips[] = 'xlsx_ziparchive_unavailable';
		} else {
			$xlsx_fixture = $this->create_xlsx_fixture();
			$xlsx_res     = $this->dispatch_parse_file_upload( $xlsx_fixture, 'smoke.xlsx', 'xlsx' );
			$xlsx_ok      = ( $xlsx_res['http'] === 200 )
				&& ! empty( $xlsx_res['success'] )
				&& ( (int) $xlsx_res['count'] >= 1 );
			$ctx->emit_step( array(
				'label'  => 'Layer 3 · Runtime · xlsx upload',
				'status' => $xlsx_ok ? 'pass' : 'fail',
				'detail' => $xlsx_ok
					? 'success=true, count=' . (int) $xlsx_res['count']
					: 'http=' . (int) $xlsx_res['http'] . ', success=' . ( $xlsx_res['success'] ? 'true' : 'false' ) . ', error=' . (string) $xlsx_res['error'],
			) );
			if ( ! $xlsx_ok ) {
				$failures[] = 'runtime_xlsx_failed';
			}
		}

		// Runtime 3: XLS smoke (requires PhpSpreadsheet writer to create fixture).
		$xls_fixture = $this->create_xls_fixture();
		if ( $xls_fixture === '' ) {
			$ctx->emit_step( array(
				'label'  => 'Layer 3 · Runtime · xls upload',
				'status' => 'skip',
				'detail' => 'PhpSpreadsheet writer unavailable; xls runtime smoke skipped.',
			) );
			$skips[] = 'xls_writer_unavailable';
		} else {
			$xls_res = $this->dispatch_parse_file_upload( $xls_fixture, 'smoke.xls', 'xls' );
			$xls_ok  = ( $xls_res['http'] === 200 )
				&& ! empty( $xls_res['success'] )
				&& ( (int) $xls_res['count'] >= 1 );
			$ctx->emit_step( array(
				'label'  => 'Layer 3 · Runtime · xls upload',
				'status' => $xls_ok ? 'pass' : 'fail',
				'detail' => $xls_ok
					? 'success=true, count=' . (int) $xls_res['count']
					: 'http=' . (int) $xls_res['http'] . ', success=' . ( $xls_res['success'] ? 'true' : 'false' ) . ', error=' . (string) $xls_res['error'],
			) );
			if ( ! $xls_ok ) {
				$failures[] = 'runtime_xls_failed';
			}
		}

		// Runtime 4: Google Sheet URL smoke via mocked HTTP response.
		$gsheet_res = $this->dispatch_parse_google_sheet_mocked();
		$gsheet_ok  = ( $gsheet_res['http'] === 200 )
			&& ! empty( $gsheet_res['success'] )
			&& ( (int) $gsheet_res['count'] >= 1 );
		$ctx->emit_step( array(
			'label'  => 'Layer 3 · Runtime · google_sheet_url (mock)',
			'status' => $gsheet_ok ? 'pass' : 'fail',
			'detail' => $gsheet_ok
				? 'success=true, count=' . (int) $gsheet_res['count']
				: 'http=' . (int) $gsheet_res['http'] . ', success=' . ( $gsheet_res['success'] ? 'true' : 'false' ) . ', error=' . (string) $gsheet_res['error'],
		) );
		if ( ! $gsheet_ok ) {
			$failures[] = 'runtime_google_sheet_failed';
		}

		// Runtime 5: Google Sheet invalid URL should fail-open with stable error code.
		$gsheet_bad = $this->dispatch_parse_google_sheet_invalid_url();
		$bad_ok     = ( $gsheet_bad['http'] === 200 )
			&& empty( $gsheet_bad['success'] )
			&& ( (string) $gsheet_bad['error'] === 'invalid_google_sheet_url' );
		$ctx->emit_step( array(
			'label'  => 'Layer 3 · Runtime · google_sheet_url invalid',
			'status' => $bad_ok ? 'pass' : 'fail',
			'detail' => $bad_ok
				? 'success=false, error=invalid_google_sheet_url'
				: 'http=' . (int) $gsheet_bad['http'] . ', success=' . ( $gsheet_bad['success'] ? 'true' : 'false' ) . ', error=' . (string) $gsheet_bad['error'],
		) );
		if ( ! $bad_ok ) {
			$failures[] = 'runtime_google_sheet_invalid_contract';
		}

		if ( ! empty( $failures ) ) {
			$summary = 'Broadcast import matrix failed: ' . implode( ', ', $failures );
			if ( ! empty( $skips ) ) {
				$summary .= ' (skipped: ' . implode( ', ', $skips ) . ')';
			}
			return array(
				'status'   => 'fail',
				'summary'  => $summary,
				'error'    => implode( '; ', $failures ),
				'fix_hint' => 'Kiểm tra class-broadcast-rest parser switch-case + route parse-file + env dependencies (ZipArchive/PhpSpreadsheet).',
			);
		}

		$summary = 'Broadcast import matrix passed (csv/xlsx/google sheet).';
		if ( ! empty( $skips ) ) {
			$summary .= ' Skipped: ' . implode( ', ', $skips ) . '.';
		}

		return array(
			'status'  => 'pass',
			'summary' => $summary,
		);
	}

	public function cleanup(): void {
		foreach ( $this->tmp_files as $f ) {
			if ( is_string( $f ) && $f !== '' && file_exists( $f ) ) {
				@unlink( $f );
			}
		}
		$this->tmp_files = array();
	}

	/**
	 * @param string $suffix
	 * @return string
	 */
	private function alloc_tmp_file( $suffix ) {
		$suffix = ltrim( (string) $suffix, '.' );
		$path   = '';
		if ( function_exists( 'wp_tempnam' ) ) {
			$path = (string) wp_tempnam( 'bz_probe_' . $suffix );
		}
		if ( $path === '' ) {
			$path = (string) tempnam( sys_get_temp_dir(), 'bz_probe_' );
		}
		if ( $path === '' ) {
			return '';
		}
		if ( $suffix !== '' && substr( $path, - ( strlen( $suffix ) + 1 ) ) !== ( '.' . $suffix ) ) {
			$renamed = $path . '.' . $suffix;
			@rename( $path, $renamed );
			$path = $renamed;
		}
		$this->tmp_files[] = $path;
		return $path;
	}

	/**
	 * @return string
	 */
	private function create_csv_fixture() {
		$path = $this->alloc_tmp_file( 'csv' );
		if ( $path === '' ) {
			return '';
		}
		$csv = "\xEF\xBB\xBF"
			. "họ tên;điện thoại;email;tags\n"
			. "Nguyễn Văn A;0901234567;a@example.com;vip\n"
			. "Trần Thị B;0912345678;b@example.com;new\n";
		file_put_contents( $path, $csv ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
		return $path;
	}

	/**
	 * @return string
	 */
	private function create_xlsx_fixture() {
		$path = $this->alloc_tmp_file( 'xlsx' );
		if ( $path === '' ) {
			return '';
		}
		$matrix = array(
			array( 'họ tên', 'điện thoại', 'email', 'tags' ),
			array( 'Nguyen Van A', '0901234567', 'a@example.com', 'vip' ),
			array( 'Tran Thi B', '0912345678', 'b@example.com', 'new' ),
		);
		if ( ! $this->build_xlsx( $path, $matrix ) ) {
			return '';
		}
		return $path;
	}

	/**
	 * @return string
	 */
	private function create_xls_fixture() {
		if ( ! class_exists( '\\PhpOffice\\PhpSpreadsheet\\Spreadsheet' ) || ! class_exists( '\\PhpOffice\\PhpSpreadsheet\\Writer\\Xls' ) ) {
			return '';
		}

		$path = $this->alloc_tmp_file( 'xls' );
		if ( $path === '' ) {
			return '';
		}

		try {
			$spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
			$sheet = $spreadsheet->getActiveSheet();
			$sheet->fromArray( array(
				array( 'họ tên', 'điện thoại', 'email', 'tags' ),
				array( 'Nguyen Van A', '0901234567', 'a@example.com', 'vip' ),
			), null, 'A1' );

			$writer = new \PhpOffice\PhpSpreadsheet\Writer\Xls( $spreadsheet );
			$writer->save( $path );

			$spreadsheet->disconnectWorksheets();
			unset( $spreadsheet );
		} catch ( \Throwable $e ) {
			return '';
		}

		return file_exists( $path ) && filesize( $path ) > 0 ? $path : '';
	}

	/**
	 * @param  string $tmp_file
	 * @param  string $filename
	 * @param  string $source_kind
	 * @return array
	 */
	private function dispatch_parse_file_upload( $tmp_file, $filename, $source_kind ) {
		if ( ! is_string( $tmp_file ) || $tmp_file === '' || ! file_exists( $tmp_file ) ) {
			return array( 'http' => 0, 'success' => false, 'count' => 0, 'error' => 'fixture_missing' );
		}

		$req = new WP_REST_Request( 'POST', '/bizcity-channel/v1/broadcasts/parse-file' );
		if ( is_string( $source_kind ) && $source_kind !== '' ) {
			$req->set_param( 'source_kind', $source_kind );
		}
		$req->set_file_params( array(
			'file' => array(
				'name'     => (string) $filename,
				'type'     => 'application/octet-stream',
				'tmp_name' => $tmp_file,
				'error'    => 0,
				'size'     => (int) filesize( $tmp_file ),
			),
		) );

		$res  = rest_do_request( $req );
		$data = $res instanceof WP_REST_Response ? $res->get_data() : array();
		if ( ! is_array( $data ) ) {
			$data = array();
		}

		return array(
			'http'    => $res instanceof WP_REST_Response ? (int) $res->get_status() : 0,
			'success' => ! empty( $data['success'] ),
			'count'   => (int) ( $data['count'] ?? 0 ),
			'error'   => (string) ( $data['error'] ?? '' ),
		);
	}

	/**
	 * @return array
	 */
	private function dispatch_parse_google_sheet_mocked() {
		$mock = static function ( $preempt, $args, $url ) {
			if ( strpos( (string) $url, 'docs.google.com/spreadsheets/d/' ) !== false && strpos( (string) $url, 'export?format=csv' ) !== false ) {
				$body = "họ tên,điện thoại,email\nNguyen Van C,0909999999,c@example.com\n";
				return array(
					'headers'  => array(),
					'body'     => $body,
					'response' => array( 'code' => 200, 'message' => 'OK' ),
					'cookies'  => array(),
					'filename' => null,
				);
			}
			return $preempt;
		};

		add_filter( 'pre_http_request', $mock, 10, 3 );
		try {
			$req = new WP_REST_Request( 'POST', '/bizcity-channel/v1/broadcasts/parse-file' );
			$req->set_param( 'source_kind', 'google_sheet_url' );
			$req->set_param( 'source_url', 'https://docs.google.com/spreadsheets/d/abc123456/edit#gid=0' );
			$res  = rest_do_request( $req );
			$data = $res instanceof WP_REST_Response ? $res->get_data() : array();
			if ( ! is_array( $data ) ) {
				$data = array();
			}
			return array(
				'http'    => $res instanceof WP_REST_Response ? (int) $res->get_status() : 0,
				'success' => ! empty( $data['success'] ),
				'count'   => (int) ( $data['count'] ?? 0 ),
				'error'   => (string) ( $data['error'] ?? '' ),
			);
		} finally {
			remove_filter( 'pre_http_request', $mock, 10 );
		}
	}

	/**
	 * @return array
	 */
	private function dispatch_parse_google_sheet_invalid_url() {
		$req = new WP_REST_Request( 'POST', '/bizcity-channel/v1/broadcasts/parse-file' );
		$req->set_param( 'source_kind', 'google_sheet_url' );
		$req->set_param( 'source_url', 'https://example.com/not-google-sheet' );
		$res  = rest_do_request( $req );
		$data = $res instanceof WP_REST_Response ? $res->get_data() : array();
		if ( ! is_array( $data ) ) {
			$data = array();
		}
		return array(
			'http'    => $res instanceof WP_REST_Response ? (int) $res->get_status() : 0,
			'success' => ! empty( $data['success'] ),
			'count'   => (int) ( $data['count'] ?? 0 ),
			'error'   => (string) ( $data['error'] ?? '' ),
		);
	}

	/**
	 * Build a minimal XLSX package from matrix rows.
	 *
	 * @param  string $tmp_file
	 * @param  array  $matrix
	 * @return bool
	 */
	private function build_xlsx( $tmp_file, array $matrix ) {
		if ( ! class_exists( 'ZipArchive' ) || $tmp_file === '' || empty( $matrix ) ) {
			return false;
		}

		$string_index = array();
		$shared       = array();
		$sheet_rows   = array();

		foreach ( $matrix as $row_idx => $cells ) {
			$r_num   = $row_idx + 1;
			$cells_x = array();
			$cells   = is_array( $cells ) ? $cells : array();
			foreach ( $cells as $col_idx => $value ) {
				$key = (string) $value;
				if ( ! isset( $string_index[ $key ] ) ) {
					$string_index[ $key ] = count( $shared );
					$shared[] = $key;
				}
				$ref     = $this->col_to_letter( (int) $col_idx ) . $r_num;
				$s_index = (int) $string_index[ $key ];
				$cells_x[] = '<c r="' . $ref . '" t="s"><v>' . $s_index . '</v></c>';
			}
			$sheet_rows[] = '<row r="' . $r_num . '">' . implode( '', $cells_x ) . '</row>';
		}

		$shared_xml_items = array();
		foreach ( $shared as $str ) {
			$shared_xml_items[] = '<si><t>' . htmlspecialchars( (string) $str, ENT_XML1, 'UTF-8' ) . '</t></si>';
		}

		$xml_content_types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
			. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
			. '<Default Extension="xml" ContentType="application/xml"/>'
			. '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
			. '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
			. '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
			. '</Types>';

		$xml_rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
			. '</Relationships>';

		$xml_workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
			. '<sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets>'
			. '</workbook>';

		$xml_workbook_rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
			. '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
			. '</Relationships>';

		$xml_sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
			. '<sheetData>' . implode( '', $sheet_rows ) . '</sheetData>'
			. '</worksheet>';

		$xml_shared = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count( $shared ) . '" uniqueCount="' . count( $shared ) . '">'
			. implode( '', $shared_xml_items )
			. '</sst>';

		$zip = new ZipArchive();
		$ok  = $zip->open( $tmp_file, ZipArchive::OVERWRITE );
		if ( true !== $ok ) {
			return false;
		}
		$zip->addFromString( '[Content_Types].xml', $xml_content_types );
		$zip->addFromString( '_rels/.rels', $xml_rels );
		$zip->addFromString( 'xl/workbook.xml', $xml_workbook );
		$zip->addFromString( 'xl/_rels/workbook.xml.rels', $xml_workbook_rels );
		$zip->addFromString( 'xl/worksheets/sheet1.xml', $xml_sheet );
		$zip->addFromString( 'xl/sharedStrings.xml', $xml_shared );
		$zip->close();

		return file_exists( $tmp_file ) && filesize( $tmp_file ) > 0;
	}

	/**
	 * @param  int $index
	 * @return string
	 */
	private function col_to_letter( $index ) {
		$index  = (int) $index;
		$letter = '';
		while ( $index >= 0 ) {
			$letter = chr( ( $index % 26 ) + 65 ) . $letter;
			$index  = (int) floor( $index / 26 ) - 1;
		}
		return $letter;
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Channel_Broadcast_Import_Matrix';
	return $list;
} );
