<?php
/**
 * BizCity Journal REST API.
 *
 * Namespace intentionally stays beside the legacy skill API while the public
 * product label changes. Internal skill identifiers remain stable.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Skills
 * @since 2026-08-02
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( class_exists( 'BizCity_Journal_REST_API', false ) ) {
	return;
}

final class BizCity_Journal_REST_API {

	const API_NAMESPACE = 'bizcity/skill/v1';
	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route( self::API_NAMESPACE, '/journal/entries', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_entries' ),
				'permission_callback' => array( $this, 'check_logged_in' ),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_entry' ),
				'permission_callback' => array( $this, 'check_logged_in' ),
			),
		) );

		register_rest_route( self::API_NAMESPACE, '/journal/entries/(?P<id>\d+)', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_entry' ),
				'permission_callback' => array( $this, 'check_logged_in' ),
			),
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'update_entry' ),
				'permission_callback' => array( $this, 'check_logged_in' ),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'archive_entry' ),
				'permission_callback' => array( $this, 'check_logged_in' ),
			),
		) );
	}

	public function check_logged_in(): bool {
		return is_user_logged_in();
	}

	public function list_entries( WP_REST_Request $request ): WP_REST_Response {
		// [2026-08-02 Johnny Chu] PHASE-SKILLS-JOURNAL — list only owned
		// entries unless an administrator explicitly requests the shared view.
		$db = $this->database();
		$all_param = $request->get_param( 'all' );
		$all = current_user_can( 'manage_options' ) && ( $all_param === true || $all_param === 1 || $all_param === '1' || $all_param === 'true' );
		$entries = $db->list_entries(
			get_current_user_id(),
			sanitize_key( (string) $request->get_param( 'workspace_id' ) ),
			(int) ( $request->get_param( 'limit' ) ?: 50 ),
			(int) ( $request->get_param( 'offset' ) ?: 0 ),
			$all
		);
		return new WP_REST_Response( array( 'success' => true, 'entries' => $this->normalize_entries( $entries ) ), 200 );
	}

	public function create_entry( WP_REST_Request $request ): WP_REST_Response {
		// [2026-08-02 Johnny Chu] PHASE-SKILLS-JOURNAL — owner identity comes
		// from WordPress auth; client-supplied owner_user_id is ignored.
		$body = $request->get_json_params() ?: array();
		$db = $this->database();
		$result = $db->create( array(
			'owner_user_id'   => get_current_user_id(),
			'workspace_id'    => $body['workspace_id'] ?? 'notion',
			'title'           => $body['title'] ?? '',
			'body'            => $body['body'] ?? '',
			'status'          => $body['status'] ?? 'draft',
			'source_type'     => $body['source_type'] ?? 'menu',
			'source_ref'      => $body['source_ref'] ?? '',
			'idempotency_key' => $body['idempotency_key'] ?? '',
			'metadata'        => $body['metadata'] ?? array(),
		) );
		if ( is_wp_error( $result ) ) {
			return $this->error_response( $result, 'Kiểm tra lại tiêu đề và nội dung nhật ký.', 'invalid_param', 'invalid_param_generic' );
		}
		return new WP_REST_Response( array( 'success' => true, 'entry' => $this->normalize_entry( $result ) ), 201 );
	}

	public function get_entry( WP_REST_Request $request ): WP_REST_Response {
		// [2026-08-02 Johnny Chu] PHASE-SKILLS-JOURNAL — ownership is part
		// of lookup, so another user receives the same not-found boundary.
		$db = $this->database();
		$all = current_user_can( 'manage_options' );
		$entry = $db->get( (int) $request['id'], get_current_user_id(), $all );
		if ( ! $entry ) {
			return $this->error_response( new WP_Error( 'not_found', 'Không tìm thấy nhật ký.' ), 'Mở lại danh sách nhật ký và thử mục khác.', 'not_found' );
		}
		return new WP_REST_Response( array( 'success' => true, 'entry' => $this->normalize_entry( $entry ) ), 200 );
	}

	public function update_entry( WP_REST_Request $request ): WP_REST_Response {
		$body = $request->get_json_params() ?: array();
		$db = $this->database();
		$result = $db->update( (int) $request['id'], get_current_user_id(), $body, current_user_can( 'manage_options' ) );
		if ( is_wp_error( $result ) ) {
			return $this->error_response( $result, 'Kiểm tra quyền sở hữu và thử lưu lại.', 'not_found' );
		}
		return new WP_REST_Response( array( 'success' => true, 'entry' => $this->normalize_entry( $result ) ), 200 );
	}

	public function archive_entry( WP_REST_Request $request ): WP_REST_Response {
		$db = $this->database();
		$result = $db->archive( (int) $request['id'], get_current_user_id(), current_user_can( 'manage_options' ) );
		if ( is_wp_error( $result ) ) {
			return $this->error_response( $result, 'Kiểm tra quyền sở hữu và thử lại.', 'not_found' );
		}
		return new WP_REST_Response( array( 'success' => true, 'entry' => $this->normalize_entry( $result ) ), 200 );
	}

	private function normalize_entries( array $entries ): array {
		return array_map( array( $this, 'normalize_entry' ), $entries );
	}

	private function normalize_entry( array $entry ): array {
		$entry['id'] = (int) ( $entry['id'] ?? 0 );
		$entry['owner_user_id'] = (int) ( $entry['owner_user_id'] ?? 0 );
		$entry['revision'] = (int) ( $entry['revision'] ?? 1 );
		$entry['notebook_id'] = (int) ( $entry['notebook_id'] ?? 0 );
		$entry['kg_source_id'] = (int) ( $entry['kg_source_id'] ?? 0 );
		$entry['metadata'] = ! empty( $entry['metadata'] ) ? ( json_decode( (string) $entry['metadata'], true ) ?: array() ) : array();
		return $entry;
	}

	private function error_response( WP_Error $error, string $hint, string $fallback_code, string $help_code = '' ): WP_REST_Response {
		$help_code = $help_code !== '' ? $help_code : $fallback_code;
		if ( class_exists( 'BizCity_Error_Payload' ) ) {
			// [2026-08-02 Johnny Chu] PHASE-SKILLS-JOURNAL — expose only
			// cataloged user-facing codes; keep storage codes internal.
			$payload = BizCity_Error_Payload::make( $fallback_code, $error->get_error_message(), $hint, $help_code );
		} else {
			$payload = array(
				'success'   => false,
				'_degraded' => true,
				'code'      => $fallback_code,
				'message'   => $error->get_error_message(),
				'hint'      => $hint,
				'help_code' => $help_code,
			);
		}
		return new WP_REST_Response( $payload, 200 );
	}

	private function database(): BizCity_Journal_Database {
		// [2026-08-02 Johnny Chu] PHASE-SKILLS-JOURNAL — provision only when
		// a Journal endpoint is used; ordinary REST requests stay DDL-free.
		BizCity_Journal_Database::maybe_install();
		return BizCity_Journal_Database::instance();
	}
}
