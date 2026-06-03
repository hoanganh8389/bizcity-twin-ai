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
	const SINGLE_PASS_TOKEN_BUDGET = 100000;
	const LLM_PURPOSE              = 'executor';
	const CHUNK_CONTENT_MAX_CHARS  = 3000; // F-11

	/** F-4 — Cost-Guard operation id used when recording skeleton-reflect LLM spend. */
	const COST_GUARD_OPERATION     = 'skeleton';

	/** Action Scheduler group. */
	const AS_GROUP = 'bizcity-kg-skeleton';

	/** Transient lock TTL (seconds) — F-3. */
	const LOCK_TTL = 180;

	public static function bind(): void {
		add_action( self::CRON_HOOK, [ __CLASS__, 'run_job' ], 10, 1 );

		// Ingest listeners — keep both canonical + legacy hook names.
		add_action( 'bizcity_twinchat_after_ingest', [ __CLASS__, 'on_twinchat_after_ingest' ], 10, 4 );
		add_action( 'bcn_source_added',              [ __CLASS__, 'on_bcn_source_added' ],     10, 2 );

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

	public static function schedule_rebuild( int $notebook_id ): void {
		if ( $notebook_id <= 0 ) {
			return;
		}
		$delay = (int) apply_filters(
			'bzkg_skeleton_debounce_seconds',
			self::DEBOUNCE_DEFAULT,
			$notebook_id
		);
		$delay = max( 5, $delay );

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

		try {
			self::set_status( $notebook_id, BizCity_KG_Skeleton_Adapter::STATUS_BUILDING );

			// F-4 — Cost-Guard pre-check (skip if class not loaded or guard disabled).
			if ( class_exists( 'BizCity_KG_Cost_Guard' ) ) {
				$owner_id = self::get_notebook_owner( $notebook_id );
				$guard    = BizCity_KG_Cost_Guard::instance()->can_extract( $owner_id, 1 );
				if ( is_wp_error( $guard ) ) {
					self::set_status( $notebook_id, BizCity_KG_Skeleton_Adapter::STATUS_FAILED );
					error_log( '[bizcity-kg-skeleton] cost-guard blocked notebook=' . $notebook_id
					           . ' owner=' . $owner_id . ' reason=' . $guard->get_error_message() );
					return;
				}
			}

			$skeleton = self::build( $notebook_id );
			if ( $skeleton ) {
				self::persist( $notebook_id, $skeleton );

				// F-4 — Record approximate LLM spend after a successful reflect.
				if ( class_exists( 'BizCity_KG_Cost_Guard' ) ) {
					self::record_skeleton_usage( $notebook_id, $skeleton );
				}
			} else {
				self::set_status( $notebook_id, BizCity_KG_Skeleton_Adapter::STATUS_FAILED );
			}
		} catch ( \Throwable $e ) {
			self::set_status( $notebook_id, BizCity_KG_Skeleton_Adapter::STATUS_FAILED );
			error_log( '[bizcity-kg-skeleton] ' . $e->getMessage() );
		} finally {
			delete_transient( $lock_key );
		}
	}

	/* ──────────────────────────────────────────────────────────────────
	 *  Build pipeline
	 * ──────────────────────────────────────────────────────────────── */

	private static function build( int $notebook_id ): ?array {
		$chunks = self::load_chunks( $notebook_id );
		if ( ! $chunks ) {
			return null;
		}

		$total_tokens = 0;
		foreach ( $chunks as $c ) {
			$total_tokens += (int) ( $c['_tokens'] ?? 0 );
		}

		if ( $total_tokens <= self::SINGLE_PASS_TOKEN_BUDGET ) {
			return self::single_pass( $chunks );
		}
		return self::map_reduce( $chunks );
	}

	private static function single_pass( array $chunks ): ?array {
		$messages = [
			[ 'role' => 'system', 'content' => BizCity_KG_Skeleton_Prompt::system() ],
			[ 'role' => 'user',   'content' => self::compose_chunks_message( $chunks ) ],
		];

		for ( $attempt = 1; $attempt <= 2; $attempt++ ) {
			$resp = bizcity_llm_chat( $messages, [
				'purpose'     => self::LLM_PURPOSE,
				'temperature' => 0.2,
			] );
			$content = (string) ( $resp['content'] ?? '' );
			$out = BizCity_KG_Skeleton_Prompt::validate( $content );
			if ( $out ) { return $out; }
		}
		return null;
	}

	private static function map_reduce( array $chunks ): ?array {
		$groups = array_chunk( $chunks, 6 );
		$mini   = [];
		foreach ( $groups as $group ) {
			$messages = [
				[ 'role' => 'system', 'content' => BizCity_KG_Skeleton_Prompt::system() ],
				[ 'role' => 'user',   'content' => self::compose_chunks_message( $group ) ],
			];
			$resp = bizcity_llm_chat( $messages, [
				'purpose'     => self::LLM_PURPOSE,
				'temperature' => 0.2,
			] );
			$out = BizCity_KG_Skeleton_Prompt::validate( (string) ( $resp['content'] ?? '' ) );
			if ( $out ) { $mini[] = $out; }
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
		$resp = bizcity_llm_chat( $messages, [
			'purpose'     => self::LLM_PURPOSE,
			'temperature' => 0.2,
		] );
		return BizCity_KG_Skeleton_Prompt::validate( (string) ( $resp['content'] ?? '' ) );
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

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, source_id, content, token_count
			   FROM {$tbl}
			  WHERE notebook_id = %d
			  ORDER BY id ASC
			  LIMIT %d",
			$notebook_id, $max
		), ARRAY_A );

		$chunks = [];
		if ( is_array( $rows ) ) {
			foreach ( $rows as $r ) {
				$chunks[] = [
					'source_id' => (int) $r['source_id'],
					'content'   => (string) $r['content'],
					'_tokens'   => (int) $r['token_count'],
				];
			}
		}

		// F-1 — let other cortexes (bzdoc_chunks, bcn_*) contribute.
		// Listener signature: function( array $chunks, int $notebook_id ): array
		// where each chunk is { source_id, content, _tokens }.
		$chunks = apply_filters( 'bizcity_kg_skeleton_load_chunks', $chunks, $notebook_id );

		// Hard cap content per chunk (F-11) regardless of source.
		$out = [];
		foreach ( (array) $chunks as $c ) {
			if ( ! is_array( $c ) ) { continue; }
			$content = (string) ( $c['content'] ?? '' );
			if ( $content === '' ) { continue; }
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
			if ( count( $out ) >= $max ) { break; }
		}
		return $out;
	}

	private static function compose_chunks_message( array $chunks ): string {
		$parts = [];
		$i     = 0;
		foreach ( $chunks as $c ) {
			$i++;
			$parts[] = "----- CHUNK {$i} (source={$c['source_id']}) -----\n" . $c['content'];
		}
		return "Đây là các đoạn nội dung của notebook (đã được trích từ tài liệu nguồn). Hãy phản chiếu thành skeleton JSON theo schema.\n\n"
		     . implode( "\n\n", $parts );
	}

	/* ──────────────────────────────────────────────────────────────────
	 *  Persistence
	 * ──────────────────────────────────────────────────────────────── */

	private static function persist( int $notebook_id, array $skeleton ): void {
		global $wpdb;
		$tbl = BizCity_KG_Database::instance()->tbl_notebooks();

		$current = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT skeleton_version FROM {$tbl} WHERE id = %d", $notebook_id
		) );
		$next_version = $current + 1;

		$wpdb->update(
			$tbl,
			[
				'skeleton_json'     => wp_json_encode( $skeleton, JSON_UNESCAPED_UNICODE ),
				'skeleton_version'  => $next_version,
				'skeleton_built_at' => current_time( 'mysql', true ),
				'skeleton_status'   => BizCity_KG_Skeleton_Adapter::STATUS_READY,
			],
			[ 'id' => $notebook_id ],
			[ '%s', '%d', '%s', '%s' ],
			[ '%d' ]
		);

		BizCity_KG_Skeleton_Adapter::flush_cache( $notebook_id );

		do_action( 'bizcity_kg_notebook_skeleton_built',
		           $notebook_id, $skeleton, $next_version );
		// Legacy alias.
		do_action( 'bzkg_notebook_skeleton_built',
		           $notebook_id, $skeleton, $next_version );
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
			BizCity_KG_Skeleton_Adapter::mark_dirty( $nb );
		}
	}

	/** BCN source added — payload: ($source_id, $project_id=$notebook_id). */
	public static function on_bcn_source_added( $source_id, $project_id ): void {
		$nb = (int) $project_id;
		if ( $nb > 0 ) {
			BizCity_KG_Skeleton_Adapter::mark_dirty( $nb );
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
