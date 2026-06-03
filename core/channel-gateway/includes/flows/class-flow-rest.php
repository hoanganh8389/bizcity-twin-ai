<?php
/**
 * BizCity Channel Gateway — Flows · REST controller
 *
 * Namespace: `bizcity/cg/v1/flows`
 *
 * Endpoints:
 *   GET    /flows                 — list with filter q + action_type
 *   GET    /flows/dropdowns       — shortcode whitelist + units + reply_modes
 *   GET    /flows/(?P<id>\d+)     — single row
 *   POST   /flows                 — create
 *   PUT    /flows/(?P<id>\d+)     — update
 *   DELETE /flows/(?P<id>\d+)     — delete
 *   POST   /flows/(?P<id>\d+)/test — dry-run match against a sample text
 *   GET    /flows/health          — smoke status (table + counters)
 *
 * Response shape: { ok:true, data:..., ts: epoch_ms }
 *
 * @package    BizCity_Twin_AI
 * @subpackage Channel_Gateway\Flows
 * @since      PHASE-N (2026-05-25)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_CG_Flow_REST {

	const NS = 'bizcity-channel/v1';

	/**
	 * Mirror of `BizCity_CRM_Campaign_Scenario_Dispatcher::SHORTCODE_WHITELIST`
	 * — kept here as a static fallback so the route works even if the CRM
	 * plugin isn't loaded on this site (R-GW-8 standalone topology).
	 */
	const SHORTCODE_WHITELIST = array(
		'tim_san_pham', 'tim_bai_viet', 'tim_chuong_trinh_uu_dai',
		'kiem_tra_diem', 'doi_diem', 'dat_hang', 'tin_tuc_moi_nhat',
	);

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		$ns = self::NS;

		register_rest_route( $ns, '/flows', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'list_rows' ),
				'permission_callback' => array( __CLASS__, 'can_read' ),
				'args'                => array(
					'q'           => array( 'type' => 'string',  'default' => '' ),
					'action_type' => array( 'type' => 'string',  'default' => '' ),
					'limit'       => array( 'type' => 'integer', 'default' => 50 ),
					'offset'      => array( 'type' => 'integer', 'default' => 0 ),
				),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'create_row' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			),
		) );

		register_rest_route( $ns, '/flows/dropdowns', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_dropdowns' ),
			'permission_callback' => array( __CLASS__, 'can_read' ),
		) );

		register_rest_route( $ns, '/flows/health', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_health' ),
			'permission_callback' => array( __CLASS__, 'can_read' ),
		) );

		register_rest_route( $ns, '/flows/(?P<id>\d+)', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_row' ),
				'permission_callback' => array( __CLASS__, 'can_read' ),
			),
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( __CLASS__, 'update_row' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'delete_row' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			),
		) );

		register_rest_route( $ns, '/flows/(?P<id>\d+)/test', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'test_match' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
		) );
	}

	/* ============================================================
	 * PERMISSIONS
	 * ============================================================ */

	public static function can_read(): bool {
		return current_user_can( 'manage_options' );
	}

	public static function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	/* ============================================================
	 * HELPERS
	 * ============================================================ */

	private static function ok( $data, int $status = 200 ): WP_REST_Response {
		return new WP_REST_Response( array(
			'ok'   => true,
			'data' => $data,
			'ts'   => (int) round( microtime( true ) * 1000 ),
		), $status );
	}

	private static function err( string $code, string $msg, int $status = 400 ): WP_REST_Response {
		return new WP_REST_Response( array(
			'ok'    => false,
			'error' => $code,
			'msg'   => $msg,
			'ts'    => (int) round( microtime( true ) * 1000 ),
		), $status );
	}

	private static function sanitize_payload( WP_REST_Request $req, bool $is_update = false ): array {
		$reply_mode  = $req->get_param( 'reply_mode' );
		$action_type = $req->get_param( 'action_type' );
		$attrs_raw   = $req->get_param( 'attributes' );

		$attributes = array();
		if ( is_array( $attrs_raw ) ) {
			foreach ( $attrs_raw as $a ) {
				$k = isset( $a['key'] ) ? sanitize_text_field( (string) $a['key'] ) : '';
				$p = isset( $a['prompt'] ) ? sanitize_textarea_field( (string) $a['prompt'] ) : '';
				if ( '' !== $k ) { $attributes[] = array( 'key' => $k, 'prompt' => $p ); }
			}
		}

		$data = array(
			'message'           => mb_strtolower( (string) sanitize_text_field( (string) $req->get_param( 'message' ) ), 'UTF-8' ),
			'shortcode'         => sanitize_textarea_field( (string) $req->get_param( 'shortcode' ) ),
			'action_type'       => in_array( $action_type, array( 'run_shortcode', 'send_message' ), true ) ? $action_type : 'run_shortcode',
			'reply_mode'        => in_array( $reply_mode, array( 'direct', 'llm' ), true ) ? $reply_mode : 'direct',
			'action_config'     => wp_json_encode( array( 'attributes' => $attributes ), JSON_UNESCAPED_UNICODE ),
			'prompt'            => (string) $req->get_param( 'prompt' ),
			'reminder_delay'    => (int) $req->get_param( 'reminder_delay' ),
			'reminder_unit'     => sanitize_text_field( (string) ( $req->get_param( 'reminder_unit' ) ?: 'minutes' ) ),
			'reminder_text'     => sanitize_textarea_field( (string) $req->get_param( 'reminder_text' ) ),
			'delay_only'        => $req->get_param( 'delay_only' ) ? 1 : 0,
			'updated_at'        => current_time( 'mysql' ),
		);
		$data['message_khong_dau'] = BizCity_CG_Flow_Handler::strip_accents( $data['message'] );
		return $data;
	}

	private static function format_row( $row ): array {
		if ( ! $row ) { return array(); }
		$cfg   = json_decode( (string) $row->action_config, true );
		$attrs = is_array( $cfg['attributes'] ?? null ) ? $cfg['attributes'] : array();
		return array(
			'id'                => (int) $row->id,
			'message'           => (string) $row->message,
			'message_khong_dau' => (string) $row->message_khong_dau,
			'shortcode'         => (string) $row->shortcode,
			'action_type'       => (string) $row->action_type,
			'reply_mode'        => (string) ( $row->reply_mode ?? 'direct' ),
			'attributes'        => $attrs,
			'prompt'            => (string) $row->prompt,
			'reminder_delay'    => (int) $row->reminder_delay,
			'reminder_unit'     => (string) $row->reminder_unit,
			'reminder_text'     => (string) $row->reminder_text,
			'delay_only'        => (int) $row->delay_only ? 1 : 0,
			'updated_at'        => (string) $row->updated_at,
		);
	}

	/* ============================================================
	 * HANDLERS
	 * ============================================================ */

	public static function list_rows( WP_REST_Request $req ): WP_REST_Response {
		global $wpdb;
		$tbl = BizCity_CG_Flow_Installer::table();
		if ( ! BizCity_CG_Flow_Installer::table_exists( $tbl ) ) {
			return self::ok( array( 'rows' => array(), 'total' => 0, '_table_missing' => true ) );
		}

		$where  = array( '1=1' );
		$params = array();
		$q      = trim( (string) $req->get_param( 'q' ) );
		if ( '' !== $q ) {
			$where[]  = '(message LIKE %s OR message_khong_dau LIKE %s OR shortcode LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $q ) . '%';
			$params[] = $like; $params[] = $like; $params[] = $like;
		}
		$atype = (string) $req->get_param( 'action_type' );
		if ( in_array( $atype, array( 'run_shortcode', 'send_message' ), true ) ) {
			$where[]  = 'action_type = %s';
			$params[] = $atype;
		}
		$limit  = max( 1, min( 500, (int) $req->get_param( 'limit' ) ) );
		$offset = max( 0, (int) $req->get_param( 'offset' ) );
		$where_sql = implode( ' AND ', $where );

		$sql = "SELECT * FROM {$tbl} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
		$params[] = $limit; $params[] = $offset;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$cnt_sql = "SELECT COUNT(*) FROM {$tbl} WHERE {$where_sql}";
		$cnt_params = array_slice( $params, 0, count( $params ) - 2 );
		$total = $cnt_params
			? (int) $wpdb->get_var( $wpdb->prepare( $cnt_sql, $cnt_params ) )
			: (int) $wpdb->get_var( $cnt_sql );

		return self::ok( array(
			'rows'  => array_map( array( __CLASS__, 'format_row' ), $rows ),
			'total' => $total,
			'limit' => $limit,
			'offset'=> $offset,
		) );
	}

	public static function get_row( WP_REST_Request $req ): WP_REST_Response {
		global $wpdb;
		$tbl = BizCity_CG_Flow_Installer::table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tbl} WHERE id=%d", (int) $req['id'] ) );
		if ( ! $row ) { return self::err( 'not_found', 'Flow not found.', 404 ); }
		return self::ok( self::format_row( $row ) );
	}

	public static function create_row( WP_REST_Request $req ): WP_REST_Response {
		global $wpdb;
		$tbl = BizCity_CG_Flow_Installer::table();
		$data = self::sanitize_payload( $req, false );
		if ( '' === trim( $data['message'] ) || '' === trim( $data['shortcode'] ) ) {
			return self::err( 'invalid_params', 'message + shortcode required.', 422 );
		}
		$ok = $wpdb->insert( $tbl, $data );
		if ( false === $ok ) {
			return self::err( 'db_insert_failed', $wpdb->last_error, 500 );
		}
		$id = (int) $wpdb->insert_id;
		wp_cache_delete( "flow_row_{$id}", 'bizcity_crm_flows' );
		return self::ok( array( 'id' => $id ), 201 );
	}

	public static function update_row( WP_REST_Request $req ): WP_REST_Response {
		global $wpdb;
		$tbl = BizCity_CG_Flow_Installer::table();
		$id  = (int) $req['id'];
		$data = self::sanitize_payload( $req, true );
		$ok = $wpdb->update( $tbl, $data, array( 'id' => $id ) );
		if ( false === $ok ) {
			return self::err( 'db_update_failed', $wpdb->last_error, 500 );
		}
		wp_cache_delete( "flow_row_{$id}", 'bizcity_crm_flows' );
		return self::ok( array( 'id' => $id, 'updated' => (int) $ok ) );
	}

	public static function delete_row( WP_REST_Request $req ): WP_REST_Response {
		global $wpdb;
		$tbl = BizCity_CG_Flow_Installer::table();
		$id  = (int) $req['id'];
		$ok  = $wpdb->delete( $tbl, array( 'id' => $id ) );
		if ( false === $ok ) {
			return self::err( 'db_delete_failed', $wpdb->last_error, 500 );
		}
		wp_cache_delete( "flow_row_{$id}", 'bizcity_crm_flows' );
		return self::ok( array( 'id' => $id, 'deleted' => (int) $ok ) );
	}

	public static function get_dropdowns( WP_REST_Request $req ): WP_REST_Response {
		// Prefer the CRM whitelist if loaded — otherwise fall back to local copy.
		$whitelist = class_exists( 'BizCity_CRM_Campaign_Scenario_Dispatcher' )
			&& defined( 'BizCity_CRM_Campaign_Scenario_Dispatcher::SHORTCODE_WHITELIST' )
			? (array) constant( 'BizCity_CRM_Campaign_Scenario_Dispatcher::SHORTCODE_WHITELIST' )
			: self::SHORTCODE_WHITELIST;

		return self::ok( array(
			'shortcodes'    => array_values( $whitelist ),
			'action_types'  => array(
				array( 'value' => 'run_shortcode', 'label' => 'Chạy shortcode' ),
				array( 'value' => 'send_message',  'label' => 'Gửi tin nhắn' ),
			),
			'reply_modes'   => array(
				array( 'value' => 'direct', 'label' => 'Trả lời trực tiếp (raw text)' ),
				array( 'value' => 'llm',    'label' => 'Sinh qua LLM (CSKH prompt)' ),
			),
			'reminder_units'=> array(
				array( 'value' => 'minutes', 'label' => 'phút' ),
				array( 'value' => 'hours',   'label' => 'giờ' ),
				array( 'value' => 'days',    'label' => 'ngày' ),
			),
			'placeholders'  => array(
				'{{client_id}}'   => 'FB PSID khi từ Messenger; contact_id khi từ web',
				'{{client_name}}' => 'Tên khách từ hook_data',
				'{{page_id}}'     => 'FB Page ID',
				'{{campaign_name}}' => 'Tên campaign (chỉ trong scenario dispatch)',
				'{{campaign_code}}' => 'Mã campaign',
			),
		) );
	}

	public static function test_match( WP_REST_Request $req ): WP_REST_Response {
		$text = trim( (string) $req->get_param( 'text' ) );
		if ( '' === $text ) {
			return self::err( 'invalid_params', '`text` required.', 422 );
		}
		$out = BizCity_CG_Flow_Handler::match( $text );
		return self::ok( array(
			'input'   => $text,
			'matched' => ! empty( $out['flow_id'] ),
			'result'  => $out,
		) );
	}

	public static function get_health( WP_REST_Request $req ): WP_REST_Response {
		global $wpdb;
		$tbl     = BizCity_CG_Flow_Installer::table();
		$exists  = BizCity_CG_Flow_Installer::table_exists( $tbl );
		$count   = $exists ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl}" ) : 0;
		$by_type = $exists
			? (array) $wpdb->get_results( "SELECT action_type, COUNT(*) c FROM {$tbl} GROUP BY action_type", ARRAY_A )
			: array();
		$by_mode = $exists
			? (array) $wpdb->get_results( "SELECT reply_mode, COUNT(*) c FROM {$tbl} GROUP BY reply_mode", ARRAY_A )
			: array();
		$legacy_exists = BizCity_CG_Flow_Installer::table_exists( BizCity_CG_Flow_Installer::legacy_table() );
		$legacy_count  = $legacy_exists ? (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . BizCity_CG_Flow_Installer::legacy_table() ) : 0;
		$migrated      = (int) get_option( BizCity_CG_Flow_Installer::OPT_MIGRATED );

		return self::ok( array(
			'table'         => $tbl,
			'table_exists'  => $exists,
			'count'         => $count,
			'by_action_type'=> $by_type,
			'by_reply_mode' => $by_mode,
			'legacy_table'  => BizCity_CG_Flow_Installer::legacy_table(),
			'legacy_exists' => $legacy_exists,
			'legacy_count'  => $legacy_count,
			'migrated'      => $migrated,
			'rest_ns'       => self::NS,
		) );
	}
}
