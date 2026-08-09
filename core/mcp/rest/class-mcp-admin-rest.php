<?php
/**
 * BizCity_MCP_Admin_REST — Channel Gateway admin API for local MCP keys.
 *
 * This surface is same-origin and WordPress-admin-only. It never returns a
 * stored hash; the plaintext key is returned only by the issue response.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\MCP
 */

defined( 'ABSPATH' ) || exit;

// [2026-07-28 Johnny Chu] PHASE-0.53-MCP — add admin REST contract for Channel Gateway key management.
final class BizCity_MCP_Admin_REST {

	const NS = 'bizcity-channel/v1';
	const KEY_PATH = '/mcp/keys';

	public static function init() {
		// [2026-07-28 Johnny Chu] PHASE-0.53-MCP — register same-origin admin routes once after class load.
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		// [2026-07-28 Johnny Chu] PHASE-0.53-MCP — expose list/issue/revoke without exposing key hashes.
		$permission = array( __CLASS__, 'can_manage' );
		register_rest_route( self::NS, self::KEY_PATH, array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'list_keys' ),
				'permission_callback' => $permission,
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'issue_key' ),
				'permission_callback' => $permission,
			),
		) );
		register_rest_route( self::NS, self::KEY_PATH . '/(?P<id>\d+)', array(
			'methods'             => WP_REST_Server::DELETABLE,
			'callback'            => array( __CLASS__, 'revoke_key' ),
			'permission_callback' => $permission,
		) );
		register_rest_route( self::NS, '/mcp/logs', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'list_logs' ),
			'permission_callback' => $permission,
		) );
		// [2026-07-30 Johnny Chu] PHASE-0.54-MCP Wave Q — admin allowlist catalog for the MCP Access settings screen.
		register_rest_route( self::NS, '/mcp/tools', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'list_tools' ),
				'permission_callback' => $permission,
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'update_tools' ),
				'permission_callback' => $permission,
			),
		) );
	}

	public static function can_manage() {
		// [2026-07-28 Johnny Chu] PHASE-0.53-MCP — key material is restricted to site administrators.
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		// [2026-07-28 Johnny Chu] R-ERROR-UX — permission failures carry an actionable catalog payload.
		return self::error( 'permission_denied', 'Bạn không có quyền quản lý MCP API key.', 'Đăng nhập bằng tài khoản quản trị để tiếp tục.', 'permission_denied', 403 );
	}

	public static function list_keys( WP_REST_Request $request ) {
		// [2026-07-28 Johnny Chu] PHASE-0.53-MCP — return metadata only; key_hash is never serialized.
		global $wpdb;
		$rows = $wpdb->get_results(
			'SELECT id, client_id, client_name, user_id, scopes, allowed_notebook_ids, status, created_at, last_used_at, revoked_at FROM ' . BizCity_MCP_Auth::tbl() . ' ORDER BY id DESC LIMIT 200',
			ARRAY_A
		) ?: array();
		return rest_ensure_response( array( 'success' => true, 'keys' => array_map( array( __CLASS__, 'present_key' ), $rows ) ) );
	}

	public static function issue_key( WP_REST_Request $request ) {
		// [2026-07-28 Johnny Chu] PHASE-0.53-MCP — validate admin-issued scope and bind key ownership explicitly.
		$client_id   = sanitize_key( (string) $request->get_param( 'client_id' ) );
		$client_name = sanitize_text_field( (string) $request->get_param( 'client_name' ) );
		$user_id     = (int) $request->get_param( 'user_id' );
		$scopes      = self::normalize_scopes( $request->get_param( 'scopes' ) );
		$notebooks   = array_values( array_unique( array_filter( array_map( 'intval', (array) $request->get_param( 'allowed_notebook_ids' ) ) ) ) );
		if ( $client_id === '' || $client_name === '' ) {
			return self::error( 'invalid_param', 'Thiếu client ID hoặc tên client.', 'Nhập đủ client ID và tên client rồi thử lại.', 'invalid_param_generic', 400 );
		}
		if ( $user_id <= 0 ) {
			$user_id = get_current_user_id();
		}
		if ( $user_id <= 0 || ! get_user_by( 'id', $user_id ) ) {
			return self::error( 'invalid_param', 'User ID không hợp lệ.', 'Kiểm tra user ID trước khi cấp key.', 'invalid_param_generic', 400 );
		}
		if ( empty( $scopes ) ) {
			return self::error( 'invalid_param', 'Scope MCP không hợp lệ.', 'Chọn ít nhất một scope được hỗ trợ rồi thử lại.', 'invalid_param_generic', 400 );
		}
		$plain = BizCity_MCP_Auth::issue_key( $client_id, $client_name, $user_id, $scopes, $notebooks );
		if ( is_wp_error( $plain ) ) {
			return self::error( 'gateway_degraded', 'Không thể tạo MCP API key.', 'Kiểm tra database và thử lại; liên hệ quản trị nếu lỗi tiếp diễn.', 'gateway_degraded', 500 );
		}
		return rest_ensure_response( array(
			'success' => true,
			'key'     => $plain,
			'warning' => 'Lưu key ngay bây giờ. Plaintext chỉ xuất hiện một lần và không thể khôi phục.',
		) );
	}

	public static function revoke_key( WP_REST_Request $request ) {
		// [2026-07-28 Johnny Chu] PHASE-0.53-MCP — revoke one key row by immutable numeric id, never by client_id wildcard.
		global $wpdb;
		$id      = (int) $request['id'];
		$updated = $wpdb->update(
			BizCity_MCP_Auth::tbl(),
			array( 'status' => 'revoked', 'revoked_at' => current_time( 'mysql' ) ),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		if ( false === $updated ) {
			return self::error( 'gateway_degraded', 'Không thể thu hồi MCP API key.', 'Kiểm tra database và thử lại; liên hệ quản trị nếu lỗi tiếp diễn.', 'gateway_degraded', 500 );
		}
		if ( 0 === $updated ) {
			return self::error( 'not_found', 'MCP API key không tồn tại hoặc đã thu hồi.', 'Kiểm tra ID key và tải lại danh sách.', 'not_found', 404 );
		}
		return rest_ensure_response( array( 'success' => true, 'id' => $id, 'status' => 'revoked' ) );
	}

	public static function list_logs( WP_REST_Request $request ) {
		// [2026-07-28 Johnny Chu] PHASE-0.53-MCP-TWINWEB — admin can audit JSONL evidence by user, key, or client.
		$user_id = absint( $request->get_param( 'user_id' ) );
		$key_id = absint( $request->get_param( 'key_id' ) );
		$client_id = sanitize_key( (string) $request->get_param( 'client_id' ) );
		$limit = min( 500, max( 1, absint( $request->get_param( 'limit' ) ?: 200 ) ) );
		$logs = class_exists( 'BizCity_MCP_File_Logger' ) ? BizCity_MCP_File_Logger::read_recent( $user_id, $key_id, $client_id, $limit ) : array();
		return rest_ensure_response( array( 'success' => true, 'logs' => $logs, 'filters' => array( 'user_id' => $user_id, 'key_id' => $key_id, 'client_id' => $client_id ), 'storage' => 'uploads/sites/{blog_id}/bizcity-mcp-logs/' ) );
	}

	private static function normalize_scopes( $raw ) {
		$allowed = array( 'brain.read', 'document.context.build', 'document.validate', 'document.render.docx', 'document.render.pptx' );
		// [2026-07-28 Johnny Chu] PHASE-0.54-MCP — expose page scopes only when the opt-in Action wave is enabled.
		if ( defined( 'BIZCITY_MCP_PAGE_TOOLS_ENABLED' ) && BIZCITY_MCP_PAGE_TOOLS_ENABLED ) {
			$allowed = array_merge( $allowed, array( 'page.read', 'page.write', 'page.publish' ) );
		}
		// [2026-07-28 Johnny Chu] PHASE-0.54-MCP — business.read is read-only and opt-in with the source bridges.
		if ( defined( 'BIZCITY_MCP_BUSINESS_TOOLS_ENABLED' ) && BIZCITY_MCP_BUSINESS_TOOLS_ENABLED ) {
			$allowed[] = 'business.read';
		}
 		// [2026-07-28 Johnny Chu] PHASE-0.54-MCP — content.read is limited to active BZCC read tools in Wave M.
		if ( defined( 'BIZCITY_MCP_CONTENT_TOOLS_ENABLED' ) && BIZCITY_MCP_CONTENT_TOOLS_ENABLED ) {
			$allowed[] = 'content.read';
		}
		// [2026-07-28 Johnny Chu] PHASE-0.54-MCP — content.write only covers create_draft until publish/update are implemented.
		if ( defined( 'BIZCITY_MCP_CONTENT_ACTION_TOOLS_ENABLED' ) && BIZCITY_MCP_CONTENT_ACTION_TOOLS_ENABLED ) {
			$allowed[] = 'content.write';
		}
		// [2026-07-28 Johnny Chu] PHASE-0.54-MCP — report.read is read-only and opt-in.
		if ( defined( 'BIZCITY_MCP_REPORT_TOOLS_ENABLED' ) && BIZCITY_MCP_REPORT_TOOLS_ENABLED ) {
			$allowed[] = 'report.read';
		}
		// [2026-07-30 Johnny Chu] PHASE-0.54-MCP Wave R — commerce.read is read-only WooCommerce data and opt-in with the bridge wave.
		if ( defined( 'BIZCITY_MCP_COMMERCE_TOOLS_ENABLED' ) && BIZCITY_MCP_COMMERCE_TOOLS_ENABLED ) {
			$allowed[] = 'commerce.read';
		}
		$scopes  = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', (array) $raw ) ) ) );
		return array_values( array_intersect( $scopes, $allowed ) );
	}

	public static function list_tools( WP_REST_Request $request ) {
		// [2026-07-30 Johnny Chu] PHASE-0.54-MCP Wave Q — full tool catalog + current admin policy state for the checkbox UI.
		if ( ! class_exists( 'BizCity_MCP_Tool_Registry' ) ) {
			return rest_ensure_response( array( 'success' => true, 'tools' => array(), '_degraded' => true ) );
		}
		return rest_ensure_response( array( 'success' => true, 'tools' => BizCity_MCP_Tool_Registry::catalog_for_settings() ) );
	}

	public static function update_tools( WP_REST_Request $request ) {
		// [2026-07-30 Johnny Chu] PHASE-0.54-MCP Wave Q — persist the admin's enabled-tool selection; unknown tool names are silently dropped.
		if ( ! class_exists( 'BizCity_MCP_Tool_Registry' ) || ! class_exists( 'BizCity_MCP_Tool_Policy' ) ) {
			return self::error( 'module_not_loaded', 'MCP tool registry chưa sẵn sàng.', 'Kiểm tra Diagnostics → core.mcp.gateway rồi thử lại.', 'module_not_loaded', 500 );
		}
		$enabled = $request->get_param( 'enabled_tools' );
		if ( ! is_array( $enabled ) ) {
			return self::error( 'invalid_param', 'Thiếu danh sách enabled_tools.', 'Gửi mảng enabled_tools (tên các tool được bật) rồi thử lại.', 'invalid_param_generic', 400 );
		}
		$enabled = array_map( 'sanitize_text_field', $enabled );
		$all     = BizCity_MCP_Tool_Registry::all_registered_tool_names();
		$saved   = BizCity_MCP_Tool_Policy::save( $enabled, $all );
		return rest_ensure_response( array( 'success' => true, 'tools' => BizCity_MCP_Tool_Registry::catalog_for_settings(), 'saved_count' => count( array_filter( $saved ) ) ) );
	}

	private static function present_key( $row ) {
		return array(
			'id'                     => (int) $row['id'],
			'client_id'              => (string) $row['client_id'],
			'client_name'            => (string) $row['client_name'],
			'user_id'                => (int) $row['user_id'],
			'scopes'                 => self::decode_list( $row['scopes'] ),
			'allowed_notebook_ids'   => array_map( 'intval', self::decode_list( $row['allowed_notebook_ids'] ) ),
			'status'                 => (string) $row['status'],
			'created_at'             => (string) $row['created_at'],
			'last_used_at'           => (string) $row['last_used_at'],
			'revoked_at'             => (string) $row['revoked_at'],
		);
	}

	private static function decode_list( $json ) {
		$decoded = json_decode( (string) $json, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	private static function error( $code, $message, $hint, $help_code, $status ) {
		// [2026-07-28 Johnny Chu] R-ERROR-UX — keep admin REST errors parseable by the Channel Gateway SPA.
		return new WP_Error( (string) $code, (string) $message, array(
			'status'    => (int) $status,
			'hint'      => (string) $hint,
			'help_code' => (string) $help_code,
		) );
	}
}
