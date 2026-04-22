<?php
/**
 * BZCC Vision Processor — Process image fields through Vision API.
 *
 * Phase 3.2 Sprint 1: When admin enables Vision on an image field,
 * the uploaded image is sent to a Vision-capable LLM to extract text/descriptions.
 *
 * @package    Bizcity_Content_Creator
 * @subpackage Includes
 * @since      0.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BZCC_Vision_Processor {

	/** Default model for Vision processing (cost-effective). */
	const DEFAULT_MODEL = 'gpt-4o-mini';

	/** Default max tokens per Vision response. */
	const DEFAULT_MAX_TOKENS = 1500;

	/** Default Vision prompt when none specified. */
	const DEFAULT_PROMPT = 'Mô tả chi tiết nội dung trong ảnh này. Nếu có chữ viết, hãy trích xuất toàn bộ text.';

	/**
	 * Process all image fields that have enable_vision = true.
	 *
	 * @param array $form_data    Cleaned form data (includes {slug}_url, {slug}_attachment_id).
	 * @param array $form_fields  Template form_fields definition (array of field configs).
	 * @param int   $vision_max_tokens  Max tokens per Vision call (from smart_input config).
	 * @return array<string, string>  [ field_slug => extracted_description ]
	 */
	public static function process( array $form_data, array $form_fields, int $vision_max_tokens = 0 ): array {
		if ( ! function_exists( 'bizcity_llm_chat' ) ) {
			error_log( '[BZCC-Vision] bizcity_llm_chat not available, skipping' );
			return [];
		}

		$max_tokens = $vision_max_tokens > 0 ? $vision_max_tokens : self::DEFAULT_MAX_TOKENS;
		$results    = [];

		foreach ( $form_fields as $field ) {
			if ( ( $field['type'] ?? '' ) !== 'image' ) {
				continue;
			}
			if ( empty( $field['enable_vision'] ) ) {
				continue;
			}

			$slug = $field['slug'] ?? '';
			if ( empty( $slug ) ) {
				continue;
			}

			$url = $form_data[ $slug . '_url' ] ?? '';
			if ( empty( $url ) ) {
				continue;
			}

			// Validate URL is from our own domain (security: prevent SSRF)
			$site_host  = wp_parse_url( home_url(), PHP_URL_HOST );
			$image_host = wp_parse_url( $url, PHP_URL_HOST );
			if ( $image_host && $site_host && $image_host !== $site_host ) {
				error_log( '[BZCC-Vision] Skipping external image: ' . $url );
				continue;
			}

			$vision_prompt = $field['vision_prompt'] ?? self::DEFAULT_PROMPT;

			error_log( '[BZCC-Vision] Processing field "' . $slug . '" | url=' . mb_substr( $url, 0, 100 ) );

			$description = self::call_vision_api( $url, $vision_prompt, $max_tokens );

			if ( $description ) {
				$results[ $slug ] = $description;
				error_log( '[BZCC-Vision] Field "' . $slug . '": ' . mb_strlen( $description ) . ' chars extracted' );
			} else {
				error_log( '[BZCC-Vision] Field "' . $slug . '": Vision API returned empty' );
			}
		}

		return $results;
	}

	/**
	 * Process a single image attachment via Vision API.
	 * Used by the preview REST endpoint.
	 *
	 * @param int    $attachment_id  WP attachment ID.
	 * @param string $vision_prompt  Instruction for Vision model.
	 * @param int    $max_tokens     Max response tokens.
	 * @return array { success: bool, description: string, tokens_used: int }
	 */
	public static function process_single( int $attachment_id, string $vision_prompt = '', int $max_tokens = 0 ): array {
		if ( ! function_exists( 'bizcity_llm_chat' ) ) {
			return [ 'success' => false, 'description' => '', 'tokens_used' => 0 ];
		}

		$url = wp_get_attachment_url( $attachment_id );
		if ( ! $url ) {
			return [ 'success' => false, 'description' => '', 'tokens_used' => 0 ];
		}

		$prompt      = $vision_prompt ?: self::DEFAULT_PROMPT;
		$tokens      = $max_tokens > 0 ? $max_tokens : self::DEFAULT_MAX_TOKENS;
		$description = self::call_vision_api( $url, $prompt, $tokens );

		return [
			'success'     => ! empty( $description ),
			'description' => $description,
			'tokens_used' => self::estimate_tokens( $description ),
		];
	}

	/**
	 * Call Vision API via bizcity_llm_chat().
	 */
	private static function call_vision_api( string $image_url, string $prompt, int $max_tokens ): string {
		$messages = [
			[
				'role'    => 'user',
				'content' => [
					[ 'type' => 'text', 'text' => $prompt ],
					[ 'type' => 'image_url', 'image_url' => [ 'url' => $image_url ] ],
				],
			],
		];

		$response = bizcity_llm_chat( [
			'messages'    => $messages,
			'model'       => self::DEFAULT_MODEL,
			'max_tokens'  => $max_tokens,
			'temperature' => 0.3,
		] );

		if ( is_wp_error( $response ) ) {
			error_log( '[BZCC-Vision] API error: ' . $response->get_error_message() );
			return '';
		}

		return trim( $response['content'] ?? '' );
	}

	/**
	 * Rough token estimation.
	 */
	private static function estimate_tokens( string $text ): int {
		return max( 1, (int) ceil( mb_strlen( $text ) / 3 ) );
	}
}
