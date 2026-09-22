<?php
/**
 * BizCity CRM — customer pipeline REST for the leader surface (PHASE-0.52, B2 `/crm/`, `/twin/?plugin=crm`).
 *
 * Namespace bizcity-crm/v1:
 *   GET  /crm-pipeline/board              — L1 Pipeline đội (columns, KPIs, per-staff matrix)   customer-pipeline-board@1.0.0
 *   GET  /crm-pipeline/contacts/{id}      — stage detail for the Inbox toolbar / rail (steps, history, next step)
 *   POST /crm-contacts/{id}/stage         — change stage / tick steps / log an outcome        pipeline-stage-change@1.0.0
 *   GET  /crm-pipeline/segments           — L2 customer sets for playbooks (≤ 200 ids, by owner)
 *   GET  /crm-tasks/load                  — L2 open tasks due per staff per day (7 days)
 *   GET|PUT /crm-settings/pipeline        — stuck days, steps per stage, lost reasons (R-PIPE-8)
 *   GET  /crm-staff/{id}/space            — a member's personal space, read-only for the leader    member-space@1.0.0
 *   PUT  /crm-staff/{id}/goal             — leader sets the member's monthly goal (R-PIPE-7)
 *
 * Member (`/gpt/`) routes live in modules/twinweb and call the same services with the current user only.
 *
 * @package BizCity_Twin_CRM
 * @since   PHASE-0.52 2026-09-18
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Pipeline_REST', false ) ) {
	return;
}

final class BizCity_CRM_Pipeline_REST {

	const SEGMENT_MAX = 200;
	const SEGMENTS = array( 'target_new', 'consult_stuck', 'quote_stuck', 'won_d3', 'dormant', 'repeat_d30', 'stuck', 'no_next' );
	const PIPELINE_NS = 'bizcity-crm/v1';

	public static function register_routes(): void {
		if ( ! class_exists( 'BizCity_CRM_Customer_Pipeline' ) || ! class_exists( 'BizCity_CRM_Staff_Policy' ) ) { return; }
		$ns = BIZCITY_CRM_REST_NS;
		$use = array( __CLASS__, 'can_use_crm' );
		// [2026-09-21 08:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.63A WP-4 — register pipeline definition/run/SLA routes with distinct permission callbacks.
		$read = array( __CLASS__, 'can_read_pipeline' );
		$manage = array( __CLASS__, 'can_manage_pipeline' );
		$write = array( __CLASS__, 'can_write_pipeline' );
		register_rest_route( self::PIPELINE_NS, '/pipelines', array(
			array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'list_definitions' ), 'permission_callback' => $read ),
			array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'save_definition' ), 'permission_callback' => $manage ),
		) );
		register_rest_route( self::PIPELINE_NS, '/pipelines/import', array(
			'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'import_definition' ), 'permission_callback' => $manage,
		) );
		register_rest_route( self::PIPELINE_NS, '/pipelines/templates', array(
			// [2026-09-22 09:30 AM OpenAI GPT-5.6 Luna] PHASE-0.63A WP-5.6 — expose code-owned built-in templates for the manager sheet.
			'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'list_templates' ), 'permission_callback' => $manage,
		) );
		register_rest_route( self::PIPELINE_NS, '/pipelines/(?P<kind>[a-z][a-z0-9_-]{0,31})', array(
			'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'get_definition' ), 'permission_callback' => $read,
		) );
		register_rest_route( self::PIPELINE_NS, '/pipelines/(?P<kind>[a-z][a-z0-9_-]{0,31})/export', array(
			'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'export_definition' ), 'permission_callback' => $manage,
		) );
		register_rest_route( self::PIPELINE_NS, '/pipeline-runs', array(
			array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'list_runs' ), 'permission_callback' => $read ),
			array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'open_run' ), 'permission_callback' => $write ),
		) );
		register_rest_route( self::PIPELINE_NS, '/pipeline-runs/(?P<id>\d+)/transition', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'transition_run' ), 'permission_callback' => $write ) );
		register_rest_route( self::PIPELINE_NS, '/pipeline-runs/(?P<id>\d+)/exceptions', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'transition_exception' ), 'permission_callback' => $write ) );
		register_rest_route( self::PIPELINE_NS, '/pipeline-runs/(?P<id>\d+)/sla', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'get_run_sla' ), 'permission_callback' => $read ) );
		register_rest_route( $ns, '/crm-pipeline/board', array(
			'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'get_board' ), 'permission_callback' => $use,
			'args' => array(
				'team_id' => array( 'type' => 'integer' ), 'owner_id' => array( 'type' => 'integer' ),
				'source' => array( 'type' => 'string' ), 'range' => array( 'type' => 'string', 'default' => '30d', 'enum' => array( '7d', '30d', '90d' ) ),
				'sample' => array( 'type' => 'integer', 'default' => 20 ),
			),
		) );
		register_rest_route( $ns, '/crm-pipeline/contacts/(?P<id>\d+)', array(
			'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'get_contact' ), 'permission_callback' => $use,
		) );
		register_rest_route( $ns, '/crm-contacts/(?P<id>\d+)/stage', array(
			'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'post_stage' ), 'permission_callback' => $use,
		) );
		register_rest_route( $ns, '/crm-pipeline/segments', array(
			'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'get_segment' ), 'permission_callback' => $use,
			'args' => array( 'segment' => array( 'type' => 'string', 'enum' => self::SEGMENTS ), 'owner_id' => array( 'type' => 'integer' ), 'team_id' => array( 'type' => 'integer' ) ),
		) );
		register_rest_route( $ns, '/crm-tasks/load', array(
			'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'get_task_load' ), 'permission_callback' => $use,
			'args' => array( 'days' => array( 'type' => 'integer', 'default' => 7 ), 'team_id' => array( 'type' => 'integer' ) ),
		) );
		register_rest_route( $ns, '/crm-settings/pipeline', array(
			array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'get_settings' ), 'permission_callback' => $use ),
			array( 'methods' => 'PUT', 'callback' => array( __CLASS__, 'put_settings' ), 'permission_callback' => $use ),
		) );
		register_rest_route( $ns, '/crm-staff/(?P<id>\d+)/space', array(
			'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'get_space' ), 'permission_callback' => $use,
		) );
		register_rest_route( $ns, '/crm-staff/(?P<id>\d+)/goal', array(
			'methods' => 'PUT', 'callback' => array( __CLASS__, 'put_goal' ), 'permission_callback' => $use,
		) );
	}

	public static function can_read_pipeline(): bool {
		// [2026-09-21 08:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.63A WP-4 — read permission delegates to the canonical CRM boundary.
		return self::can_use_crm();
	}

	public static function can_manage_pipeline(): bool {
		// [2026-09-21 08:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.63A WP-4 — definition writes require the canonical rules/admin boundary.
		return current_user_can( 'bizcity_crm_manage_rules' ) || current_user_can( 'manage_options' ) || current_user_can( 'manage_network' );
	}

	public static function can_write_pipeline(): bool {
		// [2026-09-21 08:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.63A WP-4 — run mutations use CRM staff write authority.
		return self::can_use_crm();
	}

	public static function list_definitions( WP_REST_Request $request ) {
		// [2026-09-21 08:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.63A WP-4 — expose validated definitions only.
		return self::ok( array( 'items' => class_exists( 'BizCity_CRM_Pipeline_Registry' ) ? array_values( BizCity_CRM_Pipeline_Registry::all() ) : array() ) );
	}

	public static function get_definition( WP_REST_Request $request ) {
		// [2026-09-21 08:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.63A WP-4 — return one validated definition by kind.
		$kind = sanitize_key( (string) $request['kind'] );
		$definition = class_exists( 'BizCity_CRM_Pipeline_Registry' ) ? BizCity_CRM_Pipeline_Registry::get( $kind ) : null;
		return is_array( $definition ) ? self::ok( array( 'definition' => $definition ) ) : self::error( 'pipeline_not_found', 'Không tìm thấy định nghĩa pipeline.', 404, 'Chọn một pipeline đang được bật.' );
	}

	public static function save_definition( WP_REST_Request $request ) {
		// [2026-09-21 08:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.63A WP-4 — validate and version definition before persistence.
		$body = self::body( $request );
		$definition = isset( $body['definition'] ) && is_array( $body['definition'] ) ? $body['definition'] : $body;
		$kind = sanitize_key( (string) ( $definition['kind'] ?? '' ) );
		if ( '' === $kind || ! class_exists( 'BizCity_CRM_Pipeline_Registry' ) ) { return self::error( 'invalid_param', 'Định nghĩa pipeline thiếu mã kind.', 422, 'Nhập kind hợp lệ trước khi lưu.' ); }
		$result = BizCity_CRM_Pipeline_Registry::save( $kind, $definition );
		return is_wp_error( $result ) ? self::error_from( $result ) : self::ok( array( 'post_id' => (int) $result, 'kind' => $kind, 'version' => BizCity_CRM_Pipeline_Registry::current_version( $kind ) ) );
	}

	public static function import_definition( WP_REST_Request $request ) {
		// [2026-09-21 08:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.63A WP-4 — import only through the registry validator.
		$body = self::body( $request );
		$definition = isset( $body['definition'] ) && is_array( $body['definition'] ) ? $body['definition'] : $body;
		if ( ! class_exists( 'BizCity_CRM_Pipeline_Registry' ) ) { return self::error( 'module_not_loaded', 'Registry pipeline chưa sẵn sàng.', 503, 'Tải lại CRM rồi thử lại.' ); }
		$result = BizCity_CRM_Pipeline_Registry::import_definition( $definition, ! empty( $body['overwrite'] ) );
		return is_wp_error( $result ) ? self::error_from( $result ) : self::ok( array( 'post_id' => (int) $result ) );
	}

	public static function list_templates( WP_REST_Request $request ) {
		// [2026-09-22 09:30 AM OpenAI GPT-5.6 Luna] PHASE-0.63A WP-5.6 — templates remain code-owned; import_definition() performs validation and CPT persistence.
		if ( ! class_exists( 'BizCity_CRM_Pipeline_Registry' ) ) {
			return self::error( 'module_not_loaded', 'Registry pipeline chưa sẵn sàng.', 503, 'Tải lại CRM rồi thử lại.' );
		}
		$items = array();
		foreach ( BizCity_CRM_Pipeline_Registry::templates() as $name ) {
			$definition = BizCity_CRM_Pipeline_Registry::template( $name );
			if ( ! is_array( $definition ) ) { continue; }
			$items[] = array(
				'name'       => (string) $name,
				'label'      => (string) ( $definition['label'] ?? $name ),
				'definition' => $definition,
			);
		}
		return self::ok( array( 'items' => $items ) );
	}

	public static function export_definition( WP_REST_Request $request ) {
		// [2026-09-21 08:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.63A WP-4 — export the server-owned definition.
		$definition = class_exists( 'BizCity_CRM_Pipeline_Registry' ) ? BizCity_CRM_Pipeline_Registry::get( sanitize_key( (string) $request['kind'] ) ) : null;
		return is_array( $definition ) ? self::ok( array( 'definition' => $definition ) ) : self::error( 'pipeline_not_found', 'Không tìm thấy định nghĩa pipeline.', 404, 'Chọn một pipeline đang được bật.' );
	}

	public static function list_runs( WP_REST_Request $request ) {
		// [2026-09-21 08:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.63A WP-4 — enforce contact scope before returning pipeline runs.
		$contact_id = (int) $request->get_param( 'contact_id' );
		if ( $contact_id <= 0 || ! BizCity_CRM_Customer_Pipeline::contact_in_scope( $contact_id, BizCity_CRM_Customer_Pipeline::b2_inbox_ids( (int) get_current_user_id() ) ) ) { return self::error( 'contact_not_in_scope', 'Không tìm thấy khách trong phạm vi của bạn.', 404, 'Chọn một contact trong Inbox của bạn.' ); }
		return self::ok( array( 'items' => class_exists( 'BizCity_CRM_Pipeline_Run_Service' ) ? BizCity_CRM_Pipeline_Run_Service::runs_for_contact( $contact_id ) : array() ) );
	}

	public static function open_run( WP_REST_Request $request ) {
		// [2026-09-21 08:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.63A WP-4 — enforce contact scope before opening a run.
		$body = self::body( $request );
		$contact_id = (int) ( $body['contact_id'] ?? 0 );
		if ( $contact_id <= 0 || ! BizCity_CRM_Customer_Pipeline::contact_in_scope( $contact_id, BizCity_CRM_Customer_Pipeline::b2_inbox_ids( (int) get_current_user_id() ) ) ) { return self::error( 'contact_not_in_scope', 'Không tìm thấy khách trong phạm vi của bạn.', 404, 'Chọn một contact trong Inbox của bạn.' ); }
		$result = BizCity_CRM_Pipeline_Run_Service::open_run( $contact_id, sanitize_key( (string) ( $body['kind'] ?? '' ) ), $body );
		return is_wp_error( $result ) ? self::error_from( $result ) : self::ok( array( 'run_id' => (int) $result ) );
	}

	public static function transition_run( WP_REST_Request $request ) {
		// [2026-09-21 09:30 PM OpenAI GPT-5.6 Luna] PHASE-0.63A WP-4 — route run transitions through the scoped service boundary.
		$run = self::scoped_run( (int) $request['id'] );
		if ( is_wp_error( $run ) ) {
			return self::error_from( $run );
		}
		$body = self::body( $request );
		$action = sanitize_key( (string) ( $body['action'] ?? '' ) );
		$stage_key = sanitize_text_field( (string) ( $body['stage_key'] ?? '' ) );
		$methods = array(
			'start'    => 'start_stage',
			'complete' => 'complete_stage',
			'block'   => 'block_stage',
			'reopen'  => 'reopen_stage',
		);
		if ( ! isset( $methods[ $action ] ) || '' === $stage_key ) {
			return self::error( 'invalid_param', 'Thao tác chuyển bước không hợp lệ.', 422, 'Chọn hành động và bước hợp lệ.' );
		}
		$body['actor_id'] = isset( $body['actor_id'] ) ? (int) $body['actor_id'] : (int) get_current_user_id();
		$method = $methods[ $action ];
		$result = BizCity_CRM_Pipeline_Run_Service::$method( (int) $run['id'], $stage_key, $body );
		return is_wp_error( $result ) ? self::error_from( $result ) : self::ok( array( 'run' => $result ) );
	}

	public static function transition_exception( WP_REST_Request $request ) {
		// [2026-09-21 09:30 PM OpenAI GPT-5.6 Luna] PHASE-0.63A WP-4 — keep exception mutations scoped to the run's contact.
		$run = self::scoped_run( (int) $request['id'] );
		if ( is_wp_error( $run ) ) {
			return self::error_from( $run );
		}
		$body = self::body( $request );
		$action = sanitize_key( (string) ( $body['action'] ?? '' ) );
		$exception_key = sanitize_text_field( (string) ( $body['exception_key'] ?? '' ) );
		$methods = array(
			'open'    => 'raise_exception',
			'ack'     => 'ack_exception',
			'resolve' => 'resolve_exception',
		);
		if ( ! isset( $methods[ $action ] ) || '' === $exception_key ) {
			return self::error( 'invalid_param', 'Thao tác ngoại lệ không hợp lệ.', 422, 'Chọn hành động và loại ngoại lệ hợp lệ.' );
		}
		$body['actor_id'] = isset( $body['actor_id'] ) ? (int) $body['actor_id'] : (int) get_current_user_id();
		$method = $methods[ $action ];
		$result = BizCity_CRM_Pipeline_Run_Service::$method( (int) $run['id'], $exception_key, $body );
		return is_wp_error( $result ) ? self::error_from( $result ) : self::ok( array( 'run' => $result ) );
	}

	public static function get_run_sla( WP_REST_Request $request ) {
		// [2026-09-21 09:30 PM OpenAI GPT-5.6 Luna] PHASE-0.63A WP-4 — expose only SLA state for an in-scope run.
		$run = self::scoped_run( (int) $request['id'] );
		if ( is_wp_error( $run ) ) {
			return self::error_from( $run );
		}
		if ( ! class_exists( 'BizCity_CRM_Pipeline_SLA_Service' ) ) {
			return self::error( 'module_not_loaded', 'SLA service chưa sẵn sàng.', 503, 'Tải lại CRM rồi thử lại.' );
		}
		$result = BizCity_CRM_Pipeline_SLA_Service::state_for_run( (int) $run['id'] );
		return is_wp_error( $result ) ? self::error_from( $result ) : self::ok( $result );
	}

	private static function scoped_run( int $run_id ) {
		if ( $run_id <= 0 || ! class_exists( 'BizCity_CRM_Pipeline_Run_Service' ) ) {
			return new WP_Error( 'run_not_found', 'Không tìm thấy pipeline đang chạy.', array( 'status' => 404, 'hint' => 'Tải lại pipeline rồi thử lại.', 'help_code' => 'pipeline_run_not_found' ) );
		}
		$run = BizCity_CRM_Pipeline_Run_Service::get_run( $run_id );
		if ( is_wp_error( $run ) ) {
			return $run;
		}
		$contact_id = (int) ( $run['contact_id'] ?? 0 );
		$inboxes = BizCity_CRM_Customer_Pipeline::b2_inbox_ids( (int) get_current_user_id() );
		if ( $contact_id <= 0 || ! BizCity_CRM_Customer_Pipeline::contact_in_scope( $contact_id, $inboxes ) ) {
			return new WP_Error( 'run_not_found', 'Không tìm thấy pipeline đang chạy.', array( 'status' => 404, 'hint' => 'Chọn một pipeline trong Inbox của bạn.', 'help_code' => 'pipeline_run_not_found' ) );
		}
		return $run;
	}

	public static function can_use_crm(): bool {
		return class_exists( 'BizCity_CRM_Staff_REST' ) ? BizCity_CRM_Staff_REST::can_use_crm() : is_user_logged_in();
	}

	// ── L1 board ─────────────────────────────────────────────────────────

	public static function get_board( WP_REST_Request $req ) {
		$actor = (int) get_current_user_id();
		$owner = (int) $req->get_param( 'owner_id' );
		$team  = (int) $req->get_param( 'team_id' );
		if ( $owner > 0 && $owner !== $actor ) {
			$decision = BizCity_CRM_Staff_Policy::can( $actor, 'contact.view_by_owner', $owner );
			if ( ! $decision['ok'] ) { return BizCity_CRM_Staff_Policy::denied_response( $decision ); }
		}
		$range = sanitize_key( (string) $req->get_param( 'range' ) );
		$days  = '7d' === $range ? 7 : ( '90d' === $range ? 90 : 30 );
		$source = sanitize_key( (string) $req->get_param( 'source' ) );
		$sample = (int) $req->get_param( 'sample' );
		$key = 'bzc_pipe_board_' . md5( get_current_blog_id() . '|' . (int) get_option( 'bizcity_crm_pipeline_cache_ver', 1 ) . '|' . $actor . '|' . $owner . '|' . $team . '|' . $days . '|' . $source . '|' . $sample );
		$payload = get_transient( $key );
		if ( ! is_array( $payload ) ) {
			$rows = BizCity_CRM_Customer_Pipeline::rows( BizCity_CRM_Customer_Pipeline::contact_ids_for_inboxes( BizCity_CRM_Customer_Pipeline::b2_inbox_ids( $actor ) ) );
			$owner_ids = null;
			if ( $owner > 0 ) { $owner_ids = array( $owner ); }
			elseif ( $team > 0 ) { $owner_ids = self::team_user_ids( $actor, $team ); }
			$board = BizCity_CRM_Customer_Pipeline::board( $rows, array( 'owner_ids' => $owner_ids, 'source' => $source, 'with_owner' => true, 'sample' => $sample ?: 20, 'range_days' => $days ) );
			$board['matrix'] = self::decorate_matrix( (array) $board['matrix'] );
			$payload = array_merge( array(
				'ok' => true, 'contract' => 'customer-pipeline-board', 'version' => '1.0.0', 'surface' => 'B2_ADMIN_CRM',
				'as_of' => current_time( 'c' ), 'range' => array( 'key' => $days . 'd', 'days' => $days ),
			), $board );
			set_transient( $key, $payload, BizCity_CRM_Customer_Pipeline::CACHE_TTL );
		}
		return new WP_REST_Response( $payload, 200 );
	}

	/** Add team names; hide staff the actor may not see by name (R-CRMF-8) under "Nhân viên khác". */
	private static function decorate_matrix( array $matrix ): array {
		$actor = (int) get_current_user_id();
		$visible = BizCity_CRM_Staff_Policy::visible_user_ids( $actor );
		$visible = null === $visible ? null : array_flip( array_map( 'intval', $visible ) );
		$out = array();
		$other = null;
		foreach ( $matrix as $m ) {
			$uid = (int) ( $m['user_id'] ?? 0 );
			if ( $uid > 0 && ( null === $visible || isset( $visible[ $uid ] ) ) ) {
				$team_id = BizCity_CRM_Staff_Policy::primary_team( $uid );
				$m['team_id'] = $team_id;
				$m['role'] = BizCity_CRM_Staff_Policy::role( $uid );
				$out[] = $m;
				continue;
			}
			if ( $uid <= 0 ) { $out[] = $m; continue; }
			if ( null === $other ) { $other = $m; $other['user_id'] = null; $other['display_name'] = 'Nhân viên khác'; continue; }
			foreach ( $m['counts'] as $k => $v ) { $other['counts'][ $k ] += $v; }
			foreach ( array( 'stuck', 'no_next', 'overdue_tasks', 'open', 'touched7', 'touched', 'ordered', 'risk' ) as $k ) { $other[ $k ] += $m[ $k ]; }
		}
		if ( null !== $other ) { $other['conversion'] = null; $other['touch7_rate'] = null; $out[] = $other; }
		return $out;
	}

	private static function team_user_ids( int $actor, int $team ): array {
		$visible = BizCity_CRM_Staff_Policy::visible_user_ids( $actor );
		$ids = array();
		foreach ( class_exists( 'BizCity_CRM_Team_Manager' ) ? BizCity_CRM_Team_Manager::list_team_members( $team ) : array() as $m ) {
			$uid = (int) ( $m['user_id'] ?? 0 );
			if ( $uid > 0 && ( null === $visible || in_array( $uid, array_map( 'intval', $visible ), true ) ) ) { $ids[] = $uid; }
		}
		return $ids;
	}

	// ── Inbox toolbar ────────────────────────────────────────────────────

	public static function get_contact( WP_REST_Request $req ) {
		$actor = (int) get_current_user_id();
		$contact_id = (int) $req['id'];
		if ( ! BizCity_CRM_Customer_Pipeline::contact_in_scope( $contact_id, BizCity_CRM_Customer_Pipeline::b2_inbox_ids( $actor ) ) ) {
			return self::error( 'contact_not_in_scope', 'Không tìm thấy khách trong phạm vi của bạn.', 404 );
		}
		$detail = BizCity_CRM_Customer_Pipeline::detail( $contact_id, true );
		if ( ! $detail ) { return self::error( 'contact_not_in_scope', 'Không tìm thấy khách.', 404 ); }
		// [2026-09-21 08:30 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.63A WP-5.2 — server resolves Context Apps; the FE only renders the ordered catalog.
		if ( class_exists( 'BizCity_CRM_Context_Resolver' ) ) {
			$context_apps = BizCity_CRM_Context_Resolver::for_conversation( array(
				'surface' => 'b2',
				'subject_roles' => array( 'customer' ),
				'pipeline_kind' => (string) ( $detail['pipeline_kind'] ?? '' ),
				'channel' => sanitize_key( (string) ( $detail['channel_type'] ?? '' ) ),
				'limit' => 6,
			) );
			$detail['context_apps'] = isset( $context_apps['visible'] ) && is_array( $context_apps['visible'] ) ? $context_apps['visible'] : array();
		}
		return new WP_REST_Response( array_merge( array( 'ok' => true, 'surface' => 'B2_ADMIN_CRM', 'as_of' => current_time( 'c' ) ), $detail ), 200 );
	}

	public static function post_stage( WP_REST_Request $req ) {
		$result = BizCity_CRM_Pipeline_Stage_Service::change( (int) get_current_user_id(), (int) $req['id'], self::body( $req ), 'b2' );
		if ( is_wp_error( $result ) ) { return self::error_from( $result ); }
		self::bust_board_cache();
		return new WP_REST_Response( array_merge( array( 'ok' => true, 'contract' => 'pipeline-stage-change', 'version' => '1.0.0' ), $result ), 200 );
	}

	// ── L2 planner ───────────────────────────────────────────────────────

	public static function get_segment( WP_REST_Request $req ) {
		$actor = (int) get_current_user_id();
		$segment = sanitize_key( (string) $req->get_param( 'segment' ) );
		if ( ! in_array( $segment, self::SEGMENTS, true ) ) { return self::error( 'invalid_segment', 'Tệp khách không hợp lệ.', 422 ); }
		$owner = (int) $req->get_param( 'owner_id' );
		$team = (int) $req->get_param( 'team_id' );
		if ( $owner > 0 && $owner !== $actor ) {
			$decision = BizCity_CRM_Staff_Policy::can( $actor, 'contact.view_by_owner', $owner );
			if ( ! $decision['ok'] ) { return BizCity_CRM_Staff_Policy::denied_response( $decision ); }
		}
		$owner_ids = $owner > 0 ? array( $owner ) : ( $team > 0 ? self::team_user_ids( $actor, $team ) : null );
		$rows = BizCity_CRM_Customer_Pipeline::rows( BizCity_CRM_Customer_Pipeline::contact_ids_for_inboxes( BizCity_CRM_Customer_Pipeline::b2_inbox_ids( $actor ) ) );
		$match = array();
		foreach ( $rows as $r ) {
			if ( null !== $owner_ids && ! in_array( (int) $r['owner_id'], $owner_ids, true ) ) { continue; }
			if ( self::in_segment( $segment, $r ) ) { $match[] = $r; }
		}
		usort( $match, static function ( $a, $b ) { return $b['days'] <=> $a['days']; } );
		// Grouped by current owner so the planner can give each owner their own customers (R-PIPE-6 default).
		$by_owner = array();
		$taken = 0;
		foreach ( $match as $r ) {
			$oid = (int) $r['owner_id'];
			if ( ! isset( $by_owner[ $oid ] ) ) { $u = $oid > 0 ? get_userdata( $oid ) : null; $by_owner[ $oid ] = array( 'user_id' => $oid ?: null, 'display_name' => $u ? (string) $u->display_name : 'Chưa phân công', 'count' => 0, 'contact_ids' => array() ); }
			$by_owner[ $oid ]['count']++;
			if ( $taken < self::SEGMENT_MAX ) { $by_owner[ $oid ]['contact_ids'][] = (int) $r['contact_id']; $taken++; }
		}
		$ids = array_map( static function ( $r ) { return (int) $r['contact_id']; }, array_slice( $match, 0, self::SEGMENT_MAX ) );
		return new WP_REST_Response( array(
			'ok' => true, 'segment' => $segment, 'count' => count( $match ), 'capped' => count( $match ) > self::SEGMENT_MAX,
			'contact_ids' => $ids, 'by_owner' => array_values( $by_owner ), 'as_of' => current_time( 'c' ),
		), 200 );
	}

	public static function in_segment( string $segment, array $r ): bool {
		$st = (string) $r['stage'];
		switch ( $segment ) {
			case 'target_new':    return 'target' === $st && $r['days'] >= 1;
			case 'consult_stuck': return 'consult' === $st && $r['stuck'];
			case 'quote_stuck':   return 'quote' === $st && $r['stuck'];
			case 'won_d3':        return 'won' === $st && $r['days'] >= 3 && $r['days'] <= 4;
			case 'dormant':       return 'dormant' === $st;
			case 'repeat_d30':    return 'repeat' === $st && $r['days'] >= 30;
			case 'stuck':         return (bool) $r['stuck'];
			case 'no_next':       return ! $r['has_next'] && in_array( $st, BizCity_CRM_Customer_Pipeline::OPEN_STAGES, true );
		}
		return false;
	}

	public static function get_task_load( WP_REST_Request $req ) {
		global $wpdb;
		$actor = (int) get_current_user_id();
		$days = max( 1, min( 14, (int) $req->get_param( 'days' ) ) );
		$team = (int) $req->get_param( 'team_id' );
		$users = $team > 0 ? self::team_user_ids( $actor, $team ) : BizCity_CRM_Staff_Policy::visible_user_ids( $actor );
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_tasks();
		$today = current_time( 'Y-m-d' );
		$end = gmdate( 'Y-m-d', strtotime( $today . ' +' . ( $days - 1 ) . ' days' ) );
		$where = "deleted_at IS NULL AND completed = 0 AND assignee_id IS NOT NULL AND status NOT IN ('done','cancelled','returned') AND due_date <= %s";
		$params = array( $end );
		if ( is_array( $users ) ) {
			$users = array_values( array_filter( array_map( 'intval', $users ) ) );
			if ( empty( $users ) ) { return new WP_REST_Response( array( 'ok' => true, 'days' => array(), 'staff' => array() ), 200 ); }
			$where .= ' AND assignee_id IN (' . implode( ',', array_fill( 0, count( $users ), '%d' ) ) . ')';
			$params = array_merge( $params, $users );
		}
		$load = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT assignee_id, GREATEST(due_date, %s) AS d, COUNT(*) AS n FROM `{$tbl}` WHERE {$where} AND due_date IS NOT NULL GROUP BY assignee_id, d", array_merge( array( $today ), $params ) ), ARRAY_A ) as $r ) {
			$load[ (int) $r['assignee_id'] ][ (string) $r['d'] ] = (int) $r['n'];
		}
		$dates = array();
		for ( $i = 0; $i < $days; $i++ ) { $dates[] = gmdate( 'Y-m-d', strtotime( $today . " +{$i} days" ) ); }
		$staff = array();
		foreach ( $load as $uid => $by_day ) {
			$u = get_userdata( $uid );
			$staff[] = array( 'user_id' => $uid, 'display_name' => $u ? (string) $u->display_name : '#' . $uid, 'per_day' => array_map( static function ( $d ) use ( $by_day ) { return (int) ( $by_day[ $d ] ?? 0 ); }, $dates ) );
		}
		usort( $staff, static function ( $a, $b ) { return max( $b['per_day'] ) <=> max( $a['per_day'] ); } );
		return new WP_REST_Response( array( 'ok' => true, 'days' => $dates, 'staff' => $staff, 'thresholds' => array( 'warn' => 20, 'crit' => 30 ) ), 200 );
	}

	// ── Settings ─────────────────────────────────────────────────────────

	public static function get_settings( WP_REST_Request $req ) {
		return new WP_REST_Response( array( 'ok' => true, 'settings' => BizCity_CRM_Customer_Pipeline::settings(), 'defaults' => BizCity_CRM_Customer_Pipeline::default_settings(), 'can_edit' => self::can_edit_settings() ), 200 );
	}

	public static function put_settings( WP_REST_Request $req ) {
		if ( ! self::can_edit_settings() ) { return self::error( 'rank_insufficient', 'Chỉ quản trị viên hoặc giám sát được sửa cấu hình pipeline.', 403 ); }
		$clean = BizCity_CRM_Customer_Pipeline::sanitize_settings( self::body( $req ) );
		update_option( BizCity_CRM_Customer_Pipeline::OPTION, $clean, false );
		if ( class_exists( 'BizCity_CRM_Audit_Log' ) ) { BizCity_CRM_Audit_Log::log( 'crm_settings', 0, 'updated', null, array( 'pipeline' => $clean ), array( 'user_id' => get_current_user_id() ) ); }
		self::bust_board_cache();
		return new WP_REST_Response( array( 'ok' => true, 'settings' => $clean ), 200 );
	}

	private static function can_edit_settings(): bool {
		$uid = (int) get_current_user_id();
		return current_user_can( 'manage_options' ) || BizCity_CRM_Staff_Policy::rank( BizCity_CRM_Staff_Policy::role( $uid ) ) >= 3;
	}

	// ── Personal space (read-only for the leader) + goal ─────────────────

	public static function get_space( WP_REST_Request $req ) {
		$actor = (int) get_current_user_id();
		$subject = (int) $req['id'];
		if ( $subject !== $actor ) {
			$decision = BizCity_CRM_Staff_Policy::can( $actor, 'staff.view_workspace', $subject );
			if ( ! $decision['ok'] ) { return BizCity_CRM_Staff_Policy::denied_response( $decision ); }
			if ( method_exists( 'BizCity_CRM_Staff_REST', 'record_workspace_read' ) ) { BizCity_CRM_Staff_REST::record_workspace_read( $actor, $subject, 'space' ); }
		}
		$user = get_userdata( $subject );
		if ( ! $user ) { return self::error( 'not_found', 'Không tìm thấy nhân viên.', 404 ); }
		return new WP_REST_Response( array_merge( array(
			'ok' => true, 'contract' => 'member-space', 'version' => '1.0.0', 'surface' => 'B2_ADMIN_CRM', 'read_only' => $subject !== $actor,
			'subject' => array( 'user_id' => $subject, 'display_name' => (string) $user->display_name ),
			'can_set_goal' => $subject !== $actor && BizCity_CRM_Staff_Policy::can( $actor, 'staff.set_goal', $subject )['ok'],
		), BizCity_CRM_Customer_Pipeline::space( $subject ) ), 200 );
	}

	public static function put_goal( WP_REST_Request $req ) {
		$actor = (int) get_current_user_id();
		$subject = (int) $req['id'];
		$decision = BizCity_CRM_Staff_Policy::can( $actor, 'staff.set_goal', $subject );
		if ( ! $decision['ok'] ) { return BizCity_CRM_Staff_Policy::denied_response( $decision ); }
		$body = self::body( $req );
		$month = preg_match( '/^\d{4}-\d{2}$/', (string) ( $body['month'] ?? '' ) ) ? (string) $body['month'] : '';
		$goal = BizCity_CRM_Customer_Pipeline::set_goal( $subject, (int) ( $body['won_target'] ?? 0 ), $actor, $month );
		return new WP_REST_Response( array( 'ok' => true, 'goal' => $goal ), 200 );
	}

	// ── helpers ──────────────────────────────────────────────────────────

	/** Boards are cached 60 s per actor+filters; a pipeline write bumps the version so the next read recomputes. */
	public static function bust_board_cache(): void {
		update_option( 'bizcity_crm_pipeline_cache_ver', (int) get_option( 'bizcity_crm_pipeline_cache_ver', 1 ) + 1, false );
	}

	private static function body( WP_REST_Request $req ): array {
		$body = $req->get_json_params();
		return is_array( $body ) ? $body : (array) $req->get_body_params();
	}

	private static function ok( array $data ): WP_REST_Response {
		return new WP_REST_Response( array_merge( array( 'ok' => true, 'contract' => 'pipeline-platform', 'version' => '1.0.0' ), $data ), 200 );
	}

	private static function error_from( WP_Error $error ): WP_REST_Response {
		$data = (array) $error->get_error_data();
		return self::error( (string) $error->get_error_code(), $error->get_error_message(), (int) ( $data['status'] ?? 400 ), (string) ( $data['hint'] ?? '' ) );
	}

	private static function error( string $code, string $message, int $status, string $hint = '' ): WP_REST_Response {
		return new WP_REST_Response( array( 'ok' => false, 'code' => $code, 'message' => $message, 'hint' => $hint, 'help_code' => $code ), $status );
	}
}
