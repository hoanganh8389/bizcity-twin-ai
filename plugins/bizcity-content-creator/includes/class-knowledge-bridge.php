<?php
/**
 * BZCC Knowledge Bridge — Connects Content Creator with Twin AI Knowledge + Memory systems.
 *
 * Phase 3.2 Sprint 0: Injects knowledge context (RAG + FAQ + semantic) and user memory
 * into Content Creator prompts for personalized, knowledge-aware content generation.
 *
 * @package    Bizcity_Content_Creator
 * @subpackage Includes
 * @since      0.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BZCC_Knowledge_Bridge {

	/**
	 * Build knowledge + memory context for a file generation request.
	 *
	 * @param array  $form_data  Cleaned form data.
	 * @param object $template   Template object (with smart_input config in settings).
	 * @param int    $user_id    Current user ID.
	 * @return array { knowledge_context: string, memory_context: string, sources: array, tokens_used: int }
	 */
	public static function build( array $form_data, object $template, int $user_id ): array {
		$result = [
			'knowledge_context' => '',
			'memory_context'    => '',
			'sources'           => [],
			'tokens_used'       => 0,
		];

		$smart_config = self::get_smart_config( $template );
		if ( empty( $smart_config ) ) {
			return $result;
		}

		// ── Knowledge RAG ──
		if ( ! empty( $smart_config['enable_knowledge'] ) && class_exists( 'BizCity_Knowledge_Context_API' ) ) {
			$character_id = (int) ( $smart_config['character_id'] ?? 0 );
			if ( ! $character_id ) {
				$character_id = self::get_default_character_id( $user_id );
			}

			if ( $character_id ) {
				$query = self::build_query_from_form( $form_data, $template, $smart_config );

				error_log( '[BZCC-KB] Knowledge query: "' . mb_substr( $query, 0, 200 ) . '" | char=' . $character_id );

				try {
					$ctx = BizCity_Knowledge_Context_API::build_context( $character_id, $query, [
						'max_tokens'       => (int) ( $smart_config['knowledge_max_tokens'] ?? 2000 ),
						'include_faq'      => true,
						'include_semantic' => true,
						'include_tags'     => true,
					] );

					$result['knowledge_context'] = $ctx['context'] ?? '';
					$result['sources']           = $ctx['sources'] ?? [];
					$result['tokens_used']      += $ctx['tokens_used'] ?? 0;
				} catch ( \Throwable $e ) {
					error_log( '[BZCC-KB] Knowledge build_context failed: ' . $e->getMessage() );
				}

				error_log( '[BZCC-KB] Knowledge result: ' . mb_strlen( $result['knowledge_context'] ) . ' chars, ' . count( $result['sources'] ) . ' sources' );
			}
		}

		// ── User Memory ──
		if ( ! empty( $smart_config['enable_user_memory'] ) && class_exists( 'BizCity_User_Memory' ) ) {
			$result['memory_context'] = BizCity_User_Memory::build_compact_memory( $user_id ) ?: '';

			if ( $result['memory_context'] ) {
				error_log( '[BZCC-KB] User memory: ' . mb_strlen( $result['memory_context'] ) . ' chars' );
			}
		}

		return $result;
	}

	/**
	 * Build search query from form data, prioritizing topic-related fields.
	 */
	private static function build_query_from_form( array $form_data, object $template, array $smart_config ): string {
		// Use explicitly configured query fields if available
		$query_fields = $smart_config['knowledge_query_fields'] ?? [];

		if ( ! empty( $query_fields ) ) {
			$parts = [];
			foreach ( $query_fields as $f ) {
				if ( ! empty( $form_data[ $f ] ) && is_string( $form_data[ $f ] ) ) {
					$parts[] = $form_data[ $f ];
				}
			}
			if ( ! empty( $parts ) ) {
				return implode( '. ', $parts );
			}
		}

		// Auto-detect: priority fields that likely contain the topic
		$priority_fields = [
			'topic', 'channel_topic', 'video_topic', 'main_keyword',
			'subject', 'title', 'niche', 'product', 'brand',
		];

		$parts = [];
		foreach ( $priority_fields as $f ) {
			if ( ! empty( $form_data[ $f ] ) && is_string( $form_data[ $f ] ) ) {
				$parts[] = $form_data[ $f ];
			}
		}

		// Fallback: short text fields
		if ( empty( $parts ) ) {
			foreach ( $form_data as $k => $v ) {
				if ( is_string( $v ) && mb_strlen( $v ) >= 4 && mb_strlen( $v ) <= 200 ) {
					// Skip internal fields
					if ( str_ends_with( $k, '_attachment_id' ) || str_ends_with( $k, '_url' ) || $k === 'template_id' ) {
						continue;
					}
					$parts[] = $v;
				}
			}
		}

		return implode( '. ', array_slice( $parts, 0, 3 ) );
	}

	/**
	 * Get default character_id from site settings or first available character.
	 */
	private static function get_default_character_id( int $user_id ): int {
		if ( ! class_exists( 'BizCity_Knowledge_Database' ) ) {
			return 0;
		}

		// Site default
		$default_id = (int) get_option( 'bizcity_default_character_id', 0 );
		if ( $default_id ) {
			return $default_id;
		}

		// Fallback: first character
		$db         = BizCity_Knowledge_Database::instance();
		$characters = $db->get_characters( [ 'limit' => 1 ] );

		return ! empty( $characters ) ? (int) $characters[0]->id : 0;
	}

	/**
	 * Extract smart_input config from template settings.
	 */
	public static function get_smart_config( object $template ): array {
		$settings = $template->settings ?? '';
		if ( is_string( $settings ) ) {
			$settings = json_decode( $settings, true ) ?: [];
		}
		if ( ! is_array( $settings ) ) {
			$settings = [];
		}

		return $settings['smart_input'] ?? [];
	}
}
