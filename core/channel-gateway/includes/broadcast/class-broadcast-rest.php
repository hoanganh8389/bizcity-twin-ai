<?php
/**
 * Broadcast REST Controller.
 *
 * Namespace: bizcity-channel/v1 (R-CH-NS)
 * Routes:
 *   GET    /broadcasts               — list broadcasts
 *   POST   /broadcasts               — create broadcast (+ import recipients)
 *   GET    /broadcasts/{id}          — get single broadcast
 *   DELETE /broadcasts/{id}          — delete
 *   POST   /broadcasts/{id}/start    — start sending
 *   POST   /broadcasts/{id}/pause    — pause
 *   POST   /broadcasts/{id}/cancel   — cancel
 *   GET    /broadcasts/{id}/progress — live counters
 *   POST   /broadcasts/parse-file    — parse CSV/XLSX → recipient preview
 *   GET    /broadcasts/contacts      — CRM contacts for recipient selector
 *
 * Security: manage_options + WP Nonce (R-GW-8).
 * All routes return HTTP 200, success:false on errors (fail-OPEN — R-GW-8).
 *
 * [2026-06-27 Johnny Chu] PHASE-CG-BROADCAST — new REST controller.
 *
 * @package BizCity_Channel_Gateway
 * @since   PHASE-CG-BROADCAST (2026-06-27)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Broadcast_REST' ) ) {
	return;
}

class BizCity_Broadcast_REST {

	const NS = 'bizcity-channel/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		// [2026-06-27 Johnny Chu] PHASE-CG-BROADCAST — register all broadcast routes

		// GET|POST /broadcasts
		register_rest_route( self::NS, '/broadcasts', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_broadcasts' ),
				'permission_callback' => array( __CLASS__, 'auth' ),
				'args'                => array(
					'status'   => array( 'required' => false, 'sanitize_callback' => 'sanitize_key',          'default' => '' ),
					'page'     => array( 'required' => false, 'sanitize_callback' => 'absint',                'default' => 1 ),
					'per_page' => array( 'required' => false, 'sanitize_callback' => 'absint',                'default' => 20 ),
					'q'        => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field',   'default' => '' ),
				),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'create_broadcast' ),
				'permission_callback' => array( __CLASS__, 'auth' ),
			),
		) );

		// GET /broadcasts/template — download CSV mẫu (no auth required — public asset)
		register_rest_route( self::NS, '/broadcasts/template', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'download_template' ),
			'permission_callback' => '__return_true',
		) );

		// GET /broadcasts/contacts — must be before /broadcasts/{id}
		register_rest_route( self::NS, '/broadcasts/contacts', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get_contacts' ),
			'permission_callback' => array( __CLASS__, 'auth' ),
			'args'                => array(
				'q'     => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ),
				'limit' => array( 'required' => false, 'sanitize_callback' => 'absint',              'default' => 200 ),
			),
		) );

		// POST /broadcasts/parse-file — parse CSV/XLSX
		register_rest_route( self::NS, '/broadcasts/parse-file', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'parse_file' ),
			'permission_callback' => array( __CLASS__, 'auth' ),
		) );

		// GET /broadcasts/{id}
		register_rest_route( self::NS, '/broadcasts/(?P<id>\\d+)', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_broadcast' ),
				'permission_callback' => array( __CLASS__, 'auth' ),
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => array( __CLASS__, 'delete_broadcast' ),
				'permission_callback' => array( __CLASS__, 'auth' ),
			),
		) );

		// POST /broadcasts/{id}/start|pause|cancel
		foreach ( array( 'start', 'pause', 'cancel' ) as $action ) {
			register_rest_route( self::NS, '/broadcasts/(?P<id>\\d+)/' . $action, array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_action_' . $action ),
				'permission_callback' => array( __CLASS__, 'auth' ),
			) );
		}

		// GET /broadcasts/{id}/progress
		register_rest_route( self::NS, '/broadcasts/(?P<id>\\d+)/progress', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get_progress' ),
			'permission_callback' => array( __CLASS__, 'auth' ),
		) );
	}

	// ── Permission ────────────────────────────────────────────────────────────

	public static function auth() {
		return current_user_can( 'manage_options' );
	}

	// ── Endpoints ─────────────────────────────────────────────────────────────

	/**
	 * GET /broadcasts/template — stream CSV sample file.
	 * [2026-06-27 Johnny Chu] PHASE-CG-BROADCAST — sample file download.
	 */
	public static function download_template() {
		$path = plugin_dir_path( __FILE__ ) . '../../../assets/broadcast-template.csv';
		$path = realpath( $path );

		if ( ! $path || ! file_exists( $path ) ) {
			// Fallback: build inline
			$csv = "name,phone,email\n"
				. "\xEF\xBB\xBF" // UTF-8 BOM ở đầu để Excel mở không bị lỗi tiếng Việt
				. "Nguyễn Văn An,0901234567,an@example.com\n"
				. "Trần Thị Bình,0912345678,binh@example.com\n"
				. "Lê Văn Cường,0923456789,cuong@example.com\n";
			header( 'Content-Type: text/csv; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="broadcast-template.csv"' );
			echo $csv;
			exit;
		}

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="broadcast-template.csv"' );
		header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path );
		exit;
	}

	public static function list_broadcasts( $request ) {
		$result = BizCity_Broadcast_Manager::get_list( array(
			'status'   => $request->get_param( 'status' ),
			'page'     => $request->get_param( 'page' ),
			'per_page' => $request->get_param( 'per_page' ),
			'q'        => $request->get_param( 'q' ),
		) );
		return rest_ensure_response( array_merge( array( 'success' => true ), $result ) );
	}

	public static function get_broadcast( $request ) {
		$bc = BizCity_Broadcast_Manager::get_one( (int) $request['id'] );
		if ( ! $bc ) {
			return rest_ensure_response( array( 'success' => false, 'error' => 'not_found' ) );
		}
		$bc['progress'] = BizCity_Broadcast_Manager::get_progress( (int) $request['id'] );
		return rest_ensure_response( array( 'success' => true, 'broadcast' => $bc ) );
	}

	/**
	 * POST /broadcasts — create broadcast.
	 *
	 * Body params:
	 *   name           string  required
	 *   type           string  'zns' | 'email'
	 *   meta           object  (template config)
	 *   recipients     array   [ { name, phone, email } ]
	 *   batch_size     int     default 10
	 *   delay_sec      int     default 5
	 *   auto_start     bool    default false
	 */
	public static function create_broadcast( $request ) {
		$body = $request->get_json_params();
		if ( ! $body ) {
			$body = $request->get_params();
		}

		$name       = sanitize_text_field( (string) ( $body['name'] ?? '' ) );
		$type       = sanitize_key( (string) ( $body['type'] ?? 'zns' ) );
		$meta       = isset( $body['meta'] ) && is_array( $body['meta'] ) ? $body['meta'] : array();
		$recipients = isset( $body['recipients'] ) && is_array( $body['recipients'] ) ? $body['recipients'] : array();
		$batch_size = max( 1, min( 100, (int) ( $body['batch_size'] ?? 10 ) ) );
		$delay_sec  = (int) ( $body['delay_sec'] ?? 5 );
		$auto_start = ! empty( $body['auto_start'] );

		if ( ! $name ) {
			return rest_ensure_response( array( 'success' => false, 'error' => 'missing_name' ) );
		}
		if ( ! in_array( $type, array( 'zns', 'email' ), true ) ) {
			return rest_ensure_response( array( 'success' => false, 'error' => 'invalid_type' ) );
		}
		if ( empty( $recipients ) ) {
			return rest_ensure_response( array( 'success' => false, 'error' => 'no_recipients' ) );
		}

		// Validate per type
		if ( 'zns' === $type && empty( $meta['temp_id'] ) ) {
			return rest_ensure_response( array( 'success' => false, 'error' => 'missing_temp_id' ) );
		}
		if ( 'email' === $type && ( empty( $meta['email_subject'] ) || empty( $meta['email_body'] ) ) ) {
			return rest_ensure_response( array( 'success' => false, 'error' => 'missing_email_template' ) );
		}

		// Sanitize ZNS meta
		if ( 'zns' === $type ) {
			$meta['temp_id'] = sanitize_text_field( (string) ( $meta['temp_id'] ?? '' ) );
			$meta['oa_id']   = sanitize_text_field( (string) ( $meta['oa_id'] ?? '' ) );
			$meta['sandbox'] = ! empty( $meta['sandbox'] );
			if ( isset( $meta['temp_vars'] ) && is_array( $meta['temp_vars'] ) ) {
				$meta['temp_vars'] = array_values( array_filter( $meta['temp_vars'], function ( $tv ) {
					return ! empty( $tv['var_name'] );
				} ) );
			}
		}

		// Sanitize email meta
		if ( 'email' === $type ) {
			$meta['email_subject']    = sanitize_text_field( (string) ( $meta['email_subject'] ?? '' ) );
			$meta['email_body']       = wp_kses_post( (string) ( $meta['email_body'] ?? '' ) );
			$meta['email_account_uid']= sanitize_text_field( (string) ( $meta['email_account_uid'] ?? '' ) );
			$meta['email_from']       = sanitize_email( (string) ( $meta['email_from'] ?? '' ) );
			$meta['email_from_name']  = sanitize_text_field( (string) ( $meta['email_from_name'] ?? '' ) );
		}

		// Create broadcast
		$bc_id = BizCity_Broadcast_Manager::insert( array(
			'name'       => $name,
			'type'       => $type,
			'meta_json'  => $meta,
			'batch_size' => $batch_size,
			'delay_sec'  => $delay_sec,
		) );

		if ( ! $bc_id ) {
			return rest_ensure_response( array( 'success' => false, 'error' => 'db_insert_failed' ) );
		}

		// Import recipients
		$imported = BizCity_Broadcast_Manager::add_recipients( $bc_id, $recipients );

		// Auto-start if requested
		if ( $auto_start && $imported > 0 ) {
			BizCity_Broadcast_Manager::update( $bc_id, array(
				'status'     => 'sending',
				'started_at' => current_time( 'mysql' ),
			) );
		}

		return rest_ensure_response( array(
			'success'    => true,
			'id'         => $bc_id,
			'imported'   => $imported,
			'status'     => $auto_start ? 'sending' : 'draft',
		) );
	}

	public static function delete_broadcast( $request ) {
		$id = (int) $request['id'];
		$bc = BizCity_Broadcast_Manager::get_one( $id );
		if ( ! $bc ) {
			return rest_ensure_response( array( 'success' => false, 'error' => 'not_found' ) );
		}
		if ( in_array( $bc['status'], array( 'sending' ), true ) ) {
			return rest_ensure_response( array( 'success' => false, 'error' => 'cannot_delete_active' ) );
		}
		BizCity_Broadcast_Manager::delete( $id );
		return rest_ensure_response( array( 'success' => true ) );
	}

	public static function handle_action_start( $request ) {
		$id = (int) $request['id'];
		$bc = BizCity_Broadcast_Manager::get_one( $id );
		if ( ! $bc ) {
			return rest_ensure_response( array( 'success' => false, 'error' => 'not_found' ) );
		}
		if ( ! in_array( $bc['status'], array( 'draft', 'paused' ), true ) ) {
			return rest_ensure_response( array( 'success' => false, 'error' => 'invalid_status_for_start' ) );
		}
		BizCity_Broadcast_Manager::update( $id, array(
			'status'     => 'sending',
			'started_at' => $bc['started_at'] ? $bc['started_at'] : current_time( 'mysql' ),
		) );
		return rest_ensure_response( array( 'success' => true, 'status' => 'sending' ) );
	}

	public static function handle_action_pause( $request ) {
		$id = (int) $request['id'];
		$bc = BizCity_Broadcast_Manager::get_one( $id );
		if ( ! $bc ) {
			return rest_ensure_response( array( 'success' => false, 'error' => 'not_found' ) );
		}
		if ( 'sending' !== $bc['status'] ) {
			return rest_ensure_response( array( 'success' => false, 'error' => 'not_sending' ) );
		}
		BizCity_Broadcast_Manager::update( $id, array( 'status' => 'paused' ) );
		return rest_ensure_response( array( 'success' => true, 'status' => 'paused' ) );
	}

	public static function handle_action_cancel( $request ) {
		$id = (int) $request['id'];
		$bc = BizCity_Broadcast_Manager::get_one( $id );
		if ( ! $bc ) {
			return rest_ensure_response( array( 'success' => false, 'error' => 'not_found' ) );
		}
		if ( in_array( $bc['status'], array( 'done', 'cancelled' ), true ) ) {
			return rest_ensure_response( array( 'success' => false, 'error' => 'already_done' ) );
		}
		BizCity_Broadcast_Manager::update( $id, array( 'status' => 'cancelled' ) );
		return rest_ensure_response( array( 'success' => true, 'status' => 'cancelled' ) );
	}

	public static function get_progress( $request ) {
		$id       = (int) $request['id'];
		$progress = BizCity_Broadcast_Manager::get_progress( $id );
		$bc       = BizCity_Broadcast_Manager::get_one( $id );
		return rest_ensure_response( array(
			'success'  => true,
			'progress' => $progress,
			'status'   => $bc ? $bc['status'] : 'unknown',
		) );
	}

	// ── File parsing ─────────────────────────────────────────────────────────

	/**
	 * POST /broadcasts/parse-file — parse uploaded CSV or XLSX.
	 * Returns preview of recipients (max 5000 rows).
	 *
	 * Multipart form field: file (CSV or XLSX)
	 */
	public static function parse_file( $request ) {
		// [2026-06-27 Johnny Chu] PHASE-CG-BROADCAST — parse CSV/XLSX
		$files = $request->get_file_params();
		if ( empty( $files['file'] ) ) {
			return rest_ensure_response( array( 'success' => false, 'error' => 'no_file_uploaded' ) );
		}

		$file = $files['file'];
		if ( ! empty( $file['error'] ) ) {
			return rest_ensure_response( array( 'success' => false, 'error' => 'upload_error_' . $file['error'] ) );
		}

		$tmp_path  = (string) ( $file['tmp_name'] ?? '' );
		$file_name = strtolower( (string) ( $file['name'] ?? '' ) );

		if ( ! $tmp_path || ! file_exists( $tmp_path ) ) {
			return rest_ensure_response( array( 'success' => false, 'error' => 'tmp_file_missing' ) );
		}

		// Determine format
		$ext = pathinfo( $file_name, PATHINFO_EXTENSION );
		if ( in_array( $ext, array( 'xlsx', 'xls' ), true ) ) {
			$rows = self::parse_xlsx( $tmp_path );
		} else {
			$rows = self::parse_csv( $tmp_path );
		}

		if ( ! is_array( $rows ) ) {
			return rest_ensure_response( array( 'success' => false, 'error' => 'parse_failed' ) );
		}

		// Cap at 5000
		$rows = array_slice( $rows, 0, 5000 );

		return rest_ensure_response( array(
			'success'    => true,
			'count'      => count( $rows ),
			'recipients' => $rows,
		) );
	}

	/**
	 * Parse CSV file.
	 *
	 * @param  string $path
	 * @return array|null
	 */
	private static function parse_csv( $path ) {
		$handle = fopen( $path, 'r' );
		if ( ! $handle ) {
			return null;
		}

		// Detect BOM
		$bom = fread( $handle, 3 );
		if ( $bom !== "\xEF\xBB\xBF" ) {
			rewind( $handle );
		}

		$headers = null;
		$rows    = array();

		while ( ( $line = fgetcsv( $handle, 4096, ',' ) ) !== false ) {
			if ( null === $headers ) {
				$headers = array_map( 'strtolower', array_map( 'trim', $line ) );
				continue;
			}
			$row = array_combine( $headers, array_pad( $line, count( $headers ), '' ) );
			$rows[] = self::normalize_row( $row );
		}

		fclose( $handle );
		return $rows;
	}

	/**
	 * Parse XLSX file using ZipArchive + SimpleXML.
	 * Only reads Sheet1.
	 *
	 * @param  string $path
	 * @return array|null
	 */
	private static function parse_xlsx( $path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return null;
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return null;
		}

		// Read shared strings
		$shared_strings = array();
		$ss_xml = $zip->getFromName( 'xl/sharedStrings.xml' );
		if ( $ss_xml ) {
			libxml_use_internal_errors( true );
			$ss = simplexml_load_string( $ss_xml );
			if ( $ss ) {
				foreach ( $ss->si as $si ) {
					if ( isset( $si->t ) ) {
						$shared_strings[] = (string) $si->t;
					} else {
						$val = '';
						foreach ( $si->r as $r ) {
							$val .= (string) $r->t;
						}
						$shared_strings[] = $val;
					}
				}
			}
		}

		// Read Sheet1
		$sheet_xml = $zip->getFromName( 'xl/worksheets/sheet1.xml' );
		$zip->close();

		if ( ! $sheet_xml ) {
			return null;
		}

		libxml_use_internal_errors( true );
		$sheet = simplexml_load_string( $sheet_xml );
		if ( ! $sheet ) {
			return null;
		}

		$rows    = array();
		$headers = null;

		foreach ( $sheet->sheetData->row as $row_el ) {
			$cells = array();
			foreach ( $row_el->c as $c ) {
				$col   = preg_replace( '/[0-9]/', '', (string) $c['r'] );
				$col_i = self::col_letter_to_index( $col );
				$type  = (string) ( $c['t'] ?? '' );
				$val   = (string) ( isset( $c->v ) ? $c->v : '' );

				if ( 's' === $type ) {
					$val = isset( $shared_strings[ (int) $val ] ) ? $shared_strings[ (int) $val ] : '';
				}

				// Pad cells array
				while ( count( $cells ) < $col_i ) {
					$cells[] = '';
				}
				$cells[] = $val;
			}

			if ( null === $headers ) {
				$headers = array_map( 'strtolower', array_map( 'trim', $cells ) );
				continue;
			}

			if ( ! $headers ) {
				continue;
			}
			$assoc = array();
			foreach ( $headers as $i => $h ) {
				$assoc[ $h ] = isset( $cells[ $i ] ) ? (string) $cells[ $i ] : '';
			}
			$rows[] = self::normalize_row( $assoc );
		}

		return $rows;
	}

	/**
	 * Convert Excel column letter (A, B, AA, ...) to 0-based index.
	 *
	 * @param  string $col  e.g. 'A', 'B', 'AA'
	 * @return int
	 */
	private static function col_letter_to_index( $col ) {
		$col   = strtoupper( $col );
		$index = 0;
		$len   = strlen( $col );
		for ( $i = 0; $i < $len; $i++ ) {
			$index = $index * 26 + ( ord( $col[ $i ] ) - ord( 'A' ) + 1 );
		}
		return $index - 1;
	}

	/**
	 * Normalize a parsed row: detect name/phone/email from flexible column names.
	 *
	 * @param  array $row  Associative with lowercase keys
	 * @return array { name, phone, email, custom_data }
	 */
	private static function normalize_row( array $row ) {
		$name  = '';
		$phone = '';
		$email = '';

		// Detect name
		foreach ( array( 'name', 'ten', 'họ tên', 'ho ten', 'full_name', 'fullname', 'customer_name' ) as $k ) {
			if ( isset( $row[ $k ] ) && $row[ $k ] !== '' ) {
				$name = $row[ $k ];
				break;
			}
		}

		// Detect phone
		foreach ( array( 'phone', 'sdt', 'so_dien_thoai', 'so dien thoai', 'tel', 'mobile', 'điện thoại', 'dien thoai', 'phone_number' ) as $k ) {
			if ( isset( $row[ $k ] ) && $row[ $k ] !== '' ) {
				$phone = $row[ $k ];
				break;
			}
		}

		// Detect email
		foreach ( array( 'email', 'mail', 'e-mail', 'email_address' ) as $k ) {
			if ( isset( $row[ $k ] ) && $row[ $k ] !== '' ) {
				$email = $row[ $k ];
				break;
			}
		}

		// Anything else goes to custom_data
		$known = array( 'name', 'ten', 'ho ten', 'họ tên', 'full_name', 'fullname', 'customer_name',
			'phone', 'sdt', 'so_dien_thoai', 'so dien thoai', 'tel', 'mobile', 'điện thoại', 'dien thoai', 'phone_number',
			'email', 'mail', 'e-mail', 'email_address' );
		$custom = array();
		foreach ( $row as $k => $v ) {
			if ( ! in_array( $k, $known, true ) && $v !== '' ) {
				$custom[ $k ] = $v;
			}
		}

		return array(
			'name'        => sanitize_text_field( $name ),
			'phone'       => sanitize_text_field( $phone ),
			'email'       => sanitize_email( $email ),
			'custom_data' => $custom,
		);
	}

	// ── Contacts ─────────────────────────────────────────────────────────────

	/**
	 * GET /broadcasts/contacts — lấy danh sách contacts từ CRM hoặc WP users.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function get_contacts( $request ) {
		// [2026-06-27 Johnny Chu] PHASE-CG-BROADCAST — get contacts for recipient selector
		$q     = $request->get_param( 'q' );
		$limit = min( 500, (int) $request->get_param( 'limit' ) );

		global $wpdb;
		$contacts = array();

		// Try CRM contacts first
		$crm_table = $wpdb->prefix . 'bizcity_crm_contacts';
		$crm_exists = (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
				$crm_table
			)
		);

		if ( $crm_exists ) {
			$where_parts = array( 'deleted_at IS NULL' );
			$where_vals  = array();
			if ( $q ) {
				$where_parts[] = '(name LIKE %s OR email LIKE %s OR phone LIKE %s)';
				$like = '%' . $wpdb->esc_like( $q ) . '%';
				array_push( $where_vals, $like, $like, $like );
			}
			$where_sql = implode( ' AND ', $where_parts );
			if ( $where_vals ) {
				$rows = $wpdb->get_results(
					$wpdb->prepare( "SELECT id, name, email, phone FROM `{$crm_table}` WHERE {$where_sql} ORDER BY name ASC LIMIT %d", array_merge( $where_vals, array( $limit ) ) ),
					ARRAY_A
				);
			} else {
				$rows = $wpdb->get_results(
					$wpdb->prepare( "SELECT id, name, email, phone FROM `{$crm_table}` WHERE {$where_sql} ORDER BY name ASC LIMIT %d", $limit ),
					ARRAY_A
				);
			}
			foreach ( (array) $rows as $r ) {
				$contacts[] = array(
					'id'     => (int) $r['id'],
					'name'   => (string) $r['name'],
					'email'  => (string) $r['email'],
					'phone'  => (string) $r['phone'],
					'source' => 'crm',
				);
			}
		}

		// Fallback: WP users if CRM empty
		if ( empty( $contacts ) ) {
			$user_args = array(
				'number' => $limit,
				'fields' => array( 'ID', 'display_name', 'user_email' ),
			);
			if ( $q ) {
				$user_args['search']         = '*' . $q . '*';
				$user_args['search_columns'] = array( 'display_name', 'user_email' );
			}
			$users = get_users( $user_args );
			foreach ( $users as $u ) {
				$phone = get_user_meta( $u->ID, 'phone', true );
				$contacts[] = array(
					'id'     => (int) $u->ID,
					'name'   => $u->display_name,
					'email'  => $u->user_email,
					'phone'  => (string) $phone,
					'source' => 'wp_user',
				);
			}
		}

		return rest_ensure_response( array(
			'success'  => true,
			'count'    => count( $contacts ),
			'contacts' => $contacts,
		) );
	}
}
