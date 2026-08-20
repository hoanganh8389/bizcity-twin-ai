<?php
/**
 * BizCity Profile REST API.
 *
 * Namespace: bizcity-profile/v1
 *
 * @package Bizcity_Twin_AI
 * @subpackage Plugins\BizCityProfile
 */
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Personal_Profile_REST' ) ) { return; }

final class BizCity_Personal_Profile_REST {

	const NS = 'bizcity-profile/v1';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register_routes() {
		// [2026-08-20 Johnny Chu] PHASE-PROFILE-QR — register owner-scoped Profile card routes under the canonical namespace.
		register_rest_route( self::NS, '/profile/templates', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'templates' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( self::NS, '/profile/contacts', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'contacts' ),
			'permission_callback' => array( $this, 'check_logged_in' ),
		) );

		register_rest_route( self::NS, '/profile/cards', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_cards' ),
				'permission_callback' => array( $this, 'check_logged_in' ),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_card' ),
				'permission_callback' => array( $this, 'check_logged_in' ),
			),
		) );

		register_rest_route( self::NS, '/profile/cards/(?P<id>\d+)', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_card' ),
				'permission_callback' => array( $this, 'check_logged_in' ),
			),
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'update_card' ),
				'permission_callback' => array( $this, 'check_logged_in' ),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_card' ),
				'permission_callback' => array( $this, 'check_logged_in' ),
			),
		) );

		register_rest_route( self::NS, '/profile/cards/(?P<id>\d+)/publish', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'publish_card' ),
			'permission_callback' => array( $this, 'check_logged_in' ),
		) );

		register_rest_route( self::NS, '/profile/cards/(?P<id>\d+)/channel-context', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'channel_context' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( self::NS, '/profile/chat/turn', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'chat_turn' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( self::NS, '/profile/cards/(?P<id>\d+)/entrypoints', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_entrypoints' ),
				'permission_callback' => array( $this, 'check_logged_in' ),
			),
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'save_entrypoints' ),
				'permission_callback' => array( $this, 'check_logged_in' ),
			),
		) );

		register_rest_route( self::NS, '/profile/cards/(?P<id>\d+)/analytics', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'analytics' ),
			'permission_callback' => array( $this, 'check_logged_in' ),
		) );

		register_rest_route( self::NS, '/profile/cards/(?P<id>\d+)/qr', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_qr' ),
				'permission_callback' => array( $this, 'check_logged_in' ),
			),
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'save_qr' ),
				'permission_callback' => array( $this, 'check_logged_in' ),
			),
		) );

		register_rest_route( self::NS, '/profile/cards/(?P<id>\d+)/vcard.vcf', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'vcard' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( self::NS, '/profile/track', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'track' ),
			'permission_callback' => '__return_true',
		) );
	}

	public function check_logged_in() {
		// [2026-08-20 Johnny Chu] PHASE-PROFILE-QR — fail closed before any Profile query.
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'auth_required', 'Vui lòng đăng nhập để quản lý danh thiếp.', array( 'status' => 401 ) );
		}
		return true;
	}

	public function templates() {
		// [2026-08-20 Johnny Chu] PHASE-PROFILE-QR — return a stable catalog without database access.
		return rest_ensure_response( array(
			'success' => true,
			'items'   => array(
				array( 'key' => 'business-card-compact', 'label' => 'Danh thiếp gọn', 'variant' => 'compact' ),
				array( 'key' => 'business-card-full', 'label' => 'Danh thiếp đầy đủ', 'variant' => 'full' ),
			),
		) );
	}

	public function list_cards() {
		// [2026-08-20 Johnny Chu] PHASE-PROFILE-QR — list only the current owner's registry rows.
		$items = BizCity_Personal_Profile_Card_Manager::get_by_owner( get_current_user_id() );
		foreach ( $items as $index => $item ) {
			$counts = BizCity_Personal_Profile_Analytics::counts_for_card( (int) $item['id'], 30 );
			$items[ $index ]['view_count'] = (int) ( $counts['view'] ?? 0 );
			$items[ $index ]['interaction_count'] = array_sum( array_diff_key( $counts, array( 'view' => true, 'qr_scan' => true ) ) );
			$items[ $index ]['public_url'] = $this->published_url( (int) $item['bzpb_project_id'] );
		}
		return rest_ensure_response( array( 'success' => true, 'items' => $items ) );
	}

	public function contacts() {
		// [2026-08-20 Johnny Chu] PHASE-PROFILE-QR — return CRM contacts only through owned published Profile pages.
		return rest_ensure_response( array( 'success' => true, 'items' => BizCity_Personal_Profile_Contacts_Bridge::get_for_owner( get_current_user_id() ) ) );
	}

	public function create_card( WP_REST_Request $request ) {
		// [2026-08-20 Johnny Chu] PHASE-PROFILE-QR — create from a local template or validate an existing owned Page Builder project.
		$project_id = (int) $request->get_param( 'bzpb_project_id' );
		$template_key = sanitize_key( (string) $request->get_param( 'template_key' ) );
		if ( '' === $template_key ) { $template_key = 'business-card-compact'; }
		$label = sanitize_text_field( (string) $request->get_param( 'label' ) );
		if ( '' === $label ) { $label = 'Danh thiếp mới'; }
		$config = null;
		if ( $project_id <= 0 ) {
			$template_file = BIZCITY_PERSONAL_DIR . 'includes/profile/templates/' . $template_key . '.json';
			$config = is_readable( $template_file ) ? json_decode( (string) file_get_contents( $template_file ), true ) : null;
			if ( ! is_array( $config ) ) {
				return $this->error_response( 'not_found', 'Không tìm thấy template Profile.', 'Chọn lại template danh thiếp rồi thử lại.', 'profile_template_not_found', 400 );
			}
			$created = BizCity_Personal_Profile_BZPB_Bridge::create_project( $config, $label );
			if ( is_wp_error( $created ) ) {
				return $this->error_response( 'gateway_degraded', 'Không tạo được project Page Builder.', 'Kiểm tra Page Builder rồi thử lại.', 'profile_project_create_failed', 502 );
			}
			$project_id = (int) $created['project_id'];
		} else {
			$owned_project = BizCity_Personal_Profile_BZPB_Bridge::get_project_config( $project_id, get_current_user_id() );
			if ( is_wp_error( $owned_project ) ) {
				return $this->error_response( 'not_found', 'Project Page Builder không thuộc tài khoản này.', 'Chọn project của bạn rồi thử lại.', 'pagebuilder_project_not_found', 404 );
			}
		}
		$id = BizCity_Personal_Profile_Card_Manager::create( array(
			'owner_user_id'   => get_current_user_id(),
			'bzpb_project_id' => $project_id,
			'label'           => $label,
			'template_key'    => $template_key,
		) );
		if ( $id <= 0 ) {
			return $this->error_response( 'invalid_param', 'Không tạo được danh thiếp Profile.', 'Kiểm tra project Page Builder và thử lại.', 'profile_card_create_failed', 400 );
		}
		if ( is_array( $config ) ) {
			foreach ( $config['blocks'] as $index => $block ) {
				if ( 'profile-card' === (string) ( $block['type'] ?? '' ) ) {
					$config['blocks'][ $index ]['props']['profileCardId'] = $id;
					break;
				}
			}
			$saved = BizCity_Personal_Profile_BZPB_Bridge::save_project_config( $project_id, get_current_user_id(), $config, $label );
			if ( is_wp_error( $saved ) ) {
				BizCity_Personal_Profile_Card_Manager::delete( $id, get_current_user_id() );
				return $this->error_response( 'gateway_degraded', 'Không thể gắn Profile card vào project.', 'Mở lại Page Builder rồi thử tạo danh thiếp.', 'profile_card_link_failed', 502 );
			}
		}
		return new WP_REST_Response( array( 'success' => true, 'item' => BizCity_Personal_Profile_Card_Manager::get( $id, get_current_user_id() ) ), 201 );
	}

	public function get_card( WP_REST_Request $request ) {
		// [2026-08-20 Johnny Chu] PHASE-PROFILE-QR — owner-scoped detail read.
		$item = BizCity_Personal_Profile_Card_Manager::get( (int) $request['id'], get_current_user_id() );
		if ( ! is_array( $item ) ) {
			return $this->error_response( 'not_found', 'Không tìm thấy danh thiếp Profile.', 'Mở lại danh sách danh thiếp của bạn.', 'profile_card_not_found', 404 );
		}
		$item['public_url'] = $this->published_url( (int) $item['bzpb_project_id'] );
		$item['qr'] = BizCity_Personal_Profile_QR_Manager::get_for_owner( (int) $item['id'], get_current_user_id() );
		return rest_ensure_response( array( 'success' => true, 'item' => $item ) );
	}

	public function update_card( WP_REST_Request $request ) {
		// [2026-08-20 Johnny Chu] PHASE-PROFILE-QR — update only mutable registry metadata.
		$ok = BizCity_Personal_Profile_Card_Manager::update( (int) $request['id'], get_current_user_id(), array(
			'label'  => $request->get_param( 'label' ),
			'status' => $request->get_param( 'status' ),
		) );
		if ( ! $ok ) {
			return $this->error_response( 'not_found', 'Không cập nhật được danh thiếp Profile.', 'Kiểm tra quyền sở hữu và thử lại.', 'profile_card_update_failed', 404 );
		}
		return rest_ensure_response( array( 'success' => true, 'item' => BizCity_Personal_Profile_Card_Manager::get( (int) $request['id'], get_current_user_id() ) ) );
	}

	public function delete_card( WP_REST_Request $request ) {
		// [2026-08-20 Johnny Chu] PHASE-PROFILE-QR — delete registry only; Page Builder project remains intact.
		$ok = BizCity_Personal_Profile_Card_Manager::delete( (int) $request['id'], get_current_user_id() );
		if ( ! $ok ) {
			return $this->error_response( 'not_found', 'Không xóa được danh thiếp Profile.', 'Kiểm tra quyền sở hữu và thử lại.', 'profile_card_delete_failed', 404 );
		}
		return rest_ensure_response( array( 'success' => true, 'deleted' => true ) );
	}

	public function publish_card( WP_REST_Request $request ) {
		// [2026-08-20 Johnny Chu] PHASE-PROFILE-QR — publish only an owned card and persist status after BZPB success.
		$card = BizCity_Personal_Profile_Card_Manager::get( (int) $request['id'], get_current_user_id() );
		if ( ! is_array( $card ) ) {
			return $this->error_response( 'not_found', 'Không tìm thấy danh thiếp Profile.', 'Mở lại danh sách danh thiếp của bạn.', 'profile_card_not_found', 404 );
		}
		$result = BizCity_Personal_Profile_BZPB_Bridge::publish( (int) $card['bzpb_project_id'], (int) $card['id'] );
		if ( is_wp_error( $result ) ) {
			return $this->error_response( 'gateway_degraded', 'Không thể publish trang Profile lúc này.', 'Kiểm tra Page Builder rồi thử lại.', 'pagebuilder_publish_failed', 502 );
		}
		BizCity_Personal_Profile_Card_Manager::update( (int) $card['id'], get_current_user_id(), array( 'status' => 'published' ) );
		return rest_ensure_response( array(
			'success' => true,
			'item'    => BizCity_Personal_Profile_Card_Manager::get( (int) $card['id'], get_current_user_id() ),
			'page'    => $result,
		) );
	}

	public function channel_context( WP_REST_Request $request ) {
		// [2026-08-20 Johnny Chu] PHASE-PROFILE-QR — public handshake exposes only signed, short-lived presentation context.
		$context = BizCity_Personal_Profile_Channel_Resolver::resolve(
			(int) $request['id'],
			$request->get_param( 'channel_code' ),
			$request->get_param( 'presentation' )
		);
		if ( is_wp_error( $context ) ) {
			$code = sanitize_key( (string) $context->get_error_code() );
			$map = array(
				'not_found'        => array( 'not_found', 'Cổng Profile hoặc entrypoint không tồn tại.', 'Publish Profile và bật entrypoint rồi thử lại.', 'profile_channel_unavailable', 404 ),
				'invalid_param'    => array( 'invalid_param', 'Tham số channel Profile không hợp lệ.', 'Chọn lại channel và kiểu hiển thị rồi thử lại.', 'profile_channel_invalid', 400 ),
				'module_not_loaded'=> array( 'module_not_loaded', 'Channel Gateway chưa sẵn sàng.', 'Kiểm tra module Channel Gateway rồi thử lại.', 'module_not_loaded', 503 ),
			);
			$error = $map[ $code ] ?? array( 'gateway_degraded', 'Kênh chat Profile chưa sẵn sàng.', 'Kiểm tra binding trong Channel Gateway rồi thử lại.', 'profile_channel_unavailable', 503 );
			return $this->error_response( $error[0], $error[1], $error[2], $error[3], $error[4] );
		}
		return rest_ensure_response( array( 'success' => true, 'context' => $context ) );
	}

	public function chat_turn( WP_REST_Request $request ) {
		// [2026-08-20 Johnny Chu] PHASE-PROFILE-QR — public Profile Chat delegates to the canonical WebChat TwinBrain adapter.
		$result = BizCity_Personal_Profile_Chat_Handler::handle(
			(int) $request->get_param( 'card_id' ),
			$request->get_param( 'context_token' ),
			$request->get_param( 'channel_code' ),
			$request->get_param( 'presentation' ),
			$request->get_param( 'message' ),
			$request->get_param( 'session_id' )
		);
		if ( is_wp_error( $result ) ) {
			$code = sanitize_key( (string) $result->get_error_code() );
			$map = array(
				'invalid_context'     => array( 'permission_denied', 'Phiên chat Profile không hợp lệ hoặc đã hết hạn.', 'Tải lại trang Profile rồi thử lại.', 'profile_channel_unavailable', 403 ),
				'empty_prompt'        => array( 'invalid_param', 'Tin nhắn không được để trống.', 'Nhập nội dung rồi thử lại.', 'invalid_param_generic', 400 ),
				'invalid_param'       => array( 'invalid_param', 'Yêu cầu chat Profile không hợp lệ.', 'Tải lại Profile và gửi lại tin nhắn.', 'invalid_param_generic', 400 ),
				'module_not_loaded'   => array( 'module_not_loaded', 'Twin Brain chưa sẵn sàng.', 'Kiểm tra module TwinBrain rồi thử lại.', 'module_not_loaded', 503 ),
			);
			$error = $map[ $code ] ?? array( 'twin_agent_exception', 'Twin Brain chưa thể trả lời lúc này.', 'Thử lại sau ít phút.', 'gateway_degraded', 503 );
			return $this->error_response( $error[0], $error[1], $error[2], $error[3], $error[4] );
		}
		return rest_ensure_response( array( 'success' => true, 'answer' => (string) ( $result['answer'] ?? '' ), 'session_id' => (string) ( $result['session_id'] ?? '' ), 'trace_id' => (string) ( $result['trace_id'] ?? '' ) ) );
	}

	public function get_entrypoints( WP_REST_Request $request ) {
		// [2026-08-20 Johnny Chu] PHASE-PROFILE-QR — owner-scoped entrypoint read from Page Builder source of truth.
		$card = BizCity_Personal_Profile_Card_Manager::get( (int) $request['id'], get_current_user_id() );
		if ( ! is_array( $card ) ) {
			return $this->error_response( 'not_found', 'Không tìm thấy danh thiếp Profile.', 'Mở lại danh sách danh thiếp của bạn.', 'profile_card_not_found', 404 );
		}
		$project = BizCity_Personal_Profile_BZPB_Bridge::get_project_config( (int) $card['bzpb_project_id'], get_current_user_id() );
		if ( is_wp_error( $project ) ) {
			return $this->error_response( 'not_found', 'Không đọc được cấu hình Page Builder.', 'Mở project bằng Page Builder rồi thử lại.', 'pagebuilder_project_not_found', 404 );
		}
		return rest_ensure_response( array( 'success' => true, 'items' => BizCity_Personal_Profile_Entrypoint_Manager::read_from_config( $project['config'] ) ) );
	}

	public function save_entrypoints( WP_REST_Request $request ) {
		// [2026-08-20 Johnny Chu] PHASE-PROFILE-QR — validate then save only the Profile block entrypoints.
		$card = BizCity_Personal_Profile_Card_Manager::get( (int) $request['id'], get_current_user_id() );
		if ( ! is_array( $card ) ) {
			return $this->error_response( 'not_found', 'Không tìm thấy danh thiếp Profile.', 'Mở lại danh sách danh thiếp của bạn.', 'profile_card_not_found', 404 );
		}
		$raw_entries = $request->get_param( 'items' );
		$entries = BizCity_Personal_Profile_Entrypoint_Manager::normalize( $raw_entries );
		if ( is_wp_error( $entries ) ) {
			return $this->error_response( 'invalid_param', 'Cấu hình kết nối Profile không hợp lệ.', 'Kiểm tra từng kênh và kiểu hiển thị rồi thử lại.', 'profile_entrypoint_invalid', 400 );
		}
		$project = BizCity_Personal_Profile_BZPB_Bridge::get_project_config( (int) $card['bzpb_project_id'], get_current_user_id() );
		if ( is_wp_error( $project ) ) {
			return $this->error_response( 'not_found', 'Không đọc được cấu hình Page Builder.', 'Mở project bằng Page Builder rồi thử lại.', 'pagebuilder_project_not_found', 404 );
		}
		$config = BizCity_Personal_Profile_Entrypoint_Manager::write_to_config( $project['config'], $entries );
		if ( is_wp_error( $config ) ) {
			return $this->error_response( 'not_found', 'Profile block chưa có trong project.', 'Mở project bằng Page Builder và thêm block Profile rồi thử lại.', 'profile_block_required', 400 );
		}
		$saved = BizCity_Personal_Profile_BZPB_Bridge::save_project_config( (int) $card['bzpb_project_id'], get_current_user_id(), $config, $project['title'] );
		if ( is_wp_error( $saved ) ) {
			return $this->error_response( 'gateway_degraded', 'Không lưu được kết nối Profile lúc này.', 'Kiểm tra Page Builder rồi thử lại.', 'profile_entrypoint_save_failed', 502 );
		}
		return rest_ensure_response( array( 'success' => true, 'items' => $entries ) );
	}

	public function analytics( WP_REST_Request $request ) {
		// [2026-08-20 Johnny Chu] PHASE-PROFILE-QR — owner-scoped aggregate read for Profile performance.
		$card = BizCity_Personal_Profile_Card_Manager::get( (int) $request['id'], get_current_user_id() );
		if ( ! is_array( $card ) ) {
			return $this->error_response( 'not_found', 'Không tìm thấy danh thiếp Profile.', 'Mở lại danh sách danh thiếp của bạn.', 'profile_card_not_found', 404 );
		}
		$range = max( 1, min( 365, (int) $request->get_param( 'range' ) ?: 30 ) );
		$report = BizCity_Personal_Profile_Analytics::report_for_card( (int) $card['id'], $range );
		return rest_ensure_response( array( 'success' => true, 'range' => $range, 'counts' => $report['counts'] ?? array(), 'trend' => $report['trend'] ?? array(), 'channels' => $report['channels'] ?? array() ) );
	}

	public function track( WP_REST_Request $request ) {
		// [2026-08-20 Johnny Chu] PHASE-PROFILE-QR — public beacon accepts only whitelisted event types and published cards.
		$event = sanitize_key( (string) $request->get_param( 'event_type' ) );
		$meta = $request->get_param( 'meta' );
		$ok = BizCity_Personal_Profile_Analytics::record( (int) $request->get_param( 'card_id' ), $event, is_array( $meta ) ? $meta : array() );
		return rest_ensure_response( array( 'success' => (bool) $ok ) );
	}

	public function get_qr( WP_REST_Request $request ) {
		// [2026-08-20 Johnny Chu] PHASE-PROFILE-QR — owner-scoped QR style read.
		$qr = BizCity_Personal_Profile_QR_Manager::get_for_owner( (int) $request['id'], get_current_user_id() );
		if ( ! is_array( $qr ) ) { return $this->error_response( 'not_found', 'Không tìm thấy cấu hình QR.', 'Mở lại danh sách Profile rồi thử lại.', 'profile_qr_not_found', 404 ); }
		return rest_ensure_response( array( 'success' => true, 'item' => $qr ) );
	}

	public function save_qr( WP_REST_Request $request ) {
		// [2026-08-20 Johnny Chu] PHASE-PROFILE-QR — owner-scoped QR style write; PNG/SVG stays client-side.
		$payload = $request->get_json_params();
		$payload = is_array( $payload ) ? $payload : array();
		$qr = BizCity_Personal_Profile_QR_Manager::save( (int) $request['id'], get_current_user_id(), $payload );
		if ( is_wp_error( $qr ) ) { return $this->error_response( 'invalid_param', 'Không lưu được cấu hình QR.', 'Kiểm tra màu, kích thước và URL rồi thử lại.', 'profile_qr_save_failed', 400 ); }
		return rest_ensure_response( array( 'success' => true, 'item' => $qr ) );
	}

	public function vcard( WP_REST_Request $request ) {
		// [2026-08-20 Johnny Chu] PHASE-PROFILE-QR — public vCard is available only for published cards.
		$result = BizCity_Personal_Profile_VCard_Export::response( (int) $request['id'] );
		if ( is_wp_error( $result ) ) { return $this->error_response( 'not_found', 'Không tìm thấy vCard Profile công khai.', 'Publish danh thiếp rồi thử lại.', 'profile_vcard_not_found', 404 ); }
		return $result;
	}

	private function error_response( $code, $message, $hint, $help_code, $status ) {
		$payload = class_exists( 'BizCity_Error_Payload' )
			? BizCity_Error_Payload::make( $code, $message, $hint, $help_code )
			: array( 'success' => false, 'code' => $code, 'message' => $message, 'hint' => $hint, 'help_code' => $help_code );
		return new WP_REST_Response( $payload, (int) $status );
	}

	private function published_url( $project_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bzpb_projects';
		if ( ! function_exists( 'bizcity_tbl_exists' ) || ! bizcity_tbl_exists( $table ) ) { return ''; }
		$page_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT published_page_id FROM `' . $table . '` WHERE id = %d LIMIT 1', (int) $project_id ) );
		return $page_id > 0 ? (string) get_permalink( $page_id ) : '';
	}
}
