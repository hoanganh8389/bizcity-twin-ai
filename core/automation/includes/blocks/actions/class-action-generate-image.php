<?php
/**
 * Action: Generate Image (AI image generation via BizCity LLM Gateway).
 *
 * Wraps BizCity_LLM_Client::generate_image() với model selector.
 * Optional sideload sang WP Media Library để lấy URL bền vững (cần cho FB post).
 *
 * Output vars:
 *   {{n_X.ok}}          — bool
 *   {{n_X.image_url}}   — URL ảnh (WP media nếu sideload=true, tạm thời nếu không)
 *   {{n_X.model_used}}  — model đã dùng
 *   {{n_X.width}}       — chiều rộng (từ size field)
 *   {{n_X.height}}      — chiều cao (từ size field)
 *   {{n_X.ms}}          — wall-time ms
 *
 * Fields:
 *   prompt          — mô tả ảnh cần tạo (hỗ trợ {{vars.*}})
 *   model           — gpt-image-1 | gpt-image-2 | dall-e-3 | flux-schnell | flux-1.1-pro | nano-banana
 *   size            — 1024x1024 | 1536x1024 | 1024x1536 | 512x512
 *   sideload_to_wp  — bool: lưu ảnh vào WP Media Library → URL bền vững (quan trọng cho FB publish)
 *
 * Lưu ý kỹ thuật:
 *   - gpt-image-1/2 có thể trả b64_json thay vì URL → block tự lưu ra /uploads/ nếu b64 nhận được.
 *   - Nếu sideload_to_wp=true VÀ image_url là URL tạm (OpenAI expire ~60 phút):
 *       → sideload vào WP → trả WP media URL bền vững.
 *   - Nếu sideload_to_wp=false: trả URL gốc từ gateway (có thể hết hạn sau 60p).
 *   - Dùng action.publish_wp_post → image_url field → WP tự sideload thêm 1 lần nữa
 *     (chấp nhận duplicate nhỏ khi sideload_to_wp=true → không cần optimize ngay).
 *
 * [2026-07-05 Johnny Chu] PHASE-IMG-TPL — new block for image generation automation.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Automation\Blocks\Actions
 * @since      PHASE-IMG-TPL (2026-07-05)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Automation_Action_Generate_Image extends BizCity_Automation_Block_Base {

	const MODEL_OPTIONS = array(
		array( 'value' => 'gpt-image-1',     'label' => 'GPT Image 1 (OpenAI mặc định)' ),
		array( 'value' => 'gpt-image-2',     'label' => 'GPT Image 2 (OpenAI mới nhất)' ),
		array( 'value' => 'dall-e-3',        'label' => 'DALL-E 3 (OpenAI, chất lượng cao)' ),
		array( 'value' => 'flux-schnell',    'label' => 'Flux Schnell (nhanh, tiết kiệm)' ),
		array( 'value' => 'flux-1.1-pro',    'label' => 'Flux 1.1 Pro (chất lượng cao)' ),
		array( 'value' => 'nano-banana',     'label' => 'Nano Banana (siêu nhanh, rẻ)' ),
	);

	const SIZE_OPTIONS = array(
		array( 'value' => '1024x1024', 'label' => '1024×1024 (vuông)' ),
		array( 'value' => '1536x1024', 'label' => '1536×1024 (ngang 3:2)' ),
		array( 'value' => '1024x1536', 'label' => '1024×1536 (dọc 2:3)' ),
		array( 'value' => '512x512',   'label' => '512×512 (nhỏ, nhanh)' ),
	);

	/** Valid model values for sanity check. */
	const VALID_MODELS = array( 'gpt-image-1', 'gpt-image-2', 'dall-e-3', 'flux-schnell', 'flux-1.1-pro', 'nano-banana' );

	/** Valid size values. */
	const VALID_SIZES = array( '1024x1024', '1536x1024', '1024x1536', '512x512' );

	public function id(): string   { return 'action.generate_image'; }
	public function kind(): string { return 'action'; }

	public function meta(): array {
		return array(
			'label'    => 'Tạo Ảnh AI',
			'short'    => 'generate_image',
			'category' => 'ai',
			'color'    => '#7c3aed',
			'icon'     => 'image',
			'defaults' => array(
				'label'         => 'generate_image',
				'prompt'        => '{{trigger.text}}',
				'model'         => 'nano-banana',
				'size'          => '1024x1024',
				'sideload_to_wp'=> true,
			),
			'fields' => array(
				array( 'name' => 'label',          'label' => 'Tên hiển thị', 'type' => 'text' ),
				array(
					'name'  => 'prompt',
					'label' => 'Mô tả ảnh',
					'type'  => 'textarea',
					'hint'  => 'Hỗ trợ {{trigger.text}}, {{gen.content}}, {{vars.*}}. Viết bằng tiếng Anh cho kết quả tốt hơn.',
				),
				array(
					'name'    => 'model',
					'label'   => 'Model AI',
					'type'    => 'select',
					'options' => self::MODEL_OPTIONS,
					'hint'    => 'GPT Image 1 là mặc định; Flux Schnell nhanh hơn và rẻ hơn.',
				),
				array(
					'name'    => 'size',
					'label'   => 'Kích thước',
					'type'    => 'select',
					'options' => self::SIZE_OPTIONS,
				),
				array(
					'name' => 'sideload_to_wp',
					'label' => 'Lưu vào WP Media (khuyến nghị)',
					'type' => 'toggle',
					'hint' => 'Bật = lưu ảnh vào /uploads/ → URL bền vững. Cần cho FB publish (URL tạm hết hạn sau ~60p).',
				),
			),
		);
	}

	public function execute( array $ctx, array $data ) {
		// [2026-07-05 Johnny Chu] PHASE-IMG-TPL — execute generate_image block.
		$prompt      = trim( (string) $this->resolve( $data['prompt'] ?? '{{trigger.text}}', $ctx ) );
		// [2026-07-04 Johnny Chu] PHASE-IMG-TPL — strip trigger command keywords so "đăng fb", "đăng web"
		// don't leak into image prompt and cause the AI to render Vietnamese command text in the image.
		$prompt      = self::strip_command_keywords( $prompt );
		$model       = (string) ( $data['model'] ?? 'nano-banana' );
		$size        = (string) ( $data['size']  ?? '1024x1024' );
		$sideload    = isset( $data['sideload_to_wp'] ) ? (bool) $data['sideload_to_wp'] : true;

		// [2026-07-05 Johnny Chu] PHASE-IMG-TPL — log entry so every run leaves a trace.
		error_log( sprintf(
			'[BIZCITY][generate_image] START model=%s size=%s sideload=%d prompt_len=%d prompt_preview=%.80s',
			$model, $size, (int) $sideload, strlen( $prompt ), $prompt
		) );

		if ( $prompt === '' ) {
			error_log( '[BIZCITY][generate_image] FAIL: prompt rỗng.' );
			return $this->_fail( 'invalid_param', 'Prompt tạo ảnh không được rỗng.' );
		}

		if ( ! in_array( $model, self::VALID_MODELS, true ) ) { $model = 'nano-banana'; }
		if ( ! in_array( $size,  self::VALID_SIZES,  true ) ) { $size  = '1024x1024'; }

		// ── R-GW-8: BizCity_LLM_Client gate ─────────────────────────────────
		if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
			error_log( '[BIZCITY][generate_image] FAIL: BizCity_LLM_Client class not found.' );
			return $this->_fail( 'gateway_missing', 'BizCity LLM client chưa nạp.' );
		}
		$llm = BizCity_LLM_Client::instance();
		if ( ! $llm->is_ready() ) {
			error_log( '[BIZCITY][generate_image] FAIL: LLM client not ready (API key missing?).' );
			return $this->_fail( 'gateway_not_ready', 'BizCity API key chưa cấu hình.' );
		}

		// [2026-07-04 Johnny Chu] PHASE-IMG-TPL — model fallback list: try primary, then fallbacks on server error.
		$try_models = array( $model );
		if ( $model === 'nano-banana' ) {
			$try_models[] = 'flux-schnell';
			$try_models[] = 'dall-e-3';
		} elseif ( $model === 'gpt-image-1' || $model === 'gpt-image-2' ) {
			$try_models[] = 'nano-banana';
			$try_models[] = 'flux-schnell';
		} elseif ( $model === 'dall-e-3' ) {
			$try_models[] = 'nano-banana';
			$try_models[] = 'flux-schnell';
		} elseif ( $model === 'flux-1.1-pro' ) {
			$try_models[] = 'flux-schnell';
			$try_models[] = 'nano-banana';
		}

		$result         = array();
		$ms             = 0;
		$model_used_try = $model;
		foreach ( $try_models as $_try_model ) {
			$model_used_try = $_try_model;
			error_log( '[BIZCITY][generate_image] Trying model=' . $_try_model );
			$started = microtime( true );
			$result  = $llm->generate_image( $prompt, array( 'model' => $_try_model, 'size' => $size ) );
			$ms      = (int) ( ( microtime( true ) - $started ) * 1000 );

			// [2026-07-05 Johnny Chu] PHASE-IMG-TPL — log full raw response for debugging.
			error_log( sprintf(
				'[BIZCITY][generate_image] RAW RESPONSE model=%s ms=%d success=%s image_url_len=%d b64_len=%d error=%s',
				$_try_model,
				$ms,
				! empty( $result['success'] ) ? 'true' : 'false',
				strlen( (string) ( $result['image_url'] ?? '' ) ),
				strlen( (string) ( $result['b64_json']  ?? '' ) ),
				(string) ( $result['error'] ?? '' )
			) );

			if ( ! empty( $result['success'] ) ) {
				// Got a result — use it.
				$model = $model_used_try;
				break;
			}

			$err_str = (string) ( $result['error'] ?? '' );
			// Retry on transient server errors (502/503/504). Stop on auth/quota errors.
			$is_server_error = ( strpos( $err_str, 'HTTP 502' ) !== false
				|| strpos( $err_str, 'HTTP 503' ) !== false
				|| strpos( $err_str, 'HTTP 504' ) !== false
				|| strpos( $err_str, 'HTTP 429' ) !== false );
			if ( ! $is_server_error ) {
				error_log( '[BIZCITY][generate_image] Non-retryable error on model=' . $_try_model . ' — stopping fallback. error=' . $err_str );
				break;
			}
			error_log( '[BIZCITY][generate_image] Server error on model=' . $_try_model . ' — trying next fallback.' );
		}

		if ( empty( $result['success'] ) ) {
			$err = (string) ( $result['error'] ?? 'generate_image failed' );
			error_log( '[BIZCITY][generate_image] FAIL (API) after all models: ' . $err );
			return $this->_fail( 'llm_error', $err );
		}

		$image_url = (string) ( $result['image_url'] ?? '' );
		$b64_json  = (string) ( $result['b64_json']  ?? '' );

		error_log( sprintf(
			'[BIZCITY][generate_image] API OK — image_url=%s b64_len=%d',
			$image_url !== '' ? $image_url : '(empty)',
			strlen( $b64_json )
		) );

		// ── Handle b64_json: save to uploads directory ───────────────────────
		if ( $image_url === '' && $b64_json !== '' ) {
			error_log( '[BIZCITY][generate_image] Got b64_json, saving to WP uploads...' );
			$saved = $this->save_b64_to_media( $b64_json, $prompt );
			if ( $saved !== '' ) {
				$image_url = $saved;
				error_log( '[BIZCITY][generate_image] b64 saved → ' . $image_url );
			} else {
				error_log( '[BIZCITY][generate_image] FAIL: save_b64_to_media returned empty.' );
				return $this->_fail( 'save_failed', 'Không lưu được ảnh b64 vào WP uploads.' );
			}
		}

		if ( $image_url === '' ) {
			error_log( '[BIZCITY][generate_image] FAIL: both image_url and b64_json are empty after API call.' );
			return $this->_fail( 'empty_url', 'Gateway không trả về URL ảnh.' );
		}

		// ── Optional: sideload URL vào WP Media Library ──────────────────────
		// Áp dụng khi: sideload_to_wp=true VÀ URL không phải URL local WP đã có.
		if ( $sideload && filter_var( $image_url, FILTER_VALIDATE_URL ) ) {
			$home = home_url();
			$is_local = ( strpos( $image_url, $home ) === 0 );
			if ( ! $is_local ) {
				error_log( '[BIZCITY][generate_image] Sideloading to WP Media: ' . $image_url );
				$wp_url = $this->sideload_url_to_media( $image_url, $prompt );
				if ( $wp_url !== '' ) {
					error_log( '[BIZCITY][generate_image] Sideload OK → ' . $wp_url );
					$image_url = $wp_url;
				} else {
					error_log( '[BIZCITY][generate_image] WARN: sideload failed — using original URL: ' . $image_url );
					// Tiếp tục với URL gốc (có thể expire sau 60p) thay vì fail hẳn.
				}
			} else {
				error_log( '[BIZCITY][generate_image] URL đã là local WP, skip sideload.' );
			}
		}

		// Parse width/height from size string (e.g. "1024x1024" → 1024, 1024).
		$parts  = explode( 'x', $size );
		$width  = (int) ( $parts[0] ?? 1024 );
		$height = (int) ( $parts[1] ?? 1024 );

		error_log( sprintf(
			'[BIZCITY][generate_image] SUCCESS final_url=%s model=%s ms=%d',
			$image_url, $model, $ms
		) );

		$this->note_event( 'generate_image_ok', array(
			'model'     => $model,
			'size'      => $size,
			'sideload'  => $sideload,
			'image_url' => $image_url,
			'ms'        => $ms,
		) );

		return array(
			'ok'         => true,
			'image_url'  => $image_url,
			'model_used' => (string) ( $result['model'] ?? $model ),
			'width'      => $width,
			'height'     => $height,
			'ms'         => $ms,
			'error'      => '',
		);
	}

	/* ------------------------------------------------------------------ *
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Save base64 PNG/JPG data to WP uploads directory.
	 * Returns WP media URL if successful, empty string on failure.
	 *
	 * [2026-07-05 Johnny Chu] PHASE-IMG-TPL — b64 → WP media helper.
	 */
	private function save_b64_to_media( string $b64, string $label ): string {
		$data = base64_decode( $b64 );
		if ( ! $data ) { return ''; }

		$upload = wp_upload_dir();
		if ( ! empty( $upload['error'] ) ) { return ''; }

		$filename = 'ai-img-' . gmdate( 'YmdHis' ) . '-' . substr( md5( $b64 ), 0, 6 ) . '.png';
		$filepath = trailingslashit( $upload['path'] ) . $filename;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
		if ( false === file_put_contents( $filepath, $data ) ) {
			// [2026-07-05 Johnny Chu] PHASE-IMG-TPL — log write failure.
			error_log( '[BIZCITY][generate_image] save_b64_to_media: file_put_contents FAIL path=' . $filepath );
			return '';
		}
		error_log( '[BIZCITY][generate_image] save_b64_to_media: wrote b64 PNG to ' . $filepath );

		// Register in WP media library.
		if ( ! function_exists( 'wp_insert_attachment' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$attachment = array(
			'post_mime_type' => 'image/png',
			'post_title'     => sanitize_text_field( $label ),
			'post_status'    => 'inherit',
		);
		$attach_id = wp_insert_attachment( $attachment, $filepath );
		if ( is_wp_error( $attach_id ) ) {
			// Fallback: return direct URL even if not in library.
			return trailingslashit( $upload['url'] ) . $filename;
		}

		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		$metadata = wp_generate_attachment_metadata( $attach_id, $filepath );
		wp_update_attachment_metadata( $attach_id, $metadata );

		return (string) wp_get_attachment_url( $attach_id );
	}

	/**
	 * Sideload a remote URL into WP media library.
	 * Returns new WP media URL, or empty string on failure.
	 *
	 * [2026-07-05 Johnny Chu] PHASE-IMG-TPL — sideload helper.
	 */
	private function sideload_url_to_media( string $url, string $label ): string {
		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$attach_id = media_sideload_image( $url, 0, sanitize_text_field( $label ), 'id' );
		if ( is_wp_error( $attach_id ) ) {
			// [2026-07-05 Johnny Chu] PHASE-IMG-TPL — log sideload failure detail.
			error_log( sprintf(
				'[BIZCITY][generate_image] sideload_url_to_media FAIL url=%s error=%s',
				$url,
				$attach_id->get_error_message()
			) );
			return '';
		}

		$wp_url = (string) wp_get_attachment_url( (int) $attach_id );
		error_log( '[BIZCITY][generate_image] sideload_url_to_media OK attach_id=' . $attach_id . ' url=' . $wp_url );
		return $wp_url;
	}

	/**
	 * Strip Vietnamese command-trigger prefixes from image generation prompt.
	 * Prevents "đăng fb", "đăng web", "viết bài"… leaking into the image prompt
	 * and causing the AI to render Vietnamese command text in the image.
	 *
	 * [2026-07-04 Johnny Chu] PHASE-IMG-TPL — trigger keyword sanitizer for image prompts.
	 */
	private static function strip_command_keywords( string $prompt ): string {
		$prefixes = array(
			'đăng facebook', 'dang facebook', 'post facebook',
			'đăng fb',       'dang fb',       'post fb',
			'đăng web',      'dang web',      'post web',
			'viết bài',      'viet bai',      'post bài',   'post bai',
			'đăng bài',      'dang bai',
		);
		// Sort longest-first (greedy): "đăng facebook" before "đăng fb".
		usort( $prefixes, function( $a, $b ) {
			return mb_strlen( $b, 'UTF-8' ) - mb_strlen( $a, 'UTF-8' );
		} );

		$lower = mb_strtolower( $prompt, 'UTF-8' );
		foreach ( $prefixes as $p ) {
			$plen = mb_strlen( $p, 'UTF-8' );
			if ( mb_substr( $lower, 0, $plen, 'UTF-8' ) === $p ) {
				$prompt = trim( mb_substr( $prompt, $plen, null, 'UTF-8' ) );
				break; // only strip ONE leading prefix per prompt
			}
		}
		return $prompt;
	}

	private function _fail( string $reason, string $detail = '' ): array {
		// [2026-07-05 Johnny Chu] PHASE-IMG-TPL — always error_log on fail.
		error_log( '[BIZCITY][generate_image] _fail reason=' . $reason . ( $detail !== '' ? ' detail=' . $detail : '' ) );
		$this->note_event( 'generate_image_failed', array(
			'reason' => $reason,
			'detail' => $detail,
		) );
		return array(
			'ok'         => false,
			'image_url'  => '',
			'model_used' => '',
			'width'      => 0,
			'height'     => 0,
			'ms'         => 0,
			'error'      => $reason . ( $detail !== '' ? ': ' . $detail : '' ),
		);
	}
}
