<?php
/**
 * BizCity_Video_Client — Thin wrapper for video/router/v1/* on bizcity.vn gateway.
 *
 * Pattern: mirrors BizCity_LLM_Client (R-GW-8, standalone client topology).
 * Provider keys (Kling, Runway, Veo 3) live ONLY on the server (bizcity-llm-router).
 * Client calls NEVER talk to providers directly.
 *
 * Supports:
 *   - text_to_video( $prompt, $options )   → { success, task_id, status, eta_sec }
 *   - image_to_video( $image_url, $prompt, $options ) → same
 *   - get_status( $task_id )               → { success, status, progress, result_url }
 *   - list_models()                        → { success, models[] }
 *
 * Default model: kling/v1-5/i2v-pro (Kling image-to-video Pro via PiAPI — primary use case).
 * Fall-back model: kling/v1-5/standard (text-to-video standard, no image needed).
 *
 * On any gateway error, methods return fail-OPEN:
 *   { success: false, _degraded: true, error: '...' }
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\BizCity_LLM
 * @since      2026-06-14 (PHASE-0.41 VIDEO-VEO3)
 */

// [2026-06-14 Johnny Chu] PHASE-0.41 VIDEO-VEO3 — new BizCity_Video_Client

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Video_Client {

	// [2026-06-14 Johnny Chu] PHASE-0.41 VIDEO-VEO3 — corrected to PiAPI slash-format model IDs
	const DEFAULT_MODEL    = 'kling/v1-5/i2v-pro';   // image-to-video Pro (PiAPI)
	const FALLBACK_MODEL   = 'kling/v1-5/standard';   // text-to-video standard (PiAPI)
	const DEFAULT_DURATION = 5;
	const DEFAULT_RATIO    = '16:9';
	const POLL_MAX_ATTEMPTS = 30;

	/** @var self|null */
	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/* ── Config helpers (reads from BizCity_LLM_Client options) ── */

	public function get_gateway_url(): string {
		// [2026-08-10 Johnny Chu] PHASE-1.24-VIDEO-KLING — resolve gateway URL through the canonical LLM client boundary.
		if ( class_exists( 'BizCity_LLM_Client' ) ) {
			return rtrim( (string) BizCity_LLM_Client::instance()->get_gateway_url(), '/' );
		}
		return '';
	}

	public function get_api_key(): string {
		// [2026-08-10 Johnny Chu] R-1API-AUTH — use the canonical credential getter for video and PiAPI-backed video calls.
		if ( class_exists( 'BizCity_LLM_Client' ) ) {
			return BizCity_LLM_Client::instance()->get_api_key();
		}
		return '';
	}

	public function is_ready(): bool {
		return $this->get_api_key() !== '';
	}

	/* ─────────────────────────────────────────────────────────────
	 *  Public API
	 * ───────────────────────────────────────────────────────────── */

	/**
	 * Submit a text-to-video or image-to-video job.
	 *
	 * @param string $prompt    Mô tả video.
	 * @param array  $options   model, image_url, duration, aspect_ratio, negative_prompt, with_audio.
	 * @return array { success, task_id, status, eta_sec, cost_usd, error, _degraded? }
	 */
	public function submit( string $prompt, array $options = array() ): array {
		// [2026-08-10 Johnny Chu] PHASE-1.24-VIDEO-KLING — add stable trace/idempotency context to video submits.
		$trace_id       = sanitize_text_field( (string) ( $options['trace_id'] ?? '' ) );
		$idempotency_key = sanitize_text_field( (string) ( $options['idempotency_key'] ?? '' ) );
		if ( '' === $trace_id ) {
			$trace_id = 'tr_' . wp_generate_uuid4();
		}
		if ( '' === $idempotency_key ) {
			$idempotency_key = 'video_' . wp_generate_uuid4();
		}
		$base = array(
			'success'    => false,
			'task_id'    => '',
			'status'     => '',
			'eta_sec'    => 120,
			'cost_usd'   => 0,
			'error'      => '',
			'error_code' => '', // [2026-06-14 Johnny Chu] PHASE-0.41 VIDEO-VEO3 — code from gateway
			'code'       => '',
			'trace_id'   => $trace_id,
		);

		if ( ! $this->is_ready() ) {
			$base['error']     = 'BizCity API key chưa được cấu hình.';
			$base['_degraded'] = true;
			return $base;
		}

		$body = array(
			'prompt'          => $prompt,
			'model'           => (string) ( $options['model'] ?? self::DEFAULT_MODEL ),
			'duration'        => (int) ( $options['duration'] ?? self::DEFAULT_DURATION ),
			'aspect_ratio'    => (string) ( $options['aspect_ratio'] ?? self::DEFAULT_RATIO ),
			'negative_prompt' => (string) ( $options['negative_prompt'] ?? '' ),
			'with_audio'      => ! empty( $options['with_audio'] ),
			'site_url'        => home_url(),
			'trace_id'        => $trace_id,
			'idempotency_key' => $idempotency_key,
		);

		// image_to_video: only include image_url if non-empty.
		$image_url = (string) ( $options['image_url'] ?? '' );
		if ( $image_url !== '' ) {
			$body['image_url'] = $image_url;
		}

		$endpoint = $this->get_gateway_url() . '/wp-json/video/router/v1/generate';
		$response = $this->request( 'gateway.video.submit', $endpoint, array(
			'method'  => 'POST',
			'timeout' => 30,
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $this->get_api_key(),
				'X-Site-URL'    => home_url(),
				'X-Trace-Id'    => $trace_id,
				'X-Idempotency-Key' => $idempotency_key,
			),
			'body' => wp_json_encode( $body ),
		), array( 'trace_id' => $trace_id, 'idempotency_key' => $idempotency_key ) );

		return $this->parse_response( $response, $base );
	}

	/**
	 * Convenience: image-to-video shorthand.
	 *
	 * @param string $image_url URL ảnh nguồn.
	 * @param string $prompt    Mô tả chuyển động / nội dung.
	 * @param array  $options   Xem submit().
	 */
	public function image_to_video( string $image_url, string $prompt, array $options = array() ): array {
		$options['image_url'] = $image_url;
		return $this->submit( $prompt, $options );
	}

	/**
	 * Poll status of a submitted task.
	 *
	 * @param string $task_id  task_id returned by submit().
	 * @return array { success, status, progress, result_url, thumbnail_url, error, _degraded? }
	 */
	public function get_status( string $task_id ): array {
		// [2026-08-10 Johnny Chu] PHASE-1.24-VIDEO-KLING — route polling through the same reliable wrapper boundary.
		$trace_id = 'tr_' . wp_generate_uuid4();
		$base = array(
			'success'       => false,
			'status'        => 'pending',
			'progress'      => 0,
			'result_url'    => '',
			'thumbnail_url' => '',
			'error'         => '',
			'error_code'    => '', // [2026-06-14 Johnny Chu] PHASE-0.41 VIDEO-VEO3 — code from gateway
			'code'          => '',
			'trace_id'      => $trace_id,
		);

		if ( ! $this->is_ready() ) {
			$base['_degraded'] = true;
			$base['error']     = 'BizCity API key chưa cấu hình.';
			$base['code']      = 'gateway_degraded';
			return $base;
		}
		if ( $task_id === '' ) {
			$base['error'] = 'get_status: task_id rỗng.';
			$base['code']  = 'invalid_param';
			return $base;
		}

		$endpoint = $this->get_gateway_url() . '/wp-json/video/router/v1/status?task_id=' . rawurlencode( $task_id );
		$response = $this->request( 'gateway.video.poll', $endpoint, array(
			'method'  => 'GET',
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->get_api_key(),
				'X-Site-URL'    => home_url(),
				'X-Trace-Id'    => $trace_id,
			),
		), array( 'trace_id' => $trace_id ) );

		return $this->parse_response( $response, $base );
	}

	/**
	 * List available models from gateway.
	 */
	public function list_models(): array {
		$trace_id = 'tr_' . wp_generate_uuid4();
		$base = array( 'success' => false, 'models' => array(), 'error' => '', 'code' => '', 'trace_id' => $trace_id );
		if ( ! $this->is_ready() ) {
			$base['_degraded'] = true;
			return $base;
		}
		$endpoint = $this->get_gateway_url() . '/wp-json/video/router/v1/models';
		$response = $this->request( 'gateway.video.models', $endpoint, array(
			'method'  => 'GET',
			'timeout' => 15,
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->get_api_key(),
				'X-Trace-Id'    => $trace_id,
			),
		), array( 'trace_id' => $trace_id ) );
		return $this->parse_response( $response, $base );
	}

	/* ─────────────────────────────────────────────────────────────
	 *  Internal helpers
	 * ───────────────────────────────────────────────────────────── */

	private function request( string $name, string $url, array $args, array $context = array() ) {
		// [2026-08-10 Johnny Chu] PHASE-1.24-VIDEO-KLING — lazy-load the canonical reliability boundary; never fall back to raw transport.
		if ( ! class_exists( 'BizCity_Twin_Reliable_HTTP' ) ) {
			$reliable_file = dirname( dirname( __DIR__ ) ) . '/twin-core/includes/class-twin-reliable-http.php';
			if ( is_readable( $reliable_file ) ) {
				require_once $reliable_file;
			}
		}
		if ( class_exists( 'BizCity_Twin_Reliable_HTTP' ) ) {
			return BizCity_Twin_Reliable_HTTP::request( $name, $url, $args, $context );
		}
		return new WP_Error( 'reliability_unavailable', 'Video reliability boundary chưa được load.' );
	}

	/**
	 * Parse wp_remote_* response — fail-OPEN: always returns array, never throws.
	 *
	 * @param mixed $response wp_remote_post/get return value.
	 * @param array $base     Default fields to merge into.
	 */
	private function parse_response( $response, array $base ): array {
		if ( is_wp_error( $response ) ) {
			$base['error']     = 'Dịch vụ video tạm thời không khả dụng.';
			$base['code']      = 'gateway_degraded';
			$base['hint']      = 'Thử lại sau vài phút.';
			$base['help_code'] = 'gateway_degraded';
			$base['_degraded'] = true;
			return $base;
		}
		$code    = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code === 402 ) {
			$base['error']      = 'Tài khoản đã hết hạn mức tạo video.';
			$base['error_code'] = 'insufficient_credits'; // [2026-06-14 Johnny Chu] PHASE-0.41 VIDEO-VEO3
			$base['code']      = 'quota_exceeded';
			$base['hint']      = 'Kiểm tra hạn mức hoặc nâng cấp gói rồi thử lại.';
			$base['help_code'] = 'quota_exceeded';
			return $base;
		}

		if ( $code === 429 ) { // [2026-06-14 Johnny Chu] PHASE-0.41 VIDEO-VEO3 — rate limit
			$base['error']      = 'Tác vụ video đang bị giới hạn tần suất.';
			$base['error_code'] = ( is_array( $decoded ) && isset( $decoded['code'] ) )
				? (string) $decoded['code']
				: 'rate_limited';
			$base['code']      = 'rate_limited';
			$base['hint']      = 'Đợi một lúc rồi thử lại.';
			$base['help_code'] = 'retry_later';
			return $base;
		}

		if ( ! is_array( $decoded ) ) {
			$base['error']     = 'Phản hồi từ dịch vụ video không hợp lệ.';
			$base['code']      = 'gateway_degraded';
			$base['hint']      = 'Thử lại sau vài phút.';
			$base['help_code'] = 'gateway_degraded';
			$base['_degraded'] = true;
			return $base;
		}

		if ( ! empty( $decoded['success'] ) ) {
			return array_merge( $base, $decoded );
		}

		$base['error']      = 'Không thể hoàn tất tác vụ video.';
		$base['error_code'] = (string) ( $decoded['code']  ?? '' ); // [2026-06-14 Johnny Chu] PHASE-0.41 VIDEO-VEO3
		$base['code']      = (string) ( $decoded['code'] ?? ( $code >= 500 ? 'provider_error' : 'invalid_param' ) );
		$base['hint']      = $code >= 500 ? 'Thử lại sau vài phút.' : 'Kiểm tra dữ liệu rồi thử lại.';
		$base['help_code'] = $code >= 500 ? 'gateway_degraded' : 'invalid_param_generic';
		return $base;
	}
}
