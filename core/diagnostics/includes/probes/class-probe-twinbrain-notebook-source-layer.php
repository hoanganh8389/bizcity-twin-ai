<?php
/**
 * BizCity Diagnostics — twinbrain.notebook_depth probe.
 *
 * Read-only DDV for Notebook Source Layer:
 * - Disk: class file + event schema exist.
 * - Loader: source layer + final composer classes/methods available.
 * - Runtime: synthetic notebook perspective produces source map, source block,
 *   valid citation guard, answer-depth profile, W0.20 final context chunks,
 *   and final composer strips invalid nb citations.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-07-18
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

if ( class_exists( 'BizCity_Probe_TwinBrain_Notebook_Source_Layer', false ) ) {
	return;
}

final class BizCity_Probe_TwinBrain_Notebook_Source_Layer implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'twinbrain.notebook_depth'; }
	public function label(): string { return 'TwinBrain Notebook Depth / Source Layer'; }
	public function description(): string {
		return 'Verifies Ask Brain notebook source map: notebook title/id source block, W0.20 graph/retrieval/rerank pack, depth profile, wisdom model purpose, evidence budget, nb citation validation, and final composer invalid-citation stripping.';
	}
	public function severity(): string { return 'warning'; }
	public function order(): int { return 63; }
	public function icon(): string { return 'notebook-tabs'; }
	public function estimate_ms(): int { return 220; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_TwinBrain_Notebook_Source_Layer' ) ) {
			return new WP_Error( 'notebook_source_layer_missing', 'BizCity_TwinBrain_Notebook_Source_Layer is not loaded.' );
		}
		if ( ! class_exists( 'BizCity_TwinBrain_Final_Composer' ) ) {
			return new WP_Error( 'final_composer_missing', 'BizCity_TwinBrain_Final_Composer is not loaded.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-07-18 Johnny Chu] PHASE-TBR-NB-MOAT — DDV probe for notebook source map + composer contract.
		$steps    = array();
		$failures = array();

		$tb_dir = defined( 'BIZCITY_TWINBRAIN_DIR' ) ? (string) BIZCITY_TWINBRAIN_DIR : '';
		$source_file = $tb_dir !== '' ? $tb_dir . 'includes/class-twinbrain-notebook-source-layer.php' : '';
		$source_file_deep_file = $tb_dir !== '' ? $tb_dir . 'includes/class-twinbrain-source-file-deep-layer.php' : '';
		$schema_file = $tb_dir !== '' ? $tb_dir . 'includes/event-schemas/notebook_source_layer_ready.json' : '';

		$this->step( $ctx, $steps, 'Disk - notebook source layer file', file_exists( $source_file ), $source_file );
		if ( ! file_exists( $source_file ) ) { $failures[] = 'source_layer_file_missing'; }

		$this->step( $ctx, $steps, 'Disk - source file deep layer file', file_exists( $source_file_deep_file ), $source_file_deep_file );
		if ( ! file_exists( $source_file_deep_file ) ) { $failures[] = 'source_file_deep_layer_file_missing'; }

		$this->step( $ctx, $steps, 'Disk - notebook source event schema', file_exists( $schema_file ), $schema_file );
		if ( ! file_exists( $schema_file ) ) { $failures[] = 'event_schema_missing'; }

		$methods_ok = method_exists( 'BizCity_TwinBrain_Notebook_Source_Layer', 'build_from_turn' )
			&& method_exists( 'BizCity_TwinBrain_Notebook_Source_Layer', 'build_source_block_md' )
			&& method_exists( 'BizCity_TwinBrain_Notebook_Source_Layer', 'validate_citation' )
			&& method_exists( 'BizCity_TwinBrain_Notebook_Source_Layer', 'strip_invalid_citations' );
		$this->step( $ctx, $steps, 'Loader - source layer methods', $methods_ok, $methods_ok ? 'Required public methods available.' : 'Missing source layer public methods.' );
		if ( ! $methods_ok ) { $failures[] = 'source_layer_methods_missing'; }

		$composer_method = method_exists( 'BizCity_TwinBrain_Final_Composer', 'compose_stream' )
			&& method_exists( 'BizCity_TwinBrain_Final_Composer', 'instance' )
			&& method_exists( 'BizCity_TwinBrain_Final_Composer', 'resolve_notebook_depth_profile' )
			&& method_exists( 'BizCity_TwinBrain_Final_Composer', 'resolve_llm_purpose' );
		$this->step( $ctx, $steps, 'Loader - final composer available', $composer_method, $composer_method ? 'Final composer loaded.' : 'Final composer missing.' );
		if ( ! $composer_method ) { $failures[] = 'final_composer_missing'; }

		$runner_method = class_exists( 'BizCity_TwinBrain_Perspective_Runner' )
			&& method_exists( 'BizCity_TwinBrain_Perspective_Runner', 'resolve_evidence_budget' );
		$this->step( $ctx, $steps, 'Loader - perspective evidence budget', $runner_method, $runner_method ? 'Perspective runner evidence budget resolver available.' : 'Perspective runner evidence budget resolver missing.' );
		if ( ! $runner_method ) { $failures[] = 'perspective_evidence_budget_missing'; }

		$source_file_layer_ok = class_exists( 'BizCity_TwinBrain_Source_File_Deep_Layer' )
			&& method_exists( 'BizCity_TwinBrain_Source_File_Deep_Layer', 'build_from_source_map' );
		$this->step( $ctx, $steps, 'Loader - source file deep layer', $source_file_layer_ok, $source_file_layer_ok ? 'Source file deep layer available.' : 'Source file deep layer missing.' );
		if ( ! $source_file_layer_ok ) { $failures[] = 'source_file_deep_layer_missing'; }

		$source_layer = BizCity_TwinBrain_Notebook_Source_Layer::instance();
		$candidates = array(
			array(
				'notebook_id' => 990001,
				'label'       => '__healthtest Notebook Moat',
				'score'       => 8.25,
				'reason'      => 'keyword title=1 body=2 cov=2/3 hits=[cellox,giay]',
			),
		);
		$answers = array(
			array(
				'notebook_id' => 990001,
				'label'       => '__healthtest Notebook Moat',
				'stance'      => 'sources_only',
				'confidence'  => 1.0,
				'answer_md'   => '[nb:990001/p880001] Giấy cellox là nguồn test cho Notebook Source Layer, có đủ token và excerpt để probe không cần DB fixture. [nb:990001/p880002] Đoạn lân cận synthetic giúp kiểm tra neighborhood rank_reason. [nb:990001/p880003] Đoạn sibling cùng source giúp kiểm tra source-file expansion. [nb:990001/p880004] Entity relation synthetic liên kết cellox với giấy test. [nb:990001/p880005] Retrieval candidate synthetic chứa token cellox và citation hợp lệ. [nb:990001/p880006] Rerank synthetic ưu tiên overlap lexical với giấy. [nb:990001/p880007] Final context chunk synthetic kiểm tra top evidence pack. [nb:990001/p880008] Đoạn thứ tám xác nhận cap final_context_chunks.',
				'citations'   => array(
					array(
						'token'          => '[nb:990001/p880001]',
						'kind'           => 'nb',
						'notebook_id'    => 990001,
						'passage_id'     => 880001,
						'rank_reason'    => 'primary_hit',
						'matched_tokens' => array( 'cellox', 'giay' ),
					),
					array(
						'token'          => '[nb:990001/p880002]',
						'kind'           => 'nb',
						'notebook_id'    => 990001,
						'passage_id'     => 880002,
						'rank_reason'    => 'neighbor_context',
						'matched_tokens' => array(),
					),
					array(
						'token'          => '[nb:990001/p880003]',
						'kind'           => 'nb',
						'notebook_id'    => 990001,
						'passage_id'     => 880003,
						'rank_reason'    => 'source_sibling_context',
						'matched_tokens' => array( 'cellox' ),
					),
					array( 'token' => '[nb:990001/p880004]', 'kind' => 'nb', 'notebook_id' => 990001, 'passage_id' => 880004, 'rank_reason' => 'graph_relation_context', 'matched_tokens' => array( 'cellox', 'relation' ) ),
					array( 'token' => '[nb:990001/p880005]', 'kind' => 'nb', 'notebook_id' => 990001, 'passage_id' => 880005, 'rank_reason' => 'retrieval_candidate', 'matched_tokens' => array( 'cellox' ) ),
					array( 'token' => '[nb:990001/p880006]', 'kind' => 'nb', 'notebook_id' => 990001, 'passage_id' => 880006, 'rank_reason' => 'rerank_candidate', 'matched_tokens' => array( 'giay' ) ),
					array( 'token' => '[nb:990001/p880007]', 'kind' => 'nb', 'notebook_id' => 990001, 'passage_id' => 880007, 'rank_reason' => 'final_context_candidate', 'matched_tokens' => array( 'cellox', 'giay' ) ),
					array( 'token' => '[nb:990001/p880008]', 'kind' => 'nb', 'notebook_id' => 990001, 'passage_id' => 880008, 'rank_reason' => 'final_context_candidate', 'matched_tokens' => array( 'cellox' ) ),
				),
			),
		);

		$payload = $source_layer->build_from_turn( $candidates, $answers, array(
			'notebook_search_context_query' => 'cellox giay relation',
			// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.20.2/W0.20.3 — synthetic DDV verifies shape without live vector embedding or Hub rerank side effects.
			'w020_skip_vector_retriever' => true,
			'w020_skip_hub_rerank'       => true,
			// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.21.3 — keep synthetic contract probe offline; live TwinSearch is covered by optional runtime steps.
			'w020_skip_selector_hardening' => true,
		) );
		$source_map = isset( $payload['notebook_source_map'] ) && is_array( $payload['notebook_source_map'] ) ? $payload['notebook_source_map'] : array();
		$source_block = (string) ( $payload['notebook_source_block_md'] ?? '' );
		$counts = isset( $payload['notebook_source_counts'] ) && is_array( $payload['notebook_source_counts'] ) ? $payload['notebook_source_counts'] : array();
		$probe_row = $this->find_source_map_row( $source_map, 990001 );
		$probe_passages = is_array( $probe_row ) ? (array) ( $probe_row['passages'] ?? array() ) : array();

		$runtime_shape_ok = is_array( $probe_row )
			&& strpos( $source_block, '__healthtest Notebook Moat' ) !== false
			&& count( $probe_passages ) >= 8
			&& (int) ( $counts['passage_count'] ?? 0 ) >= 8
			&& $this->source_map_has_rank_reason( $source_map, 'neighbor_context' )
			&& $this->source_map_has_rank_reason( $source_map, 'source_sibling_context' );
		$this->step( $ctx, $steps, 'Runtime - source map shape', $runtime_shape_ok, 'notebooks=' . (int) ( $counts['notebook_count'] ?? 0 ) . '; passages=' . (int) ( $counts['passage_count'] ?? 0 ) . '; probe_nb_present=' . ( is_array( $probe_row ) ? 'yes' : 'no' ) . '; probe_nb_passages=' . count( $probe_passages ) );
		if ( ! $runtime_shape_ok ) { $failures[] = 'source_map_shape_invalid'; }

		$w020_ok = $this->probe_w020_graph_vector_rerank_contract( $payload );
		$this->step( $ctx, $steps, 'Runtime - W0.20 graph/retrieval/rerank pack', $w020_ok['ok'], $w020_ok['detail'] );
		if ( ! $w020_ok['ok'] ) { $failures[] = 'w020_graph_vector_rerank_failed'; }

		$w020_vector_live = $this->probe_w020_live_vector_retriever( $ctx, $steps );
		if ( $w020_vector_live['status'] === 'fail' ) {
			$failures[] = 'w020_live_vector_failed';
		}

		$w020_hub_rerank = $this->probe_w020_live_hub_rerank( $ctx, $steps );
		if ( $w020_hub_rerank['status'] === 'fail' ) {
			$failures[] = 'w020_live_hub_rerank_failed';
		}

		$valid_ok = $source_layer->validate_citation( '[nb:990001/p880001]', $source_map );
		$neighbor_ok = $source_layer->validate_citation( '[nb:990001/p880002]', $source_map );
		$sibling_ok = $source_layer->validate_citation( '[nb:990001/p880003]', $source_map );
		$invalid_ok = ! $source_layer->validate_citation( '[nb:0/p0]', $source_map );
		$this->step( $ctx, $steps, 'Runtime - citation validation', ( $valid_ok && $neighbor_ok && $sibling_ok && $invalid_ok ), 'valid=' . ( $valid_ok ? 'yes' : 'no' ) . '; neighbor=' . ( $neighbor_ok ? 'yes' : 'no' ) . '; sibling=' . ( $sibling_ok ? 'yes' : 'no' ) . '; invalid_rejected=' . ( $invalid_ok ? 'yes' : 'no' ) );
		if ( ! ( $valid_ok && $neighbor_ok && $sibling_ok && $invalid_ok ) ) { $failures[] = 'citation_validation_failed'; }

		$composer_ok = $this->probe_final_composer_contract( $source_map, $source_block );
		$this->step( $ctx, $steps, 'Runtime - final composer source contract', $composer_ok['ok'], $composer_ok['detail'] );
		if ( ! $composer_ok['ok'] ) { $failures[] = 'final_composer_source_contract_failed'; }

		$source_file_prompt_ok = $this->probe_source_file_prompt_contract( $source_map, $source_block );
		$this->step( $ctx, $steps, 'Runtime - source file prompt contract', $source_file_prompt_ok['ok'], $source_file_prompt_ok['detail'] );
		if ( ! $source_file_prompt_ok['ok'] ) { $failures[] = 'source_file_prompt_contract_failed'; }

		$profile_ok = $this->probe_depth_profile_contract( $source_map, $source_block );
		$this->step( $ctx, $steps, 'Runtime - notebook depth profile contract', $profile_ok['ok'], $profile_ok['detail'] );
		if ( ! $profile_ok['ok'] ) { $failures[] = 'notebook_depth_profile_failed'; }

		$evidence_ok = $this->probe_evidence_budget_contract();
		$this->step( $ctx, $steps, 'Runtime - notebook evidence budget contract', $evidence_ok['ok'], $evidence_ok['detail'] );
		if ( ! $evidence_ok['ok'] ) { $failures[] = 'notebook_evidence_budget_failed'; }

		$fixture_ok = $this->probe_real_fixture( $ctx, $steps, $source_layer );
		if ( $fixture_ok['status'] === 'fail' ) {
			$failures[] = 'real_fixture_failed';
		}

		$live_ok = $this->probe_live_runner_fixture( $ctx, $steps, $source_layer );
		if ( $live_ok['status'] === 'fail' ) {
			$failures[] = 'live_runner_fixture_failed';
		}

		$source_file_ok = $this->probe_source_file_deep_layer( $ctx, $steps, $source_layer );
		if ( $source_file_ok['status'] === 'fail' ) {
			$failures[] = 'source_file_deep_layer_failed';
		}

		$status = empty( $failures ) ? 'pass' : 'fail';
		return array(
			'status'   => $status,
			'summary'  => $status === 'pass'
				? 'Notebook Source Layer operational: source map, source block, citation guard, and composer strip contract passed.'
				: 'Notebook Source Layer failed: ' . implode( ', ', array_unique( $failures ) ) . '.',
			'error'    => empty( $failures ) ? '' : implode( '; ', array_unique( $failures ) ),
			'fix_hint' => empty( $failures ) ? '' : 'Check class-twinbrain-notebook-source-layer.php wiring, event schema, and Final Composer notebook source contract.',
			'steps'    => $steps,
		);
	}

	public function cleanup(): void {
		// [2026-07-18 Johnny Chu] PHASE-TBR-NB-MOAT — read-only synthetic probe; no cleanup needed.
	}

	/**
	 * @param object              $ctx
	 * @param array<int,array>    $steps
	 * @param string              $label
	 * @param bool                $ok
	 * @param string              $detail
	 */
	private function step( $ctx, array &$steps, string $label, bool $ok, string $detail ): void {
		$step = array(
			'label'  => $label,
			'status' => $ok ? 'pass' : 'fail',
			'detail' => $detail,
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
	}

	/**
	 * @param array<int,array<string,mixed>> $source_map
	 * @param string                         $source_block
	 * @return array{ok:bool,detail:string}
	 */
	private function probe_final_composer_contract( array $source_map, string $source_block ): array {
		try {
			$composer = BizCity_TwinBrain_Final_Composer::instance();
			$ref = new ReflectionMethod( $composer, 'apply_notebook_source_contract' );
			$ref->setAccessible( true );
			$result = $ref->invokeArgs( $composer, array(
				'Kết luận có nguồn hợp lệ [nb:990001/p880001] và nguồn giả [nb:0/p0].',
				array(
					'notebook_source_map'      => $source_map,
					'notebook_source_block_md' => $source_block,
				),
			) );
			if ( ! is_array( $result ) ) {
				return array( 'ok' => false, 'detail' => 'apply_notebook_source_contract returned non-array.' );
			}
			$text = (string) ( $result['answer_md'] ?? '' );
			$invalid_count = (int) ( $result['invalid_count'] ?? 0 );
			$ok = strpos( $text, '[nb:990001/p880001]' ) !== false
				&& strpos( $text, '[nb:0/p0]' ) === false
				&& strpos( $text, '### Nguồn từ Notebook' ) !== false
				&& $invalid_count === 1;
			return array(
				'ok'     => $ok,
				'detail' => 'valid_kept=' . ( strpos( $text, '[nb:990001/p880001]' ) !== false ? 'yes' : 'no' )
					. '; invalid_count=' . $invalid_count
					. '; source_block=' . ( strpos( $text, '### Nguồn từ Notebook' ) !== false ? 'yes' : 'no' ),
			);
		} catch ( Throwable $e ) {
			return array( 'ok' => false, 'detail' => 'Reflection failed: ' . $e->getMessage() );
		}
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array{ok:bool,detail:string}
	 */
	private function probe_w020_graph_vector_rerank_contract( array $payload ): array {
		// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.20 — DDV guard for Graph → retrieval top30 → rerank → top8 payload.
		$pack = isset( $payload['graph_vector_rerank_pack'] ) && is_array( $payload['graph_vector_rerank_pack'] ) ? $payload['graph_vector_rerank_pack'] : array();
		$graph_entities = isset( $payload['graph_entities'] ) && is_array( $payload['graph_entities'] ) ? $payload['graph_entities'] : array();
		$retrieval_candidates = isset( $payload['retrieval_candidates'] ) && is_array( $payload['retrieval_candidates'] ) ? $payload['retrieval_candidates'] : array();
		$final_chunks = isset( $payload['final_context_chunks'] ) && is_array( $payload['final_context_chunks'] ) ? $payload['final_context_chunks'] : array();
		$first = isset( $final_chunks[0] ) && is_array( $final_chunks[0] ) ? $final_chunks[0] : array();

		$rerank_method = (string) ( $pack['rerank_method'] ?? '' );
		$vector_status = (string) ( $pack['vector_status'] ?? '' );
		$vector_reason = (string) ( $pack['vector_degraded_reason'] ?? '' );
		$shape_ok = (string) ( $pack['phase'] ?? '' ) === 'W0.20'
			&& (int) ( $pack['target_candidate_count'] ?? 0 ) === 30
			&& (int) ( $pack['target_final_count'] ?? 0 ) === 8
			&& ! empty( $pack['rerank_applied'] )
			&& in_array( $rerank_method, array( 'local_hybrid_graph_lexical', 'hub_branch_8_rerank' ), true )
			&& $vector_status !== ''
			&& isset( $pack['vector_candidate_count'] )
			&& array_key_exists( 'rerank_degraded', $pack )
			&& array_key_exists( 'graph_candidate_count', $pack )
			&& array_key_exists( 'selector_hardening_applied', $pack )
			&& array_key_exists( 'selector_hardening_reason', $pack );
		$count_ok = count( $retrieval_candidates ) >= 8
			&& count( $final_chunks ) === 8
			&& count( $graph_entities ) >= 2;
		$chunk_ok = ! empty( $first )
			&& ! empty( $first['citation'] )
			&& ! empty( $first['excerpt'] )
			&& isset( $first['rerank_score'] )
			&& ! empty( $first['rerank_reason'] );

		return array(
			'ok'     => $shape_ok && $count_ok && $chunk_ok,
			'detail' => 'phase=' . (string) ( $pack['phase'] ?? '' )
				. '; entities=' . count( $graph_entities )
				. '; candidates=' . count( $retrieval_candidates ) . '/' . (int) ( $pack['target_candidate_count'] ?? 0 )
				. '; final=' . count( $final_chunks ) . '/' . (int) ( $pack['target_final_count'] ?? 0 )
				. '; rerank=' . ( ! empty( $pack['rerank_applied'] ) ? $rerank_method : 'no' )
				. '; rerank_degraded=' . ( ! empty( $pack['rerank_degraded'] ) ? 'yes' : 'no' )
				. '; vector=' . $vector_status . ( $vector_reason !== '' ? '/' . $vector_reason : '' )
				. '; graph_candidates=' . (int) ( $pack['graph_candidate_count'] ?? 0 )
				. '; selector_hardening=' . ( ! empty( $pack['selector_hardening_applied'] ) ? 'yes' : 'no' )
				. '; first_citation=' . ( ! empty( $first['citation'] ) ? 'yes' : 'no' )
				. '; first_score=' . ( isset( $first['rerank_score'] ) ? (string) $first['rerank_score'] : 'missing' ),
		);
	}

	/**
	 * @param object           $ctx
	 * @param array<int,array> $steps
	 * @return array{status:string,detail:string}
	 */
	private function probe_w020_live_vector_retriever( $ctx, array &$steps ): array {
		// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.21.3 — optional live .bin vector DDV; SKIP when fixture/vector setup is unavailable.
		if ( ! class_exists( 'BizCity_KG_Retriever' ) ) {
			$this->step_status( $ctx, $steps, 'Runtime - W0.20 live vector retriever', 'skip', 'BizCity_KG_Retriever not loaded.' );
			return array( 'status' => 'skip', 'detail' => 'kg_retriever_not_loaded' );
		}
		$row = $this->find_real_kg_fixture();
		if ( empty( $row ) ) {
			$this->step_status( $ctx, $steps, 'Runtime - W0.20 live vector retriever', 'skip', 'No KG passage fixture available.' );
			return array( 'status' => 'skip', 'detail' => 'no_notebook_fixture' );
		}
		$nb_id = (int) ( $row['notebook_id'] ?? 0 );
		$tokens = $this->fixture_tokens( (string) ( $row['content'] ?? '' ) );
		$query = implode( ' ', array_slice( $tokens, 0, 4 ) );
		if ( $nb_id <= 0 || $query === '' ) {
			$this->step_status( $ctx, $steps, 'Runtime - W0.20 live vector retriever', 'skip', 'Fixture notebook/query unavailable.' );
			return array( 'status' => 'skip', 'detail' => 'fixture_query_unavailable' );
		}

		try {
			$result = BizCity_KG_Retriever::instance()->search( $nb_id, $query, 3 );
		} catch ( Throwable $e ) {
			$this->step_status( $ctx, $steps, 'Runtime - W0.20 live vector retriever', 'fail', 'Retriever threw: ' . $e->getMessage() );
			return array( 'status' => 'fail', 'detail' => 'retriever_exception' );
		}

		if ( ! is_array( $result ) ) {
			$this->step_status( $ctx, $steps, 'Runtime - W0.20 live vector retriever', 'fail', 'Retriever returned non-array.' );
			return array( 'status' => 'fail', 'detail' => 'retriever_invalid_shape' );
		}
		$count = (int) ( $result['count'] ?? 0 );
		$mode = (string) ( $result['mode'] ?? '' );
		$results = isset( $result['results'] ) && is_array( $result['results'] ) ? $result['results'] : array();
		if ( $mode === 'degraded_keyword' ) {
			$this->step_status( $ctx, $steps, 'Runtime - W0.20 live vector retriever', 'skip', 'Retriever degraded to keyword fallback; embedding/vector setup not live.' );
			return array( 'status' => 'skip', 'detail' => 'degraded_keyword' );
		}
		if ( $count <= 0 || empty( $results ) ) {
			$this->step_status( $ctx, $steps, 'Runtime - W0.20 live vector retriever', 'skip', 'No vector hits for fixture; .bin index may be absent.' );
			return array( 'status' => 'skip', 'detail' => 'no_vector_hits' );
		}
		$first = isset( $results[0] ) && is_array( $results[0] ) ? $results[0] : array();
		$ok = isset( $first['passage_id'], $first['score'], $first['snippet'] );
		$this->step_status(
			$ctx,
			$steps,
			'Runtime - W0.20 live vector retriever',
			$ok ? 'pass' : 'fail',
			'nb=' . $nb_id . '; query_terms=' . count( $tokens ) . '; count=' . $count . '; first_score=' . ( isset( $first['score'] ) ? (string) $first['score'] : 'missing' )
		);
		return array( 'status' => $ok ? 'pass' : 'fail', 'detail' => 'count=' . $count );
	}

	/**
	 * @param object           $ctx
	 * @param array<int,array> $steps
	 * @return array{status:string,detail:string}
	 */
	private function probe_w020_live_hub_rerank( $ctx, array &$steps ): array {
		// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.21.3 — optional live Hub Branch #8 rerank DDV; SKIP when API key is absent.
		if ( ! class_exists( 'BizCity_LLM_Client' ) || ! method_exists( 'BizCity_LLM_Client', 'rerank' ) ) {
			$this->step_status( $ctx, $steps, 'Runtime - W0.20 live Hub rerank', 'skip', 'BizCity_LLM_Client::rerank not loaded.' );
			return array( 'status' => 'skip', 'detail' => 'llm_client_rerank_not_loaded' );
		}
		$client = BizCity_LLM_Client::instance();
		if ( method_exists( $client, 'is_ready' ) && ! $client->is_ready() ) {
			$this->step_status( $ctx, $steps, 'Runtime - W0.20 live Hub rerank', 'skip', 'BizCity API key missing; live Hub rerank skipped.' );
			return array( 'status' => 'skip', 'detail' => 'api_key_missing' );
		}
		$candidates = array(
			array( 'id' => 'ddv_cellox', 'text' => 'Giấy cellox và sản phẩm giấy test trong notebook.', 'citation' => '[nb:990001/p880001]' ),
			array( 'id' => 'ddv_unrelated', 'text' => 'Một đoạn không liên quan đến truy vấn kiểm thử.', 'citation' => '[nb:990001/p880002]' ),
		);
		try {
			$result = $client->rerank( 'cellox giấy notebook', $candidates, array( 'top_k' => 2, 'purpose' => 'ddv_twinbrain_w020_rerank', 'timeout' => 10 ) );
		} catch ( Throwable $e ) {
			$this->step_status( $ctx, $steps, 'Runtime - W0.20 live Hub rerank', 'fail', 'Rerank threw: ' . $e->getMessage() );
			return array( 'status' => 'fail', 'detail' => 'rerank_exception' );
		}
		if ( ! is_array( $result ) ) {
			$this->step_status( $ctx, $steps, 'Runtime - W0.20 live Hub rerank', 'fail', 'Rerank returned non-array.' );
			return array( 'status' => 'fail', 'detail' => 'rerank_invalid_shape' );
		}
		$error_code = (string) ( $result['error_code'] ?? '' );
		if ( in_array( $error_code, array( 'api_key_missing', 'site_unmapped' ), true ) ) {
			$this->step_status( $ctx, $steps, 'Runtime - W0.20 live Hub rerank', 'skip', 'Hub rerank skipped: ' . $error_code );
			return array( 'status' => 'skip', 'detail' => $error_code );
		}
		$results = isset( $result['results'] ) && is_array( $result['results'] ) ? $result['results'] : array();
		$first = isset( $results[0] ) && is_array( $results[0] ) ? $results[0] : array();
		$ok = ! empty( $result['success'] ) && ! empty( $results ) && ! empty( $first['id'] );
		$this->step_status(
			$ctx,
			$steps,
			'Runtime - W0.20 live Hub rerank',
			$ok ? 'pass' : 'fail',
			'success=' . ( ! empty( $result['success'] ) ? 'yes' : 'no' )
				. '; degraded=' . ( ! empty( $result['_degraded'] ) ? 'yes' : 'no' )
				. '; http=' . (int) ( $result['http_status'] ?? 0 )
				. '; results=' . count( $results )
				. '; first_id=' . (string) ( $first['id'] ?? '' )
				. '; error_code=' . $error_code
		);
		return array( 'status' => $ok ? 'pass' : 'fail', 'detail' => 'results=' . count( $results ) );
	}

	/**
	 * @param array<int,array<string,mixed>> $source_map
	 * @param string                         $source_block
	 * @return array{ok:bool,detail:string}
	 */
	private function probe_source_file_prompt_contract( array $source_map, string $source_block ): array {
		// [2026-07-18 Johnny Chu] PHASE-TBR-NB-MOAT — DDV that Final Composer receives W0.7 source-file brief map.
		try {
			$composer = BizCity_TwinBrain_Final_Composer::instance();
			$ref = new ReflectionMethod( $composer, 'build_messages' );
			$ref->setAccessible( true );
			$briefs = array(
				array(
					'notebook_id'         => 990001,
					'source_id'           => 770001,
					'source_title'        => '__healthtest Source File W0.7',
					'source_type'         => 'upload',
					'passage_count_total' => 9,
					'passage_count_used'  => 3,
					'coverage'            => 'partial',
					'key_citations'       => array( '[nb:990001/p880001]', '[nb:990001/p880002]' ),
					'matched_tokens'      => array( 'cellox', 'giay' ),
					'relation_triples'    => array(
						array( 'subject' => 'cellox', 'predicate' => 'supports', 'object' => 'giay test' ),
					),
					'source_claims'       => array( 'File nguồn synthetic nêu claim cho Source File Deep Layer.' ),
					'source_gaps'         => array( 'Coverage file nguồn chưa đủ mạnh; cần thêm passage.' ),
					'rank_reason'         => 'primary_hit + neighbor_context',
				),
			);
			$messages = $ref->invokeArgs( $composer, array(
				'Hãy audit nguồn notebook này thật sâu.',
				array( 'answer_md' => 'Synthetic source answer.', 'consensus' => array(), 'tensions' => array(), 'recommendation' => '' ),
				array(),
				array(
					'notebook_source_map'      => $source_map,
					'notebook_source_block_md' => $source_block,
					'source_file_briefs'       => $briefs,
					'notebook_answer_depth'    => 'audit',
				),
			) );
			if ( ! is_array( $messages ) || count( $messages ) < 2 ) {
				return array( 'ok' => false, 'detail' => 'build_messages returned invalid shape.' );
			}
			$system = (string) ( $messages[0]['content'] ?? '' );
			$user   = (string) ( $messages[1]['content'] ?? '' );
			$ok = strpos( $system, 'Phân tích theo file nguồn' ) !== false
				&& strpos( $user, 'SOURCE FILE BRIEF MAP' ) !== false
				&& strpos( $user, '__healthtest Source File W0.7' ) !== false
				&& strpos( $user, 'triple: cellox --supports--> giay test' ) !== false
				&& strpos( $user, '[nb:990001/p880001]' ) !== false;
			return array(
				'ok'     => $ok,
				'detail' => 'system_contract=' . ( strpos( $system, 'Phân tích theo file nguồn' ) !== false ? 'yes' : 'no' )
					. '; brief_map=' . ( strpos( $user, 'SOURCE FILE BRIEF MAP' ) !== false ? 'yes' : 'no' )
					. '; title=' . ( strpos( $user, '__healthtest Source File W0.7' ) !== false ? 'yes' : 'no' )
					. '; triple=' . ( strpos( $user, 'triple: cellox --supports--> giay test' ) !== false ? 'yes' : 'no' ),
			);
		} catch ( Throwable $e ) {
			return array( 'ok' => false, 'detail' => 'Reflection failed: ' . $e->getMessage() );
		}
	}

	/**
	 * @param array<int,array<string,mixed>> $source_map
	 * @param string                         $source_block
	 * @return array{ok:bool,detail:string}
	 */
	private function probe_depth_profile_contract( array $source_map, string $source_block ): array {
		// [2026-07-18 Johnny Chu] PHASE-TBR-NB-MOAT — DDV for Notebook answer-depth profile budgets/prompt contract without LLM call.
		try {
			$composer = BizCity_TwinBrain_Final_Composer::instance();
			$opts = array(
				'notebook_source_map'      => $source_map,
				'notebook_source_block_md' => $source_block,
			);

			$deep = $composer->resolve_notebook_depth_profile( 'Hãy phân tích sâu và chi tiết nguồn notebook này.', $opts, false );
			$brief = $composer->resolve_notebook_depth_profile( 'Tóm tắt ngắn gọn giúp tôi.', array_merge( $opts, array( 'notebook_answer_depth' => 'brief' ) ), false );
			$audit = $composer->resolve_notebook_depth_profile( 'Audit và đối chiếu nguồn notebook.', $opts, false );
			$guru_default = $composer->resolve_notebook_depth_profile( 'Giải thích theo guru.', $opts, true );
			$deep_purpose = $composer->resolve_llm_purpose( $deep, $opts );
			$brief_purpose = $composer->resolve_llm_purpose( $brief, $opts );

			$profile_ok = (string) ( $deep['profile'] ?? '' ) === 'deep'
				&& (int) ( $deep['ans_cap'] ?? 0 ) >= 1500
				&& (int) ( $deep['max_tokens'] ?? 0 ) >= 4000
				&& (string) ( $brief['profile'] ?? '' ) === 'brief'
				&& (string) ( $audit['profile'] ?? '' ) === 'audit'
				&& (string) ( $guru_default['profile'] ?? '' ) === 'deep'
				&& $deep_purpose === 'twinbrain_final_compose'
				&& $brief_purpose === 'twinbrain_final_compose';

			$ref = new ReflectionMethod( $composer, 'build_messages' );
			$ref->setAccessible( true );
			$messages = $ref->invokeArgs( $composer, array(
				'Hãy phân tích sâu và chi tiết nguồn notebook này.',
				array(
					'answer_md'      => 'Nguồn synthetic hợp lệ [nb:990001/p880001].',
					'consensus'      => array( 'Nguồn synthetic có đủ citation.' ),
					'tensions'       => array(),
					'recommendation' => 'Dùng citation hợp lệ.',
				),
				array(
					array(
						'notebook_id' => 990001,
						'label'       => '__healthtest Notebook Moat',
						'stance'      => 'sources_only',
						'confidence'  => 1.0,
						'answer_md'   => '[nb:990001/p880001] synthetic evidence.',
					),
				),
				$opts,
			) );
			$system = is_array( $messages ) && isset( $messages[0]['content'] ) ? (string) $messages[0]['content'] : '';
			$prompt_ok = strpos( $system, 'Answer depth profile: `deep`' ) !== false
				&& strpos( $system, 'tối đa 1800 từ' ) !== false
				&& strpos( $system, 'Báo cáo thiếu hụt dữ liệu là evidence nội bộ' ) === false;

			return array(
				'ok'     => $profile_ok && $prompt_ok,
				'detail' => 'deep=' . (string) ( $deep['profile'] ?? '' )
					. '/' . (int) ( $deep['ans_cap'] ?? 0 )
					. '; deep_purpose=' . $deep_purpose
					. '; brief_purpose=' . $brief_purpose
					. '; brief=' . (string) ( $brief['profile'] ?? '' )
					. '; audit=' . (string) ( $audit['profile'] ?? '' )
					. '; guru_default=' . (string) ( $guru_default['profile'] ?? '' )
					. '; prompt_contract=' . ( $prompt_ok ? 'yes' : 'no' ),
			);
		} catch ( Throwable $e ) {
			return array( 'ok' => false, 'detail' => 'Depth profile reflection failed: ' . $e->getMessage() );
		}
	}

	/**
	 * @return array{ok:bool,detail:string}
	 */
	private function probe_evidence_budget_contract(): array {
		// [2026-07-18 Johnny Chu] PHASE-TBR-NB-MOAT — DDV for profile-driven evidence budget and model registry purpose.
		try {
			$runner = BizCity_TwinBrain_Perspective_Runner::instance();
			$normal = $runner->resolve_evidence_budget( 'Giải thích nội dung notebook.', array() );
			$deep = $runner->resolve_evidence_budget( 'Hãy phân tích sâu và chi tiết nội dung notebook.', array() );
			$audit = $runner->resolve_evidence_budget( 'Audit và đối chiếu nguồn notebook.', array() );

			$model_ok = class_exists( 'BizCity_LLM_Models' )
				&& (string) ( BizCity_LLM_Models::DEFAULTS['twinbrain_wisdom'] ?? '' ) === 'google/gemini-2.5-pro'
				&& (string) ( BizCity_LLM_Models::FALLBACK_DEFAULTS['twinbrain_wisdom'] ?? '' ) === 'anthropic/claude-sonnet-4'
				&& ! empty( BizCity_LLM_Models::get( 'twinbrain_wisdom' ) );

			$budget_ok = (string) ( $normal['profile'] ?? '' ) === 'normal'
				&& (int) ( $deep['passage_limit'] ?? 0 ) >= 10
				&& (int) ( $deep['passage_trunc_chars'] ?? 0 ) >= 1000
				&& (int) ( $deep['neighbor_radius'] ?? 0 ) >= 1
				&& (int) ( $deep['expanded_passage_limit'] ?? 0 ) > (int) ( $deep['passage_limit'] ?? 0 )
				&& (int) ( $deep['source_sibling_limit'] ?? 0 ) >= 4
				&& (int) ( $deep['diversity_per_source_cap'] ?? 0 ) >= 4
				&& (int) ( $audit['passage_limit'] ?? 0 ) >= (int) ( $deep['passage_limit'] ?? 0 )
				&& (int) ( $audit['neighbor_radius'] ?? 0 ) >= 2
				&& (int) ( $audit['source_sibling_limit'] ?? 0 ) >= (int) ( $deep['source_sibling_limit'] ?? 0 )
				&& (int) ( $audit['keyword_overfetch_cap'] ?? 0 ) >= 100;

			return array(
				'ok'     => $budget_ok && $model_ok,
				'detail' => 'normal=' . (string) ( $normal['profile'] ?? '' )
					. '; deep_limit=' . (int) ( $deep['passage_limit'] ?? 0 )
					. '; deep_trunc=' . (int) ( $deep['passage_trunc_chars'] ?? 0 )
					. '; deep_radius=' . (int) ( $deep['neighbor_radius'] ?? 0 )
					. '; deep_expanded_cap=' . (int) ( $deep['expanded_passage_limit'] ?? 0 )
					. '; deep_sibling=' . (int) ( $deep['source_sibling_limit'] ?? 0 )
					. '; deep_diversity_cap=' . (int) ( $deep['diversity_per_source_cap'] ?? 0 )
					. '; audit_limit=' . (int) ( $audit['passage_limit'] ?? 0 )
					. '; audit_radius=' . (int) ( $audit['neighbor_radius'] ?? 0 )
					. '; audit_sibling=' . (int) ( $audit['source_sibling_limit'] ?? 0 )
					. '; wisdom_model=' . ( $model_ok ? 'yes' : 'no' ),
			);
		} catch ( Throwable $e ) {
			return array( 'ok' => false, 'detail' => 'Evidence budget probe failed: ' . $e->getMessage() );
		}
	}

	/**
	 * @param array<int,array<string,mixed>> $source_map
	 * @param string                         $rank_reason
	 * @return bool
	 */
	private function source_map_has_rank_reason( array $source_map, string $rank_reason ): bool {
		foreach ( $source_map as $row ) {
			foreach ( (array) ( $row['passages'] ?? array() ) as $passage ) {
				if ( is_array( $passage ) && (string) ( $passage['rank_reason'] ?? '' ) === $rank_reason ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * @param array<int,array<string,mixed>> $source_map
	 * @return array<string,mixed>|null
	 */
	private function find_source_map_row( array $source_map, int $notebook_id ): ?array {
		foreach ( $source_map as $row ) {
			if ( is_array( $row ) && (int) ( $row['notebook_id'] ?? 0 ) === $notebook_id ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Probe real KG-Hub fixture when available. SKIP is acceptable on empty dev sites.
	 *
	 * @param object                               $ctx
	 * @param array<int,array>                     $steps
	 * @param BizCity_TwinBrain_Notebook_Source_Layer $source_layer
	 * @return array{status:string,detail:string}
	 */
	private function probe_real_fixture( $ctx, array &$steps, BizCity_TwinBrain_Notebook_Source_Layer $source_layer ): array {
		if ( ! class_exists( 'BizCity_KG_Database' ) || ! class_exists( 'BizCity_KG_Content_Router' ) ) {
			$this->step_status( $ctx, $steps, 'Runtime - real KG fixture', 'skip', 'KG database/content router not loaded.' );
			return array( 'status' => 'skip', 'detail' => 'kg_not_loaded' );
		}
		$row = $this->find_real_kg_fixture();
		if ( empty( $row ) ) {
			$this->step_status( $ctx, $steps, 'Runtime - real KG fixture', 'skip', 'No KG passage fixture available.' );
			return array( 'status' => 'skip', 'detail' => 'no_notebook_fixture' );
		}
		$body = trim( (string) ( $row['content'] ?? '' ) );
		if ( $body === '' ) {
			$this->step_status( $ctx, $steps, 'Runtime - real KG fixture hydration', 'fail', 'Fixture passage body is empty after hydration.' );
			return array( 'status' => 'fail', 'detail' => 'empty_body_after_hydrate' );
		}

		$nb_id = (int) ( $row['notebook_id'] ?? 0 );
		$pid   = (int) ( $row['id'] ?? 0 );
		$title = (string) ( $row['notebook_name'] ?? ( 'Notebook #' . $nb_id ) );
		$token = sprintf( '[nb:%d/p%d]', $nb_id, $pid );
		$fixture_payload = $source_layer->build_from_turn(
			array( array( 'notebook_id' => $nb_id, 'label' => $title, 'score' => 1.0, 'reason' => 'real_fixture' ) ),
			array( array(
				'notebook_id' => $nb_id,
				'label'       => $title,
				'stance'      => 'sources_only',
				'confidence'  => 1.0,
				'answer_md'   => $token . ' ' . mb_substr( $body, 0, 240 ),
				'citations'   => array( array( 'token' => $token, 'kind' => 'nb', 'notebook_id' => $nb_id, 'passage_id' => $pid ) ),
			) ),
			array()
		);

		$map = isset( $fixture_payload['notebook_source_map'] ) && is_array( $fixture_payload['notebook_source_map'] ) ? $fixture_payload['notebook_source_map'] : array();
		$source_ok = ! empty( $map ) && $source_layer->validate_citation( $token, $map );
		$resolver_ok = true;
		$resolver_detail = 'resolver_not_loaded';
		if ( class_exists( 'BizCity_Twin_Citation_Resolver' ) ) {
			$resolved = BizCity_Twin_Citation_Resolver::resolve_batch( array( $token ), get_current_user_id() );
			$record = isset( $resolved[ $token ] ) && is_array( $resolved[ $token ] ) ? $resolved[ $token ] : array();
			$resolver_ok = ! empty( $record ) && (string) ( $record['evidence_excerpt'] ?? '' ) !== '';
			$resolver_detail = 'resolver_excerpt_len=' . strlen( (string) ( $record['evidence_excerpt'] ?? '' ) );
		}

		$ok = $source_ok && $resolver_ok;
		$this->step_status(
			$ctx,
			$steps,
			'Runtime - real KG fixture source + resolver',
			$ok ? 'pass' : 'fail',
			'token=' . $token . '; source_map=' . ( $source_ok ? 'ok' : 'bad' ) . '; ' . $resolver_detail
		);

		return array( 'status' => $ok ? 'pass' : 'fail', 'detail' => $resolver_detail );
	}

	/**
	 * Probe the live sources-only runner path against a real KG fixture without calling LLM.
	 *
	 * @param object                                  $ctx
	 * @param array<int,array>                        $steps
	 * @param BizCity_TwinBrain_Notebook_Source_Layer $source_layer
	 * @return array{status:string,detail:string}
	 */
	private function probe_live_runner_fixture( $ctx, array &$steps, BizCity_TwinBrain_Notebook_Source_Layer $source_layer ): array {
		// [2026-07-18 Johnny Chu] PHASE-TBR-NB-MOAT — DDV live-run for runner output/schema without LLM calls.
		if ( ! class_exists( 'BizCity_TwinBrain_Perspective_Runner' ) ) {
			$this->step_status( $ctx, $steps, 'Runtime - live runner fixture', 'skip', 'Perspective runner not loaded.' );
			return array( 'status' => 'skip', 'detail' => 'runner_not_loaded' );
		}
		$row = $this->find_real_kg_fixture();
		if ( empty( $row ) ) {
			$this->step_status( $ctx, $steps, 'Runtime - live runner fixture', 'skip', 'No KG passage fixture available.' );
			return array( 'status' => 'skip', 'detail' => 'no_notebook_fixture' );
		}
		$nb_id = (int) ( $row['notebook_id'] ?? 0 );
		$title = (string) ( $row['notebook_name'] ?? ( 'Notebook #' . $nb_id ) );
		$body  = trim( (string) ( $row['content'] ?? '' ) );
		if ( $nb_id <= 0 || $body === '' ) {
			$this->step_status( $ctx, $steps, 'Runtime - live runner fixture', 'skip', 'Fixture notebook/body unavailable.' );
			return array( 'status' => 'skip', 'detail' => 'fixture_unavailable' );
		}

		$tokens = $this->fixture_tokens( $body );
		try {
			$answers = BizCity_TwinBrain_Perspective_Runner::instance()->run(
				'TBR_DDV_NOTEBOOK_DEPTH',
				'',
				array( array( 'notebook_id' => $nb_id, 'label' => $title, 'score' => 1.0, 'reason' => 'live_fixture' ) ),
				array( 'notebook_answer_depth' => 'audit', 'keyword_tokens' => $tokens )
			);
		} catch ( Throwable $e ) {
			$this->step_status( $ctx, $steps, 'Runtime - live runner fixture', 'fail', 'Runner threw: ' . $e->getMessage() );
			return array( 'status' => 'fail', 'detail' => 'runner_exception' );
		}

		$answer = is_array( $answers ) && isset( $answers[0] ) && is_array( $answers[0] ) ? $answers[0] : array();
		$citations = isset( $answer['citations'] ) && is_array( $answer['citations'] ) ? $answer['citations'] : array();
		$payload = $source_layer->build_from_turn(
			array( array( 'notebook_id' => $nb_id, 'label' => $title, 'score' => 1.0, 'reason' => 'live_fixture' ) ),
			$answer ? array( $answer ) : array(),
			array()
		);
		$map = isset( $payload['notebook_source_map'] ) && is_array( $payload['notebook_source_map'] ) ? $payload['notebook_source_map'] : array();
		$counts = isset( $payload['notebook_source_counts'] ) && is_array( $payload['notebook_source_counts'] ) ? $payload['notebook_source_counts'] : array();
		$first_token = isset( $citations[0]['token'] ) ? (string) $citations[0]['token'] : '';
		$valid_citation = $first_token !== '' && $source_layer->validate_citation( $first_token, $map );
		$budget = isset( $answer['notebook_evidence_budget'] ) && is_array( $answer['notebook_evidence_budget'] ) ? $answer['notebook_evidence_budget'] : array();
		$ok = (string) ( $answer['stance'] ?? '' ) === 'sources_only'
			&& ! empty( $citations )
			&& $valid_citation
			&& (int) ( $counts['passage_count'] ?? 0 ) >= 1
			&& (string) ( $budget['profile'] ?? '' ) === 'audit'
			&& (int) ( $budget['source_sibling_limit'] ?? 0 ) >= 1;

		$this->step_status(
			$ctx,
			$steps,
			'Runtime - live runner fixture',
			$ok ? 'pass' : 'fail',
			'nb=' . $nb_id
				. '; stance=' . (string) ( $answer['stance'] ?? '' )
				. '; citations=' . count( $citations )
				. '; source_passages=' . (int) ( $counts['passage_count'] ?? 0 )
				. '; valid_first=' . ( $valid_citation ? 'yes' : 'no' )
				. '; profile=' . (string) ( $budget['profile'] ?? '' )
				. '; sibling_budget=' . (int) ( $budget['source_sibling_limit'] ?? 0 )
		);

		return array( 'status' => $ok ? 'pass' : 'fail', 'detail' => 'citations=' . count( $citations ) );
	}

	/**
	 * @param object                                  $ctx
	 * @param array<int,array>                        $steps
	 * @param BizCity_TwinBrain_Notebook_Source_Layer $source_layer
	 * @return array{status:string,detail:string}
	 */
	private function probe_source_file_deep_layer( $ctx, array &$steps, BizCity_TwinBrain_Notebook_Source_Layer $source_layer ): array {
		// [2026-07-18 Johnny Chu] PHASE-TBR-NB-MOAT — DDV for W0.7 per-source-file briefs.
		if ( ! class_exists( 'BizCity_TwinBrain_Source_File_Deep_Layer' ) ) {
			$this->step_status( $ctx, $steps, 'Runtime - source file deep layer', 'skip', 'Source file deep layer not loaded.' );
			return array( 'status' => 'skip', 'detail' => 'source_file_layer_not_loaded' );
		}
		$row = $this->find_real_kg_fixture();
		if ( empty( $row ) ) {
			$this->step_status( $ctx, $steps, 'Runtime - source file deep layer', 'skip', 'No KG passage fixture available.' );
			return array( 'status' => 'skip', 'detail' => 'no_notebook_fixture' );
		}
		$source_id = (int) ( $row['source_id'] ?? 0 );
		if ( $source_id <= 0 ) {
			$this->step_status( $ctx, $steps, 'Runtime - source file deep layer', 'skip', 'Fixture passage has no source_id.' );
			return array( 'status' => 'skip', 'detail' => 'fixture_source_id_missing' );
		}
		$nb_id = (int) ( $row['notebook_id'] ?? 0 );
		$pid   = (int) ( $row['id'] ?? 0 );
		$title = (string) ( $row['notebook_name'] ?? ( 'Notebook #' . $nb_id ) );
		$token = sprintf( '[nb:%d/p%d]', $nb_id, $pid );
		$payload = $source_layer->build_from_turn(
			array( array( 'notebook_id' => $nb_id, 'label' => $title, 'score' => 1.0, 'reason' => 'source_file_fixture' ) ),
			array( array(
				'notebook_id' => $nb_id,
				'label'       => $title,
				'stance'      => 'sources_only',
				'confidence'  => 1.0,
				'answer_md'   => $token . ' ' . mb_substr( trim( (string) ( $row['content'] ?? '' ) ), 0, 240 ),
				'citations'   => array( array( 'token' => $token, 'kind' => 'nb', 'notebook_id' => $nb_id, 'passage_id' => $pid ) ),
			) ),
			array()
		);
		$briefs = isset( $payload['source_file_briefs'] ) && is_array( $payload['source_file_briefs'] ) ? $payload['source_file_briefs'] : array();
		$counts = isset( $payload['source_file_counts'] ) && is_array( $payload['source_file_counts'] ) ? $payload['source_file_counts'] : array();
		$brief = isset( $briefs[0] ) && is_array( $briefs[0] ) ? $briefs[0] : array();
		$citations = (array) ( $brief['key_citations'] ?? array() );
		$source_title = (string) ( $brief['source_title'] ?? '' );
		$joined_identity = $source_title !== '' && $source_title !== ( 'Source #' . $source_id );
		$triples = (array) ( $brief['relation_triples'] ?? array() );
		$triples_shape_ok = true;
		foreach ( $triples as $triple ) {
			if ( ! is_array( $triple ) || ! isset( $triple['subject'], $triple['predicate'], $triple['object'] ) ) {
				$triples_shape_ok = false;
				break;
			}
		}
		$ok = (int) ( $counts['source_file_count'] ?? 0 ) >= 1
			&& (int) ( $brief['source_id'] ?? 0 ) === $source_id
			&& $joined_identity
			&& in_array( $token, $citations, true )
			&& $triples_shape_ok;

		$this->step_status(
			$ctx,
			$steps,
			'Runtime - source file deep layer',
			$ok ? 'pass' : 'fail',
			'source_files=' . (int) ( $counts['source_file_count'] ?? 0 )
				. '; source_id=' . $source_id
				. '; joined_kg_source=' . ( $joined_identity ? 'yes' : 'no' )
				. '; citation=' . ( in_array( $token, $citations, true ) ? 'yes' : 'no' )
				. '; triples=' . count( $triples )
				. '; triples_shape=' . ( $triples_shape_ok ? 'yes' : 'no' )
		);

		return array( 'status' => $ok ? 'pass' : 'fail', 'detail' => 'source_files=' . (int) ( $counts['source_file_count'] ?? 0 ) );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function find_real_kg_fixture(): array {
		global $wpdb;
		if ( ! class_exists( 'BizCity_KG_Database' ) || ! class_exists( 'BizCity_KG_Content_Router' ) ) {
			return array();
		}
		$db = BizCity_KG_Database::instance();
		if ( ! method_exists( $db, 'tbl_passages' ) || ! method_exists( $db, 'tbl_notebooks' ) ) {
			return array();
		}
		$passages_tbl = $db->tbl_passages();
		$notebooks_tbl = $db->tbl_notebooks();
		if ( ! bizcity_tbl_exists( $passages_tbl ) || ! bizcity_tbl_exists( $notebooks_tbl ) ) {
			return array();
		}
		$rows = $wpdb->get_results(
			"SELECT p.id, p.notebook_id, p.source_id, p.chunk_id, p.content,
			        p.storage_ver, p.file_shard, p.file_offset, p.file_length,
			        nb.name AS notebook_name
			 FROM {$passages_tbl} p
			 INNER JOIN {$notebooks_tbl} nb ON nb.id = p.notebook_id
			 WHERE p.notebook_id > 0
			 ORDER BY (p.storage_ver = 2) DESC, p.id DESC
			 LIMIT 1",
			ARRAY_A
		);
		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return array();
		}
		BizCity_KG_Content_Router::instance()->hydrate_passages( $rows );
		return is_array( $rows[0] ) ? $rows[0] : array();
	}

	/**
	 * @param string $body
	 * @return array<int,string>
	 */
	private function fixture_tokens( string $body ): array {
		$body = function_exists( 'mb_strtolower' ) ? mb_strtolower( wp_strip_all_tags( $body ) ) : strtolower( wp_strip_all_tags( $body ) );
		$parts = preg_split( '/[^\p{L}\p{N}_]+/u', $body );
		$out = array();
		foreach ( is_array( $parts ) ? $parts : array() as $part ) {
			$part = trim( (string) $part );
			if ( function_exists( 'mb_strlen' ) ? mb_strlen( $part ) < 3 : strlen( $part ) < 3 ) {
				continue;
			}
			$out[] = $part;
			if ( count( $out ) >= 5 ) {
				break;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * @param object           $ctx
	 * @param array<int,array> $steps
	 * @param string           $label
	 * @param string           $status
	 * @param string           $detail
	 */
	private function step_status( $ctx, array &$steps, string $label, string $status, string $detail ): void {
		$step = array(
			'label'  => $label,
			'status' => $status,
			'detail' => $detail,
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
	}
}

// [2026-07-18 Johnny Chu] PHASE-TBR-NB-MOAT — register Notebook Depth DDV probe.
add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_TwinBrain_Notebook_Source_Layer';
	return $list;
} );