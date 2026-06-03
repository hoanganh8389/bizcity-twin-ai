<?php
/**
 * BizCity Twin AI — Diagnostics & Multisite Schema Repair
 *
 * Roadmap-bound utility class. Single source of truth for tracing, logging,
 * SQL guards, cron sweeps and schema repair on multisite shards. Add new
 * audits HERE — do NOT scatter ad-hoc scripts in /tools/.
 *
 * Bug roadmap addressed:
 *   • PHASE-0.6.6 — orphan cleanup cron crashing on shards without KG schema
 *     (see slave9/wp_1453, slave2/wp_1157 logs 10-May-2026).
 *   • PHASE-0.21 (R-VFS) — `.bin` filestore presence vs schema_version skew.
 *   • PHASE-0.36 — TwinBrain preflight: confirm 4 core KG tables exist on
 *     every blog *before* the central brain dispatches sub-agents.
 *
 * Surfaces:
 *   1. WP-CLI:  `wp bizcity diag <command> [--network] [--blog=<id>]`
 *   2. Browser: `/wp-content/plugins/bizcity-twin-ai/tools/class-diagnostics.php?cmd=audit`
 *               (admin + WP_DEBUG only)
 *   3. PHP:     `BizCity_Diagnostics::instance()->audit_blog( $blog_id )`
 *
 * Commands (CLI / ?cmd= / method):
 *   • audit              — schema presence per blog (KG core, vector store, brain).
 *   • repair             — force run BizCity_KG_Database::create_tables() on blog.
 *   • clean-cron         — unschedule stale cron hooks on blogs missing required schema.
 *   • bin-store          — stat .bin file count vs kg_passages row count.
 *   • brain-preflight    — verify TwinBrain (PHASE-0.36) can run on this blog.
 *   • brain-smoke        — emit a fixture turn end-to-end (no LLM).
 *   • brain-replay       — re-emit events of a trace_id from event stream.
 *   • brain-taxonomy     — assert the 6 brain_* + system_diagnostic event types are registered.
 *   • brain-view         — ensure & introspect the bizcity_brain_turns VIEW.
 *   • history-smoke      — sanity-check bzdoc_documents row count for {user, notebook}.
 *   • orphan-dry-run     — call cleanup-service in dry mode (no writes).
 *   • progress-report    — §7 roadmap tracker, maps each TBR.x sprint → done/partial/not-started.
 *   • hermes-port-audit  — §7 audit which Hermes UX patterns (H1..H9) are realised in code.
 *   • skeleton-audit     — snapshot health of skeleton lifecycle (schema/status/queue/stuck).
 *   • skeleton-rebuild   — re-queue skeleton rebuild for --notebook=N or --stuck.
 *   • skeleton-roadmap   — phase scorecard (PASS/FAIL/SKIP) for all PHASE-0-RULE-SKELETON milestones.
 *   • skeleton-fix       — bundled fixer: clear orphan locks + requeue stuck/failed + flush cache.
 *   • skeleton-logs      — tail recent reflect-job logs (AS + recent built notebooks).
 *
 * Multisite walk:
 *   --network applies to all blogs. Defaults to current blog.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Tools
 * @since      2026-05-10
 */

defined( 'ABSPATH' ) || ( function_exists( 'bizcity_diag_browser_bootstrap' ) ? null : bizcity_diag_browser_bootstrap() );

if ( ! function_exists( 'bizcity_diag_browser_bootstrap' ) ) {
	function bizcity_diag_browser_bootstrap() {
		// Browser entry: bootstrap WP if accessed via direct URL.
		$wp_load = dirname( __DIR__, 4 ) . '/wp-load.php';
		if ( file_exists( $wp_load ) ) {
			require $wp_load;
			if ( ! current_user_can( 'manage_options' ) || ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
				http_response_code( 403 );
				exit( 'forbidden — manage_options + WP_DEBUG required' );
			}
			header( 'Content-Type: text/plain; charset=utf-8' );
		}
	}
}

if ( class_exists( 'BizCity_Diagnostics' ) ) {
	return;
}

final class BizCity_Diagnostics {

	const VERSION = '0.36.0';

	const REQUIRED_KG_TABLES = [
		'bizcity_kg_passages',
		'bizcity_kg_passage_entities',
		'bizcity_kg_passage_relations',
		'bizcity_kg_triplet_queue',
		'bizcity_kg_entities',
		'bizcity_kg_relations',
		'bizcity_kg_notebooks',
		'bizcity_kg_sources',
	];

	const STALE_CRON_HOOKS = [
		'bizcity_kg_orphan_cleanup_weekly',
		'bizcity_kg_learning_sweep',
	];

	/** @var self|null */
	private static $instance = null;

	/** @var string[] in-memory log buffer (timestamped). */
	private $log = [];

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->register_cli();
		}
		return self::$instance;
	}

	private function register_cli(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
			\WP_CLI::add_command( 'bizcity diag', [ $this, 'cli_dispatch' ] );
		}
	}

	/* ============================================================
	 * Logging — buffered + WP error_log + bizcity_twin_event_stream
	 * ============================================================ */

	public function log( string $level, string $tag, $data = null ): void {
		$line = sprintf(
			'[%s] [%s] [blog:%d] [%s] %s',
			gmdate( 'Y-m-d H:i:s' ),
			strtoupper( $level ),
			(int) get_current_blog_id(),
			$tag,
			is_scalar( $data ) ? (string) $data : wp_json_encode( $data, JSON_UNESCAPED_UNICODE )
		);
		$this->log[] = $line;
		if ( $level === 'error' || $level === 'warn' ) {
			error_log( '[BizCity-Diag] ' . $line );
		}
		// Feed Twin Event Stream when available (R-EVT-1 compliant).
		if ( class_exists( 'BizCity_Twin_Event_Bus' ) && class_exists( 'BizCity_Twin_Data_Contract' ) ) {
			try {
				BizCity_Twin_Event_Bus::dispatch( 'system_diagnostic', [
					'trace_id' => 'diag_' . gmdate( 'YmdHis' ),
					'level'    => $level,
					'tag'      => $tag,
					'data'     => is_scalar( $data ) ? (string) $data : $data,
				] );
			} catch ( \Throwable $e ) {
				// Bus not booted yet during early cron — silent.
			}
		}
	}

	public function flush_log(): array {
		$out = $this->log;
		$this->log = [];
		return $out;
	}

	/* ============================================================
	 * §1 — Audit (read-only)
	 * ============================================================ */

	/**
	 * Audit a single blog's schema presence.
	 *
	 * @return array {
	 *   blog_id, prefix, kg_present (string[]→bool), kg_db_version,
	 *   bin_dir, bin_count, missing_tables, has_brain_module
	 * }
	 */
	public function audit_blog( int $blog_id = 0 ): array {
		global $wpdb;
		$switched = false;
		if ( $blog_id > 0 && is_multisite() && get_current_blog_id() !== $blog_id ) {
			switch_to_blog( $blog_id );
			$switched = true;
		}
		$bid    = (int) get_current_blog_id();
		$prefix = $wpdb->prefix;

		$kg_present = [];
		$missing    = [];
		$prev_supp  = $wpdb->suppress_errors( true );
		foreach ( self::REQUIRED_KG_TABLES as $logical ) {
			$tbl   = $prefix . $logical;
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) );
			$exists = ( $found === $tbl );
			$kg_present[ $logical ] = $exists;
			if ( ! $exists ) {
				$missing[] = $logical;
			}
		}
		$wpdb->suppress_errors( $prev_supp );

		$out = [
			'blog_id'        => $bid,
			'prefix'         => $prefix,
			'kg_present'     => $kg_present,
			'missing_tables' => $missing,
			'kg_db_version'  => get_option( 'bizcity_kg_db_version', '' ),
			'bin_dir'        => $this->bin_dir_path(),
			'bin_count'      => $this->count_bin_files(),
			'has_brain_module' => is_dir( BIZCITY_TWIN_AI_DIR . 'core/twinbrain' ),
			'cron_scheduled' => $this->cron_status(),
		];

		if ( $switched ) restore_current_blog();
		return $out;
	}

	public function audit_network(): array {
		if ( ! is_multisite() ) {
			return [ 'multisite' => false, 'blogs' => [ $this->audit_blog( 0 ) ] ];
		}
		$blogs = get_sites( [ 'fields' => 'ids', 'number' => 0 ] );
		$rows  = [];
		foreach ( (array) $blogs as $bid ) {
			$rows[] = $this->audit_blog( (int) $bid );
		}
		return [ 'multisite' => true, 'count' => count( $rows ), 'blogs' => $rows ];
	}

	/* ============================================================
	 * §2 — Repair (writes)
	 * ============================================================ */

	/**
	 * Force-run BizCity_KG_Database::create_tables() on target blog. Idempotent.
	 */
	public function repair_blog( int $blog_id = 0 ): array {
		$switched = false;
		if ( $blog_id > 0 && is_multisite() && get_current_blog_id() !== $blog_id ) {
			switch_to_blog( $blog_id );
			$switched = true;
		}
		$bid = (int) get_current_blog_id();

		if ( ! class_exists( 'BizCity_KG_Database' ) ) {
			$this->log( 'error', 'repair.no_class', [ 'blog' => $bid ] );
			if ( $switched ) restore_current_blog();
			return [ 'ok' => false, 'reason' => 'BizCity_KG_Database not loaded' ];
		}
		// Force re-migration: clear version then re-instantiate.
		delete_option( 'bizcity_kg_db_version' );
		$db = BizCity_KG_Database::instance();   // triggers maybe_create_tables()
		// Recheck.
		$audit = $this->audit_blog( 0 );
		$ok    = empty( $audit['missing_tables'] );
		$this->log( $ok ? 'info' : 'warn', 'repair.done', [
			'blog'     => $bid,
			'ok'       => $ok,
			'missing'  => $audit['missing_tables'],
		] );
		if ( $switched ) restore_current_blog();
		return [
			'ok'       => $ok,
			'blog_id'  => $bid,
			'missing'  => $audit['missing_tables'],
			'version'  => get_option( 'bizcity_kg_db_version', '' ),
		];
	}

	public function repair_network(): array {
		if ( ! is_multisite() ) return [ 'rows' => [ $this->repair_blog( 0 ) ] ];
		$rows = [];
		foreach ( (array) get_sites( [ 'fields' => 'ids', 'number' => 0 ] ) as $bid ) {
			$rows[] = $this->repair_blog( (int) $bid );
		}
		return [ 'multisite' => true, 'rows' => $rows ];
	}

	/* ============================================================
	 * §3 — Cron hygiene
	 * ============================================================ */

	/**
	 * Unschedule cron hooks on blogs that don't have the schema they need.
	 * Prevents "Table doesn't exist" warnings on shards where KG was never set up.
	 *
	 * Roadmap: this is the runtime-side counterpart to the preflight guard added
	 * in BizCity_KG_Cleanup_Service::kg_tables_present() (HOTFIX 2026-05-10).
	 */
	public function clean_cron_blog( int $blog_id = 0 ): array {
		$switched = false;
		if ( $blog_id > 0 && is_multisite() && get_current_blog_id() !== $blog_id ) {
			switch_to_blog( $blog_id );
			$switched = true;
		}
		$bid     = (int) get_current_blog_id();
		$audit   = $this->audit_blog( 0 );
		$missing = ! empty( $audit['missing_tables'] );
		$cleared = [];
		if ( $missing ) {
			foreach ( self::STALE_CRON_HOOKS as $hook ) {
				if ( wp_next_scheduled( $hook ) ) {
					wp_clear_scheduled_hook( $hook );
					$cleared[] = $hook;
				}
			}
			$this->log( 'warn', 'cron.cleared_stale', [ 'blog' => $bid, 'hooks' => $cleared ] );
		}
		if ( $switched ) restore_current_blog();
		return [ 'blog_id' => $bid, 'had_missing_schema' => $missing, 'cleared' => $cleared ];
	}

	public function clean_cron_network(): array {
		if ( ! is_multisite() ) return [ 'rows' => [ $this->clean_cron_blog( 0 ) ] ];
		$rows = [];
		foreach ( (array) get_sites( [ 'fields' => 'ids', 'number' => 0 ] ) as $bid ) {
			$rows[] = $this->clean_cron_blog( (int) $bid );
		}
		return [ 'multisite' => true, 'rows' => $rows ];
	}

	private function cron_status(): array {
		$out = [];
		foreach ( self::STALE_CRON_HOOKS as $hook ) {
			$ts = wp_next_scheduled( $hook );
			$out[ $hook ] = $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : 'not_scheduled';
		}
		return $out;
	}

	/* ============================================================
	 * §4 — Vector .bin filestore (R-VFS)
	 * ============================================================ */

	private function bin_dir_path(): string {
		$ud = wp_upload_dir();
		// Per PHASE-0.21 §2.2: wp-content/uploads/sites/{blog_id}/bizcity-kg/ on multisite,
		// fallback wp-content/uploads/bizcity-kg/ on single-site.
		return trailingslashit( $ud['basedir'] ) . 'bizcity-kg';
	}

	private function count_bin_files(): int {
		$dir = $this->bin_dir_path();
		if ( ! is_dir( $dir ) ) return 0;
		$n = 0;
		$rdi = new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS );
		foreach ( new \RecursiveIteratorIterator( $rdi ) as $f ) {
			if ( $f->isFile() && substr( $f->getFilename(), -4 ) === '.bin' ) $n++;
		}
		return $n;
	}

	public function bin_store_audit_blog( int $blog_id = 0 ): array {
		global $wpdb;
		$switched = false;
		if ( $blog_id > 0 && is_multisite() && get_current_blog_id() !== $blog_id ) {
			switch_to_blog( $blog_id );
			$switched = true;
		}
		$bid       = (int) get_current_blog_id();
		$row_count = 0;
		if ( class_exists( 'BizCity_KG_Database' ) ) {
			$db  = BizCity_KG_Database::instance();
			$tbl = method_exists( $db, 'tbl_source_chunks' ) ? $db->tbl_source_chunks() : $db->tbl_passages();
			$prev = $wpdb->suppress_errors( true );
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) );
			if ( $found === $tbl ) {
				$row_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl}" );
			}
			$wpdb->suppress_errors( $prev );
		}
		$bin_count = $this->count_bin_files();
		$out = [
			'blog_id'   => $bid,
			'bin_dir'   => $this->bin_dir_path(),
			'bin_count' => $bin_count,
			'rows'      => $row_count,
			'skew'      => $row_count - $bin_count,
		];
		if ( $switched ) restore_current_blog();
		return $out;
	}

	/* ============================================================
	 * §5 — TwinBrain preflight (PHASE-0.36)
	 * ============================================================ */

	/**
	 * Verify TwinBrain (Não tổng) can run on this blog.
	 * Checks: KG schema, llm-router gateway reachable, brain module loaded,
	 * event-stream table exists.
	 */
	public function brain_preflight( int $blog_id = 0 ): array {
		$switched = false;
		if ( $blog_id > 0 && is_multisite() && get_current_blog_id() !== $blog_id ) {
			switch_to_blog( $blog_id );
			$switched = true;
		}
		global $wpdb;
		$bid    = (int) get_current_blog_id();
		$audit  = $this->audit_blog( 0 );
		$checks = [];

		$checks['kg_schema_ok']       = empty( $audit['missing_tables'] );
		$checks['brain_module_loaded'] = class_exists( 'BizCity_TwinBrain_Runtime' );
		$checks['event_bus_loaded']   = class_exists( 'BizCity_Twin_Event_Bus' );
		$checks['llm_client_loaded']  = class_exists( 'BizCity_LLM_Client' );

		// Event stream table.
		$prev = $wpdb->suppress_errors( true );
		$evt  = $wpdb->prefix . 'bizcity_twin_event_stream';
		$checks['event_stream_table'] = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $evt ) ) === $evt );
		$wpdb->suppress_errors( $prev );

		$ok = ! in_array( false, $checks, true );
		$this->log( $ok ? 'info' : 'warn', 'brain.preflight', [ 'blog' => $bid, 'checks' => $checks ] );
		if ( $switched ) restore_current_blog();
		return [ 'blog_id' => $bid, 'ok' => $ok, 'checks' => $checks, 'missing_tables' => $audit['missing_tables'] ];
	}

	/**
	 * Run a TwinBrain end-to-end smoke turn (Wave 0 stubs included).
	 * Counts events emitted to bizcity_twin_event_stream during the turn.
	 */
	public function brain_smoke_turn( string $prompt, int $blog_id = 0 ): array {
		$switched = false;
		if ( $blog_id > 0 && is_multisite() && get_current_blog_id() !== $blog_id ) {
			switch_to_blog( $blog_id ); $switched = true;
		}
		if ( ! class_exists( 'BizCity_TwinBrain_Runtime' ) ) {
			if ( $switched ) restore_current_blog();
			return [ 'ok' => false, 'reason' => 'twinbrain not loaded' ];
		}
		global $wpdb;
		$tbl = $wpdb->prefix . 'bizcity_twin_event_stream';
		$prev_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl}" );

		$rt    = BizCity_TwinBrain_Runtime::instance();
		$start = $rt->start_turn( $prompt, [ 'user_id' => get_current_user_id() ] );
		$done  = $rt->complete_turn( $start['trace_id'], $prompt, $start['candidates'], $start['tool_candidates'] ?? [] );

		$post_count  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl}" );
		$emitted     = max( 0, $post_count - $prev_count );
		$ok          = $emitted >= 3 && ! empty( $start['trace_id'] );
		$this->log( $ok ? 'info' : 'error', 'brain.smoke', [
			'trace_id' => $start['trace_id'] ?? '',
			'emitted'  => $emitted,
			'k'        => count( $start['candidates'] ?? [] ),
		] );
		if ( $switched ) restore_current_blog();
		return [
			'ok'             => $ok,
			'trace_id'       => $start['trace_id'] ?? '',
			'events_emitted' => $emitted,
			'k'              => count( $start['candidates'] ?? [] ),
			'synthesis_len'  => isset( $done['synthesis']['answer_md'] ) ? strlen( (string) $done['synthesis']['answer_md'] ) : 0,
		];
	}

	/**
	 * Replay the event stream of a brain turn (chronological, with delta ms).
	 */
	public function brain_replay( string $trace_id, int $blog_id = 0 ): array {
		$switched = false;
		if ( $blog_id > 0 && is_multisite() && get_current_blog_id() !== $blog_id ) {
			switch_to_blog( $blog_id ); $switched = true;
		}
		global $wpdb;
		$tbl  = $wpdb->prefix . 'bizcity_twin_event_stream';
		$prev = $wpdb->suppress_errors( true );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT event_type, created_epoch_ms FROM {$tbl} WHERE trace_id=%s ORDER BY created_epoch_ms ASC LIMIT 500",
			$trace_id
		), ARRAY_A );
		$wpdb->suppress_errors( $prev );
		$out = []; $base = 0;
		foreach ( (array) $rows as $i => $r ) {
			$ms = (int) $r['created_epoch_ms'];
			if ( $i === 0 ) $base = $ms;
			$out[] = [
				'event'    => $r['event_type'],
				'delta_ms' => $ms - $base,
			];
		}
		if ( $switched ) restore_current_blog();
		return [ 'ok' => ! empty( $out ), 'trace_id' => $trace_id, 'events' => $out, 'count' => count( $out ) ];
	}

	/**
	 * Verify the 4 TwinBrain/Diagnostics event_types are registered in taxonomy.
	 */
	public function brain_event_taxonomy_check(): array {
		$required = [ 'brain_perspective_selected', 'brain_perspective_answer', 'brain_tool_intent', 'system_diagnostic' ];
		if ( ! class_exists( 'BizCity_Twin_Data_Contract' ) ) {
			return [ 'ok' => false, 'reason' => 'data contract not loaded', 'required' => $required ];
		}
		$taxonomy = method_exists( 'BizCity_Twin_Data_Contract', 'event_taxonomy' )
			? (array) BizCity_Twin_Data_Contract::event_taxonomy()
			: [];
		$missing = [];
		foreach ( $required as $k ) {
			if ( ! isset( $taxonomy[ $k ] ) ) $missing[] = $k;
		}
		$ok = empty( $missing );
		$this->log( $ok ? 'info' : 'warn', 'brain.taxonomy', [ 'missing' => $missing ] );
		$ver = ( class_exists( 'BizCity_Twin_Data_Contract' ) && defined( 'BizCity_Twin_Data_Contract::CONTRACT_VERSION' ) )
			? BizCity_Twin_Data_Contract::CONTRACT_VERSION
			: 'unknown';
		return [
			'ok'               => $ok,
			'contract_version' => $ver,
			'missing'          => $missing,
			'patch_hint'       => $ok ? '' : 'Add missing keys to BizCity_Twin_Data_Contract::event_taxonomy()',
		];
	}

	/**
	 * Smoke the bzdoc_documents queries used by <HistoryDocumentsTab /> (global + notebook mode).
	 */
	public function history_smoke( int $user_id = 0, int $notebook_id = 0, int $blog_id = 0 ): array {
		$switched = false;
		if ( $blog_id > 0 && is_multisite() && get_current_blog_id() !== $blog_id ) {
			switch_to_blog( $blog_id ); $switched = true;
		}
		global $wpdb;
		$tbl  = $wpdb->prefix . 'bzdoc_documents';
		$prev = $wpdb->suppress_errors( true );
		$exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) ) === $tbl );
		if ( ! $exists ) {
			$wpdb->suppress_errors( $prev );
			if ( $switched ) restore_current_blog();
			return [ 'ok' => false, 'reason' => 'bzdoc_documents table not found on this blog' ];
		}
		$user_id = $user_id > 0 ? $user_id : get_current_user_id();
		$global_count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$tbl} WHERE owner_id=%d", $user_id
		) );
		$notebook_count = $notebook_id > 0 ? (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$tbl} WHERE notebook_id=%d AND owner_id IN (%d, 0)",
			$notebook_id, $user_id
		) ) : null;
		$sample = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, title, updated_at FROM {$tbl} WHERE owner_id=%d ORDER BY updated_at DESC LIMIT 5",
			$user_id
		), ARRAY_A );
		$wpdb->suppress_errors( $prev );
		if ( $switched ) restore_current_blog();
		return [
			'ok'             => true,
			'global_count'   => $global_count,
			'notebook_count' => $notebook_count,
			'sample'         => (array) $sample,
		];
	}

	/* ============================================================
	 * §7 — Progress Assessment (PHASE-0.36 roadmap tracker)
	 *
	 * Two commands, both purely read-only:
	 *
	 *   • progress-report  — scans filesystem markers (PHP classes loaded,
	 *                        FE bundle built, options/version gates set,
	 *                        view present) and maps each TBR.x sprint to
	 *                        a status row {done|partial|not-started}.
	 *                        Output mirrors the §8 sprint tables in
	 *                        PHASE-0.36-TWINBRAIN-CENTRAL-BRAIN.md so the
	 *                        founder can ask `wp bizcity diag progress-report`
	 *                        instead of eyeballing the doc.
	 *
	 *   • hermes-port-audit — for each PORT decision in §2.1 of the spec
	 *                        (H1..H9), check whether the FE/BE artefact that
	 *                        embodies it actually exists in the repo.
	 *
	 * Both walk the plugin tree under BIZCITY_TWIN_AI_DIR — they don't touch
	 * the database except to read the schema/option versions, so they are
	 * safe to run on any blog.
	 * ============================================================ */

	const PROGRESS_PLUGIN_ROOT = 'BIZCITY_TWIN_AI_DIR';

	private function plugin_root(): string {
		if ( defined( self::PROGRESS_PLUGIN_ROOT ) ) {
			return rtrim( constant( self::PROGRESS_PLUGIN_ROOT ), '/\\' ) . '/';
		}
		return rtrim( dirname( __DIR__ ), '/\\' ) . '/';
	}

	/**
	 * Resolve a marker for a sprint cell.
	 *
	 * @param array $checks Each check is one of:
	 *   - [ 'file',   'rel/path.ext' ]
	 *   - [ 'class',  'Class_Name' ]
	 *   - [ 'method', 'Class_Name', 'method_name' ]
	 *   - [ 'option', 'option_key', 'expected_value_or_null' ]
	 *   - [ 'grep',   'rel/path', 'needle' ]
	 *
	 * @return array { status: done|partial|not-started, hits: [...], missing: [...] }
	 */
	private function evaluate_checks( array $checks ): array {
		$root = $this->plugin_root();
		$hits = [];
		$miss = [];
		foreach ( $checks as $c ) {
			[ $kind ] = $c;
			$ok    = false;
			$label = '';
			switch ( $kind ) {
				case 'file':
					$rel   = $c[1];
					$label = 'file:' . $rel;
					$ok    = is_readable( $root . $rel );
					break;
				case 'class':
					$label = 'class:' . $c[1];
					$ok    = class_exists( $c[1] );
					break;
				case 'method':
					$label = 'method:' . $c[1] . '::' . $c[2];
					$ok    = class_exists( $c[1] ) && method_exists( $c[1], $c[2] );
					break;
				case 'option':
					$label   = 'option:' . $c[1];
					$current = get_option( $c[1], null );
					if ( isset( $c[2] ) && $c[2] !== null ) {
						$ok = ( (string) $current === (string) $c[2] );
						$label .= '=' . $c[2];
					} else {
						$ok = ( $current !== null && $current !== false );
					}
					break;
				case 'grep':
					$rel   = $c[1];
					$need  = $c[2];
					$label = 'grep:' . $rel . ':' . $need;
					$path  = $root . $rel;
					$ok    = is_readable( $path ) && ( strpos( (string) @file_get_contents( $path ), $need ) !== false );
					break;
				default:
					$label = 'unknown:' . wp_json_encode( $c );
			}
			if ( $ok ) {
				$hits[] = $label;
			} else {
				$miss[] = $label;
			}
		}
		$status = empty( $miss ) ? 'done' : ( empty( $hits ) ? 'not-started' : 'partial' );
		return [ 'status' => $status, 'hits' => $hits, 'missing' => $miss ];
	}

	/**
	 * Sprint matrix derived from PHASE-0.36 §8.
	 * Update this when a new TBR.x is added — KEEP roadmap in sync.
	 */
	private function sprint_matrix(): array {
		return [
			// Wave 0 — already shipped.
			'TBR.0' => [
				'wave'  => 0,
				'title' => 'Doc + decisions',
				'checks' => [
					[ 'file', 'PHASE-0.36-TWINBRAIN-CENTRAL-BRAIN.md' ],
				],
			],
			'TBR.0a' => [
				'wave'  => 0,
				'title' => 'BE skeleton (Runtime + REST + 5 helpers)',
				'checks' => [
					[ 'file',  'core/twinbrain/bootstrap.php' ],
					[ 'class', 'BizCity_TwinBrain_Runtime' ],
					[ 'class', 'BizCity_TwinBrain_Notebook_Selector' ],
					[ 'class', 'BizCity_TwinBrain_Tool_Intent_Matcher' ],
					[ 'class', 'BizCity_TwinBrain_Perspective_Runner' ],
					[ 'class', 'BizCity_TwinBrain_Synthesizer' ],
					[ 'class', 'BizCity_TwinBrain_REST' ],
					[ 'file',  'core/twinbrain/includes/event-schemas/brain_perspective_selected.json' ],
					[ 'file',  'core/twinbrain/includes/event-schemas/brain_perspective_answer.json' ],
					[ 'file',  'core/twinbrain/includes/event-schemas/brain_tool_intent.json' ],
				],
			],
			'TBR.0b' => [
				'wave'  => 0,
				'title' => 'Hotfix multisite cron preflight',
				'checks' => [
					[ 'method', 'BizCity_KG_Cleanup_Service', 'kg_tables_present' ],
				],
			],
			'TBR.0c' => [
				'wave'  => 0,
				'title' => 'Diagnostics class (this file)',
				'checks' => [
					[ 'class',  'BizCity_Diagnostics' ],
					[ 'method', 'BizCity_Diagnostics', 'audit_blog' ],
					[ 'method', 'BizCity_Diagnostics', 'repair_blog' ],
				],
			],
			'TBR.0.5' => [
				'wave'  => 1,
				'title' => 'Diagnostics smoke for brain',
				'checks' => [
					[ 'method', 'BizCity_Diagnostics', 'brain_preflight' ],
					[ 'method', 'BizCity_Diagnostics', 'brain_smoke_turn' ],
					[ 'method', 'BizCity_Diagnostics', 'brain_replay' ],
					[ 'method', 'BizCity_Diagnostics', 'brain_event_taxonomy_check' ],
					[ 'method', 'BizCity_Diagnostics', 'history_smoke' ],
				],
			],
			'TBR.1' => [
				'wave'  => 1,
				'title' => 'Event taxonomy v5 + bizcity_brain_turns VIEW + kg_notebooks perspective cols',
				'checks' => [
					[ 'class',  'BizCity_TwinBrain_Schema' ],
					[ 'method', 'BizCity_TwinBrain_Schema', 'ensure_view' ],
					[ 'method', 'BizCity_TwinBrain_Schema', 'ensure_notebook_perspective_columns' ],
					[ 'option', 'bizcity_twinbrain_view_ver', '0.36.1' ],
					[ 'option', 'bizcity_twinbrain_kg_nb_alter_ver', '0.36.4.1' ],
					[ 'grep',   'core/twin-core/event-stream/class-twin-event-taxonomy.php', 'brain_perspective_selected' ],
					[ 'grep',   'core/twin-core/event-stream/class-twin-event-taxonomy.php', 'brain_synthesize' ],
					[ 'grep',   'core/twin-core/includes/class-twin-data-contract.php',     "CONTRACT_VERSION = '1.1'" ],
				],
			],

			// Wave 1 — FE-first.
			// Wave 1 — FE-first sprints.
			//
			// PHASE 0.36 v3 (2026-05-10): TwinBrain has NO standalone SPA. The old
			// `modules/twinbrain/ui/` was deprecated and the FE work has been
			// reassigned into `modules/twinchat/ui/` (chatMode='brain'). All
			// sprint markers below now point at the twinchat package; sprints
			// are intentionally re-set to NOT-DONE until the migration lands.
			'TBR.F0' => [
				'wave'  => 1,
				'title' => 'Deprecate twinbrain SPA + add chatMode=brain to twinchat',
				'checks' => [
					[ 'grep', 'modules/twinchat/ui/src/stores/twinchatStore.ts', 'chatMode' ],
				],
			],
			'TBR.F1' => [
				'wave'  => 1,
				'title' => 'MSW brain fixture + reducer cases (in twinchat)',
				'checks' => [
					[ 'file', 'modules/twinchat/ui/src/mocks/brainStream.fixture.ts' ],
					[ 'grep', 'modules/twinchat/ui/src/hooks/eventToStep.ts', 'brain_perspective_selected' ],
				],
			],
			'TBR.F2' => [
				'wave'  => 1,
				'title' => 'KgWorkspacePane adaptive layout (in twinchat)',
				'checks' => [
					[ 'file', 'modules/twinchat/ui/src/components/KgWorkspacePane.tsx' ],
					[ 'grep', 'modules/twinchat/ui/src/components/KgWorkspacePane.tsx', 'kgColumnMode' ],
					[ 'grep', 'modules/twinchat/ui/src/stores/twinchatStore.ts',         'bizcity_twinchat_kg_mode' ],
				],
			],
			'TBR.F3' => [
				'wave'  => 1,
				'title' => 'BrainTimeline + 3 cards + state machine (in twinchat)',
				'checks' => [
					[ 'file', 'modules/twinchat/ui/src/components/BrainTimeline.tsx' ],
					[ 'file', 'modules/twinchat/ui/src/components/BrainSelectorCard.tsx' ],
					[ 'file', 'modules/twinchat/ui/src/components/NotebookPerspectiveCard.tsx' ],
					[ 'grep', 'modules/twinchat/ui/src/components/NotebookPerspectiveCard.tsx', 'STATUS_ICON' ],
				],
			],
			'TBR.F4' => [
				'wave'  => 1,
				'title' => 'BrainComposerToggle + slash commands (in twinchat)',
				'checks' => [
					[ 'file', 'modules/twinchat/ui/src/components/BrainComposerToggle.tsx' ],
					[ 'grep', 'modules/twinchat/ui/src/components/BrainComposerToggle.tsx', '/notebook' ],
				],
			],
			'TBR.F5' => [
				'wave'  => 1,
				'title' => 'HistoryDocumentsTab (global + notebook) — in twinchat',
				'checks' => [
					[ 'file', 'modules/twinchat/ui/src/components/HistoryDocumentsTab.tsx' ],
					[ 'grep', 'modules/twinchat/ui/src/components/HistoryDocumentsTab.tsx', "mode === 'notebook'" ],
				],
			],
			'TBR.F6' => [
				'wave'  => 1,
				'title' => 'ToolIntentSuggestionCard + confirm modal (in twinchat)',
				'checks' => [
					[ 'file', 'modules/twinchat/ui/src/components/ToolIntentSuggestionCard.tsx' ],
					[ 'grep', 'modules/twinchat/ui/src/components/ToolIntentSuggestionCard.tsx', 'ConfirmDialog' ],
				],
			],
			'TBR.F7' => [
				'wave'  => 1,
				'title' => 'Replay viewer reuse twinchat surface',
				'checks' => [
					[ 'grep', 'modules/twinchat/ui/src/components/ReplayDrawer.tsx', 'replay-trace' ],
				],
			],
			'TBR.FX' => [
				'wave'  => 1,
				'title' => 'Twinchat bundle built (no separate twinbrain bundle)',
				'checks' => [
					[ 'file', 'modules/twinchat/ui/dist/assets' ],
				],
			],

			// Wave 2 — BE cut-over.
			'TBR.2' => [
				'wave'  => 2,
				'title' => 'NotebookSelector real (cosine + diversity + recency)',
				'checks' => [
					[ 'method', 'BizCity_TwinBrain_Notebook_Selector', 'select_with_cosine' ],
				],
			],
			'TBR.3' => [
				'wave'  => 2,
				'title' => 'ToolIntentMatcher real (description embedding cosine)',
				'checks' => [
					[ 'method', 'BizCity_TwinBrain_Tool_Intent_Matcher', 'match_with_cosine' ],
				],
			],
			'TBR.4' => [
				'wave'  => 2,
				'title' => 'PerspectiveRunner real (curl_multi_exec)',
				'checks' => [
					[ 'method', 'BizCity_TwinBrain_Perspective_Runner', 'run' ],
					[ 'grep',   'core/twinbrain/includes/class-twinbrain-perspective-runner.php', 'curl_multi_exec' ],
				],
			],
			'TBR.5' => [
				'wave'  => 2,
				'title' => 'Synthesizer real (locked prompt + JSON mode + brain_synthesize event)',
				'checks' => [
					[ 'method', 'BizCity_TwinBrain_Synthesizer', 'synthesize' ],
					[ 'grep',   'core/twinbrain/includes/class-twinbrain-synthesizer.php', 'response_format' ],
					[ 'grep',   'core/twinbrain/includes/class-twinbrain-runtime.php',      'brain_synthesize' ],
				],
			],
			'TBR.6' => [
				'wave'  => 2,
				'title' => 'REST history endpoints (/history?scope=)',
				'checks' => [
					[ 'method', 'BizCity_TwinBrain_REST', 'route_history' ],
				],
			],
			'TBR.7' => [
				'wave'  => 2,
				'title' => 'POST /tool/confirm wired to Shell Engine',
				'checks' => [
					[ 'method', 'BizCity_TwinBrain_REST', 'route_tool_confirm' ],
				],
			],
			'TBR.8' => [
				'wave'  => 2,
				'title' => 'E2E (Playwright) + observability dashboard',
				'checks' => [
					[ 'file', 'modules/twinchat/ui/tests/e2e/brain-flow.spec.ts' ],
				],
			],
		];
	}

	/**
	 * Hermes port matrix — derived from PHASE-0.36 §2.1 ✅ PORT list.
	 * Each row maps an Hermes UX pattern (H#) to the FE/BE artefact that
	 * actually realises it in TwinBrain.
	 */
	private function hermes_port_matrix(): array {
		return [
			'H1' => [
				'pattern' => 'Calm Console — collapsed activity rows',
				'checks'  => [
					[ 'grep', 'modules/twinchat/ui/src/components/BrainTimeline.tsx', 'tb-turn__activity-row' ],
					[ 'grep', 'modules/twinchat/ui/src/styles.css',                    'tb-turn__activity' ],
				],
			],
			'H2' => [
				'pattern' => 'Per-step state machine icons (pending/running/success/error/cancelled)',
				'checks'  => [
					[ 'grep', 'modules/twinchat/ui/src/types/brain.ts',                              'StepStatus' ],
					[ 'grep', 'modules/twinchat/ui/src/components/NotebookPerspectiveCard.tsx',     "cancelled: '⊘'" ],
				],
			],
			'H3' => [
				'pattern' => '3-level hierarchy (Activity → Stage → Step)',
				'checks'  => [
					[ 'grep', 'modules/twinchat/ui/src/components/BrainTimeline.tsx', 'tb-turn__perspectives' ],
					[ 'grep', 'modules/twinchat/ui/src/components/BrainTimeline.tsx', 'BrainSelectorCard' ],
					[ 'grep', 'modules/twinchat/ui/src/components/BrainTimeline.tsx', 'SynthesisBlock' ],
				],
			],
			'H4' => [
				'pattern' => 'Inline tool approval card (no modal interrupt)',
				'checks'  => [
					[ 'file', 'modules/twinchat/ui/src/components/ToolIntentSuggestionCard.tsx' ],
					[ 'grep', 'modules/twinchat/ui/src/components/ToolIntentSuggestionCard.tsx', 'tb-tool-intent__row' ],
				],
			],
			'H5' => [
				'pattern' => 'Citation chips clickable [N{nb}P{p}]',
				'checks'  => [
					[ 'grep', 'modules/twinchat/ui/src/components/SynthesisBlock.tsx',           'tb-citation-chip' ],
					[ 'grep', 'modules/twinchat/ui/src/components/NotebookPerspectiveCard.tsx', 'twinbrain:open-citation' ],
				],
			],
			'H6' => [
				'pattern' => 'Slash commands in composer',
				'checks'  => [
					[ 'grep', 'modules/twinchat/ui/src/components/BrainComposerToggle.tsx', '/perspective' ],
					[ 'grep', 'modules/twinchat/ui/src/components/BrainComposerToggle.tsx', 'tb-composer__slash' ],
				],
			],
			'H7' => [
				'pattern' => 'Replay viewer (re-feed events into reducer)',
				'checks'  => [
					[ 'grep', 'modules/twinchat/ui/src/components/ReplayDrawer.tsx', 'mergeEvent' ],
				],
			],
			'H8' => [
				'pattern' => 'Adaptive workspace resize (smooth motion)',
				'checks'  => [
					[ 'grep', 'modules/twinchat/ui/src/components/KgWorkspacePane.tsx', 'framer-motion' ],
					[ 'grep', 'modules/twinchat/ui/src/components/KgWorkspacePane.tsx', 'flexBasis' ],
				],
			],
			'H9' => [
				'pattern' => 'Composer draft persistence (debounced localStorage)',
				'checks'  => [
					[ 'grep', 'modules/twinchat/ui/src/components/BrainComposerToggle.tsx', 'DRAFT_KEY' ],
				],
			],
		];
	}

	/**
	 * Build a full progress report. Call from CLI:
	 *   wp bizcity diag progress-report
	 * or browser:
	 *   ?cmd=progress-report
	 */
	public function progress_report(): array {
		$matrix = $this->sprint_matrix();
		$rows   = [];
		$totals = [ 'done' => 0, 'partial' => 0, 'not-started' => 0 ];
		$by_wave = [];
		foreach ( $matrix as $sprint => $info ) {
			$ev = $this->evaluate_checks( $info['checks'] );
			$rows[ $sprint ] = [
				'wave'    => $info['wave'],
				'title'   => $info['title'],
				'status'  => $ev['status'],
				'hits'    => count( $ev['hits'] ),
				'missing' => $ev['missing'],
			];
			$totals[ $ev['status'] ] = ( $totals[ $ev['status'] ] ?? 0 ) + 1;
			$wkey = 'wave_' . $info['wave'];
			$by_wave[ $wkey ]                       = $by_wave[ $wkey ] ?? [ 'done' => 0, 'partial' => 0, 'not-started' => 0, 'total' => 0 ];
			$by_wave[ $wkey ]['total']             += 1;
			$by_wave[ $wkey ][ $ev['status'] ]    += 1;
		}
		$total = array_sum( $totals );
		$pct   = $total > 0 ? round( 100 * ( $totals['done'] + 0.5 * $totals['partial'] ) / $total, 1 ) : 0;
		return [
			'ok'           => true,
			'phase'        => '0.36 — TwinBrain (Não tổng)',
			'generated_at' => gmdate( 'c' ),
			'plugin_ver'   => defined( 'BIZCITY_TWIN_AI_VERSION' ) ? BIZCITY_TWIN_AI_VERSION : 'n/a',
			'totals'       => $totals,
			'completion_pct' => $pct,
			'by_wave'      => $by_wave,
			'sprints'      => $rows,
		];
	}

	/**
	 * Audit which Hermes patterns we actually ported.
	 */
	public function hermes_port_audit(): array {
		$matrix = $this->hermes_port_matrix();
		$rows   = [];
		$totals = [ 'done' => 0, 'partial' => 0, 'not-started' => 0 ];
		foreach ( $matrix as $h => $info ) {
			$ev = $this->evaluate_checks( $info['checks'] );
			$rows[ $h ] = [
				'pattern' => $info['pattern'],
				'status'  => $ev['status'],
				'missing' => $ev['missing'],
			];
			$totals[ $ev['status'] ] = ( $totals[ $ev['status'] ] ?? 0 ) + 1;
		}
		$total = array_sum( $totals );
		$pct   = $total > 0 ? round( 100 * ( $totals['done'] + 0.5 * $totals['partial'] ) / $total, 1 ) : 0;
		return [
			'ok'           => true,
			'reference'    => 'PHASE-0.36 §2.1',
			'totals'       => $totals,
			'port_pct'     => $pct,
			'patterns'     => $rows,
		];
	}


	/* ============================================================
	 * §5b — PHASE-0-RULE-SKELETON skeleton diagnostics
	 *
	 * Delegated to BizCity_KG_Skeleton_Diagnostic (KG-Hub class).
	 * See core/knowledge/kg-hub/skeleton/class-kg-skeleton-diagnostic.php
	 * ============================================================ */

	/* ============================================================
	 * §6 — Dispatchers (CLI + Browser)
	 * ============================================================ */

	public function cli_dispatch( $args, $assoc_args ) {
		$cmd     = $args[0] ?? 'audit';
		$network = isset( $assoc_args['network'] );
		$blog_id = isset( $assoc_args['blog'] ) ? (int) $assoc_args['blog'] : 0;

		// Special-case the brain-* / history-smoke commands that take their own args.
		switch ( $cmd ) {
			case 'brain-smoke':
				$prompt = isset( $assoc_args['prompt'] ) ? (string) $assoc_args['prompt'] : 'Có nên thuê thêm 5 nhân viên không?';
				$result = $this->brain_smoke_turn( $prompt, $blog_id );
				break;
			case 'brain-replay':
				$trace  = isset( $assoc_args['trace'] ) ? (string) $assoc_args['trace'] : '';
				$result = $this->brain_replay( $trace, $blog_id );
				break;
			case 'brain-taxonomy':
				$result = $this->brain_event_taxonomy_check();
				break;
			case 'history-smoke':
				$uid    = isset( $assoc_args['user'] ) ? (int) $assoc_args['user'] : 0;
				$nb     = isset( $assoc_args['notebook'] ) ? (int) $assoc_args['notebook'] : 0;
				$result = $this->history_smoke( $uid, $nb, $blog_id );
				break;
			case 'progress-report':
				$result = $this->progress_report();
				break;
			case 'hermes-port-audit':
				$result = $this->hermes_port_audit();
				break;
			case 'skeleton-roadmap':
				$result = BizCity_KG_Skeleton_Diagnostic::instance()->roadmap( $blog_id );
				break;
			case 'skeleton-fix':
				$dry_run = isset( $assoc_args['dry-run'] ) || isset( $assoc_args['dry_run'] );
				$result  = BizCity_KG_Skeleton_Diagnostic::instance()->fix( $dry_run, $blog_id );
				break;
			case 'skeleton-logs':
				$limit  = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 20;
				$result = BizCity_KG_Skeleton_Diagnostic::instance()->logs( $limit, $blog_id );
				break;
			case 'skeleton-rebuild':
				$nb     = isset( $assoc_args['notebook'] ) ? (int) $assoc_args['notebook'] : 0;
				$stuck  = isset( $assoc_args['stuck'] );
				$result = BizCity_KG_Skeleton_Diagnostic::instance()->rebuild( $nb, $stuck, $blog_id );
				break;
			default:
				$result = $this->dispatch( $cmd, $network, $blog_id );
		}
		\WP_CLI::log( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
	}

	public function dispatch( string $cmd, bool $network = false, int $blog_id = 0 ): array {
		switch ( $cmd ) {
			case 'audit':
				return $network ? $this->audit_network() : $this->audit_blog( $blog_id );
			case 'repair':
				return $network ? $this->repair_network() : $this->repair_blog( $blog_id );
			case 'clean-cron':
				return $network ? $this->clean_cron_network() : $this->clean_cron_blog( $blog_id );
			case 'bin-store':
				return $this->bin_store_audit_blog( $blog_id );
			case 'skeleton-audit':
				$kgd = BizCity_KG_Skeleton_Diagnostic::instance();
				return $network ? $kgd->audit_network() : $kgd->audit_blog( $blog_id );
			case 'skeleton-rebuild':
				$nb    = isset( $_GET['notebook'] ) ? (int) $_GET['notebook'] : 0;
				$stuck = ! empty( $_GET['stuck'] ) || $nb <= 0;
				return BizCity_KG_Skeleton_Diagnostic::instance()->rebuild( $nb, $stuck, $blog_id );
			case 'skeleton-roadmap':
				return BizCity_KG_Skeleton_Diagnostic::instance()->roadmap( $blog_id );
			case 'skeleton-fix':
				$dry = ! empty( $_GET['dry_run'] ) || ! empty( $_GET['dry-run'] );
				return BizCity_KG_Skeleton_Diagnostic::instance()->fix( $dry, $blog_id );
			case 'skeleton-logs':
				$limit = isset( $_GET['limit'] ) ? (int) $_GET['limit'] : 20;
				return BizCity_KG_Skeleton_Diagnostic::instance()->logs( $limit, $blog_id );
			case 'brain-preflight':
				return $this->brain_preflight( $blog_id );
			case 'brain-smoke':
				$prompt = isset( $_GET['prompt'] ) ? wp_unslash( (string) $_GET['prompt'] ) : '';
				return $this->brain_smoke_turn( $prompt !== '' ? $prompt : 'Có nên thuê thêm 5 nhân viên không?', $blog_id );
			case 'brain-replay':
				$trace = isset( $_GET['trace'] ) ? sanitize_text_field( (string) $_GET['trace'] ) : '';
				return $this->brain_replay( $trace, $blog_id );
			case 'brain-taxonomy':
				return $this->brain_event_taxonomy_check();
			case 'brain-view':
				if ( ! class_exists( 'BizCity_TwinBrain_Schema' ) ) {
					return [ 'ok' => false, 'reason' => 'twinbrain schema not loaded' ];
				}
				BizCity_TwinBrain_Schema::ensure_view();
				global $wpdb;
				$view = BizCity_TwinBrain_Schema::view_name();
				$prev = $wpdb->suppress_errors( true );
				$exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $view ) ) === $view );
				$count = $exists ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$view}" ) : 0;
				$wpdb->suppress_errors( $prev );
				return [
					'ok'         => $exists,
					'view'       => $view,
					'view_ver'   => get_option( BizCity_TwinBrain_Schema::VIEW_VERSION_OPTION ),
					'turn_count' => $count,
				];
			case 'history-smoke':
				$uid = isset( $_GET['user'] ) ? (int) $_GET['user'] : 0;
				$nb  = isset( $_GET['notebook'] ) ? (int) $_GET['notebook'] : 0;
				return $this->history_smoke( $uid, $nb, $blog_id );
			case 'orphan-dry-run':
				if ( ! class_exists( 'BizCity_KG_Cleanup_Service' ) ) {
					return [ 'ok' => false, 'reason' => 'cleanup-service not loaded' ];
				}
				return BizCity_KG_Cleanup_Service::instance()->run( [ 'trigger_kind' => 'diag', 'triggered_by' => get_current_user_id() ] );
			case 'progress-report':
				return $this->progress_report();
			case 'hermes-port-audit':
				return $this->hermes_port_audit();
			default:
				return [ 'ok' => false, 'reason' => 'unknown command', 'commands' => [
					'audit', 'repair', 'clean-cron', 'bin-store',
					'skeleton-audit', 'skeleton-rebuild', 'skeleton-roadmap', 'skeleton-fix', 'skeleton-logs',
					'brain-preflight', 'brain-smoke', 'brain-replay', 'brain-taxonomy', 'brain-view', 'history-smoke',
					'orphan-dry-run', 'progress-report', 'hermes-port-audit',
				] ];
		}
	}
}

// Register CLI early.
BizCity_Diagnostics::instance();

// Browser entry: ?cmd=audit&network=1
if ( ! defined( 'WP_CLI' ) && PHP_SAPI !== 'cli' && isset( $_GET['cmd'] ) ) {
	$cmd     = sanitize_key( (string) $_GET['cmd'] );
	$network = ! empty( $_GET['network'] );
	$blog_id = isset( $_GET['blog'] ) ? (int) $_GET['blog'] : 0;
	$out     = BizCity_Diagnostics::instance()->dispatch( $cmd, $network, $blog_id );
	echo wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
	exit;
}
