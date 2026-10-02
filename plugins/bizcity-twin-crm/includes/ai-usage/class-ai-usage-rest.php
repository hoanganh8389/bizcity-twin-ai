<?php
/**
 * PHASE-0.85 §K1 (C85-7) — `bizcity-crm/v1/ai-usage/*`: cookie + `X-WP-Nonce`
 * (standard WP REST auth, same as every other CRM route in this namespace).
 * Every route requires being logged in; `Scope::resolve()` (not the REST
 * permission callback) is what turns `scope=all` into `crm_forbidden_reports`
 * for a caller without `bizcity_crm_view_reports` — the permission callback
 * only gates "logged in at all", matching how `scope=mine` must stay open to
 * any staff member for their OWN numbers.
 *
 * @package BizCity_Twin_CRM
 * @since   PHASE-0.85 2026-09-30
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_AI_Usage_REST', false ) ) {
	return;
}

final class BizCity_CRM_AI_Usage_REST {

	const NS = 'ai-usage';

	public static function register_routes(): void {
		$ns   = defined( 'BIZCITY_CRM_REST_NS' ) ? BIZCITY_CRM_REST_NS : 'bizcity-crm/v1';
		$args = array( 'methods' => 'GET', 'permission_callback' => array( __CLASS__, 'can_read' ) );

		register_rest_route( $ns, '/' . self::NS . '/overview', array_merge( $args, array( 'callback' => array( __CLASS__, 'overview' ) ) ) );
		register_rest_route( $ns, '/' . self::NS . '/by-tool', array_merge( $args, array( 'callback' => array( __CLASS__, 'by_tool' ) ) ) );
		register_rest_route( $ns, '/' . self::NS . '/by-account', array_merge( $args, array( 'callback' => array( __CLASS__, 'by_account' ) ) ) );
		register_rest_route( $ns, '/' . self::NS . '/by-customer', array_merge( $args, array( 'callback' => array( __CLASS__, 'by_customer' ) ) ) );
		register_rest_route( $ns, '/' . self::NS . '/turns', array_merge( $args, array( 'callback' => array( __CLASS__, 'turns' ) ) ) );
		register_rest_route( $ns, '/' . self::NS . '/limits', array_merge( $args, array( 'callback' => array( __CLASS__, 'limits' ) ) ) );

		// PHASE-0.85 §K3 (C85-6) — cảnh báo: gộp, đã xem, tắt tiếng, ngưỡng riêng.
		register_rest_route( $ns, '/' . self::NS . '/alerts', array_merge( $args, array( 'callback' => array( __CLASS__, 'alerts' ) ) ) );
		register_rest_route( $ns, '/' . self::NS . '/alerts/(?P<id>[^/]+)/ack', array(
			'methods' => 'POST', 'permission_callback' => array( __CLASS__, 'can_read' ), 'callback' => array( __CLASS__, 'alerts_ack' ),
		) );
		register_rest_route( $ns, '/' . self::NS . '/alerts/(?P<id>[^/]+)/mute', array(
			'methods' => 'POST', 'permission_callback' => array( __CLASS__, 'can_read' ), 'callback' => array( __CLASS__, 'alerts_mute' ),
		) );
		// Băng-rôn cho nhân viên trực - quyền RIÊNG (xử lý hội thoại), không phải quyền xem báo cáo.
		register_rest_route( $ns, '/' . self::NS . '/alerts/banner', array(
			'methods' => 'GET', 'permission_callback' => array( __CLASS__, 'can_handle_inbox' ), 'callback' => array( __CLASS__, 'alerts_banner' ),
		) );
		register_rest_route( $ns, '/' . self::NS . '/alert-prefs', array(
			array( 'methods' => 'GET', 'permission_callback' => array( __CLASS__, 'can_read' ), 'callback' => array( __CLASS__, 'alert_prefs_get' ) ),
			array( 'methods' => 'PUT', 'permission_callback' => array( __CLASS__, 'can_read' ), 'callback' => array( __CLASS__, 'alert_prefs_set' ) ),
		) );
	}

	public static function can_read(): bool {
		return is_user_logged_in();
	}

	public static function can_handle_inbox(): bool {
		if ( ! is_user_logged_in() ) { return false; }
		$cap = class_exists( 'BizCity_CRM_Capabilities' ) ? BizCity_CRM_Capabilities::CAP_HANDLE_INBOX : 'bizcity_crm_handle_inbox';
		return current_user_can( $cap ) || current_user_can( 'manage_options' );
	}

	private static function scope( WP_REST_Request $req ) {
		if ( ! class_exists( 'BizCity_CRM_AI_Usage_Scope' ) ) {
			return self::err( 'module_not_loaded', 'Báo cáo chi phí AI chưa sẵn sàng.', 'Tải lại trang sau ít phút.', 'module_not_loaded', 503 );
		}
		$requested = sanitize_key( (string) $req->get_param( 'scope' ) ) ?: 'mine';
		$resolved  = BizCity_CRM_AI_Usage_Scope::resolve( (int) get_current_user_id(), $requested );
		if ( is_wp_error( $resolved ) ) {
			$data = $resolved->get_error_data();
			return self::err( $resolved->get_error_code(), $resolved->get_error_message(), $data['hint'] ?? '', $data['help_code'] ?? '', $data['status'] ?? 403 );
		}
		return $resolved;
	}

	private static function range( WP_REST_Request $req ): string {
		$r = sanitize_key( (string) $req->get_param( 'range' ) );
		return in_array( $r, array( 'today', '7d', '30d', 'month' ), true ) ? $r : 'today';
	}

	/**
	 * Transient cache theo `(blog, user mode, route, range, account)` — 60 s cho `range=today`
	 * (số đang chạy, cần tươi), 3 600 s cho kỳ đã qua (không đổi nữa, khỏi dội Hub mỗi lần mở
	 * trang). `fresh=1` bỏ qua cache MỘT LẦN, chặn bởi transient riêng 15 s/người: xin `fresh=1`
	 * lần hai trong vòng 15 s vẫn đọc cache như bình thường (không việc gì cũng ép Hub trả lời).
	 */
	private static function cached( string $route, array $scope, string $range, string $account, WP_REST_Request $req, callable $compute ): array {
		$key = 'bz_aiu_' . md5( implode( '|', array( get_current_blog_id(), $scope['mode'], $route, $range, $account ) ) );

		$wants_fresh = '1' === (string) $req->get_param( 'fresh' );
		if ( $wants_fresh ) {
			$throttle_key = 'bz_aiu_fresh_' . get_current_user_id();
			if ( false === get_transient( $throttle_key ) ) {
				set_transient( $throttle_key, 1, 15 );
			} else {
				$wants_fresh = false; // đã làm mới < 15 s trước - đọc cache như thường, không dội Hub thêm lần nữa
			}
		}

		if ( ! $wants_fresh ) {
			$cached = get_transient( $key );
			if ( is_array( $cached ) ) { return $cached; }
		}

		$data = $compute();
		set_transient( $key, $data, 'today' === $range ? 60 : 3600 );
		return $data;
	}

	public static function overview( WP_REST_Request $req ) {
		$scope = self::scope( $req );
		if ( $scope instanceof WP_REST_Response ) { return $scope; }
		if ( ! class_exists( 'BizCity_CRM_AI_Usage_Service' ) ) { return self::not_loaded(); }
		$range = self::range( $req );
		$data  = self::cached( 'overview', $scope, $range, '', $req, static function () use ( $scope, $range ) {
			return BizCity_CRM_AI_Usage_Service::overview( $scope, $range );
		} );
		return new WP_REST_Response( array_merge( array( 'ok' => true ), $data ), 200 );
	}

	public static function by_tool( WP_REST_Request $req ) {
		$scope = self::scope( $req );
		if ( $scope instanceof WP_REST_Response ) { return $scope; }
		if ( ! class_exists( 'BizCity_CRM_AI_Usage_Service' ) ) { return self::not_loaded(); }
		$range = self::range( $req );
		$data  = self::cached( 'by-tool', $scope, $range, '', $req, static function () use ( $scope, $range ) {
			return BizCity_CRM_AI_Usage_Service::by_tool( $scope, $range );
		} );
		return new WP_REST_Response( array_merge( array( 'ok' => true ), $data ), 200 );
	}

	public static function by_account( WP_REST_Request $req ) {
		$scope = self::scope( $req );
		if ( $scope instanceof WP_REST_Response ) { return $scope; }
		if ( ! class_exists( 'BizCity_CRM_AI_Usage_Service' ) ) { return self::not_loaded(); }
		$range = self::range( $req );
		$data  = self::cached( 'by-account', $scope, $range, '', $req, static function () use ( $scope, $range ) {
			return BizCity_CRM_AI_Usage_Service::by_account( $scope, $range );
		} );
		return new WP_REST_Response( array_merge( array( 'ok' => true ), $data ), 200 );
	}

	public static function by_customer( WP_REST_Request $req ) {
		$scope = self::scope( $req );
		if ( $scope instanceof WP_REST_Response ) { return $scope; }
		if ( ! class_exists( 'BizCity_CRM_AI_Usage_Service' ) ) { return self::not_loaded(); }
		$range      = self::range( $req );
		$account_id = sanitize_text_field( (string) $req->get_param( 'account_id' ) );
		$cursor     = sanitize_text_field( (string) $req->get_param( 'cursor' ) );
		// Cursor phân trang: bỏ qua cache khi có cursor (trang sau không nên trùng trang đầu đã cache).
		if ( '' !== $cursor ) {
			return new WP_REST_Response( array_merge( array( 'ok' => true ), BizCity_CRM_AI_Usage_Service::by_customer( $scope, $range, $account_id, $cursor ) ), 200 );
		}
		$data = self::cached( 'by-customer', $scope, $range, $account_id, $req, static function () use ( $scope, $range, $account_id ) {
			return BizCity_CRM_AI_Usage_Service::by_customer( $scope, $range, $account_id, '' );
		} );
		return new WP_REST_Response( array_merge( array( 'ok' => true ), $data ), 200 );
	}

	public static function turns( WP_REST_Request $req ) {
		$scope = self::scope( $req );
		if ( $scope instanceof WP_REST_Response ) { return $scope; }
		if ( ! class_exists( 'BizCity_CRM_AI_Usage_Service' ) ) { return self::not_loaded(); }
		$range      = self::range( $req );
		$account_id = sanitize_text_field( (string) $req->get_param( 'account_id' ) );
		$thread_id  = sanitize_text_field( (string) $req->get_param( 'thread_id' ) );
		$cursor     = sanitize_text_field( (string) $req->get_param( 'cursor' ) );
		if ( '' !== $cursor ) {
			return new WP_REST_Response( array_merge( array( 'ok' => true ), BizCity_CRM_AI_Usage_Service::turns( $scope, $range, $account_id, $thread_id, $cursor ) ), 200 );
		}
		$data = self::cached( 'turns:' . $thread_id, $scope, $range, $account_id, $req, static function () use ( $scope, $range, $account_id, $thread_id ) {
			return BizCity_CRM_AI_Usage_Service::turns( $scope, $range, $account_id, $thread_id, '' );
		} );
		return new WP_REST_Response( array_merge( array( 'ok' => true ), $data ), 200 );
	}

	public static function limits( WP_REST_Request $req ) {
		$scope = self::scope( $req );
		if ( $scope instanceof WP_REST_Response ) { return $scope; }
		if ( ! class_exists( 'BizCity_CRM_AI_Usage_Service' ) ) { return self::not_loaded(); }
		$data = self::cached( 'limits', $scope, 'today', '', $req, static function () use ( $scope ) {
			return BizCity_CRM_AI_Usage_Service::limits( $scope );
		} );
		return new WP_REST_Response( array_merge( array( 'ok' => true ), $data ), 200 );
	}

	public static function alerts( WP_REST_Request $req ) {
		$scope = self::scope( $req );
		if ( $scope instanceof WP_REST_Response ) { return $scope; }
		if ( ! class_exists( 'BizCity_CRM_AI_Usage_Alerts' ) ) { return self::not_loaded(); }
		$data = self::cached( 'alerts', $scope, 'today', '', $req, static function () use ( $scope ) {
			return BizCity_CRM_AI_Usage_Alerts::list( $scope, (int) get_current_user_id() );
		} );
		return new WP_REST_Response( array_merge( array( 'ok' => true ), $data ), 200 );
	}

	public static function alerts_ack( WP_REST_Request $req ) {
		if ( ! class_exists( 'BizCity_CRM_AI_Usage_Alerts' ) ) { return self::not_loaded(); }
		$id = sanitize_text_field( (string) $req->get_param( 'id' ) );
		return new WP_REST_Response( BizCity_CRM_AI_Usage_Alerts::ack( (int) get_current_user_id(), $id ), 200 );
	}

	public static function alerts_mute( WP_REST_Request $req ) {
		if ( ! class_exists( 'BizCity_CRM_AI_Usage_Alerts' ) ) { return self::not_loaded(); }
		$id     = sanitize_text_field( (string) $req->get_param( 'id' ) );
		$hours  = (int) ( $req->get_param( 'hours' ) ?: 24 );
		$result = BizCity_CRM_AI_Usage_Alerts::mute( (int) get_current_user_id(), $id, $hours );
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			return self::err( $result->get_error_code(), $result->get_error_message(), $data['hint'] ?? '', $data['help_code'] ?? '', $data['status'] ?? 400 );
		}
		return new WP_REST_Response( $result, 200 );
	}

	public static function alerts_banner( WP_REST_Request $req ) {
		if ( ! class_exists( 'BizCity_CRM_AI_Usage_Alerts' ) ) { return self::not_loaded(); }
		return new WP_REST_Response( array_merge( array( 'ok' => true ), BizCity_CRM_AI_Usage_Alerts::list_for_staff() ), 200 );
	}

	public static function alert_prefs_get( WP_REST_Request $req ) {
		if ( ! class_exists( 'BizCity_CRM_AI_Usage_Alerts' ) ) { return self::not_loaded(); }
		return new WP_REST_Response( array( 'ok' => true, 'prefs' => BizCity_CRM_AI_Usage_Alerts::get_prefs( (int) get_current_user_id() ) ), 200 );
	}

	public static function alert_prefs_set( WP_REST_Request $req ) {
		if ( ! class_exists( 'BizCity_CRM_AI_Usage_Alerts' ) ) { return self::not_loaded(); }
		$body  = (array) ( $req->get_json_params() ?: array() );
		$prefs = BizCity_CRM_AI_Usage_Alerts::set_prefs( (int) get_current_user_id(), $body );
		return new WP_REST_Response( array( 'ok' => true, 'prefs' => $prefs ), 200 );
	}

	private static function not_loaded(): WP_REST_Response {
		return self::err( 'module_not_loaded', 'Báo cáo chi phí AI chưa sẵn sàng.', 'Tải lại trang sau ít phút.', 'module_not_loaded', 503 );
	}

	private static function err( string $code, string $message, string $hint, string $help_code, int $status ): WP_REST_Response {
		return new WP_REST_Response( array( 'ok' => false, 'code' => $code, 'message' => $message, 'hint' => $hint, 'help_code' => $help_code ), $status );
	}
}
