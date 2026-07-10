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
	 *   POST   /broadcasts/parse-file    — parse csv/xls/xlsx/google_sheet_url → recipient preview
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

		// [2026-07-10 Johnny Chu] PHASE-0.47 — template download supports csv/xlsx/gsheet modes.
		// GET /broadcasts/template — download template assets (public read).
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

		// [2026-07-10 Johnny Chu] PHASE-0.47 — parse source_kind=google_sheet_url in addition to file upload.
		// POST /broadcasts/parse-file — parse CSV/XLS/XLSX or Google Sheet URL
		register_rest_route( self::NS, '/broadcasts/parse-file', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'parse_file' ),
			'permission_callback' => array( __CLASS__, 'auth' ),
		) );

		// [2026-07-10 Johnny Chu] PHASE-0.47 — recipient console APIs (search/pagination/retry/dispatch).
		register_rest_route( self::NS, '/broadcasts/(?P<id>\\d+)/recipients', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get_recipients' ),
			'permission_callback' => array( __CLASS__, 'auth' ),
			'args'                => array(
				'q'        => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ),
				'status'   => array( 'required' => false, 'sanitize_callback' => 'sanitize_key',        'default' => '' ),
				'page'     => array( 'required' => false, 'sanitize_callback' => 'absint',               'default' => 1 ),
				'per_page' => array( 'required' => false, 'sanitize_callback' => 'absint',               'default' => 50 ),
				'activity' => array( 'required' => false, 'sanitize_callback' => 'absint',               'default' => 0 ),
			),
		) );
		register_rest_route( self::NS, '/broadcasts/(?P<id>\\d+)/recipients/dispatch', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'dispatch_recipients' ),
			'permission_callback' => array( __CLASS__, 'auth' ),
		) );
		register_rest_route( self::NS, '/broadcasts/(?P<id>\\d+)/recipients/retry', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'retry_recipients' ),
			'permission_callback' => array( __CLASS__, 'auth' ),
		) );
		register_rest_route( self::NS, '/broadcasts/(?P<id>\\d+)/recipients/(?P<rid>\\d+)/retry', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'retry_one_recipient' ),
			'permission_callback' => array( __CLASS__, 'auth' ),
		) );
		register_rest_route( self::NS, '/broadcasts/(?P<id>\\d+)/console', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get_console' ),
			'permission_callback' => array( __CLASS__, 'auth' ),
			'args'                => array(
				'limit'  => array( 'required' => false, 'sanitize_callback' => 'absint', 'default' => 50 ),
				'offset' => array( 'required' => false, 'sanitize_callback' => 'absint', 'default' => 0 ),
				'level'  => array( 'required' => false, 'sanitize_callback' => 'sanitize_key', 'default' => '' ),
			),
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
	 * GET /broadcasts/template — stream sample file / guide.
	 *
	 * Query params:
	 *   format=csv|xlsx|gsheet
	 *
	 * [2026-06-27 Johnny Chu] PHASE-CG-BROADCAST — sample file download.
	 */
	public static function download_template( $request = null ) {
		// [2026-07-10 Johnny Chu] PHASE-0.47 — support gsheet guide download.
		$format = 'csv';
		if ( $request instanceof WP_REST_Request ) {
			$format = sanitize_key( (string) $request->get_param( 'format' ) );
		}
		if ( $format === '' ) {
			$format = 'csv';
		}

		if ( $format === 'gsheet' ) {
			$md = "# Broadcast Recipients Template (Google Sheet)\n\n"
				. "## Required columns\n"
				. "- name\n"
				. "- phone\n"
				. "- email\n"
				. "- external_id (optional)\n"
				. "- tags (optional)\n\n"
				. "## Sample rows\n"
				. "| name | phone | email | external_id | tags |\n"
				. "|---|---|---|---|---|\n"
				. "| Nguyen Van A | 0901234567 | a@example.com | KH001 | vip,new |\n"
				. "| Tran Thi B | 0912345678 | b@example.com | KH002 | retarget |\n\n"
				. "## Publish Google Sheet as CSV\n"
				. "1. Open your Google Sheet\n"
				. "2. Share as Anyone with the link (Viewer)\n"
				. "3. Use URL pattern:\n"
				. "   https://docs.google.com/spreadsheets/d/<SHEET_ID>/export?format=csv&gid=<GID>\n";

			header( 'Content-Type: text/markdown; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="broadcast-template-gsheet.md"' );
			echo $md; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			exit;
		}

		// [2026-07-10 Johnny Chu] PHASE-0.47 — add xlsx template download.
		if ( $format === 'xlsx' ) {
			$tmp = '';
			if ( function_exists( 'wp_tempnam' ) ) {
				$tmp = (string) wp_tempnam( 'broadcast-template.xlsx' );
			}
			if ( $tmp === '' ) {
				$tmp = (string) tempnam( sys_get_temp_dir(), 'bzcast_tpl_' );
			}

			if ( $tmp !== '' && self::build_template_xlsx( $tmp ) ) {
				header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
				header( 'Content-Disposition: attachment; filename="broadcast-template.xlsx"' );
				header( 'Content-Length: ' . filesize( $tmp ) );
				readfile( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_readfile
				@unlink( $tmp );
				exit;
			}

			if ( $tmp !== '' && file_exists( $tmp ) ) {
				@unlink( $tmp );
			}
		}

		$path = plugin_dir_path( __FILE__ ) . '../../../assets/broadcast-template.csv';
		$path = realpath( $path );

		if ( ! $path || ! file_exists( $path ) ) {
			// Fallback: build inline
			// [2026-07-10 Johnny Chu] PHASE-0.47 — semicolon template for Vietnamese Excel locale.
			$csv = "\xEF\xBB\xBF"
				. "name;phone;email;external_id;tags\n"
				. "Nguyen Van An;0901234567;an@example.com;KH001;vip,new\n"
				. "Tran Thi Binh;0912345678;binh@example.com;KH002;retarget\n"
				. "Le Van Cuong;0923456789;cuong@example.com;KH003;followup\n";
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

	/**
	 * GET /broadcasts/{id}/recipients — recipient list for checklist console.
	 */
	public static function get_recipients( $request ) {
		// [2026-07-10 Johnny Chu] PHASE-0.47 — recipient list with q/status/page/per_page/activity.
		$id = (int) $request['id'];
		$bc = BizCity_Broadcast_Manager::get_one( $id );
		if ( ! $bc ) {
			return rest_ensure_response( array( 'success' => false, 'error' => 'not_found' ) );
		}

		$list = BizCity_Broadcast_Manager::get_recipients( $id, array(
			'q'        => $request->get_param( 'q' ),
			'status'   => $request->get_param( 'status' ),
			'page'     => $request->get_param( 'page' ),
			'per_page' => $request->get_param( 'per_page' ),
			'activity' => $request->get_param( 'activity' ),
		) );

		// [2026-07-10 Johnny Chu] PHASE-0.47 — append attempt_count + last_attempt_at from JSONL logs.
		$recipient_ids = array();
		foreach ( (array) $list['items'] as $row ) {
			$rid = (int) ( $row['id'] ?? 0 );
			if ( $rid > 0 ) {
				$recipient_ids[] = $rid;
			}
		}
		$attempt_stats = self::get_recipient_attempt_stats( $id, $recipient_ids );

		$items = array();
		foreach ( (array) $list['items'] as $row ) {
			$rid = (int) ( $row['id'] ?? 0 );
			$st  = isset( $attempt_stats[ $rid ] ) ? $attempt_stats[ $rid ] : array();
			$row['attempt_count']   = (int) ( $st['attempt_count'] ?? 0 );
			$row['last_attempt_at'] = (string) ( $st['last_attempt_at'] ?? '' );
			if ( $row['last_attempt_at'] === '' ) {
				$row['last_attempt_at'] = (string) ( $row['sent_at'] ?? '' );
			}
			$items[] = $row;
		}

		return rest_ensure_response( array(
			'success'          => true,
			'broadcast_id'     => $id,
			'broadcast_status' => (string) $bc['status'],
			'items'            => $items,
			'total'            => (int) $list['total'],
			'page'             => (int) $list['page'],
			'per_page'         => (int) $list['per_page'],
			'counts'           => is_array( $list['counts'] ) ? $list['counts'] : array(),
		) );
	}

	/**
	 * POST /broadcasts/{id}/recipients/dispatch — queue selected recipients and resume campaign.
	 */
	public static function dispatch_recipients( $request ) {
		// [2026-07-10 Johnny Chu] PHASE-0.47 — dispatch selected rows from checklist.
		$id   = (int) $request['id'];
		$bc   = BizCity_Broadcast_Manager::get_one( $id );
		if ( ! $bc ) {
			return rest_ensure_response( array( 'success' => false, 'error' => 'not_found' ) );
		}

		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = $request->get_params();
		}

		$ids       = isset( $body['recipient_ids'] ) && is_array( $body['recipient_ids'] ) ? array_values( array_filter( array_map( 'absint', $body['recipient_ids'] ) ) ) : array();
		$phones    = isset( $body['phones'] ) && is_array( $body['phones'] ) ? array_values( array_filter( array_map( 'sanitize_text_field', $body['phones'] ) ) ) : array();
		$all_failed= ! empty( $body['all_failed'] );

		$updated = 0;
		if ( ! empty( $ids ) ) {
			$updated = BizCity_Broadcast_Manager::queue_recipients_by_ids( $id, $ids, false );
		} elseif ( ! empty( $phones ) ) {
			$updated = BizCity_Broadcast_Manager::queue_recipients_by_phones( $id, $phones, false );
		} elseif ( $all_failed ) {
			$updated = BizCity_Broadcast_Manager::queue_all_failed( $id );
		}

		if ( $updated <= 0 ) {
			return rest_ensure_response( array( 'success' => false, 'error' => 'no_rows_updated' ) );
		}

		$bc = self::force_resume_broadcast( $id );

		return rest_ensure_response( array(
			'success'  => true,
			'updated'  => $updated,
			'status'   => (string) ( $bc['status'] ?? 'sending' ),
			'progress' => BizCity_Broadcast_Manager::get_progress( $id ),
		) );
	}

	/**
	 * POST /broadcasts/{id}/recipients/retry — retry selected failed recipients.
	 */
	public static function retry_recipients( $request ) {
		// [2026-07-10 Johnny Chu] PHASE-0.47 — retry selected/all-failed recipients.
		$id = (int) $request['id'];
		$bc = BizCity_Broadcast_Manager::get_one( $id );
		if ( ! $bc ) {
			return rest_ensure_response( array( 'success' => false, 'error' => 'not_found' ) );
		}

		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = $request->get_params();
		}

		$ids        = isset( $body['recipient_ids'] ) && is_array( $body['recipient_ids'] ) ? array_values( array_filter( array_map( 'absint', $body['recipient_ids'] ) ) ) : array();
		$phones     = isset( $body['phones'] ) && is_array( $body['phones'] ) ? array_values( array_filter( array_map( 'sanitize_text_field', $body['phones'] ) ) ) : array();
		$all_failed = ! empty( $body['all_failed'] ) || ( empty( $ids ) && empty( $phones ) );

		$updated = 0;
		if ( ! empty( $ids ) ) {
			$updated = BizCity_Broadcast_Manager::queue_recipients_by_ids( $id, $ids, true );
		} elseif ( ! empty( $phones ) ) {
			$updated = BizCity_Broadcast_Manager::queue_recipients_by_phones( $id, $phones, true );
		} elseif ( $all_failed ) {
			$updated = BizCity_Broadcast_Manager::queue_all_failed( $id );
		}

		if ( $updated <= 0 ) {
			return rest_ensure_response( array( 'success' => false, 'error' => 'no_rows_updated' ) );
		}

		$resume = ! isset( $body['resume'] ) || ! empty( $body['resume'] );
		if ( $resume ) {
			$bc = self::force_resume_broadcast( $id );
		}

		return rest_ensure_response( array(
			'success'  => true,
			'updated'  => $updated,
			'status'   => (string) ( $bc['status'] ?? 'sending' ),
			'progress' => BizCity_Broadcast_Manager::get_progress( $id ),
		) );
	}

	/**
	 * POST /broadcasts/{id}/recipients/{rid}/retry — one-click retry by recipient id.
	 */
	public static function retry_one_recipient( $request ) {
		// [2026-07-10 Johnny Chu] PHASE-0.47 — quick retry single row.
		$id  = (int) $request['id'];
		$rid = (int) $request['rid'];
		$bc  = BizCity_Broadcast_Manager::get_one( $id );
		if ( ! $bc ) {
			return rest_ensure_response( array( 'success' => false, 'error' => 'not_found' ) );
		}

		$row = BizCity_Broadcast_Manager::get_recipient( $id, $rid );
		if ( ! $row ) {
			return rest_ensure_response( array( 'success' => false, 'error' => 'recipient_not_found' ) );
		}

		$updated = BizCity_Broadcast_Manager::queue_recipients_by_ids( $id, array( $rid ), false );
		if ( $updated <= 0 ) {
			return rest_ensure_response( array( 'success' => false, 'error' => 'no_rows_updated' ) );
		}

		$bc = self::force_resume_broadcast( $id );

		return rest_ensure_response( array(
			'success'      => true,
			'updated'      => $updated,
			'recipient_id' => $rid,
			'status'       => (string) ( $bc['status'] ?? 'sending' ),
			'progress'     => BizCity_Broadcast_Manager::get_progress( $id ),
		) );
	}

	/**
	 * GET /broadcasts/{id}/console — checklist snapshot for UI.
	 */
	public static function get_console( $request ) {
		// [2026-07-10 Johnny Chu] PHASE-0.47 — checklist + activity logs for broadcast console.
		$id     = (int) $request['id'];
		$limit  = max( 1, min( 200, (int) $request->get_param( 'limit' ) ) );
		$offset = max( 0, (int) $request->get_param( 'offset' ) );
		$level  = strtoupper( sanitize_key( (string) $request->get_param( 'level' ) ) );
		if ( ! in_array( $level, array( '', 'INFO', 'WARN', 'ERROR' ), true ) ) {
			$level = '';
		}
		$bc     = BizCity_Broadcast_Manager::get_one( $id );
		if ( ! $bc ) {
			return rest_ensure_response( array( 'success' => false, 'error' => 'not_found' ) );
		}

		$progress = BizCity_Broadcast_Manager::get_progress( $id );
		$page     = (int) floor( $offset / $limit ) + 1;
		$activity = BizCity_Broadcast_Manager::get_recipients( $id, array(
			'activity' => 1,
			'page'     => $page,
			'per_page' => $limit,
		) );

		$logs_all = array();
		if ( class_exists( 'BizCity_Channel_File_Logger' ) ) {
			$dates = BizCity_Channel_File_Logger::list_dates( 'broadcast', 7 );
			foreach ( (array) $dates as $date ) {
				$rows = BizCity_Channel_File_Logger::read( 'broadcast', $date, 800, $level );
				foreach ( (array) $rows as $row ) {
					$ctx_bc = (int) ( $row['ctx']['broadcast_id'] ?? 0 );
					if ( $ctx_bc !== $id ) {
						continue;
					}
					$logs_all[] = array(
						'ts'      => (string) ( $row['ts'] ?? '' ),
						'level'   => (string) ( $row['level'] ?? '' ),
						'event'   => (string) ( $row['event'] ?? '' ),
						'message' => (string) ( $row['message'] ?? '' ),
						'ctx'     => is_array( $row['ctx'] ?? null ) ? $row['ctx'] : array(),
					);
					if ( count( $logs_all ) >= 2000 ) {
						break 2;
					}
				}
			}
		}

		$logs = array_slice( $logs_all, $offset, $limit );

		$checklist = array(
			array( 'key' => 'has_recipients', 'pass' => ( $progress['total'] > 0 ), 'label' => 'Danh sach nguoi nhan' ),
			array( 'key' => 'has_progress', 'pass' => ( $progress['sent'] + $progress['failed'] ) > 0, 'label' => 'Da co tien trinh xu ly' ),
			array( 'key' => 'has_activity_logs', 'pass' => ! empty( $logs_all ), 'label' => 'Co activity log trong JSONL' ),
			array( 'key' => 'has_failed', 'pass' => (int) $progress['failed'] > 0, 'label' => 'Co ban ghi loi de retry' ),
		);

		return rest_ensure_response( array(
			'success'          => true,
			'broadcast_id'     => $id,
			'broadcast_status' => (string) ( $bc['status'] ?? 'draft' ),
			'progress'         => $progress,
			'counts'           => is_array( $activity['counts'] ?? null ) ? $activity['counts'] : array(),
			'checklist'        => $checklist,
			'activity'         => is_array( $activity['items'] ?? null ) ? $activity['items'] : array(),
			'activity_total'   => (int) ( $activity['total'] ?? 0 ),
			'logs'             => $logs,
			'logs_total'       => count( $logs_all ),
			'offset'           => $offset,
			'limit'            => $limit,
			'level'            => $level,
		) );
	}

	/**
	 * Build recipient attempt stats from channel JSONL logs.
	 *
	 * @param  int   $broadcast_id
	 * @param  int[] $recipient_ids
	 * @return array<int,array{attempt_count:int,last_attempt_at:string}>
	 */
	private static function get_recipient_attempt_stats( $broadcast_id, array $recipient_ids ) {
		// [2026-07-10 Johnny Chu] PHASE-0.47 — attempt_count/last_attempt_at computed from file logs.
		$broadcast_id = (int) $broadcast_id;
		if ( $broadcast_id <= 0 || empty( $recipient_ids ) || ! class_exists( 'BizCity_Channel_File_Logger' ) ) {
			return array();
		}

		$rid_map = array();
		foreach ( $recipient_ids as $rid ) {
			$rid = (int) $rid;
			if ( $rid > 0 ) {
				$rid_map[ $rid ] = true;
			}
		}
		if ( empty( $rid_map ) ) {
			return array();
		}

		$stats = array();
		foreach ( array_keys( $rid_map ) as $rid ) {
			$stats[ $rid ] = array(
				'attempt_count'   => 0,
				'last_attempt_at' => '',
			);
		}

		$dates = BizCity_Channel_File_Logger::list_dates( 'broadcast', 7 );
		foreach ( (array) $dates as $date ) {
			$rows = BizCity_Channel_File_Logger::read( 'broadcast', $date, 2500 );
			foreach ( (array) $rows as $row ) {
				$ctx_bc = (int) ( $row['ctx']['broadcast_id'] ?? 0 );
				if ( $ctx_bc !== $broadcast_id ) {
					continue;
				}

				$rid = (int) ( $row['ctx']['recipient_id'] ?? 0 );
				if ( $rid <= 0 || ! isset( $stats[ $rid ] ) ) {
					continue;
				}

				$event = (string) ( $row['event'] ?? '' );
				$ts    = (string) ( $row['ts'] ?? '' );

				if ( $event === 'recipient_attempt' ) {
					$stats[ $rid ]['attempt_count']++;
				} elseif ( in_array( $event, array( 'recipient_failed', 'recipient_sent' ), true ) && $stats[ $rid ]['attempt_count'] === 0 ) {
					// Backward compatibility: old logs may not have recipient_attempt.
					$stats[ $rid ]['attempt_count'] = 1;
				}

				if ( $ts !== '' && ( $stats[ $rid ]['last_attempt_at'] === '' || strcmp( $ts, $stats[ $rid ]['last_attempt_at'] ) > 0 ) ) {
					$stats[ $rid ]['last_attempt_at'] = $ts;
				}
			}
		}

		return $stats;
	}

	/**
	 * Resume a broadcast after retry/dispatch operations.
	 *
	 * @param  int $broadcast_id
	 * @return array|null
	 */
	private static function force_resume_broadcast( $broadcast_id ) {
		// [2026-07-10 Johnny Chu] PHASE-0.47 — auto-resume campaign after queueing recipients.
		$broadcast_id = (int) $broadcast_id;
		if ( $broadcast_id <= 0 ) {
			return null;
		}

		$bc = BizCity_Broadcast_Manager::get_one( $broadcast_id );
		if ( ! $bc ) {
			return null;
		}

		if ( (string) ( $bc['status'] ?? '' ) !== 'sending' ) {
			BizCity_Broadcast_Manager::update( $broadcast_id, array(
				'status'     => 'sending',
				'done_at'    => null,
				'started_at' => ! empty( $bc['started_at'] ) ? $bc['started_at'] : current_time( 'mysql' ),
			) );
		}

		if ( class_exists( 'BizCity_Broadcast_Dispatcher' ) ) {
			do_action( BizCity_Broadcast_Dispatcher::CRON_HOOK );
		}

		return BizCity_Broadcast_Manager::get_one( $broadcast_id );
	}

	// ── File parsing ─────────────────────────────────────────────────────────

	/**
	 * POST /broadcasts/parse-file — parse source into recipients.
	 * Returns preview rows (max 5000).
	 *
	 * Supported sources:
	 * - multipart file upload: csv, xls, xlsx
	 * - params: source_kind=google_sheet_url + source_url=<google sheet url>
	 */
	public static function parse_file( $request ) {
		// [2026-07-10 Johnny Chu] PHASE-0.47 — parse csv/xls/xlsx/google_sheet_url via one endpoint.
		$source_kind = sanitize_key( (string) $request->get_param( 'source_kind' ) );
		$source_url  = trim( (string) $request->get_param( 'source_url' ) );
		if ( in_array( $source_kind, array( 'google_sheet', 'gsheet', 'sheet' ), true ) ) {
			// [2026-07-10 Johnny Chu] PHASE-0.47 — alias source_kind for Google Sheet import.
			$source_kind = 'google_sheet_url';
		}

		$rows = null;
		if ( $source_kind === '' && $source_url !== '' && strpos( $source_url, 'docs.google.com/spreadsheets/' ) !== false ) {
			$source_kind = 'google_sheet_url';
		}

		if ( $source_kind === 'google_sheet_url' ) {
			$rows = self::parse_google_sheet_url( $source_url );
			if ( is_wp_error( $rows ) ) {
				return rest_ensure_response( array( 'success' => false, 'error' => $rows->get_error_code() ) );
			}
		} else {
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

			$parse_kind = $source_kind;
			if ( $parse_kind === '' ) {
				$parse_kind = strtolower( (string) pathinfo( $file_name, PATHINFO_EXTENSION ) );
			}
			if ( ! in_array( $parse_kind, array( 'csv', 'xls', 'xlsx' ), true ) ) {
				return rest_ensure_response( array( 'success' => false, 'error' => 'unsupported_source_kind' ) );
			}

			switch ( $parse_kind ) {
				case 'csv':
					$rows = self::parse_csv( $tmp_path );
					break;
				case 'xlsx':
					$rows = self::parse_xlsx( $tmp_path );
					break;
				case 'xls':
					if ( ! class_exists( '\\PhpOffice\\PhpSpreadsheet\\IOFactory' ) ) {
						return rest_ensure_response( array( 'success' => false, 'error' => 'xls_parser_unavailable' ) );
					}
					$rows = self::parse_xls( $tmp_path );
					break;
			}
		}

		if ( ! is_array( $rows ) ) {
			return rest_ensure_response( array( 'success' => false, 'error' => 'parse_failed' ) );
		}

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
		// [2026-07-10 Johnny Chu] PHASE-0.47 — robust CSV parser for Vietnamese Excel exports.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$content = file_get_contents( $path );
		if ( ! is_string( $content ) || $content === '' ) {
			return null;
		}
		return self::parse_csv_content( $content );
	}

	/**
	 * Parse CSV content string (encoding + delimiter autodetect).
	 *
	 * @param  string $content
	 * @return array|null
	 */
	private static function parse_csv_content( $content ) {
		if ( ! is_string( $content ) || $content === '' ) {
			return null;
		}

		if ( substr( $content, 0, 3 ) === "\xEF\xBB\xBF" ) {
			$content = substr( $content, 3 );
		}

		if ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $content, 'UTF-8' ) ) {
			$best       = '';
			$best_score = -1;
			foreach ( array( 'CP1258', 'CP1252', 'ISO-8859-1' ) as $enc ) {
				$try = @iconv( $enc, 'UTF-8//IGNORE', $content );
				if ( ! is_string( $try ) || $try === '' ) {
					if ( function_exists( 'mb_convert_encoding' ) ) {
						$try = @mb_convert_encoding( $content, 'UTF-8', $enc );
					}
				}
				if ( ! is_string( $try ) || $try === '' ) {
					continue;
				}
				if ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $try, 'UTF-8' ) ) {
					continue;
				}
				$score = preg_match_all( '/[\xC0-\xFF]/', $try );
				if ( $score > $best_score ) {
					$best       = $try;
					$best_score = $score;
				}
			}
			if ( $best !== '' ) {
				$content = $best;
			}
		}

		$first_line = strtok( $content, "\n" );
		$sep        = ',';
		if ( is_string( $first_line ) && substr_count( $first_line, ';' ) >= substr_count( $first_line, ',' ) ) {
			$sep = ';';
		}

		$handle = fopen( 'php://temp', 'r+' );
		if ( ! $handle ) {
			return null;
		}
		fwrite( $handle, $content );
		rewind( $handle );

		$headers = null;
		$rows    = array();

		while ( ( $line = fgetcsv( $handle, 0, $sep ) ) !== false ) {
			if ( $headers === null ) {
				$headers = array_map( array( __CLASS__, 'normalize_header_key' ), $line );
				continue;
			}

			if ( ! is_array( $headers ) || empty( $headers ) ) {
				continue;
			}

			$assoc = array();
			foreach ( $headers as $i => $h ) {
				$assoc[ $h ] = isset( $line[ $i ] ) ? (string) $line[ $i ] : '';
			}

			$norm = self::normalize_row( $assoc );
			if ( $norm['phone'] !== '' || $norm['email'] !== '' ) {
				$rows[] = $norm;
			}
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
				$headers = array_map( array( __CLASS__, 'normalize_header_key' ), $cells );
				continue;
			}

			if ( ! $headers ) {
				continue;
			}
			$assoc = array();
			foreach ( $headers as $i => $h ) {
				$assoc[ $h ] = isset( $cells[ $i ] ) ? (string) $cells[ $i ] : '';
			}
			$norm = self::normalize_row( $assoc );
			if ( $norm['phone'] !== '' || $norm['email'] !== '' ) {
				$rows[] = $norm;
			}
		}

		return $rows;
	}

	/**
	 * Parse legacy XLS file via PhpSpreadsheet when available.
	 *
	 * @param  string $path
	 * @return array|null
	 */
	private static function parse_xls( $path ) {
		// [2026-07-10 Johnny Chu] PHASE-0.47 — support parser switch-case for xls files.
		if ( ! class_exists( '\\PhpOffice\\PhpSpreadsheet\\IOFactory' ) ) {
			return null;
		}

		try {
			$reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader( 'Xls' );
			$reader->setReadDataOnly( true );
			$sheet  = $reader->load( $path )->getActiveSheet();
			$matrix = $sheet->toArray( '', true, true, false );
			if ( ! is_array( $matrix ) || empty( $matrix ) ) {
				return array();
			}

			$header_raw = array_shift( $matrix );
			$headers    = array_map( array( __CLASS__, 'normalize_header_key' ), is_array( $header_raw ) ? $header_raw : array() );

			$rows = array();
			foreach ( array_slice( $matrix, 0, 5000 ) as $line ) {
				$assoc = array();
				foreach ( $headers as $i => $h ) {
					$assoc[ $h ] = isset( $line[ $i ] ) ? (string) $line[ $i ] : '';
				}
				$norm = self::normalize_row( $assoc );
				if ( $norm['phone'] !== '' || $norm['email'] !== '' ) {
					$rows[] = $norm;
				}
			}

			return $rows;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Parse Google Sheet URL by exporting it as CSV.
	 *
	 * @param  string $source_url
	 * @return array|WP_Error
	 */
	private static function parse_google_sheet_url( $source_url ) {
		// [2026-07-10 Johnny Chu] PHASE-0.47 — import recipients from Google Sheet link.
		$csv_url = self::normalize_google_sheet_csv_url( $source_url );
		if ( $csv_url === '' ) {
			return new WP_Error( 'invalid_google_sheet_url', 'Invalid Google Sheet URL' );
		}

		$response = wp_remote_get( $csv_url, array(
			// [2026-07-10 Johnny Chu] PHASE-0.47 — align with import rule: 10s timeout.
			'timeout'     => 10,
			'redirection' => 5,
		) );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'google_sheet_fetch_failed', $response->get_error_message() );
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		if ( $status_code < 200 || $status_code >= 300 ) {
			return new WP_Error( 'google_sheet_http_error', 'Google Sheet HTTP ' . $status_code );
		}

		$body = (string) wp_remote_retrieve_body( $response );
		if ( $body === '' ) {
			return new WP_Error( 'google_sheet_empty', 'Google Sheet returned empty CSV' );
		}
		if ( strlen( $body ) > 5 * 1024 * 1024 ) {
			return new WP_Error( 'google_sheet_too_large', 'Google Sheet CSV is too large' );
		}

		$rows = self::parse_csv_content( $body );
		if ( ! is_array( $rows ) ) {
			return new WP_Error( 'google_sheet_parse_failed', 'Failed to parse Google Sheet CSV' );
		}

		return $rows;
	}

	/**
	 * Normalize Google Sheet URL to export CSV endpoint.
	 *
	 * @param  string $source_url
	 * @return string
	 */
	private static function normalize_google_sheet_csv_url( $source_url ) {
		$url = trim( (string) $source_url );
		if ( $url === '' ) {
			return '';
		}

		if ( strpos( $url, 'http://' ) !== 0 && strpos( $url, 'https://' ) !== 0 ) {
			$url = 'https://' . ltrim( $url, '/' );
		}

		$parts = wp_parse_url( $url );
		$host  = strtolower( (string) ( $parts['host'] ?? '' ) );
		$path  = (string) ( $parts['path'] ?? '' );
		// [2026-07-10 Johnny Chu] PHASE-0.47 — strict host match to avoid substring host bypass.
		if ( $host !== 'docs.google.com' || strpos( $path, '/spreadsheets/d/' ) === false ) {
			return '';
		}

		if ( ! preg_match( '#/spreadsheets/d/([a-zA-Z0-9_-]+)#', $path, $m ) ) {
			return '';
		}

		$sheet_id = (string) $m[1];
		$gid      = '0';

		if ( ! empty( $parts['query'] ) ) {
			$qv = array();
			parse_str( (string) $parts['query'], $qv );
			if ( isset( $qv['gid'] ) && preg_match( '/^[0-9]+$/', (string) $qv['gid'] ) ) {
				$gid = (string) $qv['gid'];
			}
		}
		if ( ! empty( $parts['fragment'] ) && preg_match( '/(?:^|&)gid=([0-9]+)/', (string) $parts['fragment'], $fm ) ) {
			$gid = (string) $fm[1];
		}

		return 'https://docs.google.com/spreadsheets/d/' . rawurlencode( $sheet_id ) . '/export?format=csv&gid=' . rawurlencode( $gid );
	}

	/**
	 * Build XLSX template file (minimal OpenXML package).
	 *
	 * @param  string $tmp_file
	 * @return bool
	 */
	private static function build_template_xlsx( $tmp_file ) {
		if ( ! class_exists( 'ZipArchive' ) || ! is_string( $tmp_file ) || $tmp_file === '' ) {
			return false;
		}

		$rows_matrix = array(
			array( 'name', 'phone', 'email', 'external_id', 'tags' ),
			array( 'Nguyen Van An', '0901234567', 'an@example.com', 'KH001', 'vip,new' ),
			array( 'Tran Thi Binh', '0912345678', 'binh@example.com', 'KH002', 'retarget' ),
			array( 'Le Van Cuong', '0923456789', 'cuong@example.com', 'KH003', 'followup' ),
		);

		$string_index = array();
		$shared       = array();
		$sheet_rows   = array();
		$letters      = array( 'A', 'B', 'C', 'D', 'E' );

		foreach ( $rows_matrix as $row_idx => $cells ) {
			$r_num   = $row_idx + 1;
			$cells_x = array();
			foreach ( $cells as $col_idx => $value ) {
				$key = (string) $value;
				if ( ! isset( $string_index[ $key ] ) ) {
					$string_index[ $key ] = count( $shared );
					$shared[] = $key;
				}
				$ref     = $letters[ $col_idx ] . $r_num;
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
			. '<sheets><sheet name="Recipients" sheetId="1" r:id="rId1"/></sheets>'
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
	 * Normalize imported header key to canonical lowercase_underscore format.
	 *
	 * @param  string $header
	 * @return string
	 */
	private static function normalize_header_key( $header ) {
		$k = strtolower( trim( (string) $header ) );
		if ( function_exists( 'remove_accents' ) ) {
			$k = remove_accents( $k );
		}
		$k = str_replace( array( '-', ' ', '.' ), '_', $k );
		return $k;
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
		foreach ( array( 'name', 'ten', 'họ tên', 'ho ten', 'ho_ten', 'full_name', 'fullname', 'customer_name', 'ten_khach_hang' ) as $k ) {
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
		$known = array( 'name', 'ten', 'ho ten', 'ho_ten', 'họ tên', 'full_name', 'fullname', 'customer_name', 'ten_khach_hang',
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
