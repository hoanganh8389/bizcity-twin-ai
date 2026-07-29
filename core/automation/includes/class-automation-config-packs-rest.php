<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Automation
 * @license    GPL-2.0-or-later
 *
 * Wave 1 DataTable config-pack REST API.
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Automation_Config_Packs_REST {

	const NS = 'bizcity-automation/v1';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		// [2026-07-20 Johnny Chu] PHASE-1-TEMPLATES-AUTOMATION — Wave 1 parse/save/edit config packs.
		register_rest_route( self::NS, '/config-packs/parse', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'parse' ),
			'permission_callback' => array( __CLASS__, 'admin_only' ),
		) );

		register_rest_route( self::NS, '/config-packs/sample-csv', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'sample_csv' ),
			'permission_callback' => array( __CLASS__, 'admin_only' ),
		) );

		register_rest_route( self::NS, '/config-packs', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'list_packs' ),
				'permission_callback' => array( __CLASS__, 'admin_only' ),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'create_pack' ),
				'permission_callback' => array( __CLASS__, 'admin_only' ),
			),
		) );

		register_rest_route( self::NS, '/config-packs/(?P<id>\d+)', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_pack' ),
			'permission_callback' => array( __CLASS__, 'admin_only' ),
		) );

		register_rest_route( self::NS, '/config-packs/(?P<id>\d+)/rows/(?P<row_id>\d+)', array(
			'methods'             => array( 'PATCH', 'PUT' ),
			'callback'            => array( __CLASS__, 'update_row' ),
			'permission_callback' => array( __CLASS__, 'admin_only' ),
		) );

		register_rest_route( self::NS, '/config-packs/(?P<id>\d+)/rows/bulk', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'bulk_rows' ),
			'permission_callback' => array( __CLASS__, 'admin_only' ),
		) );

		register_rest_route( self::NS, '/config-packs/(?P<id>\d+)/validate', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'validate_pack' ),
			'permission_callback' => array( __CLASS__, 'admin_only' ),
		) );

		register_rest_route( self::NS, '/config-packs/(?P<id>\d+)/activate', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'activate_deferred' ),
			'permission_callback' => array( __CLASS__, 'admin_only' ),
		) );
	}

	public static function admin_only(): bool {
		return current_user_can( 'manage_options' );
	}

	public static function parse( WP_REST_Request $request ) {
		$schema_key = sanitize_key( (string) $request->get_param( 'schema_key' ) );
		$raw_csv    = (string) $request->get_param( 'raw_csv' );
		$source_url = trim( (string) $request->get_param( 'source_url' ) );

		if ( $raw_csv === '' && $source_url !== '' ) {
			$raw_csv = self::fetch_csv_url( $source_url );
			if ( is_wp_error( $raw_csv ) ) {
				return $raw_csv;
			}
		}
		if ( $raw_csv === '' ) {
			return new WP_Error( 'content_plan_csv_invalid', 'File CSV chưa đúng mẫu kế hoạch nội dung.', array( 'status' => 400 ) );
		}

		$parsed = self::parse_csv( $raw_csv );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		return rest_ensure_response( array(
			'ok'             => true,
			'schema_key'     => $schema_key ?: 'content_calendar',
			'columns'        => $parsed['columns'],
			'rows'           => $parsed['rows'],
			'rows_count'     => count( $parsed['rows'] ),
			'rejected_count' => 0,
			'source_type'    => $source_url ? 'csv_url' : 'raw_csv',
			'source_ref'     => $source_url,
		) );
	}

	public static function sample_csv( WP_REST_Request $request ) {
		$schema_key = sanitize_key( (string) $request->get_param( 'schema_key' ) );
		$map = array(
			'content_calendar' => 'content-calendar-30d-sample.csv',
			'product_catalog' => 'product-catalog-sample.csv',
			'automation_scenarios' => 'automation-scenario-config-sample.csv',
		);
		if ( ! isset( $map[ $schema_key ] ) ) {
			$schema_key = 'content_calendar';
		}
		$file = trailingslashit( BIZCITY_AUTOMATION_DIR ) . 'templates/' . $map[ $schema_key ];
		if ( ! is_readable( $file ) ) {
			return new WP_Error( 'not_found', 'File CSV mẫu không tồn tại.', array( 'status' => 404 ) );
		}
		// [2026-07-20 Johnny Chu] PHASE-1-TEMPLATES-AUTOMATION — load same-source sample CSV into DataTable Studio.
		return rest_ensure_response( array(
			'ok'         => true,
			'schema_key' => $schema_key,
			'filename'   => $map[ $schema_key ],
			'raw_csv'    => (string) file_get_contents( $file ),
		) );
	}

	public static function list_packs( WP_REST_Request $request ) {
		return rest_ensure_response( BizCity_Automation_Repo_Config_Packs::query_packs( array(
			'schema_key' => sanitize_key( (string) $request->get_param( 'schema_key' ) ),
			'status'     => sanitize_key( (string) $request->get_param( 'status' ) ),
			'search'     => sanitize_text_field( (string) $request->get_param( 'search' ) ),
			'limit'      => (int) $request->get_param( 'limit' ),
			'offset'     => (int) $request->get_param( 'offset' ),
		) ) );
	}

	public static function create_pack( WP_REST_Request $request ) {
		$rows = $request->get_param( 'rows' );
		if ( ! is_array( $rows ) ) {
			return new WP_Error( 'config_pack_invalid', 'Bảng cấu hình còn dòng lỗi.', array( 'status' => 400 ) );
		}
		$result = BizCity_Automation_Repo_Config_Packs::create_pack( array(
			'name'          => (string) $request->get_param( 'name' ),
			'schema_key'    => (string) $request->get_param( 'schema_key' ),
			'guru_id'       => (int) $request->get_param( 'guru_id' ),
			'owner_user_id' => (int) get_current_user_id(),
			'source_type'   => (string) $request->get_param( 'source_type' ),
			'source_ref'    => (string) $request->get_param( 'source_ref' ),
			'rows'          => $rows,
		) );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function get_pack( WP_REST_Request $request ) {
		$pack = BizCity_Automation_Repo_Config_Packs::find_pack( (int) $request['id'], true );
		if ( ! $pack ) {
			return new WP_Error( 'not_found', 'Bảng cấu hình không tồn tại.', array( 'status' => 404 ) );
		}
		return rest_ensure_response( $pack );
	}

	public static function update_row( WP_REST_Request $request ) {
		$row_json = $request->get_param( 'row_json' );
		if ( ! is_array( $row_json ) ) {
			return new WP_Error( 'config_row_invalid', 'Dòng cấu hình chưa đủ dữ liệu bắt buộc.', array( 'status' => 400 ) );
		}
		$result = BizCity_Automation_Repo_Config_Packs::update_row( (int) $request['id'], (int) $request['row_id'], $row_json );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function bulk_rows( WP_REST_Request $request ) {
		$rows = $request->get_param( 'rows' );
		if ( ! is_array( $rows ) ) {
			return new WP_Error( 'config_pack_invalid', 'Bảng cấu hình còn dòng lỗi.', array( 'status' => 400 ) );
		}
		$normalized_rows = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$normalized_rows[] = isset( $row['row_json'] ) && is_array( $row['row_json'] ) ? $row['row_json'] : $row;
		}
		// [2026-07-20 Johnny Chu] PHASE-1-TEMPLATES-AUTOMATION — save added rows/columns while keeping activation manual for Wave 2.
		$result = BizCity_Automation_Repo_Config_Packs::replace_all_rows( (int) $request['id'], $normalized_rows, array(
			'name' => (string) $request->get_param( 'name' ),
		) );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function validate_pack( WP_REST_Request $request ) {
		$result = BizCity_Automation_Repo_Config_Packs::validate_pack( (int) $request['id'] );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function activate_deferred( WP_REST_Request $request ) {
		$pack = BizCity_Automation_Repo_Config_Packs::find_pack( (int) $request['id'], false );
		if ( ! $pack ) {
			return new WP_Error( 'not_found', 'Bảng cấu hình không tồn tại.', array( 'status' => 404 ) );
		}
		// [2026-07-20 Johnny Chu] PHASE-1-TEMPLATES-AUTOMATION — explicit Wave 2 gate: never create workflows during Wave 1 import/edit.
		return rest_ensure_response( array(
			'ok'        => false,
			'_deferred' => true,
			'code'      => 'wave2_not_enabled',
			'message'   => 'Wave 2 automation activation chưa bật.',
			'hint'      => 'Lưu và validate bảng cấu hình; bước tạo workflow sẽ được mở ở Wave 2.',
			'pack_id'   => (int) $pack['id'],
		) );
	}

	private static function parse_csv( string $raw ) {
		$raw = preg_replace( '/^\xEF\xBB\xBF/', '', $raw );
		$lines = preg_split( "/\r\n|\n|\r/", (string) $raw );
		$lines = array_values( array_filter( $lines, static function ( $line ) { return trim( (string) $line ) !== ''; } ) );
		if ( count( $lines ) < 2 ) {
			return new WP_Error( 'content_plan_csv_invalid', 'File CSV chưa đúng mẫu kế hoạch nội dung.', array( 'status' => 400 ) );
		}
		if ( count( $lines ) > 500 ) {
			return new WP_Error( 'content_plan_csv_too_large', 'File CSV vượt giới hạn cho phép.', array( 'status' => 413 ) );
		}

		$columns = array_map( array( __CLASS__, 'sanitize_header' ), str_getcsv( array_shift( $lines ) ) );
		$rows = array();
		foreach ( $lines as $line ) {
			$values = str_getcsv( $line );
			$row = array();
			foreach ( $columns as $index => $column ) {
				if ( $column === '' ) {
					continue;
				}
				$row[ $column ] = isset( $values[ $index ] ) ? sanitize_textarea_field( (string) $values[ $index ] ) : '';
			}
			$rows[] = $row;
		}
		return array( 'columns' => $columns, 'rows' => $rows );
	}

	private static function fetch_csv_url( string $url ) {
		$url = self::normalize_google_sheet_url( $url );
		$parts = wp_parse_url( $url );
		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return new WP_Error( 'content_plan_csv_invalid', 'File CSV chưa đúng mẫu kế hoạch nội dung.', array( 'status' => 400 ) );
		}
		$ip = gethostbyname( $parts['host'] );
		if ( self::is_private_ip( $ip ) ) {
			return new WP_Error( 'content_plan_csv_invalid', 'File CSV chưa đúng mẫu kế hoạch nội dung.', array( 'status' => 400 ) );
		}
		$res = wp_remote_get( $url, array( 'timeout' => 8, 'redirection' => 2, 'limit_response_size' => 524288 ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$body = (string) wp_remote_retrieve_body( $res );
		if ( stripos( $body, '<html' ) !== false || stripos( $body, '<!doctype html' ) !== false ) {
			return new WP_Error( 'content_plan_csv_private', 'Link CSV/Google Sheet chưa truy cập công khai.', array( 'status' => 400 ) );
		}
		return $body;
	}

	private static function normalize_google_sheet_url( string $url ): string {
		if ( strpos( $url, 'docs.google.com/spreadsheets' ) === false || strpos( $url, '/edit' ) === false ) {
			return esc_url_raw( $url );
		}
		if ( ! preg_match( '#/spreadsheets/d/([^/]+)#', $url, $m ) ) {
			return esc_url_raw( $url );
		}
		$gid = '0';
		$parts = wp_parse_url( $url );
		if ( ! empty( $parts['fragment'] ) && preg_match( '/gid=([0-9]+)/', $parts['fragment'], $gm ) ) {
			$gid = $gm[1];
		}
		return 'https://docs.google.com/spreadsheets/d/' . rawurlencode( $m[1] ) . '/export?format=csv&gid=' . rawurlencode( $gid );
	}

	private static function sanitize_header( string $header ): string {
		$header = strtolower( remove_accents( trim( $header ) ) );
		$header = preg_replace( '/[^a-z0-9_]+/', '_', $header );
		$header = trim( (string) $header, '_' );
		$aliases = array(
			'ten_san_pham' => 'product_name',
			'san_pham' => 'product_name',
			'gia_ban' => 'price_vnd',
			'gia' => 'price_vnd',
			'price' => 'price_vnd',
			'diem_khac_biet' => 'usp',
			'loi_the' => 'usp',
			'san_pham_doi_chung' => 'comparison_product',
			'doi_chung' => 'comparison_product',
			'lich_chay' => 'schedule',
			'cron' => 'schedule',
			'kenh' => 'channel',
			'platform' => 'channel',
			'tieu_de' => 'title',
			'noi_dung' => 'content',
			'goi_y_hinh' => 'image_brief',
			'hinh_anh' => 'image_brief',
			'hashtag' => 'hashtags',
		);
		// [2026-07-20 Johnny Chu] PHASE-1-TEMPLATES-AUTOMATION — map Excel/Vietnamese aliases to canonical columns.
		return $aliases[ $header ] ?? $header;
	}

	private static function is_private_ip( string $ip ): bool {
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return true;
		}
		return ! (bool) filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}
}