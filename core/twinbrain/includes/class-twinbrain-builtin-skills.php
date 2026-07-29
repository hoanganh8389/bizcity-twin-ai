<?php
/**
 * BizCity_TwinBrain_Builtin_Skills
 *
 * Registers the built-in brain-mode /skills via the
 * `bizcity_twinbrain_builtin_skills` filter.
 *
 * These skills are Tier 3 (lowest priority) in the 3-tier resolution order:
 *   1. User-owned (enabled=1, created_by=$user_id)
 *   2. Hub-imported (enabled=1, source='hub_imported')
 *   3. Built-in (this file — always available, no DB row needed)
 *
 * Each skill row mirrors the minimal shape returned by
 * BizCity_Automation_Repo_Workflows::find():
 *   { id, slug, name, description, graph_json, trigger_type, enabled, source }
 *
 * graph_json uses the pipeline simplified node format:
 *   { nodes: [{id, kind, label, config}], edges: [{source, target}] }
 * BizCity_TwinBrain_Workflow_Pipeline::get_node_kind() handles this format.
 *
 * @package BizCity_TwinBrain
 * @since   1.0.0 [2026-06-19 Johnny Chu] PHASE-TWB-WORKFLOW W3
 */

// Direct file access guard.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Double-load guard.
if ( class_exists( 'BizCity_TwinBrain_Builtin_Skills', false ) ) {
	return;
}

final class BizCity_TwinBrain_Builtin_Skills {

	/**
	 * Wire the filter. Called from core/twinbrain/bootstrap.php.
	 *
	 * [2026-06-19 Johnny Chu] PHASE-TWB-WORKFLOW W3
	 */
	public static function init(): void {
		add_filter( 'bizcity_twinbrain_builtin_skills', array( __CLASS__, 'register' ) );
	}

	/**
	 * Return all built-in skills merged into the catalog array.
	 *
	 * [2026-06-19 Johnny Chu] PHASE-TWB-WORKFLOW W3
	 *
	 * @param array $skills Incoming skill rows from earlier filter subscribers.
	 * @return array Merged skill rows.
	 */
	public static function register( array $skills ): array {
		$skills[] = self::skill_web_research_quick();
		$skills[] = self::skill_web_research_deep();
		$skills[] = self::skill_astro_quick();
		// [2026-07-18 Johnny Chu] PHASE-TWINWEB — expose seeded TwinGPT workflow templates to public FE /skills.
		foreach ( self::twingpt_template_skills() as $skill ) {
			$skills[] = $skill;
		}
		return $skills;
	}

	/**
	 * Convert seeded automation templates tagged `twingpt` into built-in workflow
	 * skills so Twin GPT public FE can run them without a pre-created workflow row.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function twingpt_template_skills(): array {
		// [2026-07-18 Johnny Chu] PHASE-TWINWEB — bridge template seeding to public FE workflow pipeline.
		if ( ! class_exists( 'BizCity_Automation_Repo_Templates' ) ) {
			return array();
		}
		if ( class_exists( 'BizCity_Automation_Templates_Seeder' ) ) {
			BizCity_Automation_Templates_Seeder::maybe_seed();
		}

		$result = BizCity_Automation_Repo_Templates::query( array(
			'source' => 'builtin',
			'limit'  => 200,
		) );
		$rows = isset( $result['rows'] ) && is_array( $result['rows'] ) ? $result['rows'] : array();
		$out  = array();

		foreach ( $rows as $tpl ) {
			$tags = ',' . (string) ( $tpl['tags'] ?? '' ) . ',';
			if ( strpos( $tags, ',twingpt,' ) === false ) {
				continue;
			}

			$template_slug = (string) ( $tpl['slug'] ?? '' );
			$graph_json    = is_string( $tpl['graph_json'] ?? null )
				? (string) $tpl['graph_json']
				: wp_json_encode( $tpl['graph'] ?? array( 'nodes' => array(), 'edges' => array() ) );
			$graph      = json_decode( (string) $graph_json, true );
			$node_count = is_array( $graph ) ? count( $graph['nodes'] ?? array() ) : 0;
			$skill_slug = self::template_slug_to_skill_slug( $template_slug );

			$out[] = array(
				'id'            => $skill_slug,
				'slug'          => $skill_slug,
				'name'          => (string) ( $tpl['name'] ?? $template_slug ),
				'label'         => (string) ( $tpl['name'] ?? $template_slug ),
				'description'   => (string) ( $tpl['description'] ?? '' ),
				'trigger_type'  => (string) ( $tpl['trigger_type'] ?? 'manual' ),
				'trigger_kind'  => (string) ( $tpl['trigger_type'] ?? 'manual' ),
				'enabled'       => 1,
				'workflow_id'   => 0,
				'source'        => 'template',
				'category'      => 'workflow',
				'icon'          => (string) ( $tpl['icon'] ?? 'Sparkles' ),
				'node_count'    => $node_count,
				'template_id'   => (int) ( $tpl['id'] ?? 0 ),
				'template_slug' => $template_slug,
				'graph_json'    => (string) $graph_json,
			);
		}

		return $out;
	}

	/**
	 * @param string $template_slug Seeded template slug.
	 * @return string Public skill slug.
	 */
	private static function template_slug_to_skill_slug( string $template_slug ): string {
		$slug = preg_replace( '/^tpl_/', '', $template_slug );
		$slug = preg_replace( '/_v\d+$/', '', (string) $slug );
		return (string) ( $slug ?: $template_slug );
	}

	// ── Built-in skill definitions ───────────────────────────────────────────

	/**
	 * /skill=web_research_quick
	 * KG lookup → compose. Fast single-pass answer.
	 *
	 * [2026-06-19 Johnny Chu] PHASE-TWB-WORKFLOW W3
	 *
	 * @return array
	 */
	private static function skill_web_research_quick(): array {
		return array(
			'id'           => 0,            // 0 = built-in (no DB row)
			'slug'         => 'web_research_quick',
			'name'         => 'Tìm kiếm nhanh',
			'description'  => 'Tìm kiếm tri thức nội bộ và trả lời trong 1 bước.',
			'trigger_type' => 'manual',
			'enabled'      => 1,
			'source'       => 'builtin',
			'node_count'   => 2,
			'graph_json'   => wp_json_encode( array(
				'nodes' => array(
					array(
						'id'     => 'n1',
						'kind'   => 'action.search_kg',
						'label'  => 'Tìm kiếm tri thức',
						'config' => array( 'top_k' => 5 ),
					),
					array(
						'id'    => 'n2',
						'kind'  => 'llm.compose',
						'label' => 'Tổng hợp câu trả lời',
					),
				),
				'edges' => array(
					array( 'source' => 'n1', 'target' => 'n2' ),
				),
			) ),
		);
	}

	/**
	 * /skill=web_research_deep
	 * KG lookup → MPR multi-perspective think → compose. Deeper analysis.
	 *
	 * [2026-06-19 Johnny Chu] PHASE-TWB-WORKFLOW W3
	 *
	 * @return array
	 */
	private static function skill_web_research_deep(): array {
		return array(
			'id'           => 0,
			'slug'         => 'web_research_deep',
			'name'         => 'Nghiên cứu chuyên sâu',
			'description'  => 'Tìm kiếm tri thức, phân tích đa chiều rồi tổng hợp câu trả lời sâu hơn.',
			'trigger_type' => 'manual',
			'enabled'      => 1,
			'source'       => 'builtin',
			'node_count'   => 3,
			'graph_json'   => wp_json_encode( array(
				'nodes' => array(
					array(
						'id'     => 'n1',
						'kind'   => 'action.search_kg',
						'label'  => 'Tìm kiếm tri thức',
						'config' => array( 'top_k' => 8 ),
					),
					array(
						'id'     => 'n2',
						'kind'   => 'llm.mpr_think',
						'label'  => 'Phân tích đa chiều',
						'config' => array( 'perspectives' => 2 ),
					),
					array(
						'id'    => 'n3',
						'kind'  => 'llm.compose',
						'label' => 'Tổng hợp sâu',
					),
				),
				'edges' => array(
					array( 'source' => 'n1', 'target' => 'n2' ),
					array( 'source' => 'n2', 'target' => 'n3' ),
				),
			) ),
		);
	}

	/**
	 * /skill=astro_quick
	 * Tử vi / astrology lookup → compose. Answers astrology queries from KG.
	 *
	 * [2026-06-19 Johnny Chu] PHASE-TWB-WORKFLOW W3
	 *
	 * @return array
	 */
	private static function skill_astro_quick(): array {
		return array(
			'id'           => 0,
			'slug'         => 'astro_quick',
			'name'         => 'Tra cứu tử vi',
			'description'  => 'Tra cứu thông tin tử vi, chiêm tinh từ cơ sở tri thức và trả lời ngay.',
			'trigger_type' => 'manual',
			'enabled'      => 1,
			'source'       => 'builtin',
			'node_count'   => 2,
			'graph_json'   => wp_json_encode( array(
				'nodes' => array(
					array(
						'id'     => 'n1',
						'kind'   => 'action.search_kg',
						'label'  => 'Tra cứu tri thức tử vi',
						'config' => array( 'top_k' => 6, 'topic' => 'astrology' ),
					),
					array(
						'id'    => 'n2',
						'kind'  => 'llm.compose',
						'label' => 'Giải đáp tử vi',
					),
				),
				'edges' => array(
					array( 'source' => 'n1', 'target' => 'n2' ),
				),
			) ),
		);
	}
}
