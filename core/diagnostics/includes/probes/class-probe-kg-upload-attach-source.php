<?php
/**
 * BizCity Diagnostics — kg.upload.attach_source probe (PHASE-0.45-KG-FILE-GRAPH).
 *
 * Plants a REAL, disposable `bizcity_webchat_sources` row (tagged
 * `__healthtest_`) — the exact table the real upload/webchat learning
 * pipeline writes to — and drives it through the PRODUCTION promotion path
 * `BizCity_KG_Source_Service::attach_source()` →
 * `promote_chunks_for_source()`/`promote_from_content_text()`, which is a
 * DIFFERENT code path from `add_passage()` already covered by
 * `kg.filestore.standalone`. Confirms:
 *
 *   1. SOURCE BODY filestore — `insert_source()` scrubs `content_text` to ''
 *      in SQL right after a verified `BizCity_KG_Source_Body_File_Store`
 *      write (PHASE-0.46-FILE-BODY), i.e. no source-level SQL leak.
 *   2. PROMOTION — `attach_source()` chunks the source content and creates
 *      real `kg_passages` rows (via the content_text fallback chunker).
 *   3. PASSAGE filestore — each promoted passage is storage_ver=2 with an
 *      empty `content` column immediately (zero SQL leak on this 2nd
 *      code path, not just add_passage()).
 *   4. HYDRATE — `Content_Router::hydrate_passages()` recovers the original
 *      text.
 *   5. VECTOR REGISTRATION — the notebook `.bin` sidecar receives the
 *      chunk's embedding (register_chunk() write path), isolating embed()
 *      vs. register_chunk() on failure exactly like kg.filestore.standalone.
 *
 * All test rows (source + chunks + passages + notebook) are deleted in
 * cleanup(), which always runs (pass or fail).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-07-25 (PHASE-0.45-KG-FILE-GRAPH)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

// [2026-07-25 Johnny Chu] PHASE-0.45-KG-FILE-GRAPH — double-load guard.
if ( class_exists( 'BizCity_Probe_KG_Upload_Attach_Source', false ) ) {
	return;
}

final class BizCity_Probe_KG_Upload_Attach_Source implements BizCity_Diagnostics_Probe {

	/** @var int */
	private $nb_id = 0;
	/** @var string */
	private $nb_uuid = '';
	/** @var int */
	private $source_id = 0;

	public function id(): string { return 'kg.upload.attach_source'; }
	public function label(): string { return 'KG Upload → Filestore (webchat_sources → attach_source() → promote → zero-SQL-leak)'; }
	public function description(): string {
		return 'Tạo bizcity_webchat_sources thật (tagged __healthtest_) giống pipeline upload thật, chạy qua attach_source()/promote_chunks_for_source() (khác add_passage()), xác nhận source body + passage content không rò rỉ vào SQL (storage_ver=2), hydrate đúng, và vector được ghi vào .bin notebook.';
	}
	public function severity(): string { return 'critical'; }
	public function order(): int { return 65; } // right after kg.filestore.standalone (64)
	public function icon(): string { return 'upload-cloud'; }
	public function estimate_ms(): int { return 6000; } // embed() calls per chunk promoted

	public function precondition() {
		// [2026-08-21 Johnny Chu] R-DDV-MOCK-GATEWAY — source promotion registers real vectors and requires a live embedding provider.
		if ( defined( 'BIZCITY_DIAGNOSTICS_MOCK' ) && BIZCITY_DIAGNOSTICS_MOCK ) {
			return 'Mock mode: bỏ qua KG upload/vector promotion live probe.';
		}
		$need = [
			'BizCity_KG_Notebook_Service', 'BizCity_KG_Source_Service', 'BizCity_KG_Database',
			'BizCity_KG_Content_Router', 'BizCity_KG_Notebook_Folder', 'BizCity_TwinChat_Sources_Database',
		];
		foreach ( $need as $cls ) {
			if ( ! class_exists( $cls ) ) {
				return new WP_Error( 'kg_class_missing', $cls . ' chưa load — knowledge/kg-hub hoặc modules/twinchat bootstrap không hoàn tất.' );
			}
		}
		if ( ! class_exists( 'BizCity_Knowledge_Embedding' ) ) {
			return new WP_Error( 'embedder_missing', 'BizCity_Knowledge_Embedding chưa load — không thể tạo embedding thật cho probe.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		global $wpdb;
		$steps    = [];
		$failures = [];
		$uniq     = substr( md5( uniqid( 'kgup', true ) ), 0, 8 );
		$token    = 'kguploadprobe' . $uniq;

		$db = BizCity_KG_Database::instance();

		// ── Step 1: disposable notebook ─────────────────────────────────────
		$nb = BizCity_KG_Notebook_Service::instance()->create( [
			'name'        => '__healthtest_kg_upload_' . $uniq,
			'description' => 'DDV probe — safe to delete',
		], get_current_user_id() ?: 1 );
		$this->nb_id   = (int) ( $nb['id'] ?? 0 );
		$this->nb_uuid = (string) ( $nb['uuid'] ?? '' );
		$this->step( $ctx, $steps, 'Runtime - create notebook', $this->nb_id > 0 && $this->nb_uuid !== '', 'notebook_id=' . $this->nb_id . ' uuid=' . $this->nb_uuid );
		if ( $this->nb_id <= 0 ) {
			return [ 'status' => 'fail', 'error' => 'Notebook create failed', 'steps' => $steps ];
		}

		// ── Step 2: real webchat_sources row (production upload shape) ─────
		$para_a = "Đoạn upload probe {$token} phần A: mô tả một tài liệu thật được tải lên hệ thống để kiểm tra pipeline filestore.";
		$para_b = "Đoạn upload probe {$token} phần B: nội dung khác để bảo đảm content_text được chia thành nhiều chunk khi promote.";
		$content_text = str_repeat( $para_a . ' ', 12 ) . "\n\n" . str_repeat( $para_b . ' ', 12 );

		$this->source_id = (int) BizCity_TwinChat_Sources_Database::instance()->insert_source( [
			'notebook_id'  => $this->nb_id,
			'user_id'      => get_current_user_id() ?: 1,
			'title'        => '__healthtest_kg_upload_source_' . $uniq,
			'source_type'  => 'text',
			'content_text' => $content_text,
		] );
		$this->step( $ctx, $steps, 'Runtime - insert_source() (real webchat_sources row)', $this->source_id > 0, 'source_id=' . $this->source_id );
		if ( $this->source_id <= 0 ) {
			$failures[] = 'insert_source_failed';
		}

		if ( $this->source_id > 0 ) {
			// [2026-07-25 Johnny Chu] PHASE-0.45-KG-FILE-GRAPH — no-leak must
			// read RAW SQL row. get_source() intentionally hydrates content_text
			// back from filestore for detail reads.
			$src_tbl = BizCity_TwinChat_Sources_Database::instance()->table_sources();
			$src_raw = $wpdb->get_row(
				$wpdb->prepare( "SELECT content_text, metadata FROM {$src_tbl} WHERE id=%d LIMIT 1", $this->source_id ),
				ARRAY_A
			);
			$src_no_leak = is_array( $src_raw )
				&& (string) ( $src_raw['content_text'] ?? '' ) === ''
				&& strpos( (string) ( $src_raw['metadata'] ?? '' ), '"body_storage":"filestore"' ) !== false;
			$this->step( $ctx, $steps, 'Runtime - source body zero SQL leak (content_text scrubbed after verified file write)', $src_no_leak, 'content_text_len=' . strlen( (string) ( $src_raw['content_text'] ?? '' ) ) . '; metadata=' . substr( (string) ( $src_raw['metadata'] ?? '' ), 0, 120 ) );
			if ( ! $src_no_leak ) { $failures[] = 'source_body_sql_leak'; }

			// get_source() transparently falls back to filestore for detail reads — confirm parity.
			$src_row = BizCity_TwinChat_Sources_Database::instance()->get_source( $this->source_id );
			$hydrated_ok = strpos( (string) ( $src_row['content_text'] ?? '' ), $token ) !== false;
			// [note] get_source() only hydrates content_text back when called fresh; the row above
			// was fetched via the same call, so content_text here IS the hydrated body already.
			$this->step( $ctx, $steps, 'Runtime - source body hydrate parity (get_source() file fallback)', $hydrated_ok, $hydrated_ok ? 'token found in hydrated body' : 'token missing after get_source()' );
			if ( ! $hydrated_ok ) { $failures[] = 'source_body_hydrate_mismatch'; }
		}

		// ── Step 3: promote via attach_source() (production promotion path) ─
		$promoted_count = 0;
		if ( $this->source_id > 0 ) {
			$promote = BizCity_KG_Source_Service::instance()->attach_source( $this->nb_id, $this->source_id );
			$promoted_count = (int) ( $promote['passages'] ?? 0 );
			$this->step( $ctx, $steps, 'Runtime - attach_source() promotes chunks into kg_passages', $promoted_count > 0, 'passages=' . $promoted_count . '; table=' . (string) ( $promote['table'] ?? '' ) );
			if ( $promoted_count <= 0 ) { $failures[] = 'attach_source_promote_failed'; }
		}

		// ── Step 4: promoted passage zero SQL leak + hydrate parity ────────
		$pid = 0;
		if ( $promoted_count > 0 ) {
			$pid = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$db->tbl_passages()} WHERE source_id=%d ORDER BY id ASC LIMIT 1",
				$this->source_id
			) );
			$raw = $pid > 0 ? ( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$db->tbl_passages()} WHERE id=%d", $pid ), ARRAY_A ) ?: [] ) : [];
			$no_leak = $pid > 0
				&& (int) ( $raw['storage_ver'] ?? 0 ) === 2
				&& (string) ( $raw['content'] ?? '' ) === '';
			$this->step( $ctx, $steps, 'Runtime - promoted passage zero SQL leak (storage_ver=2, content empty)', $no_leak, 'passage_id=' . $pid . '; storage_ver=' . (int) ( $raw['storage_ver'] ?? 0 ) . '; content_len=' . strlen( (string) ( $raw['content'] ?? '' ) ) );
			if ( ! $no_leak ) { $failures[] = 'promoted_passage_sql_leak'; }

			if ( $pid > 0 && class_exists( 'BizCity_KG_Content_Router' ) ) {
				$rows = [ $raw ];
				BizCity_KG_Content_Router::instance()->hydrate_passages( $rows );
				$hydrated_ok = strpos( (string) ( $rows[0]['content'] ?? '' ), $token ) !== false;
				$this->step( $ctx, $steps, 'Runtime - promoted passage hydrate parity (Content_Router)', $hydrated_ok, $hydrated_ok ? 'token found in hydrated body' : 'token missing after hydrate_passages()' );
				if ( ! $hydrated_ok ) { $failures[] = 'promoted_passage_hydrate_mismatch'; }
			}
		}

		// ── Step 5: vector registration in notebook .bin ────────────────────
		if ( $pid > 0 && function_exists( 'bizcity_kg_vector_bin_path' ) && class_exists( 'BizCity_KG_Vector_File_Store' ) ) {
			$bin_path   = bizcity_kg_vector_bin_path( 'notebooks', $this->nb_uuid );
			$bin_exists = $bin_path && file_exists( $bin_path );
			$bin_count  = 0;
			if ( $bin_exists ) {
				$hdr = BizCity_KG_Vector_File_Store::instance()->header_validate( $bin_path );
				$bin_count = is_wp_error( $hdr ) ? -1 : (int) ( $hdr['count'] ?? 0 );
			}
			$vector_registered = $bin_exists && $bin_count > 0;
			$this->step( $ctx, $steps, 'Runtime - promoted chunk vector registered in notebook .bin (register_chunk write path)', $vector_registered, 'bin_exists=' . ( $bin_exists ? 'yes' : 'no' ) . '; bin_count=' . $bin_count );
			if ( ! $vector_registered ) {
				$failures[] = 'promoted_chunk_vector_not_registered';
				// Isolate: was it embed() or register_chunk() that silently failed?
				if ( class_exists( 'BizCity_KG_Vector_Index' ) ) {
					$retry_vec = BizCity_KG_Vector_Index::instance()->embed( $content_text );
					if ( is_wp_error( $retry_vec ) ) {
						$this->step( $ctx, $steps, 'Runtime - isolate: retry embed(content_text)', false, 'embed() error: ' . $retry_vec->get_error_code() . ' — ' . $retry_vec->get_error_message() );
					} elseif ( class_exists( 'BizCity_KG_Embedding_Writer' ) ) {
						$retry_res = BizCity_KG_Embedding_Writer::instance()->register_chunk( $this->nb_id, $pid, $retry_vec, null, $this->source_id );
						$this->step( $ctx, $steps, 'Runtime - isolate: retry register_chunk()', $retry_res === true, $retry_res === true ? 'ok (transient failure on original attempt)' : ( is_wp_error( $retry_res ) ? ( $retry_res->get_error_code() . ' — ' . $retry_res->get_error_message() ) : 'unexpected return' ) );
					}
				}
			}
		}

		$status = empty( $failures ) ? 'pass' : 'fail';
		return [
			'status'   => $status,
			'summary'  => $status === 'pass'
				? 'KG upload → attach_source() promotion path: source body + promoted passage both zero-SQL-leak, hydrate parity ok, vector registered.'
				: 'KG upload → attach_source() promotion path FAILED: ' . implode( ', ', array_unique( $failures ) ) . '.',
			'error'    => empty( $failures ) ? '' : implode( '; ', array_unique( $failures ) ),
			'fix_hint' => empty( $failures ) ? '' : 'Xem class-kg-source-service.php::attach_source()/promote_chunks_for_source()/promote_from_content_text() và class-twinchat-sources-database.php::insert_source().',
			'steps'    => $steps,
		];
	}

	public function cleanup(): void {
		// [2026-07-25 Johnny Chu] PHASE-0.45-KG-FILE-GRAPH — wipe source+chunks (both legacy
		// and KG tables), then the notebook cascade + filestore folder purge (covers both
		// the passage-bodies AND the source-bodies sub-folders under the same notebook uuid).
		if ( $this->source_id > 0 && class_exists( 'BizCity_TwinChat_Sources_Database' ) ) {
			BizCity_TwinChat_Sources_Database::instance()->delete_source( $this->source_id );
		}
		if ( $this->nb_id > 0 && class_exists( 'BizCity_KG_Notebook_Service' ) ) {
			BizCity_KG_Notebook_Service::instance()->delete( $this->nb_id );
		}
		if ( $this->nb_uuid !== '' && class_exists( 'BizCity_KG_Notebook_Folder' ) ) {
			BizCity_KG_Notebook_Folder::instance()->purge( 'notebooks', $this->nb_uuid );
		}
	}

	/**
	 * @param object           $ctx
	 * @param array<int,array> $steps
	 */
	private function step( $ctx, array &$steps, string $label, bool $ok, string $detail ): void {
		$step = [ 'label' => $label, 'status' => $ok ? 'pass' : 'fail', 'detail' => $detail ];
		$steps[] = $step;
		$ctx->emit_step( $step );
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $probes ) {
	$probes[] = 'BizCity_Probe_KG_Upload_Attach_Source';
	return $probes;
} );
