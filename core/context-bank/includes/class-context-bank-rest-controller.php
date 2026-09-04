<?php
/**
 * Context Bank metadata-only REST consumer for the MVP canary.
 *
 * @package BizCity_Twin_AI
 * @subpackage Context_Bank
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Context_Bank_REST_Controller', false ) ) {
	return;
}

final class BizCity_Context_Bank_REST_Controller {

	const NS = 'bizcity-context/v1';

	public static function init() {
		// [2026-09-01 Johnny Chu] PHASE-CB-MVP — register only the bounded metadata read surface when the Context Bank REST namespace is requested.
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ), 20 );
	}

	public static function register_routes() {
		// [2026-09-01 Johnny Chu] PHASE-CB-MVP — register bounded metadata routes under the canonical Context Bank namespace.
		register_rest_route( self::NS, '/records', array(
			'methods' => WP_REST_Server::READABLE,
			'callback' => array( __CLASS__, 'get_records' ),
			// [2026-09-03 Johnny Chu - Chu Hoàng Anh] PHASE-CB7.3 — let the handler own the four-field fail-open response after server-side scope validation.
			'permission_callback' => '__return_true',
		) );
		register_rest_route( self::NS, '/records/(?P<record_id>[A-Za-z0-9._-]+)', array(
			'methods' => WP_REST_Server::READABLE,
			'callback' => array( __CLASS__, 'get_record' ),
			// [2026-09-03 Johnny Chu - Chu Hoàng Anh] PHASE-CB7.3 — keep single-record errors consistent with the list handler's server-side authorization boundary.
			'permission_callback' => '__return_true',
		) );
	}

	public static function permission_callback() {
		// [2026-09-01 Johnny Chu] PHASE-CB-MVP — apply the shared authenticated owner/admin gate to every metadata route.
		return BizCity_Context_Bank_Access::is_allowed_request();
	}

	public static function get_records( WP_REST_Request $request ) {
		// [2026-09-01 Johnny Chu] PHASE-CB-MVP — return bounded ledger metadata after server-side owner scoping.
		$filters = self::request_filters( $request );
		$scope = BizCity_Context_Bank_Access::scope_filters( $filters );
		if ( empty( $scope['ok'] ) ) {
			return self::error( 'permission_denied', 'Bạn không có quyền xem Context Bank này.', 'Đăng nhập đúng tài khoản hoặc liên hệ quản trị viên.', 'permission_denied' );
		}
		if ( ! class_exists( 'BizCity_Context_Bank_Search' ) ) {
			// [2026-09-02 06:05 AM Johnny Chu - Chu Hoàng Anh] PHASE-BRAND — standardize the user-facing product name.
			return self::error( 'module_not_loaded', 'Context Bank chưa sẵn sàng.', 'Kiểm tra plugin BizCity Twin Brain và thử lại.', 'module_not_loaded' );
		}
		// [2026-09-02 01:20 PM Johnny Chu - Chu Hoàng Anh] PHASE-CB7.2 — pass only the server-authorized scope filters into the bounded search owner.
		try {
			$result = BizCity_Context_Bank_Search::search( $scope['filters'], (string) $request->get_param( 'cursor' ), 20, 200 );
		} catch ( \Throwable $e ) {
			// [2026-09-03 Johnny Chu - Chu Hoàng Anh] PHASE-CB7.3 — convert bounded search exceptions into the canonical REST error envelope.
			return self::error( 'retrieval_error', 'Context Bank không thể đọc dữ liệu lúc này.', 'Kiểm tra tenant và chạy lại chẩn đoán Context Bank.', 'retrieval_error' );
		}
		if ( empty( $result['ok'] ) ) {
			return self::error( 'retrieval_error', 'Context Bank không thể đọc dữ liệu lúc này.', 'Kiểm tra tenant và chạy lại chẩn đoán Context Bank.', 'retrieval_error' );
		}
		$public_rows = array();
		foreach ( (array) ( $result['rows'] ?? array() ) as $row ) {
			if ( is_array( $row ) ) {
				$public_rows[] = self::public_row( $row );
			}
		}
		return new WP_REST_Response( array( 'ok' => true, 'rows' => $public_rows, 'next_cursor' => (string) ( $result['next_cursor'] ?? '' ), 'truncated' => ! empty( $result['truncated'] ), 'matched_count' => (int) ( $result['matched_count'] ?? 0 ), 'returned_count' => (int) ( $result['returned_count'] ?? 0 ), 'pointer_follows' => (int) ( $result['pointer_follows'] ?? 0 ), 'incomplete' => ! empty( $result['incomplete'] ), 'degraded' => ! empty( $result['degraded'] ), 'reason_bucket' => (string) ( $result['reason_bucket'] ?? '' ), 'scope' => (string) $scope['scope'] ), 200 );
	}

	public static function get_record( WP_REST_Request $request ) {
		// [2026-09-01 Johnny Chu] PHASE-CB-MVP — verify one authorized pointer while keeping protected payload fields server-side.
		$record_id = sanitize_text_field( (string) $request->get_param( 'record_id' ) );
		$scope = BizCity_Context_Bank_Access::scope_filters( array( 'record_id' => $record_id ) );
		if ( empty( $scope['ok'] ) ) {
			return self::error( 'permission_denied', 'Bạn không có quyền xem Context Bank này.', 'Đăng nhập đúng tài khoản hoặc liên hệ quản trị viên.', 'permission_denied' );
		}
		if ( ! class_exists( 'BizCity_Context_Bank_Ledger' ) || ! method_exists( 'BizCity_Context_Bank_Ledger', 'instance' ) ) {
			// [2026-09-03 Johnny Chu - Chu Hoàng Anh] PHASE-CB7.3 — fail gracefully when the metadata owner is not loaded.
			return self::error( 'module_not_loaded', 'Context Bank chưa sẵn sàng.', 'Kiểm tra plugin BizCity Twin Brain và thử lại.', 'module_not_loaded' );
		}
		try {
			$ledger = BizCity_Context_Bank_Ledger::instance();
			if ( ! is_object( $ledger ) || ! method_exists( $ledger, 'find' ) || ! method_exists( $ledger, 'follow' ) ) {
				return self::error( 'module_not_loaded', 'Context Bank chưa sẵn sàng.', 'Kiểm tra plugin BizCity Twin Brain và thử lại.', 'module_not_loaded' );
			}
			$rows = $ledger->find( $scope['filters'] );
		} catch ( \Throwable $e ) {
			// [2026-09-03 Johnny Chu - Chu Hoàng Anh] PHASE-CB7.3 — prevent ledger exceptions from escaping the same-origin REST boundary.
			return self::error( 'retrieval_error', 'Bản ghi Context Bank chưa xác minh được nguồn.', 'Chạy reconcile bounded và thử lại sau.', 'retrieval_error' );
		}
		if ( empty( $rows[0] ) ) {
			return self::error( 'not_found', 'Không tìm thấy bản ghi Context Bank.', 'Kiểm tra mã bản ghi và tenant hiện tại.', 'not_found' );
		}
		try {
			$follow = $ledger->follow( $record_id, $scope['filters'] );
		} catch ( \Throwable $e ) {
			// [2026-09-03 Johnny Chu - Chu Hoàng Anh] PHASE-CB7.3 — keep pointer-follow failure inside the canonical REST error envelope.
			return self::error( 'retrieval_error', 'Bản ghi Context Bank chưa xác minh được nguồn.', 'Chạy reconcile bounded và thử lại sau.', 'retrieval_error' );
		}
		if ( empty( $follow['ok'] ) ) {
			return self::error( 'retrieval_error', 'Bản ghi Context Bank chưa xác minh được nguồn.', 'Chạy reconcile bounded và thử lại sau.', 'retrieval_error' );
		}
		return new WP_REST_Response( array( 'ok' => true, 'record' => self::public_row( $rows[0] ), 'verified' => true, 'scope' => (string) $scope['scope'] ), 200 );
	}

	private static function request_filters( WP_REST_Request $request ) {
		// [2026-09-01 Johnny Chu] PHASE-CB-MVP — accept only the typed bounded ledger filter allowlist.
		$filters = array();
		$string_fields = array( 'source_contract_id', 'record_kind', 'identity_uuid', 'scope_key', 'entity_type', 'entity_key', 'lifecycle_status', 'kg_status', 'trace_id', 'date_from', 'date_to' );
		foreach ( $string_fields as $field ) {
			$value = sanitize_text_field( (string) $request->get_param( $field ) );
			if ( $value !== '' ) {
				$filters[ $field ] = $value;
			}
		}
		foreach ( array( 'wp_user_id', 'contact_id', 'conversation_id', 'notebook_id' ) as $field ) {
			$value = absint( $request->get_param( $field ) );
			if ( $value > 0 ) {
				$filters[ $field ] = $value;
			}
		}
		$filters['limit'] = min( 100, max( 1, absint( $request->get_param( 'limit' ) ) ?: 50 ) );
		return $filters;
	}

	private static function public_row( array $row ) {
		// [2026-09-01 Johnny Chu] PHASE-CB-MVP — project metadata only; omit encrypted token, absolute path, offset and hashes.
		return array(
			'id' => (int) ( $row['id'] ?? 0 ),
			'blog_id' => (int) ( $row['blog_id'] ?? 0 ),
			'record_id' => (string) ( $row['record_id'] ?? '' ),
			'event_uuid' => (string) ( $row['event_uuid'] ?? '' ),
			'source_contract_id' => (string) ( $row['source_contract_id'] ?? '' ),
			'contract_version' => (string) ( $row['contract_version'] ?? '' ),
			'record_kind' => (string) ( $row['record_kind'] ?? '' ),
			'identity_uuid' => (string) ( $row['identity_uuid'] ?? '' ),
			'wp_user_id' => (int) ( $row['wp_user_id'] ?? 0 ),
			'contact_id' => (int) ( $row['contact_id'] ?? 0 ),
			'conversation_id' => (int) ( $row['conversation_id'] ?? 0 ),
			'entity_type' => (string) ( $row['entity_type'] ?? '' ),
			'entity_key' => (string) ( $row['entity_key'] ?? '' ),
			'scope_key' => (string) ( $row['scope_key'] ?? '' ),
			'lifecycle_status' => (string) ( $row['lifecycle_status'] ?? '' ),
			'kg_status' => (string) ( $row['kg_status'] ?? '' ),
			'provenance_ref' => (string) ( $row['provenance_ref'] ?? '' ),
			'occurred_at' => (string) ( $row['occurred_at'] ?? '' ),
			'indexed_at' => (string) ( $row['indexed_at'] ?? '' ),
		);
	}

	private static function error( $code, $message, $hint, $help_code ) {
		// [2026-09-01 Johnny Chu] PHASE-CB-MVP — keep visible REST failures in the canonical four-field error envelope.
		return new WP_REST_Response( BizCity_Error_Payload::make( $code, $message, $hint, $help_code ), 200 );
	}
}