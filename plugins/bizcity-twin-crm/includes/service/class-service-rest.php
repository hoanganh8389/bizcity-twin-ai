<?php
/**
 * BizCity CRM — service dispatch REST (PHASE-0.69 WP-S/WP-M/WP-L-05b). Namespace `bizcity-crm/v1`:
 *
 *   GET|PUT /crm-staff/{id}/profile            — routing profile (skills/areas/roster/capacity), WP-S
 *   POST    /crm-staff/{id}/request-location    — dispatcher asks one staff member to share GPS, WP-L-05b
 *   POST    /service/match                      — candidate staff for a booking, WP-M (B1–B8)
 *   POST    /service/runs/{id}/assign            — assign a ca to a staff member, blocks time_conflict, WP-M M-02
 *   POST    /service/runs/{id}/checkin           — check-in with fresh coords, advisory distance flag, WP-L L-06
 *   POST    /service/location/{token}            — PUBLIC, no auth: the landing page's GPS submit, WP-L-05c
 *
 * @package BizCity_Twin_CRM
 * @since 2026-09-23 (PHASE-0.69)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Service_REST', false ) ) {
	return;
}

final class BizCity_CRM_Service_REST {

	const NS = 'bizcity-crm/v1';

	public static function register_routes(): void {
		if ( ! class_exists( 'BizCity_CRM_Staff_Profile' ) ) { return; }
		$use = array( __CLASS__, 'can_use_crm' );
		$manage = array( __CLASS__, 'can_manage_staff' );
		register_rest_route( self::NS, '/crm-staff/(?P<id>\d+)/profile', array(
			array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'get_profile' ), 'permission_callback' => $use ),
			array( 'methods' => 'PUT', 'callback' => array( __CLASS__, 'put_profile' ), 'permission_callback' => $manage ),
		) );
		register_rest_route( self::NS, '/crm-staff/(?P<id>\d+)/request-location', array(
			'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'request_location' ), 'permission_callback' => $manage,
		) );
		register_rest_route( self::NS, '/crm-staff/(?P<id>\d+)/location', array(
			// [2026-09-23 PHASE-0.69 D69-6] "Được vào BE, từ leader, admin, superadmin, là được xem" — a
			// colleague's own position is not a `can_use_crm()`-level read; gate same as `request_location`.
			'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'get_location' ), 'permission_callback' => $manage,
		) );
		register_rest_route( self::NS, '/service/match', array(
			'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'match' ), 'permission_callback' => $use,
		) );
		register_rest_route( self::NS, '/service/runs/(?P<id>\d+)/assign', array(
			'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'assign' ), 'permission_callback' => $use,
		) );
		register_rest_route( self::NS, '/service/runs/(?P<id>\d+)/checkin', array(
			'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'checkin' ), 'permission_callback' => $use,
		) );
		register_rest_route( self::NS, '/service/location/(?P<token>[A-Za-z0-9_.=-]+)', array(
			// [2026-09-23 PHASE-0.69 L-05c] Deliberately public — no WP session exists on this landing page
			// (0.69 §4.4b: "không cần đăng nhập"). The authorization IS possession of the single-use,
			// 10-minute magic-link token; `consume_location()` verifies it exactly like `class-magic-link-
			// handler.php` does for the login flow, just without binding a wp_user_id. Same shape as any
			// bearer-token webhook endpoint elsewhere in this codebase — not the `__return_true`-then-
			// check-inside anti-pattern the framework rules warn about for INTERNAL routes.
			'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'consume_location' ), 'permission_callback' => '__return_true',
		) );
	}

	public static function can_use_crm(): bool {
		return class_exists( 'BizCity_CRM_Staff_REST' ) ? BizCity_CRM_Staff_REST::can_use_crm() : is_user_logged_in();
	}

	/**
	 * Manager-level gate for the ROUTE (any CRM leader/lead/supervisor, or a site admin) — the per-subject
	 * decision (which staff id this actor may act on) is `BizCity_CRM_Staff_Policy::can()` inside each
	 * handler, same split as `crm-staff/{id}/goal` uses. This must NOT fall back to `can_use_crm()` — an
	 * ordinary staff member is a valid CRM user but must not manage a colleague's routing profile or push
	 * a Zalo Bot link on the dispatcher's behalf.
	 */
	public static function can_manage_staff(): bool {
		if ( current_user_can( 'bizcity_crm_manage_rules' ) || current_user_can( 'manage_options' ) || current_user_can( 'manage_network' ) ) {
			return true;
		}
		if ( ! class_exists( 'BizCity_CRM_Staff_Policy' ) ) { return false; }
		$role = BizCity_CRM_Staff_Policy::role( (int) get_current_user_id() );
		return in_array( $role, array( 'lead', 'supervisor' ), true );
	}

	public static function get_profile( WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( $id <= 0 ) { return self::error( 'staff_not_found', 'Không tìm thấy nhân viên.', 404 ); }
		return self::ok( array( 'user_id' => $id, 'profile' => BizCity_CRM_Staff_Profile::get( $id ) ) );
	}

	public static function put_profile( WP_REST_Request $request ) {
		$id = (int) $request['id'];
		$actor = (int) get_current_user_id();
		if ( class_exists( 'BizCity_CRM_Staff_Policy' ) && $actor !== $id ) {
			$decision = BizCity_CRM_Staff_Policy::can( $actor, 'staff.update_role', $id );
			if ( ! $decision['ok'] ) { return BizCity_CRM_Staff_Policy::denied_response( $decision ); }
		} elseif ( $actor === $id ) {
			// 0.69 §5.3 S-02 — self may view; editing one's own routing profile (skills especially) is a
			// manager decision, not self-service (same reasoning as `SELF_FORBIDDEN` gating `staff.update_role`).
			return self::error( 'self_edit_forbidden', 'Không thể tự sửa hồ sơ định tuyến của chính bạn — cần trưởng nhóm.', 403 );
		}
		$body = self::body( $request );
		$result = BizCity_CRM_Staff_Profile::save( $id, is_array( $body['profile'] ?? null ) ? $body['profile'] : $body );
		return is_wp_error( $result ) ? self::error_from( $result ) : self::ok( array( 'user_id' => $id, 'profile' => BizCity_CRM_Staff_Profile::get( $id ) ) );
	}

	/**
	 * Issue + push a location magic-link (0.69 §4.4b — "cách ưu tiên"). `chat_id`/`platform`/`bot_id` come
	 * from the STAFF's own Zalo Bot binding (`zalo_bot_target_for_user`) — never from the conversation the
	 * dispatcher happened to be looking at, so the link can only ever reach the actual staff member.
	 */
	public static function request_location( WP_REST_Request $request ) {
		$staff_id = (int) $request['id'];
		if ( $staff_id <= 0 || ! function_exists( 'get_userdata' ) || ! get_userdata( $staff_id ) ) {
			return self::error( 'staff_not_found', 'Không tìm thấy nhân viên.', 404 );
		}
		if ( ! class_exists( 'BizCity_Channel_User_Linker' ) || ! class_exists( 'BizCity_CRM_Magic_Link' ) ) {
			return self::error( 'module_not_loaded', 'Kênh Zalo Bot chưa sẵn sàng.', 503 );
		}
		$target = BizCity_Channel_User_Linker::zalo_bot_target_for_user( $staff_id );
		$chat_id = (string) ( $target['chat_id'] ?? '' );
		if ( '' === $chat_id ) {
			// R-LM-8 — no binding, no send, no fallback. The caller (UI) is expected to show this plainly.
			return self::error( 'zalo_bot_unlinked', 'Nhân viên này chưa liên kết Zalo Bot — không thể gửi link.', 422, 'Yêu cầu nhân viên liên kết Zalo Bot trước.' );
		}
		$body = self::body( $request );
		$run_id = (int) ( $body['run_id'] ?? 0 );
		$meta = array( 'staff_user_id' => $staff_id );
		if ( $run_id > 0 ) { $meta['run_id'] = $run_id; }
		if ( ! empty( $body['appointment_at'] ) ) { $meta['appointment_at'] = sanitize_text_field( (string) $body['appointment_at'] ); }
		$issued = BizCity_CRM_Magic_Link::issue( array(
			'platform'    => 'ZALO_BOT',
			'chat_id'     => $chat_id,
			'bot_id'      => (string) ( $target['bot_id'] ?? '' ),
			'intent'      => 'location',
			'ttl_seconds' => 600, // 10 phút (0.69 §4.4b)
			'meta'        => $meta,
		) );
		if ( is_wp_error( $issued ) ) { return self::error_from( $issued ); }
		if ( ! class_exists( 'BizCity_Gateway_Sender' ) ) { return self::error( 'gateway_unavailable', 'Kênh gửi thông báo chưa sẵn sàng.', 503 ); }
		$url = add_query_arg( 'bzloc', $issued['token'], home_url( '/' ) );
		$label = $run_id > 0 ? ( ' cho ca #' . $run_id ) : '';
		try {
			$sent = BizCity_Gateway_Sender::instance()->send( $chat_id, 'Bấm để gửi vị trí hiện tại của bạn' . $label . ': ' . $url, 'text', array( 'source' => 'crm_service_location_request' ) );
		} catch ( Throwable $error ) {
			return self::error( 'gateway_send_failed', 'Không gửi được link qua Zalo Bot.', 500 );
		}
		$ok = is_array( $sent ) ? ( ! empty( $sent['sent'] ) || ! empty( $sent['ok'] ) ) : ( true === $sent );
		if ( ! $ok ) { return self::error( 'gateway_send_failed', 'Không gửi được link qua Zalo Bot.', 500 ); }
		if ( class_exists( 'BizCity_CRM_Audit_Log' ) ) {
			BizCity_CRM_Audit_Log::log( 'crm_staff_location', $staff_id, 'location_link_sent', null, array( 'run_id' => $run_id ?: null ), array( 'user_id' => (int) get_current_user_id() ) );
		}
		return self::ok( array( 'staff_id' => $staff_id, 'expires_in' => 600 ) );
	}

	/** Last-known point + its age — never raw history (0.69 §7: the UI must show age, never claim "live"). */
	public static function get_location( WP_REST_Request $request ) {
		$staff_id = (int) $request['id'];
		if ( $staff_id <= 0 || ! class_exists( 'BizCity_CRM_Location_Service' ) ) {
			return self::error( 'staff_not_found', 'Không tìm thấy nhân viên.', 404 );
		}
		$point = BizCity_CRM_Location_Service::last_for_user( $staff_id );
		if ( null === $point ) {
			return self::ok( array( 'staff_id' => $staff_id, 'point' => null ) );
		}
		$age_s = ! empty( $point['at'] ) ? max( 0, time() - strtotime( (string) $point['at'] ) ) : null;
		return self::ok( array( 'staff_id' => $staff_id, 'point' => array( 'lat' => $point['lat'], 'lng' => $point['lng'], 'at' => $point['at'] ?? null, 'age_s' => $age_s ) ) );
	}

	public static function match( WP_REST_Request $request ) {
		if ( ! class_exists( 'BizCity_CRM_Service_Matcher' ) ) { return self::error( 'module_not_loaded', 'Bộ khớp dịch vụ chưa sẵn sàng.', 503 ); }
		$body = self::body( $request );
		// [2026-09-23 PHASE-0.69 D69-5] `duration_minutes` defaults from `catalogs.services[]` when the
		// caller names a `service_key` — the FE should not have to hardcode the same number as the
		// definition. An explicit `duration_minutes` in the body always wins.
		$duration_minutes = isset( $body['duration_minutes'] ) ? (int) $body['duration_minutes'] : self::default_duration_minutes(
			sanitize_key( (string) ( $body['kind'] ?? 'service' ) ),
			isset( $body['service_key'] ) ? sanitize_text_field( (string) $body['service_key'] ) : ''
		);
		$req = array(
			'team_id'          => (int) ( $body['team_id'] ?? 0 ),
			'appointment_at'   => sanitize_text_field( (string) ( $body['appointment_at'] ?? '' ) ),
			'duration_minutes' => $duration_minutes,
			'skill'            => isset( $body['skill'] ) ? sanitize_text_field( (string) $body['skill'] ) : '',
			'area'             => isset( $body['area'] ) ? sanitize_text_field( (string) $body['area'] ) : '',
		);
		if ( isset( $body['customer_point'] ) && is_array( $body['customer_point'] ) && isset( $body['customer_point']['lat'], $body['customer_point']['lng'] ) ) {
			$req['customer_point'] = array( 'lat' => (float) $body['customer_point']['lat'], 'lng' => (float) $body['customer_point']['lng'] );
		}
		if ( $req['team_id'] <= 0 || '' === $req['appointment_at'] ) {
			return self::error( 'invalid_param', 'Cần team_id và appointment_at.', 422 );
		}
		return self::ok( BizCity_CRM_Service_Matcher::candidates( $req ) );
	}

	/**
	 * Assign a ca to a staff member (0.69 §5.3 N4b — block a double-booking at the server, not just warn in
	 * the UI). Body: `{staff_id, duration_minutes?, lock_version?, override_reason?}`. A `time_conflict` is
	 * refused unless `override_reason` is given, in which case it is written to `audit_log` — never silent.
	 */
	public static function assign( WP_REST_Request $request ) {
		$run = self::scoped_service_run( (int) $request['id'] );
		if ( is_wp_error( $run ) ) { return self::error_from( $run ); }
		$body = self::body( $request );
		$staff_id = (int) ( $body['staff_id'] ?? 0 );
		if ( $staff_id <= 0 || ! function_exists( 'get_userdata' ) || ! get_userdata( $staff_id ) ) {
			return self::error( 'invalid_param', 'Chọn nhân viên hợp lệ.', 422 );
		}
		$override_reason = isset( $body['override_reason'] ) ? sanitize_text_field( (string) $body['override_reason'] ) : '';
		$appointment_at = (string) ( $run['appointment_at'] ?? '' );
		if ( '' !== $appointment_at && class_exists( 'BizCity_CRM_Service_Matcher' ) ) {
			$duration_minutes = isset( $body['duration_minutes'] ) ? (int) $body['duration_minutes'] : self::run_duration_minutes( $run );
			$duration_s = max( 60, $duration_minutes * 60 );
			$start_ts = strtotime( $appointment_at );
			$conflict = false !== $start_ts ? BizCity_CRM_Service_Matcher::has_conflict( $staff_id, (int) $start_ts, $duration_s ) : null;
			if ( true === $conflict && '' === $override_reason ) {
				return self::error( 'time_conflict', 'Nhân viên đã có lịch trùng giờ.', 409, 'Chọn người khác, hoặc xác nhận ghi đè kèm lý do.' );
			}
		}
		$args = array( 'actor_id' => (int) get_current_user_id(), 'stage_assignee_id' => $staff_id );
		if ( null !== ( $run['lock_version'] ?? null ) ) { $args['lock_version'] = (int) $run['lock_version']; }
		$result = BizCity_CRM_Pipeline_Run_Service::start_stage( (int) $run['id'], 'assigned', $args );
		if ( is_wp_error( $result ) ) { return self::error_from( $result ); }
		if ( '' !== $override_reason && class_exists( 'BizCity_CRM_Audit_Log' ) ) {
			BizCity_CRM_Audit_Log::log( 'crm_opportunity', (int) $run['id'], 'service_assign_override', null, array( 'staff_id' => $staff_id, 'reason' => $override_reason ), array( 'user_id' => (int) get_current_user_id() ) );
		}
		return self::ok( array( 'run' => $result ) );
	}

	/**
	 * Check-in with fresh coordinates (0.69 §7/L-06). D69-2: distance is ADVISORY — never blocks. Also
	 * updates the staff's last-known point (same store `request_location`'s consume flow writes to).
	 */
	public static function checkin( WP_REST_Request $request ) {
		$run = self::scoped_service_run( (int) $request['id'] );
		if ( is_wp_error( $run ) ) { return self::error_from( $run ); }
		$body = self::body( $request );
		$lat = isset( $body['lat'] ) ? (float) $body['lat'] : null;
		$lng = isset( $body['lng'] ) ? (float) $body['lng'] : null;
		if ( null === $lat || null === $lng || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 ) {
			return self::error( 'invalid_param', 'Toạ độ không hợp lệ.', 422 );
		}
		$point = array( 'lat' => $lat, 'lng' => $lng );
		$verdict = array( 'distance_m' => -1.0, 'within_radius' => null, 'radius_m' => 150 );
		$address = is_array( $run['service_address'] ?? null ) ? $run['service_address'] : null;
		if ( is_array( $address ) && class_exists( 'BizCity_CRM_Location_Service' ) ) {
			$verdict = BizCity_CRM_Location_Service::checkin_verdict( $point, $address, (int) ( $body['radius_m'] ?? 150 ) );
		}
		$actor_id = (int) get_current_user_id();
		if ( class_exists( 'BizCity_CRM_Location_Service' ) ) {
			BizCity_CRM_Location_Service::save_staff_location( $actor_id, $point );
		}
		$args = array(
			'actor_id' => $actor_id,
			'data'     => array( 'checkin_far' => ( false === $verdict['within_radius'] ) ? 1 : 0, 'distance_m' => round( max( 0, $verdict['distance_m'] ) ) ),
		);
		if ( null !== ( $run['lock_version'] ?? null ) ) { $args['lock_version'] = (int) $run['lock_version']; }
		$result = BizCity_CRM_Pipeline_Run_Service::start_stage( (int) $run['id'], 'checkin', $args );
		if ( is_wp_error( $result ) ) { return self::error_from( $result ); }
		if ( false === $verdict['within_radius'] && class_exists( 'BizCity_CRM_Audit_Log' ) ) {
			// [0.69 L-07] Distance number, never the raw coordinates, into audit.
			BizCity_CRM_Audit_Log::log( 'crm_opportunity', (int) $run['id'], 'service_checkin_far', null, array( 'distance_m' => round( $verdict['distance_m'] ) ), array( 'user_id' => $actor_id ) );
		}
		return self::ok( array( 'run' => $result, 'verdict' => $verdict ) );
	}

	/** Same contact-scope discipline as `class-pipeline-rest.php::scoped_run()`, restricted to kind `service`. */
	private static function scoped_service_run( int $run_id ) {
		if ( $run_id <= 0 || ! class_exists( 'BizCity_CRM_Pipeline_Run_Service' ) ) {
			return new WP_Error( 'run_not_found', 'Không tìm thấy pipeline đang chạy.', array( 'status' => 404, 'help_code' => 'service_run_not_found' ) );
		}
		$run = BizCity_CRM_Pipeline_Run_Service::get_run( $run_id );
		if ( is_wp_error( $run ) ) { return $run; }
		if ( 'service' !== (string) ( $run['pipeline_kind'] ?? '' ) ) {
			return new WP_Error( 'invalid_param', 'Chỉ áp dụng cho pipeline dịch vụ.', array( 'status' => 422, 'help_code' => 'service_kind_mismatch' ) );
		}
		$contact_id = (int) ( $run['contact_id'] ?? 0 );
		if ( class_exists( 'BizCity_CRM_Customer_Pipeline' ) ) {
			$inboxes = BizCity_CRM_Customer_Pipeline::b2_inbox_ids( (int) get_current_user_id() );
			if ( $contact_id <= 0 || ! BizCity_CRM_Customer_Pipeline::contact_in_scope( $contact_id, $inboxes ) ) {
				return new WP_Error( 'run_not_found', 'Không tìm thấy pipeline đang chạy.', array( 'status' => 404, 'help_code' => 'service_run_not_found' ) );
			}
		}
		return $run;
	}

	/** PHASE-0.69 D69-5 — `run.service_key` looked up against the run's own PINNED definition (`run['definition']`,
	 * already resolved to `pipeline_def_version` by `Pipeline_Run_Service`), so a later edit to the catalog
	 * never changes the duration a run already committed to. Falls back to 60 when the run has no
	 * `service_key` or the definition has no matching catalog entry. */
	private static function run_duration_minutes( array $run ): int {
		$service_key = (string) ( $run['service_key'] ?? '' );
		$definition = is_array( $run['definition'] ?? null ) ? $run['definition'] : array();
		if ( '' === $service_key || ! class_exists( 'BizCity_CRM_Pipeline_Registry' ) ) {
			return 60;
		}
		$service = BizCity_CRM_Pipeline_Registry::catalog_service( $definition, $service_key );
		return $service ? (int) $service['duration_minutes'] : 60;
	}

	/** Same lookup as `run_duration_minutes()`, but for a hypothetical booking with no run yet (`/service/match`) —
	 * reads the CURRENT definition for `$kind` (no pin to look up). */
	private static function default_duration_minutes( string $kind, string $service_key ): int {
		if ( '' === $service_key || ! class_exists( 'BizCity_CRM_Pipeline_Registry' ) ) {
			return 60;
		}
		$definition = BizCity_CRM_Pipeline_Registry::get( $kind );
		if ( ! is_array( $definition ) ) {
			return 60;
		}
		$service = BizCity_CRM_Pipeline_Registry::catalog_service( $definition, $service_key );
		return $service ? (int) $service['duration_minutes'] : 60;
	}

	/** Public, unauthenticated (see route registration comment). */
	public static function consume_location( WP_REST_Request $request ) {
		if ( ! class_exists( 'BizCity_CRM_Magic_Link' ) ) { return self::error( 'module_not_loaded', 'Chưa sẵn sàng.', 503 ); }
		$token = (string) $request['token'];
		$result = BizCity_CRM_Magic_Link::verify( $token );
		if ( is_wp_error( $result ) || 'location' !== (string) ( $result['intent'] ?? '' ) ) {
			return self::error( 'link_invalid', 'Link không hợp lệ hoặc đã hết hạn.', 410 );
		}
		$body = self::body( $request );
		$lat = isset( $body['lat'] ) ? (float) $body['lat'] : null;
		$lng = isset( $body['lng'] ) ? (float) $body['lng'] : null;
		if ( null === $lat || null === $lng || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 ) {
			return self::error( 'invalid_param', 'Toạ độ không hợp lệ.', 422 );
		}
		$meta = ! empty( $result['meta_json'] ) ? json_decode( (string) $result['meta_json'], true ) : array();
		$staff_id = is_array( $meta ) ? (int) ( $meta['staff_user_id'] ?? 0 ) : 0;
		if ( $staff_id <= 0 ) {
			return self::error( 'link_invalid', 'Link không hợp lệ.', 410 );
		}
		// Consume with `user_id=0` — this page never authenticates a WP user (0.69 §4.4b).
		$consumed = BizCity_CRM_Magic_Link::consume( (int) $result['id'], 0 );
		if ( ! $consumed ) {
			return self::error( 'link_invalid', 'Link đã được dùng.', 410 );
		}
		if ( class_exists( 'BizCity_CRM_Location_Service' ) ) {
			BizCity_CRM_Location_Service::save_staff_location( $staff_id, array( 'lat' => $lat, 'lng' => $lng ) );
		}
		// [0.69 §4.4b L-05g / L-07] Audit WHO/WHEN/WHICH token — never the coordinates themselves.
		if ( class_exists( 'BizCity_CRM_Audit_Log' ) ) {
			BizCity_CRM_Audit_Log::log( 'crm_staff_location', $staff_id, 'location_submitted', null, array( 'accuracy' => isset( $body['accuracy'] ) ? (int) $body['accuracy'] : null ), array( 'user_id' => 0 ) );
		}
		$run_id = is_array( $meta ) ? (int) ( $meta['run_id'] ?? 0 ) : 0;
		if ( $run_id > 0 && class_exists( 'BizCity_CRM_Pipeline_Run_Service' ) ) {
			// A staff self-report can also stand in as the run's location context if useful downstream;
			// harmless no-op when the run has since closed.
			BizCity_CRM_Pipeline_Run_Service::set_service_address( $run_id, array( 'lat' => $lat, 'lng' => $lng, 'label' => 'staff_self_report' ) );
		}
		return self::ok( array( 'saved' => true ) );
	}

	private static function body( WP_REST_Request $req ): array {
		$body = $req->get_json_params();
		return is_array( $body ) ? $body : (array) $req->get_body_params();
	}

	private static function ok( array $data ): WP_REST_Response {
		return new WP_REST_Response( array_merge( array( 'ok' => true ), $data ), 200 );
	}

	private static function error_from( WP_Error $error ): WP_REST_Response {
		$data = (array) $error->get_error_data();
		return self::error( (string) $error->get_error_code(), $error->get_error_message(), (int) ( $data['status'] ?? 400 ), (string) ( $data['hint'] ?? '' ) );
	}

	private static function error( string $code, string $message, int $status, string $hint = '' ): WP_REST_Response {
		return new WP_REST_Response( array( 'ok' => false, 'code' => $code, 'message' => $message, 'hint' => $hint, 'help_code' => $code ), $status );
	}
}
