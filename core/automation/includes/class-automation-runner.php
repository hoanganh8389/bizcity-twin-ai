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

	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) { self::$instance = new self(); }
		return self::$instance;
	}

	private function __construct() {}

	/** Convenience: enqueue + execute right away. */
	public function run_now( int $workflow_id, $payload = null ) {
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
			// fresh start — fall through.
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

		// Mark running (skip if resuming — already RUNNING).
		if ( ! $is_resume ) {
			BizCity_Automation_Repo_Runs::set_status( $run_id, BizCity_Automation_Repo_Runs::STATUS_RUNNING, array(
				'started_at' => current_time( 'mysql' ),
			) );
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
			'trigger'      => $run['trigger_payload'] ?? array(),
			'_run_id'      => $run_id,
			'_workflow_id' => (int) $wf['id'],
			'_meta'        => array(
				'workflow_slug' => $wf['slug'],
				'workflow_name' => $wf['name'],
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
		//   1. Nếu turn này có media_url, patch pending.attachment_url.
		//   2. Inject pending vào trigger._resume nếu chưa có.
		$trigger_chat_id = (string) ( $ctx['trigger']['chat_id'] ?? '' );
		if (
			$trigger_chat_id !== ''
			&& empty( $ctx['trigger']['_resume'] )
			&& class_exists( 'BizCity_Automation_Pending_State' )
		) {
			$pending = BizCity_Automation_Pending_State::get( $trigger_chat_id );
			$incoming_media = (string) ( $ctx['trigger']['media_url'] ?? '' );
			if (
				! empty( $pending )
				&& empty( $pending['attachment_url'] )
				&& $incoming_media !== ''
			) {
				BizCity_Automation_Pending_State::patch( $trigger_chat_id, array(
					'attachment_url' => $incoming_media,
				) );
				$pending['attachment_url'] = $incoming_media;
			}
			if ( ! empty( $pending ) ) {
				$ctx['trigger']['_resume'] = $pending;
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

			$start_log = current_time( 'mysql' );
			$log_id = BizCity_Automation_Repo_Runs::append_log( array(
				'run_id'     => $run_id,
				'node_id'    => $node_id,
				'block_id'   => $block_id,
				'step'       => $step,
				'status'     => self::LOG_STATUS_RUNNING,
				'input_json' => wp_json_encode( $node['data'] ?? array() ),
				'started_at' => $start_log,
			) );
			do_action( 'bizcity_automation_log_appended', $run_id, $log_id );

			if ( ! $block ) {
				$err = new WP_Error( 'unknown_block', 'Block chưa register: ' . $block_id );
				$this->update_log_failed( $log_id, $err );
				$last_error = $err;
				$this->finish_failed( $run_id, $err, 'unknown_block' );
				return $err;
			}

			$data = $node['data'] ?? array();
			try {
				$output = $block->execute( $ctx, $data );
			} catch ( \Throwable $t ) {
				$output = new WP_Error( 'block_exception', $t->getMessage(), array( 'trace' => $t->getTraceAsString() ) );
			}

			if ( is_wp_error( $output ) ) {
				$this->update_log_failed( $log_id, $output );
				$last_error = $output;
				$this->finish_failed( $run_id, $output, $this->reason_bucket( $output ) );
				return $output;
			}

			$out = is_array( $output ) ? $output : array( 'value' => $output );

			BizCity_Automation_Repo_Runs::append_log_update( $log_id, array(
				'status'      => self::LOG_STATUS_OK,
				'output_json' => wp_json_encode( $out ),
				'ended_at'    => current_time( 'mysql' ),
			) );
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
				foreach ( $succ[ $node_id ] ?? array() as $s ) {
					if ( $s['handle'] === $branch || $s['handle'] === 'out' ) {
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
		$this->emit_crm_bridge( $run_id, $wf, true, $result );

		return array( 'status' => 'ok', 'ctx' => $ctx, 'steps' => $step );
	}

	// ─── Internals ────────────────────────────────────────────────────────

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

	private function update_log_failed( int $log_id, $err ): void {
		$msg = is_wp_error( $err ) ? $err->get_error_message() : (string) $err;
		BizCity_Automation_Repo_Runs::append_log_update( $log_id, array(
			'status'   => self::LOG_STATUS_FAIL,
			'error'    => substr( $msg, 0, 500 ),
			'ended_at' => current_time( 'mysql' ),
		) );
	}

	private function finish_failed( string $run_id, $err, string $reason_bucket ): void {
		$msg = is_wp_error( $err ) ? $err->get_error_message() : (string) $err;
		BizCity_Automation_Repo_Runs::set_status( $run_id, BizCity_Automation_Repo_Runs::STATUS_FAIL, array(
			'error'    => substr( $msg, 0, 500 ),
			'ended_at' => current_time( 'mysql' ),
		) );
		$wf = BizCity_Automation_Repo_Workflows::find( (int) ( BizCity_Automation_Repo_Runs::find( $run_id )['workflow_id'] ?? 0 ) );
		do_action( 'bizcity_automation_run_ended', $run_id, false, array( 'error' => $msg ) );
		$this->emit_crm_bridge( $run_id, $wf, false, array( 'error' => $msg, 'reason' => $reason_bucket ) );

		// R-CRON-META — when called in cron context, the dispatcher handles
		// note_event. For inline runs there's nothing to note.
	}

	private function emit_crm_bridge( string $run_id, $wf, bool $ok, array $result ): void {
		if ( ! $wf ) { return; }
		$payload = array(
			'event_type'  => 'task', // 'automation_run' is not in scheduler whitelist
			'title'       => sprintf( '[%s] %s', $wf['name'] ?? $wf['slug'] ?? 'workflow', $ok ? 'OK' : 'FAIL' ),
			'description' => wp_json_encode( $result ),
			'related_id'  => $run_id,
			'workflow_id' => (int) ( $wf['id'] ?? 0 ),
			'status'      => $ok ? 'done' : 'cancelled',
			'source'      => 'workflow',
			'start_at'    => current_time( 'mysql' ),
			'metadata'    => array(
				'automation_kind' => 'run_summary',
				'workflow_slug'   => (string) ( $wf['slug'] ?? '' ),
				'ok'              => $ok,
			),
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
		$code = $err->get_error_code();
		switch ( $code ) {
			case 'block_exception':         return 'block_error';
			case 'unknown_block':           return 'validation_failed';
			case 'invalid_url':
			case 'blocked_private_host':
			case 'invalid_method':          return 'invalid_param';
			case 'http_error':              return 'http_error';
			case 'llm_unavailable':         return 'provider_unavailable';
			default:                        return $code ?: 'block_error';
		}
	}

	// ─── Cron dispatcher ──────────────────────────────────────────────────

	/**
	 * Picks up to N queued runs and executes them under cron context.
	 * Called by hook BizCity_Automation_Runner::CRON_HOOK.
	 */
	public function on_cron_dispatch(): void {
		global $wpdb;

		// Guard: tables may be missing on multisite subsites cloned without bizcity_*
		// tables (MUCD skips them). Use the same version-stamp logic as the installer
		// so we only run the heavyweight SHOW TABLES when the stamp is absent/stale —
		// not on every cron tick. get_option() is served from WP object cache → free.
		if ( class_exists( 'BizCity_Automation_Installer' ) ) {
			$stamped = get_option( BizCity_Automation_Installer::DB_VERSION_OPTION, '' );
			if ( $stamped !== BizCity_Automation_Installer::DB_VERSION ) {
				$tbl_check = BizCity_Automation_Repo_Runs::table_runs();
				if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl_check ) ) !== $tbl_check ) {
					delete_option( BizCity_Automation_Installer::DB_VERSION_OPTION );
					BizCity_Automation_Installer::ensure_admin_init();
					return;
				}
				// Tables present but stamp was stale — let installer stamp it now.
				BizCity_Automation_Installer::ensure_admin_init();
			}
		}

		$tbl = BizCity_Automation_Repo_Runs::table_runs();
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT run_id FROM {$tbl} WHERE status = %d ORDER BY id ASC LIMIT %d",
			BizCity_Automation_Repo_Runs::STATUS_QUEUED,
			self::CRON_BATCH_MAX
		) );

		$cron     = class_exists( 'BizCity_Cron_Manager' ) ? BizCity_Cron_Manager::instance() : null;
		$counters = array( 'runs_picked' => count( (array) $ids ), 'runs_done' => 0, 'runs_failed' => 0 );

		foreach ( (array) $ids as $run_id ) {
			if ( ! is_string( $run_id ) || $run_id === '' ) { continue; } // guard: null run_id in DB
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
			$cron->note( array( 'counters' => $counters ) );
		}
	}
}
