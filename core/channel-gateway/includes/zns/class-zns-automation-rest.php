<?php
/**
 * ZNS Automation REST Controller — 6 nhóm endpoints cho ZNS Automation Hub.
 *
 * Namespace: bizcity-channel/v1 (R-CH-NS bắt buộc).
 * Auth: manage_options + WP Nonce.
 *
 * Groups:
 *   1. GET/POST /zns-automation/settings  — eSMS credentials (global, reuse CF7 config)
 *   2. GET      /zns-automation/events    — Danh sách tất cả event triggers
 *   3. CRUD     /zns-automation/rules/*   — Event rules management
 *   4. POST     /zns-automation/test      — Test gửi ZNS (sandbox)
 *   5. GET      /zns-automation/logs      — JSONL file logs reader
 *   6. GET      /zns-automation/stats     — Dashboard thống kê
 *   7. GET      /zns-automation/sends     — Paginated send list + export
 *
 * Security: mọi endpoint trả 200 + success:false thay vì 5xx (fail-OPEN).
 * Credentials không bao giờ xuất hiện trong response.
 *
 * [2026-06-27 Johnny Chu] PHASE-CG-ZNS-AUTO — new class.
 *
 * @package BizCity_Channel_Gateway
 * @since   PHASE-CG-ZNS-AUTO (2026-06-27)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_ZNS_Automation_REST' ) ) {
	return;
}

class BizCity_ZNS_Automation_REST {

	const NS = 'bizcity-channel/v1';

	/**
	 * Đăng ký tất cả REST routes.
	 *
	 * @return void
	 */
	public static function register_routes() {
		// [2026-06-27 Johnny Chu] PHASE-CG-ZNS-AUTO — register routes

		// ── 1. Settings ──────────────────────────────────────────────────────
		register_rest_route( self::NS, '/zns-automation/settings', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_settings' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'save_settings' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			),
		) );

		register_rest_route( self::NS, '/zns-automation/oa-accounts', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_oa_accounts' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
		) );

		// ── 2. Events ────────────────────────────────────────────────────────
		register_rest_route( self::NS, '/zns-automation/events', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_events' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
		) );

		// ── 3. Rules CRUD ────────────────────────────────────────────────────
		register_rest_route( self::NS, '/zns-automation/rules', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'list_rules' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'create_rule' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			),
		) );

		register_rest_route( self::NS, '/zns-automation/rules/(?P<id>\d+)', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_rule' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			),
			array(
				'methods'             => 'PUT,PATCH',
				'callback'            => array( __CLASS__, 'update_rule' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'delete_rule' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			),
		) );

		register_rest_route( self::NS, '/zns-automation/rules/(?P<id>\d+)/toggle', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'toggle_rule' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
		) );

		// ── 4. Test ──────────────────────────────────────────────────────────
		register_rest_route( self::NS, '/zns-automation/test', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'test_send' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
		) );

		register_rest_route( self::NS, '/zns-automation/test/dry-run', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'dry_run' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
		) );

		// ── 5. Logs ──────────────────────────────────────────────────────────
		register_rest_route( self::NS, '/zns-automation/logs', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_logs' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
		) );

		register_rest_route( self::NS, '/zns-automation/logs/dates', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_log_dates' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
		) );

		// ── 6. Stats ─────────────────────────────────────────────────────────
		register_rest_route( self::NS, '/zns-automation/stats', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_stats' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
		) );

		// ── 7. Sends ─────────────────────────────────────────────────────────
		register_rest_route( self::NS, '/zns-automation/sends', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'list_sends' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
		) );

		register_rest_route( self::NS, '/zns-automation/sends/export', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'export_sends' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
		) );
	}

	// ── Permission ───────────────────────────────────────────────────────────

	/**
	 * @return bool
	 */
	public static function can_manage() {
		return current_user_can( 'manage_options' );
	}

	// ── 1. Settings ──────────────────────────────────────────────────────────

	/**
	 * GET /zns-automation/settings
	 *
	 * @param  WP_REST_Request $req
	 * @return WP_REST_Response
	 */
	public static function get_settings( WP_REST_Request $req ) {
		// [2026-06-27 Johnny Chu] PHASE-CG-ZNS-AUTO — get settings (mask secret_key)
		try {
			$global = class_exists( 'BizCity_CF7_ZNS_Config', false )
				? BizCity_CF7_ZNS_Config::get_global_settings()
				: array( 'api_key' => '', 'secret_key' => '', 'oa_id' => '' );

			$has_creds = ! empty( $global['api_key'] ) && ! empty( $global['secret_key'] );
			return self::ok( array(
				'api_key'       => $global['api_key'] ?? '',
				'secret_key'    => $has_creds ? str_repeat( '*', 8 ) : '',
				'oa_id'         => $global['oa_id'] ?? '',
				'connected'     => $has_creds,
			) );
		} catch ( \Exception $e ) {
			return self::degraded( $e->getMessage() );
		}
	}

	/**
	 * POST /zns-automation/settings
	 *
	 * @param  WP_REST_Request $req
	 * @return WP_REST_Response
	 */
	public static function save_settings( WP_REST_Request $req ) {
		// [2026-06-27 Johnny Chu] PHASE-CG-ZNS-AUTO — save settings via CF7_ZNS_Config
		try {
			$body = $req->get_json_params();
			$api_key    = sanitize_text_field( (string) ( $body['api_key']    ?? '' ) );
			$secret_key = sanitize_text_field( (string) ( $body['secret_key'] ?? '' ) );
			$oa_id      = sanitize_text_field( (string) ( $body['oa_id']      ?? '' ) );

			if ( class_exists( 'BizCity_CF7_ZNS_Config', false ) ) {
				BizCity_CF7_ZNS_Config::save_global_settings( array(
					'api_key'    => $api_key,
					'secret_key' => $secret_key,
					'oa_id'      => $oa_id,
				) );
			} else {
				// Fallback: lưu trực tiếp vào option (CF7_ZNS_Config sẽ đọc option này)
				$existing  = get_option( 'bizcity_cg_esms_zns_settings', array() );
				$to_save   = array_merge( (array) $existing, array(
					'api_key'    => $api_key,
					'oa_id'      => $oa_id,
				) );
				// Chỉ update secret_key nếu không phải placeholder
				if ( $secret_key && strpos( $secret_key, '*' ) === false ) {
					$to_save['secret_key'] = $secret_key;
				}
				update_option( 'bizcity_cg_esms_zns_settings', $to_save );
			}
			return self::ok( array( 'saved' => true ) );
		} catch ( \Exception $e ) {
			return self::degraded( $e->getMessage() );
		}
	}

	/**
	 * GET /zns-automation/oa-accounts
	 *
	 * @param  WP_REST_Request $req
	 * @return WP_REST_Response
	 */
	public static function get_oa_accounts( WP_REST_Request $req ) {
		try {
			$accounts = array();
			if ( class_exists( 'BizCity_CF7_ZNS_Config', false ) ) {
				$accounts = BizCity_CF7_ZNS_Config::get_oa_accounts();
			}
			// Fallback: lấy từ channel-gateway zalo OA list nếu có
			if ( empty( $accounts ) ) {
				$raw = get_option( 'bizcity_zalo_oa_accounts', array() );
				foreach ( (array) $raw as $acc ) {
					$accounts[] = array(
						'oa_id'   => $acc['oa_id'] ?? '',
						'oa_name' => $acc['name'] ?? $acc['oa_id'] ?? '',
					);
				}
			}
			return self::ok( $accounts );
		} catch ( \Exception $e ) {
			return self::degraded( $e->getMessage() );
		}
	}

	// ── 2. Events ────────────────────────────────────────────────────────────

	/**
	 * GET /zns-automation/events
	 *
	 * @param  WP_REST_Request $req
	 * @return WP_REST_Response
	 */
	public static function get_events( WP_REST_Request $req ) {
		// [2026-06-27 Johnny Chu] PHASE-CG-ZNS-AUTO — all events from registry
		try {
			$events = BizCity_ZNS_Event_Registry::get_all_events();
			$cf7    = BizCity_ZNS_Event_Registry::get_cf7_events();
			$all    = array_merge( $events, $cf7 );
			$out    = array();
			foreach ( $all as $e ) {
				$out[] = array(
					'key'          => $e['key'],
					'label'        => $e['label'],
					'group'        => $e['group'],
					'placeholders' => $e['placeholders'] ?? array(),
				);
			}
			return self::ok( $out );
		} catch ( \Exception $e ) {
			return self::degraded( $e->getMessage() );
		}
	}

	// ── 3. Rules CRUD ────────────────────────────────────────────────────────

	/**
	 * GET /zns-automation/rules
	 *
	 * @param  WP_REST_Request $req
	 * @return WP_REST_Response
	 */
	public static function list_rules( WP_REST_Request $req ) {
		try {
			if ( ! class_exists( 'BizCity_ZNS_Rules_Repo', false ) ) {
				return self::ok( array() );
			}
			$rules = BizCity_ZNS_Rules_Repo::get_all( false );
			// Optional filter
			$event = $req->get_param( 'event_key' );
			if ( $event ) {
				$event = sanitize_key( $event );
				$rules = array_values( array_filter( $rules, function( $r ) use ( $event ) {
					return $r['event_key'] === $event;
				} ) );
			}
			return self::ok( $rules );
		} catch ( \Exception $e ) {
			return self::degraded( $e->getMessage() );
		}
	}

	/**
	 * POST /zns-automation/rules
	 *
	 * @param  WP_REST_Request $req
	 * @return WP_REST_Response
	 */
	public static function create_rule( WP_REST_Request $req ) {
		try {
			$data = self::parse_rule_body( $req );
			$id   = BizCity_ZNS_Rules_Repo::insert( $data );
			if ( ! $id ) {
				return self::fail( 'insert_failed', 'Không thể tạo quy tắc.' );
			}
			return self::ok( array( 'id' => $id ) );
		} catch ( \Exception $e ) {
			return self::degraded( $e->getMessage() );
		}
	}

	/**
	 * GET /zns-automation/rules/{id}
	 *
	 * @param  WP_REST_Request $req
	 * @return WP_REST_Response
	 */
	public static function get_rule( WP_REST_Request $req ) {
		try {
			$rule = BizCity_ZNS_Rules_Repo::get_by_id( (int) $req['id'] );
			if ( ! $rule ) {
				return self::fail( 'not_found', 'Quy tắc không tồn tại.' );
			}
			return self::ok( $rule );
		} catch ( \Exception $e ) {
			return self::degraded( $e->getMessage() );
		}
	}

	/**
	 * PUT /zns-automation/rules/{id}
	 *
	 * @param  WP_REST_Request $req
	 * @return WP_REST_Response
	 */
	public static function update_rule( WP_REST_Request $req ) {
		try {
			$id   = (int) $req['id'];
			$data = self::parse_rule_body( $req );
			$ok   = BizCity_ZNS_Rules_Repo::update( $id, $data );
			if ( ! $ok ) {
				return self::fail( 'update_failed', 'Cập nhật thất bại.' );
			}
			return self::ok( array( 'updated' => true ) );
		} catch ( \Exception $e ) {
			return self::degraded( $e->getMessage() );
		}
	}

	/**
	 * DELETE /zns-automation/rules/{id}
	 *
	 * @param  WP_REST_Request $req
	 * @return WP_REST_Response
	 */
	public static function delete_rule( WP_REST_Request $req ) {
		try {
			$ok = BizCity_ZNS_Rules_Repo::delete( (int) $req['id'] );
			return self::ok( array( 'deleted' => $ok ) );
		} catch ( \Exception $e ) {
			return self::degraded( $e->getMessage() );
		}
	}

	/**
	 * POST /zns-automation/rules/{id}/toggle
	 *
	 * @param  WP_REST_Request $req
	 * @return WP_REST_Response
	 */
	public static function toggle_rule( WP_REST_Request $req ) {
		try {
			$id   = (int) $req['id'];
			$rule = BizCity_ZNS_Rules_Repo::get_by_id( $id );
			if ( ! $rule ) {
				return self::fail( 'not_found', 'Quy tắc không tồn tại.' );
			}
			$new_enabled = ! $rule['enabled'];
			BizCity_ZNS_Rules_Repo::update( $id, array( 'enabled' => $new_enabled ? 1 : 0 ) );
			return self::ok( array( 'enabled' => $new_enabled ) );
		} catch ( \Exception $e ) {
			return self::degraded( $e->getMessage() );
		}
	}

	// ── 4. Test ──────────────────────────────────────────────────────────────

	/**
	 * POST /zns-automation/test
	 *
	 * @param  WP_REST_Request $req
	 * @return WP_REST_Response
	 */
	public static function test_send( WP_REST_Request $req ) {
		// [2026-06-27 Johnny Chu] PHASE-CG-ZNS-AUTO — test send with real HTTP
		try {
			$body    = $req->get_json_params();
			$rule_id = (int) ( $body['rule_id'] ?? 0 );
			$phone   = sanitize_text_field( (string) ( $body['phone'] ?? '' ) );
			$sandbox = ! empty( $body['sandbox'] );
			$overrides = (array) ( $body['override_placeholders'] ?? array() );

			if ( ! $rule_id || ! $phone ) {
				return self::fail( 'invalid_param', 'Thiếu rule_id hoặc phone.' );
			}

			$rule = BizCity_ZNS_Rules_Repo::get_by_id( $rule_id );
			if ( ! $rule ) {
				return self::fail( 'not_found', 'Quy tắc không tồn tại.' );
			}

			$temp_vars = $rule['temp_vars'] ?? array();
			$temp_data = BizCity_ZNS_General_Sender::build_temp_data( $temp_vars, $overrides );

			$creds = class_exists( 'BizCity_CF7_ZNS_Config', false )
				? BizCity_CF7_ZNS_Config::get_global_settings()
				: array( 'api_key' => '', 'secret_key' => '', 'oa_id' => '' );

			$result = BizCity_ZNS_General_Sender::dispatch( array(
				'rule_id'    => $rule_id,
				'event_key'  => 'test_manual',
				'phone'      => $phone,
				'temp_id'    => $rule['temp_id'],
				'oa_id'      => $rule['oa_id'] ?: ( $creds['oa_id'] ?? '' ),
				'temp_data'  => $temp_data,
				'sandbox'    => $sandbox,
				'campaign_id'=> 'ManualTest',
				'api_key'    => $creds['api_key'],
				'secret_key' => $creds['secret_key'],
			) );

			return self::ok( array(
				'sent'        => $result['success'],
				'code_result' => $result['code_result'],
				'sms_id'      => $result['sms_id'],
				'error'       => $result['error'],
				'payload_sent'=> array(
					'TempID'   => $rule['temp_id'],
					'Phone'    => BizCity_ZNS_General_Sender::mask_phone( $phone ),
					'TempData' => $temp_data,
					'Sandbox'  => $sandbox ? '1' : '0',
					'OAID'     => $rule['oa_id'] ?: ( $creds['oa_id'] ?? '' ),
				),
			) );
		} catch ( \Exception $e ) {
			return self::degraded( $e->getMessage() );
		}
	}

	/**
	 * POST /zns-automation/test/dry-run
	 *
	 * @param  WP_REST_Request $req
	 * @return WP_REST_Response
	 */
	public static function dry_run( WP_REST_Request $req ) {
		// [2026-06-27 Johnny Chu] PHASE-CG-ZNS-AUTO — dry run: build payload without HTTP
		try {
			$body    = $req->get_json_params();
			$rule_id = (int) ( $body['rule_id'] ?? 0 );
			$phone   = sanitize_text_field( (string) ( $body['phone'] ?? '0901234567' ) );
			$overrides = (array) ( $body['override_placeholders'] ?? array() );

			if ( ! $rule_id ) {
				return self::fail( 'invalid_param', 'Thiếu rule_id.' );
			}
			$rule = BizCity_ZNS_Rules_Repo::get_by_id( $rule_id );
			if ( ! $rule ) {
				return self::fail( 'not_found', 'Quy tắc không tồn tại.' );
			}
			$temp_vars = $rule['temp_vars'] ?? array();
			$temp_data = BizCity_ZNS_General_Sender::build_temp_data( $temp_vars, $overrides );

			return self::ok( array(
				'dry_run' => true,
				'payload' => array(
					'TempID'   => $rule['temp_id'],
					'Phone'    => BizCity_ZNS_General_Sender::mask_phone( $phone ),
					'TempData' => $temp_data,
					'Sandbox'  => '1',
					'OAID'     => $rule['oa_id'],
					'campaignid' => $rule['campaign_id'] ?: ( 'zns_rule_' . $rule_id ),
				),
			) );
		} catch ( \Exception $e ) {
			return self::degraded( $e->getMessage() );
		}
	}

	// ── 5. Logs ──────────────────────────────────────────────────────────────

	/**
	 * GET /zns-automation/logs
	 *
	 * @param  WP_REST_Request $req
	 * @return WP_REST_Response
	 */
	public static function get_logs( WP_REST_Request $req ) {
		try {
			$date  = sanitize_text_field( (string) ( $req->get_param( 'date' )  ?? '' ) );
			$limit = min( 1000, max( 1, (int) ( $req->get_param( 'limit' ) ?? 500 ) ) );
			$level = sanitize_text_field( (string) ( $req->get_param( 'level' ) ?? '' ) );
			$event = sanitize_text_field( (string) ( $req->get_param( 'event' ) ?? '' ) );

			$data = BizCity_ZNS_Send_Tracker::read_file_logs( $date, $limit, $level, $event );
			return self::ok( $data );
		} catch ( \Exception $e ) {
			return self::degraded( $e->getMessage() );
		}
	}

	/**
	 * GET /zns-automation/logs/dates
	 *
	 * @param  WP_REST_Request $req
	 * @return WP_REST_Response
	 */
	public static function get_log_dates( WP_REST_Request $req ) {
		try {
			return self::ok( BizCity_ZNS_Send_Tracker::get_log_dates() );
		} catch ( \Exception $e ) {
			return self::degraded( $e->getMessage() );
		}
	}

	// ── 6. Stats ─────────────────────────────────────────────────────────────

	/**
	 * GET /zns-automation/stats
	 *
	 * @param  WP_REST_Request $req
	 * @return WP_REST_Response
	 */
	public static function get_stats( WP_REST_Request $req ) {
		try {
			$period = sanitize_text_field( (string) ( $req->get_param( 'period' ) ?? '30d' ) );
			if ( ! in_array( $period, array( '7d', '30d', '90d' ), true ) ) {
				$period = '30d';
			}
			return self::ok( BizCity_ZNS_Send_Tracker::get_stats( $period ) );
		} catch ( \Exception $e ) {
			return self::degraded( $e->getMessage() );
		}
	}

	// ── 7. Sends ─────────────────────────────────────────────────────────────

	/**
	 * GET /zns-automation/sends
	 *
	 * @param  WP_REST_Request $req
	 * @return WP_REST_Response
	 */
	public static function list_sends( WP_REST_Request $req ) {
		try {
			$filters = array(
				'event_key' => sanitize_key( (string) ( $req->get_param( 'event_key' ) ?? '' ) ),
				'rule_id'   => (int) ( $req->get_param( 'rule_id' ) ?? 0 ),
				'success'   => $req->get_param( 'success' ) !== null ? (bool) $req->get_param( 'success' ) : '',
				'date_from' => sanitize_text_field( (string) ( $req->get_param( 'date_from' ) ?? '' ) ),
				'date_to'   => sanitize_text_field( (string) ( $req->get_param( 'date_to' ) ?? '' ) ),
			);
			$page = max( 1, (int) ( $req->get_param( 'page' ) ?? 1 ) );
			$per  = min( 200, max( 10, (int) ( $req->get_param( 'per_page' ) ?? 50 ) ) );

			return self::ok( BizCity_ZNS_Send_Tracker::get_list( $filters, $page, $per ) );
		} catch ( \Exception $e ) {
			return self::degraded( $e->getMessage() );
		}
	}

	/**
	 * GET /zns-automation/sends/export
	 * Returns CSV (UTF-8 with BOM for Excel Windows).
	 *
	 * @param  WP_REST_Request $req
	 * @return void  Outputs CSV directly.
	 */
	public static function export_sends( WP_REST_Request $req ) {
		// [2026-06-27 Johnny Chu] PHASE-CG-ZNS-AUTO — CSV export
		$filters = array(
			'event_key' => sanitize_key( (string) ( $req->get_param( 'event_key' ) ?? '' ) ),
			'rule_id'   => (int) ( $req->get_param( 'rule_id' ) ?? 0 ),
			'date_from' => sanitize_text_field( (string) ( $req->get_param( 'date_from' ) ?? '' ) ),
			'date_to'   => sanitize_text_field( (string) ( $req->get_param( 'date_to' ) ?? '' ) ),
		);
		$rows = BizCity_ZNS_Send_Tracker::export( $filters );

		$filename = 'zns-sends-' . gmdate( 'Y-m-d' ) . '.csv';
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Cache-Control: no-store, no-cache' );
		// UTF-8 BOM for Excel Windows
		echo "\xEF\xBB\xBF";
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'ID', 'Rule ID', 'Event', 'Phone', 'Template ID', 'OA ID', 'Code eSMS', 'SMS ID', 'Thành công', 'Sandbox', 'Thời gian gửi', 'Loại nguồn', 'ID nguồn' ) );
		foreach ( $rows as $r ) {
			fputcsv( $out, array(
				$r['id'] ?? '',
				$r['rule_id'] ?? '',
				$r['event_key'] ?? '',
				$r['phone'] ?? '',
				$r['temp_id'] ?? '',
				$r['oa_id'] ?? '',
				$r['esms_code'] ?? '',
				$r['sms_id'] ?? '',
				( ! empty( $r['status'] ) && $r['status'] === 'sent' ) ? 'Có' : 'Không',
				! empty( $r['is_test'] ) ? 'Có' : 'Không',
				$r['sent_at'] ?? '',
				$r['source_object_type'] ?? '',
				$r['source_object_id'] ?? '',
			) );
		}
		fclose( $out );
		exit;
	}

	// ── Response helpers (fail-OPEN) ──────────────────────────────────────────

	/**
	 * @param  mixed $data
	 * @return WP_REST_Response
	 */
	private static function ok( $data ) {
		return new WP_REST_Response( array( 'success' => true, 'data' => $data ), 200 );
	}

	/**
	 * @param  string $code
	 * @param  string $message
	 * @return WP_REST_Response
	 */
	private static function fail( $code, $message ) {
		return new WP_REST_Response( array( 'success' => false, 'code' => $code, 'message' => $message ), 200 );
	}

	/**
	 * Fail-OPEN degraded response (always 200, R-GW-8).
	 *
	 * @param  string $message
	 * @return WP_REST_Response
	 */
	private static function degraded( $message = '' ) {
		return new WP_REST_Response( array( 'success' => false, '_degraded' => true, 'message' => $message ), 200 );
	}

	// ── Parse helper ─────────────────────────────────────────────────────────

	/**
	 * Parse & sanitize rule fields từ request body.
	 *
	 * @param  WP_REST_Request $req
	 * @return array
	 */
	private static function parse_rule_body( WP_REST_Request $req ) {
		$body = $req->get_json_params() ?: array();
		$rule = array(
			'name'        => sanitize_text_field( (string) ( $body['name']       ?? '' ) ),
			'event_key'   => sanitize_key(         (string) ( $body['event_key'] ?? '' ) ),
			'temp_id'     => sanitize_text_field( (string) ( $body['temp_id']    ?? '' ) ),
			'oa_id'       => sanitize_text_field( (string) ( $body['oa_id']      ?? '' ) ),
			'campaign_id' => sanitize_text_field( (string) ( $body['campaign_id']?? '' ) ),
			'sandbox'     => ! empty( $body['sandbox'] ) ? 1 : 0,
			'enabled'     => isset( $body['enabled'] ) ? ( (bool) $body['enabled'] ? 1 : 0 ) : 1,
			'sort_order'  => (int) ( $body['sort_order'] ?? 0 ),
		);
		if ( isset( $body['temp_vars'] ) && is_array( $body['temp_vars'] ) ) {
			$rule['temp_vars'] = $body['temp_vars'];
		}
		return $rule;
	}
}
