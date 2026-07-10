<?php
/**
 * BizCoach Pro — Client plan service (Hub proxy helpers).
 *
 * @package BizCoach_Pro
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCoach_Pro_Plan_Service', false ) ) {
	return;
}

class BizCoach_Pro_Plan_Service {

	const META_PLAN_CACHE       = 'bcpro_client_plan_cache_v1';
	const META_BILLING_CALLBACK = 'bcpro_client_billing_callback_v1';
	const CACHE_SCHEMA          = '20260710_v1';
	const CALLBACK_RECENT_WINDOW = 21600;

	/**
	 * [2026-07-10 Johnny Chu] PHASE-C-WOO-HUB — read cached plan, optional fresh sync.
	 *
	 * @param int  $user_id
	 * @param bool $fresh
	 * @return array
	 */
	public static function get_plan( $user_id, $fresh = false ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return self::error_payload(
				'auth_required',
				'Vui lòng đăng nhập để xem gói của bạn.',
				'Đăng nhập rồi thử lại.',
				'auth_required'
			);
		}

		$cached = self::read_cached_plan( $user_id );
		if ( ! $fresh && is_array( $cached ) && ! empty( $cached['success'] ) ) {
			$cached['cached'] = true;
			return $cached;
		}

		return self::sync_from_hub( $user_id );
	}

	/**
	 * [2026-07-10 Johnny Chu] PHASE-C-WOO-HUB — sync entitlement from Hub License API.
	 *
	 * @param int $user_id
	 * @return array
	 */
	public static function sync_from_hub( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return self::error_payload(
				'auth_required',
				'Vui lòng đăng nhập để đồng bộ entitlement.',
				'Đăng nhập rồi thử lại.',
				'auth_required'
			);
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return self::error_payload(
				'not_found',
				'Không tìm thấy người dùng để đồng bộ.',
				'Tải lại trang hoặc đăng nhập lại.',
				'not_found'
			);
		}

		$client_id = self::get_client_id();
		$query = array(
			'client_id'  => $client_id,
			'user_id'    => $user_id,
			'user_email' => (string) $user->user_email,
			'user_login' => (string) $user->user_login,
		);
		$resp = self::hub_request( 'GET', '/bizcity-license/v1/me/entitlement', array(), $query );

		if ( ! is_array( $resp ) || empty( $resp['success'] ) ) {
			$cached = self::read_cached_plan( $user_id );
			if ( is_array( $cached ) && ! empty( $cached['success'] ) ) {
				$cached['_degraded'] = true;
				$cached['message'] = isset( $resp['message'] ) ? (string) $resp['message'] : 'Hub chưa phản hồi, đang dùng cache cũ.';
				return $cached;
			}

			$fallback = self::fallback_free_plan( $user_id );
			$fallback['_degraded'] = true;
			$fallback['message'] = isset( $resp['message'] ) ? (string) $resp['message'] : 'Hub chưa phản hồi, đang dùng mặc định free.';
			return $fallback;
		}

		$plan_code = sanitize_key( (string) ( $resp['plan_code'] ?? ( $resp['master_level'] ?? 'free' ) ) );
		if ( $plan_code === '' ) {
			$plan_code = 'free';
		}

		$tier = sanitize_key( (string) ( $resp['tier'] ?? 'free' ) );
		if ( ! in_array( $tier, array( 'free', 'paid', 'enterprise' ), true ) ) {
			$tier = 'free';
		}

		$normalized = array(
			'success'    => true,
			'schema'     => self::CACHE_SCHEMA,
			'client_id'  => $client_id,
			'user_id'    => $user_id,
			'plan_code'  => $plan_code,
			'tier'       => $tier,
			'features'   => self::normalize_features( isset( $resp['features'] ) ? $resp['features'] : array() ),
			'status'     => sanitize_key( (string) ( $resp['status'] ?? 'active' ) ),
			'starts_at'  => sanitize_text_field( (string) ( $resp['starts_at'] ?? '' ) ),
			'expires_at' => sanitize_text_field( (string) ( $resp['expires_at'] ?? '' ) ),
			'cached'     => false,
			'_degraded'  => ! empty( $resp['_degraded'] ),
			'source'     => sanitize_key( (string) ( $resp['_via'] ?? 'hub' ) ),
			'updated_at' => gmdate( 'c' ),
		);
		if ( $normalized['status'] === '' ) {
			$normalized['status'] = 'active';
		}

		update_user_meta( $user_id, self::META_PLAN_CACHE, $normalized );
		return $normalized;
	}

	/**
	 * [2026-07-10 Johnny Chu] PHASE-C-WOO-HUB — proxy packages for pricing UI.
	 *
	 * @return array
	 */
	public static function fetch_packages() {
		$resp = self::hub_request( 'GET', '/bizcity-commerce/v1/packages' );
		if ( ! is_array( $resp ) ) {
			return self::error_payload(
				'gateway_degraded',
				'Hub không phản hồi danh sách gói.',
				'Thử lại sau vài phút.',
				'gateway_degraded',
				true
			);
		}
		if ( ! empty( $resp['success'] ) ) {
			return $resp;
		}

		return self::error_payload(
			'gateway_degraded',
			isset( $resp['message'] ) ? (string) $resp['message'] : 'Không lấy được danh sách gói.',
			'Kiểm tra kết nối hub hoặc API key.',
			'gateway_degraded',
			true
		);
	}

	/**
	 * [2026-07-10 Johnny Chu] PHASE-C-WOO-HUB — create checkout via Hub Commerce API.
	 *
	 * @param int   $user_id
	 * @param array $payload
	 * @return array
	 */
	public static function create_checkout( $user_id, array $payload ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return self::error_payload(
				'auth_required',
				'Vui lòng đăng nhập để tạo checkout.',
				'Đăng nhập rồi thử lại.',
				'auth_required'
			);
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return self::error_payload(
				'not_found',
				'Không tìm thấy người dùng để tạo checkout.',
				'Tải lại trang rồi thử lại.',
				'not_found'
			);
		}

		$body = array(
			'client_id'     => self::get_client_id(),
			'user_id'       => $user_id,
			'user_email'    => (string) $user->user_email,
			'user_login'    => (string) $user->user_login,
			'plan_code'     => sanitize_key( (string) ( $payload['plan_code'] ?? '' ) ),
			'billing_cycle' => sanitize_key( (string) ( $payload['billing_cycle'] ?? 'month' ) ),
			'return_url'    => esc_url_raw( (string) ( $payload['return_url'] ?? '' ) ),
		);

		$resp = self::hub_request( 'POST', '/bizcity-commerce/v1/checkout', $body );
		if ( is_array( $resp ) ) {
			return $resp;
		}

		return self::error_payload(
			'gateway_degraded',
			'Không thể tạo checkout từ Hub.',
			'Thử lại sau vài phút.',
			'gateway_degraded',
			true
		);
	}

	/**
	 * [2026-07-10 Johnny Chu] PHASE-C-WOO-HUB — order status proxy.
	 *
	 * @param int $user_id
	 * @param int $order_id
	 * @return array
	 */
	public static function get_order_status( $user_id, $order_id ) {
		$user_id  = (int) $user_id;
		$order_id = (int) $order_id;

		if ( $user_id <= 0 ) {
			return self::error_payload(
				'auth_required',
				'Vui lòng đăng nhập để xem trạng thái đơn.',
				'Đăng nhập rồi thử lại.',
				'auth_required'
			);
		}
		if ( $order_id <= 0 ) {
			return self::error_payload(
				'invalid_param',
				'order_id không hợp lệ.',
				'Gửi order_id kiểu số nguyên dương.',
				'invalid_param_generic'
			);
		}

		$query = array(
			'client_id' => self::get_client_id(),
			'user_id'   => $user_id,
		);
		$resp = self::hub_request( 'GET', '/bizcity-commerce/v1/orders/' . $order_id . '/status', array(), $query );
		if ( is_array( $resp ) ) {
			return $resp;
		}

		return self::error_payload(
			'gateway_degraded',
			'Không đọc được trạng thái đơn hàng từ Hub.',
			'Thử lại sau vài phút.',
			'gateway_degraded',
			true
		);
	}

	/**
	 * [2026-07-10 Johnny Chu] PHASE-C-WOO-HUB — store UX callback trace.
	 *
	 * @param int   $user_id
	 * @param array $payload
	 * @return array
	 */
	public static function record_billing_callback( $user_id, array $payload ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return self::error_payload(
				'auth_required',
				'Vui lòng đăng nhập để ghi callback.',
				'Đăng nhập rồi thử lại.',
				'auth_required'
			);
		}

		$trace = array(
			'order_id'    => isset( $payload['order_id'] ) ? (int) $payload['order_id'] : 0,
			'status'      => sanitize_key( (string) ( $payload['status'] ?? '' ) ),
			'event'       => sanitize_key( (string) ( $payload['event'] ?? 'return_from_checkout' ) ),
			// [2026-07-10 Johnny Chu] PHASE-C-WOO-HUB — persist plan/checkout fields for callback fallback bootstrap.
			'plan_code'   => sanitize_key( (string) ( $payload['plan_code'] ?? '' ) ),
			'checkout_url'=> esc_url_raw( (string) ( $payload['checkout_url'] ?? '' ) ),
			'return_url'  => esc_url_raw( (string) ( $payload['return_url'] ?? '' ) ),
			'transaction' => sanitize_text_field( (string) ( $payload['transaction_id'] ?? '' ) ),
			'timestamp'   => gmdate( 'c' ),
		);
		update_user_meta( $user_id, self::META_BILLING_CALLBACK, $trace );

		return array(
			'success' => true,
			'callback'=> $trace,
		);
	}

	/**
	 * [2026-07-10 Johnny Chu] PHASE-C-WOO-HUB — read recent callback metadata for return URL fallback.
	 *
	 * @param int $user_id
	 * @return array
	 */
	public static function get_recent_billing_callback( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return self::error_payload(
				'auth_required',
				'Vui lòng đăng nhập để đọc callback thanh toán.',
				'Đăng nhập rồi thử lại.',
				'auth_required'
			);
		}

		$raw = get_user_meta( $user_id, self::META_BILLING_CALLBACK, true );
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		$callback = array(
			'order_id'     => isset( $raw['order_id'] ) ? (int) $raw['order_id'] : 0,
			'status'       => sanitize_key( (string) ( $raw['status'] ?? '' ) ),
			'event'        => sanitize_key( (string) ( $raw['event'] ?? '' ) ),
			'plan_code'    => sanitize_key( (string) ( $raw['plan_code'] ?? '' ) ),
			'checkout_url' => esc_url_raw( (string) ( $raw['checkout_url'] ?? '' ) ),
			'return_url'   => esc_url_raw( (string) ( $raw['return_url'] ?? '' ) ),
			'transaction'  => sanitize_text_field( (string) ( $raw['transaction'] ?? '' ) ),
			'timestamp'    => sanitize_text_field( (string) ( $raw['timestamp'] ?? '' ) ),
		);

		$event_ts = strtotime( $callback['timestamp'] );
		$is_recent = false;
		if ( is_int( $event_ts ) && $event_ts > 0 ) {
			$age = abs( time() - $event_ts );
			$is_recent = $age <= self::CALLBACK_RECENT_WINDOW;
		}

		return array(
			'success'        => true,
			'has_callback'   => ( $callback['order_id'] > 0 ),
			'is_recent'      => $is_recent,
			'window_seconds' => self::CALLBACK_RECENT_WINDOW,
			'callback'       => $callback,
		);
	}

	/**
	 * Build gateway request to Hub APIs.
	 *
	 * @param string $method
	 * @param string $path
	 * @param array  $body
	 * @param array  $query
	 * @return array
	 */
	private static function hub_request( $method, $path, array $body = array(), array $query = array() ) {
		$base = self::get_gateway_base();
		if ( $base === '' ) {
			return self::error_payload(
				'gateway_degraded',
				'Gateway URL chưa cấu hình.',
				'Vào Cài đặt BizCity để cấu hình gateway.',
				'gateway_degraded',
				true
			);
		}

		$url = untrailingslashit( $base ) . '/' . ltrim( (string) $path, '/' );
		if ( ! empty( $query ) ) {
			$query_scalars = array();
			foreach ( $query as $k => $v ) {
				$query_scalars[ (string) $k ] = is_scalar( $v ) ? (string) $v : wp_json_encode( $v );
			}
			$url = add_query_arg( $query_scalars, $url );
		}

		$headers = array(
			'Accept'       => 'application/json',
			'X-Site-URL'   => home_url(),
			'X-BizCity-Client-Id' => self::get_client_id(),
		);
		$key = self::get_api_key();
		if ( $key !== '' ) {
			$headers['Authorization'] = 'Bearer ' . $key;
		}

		$args = array(
			'method'      => strtoupper( (string) $method ),
			'timeout'     => 12,
			'redirection' => 0,
			'headers'     => $headers,
		);
		if ( strtoupper( (string) $method ) !== 'GET' ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return self::error_payload(
				'gateway_degraded',
				$response->get_error_message(),
				'Kiểm tra mạng hoặc API key rồi thử lại.',
				'gateway_degraded',
				true
			);
		}

		$http = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		if ( substr( $raw, 0, 3 ) === "\xEF\xBB\xBF" ) {
			$raw = substr( $raw, 3 );
		}

		$decoded = json_decode( trim( $raw ), true );
		if ( ! is_array( $decoded ) ) {
			return self::error_payload(
				'gateway_degraded',
				'Hub trả về dữ liệu không hợp lệ.',
				'Thử lại sau vài phút.',
				'gateway_degraded',
				true
			);
		}

		if ( $http < 200 || $http >= 300 ) {
			if ( ! isset( $decoded['success'] ) ) {
				$decoded['success'] = false;
			}
			$decoded['_degraded'] = true;
			$decoded['http_code'] = $http;
			return $decoded;
		}

		return $decoded;
	}

	/**
	 * Resolve canonical Hub /wp-json base URL.
	 *
	 * @return string
	 */
	private static function get_gateway_base() {
		$base = '';
		if ( class_exists( 'BizCoach_Pro_Astro_Client' ) && method_exists( 'BizCoach_Pro_Astro_Client', 'get_gateway_base' ) ) {
			// [2026-07-10 Johnny Chu] PHASE-C-WOO-HUB — reuse BizCoach canonical gateway resolver.
			$base = (string) BizCoach_Pro_Astro_Client::get_gateway_base();
		}

		if ( $base === '' && class_exists( 'BizCity_LLM_Client' ) ) {
			$llm = BizCity_LLM_Client::instance();
			if ( $llm && method_exists( $llm, 'get_gateway_url' ) ) {
				$base = untrailingslashit( (string) $llm->get_gateway_url() );
				if ( substr( $base, -8 ) !== '/wp-json' ) {
					$base .= '/wp-json';
				}
			}
		}

		$base = untrailingslashit( (string) $base );
		if ( $base !== '' && substr( $base, -8 ) !== '/wp-json' ) {
			$base .= '/wp-json';
		}

		return $base;
	}

	/**
	 * Resolve API key from canonical chain.
	 *
	 * @return string
	 */
	private static function get_api_key() {
		if ( class_exists( 'BizCoach_Pro_Astro_Client' ) && method_exists( 'BizCoach_Pro_Astro_Client', 'get_api_key' ) ) {
			$key = (string) BizCoach_Pro_Astro_Client::get_api_key();
			if ( $key !== '' ) {
				return $key;
			}
		}
		if ( class_exists( 'BizCity_LLM_Client' ) ) {
			$llm = BizCity_LLM_Client::instance();
			if ( $llm && method_exists( $llm, 'get_api_key' ) ) {
				return (string) $llm->get_api_key();
			}
		}
		return '';
	}

	/**
	 * Stable client id for this site.
	 *
	 * @return string
	 */
	private static function get_client_id() {
		$raw = (string) get_site_option( 'bcpro_client_id', '' );
		$id  = strtolower( preg_replace( '/[^a-zA-Z0-9_-]/', '', $raw ) );
		if ( $id !== '' ) {
			return $id;
		}
		return 'site_' . substr( md5( untrailingslashit( (string) home_url() ) ), 0, 12 );
	}

	/**
	 * @param int $user_id
	 * @return array|null
	 */
	private static function read_cached_plan( $user_id ) {
		$data = get_user_meta( (int) $user_id, self::META_PLAN_CACHE, true );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * @param int $user_id
	 * @return array
	 */
	private static function fallback_free_plan( $user_id ) {
		return array(
			'success'    => true,
			'schema'     => self::CACHE_SCHEMA,
			'client_id'  => self::get_client_id(),
			'user_id'    => (int) $user_id,
			'plan_code'  => 'free',
			'tier'       => 'free',
			'features'   => array(),
			'status'     => 'active',
			'starts_at'  => '',
			'expires_at' => '',
			'cached'     => false,
			'_degraded'  => false,
			'source'     => 'fallback',
			'updated_at' => gmdate( 'c' ),
		);
	}

	/**
	 * @param mixed $raw
	 * @return array<string,bool>
	 */
	private static function normalize_features( $raw ) {
		$out = array();
		if ( is_array( $raw ) ) {
			foreach ( $raw as $key => $value ) {
				if ( is_string( $key ) && $key !== '' ) {
					$out[ self::normalize_feature_key( $key ) ] = (bool) $value;
					continue;
				}
				if ( is_string( $value ) && $value !== '' ) {
					$out[ self::normalize_feature_key( $value ) ] = true;
				}
			}
		}

		foreach ( $out as $k => $v ) {
			if ( $k === '' ) {
				unset( $out[ $k ] );
			}
		}
		ksort( $out );
		return $out;
	}

	/**
	 * @param string $key
	 * @return string
	 */
	private static function normalize_feature_key( $key ) {
		$key = strtolower( (string) $key );
		$key = str_replace( ' ', '_', $key );
		$key = preg_replace( '/[^a-z0-9._-]/', '', $key );
		return (string) $key;
	}

	/**
	 * @param string $code
	 * @param string $message
	 * @param string $hint
	 * @param string $help_code
	 * @param bool   $degraded
	 * @return array
	 */
	private static function error_payload( $code, $message, $hint, $help_code, $degraded = false ) {
		$payload = array(
			'success'   => false,
			'code'      => (string) $code,
			'message'   => (string) $message,
			'hint'      => (string) $hint,
			'help_code' => (string) $help_code,
		);
		if ( class_exists( 'BizCity_Error_Payload' ) ) {
			$_helper = BizCity_Error_Payload::make( (string) $code, (string) $message, (string) $hint, (string) $help_code );
			if ( is_array( $_helper ) ) {
				$payload = array_merge( $payload, $_helper );
			}
		}
		if ( $degraded ) {
			$payload['_degraded'] = true;
		}
		return $payload;
	}
}
