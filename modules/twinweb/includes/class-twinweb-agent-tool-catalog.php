<?php
/**
 * TwinWeb Agent Tool Catalog.
 *
 * Contract-only catalog for Twin GPT customer-facing agent tools. Execution
 * still belongs to the canonical BizCity_Twin_Tool_Registry / backend tools;
 * this class defines effective UI/runtime metadata for intent planning,
 * MPR timeline events and artifact canvas rendering.
 *
 * PHP 7.4 compatible.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Modules\TwinWeb
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_TwinWeb_Agent_Tool_Catalog' ) ) {
	return;
}

class BizCity_TwinWeb_Agent_Tool_Catalog {

	/** @var BizCity_TwinWeb_Agent_Tool_Catalog|null */
	private static $instance = null;

	/**
	 * Singleton.
	 *
	 * @return BizCity_TwinWeb_Agent_Tool_Catalog
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Return effective catalog rows.
	 *
	 * @param array $ctx Runtime context: user_id, plan_slug, surface, project_id, thread_id.
	 * @return array<string,array<string,mixed>>
	 */
	public function all( array $ctx = array() ) {
		// [2026-07-19 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — expose stable tool/artifact contract without executing side effects.
		$tools = $this->builtins();
		$tools = apply_filters( 'bizcity_twinweb_agent_tool_catalog', $tools, $ctx );

		$out = array();
		foreach ( (array) $tools as $slug => $row ) {
			$normalized = $this->normalize_tool( (string) $slug, is_array( $row ) ? $row : array() );
			if ( '' === $normalized['slug'] ) {
				continue;
			}
			$out[ $normalized['slug'] ] = $normalized;
		}

		return $out;
	}

	/**
	 * Get a single catalog row.
	 *
	 * @param string $slug Tool slug.
	 * @param array  $ctx Runtime context.
	 * @return array<string,mixed>|null
	 */
	public function get( $slug, array $ctx = array() ) {
		$slug  = sanitize_key( (string) $slug );
		$tools = $this->all( $ctx );
		return isset( $tools[ $slug ] ) ? $tools[ $slug ] : null;
	}

	/**
	 * Lightweight keyword planner used by future REST/DDV layers.
	 *
	 * @param string $prompt User prompt.
	 * @param array  $ctx Runtime context.
	 * @return array<int,array<string,mixed>> Ranked candidate rows.
	 */
	public function match_prompt( $prompt, array $ctx = array() ) {
		$prompt_lc = function_exists( 'mb_strtolower' )
			? mb_strtolower( wp_strip_all_tags( (string) $prompt ), 'UTF-8' )
			: strtolower( wp_strip_all_tags( (string) $prompt ) );
		if ( '' === trim( $prompt_lc ) ) {
			return array();
		}

		$candidates = array();
		foreach ( $this->all( $ctx ) as $tool ) {
			$score = 0;
			foreach ( (array) $tool['intent_keywords'] as $kw ) {
				$kw = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $kw, 'UTF-8' ) : strtolower( (string) $kw );
				if ( '' !== $kw && false !== strpos( $prompt_lc, $kw ) ) {
					$score++;
				}
			}
			if ( $score <= 0 ) {
				continue;
			}
			$candidates[] = array(
				// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — carry execution metadata into TwinBrain tool_decided/tool_done for MVP visibility.
				'skill_slug'        => (string) $tool['slug'],
				'tool_slug'         => (string) $tool['slug'],
				'artifact_type'     => (string) $tool['artifact_type'],
				'execution'         => (string) $tool['execution'],
				'tool_class'        => (string) $tool['tool_class'],
				'plan_min'          => (string) $tool['plan_min'],
				'capability'        => (string) $tool['capability'],
				'needs_approval'    => ! empty( $tool['needs_approval'] ),
				'parameters_schema' => isset( $tool['parameters_schema'] ) && is_array( $tool['parameters_schema'] ) ? (array) $tool['parameters_schema'] : array(),
				'score'             => min( 1.0, 0.45 + ( $score * 0.15 ) ),
				'reason'            => 'twinweb_catalog_keyword_x' . $score,
			);
		}

		usort( $candidates, static function ( $left, $right ) {
			$ls = isset( $left['score'] ) ? (float) $left['score'] : 0.0;
			$rs = isset( $right['score'] ) ? (float) $right['score'] : 0.0;
			if ( $ls === $rs ) {
				return 0;
			}
			return ( $rs > $ls ) ? 1 : -1;
		} );

		return array_slice( $candidates, 0, 3 );
	}

	/**
	 * Built-in tool contracts. These are metadata only; execution is wired in
	 * later waves through BizCity_Twin_Tool_Registry and async artifact jobs.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function builtins() {
		return array(
			'create_doc' => array(
				'label'           => 'Tạo tài liệu',
				'description'     => 'Tạo project tài liệu trong BizCity Doc Studio và mở Canvas để sinh/export nội dung.',
				'artifact_type'   => 'document',
				'execution'       => 'sync_preview',
				'tool_class'      => 'producer',
				'plan_min'        => 'free',
				'capability'      => 'artifact.document.create',
				'needs_approval'  => false,
				'intent_keywords' => array( 'tạo doc', 'tạo tài liệu', 'document', 'docx', 'bài viết dài', 'soạn tài liệu' ),
				'parameters_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'prompt'         => array( 'type' => 'string', 'description' => 'Nội dung/yêu cầu tài liệu.' ),
						'title'          => array( 'type' => 'string', 'description' => 'Tiêu đề tài liệu.' ),
						'attachment_ids' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
						// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — catalog exposes the same structured spec handoff as runtime adapters.
						'tool_spec'      => array( 'type' => 'object', 'description' => 'Structured request/thread/file context packet.' ),
					),
					'required'   => array( 'prompt' ),
				),
			),
			'generate_image' => array(
				'label'           => 'Tạo ảnh',
				'description'     => 'Tạo ảnh từ prompt và ảnh tham chiếu, qua BizCity gateway/client wrapper.',
				'artifact_type'   => 'image',
				'execution'       => 'async',
				'tool_class'      => 'producer',
				'plan_min'        => 'plus',
				'capability'      => 'artifact.image.generate',
				'needs_approval'  => false,
				'intent_keywords' => array( 'tạo ảnh', 'vẽ ảnh', 'generate image', 'poster', 'thiết kế ảnh', 'render ảnh', 'logo' ),
				'parameters_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'prompt'       => array( 'type' => 'string', 'description' => 'Mô tả ảnh cần tạo.' ),
						'aspect_ratio' => array( 'type' => 'string', 'enum' => array( '1:1', '4:3', '3:4', '16:9', '9:16' ) ),
						'input_images' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'description' => 'Attachment IDs thuộc user hiện tại.' ),
						// [2026-07-20 Johnny Chu] PHASE-1-TWIN-GPT-AGENT-TOOLS — image artifacts need the same trace packet as doc/slide/xlsx.
						'tool_spec'    => array( 'type' => 'object', 'description' => 'Structured request/thread/file context packet.' ),
					),
					'required'   => array( 'prompt' ),
				),
			),
			'render_html' => array(
				'label'           => 'HTML preview',
				'description'     => 'Tạo HTML/CSS an toàn để xem trong sandbox artifact canvas.',
				'artifact_type'   => 'html',
				'execution'       => 'sync_preview',
				'tool_class'      => 'producer',
				'plan_min'        => 'free',
				'capability'      => 'artifact.html.render',
				'needs_approval'  => false,
				'intent_keywords' => array( 'html', 'landing page', 'giao diện', 'ui component', 'web page', 'render html' ),
				'parameters_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'code'  => array( 'type' => 'string', 'description' => 'Complete HTML document or fragment.' ),
						'title' => array( 'type' => 'string' ),
					),
					'required'   => array( 'code' ),
				),
			),
			'generate_chart' => array(
				'label'           => 'Biểu đồ',
				'description'     => 'Tạo dữ liệu biểu đồ có cấu trúc để FE render rich card/canvas.',
				'artifact_type'   => 'chart',
				'execution'       => 'sync_preview',
				'tool_class'      => 'producer',
				'plan_min'        => 'free',
				'capability'      => 'artifact.chart.render',
				'needs_approval'  => false,
				'intent_keywords' => array( 'biểu đồ', 'chart', 'graph', 'visualize', 'thống kê', 'so sánh số liệu' ),
				'parameters_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'title'     => array( 'type' => 'string' ),
						'type'      => array( 'type' => 'string', 'enum' => array( 'bar', 'line', 'pie' ) ),
						'data'      => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
						'xKey'      => array( 'type' => 'string' ),
						'dataKeys'  => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					),
					'required'   => array( 'title', 'type', 'data', 'xKey', 'dataKeys' ),
				),
			),
			'create_xlsx' => array(
				'label'           => 'Tạo Excel',
				'description'     => 'Tạo project bảng tính trong BizCity Doc Studio; export XLSX chạy trong owner app/canvas.',
				'artifact_type'   => 'xlsx',
				'execution'       => 'sync_preview',
				'tool_class'      => 'producer',
				'plan_min'        => 'plus',
				'capability'      => 'artifact.xlsx.create',
				'needs_approval'  => false,
				'intent_keywords' => array( 'excel', 'xlsx', 'bảng tính', 'spreadsheet', 'file excel', 'xuất bảng' ),
				'parameters_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'prompt'         => array( 'type' => 'string', 'description' => 'Yêu cầu bảng tính gốc.' ),
						'title'          => array( 'type' => 'string' ),
						'attachment_ids' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
						'sheets'         => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
						// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — carry multimodal/thread spec into spreadsheet skeleton generation.
						'tool_spec'      => array( 'type' => 'object', 'description' => 'Structured request/thread/file context packet.' ),
					),
					'required'   => array( 'title' ),
				),
			),
			'create_pptx' => array(
				'label'           => 'Tạo slide',
				'description'     => 'Tạo project slide trong BizCity Doc Studio; export PPTX chạy trong owner app/canvas.',
				'artifact_type'   => 'pptx',
				'execution'       => 'sync_preview',
				'tool_class'      => 'producer',
				'plan_min'        => 'pro',
				'capability'      => 'artifact.pptx.create',
				'needs_approval'  => false,
				'intent_keywords' => array( 'slide', 'pptx', 'powerpoint', 'presentation', 'deck', 'bài thuyết trình' ),
				'parameters_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'prompt'         => array( 'type' => 'string', 'description' => 'Yêu cầu slide gốc.' ),
						'title'          => array( 'type' => 'string' ),
						'attachment_ids' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
						'slides'         => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
						'assets'         => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
						// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — carry multimodal/thread spec into presentation skeleton generation.
						'tool_spec'      => array( 'type' => 'object', 'description' => 'Structured request/thread/file context packet.' ),
					),
					'required'   => array( 'title' ),
				),
			),
			'create_mindmap' => array(
				'label'           => 'Tạo mindmap',
				'description'     => 'Tạo project mindmap trong BizCity Doc Studio và mở Canvas để chỉnh/sinh tiếp.',
				'artifact_type'   => 'mindmap',
				'execution'       => 'sync_preview',
				'tool_class'      => 'producer',
				'plan_min'        => 'free',
				'capability'      => 'artifact.mindmap.create',
				'needs_approval'  => false,
				'intent_keywords' => array( 'mindmap', 'mind map', 'sơ đồ tư duy', 'bản đồ tư duy', 'tạo sơ đồ' ),
				'parameters_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'prompt'         => array( 'type' => 'string', 'description' => 'Chủ đề/nội dung mindmap.' ),
						'title'          => array( 'type' => 'string' ),
						'attachment_ids' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
						// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — carry multimodal/thread spec into mindmap skeleton generation.
						'tool_spec'      => array( 'type' => 'object', 'description' => 'Structured request/thread/file context packet.' ),
					),
					'required'   => array( 'prompt' ),
				),
			),
		);
	}

	/**
	 * Normalize and sanitize one catalog row.
	 *
	 * @param string $slug Candidate slug.
	 * @param array  $row Raw row.
	 * @return array<string,mixed>
	 */
	private function normalize_tool( $slug, array $row ) {
		$slug = sanitize_key( isset( $row['slug'] ) ? (string) $row['slug'] : (string) $slug );
		$execution = sanitize_key( (string) ( $row['execution'] ?? 'async' ) );
		if ( ! in_array( $execution, array( 'sync_preview', 'async', 'frontend_interaction' ), true ) ) {
			$execution = 'async';
		}

		$tool_class = sanitize_key( (string) ( $row['tool_class'] ?? 'producer' ) );
		if ( ! in_array( $tool_class, array( 'producer', 'distributor', 'retriever' ), true ) ) {
			$tool_class = 'producer';
		}

		return array(
			'slug'              => $slug,
			'label'             => sanitize_text_field( (string) ( $row['label'] ?? $slug ) ),
			'description'       => sanitize_text_field( (string) ( $row['description'] ?? '' ) ),
			'artifact_type'     => sanitize_key( (string) ( $row['artifact_type'] ?? '' ) ),
			'execution'         => $execution,
			'tool_class'        => $tool_class,
			'plan_min'          => sanitize_key( (string) ( $row['plan_min'] ?? 'free' ) ),
			'capability'        => sanitize_key( (string) ( $row['capability'] ?? '' ) ),
			'needs_approval'    => ! empty( $row['needs_approval'] ),
			'intent_keywords'   => array_values( array_filter( array_map( 'strval', (array) ( $row['intent_keywords'] ?? array() ) ) ) ),
			'parameters_schema' => isset( $row['parameters_schema'] ) && is_array( $row['parameters_schema'] ) ? $row['parameters_schema'] : array( 'type' => 'object', 'properties' => array() ),
		);
	}
}