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

	public function register_routes(): void {
		register_rest_route( BIZCITY_TWINBRAIN_REST_NS, '/turn', [
			'methods'             => 'POST',
			'permission_callback' => [ $this, 'perm_logged_in' ],
			'args'                => [
				'prompt'           => [ 'type' => 'string', 'required' => true ],
				'k'                => [ 'type' => 'integer', 'required' => false ],
				'force_notebooks'  => [ 'type' => 'array',   'required' => false ],
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

		register_rest_route( BIZCITY_TWINBRAIN_REST_NS, '/turn/(?P<trace_id>[\w\-]+)', [
			'methods'             => 'GET',
			'permission_callback' => [ $this, 'perm_logged_in' ],
			'callback'            => [ $this, 'handle_replay' ],
		] );
	}

	public function perm_logged_in() {
		return is_user_logged_in();
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
		// [2026-06-03 Johnny Chu] BRAIN-SESSIONS BS-2 — resolve / mint session.
		$session_id = $this->resolve_session_id( $req, get_current_user_id() );
		$opts = [
			'user_id'          => get_current_user_id(),
			'k'                => $req->get_param( 'k' ) ?: BIZCITY_TWINBRAIN_K_DEFAULT,
			'force_notebooks'  => (array) $req->get_param( 'force_notebooks' ),
			'force_tools'      => (array) $req->get_param( 'force_tools' ),
			'skip_tool_intent' => (bool)  $req->get_param( 'skip_tool_intent' ),
			'answer_depth'     => $this->sanitize_answer_depth( $req->get_param( 'answer_depth' ) ),
			// [2026-08-11 Johnny Chu] PHASE-TWB-WOO-BIZOPS — propagate synchronous vertical mode to runtime.
			'web_mode'         => $this->sanitize_web_mode( $req->get_param( 'web_mode' ) ),
			'session_id'       => $session_id,
		];

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

		$opts = [
			'user_id'          => get_current_user_id(),
			'k'                => $req->get_param( 'k' ) ?: BIZCITY_TWINBRAIN_K_DEFAULT,
			'force_notebooks'  => (array) $req->get_param( 'force_notebooks' ),
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
