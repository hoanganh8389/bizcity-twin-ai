<?php
/**
 * Identity-scoped REST surface for Twin Goal Loop G10.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\TwinBrain
 * @since 2026-08-02
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_TwinBrain_Goal_Loop_REST {

	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register_routes(): void {
		register_rest_route( BIZCITY_TWINBRAIN_REST_NS, '/goal/active', array(
			'methods'             => 'GET',
			// [2026-08-02 Johnny Chu] PHASE-TWIN-GOAL-LOOP-P0 — let the handler resolve canonical identity and fail closed for unresolved guests instead of blocking the public Twin GPT route with a generic 403.
			'permission_callback' => '__return_true',
			'args'                => array(
				'session_id' => array( 'type' => 'string', 'required' => true ),
			),
			'callback'            => array( $this, 'handle_active' ),
		) );

		register_rest_route( BIZCITY_TWINBRAIN_REST_NS, '/goal/(?P<goal_id>[\w\-]+)/close', array(
			'methods'             => 'POST',
			// [2026-08-02 Johnny Chu] PHASE-TWIN-GOAL-LOOP-P0 — resolve user/guest ownership inside the canonical identity boundary before any state transition.
			'permission_callback' => '__return_true',
			'args'                => array(
				'goal_id'        => array( 'type' => 'string', 'required' => true ),
				'session_id'     => array( 'type' => 'string', 'required' => true ),
				'status'         => array( 'type' => 'string', 'required' => true ),
				'closure_signal' => array( 'type' => 'object', 'required' => true ),
			),
			'callback'            => array( $this, 'handle_close' ),
		) );
	}

	public function perm_logged_in() {
		// [2026-08-02 Johnny Chu] PHASE-TWIN-GOAL-LOOP-P0 — retained for callers that reference this callback; authorization is now fail-closed in identity().
		return true;
	}

	public function handle_active( WP_REST_Request $request ) {
		$identity = $this->identity();
		if ( $identity['uuid'] === '' ) {
			return $this->error_response( 'auth_required', 'Không xác định được danh tính phiên chat.', 'Đăng nhập lại rồi thử lại.', 'auth_required', 401 );
		}
		$session_id = trim( (string) $request->get_param( 'session_id' ) );
		if ( $session_id === '' ) {
			return $this->error_response( 'invalid_param', 'Session chat không hợp lệ.', 'Kiểm tra session_id rồi thử lại.', 'invalid_param', 400 );
		}
		$goal = class_exists( 'BizCity_TwinBrain_Goal_Loop_Repository' )
			? BizCity_TwinBrain_Goal_Loop_Repository::latest( $identity['blog_id'], $identity['uuid'], $session_id )
			: array();
		if ( ! empty( $goal ) && ! BizCity_TwinBrain_Goal_Loop_State::is_terminal( (string) ( $goal['status'] ?? '' ) ) ) {
			return rest_ensure_response( array(
				'success'            => true,
				'goal'               => $goal,
				'has_active_goal'    => true,
				'same_session'      => true,
				'resume_available'  => false,
				'needs_user_choice' => false,
			) );
		}

		// [2026-08-02 Johnny Chu] PHASE-TWIN-GOAL-LOOP-P0 — expose an identity-scoped resume candidate without silently rebasing it onto the requested session.
		$active = class_exists( 'BizCity_TwinBrain_Goal_Loop_Repository' )
			? BizCity_TwinBrain_Goal_Loop_Repository::latest_active_by_identity( $identity['blog_id'], $identity['uuid'] )
			: array();
		if ( ! empty( $active ) && ! BizCity_TwinBrain_Goal_Loop_State::is_terminal( (string) ( $active['status'] ?? '' ) ) ) {
			return rest_ensure_response( array(
				'success'            => true,
				'goal'               => null,
				'has_active_goal'    => false,
				'same_session'      => false,
				'resume_available'  => true,
				'needs_user_choice' => true,
				'resume_candidate'  => $active,
			) );
		}

		return rest_ensure_response( array(
			'success'            => true,
			'goal'               => null,
			'has_active_goal'    => false,
			'same_session'      => false,
			'resume_available'  => false,
			'needs_user_choice' => false,
		) );
	}

	public function handle_close( WP_REST_Request $request ) {
		$identity = $this->identity();
		if ( $identity['uuid'] === '' ) {
			return $this->error_response( 'auth_required', 'Không xác định được danh tính phiên chat.', 'Đăng nhập lại rồi thử lại.', 'auth_required', 401 );
		}
		$goal_id = sanitize_text_field( (string) $request->get_param( 'goal_id' ) );
		$session_id = trim( (string) $request->get_param( 'session_id' ) );
		$status = sanitize_key( (string) $request->get_param( 'status' ) );
		$signal = $request->get_param( 'closure_signal' );
		if ( $goal_id === '' || $session_id === '' || ! is_array( $signal ) ) {
			return $this->error_response( 'invalid_param', 'Thông tin đóng goal chưa đầy đủ.', 'Gửi goal_id, session_id và closure_signal hợp lệ.', 'invalid_param', 400 );
		}
		$allowed_statuses = array( 'completed', 'cancelled', 'paused' );
		if ( ! in_array( $status, $allowed_statuses, true ) ) {
			return $this->error_response( 'invalid_param', 'Trạng thái đóng goal không hợp lệ.', 'Chọn completed, cancelled hoặc paused.', 'invalid_param', 400 );
		}
		$goal = BizCity_TwinBrain_Goal_Loop_Repository::latest( $identity['blog_id'], $identity['uuid'], $session_id );
		if ( empty( $goal ) && method_exists( 'BizCity_TwinBrain_Goal_Loop_Repository', 'latest_active_by_identity' ) ) {
			// [2026-08-02 Johnny Chu] PHASE-TWIN-GOAL-LOOP-P1 — close/pause may follow an explicit cross-session resume choice before the first progress event lands in the new session.
			$goal = BizCity_TwinBrain_Goal_Loop_Repository::latest_active_by_identity( $identity['blog_id'], $identity['uuid'] );
			if ( ! empty( $goal ) ) {
				$goal['session_id'] = $session_id;
				$goal['identity_uuid'] = $identity['uuid'];
			}
		}
		if ( empty( $goal ) || (string) ( $goal['goal_id'] ?? '' ) !== $goal_id ) {
			return $this->error_response( 'not_found', 'Không tìm thấy goal trong phiên này.', 'Mở đúng phiên chat rồi thử lại.', 'not_found', 404 );
		}
		if ( BizCity_TwinBrain_Goal_Loop_State::is_terminal( (string) ( $goal['status'] ?? '' ) ) ) {
			return $this->error_response( 'invalid_param', 'Goal này đã kết thúc.', 'Mở một goal mới để tiếp tục công việc.', 'invalid_param', 409 );
		}
		$normalized_signal = array(
			'type'       => sanitize_key( (string) ( $signal['type'] ?? '' ) ),
			'evidence'   => sanitize_text_field( (string) ( $signal['evidence'] ?? '' ) ),
			'created_at' => sanitize_text_field( (string) ( $signal['created_at'] ?? '' ) ),
		);
		$next = $goal;
		$next['status'] = $status;
		$next['closure_signal'] = $normalized_signal;
		if ( ! BizCity_TwinBrain_Goal_Loop_State::can_transition( (string) $goal['status'], $status, $next ) ) {
			return $this->error_response( 'invalid_param', 'Goal chưa đủ điều kiện để chuyển trạng thái.', 'Hoàn tất DoD hoặc chọn đúng tín hiệu đóng goal.', 'invalid_param', 409 );
		}
		$event_uuid = $status === 'paused'
			? BizCity_TwinBrain_Goal_Loop_Repository::progress( $next, $this->write_opts( $identity, $session_id ) )
			: BizCity_TwinBrain_Goal_Loop_Repository::close( $next, $this->write_opts( $identity, $session_id ) );
		if ( $event_uuid === '' ) {
			return $this->error_response( 'twin_agent_exception', 'Không thể cập nhật trạng thái goal lúc này.', 'Thử lại sau và kiểm tra Diagnostics nếu lỗi tiếp diễn.', 'twin_agent_exception', 500 );
		}
		// [2026-08-02 Johnny Chu] PHASE-TWIN-GOAL-LOOP-P0 — keep the existing TwinWeb thread pointer in sync after an audited pause/close event; event stream remains canonical.
		if ( class_exists( 'BizCity_TwinWeb_Thread_Registry' ) && preg_match( '/^\d+$/', $session_id ) ) {
			BizCity_TwinWeb_Thread_Registry::sync_goal_link( (int) $session_id, $next, $event_uuid );
		}
		return rest_ensure_response( array( 'success' => true, 'goal' => BizCity_TwinBrain_Goal_Loop_State::normalize( $next ), 'event_uuid' => $event_uuid ) );
	}

	private function identity(): array {
		// [2026-08-02 Johnny Chu] PHASE-TWIN-GOAL-LOOP-P0 — reuse TwinWeb's canonical identity when this REST surface is loaded by Twin GPT; never treat guest_sid as an identity_uuid.
		if ( class_exists( 'BizCity_TwinWeb_Identity' ) ) {
			$web_identity = BizCity_TwinWeb_Identity::current();
			$user_id = (int) ( $web_identity['user_id'] ?? 0 );
			if ( class_exists( 'BizCity_Memory_Identity_Scope' ) ) {
				$scope_context = $user_id > 0
					? array( 'user_id' => $user_id, 'wp_user_id' => $user_id, 'platform' => 'TWIN_GPT' )
					: array(
						'platform'           => 'WEBCHAT',
						'account_id'         => (string) get_current_blog_id(),
						'external_user_id'   => (string) ( $web_identity['guest_sid'] ?? '' ),
						'identity_guest_bind' => true,
						'identity_is_stable'  => true,
					);
				$scope = BizCity_Memory_Identity_Scope::resolve( $scope_context );
				$uuid = strtolower( trim( (string) ( $scope['identity_uuid'] ?? '' ) ) );
				return array( 'uuid' => $uuid, 'blog_id' => (int) get_current_blog_id(), 'user_id' => $user_id, 'is_guest' => $user_id <= 0, 'identity_verified' => ! empty( $scope['identity_verified'] ) );
			}
			return array( 'uuid' => '', 'blog_id' => (int) get_current_blog_id(), 'user_id' => 0, 'is_guest' => true, 'identity_verified' => false );
		}

		$user_id = (int) get_current_user_id();
		$uuid = '';
		if ( class_exists( 'BizCity_Memory_Identity_Scope' ) ) {
			$scope = BizCity_Memory_Identity_Scope::resolve( array( 'user_id' => $user_id, 'wp_user_id' => $user_id ) );
			$uuid = strtolower( trim( (string) ( $scope['identity_uuid'] ?? '' ) ) );
		}
		if ( $uuid === '' && class_exists( 'BizCity_Identity_Hub' ) ) {
			$identity = BizCity_Identity_Hub::resolve_from_opts( array( 'user_id' => $user_id, 'wp_user_id' => $user_id ), (int) get_current_blog_id() );
			if ( ! is_wp_error( $identity ) && is_array( $identity ) ) {
				$uuid = strtolower( trim( (string) ( $identity['identity_uuid'] ?? '' ) ) );
			}
		}
		return array( 'uuid' => $uuid, 'blog_id' => (int) get_current_blog_id(), 'user_id' => $user_id, 'is_guest' => false, 'identity_verified' => $uuid !== '' );
	}

	private function write_opts( array $identity, string $session_id ): array {
		return array(
			'identity_uuid' => $identity['uuid'],
			'blog_id'       => $identity['blog_id'],
			'user_id'       => $identity['user_id'],
			'session_id'    => $session_id,
			'event_source'  => 'twinbrain', // [2026-08-02 Johnny Chu] HOTFIX — REST Goal transitions must use an allowed Event Bus source.
		);
	}

	private function error_response( string $code, string $message, string $hint, string $help_code, int $status ) {
		$payload = class_exists( 'BizCity_Error_Payload' )
			? BizCity_Error_Payload::make( $code, $message, $hint, $help_code )
			: array( 'success' => false, '_degraded' => true, 'code' => $code, 'message' => $message, 'hint' => $hint, 'help_code' => $help_code );
		return new WP_REST_Response( $payload, $status );
	}
}