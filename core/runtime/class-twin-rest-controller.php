<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Runtime
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 *
 * Phase 0.13 — Task 1.13.7
 * BizCity_Twin_REST_Controller — REST endpoint POST /run.
 *
 * Endpoint:   POST /wp-json/bizcity-twin/v1/run
 * Auth:       WordPress nonce (cookie) or Application Password
 * Body (JSON):
 *   {
 *     "agent_name":        string  (required)
 *     "messages":          [{role, content}, ...]  (required, min 1)
 *     "conversation_id":   string  (optional — provide to continue a session)
 *     "decisions":         [{call_id, decision, reason}, ...]  (Vòng 2 HIL resume)
 *     "context_overrides": object  (optional — merged into run context)
 *   }
 *
 * Response 200:
 *   {
 *     "run_id":        string
 *     "conversation_id": string
 *     "status":        "completed"|"failed"|"paused_hil"
 *     "final_output":  string|null
 *     "interruptions": array
 *     "events_url":    string  (SSE replay URL — future use)
 *     "error":         string|null
 *   }
 *
 * @since 1.13.0
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Twin_REST_Controller' ) ) return;

/**
 * BizCity Twin REST Controller
 *
 * Registers and handles the single `POST /run` endpoint that drives all
 * agent interactions from the frontend (CopilotKit canvas, TwinChat, etc.).
 *
 * This class is intentionally stateless: each request creates a fresh
 * BizCity_Twin_Rolling_Session and BizCity_Twin_Runner instance.
 */
final class BizCity_Twin_REST_Controller {

	const NAMESPACE = 'bizcity-twin/v1';
	const ROUTE     = '/run';

	/** @var self|null */
	private static $instance = null;

	public static function instance(): self {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/* ================================================================
	 *  Route registration
	 * ================================================================ */

	/**
	 * Register the REST route.
	 * Attach to `rest_api_init` action.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			[
				'methods'             => \WP_REST_Server::CREATABLE, // POST
				'callback'            => [ $this, 'handle_run' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => $this->get_endpoint_args(),
			]
		);

		// Phase 0.13 Vòng 3 — events polling endpoint.
		// GET /wp-json/bizcity-twin/v1/events/{run_id}?since=N
		register_rest_route(
			self::NAMESPACE,
			'/events/(?P<run_id>[A-Za-z0-9_\-]+)',
			[
				'methods'             => \WP_REST_Server::READABLE, // GET
				'callback'            => [ $this, 'handle_events' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => [
					'run_id' => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
					'since'  => [
						'type'              => 'integer',
						'required'          => false,
						'default'           => 0,
						'sanitize_callback' => 'absint',
					],
				],
			]
		);
	}

	/* ================================================================
	 *  Events handler (Vòng 3 — polling, SSE later)
	 * ================================================================ */

	/**
	 * Return events for a given run since a sequence number.
	 *
	 * Polling-based MVP — returns JSON instead of true SSE so it works
	 * reliably behind nginx/PHP-FPM without buffering issues.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_events( \WP_REST_Request $request ) {
		if ( ! class_exists( 'BizCity_TwinShell_Event_Bus' ) ) {
			return new \WP_Error( 'bizcity_twin_no_bus', 'Event bus not loaded.', [ 'status' => 500 ] );
		}

		$run_id = (string) $request->get_param( 'run_id' );
		$since  = (int) $request->get_param( 'since' );

		$events  = BizCity_TwinShell_Event_Bus::fetch( $run_id, $since );
		$last    = $since;
		foreach ( $events as $ev ) {
			if ( $ev['seq'] > $last ) {
				$last = $ev['seq'];
			}
		}

		return new \WP_REST_Response( [
			'run_id'     => $run_id,
			'since'      => $since,
			'next_since' => $last,
			'count'      => count( $events ),
			'events'     => $events,
		], 200 );
	}

	/* ================================================================
	 *  Permission
	 * ================================================================ */

	/**
	 * Require logged-in user.
	 *
	 * @param \WP_REST_Request $request
	 * @return bool|\WP_Error
	 */
	public function check_permission( \WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'Authentication required.', 'bizcity-twin-ai' ),
				[ 'status' => 401 ]
			);
		}
		return true;
	}

	/* ================================================================
	 *  Handler
	 * ================================================================ */

	/**
	 * Handle POST /run.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_run( \WP_REST_Request $request ) {
		// ── Vòng 5 hardening 5.10 — Rate limit per user (OWASP) ──────
		// 10 req / 60s rolling window via transient. Skips for admins.
		$current_uid = (int) get_current_user_id();
		if ( $current_uid > 0 && ! current_user_can( 'manage_options' ) ) {
			$rl_window = (int) apply_filters( 'bizcity_twin_run_rate_window', 60 );
			$rl_max    = (int) apply_filters( 'bizcity_twin_run_rate_max', 10 );
			$rl_key    = 'bcty_run_rl_' . $current_uid;
			$rl_count  = (int) get_transient( $rl_key );
			if ( $rl_count >= $rl_max ) {
				return new \WP_Error(
					'bizcity_twin_rate_limited',
					sprintf(
						/* translators: 1: max requests, 2: window seconds */
						__( 'Rate limit exceeded: %1$d requests per %2$d seconds.', 'bizcity-twin-ai' ),
						$rl_max,
						$rl_window
					),
					[ 'status' => 429, 'retry_after' => $rl_window ]
				);
			}
			set_transient( $rl_key, $rl_count + 1, $rl_window );
		}

		// Read raw JSON body to avoid WP REST schema munging (esp. for typed object/array params).
		$body = $request->get_json_params();
		if ( ! is_array( $body ) || empty( $body ) ) {
			// Fallback: parse raw body manually (some proxies strip Content-Type).
			$raw = $request->get_body();
			if ( is_string( $raw ) && $raw !== '' ) {
				$decoded = json_decode( $raw, true );
				if ( is_array( $decoded ) ) $body = $decoded;
			}
		}
		if ( ! is_array( $body ) ) $body = [];

		$agent_name      = sanitize_text_field( (string) ( $body['agent_name'] ?? $request->get_param( 'agent_name' ) ?? '' ) );

		// ── Vòng 5 hardening 5.12 — Agent name whitelist (prevent injection) ──
		// Only accept agents registered via BizCity_Twin_Agent_Registry. Skip
		// when resuming (run_id present) — registry resolved at run time.
		$is_resume_check = ! empty( $body['run_id'] );
		if ( ! $is_resume_check && $agent_name !== '' ) {
			if ( ! preg_match( '/^[a-z0-9_]{1,64}$/', $agent_name ) ) {
				return new \WP_Error(
					'bizcity_twin_invalid_agent_name',
					__( 'agent_name must match [a-z0-9_]{1,64}.', 'bizcity-twin-ai' ),
					[ 'status' => 400 ]
				);
			}
			if ( class_exists( 'BizCity_Twin_Agent_Registry' ) ) {
				if ( ! BizCity_Twin_Agent_Registry::instance()->has( $agent_name ) ) {
					return new \WP_Error(
						'bizcity_twin_unknown_agent',
						sprintf(
							/* translators: %s: agent name */
							__( 'Unknown agent: %s', 'bizcity-twin-ai' ),
							$agent_name
						),
						[ 'status' => 404 ]
					);
				}
			}
		}
		$messages        = $body['messages'] ?? null;
		$conversation_id = sanitize_text_field( (string) ( $body['conversation_id'] ?? '' ) );
		$ctx_overrides   = (array) ( $body['context_overrides'] ?? [] );
		$run_id          = sanitize_text_field( (string) ( $body['run_id'] ?? '' ) );
		$decisions_raw   = $body['decisions'] ?? [];

		if ( is_object( $decisions_raw ) ) {
			$decisions_raw = (array) $decisions_raw;
		} elseif ( ! is_array( $decisions_raw ) ) {
			$decisions_raw = [];
		}

		// ── Validate decisions (Vòng 2 HIL) ───────────────────────────
		$decisions = $this->validate_decisions( $decisions_raw );
		if ( is_wp_error( $decisions ) ) {
			return $decisions;
		}

		$is_resume = ( $run_id !== '' && ! empty( $decisions ) );

		// ── Validate messages (skip when resuming) ────────────────────
		if ( $is_resume ) {
			$messages = []; // not used on resume
		} else {
			$messages = $this->validate_messages( $messages );
			if ( is_wp_error( $messages ) ) {
				// Attach diagnostic so we can see what server received.
				$messages->add_data( [
					'status'         => 400,
					'received_keys'  => array_keys( $body ),
					'has_run_id'     => $run_id !== '',
					'has_decisions'  => ! empty( $decisions ),
					'body_preview'   => substr( wp_json_encode( $body ), 0, 400 ),
					'handler_marker' => 'v2-resume-aware',
				] );
				return $messages;
			}
		}

		// ── Persist decisions to resume_signals (audit trail) ─────────
		if ( $is_resume ) {
			$store = BizCity_Trace_Store::instance();
			foreach ( $decisions as $call_id => $d ) {
				$store->write_decision(
					$run_id,
					(string) $call_id,
					(string) $d['decision'],
					(string) ( $d['reason'] ?? '' ),
					(int) get_current_user_id()
				);
			}
		}

		// ── Build run context ─────────────────────────────────────────
		$ctx = array_merge(
			[
				'user_id'         => get_current_user_id(),
				'blog_id'         => get_current_blog_id(),
				'conversation_id' => $conversation_id ?: null,
			],
			$ctx_overrides
		);

		if ( $is_resume ) {
			$ctx['run_id']    = $run_id;
			$ctx['decisions'] = $decisions;
		}

		// ── Wire up collaborators + execute (wrapped to surface fatals as JSON) ──
		try {
			$session = new BizCity_Twin_Rolling_Session( $conversation_id ?: null );
			$runner  = new BizCity_Twin_Runner(
				BizCity_Twin_Agent_Registry::instance(),
				$session
			);

			// ── Vòng 4.5.5b — Async resume (avoid Cloudflare 524) ──
			// HIL resume can take 30–120s while the LLM drafts content. CF
			// origin timeout = 100s. Solution: ack immediately with status
			// 'running', then continue runner in the background. FE polls
			// /events/{run_id} for the eventual `final` / `failed` event
			// (events already streamed to bizcity_trace_tasks via Event Bus).
			if ( $is_resume ) {
				$ack = [
					'run_id'          => $run_id,
					'conversation_id' => $conversation_id,
					'status'          => 'running',
					'final_output'    => null,
					'interruptions'   => [],
					'events_url'      => rest_url( self::NAMESPACE . '/events/' . rawurlencode( $run_id ) ),
					'error'           => null,
				];

				// Send ack synchronously, then detach client.
				if ( ! headers_sent() ) {
					nocache_headers();
					@header( 'Content-Type: application/json; charset=utf-8' );
				}
				echo wp_json_encode( $ack );

				// Flush + close connection (PHP-FPM); fall back to ob/flush.
				if ( function_exists( 'fastcgi_finish_request' ) ) {
					@fastcgi_finish_request();
				} else {
					while ( @ob_get_level() > 0 ) { @ob_end_flush(); }
					@flush();
				}

				ignore_user_abort( true );
				@set_time_limit( 0 );

				try {
					$runner->run( $agent_name, [], $ctx );
				} catch ( \Throwable $e ) {
					if ( class_exists( 'BizCity_TwinShell_Event_Bus' ) ) {
						BizCity_TwinShell_Event_Bus::emit(
							$run_id,
							'failed',
							[ 'error' => $e->getMessage() ]
						);
					}
				}
				exit;
			}

			$state = $runner->run( $agent_name, $messages, $ctx );

			return rest_ensure_response( $this->state_to_response( $state ) );
		} catch ( \Throwable $e ) {
			return new \WP_Error(
				'bizcity_twin_runner_exception',
				$e->getMessage(),
				[
					'status' => 500,
					'file'   => $e->getFile(),
					'line'   => $e->getLine(),
					'trace'  => array_slice( explode( "\n", $e->getTraceAsString() ), 0, 8 ),
				]
			);
		}
	}

	/**
	 * Validate the `decisions` body parameter.
	 * Accepts either:
	 *   { call_id: 'approved' }                          (shorthand)
	 *   { call_id: { decision: 'approved', reason: '' } } (full form)
	 *
	 * @param mixed $raw
	 * @return array|\WP_Error  array<string, array{decision:string, reason:string}>
	 */
	private function validate_decisions( $raw ) {
		if ( empty( $raw ) ) return [];
		if ( ! is_array( $raw ) ) {
			return new \WP_Error(
				'bizcity_twin_invalid_decisions',
				__( '"decisions" must be an object keyed by call_id.', 'bizcity-twin-ai' ),
				[ 'status' => 400 ]
			);
		}

		$clean = [];
		foreach ( $raw as $call_id => $value ) {
			$call_id = sanitize_text_field( (string) $call_id );
			if ( $call_id === '' ) continue;

			if ( is_object( $value ) ) {
				$value = (array) $value;
			}

			if ( is_string( $value ) ) {
				$decision = $value;
				$reason   = '';
			} elseif ( is_array( $value ) ) {
				$decision = (string) ( $value['decision'] ?? '' );
				$reason   = (string) ( $value['reason'] ?? '' );
			} else {
				continue;
			}

			if ( ! in_array( $decision, [ 'approved', 'rejected' ], true ) ) {
				return new \WP_Error(
					'bizcity_twin_invalid_decision',
					sprintf(
						/* translators: 1: decision value, 2: call id */
						__( 'Invalid decision "%1$s" for call_id "%2$s".', 'bizcity-twin-ai' ),
						$decision,
						$call_id
					),
					[ 'status' => 400 ]
				);
			}

			$clean[ $call_id ] = [
				'decision' => $decision,
				'reason'   => sanitize_text_field( $reason ),
			];
		}
		return $clean;
	}

	/* ================================================================
	 *  Helpers
	 * ================================================================ */

	/**
	 * Validate and sanitize messages array.
	 *
	 * @param mixed $messages
	 * @return array|\WP_Error
	 */
	private function validate_messages( $messages ) {
		if ( ! is_array( $messages ) || empty( $messages ) ) {
			return new \WP_Error(
				'bizcity_twin_invalid_messages',
				__( '"messages" must be a non-empty array.', 'bizcity-twin-ai' ),
				[ 'status' => 400 ]
			);
		}

		$clean = [];
		foreach ( $messages as $idx => $msg ) {
			if ( ! is_array( $msg ) || ! isset( $msg['role'], $msg['content'] ) ) {
				return new \WP_Error(
					'bizcity_twin_invalid_message',
					/* translators: %d: message index */
					sprintf( __( 'Message at index %d must have "role" and "content".', 'bizcity-twin-ai' ), $idx ),
					[ 'status' => 400 ]
				);
			}

			$allowed_roles = [ 'user', 'assistant', 'system', 'tool' ];
			$role          = sanitize_text_field( $msg['role'] );

			if ( ! in_array( $role, $allowed_roles, true ) ) {
				return new \WP_Error(
					'bizcity_twin_invalid_role',
					/* translators: %s: invalid role */
					sprintf( __( 'Invalid role "%s".', 'bizcity-twin-ai' ), $role ),
					[ 'status' => 400 ]
				);
			}

			$clean[] = [
				'role'    => $role,
				'content' => wp_kses_post( $msg['content'] ),
			];
		}

		return $clean;
	}

	/**
	 * Convert a RunState to the REST response shape.
	 *
	 * @param BizCity_Twin_RunState $state
	 * @return array
	 */
	private function state_to_response( BizCity_Twin_RunState $state ): array {
		$events_url = rest_url( self::NAMESPACE . '/events/' . rawurlencode( $state->run_id ) );

		return [
			'run_id'          => $state->run_id,
			'conversation_id' => $state->conversation_id,
			'status'          => $state->status,
			'final_output'    => $state->final_output,
			'interruptions'   => $state->interruptions,
			'events_url'      => $events_url,
			'error'           => $state->error,
		];
	}

	/**
	 * Endpoint argument schema.
	 *
	 * @return array
	 */
	private function get_endpoint_args(): array {
		return [
			'agent_name'      => [
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
				'description'       => 'Agent identifier.',
			],
			'messages'        => [
				'type'        => 'array',
				'required'    => false,
				'description' => 'Message history [{role, content}, ...]. Required unless resuming with run_id+decisions.',
			],
			'conversation_id' => [
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'description'       => 'Optional conversation ID for session continuity.',
			],
			'run_id'          => [
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'description'       => 'Existing run ID to resume (Vòng 2 HIL).',
			],
			'decisions'       => [
				'type'        => 'object',
				'required'    => false,
				'description' => 'HIL decisions { call_id: "approved"|"rejected" }.',
			],
			'context_overrides' => [
				'type'        => 'object',
				'required'    => false,
				'description' => 'Optional context overrides merged into run context.',
			],
		];
	}
}
