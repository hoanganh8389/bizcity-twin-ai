<?php
/**
 * TwinWeb — REST Controller
 *
 * Namespace: bizcity-twinweb/v1
 *
 * Wave 1 routes:
 *   POST /chat/stream          — SSE chat proxy to TwinBrain
 *   GET  /me                   — identity + entitlement
 *
 * Wave 2 routes (threads):
 *   GET    /threads             — list threads for current user/guest
 *   POST   /threads             — create thread
 *   GET    /threads/{id}        — get single thread
 *   PATCH  /threads/{id}        — update thread (title, pinned, archived)
 *   DELETE /threads/{id}        — delete thread
 *   POST   /threads/{id}/claim  — claim guest thread after login
 *
 * Wave 4 routes (@ and / popover):
 *   GET  /modes                 — list @modes (personas/agents) for autocomplete
 *   GET  /skills                — list /skills (automation slugs) for autocomplete
 *
 * Fail-OPEN policy (R-GW-8.3):
 *   Upstream errors → 200 + { success: false, _degraded: true, message, code, hint }
 *
 * PHP 7.4 compatible.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Modules\TwinWeb
 * @since 2026-06-17 (PHASE-TWINWEB Wave 1)
 */
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_TwinWeb_REST' ) ) { return; }

class BizCity_TwinWeb_REST {

	const NS = 'bizcity-twinweb/v1';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register_routes() {
		$ns = self::NS;

		// [2026-06-20 Johnny Chu] PHASE-TWINWEB — Auth routes (AJAX login/register without page redirect)
		register_rest_route( $ns, '/auth/login', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_login' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'username' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				'password' => array( 'type' => 'string', 'required' => true ),
			),
		) );

		register_rest_route( $ns, '/auth/register', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_register' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'username' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_user' ),
				'email'    => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_email' ),
			),
		) );

		// ── Wave 1 ────────────────────────────────────────────────────────────
		register_rest_route( $ns, '/chat/stream', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_chat_stream' ),
			'permission_callback' => '__return_true', // auth checked inside handler
			'args'                => array(
				'thread_id' => array( 'type' => 'string',  'required' => false, 'default' => '' ),
				'message'   => array( 'type' => 'string',  'required' => true,  'sanitize_callback' => 'wp_kses_post' ),
				'history'   => array( 'type' => 'array',   'required' => false, 'default' => array() ),
				'use_kg'    => array( 'type' => 'boolean', 'required' => false, 'default' => true ),
				// [2026-06-18 Johnny Chu] PHASE-TWINWEB — @mode and /skill params for autocomplete popover
				'mode'      => array( 'type' => 'string',  'required' => false, 'default' => '',
					'sanitize_callback' => 'sanitize_key' ),
				'skill'     => array( 'type' => 'string',  'required' => false, 'default' => '',
					'sanitize_callback' => 'sanitize_key' ),
			),
		) );

		// [2026-06-18 Johnny Chu] PHASE-TWINWEB Wave 4 — @modes autocomplete
		register_rest_route( $ns, '/modes', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'list_modes' ),
			'permission_callback' => '__return_true',
		) );

		// [2026-06-18 Johnny Chu] PHASE-TWINWEB Wave 4 — /skills autocomplete (automation slugs)
		register_rest_route( $ns, '/skills', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'list_skills' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( $ns, '/me', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'handle_me' ),
			'permission_callback' => '__return_true',
		) );

		// ── Wave 2 ────────────────────────────────────────────────────────────
		register_rest_route( $ns, '/threads', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_threads' ),
				'permission_callback' => '__return_true',
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_thread' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'title'    => array( 'type' => 'string', 'required' => false, 'default' => '' ),
					'app_type' => array( 'type' => 'string', 'required' => false, 'default' => 'chat' ),
				),
			),
		) );

		register_rest_route( $ns, '/threads/(?P<id>\d+)', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_thread' ),
				'permission_callback' => '__return_true',
			),
			array(
				'methods'             => 'PATCH',
				'callback'            => array( $this, 'update_thread' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'title'    => array( 'type' => 'string',  'required' => false ),
					'pinned'   => array( 'type' => 'boolean', 'required' => false ),
					'archived' => array( 'type' => 'boolean', 'required' => false ),
				),
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'delete_thread' ),
				'permission_callback' => '__return_true',
			),
		) );

		register_rest_route( $ns, '/threads/(?P<id>\d+)/claim', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'claim_thread' ),
			'permission_callback' => '__return_true',
		) );
	}

	/* ═════════════════════════════════════════════════════════════════════ */
	/* AUTH — LOGIN / REGISTER (AJAX, no page redirect)                      */
	/* [2026-06-20 Johnny Chu] PHASE-TWINWEB                                 */
	/* ═════════════════════════════════════════════════════════════════════ */

	/**
	 * POST /auth/login
	 * Authenticate with email/username + password, set WP auth cookie.
	 * FE calls this then reloads twinwebConfig nonce via GET /me.
	 */
	public function handle_login( WP_REST_Request $request ) {
		$username = (string) $request->get_param( 'username' );
		$password = (string) $request->get_param( 'password' );

		if ( $username === '' || $password === '' ) {
			return rest_ensure_response( array(
				'success' => false,
				'code'    => 'invalid_param',
				'message' => 'Tên đăng nhập và mật khẩu không được trống.',
				'hint'    => 'Nhập đủ email và mật khẩu.',
			) );
		}

		// wp_signon sets auth cookie (works for email or username)
		$user = wp_signon( array(
			'user_login'    => $username,
			'user_password' => $password,
			'remember'      => true,
		), is_ssl() );

		if ( is_wp_error( $user ) ) {
			return rest_ensure_response( array(
				'success' => false,
				'code'    => 'auth_required',
				'message' => 'Email hoặc mật khẩu không đúng.',
				'hint'    => 'Kiểm tra lại thông tin đăng nhập.',
			) );
		}

		// Issue a fresh nonce for the new session so FE can re-init X-WP-Nonce
		$new_nonce = wp_create_nonce( 'wp_rest' );

		return rest_ensure_response( array(
			'success'      => true,
			'user_id'      => (int) $user->ID,
			'display_name' => $user->display_name,
			'avatar_url'   => get_avatar_url( $user->ID, array( 'size' => 40 ) ),
			'new_nonce'    => $new_nonce,
		) );
	}

	/**
	 * POST /auth/register
	 * Create new account + auto-login.
	 * Sends WP welcome email. Returns new_nonce on success so FE can resume.
	 */
	public function handle_register( WP_REST_Request $request ) {
		// Registration must be allowed on this site
		if ( ! get_option( 'users_can_register' ) ) {
			return rest_ensure_response( array(
				'success' => false,
				'code'    => 'permission_denied',
				'message' => 'Đăng ký tài khoản chưa được bật.',
				'hint'    => 'Liên hệ admin để mở tính năng này.',
			) );
		}

		$username = (string) $request->get_param( 'username' );
		$email    = (string) $request->get_param( 'email' );
		// [2026-06-21 Johnny Chu] PHASE-TWINWEB — Issue 3: accept password from FE (no longer auto-generate)
		$password = (string) $request->get_param( 'password' );

		if ( $username === '' || $email === '' ) {
			return rest_ensure_response( array(
				'success' => false,
				'code'    => 'invalid_param',
				'message' => 'Tên đăng nhập và email không được trống.',
				'hint'    => 'Điền đủ thông tin.',
			) );
		}

		if ( ! is_email( $email ) ) {
			return rest_ensure_response( array(
				'success' => false,
				'code'    => 'invalid_param',
				'message' => 'Email không hợp lệ.',
				'hint'    => 'Nhập đúng định dạng email.',
			) );
		}

		if ( username_exists( $username ) ) {
			return rest_ensure_response( array(
				'success' => false,
				'code'    => 'invalid_param',
				'message' => 'Tên đăng nhập đã tồn tại.',
				'hint'    => 'Chọn tên đăng nhập khác.',
			) );
		}

		if ( email_exists( $email ) ) {
			return rest_ensure_response( array(
				'success' => false,
				'code'    => 'invalid_param',
				'message' => 'Email đã được đăng ký.',
				'hint'    => 'Dùng email khác hoặc đăng nhập vào tài khoản đó.',
			) );
		}

		// Use FE-provided password if given, otherwise generate one
		// [2026-06-21 Johnny Chu] PHASE-TWINWEB — Issue 3 fix
		if ( $password === '' || strlen( $password ) < 6 ) {
			$password = wp_generate_password( 12, false );
			$password_emailed = true;
		} else {
			$password_emailed = false;
		}
		$user_id  = wp_create_user( $username, $password, $email );

		if ( is_wp_error( $user_id ) ) {
			return rest_ensure_response( array(
				'success' => false,
				'code'    => 'invalid_param',
				'message' => 'Không thể tạo tài khoản.',
				'hint'    => $user_id->get_error_message(),
			) );
		}

		// Set default role
		$user_obj = get_user_by( 'id', $user_id );
		if ( $user_obj ) {
			$user_obj->set_role( get_option( 'default_role', 'subscriber' ) );
		}

		// Send WP new-user notification (always send welcome email)
		wp_new_user_notification( $user_id, null, 'user' );

		// Auto-login: set auth cookie for the new user
		wp_set_auth_cookie( $user_id, true, is_ssl() );
		wp_set_current_user( $user_id );

		$new_nonce = wp_create_nonce( 'wp_rest' );

		return rest_ensure_response( array(
			'success'          => true,
			'user_id'          => (int) $user_id,
			'display_name'     => $user_obj ? $user_obj->display_name : $username,
			'avatar_url'       => get_avatar_url( $user_id, array( 'size' => 40 ) ),
			'new_nonce'        => $new_nonce,
			'password_emailed' => $password_emailed,
		) );
	}

	/* ═════════════════════════════════════════════════════════════════════ */
	/* WAVE 1 — CHAT STREAM                                                  */
	/* ═════════════════════════════════════════════════════════════════════ */

	/**
	 * POST /chat/stream — SSE proxy to TwinBrain.
	 *
	 * Streams directly, exits.
	 */
	public function handle_chat_stream( WP_REST_Request $request ) {
		// [2026-06-17 Johnny Chu] PHASE-TWINWEB — identity-first (R-TWEB-1)
		$identity = BizCity_TwinWeb_Identity::current();
		$message  = (string) $request->get_param( 'message' );

		if ( $message === '' ) {
			return new WP_Error(
				'invalid_param',
				'Tin nhắn không được trống.',
				array( 'status' => 400 )
			);
		}

		// [2026-06-17 Johnny Chu] PHASE-TWINWEB — guest quota check (basic)
		if ( $identity['is_guest'] ) {
			$quota_key = 'tw_guest_' . $identity['guest_sid'] . '_quota';
			$used      = (int) get_transient( $quota_key );
			$limit     = (int) apply_filters( 'bizcity_twinweb_guest_quota', 10 );
			if ( $used >= $limit ) {
				wp_send_json( array(
					'success'   => false,
					'code'      => 'quota_exceeded',
					'message'   => 'Bạn đã hết lượt chat miễn phí hôm nay.',
					'hint'      => 'Đăng nhập hoặc tạo tài khoản để tiếp tục.',
					'help_code' => 'guest_quota_exceeded',
				) );
				exit;
			}
		}

		// [2026-06-20 Johnny Chu] PHASE-TWINWEB — member tier quota gating
		// Limits per tier per day (filtered so membership plugin can override).
		// Tiers: free=30, plus=100, pro=unlimited (-1). Admin always bypass.
		if ( ! $identity['is_guest'] ) {
			$user_id_check = $identity['user_id'];
			if ( ! current_user_can( 'manage_options' ) ) {
				$tier         = (string) apply_filters( 'bizcity_twinweb_user_tier', 'free', $user_id_check );
				$tier_limits  = (array)  apply_filters( 'bizcity_twinweb_tier_limits', array(
					'free' => 30,
					'plus' => 100,
					'pro'  => -1,
				) );
				$day_limit    = isset( $tier_limits[ $tier ] ) ? (int) $tier_limits[ $tier ] : 30;
				if ( $day_limit >= 0 ) {
					$member_quota_key = 'tw_user_' . $user_id_check . '_quota_' . gmdate( 'Y-m-d' );
					$member_used      = (int) get_transient( $member_quota_key );
					if ( $member_used >= $day_limit ) {
						wp_send_json( array(
							'success'   => false,
							'code'      => 'quota_exceeded',
							'message'   => 'Bạn đã dùng hết ' . $day_limit . ' lượt chat hôm nay (gói ' . strtoupper( $tier ) . ').',
							'hint'      => 'Nâng cấp tài khoản để có thêm lượt hoặc chờ ngày mai.',
							'help_code' => 'member_quota_exceeded',
							'tier'      => $tier,
						) );
						exit;
					}
				}
			}
		}

		// [2026-06-17 Johnny Chu] PHASE-TWINWEB — ensure TwinBrain is available
		if ( ! class_exists( 'BizCity_TwinBrain_Runtime' ) ) {
			return new WP_Error(
				'module_not_loaded',
				'TwinBrain chưa được tải.',
				array( 'status' => 503 )
			);
		}

		// Open SSE connection
		self::open_sse();

		$thread_id = (string) $request->get_param( 'thread_id' );
		$history   = (array)  $request->get_param( 'history' );
		$use_kg    = (bool)   $request->get_param( 'use_kg' );
		$user_id   = $identity['user_id'];
		// [2026-06-18 Johnny Chu] PHASE-TWINWEB — pass mode/skill to TwinBrain opts
		$mode      = (string) $request->get_param( 'mode' );
		$skill     = (string) $request->get_param( 'skill' );

		// Auto-create thread row if none provided (Wave 2 — thread_id will be used)
		if ( $thread_id === '' ) {
			$thread_id = wp_generate_uuid4();
		}

		// [2026-06-19 Johnny Chu] PHASE-TWB-WORKFLOW W1 — route /skill → Workflow Pipeline.
		// When user selects a /skill in the composer, bypass normal brain runtime
		// and run the workflow-driven pipeline instead. Fail-OPEN: if pipeline
		// class missing → fall through to brain runtime with _degraded response.
		if ( $skill !== '' && class_exists( 'BizCity_TwinBrain_Workflow_Pipeline' ) ) {
			$pipeline = BizCity_TwinBrain_Workflow_Pipeline::instance();
			// Inject direct SSE emitter so pipeline streams events in this request.
			$pipeline->set_sse_emitter( array( __CLASS__, 'sse_emit_public' ) );
			$trace_id = 'tw_' . wp_generate_uuid4();
			// Emit 'started' SSE frame so FE recognizes stream has begun.
			self::sse_emit_public( 'started', array(
				'trace_id'   => $trace_id,
				'session_id' => $thread_id,
			) );
			try {
				$pipeline->run( $trace_id, $message, array(
					'skill'      => $skill,
					'user_id'    => $user_id,
					'guest_sid'  => (string) ( $identity['guest_sid'] ?? '' ),
					'session_id' => $thread_id,
					'surface'    => 'twinweb',
					'history'    => $history,
					'on_token'   => array( __CLASS__, 'sse_token' ),
				) );
			} catch ( \Throwable $e ) {
				error_log( '[TwinBrain][twinweb-rest] workflow pipeline threw: ' . $e->getMessage() );
				self::sse_error( array(
					'code'      => 'twin_agent_exception',
					'message'   => 'Skill pipeline gặp lỗi: ' . esc_html( $e->getMessage() ),
					'hint'      => 'Thử lại sau giây lát.',
					'help_code' => 'twin_agent_exception',
				) );
			}
			self::close_sse();
			exit;
		}

		// [2026-06-21 Johnny Chu] PHASE-TWINWEB — Fix: start_turn() does NOT use on_token/on_complete
		// callbacks. Runtime uses BizCity_Twin_Event_Bus internally. LLM generation happens in
		// complete_turn_stream($sse). Must mirror class-twinbrain-rest.php::handle_turn_stream().
		if ( ! class_exists( 'BizCity_Twin_SSE_Writer' ) ) {
			self::sse_error( array(
				'code'      => 'module_not_loaded',
				'message'   => 'SSE Writer chưa được tải.',
				'hint'      => 'Kiểm tra plugin core twin-core.',
				'help_code' => 'module_not_loaded',
			) );
			self::close_sse();
			exit;
		}

		// Map twinweb mode param → TwinBrain web_mode (used by complete_turn_stream routing).
		// 'astro' → stream_astro_mode; 'chat' in twinweb = full MPR pipeline ('off').
		$web_mode_map = array(
			'astro'   => 'astro',
			'quick'   => 'quick',
			'deep'    => 'deep',
			'social'  => 'social',
			'company' => 'company',
		);
		$web_mode = isset( $web_mode_map[ $mode ] ) ? $web_mode_map[ $mode ] : 'off';

		// headers already sent by open_sse() above; send_headers=false avoids duplicate headers.
		// Content-Encoding: none prevents LiteSpeed/Apache gzip from breaking the stream.
		if ( ! headers_sent() ) {
			header( 'Content-Encoding: none' );
		}
		$sse = new BizCity_Twin_SSE_Writer( false );

		try {
			$brain = BizCity_TwinBrain_Runtime::instance();

			$start    = $brain->start_turn( $message, array(
				'user_id'    => $user_id,
				'session_id' => $thread_id,
				'web_mode'   => $web_mode,
				'mode'       => 'brain',
			) );
			$trace_id = (string) ( $start['trace_id'] ?? '' );

			$done = $brain->complete_turn_stream(
				$trace_id,
				$message,
				(array) ( $start['candidates']      ?? array() ),
				(array) ( $start['tool_candidates'] ?? array() ),
				$sse,
				array(
					'guru_id'        => (int)    ( $start['guru_id']       ?? 0 ),
					'tool_force'     => (string) ( $start['tool_force']    ?? '' ),
					'web_mode'       => $web_mode,
					'mode'           => 'brain',
					'keyword_tokens' => (array)  ( $start['keyword_tokens'] ?? array() ),
					'memory_block'   => (string) ( $start['memory_block']  ?? '' ),
					'user_id'        => $user_id,
					'session_id'     => $thread_id,
				)
			);

			// Increment quota after successful LLM generation
			if ( $identity['is_guest'] ) {
				$quota_key = 'tw_guest_' . $identity['guest_sid'] . '_quota';
				$used      = (int) get_transient( $quota_key );
				set_transient( $quota_key, $used + 1, DAY_IN_SECONDS );
			} elseif ( ! current_user_can( 'manage_options' ) ) {
				$member_quota_key = 'tw_user_' . $identity['user_id'] . '_quota_' . gmdate( 'Y-m-d' );
				$member_used      = (int) get_transient( $member_quota_key );
				set_transient( $member_quota_key, $member_used + 1, DAY_IN_SECONDS );
			}

			$sse->close( array_merge( array( 'trace_id' => $trace_id ), (array) $done ) );
		} catch ( Exception $e ) {
			error_log( '[TwinBrain][twinweb-rest] complete_turn_stream threw: ' . $e->getMessage() );
			$sse->error( 'Có lỗi xảy ra khi xử lý yêu cầu.', 'twin_agent_exception' );
		}

		exit;
	}

	/* ── SSE helpers ────────────────────────────────────────────────────── */

	public static function open_sse() {
		while ( ob_get_level() ) { ob_end_clean(); }
		header( 'Content-Type: text/event-stream; charset=UTF-8' );
		header( 'Cache-Control: no-cache' );
		header( 'X-Accel-Buffering: no' );
		header( 'Connection: keep-alive' );
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			// Do NOT call on SSE — would close socket early.
		}
		set_time_limit( 120 );
		@ignore_user_abort( true ); // phpcs:ignore
	}

	public static function close_sse() {
		echo "\n";
		if ( ob_get_level() ) { ob_flush(); }
		flush();
	}

	private static function sse_emit( $event, $data ) {
		echo "event: {$event}\n";
		echo 'data: ' . wp_json_encode( $data ) . "\n\n";
		if ( ob_get_level() ) { ob_flush(); }
		flush();
	}

	/**
	 * Public alias for sse_emit — required by BizCity_TwinBrain_Workflow_Pipeline::set_sse_emitter().
	 * [2026-06-19 Johnny Chu] PHASE-TWB-WORKFLOW W1
	 */
	public static function sse_emit_public( $event, $data ) {
		self::sse_emit( $event, $data );
	}

	public static function sse_token( $data ) {
		self::sse_emit( 'token', is_array( $data ) ? $data : array( 'text' => (string) $data ) );
	}

	public static function sse_twin_event( $data ) {
		self::sse_emit( 'twin_event', $data );
	}

	public static function sse_kg_citations( $data ) {
		self::sse_emit( 'kg_citations', $data );
	}

	public static function sse_sources( $data ) {
		self::sse_emit( 'sources', $data );
	}

	public static function sse_status( $data ) {
		self::sse_emit( 'status', is_array( $data ) ? $data : array( 'step' => (string) $data ) );
	}

	public static function sse_complete( $data ) {
		self::sse_emit( 'complete', $data );
	}

	public static function sse_error( $data ) {
		self::sse_emit( 'error', $data );
	}

	/* ═════════════════════════════════════════════════════════════════════ */
	/* WAVE 1 — ME                                                           */
	/* ═════════════════════════════════════════════════════════════════════ */

	/**
	 * GET /me — identity + entitlement (fail-OPEN).
	 */
	public function handle_me( WP_REST_Request $request ) {
		$identity = BizCity_TwinWeb_Identity::current();

		// Entitlement from gateway (fail-OPEN)
		$entitlement = array( 'tier' => 'free', 'bypass' => true, '_degraded' => true );
		if ( ! $identity['is_guest'] && class_exists( 'BizCity_LLM_Client' ) ) {
			$client = BizCity_LLM_Client::instance();
			if ( $client->is_ready() ) {
		$raw = $client->get_entitlement( (int) $identity['user_id'] );
				if ( is_array( $raw ) && empty( $raw['_degraded'] ) ) {
					$entitlement = $raw;
				}
			}
		}

		$guest_quota_remaining = null;
		if ( $identity['is_guest'] ) {
			$quota_key             = 'tw_guest_' . $identity['guest_sid'] . '_quota';
			$used                  = (int) get_transient( $quota_key );
			$limit                 = (int) apply_filters( 'bizcity_twinweb_guest_quota', 10 );
			$guest_quota_remaining = max( 0, $limit - $used );
		}

		// [2026-06-20 Johnny Chu] PHASE-TWINWEB — expose member quota info
		$member_quota = null;
		if ( ! $identity['is_guest'] ) {
			$uid         = $identity['user_id'];
			$tier        = (string) apply_filters( 'bizcity_twinweb_user_tier', $entitlement['tier'] ?? 'free', $uid );
			$tier_limits = (array)  apply_filters( 'bizcity_twinweb_tier_limits', array( 'free' => 30, 'plus' => 100, 'pro' => -1 ) );
			$day_limit   = isset( $tier_limits[ $tier ] ) ? (int) $tier_limits[ $tier ] : 30;
			$day_used    = (int) get_transient( 'tw_user_' . $uid . '_quota_' . gmdate( 'Y-m-d' ) );
			$member_quota = array(
				'tier'      => $tier,
				'day_limit' => $day_limit,
				'day_used'  => $day_used,
				'remaining' => $day_limit < 0 ? null : max( 0, $day_limit - $day_used ),
			);
		}

		return rest_ensure_response( array(
			'user_id'               => $identity['user_id'],
			'is_guest'              => $identity['is_guest'],
			'display_name'          => $identity['display'],
			'avatar_url'            => $identity['user_id'] > 0 ? get_avatar_url( $identity['user_id'], array( 'size' => 40 ) ) : '',
			'entitlement'           => $entitlement,
			'guest_quota_remaining' => $guest_quota_remaining,
			'member_quota'          => $member_quota,
			// [2026-06-18 Johnny Chu] PHASE-TWINWEB — public page URL for FE redirects
			'twinweb_page_url'      => class_exists( 'BizCity_TwinWeb_Page' ) ? BizCity_TwinWeb_Page::get_page_url() : null,
			'apps'                  => array(
				array( 'id' => 'chat',    'label' => 'Chat',        'icon' => 'chat',    'enabled' => true ),
				array( 'id' => 'astro',   'label' => 'My Astro',    'icon' => 'moon',    'enabled' => class_exists( 'BizCoach_Pro_Self_Service_Page' ) ),
				array( 'id' => 'creator', 'label' => 'Nội Dung',    'icon' => 'pencil',  'enabled' => class_exists( 'BZCC_Frontend' ) ),
				array( 'id' => 'image',   'label' => 'Image AI',    'icon' => 'image',   'enabled' => defined( 'BZTIMG_VERSION' ) ),
			),
		) );
	}

	/* ═════════════════════════════════════════════════════════════════════ */
	/* WAVE 2 — THREADS                                                       */
	/* ═════════════════════════════════════════════════════════════════════ */

	public function list_threads( WP_REST_Request $request ) {
		global $wpdb;
		$identity = BizCity_TwinWeb_Identity::current();
		$table    = $wpdb->prefix . 'bizcity_twinweb_threads';

		if ( ! self::table_exists( $table ) ) {
			return rest_ensure_response( array( 'threads' => array() ) );
		}

		if ( $identity['is_guest'] ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM {$table} WHERE guest_sid = %s AND archived = 0 ORDER BY last_at DESC LIMIT 50",
				$identity['guest_sid']
			) );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d AND archived = 0 ORDER BY last_at DESC LIMIT 100",
				$identity['user_id']
			) );
		}

		return rest_ensure_response( array( 'threads' => array_map( array( $this, 'format_thread' ), $rows ?: array() ) ) );
	}

	public function create_thread( WP_REST_Request $request ) {
		global $wpdb;
		$identity = BizCity_TwinWeb_Identity::current();
		$table    = $wpdb->prefix . 'bizcity_twinweb_threads';

		$data = array(
			'user_id'   => $identity['user_id'],
			'guest_sid' => $identity['guest_sid'],
			'app_type'  => sanitize_key( $request->get_param( 'app_type' ) ?: 'chat' ),
			'title'     => sanitize_text_field( $request->get_param( 'title' ) ?: '' ),
			'last_at'   => current_time( 'mysql' ),
			'created_at'=> current_time( 'mysql' ),
		);

		$wpdb->insert( $table, $data );
		$id = (int) $wpdb->insert_id;
		if ( ! $id ) {
			return new WP_Error( 'db_error', 'Không thể tạo thread.', array( 'status' => 500 ) );
		}
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
		return rest_ensure_response( $this->format_thread( $row ) );
	}

	public function get_thread( WP_REST_Request $request ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_twinweb_threads';
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $request['id'] ) );
		if ( ! $row || ! $this->owns_thread( $row ) ) {
			return new WP_Error( 'not_found', 'Thread không tồn tại.', array( 'status' => 404 ) );
		}
		return rest_ensure_response( $this->format_thread( $row ) );
	}

	public function update_thread( WP_REST_Request $request ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_twinweb_threads';
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $request['id'] ) );
		if ( ! $row || ! $this->owns_thread( $row ) ) {
			return new WP_Error( 'not_found', 'Thread không tồn tại.', array( 'status' => 404 ) );
		}
		$update = array();
		if ( null !== $request->get_param( 'title' ) ) {
			$update['title'] = sanitize_text_field( $request->get_param( 'title' ) );
		}
		if ( null !== $request->get_param( 'pinned' ) ) {
			$update['pinned'] = (int) $request->get_param( 'pinned' );
		}
		if ( null !== $request->get_param( 'archived' ) ) {
			$update['archived'] = (int) $request->get_param( 'archived' );
		}
		if ( $update ) {
			$wpdb->update( $table, $update, array( 'id' => (int) $request['id'] ) );
		}
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $request['id'] ) );
		return rest_ensure_response( $this->format_thread( $row ) );
	}

	public function delete_thread( WP_REST_Request $request ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_twinweb_threads';
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $request['id'] ) );
		if ( ! $row || ! $this->owns_thread( $row ) ) {
			return new WP_Error( 'not_found', 'Thread không tồn tại.', array( 'status' => 404 ) );
		}
		$wpdb->delete( $table, array( 'id' => (int) $request['id'] ) );
		return rest_ensure_response( array( 'deleted' => true ) );
	}

	public function claim_thread( WP_REST_Request $request ) {
		global $wpdb;
		$identity = BizCity_TwinWeb_Identity::current();
		if ( $identity['is_guest'] ) {
			return new WP_Error( 'auth_required', 'Đăng nhập để claim thread.', array( 'status' => 401 ) );
		}
		$table = $wpdb->prefix . 'bizcity_twinweb_threads';
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $request['id'] ) );
		if ( ! $row ) {
			return new WP_Error( 'not_found', 'Thread không tồn tại.', array( 'status' => 404 ) );
		}
		// Only claim guest threads that are unclaimed
		if ( (int) $row->user_id !== 0 ) {
			return new WP_Error( 'already_claimed', 'Thread đã thuộc về user khác.', array( 'status' => 409 ) );
		}
		$wpdb->update( $table, array( 'user_id' => $identity['user_id'], 'guest_sid' => '' ), array( 'id' => (int) $request['id'] ) );
		return rest_ensure_response( array( 'claimed' => true ) );
	}

	/* ── helpers ───────────────────────────────────────────────────────── */

	private function owns_thread( $row ) {
		$identity = BizCity_TwinWeb_Identity::current();
		if ( $identity['is_guest'] ) {
			return ( $row->guest_sid === $identity['guest_sid'] );
		}
		return ( (int) $row->user_id === $identity['user_id'] );
	}

	private function format_thread( $row ) {
		if ( ! $row ) { return null; }
		return array(
			'id'         => (int) $row->id,
			'app_type'   => $row->app_type,
			'title'      => $row->title,
			'pinned'     => (bool) $row->pinned,
			'archived'   => (bool) $row->archived,
			'last_at'    => $row->last_at,
			'created_at' => $row->created_at,
			// [2026-06-22 Johnny Chu] PHASE-TWINWEB — project grouping
			'project_id' => $row->project_id ?? '',
		);
	}

	private static function table_exists( $table ) {
		// [2026-06-21 Johnny Chu] R-SHOW-TABLES — dùng information_schema thay vì SHOW TABLES (dual cache)
		static $s = array();
		if ( isset( $s[ $table ] ) ) {
			return $s[ $table ];
		}
		global $wpdb;
		$ck      = 'bz_tbl_' . (int) get_current_blog_id() . '_' . crc32( $table );
		$present = wp_cache_get( $ck, 'bizcity_tbl' );
		if ( false === $present ) {
			$present = (int) (bool) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
					$table
				)
			);
			wp_cache_set( $ck, $present, 'bizcity_tbl', HOUR_IN_SECONDS );
		}
		$s[ $table ] = (bool) $present;
		return $s[ $table ];
	}

	/* ═════════════════════════════════════════════════════════════════════ */
	/* WAVE 4 — @MODES + /SKILLS AUTOCOMPLETE                               */
	/* ═════════════════════════════════════════════════════════════════════ */

	/**
	 * GET /modes — list available @modes (personas / agents).
	 *
	 * [2026-06-18 Johnny Chu] PHASE-TWINWEB Wave 4 — returns persona/agent registry
	 * as lightweight items {id, label, description, icon} for the @ popover.
	 * Sources: bizcity_personas table (if exists) + hardcoded defaults.
	 *
	 * @return WP_REST_Response
	 */
	public function list_modes( WP_REST_Request $request ) {
		$modes = array();

		// Built-in modes always available
		$defaults = array(
			array(
				'id'          => 'chat',
				'label'       => 'Twin AI',
				'description' => 'Trợ lý AI đa năng mặc định',
				'icon'        => 'sparkles',
			),
			array(
				'id'          => 'astro',
				'label'       => 'My Astro',
				'description' => 'Tư vấn chiêm tinh — bản đồ sao, vận mệnh',
				'icon'        => 'moon',
			),
			array(
				'id'          => 'creator',
				'label'       => 'Content Creator',
				'description' => 'Tạo nội dung mạng xã hội, bài blog, email',
				'icon'        => 'pen-line',
			),
			array(
				'id'          => 'doc',
				'label'       => 'Doc AI',
				'description' => 'Tạo tài liệu, DOCX, slide PPTX',
				'icon'        => 'file-text',
			),
			array(
				'id'          => 'image',
				'label'       => 'Image AI',
				'description' => 'Tạo và chỉnh sửa ảnh bằng AI',
				'icon'        => 'image',
			),
		);

		// [2026-06-21 Johnny Chu] PHASE-TWINWEB — removed BizCity_Persona_Registry::get_public_personas()
		// The persona registry is for tool providers, not public UI modes. Method does not exist.
		// Modes list is plugin-extensible via bizcity_twinweb_modes filter.

		// Append defaults
		foreach ( $defaults as $d ) {
			$modes[] = $d;
		}

		// Allow plugins to add/filter modes
		$identity = BizCity_TwinWeb_Identity::current();
		$modes = (array) apply_filters( 'bizcity_twinweb_modes', $modes, $identity['user_id'] );

		return rest_ensure_response( array( 'items' => $modes ) );
	}

	/**
	 * GET /skills — list /skills (automation workflow slugs/templates).
	 *
	 * [2026-06-18 Johnny Chu] PHASE-TWINWEB Wave 4 — returns automation workflow
	 * templates as {id, label, description, icon, category} for the / popover.
	 * Sources: bizcity_automation_workflows (user's own) + seeded templates.
	 *
	 * @return WP_REST_Response
	 */
	/**
	 * GET /skills — 3-tier skill catalog with new fields for workflow pipeline.
	 *
	 * [2026-06-19 Johnny Chu] PHASE-TWB-WORKFLOW W1 — full rewrite.
	 * Changes from previous version:
	 *  - Tier 1: filter enabled=1 (only "Đang chạy" workflows), parse graph_json
	 *    for node_count, add source/workflow_id/trigger_kind/enabled fields.
	 *  - Tier 2: builtin_blueprints now carry node_count (from graph_json) +
	 *    source='builtin', workflow_id=0.
	 *  - Filter changed from bizcity_twinweb_skills → bizcity_twinbrain_skill_catalog
	 *    (surface-agnostic, shared with twinchat AskBrainPanel).
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function list_skills( WP_REST_Request $request ) {
		// [2026-06-19 Johnny Chu] PHASE-TWB-WORKFLOW W1
		$identity = BizCity_TwinWeb_Identity::current();
		$user_id  = (int) $identity['user_id'];
		$skills   = array();

		// ── Tier 1 — User's own ENABLED workflows (enabled=1 = "Đang chạy" only) ──
		// Only workflows the user has deliberately activated. KHÔNG trả về
		// workflow tạm dừng/draft — user chưa sẵn sàng dùng chúng qua /skill.
		if ( $user_id > 0 && class_exists( 'BizCity_Automation_Repo_Workflows' ) ) {
			$found = BizCity_Automation_Repo_Workflows::query( array(
				'created_by' => $user_id,
				'enabled'    => 1,
				'limit'      => 40,
			) );
			// query() may return { rows, total } or a flat array depending on version.
			$rows = array();
			if ( is_array( $found ) ) {
				$rows = isset( $found['rows'] ) && is_array( $found['rows'] ) ? $found['rows'] : $found;
			}
			foreach ( $rows as $wf ) {
				$graph      = json_decode( (string) ( $wf['graph_json'] ?? '' ), true );
				$node_count = is_array( $graph ) ? count( $graph['nodes'] ?? array() ) : 0;
				$slug       = (string) ( $wf['slug'] ?? 'wf_' . (int) $wf['id'] );
				$skills[]   = array(
					'id'           => $slug,
					'label'        => (string) ( $wf['name']         ?? 'Workflow' ),
					'description'  => $node_count . ' bước · ' . (string) ( $wf['trigger_type'] ?? 'manual' ),
					'icon'         => 'zap',
					'category'     => 'workflow',
					'source'       => 'user',
					'workflow_id'  => (int) ( $wf['id'] ?? 0 ),
					'node_count'   => $node_count,
					'trigger_kind' => (string) ( $wf['trigger_type'] ?? 'manual' ),
					'enabled'      => true,
				);
			}
		}

		// ── Tier 2 — Hub-imported workflows (source=hub_imported, enabled=1) ──
		// Same enabled=1 filter — user imported AND activated.
		if ( $user_id > 0 && class_exists( 'BizCity_Automation_Repo_Workflows' ) ) {
			global $wpdb;
			$t    = $wpdb->prefix . 'bizcity_automation_workflows';
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, name, slug, graph_json, trigger_type
				 FROM {$t}
				 WHERE created_by = %d AND enabled = 1
				   AND (
				       (trigger_config_json IS NOT NULL AND trigger_config_json LIKE %s)
				       OR slug LIKE %s
				   )
				 ORDER BY updated_at DESC LIMIT 20",
				$user_id,
				'%hub_imported%',
				'hub_%'
			), ARRAY_A );
			foreach ( (array) $rows as $wf ) {
				// Skip if already in Tier 1 (same slug).
				$slug = (string) ( $wf['slug'] ?? 'wf_' . (int) $wf['id'] );
				foreach ( $skills as $existing ) {
					if ( $existing['id'] === $slug ) {
						continue 2;
					}
				}
				$graph      = json_decode( (string) ( $wf['graph_json'] ?? '' ), true );
				$node_count = is_array( $graph ) ? count( $graph['nodes'] ?? array() ) : 0;
				$skills[]   = array(
					'id'           => $slug,
					'label'        => (string) ( $wf['name'] ?? 'Hub Workflow' ),
					'description'  => $node_count . ' bước · ' . (string) ( $wf['trigger_type'] ?? 'manual' ),
					'icon'         => 'globe',
					'category'     => 'workflow',
					'source'       => 'hub_imported',
					'workflow_id'  => (int) ( $wf['id'] ?? 0 ),
					'node_count'   => $node_count,
					'trigger_kind' => (string) ( $wf['trigger_type'] ?? 'manual' ),
					'enabled'      => true,
				);
			}
		}

		// ── Tier 3 — Built-in skills (always shown; no DB required) ──
		$builtin_skills = array(
			array(
				'id'           => 'remind',
				'label'        => 'Nhắc lịch',
				'description'  => '3 bước · manual',
				'icon'         => 'bell',
				'category'     => 'schedule',
				'source'       => 'builtin',
				'workflow_id'  => 0,
				'node_count'   => 3,
				'trigger_kind' => 'manual',
				'enabled'      => true,
			),
			array(
				'id'           => 'post_fb',
				'label'        => 'Đăng Facebook',
				'description'  => '4 bước · manual',
				'icon'         => 'facebook',
				'category'     => 'social',
				'source'       => 'builtin',
				'workflow_id'  => 0,
				'node_count'   => 4,
				'trigger_kind' => 'manual',
				'enabled'      => true,
			),
			array(
				'id'           => 'note',
				'label'        => 'Ghi chú',
				'description'  => '2 bước · manual',
				'icon'         => 'sticky-note',
				'category'     => 'knowledge',
				'source'       => 'builtin',
				'workflow_id'  => 0,
				'node_count'   => 2,
				'trigger_kind' => 'manual',
				'enabled'      => true,
			),
			array(
				'id'           => 'summary',
				'label'        => 'Tóm tắt',
				'description'  => '2 bước · manual',
				'icon'         => 'file-text',
				'category'     => 'content',
				'source'       => 'builtin',
				'workflow_id'  => 0,
				'node_count'   => 2,
				'trigger_kind' => 'manual',
				'enabled'      => true,
			),
			array(
				'id'           => 'search',
				'label'        => 'Tìm kiếm web',
				'description'  => '3 bước · manual',
				'icon'         => 'search',
				'category'     => 'research',
				'source'       => 'builtin',
				'workflow_id'  => 0,
				'node_count'   => 3,
				'trigger_kind' => 'manual',
				'enabled'      => true,
			),
		);
		foreach ( $builtin_skills as $s ) {
			$skills[] = $s;
		}

		// Extension hook — surface-agnostic, same filter as TwinBrain catalog.
		// Plugins can add/modify skills (e.g. bizcoach-pro adds astro_quick).
		$skills = (array) apply_filters( 'bizcity_twinbrain_skill_catalog', $skills, $user_id );
		// Legacy hook kept for backward compat.
		$skills = (array) apply_filters( 'bizcity_twinweb_skills', $skills, $user_id );

		return rest_ensure_response( array( 'items' => $skills ) );
	}
}
