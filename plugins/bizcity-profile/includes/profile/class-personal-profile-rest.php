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
	const PUBLIC_URL_CACHE_GROUP = 'bzp_profile_public_urls';

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

		register_rest_route( self::NS, '/profile/cards/(?P<id>\d+)/content', array(
			'methods'              => WP_REST_Server::EDITABLE,
			'callback'            => array( $this, 'update_content' ),
			'permission_callback' => array( $this, 'check_logged_in' ),
		) );

		register_rest_route( self::NS, '/profile/channel-accounts', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'channel_accounts' ),
			'permission_callback' => array( $this, 'check_logged_in' ),
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
			// [2026-08-21 Johnny Chu] PHASE-PROFILE-QR — Wave 5 item 1: auto-attach an existing or newly created CF7 contact form.
			$cf7 = BizCity_Personal_Profile_BZPB_Bridge::ensure_contact_form( $project_id );
			if ( is_wp_error( $cf7 ) && class_exists( 'WPCF7_ContactForm' ) ) {
				return $this->error_response( 'gateway_degraded', 'Không chuẩn bị được form liên hệ mặc định.', 'Kiểm tra Contact Form 7 rồi tạo lại Profile.', 'profile_contact_form_failed', 503 );
			}
			if ( ! is_wp_error( $cf7 ) ) {
				foreach ( $config['blocks'] as $index => $block ) {
					if ( 'lead-form' === (string) ( $block['type'] ?? '' ) && empty( $block['props']['cf7FormId'] ) ) {
						$config['blocks'][ $index ]['props']['cf7FormId'] = (int) $cf7['cf7_form_id'];
					}
				}
			}
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
		$project = BizCity_Personal_Profile_BZPB_Bridge::get_project_config( (int) $item['bzpb_project_id'], get_current_user_id() );
		$item['content'] = is_wp_error( $project ) ? array() : $this->profile_content_from_config( $project['config'] );
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

	public function update_content( WP_REST_Request $request ) {
		// [2026-08-21 Johnny Chu] PHASE-PROFILE-BRAIN-HERO — save the simple Profile editor through the existing SiteConfig source of truth.
		$card = BizCity_Personal_Profile_Card_Manager::get( (int) $request['id'], get_current_user_id() );
		if ( ! is_array( $card ) ) {
			return $this->error_response( 'not_found', 'Không tìm thấy danh thiếp Profile.', 'Mở lại danh sách danh thiếp của bạn.', 'profile_card_not_found', 404 );
		}
		$project = BizCity_Personal_Profile_BZPB_Bridge::get_project_config( (int) $card['bzpb_project_id'], get_current_user_id() );
		if ( is_wp_error( $project ) ) {
			return $this->error_response( 'not_found', 'Không đọc được cấu hình Profile.', 'Tải lại danh thiếp rồi thử lại.', 'profile_config_not_found', 404 );
		}
		$payload = $request->get_json_params();
		$payload = is_array( $payload ) ? $payload : array();
		$normalized = $this->normalize_content_payload( $payload );
		if ( is_wp_error( $normalized ) ) {
			return $this->error_response( 'invalid_param', 'Thông tin Profile chưa hợp lệ.', 'Kiểm tra các đường link và thử lưu lại.', 'profile_content_invalid', 400 );
		}
		$config = $project['config'];
		$found  = false;
		foreach ( is_array( $config['blocks'] ?? null ) ? $config['blocks'] : array() as $index => $block ) {
			if ( 'profile-card' !== (string) ( $block['type'] ?? '' ) ) { continue; }
			$config['blocks'][ $index ]['props'] = array_merge( is_array( $block['props'] ?? null ) ? $block['props'] : array(), $normalized );
			$found = true;
			break;
		}
		if ( ! $found ) {
			return $this->error_response( 'not_found', 'Profile block chưa tồn tại.', 'Tạo lại danh thiếp từ template Profile rồi thử lại.', 'profile_block_required', 400 );
		}
		$saved = BizCity_Personal_Profile_BZPB_Bridge::save_project_config( (int) $card['bzpb_project_id'], get_current_user_id(), $config, $project['title'] );
		if ( is_wp_error( $saved ) ) {
			return $this->error_response( 'gateway_degraded', 'Không lưu được thông tin Profile lúc này.', 'Kiểm tra Page Builder rồi thử lại.', 'profile_content_save_failed', 502 );
		}
		// [2026-08-21 Johnny Chu] PHASE-PROFILE-QR — Wave 5 item 8: rename the live public URL when a card is already published.
		if ( isset( $normalized['slug'] ) && 'published' === (string) ( $card['status'] ?? '' ) ) {
			BizCity_Personal_Profile_BZPB_Bridge::rename_published_page( (int) $card['bzpb_project_id'], get_current_user_id(), $normalized['slug'] );
			if ( class_exists( 'BizCity_Cache' ) ) { BizCity_Cache::flush_group( self::PUBLIC_URL_CACHE_GROUP ); }
		}
		return rest_ensure_response( array( 'success' => true, 'content' => $normalized ) );
	}

	private function normalize_content_payload( array $payload ) {
		$normalized = array();
		$text_fields = array( 'name', 'jobTitle', 'company', 'bio' );
		foreach ( $text_fields as $field ) {
			if ( array_key_exists( $field, $payload ) ) {
				$normalized[ $field ] = 'bio' === $field ? sanitize_textarea_field( (string) $payload[ $field ] ) : sanitize_text_field( (string) $payload[ $field ] );
			}
		}
		if ( array_key_exists( 'avatarUrl', $payload ) ) {
			$avatar_url = $this->normalize_public_url( $payload['avatarUrl'] );
			if ( is_wp_error( $avatar_url ) ) { return $avatar_url; }
			$normalized['avatarUrl'] = $avatar_url;
		}
		if ( array_key_exists( 'coverUrl', $payload ) ) {
			$cover_url = $this->normalize_public_url( $payload['coverUrl'] );
			if ( is_wp_error( $cover_url ) ) { return $cover_url; }
			$normalized['coverUrl'] = $cover_url;
		}
		if ( array_key_exists( 'heroStyle', $payload ) ) {
			$hero_style = sanitize_key( (string) $payload['heroStyle'] );
			if ( ! in_array( $hero_style, array( 'brain', 'photo' ), true ) ) { return new WP_Error( 'invalid_param' ); }
			$normalized['heroStyle'] = $hero_style;
		}
		if ( array_key_exists( 'slug', $payload ) ) {
			$slug = sanitize_title( (string) $payload['slug'] );
			if ( '' === $slug ) { return new WP_Error( 'invalid_param' ); }
			$normalized['slug'] = $slug;
		}
		if ( array_key_exists( 'brainAccentColor', $payload ) ) {
			$color = sanitize_hex_color( (string) $payload['brainAccentColor'] );
			if ( ! $color ) { return new WP_Error( 'invalid_param' ); }
			$normalized['brainAccentColor'] = $color;
		}
		if ( array_key_exists( 'contactFields', $payload ) ) {
			$fields = $this->normalize_contact_fields( $payload['contactFields'] );
			if ( is_wp_error( $fields ) ) { return $fields; }
			$normalized['contactFields'] = $fields;
		}
		if ( array_key_exists( 'socialLinks', $payload ) ) {
			$links = $this->normalize_social_links( $payload['socialLinks'] );
			if ( is_wp_error( $links ) ) { return $links; }
			$normalized['socialLinks'] = $links;
		}
		return $normalized;
	}

	private function normalize_contact_fields( $fields ) {
		if ( ! is_array( $fields ) || count( $fields ) > 8 ) { return new WP_Error( 'invalid_param' ); }
		$allowed = array( 'phone', 'email', 'website', 'address', 'link' );
		$normalized = array();
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) ) { return new WP_Error( 'invalid_param' ); }
			$type  = sanitize_key( (string) ( $field['type'] ?? 'link' ) );
			$value = trim( (string) ( $field['value'] ?? '' ) );
			if ( ! in_array( $type, $allowed, true ) || '' === $value ) { continue; }
			if ( 'email' === $type ) { $value = sanitize_email( $value ); }
			if ( in_array( $type, array( 'website', 'link' ), true ) ) {
				$value = $this->normalize_public_url( $value );
				if ( is_wp_error( $value ) ) { return $value; }
			}
			if ( '' === $value ) { continue; }
			$normalized[] = array( 'type' => $type, 'label' => sanitize_text_field( (string) ( $field['label'] ?? $type ) ), 'value' => $value );
		}
		return $normalized;
	}

	private function normalize_social_links( $links ) {
		if ( ! is_array( $links ) || count( $links ) > 8 ) { return new WP_Error( 'invalid_param' ); }
		$allowed = array( 'x', 'instagram', 'threads', 'facebook', 'youtube', 'tiktok', 'linkedin' );
		$normalized = array();
		foreach ( $links as $link ) {
			if ( ! is_array( $link ) ) { return new WP_Error( 'invalid_param' ); }
			$platform = sanitize_key( (string) ( $link['platform'] ?? '' ) );
			$url      = $this->normalize_public_url( $link['url'] ?? '' );
			if ( is_wp_error( $url ) ) { return $url; }
			if ( ! in_array( $platform, $allowed, true ) || '' === $url ) { continue; }
			$normalized[] = array( 'platform' => $platform, 'url' => $url );
		}
		return $normalized;
	}

	private function normalize_public_url( $value ) {
		// [2026-08-21 Johnny Chu] PHASE-PROFILE-BRAIN-HERO — normalize public links to one safe HTTP URL contract.
		$value = trim( (string) $value );
		if ( '' === $value ) { return ''; }
		if ( 0 !== strpos( strtolower( $value ), 'http://' ) && 0 !== strpos( strtolower( $value ), 'https://' ) ) {
			$value = 'https://' . $value;
		}
		$value = esc_url_raw( $value );
		return wp_http_validate_url( $value ) ? $value : new WP_Error( 'invalid_param' );
	}

	private function profile_content_from_config( array $config ) {
		$allowed = array( 'variant', 'heroStyle', 'brainAccentColor', 'slug', 'avatarUrl', 'coverUrl', 'name', 'jobTitle', 'company', 'bio', 'contactFields', 'socialLinks', 'messagingLinks', 'quickActions', 'ctaSave', 'ctaShare' );
		foreach ( is_array( $config['blocks'] ?? null ) ? $config['blocks'] : array() as $block ) {
			if ( 'profile-card' !== (string) ( $block['type'] ?? '' ) || ! is_array( $block['props'] ?? null ) ) { continue; }
			return array_intersect_key( $block['props'], array_flip( $allowed ) );
		}
		return array();
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
		$project = BizCity_Personal_Profile_BZPB_Bridge::get_project_config( (int) $card['bzpb_project_id'], get_current_user_id() );
		if ( ! is_wp_error( $project ) ) {
			$content = $this->profile_content_from_config( $project['config'] );
			if ( ! empty( $content['slug'] ) ) {
				BizCity_Personal_Profile_BZPB_Bridge::rename_published_page( (int) $card['bzpb_project_id'], get_current_user_id(), $content['slug'] );
			}
		}
		if ( class_exists( 'BizCity_Cache' ) ) { BizCity_Cache::flush_group( self::PUBLIC_URL_CACHE_GROUP ); }
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
		if ( is_wp_error( $qr ) ) {
			// [2026-08-21 Johnny Chu] HOTFIX — preserve schema/storage failures as infrastructure errors.
			$error_code = (string) $qr->get_error_code();
			if ( in_array( $error_code, array( 'db_error', 'module_not_loaded' ), true ) ) {
				return $this->error_response( 'gateway_degraded', 'Kho QR Profile chưa sẵn sàng để lưu.', 'Kiểm tra schema và thử lại sau.', 'profile_qr_save_failed', 503 );
			}
			return $this->error_response( 'invalid_param', 'Không lưu được cấu hình QR.', 'Kiểm tra màu, kích thước và URL rồi thử lại.', 'profile_qr_save_failed', 400 );
		}
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
		// [2026-08-21 Johnny Chu] PHASE-PROFILE-QR — cache project-to-public-page resolution to avoid one metadata query per card row.
		$cache_key = 'blog_' . ( function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0 ) . '_project_' . (int) $project_id;
		if ( class_exists( 'BizCity_Cache' ) ) {
			$cached = BizCity_Cache::get( self::PUBLIC_URL_CACHE_GROUP, $cache_key );
			if ( false !== $cached ) { return (string) $cached; }
		}
		global $wpdb;
		$table = $wpdb->prefix . 'bzpb_projects';
		if ( ! function_exists( 'bizcity_tbl_exists' ) || ! bizcity_tbl_exists( $table ) ) { return ''; }
		$page_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT published_page_id FROM `' . $table . '` WHERE id = %d LIMIT 1', (int) $project_id ) );
		$url = $page_id > 0 ? (string) get_permalink( $page_id ) : '';
		if ( class_exists( 'BizCity_Cache' ) ) { BizCity_Cache::set( self::PUBLIC_URL_CACHE_GROUP, $cache_key, $url, BizCity_Cache::TTL_MEDIUM ); }
		return $url;
	}

	public function channel_accounts( WP_REST_Request $request ) {
		// [2026-08-21 Johnny Chu] PHASE-PROFILE-QR — Wave 5 item 6: list already-connected channels for quick-pick instead of manual entry.
		$platform = sanitize_key( (string) $request->get_param( 'platform' ) );
		$accounts = array();
		$is_admin = current_user_can( 'manage_options' );
		$user_id  = get_current_user_id();
		global $wpdb;
		if ( in_array( $platform, array( 'facebook', 'messenger' ), true ) && class_exists( 'BizCity_Facebook_Bot_Database' ) ) {
			$table = $wpdb->prefix . 'bizcity_facebook_bots';
			if ( function_exists( 'bizcity_tbl_exists' ) && bizcity_tbl_exists( $table ) ) {
				$sql = 'SELECT page_id, page_name FROM `' . $table . '` WHERE status = %s';
				$args = array( 'active' );
				if ( ! $is_admin ) { $sql .= ' AND (user_id = 0 OR user_id = %d)'; $args[] = $user_id; }
				$sql .= ' ORDER BY id DESC';
				$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
				foreach ( is_array( $rows ) ? $rows : array() as $row ) {
					$page_id = (string) ( $row['page_id'] ?? '' );
					if ( '' === $page_id ) { continue; }
					$page_name = (string) ( $row['page_name'] ?? '' );
					$accounts[] = array( 'value' => $page_id, 'label' => '' !== $page_name ? $page_name : ( 'Page #' . $page_id ), 'url' => 'https://m.me/' . rawurlencode( $page_id ) );
				}
			}
		} elseif ( 'zalo_oa' === $platform && class_exists( 'BizCity_Zalo_Bot_Database' ) ) {
			// [2026-08-21 Johnny Chu] PHASE-PROFILE-QR — known gap (R-TWEB-14): Zalo Bot table has no owner_user_id yet, so this list is site-wide for now.
			$bots = BizCity_Zalo_Bot_Database::instance()->get_active_bots();
			foreach ( (array) $bots as $bot ) {
				$oa_id    = (string) ( is_object( $bot ) ? ( $bot->oa_id ?? '' ) : ( $bot['oa_id'] ?? '' ) );
				$bot_name = (string) ( is_object( $bot ) ? ( $bot->bot_name ?? '' ) : ( $bot['bot_name'] ?? '' ) );
				if ( '' === $oa_id ) { continue; }
				$accounts[] = array( 'value' => $oa_id, 'label' => ( '' !== $bot_name ? $bot_name . ' — ' : '' ) . 'OA: ' . $oa_id, 'url' => 'https://zalo.me/' . rawurlencode( $oa_id ) );
			}
		}
		return rest_ensure_response( array( 'success' => true, 'platform' => $platform, 'items' => $accounts ) );
	}
}

if ( class_exists( 'BizCity_Cache_Registry' ) && class_exists( 'BizCity_Cache' ) ) {
	BizCity_Cache_Registry::register(
		'bzp_profile_public_urls',
		'modules.personal.profile',
		array( 'blog_{blog_id}_project_{project_id}' => array( 'ttl' => BizCity_Cache::TTL_MEDIUM, 'desc' => 'Published public URL by Profile Page Builder project' ) )
	);
}
