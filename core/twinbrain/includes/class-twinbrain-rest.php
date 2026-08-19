<?php
/**
 * BizCity TwinBrain REST Controller.
 *
 * Routes (namespace bizcity-twinbrain/v1):
 *   POST /turn            — start a brain turn (Stage 1 + 2 + 4 sync wave 0)
 *   POST /tool/confirm    — Stage 3 user confirmation for tool suggestion
 *   GET  /turn/(?P<trace_id>[\w\-]+) — read replay (delegates to view)
 *
 * SSE re-uses the canonical /wp-json/bizcity-twin/v1/stream channel (R-EVT-4).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Modules\TwinBrain
 * @since      2026-05-10
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

// PHASE-0.41 L3 — trait is required for the `use` below; load defensively so
// this file works even if core/diagnostics bootstrap hasn't fired yet.
if ( ! trait_exists( 'BizCity_REST_Error' ) ) {
	$__trait = dirname( __DIR__, 2 ) . '/diagnostics/includes/trait-rest-error.php';
	if ( file_exists( $__trait ) ) {
		require_once $__trait;
	}
}

class BizCity_TwinBrain_REST {

	// Unified WP_Error builder (status/fix/ctx payload + telemetry recording).
	use BizCity_REST_Error;

	private static $instance = null;
	public static function instance(): self {
		if ( null === self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	protected function rest_error_module(): string {
		return 'twinbrain.rest';
	}

	public function list_verticals( WP_REST_Request $req ) {
		// [2026-08-16 Johnny Chu] CCG-4 — registry is catalog metadata; policy/entitlement remains enforced at runtime.
		$items = class_exists( 'BizCity_TwinBrain_Vertical_Bridge_Registry' )
			? BizCity_TwinBrain_Vertical_Bridge_Registry::all()
			: array();
		return rest_ensure_response( array( 'ok' => true, 'scope' => 'vertical_plugin', 'items' => array_values( $items ) ) );
	}

	public function register_routes(): void {
		// [2026-08-16 Johnny Chu] CCG-4 — shared / Vertical Plugin catalog for TwinChat/TwinWeb.
		register_rest_route( BIZCITY_TWINBRAIN_REST_NS, '/verticals', [
			'methods'             => 'GET',
			'permission_callback' => [ $this, 'perm_logged_in' ],
			'callback'            => [ $this, 'list_verticals' ],
		] );
		register_rest_route( BIZCITY_TWINBRAIN_REST_NS, '/turn', [
			'methods'             => 'POST',
			'permission_callback' => [ $this, 'perm_logged_in' ],
			'args'                => [
				'prompt'           => [ 'type' => 'string', 'required' => true ],
				'k'                => [ 'type' => 'integer', 'required' => false ],
				'force_notebooks'  => [ 'type' => 'array',   'required' => false ],
				'focus_notebook_id' => [ 'type' => 'integer', 'required' => false, 'default' => 0 ],
				'force_tools'      => [ 'type' => 'array',   'required' => false ],
				'skip_tool_intent' => [ 'type' => 'boolean', 'required' => false ],
				'auto_complete'    => [ 'type' => 'boolean', 'required' => false, 'default' => true ],
				// [2026-08-07 Johnny Chu] V4-DEPTH — expose the MPR reasoning tier; absent means canonical high.
				'answer_depth'     => [ 'type' => 'string', 'required' => false, 'enum' => [ 'fast', 'balanced', 'high', 'deep' ], 'default' => 'high' ],
				// [2026-08-11 Johnny Chu] PHASE-TWB-WOO-BIZOPS — keep synchronous REST mode parity with stream.
				'web_mode'         => [ 'type' => 'string', 'required' => false, 'enum' => [ 'off', 'astro', 'quick', 'deep', 'social', 'company', 'med', 'scholar', 'nutri', 'law', 'tax', 'gov', 'products', 'woo_bizops' ] ],
				// [2026-06-03 Johnny Chu] BRAIN-SESSIONS BS-2 — thread session_id.
				'session_id'       => [ 'type' => 'string',  'required' => false ],
			],
			'callback'            => [ $this, 'handle_turn' ],
		] );

		register_rest_route( BIZCITY_TWINBRAIN_REST_NS, '/turn/stream', [
			'methods'             => 'POST',
			'permission_callback' => [ $this, 'perm_logged_in' ],
			'args'                => [
				'prompt'           => [ 'type' => 'string', 'required' => true ],
				'k'                => [ 'type' => 'integer', 'required' => false ],
				'force_notebooks'  => [ 'type' => 'array',   'required' => false ],
				'focus_notebook_id' => [ 'type' => 'integer', 'required' => false, 'default' => 0 ],
				'force_tools'      => [ 'type' => 'array',   'required' => false ],
				'skip_tool_intent' => [ 'type' => 'boolean', 'required' => false ],
				// TBR.W9 (2026-05-21) + TBR.W14/W15 (2026-05-22) + TBR.W17 (2026-05-27/28)
				// — Web Research toggle. Values: 'off' (default), 'quick' (W6),
				// 'deep' (W7), 'social' (W14), 'company' (W15), 'med' (W17), 'scholar'
				// (W17), 'nutri' (W17), 'law' (W17), 'tax' (W17), 'gov' (W17),
				// 'products' (PHASE-TWB-PRODUCTS).
				// [2026-08-03 Johnny Chu] R-TGL-CS — public Brain Chat uses `off`
				// and full MPR; internal casual optimization uses companion_mode.
				// [2026-06-04 Johnny Chu] PHASE-A C.3b — 'astro' mode: bypass MPR,
				// inject transit passages qua CAP filter, compose final với astro context.
				// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS — add products mode enum.
				'web_mode'         => [ 'type' => 'string',  'required' => false, 'enum' => [ 'off', 'astro', 'quick', 'deep', 'social', 'company', 'med', 'scholar', 'nutri', 'law', 'tax', 'gov', 'products', 'woo_bizops' ] ],
				// TBR.W20 (2026-05-28) — Agent mode toggle. 'brain' (default) =
				// full MPR pipeline (perspectives + synthesis); 'agent' = bypass
				// perspectives, run ReAct loop over Tool_Registry instead.
				'mode'             => [ 'type' => 'string',  'required' => false, 'enum' => [ 'brain', 'agent' ] ],
				// [2026-08-07 Johnny Chu] V4-DEPTH — same contract for streaming turns.
				'answer_depth'     => [ 'type' => 'string', 'required' => false, 'enum' => [ 'fast', 'balanced', 'high', 'deep' ], 'default' => 'high' ],
				// [2026-06-03 Johnny Chu] BRAIN-SESSIONS BS-2 — thread session_id.
				// Auto-mint on first turn when omitted; FE picks up session_id from
				// the streamed `started` SSE frame.
				'session_id'       => [ 'type' => 'string',  'required' => false ],
				// [2026-06-19 Johnny Chu] PHASE-TWB-WORKFLOW W1 — /skill param routes to Workflow Pipeline.
				'skill'            => [ 'type' => 'string',  'required' => false, 'default' => '' ],
			],
			'callback'            => [ $this, 'handle_turn_stream' ],
		] );

		register_rest_route( BIZCITY_TWINBRAIN_REST_NS, '/tool/confirm', [
			'methods'             => 'POST',
			'permission_callback' => [ $this, 'perm_logged_in' ],
			'args'                => [
				'trace_id'   => [ 'type' => 'string', 'required' => true ],
				'skill_slug' => [ 'type' => 'string', 'required' => true ],
				'args'       => [ 'type' => 'object', 'required' => false ],
			],
			'callback'            => [ $this, 'handle_tool_confirm' ],
		] );

		register_rest_route( BIZCITY_TWINBRAIN_REST_NS, '/hil/compile', [
			'methods'             => 'POST',
			'permission_callback' => [ $this, 'perm_hil_builder' ],
			'args'                => [
				'trigger_id' => [ 'type' => 'string', 'required' => true ],
				'prompt'     => [ 'type' => 'string', 'required' => true ],
				'context'    => [ 'type' => 'object', 'required' => false ],
			],
			'callback'            => [ $this, 'handle_hil_compile' ],
		] );

		register_rest_route( BIZCITY_TWINBRAIN_REST_NS, '/turn/(?P<trace_id>[\w\-]+)', [
			'methods'             => 'GET',
			'permission_callback' => [ $this, 'perm_logged_in' ],
			'callback'            => [ $this, 'handle_replay' ],
		] );
	}

	public function perm_logged_in() {
		return is_user_logged_in();
	}

	public function perm_hil_builder() {
		// [2026-08-15 Johnny Chu] MPR-V5-HIL-COMPILER — restrict prompt compilation to workflow administrators.
		return is_user_logged_in() && current_user_can( 'manage_options' );
	}

	public function handle_hil_compile( WP_REST_Request $req ) {
		// [2026-08-15 Johnny Chu] MPR-V5-HIL-COMPILER — expose compile/test only; persistence and execution stay outside this endpoint.
		$trigger_id = trim( (string) $req->get_param( 'trigger_id' ) );
		$prompt     = trim( (string) $req->get_param( 'prompt' ) );
		if ( $trigger_id === '' || $prompt === '' ) {
			return $this->hil_error(
				'invalid_param',
				'Trigger và mô tả HIL là bắt buộc.',
				'Nhập trigger ID và mô tả rõ các thông tin cần hỏi.',
				'hil_compile_input'
			);
		}
		if ( ! class_exists( 'BizCity_TwinBrain_HIL_Compiler' ) ) {
			return $this->hil_error(
				'module_not_loaded',
				'Bộ biên dịch HIL chưa được nạp.',
				'Mở lại trang Automation hoặc kiểm tra module TwinBrain.',
				'hil_compiler_unavailable',
				503
			);
		}

		$result = BizCity_TwinBrain_HIL_Compiler::compile(
			$trigger_id,
			$prompt,
			(array) $req->get_param( 'context' )
		);
		return rest_ensure_response( $result );
	}

	private function hil_error( string $code, string $message, string $hint, string $help_code, int $status = 422 ) {
		// [2026-08-15 Johnny Chu] MPR-V5-HIL-COMPILER — preserve the four-field Error UX contract at the REST boundary.
		return new WP_Error(
			$code,
			$message,
			array(
				'status'    => $status,
				'code'      => $code,
				'message'   => $message,
				'hint'      => $hint,
				'help_code' => $help_code,
			)
		);
	}

	/**
	 * TBR.W9 — Normalize `web_mode` request param. Returns 'off' for any
	 * value not in the allowed enum so the Stage 2.5 dispatcher can fall
	 * back to a no-op when the FE composer is in default state.
	 */
	private function sanitize_web_mode( $raw ): string {
		$v = strtolower( trim( (string) $raw ) );
		// [2026-08-03 Johnny Chu] R-TGL-CS — legacy 'chat' requests normalize to
		// off so Brain Chat uses the canonical full-MPR path.
		// [2026-06-04 Johnny Chu] PHASE-A C.3b — accept 'astro' (transit mode).
		// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS — accept 'products' vertical mode.
		// MISSING from whitelist trước đây → astro request bị fallback 'off' →
		// chạy full MPR pipeline (notebook perspectives) thay vì stream_astro_mode.
		// [2026-08-11 Johnny Chu] PHASE-TWB-WOO-BIZOPS — accept mode; resolver enforces admin capability before data access.
		return in_array( $v, [ 'astro', 'quick', 'deep', 'social', 'company', 'med', 'scholar', 'nutri', 'law', 'tax', 'gov', 'products', 'woo_bizops' ], true ) ? $v : 'off';
	}

	/**
	 * TBR.W20 — Normalize `mode` request param. Default 'brain' (full MPR
	 * pipeline). 'agent' = bypass perspectives, run ReAct loop over
	 * Tool_Registry. Unknown value → 'brain'.
	 */
	private function sanitize_mode( $raw ): string {
		$v = strtolower( trim( (string) $raw ) );
		return $v === 'agent' ? 'agent' : 'brain';
	}

	/**
	 * [2026-08-07 Johnny Chu] V4-DEPTH — normalize the user-selected MPR tier
	 * at the REST boundary; unknown values fail closed to canonical high.
	 */
	private function sanitize_answer_depth( $raw ): string {
		$value = strtolower( trim( (string) $raw ) );
		return in_array( $value, array( 'fast', 'balanced', 'high', 'deep' ), true ) ? $value : 'high';
	}

	/**
	 * [2026-06-03 Johnny Chu] BRAIN-SESSIONS BS-2 — Resolve session_id from
	 * the request, validating ownership when supplied. Mints a fresh session
	 * (with brain_session_created event) when missing or invalid. Returns
	 * the canonical session_id to thread into runtime opts.
	 */
	private function resolve_session_id( WP_REST_Request $req, int $user_id ): string {
		if ( ! class_exists( 'BizCity_TwinBrain_Sessions_Manager' ) ) {
			return '';
		}
		$mgr = BizCity_TwinBrain_Sessions_Manager::instance();
		$raw = trim( (string) $req->get_param( 'session_id' ) );
		if ( $raw !== '' && BizCity_TwinBrain_Sessions_Manager::is_valid_session_id( $raw ) ) {
			$existing = $mgr->get( $raw, $user_id );
			if ( ! empty( $existing ) ) {
				return $raw;
			}
			// Caller passed a fresh id we haven't seen — honour it and mint.
			$res = $mgr->create( [ 'user_id' => $user_id, 'session_id' => $raw, 'source' => 'user' ] );
			return (string) ( $res['session_id'] ?? $raw );
		}
		$res = $mgr->create( [ 'user_id' => $user_id, 'source' => 'user' ] );
		return (string) ( $res['session_id'] ?? '' );
	}

	public function handle_turn( WP_REST_Request $req ) {
		$prompt = trim( (string) $req->get_param( 'prompt' ) );
		if ( $prompt === '' ) {
			return $this->err_validation( 'twinbrain_empty_prompt', 'Prompt bắt buộc không được để trống.' );
		}
		$focus_notebook_id = absint( $req->get_param( 'focus_notebook_id' ) );
		$focus_policy = '';
		if ( $focus_notebook_id > 0 ) {
			// [2026-08-16 Johnny Chu] P2 — keep sync and SSE focus validation identical.
			$focus_check = $this->validate_focus_notebook( $prompt, get_current_user_id(), $focus_notebook_id );
			if ( is_wp_error( $focus_check ) ) {
				return $focus_check;
			}
			$focus_policy = (string) ( $focus_check['policy'] ?? '' );
		}
		// [2026-06-03 Johnny Chu] BRAIN-SESSIONS BS-2 — resolve / mint session.
		$session_id = $this->resolve_session_id( $req, get_current_user_id() );
		$opts = [
			'user_id'          => get_current_user_id(),
			'k'                => $req->get_param( 'k' ) ?: BIZCITY_TWINBRAIN_K_DEFAULT,
			'force_notebooks'  => (array) $req->get_param( 'force_notebooks' ),
			'focus_notebook_id' => $focus_notebook_id,
			'force_tools'      => (array) $req->get_param( 'force_tools' ),
			'skip_tool_intent' => (bool)  $req->get_param( 'skip_tool_intent' ),
			'answer_depth'     => $this->sanitize_answer_depth( $req->get_param( 'answer_depth' ) ),
			// [2026-08-11 Johnny Chu] PHASE-TWB-WOO-BIZOPS — propagate synchronous vertical mode to runtime.
			'web_mode'         => $this->sanitize_web_mode( $req->get_param( 'web_mode' ) ),
			'session_id'       => $session_id,
		];
		if ( $focus_notebook_id > 0 ) {
			$opts['force_notebooks'] = array( $focus_notebook_id );
		}
		// [2026-08-16 Johnny Chu] CCG-2 — literal /vertical_slug must resolve deterministically when the client never set web_mode.
		if ( 'off' === $opts['web_mode'] && class_exists( 'BizCity_TwinBrain_Vertical_Bridge_Registry' ) ) {
			$vertical_command = BizCity_TwinBrain_Vertical_Bridge_Registry::extract( $prompt );
			if ( is_array( $vertical_command ) ) {
				$opts['web_mode'] = (string) $vertical_command['slug'];
			}
		}

		$runtime = BizCity_TwinBrain_Runtime::instance();
		$start   = $runtime->start_turn( $prompt, $opts );

		if ( $req->get_param( 'auto_complete' ) ) {
			$done = $runtime->complete_turn(
				$start['trace_id'],
				$prompt,
				$start['candidates'],
				$start['tool_candidates'],
				array(
					'guru_id'                   => (int)    ( $start['guru_id']    ?? 0 ),
					'tool_force'                => (string) ( $start['tool_force'] ?? '' ),
					// [2026-07-27 Johnny Chu] PHASE-0.52 W3 — reuse start-stage profile context in auto-complete.
					'subject_context_md'        => (string) ( $start['subject_context_md'] ?? '' ),
					'subject_context_label'     => (string) ( $start['subject_context_label'] ?? '' ),
					'subject_id'                => (int)    ( $start['subject_id'] ?? 0 ),
					'_subject_profile_resolved' => ! empty( $start['_subject_profile_resolved'] ),
					'user_id'                   => (int)    ( $opts['user_id'] ?? 0 ),
					'session_id'                => (string) ( $opts['session_id'] ?? '' ),
					'identity_uuid'             => (string) ( $start['identity_uuid'] ?? '' ),
					'identity_state'            => (string) ( $start['identity_state'] ?? 'unknown' ),
					'subject_contract'          => (array)  ( $start['subject_contract'] ?? array() ),
					'goal_loop_state'            => (array)  ( $start['goal_loop_state'] ?? array() ),
					'goal_loop'                 => (array)  ( $start['goal_loop_state'] ?? array() ),
					'goal_contract'              => (array)  ( $start['goal_contract'] ?? array() ),
					// [2026-08-01 Johnny Chu] PHASE-TWIN-GOAL-LOOP-G2 — preserve the active goal brief for Final Composer.
					'goal_loop_brief'           => (string) ( $start['goal_loop_brief'] ?? '' ),
					'answer_depth'               => (string) ( $start['answer_depth'] ?? $opts['answer_depth'] ?? 'high' ), // [2026-08-07 Johnny Chu] V4-DEPTH — preserve resolved tier for synchronous completion.
					'goal_loop_pre_turn_completed' => ! empty( $start['goal_loop_pre_turn_completed'] ), // [2026-08-07 Johnny Chu] V4-DEPTH — preserve parser lifecycle marker.
					'pre_mpr_triage'             => (array) ( $start['pre_mpr_triage'] ?? array() ), // [2026-08-07 Johnny Chu] V4-TRIAGE — preserve ambiguous/MPR branch.
					'ambiguous_no_goal'          => ! empty( $start['ambiguous_no_goal'] ),
					// [2026-08-11 Johnny Chu] PHASE-TWB-WOO-BIZOPS — preserve vertical mode during synchronous completion.
					'web_mode'                   => (string) ( $opts['web_mode'] ?? 'off' ),
				)
			);
			return rest_ensure_response( array_merge( $start, $done, [ 'session_id' => $session_id ] ) );
		}
		return rest_ensure_response( array_merge( $start, [ 'session_id' => $session_id ] ) );
	}

	/**
	 * TBR.F6-sse — progressive turn over Server-Sent Events.
	 * Mirrors `handle_turn` (auto_complete=true) but pushes phase events as
	 * they happen. Final synthesis still arrives in the SSE `complete` frame
	 * so a client that reconnects mid-turn can recover state from the BE
	 * event-stream replay (`GET /turn/{trace_id}`).
	 */
	public function handle_turn_stream( WP_REST_Request $req ) {
		$prompt = trim( (string) $req->get_param( 'prompt' ) );
		if ( $prompt === '' ) {
			return $this->err_validation( 'twinbrain_empty_prompt', 'Prompt bắt buộc không được để trống.' );
		}
		if ( ! class_exists( 'BizCity_Twin_SSE_Writer' ) ) {
			return $this->err( 'twinbrain_sse_unavailable', 'SSE writer chưa được nạp — plugin core twin-core có thể bị tắt.', 503, [], null, true );
		}
		$focus_notebook_id = absint( $req->get_param( 'focus_notebook_id' ) );
		$focus_policy = '';
		if ( $focus_notebook_id > 0 ) {
			// [2026-08-16 Johnny Chu] P2 — reject focused notebook requests before MPR can widen scope.
			$focus_check = $this->validate_focus_notebook( $prompt, get_current_user_id(), $focus_notebook_id );
			if ( is_wp_error( $focus_check ) ) {
				return $focus_check;
			}
			$focus_policy = (string) ( $focus_check['policy'] ?? '' );
		}

		$opts = [
			'user_id'          => get_current_user_id(),
			'k'                => $req->get_param( 'k' ) ?: BIZCITY_TWINBRAIN_K_DEFAULT,
			'force_notebooks'  => (array) $req->get_param( 'force_notebooks' ),
			'focus_notebook_id' => $focus_notebook_id,
			'force_tools'      => (array) $req->get_param( 'force_tools' ),
			'skip_tool_intent' => (bool)  $req->get_param( 'skip_tool_intent' ),
			'answer_depth'     => $this->sanitize_answer_depth( $req->get_param( 'answer_depth' ) ),
			// TBR.W9 (2026-05-21) — propagate web_mode to runtime so Stage 2.5
			// engines (Web_Quick / Web_Deep) fire after notebook perspectives.
			'web_mode'         => $this->sanitize_web_mode( $req->get_param( 'web_mode' ) ),
			// TBR.W20 (2026-05-28) — propagate agent-mode toggle.
			'mode'             => $this->sanitize_mode( $req->get_param( 'mode' ) ),
			// [2026-06-03 Johnny Chu] BRAIN-SESSIONS BS-2 — resolve / mint session.
			'session_id'       => $this->resolve_session_id( $req, get_current_user_id() ),
		];
		if ( $focus_notebook_id > 0 ) {
			$opts['force_notebooks'] = array( $focus_notebook_id );
		}
		// [2026-08-16 Johnny Chu] CCG-2 — literal /vertical_slug must resolve deterministically before the fuzzy conversation router runs.
		if ( 'off' === $opts['web_mode'] && class_exists( 'BizCity_TwinBrain_Vertical_Bridge_Registry' ) ) {
			$vertical_command = BizCity_TwinBrain_Vertical_Bridge_Registry::extract( $prompt );
			if ( is_array( $vertical_command ) ) {
				$opts['web_mode'] = (string) $vertical_command['slug'];
			}
		}
		$conversation_route = array();
		// [2026-08-01 Johnny Chu] R-CH-IDMEM — scope pending confirmation by blog, user, and session.
		$confirm_key = 'twinchat:' . (int) get_current_blog_id() . ':' . (int) ( $opts['user_id'] ?? 0 ) . ':' . (string) ( $opts['session_id'] ?? '' );
		$skill = trim( (string) $req->get_param( 'skill' ) );
		$confirmation_result = array( 'status' => 'none' );
		if ( class_exists( 'BizCity_TwinBrain_Conversation_Confirmation' )
			&& class_exists( 'BizCity_TwinBrain_Conversation_Router' )
			&& BizCity_TwinBrain_Conversation_Router::SPECIALIZED_ROUTING_ENABLED ) {
			// [2026-08-01 Johnny Chu] PHASE-TBR-CHAT-DEFAULT — pending confirmation takes precedence over stale sticky Skill UI state.
			$confirmation_result = BizCity_TwinBrain_Conversation_Confirmation::consume( $confirm_key, $prompt );
			if ( in_array( (string) ( $confirmation_result['status'] ?? '' ), array( 'confirmed', 'invalid' ), true ) ) {
				// [2026-08-01 Johnny Chu] PHASE-TBR-CHAT-DEFAULT — a confirmation reply is conversational, never a workflow skill invocation.
				$skill = '';
			}
			if ( ( $confirmation_result['status'] ?? '' ) === 'confirmed' ) {
				$prompt = (string) ( $confirmation_result['prompt'] ?? $prompt );
				$conversation_route = (array) ( $confirmation_result['decision'] ?? array() );
			}
		}
		// [2026-08-01 Johnny Chu] PHASE-TBR-CHAT-DEFAULT — route the default TwinChat Brain surface through the shared Layer 0.9 classifier.
		if ( class_exists( 'BizCity_TwinBrain_Conversation_Router' )
			&& BizCity_TwinBrain_Conversation_Router::SPECIALIZED_ROUTING_ENABLED
			&& '' === trim( (string) $req->get_param( 'skill' ) )
			&& 'off' === (string) $opts['web_mode']
			&& ( $confirmation_result['status'] ?? '' ) !== 'confirmed'
			&& class_exists( 'BizCity_TwinBrain_Conversation_Router' ) ) {
			try {
				$conversation_route = BizCity_TwinBrain_Conversation_Router::route(
					$prompt,
					(int) $opts['user_id'],
					array(
						'surface' => 'twinchat',
						'session_id' => (string) ( $opts['session_id'] ?? '' ),
						'trace_id' => (string) ( $opts['trace_id'] ?? '' ),
					)
				);
				if ( empty( $conversation_route['needs_confirm'] ) ) {
					if ( ! empty( $conversation_route['web_mode'] ) && 'off' !== $conversation_route['web_mode'] ) {
						$opts['web_mode'] = sanitize_key( (string) $conversation_route['web_mode'] );
					} elseif ( 'casual' === (string) ( $conversation_route['route'] ?? '' )
						&& 'casual_fast_path' === (string) ( $conversation_route['reason'] ?? '' ) ) {
						// [2026-08-02 Johnny Chu] PHASE-TWIN-GOAL-LOOP-MPR — only true greeting/small-talk may use companion chat; long natural prompts must retain Notebook/MPR search.
						$opts['companion_mode'] = true;
						$opts['web_mode'] = 'off';
					}
					if ( ! empty( $conversation_route['force_notebooks'] ) ) {
						$opts['force_notebooks'] = array_map( 'intval', (array) $conversation_route['force_notebooks'] );
					}
				}
			} catch ( \Throwable $e ) {
				$conversation_route = array( 'route' => 'casual', 'reason' => 'router_error' );
			}
		}

		$sse = new BizCity_Twin_SSE_Writer( true );

		// [2026-08-16 Johnny Chu] CCG-5 — exact #workflow_slug bypasses MPR/skill routing and reuses Automation Runner.
		if ( class_exists( 'BizCity_Automation_Command_Resolver' )
			&& BizCity_Automation_Command_Resolver::extract( $prompt ) ) {
			$this->handle_explicit_workflow_command( $prompt, $opts, $sse );
			$sse->close( array( 'surface' => 'twinchat_workflow_command' ) );
			exit;
		}

		// [2026-06-19 Johnny Chu] PHASE-TWB-WORKFLOW W1 — route /skill → Workflow Pipeline.
		// When AskBrainPanel sends `skill` param, bypass normal MPR pipeline and
		// run the workflow-driven pipeline instead. Fail-OPEN if class missing.
		if ( $skill === '' && class_exists( 'BizCity_TwinBrain_Conversation_Confirmation' ) ) {
			$confirmed = $confirmation_result;
			if ( ( $confirmed['status'] ?? '' ) === 'confirmed' ) {
				if ( ! empty( $conversation_route['web_mode'] ) && 'off' !== $conversation_route['web_mode'] ) {
					$opts['web_mode'] = sanitize_key( (string) $conversation_route['web_mode'] );
				} elseif ( 'casual' === (string) ( $conversation_route['route'] ?? '' )
					&& 'casual_fast_path' === (string) ( $conversation_route['reason'] ?? '' ) ) {
					// [2026-08-02 Johnny Chu] PHASE-TWIN-GOAL-LOOP-MPR — preserve Notebook/MPR for goal-bearing natural chat after confirmation handoff.
					$opts['companion_mode'] = true;
					$opts['web_mode'] = 'off';
				}
				if ( ! empty( $conversation_route['force_notebooks'] ) ) {
					$opts['force_notebooks'] = array_map( 'intval', (array) $conversation_route['force_notebooks'] );
				}
			} elseif ( ( $confirmed['status'] ?? '' ) === 'invalid' ) {
				$trace_id = 'tb_' . wp_generate_uuid4();
				$sse->emit( 'started', array( 'trace_id' => $trace_id, 'session_id' => (string) $opts['session_id'] ) );
				$sse->emit( 'conversation_confirm_prompt', array(
					'trace_id' => $trace_id,
					'message'  => 'Sếp trả lời "Có" để dùng nguồn chuyên gia, hoặc "Không" để em trả lời chung nhé.',
					'route'    => 'notebook',
					'expires_in' => BizCity_TwinBrain_Conversation_Confirmation::TTL,
				) );
				BizCity_TwinBrain_Conversation_Confirmation::dispatch_prompt(
					array( 'trace_id' => $trace_id, 'message' => 'Sếp trả lời "Có" để dùng nguồn chuyên gia, hoặc "Không" để em trả lời chung nhé.', 'route' => 'notebook', 'expires_in' => BizCity_TwinBrain_Conversation_Confirmation::TTL ),
					array( 'event_source' => 'twinbrain', 'session_id' => (string) $opts['session_id'], 'user_id' => (int) $opts['user_id'] )
				);
				$sse->close( array() );
				exit;
			}
			if ( ! empty( $conversation_route['needs_confirm'] ) && in_array( (string) ( $conversation_route['route'] ?? '' ), array( 'notebook', 'vertical' ), true ) ) {
				$label = '';
				if ( ! empty( $conversation_route['candidate_vertical'] ) ) {
					$label = (string) ( BizCity_TwinBrain_Conversation_Router::VERTICAL_CATALOG[ $conversation_route['candidate_vertical'] ]['label'] ?? $conversation_route['candidate_vertical'] );
				} elseif ( ! empty( $conversation_route['candidate_notebook_titles'][0] ) ) {
					$label = 'Notebook "' . (string) $conversation_route['candidate_notebook_titles'][0] . '"';
				}
				if ( $label !== '' && BizCity_TwinBrain_Conversation_Confirmation::begin( $confirm_key, $prompt, $conversation_route ) ) {
					$trace_id = 'tb_' . wp_generate_uuid4();
					$sse->emit( 'started', array( 'trace_id' => $trace_id, 'session_id' => (string) $opts['session_id'] ) );
					$sse->emit( 'conversation_confirm_prompt', array(
						'trace_id' => $trace_id,
						'message'  => 'Câu hỏi này có vẻ liên quan tới ' . $label . '. Sếp muốn em dùng nguồn đó để trả lời không?',
						'route'    => (string) $conversation_route['route'],
						'candidate_notebook_ids' => array_values( array_map( 'intval', (array) ( $conversation_route['candidate_notebook_ids'] ?? array() ) ) ),
						'candidate_vertical' => (string) ( $conversation_route['candidate_vertical'] ?? '' ),
						'expires_in' => BizCity_TwinBrain_Conversation_Confirmation::TTL,
					) );
					BizCity_TwinBrain_Conversation_Confirmation::dispatch_prompt(
						array( 'trace_id' => $trace_id, 'message' => 'Câu hỏi này có vẻ liên quan tới ' . $label . '. Sếp muốn em dùng nguồn đó để trả lời không?', 'route' => (string) $conversation_route['route'], 'expires_in' => BizCity_TwinBrain_Conversation_Confirmation::TTL ),
						array( 'event_source' => 'twinbrain', 'session_id' => (string) $opts['session_id'], 'user_id' => (int) $opts['user_id'] )
					);
					$sse->close( array() );
					exit;
				}

			}
		}
		if ( $skill !== '' && class_exists( 'BizCity_TwinBrain_Workflow_Pipeline' ) ) {
			$pipeline = BizCity_TwinBrain_Workflow_Pipeline::instance();
			// Inject SSE emitter that mirrors BizCity_Twin_SSE_Writer->emit().
			$pipeline->set_sse_emitter( static function ( string $ev, array $data ) use ( $sse ) {
				$sse->emit( $ev, $data );
			} );
			$trace_id = 'tb_' . wp_generate_uuid4();
			$sse->emit( 'started', array(
				'trace_id'   => $trace_id,
				'session_id' => (string) ( $opts['session_id'] ?? '' ),
			) );
			try {
				$pipeline->run( $trace_id, $prompt, array(
					'skill'      => $skill,
					'user_id'    => (int) ( $opts['user_id'] ?? 0 ),
					'guest_sid'  => '',
					'session_id' => (string) ( $opts['session_id'] ?? '' ),
					'surface'    => 'twinchat',
					'history'    => array(),
					'on_token'   => static function ( $delta, $acc ) use ( $sse ) {
						$sse->emit( 'final_token', array( 'delta' => (string) $delta ) );
					},
				) );
				$sse->close( array() );
			} catch ( \Throwable $e ) {
				// [2026-08-01 Johnny Chu] R-ERROR-UX — keep exception detail in server logs and return a safe retry contract.
				error_log( '[TwinBrain][twinbrain-rest] workflow pipeline threw: ' . get_class( $e ) . ' ' . $e->getMessage() );
				$sse->error( 'Skill pipeline không thể hoàn tất.', 'twin_agent_exception' );
			}
			exit;
		}

		try {
			$runtime = BizCity_TwinBrain_Runtime::instance();

			$sse->emit( 'started', [ 'prompt' => $prompt, 'session_id' => (string) ( $opts['session_id'] ?? '' ) ] );
			$start = $runtime->start_turn( $prompt, $opts );
			$trace_id = (string) ( $start['trace_id'] ?? '' );
			if ( $focus_notebook_id > 0 ) {
				$sse->emit( 'notebook_focus_resolved', array(
					'trace_id' => $trace_id,
					'notebook_id' => $focus_notebook_id,
					'guru_id' => (int) ( $start['guru_id'] ?? 0 ),
					'policy' => $focus_policy,
				) );
			}
			// [2026-08-07 Johnny Chu] V4-TRIAGE — project provider-first route metadata onto native SSE.
			$triage_sse = (array) ( $start['pre_mpr_triage'] ?? array() );
			$sse->emit( 'conversation_triage_started', array(
				'trace_id'         => $trace_id,
				'triage_model'     => (string) ( $triage_sse['triage_model'] ?? 'openai/gpt-5.6-luna' ),
				'triage_reasoning' => (string) ( $triage_sse['triage_reasoning'] ?? 'low' ),
			) );
			$sse->emit( 'conversation_triage_done', array(
				'trace_id'          => $trace_id,
				'route'             => (string) ( $triage_sse['route'] ?? 'mpr' ),
				'conversation_kind' => (string) ( $triage_sse['conversation_kind'] ?? 'unclear' ),
				'confidence'        => (float) ( $triage_sse['confidence'] ?? 0 ),
				'reason_code'       => (string) ( $triage_sse['reason_code'] ?? '' ),
				'mpr_dispatched'    => ( (string) ( $triage_sse['route'] ?? 'mpr' ) === 'mpr' ),
			) );
			if ( ! empty( $conversation_route ) ) {
				$sse->emit( 'twin_event', array(
					'event_uuid' => wp_generate_uuid4(),
					'event_type' => 'conversation_route_decided',
					'event_source' => 'twinbrain',
					'trace_id' => $trace_id,
					'payload' => array(
						'route' => (string) ( $conversation_route['route'] ?? 'casual' ),
						'confidence' => (float) ( $conversation_route['confidence'] ?? 0 ),
						'needs_confirm' => ! empty( $conversation_route['needs_confirm'] ),
						'candidate_notebook_ids' => array_values( array_map( 'intval', (array) ( $conversation_route['candidate_notebook_ids'] ?? array() ) ) ),
						'candidate_vertical' => (string) ( $conversation_route['candidate_vertical'] ?? '' ),
						'web_mode' => (string) ( $conversation_route['web_mode'] ?? 'off' ),
						'reason' => (string) ( $conversation_route['reason'] ?? '' ),
					),
				) );
			}

			/* PHASE-0.35 / F7.C4.1 — Re-emit Layer 0/1 events as native SSE so
			 * the FE BrainThinkingTimeline reducer can render guru search +
			 * instruction layer steps (event_bus echo only fires on debug). */
			if ( ! empty( $start['pre_rules'] ) ) {
				$sse->emit( 'pre_rules_done', (array) $start['pre_rules'] );
			}
			if ( ! empty( $start['guru_lookup'] ) ) {
				$sse->emit( 'guru_lookup', (array) $start['guru_lookup'] );
			}
			if ( ! empty( $start['guru_layer'] ) ) {
				$sse->emit( 'guru_layer', (array) $start['guru_layer'] );
			}

			/* Wave 2.8 — Layer 0.5 Memory Recall (TBR.MEM-3 echo). */
			if ( ! empty( $start['memory_recall'] ) ) {
				$sse->emit( 'memory_recall', (array) $start['memory_recall'] );
			}

			$sse->emit( 'candidates_selected', [
				'trace_id'        => $trace_id,
				'candidates'      => (array) ( $start['candidates']      ?? [] ),
				'tool_candidates' => (array) ( $start['tool_candidates'] ?? [] ),
				'keyword_tokens'  => (array) ( $start['keyword_tokens']  ?? [] ),
			] );

			$done = $runtime->complete_turn_stream(
				$trace_id,
				$prompt,
				(array) ( $start['candidates'] ?? [] ),
				(array) ( $start['tool_candidates'] ?? [] ),
				$sse,
				array(
					'guru_id'                   => (int)    ( $start['guru_id']    ?? 0 ),
					'tool_force'                => (string) ( $start['tool_force'] ?? '' ),
					// [2026-07-27 Johnny Chu] PHASE-0.52 W3 — reuse start-stage profile context in SSE completion.
					'subject_context_md'        => (string) ( $start['subject_context_md'] ?? '' ),
					'subject_context_label'     => (string) ( $start['subject_context_label'] ?? '' ),
					'subject_id'                => (int)    ( $start['subject_id'] ?? 0 ),
					'_subject_profile_resolved' => ! empty( $start['_subject_profile_resolved'] ),
					'identity_uuid'             => (string) ( $start['identity_uuid'] ?? '' ),
					'identity_state'            => (string) ( $start['identity_state'] ?? 'unknown' ),
					'subject_contract'          => (array)  ( $start['subject_contract'] ?? array() ),
					'goal_loop_state'            => (array)  ( $start['goal_loop_state'] ?? array() ),
					'goal_loop'                 => (array)  ( $start['goal_loop_state'] ?? array() ),
					// [2026-08-01 Johnny Chu] PHASE-TWIN-GOAL-LOOP-G2 — preserve the active goal brief for Final Composer.
					'goal_loop_brief'           => (string) ( $start['goal_loop_brief'] ?? '' ),
					'goal_contract'              => (array)  ( $start['goal_contract'] ?? array() ),
					'answer_depth'               => (string) ( $start['answer_depth'] ?? $opts['answer_depth'] ?? 'high' ), // [2026-08-07 Johnny Chu] V4-DEPTH — preserve resolved tier for streaming completion.
					'pre_mpr_triage'             => (array) ( $start['pre_mpr_triage'] ?? array() ), // [2026-08-07 Johnny Chu] V4-TRIAGE — preserve ambiguous/MPR branch.
					'ambiguous_no_goal'          => ! empty( $start['ambiguous_no_goal'] ),
					// TBR.W9 — Stage 2.5 toggle propagated from REST opts.
					'web_mode'       => (string) ( $opts['web_mode']    ?? 'off' ),
					// TBR.W20 — Agent mode toggle (brain | agent).
					'mode'           => (string) ( $opts['mode']        ?? 'brain' ),
					// TBR.SEL-LEX — tokenized prompt shared with downstream
					// (perspective passage rerank + FE highlight).
					'keyword_tokens' => (array)  ( $start['keyword_tokens'] ?? [] ),
					// Wave 2.8 TBR.MEM — recall block + user/session ctx for Writer.
					'memory_block'   => (string) ( $start['memory_block']   ?? '' ),
					'user_id'        => (int)    ( $opts['user_id']        ?? 0 ),
					'session_id'     => (string) ( $opts['session_id']     ?? '' ),
				)
			);

			$sse->close( array_merge(
				[ 'trace_id' => $trace_id ],
				$start,
				$done
			) );
		} catch ( \Throwable $e ) {
			$sse->error( $e->getMessage(), 'twinbrain_turn_stream_error' );
		}

		// Headers + body already streamed; return null so WP REST doesn't try
		// to serialize a JSON response over the closed event-stream.
		exit;
	}

	private function handle_explicit_workflow_command( $prompt, array $opts, BizCity_Twin_SSE_Writer $sse ): void {
		// [2026-08-16 Johnny Chu] CCG-5 — resolve, authorize, run and expose explicit workflow command state.
		$user_id = (int) ( $opts['user_id'] ?? 0 );
		$resolved = BizCity_Automation_Command_Resolver::resolve(
			$prompt,
			array( 'user_id' => $user_id, 'is_admin' => current_user_can( 'manage_options' ), 'zone' => 'admin' ),
			array( 'zone' => 'admin' )
		);
		$trace_id = 'tb_' . wp_generate_uuid4();
		$sse->emit( 'started', array( 'trace_id' => $trace_id, 'session_id' => (string) ( $opts['session_id'] ?? '' ), 'surface' => 'twinchat_workflow_command' ) );
		if ( empty( $resolved['matched'] ) ) {
			$sse->emit( 'error', array(
				'code'      => (string) ( $resolved['reason'] ?? 'workflow_command_denied' ),
				'message'   => 'Workflow command không được phép chạy.',
				'hint'      => 'Chọn workflow được cấp quyền trong danh sách # rồi thử lại.',
				'help_code' => 'automation_run_failed',
			) );
			return;
		}
		$workflow = (array) ( $resolved['workflow'] ?? array() );
		$workflow_id = (int) ( $workflow['id'] ?? 0 );
		$chat_id = 'twinchat_prompt_' . $user_id . '_' . sanitize_key( (string) ( $opts['session_id'] ?? 'default' ) );
		$run_payload = array(
			'platform' => 'TWINCHAT', 'event_subtype' => 'twinchat_prompt', 'origin_surface' => 'twinchat_prompt',
			'text' => (string) $prompt, 'raw_text' => (string) $prompt, 'wp_user_id' => $user_id,
			'_owner_user_id' => $user_id, 'user_id' => (string) $user_id, 'chat_id' => $chat_id,
			'conversation_chat_id' => $chat_id, '_trigger' => 'prompt_command',
			'command_slug' => (string) ( $resolved['slug'] ?? '' ), 'command_args' => (string) ( $resolved['args'] ?? '' ), 'zone' => 'admin',
		);
		$sse->emit( 'automation_run_started', array(
			'trace_id' => $trace_id, 'workflow_id' => $workflow_id,
			'workflow' => (string) ( $workflow['name'] ?? $workflow['slug'] ?? '' ), 'slug' => (string) ( $resolved['slug'] ?? '' ),
		) );
		if ( ! class_exists( 'BizCity_Automation_Runner' ) ) {
			$sse->emit( 'error', array( 'code' => 'automation_runtime_unavailable', 'message' => 'Automation runtime chưa sẵn sàng.', 'hint' => 'Kiểm tra module Automation rồi thử lại.', 'help_code' => 'automation_run_failed' ) );
			return;
		}
		$result = BizCity_Automation_Runner::instance()->run_now( $workflow_id, $run_payload );
		if ( is_wp_error( $result ) ) {
			$sse->emit( 'error', array( 'code' => (string) $result->get_error_code(), 'message' => 'Workflow không chạy được.', 'hint' => 'Kiểm tra lại kịch bản Automation và thử lại.', 'help_code' => 'automation_run_failed' ) );
			return;
		}
		$sse->emit( 'automation_run_done', array(
			'trace_id' => $trace_id, 'workflow_id' => $workflow_id,
			'run_id' => is_scalar( $result ) ? (string) $result : '', 'slug' => (string) ( $resolved['slug'] ?? '' ),
		) );
	}

	private function validate_focus_notebook( $prompt, $user_id, $notebook_id ) {
		// [2026-08-16 Johnny Chu] P2 — focused notebook must belong to the resolved Guru attachment set.
		if ( ! class_exists( 'BizCity_Guru_Token_Parser' ) || ! class_exists( 'BizCity_KG_Database' ) ) {
			return new WP_Error( 'guru_notebook_focus_unavailable', 'Không thể xác thực phạm vi notebook của Guru.', array( 'status' => 503 ) );
		}
		$parsed = BizCity_Guru_Token_Parser::parse( (string) $prompt );
		$guru_id = (int) ( $parsed['guru_id'] ?? 0 );
		if ( $guru_id <= 0 ) {
			return new WP_Error( 'guru_notebook_focus_invalid', 'Notebook focus cần Guru Workspace hợp lệ.', array( 'status' => 400 ) );
		}
		global $wpdb;
		$db = BizCity_KG_Database::instance();
		$guru_uuid = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT guru_uuid FROM ' . $wpdb->prefix . 'bizcity_characters WHERE id = %d LIMIT 1', $guru_id ) );
		if ( $guru_uuid === '' ) {
			return new WP_Error( 'guru_notebook_focus_invalid', 'Guru Workspace không thuộc site hiện tại.', array( 'status' => 400 ) );
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT n.owner_id, n.notebook_scope FROM ' . $db->tbl_notebooks() . ' n INNER JOIN ' . $db->tbl_notebook_character_attachments() . ' a ON a.notebook_id = n.id WHERE a.guru_uuid = %s AND n.id = %d LIMIT 1', $guru_uuid, (int) $notebook_id ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return new WP_Error( 'guru_notebook_focus_invalid', 'Notebook này chưa được gắn vào Guru Workspace.', array( 'status' => 400 ) );
		}
		$owner_id = (int) ( $row['owner_id'] ?? 0 );
		$scope = sanitize_key( (string) ( $row['notebook_scope'] ?? 'personal' ) );
		$visible = ( $owner_id > 0 && $owner_id === (int) $user_id ) || ( $owner_id === 0 && in_array( $scope, array( 'business_kb', 'guru_kb' ), true ) );
		if ( ! $visible ) {
			return new WP_Error( 'guru_notebook_focus_invalid', 'Notebook focus không thuộc phạm vi được phép.', array( 'status' => 403 ) );
		}
		$guru = $wpdb->get_row( $wpdb->prepare( 'SELECT notebook_policy FROM ' . $wpdb->prefix . 'bizcity_characters WHERE id = %d LIMIT 1', $guru_id ), ARRAY_A );
		return array(
			'valid'  => true,
			'policy' => in_array( (string) ( $guru['notebook_policy'] ?? 'augment' ), array( 'augment', 'restrict' ), true ) ? (string) $guru['notebook_policy'] : 'augment',
		);
	}

	public function handle_tool_confirm( WP_REST_Request $req ) {
		// TODO PHASE-0.36 sprint .9 — invoke Shell Engine to run the skill,
		// then re-trigger synthesizer with tool result included.
		return rest_ensure_response( [
			'ok'      => true,
			'pending' => 'sprint_0.36.9',
			'trace_id'=> (string) $req->get_param( 'trace_id' ),
			'skill'   => (string) $req->get_param( 'skill_slug' ),
		] );
	}

	public function handle_replay( WP_REST_Request $req ) {
		global $wpdb;
		$trace_id = (string) $req['trace_id'];
		$tbl      = $wpdb->prefix . 'bizcity_twin_event_stream';
		$prev     = $wpdb->suppress_errors( true );
		$exists_row = bizcity_tbl_exists( $tbl ) ? $tbl : null; // [2026-06-21 Johnny Chu] R-SHOW-TABLES
		if ( $exists_row !== $tbl ) {
			$wpdb->suppress_errors( $prev );
			return $this->err_table_missing( $tbl, 'twinbrain' );
		}
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT event_type, payload_json, created_epoch_ms
			 FROM {$tbl}
			 WHERE trace_id = %s
			 ORDER BY created_epoch_ms ASC
			 LIMIT 500",
			$trace_id
		), ARRAY_A );
		$wpdb->suppress_errors( $prev );
		return rest_ensure_response( [ 'ok' => true, 'trace_id' => $trace_id, 'events' => (array) $rows ] );
	}
}
