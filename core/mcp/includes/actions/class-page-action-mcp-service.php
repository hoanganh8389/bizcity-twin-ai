<?php
/**
 * BizCity_Page_Action_MCP_Service — Page Specification bridge for BZPB.
 *
 * This service owns the MCP contract only. SiteConfig persistence, preview
 * rendering, and WordPress page publication remain owned by bizcity-pagebuilder.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\MCP\Actions
 * @since      2026-07-28 (PHASE-0.54-MCP Wave K)
 */

defined( 'ABSPATH' ) || exit;

// [2026-07-28 Johnny Chu] PHASE-0.54-MCP — MVP landing-page Action/Brain bridge.
final class BizCity_Page_Action_MCP_Service {

	private static $instance = null;

	public static function instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function get_schema( array $args, array $ctx ) {
		// [2026-07-28 Johnny Chu] PHASE-0.54-MCP — expose the active BZPB-compatible Page Specification contract.
		return array(
			'format'       => 'SiteConfig',
			'required'     => array( 'name', 'theme', 'blocks' ),
			'properties'   => array(
				'name'   => array( 'type' => 'string', 'minLength' => 1 ),
				'theme'  => array( 'type' => 'object', 'description' => 'BZPB theme tokens: bg0, bg1, text0, text1, accent, border, fonts, radius.' ),
				'blocks' => array( 'type' => 'array', 'maxItems' => 100, 'description' => 'Full BZPB block objects with id, type, and props.' ),
			),
			'block_contract' => array(
				'required' => array( 'id', 'type' ),
				'common_types' => array( 'navbar', 'hero', 'feature', 'pricing', 'testimonial', 'faq', 'lead-form', 'footer' ),
			),
			'notes' => array(
				'Gửi SiteConfig JSON trong page.create_draft; không gửi HTML.',
				'create_draft luôn tạo trạng thái draft; publish là lời gọi riêng cần confirmation_token.',
			),
		);
	}

	public function get_project( array $args, array $ctx ) {
		// [2026-07-28 Johnny Chu] PHASE-0.54-MCP — read BZPB project through its canonical REST handler.
		$project_id = isset( $args['draft_id'] ) ? absint( $args['draft_id'] ) : absint( $args['project_id'] ?? 0 );
		if ( ! $project_id ) {
			return new WP_Error( BizCity_MCP_Error::QUERY_INVALID, 'Thiếu draft_id của landing page.', array( 'status' => 400 ) );
		}
		$request = new WP_REST_Request( 'GET' );
		$request->set_param( 'id', $project_id );
		$response = $this->call_bzpb( 'handle_get_project', $request, $ctx );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return array( 'project' => $response->get_data()['project'] ?? array() );
	}

	public function create_draft( array $args, array $ctx ) {
		// [2026-07-28 Johnny Chu] PHASE-0.54-MCP — validate Page Spec before creating a BZPB draft.
		$config = isset( $args['site_config'] ) ? $args['site_config'] : ( $args['config'] ?? null );
		$validation = $this->validate_config( $config, (array) ( $args['citation_ids'] ?? array() ) );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}
		$request = new WP_REST_Request( 'POST' );
		$request->set_param( 'title', isset( $args['title'] ) ? (string) $args['title'] : (string) $config['name'] );
		$request->set_param( 'config', $config );
		$response = $this->call_bzpb( 'handle_save', $request, $ctx );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$data = $response->get_data();
		$project_id = absint( $data['project_id'] ?? 0 );
		if ( ! $project_id ) {
			return new WP_Error( BizCity_MCP_Error::INTERNAL_ERROR, 'PageBuilder không trả về draft_id.', array( 'status' => 500 ) );
		}
		return array_merge(
			array(
				'draft_id'         => $project_id,
				'status'           => 'draft',
				'validation_report'=> $validation,
			),
			BizCity_MCP_Action_Confirmation::issue( 'page.publish', $project_id, $ctx )
		);
	}

	public function update_draft( array $args, array $ctx ) {
		// [2026-07-28 Johnny Chu] PHASE-0.54-MCP — update only an owned BZPB draft and rotate confirmation token.
		$project_id = absint( $args['draft_id'] ?? $args['project_id'] ?? 0 );
		$config     = isset( $args['site_config'] ) ? $args['site_config'] : ( $args['config'] ?? null );
		if ( ! $project_id ) {
			return new WP_Error( BizCity_MCP_Error::QUERY_INVALID, 'Thiếu draft_id của landing page.', array( 'status' => 400 ) );
		}
		$current = $this->get_project( array( 'draft_id' => $project_id ), $ctx );
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		if ( (string) ( $current['project']['status'] ?? '' ) === 'published' ) {
			return new WP_Error( BizCity_MCP_Error::DRAFT_INVALID, 'Project đã publish; hãy tạo draft/revision mới trước khi chỉnh sửa.', array( 'status' => 409 ) );
		}
		$validation = $this->validate_config( $config, (array) ( $args['citation_ids'] ?? array() ) );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}
		$request = new WP_REST_Request( 'POST' );
		$request->set_param( 'project_id', $project_id );
		$request->set_param( 'title', isset( $args['title'] ) ? (string) $args['title'] : (string) $config['name'] );
		$request->set_param( 'config', $config );
		$response = $this->call_bzpb( 'handle_save', $request, $ctx );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return array_merge(
			array( 'draft_id' => $project_id, 'status' => 'draft', 'validation_report' => $validation ),
			BizCity_MCP_Action_Confirmation::issue( 'page.publish', $project_id, $ctx )
		);
	}

	public function preview( array $args, array $ctx ) {
		// [2026-07-28 Johnny Chu] PHASE-0.54-MCP — render an owned draft without publishing it.
		$project = $this->get_project( $args, $ctx );
		if ( is_wp_error( $project ) ) {
			return $project;
		}
		$row    = $project['project'] ?? array();
		$config = isset( $row['site_config'] ) && is_array( $row['site_config'] ) ? $row['site_config'] : array();
		if ( ! class_exists( 'BZPB_Export' ) ) {
			return new WP_Error( BizCity_MCP_Error::RENDERER_UNAVAILABLE, 'PageBuilder renderer chưa được nạp.', array( 'status' => 503 ) );
		}
		return array(
			'draft_id'    => absint( $row['id'] ?? 0 ),
			'status'      => (string) ( $row['status'] ?? 'draft' ),
			'block_count' => count( (array) ( $config['blocks'] ?? array() ) ),
			'html'        => BZPB_Export::render_for_wp_page( $config ),
		);
	}

	public function publish( array $args, array $ctx ) {
		// [2026-07-28 Johnny Chu] PHASE-0.54-MCP — require one-time confirmation before BZPB publishes a WordPress page.
		$project_id = absint( $args['draft_id'] ?? $args['project_id'] ?? 0 );
		if ( ! $project_id ) {
			return new WP_Error( BizCity_MCP_Error::QUERY_INVALID, 'Thiếu draft_id của landing page.', array( 'status' => 400 ) );
		}
		$check = BizCity_MCP_Action_Confirmation::consume( $args['confirmation_token'] ?? '', 'page.publish', $project_id, $ctx );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$request = new WP_REST_Request( 'POST' );
		$request->set_param( 'project_id', $project_id );
		$response = $this->call_bzpb( 'handle_publish', $request, $ctx );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$data = $response->get_data();
		return array(
			'draft_id' => $project_id,
			'post_id'  => absint( $data['page_id'] ?? 0 ),
			'url'      => (string) ( $data['page_url'] ?? '' ),
			'status'   => 'published',
		);
	}

	private function validate_config( $config, array $citation_ids = array() ) {
		if ( ! is_array( $config ) || empty( $config['name'] ) || ! is_array( $config['theme'] ?? null ) || ! is_array( $config['blocks'] ?? null ) ) {
			return new WP_Error( BizCity_MCP_Error::DRAFT_INVALID, 'SiteConfig cần có name, theme và blocks.', array( 'status' => 400 ) );
		}
		if ( count( $config['blocks'] ) > 100 ) {
			return new WP_Error( BizCity_MCP_Error::DRAFT_INVALID, 'Landing page không được vượt quá 100 block.', array( 'status' => 400 ) );
		}
		foreach ( $config['blocks'] as $index => $block ) {
			if ( ! is_array( $block ) || empty( $block['id'] ) || empty( $block['type'] ) ) {
				return new WP_Error( BizCity_MCP_Error::DRAFT_INVALID, 'Block #' . ( $index + 1 ) . ' thiếu id hoặc type.', array( 'status' => 400 ) );
			}
		}
		$valid_citations = array();
		foreach ( $citation_ids as $citation_id ) {
			$parsed = class_exists( 'BizCity_MCP_Citation' ) ? BizCity_MCP_Citation::parse( $citation_id ) : false;
			if ( is_wp_error( $parsed ) || $parsed === false ) {
				return new WP_Error( BizCity_MCP_Error::CITATION_INVALID, 'Page Specification có citation_id không hợp lệ.', array( 'status' => 400 ) );
			}
			$valid_citations[] = (string) $citation_id;
		}
		return array( 'valid' => true, 'block_count' => count( $config['blocks'] ), 'citation_ids' => array_values( array_unique( $valid_citations ) ) );
	}

	private function call_bzpb( $method, WP_REST_Request $request, array $ctx ) {
		if ( ! class_exists( 'BZPB_Rest_API' ) || ! method_exists( 'BZPB_Rest_API', $method ) ) {
			return new WP_Error( BizCity_MCP_Error::RENDERER_UNAVAILABLE, 'BizCity PageBuilder chưa sẵn sàng.', array( 'status' => 503 ) );
		}
		$previous_user = get_current_user_id();
		if ( (int) ( $ctx['user_id'] ?? 0 ) <= 0 || ! get_userdata( (int) $ctx['user_id'] ) ) {
			return new WP_Error( BizCity_MCP_Error::AUTH_INVALID, 'MCP user context không hợp lệ.', array( 'status' => 401 ) );
		}
		wp_set_current_user( (int) $ctx['user_id'] );
		try {
			return call_user_func( array( 'BZPB_Rest_API', $method ), $request );
		} finally {
			wp_set_current_user( $previous_user );
		}
	}
}
