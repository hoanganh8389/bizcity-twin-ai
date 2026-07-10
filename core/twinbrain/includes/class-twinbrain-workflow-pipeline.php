<?php
/**
 * BizCity_TwinBrain_Workflow_Pipeline
 *
 * Generic brain-mode pipeline that executes a user-defined automation workflow
 * (from bizcity_automation_workflows) as a step-by-step "thinking pipeline"
 * analogous to the built-in Astro and Deep engines.
 *
 * Entry point: BizCity_TwinBrain_Workflow_Pipeline::instance()->run(...)
 *
 * SSE event contract (TAXONOMY_VERSION 7):
 *   workflow_started   — pipeline begins; FE renders N skeleton rows.
 *   workflow_step      — one row update per node (status: running → done|error|skipped|timeout).
 *   workflow_completed — final summary (artifacts_count, total_ms, tokens, error).
 *
 * Design mirrors class-twinbrain-web-astro.php (single-instance, run() API).
 *
 * @package BizCity_TwinBrain
 * @since   1.0.0 [2026-06-19 Johnny Chu] PHASE-TWB-WORKFLOW W1
 *
 * Cache contract: no persistent caching — this is a live streaming pipeline.
 */

// Direct file access guard.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class BizCity_TwinBrain_Workflow_Pipeline {

	// ── Hard caps ────────────────────────────────────────────────────────────
	// [2026-06-19 Johnny Chu] PHASE-TWB-WORKFLOW W1

	/** Maximum nodes executed per /skill run (prevents abuse of huge graphs). */
	const MAX_NODES_PER_RUN = 12;

	/** Wall-clock budget in seconds for the entire pipeline. */
	const TOTAL_BUDGET_S = 45;

	/** Per-node timeout in seconds. */
	const NODE_TIMEOUT_S = 12;

	/** Maximum artifacts in pool (prevents context explosion). */
	const ARTIFACT_CAP = 32;

	// ── Singleton ────────────────────────────────────────────────────────────

	/** @var self|null */
	private static $instance = null;

	/** @var callable|null Injected by REST handler to emit SSE frames directly. */
	private $sse_emitter = null;

	private function __construct() {}

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	// ── Public API ───────────────────────────────────────────────────────────

	/**
	 * Set the SSE emitter callable. Must be called before run() when streaming.
	 * Callable signature: fn(string $event_name, array $payload): void
	 *
	 * [2026-06-19 Johnny Chu] PHASE-TWB-WORKFLOW W1
	 */
	public function set_sse_emitter( $callable ): void {
		$this->sse_emitter = is_callable( $callable ) ? $callable : null;
	}

	/**
	 * Run a user-defined workflow as a brain pipeline.
	 *
	 * [2026-06-19 Johnny Chu] PHASE-TWB-WORKFLOW W1
	 *
	 * @param string $trace_id Brain turn trace ID.
	 * @param string $query    User prompt that triggered /skill.
	 * @param array  $opts {
	 *   skill:      string  required — slug to look up.
	 *   user_id:    int     identity for ownership guard.
	 *   guest_sid:  string  guest session ID (if user_id=0).
	 *   session_id: string  conversation thread ID.
	 *   surface:    string  twinweb|twinchat.
	 *   history:    array   recent conversation messages.
	 *   on_token:   callable SSE token callback (for compose streaming).
	 * }
	 * @return array {
	 *   mode, trace_id, skill_slug, workflow_id,
	 *   artifacts, answer_md, tokens, ms, error,
	 * }
	 */
	public function run( string $trace_id, string $query, array $opts = array() ): array {
		// [2026-06-19 Johnny Chu] PHASE-TWB-WORKFLOW W1
		$t0      = microtime( true );
		$opts['_t0'] = $t0;
		$skill   = (string) ( $opts['skill']   ?? '' );
		$user_id = (int)    ( $opts['user_id'] ?? 0 );

		// [2026-06-20 Johnny Chu] PHASE-TWB-WORKFLOW W1-fix — reset compose state each run() call
		// (singleton is reused per-request; stale properties from a prior run would cause
		// execute_graph to skip fallback_compose on a clean second call).
		$this->_compose_done   = false;
		$this->_compose_answer = '';
		$this->_compose_tokens = 0;

		$row = array(
			'mode'        => 'workflow',
			'trace_id'    => $trace_id,
			'skill_slug'  => $skill,
			'workflow_id' => 0,
			'artifacts'   => array(),
			'answer_md'   => '',
			'tokens'      => 0,
			'ms'          => 0,
			'error'       => '',
		);

		if ( $skill === '' ) {
			$row['error'] = 'skill_empty';
			$this->emit_completed( $trace_id, $row, $t0 );
			return $row;
		}

		// Resolve workflow (3-tier: user-owned → public → built-in).
		$workflow = $this->resolve_workflow( $skill, $user_id );
		if ( ! $workflow ) {
			$row['error'] = 'skill_not_found';
			$this->emit_workflow_started( $trace_id, $skill, 0, 0, $skill, $opts );
			$this->emit_completed( $trace_id, $row, $t0 );
			return $row;
		}
		$row['workflow_id'] = (int) ( $workflow['id'] ?? 0 );

		// Parse + validate graph.
		$graph_raw = (string) ( $workflow['graph_json'] ?? '' );
		$graph     = json_decode( $graph_raw, true );
		if ( ! is_array( $graph ) || empty( $graph['nodes'] ) ) {
			$row['error'] = 'graph_invalid';
			$this->emit_workflow_started( $trace_id, $skill, $row['workflow_id'], 0, (string) ( $workflow['name'] ?? $skill ), $opts );
			$this->emit_completed( $trace_id, $row, $t0 );
			return $row;
		}

		// Auto-append terminal compose node if missing.
		$this->ensure_terminal_compose( $graph );

		$node_count = count( $graph['nodes'] );
		$label      = (string) ( $workflow['name'] ?? $skill );

		// [2026-06-20 Johnny Chu] PHASE-TWB-WORKFLOW W7 — inject workflow_id + run_id into opts
		// so build_node_ctx can forward them to block ctx (_workflow_id, _run_id).
		// llm.mpr_think reads $ctx['_workflow_id']; without this it would always be 0.
		$opts['_workflow_id'] = $row['workflow_id'];
		$opts['_run_id']      = $trace_id;

		// Emit workflow_started — FE builds skeleton.
		$this->emit_workflow_started( $trace_id, $skill, $row['workflow_id'], $node_count, $label, $opts );

		// Execute graph.
		try {
			$exec = $this->execute_graph( $graph, $query, $opts, $trace_id );
		} catch ( \Throwable $e ) {
			error_log( '[TwinBrain][workflow-pipeline] exception: ' . $e->getMessage() );
			$row['error'] = 'pipeline_exception:' . $e->getMessage();
			$this->emit_completed( $trace_id, $row, $t0 );
			return $row;
		}

		$row['artifacts'] = $exec['artifacts'];
		$row['answer_md'] = (string) ( $exec['answer_md'] ?? '' );
		$row['tokens']    = (int)    ( $exec['tokens']    ?? 0 );
		$row['ms']        = (int) round( ( microtime( true ) - $t0 ) * 1000 );

		$this->emit_completed( $trace_id, $row, $t0 );
		return $row;
	}

	/**
	 * Resolve workflow for a skill slug, scoped to caller identity.
	 * Resolution order:
	 *   1. User-owned (created_by=$user_id, enabled=1)
	 *   2. Public scope (scope='public', enabled=1) — future
	 *   3. Built-in defaults (filter 'bizcity_twinbrain_builtin_skills')
	 *
	 * [2026-06-19 Johnny Chu] PHASE-TWB-WORKFLOW W1
	 *
	 * @param string $slug    Skill slug.
	 * @param int    $user_id Caller user ID; 0 = guest.
	 * @return array|null     Workflow row array or null if not found/no permission.
	 */
	public function resolve_workflow( string $slug, int $user_id ): ?array {
		// 1. User-owned via Repo helper (preferred — uses BizCity_Cache internally).
		if ( $user_id > 0 && class_exists( 'BizCity_Automation_Repo_Workflows' ) ) {
			$found = BizCity_Automation_Repo_Workflows::query( array(
				'created_by' => $user_id,
				'enabled'    => 1,
				'search'     => $slug,
				'limit'      => 5,
			) );
			// query() returns { rows, total }; check rows for exact slug match.
			$rows = is_array( $found ) ? ( is_array( $found['rows'] ?? null ) ? $found['rows'] : $found ) : array();
			foreach ( $rows as $wf ) {
				if ( ( $wf['slug'] ?? '' ) === $slug ) {
					return $wf;
				}
			}
		}

		// 2. Built-in defaults via filter.
		$builtins = (array) apply_filters( 'bizcity_twinbrain_builtin_skills', array() );
		foreach ( $builtins as $b ) {
			if ( ( $b['slug'] ?? '' ) === $slug ) {
				return $b;
			}
		}

		return null;
	}

	// ── Graph execution ──────────────────────────────────────────────────────

	/**
	 * Walk graph in topological order, executing each node.
	 * Soft-fails on individual node errors so the terminal compose still runs.
	 *
	 * [2026-06-19 Johnny Chu] PHASE-TWB-WORKFLOW W1
	 *
	 * @return array { artifacts, answer_md, tokens }
	 */
	private function execute_graph( array $graph, string $query, array $opts, string $trace_id ): array {
		$nodes     = $this->topo_sort( $graph );
		$artifacts = array();
		$tokens    = 0;
		$idx       = 0;
		$skill     = (string) ( $opts['skill'] ?? '' );
		$total     = count( $nodes );

		foreach ( $nodes as $node ) {
			// Hard cap — never execute more than MAX_NODES_PER_RUN.
			if ( $idx >= self::MAX_NODES_PER_RUN ) {
				$this->emit_step_event( $trace_id, $skill, $node, $idx, $total, 'skipped', null, 0, 'Đã đạt giới hạn tối đa ' . self::MAX_NODES_PER_RUN . ' bước.' );
				$idx++;
				continue;
			}

			// Wall-clock budget.
			if ( ( microtime( true ) - $opts['_t0'] ) > self::TOTAL_BUDGET_S ) {
				$this->emit_step_event( $trace_id, $skill, $node, $idx, $total, 'timeout', null, 0, 'Vượt giới hạn thời gian ' . self::TOTAL_BUDGET_S . 's.' );
				$idx++;
				continue;
			}

			// ─── Step running ────────────────────────────────────────────────
			$this->emit_step_event( $trace_id, $skill, $node, $idx, $total, 'running', null, 0, null );
			$node_t0 = microtime( true );

			// ─── Extract kind (shared by all checks below) ────────────────────
			// [2026-06-19 Johnny Chu] PHASE-TWB-WORKFLOW W1 fix — handle both simplified and builder formats.
			$kind = $this->get_node_kind( $node );

			// ─── Terminal compose node — stream answer ────────────────────────
			// MUST check BEFORE resolve_block(): llm.compose / llm.compose_reply are
			// NOT registered in the automation block registry (they are brain-mode
			// special). resolve_block() would return null → early bail → compose never runs.
			// [2026-06-20 Johnny Chu] PHASE-TWB-WORKFLOW W6-BUG — moved before resolve_block.
			$is_compose = ( $kind === 'llm.compose' || $kind === 'llm.compose_reply' );
			if ( $is_compose ) {
				$ms = $this->do_compose( $node, $artifacts, $query, $opts, $trace_id, $tokens );
				$this->emit_step_event( $trace_id, $skill, $node, $idx, $total, 'done', null, $ms, 'Tổng hợp câu trả lời hoàn tất.' );
				$idx++;
				// Compose is always terminal — stop graph walk.
				break;
			}

			// ─── Brain-safety whitelist ──────────────────────────────────────
			// Block kinds with prefix 'admin.' are banned in brain mode.
			if ( strpos( $kind, 'admin.' ) === 0 ) {
				$ms = (int) round( ( microtime( true ) - $node_t0 ) * 1000 );
				$this->emit_step_event( $trace_id, $skill, $node, $idx, $total, 'skipped', null, $ms, "Bỏ qua '{$kind}': không được phép trong brain mode." );
				$idx++;
				continue;
			}

			// [2026-06-20 Johnny Chu] PHASE-TWB-WORKFLOW W7 — block publish/send side-effects in brain mode.
			// action.publish_* and action.send_* can trigger real external state changes
			// (post FB, send Zalo…) silently during a /skill chat turn. Spec §10 HIGH risk.
			// Guard: skip unless the node has explicit config flag `brain_allowed: true`.
			// This lets power-users opt-in per-node in the graph builder.
			$is_side_effect = (
				strpos( $kind, 'action.publish_' ) === 0 ||
				strpos( $kind, 'action.send_' )    === 0 ||
				strpos( $kind, 'action.post_' )    === 0
			);
			if ( $is_side_effect ) {
				$cfg = $this->get_node_config( $node );
				if ( empty( $cfg['brain_allowed'] ) ) {
					$ms = (int) round( ( microtime( true ) - $node_t0 ) * 1000 );
					$this->emit_step_event( $trace_id, $skill, $node, $idx, $total, 'skipped', null, $ms, "Bỏ qua '{$kind}': block có tác động bên ngoài cần opt-in `brain_allowed:true` trong graph." );
					$idx++;
					continue;
				}
			}

			// ─── Resolve block class ─────────────────────────────────────────
			$block = $this->resolve_block( $kind );

			if ( ! $block ) {
				$ms = (int) round( ( microtime( true ) - $node_t0 ) * 1000 );
				$this->emit_step_event( $trace_id, $skill, $node, $idx, $total, 'error', null, $ms, "Block kind '{$kind}' không tìm thấy." );
				$idx++;
				continue;
			}
			$ctx = $this->build_node_ctx( $node, $artifacts, $query, $opts, $trace_id );

			// [2026-06-20 Johnny Chu] PHASE-TWB-WORKFLOW W7 — pass $data as 2nd arg.
			// Block interface: execute(array $ctx, array $data). $data = node config
			// fields (defaults filled). Blocks use resolve($data['query'], $ctx) etc.
			// Merge block meta() defaults first so node config overrides are on top.
			// Without this, nodes without an explicit 'query' field would pass '' to
			// action.search_kg — no KG lookup would happen.
			$node_config = $this->get_node_config( $node );
			$block_meta  = method_exists( $block, 'meta' ) ? $block->meta() : array();
			$block_defaults = is_array( $block_meta['defaults'] ?? null ) ? $block_meta['defaults'] : array();
			$node_data = array_merge( $block_defaults, $node_config );

			$output = null;
			$exec_error = '';
			try {
				$node_deadline = $node_t0 + self::NODE_TIMEOUT_S;
				// Inject deadline hint; blocks that support it can self-limit.
				$ctx['_deadline'] = $node_deadline;
				$output = $block->execute( $ctx, $node_data );
			} catch ( \Throwable $e ) {
				$exec_error = $e->getMessage();
				error_log( '[TwinBrain][workflow-pipeline] node ' . $kind . ' threw: ' . $exec_error );
			}

			$ms = (int) round( ( microtime( true ) - $node_t0 ) * 1000 );

			if ( $exec_error !== '' ) {
				$this->emit_step_event( $trace_id, $skill, $node, $idx, $total, 'error', null, $ms, $exec_error );
				$idx++;
				continue; // soft-fail — keep going
			}

			// ─── Normalize output into artifact ──────────────────────────────
			if ( class_exists( 'BizCity_Twin_Artifact_Normalizer' ) ) {
				$artifact       = BizCity_Twin_Artifact_Normalizer::normalize( $output, $node, $ctx );
				$artifact['ms'] = $ms;
			} else {
				$artifact = array(
					'node_id'   => (string) ( $node['id']    ?? "n_{$idx}" ),
					'node_kind' => $kind,
					'label'     => (string) ( $node['label'] ?? "Bước " . ( $idx + 1 ) ),
					'type'      => 'text',
					'body'      => is_string( $output ) ? substr( $output, 0, 4000 ) : '',
					'summary'   => '',
					'ms'        => $ms,
				);
			}

			// Enforce artifact cap.
			if ( count( $artifacts ) < self::ARTIFACT_CAP ) {
				$artifacts[] = $artifact;
			}

			$summary = (string) ( $artifact['summary'] ?? '' );
			// Check node timeout post-hoc (block may have ignored deadline).
			$actual_status = $ms > ( self::NODE_TIMEOUT_S * 1000 ) ? 'timeout' : 'done';
			$this->emit_step_event( $trace_id, $skill, $node, $idx, $total, $actual_status, $artifact, $ms, $summary );

			$idx++;
		}

		// If graph had no terminal compose (or it was capped), fallback-compose.
		if ( empty( $this->_compose_done ) ) {
			return $this->fallback_compose( $trace_id, $query, $artifacts, $opts, $tokens );
		}

		return array(
			'artifacts' => $artifacts,
			'answer_md' => (string) ( $this->_compose_answer ?? '' ),
			'tokens'    => $tokens + (int) ( $this->_compose_tokens ?? 0 ),
		);
	}

	/** @var bool   True when terminal compose completed. Reset at top of run(). */
	private $_compose_done   = false;
	/** @var string Final answer_md from compose. */
	private $_compose_answer = '';
	/** @var int    Tokens used by compose. */
	private $_compose_tokens = 0;

	/**
	 * Execute terminal compose node via Final_Composer::compose_stream().
	 *
	 * [2026-06-19 Johnny Chu] PHASE-TWB-WORKFLOW W1
	 *
	 * @return int Wall-clock ms for compose step.
	 */
	private function do_compose( array $node, array $artifacts, string $query, array $opts, string $trace_id, int &$tokens ): int {
		$t0 = microtime( true );
		$this->_compose_done   = true;
		$this->_compose_answer = '';
		$this->_compose_tokens = 0;

		if ( ! class_exists( 'BizCity_TwinBrain_Final_Composer' ) ) {
			// Graceful degrade: join artifact bodies as answer.
			$this->_compose_answer = $this->artifacts_to_text( $artifacts );
			return (int) round( ( microtime( true ) - $t0 ) * 1000 );
		}

		// Build a minimal synth + answers array from artifacts so Final_Composer
		// receives the same shape it expects from the normal pipeline.
		$synth_body = $this->build_compose_prompt( $artifacts );
		$synth      = array(
			'answer_md' => $synth_body,
			'ms'        => 0,
		);
		$answers    = array();

		// on_token callback: relay token SSE to caller.
		$on_token = isset( $opts['on_token'] ) && is_callable( $opts['on_token'] )
			? $opts['on_token']
			: null;

		// If no on_token, build a simple direct SSE emitter.
		if ( ! $on_token && $this->sse_emitter ) {
			$emitter  = $this->sse_emitter;
			$on_token = static function ( $delta, $accumulated ) use ( $emitter ) {
				call_user_func( $emitter, 'token', array( 'text' => (string) $delta ) );
			};
		}

		$compose_opts = array(
			'user_id' => (int) ( $opts['user_id'] ?? 0 ),
		);

		try {
			$result = BizCity_TwinBrain_Final_Composer::instance()->compose_stream(
				$trace_id,
				$query,
				$synth,
				$answers,
				$compose_opts,
				$on_token
			);
			$this->_compose_answer = (string) ( $result['answer_md'] ?? $synth_body );
			$this->_compose_tokens = (int)    ( $result['tokens']    ?? 0 );
			$tokens += $this->_compose_tokens;
		} catch ( \Throwable $e ) {
			error_log( '[TwinBrain][workflow-pipeline] compose_stream threw: ' . $e->getMessage() );
			$this->_compose_answer = $this->artifacts_to_text( $artifacts );
		}

		return (int) round( ( microtime( true ) - $t0 ) * 1000 );
	}

	/**
	 * Fallback compose when graph has no terminal llm.compose node.
	 *
	 * [2026-06-19 Johnny Chu] PHASE-TWB-WORKFLOW W1
	 *
	 * @return array { artifacts, answer_md, tokens }
	 */
	private function fallback_compose( string $trace_id, string $query, array $artifacts, array $opts, int $tokens ): array {
		// [2026-06-20 Johnny Chu] PHASE-TWB-WORKFLOW W1-fix — added $trace_id param; was hardcoded 'fallback'
		// Attempt Final_Composer with accumulated artifacts.
		if ( class_exists( 'BizCity_TwinBrain_Final_Composer' ) ) {
			$synth   = array( 'answer_md' => $this->build_compose_prompt( $artifacts ), 'ms' => 0 );
			$on_tok  = isset( $opts['on_token'] ) && is_callable( $opts['on_token'] ) ? $opts['on_token'] : null;
			if ( ! $on_tok && $this->sse_emitter ) {
				$emitter = $this->sse_emitter;
				$on_tok  = static function ( $d, $a ) use ( $emitter ) {
					call_user_func( $emitter, 'token', array( 'text' => (string) $d ) );
				};
			}
			try {
				$r = BizCity_TwinBrain_Final_Composer::instance()->compose_stream(
					$trace_id, $query, $synth, array(), array( 'user_id' => (int) ( $opts['user_id'] ?? 0 ) ), $on_tok
				);
				$tokens += (int) ( $r['tokens'] ?? 0 );
				return array( 'artifacts' => $artifacts, 'answer_md' => (string) ( $r['answer_md'] ?? '' ), 'tokens' => $tokens );
			} catch ( \Throwable $e ) {
				// Silent degrade below.
			}
		}
		return array(
			'artifacts' => $artifacts,
			'answer_md' => $this->artifacts_to_text( $artifacts ),
			'tokens'    => $tokens,
		);
	}

	// ── Graph utilities ──────────────────────────────────────────────────────

	/**
	 * Topological sort of graph nodes (Kahn's algorithm).
	 * Throws BizCity_Cycle_Exception on cycle detection.
	 *
	 * [2026-06-19 Johnny Chu] PHASE-TWB-WORKFLOW W1
	 *
	 * @param array $graph { nodes: array, edges?: array }
	 * @return array Ordered node arrays.
	 */
	private function topo_sort( array $graph ): array {
		// [2026-06-20 Johnny Chu] PHASE-TWB-WORKFLOW W1-fix — removed unused $index
		$nodes = array();
		foreach ( $graph['nodes'] as $n ) {
			$id           = (string) ( $n['id'] ?? '' );
			$nodes[ $id ] = $n;
		}

		// Build adjacency + in-degree from edges (if present).
		$in_degree  = array_fill_keys( array_keys( $nodes ), 0 );
		$successors = array_fill_keys( array_keys( $nodes ), array() );

		$edges = $graph['edges'] ?? array();
		foreach ( $edges as $e ) {
			$from = (string) ( $e['source'] ?? $e['from'] ?? '' );
			$to   = (string) ( $e['target'] ?? $e['to']   ?? '' );
			if ( isset( $nodes[ $from ] ) && isset( $nodes[ $to ] ) ) {
				$successors[ $from ][] = $to;
				$in_degree[ $to ]++;
			}
		}

		// Kahn's BFS.
		$queue  = array();
		$sorted = array();
		foreach ( $in_degree as $id => $deg ) {
			if ( $deg === 0 ) {
				$queue[] = $id;
			}
		}

		while ( ! empty( $queue ) ) {
			$id      = array_shift( $queue );
			$sorted[] = $nodes[ $id ];
			foreach ( $successors[ $id ] as $next ) {
				$in_degree[ $next ]--;
				if ( $in_degree[ $next ] === 0 ) {
					$queue[] = $next;
				}
			}
		}

		// If not all nodes processed → cycle.
		if ( count( $sorted ) !== count( $nodes ) ) {
			throw new \RuntimeException( 'Workflow graph có chu trình (cycle) — không thể thực thi.' );
		}

		return $sorted;
	}

	/**
	 * Auto-append terminal llm.compose node if the graph has none.
	 * Idempotent — does nothing if compose already exists.
	 *
	 * [2026-06-19 Johnny Chu] PHASE-TWB-WORKFLOW W1
	 * [2026-06-20 Johnny Chu] PHASE-TWB-WORKFLOW W6-BUG — also accept llm.compose_reply (FE builder block ID).
	 */
	private function ensure_terminal_compose( array &$graph ): void {
		foreach ( $graph['nodes'] as $n ) {
			$k = $this->get_node_kind( $n );
			if ( $k === 'llm.compose' || $k === 'llm.compose_reply' ) {
				return;
			}
		}
		$graph['nodes'][] = array(
			'id'     => 'auto_compose_terminal',
			'kind'   => 'llm.compose',
			'label'  => 'Tổng hợp câu trả lời',
			'config' => array( 'use_artifacts' => true, 'tone' => 'friendly' ),
		);
	}

	// ── Block resolution ─────────────────────────────────────────────────────

	/**
	 * Resolve block class for a given kind via Automation Block Registry.
	 *
	 * [2026-06-19 Johnny Chu] PHASE-TWB-WORKFLOW W1
	 *
	 * @param string $kind Block kind (e.g. 'action.search_kg', 'llm.mpr_think').
	 * @return object|null  Block instance or null if not found.
	 */
	private function resolve_block( string $kind ) {
		// Try Automation Block Registry (preferred).
		// Registry uses ::instance()->get($kind) — confirmed from class-block-registry.php.
		if ( class_exists( 'BizCity_Automation_Block_Registry' ) ) {
			$registry = BizCity_Automation_Block_Registry::instance();
			$block    = $registry->get( $kind );
			if ( $block ) {
				return $block;
			}
		}
		// Extension hook for third-party blocks.
		return apply_filters( 'bizcity_twinbrain_workflow_resolve_block', null, $kind );
	}

	/**
	 * Extract block kind from a graph node, handling two formats:
	 *   - Pipeline simplified: { kind: 'action.search_kg' }
	 *   - FE workflow builder: { type: '...', data: { blockId: 'action.search_kg' } }
	 *
	 * [2026-06-19 Johnny Chu] PHASE-TWB-WORKFLOW W1 fix
	 *
	 * @param array $node Graph node array.
	 * @return string Block kind string, empty if not found.
	 */
	private function get_node_kind( array $node ): string {
		// Simplified pipeline format: { kind: 'action.search_kg' }
		if ( isset( $node['kind'] ) && $node['kind'] !== '' ) {
			return (string) $node['kind'];
		}
		// FE workflow builder / automation runner format: { data: { blockId: '...' } }
		if ( isset( $node['data']['blockId'] ) && $node['data']['blockId'] !== '' ) {
			return (string) $node['data']['blockId'];
		}
		// Fallback: React Flow node type field.
		return (string) ( $node['type'] ?? '' );
	}

	/**
	 * Extract node config from a graph node, handling two formats:
	 *   - Pipeline simplified: { config: { ... } }
	 *   - FE workflow builder: { data: { blockId: '...', ...config_fields } }
	 *
	 * [2026-06-19 Johnny Chu] PHASE-TWB-WORKFLOW W1 fix
	 *
	 * @param array $node Graph node array.
	 * @return array Config array (may be empty).
	 */
	private function get_node_config( array $node ): array {
		// Simplified format: { config: {...} }
		if ( isset( $node['config'] ) && is_array( $node['config'] ) ) {
			return $node['config'];
		}
		// Builder format: fields are mixed into data alongside blockId.
		if ( isset( $node['data'] ) && is_array( $node['data'] ) ) {
			$data = $node['data'];
			unset( $data['blockId'] ); // strip the kind identifier, keep config fields
			return $data;
		}
		return array();
	}

	// ── Node context builder ─────────────────────────────────────────────────

	/**
	 * Build the execution context for a single node.
	 * Injects identity, artifact pool (metadata only, not full bodies), and query.
	 *
	 * [2026-06-19 Johnny Chu] PHASE-TWB-WORKFLOW W1 — only previous-node metadata
	 * injected (not full bodies) to prevent context explosion.
	 *
	 * @return array
	 */
	private function build_node_ctx( array $node, array $artifacts, string $query, array $opts, string $trace_id ): array {
		// Previous-node artifact summaries (safe — short strings only).
		$artifact_meta = array();
		foreach ( $artifacts as $art ) {
			$artifact_meta[] = array(
				'node_id'   => $art['node_id'],
				'node_kind' => $art['node_kind'],
				'label'     => $art['label'],
				'type'      => $art['type'],
				'summary'   => $art['summary'],
				// Pass full body only if within safe size.
				'body'      => strlen( (string) ( $art['body'] ?? '' ) ) <= 2000 ? (string) $art['body'] : '',
			);
		}

		// KG snippet alias — blocks use {{kg.snippet}} to reference last search.
		$kg_snippet = '';
		foreach ( array_reverse( $artifact_meta ) as $art ) {
			if ( strpos( (string) ( $art['node_kind'] ?? '' ), 'search_kg' ) !== false
				&& (string) ( $art['body'] ?? '' ) !== '' ) {
				$kg_snippet = $art['body'];
				break;
			}
		}

		// [2026-06-20 Johnny Chu] PHASE-TWB-WORKFLOW W7 — trigger pseudo-payload.
		// Blocks were designed for cron/webhook context where ctx['trigger'] carries
		// the inbound message. In brain mode we map opts → trigger shape so blocks
		// using {{trigger.text}}, {{trigger.wp_user_id}}, etc. resolve correctly
		// without requiring code changes in each block.
		$user_id = (int) ( $opts['user_id'] ?? 0 );
		$trigger = array(
			'text'          => $query,
			'wp_user_id'    => $user_id,
			'user_id'       => $user_id,
			'chat_id'       => (string) ( $opts['session_id'] ?? '' ),
			'session_id'    => (string) ( $opts['session_id'] ?? '' ),
			'character_id'  => 0,   // no guru override by default
			'platform'      => (string) ( $opts['surface'] ?? 'twinweb' ),
			'_brain_mode'   => true,
		);

		return array(
			'_brain_mode' => true,
			'_user_id'    => $user_id,
			'_guest_sid'  => (string) ( $opts['guest_sid'] ?? '' ),
			'_session_id' => (string) ( $opts['session_id'] ?? '' ),
			'_surface'    => (string) ( $opts['surface'] ?? 'twinweb' ),
			'_artifacts'  => $artifact_meta,
			'_query'      => $query,
			'_history'    => (array) ( $opts['history'] ?? array() ),
			'_trace_id'   => $trace_id,
			'_workflow_id' => (int) ( $opts['_workflow_id'] ?? 0 ),
			'_run_id'      => (string) ( $opts['_run_id']     ?? '' ),
			'trigger'     => $trigger,
			'kg'          => array( 'snippet' => $kg_snippet ),
			'config'      => $this->get_node_config( $node ),
		);
	}

	// ── Compose prompt builder ───────────────────────────────────────────────

	/**
	 * Build a structured prompt block from accumulated artifacts for Final_Composer.
	 * Mirror §13 MPR-RESEARCH paradigm: ## RESEARCH ARTIFACTS block.
	 *
	 * [2026-06-19 Johnny Chu] PHASE-TWB-WORKFLOW W1
	 */
	private function build_compose_prompt( array $artifacts ): string {
		if ( empty( $artifacts ) ) {
			return '';
		}
		$lines = array( '## RESEARCH ARTIFACTS' );
		foreach ( $artifacts as $i => $art ) {
			$n       = $i + 1;
			$kind    = (string) ( $art['node_kind'] ?? 'unknown' );
			$label   = (string) ( $art['label']    ?? "Bước {$n}" );
			$body    = (string) ( $art['body']     ?? '' );
			$lines[] = "\n### [{$n}] {$label} ({$kind})";
			if ( $body !== '' ) {
				$lines[] = $body;
			}
		}
		return implode( "\n", $lines );
	}

	/**
	 * Naive text concatenation of artifact bodies (used when Final_Composer unavailable).
	 * [2026-06-19 Johnny Chu] PHASE-TWB-WORKFLOW W1
	 */
	private function artifacts_to_text( array $artifacts ): string {
		$parts = array();
		foreach ( $artifacts as $art ) {
			$body = (string) ( $art['body'] ?? '' );
			if ( $body !== '' ) {
				$parts[] = $body;
			}
		}
		return implode( "\n\n" , $parts );
	}

	// ── SSE emit helpers ─────────────────────────────────────────────────────

	/**
	 * Emit workflow_started SSE event.
	 * [2026-06-19 Johnny Chu] PHASE-TWB-WORKFLOW W1
	 */
	private function emit_workflow_started( string $trace_id, string $skill, int $workflow_id, int $node_count, string $label, array $opts ): void {
		$payload = array(
			'trace_id'    => $trace_id,
			'skill_slug'  => $skill,
			'workflow_id' => $workflow_id,
			'node_count'  => $node_count,
			'label'       => $label,
			'surface'     => (string) ( $opts['surface'] ?? 'twinweb' ),
		);
		// Direct SSE (primary path).
		$this->emit_sse( 'workflow_started', $payload );
		// Event bus (audit trail; may throw — catch silently).
		$this->dispatch_event_bus( 'workflow_started', $payload );
	}

	/**
	 * Emit workflow_step SSE event for a node state change.
	 * [2026-06-19 Johnny Chu] PHASE-TWB-WORKFLOW W1
	 *
	 * @param array|null $artifact Artifact row (for output_summary on done).
	 * @param string|null $extra_summary Override summary string.
	 */
	private function emit_step_event(
		string $trace_id,
		string $skill,
		array $node,
		int $idx,
		int $total,
		string $status,
		$artifact,
		int $ms,
		$extra_summary
	): void {
		// [2026-06-20 Johnny Chu] PHASE-TWB-WORKFLOW W1-fix — use get_node_kind() for dual-format;
		// send 1-based idx (FE ChatPage does event.idx - 1 to get array position).
		$node_id   = (string) ( $node['id']    ?? "node_{$idx}" );
		$node_kind = $this->get_node_kind( $node ) ?: 'unknown';
		$label     = (string) ( $node['label'] ?? "Bước " . ( $idx + 1 ) );

		$payload = array(
			'trace_id'  => $trace_id,
			'skill_slug'=> $skill,
			'node_id'   => $node_id,
			'node_kind' => $node_kind,
			'label'     => $label,
			'status'    => $status,
			'idx'       => $idx + 1,
			'total'     => $total,
			'ms'        => $ms,
		);

		if ( $extra_summary !== null && $extra_summary !== '' ) {
			$payload['output_summary'] = (string) $extra_summary;
		} elseif ( is_array( $artifact ) && ! empty( $artifact['summary'] ) ) {
			$payload['output_summary'] = (string) $artifact['summary'];
		}

		if ( $status === 'error' && $extra_summary !== null ) {
			$payload['error'] = (string) $extra_summary;
		}

		$this->emit_sse( 'workflow_step', $payload );
		$this->dispatch_event_bus( 'workflow_step', $payload );
	}

	/**
	 * Emit workflow_completed SSE event.
	 * [2026-06-19 Johnny Chu] PHASE-TWB-WORKFLOW W1
	 */
	private function emit_completed( string $trace_id, array $row, float $t0 ): void {
		$total_ms = (int) round( ( microtime( true ) - $t0 ) * 1000 );
		$payload  = array(
			'trace_id'       => $trace_id,
			'skill_slug'     => (string) ( $row['skill_slug']  ?? '' ),
			'artifacts_count'=> count( (array) ( $row['artifacts'] ?? array() ) ),
			'total_ms'       => $total_ms,
			'tokens'         => (int) ( $row['tokens'] ?? 0 ),
			'answer_len'     => strlen( (string) ( $row['answer_md'] ?? '' ) ),
			'error'          => (string) ( $row['error'] ?? '' ),
		);
		$this->emit_sse( 'workflow_completed', $payload );
		$this->dispatch_event_bus( 'workflow_completed', $payload );
	}

	/**
	 * Emit a raw SSE frame via the injected emitter.
	 * Falls back to direct echo if no emitter set (for unit testing).
	 *
	 * [2026-06-19 Johnny Chu] PHASE-TWB-WORKFLOW W1
	 */
	private function emit_sse( string $event_name, array $payload ): void {
		if ( $this->sse_emitter ) {
			try {
				call_user_func( $this->sse_emitter, $event_name, $payload );
			} catch ( \Throwable $e ) {
				error_log( '[TwinBrain][workflow-pipeline] sse_emitter threw on ' . $event_name . ': ' . $e->getMessage() );
			}
			return;
		}
		// No emitter — emit directly (for REST contexts that call run() standalone).
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo "event: {$event_name}\n";
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo 'data: ' . wp_json_encode( $payload ) . "\n\n";
		if ( ob_get_level() ) {
			ob_flush();
		}
		flush();
	}

	/**
	 * Attempt to dispatch to Event Bus for audit trail.
	 * Catches all exceptions — event bus failure must never break the pipeline.
	 *
	 * [2026-06-19 Johnny Chu] PHASE-TWB-WORKFLOW W1
	 */
	private function dispatch_event_bus( string $event_type, array $payload ): void {
		if ( ! class_exists( 'BizCity_Twin_Event_Bus' ) ) {
			return;
		}
		try {
			BizCity_Twin_Event_Bus::dispatch_v2( $event_type, $payload, array(
				'event_source' => 'twinbrain',
			) );
		} catch ( \Throwable $e ) {
			// Non-fatal — event bus audit failure does not abort pipeline.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[TwinBrain][workflow-pipeline] event bus ' . $event_type . ' dispatch failed: ' . $e->getMessage() );
			}
		}
	}
}
