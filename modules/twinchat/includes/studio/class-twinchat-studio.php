<?php
/**
 * Bizcity Twin AI — TwinChat Studio Orchestrator
 *
 * Phase 0.7 Wave C — Port of BCN_Studio scoped to TwinChat notebooks.
 *
 * Pipeline:
 *   1. BizCity_TwinChat_Studio_Input_Builder::build($notebook_id)
 *      → cached Skeleton JSON (LLM 1-shot) + Graph-RAG subgraph enrichment
 *   2. BCN_Notebook_Tool_Registry::execute($tool_type, $skeleton)
 *      → dispatch to any tool registered via the `bcn_register_notebook_tools`
 *        hook (bzdoc bridge, BCN content tools, future content-creator plugins)
 *   3. save_output() → bizcity_webchat_studio_outputs (project_id = "tc_<id>")
 *      → fires `bizcity_twinchat_studio_generated` action for downstream
 *
 * Output table is the SAME `bizcity_webchat_studio_outputs` already used by
 * companion-notebook so artifacts are unified across surfaces.
 *
 * PHP 7.4 compatible.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Modules\TwinChat\Studio
 * @since 0.7.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_TwinChat_Studio {

	private static $instance = null;

	/** WP-Cron / Action Scheduler hook name for background generation. */
	const HOOK_RUN = 'bizcity_twinchat_studio_run';

	public static function instance() {
		if ( null === self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	/* ─────────────────────────── Tools ──────────────────────────────── */

	public static function get_available_tools() {
		if ( ! class_exists( 'BCN_Notebook_Tool_Registry' ) ) return [];
		return BCN_Notebook_Tool_Registry::get_all();
	}

	public static function has_tool( $type ) {
		if ( ! class_exists( 'BCN_Notebook_Tool_Registry' ) ) return false;
		return BCN_Notebook_Tool_Registry::has( $type );
	}

	/* ───────────────────────── Generate ─────────────────────────────── */

	/**
	 * Generate a studio artifact for a TwinChat notebook.
	 *
	 * @param int    $notebook_id
	 * @param string $tool_type    Registered tool type (e.g. doc_presentation).
	 * @param int    $user_id
	 * @param array  $opts {
	 *   @type int[] source_ids  Optional: restrict skeleton to selected sources.
	 *   @type bool  force       Bypass skeleton cache.
	 * }
	 * @return int|WP_Error  Output row id on success.
	 */
	public function generate( $notebook_id, $tool_type, $user_id, array $opts = [] ) {
		$notebook_id = (int) $notebook_id;
		$tool_type   = sanitize_key( (string) $tool_type );
		$user_id     = (int) $user_id;

		if ( $notebook_id <= 0 ) {
			return new WP_Error( 'bad_notebook', 'Notebook id required.' );
		}
		if ( ! self::has_tool( $tool_type ) ) {
			return new WP_Error( 'unknown_tool', "Tool '{$tool_type}' chưa được đăng ký vào BCN_Notebook_Tool_Registry." );
		}

		$skeleton = BizCity_TwinChat_Studio_Input_Builder::build( $notebook_id, [
			'force'      => ! empty( $opts['force'] ),
			'source_ids' => isset( $opts['source_ids'] ) && is_array( $opts['source_ids'] ) ? $opts['source_ids'] : [],
		] );

		if ( empty( $skeleton['skeleton'] ) && empty( $skeleton['_raw_text'] ) ) {
			return new WP_Error( 'no_content', 'Notebook chưa có nguồn hoặc ghi chú để tạo nội dung.' );
		}

		error_log( sprintf(
			'[TwinChat Studio] generate notebook=%d tool=%s skeleton_nodes=%d sources=%d notes=%d kg_passages=%d',
			$notebook_id, $tool_type,
			count( $skeleton['skeleton'] ?? [] ),
			(int) ( $skeleton['meta']['source_count'] ?? 0 ),
			(int) ( $skeleton['meta']['note_count']   ?? 0 ),
			isset( $skeleton['_kg_subgraph']['passages'] ) ? count( $skeleton['_kg_subgraph']['passages'] ) : 0
		) );

		$result = BCN_Notebook_Tool_Registry::execute( $tool_type, $skeleton );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$output_id = $this->save_output( $notebook_id, $tool_type, $result, $user_id, $skeleton );
		if ( $output_id <= 0 ) {
			return new WP_Error( 'save_failed', 'Không thể lưu output vào DB.' );
		}

		do_action( 'bizcity_twinchat_studio_generated', $output_id, $tool_type, $notebook_id );
		return $output_id;
	}

	/* ───────────────── Async entry point (avoids 524 timeout) ─────────── */

	/**
	 * Non-blocking: create a job in studio_jobs then schedule background dispatch.
	 * Returns job_id in < 100ms — REST response beats Cloudflare 100s timeout.
	 * Worker builds skeleton + calls bridge, then Job_Manager::complete() writes
	 * studio_outputs ONLY when done (real content, not a placeholder).
	 *
	 * For 'dispatch'-mode bridges (fast, no LLM in bridge itself), the worker
	 * still runs in the background because skeleton building is slow.
	 *
	 * @return int|WP_Error  Job ID
	 */
	public function enqueue_generate( $notebook_id, $tool_type, $user_id, array $opts = [] ) {
		$notebook_id = (int) $notebook_id;
		$tool_type   = sanitize_key( (string) $tool_type );
		$user_id     = (int) $user_id;

		if ( $notebook_id <= 0 ) {
			return new WP_Error( 'bad_notebook', 'Notebook id required.' );
		}
		// Allow bridge-only tools OR legacy BCN_Notebook_Tool_Registry tools.
		if ( ! BizCity_Studio_Job_Manager::has_bridge( $tool_type ) && ! self::has_tool( $tool_type ) ) {
			return new WP_Error( 'unknown_tool', "Tool '{$tool_type}' chưa được đăng ký." );
		}

		$pid    = BizCity_TwinChat_Studio_Input_Builder::project_id( $notebook_id );
		$job_id = BizCity_Studio_Job_Manager::create( [
			'project_id'       => $pid,
			'user_id'          => $user_id,
			'tool_type'        => $tool_type,
			'allow_duplicate'  => ! empty( $opts['allow_duplicate'] ),
		] );

		if ( is_wp_error( $job_id ) ) return $job_id;

		$this->schedule_run( $job_id, $notebook_id, $tool_type, $user_id, $opts );
		return $job_id;
	}

	/**
	 * Worker: builds skeleton + executes bridge, then delegates job completion to
	 * BizCity_Studio_Job_Manager. Race-guarded via atomic claim in Job_Manager::claim().
	 *
	 * NOTE: $job_id replaces the old $output_id parameter.
	 */
	public function run_background_job( $job_id, $notebook_id, $tool_type, $user_id, $opts = [] ) {
		$job_id      = (int) $job_id;
		$notebook_id = (int) $notebook_id;
		$tool_type   = sanitize_key( (string) $tool_type );
		$user_id     = (int) $user_id;
		$opts        = is_array( $opts ) ? $opts : [];

		// Atomic claim — prevents double execution from shutdown + cron duplicate fire.
		if ( ! BizCity_Studio_Job_Manager::claim( $job_id ) ) {
			error_log( '[TwinChat Studio] run_background_job skipped (already claimed) job_id=' . $job_id );
			return;
		}

		// Switch into the user's identity so downstream get_current_user_id()
		// (used by bzdoc bridge / project create / source transfer) returns owner ≠ 0.
		if ( $user_id > 0 && function_exists( 'wp_set_current_user' ) ) {
			wp_set_current_user( $user_id );
		}

		@set_time_limit( 0 );
		@ignore_user_abort( true );

		error_log( sprintf(
			'[TwinChat Studio] run_background_job START job_id=%d notebook=%d tool=%s user=%d',
			$job_id, $notebook_id, $tool_type, $user_id
		) );

		try {
			// Bridge-only tools don't need BCN_Notebook_Tool_Registry.
			if ( ! BizCity_Studio_Job_Manager::has_bridge( $tool_type ) ) {
				if ( ! self::has_tool( $tool_type ) ) {
					throw new Exception( "Tool '{$tool_type}' chưa được đăng ký." );
				}
				if ( ! function_exists( 'bizcity_llm_chat' ) ) {
					throw new Exception( 'bizcity_llm_chat() not available in worker context.' );
				}
			}

			$skeleton = BizCity_TwinChat_Studio_Input_Builder::build( $notebook_id, [
				'force'      => ! empty( $opts['force'] ),
				'source_ids' => isset( $opts['source_ids'] ) && is_array( $opts['source_ids'] ) ? $opts['source_ids'] : [],
			] );
			if ( empty( $skeleton['skeleton'] ) && empty( $skeleton['_raw_text'] ) ) {
				throw new Exception( 'Notebook chưa có nguồn hoặc ghi chú.' );
			}

			if ( ! empty( $opts['kickstart'] ) ) {
				$skeleton['_kickstart'] = true;
			}

			// Persist skeleton into job row (for regenerate / bridge access).
			BizCity_Studio_Job_Manager::save_payload( $job_id, $skeleton );

			// Execute: prefer registered bridge, fall back to BCN_Notebook_Tool_Registry.
			$bridge = BizCity_Studio_Job_Manager::get_bridge( $tool_type );
			if ( $bridge ) {
				$job    = BizCity_Studio_Job_Manager::get( $job_id );
				$result = call_user_func( $bridge['fn'], (array) $job, $skeleton );
			} else {
				$result = BCN_Notebook_Tool_Registry::execute( $tool_type, $skeleton );
			}

			if ( is_wp_error( $result ) ) {
				throw new Exception( $result->get_error_message() );
			}

			// complete() → writes studio_outputs row + updates job.status=done.
			BizCity_Studio_Job_Manager::complete( $job_id, (array) $result, $skeleton );
			error_log( '[TwinChat Studio] run_background_job DONE job_id=' . $job_id );

		} catch ( Exception $e ) {
			error_log( '[TwinChat Studio] run_background_job ERROR job_id=' . $job_id . ' ' . $e->getMessage() );
			BizCity_Studio_Job_Manager::fail( $job_id, $e->getMessage() );
		}
	}

	protected function schedule_run( $output_id, $notebook_id, $tool_type, $user_id, $opts ) {
		$args = [ (int) $output_id, (int) $notebook_id, $tool_type, (int) $user_id, $opts ];

		// Strategy A (preferred): same-process execution AFTER REST response is
		// flushed. PHP-FPM/LiteSpeed keep the worker running with the full
		// max_execution_time, with NO Cloudflare 524 risk because the client
		// connection is already closed.
		$can_finish_request =
			function_exists( 'fastcgi_finish_request' ) ||
			function_exists( 'litespeed_finish_request' );

		if ( $can_finish_request ) {
			add_action( 'shutdown', function () use ( $args ) {
				if ( function_exists( 'fastcgi_finish_request' ) ) {
					@fastcgi_finish_request();
				} elseif ( function_exists( 'litespeed_finish_request' ) ) {
					@litespeed_finish_request();
				}
				@ignore_user_abort( true );
				@set_time_limit( 0 );
				try {
					BizCity_TwinChat_Studio::instance()->run_background_job(
						$args[0], $args[1], $args[2], $args[3], $args[4]
					);
				} catch ( Throwable $e ) {
					error_log( '[TwinChat Studio] shutdown worker fatal: ' . $e->getMessage() );
				}
			}, PHP_INT_MAX );
		}

		// Strategy B (safety net): also queue via Action Scheduler / WP-Cron so
		// the job still runs if the shutdown handler is killed (e.g. fatal
		// during input-builder, missing FPM hooks, mod_php, CLI). The atomic
		// status-flip in run_background_job prevents double execution.
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK_RUN, $args, 'bizcity_twinchat_studio' );
			return;
		}
		if ( ! wp_next_scheduled( self::HOOK_RUN, $args ) ) {
			wp_schedule_single_event( time() + ( $can_finish_request ? 30 : 0 ), self::HOOK_RUN, $args );
		}
		if ( ! $can_finish_request ) {
			$this->spawn_cron();
		}
	}

	protected function spawn_cron() {
		$url = site_url( 'wp-cron.php?doing_wp_cron=' . microtime( true ) );
		wp_remote_post( $url, [
			'timeout'   => 0.01,
			'blocking'  => false,
			'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
		] );
	}

	/* ─────────────────────────── Outputs ────────────────────────────── */

	private function save_output( $notebook_id, $tool_type, $result, $user_id, array $skeleton ) {
		$tbl = self::outputs_table();
		if ( ! $tbl ) return 0;

		global $wpdb;

		$pid = BizCity_TwinChat_Studio_Input_Builder::project_id( $notebook_id );

		$data = [
			'user_id'          => (int) $user_id,
			'project_id'       => $pid,
			'session_id'       => '',
			'caller'           => 'twinchat',
			'tool_type'        => $tool_type,
			'title'            => sanitize_text_field( (string) ( $result['title'] ?? '' ) ),
			'content'          => (string) ( $result['content'] ?? '' ),
			'content_format'   => sanitize_text_field( (string) ( $result['content_format'] ?? 'json' ) ),
			'source_count'     => (int) ( $skeleton['meta']['source_count'] ?? 0 ),
			'note_count'       => (int) ( $skeleton['meta']['note_count']   ?? 0 ),
			'external_post_id' => isset( $result['data']['id'] )  ? absint( $result['data']['id'] ) : null,
			'external_url'     => esc_url_raw( (string) ( $result['data']['url'] ?? '' ) ),
			'status'           => 'ready',
			'input_snapshot'   => wp_json_encode( $skeleton, JSON_UNESCAPED_UNICODE ),
			'created_at'       => current_time( 'mysql' ),
		];

		$ok = $wpdb->insert( $tbl, $data );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * List jobs for a notebook (newest-first). Joined with output data when done.
	 * This replaces the old get_outputs() which queried studio_outputs directly.
	 */
	public function get_outputs( $notebook_id, $tool_type = '' ) {
		$pid  = BizCity_TwinChat_Studio_Input_Builder::project_id( $notebook_id );
		$rows = BizCity_Studio_Job_Manager::list_for_project( $pid, $tool_type );

		// Shape each row to match the legacy REST format expected by the FE.
		$out = [];
		foreach ( $rows as $row ) {
			$out[] = self::shape_job_as_output( $row );
		}
		return $out;
	}

	/**
	 * Get a single job (by job_id) shaped as an output row for the REST layer.
	 * Also returns full content + input_snapshot when $full = true.
	 */
	public function get_output( $id, bool $full = false ) {
		global $wpdb;
		$jobs_tbl    = BizCity_Studio_Job_Manager::table();
		$outputs_tbl = self::outputs_table();

		if ( ! $outputs_tbl ) return null;

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT j.*,
			        COALESCE(o.title, 'Đang tạo…') AS out_title,
			        o.content,
			        o.content_format,
			        COALESCE(o.source_count, 0) AS out_source_count,
			        COALESCE(o.note_count, 0)   AS out_note_count,
			        o.external_url              AS out_external_url,
			        o.external_post_id          AS out_external_post_id,
			        o.input_snapshot
			 FROM {$jobs_tbl} j
			 LEFT JOIN {$outputs_tbl} o ON o.id = j.output_id
			 WHERE j.id = %d",
			(int) $id
		) );
		if ( ! $row ) return null;

		return self::shape_job_as_output( $row, $full );
	}

	public function delete_output( $id, $user_id = 0 ) {
		return BizCity_Studio_Job_Manager::delete( (int) $id, (int) $user_id, true );
	}

	public function regenerate( $id, $user_id = 0 ) {
		$job = BizCity_Studio_Job_Manager::get( (int) $id );
		if ( ! $job ) return new WP_Error( 'not_found', 'Job not found.' );

		$pid = (string) $job->project_id;
		if ( ! preg_match( '/^tc_(\d+)$/', $pid, $m ) ) {
			return new WP_Error( 'bad_scope', 'Job scope is not TwinChat.' );
		}
		$notebook_id = (int) $m[1];
		$this->delete_output( $id, $user_id );
		return $this->enqueue_generate( $notebook_id, (string) $job->tool_type, $user_id ?: (int) $job->user_id, [ 'force' => true, 'allow_duplicate' => true ] );
	}

	/**
	 * Map a job+output joined row to the legacy output shape used by REST layer.
	 */
	private static function shape_job_as_output( object $row, bool $full = false ): object {
		// Map job.status → legacy output status that FE understands.
		$job_status = (string) ( $row->job_status ?? $row->status ?? '' );
		$status_map = [
			'pending'    => 'pending',
			'processing' => 'processing',
			'done'       => 'ready',
			'failed'     => 'error',
		];
		$status = $status_map[ $job_status ] ?? $job_status;

		$title = (string) (
			$row->out_title ?? $row->title ?? 'Đang tạo…'
		);
		$external_url = (string) (
			$row->out_external_url ?? $row->external_url ?? $row->result_url ?? ''
		);

		$out = (object) [
			'id'               => (int) $row->id,
			'project_id'       => (string) $row->project_id,
			'user_id'          => (int) $row->user_id,
			'tool_type'        => (string) $row->tool_type,
			'title'            => $title,
			'content_format'   => (string) ( $row->content_format ?? 'json' ),
			'source_count'     => (int) ( $row->out_source_count ?? $row->source_count ?? 0 ),
			'note_count'       => (int) ( $row->out_note_count   ?? $row->note_count   ?? 0 ),
			'external_url'     => $external_url,
			'external_post_id' => isset( $row->out_external_post_id ) && $row->out_external_post_id !== null
				? (int) $row->out_external_post_id
				: null,
			'status'           => $status,
			'error_message'    => (string) ( $row->error_message ?? '' ),
			'created_at'       => (string) $row->created_at,
			'output_id'        => isset( $row->output_id ) ? (int) $row->output_id : null,
		];

		if ( $full ) {
			$out->content        = (string) ( $row->content ?? '' );
			$out->input_snapshot = (string) ( $row->input_snapshot ?? '' );
		}

		return $out;
	}

	private static function outputs_table() {
		if ( class_exists( 'BCN_Schema_Extend' ) ) {
			return BCN_Schema_Extend::table_studio_outputs();
		}
		global $wpdb;
		$tbl = $wpdb->prefix . 'bizcity_webchat_studio_outputs';
		return (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) ) === $tbl ? $tbl : null;
	}
}
