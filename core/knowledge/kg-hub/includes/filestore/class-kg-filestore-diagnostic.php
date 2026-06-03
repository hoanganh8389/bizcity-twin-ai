<?php
/**
 * Bizcity Twin AI — KG Filestore Diagnostic (Phase 0.7 Wave F2.5).
 *
 * Operations console for the content→filestore migration. Gives admin a
 * single page to:
 *
 *   1. SEE     storage_ver=1 vs storage_ver=2 progress per fat-payload table.
 *   2. MEASURE on-disk footprint of `bizcity-kg/notebooks/*` vs MySQL.
 *   3. VERIFY  parity on a random sample (sha256 DB body vs file body).
 *   4. RUN     a manual backfill batch (500 rows) without waiting for cron.
 *   5. TOGGLE  the `bizcity_kg_filestore_dual_write` option.
 *
 * This is the **gate** before flipping Wave F3 (read file-first) and Wave F4
 * (stop DB writes). Surfacing the numbers next to a backfill button keeps the
 * operator in the loop on every blog of the multisite.
 *
 * Lives under Tools → BizCity KG Filestore. Capability: manage_options.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge\KG_Hub\Filestore
 * @since      2026-05-20  (PHASE-0.7-LEARN-VECTOR-FILE Wave F2.5)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

final class BizCity_KG_Filestore_Diagnostic {

	const MENU_SLUG      = 'bizcity-kg-filestore';
	const NONCE_ACTION   = 'bizcity_kg_filestore_admin';
	const PARITY_SAMPLE  = 10;

	/** Wave F5 — ingest pipeline trace ring buffer (option-backed, last 200 entries). */
	const LOG_OPTION     = 'bizcity_kg_filestore_trace';
	const LOG_MAX        = 200;

	/**
	 * Wave F4.1c — housekeeping cron (Backfill + Clean + Re-embed + Optimize).
	 *
	 * 2026-05-27 — interval switched from hard-coded 3 days → admin-selectable
	 * (daily / 3-day / weekly / monthly), default = weekly. Hook name kept for
	 * back-compat with already-scheduled events; the schedule key is now derived
	 * from the chosen interval via {@see schedule_key_for_interval()}.
	 */
	const HOUSEKEEPING_HOOK     = 'bizcity_kg_housekeeping_3d';
	const HOUSEKEEPING_OPT_LAST = 'bizcity_kg_housekeeping_last';
	const HOUSEKEEPING_LOG_OPT  = 'bizcity_kg_housekeeping_log';
	const HOUSEKEEPING_LOG_MAX  = 80;

	/** Admin-selectable cadence. Stores one of: daily | 3days | weekly | monthly. */
	const HOUSEKEEPING_OPT_INTERVAL = 'bizcity_kg_housekeeping_interval';
	const HOUSEKEEPING_DEFAULT_INTERVAL = 'weekly';

	/**
	 * Legacy schedule name (3-day). Kept so we can detect & migrate already-scheduled
	 * cron rows pinned to it.
	 */
	const HOUSEKEEPING_LEGACY_SCHEDULE = 'bizcity_kg_3days';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) { self::$instance = new self(); }
		return self::$instance;
	}

	private function __construct() {}

	public function bind() {
		add_action( 'admin_menu',          [ $this, 'register_menu' ] );
		add_action( 'admin_post_bizcity_kg_filestore_action', [ $this, 'handle_post' ] );
		// 2026-05-26 — chunked AJAX runner (avoids 30s timeouts on big tables
		// & 200×LLM embedding bursts). Each call processes one small chunk
		// and returns a JSON envelope the FE drives in a loop.
		add_action( 'wp_ajax_bizcity_kg_filestore_step', [ $this, 'ajax_step' ] );
		add_filter( 'bizcity_diagnostics_register_tables',    [ $this, 'mark_filestore_aware' ] );
		// Capture the BizCity_Twin_Debug::trace() stream for the on-page log viewer.
		// Hook fires regardless of admin/front, so the ring buffer keeps the most
		// recent activity (ingest, embed batches, triplet extract, filestore writes)
		// for the operator to inspect from the Diagnostic page.
		add_action( 'bizcity_intent_pipeline_log', [ $this, 'capture_trace' ], 10, 4 );

		// Wave F4.1c (2026-05-26) — Health-checker housekeeping cycle, every 3 days.
		// First-class scheduled chore: backfill v1→v2, clean inline payload, re-embed
		// NULL embeddings, OPTIMIZE TABLE. Same code path as the manual chunked runner
		// so on-call ops can replay/debug from the admin UI.
		add_filter( 'cron_schedules', [ $this, 'register_cron_schedule' ] );
		add_action( self::HOUSEKEEPING_HOOK, [ $this, 'run_housekeeping_cycle' ] );
		add_action( 'init', [ $this, 'ensure_housekeeping_scheduled' ], 99 );
	}

	/**
	 * Push a BizCity_Twin_Debug::trace() event into a bounded ring buffer.
	 * Filters to KG-relevant scopes so we don't drown in chat/agent noise.
	 */
	public function capture_trace( $step, $data, $level = 'info', $ms = 0 ) {
		if ( ! is_string( $step ) || strpos( $step, 'twin_debug:' ) !== 0 ) { return; }
		$key = substr( $step, strlen( 'twin_debug:' ) ); // e.g. 'kg.ingest_done'
		// Keep only scopes that matter for source ingest → embed → triplet flow.
		$keep_scopes = [ 'kg' ];
		$scope = strstr( $key, '.', true ) ?: $key;
		if ( ! in_array( $scope, $keep_scopes, true ) ) { return; }
		$ring = get_option( self::LOG_OPTION, [] );
		if ( ! is_array( $ring ) ) { $ring = []; }
		$ring[] = [
			't'     => time(),
			'ms'    => (float) $ms,
			'level' => (string) $level,
			'event' => $key,
			'data'  => is_array( $data ) ? $data : [ 'data' => $data ],
		];
		if ( count( $ring ) > self::LOG_MAX ) {
			$ring = array_slice( $ring, -self::LOG_MAX );
		}
		update_option( self::LOG_OPTION, $ring, false );
	}

	// ─────────────────────────────────────────────────────────────────
	// Diagnostics filter — tag fat-payload tables.
	// ─────────────────────────────────────────────────────────────────

	public function mark_filestore_aware( $tables ) {
		if ( ! is_array( $tables ) ) { return $tables; }
		$targets = [
			'bizcity_kg_passages', 'bizcity_kg_entities',
			'bizcity_kg_relations', 'bizcity_kg_triplet_queue',
		];
		foreach ( $tables as &$row ) {
			$name = isset( $row['name'] ) ? (string) $row['name'] : '';
			if ( in_array( $name, $targets, true ) ) {
				$row['filestore_aware'] = true;
			}
		}
		return $tables;
	}

	// ─────────────────────────────────────────────────────────────────
	// Menu + form handler.
	// ─────────────────────────────────────────────────────────────────

	public function register_menu() {
		add_management_page(
			'BizCity KG · Filestore Browser',
			'BizCity KG · Filestore',
			'manage_options',
			self::MENU_SLUG,
			[ $this, 'render_page' ]
		);
	}

	public function handle_post() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions.' );
		}
		check_admin_referer( self::NONCE_ACTION );

		$action = isset( $_POST['kg_action'] ) ? sanitize_key( (string) $_POST['kg_action'] ) : '';
		$msg    = '';
		switch ( $action ) {

			case 'toggle_dual_write':
				$cur = (int) get_option( BizCity_KG_Filestore_Dispatcher::OPT_DUAL_WRITE, 0 );
				update_option( BizCity_KG_Filestore_Dispatcher::OPT_DUAL_WRITE, $cur ? 0 : 1 );
				if ( class_exists( 'BizCity_KG_Filestore_Backfill' ) ) {
					BizCity_KG_Filestore_Backfill::instance()->bind();
				}
				$msg = $cur ? 'Dual-write OFF' : 'Dual-write ON';
				break;

			case 'run_backfill':
				$prev_enabled = BizCity_KG_Filestore_Dispatcher::is_enabled();
				if ( ! $prev_enabled ) {
					// Force-enable for this loop — user clicked the button.
					update_option( BizCity_KG_Filestore_Dispatcher::OPT_DUAL_WRITE, 1 );
				}
				// Give the loop most of the request window. PHP default
				// max_execution_time is 30s; leave 5s headroom for the redirect.
				@set_time_limit( 60 );
				$report = BizCity_KG_Filestore_Backfill::instance()->run_loop( 25, 200 );
				if ( ! $prev_enabled ) {
					update_option( BizCity_KG_Filestore_Dispatcher::OPT_DUAL_WRITE, 0 );
				}
				$msg = sprintf(
					'Backfill loop: %d pass(es) in %d ms · passages %d, entities %d, relations %d, errors %d',
					(int) $report['passes'],
					(int) $report['elapsed_ms'],
					(int) $report['passages'],
					(int) $report['entities'],
					(int) $report['relations'],
					(int) $report['errors']
				);
				break;

			case 'verify_parity':
				$res = $this->run_parity_check( self::PARITY_SAMPLE );
				set_transient( 'bizcity_kg_filestore_parity', $res, 5 * MINUTE_IN_SECONDS );
				$msg = sprintf(
					'Parity sample: %d/%d match, %d mismatch, %d missing-file',
					$res['match'], $res['checked'], $res['mismatch'], $res['missing']
				);
				break;

			case 'clean_inline_passages':
				$res = $this->clean_inline( 'passages' );
				$msg = sprintf( 'Cleaned %d passages (content=NULL where storage_ver=2) in %d ms', $res['rows'], $res['ms'] );
				break;

			case 'clean_inline_entities':
				$res = $this->clean_inline( 'entities' );
				$msg = sprintf( 'Cleaned %d entities (description/aliases/embedding=NULL where storage_ver=2) in %d ms', $res['rows'], $res['ms'] );
				break;

			case 'clean_inline_relations':
				$res = $this->clean_inline( 'relations' );
				$msg = sprintf( 'Cleaned %d relations (relation_text/embedding=NULL where storage_ver=2) in %d ms', $res['rows'], $res['ms'] );
				break;

			case 'clean_triplet_queue':
				$res = $this->clean_triplet_queue();
				$msg = sprintf( 'Triplet queue: NULL raw_llm_output for %d processed rows in %d ms', $res['rows'], $res['ms'] );
				break;

			case 'optimize_tables':
				$res = $this->optimize_tables();
				$msg = 'OPTIMIZE TABLE: ' . implode( ', ', $res );
				break;

			case 'toggle_debug':
				$cur = (string) get_option( 'bizcity_twin_debug', '' );
				$new = $cur === '1' ? '' : '1';
				update_option( 'bizcity_twin_debug', $new );
				$msg = $new === '1' ? 'Twin Debug ON — trace events now captured' : 'Twin Debug OFF';
				break;

			case 'set_schedule':
				$want = isset( $_POST['hk_interval'] ) ? sanitize_key( (string) wp_unslash( $_POST['hk_interval'] ) ) : '';
				if ( ! in_array( $want, [ 'daily', '3days', 'weekly', 'monthly' ], true ) ) {
					$msg = 'Invalid interval (allowed: daily, 3days, weekly, monthly).';
					break;
				}
				$prev = self::current_interval();
				update_option( self::HOUSEKEEPING_OPT_INTERVAL, $want, false );
				// Force-reschedule with the new cadence on this site.
				wp_clear_scheduled_hook( self::HOUSEKEEPING_HOOK );
				$sched = self::schedule_key_for_interval( $want );
				wp_schedule_event( time() + HOUR_IN_SECONDS, $sched, self::HOUSEKEEPING_HOOK );
				$msg = sprintf( 'Housekeeping cadence: %s → %s (rescheduled).', $prev, $want );
				break;

			case 'run_housekeeping_now':
				@set_time_limit( 180 );
				$summary = $this->run_housekeeping_cycle();
				$msg = sprintf(
					'Housekeeping run NOW: %d phase(s) in %d ms (blog_id=%d). Errors: %s',
					count( $summary['phases'] ?? [] ),
					(int) ( $summary['elapsed_ms'] ?? 0 ),
					(int) ( $summary['blog_id']    ?? 0 ),
					empty( $summary['errors'] ) ? 'none' : implode( '; ', (array) $summary['errors'] )
				);
				break;

			case 'clear_housekeeping_log':
				update_option( self::HOUSEKEEPING_LOG_OPT, [], false );
				$msg = 'Housekeeping log cleared (per-site).';
				break;

			case 'clear_trace_log':
				update_option( self::LOG_OPTION, [], false );
				$msg = 'Trace log cleared';
				break;

			case 'rebuild_kg_embeddings':
				// EMERGENCY — re-embed entities & relations whose `embedding` is NULL.
				// Caused by an earlier overly-aggressive cleanup pass that wiped
				// vectors. Pulls description from Content_Router (filestore) so it
				// works on storage_ver=2 rows.
				@set_time_limit( 90 );
				$report = $this->rebuild_kg_embeddings( 200 );
				$msg = sprintf(
					'Re-embed: entities %d ok / %d fail, relations %d ok / %d fail in %d ms (limit 200 each, click again to drain)',
					$report['ent_ok'], $report['ent_fail'],
					$report['rel_ok'], $report['rel_fail'],
					$report['ms']
				);
				break;

			default:
				$msg = 'Unknown action';
		}

		// Store full message in a per-user transient — long query strings with
		// payloads like "content=NULL where storage_ver=2" get tripped by WAF /
		// mod_security and return a fake 404. URL only carries a short flag.
		if ( $msg !== '' ) {
			set_transient( 'bizcity_kg_filestore_msg_' . get_current_user_id(), $msg, 60 );
		}
		$redirect = add_query_arg( [
			'page'   => self::MENU_SLUG,
			'kg_msg' => '1',
		], admin_url( 'tools.php' ) );
		wp_safe_redirect( $redirect );
		exit;
	}

	// ─────────────────────────────────────────────────────────────────
	// Stats collectors.
	// ─────────────────────────────────────────────────────────────────

	private function storage_progress() {
		global $wpdb;
		$db  = BizCity_KG_Database::instance();
		$out = [];
		foreach ( [
			'kg_passages'  => $db->tbl_passages(),
			'kg_entities'  => $db->tbl_entities(),
			'kg_relations' => $db->tbl_relations(),
		] as $key => $tbl ) {
			// Defensive: storage_ver column added by migrate_v024.
			$has_col = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND COLUMN_NAME='storage_ver'",
				$tbl
			) );
			if ( ! $has_col ) {
				$out[ $key ] = [ 'table' => $tbl, 'has_col' => false ];
				continue;
			}
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl}" );
			$v2    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl} WHERE storage_ver=2" );
			$v1    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl} WHERE storage_ver=1" );
			$out[ $key ] = [
				'table'    => $tbl,
				'has_col'  => true,
				'total'    => $total,
				'v1'       => $v1,
				'v2'       => $v2,
				'pct'      => $total > 0 ? round( $v2 * 100 / $total, 1 ) : 0.0,
			];
		}
		return $out;
	}

	private function filesystem_stats() {
		$uploads = wp_get_upload_dir();
		$root    = trailingslashit( $uploads['basedir'] ) . 'bizcity-kg/notebooks';
		$stat    = [ 'root' => $root, 'exists' => is_dir( $root ), 'notebook_count' => 0, 'bytes' => 0, 'files' => 0 ];
		if ( ! $stat['exists'] ) { return $stat; }
		$dh = @opendir( $root );
		if ( ! $dh ) { return $stat; }
		while ( ( $entry = readdir( $dh ) ) !== false ) {
			if ( $entry === '.' || $entry === '..' ) { continue; }
			$dir = $root . '/' . $entry;
			if ( ! is_dir( $dir ) ) { continue; }
			$stat['notebook_count']++;
			$this->scan_dir( $dir, $stat );
		}
		closedir( $dh );
		return $stat;
	}

	private function scan_dir( $dir, array &$stat ) {
		$dh = @opendir( $dir );
		if ( ! $dh ) { return; }
		while ( ( $entry = readdir( $dh ) ) !== false ) {
			if ( $entry === '.' || $entry === '..' ) { continue; }
			$p = $dir . '/' . $entry;
			if ( is_dir( $p ) ) {
				$this->scan_dir( $p, $stat );
			} elseif ( is_file( $p ) ) {
				$stat['files']++;
				$sz = @filesize( $p );
				if ( $sz !== false ) { $stat['bytes'] += (int) $sz; }
			}
		}
		closedir( $dh );
	}

	private function run_parity_check( $sample_n ) {
		global $wpdb;
		$db   = BizCity_KG_Database::instance();
		$tbl  = $db->tbl_passages();
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, notebook_id, content, content_hash, storage_ver, file_shard, file_offset, file_length FROM {$tbl} WHERE storage_ver=2 ORDER BY RAND() LIMIT %d",
			(int) $sample_n
		), ARRAY_A );
		$out = [
			'checked' => 0, 'match' => 0, 'mismatch' => 0, 'missing' => 0,
			'details' => [], 'ts' => time(),
		];
		if ( ! $rows ) { return $out; }
		$folder = BizCity_KG_Notebook_Folder::instance();
		$pstore = BizCity_KG_Passage_File_Store::instance();
		foreach ( $rows as $r ) {
			$out['checked']++;
			$uuid = $folder->notebook_uuid( (int) $r['notebook_id'] );
			if ( is_wp_error( $uuid ) ) {
				$out['missing']++;
				$out['details'][] = [ 'id' => (int) $r['id'], 'status' => 'no_uuid' ];
				continue;
			}
			$body = $pstore->read_body( $uuid, (int) $r['file_shard'], (int) $r['file_offset'], (int) $r['file_length'] );
			if ( is_wp_error( $body ) || $body === null || $body === false ) {
				$out['missing']++;
				$out['details'][] = [ 'id' => (int) $r['id'], 'status' => 'no_file' ];
				continue;
			}
			$db_hash   = (string) $r['content_hash'];
			$file_hash = hash( 'sha256', (string) $body );
			$db_body_h = hash( 'sha256', (string) $r['content'] );
			if ( $file_hash === $db_body_h ) {
				$out['match']++;
				$out['details'][] = [ 'id' => (int) $r['id'], 'status' => 'ok' ];
			} else {
				$out['mismatch']++;
				$out['details'][] = [
					'id'        => (int) $r['id'],
					'status'    => 'mismatch',
					'db_hash'   => substr( $db_body_h, 0, 12 ),
					'file_hash' => substr( $file_hash, 0, 12 ),
					'meta_hash' => substr( $db_hash, 0, 12 ),
				];
			}
		}
		return $out;
	}

	// ─────────────────────────────────────────────────────────────────
	// Cleanup ops (Wave F4 — null out inline payload once filestore proven).
	// ─────────────────────────────────────────────────────────────────

	/**
	 * Null out inline payload columns for rows already migrated (storage_ver=2).
	 * Schema columns stay so we can rollback by re-backfilling from file; the
	 * DROP COLUMN happens in a later wave once parity is soaked.
	 *
	 * @param string $kind  'passages' | 'entities' | 'relations'
	 * @return array { rows: int, ms: int }
	 */
	private function clean_inline( $kind ) {
		global $wpdb;
		$db = BizCity_KG_Database::instance();
		$t0 = microtime( true );

		$rows = 0;
		switch ( $kind ) {
			case 'passages':
				$tbl  = $db->tbl_passages();
				// `content` lives in passage shard MD files (storage_ver=2).
				// `embedding` LONGTEXT lives in R-VFS .bin (predecessor rule).
				// `metadata` is duplicated inside the MD frontmatter.
				// All three are recoverable → safe to NULL out the inline copy.
				$rows = (int) $wpdb->query(
					"UPDATE {$tbl}
					    SET content='', embedding=NULL, metadata=NULL
					  WHERE storage_ver=2
					    AND ( (content<>'' AND content IS NOT NULL)
					       OR embedding IS NOT NULL
					       OR metadata IS NOT NULL )"
				);
				break;

			case 'entities':
				$tbl  = $db->tbl_entities();
				// description + aliases are reconstructible from entities.jsonl.
				// 2026-05-20 — do NOT null `embedding` here. Entity vectors live
				// ONLY in the DB column (no .bin sidecar exists for entities), so
				// dropping them breaks vector seed search → empty `query_entities`
				// → Graph Nexus loses cited-node highlighting.
				$rows = (int) $wpdb->query(
					"UPDATE {$tbl}
					    SET description=NULL, aliases=NULL, metadata=NULL
					  WHERE storage_ver=2
					    AND (description IS NOT NULL OR aliases IS NOT NULL OR metadata IS NOT NULL)"
				);
				break;

			case 'relations':
				$tbl  = $db->tbl_relations();
				// Same caveat as entities — keep `embedding` column intact.
				$rows = (int) $wpdb->query(
					"UPDATE {$tbl}
					    SET relation_text=NULL, metadata=NULL
					  WHERE storage_ver=2
					    AND (relation_text IS NOT NULL OR metadata IS NOT NULL)"
				);
				break;
		}
		return [ 'rows' => $rows, 'ms' => (int) round( ( microtime( true ) - $t0 ) * 1000 ) ];
	}

	private function optimize_tables() {
		global $wpdb;
		$db  = BizCity_KG_Database::instance();
		$out = [];
		foreach ( [
			'passages'      => $db->tbl_passages(),
			'entities'      => $db->tbl_entities(),
			'relations'     => $db->tbl_relations(),
			'triplet_queue' => $db->tbl_triplet_queue(),
		] as $key => $tbl ) {
			$r = $wpdb->get_row( "OPTIMIZE TABLE {$tbl}", ARRAY_A );
			$out[] = $key . ':' . ( isset( $r['Msg_text'] ) ? $r['Msg_text'] : 'ok' );
		}
		return $out;
	}

	/**
	 * Null out `raw_llm_output` on triplet_queue rows that have been processed
	 * (status != 'pending'). The raw LLM response is audit-only after the
	 * triplet is approved/rejected/merged — the structured columns
	 * (subject/predicate/object/applied_relation_id) keep the durable record.
	 */
	private function clean_triplet_queue() {
		global $wpdb;
		$db  = BizCity_KG_Database::instance();
		$tbl = $db->tbl_triplet_queue();
		$t0  = microtime( true );
		$rows = (int) $wpdb->query(
			"UPDATE {$tbl}
			    SET raw_llm_output=NULL
			  WHERE status<>'pending'
			    AND raw_llm_output IS NOT NULL
			    AND raw_llm_output<>''"
		);
		return [ 'rows' => $rows, 'ms' => (int) round( ( microtime( true ) - $t0 ) * 1000 ) ];
	}

	/**
	 * Re-embed entities & relations whose `embedding` column is NULL.
	 *
	 * Why this exists: an early Wave-F4 cleanup nullified embeddings for
	 * storage_ver=2 entities/relations under the false assumption that they
	 * had a `.bin` sidecar. They don't — vectors live exclusively in the DB
	 * column for entities & relations. This drainer rebuilds them so the
	 * vector seed search (and downstream Graph Nexus highlight) works again.
	 *
	 * Text source: `name [ — description ]` for entities, `head predicate tail`
	 * for relations. Description hydrated via Content_Router so storage_ver=2
	 * rows still get a body when available.
	 */
	private function rebuild_kg_embeddings( $batch = 200 ) {
		global $wpdb;
		$db    = BizCity_KG_Database::instance();
		$t0    = microtime( true );
		$idx   = class_exists( 'BizCity_KG_Vector_Index' ) ? BizCity_KG_Vector_Index::instance() : null;
		$out   = [ 'ent_ok' => 0, 'ent_fail' => 0, 'rel_ok' => 0, 'rel_fail' => 0, 'ms' => 0 ];
		if ( ! $idx ) { return $out; }

		// ── Entities ───────────────────────────────────────────────────────
		$ent_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, notebook_id, name, description, storage_ver
				   FROM {$db->tbl_entities()}
				  WHERE embedding IS NULL AND status='approved' AND deleted_at IS NULL
				  ORDER BY id ASC LIMIT %d",
				(int) $batch
			), ARRAY_A
		) ?: [];
		if ( $ent_rows && class_exists( 'BizCity_KG_Content_Router' ) ) {
			BizCity_KG_Content_Router::instance()->hydrate_entities( $ent_rows, false );
		}
		foreach ( $ent_rows as $r ) {
			$name = (string) ( $r['name'] ?? '' );
			$desc = (string) ( $r['description'] ?? '' );
			$txt  = trim( $name . ( $desc !== '' ? ' — ' . $desc : '' ) );
			if ( $txt === '' ) { $out['ent_fail']++; continue; }
			$vec = $idx->embed( $txt );
			if ( is_wp_error( $vec ) || ! is_array( $vec ) ) { $out['ent_fail']++; continue; }
			$enc = BizCity_KG_Database::encode_embedding( $vec );
			$ok  = $wpdb->update(
				$db->tbl_entities(),
				[ 'embedding' => $enc ],
				[ 'id' => (int) $r['id'] ]
			);
			if ( false === $ok ) { $out['ent_fail']++; } else { $out['ent_ok']++; }
		}

		// ── Relations ──────────────────────────────────────────────────────
		$rel_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.id, r.notebook_id, r.predicate, r.relation_text, r.storage_ver,
				        h.name AS head_name, t.name AS tail_name
				   FROM {$db->tbl_relations()} r
				   LEFT JOIN {$db->tbl_entities()} h ON h.id = r.head_entity_id
				   LEFT JOIN {$db->tbl_entities()} t ON t.id = r.tail_entity_id
				  WHERE r.embedding IS NULL AND r.status='approved' AND r.deleted_at IS NULL
				  ORDER BY r.id ASC LIMIT %d",
				(int) $batch
			), ARRAY_A
		) ?: [];
		if ( $rel_rows && class_exists( 'BizCity_KG_Content_Router' ) ) {
			BizCity_KG_Content_Router::instance()->hydrate_relations( $rel_rows );
		}
		foreach ( $rel_rows as $r ) {
			$rel_text = (string) ( $r['relation_text'] ?? '' );
			if ( $rel_text === '' ) {
				$rel_text = trim( ( $r['head_name'] ?? '' ) . ' ' . ( $r['predicate'] ?? '' ) . ' ' . ( $r['tail_name'] ?? '' ) );
			}
			if ( $rel_text === '' ) { $out['rel_fail']++; continue; }
			$vec = $idx->embed( $rel_text );
			if ( is_wp_error( $vec ) || ! is_array( $vec ) ) { $out['rel_fail']++; continue; }
			$enc = BizCity_KG_Database::encode_embedding( $vec );
			$ok  = $wpdb->update(
				$db->tbl_relations(),
				[ 'embedding' => $enc ],
				[ 'id' => (int) $r['id'] ]
			);
			if ( false === $ok ) { $out['rel_fail']++; } else { $out['rel_ok']++; }
		}

		$out['ms'] = (int) round( ( microtime( true ) - $t0 ) * 1000 );
		return $out;
	}

	// ─────────────────────────────────────────────────────────────────
	// Chunked AJAX runner (Wave F4.1 — 2026-05-26).
	//
	// Solves two pains:
	//   1. Clean buttons time out (FPM 30s / Cloudflare 100s) when running a
	//      single UPDATE over 14k–65k rows + the redirect dropping the success
	//      transient → operator thinks "nothing happened".
	//   2. Re-embed (200+200) needs ~200s of LLM calls — always times out.
	//
	// Pattern: each AJAX call executes ONE small chunk and returns a JSON
	// envelope { ok, op, processed, remaining, done, ms, message }. The FE
	// runner loops fetch() calls until done=true, streaming progress into a
	// console-style log so the operator can watch and stop at any time.
	// ─────────────────────────────────────────────────────────────────

	const CLEAN_CHUNK_DEFAULT = 1000; // UPDATE … LIMIT
	const EMBED_CHUNK_DEFAULT = 10;   // LLM embedding calls / step

	public function ajax_step() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
		}
		check_ajax_referer( self::NONCE_ACTION, '_nonce' );

		$op   = isset( $_POST['op'] )   ? sanitize_key( (string) wp_unslash( $_POST['op'] ) ) : '';
		$size = isset( $_POST['size'] ) ? max( 1, (int) $_POST['size'] ) : 0;

		@set_time_limit( 60 );
		$t0 = microtime( true );

		switch ( $op ) {
			case 'backfill':
				$res = $this->step_backfill();
				break;
			case 'clean_passages':
				$res = $this->step_clean( 'passages', $size ?: self::CLEAN_CHUNK_DEFAULT );
				break;
			case 'clean_entities':
				$res = $this->step_clean( 'entities', $size ?: self::CLEAN_CHUNK_DEFAULT );
				break;
			case 'clean_relations':
				$res = $this->step_clean( 'relations', $size ?: self::CLEAN_CHUNK_DEFAULT );
				break;
			case 'clean_triplet_queue':
				$res = $this->step_clean_triplet_queue( $size ?: self::CLEAN_CHUNK_DEFAULT );
				break;
			case 'reembed_entities':
				$res = $this->step_reembed( 'entities', $size ?: self::EMBED_CHUNK_DEFAULT );
				break;
			case 'reembed_relations':
				$res = $this->step_reembed( 'relations', $size ?: self::EMBED_CHUNK_DEFAULT );
				break;
			case 'optimize_one':
				$key = isset( $_POST['target'] ) ? sanitize_key( (string) wp_unslash( $_POST['target'] ) ) : '';
				$res = $this->step_optimize_one( $key );
				break;
			case 'health_relations':
				$res = $this->step_health( 'relations' );
				break;
			case 'health_entities':
				$res = $this->step_health( 'entities' );
				break;
			case 'health_triplets':
				$res = $this->step_health( 'triplets' );
				break;
			case 'health_files':
				$res = $this->step_health( 'files' );
				break;
			default:
				wp_send_json_error( [ 'message' => 'unknown op: ' . $op ], 400 );
		}

		$res['op'] = $op;
		$res['ms'] = (int) round( ( microtime( true ) - $t0 ) * 1000 );
		if ( ! isset( $res['ok'] ) )       { $res['ok'] = true; }
		if ( ! isset( $res['done'] ) )     { $res['done'] = false; }
		if ( ! isset( $res['processed'] ) ){ $res['processed'] = 0; }
		if ( ! isset( $res['remaining'] ) ){ $res['remaining'] = null; }
		wp_send_json_success( $res );
	}

	/**
	 * One chunk of the clean_inline UPDATE for passages/entities/relations.
	 * Uses UPDATE … LIMIT N which MySQL supports (no subselect needed). The
	 * runner loops until rows=0.
	 */
	private function step_clean( $kind, $limit ) {
		global $wpdb;
		$db = BizCity_KG_Database::instance();
		$rows = 0;
		$tbl  = '';
		switch ( $kind ) {
			case 'passages':
				$tbl  = $db->tbl_passages();
				$rows = (int) $wpdb->query( $wpdb->prepare(
					"UPDATE {$tbl}
					    SET content='', embedding=NULL, metadata=NULL
					  WHERE storage_ver=2
					    AND ( (content<>'' AND content IS NOT NULL)
					       OR embedding IS NOT NULL
					       OR metadata IS NOT NULL )
					  LIMIT %d", (int) $limit
				) );
				$remaining = (int) $wpdb->get_var(
					"SELECT COUNT(*) FROM {$tbl}
					  WHERE storage_ver=2
					    AND ( (content<>'' AND content IS NOT NULL)
					       OR embedding IS NOT NULL
					       OR metadata IS NOT NULL )"
				);
				break;
			case 'entities':
				$tbl  = $db->tbl_entities();
				$rows = (int) $wpdb->query( $wpdb->prepare(
					"UPDATE {$tbl}
					    SET description=NULL, aliases=NULL, metadata=NULL
					  WHERE storage_ver=2
					    AND (description IS NOT NULL OR aliases IS NOT NULL OR metadata IS NOT NULL)
					  LIMIT %d", (int) $limit
				) );
				$remaining = (int) $wpdb->get_var(
					"SELECT COUNT(*) FROM {$tbl}
					  WHERE storage_ver=2
					    AND (description IS NOT NULL OR aliases IS NOT NULL OR metadata IS NOT NULL)"
				);
				break;
			case 'relations':
				$tbl  = $db->tbl_relations();
				$rows = (int) $wpdb->query( $wpdb->prepare(
					"UPDATE {$tbl}
					    SET relation_text=NULL, metadata=NULL
					  WHERE storage_ver=2
					    AND (relation_text IS NOT NULL OR metadata IS NOT NULL)
					  LIMIT %d", (int) $limit
				) );
				$remaining = (int) $wpdb->get_var(
					"SELECT COUNT(*) FROM {$tbl}
					  WHERE storage_ver=2
					    AND (relation_text IS NOT NULL OR metadata IS NOT NULL)"
				);
				break;
			default:
				return [ 'ok' => false, 'message' => 'unknown kind: ' . $kind ];
		}
		return [
			'processed' => $rows,
			'remaining' => $remaining,
			'done'      => ( $rows === 0 || $remaining === 0 ),
			'message'   => sprintf( '%s: cleaned %d row(s), %d remaining', $kind, $rows, $remaining ),
		];
	}

	private function step_clean_triplet_queue( $limit ) {
		global $wpdb;
		$db   = BizCity_KG_Database::instance();
		$tbl  = $db->tbl_triplet_queue();
		$rows = (int) $wpdb->query( $wpdb->prepare(
			"UPDATE {$tbl}
			    SET raw_llm_output=NULL
			  WHERE status<>'pending'
			    AND raw_llm_output IS NOT NULL
			    AND raw_llm_output<>''
			  LIMIT %d", (int) $limit
		) );
		$remaining = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$tbl}
			  WHERE status<>'pending'
			    AND raw_llm_output IS NOT NULL
			    AND raw_llm_output<>''"
		);
		return [
			'processed' => $rows,
			'remaining' => $remaining,
			'done'      => ( $rows === 0 || $remaining === 0 ),
			'message'   => sprintf( 'triplet_queue: cleaned %d row(s), %d remaining', $rows, $remaining ),
		];
	}

	/**
	 * One chunk of re-embed for entities or relations. Each chunk = $batch LLM
	 * embedding calls. Cursor not needed — the WHERE filter excludes rows we
	 * just embedded (embedding IS NULL → IS NOT NULL after success).
	 */
	private function step_reembed( $kind, $batch ) {
		global $wpdb;
		$db  = BizCity_KG_Database::instance();
		$idx = class_exists( 'BizCity_KG_Vector_Index' ) ? BizCity_KG_Vector_Index::instance() : null;
		if ( ! $idx ) {
			return [ 'ok' => false, 'done' => true, 'message' => 'BizCity_KG_Vector_Index unavailable' ];
		}

		$ok = 0; $fail = 0;
		if ( $kind === 'entities' ) {
			$tbl  = $db->tbl_entities();
			$rows = (array) $wpdb->get_results( $wpdb->prepare(
				"SELECT id, notebook_id, name, description, storage_ver
				   FROM {$tbl}
				  WHERE embedding IS NULL AND status='approved' AND deleted_at IS NULL
				  ORDER BY id ASC LIMIT %d", (int) $batch
			), ARRAY_A );
			if ( $rows && class_exists( 'BizCity_KG_Content_Router' ) ) {
				BizCity_KG_Content_Router::instance()->hydrate_entities( $rows, false );
			}
			foreach ( $rows as $r ) {
				$name = (string) ( $r['name'] ?? '' );
				$desc = (string) ( $r['description'] ?? '' );
				$txt  = trim( $name . ( $desc !== '' ? ' — ' . $desc : '' ) );
				if ( $txt === '' ) { $fail++; continue; }
				$vec = $idx->embed( $txt );
				if ( is_wp_error( $vec ) || ! is_array( $vec ) ) { $fail++; continue; }
				$enc = BizCity_KG_Database::encode_embedding( $vec );
				$res = $wpdb->update( $tbl, [ 'embedding' => $enc ], [ 'id' => (int) $r['id'] ] );
				if ( false === $res ) { $fail++; } else { $ok++; }
			}
			$remaining = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$tbl} WHERE embedding IS NULL AND status='approved' AND deleted_at IS NULL"
			);
		} else { // relations
			$tbl  = $db->tbl_relations();
			$rows = (array) $wpdb->get_results( $wpdb->prepare(
				"SELECT r.id, r.notebook_id, r.predicate, r.relation_text, r.storage_ver,
				        h.name AS head_name, t.name AS tail_name
				   FROM {$tbl} r
				   LEFT JOIN {$db->tbl_entities()} h ON h.id = r.head_entity_id
				   LEFT JOIN {$db->tbl_entities()} t ON t.id = r.tail_entity_id
				  WHERE r.embedding IS NULL AND r.status='approved' AND r.deleted_at IS NULL
				  ORDER BY r.id ASC LIMIT %d", (int) $batch
			), ARRAY_A );
			if ( $rows && class_exists( 'BizCity_KG_Content_Router' ) ) {
				BizCity_KG_Content_Router::instance()->hydrate_relations( $rows );
			}
			foreach ( $rows as $r ) {
				$rel_text = (string) ( $r['relation_text'] ?? '' );
				if ( $rel_text === '' ) {
					$rel_text = trim( ( $r['head_name'] ?? '' ) . ' ' . ( $r['predicate'] ?? '' ) . ' ' . ( $r['tail_name'] ?? '' ) );
				}
				if ( $rel_text === '' ) { $fail++; continue; }
				$vec = $idx->embed( $rel_text );
				if ( is_wp_error( $vec ) || ! is_array( $vec ) ) { $fail++; continue; }
				$enc = BizCity_KG_Database::encode_embedding( $vec );
				$res = $wpdb->update( $tbl, [ 'embedding' => $enc ], [ 'id' => (int) $r['id'] ] );
				if ( false === $res ) { $fail++; } else { $ok++; }
			}
			$remaining = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$tbl} WHERE embedding IS NULL AND status='approved' AND deleted_at IS NULL"
			);
		}

		$attempted = $ok + $fail;
		return [
			'processed' => $ok,
			'failed'    => $fail,
			'remaining' => $remaining,
			// Stop if we attempted nothing (no more candidates) OR remaining hit 0.
			'done'      => ( $attempted === 0 || $remaining === 0 ),
			'message'   => sprintf( 're-embed %s: ok=%d fail=%d, %d remaining', $kind, $ok, $fail, $remaining ),
		];
	}

	private function step_optimize_one( $target ) {
		global $wpdb;
		$db  = BizCity_KG_Database::instance();
		$map = [
			'passages'      => $db->tbl_passages(),
			'entities'      => $db->tbl_entities(),
			'relations'     => $db->tbl_relations(),
			'triplet_queue' => $db->tbl_triplet_queue(),
		];
		if ( ! isset( $map[ $target ] ) ) {
			return [ 'ok' => false, 'done' => true, 'message' => 'unknown target' ];
		}
		$tbl = $map[ $target ];
		$r   = $wpdb->get_row( "OPTIMIZE TABLE {$tbl}", ARRAY_A );
		return [
			'processed' => 1,
			'remaining' => 0,
			'done'      => true,
			'message'   => 'OPTIMIZE ' . $target . ': ' . ( $r['Msg_text'] ?? 'ok' ),
		];
	}

	/**
	 * Read-only health snapshot for relations / entities / triplets / files.
	 * Returns one-shot done=true envelope with a human-readable message + a
	 * structured `health` payload so the FE can decide OK / WARN / FAIL.
	 *
	 * Used by the cron-triggered diagnostic UI buttons ("Check Relations OK?",
	 * "Check Entities OK?", "Check Triplets OK?") so on-call ops can verify
	 * post-housekeeping integrity without dropping into mysql.
	 */
	private function step_health( $kind ) {
		global $wpdb;
		$db   = BizCity_KG_Database::instance();
		$out  = [ 'kind' => $kind, 'status' => 'ok', 'metrics' => [], 'warnings' => [] ];

		switch ( $kind ) {
			case 'relations':
				$tbl = $db->tbl_relations();
				$total      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl}" );
				$approved   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl} WHERE status='approved' AND deleted_at IS NULL" );
				$null_embed = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl} WHERE embedding IS NULL AND status='approved' AND deleted_at IS NULL" );
				$v1_left    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl} WHERE storage_ver=1" );
				$v2         = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl} WHERE storage_ver=2" );
				$out['metrics'] = compact( 'total', 'approved', 'null_embed', 'v1_left', 'v2' );
				if ( $null_embed > 0 ) { $out['status'] = 'warn'; $out['warnings'][] = $null_embed . ' approved rows have NULL embedding'; }
				if ( $v1_left > 0 )    { $out['status'] = 'warn'; $out['warnings'][] = $v1_left . ' rows still on storage_ver=1'; }
				break;

			case 'entities':
				$tbl = $db->tbl_entities();
				$total      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl}" );
				$approved   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl} WHERE status='approved' AND deleted_at IS NULL" );
				$null_embed = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl} WHERE embedding IS NULL AND status='approved' AND deleted_at IS NULL" );
				$v1_left    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl} WHERE storage_ver=1" );
				$v2         = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl} WHERE storage_ver=2" );
				$out['metrics'] = compact( 'total', 'approved', 'null_embed', 'v1_left', 'v2' );
				if ( $null_embed > 0 ) { $out['status'] = 'warn'; $out['warnings'][] = $null_embed . ' approved rows have NULL embedding'; }
				if ( $v1_left > 0 )    { $out['status'] = 'warn'; $out['warnings'][] = $v1_left . ' rows still on storage_ver=1'; }
				break;

			case 'triplets':
				$tbl = $db->tbl_triplet_queue();
				$total    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl}" );
				$pending  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl} WHERE status='pending'" );
				$approved = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl} WHERE status='approved'" );
				$rejected = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl} WHERE status='rejected'" );
				$raw_left = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl} WHERE status<>'pending' AND raw_llm_output IS NOT NULL AND raw_llm_output<>''" );
				$out['metrics'] = compact( 'total', 'pending', 'approved', 'rejected', 'raw_left' );
				if ( $raw_left > 0 ) { $out['status'] = 'warn'; $out['warnings'][] = $raw_left . ' processed rows still hold raw_llm_output (run clean_triplet_queue)'; }
				break;

			case 'files':
				$fs = $this->filesystem_stats();
				$out['metrics'] = [
					'root'            => $fs['root'],
					'exists'          => (bool) $fs['exists'],
					'notebook_count'  => (int) $fs['notebook_count'],
					'files'           => (int) $fs['files'],
					'bytes'           => (int) $fs['bytes'],
					'bytes_human'     => size_format( $fs['bytes'], 2 ),
				];
				if ( ! $fs['exists'] ) {
					$out['status']    = 'fail';
					$out['warnings'][] = 'filestore root missing: ' . $fs['root'];
				}
				break;

			default:
				return [ 'ok' => false, 'done' => true, 'message' => 'unknown health kind: ' . $kind ];
		}

		$msg_parts = [ strtoupper( $kind ) . ' ' . strtoupper( $out['status'] ) ];
		foreach ( $out['metrics'] as $k => $v ) {
			if ( is_bool( $v ) ) { $v = $v ? 'yes' : 'no'; }
			if ( is_string( $v ) && strlen( $v ) > 60 ) { $v = '…'; }
			$msg_parts[] = $k . '=' . ( is_scalar( $v ) ? (string) $v : wp_json_encode( $v ) );
		}
		if ( $out['warnings'] ) {
			$msg_parts[] = '⚠ ' . implode( ' | ', $out['warnings'] );
		}

		return [
			'processed' => 1,
			'remaining' => 0,
			'done'      => true,
			'health'    => $out,
			'message'   => implode( ' · ', $msg_parts ),
		];
	}

	/**
	 * One pass of the filestore backfill (3 tables × BATCH_SIZE rows each =
	 * up to 1500 rows touched per call). Loop terminates when no
	 * `storage_ver=1` rows remain across all 3 tables.
	 *
	 * 2026-05-26 — formerly only reachable via the sync "Run until done"
	 * button which would silently die at 30s on big blogs. Now drives the
	 * chunked runner so the operator sees per-pass progress.
	 */
	private function step_backfill() {
		if ( ! class_exists( 'BizCity_KG_Filestore_Backfill' )
		     || ! class_exists( 'BizCity_KG_Filestore_Dispatcher' ) ) {
			return [ 'ok' => false, 'done' => true, 'message' => 'backfill classes unavailable' ];
		}
		$prev_enabled = BizCity_KG_Filestore_Dispatcher::is_enabled();
		if ( ! $prev_enabled ) {
			update_option( BizCity_KG_Filestore_Dispatcher::OPT_DUAL_WRITE, 1 );
		}
		// Single pass = bounded work (≤ 1500 rows, ≤ a few seconds typically).
		$pass = BizCity_KG_Filestore_Backfill::instance()->run_once();
		if ( ! $prev_enabled ) {
			update_option( BizCity_KG_Filestore_Dispatcher::OPT_DUAL_WRITE, 0 );
		}
		global $wpdb;
		$db = BizCity_KG_Database::instance();
		$remaining_p = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$db->tbl_passages()}  WHERE storage_ver=1" );
		$remaining_e = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$db->tbl_entities()}  WHERE storage_ver=1" );
		$remaining_r = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$db->tbl_relations()} WHERE storage_ver=1" );
		$remaining   = $remaining_p + $remaining_e + $remaining_r;
		$processed   = (int) ( $pass['passages'] ?? 0 ) + (int) ( $pass['entities'] ?? 0 ) + (int) ( $pass['relations'] ?? 0 );
		return [
			'processed' => $processed,
			'failed'    => (int) ( $pass['errors'] ?? 0 ),
			'remaining' => $remaining,
			'done'      => ( $processed === 0 || $remaining === 0 ),
			'message'   => sprintf(
				'backfill: passages=%d entities=%d relations=%d errors=%d · v1 left p=%d e=%d r=%d',
				(int) ( $pass['passages']  ?? 0 ),
				(int) ( $pass['entities']  ?? 0 ),
				(int) ( $pass['relations'] ?? 0 ),
				(int) ( $pass['errors']    ?? 0 ),
				$remaining_p, $remaining_e, $remaining_r
			),
		];
	}

	// ─────────────────────────────────────────────────────────────────
	// Wave F4.1c — Housekeeping cron (every 3 days).
	// Sequence: backfill (loop until v1=0) → clean × 4 → re-embed × 2 →
	// optimize × 4. Each step uses the same step_*() methods as the JS
	// runner so admin debugging mirrors automation.
	// ─────────────────────────────────────────────────────────────────

	/**
	 * Register all 4 cadence buckets so the admin can switch live without losing
	 * the schedule. Legacy 3-day name stays registered so already-scheduled events
	 * keep running until {@see ensure_housekeeping_scheduled()} migrates them.
	 */
	public function register_cron_schedule( $schedules ) {
		$add = [
			'bizcity_kg_daily'   => [ 'interval' =>     DAY_IN_SECONDS, 'display' => 'BizCity KG Housekeeping (daily)'   ],
			self::HOUSEKEEPING_LEGACY_SCHEDULE => [ 'interval' => 3 * DAY_IN_SECONDS, 'display' => 'BizCity KG Housekeeping (3 days)' ],
			'bizcity_kg_weekly'  => [ 'interval' => 7 * DAY_IN_SECONDS, 'display' => 'BizCity KG Housekeeping (weekly)'  ],
			'bizcity_kg_monthly' => [ 'interval' => 30 * DAY_IN_SECONDS, 'display' => 'BizCity KG Housekeeping (monthly)' ],
		];
		foreach ( $add as $k => $v ) {
			if ( ! isset( $schedules[ $k ] ) ) { $schedules[ $k ] = $v; }
		}
		return $schedules;
	}

	/** Map an interval key → WP-cron schedule name (used in wp_schedule_event). */
	public static function schedule_key_for_interval( $interval ) {
		switch ( $interval ) {
			case 'daily':   return 'bizcity_kg_daily';
			case '3days':   return self::HOUSEKEEPING_LEGACY_SCHEDULE;
			case 'monthly': return 'bizcity_kg_monthly';
			case 'weekly':
			default:        return 'bizcity_kg_weekly';
		}
	}

	/** Read the admin-chosen interval, normalising legacy/empty values to default. */
	public static function current_interval() {
		$cur = (string) get_option( self::HOUSEKEEPING_OPT_INTERVAL, '' );
		if ( ! in_array( $cur, [ 'daily', '3days', 'weekly', 'monthly' ], true ) ) {
			$cur = self::HOUSEKEEPING_DEFAULT_INTERVAL;
		}
		return $cur;
	}

	/**
	 * Make sure the housekeeping hook is scheduled with the schedule that matches
	 * the admin-chosen interval. If a legacy event was registered with a different
	 * schedule (e.g. the old 3-day default), re-schedule it on the fly so the next
	 * tick honours the new cadence — per-site (multisite-safe).
	 */
	public function ensure_housekeeping_scheduled() {
		$desired_interval = self::current_interval();
		$desired_schedule = self::schedule_key_for_interval( $desired_interval );

		$ts = wp_next_scheduled( self::HOUSEKEEPING_HOOK );
		if ( ! $ts ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, $desired_schedule, self::HOUSEKEEPING_HOOK );
			return;
		}

		// If already scheduled, verify the recurrence matches the desired one.
		$crons = _get_cron_array();
		if ( ! is_array( $crons ) ) { return; }
		$current_schedule = '';
		foreach ( $crons as $time_key => $hooks ) {
			if ( ! isset( $hooks[ self::HOUSEKEEPING_HOOK ] ) ) { continue; }
			foreach ( $hooks[ self::HOUSEKEEPING_HOOK ] as $event ) {
				if ( ! empty( $event['schedule'] ) ) { $current_schedule = (string) $event['schedule']; break 2; }
			}
		}
		if ( $current_schedule !== '' && $current_schedule !== $desired_schedule ) {
			wp_clear_scheduled_hook( self::HOUSEKEEPING_HOOK );
			wp_schedule_event( time() + HOUR_IN_SECONDS, $desired_schedule, self::HOUSEKEEPING_HOOK );
		}
	}

	/**
	 * Server-side housekeeping cycle. Bounded by a wall-clock budget so a slow
	 * cron tick can't pin a worker for >2 min. Each phase logs to the
	 * housekeeping ring buffer + the main trace log.
	 *
	 * Phases (filterable order via `bizcity_kg_housekeeping_phases`):
	 *   1. backfill          — drain storage_ver=1 (most important; reader uses files).
	 *   2. clean_passages    — null inline payload for v2 rows (DB shrink).
	 *   3. clean_entities
	 *   4. clean_relations
	 *   5. clean_triplet_queue
	 *   6. reembed_entities  — re-embed NULL vectors (caps at $reembed_max steps).
	 *   7. reembed_relations
	 *   8. optimize_one × 4  — reclaim free pages.
	 */
	public function run_housekeeping_cycle() {
		$t0          = microtime( true );
		$budget_s    = (int) apply_filters( 'bizcity_kg_housekeeping_budget_s', 120 );
		$reembed_max = (int) apply_filters( 'bizcity_kg_housekeeping_reembed_max_steps', 50 ); // 50 × 10 = 500 calls cap

		@set_time_limit( $budget_s + 30 );

		$summary = [
			't0'        => time(),
			'blog_id'   => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0,
			'phases'    => [],
			'errors'    => [],
		];

		$phases = [
			[ 'op' => 'backfill',           'size' => 0,  'max_steps' => 200 ],
			[ 'op' => 'clean_passages',     'size' => self::CLEAN_CHUNK_DEFAULT, 'max_steps' => 200 ],
			[ 'op' => 'clean_entities',     'size' => self::CLEAN_CHUNK_DEFAULT, 'max_steps' => 200 ],
			[ 'op' => 'clean_relations',    'size' => self::CLEAN_CHUNK_DEFAULT, 'max_steps' => 200 ],
			[ 'op' => 'clean_triplet_queue','size' => self::CLEAN_CHUNK_DEFAULT, 'max_steps' => 200 ],
			[ 'op' => 'reembed_entities',   'size' => self::EMBED_CHUNK_DEFAULT, 'max_steps' => $reembed_max ],
			[ 'op' => 'reembed_relations',  'size' => self::EMBED_CHUNK_DEFAULT, 'max_steps' => $reembed_max ],
		];
		$phases = (array) apply_filters( 'bizcity_kg_housekeeping_phases', $phases );

		foreach ( $phases as $p ) {
			$op   = (string) ( $p['op']        ?? '' );
			$size = (int)    ( $p['size']      ?? 0 );
			$mx   = (int)    ( $p['max_steps'] ?? 100 );
			if ( $op === '' ) { continue; }
			$phase = [ 'op' => $op, 'steps' => 0, 'processed' => 0, 'failed' => 0, 'remaining' => null, 'last_msg' => '' ];
			for ( $i = 0; $i < $mx; $i++ ) {
				if ( ( microtime( true ) - $t0 ) >= $budget_s ) {
					$summary['errors'][] = $op . ': budget exhausted';
					break 2;
				}
				$res = $this->run_op_step( $op, $size );
				$phase['steps']++;
				$phase['processed'] += (int) ( $res['processed'] ?? 0 );
				$phase['failed']    += (int) ( $res['failed']    ?? 0 );
				$phase['remaining'] = $res['remaining'] ?? null;
				$phase['last_msg']  = (string) ( $res['message'] ?? '' );
				if ( ! empty( $res['done'] ) ) { break; }
			}
			$summary['phases'][] = $phase;
		}

		// Final OPTIMIZE pass (one shot each; bounded already).
		foreach ( [ 'passages', 'entities', 'relations', 'triplet_queue' ] as $tgt ) {
			if ( ( microtime( true ) - $t0 ) >= $budget_s ) { break; }
			$res = $this->step_optimize_one( $tgt );
			$summary['phases'][] = [
				'op' => 'optimize_' . $tgt, 'steps' => 1,
				'processed' => 1, 'failed' => 0,
				'remaining' => 0, 'last_msg' => (string) ( $res['message'] ?? '' ),
			];
		}

		$summary['elapsed_ms'] = (int) round( ( microtime( true ) - $t0 ) * 1000 );
		update_option( self::HOUSEKEEPING_OPT_LAST, $summary, false );
		// Append to ring buffer for the on-page history view.
		$ring = get_option( self::HOUSEKEEPING_LOG_OPT, [] );
		if ( ! is_array( $ring ) ) { $ring = []; }
		$ring[] = $summary;
		if ( count( $ring ) > self::HOUSEKEEPING_LOG_MAX ) {
			$ring = array_slice( $ring, -self::HOUSEKEEPING_LOG_MAX );
		}
		update_option( self::HOUSEKEEPING_LOG_OPT, $ring, false );

		do_action( 'bizcity_diagnostics_notice', 'kg_housekeeping', $summary );
		return $summary;
	}

	/**
	 * Internal dispatcher used by `run_housekeeping_cycle()` AND the chunked
	 * AJAX endpoint when op=run_housekeeping_step is invoked from the runner.
	 */
	private function run_op_step( $op, $size ) {
		switch ( $op ) {
			case 'backfill':            return $this->step_backfill();
			case 'clean_passages':      return $this->step_clean( 'passages',  $size );
			case 'clean_entities':      return $this->step_clean( 'entities',  $size );
			case 'clean_relations':     return $this->step_clean( 'relations', $size );
			case 'clean_triplet_queue': return $this->step_clean_triplet_queue( $size );
			case 'reembed_entities':    return $this->step_reembed( 'entities',  $size );
			case 'reembed_relations':   return $this->step_reembed( 'relations', $size );
		}
		return [ 'ok' => false, 'done' => true, 'message' => 'unknown op: ' . $op ];
	}

	// ─────────────────────────────────────────────────────────────────
	// Render.
	// ─────────────────────────────────────────────────────────────────

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions.' );
		}
		global $wpdb;

		$progress      = $this->storage_progress();
		$fs            = $this->filesystem_stats();
		$dual          = (int) get_option( BizCity_KG_Filestore_Dispatcher::OPT_DUAL_WRITE, 0 );
		$next_cron     = wp_next_scheduled( BizCity_KG_Filestore_Backfill::HOOK );
		$parity_cached = get_transient( 'bizcity_kg_filestore_parity' );
		$msg = '';
		if ( isset( $_GET['kg_msg'] ) ) {
			$key = 'bizcity_kg_filestore_msg_' . get_current_user_id();
			$msg = (string) get_transient( $key );
			if ( $msg !== '' ) { delete_transient( $key ); }
		}
		$blog_id       = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		$post_url      = admin_url( 'admin-post.php' );
		?>
		<div class="wrap">
			<h1>BizCity KG Filestore — Migration Console</h1>
			<p class="description">
				Phase 0.7 · Wave F2.5 · blog_id=<?php echo esc_html( $blog_id ); ?>
				· Dual-write: <strong style="color:<?php echo $dual ? '#00674e' : '#b32d2e'; ?>"><?php echo $dual ? 'ON' : 'OFF'; ?></strong>
				· Next backfill cron:
				<?php echo $next_cron ? esc_html( human_time_diff( time(), $next_cron ) . ' (in ' . gmdate( 'H:i:s', max( 0, $next_cron - time() ) ) . ')' ) : '—'; ?>
			</p>

			<?php if ( $msg !== '' ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $msg ); ?></p></div>
			<?php endif; ?>

			<h2>Storage version progress</h2>
			<table class="widefat striped" style="max-width:900px">
				<thead>
					<tr>
						<th>Table</th><th>Total</th><th>v1 (inline)</th><th>v2 (filestore)</th><th>Progress</th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $progress as $key => $r ) : ?>
					<tr>
						<td><code><?php echo esc_html( $r['table'] ); ?></code></td>
						<?php if ( empty( $r['has_col'] ) ) : ?>
							<td colspan="4"><em style="color:#b32d2e">storage_ver column missing — run schema migration (F0)</em></td>
						<?php else : ?>
							<td><?php echo number_format_i18n( $r['total'] ); ?></td>
							<td style="color:#b35b00"><?php echo number_format_i18n( $r['v1'] ); ?></td>
							<td style="color:#00674e"><?php echo number_format_i18n( $r['v2'] ); ?></td>
							<td>
								<div style="background:#eee;border-radius:3px;overflow:hidden;height:14px;width:200px;display:inline-block;vertical-align:middle">
									<div style="background:#00674e;height:100%;width:<?php echo esc_attr( $r['pct'] ); ?>%"></div>
								</div>
								<strong style="margin-left:8px"><?php echo esc_html( $r['pct'] ); ?>%</strong>
							</td>
						<?php endif; ?>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h2 style="margin-top:24px">On-disk footprint</h2>
			<table class="widefat striped" style="max-width:700px">
				<tbody>
					<tr><td style="width:40%"><strong>Root</strong></td><td><code><?php echo esc_html( $fs['root'] ); ?></code> <?php echo $fs['exists'] ? '✓' : '<span style="color:#b32d2e">✗ missing</span>'; ?></td></tr>
					<tr><td><strong>Notebook folders</strong></td><td><?php echo number_format_i18n( $fs['notebook_count'] ); ?></td></tr>
					<tr><td><strong>File count</strong></td><td><?php echo number_format_i18n( $fs['files'] ); ?></td></tr>
					<tr><td><strong>Bytes on disk</strong></td><td><strong><?php echo esc_html( size_format( $fs['bytes'], 2 ) ); ?></strong></td></tr>
				</tbody>
			</table>

			<h2 style="margin-top:24px">Manual actions</h2>
			<div style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-start">

				<form method="post" action="<?php echo esc_url( $post_url ); ?>" style="padding:12px;border:1px solid #ddd;border-radius:4px;min-width:240px">
					<?php wp_nonce_field( self::NONCE_ACTION ); ?>
					<input type="hidden" name="action"    value="bizcity_kg_filestore_action">
					<input type="hidden" name="kg_action" value="run_backfill">
					<strong>Run backfill batch</strong>
					<p style="margin:6px 0;color:#666;font-size:12px">
						Loops <code>BATCH_SIZE=<?php echo (int) BizCity_KG_Filestore_Backfill::BATCH_SIZE; ?></code>
						rows × 3 tables until <code>storage_ver=1</code> is drained
						<strong>or 25s budget</strong> is hit (whichever first).
						Force-enables dual-write for the loop only if currently OFF.
					</p>
					<button type="submit" class="button button-primary">▶ Run until done (≤25s)</button>
				</form>

				<form method="post" action="<?php echo esc_url( $post_url ); ?>" style="padding:12px;border:1px solid #ddd;border-radius:4px;min-width:240px">
					<?php wp_nonce_field( self::NONCE_ACTION ); ?>
					<input type="hidden" name="action"    value="bizcity_kg_filestore_action">
					<input type="hidden" name="kg_action" value="verify_parity">
					<strong>Verify parity (sample)</strong>
					<p style="margin:6px 0;color:#666;font-size:12px">
						Random <?php echo (int) self::PARITY_SAMPLE; ?> rows where <code>storage_ver=2</code>:
						compare sha256(DB.content) vs sha256(file body). Required gate for Wave F3 → F4.
					</p>
					<button type="submit" class="button">⚖ Sample <?php echo (int) self::PARITY_SAMPLE; ?> rows</button>
				</form>

				<form method="post" action="<?php echo esc_url( $post_url ); ?>" style="padding:12px;border:1px solid #ddd;border-radius:4px;min-width:240px">
					<?php wp_nonce_field( self::NONCE_ACTION ); ?>
					<input type="hidden" name="action"    value="bizcity_kg_filestore_action">
					<input type="hidden" name="kg_action" value="toggle_dual_write">
					<strong>Toggle dual-write</strong>
					<p style="margin:6px 0;color:#666;font-size:12px">
						Currently: <strong><?php echo $dual ? 'ON' : 'OFF'; ?></strong>.
						Turning ON schedules the 5-min cron + writes every new row to file too.
					</p>
					<button type="submit" class="button"><?php echo $dual ? 'Turn OFF' : 'Turn ON'; ?></button>
				</form>
			</div>

			<?php if ( is_array( $parity_cached ) ) : ?>
				<h2 style="margin-top:24px">Last parity sample
					<span style="font-size:12px;font-weight:normal;color:#666">
						(<?php echo esc_html( human_time_diff( (int) $parity_cached['ts'], time() ) ); ?> ago)
					</span>
				</h2>
				<p>
					Checked <strong><?php echo (int) $parity_cached['checked']; ?></strong>
					· <span style="color:#00674e">Match <strong><?php echo (int) $parity_cached['match']; ?></strong></span>
					· <span style="color:#b32d2e">Mismatch <strong><?php echo (int) $parity_cached['mismatch']; ?></strong></span>
					· <span style="color:#b35b00">Missing-file <strong><?php echo (int) $parity_cached['missing']; ?></strong></span>
				</p>
				<?php if ( ! empty( $parity_cached['details'] ) ) : ?>
					<table class="widefat striped" style="max-width:900px">
						<thead><tr><th>Passage ID</th><th>Status</th><th>DB hash</th><th>File hash</th><th>Meta hash</th></tr></thead>
						<tbody>
						<?php foreach ( $parity_cached['details'] as $d ) : ?>
							<tr>
								<td><code><?php echo (int) $d['id']; ?></code></td>
								<td><?php
									$color = $d['status'] === 'ok' ? '#00674e' : ( $d['status'] === 'mismatch' ? '#b32d2e' : '#b35b00' );
									echo '<strong style="color:' . esc_attr( $color ) . '">' . esc_html( $d['status'] ) . '</strong>';
								?></td>
								<td><code><?php echo esc_html( $d['db_hash']   ?? '—' ); ?></code></td>
								<td><code><?php echo esc_html( $d['file_hash'] ?? '—' ); ?></code></td>
								<td><code><?php echo esc_html( $d['meta_hash'] ?? '—' ); ?></code></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			<?php endif; ?>

			<?php
			// ─────────────────────────────────────────────────────────
			// Wave F4.1 — Chunked runner UI (2026-05-26).
			// Each button drives a JS loop calling admin-ajax in small chunks
			// and streams progress to the console below. Sync forms further
			// down stay as no-JS fallback.
			// ─────────────────────────────────────────────────────────
			$ajax_nonce = wp_create_nonce( self::NONCE_ACTION );
			$ent_null   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . BizCity_KG_Database::instance()->tbl_entities()  . " WHERE embedding IS NULL AND status='approved' AND deleted_at IS NULL" );
			$rel_null   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . BizCity_KG_Database::instance()->tbl_relations() . " WHERE embedding IS NULL AND status='approved' AND deleted_at IS NULL" );
			// Backfill v1 row counts (the bigger this is, the longer Backfill will run).
			$v1_pas = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . BizCity_KG_Database::instance()->tbl_passages()  . " WHERE storage_ver=1" );
			$v1_ent = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . BizCity_KG_Database::instance()->tbl_entities()  . " WHERE storage_ver=1" );
			$v1_rel = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . BizCity_KG_Database::instance()->tbl_relations() . " WHERE storage_ver=1" );
			$v1_total = $v1_pas + $v1_ent + $v1_rel;
			// Housekeeping last-run summary + next-run time.
			$hk_last     = get_option( self::HOUSEKEEPING_OPT_LAST, [] );
			$hk_next_ts  = wp_next_scheduled( self::HOUSEKEEPING_HOOK );
			?>
			<h2 style="margin-top:24px">⚡ Chunked runner (no timeout)
				<span style="font-size:12px;font-weight:normal;color:#666">— recommended path, replaces sync buttons below</span>
			</h2>
			<p class="description" style="max-width:900px;color:#666">
				Each button below runs in <strong>small chunks via AJAX</strong>: 1000 rows/step for clean ops,
				10 embedding calls/step for re-embed, 1 backfill pass (≤1500 rows) per step.
				Watch the console for live progress.
				Press <strong>Stop</strong> anytime — the next chunk simply won't be requested.
			</p>
			<div style="background:#f0f9ff;border:1px solid #bae6fd;padding:8px 12px;border-radius:4px;margin:8px 0;font:12px/1.5 system-ui">
				<strong>🏥 Health-checker housekeeping cycle (cadence: <code><?php echo esc_html( self::current_interval() ); ?></code>, this site only):</strong>
				<?php if ( ! empty( $hk_last['t0'] ) ) : ?>
					last run <code><?php echo esc_html( gmdate( 'Y-m-d H:i', (int) $hk_last['t0'] ) ); ?>Z</code>
					· elapsed <code><?php echo (int) ( $hk_last['elapsed_ms'] ?? 0 ); ?>ms</code>
					· phases <code><?php echo count( $hk_last['phases'] ?? [] ); ?></code>
					<?php if ( ! empty( $hk_last['errors'] ) ) : ?>
						· <span style="color:#dc2626">errors: <?php echo esc_html( implode( '; ', (array) $hk_last['errors'] ) ); ?></span>
					<?php endif; ?>
				<?php else : ?>
					<em>not run yet on this site.</em>
				<?php endif; ?>
				· next: <code><?php echo $hk_next_ts ? esc_html( gmdate( 'Y-m-d H:i', (int) $hk_next_ts ) . 'Z' ) : 'unscheduled'; ?></code>
			</div>

			<?php
			// ─────────────────────────────────────────────────────────
			// Cron schedule settings + manual "run now" + log history
			// (2026-05-27 — replaces hard-coded 3-day cadence; per-site log).
			// ─────────────────────────────────────────────────────────
			$hk_interval = self::current_interval();
			$hk_log      = (array) get_option( self::HOUSEKEEPING_LOG_OPT, [] );
			$hk_log      = array_reverse( $hk_log ); // newest first
			?>
			<div style="display:flex;gap:16px;flex-wrap:wrap;align-items:stretch;margin:8px 0 16px">
				<form method="post" action="<?php echo esc_url( $post_url ); ?>" style="flex:1;min-width:280px;padding:12px;border:1px solid #94a3b8;border-radius:4px;background:#f8fafc">
					<?php wp_nonce_field( self::NONCE_ACTION ); ?>
					<input type="hidden" name="action"    value="bizcity_kg_filestore_action">
					<input type="hidden" name="kg_action" value="set_schedule">
					<strong>⏱ Cron cadence (per-site)</strong>
					<p style="margin:6px 0;color:#666;font-size:12px">Mỗi site giữ schedule riêng. Đổi cadence sẽ unschedule + reschedule hook <code><?php echo esc_html( self::HOUSEKEEPING_HOOK ); ?></code> ngay lập tức.</p>
					<select name="hk_interval" style="width:100%">
						<option value="daily"   <?php selected( $hk_interval, 'daily'   ); ?>>Hàng ngày (1 ngày / lần)</option>
						<option value="3days"   <?php selected( $hk_interval, '3days'   ); ?>>3 ngày / lần (legacy)</option>
						<option value="weekly"  <?php selected( $hk_interval, 'weekly'  ); ?>>Hàng tuần (7 ngày / lần) — recommended</option>
						<option value="monthly" <?php selected( $hk_interval, 'monthly' ); ?>>Hàng tháng (30 ngày / lần)</option>
					</select>
					<button type="submit" class="button button-primary" style="margin-top:8px">💾 Lưu &amp; reschedule</button>
				</form>
				<form method="post" action="<?php echo esc_url( $post_url ); ?>" onsubmit="return confirm('Chạy full housekeeping ngay (server-side, bounded 120s)?');" style="flex:1;min-width:280px;padding:12px;border:1px solid #0ea5e9;border-radius:4px;background:#f0f9ff">
					<?php wp_nonce_field( self::NONCE_ACTION ); ?>
					<input type="hidden" name="action"    value="bizcity_kg_filestore_action">
					<input type="hidden" name="kg_action" value="run_housekeeping_now">
					<strong>▶ Chạy housekeeping ngay (server-side)</strong>
					<p style="margin:6px 0;color:#666;font-size:12px">Bằng đúng code path cron sẽ chạy. Bounded 120s budget — sẽ ghi summary vào log bên dưới + tab Cron Health.</p>
					<button type="submit" class="button button-primary">🏥 Run cron-equivalent now</button>
				</form>
				<form method="post" action="<?php echo esc_url( $post_url ); ?>" onsubmit="return confirm('Xoá toàn bộ log housekeeping của site này?');" style="flex:1;min-width:220px;padding:12px;border:1px solid #b35b00;border-radius:4px;background:#fff7ed">
					<?php wp_nonce_field( self::NONCE_ACTION ); ?>
					<input type="hidden" name="action"    value="bizcity_kg_filestore_action">
					<input type="hidden" name="kg_action" value="clear_housekeeping_log">
					<strong>🗑 Xoá log housekeeping</strong>
					<p style="margin:6px 0;color:#666;font-size:12px">Reset ring buffer (giữ tối đa <?php echo (int) self::HOUSEKEEPING_LOG_MAX; ?> entries) trên site này.</p>
					<button type="submit" class="button">🗑 Clear log</button>
				</form>
			</div>

			<div style="margin:8px 0 16px;padding:12px;border:1px solid #16a34a;border-radius:4px;background:#f0fdf4">
				<strong>✅ Verify health (read-only, on-demand)</strong>
				<p style="margin:6px 0;color:#666;font-size:12px">
					Bấm để kiểm tra nhanh: <strong>Relations / Entities / Triplet queue / Filestore</strong>
					có còn null embeddings, storage_ver=1 hay raw_llm_output chưa clean không.
					Kết quả hiển thị inline (OK / WARN / FAIL).
				</p>
				<div style="display:flex;gap:8px;flex-wrap:wrap">
					<button type="button" class="button kg-check-btn" data-kind="relations">🔍 Check Relations</button>
					<button type="button" class="button kg-check-btn" data-kind="entities">🔍 Check Entities</button>
					<button type="button" class="button kg-check-btn" data-kind="triplets">🔍 Check Triplets</button>
					<button type="button" class="button kg-check-btn" data-kind="files">🔍 Check Filestore</button>
				</div>
				<div id="kg-health-result" style="margin-top:8px;font:12px/1.5 ui-monospace,Menlo,Consolas,monospace;background:#fff;border:1px solid #d1d5db;border-radius:4px;padding:8px;min-height:36px;max-height:200px;overflow:auto">
					<em style="color:#94a3b8">No checks run yet.</em>
				</div>
			</div>

			<?php if ( $hk_log ) : ?>
			<div style="margin:8px 0 16px">
				<strong>📜 Housekeeping log (this site, newest first — max <?php echo (int) self::HOUSEKEEPING_LOG_MAX; ?> entries)</strong>
				<table class="widefat striped" style="margin-top:6px;font-size:12px">
					<thead><tr>
						<th>When (UTC)</th><th>blog_id</th><th>elapsed ms</th><th>phases</th><th>processed</th><th>failed</th><th>errors</th>
					</tr></thead>
					<tbody>
					<?php foreach ( $hk_log as $row ) :
						$phases = (array) ( $row['phases'] ?? [] );
						$proc   = 0; $fail = 0;
						foreach ( $phases as $ph ) {
							$proc += (int) ( $ph['processed'] ?? 0 );
							$fail += (int) ( $ph['failed']    ?? 0 );
						}
						$errs = $row['errors'] ?? [];
					?>
						<tr>
							<td><code><?php echo esc_html( gmdate( 'Y-m-d H:i', (int) ( $row['t0'] ?? 0 ) ) ); ?>Z</code></td>
							<td><?php echo (int) ( $row['blog_id'] ?? 0 ); ?></td>
							<td><?php echo (int) ( $row['elapsed_ms'] ?? 0 ); ?></td>
							<td><?php echo count( $phases ); ?></td>
							<td><?php echo (int) $proc; ?></td>
							<td<?php echo $fail > 0 ? ' style="color:#dc2626;font-weight:600"' : ''; ?>><?php echo (int) $fail; ?></td>
							<td<?php echo ! empty( $errs ) ? ' style="color:#dc2626"' : ''; ?>><?php echo esc_html( empty( $errs ) ? '—' : implode( '; ', (array) $errs ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php endif; ?>

			<h2 style="margin-top:24px">⚡ Chunked runner (no timeout)
				<span style="font-size:12px;font-weight:normal;color:#666">— manual maintenance</span>
			</h2>
			<div id="kg-runner" data-nonce="<?php echo esc_attr( $ajax_nonce ); ?>" data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" style="margin-top:8px">
				<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px">
					<button type="button" class="button button-primary kg-run-btn" data-op="backfill">📁 Backfill v1→v2 (<?php echo number_format_i18n( $v1_total ); ?> left: p=<?php echo number_format_i18n( $v1_pas ); ?> e=<?php echo number_format_i18n( $v1_ent ); ?> r=<?php echo number_format_i18n( $v1_rel ); ?>)</button>
					<span style="border-left:1px solid #ccc;margin:0 4px"></span>
					<button type="button" class="button kg-run-btn" data-op="clean_passages"      data-size="1000">🗑 Clean passages</button>
					<button type="button" class="button kg-run-btn" data-op="clean_entities"      data-size="1000">🗑 Clean entities</button>
					<button type="button" class="button kg-run-btn" data-op="clean_relations"     data-size="1000">🗑 Clean relations</button>
					<button type="button" class="button kg-run-btn" data-op="clean_triplet_queue" data-size="1000">🗑 Clean triplet queue</button>
					<button type="button" class="button button-primary kg-run-btn" data-op="reembed_entities"  data-size="10">🔧 Re-embed entities (<?php echo number_format_i18n( $ent_null ); ?> null)</button>
					<button type="button" class="button button-primary kg-run-btn" data-op="reembed_relations" data-size="10">🔧 Re-embed relations (<?php echo number_format_i18n( $rel_null ); ?> null)</button>
					<span style="border-left:1px solid #ccc;margin:0 4px"></span>
					<button type="button" class="button kg-run-btn" data-op="optimize_one" data-target="passages">⚙ OPTIMIZE passages</button>
					<button type="button" class="button kg-run-btn" data-op="optimize_one" data-target="entities">⚙ OPTIMIZE entities</button>
					<button type="button" class="button kg-run-btn" data-op="optimize_one" data-target="relations">⚙ OPTIMIZE relations</button>
					<button type="button" class="button kg-run-btn" data-op="optimize_one" data-target="triplet_queue">⚙ OPTIMIZE triplet_queue</button>
					<span style="border-left:1px solid #ccc;margin:0 4px"></span>
					<button type="button" class="button button-hero" id="kg-run-housekeeping" style="background:#0ea5e9;color:#fff;border-color:#0284c7">🏥 Run housekeeping (all steps)</button>
					<button type="button" class="button button-link-delete" id="kg-run-stop" disabled>■ Stop</button>
					<button type="button" class="button" id="kg-run-clear">🧹 Clear console</button>
				</div>
				<div id="kg-run-status" style="font:12px/1.4 system-ui;color:#444;margin-bottom:4px">Idle.</div>
				<pre id="kg-run-console" style="max-height:340px;overflow:auto;background:#0f172a;color:#e2e8f0;font-family:Consolas,monospace;font-size:11px;padding:8px;border-radius:4px;border:1px solid #1e293b;margin:0;white-space:pre-wrap;word-break:break-word">[idle] click any chunked button above to start.
</pre>
			</div>
			<script>
			(function(){
				var root = document.getElementById('kg-runner');
				if (!root) return;
				var ajaxUrl = root.getAttribute('data-ajax-url');
				var nonce   = root.getAttribute('data-nonce');
				var consoleEl = document.getElementById('kg-run-console');
				var statusEl  = document.getElementById('kg-run-status');
				var stopBtn   = document.getElementById('kg-run-stop');
				var clearBtn  = document.getElementById('kg-run-clear');
				var buttons   = root.querySelectorAll('.kg-run-btn');
				var stopFlag  = false;
				var running   = false;

				function ts() {
					var d = new Date();
					return d.toTimeString().slice(0,8) + '.' + String(d.getMilliseconds()).padStart(3,'0');
				}
				function log(line, color) {
					var span = document.createElement('span');
					span.textContent = '[' + ts() + '] ' + line + '\n';
					if (color) span.style.color = color;
					consoleEl.appendChild(span);
					consoleEl.scrollTop = consoleEl.scrollHeight;
				}
				function setRunning(on) {
					running = on;
					stopBtn.disabled = !on;
					buttons.forEach(function(b){ b.disabled = on; });
					var hk = document.getElementById('kg-run-housekeeping');
					if (hk) hk.disabled = on;
					statusEl.textContent = on ? '⏳ Running…' : 'Idle.';
				}
				clearBtn.addEventListener('click', function(){
					consoleEl.textContent = '[cleared]\n';
				});
				stopBtn.addEventListener('click', function(){
					stopFlag = true;
					log('→ stop requested; will halt after current chunk.', '#fcd34d');
				});

				function step(op, target, size) {
					var body = new URLSearchParams();
					body.set('action', 'bizcity_kg_filestore_step');
					body.set('_nonce', nonce);
					body.set('op',     op);
					if (target) body.set('target', target);
					if (size)   body.set('size',   size);
					return fetch(ajaxUrl, {
						method: 'POST',
						credentials: 'same-origin',
						headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
						body: body.toString()
					}).then(function(r){
						if (!r.ok) throw new Error('HTTP ' + r.status);
						return r.json();
					});
				}

				async function run(op, target, size) {
					if (running) { log('busy; ignored ' + op, '#fcd34d'); return; }
					stopFlag = false;
					setRunning(true);
					log('▶ start ' + op + (target ? ' target=' + target : '') + ' size=' + size, '#7dd3fc');
					var step_no = 0;
					var total_processed = 0;
					var total_failed = 0;
					try {
						while (!stopFlag) {
							step_no++;
							var resp = await step(op, target, size);
							if (!resp || !resp.success) {
								log('✗ step ' + step_no + ' error: ' + (resp && resp.data && resp.data.message || 'unknown'), '#fda4af');
								break;
							}
							var d = resp.data || {};
							total_processed += (d.processed || 0);
							total_failed    += (d.failed    || 0);
							var msg = '· step ' + step_no + ': ' + (d.message || '') + ' (' + (d.ms||0) + 'ms)';
							log(msg, d.done ? '#86efac' : '#cbd5e1');
							if (d.done) {
								log('✓ done. Total processed=' + total_processed + (total_failed?(' failed=' + total_failed):''), '#86efac');
								break;
							}
							// Small spacing between calls so the browser/server don't flood.
							await new Promise(function(r){ setTimeout(r, 200); });
						}
						if (stopFlag) log('■ stopped by user. Total processed=' + total_processed, '#fcd34d');
					} catch (e) {
						log('✗ exception: ' + (e && e.message || e), '#fda4af');
					}
					setRunning(false);
				}

				buttons.forEach(function(btn){
					btn.addEventListener('click', function(){
						var op     = btn.getAttribute('data-op');
						var target = btn.getAttribute('data-target') || '';
						var size   = btn.getAttribute('data-size') || '';
						run(op, target, size);
					});
				});

				// 🏥 Housekeeping chain — runs the same sequence as the 3-day cron,
				// but interactively so the operator can see progress and stop anytime.
				async function runChain(steps) {
					if (running) { log('busy; ignored housekeeping chain', '#fcd34d'); return; }
					stopFlag = false;
					setRunning(true);
					log('▶ START housekeeping chain (' + steps.length + ' phases)', '#a5b4fc');
					var t_chain = Date.now();
					try {
						for (var i = 0; i < steps.length; i++) {
							if (stopFlag) { log('■ chain stopped by user.', '#fcd34d'); break; }
							var s = steps[i];
							log('── [' + (i+1) + '/' + steps.length + '] phase: ' + s.op + (s.target?' '+s.target:'') + ' ─────', '#c4b5fd');
							var step_no = 0;
							var phase_processed = 0;
							var max_steps = s.max_steps || 500;
							while (!stopFlag && step_no < max_steps) {
								step_no++;
								var resp;
								try {
									resp = await step(s.op, s.target || '', s.size || '');
								} catch (e) {
									log('  ✗ http exception: ' + (e && e.message || e), '#fda4af');
									break;
								}
								if (!resp || !resp.success) {
									log('  ✗ step ' + step_no + ' error: ' + (resp && resp.data && resp.data.message || 'unknown'), '#fda4af');
									break;
								}
								var d = resp.data || {};
								phase_processed += (d.processed || 0);
								log('  · step ' + step_no + ': ' + (d.message || '') + ' (' + (d.ms||0) + 'ms)', d.done?'#86efac':'#cbd5e1');
								if (d.done) {
									log('  ✓ phase done (processed=' + phase_processed + ')', '#86efac');
									break;
								}
								await new Promise(function(r){ setTimeout(r, 200); });
							}
							if (step_no >= max_steps) {
								log('  ⚠ phase reached max_steps cap (' + max_steps + ')', '#fcd34d');
							}
						}
						var elapsed = ((Date.now() - t_chain)/1000).toFixed(1);
						log('✓ HOUSEKEEPING CHAIN COMPLETE in ' + elapsed + 's', '#86efac');
					} catch (e) {
						log('✗ chain exception: ' + (e && e.message || e), '#fda4af');
					}
					setRunning(false);
				}

				var hkBtn = document.getElementById('kg-run-housekeeping');
				if (hkBtn) {
					hkBtn.addEventListener('click', function(){
						if (!confirm('Chạy full housekeeping (backfill + clean × 4 + reembed × 2 + optimize × 4)? Có thể mất vài phút.')) return;
						runChain([
							{ op:'backfill',                              max_steps: 200 },
							{ op:'clean_passages',      size:'1000',      max_steps: 200 },
							{ op:'clean_entities',      size:'1000',      max_steps: 200 },
							{ op:'clean_relations',     size:'1000',      max_steps: 200 },
							{ op:'clean_triplet_queue', size:'1000',      max_steps: 200 },
							{ op:'reembed_entities',    size:'10',        max_steps: 100 },
							{ op:'reembed_relations',   size:'10',        max_steps: 100 },
							{ op:'optimize_one', target:'passages',      max_steps: 1 },
							{ op:'optimize_one', target:'entities',      max_steps: 1 },
							{ op:'optimize_one', target:'relations',     max_steps: 1 },
							{ op:'optimize_one', target:'triplet_queue', max_steps: 1 },
						]);
					});
				}

				// ✅ Verify health buttons (read-only one-shot AJAX) — 2026-05-27.
				var hres = document.getElementById('kg-health-result');
				document.querySelectorAll('.kg-check-btn').forEach(function(btn){
					btn.addEventListener('click', async function(){
						var kind = btn.getAttribute('data-kind');
						btn.disabled = true; btn.textContent = '⏳ checking ' + kind + '…';
						try {
							var resp = await step('health_' + kind, '', '');
							var d = (resp && resp.data) || {};
							var st = (d.health && d.health.status) || 'fail';
							var color = st === 'ok' ? '#16a34a' : (st === 'warn' ? '#b35b00' : '#dc2626');
							var icon  = st === 'ok' ? '✅' : (st === 'warn' ? '⚠' : '❌');
							var line  = document.createElement('div');
							line.style.color = color;
							line.style.borderBottom = '1px dashed #e5e7eb';
							line.style.padding = '4px 0';
							line.textContent = icon + ' [' + new Date().toISOString().slice(11,19) + '] ' + (d.message || '(no message)');
							if (hres.querySelector('em')) hres.innerHTML = '';
							hres.insertBefore(line, hres.firstChild);
						} catch (e) {
							var err = document.createElement('div');
							err.style.color = '#dc2626';
							err.textContent = '❌ exception: ' + (e && e.message || e);
							if (hres.querySelector('em')) hres.innerHTML = '';
							hres.insertBefore(err, hres.firstChild);
						}
						btn.disabled = false; btn.textContent = '🔍 Check ' + kind.charAt(0).toUpperCase() + kind.slice(1);
					});
				});
			})();
			</script>

			<h2 style="margin-top:24px">Cleanup inline payload (Wave F4 — reclaim MySQL space) <span style="font-size:12px;font-weight:normal;color:#666">— legacy sync (use chunked runner above instead)</span></h2>
			<p class="description" style="max-width:900px;color:#666">
				After dual-write is ON and parity passes, these actions
				<strong>empty the inline columns</strong> (set to <code>NULL</code> /
				<code>''</code>) for rows already on filestore (<code>storage_ver=2</code>).
				Reader paths now go through <code>BizCity_KG_Content_Router</code> so the
				API keeps working. Schema columns stay — DROP COLUMN is a separate later
				wave. <strong>Heads-up:</strong> after cleanup, MySQL <code>LIKE</code>
				keyword search over <code>content/description/relation_text</code> stops
				returning results (RF11 — needs FTS5 sidecar). Run <strong>OPTIMIZE
				TABLE</strong> afterwards to actually shrink the .ibd file.
			</p>
			<div style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-start;margin-top:8px">
				<form method="post" action="<?php echo esc_url( $post_url ); ?>" onsubmit="return confirm('Empty content + embedding + metadata for ALL kg_passages rows where storage_ver=2?');" style="padding:12px;border:1px solid #b35b00;border-radius:4px;min-width:220px">
					<?php wp_nonce_field( self::NONCE_ACTION ); ?>
					<input type="hidden" name="action"    value="bizcity_kg_filestore_action">
					<input type="hidden" name="kg_action" value="clean_inline_passages">
					<strong>Null passages payload</strong>
					<p style="margin:6px 0;color:#666;font-size:12px">content, embedding (LONGTEXT), metadata. Where <code>storage_ver=2</code>.</p>
					<button type="submit" class="button">🗑 Clean passages</button>
				</form>
				<form method="post" action="<?php echo esc_url( $post_url ); ?>" onsubmit="return confirm('Empty description/aliases/embedding/metadata for ALL kg_entities rows where storage_ver=2?');" style="padding:12px;border:1px solid #b35b00;border-radius:4px;min-width:220px">
					<?php wp_nonce_field( self::NONCE_ACTION ); ?>
					<input type="hidden" name="action"    value="bizcity_kg_filestore_action">
					<input type="hidden" name="kg_action" value="clean_inline_entities">
					<strong>Null entities.* payload</strong>
					<p style="margin:6px 0;color:#666;font-size:12px">description, aliases, embedding, metadata.</p>
					<button type="submit" class="button">🗑 Clean entities</button>
				</form>
				<form method="post" action="<?php echo esc_url( $post_url ); ?>" onsubmit="return confirm('Empty relation_text/embedding/metadata for ALL kg_relations rows where storage_ver=2?');" style="padding:12px;border:1px solid #b35b00;border-radius:4px;min-width:220px">
					<?php wp_nonce_field( self::NONCE_ACTION ); ?>
					<input type="hidden" name="action"    value="bizcity_kg_filestore_action">
					<input type="hidden" name="kg_action" value="clean_inline_relations">
					<strong>Null relations.* payload</strong>
					<p style="margin:6px 0;color:#666;font-size:12px">relation_text, embedding, metadata.</p>
					<button type="submit" class="button">🗑 Clean relations</button>
				</form>
				<form method="post" action="<?php echo esc_url( $post_url ); ?>" onsubmit="return confirm('Null raw_llm_output for processed triplet_queue rows (status != pending)?');" style="padding:12px;border:1px solid #b35b00;border-radius:4px;min-width:220px">
					<?php wp_nonce_field( self::NONCE_ACTION ); ?>
					<input type="hidden" name="action"    value="bizcity_kg_filestore_action">
					<input type="hidden" name="kg_action" value="clean_triplet_queue">
					<strong>Null triplet_queue raw</strong>
					<p style="margin:6px 0;color:#666;font-size:12px">Keeps pending rows untouched. NULLs <code>raw_llm_output</code> on approved/rejected/merged.</p>
					<button type="submit" class="button">🗑 Clean triplet queue</button>
				</form>
				<form method="post" action="<?php echo esc_url( $post_url ); ?>" onsubmit="return confirm('Run OPTIMIZE TABLE on kg_passages/entities/relations/triplet_queue? Locks tables briefly.');" style="padding:12px;border:1px solid #1d4ed8;border-radius:4px;min-width:220px">
					<?php wp_nonce_field( self::NONCE_ACTION ); ?>
					<input type="hidden" name="action"    value="bizcity_kg_filestore_action">
					<input type="hidden" name="kg_action" value="optimize_tables">
					<strong>OPTIMIZE TABLE</strong>
					<p style="margin:6px 0;color:#666;font-size:12px">Reclaim free space after cleanup. Brief table lock.</p>
					<button type="submit" class="button">⚙ Optimize 4 tables</button>
				</form>
				<?php
				// 2026-05-20 — Rescue button. Count NULL-embedding entities/relations
				// up-front so the operator sees scope before clicking.
				$ent_null = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . BizCity_KG_Database::instance()->tbl_entities()  . " WHERE embedding IS NULL AND status='approved' AND deleted_at IS NULL" );
				$rel_null = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . BizCity_KG_Database::instance()->tbl_relations() . " WHERE embedding IS NULL AND status='approved' AND deleted_at IS NULL" );
				?>
				<form method="post" action="<?php echo esc_url( $post_url ); ?>" style="padding:12px;border:2px solid <?php echo ( $ent_null + $rel_null ) > 0 ? '#b32d2e' : '#9ca3af'; ?>;border-radius:4px;min-width:240px;background:<?php echo ( $ent_null + $rel_null ) > 0 ? '#fef2f2' : '#f9fafb'; ?>">
					<?php wp_nonce_field( self::NONCE_ACTION ); ?>
					<input type="hidden" name="action"    value="bizcity_kg_filestore_action">
					<input type="hidden" name="kg_action" value="rebuild_kg_embeddings">
					<strong>🔧 Re-embed entities / relations</strong>
					<p style="margin:6px 0;color:#666;font-size:12px">
						NULL embeddings: <strong style="color:<?php echo $ent_null > 0 ? '#b32d2e' : '#00674e'; ?>"><?php echo number_format_i18n( $ent_null ); ?> entities</strong>,
						<strong style="color:<?php echo $rel_null > 0 ? '#b32d2e' : '#00674e'; ?>"><?php echo number_format_i18n( $rel_null ); ?> relations</strong>.<br>
						Fixes Graph Nexus "cited orange node" miss when vector seed search returns 0.
					</p>
					<button type="submit" class="button button-primary" <?php disabled( $ent_null + $rel_null, 0 ); ?>>🔧 Re-embed batch (200+200)</button>
				</form>
			</div>

			<?php
			// ─────────────────────────────────────────────────────────
			// Ingest pipeline log viewer — Wave F5 observability.
			// Live tail of `BizCity_Twin_Debug::trace()` events scoped to `kg.*`.
			// Operator toggles debug ON, performs an upload, refreshes this page.
			// ─────────────────────────────────────────────────────────
			$debug_on  = '1' === (string) get_option( 'bizcity_twin_debug', '' );
			$trace_log = get_option( self::LOG_OPTION, [] );
			if ( ! is_array( $trace_log ) ) { $trace_log = []; }
			?>
			<h2 style="margin-top:24px">Ingest pipeline log <span style="font-size:12px;color:#666">(last <?php echo (int) self::LOG_MAX; ?> kg.* trace events)</span></h2>
			<div style="display:flex;gap:8px;margin:8px 0;align-items:center">
				<form method="post" action="<?php echo esc_url( $post_url ); ?>" style="display:inline">
					<?php wp_nonce_field( self::NONCE_ACTION ); ?>
					<input type="hidden" name="action"    value="bizcity_kg_filestore_action">
					<input type="hidden" name="kg_action" value="toggle_debug">
					<button type="submit" class="button"><?php echo $debug_on ? '🔴 Turn Twin Debug OFF' : '🟢 Turn Twin Debug ON'; ?></button>
				</form>
				<form method="post" action="<?php echo esc_url( $post_url ); ?>" style="display:inline" onsubmit="return confirm('Clear captured trace log?');">
					<?php wp_nonce_field( self::NONCE_ACTION ); ?>
					<input type="hidden" name="action"    value="bizcity_kg_filestore_action">
					<input type="hidden" name="kg_action" value="clear_trace_log">
					<button type="submit" class="button">🧹 Clear log</button>
				</form>
				<span style="color:#666;font-size:12px">
					Status: <?php echo $debug_on
						? '<strong style="color:#00674e">ON</strong> &mdash; new ingests will be captured here'
						: '<strong style="color:#b32d2e">OFF</strong> &mdash; toggle ON, run an upload, then refresh'; ?>
				</span>
			</div>
			<?php if ( empty( $trace_log ) ) : ?>
				<p style="color:#666"><em>No trace events captured. Toggle debug ON and run an ingest, then refresh this page.</em></p>
			<?php else : ?>
				<div style="max-height:420px;overflow:auto;border:1px solid #ddd;background:#0f172a;color:#e2e8f0;font-family:Consolas,monospace;font-size:11px;padding:8px;border-radius:4px">
				<?php
				$rows = array_reverse( $trace_log ); // newest first
				foreach ( $rows as $r ) {
					$ts    = date_i18n( 'H:i:s', (int) $r['t'] );
					$ev    = esc_html( (string) $r['event'] );
					$lvl   = esc_html( (string) $r['level'] );
					$ms    = (float) ( isset( $r['ms'] ) ? $r['ms'] : 0 );
					$data  = wp_json_encode( $r['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
					if ( false === $data ) { $data = '[unencodable]'; }
					// Truncate huge data blobs.
					if ( strlen( $data ) > 600 ) { $data = substr( $data, 0, 600 ) . '… (' . strlen( $data ) . 'b)'; }
					$color = $lvl === 'error' ? '#fda4af' : ( $lvl === 'warn' ? '#fcd34d' : '#7dd3fc' );
					echo '<div style="margin-bottom:2px"><span style="color:#94a3b8">[' . esc_html( $ts ) . ' +' . esc_html( number_format( $ms, 0 ) ) . 'ms]</span> '
						. '<span style="color:' . $color . ';font-weight:bold">' . $ev . '</span> '
						. '<span style="color:#cbd5e1">' . esc_html( $data ) . '</span></div>';
				}
				?>
				</div>
			<?php endif; ?>

			<h2 style="margin-top:24px">Wave gate checklist</h2>
			foreach ( $progress as $r ) { if ( ! empty( $r['has_col'] ) ) { $total_v1 += (int) $r['v1']; } }
			$ready_f3 = $dual === 1 && $total_v1 === 0;
			$ready_f4 = $ready_f3 && is_array( $parity_cached ) && (int) $parity_cached['mismatch'] === 0 && (int) $parity_cached['missing'] === 0;
			?>
			<ul>
				<li>Schema migrated (F0):
					<?php echo array_reduce( $progress, function ( $a, $r ) { return $a && ! empty( $r['has_col'] ); }, true )
						? '<strong style="color:#00674e">✓ ready</strong>'
						: '<strong style="color:#b32d2e">✗ pending</strong>'; ?>
				</li>
				<li>Dual-write enabled (F1): <?php echo $dual ? '<strong style="color:#00674e">✓ ON</strong>' : '<strong style="color:#b32d2e">✗ OFF</strong>'; ?></li>
				<li>Backfill drained (F2): <?php echo $total_v1 === 0 ? '<strong style="color:#00674e">✓ 0 rows left</strong>' : '<strong style="color:#b35b00">' . number_format_i18n( $total_v1 ) . ' rows pending</strong>'; ?></li>
				<li>Read file-first (F3): <?php echo $ready_f3 ? '<strong style="color:#00674e">✓ gate open</strong>' : '<em>blocked by above</em>'; ?></li>
				<li>Drop columns (F4/F5): <?php echo $ready_f4 ? '<strong style="color:#00674e">✓ parity verified</strong>' : '<em>need parity sample 0/0 + 30-day soak</em>'; ?></li>
			</ul>
		</div>
		<?php
	}
}
