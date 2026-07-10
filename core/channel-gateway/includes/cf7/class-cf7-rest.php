<?php
/**
 * CF7 Channel — REST API
 *
 * Namespace: bizcity-channel/v1
 *
 * Endpoints:
 *   GET  /cf7/forms                       — list CF7 forms + stats + mapping status
 *   GET  /cf7/forms/{id}/mapping          — field mapping for one form
 *   POST /cf7/forms/{id}/mapping          — save field mapping
 *   POST /cf7/forms/{id}/test-submit      — run a fake submission (no email)
 *   GET  /cf7/forms/{id}/submissions      — paginated submissions for one form
 *   GET  /cf7/submissions                 — global submissions (filterable)
 *
 * R-GW-8: all endpoints return HTTP 200 even on error.
 * R-CH-NS: namespace = bizcity-channel/v1.
 *
 * @package BizCity_Channel_Gateway
 * @since   2026-06-13
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_CF7_REST {

	const NS = 'bizcity-channel/v1';

	public static function init(): void {
		// [2026-06-13 Johnny Chu] PHASE-CG-CF7 — register CF7 REST routes
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		// List all forms
		register_rest_route( self::NS, '/cf7/forms', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'list_forms' ),
			'permission_callback' => array( __CLASS__, 'admin_only' ),
		) );

		// Mapping GET / POST
		register_rest_route( self::NS, '/cf7/forms/(?P<id>\d+)/mapping', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_mapping' ),
				'permission_callback' => array( __CLASS__, 'admin_only' ),
				'args'                => array( 'id' => array( 'type' => 'integer' ) ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'save_mapping' ),
				'permission_callback' => array( __CLASS__, 'admin_only' ),
				'args'                => array( 'id' => array( 'type' => 'integer' ) ),
			),
		) );

		// Test submit
		register_rest_route( self::NS, '/cf7/forms/(?P<id>\d+)/test-submit', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'test_submit' ),
			'permission_callback' => array( __CLASS__, 'admin_only' ),
			'args'                => array( 'id' => array( 'type' => 'integer' ) ),
		) );

		// Submissions for one form
		register_rest_route( self::NS, '/cf7/forms/(?P<id>\d+)/submissions', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'form_submissions' ),
			'permission_callback' => array( __CLASS__, 'admin_only' ),
			'args'                => array(
				'id'       => array( 'type' => 'integer' ),
				'page'     => array( 'type' => 'integer', 'default' => 1 ),
				'per_page' => array( 'type' => 'integer', 'default' => 20 ),
			),
		) );

		// [2026-06-24 Johnny Chu] PHASE-CF7-RESP — Response config GET / POST
		register_rest_route( self::NS, '/cf7/forms/(?P<id>\d+)/response-config', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_response_config' ),
				'permission_callback' => array( __CLASS__, 'admin_only' ),
				'args'                => array( 'id' => array( 'type' => 'integer' ) ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'save_response_config' ),
				'permission_callback' => array( __CLASS__, 'admin_only' ),
				'args'                => array( 'id' => array( 'type' => 'integer' ) ),
			),
		) );

		// [2026-06-25 Johnny Chu] PHASE-CG-CF7-ZNS — Global eSMS ZNS settings GET / POST
		register_rest_route( self::NS, '/cf7/zns-settings', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_zns_settings' ),
				'permission_callback' => array( __CLASS__, 'admin_only' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'save_zns_settings' ),
				'permission_callback' => array( __CLASS__, 'admin_only' ),
			),
		) );

		// [2026-06-25 Johnny Chu] PHASE-CG-CF7-ZNS — Per-form ZNS config GET / POST
		register_rest_route( self::NS, '/cf7/forms/(?P<id>\d+)/zns-config', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_zns_config' ),
				'permission_callback' => array( __CLASS__, 'admin_only' ),
				'args'                => array( 'id' => array( 'type' => 'integer' ) ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'save_zns_config' ),
				'permission_callback' => array( __CLASS__, 'admin_only' ),
				'args'                => array( 'id' => array( 'type' => 'integer' ) ),
			),
		) );

		// [2026-06-25 Johnny Chu] PHASE-CG-CF7-ZNS — CF7 form field names + sample values from latest submission
		register_rest_route( self::NS, '/cf7/forms/(?P<id>\d+)/fields', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'form_fields' ),
			'permission_callback' => array( __CLASS__, 'admin_only' ),
			'args'                => array( 'id' => array( 'type' => 'integer' ) ),
		) );

		// [2026-06-25 Johnny Chu] PHASE-CG-CF7-ZNS — Test send ZNS for a form
		register_rest_route( self::NS, '/cf7/forms/(?P<id>\d+)/zns-test', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'zns_test_send' ),
			'permission_callback' => array( __CLASS__, 'admin_only' ),
			'args'                => array( 'id' => array( 'type' => 'integer' ) ),
		) );

		// [2026-06-25 Johnny Chu] PHASE-CG-CF7-ZNS — Standalone ZNS test (no form_id needed)
		register_rest_route( self::NS, '/cf7/zns-direct-test', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'zns_direct_test' ),
			'permission_callback' => array( __CLASS__, 'admin_only' ),
		) );

		// [2026-06-25 Johnny Chu] PHASE-CG-CF7-ZNS — List configured Zalo OA accounts
		register_rest_route( self::NS, '/cf7/zns-oa-accounts', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get_zns_oa_accounts' ),
			'permission_callback' => array( __CLASS__, 'admin_only' ),
		) );

		// [2026-06-25 Johnny Chu] PHASE-CRM-SUBMISSIONS — Analytics stats
		register_rest_route( self::NS, '/cf7/submissions/stats', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'submissions_stats' ),
			'permission_callback' => array( __CLASS__, 'admin_only' ),
			'args'                => array(
				'days'    => array( 'type' => 'integer', 'default' => 30 ),
				'form_id' => array( 'type' => 'integer', 'default' => 0 ),
			),
		) );

		// [2026-06-25 Johnny Chu] PHASE-CRM-SUBMISSIONS — Export all rows (JSON → FE converts CSV)
		register_rest_route( self::NS, '/cf7/submissions/export', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'submissions_export' ),
			'permission_callback' => array( __CLASS__, 'admin_only' ),
			'args'                => array(
				'form_id'    => array( 'type' => 'integer', 'default' => 0 ),
				'crm_action' => array( 'type' => 'string',  'default' => '' ),
				'from'       => array( 'type' => 'string',  'default' => '' ),
				'to'         => array( 'type' => 'string',  'default' => '' ),
			),
		) );

		// Global submissions
		register_rest_route( self::NS, '/cf7/submissions', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'global_submissions' ),
			'permission_callback' => array( __CLASS__, 'admin_only' ),
			'args'                => array(
				'form_id'    => array( 'type' => 'integer', 'default' => 0 ),
				'crm_action' => array( 'type' => 'string',  'default' => '' ),
				'from'       => array( 'type' => 'string',  'default' => '' ),
				'to'         => array( 'type' => 'string',  'default' => '' ),
				'page'       => array( 'type' => 'integer', 'default' => 1 ),
				'per_page'   => array( 'type' => 'integer', 'default' => 20 ),
			),
		) );
	}

	// ── Handlers ─────────────────────────────────────────────────────────

	public static function list_forms( \WP_REST_Request $req ): \WP_REST_Response {
		if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
			return new \WP_REST_Response( array( 'success' => false, '_degraded' => true, 'error' => 'Contact Form 7 plugin not active.' ), 200 );
		}
		$forms = BizCity_CF7_Channel_Listener::get_all_forms();
		return new \WP_REST_Response( array( 'success' => true, 'data' => $forms ), 200 );
	}

	public static function get_mapping( \WP_REST_Request $req ): \WP_REST_Response {
		$form_id = (int) $req['id'];
		$mapping = BizCity_CF7_Channel_Listener::get_form_mapping( $form_id );

		// If no saved mapping, build auto-suggest from CF7 form tags
		if ( empty( $mapping['field_map'] ) && class_exists( 'WPCF7_ContactForm' ) ) {
			$cf7 = WPCF7_ContactForm::get_instance( $form_id );
			if ( $cf7 ) {
				$fake_posted = array();
				foreach ( $cf7->scan_form_tags() as $tag ) {
					if ( $tag->name ) {
						$fake_posted[ $tag->name ] = '';
					}
				}
				$mapping['field_map']      = BizCity_CF7_Channel_Listener::auto_detect_mapping( $fake_posted );
				$mapping['auto_suggested'] = true;
			}
		}

		return new \WP_REST_Response( array( 'success' => true, 'data' => $mapping ), 200 );
	}

	public static function save_mapping( \WP_REST_Request $req ): \WP_REST_Response {
		$form_id = (int) $req['id'];
		$body    = $req->get_json_params();
		if ( ! is_array( $body ) ) {
			return new \WP_REST_Response( array( 'success' => false, 'error' => 'Invalid JSON body.' ), 200 );
		}
		BizCity_CF7_Channel_Listener::save_form_mapping( $form_id, $body );
		$saved = BizCity_CF7_Channel_Listener::get_form_mapping( $form_id );
		return new \WP_REST_Response( array( 'success' => true, 'data' => $saved ), 200 );
	}

	public static function test_submit( \WP_REST_Request $req ): \WP_REST_Response {
		$form_id = (int) $req['id'];
		$body    = $req->get_json_params();
		$fields  = is_array( $body['fields'] ?? null ) ? $body['fields'] : array();

		if ( empty( $fields ) ) {
			return new \WP_REST_Response( array( 'success' => false, 'error' => 'No fields provided.' ), 200 );
		}

		$mapping  = BizCity_CF7_Channel_Listener::get_form_mapping( $form_id );
		$mapped   = BizCity_CF7_Channel_Listener::apply_mapping( $fields, $mapping['field_map'] ?? array() );

		$email = sanitize_email( $mapped['email'] ?? '' );
		$phone = preg_replace( '/[^0-9+\-() ]/', '', $mapped['phone'] ?? '' );

		$warnings = array();
		if ( empty( $email ) && empty( $phone ) ) {
			$warnings[] = 'Không tìm thấy email hoặc phone trong mapped data — submission sẽ bị bỏ qua.';
		}

		$crm_result = array( 'action' => 'skipped', 'contact_id' => 0, 'error' => null );
		if ( ( $email || $phone ) && BizCity_CF7_CRM_Sync::is_available() ) {
			$crm_result = BizCity_CF7_CRM_Sync::upsert( $email, $phone, $mapped, array(
				'form_id'    => $form_id,
				'form_title' => '[Test Submit]',
				'sub_id'     => 0,
				'auto_tag'   => array_merge( array( 'cf7', 'test' ), $mapping['auto_tag'] ?? array() ),
				'owner_id'   => $mapping['default_owner_id'] ?? 0,
			) );
		} elseif ( ! BizCity_CF7_CRM_Sync::is_available() ) {
			$warnings[] = 'bizcity-twin-crm không active — CRM sync bị skip.';
		}

		return new \WP_REST_Response( array(
			'success'  => true,
			'data'     => array(
				'raw'        => $fields,
				'mapped'     => $mapped,
				'crm_action' => $crm_result['action'],
				'contact_id' => (int) $crm_result['contact_id'],
				'warnings'   => $warnings,
			),
		), 200 );
	}

	public static function form_submissions( \WP_REST_Request $req ): \WP_REST_Response {
		if ( ! BizCity_CF7_Installer::table_exists() ) {
			return new \WP_REST_Response( array( 'success' => true, 'data' => array(), 'total' => 0 ), 200 );
		}
		$form_id = (int) $req['id'];
		$page    = max( 1, (int) $req['page'] );
		$per     = min( 100, max( 1, (int) ( $req['per_page'] ?? 20 ) ) );

		$rows  = BizCity_CF7_Submissions_Log::get_list( $form_id, $page, $per );
		$total = BizCity_CF7_Submissions_Log::count( $form_id );

		return new \WP_REST_Response( array(
			'success' => true,
			'data'    => array_map( array( __CLASS__, 'format_submission' ), $rows ),
			'total'   => $total,
			'pages'   => $per > 0 ? (int) ceil( $total / $per ) : 1,
		), 200 );
	}

	public static function global_submissions( \WP_REST_Request $req ): \WP_REST_Response {
		if ( ! BizCity_CF7_Installer::table_exists() ) {
			return new \WP_REST_Response( array( 'success' => true, 'data' => array(), 'total' => 0, 'pages' => 1 ), 200 );
		}
		$result = BizCity_CF7_Submissions_Log::get_global_list( array(
			'form_id'    => (int) $req['form_id'],
			'crm_action' => (string) $req['crm_action'],
			'from'       => sanitize_text_field( (string) $req['from'] ),
			'to'         => sanitize_text_field( (string) $req['to'] ),
			'page'       => max( 1, (int) $req['page'] ),
			'per'        => min( 100, max( 1, (int) ( $req['per_page'] ?? 20 ) ) ),
		) );

		return new \WP_REST_Response( array(
			'success' => true,
			'data'    => array_map( array( __CLASS__, 'format_submission' ), $result['rows'] ),
			'total'   => $result['total'],
			'pages'   => $result['pages'],
		), 200 );
	}

	// ── Permission ────────────────────────────────────────────────────────

	public static function admin_only(): bool {
		return current_user_can( 'manage_options' );
	}

	// ── Formatter ─────────────────────────────────────────────────────────

	private static function format_submission( $row ): array {
		if ( ! $row ) {
			return array();
		}
		return array(
			'id'             => (int) $row->id,
			'form_id'        => (int) $row->form_id,
			'form_title'     => $row->form_title,
			'email'          => $row->email,
			'phone'          => $row->phone,
			'crm_contact_id' => $row->crm_contact_id ? (int) $row->crm_contact_id : null,
			'crm_action'     => $row->crm_action,
			'crm_error'      => $row->crm_error,
			'source_url'     => $row->source_url,
			'submitted_at'   => $row->submitted_at,
			// raw/mapped decoded for detail view
			'raw_data'       => $row->raw_data ? json_decode( $row->raw_data, true ) : null,
			'mapped_data'    => $row->mapped_data ? json_decode( $row->mapped_data, true ) : null,
		);
	}

	// ── ZNS handlers (PHASE-CG-CF7-ZNS) ─────────────────────────────────────

	/**
	 * GET /cf7/zns-settings
	 * Returns global eSMS credentials with secret_key masked.
	 *
	 * [2026-06-25 Johnny Chu] PHASE-CG-CF7-ZNS
	 */
	public static function get_zns_settings( \WP_REST_Request $req ) {
		if ( ! class_exists( 'BizCity_CF7_ZNS_Config' ) ) {
			return new \WP_REST_Response( array( 'success' => false, '_degraded' => true, 'error' => 'BizCity_CF7_ZNS_Config not loaded.' ), 200 );
		}
		return new \WP_REST_Response( array( 'success' => true, 'data' => BizCity_CF7_ZNS_Config::get_global_settings_safe() ), 200 );
	}

	/**
	 * POST /cf7/zns-settings
	 * Body: { api_key, secret_key, oa_id }
	 *
	 * [2026-06-25 Johnny Chu] PHASE-CG-CF7-ZNS
	 */
	public static function save_zns_settings( \WP_REST_Request $req ) {
		if ( ! class_exists( 'BizCity_CF7_ZNS_Config' ) ) {
			return new \WP_REST_Response( array( 'success' => false, '_degraded' => true, 'error' => 'BizCity_CF7_ZNS_Config not loaded.' ), 200 );
		}
		$body = (array) $req->get_json_params();
		BizCity_CF7_ZNS_Config::save_global_settings( $body );
		return new \WP_REST_Response( array( 'success' => true, 'data' => BizCity_CF7_ZNS_Config::get_global_settings_safe() ), 200 );
	}

	/**
	 * GET /cf7/forms/{id}/zns-config
	 *
	 * [2026-06-25 Johnny Chu] PHASE-CG-CF7-ZNS
	 */
	public static function get_zns_config( \WP_REST_Request $req ) {
		if ( ! class_exists( 'BizCity_CF7_ZNS_Config' ) ) {
			return new \WP_REST_Response( array( 'success' => false, '_degraded' => true, 'error' => 'BizCity_CF7_ZNS_Config not loaded.' ), 200 );
		}
		$form_id = (int) $req->get_param( 'id' );
		return new \WP_REST_Response( array( 'success' => true, 'data' => BizCity_CF7_ZNS_Config::get_form_config( $form_id ) ), 200 );
	}

	/**
	 * POST /cf7/forms/{id}/zns-config
	 * Body: { enabled, temp_id, oa_id, sandbox, campaign_id, temp_vars[] }
	 *
	 * [2026-06-25 Johnny Chu] PHASE-CG-CF7-ZNS
	 */
	public static function save_zns_config( \WP_REST_Request $req ) {
		if ( ! class_exists( 'BizCity_CF7_ZNS_Config' ) ) {
			return new \WP_REST_Response( array( 'success' => false, '_degraded' => true, 'error' => 'BizCity_CF7_ZNS_Config not loaded.' ), 200 );
		}
		$form_id = (int) $req->get_param( 'id' );
		$json    = $req->get_json_params();
		$body    = is_array( $json ) ? $json : array();

		// [2026-06-25 Johnny Chu] DEBUG — log incoming body to PHP error_log
		error_log( '[bizcity-zns-config] save form_id=' . $form_id . ' body=' . wp_json_encode( $body ) );

		BizCity_CF7_ZNS_Config::save_form_config( $form_id, $body );

		// [2026-06-25 Johnny Chu] DEBUG — log what was actually saved
		$saved = BizCity_CF7_ZNS_Config::get_form_config( $form_id );
		error_log( '[bizcity-zns-config] after_save form_id=' . $form_id . ' temp_vars=' . wp_json_encode( $saved['temp_vars'] ?? null ) );

		return new \WP_REST_Response( array( 'success' => true, 'data' => $saved ), 200 );
	}

	/**
	 * POST /cf7/forms/{id}/zns-test
	 * Body: { phone, mapped_fields: {}, force_sandbox: bool }
	 * Sends a ZNS in sandbox mode by default; pass force_sandbox=false to send real.
	 *
	 * [2026-06-25 Johnny Chu] PHASE-CG-CF7-ZNS
	 */
	public static function zns_test_send( \WP_REST_Request $req ) {
		if ( ! class_exists( 'BizCity_CF7_ZNS_Sender' ) || ! class_exists( 'BizCity_CF7_ZNS_Config' ) ) {
			return new \WP_REST_Response( array( 'success' => false, '_degraded' => true, 'error' => 'ZNS classes not loaded.' ), 200 );
		}

		$form_id = (int) $req->get_param( 'id' );
		$body    = (array) $req->get_json_params();

		$phone  = BizCity_CF7_ZNS_Sender::normalize_phone( (string) ( $body['phone'] ?? '' ) );
		$mapped = is_array( $body['mapped_fields'] ?? null ) ? $body['mapped_fields'] : array();

		if ( empty( $phone ) ) {
			return new \WP_REST_Response( array( 'success' => false, 'error' => 'Phone number is required.' ), 200 );
		}

		$cfg    = BizCity_CF7_ZNS_Config::get_form_config( $form_id );
		$global = BizCity_CF7_ZNS_Config::get_global_settings();

		if ( empty( $global['api_key'] ) || empty( $global['secret_key'] ) ) {
			return new \WP_REST_Response( array( 'success' => false, 'error' => 'eSMS credentials not configured.' ), 200 );
		}
		if ( empty( $cfg['temp_id'] ) ) {
			return new \WP_REST_Response( array( 'success' => false, 'error' => 'TempID not configured for this form.' ), 200 );
		}

		$oa_id = ! empty( $cfg['oa_id'] ) ? $cfg['oa_id'] : $global['oa_id'];
		if ( empty( $oa_id ) ) {
			return new \WP_REST_Response( array( 'success' => false, 'error' => 'OA ID not configured.' ), 200 );
		}

		// [2026-06-25 Johnny Chu] PHASE-CG-CF7-ZNS FIX — For test sends, $mapped IS the TempData
		// (user enters template var name → value directly in the test UI).
		// DO NOT use build_temp_data() — that function maps CF7 field names → var names, which
		// is the wrong direction for manual test inputs.
		// Merge: start with literal vars from config, then override with user-provided $mapped.
		$temp_data = array();
		foreach ( (array) ( $cfg['temp_vars'] ?? array() ) as $tv ) {
			$var_name = (string) ( $tv['var_name'] ?? '' );
			if ( $var_name !== '' && ( $tv['source'] ?? 'mapped' ) === 'literal' ) {
				$temp_data[ $var_name ] = (string) ( $tv['literal_value'] ?? '' );
			}
		}
		// User-provided values override literals
		foreach ( $mapped as $k => $v ) {
			if ( (string) $k !== '' ) {
				$temp_data[ (string) $k ] = (string) $v;
			}
		}

		// File-log TRƯỚC HTTP (R-CH-FILE-LOG)
		// [2026-06-25 Johnny Chu] PHASE-CG-CF7-ZNS — force_sandbox=false lets user send real
		// JSON false decoded by PHP = boolean false; use strict === comparison
		$force_sandbox_param = $body['force_sandbox'] ?? true;
		$use_sandbox = ( $force_sandbox_param === false ) ? ! empty( $cfg['sandbox'] ) : true;

		if ( class_exists( 'BizCity_Channel_File_Logger', false ) ) {
			BizCity_Channel_File_Logger::write(
				BizCity_Channel_File_Logger::CH_ZALO_ZNS,
				BizCity_Channel_File_Logger::LEVEL_INFO,
				'zns_test_send_attempt',
				'Test send ZNS form #' . $form_id . ' to ' . BizCity_CF7_ZNS_Sender::mask_phone( $phone ),
				array(
					'form_id'    => $form_id,
					'temp_id'    => $cfg['temp_id'],
					'oa_id'      => $oa_id,
					'phone'      => BizCity_CF7_ZNS_Sender::mask_phone( $phone ),
					'temp_data'  => $temp_data,
					'sandbox'    => $use_sandbox,
				)
			);
		}

		// [2026-06-25 Johnny Chu] PHASE-CG-CF7-ZNS — use $use_sandbox instead of hardcoded '1'
		$request_args = array(
			'ApiKey'      => $global['api_key'],
			'SecretKey'   => $global['secret_key'],
			'OAID'        => $oa_id,
			'Phone'       => $phone,
			'TempData'    => $temp_data,
			'TempID'      => (string) $cfg['temp_id'],
			'SendingMode' => '1',
			'campaignid'  => 'Test — CF7 Form ' . $form_id,
		);
		if ( $use_sandbox ) {
			$request_args['Sandbox'] = '1';
		}

		$result = BizCity_CF7_ZNS_Sender::send( $request_args );

		// File-log result
		if ( class_exists( 'BizCity_Channel_File_Logger', false ) ) {
			$level = $result['sent'] ? BizCity_Channel_File_Logger::LEVEL_INFO : BizCity_Channel_File_Logger::LEVEL_ERROR;
			BizCity_Channel_File_Logger::write(
				BizCity_Channel_File_Logger::CH_ZALO_ZNS,
				$level,
				$result['sent'] ? 'zns_test_send_ok' : 'zns_test_send_failed',
				'Test result form #' . $form_id . ' — code: ' . $result['code'],
				array(
					'phone'  => BizCity_CF7_ZNS_Sender::mask_phone( $phone ),
					'code'   => $result['code'],
					'sms_id' => $result['sms_id'],
					'error'  => $result['error'],
				)
			);
		}

		return new \WP_REST_Response( array(
			'success' => true,
			'data'    => array(
				'sent'      => $result['sent'],
				'code'      => $result['code'],
				'sms_id'    => $result['sms_id'],
				'error'     => $result['error'],
				'temp_data' => $temp_data,
				'phone'     => BizCity_CF7_ZNS_Sender::mask_phone( $phone ),
				'temp_id'   => $cfg['temp_id'],
				'oa_id'     => $oa_id,
				'sandbox'   => $use_sandbox,
			),
		), 200 );
	}

	// ── Response Config handlers (PHASE-CF7-RESP) ─────────────────────────

	/**
	 * GET /cf7/zns-oa-accounts
	 * Returns list of { uid, oa_id, label, source } from zalo_bot + zalo_oa registry.
	 * Used by CRM selector to populate OA ID dropdown.
	 *
	 * [2026-06-25 Johnny Chu] PHASE-CG-CF7-ZNS
	 */
	/**
	 * GET /cf7/forms/{id}/fields
	 * Returns field names + sample values from the most recent submission of the given form.
	 * Used by FE to populate CF7 field dropdowns in ZNS mapping + test panels.
	 *
	 * [2026-06-25 Johnny Chu] PHASE-CG-CF7-ZNS
	 */
	public static function form_fields( \WP_REST_Request $req ) {
		$form_id = (int) $req->get_param( 'id' );
		if ( ! class_exists( 'BizCity_CF7_Submissions_Log' ) ) {
			return new \WP_REST_Response( array( 'success' => true, 'data' => array() ), 200 );
		}

		// Get the most recent submission for this form to extract field names + sample values.
		$rows = BizCity_CF7_Submissions_Log::get_list( $form_id, 1, 1 );
		if ( empty( $rows ) ) {
			return new \WP_REST_Response( array( 'success' => true, 'data' => array() ), 200 );
		}

		// get_results() returns stdClass objects — use object or array access safely.
		$first = $rows[0];
		$raw   = is_object( $first ) ? ( $first->raw_data ?? '' ) : ( $first['raw_data'] ?? '' );
		if ( is_string( $raw ) && $raw !== '' ) {
			$raw = json_decode( $raw, true );
		}
		if ( ! is_array( $raw ) ) {
			return new \WP_REST_Response( array( 'success' => true, 'data' => array() ), 200 );
		}

		$fields = array();
		foreach ( $raw as $key => $val ) {
			$sample = is_array( $val ) ? implode( ', ', $val ) : (string) $val;
			$fields[] = array(
				'name'   => (string) $key,
				'sample' => $sample,
			);
		}

		return new \WP_REST_Response( array( 'success' => true, 'data' => $fields ), 200 );
	}

	public static function get_zns_oa_accounts( \WP_REST_Request $req ) {
		if ( ! class_exists( 'BizCity_Integration_Registry' ) ) {
			return new \WP_REST_Response( array( 'success' => true, 'data' => array() ), 200 );
		}
		$registry = BizCity_Integration_Registry::instance();
		$raw      = array();
		foreach ( array( 'zalo_bot', 'zalo_oa' ) as $code ) {
			$accts = $registry->get_accounts( $code );
			if ( ! is_array( $accts ) ) { continue; }
			foreach ( $accts as $uid => $acct ) {
				$acct  = (array) $acct;
				$oa_id = (string) ( $acct['oa_id'] ?? $acct['meta']['oa_id'] ?? '' );
				if ( $oa_id === '' ) { continue; }
				$label  = (string) ( $acct['label'] ?? $acct['name'] ?? $uid );
				$raw[]  = array(
					'uid'    => $uid,
					'oa_id'  => $oa_id,
					'label'  => $label,
					'source' => $code,
				);
			}
		}
		// Deduplicate by oa_id — keep first occurrence.
		$seen = array();
		$out  = array();
		foreach ( $raw as $a ) {
			if ( isset( $seen[ $a['oa_id'] ] ) ) { continue; }
			$seen[ $a['oa_id'] ] = true;
			$out[]               = $a;
		}
		return new \WP_REST_Response( array( 'success' => true, 'data' => $out ), 200 );
	}

	/**
	 * POST /cf7/zns-direct-test
	 * Body: { phone, temp_id, oa_id, temp_data: {} }
	 * Standalone test without needing a form — forced Sandbox=1.
	 *
	 * [2026-06-25 Johnny Chu] PHASE-CG-CF7-ZNS
	 */
	public static function zns_direct_test( \WP_REST_Request $req ) {
		if ( ! class_exists( 'BizCity_CF7_ZNS_Sender' ) || ! class_exists( 'BizCity_CF7_ZNS_Config' ) ) {
			return new \WP_REST_Response( array( 'success' => false, '_degraded' => true, 'error' => 'ZNS classes not loaded.' ), 200 );
		}
		$body    = (array) $req->get_json_params();
		$phone   = BizCity_CF7_ZNS_Sender::normalize_phone( (string) ( $body['phone'] ?? '' ) );
		$temp_id = trim( (string) ( $body['temp_id'] ?? '' ) );

		if ( empty( $phone ) ) {
			return new \WP_REST_Response( array( 'success' => false, 'error' => 'Số điện thoại không được để trống.' ), 200 );
		}
		if ( empty( $temp_id ) ) {
			return new \WP_REST_Response( array( 'success' => false, 'error' => 'TempID không được để trống.' ), 200 );
		}

		$global = BizCity_CF7_ZNS_Config::get_global_settings();
		if ( empty( $global['api_key'] ) || empty( $global['secret_key'] ) ) {
			return new \WP_REST_Response( array( 'success' => false, 'error' => 'Chưa cấu hình eSMS API Key / Secret Key.' ), 200 );
		}

		$oa_id = trim( (string) ( $body['oa_id'] ?? '' ) );
		if ( $oa_id === '' ) {
			$oa_id = $global['oa_id'] ?? '';
		}
		if ( empty( $oa_id ) ) {
			return new \WP_REST_Response( array( 'success' => false, 'error' => 'OA ID không được để trống.' ), 200 );
		}

		$temp_data_raw = $body['temp_data'] ?? array();
		if ( ! is_array( $temp_data_raw ) ) { $temp_data_raw = array(); }

		// File-log TRƯỚC HTTP (R-CH-FILE-LOG)
		if ( class_exists( 'BizCity_Channel_File_Logger', false ) ) {
			BizCity_Channel_File_Logger::write(
				BizCity_Channel_File_Logger::CH_ZALO_ZNS,
				BizCity_Channel_File_Logger::LEVEL_INFO,
				'zns_direct_test_attempt',
				'Direct test ZNS to ' . BizCity_CF7_ZNS_Sender::mask_phone( $phone ),
				array(
					'temp_id'   => $temp_id,
					'oa_id'     => $oa_id,
					'phone'     => BizCity_CF7_ZNS_Sender::mask_phone( $phone ),
					'temp_data' => $temp_data_raw,
					'sandbox'   => true,
				)
			);
		}

		$request_args = array(
			'ApiKey'      => $global['api_key'],
			'SecretKey'   => $global['secret_key'],
			'OAID'        => $oa_id,
			'Phone'       => $phone,
			'TempData'    => $temp_data_raw,
			'TempID'      => $temp_id,
			'SendingMode' => '1',
			'campaignid'  => 'ZNS Direct Test',
			'Sandbox'     => '1',
		);

		$result = BizCity_CF7_ZNS_Sender::send( $request_args );

		// File-log result
		if ( class_exists( 'BizCity_Channel_File_Logger', false ) ) {
			$level = $result['sent'] ? BizCity_Channel_File_Logger::LEVEL_INFO : BizCity_Channel_File_Logger::LEVEL_ERROR;
			BizCity_Channel_File_Logger::write(
				BizCity_Channel_File_Logger::CH_ZALO_ZNS,
				$level,
				$result['sent'] ? 'zns_direct_test_ok' : 'zns_direct_test_failed',
				'Direct test result — code: ' . $result['code'],
				array(
					'phone'  => BizCity_CF7_ZNS_Sender::mask_phone( $phone ),
					'code'   => $result['code'],
					'sms_id' => $result['sms_id'],
					'error'  => $result['error'],
				)
			);
		}

		return new \WP_REST_Response( array(
			'success' => true,
			'data'    => array(
				'sent'      => $result['sent'],
				'code'      => (string) ( $result['code'] ?? '' ),
				'sms_id'    => $result['sms_id'] ?? '',
				'error'     => $result['error'] ?? '',
				'temp_data' => $temp_data_raw,
				'phone'     => BizCity_CF7_ZNS_Sender::mask_phone( $phone ),
				'temp_id'   => $temp_id,
				'oa_id'     => $oa_id,
				'sandbox'   => true,
			),
		), 200 );
	}

	/**
	 * GET /cf7/submissions/stats
	 * Returns aggregated analytics for Dashboard.
	 *
	 * [2026-06-25 Johnny Chu] PHASE-CRM-SUBMISSIONS
	 */
	public static function submissions_stats( \WP_REST_Request $req ) {
		if ( ! BizCity_CF7_Installer::table_exists() ) {
			$empty = array( 'totals' => array( 'total' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'error' => 0 ), 'by_date' => array(), 'by_form' => array() );
			return new \WP_REST_Response( array( 'success' => true, 'data' => $empty ), 200 );
		}
		$stats = BizCity_CF7_Submissions_Log::get_stats( array(
			'days'    => max( 1, min( 365, (int) ( $req['days'] ?? 30 ) ) ),
			'form_id' => (int) ( $req['form_id'] ?? 0 ),
		) );
		return new \WP_REST_Response( array( 'success' => true, 'data' => $stats ), 200 );
	}

	/**
	 * GET /cf7/submissions/export
	 * Returns all rows (max 5000) for client-side CSV export.
	 *
	 * [2026-06-25 Johnny Chu] PHASE-CRM-SUBMISSIONS
	 * [2026-07-03 Johnny Chu] PHASE-0.46 FIX — add activity_* + gift_orders + follow_status + assignee to export
	 * [2026-07-08 Johnny Chu] PHASE-0.46 EXPORT — expand to full activity timeline per submission.
	 * [2026-07-08 Johnny Chu] PHASE-0.46 EXPORT — optional group_by=phone + activity_list numbered attempts.
	 */
	public static function submissions_export( \WP_REST_Request $req ) {
		if ( ! BizCity_CF7_Installer::table_exists() ) {
			return new \WP_REST_Response( array( 'success' => true, 'data' => array() ), 200 );
		}
		$group_by = sanitize_key( (string) ( $req['group_by'] ?? '' ) );
		$rows = BizCity_CF7_Submissions_Log::export_all( array(
			'form_id'    => (int) ( $req['form_id'] ?? 0 ),
			'crm_action' => sanitize_text_field( (string) ( $req['crm_action'] ?? '' ) ),
			'from'       => sanitize_text_field( (string) ( $req['from'] ?? '' ) ),
			'to'         => sanitize_text_field( (string) ( $req['to'] ?? '' ) ),
		) );

		if ( empty( $rows ) ) {
			return new \WP_REST_Response( array( 'success' => true, 'data' => array() ), 200 );
		}

		// Batch enrich: activities + unified submission meta (no JOIN on wp_users)
		global $wpdb;
		$cf7_ids = array_map( static function ( $r ) { return (int) $r->id; }, $rows );
		$ids_in  = implode( ',', $cf7_ids );

		// [2026-07-08 Johnny Chu] PHASE-0.46 EXPORT — fetch full activity timeline (no GROUP BY collapse).
		$act_map = array();
		$act_tbl = $wpdb->prefix . 'bizcity_crm_activities';
		if ( function_exists( 'bizcity_tbl_exists' ) && bizcity_tbl_exists( $act_tbl ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$act_rows = $wpdb->get_results(
				"SELECT entity_id, id, type, title, body, user_label, created_at
				 FROM `{$act_tbl}`
				 WHERE entity_type = 'cf7_submission' AND entity_id IN ({$ids_in})
				   AND ( deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00' )
				 ORDER BY entity_id ASC, created_at ASC, id ASC",
				\ARRAY_A
			);
			foreach ( is_array( $act_rows ) ? $act_rows : array() as $a ) {
				$entity_id = (int) $a['entity_id'];
				if ( ! isset( $act_map[ $entity_id ] ) ) {
					$act_map[ $entity_id ] = array();
				}
				$act_map[ $entity_id ][] = $a;
			}
		}

		// Unified submission meta batch (source_meta_json, follow_status, assignee)
		$sub_map      = array();
		$assignee_ids = array();
		$sub_tbl      = $wpdb->prefix . 'bizcity_crm_submissions';
		if ( function_exists( 'bizcity_tbl_exists' ) && bizcity_tbl_exists( $sub_tbl ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$sub_rows = $wpdb->get_results(
				"SELECT source_ref_id, follow_status, assigned_to_wp_user_id, source_meta_json
				 FROM `{$sub_tbl}`
				 WHERE source_type = 'cf7' AND source_ref_id IN ({$ids_in}) AND deleted_at IS NULL",
				\ARRAY_A
			);
			foreach ( is_array( $sub_rows ) ? $sub_rows : array() as $s ) {
				$sub_map[ (int) $s['source_ref_id'] ] = $s;
				$uid = (int) ( $s['assigned_to_wp_user_id'] ?? 0 );
				if ( $uid ) { $assignee_ids[ $uid ] = true; }
			}
		}

		// Resolve assignee display names via WP object cache (no raw JOIN on wp_users)
		$assignee_map = array();
		foreach ( array_keys( $assignee_ids ) as $uid ) {
			$u = get_userdata( $uid );
			if ( $u ) { $assignee_map[ $uid ] = $u->display_name; }
		}

		// [2026-07-08 Johnny Chu] PHASE-0.46 EXPORT — expand each submission into N activity rows.
		$out = array();
		foreach ( $rows as $row ) {
			$cf7_id = (int) $row->id;
			$activities = $act_map[ $cf7_id ] ?? array();
			$sub    = $sub_map[ $cf7_id ] ?? null;

			// Build gift_orders list from source_meta_json.gifts
			$gift_order_ids = array();
			if ( $sub && $sub['source_meta_json'] ) {
				$meta = json_decode( $sub['source_meta_json'], true );
				if ( is_array( $meta ) && ! empty( $meta['gifts'] ) && is_array( $meta['gifts'] ) ) {
					foreach ( $meta['gifts'] as $g ) {
						$wc_id = isset( $g['wc_order_id'] ) ? (int) $g['wc_order_id'] : 0;
						if ( $wc_id ) { $gift_order_ids[] = $wc_id; }
					}
				}
			}

			$uid           = $sub ? (int) ( $sub['assigned_to_wp_user_id'] ?? 0 ) : 0;
			$assignee_name = $uid ? ( $assignee_map[ $uid ] ?? '' ) : '';

			$activity_total = count( $activities );
			if ( $activity_total === 0 ) {
				$formatted = self::format_submission( $row );
				$formatted['activity_id']     = 0;
				$formatted['activity_index']  = 0;
				$formatted['activity_total']  = 0;
				$formatted['activity_user']   = '';
				$formatted['activity_status'] = '';
				$formatted['activity_count']  = 0;
				$formatted['activity_title']  = '';
				$formatted['activity_body']   = '';
				$formatted['activity_at']     = '';
				$formatted['activity_list']   = '';
				$formatted['follow_status']   = $sub ? (string) ( $sub['follow_status'] ?? '' ) : '';
				$formatted['assignee_name']   = $assignee_name;
				$formatted['wc_order_ids']    = $gift_order_ids; // array of int — FE joins as string
				$out[] = $formatted;
				continue;
			}

			foreach ( $activities as $idx => $act ) {
				$formatted = self::format_submission( $row );
				$formatted['activity_id']     = isset( $act['id'] ) ? (int) $act['id'] : 0;
				$formatted['activity_index']  = (int) $idx + 1;
				$formatted['activity_total']  = (int) $activity_total;
				$formatted['activity_user']   = (string) ( $act['user_label'] ?? '' );
				$formatted['activity_status'] = (string) ( $act['type'] ?? '' );
				$formatted['activity_count']  = (int) $activity_total;
				$formatted['activity_title']  = (string) ( $act['title'] ?? '' );
				$formatted['activity_body']   = (string) ( $act['body'] ?? '' );
				$formatted['activity_at']     = (string) ( $act['created_at'] ?? '' );
				$formatted['activity_list']   = '';
				$formatted['follow_status']   = $sub ? (string) ( $sub['follow_status'] ?? '' ) : '';
				$formatted['assignee_name']   = $assignee_name;
				$formatted['wc_order_ids']    = $gift_order_ids; // array of int — FE joins as string
				$out[] = $formatted;
			}
		}

		// [2026-07-08 Johnny Chu] PHASE-0.46 EXPORT — compact mode: one row per phone with numbered activity_list.
		if ( $group_by === 'phone' ) {
			$grouped = array();

			foreach ( $out as $r ) {
				$phone_raw = (string) ( $r['phone'] ?? '' );
				$phone_key = preg_replace( '/\D+/', '', $phone_raw );
				if ( $phone_key === '' ) {
					$phone_key = 'submission_' . (string) ( $r['id'] ?? 0 );
				}

				if ( ! isset( $grouped[ $phone_key ] ) ) {
					$base = $r;
					$base['activity_id']    = 0;
					$base['activity_index'] = 0;
					$base['activity_total'] = 0;
					$base['activity_count'] = 0;
					$base['activity_list']  = '';
					$base['_acts']          = array();
					$base['_seen_act_id']   = array();
					$grouped[ $phone_key ]  = $base;
				}

				$act_id = isset( $r['activity_id'] ) ? (int) $r['activity_id'] : 0;
				if ( $act_id > 0 ) {
					if ( isset( $grouped[ $phone_key ]['_seen_act_id'][ $act_id ] ) ) {
						continue;
					}
					$grouped[ $phone_key ]['_seen_act_id'][ $act_id ] = true;
					$grouped[ $phone_key ]['_acts'][] = array(
						'id'     => $act_id,
						'at'     => (string) ( $r['activity_at'] ?? '' ),
						'status' => (string) ( $r['activity_status'] ?? '' ),
						'title'  => (string) ( $r['activity_title'] ?? '' ),
						'body'   => (string) ( $r['activity_body'] ?? '' ),
						'user'   => (string) ( $r['activity_user'] ?? '' ),
					);
				}
			}

			$out_grouped = array();
			foreach ( $grouped as $g ) {
				$acts = isset( $g['_acts'] ) && is_array( $g['_acts'] ) ? $g['_acts'] : array();
				usort(
					$acts,
					static function ( $a, $b ) {
						$at_a = (string) ( $a['at'] ?? '' );
						$at_b = (string) ( $b['at'] ?? '' );
						if ( $at_a === $at_b ) {
							return (int) ( $a['id'] ?? 0 ) <=> (int) ( $b['id'] ?? 0 );
						}
						return strcmp( $at_a, $at_b );
					}
				);

				$lines = array();
				foreach ( $acts as $idx => $a ) {
					$line = 'Lần ' . ( (int) $idx + 1 ) . ': ';
					$parts = array();
					if ( ! empty( $a['at'] ) ) {
						$parts[] = '[' . $a['at'] . ']';
					}
					if ( ! empty( $a['status'] ) ) {
						$parts[] = (string) $a['status'];
					}
					if ( ! empty( $a['title'] ) ) {
						$parts[] = (string) $a['title'];
					}
					if ( ! empty( $a['body'] ) ) {
						$parts[] = (string) $a['body'];
					}
					if ( ! empty( $a['user'] ) ) {
						$parts[] = 'NV: ' . (string) $a['user'];
					}
					$line .= implode( ' | ', $parts );
					$lines[] = trim( $line );
				}

				$last = ! empty( $acts ) ? $acts[ count( $acts ) - 1 ] : array();
				$g['activity_id']     = 0;
				$g['activity_index']  = 0;
				$g['activity_total']  = count( $acts );
				$g['activity_count']  = count( $acts );
				$g['activity_status'] = ! empty( $last['status'] ) ? (string) $last['status'] : '';
				$g['activity_title']  = ! empty( $last['title'] ) ? (string) $last['title'] : '';
				$g['activity_body']   = ! empty( $last['body'] ) ? (string) $last['body'] : '';
				$g['activity_at']     = ! empty( $last['at'] ) ? (string) $last['at'] : '';
				$g['activity_user']   = ! empty( $last['user'] ) ? (string) $last['user'] : '';
				$g['activity_list']   = implode( "\n", $lines );

				unset( $g['_acts'], $g['_seen_act_id'] );
				$out_grouped[] = $g;
			}

			$out = $out_grouped;
		}

		return new \WP_REST_Response( array( 'success' => true, 'data' => $out ), 200 );
	}

	/**
	 * GET /cf7/forms/{id}/response-config
	 *
	 * [2026-06-24 Johnny Chu] PHASE-CF7-RESP — return per-form response config.
	 */
	public static function get_response_config( \WP_REST_Request $req ) {
		if ( ! class_exists( 'BizCity_CF7_Response_Config' ) ) {
			return new \WP_REST_Response( array( 'success' => false, '_degraded' => true, 'error' => 'BizCity_CF7_Response_Config not loaded.' ), 200 );
		}
		$form_id = (int) $req->get_param( 'id' );
		$cfg     = BizCity_CF7_Response_Config::get( $form_id );
		return new \WP_REST_Response( array( 'success' => true, 'data' => $cfg ), 200 );
	}

	/**
	 * POST /cf7/forms/{id}/response-config
	 * Body: { reply_type, custom_html, prompt_prefix, enabled }
	 *
	 * [2026-06-24 Johnny Chu] PHASE-CF7-RESP — save per-form response config.
	 */
	public static function save_response_config( \WP_REST_Request $req ) {
		if ( ! class_exists( 'BizCity_CF7_Response_Config' ) ) {
			return new \WP_REST_Response( array( 'success' => false, '_degraded' => true, 'error' => 'BizCity_CF7_Response_Config not loaded.' ), 200 );
		}
		$form_id = (int) $req->get_param( 'id' );
		$body    = (array) $req->get_json_params();
		BizCity_CF7_Response_Config::save( $form_id, $body );
		$cfg = BizCity_CF7_Response_Config::get( $form_id );
		return new \WP_REST_Response( array( 'success' => true, 'data' => $cfg ), 200 );
	}
}
