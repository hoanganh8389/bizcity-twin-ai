<?php
/**
 * Action: Edit Image (image-to-image via BizCity LLM Gateway).
 *
 * Uses BizCity_LLM_Client::generate_image() with input_images[] so client sites
 * stay standalone (R-GW-8) while the hub/provider key remains server-side.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Automation\Blocks\Actions
 * @since      PHASE-SEEDREAM-45 (2026-07-21)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Automation_Action_Edit_Image extends BizCity_Automation_Block_Base {

	const MODEL_OPTIONS = array(
		array( 'value' => 'google/gemini-3-pro-image-preview', 'label' => 'Gemini 3 Pro Image' ),
		array( 'value' => 'openai/gpt-image-1', 'label' => 'GPT Image 1' ),
	);

	const SIZE_OPTIONS = array(
		array( 'value' => '1024x1024', 'label' => '1024x1024 (vuong)' ),
		array( 'value' => '1536x1024', 'label' => '1536x1024 (ngang 3:2)' ),
		array( 'value' => '1024x1536', 'label' => '1024x1536 (doc 2:3)' ),
		array( 'value' => '1024x1792', 'label' => '1024x1792 (doc 9:16)' ),
		array( 'value' => '1792x1024', 'label' => '1792x1024 (ngang 16:9)' ),
	);

	public function id(): string   { return 'action.edit_image'; }
	public function kind(): string { return 'action'; }

	public function meta(): array {
		return array(
			'label'    => 'Chinh sua anh AI',
			'short'    => 'edit_image',
			'category' => 'ai',
			'color'    => '#a21caf',
			'icon'     => 'image-up',
			'defaults' => array(
				'label'          => 'edit_image',
				'image_url'      => '{{consume.attachment_url}}',
				'image_urls'     => '{{consume.attachment_urls}}',
				'prompt'         => '{{trigger.text}}',
				'model'          => 'google/gemini-3-pro-image-preview',
				'size'           => '1024x1024',
				'sideload_to_wp' => true,
			),
			'fields'   => array(
				array( 'name' => 'label',     'label' => 'Ten hien thi', 'type' => 'text' ),
				array( 'name' => 'image_url', 'label' => 'Anh goc (URL)', 'type' => 'text', 'hint' => '{{consume.attachment_url}} hoac {{trigger._resume.attachment_url}}' ),
				array( 'name' => 'image_urls', 'label' => 'Nhieu anh goc (JSON/CSV)', 'type' => 'textarea', 'hint' => '{{consume.attachment_urls}} de gui nhieu anh tham chieu' ),
				array( 'name' => 'prompt',    'label' => 'Yeu cau chinh sua', 'type' => 'textarea', 'hint' => 'VD: xoa vat the ben trai, lam anh sang hon, doi nen studio...' ),
				array( 'name' => 'model',     'label' => 'Model AI', 'type' => 'select', 'options' => self::MODEL_OPTIONS ),
				array( 'name' => 'size',      'label' => 'Kich thuoc', 'type' => 'select', 'options' => self::SIZE_OPTIONS ),
				array( 'name' => 'sideload_to_wp', 'label' => 'Luu ket qua vao WP Media', 'type' => 'toggle' ),
			),
		);
	}

	public function execute( array $ctx, array $data ) {
		// [2026-07-21 Johnny Chu] PHASE-SEEDREAM-45 — dedicated image-edit block backed by Branch #06 input_images.
		$content_artifact_id = class_exists( 'BizCity_Content_Artifact_Service' )
			? BizCity_Content_Artifact_Service::create_or_get_from_ctx( $ctx, array( 'content_type' => 'image_edit' ) )
			: 0;
		if ( $content_artifact_id > 0 ) {
			BizCity_Content_Artifact_Service::mark_stage( $content_artifact_id, 'image_editing' );
			BizCity_Content_Artifact_Service::append_trace( $content_artifact_id, array(
				'stage' => 'image_editing', 'source' => 'automation', 'block_id' => $this->id(), 'status' => 'start',
				'run_id' => (string) ( $ctx['_run_id'] ?? '' ), 'workflow_id' => (int) ( $ctx['_workflow_id'] ?? 0 ),
				'message' => 'Editing image with reference input.',
			) );
		}

		$image_urls = $this->resolve_image_urls( $ctx, $data );
		$image_url = (string) ( $image_urls[0] ?? '' );
		if ( $image_url === '' || preg_match( '/\{\{\s*[a-z0-9_.]+\s*\}\}/i', $image_url ) ) {
			// [2026-07-21 Johnny Chu] PHASE-SEEDREAM-45-FIX — fallback to resume attachment when consume node did not hydrate yet.
			$resume = is_array( $ctx['trigger']['_resume'] ?? null ) ? $ctx['trigger']['_resume'] : array();
			$image_urls = $this->extract_urls_from_state( $resume );
			$image_url = (string) ( $image_urls[0] ?? trim( (string) ( $resume['attachment_url'] ?? '' ) ) );
			if ( $image_url === '' && class_exists( 'BizCity_Automation_Pending_State' ) ) {
				$chat_id = (string) ( $ctx['trigger']['chat_id'] ?? '' );
				$pending = $chat_id !== '' ? BizCity_Automation_Pending_State::get( $chat_id ) : array();
				$image_urls = $this->extract_urls_from_state( $pending );
				$image_url = (string) ( $image_urls[0] ?? trim( (string) ( $pending['attachment_url'] ?? '' ) ) );
			}
		}
		if ( empty( $image_urls ) && $image_url !== '' ) { $image_urls = array( $image_url ); }
		$prompt    = trim( (string) $this->resolve( $data['prompt'] ?? '', $ctx ) );
		$prompt    = $this->strip_photo_editor_keywords( $prompt );
		$model     = trim( (string) ( $data['model'] ?? 'google/gemini-3-pro-image-preview' ) );
		$size      = trim( (string) ( $data['size'] ?? '1024x1024' ) );
		$sideload  = array_key_exists( 'sideload_to_wp', $data ) ? (bool) $data['sideload_to_wp'] : true;

		if ( $image_url === '' ) {
			return $this->fail( 'attachment_missing', 'edit_image: chưa có ảnh nguồn để chỉnh sửa.', $content_artifact_id, $ctx );
		}
		foreach ( $image_urls as $check_url ) {
			if ( ! filter_var( $check_url, FILTER_VALIDATE_URL ) && strpos( $check_url, 'data:image/' ) !== 0 ) {
				return $this->fail( 'invalid_image_url', 'edit_image: image_url không hợp lệ.', $content_artifact_id, $ctx );
			}
		}
		if ( $prompt === '' ) {
			return $this->fail( 'invalid_param', 'edit_image: prompt chỉnh sửa không được rỗng.', $content_artifact_id, $ctx );
		}
		if ( $model === '' || $model === 'bytedance-seed/seedream-4.5' ) {
			// [2026-08-06 Johnny Chu] PHASE-IMG-GEMINI — remove Seedream from image-edit runtime; old saved workflows migrate to Gemini.
			$model = 'google/gemini-3-pro-image-preview';
		}
		if ( $size === '' ) {
			$size = '1024x1024';
		}

		if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
			return $this->fail( 'gateway_missing', 'BizCity LLM client chưa nạp.', $content_artifact_id, $ctx );
		}
		$llm = BizCity_LLM_Client::instance();
		if ( ! $llm->is_ready() ) {
			return $this->fail( 'gateway_not_ready', 'BizCity API key chưa cấu hình.', $content_artifact_id, $ctx );
		}

		$started = microtime( true );
		$result = $llm->generate_image( $this->build_edit_prompt( $prompt ), array(
			'model'        => $model,
			'size'         => $size,
			'timeout'      => 300,
			// [2026-07-21 Johnny Chu] R-AUTO-MULTI-ATTACH — forward all reference images to Branch #06 input_images[].
			'input_images' => $image_urls,
		) );
		$ms = (int) ( ( microtime( true ) - $started ) * 1000 );

		if ( empty( $result['success'] ) ) {
			return $this->fail( 'llm_error', (string) ( $result['error'] ?? 'edit_image failed' ), $content_artifact_id, $ctx );
		}

		$out_url = (string) ( $result['image_url'] ?? '' );
		$b64     = (string) ( $result['b64_json'] ?? '' );
		if ( $out_url === '' && $b64 !== '' ) {
			$out_url = $this->save_b64_to_media( $b64, $prompt );
		}
		if ( $out_url === '' ) {
			return $this->fail( 'empty_url', 'Gateway không trả về URL ảnh đã chỉnh sửa.', $content_artifact_id, $ctx );
		}
		if ( in_array( $out_url, $image_urls, true ) ) {
			return $this->fail( 'unchanged_url', 'Gateway trả lại đúng URL ảnh gốc, chưa có ảnh đã chỉnh sửa.', $content_artifact_id, $ctx );
		}

		$attachment_id = 0;
		if ( $sideload && filter_var( $out_url, FILTER_VALIDATE_URL ) ) {
			$sideloaded = $this->sideload_url_to_media( $out_url, $prompt );
			if ( ! empty( $sideloaded['url'] ) ) {
				$out_url       = (string) $sideloaded['url'];
				$attachment_id = (int) ( $sideloaded['id'] ?? 0 );
			}
		}

		$parts  = explode( 'x', $size );
		$width  = (int) ( $parts[0] ?? 0 );
		$height = (int) ( $parts[1] ?? 0 );

		$this->note_event( 'edit_image_ok', array(
			'model'       => (string) ( $result['model'] ?? $model ),
			'size'        => $size,
			'ms'          => $ms,
			'input_count' => count( $image_urls ),
		) );

		if ( $content_artifact_id > 0 && class_exists( 'BizCity_Content_Artifact_Service' ) ) {
			BizCity_Content_Artifact_Service::mark_stage( $content_artifact_id, 'image_ready', array(
				'_bizcity_content_type' => 'image_edit',
				'_bizcity_image_url'    => $out_url,
				'_bizcity_source_image'  => $image_url,
				'_bizcity_source_images' => wp_json_encode( $image_urls ),
				'_bizcity_prompt'        => $prompt,
			) );
			BizCity_Content_Artifact_Service::append_trace( $content_artifact_id, array(
				'stage' => 'image_ready', 'source' => 'automation', 'block_id' => $this->id(), 'status' => 'ok',
				'run_id' => (string) ( $ctx['_run_id'] ?? '' ), 'workflow_id' => (int) ( $ctx['_workflow_id'] ?? 0 ),
				'message' => 'Image edited and saved.', 'ctx' => array( 'source_url' => $image_url, 'source_urls' => $image_urls, 'image_url' => $out_url, 'model' => (string) ( $result['model'] ?? $model ), 'ms' => $ms ),
			) );
		}

		return array(
			'ok'            => true,
			'content_id'    => $content_artifact_id,
			'image_url'     => $out_url,
			'source_url'    => $image_url,
			'source_urls'   => $image_urls,
			'attachment_id' => $attachment_id,
			'model_used'    => (string) ( $result['model'] ?? $model ),
			'width'         => $width,
			'height'        => $height,
			'ms'            => $ms,
			'error'         => '',
		);
	}

	private function resolve_image_urls( array $ctx, array $data ): array {
		// [2026-07-21 Johnny Chu] R-AUTO-MULTI-ATTACH — image-edit blocks consume canonical attachment_urls[] before legacy image_url.
		$urls = array();
		if ( array_key_exists( 'image_urls', $data ) ) { $this->append_url_value( $this->resolve( $data['image_urls'], $ctx ), $urls ); }
		if ( array_key_exists( 'image_url', $data ) ) { $this->append_url_value( $this->resolve( $data['image_url'], $ctx ), $urls ); }
		foreach ( array( 'consume', 'consume_attachment', 'capture', 'trigger' ) as $key ) {
			if ( isset( $ctx[ $key ] ) && is_array( $ctx[ $key ] ) ) { $this->append_url_value( $ctx[ $key ]['attachment_urls'] ?? array(), $urls ); }
		}
		if ( isset( $ctx['trigger']['_resume'] ) && is_array( $ctx['trigger']['_resume'] ) ) { $this->append_url_value( $this->extract_urls_from_state( $ctx['trigger']['_resume'] ), $urls ); }
		return $this->dedupe_urls( $urls );
	}

	private function extract_urls_from_state( array $state ): array {
		$urls = array();
		$this->append_url_value( $state['attachment_urls'] ?? array(), $urls );
		if ( ! empty( $state['attachments'] ) && is_array( $state['attachments'] ) ) {
			foreach ( $state['attachments'] as $item ) {
				if ( is_array( $item ) ) { $this->append_url_value( $item['wp_url'] ?? $item['url'] ?? $item['source_url'] ?? '', $urls ); }
			}
		}
		$this->append_url_value( $state['attachment_url'] ?? '', $urls );
		return $this->dedupe_urls( $urls );
	}

	private function append_url_value( $value, array &$urls ): void {
		if ( is_array( $value ) ) { foreach ( $value as $item ) { $this->append_url_value( $item, $urls ); } return; }
		$value = trim( (string) $value );
		if ( $value === '' || strpos( $value, '{{' ) !== false ) { return; }
		$decoded = json_decode( $value, true );
		if ( is_array( $decoded ) ) { $this->append_url_value( $decoded, $urls ); return; }
		foreach ( preg_split( '/[\r\n,]+/', $value ) as $part ) {
			$part = trim( (string) $part );
			if ( $part !== '' ) { $urls[] = $part; }
		}
	}

	private function dedupe_urls( array $urls ): array {
		$out = array();
		foreach ( $urls as $url ) { $url = trim( (string) $url ); if ( $url !== '' ) { $out[ $url ] = $url; } }
		return array_values( $out );
	}

	private function build_edit_prompt( string $prompt ): string {
		// [2026-07-21 Johnny Chu] PHASE-SEEDREAM-45 — make user prompt explicit for image-edit models.
		return "Edit the provided reference image according to this request. Preserve the main subject identity, camera perspective, and natural details unless the request says otherwise. Do not add watermarks, logos, or extra text.\n\nUser request:\n" . $prompt;
	}

	private function strip_photo_editor_keywords( string $prompt ): string {
		// [2026-07-21 Johnny Chu] PHASE-SEEDREAM-45-FIX — keep the actual editing instruction, not the trigger command.
		$prefixes = array( '@thợ ảnh', '@tho anh', 'thợ ảnh', 'tho anh', 'sửa ảnh', 'sua anh', 'chỉnh ảnh', 'chinh anh' );
		$lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $prompt, 'UTF-8' ) : strtolower( $prompt );
		foreach ( $prefixes as $prefix ) {
			$len = function_exists( 'mb_strlen' ) ? mb_strlen( $prefix, 'UTF-8' ) : strlen( $prefix );
			$head = function_exists( 'mb_substr' ) ? mb_substr( $lower, 0, $len, 'UTF-8' ) : substr( $lower, 0, $len );
			if ( $head === $prefix ) {
				$prompt = function_exists( 'mb_substr' ) ? mb_substr( $prompt, $len, null, 'UTF-8' ) : substr( $prompt, $len );
				return trim( $prompt, " \t\n\r\0\x0B:-—" );
			}
		}
		return trim( $prompt );
	}

	private function save_b64_to_media( string $b64, string $label ): string {
		// [2026-07-21 Johnny Chu] PHASE-SEEDREAM-45 — persist b64 edit output to WP Media.
		$data = base64_decode( $b64 );
		if ( ! $data ) { return ''; }
		$upload = wp_upload_dir();
		if ( ! empty( $upload['error'] ) ) { return ''; }
		$filename = 'ai-edit-' . gmdate( 'YmdHis' ) . '-' . substr( md5( $b64 ), 0, 6 ) . '.png';
		$filepath = trailingslashit( $upload['path'] ) . $filename;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
		if ( false === file_put_contents( $filepath, $data ) ) { return ''; }
		if ( ! function_exists( 'wp_insert_attachment' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		$attach_id = wp_insert_attachment( array(
			'post_mime_type' => 'image/png',
			'post_title'     => sanitize_text_field( $label ),
			'post_status'    => 'inherit',
		), $filepath );
		if ( is_wp_error( $attach_id ) ) {
			return trailingslashit( $upload['url'] ) . $filename;
		}
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		$metadata = wp_generate_attachment_metadata( $attach_id, $filepath );
		wp_update_attachment_metadata( $attach_id, $metadata );
		return (string) wp_get_attachment_url( (int) $attach_id );
	}

	private function sideload_url_to_media( string $url, string $label ): array {
		// [2026-07-21 Johnny Chu] PHASE-SEEDREAM-45 — sideload edited URL for durable Zalo/WP tracking links.
		$home = home_url();
		if ( strpos( $url, $home ) === 0 ) {
			return array( 'id' => 0, 'url' => $url );
		}
		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		$attach_id = media_sideload_image( $url, 0, sanitize_text_field( $label !== '' ? $label : 'edited image' ), 'id' );
		if ( is_wp_error( $attach_id ) ) {
			$this->note_event( 'edit_image_sideload_failed', array(
				'reason' => 'http_error',
				'error'  => $attach_id->get_error_message(),
			) );
			return array();
		}
		$wp_url = (string) wp_get_attachment_url( (int) $attach_id );
		return $wp_url !== '' ? array( 'id' => (int) $attach_id, 'url' => $wp_url ) : array();
	}

	private function fail( string $reason, string $detail, int $content_artifact_id, array $ctx ) {
		// [2026-07-21 Johnny Chu] PHASE-SEEDREAM-45 — structured failure output for editor templates.
		if ( $content_artifact_id > 0 && class_exists( 'BizCity_Content_Artifact_Service' ) ) {
			BizCity_Content_Artifact_Service::mark_stage( $content_artifact_id, 'failed', array( '_bizcity_error_code' => $reason, '_bizcity_error_message' => $detail ) );
			BizCity_Content_Artifact_Service::append_trace( $content_artifact_id, array(
				'stage' => 'failed', 'source' => 'automation', 'block_id' => $this->id(), 'status' => 'fail',
				'run_id' => (string) ( $ctx['_run_id'] ?? '' ), 'workflow_id' => (int) ( $ctx['_workflow_id'] ?? 0 ),
				'error_code' => $reason, 'message' => $detail,
			) );
		}
		$this->note_event( 'edit_image_failed', array( 'reason' => $reason, 'detail' => $detail ) );
		return new WP_Error( $reason, $detail, array( 'status' => 500, 'content_id' => $content_artifact_id ) );
	}
}
