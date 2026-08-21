<?php
/**
 * BizCity Diagnostics — kg.graph.rag.ask probe (PHASE-0.45-KG-FILE-GRAPH).
 *
 * Plants a REAL, disposable notebook + passage + 2 entities + 1 relation
 * (tagged `__healthtest_`) and drives the FULL Graph-RAG pipeline via
 * `BizCity_KG_Retriever::ask()` — distinct from `kg.filestore.standalone`,
 * which only exercises the lower-level `search_entities()`/`search_relations()`
 * primitives, never the end-to-end question → answer pipeline (embed
 * question → seed entities/relations → 1-hop subgraph expansion → LLM
 * rerank → passage fetch → LLM answer generation) that TwinChat/TwinWeb
 * "hỏi và trả lời theo KG" actually calls.
 *
 * Confirms:
 *   1. `ask()` returns a non-empty real LLM answer.
 *   2. The answer/passages are actually grounded in the planted fact (not a
 *      hallucination) — the unique probe token is found either in the
 *      answer text or in the retrieved passages.
 *   3. Retrieval used real embedding search, not the keyword-degraded
 *      fallback (`retrieval_mode !== 'degraded_keyword'`).
 *   4. The planted entity is present in the retrieval detail / subgraph —
 *      proving graph expansion (not just flat passage search) contributed.
 *
 * All test rows are deleted in cleanup(), which always runs (pass or fail).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-07-25 (PHASE-0.45-KG-FILE-GRAPH)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

// [2026-07-25 Johnny Chu] PHASE-0.45-KG-FILE-GRAPH — double-load guard.
if ( class_exists( 'BizCity_Probe_KG_Graph_RAG_Ask', false ) ) {
	return;
}

final class BizCity_Probe_KG_Graph_RAG_Ask implements BizCity_Diagnostics_Probe {

	/** @var int */
	private $nb_id = 0;
	/** @var string */
	private $nb_uuid = '';

	public function id(): string { return 'kg.graph.rag.ask'; }
	public function label(): string { return 'KG Graph RAG — ask() end-to-end (embed → seed → expand → rerank → answer)'; }
	public function description(): string {
		return 'Tạo notebook + passage + entity/relation thật (tagged __healthtest_) chứa 1 sự kiện độc nhất, gọi BizCity_KG_Retriever::ask() (full pipeline câu hỏi → câu trả lời), xác nhận câu trả lời/passages thật sự grounded vào sự kiện vừa tạo và retrieval không rơi vào chế độ degraded_keyword.';
	}
	public function severity(): string { return 'critical'; }
	public function order(): int { return 66; }
	public function icon(): string { return 'message-circle-question'; }
	public function estimate_ms(): int { return 10000; } // embed + LLM rerank + LLM answer generation

	public function precondition() {
		$need = [
			'BizCity_KG_Notebook_Service', 'BizCity_KG_Source_Service', 'BizCity_KG_Graph_Service',
			'BizCity_KG_Database', 'BizCity_KG_Retriever', 'BizCity_KG_Notebook_Folder',
		];
		foreach ( $need as $cls ) {
			if ( ! class_exists( $cls ) ) {
				return new WP_Error( 'kg_class_missing', $cls . ' chưa load — knowledge/kg-hub bootstrap không hoàn tất.' );
			}
		}
		if ( ! class_exists( 'BizCity_Knowledge_Embedding' ) ) {
			return new WP_Error( 'embedder_missing', 'BizCity_Knowledge_Embedding chưa load — không thể tạo embedding thật cho probe.' );
		}
		// [2026-08-21 Johnny Chu] R-DDV-MOCK-GATEWAY — ask() embeds, reranks and
		// answers via real LLM/embedding calls; mock diagnostics must SKIP.
		if ( defined( 'BIZCITY_DIAGNOSTICS_MOCK' ) && BIZCITY_DIAGNOSTICS_MOCK ) {
			return 'Mock mode: bỏ qua KG Graph RAG ask() live gateway probe.';
		}
		return true;
	}

	public function run( $ctx ): array {
		$steps    = [];
		$failures = [];
		$uniq     = substr( md5( uniqid( 'kgask', true ) ), 0, 8 );
		$token    = 'kgaskprobe' . $uniq;

		// ── Step 1: disposable notebook ─────────────────────────────────────
		$nb = BizCity_KG_Notebook_Service::instance()->create( [
			'name'        => '__healthtest_kg_ask_' . $uniq,
			'description' => 'DDV probe — safe to delete',
		], get_current_user_id() ?: 1 );
		$this->nb_id   = (int) ( $nb['id'] ?? 0 );
		$this->nb_uuid = (string) ( $nb['uuid'] ?? '' );
		$this->step( $ctx, $steps, 'Runtime - create notebook', $this->nb_id > 0 && $this->nb_uuid !== '', 'notebook_id=' . $this->nb_id . ' uuid=' . $this->nb_uuid );
		if ( $this->nb_id <= 0 ) {
			return [ 'status' => 'fail', 'error' => 'Notebook create failed', 'steps' => $steps ];
		}

		// ── Step 2: plant 1 fact passage + 2 entities + 1 relation ─────────
		$fact_text = "Sản phẩm {$token} có màu xanh dương duy nhất và chỉ được bán tại chi nhánh Quận 1.";
		$pid = BizCity_KG_Source_Service::instance()->add_passage( $this->nb_id, $fact_text, 'note' );
		$pid = is_wp_error( $pid ) ? 0 : (int) $pid;
		$this->step( $ctx, $steps, 'Runtime - add_passage() plants the fact', $pid > 0, 'passage_id=' . $pid );
		if ( $pid <= 0 ) { $failures[] = 'add_passage_failed'; }

		$eid_a = (int) BizCity_KG_Graph_Service::instance()->upsert_entity( $this->nb_id, 'Product' . $uniq, 'Product', "Sản phẩm {$token} màu xanh dương." );
		$eid_b = (int) BizCity_KG_Graph_Service::instance()->upsert_entity( $this->nb_id, 'BranchQ1' . $uniq, 'Location', 'Chi nhánh Quận 1.' );
		$rid   = 0;
		if ( $eid_a > 0 && $eid_b > 0 ) {
			$rid = (int) BizCity_KG_Graph_Service::instance()->upsert_relation( $this->nb_id, $eid_a, 'sold_at', $eid_b, $pid ?: null );
		}
		$this->step( $ctx, $steps, 'Runtime - plant entities + relation for graph expansion', $eid_a > 0 && $eid_b > 0 && $rid > 0, 'entity_a=' . $eid_a . ' entity_b=' . $eid_b . ' relation=' . $rid );
		if ( $eid_a <= 0 || $eid_b <= 0 || $rid <= 0 ) { $failures[] = 'entity_relation_plant_failed'; }

		// ── Step 3: ask() — the real question → answer pipeline ────────────
		if ( $pid > 0 ) {
			$question = "Sản phẩm {$token} có màu gì và bán ở đâu?";
			try {
				$result = BizCity_KG_Retriever::instance()->ask( $this->nb_id, $question, [ 'answer' => true ] );
			} catch ( \Throwable $e ) {
				$result = null;
				$this->step( $ctx, $steps, 'Runtime - ask() exception', false, $e->getMessage() );
				$failures[] = 'ask_exception';
			}

			if ( is_array( $result ) ) {
				$answer = (string) ( $result['answer'] ?? '' );
				$this->step( $ctx, $steps, 'Runtime - ask() returns non-empty answer', $answer !== '', 'answer_len=' . strlen( $answer ) );
				if ( $answer === '' ) { $failures[] = 'ask_empty_answer'; }

				// Grounded check: token must appear either in the answer or in a retrieved passage.
				$grounded_in_answer = strpos( $answer, $token ) !== false;
				$grounded_in_passage = false;
				foreach ( (array) ( $result['passages'] ?? [] ) as $p ) {
					if ( strpos( (string) ( $p['content'] ?? '' ), $token ) !== false ) { $grounded_in_passage = true; break; }
				}
				$grounded = $grounded_in_answer || $grounded_in_passage;
				$this->step( $ctx, $steps, 'Runtime - answer/passages grounded in planted fact (not hallucinated)', $grounded, 'grounded_in_answer=' . ( $grounded_in_answer ? 'yes' : 'no' ) . '; grounded_in_passage=' . ( $grounded_in_passage ? 'yes' : 'no' ) . '; passages=' . count( (array) ( $result['passages'] ?? [] ) ) );
				if ( ! $grounded ) { $failures[] = 'ask_not_grounded'; }

				$mode = (string) ( $result['retrieval_mode'] ?? '' );
				$non_degraded = $mode !== 'degraded_keyword';
				$this->step( $ctx, $steps, 'Runtime - retrieval used real embedding search (not degraded_keyword)', $non_degraded, 'retrieval_mode=' . ( $mode !== '' ? $mode : '(default/embedding)' ) );
				if ( ! $non_degraded ) { $failures[] = 'ask_degraded_keyword_fallback'; }

				$entity_ids = (array) ( $result['retrieval_detail']['entity_ids'] ?? [] );
				$entity_found = in_array( $eid_a, array_map( 'intval', $entity_ids ), true ) || in_array( $eid_b, array_map( 'intval', $entity_ids ), true );
				$this->step( $ctx, $steps, 'Runtime - planted entity present in retrieval_detail (graph expansion contributed)', $entity_found, 'entity_ids=' . implode( ',', $entity_ids ) );
				if ( ! $entity_found ) { $failures[] = 'ask_entity_not_in_retrieval'; }
			}
		}

		$status = empty( $failures ) ? 'pass' : 'fail';
		return [
			'status'   => $status,
			'summary'  => $status === 'pass'
				? 'KG Graph RAG ask(): real LLM answer grounded in planted fact, embedding retrieval (not degraded), graph expansion contributed the planted entity.'
				: 'KG Graph RAG ask() FAILED: ' . implode( ', ', array_unique( $failures ) ) . '.',
			'error'    => empty( $failures ) ? '' : implode( '; ', array_unique( $failures ) ),
			'fix_hint' => empty( $failures ) ? '' : 'Xem class-kg-retriever.php::ask() — kiểm tra embed(), seed_entities/relations(), expand_subgraph(), rerank(), generate_answer().',
			'steps'    => $steps,
		];
	}

	public function cleanup(): void {
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
	$probes[] = 'BizCity_Probe_KG_Graph_RAG_Ask';
	return $probes;
} );
