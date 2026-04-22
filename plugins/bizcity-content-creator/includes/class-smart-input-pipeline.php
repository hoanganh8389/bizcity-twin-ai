<?php
/**
 * BZCC Smart Input Pipeline — Orchestrates the 4-stage smart input processing.
 *
 * Phase 3.2: Collect → Preprocess (Vision/File) → Knowledge Enrich → Assemble
 *
 * @package    Bizcity_Content_Creator
 * @subpackage Includes
 * @since      0.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BZCC_Smart_Input_Pipeline {

	/**
	 * Run the full Smart Input pipeline for a file generation.
	 *
	 * Call this after {{field}} substitution and before building the LLM prompt.
	 *
	 * @param array  $form_data    Cleaned form data.
	 * @param object $template     Template object.
	 * @param int    $user_id      Current user ID.
	 * @return array {
	 *   smart_context:       string   — Unified context block to inject into prompts.
	 *   smart_input_result:  array    — Full result for storage/trace.
	 *   has_content:         bool     — Whether any smart input was generated.
	 * }
	 */
	public static function run( array $form_data, object $template, int $user_id ): array {
		$smart_config = BZCC_Knowledge_Bridge::get_smart_config( $template );
		$form_fields  = json_decode( $template->form_fields ?? '[]', true ) ?: [];

		$result = [
			'smart_context'      => '',
			'smart_input_result' => [
				'vision'       => [],
				'files'        => [],
				'knowledge'    => [],
				'memory'       => '',
				'total_tokens' => 0,
				'processed_at' => current_time( 'c' ),
			],
			'has_content' => false,
		];

		$parts = [];

		// ── Stage 1: Vision Processing (Sprint 1) ──
		$vision_results = BZCC_Vision_Processor::process(
			$form_data,
			$form_fields,
			(int) ( $smart_config['vision_max_tokens'] ?? 0 )
		);

		if ( ! empty( $vision_results ) ) {
			foreach ( $vision_results as $slug => $description ) {
				$field_label = self::find_field_label( $form_fields, $slug );
				$parts[]     = "## Nội dung ảnh: {$field_label}\n{$description}";
			}
			$result['smart_input_result']['vision'] = $vision_results;
		}

		// ── Stage 2: File Processing (Sprint 2) ──
		$file_results = BZCC_File_Processor::process( $form_data, $form_fields );

		if ( ! empty( $file_results ) ) {
			foreach ( $file_results as $slug => $content ) {
				$field_label = self::find_field_label( $form_fields, $slug );
				$parts[]     = "## Dữ liệu file: {$field_label}\n{$content}";
			}
			$result['smart_input_result']['files'] = array_map( function ( $v ) {
				return mb_substr( $v, 0, 200 ) . ( mb_strlen( $v ) > 200 ? '...' : '' );
			}, $file_results );
		}

		// ── Stage 3: Knowledge Enrichment (Sprint 0) ──
		$kb_result = BZCC_Knowledge_Bridge::build( $form_data, $template, $user_id );

		if ( ! empty( $kb_result['knowledge_context'] ) ) {
			$parts[] = "## Kiến thức tham khảo\n" . $kb_result['knowledge_context'];
			$result['smart_input_result']['knowledge'] = [
				'context' => mb_substr( $kb_result['knowledge_context'], 0, 500 ) . '...',
				'sources' => $kb_result['sources'],
				'tokens'  => $kb_result['tokens_used'],
			];
			$result['smart_input_result']['total_tokens'] += $kb_result['tokens_used'];
		}

		if ( ! empty( $kb_result['memory_context'] ) ) {
			$parts[] = "## Thông tin người dùng\n" . $kb_result['memory_context'];
			$result['smart_input_result']['memory'] = mb_substr( $kb_result['memory_context'], 0, 300 ) . '...';
		}

		// ── Stage 4: Assembly ──
		if ( ! empty( $parts ) ) {
			$result['smart_context'] = implode( "\n\n---\n\n", $parts );
			$result['has_content']   = true;

			error_log( '[BZCC-SmartInput] Assembled ' . count( $parts ) . ' parts, ' . mb_strlen( $result['smart_context'] ) . ' chars total' );
		}

		return $result;
	}

	/**
	 * Inject smart context into prompts.
	 *
	 * Replaces {{__smart_context__}} placeholder or appends to system_prompt.
	 *
	 * @param string $system_prompt   System prompt (already had {{field}} substitution).
	 * @param string $outline_prompt  Outline prompt.
	 * @param string $chunk_prompt    Chunk prompt.
	 * @param string $smart_context   The assembled smart context.
	 * @param string $original_system Original system prompt template (for placeholder detection).
	 * @return array [ system_prompt, outline_prompt, chunk_prompt ]
	 */
	public static function inject( string $system_prompt, string $outline_prompt, string $chunk_prompt, string $smart_context, string $original_system = '' ): array {
		if ( empty( $smart_context ) ) {
			return [ $system_prompt, $outline_prompt, $chunk_prompt ];
		}

		$placeholder = '{{__smart_context__}}';

		// Check if placeholder exists in original template
		$has_placeholder = strpos( $original_system, $placeholder ) !== false
			|| strpos( $system_prompt, $placeholder ) !== false
			|| strpos( $outline_prompt, $placeholder ) !== false;

		if ( $has_placeholder ) {
			$system_prompt  = str_replace( $placeholder, $smart_context, $system_prompt );
			$outline_prompt = str_replace( $placeholder, $smart_context, $outline_prompt );
			$chunk_prompt   = str_replace( $placeholder, $smart_context, $chunk_prompt );
		} else {
			// Fallback: append to system_prompt
			$system_prompt .= "\n\n---\n\nDỮ LIỆU THAM KHẢO (Smart Input):\n\n" . $smart_context;
		}

		return [ $system_prompt, $outline_prompt, $chunk_prompt ];
	}

	/**
	 * Find field label by slug.
	 */
	private static function find_field_label( array $form_fields, string $slug ): string {
		foreach ( $form_fields as $field ) {
			if ( ( $field['slug'] ?? '' ) === $slug ) {
				return $field['label'] ?? $slug;
			}
		}
		return $slug;
	}
}
