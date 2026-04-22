<?php
/**
 * BZDoc Canvas Bridge — Integration with Twin AI Canvas/Intent system.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BZDoc_Canvas_Bridge {

	/**
	 * Register canvas handlers for studio output.
	 */
	public static function register_handlers( array $handlers ): array {
		$handlers['doc_generate'] = [
			'class'    => __CLASS__,
			'method'   => 'handle_generate',
			'doc_type' => [ 'document', 'presentation', 'spreadsheet' ],
		];
		$handlers['doc_edit'] = [
			'class'  => __CLASS__,
			'method' => 'handle_edit',
		];
		return $handlers;
	}

	/**
	 * Handle document generation from Intent Engine.
	 *
	 * @param array $input Tool input from pipeline.
	 * @param array $context Pipeline context.
	 * @return array Tool output envelope (BizCity standard).
	 */
	public static function handle_generate( array $input, array $context = [] ): array {
		$doc_type  = $input['doc_type'] ?? 'document';
		$topic     = $input['topic'] ?? $input['prompt'] ?? '';
		$template  = $input['template_name'] ?? 'blank';
		$theme     = $input['theme_name'] ?? 'modern';

		if ( empty( $topic ) ) {
			return [
				'success'        => false,
				'error'          => 'Topic is required.',
				'missing_fields' => [ 'topic' ],
			];
		}

		// Build a fake WP_REST_Request to reuse REST API logic
		$request = new \WP_REST_Request( 'POST' );
		$request->set_param( 'doc_type', $doc_type );
		$request->set_param( 'topic', $topic );
		$request->set_param( 'template_name', $template );
		$request->set_param( 'theme_name', $theme );
		$request->set_param( 'slide_count', $input['slide_count'] ?? 10 );

		$result = BZDoc_Rest_API::handle_generate( $request );

		if ( is_wp_error( $result ) ) {
			return [
				'success' => false,
				'error'   => $result->get_error_message(),
			];
		}

		$response_data = $result->get_data();
		$schema = $response_data['data'] ?? $response_data;

		return [
			'success' => true,
			'type'    => $doc_type,
			'data'    => [
				'schema'   => $schema,
				'doc_type' => $doc_type,
			],
			'message' => self::get_success_message( $doc_type, $schema ),
			'studio_outputs' => [
				[
					'type'    => 'doc_preview',
					'content' => $schema,
					'actions' => [ 'download', 'edit', 'save' ],
				],
			],
		];
	}

	/**
	 * Handle document edit from Intent Engine.
	 */
	public static function handle_edit( array $input, array $context = [] ): array {
		$doc_id      = $input['doc_id'] ?? 0;
		$instruction = $input['instruction'] ?? '';

		if ( empty( $instruction ) ) {
			return [
				'success'        => false,
				'error'          => 'Edit instruction is required.',
				'missing_fields' => [ 'instruction' ],
			];
		}

		// Load existing document if we have doc_id
		$current_json = $input['current_json'] ?? null;
		if ( ! $current_json && $doc_id > 0 ) {
			global $wpdb;
			$table = $wpdb->prefix . 'bzdoc_documents';
			$row   = $wpdb->get_row( $wpdb->prepare(
				"SELECT schema_json, doc_type, theme_name FROM {$table} WHERE id = %d AND user_id = %d",
				$doc_id, get_current_user_id()
			) );
			if ( $row ) {
				$current_json = json_decode( $row->schema_json, true );
				$input['doc_type']   = $row->doc_type;
				$input['theme_name'] = $row->theme_name;
			}
		}

		if ( empty( $current_json ) ) {
			return [
				'success' => false,
				'error'   => 'No document to edit.',
			];
		}

		$request = new \WP_REST_Request( 'POST' );
		$request->set_param( 'instruction', $instruction );
		$request->set_param( 'current_json', $current_json );
		$request->set_param( 'doc_type', $input['doc_type'] ?? 'document' );
		$request->set_param( 'theme_name', $input['theme_name'] ?? 'modern' );

		$result = BZDoc_Rest_API::handle_edit( $request );

		if ( is_wp_error( $result ) ) {
			return [
				'success' => false,
				'error'   => $result->get_error_message(),
			];
		}

		$response_data = $result->get_data();
		$edit_schema   = $response_data['data'] ?? $response_data;

		return [
			'success' => true,
			'type'    => $input['doc_type'] ?? 'document',
			'data'    => [
				'schema' => $edit_schema,
			],
			'message' => 'Đã cập nhật tài liệu theo yêu cầu.',
		];
	}

	private static function get_success_message( string $doc_type, $schema ): string {
		switch ( $doc_type ) {
			case 'presentation':
				$count = count( $schema['slides'] ?? [] );
				$title = $schema['presentation_title'] ?? 'Presentation';
				return "Đã tạo bài thuyết trình \"{$title}\" với {$count} slides. Bạn có thể download PPTX/PDF.";

			case 'spreadsheet':
				return 'Đã tạo bảng tính. Bạn có thể download XLSX/CSV.';

			default:
				$title = $schema['metadata']['title'] ?? 'Document';
				return "Đã tạo tài liệu \"{$title}\". Bạn có thể download DOCX/PDF.";
		}
	}
}
