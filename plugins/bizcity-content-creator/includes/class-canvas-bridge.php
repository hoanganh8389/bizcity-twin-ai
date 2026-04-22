<?php
/**
 * Canvas Bridge — Content Creator ↔ Canvas Adapter integration.
 *
 * Receives dispatch from BizCity_Canvas_Adapter, resolves template,
 * creates file record with pre-filled form_data, returns launch info.
 *
 * KHÔNG chứa execution logic. KHÔNG gọi LLM.
 * Chỉ tạo file record (status=pending) + trả URL.
 *
 * @package BizCity\ContentCreator
 * @since   0.1.27
 * @see     PHASE-1.20-CANVAS-ADAPTER.md
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BZCC_Canvas_Bridge {

	/**
	 * Register Canvas handlers for Content Creator tools.
	 *
	 * @param array $handlers Existing handlers.
	 * @return array
	 */
	public static function register_handlers( array $handlers ): array {
		$handlers['content_creator_execute'] = [ __CLASS__, 'handle_dispatch' ];
		$handlers['content_creator']         = [ __CLASS__, 'handle_dispatch' ];
		return $handlers;
	}

	/**
	 * Handle Canvas dispatch — resolve template, create file, return launch info.
	 *
	 * @param string $tool_id Tool identifier.
	 * @param array  $params  Entities/slots from intent engine.
	 * @param array  $context {session_id, user_id, conv_id, message, entities}
	 * @return array Job data for Canvas Adapter.
	 */
	public static function handle_dispatch( string $tool_id, array $params, array $context ): array {
		$user_id = (int) ( $context['user_id'] ?? get_current_user_id() );
		$message = $context['message'] ?? '';
		$entities = $context['entities'] ?? $params;

		// ── 1. Resolve template ──
		$template = self::resolve_template( $entities, $message );

		if ( ! $template ) {
			// Fallback: open browse page (user picks template manually)
			return self::generic_handoff( $context );
		}

		// ── 2. Build form_data from entities → template form_fields ──
		$form_fields = json_decode( $template->form_fields, true ) ?: [];
		$form_data   = self::map_entities_to_form( $entities, $form_fields, $message );

		// ── 3. Build title ──
		$title = self::build_title( $template, $form_data, $message );

		// ── 4. Create file record (status=pending → generate_file() accepts it) ──
		$file_id = BZCC_File_Manager::insert( [
			'template_id' => (int) $template->id,
			'user_id'     => $user_id,
			'title'       => $title,
			'form_data'   => wp_json_encode( $form_data ),
			'status'      => 'pending',
		] );

		if ( ! $file_id ) {
			return [
				'error' => 'Không thể tạo file record.',
			];
		}

		// ── 5. Build launch URL ──
		$launch_url = home_url( '/creator/result/' . $file_id . '/' );

		error_log( '[BZCC-Bridge] dispatch: template=' . $template->slug . ' file=' . $file_id . ' url=' . $launch_url );

		return [
			'workshop'       => 'content-creator',
			'workshop_label' => 'Content Creator',
			'tool_type'      => 'content',
			'title'          => $title,
			'launch_url'     => $launch_url,
			'sse_endpoint'   => rest_url( 'bzcc/v1/file/' . $file_id . '/stream' ),
			'auto_execute'   => false, // User phải xác nhận trước khi generate
			'prefill_data'   => $form_data,
			'reply'          => sprintf(
				'Đã chuẩn bị "%s" với template **%s**. Mở Canvas để xem và xác nhận tạo nội dung.',
				$title,
				$template->title
			),
			'job_data'       => [
				'file_id'     => $file_id,
				'template_id' => (int) $template->id,
			],
		];
	}

	/**
	 * Generic handoff — no template matched, open browse page.
	 *
	 * @param array $context Dispatch context.
	 * @return array
	 */
	private static function generic_handoff( array $context ): array {
		return [
			'workshop'       => 'content-creator',
			'workshop_label' => 'Content Creator',
			'tool_type'      => 'content',
			'title'          => 'Content Creator',
			'launch_url'     => home_url( '/creator/' ),
			'auto_execute'   => false,
			'reply'          => 'Mở Content Creator — hãy chọn template phù hợp.',
			'job_data'       => [],
		];
	}

	/**
	 * Resolve template from entities or message matching.
	 *
	 * Priority:
	 *   1. entities['template_id'] → direct lookup
	 *   2. Message regex match against template tags (same as bzcc_get_intent_patterns)
	 *   3. null (no match)
	 *
	 * @param array  $entities Extracted entities.
	 * @param string $message  User's original message.
	 * @return object|null Template object or null.
	 */
	private static function resolve_template( array $entities, string $message ) {
		// Priority 1: direct template_id from entities
		if ( ! empty( $entities['template_id'] ) ) {
			$tpl = BZCC_Template_Manager::get_by_id( (int) $entities['template_id'] );
			if ( $tpl ) {
				return $tpl;
			}
		}

		// Priority 2: match message against template tags
		if ( empty( $message ) ) {
			return null;
		}

		$templates = BZCC_Template_Manager::get_all_active();
		$best_match = null;
		$best_score = 0;

		foreach ( $templates as $tpl ) {
			$keywords = array_filter( array_map( 'trim', explode( ',', $tpl->tags ?? '' ) ) );
			if ( empty( $keywords ) ) {
				continue;
			}

			$score = 0;
			foreach ( $keywords as $kw ) {
				if ( mb_stripos( $message, $kw ) !== false ) {
					$score++;
				}
			}

			if ( $score > $best_score ) {
				$best_score = $score;
				$best_match = $tpl;
			}
		}

		return $best_match;
	}

	/**
	 * Map extracted entities to template form_fields.
	 *
	 * Matches entity keys to form field slugs. Also captures the original
	 * message as a fallback field for LLM context.
	 *
	 * @param array  $entities    Extracted entities.
	 * @param array  $form_fields Template form_fields (decoded JSON).
	 * @param string $message     Original user message.
	 * @return array Mapped form_data.
	 */
	private static function map_entities_to_form( array $entities, array $form_fields, string $message ): array {
		$form_data = [];
		$field_slugs = [];

		foreach ( $form_fields as $field ) {
			if ( ! empty( $field['slug'] ) ) {
				$field_slugs[] = $field['slug'];
			}
		}

		// Direct match: entity key == form field slug
		foreach ( $entities as $key => $val ) {
			if ( in_array( $key, $field_slugs, true ) && $val !== '' && $val !== null ) {
				$form_data[ $key ] = $val;
			}
		}

		// If very few fields matched, store original message for LLM context
		if ( count( $form_data ) < 2 && ! empty( $message ) ) {
			// Find a text-type field that is still empty and use the message
			foreach ( $form_fields as $field ) {
				$slug = $field['slug'] ?? '';
				$type = $field['type'] ?? 'text';
				if ( $slug && ! isset( $form_data[ $slug ] ) && in_array( $type, [ 'text', 'textarea', 'long_text' ], true ) ) {
					$form_data[ $slug ] = $message;
					break;
				}
			}
		}

		return $form_data;
	}

	/**
	 * Build a descriptive title for the file.
	 *
	 * @param object $template  Template object.
	 * @param array  $form_data Pre-filled form data.
	 * @param string $message   Original user message.
	 * @return string
	 */
	private static function build_title( $template, array $form_data, string $message ): string {
		// Use first non-empty form value as context
		$context_hint = '';
		foreach ( $form_data as $val ) {
			if ( is_string( $val ) && mb_strlen( $val ) > 3 ) {
				$context_hint = mb_substr( $val, 0, 60 );
				break;
			}
		}

		if ( $context_hint ) {
			return $template->title . ' — ' . $context_hint;
		}

		// Fallback: template title + message snippet
		if ( mb_strlen( $message ) > 5 ) {
			return $template->title . ' — ' . mb_substr( $message, 0, 60 );
		}

		return $template->title;
	}

	/**
	 * Inline fallback — khi Canvas bị tắt hoặc dispatch fail.
	 *
	 * Trả về link mở Content Creator thay vì crash.
	 * Tool_Run::execute() gọi callback này khi Phase 2b inline.
	 *
	 * @param array $params  Resolved params {template_id, form_data}.
	 * @param array $context Execution context.
	 * @return array Tool result.
	 */
	public static function handle_inline_fallback( array $params, array $context = [] ): array {
		$template_id = (int) ( $params['template_id'] ?? 0 );
		$url         = $template_id
			? home_url( '/creator/?template=' . $template_id )
			: home_url( '/creator/' );

		return [
			'success' => true,
			'content' => sprintf( 'Mở Content Creator để tạo nội dung: %s', $url ),
			'data'    => [
				'content'    => sprintf( '[Mở Content Creator](%s)', $url ),
				'action_url' => $url,
			],
		];
	}

	/**
	 * Select template — help user choose a template.
	 *
	 * @param array $params  {template_choice: string}.
	 * @param array $context Execution context.
	 * @return array Tool result with template suggestions.
	 */
	public static function handle_select_template( array $params, array $context = [] ): array {
		$choice    = $params['template_choice'] ?? '';
		$templates = class_exists( 'BZCC_Template_Manager' )
			? BZCC_Template_Manager::get_all_active()
			: [];

		if ( empty( $templates ) ) {
			return [
				'success' => false,
				'content' => 'Chưa có template nào. Vui lòng tạo template trước.',
				'data'    => [],
			];
		}

		// Simple keyword matching
		$matches = [];
		foreach ( $templates as $tpl ) {
			$haystack = mb_strtolower( $tpl->title . ' ' . ( $tpl->tags ?? '' ) );
			if ( empty( $choice ) || mb_stripos( $haystack, mb_strtolower( $choice ) ) !== false ) {
				$matches[] = [
					'id'    => (int) $tpl->id,
					'title' => $tpl->title,
					'slug'  => $tpl->slug,
				];
			}
		}

		$list = array_slice( $matches, 0, 10 );
		$text = "Các template phù hợp:\n";
		foreach ( $list as $m ) {
			$text .= sprintf( "- **%s** (ID: %d)\n", $m['title'], $m['id'] );
		}

		return [
			'success' => true,
			'content' => $text,
			'data'    => [ 'templates' => $list ],
		];
	}
}
