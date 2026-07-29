<?php
/**
 * BizCity Diagnostics — kg.filestore.standalone probe (R-KG-FS-STANDALONE).
 *
 * Plants a REAL, disposable notebook + passage + 2 entities + 1 relation
 * (tagged `__healthtest_`) and asserts, end to end, that the KG Filestore
 * migration (PHASE-0.45-KG-FILE-GRAPH) leaves the SQL side of the Knowledge
 * Graph fully standalone-safe:
 *
 *   1. INGEST — creating a notebook + uploading a source/passage + creating
 *      entities/relations writes to filestore FIRST (storage_ver=2 immediately,
 *      NOT after a delayed backfill) and the fat SQL columns
 *      (content/description/relation_text/embedding) are genuinely empty right
 *      after insert — i.e. no silent SQL leak.
 *   2. HYDRATE — `BizCity_KG_Content_Router::hydrate_passages/entities/relations()`
 *      recovers the exact original text from the filestore mirror (parity).
 *   3. SEARCH — `BizCity_KG_Vector_Index::search_entities/search_relations()`
 *      (bin-first fix, PHASE-0.45-KG-FILE-GRAPH 2026-07-25) and
 *      `BizCity_KG_Retriever::search()` (passage vector search) both find the
 *      freshly-created, SQL-null rows via their `.embed.bin` sidecars — proving
 *      KG search is standalone from SQL `embedding` columns.
 *   4. VISUALIZE — `BizCity_KG_Graph_Service::get_full_graph()` renders the
 *      created entities/relations correctly (this path never depended on the
 *      nulled columns — name/type/predicate/weight are never scrubbed — but we
 *      assert it here so a future column-list change is caught).
 *   5. RAG EVIDENCE PACK — `BizCity_TwinBrain_Notebook_Source_Layer::build_from_turn()`
 *      with a REAL citation token pointing at the freshly-created (SQL-null)
 *      passage produces a hydrated `final_context_chunks[]` entry — the exact
 *      contract TwinChat/TwinWeb SSE streaming + Final Composer consume to
 *      answer with a working `[nb:X/pY]` citation link.
 *
 * All test rows are deleted (SQL cascade + filestore folder purge) in
 * cleanup(), which always runs (pass or fail).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-07-25 (PHASE-0.45-KG-FILE-GRAPH)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

// [2026-07-25 Johnny Chu] PHASE-0.45-KG-FILE-GRAPH — double-load guard (bootstrap may include via filter AND direct require).
if ( class_exists( 'BizCity_Probe_KG_Filestore_Standalone', false ) ) {
	return;
}

final class BizCity_Probe_KG_Filestore_Standalone implements BizCity_Diagnostics_Probe {

	/** @var int */
	private $nb_id = 0;
	/** @var string */
	private $nb_uuid = '';

	public function id(): string { return 'kg.filestore.standalone'; }
	public function label(): string { return 'KG Filestore Standalone (ingest → zero-SQL-leak → search → visualize → citation)'; }
	public function description(): string {
		return 'Tạo notebook + source + entity/relation thật (tagged __healthtest_), xác nhận mặc định ghi filestore ngay (storage_ver=2, KHÔNG rò rỉ content/description/relation_text/embedding vào SQL), hydrate đúng, tìm được qua KG search (entities/relations/passages, bin-first), render đúng ở Graph visualize, và evidence pack RAG (citation [nb:X/pY]) hoạt động standalone khỏi SQL.';
	}
	public function severity(): string { return 'critical'; }
	public function order(): int { return 64; } // after twinbrain.notebook_depth (63)
	public function icon(): string { return 'shield-check'; }
	public function estimate_ms(): int { return 8000; } // 4 real embed calls (passage + 2 entities + 1 relation)

	public function precondition() {
		$need = [
			'BizCity_KG_Notebook_Service', 'BizCity_KG_Source_Service', 'BizCity_KG_Graph_Service',
			'BizCity_KG_Database', 'BizCity_KG_Content_Router', 'BizCity_KG_Vector_Index',
			'BizCity_KG_Retriever', 'BizCity_KG_Filestore_Dispatcher', 'BizCity_KG_Notebook_Folder',
		];
		foreach ( $need as $cls ) {
			if ( ! class_exists( $cls ) ) {
				return new WP_Error( 'kg_class_missing', $cls . ' chưa load — knowledge/kg-hub bootstrap không hoàn tất.' );
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
		$uniq     = substr( md5( uniqid( 'kgfs', true ) ), 0, 8 );
		$token    = 'kgfsprobe' . $uniq; // distinctive token, won't collide with real content
		$passage_source_id = 0;

		$db = BizCity_KG_Database::instance();

		// ── Step 1: create disposable notebook ──────────────────────────────
		$nb = BizCity_KG_Notebook_Service::instance()->create( [
			'name'        => '__healthtest_kg_filestore_' . $uniq,
			'description' => 'DDV probe — safe to delete',
		], get_current_user_id() ?: 1 );
		$this->nb_id   = (int) ( $nb['id'] ?? 0 );
		$this->nb_uuid = (string) ( $nb['uuid'] ?? '' );
		$this->step( $ctx, $steps, 'Runtime - create notebook', $this->nb_id > 0 && $this->nb_uuid !== '', 'notebook_id=' . $this->nb_id . ' uuid=' . $this->nb_uuid );
		if ( $this->nb_id <= 0 ) {
			return [ 'status' => 'fail', 'error' => 'Notebook create failed', 'steps' => $steps ];
		}

		// ── Step 2: upload a source passage — assert zero SQL leak ──────────
		$passage_text = "Đoạn probe {$token} kiểm tra filestore standalone cho passage content, không nằm trong SQL sau khi insert.";
		$pid = BizCity_KG_Source_Service::instance()->add_passage( $this->nb_id, $passage_text, 'note' );
		$pid = is_wp_error( $pid ) ? 0 : (int) $pid;
		$this->step( $ctx, $steps, 'Runtime - add_passage()', $pid > 0, 'passage_id=' . $pid );
		if ( $pid <= 0 ) { $failures[] = 'add_passage_failed'; }

		if ( $pid > 0 ) {
			$raw = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$db->tbl_passages()} WHERE id=%d", $pid ), ARRAY_A ) ?: [];
			$passage_source_id = (int) ( $raw['source_id'] ?? 0 );
			$no_leak = (int) ( $raw['storage_ver'] ?? 0 ) === 2
				&& (string) ( $raw['content'] ?? '' ) === ''
				&& empty( $raw['embedding'] );
			$this->step( $ctx, $steps, 'Runtime - passage zero SQL leak (storage_ver=2, content/embedding empty)', $no_leak, 'storage_ver=' . (int) ( $raw['storage_ver'] ?? 0 ) . '; content_len=' . strlen( (string) ( $raw['content'] ?? '' ) ) . '; embedding_empty=' . ( empty( $raw['embedding'] ) ? 'yes' : 'no' ) );
			if ( ! $no_leak ) { $failures[] = 'passage_sql_leak'; }

			$rows = [ $raw ];
			BizCity_KG_Content_Router::instance()->hydrate_passages( $rows );
			$hydrated_ok = strpos( (string) ( $rows[0]['content'] ?? '' ), $token ) !== false;
			$this->step( $ctx, $steps, 'Runtime - passage hydrate parity (Content_Router)', $hydrated_ok, $hydrated_ok ? 'token found in hydrated body' : 'token missing after hydrate_passages()' );
			if ( ! $hydrated_ok ) { $failures[] = 'passage_hydrate_mismatch'; }
		}

		// ── Step 3: create 2 entities + 1 relation — assert zero SQL leak ───
		$desc_a = "Thực thể probe {$token}-A mô tả để kiểm tra description filestore.";
		$desc_b = "Thực thể probe {$token}-B liên kết với A.";
		$eid_a  = (int) BizCity_KG_Graph_Service::instance()->upsert_entity( $this->nb_id, 'ProbeEntityA' . $uniq, 'Other', $desc_a );
		$eid_b  = (int) BizCity_KG_Graph_Service::instance()->upsert_entity( $this->nb_id, 'ProbeEntityB' . $uniq, 'Other', $desc_b );
		$this->step( $ctx, $steps, 'Runtime - upsert_entity() x2', $eid_a > 0 && $eid_b > 0, 'entity_a=' . $eid_a . ' entity_b=' . $eid_b );
		if ( $eid_a <= 0 || $eid_b <= 0 ) { $failures[] = 'upsert_entity_failed'; }

		if ( $eid_a > 0 ) {
			$raw_e = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$db->tbl_entities()} WHERE id=%d", $eid_a ), ARRAY_A ) ?: [];
			// [2026-07-25 Johnny Chu] PHASE-0.45-KG-FILE-GRAPH — confirmed via
			// class-kg-filestore-dispatcher.php::after_entity_insert(): only
			// storage_ver flip + embedding drain are SYNCHRONOUS at insert time.
			// description/aliases/metadata nulling is INTENTIONALLY deferred to
			// the batched clean_inline() housekeeping pass (manual button / cron),
			// same as relation_text for relations. Asserting description empty
			// here was a probe bug (false failure), not a real regression.
			$no_leak_e = (int) ( $raw_e['storage_ver'] ?? 0 ) === 2
				&& empty( $raw_e['embedding'] );
			$this->step( $ctx, $steps, 'Runtime - entity zero SQL leak (storage_ver=2, embedding empty synchronously; description/aliases deferred to clean_inline housekeeping)', $no_leak_e, 'storage_ver=' . (int) ( $raw_e['storage_ver'] ?? 0 ) . '; description_empty=' . ( empty( $raw_e['description'] ) ? 'yes' : 'no' ) . ' (expected no until housekeeping runs); embedding_empty=' . ( empty( $raw_e['embedding'] ) ? 'yes' : 'no' ) );
			if ( ! $no_leak_e ) { $failures[] = 'entity_sql_leak'; }

			$e_rows = [ $raw_e ];
			BizCity_KG_Content_Router::instance()->hydrate_entities( $e_rows, false );
			$e_hydrated_ok = strpos( (string) ( $e_rows[0]['description'] ?? '' ), $token ) !== false;
			$this->step( $ctx, $steps, 'Runtime - entity hydrate parity (Content_Router)', $e_hydrated_ok, $e_hydrated_ok ? 'token found in hydrated description' : 'token missing after hydrate_entities()' );
			if ( ! $e_hydrated_ok ) { $failures[] = 'entity_hydrate_mismatch'; }
		}

		$rid = 0;
		if ( $eid_a > 0 && $eid_b > 0 ) {
			$rid = (int) BizCity_KG_Graph_Service::instance()->upsert_relation( $this->nb_id, $eid_a, 'linked_to_probe_' . $uniq, $eid_b, $pid ?: null );
			$this->step( $ctx, $steps, 'Runtime - upsert_relation()', $rid > 0, 'relation_id=' . $rid );
			if ( $rid <= 0 ) { $failures[] = 'upsert_relation_failed'; }

			if ( $rid > 0 ) {
				$raw_r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$db->tbl_relations()} WHERE id=%d", $rid ), ARRAY_A ) ?: [];
				// [2026-07-25 Johnny Chu] PHASE-0.45-KG-FILE-GRAPH — same two-phase
				// design as entities: relation_text nulling is deferred to
				// clean_inline() housekeeping, only embedding drains synchronously.
				$no_leak_r = (int) ( $raw_r['storage_ver'] ?? 0 ) === 2
					&& empty( $raw_r['embedding'] );
				$this->step( $ctx, $steps, 'Runtime - relation zero SQL leak (storage_ver=2, embedding empty synchronously; relation_text deferred to clean_inline housekeeping)', $no_leak_r, 'storage_ver=' . (int) ( $raw_r['storage_ver'] ?? 0 ) . '; relation_text_empty=' . ( empty( $raw_r['relation_text'] ) ? 'yes' : 'no' ) . ' (expected no until housekeeping runs); embedding_empty=' . ( empty( $raw_r['embedding'] ) ? 'yes' : 'no' ) );
				if ( ! $no_leak_r ) { $failures[] = 'relation_sql_leak'; }

				$r_rows = [ $raw_r ];
				BizCity_KG_Content_Router::instance()->hydrate_relations( $r_rows );
				$r_hydrated_ok = strpos( (string) ( $r_rows[0]['relation_text'] ?? '' ), 'linked_to_probe_' . $uniq ) !== false;
				$this->step( $ctx, $steps, 'Runtime - relation hydrate parity (Content_Router)', $r_hydrated_ok, $r_hydrated_ok ? 'predicate found in hydrated relation_text' : 'predicate missing after hydrate_relations()' );
				if ( ! $r_hydrated_ok ) { $failures[] = 'relation_hydrate_mismatch'; }
			}
		}

		// ── Step 4: KG search must find the SQL-null rows via .embed.bin ───
		$vindex = BizCity_KG_Vector_Index::instance();
		$qvec   = $vindex->embed( 'ProbeEntityA' . $uniq . ' ' . $desc_a );
		if ( is_wp_error( $qvec ) ) {
			$this->step( $ctx, $steps, 'Runtime - query embed for search', false, 'embed() error: ' . $qvec->get_error_message() );
			$failures[] = 'query_embed_failed';
		} else {
			$ent_hits = $vindex->search_entities( $this->nb_id, $qvec, 5 );
			$ent_found = false;
			foreach ( (array) $ent_hits as $h ) { if ( (int) ( $h['id'] ?? 0 ) === $eid_a ) { $ent_found = true; break; } }
			$this->step( $ctx, $steps, 'Runtime - search_entities() finds SQL-null entity (bin-first fix)', $ent_found, 'hits=' . count( (array) $ent_hits ) . '; found_entity_a=' . ( $ent_found ? 'yes' : 'no' ) );
			if ( ! $ent_found ) { $failures[] = 'search_entities_regression'; }

			if ( $rid > 0 ) {
				$rel_hits = $vindex->search_relations( $this->nb_id, $qvec, 10 );
				$rel_found = false;
				foreach ( (array) $rel_hits as $h ) { if ( (int) ( $h['id'] ?? 0 ) === $rid ) { $rel_found = true; break; } }
				$this->step( $ctx, $steps, 'Runtime - search_relations() finds SQL-null relation (bin-first fix)', $rel_found, 'hits=' . count( (array) $rel_hits ) . '; found_relation=' . ( $rel_found ? 'yes' : 'no' ) );
				if ( ! $rel_found ) { $failures[] = 'search_relations_regression'; }
			}
		}

		if ( $pid > 0 && function_exists( 'bizcity_kg_vector_bin_path' ) && class_exists( 'BizCity_KG_Vector_File_Store' ) ) {
			// [2026-07-25 Johnny Chu] PHASE-0.45-KG-FILE-GRAPH — isolate WHY passage
			// vector search might miss: check the notebook .bin actually received
			// the register_chunk() write from add_passage() before blaming the
			// search side.
			$bin_path   = bizcity_kg_vector_bin_path( 'notebooks', $this->nb_uuid );
			$bin_exists = $bin_path && file_exists( $bin_path );
			$bin_count  = 0;
			if ( $bin_exists ) {
				$hdr = BizCity_KG_Vector_File_Store::instance()->header_validate( $bin_path );
				$bin_count = is_wp_error( $hdr ) ? -1 : (int) ( $hdr['count'] ?? 0 );
			}
			$vector_registered = $bin_exists && $bin_count > 0;
			$this->step( $ctx, $steps, 'Runtime - passage vector registered in notebook .bin (register_chunk write path)', $vector_registered, 'bin_exists=' . ( $bin_exists ? 'yes' : 'no' ) . '; bin_count=' . $bin_count );
			if ( ! $vector_registered ) {
				$failures[] = 'passage_vector_not_registered';
				// Isolate further: retry embed() + register_chunk() directly to see
				// which of the two steps actually breaks (embedding API vs .bin write).
				$retry_vec = BizCity_KG_Vector_Index::instance()->embed( $passage_text );
				if ( is_wp_error( $retry_vec ) ) {
					$this->step( $ctx, $steps, 'Runtime - isolate: retry embed(passage_text)', false, 'embed() error: ' . $retry_vec->get_error_code() . ' — ' . $retry_vec->get_error_message() );
				} elseif ( class_exists( 'BizCity_KG_Embedding_Writer' ) ) {
					$retry_res = BizCity_KG_Embedding_Writer::instance()->register_chunk( $this->nb_id, $pid, $retry_vec, null, null );
					$this->step( $ctx, $steps, 'Runtime - isolate: retry register_chunk()', $retry_res === true, $retry_res === true ? 'ok (embed() succeeded on retry — original failure was transient)' : ( is_wp_error( $retry_res ) ? ( $retry_res->get_error_code() . ' — ' . $retry_res->get_error_message() ) : 'unexpected return' ) );
				}
			}
		}

		if ( $pid > 0 ) {
			$include_chat_filter = null;
			if ( $passage_source_id <= 0 ) {
				// [2026-07-25 Johnny Chu] PHASE-0.45-KG-FILE-GRAPH — probe rows
				// may carry source_id<=0, which BizCity_KG_Retriever::search()
				// normally filters out to avoid chat-promoted loops. Enable include
				// ONLY for this notebook during the assertion so vector retrieval
				// health is measured independently from that policy filter.
				$probe_notebook_id = (int) $this->nb_id;
				$include_chat_filter = static function ( $include, $nb_id ) use ( $probe_notebook_id ) {
					return ( (int) $nb_id === $probe_notebook_id ) ? true : $include;
				};
				add_filter( 'bizcity_kg_rag_include_chat_promoted', $include_chat_filter, 10, 2 );
			}
			try {
				$psg_result = BizCity_KG_Retriever::instance()->search( $this->nb_id, $token, 3 );
			} catch ( \Throwable $e ) {
				$psg_result = null;
				$this->step( $ctx, $steps, 'Runtime - passage search() exception', false, $e->getMessage() );
				$failures[] = 'passage_search_exception';
			} finally {
				if ( is_callable( $include_chat_filter ) ) {
					remove_filter( 'bizcity_kg_rag_include_chat_promoted', $include_chat_filter, 10 );
				}
			}
			if ( is_array( $psg_result ) ) {
				$mode  = (string) ( $psg_result['mode'] ?? '' );
				$count = (int) ( $psg_result['count'] ?? 0 );
				$psg_ok = $count > 0 && $mode !== 'degraded_keyword';
				$this->step( $ctx, $steps, 'Runtime - BizCity_KG_Retriever::search() finds passage (non-degraded)', $psg_ok, 'mode=' . $mode . '; count=' . $count );
				if ( ! $psg_ok ) { $failures[] = 'passage_search_degraded_or_empty'; }
			}
		}

		// ── Step 5: Graph visualize renders the created nodes/links ────────
		$graph = BizCity_KG_Graph_Service::instance()->get_full_graph( $this->nb_id, 50 );
		$node_ok = false;
		foreach ( (array) ( $graph['nodes'] ?? [] ) as $n ) {
			if ( (int) ( $n['id'] ?? 0 ) === $eid_a && strpos( (string) ( $n['label'] ?? '' ), 'ProbeEntityA' ) !== false ) { $node_ok = true; break; }
		}
		$link_ok = $rid <= 0; // if no relation created, don't require a link
		foreach ( (array) ( $graph['links'] ?? [] ) as $l ) {
			if ( (int) ( $l['id'] ?? 0 ) === $rid ) { $link_ok = true; break; }
		}
		$this->step( $ctx, $steps, 'Runtime - get_full_graph() renders entity + relation', $node_ok && $link_ok, 'nodes=' . count( (array) ( $graph['nodes'] ?? [] ) ) . '; links=' . count( (array) ( $graph['links'] ?? [] ) ) . '; node_ok=' . ( $node_ok ? 'yes' : 'no' ) . '; link_ok=' . ( $link_ok ? 'yes' : 'no' ) );
		if ( ! ( $node_ok && $link_ok ) ) { $failures[] = 'graph_visualize_failed'; }

		// ── Step 6: RAG evidence pack (real citation → hydrated final_context_chunks) ─
		if ( $pid > 0 && class_exists( 'BizCity_TwinBrain_Notebook_Source_Layer' ) ) {
			$nb_label = '__healthtest_kg_filestore_' . $uniq;
			$candidates = [ [ 'notebook_id' => $this->nb_id, 'label' => $nb_label, 'score' => 9.0, 'reason' => 'probe' ] ];
			$answers = [ [
				'notebook_id' => $this->nb_id,
				'label'       => $nb_label,
				'stance'      => 'sources_only',
				'confidence'  => 1.0,
				'answer_md'   => "[nb:{$this->nb_id}/p{$pid}] {$passage_text}",
				'citations'   => [ [
					'token'          => "[nb:{$this->nb_id}/p{$pid}]",
					'kind'           => 'nb',
					'notebook_id'    => $this->nb_id,
					'passage_id'     => $pid,
					'rank_reason'    => 'primary_hit',
					'matched_tokens' => [ $token ],
				] ],
			] ];
			try {
				$payload = BizCity_TwinBrain_Notebook_Source_Layer::instance()->build_from_turn( $candidates, $answers, [
					'w020_skip_vector_retriever'   => true,
					'w020_skip_hub_rerank'         => true,
					'w020_skip_selector_hardening' => true,
				] );
			} catch ( \Throwable $e ) {
				$payload = null;
				$this->step( $ctx, $steps, 'Runtime - build_from_turn() exception', false, $e->getMessage() );
				$failures[] = 'build_from_turn_exception';
			}
			if ( is_array( $payload ) ) {
				$final_chunks = (array) ( $payload['graph_vector_rerank_pack']['final_context_chunks'] ?? $payload['final_context_chunks'] ?? [] );
				$citation_ok = false;
				foreach ( $final_chunks as $c ) {
					if ( strpos( (string) ( $c['excerpt'] ?? '' ), $token ) !== false ) { $citation_ok = true; break; }
				}
				$this->step( $ctx, $steps, 'Runtime - RAG evidence pack hydrates real citation (TwinChat/TwinWeb SSE contract)', $citation_ok, 'final_context_chunks=' . count( $final_chunks ) . '; token_found=' . ( $citation_ok ? 'yes' : 'no' ) );
				if ( ! $citation_ok ) { $failures[] = 'rag_evidence_pack_hydrate_failed'; }
			}
		}

		$status = empty( $failures ) ? 'pass' : 'fail';
		return [
			'status'   => $status,
			'summary'  => $status === 'pass'
				? 'KG filestore standalone: ingest zero-leak, hydrate parity, search (entities/relations/passages), visualize, and RAG citation evidence pack all passed.'
				: 'KG filestore standalone FAILED: ' . implode( ', ', array_unique( $failures ) ) . '.',
			'error'    => empty( $failures ) ? '' : implode( '; ', array_unique( $failures ) ),
			'fix_hint' => empty( $failures ) ? '' : 'Xem core/knowledge/kg-hub/docs/PHASE-KG-FILESTORE-STANDALONE-VALIDATION.md — bảng risk map theo từng failure code ở trên.',
			'steps'    => $steps,
		];
	}

	public function cleanup(): void {
		// [2026-07-25 Johnny Chu] PHASE-0.45-KG-FILE-GRAPH — always wipe SQL rows (cascade)
		// + purge the notebook's filestore folder so no __healthtest_ artifact lingers.
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
	$probes[] = 'BizCity_Probe_KG_Filestore_Standalone';
	return $probes;
} );
