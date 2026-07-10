<?php
/**
 * Bizcity Twin AI — Notebook Skeleton Service (cron worker)
 *
 * Owns the asynchronous reflection lifecycle: listens to ingest events
 * across the BizCity ecosystem, debounces them, then runs the LLM
 * reflection pass to (re)build a notebook's canonical skeleton.
 *
 * Public surface:
 *  - BizCity_KG_Skeleton_Service::bind()                 (called from bootstrap)
 *  - BizCity_KG_Skeleton_Service::schedule_rebuild($id)  (debounced enqueue)
 *  - BizCity_KG_Skeleton_Service::run_job($id)           (cron callback)
 *  - BizCity_KG_Skeleton_Service::mark_dirty_by_source(  (legacy bridge — F-2)
 *        $cortex, $legacy_source_id, $legacy_source_table )
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge\KG_Hub
 * @since      2026-05-11
 * @see        PHASE-0-RULE-SKELETON.md   Reflection pipeline
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_KG_Skeleton_Service {

	const CRON_HOOK                = 'bizcity_kg_skeleton_reflect_job';
	const DEBOUNCE_DEFAULT         = 30;
	const DEBOUNCE_NOTES_PINNED    = 10; // Phase 6.6 S2.2 — notes change faster than ingest
	const SINGLE_PASS_TOKEN_BUDGET = 100000;
	const LLM_PURPOSE              = 'executor';
	const CHUNK_CONTENT_MAX_CHARS  = 3000; // F-11

	/** F-4 — Cost-Guard operation id used when recording skeleton-reflect LLM spend. */
	const COST_GUARD_OPERATION     = 'skeleton';

	/** Action Scheduler group. */
	const AS_GROUP = 'bizcity-kg-skeleton';

	/** Transient lock TTL (seconds) — F-3. */
	const LOCK_TTL = 180;

	// [2026-06-04 Johnny Chu] SKEL-FAIL-REASON — option prefix stores last fail detail per notebook.
	const FAIL_OPT_PREFIX = 'bizcity_kg_skel_fail_';

	/** Last build-fail reason set by sub-methods, read by run_job() for store_fail(). */
	private static $_last_build_fail = '';

	public static function bind(): void {
		add_action( self::CRON_HOOK, [ __CLASS__, 'run_job' ], 10, 1 );

		// Ingest listeners — keep both canonical + legacy hook names.
		add_action( 'bizcity_twinchat_after_ingest', [ __CLASS__, 'on_twinchat_after_ingest' ], 10, 4 );
		add_action( 'bcn_source_added',              [ __CLASS__, 'on_bcn_source_added' ],     10, 2 );

		// Phase 6.6 S1.5 — canonical kg_sources hook + bizcity-doc upload hook +
		// generic kg-hub source insert (covers any plugin that goes through
		// BizCity_KG_Source_Service::create()).
		add_action( 'bizcity_kg_source_inserted', [ __CLASS__, 'on_kg_source_inserted' ], 10, 2 );
		add_action( 'bizcity_doc_source_added',   [ __CLASS__, 'on_doc_source_added' ],   10, 2 );
		add_action( 'bcn_batch_upload_complete',  [ __CLASS__, 'on_bcn_batch_upload_complete' ], 10, 2 );

		// Phase 6.6 S2.2 — Save-to-notes triggers a debounced (10s) rebuild so
		// pinned notes feed the next reflection pass with priority.
		add_action( 'bizcity_kg_notebook_notes_pinned', [ __CLASS__, 'on_notes_pinned' ], 10, 3 );

		// F-2 — legacy chunks pipeline lacks notebook_id, so we resolve via
		// the legacy_source_id ↔ source_id mapping on the canonical kg_passages.
		add_action(
			'bizcity_kg_legacy_chunks_persisted',
			[ __CLASS__, 'on_legacy_chunks_persisted' ],
			10, 1
		);
	}

	/* ──────────────────────────────────────────────────────────────────
	 *  Scheduler — F-3 + F-7
	 * ──────────────────────────────────────────────────────────────── */

	public static function schedule_rebuild( int $notebook_id, string $trigger_reason = 'ingest' ): void {
		if ( $notebook_id <= 0 ) {
			return;
		}
		$default_delay = ( $trigger_reason === 'notes_pinned' )
			? self::DEBOUNCE_NOTES_PINNED
			: self::DEBOUNCE_DEFAULT;
		$delay = (int) apply_filters(
			'bzkg_skeleton_debounce_seconds',
			$default_delay,
			$notebook_id,
			$trigger_reason
		);
		$delay = max( 5, $delay );

		// Stash the most-recent trigger reason so run_job() can stamp it on
		// the history row (Phase 6.6 S3.2). Transient, not persisted.
		set_transient(
			'bizcity_kg_skel_trigger_' . $notebook_id,
			$trigger_reason,
			10 * MINUTE_IN_SECONDS
		);

		// Prefer Action Scheduler when present (F-7) — survives wp-cron
		// being disabled and gives retry/queue visibility for free.
		if ( function_exists( 'as_unschedule_all_actions' )
		     && function_exists( 'as_schedule_single_action' ) ) {
			as_unschedule_all_actions( self::CRON_HOOK, [ $notebook_id ], self::AS_GROUP );
			as_schedule_single_action(
				time() + $delay,
				self::CRON_HOOK,
				[ $notebook_id ],
				self::AS_GROUP
			);
			return;
		}

		// Fallback to WP-Cron.
		$args = [ $notebook_id ];
		$next = wp_next_scheduled( self::CRON_HOOK, $args );
		if ( $next ) {
			wp_unschedule_event( $next, self::CRON_HOOK, $args );
		}
		wp_schedule_single_event( time() + $delay, self::CRON_HOOK, $args );
	}

	/**
	 * Phase 6.6 — IMMEDIATE trigger (no cron, no debounce).
	 *
	 * Used by the three production triggers per user contract:
	 *   1. First / subsequent file added to a notebook (ingest hooks).
	 *   2. Save-to-notes (`_bizcity_memory_notes` pin).
	 *
	 * Behavior:
	 *   - Flips `skeleton_status` to 'pending' immediately so FE sees the
	 *     “bánh răng” badge during the build.
	 *   - Stashes trigger_reason so run_job() can stamp the history row.
	 *   - If FastCGI is available, defers run_job() to shutdown AFTER
	 *     `fastcgi_finish_request()` so the REST upload response is sent
	 *     before the LLM call begins (avoids client-side timeout).
	 *   - Otherwise runs synchronously (CLI / non-FPM sites).
	 *
	 * Filter `bzkg_skeleton_trigger_async` (bool, default true) lets ops
	 * force fully-synchronous behavior for diagnostics / tests.
	 */
	public static function trigger_now( int $notebook_id, string $trigger_reason = 'ingest' ): void {
		if ( $notebook_id <= 0 ) {
			return;
		}

		global $wpdb;
		$tbl = BizCity_KG_Database::instance()->tbl_notebooks();
		$wpdb->update(
			$tbl,
			[ 'skeleton_status' => BizCity_KG_Skeleton_Adapter::STATUS_PENDING ],
			[ 'id' => $notebook_id ],
			[ '%s' ],
			[ '%d' ]
		);
		BizCity_KG_Skeleton_Adapter::flush_cache( $notebook_id );

		set_transient(
			'bizcity_kg_skel_trigger_' . $notebook_id,
			$trigger_reason,
			10 * MINUTE_IN_SECONDS
		);

		do_action( 'bizcity_kg_notebook_skeleton_marked_dirty', $notebook_id );

		$async = (bool) apply_filters(
			'bzkg_skeleton_trigger_async',
			true,
			$notebook_id,
			$trigger_reason
		);

		if ( $async && function_exists( 'fastcgi_finish_request' )
		     && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			// Defer until after the current REST/HTTP response has been flushed
			// to the client. PHP keeps running after fastcgi_finish_request().
			// Capture blog_id to restore multisite context inside shutdown (R-GW-8 / cron-context guard).
			$_skel_blog_id = function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 0;
			add_action( 'shutdown', static function () use ( $notebook_id, $_skel_blog_id ) {
				if ( function_exists( 'fastcgi_finish_request' ) ) {
					@fastcgi_finish_request();
				}
				$_switched = false;
				if ( $_skel_blog_id > 0 && is_multisite() && get_current_blog_id() !== $_skel_blog_id ) {
					switch_to_blog( $_skel_blog_id );
					$_switched = true;
				}
				try {
					self::run_job( $notebook_id );
				} catch ( \Throwable $e ) {
					error_log( '[bizcity-kg-skeleton] trigger_now shutdown failed nb=' . $notebook_id . ' msg=' . $e->getMessage() );
				} finally {
					if ( $_switched ) {
						restore_current_blog();
					}
				}
			}, 999 );
			return;
		}

		// Synchronous fallback (CLI, mod_php, or when async disabled).
		self::run_job( $notebook_id );
	}

	/* ──────────────────────────────────────────────────────────────────
	 *  Worker — F-3 transient lock
	 * ──────────────────────────────────────────────────────────────── */

	public static function run_job( $notebook_id ): void {
		$notebook_id = (int) $notebook_id;
		if ( $notebook_id <= 0 ) {
			return;
		}

		$lock_key = 'bizcity_kg_skel_lock_' . $notebook_id;
		if ( get_transient( $lock_key ) ) {
			return; // Another worker holds the lock.
		}
		set_transient( $lock_key, 1, self::LOCK_TTL );

		$trigger_reason = (string) get_transient( 'bizcity_kg_skel_trigger_' . $notebook_id );
		if ( $trigger_reason === '' ) { $trigger_reason = 'ingest'; }
		self::cron_meta_note( [ 'notebook_id' => $notebook_id, 'trigger_reason' => $trigger_reason ] );
		self::cron_meta_event( 'skeleton_build_start', [ 'notebook_id' => $notebook_id, 'trigger_reason' => $trigger_reason ] );
		$t_start = microtime( true );

		try {
			self::set_status( $notebook_id, BizCity_KG_Skeleton_Adapter::STATUS_BUILDING );

			// F-4 — Cost-Guard pre-check (skip if class not loaded or guard disabled).
			if ( class_exists( 'BizCity_KG_Cost_Guard' ) ) {
				$owner_id = self::get_notebook_owner( $notebook_id );
				$guard    = BizCity_KG_Cost_Guard::instance()->can_extract( $owner_id, 1 );
				if ( is_wp_error( $guard ) ) {
					self::set_status( $notebook_id, BizCity_KG_Skeleton_Adapter::STATUS_FAILED );
					$msg = $guard->get_error_message();
					error_log( '[bizcity-kg-skeleton] cost-guard blocked notebook=' . $notebook_id
					           . ' owner=' . $owner_id . ' reason=' . $msg );
					self::cron_meta_event( 'skeleton_cost_guard_block', [
						'notebook_id' => $notebook_id,
						'owner_id'    => $owner_id,
						'reason'      => $msg,
					] );				// [2026-06-04 Johnny Chu] SKEL-FAIL-REASON — store for REST status endpoint.
				self::store_fail( $notebook_id, 'cost_guard_blocked', $msg );					return;
				}
			}

			$skeleton = self::build( $notebook_id, $trigger_reason );
			if ( $skeleton ) {
				self::persist( $notebook_id, $skeleton, $trigger_reason );
				self::cron_meta_event( 'skeleton_build_ok', [
					'notebook_id'    => $notebook_id,
					'trigger_reason' => $trigger_reason,
					'duration_ms'    => (int) round( ( microtime( true ) - $t_start ) * 1000 ),
				] );

				// F-4 — Record approximate LLM spend after a successful reflect.
				if ( class_exists( 'BizCity_KG_Cost_Guard' ) ) {
					self::record_skeleton_usage( $notebook_id, $skeleton );
				}
			} else {
				// build() returns null for two reasons:
				// (a) no chunks yet — notebook not ingested; skip silently for notes_pinned
				// (b) LLM / parse failure — log as error only for non-empty-chunk case
				//     (chunks_empty already logged inside build())
				if ( $trigger_reason !== 'notes_pinned' ) {
					self::set_status( $notebook_id, BizCity_KG_Skeleton_Adapter::STATUS_FAILED );
					// [2026-06-04 Johnny Chu] SKEL-FAIL-REASON — use fine-grained reason from sub-methods.
					$fail_reason = self::$_last_build_fail !== '' ? self::$_last_build_fail : 'build_returned_null';
					self::store_fail( $notebook_id, $fail_reason, '' );
					error_log( '[bizcity-kg-skeleton] build returned null notebook=' . $notebook_id
					           . ' trigger=' . $trigger_reason . ' reason=' . $fail_reason );
					self::cron_meta_event( 'skeleton_build_failed', [
						'notebook_id'    => $notebook_id,
						'trigger_reason' => $trigger_reason,
						'reason'         => $fail_reason,
					] );
				} else {
					// notes_pinned + no chunks: restore previous status (don't mark failed)
					self::set_status( $notebook_id, BizCity_KG_Skeleton_Adapter::STATUS_STALE );
				}
			}
		} catch ( \Throwable $e ) {
			self::set_status( $notebook_id, BizCity_KG_Skeleton_Adapter::STATUS_FAILED );
			// [2026-06-04 Johnny Chu] SKEL-FAIL-REASON.
			self::store_fail( $notebook_id, 'exception', $e->getMessage() );
			error_log( '[bizcity-kg-skeleton] exception notebook=' . $notebook_id
			           . ' msg=' . $e->getMessage() );
			self::cron_meta_event( 'skeleton_build_failed', [
				'notebook_id'    => $notebook_id,
				'trigger_reason' => $trigger_reason,
				'reason'         => 'exception',
				'error'          => $e->getMessage(),
			] );
		} finally {
			delete_transient( $lock_key );
			delete_transient( 'bizcity_kg_skel_trigger_' . $notebook_id );
		}
	}

	/* ──────────────────────────────────────────────────────────────────
	 *  Build pipeline
	 * ──────────────────────────────────────────────────────────────── */

	private static function build( int $notebook_id, string $trigger_reason = 'ingest' ): ?array {
		$chunks = self::load_chunks( $notebook_id );
		if ( ! $chunks ) {
			// notes_pinned fires BEFORE any documents are ingested — that's fine,
			// just skip silently at debug level (not an error worth alerting on).
			if ( $trigger_reason !== 'notes_pinned' ) {
				error_log( '[bizcity-kg-skeleton] no chunks loaded notebook=' . $notebook_id
				           . ' trigger=' . $trigger_reason );
			}
			self::cron_meta_event( 'skeleton_chunks_empty', [
				'notebook_id'    => $notebook_id,
				'trigger_reason' => $trigger_reason,
			] );
			return null;
		}

		// Phase 6.6 S6.6.2 — when the build was triggered by a Save-to-notes
		// pin, surface the latest pinned notes as a HIGH-PRIORITY context block
		// so the reflection re-weights nucleus / skeleton around user intent.
		$pinned_notes = ( $trigger_reason === 'notes_pinned' )
			? self::load_pinned_notes( $notebook_id )
			: [];

		$total_tokens = 0;
		foreach ( $chunks as $c ) {
			$total_tokens += (int) ( $c['_tokens'] ?? 0 );
		}
		self::cron_meta_note( [
			'counters' => [
				'chunks_loaded'      => count( $chunks ),
				'pinned_notes_count' => count( $pinned_notes ),
				'tokens_in'          => $total_tokens,
			],
		] );

		if ( $total_tokens <= self::SINGLE_PASS_TOKEN_BUDGET ) {
			return self::single_pass( $chunks, $pinned_notes );
		}
		return self::map_reduce( $chunks, $pinned_notes );
	}

	private static function single_pass( array $chunks, array $pinned_notes = [] ): ?array {
		$has_notes = ! empty( $pinned_notes );
		$messages = [
			[ 'role' => 'system', 'content' => BizCity_KG_Skeleton_Prompt::system( $has_notes ) ],
			[ 'role' => 'user',   'content' => self::compose_chunks_message( $chunks, $pinned_notes ) ],
		];

		// [2026-06-05 Johnny Chu] SKEL-PARSE-ROBUST — json_object mode + correction retry.
		$last_err = '';
		$is_parse_fail = false;
		for ( $attempt = 1; $attempt <= 2; $attempt++ ) {
			$resp = bizcity_llm_chat( $messages, [
				'purpose'          => self::LLM_PURPOSE,
				'temperature'      => 0.2,
				'response_format'  => [ 'type' => 'json_object' ],
			] );
			if ( empty( $resp['success'] ) ) {
				$last_err = $resp['error'] ?? 'llm_failed';
				error_log( '[bizcity-kg-skeleton] single_pass llm_error attempt=' . $attempt . ' msg=' . $last_err );
				self::cron_meta_event( 'skeleton_llm_error', [ 'attempt' => $attempt, 'error' => $last_err ] );
				continue;
			}
			$content = (string) ( $resp['message'] ?? '' );
			$out = BizCity_KG_Skeleton_Prompt::validate( $content );
			if ( $out ) { return $out; }
			$is_parse_fail = true;
			$last_err = 'validate_failed (len=' . strlen( $content ) . ')';
			error_log( '[bizcity-kg-skeleton] single_pass parse failed attempt=' . $attempt . ' ' . $last_err );
			self::cron_meta_event( 'skeleton_llm_parse_error', [ 'attempt' => $attempt, 'preview' => substr( $content, 0, 200 ) ] );
			// On parse-fail, add a correction turn for the next attempt.
			if ( $attempt === 1 ) {
				$messages[] = [ 'role' => 'assistant', 'content' => $content ];
				$messages[] = [ 'role' => 'user', 'content' =>
					'Output của bạn không phải JSON hợp lệ. Hãy trả lại ĐÚNG JSON object theo schema đã yêu cầu, không có bất kỳ text nào khác bên ngoài JSON.' ];
			}
		}
		// [2026-06-04 Johnny Chu] SKEL-FAIL-REASON — propagate to run_job.
		self::$_last_build_fail = $is_parse_fail ? 'llm_parse_failed' : 'llm_failed';
		return null;
	}

	private static function map_reduce( array $chunks, array $pinned_notes = [] ): ?array {
		$has_notes = ! empty( $pinned_notes );
		$groups = array_chunk( $chunks, 6 );
		$mini   = [];
		foreach ( $groups as $idx => $group ) {
			// Pin the priority block to EVERY map call so each mini-skeleton
			// re-weights against user intent — the REDUCE step then preserves it.
			$messages = [
				[ 'role' => 'system', 'content' => BizCity_KG_Skeleton_Prompt::system( $has_notes ) ],
				[ 'role' => 'user',   'content' => self::compose_chunks_message( $group, $pinned_notes ) ],
			];
			// [2026-06-05 Johnny Chu] SKEL-PARSE-ROBUST — json_object mode for map step.
			$resp = bizcity_llm_chat( $messages, [
				'purpose'         => self::LLM_PURPOSE,
				'temperature'     => 0.2,
				'response_format' => [ 'type' => 'json_object' ],
			] );
			if ( empty( $resp['success'] ) ) {
				$map_err = $resp['error'] ?? 'llm_failed';
				error_log( '[bizcity-kg-skeleton] map_reduce llm_error group=' . $idx . ' msg=' . $map_err );
				self::cron_meta_event( 'skeleton_llm_error', [ 'phase' => 'map', 'group' => $idx, 'error' => $map_err ] );
				continue;
			}
			$content = (string) ( $resp['message'] ?? '' );
			$out = BizCity_KG_Skeleton_Prompt::validate( $content );
			if ( $out ) {
				$mini[] = $out;
			} else {
				error_log( '[bizcity-kg-skeleton] map_reduce parse failed group=' . $idx );
				self::cron_meta_event( 'skeleton_llm_parse_error', [ 'phase' => 'map', 'group' => $idx ] );
			}
		}
		if ( ! $mini ) {
			return null;
		}

		// REDUCE pass.
		$reduce_msg = "Dưới đây là " . count( $mini ) . " skeleton con (JSON), hãy gộp:\n\n"
		            . wp_json_encode( $mini, JSON_UNESCAPED_UNICODE );

		$messages = [
			[ 'role' => 'system', 'content' => BizCity_KG_Skeleton_Prompt::reduce_system() ],
			[ 'role' => 'user',   'content' => $reduce_msg ],
		];
		// [2026-06-05 Johnny Chu] SKEL-PARSE-ROBUST — json_object mode for reduce step.
		$resp = bizcity_llm_chat( $messages, [
			'purpose'         => self::LLM_PURPOSE,
			'temperature'     => 0.2,
			'response_format' => [ 'type' => 'json_object' ],
		] );
		if ( empty( $resp['success'] ) ) {
			$reduce_err = $resp['error'] ?? 'llm_failed';
			error_log( '[bizcity-kg-skeleton] reduce llm_error msg=' . $reduce_err );
			self::cron_meta_event( 'skeleton_llm_error', [ 'phase' => 'reduce', 'error' => $reduce_err ] );
			return null;
		}
		$content = (string) ( $resp['message'] ?? '' );
		$out = BizCity_KG_Skeleton_Prompt::validate( $content );
		if ( ! $out ) {
			error_log( '[bizcity-kg-skeleton] reduce parse failed' );
			self::cron_meta_event( 'skeleton_llm_parse_error', [ 'phase' => 'reduce' ] );
			// [2026-06-04 Johnny Chu] SKEL-FAIL-REASON.
			self::$_last_build_fail = 'llm_parse_failed';
		}
		return $out;
	}

	/* ──────────────────────────────────────────────────────────────────
	 *  Chunk loader — F-1 extensible via filter
	 * ──────────────────────────────────────────────────────────────── */

	/**
	 * Load chunks for a notebook from kg_passages, then let downstream
	 * plugins (bizcity-doc, bizcity-companion-notebook, etc.) contribute
	 * their own chunk rows so map-reduce sees the full corpus even when
	 * upload happened outside the canonical kg_passages flow.
	 */
	private static function load_chunks( int $notebook_id ): array {
		global $wpdb;
		$tbl = BizCity_KG_Database::instance()->tbl_passages();

		$max = (int) apply_filters( 'bzkg_skeleton_max_chunks', 200, $notebook_id );
		$max = max( 10, min( 1000, $max ) );

		// Also select filestore index columns so we can fallback to file read
		// when content column is empty (P0.7-LVF filestore path).
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, source_id, content, token_count, file_shard, file_offset, file_length
			   FROM {$tbl}
			  WHERE notebook_id = %d
			  ORDER BY id ASC
			  LIMIT %d",
			$notebook_id, $max
		), ARRAY_A );

		// Diagnostic: log table name + row count so we can trace multisite prefix bugs.
		error_log( sprintf(
			'[bizcity-kg-skeleton] load_chunks tbl=%s nb=%d blog_id=%d rows=%d last_sql=%s',
			$tbl,
			$notebook_id,
			function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 0,
			is_array( $rows ) ? count( $rows ) : -1,
			$wpdb->last_query
		) );

		// Resolve notebook UUID once — needed for filestore reads.
		$notebook_uuid = null;
		if ( is_array( $rows ) && count( $rows ) > 0 ) {
			$nb_tbl = BizCity_KG_Database::instance()->tbl_notebooks();
			$notebook_uuid = $wpdb->get_var( $wpdb->prepare(
				"SELECT uuid FROM {$nb_tbl} WHERE id = %d LIMIT 1",
				$notebook_id
			) );
		}

		$filestore    = null; // lazy-init
		$dbg_filestore = 0;

		$chunks = [];
		if ( is_array( $rows ) ) {
			foreach ( $rows as $r ) {
				$content = (string) ( $r['content'] ?? '' );

				// P0.7-LVF: content column is empty but filestore index is present → read from disk.
				if ( $content === ''
				     && $notebook_uuid
				     && $r['file_offset'] !== null && $r['file_offset'] !== ''
				     && $r['file_length'] !== null && (int) $r['file_length'] > 0
				     && class_exists( 'BizCity_KG_Passage_File_Store' )
				) {
					if ( $filestore === null ) {
						$filestore = new BizCity_KG_Passage_File_Store();
					}
					$body = $filestore->read_body(
						$notebook_uuid,
						(int) $r['file_shard'],
						(int) $r['file_offset'],
						(int) $r['file_length']
					);
					if ( ! is_wp_error( $body ) && is_string( $body ) && $body !== '' ) {
						$content = $body;
						$dbg_filestore++;
					}
				}

				$chunks[] = [
					'source_id' => (int) $r['source_id'],
					'content'   => $content,
					'_tokens'   => (int) $r['token_count'],
				];
			}
		}

		if ( $dbg_filestore > 0 ) {
			error_log( sprintf(
				'[bizcity-kg-skeleton] load_chunks filestore_reads=%d nb=%d',
				$dbg_filestore, $notebook_id
			) );
		}

		// F-1 — let other cortexes (bzdoc_chunks, bcn_*) contribute.
		// Listener signature: function( array $chunks, int $notebook_id ): array
		// where each chunk is { source_id, content, _tokens }.
		$chunks = apply_filters( 'bizcity_kg_skeleton_load_chunks', $chunks, $notebook_id );

		// Hard cap content per chunk (F-11) regardless of source.
		$out        = [];
		$dbg_empty  = 0;
		$dbg_ok     = 0;
		foreach ( (array) $chunks as $c ) {
			if ( ! is_array( $c ) ) { continue; }
			$content = (string) ( $c['content'] ?? '' );
			if ( $content === '' ) { $dbg_empty++; continue; }
			if ( strlen( $content ) > self::CHUNK_CONTENT_MAX_CHARS ) {
				$content = mb_substr( $content, 0, self::CHUNK_CONTENT_MAX_CHARS );
			}
			$tokens = (int) ( $c['_tokens'] ?? 0 );
			if ( $tokens <= 0 ) {
				$tokens = (int) ceil( strlen( $content ) / 4 ); // rough fallback
			}
			$out[] = [
				'source_id' => (int) ( $c['source_id'] ?? 0 ),
				'content'   => $content,
				'_tokens'   => $tokens,
			];
			$dbg_ok++;
			if ( count( $out ) >= $max ) { break; }
		}
		if ( $dbg_empty > 0 || $dbg_ok === 0 ) {
			error_log( sprintf(
				'[bizcity-kg-skeleton] load_chunks filter nb=%d raw=%d empty_content=%d ok=%d (content column may be NULL/empty in passages table)',
				$notebook_id, count( $chunks ), $dbg_empty, $dbg_ok
			) );
		}
		// [2026-06-04 Johnny Chu] SKEL-FAIL-REASON — propagate fine-grained reason to run_job.
		if ( empty( $out ) ) {
			$db_rows_count = is_array( $rows ) ? count( $rows ) : 0;
			self::$_last_build_fail = ( $db_rows_count === 0 ) ? 'no_passages' : 'passages_content_empty';
		}
		return $out;
	}

	private static function compose_chunks_message( array $chunks, array $pinned_notes = [] ): string {
		$parts = [];
		$i     = 0;
		foreach ( $chunks as $c ) {
			$i++;
			$parts[] = "----- CHUNK {$i} (source={$c['source_id']}) -----\n" . $c['content'];
		}

		$body = "Đây là các đoạn nội dung của notebook (đã được trích từ tài liệu nguồn). Hãy phản chiếu thành skeleton JSON theo schema.\n\n"
		       . implode( "\n\n", $parts );

		if ( ! empty( $pinned_notes ) ) {
			$body .= "\n\n## NOTES PINNED BY USER\n"
			       . "(Người dùng đã ghim các ghi chú sau — coi như tín hiệu ý đồ ưu tiên cao nhất)\n\n";
			$n = 0;
			foreach ( $pinned_notes as $note ) {
				$n++;
				$title   = (string) ( $note['title']   ?? '' );
				$content = (string) ( $note['content'] ?? '' );
				$body .= "----- NOTE {$n}";
				if ( $title !== '' ) {
					$body .= ": {$title}";
				}
				$body .= " -----\n" . $content . "\n\n";
			}
		}

		return $body;
	}

	/**
	 * Phase 6.6 S6.6.2 — fetch the latest pinned notes for the notebook from
	 * `bizcity_memory_notes`. Project_id format matches the TwinChat notes
	 * controller (`tc_<notebook_id>`).
	 *
	 * Returns up to 10 most-recent notes ordered newest-first; each row is
	 * trimmed to keep the user message bounded (max 1.5KB per note).
	 *
	 * Filter `bizcity_kg_skeleton_pinned_notes` lets downstream plugins
	 * (e.g. bzdoc) inject their own pinned-note source.
	 *
	 * @return array<int, array{title:string, content:string, note_type:string, created_at:?string}>
	 */
	private static function load_pinned_notes( int $notebook_id ): array {
		global $wpdb;
		$tbl = $wpdb->prefix . 'bizcity_memory_notes';

		$limit = (int) apply_filters( 'bizcity_kg_skeleton_pinned_notes_limit', 10, $notebook_id );
		$limit = max( 1, min( 50, $limit ) );

		$project_id = 'tc_' . $notebook_id;

		$prev = $wpdb->suppress_errors( true );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT title, content, note_type, created_at
			   FROM {$tbl}
			  WHERE project_id = %s
			  ORDER BY (is_starred = 1) DESC, id DESC
			  LIMIT %d",
			$project_id, $limit
		), ARRAY_A );
		$wpdb->suppress_errors( $prev );

		$out = [];
		if ( is_array( $rows ) ) {
			foreach ( $rows as $r ) {
				$content = (string) ( $r['content'] ?? '' );
				if ( strlen( $content ) > 1500 ) {
					$content = mb_substr( $content, 0, 1500 ) . '…';
				}
				$out[] = [
					'title'      => (string) ( $r['title'] ?? '' ),
					'content'    => $content,
					'note_type'  => (string) ( $r['note_type'] ?? '' ),
					'created_at' => $r['created_at'] ?: null,
				];
			}
		}

		return (array) apply_filters(
			'bizcity_kg_skeleton_pinned_notes',
			$out, $notebook_id
		);
	}

	/* ──────────────────────────────────────────────────────────────────
	 *  Persistence
	 * ──────────────────────────────────────────────────────────────── */

	private static function persist( int $notebook_id, array $skeleton, string $trigger_reason = 'ingest' ): void {
		global $wpdb;
		$tbl = BizCity_KG_Database::instance()->tbl_notebooks();

		$current = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT skeleton_version FROM {$tbl} WHERE id = %d", $notebook_id
		) );
		$next_version = $current + 1;
		$built_at     = current_time( 'mysql', true );
		$skeleton_json = wp_json_encode( $skeleton, JSON_UNESCAPED_UNICODE );

		$wpdb->update(
			$tbl,
			[
				'skeleton_json'     => $skeleton_json,
				'skeleton_version'  => $next_version,
				'skeleton_built_at' => $built_at,
				'skeleton_status'   => BizCity_KG_Skeleton_Adapter::STATUS_READY,
			],
			[ 'id' => $notebook_id ],
			[ '%s', '%d', '%s', '%s' ],
			[ '%d' ]
		);
		// [2026-06-04 Johnny Chu] SKEL-FAIL-REASON — clear stale fail detail on success.
		self::clear_fail( $notebook_id );

		// Phase 6.6 S3.2 — push immutable history row (idempotent: ignore if
		// history table doesn't exist yet on legacy blogs).
		self::insert_history_row( $notebook_id, $next_version, $skeleton_json, $built_at, $trigger_reason );

		BizCity_KG_Skeleton_Adapter::flush_cache( $notebook_id );

		do_action( 'bizcity_kg_notebook_skeleton_built',
		           $notebook_id, $skeleton, $next_version );
		// Legacy alias.
		do_action( 'bzkg_notebook_skeleton_built',
		           $notebook_id, $skeleton, $next_version );
	}

	/**
	 * Phase 6.6 S3.2 — append history row to bizcity_kg_skeleton_history.
	 * Best-effort: swallows MySQL errors if table is missing on legacy blogs;
	 * site provisioner will create it on next install pass.
	 */
	private static function insert_history_row(
		int $notebook_id,
		int $version,
		string $skeleton_json,
		string $built_at,
		string $trigger_reason
	): void {
		global $wpdb;
		$tbl = $wpdb->prefix . 'bizcity_kg_skeleton_history';
		$prev_suppress = $wpdb->suppress_errors( true );
		$ok = $wpdb->insert(
			$tbl,
			[
				'notebook_id'    => $notebook_id,
				'version'        => $version,
				'skeleton_json'  => $skeleton_json,
				'trigger_reason' => $trigger_reason !== '' ? $trigger_reason : 'ingest',
				'built_at'       => $built_at,
			],
			[ '%d', '%d', '%s', '%s', '%s' ]
		);
		$wpdb->suppress_errors( $prev_suppress );
		if ( false === $ok && ! empty( $wpdb->last_error ) ) {
			error_log( '[bizcity-kg-skeleton] history insert skipped notebook=' . $notebook_id
			           . ' v=' . $version . ' err=' . $wpdb->last_error );
		}
	}

	/* ──────────────────────────────────────────────────────────────────
	 *  [2026-06-04 Johnny Chu] SKEL-FAIL-REASON — persist last fail detail
	 *  so REST /skeleton/status and probe can surface actionable message.
	 * ──────────────────────────────────────────────────────────────── */

	/**
	 * Store fail reason as a lightweight WP option (autoload=false).
	 * Cleared on successful build via clear_fail().
	 *
	 * @param string $reason  One of: no_passages, passages_content_empty,
	 *                        llm_failed, llm_parse_failed,
	 *                        cost_guard_blocked, exception, build_returned_null.
	 * @param string $detail  Extra context (error message, guard reason, etc.).
	 */
	private static function store_fail( int $notebook_id, string $reason, string $detail = '' ): void {
		$val = wp_json_encode( [
			'reason' => $reason,
			'detail' => mb_substr( $detail, 0, 300 ),
			'ts'     => gmdate( 'Y-m-d\TH:i:s\Z' ),
		] );
		if ( false === $val ) {
			return;
		}
		update_option( self::FAIL_OPT_PREFIX . $notebook_id, $val, false );
	}

	/**
	 * Remove the fail-detail option after a successful build.
	 */
	private static function clear_fail( int $notebook_id ): void {
		delete_option( self::FAIL_OPT_PREFIX . $notebook_id );
	}

	private static function set_status( int $notebook_id, string $status ): void {
		global $wpdb;
		$tbl = BizCity_KG_Database::instance()->tbl_notebooks();
		$wpdb->update(
			$tbl,
			[ 'skeleton_status' => $status ],
			[ 'id' => $notebook_id ],
			[ '%s' ],
			[ '%d' ]
		);
		BizCity_KG_Skeleton_Adapter::flush_cache( $notebook_id );
	}

	/* ──────────────────────────────────────────────────────────────────
	 *  F-4 — Cost-Guard helpers
	 * ──────────────────────────────────────────────────────────────── */

	/** Look up the owner_id of a notebook (defaults to 0 if missing). */
	private static function get_notebook_owner( int $notebook_id ): int {
		global $wpdb;
		$tbl = BizCity_KG_Database::instance()->tbl_notebooks();
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT owner_id FROM {$tbl} WHERE id = %d", $notebook_id
		) );
	}

	/**
	 * Estimate input/output tokens for a completed reflect job and persist
	 * the spend via Cost_Guard so daily caps + per-user quotas stay accurate.
	 *
	 * Input tokens  ≈ Σ chunk token_count loaded from kg_passages.
	 * Output tokens ≈ JSON byte length / 4 (cheap heuristic, errs high).
	 */
	private static function record_skeleton_usage( int $notebook_id, array $skeleton ): void {
		global $wpdb;
		$tbl    = BizCity_KG_Database::instance()->tbl_passages();
		$in_tok = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE( SUM( token_count ), 0 )
			   FROM {$tbl} WHERE notebook_id = %d", $notebook_id
		) );
		$out_tok = (int) ceil( strlen( wp_json_encode( $skeleton ) ) / 4 );

		BizCity_KG_Cost_Guard::instance()->record_usage( [
			'user_id'       => self::get_notebook_owner( $notebook_id ),
			'operation'     => self::COST_GUARD_OPERATION,
			'notebook_id'   => $notebook_id,
			'input_tokens'  => $in_tok,
			'output_tokens' => $out_tok,
			'meta'          => [
				'reference'        => 'PHASE-0-RULE-SKELETON F-4',
				'skeleton_version' => (int) ( $skeleton['meta']['schema_version'] ?? 1 ),
				'source_count'     => (int) ( $skeleton['meta']['source_count'] ?? 0 ),
				'chunk_count'      => (int) ( $skeleton['meta']['chunk_count'] ?? 0 ),
			],
		] );
	}

	/* ──────────────────────────────────────────────────────────────────
	 *  Ingest listeners
	 * ──────────────────────────────────────────────────────────────── */

	/** TwinChat ingest finished — payload: ($scope_id=$notebook_id, $user_id, $result, $payload). */
	public static function on_twinchat_after_ingest( $scope_id, $user_id, $result, $payload ): void {
		$nb = (int) $scope_id;
		if ( $nb > 0 ) {
			self::trigger_now( $nb, 'ingest' );
		}
	}

	/** BCN source added — payload: ($source_id, $project_id=$notebook_id). */
	public static function on_bcn_source_added( $source_id, $project_id ): void {
		$nb = (int) $project_id;
		if ( $nb > 0 ) {
			self::trigger_now( $nb, 'ingest' );
		}
	}

	/**
	 * Phase 6.6 S1.5 — canonical kg_sources insert. Hook payload shape can vary,
	 * accept both ($source_id, $scope_id) and ($source_id, $source_row[]).
	 */
	public static function on_kg_source_inserted( $source_id, $context = null ): void {
		$nb = 0;
		if ( is_array( $context ) ) {
			if ( isset( $context['scope_type'] ) && $context['scope_type'] === 'notebook' ) {
				$nb = (int) ( $context['scope_id'] ?? 0 );
			}
			if ( ! $nb && isset( $context['notebook_id'] ) ) {
				$nb = (int) $context['notebook_id'];
			}
		} elseif ( is_numeric( $context ) ) {
			$nb = (int) $context;
		}
		if ( $nb > 0 ) {
			self::trigger_now( $nb, 'ingest' );
		}
	}

	/**
	 * Phase 6.6 S1.5 — bizcity-doc source upload completed.
	 * Payload: ($source_id, $notebook_id|$doc_meta).
	 */
	public static function on_doc_source_added( $source_id, $context = null ): void {
		self::on_kg_source_inserted( $source_id, $context );
	}

	/**
	 * Phase 6.6 S1.5 — BCN multi-file batch upload finished.
	 * Payload: ($project_id, $summary[]).
	 */
	public static function on_bcn_batch_upload_complete( $project_id, $summary = null ): void {
		$nb = (int) $project_id;
		if ( $nb > 0 ) {
			self::trigger_now( $nb, 'ingest' );
		}
	}

	/**
	 * Phase 6.6 S2.2 — Save-to-notes trigger. Fired by the TwinChat notes
	 * controller after a chat snippet is pinned to bizcity_memory_notes.
	 *
	 * @param int $notebook_id
	 * @param int $user_id
	 * @param int $note_id
	 */
	public static function on_notes_pinned( $notebook_id, $user_id = 0, $note_id = 0 ): void {
		$nb = (int) $notebook_id;
		if ( $nb <= 0 ) {
			return;
		}
		self::trigger_now( $nb, 'notes_pinned' );
	}

	/* ──────────────────────────────────────────────────────────────────
	 *  Phase 6.6 S1.3 — Cron meta evidence helpers (R-CRON-META)
	 *
	 *  No-ops when the current request is not inside a registered
	 *  BizCity_Cron_Manager run; safe to call from any context.
	 * ──────────────────────────────────────────────────────────────── */

	private static function cron_meta_note( array $patch ): void {
		if ( ! class_exists( 'BizCity_Cron_Manager' ) ) { return; }
		try {
			BizCity_Cron_Manager::instance()->note( $patch );
		} catch ( \Throwable $e ) {
			// Best-effort; never break the worker.
		}
	}

	private static function cron_meta_event( string $name, array $data = [] ): void {
		if ( ! class_exists( 'BizCity_Cron_Manager' ) ) { return; }
		try {
			BizCity_Cron_Manager::instance()->note_event( $name, $data );
		} catch ( \Throwable $e ) {
			// Best-effort.
		}
	}

	/**
	 * F-2 — legacy chunks pipeline payload lacks notebook_id, so we
	 * resolve it ourselves via kg_passages.source_id ↔ legacy_source_id.
	 *
	 * Payload shape: { cortex, legacy_source_id, legacy_source_table, legacy_chunks_table }
	 */
	public static function on_legacy_chunks_persisted( $args ): void {
		if ( ! is_array( $args ) ) { return; }
		$cortex = isset( $args['cortex'] ) ? (string) $args['cortex'] : '';
		$lsid   = isset( $args['legacy_source_id'] ) ? (int) $args['legacy_source_id'] : 0;
		$ltable = isset( $args['legacy_source_table'] ) ? (string) $args['legacy_source_table'] : '';
		self::mark_dirty_by_source( $cortex, $lsid, $ltable );
	}

	/**
	 * Resolve a legacy source id to its notebook(s) and mark dirty.
	 * Allows downstream cortexes to register a custom resolver via filter
	 * `bizcity_kg_skeleton_resolve_legacy_source`.
	 *
	 * @param string $cortex            e.g. 'bzdoc', 'bcn'
	 * @param int    $legacy_source_id  id within the legacy table
	 * @param string $legacy_source_table optional table hint
	 */
	public static function mark_dirty_by_source( string $cortex, int $legacy_source_id, string $legacy_source_table = '' ): void {
		if ( $legacy_source_id <= 0 ) {
			return;
		}

		// Default resolver: look up canonical kg_sources rows that wrap
		// this legacy source and treat their scope_id as notebook id when
		// scope_type='notebook'.
		$notebook_ids = [];
		try {
			$db   = BizCity_KG_Database::instance();
			$tbl  = method_exists( $db, 'tbl_sources' ) ? $db->tbl_sources() : '';
			if ( $tbl ) {
				global $wpdb;
				$rows = $wpdb->get_col( $wpdb->prepare(
					"SELECT DISTINCT scope_id FROM {$tbl}
					  WHERE origin_plugin = %s
					    AND origin_id     = %d
					    AND scope_type    = 'notebook'
					    AND scope_id     <> ''",
					$cortex, $legacy_source_id
				) );
				if ( is_array( $rows ) ) {
					foreach ( $rows as $nb ) {
						$nb = (int) $nb;
						if ( $nb > 0 ) { $notebook_ids[] = $nb; }
					}
				}
			}
		} catch ( \Throwable $e ) {
			// Swallow — resolver is best-effort; filter is the escape hatch.
		}

		$notebook_ids = (array) apply_filters(
			'bizcity_kg_skeleton_resolve_legacy_source',
			$notebook_ids,
			$cortex,
			$legacy_source_id,
			$legacy_source_table
		);

		foreach ( array_unique( array_map( 'intval', $notebook_ids ) ) as $nb ) {
			if ( $nb > 0 ) {
				BizCity_KG_Skeleton_Adapter::mark_dirty( $nb );
			}
		}
	}
}

// Back-compat alias — PHASE-0-RULE-NAMESPACE §2.2.
if ( ! class_exists( 'BZKG_Notebook_Skeleton_Service' ) ) {
	class_alias( 'BizCity_KG_Skeleton_Service', 'BZKG_Notebook_Skeleton_Service' );
}
