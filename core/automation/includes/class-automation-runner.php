<?php
/**
 * BizCity_Automation_Runner — DAG executor (BE-3).
 *
 * Public API:
 *   $runner = BizCity_Automation_Runner::instance();
 *   $run_id = $runner->enqueue( $workflow_id, $payload );
 *   $runner->execute( $run_id );           // sync (used by REST + tests)
 *
 * Pipeline (per execute()):
 *   1. Load run + workflow (status guard: queued only).
 *   2. validate_graph() — Kahn topo sort over nodes/edges.
 *   3. For each node in topo order:
 *        - Resolve block from registry.
 *        - Merge predecessor outputs into ctx + alias short keys (`{kind}` →
 *          last node of that kind, e.g. `kg`, `llm`, `trigger`).
 *        - Execute block (block::execute already resolves `{{tokens}}`).
 *        - Append log row + emit `bizcity_automation_log_appended` action
 *          so SSE consumer can stream.
 *        - On `logic.condition` → skip the unmatched branch (graph walk).
 *   4. set_status ok | fail; emit CRM bridge event (canonical action).
 *   5. Cron-deferred mode: skip step 1-3 inline; rely on cron dispatcher.
 *
 * R-CRON-META: cron handler `bizcity_automation_cron_dispatch` notes counters
 * `runs_picked` / `runs_done` / `runs_failed` and `note_event()` with reason
 * buckets {block_failed, block_timeout, validation_failed, *_error}.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Automation
 * @since      AUTOMATION BE-3 (2026-05-29)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Automation_Runner {

	const LOG_STATUS_RUNNING = 0;
	const LOG_STATUS_OK      = 1;
	const LOG_STATUS_FAIL    = 2;
	const LOG_STATUS_SKIP    = 3;

	const CRON_HOOK = 'bizcity_automation_cron_dispatch';
	const CRON_BATCH_MAX = 5;
	// [2026-07-26 Johnny Chu] CRON-OVERLOAD-PHASE-A/B — job_id used to register with
	// BizCity_Cron_Manager (must match bootstrap.php) + wall-clock time budget so a
	// single tick self-limits its duration (observed 28-30s ticks widen the WP
	// pseudo-cron double-fire race window). See TRACE-CRON-OVERLOAD-2026-07-26.md.
	const CRON_JOB_ID = 'core.automation.dispatch';
	const CRON_TIME_BUDGET_SECONDS = 45;

	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) { self::$instance = new self(); }
		return self::$instance;
	}

	private function __construct() {}

	/** Convenience: enqueue + execute right away. */
	public function run_now( int $workflow_id, $payload = null ) {
		// [2026-08-20 Johnny Chu] R-CLI-ASYNC-ISOLATION — direct automation
		// execution is not part of diagnostics mock mode.
		if ( defined( 'BIZCITY_DIAGNOSTICS_CLI' ) && BIZCITY_DIAGNOSTICS_CLI ) {
			return new WP_Error( 'diagnostics_async_isolated', 'Automation worker is isolated during diagnostics CLI.' );
		}
		$run_id = BizCity_Automation_Repo_Runs::enqueue( $workflow_id, $payload );
		if ( is_wp_error( $run_id ) ) { return $run_id; }
		return $this->execute( $run_id );
	}

	/**
	 * Executor — pure SYNC. SSE consumer polls log table independently.
	 *
	 * @param string $run_id
	 * @return array|WP_Error  { status, ctx, logs_count }
	 */
	public function execute( string $run_id ) {
		// [2026-08-20 Johnny Chu] R-CLI-ASYNC-ISOLATION — defense in depth for
		// callback, REST sync, replay, resume, and direct runner calls.
		if ( defined( 'BIZCITY_DIAGNOSTICS_CLI' ) && BIZCITY_DIAGNOSTICS_CLI ) {
			return new WP_Error( 'diagnostics_async_isolated', 'Automation worker is isolated during diagnostics CLI.' );
		}
		$run = BizCity_Automation_Repo_Runs::find( $run_id );
		if ( ! $run ) {
			return new WP_Error( 'run_not_found', 'run_id không tồn tại', array( 'run_id' => $run_id ) );
		}

		// PG-S5: allow re-entry when run is RUNNING + paused. The /step + /resume
		// REST handlers leave status=RUNNING and set debug_state='paused_before:*'
		// or 'stepping'/''. Anything else (OK/FAIL/CANCELLED) is terminal.
		$cur_status = (int) $run['status'];
		$debug      = (string) ( $run['debug_state'] ?? '' );
		$is_resume  = false;
		$skip_break_once_for = '';

		if ( $cur_status === BizCity_Automation_Repo_Runs::STATUS_QUEUED ) {
			// [2026-06-25 Johnny Chu] PHASE-TRENDING W1 FIX — atomic CAS QUEUED→RUNNING.
			// Prevents double-execution race: on_cron_dispatch + bizcity_automation_run_async
			// both seeing STATUS_QUEUED in concurrent WP-Cron processes.
			$claimed = BizCity_Automation_Repo_Runs::try_claim_running( $run_id, array(
				'started_at' => current_time( 'mysql' ),
			) );
			if ( ! $claimed ) {
				return new WP_Error( 'run_already_claimed', 'run đã được process khác lấy', array( 'run_id' => $run_id ) );
			}
		} elseif ( $cur_status === BizCity_Automation_Repo_Runs::STATUS_RUNNING && ( strpos( $debug, 'paused_before:' ) === 0 || $debug === 'stepping' || $debug === 'pausing' || $debug === '' ) ) {
			$is_resume = true;
			if ( strpos( $debug, 'paused_before:' ) === 0 ) {
				$skip_break_once_for = (string) substr( $debug, strlen( 'paused_before:' ) );
			}
		} else {
			return new WP_Error( 'run_not_queued', 'run đã chạy / kết thúc', array(
				'run_id' => $run_id, 'status' => $cur_status,
			) );
		}

		$wf = BizCity_Automation_Repo_Workflows::find( (int) $run['workflow_id'] );
		if ( ! $wf ) {
			BizCity_Automation_Repo_Runs::set_status( $run_id, BizCity_Automation_Repo_Runs::STATUS_FAIL, array(
				'error' => 'workflow_missing', 'ended_at' => current_time( 'mysql' ),
			) );
			return new WP_Error( 'workflow_missing', 'Workflow không tồn tại.', array( 'workflow_id' => $run['workflow_id'] ) );
		}
		$graph = $wf['graph'] ?? array();
		$nodes = isset( $graph['nodes'] ) && is_array( $graph['nodes'] ) ? $graph['nodes'] : array();
		$edges = isset( $graph['edges'] ) && is_array( $graph['edges'] ) ? $graph['edges'] : array();
		$trigger_payload = isset( $run['trigger_payload'] ) && is_array( $run['trigger_payload'] )
			? $run['trigger_payload']
			: array();
		// [2026-08-01 Johnny Chu] PHASE-1.26-CORRELATION — restore the queued
		// correlation context before any automation block or outbound reply runs.
		if ( class_exists( 'BizCity_Chat_Correlation' ) ) {
			$correlation = BizCity_Chat_Correlation::import_async( $trigger_payload, 'automation_run' );
			$trigger_payload['event_uuid'] = (string) ( $correlation['event_uuid'] ?? '' );
			$trigger_payload['trace_id'] = (string) ( $correlation['trace_id'] ?? '' );
			$trigger_payload['parent_event_uuid'] = (string) ( $correlation['parent_event_uuid'] ?? '' );
		}
		if ( ! empty( $trigger_payload['_hil_ready'] )
			&& ! empty( $trigger_payload['_hil_id'] )
			&& ! empty( $trigger_payload['_hil_identity_uuid'] )
			&& ! empty( $trigger_payload['_hil_session_id'] ) ) {
			// [2026-08-16 Johnny Chu] PHASE-2-HIL-ORDER-SCHEMA — decrypt HIL slots only in runner memory; never persist PII in trigger_payload_json.
			$hil_state = class_exists( 'BizCity_TwinBrain_HIL_Repository' )
				? BizCity_TwinBrain_HIL_Repository::latest(
				(int) get_current_blog_id(),
				(string) $trigger_payload['_hil_identity_uuid'],
				(string) $trigger_payload['_hil_session_id'],
				(string) $trigger_payload['_hil_id']
				)
				: array();
			if ( is_array( $hil_state ) && is_array( $hil_state['slot_values'] ?? null ) ) {
				$trigger_payload['hil_slots'] = $hil_state['slot_values'];
			}
			if ( ! is_array( $hil_state )
				|| (string) ( $hil_state['status'] ?? '' ) !== 'ready'
				|| ! class_exists( 'BizCity_TwinBrain_HIL_State' )
				|| ! BizCity_TwinBrain_HIL_State::is_side_effect_ready( $hil_state, (array) ( $trigger_payload['_hil_spec'] ?? $wf['trigger_config']['hil_spec'] ?? array() ) ) ) {
				// [2026-08-16 Johnny Chu] PHASE-2-HIL-ORDER-SCHEMA — never trust _hil_ready without the scoped ready snapshot and required slot values.
				$err = new WP_Error( 'hil_state_invalid', 'HIL state không còn hợp lệ cho side effect.', array( 'status' => 409 ) );
				$this->finish_failed( $run_id, $err, 'hil_state_invalid' );
				return $err;
			}
		}
		$had_outbound_trace_ctx = array_key_exists( '_bizcity_outbound_trace_ctx', $GLOBALS );
		$previous_outbound_trace_ctx = $had_outbound_trace_ctx && is_array( $GLOBALS['_bizcity_outbound_trace_ctx'] )
			? $GLOBALS['_bizcity_outbound_trace_ctx']
			: array();
		$async_trace_id = (string) ( $correlation['trace_id'] ?? '' );
		$correlation_context_active = false;
		if ( class_exists( 'BizCity_Chat_Correlation' ) && $async_trace_id !== '' ) {
			// [2026-08-01 Johnny Chu] PHASE-1.26-CORRELATION — bind the imported
			// source event as parent context; outbound sends must receive fresh UUIDs.
			BizCity_Chat_Correlation::bind_pending_root( $correlation );
			$GLOBALS['_bizcity_outbound_trace_ctx'] = array(
				'trace_id'          => $async_trace_id,
				'parent_event_uuid' => (string) ( $correlation['event_uuid'] ?? '' ),
				'source'            => 'automation.runner',
			);
			$correlation_context_active = true;
		}
		try {
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-MY-CONTENT-TRACE — linked channel user owns content/events before persisted workflow-owner fallback.
		$owner_user_id = (int) ( $trigger_payload['wp_user_id'] ?? $run['user_id'] ?? $trigger_payload['_owner_user_id'] ?? $wf['created_by'] ?? 0 );

		// Mark running (skip if resuming — already RUNNING; also skip if just claimed above which already set it).
		if ( ! $is_resume && $cur_status !== BizCity_Automation_Repo_Runs::STATUS_QUEUED ) {
			// Only for resume path — QUEUED path already claimed via try_claim_running().
			BizCity_Automation_Repo_Runs::set_status( $run_id, BizCity_Automation_Repo_Runs::STATUS_RUNNING, array(
				'started_at' => current_time( 'mysql' ),
			) );
			do_action( 'bizcity_automation_run_started', $run_id, $wf );
		} elseif ( $cur_status === BizCity_Automation_Repo_Runs::STATUS_QUEUED ) {
			// QUEUED path: hook fired after atomic claim.
			do_action( 'bizcity_automation_run_started', $run_id, $wf );
		} else {
			// On resume, clear `paused_before:*` immediately so a concurrent /pause
			// call writes 'pausing' instead of being lost. Keep 'stepping' as-is.
			if ( strpos( $debug, 'paused_before:' ) === 0 ) {
				BizCity_Automation_Repo_Runs::set_debug_state( $run_id, '' );
				$debug = '';
			}
			do_action( 'bizcity_automation_run_resumed', $run_id, $wf, $skip_break_once_for );
		}

		// Breakpoints (PG-S5): per-node `before` flags from workflow config.
		$breakpoints = is_array( $wf['debug_breakpoints'] ?? null ) ? $wf['debug_breakpoints'] : array();

		// Track which nodes already produced a successful log row in a prior
		// execute() pass — skip them on resume so we don't duplicate side-effects.
		$completed_nodes = array();
		if ( $is_resume ) {
			foreach ( BizCity_Automation_Repo_Runs::logs( $run_id ) as $log ) {
				if ( in_array( (int) $log['status'], array( self::LOG_STATUS_OK, self::LOG_STATUS_SKIP ), true ) ) {
					$completed_nodes[ (string) $log['node_id'] ] = true;
				}
			}
		}

		$registry = BizCity_Automation_Block_Registry::instance();
		$nodes_by_id = array();
		foreach ( $nodes as $n ) { $nodes_by_id[ $n['id'] ] = $n; }

		// Topo sort.
		$order = $this->topo_sort( $nodes, $edges );
		if ( is_wp_error( $order ) ) {
			$this->finish_failed( $run_id, $order, 'validation_failed' );
			return $order;
		}

		// Build successor map for branch skipping.
		$succ = array();
		foreach ( $edges as $e ) {
			$src = $e['source'] ?? ''; $tgt = $e['target'] ?? '';
			if ( $src === '' || $tgt === '' ) { continue; }
			$handle = (string) ( $e['sourceHandle'] ?? 'out' );
			$succ[ $src ][] = array( 'target' => $tgt, 'handle' => $handle );
		}

		$ctx = array(
			'trigger'      => $trigger_payload,
			'_run_id'      => $run_id,
			'_workflow_id' => (int) $wf['id'],
			'_hil_spec'    => isset( $trigger_payload['_hil_spec'] ) && is_array( $trigger_payload['_hil_spec'] )
				? $trigger_payload['_hil_spec']
				: ( isset( $wf['trigger_config']['hil_spec'] ) && is_array( $wf['trigger_config']['hil_spec'] ) ? $wf['trigger_config']['hil_spec'] : array() ),
			'_hil_ready'   => ! empty( $trigger_payload['_hil_ready'] ),
			// [2026-06-02 Johnny Chu] AUTOMATION SCHED-OWNER — propagate
			// workflow owner xuống ctx để action block (publish_fb_post,
			// scheduler_*) attach event vào đúng user_id thay vì user_id=0
			// (cron context không có current user → calendar UI trống lốc).
			'_owner_user_id' => $owner_user_id,
			'_meta'        => array(
				'workflow_slug' => $wf['slug'],
				'workflow_name' => $wf['name'],
				'created_by'    => (int) ( $wf['created_by'] ?? 0 ),
				'owner_user_id' => $owner_user_id,
			),
			// PG-S9 — dry-run flag bay theo ctx top-level. Block side-effect
			// (reply_zalo, send_email, http_request, db_write…) check cờ này
			// để mock thay vì gọi thật.
			'_dry_run'     => ! empty( $run['trigger_payload']['_dry_run'] ),
		);

		// PG-S9-fix — Auto-inject resume từ Pending_State.
		// Lý do: FE "Chạy thử" capture event xong POST trực tiếp
		// /workflows/:id/run, KHÔNG đi qua matcher → ctx.trigger._resume
		// luôn rỗng → cond `_resume.attachment_url != ''` FALSE → flow
		// đăng-bài-multi-turn rơi lại nhánh "hỏi gửi ảnh" mãi.
		// Logic ở đây mirror matcher::on_channel_normalized:
		//   1. Nếu turn này có media_url, append pending.attachments[].
		//   2. Inject pending vào trigger._resume nếu chưa có.
		$trigger_chat_id = (string) ( $ctx['trigger']['chat_id'] ?? '' );
		if (
			$trigger_chat_id !== ''
			&& class_exists( 'BizCity_Automation_Pending_State' )
		) {
			$pending = BizCity_Automation_Pending_State::get( $trigger_chat_id );
			$existing_resume = is_array( $ctx['trigger']['_resume'] ?? null ) ? $ctx['trigger']['_resume'] : array();
			$incoming_media = (string) ( $ctx['trigger']['media_url'] ?? '' );
			if ( $incoming_media !== '' ) {
				// [2026-07-21 Johnny Chu] R-AUTO-MULTI-ATTACH — direct FE/REST runs preserve media as canonical attachments[].
				BizCity_Automation_Pending_State::append_attachment( $trigger_chat_id, array(
					'kind'        => (string) ( $ctx['trigger']['media_kind'] ?? 'image' ),
					'url'         => $incoming_media,
					'source_url'  => $incoming_media,
					'message_id'  => (string) ( $ctx['trigger']['mid'] ?? $ctx['trigger']['message_id'] ?? '' ),
					'received_at' => time(),
				) );
				$pending = BizCity_Automation_Pending_State::get( $trigger_chat_id );
			}
			if ( ! empty( $pending ) || ! empty( $existing_resume ) ) {
				// [2026-07-21 Johnny Chu] R-AUTO-MULTI-ATTACH — merge pending into partial _resume so blocks see both attachment_url and attachments[].
				$resume = array_merge( $pending, $existing_resume );
				if ( ! empty( $pending['attachments'] ) || ! empty( $existing_resume['attachments'] ) ) {
					$resume['attachments'] = array_values( array_merge( (array) ( $pending['attachments'] ?? array() ), (array) ( $existing_resume['attachments'] ?? array() ) ) );
				}
				if ( empty( $resume['attachment_url'] ) && ! empty( $pending['attachment_url'] ) ) {
					$resume['attachment_url'] = (string) $pending['attachment_url'];
				}
				if ( empty( $resume['attachment_url'] ) && $incoming_media !== '' ) {
					$resume['attachment_url'] = $incoming_media;
				}
				$ctx['trigger']['_resume'] = $resume;
			}
		}
		$kind_alias = array(); // kind => nodeId most recent
		$skipped    = array(); // nodeId => true (downstream of logic-false branch)
		$step       = 0;
		$last_error = null;
		$executed_in_session = 0;

		foreach ( $order as $node_id ) {
			$node = $nodes_by_id[ $node_id ];
			$step++;

			if ( isset( $skipped[ $node_id ] ) ) {
				$log_id = BizCity_Automation_Repo_Runs::append_log( array(
					'run_id'     => $run_id,
					'node_id'    => $node_id,
					'block_id'   => (string) ( $node['data']['blockId'] ?? '' ),
					'step'       => $step,
					'status'     => self::LOG_STATUS_SKIP,
					'input_json' => wp_json_encode( array( 'reason_code' => 'condition_false' ) ),
					'started_at' => current_time( 'mysql' ),
					'ended_at'   => current_time( 'mysql' ),
				) );
				do_action( 'bizcity_automation_log_appended', $run_id, $log_id );
				continue;
			}

			$block_id = (string) ( $node['data']['blockId'] ?? '' );
			$block    = $registry->get( $block_id );

			// Skip nodes already executed in a prior session (resume idempotency).
			if ( isset( $completed_nodes[ $node_id ] ) ) {
				// Re-hydrate ctx from the prior log so downstream resolves correctly.
				$prior_logs = BizCity_Automation_Repo_Runs::logs( $run_id );
				foreach ( $prior_logs as $log ) {
					if ( $log['node_id'] === $node_id && (int) $log['status'] === self::LOG_STATUS_OK ) {
						$out = is_array( $log['output'] ?? null ) ? $log['output'] : array();
						$ctx[ $node_id ] = $out;
						if ( $block ) {
							$kind = $block->kind();
							$kind_alias[ $kind ] = $node_id;
							$ctx[ $kind ] = $out;
						}
						break;
					}
				}
				continue;
			}

			// PG-S5: pause checks BEFORE block execution.
			// Re-read debug_state each iteration so async /pause is observed.
			$cur_debug = (string) BizCity_Automation_Repo_Runs::find( $run_id )['debug_state'];
			$bp_before = ! empty( $breakpoints[ $node_id ]['before'] );
			$do_pause  = false;

			if ( $cur_debug === 'pausing' ) {
				$do_pause = true;
			} elseif ( $cur_debug === 'stepping' && $executed_in_session >= 1 ) {
				$do_pause = true;
			} elseif ( $bp_before && $skip_break_once_for !== $node_id ) {
				$do_pause = true;
			}

			if ( $do_pause ) {
				BizCity_Automation_Repo_Runs::set_debug_state( $run_id, 'paused_before:' . $node_id );
				do_action( 'bizcity_automation_run_paused', $run_id, $node_id );
				return array( 'status' => 'paused', 'paused_at' => $node_id, 'steps' => $step - 1 );
			}

			// Once we've moved past the resume-from node, drop the skip.
			$skip_break_once_for = '';
			$data = $node['data'] ?? array();
			$side_effect = array();
			if ( $this->is_hil_side_effect_block( $block_id ) && class_exists( 'BizCity_Automation_Side_Effect_Contract' ) ) {
				// [2026-08-16 Johnny Chu] MPR-V5-IDEMPOTENCY — calculate the provider key before the RUNNING log is persisted.
				$side_effect_data = is_array( $data ) ? $data : array();
				if ( empty( $side_effect_data['side_effect_key'] ) ) {
					$side_effect_data['side_effect_key'] = $block_id;
				}
				$side_effect_data['blog_id'] = (int) get_current_blog_id();
				$side_effect_data['resource_id'] = (string) ( $side_effect_data['resource_id'] ?? (int) $wf['id'] );
				$side_effect = BizCity_Automation_Side_Effect_Contract::context( $run_id, $node_id, $side_effect_data );
				$ctx['_side_effect'] = $side_effect;
				$data['_side_effect'] = $side_effect;
				$data['idempotency_key'] = (string) ( $side_effect['idempotency_key'] ?? '' );
				if ( $is_resume && ! empty( $side_effect['idempotency_key'] ) ) {
					$unknown_log = $this->find_unresolved_side_effect_log( $run_id, $node_id, (string) $side_effect['idempotency_key'] );
					if ( $unknown_log ) {
						// [2026-08-16 Johnny Chu] MPR-V5-IDEMPOTENCY — crash ambiguity is terminal for this run until provider reconciliation.
						$err = new WP_Error( 'external_side_effect_unknown', 'External side effect chưa xác minh; cần đối soát trước khi thử lại.', array( 'status' => 409, 'side_effect_status' => 'unknown' ) );
						BizCity_Automation_Repo_Runs::append_log_update( (int) $unknown_log['id'], array(
							'status'      => self::LOG_STATUS_FAIL,
							'error'       => 'external_side_effect_unknown',
							'output_json' => wp_json_encode( array( 'side_effect_status' => 'unknown', 'idempotency_key' => $side_effect['idempotency_key'] ) ),
							'ended_at'    => current_time( 'mysql' ),
						), $run_id );
						$this->finish_failed( $run_id, $err, 'external_side_effect_unknown' );
						return $err;
					}
				}
			}

			$start_log = current_time( 'mysql' );
			$log_id = BizCity_Automation_Repo_Runs::append_log( array(
				'run_id'     => $run_id,
				'node_id'    => $node_id,
				'block_id'   => $block_id,
				'step'       => $step,
				'status'     => self::LOG_STATUS_RUNNING,
				'input_json' => wp_json_encode( $data ),
				'started_at' => $start_log,
			) );
			do_action( 'bizcity_automation_log_appended', $run_id, $log_id );

			if ( ! $block ) {
				$err = new WP_Error( 'unknown_block', 'Block chưa register: ' . $block_id );
				$this->update_log_failed( $run_id, $log_id, $err );
				$last_error = $err;
				$this->finish_failed( $run_id, $err, 'unknown_block' );
				return $err;
			}

			if ( ! empty( $ctx['_hil_spec'] ) && $this->is_hil_side_effect_block( $block_id ) && empty( $ctx['_hil_ready'] ) ) {
				$err = new WP_Error( 'hil_not_ready', 'HIL chưa thu đủ thông tin hoặc chưa được xác nhận trước side effect.' );
				$this->update_log_failed( $run_id, $log_id, $err );
				$this->finish_failed( $run_id, $err, 'hil_not_ready' );
				return $err;
			}

			try {
				$output = $block->execute( $ctx, $data );
			} catch ( \Throwable $t ) {
				$output = new WP_Error( 'block_exception', $t->getMessage(), array( 'trace' => $t->getTraceAsString() ) );
			}

			if ( is_wp_error( $output ) ) {
				$side_effect_outcome = $this->side_effect_outcome( $block_id, $ctx, $output );
				$this->update_log_failed( $run_id, $log_id, $output, $side_effect_outcome );
				$last_error = $output;
				$reason = ( $side_effect_outcome['status'] ?? '' ) === 'unknown'
					? 'external_side_effect_unknown'
					: $this->reason_bucket( $output );
				$this->finish_failed( $run_id, $output, $reason );
				return $output;
			}

			$out = is_array( $output ) ? $output : array( 'value' => $output );
			$side_effect_outcome = $this->side_effect_outcome( $block_id, $ctx, $out );
			if ( $side_effect_outcome && in_array( (string) ( $side_effect_outcome['status'] ?? '' ), array( 'unknown', 'failed' ), true ) ) {
				// [2026-08-17 Johnny Chu] MPR-V5-GATE5 — ambiguous provider outcomes stop the run and require reconciliation.
				$out['side_effect_status'] = $side_effect_outcome['status'];
				$out['side_effect_reason']  = $side_effect_outcome['reason_code'];
				$err_code = $side_effect_outcome['status'] === 'unknown' ? 'external_side_effect_unknown' : 'external_side_effect_failed';
				$err = new WP_Error( $err_code, 'External side effect cần đối soát trước khi tiếp tục.', array( 'side_effect_status' => $side_effect_outcome['status'] ) );
				$this->update_log_failed( $run_id, $log_id, $err, $side_effect_outcome );
				$this->finish_failed( $run_id, $err, $err_code );
				return $err;
			}
			if ( $side_effect_outcome ) {
				$out['side_effect_status']  = $side_effect_outcome['status'];
				$out['side_effect_reason']  = $side_effect_outcome['reason_code'];
				$out['provider_request_id'] = $side_effect_outcome['provider_request_id'];
			}

			BizCity_Automation_Repo_Runs::append_log_update( $log_id, array(
				'status'      => self::LOG_STATUS_OK,
				'output_json' => wp_json_encode( $out ),
				'ended_at'    => current_time( 'mysql' ),
			), $run_id );
			do_action( 'bizcity_automation_log_appended', $run_id, $log_id );

			// Store in ctx by node id + kind alias.
			$ctx[ $node_id ] = $out;
			$kind = $block->kind();
			$kind_alias[ $kind ] = $node_id;
			$ctx[ $kind ] = $out;
			$executed_in_session++;

			// Branch skipping for logic.condition: out.branch ∈ {true|false}.
			if ( $kind === 'condition' && isset( $out['branch'] ) ) {
				$branch    = (string) $out['branch'];
				$kept      = array(); // edges to follow
				$discarded = array();
				// [2026-07-03 Johnny Chu] GAP-BRANCH-P0-3 — when explicit true/false branch edges
				// exist, ignore any stale 'out' edges to prevent both branches firing simultaneously.
				$has_explicit_branch = false;
				foreach ( $succ[ $node_id ] ?? array() as $s ) {
					if ( $s['handle'] === 'true' || $s['handle'] === 'false' ) {
						$has_explicit_branch = true;
						break;
					}
				}
				foreach ( $succ[ $node_id ] ?? array() as $s ) {
					$qualifies_out = ( ! $has_explicit_branch && $s['handle'] === 'out' );
					if ( $s['handle'] === $branch || $qualifies_out ) {
						$kept[] = $s['target'];
					} else {
						$discarded[] = $s['target'];
					}
				}
				if ( ! $kept && $succ[ $node_id ] ?? null ) {
					// No handle matched (eg user wired single 'out') → keep all.
				}
				foreach ( $discarded as $start_id ) {
					$this->mark_subtree( $start_id, $succ, $skipped );
				}
			}
		}

		$result = array( 'ctx_keys' => array_keys( $ctx ), 'steps' => $step );
		BizCity_Automation_Repo_Runs::set_status( $run_id, BizCity_Automation_Repo_Runs::STATUS_OK, array(
			'result_json' => wp_json_encode( $result ),
			'ended_at'    => current_time( 'mysql' ),
		) );
		BizCity_Automation_Repo_Runs::set_debug_state( $run_id, '' );
		do_action( 'bizcity_automation_run_ended', $run_id, true, $ctx );
		// [2026-06-03 Johnny Chu] SCH-NC W5 — pass ctx so emit_crm_bridge forwards inbound.
		$this->emit_crm_bridge( $run_id, $wf, true, $result, $ctx );

		return array( 'status' => 'ok', 'ctx' => $ctx, 'steps' => $step );
		} finally {
			if ( $correlation_context_active ) {
				if ( class_exists( 'BizCity_Chat_Correlation' ) ) {
					BizCity_Chat_Correlation::release_pending_root( $async_trace_id );
				}
				if ( $had_outbound_trace_ctx ) {
					$GLOBALS['_bizcity_outbound_trace_ctx'] = $previous_outbound_trace_ctx;
				} else {
					unset( $GLOBALS['_bizcity_outbound_trace_ctx'] );
				}
			}
		}
	}

	// ─── Internals ────────────────────────────────────────────────────────

	private function is_hil_side_effect_block( string $block_id ): bool {
		// [2026-08-16 Johnny Chu] MPR-V5-HIL-RUNTIME — conservative side-effect denylist for manual/cron bypass protection.
		return in_array( $block_id, array(
			'action.reply_zalo', 'action.reply_zalo_each_day', 'action.reply_fb_message', 'action.reply_telegram',
			'action.send_email', 'action.notify_discord', 'action.http_request', 'action.db_write',
			'action.create_crm_event', 'action.create_woo_order', 'action.publish_fb_post',
			'action.publish_wp_post', 'action.schedule_event', 'action.video_submit',
		), true );
	}

	private function find_unresolved_side_effect_log( string $run_id, string $node_id, string $idempotency_key ): array {
		// [2026-08-16 Johnny Chu] MPR-V5-IDEMPOTENCY — inspect existing run logs before any provider call on resume.
		foreach ( BizCity_Automation_Repo_Runs::logs( $run_id ) as $log ) {
			if ( (string) ( $log['node_id'] ?? '' ) !== $node_id || (int) ( $log['status'] ?? -1 ) !== self::LOG_STATUS_RUNNING ) {
				continue;
			}
			$input = is_array( $log['input'] ?? null ) ? $log['input'] : array();
			$logged_key = (string) ( $input['idempotency_key'] ?? ( $input['_side_effect']['idempotency_key'] ?? '' ) );
			if ( $logged_key === '' || $logged_key === $idempotency_key ) {
				return $log;
			}
		}
		return array();
	}

	/**
	 * Kahn topological sort.
	 *
	 * @param array $nodes
	 * @param array $edges
	 * @return array<int,string>|WP_Error  Sorted node ids on success.
	 */
	private function topo_sort( array $nodes, array $edges ) {
		$in_degree = array();
		$adj       = array();
		foreach ( $nodes as $n ) {
			$in_degree[ $n['id'] ] = 0;
			$adj[ $n['id'] ]       = array();
		}
		foreach ( $edges as $e ) {
			$src = $e['source'] ?? ''; $tgt = $e['target'] ?? '';
			if ( $src === '' || $tgt === '' ) { continue; }
			if ( ! isset( $in_degree[ $tgt ], $adj[ $src ] ) ) { continue; }
			$adj[ $src ][] = $tgt;
			$in_degree[ $tgt ]++;
		}
		$queue = array();
		foreach ( $in_degree as $id => $deg ) {
			if ( $deg === 0 ) { $queue[] = $id; }
		}
		$order = array();
		while ( $queue ) {
			$id = array_shift( $queue );
			$order[] = $id;
			foreach ( $adj[ $id ] as $next ) {
				if ( --$in_degree[ $next ] === 0 ) {
					$queue[] = $next;
				}
			}
		}
		if ( count( $order ) !== count( $nodes ) ) {
			return new WP_Error( 'graph_cycle', 'Workflow chứa cycle hoặc node lạc — Kahn không hoàn tất.', array(
				'expected' => count( $nodes ),
				'sorted'   => count( $order ),
			) );
		}
		return $order;
	}

	private function mark_subtree( string $start, array $succ, array &$skipped ): void {
		$stack = array( $start );
		while ( $stack ) {
			$id = array_pop( $stack );
			if ( isset( $skipped[ $id ] ) ) { continue; }
			$skipped[ $id ] = true;
			foreach ( $succ[ $id ] ?? array() as $s ) {
				$stack[] = $s['target'];
			}
		}
	}

	private function update_log_failed( string $run_id, int $log_id, $err, array $outcome = array() ): void {
		// [2026-08-27 Johnny Chu] PHASE-1.30-LIFECYCLE — include run context so repository fallback can update the same synthetic log row in JSONL mode.
		$msg = is_wp_error( $err ) ? $err->get_error_message() : (string) $err;
		$patch = array(
			'status'   => self::LOG_STATUS_FAIL,
			'error'    => substr( $msg, 0, 500 ),
			'ended_at' => current_time( 'mysql' ),
		);
		if ( ! empty( $outcome ) ) {
			$patch['output_json'] = wp_json_encode( array(
				'side_effect_status'  => (string) ( $outcome['status'] ?? '' ),
				'side_effect_reason'  => (string) ( $outcome['reason_code'] ?? '' ),
				'provider_request_id' => (string) ( $outcome['provider_request_id'] ?? '' ),
			) );
		}
		BizCity_Automation_Repo_Runs::append_log_update( $log_id, $patch, $run_id );
	}

	private function side_effect_outcome( string $block_id, array $ctx, $result ): array {
		if ( ! class_exists( 'BizCity_Automation_Side_Effect_Contract' ) || empty( $ctx['_side_effect']['known'] ) ) {
			return array();
		}
		if ( ! in_array( $block_id, array( 'action.http_request', 'action.reply_zalo' ), true )
			&& ( ! is_array( $result ) || ! array_key_exists( 'side_effect_status', $result ) ) ) {
			return array();
		}
		return BizCity_Automation_Side_Effect_Contract::provider_result( $result );
	}

	private function finish_failed( string $run_id, $err, string $reason_bucket ): void {
		$msg = is_wp_error( $err ) ? $err->get_error_message() : (string) $err;
		BizCity_Automation_Repo_Runs::set_status( $run_id, BizCity_Automation_Repo_Runs::STATUS_FAIL, array(
			'error'    => substr( $msg, 0, 500 ),
			'ended_at' => current_time( 'mysql' ),
		) );
		$run = BizCity_Automation_Repo_Runs::find( $run_id );
		$wf  = BizCity_Automation_Repo_Workflows::find( (int) ( $run['workflow_id'] ?? 0 ) );
		do_action( 'bizcity_automation_run_ended', $run_id, false, array( 'error' => $msg ) );
		// [2026-06-03 Johnny Chu] SCH-NC W5 — synth ctx.trigger từ run's trigger_payload
		// để emit_crm_bridge forward inbound block xuống Bridge.
		$failed_trigger = is_array( $run ) && isset( $run['trigger_payload'] ) && is_array( $run['trigger_payload'] )
			? $run['trigger_payload']
			: array();
		// [2026-07-16 Johnny Chu] PHASE-TWINWEB F4 — keep owner continuity on failed path for scheduler completion + reply routing.
		$ctx_failed = array(
			'trigger'        => $failed_trigger,
			'_owner_user_id' => (int) ( $run['user_id'] ?? $failed_trigger['_owner_user_id'] ?? $failed_trigger['wp_user_id'] ?? ( $wf['created_by'] ?? 0 ) ),
		);
		$this->emit_crm_bridge( $run_id, $wf, false, array( 'error' => $msg, 'reason' => $reason_bucket ), $ctx_failed );

		// R-CRON-META — when called in cron context, the dispatcher handles
		// note_event. For inline runs there's nothing to note.
	}

	private function emit_crm_bridge( string $run_id, $wf, bool $ok, array $result, array $ctx = array() ): void {
		if ( ! $wf ) { return; }
		// [2026-07-16 Johnny Chu] PHASE-TWINWEB F4 — explicit owner propagation from run context to scheduler row.
		$owner_user_id = (int) ( $ctx['_owner_user_id'] ?? $ctx['trigger']['_owner_user_id'] ?? $ctx['trigger']['wp_user_id'] ?? ( $wf['created_by'] ?? 0 ) );
		if ( $owner_user_id <= 0 && class_exists( 'BizCity_User_Resolver' ) ) {
			// [2026-08-13 Johnny Chu] HOTFIX-ZALO-OWNER-FAILURE — recover the linked Zalo owner from inbound identity on failed async runs; never use current user.
			$trigger = is_array( $ctx['trigger'] ?? null ) ? $ctx['trigger'] : array();
			$identity_chat_id = (string) ( $trigger['identity_chat_id'] ?? '' );
			if ( $identity_chat_id === '' ) {
				$platform = strtolower( (string) ( $trigger['platform'] ?? $trigger['channel'] ?? '' ) );
				$bot_id = (string) ( $trigger['bot_id'] ?? $trigger['account_id'] ?? '' );
				$user_id = (string) ( $trigger['user_id'] ?? $trigger['sender_id'] ?? '' );
				// [2026-09-01 Johnny Chu] R-CRM-CHANNEL-CONTRACT - owner recovery never treats OA/Personal as Bot automation.
				if ( in_array( strtoupper( $platform ), array( 'ZALO_BOT', 'ZALO' ), true ) && $bot_id !== '' && $user_id !== '' ) {
					$identity_chat_id = 'zalobot_' . $bot_id . '_' . $user_id;
				}
			}
			if ( $identity_chat_id !== '' ) {
				$owner_user_id = (int) BizCity_User_Resolver::instance()->resolve( $identity_chat_id );
			}
		}
		if ( $owner_user_id <= 0 ) {
			error_log( '[automation] emit_crm_bridge refused: owner_user_id missing for run ' . $run_id );
			return;
		}
		// [2026-06-03 Johnny Chu] SCH-NC W5 — forward canonical inbound block từ
		// trigger payload xuống Bridge → Manager. Block đã được matcher inject
		// vào ctx.trigger.inbound (xem class-automation-trigger-matcher.php W5).
		$metadata = array(
			'automation_kind' => 'run_summary',
			'workflow_slug'   => (string) ( $wf['slug'] ?? '' ),
			'owner_user_id'   => $owner_user_id,
			'ok'              => $ok,
		);
		$inbound = isset( $ctx['trigger']['inbound'] ) ? $ctx['trigger']['inbound'] : null;
		if ( is_array( $inbound )
			&& class_exists( 'BizCity_Scheduler_Inbound_Provenance' )
			&& BizCity_Scheduler_Inbound_Provenance::is_valid( $inbound ) ) {
			$metadata['inbound'] = $inbound;
		}
		$payload = array(
			'user_id'     => $owner_user_id,
			'event_type'  => 'task', // 'automation_run' is not in scheduler whitelist
			'title'       => sprintf( '[%s] %s', $wf['name'] ?? $wf['slug'] ?? 'workflow', $ok ? 'OK' : 'FAIL' ),
			'description' => wp_json_encode( $result ),
			'related_id'  => $run_id,
			'workflow_id' => (int) ( $wf['id'] ?? 0 ),
			'status'      => $ok ? 'done' : 'cancelled',
			'source'      => 'workflow',
			'start_at'    => current_time( 'mysql' ),
			'metadata'    => $metadata,
		);
		$event_id = BizCity_Automation_CRM_Bridge::create_event( $payload );
		if ( $event_id > 0 ) {
			BizCity_Automation_Repo_Runs::set_status( $run_id, $ok
				? BizCity_Automation_Repo_Runs::STATUS_OK
				: BizCity_Automation_Repo_Runs::STATUS_FAIL,
				array( 'crm_event_id' => $event_id )
			);
		}
	}

	private function reason_bucket( WP_Error $err ): string {
		// [2026-07-25 Johnny Chu] PHASE-0.46 W2 — normalize notebook-capture
		// WP_Error codes to shared reason buckets so runtime dashboards group
		// action.capture_to_notebook failures consistently.
		$code = (string) $err->get_error_code();
		switch ( $code ) {
			case 'block_exception':         return 'block_error';
			case 'unknown_block':           return 'validation_failed';
			case 'invalid_url':
			case 'blocked_private_host':
			case 'invalid_method':          return 'invalid_param';
			case 'http_error':              return 'http_error';
			case 'llm_unavailable':         return 'provider_unavailable';
			case 'notebook_bridge_unavailable':
				return 'bridge_unavailable';
			case 'no_chat_id':
				return 'chat_id_missing';
			case 'invalid_channel':
				return 'channel_unresolved';
			case 'notebook_bridge_invalid_identity':
				return 'owner_user_missing';
			case 'notebook_bridge_empty_batch':
				return 'empty_payload';
			case 'notebook_bridge_capture_all_failed':
				return 'all_items_failed';
			case 'notebook_bridge_no_attachment':
			case 'notebook_bridge_empty_url':
			case 'notebook_bridge_download_failed':
			case 'notebook_bridge_sideload_failed':
				return 'capture_failed';
			default:
				if ( $code !== '' && strpos( $code, 'notebook_bridge_' ) === 0 ) {
					return 'capture_failed';
				}
				return $code !== '' ? $code : 'block_error';
		}
	}

	// ─── Cron dispatcher ──────────────────────────────────────────────────

	/**
	 * Picks up to N queued runs and executes them under cron context.
	 * Called by hook BizCity_Automation_Runner::CRON_HOOK.
	 */
	public function on_cron_dispatch(): void {
		// [2026-08-20 Johnny Chu] R-CLI-ASYNC-ISOLATION — direct cron runner
		// calls must not query, claim, or execute production runs in diagnostics.
		if ( defined( 'BIZCITY_DIAGNOSTICS_CLI' ) && BIZCITY_DIAGNOSTICS_CLI ) {
			return;
		}
		global $wpdb;

		$cron = class_exists( 'BizCity_Cron_Manager' ) ? BizCity_Cron_Manager::instance() : null;

		// [2026-07-26 Johnny Chu] CRON-LOCK-PHASE-A — bail immediately if another
		// in-flight tick already holds the lock for this job (duplicate concurrent
		// fire from WP pseudo-cron). See TRACE-CRON-OVERLOAD-2026-07-26.md.
		if ( $cron && $cron->is_locked_out( self::CRON_JOB_ID ) ) {
			return;
		}

		// [2026-06-21 Johnny Chu] HOTFIX — replaces stale-stamp-only guard (missed blogs where
		// stamp=='1.7.0' but tables were never created, e.g. cloned/new multisite sub-sites).
		// tables_present_cached() does ONE SHOW TABLES per blog per request regardless of stamp.
		if ( class_exists( 'BizCity_Automation_Installer' ) && ! BizCity_Automation_Installer::tables_present_cached() ) {
			BizCity_Automation_Installer::ensure(); // attempt provisioning; next tick will run
			return;
		}

		$tbl = BizCity_Automation_Repo_Runs::table_runs();
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT run_id FROM {$tbl} WHERE status = %d ORDER BY id ASC LIMIT %d",
			BizCity_Automation_Repo_Runs::STATUS_QUEUED,
			self::CRON_BATCH_MAX
		) );

		$counters   = array( 'runs_picked' => count( (array) $ids ), 'runs_done' => 0, 'runs_failed' => 0, 'runs_deferred' => 0 );
		$tick_start = microtime( true );

		foreach ( (array) $ids as $run_id ) {
			if ( ! is_string( $run_id ) || $run_id === '' ) { continue; } // guard: null run_id in DB

			// [2026-07-26 Johnny Chu] CRON-OVERLOAD-PHASE-B — self-limit tick duration;
			// leave any remaining queued runs (still status=QUEUED) for the next tick
			// instead of risking a run past the 60s interval.
			if ( ( microtime( true ) - $tick_start ) > self::CRON_TIME_BUDGET_SECONDS ) {
				$counters['runs_deferred'] += ( count( (array) $ids ) - $counters['runs_done'] - $counters['runs_failed'] );
				break;
			}

			$res = $this->execute( $run_id );
			if ( is_wp_error( $res ) ) {
				$counters['runs_failed']++;
				if ( $cron ) {
					$cron->note_event( 'automation_run_failed', array(
						'run_id' => $run_id,
						'reason' => $this->reason_bucket( $res ),
						'error'  => $res->get_error_message(),
					) );
				}
			} else {
				$counters['runs_done']++;
			}
		}

		if ( $cron ) {
			$logs_deleted = BizCity_Automation_Repo_Runs::gc_logs();
			$cron->note( array( 'counters' => array( 'automation_logs_retention_deleted' => $logs_deleted ) ) );
			$cron->note_event( 'automation_logs_retention', array(
				'deleted' => $logs_deleted,
				'retention_days' => BizCity_Automation_Repo_Runs::LOG_RETENTION_DAYS,
			) );
			$cron->note( array( 'counters' => $counters ) );
		}
	}
}
