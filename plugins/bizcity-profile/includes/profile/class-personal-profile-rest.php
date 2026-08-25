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

		// [2026-08-21 Johnny Chu] PHASE-TWIN-BRAIN-PROFILE — TBP-4: list provider wheels allowed for the current member.
		register_rest_route( self::NS, '/profile/wheels', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'wheels' ),
			'permission_callback' => array( $this, 'check_logged_in' ),
		) );

		// [2026-08-21 Johnny Chu] PHASE-TWIN-BRAIN-PROFILE — TBP-4: expose provider-owned member assignment without adding a Profile mapping table.
		register_rest_route( self::NS, '/profile/wheels/(?P<provider>[a-z0-9_-]+)/(?P<id>\d+)/members', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'wheel_members' ),
				'permission_callback' => array( $this, 'check_admin' ),
			),
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'update_wheel_members' ),
				'permission_callback' => array( $this, 'check_admin' ),
			),
		) );

		register_rest_route( self::NS, '/profile/contacts', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'contacts' ),
			'permission_callback' => array( $this, 'check_logged_in' ),
		) );

		// [2026-08-21 Johnny Chu] PHASE-TBP-5.1 — create owner-scoped lead follow-up in the canonical Scheduler, not a Profile task table.
		register_rest_route( self::NS, '/profile/contacts/(?P<id>\d+)/follow-up', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'create_contact_follow_up' ),
			'permission_callback' => array( $this, 'check_logged_in' ),
		) );

		// [2026-08-21 Johnny Chu] PHASE-TBP-5.2 — expose the Scheduler-backed owner follow-up queue to Profile.
		register_rest_route( self::NS, '/profile/follow-ups', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'follow_ups' ),
			'permission_callback' => array( $this, 'check_logged_in' ),
		) );

		register_rest_route( self::NS, '/profile/follow-ups/(?P<id>\d+)/done', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'complete_follow_up' ),
			'permission_callback' => array( $this, 'check_logged_in' ),
		) );

		// [2026-08-22 Johnny Chu] PHASE-TBP-5.3 — expose transparent lead-priority heuristics without inventing an AI qualification field or CRM table.
		register_rest_route( self::NS, '/profile/lead-priorities', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'lead_priorities' ),
			'permission_callback' => array( $this, 'check_logged_in' ),
		) );

		// [2026-08-21 Johnny Chu] PHASE-TWIN-BRAIN-PROFILE — TBP-2: expose only the current owner's CRM conversation projection.
		register_rest_route( self::NS, '/profile/conversations', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'conversations' ),
			'permission_callback' => array( $this, 'check_logged_in' ),
		) );

		// [2026-08-21 Johnny Chu] PHASE-TWIN-BRAIN-PROFILE — TBP-4: owner-scoped campaign counters from the optional Mabel provider.
		register_rest_route( self::NS, '/profile/campaigns', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'campaigns' ),
			'permission_callback' => array( $this, 'check_logged_in' ),
		) );

		// [2026-08-21 Johnny Chu] PHASE-TWIN-BRAIN-PROFILE — expose the canonical WebChat Guru binding to Profile admins without duplicating character configuration.
		register_rest_route( self::NS, '/profile/chat-config', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'chat_config' ),
				'permission_callback' => array( $this, 'check_admin' ),
			),
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'update_chat_config' ),
				'permission_callback' => array( $this, 'check_admin' ),
			),
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

		// [2026-08-24 Johnny Chu] PHASE-PROFILE-PORTFOLIO — apply an owner-selected local template through the shared SiteConfig source of truth.
		register_rest_route( self::NS, '/profile/cards/(?P<id>\d+)/template', array(
			'methods'             => WP_REST_Server::EDITABLE,
			'callback'            => array( $this, 'apply_template' ),
			'permission_callback' => array( $this, 'check_logged_in' ),
		) );

		// [2026-08-21 Johnny Chu] PHASE-PROFILE-QR — Wave 6.2: quick-add shortcode blocks from the simple Profile editor.
		register_rest_route( self::NS, '/profile/cards/(?P<id>\d+)/shortcodes', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_shortcodes' ),
				'permission_callback' => array( $this, 'check_logged_in' ),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'add_shortcode' ),
				'permission_callback' => array( $this, 'check_logged_in' ),
			),
		) );

		register_rest_route( self::NS, '/profile/cards/(?P<id>\d+)/shortcodes/(?P<block_id>[a-zA-Z0-9_-]+)', array(
			'methods'             => WP_REST_Server::DELETABLE,
			'callback'            => array( $this, 'remove_shortcode' ),
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

		register_rest_route( self::NS, '/profile/cards/(?P<id>\d+)/chat-transcript', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'chat_transcript' ),
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

		register_rest_route( self::NS, '/profile/logs', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'traffic_logs' ),
			'permission_callback' => array( $this, 'check_logged_in' ),
		) );

	}

	public function check_logged_in() {
		// [2026-08-20 Johnny Chu] PHASE-PROFILE-QR — fail closed before any Profile query.
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'auth_required', 'Vui lòng đăng nhập để quản lý danh thiếp.', array( 'status' => 401 ) );
		}
		return true;
	}

	public function check_admin() {
		// [2026-08-21 Johnny Chu] PHASE-TWIN-BRAIN-PROFILE — TBP-4: only administrators may change shared wheel assignments.
		$logged_in = $this->check_logged_in();
		if ( is_wp_error( $logged_in ) ) { return $logged_in; }
		return current_user_can( 'manage_options' ) ? true : new WP_Error( 'permission_denied', 'Bạn không có quyền quản lý vòng quay.', array( 'status' => 403 ) );
	}

	public function templates() {
		// [2026-08-20 Johnny Chu] PHASE-PROFILE-QR — return a stable catalog without database access.
		return rest_ensure_response( array(
			'success' => true,
			'items'   => array(
				array( 'key' => 'business-card-compact', 'label' => 'Danh thiếp gọn', 'variant' => 'compact' ),
				array( 'key' => 'business-card-full', 'label' => 'Danh thiếp đầy đủ', 'variant' => 'full' ),
				array( 'key' => 'business-card-portfolio', 'label' => 'Portfolio dark', 'variant' => 'full' ),
			),
		) );
	}

	public function wheels() {
		// [2026-08-21 Johnny Chu] PHASE-TWIN-BRAIN-PROFILE — TBP-4: provider registry applies ownership and assignment filtering.
		if ( ! class_exists( 'BizCity_Profile_Wheel_Provider_Registry' ) ) {
			return rest_ensure_response( array( 'success' => true, 'items' => array(), '_degraded' => true ) );
		}
		$items = BizCity_Profile_Wheel_Provider_Registry::list_for_user( get_current_user_id(), current_user_can( 'manage_options' ) );
		return rest_ensure_response( array( 'success' => true, 'items' => $items ) );
	}

	public function wheel_members( WP_REST_Request $request ) {
		// [2026-08-21 Johnny Chu] PHASE-TWIN-BRAIN-PROFILE — TBP-4: expose assignment state only to administrators.
		$provider = sanitize_key( (string) $request['provider'] );
		$wheel_id = absint( $request['id'] );
		if ( ! class_exists( 'BizCity_Profile_Wheel_Provider_Registry' ) || ! BizCity_Profile_Wheel_Provider_Registry::can_use( $provider, $wheel_id, get_current_user_id(), true ) ) {
			return $this->error_response( 'not_found', 'Không tìm thấy vòng quay.', 'Chọn lại vòng quay đã publish rồi thử lại.', 'profile_wheel_not_found', 404 );
		}
		return rest_ensure_response( array( 'success' => true, 'provider' => $provider, 'id' => $wheel_id, 'user_ids' => BizCity_Profile_Wheel_Provider_Registry::assigned_user_ids( $provider, $wheel_id ) ) );
	}

	public function update_wheel_members( WP_REST_Request $request ) {
		// [2026-08-21 Johnny Chu] PHASE-TWIN-BRAIN-PROFILE — TBP-4: persist many-to-many member assignment through the provider boundary.
		$provider = sanitize_key( (string) ( $request['provider'] ?: $request->get_param( 'provider' ) ?: BizCity_Profile_Wheel_Provider_Registry::default_key() ) );
		$wheel_id = absint( $request['id'] );
		$payload = $request->get_json_params();
		$user_ids = is_array( $payload ) && is_array( $payload['user_ids'] ?? null ) ? $payload['user_ids'] : $request->get_param( 'user_ids' );
		if ( ! is_array( $user_ids ) || count( $user_ids ) > 100 || ! class_exists( 'BizCity_Profile_Wheel_Provider_Registry' ) || ! BizCity_Profile_Wheel_Provider_Registry::can_use( $provider, $wheel_id, get_current_user_id(), true ) ) {
			return $this->error_response( 'invalid_param', 'Danh sách thành viên chưa hợp lệ.', 'Kiểm tra vòng quay và danh sách user ID rồi thử lại.', 'profile_wheel_members_invalid', 400 );
		}
		$requested_ids = array_values( array_unique( array_filter( array_map( 'absint', $user_ids ) ) ) );
		$existing_ids = empty( $requested_ids ) ? array() : array_values( array_unique( array_map( 'absint', (array) get_users( array( 'include' => $requested_ids, 'fields' => 'ID' ) ) ) ) );
		if ( count( $existing_ids ) !== count( $requested_ids ) ) {
			return $this->error_response( 'invalid_param', 'Có user ID không tồn tại.', 'Xóa user ID không hợp lệ rồi lưu lại.', 'profile_wheel_member_user_invalid', 400 );
		}
		$saved = BizCity_Profile_Wheel_Provider_Registry::set_assigned_user_ids( $provider, $wheel_id, $existing_ids );
		if ( ! $saved ) {
			return $this->error_response( 'gateway_degraded', 'Không cập nhật được thành viên của vòng quay.', 'Kiểm tra quyền quản trị và thử lại.', 'profile_wheel_members_save_failed', 502 );
		}
		return rest_ensure_response( array( 'success' => true, 'provider' => $provider, 'id' => $wheel_id, 'user_ids' => $existing_ids ) );
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

	public function create_contact_follow_up( WP_REST_Request $request ) {
		// [2026-08-21 Johnny Chu] PHASE-TBP-5.1 — verify CRM contact ownership through the existing Profile bridge before creating a Scheduler row.
		$contact_id = absint( $request['id'] );
		$contact = null;
		foreach ( BizCity_Personal_Profile_Contacts_Bridge::get_for_owner( get_current_user_id() ) as $candidate ) {
			if ( $contact_id === (int) ( $candidate['id'] ?? 0 ) ) { $contact = $candidate; break; }
		}
		if ( ! is_array( $contact ) ) {
			return $this->error_response( 'not_found', 'Không tìm thấy liên hệ thuộc Profile của bạn.', 'Mở lại danh bạ rồi chọn một liên hệ hợp lệ.', 'profile_contact_not_found', 404 );
		}
		if ( ! class_exists( 'BizCity_Scheduler_Manager' ) ) {
			return $this->error_response( 'module_not_loaded', 'Lịch follow-up chưa sẵn sàng.', 'Kiểm tra Scheduler rồi thử lại.', 'profile_follow_up_scheduler_missing', 503 );
		}
		$body = $request->get_json_params();
		$body = is_array( $body ) ? $body : array();
		$start_at = sanitize_text_field( (string) ( $body['start_at'] ?? '' ) );
		if ( '' === $start_at ) { $start_at = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) + DAY_IN_SECONDS ); }
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $start_at ) || false === strtotime( $start_at ) ) {
			return $this->error_response( 'invalid_param', 'Thời gian follow-up chưa hợp lệ.', 'Chọn thời gian theo định dạng YYYY-MM-DD HH:MM:SS.', 'profile_follow_up_time_invalid', 400 );
		}
		$name = sanitize_text_field( (string) ( $contact['name'] ?? '' ) );
		$title = sanitize_text_field( (string) ( $body['title'] ?? '' ) );
		if ( '' === $title ) { $title = 'Follow-up lead' . ( $name ? ': ' . $name : '' ); }
		$event_id = BizCity_Scheduler_Manager::instance()->create_event( array(
			'user_id'        => get_current_user_id(),
			'title'          => $title,
			'description'    => 'Follow-up contact từ Profile #' . (int) ( $contact['profile_card_id'] ?? 0 ),
			'start_at'       => $start_at,
			'reminder_min'   => max( 0, min( 10080, absint( $body['reminder_min'] ?? 60 ) ) ),
			'event_type'     => 'crm_conversation_task',
			'source'         => 'crm_inbox',
			'contact_id'     => $contact_id,
			'metadata'       => array( 'source' => 'profile', 'profile_card_id' => (int) ( $contact['profile_card_id'] ?? 0 ), 'contact_id' => $contact_id, 'acquisition_source' => sanitize_key( (string) ( $contact['acquisition_source'] ?? 'profile' ) ) ),
		) );
		if ( is_wp_error( $event_id ) || ! $event_id ) {
			return $this->error_response( 'gateway_degraded', 'Không tạo được lịch follow-up.', 'Kiểm tra Scheduler rồi thử lại.', 'profile_follow_up_create_failed', 502 );
		}
		return rest_ensure_response( array( 'success' => true, 'event_id' => (int) $event_id, 'contact_id' => $contact_id, 'start_at' => $start_at ) );
	}

	public function follow_ups( WP_REST_Request $request ) {
		// [2026-08-21 Johnny Chu] PHASE-TBP-5.2 — classify active Scheduler follow-ups as overdue, today, or upcoming in WordPress local time.
		if ( ! class_exists( 'BizCity_Scheduler_Manager' ) ) {
			return rest_ensure_response( array( 'success' => true, 'items' => array(), 'groups' => array( 'overdue' => array(), 'today' => array(), 'upcoming' => array() ), '_degraded' => true ) );
		}
		$now_timestamp = current_time( 'timestamp' );
		$today = wp_date( 'Y-m-d', $now_timestamp );
		$from = wp_date( 'Y-m-d H:i:s', $now_timestamp - ( 90 * DAY_IN_SECONDS ) );
		$to = wp_date( 'Y-m-d H:i:s', $now_timestamp + ( 90 * DAY_IN_SECONDS ) );
		$events = BizCity_Scheduler_Manager::instance()->get_events( get_current_user_id(), $from, $to, 'active', 'crm_conversation_task' );
		$contact_map = array();
		foreach ( BizCity_Personal_Profile_Contacts_Bridge::get_for_owner( get_current_user_id() ) as $contact ) {
			$contact_map[ (int) ( $contact['id'] ?? 0 ) ] = $contact;
		}
		$groups = array( 'overdue' => array(), 'today' => array(), 'upcoming' => array() );
		foreach ( $events as $event ) {
			if ( ! $this->is_profile_follow_up_event( $event ) ) { continue; }
			$start_at = (string) ( $event['start_at'] ?? '' );
			$bucket = $this->follow_up_bucket( $start_at, $today );
			$groups[ $bucket ][] = $this->follow_up_payload( $event, $bucket, $contact_map );
		}
		foreach ( $groups as $bucket => $items ) {
			usort( $groups[ $bucket ], function ( $left, $right ) { return strcmp( (string) $left['start_at'], (string) $right['start_at'] ); } );
		}
		$scope = sanitize_key( (string) $request->get_param( 'scope' ) );
		if ( ! in_array( $scope, array( 'overdue', 'today', 'upcoming' ), true ) ) { $scope = 'all'; }
		$items = 'all' === $scope ? array_merge( $groups['overdue'], $groups['today'], $groups['upcoming'] ) : $groups[ $scope ];
		return rest_ensure_response( array( 'success' => true, 'scope' => $scope, 'today' => $today, 'items' => $items, 'groups' => $groups ) );
	}

	private function follow_up_bucket( $start_at, $today ) {
		// [2026-08-21 Johnny Chu] PHASE-TBP-5.2 — classify by local calendar date, keeping overdue/today/upcoming behavior deterministic for DDV.
		$event_day = substr( (string) $start_at, 0, 10 );
		$today = substr( (string) $today, 0, 10 );
		return $event_day < $today ? 'overdue' : ( $event_day === $today ? 'today' : 'upcoming' );
	}

	private function is_profile_follow_up_event( $event ) {
		// [2026-08-22 Johnny Chu] PHASE-TBP-5.2 — keep Profile queue ownership separate from other CRM conversation tasks.
		if ( ! is_array( $event ) || 'crm_conversation_task' !== (string) ( $event['event_type'] ?? '' ) ) { return false; }
		$metadata = is_string( $event['metadata'] ?? null ) ? json_decode( $event['metadata'], true ) : ( is_array( $event['metadata'] ?? null ) ? $event['metadata'] : array() );
		return is_array( $metadata ) && 'profile' === (string) ( $metadata['source'] ?? '' ) && absint( $metadata['profile_card_id'] ?? 0 ) > 0 && absint( $metadata['contact_id'] ?? 0 ) > 0;
	}

	public function complete_follow_up( WP_REST_Request $request ) {
		// [2026-08-21 Johnny Chu] PHASE-TBP-5.2 — mark only the current owner's CRM follow-up done through Scheduler ownership guards.
		$event_id = absint( $request['id'] );
		if ( ! class_exists( 'BizCity_Scheduler_Manager' ) ) {
			return $this->error_response( 'module_not_loaded', 'Scheduler chưa sẵn sàng.', 'Kiểm tra Scheduler rồi thử lại.', 'profile_follow_up_scheduler_missing', 503 );
		}
		$manager = BizCity_Scheduler_Manager::instance();
		$event = $manager->get_event( $event_id, get_current_user_id() );
		$event_array = $event ? get_object_vars( $event ) : array();
		if ( ! $event || ! $this->is_profile_follow_up_event( $event_array ) ) {
			return $this->error_response( 'not_found', 'Không tìm thấy follow-up thuộc về bạn.', 'Mở lại hàng đợi follow-up rồi thử lại.', 'profile_follow_up_not_found', 404 );
		}
		$result = $manager->update_event( $event_id, array( 'status' => 'done' ), get_current_user_id() );
		if ( is_wp_error( $result ) || true !== $result ) {
			return $this->error_response( 'gateway_degraded', 'Không thể hoàn tất follow-up.', 'Kiểm tra Scheduler rồi thử lại.', 'profile_follow_up_complete_failed', 502 );
		}
		$updated_event = $manager->get_event( $event_id, get_current_user_id() );
		if ( ! $updated_event || 'done' !== (string) ( $updated_event->status ?? '' ) ) {
			return $this->error_response( 'gateway_degraded', 'Scheduler chưa xác nhận hoàn tất follow-up.', 'Tải lại hàng đợi rồi thử lại.', 'profile_follow_up_persistence_unconfirmed', 502 );
		}
		return rest_ensure_response( array( 'success' => true, 'event_id' => $event_id, 'status' => 'done' ) );
	}

	public function lead_priorities() {
		// [2026-08-22 Johnny Chu] PHASE-TBP-5.3 — rank only owner-scoped existing CRM contacts; this is a visible heuristic, not an LLM claim.
		$contacts = BizCity_Personal_Profile_Contacts_Bridge::get_for_owner( get_current_user_id() );
		$conversations = BizCity_Personal_Profile_Contacts_Bridge::get_conversations_for_owner( get_current_user_id(), 100 );
		$conversation_map = array();
		foreach ( $conversations as $conversation ) {
			$contact_id = (int) ( $conversation['contact_id'] ?? 0 );
			if ( $contact_id <= 0 ) { continue; }
			if ( ! isset( $conversation_map[ $contact_id ] ) || strcmp( (string) ( $conversation['last_activity_at'] ?? '' ), (string) ( $conversation_map[ $contact_id ]['last_activity_at'] ?? '' ) ) > 0 ) {
				$conversation_map[ $contact_id ] = $conversation;
			}
		}
		$items = array();
		$counts = array( 'high' => 0, 'medium' => 0, 'low' => 0 );
		$now = current_time( 'timestamp' );
		foreach ( $contacts as $contact ) {
			$contact_id = (int) ( $contact['id'] ?? 0 );
			$conversation = $conversation_map[ $contact_id ] ?? array();
			$score = 0;
			$reasons = array();
			$crm_priority = (int) ( $conversation['priority'] ?? 0 );
			if ( $crm_priority > 0 ) { $score += min( 30, $crm_priority * 10 ); $reasons[] = 'CRM priority'; }
			if ( (int) ( $conversation['unread_count'] ?? 0 ) > 0 ) { $score += 25; $reasons[] = 'có tin chưa đọc'; }
			if ( ! empty( $contact['email'] ) || ! empty( $contact['phone'] ) ) { $score += 20; $reasons[] = 'đủ kênh liên hệ'; }
			$activity = ! empty( $conversation['last_activity_at'] ) ? strtotime( (string) $conversation['last_activity_at'] ) : 0;
			if ( $activity > 0 && $activity >= $now - ( 7 * DAY_IN_SECONDS ) ) { $score += 15; $reasons[] = 'hoạt động 7 ngày'; }
			if ( ! empty( $conversation['last_message'] ) ) { $score += 10; $reasons[] = 'đã có hội thoại'; }
			$level = $score >= 60 ? 'high' : ( $score >= 30 ? 'medium' : 'low' );
			$counts[ $level ]++;
			$items[] = array(
				'contact_id'      => $contact_id,
				'profile_card_id' => (int) ( $contact['profile_card_id'] ?? 0 ),
				'name'            => sanitize_text_field( (string) ( $contact['name'] ?? '' ) ),
				'email'           => sanitize_email( (string) ( $contact['email'] ?? '' ) ),
				'phone'           => sanitize_text_field( (string) ( $contact['phone'] ?? '' ) ),
				'score'           => $score,
				'level'           => $level,
				'reasons'         => $reasons,
				'last_activity_at' => (string) ( $conversation['last_activity_at'] ?? '' ),
			);
		}
		usort( $items, function ( $left, $right ) { return (int) $right['score'] <=> (int) $left['score']; } );
		return rest_ensure_response( array( 'success' => true, 'heuristic' => true, 'items' => array_slice( $items, 0, 50 ), 'counts' => $counts ) );
	}

	private function follow_up_payload( $event, $bucket, array $contact_map = array() ) {
		$metadata = is_array( $event ) && is_string( $event['metadata'] ?? null ) ? json_decode( $event['metadata'], true ) : array();
		$contact_id = (int) ( $event['contact_id'] ?? ( is_array( $metadata ) ? ( $metadata['contact_id'] ?? 0 ) : 0 ) );
		$contact = is_array( $contact_map[ $contact_id ] ?? null ) ? $contact_map[ $contact_id ] : array();
		return array(
			'id'              => (int) ( $event['id'] ?? 0 ),
			'title'           => (string) ( $event['title'] ?? '' ),
			'description'     => (string) ( $event['description'] ?? '' ),
			'start_at'        => (string) ( $event['start_at'] ?? '' ),
			'reminder_min'    => (int) ( $event['reminder_min'] ?? 0 ),
			'status'          => (string) ( $event['status'] ?? 'active' ),
			'event_type'      => 'crm_conversation_task',
			'contact_id'      => $contact_id,
			'contact_name'    => sanitize_text_field( (string) ( $contact['name'] ?? '' ) ),
			'contact_channel' => sanitize_text_field( (string) ( $contact['email'] ?? $contact['phone'] ?? '' ) ),
			'profile_card_id' => (int) ( is_array( $metadata ) ? ( $metadata['profile_card_id'] ?? 0 ) : 0 ),
			'bucket'          => $bucket,
		);
	}

	public function conversations( WP_REST_Request $request ) {
		// [2026-08-21 Johnny Chu] PHASE-TWIN-BRAIN-PROFILE — TBP-2: CRM is source of truth; Profile only projects owned card conversations.
		$limit = absint( $request->get_param( 'limit' ) ?: 50 );
		return rest_ensure_response( array(
			'success' => true,
			'items'   => BizCity_Personal_Profile_Contacts_Bridge::get_conversations_for_owner( get_current_user_id(), $limit ),
		) );
	}

	public function campaigns() {
		// [2026-08-21 Johnny Chu] PHASE-TWIN-BRAIN-PROFILE — TBP-4: no Profile campaign table; return provider projection only.
		$items = class_exists( 'BizCity_Personal_Profile_Wheel_Bridge' ) ? BizCity_Personal_Profile_Wheel_Bridge::get_campaigns_for_owner( get_current_user_id() ) : array();
		return rest_ensure_response( array( 'success' => true, 'items' => $items, '_degraded' => empty( $items ) ) );
	}

	public function chat_config() {
		// [2026-08-21 Johnny Chu] PHASE-TWIN-BRAIN-PROFILE — read WebChat binding plus publishable Guru roster for the CSKH chat selector.
		if ( ! class_exists( 'BizCity_Channel_Binding' ) ) {
			return rest_ensure_response( array( 'success' => true, 'configured' => false, 'binding' => null, 'gurus' => array(), '_degraded' => true ) );
		}
		$binding = BizCity_Channel_Binding::resolve( 'WEBCHAT', (string) get_current_blog_id() );
		$gurus = array();
		if ( class_exists( 'BizCity_Knowledge_Database' ) ) {
			foreach ( (array) BizCity_Knowledge_Database::instance()->get_characters( array( 'limit' => 200 ) ) as $guru ) {
				$status = strtolower( (string) ( $guru->status ?? '' ) );
				if ( ! in_array( $status, array( 'active', 'published' ), true ) ) { continue; }
				$gurus[] = array( 'id' => (int) ( $guru->id ?? 0 ), 'name' => (string) ( $guru->name ?? '' ), 'status' => $status, 'system_instruction_ready' => '' !== trim( (string) ( $guru->system_prompt ?? '' ) ) );
			}
		}
		$character_id = is_array( $binding ) ? (int) ( $binding['character_id'] ?? 0 ) : 0;
		return rest_ensure_response( array(
			'success'    => true,
			'configured' => $character_id > 0,
			'channel'    => 'WEBCHAT',
			'account_id' => (string) get_current_blog_id(),
			'role'       => 'customer_service',
			'binding'    => is_array( $binding ) ? array( 'id' => (int) $binding['id'], 'character_id' => $character_id, 'status' => (int) $binding['status'], 'mode' => (string) ( $binding['mode'] ?? 'auto' ), 'auto_reply' => (int) ( $binding['auto_reply'] ?? 0 ) ) : null,
			'gurus'      => $gurus,
		) );
	}

	public function update_chat_config( WP_REST_Request $request ) {
		// [2026-08-21 Johnny Chu] PHASE-TWIN-BRAIN-PROFILE — bind a published Guru to the existing site WebChat channel; system instruction remains owned by the Guru editor.
		$character_id = absint( $request->get_param( 'character_id' ) );
		if ( $character_id <= 0 || ! class_exists( 'BizCity_Channel_Binding' ) ) {
			return $this->error_response( 'invalid_param', 'Guru CSKH chưa hợp lệ.', 'Chọn một Guru đã publish rồi thử lại.', 'profile_guru_invalid', 400 );
		}
		if ( class_exists( 'BizCity_Knowledge_Database' ) ) {
			$guru = BizCity_Knowledge_Database::instance()->get_character( $character_id );
			$status = $guru ? strtolower( (string) ( $guru->status ?? '' ) ) : '';
			if ( ! $guru || ! in_array( $status, array( 'active', 'published' ), true ) ) {
				return $this->error_response( 'invalid_param', 'Guru CSKH chưa được publish.', 'Publish Guru rồi chọn lại cho WebChat Profile.', 'profile_guru_not_publishable', 400 );
			}
			if ( '' === trim( (string) ( $guru->system_prompt ?? '' ) ) ) {
				return $this->error_response( 'invalid_param', 'Guru CSKH chưa có system instruction.', 'Mở Guru, thêm hướng dẫn chăm sóc khách hàng rồi thử lại.', 'profile_guru_prompt_missing', 400 );
			}
		}
		$binding_id = BizCity_Channel_Binding::upsert( array(
			'platform'     => 'WEBCHAT',
			'account_id'   => (string) get_current_blog_id(),
			'character_id' => $character_id,
			'mode'         => BizCity_Channel_Binding::MODE_AUTO,
			'auto_reply'   => 1,
			'status'       => 1,
			'meta'         => array( 'source' => 'profile_chat_config', 'role' => 'customer_service' ),
		) );
		if ( ! $binding_id ) {
			return $this->error_response( 'gateway_degraded', 'Không lưu được Guru cho chat Profile.', 'Kiểm tra Channel Gateway và thử lại.', 'profile_guru_binding_failed', 502 );
		}
		return rest_ensure_response( array( 'success' => true, 'binding_id' => (int) $binding_id, 'character_id' => $character_id, 'role' => 'customer_service' ) );
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
			if ( array_key_exists( 'publicCapabilities', $normalized ) ) {
				// [2026-08-24 Johnny Chu] PHASE-TBP-3 — capability edits invalidate the published snapshot until the owner publishes again.
				unset( $config['blocks'][ $index ]['props']['publicGraphSnapshot'] );
				unset( $config['blocks'][ $index ]['props']['publicPortfolioSnapshot'] );
			}
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

	public function apply_template( WP_REST_Request $request ) {
		// [2026-08-24 Johnny Chu] PHASE-PROFILE-PORTFOLIO — replace only the selected card layout, preserving owner data and canonical CRM/WebChat configuration.
		$card = BizCity_Personal_Profile_Card_Manager::get( (int) $request['id'], get_current_user_id() );
		if ( ! is_array( $card ) ) {
			return $this->error_response( 'not_found', 'Không tìm thấy danh thiếp Profile.', 'Mở lại danh sách danh thiếp rồi thử lại.', 'profile_card_not_found', 404 );
		}
		$template_key = sanitize_key( (string) $request->get_param( 'template_key' ) );
		$allowed_templates = array( 'business-card-compact', 'business-card-full', 'business-card-portfolio' );
		if ( ! in_array( $template_key, $allowed_templates, true ) ) {
			return $this->error_response( 'invalid_param', 'Template Profile chưa hợp lệ.', 'Chọn một template có trong danh sách rồi thử lại.', 'profile_template_invalid', 400 );
		}
		$project = BizCity_Personal_Profile_BZPB_Bridge::get_project_config( (int) $card['bzpb_project_id'], get_current_user_id() );
		if ( is_wp_error( $project ) ) {
			return $this->error_response( 'not_found', 'Không đọc được cấu hình Profile.', 'Tải lại Profile rồi thử lại.', 'profile_config_not_found', 404 );
		}
		$template_file = BIZCITY_PERSONAL_DIR . 'includes/profile/templates/' . $template_key . '.json';
		$template_config = is_readable( $template_file ) ? json_decode( (string) file_get_contents( $template_file ), true ) : null;
		if ( ! is_array( $template_config ) || ! is_array( $template_config['blocks'] ?? null ) ) {
			return $this->error_response( 'not_found', 'Không tìm thấy template Profile.', 'Chọn lại template rồi thử lại.', 'profile_template_not_found', 400 );
		}
		$current_props = array();
		foreach ( is_array( $project['config']['blocks'] ?? null ) ? $project['config']['blocks'] : array() as $block ) {
			if ( 'profile-card' === (string) ( $block['type'] ?? '' ) && is_array( $block['props'] ?? null ) ) {
				$current_props = $block['props'];
				break;
			}
		}
		$preserve_keys = array( 'heroStyle', 'brainAccentColor', 'slug', 'avatarUrl', 'coverUrl', 'logoUrl', 'name', 'jobTitle', 'company', 'bio', 'contactFields', 'socialLinks', 'messagingLinks', 'chatEntrypoints', 'quickActions', 'ctaSave', 'ctaShare', 'twinBrainEnabled', 'twinBrainGreeting', 'twinBrainSuggestedQuestions', 'twinBrainGoal', 'publicCapabilities', 'giftWheelProvider', 'giftWheelId' );
		$profile_found = false;
		foreach ( $template_config['blocks'] as $index => $block ) {
			if ( 'profile-card' !== (string) ( $block['type'] ?? '' ) ) { continue; }
			$props = is_array( $block['props'] ?? null ) ? $block['props'] : array();
			foreach ( $preserve_keys as $key ) {
				if ( array_key_exists( $key, $current_props ) ) { $props[ $key ] = $current_props[ $key ]; }
			}
			$props['profileCardId'] = (int) $card['id'];
			$template_config['blocks'][ $index ]['props'] = $props;
			$profile_found = true;
			break;
		}
		if ( ! $profile_found ) {
			return $this->error_response( 'invalid_param', 'Template thiếu Profile card.', 'Chọn lại template danh thiếp rồi thử lại.', 'profile_template_invalid', 400 );
		}
		$current_cf7_form_id = 0;
		foreach ( is_array( $project['config']['blocks'] ?? null ) ? $project['config']['blocks'] : array() as $block ) {
			if ( 'lead-form' === (string) ( $block['type'] ?? '' ) ) {
				$current_cf7_form_id = absint( $block['props']['cf7FormId'] ?? 0 );
				break;
			}
		}
		if ( $current_cf7_form_id <= 0 ) {
			$cf7 = BizCity_Personal_Profile_BZPB_Bridge::ensure_contact_form( (int) $card['bzpb_project_id'] );
			if ( is_wp_error( $cf7 ) && class_exists( 'WPCF7_ContactForm' ) ) {
				return $this->error_response( 'gateway_degraded', 'Không chuẩn bị được form liên hệ.', 'Kiểm tra Contact Form 7 rồi thử lại.', 'profile_contact_form_failed', 503 );
			}
			$current_cf7_form_id = is_wp_error( $cf7 ) ? 0 : absint( $cf7['cf7_form_id'] );
		}
		foreach ( $template_config['blocks'] as $index => $block ) {
			if ( 'lead-form' === (string) ( $block['type'] ?? '' ) ) {
				if ( $current_cf7_form_id > 0 ) { $template_config['blocks'][ $index ]['props']['cf7FormId'] = $current_cf7_form_id; }
				break;
			}
		}
		$template_config = $this->with_public_graph_snapshot( $template_config, (int) $card['id'] );
		$template_config = $this->with_public_portfolio_snapshot( $template_config );
		$saved = BizCity_Personal_Profile_BZPB_Bridge::save_project_config( (int) $card['bzpb_project_id'], get_current_user_id(), $template_config, $project['title'] );
		if ( is_wp_error( $saved ) ) {
			return $this->error_response( 'gateway_degraded', 'Không áp dụng được template Profile.', 'Kiểm tra Page Builder rồi thử lại.', 'profile_template_apply_failed', 502 );
		}
		$page = null;
		if ( 'published' === (string) ( $card['status'] ?? '' ) ) {
			$page = BizCity_Personal_Profile_BZPB_Bridge::publish( (int) $card['bzpb_project_id'], (int) $card['id'] );
			if ( is_wp_error( $page ) ) {
				return $this->error_response( 'gateway_degraded', 'Đã lưu template nhưng chưa cập nhật trang public.', 'Mở lại template và publish lại sau.', 'profile_template_publish_failed', 502 );
			}
		}
		BizCity_Personal_Profile_Card_Manager::update( (int) $card['id'], get_current_user_id(), array( 'template_key' => $template_key ) );
		if ( class_exists( 'BizCity_Cache' ) ) { BizCity_Cache::flush_group( self::PUBLIC_URL_CACHE_GROUP ); }
		$updated_project = BizCity_Personal_Profile_BZPB_Bridge::get_project_config( (int) $card['bzpb_project_id'], get_current_user_id() );
		$content = is_wp_error( $updated_project ) ? array() : $this->profile_content_from_config( $updated_project['config'] );
		return rest_ensure_response( array( 'success' => true, 'template_key' => $template_key, 'content' => $content, 'published' => ! is_null( $page ) ) );
	}

	/**
	 * List `shortcode`-type blocks currently in the card's Page Builder project.
	 * Same storage as the advanced editor — this is a read of the shared SiteConfig,
	 * not a separate copy.
	 */
	public function list_shortcodes( WP_REST_Request $request ) {
		// [2026-08-21 Johnny Chu] PHASE-PROFILE-QR — Wave 6.2 quick-add shortcode
		$card = BizCity_Personal_Profile_Card_Manager::get( (int) $request['id'], get_current_user_id() );
		if ( ! is_array( $card ) ) {
			return $this->error_response( 'not_found', 'Không tìm thấy danh thiếp Profile.', 'Mở lại danh sách danh thiếp của bạn.', 'profile_card_not_found', 404 );
		}
		$project = BizCity_Personal_Profile_BZPB_Bridge::get_project_config( (int) $card['bzpb_project_id'], get_current_user_id() );
		if ( is_wp_error( $project ) ) {
			return $this->error_response( 'not_found', 'Không đọc được cấu hình Profile.', 'Tải lại danh thiếp rồi thử lại.', 'profile_config_not_found', 404 );
		}
		return rest_ensure_response( array( 'success' => true, 'items' => $this->shortcode_blocks_from_config( $project['config'] ) ) );
	}

	public function add_shortcode( WP_REST_Request $request ) {
		// [2026-08-21 Johnny Chu] PHASE-PROFILE-QR — Wave 6.2: append a new shortcode block into the shared BZPB project config.
		$card = BizCity_Personal_Profile_Card_Manager::get( (int) $request['id'], get_current_user_id() );
		if ( ! is_array( $card ) ) {
			return $this->error_response( 'not_found', 'Không tìm thấy danh thiếp Profile.', 'Mở lại danh sách danh thiếp của bạn.', 'profile_card_not_found', 404 );
		}
		$shortcode = trim( (string) $request->get_param( 'shortcode' ) );
		if ( '' === $shortcode || ! preg_match( '/^\[\s*[a-zA-Z0-9_-]+/', $shortcode ) ) {
			return $this->error_response( 'invalid_param', 'Shortcode chưa hợp lệ.', 'Nhập shortcode dạng [ten-shortcode ...].', 'profile_shortcode_invalid', 400 );
		}
		$label = sanitize_text_field( (string) $request->get_param( 'label' ) );
		$project = BizCity_Personal_Profile_BZPB_Bridge::get_project_config( (int) $card['bzpb_project_id'], get_current_user_id() );
		if ( is_wp_error( $project ) ) {
			return $this->error_response( 'not_found', 'Không đọc được cấu hình Profile.', 'Tải lại danh thiếp rồi thử lại.', 'profile_config_not_found', 404 );
		}
		$config = $project['config'];
		$new_block = array(
			'id'      => 'bzp-sc-' . substr( md5( wp_generate_uuid4() ), 0, 12 ),
			'type'    => 'shortcode',
			'variant' => 'default',
			'props'   => array( 'shortcode' => $shortcode, 'label' => $label ),
		);
		// [2026-08-21 Johnny Chu] PHASE-PROFILE-QR — Wave 6.2: place quick-added shortcode directly after lead-form in the active page.
		$config = $this->insert_shortcode_after_lead_form( $config, $new_block );
		$saved = BizCity_Personal_Profile_BZPB_Bridge::save_project_config( (int) $card['bzpb_project_id'], get_current_user_id(), $config, $project['title'] );
		if ( is_wp_error( $saved ) ) {
			return $this->error_response( 'gateway_degraded', 'Không lưu được shortcode lúc này.', 'Kiểm tra Page Builder rồi thử lại.', 'profile_shortcode_save_failed', 502 );
		}
		return rest_ensure_response( array( 'success' => true, 'items' => $this->shortcode_blocks_from_config( $config ) ) );
	}

	private function insert_shortcode_after_lead_form( array $config, array $new_block ) {
		// [2026-08-21 Johnny Chu] PHASE-PROFILE-QR — Wave 6.2: keep paged and legacy configs aligned with the canvas source.
		if ( ! empty( $config['pages'] ) && is_array( $config['pages'] ) ) {
			$page_index = 0;
			$page_blocks = is_array( $config['pages'][ $page_index ]['blocks'] ?? null )
				? $config['pages'][ $page_index ]['blocks']
				: array();
			$insert_at = count( $page_blocks );
			foreach ( $page_blocks as $index => $block ) {
				if ( 'lead-form' === (string) ( $block['type'] ?? '' ) ) {
					$insert_at = (int) $index + 1;
					break;
				}
			}
			array_splice( $page_blocks, $insert_at, 0, array( $new_block ) );
			$config['pages'][ $page_index ]['blocks'] = $page_blocks;
			$config['blocks'] = $page_blocks;
			return $config;
		}

		$blocks = is_array( $config['blocks'] ?? null ) ? $config['blocks'] : array();
		$insert_at = count( $blocks );
		foreach ( $blocks as $index => $block ) {
			if ( 'lead-form' === (string) ( $block['type'] ?? '' ) ) {
				$insert_at = (int) $index + 1;
				break;
			}
		}
		array_splice( $blocks, $insert_at, 0, array( $new_block ) );
		$config['blocks'] = $blocks;
		return $config;
	}

	public function remove_shortcode( WP_REST_Request $request ) {
		// [2026-08-21 Johnny Chu] PHASE-PROFILE-QR — Wave 6.2: remove one shortcode block by id; never touches other block types.
		$card = BizCity_Personal_Profile_Card_Manager::get( (int) $request['id'], get_current_user_id() );
		if ( ! is_array( $card ) ) {
			return $this->error_response( 'not_found', 'Không tìm thấy danh thiếp Profile.', 'Mở lại danh sách danh thiếp của bạn.', 'profile_card_not_found', 404 );
		}
		$block_id = sanitize_text_field( (string) $request['block_id'] );
		$project = BizCity_Personal_Profile_BZPB_Bridge::get_project_config( (int) $card['bzpb_project_id'], get_current_user_id() );
		if ( is_wp_error( $project ) ) {
			return $this->error_response( 'not_found', 'Không đọc được cấu hình Profile.', 'Tải lại danh thiếp rồi thử lại.', 'profile_config_not_found', 404 );
		}
		$config = $project['config'];
		$blocks = is_array( $config['blocks'] ?? null ) ? $config['blocks'] : array();
		$kept   = array();
		$found  = false;
		foreach ( $blocks as $block ) {
			if ( ! $found && 'shortcode' === (string) ( $block['type'] ?? '' ) && $block_id === (string) ( $block['id'] ?? '' ) ) {
				$found = true;
				continue;
			}
			$kept[] = $block;
		}
		if ( ! $found ) {
			return $this->error_response( 'not_found', 'Không tìm thấy shortcode này.', 'Tải lại danh sách rồi thử lại.', 'profile_shortcode_not_found', 404 );
		}
		$config['blocks'] = $kept;
		$saved = BizCity_Personal_Profile_BZPB_Bridge::save_project_config( (int) $card['bzpb_project_id'], get_current_user_id(), $config, $project['title'] );
		if ( is_wp_error( $saved ) ) {
			return $this->error_response( 'gateway_degraded', 'Không xóa được shortcode lúc này.', 'Kiểm tra Page Builder rồi thử lại.', 'profile_shortcode_save_failed', 502 );
		}
		return rest_ensure_response( array( 'success' => true, 'items' => $this->shortcode_blocks_from_config( $config ) ) );
	}

	private function shortcode_blocks_from_config( array $config ) {
		$items = array();
		foreach ( is_array( $config['blocks'] ?? null ) ? $config['blocks'] : array() as $block ) {
			if ( 'shortcode' !== (string) ( $block['type'] ?? '' ) ) { continue; }
			$items[] = array(
				'id'        => (string) ( $block['id'] ?? '' ),
				'shortcode' => (string) ( $block['props']['shortcode'] ?? '' ),
				'label'     => (string) ( $block['props']['label'] ?? '' ),
			);
		}
		return $items;
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
		if ( array_key_exists( 'logoUrl', $payload ) ) {
			$logo_url = $this->normalize_public_url( $payload['logoUrl'] );
			if ( is_wp_error( $logo_url ) ) { return $logo_url; }
			$normalized['logoUrl'] = $logo_url;
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
		if ( array_key_exists( 'messagingLinks', $payload ) ) {
			$links = $this->normalize_messaging_links( $payload['messagingLinks'] );
			if ( is_wp_error( $links ) ) { return $links; }
			$normalized['messagingLinks'] = $links;
		}
		if ( array_key_exists( 'twinBrainEnabled', $payload ) ) {
			$normalized['twinBrainEnabled'] = ! empty( $payload['twinBrainEnabled'] );
		}
		if ( array_key_exists( 'twinBrainGreeting', $payload ) ) {
			$normalized['twinBrainGreeting'] = sanitize_textarea_field( substr( (string) $payload['twinBrainGreeting'], 0, 240 ) );
		}
		if ( array_key_exists( 'twinBrainSuggestedQuestions', $payload ) ) {
			$questions = $payload['twinBrainSuggestedQuestions'];
			if ( ! is_array( $questions ) || count( $questions ) > 4 ) { return new WP_Error( 'invalid_param' ); }
			$normalized['twinBrainSuggestedQuestions'] = array();
			foreach ( $questions as $question ) {
				$question = sanitize_text_field( substr( trim( (string) $question ), 0, 120 ) );
				if ( '' !== $question ) { $normalized['twinBrainSuggestedQuestions'][] = $question; }
			}
		}
		if ( array_key_exists( 'publicCapabilities', $payload ) ) {
			$capabilities = $payload['publicCapabilities'];
			// [2026-08-25 Johnny Chu] PHASE-PROFILE-QUICK-INTRO — keep the public quick-intro list at five items.
			if ( ! is_array( $capabilities ) || count( $capabilities ) > 5 ) { return new WP_Error( 'invalid_param' ); }
			$normalized['publicCapabilities'] = array();
			foreach ( array_slice( $capabilities, 0, 5 ) as $capability ) {
				if ( ! is_array( $capability ) ) { return new WP_Error( 'invalid_param' ); }
				$label = sanitize_text_field( substr( trim( (string) ( $capability['label'] ?? '' ) ), 0, 60 ) );
				if ( '' === $label ) { continue; }
				$normalized['publicCapabilities'][] = array(
					'id'       => sanitize_key( substr( (string) ( $capability['id'] ?? $label ), 0, 40 ) ),
					'label'    => $label,
					'category' => sanitize_text_field( substr( trim( (string) ( $capability['category'] ?? 'expertise' ) ), 0, 40 ) ),
					'weight'   => max( 1, min( 10, (int) ( $capability['weight'] ?? 5 ) ) ),
				);
			}
		}
		if ( array_key_exists( 'twinBrainGoal', $payload ) ) {
			$goal = sanitize_key( (string) $payload['twinBrainGoal'] );
			if ( ! in_array( $goal, array( 'consultation', 'lead_capture', 'appointment', 'support', 'portfolio' ), true ) ) { return new WP_Error( 'invalid_param' ); }
			$normalized['twinBrainGoal'] = $goal;
		}
		if ( array_key_exists( 'giftWheelId', $payload ) ) {
			// [2026-08-21 Johnny Chu] PHASE-TWIN-BRAIN-PROFILE — TBP-4: validate wheel IDs against the selected provider and current member ownership.
			$provider = sanitize_key( (string) ( $payload['giftWheelProvider'] ?? ( class_exists( 'BizCity_Profile_Wheel_Provider_Registry' ) ? BizCity_Profile_Wheel_Provider_Registry::default_key() : '' ) ) );
			$wheel_id = absint( $payload['giftWheelId'] );
			if ( $wheel_id > 0 && ( ! class_exists( 'BizCity_Profile_Wheel_Provider_Registry' ) || ! BizCity_Profile_Wheel_Provider_Registry::can_use( $provider, $wheel_id, get_current_user_id(), current_user_can( 'manage_options' ) ) ) ) { return new WP_Error( 'invalid_param' ); }
			$normalized['giftWheelProvider'] = $wheel_id > 0 ? $provider : ( class_exists( 'BizCity_Profile_Wheel_Provider_Registry' ) ? BizCity_Profile_Wheel_Provider_Registry::default_key() : '' );
			$normalized['giftWheelId'] = $wheel_id;
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

	private function normalize_messaging_links( $links ) {
		if ( ! is_array( $links ) || count( $links ) > 8 ) { return new WP_Error( 'invalid_param' ); }
		$allowed = array( 'whatsapp', 'discord', 'skype', 'telegram', 'zalo' );
		$normalized = array();
		foreach ( $links as $link ) {
			if ( ! is_array( $link ) ) { return new WP_Error( 'invalid_param' ); }
			$platform = sanitize_key( (string) ( $link['platform'] ?? '' ) );
			$value = sanitize_text_field( substr( trim( (string) ( $link['value'] ?? '' ) ), 0, 160 ) );
			if ( ! in_array( $platform, $allowed, true ) || '' === $value ) { continue; }
			$normalized[] = array( 'platform' => $platform, 'value' => $value );
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
		$allowed = array( 'variant', 'profileStyle', 'heroStyle', 'brainAccentColor', 'slug', 'avatarUrl', 'coverUrl', 'logoUrl', 'name', 'jobTitle', 'company', 'bio', 'contactFields', 'socialLinks', 'messagingLinks', 'quickActions', 'ctaSave', 'ctaShare', 'twinBrainEnabled', 'twinBrainGreeting', 'twinBrainSuggestedQuestions', 'twinBrainGoal', 'publicCapabilities', 'publicGraphSnapshot', 'publicPortfolioSnapshot', 'giftWheelProvider', 'giftWheelId' );
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
		// [2026-08-23 Johnny Chu] PHASE-TBP-3 — freeze a public-safe capability snapshot before Page Builder publishes the public page.
		$project = BizCity_Personal_Profile_BZPB_Bridge::get_project_config( (int) $card['bzpb_project_id'], get_current_user_id() );
		if ( is_wp_error( $project ) ) {
			return $this->error_response( 'not_found', 'Không đọc được cấu hình Profile.', 'Tải lại danh thiếp rồi thử publish lại.', 'profile_config_not_found', 404 );
		}
		$snapshot_config = $this->with_public_graph_snapshot( $project['config'], (int) $card['id'] );
		$snapshot_config = $this->with_public_portfolio_snapshot( $snapshot_config );
		$snapshot_saved = BizCity_Personal_Profile_BZPB_Bridge::save_project_config( (int) $card['bzpb_project_id'], get_current_user_id(), $snapshot_config, $project['title'] );
		if ( is_wp_error( $snapshot_saved ) ) {
			return $this->error_response( 'gateway_degraded', 'Không lưu được snapshot Profile lúc này.', 'Kiểm tra Page Builder rồi thử lại.', 'profile_snapshot_save_failed', 502 );
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

	private function with_public_graph_snapshot( array $config, $card_id = 0 ): array {
		// [2026-08-23 Johnny Chu] PHASE-TBP-3 — snapshot only owner-approved public capabilities; never query private KG or memory at publish time.
		foreach ( is_array( $config['blocks'] ?? null ) ? $config['blocks'] : array() as $index => $block ) {
			if ( 'profile-card' !== (string) ( $block['type'] ?? '' ) ) { continue; }
			$props = is_array( $block['props'] ?? null ) ? $block['props'] : array();
			$capabilities = array();
			// [2026-08-25 Johnny Chu] PHASE-PROFILE-QUICK-INTRO — publish at most five public quick-intro items.
			foreach ( array_slice( is_array( $props['publicCapabilities'] ?? null ) ? $props['publicCapabilities'] : array(), 0, 5 ) as $capability ) {
				if ( ! is_array( $capability ) ) { continue; }
				$label = sanitize_text_field( (string) ( $capability['label'] ?? '' ) );
				if ( '' === $label ) { continue; }
				$capabilities[] = array(
					'id'       => sanitize_key( (string) ( $capability['id'] ?? $label ) ),
					'label'    => $label,
					'category' => sanitize_text_field( (string) ( $capability['category'] ?? 'expertise' ) ),
					'weight'   => max( 1, min( 10, (int) ( $capability['weight'] ?? 5 ) ) ),
				);
			}
			$trusted_graph = apply_filters( 'bizcity_profile_public_graph_snapshot', array(), (int) $card_id );
			$redacted_graph = $this->redact_public_graph( $trusted_graph );
			if ( empty( $redacted_graph['nodes'] ) ) {
				foreach ( $capabilities as $capability ) {
					$redacted_graph['nodes'][] = array(
						'id'       => (string) $capability['id'],
						'label'    => (string) $capability['label'],
						'category' => (string) $capability['category'],
						'weight'   => (int) $capability['weight'],
					);
				}
			}
			$props['publicGraphSnapshot'] = array(
				'version'       => 1,
				'source'        => 'profile_public_capabilities',
				'generated_at'  => current_time( 'mysql', true ),
				'capabilities'  => $capabilities,
				'graph'         => $redacted_graph,
				'content_hash'  => hash( 'sha256', wp_json_encode( $capabilities ) ),
				'graph_hash'    => hash( 'sha256', wp_json_encode( $redacted_graph ) ),
			);
			$config['blocks'][ $index ]['props'] = $props;
			break;
		}
		return $config;
	}

	private function redact_public_graph( $graph ): array {
		// [2026-08-24 Johnny Chu] PHASE-TBP-3 — allowlist public graph nodes/edges and discard passage, notebook, memory, score, and debug fields.
		$graph = is_array( $graph ) ? $graph : array();
		$raw_nodes = is_array( $graph['nodes'] ?? null ) ? $graph['nodes'] : ( is_array( $graph['entities'] ?? null ) ? $graph['entities'] : array() );
		$nodes = array();
		$node_ids = array();
		foreach ( array_slice( $raw_nodes, 0, 24 ) as $node ) {
			if ( ! is_array( $node ) ) { continue; }
			$id = sanitize_key( substr( (string) ( $node['id'] ?? '' ), 0, 64 ) );
			$label = $this->public_snapshot_text( $node['label'] ?? ( $node['name'] ?? '' ), 120 );
			if ( '' === $id || '' === $label || isset( $node_ids[ $id ] ) ) { continue; }
			$clean = array(
				'id'       => $id,
				'label'    => $label,
				'category' => $this->public_snapshot_text( $node['category'] ?? 'expertise', 40 ),
				'weight'   => max( 1, min( 10, (int) ( $node['weight'] ?? 5 ) ) ),
			);
			$public_url = $this->normalize_public_url( $node['public_url'] ?? '' );
			if ( ! is_wp_error( $public_url ) && '' !== $public_url ) { $clean['public_url'] = $public_url; }
			$nodes[] = $clean;
			$node_ids[ $id ] = true;
		}
		$edges = array();
		$raw_edges = is_array( $graph['edges'] ?? null ) ? $graph['edges'] : array();
		foreach ( array_slice( $raw_edges, 0, 48 ) as $edge ) {
			if ( ! is_array( $edge ) ) { continue; }
			$source = sanitize_key( substr( (string) ( $edge['source'] ?? '' ), 0, 64 ) );
			$target = sanitize_key( substr( (string) ( $edge['target'] ?? '' ), 0, 64 ) );
			$relation = $this->public_snapshot_text( $edge['relation_public'] ?? '', 60 );
			if ( '' === $source || '' === $target || '' === $relation || ! isset( $node_ids[ $source ], $node_ids[ $target ] ) ) { continue; }
			$edges[] = array( 'source' => $source, 'target' => $target, 'relation_public' => $relation );
		}
		return array( 'nodes' => $nodes, 'edges' => $edges );
	}

	private function with_public_portfolio_snapshot( array $config ): array {
		// [2026-08-24 Johnny Chu] PHASE-TBP-3 — freeze a bounded portfolio projection from public Page Builder blocks; exclude forms, shortcodes, custom HTML, and team/private data.
		$sections = array();
		$allowed_types = array( 'content', 'features', 'cta', 'testimonials', 'stats', 'faq', 'logocloud', 'image', 'gallery', 'video' );
		foreach ( is_array( $config['blocks'] ?? null ) ? $config['blocks'] : array() as $block ) {
			$type = sanitize_key( (string) ( $block['type'] ?? '' ) );
			if ( ! in_array( $type, $allowed_types, true ) || ! empty( $block['hidden'] ) ) { continue; }
			$section = $this->public_portfolio_section( $type, is_array( $block['props'] ?? null ) ? $block['props'] : array() );
			if ( ! empty( $section ) ) { $sections[] = $section; }
			if ( count( $sections ) >= 12 ) { break; }
		}
		foreach ( is_array( $config['blocks'] ?? null ) ? $config['blocks'] : array() as $index => $block ) {
			if ( 'profile-card' !== (string) ( $block['type'] ?? '' ) ) { continue; }
			$props = is_array( $block['props'] ?? null ) ? $block['props'] : array();
			$props['publicPortfolioSnapshot'] = array(
				'version'      => 1,
				'source'       => 'profile_public_site_config',
				'generated_at' => current_time( 'mysql', true ),
				'sections'     => $sections,
				'content_hash' => hash( 'sha256', wp_json_encode( $sections ) ),
			);
			$config['blocks'][ $index ]['props'] = $props;
			break;
		}
		return $config;
	}

	private function public_portfolio_section( $type, array $props ): array {
		$title = $this->public_snapshot_text( $props['title'] ?? '', 120 );
		$section = array( 'type' => (string) $type );
		if ( '' !== $title ) { $section['title'] = $title; }
		if ( 'content' === $type ) {
			$body = $this->public_snapshot_text( wp_strip_all_tags( (string) ( $props['body'] ?? '' ) ), 900 );
			if ( '' === $body ) { return array(); }
			$section['body'] = $body;
		} elseif ( 'features' === $type ) {
			$section['subtitle'] = $this->public_snapshot_text( $props['subtitle'] ?? '', 240 );
			$section['items'] = $this->public_snapshot_items( $props['items'] ?? array(), array( 'title', 'period', 'description' ), 8 );
		} elseif ( 'cta' === $type ) {
			$section['description'] = $this->public_snapshot_text( $props['description'] ?? '', 360 );
			$section['primary_cta'] = $this->public_snapshot_text( $props['primaryCta'] ?? '', 80 );
			$section['secondary_cta'] = $this->public_snapshot_text( $props['secondaryCta'] ?? '', 80 );
		} elseif ( 'testimonials' === $type ) {
			$section['items'] = $this->public_snapshot_items( $props['items'] ?? array(), array( 'quote', 'author', 'role', 'rating' ), 8 );
		} elseif ( 'stats' === $type ) {
			$section['items'] = $this->public_snapshot_items( $props['items'] ?? array(), array( 'value', 'label', 'suffix' ), 8 );
		} elseif ( 'faq' === $type ) {
			$section['items'] = $this->public_snapshot_items( $props['items'] ?? array(), array( 'question', 'answer' ), 8 );
		} elseif ( 'logocloud' === $type ) {
			$section['logos'] = array();
			foreach ( array_slice( (array) ( $props['logos'] ?? array() ), 0, 12 ) as $logo ) {
				$text = $this->public_snapshot_text( $logo, 100 );
				if ( '' !== $text ) { $section['logos'][] = $text; }
			}
		} elseif ( in_array( $type, array( 'image', 'gallery', 'video' ), true ) ) {
			$section['caption'] = $this->public_snapshot_text( $props['caption'] ?? '', 240 );
			$section['alt'] = $this->public_snapshot_text( $props['alt'] ?? '', 160 );
		}
		return count( $section ) > 1 ? $section : array();
	}

	private function public_snapshot_items( $items, array $fields, $limit ): array {
		$out = array();
		foreach ( array_slice( is_array( $items ) ? $items : array(), 0, (int) $limit ) as $item ) {
			if ( ! is_array( $item ) ) { continue; }
			$clean = array();
			foreach ( $fields as $field ) {
				$value = 'rating' === $field ? max( 0, min( 5, (int) ( $item[ $field ] ?? 0 ) ) ) : $this->public_snapshot_text( $item[ $field ] ?? '', 360 );
				if ( '' !== (string) $value && ( 'rating' !== $field || (int) $value > 0 ) ) { $clean[ $field ] = $value; }
			}
			if ( ! empty( $clean ) ) { $out[] = $clean; }
		}
		return $out;
	}

	private function public_snapshot_text( $value, $limit = 240 ): string {
		$value = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $value ) ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, (int) $limit, 'UTF-8' ) : substr( $value, 0, (int) $limit );
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
		$entries = BizCity_Personal_Profile_Entrypoint_Manager::validate_owner_accounts( $entries, get_current_user_id() );
		if ( is_wp_error( $entries ) ) {
			return $this->error_response( 'invalid_param', 'Tài khoản Zalo cá nhân chưa được xác thực.', 'Chọn lại tài khoản Zalo cá nhân đã kết nối của bạn rồi thử lại.', 'profile_entrypoint_invalid', 400 );
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
		return rest_ensure_response( array( 'success' => true, 'range' => $range, 'counts' => $report['counts'] ?? array(), 'metrics' => $report['metrics'] ?? array(), 'trend' => $report['trend'] ?? array(), 'channels' => $report['channels'] ?? array(), 'funnel' => $report['funnel'] ?? array() ) );
	}

	public function chat_transcript( WP_REST_Request $request ) {
		// [2026-08-23 Johnny Chu] PHASE-TBP-6.4 — read Profile chat content from canonical WebChat storage with owner/card scoping.
		$card_id = absint( $request['id'] );
		$card = BizCity_Personal_Profile_Card_Manager::get( $card_id, get_current_user_id() );
		if ( ! is_array( $card ) ) {
			return $this->error_response( 'not_found', 'Không tìm thấy Profile này.', 'Chọn Profile thuộc tài khoản của bạn rồi thử lại.', 'profile_card_not_found', 404 );
		}
		if ( ! class_exists( 'BizCity_WebChat_Database' ) ) {
			return rest_ensure_response( array( 'success' => true, 'items' => array(), '_degraded' => true ) );
		}
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_webchat_messages';
		if ( ! function_exists( 'bizcity_tbl_exists' ) || ! bizcity_tbl_exists( $table ) ) {
			return rest_ensure_response( array( 'success' => true, 'items' => array(), '_degraded' => true ) );
		}
		$limit = max( 1, min( 200, absint( $request->get_param( 'limit' ) ?: 100 ) ) );
		$session_prefix = 'profile_webchat_' . $card_id . '_%';
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, message_text, message_from, message_type, created_at FROM `' . $table . '` WHERE session_id LIKE %s AND platform_type = %s ORDER BY id ASC LIMIT %d', $session_prefix, 'WEBCHAT', $limit ), ARRAY_A );
		$items = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$items[] = array(
				'id'           => (int) ( $row['id'] ?? 0 ),
				'content'      => (string) ( $row['message_text'] ?? '' ),
				'from'         => sanitize_key( (string) ( $row['message_from'] ?? 'user' ) ),
				'type'         => sanitize_key( (string) ( $row['message_type'] ?? 'text' ) ),
				'created_at'   => (string) ( $row['created_at'] ?? '' ),
			);
		}
		return rest_ensure_response( array( 'success' => true, 'card_id' => $card_id, 'items' => $items ) );
	}

	public function track( WP_REST_Request $request ) {
		// [2026-08-20 Johnny Chu] PHASE-PROFILE-QR — public beacon accepts only whitelisted event types and published cards.
		$event = sanitize_key( (string) $request->get_param( 'event_type' ) );
		$meta = $request->get_param( 'meta' );
		$ok = BizCity_Personal_Profile_Analytics::record( (int) $request->get_param( 'card_id' ), $event, is_array( $meta ) ? $meta : array() );
		return rest_ensure_response( array( 'success' => (bool) $ok ) );
	}

	public function traffic_logs( WP_REST_Request $request ) {
		// [2026-08-23 Johnny Chu] PHASE-TBP-6.2 — expose daily Profile JSONL evidence only for cards owned by the current user.
		if ( ! class_exists( 'BizCity_Channel_File_Logger' ) ) {
			return rest_ensure_response( array( 'success' => false, '_degraded' => true, 'dates' => array(), 'items' => array() ) );
		}
		$owned_cards = BizCity_Personal_Profile_Card_Manager::get_by_owner( get_current_user_id() );
		$owned_ids = array();
		foreach ( $owned_cards as $owned_card ) {
			$owned_ids[ (int) ( $owned_card['id'] ?? 0 ) ] = true;
		}
		$card_id = absint( $request->get_param( 'card_id' ) );
		if ( $card_id > 0 && empty( $owned_ids[ $card_id ] ) ) {
			return $this->error_response( 'not_found', 'Không tìm thấy Profile này.', 'Chọn Profile thuộc tài khoản của bạn rồi thử lại.', 'profile_card_not_found', 404 );
		}
		$date = sanitize_text_field( (string) $request->get_param( 'date' ) );
		if ( '' !== $date && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return $this->error_response( 'invalid_param', 'Ngày nhật ký Profile không hợp lệ.', 'Chọn ngày theo định dạng YYYY-MM-DD rồi thử lại.', 'profile_log_date_invalid', 400 );
		}
		if ( '' === $date ) {
			$date = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
		}
		$raw_items = BizCity_Channel_File_Logger::read( 'profile', $date, 500 );
		$items = array();
		foreach ( $raw_items as $item ) {
			$item_card_id = (int) ( $item['ctx']['card_id'] ?? 0 );
			if ( empty( $owned_ids[ $item_card_id ] ) || ( $card_id > 0 && $card_id !== $item_card_id ) ) { continue; }
			$items[] = $item;
		}
		return rest_ensure_response( array(
			'success' => true,
			'channel' => 'profile',
			'date'    => $date,
			'dates'   => BizCity_Channel_File_Logger::list_dates( 'profile', 90 ),
			'items'   => $items,
		) );
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
			// [2026-08-21 Johnny Chu] PHASE-PROFILE-QR — Wave 6: use existing many-to-many bot assignment instead of exposing site-wide Zalo accounts.
			$bots = BizCity_Zalo_Bot_Database::instance()->get_active_bots();
			$bots = $this->filter_zalo_bots_for_user( (array) $bots, $user_id, $is_admin );
			foreach ( $bots as $bot ) {
				$oa_id    = (string) ( is_object( $bot ) ? ( $bot->oa_id ?? '' ) : ( $bot['oa_id'] ?? '' ) );
				$bot_name = (string) ( is_object( $bot ) ? ( $bot->bot_name ?? '' ) : ( $bot['bot_name'] ?? '' ) );
				if ( '' === $oa_id ) { continue; }
				$accounts[] = array( 'value' => $oa_id, 'label' => ( '' !== $bot_name ? $bot_name . ' — ' : '' ) . 'OA: ' . $oa_id, 'url' => 'https://zalo.me/' . rawurlencode( $oa_id ) );
			}
		} elseif ( 'zalo_personal' === $platform && class_exists( 'BizCity_Zalo_Mapping_Repo' ) ) {
			// [2026-08-22 Johnny Chu] PHASE-TBP-6.3 — expose only connected Personal accounts owned by the current Profile owner.
			$personal_accounts = BizCity_Zalo_Mapping_Repo::list_personal_accounts_for_owner( (int) $user_id );
			foreach ( $personal_accounts as $account ) {
				if ( 'connected' !== (string) ( $account['status'] ?? '' ) ) { continue; }
				$bridge_id = (string) ( $account['bridge_account_id'] ?? '' );
				$zalo_uid  = (string) ( $account['zalo_uid'] ?? '' );
				if ( '' === $bridge_id || '' === $zalo_uid ) { continue; }
				$label = (string) ( $account['label'] ?? $account['account_name'] ?? '' );
				$accounts[] = array(
					'value' => $bridge_id,
					'label' => '' !== $label ? $label : 'Zalo cá nhân',
					'url'   => 'https://zalo.me/' . rawurlencode( $zalo_uid ),
				);
			}
		}
		return rest_ensure_response( array( 'success' => true, 'platform' => $platform, 'items' => $accounts ) );
	}

	private function filter_zalo_bots_for_user( array $bots, $user_id, $is_admin = false, $owned_bot_ids = null ) {
		// [2026-08-21 Johnny Chu] PHASE-PROFILE-QR — Wave 6: fail closed when member assignment cannot be resolved.
		if ( $is_admin ) { return $bots; }
		if ( null === $owned_bot_ids ) {
			if ( ! class_exists( 'BizCity_Zalo_Bot_Dashboard' ) || ! method_exists( 'BizCity_Zalo_Bot_Dashboard', 'get_user_bot_ids' ) ) {
				return array();
			}
			$owned_bot_ids = BizCity_Zalo_Bot_Dashboard::get_user_bot_ids( (int) $user_id );
		}
		$owned_bot_ids = array_map( 'intval', (array) $owned_bot_ids );
		return array_values( array_filter( $bots, function ( $bot ) use ( $owned_bot_ids ) {
			$bot_id = (int) ( is_object( $bot ) ? ( $bot->id ?? 0 ) : ( $bot['id'] ?? 0 ) );
			return in_array( $bot_id, $owned_bot_ids, true );
		} ) );
	}
}

if ( class_exists( 'BizCity_Cache_Registry' ) && class_exists( 'BizCity_Cache' ) ) {
	BizCity_Cache_Registry::register(
		'bzp_profile_public_urls',
		'modules.personal.profile',
		array( 'blog_{blog_id}_project_{project_id}' => array( 'ttl' => BizCity_Cache::TTL_MEDIUM, 'desc' => 'Published public URL by Profile Page Builder project' ) )
	);
}
