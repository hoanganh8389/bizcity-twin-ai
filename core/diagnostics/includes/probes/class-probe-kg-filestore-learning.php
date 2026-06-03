<?php
/**
 * BizCity Diagnostics — kg.filestore.learning probe (Phase 0.7 Wave F4.1c).
 *
 * "Learning tab" for the Health Check Wizard — surfaces the health of the
 * 16 KG-Hub tables that drive the source → embed → triplet learning loop,
 * and validates that the 3-day housekeeping cron is keeping the filestore
 * (`wp-content/uploads/bizcity-kg/notebooks/*`) authoritative.
 *
 * Sub-steps emitted (read-only, bounded ≤25s):
 *   1. Schema inventory — all 16 KG tables exist on this blog (R-VFS §2).
 *   2. Filestore root reachable + dual-write flag.
 *   3. storage_ver=1 backlog (3 fat-payload tables).
 *   4. NULL embedding backlog (entities/relations).
 *   5. Triplet queue stale `raw_llm_output` (post-decision rows).
 *   6. Parity sample (10 random passages: DB body sha256 vs file body).
 *   7. Cron heartbeat — last housekeeping run + next scheduled.
 *
 * PASS  → all 6 health gates green.
 * FAIL  → schema missing, filestore root unreachable, or parity mismatch.
 * WARN  → backlog > 0 (still drainable by next 3-day cron / chunked runner).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-05-26 (PHASE-0.7-LEARN-VECTOR-FILE Wave F4.1c)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

final class BizCity_Probe_KG_Filestore_Learning implements BizCity_Diagnostics_Probe {

	/** The 16 KG-Hub tables surfaced on Repair Hub → knowledge group. */
	private const KG_TABLES = [
		'bizcity_kg_notebooks',
		'bizcity_kg_notebook_sources',
		'bizcity_kg_passages',
		'bizcity_kg_entities',
		'bizcity_kg_relations',
		'bizcity_kg_passage_entities',
		'bizcity_kg_passage_relations',
		'bizcity_kg_triplet_queue',
		'bizcity_kg_provenance',
		'bizcity_kg_scope_links',
		'bizcity_kg_sources',
		'bizcity_kg_mentions',
		'bizcity_kg_xref',
		'bizcity_kg_passage_identities',
		'bizcity_kg_source_progress_log',
		'bizcity_kg_usage_log',
	];

	public function id(): string          { return 'kg.filestore.learning'; }
	public function label(): string       { return 'KG Filestore Learning (3-day housekeeping)'; }
	public function description(): string {
		return 'Audit 16 bảng KG-Hub: schema, filestore root, backlog storage_ver=1, NULL embeddings, parity sha256, lịch cron 3 ngày. Mọi backlog drainable bằng Tools → BizCity KG Filestore → Housekeeping.';
	}
	public function severity(): string    { return 'warning'; } // not blocking ingest; backlog drains async
	public function order(): int          { return 85; }        // late — after schema (50) + vector-graph (80)
	public function icon(): string        { return 'brain-circuit'; }
	public function estimate_ms(): int    { return 25000; }     // bounded by parity sample (10 file reads)

	public function precondition() {
		if ( ! class_exists( 'BizCity_KG_Database' ) ) {
			return new \WP_Error( 'kg_hub_missing', 'BizCity_KG_Database không khả dụng (module knowledge/kg-hub chưa load).' );
		}
		if ( ! class_exists( 'BizCity_KG_Filestore_Diagnostic' ) ) {
			return new \WP_Error( 'filestore_missing', 'KG Filestore Diagnostic chưa được load.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		global $wpdb;
		$prefix = $wpdb->prefix;

		$summary_bits = [];
		$has_fail     = false;
		$has_warn     = false;

		// ─── Step 1: schema inventory ──────────────────────────────────
		$missing = [];
		foreach ( self::KG_TABLES as $bare ) {
			$full = $prefix . $bare;
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) );
			if ( $exists !== $full ) { $missing[] = $bare; }
		}
		if ( $missing ) {
			$has_fail = true;
			$ctx->emit_step( [
				'label'  => sprintf( 'Schema inventory · 16 KG tables (%d missing)', count( $missing ) ),
				'status' => 'fail',
				'detail' => 'Missing: ' . implode( ', ', $missing ) . '. Vào Diagnostics → Repair Hub → Auto-fix.',
			] );
		} else {
			$ctx->emit_step( [
				'label'  => 'Schema inventory · 16 KG tables present',
				'status' => 'pass',
				'detail' => implode( ', ', self::KG_TABLES ),
			] );
		}

		// ─── Step 2: filestore root + dual-write flag ─────────────────
		$uploads = wp_get_upload_dir();
		$root    = trailingslashit( $uploads['basedir'] ) . 'bizcity-kg/notebooks';
		// Auto-create lazy: thư mục per-site uploads chỉ tạo khi có write
		// thực tế nên probe phải tự `wp_mkdir_p` trước khi kết luận fail.
		if ( ! is_dir( $root ) ) {
			wp_mkdir_p( $root );
		}
		$root_ok = is_dir( $root ) && is_writable( $root );
		$dual    = class_exists( 'BizCity_KG_Filestore_Dispatcher' ) && BizCity_KG_Filestore_Dispatcher::is_enabled();
		if ( ! $root_ok ) {
			$has_fail = true;
			$reason   = ! is_dir( $root )
				? 'mkdir_failed (uploads parent không writable cho web user)'
				: 'directory exists nhưng không writable (check chmod/owner)';
			$ctx->emit_step( [
				'label'  => 'Filestore root reachable',
				'status' => 'fail',
				'detail' => $reason . ': ' . $root . '. Fix: `chown -R www-data:www-data ' . dirname( $root ) . '` hoặc `chmod 775` parent uploads/sites/{blog_id}/.',
			] );
		} else {
			$ctx->emit_step( [
				'label'  => 'Filestore root reachable · dual-write=' . ( $dual ? 'ON' : 'OFF' ),
				'status' => $dual ? 'pass' : 'warn',
				'detail' => $root,
			] );
			if ( ! $dual ) { $has_warn = true; }
		}

		// ─── Step 3: storage_ver=1 backlog (fat-payload tables) ───────
		$db    = BizCity_KG_Database::instance();
		$v1_p  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . $db->tbl_passages()  . " WHERE storage_ver=1" );
		$v1_e  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . $db->tbl_entities()  . " WHERE storage_ver=1" );
		$v1_r  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . $db->tbl_relations() . " WHERE storage_ver=1" );
		$v1    = $v1_p + $v1_e + $v1_r;
		$summary_bits[] = sprintf( 'v1=%d', $v1 );
		if ( $v1 > 0 ) {
			$has_warn = true;
			$ctx->emit_step( [
				'label'  => sprintf( 'Backfill backlog (storage_ver=1) · %d rows', $v1 ),
				'status' => 'warn',
				'detail' => sprintf( 'passages=%d entities=%d relations=%d — sẽ drain ở cron housekeeping kế tiếp.', $v1_p, $v1_e, $v1_r ),
			] );
		} else {
			$ctx->emit_step( [
				'label'  => 'Backfill backlog · 0 (filestore authoritative)',
				'status' => 'pass',
			] );
		}

		// ─── Step 4: NULL embeddings (entities/relations) ─────────────
		$ent_null = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . $db->tbl_entities()  . " WHERE embedding IS NULL AND status='approved' AND deleted_at IS NULL" );
		$rel_null = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . $db->tbl_relations() . " WHERE embedding IS NULL AND status='approved' AND deleted_at IS NULL" );
		$null_total = $ent_null + $rel_null;
		$summary_bits[] = sprintf( 'null_emb=%d', $null_total );
		if ( $null_total > 0 ) {
			$has_warn = true;
			$ctx->emit_step( [
				'label'  => sprintf( 'NULL embedding backlog · %d rows', $null_total ),
				'status' => 'warn',
				'detail' => sprintf( 'entities=%d relations=%d — chạy Re-embed (chunked runner) hoặc đợi cron housekeeping.', $ent_null, $rel_null ),
			] );
		} else {
			$ctx->emit_step( [
				'label'  => 'NULL embedding backlog · 0',
				'status' => 'pass',
			] );
		}

		// ─── Step 5: triplet queue stale raw_llm_output ───────────────
		$stale_raw = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM " . $db->tbl_triplet_queue() .
			" WHERE status <> 'pending' AND raw_llm_output IS NOT NULL AND raw_llm_output <> ''"
		);
		$summary_bits[] = sprintf( 'stale_raw=%d', $stale_raw );
		if ( $stale_raw > 0 ) {
			$has_warn = true;
			$ctx->emit_step( [
				'label'  => sprintf( 'Triplet queue raw_llm_output · %d post-decision rows giữ payload', $stale_raw ),
				'status' => 'warn',
				'detail' => 'Có thể NULL hoá để giảm size — chạy "Clean triplet queue" trên chunked runner.',
			] );
		} else {
			$ctx->emit_step( [
				'label'  => 'Triplet queue payload đã NULL hoá xong (audit trail giữ ở subject/predicate/object)',
				'status' => 'pass',
			] );
		}

		// ─── Step 6: parity sample (sha256 DB body vs file body) ──────
		$parity = $this->parity_sample();
		if ( $parity['sampled'] === 0 ) {
			$ctx->emit_step( [
				'label'  => 'Parity sample · không có row v2 nào để sample (mới setup)',
				'status' => 'pass',
			] );
		} elseif ( $parity['mismatch'] > 0 || $parity['missing'] > 0 ) {
			$has_fail = true;
			$ctx->emit_step( [
				'label'  => sprintf( 'Parity sample · %d/%d mismatch · %d missing file', $parity['mismatch'], $parity['sampled'], $parity['missing'] ),
				'status' => 'fail',
				'detail' => 'File store không khớp DB — KHÔNG flip read file-first cho tới khi điều tra.',
			] );
		} else {
			$ctx->emit_step( [
				'label'  => sprintf( 'Parity sample · %d/%d OK', $parity['matched'], $parity['sampled'] ),
				'status' => 'pass',
			] );
		}

		// ─── Step 7: cron heartbeat ───────────────────────────────────
		$next = wp_next_scheduled( BizCity_KG_Filestore_Diagnostic::HOUSEKEEPING_HOOK );
		$last = get_option( BizCity_KG_Filestore_Diagnostic::HOUSEKEEPING_OPT_LAST, [] );
		$last_t0 = (int) ( is_array( $last ) ? ( $last['t0'] ?? 0 ) : 0 );
		$detail_bits = [];
		if ( $last_t0 ) {
			$ago_h = round( ( time() - $last_t0 ) / HOUR_IN_SECONDS, 1 );
			$detail_bits[] = 'last=' . gmdate( 'Y-m-d H:i', $last_t0 ) . 'Z (' . $ago_h . 'h ago)';
			$detail_bits[] = 'elapsed=' . (int) ( $last['elapsed_ms'] ?? 0 ) . 'ms';
			$detail_bits[] = 'phases=' . count( $last['phases'] ?? [] );
		} else {
			$detail_bits[] = 'last=<chưa chạy>';
		}
		$detail_bits[] = 'next=' . ( $next ? gmdate( 'Y-m-d H:i', (int) $next ) . 'Z' : '<unscheduled>' );
		if ( ! $next ) {
			$has_warn = true;
			$ctx->emit_step( [
				'label'  => 'Cron heartbeat · housekeeping chưa schedule',
				'status' => 'warn',
				'detail' => implode( ' · ', $detail_bits ),
			] );
		} elseif ( $last_t0 && ( time() - $last_t0 ) > 4 * DAY_IN_SECONDS ) {
			$has_warn = true;
			$ctx->emit_step( [
				'label'  => 'Cron heartbeat · housekeeping > 4 ngày chưa chạy',
				'status' => 'warn',
				'detail' => implode( ' · ', $detail_bits ),
			] );
		} else {
			$ctx->emit_step( [
				'label'  => 'Cron heartbeat · 3-day housekeeping OK',
				'status' => 'pass',
				'detail' => implode( ' · ', $detail_bits ),
			] );
		}

		$status = $has_fail ? 'fail' : ( $has_warn ? 'pass' : 'pass' );
		// Note: wizard only knows pass/fail/precheck-fail; warnings keep PASS to
		// avoid blocking the wizard while still surfacing yellow sub-steps.
		return [
			'status'    => $has_fail ? 'fail' : 'pass',
			'summary'   => sprintf(
				'KG learning · 16 tables · %s · parity %d/%d',
				implode( ' · ', $summary_bits ),
				$parity['matched'], $parity['sampled']
			),
			'fix_hint'  => $has_fail
				? 'Repair Hub auto-fix cho missing tables · điều tra parity log trước khi flip read file-first.'
				: ( $has_warn ? 'Backlog drainable: Tools → BizCity KG Filestore → 🏥 Run housekeeping (all steps).' : '' ),
			'artifacts' => [
				[
					'kind'  => 'link',
					'id'    => 'kg-filestore-page',
					'label' => 'Open KG Filestore page',
					'url'   => admin_url( 'tools.php?page=' . BizCity_KG_Filestore_Diagnostic::MENU_SLUG ),
				],
			],
		];
	}

	public function cleanup(): void {
		// Read-only probe — nothing to clean.
	}

	/**
	 * Lightweight parity sample: read up to 10 random v2 passages, compare
	 * sha256 of DB `content` (if non-empty) vs file body. Skip rows whose
	 * inline content has already been NULL-ed (clean phase done) — those are
	 * file-only by design.
	 */
	private function parity_sample(): array {
		global $wpdb;
		$db   = BizCity_KG_Database::instance();
		$tbl  = $db->tbl_passages();
		$rows = $wpdb->get_results(
			"SELECT id, uuid, scope_type, scope_id, content
			   FROM {$tbl}
			  WHERE storage_ver = 2 AND content IS NOT NULL AND content <> ''
			  ORDER BY RAND() LIMIT 10",
			ARRAY_A
		);
		$out = [ 'sampled' => 0, 'matched' => 0, 'mismatch' => 0, 'missing' => 0 ];
		if ( ! $rows ) { return $out; }
		if ( ! class_exists( 'BizCity_KG_Notebook_Folder' ) || ! class_exists( 'BizCity_KG_Passage_File_Store' ) ) {
			return $out;
		}
		$folder = BizCity_KG_Notebook_Folder::instance();
		$pstore = BizCity_KG_Passage_File_Store::instance();
		foreach ( $rows as $r ) {
			$out['sampled']++;
			try {
				$dir  = method_exists( $folder, 'resolve_passage_dir' )
					? $folder->resolve_passage_dir( (string) $r['scope_type'], (string) $r['scope_id'] )
					: '';
				$body = method_exists( $pstore, 'read_body' )
					? $pstore->read_body( $dir, (string) $r['uuid'] )
					: null;
				if ( $body === null || $body === false ) { $out['missing']++; continue; }
				$h_db = hash( 'sha256', (string) $r['content'] );
				$h_fs = hash( 'sha256', (string) $body );
				if ( $h_db === $h_fs ) { $out['matched']++; }
				else                   { $out['mismatch']++; }
			} catch ( \Throwable $e ) {
				$out['missing']++;
			}
		}
		return $out;
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	if ( ! is_array( $list ) ) { $list = []; }
	$list[] = 'BizCity_Probe_KG_Filestore_Learning';
	return $list;
} );
