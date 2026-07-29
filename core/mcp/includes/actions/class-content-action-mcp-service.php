<?php
/**
 * BizCity_Content_Action_MCP_Service — safe Content Creator draft bridge.
 *
 * Wave M only exposes create_draft. Publish/update tools stay disabled until
 * their active BZCC state transitions have a canonical handler and DDV proof.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\MCP\Actions
 * @since      2026-07-28 (PHASE-0.54-MCP Wave M)
 */

defined( 'ABSPATH' ) || exit;

// [2026-07-28 Johnny Chu] PHASE-0.54-MCP — create-only content Action boundary.
final class BizCity_Content_Action_MCP_Service {

	private static $instance = null;

	public static function instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function create_draft( array $args, array $ctx ) {
		// [2026-07-28 Johnny Chu] PHASE-0.54-MCP — create BZCC pending file through the canonical REST handler.
		$template_id = absint( $args['template_id'] ?? 0 );
		if ( ! $template_id ) {
			return new WP_Error( BizCity_MCP_Error::QUERY_INVALID, 'Thiếu template_id của Content Creator.', array( 'status' => 400 ) );
		}
		$request = new WP_REST_Request( 'POST' );
		$request->set_param( 'template_id', $template_id );
		$request->set_param( 'form_data', is_array( $args['form_data'] ?? null ) ? $args['form_data'] : array() );
		$request->set_param( 'notebook_id', absint( $args['notebook_id'] ?? 0 ) );
		$request->set_param( 'notebook_context', is_array( $args['notebook_context'] ?? null ) ? $args['notebook_context'] : array() );
		$request->set_param( 'title', isset( $args['title'] ) ? (string) $args['title'] : '' );
		$response = $this->call_bzcc( 'start_file', $request, $ctx );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$data = $response->get_data();
		if ( empty( $data['success'] ) || empty( $data['file_id'] ) ) {
			return new WP_Error( BizCity_MCP_Error::INTERNAL_ERROR, 'Content Creator không tạo được draft.', array( 'status' => 500 ) );
		}
		return array_merge(
			array(
				'draft_id'   => absint( $data['file_id'] ),
				'file_id'    => absint( $data['file_id'] ),
				'title'      => (string) ( $data['title'] ?? '' ),
				'status'     => 'pending',
				'launch_url' => (string) ( $data['launch_url'] ?? '' ),
				'plugin'     => 'bizcity-content-creator',
			),
			BizCity_MCP_Action_Confirmation::issue( 'content.publish', (int) $data['file_id'], $ctx )
		);
	}

	public function update_draft( array $args, array $ctx ) {
		// [2026-07-28 Johnny Chu] PHASE-0.54-MCP — edit one completed BZCC chunk through the canonical action handler.
		$file_id  = absint( $args['file_id'] ?? 0 );
		$chunk_id = absint( $args['chunk_id'] ?? 0 );
		$content  = isset( $args['content'] ) ? (string) $args['content'] : '';
		if ( ! $file_id || ! $chunk_id || trim( $content ) === '' ) {
			return new WP_Error( BizCity_MCP_Error::QUERY_INVALID, 'Cần có file_id, chunk_id và content của draft.', array( 'status' => 400 ) );
		}

		$file_request = new WP_REST_Request( 'GET' );
		$file_request->set_param( 'id', $file_id );
		$file_response = $this->call_bzcc( 'get_file', $file_request, $ctx );
		if ( is_wp_error( $file_response ) ) {
			return $file_response;
		}
		$file_data = is_object( $file_response ) && method_exists( $file_response, 'get_data' )
			? (array) $file_response->get_data()
			: array();
		$file = isset( $file_data['file'] ) && is_array( $file_data['file'] ) ? $file_data['file'] : array();
		if ( (int) ( $file['id'] ?? 0 ) !== $file_id ) {
			return new WP_Error( BizCity_MCP_Error::NOT_FOUND, 'Không tìm thấy draft nội dung thuộc MCP user hiện tại.', array( 'status' => 404 ) );
		}
		if ( (string) ( $file['status'] ?? '' ) !== 'completed' ) {
			return new WP_Error( BizCity_MCP_Error::DRAFT_INVALID, 'Draft chỉ được chỉnh sửa sau khi Content Creator hoàn tất sinh nội dung.', array( 'status' => 409 ) );
		}

		$chunk_found = false;
		foreach ( (array) ( $file_data['chunks'] ?? array() ) as $chunk ) {
			if ( is_array( $chunk ) && (int) ( $chunk['id'] ?? 0 ) === $chunk_id ) {
				$chunk_found = true;
				break;
			}
		}
		if ( ! $chunk_found ) {
			return new WP_Error( BizCity_MCP_Error::NOT_FOUND, 'Chunk không thuộc draft nội dung hiện tại.', array( 'status' => 404 ) );
		}

		$chunk_request = new WP_REST_Request( 'POST' );
		$chunk_request->set_param( 'id', $chunk_id );
		$chunk_request->set_param( 'action', 'edit' );
		$chunk_request->set_param( 'content', $content );
		$response = $this->call_bzcc( 'chunk_action', $chunk_request, $ctx );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$data = is_object( $response ) && method_exists( $response, 'get_data' )
			? (array) $response->get_data()
			: array();
		if ( empty( $data['success'] ) ) {
			return new WP_Error( BizCity_MCP_Error::INTERNAL_ERROR, 'Content Creator không lưu được thay đổi draft.', array( 'status' => 500 ) );
		}

		return array_merge(
			array(
				'draft_id' => $file_id,
				'file_id'  => $file_id,
				'chunk_id' => $chunk_id,
				'status'   => 'edited',
				'plugin'   => 'bizcity-content-creator',
			),
			BizCity_MCP_Action_Confirmation::issue( 'content.publish', $file_id, $ctx )
		);
	}

	private function call_bzcc( $method, WP_REST_Request $request, array $ctx ) {
		if ( ! class_exists( 'BZCC_Rest_API' ) || ! method_exists( 'BZCC_Rest_API', $method ) ) {
			return new WP_Error( BizCity_MCP_Error::RENDERER_UNAVAILABLE, 'Content Creator chưa được nạp.', array( 'status' => 503 ) );
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
		if ( is_object( $response ) && method_exists( $response, 'get_status' ) && (int) $response->get_status() >= 400 ) {
			return new WP_Error( BizCity_MCP_Error::INTERNAL_ERROR, 'Content Creator từ chối tạo draft.', array( 'status' => (int) $response->get_status() ) );
		}
		return $response;
	}
}
