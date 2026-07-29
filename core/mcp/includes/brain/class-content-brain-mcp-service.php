<?php
/**
 * BizCity_Content_Brain_MCP_Service — read-only bridge to Content Creator.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\MCP\Brain
 * @since      2026-07-28 (PHASE-0.54-MCP Wave M)
 */

defined( 'ABSPATH' ) || exit;

// [2026-07-28 Johnny Chu] PHASE-0.54-MCP — content read tools reuse active BZCC ownership guards.
final class BizCity_Content_Brain_MCP_Service {

	private static $instance = null;

	public static function instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function list_posts( array $args, array $ctx ) {
		// [2026-07-28 Johnny Chu] PHASE-0.54-MCP — list only the authenticated user's Content Creator files.
		$request = new WP_REST_Request( 'GET' );
		$request->set_param( 'status', isset( $args['status'] ) ? (string) $args['status'] : '' );
		$request->set_param( 'search', isset( $args['search'] ) ? (string) $args['search'] : '' );
		$request->set_param( 'limit', isset( $args['limit'] ) ? (int) $args['limit'] : 50 );
		$request->set_param( 'offset', isset( $args['offset'] ) ? (int) $args['offset'] : 0 );
		return $this->call_bzcc( 'list_files', $request, $ctx );
	}

	public function get_post( array $args, array $ctx ) {
		// [2026-07-28 Johnny Chu] PHASE-0.54-MCP — retrieve one owned Content Creator file through BZCC.
		$file_id = absint( $args['file_id'] ?? $args['id'] ?? 0 );
		if ( ! $file_id ) {
			return new WP_Error( BizCity_MCP_Error::QUERY_INVALID, 'Thiếu file_id của nội dung.', array( 'status' => 400 ) );
		}
		$request = new WP_REST_Request( 'GET' );
		$request->set_param( 'id', $file_id );
		return $this->call_bzcc( 'get_file', $request, $ctx );
	}

	public function get_templates( array $args, array $ctx ) {
		// [2026-07-28 Johnny Chu] PHASE-0.54-MCP — expose active BZCC templates without mutating library state.
		$request = new WP_REST_Request( 'GET' );
		$request->set_param( 'category', isset( $args['category'] ) ? (string) $args['category'] : '' );
		$request->set_param( 'search', isset( $args['search'] ) ? (string) $args['search'] : '' );
		$request->set_param( 'limit', isset( $args['limit'] ) ? (int) $args['limit'] : 100 );
		return $this->call_bzcc( 'list_templates', $request, $ctx );
	}

	private function call_bzcc( $method, WP_REST_Request $request, array $ctx ) {
		if ( ! class_exists( 'BZCC_Rest_API' ) || ! method_exists( 'BZCC_Rest_API', $method ) ) {
			return array( '_degraded' => true, 'reason' => 'content_creator_unavailable', 'items' => array() );
		}
		$user_id = (int) ( $ctx['user_id'] ?? 0 );
		if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
			return new WP_Error( BizCity_MCP_Error::AUTH_INVALID, 'MCP user context không hợp lệ.', array( 'status' => 401 ) );
		}
		$previous_user = get_current_user_id();
		wp_set_current_user( $user_id );
		try {
			$response = call_user_func( array( 'BZCC_Rest_API', $method ), $request );
		} finally {
			wp_set_current_user( $previous_user );
		}
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( is_object( $response ) && method_exists( $response, 'get_data' ) ) {
			$status = method_exists( $response, 'get_status' ) ? (int) $response->get_status() : 200;
			if ( $status >= 400 ) {
				$code = $status === 403 ? BizCity_MCP_Error::SCOPE_DENIED : ( $status === 404 ? BizCity_MCP_Error::NOT_FOUND : BizCity_MCP_Error::INTERNAL_ERROR );
				return new WP_Error( $code, 'Content Creator từ chối hoặc không tìm thấy dữ liệu.', array( 'status' => $status ) );
			}
			return (array) $response->get_data();
		}
		return is_array( $response ) ? $response : array( 'data' => $response );
	}
}
