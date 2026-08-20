<?php
/**
 * Profile to Page Builder bridge.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Plugins\BizCityProfile
 */
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Personal_Profile_BZPB_Bridge' ) ) { return; }

final class BizCity_Personal_Profile_BZPB_Bridge {

	public static function create_project( array $config, $title ) {
		// [2026-08-20 Johnny Chu] PHASE-PROFILE-QR — clone a local Profile template through the canonical BZPB save route.
		$request = new WP_REST_Request( 'POST', '/bzpb/v1/save' );
		$request->set_param( 'project_id', 0 );
		$request->set_param( 'title', sanitize_text_field( $title ) );
		$request->set_param( 'config', $config );
		$request->set_header( 'x-trace-id', wp_generate_uuid4() );
		$request->set_header( 'x-idempotency-key', 'profile_create_' . wp_generate_uuid4() );
		$response = rest_do_request( $request );
		if ( is_wp_error( $response ) ) { return $response; }
		$data = is_object( $response ) && method_exists( $response, 'get_data' ) ? $response->get_data() : array();
		if ( ! is_array( $data ) || empty( $data['success'] ) || empty( $data['project_id'] ) ) {
			return new WP_Error( 'db_error', 'Không thể tạo project Page Builder cho Profile.', array( 'status' => 400 ) );
		}
		return array( 'project_id' => (int) $data['project_id'], 'data' => $data );
	}

	public static function get_project_config( $project_id, $user_id ) {
		// [2026-08-20 Johnny Chu] PHASE-PROFILE-QR — read Page Builder config only inside the current owner's project boundary.
		global $wpdb;
		$project_table = $wpdb->prefix . 'bzpb_projects';
		if ( ! function_exists( 'bizcity_tbl_exists' ) || ! bizcity_tbl_exists( $project_table ) ) {
			return new WP_Error( 'module_not_loaded', 'Page Builder project store chưa sẵn sàng.', array( 'status' => 503 ) );
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id, title, site_config FROM `' . $project_table . '` WHERE id = %d AND user_id = %d LIMIT 1', (int) $project_id, (int) $user_id ) );
		if ( ! $row ) { return new WP_Error( 'not_found', 'Không tìm thấy project Page Builder.', array( 'status' => 404 ) ); }
		$config = json_decode( (string) $row->site_config, true );
		if ( ! is_array( $config ) ) { return new WP_Error( 'invalid_config', 'SiteConfig của project không hợp lệ.', array( 'status' => 400 ) ); }
		return array( 'id' => (int) $row->id, 'title' => (string) $row->title, 'config' => $config );
	}

	public static function save_project_config( $project_id, $user_id, array $config, $title = '' ) {
		// [2026-08-20 Johnny Chu] PHASE-PROFILE-QR — persist SiteConfig through BZPB save with mutation correlation.
		$project = self::get_project_config( $project_id, $user_id );
		if ( is_wp_error( $project ) ) { return $project; }
		$request = new WP_REST_Request( 'POST', '/bzpb/v1/save' );
		$request->set_param( 'project_id', (int) $project_id );
		$request->set_param( 'title', '' !== (string) $title ? sanitize_text_field( $title ) : $project['title'] );
		$request->set_param( 'config', $config );
		$request->set_header( 'x-trace-id', wp_generate_uuid4() );
		$request->set_header( 'x-idempotency-key', 'profile_save_' . (int) $project_id . '_' . wp_generate_uuid4() );
		$response = rest_do_request( $request );
		if ( is_wp_error( $response ) ) { return $response; }
		$data = is_object( $response ) && method_exists( $response, 'get_data' ) ? $response->get_data() : array();
		return is_array( $data ) && ! empty( $data['success'] )
			? $data
			: new WP_Error( 'db_error', 'Không thể lưu cấu hình Page Builder.', array( 'status' => 400 ) );
	}

	public static function publish( $project_id, $card_id ) {
		// [2026-08-20 Johnny Chu] PHASE-PROFILE-QR — publish through BZPB with mutation correlation headers.
		$project_id = (int) $project_id;
		$card_id    = (int) $card_id;
		if ( $project_id <= 0 || $card_id <= 0 ) {
			return new WP_Error( 'invalid_param', 'Thiếu project hoặc danh thiếp Profile.', array( 'status' => 400 ) );
		}
		if ( ! class_exists( 'BZPB_Rest_API' ) ) {
			return new WP_Error( 'module_not_loaded', 'Page Builder chưa sẵn sàng.', array( 'status' => 503 ) );
		}

		$request = new WP_REST_Request( 'POST', '/bzpb/v1/publish' );
		$request->set_param( 'project_id', $project_id );
		$request->set_header( 'x-trace-id', wp_generate_uuid4() );
		$request->set_header( 'x-idempotency-key', 'profile_publish_' . $card_id . '_' . wp_generate_uuid4() );
		$response = rest_do_request( $request );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( ! is_object( $response ) || ! method_exists( $response, 'get_data' ) ) {
			return new WP_Error( 'gateway_degraded', 'Page Builder không trả về kết quả publish hợp lệ.', array( 'status' => 502 ) );
		}
		$data = $response->get_data();
		if ( ! is_array( $data ) || empty( $data['success'] ) ) {
			return new WP_Error( 'publish_failed', 'Không thể publish trang Profile.', array( 'status' => 400 ) );
		}
		return $data;
	}
}
